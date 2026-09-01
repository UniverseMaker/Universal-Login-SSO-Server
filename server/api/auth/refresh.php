<?php
declare(strict_types=1);
/**
 * POST /api/auth/refresh — refresh 토큰 회전
 * 담당 계약: 01_architect_api_contract.md §4-4, 스펙 §5-2
 * 응답 최상위 키: user, session, access_token, refresh_token, token_type,
 *                 expires_in, refresh_expires_in, payload
 *
 * 사용된 refresh 는 즉시 폐기되고 session_id 도 새 값이 된다.
 * payload 는 재계산한다 — 등급이 바뀌었거나 시크릿이 교체됐을 수 있고,
 * 그러면 로컬 앱이 폐기된 API 키를 계속 쓰게 된다.
 */
require_once __DIR__ . '/../../core/bootstrap.php';

only_method('POST');

// ---------------------------------------------------------------------
// 전용 레이트리밋 — 보안 재검증 V14b-H-1 (조사 A-5 의 후반부)
//
// 이 엔드포인트에는 상한이 **전역 `api:ip` 300/60 뿐**이었다. 그런데 회전은 매번
// 새 `session_id` 를 만들고, `secret_access_logs` 의 시간 버킷 UNIQUE 키가
// `(session_id, source, window_start)` 다 — 즉 **회전은 절대 접히지 않는다.**
// 실측: 유효 계정 하나로 refresh 150회 → `secret_access_logs` **151행**(요청과 1:1),
// `sessions` 도 같은 비율로 늘었다. 전역 상한만으로는 IP 하나당 하루 43만 행이고
// `gc_expired()` 는 그 표를 치우지 않는다.
//
// 정상 클라이언트는 access_token 이 만료될 때 한 번 회전한다(`SESSION_TTL` 기본 3600초).
// 두 버킷을 쓴다 — 어느 하나만으로는 부족하다.
//   refresh:ip   폭주 억제. 지속 2회/초. NAT·CDN 뒤의 정상 배포를 막지 않으려면
//                넉넉해야 한다(`TRUST_PROXY` 를 안 켠 배포에서는 모든 사용자가 프록시
//                IP 하나로 보인다). 전역 `api:ip` 300/60 보다 이 경로에서만 2.5배 좁다.
//   refresh:user 행 증가를 실제로 묶는 쪽. 행은 세션 단위이고 세션은 사용자 단위다.
//                60회/시간 = 정상의 60배 여유. 사용자당 하루 1,440행 상한.
// 사용자 버킷은 토큰을 검증한 **뒤에야** 걸 수 있으므로 core/auth.php 의
// rotate_refresh() 안에 있다 — 그 함수의 해당 주석에 근거가 있다.
//
// **1:1 비율 자체는 이 수정으로 사라지지 않는다.** 회전마다 새 session_id 가 나오는
// 구조를 고치려면 회전에 걸쳐 살아남는 계보 키(`sessions.root_session_id`)가 필요하고
// 그것은 마이그레이션 대상이다(V14-M-3, v1.5.0). 여기서 닫은 것은 **상한 없음**이다.
//
// 429 는 이 경로에서 새 상태가 아니다(전역 `api:ip` 가 이미 낼 수 있었다).
// 계약 §2 의 RATE_LIMITED 를 그대로 쓰고 응답 shape 도 바뀌지 않는다.
// ---------------------------------------------------------------------
rate_limit('refresh:ip:' . client_ip(), 120, 60);

$in       = body_json();
$refresh  = (string) v_string($in, 'refresh_token', 64, 64, true);
$devLabel = v_string($in, 'device_label', 1, 80);

$r = rotate_refresh($refresh, ['device_label' => $devLabel]);

json_out([
    'user'               => serialize_user($r['user'], $r['user_service']),
    'session'            => serialize_session($r['session'], true),
    'access_token'       => $r['access_token'],
    'refresh_token'      => $r['refresh_token'],
    'token_type'         => 'Bearer',
    'expires_in'         => $r['expires_in'],
    'refresh_expires_in' => $r['refresh_expires_in'],
    'payload'            => json_object(build_payload($r['session']['service_id'] ?? null, $r['grades'],
                                $r['user_service'], ['source' => 'refresh', 'session' => $r['session']])),
]);
