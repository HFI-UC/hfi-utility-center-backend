-- Apply once after 003_roles_archive.sql. Existing reservation snapshots stay intact.
-- Only emails whose nonblank historical name/class mapping is unambiguous are
-- imported. Resolve any conflicting email manually in student after migration.
CREATE TABLE IF NOT EXISTS student (
  email VARCHAR(191) NOT NULL,
  name VARCHAR(191) NOT NULL,
  classId INT NULL,
  PRIMARY KEY (email),
  KEY student_class (classId),
  CONSTRAINT student_class_fk FOREIGN KEY (classId) REFERENCES class (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO student (email, name, classId)
SELECT LOWER(TRIM(email)), MIN(TRIM(studentName)), MIN(classId)
FROM reservation
WHERE TRIM(email) <> ''
GROUP BY LOWER(TRIM(email))
HAVING SUM(TRIM(studentName) = '') = 0
   AND COUNT(DISTINCT BINARY CONCAT(TRIM(studentName), '#', COALESCE(classId, -1))) = 1;

ALTER TABLE reservation DROP COLUMN studentId;
