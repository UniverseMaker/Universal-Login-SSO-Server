<?php
declare(strict_types=1);
/**
 * POST /api/auth/exchange — SSO code 를 토큰 + 시크릿으로 교환
 * 담당 계약: 01_architect_api_contract.md §4-9, 스펙 §4-2, §4-3
 * 응답 최상위 키: user, session, access_token, refresh_token, token_type,
 *                 expires_in, refresh_expires_in, state, issued_via, payload
 *                 (`issued_via` 는 v1.7.0 수정 라운드 신규 **선택 필드** — 감사 M17-4.
 *                  기존 연동 앱은 키가 하나 늘어도 깨지지 않는다)
 *                 account_link (v1.8.4 — 설계 92 S-5. **순수 추가.** 객체 = 연결된 로컬 계정 /
 *                  null = 연결 없음 / 키 없음 = 이 기능 이전의 ulogin. 세 상태를 구분한다)
 *
 * 이 시스템의 정규 경로다. 시크릿은 URL 이 아니라 이 POST 응답 본문으로만 나간다.
 *
 * code 실패 사유(없음/만료/재사용/service_id 불일치/redirect_uri 불일치/PKCE 불일치)는
 * **전부 SSO_CODE_INVALID 하나로** 응답한다. 구분하면 공격자에게 어느 단계까지
 * 성공했는지 알려주는 오라클이 된다.
 */
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/accountlink.php';

only_method('POST');

rate_limit_logged('exchange:ip:' . client_ip(), 30, 300);

$in          = body_json();
$code        = (string) v_string($in, 'code', 1, 128, true);
// v1.8.6 — 옛 id 는 **이 줄에서** 새 id 로 푼다(설계 104 C-1). 아래 code 행 대조(hash_equals)가
//           입력 변수를 직접 비교하므로 조회 함수가 아니라 입력에서 풀어야 한다.
$serviceId   = (string) v_service_id($in, 'service_id', true);
$redirectUri = (string) v_string($in, 'redirect_uri', 1, 500, true);
$verifier    = v_string($in, 'code_verifier', 1, 200);

// RFC 7636 §4.1 — code_verifier 는 43~128자다. 종전 하한은 1자였다 (감사 L17-3).
// **공격자가 이득을 보지는 않는다** — 엔트로피는 클라이언트가 정하고, 어떤 값이든
// 발급 시점의 challenge 와 맞아야 교환된다. 문제는 **깨진 클라이언트를 조용히
// 통과시키는 것**이다(1자 verifier 를 쓰는 앱은 PKCE 를 쓰지 않는 것과 같은데,
// 그 사실이 어디에도 드러나지 않았다).
//
// 응답은 `SSO_CODE_INVALID` 로 뭉갠다 — 이 파일의 규약이 「code 실패 사유를
// 구분하지 않는다」이고, 사유를 쪼개면 어느 단계까지 맞았는지 알려주는 오라클이 된다.
// 깨진 클라이언트를 고칠 사람이 볼 곳은 **서버 로그**다.
//
// [실측] `public/assets/ulogin-client.js` 의 `makePkce()` 가 쓰는 `randomToken(48)` 은
// base64url 64자라
// 이 범위 안이다. 기존 연동 앱이 깨지지 않는다.
if ($verifier !== null) {
    $vlen = strlen($verifier);
    if ($vlen < 43 || $vlen > 128) {
        error_log('[ulogin] exchange: code_verifier length ' . $vlen
            . ' is outside RFC 7636 range 43-128; rejecting. the integrating app is misconfigured.');
        throw new AppError('SSO_CODE_INVALID', 400);
    }
}
$clientSecret = v_string($in, 'service_secret', 1, 255);
$devLabel    = v_string($in, 'device_label', 1, 80);

// 1) 서비스 — code 와 무관한 검증이므로 먼저 한다
$service = service_require($serviceId);

// ---------------------------------------------------------------------
// 2) code 형식 — **클라이언트 인증보다 먼저** 본다. 리더 판정 D-153 H-1 / 보안 조사 A-4.
//
// v1.3.0 은 순서가 반대였다. 그 결과 익명이 이렇게 할 수 있었다:
//   쓰레기 code + 틀린 secret → 401 SSO_CLIENT_AUTH_FAILED
//   쓰레기 code + 맞는 secret → 400 SSO_CODE_INVALID
// **유효한 code 없이 응답 코드 두 종류만으로 service_secret 정오답을 가를 수 있었고**,
// 그 실패가 어느 로그에도 남지 않았다(login_logs 0행 · admin_logs 대상 아님).
// 실측으로 재현했다 — `_workspace/42a_backend_v14_report.md` 「High 2건」.
//
// 순서를 바꾸는 것만으로는 부족하다. 공격자는 자기 계정으로 정상 authorize 를 돌려
// 유효한 code 를 얻을 수 있고, 종전 코드는 클라이언트 인증 실패 시 code 를
// **소모하지 않았으므로** 같은 code 로 60초간 반복 추측이 가능했다.
// 그래서 아래 세 가지를 함께 건다:
//   (a) 형식 검사를 앞으로     — 유효한 code 없이는 401 분기에 도달조차 못 한다
//   (b) 인증 실패 시 code 소모 — 추측 1회당 authorize 왕복 1회의 비용이 붙는다
//   (c) 실패를 기록 + 서비스별 버킷 — 진행 중인 무차별 대입이 콘솔에 보인다
// ---------------------------------------------------------------------
if (!is_token_format($code)) {
    throw new AppError('SSO_CODE_INVALID', 400);
}

$result = db_tx(function () use ($code, $service, $serviceId, $redirectUri, $verifier, $devLabel, $clientSecret) {
    // 3) code 를 잠근 채로 읽는다. 잠금이 없으면 동시 요청이 같은 code 를 두 번 쓴다.
    //    v1.8.0 — 잠금 수단이 저장소마다 다르다(설계 B-2):
    //      MySQL  : sql_for_update() = ' FOR UPDATE' — 이 행의 레코드 잠금
    //      SQLite : sql_for_update() = '' — 대신 db_tx() 가 BEGIN IMMEDIATE 로 열어
    //               **트랜잭션 전체를** 다른 쓰기 트랜잭션과 직렬화한다(SQLite 에 행 잠금 문법이 없다)
    //    어느 쪽이든 아래 4) 의 조건부 UPDATE 가 2차 방어로 남는다. 지우지 마라.
    $row = db_one('SELECT * FROM ' . T_AUTH_CODES . ' WHERE code_hash = ?' . sql_for_update(), [token_hash($code)]);

    $bad = false;
    if ($row === null) {
        $bad = true;
    } elseif ($row['used_at'] !== null) {
        $bad = true;                                   // 재사용
    } elseif ((int) ts($row['expires_at']) <= time()) {
        $bad = true;                                   // 만료(60초)
    } elseif (!hash_equals((string) $row['service_id'], $serviceId)) {
        $bad = true;
    } elseif (!hash_equals(normalize_uri((string) $row['redirect_uri']), normalize_uri($redirectUri))
              || normalize_uri($redirectUri) === '') {
        $bad = true;                                   // 발급 시점 값과 완전 일치해야 한다
    } elseif (empty($service['is_confidential'])
              && trim((string) ($row['code_challenge'] ?? '')) === '') {
        // v1.10.0 감사 H-1 / D-372 — 공개 클라이언트(is_confidential=0)는 비밀값이 없으므로
        // PKCE 가 code 를 묶는 유일한 수단이다. challenge 없이 발급된 code 는 교환하지 않는다.
        // authorize.php 는 D-234 로 불변이라 발급 쪽이 아니라 여기서 막는다.
        // 사유는 SSO_CODE_INVALID 로 뭉갠다(이 파일의 규약). 원인은 서버 로그로만.
        error_log('[ulogin] exchange: public client ' . $serviceId
            . ' presented a code issued without code_challenge; PKCE is required for public clients.');
        $bad = true;
    } elseif (!pkce_verify($row['code_challenge'], $verifier)) {
        $bad = true;                                   // PKCE S256 불일치
    }
    if ($bad) {
        throw new AppError('SSO_CODE_INVALID', 400);
    }

    // 4) 소모 — 조건부 UPDATE 로 한 번 더 원자성을 보장한다
    $used = db_exec('UPDATE ' . T_AUTH_CODES . ' SET used_at = ? WHERE id = ? AND used_at IS NULL',
        [now_utc(), $row['id']]);
    if ($used !== 1) {
        throw new AppError('SSO_CODE_INVALID', 400);
    }

    // 4-b) confidential 클라이언트면 service_secret 을 확인한다 — D-153 H-1.
    //      **code 를 소모한 뒤**에 본다. 실패해도 code 는 소모된 채로 두어야
    //      같은 code 로 secret 을 반복 추측할 수 없다.
    //      여기서 예외를 던지지 않는 이유는 5-b) 와 같다 — 던지면 db_tx 가 롤백해
    //      4) 의 소모가 되돌아가고, 그러면 (b) 가 무력화된다.
    if (!empty($service['is_confidential'])) {
        $stored = (string) ($service['service_secret_hash'] ?? '');
        $ok     = false;
        if ($clientSecret !== null && $stored !== '') {
            if (is_token_format($stored)) {
                // SHA-256 hex 로 저장된 경우
                $ok = hash_equals($stored, token_hash($clientSecret));
            } else {
                $ok = password_verify($clientSecret, $stored);
            }
        } else {
            // 값이 없어도 반드시 한 번 돌린다. 즉시 반환하면 응답 시간 차이로
            // "이 서비스가 confidential 인가" 와 "secret 을 보냈는가" 가 새어나간다
            // (실측: 미전송 8ms vs 오답 50~76ms). core/auth.php 의 로그인 경로와 같은 규약.
            dummy_password_verify((string) $clientSecret);
        }
        if (!$ok) {
            return ['client_auth_failed' => true, 'user_id' => (int) $row['user_id']];
        }
    }

    // 5) 사용자
    $user = db_one('SELECT * FROM ' . T_USERS . ' WHERE id = ?', [$row['user_id']]);
    if ($user === null) {
        throw new AppError('SSO_CODE_INVALID', 400);
    }
    assert_user_active($user, true);   // suspended / withdrawn / dormant / pending 은 여기서 구분해 알린다

    // 5-b) 서비스 단위 차단 — 리더 판정 D-31 / 감사 H-1.
    //      **여기서 예외를 던지지 않는다.** 이 클로저는 db_tx 안이고, 던지면 롤백되어
    //      4) 의 code 소모(used_at)가 되돌아간다 → 같은 code 를 다시 쓸 수 있게 되고
    //      재사용 방지가 깨진다. 소모된 code 는 소모된 채로 두는 것이 맞다.
    //      대신 표식만 돌려주고 **세션 발급을 건너뛴다** — 거부는 커밋 뒤 트랜잭션
    //      밖에서 한다. 로그도 트랜잭션 밖에서 남긴다(안에서 남기면 함께 롤백된다).
    if (service_membership_blocked((int) $user['id'], (string) $service['service_id'])) {
        return ['blocked' => true, 'user' => $user];
    }
    // 5-b') v1.9.0 서비스 접근 규칙(80 §5-3 집행 3번) — 같은 규약: 여기서 던지지 않고 표식만. 거절은 커밋 뒤 밖에서.
    $accUs = service_access_us_row((int) $user['id'], (string) $service['service_id']);
    if (!service_access_eval($user, $accUs, $service)['allowed']) {
        return ['access_denied' => true, 'user' => $user, 'us' => $accUs];
    }

    $us     = ensure_user_service((int) $user['id'], $service);
    $issued = create_session($user, $service, 'token', [
        'device_label' => $devLabel,
        'with_refresh' => true,
        'set_cookie'   => false,   // 로컬 앱은 Bearer 를 쓴다. 쿠키를 심지 않는다.
        // v1.8.6 — 옛 id(별칭)로 들어왔으면 그 값을 세션에 남긴다. 이후 session·refresh 응답이
        // 같은 값을 되돌린다(설계 104 C-2). 새 id 로 들어오면 NULL.
        'client_service_id' => service_id_presented_alias((string) $service['service_id']),
    ]);

    return [
        'user'         => $user,
        'user_service' => $us,
        'issued'       => $issued,
        'state'        => $row['state'],
        // v1.7.0 — 이 code 를 발급한 경로. 'password' | 'session' (마이그레이션 0004).
        // **`??` 로 떨어지는 경우가 실제로 있다**: 0004 를 아직 적용하지 않은 설치본에서는
        // 컬럼 자체가 없어 키가 존재하지 않는다. 그 서버에는 무음 경로도 없으므로
        // 'password' 가 사실이다. 여기서 죽으면 기존 SSO 가 통째로 멈춘다.
        'issued_via'   => isset($row['issued_via']) ? (string) $row['issued_via'] : 'password',
    ];
});

// 4-c) 클라이언트 인증 실패 — 트랜잭션은 커밋됐고 code 는 소모된 상태다.
//      기록이 먼저다. 그다음 서비스별 실패 버킷을 올린다 — 이 버킷을 넘기면
//      뒤 시도는 429(RATE_LIMITED) 가 되고, 그것도 login_logs 에 남는다.
//      **응답 코드는 종전과 같은 401 SSO_CLIENT_AUTH_FAILED 다** — 계약을 바꾸지 않는다.
//      정오답을 가르는 오라클로 쓸 수 없게 만든 것은 응답 코드가 아니라
//      "유효한 code 가 필요하고 시도할 때마다 소모된다" 는 비용 쪽이다.
if (!empty($result['client_auth_failed'])) {
    $uid = (int) $result['user_id'];
    log_login('fail_client_auth', $uid > 0 ? $uid : null, '', $serviceId);
    rate_limit_logged('exchange:clientauth:' . $serviceId, 10, 900, $uid > 0 ? $uid : null, '', $serviceId);
    throw new AppError('SSO_CLIENT_AUTH_FAILED', 401, ['field' => 'service_secret']);
}

// 5-c) 차단 거부 — 트랜잭션은 여기서 이미 커밋됐다.
//      code 는 소모된 상태로 남고(재사용 불가), 세션·refresh 토큰은 만들어지지 않았다.
if (!empty($result['blocked'])) {
    $blocked = $result['user'];
    log_login('fail_service_blocked', (int) $blocked['id'], (string) $blocked['username'], $serviceId);
    throw new AppError('FORBIDDEN', 403, ['message' => '이 서비스 이용이 제한되었습니다.']);
}

// 5-d) v1.9.0 접근 거절 — code 는 소모된 채, 세션은 없다. 기록·거절은 require_service_access() 하나가 한다.
if (!empty($result['access_denied'])) {
    require_service_access($result['user'], $result['us'], $service, 'exchange');
    throw new AppError('SERVICE_ACCESS_DENIED', 403);   // 판정이 그 사이 바뀌었어도 이 요청은 거절이다
}

$user   = $result['user'];
$us     = $result['user_service'];
$issued = $result['issued'];
$grades = effective_grades($user, $us);

// 6) 로그인 이력에도 남긴다 — SSO 도 로그인이다
//
//    v1.7.0: 무음 인가(POST /api/sso/authorize_session)로 발급된 code 였으면
//    `success_silent` 로 **구분해** 남긴다. 설계 A-6 / D-235.
//
//    왜 구분하나 — 무음 경로에는 동의 화면이 없다. 등록된 서비스는 사용자 개입 없이
//    언제든 code 를 받는다. 동의를 받지 않기로 한 판정의 **조건**이 「사후에 확인할 수
//    있을 것」이었고, 그 확인 수단이 이 값이다. `success` 와 섞으면
//    「비밀번호를 다시 넣은 로그인」과 「아무것도 묻지 않은 로그인」을 구분할 수 없다.
//
//    발급 경로는 code 행이 싣고 온다(auth_codes.issued_via). 발급과 소모가 다른 요청이라
//    다른 방법이 없다. `login_logs.result` 는 VARCHAR(30) 이고 'success_silent' 는 14자다.
//
//    **값을 읽는 쪽을 같은 라운드에 함께 고쳤다**(D-172 생산자/소비자 쌍):
//      server/api/admin/logs.php    `$results` 화이트리스트 + summary 키
//      server/api/me/login_logs.php v_enum 화이트리스트
//      schema.sql · 마이그레이션 0004 의 COLUMN COMMENT
//    화면의 한글 라벨은 frontend 담당 몫이다 — 없으면 「알 수 없음」으로 보인다.
//    **판정은 호출 바깥에서 한다.** tests/lib/checks.php 의 `ck_result_values_server()` 는
//    `log_login()` 의 **첫 인자에 등장하는 모든 소문자 문자열 리터럴**을 result 값으로
//    수집한다(삼항 분기를 잡기 위해 일부러 그렇게 돼 있다). 비교식을 인자 안에 두면
//    `'password'` · `'session'` · `'issued_via'` 까지 result 값으로 읽혀
//    「화면에 라벨이 없다」가 셋 더 뜬다 — 실측으로 확인했다.
//    인자에는 **실제 result 값 두 개만** 남긴다.
$viaSilent = (isset($result['issued_via']) ? (string) $result['issued_via'] : 'password') === 'session';
log_login($viaSilent ? 'success_silent' : 'success', (int) $user['id'], (string) $user['username'], $serviceId);

// v1.8.6 — 별칭 사용 기록은 **secret 을 검증한 교환만** 센다(설계 104 C-1). public 클라이언트는 세지 않는다.
if (!empty($service['is_confidential'])) {
    service_alias_touch((string) $service['service_id']);
}
// v1.8.6 — 응답의 service_id 는 클라이언트가 보낸 값을 되돌린다(설계 104 C-2). DB·로그는 새 id.
$userOut = serialize_user($user, $us);
$sessOut = serialize_session($issued['row'], true);
service_id_echo($userOut, $issued['row']);
service_id_echo($sessOut, $issued['row']);

features_emit((string) $service['service_id'], $us, $user);   // v1.9.0 — features 순수 추가(설계 116 §7)
access_emit((string) $service['service_id'], $user, $us);      // v1.10.0 — access_level · access 순수 추가(설계 127 §3-1)
json_out([
    'user'               => $userOut,
    'session'            => $sessOut,
    'access_token'       => $issued['access_token'],
    'refresh_token'      => $issued['refresh_token'],
    'token_type'         => 'Bearer',
    'expires_in'         => $issued['expires_in'],
    'refresh_expires_in' => $issued['refresh_expires_in'],
    'state'              => $result['state'] !== null ? (string) $result['state'] : null,
    // ───────────────────────────────────────────────────────────────
    // v1.7.0 수정 라운드 — **선택 필드 추가** (감사 M17-4 / 계약 §4-9).
    //
    // 이 code 가 **비밀번호를 받고** 나왔는가(`'password'`), 기존 **쿠키 세션**에서
    // 조용히 나왔는가(`'session'`). 키 **추가**이므로 기존 연동 앱은 깨지지 않는다.
    //
    // 왜 필요한가 — 서비스가 민감한 조작 앞에서 재인증(step-up)을 요구하려면
    // `…/login.html?…&prompt=login` 을 보낸다. 그런데 **서버는 `prompt` 를 읽지 않고**
    // 그 처리는 `login.html` 의 JS 한 줄이다(D-254). URL 에서 `prompt=login` 만 빼면
    // 무음 code 가 그대로 나간다. 그 상태에서 서비스는 **재인증이 실제로 일어났는지
    // 알 수 있는 필드를 하나도 받지 못했다** — 보증이 없는데 있는 것처럼 보였다.
    //
    // **연동 앱 쪽 규약(AI_INTEGRATION_GUIDE.md / integrator 담당):**
    //   재인증이 필요하면 `prompt=login` 을 보내고 **`issued_via === 'password'` 를
    //   확인하라. 확인하지 않으면 보증이 없다.**
    //
    // 서버가 `prompt` 를 직접 처리하는 것(= `prompt=login` 이면 무음 거절)은
    // 계약 변경이라 다음 라운드 몫이다. 이 한 키만으로도 **앱이 스스로 거절**할 수 있다.
    // 값의 출처는 `auth_codes.issued_via` 이고 0004 미적용 설치본에서는 `'password'` 다.
    // ───────────────────────────────────────────────────────────────
    'issued_via'         => isset($result['issued_via']) ? (string) $result['issued_via'] : 'password',
    'payload'            => json_object(build_payload((string) $service['service_id'], $grades, $us,
                                ['source' => 'exchange', 'session' => $issued['row']])),
    // v1.8.4 — 「어느 로컬 계정인가」만 말한다. 연결 ≠ 접근 권한(설계 E절).
    // 트랜잭션 **밖**, 성공 응답 직전에 읽는다(읽기 전용 — 위 롤백 규약과 무관).
    'account_link'       => account_link_for((int) $user['id'], (string) $service['service_id']),
]);
