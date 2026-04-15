from __future__ import annotations

import re
import unicodedata


MULTISPACE_RE = re.compile(r"\s+")
NON_ALNUM_RE = re.compile(r"[^a-z0-9]+")


def strip_accents(value: str | None) -> str:
    if not value:
        return ""
    normalized = unicodedata.normalize("NFKD", value)
    return "".join(ch for ch in normalized if not unicodedata.combining(ch))


def normalize_company_name(value: str | None) -> str:
    text = strip_accents(value).lower().strip()
    text = text.replace("&", " et ")
    text = NON_ALNUM_RE.sub(" ", text)
    return MULTISPACE_RE.sub(" ", text).strip()


def normalize_text(value: str | None) -> str:
    return MULTISPACE_RE.sub(" ", strip_accents(value).lower()).strip()


def safe_excerpt(value: str | None, limit: int = 400) -> str | None:
    if not value:
        return None
    compact = MULTISPACE_RE.sub(" ", value).strip()
    if len(compact) <= limit:
        return compact
    return f"{compact[: limit - 1]}…"
