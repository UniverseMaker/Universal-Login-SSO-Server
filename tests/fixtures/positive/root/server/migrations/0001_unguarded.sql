-- fixture migration
-- PLANTED: the guard comment is absent above the ALTER -> chk_migration_guards
ALTER TABLE `fx_users` ADD COLUMN `planted` TINYINT(1) NOT NULL DEFAULT 0;
