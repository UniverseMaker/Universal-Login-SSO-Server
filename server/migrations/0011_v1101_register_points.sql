-- name: v1.10.1 — 가입 신청(register_requests) · 공동 포인트 s2s 허용·한도(services.points_json)
--
-- 대상: 0010 까지 적용된 기존 DB. 신규 설치는 schema.sql 이 이미 이 표·컬럼을 갖는다(baseline).
-- 설계 `_workspace/130_architect_v1101_design.md` §2-2 · §4-3 · §4-4 · 판정 D-371.
--   services        + points_json (NULL = 공동 포인트 막음, fail-closed)
--   point_logs      + idx_point_logs_service (service_id, created_at) — 24시간 한도 합산·history
--   register_requests (새 표 1개)
-- **기존 데이터는 옮기지 않는다.** 씨앗 문장도 없다. settings.register_mode 행도 쓰지 않는다(D-371 Q-1):
-- 행이 없으면 register_mode_effective() 가 기존 allow_register 를 따라가므로 0011 직후 동작이 v1.10.0 과 같다.
-- 규약은 0007 ~ 0010 과 같다. 모든 DDL 앞에 skip-if 가드. 컬럼 정의는 schema.sql 과 글자 그대로 같다.
-- 방언: ENUM · 중복 키 UPSERT 꼬리 · 행 잠금 꼬리 0회.
-- 적용 순서: DB 사본(-wal/-shm 포함) → apply → verify → 접합 전환. **apply 가 접합보다 먼저.**


-- ---------------------------------------------------------------------
-- 1) 서비스 — 공동 포인트 허용·한도
-- ---------------------------------------------------------------------
-- @skip-if: column-exists app_ulogin_services points_json
ALTER TABLE `app_ulogin_services` ADD COLUMN `points_json` TEXT NULL COMMENT '공동 포인트 s2s 허용·한도 {enabled,earn,spend,per_tx_max,daily_*}. NULL=막음. 재등록하면 NULL';


-- ---------------------------------------------------------------------
-- 2) 포인트 원장 — 서비스별 24시간 합산 색인
-- ---------------------------------------------------------------------
-- @skip-if: index-exists app_ulogin_point_logs idx_point_logs_service
ALTER TABLE `app_ulogin_point_logs` ADD KEY `idx_point_logs_service` (`service_id`, `created_at`);


-- ---------------------------------------------------------------------
-- 3) 가입 신청 표 — 정본 DDL 은 schema.sql 한 곳
-- ---------------------------------------------------------------------
-- @skip-if: table-exists app_ulogin_register_requests
CREATE TABLE FROM SCHEMA app_ulogin_register_requests;
