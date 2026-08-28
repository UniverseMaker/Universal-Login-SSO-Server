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

// 응답 헬퍼를 가장 먼저 올린다. 설정이 없을 때도 JSON 봉투로 답해야 한다.
require_once __DIR__ . '/response.php';

if (!is_file(__DIR__ . '/../config.php')) {
    // 설치 전이다. 상세 사유는 서버 로그에만 남긴다.
    error_log('[ulogin] config.php not found. run install.php first.');
    json_err('SERVER_ERROR', 500);
}
require_once __DIR__ . '/../config.php';

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
// 프록시 헤더를 신뢰할 수 있는 요청인가 — 리더 판정 D-51 / 감사 L-8
//
// `X-Forwarded-*` 는 **클라이언트가 마음대로 붙일 수 있는 문자열**이다.
// 종전 코드는 `X-Forwarded-Proto: https` 한 줄을 무검증으로 믿어서,
// `curl -H 'X-Forwarded-Proto: https' http://…` 로 FORCE_HTTPS 를 통째로 우회할 수 있었다.
//
// 신뢰 조건 (**전부** 만족해야 한다) — 리더 판정 D-67 / 감사 V11-L-6:
//   1) `TRUST_PROXY` 가 정의돼 있고 true — 리버스 프록시·CDN 뒤에 있을 때만 켠다.
//      상수가 없는(구버전) config.php 로도 죽지 않아야 하므로 defined() 로 방어한다.
//      **미정의는 false 와 같다** = 헤더를 무시한다(fail-closed).
//   2) `TRUSTED_PROXIES` 가 **비어 있지 않고** REMOTE_ADDR 이 그 목록에 있어야 한다.
//      비어 있으면 홉을 검증할 근거가 아예 없으므로 신뢰하지 않는다.
//
// **여기 있던 주석은 거짓이었다** (D-67 이 정정을 지시한 항목). 종전 코드는
// `TRUSTED_PROXIES === []` 일 때 `return true` 였고, 주석은 "core/request.php 의
// client_ip() 와 같은 기준" 이라고 단언했다. 사실이 아니었다 — client_ip()
// (`request.php:110`)는 `$proxies !== [] && in_array($remote, $proxies)` 를 요구하므로
// 같은 설정에서 X-Forwarded-For 를 **무시**한다. 그래서 `TRUST_PROXY=true` +
// `TRUSTED_PROXIES=[]` 에서는 `curl -H 'X-Forwarded-Proto: https' http://…` 한 줄로
// FORCE_HTTPS 가 우회됐다(감사 L-8 재발). 이제 두 함수의 조건식이 실제로 동일하다.
// **한쪽만 고치지 마라.** request.php:100-130 과 이 함수를 항상 함께 본다.
//
// 상수 자체(`TRUST_PROXY`)는 config.sample.php / install.php 담당이 추가한다.
// ---------------------------------------------------------------------
if (!function_exists('trust_proxy_headers')) {
    function trust_proxy_headers(): bool
    {
        if (!defined('TRUST_PROXY') || !TRUST_PROXY) {
            return false;
        }
        $proxies = defined('TRUSTED_PROXIES') && is_array(TRUSTED_PROXIES) ? TRUSTED_PROXIES : [];
        if ($proxies === []) {
            // 설정 오류다. 조용히 무시하면 프록시 뒤 배포에서 FORCE_HTTPS 무한
            // 리다이렉트가 나고 원인이 숨는다 — 그래서 FORCE_HTTPS 분기가
            // trust_proxy_misconfigured() 로 이 상태를 감지해 500 으로 멈춘다 (D-67).
            return false;
        }
        return in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), $proxies, true);
    }
}

/**
 * 프록시 설정이 모순 상태인가 — 리더 판정 D-67.
 *
 * `TRUST_PROXY=true` 는 "나는 프록시 뒤에 있다" 는 선언이고,
 * `TRUSTED_PROXIES=[]` 는 "어떤 홉도 신뢰하지 않는다" 는 선언이다. 둘이 함께 있으면
 * 두 선언이 서로를 부정한다. 이 상태에서 프로토콜 헤더를 **무시**하면
 * (프록시가 https 를 받아 http 로 전달하는 정상 구성에서) FORCE_HTTPS 301 이
 * 영원히 되돌아오는 **무한 리다이렉트**가 되고, 로그에도 원인이 남지 않는다.
 * 반대로 **신뢰**하면 누구의 `X-Forwarded-Proto` 든 믿어 FORCE_HTTPS 가 우회된다.
 *
 * 어느 쪽으로도 조용히 넘어가지 않는다 — D-53 의 `BASE_URL` 미설정 처리와 같이
 * **fail-closed 로 멈추고 사유를 서버 로그에 남긴다.**
 */
if (!function_exists('trust_proxy_misconfigured')) {
    function trust_proxy_misconfigured(): bool
    {
        if (!defined('TRUST_PROXY') || !TRUST_PROXY) {
            return false;
        }
        $proxies = defined('TRUSTED_PROXIES') && is_array(TRUSTED_PROXIES) ? TRUSTED_PROXIES : [];
        return $proxies === [];
    }
}

/**
 * 이 요청이 https 로 들어왔는가.
 * 프록시 헤더는 trust_proxy_headers() 가 true 일 때만 본다 (D-51).
 */
if (!function_exists('request_is_https')) {
    function request_is_https(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        if (!trust_proxy_headers()) {
            return false;   // X-Forwarded-* 는 보지 않는다
        }
        // 프록시가 홉을 콤마로 이어 붙이는 경우가 있다("https, http").
        // 가장 앞(클라이언트에 가장 가까운 홉)이 실제 클라이언트 프로토콜이다.
        $xfp = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if ($xfp === '') {
            return false;
        }
        if (strpos($xfp, ',') !== false) {
            $hops = explode(',', $xfp);
            $xfp  = trim((string) $hops[0]);
        }
        return $xfp === 'https';
    }
}

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
