<?php
declare(strict_types=1);

/**
 * core/accessadmin.php — 접근 단계 관리 API 공용 조각 (v1.10.0)
 * 담당 계약: `_workspace/127_architect_v1100_design.md` §3-5 · §2(기간·회수) · 판정 D-367
 *
 * 부르는 곳: api/admin/access_groups.php · api/admin/service_access.php · api/admin/access_matrix.php
 * 판정은 하지 않는다 — 판정은 core/access.php 의 access_level_eval() 하나(S1). 여기는 입력 검증·조회·회수만.
 *
 * PHP 7.2 호환.
 */

if (!defined('ULOGIN_BOOTSTRAPPED')) {
    header_remove('X-Powered-By');
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/accountlink.php';

/** 0010 이 적용됐는가. 아니면 관리 API 는 503 대신 명시적 오류(설정 문제는 조용히 넘어가지 않는다, D-53). */
function acc_require_schema(): void
{
    if (!defined('T_ACCESS_GROUPS') || !db_table_exists(T_ACCESS_GROUPS) || !db_column_exists(T_USER_SERVICES, 'access_level')) {
        error_log('[ulogin] access admin API called before migration 0010');
        throw new AppError('SERVER_ERROR', 500);
    }
}

/**
 * 기간 입력. ISO8601 UTC → DB DATETIME. 없음·null → null. ends_at > starts_at 아니면 400 field ends_at.
 * @return array{0:?string,1:?string}
 */
function acc_period(array $in): array
{
    $out = [];
    foreach (['starts_at', 'ends_at'] as $k) {
        if (!array_key_exists($k, $in) || $in[$k] === null || $in[$k] === '') {
            $out[] = null;
            continue;
        }
        $v = al_parse_iso($in[$k]);
        if ($v === null) {
            throw new AppError('VALIDATION_FAILED', 400, ['field' => $k, 'message' => '시각은 ISO8601(UTC) 형식이어야 합니다.']);
        }
        $out[] = $v;
    }
    if ($out[0] !== null && $out[1] !== null && strcmp($out[1], $out[0]) <= 0) {
        throw new AppError('VALIDATION_FAILED', 400, ['field' => 'ends_at', 'message' => '끝 시각은 시작 시각보다 뒤여야 합니다.']);
    }
    return $out;
}

/** uuid 로 계정. 없으면 404 field uuid. */
function acc_user(array $in, string $key = 'uuid'): array
{
    $uuid = $in[$key] ?? null;
    if (!is_string($uuid) || !al_is_uuid($uuid)) {
        v_fail($key, '회원 식별자 형식이 올바르지 않습니다.');
    }
    $u = db_one('SELECT * FROM ' . T_USERS . ' WHERE uuid = ?', [strtolower((string) $uuid)]);
    if ($u === null) {
        throw new AppError('NOT_FOUND', 404, ['field' => $key, 'message' => '회원을 찾을 수 없습니다.']);
    }
    return $u;
}

/** service_id 로 서비스(상태 무관). 옛 id 는 거절(관리 API 규약). 없으면 404. */
function acc_service($sidIn): array
{
    $sid = is_string($sidIn) ? trim($sidIn) : '';
    if ($sid === '' || strlen($sid) > 64) {
        v_fail('service_id', 'service_id 가 필요합니다.');
    }
    admin_service_id_reject_former($sid);
    $s = db_one('SELECT * FROM ' . T_SERVICES . ' WHERE service_id = ?', [$sid]);
    if ($s === null) {
        throw new AppError('NOT_FOUND', 404, ['field' => 'service_id', 'message' => '서비스가 없습니다.']);
    }
    return $s;
}

/** 그룹 행. 없으면 404 field group_key. */
function acc_group($key): array
{
    if (!is_string($key) || preg_match(ACCESS_GROUP_KEY_RE, $key) !== 1) {
        v_fail('group_key', '그룹 키는 영문 소문자로 시작하는 [a-z0-9_.-] 1~64자입니다.');
    }
    $g = db_one('SELECT * FROM ' . T_ACCESS_GROUPS . ' WHERE group_key = ?', [$key]);
    if ($g === null) {
        throw new AppError('NOT_FOUND', 404, ['field' => 'group_key', 'message' => '그룹이 없습니다.']);
    }
    return $g;
}

/** 그룹의 서비스 행 출력 모양. */
function acc_group_service_out(array $r): array
{
    $sc = null;
    if ($r['scopes_json'] !== null && $r['scopes_json'] !== '') {
        $v = json_decode((string) $r['scopes_json'], true);
        $sc = is_array($v) ? array_values($v) : [];
    }
    return [
        'service_id' => (string) $r['service_id'],
        'level'      => (string) $r['level'],
        'scopes'     => $sc,
        'starts_at'  => iso($r['starts_at']),
        'ends_at'    => iso($r['ends_at']),
    ];
}

/** 그룹 출력 모양(목록·단건 공통). */
function acc_group_out(array $g): array
{
    $services = [];
    foreach (db_all('SELECT * FROM ' . T_ACCESS_GROUP_SERVICES . ' WHERE group_id = ? ORDER BY service_id ASC', [(int) $g['id']]) as $r) {
        $services[] = acc_group_service_out($r);
    }
    return [
        'group_key'    => (string) $g['group_key'],
        'name'         => (string) $g['name'],
        'description'  => $g['description'] === null ? null : (string) $g['description'],
        'status'       => (string) $g['status'],
        'member_count' => (int) db_val('SELECT COUNT(*) FROM ' . T_ACCESS_GROUP_MEMBERS . ' WHERE group_id = ?', [(int) $g['id']]),
        'services'     => $services,
        'created_at'   => iso($g['created_at']),
        'updated_at'   => iso($g['updated_at']),
    ];
}

/** 그룹 구성원 user_id 전부. */
function acc_group_member_ids(int $groupId): array
{
    return array_map('intval', array_column(db_all('SELECT user_id FROM ' . T_ACCESS_GROUP_MEMBERS . ' WHERE group_id = ?', [$groupId]),
        'user_id'));
}

/** 그룹의 서비스 id 전부. */
function acc_group_service_ids(int $groupId): array
{
    return array_column(db_all('SELECT service_id FROM ' . T_ACCESS_GROUP_SERVICES . ' WHERE group_id = ?', [$groupId]), 'service_id');
}

/**
 * 서비스 하나의 살아 있는 세션을 재판정해, 입장을 잃은 계정의 세션을 회수한다(규칙·level_aware 변경 뒤).
 * @return array{users:int, revoked_sessions:int}
 */
function acc_revoke_service_sessions(string $sid): array
{
    $ids = array_map('intval', array_column(db_all('SELECT DISTINCT user_id FROM ' . T_SESSIONS
        . ' WHERE service_id = ? AND revoked_at IS NULL AND expires_at > ?', [$sid, now_utc()]), 'user_id'));
    if ($ids === []) {
        return ['users' => 0, 'revoked_sessions' => 0];
    }
    $before = [];
    foreach ($ids as $uid) {
        $before[$uid][$sid] = true;
    }
    $users = 0;
    $snap = access_enter_snapshot($ids, [$sid]);
    foreach ($ids as $uid) {
        if (empty($snap[$uid][$sid])) {
            $users++;
        }
    }
    return ['users' => $users, 'revoked_sessions' => access_revoke_lost($before)];
}

/**
 * 조작 전 스냅숏과 비교해 영향 계정 수와 회수 수를 낸다. affected{users, revoked_sessions}.
 * users = 입장 판정이 바뀐 계정 수(true↔false). 회수는 true→false 만.
 */
function acc_affected(array $before): array
{
    $userIds = array_keys($before);
    $sids = [];
    foreach ($before as $m) {
        foreach (array_keys($m) as $sid) {
            $sids[(string) $sid] = true;
        }
    }
    $after = access_enter_snapshot($userIds, array_keys($sids));
    $changed = 0;
    foreach ($before as $uid => $m) {
        foreach ($m as $sid => $was) {
            if ((bool) $was !== !empty($after[$uid][$sid])) {
                $changed++;
                break;
            }
        }
    }
    return ['users' => $changed, 'revoked_sessions' => access_revoke_lost($before)];
}
