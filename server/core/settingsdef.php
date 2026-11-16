<?php
declare(strict_types=1);

/**
 * core/settingsdef.php — 관리 가능한 전역 설정 키 정의 (단일 정의)
 * 담당 계약: §8-22(GET) · §8-23(PUT) · §8-26 ~ §8-28 (설정 번들)
 *
 * ── 왜 이 파일이 생겼나 ───────────────────────────────────────────────
 * 정의는 `api/admin/settings.php` 의 지역 변수 `$DEFS` 였다. v1.6.0 의 설정 번들이
 * **같은 13키를 같은 타입·범위·폴백으로** 검증해야 한다. 그 표를 번들 쪽에 다시
 * 적으면 키를 하나 더할 때 두 곳을 고쳐야 하고, 한쪽만 고치면 **관리자 화면은
 * 받는데 번들은 `unknown_key` 로 거절**하는(또는 반대의) 상태가 된다.
 * 그래서 표와 그 표를 읽는 두 함수를 이 파일로 올렸다.
 *
 * 함수 이름에 `admin_` 접두사를 그대로 남겼다 — 호출부와 다른 파일의 상호 참조를
 * 흔들지 않기 위해서다(`core/bootstrap.php` 의 SECRET_ACCESS_LOG 가드 주석이
 * `admin_setting_value()` 를 이름으로 가리킨다).
 *
 * 로드 방법: `core/bootstrap.php` 를 지난 뒤 `require_once` 한다.
 */


// 직접 실행 차단 — 리더 판정 D-34. bootstrap.php 를 거치지 않은 요청은 존재하지 않는 파일이다.
if (!defined('ULOGIN_BOOTSTRAPPED')) {
    // X-Powered-By 제거 — D-154(2). 이 경로는 bootstrap.php 를 거치지 않는다.
    header_remove('X-Powered-By');
    http_response_code(404);
    exit;
}

if (!function_exists('settings_defs')) {
    /**
     * 관리 가능한 설정 키 정의.
     *   [0] vtype           'bool' | 'int' | 'string'
     *   [1] label           관리자 콘솔 표시명
     *   [2] 폴백 상수명     DB 행이 없을 때 읽는 config.php 상수
     *   [3] min             int 전용 하한 (그 외 null)
     *   [4] max             int 전용 상한 (그 외 null)
     *   [5] enum            string 전용 허용값 배열 (그 외 null)
     *   [6] [minLen, maxLen] string 전용 길이 범위 (그 외 null)
     *
     * **[5]·[6] 은 v1.6.0 에서 더했다.** 종전에는 `mail_driver` 의 열거값과
     * `site_name` 의 길이가 `api/admin/settings.php` 의 PUT 분기에 리터럴로 박혀
     * 있었고, 번들 검증기가 같은 값을 다시 적어야 했다. 표에 넣어 한 곳으로 만들었다.
     * **앞의 [0]~[4] 는 인덱스와 순서가 종전과 같다** — 그 인덱스를 읽는 코드가 있다.
     *
     * 앞의 11개는 계약 §8-22 의 목록이며 **순서까지 그대로**다.
     * 리더 판정 D-40 으로 `auth_code_ttl` 을 12번째로 **맨 뒤에 추가**했다 — 중간에 끼우면
     * 기존 11개의 `items` 인덱스가 밀려 관리자 화면의 표 순서가 바뀐다.
     * v1.4.0 후속으로 `secret_access_log` 를 **13번째로 맨 뒤에** 더했다. 같은 규칙이다.
     * **새 키는 언제나 맨 뒤다.** 지금 total 은 16 이다(v1.9.0 이 plat.* 3개를 맨 뒤에 더했다).
     */
    function settings_defs(): array
    {
        return [
            'allow_register'       => ['bool',   '신규 가입 허용',              'ALLOW_REGISTER',       null, null,     null,             null],
            'require_email_verify' => ['bool',   '가입 시 이메일 인증 필수',     'REQUIRE_EMAIL_VERIFY', null, null,     null,             null],
            'signup_bonus_points'  => ['int',    '가입 보너스 포인트',           'SIGNUP_BONUS_POINTS',  0,    1000000,  null,             null],
            'login_fail_threshold' => ['int',    '락아웃 임계 실패 횟수',        'LOCKOUT_THRESHOLD',    1,    100,      null,             null],
            'login_lock_minutes'   => ['int',    '락아웃 지속 시간(분)',         'LOCKOUT_MINUTES',      1,    1440,     null,             null],
            'password_min_length'  => ['int',    '비밀번호 최소 길이',           'PASSWORD_MIN_LENGTH',  8,    64,       null,             null],
            'session_ttl_default'  => ['int',    '기본 access 토큰 수명(초)',    'SESSION_TTL',          60,   604800,   null,             null],
            'refresh_ttl_default'  => ['int',    '기본 refresh 토큰 수명(초)',   'REFRESH_TTL',          300,  31536000, null,             null],
            'maintenance_mode'     => ['bool',   '점검 모드',                   'MAINTENANCE_MODE',     null, null,     null,             null],
            'mail_driver'          => ['string', '메일 발송 방식 (mail|log)',    'MAIL_DRIVER',          null, null,     ['mail', 'log'],  [1, 100]],
            'site_name'            => ['string', '사이트 표시명',                'SITE_NAME',            null, null,     null,             [1, 100]],
            // D-40: core 가 setting_get('auth_code_ttl') 로 읽는데 여기 정의가 없어 조회·변경이 불가능했다.
            // 폴백 상수는 api/sso/authorize.php 의 `11) code 발급` 절이 쓰는 것과 같은 AUTH_CODE_TTL 이고,
            // 그 기본값(60)은 config.sample.php 의 `정책 기본값` 블록에 있다.
            // 행 번호는 적지 않는다 — 편집 한 번에 낡는다(D-92). 파일명 + 절 이름으로 가리킨다.
            //   다시 찾으려면: grep -rn 'AUTH_CODE_TTL' server/
            // 상한 600초: SSO code 는 1회용 단기 자격증명이다. 수명이 길어지면 탈취된 code 의
            // 유효 창이 그만큼 넓어진다(RFC 6749 §4.1.2 권고 = 10분 이내).
            // 하한 30초: 로그인 → 리다이렉트 → exchange 왕복이 끝나야 한다. 더 낮추면 느린 회선에서
            // 정상 로그인이 SSO_CODE_EXPIRED 로 실패한다. 위의 `11) code 발급` 절도 `$ttl <= 0` 이면
            // 설정값을 버리고 상수로 되돌리므로, 0 이하는 그쪽에서도 무효다.
            'auth_code_ttl'        => ['int',    'SSO code 수명(초)',            'AUTH_CODE_TTL',        30,   600,      null,             null],
            // v1.4.0 후속: core/logger.php 가 setting_get('secret_access_log') 로 읽는데 여기
            // 정의가 없어 **DB 에 직접 INSERT 하지 않으면 끌 수 없었다.** D-40 의 auth_code_ttl
            // 과 같은 형태의 결함이다(코드가 읽는데 정의가 없다). 그래서 같은 처리를 한다 —
            // **13번째로 맨 뒤에.** 중간에 끼우면 앞 12개의 items 인덱스가 밀려 관리자 화면의
            // 표 순서가 바뀐다(D-40 후속이 확인한 그 이유 그대로).
            // 폴백 상수 SECRET_ACCESS_LOG 의 정의와 기본값(true)의 근거는 core/bootstrap.php
            // 의 가드 블록에 있다. logger.php 도 같은 상수를 폴백으로 읽는다.
            'secret_access_log'    => ['bool',   '시크릿 전달 감사 기록',        'SECRET_ACCESS_LOG',    null, null,     null,             null],
            // v1.9.0 (설계 116 §3) — 14·15·16번째, **맨 뒤에.** 폴백 상수는 core/bootstrap.php 가드 블록.
            // `audit.chain_head` 는 여기 넣지 않는다(내부 값 — 관리자 화면 비노출).
            'plat.enabled'              => ['bool', '통합로그인 플랫폼 사용(로그·감사·설정·테이블)', 'PLAT_ENABLED',              null, null, null, null],
            'plat.log_retention_days'   => ['int',  '플랫폼 로그 보존(일)',                        'PLAT_LOG_RETENTION_DAYS',   1,    365,  null, null],
            'plat.audit_retention_days' => ['int',  '플랫폼 감사 보존(일)',                        'PLAT_AUDIT_RETENTION_DAYS', 90,   3650, null, null],
        ];
    }
}

if (!function_exists('admin_settings_rows')) {
    /** DB 의 현재 행. settings_all() 은 정적 캐시라 쓰기 직후 값이 낡는다 — 직접 읽는다. */
    function admin_settings_rows(array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $ph   = implode(',', array_fill(0, count($keys), '?'));
        $out  = [];
        foreach (db_all('SELECT skey, svalue, vtype, label, updated_at FROM ' . T_SETTINGS
            . ' WHERE skey IN (' . $ph . ')', $keys) as $r) {
            $out[(string) $r['skey']] = $r;
        }
        return $out;
    }
}

if (!function_exists('admin_setting_value')) {
    /**
     * vtype 대로 캐스팅. DB 행이 없으면 config.php 상수 폴백.
     * @return mixed
     */
    function admin_setting_value(string $key, array $def, array $rows)
    {
        $vtype = $def[0];
        if (isset($rows[$key]) && $rows[$key]['svalue'] !== null) {
            $raw = (string) $rows[$key]['svalue'];
            if ($vtype === 'int') {
                return (int) $raw;
            }
            if ($vtype === 'bool') {
                return ($raw === '1' || $raw === 'true' || $raw === 'on');
            }
            return $raw;
        }
        $const = $def[2];
        if ($const !== null && defined($const)) {
            $v = constant($const);
            if ($vtype === 'int') {
                return (int) $v;
            }
            if ($vtype === 'bool') {
                return (bool) $v;
            }
            return (string) $v;
        }
        return $vtype === 'int' ? 0 : ($vtype === 'bool' ? false : '');
    }
}

if (!function_exists('admin_settings_rows_for_update')) {
    /**
     * 위와 같지만 **행을 잠근다**(`FOR UPDATE`). 설정 번들 apply 전용.
     *
     * 왜 필요한가 — 낙관적 동시성(`expect_plan_hash`)만으로는 창이 남는다.
     * 두 apply 가 **둘 다 커밋 전에** 현재 상태를 읽으면 양쪽 해시가 모두 맞고
     * 둘 다 적용된다. 그것이 보안 검증 V14-H-2(읽기-수정-쓰기 경쟁 조건)와
     * **정확히 같은 형태**다. 그래서 apply 는 트랜잭션 안에서 이 함수로 읽고,
     * 그 읽기가 대상 행(그리고 없는 키의 UNIQUE 갭)을 잠근다. 뒤에 온 apply 는
     * 앞이 커밋할 때까지 기다린 뒤 **바뀐 상태**를 읽고 해시 불일치로 409 가 된다.
     *
     * 잠금은 트랜잭션 수명과 같다 — 고아 잠금이 남지 않는다(설계가 잠금 파일·
     * 잠금 테이블을 기각한 이유가 그것이다).
     *
     * v1.8.0 — SQLite 에는 `FOR UPDATE` 문법이 없다. `sql_for_update()` 가 그 경로에서 ''
     * 를 돌려주고, 대신 `db_tx()` 가 `BEGIN IMMEDIATE` 로 **DB 전체를** 직렬화한다.
     * 「없는 키의 갭」까지 막는다는 성질은 그쪽이 더 강하게 지킨다(설계 E-4).
     */
    function admin_settings_rows_for_update(array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($keys), '?'));
        $out = [];
        foreach (db_all('SELECT skey, svalue, vtype, label, updated_at FROM ' . T_SETTINGS
            . ' WHERE skey IN (' . $ph . ')' . sql_for_update(), $keys) as $r) {
            $out[(string) $r['skey']] = $r;
        }
        return $out;
    }
}
