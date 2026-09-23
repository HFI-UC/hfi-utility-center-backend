# HFI Utility Center PHP Backend

The PHP API for HFI Utility Center. It keeps the reservation, approval, and
notification rules while storing data in MySQL.

## Technology stack

- PHP 8.4, Slim 4, and PHP-FPM
- MySQL 5.6 with the schema in `sql/001_schema.sql`
- Apache serving `public/index.php`
- PHPMailer for reservation email
- Cloudflare Queues for durable outbox delivery
- PHPUnit for business and database tests

## Layout

- `public/index.php`: front controller.
- `src/Http/Application.php`: routes, CORS, and error responses.
- `src/Auth/`: CSRF tokens, administrator sessions, and Turnstile checks.
- `src/Catalog/`: campuses, classes, rooms, policies, and administrators.
- `src/Reservation/`: creation, availability, review, editing, cancellation, and XLSX export.
- `src/Worker/`: outbox publishing and job processing.
- `cloudflare/outbox-consumer/`: queue consumer that posts each job to `/internal/outbox/process`.
- `openapi.yaml`: HTTP contract.
- `tests/`: PHPUnit suite. Database tests use the separate `uc_test` database configured in `tests/.env`.

Secrets belong in the repository root `.env`. See `.env.example`.

## Local checks

```sh
composer install
composer test
```
