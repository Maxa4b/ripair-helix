# Checklist De Tests Prospection

## API

- `GET /api/prospecting/companies` retourne uniquement les entreprises du viewport filtre.
- `GET /api/prospecting/companies/{id}` retourne l'historique recent.
- `PATCH /api/prospecting/companies/{id}/status` refuse une version obsolete avec `409`.
- `PATCH /api/prospecting/companies/{id}` persiste owner et notes.
- `GET /api/prospecting/stats` calcule les compteurs attendus.

## Import

- Import CSV simple avec `name`, `city`, `segment`.
- Import XLSX avec `siret` et deduplication correcte.
- Lignes invalides journalisees dans `import_jobs`.
- Les entreprises sans email/telephone restent importees avec valeurs `null`.

## Excel

- Export miroir cree un fichier ouvrable dans Excel.
- Modification du statut dans le miroir puis `resync` remonte bien en base.
- Import Excel stale avec `version` obsolete ne remplace pas l'etat courant.
- `excel_sync_jobs` journalise succes, volumes et erreurs.

## UI

- La carte charge sans cle hardcodee.
- Les filtres mettent a jour le viewport sans recharger toute la page.
- Le clic sur un point ouvre la carte compacte.
- Le drawer detaille permet de modifier owner et notes.
- Les boutons copier email/telephone fonctionnent.
- La legende rouge/bleu/vert est visible.
- La liste laterale pagine les resultats courants.

## Performance

- Les 10k entreprises ne sont jamais rendues comme 10k marqueurs HTML simultanes.
- Le changement d'un statut ne force pas un rerender complet manuel.
- Les requetes API restent bornees par `bounds` et `limit`.

## Securite

- Les endpoints prospection refusent les utilisateurs non `owner`/`manager`.
- Les changements de statut creent une entree `company_contact_history`.
- Aucun secret back ne fuite dans le bundle frontend.
