<?php
declare(strict_types=1);

/**
 * core/access.php — 서비스별 접근 판정 · 단계(access level) · 기능/scopes (v1.9.0 → v1.10.0)
 * 담당 계약: `_workspace/127_architect_v1100_design.md` §2 · §3 · §7(S1~S11) · 판정 D-367 · 80 §5 · 116 §7 · D-354 H-6
 *
 *   access_level_decide()     단계 규칙 본체 — **유일한** 구현. 순수 함수(DB·요청을 보지 않는다). §2 의 1~9 번 그대로
 *   access_level_eval()       행 로더(그룹 행을 읽어 decide 에 넘긴다). 읽기 소비자 전부가 이것을 쓴다
 *   access_enter_ok()         입장 판정 = RANK ≥ use 또는 (view 이고 services.level_aware=1)  (K4)
 *   require_service_access()  거절 집행 + login_logs 기록. **집행 지점은 이 함수만 부른다**
 *       집행 6곳: auth/login · sso/authorize · sso/authorize_session · auth/exchange · auth/session
 *                 · platform user 갈래(core/platauth.php). + build_payload() 방어층(조용히 {})
 *   service_access_map()      bool 맵 {id: RANK ≥ use}. view 는 false(단계를 모르는 소비자는 막는 쪽, §2)
 *   access_emit()             응답 `access_level` · `access` 순수 추가(login·exchange·session·refresh)
 *   features_effective()      `data.features` — 등록부 ∩ 사용자 지정(또는 default:true). 전역 role 로 우회하지 않는다(D-326)
 *
 * 🔴 판정에는 **전역 role**(users.role)만 넘긴다. `$ctx['grades']['role']`(서비스 오버라이드 반영)을 넘기면
 *    어떤 서비스에서 admin 오버라이드를 받은 계정이 admin 규칙 서비스 전부에 들어간다(80 §3-3).
 * 🔴 호출부에서 access_level_decide()·service_access_decide() 를 직접 부르지 마라(T1-1) — 거절 기록이 빠지는 경로가 생긴다.
 *
 * PHP 7.2 호환.
 */

if (!defined('ULOGIN_BOOTSTRAPPED')) {
    header_remove('X-Powered-By');
    http_response_code(404);
    exit;
}

const ACCESS_RULES  = ['all', 'all_view', 'granted', 'admin'];
const ACCESS_GRANTS = ['inherit', 'allow', 'deny'];
const ACCESS_LEVELS = ['view', 'use', 'admin'];
const ACCESS_RANK   = ['none' => 0, 'view' => 10, 'use' => 20, 'admin' => 30];
const FEATURES_MAX  = 64;

// 상한(설계 127 §1). 넘으면 400 VALIDATION_FAILED.
const ACCESS_GROUPS_MAX        = 200;
const ACCESS_GROUP_MEMBERS_MAX = 5000;
const ACCESS_USER_GROUPS_MAX   = 50;
const ACCESS_GROUP_KEY_RE      = '/^[a-z][a-z0-9_.-]{0,63}$/';

/**
 * v1.9.0 규칙(bool). **판정에 쓰지 않는다** — v1.10.0 의 단계 판정(access_level_decide)이 대신한다.
 * 0010 직후 동치(K6 · 시험 T2-2)를 재는 기준식으로만 남긴다. access.php 밖에서 부르지 마라(T1-1).
 * @return array{allowed:bool, reason:string}
 */
function service_access_decide(string $rule, string $grant, string $globalRole): array
{
    if ($grant === 'deny') {
        return ['allowed' => false, 'reason' => 'denied_by_grant'];
    }
    if ($rule === 'all') {
        return ['allowed' => true, 'reason' => 'rule_all'];
    }
    if ($rule === 'granted') {
        return $grant === 'allow'
            ? ['allowed' => true, 'reason' => 'granted_allow']
            : ['allowed' => false, 'reason' => 'not_granted'];
    }
    if ($rule === 'admin') {
        return role_gte($globalRole, 'admin')
            ? ['allowed' => true, 'reason' => 'admin_role']
            : ['allowed' => false, 'reason' => 'not_admin'];
    }
    return ['allowed' => false, 'reason' => 'unknown_rule'];
}

// =====================================================================
// 단계 판정 (설계 127 §2)
// =====================================================================

/** win(s,e,now) = (s IS NULL OR s <= now) AND (e IS NULL OR now < e). 깨진 시각은 창 밖(fail-closed). */
function access_win($s, $e, int $now): bool
{
    if ($s !== null && $s !== '') {
        $st = ts((string) $s);
        if ($st === null || $st > $now) {
            return false;
        }
    }
    if ($e !== null && $e !== '') {
        $et = ts((string) $e);
        if ($et === null || $now >= $et) {
            return false;
        }
    }
    return true;
}

function access_rank(string $level): int
{
    return isset(ACCESS_RANK[$level]) ? (int) ACCESS_RANK[$level] : 0;
}

/**
 * 단계 판정의 **유일한** 규칙 구현 (설계 127 §2 1~9). 순수 함수.
 *
 * @param array|null $user      users 행(status · account_kind)
 * @param array|null $svc       services 행(status · access_rule · features_json · level_aware)
 * @param array|null $us        user_services 행(없으면 null = inherit)
 * @param array      $grpRows   이 계정 × 이 서비스의 그룹 행들:
 *                              {group_key, group_status, m_starts_at, m_ends_at, level, scopes_json, gs_starts_at, gs_ends_at}
 * @param int        $now       epoch
 * @param string     $globalRole 전역 role(users.role). 7번에서만 쓴다
 * @return array{level:string, scopes:?array, until:?string, source:string, reason:string}
 */
function access_level_decide(?array $user, ?array $svc, ?array $us, array $grpRows, int $now, string $globalRole): array
{
    $out = function (string $level, string $source, string $reason, ?string $until = null) use ($svc): array {
        return ['level' => $level, 'scopes' => ($svc === null || features_registry($svc) === null) ? null : [],
                'until' => $until, 'source' => $source, 'reason' => $reason];
    };
    // 1 서비스 없음·비활성
    if ($svc === null || (string) ($svc['status'] ?? '') !== 'active') {
        return $out('none', 'rule', 'service_disabled');
    }
    // 2 계정 비활성
    if ($user === null || (string) ($user['status'] ?? '') !== 'active') {
        return $out('none', 'rule', 'account_inactive');
    }
    // 3 비상 계정은 SSO 대상이 아니다(§6-2)
    if ((string) ($user['account_kind'] ?? 'normal') === 'emergency') {
        return $out('none', 'rule', 'emergency_account');
    }
    // 4 서비스 단위 차단(D-31)
    if ($us !== null && (string) ($us['status'] ?? 'active') !== 'active') {
        return $out('none', 'rule', 'membership_blocked');
    }

    // until: 이 서비스에 걸린 us·member·gs 행의 시작/끝 중 now 보다 뒤인 가장 이른 시각
    $until = null;
    $cands = [];
    if ($us !== null) {
        $cands[] = $us['access_starts_at'] ?? null;
        $cands[] = $us['access_ends_at'] ?? null;
    }
    foreach ($grpRows as $g) {
        $cands[] = $g['m_starts_at'] ?? null;
        $cands[] = $g['m_ends_at'] ?? null;
        $cands[] = $g['gs_starts_at'] ?? null;
        $cands[] = $g['gs_ends_at'] ?? null;
    }
    $untilTs = null;
    foreach ($cands as $c) {
        $t = ($c === null || $c === '') ? null : ts((string) $c);
        if ($t !== null && $t > $now && ($untilTs === null || $t < $untilTs)) {
            $untilTs = $t;
        }
    }
    if ($untilTs !== null) {
        $until = gmdate('Y-m-d\TH:i:s\Z', $untilTs);
    }

    // 5 deny 우선(기간 안). superadmin 도 막는다(H-6)
    $g  = $us === null ? 'inherit' : (string) ($us['access_grant'] ?? 'inherit');
    if (!in_array($g, ACCESS_GRANTS, true)) {
        error_log('[ulogin] user_services.access_grant has an unknown value. service=' . (string) $svc['service_id']);
        return $out('none', 'rule', 'unknown_grant', $until);
    }
    $gw = $us === null ? true : access_win($us['access_starts_at'] ?? null, $us['access_ends_at'] ?? null, $now);
    if ($g === 'deny' && $gw) {
        return $out('none', 'override', 'denied_by_grant', $until);
    }
    // 6 모르는 규칙 — fail-closed
    $rule = (string) ($svc['access_rule'] ?? 'all');
    if (!in_array($rule, ACCESS_RULES, true)) {
        return $out('none', 'rule', 'unknown_rule', $until);
    }
    // 7 admin 규칙 — 그룹·allow 로 넘지 못한다(H-6 판정 2를 넓힘)
    if ($rule === 'admin' && !role_gte($globalRole, 'admin')) {
        return $out('none', 'rule', 'not_admin', $until);
    }

    $level = 'none';
    $source = 'rule';
    $reason = '';
    $scopeRows = [];
    if ($g === 'allow' && $gw) {
        // 8 덮어쓰기 — 정확히 그 값(그룹보다 낮출 수도 있다)
        $lv = ($us['access_level'] ?? null) === null || $us['access_level'] === '' ? 'use' : (string) $us['access_level'];
        if (!in_array($lv, ACCESS_LEVELS, true)) {
            error_log('[ulogin] user_services.access_level has an unknown value. service=' . (string) $svc['service_id']);
            return $out('none', 'override', 'unknown_level', $until);   // S11
        }
        $level = $lv;
        $source = 'override';
        $reason = 'granted_allow';
    } else {
        // 9 inherit · 기간 밖 allow/deny → 규칙 + 그룹
        $base = ['all' => 'use', 'all_view' => 'view', 'granted' => 'none', 'admin' => 'use'][$rule];
        $grp = 'none';
        $grpKey = '';
        foreach ($grpRows as $r) {
            if ((string) ($r['group_status'] ?? '') !== 'active'
                || !access_win($r['m_starts_at'] ?? null, $r['m_ends_at'] ?? null, $now)
                || !access_win($r['gs_starts_at'] ?? null, $r['gs_ends_at'] ?? null, $now)) {
                continue;
            }
            $lv = (string) ($r['level'] ?? '');
            if (!in_array($lv, ACCESS_LEVELS, true)) {
                error_log('[ulogin] access_group_services.level has an unknown value. service=' . (string) $svc['service_id']);
                continue;   // S11 — 모르는 값은 none
            }
            $scopeRows[] = $r;
            if (access_rank($lv) > access_rank($grp)) {
                $grp = $lv;
                $grpKey = (string) ($r['group_key'] ?? '');
            }
        }
        if (access_rank($grp) > access_rank($base)) {
            $level = $grp;
            $source = 'group:' . $grpKey;
            $reason = 'group';
        } else {
            $level = $base;
            $reason = ['all' => 'rule_all', 'all_view' => 'rule_all_view', 'granted' => 'not_granted', 'admin' => 'admin_role'][$rule];
        }
    }
    if ($level === 'none') {
        return $out('none', $source, $reason === '' ? 'not_granted' : $reason, $until);
    }

    // scopes(K3): 등록부 NULL → null. us.features_json 배열 → 그것, 아니면 기간 안 그룹 scopes 합집합, 없으면 default:true. ∩ 등록부
    $reg = features_registry($svc);
    $scopes = null;
    if ($reg !== null) {
        $pick = null;
        $raw = $us['features_json'] ?? null;
        if ($raw !== null && $raw !== '') {
            $v = json_decode((string) $raw, true);
            if (!is_array($v)) {
                error_log('[ulogin] user_services.features_json is invalid. service=' . (string) $svc['service_id']);
                $v = [];
            }
            $pick = $v;
        } else {
            foreach ($scopeRows as $r) {
                $sr = $r['scopes_json'] ?? null;
                if ($sr === null || $sr === '') {
                    continue;
                }
                $v = json_decode((string) $sr, true);
                if (!is_array($v)) {
                    error_log('[ulogin] access_group_services.scopes_json is invalid. service=' . (string) $svc['service_id']);
                    $v = [];   // S11 — 깨진 값은 [] (아무 scope 도 더하지 않는다)
                }
                $pick = array_merge($pick === null ? [] : $pick, $v);
            }
        }
        $scopes = [];
        foreach ($reg as $f) {
            if ($pick === null ? (bool) $f['default'] : in_array($f['id'], $pick, true)) {
                $scopes[] = (string) $f['id'];
            }
        }
    }
    return ['level' => $level, 'scopes' => $scopes, 'until' => $until, 'source' => $source, 'reason' => $reason];
}

/** 입장 판정(K4). RANK ≥ use, 또는 view 이고 서비스가 단계를 안다고 선언(level_aware=1). */
function access_enter_ok(array $d, ?array $svc): bool
{
    $r = access_rank((string) ($d['level'] ?? 'none'));
    if ($r >= ACCESS_RANK['use']) {
        return true;
    }
    return $r === ACCESS_RANK['view'] && $svc !== null && (int) ($svc['level_aware'] ?? 0) === 1;
}

/**
 * 그룹 행 로더. [user_id => [service_id => [행…]]]. $serviceId 가 null 이면 전 서비스.
 * 0010 미적용 DB(표 없음)는 그룹 없음(= 넓히지 않는 쪽)으로 본다.
 */
function access_group_rows(array $userIds, ?string $serviceId = null): array
{
    $ids = [];
    foreach ($userIds as $u) {
        if ((int) $u > 0) {
            $ids[(int) $u] = true;
        }
    }
    if ($ids === [] || !defined('T_ACCESS_GROUP_MEMBERS')) {
        return [];
    }
    $ids = array_keys($ids);
    $out = [];
    try {
        foreach (array_chunk($ids, 500) as $chunk) {
            $p = $chunk;
            $sql = 'SELECT m.user_id, gs.service_id, g.group_key, g.status AS group_status, m.starts_at AS m_starts_at,'
                . ' m.ends_at AS m_ends_at, gs.level, gs.scopes_json, gs.starts_at AS gs_starts_at, gs.ends_at AS gs_ends_at'
                . ' FROM ' . T_ACCESS_GROUP_MEMBERS . ' m JOIN ' . T_ACCESS_GROUPS . ' g ON g.id = m.group_id'
                . ' JOIN ' . T_ACCESS_GROUP_SERVICES . ' gs ON gs.group_id = g.id'
                . ' WHERE m.user_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')';
            if ($serviceId !== null) {
                $sql .= ' AND gs.service_id = ?';
                $p[] = $serviceId;
            }
            $sql .= ' ORDER BY g.group_key ASC';
            foreach (db_all($sql, $p) as $r) {
                $out[(int) $r['user_id']][(string) $r['service_id']][] = $r;
            }
        }
    } catch (Throwable $e) {
        log_throttled('access_group_rows', '[ulogin] access group rows unavailable (migration 0010 pending?): ' . get_class($e));
        return [];
    }
    return $out;
}

/**
 * 행 셋으로 단계 판정. $grpRows 가 null 이면 이 계정 × 이 서비스의 그룹 행을 읽는다.
 * 판정 입력의 role 은 **전역**(global_role_of) 하나뿐이다.
 */
function access_level_eval(array $user, ?array $service, ?array $us, ?array $grpRows = null): array
{
    if ($grpRows === null) {
        $sidKey = $service === null ? '' : (string) $service['service_id'];
        $all = $sidKey === '' ? [] : access_group_rows([(int) ($user['id'] ?? 0)], $sidKey);
        $grpRows = $all[(int) ($user['id'] ?? 0)][$sidKey] ?? [];
    }
    return access_level_decide($user, $service, $us, $grpRows, time(),
        global_role_of($user)
    );
}

/**
 * v1.9.0 호환 판정 {allowed, reason}. allowed = 입장 판정(access_enter_ok). 단계·출처는 'decision' 에 싣는다.
 * 읽기 소비자(관리 화면·비상 확인값 자격)가 쓴다.
 */
function service_access_eval(array $user, ?array $us, array $service): array
{
    $d = access_level_eval($user, $service, $us);
    return ['allowed' => access_enter_ok($d, $service), 'reason' => $d['reason'], 'decision' => $d];
}

/** 판정에 쓸 user_services 행. 없으면 null(= inherit). */
function service_access_us_row(int $userId, string $serviceId): ?array
{
    if ($userId <= 0 || $serviceId === '') {
        return null;
    }
    return db_one('SELECT * FROM ' . T_USER_SERVICES . ' WHERE user_id = ? AND service_id = ?', [$userId, $serviceId]);
}

/**
 * 거절을 집행한다. 기록(login_logs)을 이 함수 안에 둔다 — 집행 지점마다 log_login() 을 부르면 한 곳이 빠진다.
 * 서비스 단위 차단(membership_blocked)은 여기서 거절하지 않는다 — D-31 이 각 경로에서 FORBIDDEN 으로(session·refresh 는
 * payload {} 로) 이미 처리하고, 그 응답 코드를 바꾸지 않기 위해서다.
 */
function require_service_access(array $user, ?array $us, array $service, string $where): void
{
    $d = access_level_eval($user, $service, $us);
    if ($d['reason'] === 'membership_blocked' || access_enter_ok($d, $service)) {
        return;
    }
    // 판정은 인자 밖에서 한다 — tests 의 result 값 수집기가 log_login() 첫 인자의 문자열 리터럴을 전부 result 값으로 읽는다
    $isEm = $d['reason'] === 'emergency_account';
    log_login($isEm ? 'fail_emergency_scope' : 'fail_service_access',
        (int) $user['id'], (string) $user['username'], (string) $service['service_id']);
    if ($isEm) {
        audit_emergency_event('emergency.sso.denied', $user, ['service_id' => (string) $service['service_id'], 'where' => $where]);
    }
    error_log('[ulogin] service access denied at ' . $where . ' service=' . (string) $service['service_id']
        . ' reason=' . $d['reason'] . ' level=' . $d['level']);
    throw new AppError('SERVICE_ACCESS_DENIED', 403);
}

/** 서비스 행(상태 무관) — 접근 판정 방어층이 쓴다. 요청 안 정적 캐시. */
function access_service_row(string $serviceId): ?array
{
    static $cache = [];
    if (!array_key_exists($serviceId, $cache)) {
        $cache[$serviceId] = db_one('SELECT * FROM ' . T_SERVICES . ' WHERE service_id = ?', [$serviceId]);
    }
    return $cache[$serviceId];
}

/**
 * 계정 하나 × 서비스 여러 개의 판정. [service_id => decide 결과]. 쿼리: 그룹 행 1회(+ $usBy 가 없으면 1회).
 * @param array      $services services 행 목록
 * @param array|null $usBy     [service_id => user_services 행]
 */
function access_levels_for_user(array $user, array $services, ?array $usBy = null): array
{
    $uid = (int) ($user['id'] ?? 0);
    if ($usBy === null) {
        $usBy = [];
        if ($uid > 0) {
            foreach (db_all('SELECT * FROM ' . T_USER_SERVICES . ' WHERE user_id = ?', [$uid]) as $r) {
                $usBy[(string) $r['service_id']] = $r;
            }
        }
    }
    $grp = access_group_rows([$uid]);
    $out = [];
    foreach ($services as $s) {
        $sid = (string) $s['service_id'];
        $out[$sid] = access_level_eval($user, $s, $usBy[$sid] ?? null, $grp[$uid][$sid] ?? []);
    }
    return $out;
}

/**
 * 계정 × 활성 서비스 전부의 bool 맵 `{service_id: bool}` (80 §1-1). true = RANK ≥ use (view 는 false, §2).
 * $usByService: [service_id => user_services 행]
 */
function service_access_map(array $user, array $activeServices, array $usByService): array
{
    $map = [];
    foreach (access_levels_for_user($user, $activeServices, $usByService) as $sid => $d) {
        $map[$sid] = access_rank($d['level']) >= ACCESS_RANK['use'];
    }
    return $map;
}

/**
 * `access` 맵(§3-1): 활성 서비스 중 level ≥ view 만. scopes 는 $callerSid 만 채우고 나머지는 null(S6).
 * @return array [service_id => {level, scopes, until}]
 */
function access_map(array $user, ?string $callerSid, ?array $usBy = null): array
{
    $out = [];
    foreach (access_levels_for_user($user, access_active_services(), $usBy) as $sid => $d) {
        if (access_rank($d['level']) < ACCESS_RANK['view']) {
            continue;
        }
        $out[$sid] = ['level' => $d['level'], 'scopes' => $sid === $callerSid ? $d['scopes'] : null, 'until' => $d['until']];
    }
    return $out;
}

/**
 * 응답에 `access_level` · `access` 를 붙인다(§3-1, 순수 추가). 서비스 컨텍스트가 없으면 붙이지 않는다.
 * login(service_id 있음) · exchange · session · refresh 네 곳이 json_out() **직전에** 부른다(response_extra 경유).
 */
function access_emit(?string $serviceId, array $user, ?array $us): void
{
    if ($serviceId === null || $serviceId === '') {
        return;
    }
    $svc = service_load($serviceId);
    if ($svc === null) {
        return;
    }
    $d = access_level_eval($user, $svc, $us);
    response_extra('access_level', $d['level']);
    response_extra('access', json_object(access_map($user, $serviceId)));
}

/** 활성 서비스 목록(접근 맵용). 요청 안 정적 캐시. */
function access_active_services(): array
{
    static $rows = null;
    if ($rows === null) {
        $rows = db_all('SELECT * FROM ' . T_SERVICES . ' WHERE status = ? ORDER BY service_id ASC', ['active']);
    }
    return $rows;
}

/**
 * 관리자 조작 뒤 처리(§2 기간·회수): 입장이 true → false 가 된 (계정, 서비스) 의 세션을 바로 회수한다.
 * $before: [user_id => [service_id => bool]] (조작 전 입장 판정). 반환: 회수한 세션 수.
 */
function access_revoke_lost(array $before): int
{
    $n = 0;
    $users = [];
    foreach ($before as $uid => $m) {
        foreach ($m as $sid => $was) {
            if (!$was) {
                continue;
            }
            $svc = access_service_row_fresh((string) $sid);
            $u = $users[$uid] ?? ($users[$uid] = db_one('SELECT * FROM ' . T_USERS . ' WHERE id = ?', [(int) $uid]));
            if ($u === null) {
                continue;
            }
            $d = access_level_eval($u, $svc, service_access_us_row((int) $uid, (string) $sid));
            if (!access_enter_ok($d, $svc)) {
                $n += db_exec('UPDATE ' . T_SESSIONS . ' SET revoked_at = ?, revoke_reason = ? WHERE user_id = ? AND service_id = ?'
                    . ' AND revoked_at IS NULL', [now_utc(), 'access_revoked', (int) $uid, (string) $sid]);
            }
        }
    }
    foreach (array_keys($users) as $uid) {
        em_reconcile_user((int) $uid);   // v1.9.1 — 접근을 잃었으면 비상 확인값 폐기
    }
    return $n;
}

/** 입장 판정 스냅숏 [user_id => [service_id => bool]] — access_revoke_lost() 의 조작 전 값. */
function access_enter_snapshot(array $userIds, array $serviceIds): array
{
    $out = [];
    if ($userIds === [] || $serviceIds === []) {
        return $out;
    }
    $svcs = [];
    foreach ($serviceIds as $sid) {
        $svcs[(string) $sid] = access_service_row_fresh((string) $sid);
    }
    $grp = access_group_rows($userIds);
    foreach (array_chunk(array_values(array_unique(array_map('intval', $userIds))), 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $usAll = [];
        foreach (db_all('SELECT * FROM ' . T_USER_SERVICES . ' WHERE user_id IN (' . $ph . ')', $chunk) as $r) {
            $usAll[(int) $r['user_id']][(string) $r['service_id']] = $r;
        }
        foreach (db_all('SELECT * FROM ' . T_USERS . ' WHERE id IN (' . $ph . ')', $chunk) as $u) {
            $uid = (int) $u['id'];
            foreach ($svcs as $sid => $svc) {
                $d = access_level_eval($u, $svc, $usAll[$uid][$sid] ?? null, $grp[$uid][$sid] ?? []);
                $out[$uid][$sid] = access_enter_ok($d, $svc);
            }
        }
    }
    return $out;
}

/** 캐시 없는 서비스 행(관리 조작 직후 재판정용). */
function access_service_row_fresh(string $sid): ?array
{
    return db_one('SELECT * FROM ' . T_SERVICES . ' WHERE service_id = ?', [$sid]);
}

/**
 * GC(§2 기간·회수): 지난 2일 안에 끝난 기간(override·member·group service)의 영향 계정을 재판정해
 * 입장을 잃었으면 세션을 회수한다. 호출당 원천별 200행 상한. 회수는 멱등(revoked_at IS NULL)이라 되풀이돼도 같다.
 *
 * v1.10.0 감사 L-4 / D-372 — **커서**. 종전은 순서 없는 `LIMIT 200` 이라 만료가 200건을 넘으면 매번 같은 200건만
 * 다시 보고 나머지는 2일 창이 지나 영영 회수되지 않았다. 이제 원천마다 (ends_at, id) 키셋으로 이어 읽고,
 * 위치를 설정 `access.gc_cursor`(내부 값, 화면 비노출 — audit.chain_head 와 같은 부류)에 남긴다.
 *   us : user_services (access_ends_at, id)
 *   m  : access_group_members (ends_at, id) → 그 그룹의 서비스 전부
 *   gs : access_group_services (ends_at, id, 구성원 id) → 그 그룹의 구성원 전부(구성원도 200명씩 나눈다)
 * 커서보다 앞(과거)으로 고쳐 쓴 기간은 관리 조작 자체가 즉시 재판정·회수하므로(acc_revoke_service_sessions 등) GC 가 볼 필요가 없다.
 */
function access_gc_expired(): int
{
    if (!defined('T_ACCESS_GROUP_MEMBERS') || !db_table_exists(T_ACCESS_GROUP_MEMBERS)) {
        return 0;
    }
    $now = now_utc();
    $from = at_utc(-2 * 86400);
    $budget = 200;
    $raw = setting_get('access.gc_cursor', null);
    $cur = is_string($raw) ? json_decode($raw, true) : null;
    $cur = is_array($cur) ? $cur : [];
    $start = $cur;
    // 커서가 없거나 창(2일) 밖으로 밀려났으면 창 시작에서 다시 읽는다
    $pos = function (string $k, int $n) use ($cur, $from): array {
        $c = isset($cur[$k]) && is_array($cur[$k]) && count($cur[$k]) === $n ? $cur[$k] : null;
        if ($c === null || !is_string($c[0]) || strcmp($c[0], $from) < 0) {
            return array_merge([$from], array_fill(0, $n - 1, 0));
        }
        return array_merge([(string) $c[0]], array_map('intval', array_slice($c, 1)));
    };
    $pairs = [];

    // 1) 계정 직접 덮어쓰기
    list($ts, $id) = $pos('us', 2);
    foreach (db_all('SELECT id, user_id, service_id, access_ends_at FROM ' . T_USER_SERVICES
        . ' WHERE access_ends_at <= ? AND (access_ends_at > ? OR (access_ends_at = ? AND id > ?))'
        . ' ORDER BY access_ends_at ASC, id ASC LIMIT ' . $budget, [$now, $ts, $ts, $id]) as $r) {
        $pairs[(int) $r['user_id']][(string) $r['service_id']] = true;
        $cur['us'] = [(string) $r['access_ends_at'], (int) $r['id']];
    }

    // 2) 그룹 구성원 기간
    list($ts, $id) = $pos('m', 2);
    $mem = db_all('SELECT id, group_id, user_id, ends_at FROM ' . T_ACCESS_GROUP_MEMBERS
        . ' WHERE ends_at <= ? AND (ends_at > ? OR (ends_at = ? AND id > ?))'
        . ' ORDER BY ends_at ASC, id ASC LIMIT ' . $budget, [$now, $ts, $ts, $id]);
    if ($mem !== []) {
        $gids = array_values(array_unique(array_map(function (array $r): int { return (int) $r['group_id']; }, $mem)));
        $svcBy = [];
        foreach (db_all('SELECT group_id, service_id FROM ' . T_ACCESS_GROUP_SERVICES . ' WHERE group_id IN ('
            . implode(',', array_fill(0, count($gids), '?')) . ')', $gids) as $g) {
            $svcBy[(int) $g['group_id']][] = (string) $g['service_id'];
        }
        foreach ($mem as $r) {
            foreach ($svcBy[(int) $r['group_id']] ?? [] as $sid) {
                $pairs[(int) $r['user_id']][$sid] = true;
            }
            $cur['m'] = [(string) $r['ends_at'], (int) $r['id']];
        }
    }

    // 3) 그룹-서비스 기간 — 행 하나가 구성원 전부에 걸리므로 구성원 id 까지 커서에 둔다.
    //    커서 [ends_at, id, mid]: mid = 이 행에서 마지막으로 본 구성원 id. -1 = 이 행은 끝났다(다음 행부터).
    list($ts, $id, $mid) = $pos('gs', 3);
    $left = $budget;
    $guard = $budget;   // 구성원 0명 행이 길게 이어져도 호출당 질의 수를 묶는다
    while ($left > 0 && $guard-- > 0) {
        $after = '(ends_at > ? OR (ends_at = ? AND id > ?))';
        $args  = [$now, $ts, $ts, $id];
        if ($mid >= 0) {
            $after = '(' . $after . ' OR (ends_at = ? AND id = ?))';   // 끝나지 않은 현재 행을 이어서
            array_push($args, $ts, $id);
        }
        $gs = db_one('SELECT id, group_id, service_id, ends_at FROM ' . T_ACCESS_GROUP_SERVICES
            . ' WHERE ends_at <= ? AND ' . $after . ' ORDER BY ends_at ASC, id ASC LIMIT 1', $args);
        if ($gs === null) {
            break;
        }
        if ((string) $gs['ends_at'] !== $ts || (int) $gs['id'] !== $id || $mid < 0) {
            $mid = 0;   // 새 행 — 구성원을 처음부터
        }
        $ts = (string) $gs['ends_at'];
        $id = (int) $gs['id'];
        $ms = db_all('SELECT id, user_id FROM ' . T_ACCESS_GROUP_MEMBERS . ' WHERE group_id = ? AND id > ? ORDER BY id ASC LIMIT ' . $left,
            [(int) $gs['group_id'], $mid]);
        foreach ($ms as $m) {
            $pairs[(int) $m['user_id']][(string) $gs['service_id']] = true;
            $mid = (int) $m['id'];
        }
        if (count($ms) < $left) {
            $mid = -1;   // 이 행의 구성원을 다 봤다
        }
        $left -= count($ms);
        $cur['gs'] = [$ts, $id, $mid];
    }

    if ($cur !== $start) {
        setting_set('access.gc_cursor', (string) json_encode($cur, JSON_UNESCAPED_SLASHES), 'string');
    }
    return $pairs === [] ? 0 : access_revoke_lost($pairs);
}

// =====================================================================
// features (D-326 · 설계 116 §7)
// =====================================================================

/**
 * 등록부 정규화. 입력이 틀리면 null(+ $err). 반환: [{id,name,default}] (id 중복 없음).
 * @param mixed $v
 */
function features_norm_registry($v, ?string &$err = null): ?array
{
    if (!is_array($v) || ($v !== [] && array_keys($v) !== range(0, count($v) - 1))) {
        $err = 'features 는 배열이어야 합니다.';
        return null;
    }
    if (count($v) > FEATURES_MAX) {
        $err = 'features 는 ' . FEATURES_MAX . '개 이하입니다.';
        return null;
    }
    $out = [];
    $seen = [];
    foreach ($v as $f) {
        if (!is_array($f) || !is_string($f['id'] ?? null) || preg_match('/^[a-z0-9_.]{1,64}$/', (string) $f['id']) !== 1) {
            $err = '기능 id 는 [a-z0-9_.] 1~64자여야 합니다.';
            return null;
        }
        $id = (string) $f['id'];
        if (isset($seen[$id])) {
            $err = '기능 id 가 중복됐습니다: ' . $id;
            return null;
        }
        $seen[$id] = true;
        $name = features_name($f['name'] ?? null);
        $def  = $f['default'] ?? false;
        if (!is_bool($def)) {
            $err = 'default 는 true/false 여야 합니다.';
            return null;
        }
        $out[] = ['id' => $id, 'name' => $name === null ? $id : $name, 'default' => $def];
    }
    return $out;
}

function features_name($v): ?string
{
    if (!is_string($v)) {
        return null;
    }
    $s = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $v));
    return $s === '' ? null : mb_substr($s, 0, 100, 'UTF-8');
}

/** 서비스 등록부. NULL(관리 안 함) 이면 null. 저장값이 깨졌으면 [] + 로그(fail-closed: 기능 0개). */
function features_registry(array $service): ?array
{
    $raw = $service['features_json'] ?? null;
    if ($raw === null || $raw === '') {
        return null;
    }
    $err = null;
    $r = features_norm_registry(json_decode((string) $raw, true), $err);
    if ($r === null) {
        error_log('[ulogin] services.features_json is invalid. service=' . (string) $service['service_id']);
        return [];
    }
    return $r;
}

/**
 * 유효 기능 id 목록(그룹 scopes 미반영 — 관리 화면의 계정 지정 표시용). 등록부가 NULL 이면 null.
 * 사용자 지정(user_services.features_json)이 배열이면 등록부와 교집합, NULL 이면 default:true.
 * @return string[]|null
 */
function features_effective(array $service, ?array $us): ?array
{
    $reg = features_registry($service);
    if ($reg === null) {
        return null;
    }
    $raw = $us['features_json'] ?? null;
    if ($raw !== null && $raw !== '') {
        $pick = json_decode((string) $raw, true);
        if (!is_array($pick)) {
            error_log('[ulogin] user_services.features_json is invalid. service=' . (string) $service['service_id']);
            return [];
        }
        $out = [];
        foreach ($reg as $f) {
            if (in_array($f['id'], $pick, true)) {
                $out[] = $f['id'];
            }
        }
        return $out;
    }
    $out = [];
    foreach ($reg as $f) {
        if ($f['default']) {
            $out[] = (string) $f['id'];
        }
    }
    return $out;
}

/**
 * 응답에 `features` 를 붙이게 등록한다(등록부가 있을 때만 — 없으면 키 자체가 없다). 서비스가 없거나 비활성이면 붙이지 않는다.
 * v1.10.0 — scopes 와 같은 축(K3): 단계가 있으면 판정의 scopes(그룹 scopes 반영), 없으면 종전 계산.
 */
function features_emit(?string $serviceId, ?array $us, ?array $user = null): void
{
    if ($serviceId === null || $serviceId === '') {
        return;
    }
    $svc = service_load($serviceId);
    if ($svc === null) {
        return;
    }
    $f = features_effective($svc, $us);
    if ($f !== null && $user !== null) {
        $d = access_level_eval($user, $svc, $us);
        if ($d['level'] !== 'none' && is_array($d['scopes'])) {
            $f = $d['scopes'];
        }
    }
    if ($f !== null) {
        response_extra('features', $f);
    }
}

// =====================================================================
// 비상 관리자 계정 (설계 127 §6 · D-367 Q-5) — CLI 로만 만들고 정한다
// =====================================================================

/** 미설정 표지. password_hash() 결과는 '$' 로 시작하므로 어떤 해시와도 겹치지 않는다. */
const EMERGENCY_UNSET_HASH  = '!unset';
const EMERGENCY_SESSION_TTL = 3600;

function is_emergency_user(?array $u): bool
{
    return $u !== null && (string) ($u['account_kind'] ?? 'normal') === 'emergency';
}

/**
 * 웹 경로가 비상 계정을 **대상으로** 바꾸려 하면 403(§6-1: 정하기·초기화·역할·상태·아이디 변경 모두 CLI 전용).
 * $field 가 있으면 400 VALIDATION_FAILED(field) — 접근 권한 부여 같은 「대상이 될 수 없다」 경우.
 */
function emergency_target_guard(?array $u, ?string $field = null): void
{
    if (!is_emergency_user($u)) {
        return;
    }
    if ($field !== null) {
        throw new AppError('VALIDATION_FAILED', 400, ['field' => $field, 'message' => '비상 계정에는 서비스 권한을 줄 수 없습니다.']);
    }
    throw new AppError('FORBIDDEN', 403, ['message' => '비상 계정은 서버 PC 의 명령줄 도구로만 바꿀 수 있습니다.']);
}

// =====================================================================
// 비상 계정 감사 (설계 127 §6-4) — 값은 싣지 않는다
// =====================================================================

/**
 * `emergency.*` 감사 1건. 실패해도 응답은 막지 않는다(log_admin 과 같은 규약).
 * 행위자는 system(admin_id 0). IP·UA 는 감사 행의 ip·user_agent 칸이 싣는다(웹 요청일 때) — changes 에 다시 넣지 않는다.
 */
function audit_emergency_event(string $action, array $user, array $after = []): void
{
    try {
        log_admin(0, $action, 'user', (string) ($user['uuid'] ?? ''), null, $after === [] ? null : $after);
    } catch (Throwable $e) {
        error_log('[ulogin] emergency audit failed: ' . get_class($e));
    }
}
