from __future__ import annotations

import csv
import logging
from pathlib import Path

import duckdb
import polars as pl

from app.config import PipelineConfig
from app.utils.logging_utils import log_phase

LOGGER = logging.getLogger(__name__)

SOURCE_COLUMN_ALIASES: dict[str, list[str]] = {
    "siren": ["siren"],
    "siret": ["siret"],
    "raison_sociale": [
        "denominationUniteLegale",
        "denominationunitelegale",
        "nomUniteLegale",
        "nomunitelegale",
    ],
    "enseigne": [
        "enseigne1Etablissement",
        "enseigne1etablissement",
        "nomCommercialEtablissement",
        "nomcommercialetablissement",
        "sigleUniteLegale",
        "sigleunitelegale",
    ],
    "forme_juridique": [
        "categorieJuridiqueUniteLegale",
        "categoriejuridiqueunitelegale",
    ],
    "naf": [
        "activitePrincipaleEtablissement",
        "activiteprincipaleetablissement",
        "activitePrincipaleUniteLegale",
        "activiteprincipaleunitelegale",
    ],
    "adresse": [
        "adresseEtablissement",
        "adresseetablissement",
    ],
    "code_postal": [
        "codePostalEtablissement",
        "codepostaletablissement",
    ],
    "ville": [
        "libelleCommuneEtablissement",
        "libellecommuneetablissement",
        "communeEtablissement",
        "communeetablissement",
    ],
    "region": [
        "libelleRegionEtablissement",
        "libelleregionetablissement",
        "region",
        "codeRegionEtablissement",
        "coderegionetablissement",
    ],
    "pays": [
        "libellePaysEtrangerEtablissement",
        "libellepaysetrangeretablissement",
        "paysEtrangerEtablissement",
        "paysetrangeretablissement",
    ],
    "est_siege": [
        "etablissementSiege",
        "etablissementsiege",
    ],
    "statut_administratif": [
        "etatAdministratifEtablissement",
        "etatadministratifetablissement",
    ],
    "tranche_effectif": [
        "trancheEffectifsEtablissement",
        "trancheeffectifsetablissement",
        "trancheEffectifsUniteLegale",
        "trancheeffectifsunitelegale",
    ],
    "website": [
        "website",
        "siteWeb",
        "siteweb",
        "url",
    ],
}

ADDRESS_COMPONENT_ALIASES = [
    ["numeroVoieEtablissement", "numerovoieetablissement"],
    ["indiceRepetitionEtablissement", "indicerepetitionetablissement"],
    ["typeVoieEtablissement", "typevoieetablissement"],
    ["libelleVoieEtablissement", "libellevoieetablissement"],
    ["complementAdresseEtablissement", "complementadresseetablissement"],
    ["distributionSpecialeEtablissement", "distributionspecialeetablissement"],
    ["distributionSpeciale2Etablissement", "distributionspeciale2etablissement"],
]


def _sql_escape(value: str) -> str:
    return value.replace("'", "''")


def _normalize_column_key(value: str) -> str:
    return "".join(character for character in value.lower() if character.isalnum())


def _read_source_headers(input_path: Path, config: PipelineConfig) -> list[str]:
    if input_path.suffix.lower() == ".parquet" or config.source.format.lower() == "parquet":
        frame = duckdb.connect().execute(f"DESCRIBE SELECT * FROM read_parquet('{input_path.as_posix()}')").fetchall()
        return [str(row[0]) for row in frame]

    delimiter = config.source.csv.delimiter
    with input_path.open("r", encoding=config.source.csv.encoding, newline="") as handle:
        sample = handle.read(64_000)
        handle.seek(0)

        if delimiter == "auto":
            try:
                dialect = csv.Sniffer().sniff(sample, delimiters=[",", ";", "\t", "|"])
                delimiter = dialect.delimiter
            except csv.Error:
                delimiter = ","

        reader = csv.reader(handle, delimiter=delimiter, quotechar=config.source.csv.quotechar)
        return next(reader, [])


def _resolved_mapping(input_path: Path, config: PipelineConfig) -> dict[str, str]:
    mapping = dict(config.source.source_columns)
    source_headers = _read_source_headers(input_path, config)
    normalized_headers = {_normalize_column_key(column): column for column in source_headers}

    resolved: dict[str, str] = {}
    all_targets = set(mapping.keys()) | set(SOURCE_COLUMN_ALIASES.keys())
    for target in all_targets:
        configured_name = mapping.get(target)
        candidates = [candidate for candidate in [configured_name, *SOURCE_COLUMN_ALIASES.get(target, [])] if candidate]
        for candidate in candidates:
            matched = normalized_headers.get(_normalize_column_key(candidate))
            if matched:
                resolved[target] = matched
                break

    return resolved


def _mapped_expression(mapping: dict[str, str], target: str, default_sql: str = "NULL") -> str:
    source_column = mapping.get(target)
    return f'"{source_column}" AS "{target}"' if source_column else f'{default_sql} AS "{target}"'


def _resolve_address_expression(mapping: dict[str, str], source_headers: list[str]) -> str:
    address_column = mapping.get("adresse")
    if address_column:
        return f'NULLIF(TRIM("{address_column}"), \'\')'

    parts: list[str] = []
    normalized_headers = {_normalize_column_key(value): value for value in source_headers}
    for aliases in ADDRESS_COMPONENT_ALIASES:
        matched = next((normalized_headers.get(_normalize_column_key(alias)) for alias in aliases if normalized_headers.get(_normalize_column_key(alias))), None)
        if matched:
            parts.append(f'NULLIF(TRIM("{matched}"), \'\')')

    if not parts:
        return "NULL"

    return "NULLIF(TRIM(CONCAT_WS(' ', " + ", ".join(parts) + ")), '')"


def _startswith_any(column_sql: str, prefixes: list[str]) -> str:
    if not prefixes:
        return "TRUE"
    clauses = [f"{column_sql} LIKE '{_sql_escape(prefix)}%%'" for prefix in prefixes]
    return "(" + " OR ".join(clauses) + ")"


def _not_startswith_any(column_sql: str, prefixes: list[str]) -> str:
    if not prefixes:
        return "TRUE"
    clauses = [f"{column_sql} NOT LIKE '{_sql_escape(prefix)}%%'" for prefix in prefixes]
    return "(" + " AND ".join(clauses) + ")"


def _in_list(column_sql: str, values: list[str]) -> str:
    if not values:
        return "TRUE"
    quoted = ", ".join(f"'{_sql_escape(value)}'" for value in values)
    return f"{column_sql} IN ({quoted})"


def _not_in_list(column_sql: str, values: list[str]) -> str:
    if not values:
        return "TRUE"
    quoted = ", ".join(f"'{_sql_escape(value)}'" for value in values)
    return f"COALESCE({column_sql}, '') NOT IN ({quoted})"


def _build_source_relation(input_path: Path, config: PipelineConfig) -> str:
    if input_path.suffix.lower() == ".parquet" or config.source.format.lower() == "parquet":
        return f"read_parquet('{input_path.as_posix()}')"
    csv = config.source.csv
    return (
        f"read_csv_auto('{input_path.as_posix()}', delim='{csv.delimiter}', quote='{csv.quotechar}', "
        f"header={str(csv.header).upper()}, encoding='{csv.encoding}', sample_size={csv.sample_size}, all_varchar=true)"
    )


def build_ingest_sql(input_path: Path, config: PipelineConfig) -> str:
    mapping = _resolved_mapping(input_path, config)
    source_headers = _read_source_headers(input_path, config)
    filters = config.filters
    weights = config.priority_weights
    relation = _build_source_relation(input_path, config)

    status_col = f'COALESCE("{mapping.get("statut_administratif", "statut_administratif")}", \'\')'
    naf_col = f'COALESCE("{mapping.get("naf", "naf")}", \'\')'
    legal_form_col = f'COALESCE("{mapping.get("forme_juridique", "forme_juridique")}", \'\')'
    country_col = f'COALESCE("{mapping.get("pays", "pays")}", \'\')'
    city_col = f'COALESCE("{mapping.get("ville", "ville")}", \'\')'
    region_col = f'COALESCE("{mapping.get("region", "region")}", \'\')'
    est_siege_col = f'COALESCE("{mapping.get("est_siege", "est_siege")}", \'\')'
    tranche_col = f'COALESCE("{mapping.get("tranche_effectif", "tranche_effectif")}", \'\')'
    department_col = f'COALESCE(SUBSTR("{mapping.get("code_postal", "code_postal")}", 1, 2), \'\')'
    siren_col = f'COALESCE("{mapping.get("siren", "siren")}", \'\')'
    row_id_col = mapping.get("source_row_id")
    row_id_sql = f'"{row_id_col}"' if row_id_col else "row_number() OVER ()"
    website_col = mapping.get("website")
    website_sql = f'NULLIF(TRIM("{website_col}"), \'\')' if website_col else "NULL"
    min_effectif = filters.min_effectif if filters.min_effectif is not None else None

    score_sql = f"""
        (
            CASE WHEN {_startswith_any(naf_col, filters.include_naf_prefixes)} THEN {weights.naf_match} ELSE 0 END +
            CASE WHEN {"TRY_CAST(NULLIF(" + tranche_col + ", '') AS INTEGER) >= " + str(min_effectif) if min_effectif is not None else "FALSE"} THEN {weights.effectif_match} ELSE 0 END +
            CASE WHEN {_in_list(legal_form_col, filters.include_legal_forms) if filters.include_legal_forms else "FALSE"} THEN {weights.legal_form_match} ELSE 0 END +
            CASE WHEN {website_sql} IS NOT NULL THEN {weights.known_domain_hint} ELSE 0 END +
            CASE WHEN {legal_form_col} IN ('1000', '5499', '0000') THEN {weights.individual_like_penalty} ELSE 0 END +
            CASE WHEN {status_col} IN ('F', 'C') THEN {weights.closed_penalty} ELSE 0 END
        ) AS "score_priorite_initial"
    """

    where_parts = [
        "TRUE",
        f"NULLIF(TRIM({siren_col}), '') IS NOT NULL" if "siren" in mapping else "TRUE",
    ]
    if filters.active_only:
        where_parts.append(_not_in_list(status_col, filters.excluded_statuses or ["F", "C"]))
    if filters.headquarters_only:
        where_parts.append(f"{est_siege_col} IN ('true', 'TRUE', '1', 'oui', 'O', 'S')")
    if filters.include_naf_prefixes:
        where_parts.append(_startswith_any(naf_col, filters.include_naf_prefixes))
    if filters.exclude_naf_prefixes:
        where_parts.append(_not_startswith_any(naf_col, filters.exclude_naf_prefixes))
    if filters.include_legal_forms:
        where_parts.append(_in_list(legal_form_col, filters.include_legal_forms))
    if filters.exclude_legal_forms:
        where_parts.append(_not_in_list(legal_form_col, filters.exclude_legal_forms))
    if filters.countries:
        where_parts.append(_in_list(country_col, filters.countries))
    if filters.cities:
        where_parts.append(_in_list(city_col, filters.cities))
    if filters.regions:
        where_parts.append(_in_list(region_col, filters.regions))
    if filters.departments:
        where_parts.append(_in_list(department_col, filters.departments))

    select_parts = [
        _mapped_expression(mapping, "siren"),
        _mapped_expression(mapping, "siret"),
        _mapped_expression(mapping, "raison_sociale"),
        _mapped_expression(mapping, "enseigne"),
        _mapped_expression(mapping, "forme_juridique"),
        _mapped_expression(mapping, "naf"),
        f'{_resolve_address_expression(mapping, source_headers)} AS "adresse"',
        _mapped_expression(mapping, "code_postal"),
        _mapped_expression(mapping, "ville"),
        _mapped_expression(mapping, "pays", "''"),
        f'CASE WHEN {est_siege_col} IN (\'true\', \'TRUE\', \'1\', \'oui\', \'O\', \'S\') THEN TRUE ELSE FALSE END AS "est_siege"',
        _mapped_expression(mapping, "statut_administratif"),
        _mapped_expression(mapping, "tranche_effectif"),
        score_sql,
        f'{row_id_sql} AS "source_row_id"',
        f'{website_sql} AS "website"',
    ]

    return f"""
        SELECT
            {", ".join(select_parts)}
        FROM {relation}
        WHERE {" AND ".join(where_parts)}
    """


def ingest_targets(input_path: str | Path, output_path: str | Path, config: PipelineConfig) -> pl.DataFrame:
    input_file = Path(input_path)
    output_file = Path(output_path)
    output_file.parent.mkdir(parents=True, exist_ok=True)

    with log_phase(LOGGER, "ingest"):
        sql = build_ingest_sql(input_file, config)
        connection = duckdb.connect()
        connection.execute(f"COPY ({sql}) TO '{output_file.as_posix()}' (FORMAT PARQUET)")
        frame = pl.read_parquet(output_file)
        LOGGER.info("ingest_completed", extra={"phase": "ingest", "count": frame.height, "path": output_file.as_posix()})
        return frame
