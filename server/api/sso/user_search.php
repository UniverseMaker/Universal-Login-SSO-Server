<?php
declare(strict_types=1);
/**
 * POST /server/api/sso/user_search.php — 공유 대상을 고르는 계정 찾기 (s2s) (v1.10.0)
 * 담당 계약: `_workspace/127_architect_v1100_design.md` §3-3 · §7 S7 · 판정 D-367
 * 요청: {service_id, service_secret, q, limit?}
 * 응답 최상위 키: items, truncated   (items[]: uuid, username, name — 이 셋뿐. email·role·status 는 싣지 않는다)
 *
 * 일치: q(trim, 제어문자 거절, 1~50자) → ① username 완전 일치(소문자) ② 2자 이상이면 name 접두(LIKE, % _ 이스케이프).
 * 거름: status=active · account_kind=normal · **호출 서비스에 대한 단계 ≥ view**(access_level_eval — 판정 사본 없음).
 * 후보는 SQL LIMIT 200 으로 뽑은 뒤 판정하고 limit(기본 10, 최대 20)으로 자른다. 완전 일치가 먼저, 그다음 name 오름차순.
 * 빈도: IP 300/300(인증 전) · 서비스 30/60(인증 뒤). 호출마다 감사(sso.user_search) — **검색어 원문은 남기지 않는다**.
 * 감사 기록 실패면 500(users.php 와 같다 — 결과가 나갔는데 기록이 없는 상태를 만들지 않는다).
 */
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/accountlink.php';

only_method('POST');
rate_limit('svcapi:ip:' . client_ip(), 300, 300);
$in = body_json();

$qRaw = $in['q'] ?? null;
$q = is_string($qRaw) ? trim($qRaw) : '';
$qLen = mb_strlen($q, 'UTF-8');
if (!is_string($qRaw) || $qLen < 1 || $qLen > 50 || preg_match('/[\x00-\x1F\x7F]/u', $q) === 1 || preg_match('//u', $q) !== 1) {
    throw new AppError('VALIDATION_FAILED', 400, ['field' => 'q', 'message' => '검색어는 1~50자입니다.']);
}
$limit = 10;
if (array_key_exists('limit', $in) && $in['limit'] !== null) {
    if (!is_int($in['limit']) || $in['limit'] < 1) {
        throw new AppError('VALIDATION_FAILED', 400, ['field' => 'limit', 'message' => 'limit 은 1~20 입니다.']);
    }
    $limit = min(20, (int) $in['limit']);
}

$svc = al_s2s_auth($in);
$sid = (string) $svc['service_id'];
rate_limit('svcapi:search:' . $sid, 30, 60);

// 후보 — 필드는 판정에 필요한 것만 읽는다(이메일·전화 등은 SELECT 하지 않는다, T1-2)
$cols = 'id, uuid, username, name, status, account_kind, role';
$lower = mb_strtolower($q, 'UTF-8');
$cands = [];
foreach (db_all('SELECT ' . $cols . ' FROM ' . T_USERS . ' WHERE username = ? AND status = ? AND account_kind = ? LIMIT 1',
    [$lower, 'active', 'normal']) as $r) {
    $r['_exact'] = 1;
    $cands[(int) $r['id']] = $r;
}
$mode = 'exact';
if ($qLen >= 2) {
    $mode = 'prefix';
    $pat = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
    foreach (db_all('SELECT ' . $cols . ' FROM ' . T_USERS . ' WHERE name LIKE ?' . sql_like_escape()
        . ' AND status = ? AND account_kind = ? ORDER BY name ASC, id ASC LIMIT 200', [$pat, 'active', 'normal']) as $r) {
        if (!isset($cands[(int) $r['id']])) {
            $r['_exact'] = 0;
            $cands[(int) $r['id']] = $r;
        }
    }
}

// 거름 — 호출 서비스에 대한 단계 ≥ view. 판정은 access_level_eval() 하나(쿼리: user_services IN · 그룹 행 IN).
$ok = [];
if ($cands !== []) {
    $ids = array_keys($cands);
    $usBy = [];
    foreach (array_chunk($ids, 500) as $chunk) {
        foreach (db_all('SELECT * FROM ' . T_USER_SERVICES . ' WHERE service_id = ? AND user_id IN ('
            . implode(',', array_fill(0, count($chunk), '?')) . ')', array_merge([$sid], $chunk)) as $r) {
            $usBy[(int) $r['user_id']] = $r;
        }
    }
    $grp = access_group_rows($ids, $sid);
    foreach ($cands as $uid => $u) {
        $d = access_level_eval($u, $svc, $usBy[$uid] ?? null, $grp[$uid][$sid] ?? []);
        if (access_rank($d['level']) >= ACCESS_RANK['view']) {   // USER-SEARCH-LEVEL
            $ok[] = $u;
        }
    }
}
usort($ok, function (array $a, array $b): int {
    if ($a['_exact'] !== $b['_exact']) {
        return $b['_exact'] <=> $a['_exact'];
    }
    $c = strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    return $c !== 0 ? $c : ((int) $a['id'] <=> (int) $b['id']);
});
$truncated = count($ok) > $limit;
$items = [];
foreach (array_slice($ok, 0, $limit) as $u) {
    $items[] = [
        'uuid'     => (string) $u['uuid'],
        'username' => (string) $u['username'],
        'name'     => ($u['name'] ?? null) === null || (string) $u['name'] === '' ? null : (string) $u['name'],
    ];
}

// 감사 — 실패면 500. 검색어 원문은 남기지 않는다(사람 이름이다) — 길이와 방식만.
$row = audit_ulogin_row('service', null, 'sso.user_search', 'service', $sid, ['count' => ['before' => null, 'after' => count($items)]],
    'q_len=' . $qLen . ' mode=' . $mode);
$row['actor_local'] = $sid;
audit_append([$row]);
plat_usage_add($sid, 'sso_user_search', 1, true);

json_out(['items' => $items, 'truncated' => $truncated]);
