from __future__ import annotations

from app.config import PipelineConfig
from app.models import ConfidenceLabel, EmailCandidate, EmailStatus


def confidence_label_for_score(score: int) -> ConfidenceLabel:
    if score >= 90:
        return ConfidenceLabel.VERY_HIGH
    if score >= 75:
        return ConfidenceLabel.HIGH
    if score >= 60:
        return ConfidenceLabel.MEDIUM
    return ConfidenceLabel.LOW


def score_email_candidate(
    candidate: EmailCandidate,
    domain_score: int,
    domain_status: str | None,
    blacklist_reason: str | None,
    config: PipelineConfig,
) -> tuple[int, EmailStatus, dict, str | None]:
    details = {
        "domain_component": domain_score,
        "observed_component": 0,
        "usage_component": 0,
        "validation_component": 0,
    }
    score = max(0, domain_score)
    rejection_reason: str | None = None

    if candidate.observed_in_mailto and candidate.email_domain == candidate.domain:
        details["observed_component"] += config.scoring.email_observed.mailto_official
    elif candidate.observed_in_contact_page and candidate.email_domain == candidate.domain:
        details["observed_component"] += config.scoring.email_observed.contact_page_official
    elif candidate.observed_in_mentions_legales and candidate.email_domain == candidate.domain:
        details["observed_component"] += config.scoring.email_observed.legal_page_official
    elif candidate.email_domain == candidate.domain:
        details["observed_component"] += config.scoring.email_observed.footer_official
    else:
        details["observed_component"] += config.scoring.email_observed.third_party_penalty

    if candidate.is_pattern_generated:
        details["observed_component"] += config.scoring.email_observed.pattern_generated_penalty

    if candidate.email_type in {"generic_contact", "support", "sales", "workshop"}:
        details["usage_component"] += config.scoring.business_usage.generic_relevant
    elif candidate.email_type == "named_person":
        details["usage_component"] += config.scoring.business_usage.personal_penalty
    elif candidate.email_type == "no_reply":
        details["usage_component"] += config.scoring.business_usage.too_personal_penalty

    if candidate.syntax_valid:
        details["validation_component"] += 5
    else:
        rejection_reason = "invalid_syntax"
    if candidate.mx_valid is True:
        details["validation_component"] += 5
    elif candidate.mx_valid is False:
        details["validation_component"] -= 10

    if blacklist_reason:
        rejection_reason = blacklist_reason
        score = 0
    else:
        score += sum(details.values()) - details["domain_component"]
        score = max(0, min(100, score))

    verified_gate = (
        score >= config.scoring.thresholds.verified_min_score
        and candidate.observed_on_official_site
        and not candidate.is_pattern_generated
        and candidate.email_domain == candidate.domain
        and domain_status in {"verified", "likely"}
    )
    likely_gate = score >= config.scoring.thresholds.likely_min_score and rejection_reason is None

    if rejection_reason or candidate.email_type == "no_reply":
        status = EmailStatus.REJECTED
        rejection_reason = rejection_reason or "unsuitable_email_type"
    elif verified_gate:
        status = EmailStatus.VERIFIED
    elif likely_gate:
        status = EmailStatus.LIKELY
    elif score > 0:
        status = EmailStatus.UNCERTAIN
    else:
        status = EmailStatus.REJECTED
        rejection_reason = rejection_reason or "low_score"

    return score, status, details, rejection_reason


EMAIL_TYPE_PRIORITY = {
    "generic_contact": 4,
    "support": 3,
    "sales": 3,
    "workshop": 3,
    "admin": 2,
    "named_person": 1,
    "unknown": 0,
    "no_reply": -1,
}


def rank_emails_for_export(row: dict) -> tuple:
    return (
        row.get("status") == EmailStatus.VERIFIED.value,
        EMAIL_TYPE_PRIORITY.get(row.get("email_type", "unknown"), 0),
        int(row.get("score") or 0),
        row.get("observed_in_mailto") == "true" or row.get("observed_in_mailto") is True,
    )
