<?php
declare(strict_types=1);
/**
 * POST /api/sso/authorize_session — **무재인증(무음) SSO code 발급**
 * 담당 계약: 01_architect_api_contract.md §5-2 의 형제 경로 / 설계 70_architect_v17_spec.md §2-2·§2-3
 * 리더 판정: D-234(authorize.php 무변경) · D-235(동의 생략 + https 강제)
 * 응답 최상위 키: redirect_to, code, state, expires_in   ← `api/sso/authorize.php` 와 **같은 4키**
 *
 * OIDC 의 `prompt=none` 에 해당한다. 이미 로그인한 **브라우저 세션**을 보고
 * 비밀번호를 다시 묻지 않은 채 SSO code 를 발급한다.
 *
 * ── 왜 authorize.php 에 분기를 더하지 않고 파일을 나눴나 (설계 A-1 / D-234) ──
 * 같은 파일 안에서 「비밀번호가 있으면 A 정책, 없으면 B 정책」이 되면
 * 레이트리밋·락아웃·로깅이 한 파일 안에서 두 갈래가 된다. 그 파일의 머리 주석이
 * 경고하는 것이 정확히 「같은 자격 검증 경로가 두 개인데 정책이 다르면 약한 쪽이
 * 우회로가 된다」다. **정책이 다른 경로는 파일을 나눈다.**
 * `api/sso/authorize.php` 는 이 라운드에서 **한 줄도 고치지 않았다**(D-234).
 *
 * ── 이 경로가 기존 경로보다 엄격한 네 가지 (비대칭은 의도다 — 설계 A-4) ──
 *   1) `state` **필수**          — 무음 경로에는 사람의 개입이 한 번도 없다.
 *                                  상대 앱이 state 를 대조하는지는 강제할 수 없지만
 *                                  **부재는 거절할 수 있다.**
 *   2) `code_challenge` **필수** (S256) — code 가 브라우저를 한 번 더 지난다.
 *                                  서비스 화면에 XSS 가 있어 code 를 읽혀도
 *                                  verifier 가 서비스 **서버** 세션에만 있으면 교환이 안 된다.
 *   3) **쿠키 세션만** 인정       — `Authorization: Bearer` 는 거절한다. 서비스 A 에 발급된
 *                                  토큰이 서비스 B 의 code 를 찍어낼 수 있으면
 *                                  토큰 1건의 유출이 전 서비스 침해가 된다.
 *                                  무음 인가는 **브라우저 세션의 성질**이지 토큰의 성질이 아니다.
 *   4) **https 전용**            — 요청이 https 여야 하고 `redirect_uri` 의 scheme 도 https 여야 한다.
 *                                  외부 페이지가 사용자 브라우저를 이 경로로 보내
 *                                  **자기 PC 의 루프백 시스템에 로그인시키는** 길(로그인 CSRF)을
 *                                  닫는다. 등록된 내부 주소 항목은 무음 경로로는 도달 불가가 되고
 *                                  비밀번호 경로에서만 쓸 수 있다 — 그것이 시험용의 실제 용법이다.
 *
 * **기존 `authorize.php` 의 state·PKCE 는 지금처럼 선택이다.** 필수로 바꾸면
 * 공개 저장소(v1.6.0)를 보고 만든 연동 앱이 깨진다 — 계약 무변경 원칙.
 *
 * ── 동의(consent) 화면을 두지 않은 근거와, 그 판정을 뒤집어야 하는 조건 (D-235) ──
 * 근거: 서비스 등록은 `role >= admin` 전용 / 복귀 주소는 origin+path 완전 일치 /
 *       7개 서비스 전부 `is_confidential` 이라 code 만으로는 토큰이 안 나온다 /
 *       무음 발급을 `issued_via='session'` 으로 구분 기록한다.
 * **근거 ③·④ 는 1차 구현에서 코드로 성립하지 않았다. 이번 라운드에 성립시켰다:**
 *   ③ `is_confidential` 을 **읽지 않았다** → 8-b) 블록 추가 (감사 M17-5).
 *      단 실제 버팀목은 그 줄이 아니라 **PKCE S256 필수**다 — 8-b) 주석을 보라.
 *   ④ 기록이 **교환됐을 때만** 생겼다 → 12) 블록에서 **발급 자체**를 남긴다 (M17-3).
 * **다음 중 하나라도 생기면 동의 없이 무음을 열어 두면 안 된다:**
 *   - 운영자가 소유하지 않는 제3자 시스템을 services 에 등록할 때
 *   - allow_register 로 가입한 불특정 다수가 같은 ulogin 을 쓰게 될 때
 *   - 서비스 등록 권한이 admin 아래로 내려갈 때
 *
 * ── 로그 (감사 M17-3 으로 **바뀌었다**) ──────────────────────────────
 * 기존 `authorize.php` 는 성공 로그를 남기지 않는다. **이 경로는 남긴다** —
 *   발급 시점: `issued_silent` (12번 블록)
 *   교환 시점: `success_silent` (`exchange.php`)
 * 둘을 나눈 이유는 **발급됐으나 교환되지 않은** 무음 인가가 D-235 근거 ④ 의
 * 사각지대였기 때문이다. 자세한 근거는 12번 블록 주석에 있다.
 * 비밀번호 경로와 규칙이 달라지는 것은 **의도**다 — 그 경로에는 사람의 개입이 있다.
 */
require_once __DIR__ . '/../../core/bootstrap.php';

// ---------------------------------------------------------------------
// 1) 메서드
// ---------------------------------------------------------------------
only_method('POST');

// ---------------------------------------------------------------------
// 2) 레이트리밋 (IP) — 인증보다 먼저. 설계 A-5.
//
//    **`login:*` 버킷을 공유하지 않는다.** 공유하면 반대 사고가 난다:
//    사용자가 7개 시스템을 오가는 정상 사용이 `login:ip` 10/300 을 소진해
//    **로그인 자체가 막힌다.** 「authorize 10 + login 10 = 20회 추측」 경고는
//    두 경로가 **같은 자격증명을 받을 때**의 이야기이고, 이 경로는
//    자격증명을 받지 않는다 — 이미 유효한 쿠키 세션을 요구한다.
//
//    그래도 상한을 두는 이유: 유효한 세션 하나로 code 를 무제한 찍어낼 수 있으면
//    auth_codes 테이블이 공격면이 된다. 60/300 은 정상 사용(시스템 7개 × 재진입)의 수 배다.
//    전역 `api:ip` 300/60 은 그대로 위에 걸린다.
//    rate_limit_logged — 한도 도달이 login_logs 에 남는다(D-153).
// ---------------------------------------------------------------------
rate_limit_logged('sso_silent:ip:' . client_ip(), 60, 300);

// ---------------------------------------------------------------------
// 3) CSRF — 쿠키로 인증하는 상태 변경 요청이다.
//
//    주의: require_csrf() 는 Bearer 요청을 면제한다(브라우저가 자동으로 싣지 않으므로
//    CSRF 의 전제가 없다). 그 면제로 들어온 Bearer 요청은 **5) 에서 거절된다.**
// ---------------------------------------------------------------------
require_csrf();

// ---------------------------------------------------------------------
// 4) 입력 — state 와 code_challenge 가 **필수**다
// ---------------------------------------------------------------------
$in          = body_json();
$serviceId   = (string) v_service_id($in, 'service_id', true);   // v1.8.6 — 옛 id 는 여기서 새 id 로(설계 104 C-1)
$redirectUri = (string) v_string($in, 'redirect_uri', 1, 500, true);
$state       = v_string($in, 'state', 1, 255);
$challenge   = v_string($in, 'code_challenge', 1, 128);
$chalMethod  = v_string($in, 'code_challenge_method', 1, 10);

if ($state === null) {
    v_fail('state', 'state는 무음 로그인에 필수입니다.');
}
if ($challenge === null) {
    v_fail('code_challenge', 'code_challenge(PKCE S256)는 무음 로그인에 필수입니다.');
}
// PKCE 는 S256 만 지원한다. plain 은 challenge 가 곧 verifier 여서 방어력이 없다.
// 값이 없으면 S256 으로 본다 — 지원하는 방식이 하나뿐이라 다른 해석이 없다.
if ($chalMethod !== null && strtoupper($chalMethod) !== 'S256') {
    v_fail('code_challenge_method', 'PKCE는 S256만 지원합니다.');
}
// 형식 — 이 검사는 **정책이 아니라 조기 실패**다. 통과하지 못하는 값은
// exchange 의 pkce_verify() 에서도 결코 일치할 수 없다: 그 함수가 만드는 비교값은
// `base64url(sha256(verifier))` 이고 S256 출력은 **언제나 정확히 43자 base64url** 이다.
// 즉 여기서 거절되는 값은 「60초 뒤 exchange 에서 SSO_CODE_INVALID 가 될 code」 뿐이다.
// 동작하는 클라이언트를 거절할 수 없는 검사이므로 넣었다.
if (preg_match('/^[A-Za-z0-9\-_]{43}$/', (string) $challenge) !== 1) {
    v_fail('code_challenge', 'code_challenge 형식이 올바르지 않습니다(S256 base64url 43자).');
}

// ---------------------------------------------------------------------
// 5) 인증 — **쿠키 세션만**.
//
//    require_auth() 가 세션 4조건(회수·만료·계정 상태·비밀번호 변경 이후 발급)을
//    전부 보므로 assert_user_active() 를 따로 부르지 않는다.
//    assert_not_locked() 는 **돌리지 않는다** — 락아웃은 비밀번호 추측에 대한
//    방어이고 이 경로에는 비밀번호가 없다. 이미 유효한 세션을 들고 온 사용자를
//    남이 틀린 비밀번호를 넣었다는 이유로 막을 근거가 없다.
//
//    세션이 없으면 require_auth() 가 AUTH_REQUIRED 401 / AUTH_SESSION_EXPIRED 401 을 던진다.
//    **화면은 그 둘을 오류로 표시하지 않는다** — 「무음이 안 됐다」는 뜻이고
//    정상 동작으로서 비밀번호 폼을 그린다.
// ---------------------------------------------------------------------
$ctx = require_auth(true);
if (($ctx['auth_via'] ?? '') !== 'cookie') {
    throw new AppError('FORBIDDEN', 403, [
        'message' => '무음 로그인은 브라우저 세션에서만 사용할 수 있습니다.',
    ]);
}
$user = $ctx['user'];

// ---------------------------------------------------------------------
// 6) 레이트리밋 (사용자) — 설계 A-5.
//    입력값이 아니라 **인증된 세션의 user_id** 로 건다. 열거 재료가 되지 않는다.
// ---------------------------------------------------------------------
rate_limit_logged('sso_silent:user:' . (int) $user['id'], 30, 300,
    (int) $user['id'], (string) $user['username'], $serviceId);

// ---------------------------------------------------------------------
// 7) 전송 계층 — https 전용 (설계 B-4 / D-235)
//
//    앞 조건은 사실상 자동으로 성립한다(쿠키가 Secure 라 평문 요청에는 세션이 실리지 않는다).
//    그래도 명시한다 — COOKIE_SECURE 설정이 바뀌면 그 전제가 조용히 사라진다.
//    뒤 조건이 **공개 진입에서 루프백 콜백으로 가는 길**을 막는다.
// ---------------------------------------------------------------------
if (!request_is_https()) {
    throw new AppError('FORBIDDEN', 403, [
        'message' => '무음 로그인은 https 연결에서만 사용할 수 있습니다.',
    ]);
}
$scheme = '';
$parsed = parse_url($redirectUri);
if (is_array($parsed) && isset($parsed['scheme'])) {
    $scheme = strtolower((string) $parsed['scheme']);
}
if ($scheme !== 'https') {
    throw new AppError('SSO_INVALID_REDIRECT', 400, ['field' => 'redirect_uri']);
}

// ---------------------------------------------------------------------
// 8) 서비스 + redirect_uri 화이트리스트 — **code 발급보다 먼저**.
//    발급 후에 검증하면 실패한 시도마다 유효한 code 가 DB 에 쌓인다.
// ---------------------------------------------------------------------
$service = service_require($serviceId);
if (!redirect_uri_allowed($service, $redirectUri)) {
    throw new AppError('SSO_INVALID_REDIRECT', 400, ['field' => 'redirect_uri']);
}

// ---------------------------------------------------------------------
// 8-b) **`is_confidential` 을 실제로 읽는다** (감사 M17-5).
//
//      D-235 가 동의 화면을 생략한 세 번째 근거는 「7개 서비스 전부 `is_confidential`
//      이라 code 만으로는 토큰이 안 나온다」였다. 그런데 1차 구현은 이 파일에서
//      `$service['is_confidential']` 을 **한 번도 읽지 않았다.** 근거가 코드가 아니라
//      **DB 의 현재 상태**에 걸려 있었다 — 상태는 콘솔에서 한 번에 바뀐다.
//
//      ★ **지금 실제로 막고 있는 것은 이 줄이 아니라 PKCE S256 필수(4번 블록)다.** ★
//      `is_confidential=0` 인 서비스가 등록돼도 code 단독으로는 교환되지 않는다 —
//      `api/auth/exchange.php` 의 `pkce_verify()` 가 verifier 를 요구하고, 그 값은
//      서비스 **서버** 세션에만 있기 때문이다. 이 검사는 **두 번째 겹**이다.
//
//      그 사실을 여기 적는 이유: 「무음 경로의 PKCE 필수를 완화하자」(연동 앱 호환
//      요구는 반드시 온다)는 제안이 올라왔을 때, D-235 를 읽은 사람은
//      「is_confidential 이 막아 준다」고 믿는다. **둘 중 하나만 있어도 되는 것이
//      아니다.** 하나를 완화하려면 **다른 하나를 먼저 보라.** 이 주석이 그때의
//      유일한 방어다 — 지우지 마라.
//
//      서비스별 옵트인(`services.allow_silent_sso`)으로 바꾸는 것이 정석이고
//      (OIDC 의 `prompt=none` 허용 여부에 해당) 마이그레이션이 필요하므로
//      architect 에게 다음 라운드 후보로 올렸다.
// ---------------------------------------------------------------------
if (empty($service['is_confidential'])) {
    throw new AppError('FORBIDDEN', 403, [
        'message' => '이 서비스는 무음 로그인을 사용할 수 없습니다.',
    ]);
}

// ---------------------------------------------------------------------
// 9) 점검 모드 — authorize.php 와 같은 규칙. manager 이상은 통과.
// ---------------------------------------------------------------------
if ((bool) setting_get('maintenance_mode', MAINTENANCE_MODE)
    && !role_gte(ctx_global_role($ctx), 'manager')) {
    log_login('fail_status', (int) $user['id'], (string) $user['username'], $serviceId);
    throw new AppError('MAINTENANCE_MODE', 503);
}

// ---------------------------------------------------------------------
// 10) 서비스 단위 차단 — D-31 / 감사 H-1.
//     전역 계정은 정상이므로 AUTH_ACCOUNT_SUSPENDED 가 아니라 FORBIDDEN 이다.
//     **11) 발급보다 먼저** 판정한다 — 8) 의 주석과 같은 이유.
// ---------------------------------------------------------------------
if (service_membership_blocked((int) $user['id'], (string) $service['service_id'])) {
    log_login('fail_service_blocked', (int) $user['id'], (string) $user['username'], $serviceId);
    throw new AppError('FORBIDDEN', 403, ['message' => '이 서비스 이용이 제한되었습니다.']);
}
// 10-b) v1.9.0 서비스 접근 규칙(80 §5-3 집행 2번). 무음 code 발급 **앞**.
require_service_access($user, service_access_us_row((int) $user['id'], (string) $service['service_id']), $service, 'authorize_session');

// ---------------------------------------------------------------------
// 11) **여기서 처음으로** code 를 발급한다. 60초·1회성.
//     원문은 응답으로만 나가고 DB 에는 SHA-256 만 저장한다.
//
//     `issued_via = 'session'` — 마이그레이션 0004 가 추가한 컬럼이다.
//     exchange.php 가 이 값을 보고 `success_silent` 로 기록한다.
//     기존 `authorize.php` 는 이 컬럼을 쓰지 않으므로 DEFAULT 'password' 로 떨어진다.
//
//     **0004 를 적용하지 않은 서버에서는 이 INSERT 가 실패하고 500 이 난다.
//     그것이 맞다.** 무음 인가에 동의 화면을 두지 않기로 한 판정(D-235)의 조건이
//     「무음 발급을 구분해 기록한다」였다. 기록할 컬럼이 없으면 그 조건이 깨진 것이고,
//     그 상태에서 code 를 내주면 **아무 흔적 없이** 무음 로그인이 성립한다.
//     컬럼을 빼고 INSERT 하는 폴백을 두지 않는 이유가 이것이다 — fail-closed.
//     운영자는 서버 로그에서 `Unknown column 'issued_via'` 를 보고
//     `php server/migrate.php apply` 를 돌리면 된다.
//     (exchange.php 쪽은 반대로 `??` 폴백을 둔다. 그쪽은 **읽기**라 폴백해도
//      새 기록이 왜곡되지 않고, 막으면 기존 SSO 가 통째로 멈춘다.)
//
//     last_login_at / login_count 는 **갱신하지 않는다.** authorize.php 가 그것을
//     갱신하는 이유는 「SSO 로만 쓰는 계정의 last_login_at 이 영구히 NULL 로 남지
//     않게」였는데, 무음 경로는 **이미 그 계정이 쿠키 세션으로 로그인한 상태**를
//     전제한다 — 그 로그인 시점에 login.php 가 이미 갱신했다. 여기서 또 올리면
//     시스템을 오갈 때마다 login_count 가 늘어 그 숫자의 뜻이 바뀐다.
// ---------------------------------------------------------------------
$ttl = (int) setting_get('auth_code_ttl', AUTH_CODE_TTL);
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
    'issued_via'     => 'session',
    'expires_at'     => at_utc($ttl),
    'created_ip'     => client_ip(),
    'created_at'     => now_utc(),
]);

// ---------------------------------------------------------------------
// 12) **발급 자체를 남긴다** — `issued_silent` (감사 M17-3 / D-235 근거 ④).
//
//     D-235 가 동의 화면을 생략한 네 번째 근거는 「무음 발급을 구분 기록한다」였다.
//     1차 구현에서 실제로 남는 것은 **exchange 가 code 를 교환했을 때의
//     `success_silent` 하나**뿐이었다. 즉 **발급됐으나 교환되지 않은** 무음 인가는
//     흔적이 전혀 없었다 — `auth_codes` 행은 60초 뒤 만료되고 `gc_expired()` 가 치운다.
//
//     **그런데 그것이 바로 감사하고 싶은 사건이다.** 제3자 페이지가 피해자의 브라우저를
//     `login.html?service_id=…&state=…&code_challenge=…` 로 몰아넣으면, 피해자가
//     로그인 상태인 한 무음 code 가 발급되고 등록된 서비스로 리다이렉트된다.
//     서비스가 `state` 불일치로 거절하면 **교환이 일어나지 않고 → 로그 0행**이다.
//     운영자가 「누가 내 세션으로 무음 인가를 돌렸나」를 물으면 답할 데이터가 없었다.
//     실패·거절은 남는데(`fail_status`·`fail_service_blocked`·`fail_ratelimit`)
//     **성공만 안 남는** 상태였다 — 방향이 반대다.
//
//     그래서 **두 사건을 각각** 남긴다. 둘은 다른 사건이다:
//       `issued_silent`  = 이 서버가 무음 code 를 내줬다            (여기)
//       `success_silent` = 그 code 가 실제로 토큰과 교환됐다        (exchange.php)
//     전자만 있고 후자가 없는 줄 = **발급됐는데 쓰이지 않은 무음 인가**. 그것이 신호다.
//
//     행 수: 무음 발급의 상한은 `sso_silent:user` 30/300 이고 정상 사용은
//     「시스템 7개 × 재진입」 수준이다. `secret_access_logs` 처럼 윈도우 UPSERT 로
//     접지 않는다 — `login_logs` 는 **한 행 = 한 시점**이어야 마이페이지 이력 화면이
//     그대로 그려진다(`core/ratelimit.php` 의 `rate_limit_logged()` 주석과 같은 판단이다).
//
//     `login_logs.result` 는 VARCHAR(30), `'issued_silent'` 는 13자다.
//     **값을 읽는 쪽을 같은 라운드에 함께 고쳤다**(D-172 생산자/소비자 쌍):
//       server/api/admin/logs.php     `$results` 화이트리스트 (+ summary 키)
//       server/api/me/login_logs.php  v_enum 화이트리스트
//       server/schema.sql · migrations/0004  의 COLUMN COMMENT (정본 목록)
//       server/core/logger.php        log_login() 의 @param 목록
//     화면의 한글 라벨은 frontend 몫이다 — 없으면 「알 수 없음」으로 보인다.
//     **리터럴을 그대로 둔다.** tests/lib/checks.php 의 `ck_result_values_server()` 가
//     `log_login()` **첫 인자의 소문자 문자열 리터럴**을 result 값으로 수집한다.
//     비교식을 인자 안에 넣으면 엉뚱한 값이 result 로 읽힌다
//     (`api/auth/exchange.php` 의 `$viaSilent` 를 왜 호출 밖에서 만드는지 보라).
// ---------------------------------------------------------------------
log_login('issued_silent', (int) $user['id'], (string) $user['username'], $serviceId);

// ---------------------------------------------------------------------
// 13) redirect_to 조립 — **서버가 만든다.** core/auth.php 의 공용 헬퍼를 쓴다.
//     프래그먼트 처리까지 authorize.php 와 같아야 한다.
// ---------------------------------------------------------------------
json_out([
    'redirect_to' => sso_build_redirect_to($redirectUri, $code, $state),
    'code'        => $code,
    'state'       => $state,
    'expires_in'  => $ttl,
]);
