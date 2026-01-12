# Helix

Internal operations platform for RIPAIR.  
Goal: centralize availability planning, appointment management and operational follow-up.

## Status
- ✅ Folder structure created (`backend/`, `frontend/`, `docs/`)
- ✅ Product brief & data model (`docs/01-product-overview.md`, `docs/02-data-model.md`)
- ✅ Technical stack documented (`docs/03-tech-stack.md`)
- ✅ Laravel backend (composer scaffold) & Vite/React frontend (npm scaffold) in place
- ✅ Database migrations & Eloquent models drafted
- ⏳ Next: API contract + UI wireframes, then feature implementation

## Immediate Next Steps
1. **API contract**  
   - Define endpoint list (auth, appointments, availability, dashboard).  
   - Produce an OpenAPI spec to align front/back expectations.
2. **UI wireframes & flows**  
   - Design dashboard, calendar, appointment detail, settings pages.  
   - Validate status transitions, note-taking, and notifications UX.
3. **Backend features**  
   - Implement controllers/routes for appointments, notes, events, availability.  
   - Seed an initial Helix admin user.  
   - Add sync hooks to ingest bookings from the public site.
4. **Frontend features**  
   - Build auth screens + shell layout.  
   - Integrate calendar (FullCalendar) and list views via React Query.  
   - Consume new API endpoints.

## Repo Structure
```
Helix/
├── backend/   # Laravel API
├── frontend/  # React + Vite SPA
└── docs/      # Requirements, specs, architecture
```

## Backend quick start
```bash
cd Helix/backend
composer install                # already done by scaffold; keep for reference
cp .env.example .env            # .env already generated, edit DB_* for ripair
php artisan migrate             # guarded migrations, safe to run multiple times
php artisan serve               # launches http://localhost:8000
```

Useful Artisan commands:
- `php artisan make:migration ...`
- `php artisan make:model ...`
- `php artisan migrate:fresh --seed` *(danger: wipes data)*
- `php artisan tinker`

## Frontend quick start
```bash
cd Helix/frontend
npm install
npm run dev     # Vite dev server (default http://localhost:5173)
npm run build   # production build → dist/
```

## How to use this folder
- Maintain product & technical documentation under `docs/`.
- Keep backend/frontend READMEs in their respective folders with setup details.
- Track tasks (issues, Kanban) referencing the docs + this README roadmap.

## References
- Public website repo: `/site`
- Requirements/docs: `docs/01-product-overview.md`, `docs/02-data-model.md`, `docs/03-tech-stack.md`

