from __future__ import annotations

import logging
from datetime import UTC, datetime
from pathlib import Path

import polars as pl

from app.config import PipelineConfig
from app.models import EmailCandidate, EmailStatus, FailureRecord
from app.scoring.rules import score_email_candidate
from app.utils.domains import normalize_domain
from app.utils.email_utils import (
    classify_email_type,
    generate_pattern_candidates,
    has_mx_record,
    is_blacklisted_email,
    is_valid_email_syntax,
)
from app.utils.io import read_frame, records_to_frame, write_failures
from app.utils.logging_utils import log_phase

LOGGER = logging.getLogger(__name__)


def score_emails_from_crawl(input_path: str | Path, output_path: str | Path, config: PipelineConfig) -> None:
    input_file = Path(input_path)
    output_file = Path(output_path)
    output_file.parent.mkdir(parents=True, exist_ok=True)

    with log_phase(LOGGER, "score_emails"):
        frame = read_frame(input_file)
        records: list[dict] = []
        failures: list[dict] = []

        if frame.is_empty():
            pl.DataFrame().write_parquet(output_file)
            return

        for row in frame.to_dicts():
            detected_emails = row.get("detected_emails") or []
            detected_mailtos = row.get("detected_mailtos") or []
            if isinstance(detected_emails, str):
                detected_emails = [item.strip() for item in detected_emails.strip("[]").replace('"', "").split(",") if item.strip()]
            if isinstance(detected_mailtos, str):
                detected_mailtos = [item.strip() for item in detected_mailtos.strip("[]").replace('"', "").split(",") if item.strip()]

            if not detected_emails:
                failures.append(
                    FailureRecord(
                        stage="score_emails",
                        reason="no_email_found",
                        siren=row.get("siren"),
                        domain=row.get("domain"),
                        source_url=row.get("url"),
                        created_at=datetime.now(UTC),
                    ).model_dump()
                )
                continue

            official_domain = normalize_domain(row.get("domain"))
            observed_same_domain = {email for email in detected_emails if normalize_domain(email.split("@", 1)[1]) == official_domain}
            generated_candidates = set()
            if config.email_extraction.enable_pattern_generation and official_domain and observed_same_domain:
                generated_candidates = generate_pattern_candidates(
                    official_domain,
                    observed_same_domain,
                    person_name=row.get("title"),
                )

            all_candidates = {(email, False) for email in detected_emails}
            all_candidates.update((email, True) for email in generated_candidates if email not in detected_emails)

            for email, is_generated in all_candidates:
                local_part, _, email_domain = email.lower().partition("@")
                syntax_valid = is_valid_email_syntax(email)
                mx_valid = has_mx_record(email_domain) if config.email_extraction.enable_mx_check and syntax_valid else None
                blacklist_reason = is_blacklisted_email(email, config.email_extraction)
                candidate = EmailCandidate(
                    siren=row["siren"],
                    domain=official_domain,
                    email=email.lower(),
                    local_part=local_part,
                    email_domain=email_domain,
                    email_type=classify_email_type(email, config.email_extraction),
                    source_url=row.get("url"),
                    source_type=row.get("page_type") or "html",
                    observed_on_official_site=bool(official_domain),
                    observed_in_mailto=email.lower() in {item.lower() for item in detected_mailtos},
                    observed_in_contact_page=(row.get("page_type") == "contact_page"),
                    observed_in_mentions_legales=(row.get("page_type") == "legal_page"),
                    is_pattern_generated=is_generated,
                    syntax_valid=syntax_valid,
                    mx_valid=mx_valid,
                    discovered_at=datetime.now(UTC),
                )

                score, status, details, rejection_reason = score_email_candidate(
                    candidate,
                    domain_score=int(row.get("domain_score") or 0),
                    domain_status=row.get("domain_status"),
                    blacklist_reason=blacklist_reason,
                    config=config,
                )
                candidate.score = score
                candidate.status = status
                candidate.rejection_reason = rejection_reason
                candidate.score_details = details

                records.append(candidate.model_dump())

        out_frame = records_to_frame(records)
        if not out_frame.is_empty():
            out_frame = out_frame.sort(["siren", "score", "email"], descending=[False, True, False]).unique(
                subset=["siren", "email"],
                keep="first",
            )
        out_frame.write_parquet(output_file)
        write_failures(failures, output_file, config, "score_emails")
        LOGGER.info("emails_scored", extra={"phase": "score_emails", "count": out_frame.height, "path": output_file.as_posix()})
