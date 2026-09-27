-- Read-only orphan report. Any row must be reviewed and repaired before running
-- 003_roles_archive.sql; that migration aborts on the first foreign-key failure.
SELECT 'room' AS referenceType, r.id AS reservationId, r.roomId AS missingId
FROM reservation r LEFT JOIN room x ON x.id = r.roomId
WHERE r.roomId IS NOT NULL AND x.id IS NULL
UNION ALL
SELECT 'class', r.id, r.classId
FROM reservation r LEFT JOIN class x ON x.id = r.classId
WHERE r.classId IS NOT NULL AND x.id IS NULL
UNION ALL
SELECT 'admin', r.id, r.latestExecutorId
FROM reservation r LEFT JOIN admin x ON x.id = r.latestExecutorId
WHERE r.latestExecutorId IS NOT NULL AND x.id IS NULL;
