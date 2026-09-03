-- name: v1.5.0 — login_logs.result COMMENT 를 두 설치 경로에서 같게 만든다
--
-- 대상: v1.0.0 ~ v1.4.0 으로 설치된 기존 DB
-- 신규 설치는 이 파일을 적용하지 않는다 — schema.sql 이 이미 최종 COMMENT 를 갖고 있고,
-- install.php 가 status='baseline' 으로 기록만 한다.
--
-- 무엇이 갈렸나
--   `login_logs.result` 의 COLUMN COMMENT 는 이 컬럼이 가질 수 있는 값의 **정본 목록**이다.
--   신규 설치(schema.sql)는 11값을 갖는데, 업그레이드 경로는 v1.3.0 당시의 7값에서 멈춰
--   있었다. v1.4.0 이 값 4개를 새로 쓰기 시작했지만(아래) 기존 DB 의 COMMENT 를 갱신하는
--   문장을 넣지 않았기 때문이다.
--
--     빠져 있던 값: fail_client_auth · fail_migrate_auth · fail_refresh_reuse · fail_refresh_revoked
--
--   동작에는 영향이 없다 — 컬럼은 VARCHAR 이고 코드가 COMMENT 를 읽지 않는다.
--   그러나 이 COMMENT 는 **운영자가 `result` 값을 확인하는 유일한 자리**다.
--   `SHOW FULL COLUMNS` 로 7값을 본 사람은 `fail_refresh_reuse`(토큰 재사용 = 침해 신호)를
--   알 수 없는 값으로 읽는다. 값 집합의 드리프트는 그 다음 라운드에 스키마 자체의
--   드리프트로 자란다 — 이 저장소가 반복해 맞은 유형이다.
--
-- 왜 `migrate.php verify` 가 못 잡았나 (D-175)
--   verify 는 대조 전에 인용 문자열을 선삭제한다. COMMENT 는 통째로 문자열이라
--   양쪽에서 사라지고, 그러면 어떤 값이 들어 있어도 "동일" 이 된다.
--   이 차이는 v1.5.0 테스트 스위트가 `information_schema` 를 직접 읽어 잡았다
--   (`tests/tier3_live/t_20_migration_paths.php`).
--
-- 규약은 0001 · 0002 와 같다. 모든 DDL 문장 바로 앞에 skip-if 가드를 둔다 —
-- 가드 없는 DDL 은 러너(mig_lint)가 파일 자체를 거부한다.
-- 테이블명은 `app_ulogin_` 리터럴로 적고 러너가 DB_PREFIX 로 치환한다.


-- ---------------------------------------------------------------------
-- 1) COMMENT 정정
--    가드는 `never` 다 — 건너뛸 조건이 없다.
--    러너의 가드 종류는 table-exists · column-exists · index-exists · never 뿐이고,
--    "COMMENT 가 이미 이 값인가" 를 물을 수 있는 가드는 없다. 대신 이 문장 자체가
--    **멱등**이다: 같은 정의로 MODIFY 를 다시 걸면 결과가 같다. 그래서 중간에
--    죽어도 파일을 다시 돌려 이어갈 수 있다는 가드의 목적은 그대로 지켜진다.
--
--    컬럼 정의는 schema.sql 의 `app_ulogin_login_logs` 와 **글자 그대로 같아야 한다.**
--    MODIFY 는 적지 않은 속성을 기본값으로 되돌리므로, 타입·NULL 허용을 함께 적지
--    않으면 COMMENT 를 고치면서 컬럼을 망가뜨린다.
--    한쪽만 고치면 두 경로가 다시 갈린다 — 그때는 이 파일이 아니라 schema.sql 이 정본이다.
--    대조: grep -n 'login_logs' -A 12 server/schema.sql
-- ---------------------------------------------------------------------
-- @skip-if: never
ALTER TABLE `app_ulogin_login_logs`
  MODIFY COLUMN `result` VARCHAR(30) NOT NULL
  COMMENT 'success|fail_credential|fail_locked|fail_status|fail_ratelimit|fail_service_blocked|fail_client_auth|fail_migrate_auth|fail_refresh_reuse|fail_refresh_revoked|logout';
