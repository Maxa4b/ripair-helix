from __future__ import annotations

from pathlib import Path
from typing import Any

import yaml
from pydantic import BaseModel, Field


class CsvSourceConfig(BaseModel):
    delimiter: str = ","
    quotechar: str = '"'
    encoding: str = "utf-8"
    header: bool = True
    sample_size: int = 50_000


class SourceConfig(BaseModel):
    format: str = "csv"
    source_columns: dict[str, str] = Field(default_factory=dict)
    csv: CsvSourceConfig = Field(default_factory=CsvSourceConfig)


class FilterConfig(BaseModel):
    active_only: bool = True
    headquarters_only: bool = False
    include_naf_prefixes: list[str] = Field(default_factory=list)
    exclude_naf_prefixes: list[str] = Field(default_factory=list)
    include_legal_forms: list[str] = Field(default_factory=list)
    exclude_legal_forms: list[str] = Field(default_factory=list)
    min_effectif: int | None = None
    countries: list[str] = Field(default_factory=list)
    departments: list[str] = Field(default_factory=list)
    regions: list[str] = Field(default_factory=list)
    cities: list[str] = Field(default_factory=list)
    excluded_statuses: list[str] = Field(default_factory=list)


class PriorityWeights(BaseModel):
    naf_match: int = 30
    effectif_match: int = 20
    legal_form_match: int = 15
    known_domain_hint: int = 10
    individual_like_penalty: int = -40
    closed_penalty: int = -50


class DomainStatusThresholds(BaseModel):
    verified: int = 70
    likely: int = 45
    uncertain: int = 20


class DomainResolutionConfig(BaseModel):
    provider: str = "column"
    website_column: str | None = None
    seed_candidates_path: str | None = None
    batch_size: int = 100
    concurrency: int = 10
    timeout_seconds: float = 12.0
    max_candidates_per_company: int = 5
    allow_insecure_tls: bool = False
    validation_paths: list[str] = Field(default_factory=lambda: ["/", "/contact", "/mentions-legales"])
    status_thresholds: DomainStatusThresholds = Field(default_factory=DomainStatusThresholds)


class CrawlConfig(BaseModel):
    concurrency: int = 20
    timeout_seconds: float = 10.0
    retries: int = 2
    max_pages_per_domain: int = 6
    max_depth: int = 1
    stop_after_high_confidence_email: bool = True
    allow_pdf: bool = True
    ignore_extensions: list[str] = Field(default_factory=list)
    priority_paths: list[str] = Field(default_factory=list)
    user_agent: str = "HelixEnrichmentBot/0.1"


class EmailExtractionConfig(BaseModel):
    enable_pattern_generation: bool = True
    enable_mx_check: bool = True
    blacklist_domains: list[str] = Field(default_factory=list)
    blacklist_prefixes: list[str] = Field(default_factory=list)
    generic_prefixes: list[str] = Field(default_factory=list)


class ScoringThresholds(BaseModel):
    primary_export_min_score: int = 75
    verified_min_score: int = 75
    likely_min_score: int = 60


class DomainScoreConfig(BaseModel):
    siren_exact: int = 35
    address_and_name: int = 20
    brand_and_location: int = 10
    uncertain_penalty: int = -50


class EmailObservedScoreConfig(BaseModel):
    mailto_official: int = 35
    contact_page_official: int = 30
    legal_page_official: int = 25
    footer_official: int = 15
    pdf_official: int = 10
    third_party_penalty: int = -25
    pattern_generated_penalty: int = -40


class BusinessUsageScoreConfig(BaseModel):
    generic_relevant: int = 15
    named_with_role: int = 5
    personal_penalty: int = -20
    too_personal_penalty: int = -30


class ScoringConfig(BaseModel):
    thresholds: ScoringThresholds = Field(default_factory=ScoringThresholds)
    domain: DomainScoreConfig = Field(default_factory=DomainScoreConfig)
    email_observed: EmailObservedScoreConfig = Field(default_factory=EmailObservedScoreConfig)
    business_usage: BusinessUsageScoreConfig = Field(default_factory=BusinessUsageScoreConfig)


class StorageConfig(BaseModel):
    sqlite_path: str = ".state/enrichment.sqlite3"
    http_cache_path: str = ".state/http_cache.sqlite3"
    output_failures_name: str = "failures"


class LoggingConfig(BaseModel):
    level: str = "INFO"


class PipelineConfig(BaseModel):
    source: SourceConfig = Field(default_factory=SourceConfig)
    filters: FilterConfig = Field(default_factory=FilterConfig)
    priority_weights: PriorityWeights = Field(default_factory=PriorityWeights)
    domain_resolution: DomainResolutionConfig = Field(default_factory=DomainResolutionConfig)
    crawl: CrawlConfig = Field(default_factory=CrawlConfig)
    email_extraction: EmailExtractionConfig = Field(default_factory=EmailExtractionConfig)
    scoring: ScoringConfig = Field(default_factory=ScoringConfig)
    storage: StorageConfig = Field(default_factory=StorageConfig)
    logging: LoggingConfig = Field(default_factory=LoggingConfig)


def load_config(path: str | Path) -> PipelineConfig:
    with Path(path).open("r", encoding="utf-8") as handle:
        raw = yaml.safe_load(handle) or {}
    return PipelineConfig.model_validate(raw)


def ensure_directory(path: str | Path) -> Path:
    directory = Path(path)
    directory.mkdir(parents=True, exist_ok=True)
    return directory


def dump_yaml(data: dict[str, Any], path: str | Path) -> None:
    with Path(path).open("w", encoding="utf-8") as handle:
        yaml.safe_dump(data, handle, allow_unicode=False, sort_keys=False)
