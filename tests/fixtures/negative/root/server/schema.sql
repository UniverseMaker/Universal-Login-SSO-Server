-- negative control schema
CREATE TABLE IF NOT EXISTS `ng_users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`id`)
);
CREATE TABLE IF NOT EXISTS `ng_sessions` (
  `id`     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `result` VARCHAR(30) NOT NULL COMMENT 'success|fail_credential|logout',
  PRIMARY KEY (`id`)
);
