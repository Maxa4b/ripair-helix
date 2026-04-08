# Prospection Cartographique Helix

## 1. Audit Technique Helix

### Front admin
- Framework: React 19 + TypeScript + Vite dans `Helix/frontend`.
- Routage: `react-router-dom` avec SPA protégée par `RequireAuth`.
- Data fetching: `@tanstack/react-query` + `axios`.
- UI: composants maison majoritairement en styles inline, avec MUI disponible mais peu structurant aujourd'hui.

### Backend admin
- Stack: Laravel 12 / PHP 8.2 dans `Helix/backend`.
- API: routes JSON dans `routes/api.php`, protégées par `auth:sanctum`.
- ORM: Eloquent.
- Base: config multi-connexions, MySQL attendu en production, SQLite possible en local/tests.
- Queue: driver `database` configuré, tables Laravel de jobs présentes.
- Cache/session: drivers base de données par défaut, Redis également configurable.

### Auth admin
- Authentification par jeton Sanctum.
- Utilisateur admin: modèle `App\Models\HelixUser`.
- Rôles existants: `owner`, `manager`, `technician`, `frontdesk`.
- Observation: les contrôles de rôle existent au cas par cas, pas via policy centralisée.

### Variables d'environnement
- Backend: `.env` Laravel standard, correct pour les secrets serveurs.
- Frontend: `import.meta.env` via Vite.
- Point critique: un `.env` frontend versionné contient des identifiants non destinés au client. Ils doivent sortir du front et être régénérés côté serveur.

### Build / lint / tests / déploiement
- Front: `npm run build`, `npm run lint`.
- Back: `php artisan test`, `laravel/pint`, build assets Vite côté backend.
- Déploiement: GitHub Actions vers VPS via `Helix/.github/workflows/deploy-vps.yml`.
- Processus relevé: `composer install`, caches Laravel, `npm ci`, `npm run build`.

### Cron / workers / infra asynchrone
- `queue:listen` prévu en dev et `queue:restart` pendant le déploiement.
- Aucun cron métier Helix déclaré dans `routes/console.php` à ce stade.
- Conclusion: l'infra queue existe, le scheduler Laravel n'est pas encore exploité.

## 2. Contraintes Structurantes

- La source de vérité est la base Helix, jamais Excel.
- Excel sert de miroir import/export et d'outil opérationnel, pas de stockage primaire.
- L'affichage doit rester fluide avec 10k+ entreprises, donc:
  - chargement par viewport,
  - filtres côté backend,
  - clustering,
  - pas de rendu HTML brut de 10k marqueurs simultanés.
- Les statuts doivent être historisés et protégés contre les écritures concurrentes.

## 3. Architecture Recommandée

### Domaine backend
- Nouveau sous-domaine fonctionnel `Prospecting`.
- Tables minimales:
  - `companies`
  - `company_contact_history`
  - `excel_sync_jobs`
  - `import_jobs`
- Source vérité:
  - `companies.contact_status`
  - `companies.version`
  - `company_contact_history`

### API Helix
- Convention réelle Helix retenue: endpoints sous `/api/prospecting/...`.
- Équivalents du besoin:
  - `GET /api/prospecting/companies`
  - `GET /api/prospecting/companies/{company}`
  - `PATCH /api/prospecting/companies/{company}/status`
  - `PATCH /api/prospecting/companies/{company}`
  - `POST /api/prospecting/import/companies`
  - `POST /api/prospecting/sync/excel`
  - `GET /api/prospecting/stats`

### UI admin
- Nouvelle page React `/prospecting`.
- Layout:
  - panneau latéral filtres + compteurs,
  - carte dominante,
  - popup rapide au clic,
  - drawer latéral détaillé.
- Stratégie Google Maps:
  - loader natif Google Maps JS API,
  - `AdvancedMarkerElement` HTML custom,
  - regroupement des points visibles,
  - refetch au changement de bounds / zoom / filtres.

### Pipeline données
- Import initial:
  - fichier CSV ou XLSX source,
  - déduplication par `siret`, puis `siren + name + postal_code`, puis `name + address`.
- Enrichissement:
  - si email/téléphone absents, champs laissés à `null`,
  - score de pertinence calculé et persistant,
  - géolocalisation stockée en base.
- Sync Excel:
  - export DB -> classeur miroir,
  - import contrôlé Excel -> DB pour réintégrer les changements autorisés,
  - logs dans `excel_sync_jobs`.

## 4. Schéma Données

### companies
- Identité: `company_id`, `name`, `siren`, `siret`, `source`, `segment`
- Contact: `website`, `email`, `phone`, `contact_owner`, `notes`
- Adresse: `address`, `postal_code`, `city`, `country`
- Géo: `lat`, `lng`, `google_place_id`
- Qualification: `relevance_score`
- Prospecting: `contact_status`, `first_contact_at`, `last_contact_at`
- Sync: `excel_row_id`
- Concurrence: `version`
- Audit: `created_at`, `updated_at`

### company_contact_history
- Référence entreprise
- ancien / nouveau statut
- ancien / nouveau owner
- note de changement
- source (`ui`, `excel_sync`, `import`)
- acteur
- horodatage

### excel_sync_jobs
- type (`import`, `export`, `resync`)
- statut (`pending`, `running`, `success`, `failed`)
- chemin fichier
- compteurs
- payload / erreurs
- timestamps

### import_jobs
- source / fichier / segment forcé
- statut
- compteurs importés / dédupliqués / enrichis / rejetés
- erreurs
- timestamps

## 5. Performance 10k+

- Query bornée par viewport `(south, west, north, east)`.
- Limite dure côté API pour éviter les charges anormales.
- Index sur `contact_status`, `segment`, `city`, `postal_code`, `siren`, `siret`, `lat`, `lng`.
- Marqueurs calculés uniquement pour les points visibles.
- Clustering en grille côté client sur le sous-ensemble viewport.
- Mise à jour d'un statut sans refetch global forcé.
- Liste latérale paginée localement sur le dataset chargé.

## 6. Sécurité

- Aucun secret Google côté code source.
- Nouvelle variable serveur prévue: `GOOGLE_MAPS_API_KEY`.
- Contrôle d'accès:
  - lecture/écriture prospection limitées à `owner` et `manager`.
- Historisation obligatoire des changements de statut.
- Protection concurrence:
  - `version` demandé sur les `PATCH`,
  - retour `409` si conflit.

## 7. Livraison

### MVP
- tables + API + import DB
- carte Google + viewport loading + popup + drawer
- filtres + stats + changement de statut
- export/import Excel miroir

### V2
- sync automatique planifiable
- géocodage/enrichissement branché à un provider réel
- vues de segment plus riches

### V3
- ownership avancé
- scoring automatique
- reporting campagne / SLA / relances
