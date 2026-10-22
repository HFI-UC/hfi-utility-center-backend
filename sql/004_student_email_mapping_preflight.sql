-- Run before 004_student_email_mapping.sql to find email addresses that need
-- manual student registration. The migration does not guess among these rows.
SELECT LOWER(TRIM(email)) AS email,
       COUNT(*) AS reservationCount,
       COUNT(DISTINCT BINARY CONCAT(TRIM(studentName), '#', COALESCE(classId, -1))) AS profileCount,
       SUM(TRIM(studentName) = '') AS blankNames
FROM reservation
WHERE TRIM(email) <> ''
GROUP BY LOWER(TRIM(email))
HAVING profileCount <> 1 OR blankNames <> 0;
