<?php
declare(strict_types=1);
/**
 * GET /api/admin/logs.php?type=login|admin — 로그 조회
 * 담당 계약: §8-20(로그인 로그) · §8-21(관리자 조작 로그)
 * 리더 판정 D-0: 두 논리 경로를 `?type=` 으로 구분한다.
 * 최소 역할: type=login → manager / type=admin → admin
 *
 * 응답 최상위 키
 *   type=login : items, total, page, per_page, summary
 *   type=admin : items, total, page, per_page
 *
 * 조회이므로 admin_logs 에 기록하지 않는다(계약 §8 공통 — secret.reveal 만 예외).
 */
require_once __DIR__ . '/../../core/bootstrap.php';

only_method('GET');

if (!function_exists('admin_parse_dt')) {
    /**
     * ISO8601 / 'YYYY-MM-DD HH:MM:SS' / 'YYYY-MM-DD' → UTC DATETIME.
     * @param mixed $v
     */
    function admin_parse_dt($v, string $field, bool $endOfDay = false): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_string($v)) {
            v_fail($field, '날짜 형식이 올바르지 않습니다.');
        }
        $s = trim($v);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1) {
            return $s . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
        }
        $t = strtotime(str_replace('Z', ' UTC', $s));
        if ($t === false) {
            v_fail($field, '날짜 형식이 올바르지 않습니다.');
        }
        return gmdate('Y-m-d H:i:s', (int) $t);
    }
}

$ctx  = require_auth();
$type = (string) v_enum($_GET, 'type', ['login', 'admin'], false, 'login');

$fromAt = admin_parse_dt(in_raw($_GET, 'from'), 'from', false);
$toAt   = admin_parse_dt(in_raw($_GET, 'to'), 'to', true);
list($page, $perPage, $offset) = page_params($_GET, 20);

$uuidRe = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

// =====================================================================
// type=login — 계약 §8-20 (manager)
// =====================================================================
if ($type === 'login') {
    require_global_role($ctx, 'manager');

    $results = ['success', 'fail_credential', 'fail_locked', 'fail_status', 'fail_ratelimit', 'fail_service_blocked', 'logout'];

    $q         = v_string($_GET, 'q', 1, 190, false);
    $serviceId = v_string($_GET, 'service_id', 1, 64, false);
    $ip        = v_string($_GET, 'ip', 1, 45, false);
    $uuid      = v_string($_GET, 'uuid', 1, 64, false);

    $resultRaw = in_raw($_GET, 'result');
    $result    = null;
    if ($resultRaw !== null && $resultRaw !== '') {
        if (!is_string($resultRaw) || !in_array($resultRaw, $results, true)) {
            throw new AppError('VALIDATION_FAILED', 400, [
                'field' => 'result', 'message' => 'result 값이 올바르지 않습니다.',
            ]);
        }
        $result = $resultRaw;
    }

    $where  = ['1 = 1'];
    $params = [];

    if ($uuid !== null) {
        if (preg_match($uuidRe, $uuid) !== 1) {
            v_fail('uuid', '회원 식별자 형식이 올바르지 않습니다.');
        }
        $targetId = db_val('SELECT id FROM ' . T_USERS . ' WHERE uuid = ?', [$uuid]);
        if ($targetId === null) {
            throw new AppError('NOT_FOUND', 404, ['message' => '회원을 찾을 수 없습니다.']);
        }
        $where[]  = 'l.user_id = ?';
        $params[] = (int) $targetId;
    }
    if ($q !== null) {
        $like     = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $where[]  = '(l.username_input LIKE ? OR u.username LIKE ?)';
        $params[] = $like;
        $params[] = $like;
    }
    if ($result !== null)    { $where[] = 'l.result = ?';     $params[] = $result; }
    if ($serviceId !== null) { $where[] = 'l.service_id = ?'; $params[] = $serviceId; }
    if ($ip !== null)        { $where[] = 'l.ip = ?';         $params[] = $ip; }
    if ($fromAt !== null)    { $where[] = 'l.created_at >= ?'; $params[] = $fromAt; }
    if ($toAt !== null)      { $where[] = 'l.created_at <= ?'; $params[] = $toAt; }
    $whereSql = implode(' AND ', $where);

    $joinSql = ' FROM ' . T_LOGIN_LOGS . ' l LEFT JOIN ' . T_USERS . ' u ON u.id = l.user_id WHERE ' . $whereSql;

    $total = (int) db_val('SELECT COUNT(*)' . $joinSql, $params);

    $rows = db_all(
        'SELECT l.id, l.username_input, l.service_id, l.result, l.ip, l.user_agent, l.created_at,'
        . ' u.uuid AS user_uuid, u.username'
        . $joinSql
        . ' ORDER BY l.id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
        $params
    );

    $items = [];
    foreach ($rows as $r) {
        // user_id 가 NULL 인 행은 존재하지 않는 계정에 대한 시도다 — username_input 만 채워진다
        $items[] = [
            'id'             => (int) $r['id'],
            'user_uuid'      => $r['user_uuid'] !== null ? (string) $r['user_uuid'] : null,
            'username'       => $r['username'] !== null ? (string) $r['username'] : null,
            'username_input' => (string) $r['username_input'],
            'service_id'     => $r['service_id'] !== null ? (string) $r['service_id'] : null,
            'result'         => (string) $r['result'],
            'ip'             => (string) $r['ip'],
            'user_agent'     => (string) $r['user_agent'],
            'created_at'     => iso($r['created_at']),
        ];
    }

    // summary — 같은 필터 조건의 result 별 집계 1회. 값이 0인 키도 포함한다.
    $summary = [];
    foreach ($results as $k) {
        $summary[$k] = 0;
    }
    foreach (db_all('SELECT l.result AS r, COUNT(*) AS c' . $joinSql . ' GROUP BY l.result', $params) as $s) {
        // 표에 없는 result 값이 나와도 버리지 않는다
        $summary[(string) $s['r']] = (int) $s['c'];
    }

    json_out([
        'items'    => $items,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'summary'  => $summary,
    ]);
}

// =====================================================================
// type=admin — 계약 §8-21 (admin)
// =====================================================================
if (!role_gte(ctx_global_role($ctx), 'admin')) {
    throw new AppError('FORBIDDEN', 403, ['message' => '관리자 조작 로그는 admin 이상만 조회할 수 있습니다.']);
}

$action     = v_string($_GET, 'action', 1, 60, false);
$targetType = v_string($_GET, 'target_type', 1, 40, false);
$targetId   = v_string($_GET, 'target_id', 1, 64, false);
$adminUuid  = v_string($_GET, 'admin_uuid', 1, 64, false);

$where  = ['1 = 1'];
$params = [];

if ($adminUuid !== null) {
    if (preg_match($uuidRe, $adminUuid) !== 1) {
        v_fail('admin_uuid', '관리자 식별자 형식이 올바르지 않습니다.');
    }
    $aid = db_val('SELECT id FROM ' . T_USERS . ' WHERE uuid = ?', [$adminUuid]);
    if ($aid === null) {
        throw new AppError('NOT_FOUND', 404, ['message' => '관리자를 찾을 수 없습니다.']);
    }
    $where[]  = 'a.admin_id = ?';
    $params[] = (int) $aid;
}
if ($action !== null)     { $where[] = 'a.action = ?';      $params[] = $action; }
if ($targetType !== null) { $where[] = 'a.target_type = ?'; $params[] = $targetType; }
if ($targetId !== null)   { $where[] = 'a.target_id = ?';   $params[] = $targetId; }
if ($fromAt !== null)     { $where[] = 'a.created_at >= ?'; $params[] = $fromAt; }
if ($toAt !== null)       { $where[] = 'a.created_at <= ?';  $params[] = $toAt; }
$whereSql = implode(' AND ', $where);

$joinSql = ' FROM ' . T_ADMIN_LOGS . ' a LEFT JOIN ' . T_USERS . ' u ON u.id = a.admin_id WHERE ' . $whereSql;

$total = (int) db_val('SELECT COUNT(*)' . $joinSql, $params);

$rows = db_all(
    'SELECT a.id, a.action, a.target_type, a.target_id, a.before_json, a.after_json, a.memo, a.ip, a.created_at,'
    . ' u.uuid AS admin_uuid, u.username AS admin_username'
    . $joinSql
    . ' ORDER BY a.id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
    $params
);

// ---------------------------------------------------------------------
// target_label 을 행마다 조회하면 N+1 이 된다.
// 현재 페이지의 대상 식별자만 타입별로 묶어 최대 3회로 해결한다.
// ---------------------------------------------------------------------
$userIds = [];
$svcIds  = [];
$secIds  = [];
foreach ($rows as $r) {
    $tid = $r['target_id'];
    if ($tid === null || $tid === '') {
        continue;
    }
    if ($r['target_type'] === 'user') {
        $userIds[(string) $tid] = true;
    } elseif ($r['target_type'] === 'service') {
        $svcIds[(string) $tid] = true;
    } elseif ($r['target_type'] === 'secret') {
        $secIds[(int) $tid] = true;
    }
}

$labelUser = [];
if ($userIds !== []) {
    $keys = array_keys($userIds);
    $ph   = implode(',', array_fill(0, count($keys), '?'));
    foreach (db_all('SELECT uuid, username FROM ' . T_USERS . ' WHERE uuid IN (' . $ph . ')', $keys) as $x) {
        $labelUser[(string) $x['uuid']] = (string) $x['username'];
    }
}
$labelSvc = [];
if ($svcIds !== []) {
    $keys = array_keys($svcIds);
    $ph   = implode(',', array_fill(0, count($keys), '?'));
    foreach (db_all('SELECT service_id, service_name FROM ' . T_SERVICES . ' WHERE service_id IN (' . $ph . ')', $keys) as $x) {
        $labelSvc[(string) $x['service_id']] = (string) $x['service_name'];
    }
}
$labelSec = [];
if ($secIds !== []) {
    $keys = array_keys($secIds);
    $ph   = implode(',', array_fill(0, count($keys), '?'));
    foreach (db_all('SELECT id, service_id, secret_key FROM ' . T_SECRETS . ' WHERE id IN (' . $ph . ')', $keys) as $x) {
        $labelSec[(int) $x['id']] = (string) $x['service_id'] . ' / ' . (string) $x['secret_key'];
    }
}

$items = [];
foreach ($rows as $r) {
    $tid   = $r['target_id'] !== null ? (string) $r['target_id'] : null;
    $label = null;
    if ($tid !== null) {
        if ($r['target_type'] === 'user') {
            $label = $labelUser[$tid] ?? null;
        } elseif ($r['target_type'] === 'service') {
            $label = $labelSvc[$tid] ?? null;
        } elseif ($r['target_type'] === 'secret') {
            $label = $labelSec[(int) $tid] ?? null;
        } else {
            $label = $tid;      // setting 등은 키 자체가 이름이다
        }
    }
    $items[] = [
        'id'             => (int) $r['id'],
        'admin_uuid'     => $r['admin_uuid'] !== null ? (string) $r['admin_uuid'] : null,
        'admin_username' => $r['admin_username'] !== null ? (string) $r['admin_username'] : null,
        'action'         => (string) $r['action'],
        'target_type'    => (string) $r['target_type'],
        'target_id'      => $tid,
        'target_label'   => $label,
        // 문자열이 아니라 파싱된 객체로 내보낸다 (계약 §8-21)
        'before'         => $r['before_json'] === null ? null : json_object(json_col($r['before_json'])),
        'after'          => $r['after_json'] === null ? null : json_object(json_col($r['after_json'])),
        'memo'           => $r['memo'] !== null ? (string) $r['memo'] : null,
        'ip'             => (string) $r['ip'],
        'created_at'     => iso($r['created_at']),
    ];
}

json_out([
    'items'    => $items,
    'total'    => $total,
    'page'     => $page,
    'per_page' => $perPage,
]);
