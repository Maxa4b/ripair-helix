from __future__ import annotations

from abc import ABC, abstractmethod
from typing import Any

from app.config import PipelineConfig
from app.models import CompanyTarget
from app.utils.io import read_frame


class BaseDomainProvider(ABC):
    source_type: str

    @abstractmethod
    async def discover(self, company: CompanyTarget) -> list[dict[str, Any]]:
        raise NotImplementedError


class ColumnDomainProvider(BaseDomainProvider):
    source_type = "source_column"

    async def discover(self, company: CompanyTarget) -> list[dict[str, Any]]:
        if not company.website:
            return []
        return [{"url": company.website, "source_type": self.source_type}]


class SeedFileDomainProvider(BaseDomainProvider):
    source_type = "seed_file"

    def __init__(self, candidates: pl.DataFrame) -> None:
        self.candidates = candidates

    async def discover(self, company: CompanyTarget) -> list[dict[str, Any]]:
        if self.candidates.is_empty():
            return []
        subset = self.candidates.filter(pl.col("siren") == company.siren)
        rows = subset.to_dicts()
        return [
            {
                "url": row.get("source_url") or row.get("url") or row.get("domain"),
                "source_type": row.get("source_type") or self.source_type,
            }
            for row in rows
            if row.get("source_url") or row.get("url") or row.get("domain")
        ]


def build_domain_providers(config: PipelineConfig) -> list[BaseDomainProvider]:
    providers: list[BaseDomainProvider] = []
    providers.append(ColumnDomainProvider())

    if config.domain_resolution.seed_candidates_path:
        candidates = read_frame(config.domain_resolution.seed_candidates_path)
        providers.append(SeedFileDomainProvider(candidates))

    return providers
