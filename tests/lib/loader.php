<?php
declare(strict_types=1);

/**
 * tests/lib/loader.php — 티어 2 용 `server/core/**` 격리 로더
 *
 * **DB 도 웹서버도 config.php 도 없이** 순수 함수만 올린다.
 *
 * 왜 `bootstrap.php` 를 쓰지 않는가: 그 파일은 `config.php` 를 요구하고(없으면
 * 500 후 `exit`), CORS 를 처리하고, 확률적 GC 를 돌린다. 테스트가 그 부작용을
 * 감당할 이유가 없다. 대신 `core/*.php` 가 요구하는 **최소 계약**만 만족시킨다:
 *   (a) `ULOGIN_BOOTSTRAPPED` 상수 (D-34 직접 실행 가드)
 *   (b) config 상수
 *   (c) `bootstrap.php` 가 정의하지만 `request.php` 가 호출 시점에 필요로 하는 함수
 *
 * ─── 이 로더가 만드는 사각지대 (정직하게 적는다) ──────────────────────
 *  · `bootstrap.php` **자신의 동작**은 여기서 검증되지 않는다. FORCE_HTTPS 301,
 *    OPTIONS 204, 예외 핸들러, GC — 전부 티어 3 의 몫이다
 *  · `request_is_https()` 는 **스텁**이다. 진짜 함수의 프록시 판정 로직
 *    (`TRUST_PROXY` · `TRUSTED_PROXIES` · `X-Forwarded-Proto`)은 여기서 안 돈다.
 *    D-67 게이트의 검증은 티어 3 이 실 HTTP 로 해야 한다
 *  · CLI SAPI 에서 `header()` 는 무동작이고 `headers_list()` 는 빈 배열이다.
 *    그래서 **실제로 나가는 헤더 바이트는 여기서 볼 수 없다.**
 *    `build_set_cookie_header()` 는 순수 함수라 직접 부를 수 있지만,
 *    `setcookie()`(7.3+ 본체 분기)와의 **바이트 대조는 티어 3** 이다
 * ─────────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/harness.php';

/** 스텁이 돌려줄 값. 테스트가 바꾼다. */
$GLOBALS['ULOGIN_TEST_IS_HTTPS'] = true;

/**
 * `core/` 의 순수 함수 계층을 올린다. 두 번 불러도 안전하다.
 *
 * 상수는 **한 번 정의되면 못 바꾼다.** 다른 config 조합을 보려면
 * 별도 프로세스가 필요하다 — `tier2_unit/t_20_cookie_send.php` 가
 * 그 방식(자식 PHP 프로세스)을 쓴다.
 */
function load_core(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $root = h_root();

    if (!defined('ULOGIN_BOOTSTRAPPED')) {
        define('ULOGIN_BOOTSTRAPPED', 1);
    }
    load_core_constants();

    // bootstrap.php 가 정의하는 것 중 request.php 가 **호출 시점에** 쓰는 것.
    // 미리 정의해 두면 core 파일이 자기 자리에서 그대로 돈다.
    if (!function_exists('request_is_https')) {
        /**
         * 스텁. 진짜 함수는 bootstrap.php 에 있고 프록시 헤더 신뢰 판정을 포함한다.
         * 여기서는 **판정 결과만** 흉내 낸다 — D-75 분기를 양쪽으로 몰기 위해서다.
         */
        function request_is_https(): bool
        {
            return (bool) $GLOBALS['ULOGIN_TEST_IS_HTTPS'];
        }
    }

    require_once $root . '/server/core/response.php';
    require_once $root . '/server/core/crypto.php';
    require_once $root . '/server/core/perm.php';
    require_once $root . '/server/core/request.php';
}

/**
 * `config.sample.php` 와 **같은 이름·같은 의미**의 상수를 정의한다.
 * 값은 테스트용이다(비밀은 전부 더미). 실 `config.php` 는 읽지 않는다 —
 * 개발자 PC 의 설정에 따라 테스트 결과가 달라지면 게이트가 아니다.
 */
function load_core_constants(array $override = array()): void
{
    $c = array(
        'DB_HOST'    => '127.0.0.1',
        'DB_NAME'    => 'ulogin_test',
        'DB_USER'    => 'root',
        'DB_PASS'    => '',
        'DB_CHARSET' => 'utf8mb4',
        'DB_PREFIX'  => 'app_ulogin_',

        // 64자 hex. 실 배포 키가 아니라 형식만 맞춘 더미다.
        'SECRET_ENC_KEY' => str_repeat('0123456789abcdef', 4),
        'APP_KEY'        => str_repeat('fedcba9876543210', 4),

        'FORCE_HTTPS'     => false,
        'TRUST_PROXY'     => false,
        'TRUSTED_PROXIES' => array(),

        'COOKIE_NAME'      => 'ULOGIN_SID',
        'COOKIE_CSRF_NAME' => 'ULOGIN_CSRF',
        'COOKIE_SECURE'    => true,
        'COOKIE_SAMESITE'  => 'Lax',
        'COOKIE_DOMAIN'    => '',
        'COOKIE_PATH'      => '/',

        'MAIL_DRIVER'    => 'log',
        'MAIL_FROM'      => 'no-reply@example.invalid',
        'MAIL_FROM_NAME' => 'ulogin-test',
        'BASE_URL'       => 'https://test.example.invalid',

        'SESSION_TTL'          => 3600,
        'REFRESH_TTL'          => 1209600,
        'REMEMBER_TTL'         => 2592000,
        'AUTH_CODE_TTL'        => 60,
        'PW_RESET_TTL'         => 1800,
        'EMAIL_VERIFY_TTL'     => 86400,
        'LOCKOUT_THRESHOLD'    => 5,
        'LOCKOUT_MINUTES'      => 15,
        'PASSWORD_MIN_LENGTH'  => 8,
        'SIGNUP_BONUS_POINTS'  => 100,
        'ALLOW_REGISTER'       => true,
        'REQUIRE_EMAIL_VERIFY' => false,
        'MAINTENANCE_MODE'     => false,
        'SITE_NAME'            => 'ulogin-test',
        'SECRET_ACCESS_LOG'    => true,
        'DEBUG'                => false,
    );
    foreach ($override as $k => $v) {
        $c[$k] = $v;
    }
    foreach ($c as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
    // 테이블 상수는 접두사에서 파생된다. config.sample.php 와 같은 목록이다.
    $tables = array(
        'T_USERS' => 'users', 'T_SERVICES' => 'services', 'T_USER_SERVICES' => 'user_services',
        'T_SECRETS' => 'service_secrets', 'T_SESSIONS' => 'sessions', 'T_AUTH_CODES' => 'auth_codes',
        'T_PW_RESETS' => 'password_resets', 'T_EMAIL_VERIFY' => 'email_verifications',
        'T_LOGIN_LOGS' => 'login_logs', 'T_POINT_LOGS' => 'point_logs', 'T_ADMIN_LOGS' => 'admin_logs',
        'T_RATE_LIMITS' => 'rate_limits', 'T_SETTINGS' => 'settings', 'T_TERMS' => 'terms',
        'T_CONSENTS' => 'user_consents', 'T_MIGRATIONS' => 'schema_migrations',
        'T_SECRET_ACCESS' => 'secret_access_logs',
        'T_EXT_ACCOUNTS' => 'ext_accounts', 'T_LINK_JOBS' => 'link_jobs', 'T_LINK_ITEMS' => 'link_job_items', 'T_EMERGENCY' => 'emergency',
        'T_ACCESS_GROUPS' => 'access_groups', 'T_ACCESS_GROUP_MEMBERS' => 'access_group_members', 'T_ACCESS_GROUP_SERVICES' => 'access_group_services',
        'T_RC_DRAFTS' => 'rc_drafts', 'T_RC_VERSIONS' => 'rc_versions', 'T_PLAT_LOGS' => 'plat_logs', 'T_PLAT_AUDIT' => 'plat_audit',
        'T_PLAT_COLLS' => 'plat_collections', 'T_PLAT_DOCS' => 'plat_docs', 'T_PLAT_USAGE' => 'plat_usage',
    );
    foreach ($tables as $k => $suffix) {
        if (!defined($k)) {
            define($k, DB_PREFIX . $suffix);
        }
    }
}

/**
 * 자식 PHP 프로세스에서 코드 조각을 돌린다.
 *
 * **상수는 한 번 정의되면 못 바꾼다.** `COOKIE_SECURE=false` 인 세계를 보려면
 * 프로세스를 새로 띄우는 수밖에 없다. 인라인 `-r` 대신 임시 파일을 쓴다 —
 * 셸 인용 규칙이 OS 마다 다르고, 이 저장소는 그 함정(백슬래시 소실)을 이미 겪었다.
 *
 * @return array [exitCode, stdout+stderr]
 */
function run_php_snippet(string $code, ?string $tmpDir = null): array
{
    $tmpDir = $tmpDir === null ? sys_get_temp_dir() : $tmpDir;
    $f = $tmpDir . '/ulogin_t2_' . bin2hex(random_bytes(6)) . '.php';
    if (@file_put_contents($f, "<?php\n" . $code) === false) {
        throw new RuntimeException('cannot write temp snippet to ' . $f);
    }
    try {
        $code_ = 0;
        $out = h_run(h_q(h_php_bin()) . ' -n ' . h_q($f), $code_);
        return array($code_, $out);
    } finally {
        // **실패해도 지운다.** 테스트가 부산물을 남기면 다음 실행이 오염된다.
        @unlink($f);
    }
}
