<?php
declare(strict_types=1);
/**
 * GET /api/auth/session — 세션 유효성 확인 + payload 재발급
 * 담당 계약: 01_architect_api_contract.md §4-5
 * 응답 최상위 키: user, session, expires_in, payload
 *
 * expires_in 은 발급 시 TTL 이 아니라 **남은 초**다. 앱이 이 값으로 갱신 타이머를 건다.
 */
require_once __DIR__ . '/../../core/bootstrap.php';

only_method('GET');

$ctx = require_auth();

$expTs     = ts($ctx['session']['expires_at']);
$expiresIn = $expTs === null ? 0 : max(0, $expTs - time());

json_out([
    'user'       => serialize_user($ctx['user'], $ctx['user_service']),
    'session'    => serialize_session($ctx['session'], true),
    'expires_in' => $expiresIn,
    // build_payload() 가 services.status 를 직접 확인한다 (D-152). 이 파일은 소유자가
    // 없는 얇은 엔드포인트라, 판정을 여기 두면 다음 수정에서 빠진다.
    'payload'    => json_object(build_payload($ctx['session']['service_id'] ?? null, $ctx['grades'],
                        $ctx['user_service'], ['source' => 'session', 'session' => $ctx['session']])),
]);
