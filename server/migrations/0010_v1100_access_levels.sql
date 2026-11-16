-- name: v1.10.0 — 접근 단계 · 그룹 · 계정 덮어쓰기 기간 · 비상 관리자 계정 표지
--
-- 대상: 0009 까지 적용된 기존 DB. 신규 설치는 schema.sql 이 이미 이 표·컬럼을 갖는다(baseline).
-- 설계 `_workspace/127_architect_v1100_design.md` §1 · 판정 D-367.
--   services        + level_aware · access_rule COMMENT(all_view)
--   user_services   + access_level · access_starts_at · access_ends_at · access_note · access_updated_by · access_updated_at
--   users           + account_kind
--   ext_accounts    + is_emergency
--   access_groups · access_group_members · access_group_services   (새 표 3개)
--   login_logs.result COMMENT  + fail_emergency_unset · fail_emergency_scope
-- **기존 데이터는 옮기지 않는다(K6).** 씨앗 문장도 없다. 0010 직후 모든 계정·서비스의 판정 결과가 v1.9.3 과 같다.
-- 규약은 0007 ~ 0009 와 같다. 모든 DDL 앞에 skip-if 가드. 컬럼 정의는 schema.sql 과 글자 그대로 같다.
-- 방언: ENUM · `ON DUPLICATE KEY` · `FOR UPDATE` 0회.
-- 🔴 적용 순서: DB 사본(-wal/-shm 포함) → apply → verify → 접합 전환. **apply 가 접합보다 먼저.**


-- ---------------------------------------------------------------------
-- 1) 서비스 — 단계 인지 선언 · 규칙 값 목록
-- ---------------------------------------------------------------------
-- @skip-if: column-exists app_ulogin_services level_aware
ALTER TABLE `app_ulogin_services` ADD COLUMN `level_aware` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=서비스가 view 단계를 이해함(K4). 재등록하면 0';

-- @skip-if: never
ALTER TABLE `app_ulogin_services`
  MODIFY COLUMN `access_rule` VARCHAR(16) NOT NULL DEFAULT 'all'
  COMMENT 'all|all_view|granted|admin. 그 밖의 값은 거절(fail-closed)';


-- ---------------------------------------------------------------------
-- 2) 계정 직접 덮어쓰기(K2) — 단계 · 기간 · 메모
-- ---------------------------------------------------------------------
-- @skip-if: column-exists app_ulogin_user_services access_level
ALTER TABLE `app_ulogin_user_services` ADD COLUMN `access_level` VARCHAR(8) NULL COMMENT 'view|use|admin. access_grant=allow 일 때만 의미. NULL=use';

-- @skip-if: column-exists app_ulogin_user_services access_starts_at
ALTER TABLE `app_ulogin_user_services` ADD COLUMN `access_starts_at` DATETIME NULL COMMENT 'allow·deny 유효 시작. NULL=제한 없음';

-- @skip-if: column-exists app_ulogin_user_services access_ends_at
ALTER TABLE `app_ulogin_user_services` ADD COLUMN `access_ends_at` DATETIME NULL COMMENT 'allow·deny 유효 끝(이 시각부터 무효). NULL=무기한';

-- @skip-if: column-exists app_ulogin_user_services access_note
ALTER TABLE `app_ulogin_user_services` ADD COLUMN `access_note` VARCHAR(255) NULL COMMENT '관리자 메모';

-- @skip-if: column-exists app_ulogin_user_services access_updated_by
ALTER TABLE `app_ulogin_user_services` ADD COLUMN `access_updated_by` BIGINT UNSIGNED NULL;

-- @skip-if: column-exists app_ulogin_user_services access_updated_at
ALTER TABLE `app_ulogin_user_services` ADD COLUMN `access_updated_at` DATETIME NULL;


-- ---------------------------------------------------------------------
-- 3) 비상 관리자 계정 표지(§6)
-- ---------------------------------------------------------------------
-- @skip-if: column-exists app_ulogin_users account_kind
ALTER TABLE `app_ulogin_users` ADD COLUMN `account_kind` VARCHAR(12) NOT NULL DEFAULT 'normal' COMMENT 'normal|emergency';

-- @skip-if: column-exists app_ulogin_ext_accounts is_emergency
ALTER TABLE `app_ulogin_ext_accounts` ADD COLUMN `is_emergency` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=시스템이 비상 계정으로 표시(extra.kind). 연결 대상 아님';


-- ---------------------------------------------------------------------
-- 4) 그룹 표 3개 — 정본 DDL 은 schema.sql 한 곳
-- ---------------------------------------------------------------------
-- @skip-if: table-exists app_ulogin_access_groups
CREATE TABLE FROM SCHEMA app_ulogin_access_groups;

-- @skip-if: table-exists app_ulogin_access_group_members
CREATE TABLE FROM SCHEMA app_ulogin_access_group_members;

-- @skip-if: table-exists app_ulogin_access_group_services
CREATE TABLE FROM SCHEMA app_ulogin_access_group_services;


-- ---------------------------------------------------------------------
-- 5) login_logs.result 값 목록 (COMMENT 만 — SQLite 는 no-op, 정의가 같을 때만)
-- ---------------------------------------------------------------------
-- @skip-if: never
ALTER TABLE `app_ulogin_login_logs`
  MODIFY COLUMN `result` VARCHAR(30) NOT NULL
  COMMENT 'success|success_silent|issued_silent|fail_credential|fail_locked|fail_status|fail_ratelimit|fail_service_blocked|fail_service_access|fail_emergency_unset|fail_emergency_scope|fail_client_auth|fail_migrate_auth|fail_refresh_reuse|fail_refresh_revoked|logout';
