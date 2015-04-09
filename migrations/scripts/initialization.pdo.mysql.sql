# noinspection SqlNoDataSourceInspection
CREATE TABLE IF NOT EXISTS `{{migrations.table.name}}` (
  `name`      TEXT NOT NULL,
  `type`      CHAR(10) NOT NULL,
  `created`   CHAR(19) NOT NULL,
  `applied`   CHAR(19) NOT NULL,
  `script`    TEXT NOT NULL,
  `options`   TEXT NULL,
  `signature` TEXT NOT NULL,
  INDEX `{{migrations.table.name}}_index` (`name`(32))
)
  COLLATE='utf8_general_ci'
  ENGINE=InnoDB
;
