from __future__ import annotations

import re
from email.utils import parseaddr

import dns.resolver

from app.config import EmailExtractionConfig
from app.utils.domains import normalize_domain

EMAIL_RE = re.compile(r"\b([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})\b", re.IGNORECASE)
MAILTO_RE = re.compile(r"mailto:([^?\"'#<>\s]+)", re.IGNORECASE)
OBFUSCATED_PATTERNS = [
    (
        re.compile(r"\b([a-z0-9._%+\-]+)\s*\[\s*at\s*\]\s*([a-z0-9.\-]+)\s*\[\s*dot\s*\]\s*([a-z]{2,})\b", re.I),
        "{0}@{1}.{2}",
    ),
    (
        re.compile(r"\b([a-z0-9._%+\-]+)\s*\(\s*at\s*\)\s*([a-z0-9.\-]+)\s*\(\s*point\s*\)\s*([a-z]{2,})\b", re.I),
        "{0}@{1}.{2}",
    ),
]
ROLE_KEYWORDS = {"atelier": "workshop", "support": "support", "sav": "support", "sales": "sales", "commercial": "sales"}


def deobfuscate_emails(text: str) -> set[str]:
    results: set[str] = set()
    for pattern, template in OBFUSCATED_PATTERNS:
        for match in pattern.findall(text):
            results.add(template.format(*match).lower())
    return results


def extract_emails_from_text(text: str) -> set[str]:
    found = {match.lower() for match in EMAIL_RE.findall(text or "")}
    found.update(deobfuscate_emails(text or ""))
    return found


def extract_mailtos(html: str) -> set[str]:
    return {match.lower() for match in MAILTO_RE.findall(html or "")}


def classify_email_type(email: str, config: EmailExtractionConfig) -> str:
    local_part = email.split("@", 1)[0].lower()
    if any(local_part.startswith(prefix) for prefix in config.generic_prefixes):
        if any(keyword in local_part for keyword in ("support", "sav")):
            return "support"
        if any(keyword in local_part for keyword in ("sales", "commercial", "devis")):
            return "sales"
        if "atelier" in local_part:
            return "workshop"
        return "generic_contact"
    if any(token in local_part for token in ROLE_KEYWORDS):
        return ROLE_KEYWORDS[next(token for token in ROLE_KEYWORDS if token in local_part)]
    if local_part.startswith(("noreply", "no-reply", "donotreply", "do-not-reply")):
        return "no_reply"
    if "." in local_part or "_" in local_part:
        return "named_person"
    return "unknown"


def is_valid_email_syntax(email: str) -> bool:
    _, parsed = parseaddr(email)
    if not parsed or "@" not in parsed:
        return False
    return bool(EMAIL_RE.fullmatch(parsed))


def is_blacklisted_email(email: str, config: EmailExtractionConfig) -> str | None:
    local_part, _, domain = email.lower().partition("@")
    normalized = normalize_domain(domain)
    if not normalized:
        return "invalid_domain"
    blacklisted_domains = {normalize_domain(item) for item in config.blacklist_domains}
    if normalized in blacklisted_domains:
        return "blacklisted_domain"
    if any(local_part.startswith(prefix) for prefix in config.blacklist_prefixes):
        return "blacklisted_prefix"
    return None


def has_mx_record(domain: str, timeout_seconds: float = 4.0) -> bool | None:
    resolver = dns.resolver.Resolver(configure=True)
    resolver.lifetime = timeout_seconds
    resolver.timeout = timeout_seconds
    try:
        answers = resolver.resolve(domain, "MX")
    except (dns.resolver.NoAnswer, dns.resolver.NXDOMAIN, dns.resolver.NoNameservers):
        return False
    except Exception:
        return None
    return bool(answers)


def generate_pattern_candidates(domain: str, observed_emails: set[str], person_name: str | None) -> set[str]:
    if not person_name or not observed_emails:
        return set()
    local_patterns = {email.split("@", 1)[0] for email in observed_emails if email.endswith(f"@{domain}")}
    if not local_patterns:
        return set()
    tokens = [token for token in re.split(r"[\s\-]+", person_name.lower()) if token]
    if len(tokens) < 2:
        return set()

    first_name, last_name = tokens[0], tokens[-1]
    candidates = {
        f"{first_name}.{last_name}@{domain}",
        f"{first_name[0]}.{last_name}@{domain}",
        f"{first_name}@{domain}",
        f"{first_name[0]}{last_name}@{domain}",
    }
    return {candidate for candidate in candidates if any(local[0] == candidate[0] for local in local_patterns)}
