from __future__ import annotations

from datetime import UTC, datetime

import httpx
from tenacity import AsyncRetrying, retry_if_exception_type, stop_after_attempt, wait_exponential

from app.config import PipelineConfig
from app.crawl.cache import SqliteHttpCache


class CachedHttpClient:
    def __init__(self, config: PipelineConfig) -> None:
        self.config = config
        self.cache = SqliteHttpCache(config.storage.http_cache_path)
        self.client = httpx.AsyncClient(
            timeout=config.crawl.timeout_seconds,
            headers={"User-Agent": config.crawl.user_agent},
            follow_redirects=True,
        )

    async def close(self) -> None:
        await self.client.aclose()

    async def get(self, url: str) -> dict | None:
        cached = self.cache.get(url)
        if cached:
            return cached

        async for attempt in AsyncRetrying(
            reraise=True,
            stop=stop_after_attempt(self.config.crawl.retries + 1),
            wait=wait_exponential(min=1, max=4),
            retry=retry_if_exception_type(httpx.HTTPError),
        ):
            with attempt:
                response = await self.client.get(url)
                payload = {
                    "url": str(response.url),
                    "status_code": response.status_code,
                    "content_type": response.headers.get("content-type", ""),
                    "body": response.text,
                    "fetched_at": datetime.now(UTC).isoformat(),
                }
                self.cache.set(**payload)
                return payload
        return None
