<?php
declare(strict_types=1);
/**
 * POST /server/api/sso/users.php — 계정 목록 (s2s, can_list_users=1 서비스만) (v1.9.0)
 * 담당 계약: `_workspace/80_architect_v18_access_spec.md` §1-2 · §6-2 · §6-5 · 판정 D-350(키) · 설계 116 §7
 * 응답 최상위 키: items, total, page, per_page   (items[]: uuid, username, name, role, role_rank, status)
 *
 * 권한은 **행의 플래그**(services.can_list_users)로 판정한다 — id 문자열 비교가 아니다(재등록하면 0).
 * 403 은 **인증 뒤**(인증 전 403 은 「누가 목록 권한을 갖는가」를 익명에게 알려준다).
 * 호출마다 감사 행(action sso.users.list, changes {count}) — **기록 실패면 500**(80 판정 4 권고).
 * 빈도: IP 300/300(인증 전) · 서비스 60/300(인증 뒤).
 */
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/accountlink.php';

only_method('POST');
rate_limit('svcapi:ip:' . client_ip(), 300, 300);
$in = body_json();
$page = v_int($in, 'page', 1, PAGE_MAX, false, 1);
$per  = v_int($in, 'per_page', 1, 100, false, 50);
$svc = al_s2s_auth($in);
$sid = (string) $svc['service_id'];
rate_limit('svcapi:users:' . $sid, 60, 300);
if (empty($svc['can_list_users'])) {
    throw new AppError('FORBIDDEN', 403, ['message' => '이 서비스는 계정 목록을 조회할 수 없습니다.']);
}

// v1.10.0 (설계 127 §6-2) — 비상 계정은 목록에서 빠진다
$total = (int) db_val('SELECT COUNT(*) FROM ' . T_USERS . ' WHERE account_kind <> ?', ['emergency']);
$rows = db_all('SELECT id, uuid, username, name, role, status FROM ' . T_USERS . ' WHERE account_kind <> ? ORDER BY id ASC LIMIT '
    . (int) $per . ' OFFSET ' . (int) (($page - 1) * $per), ['emergency']);
$items = [];
foreach ($rows as $u) {
    $role = global_role_of($u);
    $items[] = [
        'uuid'      => (string) $u['uuid'],
        'username'  => (string) $u['username'],
        'name'      => ($u['name'] ?? null) === null || (string) $u['name'] === '' ? null : (string) $u['name'],
        'role'      => $role,
        'role_rank' => rank_of(ROLE_RANK, $role),
        'status'    => (string) $u['status'],
    ];
}

// 감사 — 실패면 500(목록이 나갔는데 기록이 없는 상태를 만들지 않는다). 체인 키가 없어도 500(D-53).
$row = audit_ulogin_row('service', null, 'sso.users.list', 'service', $sid, ['count' => ['before' => null, 'after' => count($items)]],
    'page ' . $page . ' per_page ' . $per);
$row['actor_local'] = $sid;
audit_append([$row]);
plat_usage_add($sid, 'sso_users_subjects', count($items), true);

json_out([
    'items'    => $items,
    'total'    => $total,
    'page'     => (int) $page,
    'per_page' => (int) $per,
]);
