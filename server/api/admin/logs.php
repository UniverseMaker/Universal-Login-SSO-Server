<?php
declare(strict_types=1);
/**
 * GET /api/admin/logs.php?type=login|admin|secret_access — 로그 조회
 * 담당 계약: §8-20(로그인 로그) · §8-21(관리자 조작 로그) · secret_access 는 v1.4.0 신규
 * 리더 판정 D-0: 논리 경로를 `?type=` 으로 구분한다.
 * 최소 역할: type=login → manager / type=admin → admin / type=secret_access → admin
 *
 * 응답 최상위 키
 *   type=login          : items, total, page, per_page, summary
 *   type=admin          : items, total, page, per_page
 *   type=secret_access  : items, total, page, per_page, summary  (v1.4.0 신규 — D-153 H-2)
 *                         summary 는 v1.4.0 후속 추가 —
 *                         {rows, hits, ip_changed_rows}. **조건 전체**의 집계다.
 *                         이 표는 시간 버킷이라 total(행 수) 이 접근 횟수가 아니다.
 *
 * **기존 두 type 의 응답 shape 은 바뀌지 않았다.** 새 type 하나가 추가됐을 뿐이다.
 *
 * 조회이므로 admin_logs 에 기록하지 않는다(계약 §8 공통 — secret.reveal 만 예외).
 */
require_once __DIR__ . '/../../core/bootstrap.php';

only_method('GET');

if (!function_exists('admin_parse_dt')) {
    /**
     * ISO8601 / 'YYYY-MM-DD HH:MM:SS' / 'YYYY-MM-DD' → UTC DATETIME.
     * @param mixed $v
     */
    function admin_parse_dt($v, string $field, bool $endOfDay = false): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_string($v)) {
            v_fail($field, '날짜 형식이 올바르지 않습니다.');
        }
        $s = trim($v);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1) {
            return $s . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
        }
        $t = strtotime(str_replace('Z', ' UTC', $s));
        if ($t === false) {
            v_fail($field, '날짜 형식이 올바르지 않습니다.');
        }
        return gmdate('Y-m-d H:i:s', (int) $t);
    }
}

$ctx  = require_auth();
$viewerRole = ctx_global_role($ctx);   // v1.10.0 (설계 127 §4) — IP·UA·입력값 마스킹 기준(mask_for_viewer)
$type = (string) v_enum($_GET, 'type', ['login', 'admin', 'secret_access'], false, 'login');

$fromAt = admin_parse_dt(in_raw($_GET, 'from'), 'from', false);
$toAt   = admin_parse_dt(in_raw($_GET, 'to'), 'to', true);
list($page, $perPage, $offset) = page_params($_GET, 20);

$uuidRe = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

// =====================================================================
// type=login — 계약 §8-20 (manager)
// =====================================================================
if ($type === 'login') {
    require_global_role($ctx, 'manager');

    // v1.4.0: fail_client_auth 추가 (D-153 H-1 — exchange 의 service_secret 오인증).
    // fail_ratelimit 은 종전부터 이 목록에 있었지만 **기록하는 코드가 없어** 항상 0건이었다.
    // v1.4.0 부터 core/ratelimit.php 의 rate_limit_logged() 가 실제로 남긴다.
    //
    // 아래 셋은 보안 검증 후속에서 더 늘었다. 셋 다 **필터 값이 늘어난 것**이고
    // 응답 shape 은 그대로다(summary 는 이 배열을 그대로 키로 쓴다).
    //   fail_migrate_auth — server/migrate.php 의 superadmin 인증 실패 (V14-H-2)
    //   fail_refresh_reuse — 회수된 refresh 토큰의 재사용 = 탈취 신호 (A-6 / D-164)
    //
    // fail_refresh_revoked 는 보안 재검증 A-6 오탐이 만든 값이다. 종전에는 로그아웃 ·
    // 마이페이지 세션정리 · 비밀번호 변경 · 관리자 회수 **네 정상 경로**가 전부
    // fail_refresh_reuse 로 기록돼 「탈취 신호」의 대부분이 오탐이었다. 이제
    // 회수 사유가 refresh_rotated / refresh_reuse 일 때만 fail_refresh_reuse 이고
    // 나머지는 이 값으로 온다. **화이트리스트를 한쪽만 고치면 필터에서 사라진다** —
    // 이 배열과 api/me/login_logs.php 의 v_enum 목록은 항상 함께 본다.
    // v1.7.0: success_silent 추가 (설계 A-6 / D-235). 무재인증(무음) SSO 로 발급된 code 를
    // exchange 가 교환했을 때의 성공이다 — **비밀번호를 한 번도 묻지 않은 로그인**이라
    // success 와 구분해 남긴다. 무음 경로에 동의 화면을 두지 않기로 한 판정의 조건이
    // 「사후에 확인할 수 있을 것」이었고, 이 필터가 그 확인 창구다.
    // summary 는 이 배열을 그대로 키로 쓰므로 0건이어도 키가 나간다 — **값 추가이지
    // shape 변경이 아니다**(키는 늘고 기존 키는 그대로다).
    // v1.7.0 수정 라운드: issued_silent 추가 (감사 M17-3). **success_silent 와 다른 사건이다.**
    //   issued_silent  = 무음 code 를 **발급**했다 (api/sso/authorize_session.php)
    //   success_silent = 그 code 가 **교환**돼 토큰이 나갔다 (api/auth/exchange.php)
    // 발급만 있고 교환이 없는 줄 = 제3자가 피해자 브라우저를 무음 경로로 몰아넣었는데
    // 서비스가 state 불일치로 거절한 모양이다. **공격 시도가 가장 잘 보이는 자리**이고,
    // 1차 구현에서는 그 경우에 로그가 0행이었다.
    $results = ['success', 'success_silent', 'issued_silent', 'fail_credential', 'fail_locked',
                'fail_status', 'fail_ratelimit', 'fail_service_blocked', 'fail_service_access', 'fail_emergency_unset', 'fail_emergency_scope', 'fail_client_auth',
                'fail_migrate_auth', 'fail_refresh_reuse', 'fail_refresh_revoked', 'logout'];

    $q         = v_string($_GET, 'q', 1, 190, false);
    $serviceId = v_string($_GET, 'service_id', 1, 64, false);
    admin_service_id_reject_former($serviceId);   // v1.8.6 — 관리자 API 는 옛 id 를 받지 않는다(설계 104 B-2)
    $ip        = v_string($_GET, 'ip', 1, 45, false);
    $uuid      = v_string($_GET, 'uuid', 1, 64, false);

    $resultRaw = in_raw($_GET, 'result');
    $result    = null;
    if ($resultRaw !== null && $resultRaw !== '') {
        if (!is_string($resultRaw) || !in_array($resultRaw, $results, true)) {
            throw new AppError('VALIDATION_FAILED', 400, [
                'field' => 'result', 'message' => 'result 값이 올바르지 않습니다.',
            ]);
        }
        $result = $resultRaw;
    }

    $where  = ['1 = 1'];
    $params = [];

    if ($uuid !== null) {
        if (preg_match($uuidRe, $uuid) !== 1) {
            v_fail('uuid', '회원 식별자 형식이 올바르지 않습니다.');
        }
        $targetId = db_val('SELECT id FROM ' . T_USERS . ' WHERE uuid = ?', [$uuid]);
        if ($targetId === null) {
            throw new AppError('NOT_FOUND', 404, ['message' => '회원을 찾을 수 없습니다.']);
        }
        $where[]  = 'l.user_id = ?';
        $params[] = (int) $targetId;
    }
    if ($q !== null) {
        // v1.8.0 — ESCAPE 를 명시한다(설계 A30). 패턴과 절은 한 쌍이다 — core/request.php
        $like     = like_contains($q);
        $esc      = sql_like_escape();
        // v1.10.0 (설계 127 §4) — 없는 아이디로 실패한 입력(user_id NULL)은 검색 대상에서도 뺀다(마스킹 우회 오라클 차단)
        $where[]  = '((l.user_id IS NOT NULL AND l.username_input LIKE ?' . $esc . ') OR u.username LIKE ?' . $esc . ')';
        $params[] = $like;
        $params[] = $like;
    }
    if ($result !== null)    { $where[] = 'l.result = ?';     $params[] = $result; }
    if ($serviceId !== null) { $where[] = 'l.service_id = ?'; $params[] = $serviceId; }
    if ($ip !== null)        { $where[] = 'l.ip = ?';         $params[] = $ip; }
    if ($fromAt !== null)    { $where[] = 'l.created_at >= ?'; $params[] = $fromAt; }
    if ($toAt !== null)      { $where[] = 'l.created_at <= ?'; $params[] = $toAt; }
    $whereSql = implode(' AND ', $where);

    $joinSql = ' FROM ' . T_LOGIN_LOGS . ' l LEFT JOIN ' . T_USERS . ' u ON u.id = l.user_id WHERE ' . $whereSql;

    $total = (int) db_val('SELECT COUNT(*)' . $joinSql, $params);

    $rows = db_all(
        'SELECT l.id, l.username_input, l.service_id, l.result, l.ip, l.user_agent, l.created_at,'
        . ' u.uuid AS user_uuid, u.username'
        . $joinSql
        . ' ORDER BY l.id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
        $params
    );

    $items = [];
    foreach ($rows as $r) {
        // user_id 가 NULL 인 행은 존재하지 않는 계정에 대한 시도다 — username_input 만 채워진다
        $items[] = [
            'id'             => (int) $r['id'],
            'user_uuid'      => $r['user_uuid'] !== null ? (string) $r['user_uuid'] : null,
            'username'       => $r['username'] !== null ? (string) $r['username'] : null,
            // v1.10.0 (설계 127 §4) — 마스킹은 core/serializer.php 의 mask_for_viewer() 한 곳
            'username_input' => (string) mask_for_viewer('username_input', [(string) $r['username_input'], $r['user_uuid'] !== null], $viewerRole),
            'service_id'     => $r['service_id'] !== null ? (string) $r['service_id'] : null,
            'result'         => (string) $r['result'],
            'ip'             => (string) mask_for_viewer('ip', (string) $r['ip'], $viewerRole),
            'user_agent'     => (string) mask_for_viewer('ua', (string) $r['user_agent'], $viewerRole),
            'created_at'     => iso($r['created_at']),
        ];
    }

    // summary — 같은 필터 조건의 result 별 집계 1회. 값이 0인 키도 포함한다.
    $summary = [];
    foreach ($results as $k) {
        $summary[$k] = 0;
    }
    foreach (db_all('SELECT l.result AS r, COUNT(*) AS c' . $joinSql . ' GROUP BY l.result', $params) as $s) {
        // 표에 없는 result 값이 나와도 버리지 않는다
        $summary[(string) $s['r']] = (int) $s['c'];
    }

    json_out([
        'items'    => $items,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'summary'  => $summary,
    ]);
}

// =====================================================================
// type=secret_access — 시크릿 전달 감사 (v1.4.0 신규, D-153 H-2) · admin
//
// 기록은 core/logger.php 의 log_secret_access() 가 만든다. **시간 버킷 UPSERT** 라
// 한 행이 여러 요청을 담는다 — hits 가 그 수다. 값은 담기지 않는다(키 이름만).
//
// 없으면 무슨 일이 생기나: 기록만 하고 볼 곳이 없으면, `fail_ratelimit` 이
// 「필터는 있는데 항상 0건」이었던 것과 대칭인 상태가 된다 — 이번에는
// 「데이터는 있는데 화면이 없다」다. 그래서 읽는 경로를 같은 라운드에 만든다.
// =====================================================================
if ($type === 'secret_access') {
    if (!role_gte(ctx_global_role($ctx), 'admin')) {
        throw new AppError('FORBIDDEN', 403, ['message' => '시크릿 전달 로그는 admin 이상만 조회할 수 있습니다.']);
    }

    $svcId  = v_string($_GET, 'service_id', 1, 64, false);
    admin_service_id_reject_former($svcId);   // v1.8.6 — 관리자 API 는 옛 id 를 받지 않는다(설계 104 B-2)
    $source = v_enum($_GET, 'source', ['login', 'exchange', 'refresh', 'session'], false);
    $uuid   = v_string($_GET, 'uuid', 1, 64, false);

    $where  = ['1 = 1'];
    $params = [];
    if ($svcId !== null)  { $where[] = 's.service_id = ?'; $params[] = $svcId; }
    if ($source !== null) { $where[] = 's.source = ?';     $params[] = $source; }
    if ($uuid !== null) {
        if (preg_match($uuidRe, $uuid) !== 1) {
            v_fail('uuid', '회원 식별자 형식이 올바르지 않습니다.');
        }
        $uid = db_val('SELECT id FROM ' . T_USERS . ' WHERE uuid = ?', [$uuid]);
        if ($uid === null) {
            throw new AppError('NOT_FOUND', 404, ['message' => '회원을 찾을 수 없습니다.']);
        }
        $where[]  = 's.user_id = ?';
        $params[] = (int) $uid;
    }
    if ($fromAt !== null) { $where[] = 's.last_at >= ?'; $params[] = $fromAt; }
    if ($toAt !== null)   { $where[] = 's.last_at <= ?'; $params[] = $toAt; }

    $joinSql = ' FROM ' . T_SECRET_ACCESS . ' s LEFT JOIN ' . T_USERS . ' u ON u.id = s.user_id'
             . ' WHERE ' . implode(' AND ', $where);

    // ------------------------------------------------------------------
    // summary — **조건 전체**의 집계. frontend 요청.
    //
    // 이 표는 시간 버킷 UPSERT 라 `total`(= 행 수)이 접근 횟수가 아니다. 그런데
    // 페이저의 "전체 N건" 은 total 을 그대로 쓰므로, 화면이 계산할 수 있는 유일한
    // 횟수가 **현재 페이지의 hits 합**이었다. 실측된 대비가 그 오차의 크기다 —
    // 4행에 hits 288,046. 조건 전체 합계를 서버가 주지 않으면 화면은 그 차이를
    // 글자로 경고하는 것 말고 할 수 있는 것이 없다.
    //
    // COUNT 를 따로 돌리지 않고 같은 쿼리에서 함께 낸다 — 쿼리 수는 종전과 같다.
    // `SUM(hits)` 는 32비트를 넘길 수 있어 드라이버가 문자열로 준다. PHP 정수는
    // 64비트이므로 (int) 캐스팅이 안전하다.
    // ip_changed 는 목록 항목이 이미 first_ip <> last_ip 로 계산하는 값이고,
    // 여기서는 **조건 전체**에서 그런 행이 몇 개인지를 같은 규칙으로 센다.
    // ------------------------------------------------------------------
    $agg = db_one(
        'SELECT COUNT(*) AS rows_total, COALESCE(SUM(s.hits), 0) AS hits_total,'
        . ' COALESCE(SUM(CASE WHEN s.first_ip <> s.last_ip THEN 1 ELSE 0 END), 0) AS ip_changed_rows'
        . $joinSql,
        $params
    );
    $total   = (int) ($agg['rows_total'] ?? 0);
    $summary = [
        // rows 는 total 과 같은 값이다. 이름이 다른 자리에 같은 수를 두는 이유는,
        // 이 표에서 "행 수" 와 "전달 횟수" 를 나란히 놓지 않으면 total 이 횟수로 읽히기 때문이다.
        'rows'            => $total,
        'hits'            => (int) ($agg['hits_total'] ?? 0),
        'ip_changed_rows' => (int) ($agg['ip_changed_rows'] ?? 0),
    ];

    $rows  = db_all(
        'SELECT s.id, s.service_id, s.session_id, s.source, s.window_start, s.hits, s.secret_keys,'
        . ' s.first_ip, s.last_ip, s.first_at, s.last_at, u.uuid AS user_uuid, u.username'
        . $joinSql
        . ' ORDER BY s.last_at DESC, s.id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
        $params
    );

    $items = [];
    foreach ($rows as $r) {
        $keys = (string) $r['secret_keys'] === '' ? [] : explode(',', (string) $r['secret_keys']);
        $items[] = [
            'id'           => (int) $r['id'],
            'user_uuid'    => $r['user_uuid'] !== null ? (string) $r['user_uuid'] : null,
            'username'     => $r['username'] !== null ? (string) $r['username'] : null,
            'service_id'   => (string) $r['service_id'],
            'session_id'   => (string) $r['session_id'],
            'source'       => (string) $r['source'],
            'hits'         => (int) $r['hits'],
            // 키 **이름**만이다. 값은 어떤 경우에도 이 응답에 담기지 않는다.
            'secret_keys'  => $keys,
            'first_ip'     => (string) mask_for_viewer('ip', (string) $r['first_ip'], $viewerRole),
            'last_ip'      => (string) mask_for_viewer('ip', (string) $r['last_ip'], $viewerRole),
            // first_ip 와 last_ip 가 다르면 같은 세션이 여러 IP 에서 쓰였다는 신호다.
            'ip_changed'   => ((string) $r['first_ip'] !== (string) $r['last_ip']),
            'window_start' => iso($r['window_start']),
            'first_at'     => iso($r['first_at']),
            'last_at'      => iso($r['last_at']),
        ];
    }

    json_out([
        'items'    => $items,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        // 추가 키다. items·total·page·per_page 는 바뀌지 않았다.
        'summary'  => $summary,
    ]);
}

// =====================================================================
// type=admin — 계약 §8-21 (admin)
// =====================================================================
if (!role_gte(ctx_global_role($ctx), 'admin')) {
    throw new AppError('FORBIDDEN', 403, ['message' => '관리자 조작 로그는 admin 이상만 조회할 수 있습니다.']);
}

$action     = v_string($_GET, 'action', 1, 60, false);
$targetType = v_string($_GET, 'target_type', 1, 40, false);
$targetId   = v_string($_GET, 'target_id', 1, 64, false);
$adminUuid  = v_string($_GET, 'admin_uuid', 1, 64, false);

$where  = ['1 = 1'];
$params = [];

if ($adminUuid !== null) {
    if (preg_match($uuidRe, $adminUuid) !== 1) {
        v_fail('admin_uuid', '관리자 식별자 형식이 올바르지 않습니다.');
    }
    $aid = db_val('SELECT id FROM ' . T_USERS . ' WHERE uuid = ?', [$adminUuid]);
    if ($aid === null) {
        throw new AppError('NOT_FOUND', 404, ['message' => '관리자를 찾을 수 없습니다.']);
    }
    $where[]  = 'a.admin_id = ?';
    $params[] = (int) $aid;
}
if ($action !== null)     { $where[] = 'a.action = ?';      $params[] = $action; }
if ($targetType !== null) { $where[] = 'a.target_type = ?'; $params[] = $targetType; }
if ($targetId !== null)   { $where[] = 'a.target_id = ?';   $params[] = $targetId; }
if ($fromAt !== null)     { $where[] = 'a.created_at >= ?'; $params[] = $fromAt; }
if ($toAt !== null)       { $where[] = 'a.created_at <= ?';  $params[] = $toAt; }
$whereSql = implode(' AND ', $where);

$joinSql = ' FROM ' . T_ADMIN_LOGS . ' a LEFT JOIN ' . T_USERS . ' u ON u.id = a.admin_id WHERE ' . $whereSql;

$total = (int) db_val('SELECT COUNT(*)' . $joinSql, $params);

$rows = db_all(
    'SELECT a.id, a.action, a.target_type, a.target_id, a.before_json, a.after_json, a.memo, a.ip, a.created_at,'
    . ' u.uuid AS admin_uuid, u.username AS admin_username'
    . $joinSql
    . ' ORDER BY a.id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
    $params
);

// ---------------------------------------------------------------------
// target_label 을 행마다 조회하면 N+1 이 된다.
// 현재 페이지의 대상 식별자만 타입별로 묶어 최대 3회로 해결한다.
// ---------------------------------------------------------------------
$userIds = [];
$svcIds  = [];
$secIds  = [];
foreach ($rows as $r) {
    $tid = $r['target_id'];
    if ($tid === null || $tid === '') {
        continue;
    }
    if ($r['target_type'] === 'user') {
        $userIds[(string) $tid] = true;
    } elseif ($r['target_type'] === 'service') {
        $svcIds[(string) $tid] = true;
    } elseif ($r['target_type'] === 'secret') {
        $secIds[(int) $tid] = true;
    }
}

$labelUser = [];
if ($userIds !== []) {
    $keys = array_keys($userIds);
    $ph   = implode(',', array_fill(0, count($keys), '?'));
    foreach (db_all('SELECT uuid, username FROM ' . T_USERS . ' WHERE uuid IN (' . $ph . ')', $keys) as $x) {
        $labelUser[(string) $x['uuid']] = (string) $x['username'];
    }
}
$labelSvc = [];
if ($svcIds !== []) {
    $keys = array_keys($svcIds);
    $ph   = implode(',', array_fill(0, count($keys), '?'));
    // v1.8.6 — 과거 감사 행(admin_logs)은 옛 id 그대로다(불변 기록). 이름이 보이게 옛 id 로도 찾는다(설계 104 B-2).
    foreach (db_all('SELECT service_id, service_name, former_service_id FROM ' . T_SERVICES
        . ' WHERE service_id IN (' . $ph . ') OR former_service_id IN (' . $ph . ')', array_merge($keys, $keys)) as $x) {
        $labelSvc[(string) $x['service_id']] = (string) $x['service_name'];
        if ($x['former_service_id'] !== null && $x['former_service_id'] !== '' && !isset($labelSvc[(string) $x['former_service_id']])) {
            $labelSvc[(string) $x['former_service_id']] = (string) $x['service_name'];
        }
    }
}
$labelSec = [];
if ($secIds !== []) {
    $keys = array_keys($secIds);
    $ph   = implode(',', array_fill(0, count($keys), '?'));
    foreach (db_all('SELECT id, service_id, secret_key FROM ' . T_SECRETS . ' WHERE id IN (' . $ph . ')', $keys) as $x) {
        $labelSec[(int) $x['id']] = (string) $x['service_id'] . ' / ' . (string) $x['secret_key'];
    }
}

$items = [];
foreach ($rows as $r) {
    $tid   = $r['target_id'] !== null ? (string) $r['target_id'] : null;
    $label = null;
    if ($tid !== null) {
        if ($r['target_type'] === 'user') {
            $label = $labelUser[$tid] ?? null;
        } elseif ($r['target_type'] === 'service') {
            $label = $labelSvc[$tid] ?? null;
        } elseif ($r['target_type'] === 'secret') {
            $label = $labelSec[(int) $tid] ?? null;
        } else {
            $label = $tid;      // setting 등은 키 자체가 이름이다
        }
    }
    $items[] = [
        'id'             => (int) $r['id'],
        'admin_uuid'     => $r['admin_uuid'] !== null ? (string) $r['admin_uuid'] : null,
        'admin_username' => $r['admin_username'] !== null ? (string) $r['admin_username'] : null,
        'action'         => (string) $r['action'],
        'target_type'    => (string) $r['target_type'],
        'target_id'      => $tid,
        'target_label'   => $label,
        // 문자열이 아니라 파싱된 객체로 내보낸다 (계약 §8-21)
        'before'         => $r['before_json'] === null ? null : json_object(json_col($r['before_json'])),
        'after'          => $r['after_json'] === null ? null : json_object(json_col($r['after_json'])),
        'memo'           => $r['memo'] !== null ? (string) $r['memo'] : null,
        'ip'             => (string) mask_for_viewer('ip', (string) $r['ip'], $viewerRole),
        'created_at'     => iso($r['created_at']),
    ];
}

json_out([
    'items'    => $items,
    'total'    => $total,
    'page'     => $page,
    'per_page' => $perPage,
]);
