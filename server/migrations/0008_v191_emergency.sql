-- name: v1.9.1 — 비상 로그인 확인값 (emergency/1 §1-1, 판정 D-362)
--
-- 대상: 0007 까지 적용된 기존 DB. 신규 설치는 schema.sql 이 이미 이 컬럼·표를 갖는다(baseline).
-- 무엇이 생기나
--   services   + emergency_allow(기본 1) · emergency_days(기본 30)
--   emergency  비상 확인값(ev) 발급·폐기 사건의 저장. ev 는 AES 봉인본(enc)이고 지급·폐기 뒤 비운다.
-- 규약은 0007 과 같다. 방언: ENUM · ON DUPLICATE KEY · FOR UPDATE 0회. 컬럼 정의는 schema.sql 과 글자 그대로 같다.

-- @skip-if: column-exists app_ulogin_services emergency_allow
ALTER TABLE `app_ulogin_services` ADD COLUMN `emergency_allow` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1이면 이 서비스에 비상 로그인 확인값을 발급(emergency/1)';

-- @skip-if: column-exists app_ulogin_services emergency_days
ALTER TABLE `app_ulogin_services` ADD COLUMN `emergency_days` INT NOT NULL DEFAULT 30 COMMENT '비상 확인값 유효 일수(1~365)';

-- @skip-if: table-exists app_ulogin_emergency
CREATE TABLE FROM SCHEMA app_ulogin_emergency;
