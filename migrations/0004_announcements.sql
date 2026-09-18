CREATE TABLE IF NOT EXISTS announcement (
    id integer PRIMARY KEY,
    title varchar(120) NOT NULL DEFAULT '',
    content text NOT NULL DEFAULT '',
    enabled boolean NOT NULL DEFAULT false,
    "updatedAt" timestamp without time zone NOT NULL DEFAULT now(),
    "updatedBy" integer REFERENCES admin(id) ON DELETE SET NULL
);
