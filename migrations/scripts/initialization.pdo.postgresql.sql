CREATE TABLE IF NOT EXISTS {{migrations.table.name}} (
  name VARCHAR(64) NOT NULL,
  type VARCHAR(10) NOT NULL,
  created VARCHAR(19) NOT NULL,
  applied VARCHAR(19) NOT NULL,
  script TEXT NOT NULL,
  options TEXT DEFAULT NULL,
  signature VARCHAR(40) NOT NULL
);

DROP INDEX IF EXISTS idx_{{migrations.table.name}};
CREATE  UNIQUE INDEX idx_{{migrations.table.name}} ON {{migrations.table.name}} (name);