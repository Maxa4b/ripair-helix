from app.scoring.rules import rank_emails_for_export


def test_rank_emails_prefers_verified_generic() -> None:
    generic = {"status": "verified", "email_type": "generic_contact", "score": 80, "observed_in_mailto": True}
    named = {"status": "likely", "email_type": "named_person", "score": 95, "observed_in_mailto": False}
    assert rank_emails_for_export(generic) > rank_emails_for_export(named)
