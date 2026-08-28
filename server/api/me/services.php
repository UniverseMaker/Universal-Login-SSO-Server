<?php
declare(strict_types=1);
/**
 * GET /api/me/services — 내가 가입한 서비스 + 서비스별 유효 등급
 * 담당 계약: 01_architect_api_contract.md §6-8, 리더 판정 D-4
 * 응답 최상위 키: items, total, page, per_page
 *
 * 등급은 전부 effective 값이다 — 전역값 ← 서비스 오버라이드 ← 결제 만료 강등.
 * 반드시 effective_grades() 를 거친다. 컬럼을 직접 읽어 비교하면
 * 오버라이드와 만료가 누락된다.
 *
 * **service_points 는 응답에 포함하지 않는다** (리더 판정 D-4).
 * 계약 §6-8 예시에는 `"service_points": 0` 이 있으나, D-4 는 v1.0.0 에서
 * 서비스별 포인트를 "API·UI에서 노출하지 않는다 / me/services 응답에도 포함하지 않는다"
 * 로 확정했고 D-4 가 계약보다 우선한다. 잔액이 두 개로 보이면 사용자가 혼동한다.
 */
require_once __DIR__ . '/../../core/bootstrap.php';

only_method('GET');

$ctx  = require_auth();
$user = $ctx['user'];

list($page, $perPage, $offset) = page_params($_GET);

$total = (int) db_val('SELECT COUNT(*) FROM ' . T_USER_SERVICES . ' WHERE user_id = ?', [(int) $user['id']]);

// 서비스가 삭제된 매핑도 보여준다(LEFT JOIN). 사용자에게는 "가입 이력"이 사실이다.
$rows = db_all(
    'SELECT us.*, s.service_name, s.description, s.status AS service_status'
    . ' FROM ' . T_USER_SERVICES . ' us'
    . ' LEFT JOIN ' . T_SERVICES . ' s ON s.service_id = us.service_id'
    . ' WHERE us.user_id = ?'
    . ' ORDER BY us.last_used_at IS NULL, us.last_used_at DESC, us.joined_at DESC, us.id DESC'
    . ' LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
    [(int) $user['id']]
);

$items = [];
foreach ($rows as $r) {
    // 이 행의 서비스 컨텍스트에서의 유효 등급
    $g = effective_grades($user, $r);

    $items[] = [
        'service_id'         => (string) $r['service_id'],
        'service_name'       => $r['service_name'] !== null ? (string) $r['service_name'] : null,
        'description'        => $r['description'] !== null ? (string) $r['description'] : null,
        // 서비스 자체의 상태 (active|disabled). 서비스가 지워졌으면 null.
        'status'             => $r['service_status'] !== null ? (string) $r['service_status'] : null,
        // 이 사용자의 서비스 가입 상태 (active|blocked)
        'membership_status'   => (string) $r['status'],
        'account_level'      => $g['account_level'],
        'account_level_rank' => $g['account_level_rank'],
        'role'               => $g['role'],
        'role_rank'          => $g['role_rank'],
        'billing_tier'       => $g['billing_tier'],
        'billing_tier_rank'  => $g['billing_tier_rank'],
        'billing_expires_at' => iso($g['billing_expires_at']),
        'billing_active'     => (bool) $g['billing_active'],
        // service_points 는 D-4 에 따라 의도적으로 제외했다
        'joined_at'          => iso($r['joined_at']),
        'last_used_at'       => iso($r['last_used_at']),
    ];
}

json_out([
    'items'    => $items,
    'total'    => $total,
    'page'     => $page,
    'per_page' => $perPage,
]);
