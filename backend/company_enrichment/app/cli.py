from __future__ import annotations

import argparse
from pathlib import Path

from app.config import load_config
from app.crawl import crawl_domains
from app.domain_resolution import resolve_domains
from app.export import export_final_contacts
from app.extract import score_emails_from_crawl
from app.ingest import ingest_targets
from app.utils.logging_utils import configure_logging


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(prog="python -m app", description="Helix company enrichment pipeline")
    subparsers = parser.add_subparsers(dest="command", required=True)

    ingest = subparsers.add_parser("ingest")
    ingest.add_argument("--input", required=True)
    ingest.add_argument("--output", required=True)
    ingest.add_argument("--config", required=True)

    resolve = subparsers.add_parser("resolve-domains")
    resolve.add_argument("--input", required=True)
    resolve.add_argument("--output", required=True)
    resolve.add_argument("--config", required=True)

    crawl = subparsers.add_parser("crawl")
    crawl.add_argument("--input", required=True)
    crawl.add_argument("--output", required=True)
    crawl.add_argument("--config", required=True)

    score = subparsers.add_parser("score-emails")
    score.add_argument("--input", required=True)
    score.add_argument("--output", required=True)
    score.add_argument("--config", required=True)

    export = subparsers.add_parser("export-final")
    export.add_argument("--targets", required=True)
    export.add_argument("--domains", required=True)
    export.add_argument("--emails", required=True)
    export.add_argument("--output", required=True)
    export.add_argument("--config", required=True)

    run_all = subparsers.add_parser("run-all")
    run_all.add_argument("--input", required=True)
    run_all.add_argument("--output-dir", required=True)
    run_all.add_argument("--config", required=True)

    return parser


def main(argv: list[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)
    config = load_config(args.config)
    configure_logging(config.logging.level)

    if args.command == "ingest":
        ingest_targets(args.input, args.output, config)
        return 0
    if args.command == "resolve-domains":
        resolve_domains(args.input, args.output, config)
        return 0
    if args.command == "crawl":
        crawl_domains(args.input, args.output, config)
        return 0
    if args.command == "score-emails":
        score_emails_from_crawl(args.input, args.output, config)
        return 0
    if args.command == "export-final":
        export_final_contacts(args.targets, args.domains, args.emails, args.output, config)
        return 0
    if args.command == "run-all":
        output_dir = Path(args.output_dir)
        output_dir.mkdir(parents=True, exist_ok=True)
        targets = output_dir / "targets.parquet"
        domains = output_dir / "domain_candidates.parquet"
        crawled = output_dir / "crawl_results.parquet"
        emails = output_dir / "email_candidates.parquet"
        final_contacts = output_dir / "final_contacts.csv"

        ingest_targets(args.input, targets, config)
        resolve_domains(targets, domains, config)
        crawl_domains(domains, crawled, config)
        score_emails_from_crawl(crawled, emails, config)
        export_final_contacts(targets, domains, emails, final_contacts, config)
        return 0
    return 1
