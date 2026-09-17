ALTER TABLE admin
  ADD COLUMN IF NOT EXISTS "receiveReservationNotifications" boolean NOT NULL DEFAULT true;

CREATE INDEX IF NOT EXISTS admin_reservation_notification_idx
  ON admin (id)
  WHERE "receiveReservationNotifications" = true;
