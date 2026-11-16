<?php
declare(strict_types=1);
/**
 * GET /api/admin/stats — 관리자 대시보드 통계
 * 담당 계약: 01_architect_api_contract.md §8-1 (리더 판정 D-0: 실제 호출 = /api/admin/stats.php)
 * 최소 역할: manager
 * 응답 최상위 키: users, logins, points, by_billing_tier, by_account_level, by_role,
 *                 services, sessions, generated_at
 *
 * 쿼리 6개로 끝낸다(집계는 GROUP BY / SUM(조건)으로 합친다).
 * by_* 는 값이 0인 키도 반드시 포함한다 — 프론트가 차트를 그릴 때 키 존재를 검사하지 않아도 되게.
 * 조회이므로 admin_logs 에 기록하지 않는다.
 */
require_once __DIR__ . '/../../core/bootstrap.php';

only_method('GET');

$ctx = require_auth();
require_global_role($ctx, 'manager');

$now   = now_utc();
$today = gmdate('Y-m-d') . ' 00:00:00';
$d7    = at_utc(-7 * 86400);
$d30   = at_utc(-30 * 86400);

// ---------------------------------------------------------------------
// 1) 회원 — 상태별 + 신규 + 포인트 총잔액 (1 쿼리)
// ---------------------------------------------------------------------
$u = db_one(
    'SELECT COUNT(*) AS total,'
    . ' SUM(status = ?) AS s_active,'
    . ' SUM(status = ?) AS s_pending,'
    . ' SUM(status = ?) AS s_suspended,'
    . ' SUM(status = ?) AS s_dormant,'
    . ' SUM(status = ?) AS s_withdrawn,'
    . ' SUM(created_at >= ?) AS new_today,'
    . ' SUM(created_at >= ?) AS new_7d,'
    . ' SUM(created_at >= ?) AS new_30d,'
    . ' COALESCE(SUM(points), 0) AS points_total'
    . ' FROM ' . T_USERS,
    ['active', 'pending', 'suspended', 'dormant', 'withdrawn', $today, $d7, $d30]
);
$u = $u === null ? [] : $u;

// ---------------------------------------------------------------------
// 2) 3축 등급 분포 (1 쿼리 — UNION ALL 로 GROUP BY 3개를 묶는다)
//    billing_tier 는 만료분을 free 로 강등해서 센다 (perm.php effective_grades 와 같은 규칙).
// ---------------------------------------------------------------------
// 그룹 라벨('level'/'role'/'tier')과 'free' 는 코드 리터럴이다(사용자 입력이 아니다).
// 시각 비교값 $now 만 바인딩한다.
$tierExpr = "CASE WHEN billing_expires_at IS NOT NULL AND billing_expires_at <= ? THEN 'free' ELSE billing_tier END";
$dist = db_all(
    "SELECT 'level' AS grp, account_level AS k, COUNT(*) AS c FROM " . T_USERS . ' GROUP BY account_level'
    . ' UNION ALL '
    . "SELECT 'role', role, COUNT(*) FROM " . T_USERS . ' GROUP BY role'
    . ' UNION ALL '
    . "SELECT 'tier', " . $tierExpr . ', COUNT(*) FROM ' . T_USERS . ' GROUP BY ' . $tierExpr,
    [$now, $now]
);

$byLevel = [];
foreach (ACCOUNT_LEVEL_RANK as $k => $_r) { $byLevel[$k] = 0; }
$byRole = [];
foreach (ROLE_RANK as $k => $_r) { $byRole[$k] = 0; }
$byTier = [];
foreach (BILLING_TIER_RANK as $k => $_r) { $byTier[$k] = 0; }

foreach ($dist as $r) {
    $key = (string) $r['k'];
    $cnt = (int) $r['c'];
    if ($r['grp'] === 'level') {
        $byLevel[$key] = isset($byLevel[$key]) ? $byLevel[$key] + $cnt : $cnt;
    } elseif ($r['grp'] === 'role') {
        $byRole[$key] = isset($byRole[$key]) ? $byRole[$key] + $cnt : $cnt;
    } else {
        $byTier[$key] = isset($byTier[$key]) ? $byTier[$key] + $cnt : $cnt;
    }
}

// ---------------------------------------------------------------------
// 3) 로그인 로그 (1 쿼리)
// ---------------------------------------------------------------------
// v1.8.0 (설계 A30) — 아래 `result LIKE 'fail%'` 은 **고정 패턴**이고 `_`·`%`·이스케이프 문자를
// 데이터로 담지 않는다(끝의 % 는 의도한 와일드카드). 그래서 ESCAPE 절이 필요 없고 두 저장소에서
// 같은 뜻이다. 사용자 입력을 LIKE 에 넣을 때는 like_contains() + sql_like_escape() 를 써라.
$lg = db_one(
    'SELECT SUM(created_at >= ?) AS today,'
    . ' SUM(created_at >= ? AND result = ?) AS success_7d,'
    . ' SUM(created_at >= ? AND result LIKE ?) AS fail_7d,'
    . ' COUNT(DISTINCT CASE WHEN created_at >= ? AND result = ? THEN user_id END) AS uniq_7d'
    . ' FROM ' . T_LOGIN_LOGS . ' WHERE created_at >= ?',
    [$today, $d7, 'success', $d7, 'fail%', $d7, 'success', $d7]
);
$lg = $lg === null ? [] : $lg;

// ---------------------------------------------------------------------
// 4) 포인트 원장 (1 쿼리)
// ---------------------------------------------------------------------
$pt = db_one(
    'SELECT COALESCE(SUM(CASE WHEN delta > 0 THEN delta ELSE 0 END), 0) AS earned_7d,'
    . ' COALESCE(SUM(CASE WHEN delta < 0 THEN -delta ELSE 0 END), 0) AS spent_7d'
    . ' FROM ' . T_POINT_LOGS . ' WHERE created_at >= ?',
    [$d7]
);
$pt = $pt === null ? [] : $pt;

// ---------------------------------------------------------------------
// 5) 서비스 (1 쿼리) / 6) 유효 세션 (1 쿼리)
// ---------------------------------------------------------------------
$sv = db_one(
    'SELECT COUNT(*) AS total, SUM(status = ?) AS active, SUM(status = ?) AS disabled FROM ' . T_SERVICES,
    ['active', 'disabled']
);
$sv = $sv === null ? [] : $sv;

$activeSessions = (int) db_val(
    'SELECT COUNT(*) FROM ' . T_SESSIONS . ' WHERE revoked_at IS NULL AND expires_at > ?',
    [$now]
);

// v1.10.1 — 가입 신청 대기(설계 130 §2-5 순수 추가). 기존 users.pending(이메일 미인증 계정)은 키를 그대로 둔다(D-371 Q-4).
//          0011 미적용이면 0(통계 화면 전체를 죽이지 않는다 — 신청 기능 자체가 없는 상태다).
$regPending = 0;
if (db_table_exists(T_REGISTER_REQUESTS)) {
    $regPending = (int) db_val('SELECT COUNT(*) FROM ' . T_REGISTER_REQUESTS . ' WHERE status = ? AND expires_at > ?',
        ['pending', $now]);
}

json_out([
    'users' => [
        'total'     => (int) ($u['total'] ?? 0),
        'active'    => (int) ($u['s_active'] ?? 0),
        'pending'   => (int) ($u['s_pending'] ?? 0),
        'suspended' => (int) ($u['s_suspended'] ?? 0),
        'dormant'   => (int) ($u['s_dormant'] ?? 0),
        'withdrawn' => (int) ($u['s_withdrawn'] ?? 0),
        'new_today' => (int) ($u['new_today'] ?? 0),
        'new_7d'    => (int) ($u['new_7d'] ?? 0),
        'new_30d'   => (int) ($u['new_30d'] ?? 0),
        'register_requests_pending' => $regPending,
    ],
    'logins' => [
        'today'           => (int) ($lg['today'] ?? 0),
        'success_7d'      => (int) ($lg['success_7d'] ?? 0),
        'fail_7d'         => (int) ($lg['fail_7d'] ?? 0),
        'unique_users_7d' => (int) ($lg['uniq_7d'] ?? 0),
    ],
    'points' => [
        'total_balance' => (int) ($u['points_total'] ?? 0),
        'earned_7d'     => (int) ($pt['earned_7d'] ?? 0),
        'spent_7d'      => (int) ($pt['spent_7d'] ?? 0),
    ],
    'by_billing_tier'  => $byTier,
    'by_account_level' => $byLevel,
    'by_role'          => $byRole,
    'services' => [
        'total'    => (int) ($sv['total'] ?? 0),
        'active'   => (int) ($sv['active'] ?? 0),
        'disabled' => (int) ($sv['disabled'] ?? 0),
    ],
    'sessions'     => ['active' => $activeSessions],
    'generated_at' => iso($now),
]);
