-- name: v1.8.4 — 시스템 계정 가져오기·연결 (ext_accounts · link_jobs · link_job_items · password_resets.purpose)
--
-- 대상: 0004 까지 적용된 기존 DB
-- 신규 설치는 이 파일을 적용하지 않는다 — schema.sql 이 이미 이 표·컬럼을 갖고 있고,
-- install.php 가 status='baseline' 으로 기록만 한다. 두 경로의 최종 형태가 같은지는
-- `php server/migrate.php verify` 가 대조한다(SQLite·MySQL 둘 다).
--
-- 무엇이 생기나 (설계 `_workspace/92_architect_v182_account_link_spec.md` C절)
--   ext_accounts    다른 시스템이 보낸 로컬 계정 스냅숏 + ulogin 계정과의 연결(진실은 user_id 한 컬럼)
--   link_jobs       plan · push · sync_request · system 작업
--   link_job_items  작업 항목. id 가 시스템용 사건 피드 커서다
--   password_resets.purpose   'reset'(비밀번호 찾기, 30분) | 'setup'(이관 계정의 첫 비밀번호 설정, 72h)
--
-- 번호: 리더 판정 D-324 H-1 — 이 판이 0005, v1.9.0 의 접근 제한은 0006 부터.
--
-- 규약은 0001 ~ 0004 와 같다. 모든 DDL 문장 바로 앞에 skip-if 가드를 둔다 —
-- 가드 없는 DDL 은 러너(mig_lint)가 파일 자체를 거부한다.
-- 테이블 생성은 `CREATE TABLE FROM SCHEMA` 로 적는다(정본 DDL 은 schema.sql 한 곳).
-- 방언: ENUM · `ON DUPLICATE KEY` · `FOR UPDATE` 0회. 동시성은 조건부 UPDATE + UNIQUE 가 진다.


-- ---------------------------------------------------------------------
-- 1) 외부 계정 스냅숏 + 연결
-- ---------------------------------------------------------------------
-- @skip-if: table-exists app_ulogin_ext_accounts
CREATE TABLE FROM SCHEMA app_ulogin_ext_accounts;


-- ---------------------------------------------------------------------
-- 2) 작업
-- ---------------------------------------------------------------------
-- @skip-if: table-exists app_ulogin_link_jobs
CREATE TABLE FROM SCHEMA app_ulogin_link_jobs;


-- ---------------------------------------------------------------------
-- 3) 작업 항목 (사건 피드 원장)
-- ---------------------------------------------------------------------
-- @skip-if: table-exists app_ulogin_link_job_items
CREATE TABLE FROM SCHEMA app_ulogin_link_job_items;


-- ---------------------------------------------------------------------
-- 4) 설정 링크 구분 컬럼
--    기존 행은 전부 비밀번호 찾기에서 나왔다 — 'reset' 이 사실이다.
--    위치를 맞추지 않는다(AFTER 없음). schema.sql 도 이 컬럼을 맨 뒤에 둔다 —
--    그래서 신규 설치와 업그레이드의 컬럼 순서가 같다.
--    컬럼 정의는 schema.sql 의 `app_ulogin_password_resets` 와 **글자 그대로 같아야 한다.**
-- ---------------------------------------------------------------------
-- @skip-if: column-exists app_ulogin_password_resets purpose
ALTER TABLE `app_ulogin_password_resets`
  ADD COLUMN `purpose` VARCHAR(12) NOT NULL DEFAULT 'reset' COMMENT 'reset|setup. setup=이관 계정의 첫 비밀번호 설정 링크(72h)';
