from __future__ import annotations

import json
from datetime import datetime
from enum import Enum
from pathlib import Path
from typing import Iterable

import polars as pl

from app.config import PipelineConfig


def read_frame(path: str | Path) -> pl.DataFrame:
    file_path = Path(path)
    if file_path.suffix.lower() == ".csv":
        return pl.read_csv(file_path)
    return pl.read_parquet(file_path)


def records_to_frame(records: Iterable[dict]) -> pl.DataFrame:
    normalized: list[dict] = []
    for record in records:
        normalized.append(
            {
                key: json.dumps(value, ensure_ascii=False)
                if isinstance(value, (dict, list))
                else value.value
                if isinstance(value, Enum)
                else value.isoformat()
                if isinstance(value, datetime)
                else value
                for key, value in record.items()
            }
        )
    return pl.DataFrame(normalized) if normalized else pl.DataFrame()


def write_frame(frame: pl.DataFrame, path: str | Path) -> None:
    target = Path(path)
    target.parent.mkdir(parents=True, exist_ok=True)
    if target.suffix.lower() == ".csv":
        frame.write_csv(target)
    else:
        frame.write_parquet(target)


def write_failures(
    failures: list[dict],
    output_anchor: str | Path,
    config: PipelineConfig,
    stage: str,
) -> None:
    if not failures:
        return
    anchor = Path(output_anchor)
    stem = anchor.parent / f"{config.storage.output_failures_name}_{stage}"
    frame = records_to_frame(failures)
    frame.write_csv(stem.with_suffix(".csv"))
    frame.write_parquet(stem.with_suffix(".parquet"))
