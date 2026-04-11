# Installation Prospection Cartographique

## Backend

1. Se placer dans `Helix/backend`.
2. Installer les dependances PHP si necessaire.
3. Renseigner le `.env` backend.
4. Executer les migrations:

```bash
php artisan migrate
```

## Frontend

1. Se placer dans `Helix/frontend`.
2. Installer les dependances Node si necessaire.
3. Copier `.env.example` vers `.env` puis renseigner les variables.
4. Construire l'admin:

```bash
npm run build
```

## Variables d'environnement

### Backend
- `PROSPECTING_EXCEL_PATH`
- `PROSPECTING_EXCEL_SHEET`
- `PROSPECTING_SIRENE_STOCK_URL`
- `PROSPECTING_SIRENE_MIN_SCORE`
- `PROSPECTING_SIRENE_NAF_PREFIXES`
- `PROSPECTING_SIRENE_INCLUDE_KEYWORDS`
- `PROSPECTING_SIRENE_EXCLUDE_KEYWORDS`
- `PROSPECTING_GEOCODER_URL`
- `PROSPECTING_GEOCODER_MIN_SCORE`
- `PROSPECTING_GOOGLE_PLACES_API_KEY`
- `PROSPECTING_GOOGLE_PLACES_SEARCH_ENDPOINT`
- `PROSPECTING_GOOGLE_PLACES_DETAILS_BASE`

### Frontend
- `VITE_API_URL`
- `VITE_GOOGLE_MAPS_API_KEY`
- `VITE_GOOGLE_MAPS_MAP_ID`

## Execution

### Import entreprises

```bash
php artisan prospecting:import storage/app/private/prospecting/imports/prospects.xlsx --source=annuaire --segment=reparation
```

### Import automatique SIRENE

Import depuis un fichier CSV local:

```bash
php artisan prospecting:auto-import storage/app/private/prospecting/imports/StockEtablissement_utf8.csv --limit=10000 --min-score=55
```

Import depuis une URL de stock configuree dans `.env`:

```bash
php artisan prospecting:auto-import --limit=10000 --departments=31,38,69
```

### Geocodage batch

```bash
php artisan prospecting:geocode-missing --source=sirene_auto --limit=1000
```

### Enrichissement contact batch

Necessite une cle serveur Google Places distincte de la cle front Maps JavaScript.

```bash
php artisan prospecting:enrich-contacts --source=sirene_auto --limit=250
```

### Sync Excel

```bash
php artisan prospecting:excel-sync resync --file=storage/app/private/prospecting/prospecting-mirror.xlsx --sheet=Prospection
```

## Notes de securite

- La cle Google Maps JavaScript est publique par nature cote navigateur: elle doit etre restreinte par domaine/referrer dans Google Cloud.
- La cle `PROSPECTING_GOOGLE_PLACES_API_KEY` doit rester cote backend uniquement et etre restreinte a Places API.
- Aucun identifiant base de donnees ne doit rester dans le frontend.
- Le miroir Excel est operationnel, mais la base Helix reste la source de verite.
- Pour viser 10k+ entreprises, preferer un stock SIRENE bulk + geocodage batch plutot qu'une generation manuelle via LLM.
