from app.utils.domains import normalize_domain
from app.utils.text import normalize_company_name


def test_normalize_company_name_strips_accents_and_symbols() -> None:
    assert normalize_company_name("Réparations & Fils SARL") == "reparations et fils sarl"


def test_normalize_domain_strips_www_and_scheme() -> None:
    assert normalize_domain("https://www.Example.FR/contact") == "example.fr"
