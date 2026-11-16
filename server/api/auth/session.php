<?php
declare(strict_types=1);
/**
 * GET /api/auth/session — 세션 유효성 확인 + payload 재발급
 * 담당 계약: 01_architect_api_contract.md §4-5
 * 응답 최상위 키: user, session, expires_in, payload, account_link
 *   account_link (v1.8.4, 설계 92 S-5 — 순수 추가): 이 세션의 서비스에서 연결된 로컬 계정 객체 | null.
 *   서비스 없는 세션(ulogin 자체 쿠키 세션)은 언제나 null 이다.
 *
 * expires_in 은 발급 시 TTL 이 아니라 **남은 초**다. 앱이 이 값으로 갱신 타이머를 건다.
 */
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/accountlink.php';

only_method('GET');

$ctx = require_auth();

// v1.9.0 서비스 접근 규칙(80 §5-3 집행 4번) — 권한을 빼면 그쪽 시스템은 **다음 session 호출에서 403** 을 받는다.
// 서비스 없는 세션(ulogin 자체 화면)은 판정하지 않는다. 비활성 서비스는 payload 가 이미 {} 다(D-152).
if (!empty($ctx['session']['service_id'])) {
    $accSvc = service_load((string) $ctx['session']['service_id']);
    if ($accSvc !== null) {
        require_service_access($ctx['user'], $ctx['user_service'], $accSvc, 'session');
    }
}

$expTs     = ts($ctx['session']['expires_at']);
$expiresIn = $expTs === null ? 0 : max(0, $expTs - time());

// v1.8.6 — 이 세션이 옛 id(별칭)로 발급됐으면 응답도 그 값을 되돌린다(설계 104 C-2).
$userOut = serialize_user($ctx['user'], $ctx['user_service']);
$sessOut = serialize_session($ctx['session'], true);
service_id_echo($userOut, $ctx['session']);
service_id_echo($sessOut, $ctx['session']);

features_emit(isset($ctx['session']['service_id']) ? (string) $ctx['session']['service_id'] : null, $ctx['user_service'], $ctx['user']);
// v1.10.0 — access_level · access 순수 추가(설계 127 §3-1). 서비스 없는 세션에는 붙지 않는다.
access_emit(isset($ctx['session']['service_id']) ? (string) $ctx['session']['service_id'] : null, $ctx['user'], $ctx['user_service']);
json_out([
    'user'       => $userOut,
    'session'    => $sessOut,
    'expires_in' => $expiresIn,
    // build_payload() 가 services.status 를 직접 확인한다 (D-152). 이 파일은 소유자가
    // 없는 얇은 엔드포인트라, 판정을 여기 두면 다음 수정에서 빠진다.
    'payload'    => json_object(build_payload($ctx['session']['service_id'] ?? null, $ctx['grades'],
                        $ctx['user_service'], ['source' => 'session', 'session' => $ctx['session']])),
    'account_link' => account_link_for((int) $ctx['user']['id'],
                        isset($ctx['session']['service_id']) ? (string) $ctx['session']['service_id'] : null),
]);
