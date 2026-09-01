-- name: v1.4.0 — 마이그레이션 기반 · 시크릿 전달 감사 · 조회 인덱스
--
-- 대상: v1.0.0 ~ v1.3.0 으로 설치된 기존 DB
-- 신규 설치는 이 파일을 적용하지 않는다 — install.php 가 schema.sql 을 적용한 뒤
-- 이 파일을 status='baseline' 으로 기록만 한다. 두 경로의 최종 형태는 같아야 하고,
-- 그것을 확인하는 명령이 `php server/migrate.php verify` 다.
--
-- 규약
--   1) 모든 DDL 문장 **바로 앞**에 skip-if 가드 주석을 둔다. MySQL 은 DDL 에
--      트랜잭션이 걸리지 않으므로 중간에 죽으면 롤백되지 않는다. 가드가 있어야
--      같은 파일을 다시 돌려 **이미 적용된 문장을 건너뛰고 이어서** 갈 수 있다.
--      가드 없는 DDL 은 러너가 파일 자체를 거부한다(mig_lint).
--   2) 테이블 생성은 `CREATE TABLE FROM SCHEMA <table>` 로 적는다. 정본 DDL 은
--      schema.sql 한 곳에만 있고 러너가 실행 직전에 그것을 끼워 넣는다.
--      여기에 CREATE 문을 복사해 두면 정의가 두 곳이 되고, 그것이 이 프로젝트에서
--      반복된 사고 유형이다.
--   3) 테이블명은 `app_ulogin_` 리터럴로 적는다. 러너가 DB_PREFIX 로 치환한다.


-- ---------------------------------------------------------------------
-- 1) 스키마 리비전 저장소
--    러너가 부트스트랩에서 이미 만들지만, 파일만 읽어도 무엇이 생기는지
--    알 수 있도록 여기에도 적는다. 가드가 항상 건너뛴다.
-- ---------------------------------------------------------------------
-- @skip-if: table-exists app_ulogin_schema_migrations
CREATE TABLE FROM SCHEMA app_ulogin_schema_migrations;


-- ---------------------------------------------------------------------
-- 2) 시크릿 전달 감사 (D-153 H-2)
--    payload 로 시크릿이 실제로 나간 사건을 시간 버킷으로 기록한다.
-- ---------------------------------------------------------------------
-- @skip-if: table-exists app_ulogin_secret_access_logs
CREATE TABLE FROM SCHEMA app_ulogin_secret_access_logs;


-- ---------------------------------------------------------------------
-- 3) 조회 인덱스 (D-154 / 40c C-5)
--    관리자 목록의 정렬 화이트리스트에 points·login_count 가 있는데 인덱스가 없어
--    필터 없는 정렬이 전량 filesort 였다.
-- ---------------------------------------------------------------------
-- @skip-if: index-exists app_ulogin_users idx_users_points
ALTER TABLE `app_ulogin_users` ADD KEY `idx_users_points` (`points`);

-- @skip-if: index-exists app_ulogin_users idx_users_login_count
ALTER TABLE `app_ulogin_users` ADD KEY `idx_users_login_count` (`login_count`);


-- ---------------------------------------------------------------------
-- 4) GC 인덱스 (40a B-2)
--    core/gc.php 의 세션 정리 DELETE 는 refresh_expires_at 또는 expires_at 으로
--    지운다. refresh_expires_at 에 인덱스가 없어 OR 절의 절반이 전체 스캔이었고,
--    그 DELETE 가 100 요청마다 한 번 돈다.
-- ---------------------------------------------------------------------
-- @skip-if: index-exists app_ulogin_sessions idx_sessions_refresh_expires
ALTER TABLE `app_ulogin_sessions` ADD KEY `idx_sessions_refresh_expires` (`refresh_expires_at`);
