<?php
declare(strict_types=1);
/**
 * POST /api/auth/exchange — SSO code 를 토큰 + 시크릿으로 교환
 * 담당 계약: 01_architect_api_contract.md §4-9, 스펙 §4-2, §4-3
 * 응답 최상위 키: user, session, access_token, refresh_token, token_type,
 *                 expires_in, refresh_expires_in, state, payload
 *
 * 이 시스템의 정규 경로다. 시크릿은 URL 이 아니라 이 POST 응답 본문으로만 나간다.
 *
 * code 실패 사유(없음/만료/재사용/service_id 불일치/redirect_uri 불일치/PKCE 불일치)는
 * **전부 SSO_CODE_INVALID 하나로** 응답한다. 구분하면 공격자에게 어느 단계까지
 * 성공했는지 알려주는 오라클이 된다.
 */
require_once __DIR__ . '/../../core/bootstrap.php';

only_method('POST');

rate_limit_logged('exchange:ip:' . client_ip(), 30, 300);

$in          = body_json();
$code        = (string) v_string($in, 'code', 1, 128, true);
$serviceId   = (string) v_string($in, 'service_id', 1, 64, true);
$redirectUri = (string) v_string($in, 'redirect_uri', 1, 500, true);
$verifier    = v_string($in, 'code_verifier', 1, 200);
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
    // 3) code 를 잠근 채로 읽는다. FOR UPDATE 가 없으면 동시 요청이 같은 code 를 두 번 쓴다.
    $row = db_one('SELECT * FROM ' . T_AUTH_CODES . ' WHERE code_hash = ? FOR UPDATE', [token_hash($code)]);

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

    $us     = ensure_user_service((int) $user['id'], $service);
    $issued = create_session($user, $service, 'token', [
        'device_label' => $devLabel,
        'with_refresh' => true,
        'set_cookie'   => false,   // 로컬 앱은 Bearer 를 쓴다. 쿠키를 심지 않는다.
    ]);

    return [
        'user'         => $user,
        'user_service' => $us,
        'issued'       => $issued,
        'state'        => $row['state'],
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

$user   = $result['user'];
$us     = $result['user_service'];
$issued = $result['issued'];
$grades = effective_grades($user, $us);

// 6) 로그인 이력에도 남긴다 — SSO 도 로그인이다
log_login('success', (int) $user['id'], (string) $user['username'], $serviceId);

json_out([
    'user'               => serialize_user($user, $us),
    'session'            => serialize_session($issued['row'], true),
    'access_token'       => $issued['access_token'],
    'refresh_token'      => $issued['refresh_token'],
    'token_type'         => 'Bearer',
    'expires_in'         => $issued['expires_in'],
    'refresh_expires_in' => $issued['refresh_expires_in'],
    'state'              => $result['state'] !== null ? (string) $result['state'] : null,
    'payload'            => json_object(build_payload((string) $service['service_id'], $grades, $us,
                                ['source' => 'exchange', 'session' => $issued['row']])),
]);
