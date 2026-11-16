<?php
declare(strict_types=1);
/**
 * GET|POST /server/api/admin/service_access.php — 서비스별 접근 규칙 · 계정별 허용 · 기능 (v1.9.0)
 * 담당 계약: 설계 116 §7 · §8 · 80 §9 · H-6
 *
 * 쿠키 세션 + CSRF + 전역 role. 읽기 admin · 쓰기 superadmin. **allowed 는 서버 판정값** — 화면이 다시 판정하지 않는다.
 * GET ?service_id[&page&per_page] → service_id, access_rule, can_list_users, features, grants{items,total,page,per_page}
 * GET ?uuid                        → uuid, systems[{service_id,service_name,access_rule,access_grant,allowed,reason,features}]
 * POST action=set_rule{service_id, access_rule, can_list_users?}        → service_id, access_rule, can_list_users
 *      action=set_grant{uuid, service_id, grant}                         → uuid, service_id, access_grant, allowed, reason, revoked_sessions
 *           (권한을 빼면 그 서비스의 세션을 회수한다 — H-6 판정 5)
 *      action=set_features_registry{service_id, features|null}           → service_id, features
 *      action=set_user_features{uuid, service_id, features|null}         → uuid, service_id, features
 */
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/accountlink.php';
require_once __DIR__ . '/../../core/accessadmin.php';
// v1.10.0 (설계 127 §3-5 — 순수 추가):
//   GET ?uuid       systems[] += level, until, source, access_level, starts_at, ends_at, access_note, groups[]
//   GET ?service_id += level_aware · grants.items[] += access_level, access_starts_at, access_ends_at
//   POST set_rule   access_rule 에 all_view, level_aware?:bool → + level_aware, affected, revoked_sessions
//   POST set_override{uuid, service_id, grant, level?, starts_at?, ends_at?, note?}
//        → uuid, service_id, access_grant, access_level, starts_at, ends_at, access_note, effective{level,until,source}, allowed, revoked_sessions
//   POST revoke{uuid, service_id, note?} = deny · 무기한 + 세션 회수 → 위와 같은 모양
//   비상 계정을 대상으로 하면 400 field uuid. allowed·level 은 서버 판정값(access_level_eval) — 화면이 다시 판정하지 않는다.

only_method('GET', 'POST');
$ctx = require_auth();
require_global_role($ctx, 'admin');
$adminId = (int) $ctx['user']['id'];
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

function sa_service(array $in): array
{
    $sid = v_string($in, 'service_id', 1, 64, true);
    admin_service_id_reject_former($sid);
    $s = db_one('SELECT * FROM ' . T_SERVICES . ' WHERE service_id = ?', [$sid]);
    if ($s === null) {
        throw new AppError('NOT_FOUND', 404, ['field' => 'service_id', 'message' => '서비스가 없습니다.']);
    }
    return $s;
}

function sa_user(array $in): array
{
    $uuid = $in['uuid'] ?? null;
    if (!is_string($uuid) || !al_is_uuid($uuid)) {
        v_fail('uuid', '회원 식별자 형식이 올바르지 않습니다.');
    }
    $u = db_one('SELECT * FROM ' . T_USERS . ' WHERE uuid = ?', [strtolower((string) $uuid)]);
    if ($u === null) {
        throw new AppError('NOT_FOUND', 404, ['field' => 'uuid', 'message' => '회원을 찾을 수 없습니다.']);
    }
    return $u;
}

/** 계정 × 서비스 판정(서버 값). 판정은 access_level_eval() 하나(설계 127 S1) — 비활성·차단·비상도 그 안에서 none 이 된다. */
function sa_verdict(array $u, ?array $us, array $s, ?array $grpRows = null): array
{
    $d = access_level_eval($u, $s, $us, $grpRows);
    return ['allowed' => access_enter_ok($d, $s), 'reason' => $d['reason'], 'decision' => $d];
}

/** set_override · revoke 응답 모양(§3-5). */
function sa_override_out(array $u, ?array $us, array $s, int $revoked): array
{
    $d = access_level_eval($u, $s, $us);
    return [
        'uuid'             => (string) $u['uuid'],
        'service_id'       => (string) $s['service_id'],
        'access_grant'     => $us === null ? 'inherit' : (string) ($us['access_grant'] ?? 'inherit'),
        'access_level'     => $us === null || ($us['access_level'] ?? null) === null ? null : (string) $us['access_level'],
        'starts_at'        => $us === null ? null : iso($us['access_starts_at'] ?? null),
        'ends_at'          => $us === null ? null : iso($us['access_ends_at'] ?? null),
        'access_note'      => $us === null || ($us['access_note'] ?? null) === null ? null : (string) $us['access_note'],
        'effective'        => ['level' => $d['level'], 'until' => $d['until'], 'source' => $d['source']],
        'allowed'          => access_enter_ok($d, $s),
        'revoked_sessions' => $revoked,
        'affected'         => ['users' => $revoked > 0 ? 1 : 0, 'revoked_sessions' => $revoked],
    ];
}

function sa_user_features(?array $us)
{
    if ($us === null || $us['features_json'] === null || $us['features_json'] === '') {
        return null;
    }
    $v = json_decode((string) $us['features_json'], true);
    return is_array($v) ? array_values($v) : [];
}

if ($method === 'GET') {
    if (isset($_GET['uuid']) && $_GET['uuid'] !== '') {
        $u = sa_user($_GET);
        $usBy = [];
        foreach (db_all('SELECT * FROM ' . T_USER_SERVICES . ' WHERE user_id = ?', [(int) $u['id']]) as $r) {
            $usBy[(string) $r['service_id']] = $r;
        }
        $systems = [];
        $grp = access_group_rows([(int) $u['id']]);
        foreach (db_all('SELECT * FROM ' . T_SERVICES . ' ORDER BY service_id ASC') as $s) {
            $sidS = (string) $s['service_id'];
            $us = $usBy[$sidS] ?? null;
            $rows = $grp[(int) $u['id']][$sidS] ?? [];
            $v = sa_verdict($u, $us, $s, $rows);
            $gkeys = [];
            foreach ($rows as $gr) {
                $gkeys[(string) $gr['group_key']] = true;
            }
            $systems[] = [
                'service_id'   => $sidS,
                'service_name' => (string) $s['service_name'],
                'access_rule'  => (string) ($s['access_rule'] ?? 'all'),
                'access_grant' => $us === null ? 'inherit' : (string) ($us['access_grant'] ?? 'inherit'),
                'allowed'      => $v['allowed'],
                'reason'       => $v['reason'],
                'features'     => features_effective($s, $us),
                // v1.10.0 — 단계 판정(서버 값)과 덮어쓰기 원값
                'level'        => $v['decision']['level'],
                'until'        => $v['decision']['until'],
                'source'       => $v['decision']['source'],
                'access_level' => $us === null || ($us['access_level'] ?? null) === null ? null : (string) $us['access_level'],
                'starts_at'    => $us === null ? null : iso($us['access_starts_at'] ?? null),
                'ends_at'      => $us === null ? null : iso($us['access_ends_at'] ?? null),
                'access_note'  => $us === null || ($us['access_note'] ?? null) === null ? null : (string) $us['access_note'],
                'groups'       => array_keys($gkeys),
            ];
        }
        json_out(['uuid' => (string) $u['uuid'], 'systems' => $systems]);
    }
    $s = sa_service($_GET);
    list($pgPage, $pgPer, $pgOff) = page_params($_GET, 50);
    $w = 'us.service_id = ? AND (us.access_grant <> ? OR us.features_json IS NOT NULL)';
    $total = (int) db_val('SELECT COUNT(*) FROM ' . T_USER_SERVICES . ' us WHERE ' . $w, [(string) $s['service_id'], 'inherit']);
    $rows = db_all('SELECT u.uuid, u.username, us.access_grant, us.features_json, us.access_level, us.access_starts_at, us.access_ends_at'
        . ' FROM ' . T_USER_SERVICES . ' us JOIN ' . T_USERS
        . ' u ON u.id = us.user_id WHERE ' . $w . ' ORDER BY u.username ASC LIMIT ' . (int) $pgPer . ' OFFSET ' . (int) $pgOff,
        [(string) $s['service_id'], 'inherit']);
    $items = [];
    foreach ($rows as $r) {
        $items[] = ['uuid' => (string) $r['uuid'], 'username' => (string) $r['username'],
            'access_grant' => (string) $r['access_grant'], 'features' => sa_user_features($r),
            'access_level' => $r['access_level'] === null ? null : (string) $r['access_level'],
            'access_starts_at' => iso($r['access_starts_at']), 'access_ends_at' => iso($r['access_ends_at'])];
    }
    json_out([
        'service_id'     => (string) $s['service_id'],
        'access_rule'    => (string) ($s['access_rule'] ?? 'all'),
        'can_list_users' => !empty($s['can_list_users']),
        'level_aware'    => (int) ($s['level_aware'] ?? 0) === 1,
        'features'       => features_registry($s),
        'grants'         => ['items' => $items, 'total' => $total, 'page' => (int) $pgPage, 'per_page' => (int) $pgPer],
    ]);
}

require_csrf();
require_global_role($ctx, 'superadmin');
$in = body_json();
$action = v_enum($in, 'action', ['set_rule', 'set_grant', 'set_features_registry', 'set_user_features', 'set_override', 'revoke'], true);
acc_require_schema();
$s = sa_service($in);
$sid = (string) $s['service_id'];

if ($action === 'set_rule') {
    $rule = v_enum($in, 'access_rule', ACCESS_RULES, true);
    $clu = array_key_exists('can_list_users', $in) ? v_bool($in, 'can_list_users', true) : !empty($s['can_list_users']);
    $la  = array_key_exists('level_aware', $in) ? (bool) v_bool($in, 'level_aware', true) : ((int) ($s['level_aware'] ?? 0) === 1);
    db_exec('UPDATE ' . T_SERVICES . ' SET access_rule = ?, can_list_users = ?, level_aware = ?, updated_at = ? WHERE id = ?',
        [$rule, $clu ? 1 : 0, $la ? 1 : 0, now_utc(), (int) $s['id']]);
    em_reconcile_service($sid);   // v1.9.1 - 접근을 잃은 계정의 비상 확인값 폐기
    // v1.10.0 (§2) — 규칙·level_aware 변경으로 입장을 잃은 계정의 이 서비스 세션을 바로 회수한다.
    $aff = acc_revoke_service_sessions($sid);
    log_admin($adminId, 'access.rule.update', 'service', $sid,
        ['access_rule' => (string) ($s['access_rule'] ?? 'all'), 'can_list_users' => !empty($s['can_list_users']),
         'level_aware' => (int) ($s['level_aware'] ?? 0) === 1],
        ['access_rule' => $rule, 'can_list_users' => $clu, 'level_aware' => $la, 'revoked_sessions' => $aff['revoked_sessions']]);
    json_out(['service_id' => $sid, 'access_rule' => $rule, 'can_list_users' => $clu, 'level_aware' => $la,
        'affected' => $aff, 'revoked_sessions' => $aff['revoked_sessions']]);
}

if ($action === 'set_features_registry') {
    $reg = null;
    if (array_key_exists('features', $in) && $in['features'] !== null) {
        $err = null;
        $reg = features_norm_registry($in['features'], $err);
        if ($reg === null) {
            throw new AppError('VALIDATION_FAILED', 400, ['field' => 'features', 'message' => (string) $err]);
        }
    }
    $before = features_registry($s);
    db_exec('UPDATE ' . T_SERVICES . ' SET features_json = ?, updated_at = ? WHERE id = ?',
        [$reg === null ? null : plat_json($reg), now_utc(), (int) $s['id']]);
    log_admin($adminId, 'features.registry.update', 'service', $sid,
        ['features' => $before === null ? null : array_column($before, 'id')], ['features' => $reg === null ? null : array_column($reg, 'id')]);
    json_out(['service_id' => $sid, 'features' => $reg]);
}

$u = sa_user($in);
emergency_target_guard($u, 'uuid');   // v1.10.0 §3-5 — 비상 계정에는 서비스 권한을 줄 수 없다(400 field uuid)
$us = service_access_us_row((int) $u['id'], $sid);
$ensureUs = function () use ($u, $sid, &$us) {
    if ($us === null) {
        // 허용·기능을 담을 행이 필요하다 — 관리자 지정은 가입을 뜻한다(status=active, 등급 오버라이드 없음).
        db_insert(T_USER_SERVICES, ['user_id' => (int) $u['id'], 'service_id' => $sid, 'status' => 'active', 'joined_at' => now_utc()]);
        $us = service_access_us_row((int) $u['id'], $sid);
    }
};

if ($action === 'set_grant') {
    $grant = v_enum($in, 'grant', ACCESS_GRANTS, true);
    $before = sa_verdict($u, $us, $s);
    $prevGrant = $us === null ? 'inherit' : (string) ($us['access_grant'] ?? 'inherit');
    $ensureUs();
    // v1.10.0 — set_grant 는 set_override 에서 level·기간을 NULL 로 비운 것과 같다(§3-5)
    db_exec('UPDATE ' . T_USER_SERVICES . ' SET access_grant = ?, access_level = NULL, access_starts_at = NULL, access_ends_at = NULL,'
        . ' access_updated_by = ?, access_updated_at = ? WHERE id = ?', [$grant, $adminId, now_utc(), (int) $us['id']]);
    $us = service_access_us_row((int) $u['id'], $sid);
    $after = sa_verdict($u, $us, $s);
    $revoked = 0;
    if ($before['allowed'] && !$after['allowed']) {
        // H-6 판정 5 — 권한을 빼면 그 서비스의 세션을 끊는다(다음 session 호출 403 보다 먼저).
        $revoked = db_exec('UPDATE ' . T_SESSIONS . ' SET revoked_at = ?, revoke_reason = ? WHERE user_id = ? AND service_id = ? AND revoked_at IS NULL',
            [now_utc(), 'access_revoked', (int) $u['id'], $sid]);
    }
    em_reconcile_user((int) $u['id']);   // v1.9.1 - 접근을 잃었으면 비상 확인값 폐기
    log_admin($adminId, 'access.grant.update', 'user', (string) $u['uuid'],
        ['service_id' => $sid, 'access_grant' => $prevGrant, 'allowed' => $before['allowed']],
        ['service_id' => $sid, 'access_grant' => $grant, 'allowed' => $after['allowed'], 'revoked_sessions' => $revoked]);
    json_out(['uuid' => (string) $u['uuid'], 'service_id' => $sid, 'access_grant' => $grant,
        'allowed' => $after['allowed'], 'reason' => $after['reason'], 'revoked_sessions' => $revoked]);
}

if ($action === 'set_override' || $action === 'revoke') {
    if ($action === 'revoke') {
        $grant = 'deny';
        $level = null;
        $starts = null;
        $ends = null;
    } else {
        $grant = v_enum($in, 'grant', ACCESS_GRANTS, true);
        $level = null;
        if (array_key_exists('level', $in) && $in['level'] !== null && $in['level'] !== '') {
            if ($grant !== 'allow') {
                throw new AppError('VALIDATION_FAILED', 400, ['field' => 'level', 'message' => '단계는 허용(allow)일 때만 정할 수 있습니다.']);
            }
            $level = v_enum($in, 'level', ACCESS_LEVELS, true);
        }
        list($starts, $ends) = acc_period($in);
        if ($grant === 'inherit') {
            $starts = null;
            $ends = null;
        }
    }
    $note = array_key_exists('note', $in) ? v_string($in, 'note', 0, 255, false) : null;
    $note = $note === null || trim($note) === '' ? null : trim($note);
    $before = sa_verdict($u, $us, $s);
    $prev = $us === null ? [] : ['access_grant' => (string) $us['access_grant'], 'access_level' => $us['access_level'] ?? null,
        'starts_at' => $us['access_starts_at'] ?? null, 'ends_at' => $us['access_ends_at'] ?? null];
    $ensureUs();
    db_exec('UPDATE ' . T_USER_SERVICES . ' SET access_grant = ?, access_level = ?, access_starts_at = ?, access_ends_at = ?,'
        . ' access_note = ?, access_updated_by = ?, access_updated_at = ? WHERE id = ?',
        [$grant, $level, $starts, $ends, $note, $adminId, now_utc(), (int) $us['id']]);
    $us = service_access_us_row((int) $u['id'], $sid);
    $after = sa_verdict($u, $us, $s);
    $revoked = 0;
    if ($action === 'revoke' || ($before['allowed'] && !$after['allowed'])) {
        // H-6 판정 5 — 입장을 잃으면 그 서비스의 세션을 바로 끊는다. revoke 는 언제나 끊는다.
        $revoked = db_exec('UPDATE ' . T_SESSIONS . ' SET revoked_at = ?, revoke_reason = ? WHERE user_id = ? AND service_id = ?'
            . ' AND revoked_at IS NULL', [now_utc(), 'access_revoked', (int) $u['id'], $sid]);
    }
    em_reconcile_user((int) $u['id']);
    log_admin($adminId, $action === 'revoke' ? 'access.revoke' : 'access.override.set', 'user', (string) $u['uuid'],
        array_merge(['service_id' => $sid, 'level' => $before['decision']['level']], $prev),
        ['service_id' => $sid, 'access_grant' => $grant, 'access_level' => $level, 'starts_at' => $starts, 'ends_at' => $ends,
         'level' => $after['decision']['level'], 'revoked_sessions' => $revoked], $note);
    $o = sa_override_out($u, $us, $s, $revoked);
    json_out([
        'uuid' => $o['uuid'], 'service_id' => $o['service_id'], 'access_grant' => $o['access_grant'],
        'access_level' => $o['access_level'], 'starts_at' => $o['starts_at'], 'ends_at' => $o['ends_at'],
        'access_note' => $o['access_note'], 'effective' => $o['effective'], 'allowed' => $o['allowed'],
        'revoked_sessions' => $o['revoked_sessions'], 'affected' => $o['affected'],
    ]);
}

// set_user_features
$feat = null;
if (array_key_exists('features', $in) && $in['features'] !== null) {
    $f = $in['features'];
    if (!is_array($f) || ($f !== [] && array_keys($f) !== range(0, count($f) - 1)) || count($f) > FEATURES_MAX) {
        throw new AppError('VALIDATION_FAILED', 400, ['field' => 'features', 'message' => 'features 는 기능 id 배열이거나 null 입니다.']);
    }
    $feat = [];
    foreach ($f as $id) {
        if (!is_string($id) || preg_match('/^[a-z0-9_.]{1,64}$/', $id) !== 1) {
            throw new AppError('VALIDATION_FAILED', 400, ['field' => 'features', 'message' => '기능 id 는 [a-z0-9_.] 1~64자입니다.']);
        }
        $feat[$id] = true;
    }
    $feat = array_keys($feat);
}
$prev = sa_user_features($us);
$ensureUs();
db_exec('UPDATE ' . T_USER_SERVICES . ' SET features_json = ? WHERE id = ?', [$feat === null ? null : plat_json($feat), (int) $us['id']]);
log_admin($adminId, 'features.user.update', 'user', (string) $u['uuid'],
    ['service_id' => $sid, 'features' => $prev], ['service_id' => $sid, 'features' => $feat]);
json_out(['uuid' => (string) $u['uuid'], 'service_id' => $sid, 'features' => $feat]);
