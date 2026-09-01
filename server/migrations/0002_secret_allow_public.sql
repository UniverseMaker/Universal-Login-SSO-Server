-- name: v1.4.0 후속 — 게이트 없는 민감 시크릿의 명시적 승인 표시
--
-- 대상: 0001 까지 적용된 DB
-- 신규 설치는 이 파일을 적용하지 않는다 — schema.sql 이 이미 이 컬럼을 갖고 있고,
-- install.php 가 status='baseline' 으로 기록만 한다. 두 경로의 최종 형태가 같은지는
-- `php server/migrate.php verify` 가 대조한다.
--
-- 왜 컬럼이 필요한가
--   `admin_secret_guard_gate()` 는 민감한 키 이름 + 실효 게이트 0개인 시크릿을
--   `allow_public=true` 없이는 거부한다(D-54 / D-47). 그런데 그 플래그로 통과한
--   사실이 `admin_logs` 에만 남아서, **목록 응답으로는 의도한 공개와 빠뜨린 게이트를
--   구분할 수 없었다.** 관리자 콘솔의 게이트 점검 화면이 둘을 섞어 보여줄 수밖에 없고,
--   섞이면 그 화면은 "전부 확인함" 으로 한 번에 무시된다.
--
-- 왜 기본값 0 인가
--   이 컬럼이 없던 시절에 만들어진 행은 승인을 받은 적이 없다. 0(미승인)이 사실이고,
--   점검 화면에 남는 것이 맞다 — D-156(7) 이 지시한 "이미 등록된 것을 찾아 주는 화면"
--   의 데이터가 바로 이것이다. 값을 추측해 1 로 채우면 그 화면이 처음부터 비어 버린다.
--
-- 규약은 0001 과 같다. 모든 DDL 문장 바로 앞에 skip-if 가드를 둔다 —
-- 가드 없는 DDL 은 러너(mig_lint)가 파일 자체를 거부한다.
-- 테이블명은 `app_ulogin_` 리터럴로 적고 러너가 DB_PREFIX 로 치환한다.


-- ---------------------------------------------------------------------
-- 1) 승인 표시 컬럼
--    `ADD COLUMN IF NOT EXISTS` 는 MariaDB 전용이라 쓰지 않는다. 가드가
--    information_schema 로 판정하므로 MySQL·MariaDB 양쪽에서 같게 돈다.
-- ---------------------------------------------------------------------
-- @skip-if: column-exists app_ulogin_service_secrets allow_public
ALTER TABLE `app_ulogin_service_secrets`
  ADD COLUMN `allow_public` TINYINT(1) NOT NULL DEFAULT 0
  COMMENT '게이트 없는 민감 키를 운영자가 명시적으로 승인했는가(D-54)';
