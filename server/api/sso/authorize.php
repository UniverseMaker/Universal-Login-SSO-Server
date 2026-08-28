<?php
declare(strict_types=1);
/**
 * POST /api/sso/authorize — 자격 검증 후 SSO code 발급
 * 담당 계약: 01_architect_api_contract.md §5-2, 스펙 §4-2
 * 응답 최상위 키: redirect_to, code, state, expires_in
 *
 * 여기서는 **code 만** 나간다. 토큰도 시크릿도 나가지 않는다.
 * 시크릿은 POST /api/auth/exchange 응답 본문으로만 전달된다 — URL 에 실리면
 * 브라우저 히스토리·리퍼러·프록시 로그에 AI API 키가 남는다.
 *
 * 락아웃·레이트리밋·로그 정책은 api/auth/login.php 와 동일하다.
 * (같은 자격증명 검증 경로가 두 개인데 정책이 다르면 약한 쪽이 우회로가 된다)
 */
require_once __DIR__ . '/../../core/bootstrap.php';

// 1) 메서드
only_method('POST');

// 2) 레이트리밋 — 인증보다 먼저. login.php 와 같은 버킷을 공유한다.
//    버킷을 분리하면 authorize 로 10회 + login 으로 10회를 시도할 수 있다.
rate_limit('login:ip:' . client_ip(), 10, 300);

// 3) 입력
$in          = body_json();
$loginId     = (string) v_string($in, 'username', 1, 190, true);
$password    = (string) v_string($in, 'password', 1, 200, true);
$serviceId   = (string) v_string($in, 'service_id', 1, 64, true);
$redirectUri = (string) v_string($in, 'redirect_uri', 1, 500, true);
$state       = v_string($in, 'state', 1, 255);
$challenge   = v_string($in, 'code_challenge', 1, 128);
$chalMethod  = v_string($in, 'code_challenge_method', 1, 10);

rate_limit('login:user:' . mb_strtolower($loginId, 'UTF-8'), 5, 300);

// PKCE 는 S256 만 지원한다. plain 은 challenge 가 곧 verifier 여서 방어력이 없다.
if ($challenge !== null && $chalMethod !== null && strtoupper($chalMethod) !== 'S256') {
    v_fail('code_challenge_method', 'PKCE 는 S256 만 지원합니다.');
}

// 4) 서비스 + redirect_uri 검증 — **code 발급보다 먼저**.
//    발급 후에 검증하면 실패한 시도마다 유효한 code 가 DB 에 쌓인다.
$service = service_require($serviceId);
if (!redirect_uri_allowed($service, $redirectUri)) {
    throw new AppError('SSO_INVALID_REDIRECT', 400, ['field' => 'redirect_uri']);
}

// 5) 사용자 조회 — username 또는 email
$norm = mb_strtolower(trim($loginId), 'UTF-8');
$user = db_one('SELECT * FROM ' . T_USERS . ' WHERE username = ? OR email = ? LIMIT 1', [$norm, $norm]);

// 계정이 없어도 더미 해시로 password_verify 를 한 번 돌린다.
// 즉시 반환하면 응답 시간 차이로 계정 존재 여부가 새어나간다.
if ($user === null) {
    dummy_password_verify($password);
    log_login('fail_credential', null, $loginId, $serviceId);
    throw new AppError('AUTH_INVALID_CREDENTIALS', 401, ['field' => 'password']);
}

// 6) 락아웃 — 비밀번호 검증보다 먼저
try {
    assert_not_locked($user);
} catch (AppError $e) {
    log_login('fail_locked', (int) $user['id'], $loginId, $serviceId);
    throw $e;
}

// 7) 비밀번호
if (!password_verify($password, (string) $user['password_hash'])) {
    register_login_failure($user);
    log_login('fail_credential', (int) $user['id'], $loginId, $serviceId);
    // 아이디 없음과 비밀번호 틀림을 구분하지 않는다 — 계정 열거 방지
    throw new AppError('AUTH_INVALID_CREDENTIALS', 401, ['field' => 'password']);
}

// 8) 점검 모드 — 관리자는 통과시킨다
if ((bool) setting_get('maintenance_mode', MAINTENANCE_MODE)
    && !role_gte((string) $user['role'], 'manager')) {
    log_login('fail_status', (int) $user['id'], $loginId, $serviceId);
    throw new AppError('MAINTENANCE_MODE', 503);
}

// 9) 계정 상태
$requireVerify = (bool) setting_get('require_email_verify', REQUIRE_EMAIL_VERIFY);
if ($requireVerify && empty($user['email_verified_at'])) {
    log_login('fail_status', (int) $user['id'], $loginId, $serviceId);
    throw new AppError('AUTH_EMAIL_UNVERIFIED', 403, ['email' => (string) $user['email']]);
}
try {
    assert_user_active($user, true);
} catch (AppError $e) {
    log_login('fail_status', (int) $user['id'], $loginId, $serviceId);
    throw $e;
}

// 9-b) 서비스 단위 차단 — 리더 판정 D-31 / 감사 H-1.
//      자격 검증과 전역 계정 상태는 통과했으나 이 서비스에 대해서만 이용이 막힌 상태다.
//      전역 계정은 정상이므로 AUTH_ACCOUNT_SUSPENDED 가 아니라 FORBIDDEN 이다.
//
//      **11) auth code 발급보다 먼저** 판정한다. 뒤에 두면 거부된 시도마다
//      유효한 code 행이 DB 에 쌓인다(4) 의 주석과 같은 이유).
//      login.php 와 동일하게 "10) 성공 처리"(last_login_at·login_count) 앞에 둔다.
//      이 파일은 user_services 를 로드하지 않으므로 헬퍼로만 판정한다.
if (service_membership_blocked((int) $user['id'], (string) $service['service_id'])) {
    // D-16 에 따라 이 파일이 실패 로그를 담당한다. 성공 로그는 exchange.php 만 남기므로
    // 중복은 생기지 않는다. result 는 리더 판정 D-46 의 서비스 차단 전용 값.
    log_login('fail_service_blocked', (int) $user['id'], $loginId, $serviceId);
    throw new AppError('FORBIDDEN', 403, ['message' => '이 서비스 이용이 제한되었습니다.']);
}

// 10) 성공 처리
//     exchange.php 는 last_login_* 을 갱신하지 않는다. SSO 로만 쓰는 계정의
//     last_login_at 이 영구히 NULL 로 남지 않도록 자격 검증 시점에 갱신한다.
$now = now_utc();
if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
    // password_changed_at 은 건드리지 않는다. 알고리즘 재해시일 뿐 비밀번호는 그대로다.
    db_exec('UPDATE ' . T_USERS . ' SET password_hash = ? WHERE id = ?',
        [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
}
clear_login_failures((int) $user['id']);
db_exec(
    'UPDATE ' . T_USERS . ' SET last_login_at = ?, last_login_ip = ?, login_count = login_count + 1, updated_at = ?'
    . ' WHERE id = ?',
    [$now, client_ip(), $now, $user['id']]
);

// 11) code 발급 — 60초. 원문은 응답으로만 나가고 DB 에는 SHA-256 만 저장한다.
$ttl  = (int) setting_get('auth_code_ttl', AUTH_CODE_TTL);
if ($ttl <= 0) {
    $ttl = (int) AUTH_CODE_TTL;
}
$code = gen_token(32);

db_insert(T_AUTH_CODES, [
    'code_hash'      => token_hash($code),
    'user_id'        => (int) $user['id'],
    'service_id'     => (string) $service['service_id'],
    // 발급 시점 값을 그대로 저장한다. exchange 가 이 값과 재대조한다.
    'redirect_uri'   => mb_substr($redirectUri, 0, 500),
    'state'          => $state,
    'code_challenge' => $challenge,
    'expires_at'     => at_utc($ttl),
    'created_ip'     => client_ip(),
    'created_at'     => $now,
]);

// 12) redirect_to 조립 — **서버가 만든다.**
//     조립 로직이 서버와 프론트 두 곳에 있으면 반드시 어긋난다.
//     프래그먼트(#)가 있으면 쿼리는 그 앞에 붙어야 한다.
$frag = '';
$base = $redirectUri;
$hash = strpos($base, '#');
if ($hash !== false) {
    $frag = substr($base, $hash);
    $base = substr($base, 0, $hash);
}
$sep        = strpos($base, '?') === false ? '?' : '&';
$redirectTo = $base . $sep . 'code=' . rawurlencode($code);
if ($state !== null) {
    $redirectTo .= '&state=' . rawurlencode($state);
}
$redirectTo .= $frag;

// 13) 로깅
//     성공 로그는 남기지 않는다. exchange.php 가 code 를 실제로 소모할 때
//     log_login('success') 를 남기므로, 여기서도 남기면 SSO 로그인 1회에
//     login_logs 에 success 가 2건 쌓인다(사용자 화면의 "로그인 기록"이 어긋난다).
//     실패는 전부 위에서 기록했다.

json_out([
    'redirect_to' => $redirectTo,
    'code'        => $code,
    'state'       => $state,
    'expires_in'  => $ttl,
]);
