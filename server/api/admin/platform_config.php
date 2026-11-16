<?php
declare(strict_types=1);
/**
 * GET|POST /server/api/admin/platform_config.php — 전역 설정 관리 (v1.9.0)
 * 담당 계약: 설계 116 §4 · §8 · H-5
 *
 * 쿠키 세션 + CSRF + **전역 role**(require_global_role). 읽기 admin · 쓰기 superadmin. 서비스 토큰은 user 로 읽힌다(H186-1).
 * GET  ?scope=*|{sid}            → scope, drafts, published, versions, dirty
 * GET  ?scope=…&diff={v}         → added, removed, changed   (버전 v → 지금 초안)
 * POST action=save_draft{scope,key,value,conditions,description} → draft
 *      action=delete_draft{scope,key}                            → deleted
 *      action=publish{scope,expected_version,note}               → published
 *      action=rollback{scope,to_version,note}                    → published
 * 모든 쓰기는 log_admin → 감사 체인.
 */
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/rc.php';

only_method('GET', 'POST');
$ctx = require_auth();
require_global_role($ctx, 'admin');
$adminId = (int) $ctx['user']['id'];
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

function pc_draft_out(array $d): array
{
    return [
        'key'         => (string) $d['pkey'],
        'value'       => json_decode((string) $d['value_json'], true),
        'conditions'  => $d['cond_json'] === null ? [] : json_col((string) $d['cond_json']),
        'description' => $d['description'] === null ? null : (string) $d['description'],
        'updated_at'  => iso($d['updated_at']),
    ];
}

if ($method === 'GET') {
    $scope = rc_scope_check($_GET['scope'] ?? '*');
    if (isset($_GET['diff']) && $_GET['diff'] !== '') {
        $v = v_int($_GET, 'diff', 1, 2147483647, true);
        $old = db_one('SELECT * FROM ' . T_RC_VERSIONS . ' WHERE scope = ? AND version = ?', [$scope, $v]);
        if ($old === null) {
            throw new AppError('NOT_FOUND', 404, ['field' => 'diff', 'message' => '그 버전이 없습니다.']);
        }
        $d = rc_diff(rc_snapshot_of($old), rc_snapshot_from_drafts($scope));
        json_out(['added' => $d['added'], 'removed' => $d['removed'], 'changed' => $d['changed']]);
    }
    $drafts = array_map('pc_draft_out', rc_drafts($scope));
    $latest = rc_latest($scope);
    $vers = [];
    foreach (db_all('SELECT version, source, from_version, note, published_by, published_at FROM ' . T_RC_VERSIONS
        . ' WHERE scope = ? ORDER BY version DESC LIMIT 100', [$scope]) as $r) {
        $by = db_val('SELECT username FROM ' . T_USERS . ' WHERE id = ?', [(int) $r['published_by']]);
        $vers[] = [
            'version'      => (int) $r['version'],
            'source'       => (string) $r['source'],
            'from_version' => $r['from_version'] === null ? null : (int) $r['from_version'],
            'note'         => $r['note'] === null ? null : (string) $r['note'],
            'published_by' => $by === null ? null : (string) $by,
            'published_at' => iso($r['published_at']),
        ];
    }
    json_out([
        'scope'     => $scope,
        'drafts'    => $drafts,
        'published' => $latest === null ? null : [
            'version' => (int) $latest['version'], 'etag' => (string) $latest['etag'], 'published_at' => iso($latest['published_at']),
        ],
        'versions'  => $vers,
        'dirty'     => rc_dirty($scope),
    ]);
}

// POST — 쓰기는 superadmin (H-5)
require_csrf();
require_global_role($ctx, 'superadmin');
$in = body_json();
$action = v_enum($in, 'action', ['save_draft', 'delete_draft', 'publish', 'rollback'], true);
$scope = rc_scope_check($in['scope'] ?? null);

if ($action === 'save_draft') {
    $key = rc_key_check($in['key'] ?? null);
    if (!array_key_exists('value', $in)) {
        v_fail('value', 'value 가 필요합니다.');
    }
    rc_value_check($in['value'], 'value');
    $cond = rc_conditions_check($in['conditions'] ?? null);
    $desc = v_string($in, 'description', 0, 255);
    $prev = db_one('SELECT * FROM ' . T_RC_DRAFTS . ' WHERE scope = ? AND pkey = ?', [$scope, $key]);
    if ($prev === null && (int) db_val('SELECT COUNT(*) FROM ' . T_RC_DRAFTS . ' WHERE scope = ?', [$scope]) >= RC_KEYS_MAX) {
        throw new AppError('QUOTA_EXCEEDED', 403, ['field' => 'key', 'message' => '범위당 키는 200개까지입니다.']);
    }
    $row = [
        'value_json' => (string) plat_json($in['value']), 'cond_json' => $cond === [] ? null : plat_json($cond),
        'description' => $desc === '' ? null : $desc, 'updated_by' => $adminId, 'updated_at' => now_utc(),
    ];
    if ($prev === null) {
        db_insert(T_RC_DRAFTS, array_merge(['scope' => $scope, 'pkey' => $key], $row));
    } else {
        db_update(T_RC_DRAFTS, $row, 'id = ?', [(int) $prev['id']]);
    }
    log_admin($adminId, 'plat.config.draft_save', 'rc', $scope . ':' . $key,
        $prev === null ? null : ['value' => json_decode((string) $prev['value_json'], true)],
        ['value' => $in['value']]);
    $d = db_one('SELECT * FROM ' . T_RC_DRAFTS . ' WHERE scope = ? AND pkey = ?', [$scope, $key]);
    json_out(['draft' => pc_draft_out($d)]);
}

if ($action === 'delete_draft') {
    $key = rc_key_check($in['key'] ?? null);
    $prev = db_one('SELECT * FROM ' . T_RC_DRAFTS . ' WHERE scope = ? AND pkey = ?', [$scope, $key]);
    if ($prev === null) {
        throw new AppError('NOT_FOUND', 404, ['field' => 'key']);
    }
    db_exec('DELETE FROM ' . T_RC_DRAFTS . ' WHERE id = ?', [(int) $prev['id']]);
    log_admin($adminId, 'plat.config.draft_delete', 'rc', $scope . ':' . $key,
        ['value' => json_decode((string) $prev['value_json'], true)], null);
    json_out(['deleted' => true]);
}

if ($action === 'publish') {
    $exp = v_int($in, 'expected_version', 0, 2147483647, true);
    $note = v_string($in, 'note', 0, 255);
    $p = rc_publish($scope, (int) $exp, $note === '' ? null : $note, $adminId);
    log_admin($adminId, 'plat.config.publish', 'rc', $scope, ['version' => $exp], ['version' => $p['version']], $note);
    json_out(['published' => $p]);
}

// rollback
$to = v_int($in, 'to_version', 1, 2147483647, true);
$note = v_string($in, 'note', 0, 255);
$p = rc_rollback($scope, (int) $to, $note === '' ? null : $note, $adminId);
log_admin($adminId, 'plat.config.rollback', 'rc', $scope, ['to_version' => $to], ['version' => $p['version']], $note);
json_out(['published' => $p]);
