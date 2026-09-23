## Learned User Preferences

- Keep business logic equivalent to the Rust backend; response identity strings may change (health service name is hfiuc-php).
- Use Slim 4 for the PHP API.
- Store secret configuration in the repository root `.env`. PHPUnit database settings live in `tests/.env`.
- Persist detailed error logs and audit logs in MySQL.
- After every backend change that affects routes, request or response shapes, status codes, or auth, update the OpenAPI document in the same change so it stays in sync with the PHP API.
- Cover critical business logic, including database behavior, with PHPUnit.

## Learned Workspace Facts

- The HFI Utility Center backend is being rewritten from Rust and PostgreSQL to PHP 8.4, MySQL 5.6.51, Apache 2.4.54, and PHP-FPM.
- Admins with no `roomapprover` rows are super admins.
- Office Teachers reservations override room policy, conflicts, daily limits, and `roomapprover`.
- Admins who can manage a room all see and can modify that room's reservations; one admin approving a reservation does not hide it from the others.
- Moving a reservation to another room requires approval rights on both rooms.
- New-reservation notifications go only to admins who can approve that room and have notifications enabled.
- Application logs are stored in the MySQL tables `errorlog` and `auditlog`.
- XLSX export uses PHP `ext-zip`.
- PostgreSQL data is migrated by CSV export, conversion, then MySQL import.
- PHPUnit runs against a dedicated MySQL database named `uc_test`, separate from the application database.
- Mail and AI-approval jobs are published to Cloudflare Queue `uc` through the Queues HTTP API. A queue consumer forwards each message to `POST /internal/outbox/process` on `https://api.hfiuc.org`.
