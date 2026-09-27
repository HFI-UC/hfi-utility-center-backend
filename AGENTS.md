## Learned User Preferences

- Keep business logic equivalent to the Rust backend; response identity strings may change (health service name is hfiuc-php).
- Use Slim 4 for the PHP API.
- Store secret configuration in the repository root `.env` next to `composer.json`, not inside `public/`. PHPUnit database settings live in `tests/.env`.
- 遵循奥卡姆剃刀原则：如无必要，不新增实体、功能、抽象或依赖；优先复用和扩展已有代码与数据结构，避免重复实现。
- Persist detailed error logs and audit logs in the MySQL tables `errorlog` and `auditlog`.
- After every backend change that affects routes, request or response shapes, status codes, or auth, update the OpenAPI document in the same change so it stays in sync with the PHP API.
- Cover critical business logic, including database behavior, with PHPUnit.

## Learned Workspace Facts

- The HFI Utility Center backend is being rewritten from Rust and PostgreSQL to PHP 8.4, MySQL 5.6.51, Apache 2.4.54, and PHP-FPM.
- Admins with `role=global` can manage every room; `role=room` admins are scoped by `roomapprover`. Migration maps the former empty-`roomapprover` super admins to `global`.
- Reservations submitted with an administrator email are priority reservations. They override room policy, conflicts, daily limits, and `roomapprover`; final creation requires preview confirmation and cancels active overlaps.
- Admins who can manage a room all see and can modify that room's reservations; one admin approving a reservation does not hide it from the others.
- Moving a reservation to another room requires approval rights on both rooms.
- New-reservation notifications go only to admins who can approve that room and have notifications enabled.
- XLSX export uses PHP `ext-zip`.
- `TURNSTILE_VERIFY_SSL` in the root `.env` controls SSL certificate verification for the Turnstile siteverify request. The default is true; false disables peer and host verification.
- PostgreSQL data is migrated by CSV export, conversion, then MySQL import.
- PHPUnit runs against a dedicated MySQL database named `uc_test`, separate from the application database.
- Mail jobs are written to MySQL outbox within the business transaction, published to Cloudflare Queue `uc` immediately after commit, and executed by the Queue consumer through `POST /tasks/{taskId}/execute`. A Durable Object Alarm calls `GET /tasks` every 30 seconds to recover missed publication and dispatch AI tasks due after 15 minutes.
- The health check route is `GET /health`, and `GET /` does not serve `public/index.html`. `DEBUG=true` makes every failed API response include an `error` object with the exception type, file, line, trace, field or Turnstile detail, and the previous exception; `DEBUG=false` keeps only the public message.
