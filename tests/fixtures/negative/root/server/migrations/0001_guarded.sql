-- negative control migration
-- @skip-if: SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_NAME='ng_users' AND COLUMN_NAME='note'
ALTER TABLE `ng_users` ADD COLUMN `note` VARCHAR(50) NULL;
