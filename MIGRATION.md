# Migration from the JSON version (v1) to the SQL version (v2)

This document explains why the JSON version lost or showed outdated data, what v2 changes, and how to
migrate an existing installation without losing anything.

---

## 1. Root causes of the problems in v1

The issues were not isolated bugs but came from the way data was saved, which is why fixing one error
kept revealing another one.

### 1.1 Each browser wrote back its own (outdated) copy of all the data — *main cause*

v1 loaded the project list once when the page was opened and kept it in the browser. Several actions
then sent this **whole list** back to the server (`POST /api/projects`), and the server **deleted every
project file that was not in that list**. Other actions re-wrote complete project objects, including
`totalHours`, from the browser's copy.

So any page opened *before* someone else's change silently undid that change. Reproduced on v1 with the
real data:

1. Engineer A opens the application.
2. Engineer B creates project #31 and records 2 h on project #27.
3. Engineer A drags a row to reorder the list.
4. Result: **project #31 is deleted and B's 2 hours are gone** (`16:24:51` instead of `18:24:51`).

The same happened with *Add project*, *Delete*, *Import*, *Finish/Delete selected* (whole list) and
with the timer, *Edit time manually* and the *Edit* dialog (whole project, including a stale
`totalHours`: two engineers stopping a timer on the same project = one of the two durations lost).

### 1.2 The page never refreshed

Data was only loaded when the page was opened: changes made from other computers stayed invisible
until a manual reload, and all subsequent saves were computed from this outdated copy (1.1).

### 1.3 The "restore after session expiry" overwrote everybody's data

When a save failed (expired session, any network error), v1 stored a copy of the **entire list** in the
browser (`localStorage`). After the next login it offered to "restore" it, which pushed that old snapshot
over the server data: projects created in the meantime were deleted and hours recorded by others were
reverted. This matches the "after closing and reopening a session" symptom. Sessions were also kept in
memory, so every restart of the container logged everybody out and triggered this path.

### 1.4 Two storage locations that drifted apart

v1 read the projects from `project/*.json` but several actions only updated `projects.json` and others
only `project/*.json`. When one project file could not be read (e.g. a write interrupted half-way —
files were overwritten in place, not atomically), the server fell back to `projects.json`, which was
older, and the next whole-list save made this outdated data permanent.

### 1.5 Project ids generated in the browser

New ids were `max(id) + 1` computed by each browser: two engineers creating a project at about the same
time obtained the same id, and the "rename clean-up" of the second save deleted the first project's file.

### 1.6 Timer bound to the checkbox selection

The timer recorded its time on *the currently selected row*. Changing the selection while it ran put the
time on another project; *Deselect all* or *Finish selected* during a running timer **dropped the
time entirely**.

### 1.7 Security issues

- The Express static server served the whole application directory **without login**:
  `/projects.json` (all the data) and `/password.env` (the login password and the session secret,
  copied into the image by `COPY . .` because there was no `.dockerignore`). Verified on v1.
- Project values were inserted into the page as HTML (a `<` in a title broke the table; HTML/JavaScript
  in a title was executed).

---

## 2. What v2 changes

| Problem | v2 |
|---|---|
| Whole-list overwrites (1.1) | Each action sends one targeted change; the server applies it in a SQL transaction. The old "save whole list" endpoints are refused (HTTP 410), even from a v1 page still open in a tab. |
| Lost timer time | Time is **added** on the server (`total_seconds = total_seconds + n`): simultaneous timers all count. Tested with 30 simultaneous timer stops. |
| Stale manual time / edits | *Edit time manually* is refused if the total changed since it was displayed; the *Edit* dialog merges fields changed by different people and refuses only a field changed by both — with a clear message, never a silent overwrite. |
| Outdated display (1.2) | The server answers every change with the current list; pages refresh every 30 s and when the tab is shown again; `Cache-Control: no-store` everywhere. |
| Session restore (1.3) | Unsaved changes are kept as individual changes with a unique id, re-sent after login and applied **exactly once**. Sessions are stored in the database (survive restarts); same 8 h inactivity rule, background refresh does not count as activity. |
| Two storages (1.4) | One database (MariaDB/InnoDB, transactions, crash-safe). |
| Duplicate ids (1.5) | Ids assigned by the database (`AUTO_INCREMENT`). |
| Timer selection (1.6) | The time goes to the project the timer was started on. |
| Security (1.7) | Only `public/` (logo + front controller) is served; secrets and data are never in the image or reachable; all values are displayed as text. |
| History | Every change is written to `activity_log`; deletions are soft (restorable). |

The interface is the same page with the same texts, buttons, dialogs and notifications. The only text
change: *"Your data is automatically saved to your browser's local storage"* now reads *"Your data is
automatically saved on the server"* (which is what actually happens). New messages appear only in the
new situations above (e.g. a refused conflicting change).

---

## 3. Migration procedure

The migration script `bin/migrate-json.php`:

- accepts an export file (`centuri-projects.json`), `projects.json`, or the `project/` directory;
- validates every value first and stops if anything is unreadable (nothing is written);
- keeps ids, titles, researchers and engineers (order and spelling), institutes, categories, statuses,
  all dates, recorded hours, *last update* (with or without time) and the display order;
- stores a verbatim copy of every original record in `activity_log`;
- reads everything back through the application code and compares it **field by field** with the
  source before committing (one transaction: all or nothing);
- refuses to run on a database that already contains projects (unless `--replace`);
- `--dry-run` does everything, shows the report, and rolls back.

Your export of 02/11/2026 (27 projects, 19 active / 8 finished, 3553:59:59 recorded) was migrated with
this script during development: zero differences, and the JSON exported afterwards from the database is
identical to the original file.

### Step by step (on the server, in the repository directory)

**1. Freeze v1.** Ask everyone to stop their running timers, then close the tracker tabs. Then make a
copy of the current data:

```bash
cp -a project project.backup-$(date +%F)
cp -a projects.json projects.json.backup-$(date +%F)
```

Take a fresh export with **Export Data as JSON** (file `centuri-projects.json`). The `project/`
directory can also be used directly: it is what v1 displays.

**2. Stop v1 and get v2.**

```bash
docker compose down          # (or docker-compose down) — the JSON files on disk are not touched
git pull
```

**3. Configure.** `password.env` is reused as is. Create `database.env`:

```bash
cp database.setup.env database.env
nano database.env            # set MARIADB_PASSWORD
```

Because v1 exposed `password.env` publicly (see 1.7), **choose a new `PASSWORD` and a new
`SESSION_SECRET`** in `password.env` (`openssl rand -hex 32`).

**4. Build, then check the migration without writing anything.**

```bash
docker compose build
docker compose run --rm -T web php bin/migrate-json.php --dry-run - < centuri-projects.json
```

(`docker compose run` starts the database automatically and creates the tables.) Read the report: the
list of projects, the totals, and `Verification: every project was read back and matches the source`.

To use the `project/` directory instead of an export:

```bash
docker compose run --rm -T -v "$PWD/project:/legacy:ro" web php bin/migrate-json.php --dry-run /legacy
```

**5. Migrate** (same command without `--dry-run`):

```bash
docker compose run --rm -T web php bin/migrate-json.php - < centuri-projects.json
```

**6. Start the application.**

```bash
docker compose up -d
docker compose ps            # web and db "healthy"
```

Open the application: everyone logs in once (sessions are now stored in the database). Check a few
projects and the totals.

**7. Keep the old files** (`projects.json`, `project/`, backups) as an archive. v2 does not use them
and does not serve them any more.

### If the migration reports a problem

Nothing has been written. The report lists each faulty value (`ERROR project #12 "…": startDate: "31/02/2026"
is not a valid date`). Fix the value in the JSON file and run the command again. Warnings (e.g. two files
with the same id, left by bug 1.5) are not blocking: both projects are kept, the second one gets a new id.

To start over after a completed migration: `--replace` (deletes the projects currently in the database,
the history is kept), or `docker compose down -v` to delete the whole database volume.

### Rolling back to v1

v1 data files are untouched by the migration. `git checkout 67d5175 && docker compose up -d --build`
restarts v1 with the data as it was at step 1 (changes made in v2 afterwards would have to be
exported from v2 and re-imported).

### Browsers that still have v1 open

A v1 tab left open cannot damage anything: its saves are refused (it shows *Save failed*). After a
reload it runs v2. If v1 had kept unsaved time in that browser, v2 offers to restore only the recorded
times that are higher than on the server — never the rest of the old copy.
