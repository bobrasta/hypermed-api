# CLAUDE.md — Hypermed API (Laravel + PostgreSQL)

## What is Hypermed?

Hypermed is a **medical equipment management platform** for a company that
sells, installs, and maintains biomedical machines at hospitals across
**Tanzania**. It is used by internal staff (field technicians, sales reps,
finance, admin).

The company is **MedEquip Tanzania Ltd** (placeholder name in the UI).
Currency is **TZS (Tanzanian Shilling)**. All monetary values in the
database are stored as integers (shillings, no decimals).

---

## Project structure

```
Flutter frontend  →  c:\flutter_projects\bienhypermed   (already built)
Laravel backend   →  this project                        (being built now)
```

The Flutter app currently uses hardcoded `SampleData`. The goal is to
replace every `SampleData.xxx` call with real HTTP requests to this API.

---

## Tech stack

| Layer        | Choice                          |
|--------------|---------------------------------|
| Framework    | Laravel 11+                     |
| Database     | PostgreSQL                      |
| Auth         | Laravel Sanctum (API tokens)    |
| API style    | REST, versioned under `/api/v1` |
| Response     | JSON via Laravel API Resources  |

---

## Authentication

- `POST /api/v1/auth/login`  — email + password → returns `{ token, user }`
- `POST /api/v1/auth/logout` — revokes current token (auth required)
- `GET  /api/v1/auth/me`     — returns authenticated user profile

All other routes are protected by `auth:sanctum` middleware.
Token is passed as `Authorization: Bearer <token>` header.

---

## Database tables & field names

Use **snake_case** for all column names. All tables have `id` (bigint,
primary key), `created_at`, `updated_at`.

### `users` (staff / technicians / admins)
```
id, name, email, password, role (enum), phone, region, avatar_initials,
avail_status (enum), is_active
```
`role` values: `admin`, `technician`, `sales`, `finance`, `cs`
`avail_status` values: `Available`, `On task`, `Assigned`, `At desk`, `Busy`

---

### `hospitals`
```
id, name, short_code, type (enum), region, district,
latitude (decimal 10,7), longitude (decimal 10,7),
machine_count (computed / cached), machines_operational (computed / cached),
revenue_monthly (bigint, TZS),
contact_name, contact_phone, contact_email, notes
```
`type` values: `public`, `private`, `mission`, `clinic`

---

### `machines`
```
id, serial_no (unique), model, type, hospital_id (FK),
ward, install_date (date), warranty_expiry (date),
status (enum), revenue_per_month (bigint, TZS)
```
`status` values: `operational`, `needs_service`, `down`, `warranty`, `idle`

The Flutter app also uses short CSS class names for status:
`op`, `svc`, `down`, `claim`, `idle` — include both in API responses.

---

### `service_tickets`
```
id, ticket_number (string, e.g. '#1042'), machine_id (FK),
hospital_id (FK), ward, assigned_to (FK → users.id),
status (enum), description, created_at
```
`status` values: `open`, `in_progress`, `resolved`, `overdue`

#### `checklist_items`
```
id, ticket_id (FK), label, is_checked
```

#### `parts_used`
```
id, ticket_id (FK), spare_part_id (FK), qty, unit_cost (bigint)
```

---

### `invoices`
```
id, invoice_number (unique), hospital_id (FK), machine_id (FK nullable),
issue_date (date), due_date (date),
subtotal (bigint), tax_rate (decimal 5,2, default 18.0),
tax_amount (bigint), total (bigint), amount_paid (bigint),
status (enum), currency (default 'TZS'), notes
```
`status` values: `pending`, `partial`, `paid`, `overdue`, `waived`

#### `invoice_line_items`
```
id, invoice_id (FK), description, quantity (decimal 8,2),
unit_price (bigint), total (bigint)
```

---

### `sales_leads`
```
id, hospital_id (FK nullable — may be a prospect not yet in hospitals table),
hospital_name_raw (string — for prospects), contact_id (FK nullable),
contact_name_raw (string), machine_type, deal_value (bigint, TZS),
days_in_stage (computed from updated_at), stage (enum),
demo_date (date nullable), assigned_to (FK → users.id nullable)
```
`stage` values: `lead`, `qualified`, `demo_scheduled`, `proposal_sent`,
`negotiation`, `won`, `lost`

---

### `spare_parts`
```
id, part_number (unique), name, description,
unit_cost (bigint), currency (default 'TZS'),
stock_qty (int), reorder_level (int), supplier
```

#### `spare_part_machine_models` (pivot — compatible models)
```
id, spare_part_id (FK), machine_model (string)
```

---

### `contacts` (hospital contacts / CRM)
```
id, first_name, last_name, job_title, department,
email, phone, whatsapp, hospital_id (FK),
last_contacted_at (datetime nullable),
next_followup_at (datetime nullable)
```

#### `contact_tags` (pivot)
```
id, contact_id (FK), tag (string)
```

#### `contact_interactions`
```
id, contact_id (FK), type (enum), summary, outcome,
next_action, next_action_date, created_at
```
`type` values: `call`, `email`, `meeting`, `whatsapp`, `visit`

---

### `notifications`
```
id, user_id (FK), type (enum), title, body,
entity_type (string nullable — 'ticket'|'invoice'|'deal'|'machine'),
entity_id (bigint nullable), is_read (bool default false), created_at
```
`type` values: `service_due`, `ticket_assigned`, `ticket_updated`,
`payment_overdue`, `warranty_expiring`, `deal_updated`, `system`

---

## API endpoints (full surface)

### Auth
```
POST   /api/v1/auth/login
POST   /api/v1/auth/logout
GET    /api/v1/auth/me
```

### Dashboard
```
GET    /api/v1/dashboard          — KPI summary + recent tickets + top hospitals
```

### Machines
```
GET    /api/v1/machines           — paginated list, filters: status, hospital, type
POST   /api/v1/machines           — create
GET    /api/v1/machines/{id}      — detail + service history + parts used
PUT    /api/v1/machines/{id}      — update
DELETE /api/v1/machines/{id}
GET    /api/v1/machines/map       — all machines with GPS coords for map screen
```

### Hospitals
```
GET    /api/v1/hospitals          — paginated list, filters: type, region
POST   /api/v1/hospitals
GET    /api/v1/hospitals/{id}     — detail + machine list + uptime stats
PUT    /api/v1/hospitals/{id}
DELETE /api/v1/hospitals/{id}
```

### Service Tickets
```
GET    /api/v1/tickets            — paginated, filters: status, machine, hospital, assignee
POST   /api/v1/tickets
GET    /api/v1/tickets/{id}
PUT    /api/v1/tickets/{id}
DELETE /api/v1/tickets/{id}
POST   /api/v1/tickets/{id}/resolve
POST   /api/v1/tickets/{id}/checklist/{item}  — toggle checklist item
```

### Revenue / Invoices
```
GET    /api/v1/invoices           — paginated, filters: status, hospital, date range
POST   /api/v1/invoices
GET    /api/v1/invoices/{id}
PUT    /api/v1/invoices/{id}
GET    /api/v1/revenue/summary    — monthly actual vs target (12 months)
GET    /api/v1/revenue/by-hospital — top hospitals by revenue
```

### Sales Pipeline
```
GET    /api/v1/leads              — all leads, grouped by stage for kanban
POST   /api/v1/leads
GET    /api/v1/leads/{id}
PUT    /api/v1/leads/{id}
PATCH  /api/v1/leads/{id}/stage   — move stage
```

### Spare Parts / Inventory
```
GET    /api/v1/spare-parts        — paginated, filter: low_stock, supplier
POST   /api/v1/spare-parts
GET    /api/v1/spare-parts/{id}
PUT    /api/v1/spare-parts/{id}
```

### Contacts (CRM)
```
GET    /api/v1/contacts           — paginated, filter: hospital, tag
POST   /api/v1/contacts
GET    /api/v1/contacts/{id}
PUT    /api/v1/contacts/{id}
POST   /api/v1/contacts/{id}/interactions
```

### Staff / Users
```
GET    /api/v1/staff              — all users/technicians
GET    /api/v1/staff/{id}
PUT    /api/v1/staff/{id}
```

### Notifications
```
GET    /api/v1/notifications      — for current user
PATCH  /api/v1/notifications/{id}/read
POST   /api/v1/notifications/read-all
```

### Reports
```
GET    /api/v1/reports            — aggregated report data
```

---

## JSON response shape conventions

All list endpoints return:
```json
{
  "data": [...],
  "meta": { "current_page": 1, "last_page": 4, "total": 80, "per_page": 20 }
}
```

All single-resource endpoints return:
```json
{ "data": { ... } }
```

Errors return:
```json
{ "message": "...", "errors": { "field": ["..."] } }
```

- Dates: ISO 8601 strings (`"2024-03-14"`)
- Monetary values: integers (TZS, e.g. `4200000`)
- Enums: lowercase snake_case strings matching the values listed above
- Booleans: `true` / `false`

---

## Geography context

Tanzania has 26 regions. The app groups them into 6 zones for the map
screen:

| Zone key    | Zone label        | Key regions                        |
|-------------|-------------------|------------------------------------|
| `coastal`   | Coastal Zone      | Dar es Salaam, Pwani, Tanga        |
| `northern`  | Northern Zone     | Arusha, Kilimanjaro, Manyara       |
| `lake`      | Lake Zone         | Mwanza, Kagera, Geita, Shinyanga   |
| `central`   | Central Zone      | Dodoma, Singida, Tabora            |
| `shighland` | Southern Highland | Mbeya, Iringa, Njombe              |
| `southern`  | Southern Zone     | Lindi, Mtwara, Ruvuma              |

Add a `zone` column to the `hospitals` table.

---

## Flutter frontend reference

- Located at: `c:\flutter_projects\bienhypermed`
- All screens are in `lib/screens/`
- All models are in `lib/models/` — field names there define what the API
  must return (use Laravel API Resources to match exactly)
- Current data source: `lib/data/sample_data.dart` — replace each
  collection with a real API call
- HTTP client to be added: `dio` package
- Auth token stored in `flutter_secure_storage`

---

## Naming conventions (Laravel side)

- Models: `PascalCase` singular (`Machine`, `ServiceTicket`, `SalesLead`)
- Tables: `snake_case` plural (`machines`, `service_tickets`, `sales_leads`)
- API Resources: `MachineResource`, `HospitalResource`, etc.
- Controllers: `MachineController`, `HospitalController`, etc. — all `--api`
- Route file: `routes/api.php` with `Route::prefix('v1')->group(...)`
- Use Form Requests for validation (`StoreMachineRequest`, `UpdateMachineRequest`)
- Policies for authorization (`MachinePolicy`, etc.)

---

## Seeding

Seed the database with realistic Tanzania data matching the Flutter
`SampleData` so the UI looks identical during development. Seed classes:

```
DatabaseSeeder
  └── UserSeeder
  └── HospitalSeeder
  └── MachineSeeder
  └── ServiceTicketSeeder
  └── InvoiceSeeder
  └── SalesLeadSeeder
  └── SparePartSeeder
  └── ContactSeeder
  └── NotificationSeeder
```

---

## Development order (recommended)

1. Laravel project creation + PostgreSQL connection verified
2. Migrations for all tables above
3. Models + relationships
4. Seeders (copy values from Flutter SampleData)
5. Auth (Sanctum login/logout/me)
6. MachineController + MachineResource (first full round-trip test)
7. HospitalController
8. ServiceTicketController
9. InvoiceController + revenue summary
10. Remaining controllers
11. Notifications system
12. Dashboard aggregation endpoint
