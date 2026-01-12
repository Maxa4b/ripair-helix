# Helix – Data Model Proposal (v0.1)

This document describes the target relational schema for the Helix back-office.  
It builds on the current MySQL database used by `ripair.shop` and extends it for richer operations management.

---

## 1. Existing Tables (recap)

| Table | Key fields (observed) | Notes |
| --- | --- | --- |
| `appointments` | `id`, `service_label`, `duration_min`, `start_datetime`, `end_datetime`, `customer_name`, `customer_email`, `customer_phone`, `status`, `cancel_token`, `cancel_token_created_at`, `cancel_token_expires_at`, `cancel_token_used_at`, `part_ordered_at`, `slot_id?`, timestamps | Current online booking storage. Lacks ownership, pricing metadata, audit trail. |
| `slots` | `id`, `date`, `time`, `is_booked` | Legacy slot system (still used for the older flow). |
| `opening_hours` | `weekday`, `morning_start`, `morning_end`, `afternoon_start`, `afternoon_end`, `slot_step_min` | Determines availability grid for the public booking tool. |
| `promo_rules` | `weekday`, `start_time`, `end_time`, `discount_pct` | Discounts applied to certain slots. |
| `quotes` | `id`, `category`, `brand`, `model`, `problem`, `price`, `duration`, timestamps | Keeps generated quotes (used to pre-fill booking page). |

Helix must remain compatible (no breaking changes for the public website).

---

## 2. Target Entities & Relationships

```
helix_users (staff) 1----* Appointments *----* helix_appointment_notes
                 \                        \
                  \                        *----* helix_appointment_events (audit)
                   \
                    *----* helix_availability_blocks  (authored by staff)

Stores (optional future) 1----* Appointments
```

### 2.1 Staff users (`helix_users`)
Purpose: authenticate Helix operators and control permissions.

| Column | Type | Description |
| --- | --- | --- |
| `id` | BIGINT UNSIGNED PK | Identifier |
| `first_name` | VARCHAR(80) | Prénom affiché |
| `last_name` | VARCHAR(80) | Nom |
| `email` | VARCHAR(190) UNIQUE | Login |
| `password_hash` | VARCHAR(255) | Hash bcrypt/argon |
| `role` | ENUM(`owner`,`manager`,`technician`,`frontdesk`) | ACL simple |
| `phone` | VARCHAR(25) NULL | Pour notifications |
| `color` | CHAR(7) NULL | Couleur agenda (technicien) |
| `is_active` | TINYINT(1) DEFAULT 1 |
| timestamps | `created_at`, `updated_at`, `last_login_at` |

Indexes: unique(`email`), index(`role`,`is_active`).

### 2.2 Appointments (`appointments`) — extensions
Add columns to the existing table instead of recreating it.

| Column | Type | Description |
| --- | --- | --- |
| `assigned_user_id` | BIGINT NULL FK→`helix_users.id` | Technicien référent |
| `store_code` | VARCHAR(20) DEFAULT 'CESTAS' | Pour multi-sites futur |
| `price_estimate_cents` | INT NULL | Total estimé TTC (en centimes) |
| `discount_pct` | DECIMAL(5,2) NULL | Remise appliquée |
| `source` | ENUM(`web`, `manual`, `import`, `helix`) DEFAULT `web` | Origine |
| `status` | ENUM(`booked`,`confirmed`,`in_progress`,`done`,`cancelled`,`no_show`) | Replace current text column si non enum |
| `status_updated_at` | DATETIME NULL | Dernier changement |
| `internal_notes` | TEXT NULL | Note courte (affichée dans la fiche) |
| `customer_address` | VARCHAR(255) NULL | Field optional |
| `meta` | JSON NULL | Stockage flexible (ex: ID SumUp, pièces) |

**Indexes**
- index on (`start_datetime`,`end_datetime`)
- index on (`status`)
- index on (`assigned_user_id`,`start_datetime`)
- index on (`source`)

**Migration considerations**
- Migrate existing `status` values (`booked` etc.) to ENUM set (verify current data).  
- `price_estimate_cents` = `quote price * 100`, keep 0 if unknown.  
- `cancel_token` & related columns stay intact.

### 2.3 Appointment Notes (`helix_appointment_notes`)
Internal comments + timeline.

| Column | Type | Description |
| --- | --- | --- |
| `id` | BIGINT PK |
| `appointment_id` | BIGINT FK→`appointments.id` ON DELETE CASCADE |
| `author_id` | BIGINT FK→`helix_users.id` NULL (system events) |
| `body` | TEXT | Note (markdown autorisé) |
| `visibility` | ENUM(`internal`,`technician`,`public`) DEFAULT `internal` |
| `created_at` | DATETIME |

Indexes: (`appointment_id`,`created_at` DESC).

### 2.4 Appointment Events (`helix_appointment_events`)
Audit trail pour statut, notifications, pièces commandées.

| Column | Type | Description |
| --- | --- | --- |
| `id` | BIGINT PK |
| `appointment_id` | BIGINT FK→`appointments.id` |
| `type` | ENUM(`status_change`,`notification`,`inventory`,`payment`,`custom`) |
| `payload` | JSON | Détails (avant / après statut, SMS id, etc.) |
| `author_id` | BIGINT NULL | Staff responsable |
| `created_at` | DATETIME |

### 2.5 Availability Blocks (`helix_availability_blocks`)
Define manual opening/closing windows or allocation to projects.

| Column | Type | Description |
| --- | --- | --- |
| `id` | BIGINT PK |
| `created_by` | BIGINT FK→`helix_users.id` |
| `type` | ENUM(`open`,`closed`,`maintenance`,`offsite`) DEFAULT `open` |
| `title` | VARCHAR(140) NULL | Description rapide |
| `start_datetime` | DATETIME |
| `end_datetime` | DATETIME |
| `recurrence_rule` | VARCHAR(255) NULL | iCal RRULE string |
| `recurrence_until` | DATETIME NULL |
| `color` | CHAR(7) NULL | Couleur custom dans l’agenda |
| `notes` | TEXT NULL |
| timestamps | `created_at`, `updated_at` |

Indexes: (`start_datetime`,`end_datetime`), (`type`).

*Usage:* Helix UI affichera ce calendrier par-dessus `appointments`.  
Public site pourra (plus tard) consommer ces données au lieu de `opening_hours`.

### 2.6 Settings (`helix_settings`)
Key-value storage for Helix-specific configuration (optional, simple).

| Column | Type | Description |
| --- | --- | --- |
| `key` | VARCHAR(120) PK |
| `value` | JSON | Example: default_duration, reminder_preferences |
| `updated_at` | DATETIME |

### 2.7 (Optional later) `inventory_items`, `appointment_parts`
Placeholders for stock management if needed later.

---

## 3. Entity Relationships Summary

- `appointments.assigned_user_id` → `helix_users.id`
- `helix_appointment_notes.author_id` → `helix_users.id`
- `helix_appointment_notes.appointment_id` → `appointments.id`
- `helix_appointment_events.appointment_id` → `appointments.id`
- `helix_availability_blocks.created_by` → `helix_users.id`

Ensure cascading deletes for child tables (`helix_appointment_notes`, `helix_appointment_events`) when an appointment is deleted.

---

## 4. Migration Plan (Phase 1)

1. **Add staff table**
   ```sql
   CREATE TABLE helix_users (
     id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
     first_name VARCHAR(80) NOT NULL,
     last_name VARCHAR(80) NOT NULL,
     email VARCHAR(190) NOT NULL UNIQUE,
     password_hash VARCHAR(255) NOT NULL,
     role ENUM('owner','manager','technician','frontdesk') NOT NULL DEFAULT 'manager',
     phone VARCHAR(25) NULL,
     color CHAR(7) NULL,
     is_active TINYINT(1) NOT NULL DEFAULT 1,
     last_login_at DATETIME NULL,
     created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
     updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
   );
   ```

2. **Alter `appointments`**
   ```sql
   ALTER TABLE appointments
     ADD COLUMN assigned_user_id BIGINT UNSIGNED NULL AFTER status,
     ADD COLUMN store_code VARCHAR(20) NOT NULL DEFAULT 'CESTAS' AFTER assigned_user_id,
     ADD COLUMN price_estimate_cents INT NULL AFTER store_code,
     ADD COLUMN discount_pct DECIMAL(5,2) NULL AFTER price_estimate_cents,
     ADD COLUMN source ENUM('web','manual','import','helix') NOT NULL DEFAULT 'web' AFTER discount_pct,
     ADD COLUMN status_updated_at DATETIME NULL AFTER end_datetime,
     ADD COLUMN internal_notes TEXT NULL AFTER status_updated_at,
     ADD COLUMN customer_address VARCHAR(255) NULL AFTER internal_notes,
     ADD COLUMN meta JSON NULL AFTER customer_address,
     ADD INDEX idx_appointments_schedule (start_datetime, end_datetime),
     ADD INDEX idx_appointments_status (status),
     ADD INDEX idx_appointments_assignee (assigned_user_id, start_datetime),
     ADD INDEX idx_appointments_source (source);
   ```
   - If `status` is currently `VARCHAR`, run `ALTER TABLE` to ENUM and migrate existing values (`booked`, `cancelled`) first.
   - Add FK constraint: `ALTER TABLE appointments ADD CONSTRAINT fk_appointments_user FOREIGN KEY (assigned_user_id) REFERENCES helix_users(id) ON DELETE SET NULL;`
   - **Important:** keep `appointments.id` type as-is (currently `INT UNSIGNED`); all foreign keys referencing it (`appointment_id`) must use the exact same type.

3. **Create child tables**
   ```sql
  CREATE TABLE helix_appointment_notes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT NOT NULL,          -- type identique à appointments.id
    author_id BIGINT UNSIGNED NULL,
    body TEXT NOT NULL,
    visibility ENUM('internal','technician','public') NOT NULL DEFAULT 'internal',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notes_appointment
        FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
    CONSTRAINT fk_notes_author
        FOREIGN KEY (author_id) REFERENCES helix_users(id) ON DELETE SET NULL,
    INDEX idx_notes_appointment_created (appointment_id, created_at DESC)
  );


  CREATE TABLE helix_appointment_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT NOT NULL,          -- même type que appointments.id
    type ENUM('status_change','notification','inventory','payment','custom') NOT NULL,
    payload JSON NULL,
    author_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_events_appointment
        FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
    CONSTRAINT fk_events_author
        FOREIGN KEY (author_id) REFERENCES helix_users(id) ON DELETE SET NULL,
    INDEX idx_events_type_created (type, created_at DESC)
  );


   CREATE TABLE helix_availability_blocks (
     id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
     created_by BIGINT UNSIGNED NOT NULL,
     type ENUM('open','closed','maintenance','offsite') NOT NULL DEFAULT 'open',
     title VARCHAR(140) NULL,
     start_datetime DATETIME NOT NULL,
     end_datetime DATETIME NOT NULL,
     recurrence_rule VARCHAR(255) NULL,
     recurrence_until DATETIME NULL,
     color CHAR(7) NULL,
     notes TEXT NULL,
     created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
     updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
     CONSTRAINT fk_availability_user FOREIGN KEY (created_by) REFERENCES helix_users(id) ON DELETE CASCADE,
     INDEX idx_availability_schedule (start_datetime, end_datetime),
     INDEX idx_availability_type (type)
   );
   ```

4. **Optional** settings table
   ```sql
   CREATE TABLE helix_settings (
     `key` VARCHAR(120) NOT NULL PRIMARY KEY,
     `value` JSON NOT NULL,
     updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
   );
   ```

---

## 5. Data Access Patterns
- **Agenda views**: query `appointments` & `helix_availability_blocks` for a given date range.  
  Use composite index `start_datetime` to support range scans.
- **Dashboards**: aggregated counts by `status`, `source`, `store_code` — add covering indexes if necessary once usage is measured.
- **Search**: filter by `customer_email`, `customer_phone`, `tag` (if present). Consider adding index on these fields if queries become frequent.
- **Audit**: join `helix_appointment_events` with `helix_users` to display timeline.

---

## 6. Compatibility Considerations
- Keep writing to `appointments` from existing API; new columns should have defaults to avoid failing current inserts.  
  - Update PHP API (`book.php`, `send_confirmation.php`) to populate `price_estimate_cents`, `discount_pct`, `source='web'`.
- `helix_availability_blocks` is additive; public site can continue to rely on `opening_hours` until Helix replaces the logic.
- Introduce database migration scripts in a controlled environment (stage → prod).

---

## 7. Open Items
- Decide if `appointments` should track `tag` references to `quotes.id` (foreign key) officially.
- Determine retention policy for `helix_appointment_events` (size can grow quickly).
- Clarify timezone handling (store everything in UTC? currently looks like Europe/Paris).
- Determine encryption/anonymisation requirements (GDPR) for `customer_*` fields.

---

**Next deliverable:** confirm schema with stakeholders, then script migrations (Laravel migrations or raw SQL). Update API contract accordingly.
