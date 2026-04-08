# Plan D'Execution Prospection Cartographique

## Phase 1 - Audit et cadrage

1. Confirmer la stack réelle Helix.
2. Vérifier les permissions admin et conventions API existantes.
3. Définir le contrat de données et la stratégie Excel miroir.
4. Corriger la gestion des secrets avant mise en production du module.

## Phase 2 - Backend MVP

1. Créer les migrations `companies`, `company_contact_history`, `excel_sync_jobs`, `import_jobs`.
2. Ajouter modèles Eloquent, resources JSON et contrôleurs.
3. Ajouter les endpoints prospecting sous API Sanctum.
4. Ajouter la protection de rôle `owner|manager`.
5. Ajouter la gestion de concurrence via champ `version`.

## Phase 3 - Import et sync

1. Ajouter un service d'import CSV/XLSX avec déduplication.
2. Ajouter une commande artisan d'import batch.
3. Ajouter un service d'export/import Excel miroir.
4. Logger toutes les opérations dans `import_jobs` et `excel_sync_jobs`.

## Phase 4 - Front admin

1. Ajouter la route `/prospecting` dans l'app React Helix.
2. Ajouter hook API companies/stats/sync/import.
3. Ajouter carte Google Maps avec loader sécurisé par variable d'environnement.
4. Ajouter clustering viewport, légende, filtres et compteurs.
5. Ajouter popup compact et drawer détaillé.
6. Ajouter mise à jour optimiste des statuts avec rollback sur `409`/erreur API.

## Phase 5 - Vérification

1. Exécuter migrations.
2. Vérifier `npm run build` frontend.
3. Vérifier `php artisan test`.
4. Tester import, modification manuelle, export Excel, resync et historique.

## Découpage Produit

### MVP
- lecture/écriture des entreprises
- carte + filtres + stats + historique
- import manuel
- export/import Excel manuel

### V2
- sync automatique configurable
- enrichissement contact branché à un provider
- meilleures zones géographiques et saved views

### V3
- orchestration queue/scheduler
- campagnes multi-utilisateurs
- analytics avancées et pilotage commercial
