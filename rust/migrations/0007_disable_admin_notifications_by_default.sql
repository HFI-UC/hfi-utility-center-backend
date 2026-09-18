ALTER TABLE admin
  ALTER COLUMN "receiveReservationNotifications" SET DEFAULT false;

UPDATE admin
SET "receiveReservationNotifications" = false
WHERE "receiveReservationNotifications" = true;

UPDATE outboxjob
SET status = 'completed',
    "completedAt" = now(),
    "lastError" = 'Cancelled because administrator reservation notifications were disabled globally.'
WHERE kind = 'admin_reservation_notification'
  AND status IN ('pending', 'processing');
