<?php
declare(strict_types=1);
/**
 * POST /server/api/points/s2s_balance.php — 공동 포인트 잔액·남은 한도 (서버 간, v1.10.1)
 * 담당 계약: 설계 130 §4-1 · §4-2 · §4-6
 * 요청: {service_id, service_secret, uuid}
 * 응답 최상위 키: uuid, points, limits{per_tx_max, earn_remaining_24h, spend_remaining_24h, earn, spend}
 * 대상 판정은 earn·spend 와 같은 points_s2s_target()(op='balance' — earn/spend 꺼짐은 거절 사유가 아니다).
 */
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/accountlink.php';
require_once __DIR__ . '/../../core/points.php';

only_method('POST');
rate_limit('svcapi:ip:' . client_ip(), 300, 300);
$in   = body_json();
$uuid = points_s2s_uuid($in);
$svc  = al_s2s_auth($in);
$sid  = (string) $svc['service_id'];
rate_limit('svcapi:points:' . $sid, 600, 300);

list($user, $cfg) = points_s2s_target($svc, $uuid, 'balance');
plat_usage_add($sid, 'points_balance', 1, true);
json_out([
    'uuid'   => (string) $user['uuid'],
    'points' => (int) $user['points'],
    'limits' => points_s2s_limits_view($cfg, $sid, (int) $user['id']),
]);
