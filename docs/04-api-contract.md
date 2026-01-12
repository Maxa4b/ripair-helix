# Helix – API Contract (Draft v0.1)

This document defines the REST endpoints for the Helix back-office. It targets a SPA (React) consuming a JSON API served by Laravel.

Base URL (local dev): `http://localhost:8000/api`

Authentication: Sanctum cookie-based (SPA mode). All non-auth routes are prefixed by `/auth`.

---

## 1. Authentication

### POST `/auth/login`
- **Request body**
  ```json
  {
    "email": "admin@ripair.shop",
    "password": "secret"
  }
  ```
- **Response 200**
  ```json
  {
    "user": {
      "id": 1,
      "first_name": "Maxence",
      "last_name": "Cordeau",
      "email": "admin@ripair.shop",
      "role": "owner"
    },
    "token": "optional_if_using_token"
  }
  ```
  - If SPA uses Sanctum cookies, `token` can be omitted.
- **Errors**
  - `401` invalid credentials
  - `423` account disabled (`is_active = 0`)

### POST `/auth/logout`
- Invalidates session/token.
- **Response 204** (empty body).

### GET `/auth/me`
- Returns the currently authenticated user profile.
- **Response 200** same shape as `user` above.

---

## 2. Users (Admin-only)

### GET `/users`
- Query params: `role`, `is_active`, pagination.
- Response includes minimal staff details (without password hash).

### POST `/users`
```json
{
  "first_name": "Alex",
  "last_name": "Dupont",
  "email": "alex@ripair.shop",
  "role": "technician",
  "phone": "+33600000000",
  "color": "#1789FC",
  "password": "Secret123!"
}
```
- Password stored hashed; optional random password generator + email invite.

### PATCH `/users/{id}`
- Updatable fields: `first_name`, `last_name`, `role`, `phone`, `color`, `is_active`, password reset.

### DELETE `/users/{id}`
- Soft-disable recommended (set `is_active = false`).

---

## 3. Appointments

### GET `/appointments`
- Query parameters:
  - `start`, `end` (ISO timestamps) – **required** for calendar view.
  - `status[]`, `assigned_user_id`, `store_code`, `search` (name/email/phone).
  - `page`, `per_page` (for list mode).
- **Response 200**
  ```json
  {
    "data": [
      {
        "id": 123,
        "service_label": "iPhone 13 Pro Max - Batterie",
        "start_datetime": "2025-11-05T15:00:00+01:00",
        "end_datetime": "2025-11-05T16:00:00+01:00",
        "status": "booked",
        "assigned_user_id": 2,
        "customer_name": "Jean Dupont",
        "customer_phone": "0612345678",
        "customer_email": "jean.dupont@example.com",
        "price_estimate_cents": 6210,
        "discount_pct": 10,
        "source": "web",
        "store_code": "CESTAS",
        "can_cancel": true
      }
    ],
    "meta": {
      "range_start": "2025-11-01T00:00:00+01:00",
      "range_end": "2025-11-07T23:59:59+01:00",
      "count": 42
    }
  }
  ```

### GET `/appointments/{id}`
- Returns full details: quote info, notes, events timeline.

### POST `/appointments`
- Allows manual booking from Helix.
- **Request**
  ```json
  {
    "service_label": "MacBook Pro 14” - Remplacement clavier",
    "start_datetime": "2025-11-10T09:00:00+01:00",
    "duration_min": 120,
    "customer": {
      "name": "Marie Curie",
      "email": "marie@example.com",
      "phone": "0699887766",
      "address": "12 rue du Général, Bordeaux"
    },
    "price_estimate_cents": 12900,
    "discount_pct": 0,
    "assigned_user_id": 3,
    "notes": "Client souhaitera un prêt de téléphone."
  }
  ```
- **Response 201** -> created appointment with `id`.
- Triggers optional email/SMS confirm.

### PATCH `/appointments/{id}`
- Allowed updates: `start/end`, `status`, `assigned_user_id`, `internal_notes`, `price_estimate_cents`, `discount_pct`, `store_code`, `meta`.
- Status transitions validated server-side (e.g., `booked → confirmed → in_progress → done` or `cancelled/no_show`).

### DELETE `/appointments/{id}`
- Cancels & archives (set status `cancelled`, record event). Real delete only if necessary.

---

## 4. Appointment Notes

### GET `/appointments/{id}/notes`
- Returns notes sorted descending by `created_at`.

### POST `/appointments/{id}/notes`
```json
{
  "body": "Pièce commandée le 05/11, livraison prévue J+3.",
  "visibility": "internal"   // enum: internal, technician, public
}
```
- **Response 201** note created (returns note object).

### DELETE `/appointments/{id}/notes/{noteId}`
- Hard delete (only owner or admin).

---

## 5. Appointment Events (Timeline)

### GET `/appointments/{id}/events`
- Timeline entries for status changes, notifications, inventory actions.
- Response includes `type`, `payload`, `created_at`, `author`.

*Creation of events happens server-side when actions occur (status change, note creation, SMS).*

---

## 6. Availability Blocks

### GET `/availability-blocks`
- Query: `start`, `end`, `type`.
- Returns manual availability entries (blocks).

### POST `/availability-blocks`
```json
{
  "type": "closed",
  "title": "Fermé pour inventaire",
  "start_datetime": "2025-11-15T13:00:00+01:00",
  "end_datetime": "2025-11-15T18:00:00+01:00",
  "color": "#ff4b4b",
  "notes": "Inventaire annuel",
  "recurrence_rule": "FREQ=YEARLY",
  "recurrence_until": null
}
```
- **Response 201** block created.

### PATCH `/availability-blocks/{id}`
- Update block attributes (type, times, recurrence, notes).

### DELETE `/availability-blocks/{id}`
- Remove block (cascade effect on recurrence handled server-side).

---

## 7. Dashboard / Metrics

### GET `/dashboard/summary`
- Returns highlight metrics for the upcoming period.
```json
{
  "next_appointments": [
    { "id": 1, "start_datetime": "...", "service_label": "...", "customer_name": "...", "status": "booked" }
  ],
  "counts": {
    "today": 5,
    "week": 23,
    "pending_confirmation": 4,
    "cancel_pending": 1
  },
  "alerts": [
    { "type": "part_order", "appointment_id": 45, "message": "Pièce non reçue" }
  ]
}
```

### GET `/dashboard/agenda`
- Optionally returns aggregated availability/reservations for a given day to display workload charts.

---

## 8. Settings

### GET `/settings`
- Returns key/value map (`helix_settings` table).

### PUT `/settings/{key}`
- Example keys: `calendar.default_view`, `notifications.internal_sms`, `reminder.before_hours`.
- **Request**
  ```json
  {
    "value": { "enabled": true, "to": ["+33612345678"] }
  }
  ```

---

## 9. Webhooks / Integrations (Future)

- Endpoint to receive notifications when the public site creates a booking (`POST /webhooks/bookings` with signature).
- Export endpoints for invoices / reports.

---

### Notes
- All responses use snake_case to match database columns; optionally map to camelCase in frontend.
- Pagination follows Laravel conventions (`?page=1&per_page=25` returning `meta` + `links`).
- Error responses: JSON with `message`, optional `errors` details. Use `422` for validation errors.
- Rate limiting: apply per role if necessary.

This draft should be refined once UI workflows are finalized.

