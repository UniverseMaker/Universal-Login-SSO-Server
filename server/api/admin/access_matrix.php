<?php
declare(strict_types=1);
/**
 * GET /server/api/admin/access_matrix.php — 계정 × 서비스 단계 행렬 (v1.10.0)
 * 담당 계약: `_workspace/127_architect_v1100_design.md` §3-5 · §5 · 판정 D-367
 *
 * 쿠키 세션 + 전역 role admin 이상. 서비스 토큰은 403(H186-1).
 * ?page&per_page≤50&q&group_key&service_id
 *   → services[{service_id,service_name,access_rule,level_aware}],
 *     items[{uuid,username,name,status,is_emergency,cells{sid:{level,source,until,override:"allow"|"deny"|null}}}],
 *     total, page, per_page
 * 셀은 **서버가 판정한 값**(access_level_eval — 단건 GET service_access ?uuid 와 같은 함수)이다. 화면은 다시 계산하지 않는다.
 * 쿼리는 고정 개수: 서비스 · 계정 페이지(+COUNT) · user_services IN · 그룹 행 IN(구성원⋈그룹⋈그룹서비스 한 번).
 */
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/accessadmin.php';

only_method('GET');
$ctx = require_auth();
require_global_role($ctx, 'admin');
acc_require_schema();

list($pg, $per, $off) = page_params($_GET, 50);
$per = min(50, $per);
$off = ($pg - 1) * $per;

// 1) 서비스(열)
$svcSql = 'SELECT * FROM ' . T_SERVICES;
$svcP = [];
if (isset($_GET['service_id']) && $_GET['service_id'] !== '') {
    $svcSql .= ' WHERE service_id = ?';
    $svcP[] = (string) $_GET['service_id'];
}
$services = db_all($svcSql . ' ORDER BY service_id ASC', $svcP);

// 2) 계정(행) — q(아이디·이름 부분 일치), group_key(구성원만)
$w = [];
$p = [];
if (isset($_GET['q']) && is_string($_GET['q']) && trim($_GET['q']) !== '') {
    $q = mb_substr(trim($_GET['q']), 0, 50, 'UTF-8');
    $w[] = '(u.username LIKE ?' . sql_like_escape() . ' OR u.name LIKE ?' . sql_like_escape() . ')';
    $p[] = like_contains($q);
    $p[] = like_contains($q);
}
if (isset($_GET['group_key']) && $_GET['group_key'] !== '') {
    $g = acc_group($_GET['group_key']);
    $w[] = 'u.id IN (SELECT user_id FROM ' . T_ACCESS_GROUP_MEMBERS . ' WHERE group_id = ?)';
    $p[] = (int) $g['id'];
}
$where = $w === [] ? '' : ' WHERE ' . implode(' AND ', $w);
$total = (int) db_val('SELECT COUNT(*) FROM ' . T_USERS . ' u' . $where, $p);
$users = db_all('SELECT u.* FROM ' . T_USERS . ' u' . $where . ' ORDER BY u.username ASC LIMIT ' . (int) $per . ' OFFSET ' . (int) $off, $p);

// 3) user_services IN · 4) 그룹 행 IN
$ids = array_map('intval', array_column($users, 'id'));
$usAll = [];
if ($ids !== []) {
    foreach (db_all('SELECT * FROM ' . T_USER_SERVICES . ' WHERE user_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $r) {
        $usAll[(int) $r['user_id']][(string) $r['service_id']] = $r;
    }
}
$grp = access_group_rows($ids);

$items = [];
foreach ($users as $u) {
    $uid = (int) $u['id'];
    $cells = [];
    foreach ($services as $s) {
        $sid = (string) $s['service_id'];
        $us = $usAll[$uid][$sid] ?? null;
        $d = access_level_eval($u, $s, $us, $grp[$uid][$sid] ?? []);
        $g = $us === null ? 'inherit' : (string) ($us['access_grant'] ?? 'inherit');
        $cells[$sid] = ['level' => $d['level'], 'source' => $d['source'], 'until' => $d['until'],
            'override' => ($g === 'allow' || $g === 'deny') ? $g : null];
    }
    $items[] = [
        'uuid'         => (string) $u['uuid'],
        'username'     => (string) $u['username'],
        'name'         => ($u['name'] ?? null) === null || (string) $u['name'] === '' ? null : (string) $u['name'],
        'status'       => (string) $u['status'],
        'is_emergency' => is_emergency_user($u),
        'cells'        => json_object($cells),
    ];
}
$svcOut = [];
foreach ($services as $s) {
    $svcOut[] = ['service_id' => (string) $s['service_id'], 'service_name' => (string) $s['service_name'],
        'access_rule' => (string) ($s['access_rule'] ?? 'all'), 'level_aware' => (int) ($s['level_aware'] ?? 0) === 1];
}
json_out(['services' => $svcOut, 'items' => $items, 'total' => $total, 'page' => (int) $pg, 'per_page' => (int) $per]);
