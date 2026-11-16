-- name: v1.9.0 — 통합로그인 플랫폼 (접근 제한 · features · 전역 설정 · 로그 · 감사 · 문서 테이블 · 사용량)
--
-- 대상: 0006 까지 적용된 기존 DB
-- 신규 설치는 이 파일을 적용하지 않는다 — schema.sql 이 이미 이 표·컬럼을 갖고 있고,
-- install.php 가 status='baseline' 으로 기록만 한다. 두 경로의 최종 형태가 같은지는
-- `php server/migrate.php verify` 가 대조한다(SQLite·MySQL 둘 다).
--
-- 무엇이 생기나 (설계 `_workspace/116_architect_v190_platform.md` §3 · 판정 D-354)
--   services       + access_rule · can_list_users · features_json · platform_json
--   user_services  + access_grant · features_json · idx_us_access_grant
--   rc_drafts · rc_versions          전역 설정 초안·게시본
--   plat_logs · plat_audit           로그 사본 · 감사(HMAC 체인)
--   plat_collections · plat_docs     문서 테이블
--   plat_usage                       사용량
--   login_logs.result COMMENT        + fail_service_access
--   씨앗(H-7): manager·office 의 access_rule='admin'(손댄 값은 보존), manager 의 can_list_users=1
--
-- 규약은 0001 ~ 0006 과 같다. 모든 DDL 문장 바로 앞에 skip-if 가드. 표 생성은 `CREATE TABLE FROM SCHEMA`.
-- 컬럼 정의는 schema.sql 과 **글자 그대로 같아야 한다.** 위치를 맞추지 않는다(AFTER 없음).
-- 방언: ENUM · `ON DUPLICATE KEY` · `FOR UPDATE` 0회.
-- 🔴 적용 순서(D-347 Q-6): DB 사본(-wal/-shm 포함) → apply → verify → 접합 전환. **apply 가 접합보다 먼저.**


-- ---------------------------------------------------------------------
-- 1) 서비스 접근 규칙 · 계정 목록 권한 · 기능 등록부 · 플랫폼 상한
-- ---------------------------------------------------------------------
-- @skip-if: column-exists app_ulogin_services access_rule
ALTER TABLE `app_ulogin_services` ADD COLUMN `access_rule` VARCHAR(16) NOT NULL DEFAULT 'all' COMMENT 'all|granted|admin. 그 밖의 값은 거절(fail-closed)';

-- @skip-if: column-exists app_ulogin_services can_list_users
ALTER TABLE `app_ulogin_services` ADD COLUMN `can_list_users` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1이면 sso/users.php 계정 목록 허용. 재등록하면 0';

-- @skip-if: column-exists app_ulogin_services features_json
ALTER TABLE `app_ulogin_services` ADD COLUMN `features_json` TEXT NULL COMMENT 'NULL=ulogin이 기능을 관리하지 않음. 배열 [{id,name,default}] 64개 이하';

-- @skip-if: column-exists app_ulogin_services platform_json
ALTER TABLE `app_ulogin_services` ADD COLUMN `platform_json` TEXT NULL COMMENT '객체 {logs_from_users,quota}. 서비스별 상한은 기본값보다 낮게만';


-- ---------------------------------------------------------------------
-- 2) 계정별 접근 허용·차단 · 기능 지정
-- ---------------------------------------------------------------------
-- @skip-if: column-exists app_ulogin_user_services access_grant
ALTER TABLE `app_ulogin_user_services` ADD COLUMN `access_grant` VARCHAR(8) NOT NULL DEFAULT 'inherit' COMMENT 'inherit|allow|deny. deny 는 어느 규칙보다 우선';

-- @skip-if: column-exists app_ulogin_user_services features_json
ALTER TABLE `app_ulogin_user_services` ADD COLUMN `features_json` TEXT NULL COMMENT 'NULL=서비스 기본값. 배열=그 id 만(등록부와 교집합)';

-- @skip-if: index-exists app_ulogin_user_services idx_us_access_grant
ALTER TABLE `app_ulogin_user_services` ADD KEY `idx_us_access_grant` (`service_id`, `access_grant`);


-- ---------------------------------------------------------------------
-- 3) 새 표 7개 — 정본 DDL 은 schema.sql 한 곳
-- ---------------------------------------------------------------------
-- @skip-if: table-exists app_ulogin_rc_drafts
CREATE TABLE FROM SCHEMA app_ulogin_rc_drafts;

-- @skip-if: table-exists app_ulogin_rc_versions
CREATE TABLE FROM SCHEMA app_ulogin_rc_versions;

-- @skip-if: table-exists app_ulogin_plat_logs
CREATE TABLE FROM SCHEMA app_ulogin_plat_logs;

-- @skip-if: table-exists app_ulogin_plat_audit
CREATE TABLE FROM SCHEMA app_ulogin_plat_audit;

-- @skip-if: table-exists app_ulogin_plat_collections
CREATE TABLE FROM SCHEMA app_ulogin_plat_collections;

-- @skip-if: table-exists app_ulogin_plat_docs
CREATE TABLE FROM SCHEMA app_ulogin_plat_docs;

-- @skip-if: table-exists app_ulogin_plat_usage
CREATE TABLE FROM SCHEMA app_ulogin_plat_usage;


-- ---------------------------------------------------------------------
-- 4) login_logs.result 값 목록 (COMMENT 만 — SQLite 는 no-op, 정의가 같을 때만)
-- ---------------------------------------------------------------------
-- @skip-if: never
ALTER TABLE `app_ulogin_login_logs`
  MODIFY COLUMN `result` VARCHAR(30) NOT NULL
  COMMENT 'success|success_silent|issued_silent|fail_credential|fail_locked|fail_status|fail_ratelimit|fail_service_blocked|fail_service_access|fail_client_auth|fail_migrate_auth|fail_refresh_reuse|fail_refresh_revoked|logout';


-- ---------------------------------------------------------------------
-- 5) 씨앗 (H-7: office id 는 그대로). 손댄 값은 보존 — 기본값 'all' 인 행만 바꾼다.
--    씨앗은 별칭(former_service_id)을 보지 않는다. 데이터 문장이라 가드가 필요 없다(멱등).
-- ---------------------------------------------------------------------
UPDATE `app_ulogin_services` SET `access_rule` = 'admin' WHERE `service_id` IN ('manager', 'office') AND `access_rule` = 'all';

UPDATE `app_ulogin_services` SET `can_list_users` = 1 WHERE `service_id` = 'manager' AND `can_list_users` = 0;
