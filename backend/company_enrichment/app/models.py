from __future__ import annotations

from datetime import datetime
from enum import Enum
from typing import Any

from pydantic import BaseModel, Field, field_validator


class DomainStatus(str, Enum):
    VERIFIED = "verified"
    LIKELY = "likely"
    UNCERTAIN = "uncertain"
    REJECTED = "rejected"


class EmailStatus(str, Enum):
    VERIFIED = "verified"
    LIKELY = "likely"
    UNCERTAIN = "uncertain"
    REJECTED = "rejected"


class ConfidenceLabel(str, Enum):
    VERY_HIGH = "very_high"
    HIGH = "high"
    MEDIUM = "medium"
    LOW = "low"


class CompanyTarget(BaseModel):
    siren: str
    siret: str | None = None
    raison_sociale: str | None = None
    enseigne: str | None = None
    forme_juridique: str | None = None
    naf: str | None = None
    adresse: str | None = None
    code_postal: str | None = None
    ville: str | None = None
    pays: str | None = None
    est_siege: bool | None = None
    statut_administratif: str | None = None
    tranche_effectif: str | None = None
    score_priorite_initial: int = 0
    source_row_id: str | None = None
    website: str | None = None

    @field_validator("source_row_id", mode="before")
    @classmethod
    def normalize_source_row_id(cls, value: Any) -> str | None:
        if value is None:
            return None
        return str(value)


class DomainCandidate(BaseModel):
    siren: str
    domain: str
    normalized_domain: str
    source_url: str | None = None
    source_type: str
    matched_name: bool = False
    matched_address: bool = False
    matched_siren: bool = False
    matched_phone: bool = False
    matched_vat: bool = False
    confidence_signals: dict[str, Any] = Field(default_factory=dict)
    domain_score: int = 0
    domain_status: DomainStatus = DomainStatus.UNCERTAIN
    checked_at: datetime


class PageCrawlResult(BaseModel):
    domain: str
    siren: str | None = None
    url: str
    http_status: int | None = None
    content_type: str | None = None
    fetch_success: bool = False
    title: str | None = None
    html_hash: str | None = None
    text_excerpt: str | None = None
    detected_emails: list[str] = Field(default_factory=list)
    detected_phones: list[str] = Field(default_factory=list)
    detected_siren: list[str] = Field(default_factory=list)
    detected_address: list[str] = Field(default_factory=list)
    page_type: str = "other"
    crawl_depth: int = 0
    fetched_at: datetime


class EmailCandidate(BaseModel):
    siren: str
    domain: str | None = None
    email: str
    local_part: str
    email_domain: str
    email_type: str = "unknown"
    source_url: str | None = None
    source_type: str = "html"
    observed_on_official_site: bool = False
    observed_in_mailto: bool = False
    observed_in_contact_page: bool = False
    observed_in_mentions_legales: bool = False
    is_pattern_generated: bool = False
    syntax_valid: bool = False
    mx_valid: bool | None = None
    score: int = 0
    status: EmailStatus = EmailStatus.UNCERTAIN
    rejection_reason: str | None = None
    discovered_at: datetime
    score_details: dict[str, Any] = Field(default_factory=dict)


class FinalContact(BaseModel):
    siren: str
    siret: str | None = None
    raison_sociale: str | None = None
    naf: str | None = None
    ville: str | None = None
    domain: str | None = None
    best_email: str | None = None
    best_email_type: str | None = None
    score: int = 0
    confidence_label: ConfidenceLabel = ConfidenceLabel.LOW
    source_url: str | None = None
    source_type: str | None = None
    status: EmailStatus = EmailStatus.UNCERTAIN
    checked_at: datetime


class FailureRecord(BaseModel):
    stage: str
    reason: str
    siren: str | None = None
    domain: str | None = None
    source_url: str | None = None
    details: dict[str, Any] = Field(default_factory=dict)
    created_at: datetime
