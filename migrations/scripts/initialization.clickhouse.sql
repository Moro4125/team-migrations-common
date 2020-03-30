# noinspection SqlNoDataSourceInspection
CREATE TABLE IF NOT EXISTS `{{migrations.table.name}}` (
  `name`      String,
  `type`      String,
  `created`   String,
  `applied`   String,
  `script`    String,
  `options`   Nullable(String),
  `signature` String
) ENGINE = Log