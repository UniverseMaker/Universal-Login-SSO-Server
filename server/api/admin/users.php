<?php
declare(strict_types=1);
/**
 * GET /api/admin/users — 회원 목록(검색·필터·정렬·페이징)
 * 담당 계약: §8-2 (리더 판정 D-0: 실제 호출 = /api/admin/users.php?...)
 * 최소 역할: manager
 * 응답 최상위 키: items, total, page, per_page, filters_applied
 *
 * N+1 방지 (지시 9항):
 *   목록의 user 객체는 serialize_user($u, null, true, $extra) 로 만든다.
 *   $extra['session_count'] 는 **현재 페이지 user_id 를 IN 으로 묶은 집계 쿼리 1회**로 미리 뽑아 넘기고,
 *   $extra['service_overrides'] 는 계약 §8-2 대로 목록에서 **빈 배열**로 내린다(상세 §8-3 에서만 채운다).
 *   → 페이지당 쿼리 3개 고정: COUNT / 목록 / 세션 집계.
 *
 * 정렬 컬럼은 화이트리스트로만 받는다. 바인딩이 불가능한 값이라 이것이 유일한 방어선이다.
 * 조회이므로 admin_logs 에 기록하지 않는다.
 */
require_once __DIR__ . '/../../core/bootstrap.php';

only_method('GET');

$ctx = require_auth();
require_global_role($ctx, 'manager');

$in = $_GET;

// ---------------------------------------------------------------------
// 필터 파싱 — 허용 목록 외의 값은 VALIDATION_FAILED
// ---------------------------------------------------------------------
$q            = v_string($in, 'q', 1, 190, false);
$status       = v_enum($in, 'status', ['active', 'pending', 'suspended', 'dormant', 'withdrawn'], false);
$role         = v_enum($in, 'role', array_keys(ROLE_RANK), false);
$accountLevel = v_enum($in, 'account_level', array_keys(ACCOUNT_LEVEL_RANK), false);
$billingTier  = v_enum($in, 'billing_tier', array_keys(BILLING_TIER_RANK), false);
$serviceId    = v_string($in, 'service_id', 1, 64, false);
$from         = v_string($in, 'from', 4, 30, false);
$to           = v_string($in, 'to', 4, 30, false);

$allowedSort = ['created_at', 'last_login_at', 'username', 'points', 'login_count'];
$sortRaw     = in_raw($in, 'sort');
if ($sortRaw === null || $sortRaw === '') {
    $sort = 'created_at';
} elseif (is_string($sortRaw) && in_array($sortRaw, $allowedSort, true)) {
    $sort = $sortRaw;
} else {
    throw new AppError('VALIDATION_FAILED', 400, [
        'field'   => 'sort',
        'message' => '정렬 기준이 올바르지 않습니다.',
    ]);
}

$orderRaw = in_raw($in, 'order');
if ($orderRaw === null || $orderRaw === '') {
    $order = 'DESC';
} else {
    $o = is_string($orderRaw) ? strtoupper(trim($orderRaw)) : '';
    if ($o !== 'ASC' && $o !== 'DESC') {
        throw new AppError('VALIDATION_FAILED', 400, [
            'field'   => 'order',
            'message' => '정렬 방향은 asc 또는 desc 여야 합니다.',
        ]);
    }
    $order = $o;
}

/** 'YYYY-MM-DD' 또는 ISO8601 → UTC DATETIME. 형식이 틀리면 VALIDATION_FAILED */
$parseDate = function (?string $v, string $field, bool $endOfDay) {
    if ($v === null) {
        return null;
    }
    $s = trim($v);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1) {
        return $s . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
    }
    $t = strtotime(str_replace('Z', ' UTC', $s));
    if ($t === false) {
        throw new AppError('VALIDATION_FAILED', 400, ['field' => $field, 'message' => '날짜 형식이 올바르지 않습니다.']);
    }
    return gmdate('Y-m-d H:i:s', $t);
};
$fromAt = $parseDate($from, 'from', false);
$toAt   = $parseDate($to, 'to', true);

list($page, $perPage, $offset) = page_params($in, 20);

// ---------------------------------------------------------------------
// WHERE 조립 — 값은 예외 없이 바인딩한다
// ---------------------------------------------------------------------
$where  = ['1 = 1'];
$params = [];

if ($q !== null) {
    // LIKE 와일드카드는 이스케이프한다 (MySQL 기본 이스케이프 문자는 백슬래시)
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $where[]  = '(u.username LIKE ? OR u.email LIKE ? OR u.name LIKE ? OR u.nickname LIKE ?)';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($status !== null)       { $where[] = 'u.status = ?';        $params[] = $status; }
if ($role !== null)         { $where[] = 'u.role = ?';          $params[] = $role; }
if ($accountLevel !== null) { $where[] = 'u.account_level = ?'; $params[] = $accountLevel; }
if ($billingTier !== null)  { $where[] = 'u.billing_tier = ?';  $params[] = $billingTier; }
if ($fromAt !== null)       { $where[] = 'u.created_at >= ?';   $params[] = $fromAt; }
if ($toAt !== null)         { $where[] = 'u.created_at <= ?';   $params[] = $toAt; }
if ($serviceId !== null) {
    $where[]  = 'EXISTS (SELECT 1 FROM ' . T_USER_SERVICES . ' us WHERE us.user_id = u.id AND us.service_id = ?)';
    $params[] = $serviceId;
}
$whereSql = implode(' AND ', $where);

$total = (int) db_val('SELECT COUNT(*) FROM ' . T_USERS . ' u WHERE ' . $whereSql, $params);

// LIMIT/OFFSET 은 정수 캐스팅 후 조립한다 (page_params 가 이미 clamp 했다)
$rows = db_all(
    'SELECT u.* FROM ' . T_USERS . ' u WHERE ' . $whereSql
    . ' ORDER BY u.`' . $sort . '` ' . $order . ', u.id ' . $order
    . ' LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
    $params
);

// ---------------------------------------------------------------------
// 세션 수 집계 1회 (N+1 방지)
// ---------------------------------------------------------------------
$sessionCounts = [];
if ($rows !== []) {
    $ids = [];
    foreach ($rows as $r) {
        $ids[] = (int) $r['id'];
    }
    $ph   = implode(',', array_fill(0, count($ids), '?'));
    $args = $ids;
    $args[] = now_utc();
    foreach (db_all(
        'SELECT user_id, COUNT(*) AS c FROM ' . T_SESSIONS
        . ' WHERE user_id IN (' . $ph . ') AND revoked_at IS NULL AND expires_at > ?'
        . ' GROUP BY user_id',
        $args
    ) as $sr) {
        $sessionCounts[(int) $sr['user_id']] = (int) $sr['c'];
    }
}

$items = [];
foreach ($rows as $r) {
    $items[] = serialize_user($r, null, true, [
        'session_count'     => $sessionCounts[(int) $r['id']] ?? 0,
        'service_overrides' => [],      // 계약 §8-2 — 목록에서는 비운다
    ]);
}

// 실제로 적용된 필터만 담는다. 비면 {} (null 도 [] 도 아니다)
$applied = [];
if ($q !== null)            { $applied['q'] = $q; }
if ($status !== null)       { $applied['status'] = $status; }
if ($role !== null)         { $applied['role'] = $role; }
if ($accountLevel !== null) { $applied['account_level'] = $accountLevel; }
if ($billingTier !== null)  { $applied['billing_tier'] = $billingTier; }
if ($serviceId !== null)    { $applied['service_id'] = $serviceId; }
if ($from !== null)         { $applied['from'] = $from; }
if ($to !== null)           { $applied['to'] = $to; }
// 계약 §8-2 예시는 sort/order 를 filters_applied 에 넣지 않는다(요청에 있어도). 그대로 따른다.

json_out([
    'items'            => $items,
    'total'            => $total,
    'page'             => $page,
    'per_page'         => $perPage,
    'filters_applied'  => json_object($applied),
]);
