<?php
declare(strict_types=1);

/**
 * core/bootstrap.php — 모든 API 파일이 최상단에서 require 하는 공통 부트스트랩
 * 담당 계약: §1(공통 규약), §8-5(CORS), 스펙 §8-6
 *
 * 하는 일: 에러 표시 off + 로그 on / 타임존 UTC / HTTPS 강제 / 공통 include /
 *          CORS / OPTIONS 프리플라이트 / 예외→표준 에러 응답 / 확률적 GC
 *
 * =====================================================================
 * **PHP 7.2 호환. `server/core/**` 와 `server/api/**` 전체에 적용된다.**
 * (리더 판정 D-57 — 사용자의 실제 호스팅이 PHP 7.2.34 다)
 *
 * 7.2 에서 동작하고 7.4·8.x 에서도 그대로 동작해야 한다(양방향 호환).
 * 버전 분기가 필요하면 `PHP_VERSION_ID` 로 명시적으로 하라.
 *
 * 배포 PHP 가 7.2 이므로 **아래 문법·API 를 쓰면 안 된다.** 로컬 PHP 8 의
 * `php -l` 은 이것들을 전부 통과시킨다 — 통과했다는 사실은 아무 증거가 아니다.
 * 반드시 `_workspace/php72_scan.php` 로 검사하라 (검출 0건이어야 한다).
 *
 *  [7.3 금지] 함수·메서드 **호출**의 trailing comma `foo($a,)`
 *             (배열 리터럴 `[$a,]` 은 예전부터 허용 — 혼동하지 마라)
 *           / `setcookie($n, $v, ['samesite'=>…])` 옵션 배열 형식
 *             → **`send_cookie()`** 를 쓴다 (core/request.php, D-59)
 *           / `session_set_cookie_params(배열)`
 *           / 구조분해 참조 할당 `[&$a, &$b] = $arr`
 *           / 들여쓴 heredoc 종료 마커(유연 heredoc)
 *           / `array_key_first` `array_key_last` `is_countable` `hrtime`
 *           / `JSON_THROW_ON_ERROR` `PASSWORD_ARGON2ID`
 *           / `array_push($a)` 처럼 값 없는 호출
 *  [7.4 금지] 화살표 함수 `fn() => …` / 타입 프로퍼티 `private int $x`
 *           / `??=` / 배열 스프레드 `[...$a]` / 숫자 구분자 `1_000`
 *           / `mb_str_split` `password_algos` `get_mangled_object_vars`
 *           / `strip_tags($s, ['a'])` / `array_merge()` 무인자
 *           / `preg_replace_callback(..., $flags)` 5번째 인자
 *           / 공변 반환 타입
 *  [8.0+ 금지] `match` / `str_contains` `str_starts_with` `str_ends_with`
 *           / 널세이프 `?->` / 유니온 타입 `int|string` / `mixed` / `static` 반환
 *           / `enum` / 생성자 프로퍼티 승격 / named arguments
 *           / 변수 없는 `catch (Foo)` / throw 를 식으로 / `$obj::class`
 *           / 어트리뷰트 `#[…]` / `readonly` / `never` / `foo(...)`
 *
 * 쓸 수 있는 것: `??`(7.0) `?:` 스칼라 타입선언 `?nullable`(7.1) 반환타입
 *               `void`(7.1) 클래스 상수 가시성(7.1) `list()` 키(7.1)
 *               가변인자 `...$args` 언팩(5.6) 배열 리터럴 trailing comma
 * =====================================================================
 */

// ---------------------------------------------------------------------
// core/*.php 직접 실행 차단 — 리더 판정 D-34 / 감사 H-5
//
// 이 파일이 core 의 유일한 진입점이다. 나머지 core/*.php 는 상단에서
// 이 상수를 확인하고 없으면 404 로 죽는다.
//   - .htaccess 가 무시되는 환경(nginx, AllowOverride None)에서
//     /server/core/db.php 를 직접 열면 PHP 가 실행되고, bootstrap 을
//     거치지 않았으므로 display_errors 도 꺼지지 않아 절대경로가 새어나간다.
//   - 상수를 다른 include 보다 먼저 정의해야 한다. response.php 조차
//     이 뒤에 올라온다.
// api/ 엔드포인트에는 이 가드를 넣지 않는다 — 직접 호출이 정상 경로다.
// ---------------------------------------------------------------------
if (!defined('ULOGIN_BOOTSTRAPPED')) {
    define('ULOGIN_BOOTSTRAPPED', 1);
}

// ---------------------------------------------------------------------
// X-Powered-By 제거 — 리더 판정 D-154(2) (v1.2.0 D-99(3) 부터 이월)
//
// 공용 호스팅의 PHP 기본값은 `expose_php=On` 이고, 그러면 SAPI 가 **모든 응답에**
// `X-Powered-By: PHP/7.2.34` 를 붙인다. 저장소가 공개된 지금 정확한 마이너 버전은
// 그 버전의 알려진 취약점으로 바로 이어지는 지문이다.
// `expose_php` 는 PHP_INI_PERDIR 이라 ini_set() 으로 못 바꾼다 — 헤더를 지우는 것이
// 코드에서 할 수 있는 유일한 조치다.
//
// **여기가 맞는 자리인 이유:** 이 파일을 지나는 출력 경로가 넷이고
// (FORCE_HTTPS 301 · OPTIONS 204 · json_out/json_err 의 JSON · config 없음 500)
// 넷 다 이 지점 **뒤에** 있다. response.php 에 두면 301·204 가 빠진다.
// install.php 와 migrate.php 는 이 파일을 거치지 않으므로 각자 같은 줄을 갖고 있고,
// core/*.php 의 직접 실행 가드 404 도 마찬가지다.
// ---------------------------------------------------------------------
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header_remove('X-Powered-By');
}

// 응답 헬퍼를 가장 먼저 올린다. 설정이 없을 때도 JSON 봉투로 답해야 한다.
require_once __DIR__ . '/response.php';

if (!is_file(__DIR__ . '/../config.php')) {
    // 설치 전이다. 상세 사유는 서버 로그에만 남긴다.
    error_log('[ulogin] config.php not found. run install.php first.');
    json_err('SERVER_ERROR', 500);
}
require_once __DIR__ . '/../config.php';

// ---------------------------------------------------------------------
// 새 테이블 상수의 하위호환 가드 — 리더 판정 D-151
//
// **기존 설치본의 config.php 는 v1.4.0 이전에 install.php 가 만든 파일이다.**
// 그 파일에는 v1.4.0 에서 새로 생긴 T_* 상수가 없다. 코드가 그것을 참조하는 순간
// PHP 8 에서는 Error, PHP 7.2 에서는 경고 + 상수명 문자열로 떨어져
// `SELECT ... FROM T_SECRET_ACCESS` 라는 존재하지 않는 테이블 쿼리가 된다.
// 어느 쪽이든 업그레이드 직후 전 엔드포인트가 죽는다.
//
// 그래서 **새 테이블 상수는 반드시 여기에 defined() 가드로 함께 추가한다.**
// config.sample.php / install.php 의 ins_config_spec() 과 값이 같아야 한다 —
// 세 곳이 한 벌이다. 스키마를 바꾸는 다음 버전들도 이 규약을 따른다.
//
// (설정값 상수는 여기에 넣지 않는다. 그것은 운영자가 골라야 하는 값이고,
//  조용한 기본값은 D-53·D-67·D-75 의 fail-closed 규약과 충돌한다.
//  테이블명은 운영자가 고를 값이 아니라 DB_PREFIX 에서 파생되는 이름이라 예외다.)
// ---------------------------------------------------------------------
if (!defined('T_MIGRATIONS')) {
    define('T_MIGRATIONS', DB_PREFIX . 'schema_migrations');
}
if (!defined('T_SECRET_ACCESS')) {
    define('T_SECRET_ACCESS', DB_PREFIX . 'secret_access_logs');
}

// ---------------------------------------------------------------------
// 정책 기본값 상수의 하위호환 가드 — **위 규칙의 유일한 예외다. 읽고 늘려라.**
//
// 위 괄호가 "설정값 상수는 여기 넣지 않는다" 고 적은 이유는, 운영자가 골라야 하는
// 값에 조용한 기본값을 주면 잘못된 설정이 소리 없이 넘어가기 때문이다(D-53·D-67·D-75).
// 그 규칙은 **기본값이 위험한 쪽으로 떨어지는 상수**를 겨눈 것이다.
//
// SECRET_ACCESS_LOG 는 반대 방향이다. 이 상수가 없으면 무슨 일이 생기는지 보라:
//   - core/logger.php 는 값을 못 찾으면 **기록한다**(감사 유지)
//   - api/admin/settings.php 의 admin_setting_value() 는 DB 행도 상수도 없으면
//     bool 을 **false 로** 떨어뜨린다 → 관리자 화면이 "꺼짐" 으로 보인다
// 즉 가드가 없으면 업그레이드한 설치본에서 **화면과 실제 동작이 어긋난다** —
// 운영자는 감사가 꺼져 있다고 믿는데 실제로는 켜져 있다. 그 반대보다 낫지도 않다.
// 두 곳이 같은 폴백을 보게 만드는 것이 이 가드의 목적이고, 그래서 logger.php 도
// 하드코딩 true 가 아니라 이 상수를 읽는다 — **폴백 정의는 한 곳이어야 한다.**
//
// 기본값은 **true(기록)** 다. 이 감사 기록은 v1.4.0 이 "누가 언제 이 키를 받아 갔나"
// 에 답할 데이터가 DB 에 아예 없었기 때문에 만든 것이다(D-153 H-2). 기본이 꺼짐이면
// 그 답이 기본으로 다시 사라진다. 시간 버킷 UPSERT 라 행 증가도 (세션 × 경로 × 시간)
// 으로 묶여 있다 — 켜 두는 비용이 작다. **끄는 것은 운영자의 명시적 조작이어야 한다.**
//
// 이 자리에 상수를 더할 때는 위 조건을 만족하는지 먼저 확인하라:
//   (1) 기본값이 **안전한 쪽**으로 떨어지는가
//   (2) 상수가 없을 때 **두 코드 경로가 서로 다른 답**을 내는가
// 둘 다 아니면 여기가 아니라 install.php 의 설정 화면에 넣어야 하는 값이다.
// ---------------------------------------------------------------------
if (!defined('SECRET_ACCESS_LOG')) {
    define('SECRET_ACCESS_LOG', true);
}

ini_set('display_errors', '0');       // 스택 트레이스는 경로·쿼리를 노출한다
ini_set('log_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/request.php';
require_once __DIR__ . '/perm.php';
require_once __DIR__ . '/serializer.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/payload.php';
require_once __DIR__ . '/ratelimit.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/gc.php';

// ---------------------------------------------------------------------
// 예외 → 표준 에러 응답
//   AppError 는 계약 §2 의 코드를 그대로 내보낸다.
//   그 외 예외는 SERVER_ERROR 로 뭉개고 상세는 서버 로그에만 남긴다.
//   (CORS·레이트리밋보다 먼저 등록해야 그 안에서 난 예외도 JSON 으로 나간다)
// ---------------------------------------------------------------------
set_exception_handler(function (Throwable $e) {
    if ($e instanceof AppError) {
        json_err($e->code, $e->status, $e->extra);
    }
    error_log('[ulogin] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_err('SERVER_ERROR', 500, DEBUG ? ['debug' => $e->getMessage()] : []);
});

// 치명적 오류가 HTML 로 새어 나가 프론트의 JSON 파싱을 깨뜨리는 것을 막는다.
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e === null || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }
    error_log('[ulogin] fatal: ' . $e['message'] . ' @ ' . $e['file'] . ':' . $e['line']);
    if (!headers_sent()) {
        json_err('SERVER_ERROR', 500);
    }
});

// ---------------------------------------------------------------------
// 전송 보안 판정 — core/transport.php 로 옮겼다 (보안 재검증 V14b-M-3)
//
// trust_proxy_headers() · trust_proxy_misconfigured() · request_is_https() 세 함수는
// 여기 있었다. 그래서 bootstrap 을 거치지 않는 server/migrate.php 가 그 판정을 쓸 수
// 없었고 축약 사본(HTTPS 와 포트 443 만)을 갖게 됐다. 그 사본은 프록시가 TLS 를
// 종료하는 정상 배포에서 마이그레이션 화면을 **영구히 403** 으로 만들었다.
//
// 조건식이 두 곳에 있는 것이 D-51·D-67 이 겨눈 사고이므로, 사본을 고치는 대신
// 정의를 한 파일로 뺐다. 그 파일은 $_SERVER 4키 + TRUST_PROXY/TRUSTED_PROXIES 만
// 참조하고 DB·스키마·다른 core/ 파일을 건드리지 않는다 — 그래서 러너가 로드해도
// 「고치려는 스키마에 의존하면 안 된다」는 전제를 깨지 않는다.
//
// **request.php 의 client_ip() 와 조건식이 같아야 한다** — 한쪽만 고치지 마라
// (D-67 이 정정한 항목이다). 지금은 client_ip() 가 자기 사본을 갖고 있고,
// 그것을 transport.php 로 합치는 것은 별건이다.
// ---------------------------------------------------------------------
require_once __DIR__ . '/transport.php';

// ---------------------------------------------------------------------
// HTTPS 강제 — 리더 판정 D-6
//   HTTPS 없이 운영하면 access_token 과 AI API 키가 평문으로 흐른다.
// ---------------------------------------------------------------------
if (PHP_SAPI !== 'cli' && defined('FORCE_HTTPS') && FORCE_HTTPS) {
    if (!request_is_https()) {
        // 리더 판정 D-67 / 감사 V11-L-6.
        // 리다이렉트하기 **전에** 프록시 설정 모순을 먼저 잡는다. `TRUST_PROXY=true` +
        // `TRUSTED_PROXIES=[]` 이면 request_is_https() 가 X-Forwarded-Proto 를 보지
        // 않으므로, 프록시가 https 를 받아 http 로 전달하는 정상 구성에서 이 301 이
        // 영원히 반복된다. 무한 루프는 원인을 숨긴다 — 멈추고 로그를 남기는 쪽이 낫다.
        // 실제 https 로 들어온 요청(`$_SERVER['HTTPS']`·포트 443)은 이 분기에 오지
        // 않으므로, TRUSTED_PROXIES 를 올바로 채운 배포는 전혀 영향받지 않는다.
        if (trust_proxy_misconfigured()) {
            error_log('[ulogin] TRUST_PROXY is on but TRUSTED_PROXIES is empty. '
                . 'proxy headers cannot be verified and FORCE_HTTPS would loop. '
                . 'set TRUSTED_PROXIES to your proxy IP(s) in config.php.');
            json_err('SERVER_ERROR', 500);
        }
        // 감사 M-2 / 리더 판정 D-53.
        // Location 에 $_SERVER['HTTP_HOST'] 를 그대로 쓰면 안 된다. Host 는 클라이언트가
        // 정하는 값이므로 `Host: evil.com` 한 줄로 301 목적지를 공격자 호스트로 바꿀 수
        // 있다(호스트 헤더 주입 → 오픈 리다이렉트). 정본 호스트는 BASE_URL 뿐이다.
        $canonicalHost = '';
        if (defined('BASE_URL') && BASE_URL !== '' && strpos((string) BASE_URL, '__') === false) {
            $parts = parse_url((string) BASE_URL);
            if (is_array($parts) && !empty($parts['host'])) {
                $canonicalHost = (string) $parts['host'];
                if (!empty($parts['port']) && (int) $parts['port'] !== 443) {
                    $canonicalHost .= ':' . (int) $parts['port'];
                }
            }
        }
        if ($canonicalHost === '') {
            // BASE_URL 이 없으면 어디로 보내야 하는지 알 수 없다. 추측해서 보내는 것보다
            // 멈추는 편이 안전하다(fail-closed). 상세 사유는 서버 로그에만 남긴다.
            error_log('[ulogin] FORCE_HTTPS is on but BASE_URL is not configured. refusing to redirect.');
            json_err('SERVER_ERROR', 500);
        }
        $path = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;   // 스킴 상대 URL(`//evil.com`) 로 해석되지 않게 한다
        }
        if (strpos($path, '//') === 0) {
            $path = '/' . ltrim($path, '/');
        }
        header('Location: https://' . $canonicalHost . $path, true, 301);
        exit;
    }
}

// CORS — service 의 allowed_origins 반향 방식. `*` 는 쓰지 않는다.
cors_handle();

// OPTIONS 프리플라이트는 여기서 끝낸다 (본문·인증 처리로 내려보내지 않는다)
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// 전 엔드포인트 공통 상한 (스펙 §8-3). 개별 버킷은 각 엔드포인트가 추가로 건다.
if (PHP_SAPI !== 'cli') {
    rate_limit('api:ip:' . client_ip(), 300, 60);
}

// cron 이 없으므로 확률적 GC
if (random_int(1, 100) === 1) {
    gc_expired();
}
