\set ON_ERROR_STOP on

\i scripts/preflight.sql

SELECT version, description, success
FROM _sqlx_migrations
ORDER BY version;

SELECT 'legacy_roompolicy_snapshot' AS check_name, count(*)::bigint AS value
FROM roompolicy_legacy_unavailable;

SELECT 'active_roompolicy_count' AS check_name, count(*)::bigint AS value
FROM roompolicy;
