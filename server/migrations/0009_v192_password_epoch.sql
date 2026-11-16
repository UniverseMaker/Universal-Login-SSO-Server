-- name: v1.9.2 — users.password_epoch (비밀번호 변경 단조 카운터, 보안 124 L-1)
--
-- 대상: 0008 까지 적용된 기존 DB. 신규 설치는 schema.sql 이 이미 이 컬럼을 갖는다(baseline).
-- password_changed_at 은 초 단위라 같은 초의 두 번째 변경을 못 가른다. 이 카운터가 대신 대조된다.
-- 규약은 0007·0008 과 같다. 컬럼 정의는 schema.sql 과 글자 그대로 같다.

-- @skip-if: column-exists app_ulogin_users password_epoch
ALTER TABLE `app_ulogin_users` ADD COLUMN `password_epoch` INT NOT NULL DEFAULT 0 COMMENT '비밀번호가 바뀔 때마다 +1. 시각 해상도와 무관한 변경 대조용';
