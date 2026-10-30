# Project 25 — Inventory Reorder Alert System

Week 13 implementation using Flutter Web, a Laravel REST API, and MySQL. The project uses fictional sample inventory and does not connect to a real purchasing or notification service.

## Local setup

### 1. Create the MySQL database

Start the MySQL 8 service, then create the database using a MySQL account with permission to create databases:

```sql
CREATE DATABASE inventory_reorder_alerts
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
```

In `backend/.env`, set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` for your local MySQL instance. Do not commit `.env` or use production credentials.

### 2. Start the Laravel API

From `backend`:

```powershell
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve --host 127.0.0.1 --port 8000
```

The seed command creates fictional office/laboratory supplies. The sample toner and gloves begin below their thresholds to demonstrate alerts and recommendations.

### 3. Start scheduled automation

In another terminal, from `backend`:

```powershell
php artisan schedule:work
```

The daily aging scan runs at 08:00 Asia/Manila. The weekly summary covers Monday–Sunday and runs Monday at 08:00 Asia/Manila. Keep the scheduler process running to execute these triggers.

To capture the recovered-transient-failure demonstration on a fresh reporting week, run this **before** generating that week's summary:

```powershell
php artisan inventory:weekly-risk --simulate-transient
```

The local-only simulation fails once, retries, and uses an idempotency key so the reporting period has one summary. After that, a regular manual run is safe and returns the existing summary:

```powershell
php artisan inventory:weekly-risk
```

The simulation is refused outside local/testing environments.

### 4. Start Flutter Web

From `frontend`:

```powershell
flutter pub get
flutter run -d web-server --web-hostname 127.0.0.1 --web-port 5000
```

Open the URL printed by Flutter. The default API URL is `http://127.0.0.1:8000/api/v1`; override it with:

```powershell
flutter run -d web-server --web-hostname 127.0.0.1 --web-port 5000 --dart-define=API_BASE_URL=http://127.0.0.1:8000/api/v1
```

## Verify

From `frontend`:

```powershell
flutter analyze
flutter test
flutter build web
```

From `backend`, enable PHP's `pdo_sqlite` extension for the in-memory feature-test database, then run:

```powershell
php artisan test
```

The feature tests cover the normal reorder workflow, invalid input, threshold/duplicate decisions, the recovered transient summary failure, permanent aging failure routed to a supervisor manual-action queue, and human resolution.

## Scope and limitations

- Week 13 provides threshold alerts, active-reorder duplicate prevention, seven-day aging escalation, and weekly stock-risk summaries.
- Week 13 defines unauthorized 401/403 API/UI behavior, but authentication and role enforcement are intentionally deferred to Week 14. Do not expose this prototype outside a trusted local environment.
- “Demo user” audit labels are placeholders until authentication is implemented; they do not prove a real person's identity.
- If `INVENTORY_SUPERVISOR_OWNER` is unset, aging automation creates a permanent-failure manual action for a supervisor to assign an owner.
- The project schema/API were validated against a local MariaDB 10.4 server using Laravel's MySQL driver. The installed MySQL 8 Windows service was stopped; run final deployment checks against the course-approved MySQL version if required.

See [the requirements checklist](documentation/requirements-checklist.md), [the system architecture](documentation/system-architecture.md), and [the implementation plan](documentation/implementation-plan.md) for the traceable scope and decisions.
