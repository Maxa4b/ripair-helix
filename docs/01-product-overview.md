# Helix – Internal Operations Suite

## Vision
Provide a single internal workspace for RIPAIR to plan availability, monitor appointments, and coordinate repair operations without juggling spreadsheets or the public website back-office.

## Primary Objectives
- **Visual availability planning:** open/close time slots through an interactive calendar, manage exceptions (congés, interventions externes).
- **Appointment cockpit:** view upcoming bookings, statuses, customer details, and related notes at a glance.
- **Actionable insights:** highlight urgent tasks (pièces à commander, rendez-vous non confirmés, annulations).
- **Fast workflows:** create/edit bookings manually, drag & drop reorganisations, mark a job as completed or invoiced.

## Users & Needs
| Role | Needs | Typical actions |
| --- | --- | --- |
| Owner / Manager | Global visibility, quick rescheduling, indicators | Manage opening hours, review workload, check KPIs |
| Technician | Daily agenda and task checklist | See today’s repairs, add technical notes, update status |
| Front-desk | Intake & customer communication | Create bookings, confirm arrivals, trigger reminder communications |

## Core Features – MVP Scope
1. **Calendar Board**
   - Week & day views, mobile-friendly.
   - Create availability blocks (open/closed) with recurrence.
   - Drag & drop appointments; conflict detection.
2. **Appointment List & Details**
   - Filter/search by status, service label, date.
   - Detail panel with customer info, quote summary, cancellation link state.
   - Internal notes & status transitions (booked → confirmed → done/cancelled).
3. **Dashboard KPIs**
   - Next appointments (24h), workload for the week, pending confirmations.
4. **Notifications / Hooks**
   - Webhook listener to sync new online bookings.
   - Optional internal alerts (SMS/email) when changes occur inside Helix.

## Nice-to-Have (post-MVP)
- Inventory tracking for parts linked to appointments.
- Payment tracking (SumUp / cash) with export.
- Customer history & loyalty metrics.
- Multi-store support.

## System Architecture (initial sketch)
```
Frontend (React + TypeScript)  -->  Helix API (PHP/Laravel or Slim)
                                    |
                                    --> MySQL (shared with ripair.shop)
                                    --> Notification services (SMTP, Twilio)
```

*Notes:*
- Keep API stateless with JWT or session-based auth (decide in technical design).
- Introduce service layer for calendar logic (availability blocks, recurring patterns).
- Use existing `appointments` table; extend schema (status, assigned_user_id, notes, color, `source`).
- New tables: `users`, `availability_blocks`, `appointment_notes`, `event_log`.

## Open Questions
- Authentication: reuse existing admin accounts or create a dedicated Helix user system?
- Permissions: do techniciens need restricted views (only own appointments)?
- Multi-location: single store now; plan for eventual second site?
- Real-time updates versus periodic refresh (e.g. websockets vs. polling).

## Next Steps
1. Define database changes (ER diagram + migration scripts).
2. Decide backend stack (pure PHP vs. framework) and scaffolding.
3. Build API contract (OpenAPI spec) for calendar, appointments, settings.
4. Prototype UI wireframes (dashboard, calendar, detail drawer).
5. Set up development tooling (Docker containers, npm workspace, lint/format rules).

