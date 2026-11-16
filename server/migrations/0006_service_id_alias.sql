-- name: v1.8.6 — 서비스 id 개명 별칭 (services.former_* · sessions.client_service_id)
--
-- 대상: 0005 까지 적용된 기존 DB
-- 신규 설치는 이 파일을 적용하지 않는다 — schema.sql 이 이미 이 컬럼·색인을 갖고 있고,
-- install.php 가 status='baseline' 으로 기록만 한다. 두 경로의 최종 형태가 같은지는
-- `php server/migrate.php verify` 가 대조한다(SQLite·MySQL 둘 다).
--
-- **스키마만 바꾼다. 개명(데이터)은 이 파일에 없다** (설계 `_workspace/104_architect_v186_service_id_rename.md`
-- A-4 · 리더 판정 D-341 H-1). 이유:
--   1) 러너는 문장마다 커밋한다. 11개 표 개명이 중간에 끊기면 반쯤 바뀐 DB 가 남는다
--   2) `mgm→admin` 은 이 배포의 데이터다. 공개 저장소의 다른 설치본 마이그레이션에 들어가면 안 된다
--   3) 개명은 새 코드(별칭 해석)가 운영에 올라간 **뒤**에만 안전하다. 마이그레이션은 접합 전환 **전**에 돈다
-- 개명은 `tools/register_services.php --rename=mgm:admin` 이 서비스마다 한 트랜잭션으로 한다.
--
-- 무엇이 생기나
--   services.former_service_id    개명 전 id. 해석 기간 동안 별칭으로 받는다. **지우지 않는다**(재사용 금지)
--   services.former_until         NULL = 별칭 유효. 시각이 지나면 해석하지 않는다(재사용 금지는 계속)
--   services.former_last_used_at  secret 을 검증한 요청이 별칭으로 들어온 마지막 시각(10분에 1회 갱신)
--   uq_services_former            옛 id 끼리의 중복 금지. NULL 다중 허용(SQLite·MySQL 같다)
--   sessions.client_service_id    별칭으로 들어온 세션의 그 값 — 이후 Bearer 요청 응답의 service_id echo
--
-- 번호: 리더 판정 D-341 H-1 — 이 판이 0006, v1.9.0 의 접근 제한은 0007 부터.
-- 순서 의존: 0006 은 0005 의 표를 건드리지 않는다. 같은 배포에서 0005 → 0006 이 연달아 돈다.
--
-- 규약은 0001 ~ 0005 와 같다. 모든 DDL 문장 바로 앞에 skip-if 가드를 둔다.
-- 위치를 맞추지 않는다(AFTER 없음). schema.sql 도 이 컬럼을 표 맨 뒤에 둔다.
-- 컬럼 정의는 schema.sql 과 **글자 그대로 같아야 한다.**


-- @skip-if: column-exists app_ulogin_services former_service_id
ALTER TABLE `app_ulogin_services` ADD COLUMN `former_service_id` VARCHAR(64) NULL COMMENT '개명 전 id. 해석 기간 동안 별칭';

-- @skip-if: column-exists app_ulogin_services former_until
ALTER TABLE `app_ulogin_services` ADD COLUMN `former_until` DATETIME NULL COMMENT 'NULL=별칭 유효. 값이 지나면 해석 안 함(재사용 금지는 계속)';

-- @skip-if: column-exists app_ulogin_services former_last_used_at
ALTER TABLE `app_ulogin_services` ADD COLUMN `former_last_used_at` DATETIME NULL COMMENT '인증된 별칭 사용 마지막 시각(10분 상한 갱신)';

-- @skip-if: index-exists app_ulogin_services uq_services_former
ALTER TABLE `app_ulogin_services` ADD UNIQUE KEY `uq_services_former` (`former_service_id`);

-- @skip-if: column-exists app_ulogin_sessions client_service_id
ALTER TABLE `app_ulogin_sessions` ADD COLUMN `client_service_id` VARCHAR(64) NULL COMMENT '클라이언트가 별칭으로 들어왔을 때 그 값(응답 echo용)';
