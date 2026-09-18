DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'hfiuc_app') THEN
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE announcement TO hfiuc_app;
  END IF;
END
$$;
