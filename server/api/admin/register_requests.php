<?php
declare(strict_types=1);
/**
 * 가입 신청 관리 — /api/admin/register_requests.php (v1.10.1 신규)
 * 담당 계약: 설계 130 §2-5 · 판정 D-371 Q-3(admin 이상, 감사 필수)
 *
 *   GET  ?status=pending&page=1&per_page=20   → items, page, per_page, total
 *   GET  ?id=12                               → request (판단 재료 8종 signals · service · 결정 정보)
 *   POST {"action":"approve","id":12,"memo":"…"}                   → request{id,status,user_uuid,decided_at}, warnings
 *   POST {"action":"reject","id":12,"reason":"…","notice":"…"}     → request{id,status,decided_at}
 *
 * 권한: 전역 role admin 이상(require_global_role — D-50. 서비스 세션은 user 로 본다). CAN_MAP register.view/decide.
 * 쓰기는 CSRF 필수(쿠키 세션일 때). 감사: log_admin('register.approve'|'register.reject') — 비밀번호·이메일·IP 는 넣지 않는다.
 * 가리기: email·phone 은 admin 이상이면 원문, ip·ua 는 superadmin 만 원문 — mask_for_viewer() 한 곳(T1-6).
 */
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/regreq.php';

only_method('GET', 'POST');

$ctx = require_auth();
require_global_role($ctx, CAN_MAP['register.view']);
$viewer  = ctx_global_role($ctx);
$adminId = (int) $ctx['user']['id'];
$method  = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if (!db_table_exists(T_REGISTER_REQUESTS)) {
    // 0011 미적용 — 조용히 빈 목록을 내지 않는다(fail-closed, D-53)
    error_log('[ulogin] admin/register_requests: table missing (run migrate.php apply)');
    throw new AppError('SERVER_ERROR', 500);
}

$svcNames = [];
foreach (db_all('SELECT service_id, service_name FROM ' . T_SERVICES) as $s) {
    $svcNames[(string) $s['service_id']] = (string) $s['service_name'];
}

if ($method === 'GET') {
    if (array_key_exists('id', $_GET)) {
        $id = (int) v_int($_GET, 'id', 1, PHP_INT_MAX, true);
        $r  = db_one('SELECT * FROM ' . T_REGISTER_REQUESTS . ' WHERE id = ?', [$id]);
        if ($r === null) {
            throw new AppError('NOT_FOUND', 404, ['field' => 'id']);
        }
        json_out(['request' => regreq_item($r, $viewer, true, $svcNames)]);
    }
    $status = (string) v_enum($_GET, 'status', REGREQ_STATUSES, false, 'pending');
    list($page, $perPage, $offset) = page_params($_GET, 20);
    $now = now_utc();
    // 만료 시각이 지난 pending 은 gc 전이라도 「expired」 로 본다(목록이 결정 불가능한 행을 대기로 보이지 않게)
    if ($status === 'pending') {
        $where = ' WHERE status = ? AND expires_at > ?';
        $p     = ['pending', $now];
    } elseif ($status === 'expired') {
        $where = ' WHERE (status = ? OR (status = ? AND expires_at <= ?))';
        $p     = ['expired', 'pending', $now];
    } else {
        $where = ' WHERE status = ?';
        $p     = [$status];
    }
    $total = (int) db_val('SELECT COUNT(*) FROM ' . T_REGISTER_REQUESTS . $where, $p);
    $rows  = db_all('SELECT * FROM ' . T_REGISTER_REQUESTS . $where . ' ORDER BY created_at DESC, id DESC'
        . ' LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset, $p);
    $items = [];
    foreach ($rows as $r) {
        $items[] = regreq_item($r, $viewer, false, $svcNames);
    }
    json_out(['items' => $items, 'page' => $page, 'per_page' => $perPage, 'total' => $total]);
}

// ---------------------------------------------------------------- POST
require_global_role($ctx, CAN_MAP['register.decide']);
require_csrf();
$body   = body_json();
$action = (string) v_enum($body, 'action', ['approve', 'reject'], true);
$id     = (int) v_int($body, 'id', 1, PHP_INT_MAX, true);

if ($action === 'approve') {
    $memo = v_string($body, 'memo', 0, 255);
    $memo = ($memo === null || $memo === '') ? null : $memo;
    $res  = regreq_approve($id, $adminId, $memo);
    if ($res['require_verify']) {
        // 이메일 인증이 켜져 있으면 계정은 pending 이고 인증 메일은 이때 보낸다(open 경로와 같다)
        try {
            register_send_verify_mail((int) $res['user_id'], (string) $res['email'], client_ip());
        } catch (Throwable $e) {
            error_log('[ulogin] register approve: verify mail failed: ' . get_class($e));
        }
    }
    log_admin($adminId, 'register.approve', 'register_request', (string) $id, ['status' => 'pending'],
        ['status' => 'approved', 'user_id' => (int) $res['user_id'], 'username' => $res['username']], $memo);
    json_out(['request' => $res['request'], 'warnings' => $res['warnings']]);
}

// reject — 사유 필수(1~255), 안내 선택(≤200)
$reason = (string) v_string($body, 'reason', 1, 255, true);
if (trim($reason) === '') {
    v_fail('reason', '거부 사유를 적어 주세요.');
}
$notice = v_string($body, 'notice', 0, 200);
$notice = ($notice === null || $notice === '') ? null : $notice;
$res = regreq_reject($id, $adminId, $reason, $notice);
log_admin($adminId, 'register.reject', 'register_request', (string) $id, ['status' => 'pending'],
    ['status' => 'rejected', 'username' => $res['username']], $reason);
json_out(['request' => $res['request']]);
