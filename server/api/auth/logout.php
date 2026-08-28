<?php
declare(strict_types=1);
/**
 * POST /api/auth/logout — 로그아웃
 * 담당 계약: 01_architect_api_contract.md §4-3
 * 응답 최상위 키: revoked_count, all_devices
 *
 * 이미 만료된 세션으로 호출하면 200 이 아니라 401 이다. 프론트는 401 도
 * 로그아웃 성공과 동일하게(로컬 토큰 삭제) 처리하면 된다.
 */
require_once __DIR__ . '/../../core/bootstrap.php';

only_method('POST');

$ctx = require_auth();
require_csrf();     // 쿠키 세션일 때만 실제로 검사한다. Bearer 는 면제.

$in  = body_json();
$all = (bool) v_bool($in, 'all_devices', false, false);

$userId    = (int) $ctx['user']['id'];
$sessionId = (int) $ctx['session']['id'];

if ($all) {
    $count = revoke_user_sessions($userId, 'logout_all');
} else {
    $count = revoke_session($sessionId, 'logout');
}

clear_session_cookie();
log_login('logout', $userId, (string) $ctx['user']['username'], $ctx['session']['service_id'] ?? null);

json_out([
    'revoked_count' => $count,
    'all_devices'   => $all,
]);
