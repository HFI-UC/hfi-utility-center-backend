\set ON_ERROR_STOP on

BEGIN;

DELETE FROM roompolicy;
INSERT INTO roompolicy
SELECT * FROM roompolicy_legacy_unavailable;

DELETE FROM _sqlx_migrations
WHERE version IN (8, 9);

COMMIT;
