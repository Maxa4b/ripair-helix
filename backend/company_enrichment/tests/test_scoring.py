from datetime import UTC, datetime

from app.config import PipelineConfig
from app.models import EmailCandidate, EmailStatus
from app.scoring.rules import confidence_label_for_score, score_email_candidate


def test_score_email_candidate_verified_generic_contact() -> None:
    config = PipelineConfig()
    candidate = EmailCandidate(
        siren="123456789",
        domain="acme.fr",
        email="contact@acme.fr",
        local_part="contact",
        email_domain="acme.fr",
        email_type="generic_contact",
        source_url="https://acme.fr/contact",
        source_type="contact_page",
        observed_on_official_site=True,
        observed_in_mailto=True,
        observed_in_contact_page=True,
        syntax_valid=True,
        mx_valid=True,
        discovered_at=datetime.now(UTC),
    )
    score, status, _, reason = score_email_candidate(candidate, 35, "verified", None, config)
    assert score >= 75
    assert status == EmailStatus.VERIFIED
    assert reason is None


def test_confidence_label_boundaries() -> None:
    assert confidence_label_for_score(95).value == "very_high"
    assert confidence_label_for_score(76).value == "high"
    assert confidence_label_for_score(60).value == "medium"
    assert confidence_label_for_score(10).value == "low"
