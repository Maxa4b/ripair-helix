from __future__ import annotations

import hashlib
import re
from urllib.parse import urljoin, urlparse, urlunparse

from app.utils.text import normalize_company_name, normalize_text

SIREN_RE = re.compile(r"\b\d{9}\b")
VAT_RE = re.compile(r"\bFR[0-9A-Z]{2}\d{9}\b", re.IGNORECASE)
PHONE_RE = re.compile(r"(?:(?:\+33|0)[1-9](?:[\s.\-]?\d{2}){4})")


def normalize_domain(domain_or_url: str | None) -> str | None:
    if not domain_or_url:
        return None
    candidate = domain_or_url.strip()
    if "://" not in candidate:
        candidate = f"https://{candidate}"
    parsed = urlparse(candidate)
    host = (parsed.hostname or "").strip(".").lower()
    if not host:
        return None
    if host.startswith("www."):
        host = host[4:]
    try:
        host = host.encode("idna").decode("ascii")
    except UnicodeError:
        return None
    return host


def canonicalize_url(url: str, base_url: str | None = None) -> str:
    joined = urljoin(base_url, url) if base_url else url
    parsed = urlparse(joined)
    scheme = parsed.scheme or "https"
    netloc = parsed.netloc.lower()
    if netloc.startswith("www."):
        netloc = netloc[4:]
    path = parsed.path or "/"
    return urlunparse((scheme, netloc, path, "", "", ""))


def is_same_domain(left: str | None, right: str | None) -> bool:
    left_normalized = normalize_domain(left)
    right_normalized = normalize_domain(right)
    return bool(left_normalized and right_normalized and left_normalized == right_normalized)


def html_hash(content: str | bytes) -> str:
    raw = content.encode("utf-8", errors="ignore") if isinstance(content, str) else content
    return hashlib.sha256(raw).hexdigest()


def score_company_site_match(
    company_name: str | None,
    company_city: str | None,
    company_address: str | None,
    company_siren: str,
    page_text: str,
) -> dict[str, bool]:
    normalized_text = normalize_text(page_text)
    normalized_name = normalize_company_name(company_name)
    normalized_city = normalize_text(company_city)
    normalized_address = normalize_text(company_address)
    compact_text = normalized_text.replace(" ", "")

    return {
        "matched_name": bool(normalized_name and normalized_name in normalized_text),
        "matched_address": bool(
            normalized_address and normalized_address[:25] and normalized_address[:25] in normalized_text
        ) or bool(normalized_city and normalized_city in normalized_text),
        "matched_siren": company_siren in compact_text,
        "matched_vat": bool(VAT_RE.search(page_text)),
        "matched_phone": bool(PHONE_RE.search(page_text)),
    }


def extract_siren_candidates(text: str) -> list[str]:
    return sorted(set(SIREN_RE.findall(text)))


def is_asset_url(url: str, ignored_extensions: list[str]) -> bool:
    lower = url.lower()
    return any(lower.endswith(ext.lower()) for ext in ignored_extensions)
