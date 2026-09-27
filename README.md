# HFI Utility Center PHP Backend

The PHP API for HFI Utility Center. It keeps the reservation, approval, and
notification rules while storing data in MySQL.

## Technology stack

- PHP 8.4, Slim 4, and PHP-FPM
- MySQL 5.6 with the schema in `sql/001_schema.sql`
- Apache serving `public/index.php`
- PHPMailer for reservation email
- Cloudflare Queues for immediate outbox delivery; a Durable Object Alarm recovers missed or delayed tasks every 30 seconds
- PHPUnit for business and database tests

## Layout

- `public/index.php`: front controller.
- `src/Http/Application.php`: routes, CORS, and error responses.
- `src/Auth/`: CSRF tokens, administrator sessions, and Turnstile checks.
- `src/Catalog/`: campuses, classes, rooms, policies, and administrators.
- `src/Reservation/`: creation, availability, review, editing, cancellation, and XLSX export.
- `src/Worker/`: transaction-safe outbox storage, immediate post-commit publishing, and PHP job execution.
- `cloudflare/outbox-consumer/`: Queue consumer that calls `/tasks/{taskId}/execute`; a Durable Object Alarm claims due tasks from `GET /tasks` for recovery and delayed AI review.
- `openapi.yaml`: HTTP contract.
- `tests/`: PHPUnit suite. Database tests use the separate `uc_test` database configured in `tests/.env`.

Secrets belong in the repository root `.env`. See `.env.example`.

Existing databases created before the removal of `campus.isPrivileged` must run
`sql/002_drop_campus_is_privileged.sql` once before deploying this version. New
databases use `sql/001_schema.sql` and do not need the migration.

Existing databases must also run the read-only `sql/003_roles_archive_preflight.sql`,
repair any orphan reservation references it reports, then run
`sql/003_roles_archive.sql` during the maintenance window. The migration adds
explicit administrator roles, catalog archival, AI review versions, foreign keys,
and outbox dispatch leases. Back up the database before applying it.

Set `TASK_PULL_SECRET` and `TASK_EXECUTE_SECRET` in the PHP root `.env` and as
Cloudflare Worker secrets. The production Worker consumes queue `uc`; its `dev`
environment uses an isolated `uc-dev` queue and requires a separate dev PHP API.

## Local checks

```sh
composer install
composer test:fast
```

`composer test:fast` runs the pure PHP tests and is intended for the inner
development loop. `composer test:db:smoke` runs the critical remote MySQL
integration files, and `composer test` remains the complete remote `uc_test`
suite for release checks. All database commands use the dedicated remote
`uc_test` database and never a local database. The full remote suite is a
release gate; it is intentionally separate from the fast command because its
feedback time depends on the remote MySQL host.
