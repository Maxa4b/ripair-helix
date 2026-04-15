from app.crawl.classify import classify_page_type


def test_classify_contact_page() -> None:
    assert classify_page_type("https://example.fr/contact", "Contact", "Nous contacter") == "contact_page"


def test_classify_legal_page() -> None:
    assert classify_page_type("https://example.fr/mentions-legales", "Mentions légales", "") == "legal_page"
