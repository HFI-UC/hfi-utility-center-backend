-- Apply once to existing MySQL 5.6 installations, after 002_drop_campus_is_privileged.sql.
-- Back up the database first and run 003_roles_archive_preflight.sql. Repair
-- any reported orphan references before applying this migration.
-- Foreign-key creation fails if any orphan remains, before any role or archive
-- columns are changed. No reservation association is silently discarded.

ALTER TABLE reservation
  ADD KEY reservation_class (classId),
  ADD KEY reservation_latest_executor (latestExecutorId),
  ADD CONSTRAINT reservation_room_fk FOREIGN KEY (roomId) REFERENCES room (id),
  ADD CONSTRAINT reservation_class_fk FOREIGN KEY (classId) REFERENCES class (id),
  ADD CONSTRAINT reservation_latest_executor_fk FOREIGN KEY (latestExecutorId) REFERENCES admin (id);

ALTER TABLE reservation
  ADD COLUMN reviewVersion INT NOT NULL DEFAULT 0 AFTER editCount;

ALTER TABLE admin
  ADD COLUMN role ENUM('global', 'room') NOT NULL DEFAULT 'room' AFTER password;

UPDATE admin a
SET a.role = 'global'
WHERE NOT EXISTS (SELECT 1 FROM roomapprover ra WHERE ra.adminId = a.id);

ALTER TABLE campus
  ADD COLUMN deletedAt DATETIME NULL,
  ADD COLUMN deletedBy INT NULL;

ALTER TABLE class
  ADD COLUMN deletedAt DATETIME NULL,
  ADD COLUMN deletedBy INT NULL;

ALTER TABLE room
  ADD COLUMN deletedAt DATETIME NULL,
  ADD COLUMN deletedBy INT NULL;

ALTER TABLE outboxjob
  MODIFY COLUMN payload MEDIUMTEXT NOT NULL,
  ADD COLUMN dispatchToken CHAR(64) NULL AFTER lockToken,
  ADD COLUMN leaseUntil DATETIME NULL AFTER dispatchToken,
  ADD COLUMN publishedAt DATETIME NULL AFTER leaseUntil,
  ADD KEY outboxjob_lease (status, leaseUntil);
