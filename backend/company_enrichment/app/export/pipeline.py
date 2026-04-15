from __future__ import annotations

import logging
from datetime import UTC, datetime
from pathlib import Path

import polars as pl

from app.config import PipelineConfig
from app.models import FailureRecord, FinalContact
from app.scoring.rules import confidence_label_for_score, rank_emails_for_export
from app.utils.io import read_frame, records_to_frame, write_failures
from app.utils.logging_utils import log_phase

LOGGER = logging.getLogger(__name__)


def export_final_contacts(
    targets_path: str | Path,
    domains_path: str | Path,
    emails_path: str | Path,
    output_path: str | Path,
    config: PipelineConfig,
) -> None:
    output_file = Path(output_path)
    output_file.parent.mkdir(parents=True, exist_ok=True)
    failures: list[dict] = []

    with log_phase(LOGGER, "export_final"):
        targets = read_frame(targets_path)
        domains = read_frame(domains_path)
        emails = read_frame(emails_path)

        best_domain_by_siren: dict[str, dict] = {}
        for row in domains.to_dicts():
            current = best_domain_by_siren.get(row["siren"])
            if current is None or int(row.get("domain_score") or 0) > int(current.get("domain_score") or 0):
                best_domain_by_siren[row["siren"]] = row

        best_email_by_siren: dict[str, dict] = {}
        for row in sorted(emails.to_dicts(), key=rank_emails_for_export, reverse=True):
            best_email_by_siren.setdefault(row["siren"], row)

        final_records: list[dict] = []
        for target in targets.to_dicts():
            domain_row = best_domain_by_siren.get(target["siren"])
            email_row = best_email_by_siren.get(target["siren"])
            if not domain_row:
                failures.append(
                    FailureRecord(
                        stage="export_final",
                        reason="no_domain_found",
                        siren=target["siren"],
                        created_at=datetime.now(UTC),
                    ).model_dump()
                )
            if not email_row:
                failures.append(
                    FailureRecord(
                        stage="export_final",
                        reason="no_email_found",
                        siren=target["siren"],
                        domain=domain_row.get("normalized_domain") if domain_row else None,
                        created_at=datetime.now(UTC),
                    ).model_dump()
                )

            best_score = int(email_row.get("score") or 0) if email_row else 0
            label = confidence_label_for_score(best_score)
            status = email_row.get("status", "rejected") if email_row else "rejected"
            if best_score < config.scoring.thresholds.primary_export_min_score:
                failures.append(
                    FailureRecord(
                        stage="export_final",
                        reason="only_low_confidence_email",
                        siren=target["siren"],
                        domain=domain_row.get("normalized_domain") if domain_row else None,
                        created_at=datetime.now(UTC),
                    ).model_dump()
                )

            contact = FinalContact(
                siren=target["siren"],
                siret=target.get("siret"),
                raison_sociale=target.get("raison_sociale"),
                naf=target.get("naf"),
                ville=target.get("ville"),
                domain=domain_row.get("normalized_domain") if domain_row else None,
                best_email=email_row.get("email") if email_row and best_score >= config.scoring.thresholds.primary_export_min_score else None,
                best_email_type=email_row.get("email_type") if email_row and best_score >= config.scoring.thresholds.primary_export_min_score else None,
                score=best_score,
                confidence_label=label,
                source_url=email_row.get("source_url") if email_row else domain_row.get("source_url") if domain_row else None,
                source_type=email_row.get("source_type") if email_row else domain_row.get("source_type") if domain_row else None,
                status=status,
                checked_at=datetime.now(UTC),
            )
            final_records.append(contact.model_dump())

        final_frame = records_to_frame(final_records)
        if output_file.suffix.lower() == ".parquet":
            final_frame.write_parquet(output_file)
            final_frame.write_csv(output_file.with_suffix(".csv"))
        else:
            final_frame.write_csv(output_file)
            final_frame.write_parquet(output_file.with_suffix(".parquet"))
        write_failures(failures, output_file, config, "export_final")
        LOGGER.info("export_completed", extra={"phase": "export_final", "count": final_frame.height, "path": output_file.as_posix()})
