from app.config import EmailExtractionConfig
from app.utils.email_utils import has_mx_record, is_blacklisted_email, is_valid_email_syntax


def test_is_valid_email_syntax() -> None:
    assert is_valid_email_syntax("contact@example.fr") is True
    assert is_valid_email_syntax("contact@@example.fr") is False


def test_blacklisted_email() -> None:
    config = EmailExtractionConfig(blacklist_domains=["cloudflare.com"], blacklist_prefixes=["noreply"])
    assert is_blacklisted_email("noreply@example.fr", config) == "blacklisted_prefix"
    assert is_blacklisted_email("contact@cloudflare.com", config) == "blacklisted_domain"


def test_has_mx_record_can_be_mocked(monkeypatch) -> None:
    monkeypatch.setattr("app.utils.email_utils.dns.resolver.Resolver.resolve", lambda self, domain, record: [object()])
    assert has_mx_record("example.fr") is True
