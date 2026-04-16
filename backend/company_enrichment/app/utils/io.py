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
                key: _normalize_scalar(value)
                for key, value in record.items()
            }
        )

    if not normalized:
        return pl.DataFrame()

    keys = {key for row in normalized for key in row.keys()}
    scalar_types: dict[str, set[type]] = {key: set() for key in keys}
    for row in normalized:
        for key in keys:
            value = row.get(key)
            if value is None:
                continue
            scalar_types[key].add(type(value))

    columns_to_string = {
        key
        for key, types in scalar_types.items()
        if len(types) > 1
    }

    if columns_to_string:
        normalized = [
            {
                key: None if row.get(key) is None else str(row.get(key))
                if key in columns_to_string
                else row.get(key)
                for key in keys
            }
            for row in normalized
        ]

    return pl.DataFrame(normalized)


def _normalize_scalar(value: object) -> object:
    if isinstance(value, (dict, list)):
        return json.dumps(value, ensure_ascii=False)
    if isinstance(value, Enum):
        return value.value
    if isinstance(value, datetime):
        return value.isoformat()
    return value


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
