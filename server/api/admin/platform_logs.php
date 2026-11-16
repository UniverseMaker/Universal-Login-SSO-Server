<?php
declare(strict_types=1);
/**
 * GET|POST /server/api/admin/platform_logs.php — 플랫폼 로그·감사 조회 · 체인 검증 · 보존 (v1.9.0)
 * 담당 계약: 설계 116 §5 · §8 · §10-3·4
 *
 * 쿠키 세션 + CSRF + 전역 role. 읽기·검증 admin · 쓰기(정리·보존 변경)·내보내기 superadmin.
 * GET ?kind=logs|audit&service_id&level_min&event&tag&uuid&request_id&q&from&to&cursor&limit(≤200)
 *     → items, next_cursor   (ts 내림차순. from/to 를 안 주면 최근 7일. q 는 서비스 지정 또는 기간 ≤ 7일일 때만)
 * GET ?kind=audit&format=jsonl (superadmin) → 감사 내보내기 ≤ 20,000행(prev_hash·row_hash 포함, application/x-ndjson)
 * POST action=verify{from_seq}              → checked, ok, first_bad_seq, last_seq   (5,000행씩)
 *      action=purge_now{kind:"logs",before} → deleted   (2,000행씩)
 *      action=set_retention{logs_days,audit_days} → logs_days, audit_days
 */
require_once __DIR__ . '/../../core/bootstrap.php';

only_method('GET', 'POST');
$ctx = require_auth();
require_global_role($ctx, 'admin');
$adminId = (int) $ctx['user']['id'];
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

function pl_dt(array $in, string $k): ?string
{
    $v = $in[$k] ?? null;
    if ($v === null || $v === '') {
        return null;
    }
    $t = is_string($v) ? strtotime($v . (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? ' 00:00:00 UTC' : '')) : false;
    if ($t === false) {
        v_fail($k, $k . ' 시각 형식이 올바르지 않습니다.');
    }
    return gmdate('Y-m-d H:i:s', (int) $t);
}

function pl_log_out(array $r): array
{
    $tags = $r['tags'] === null ? [] : array_values(array_filter(explode(',', (string) $r['tags']), 'strlen'));
    $data = $r['data_json'] === null ? null : json_decode((string) $r['data_json'], true);
    return [
        'id' => (int) $r['id'], 'service_id' => (string) $r['service_id'], 'event_id' => (string) $r['event_id'],
        'ts' => iso($r['ts']), 'received_at' => iso($r['received_at']), 'level' => (string) $r['level'],
        'event' => (string) $r['event'], 'message' => $r['message'] === null ? null : (string) $r['message'], 'tags' => $tags,
        'uuid' => $r['uuid'] === null ? null : (string) $r['uuid'],
        'local_user' => $r['local_user'] === null ? null : (string) $r['local_user'],
        'request_id' => $r['request_id'] === null ? null : (string) $r['request_id'], 'source' => (string) $r['source'],
        'sender_ip' => (string) $r['sender_ip'], 'data' => $data,
    ];
}

/** v1.10.0 — 목록용 감사 행 마스킹(mask_for_viewer 한 곳을 거친다). */
function pl_audit_mask(array $o, string $viewerRole): array
{
    $o['ip'] = mask_for_viewer('ip', $o['ip'], $viewerRole);
    $o['user_agent'] = mask_for_viewer('ua', $o['user_agent'], $viewerRole);
    $o['sender_ip'] = (string) mask_for_viewer('ip', $o['sender_ip'], $viewerRole);   // v1.10.0 감사 M-2
    return $o;
}

function pl_audit_out(array $r, bool $chain = false): array
{
    $o = [
        'seq' => (int) $r['id'], 'service_id' => (string) $r['service_id'], 'event_id' => (string) $r['event_id'],
        'ts' => iso($r['ts']), 'received_at' => iso($r['received_at']),
        'actor' => ['type' => (string) $r['actor_type'], 'uuid' => $r['actor_uuid'] === null ? null : (string) $r['actor_uuid'],
                    'local_user' => $r['actor_local'] === null ? null : (string) $r['actor_local']],
        'action' => (string) $r['action'],
        'target' => ['type' => (string) $r['target_type'], 'id' => $r['target_id'] === null ? null : (string) $r['target_id']],
        'result' => (string) $r['result'],
        'changes' => $r['changes_json'] === null ? null : json_decode((string) $r['changes_json'], true),
        'ip' => $r['ip'] === null ? null : (string) $r['ip'], 'user_agent' => $r['user_agent'] === null ? null : (string) $r['user_agent'],
        'request_id' => $r['request_id'] === null ? null : (string) $r['request_id'],
        'reason' => $r['reason'] === null ? null : (string) $r['reason'], 'sender_ip' => (string) $r['sender_ip'],
    ];
    if ($chain) {
        $o['prev_hash'] = (string) $r['prev_hash'];
        $o['row_hash']  = (string) $r['row_hash'];
    }
    return $o;
}

if ($method === 'GET') {
    $kind = v_enum($_GET, 'kind', ['logs', 'audit'], false, 'logs');
    $table = $kind === 'logs' ? T_PLAT_LOGS : T_PLAT_AUDIT;
    $sid = v_string($_GET, 'service_id', 1, 64, false);
    admin_service_id_reject_former($sid);
    $to   = pl_dt($_GET, 'to') ?? now_utc();
    $from = pl_dt($_GET, 'from') ?? gmdate('Y-m-d H:i:s', (int) ts($to) - 7 * 86400);
    $w = ['ts >= ?', 'ts <= ?'];
    $p = [$from, $to];
    if ($sid !== null) {
        $w[] = 'service_id = ?';
        $p[] = $sid;
    }
    $uuid = v_string($_GET, 'uuid', 36, 36, false);
    if ($uuid !== null) {
        $w[] = ($kind === 'logs' ? 'uuid' : 'actor_uuid') . ' = ?';
        $p[] = strtolower($uuid);
    }
    $rid = v_string($_GET, 'request_id', 1, 64, false);
    if ($rid !== null) {
        $w[] = 'request_id = ?';
        $p[] = $rid;
    }
    if ($kind === 'logs') {
        $lm = v_enum($_GET, 'level_min', array_keys(PLAT_LEVELS), false);
        if ($lm !== null) {
            $ls = [];
            foreach (PLAT_LEVELS as $l => $rank) {
                if ($rank >= PLAT_LEVELS[$lm]) {
                    $ls[] = $l;
                }
            }
            $w[] = 'level IN (' . implode(',', array_fill(0, count($ls), '?')) . ')';
            $p = array_merge($p, $ls);
        }
        $ev = v_string($_GET, 'event', 1, 64, false);
        if ($ev !== null) {
            $w[] = 'event = ?';
            $p[] = $ev;
        }
        $tag = v_string($_GET, 'tag', 1, 32, false);
        if ($tag !== null) {
            if (preg_match('/^[a-z0-9_.:-]{1,32}$/', $tag) !== 1) {
                v_fail('tag', 'tag 형식이 올바르지 않습니다.');
            }
            $w[] = 'tags LIKE ? ' . sql_like_escape();
            $p[] = '%,' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $tag) . ',%';
        }
    } else {
        $act = v_string($_GET, 'event', 1, 64, false);
        if ($act !== null) {
            $w[] = 'action = ?';
            $p[] = $act;
        }
    }
    $q = v_string($_GET, 'q', 1, 100, false);
    if ($q !== null) {
        if ($sid === null && (int) ts($to) - (int) ts($from) > 7 * 86400) {
            v_fail('q', '본문 검색은 서비스를 고르거나 기간을 7일 이내로 좁힌 뒤에만 됩니다.');
        }
        $col = $kind === 'logs' ? 'message' : 'changes_json';
        $w[] = $col . ' LIKE ? ' . sql_like_escape();
        $p[] = like_contains($q);
    }

    if (($_GET['format'] ?? '') === 'jsonl') {
        if ($kind !== 'audit') {
            v_fail('format', '내보내기는 감사(kind=audit)만 됩니다.');
        }
        require_global_role($ctx, 'superadmin');
        $rows = db_all('SELECT * FROM ' . T_PLAT_AUDIT . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY id ASC LIMIT 20000', $p);
        log_admin($adminId, 'plat.audit.export', 'audit', $sid, null, ['rows' => count($rows), 'from' => $from, 'to' => $to]);
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('Content-Disposition: attachment; filename="ulogin-audit-' . gmdate('Ymd-His') . '.jsonl"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        foreach ($rows as $r) {
            echo json_encode(pl_audit_out($r, true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
        }
        exit;
    }

    $limit = v_int($_GET, 'limit', 1, 200, false, 50);
    $cur = $_GET['cursor'] ?? null;
    if ($cur !== null && $cur !== '') {
        $raw = is_string($cur) ? base64_decode(strtr($cur, '-_', '+/'), true) : false;
        $cj = $raw === false ? null : json_decode((string) $raw, true);
        if (!is_array($cj) || !is_string($cj['ts'] ?? null) || !is_int($cj['id'] ?? null)) {
            v_fail('cursor', '커서가 올바르지 않습니다.');
        }
        $w[] = '(ts < ? OR (ts = ? AND id < ?))';
        array_push($p, $cj['ts'], $cj['ts'], $cj['id']);
    }
    $rows = db_all('SELECT * FROM ' . $table . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY ts DESC, id DESC LIMIT ' . ($limit + 1), $p);
    $more = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);
    $items = [];
    foreach ($rows as $r) {
        // v1.10.0 (설계 127 §4) — 감사 ip·user_agent 원문은 superadmin 만. 내보내기(superadmin)는 원문(체인 대조)
        // v1.10.0 감사 M-2 / D-372 — 로그 행의 sender_ip 도 같은 마스킹 한 곳을 거친다(감사 행은 pl_audit_mask 안에서)
        if ($kind === 'logs') {
            $lo = pl_log_out($r);
            $lo['sender_ip'] = (string) mask_for_viewer('ip', $lo['sender_ip'], ctx_global_role($ctx));
            $items[] = $lo;
        } else {
            $items[] = pl_audit_mask(pl_audit_out($r, false), ctx_global_role($ctx));
        }
    }
    $next = null;
    if ($more && $rows !== []) {
        $l = $rows[count($rows) - 1];
        $next = rtrim(strtr(base64_encode((string) json_encode(['ts' => (string) $l['ts'], 'id' => (int) $l['id']])), '+/', '-_'), '=');
    }
    json_out(['items' => $items, 'next_cursor' => $next]);
}

require_csrf();
$in = body_json();
$action = v_enum($in, 'action', ['verify', 'purge_now', 'set_retention'], true);

if ($action === 'verify') {
    $from = v_int($in, 'from_seq', 1, PHP_INT_MAX, false, 1);
    $r = audit_verify((int) $from);
    json_out(['checked' => $r['checked'], 'ok' => $r['ok'], 'first_bad_seq' => $r['first_bad_seq'], 'last_seq' => $r['last_seq']]);
}

require_global_role($ctx, 'superadmin');

if ($action === 'purge_now') {
    v_enum($in, 'kind', ['logs'], true);   // 감사는 이 경로로 지우지 않는다 — audit_prune() 만
    $before = pl_dt($in, 'before');
    if ($before === null) {
        v_fail('before', 'before 시각이 필요합니다.');
    }
    $n = db_delete_limited(T_PLAT_LOGS, 'received_at < ?', [$before], 2000);
    log_admin($adminId, 'plat.logs.purge', 'plat_logs', null, null, ['before' => $before, 'deleted' => $n]);
    json_out(['deleted' => $n]);
}

// set_retention
$ld = v_int($in, 'logs_days', 1, 365, true);
$ad = v_int($in, 'audit_days', 90, 3650, true);
$prev = ['logs_days' => (int) setting_get('plat.log_retention_days', (int) PLAT_LOG_RETENTION_DAYS),
         'audit_days' => (int) setting_get('plat.audit_retention_days', (int) PLAT_AUDIT_RETENTION_DAYS)];
setting_set('plat.log_retention_days', (int) $ld, 'int', '플랫폼 로그 보존(일)');
setting_set('plat.audit_retention_days', (int) $ad, 'int', '플랫폼 감사 보존(일)');
log_admin($adminId, 'plat.retention.update', 'setting', 'plat.retention', $prev, ['logs_days' => $ld, 'audit_days' => $ad]);
json_out(['logs_days' => (int) $ld, 'audit_days' => (int) $ad]);
