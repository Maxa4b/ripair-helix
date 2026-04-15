from __future__ import annotations

import sqlite3
from pathlib import Path


class SqliteHttpCache:
    def __init__(self, path: str | Path) -> None:
        self.path = Path(path)
        self.path.parent.mkdir(parents=True, exist_ok=True)
        with sqlite3.connect(self.path) as connection:
            connection.execute(
                """
                CREATE TABLE IF NOT EXISTS http_cache (
                    url TEXT PRIMARY KEY,
                    status_code INTEGER,
                    content_type TEXT,
                    body TEXT,
                    fetched_at TEXT
                )
                """
            )

    def get(self, url: str) -> dict | None:
        with sqlite3.connect(self.path) as connection:
            row = connection.execute(
                "SELECT url, status_code, content_type, body, fetched_at FROM http_cache WHERE url = ?",
                (url,),
            ).fetchone()
        if row is None:
            return None
        return {
            "url": row[0],
            "status_code": row[1],
            "content_type": row[2],
            "body": row[3],
            "fetched_at": row[4],
        }

    def set(self, url: str, status_code: int, content_type: str, body: str, fetched_at: str) -> None:
        with sqlite3.connect(self.path) as connection:
            connection.execute(
                """
                INSERT INTO http_cache(url, status_code, content_type, body, fetched_at)
                VALUES (?, ?, ?, ?, ?)
                ON CONFLICT(url) DO UPDATE SET
                    status_code = excluded.status_code,
                    content_type = excluded.content_type,
                    body = excluded.body,
                    fetched_at = excluded.fetched_at
                """,
                (url, status_code, content_type, body, fetched_at),
            )
