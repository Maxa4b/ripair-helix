from __future__ import annotations

import asyncio
import logging
import re
from datetime import UTC, datetime
from pathlib import Path

import polars as pl
from bs4 import BeautifulSoup

from app.config import PipelineConfig
from app.crawl.classify import classify_page_type
from app.crawl.http_client import CachedHttpClient
from app.models import FailureRecord, PageCrawlResult
from app.utils.domains import canonicalize_url, extract_siren_candidates, html_hash, is_asset_url
from app.utils.email_utils import extract_emails_from_text, extract_mailtos
from app.utils.io import read_frame, records_to_frame, write_failures
from app.utils.logging_utils import log_phase
from app.utils.text import safe_excerpt

LOGGER = logging.getLogger(__name__)
PHONE_RE = re.compile(r"(?:(?:\+33|0)[1-9](?:[\s.\-]?\d{2}){4})")


async def _crawl_domain_group(
    rows: list[dict],
    http: CachedHttpClient,
    config: PipelineConfig,
) -> tuple[list[dict], list[dict]]:
    failures: list[dict] = []
    records: list[dict] = []
    leader = max(rows, key=lambda item: int(item.get("domain_score", 0)))
    domain = leader["normalized_domain"]
    base_url = canonicalize_url(f"https://{domain}/")
    page_budget = config.crawl.priority_paths[: config.crawl.max_pages_per_domain]
    high_confidence_hit = False
    parsed_pages: list[dict] = []

    for depth, path in enumerate(page_budget):
        url = canonicalize_url(path, base_url)
        if is_asset_url(url, config.crawl.ignore_extensions):
            continue
        response = await http.get(url)
        if not response:
            continue
        if response["status_code"] >= 400:
            continue

        content_type = response["content_type"] or ""
        if "html" not in content_type and not (config.crawl.allow_pdf and "pdf" in content_type):
            continue

        body = response["body"] or ""
        if "pdf" in content_type:
            title = None
            text = body[:2000]
            mailtos = set()
            emails = extract_emails_from_text(text)
        else:
            soup = BeautifulSoup(body, "html.parser")
            title = soup.title.get_text(" ", strip=True) if soup.title else None
            text = soup.get_text(" ", strip=True)
            mailtos = extract_mailtos(body)
            emails = extract_emails_from_text(text) | mailtos

        page_type = classify_page_type(url, title, text)
        parsed_pages.append(
            {
                "url": url,
                "http_status": response["status_code"],
                "content_type": content_type,
                "title": title,
                "text": text,
                "emails": sorted(emails),
                "mailtos": sorted(mailtos),
                "page_type": page_type,
                "depth": depth,
            }
        )

        if config.crawl.stop_after_high_confidence_email and any(email.endswith(f"@{domain}") for email in mailtos):
            high_confidence_hit = True
            break

    if not parsed_pages:
        for row in rows:
            failures.append(
                FailureRecord(
                    stage="crawl",
                    reason="crawl_failed",
                    siren=row.get("siren"),
                    domain=domain,
                    source_url=leader.get("source_url"),
                    created_at=datetime.now(UTC),
                ).model_dump()
            )
        return records, failures

    for row in rows:
        for page in parsed_pages:
            result = PageCrawlResult(
                domain=domain,
                siren=row.get("siren"),
                url=page["url"],
                http_status=page["http_status"],
                content_type=page["content_type"],
                fetch_success=True,
                title=page["title"],
                html_hash=html_hash(page["text"]),
                text_excerpt=safe_excerpt(page["text"]),
                detected_emails=page["emails"],
                detected_phones=PHONE_RE.findall(page["text"]),
                detected_siren=extract_siren_candidates(page["text"]),
                detected_address=[],
                page_type=page["page_type"],
                crawl_depth=page["depth"],
                fetched_at=datetime.now(UTC),
            )
            payload = result.model_dump()
            payload["domain_score"] = row.get("domain_score")
            payload["domain_status"] = row.get("domain_status")
            payload["source_url"] = row.get("source_url")
            payload["source_type"] = row.get("source_type")
            payload["matched_siren"] = row.get("matched_siren")
            payload["matched_name"] = row.get("matched_name")
            payload["high_confidence_stop"] = high_confidence_hit
            payload["detected_mailtos"] = page["mailtos"]
            records.append(payload)

    return records, failures


async def _crawl_async(input_path: Path, output_path: Path, config: PipelineConfig) -> None:
    frame = read_frame(input_path)
    if frame.is_empty():
        pl.DataFrame().write_parquet(output_path)
        return

    accepted = frame.filter(pl.col("domain_status").is_in(["verified", "likely", "uncertain"]))
    grouped: dict[str, list[dict]] = {}
    for row in accepted.to_dicts():
        grouped.setdefault(row["normalized_domain"], []).append(row)

    http = CachedHttpClient(config)
    try:
        results: list[dict] = []
        failures: list[dict] = []
        for rows in grouped.values():
            crawled_rows, crawl_failures = await _crawl_domain_group(rows, http, config)
            results.extend(crawled_rows)
            failures.extend(crawl_failures)
    finally:
        await http.close()

    records_to_frame(results).write_parquet(output_path)
    write_failures(failures, output_path, config, "crawl")
    LOGGER.info("crawl_completed", extra={"phase": "crawl", "count": len(results), "path": output_path.as_posix()})


def crawl_domains(input_path: str | Path, output_path: str | Path, config: PipelineConfig) -> None:
    input_file = Path(input_path)
    output_file = Path(output_path)
    output_file.parent.mkdir(parents=True, exist_ok=True)
    with log_phase(LOGGER, "crawl"):
        asyncio.run(_crawl_async(input_file, output_file, config))
