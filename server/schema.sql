-- =====================================================================
-- ulogin — 범용 로그인 서비스 DB 스키마 v1.0.0
-- 원안: ulogin-architect (내부 설계 기록)
-- 구현: ulogin-backend / 2026-08-25
-- 대상: MySQL 5.7+ / MariaDB 10.3+ , InnoDB, utf8mb4
-- 테이블 수: 17 (v1.0.0 의 15 + v1.4.0 의 schema_migrations · secret_access_logs)
-- =====================================================================
--
-- [이 파일의 지위 — v1.4.0]
--   이 파일은 **신규 설치용 최종 스키마**다. install.php 만 이것을 적용한다.
--   기존 설치본은 server/migrations/NNNN_*.sql 을 순서대로 적용해 여기 도달한다.
--   두 경로가 갈라지지 않게 하는 장치는 `php server/migrate.php verify` 다 —
--   그것이 살아 있는 DB 를 **이 파일**과 대조한다. 마이그레이션에 컬럼을 넣고
--   이 파일에 넣지 않으면 업그레이드된 DB 쪽에서 'schema.sql 에 없는 것' 으로 잡히고,
--   반대로 여기에만 넣으면 업그레이드된 DB 쪽에서 '없는 것' 으로 잡힌다.
--   **스키마를 바꾸는 모든 변경은 이 파일과 마이그레이션 파일을 같이 고친다.**
-- =====================================================================
--
-- [테이블 prefix 규약]
--   이 파일의 테이블명은 모두 `app_ulogin_` prefix 로 적혀 있다.
--   install.php 가 실행 시점에 이 파일을 읽어 문자열 `app_ulogin_` 를
--   config.php 의 DB_PREFIX 상수 값으로 치환한 뒤 실행한다.
--   따라서 PHP 코드에서도 테이블명 리터럴을 쓰지 말고 DB_PREFIX 기반
--   상수(T_USERS ...)만 사용한다.
--
-- [공통 규칙]
--   - 모든 시각 컬럼은 UTC 기준 DATETIME. 애플리케이션이 UTC로 넣는다.
--     (DB 서버 타임존이 호스팅마다 다르므로 NOW() 의존을 최소화한다)
--   - 외부 노출 식별자는 users.uuid. users.id 는 절대 API로 나가지 않는다.
--   - FOREIGN KEY 를 걸지 않는다. 공용 호스팅에서 다른 서비스와 DB를
--     공유하고, install/uninstall 시 순서 의존 오류를 만들기 때문이다.
--     참조 무결성은 애플리케이션 계층 + 트랜잭션으로 보장한다.
--   - 확장 필드는 새 테이블 대신 meta_json(TEXT, JSON 문자열)으로 흡수한다.
--   - 기본 데이터 INSERT 는 이 파일에 넣지 않는다. install.php 가 처리한다.
--
-- [원안 대비 보정 — backend]
--   - 전 테이블에 ROW_FORMAT=DYNAMIC 을 명시했다.
--     utf8mb4 에서 VARCHAR(190)/VARCHAR(150) 복합 UNIQUE 키는 COMPACT 행 포맷의
--     767바이트 인덱스 접두 한계를 넘는다. 호스팅의 innodb_default_row_format 이
--     무엇이든 설치가 실패하지 않도록 못박는다.
--   - 그 외 컬럼·인덱스는 원안 그대로다.
--
-- =====================================================================


-- ---------------------------------------------------------------------
-- 1. 회원
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `app_ulogin_users` (
  `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`                 CHAR(36)        NOT NULL COMMENT '외부 노출 식별자. API는 id 대신 이것을 쓴다',
  `username`             VARCHAR(50)     NOT NULL COMMENT '로그인 아이디. 소문자 정규화 저장',
  `email`                VARCHAR(190)    NOT NULL COMMENT '이메일. 소문자 정규화 저장',
  `password_hash`        VARCHAR(255)    NOT NULL COMMENT 'password_hash() 결과. 응답 반환 금지',
  `password_changed_at`  DATETIME        NULL     COMMENT '이 시각 이전 발급 세션은 무효 처리',
  `name`                 VARCHAR(80)     NULL,
  `nickname`             VARCHAR(50)     NULL,
  `phone`                VARCHAR(30)     NULL,
  `avatar_url`           VARCHAR(255)    NULL,
  `status`               VARCHAR(20)     NOT NULL DEFAULT 'active' COMMENT 'active|pending|suspended|dormant|withdrawn',
  `account_level`        VARCHAR(20)     NOT NULL DEFAULT 'basic'  COMMENT 'guest|basic|verified|vip|staff',
  `role`                 VARCHAR(20)     NOT NULL DEFAULT 'user'   COMMENT 'user|manager|admin|superadmin',
  `billing_tier`         VARCHAR(20)     NOT NULL DEFAULT 'free'   COMMENT 'free|lite|pro|enterprise',
  `billing_started_at`   DATETIME        NULL,
  `billing_expires_at`   DATETIME        NULL COMMENT 'NULL=무기한. 지나면 판정 시점에 free 취급',
  `points`               INT             NOT NULL DEFAULT 0 COMMENT '현재 잔액. 음수 불가(애플리케이션 보장)',
  `total_points_earned`  INT             NOT NULL DEFAULT 0,
  `total_points_spent`   INT             NOT NULL DEFAULT 0,
  `email_verified_at`    DATETIME        NULL,
  `two_factor_enabled`   TINYINT(1)      NOT NULL DEFAULT 0,
  `two_factor_secret`    VARCHAR(255)    NULL COMMENT 'TOTP 시크릿(암호화 저장). 응답 반환 금지',
  `failed_login_count`   INT             NOT NULL DEFAULT 0 COMMENT '연속 실패 횟수. 성공 시 0',
  `locked_until`         DATETIME        NULL COMMENT '락아웃 해제 시각',
  `last_login_at`        DATETIME        NULL,
  `last_login_ip`        VARCHAR(45)     NULL COMMENT 'IPv6 대응 45자',
  `login_count`          INT             NOT NULL DEFAULT 0,
  `timezone`             VARCHAR(40)     NOT NULL DEFAULT 'Asia/Seoul',
  `locale`               VARCHAR(10)     NOT NULL DEFAULT 'ko-KR',
  `marketing_agreed`     TINYINT(1)      NOT NULL DEFAULT 0,
  `memo`                 TEXT            NULL COMMENT '관리자 전용 메모. 본인 응답 반환 금지',
  `meta_json`            TEXT            NULL COMMENT '확장 필드. JSON 문자열',
  `created_at`           DATETIME        NOT NULL,
  `updated_at`           DATETIME        NOT NULL,
  `withdrawn_at`         DATETIME        NULL,
  `withdraw_reason`      VARCHAR(255)    NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_uuid`     (`uuid`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_email`    (`email`),
  KEY `idx_users_status`         (`status`),
  KEY `idx_users_created_at`     (`created_at`),
  KEY `idx_users_role_status`    (`role`, `status`),
  KEY `idx_users_billing`        (`billing_tier`, `billing_expires_at`),
  KEY `idx_users_account_level`  (`account_level`),
  KEY `idx_users_last_login_at`  (`last_login_at`),
  -- v1.4.0 추가. 관리자 목록의 정렬 화이트리스트(api/admin/users.php)에는 points 와
  -- login_count 가 들어 있는데 인덱스가 없어 필터 없는 정렬이 전량 filesort 였다.
  -- LIMIT 20 이어도 정렬은 전 행에 걸린다.
  KEY `idx_users_points`         (`points`),
  KEY `idx_users_login_count`    (`login_count`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 조회 패턴: 로그인=username/email(UNIQUE), 관리자 목록=status/role/level 필터 + created_at 정렬,
--            통계=billing_tier·account_level 집계.


-- ---------------------------------------------------------------------
-- 2. 서비스 (멀티 테넌트)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `app_ulogin_services` (
  `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `service_id`            VARCHAR(64)     NOT NULL COMMENT '외부에서 쓰는 문자열 키. 예: local-ai-chat',
  `service_name`          VARCHAR(100)    NOT NULL,
  `service_secret_hash`   VARCHAR(255)    NULL COMMENT '서버-투-서버용. public client 는 NULL',
  `is_confidential`       TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1이면 exchange 시 service_secret 필수',
  `redirect_whitelist`    TEXT            NOT NULL COMMENT 'JSON 배열. origin+path 완전 일치 비교',
  `allowed_origins`       TEXT            NULL COMMENT 'JSON 배열. CORS Access-Control-Allow-Origin 판정용',
  `auto_join`             TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '1이면 최초 로그인 시 user_services 자동 생성',
  -- 리더 판정 D-35: grant-only 다. 전역 등급을 절대 강등하지 않는다.
  -- core/auth.php ensure_user_service() 는 이 값의 랭크가 users.account_level 보다
  -- 높을 때만 user_services.account_level 오버라이드로 기록하고, 그 외에는 NULL(전역 상속)을 넣는다.
  `default_account_level` VARCHAR(20)     NULL COMMENT '자동 가입 시 최소 보장 등급(grant-only). 전역보다 높을 때만 오버라이드로 기록. 강등 불가',
  `session_ttl`           INT             NOT NULL DEFAULT 3600    COMMENT 'access_token 수명(초)',
  `refresh_ttl`           INT             NOT NULL DEFAULT 1209600 COMMENT 'refresh_token 수명(초). 14일',
  `payload_fields`        TEXT            NULL COMMENT 'JSON 배열. 이 서비스가 받을 user 필드 화이트리스트. NULL=기본셋',
  `allow_register`        TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '이 서비스 경유 회원가입 허용 여부',
  `status`                VARCHAR(20)     NOT NULL DEFAULT 'active' COMMENT 'active|disabled',
  `description`           VARCHAR(255)    NULL,
  `meta_json`             TEXT            NULL,
  `created_at`            DATETIME        NOT NULL,
  `updated_at`            DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_services_service_id` (`service_id`),
  KEY `idx_services_status` (`status`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `app_ulogin_user_services` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`            BIGINT UNSIGNED NOT NULL,
  `service_id`         VARCHAR(64)     NOT NULL,
  -- 오버라이드 3축. 자동 가입은 role·billing_tier 를 항상 NULL 로 넣는다(리더 판정 D-35).
  -- account_level 만, services.default_account_level 이 전역보다 높을 때 채워진다.
  -- 관리자가 수동으로 넣는 값은 강등용으로도 쓸 수 있다 — 자동 경로에만 grant-only 제약이 있다.
  `account_level`      VARCHAR(20)     NULL COMMENT 'NULL=전역값 사용. 자동 가입에서는 최소 보장 등급만 기록(D-35)',
  `role`               VARCHAR(20)     NULL COMMENT 'NULL=전역값 사용. 자동 가입에서는 항상 NULL',
  `billing_tier`       VARCHAR(20)     NULL COMMENT 'NULL=전역값 사용. 자동 가입에서는 항상 NULL',
  `billing_expires_at` DATETIME        NULL,
  `points`             INT             NOT NULL DEFAULT 0 COMMENT '서비스 전용 포인트. v1.0.0 미사용(리더 판정 D-4)',
  -- 리더 판정 D-31: 'blocked' 는 실제로 차단된다.
  -- core/payload.php build_payload() 가 빈 payload 를 주고,
  -- login/exchange/sso-authorize 는 core/auth.php service_membership_blocked() 로 403 거부한다.
  `status`             VARCHAR(20)     NOT NULL DEFAULT 'active' COMMENT 'active|blocked. blocked 면 payload 없음 + 로그인 거부(D-31)',
  `meta_json`          TEXT            NULL,
  `joined_at`          DATETIME        NOT NULL,
  `last_used_at`       DATETIME        NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_services` (`user_id`, `service_id`),
  KEY `idx_us_service_id` (`service_id`, `status`),
  KEY `idx_us_last_used`  (`last_used_at`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- [알려진 차이 — 이 테이블의 컬럼 **순서**는 두 설치 경로에서 영구히 다르다]
--
--   신규 설치는 이 파일 그대로 만들어지므로 `allow_public` 이 `is_masked` 다음에 온다.
--   기존 설치본은 마이그레이션 0002 의 `ADD COLUMN` 으로 그 컬럼을 **맨 끝에** 얻었고,
--   그래서 `allow_public` 뒤에 와야 할 컬럼들이 한 칸씩 앞으로 당겨져 있다.
--
--   **동작에는 영향이 없고, 고치지 않기로 판정했다.** 근거:
--    · 이 테이블에 접근하는 코드는 전부 **컬럼 이름**으로 읽고 쓴다.
--      `SELECT *` 는 연관 배열로 받고, INSERT 는 컬럼 목록을 명시한다.
--      서수 위치에 기대는 자리(FETCH_NUM · 컬럼 목록 없는 VALUES)는 한 곳도 없다.
--      다시 확인하려면: grep -rn 'T_SECRETS\|service_secrets' server --include=*.php
--    · 되돌리려면 `MODIFY COLUMN … AFTER …` 가 필요한데 MySQL 은 컬럼 재배치를
--      **제자리에서 못 한다** — 테이블을 통째로 복사한다. 이 테이블에 든 것은
--      **복호화 불가능해지면 끝인 AES 암호문**이다. 얻는 것이 두 설치본의 스키마 덤프가
--      나란히 같아 보이는 것뿐인데 그 대가로 암호문 테이블을 재작성하지 않는다.
--    · 러너의 skip-if 가드에는 "컬럼이 이 위치에 있는가" 가 없다. 가드를 `never` 로
--      두면 `migrate.php apply` 를 다시 돌릴 때마다 그 복사가 되풀이된다.
--
--   `migrate.php verify` 는 컬럼 순서를 보지 않는다(D-175 맹점). 대신 v1.5.0 테스트
--   스위트가 `information_schema` 를 직접 대조해 **매 실행 이 차이를 이름까지 열거**한다
--   (`tests/tier3_live/t_20_migration_paths.php` 의 soft 목록). 목록에 **여기 적히지 않은
--   항목이 새로 뜨면 그것은 이 알려진 차이가 아니라 새 드리프트다** — 그때 조사하라.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `app_ulogin_service_secrets` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `service_id`        VARCHAR(64)     NOT NULL,
  `secret_key`        VARCHAR(100)    NOT NULL COMMENT 'payload 에서의 키 이름. 예: ai_api_key',
  `secret_value_enc`  TEXT            NOT NULL COMMENT 'AES-256-CBC 암호문(base64). 평문 저장 금지',
  `value_type`        VARCHAR(20)     NOT NULL DEFAULT 'string' COMMENT 'string|json|url',
  `min_account_level` VARCHAR(20)     NULL COMMENT '미달 시 payload 에서 조용히 제외',
  `min_billing_tier`  VARCHAR(20)     NULL,
  `min_role`          VARCHAR(20)     NULL,
  `is_masked`         TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '1이면 관리자 콘솔에서도 값 마스킹',
  -- v1.4.0 후속 (마이그레이션 0002). 민감한 키 이름인데 실효 게이트가 하나도 없는 행을
  -- 운영자가 `allow_public=true` 로 **명시적으로 승인**해 만들었는가(D-54 / D-47).
  -- 종전에는 그 사실이 admin_logs 에만 남아 목록 응답으로는 구분할 수 없었고,
  -- 그래서 게이트 점검 화면이 '의도한 공개' 와 '빠뜨린 게이트' 를 섞어 보여줄 수밖에 없었다.
  -- 기본값 0 = 승인된 적 없음. 이 컬럼이 생기기 전에 만들어진 행도 0 이며 **그것이 맞다** —
  -- 그때는 승인이라는 절차 자체가 없었으므로 전부 점검 대상이다.
  `allow_public`      TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '게이트 없는 민감 키를 운영자가 명시적으로 승인했는가(D-54)',
  `sort_order`        INT             NOT NULL DEFAULT 0,
  `description`       VARCHAR(255)    NULL,
  `status`            VARCHAR(20)     NOT NULL DEFAULT 'active' COMMENT 'active|disabled',
  `created_at`        DATETIME        NOT NULL,
  `updated_at`        DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_secrets` (`service_id`, `secret_key`),
  KEY `idx_ss_service_status` (`service_id`, `status`, `sort_order`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- 3. 인증
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `app_ulogin_sessions` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- 리더 판정 D-29 / 감사 C-1: session_id 는 **인증자가 아니다.**
  -- 쿠키(ULOGIN_SID)에 들어가는 값은 access_token 원문이고, require_auth() 는
  -- Bearer 든 쿠키든 access_token_hash 로만 세션을 찾는다.
  -- 이 값을 인증에 쓰는 코드를 다시 만들면 안 된다 — 응답과 관리자 화면에 노출되는 값이다.
  `session_id`          CHAR(64)        NOT NULL COMMENT '공개 식별자(난수 hex). 응답 노출 O. 인증자 아님 — 인증은 access_token_hash(D-29)',
  `user_id`             BIGINT UNSIGNED NOT NULL,
  `service_id`          VARCHAR(64)     NULL COMMENT '로그인 서버 자체 세션이면 NULL',
  `auth_type`           VARCHAR(10)     NOT NULL COMMENT 'cookie|token',
  `access_token_hash`   CHAR(64)        NULL COMMENT 'SHA-256. 원문 저장 금지. **유일한 인증자** — Bearer/쿠키 양쪽이 이 컬럼으로 조회된다(D-29)',
  `refresh_token_hash`  CHAR(64)        NULL COMMENT 'SHA-256. 회전 시 갱신',
  `csrf_token_hash`     CHAR(64)        NULL COMMENT 'cookie 세션 전용. SHA-256',
  `issued_at`           DATETIME        NOT NULL,
  `expires_at`          DATETIME        NOT NULL COMMENT 'access 만료',
  `refresh_expires_at`  DATETIME        NULL,
  `last_seen_at`        DATETIME        NOT NULL COMMENT '검증 성공 시 갱신',
  `ip`                  VARCHAR(45)     NOT NULL,
  `user_agent`          VARCHAR(255)    NOT NULL DEFAULT '',
  `device_label`        VARCHAR(80)     NULL COMMENT '사용자에게 보여줄 기기명',
  `revoked_at`          DATETIME        NULL,
  `revoke_reason`       VARCHAR(50)     NULL COMMENT 'logout|logout_all|password_changed|admin|expired|refresh_rotated|refresh_reuse|withdraw',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sessions_session_id`   (`session_id`),
  UNIQUE KEY `uq_sessions_access_hash`  (`access_token_hash`),
  UNIQUE KEY `uq_sessions_refresh_hash` (`refresh_token_hash`),
  KEY `idx_sessions_user_revoked` (`user_id`, `revoked_at`),
  KEY `idx_sessions_expires_at`   (`expires_at`),
  KEY `idx_sessions_service`      (`service_id`, `user_id`),
  -- v1.4.0 추가. core/gc.php 의 세션 정리 DELETE 는
  --   (refresh_expires_at IS NOT NULL AND refresh_expires_at < now) OR (refresh_expires_at IS NULL AND expires_at < now)
  -- 형태다. expires_at 에만 인덱스가 있어 OR 절의 앞 절반이 전체 스캔이었고,
  -- 그 DELETE 가 100 요청마다 한 번 돈다.
  KEY `idx_sessions_refresh_expires` (`refresh_expires_at`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 토큰 해시에 UNIQUE 를 건 이유: 조회가 항상 단건 exact-match 이고,
-- 난수 충돌/중복 삽입을 DB가 직접 막아준다. (NULL 은 MySQL에서 중복 허용되므로
-- cookie 세션이 access_token_hash=NULL 로 다수 존재해도 문제없다)


CREATE TABLE IF NOT EXISTS `app_ulogin_auth_codes` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code_hash`      CHAR(64)        NOT NULL COMMENT 'SHA-256. 원문 저장 금지',
  `user_id`        BIGINT UNSIGNED NOT NULL,
  `service_id`     VARCHAR(64)     NOT NULL,
  `redirect_uri`   VARCHAR(500)    NOT NULL COMMENT '발급 시점 값. exchange 에서 재대조',
  `state`          VARCHAR(255)    NULL COMMENT '클라이언트가 준 값. 그대로 되돌려줌',
  `code_challenge` VARCHAR(128)    NULL COMMENT 'PKCE S256 challenge',
  `expires_at`     DATETIME        NOT NULL COMMENT '발급 +60초',
  `used_at`        DATETIME        NULL COMMENT 'NOT NULL 이면 재사용 거부',
  `created_ip`     VARCHAR(45)     NOT NULL,
  `created_at`     DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_auth_codes_hash` (`code_hash`),
  KEY `idx_auth_codes_expires` (`expires_at`),
  KEY `idx_auth_codes_user`    (`user_id`, `created_at`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `app_ulogin_password_resets` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token_hash` CHAR(64)        NOT NULL COMMENT 'SHA-256',
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `expires_at` DATETIME        NOT NULL COMMENT '발급 +30분',
  `used_at`    DATETIME        NULL,
  `created_ip` VARCHAR(45)     NOT NULL,
  `created_at` DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pw_resets_token` (`token_hash`),
  KEY `idx_pw_resets_user`    (`user_id`, `created_at`),
  KEY `idx_pw_resets_expires` (`expires_at`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `app_ulogin_email_verifications` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token_hash` CHAR(64)        NOT NULL COMMENT 'SHA-256',
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `email`      VARCHAR(190)    NOT NULL COMMENT '인증 대상 이메일. 변경 요청 시 새 주소',
  `expires_at` DATETIME        NOT NULL COMMENT '발급 +24시간',
  `used_at`    DATETIME        NULL,
  `created_ip` VARCHAR(45)     NOT NULL,
  `created_at` DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email_verif_token` (`token_hash`),
  KEY `idx_email_verif_user`    (`user_id`, `created_at`),
  KEY `idx_email_verif_expires` (`expires_at`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- 4. 활동 로그
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `app_ulogin_login_logs` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        BIGINT UNSIGNED NULL COMMENT '존재하지 않는 계정 시도면 NULL',
  `username_input` VARCHAR(190)    NOT NULL DEFAULT '' COMMENT '입력값 원본. 계정 열거 시도 추적용',
  `service_id`     VARCHAR(64)     NULL,
  `result`         VARCHAR(30)     NOT NULL COMMENT 'success|fail_credential|fail_locked|fail_status|fail_ratelimit|fail_service_blocked|fail_client_auth|fail_migrate_auth|fail_refresh_reuse|fail_refresh_revoked|logout',
  `ip`             VARCHAR(45)     NOT NULL,
  `user_agent`     VARCHAR(255)    NOT NULL DEFAULT '',
  `created_at`     DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_login_logs_user`    (`user_id`, `created_at`),
  KEY `idx_login_logs_ip`      (`ip`, `created_at`),
  KEY `idx_login_logs_result`  (`result`, `created_at`),
  KEY `idx_login_logs_created` (`created_at`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `app_ulogin_point_logs` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`          BIGINT UNSIGNED NOT NULL,
  `service_id`       VARCHAR(64)     NULL,
  `delta`            INT             NOT NULL COMMENT '증감. 부호 있음',
  `balance_after`    INT             NOT NULL COMMENT '처리 후 잔액. 사후 검증용 — 반드시 기록',
  `reason`           VARCHAR(100)    NOT NULL COMMENT 'signup_bonus|admin_adjust|purchase|refund|usage|withdraw_clear 등',
  `ref_type`         VARCHAR(40)     NULL,
  `ref_id`           VARCHAR(64)     NULL,
  `idempotency_key`  VARCHAR(100)    NULL COMMENT 'POST /api/points/consume 이중 차감 방지',
  `memo`             VARCHAR(255)    NULL,
  `admin_id`         BIGINT UNSIGNED NULL COMMENT '관리자 조정 시 조작자',
  `created_at`       DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_point_logs_idem` (`user_id`, `idempotency_key`),
  KEY `idx_point_logs_user`    (`user_id`, `created_at`),
  KEY `idx_point_logs_reason`  (`reason`, `created_at`),
  KEY `idx_point_logs_created` (`created_at`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- (user_id, idempotency_key) UNIQUE: idempotency_key 가 NULL 인 행은 MySQL 에서
-- 중복 허용되므로 일반 적립/차감에는 영향이 없다. consume 재시도만 DB가 막는다.
-- 포인트 변경은 반드시 트랜잭션 + SELECT ... FOR UPDATE 로 잔액을 잠근 뒤 수행한다.


CREATE TABLE IF NOT EXISTS `app_ulogin_admin_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`    BIGINT UNSIGNED NOT NULL,
  `action`      VARCHAR(60)     NOT NULL COMMENT '예: user.suspend, service.create, secret.update',
  `target_type` VARCHAR(40)     NOT NULL COMMENT 'user|service|secret|setting|session',
  `target_id`   VARCHAR(64)     NULL COMMENT 'uuid 또는 service_id 등 문자열 식별자',
  `before_json` TEXT            NULL,
  `after_json`  TEXT            NULL,
  `memo`        VARCHAR(255)    NULL,
  `ip`          VARCHAR(45)     NOT NULL,
  `created_at`  DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_admin_logs_admin`  (`admin_id`, `created_at`),
  KEY `idx_admin_logs_target` (`target_type`, `target_id`, `created_at`),
  KEY `idx_admin_logs_action` (`action`, `created_at`),
  KEY `idx_admin_logs_created`(`created_at`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- 5. 운영
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `app_ulogin_rate_limits` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bucket`       VARCHAR(150)    NOT NULL COMMENT '예: login:ip:1.2.3.4 / register:ip:... / consume:user:12',
  `window_start` DATETIME        NOT NULL COMMENT '고정 윈도우 시작 시각(UTC)',
  `count`        INT             NOT NULL DEFAULT 0,
  `expires_at`   DATETIME        NOT NULL COMMENT '윈도우 종료 시각. 확률적 GC 대상',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rate_limits` (`bucket`, `window_start`),
  KEY `idx_rate_limits_expires` (`expires_at`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 고정 윈도우. INSERT ... ON DUPLICATE KEY UPDATE count = count + 1 한 방으로
-- 원자 증가시킨다. Redis 가 없으므로 이 방식이 최선이다.


CREATE TABLE IF NOT EXISTS `app_ulogin_settings` (
  `skey`       VARCHAR(100) NOT NULL,
  `svalue`     TEXT         NULL,
  `vtype`      VARCHAR(20)  NOT NULL DEFAULT 'string' COMMENT 'string|int|bool|json',
  `label`      VARCHAR(150) NULL COMMENT '관리자 콘솔 표시명',
  `updated_at` DATETIME     NOT NULL,
  PRIMARY KEY (`skey`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `app_ulogin_terms` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`         VARCHAR(30)     NOT NULL COMMENT 'tos|privacy|marketing',
  `version`      VARCHAR(20)     NOT NULL COMMENT '예: 1.0',
  `title`        VARCHAR(150)    NOT NULL,
  `content`      LONGTEXT        NOT NULL,
  `is_required`  TINYINT(1)      NOT NULL DEFAULT 1,
  `published_at` DATETIME        NULL COMMENT 'NULL=미발행(초안)',
  `created_at`   DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_terms_code_version` (`code`, `version`),
  KEY `idx_terms_code_published` (`code`, `published_at`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `app_ulogin_user_consents` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `terms_id`   BIGINT UNSIGNED NOT NULL,
  `agreed`     TINYINT(1)      NOT NULL DEFAULT 1,
  `agreed_at`  DATETIME        NOT NULL,
  `ip`         VARCHAR(45)     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_consents` (`user_id`, `terms_id`),
  KEY `idx_user_consents_terms` (`terms_id`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- 9. 스키마 리비전 (v1.4.0 신규)
-- ---------------------------------------------------------------------
--
-- 이 테이블이 **스키마 리비전의 유일한 저장소**다.
-- install.php 의 `db_version` 은 `SELECT VERSION()` 의 결과 = **MySQL 서버 버전 문자열**이며
-- 스키마 리비전이 아니다. 둘을 섞지 마라.
--
-- 두 경로가 이 테이블을 채운다 (둘 다 같은 최종 스키마에 도달해야 한다):
--   1) 신규 설치  — install.php 가 이 schema.sql 전체를 적용한 뒤
--                   server/migrations/ 의 전 파일을 status='baseline' 으로 기록한다.
--                   schema.sql 이 이미 최종 형태이므로 다시 적용하지 않는다.
--   2) 기존 업그레이드 — server/migrate.php 가 미적용 파일만 순서대로 적용하고
--                   status='applied' 로 기록한다.
--
-- **이 CREATE 문의 정의가 정본이다.** server/migrate.php 는 부트스트랩 시
-- 이 파일에서 이 문장을 찾아 그대로 실행한다 — DDL 을 두 곳에 적지 않기 위함이다.
--
-- 부분 적용의 표현: MySQL 은 DDL 에 트랜잭션이 걸리지 않는다. 그래서 실행 **전에**
-- status='running' 행을 만들고 문장 하나가 끝날 때마다 statements_done 을 올린다.
-- 중간에 죽으면 그 행이 'running' 인 채로 남아 **어디까지 적용됐는지**가 DB 에 남는다.

CREATE TABLE IF NOT EXISTS `app_ulogin_schema_migrations` (
  `version`          VARCHAR(20)  NOT NULL COMMENT '파일명 앞의 번호. 0001, 0002 …',
  `name`             VARCHAR(120) NOT NULL COMMENT '파일의 -- name: 헤더',
  `filename`         VARCHAR(190) NOT NULL,
  `checksum`         CHAR(64)     NOT NULL COMMENT '파일 원문의 SHA-256. 적용 후 파일이 바뀌면 거부한다',
  `status`           VARCHAR(20)  NOT NULL COMMENT 'baseline|running|applied|failed',
  `statements_total` INT          NOT NULL DEFAULT 0,
  `statements_done`  INT          NOT NULL DEFAULT 0 COMMENT '부분 적용 지점. status=running 으로 남았다면 여기까지 적용됐다',
  `started_at`       DATETIME     NOT NULL,
  `finished_at`      DATETIME     NULL,
  `applied_by`       VARCHAR(60)  NULL COMMENT 'install|cli|관리자 username',
  `error_text`       TEXT         NULL COMMENT '실패 사유. DB 메시지는 요약만 담는다',
  PRIMARY KEY (`version`),
  KEY `idx_schema_migrations_status` (`status`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- 10. 시크릿 전달 감사 (v1.4.0 신규)
-- ---------------------------------------------------------------------
--
-- 이 시스템의 왕관 보석은 `payload` 로 나가는 서비스 시크릿이다. v1.3.0 까지
-- 그 전달은 **무기록**이었다 — 탈취된 토큰으로 분당 200회 키를 받아가도 남는 것은
-- sessions.last_seen_at 하나였다. 반대로 드문 관리자 조회(secret.reveal)는 남았다.
--
-- **시간 버킷 UPSERT 다 — 그러나 모든 경로가 접히지는 않는다.**
-- (session_id, source, window_start) 로 1시간 버킷을 묶고 hits 를 올린다.
-- 같은 session_id 로 반복 호출되는 경로(source=session)는 설계대로 접힌다 —
-- 그 세션이 한 시간에 몇 번 불리든 1행이고 hits 만 오른다. 단순 INSERT 라면
-- 분당 200회 트래픽이 하루 28만 행이 됐을 자리다.
--
-- **접히지 않는 경로가 있다 — 보안 재검증 V14b-H-1.**
-- rotate_refresh() 가 회전마다 새 session_id 를 만들기 때문에 source=refresh 는
-- 두 요청이 같은 버킷을 쓰는 일이 없다. 즉 **갱신 경로는 요청과 1:1** 이다.
-- 실측: 같은 세션 GET session 20회 → 2행 / refresh 10회 → 10행 / refresh 150회 → 151행.
-- **그래서 「하루 24행」은 이 표 전체의 전제가 아니다** — 접히는 경로에만 참이다.
--
-- 증가율은 회전 자체의 상한으로 묶었다 — api/auth/refresh.php 의 refresh:ip 120/60 과
-- core/auth.php rotate_refresh() 의 refresh:user 60/3600. **계정당 하루 상한 1,440행.**
-- 1:1 비율 자체를 없애려면 회전에 걸쳐 살아남는 계보 키(sessions.root_session_id)가
-- 필요하고 그것은 마이그레이션 대상이다(V14-M-3, v1.5.0).
-- 근거 전문은 core/logger.php 의 log_secret_access() 머리 주석에 있다.
--
-- **값은 절대 담지 않는다.** secret_keys 에는 키의 *이름*만 콤마로 나열한다.

CREATE TABLE IF NOT EXISTS `app_ulogin_secret_access_logs` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      BIGINT UNSIGNED NOT NULL,
  `service_id`   VARCHAR(64)     NOT NULL,
  `session_id`   CHAR(64)        NOT NULL COMMENT 'sessions.session_id (공개 식별자). 인증자 아님',
  `source`       VARCHAR(20)     NOT NULL COMMENT 'login|exchange|refresh|session',
  `window_start` DATETIME        NOT NULL COMMENT '1시간 버킷의 시작(UTC)',
  `hits`         INT UNSIGNED    NOT NULL DEFAULT 1,
  `secret_keys`  VARCHAR(255)    NOT NULL COMMENT '전달된 키 **이름** 목록. 값은 담지 않는다',
  `first_ip`     VARCHAR(45)     NOT NULL,
  `last_ip`      VARCHAR(45)     NOT NULL COMMENT 'first_ip 와 다르면 같은 세션이 여러 IP 에서 쓰였다는 신호',
  `first_at`     DATETIME        NOT NULL,
  `last_at`      DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_secret_access_bucket` (`session_id`, `source`, `window_start`),
  KEY `idx_secret_access_service` (`service_id`, `window_start`),
  KEY `idx_secret_access_user`    (`user_id`, `window_start`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 확률적 GC 대상 (cron 이 없으므로 요청당 1/100 확률로 실행 — core/gc.php)
--   DELETE FROM app_ulogin_auth_codes          WHERE expires_at < UTC_TIMESTAMP();
--   DELETE FROM app_ulogin_rate_limits         WHERE expires_at < UTC_TIMESTAMP();
--   DELETE FROM app_ulogin_password_resets     WHERE expires_at < UTC_TIMESTAMP();
--   DELETE FROM app_ulogin_email_verifications WHERE expires_at < UTC_TIMESTAMP();
--   DELETE FROM app_ulogin_sessions
--     WHERE (refresh_expires_at IS NOT NULL AND refresh_expires_at < UTC_TIMESTAMP())
--        OR (refresh_expires_at IS NULL AND expires_at < UTC_TIMESTAMP());
-- 로그 테이블(login_logs / point_logs / admin_logs / secret_access_logs)은 GC 하지 않는다.
-- 감사 근거다. secret_access_logs 는 (세션 × 경로 × 시간) 으로 늘어나는데,
-- **갱신 경로는 회전마다 session_id 가 새로 생기므로 요청과 1:1 이다**(V14b-H-1).
-- 상한은 레이트리밋뿐이다 — refresh:ip 120/60 · refresh:user 60/3600, 계정당 하루 1,440행.
-- 네 표의 상한은 core/gc.php 머리 주석에 표로 정리돼 있다.
-- 보존 정책은 아직 없다 — 격차 G-26, D-156(6) 이 v1.9.0 으로 배정했다.
-- schema_migrations 도 GC 대상이 아니다. 적용 이력 자체가 자산이다.
-- =====================================================================
