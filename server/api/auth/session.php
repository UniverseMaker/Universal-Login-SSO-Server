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
    'payload'    => json_object(build_payload($ctx['session']['service_id'] ?? null, $ctx['grades'])),
]);
