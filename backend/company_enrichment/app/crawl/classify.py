from __future__ import annotations


def classify_page_type(url: str, title: str | None, text: str | None) -> str:
    lower_url = url.lower()
    haystack = f"{lower_url} {(title or '').lower()} {(text or '')[:300].lower()}"
    if any(token in haystack for token in ("/contact", "nous contacter", "contactez")):
        return "contact_page"
    if any(token in haystack for token in ("mentions-legales", "/legal", "mentions legales")):
        return "legal_page"
    if any(token in haystack for token in ("/support", "/sav", "service apres-vente")):
        return "support_page"
    if any(token in haystack for token in ("/about", "/a-propos", "/team", "/equipe", "qui sommes")):
        return "about_page"
    if lower_url.rstrip("/").count("/") <= 2:
        return "homepage"
    return "other"
