-- HFI Utility Center MySQL 5.6.51 schema. InnoDB, utf8mb4.
-- Session time zone is set to +08:00 by the application.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS campus (
  id INT NOT NULL AUTO_INCREMENT,
  name VARCHAR(191) NOT NULL,
  isPrivileged TINYINT(1) NOT NULL DEFAULT 0,
  createdAt DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS class (
  id INT NOT NULL AUTO_INCREMENT,
  name VARCHAR(191) NOT NULL,
  campusId INT NULL,
  createdAt DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY class_campus (campusId),
  CONSTRAINT class_campus_fk FOREIGN KEY (campusId) REFERENCES campus (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS room (
  id INT NOT NULL AUTO_INCREMENT,
  name VARCHAR(191) NOT NULL,
  campusId INT NULL,
  enabled TINYINT(1) NULL,
  createdAt DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY room_campus (campusId),
  CONSTRAINT room_campus_fk FOREIGN KEY (campusId) REFERENCES campus (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roompolicy (
  id INT NOT NULL AUTO_INCREMENT,
  roomId INT NOT NULL,
  days TEXT NOT NULL,
  startTime TEXT NOT NULL,
  endTime TEXT NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY roompolicy_room (roomId),
  CONSTRAINT roompolicy_room_fk FOREIGN KEY (roomId) REFERENCES room (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin (
  id INT NOT NULL AUTO_INCREMENT,
  name VARCHAR(191) NOT NULL,
  email VARCHAR(191) NOT NULL,
  password VARCHAR(255) NOT NULL,
  receiveReservationNotifications TINYINT(1) NOT NULL DEFAULT 0,
  createdAt DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY admin_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roomapprover (
  roomId INT NOT NULL,
  adminId INT NOT NULL,
  PRIMARY KEY (roomId, adminId),
  KEY roomapprover_admin (adminId, roomId),
  CONSTRAINT roomapprover_room_fk FOREIGN KEY (roomId) REFERENCES room (id) ON DELETE CASCADE,
  CONSTRAINT roomapprover_admin_fk FOREIGN KEY (adminId) REFERENCES admin (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS adminlogin (
  id INT NOT NULL AUTO_INCREMENT,
  email VARCHAR(191) NOT NULL,
  cookie VARCHAR(191) NOT NULL,
  expiry DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY adminlogin_cookie (cookie),
  KEY adminlogin_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tempadminlogin (
  id INT NOT NULL AUTO_INCREMENT,
  token VARCHAR(191) NOT NULL,
  email VARCHAR(191) NOT NULL,
  createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY tempadminlogin_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reservation (
  id INT NOT NULL AUTO_INCREMENT,
  roomId INT NULL,
  classId INT NULL,
  startTime DATETIME NOT NULL,
  endTime DATETIME NOT NULL,
  studentName VARCHAR(191) NOT NULL,
  studentId VARCHAR(32) NULL,
  email VARCHAR(191) NOT NULL,
  reason TEXT NOT NULL,
  status VARCHAR(16) NOT NULL,
  purposeType VARCHAR(16) NULL,
  needsMultimedia TINYINT(1) NOT NULL DEFAULT 0,
  editCount INT NOT NULL DEFAULT 0,
  latestExecutorId INT NULL,
  cancelledAt DATETIME NULL,
  createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY reservation_room_status_time (roomId, status, startTime, endTime),
  KEY reservation_email_created (email, createdAt),
  KEY reservation_status_id (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reservationcanceltoken (
  id INT NOT NULL AUTO_INCREMENT,
  reservationId INT NOT NULL,
  tokenHash CHAR(64) NOT NULL,
  expiresAt DATETIME NOT NULL,
  usedAt DATETIME NULL,
  createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY reservationcanceltoken_hash (tokenHash),
  KEY reservationcanceltoken_reservation (reservationId),
  CONSTRAINT reservationcanceltoken_reservation_fk FOREIGN KEY (reservationId) REFERENCES reservation (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reservationoperationlog (
  id INT NOT NULL AUTO_INCREMENT,
  adminId INT NULL,
  reservationId INT NOT NULL,
  operation VARCHAR(64) NOT NULL,
  reason TEXT NULL,
  createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY reservationoperationlog_reservation (reservationId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS outboxjob (
  id BIGINT NOT NULL AUTO_INCREMENT,
  kind VARCHAR(64) NOT NULL,
  payload TEXT NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  attempts INT NOT NULL DEFAULT 0,
  availableAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  lockedAt DATETIME NULL,
  lockToken CHAR(36) NULL,
  lastError TEXT NULL,
  createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completedAt DATETIME NULL,
  PRIMARY KEY (id),
  KEY outboxjob_claim (status, availableAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcement (
  id INT NOT NULL,
  title VARCHAR(120) NOT NULL DEFAULT '',
  content TEXT NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updatedBy INT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS analytic (
  id INT NOT NULL AUTO_INCREMENT,
  date DATETIME NOT NULL,
  reservations INT NOT NULL DEFAULT 0,
  reservationCreations INT NOT NULL DEFAULT 0,
  requests BIGINT NOT NULL DEFAULT 0,
  approvals INT NOT NULL DEFAULT 0,
  rejections INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY analytic_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS csrftoken (
  token VARCHAR(191) NOT NULL,
  expiresAt DATETIME NOT NULL,
  PRIMARY KEY (token),
  KEY csrftoken_expiry (expiresAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catalogcache (
  cacheKey VARCHAR(32) NOT NULL,
  payload MEDIUMTEXT NOT NULL,
  updatedAt DATETIME NOT NULL,
  PRIMARY KEY (cacheKey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS errorlog (
  id BIGINT NOT NULL AUTO_INCREMENT,
  level VARCHAR(16) NOT NULL,
  message TEXT NOT NULL,
  context TEXT NULL,
  method VARCHAR(8) NULL,
  path VARCHAR(191) NULL,
  requestId VARCHAR(64) NULL,
  createdAt DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY errorlog_created (createdAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auditlog (
  id BIGINT NOT NULL AUTO_INCREMENT,
  adminId INT NULL,
  action VARCHAR(64) NOT NULL,
  entity VARCHAR(64) NOT NULL,
  entityId VARCHAR(64) NULL,
  detail TEXT NULL,
  requestId VARCHAR(64) NULL,
  ip VARCHAR(64) NULL,
  createdAt DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY auditlog_created (createdAt),
  KEY auditlog_action_created (action, createdAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
