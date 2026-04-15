from __future__ import annotations

import asyncio
from dataclasses import dataclass
from datetime import datetime, UTC

import httpx
from bs4 import BeautifulSoup

from app.config import PipelineConfig
from app.models import CompanyTarget, DomainCandidate, DomainStatus
from app.utils.domains import canonicalize_url, normalize_domain, score_company_site_match
from app.utils.text import safe_excerpt


@dataclass
class DomainFetchSnapshot:
    final_url: str
    title: str | None
    aggregated_text: str
    fetched_urls: list[str]


class DomainValidator:
    def __init__(self, config: PipelineConfig) -> None:
        self.config = config
        self._cache: dict[str, DomainFetchSnapshot] = {}
        self._semaphore = asyncio.Semaphore(config.domain_resolution.concurrency)

    async def _fetch_snapshot(self, client: httpx.AsyncClient, candidate_url: str) -> DomainFetchSnapshot | None:
        normalized_domain = normalize_domain(candidate_url)
        if not normalized_domain:
            return None
        if normalized_domain in self._cache:
            return self._cache[normalized_domain]

        base_url = canonicalize_url(f"https://{normalized_domain}/")
        texts: list[str] = []
        title: str | None = None
        fetched_urls: list[str] = []

        async with self._semaphore:
            for path in self.config.domain_resolution.validation_paths:
                url = canonicalize_url(path, base_url)
                try:
                    response = await client.get(url, follow_redirects=True)
                    if response.status_code >= 400 or "html" not in response.headers.get("content-type", ""):
                        continue
                except httpx.HTTPError:
                    continue

                fetched_urls.append(str(response.url))
                soup = BeautifulSoup(response.text, "html.parser")
                if title is None and soup.title:
                    title = soup.title.get_text(" ", strip=True)
                texts.append(soup.get_text(" ", strip=True))

        if not texts:
            return None

        snapshot = DomainFetchSnapshot(
            final_url=base_url,
            title=title,
            aggregated_text="\n".join(texts),
            fetched_urls=fetched_urls,
        )
        self._cache[normalized_domain] = snapshot
        return snapshot

    def _score_candidate(self, company: CompanyTarget, snapshot: DomainFetchSnapshot) -> tuple[int, DomainStatus, dict]:
        signals = score_company_site_match(
            company_name=company.raison_sociale or company.enseigne,
            company_city=company.ville,
            company_address=company.adresse,
            company_siren=company.siren,
            page_text=snapshot.aggregated_text,
        )
        score = 0
        if signals["matched_siren"]:
            score += self.config.scoring.domain.siren_exact
        if signals["matched_name"] and signals["matched_address"]:
            score += self.config.scoring.domain.address_and_name
        elif signals["matched_name"] or signals["matched_address"]:
            score += self.config.scoring.domain.brand_and_location
        if score == 0:
            score += self.config.scoring.domain.uncertain_penalty

        thresholds = self.config.domain_resolution.status_thresholds
        if score >= thresholds.verified:
            status = DomainStatus.VERIFIED
        elif score >= thresholds.likely:
            status = DomainStatus.LIKELY
        elif score >= thresholds.uncertain:
            status = DomainStatus.UNCERTAIN
        else:
            status = DomainStatus.REJECTED

        confidence_signals = {
            **signals,
            "title": snapshot.title,
            "fetched_urls": snapshot.fetched_urls,
            "excerpt": safe_excerpt(snapshot.aggregated_text),
        }
        return score, status, confidence_signals

    async def validate_candidate(
        self,
        client: httpx.AsyncClient,
        company: CompanyTarget,
        candidate_url: str,
        source_type: str,
    ) -> DomainCandidate | None:
        normalized = normalize_domain(candidate_url)
        if not normalized:
            return None
        snapshot = await self._fetch_snapshot(client, candidate_url)
        if snapshot is None:
            return DomainCandidate(
                siren=company.siren,
                domain=normalized,
                normalized_domain=normalized,
                source_url=candidate_url,
                source_type=source_type,
                domain_score=self.config.scoring.domain.uncertain_penalty,
                domain_status=DomainStatus.REJECTED,
                confidence_signals={"fetch_failed": True},
                checked_at=datetime.now(UTC),
            )

        score, status, signals = self._score_candidate(company, snapshot)
        return DomainCandidate(
            siren=company.siren,
            domain=normalized,
            normalized_domain=normalized,
            source_url=candidate_url,
            source_type=source_type,
            matched_name=signals.get("matched_name", False),
            matched_address=signals.get("matched_address", False),
            matched_siren=signals.get("matched_siren", False),
            matched_phone=signals.get("matched_phone", False),
            matched_vat=signals.get("matched_vat", False),
            confidence_signals=signals,
            domain_score=score,
            domain_status=status,
            checked_at=datetime.now(UTC),
        )
