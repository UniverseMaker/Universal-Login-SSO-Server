<?php
declare(strict_types=1);
/**
 * GET /server/api/admin/account_links.php?view=… — 시스템 계정 연결 조회 (v1.8.4)
 * 담당 계약: 설계 92 G-1 · G-2 · G-3 · G-7
 * 최소 역할: admin (`account_link.view`) — **전역 users.role 만**(require_global_role, D-50).
 *            서비스 오버라이드(user_services.role)로 받은 superadmin 은 통과하지 못한다.
 *
 * 응답 최상위 키
 *   view=systems : systems
 *   view=accounts: items, page, per_page, total
 *   view=person  : user, systems
 *   view=groups  : groups, page, per_page, total
 *   view=job     : job, items
 *   view=jobs    : items, page, per_page, total
 *
 * 「후보」는 저장하지 않는다 — 읽을 때 계산한다(설계 C절). 비탈퇴 ulogin 계정 중
 * 이메일 완전 일치 / 아이디 소문자 일치 / bound_uuid 일치, 최대 5개.
 * 조회이므로 admin_logs 에 남기지 않는다. setup_url 은 어떤 view 에도 나오지 않는다.
 */
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/accountlink.php';

only_method('GET');

$ctx = require_auth();
require_global_role($ctx, CAN_MAP['account_link.view']);

$in   = $_GET;
$view = v_enum($in, 'view', ['systems', 'accounts', 'person', 'groups', 'job', 'jobs'], true);

// =====================================================================
// 공용
// =====================================================================

/** ext 행 → 목록·상세 공용 표현의 앞부분. */
function ala_ext_base(array $r): array
{
    return [
        'service_id'        => (string) $r['service_id'],
        'external_id'       => (string) $r['external_id'],
        'username'          => $r['username'] === null ? null : (string) $r['username'],
        'email'             => $r['email'] === null ? null : (string) $r['email'],
        'name'              => $r['name'] === null ? null : (string) $r['name'],
        'ext_status'        => $r['ext_status'] === null ? null : (string) $r['ext_status'],
        'ext_created_at'    => iso($r['ext_created_at']),
        'ext_last_login_at' => iso($r['ext_last_login_at']),
        'state'             => (string) $r['state'],
        'row_version'       => (int) $r['row_version'],
        'gone'              => $r['gone_at'] !== null,
        'is_emergency'      => !empty($r['is_emergency']),   // v1.10.0 §6-3 「비상 계정 — 연결하지 않음」
    ];
}

/** 이름 있는 IN 자리표시자. 빈 목록이면 null(호출자가 질의를 건너뛴다). */
function ala_in(array $vals): ?string
{
    return $vals === [] ? null : implode(',', array_fill(0, count($vals), '?'));
}

/**
 * ext 행 목록의 후보 ulogin 계정 — 질의 2번(사용자 · 이미 연결)으로 페이지 전체를 계산한다.
 * @return array [row id => [후보…]] 와 사용자 맵
 */
function ala_candidates(array $rows): array
{
    $emails = [];
    $names  = [];
    $uuids  = [];
    foreach ($rows as $r) {
        if ($r['email'] !== null) {
            $emails[(string) $r['email']] = true;
        }
        if ($r['username'] !== null) {
            $names[mb_strtolower((string) $r['username'], 'UTF-8')] = true;
        }
        if ($r['bound_uuid'] !== null) {
            $uuids[strtolower((string) $r['bound_uuid'])] = true;
        }
    }
    $users = [];
    $conds = [];
    $params = ['withdrawn'];
    foreach (['email' => array_keys($emails), 'username' => array_keys($names), 'uuid' => array_keys($uuids)] as $col => $vals) {
        $ph = ala_in($vals);
        if ($ph !== null) {
            $conds[] = $col . ' IN (' . $ph . ')';
            $params  = array_merge($params, $vals);
        }
    }
    if ($conds !== []) {
        // v1.10.0 §6-3 ② — 통합로그인 비상 계정은 어떤 연결의 후보도 되지 않는다
        foreach (db_all('SELECT id, uuid, username, email, name, status, role FROM ' . T_USERS
            . ' WHERE status <> ? AND account_kind <> \'emergency\' AND (' . implode(' OR ', $conds) . ')', $params) as $u) {
            $users[(int) $u['id']] = $u;
        }
    }
    $linked = [];   // "service_id|user_id" => true
    $ids = array_keys($users);
    $ph  = ala_in($ids);
    if ($ph !== null) {
        foreach (db_all('SELECT service_id, user_id FROM ' . T_EXT_ACCOUNTS . ' WHERE state = ? AND user_id IN (' . $ph . ')',
            array_merge(['linked'], $ids)) as $x) {
            $linked[(string) $x['service_id'] . '|' . (int) $x['user_id']] = true;
        }
    }
    $out = [];
    foreach ($rows as $r) {
        $list = [];
        if (!empty($r['is_emergency'])) {
            $out[(int) $r['id']] = [];   // v1.10.0 §6-3 ① — 비상 표지가 붙은 외부 계정은 후보를 내지 않는다
            continue;
        }
        foreach ($users as $uid => $u) {
            if ($r['user_id'] !== null && (int) $r['user_id'] === $uid) {
                continue;   // 지금 연결된 사람은 후보가 아니다
            }
            $m = [];
            if ($r['email'] !== null && (string) $u['email'] === (string) $r['email']) {
                $m[] = 'email';
            }
            if ($r['username'] !== null && (string) $u['username'] === mb_strtolower((string) $r['username'], 'UTF-8')) {
                $m[] = 'username';
            }
            if ($r['bound_uuid'] !== null && strtolower((string) $u['uuid']) === strtolower((string) $r['bound_uuid'])) {
                $m[] = 'bound';
            }
            if ($m === []) {
                continue;
            }
            $list[] = [
                'uuid'                => (string) $u['uuid'],
                'username'            => (string) $u['username'],
                'email'               => (string) $u['email'],
                'match'               => $m,
                'already_linked_here' => isset($linked[(string) $r['service_id'] . '|' . $uid]),
                'role_rank'           => rank_of(ROLE_RANK, global_role_of($u)),
                'status'              => (string) $u['status'],
                'is_emergency'        => false,
            ];
            if (count($list) >= 5) {
                break;
            }
        }
        $out[(int) $r['id']] = $list;
    }
    return $out;
}

/** 연결된 사용자 행 맵(id => row). */
function ala_users_by_id(array $ids): array
{
    $ids = array_values(array_unique(array_filter($ids)));
    $ph  = ala_in($ids);
    if ($ph === null) {
        return [];
    }
    $out = [];
    foreach (db_all('SELECT id, uuid, username, email, name, status, role FROM ' . T_USERS . ' WHERE id IN (' . $ph . ')', $ids) as $u) {
        $out[(int) $u['id']] = $u;
    }
    return $out;
}

/** F절 충돌 다섯 종류(+ D-328 경고). 원소 = {code, uuid}. */
function ala_conflicts(array $r, ?array $linkedUser, array $cands): array
{
    $c = [];
    if ((string) $r['state'] === 'linked' && $linkedUser !== null) {
        $c[] = ['code' => 'EXT_ALREADY_LINKED', 'uuid' => (string) $linkedUser['uuid']];
        if ((string) $linkedUser['status'] !== 'active') {
            $c[] = ['code' => 'USER_NOT_ACTIVE', 'uuid' => (string) $linkedUser['uuid']];
        }
        if (al_ext_is_admin($r['extra_json']) && rank_of(ROLE_RANK, global_role_of($linkedUser)) < 20) {
            $c[] = ['code' => 'EXT_ROLE_ADMIN_LOW_RANK', 'uuid' => (string) $linkedUser['uuid']];
        }
    }
    foreach ($cands as $cd) {
        if ($cd['already_linked_here']) {
            $c[] = ['code' => 'USER_ALREADY_LINKED_SERVICE', 'uuid' => $cd['uuid']];
        }
    }
    if ($r['bound_uuid'] !== null) {
        $owner = $linkedUser === null ? null : strtolower((string) $linkedUser['uuid']);
        if ($owner !== null && $owner !== strtolower((string) $r['bound_uuid'])) {
            $c[] = ['code' => 'SYSTEM_BOUND_OTHER', 'uuid' => strtolower((string) $r['bound_uuid'])];
        }
    }
    if ($r['gone_at'] !== null || (string) $r['ext_status'] === 'deleted') {
        $c[] = ['code' => 'EXT_GONE', 'uuid' => null];
    } elseif ((string) $r['ext_status'] === 'disabled') {
        $c[] = ['code' => 'EXT_DISABLED', 'uuid' => null];
    }
    return $c;
}

function ala_user_brief(?array $u): ?array
{
    if ($u === null) {
        return null;
    }
    return ['uuid' => (string) $u['uuid'], 'username' => (string) $u['username'], 'email' => (string) $u['email'],
        'name' => $u['name'] === null ? null : (string) $u['name'], 'status' => (string) $u['status'],
        'role_rank' => rank_of(ROLE_RANK, global_role_of($u))];
}

// =====================================================================
// G-1 systems
// =====================================================================
if ($view === 'systems') {
    $counts = [];
    foreach (db_all('SELECT service_id, state, COUNT(*) AS n FROM ' . T_EXT_ACCOUNTS . ' GROUP BY service_id, state') as $x) {
        $counts[(string) $x['service_id']][(string) $x['state']] = (int) $x['n'];
    }
    $gone = [];
    foreach (db_all('SELECT service_id, COUNT(*) AS n FROM ' . T_EXT_ACCOUNTS . ' WHERE gone_at IS NOT NULL GROUP BY service_id') as $x) {
        $gone[(string) $x['service_id']] = (int) $x['n'];
    }
    $lastPush = [];
    foreach (db_all('SELECT service_id, MAX(updated_at) AS t FROM ' . T_LINK_JOBS
        . ' WHERE kind = ? AND created_by IS NULL GROUP BY service_id', ['push']) as $x) {
        $lastPush[(string) $x['service_id']] = (string) $x['t'];
    }
    $lastFull = [];
    foreach (db_all('SELECT service_id, MAX(finished_at) AS t FROM ' . T_LINK_JOBS
        . ' WHERE kind = ? AND status = ? GROUP BY service_id', ['push', 'done']) as $x) {
        $lastFull[(string) $x['service_id']] = (string) $x['t'];
    }
    $req = [];
    foreach (db_all('SELECT service_id, MAX(created_at) AS t FROM ' . T_LINK_JOBS
        . ' WHERE kind = ? AND status = ? GROUP BY service_id', ['sync_request', 'requested']) as $x) {
        $req[(string) $x['service_id']] = (string) $x['t'];
    }
    $systems = [];
    foreach (db_all('SELECT service_id, service_name, status FROM ' . T_SERVICES . ' ORDER BY service_id ASC') as $s) {
        $sid = (string) $s['service_id'];
        $c   = isset($counts[$sid]) ? $counts[$sid] : [];
        $cn  = [
            'total'    => array_sum($c),
            'new'      => (int) ($c['new'] ?? 0),
            'ignored'  => (int) ($c['ignored'] ?? 0),
            'linked'   => (int) ($c['linked'] ?? 0),
            'unlinked' => (int) ($c['unlinked'] ?? 0),
            'gone'     => (int) ($gone[$sid] ?? 0),
        ];
        $systems[] = [
            'service_id'        => $sid,
            'service_name'      => (string) $s['service_name'],
            'service_status'    => (string) $s['status'],
            'supported'         => isset($lastPush[$sid]),
            'last_push_at'      => isset($lastPush[$sid]) ? iso($lastPush[$sid]) : null,
            'last_full_sync_at' => isset($lastFull[$sid]) ? iso($lastFull[$sid]) : null,
            'sync_requested_at' => isset($req[$sid]) ? iso($req[$sid]) : null,
            'counts'            => $cn,
        ];
    }
    json_out(['systems' => $systems]);
}

// =====================================================================
// G-2 accounts
// =====================================================================
if ($view === 'accounts') {
    $sid = v_string($in, 'service_id', 1, 64, false);
    admin_service_id_reject_former($sid);   // v1.8.6 — 관리자 API 는 옛 id 를 받지 않는다(설계 104 B-2)
    if ($sid !== null && al_service_row($sid) === null) {
        throw new AppError('VALIDATION_FAILED', 400, ['field' => 'service_id', 'message' => '없는 서비스입니다.']);
    }
    $q         = v_string($in, 'q', 1, 190, false);
    $state     = v_enum($in, 'state', ['new', 'ignored', 'linked', 'unlinked', 'not_linked', 'all'], false, 'all');
    // 시스템 쪽 상태(리더 판정 — frontend 요청: 받아온 페이지 안에서만 거르던 것을 서버로).
    //   present  사라짐 아님 · deleted 아님 · disabled 아님
    //   gone     전체 전송에서 빠짐(gone_at) 또는 시스템이 deleted 로 보냄
    //   disabled 시스템이 disabled 로 보냄(사라짐 아님)
    $extF      = v_enum($in, 'ext', ['any', 'present', 'gone', 'disabled'], false, 'any');
    $candidate = v_enum($in, 'candidate', ['any', 'has', 'none'], false, 'any');
    $sort      = v_enum($in, 'sort', ['updated_at', 'username', 'email', 'ext_last_login_at'], false, 'updated_at');
    $orderRaw  = v_enum($in, 'order', ['asc', 'desc', 'ASC', 'DESC'], false, 'desc');
    $order     = strtoupper((string) $orderRaw) === 'ASC' ? 'ASC' : 'DESC';
    $in2 = $in;
    if (!isset($in2['per_page'])) {
        $in2['per_page'] = 50;
    }
    list($page, $perPage, $offset) = page_params($in2, 50);

    $where  = ['1 = 1'];
    $params = [];
    if ($sid !== null) {
        $where[]  = 'e.service_id = ?';
        $params[] = $sid;
    }
    if ($state === 'not_linked') {
        $where[] = 'e.state IN (?, ?, ?)';
        array_push($params, 'new', 'ignored', 'unlinked');
    } elseif ($state !== 'all') {
        $where[]  = 'e.state = ?';
        $params[] = $state;
    }
    if ($extF === 'gone') {
        $where[]  = '(e.gone_at IS NOT NULL OR e.ext_status = ?)';
        $params[] = 'deleted';
    } elseif ($extF === 'disabled') {
        $where[]  = 'e.gone_at IS NULL AND e.ext_status = ?';
        $params[] = 'disabled';
    } elseif ($extF === 'present') {
        $where[]  = "e.gone_at IS NULL AND (e.ext_status IS NULL OR e.ext_status NOT IN (?, ?))";
        array_push($params, 'deleted', 'disabled');
    }
    if ($q !== null) {
        $like    = like_contains($q);
        $where[] = '(e.external_id LIKE ?' . sql_like_escape() . ' OR e.username LIKE ?' . sql_like_escape()
            . ' OR e.email LIKE ?' . sql_like_escape() . ' OR e.name LIKE ?' . sql_like_escape() . ')';
        array_push($params, $like, $like, $like, $like);
    }
    if ($candidate !== 'any') {
        $exists = 'EXISTS (SELECT 1 FROM ' . T_USERS . ' cu WHERE cu.status <> ? AND ('
            . '(e.email IS NOT NULL AND cu.email = e.email)'
            . ' OR (e.username IS NOT NULL AND cu.username = LOWER(e.username))'
            . ' OR (e.bound_uuid IS NOT NULL AND cu.uuid = e.bound_uuid))'
            . ' AND (e.user_id IS NULL OR cu.id <> e.user_id))';
        $where[]  = ($candidate === 'has' ? '' : 'NOT ') . $exists;
        $params[] = 'withdrawn';
    }
    $w     = implode(' AND ', $where);
    $total = (int) db_val('SELECT COUNT(*) FROM ' . T_EXT_ACCOUNTS . ' e WHERE ' . $w, $params);
    $rows  = db_all('SELECT e.* FROM ' . T_EXT_ACCOUNTS . ' e WHERE ' . $w
        . ' ORDER BY e.' . $sort . ' ' . $order . ', e.id ' . $order
        . ' LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset, $params);

    $cands = ala_candidates($rows);
    $uids  = [];
    foreach ($rows as $r) {
        $uids[] = $r['user_id'] === null ? 0 : (int) $r['user_id'];
    }
    $lu    = ala_users_by_id($uids);
    $items = [];
    foreach ($rows as $r) {
        $u  = ($r['user_id'] !== null && isset($lu[(int) $r['user_id']])) ? $lu[(int) $r['user_id']] : null;
        $cd = $cands[(int) $r['id']];
        $items[] = array_merge(ala_ext_base($r), [
            'extra'       => json_object(json_col($r['extra_json'])),
            'linked_user' => ala_user_brief($u),
            'link_origin' => $r['link_origin'] === null ? null : (string) $r['link_origin'],
            'linked_at'   => iso($r['linked_at']),
            'bound_uuid'  => $r['bound_uuid'] === null ? null : (string) $r['bound_uuid'],
            'candidates'  => $cd,
            'conflicts'   => ala_conflicts($r, $u, $cd),
        ]);
    }
    json_out(['items' => $items, 'page' => $page, 'per_page' => $perPage, 'total' => $total]);
}

// =====================================================================
// G-3 person
// =====================================================================
if ($view === 'person') {
    $uuid = in_raw($in, 'uuid');
    if (!al_is_uuid($uuid)) {
        throw new AppError('VALIDATION_FAILED', 400, ['field' => 'uuid', 'message' => '회원 식별자 형식이 올바르지 않습니다.']);
    }
    $u = db_one('SELECT * FROM ' . T_USERS . ' WHERE uuid = ?', [strtolower((string) $uuid)]);
    if ($u === null) {
        throw new AppError('NOT_FOUND', 404, ['field' => 'uuid']);
    }
    $uid = (int) $u['id'];
    $links = [];
    foreach (db_all('SELECT * FROM ' . T_EXT_ACCOUNTS . ' WHERE user_id = ? AND state = ?', [$uid, 'linked']) as $r) {
        $links[(string) $r['service_id']] = $r;
    }
    $cand = [];
    foreach (db_all('SELECT * FROM ' . T_EXT_ACCOUNTS . ' WHERE state IN (?, ?) AND ('
        . '(email IS NOT NULL AND email = ?) OR (username IS NOT NULL AND LOWER(username) = ?)'
        . ' OR (bound_uuid IS NOT NULL AND bound_uuid = ?)) ORDER BY service_id, id',
        ['new', 'unlinked', (string) $u['email'], (string) $u['username'], strtolower((string) $u['uuid'])]) as $r) {
        $m = [];
        if ($r['email'] !== null && (string) $r['email'] === (string) $u['email']) {
            $m[] = 'email';
        }
        if ($r['username'] !== null && mb_strtolower((string) $r['username'], 'UTF-8') === (string) $u['username']) {
            $m[] = 'username';
        }
        if ($r['bound_uuid'] !== null && strtolower((string) $r['bound_uuid']) === strtolower((string) $u['uuid'])) {
            $m[] = 'bound';
        }
        $sidC = (string) $r['service_id'];
        if (!empty($r['is_emergency']) || is_emergency_user($u)) {
            continue;   // v1.10.0 §6-3 — 비상 계정끼리도, 비상 표지 외부 계정도 후보가 아니다
        }
        if (!isset($cand[$sidC])) {
            $cand[$sidC] = [];
        }
        if (count($cand[$sidC]) < 5) {
            $cand[$sidC][] = [
                'external_id' => (string) $r['external_id'],
                'username'    => $r['username'] === null ? null : (string) $r['username'],
                'email'       => $r['email'] === null ? null : (string) $r['email'],
                'state'       => (string) $r['state'],
                'row_version' => (int) $r['row_version'],
                'match'       => $m,
                'admin_flag'  => al_ext_is_admin($r['extra_json']),
                'is_emergency' => false,
            ];
        }
    }
    $systems = [];
    foreach (db_all('SELECT service_id, service_name FROM ' . T_SERVICES . ' ORDER BY service_id ASC') as $s) {
        $sidS = (string) $s['service_id'];
        $l    = isset($links[$sidS]) ? $links[$sidS] : null;
        $systems[] = [
            'service_id'   => $sidS,
            'service_name' => (string) $s['service_name'],
            'link'         => $l === null ? null : [
                'external_id' => (string) $l['external_id'],
                'username'    => $l['username'] === null ? null : (string) $l['username'],
                'linked_at'   => iso($l['linked_at']),
                'link_origin' => $l['link_origin'] === null ? null : (string) $l['link_origin'],
                'row_version' => (int) $l['row_version'],
            ],
            'candidates'   => $l === null && isset($cand[$sidS]) ? $cand[$sidS] : [],
        ];
    }
    json_out([
        'user'    => [
            'uuid'          => (string) $u['uuid'],
            'username'      => (string) $u['username'],
            'email'         => (string) $u['email'],
            'name'          => $u['name'] === null ? null : (string) $u['name'],
            'status'        => (string) $u['status'],
            'setup_pending' => al_setup_pending($u),
            'role_rank'     => rank_of(ROLE_RANK, global_role_of($u)),
            'is_emergency'  => is_emergency_user($u),
        ],
        'systems' => $systems,
    ]);
}

// =====================================================================
// G-3 groups — 미연결(new·unlinked) 외부 계정을 이메일로 묶는다
// =====================================================================
if ($view === 'groups') {
    $without = (bool) v_bool($in, 'without_user', false, false);
    $in2 = $in;
    if (!isset($in2['per_page'])) {
        $in2['per_page'] = 50;
    }
    list($page, $perPage, $offset) = page_params($in2, 50);
    $w = 'state IN (?, ?) AND email IS NOT NULL';
    $p = ['new', 'unlinked'];
    if ($without) {
        $w .= ' AND email NOT IN (SELECT email FROM ' . T_USERS . ' WHERE status <> ?)';
        $p[] = 'withdrawn';
    }
    $total  = (int) db_val('SELECT COUNT(DISTINCT email) FROM ' . T_EXT_ACCOUNTS . ' WHERE ' . $w, $p);
    $emails = [];
    foreach (db_all('SELECT email FROM ' . T_EXT_ACCOUNTS . ' WHERE ' . $w . ' GROUP BY email ORDER BY email ASC'
        . ' LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset, $p) as $x) {
        $emails[] = (string) $x['email'];
    }
    $groups = [];
    $ph = ala_in($emails);
    if ($ph !== null) {
        $acc = [];
        foreach (db_all('SELECT * FROM ' . T_EXT_ACCOUNTS . ' WHERE state IN (?, ?) AND email IN (' . $ph . ')'
            . ' ORDER BY service_id, id', array_merge(['new', 'unlinked'], $emails)) as $r) {
            $acc[(string) $r['email']][] = [
                'service_id'  => (string) $r['service_id'],
                'external_id' => (string) $r['external_id'],
                'username'    => $r['username'] === null ? null : (string) $r['username'],
                'name'        => $r['name'] === null ? null : (string) $r['name'],
                'state'       => (string) $r['state'],
                'row_version' => (int) $r['row_version'],
                'admin_flag'  => al_ext_is_admin($r['extra_json']),
            ];
        }
        $owners = [];
        foreach (db_all('SELECT uuid, username, email FROM ' . T_USERS . ' WHERE status <> ? AND email IN (' . $ph . ')',
            array_merge(['withdrawn'], $emails)) as $x) {
            $owners[(string) $x['email']] = ['uuid' => (string) $x['uuid'], 'username' => (string) $x['username']];
        }
        foreach ($emails as $e) {
            $groups[] = [
                'email'       => $e,
                'ulogin_user' => isset($owners[$e]) ? $owners[$e] : null,
                'accounts'    => isset($acc[$e]) ? $acc[$e] : [],
            ];
        }
    }
    json_out(['groups' => $groups, 'page' => $page, 'per_page' => $perPage, 'total' => $total]);
}

// =====================================================================
// G-7 job / jobs
// =====================================================================

/** 작업 행 → 표현. created_by: 관리자 아이디 | 'system' | null(push). */
function ala_job_out(array $j, array $totals, array $names): array
{
    $cb = $j['created_by'];
    if ($cb === null) {
        $by = null;
    } elseif ((int) $cb === 0) {
        $by = 'system';
    } else {
        $by = isset($names[(int) $cb]) ? $names[(int) $cb] : null;
    }
    return [
        'job_id'      => (string) $j['job_uuid'],
        'kind'        => (string) $j['kind'],
        'status'      => (string) $j['status'],
        'service_id'  => $j['service_id'] === null ? null : (string) $j['service_id'],
        'created_by'  => $by,
        'created_at'  => iso($j['created_at']),
        'finished_at' => iso($j['finished_at']),
        'expires_at'  => iso($j['expires_at']),
        'item_count'  => (int) $j['item_count'],
        'totals'      => [
            'done'    => (int) ($totals['done'] ?? 0),
            'failed'  => (int) ($totals['failed'] ?? 0),
            'skipped' => (int) ($totals['skipped'] ?? 0),
            'planned' => (int) ($totals['planned'] ?? 0),
        ],
    ];
}

function ala_admin_names(array $jobs): array
{
    $ids = [];
    foreach ($jobs as $j) {
        if ($j['created_by'] !== null && (int) $j['created_by'] > 0) {
            $ids[] = (int) $j['created_by'];
        }
    }
    $out = [];
    foreach (ala_users_by_id($ids) as $id => $u) {
        $out[$id] = (string) $u['username'];
    }
    return $out;
}

if ($view === 'job') {
    $jid = in_raw($in, 'job_id');
    if (!al_is_uuid($jid)) {
        throw new AppError('VALIDATION_FAILED', 400, ['field' => 'job_id', 'message' => '작업 식별자 형식이 올바르지 않습니다.']);
    }
    $j = db_one('SELECT * FROM ' . T_LINK_JOBS . ' WHERE job_uuid = ?', [strtolower((string) $jid)]);
    if ($j === null) {
        throw new AppError('NOT_FOUND', 404, ['field' => 'job_id']);
    }
    $rows   = db_all('SELECT * FROM ' . T_LINK_ITEMS . ' WHERE job_id = ? ORDER BY seq ASC', [$j['id']]);
    $totals = [];
    $uids   = [];
    foreach ($rows as $r) {
        $totals[(string) $r['status']] = (int) ($totals[(string) $r['status']] ?? 0) + 1;
        $uids[] = (int) ($r['result_user_id'] ?? $r['target_user_id'] ?? 0);
    }
    $users = ala_users_by_id($uids);
    $items = [];
    foreach ($rows as $r) {
        $id = (int) ($r['result_user_id'] ?? $r['target_user_id'] ?? 0);
        $items[] = [
            'seq'         => (int) $r['seq'],
            'op'          => (string) $r['op'],
            'service_id'  => (string) $r['service_id'],
            'external_id' => (string) $r['external_id'],
            'verdict'     => (string) $r['verdict'],
            'status'      => (string) $r['status'],
            'error_code'  => $r['error_code'] === null ? null : (string) $r['error_code'],
            'target_ref'  => $r['target_ref'] === null ? null : (string) $r['target_ref'],
            'user'        => isset($users[$id]) ? ['uuid' => (string) $users[$id]['uuid'], 'username' => (string) $users[$id]['username']] : null,
            'done_at'     => iso($r['done_at']),
        ];
    }
    json_out(['job' => ala_job_out($j, $totals, ala_admin_names([$j])), 'items' => $items]);
}

// view === 'jobs'
$kind = v_enum($in, 'kind', ['plan', 'push', 'sync_request', 'system'], false);
list($page, $perPage, $offset) = page_params($in, 20);
$w = $kind === null ? '1 = 1' : 'kind = ?';
$p = $kind === null ? [] : [$kind];
$total = (int) db_val('SELECT COUNT(*) FROM ' . T_LINK_JOBS . ' WHERE ' . $w, $p);
$jobs  = db_all('SELECT * FROM ' . T_LINK_JOBS . ' WHERE ' . $w . ' ORDER BY id DESC LIMIT ' . (int) $perPage
    . ' OFFSET ' . (int) $offset, $p);
$tot = [];
$ids = [];
foreach ($jobs as $j) {
    $ids[] = (int) $j['id'];
}
$ph = ala_in($ids);
if ($ph !== null) {
    foreach (db_all('SELECT job_id, status, COUNT(*) AS n FROM ' . T_LINK_ITEMS . ' WHERE job_id IN (' . $ph . ') GROUP BY job_id, status', $ids) as $x) {
        $tot[(int) $x['job_id']][(string) $x['status']] = (int) $x['n'];
    }
}
$names = ala_admin_names($jobs);
$items = [];
foreach ($jobs as $j) {
    $items[] = ala_job_out($j, isset($tot[(int) $j['id']]) ? $tot[(int) $j['id']] : [], $names);
}
json_out(['items' => $items, 'page' => $page, 'per_page' => $perPage, 'total' => $total]);
