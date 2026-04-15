from pathlib import Path

from app.config import EmailExtractionConfig
from app.utils.email_utils import classify_email_type, deobfuscate_emails, extract_emails_from_text, extract_mailtos


FIXTURES = Path(__file__).parent / "fixtures" / "html"


def test_extract_mailto_and_plain_email() -> None:
    html = (FIXTURES / "contact_mailto.html").read_text(encoding="utf-8")
    assert "contact@acme-repair.fr" in extract_mailtos(html)
    assert "contact@acme-repair.fr" in extract_emails_from_text(html)


def test_deobfuscate_email() -> None:
    html = (FIXTURES / "obfuscated_email.html").read_text(encoding="utf-8")
    assert deobfuscate_emails(html) == {"devis@acme-repair.fr"}


def test_classify_generic_email_type() -> None:
    config = EmailExtractionConfig(generic_prefixes=["contact", "support", "devis"])
    assert classify_email_type("contact@acme.fr", config) == "generic_contact"
    assert classify_email_type("devis@acme.fr", config) == "sales"
