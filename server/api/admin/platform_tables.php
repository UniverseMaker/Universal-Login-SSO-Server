<?php
declare(strict_types=1);
/**
 * GET|POST /server/api/admin/platform_tables.php — 문서 테이블 관리 (v1.9.0)
 * 담당 계약: 설계 116 §6 · §8 · §10-3
 *
 * 쿠키 세션 + CSRF + 전역 role. 읽기 admin · 쓰기 superadmin. 모든 쓰기는 log_admin → 감사.
 * GET ?service_id                         → items(컬렉션 목록)
 * GET ?service_id&collection[&cursor]     → collection, items(문서 목록, data 미리보기 ≤ 2KiB), next_cursor
 * GET ?service_id&collection&doc_id       → doc(전문)
 * POST action=upsert_collection{service_id, name, read_rule, write_rule, indexes, max_docs, max_doc_bytes} → collection
 *      action=drop_collection{service_id, collection, confirm:이름}  → deleted, remaining, dropped (2,000건씩)
 *      action=reindex{service_id, collection, after}                 → processed, done, next_after (500건씩)
 *      action=doc_set{service_id, collection, doc_id, data, expected_version}     → doc
 *      action=doc_delete{service_id, collection, doc_id, expected_version}        → deleted
 */
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/accountlink.php';
require_once __DIR__ . '/../../core/docs.php';

only_method('GET', 'POST');
$ctx = require_auth();
require_global_role($ctx, 'admin');
$adminId = (int) $ctx['user']['id'];
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

function pt_service(array $in): string
{
    $sid = v_string($in, 'service_id', 1, 64, true);
    admin_service_id_reject_former($sid);
    if (al_service_row($sid) === null) {
        throw new AppError('NOT_FOUND', 404, ['field' => 'service_id', 'message' => '서비스가 없습니다.']);
    }
    return (string) $sid;
}

if ($method === 'GET') {
    $sid = pt_service($_GET);
    $cname = $_GET['collection'] ?? null;
    if ($cname === null || $cname === '') {
        $items = [];
        foreach (db_all('SELECT * FROM ' . T_PLAT_COLLS . ' WHERE service_id = ? ORDER BY name ASC', [$sid]) as $r) {
            $items[] = array_merge(docs_coll_out($r), ['status' => (string) $r['status'], 'updated_at' => iso($r['updated_at'])]);
        }
        json_out(['items' => $items]);
    }
    $coll = docs_coll($sid, (string) $cname);
    if ($coll === null) {
        throw new AppError('NOT_FOUND', 404, ['field' => 'collection']);
    }
    // v1.10.0 (설계 127 §4 · S10) — read_rule=owner 컬렉션은 문서 data 를 **아무 관리자도** 보지 못한다(개인 다이어리 등).
    $ownerOnly = (string) $coll['read_rule'] === 'owner';   // OWNER-DATA-HIDDEN
    if (isset($_GET['doc_id']) && $_GET['doc_id'] !== '') {
        $d = docs_find($sid, (string) $coll['name'], $_GET['doc_id']);
        $out = docs_out($d);
        if ($ownerOnly) {
            $out['data'] = null;
            $out['data_bytes'] = strlen((string) $d['data_json']);
        }
        json_out(['doc' => $out]);
    }
    $after = isset($_GET['cursor']) && $_GET['cursor'] !== '' ? v_int($_GET, 'cursor', 1, PHP_INT_MAX, false) : null;
    $limit = 50;
    $p = [$sid, (string) $coll['name']];
    $w = 'service_id = ? AND collection = ?';
    if ($after !== null) {
        $w .= ' AND id < ?';
        $p[] = $after;
    }
    $rows = db_all('SELECT * FROM ' . T_PLAT_DOCS . ' WHERE ' . $w . ' ORDER BY id DESC LIMIT ' . ($limit + 1), $p);
    $more = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);
    $items = [];
    foreach ($rows as $r) {
        $prev = (string) $r['data_json'];
        $items[] = [
            'doc_id' => (string) $r['doc_id'], 'version' => (int) $r['version'],
            'owner_uuid' => $r['owner_uuid'] === null ? null : (string) $r['owner_uuid'],
            'preview' => $ownerOnly ? null : (strlen($prev) > 2048 ? mb_strcut($prev, 0, 2048, 'UTF-8') : $prev),
            'truncated' => !$ownerOnly && strlen($prev) > 2048, 'updated_at' => iso($r['updated_at']),
            'data' => null, 'data_bytes' => strlen($prev),   // v1.10.0 — 순수 추가(owner 컬렉션은 preview 도 null)
        ];
    }
    json_out([
        'collection'  => docs_coll_out($coll),
        'items'       => $items,
        'next_cursor' => ($more && $rows !== []) ? (string) $rows[count($rows) - 1]['id'] : null,
    ]);
}

require_csrf();
require_global_role($ctx, 'superadmin');
$in = body_json();
$action = v_enum($in, 'action', ['upsert_collection', 'drop_collection', 'reindex', 'doc_set', 'doc_delete'], true);
$sid = pt_service($in);
$svcCaller = ['kind' => 'service', 'service' => ['service_id' => $sid], 'user' => null];

if ($action === 'upsert_collection') {
    $before = docs_coll($sid, is_string($in['name'] ?? null) ? (string) $in['name'] : '');
    $coll = docs_coll_upsert($sid, $in);
    log_admin($adminId, 'plat.table.upsert', 'collection', $sid . '/' . (string) $coll['name'],
        $before === null ? null : ['read_rule' => $before['read_rule'], 'write_rule' => $before['write_rule'], 'indexes' => $before['index_json']],
        ['read_rule' => $coll['read_rule'], 'write_rule' => $coll['write_rule'], 'indexes' => $coll['index_json']]);
    json_out(['collection' => docs_coll_out($coll)]);
}

$cname = $in['collection'] ?? null;
if (!is_string($cname) || $cname === '') {
    v_fail('collection', 'collection 이 필요합니다.');
}
$coll = docs_coll($sid, (string) $cname);
if ($coll === null) {
    throw new AppError('NOT_FOUND', 404, ['field' => 'collection']);
}

if ($action === 'drop_collection') {
    if (($in['confirm'] ?? null) !== (string) $coll['name']) {
        v_fail('confirm', '확인을 위해 컬렉션 이름을 그대로 입력하세요.');
    }
    // 끄고(질의·쓰기 404) 2,000건씩 지운다. 다 지우면 컬렉션 행도 지운다.
    db_exec('UPDATE ' . T_PLAT_COLLS . ' SET status = ?, updated_at = ? WHERE id = ?', ['disabled', now_utc(), (int) $coll['id']]);
    $n = db_delete_limited(T_PLAT_DOCS, 'service_id = ? AND collection = ?', [$sid, (string) $coll['name']], 2000);
    $left = (int) db_val('SELECT COUNT(*) FROM ' . T_PLAT_DOCS . ' WHERE service_id = ? AND collection = ?', [$sid, (string) $coll['name']]);
    $dropped = false;
    if ($left === 0) {
        db_exec('DELETE FROM ' . T_PLAT_COLLS . ' WHERE id = ?', [(int) $coll['id']]);
        $dropped = true;
    } else {
        db_exec('UPDATE ' . T_PLAT_COLLS . ' SET doc_count = ? WHERE id = ?', [$left, (int) $coll['id']]);
    }
    log_admin($adminId, 'plat.table.drop', 'collection', $sid . '/' . (string) $coll['name'],
        ['doc_count' => (int) $coll['doc_count']], ['deleted' => $n, 'remaining' => $left, 'dropped' => $dropped]);
    json_out(['deleted' => $n, 'remaining' => $left, 'dropped' => $dropped]);
}

if ($action === 'reindex') {
    $after = isset($in['after']) && is_int($in['after']) ? (int) $in['after'] : 0;
    $r = docs_reindex($coll, $after);
    if ($r['done']) {
        log_admin($adminId, 'plat.table.reindex', 'collection', $sid . '/' . (string) $coll['name'], null, ['done' => true]);
    }
    json_out(['processed' => $r['processed'], 'done' => $r['done'], 'next_after' => $r['next_after']]);
}

if ($action === 'doc_set') {
    if ((string) $coll['read_rule'] === 'owner') {
        // v1.10.0 (설계 127 §4) — 개인 문서는 관리자가 고쳐 쓰지 않는다(삭제만, 사유와 함께)
        throw new AppError('FORBIDDEN', 403, ['message' => '본인만 읽는 컬렉션의 문서는 고칠 수 없습니다. 삭제만 할 수 있습니다.']);
    }
    $prev = docs_find($sid, (string) $coll['name'], $in['doc_id'] ?? null);
    $r = docs_op_update($svcCaller, $coll, $in, false);
    log_admin($adminId, 'plat.table.doc_set', 'doc', $sid . '/' . (string) $coll['name'] . '/' . (string) $prev['doc_id'],
        ['version' => (int) $prev['version']], ['version' => (int) $r['doc']['version']]);
    json_out(['doc' => $r['doc']]);
}

// doc_delete
$ownerDoc = (string) $coll['read_rule'] === 'owner';
$reason = null;
if ($ownerDoc) {
    // v1.10.0 (설계 127 §4 · §5) — 개인 문서 삭제는 superadmin(위에서 확인) + 사유 필수. 감사 admin.doc.delete_owner
    $reason = v_string($in, 'reason', 1, 255, false);
    if ($reason === null || trim($reason) === '') {
        v_fail('reason', '본인만 읽는 문서를 지우려면 사유가 필요합니다.');
    }
}
$prev = docs_find($sid, (string) $coll['name'], $in['doc_id'] ?? null);
docs_op_delete($svcCaller, $coll, $in);
log_admin($adminId, $ownerDoc ? 'admin.doc.delete_owner' : 'plat.table.doc_delete', 'doc',
    $sid . '/' . (string) $coll['name'] . '/' . (string) $prev['doc_id'], ['version' => (int) $prev['version']], null, $reason);
json_out(['deleted' => true]);
