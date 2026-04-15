from pathlib import Path

from app.config import PipelineConfig
from app.ingest.pipeline import build_ingest_sql


def test_build_ingest_sql_resolves_camel_case_sirene_headers() -> None:
    sql = build_ingest_sql(
        Path(__file__).parent / "fixtures" / "stock_etablissement_camel_case.csv",
        PipelineConfig(),
    )

    assert '"denominationUniteLegale" AS "raison_sociale"' in sql
    assert '"categorieJuridiqueUniteLegale" AS "forme_juridique"' in sql
    assert 'CONCAT_WS' in sql
