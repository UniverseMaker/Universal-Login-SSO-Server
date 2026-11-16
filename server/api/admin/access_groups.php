<?php
declare(strict_types=1);
/**
 * GET|POST /server/api/admin/access_groups.php — 접근 단계 그룹 (v1.10.0)
 * 담당 계약: `_workspace/127_architect_v1100_design.md` §3-5 · §1(상한) · §5(감사) · 판정 D-367
 *
 * 쿠키 세션 + CSRF + 전역 role. 읽기 admin · 쓰기 superadmin. 서비스 토큰은 403(H186-1, ctx_global_role).
 * GET ?page&per_page≤100      → items[{group_key,name,description,status,member_count,services[…]}], total, page, per_page
 * GET ?group_key&page          → group{…}, members{items[{uuid,username,name,starts_at,ends_at}], total, page, per_page}
 * POST create{group_key,name,description?}                     → group
 *      update{group_key,name?,description?,status?}             → group, affected{users,revoked_sessions}, revoked_sessions
 *      delete{group_key,confirm:group_key}                      → deleted:true, affected{…}, revoked_sessions
 *      add_members|remove_members{group_key,uuids[≤100],starts_at?,ends_at?}
 *                                                               → added|removed:n, skipped[{uuid,reason}], affected{…}, revoked_sessions
 *      set_service{group_key,service_id,level:view|use|admin|none,scopes:[…]|null,starts_at?,ends_at?}  (none = 행 삭제)
 *                                                               → group_key,service_id,level,scopes,starts_at,ends_at, affected{…}, revoked_sessions
 * 판정은 하지 않는다 — 영향·회수 계산은 core/access.php 의 access_level_eval() 하나를 거친다(S1).
 */
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/accessadmin.php';

only_method('GET', 'POST');
$ctx = require_auth();
require_global_role($ctx, 'admin');
acc_require_schema();
$adminId = (int) $ctx['user']['id'];
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    if (isset($_GET['group_key']) && $_GET['group_key'] !== '') {
        $g = acc_group($_GET['group_key']);
        list($pg, $per, $off) = page_params($_GET, 50);
        $total = (int) db_val('SELECT COUNT(*) FROM ' . T_ACCESS_GROUP_MEMBERS . ' WHERE group_id = ?', [(int) $g['id']]);
        $items = [];
        foreach (db_all('SELECT u.uuid, u.username, u.name, m.starts_at, m.ends_at FROM ' . T_ACCESS_GROUP_MEMBERS . ' m JOIN '
            . T_USERS . ' u ON u.id = m.user_id WHERE m.group_id = ? ORDER BY u.username ASC LIMIT ' . (int) $per . ' OFFSET ' . (int) $off,
            [(int) $g['id']]) as $r) {
            $items[] = ['uuid' => (string) $r['uuid'], 'username' => (string) $r['username'],
                'name' => $r['name'] === null || $r['name'] === '' ? null : (string) $r['name'],
                'starts_at' => iso($r['starts_at']), 'ends_at' => iso($r['ends_at'])];
        }
        json_out(['group' => acc_group_out($g),
            'members' => ['items' => $items, 'total' => $total, 'page' => (int) $pg, 'per_page' => (int) $per]]);
    }
    list($pg, $per, $off) = page_params($_GET, 50);
    $total = (int) db_val('SELECT COUNT(*) FROM ' . T_ACCESS_GROUPS);
    $items = [];
    foreach (db_all('SELECT * FROM ' . T_ACCESS_GROUPS . ' ORDER BY group_key ASC LIMIT ' . (int) $per . ' OFFSET ' . (int) $off) as $g) {
        $items[] = acc_group_out($g);
    }
    json_out(['items' => $items, 'total' => $total, 'page' => (int) $pg, 'per_page' => (int) $per]);
}

require_csrf();
if (!role_gte(ctx_global_role($ctx), 'superadmin')) {
    throw new AppError('FORBIDDEN', 403, ['message' => '최고관리자만 바꿀 수 있습니다.']);
}
$in = body_json();
$action = v_enum($in, 'action', ['create', 'update', 'delete', 'add_members', 'remove_members', 'set_service'], true);
$now = now_utc();

/** 그룹의 (구성원 × 서비스) 입장 스냅숏 — 조작 전 값. */
function ag_snapshot(int $groupId, ?array $userIds = null, ?array $sids = null): array
{
    $u = $userIds === null ? acc_group_member_ids($groupId) : $userIds;
    $s = $sids === null ? acc_group_service_ids($groupId) : $sids;
    return access_enter_snapshot($u, $s);
}

function ag_name(array $in, bool $required): ?string
{
    if (!$required && !array_key_exists('name', $in)) {
        return null;
    }
    $n = features_name($in['name'] ?? null);
    if ($n === null) {
        v_fail('name', '그룹 이름이 필요합니다.');
    }
    return $n;
}

function ag_desc(array $in): ?string
{
    if (!array_key_exists('description', $in) || $in['description'] === null) {
        return null;
    }
    if (!is_string($in['description'])) {
        v_fail('description', '설명은 문자열이어야 합니다.');
    }
    $s = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $in['description']));
    return $s === '' ? null : mb_substr($s, 0, 255, 'UTF-8');
}

if ($action === 'create') {
    $key = $in['group_key'] ?? null;
    if (!is_string($key) || preg_match(ACCESS_GROUP_KEY_RE, $key) !== 1) {
        v_fail('group_key', '그룹 키는 영문 소문자로 시작하는 [a-z0-9_.-] 1~64자입니다.');
    }
    $name = ag_name($in, true);
    $desc = ag_desc($in);
    if ((int) db_val('SELECT COUNT(*) FROM ' . T_ACCESS_GROUPS) >= ACCESS_GROUPS_MAX) {
        throw new AppError('VALIDATION_FAILED', 400, ['field' => 'group_key', 'message' => '그룹은 ' . ACCESS_GROUPS_MAX . '개까지 만들 수 있습니다.']);
    }
    if (db_val('SELECT id FROM ' . T_ACCESS_GROUPS . ' WHERE group_key = ?', [$key]) !== null) {
        throw new AppError('CONFLICT', 409, ['field' => 'group_key', 'message' => '이미 있는 그룹 키입니다.']);
    }
    try {
        db_insert(T_ACCESS_GROUPS, ['group_key' => $key, 'name' => $name, 'description' => $desc, 'status' => 'active',
            'created_by' => $adminId, 'created_at' => $now, 'updated_at' => $now]);
    } catch (Throwable $e) {
        if (db_is_duplicate($e)) {
            throw new AppError('CONFLICT', 409, ['field' => 'group_key', 'message' => '이미 있는 그룹 키입니다.']);
        }
        throw $e;
    }
    $g = acc_group($key);
    log_admin($adminId, 'access.group.create', 'access_group', $key, null, ['name' => $name, 'description' => $desc]);
    json_out(['group' => acc_group_out($g)]);
}

$g = acc_group($in['group_key'] ?? null);
$gid = (int) $g['id'];
$key = (string) $g['group_key'];

if ($action === 'update') {
    $set = [];
    $before = [];
    $after = [];
    if (array_key_exists('name', $in)) {
        $set['name'] = ag_name($in, true);
    }
    if (array_key_exists('description', $in)) {
        $set['description'] = ag_desc($in);
    }
    if (array_key_exists('status', $in)) {
        $set['status'] = v_enum($in, 'status', ['active', 'disabled'], true);
    }
    foreach ($set as $k => $v) {
        if ((string) $g[$k] !== (string) $v) {
            $before[$k] = $g[$k];
            $after[$k] = $v;
        }
    }
    $snap = ag_snapshot($gid);
    if ($after !== []) {
        $after2 = $after;
        $after2['updated_at'] = $now;
        db_update(T_ACCESS_GROUPS, $after2, 'id = ?', [$gid]);
    }
    $aff = acc_affected($snap);
    if ($after !== []) {
        log_admin($adminId, 'access.group.update', 'access_group', $key, $before, $after + ['revoked_sessions' => $aff['revoked_sessions']]);
    }
    json_out(['group' => acc_group_out(acc_group($key)), 'affected' => $aff, 'revoked_sessions' => $aff['revoked_sessions']]);
}

if ($action === 'delete') {
    if (($in['confirm'] ?? null) !== $key) {
        throw new AppError('VALIDATION_FAILED', 400, ['field' => 'confirm', 'message' => '확인 칸에 그룹 키를 그대로 적어 주세요.']);
    }
    $snap = ag_snapshot($gid);
    $counts = ['members' => count(acc_group_member_ids($gid)), 'services' => count(acc_group_service_ids($gid))];
    db_tx(function () use ($gid) {
        db_exec('DELETE FROM ' . T_ACCESS_GROUP_MEMBERS . ' WHERE group_id = ?', [$gid]);
        db_exec('DELETE FROM ' . T_ACCESS_GROUP_SERVICES . ' WHERE group_id = ?', [$gid]);
        db_exec('DELETE FROM ' . T_ACCESS_GROUPS . ' WHERE id = ?', [$gid]);
    });
    $aff = acc_affected($snap);
    log_admin($adminId, 'access.group.delete', 'access_group', $key, ['name' => (string) $g['name']] + $counts,
        ['deleted' => true, 'revoked_sessions' => $aff['revoked_sessions']]);
    json_out(['deleted' => true, 'affected' => $aff, 'revoked_sessions' => $aff['revoked_sessions']]);
}

if ($action === 'add_members' || $action === 'remove_members') {
    $uuids = $in['uuids'] ?? null;
    if (!is_array($uuids) || $uuids === [] || count($uuids) > 100 || array_keys($uuids) !== range(0, count($uuids) - 1)) {
        throw new AppError('VALIDATION_FAILED', 400, ['field' => 'uuids', 'message' => 'uuids 는 1~100개의 회원 식별자 배열입니다.']);
    }
    $list = [];
    foreach ($uuids as $x) {
        if (!is_string($x) || !al_is_uuid($x)) {
            throw new AppError('VALIDATION_FAILED', 400, ['field' => 'uuids', 'message' => '회원 식별자 형식이 올바르지 않습니다.']);
        }
        $list[strtolower($x)] = true;
    }
    list($starts, $ends) = $action === 'add_members' ? acc_period($in) : [null, null];
    $users = [];
    $ph = implode(',', array_fill(0, count($list), '?'));
    foreach (db_all('SELECT * FROM ' . T_USERS . ' WHERE uuid IN (' . $ph . ')', array_keys($list)) as $u) {
        $users[strtolower((string) $u['uuid'])] = $u;
    }
    $skipped = [];
    $targets = [];
    foreach (array_keys($list) as $uuid) {
        if (!isset($users[$uuid])) {
            $skipped[] = ['uuid' => $uuid, 'reason' => 'not_found'];
        } elseif (is_emergency_user($users[$uuid])) {
            $skipped[] = ['uuid' => $uuid, 'reason' => 'emergency_account'];
        } else {
            $targets[(int) $users[$uuid]['id']] = $uuid;
        }
    }
    $snap = ag_snapshot($gid, array_keys($targets));
    $n = 0;
    $done = [];
    db_tx(function () use ($action, $gid, $targets, $starts, $ends, $adminId, $now, &$n, &$skipped, &$done) {
        $count = (int) db_val('SELECT COUNT(*) FROM ' . T_ACCESS_GROUP_MEMBERS . ' WHERE group_id = ?', [$gid]);
        foreach ($targets as $uid => $uuid) {
            $has = db_val('SELECT id FROM ' . T_ACCESS_GROUP_MEMBERS . ' WHERE group_id = ? AND user_id = ?', [$gid, $uid]);
            if ($action === 'remove_members') {
                if ($has === null) {
                    $skipped[] = ['uuid' => $uuid, 'reason' => 'not_member'];
                    continue;
                }
                db_exec('DELETE FROM ' . T_ACCESS_GROUP_MEMBERS . ' WHERE id = ?', [(int) $has]);
                $n++;
                $done[] = $uuid;
                continue;
            }
            if ($has !== null) {
                // 이미 구성원 — 기간만 새 값으로(같은 요청을 두 번 보내도 결과가 같다)
                db_exec('UPDATE ' . T_ACCESS_GROUP_MEMBERS . ' SET starts_at = ?, ends_at = ? WHERE id = ?', [$starts, $ends, (int) $has]);
                $n++;
                $done[] = $uuid;
                continue;
            }
            if ($count >= ACCESS_GROUP_MEMBERS_MAX) {
                $skipped[] = ['uuid' => $uuid, 'reason' => 'group_full'];
                continue;
            }
            if ((int) db_val('SELECT COUNT(*) FROM ' . T_ACCESS_GROUP_MEMBERS . ' WHERE user_id = ?', [$uid]) >= ACCESS_USER_GROUPS_MAX) {
                $skipped[] = ['uuid' => $uuid, 'reason' => 'user_group_limit'];
                continue;
            }
            db_insert(T_ACCESS_GROUP_MEMBERS, ['group_id' => $gid, 'user_id' => $uid, 'starts_at' => $starts, 'ends_at' => $ends,
                'added_by' => $adminId, 'created_at' => $now]);
            $count++;
            $n++;
            $done[] = $uuid;
        }
    });
    $aff = acc_affected($snap);
    if ($n > 0) {
        log_admin($adminId, $action === 'add_members' ? 'access.group.members.add' : 'access.group.members.remove', 'access_group', $key,
            null, ['uuids' => $n, 'starts_at' => $starts, 'ends_at' => $ends, 'revoked_sessions' => $aff['revoked_sessions']],
            implode(',', array_slice($done, 0, 5)) . (count($done) > 5 ? ' …' : ''));
    }
    json_out([$action === 'add_members' ? 'added' : 'removed' => $n, 'skipped' => $skipped, 'affected' => $aff,
        'revoked_sessions' => $aff['revoked_sessions']]);
}

// set_service
$svc = acc_service($in['service_id'] ?? null);
$sid = (string) $svc['service_id'];
$level = v_enum($in, 'level', ['view', 'use', 'admin', 'none'], true);
$scopes = null;
if (array_key_exists('scopes', $in) && $in['scopes'] !== null) {
    $sc = $in['scopes'];
    if (!is_array($sc) || ($sc !== [] && array_keys($sc) !== range(0, count($sc) - 1)) || count($sc) > FEATURES_MAX) {
        throw new AppError('VALIDATION_FAILED', 400, ['field' => 'scopes', 'message' => 'scopes 는 기능 id 배열이거나 null 입니다.']);
    }
    $seen = [];
    foreach ($sc as $id) {
        if (!is_string($id) || preg_match('/^[a-z0-9_.]{1,64}$/', $id) !== 1) {
            throw new AppError('VALIDATION_FAILED', 400, ['field' => 'scopes', 'message' => '기능 id 는 [a-z0-9_.] 1~64자입니다.']);
        }
        $seen[$id] = true;
    }
    $scopes = array_keys($seen);
}
list($starts, $ends) = acc_period($in);
$prevRow = db_one('SELECT * FROM ' . T_ACCESS_GROUP_SERVICES . ' WHERE group_id = ? AND service_id = ?', [$gid, $sid]);
$snap = ag_snapshot($gid, null, [$sid]);
if ($level === 'none') {
    db_exec('DELETE FROM ' . T_ACCESS_GROUP_SERVICES . ' WHERE group_id = ? AND service_id = ?', [$gid, $sid]);
    $scopes = null;
    $starts = null;
    $ends = null;
} elseif ($prevRow === null) {
    db_insert(T_ACCESS_GROUP_SERVICES, ['group_id' => $gid, 'service_id' => $sid, 'level' => $level,
        'scopes_json' => $scopes === null ? null : plat_json($scopes), 'starts_at' => $starts, 'ends_at' => $ends,
        'updated_by' => $adminId, 'updated_at' => $now]);
} else {
    db_exec('UPDATE ' . T_ACCESS_GROUP_SERVICES . ' SET level = ?, scopes_json = ?, starts_at = ?, ends_at = ?, updated_by = ?, updated_at = ?'
        . ' WHERE id = ?', [$level, $scopes === null ? null : plat_json($scopes), $starts, $ends, $adminId, $now, (int) $prevRow['id']]);
}
$aff = acc_affected($snap);
log_admin($adminId, 'access.group.service.set', 'access_group', $key,
    $prevRow === null ? ['service_id' => $sid, 'level' => 'none'] : ['service_id' => $sid] + acc_group_service_out($prevRow),
    ['service_id' => $sid, 'level' => $level, 'scopes' => $scopes, 'starts_at' => $starts, 'ends_at' => $ends,
     'revoked_sessions' => $aff['revoked_sessions']]);
json_out(['group_key' => $key, 'service_id' => $sid, 'level' => $level, 'scopes' => $scopes,
    'starts_at' => iso($starts), 'ends_at' => iso($ends), 'affected' => $aff, 'revoked_sessions' => $aff['revoked_sessions']]);
