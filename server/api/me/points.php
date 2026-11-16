<?php
declare(strict_types=1);
/**
 * GET /api/me/points — 포인트 잔액 + 이력
 * 담당 계약: 01_architect_api_contract.md §6-9, 리더 판정 D-4
 * 응답 최상위 키: points, total_points_earned, total_points_spent,
 *                 items, total, page, per_page
 *
 * 잔액 3종을 목록과 같은 응답에 담는다 — 마이페이지 포인트 탭이 요청을
 * 두 번 하지 않게 하기 위함이다.
 *
 * admin_id 는 본인 응답에 포함하지 않는다 — 누가 조정했는지는 관리자 정보다.
 * idempotency_key 도 내보내지 않는다(다른 클라이언트의 재시도 키다).
 * 잔액은 전역 포인트(users.points)만 다룬다 (D-4).
 */
require_once __DIR__ . '/../../core/bootstrap.php';

only_method('GET');

$ctx    = require_auth();
$userId = (int) $ctx['user']['id'];

list($page, $perPage, $offset) = page_params($_GET);

$reason    = v_string($_GET, 'reason', 1, 100);
$serviceId = v_service_id($_GET, 'service_id');   // v1.8.6 — 옛 id 필터도 새 id 행을 찾는다(설계 104 C-1)

$where  = ' WHERE user_id = ?';
$params = [$userId];
if ($reason !== null) {
    $where   .= ' AND reason = ?';
    $params[] = $reason;
}
if ($serviceId !== null) {
    $where   .= ' AND service_id = ?';
    $params[] = $serviceId;
}

$total = (int) db_val('SELECT COUNT(*) FROM ' . T_POINT_LOGS . $where, $params);

$rows = db_all(
    'SELECT id, delta, balance_after, reason, service_id, ref_type, ref_id, memo, created_at'
    . ' FROM ' . T_POINT_LOGS . $where
    . ' ORDER BY created_at DESC, id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
    $params
);

// v1.10.1 — 행마다 service_name(null 가능) 순수 추가(설계 130 §4-6). 서비스 이름표를 한 번에 읽는다.
$svcNames = [];
foreach (db_all('SELECT service_id, service_name FROM ' . T_SERVICES) as $s) {
    $svcNames[(string) $s['service_id']] = (string) $s['service_name'];
}

$items = [];
foreach ($rows as $r) {
    $items[] = [
        'id'            => (int) $r['id'],
        'delta'         => (int) $r['delta'],
        'balance_after' => (int) $r['balance_after'],
        'reason'        => (string) $r['reason'],
        'service_id'    => $r['service_id'] !== null ? (string) $r['service_id'] : null,
        'service_name'  => ($r['service_id'] !== null && isset($svcNames[(string) $r['service_id']]))
            ? $svcNames[(string) $r['service_id']] : null,
        'ref_type'      => $r['ref_type'] !== null ? (string) $r['ref_type'] : null,
        'ref_id'        => $r['ref_id'] !== null ? (string) $r['ref_id'] : null,
        'memo'          => $r['memo'] !== null ? (string) $r['memo'] : null,
        'created_at'    => iso($r['created_at']),
    ];
}

// 잔액은 세션 시작 시점 값이 아니라 지금 값을 다시 읽는다.
// consume 직후 호출될 수 있으므로 require_auth 가 실어 준 행을 재사용하지 않는다.
$bal = db_one('SELECT points, total_points_earned, total_points_spent FROM ' . T_USERS . ' WHERE id = ?', [$userId]);

json_out([
    'points'              => (int) ($bal['points'] ?? 0),
    'total_points_earned' => (int) ($bal['total_points_earned'] ?? 0),
    'total_points_spent'  => (int) ($bal['total_points_spent'] ?? 0),
    'items'               => $items,
    'total'               => $total,
    'page'                => $page,
    'per_page'            => $perPage,
]);
