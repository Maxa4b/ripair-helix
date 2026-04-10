# Plan D'Execution Prospection Cartographique

## Phase 1 - Audit et cadrage

1. Confirmer la stack reelle Helix.
2. Verifier les permissions admin et conventions API existantes.
3. Definir le contrat de donnees et la strategie Excel miroir.
4. Corriger la gestion des secrets avant mise en production du module.

## Phase 2 - Backend MVP

1. Creer les migrations `companies`, `company_contact_history`, `excel_sync_jobs`, `import_jobs`.
2. Ajouter modeles Eloquent, resources JSON et controleurs.
3. Ajouter les endpoints prospecting sous API Sanctum.
4. Ajouter la protection de role `owner|manager`.
5. Ajouter la gestion de concurrence via champ `version`.

## Phase 3 - Import et sync

1. Ajouter un service d'import CSV/XLSX avec deduplication.
2. Ajouter une commande artisan d'import batch.
3. Ajouter un service d'export/import Excel miroir.
4. Logger toutes les operations dans `import_jobs` et `excel_sync_jobs`.

## Phase 3 bis - Automatisation sourcing

1. Brancher un sourcing bulk depuis SIRENE plutot qu'une recherche manuelle ligne par ligne.
2. Filtrer les lignes par APE + mots-cles + exclusions retail grand public.
3. Importer directement dans `companies` via une commande CLI idempotente.
4. Geocoder les entreprises adressees mais non cartographiees en batch.
5. Prevoir une execution planifiee via cron ou scheduler VPS quand la volumetrie sera stable.

## Phase 4 - Front admin

1. Ajouter la route `/prospecting` dans l'app React Helix.
2. Ajouter hook API companies/stats/sync/import.
3. Ajouter carte Google Maps avec loader securise par variable d'environnement.
4. Ajouter clustering viewport, legende, filtres et compteurs.
5. Ajouter popup compact et drawer detaille.
6. Ajouter mise a jour optimiste des statuts avec rollback sur `409`/erreur API.

## Phase 5 - Verification

1. Executer migrations.
2. Verifier `npm run build` frontend.
3. Verifier `php artisan test`.
4. Tester import, modification manuelle, export Excel, resync et historique.

## Decoupage Produit

### MVP
- lecture/ecriture des entreprises
- carte + filtres + stats + historique
- import manuel
- export/import Excel manuel

### V2
- sync automatique configurable
- enrichissement contact branche a un provider
- sourcing bulk SIRENE + geocodage batch industrialises
- meilleures zones geographiques et saved views

### V3
- orchestration queue/scheduler
- campagnes multi-utilisateurs
- analytics avancees et pilotage commercial
