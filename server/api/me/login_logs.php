<?php
declare(strict_types=1);
/**
 * GET /api/me/login-logs — 내 로그인 기록
 * 담당 계약: 01_architect_api_contract.md §6-7
 * 실제 호출 경로: api/me/login_logs.php  (리더 판정 D-0 — 하이픈이 언더스코어)
 * 응답 최상위 키: items, total, page, per_page
 *
 * username_input 은 반환하지 않는다. 본인 로그에서는 의미가 없고,
 * 오타로 입력한 다른 사람의 아이디가 노출될 수 있다.
 */
require_once __DIR__ . '/../../core/bootstrap.php';

only_method('GET');

$ctx    = require_auth();
$userId = (int) $ctx['user']['id'];

list($page, $perPage, $offset) = page_params($_GET);

// result 는 화이트리스트로만 받는다 (schema 의 result 열거값)
// fail_refresh_reuse 는 v1.4.0 후속 신규 — 회수된 refresh 토큰이 다시 온 사건이다.
// **본인에게 보이는 것이 맞다**: "누군가 내 옛 토큰을 들고 왔고 그래서 전 세션이 끊겼다"
// 는 사용자가 알아야 하는 사건이고, 지금은 그 회수의 이유를 알 방법이 없다.
// fail_migrate_auth 는 server/migrate.php 의 superadmin 인증 실패다.
//
// fail_refresh_revoked 는 보안 재검증 A-6 오탐이 만든 값이다. 이 표는 **사용자 본인이
// 보는 표**이고, 종전에는 본인이 로그아웃한 직후 자기 이력에서 「토큰 탈취」한 줄을
// 봤다(실측). 이제 정상 회수 뒤의 갱신은 이 값으로 온다.
// **화이트리스트를 한쪽만 고치면 필터에서 사라진다** — 이 목록과
// api/admin/logs.php 의 $results 배열은 항상 함께 본다.
//
// success_silent 는 v1.7.0 신규 — 무재인증(무음) SSO 로 들어간 로그인이다.
// **본인에게 보이는 것이 맞다**: 「내 계정으로 어느 시스템에 언제 들어갔나」가
// 동의 화면을 두지 않기로 한 판정(D-235)의 대가로 남긴 유일한 확인 수단이고,
// 그 확인을 가장 먼저 할 사람이 계정 주인이다. 이름이 `success` 로 시작하는
// 정상 경로이므로 경고로 칠하면 안 된다.
// issued_silent 는 v1.7.0 수정 라운드 신규 (감사 M17-3) — 무음 code 가 **발급된** 시점이다.
// success_silent(교환 성공)와 **다른 사건**이고, 본인에게 보이는 것이 맞다:
// 「내 세션으로 누군가 무음 인가를 받아 갔는데 그 code 가 쓰이지는 않았다」가
// 계정 주인이 가장 먼저 알아야 할 모양이다. 이름이 success 로 시작하지 않지만
// **실패도 아니다** — 경고색이 아니라 중립으로 칠해야 한다(화면 담당 주의).
$result = v_enum($_GET, 'result',
    ['success', 'success_silent', 'issued_silent', 'fail_credential', 'fail_locked',
     'fail_status', 'fail_ratelimit', 'fail_service_blocked', 'fail_service_access', 'fail_emergency_unset', 'fail_emergency_scope', 'fail_client_auth',
     'fail_migrate_auth', 'fail_refresh_reuse', 'fail_refresh_revoked', 'logout']);

/** YYYY-MM-DD 인지 확인하고 실제 존재하는 날짜인지까지 본다. */
$dateOnly = function (string $key): ?string {
    if (!array_key_exists($key, $_GET) || $_GET[$key] === '' || $_GET[$key] === null) {
        return null;
    }
    $v = trim((string) $_GET[$key]);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) !== 1
        || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        v_fail($key, '날짜 형식이 올바르지 않습니다. (YYYY-MM-DD)');
    }
    return $v;
};
$from = $dateOnly('from');
$to   = $dateOnly('to');

$where  = ' WHERE user_id = ?';
$params = [$userId];
if ($result !== null) {
    $where   .= ' AND result = ?';
    $params[] = $result;
}
if ($from !== null) {
    $where   .= ' AND created_at >= ?';
    $params[] = $from . ' 00:00:00';
}
if ($to !== null) {
    // to 는 그 날짜를 포함한다 (사용자가 기대하는 동작)
    $where   .= ' AND created_at <= ?';
    $params[] = $to . ' 23:59:59';
}

$total = (int) db_val('SELECT COUNT(*) FROM ' . T_LOGIN_LOGS . $where, $params);

$rows = db_all(
    'SELECT id, result, service_id, ip, user_agent, created_at FROM ' . T_LOGIN_LOGS . $where
    . ' ORDER BY created_at DESC, id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
    $params
);

$items = [];
foreach ($rows as $r) {
    $items[] = [
        'id'         => (int) $r['id'],
        'result'     => (string) $r['result'],
        'service_id' => $r['service_id'] !== null ? (string) $r['service_id'] : null,
        'ip'         => (string) $r['ip'],
        'user_agent' => (string) $r['user_agent'],
        'created_at' => iso($r['created_at']),
    ];
}

json_out([
    'items'    => $items,
    'total'    => $total,
    'page'     => $page,
    'per_page' => $perPage,
]);
