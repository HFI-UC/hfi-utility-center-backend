\set ON_ERROR_STOP on

SELECT 'reservation_count' AS check_name, count(*)::bigint AS value FROM reservation;
SELECT 'reservation_max_id' AS check_name, COALESCE(max(id), 0)::bigint AS value FROM reservation;
SELECT 'reservation_without_room' AS check_name, count(*)::bigint AS value
  FROM reservation WHERE "roomId" IS NULL;
SELECT 'reservation_without_class' AS check_name, count(*)::bigint AS value
  FROM reservation WHERE "classId" IS NULL;
SELECT 'reservation_without_student_id' AS check_name, count(*)::bigint AS value
  FROM reservation WHERE "studentId" IS NULL;
SELECT 'invalid_status_count' AS check_name, count(*)::bigint AS value
  FROM reservation WHERE status NOT IN ('pending', 'approved', 'rejected', 'cancelled');
SELECT 'invalid_range_count' AS check_name, count(*)::bigint AS value
  FROM reservation WHERE "startTime" >= "endTime";
SELECT 'overlap_count' AS check_name, count(*)::bigint AS value
  FROM reservation a JOIN reservation b
    ON a.id < b.id AND a."roomId" = b."roomId"
   AND a.status NOT IN ('rejected', 'cancelled')
   AND b.status NOT IN ('rejected', 'cancelled')
   AND a."startTime" < b."endTime" AND b."startTime" < a."endTime";
SELECT 'duplicate_room_approver_pairs' AS check_name, count(*)::bigint AS value
  FROM (
    SELECT "roomId", "adminId"
    FROM roomapprover
    GROUP BY "roomId", "adminId"
    HAVING count(*) > 1
  ) duplicates;

SELECT md5(string_agg(row_fingerprint, '' ORDER BY id)) AS reservation_fingerprint
FROM (
  SELECT id, md5(concat_ws('|', id, "roomId", "startTime", "endTime", "studentName",
    email, reason, "classId", "studentId", status, "createdAt")) AS row_fingerprint
  FROM reservation
) rows;
