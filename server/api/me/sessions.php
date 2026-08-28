<?php
declare(strict_types=1);
/**
 * GET    /api/me/sessions                    — 유효 세션 목록
 * DELETE /api/me/sessions?session_id={sid}    — 세션 1건 종료
 * 담당 계약: 01_architect_api_contract.md §6-5, §6-6
 * 실제 호출 경로: api/me/sessions.php?session_id=...  (리더 판정 D-0 — 경로 세그먼트 금지)
 *
 * 응답 최상위 키
 *   GET    : items, total, page, per_page
 *   DELETE : revoked, session_id, was_current
 *
 * 남의 session_id 를 넣으면 FORBIDDEN 이 아니라 **NOT_FOUND** 다.
 * 403 을 주면 "그 세션은 존재한다"를 알려주는 오라클이 된다.
 */
require_once __DIR__ . '/../../core/bootstrap.php';

only_method('GET', 'DELETE');

$ctx     = require_auth();
$userId  = (int) $ctx['user']['id'];
$curSid  = (string) $ctx['session']['session_id'];
$method  = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// ---------------------------------------------------------------------
// GET — 회수되지 않고 만료되지 않은 세션만
// ---------------------------------------------------------------------
if ($method === 'GET') {
    list($page, $perPage, $offset) = page_params($_GET);
    $now = now_utc();

    $where  = ' WHERE user_id = ? AND revoked_at IS NULL AND expires_at > ?';
    $params = [$userId, $now];

    $total = (int) db_val('SELECT COUNT(*) FROM ' . T_SESSIONS . $where, $params);

    // 정렬 컬럼은 고정이다. 사용자 입력을 정렬에 쓰지 않는다.
    $rows = db_all(
        'SELECT * FROM ' . T_SESSIONS . $where . ' ORDER BY last_seen_at DESC, id DESC'
        . ' LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
        $params
    );

    $items = [];
    foreach ($rows as $r) {
        $items[] = serialize_session($r, hash_equals($curSid, (string) $r['session_id']));
    }

    json_out([
        'items'    => $items,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
    ]);
}

// ---------------------------------------------------------------------
// DELETE — 본인 세션 1건 종료
// ---------------------------------------------------------------------
require_csrf();     // 쿠키 세션일 때만 실제로 검사한다. Bearer 는 면제.

// 본문으로도 받는다(fetch 의 DELETE + body 패턴). 쿼리가 우선.
$body      = array_key_exists('session_id', $_GET) ? $_GET : body_json();
$targetSid = (string) v_string($body, 'session_id', 1, 64, true);

if (!is_token_format($targetSid)) {
    // 형식조차 아니면 조회할 필요가 없다. 응답은 존재하지 않는 경우와 같다.
    throw new AppError('NOT_FOUND', 404, ['message' => '해당 세션을 찾을 수 없습니다.']);
}

// **반드시 user_id 조건을 함께 건다.** session_id 단독 조회 후 소유자를 비교하면
// 비교를 빼먹는 순간 남의 세션을 끊을 수 있는 구멍이 된다.
$row = db_one(
    'SELECT * FROM ' . T_SESSIONS . ' WHERE session_id = ? AND user_id = ? AND revoked_at IS NULL AND expires_at > ?',
    [$targetSid, $userId, now_utc()]
);
if ($row === null) {
    // 없음 / 남의 것 / 이미 회수됨 / 만료됨 — 전부 같은 응답이다.
    throw new AppError('NOT_FOUND', 404, ['message' => '해당 세션을 찾을 수 없습니다.']);
}

$wasCurrent = hash_equals($curSid, (string) $row['session_id']);

revoke_session((int) $row['id'], 'logout');

if ($wasCurrent) {
    // 자기 자신을 끊었으면 쿠키도 지운다. 계약 §6-6 이 허용하는 동작이다.
    clear_session_cookie();
    log_login('logout', $userId, (string) $ctx['user']['username'], $row['service_id'] ?? null);
}

json_out([
    'revoked'     => true,
    'session_id'  => (string) $row['session_id'],
    'was_current' => $wasCurrent,
]);
