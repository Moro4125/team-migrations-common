# noinspection SqlNoDataSourceInspection
CREATE TABLE IF NOT EXISTS `{{migrations.table.name}}` (
  `name`      CHAR(64) NOT NULL,
  `type`      CHAR(10) NOT NULL,
  `created`   CHAR(19) NOT NULL,
  `applied`   CHAR(19) NOT NULL,
  `script`    TEXT NOT NULL,
  `options`   TEXT NULL,
  `signature` CHAR(40) NOT NULL,
  INDEX `idx_{{migrations.table.name}}` (`name`)
)
  COLLATE='utf8_general_ci'
  ENGINE=InnoDB
;
