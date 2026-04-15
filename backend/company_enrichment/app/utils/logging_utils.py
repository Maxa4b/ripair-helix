from __future__ import annotations

import json
import logging
import time
from contextlib import contextmanager
from typing import Iterator


class JsonFormatter(logging.Formatter):
    def format(self, record: logging.LogRecord) -> str:
        payload = {
            "level": record.levelname,
            "logger": record.name,
            "message": record.getMessage(),
            "time": self.formatTime(record, self.datefmt),
        }
        for key in ("phase", "count", "duration_s", "reason", "path"):
            value = getattr(record, key, None)
            if value is not None:
                payload[key] = value
        return json.dumps(payload, ensure_ascii=False)


def configure_logging(level: str = "INFO") -> None:
    handler = logging.StreamHandler()
    handler.setFormatter(JsonFormatter())
    root = logging.getLogger()
    root.handlers.clear()
    root.addHandler(handler)
    root.setLevel(level.upper())


@contextmanager
def log_phase(logger: logging.Logger, phase: str) -> Iterator[None]:
    started = time.perf_counter()
    logger.info("phase_started", extra={"phase": phase})
    try:
        yield
    finally:
        logger.info(
            "phase_finished",
            extra={"phase": phase, "duration_s": round(time.perf_counter() - started, 3)},
        )
