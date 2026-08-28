<?php
declare(strict_types=1);

/**
 * core/request.php — 입력 파싱·검증·CORS·CSRF
 * 담당 계약: §1(공통 규약), §1-1(에러 field), §8-4(CSRF), §8-5(CORS)
 */


// 직접 실행 차단 — 리더 판정 D-34. bootstrap.php 를 거치지 않은 요청은 존재하지 않는 파일이다.
if (!defined('ULOGIN_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}
// ---------------------------------------------------------------------
// 메서드 · 본문
// ---------------------------------------------------------------------

/**
 * 허용 메서드 확인. GET 으로 로그인이 되면 CSRF·리퍼러 유출 경로가 생긴다.
 *
 * D-10 반영: 405 전용 코드 METHOD_NOT_ALLOWED 로 응답한다.
 * 응답 data 에 allowed(허용 메서드 배열)를 함께 싣는다.
 */
function only_method(string ...$methods): void
{
    $m = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (in_array($m, $methods, true)) {
        return;
    }
    if (!headers_sent()) {
        header('Allow: ' . implode(', ', $methods));
    }
    throw new AppError('METHOD_NOT_ALLOWED', 405, [
        'field'   => 'method',
        'allowed' => $methods,
    ]);
}

/**
 * 요청 본문을 배열로. application/json 이 기본이고 폼 전송도 받는다.
 * 본문이 있는데 JSON 파싱이 실패하면 VALIDATION_FAILED.
 */
function body_json(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $ctype = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
    if (strpos($ctype, 'application/json') === false && !empty($_POST)) {
        $cached = $_POST;
        return $cached;
    }
    $raw = file_get_contents('php://input');
    if ($raw === false || trim((string) $raw) === '') {
        $cached = !empty($_POST) ? $_POST : [];
        return $cached;
    }
    $v = json_decode((string) $raw, true);
    if (!is_array($v)) {
        throw new AppError('VALIDATION_FAILED', 400, [
            'field'   => 'body',
            'message' => '요청 본문 형식이 올바르지 않습니다. (JSON)',
        ]);
    }
    $cached = $v;
    return $cached;
}

/** 요청 헤더 한 개. CGI 모드까지 커버한다. */
function req_header(string $name): ?string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
        return (string) $_SERVER[$key];
    }
    if (isset($_SERVER['REDIRECT_' . $key]) && $_SERVER['REDIRECT_' . $key] !== '') {
        return (string) $_SERVER['REDIRECT_' . $key];
    }
    if (function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) {
            if (strcasecmp($k, $name) === 0 && $v !== '') {
                return (string) $v;
            }
        }
    }
    return null;
}

// ---------------------------------------------------------------------
// 클라이언트 식별
// ---------------------------------------------------------------------

/**
 * 요청 IP. 스펙 §8-3.
 * X-Forwarded-For 는 TRUSTED_PROXIES 에 등록된 홉에서 온 요청에서만 신뢰한다.
 * 무조건 믿으면 헤더 위조로 레이트리밋이 통째로 우회된다.
 */
function client_ip(): string
{
    $remote  = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $proxies = defined('TRUSTED_PROXIES') && is_array(TRUSTED_PROXIES) ? TRUSTED_PROXIES : [];

    // 리더 판정 D-53: 프록시 헤더 신뢰의 주 게이트는 TRUST_PROXY 하나로 통일한다.
    // bootstrap.php 의 trust_proxy_headers() 와 기준이 달라지면, 한쪽은 X-Forwarded-* 를
    // 무시하는데 다른 쪽은 신뢰하는 상태가 되어 레이트리밋·락아웃이 IP 위조로 우회된다.
    $trustProxy = defined('TRUST_PROXY') && TRUST_PROXY;

    if ($trustProxy && $proxies !== [] && in_array($remote, $proxies, true)) {
        $xff = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            $hops = array_map('trim', explode(',', $xff));
            for ($i = count($hops) - 1; $i >= 0; $i--) {
                $ip = $hops[$i];
                if (in_array($ip, $proxies, true)) {
                    continue;   // 신뢰 프록시는 건너뛴다
                }
                if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                    return substr($ip, 0, 45);
                }
                break;
            }
        }
    }
    if (filter_var($remote, FILTER_VALIDATE_IP) === false) {
        return '0.0.0.0';
    }
    return substr($remote, 0, 45);
}

/** User-Agent 에서 사람이 읽을 기기명을 만든다. 예: "Chrome / Windows" */
function device_label_from_ua(?string $ua = null): ?string
{
    $ua = $ua !== null ? $ua : (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (trim($ua) === '') {
        return null;
    }
    $browser = 'Unknown';
    $bmap = [
        'Edg'      => 'Edge',
        'OPR'      => 'Opera',
        'Whale'    => 'Whale',
        'SamsungBrowser' => 'Samsung Internet',
        'Firefox'  => 'Firefox',
        'Chrome'   => 'Chrome',
        'Safari'   => 'Safari',
    ];
    foreach ($bmap as $needle => $label) {
        if (strpos($ua, $needle) !== false) {
            $browser = $label;
            break;
        }
    }
    $os = 'Unknown';
    $omap = [
        'Windows NT' => 'Windows',
        'iPhone'     => 'iPhone',
        'iPad'       => 'iPad',
        'Android'    => 'Android',
        'Mac OS X'   => 'macOS',
        'Linux'      => 'Linux',
    ];
    foreach ($omap as $needle => $label) {
        if (strpos($ua, $needle) !== false) {
            $os = $label;
            break;
        }
    }
    return mb_substr($browser . ' / ' . $os, 0, 80);
}

// ---------------------------------------------------------------------
// 값 검증 — 실패는 전부 VALIDATION_FAILED(400) + field
// ---------------------------------------------------------------------

/** @return mixed|null */
function in_raw(array $in, string $key)
{
    return array_key_exists($key, $in) ? $in[$key] : null;
}

function v_fail(string $field, string $message): void
{
    throw new AppError('VALIDATION_FAILED', 400, ['field' => $field, 'message' => $message]);
}

/**
 * 문자열. 앞뒤 공백 제거 후 길이 검사.
 * 값이 없고 required=false 면 $default 를 돌려준다.
 */
function v_string(array $in, string $key, int $min = 0, int $max = 255, bool $required = false, ?string $default = null): ?string
{
    $v = in_raw($in, $key);
    if ($v === null || $v === '') {
        if ($required) {
            v_fail($key, '필수 입력 항목입니다.');
        }
        return $default;
    }
    if (is_bool($v) || is_array($v) || is_object($v)) {
        v_fail($key, '값 형식이 올바르지 않습니다.');
    }
    $s = trim((string) $v);
    if ($s === '') {
        if ($required) {
            v_fail($key, '필수 입력 항목입니다.');
        }
        return $default;
    }
    $len = mb_strlen($s, 'UTF-8');
    if ($len < $min) {
        v_fail($key, $min . '자 이상 입력해 주세요.');
    }
    if ($len > $max) {
        v_fail($key, $max . '자 이내로 입력해 주세요.');
    }
    return $s;
}

function v_int(array $in, string $key, ?int $min = null, ?int $max = null, bool $required = false, ?int $default = null): ?int
{
    $v = in_raw($in, $key);
    if ($v === null || $v === '') {
        if ($required) {
            v_fail($key, '필수 입력 항목입니다.');
        }
        return $default;
    }
    if (is_bool($v) || is_array($v) || !is_numeric($v)) {
        v_fail($key, '숫자를 입력해 주세요.');
    }
    $n = (int) $v;
    if ($min !== null && $n < $min) {
        v_fail($key, $min . ' 이상이어야 합니다.');
    }
    if ($max !== null && $n > $max) {
        v_fail($key, $max . ' 이하여야 합니다.');
    }
    return $n;
}

/** 불리언. "1"/"true"/"on"/"yes" 를 참으로 본다 (폼 전송 대응). */
function v_bool(array $in, string $key, bool $required = false, ?bool $default = null): ?bool
{
    $v = in_raw($in, $key);
    if ($v === null || $v === '') {
        if ($required) {
            v_fail($key, '필수 입력 항목입니다.');
        }
        return $default;
    }
    if (is_bool($v)) {
        return $v;
    }
    if (is_array($v) || is_object($v)) {
        v_fail($key, '값 형식이 올바르지 않습니다.');
    }
    $s = strtolower(trim((string) $v));
    if (in_array($s, ['1', 'true', 'on', 'yes', 'y'], true)) {
        return true;
    }
    if (in_array($s, ['0', 'false', 'off', 'no', 'n'], true)) {
        return false;
    }
    v_fail($key, '값 형식이 올바르지 않습니다.');
    return null; // 도달하지 않음
}

/** 이메일. 소문자로 정규화해서 돌려준다 (DB 도 소문자 저장). */
function v_email(array $in, string $key, bool $required = false, ?string $default = null): ?string
{
    $s = v_string($in, $key, 3, 190, $required, $default);
    if ($s === null) {
        return null;
    }
    $s = mb_strtolower($s, 'UTF-8');
    if (filter_var($s, FILTER_VALIDATE_EMAIL) === false) {
        v_fail($key, '이메일 형식이 올바르지 않습니다.');
    }
    return $s;
}

/** 화이트리스트 값. 정렬 컬럼처럼 바인딩 불가한 값도 이걸로 거른다. */
function v_enum(array $in, string $key, array $allowed, bool $required = false, ?string $default = null): ?string
{
    $v = in_raw($in, $key);
    if ($v === null || $v === '') {
        if ($required) {
            v_fail($key, '필수 입력 항목입니다.');
        }
        return $default;
    }
    if (is_array($v) || is_object($v) || is_bool($v)) {
        v_fail($key, '값 형식이 올바르지 않습니다.');
    }
    $s = trim((string) $v);
    if (!in_array($s, $allowed, true)) {
        v_fail($key, '허용되지 않은 값입니다.');
    }
    return $s;
}

/**
 * 페이징 파라미터. 계약 §1 — per_page 최대 100, 초과 시 clamp.
 * @return array{0:int,1:int,2:int} [page, per_page, offset]
 */
function page_params(array $in, int $defaultPerPage = 20): array
{
    $page = (int) (in_raw($in, 'page') ?? 1);
    $per  = (int) (in_raw($in, 'per_page') ?? $defaultPerPage);
    if ($page < 1) {
        $page = 1;
    }
    if ($per < 1) {
        $per = $defaultPerPage;
    }
    if ($per > 100) {
        $per = 100;     // 에러가 아니라 clamp 한다 (계약 §1)
    }
    return [$page, $per, ($page - 1) * $per];
}

// ---------------------------------------------------------------------
// CORS — 스펙 §8-5
// ---------------------------------------------------------------------

/** 이 서버 자신의 origin. 자체 UI 는 same-origin 이라 CORS 가 필요 없지만 방어적으로 허용한다. */
function own_origin(): string
{
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    return ($https ? 'https://' : 'http://') . $host;
}

/** 등록된 서비스 중 하나라도 이 Origin 을 허용하는가. */
function origin_allowed(string $origin): bool
{
    if ($origin === own_origin()) {
        return true;
    }
    try {
        $rows = db_all('SELECT allowed_origins FROM ' . T_SERVICES . ' WHERE status = ? LIMIT 500', ['active']);
    } catch (Throwable $e) {
        return false;   // 설치 전이거나 DB 장애 — CORS 헤더를 붙이지 않는다
    }
    foreach ($rows as $r) {
        foreach (json_col($r['allowed_origins']) as $o) {
            if (is_string($o) && $o !== '' && $o === $origin) {
                return true;
            }
        }
    }
    return false;
}

/**
 * CORS 처리. 화이트리스트 반향 방식.
 * `*` 는 쓰지 않는다 — 등록되지 않은 앱까지 API 를 부르게 된다.
 * 토큰 인증이므로 Allow-Credentials 는 켜지 않는다.
 */
function cors_handle(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    if ($origin === null || $origin === '') {
        return;   // 브라우저 교차 출처 요청이 아니다
    }
    $origin = (string) $origin;

    // 캐시 오염 방지 — 허용 여부와 무관하게 항상 붙인다
    if (!headers_sent()) {
        header('Vary: Origin');
    }
    if (!origin_allowed($origin)) {
        return;   // 헤더를 붙이지 않으면 브라우저가 알아서 막는다
    }
    if (headers_sent()) {
        return;
    }
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token, X-Auth-Token');
    header('Access-Control-Max-Age: 600');
}

// ---------------------------------------------------------------------
// CSRF — 스펙 §8-4
// ---------------------------------------------------------------------

/**
 * 쿠키 옵션 배열을 만든다.
 *
 * 이 배열은 **PHP 7.3+ 의 `setcookie($name, $value, $options)` 형태**다.
 * 7.2 에서는 이 배열을 setcookie() 에 그대로 넘길 수 없다 — 3번째 인자가
 * `$expires` 로 해석되어 정수 캐스팅되고 SameSite·HttpOnly·Secure·Path 가
 * 통째로 사라진다. **전송은 반드시 send_cookie() 를 통해서 한다.**
 *
 * 배열을 만드는 책임(이 함수)과 전송하는 책임(send_cookie)을 분리해 둔다.
 * — 리더 판정 D-59
 */
function cookie_options(int $ttl): array
{
    $opt = [
        'expires'  => $ttl > 0 ? time() + $ttl : 0,
        'path'     => defined('COOKIE_PATH') ? COOKIE_PATH : '/',
        'secure'   => defined('COOKIE_SECURE') ? (bool) COOKIE_SECURE : true,
        'httponly' => true,
        'samesite' => cookie_samesite_safe(defined('COOKIE_SAMESITE') ? (string) COOKIE_SAMESITE : 'Lax'),
    ];
    if (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN !== '') {
        $opt['domain'] = COOKIE_DOMAIN;
    }
    // 감사 V11-L-4 / 리더 판정 D-66.
    // SameSite=None 인 쿠키는 Secure 가 없으면 Chrome·Firefox·Safari 가 **통째로 버린다.**
    // 그러면 세션 쿠키가 저장되지 않아 "로그인이 안 되는데 응답은 200" 인 상태가 된다 —
    // 원인이 서버 어디에도 남지 않는 종류의 사고다. 브라우저 요구사항을 코드로 강제한다.
    if ($opt['samesite'] === 'None' && empty($opt['secure'])) {
        error_log('[ulogin] COOKIE_SAMESITE=None requires Secure. forcing secure=true.');
        $opt['secure'] = true;
    }
    return $opt;
}

/**
 * `SameSite` 값 화이트리스트 — 감사 V11-L-4 / 리더 판정 D-66.
 *
 * 브라우저가 해석하는 값은 `Lax` `Strict` `None` 셋뿐이다. PHP 본체 `setcookie()` 도
 * 이 값을 **검증하지 않으므로**(두 분기 공통) 오타(`lax`)나 임의 문자열이 그대로
 * 헤더에 실린다. `'Lax; Domain=evil.example'` 처럼 구분자를 넣으면 **추가 속성이
 * 실제 속성으로 해석**되고, `'None'` + Secure 없음이면 쿠키가 조용히 거부된다.
 *
 * 목록 밖의 값은 `Lax` 로 강제하고 `error_log()` 에 남긴다 — 조용히 넘기지 않는다.
 */
function cookie_samesite_safe(string $ss): string
{
    if (!in_array($ss, ['Lax', 'Strict', 'None'], true)) {
        error_log('[ulogin] invalid COOKIE_SAMESITE: ' . $ss . ' - falling back to Lax');
        return 'Lax';
    }
    return $ss;
}

/**
 * `path` · `domain` · `samesite` 에 쿠키 구분자가 섞였는가 — 감사 V11-M-1 / 리더 판정 D-65.
 *
 * PHP 본체(`ext/standard/head.c`)는 이 세 옵션에 `, ; 공백 \t \r \n \013 \014` 가 있으면
 * 쿠키를 **전송하지 않는다**(8.0+ 는 `ValueError`, 7.3~7.4 는 경고 + `false`).
 * 7.2 분기에는 그 층이 존재하지 않으므로 같은 문턱을 코드에 둔다. 없으면
 * `COOKIE_PATH = '/; HttpOnly'` 같은 설정이 **7.2 배포에서만** 조용히 통과해
 * 쿠키 속성 주입이 된다(실측: `httponly=false` 인데 `HttpOnly` 가 붙는다).
 *
 * `header()`(SAPI)는 CR/LF 만 막는다. `;` 공백 `,` 는 이 검사가 유일한 방어다.
 *
 * @return string|null 문제가 있으면 해당 옵션 키, 없으면 null
 */
function cookie_attr_offender(array $opt): ?string
{
    foreach (['path', 'domain', 'samesite'] as $k) {
        if (!isset($opt[$k])) {
            continue;
        }
        $v = (string) $opt[$k];
        if ($v !== '' && strpbrk($v, ",; \t\r\n\013\014") !== false) {
            return $k;
        }
    }
    return null;
}

/**
 * `Set-Cookie` 헤더 값을 조립한다 — **PHP 본체 `php_setcookie()` 와 바이트 단위로 같게.**
 *
 * PHP 7.2 에는 옵션 배열 형식이 없으므로 헤더를 직접 만들어야 한다. 그때
 * 형식이 조금이라도 다르면 **7.2 배포에서만 조용히 다르게 동작한다.** 그래서
 * ext/standard/head.c 의 조립 순서·표기를 그대로 따른다:
 *
 *   Set-Cookie: <name>=<rawurlencode(value)>; expires=<RFC7231>; Max-Age=<n>
 *               ; path=<p>; domain=<d>; secure; HttpOnly; SameSite=<s>
 *
 * 본체와 맞춘 세부 사항 (하나라도 어기면 두 분기가 갈라진다):
 *  - **이름은 인코딩하지 않는다.** 본체는 이름을 검증만 하고 원문 그대로 내보낸다.
 *    (감사 V11-L-1 / D-65) 예전 구현은 `rawurlencode($name)` 를 통과시켰고 주석에
 *    "검증을 통과했으므로 항등이다" 라고 적혀 있었으나 **거짓이었다** —
 *    `strpbrk($name, "=,; \t\r\n\013\014")` 는 `[ ] + % ( ) / ? : @ & $ ! * '` 와
 *    비ASCII 를 걸러내지 않고 그 문자들이 정확히 인코딩 대상이다. 결과적으로
 *    `ulogin[sid]` 가 7.2 에서만 `ulogin%5Bsid%5D` 로 나가 로그인이 조용히 깨졌다.
 *    이름의 안전성은 `send_cookie()` 의 `strpbrk` 검증(본체와 동일)이 담보한다
 *  - 값은 `rawurlencode()` (setcookie 의 기본 동작. setrawcookie 만 무인코딩)
 *  - **값이 빈 문자열이면 `expires` 옵션을 무시하고** `<name>=deleted;
 *    expires=Thu, 01-Jan-1970 00:00:01 GMT; Max-Age=0` 을 낸다. 본체가 그렇게
 *    한다(MSIE 가 빈 값으로는 쿠키를 지우지 않기 때문). 삭제 경로가 여기다
 *  - `expires` 는 0 이하면 **아예 붙이지 않는다** (브라우저 세션 쿠키)
 *  - `Max-Age` 는 `max(0, expires - time())`
 *  - 날짜는 **`gmdate('D, d-M-Y H:i:s') . ' GMT'`** — 본체는 RFC 7231 의 공백 형식이
 *    아니라 **구 Netscape 형식(일-월-년 하이픈)** 을 쓴다. `D, d M Y` 로 쓰면
 *    7.2 에서만 다른 문자열이 나간다 (실측으로 확인했다)
 *
 *    > **경고 (리더 판정 D-68).** 위 두 곳의 `01-Jan-1970` / `d-M-Y` 하이픈은 오타가
 *    > 아니다. 이 독스트링에 한때 `01 Jan 1970`(공백)이라고 적힌 줄이 있었고, 그것을
 *    > 근거로 코드를 RFC 7231 형식(`D, d M Y`)으로 "고치면" **7.2 배포에서만 다른
 *    > 만료 문자열이 나가는 결함이 그대로 되살아난다.** 12a 라운드가 두 분기 헤더
 *    > 대조로 실측해 잡은 결함이 정확히 그것이다. 바꾸지 마라 —
 *    > 바꾸려면 먼저 PHP 본체와 헤더 바이트를 대조해라
 *  - 속성 표기: `expires` `Max-Age` `path` `domain` `secure` `HttpOnly` `SameSite`
 *    (본체의 대소문자 그대로. 임의로 통일하지 마라)
 *
 * `path`·`domain`·`samesite` 에 쿠키 구분자가 있으면 **본체와 같이 거부한다**
 * (감사 V11-M-1 / D-65). 정상 경로에서는 `send_cookie()` 가 먼저 같은 검사를 하므로
 * 여기 도달하지 않는다 — 이 검사는 이 함수를 직접 부르는 코드를 위한 것이다.
 *
 * **주의:** 이 함수는 "주어진 배열을 본체와 같은 문자열로 만든다" 는 계약만 진다.
 * 누락 키를 기본값으로 채우지 마라 — 그 순간 7.3+ 분기(본체)와 출력이 갈라지고
 * 두 분기 대조가 무의미해진다. 하한선은 `send_cookie()` 의 책임이다 (V11-L-3).
 *
 * @param array $opt cookie_options() 형태
 * @throws AppError 설정 오류 시 500 (본체의 ValueError 와 같은 자리에서 시끄럽게 실패)
 */
function build_set_cookie_header(string $name, string $value, array $opt): string
{
    $expires  = isset($opt['expires'])  ? (int) $opt['expires']     : 0;
    $path     = isset($opt['path'])     ? (string) $opt['path']     : '';
    $domain   = isset($opt['domain'])   ? (string) $opt['domain']   : '';
    $samesite = isset($opt['samesite']) ? (string) $opt['samesite'] : '';
    $secure   = !empty($opt['secure']);
    $httponly = !empty($opt['httponly']);

    $bad = cookie_attr_offender($opt);
    if ($bad !== null) {
        error_log('[ulogin] build_set_cookie_header: invalid "' . $bad . '" cookie option rejected');
        throw new AppError('SERVER_ERROR', 500);
    }

    // 이름은 무인코딩 — 본체와 동일하다 (V11-L-1). 안전성은 send_cookie() 의 검증이 담보한다.
    $out = $name;

    if ($value === '') {
        $out .= '=deleted; expires=' . gmdate('D, d-M-Y H:i:s', 1) . ' GMT; Max-Age=0';
    } else {
        $out .= '=' . rawurlencode($value);
        if ($expires > 0) {
            $age = $expires - time();
            if ($age < 0) {
                $age = 0;
            }
            $out .= '; expires=' . gmdate('D, d-M-Y H:i:s', $expires) . ' GMT';
            $out .= '; Max-Age=' . $age;
        }
    }
    if ($path !== '') {
        $out .= '; path=' . $path;
    }
    if ($domain !== '') {
        $out .= '; domain=' . $domain;
    }
    if ($secure) {
        $out .= '; secure';
    }
    if ($httponly) {
        $out .= '; HttpOnly';
    }
    if ($samesite !== '') {
        $out .= '; SameSite=' . $samesite;
    }
    return $out;
}

/**
 * 쿠키 전송 — PHP 7.2 / 7.3+ 양방향 호환 단일 진입점. 리더 판정 D-59.
 *
 *   PHP >= 7.3 → setcookie($name, $value, $options)   (본체 경로 유지)
 *   PHP <  7.3 → header('Set-Cookie: …', false) 직접 조립
 *
 * `$path . '; SameSite=Lax'` 같은 path 해킹은 쓰지 않는다 — 일부 SAPI·프록시가
 * path 를 정규화하면서 조용히 깨진다.
 *
 * `header()` 의 2번째 인자는 **반드시 false** 다. true 면 앞서 보낸 Set-Cookie 를
 * 지워버린다 — 로그아웃은 세션 쿠키와 CSRF 쿠키를 **함께** 보낸다.
 *
 * headers_sent() 면 조용히 false 를 반환한다 (기존 set_session_cookie() 계약 유지).
 *
 * ---------------------------------------------------------------------
 * **두 분기 동등성은 관측 사실이 아니라 불변식이어야 한다** — 리더 판정 D-65.
 *
 * 목표는 "같은 입력에서 같은 결과, **잘못된 입력에서 같은 실패**" 다. 그래서
 * 하한선 채움(V11-L-3)과 옵션 검증(V11-M-1)을 **버전 분기보다 앞**에서 한다.
 * 검증을 7.2 분기 안에만 두면 7.3+ 는 본체가(8.0+ `ValueError` / 7.3~7.4 경고+false)
 * 다른 방식으로 실패해 두 분기가 다시 갈라진다. 분기 앞에 두면 7.2·7.3·7.4·8.x가
 * **같은 지점에서 같은 예외로** 실패한다.
 *
 * 분기 뒤에 새 검증을 추가하지 마라. 추가하려면 여기, 분기 앞에 넣어라.
 * ---------------------------------------------------------------------
 *
 * @return bool 전송했으면 true. 이름 거부·headers_sent·조립 실패면 false
 * @throws AppError 설정 오류 시 500 — (a) 미지 옵션 키, (b) path·domain·samesite 에
 *                  쿠키 구분자, (c) `secure` 쿠키를 평문 HTTP 요청에서 내보내려 할 때
 *                  (D-75. 브라우저가 버리므로 "200 인데 세션 없음" 이 된다)
 */
function send_cookie(string $name, string $value, array $opt): bool
{
    if (headers_sent()) {
        return false;
    }
    // PHP 본체와 동일한 이름 검증. 여기서 막으면 어느 분기에서도 헤더 인젝션이
    // 불가능하고, 두 분기의 출력이 갈라질 여지도 없어진다.
    // 이름은 build_set_cookie_header() 에서 **무인코딩으로** 나간다(본체와 동일, V11-L-1)
    // — 즉 이 검증이 이름의 유일한 방어선이다. 절대 지우지 마라.
    //
    // 목록에 NUL 을 더한 것은 본체 검증에는 없지만 의도한 것이다. 본체 분기에서는
    // 이름의 NUL 을 SAPI 층(`sapi_header_op`)이 거부해 `setcookie()` 가 false 를
    // 반환한다. 7.2 분기는 그 층의 반환값을 볼 수 없으므로(header() 는 void)
    // 여기서 미리 막지 않으면 "헤더는 안 나갔는데 true" 가 되어 두 분기가 갈라진다.
    if ($name === '' || strpbrk($name, "=,; \t\r\n\013\014\0") !== false) {
        error_log('[ulogin] send_cookie: invalid cookie name rejected');
        return false;
    }
    // 본체는 알 수 없는 옵션 키를 거부한다(8.0+ ValueError / 7.3~7.4 경고+false).
    // 7.2 분기가 조용히 무시하면, 오타 난 키(`samesit`)가 **7.2 에서만** 속성 누락으로
    // 이어진다. 분기 앞에서 함께 막아 "잘못된 입력에서 같은 실패" 를 지킨다 (D-65).
    $unknown = array_diff(array_keys($opt), ['expires', 'path', 'domain', 'secure', 'httponly', 'samesite']);
    if ($unknown !== []) {
        error_log('[ulogin] send_cookie: unknown cookie option(s) rejected: ' . implode(',', $unknown));
        throw new AppError('SERVER_ERROR', 500);
    }

    // --- 속성 하한선 (감사 V11-L-3 / D-65) --------------------------------
    // 이 래퍼는 **세션 쿠키와 CSRF 쿠키만** 운반한다. 옵션 배열이 불완전하게
    // 넘어오면 `Set-Cookie: ULOGIN_SID=v` (Secure·HttpOnly·SameSite 전무) 가
    // 조용히 나간다 — 발견이 매우 어려운 종류다. 누락은 안전한 쪽으로 채운다.
    // 채움은 반드시 여기(분기 앞)서만 한다. build_set_cookie_header() 에 넣으면
    // 7.3+ 분기(본체)와 출력이 갈라진다.
    if (!array_key_exists('httponly', $opt)) {
        $opt['httponly'] = true;
    }
    if (!array_key_exists('secure', $opt)) {
        $opt['secure'] = defined('COOKIE_SECURE') ? (bool) COOKIE_SECURE : true;
    }
    if (!array_key_exists('path', $opt)) {
        // 키가 있으면 그 값을 존중한다(빈 문자열 포함). 빈 path 는 본체도 속성을
        // 생략하고, 두 분기가 동일하게 동작한다. COOKIE_PATH 자체가 ''인 배포를
        // 여기서 덮어쓰면 관리자의 명시적 설정을 조용히 바꾸는 셈이 된다.
        $opt['path'] = defined('COOKIE_PATH') ? (string) COOKIE_PATH : '/';
    }
    // samesite 는 값까지 하한선을 적용한다 — 키가 있어도 화이트리스트 밖이면
    // 브라우저가 해석하지 못하거나(오타) 속성 주입이 된다 (V11-L-4).
    $ss = array_key_exists('samesite', $opt) ? (string) $opt['samesite'] : '';
    if (!in_array($ss, ['Lax', 'Strict', 'None'], true)) {
        if ($ss !== '') {
            error_log('[ulogin] send_cookie: invalid samesite "' . $ss . '" - flooring');
        }
        $ss = cookie_samesite_safe(defined('COOKIE_SAMESITE') ? (string) COOKIE_SAMESITE : 'Lax');
    }
    $opt['samesite'] = $ss;
    if ($ss === 'None') {
        $opt['secure'] = true;   // 브라우저 요구사항 (V11-L-4)
    }

    // --- 옵션 검증 (감사 V11-M-1 / D-65) ---------------------------------
    // 본체가 거부하는 값을 7.2 분기만 그대로 내보내면 안 된다. 두 분기가
    // **같은 지점에서 같은 이유로** 실패하도록 분기 앞에서 던진다.
    $bad = cookie_attr_offender($opt);
    if ($bad !== null) {
        error_log('[ulogin] send_cookie: invalid "' . $bad . '" cookie option rejected (config error)');
        throw new AppError('SERVER_ERROR', 500);
    }

    // --- Secure ↔ 평문 HTTP 모순 (리더 판정 D-75 / 감사 M-3 잔존분) --------
    // `Secure` 가 붙은 쿠키는 **브라우저가 평문 연결에서 저장하지 않는다.** 서버는
    // 200 과 정상 `Set-Cookie` 를 내보내고, 브라우저는 그것을 버린다. 그러면
    // "로그인 API 는 200 인데 다음 요청에 세션이 없다" 가 되고 **서버 로그에 아무것도
    // 남지 않는다** — 배포자는 "로그인을 눌렀는데 다시 로그인 화면"만 본다.
    // 성립 조건은 `FORCE_HTTPS=false` + `COOKIE_SECURE=true` + 평문 요청이다
    // (`FORCE_HTTPS=true` 면 bootstrap.php 의 301 이 먼저 걷어낸다. TLS 를 종료하는
    //  정상 프록시 배포는 request_is_https() 가 true 이므로 무관하다).
    //
    // **자동으로 `secure` 를 떼지 않는다.** 그러면 세션 쿠키가 평문으로 흐른다 —
    // 이 시스템이 막으려는 것 자체다. 설정을 조용히 약화시키는 대신 멈추고 알린다.
    // D-53(BASE_URL 미설정) · D-67(TRUST_PROXY 짝 누락)과 같은 fail-closed 계열이다.
    //
    // 판정은 **여기(쿠키 발급 시점)에서만** 한다. bootstrap.php 에서 전역으로 막으면
    // 쿠키를 쓰지 않는 `Authorization: Bearer` 전용 요청까지 죽는다 — 그 요청들은
    // 이 모순의 영향을 전혀 받지 않는다.
    //
    // HTTPS 판정은 **request_is_https()(bootstrap.php)를 재사용한다.** 여기서 새
    // 판정식을 만들면 D-67 에서 통일한 기준(프록시 헤더를 언제 믿는가)이 다시 갈라진다.
    // 그 함수는 bootstrap.php 가 이 파일을 require 한 **뒤**에 정의되지만, 정의는
    // bootstrap 실행 중에 끝나고 send_cookie() 는 그보다 늦은 요청 처리 중에만
    // 호출되므로 항상 존재한다(core/*.php 는 ULOGIN_BOOTSTRAPPED 가드로 bootstrap
    // 없이는 실행되지 않는다 — D-34). 여기서 function_exists() 로 조용히 건너뛰면
    // 이 검사가 있는지 없는지 아무도 모르는 상태가 되므로 그렇게 하지 않는다.
    //
    // 조건은 상수 `COOKIE_SECURE` 가 아니라 **지금 내보내려는 쿠키의 실제 secure 값**
    // 으로 본다. 위 하한선이 (a) `COOKIE_SECURE` 미정의 시 true, (b) `SameSite=None`
    // 시 true 를 강제하므로, 그 두 경로도 똑같이 "브라우저가 버리는 쿠키" 가 된다.
    // 정상 경로에서는 이 값이 곧 `COOKIE_SECURE` 다.
    if (!empty($opt['secure']) && !request_is_https()) {
        error_log('[ulogin] send_cookie: COOKIE_SECURE is on but the request is not HTTPS. '
            . 'the browser would discard this cookie and login would appear to succeed '
            . 'without a session. serve the site over HTTPS (and set FORCE_HTTPS=true), '
            . 'or set COOKIE_SECURE=false for plain HTTP, in config.php.');
        throw new AppError('SERVER_ERROR', 500);
    }

    if (PHP_VERSION_ID >= 70300) {
        // php72-scan: ignore ARG_ARRAY_FORM_MANUAL — PHP_VERSION_ID 로 보호된 의도적 7.3+ 경로
        return setcookie($name, $value, $opt);
    }

    // 7.2 분기. header() 는 CR/LF 를 포함한 헤더 라인을 거부하고(경고 + 미전송)
    // 반환값이 없다. 그때 true 를 돌려주면 **7.2 분기만 실패를 숨긴다**
    // (본체 분기는 false 를 반환한다 — 감사 V11-L-2).
    // 위 옵션 검증으로 사실상 도달 불가지만 두 방어를 함께 둔다.
    // NUL 도 함께 본다 — SAPI 가 CR/LF 와 같이 거부하는 문자이고, 본체 분기는
    // 그 실패를 false 로 돌려준다.
    $line = build_set_cookie_header($name, $value, $opt);
    if (strpbrk($line, "\r\n\0") !== false) {
        error_log('[ulogin] send_cookie: assembled header contains CR/LF/NUL. not sent.');
        return false;
    }
    header('Set-Cookie: ' . $line, false);
    return true;
}

/**
 * CSRF 토큰 발급. 계약 §4-6.
 * 쿠키 세션이 있으면 세션 행의 csrf_token_hash 와 묶는다.
 * 비로그인(로그인 폼용) 이면 이중 제출 쿠키로 대신한다.
 */
function csrf_token(): string
{
    $t = gen_token(32);

    $ck = (string) ($_COOKIE[COOKIE_NAME] ?? '');  // access_token 원문 (D-29)
    if (is_token_format($ck)) {
        try {
            // 쿠키에는 access_token 원문이 들어간다 (D-29). session_id 로 조회하면 0 건이 되어
            // CSRF 가 세션 바인딩에서 이중 제출 쿠키로 조용히 격하된다.
            db_exec('UPDATE ' . T_SESSIONS . ' SET csrf_token_hash = ? WHERE access_token_hash = ? AND revoked_at IS NULL',
                [token_hash($t), token_hash($ck)]);
        } catch (Throwable $e) {
            error_log('[ulogin] csrf bind failed: ' . $e->getMessage());
        }
    }
    if (!headers_sent()) {
        // PHP 7.2 호환 — 옵션 배열을 setcookie() 에 직접 넘기지 않는다 (D-59)
        send_cookie(COOKIE_CSRF_NAME, $t, cookie_options((int) SESSION_TTL));
    }
    $_COOKIE[COOKIE_CSRF_NAME] = $t;
    return $t;
}

/**
 * 상태 변경 요청의 CSRF 검증.
 * Bearer 인증은 면제한다 — 브라우저가 자동으로 실어 보내지 않으므로 CSRF 의 전제가 없다.
 * 비교는 hash_equals 로 한다 (=== 는 타이밍 공격에 노출된다).
 */
function require_csrf(): void
{
    if (bearer_token() !== null) {
        return;
    }
    $hdr = req_header('X-CSRF-Token');
    if ($hdr === null || $hdr === '') {
        throw new AppError('CSRF_FAILED', 403);
    }

    $ck = (string) ($_COOKIE[COOKIE_NAME] ?? '');  // access_token 원문 (D-29)
    if (is_token_format($ck)) {
        // D-29: 쿠키 = access_token 원문. 해시로 조회한다.
        $row = db_one('SELECT csrf_token_hash FROM ' . T_SESSIONS . ' WHERE access_token_hash = ? AND revoked_at IS NULL', [token_hash($ck)]);
        if ($row !== null && !empty($row['csrf_token_hash'])) {
            if (hash_equals((string) $row['csrf_token_hash'], token_hash($hdr))) {
                return;
            }
            throw new AppError('CSRF_FAILED', 403);
        }
    }

    // 세션에 아직 토큰이 묶이지 않았거나 비로그인 — 이중 제출 쿠키로 대조
    $cookie = (string) ($_COOKIE[COOKIE_CSRF_NAME] ?? '');
    if ($cookie !== '' && hash_equals($cookie, $hdr)) {
        return;
    }
    throw new AppError('CSRF_FAILED', 403);
}
