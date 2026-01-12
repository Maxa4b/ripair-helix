# Helix – Technical Stack Proposal (v0.1)

## Goals
- Ship a modern, maintainable internal tool with fast iteration.
- Reuse existing PHP ecosystem knowledge.
- Provide a smooth developer experience (DX) with hot reload and clear migrations.

---

## Backend

| Choice | Rationale |
| --- | --- |
| **PHP 8.2+** | Aligns with current stack, easy hosting alongside existing site. |
| **Laravel 11** | Provides authentication scaffolding, Eloquent ORM, migrations, queues, scheduling; large ecosystem. |
| **Composer** | Dependency management. |
| **MySQL 8 / MariaDB 10.x** | Existing database. Uses new `helix_*` tables defined in `02-data-model.md`. |
| **Sanctum** (token-based auth) | Simple SPA authentication (cookie or token). |
| **Pest** (tests) | Fast, expressive testing framework for Laravel. |

**Project structure (backend)**
```
backend/
├── app/                # Controllers, Services, Models
├── database/
│   ├── migrations/     # sync with SQL already executed via phpMyAdmin
│   ├── seeders/
│   └── factories/
├── routes/
│   └── api.php         # REST endpoints
└── tests/
    └── Feature/
```

**Key packages**
- `laravel/sanctum` for SPA auth.
- `spatie/laravel-activitylog` (optional) to mirror appointment events.
- `laravel/telescope` (optional) for debugging.

**Core API endpoints (MVP)**
- `POST /auth/login`, `POST /auth/logout`, `GET /auth/me`.
- `GET /appointments`, `POST /appointments`, `PATCH /appointments/{id}`, `DELETE /appointments/{id}`.
- `GET /appointments/{id}/notes`, `POST /appointments/{id}/notes`.
- `GET /availability-blocks`, `POST /availability-blocks`.
- `GET /dashboard/summary` (KPIs).

---

## Frontend

| Choice | Rationale |
| --- | --- |
| **React 18 + TypeScript** | Widely adopted, component-based, easy integration with calendar libraries. |
| **Vite** | Fast dev server and build tool. |
| **TanStack Query** | Data fetching & caching for API interactions. |
| **React Router** | Page layout + navigation. |
| **MUI (Material UI)** | Ready-made components, theming. Alternative: Ant Design. |
| **FullCalendar** | Feature-rich calendar (drag/drop, resource view). |
| **ESLint + Prettier** | Code quality & formatting. |

**Project structure (frontend)**
```
frontend/
├── src/
│   ├── api/           # axios clients, hooks
│   ├── components/
│   ├── features/
│   │   ├── calendar/
│   │   ├── appointments/
│   │   └── dashboard/
│   ├── hooks/
│   ├── pages/
│   ├── routes/
│   └── theme/
├── public/
└── tests/
    └── e2e/ (Cypress or Playwright)
```

**Auth flow**
- SPA served from `frontend` (Vite build → static assets).
- Uses Sanctum cookie-based auth or token stored in secure storage.
- `axios` (with interceptors) to handle 401 and refresh.

---

## Tooling & Dev Environment

- **Docker Compose** (optionnel) : containers `app`, `db`, `npm` pour un onboarding rapide.
- **Makefile / npm scripts** : `composer install`, `php artisan migrate`, `npm install`, `npm run dev`.
- **.env management** : `.env.example` pour backend + frontend (API base URL).
- **Git workflow** : branch `helix/main`, PRs pour features, GitHub Actions (CI) plus tard.

---

## Initial Setup Tasks

1. **Backend scaffolding**
   - `composer create-project laravel/laravel backend`
   - Ajouter les migrations correspondant aux tables `helix_*` (keep in sync with existing DB).
   - Implémenter modèles Eloquent (`HelixUser`, `Appointment`, `AppointmentNote`, etc.).
   - Seed minimal admin user.
2. **Auth & middlewares**
   - Configurer Sanctum (SPA mode).
   - Protéger routes API par middleware `auth:sanctum`.
3. **API skeleton**
   - CRUD endpoints basiques pour appointments + availability blocks.
   - Transformer les requêtes existantes (`book.php`) pour respecter nouveaux champs (future integration).
4. **Frontend bootstrapping**
   - `npm create vite@latest frontend -- --template react-ts`
   - Installer `@mui/material`, `@emotion/react`, `@emotion/styled`, `@fullcalendar/react`, `@fullcalendar/timegrid`, `axios`, `@tanstack/react-query`.
   - Créer structure de pages : `Login`, `Dashboard`, `Calendar`, `Appointments`.
5. **Integration**
   - Mettre en place `.env` : `VITE_API_URL=http://localhost:8000/api`.
   - Configurer axios + React Query (provider global).
6. **Quality**
   - ESLint + Prettier configs.
   - Tests initiaux (Pest pour backend, Vitest/Cypress pour frontend).

---

## Roadmap (High-level)

1. **Foundation**
   - Backend + Frontend scaffolding
   - Auth & user management
   - Data access endpoints
2. **Calendar MVP**
   - Display appointments & availability
   - Drag & drop updates
   - Manual booking creation
3. **Operational features**
   - Notes, status management
   - Notifications (email/SMS) from Helix actions
   - Dashboard metrics
4. **Enhancements**
   - Inventory link, payment tracking
   - Multi-store support
   - Reporting exports

---

## Open Decisions
- Whether the frontend is served statically (e.g. Nginx) or via Laravel’s `public/` (single origin). SPA mode + API on same domain simplifies cookies.
- Use of real-time updates (Laravel Echo + Pusher or polling).
- Hosting environment (shared hosting vs. dedicated VPS with Docker).

Once approved, next steps will be to scaffold the Laravel app, port migrations, and set up the Vite/React project in the `frontend/` directory.

