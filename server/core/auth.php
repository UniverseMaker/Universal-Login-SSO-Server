<?php
declare(strict_types=1);

/**
 * core/auth.php — 세션·토큰 발급/검증/회수, SSO 보조
 * 담당 계약: §4-2~4-5, §4-9, 스펙 §5(세션·토큰 정책)
 *
 * access_token / refresh_token 은 64자 hex 난수다. JWT 가 아니다.
 * DB 에는 SHA-256 해시만 저장한다. session_id 는 토큰과 별개의 공개 식별자다.
 *
 * **인증자는 access_token 원문 하나뿐이다** (리더 판정 D-29 / 감사 C-1).
 * Bearer 헤더로 오든 ULOGIN_SID 쿠키로 오든 조회는 `access_token_hash` 로만 한다.
 * session_id 는 어떤 경로에서도 인증자가 아니다 — 응답에 실려 나가는 값이기 때문이다.
 */

// 직접 실행 차단 — 리더 판정 D-34. bootstrap.php 를 거치지 않은 요청은 존재하지 않는 파일이다.
if (!defined('ULOGIN_BOOTSTRAPPED')) {
    // X-Powered-By 제거 — D-154(2). 이 경로는 bootstrap.php 를 거치지 않는다.
    header_remove('X-Powered-By');
    http_response_code(404);
    exit;
}

// ---------------------------------------------------------------------
// 서비스 · redirect_uri
// ---------------------------------------------------------------------

/** 활성 서비스 조회. 없거나 비활성이면 null. */
function service_load(?string $serviceId): ?array
{
    if ($serviceId === null || $serviceId === '') {
        return null;
    }
    return db_one('SELECT * FROM ' . T_SERVICES . ' WHERE service_id = ? AND status = ?', [$serviceId, 'active']);
}

/** 활성 서비스를 요구한다. 없으면 SSO_INVALID_SERVICE. */
function service_require(?string $serviceId): array
{
    $s = service_load($serviceId);
    if ($s === null) {
        throw new AppError('SSO_INVALID_SERVICE', 400, ['field' => 'service_id']);
    }
    return $s;
}

/**
 * redirect_uri 를 origin+path 로 정규화한다.
 * 쿼리스트링·프래그먼트는 비교에서 제외한다 (스펙 §4-2).
 * 실패하면 빈 문자열 — 빈 문자열끼리는 매칭시키지 않는다.
 */
function normalize_uri(?string $uri): string
{
    if ($uri === null || trim($uri) === '') {
        return '';
    }
    $p = parse_url(trim($uri));
    if ($p === false || empty($p['scheme']) || empty($p['host'])) {
        return '';
    }
    $scheme = strtolower((string) $p['scheme']);
    $host   = strtolower((string) $p['host']);
    $port   = isset($p['port']) ? (int) $p['port'] : null;
    if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
        $port = null;   // 기본 포트는 표기 차이일 뿐이다
    }
    $path = isset($p['path']) ? (string) $p['path'] : '';
    if ($path === '/') {
        $path = '';
    }
    return $scheme . '://' . $host . ($port !== null ? ':' . $port : '') . $path;
}

/**
 * 화이트리스트 대조. origin+path 완전 일치만 허용한다.
 * 접두사 매칭은 금지 — `http://localhost:5173/callback.html.evil.com` 류 우회를 허용한다.
 */
function redirect_uri_allowed(array $service, ?string $uri): bool
{
    $target = normalize_uri($uri);
    if ($target === '') {
        return false;
    }
    foreach (json_col($service['redirect_whitelist'] ?? null) as $w) {
        if (!is_string($w)) {
            continue;
        }
        $n = normalize_uri($w);
        if ($n !== '' && hash_equals($n, $target)) {
            return true;
        }
    }
    return false;
}

// ---------------------------------------------------------------------
// 아이디 · 비밀번호 정책
// ---------------------------------------------------------------------

/** 아이디는 소문자로 정규화해 저장·조회한다. */
function normalize_username(string $u): string
{
    return mb_strtolower(trim($u), 'UTF-8');
}

/** 계약 §4-1: username `^[a-z0-9_]{4,20}$` (소문자 정규화 후) */
function validate_username_format(string $u): void
{
    if (preg_match('/^[a-z0-9_]{4,20}$/', $u) !== 1) {
        v_fail('username', '아이디는 영문 소문자·숫자·밑줄 4~20자여야 합니다.');
    }
}

/**
 * 비밀번호 정책. 스펙 §8-1.
 *   8자 이상(설정값) + 영문/숫자/특수 중 2종 이상 + username 과 동일 문자열 금지
 */
function validate_password(string $pw, ?string $username = null, string $field = 'password'): void
{
    $min = (int) setting_get('password_min_length', PASSWORD_MIN_LENGTH);
    if ($min < 8) {
        $min = 8;
    }
    if (mb_strlen($pw, 'UTF-8') < $min) {
        v_fail($field, '비밀번호는 ' . $min . '자 이상이어야 합니다.');
    }
    if (mb_strlen($pw, 'UTF-8') > 200) {
        v_fail($field, '비밀번호는 200자 이내여야 합니다.');
    }
    // 감사 V11-L-5 / 리더 판정 D-66.
    // password_hash() 는 bcrypt = C 문자열이므로 **첫 NUL 에서 절단**된다.
    // 막지 않으면 "8자 이상 · 2종 이상" 을 통과한 비밀번호가 실제로는 앞 3자 해시로
    // 저장되고 그 3자만으로 로그인이 된다(감사 실측: "Abc\0defgh1" → "Abc" 로 로그인 성공).
    // 즉 서버가 선언한 정책이 **저장된 해시에는 적용되지 않는** 상태가 된다.
    // 또 선행 NUL 은 CVE-2024-3096(`password_verify('', $hash)` 가 true, 7.2 미패치)의 전제다.
    //
    // 현재 유일한 방어는 v_string() 의 trim() 이 PHP 기본 문자셋(" \t\n\r\0\x0B")에
    // NUL 을 포함해 **선행·후행** NUL 을 우연히 지워주는 것이다. 중간 NUL 은 남고,
    // trim() 을 거치지 않는 새 입력 경로가 생기면 즉시 성립한다. 우연에 의존하지 않는다.
    // 이 함수는 register / password_reset / me/password 세 저장 경로 전부를 지난다.
    if (strpos($pw, "\0") !== false) {
        v_fail($field, '비밀번호에 사용할 수 없는 문자가 포함되어 있습니다.');
    }
    $kinds = 0;
    $kinds += preg_match('/[A-Za-z]/', $pw) === 1 ? 1 : 0;
    $kinds += preg_match('/[0-9]/', $pw) === 1 ? 1 : 0;
    $kinds += preg_match('/[^A-Za-z0-9]/', $pw) === 1 ? 1 : 0;
    if ($kinds < 2) {
        v_fail($field, '비밀번호는 영문·숫자·특수문자 중 2종 이상을 포함해야 합니다.');
    }
    if ($username !== null && $username !== '' && mb_strtolower($pw, 'UTF-8') === mb_strtolower($username, 'UTF-8')) {
        v_fail($field, '아이디와 동일한 비밀번호는 사용할 수 없습니다.');
    }
}

/**
 * 계정이 없어도 반드시 한 번 돌리는 더미 해시.
 * 즉시 반환하면 응답 시간 차이로 계정 존재 여부가 새어나간다.
 */
function dummy_password_verify(string $plain): void
{
    static $dummy = null;
    if ($dummy === null) {
        // 고정 해시. 어떤 비밀번호와도 일치하지 않는다.
        $dummy = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
    }
    password_verify($plain, $dummy);
}

// ---------------------------------------------------------------------
// 쿠키
// ---------------------------------------------------------------------

/**
 * ULOGIN_SID 쿠키를 심는다.
 *
 * **쿠키에 들어가는 값은 access_token 원문이다. session_id 가 아니다.**
 * (리더 판정 D-29 / 감사 C-1) session_id 는 관리자 API 응답과 기기 목록에
 * 실려 나가므로, 그 값을 인증자로 쓰면 목록을 볼 수 있는 권한이 곧
 * 계정 탈취 권한이 된다. access_token 은 DB 에 SHA-256 만 있고 발급
 * 시점의 응답에만 원문이 나간다.
 *
 * @param string $accessToken create_session() 이 발급한 access_token 원문 (64자 hex)
 * @param int    $ttl         쿠키 수명(초). 0 이면 브라우저 세션 쿠키
 */
function set_session_cookie(string $accessToken, int $ttl): void
{
    if (headers_sent()) {
        return;
    }
    // PHP 7.2 호환 — 옵션 배열을 setcookie() 에 직접 넘기면 7.2 에서
    // SameSite·HttpOnly·Secure·Path 가 통째로 사라진다 (D-59). send_cookie() 가 분기한다.
    send_cookie(COOKIE_NAME, $accessToken, cookie_options($ttl));
    $_COOKIE[COOKIE_NAME] = $accessToken;
}

/**
 * refresh 회전 후 쿠키를 새 access_token 으로 다시 심는다.
 * rotate_refresh() 가 쿠키 세션을 스스로 감지해 호출하므로 보통은 필요 없지만,
 * api/ 쪽에서 명시적으로 갱신해야 할 때(예: 쿠키 TTL 을 바꿀 때) 쓰는 공개 진입점이다.
 * set_session_cookie() 의 의미를 이름으로 드러내기 위한 얇은 래퍼다.
 */
function refresh_session_cookie(string $accessToken, int $ttl): void
{
    set_session_cookie($accessToken, $ttl);
}

/**
 * 현재 요청의 ULOGIN_SID 쿠키가 이 세션 행을 가리키는가.
 * 쿠키에는 access_token 원문이 있고 DB 에는 SHA-256 만 있으므로 해시로 비교한다.
 * 비교는 hash_equals — === 는 타이밍 차이를 남긴다.
 */
function cookie_binds_session(?array $sessionRow): bool
{
    if ($sessionRow === null || empty($sessionRow['access_token_hash'])) {
        return false;
    }
    $ck = (string) ($_COOKIE[COOKIE_NAME] ?? '');
    if (!is_token_format($ck)) {
        return false;
    }
    return hash_equals((string) $sessionRow['access_token_hash'], token_hash($ck));
}

function clear_session_cookie(): void
{
    if (headers_sent()) {
        return;
    }
    $opt = cookie_options(0);
    $opt['expires'] = time() - 3600;
    // PHP 7.2 호환 (D-59). 값이 빈 문자열이므로 두 분기 모두 PHP 본체와 같은
    // `name=deleted; expires=Thu, 01-Jan-1970 00:00:01 GMT; Max-Age=0` 형태를 낸다.
    // 하이픈이 맞다 (리더 판정 D-68) — PHP 본체는 RFC 7231 의 공백 형식(`D, d M Y`)이
    // 아니라 **구 Netscape 형식(`D, d-M-Y`)** 을 쓴다. 이 주석을 공백 형식으로
    // "고치고" 코드를 맞추면 7.2 배포에서만 다른 만료 문자열이 나가는 결함이
    // 되살아난다(12a 라운드가 두 분기 헤더 대조로 실측해 잡은 결함이 그것이다).
    // 형식의 정본은 core/request.php 의 build_set_cookie_header() 독스트링이다.
    // header(..., false) 로 보내므로 두 쿠키가 서로를 덮어쓰지 않는다.
    send_cookie(COOKIE_NAME, '', $opt);
    send_cookie(COOKIE_CSRF_NAME, '', $opt);
    unset($_COOKIE[COOKIE_NAME], $_COOKIE[COOKIE_CSRF_NAME]);
}

// ---------------------------------------------------------------------
// 세션 생성
// ---------------------------------------------------------------------

/**
 * 세션 1건 + 토큰 발급.
 *
 * v1.0.0 은 로그인 1회당 세션 행을 하나만 만든다.
 * auth_type='token' 으로 저장하고, 필요하면 **같은 access_token 원문**을 쿠키에도 심는다.
 * (두 행으로 나누면 Bearer 로그아웃이 쿠키 세션을 살려두는 구멍이 생긴다)
 *
 * 쿠키에 session_id 를 넣지 않는다 — 리더 판정 D-29. set_session_cookie() 주석 참고.
 *
 * $opt:
 *   device_label  string|null  클라이언트가 준 기기명
 *   with_refresh  bool         refresh 토큰 발급 여부 (기본 true)
 *   set_cookie    bool         ULOGIN_SID 쿠키 동시 발급 (기본 false)
 *   cookie_ttl    int          쿠키 수명. 미지정/null 이면 access TTL,
 *                              0 이면 브라우저 세션 쿠키
 *
 * @return array{row:array,session_id:string,access_token:string,refresh_token:?string,expires_in:int,refresh_expires_in:?int}
 */
function create_session(array $user, ?array $service, string $authType, array $opt = []): array
{
    $withRefresh = array_key_exists('with_refresh', $opt) ? (bool) $opt['with_refresh'] : true;

    $sid     = gen_token(32);
    $access  = gen_token(32);
    $refresh = $withRefresh ? gen_token(32) : null;

    $ttl  = (int) ($service['session_ttl'] ?? setting_get('session_ttl_default', SESSION_TTL));
    $rttl = (int) ($service['refresh_ttl'] ?? setting_get('refresh_ttl_default', REFRESH_TTL));
    if ($ttl <= 0) {
        $ttl = (int) SESSION_TTL;
    }
    if ($rttl <= 0) {
        $rttl = (int) REFRESH_TTL;
    }

    // service_id 는 서비스 행에서 온다. 행이 없을 때만 $opt['service_id_fallback'] 을 쓴다 —
    // 리더 판정 D-162. 지금 이것을 쓰는 곳은 rotate_refresh() 하나이고, 이유는 그 함수의
    // 주석에 있다. **넓히는 용도로 쓰지 마라**: 서비스가 존재하지 않는데 세션에
    // service_id 를 심는 경로를 만들면 payload 판정이 아니라 세션의 뜻이 흐려진다.
    $now = now_utc();
    $row = [
        'session_id'         => $sid,
        'user_id'            => (int) $user['id'],
        'service_id'         => isset($service['service_id'])
            ? (string) $service['service_id']
            : (isset($opt['service_id_fallback']) && $opt['service_id_fallback'] !== ''
                ? (string) $opt['service_id_fallback'] : null),
        'auth_type'          => $authType,
        'access_token_hash'  => token_hash($access),
        'refresh_token_hash' => $refresh !== null ? token_hash($refresh) : null,
        'csrf_token_hash'    => null,
        'issued_at'          => $now,
        'expires_at'         => at_utc($ttl),
        'refresh_expires_at' => $refresh !== null ? at_utc($rttl) : null,
        'last_seen_at'       => $now,
        'ip'                 => client_ip(),
        'user_agent'         => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        'device_label'       => $opt['device_label'] ?? device_label_from_ua(),
    ];
    $row['id'] = db_insert(T_SESSIONS, $row);

    if (!empty($opt['set_cookie'])) {
        // 쿠키에 심는 값은 access_token 원문이다 (D-29). session_id 를 심으면
        // 관리자 API 로 세션 목록을 볼 수 있는 권한이 곧 계정 탈취 권한이 된다.
        // cookie_ttl 이 키로 존재하되 null 이면(로그인 remember=false) 0 → 세션 쿠키.
        $cttl = array_key_exists('cookie_ttl', $opt) ? (int) $opt['cookie_ttl'] : $ttl;
        set_session_cookie($access, $cttl);
    }

    return [
        'row'                => $row,
        'session_id'         => $sid,
        'access_token'       => $access,
        'refresh_token'      => $refresh,
        'expires_in'         => $ttl,
        'refresh_expires_in' => $refresh !== null ? $rttl : null,
    ];
}

// ---------------------------------------------------------------------
// 인증 컨텍스트
// ---------------------------------------------------------------------

/**
 * Authorization: Bearer {token}. 일부 공용 호스팅(CGI 모드)은 이 헤더를 PHP 에 전달하지
 * 않는다. .htaccess 의 RewriteRule 로 보정하고, 그래도 안 되면 X-Auth-Token 을 쓴다.
 */
function bearer_token(): ?string
{
    $h = req_header('Authorization');
    if ($h !== null && preg_match('/^Bearer\s+(\S+)$/i', $h, $m)) {
        return $m[1];
    }
    $alt = req_header('X-Auth-Token');
    if ($alt !== null && trim($alt) !== '') {
        return trim($alt);
    }
    return null;
}

/**
 * 계정 상태 판정. 스펙 §2-5. 로그인·세션 검증 양쪽에서 같은 규칙을 쓴다.
 * $strict=false 면 (선택적 인증) 예외 대신 false 를 돌려준다.
 */
function assert_user_active(array $user, bool $strict = true): bool
{
    $st = (string) ($user['status'] ?? '');
    if ($st === 'active') {
        return true;
    }
    if (!$strict) {
        return false;
    }
    if ($st === 'suspended') {
        throw new AppError('AUTH_ACCOUNT_SUSPENDED', 403);
    }
    if ($st === 'withdrawn') {
        throw new AppError('AUTH_ACCOUNT_WITHDRAWN', 403);
    }
    if ($st === 'dormant') {
        throw new AppError('AUTH_ACCOUNT_DORMANT', 403);
    }
    if ($st === 'pending') {
        throw new AppError('AUTH_ACCOUNT_PENDING', 403);
    }
    throw new AppError('AUTH_SESSION_EXPIRED', 401);
}

/**
 * 현재 요청의 인증 컨텍스트.
 * 인증 우선순위: Authorization: Bearer 헤더 → 쿠키 ULOGIN_SID.
 * 헤더가 있으면 쿠키는 보지 않는다 (세션 혼동 방지).
 *
 * **인증자는 두 경로 모두 access_token 원문이다** (리더 판정 D-29 / 감사 C-1).
 * 값을 어디서 읽었는지만 다르고 조회는 한 문장 — `access_token_hash` 다.
 * 경로마다 SELECT 를 따로 쓰면 한쪽만 고쳐지는 사고가 난다.
 * 이 함수에서 `WHERE session_id = ?` 로 세션을 찾는 코드가 다시 생기면
 * 그 즉시 C-1 이 되살아난다.
 *
 * 세션 유효성 4조건 (스펙 §5-4)
 *   revoked_at IS NULL / expires_at > now / users.status='active' /
 *   sessions.issued_at >= users.password_changed_at
 *
 * @return array{user:array,session:array,user_service:?array,grades:array,auth_via:string}|null
 */
function require_auth(bool $required = true): ?array
{
    $row = null;

    // 자격증명 수집 — 우선순위만 여기서 정하고 검증은 아래 한 곳에서 한다.
    $token = bearer_token();
    $via   = 'bearer';
    if ($token === null && !empty($_COOKIE[COOKIE_NAME])) {
        $token = (string) $_COOKIE[COOKIE_NAME];
        $via   = 'cookie';
    }

    if ($token !== null) {
        if (!is_token_format($token)) {
            if ($required) {
                throw new AppError('AUTH_REQUIRED', 401);
            }
            return null;
        }
        // 유일한 조회 조건. 64자 hex 라도 access_token 이 아니면(예: session_id) 적중하지 않는다.
        $row = db_one('SELECT * FROM ' . T_SESSIONS . ' WHERE access_token_hash = ?', [token_hash($token)]);
    }

    if ($row === null) {
        if ($required) {
            throw new AppError('AUTH_REQUIRED', 401);
        }
        return null;
    }

    // 1) 회수 / 2) 만료
    if ($row['revoked_at'] !== null || (int) ts($row['expires_at']) <= time()) {
        if ($required) {
            throw new AppError('AUTH_SESSION_EXPIRED', 401);
        }
        return null;
    }

    $user = db_one('SELECT * FROM ' . T_USERS . ' WHERE id = ?', [$row['user_id']]);
    if ($user === null) {
        if ($required) {
            throw new AppError('AUTH_SESSION_EXPIRED', 401);
        }
        return null;
    }

    // 3) 계정 상태
    if (!assert_user_active($user, $required)) {
        return null;
    }

    // 4) 비밀번호 변경 이후 발급된 세션만 유효 — 회수 UPDATE 가 누락돼도 잡히는 이중 안전장치
    $pwChanged = ts($user['password_changed_at'] ?? null);
    if ($pwChanged !== null && (int) ts($row['issued_at']) < $pwChanged) {
        revoke_session((int) $row['id'], 'password_changed');
        if ($required) {
            throw new AppError('AUTH_SESSION_EXPIRED', 401);
        }
        return null;
    }

    $now = now_utc();
    db_exec('UPDATE ' . T_SESSIONS . ' SET last_seen_at = ? WHERE id = ?', [$now, $row['id']]);
    $row['last_seen_at'] = $now;

    $us = null;
    if (!empty($row['service_id'])) {
        $us = db_one('SELECT * FROM ' . T_USER_SERVICES . ' WHERE user_id = ? AND service_id = ?',
            [$user['id'], $row['service_id']]);
        if ($us !== null) {
            db_exec('UPDATE ' . T_USER_SERVICES . ' SET last_used_at = ? WHERE id = ?', [$now, $us['id']]);
        }
    }

    return [
        'user'         => $user,
        'session'      => $row,
        'user_service' => $us,
        'grades'       => effective_grades($user, $us),
        'auth_via'     => $via,
    ];
}

// ---------------------------------------------------------------------
// 회수
// ---------------------------------------------------------------------

/** 세션 1건 회수. 이미 회수된 건은 건드리지 않는다(사유가 덮어써지면 감사 추적이 흐려진다). */
function revoke_session(int $sessionId, string $reason): int
{
    if ($sessionId <= 0) {
        return 0;
    }
    return db_exec(
        'UPDATE ' . T_SESSIONS . ' SET revoked_at = ?, revoke_reason = ? WHERE id = ? AND revoked_at IS NULL',
        [now_utc(), substr($reason, 0, 50), $sessionId]
    );
}

/** 사용자 전 세션 회수. $exceptSessionId 를 주면 그 세션만 남긴다. */
function revoke_user_sessions(int $userId, string $reason, ?int $exceptSessionId = null): int
{
    if ($userId <= 0) {
        return 0;
    }
    $sql    = 'UPDATE ' . T_SESSIONS . ' SET revoked_at = ?, revoke_reason = ? WHERE user_id = ? AND revoked_at IS NULL';
    $params = [now_utc(), substr($reason, 0, 50), $userId];
    if ($exceptSessionId !== null) {
        $sql .= ' AND id <> ?';
        $params[] = $exceptSessionId;
    }
    return db_exec($sql, $params);
}

// ---------------------------------------------------------------------
// refresh 회전 — 계약 §4-4 / 스펙 §5-2
// ---------------------------------------------------------------------

/**
 * refresh 토큰을 새 세션·새 토큰 쌍으로 회전한다.
 * 사용된 refresh 는 즉시 폐기(revoke_reason='refresh_rotated')되고 session_id 도 새 값이 된다.
 *
 * 이미 회수된 refresh 가 다시 오면 토큰 탈취 신호로 보고 해당 사용자의 전 세션을 회수한다.
 *
 * **쿠키 재설정** (리더 판정 D-29): 회전하면 이전 access_token 이 폐기되므로,
 * 쿠키에 그 값을 들고 있던 세션은 갱신 직후 죽는다. 그래서 이 함수가
 * 요청의 ULOGIN_SID 쿠키가 회전 대상 세션을 가리키는지 직접 확인하고,
 * 그렇다면 새 access_token 으로 쿠키를 다시 심는다.
 * api/auth/refresh.php 는 아무것도 하지 않아도 된다 — 호출부가 잊어도 안전하게
 * 만들어야 하는 종류의 처리이기 때문이다.
 * 쿠키를 반드시 심어야 하는 경우에는 `$opt['set_cookie'] = true` 로 강제할 수 있고,
 * `$opt['cookie_ttl']` 로 수명을 지정할 수 있다(remember-me 유지).
 *
 * @return array{user:array,user_service:?array,service:?array,grades:array,session:array,
 *               access_token:string,refresh_token:?string,expires_in:int,refresh_expires_in:?int}
 */
function rotate_refresh(string $refreshToken, array $opt = []): array
{
    if (!is_token_format($refreshToken)) {
        throw new AppError('AUTH_SESSION_EXPIRED', 401);
    }
    $old = db_one('SELECT * FROM ' . T_SESSIONS . ' WHERE refresh_token_hash = ?', [token_hash($refreshToken)]);
    if ($old === null) {
        throw new AppError('AUTH_SESSION_EXPIRED', 401);
    }

    // 회전된(이미 회수된) 토큰의 재사용 = 탈취 신호. 전면 회수한다.
    //
    // 리더 판정 D-164 (보안 조사 A-6) — **관리자가 볼 곳을 만든다.**
    // 이것은 이 시스템의 유일한 **능동** 침해 탐지다(나머지는 전부 시도 기록이다).
    // 그런데 v1.4.0 후속까지 남는 것이 error_log 한 줄뿐이었다. 같은 라운드가
    // 레이트리밋 429 라는 수동 신호는 콘솔에 올렸으니(D-153) 비대칭이 뒤집힌 채였다 —
    // 콘솔은 "레이트리밋에 걸렸다"는 보여 주고 "토큰이 탈취됐다"는 안 보여줬다.
    // error_log 는 남긴다. 공용 호스팅에서 DB 가 죽어 있을 때 남는 유일한 흔적이다.
    //
    // ── 로그를 회수 **사유별로** 가른다 — 보안 재검증 A-6 오탐 ─────────────
    // 이 분기는 `revoked_at` 만 보고 사유를 가리지 않는다. 그런데 회수 사유는
    // 저장소 전수로 7가지(`refresh_rotated` `refresh_reuse` `logout` `logout_all`
    // `password_changed` `admin` `withdraw`)이고 **진짜 재사용 신호는 앞의 둘뿐**이다.
    // 뒤의 다섯은 시스템이 스스로 회수한 것이고, 그 뒤에 옛 토큰이 오는 것은
    // 정상 클라이언트의 갱신 타이머다. 실측으로 네 경로가 확인됐다 —
    // 로그아웃 · 마이페이지 세션정리 · 비밀번호 변경 · 관리자 회수. 넷 다
    // `fail_refresh_reuse` 로 기록됐고 `GET /api/me/login-logs` 로 **사용자 본인에게도**
    // 보인다. 정상 사용 빈도가 침해 빈도보다 높으므로, 그대로 두면 이 시스템의
    // 유일한 능동 침해 탐지가 **대부분 오탐**이 된다.
    //
    // **회수 동작은 한 줄도 바꾸지 않았다.** 사유를 가려 회수 범위를 좁히는 것은
    // 정상 경로의 동작 변경이라 별도 회귀 확인이 필요하다 — v1.5.0.
    // 지금 가르는 것은 **기록되는 이름**뿐이다. `fail_refresh_revoked` 는
    // 「회수된 토큰이 제시됐다」는 사실 그대로이고, 전 세션을 회수해 놓고
    // 로그에 아무것도 남기지 않는 상태(그게 더 나쁘다)도 만들지 않는다.
    if ($old['revoked_at'] !== null) {
        $priorReason = (string) ($old['revoke_reason'] === null ? '' : $old['revoke_reason']);
        $isReuse     = ($priorReason === 'refresh_rotated' || $priorReason === 'refresh_reuse');
        revoke_user_sessions((int) $old['user_id'], 'refresh_reuse');
        error_log('[ulogin] revoked refresh token presented. user_id=' . (int) $old['user_id']
            . ' prior_reason=' . ($priorReason === '' ? '(none)' : $priorReason)
            . ' reuse=' . ($isReuse ? '1' : '0'));
        // 회수보다 뒤에 둔다 — 기록이 실패해도 회수는 이미 끝나 있어야 한다.
        // (log_login() 은 내부에서 try/catch 하지만 순서 자체를 그렇게 못박는다.)
        $reuseName = (string) db_val('SELECT username FROM ' . T_USERS . ' WHERE id = ?', [(int) $old['user_id']]);
        log_login($isReuse ? 'fail_refresh_reuse' : 'fail_refresh_revoked',
            (int) $old['user_id'], $reuseName,
            !empty($old['service_id']) ? (string) $old['service_id'] : null);
        throw new AppError('AUTH_SESSION_EXPIRED', 401);
    }
    $rexp = ts($old['refresh_expires_at']);
    if ($rexp === null || $rexp <= time()) {
        throw new AppError('AUTH_SESSION_EXPIRED', 401);
    }

    $user = db_one('SELECT * FROM ' . T_USERS . ' WHERE id = ?', [$old['user_id']]);
    if ($user === null) {
        throw new AppError('AUTH_SESSION_EXPIRED', 401);
    }
    assert_user_active($user, true);

    $pwChanged = ts($user['password_changed_at'] ?? null);
    if ($pwChanged !== null && (int) ts($old['issued_at']) < $pwChanged) {
        revoke_session((int) $old['id'], 'password_changed');
        throw new AppError('AUTH_SESSION_EXPIRED', 401);
    }

    // ------------------------------------------------------------------
    // 사용자 단위 회전 상한 — 보안 재검증 V14b-H-1
    //
    // 회전은 매번 새 `session_id` 를 만들고, `secret_access_logs` 의 시간 버킷은
    // 그 값을 UNIQUE 키에 쓴다. 그래서 **회전 경로만 유일하게 접히지 않고** 요청과
    // 1:1 로 행이 늘었다(실측 150회 → 151행). `sessions` 도 같은 비율로 늘어난다.
    // 행은 세션 단위, 세션은 사용자 단위이므로 **사용자 버킷이 증가율을 실제로 묶는
    // 자리**다. IP 버킷(api/auth/refresh.php)은 폭주 억제용이고, NAT·CDN 뒤에서는
    // 같은 IP 에 사용자가 수천 명일 수 있어 그것만으로는 좁게 걸 수 없다.
    //
    // **한도를 넉넉하게 잡았다 — 좁게 거는 것이 이 라운드가 고친 결함의 형태다.**
    // 한 사용자의 정상 회전율은 `기기 수 × (3600 / session_ttl)` 회/시간이다.
    // 기본값(`SESSION_TTL` 3600, 기기 1대)이면 1회/시간이므로 **60회/시간은 60배 여유**고,
    // 기기 10대 + 10분 토큰(60회/시간)까지 그대로 통과한다. 사용자당 하루 1,440행 상한 —
    // 상한 없는 432,000행/일/IP 에서 300배 줄어들고, 보안이 감당 가능하다고 판정한
    // `login_logs` 의 3,456행/일/IP 보다도 작다.
    // **`session_ttl` 을 아주 짧게 설정한 배포는 이 값을 다시 봐야 한다** —
    // 넘으면 클라이언트가 429 를 받고 갱신이 지연된다.
    //
    // **회전 트랜잭션보다 앞에 둔다.** 뒤에 두면 429 를 받은 클라이언트가 이미
    // 폐기된 refresh 토큰을 들고 있게 되어 재로그인 말고는 복구 경로가 없다.
    // 여기서 던지면 옛 토큰이 그대로 살아 있어 잠시 뒤 다시 시도할 수 있다(실측 확인).
    //
    // 재사용 탐지 분기보다 **뒤에** 있는 것도 의도한 것이다 — 레이트리밋이
    // 침해 탐지를 가려서는 안 된다.
    // ------------------------------------------------------------------
    rate_limit('refresh:user:' . (int) $old['user_id'], 60, 3600);

    // ------------------------------------------------------------------
    // 리더 판정 D-162 — **회전이 service_id 를 잃으면 안 된다.**
    //
    // service_load() 는 `status='active'` 필터다. 서비스가 비활성인 동안에는 null 을
    // 돌려주고, 종전 코드는 그 null 을 그대로 create_session() 에 넘겼다. 그래서 새
    // 세션 행의 service_id 가 **NULL 로 굳었다.** 결과는 의도의 정반대였다(보안 실측):
    //
    //   [1] 서비스 disabled → 정상 사용자가 refresh    → 새 세션 service_id = NULL
    //   [2] 운영자가 점검을 끝내고 active 로 되살림
    //       회전하지 않은 **탈취 토큰** → payload 복구 (service_id 가 살아 있으므로)
    //       회전한 **정상 사용자**     → payload 영원히 {} (재로그인 전까지)
    //
    // D-152 가 "payload 만 비우고 세션은 살린다"를 고른 이유가 연동 앱을 깨지 않는
    // 것이었는데, 실제로는 정상 사용자만 영구히 깨지고 공격자가 복구됐다.
    //
    // 그래서 **service_id 는 그대로 두고, 시크릿 차단은 build_payload() 에 맡긴다.**
    // 비활성 동안 payload 가 {} 인 것은 그 함수의 payload_service_active() 가 이미
    // 보장한다(D-152) — 세션 행에 id 를 남긴다고 시크릿이 나가지 않는다. 회전 자체가
    // 정책 판단을 하지 않는 것이 옳다: 서비스를 통째로 끊는 것은 A-13(서비스 단위
    // 일괄 세션 회수, v1.9.0)의 일이고, 그것은 **양쪽 토큰에 똑같이** 적용돼야 한다.
    //
    // $service 가 null 이면 세션 TTL 은 서비스별 값이 아니라 전역 기본값이 된다 —
    // 비활성 서비스의 TTL 을 권위로 쓸 수 없으므로 이것이 맞다. 재활성 뒤의 회전부터
    // 다시 서비스 값을 쓴다.
    // ------------------------------------------------------------------
    $service = !empty($old['service_id']) ? service_load((string) $old['service_id']) : null;

    // 회전 '전' 에 판정해야 한다. revoke 후에는 쿠키가 가리키던 세션이 이미 죽어 있다.
    $setCookie = !empty($opt['set_cookie']) || cookie_binds_session($old);

    $issued = db_tx(function () use ($old, $user, $service, $opt, $setCookie) {
        revoke_session((int) $old['id'], 'refresh_rotated');
        $copt = [
            'device_label' => $opt['device_label'] ?? ($old['device_label'] ?? device_label_from_ua()),
            'with_refresh' => true,
            'set_cookie'   => $setCookie,
            // 리더 판정 D-162 — 회전이 세션의 service_id 를 **잃지 않는다.**
            'service_id_fallback' => !empty($old['service_id']) ? (string) $old['service_id'] : null,
        ];
        if (array_key_exists('cookie_ttl', $opt)) {
            $copt['cookie_ttl'] = $opt['cookie_ttl'];
        }
        return create_session($user, $service, (string) $old['auth_type'], $copt);
    });

    $us = null;
    if (!empty($old['service_id'])) {
        $us = db_one('SELECT * FROM ' . T_USER_SERVICES . ' WHERE user_id = ? AND service_id = ?',
            [$user['id'], $old['service_id']]);
    }

    return [
        'user'               => $user,
        'user_service'       => $us,
        'service'            => $service,
        'grades'             => effective_grades($user, $us),
        'session'            => $issued['row'],
        'access_token'       => $issued['access_token'],
        'refresh_token'      => $issued['refresh_token'],
        'expires_in'         => $issued['expires_in'],
        'refresh_expires_in' => $issued['refresh_expires_in'],
    ];
}

// ---------------------------------------------------------------------
// user_services
// ---------------------------------------------------------------------

/**
 * 이 사용자가 해당 서비스에서 차단됐는가. 리더 판정 D-31 / 감사 H-1.
 *
 * `user_services.status` 가 'active' 가 아니면 true. 가입 행이 아직 없으면
 * **false** 다 — 차단이 아니라 미가입이며, auto_join 으로 곧 만들어질 수 있다.
 * ('active' 외 전부를 차단으로 보는 것은 열거값이 늘어나도 기본 거부가 되게 하기 위함이다)
 *
 * 호출측(api/auth/login.php, api/auth/exchange.php, api/sso/authorize.php)은
 * true 면 `FORBIDDEN`(403) 으로 거부한다. 전역 계정은 정상이므로
 * AUTH_ACCOUNT_SUSPENDED 가 아니다.
 *
 * @param int         $userId    users.id (내부 ID)
 * @param string|null $serviceId services.service_id. null/빈문자면 false
 */
function service_membership_blocked(int $userId, ?string $serviceId): bool
{
    if ($userId <= 0 || $serviceId === null || $serviceId === '') {
        return false;
    }
    $st = db_val('SELECT status FROM ' . T_USER_SERVICES . ' WHERE user_id = ? AND service_id = ?',
        [$userId, $serviceId]);
    if ($st === null) {
        return false;   // 미가입 — 차단이 아니다
    }
    return (string) $st !== 'active';
}

/**
 * 새 `user_services` 행에 심을 등급 오버라이드를 계산한다.
 * **가입 경로가 몇 개든 이 함수 하나만 쓴다** (리더 판정 D-49 / 감사 M-16).
 *
 * 호출부 2곳:
 *   1) `ensure_user_service()` (바로 아래)          — 자동 가입 (`services.auto_join=1`)
 *   2) `api/auth/register.php`                      — 명시적 가입 (회원가입 시 service_id 동반)
 *
 * D-35 는 (1) 만 grant-only 로 고쳤고 (2) 는 `default_account_level` 을 무조건
 * 오버라이드로 박아 넣은 채 남았다(감사 M-16). 같은 규칙을 두 곳에 복제했기 때문에
 * 한 곳만 고쳐진 것이다 — 그래서 규칙 자체를 함수로 뽑았다. 세 번째 가입 경로가
 * 생기더라도 이 함수를 부르면 규칙이 자동으로 따라온다.
 *
 * **`default_account_level` 은 grant-only 다** (D-35 / QA Q-3).
 * 컬럼의 의미는 "가입 시 최소 보장 등급" 이며, 전역 등급을 절대 강등하지 않는다.
 * 예전 구현은 이 값을 무조건 오버라이드로 넣어, 전역 `verified` 사용자가
 * 서비스 기본값 `basic` 때문에 강등되고 payload 에서 `ai_api_key` 가 조용히 사라졌다.
 *   - 기본은 NULL(전역 상속)
 *   - `default_account_level` 의 랭크가 전역보다 **높을 때만** 오버라이드로 기록
 *   - `role`·`billing_tier` 오버라이드는 어떤 가입 경로에서도 항상 NULL (권한 승격 금지)
 *
 * 반환 배열은 그대로 `db_insert(T_USER_SERVICES, …)` 에 펼쳐 쓸 수 있게 컬럼명으로 맞췄다.
 *
 * @param array|null $service services 행. null 이면 전부 NULL
 * @param array|null $user    users 행(최소 `account_level` 키). 아직 커밋 전인 행도 그대로 넘길 수 있다
 * @param int        $userId  $user 가 없을 때 전역 등급을 읽어 올 users.id. 0 이면 읽지 않는다
 * @return array{account_level:?string, role:null, billing_tier:null}
 */
function service_grade_overrides(?array $service, ?array $user = null, int $userId = 0): array
{
    $none = ['account_level' => null, 'role' => null, 'billing_tier' => null];
    if ($service === null) {
        return $none;
    }
    $def = isset($service['default_account_level']) && (string) $service['default_account_level'] !== ''
        ? (string) $service['default_account_level']
        : null;
    if ($def === null) {
        return $none;
    }
    if (($user === null || !isset($user['account_level'])) && $userId > 0) {
        $user = db_one('SELECT account_level FROM ' . T_USERS . ' WHERE id = ?', [$userId]);
    }
    $global = (string) ($user['account_level'] ?? 'basic');

    // 문자열 비교 금지 — 랭크로만 비교한다 (perm.php 규약. 'vip' > 'staff' 는 문자열로는 참이다).
    // 전역이 default 이상이면 오버라이드를 만들지 않는다(= 강등 불가).
    // 알 수 없는 열거값은 rank 0 이 되어 자동으로 '기록하지 않음' 으로 떨어진다.
    if (level_gte($global, $def)) {
        return $none;
    }
    return ['account_level' => $def, 'role' => null, 'billing_tier' => null];
}

/**
 * 서비스 자동 가입(services.auto_join=1). 이미 있으면 last_used_at 만 갱신한다.
 *
 * 등급 오버라이드 규칙은 `service_grade_overrides()` 에 있다 — D-49 로 명시적 가입
 * 경로(`api/auth/register.php`)와 규칙을 하나로 합쳤다. 여기서 다시 판정하지 마라.
 *
 * @param int        $userId
 * @param array|null $service services 행
 * @param array|null $user    users 행. 없으면 전역 등급만 다시 읽는다(선택 인자 — 기존 호출부 무영향)
 * @return array|null user_services 행
 */
function ensure_user_service(int $userId, ?array $service, ?array $user = null)
{
    if ($service === null) {
        return null;
    }
    $sid = (string) $service['service_id'];
    $us  = db_one('SELECT * FROM ' . T_USER_SERVICES . ' WHERE user_id = ? AND service_id = ?', [$userId, $sid]);
    if ($us !== null) {
        db_exec('UPDATE ' . T_USER_SERVICES . ' SET last_used_at = ? WHERE id = ?', [now_utc(), $us['id']]);
        $us['last_used_at'] = now_utc();
        return $us;
    }
    if (empty($service['auto_join'])) {
        return null;
    }

    // D-35 / D-49 grant-only 판정 — 규칙은 한 곳에만 있다.
    $ov = service_grade_overrides($service, $user, $userId);

    $now = now_utc();
    $id  = db_insert(T_USER_SERVICES, [
        'user_id'       => $userId,
        'service_id'    => $sid,
        'account_level' => $ov['account_level'],   // NULL = 전역 상속
        'role'          => $ov['role'],
        'billing_tier'  => $ov['billing_tier'],
        'status'        => 'active',
        'joined_at'     => $now,
        'last_used_at'  => $now,
    ]);
    return db_one('SELECT * FROM ' . T_USER_SERVICES . ' WHERE id = ?', [$id]);
}
