from __future__ import annotations

import asyncio
import logging
from datetime import UTC, datetime
from pathlib import Path

import httpx

from app.config import PipelineConfig
from app.domain_resolution.providers import build_domain_providers
from app.domain_resolution.validator import DomainValidator
from app.models import CompanyTarget, FailureRecord
from app.utils.io import read_frame, records_to_frame, write_failures
from app.utils.logging_utils import log_phase

LOGGER = logging.getLogger(__name__)


async def _resolve_async(input_path: Path, output_path: Path, config: PipelineConfig) -> None:
    frame = read_frame(input_path)
    providers = build_domain_providers(config)
    validator = DomainValidator(config)
    failures: list[dict] = []
    output_records: list[dict] = []

    async with httpx.AsyncClient(
        timeout=config.domain_resolution.timeout_seconds,
        headers={"User-Agent": config.crawl.user_agent},
        verify=not config.domain_resolution.allow_insecure_tls,
    ) as client:
        for row in frame.to_dicts():
            company = CompanyTarget.model_validate(row)
            discovered: list[dict] = []
            for provider in providers:
                provider_candidates = await provider.discover(company)
                discovered.extend(provider_candidates)

            dedup_candidates: dict[str, dict] = {}
            for candidate in discovered[: config.domain_resolution.max_candidates_per_company]:
                url = candidate.get("url")
                if url:
                    dedup_candidates[url] = candidate

            if not dedup_candidates:
                failures.append(
                    FailureRecord(
                        stage="resolve_domains",
                        reason="no_domain_found",
                        siren=company.siren,
                        created_at=datetime.now(UTC),
                    ).model_dump()
                )
                continue

            tasks = [
                validator.validate_candidate(client, company, candidate["url"], candidate["source_type"])
                for candidate in dedup_candidates.values()
            ]
            results = [item for item in await asyncio.gather(*tasks) if item is not None]
            if not results:
                failures.append(
                    FailureRecord(
                        stage="resolve_domains",
                        reason="domain_validation_failed",
                        siren=company.siren,
                        created_at=datetime.now(UTC),
                    ).model_dump()
                )
                continue

            output_records.extend(item.model_dump() for item in results)

    out_frame = records_to_frame(output_records)
    out_frame.write_parquet(output_path)
    write_failures(failures, output_path, config, "resolve_domains")
    LOGGER.info("domains_resolved", extra={"phase": "resolve_domains", "count": out_frame.height, "path": output_path.as_posix()})


def resolve_domains(input_path: str | Path, output_path: str | Path, config: PipelineConfig) -> None:
    input_file = Path(input_path)
    output_file = Path(output_path)
    output_file.parent.mkdir(parents=True, exist_ok=True)

    with log_phase(LOGGER, "resolve_domains"):
        asyncio.run(_resolve_async(input_file, output_file, config))
