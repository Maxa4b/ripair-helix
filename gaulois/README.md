# Helix × Gaulois

This folder centralises artefacts for the Helix UI around the existing `ripair_import/gaulois.py` script.

- The original script remains untouched inside `../ripair_import/gaulois.py`.
- Laravel bridges the script through the new `/gaulois/*` API endpoints (see `app/Http/Controllers/GauloisController.php`).
- Frontend assets that power the new Gaulois interface live under `frontend/src/` (see the `pages/GauloisPage.tsx` module).

If you need to adjust execution details (Python path, working directory, logs) use the env overrides defined in `config/gaulois.php`.
