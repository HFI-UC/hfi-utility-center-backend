# HFI Utility Center Rust Backend

<1>

The Rust backend is the production API for HFI Utility Center. It preserves the
legacy PostgreSQL data model while adding availability queries, reservation
editing and cancellation, durable email jobs, AI approval, announcements, and
analytics.

## Technology stack

- Rust 2024, Tokio, Axum, and Tower HTTP
- PostgreSQL 17 with SQLx migrations
- Lettre over authenticated SMTP
- Reqwest for Cloudflare Turnstile and AI approval
- OpenResty for TLS termination and reverse proxying
- systemd for the API and outbox worker
- 1Panel for PostgreSQL, pgAdmin, OpenResty, certificates, and container health

## Architecture

- `src/app.rs`: routes, CORS, request IDs, tracing, and static assets.
- `src/auth.rs`: CSRF tokens, administrator sessions, and Turnstile checks.
- `src/catalog.rs`: campuses, classes, rooms, policies, and administrators.
- `src/reservations.rs`: creation, availability, review, editing, cancellation,
  historical null-safe reads, and XLSX export.
- `src/worker.rs`: durable outbox processing for SMTP and AI approval.
- `migrations/`: forward-only SQLx schema and data migrations.
- `scripts/`: migration reports and the legacy-policy rollback procedure.
- `deploy/`: systemd units, an optional Docker Compose deployment, and an
  OpenResty example.

The HTTP API and worker are the same binary. `HFIUC_MODE=api` starts the server,
`HFIUC_MODE=worker` processes outbox jobs, and `HFIUC_MODE=migrate` applies SQLx
migrations.

## Reservation compatibility

New reservations require a room, time range, name, student ID, email, and a
non-empty detailed reason. `classId` and `purposeType` are optional. Historical
rows with missing rooms, classes, or student IDs remain readable and exportable;
the migration never fills them with invented values.

The Python backend stored unavailable room ranges. Migration `0008` snapshots
those rows and replaces them with their available-time complement between
08:00 and 21:30. The Rust API always treats `roompolicy` as available time.

## Production deployment

The Hong Kong production host runs the binary through the systemd units in
`deploy/`. The API listens only on `127.0.0.1:8002`; 1Panel OpenResty serves
`https://api.hfiuc.org`. PostgreSQL and pgAdmin remain managed by 1Panel. The
legacy Python container stays stopped during the rollback window.

Configuration is loaded from `/etc/hfiuc/backend.env` and
`/etc/hfiuc/rust.env`. Required production values include `DATABASE_URL`, SMTP
credentials, `FRONTEND_URL`, Cloudflare Turnstile settings, and the AI approval
URL, secret, administrator ID, and enabled flag. Secrets must never be committed.

Before a production migration:

1. Run `scripts/preflight.sql` and create a custom-format `pg_dump`.
2. Restore the dump to a temporary database and migrate that copy first.
3. Compare reservation counts, maximum ID, and the reservation fingerprint.
4. Verify converted room policies and the optional class/purpose contract.
5. Stop legacy writes, create a new final backup, then migrate production.
6. Start the API without the worker, run health and CORS checks, switch
   OpenResty, and start the worker last.

If cutover fails before Rust accepts new writes, run
`scripts/rollback_roompolicy.sql`, restore the previous OpenResty upstream, and
restart the Python container. The full database dump remains the authoritative
rollback artifact.

## Local checks

```sh
cargo fmt --check
cargo test --locked
cargo clippy --locked --all-targets -- -D warnings
```
