ALTER TABLE reservation
  ADD COLUMN IF NOT EXISTS "editCount" integer NOT NULL DEFAULT 0;

CREATE INDEX IF NOT EXISTS reservation_editable_status_end_idx
  ON reservation (status, "endTime")
  WHERE status IN ('pending', 'approved');
