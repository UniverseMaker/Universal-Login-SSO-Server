-- name: v1.7.0 — 무재인증(무음) SSO 발급 구분 · auth_codes.issued_via
--
-- 대상: 0003 까지 적용된 기존 DB
-- 신규 설치는 이 파일을 적용하지 않는다 — schema.sql 이 이미 이 컬럼과 COMMENT 를 갖고 있고,
-- install.php 가 status='baseline' 으로 기록만 한다. 두 경로의 최종 형태가 같은지는
-- `php server/migrate.php verify` 가 대조한다.
--
-- 무엇이 생기나
--   v1.7.0 이 `POST /api/sso/authorize_session`(무음 인가)을 추가했다. 쿠키 세션만 보고
--   비밀번호를 다시 묻지 않은 채 SSO code 를 발급하는 경로다.
--
--   설계는 이 경로에 **동의(consent) 화면을 두지 않기로** 판정했고(D-235), 그 대신
--   **무음으로 발급된 사실을 구분해 기록**하는 것을 조건으로 달았다. 「누가 언제 내
--   계정으로 어느 시스템에 들어갔나」에 답할 수 있어야 동의 화면이 주는 통제의 실질이 남는다.
--
--   그 기록을 가능하게 하는 것이 이 컬럼이다. code 를 발급한 쪽과 소모하는 쪽이 다른
--   요청이므로(authorize_session → exchange), 발급 경로를 **code 행에 실어 보내는** 수밖에 없다.
--
-- 왜 기본값이 'password' 인가
--   이 컬럼이 없던 시절의 행과 기존 `authorize.php` 가 넣는 행은 전부 아이디·비밀번호
--   검증을 거친 것이다. 'password' 가 **사실**이고, 무음은 새 경로가 명시적으로 'session'
--   을 넣을 때만 기록된다. 값을 추측해 채우지 않는다.
--   `authorize.php` 는 이 라운드에서 한 줄도 고치지 않았다(D-234) — 그 파일은 이 컬럼을
--   INSERT 목록에 넣지 않고 DEFAULT 로 떨어진다. **그래서 기본값이 맞아야 한다.**
--
-- 규약은 0001 ~ 0003 과 같다. 모든 DDL 문장 바로 앞에 skip-if 가드를 둔다 —
-- 가드 없는 DDL 은 러너(mig_lint)가 파일 자체를 거부한다.
-- 테이블명은 `app_ulogin_` 리터럴로 적고 러너가 DB_PREFIX 로 치환한다.
-- 테이블 **생성**은 `CREATE TABLE FROM SCHEMA <table>` 로 적지만(정본 DDL 은 schema.sql
-- 한 곳), 이 파일에는 생성이 없다. 컬럼 추가·수정은 0002 · 0003 과 같이 리터럴로 적는다.


-- ---------------------------------------------------------------------
-- 1) 발급 경로 컬럼
--    `ADD COLUMN IF NOT EXISTS` 는 MariaDB 전용이라 쓰지 않는다. 가드가
--    information_schema 로 판정하므로 MySQL·MariaDB 양쪽에서 같게 돈다.
--
--    `AFTER code_challenge` 를 쓰는 이유 — 0002 와 판단이 갈리는 지점이라 적어 둔다.
--      0002(`service_secrets.allow_public`)는 위치를 **맞추지 않았다.** MySQL 은 컬럼을
--      제자리에서 재배치하지 못해 테이블을 통째로 복사하는데, 그 테이블에 든 것이
--      「복호화 불가능해지면 끝인 AES 암호문」이었기 때문이다.
--      `auth_codes` 는 그 조건에 해당하지 않는다 — **수명 60초짜리 일회용 code 만** 들어
--      있고(만료분은 GC 가 지운다) 평소 행 수가 수십 단위다. 복사 비용도 위험도 없다.
--      그래서 여기서는 위치까지 맞춰 **신규 설치와 업그레이드의 컬럼 순서를 같게** 둔다.
--      (컬럼 순서 차이는 `tests/tier3_live/t_20_migration_paths.php` 가 soft 로 보고하는
--       항목이다. 보고를 하나 늘리지 않을 수 있으면 늘리지 않는다 — soft 목록이 길어질수록
--       거기 섞여 들어온 진짜 드리프트를 못 본다.)
-- ---------------------------------------------------------------------
-- @skip-if: column-exists app_ulogin_auth_codes issued_via
ALTER TABLE `app_ulogin_auth_codes`
  ADD COLUMN `issued_via` VARCHAR(16) NOT NULL DEFAULT 'password'
  COMMENT 'password|session — 무음 인가로 발급됐는가(v1.7.0)'
  AFTER `code_challenge`;


-- ---------------------------------------------------------------------
-- 2) login_logs.result COMMENT 에 `success_silent` 추가
--    0003 이 만든 자리를 그대로 이어받는다. 그 파일이 고친 이유가
--    「COMMENT 가 이 컬럼이 가질 수 있는 값의 **정본 목록**이고, 그 목록이 두 설치
--    경로에서 갈리면 운영자가 모르는 값을 보게 된다」였다. 이번에 값이 하나 는다.
--
--    가드는 `never` 다 — 0003 과 같은 이유다. 러너의 가드 종류는 table-exists ·
--    column-exists · index-exists · never 뿐이고 "COMMENT 가 이미 이 값인가" 를 물을 수
--    있는 가드는 없다. 대신 이 문장 자체가 **멱등**이다.
--
--    컬럼 정의는 schema.sql 의 `app_ulogin_login_logs` 와 **글자 그대로 같아야 한다.**
--    MODIFY 는 적지 않은 속성을 기본값으로 되돌리므로, 타입·NULL 허용을 함께 적지
--    않으면 COMMENT 를 고치면서 컬럼을 망가뜨린다.
--    대조: grep -n 'login_logs' -A 20 server/schema.sql
--
--    값을 읽는 쪽도 함께 고쳐야 한다. **COMMENT 만 고치면 필터에서 사라진다:**
--      server/api/admin/logs.php  의 `$results` 배열
--      server/api/me/login_logs.php 의 v_enum 목록
--    (이 라운드에서 둘 다 갱신했다. 한쪽만 고치지 마라 — 두 파일의 주석이 서로를 가리킨다)
-- ---------------------------------------------------------------------
-- @skip-if: never
ALTER TABLE `app_ulogin_login_logs`
  MODIFY COLUMN `result` VARCHAR(30) NOT NULL
  COMMENT 'success|success_silent|issued_silent|fail_credential|fail_locked|fail_status|fail_ratelimit|fail_service_blocked|fail_client_auth|fail_migrate_auth|fail_refresh_reuse|fail_refresh_revoked|logout';
