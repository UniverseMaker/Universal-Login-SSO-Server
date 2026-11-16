<?php
declare(strict_types=1);
/**
 * POST /server/api/points/s2s_history.php — 공동 포인트 내역 (서버 간, v1.10.1)
 * 담당 계약: 설계 130 §4-1 · §4-2 · §4-6
 * 요청: {service_id, service_secret, uuid, before_id?: int|null, limit?: 1~100 (기본 50)}
 * 응답 최상위 키: items[{log_id, delta, balance_after, reason, ref_type, ref_id, memo, created_at}], next_before_id
 * **호출 서비스의 행만** 준다(service_id = 호출 서비스). 다른 시스템의 사용 내역을 서비스에 보여 주지 않는다.
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

$limit    = (int) v_int($in, 'limit', 1, 100, false, 50);
$beforeId = ($in['before_id'] ?? null) === null ? null : (int) v_int($in, 'before_id', 1, PHP_INT_MAX, true);

list($user, $cfg) = points_s2s_target($svc, $uuid, 'history');
$sql = 'SELECT id, delta, balance_after, reason, ref_type, ref_id, memo, created_at FROM ' . T_POINT_LOGS
    . ' WHERE user_id = ? AND service_id = ?';
$p   = [(int) $user['id'], $sid];
if ($beforeId !== null) {
    $sql .= ' AND id < ?';
    $p[]  = $beforeId;
}
$rows = db_all($sql . ' ORDER BY id DESC LIMIT ' . ($limit + 1), $p);
$more = count($rows) > $limit;
$rows = array_slice($rows, 0, $limit);
$items = [];
foreach ($rows as $r) {
    $items[] = [
        'log_id'        => (int) $r['id'],
        'delta'         => (int) $r['delta'],
        'balance_after' => (int) $r['balance_after'],
        'reason'        => (string) $r['reason'],
        'ref_type'      => $r['ref_type'] !== null ? (string) $r['ref_type'] : null,
        'ref_id'        => $r['ref_id'] !== null ? (string) $r['ref_id'] : null,
        'memo'          => $r['memo'] !== null ? (string) $r['memo'] : null,
        'created_at'    => iso($r['created_at']),
    ];
}
plat_usage_add($sid, 'points_history', 1, true);
json_out([
    'items'          => $items,
    'next_before_id' => ($more && $items !== []) ? $items[count($items) - 1]['log_id'] : null,
]);
