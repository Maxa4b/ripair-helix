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

### Frontend
- `VITE_API_URL`
- `VITE_GOOGLE_MAPS_API_KEY`
- `VITE_GOOGLE_MAPS_MAP_ID`

## Execution

### Import entreprises

```bash
php artisan prospecting:import storage/app/private/prospecting/imports/prospects.xlsx --source=annuaire --segment=reparation
```

### Sync Excel

```bash
php artisan prospecting:excel-sync resync --file=storage/app/private/prospecting/prospecting-mirror.xlsx --sheet=Prospection
```

## Notes de securite

- La cle Google Maps JavaScript est publique par nature cote navigateur: elle doit etre restreinte par domaine/referrer dans Google Cloud.
- Aucun identifiant base de donnees ne doit rester dans le frontend.
- Le miroir Excel est operationnel, mais la base Helix reste la source de verite.
