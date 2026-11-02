# Project Time Tracker

Project Time Tracker is a simple web application to manage and track project times, developed by **Thomas Vannier**.
It allows you to:
- Add, edit, and delete projects
- Track active and finished projects
- Assign researchers and engineers
- Record time automatically with a timer or manually
- Visualize project distribution with charts
- Export / import the data as JSON

Version 2 stores the data in a **MariaDB (SQL) database** instead of JSON files, so several engineers can
use the application at the same time from different computers without overwriting each other's work.
The interface and the features are unchanged.
See **[MIGRATION.md](MIGRATION.md)** for the analysis of the problems of the JSON version and the
step-by-step procedure to migrate an existing installation.

---

## Architecture

```
 Browser ──HTTP──▶ Caddy (optional reverse proxy, HTTPS)
                     │
                     ▼  host port 4020
            ┌──────────────────────────┐        ┌─────────────────────────┐
            │ web  (port 80)           │  SQL   │ db                      │
            │ PHP 8.3 + Apache         │──────▶ │ MariaDB 11.4 LTS        │
            │ public/index.php (API)   │        │ volume "db_data"        │
            └──────────────────────────┘        └─────────────────────────┘
```

| Path | Content |
|---|---|
| `views/index.html`, `views/login.html` | The user interface (unchanged look and behaviour) |
| `public/index.php` | Front controller: pages, login, JSON API. `public/` is the only directory served by Apache |
| `src/` | PHP code: `ProjectService` (all changes, as atomic transactions), `ProjectRepository` (SQL), `Auth` (sessions), `Format` (date/duration formats), `ProjectInput` (validation) |
| `database/schema.sql` | Database schema (applied automatically at start-up) |
| `bin/migrate-json.php` | One-time migration of the JSON data, with verification |
| `bin/export-json.php` | Server-side JSON export (same format as the *Export* button) |
| `docker/` | Apache, PHP and entrypoint configuration |
| `tests/` | Automated tests (run in an isolated, throw-away stack) |

### How data stays consistent with several users

- Each action sends **one targeted change** (add 25 min to project 12, finish project 7, rename project 3…).
  The browser never sends its whole list back, so it can never overwrite what others saved.
- Recorded time is **added on the server** (`total = total + n`) inside a transaction: simultaneous timers all count.
- *Edit time manually* and the *Edit* dialog are checked against what the user saw: if someone else changed
  the same value in the meantime, the change is **refused with a clear message** instead of silently overwriting it.
  Fields changed by different people are merged.
- Project ids are assigned by the database (no more duplicates).
- Every change carries a unique id: a change sent twice (network retry, restore after an expired session,
  two tabs) is applied **once**.
- After each change the server returns the complete, current list; pages also refresh automatically every
  30 s and when the tab comes back to the foreground. Nothing is cached (`Cache-Control: no-store`).
- If the server cannot be reached or the session expired, the change is kept in the browser **as a single
  change** and sent later (the user is asked after logging in again, as before).
- Every change is recorded in the `activity_log` table (complete history), deleted projects can be restored.

---

## Getting Started

### Requirements
- [Docker](https://www.docker.com/) with [Docker Compose](https://docs.docker.com/compose/) v2 (`docker compose`)

### 1. Configuration

Two template files are provided. Copy them and edit the copies (the copies are ignored by Git, never commit them):

```bash
cp password.setup.env password.env      # PASSWORD and SESSION_SECRET
cp database.setup.env database.env      # MariaDB database name, user and password
```

`password.env`:

```env
PASSWORD=your_password
SESSION_SECRET=put_a_long_random_string_here_min_32_chars   # e.g. openssl rand -hex 32
```

`database.env`:

```env
MARIADB_DATABASE=projecttracker
MARIADB_USER=projecttracker
MARIADB_PASSWORD=choose_a_strong_password
```

Optional settings (in `password.env`): `SESSION_LIFETIME_HOURS` (default `8`), `APP_TIMEZONE`
(default `Europe/Paris`), `SESSION_COOKIE_SECURE` (`auto`, `true`, `false`).

### 2. Build and run

```bash
docker compose up -d --build
docker compose ps          # both containers should become "healthy"
```

The web container listens on **port 80**; `docker-compose.yml` maps it to port **4020** on the host:

```
http://localhost:4020
```

(or your reverse proxy domain, e.g. with Caddy: `reverse_proxy localhost:4020`).

On first start the tables are created automatically and the database is empty.

### 3. Import the existing data

Follow **[MIGRATION.md](MIGRATION.md)**. In short:

```bash
docker compose exec -T web php bin/migrate-json.php --dry-run - < centuri-projects.json   # check
docker compose exec -T web php bin/migrate-json.php - < centuri-projects.json             # migrate
```

---

## Operations

### Logs

```bash
docker compose logs -f web
docker compose logs -f db
```

### Backups

Database dump (recommended, e.g. daily with cron):

```bash
mkdir -p backups
docker compose exec -T db sh -c 'exec mariadb-dump --single-transaction --no-tablespaces -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' \
  | gzip > backups/projecttracker-$(date +%F).sql.gz
```

Restore a dump:

```bash
gunzip -c backups/projecttracker-YYYY-MM-DD.sql.gz \
  | docker compose exec -T db sh -c 'exec mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"'
```

JSON export (same format as the *Export Data as JSON* button):

```bash
docker compose exec -T web php bin/export-json.php > backups/projects-$(date +%F).json
```

The data lives in the Docker volume `db_data`: `docker compose down` keeps it,
**`docker compose down -v` deletes it**.

### Opening a SQL console

```bash
docker compose exec db sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"'
```

### Restoring a deleted project

Deleting a project in the interface hides it but keeps it in the database:

```sql
SELECT id, title, deleted_at FROM projects WHERE deleted_at IS NOT NULL;
UPDATE projects SET deleted_at = NULL WHERE id = 42;
```

### History of a project

```sql
SELECT created_at, action, seconds_delta, total_seconds_after, details
FROM activity_log WHERE project_id = 42 ORDER BY id;
```

`action` is one of `migrate`, `create`, `update`, `add_time`, `set_time`, `finish`, `reopen`,
`move`, `delete`, `import`. Times are in UTC. The `migrate` rows contain a verbatim copy of the
original JSON record.

### Changing the database password

The values of `database.env` are only used when the `db_data` volume is created. To change the
password later, change it in MariaDB first, then in `database.env`, then restart:

```bash
docker compose exec db sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" -e "SET PASSWORD = PASSWORD('"'"'NEW_PASSWORD'"'"')"'
# edit database.env (MARIADB_PASSWORD=NEW_PASSWORD), then:
docker compose up -d
```

### Updating the application

```bash
git pull
docker compose up -d --build
```

The schema is applied idempotently at each start; the data volume is kept.

---

## API Endpoints

All endpoints except `/api/auth` and `/api/health` require a session (login). Responses are JSON and never cached.

| Method & path | Purpose |
|---|---|
| `POST /api/auth` `{password}` | Log in |
| `GET /logout` | Log out |
| `GET /api/session` | Session check |
| `GET /api/health` | Health check (used by Docker) |
| `GET /api/projects` | All projects (same JSON format as before) |
| `POST /api/projects` | Create a project `{title, researchers, engineers, institute, category}` |
| `PATCH /api/projects/{id}` | Edit title / researchers / engineers / institute / category |
| `DELETE /api/projects/{id}` | Delete (soft delete) |
| `POST /api/projects/{id}/time` | Add timer time `{seconds, lastUpdate}` |
| `PUT /api/projects/{id}/time` | Set the total manually `{totalHours, expected, lastUpdate}` |
| `POST /api/projects/{id}/finish` · `/reopen` | Change the status |
| `POST /api/projects/{id}/move` | Drag & drop `{targetId}` |
| `POST /api/projects/bulk` | `{action: "finish"\|"reopen"\|"delete", ids: [...]}` |
| `POST /api/projects/import` | Add the projects of an exported file `{projects: [...]}` |

Every change returns `{"status": "ok", "projects": [...]}` with the up-to-date list and accepts an
`Idempotency-Key` header. Project format (unchanged):

```json
{
  "id": 1,
  "title": "Example Project",
  "researchers": ["Alice"],
  "engineers": ["Thomas Vannier"],
  "institute": "IBDM",
  "submissionDate": "22/09/2025",
  "startDate": "22/09/2025",
  "endDate": "",
  "totalHours": "2:30:00",
  "lastUpdate": "22/09/2025\r\n14:05:00",
  "category": "Bioinformatics",
  "status": "active",
  "order": 0
}
```

---

## Tests

```bash
sh tests/run-tests.sh
```

Builds the image and starts a separate test stack (project `projecttracker-test`, port 4099, in-memory
database — production containers and data are never touched), then runs the migration tests and the API
tests, including simultaneous-use scenarios (30 simultaneous timer stops, concurrent edits, reorder from an
outdated page, retries, session expiry, restart).

## Author

Developed by Thomas Vannier.
