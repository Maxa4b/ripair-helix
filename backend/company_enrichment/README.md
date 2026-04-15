# Helix Company Enrichment

Pipeline Python orienté gros volume pour transformer un relevé SIRENE massif en dataset de contacts email professionnels traçables.

## Objectif

Le pipeline exécute trois phases distinctes :

1. `ingest` : lecture d'un CSV/Parquet massif, filtrage métier, scoring initial, sortie compacte en Parquet.
2. `resolve-domains` + `crawl` : découverte de domaines candidats, validation prudente, crawl limité des pages utiles.
3. `score-emails` + `export-final` : extraction, validation, scoring, déduplication et export final.

Règles strictes :

- aucun email inventé comme `verified`
- séparation `observed` / `generated` / `rejected`
- domaines ambigus conservés en `uncertain`
- chaque résultat exporté garde source, score, type et statut

## Architecture

```text
company_enrichment/
├── app/
│   ├── cli.py
│   ├── config.py
│   ├── models.py
│   ├── ingest/
│   ├── domain_resolution/
│   ├── crawl/
│   ├── extract/
│   ├── scoring/
│   ├── export/
│   └── utils/
├── tests/
│   └── fixtures/html/
├── config.example.yaml
└── pyproject.toml
```

## Installation

```bash
cd Helix/backend/company_enrichment
python -m venv .venv
. .venv/bin/activate
pip install -e .[dev]
```

Sous Windows PowerShell :

```powershell
cd Helix\backend\company_enrichment
python -m venv .venv
.venv\Scripts\Activate.ps1
pip install -e .[dev]
```

## Commandes

```bash
python -m app ingest --input path/to/sirene.csv --output out/targets.parquet --config config.yaml
python -m app resolve-domains --input out/targets.parquet --output out/domains.parquet --config config.yaml
python -m app crawl --input out/domains.parquet --output out/crawl_results.parquet --config config.yaml
python -m app score-emails --input out/crawl_results.parquet --output out/email_candidates.parquet --config config.yaml
python -m app export-final --targets out/targets.parquet --domains out/domains.parquet --emails out/email_candidates.parquet --output out/final_contacts.csv --config config.yaml
python -m app run-all --input path/to/sirene.csv --output-dir out --config config.yaml
```

## Limites connues

- la découverte de domaine entièrement externe dépend d'un provider à brancher
- sans provider de recherche, la résolution s'appuie surtout sur une colonne `website` ou un fichier de seeds
- le crawler reste volontairement limité par domaine pour privilégier précision, coût et reprise
