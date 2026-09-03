-- fixture schema
CREATE TABLE IF NOT EXISTS `fx_users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`id`)
);
CREATE TABLE IF NOT EXISTS `fx_sessions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`id`)
);
-- PLANTED: no T_* constant for this table -> chk_table_constants
CREATE TABLE IF NOT EXISTS `fx_unconstanted` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- PLANTED: COMMENT omits 'fail_planted' which login.php writes -> chk_login_result_values
  `result` VARCHAR(30) NOT NULL COMMENT 'success|fail_credential|logout',
  PRIMARY KEY (`id`)
);
