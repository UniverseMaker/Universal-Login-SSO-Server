<?php
declare(strict_types=1);

/**
 * core/emergency.php — 비상 로그인 확인값(ev) 발급·폐기 (v1.9.1)
 * 담당 계약: 주관 계약 `비상복구모드-계약-emergency1.md` 0장·1-1장·1-2a장 · 판정 D-362
 *
 * 비밀번호를 **직접 입력해 인증한 순간**(로그인·비밀번호 변경·재설정)에만, 그 계정이 연결돼 있고
 * 들어갈 수 있고 허용이 켜진 서비스마다 ev 를 만들어 사건 피드(link_events)에 `emergency_verifier` 로 싣는다.
 *
 *   dk  = PBKDF2-HMAC-SHA256(pw, salt, 310000, 32)                  — 발급 1회에 한 번만
 *   mac = HMAC-SHA256(key=dk, "emergency/1|" + service_id + "|" + uuid)   — 서비스마다
 *   ev  = "ev1$pbkdf2-sha256$310000$" + b64url(salt) + "$" + b64url(mac)
 *
 * 저장·전달 규칙
 *   · 사건은 link_job_items(op em_verifier|em_revoke)에 한 줄 — 피드 커서를 하나로 둔다. 값은 emergency 표에 있다.
 *   · ev 는 AES 봉인본으로만 저장하고, 새 값이 나가거나 폐기되면 비운다. 로그·감사·관리 응답·화면에 내지 않는다.
 *     나가는 곳은 피드 응답의 해당 사건 하나(em_feed_event)뿐이다.
 *   · 감사에는 emergency.issue / emergency.revoke 행만, 값 없이.
 *   · service_id 는 services.service_id(정규=새 id)다. 옛 id(별칭)를 쓰지 않는다.
 *
 * PHP 7.2 호환(core/bootstrap.php 상단 금지 목록).
 */

if (!defined('ULOGIN_BOOTSTRAPPED')) {
    header_remove('X-Powered-By');
    http_response_code(404);
    exit;
}

const EM_ITERS       = 310000;
const EM_PREFIX      = 'emergency/1|';
const EM_DEDUPE_SEC  = 600;     // 로그인 때 같은 (계정, 서비스)에 최근 10분 안에 나간 값이 있으면 다시 만들지 않는다
const EM_MIN_DAYS    = 1;
const EM_MAX_DAYS    = 365;
const EM_STRONG_RANK = 20;      // role_rank 20 이상(admin~) — 비밀번호 정책 강화 대상
const EM_STRONG_MIN  = 12;

// ---------------------------------------------------------------------
// 계산 (순수 함수 — 시험이 계약 식을 따로 재계산해 대조한다)
// ---------------------------------------------------------------------

function em_dk(string $password, string $salt): string
{
    return hash_pbkdf2('sha256', $password, $salt, EM_ITERS, 32, true);
}

function em_ev_from_dk(string $dk, string $salt, string $serviceId, string $uuid): string
{
    $mac = hash_hmac('sha256', EM_PREFIX . $serviceId . '|' . $uuid, $dk, true);
    return 'ev1$pbkdf2-sha256$' . EM_ITERS . '$' . base64url($salt) . '$' . base64url($mac);
}

// ---------------------------------------------------------------------
// 자격 판정
// ---------------------------------------------------------------------

/**
 * 그 (계정, 서비스)가 비상 확인값을 가질 수 있는가. 이유는 폐기 사유로 쓴다.
 * @return array{0:bool,1:string}
 */
function em_eligible(array $user, ?array $service, bool $linked): array
{
    if ($service === null || (string) $service['status'] !== 'active') {
        return [false, 'service_disabled'];
    }
    if ((int) ($service['emergency_allow'] ?? 1) !== 1) {
        return [false, 'emergency_disabled'];
    }
    if ((string) $user['status'] !== 'active') {
        return [false, ((string) $user['status'] === 'withdrawn') ? 'withdrawn' : 'suspended'];
    }
    if (is_emergency_user($user)) {
        return [false, 'emergency_account'];   // v1.10.0 §6-2 — 비상 관리자 계정은 SSO 대상이 아니다
    }
    if (!$linked) {
        return [false, 'unlinked'];
    }
    $sid = (string) $service['service_id'];
    if (service_membership_blocked((int) $user['id'], $sid)) {
        return [false, 'access_revoked'];
    }
    $d = service_access_eval($user, service_access_us_row((int) $user['id'], $sid), $service);
    if (empty($d['allowed'])) {
        return [false, 'access_revoked'];
    }
    return [true, ''];
}

function em_days(array $service): int
{
    $d = (int) ($service['emergency_days'] ?? 30);
    return max(EM_MIN_DAYS, min(EM_MAX_DAYS, $d <= 0 ? 30 : $d));
}

function em_is_linked(string $sid, int $userId): bool
{
    return db_val('SELECT id FROM ' . T_EXT_ACCOUNTS . ' WHERE service_id = ? AND user_id = ? AND state = ?',
        [$sid, $userId, 'linked']) !== null;
}

/** 사건을 싣는 고정 작업 1개(kind=system). 없으면 만든다. gc 가 지우지 않는 종류다. */
function em_job_id(): int
{
    $plan = '{"reason":"emergency"}';
    $id = db_val('SELECT id FROM ' . T_LINK_JOBS . ' WHERE kind = ? AND plan_json = ?', ['system', $plan]);
    if ($id !== null) {
        return (int) $id;
    }
    $now = now_utc();
    return db_insert(T_LINK_JOBS, [
        'job_uuid' => gen_uuid4(), 'kind' => 'system', 'service_id' => null, 'sync_id' => null,
        'status' => 'done', 'created_by' => 0, 'item_count' => 0, 'ok_count' => 0,
        'plan_json' => $plan, 'created_at' => $now, 'updated_at' => $now, 'finished_at' => $now,
    ]);
}

/** 사건 줄 하나(+ 값 행). 호출자의 트랜잭션 안에서 부른다. */
function em_put_event(string $op, string $sid, string $extId, int $userId, string $uuid, array $row): int
{
    $now  = now_utc();
    $item = db_insert(T_LINK_ITEMS, [
        'job_id' => em_job_id(), 'seq' => 0, 'op' => $op, 'service_id' => $sid, 'external_id' => $extId,
        'target_user_id' => $userId > 0 ? $userId : null, 'verdict' => 'ok', 'status' => 'done',
        'result_user_id' => $userId > 0 ? $userId : null, 'created_at' => $now, 'done_at' => $now,
    ]);
    $row['item_id']     = $item;
    $row['service_id']  = $sid;
    $row['external_id'] = $extId;
    $row['user_id']     = $userId > 0 ? $userId : null;
    $row['uuid']        = $uuid;
    db_insert(T_EMERGENCY, $row);
    return $item;
}

// ---------------------------------------------------------------------
// 발급
// ---------------------------------------------------------------------

/**
 * 비밀번호를 직접 인증한 직후 부른다. **실패해도 로그인을 막지 않는다**(삼키고 error_log, 값 없이).
 * @param bool $force true 면 최근 발급 중복 억제를 건너뛴다(비밀번호 변경·재설정)
 * @return int 발급한 서비스 수
 */
function em_issue_for_user(array $user, string $password, bool $force = false): int
{
    try {
        return em_issue_inner($user, $password, $force);
    } catch (Throwable $e) {
        error_log('[ulogin] emergency issue failed: ' . get_class($e) . ' ' . substr($e->getMessage(), 0, 120));
        return 0;
    }
}

function em_issue_inner(array $user, string $password, bool $force): int
{
    $plan = em_issue_prepare($user, $force);
    if ($plan === null) {
        return 0;
    }
    $plan['dk'] = em_dk($password, $plan['salt']);   // 느린 계산: 트랜잭션 밖, 발급 1회에 한 번만
    $pca = em_issue_verify($plan, $password);
    return $pca === null ? 0 : em_issue_store($plan, $pca);
}

/** 대상 서비스를 정한다(느린 계산 전). 없으면 null. */
function em_issue_prepare(array $user, bool $force): ?array
{
    $uid  = (int) $user['id'];
    $uuid = (string) $user['uuid'];
    if ($uid <= 0 || $uuid === '' || (string) $user['status'] !== 'active') {
        return null;
    }
    $rows = db_all('SELECT service_id, external_id FROM ' . T_EXT_ACCOUNTS . ' WHERE user_id = ? AND state = ?', [$uid, 'linked']);
    $targets = [];
    foreach ($rows as $r) {
        $sid = (string) $r['service_id'];
        $svc = db_one('SELECT * FROM ' . T_SERVICES . ' WHERE service_id = ?', [$sid]);
        $ok  = em_eligible($user, $svc, true);
        if (!$ok[0]) {
            continue;
        }
        if (!$force) {
            $last = db_one('SELECT issued_at FROM ' . T_EMERGENCY . ' WHERE service_id = ? AND uuid = ? AND kind = ?'
                . ' AND ev IS NOT NULL ORDER BY id DESC LIMIT 1', [$sid, $uuid, 'issue']);
            $lt = $last === null ? null : ts($last['issued_at']);
            if ($lt !== null && $lt > time() - EM_DEDUPE_SEC) {
                continue;
            }
        }
        $targets[] = [$svc, (string) $r['external_id']];
    }
    if ($targets === []) {
        return null;
    }
    return ['uid' => $uid, 'uuid' => $uuid, 'targets' => $targets, 'salt' => random_bytes(16), 'dk' => '', 'now' => now_utc()];
}

/**
 * H-1(D-365) — 계산이 끝난 **지금** 저장된 비밀번호 해시로 다시 확인한다. 로그인 도중 비밀번호가 바뀌었거나 정지됐으면
 * 옛 비밀번호의 값을 내지 않는다. 통과하면 그 시점의 password_epoch(변경마다 +1 하는 정수)를 돌려준다(저장 직전 트랜잭션이 다시 대조한다).
 */
function em_issue_verify(array $plan, string $password): ?string
{
    $cur = db_one('SELECT password_hash, password_epoch, status FROM ' . T_USERS . ' WHERE id = ?', [$plan['uid']]);
    if ($cur === null || (string) $cur['status'] !== 'active' || !password_verify($password, (string) $cur['password_hash'])) {
        return null;
    }
    return (string) (int) $cur['password_epoch'];
}

/** 대상별 저장. 각 트랜잭션 안에서 계정·서비스 자격과 password_epoch 를 다시 대조하고 다르면 버린다. */
function em_issue_store(array $plan, string $pca): int
{
    $uid = $plan['uid'];
    $uuid = $plan['uuid'];
    $n = 0;
    foreach ($plan['targets'] as $t) {
        $sid = (string) $t[0]['service_id'];
        $ev  = enc(em_ev_from_dk($plan['dk'], $plan['salt'], $sid, $uuid));
        $exp = at_utc(em_days($t[0]) * 86400);
        $ext = $t[1];
        $now = $plan['now'];
        $stored = db_tx(function () use ($sid, $uuid, $uid, $ext, $ev, $now, $exp, $pca) {
            $c2 = db_one('SELECT * FROM ' . T_USERS . ' WHERE id = ?' . sql_for_update(), [$uid]);
            $s2 = db_one('SELECT * FROM ' . T_SERVICES . ' WHERE service_id = ?', [$sid]);
            if ($c2 === null || (string) (int) $c2['password_epoch'] !== $pca   // TXCHECK
                || !em_eligible($c2, $s2, em_is_linked($sid, $uid))[0]) {
                return false;
            }
            // 새 값이 나가면 이전 값은 비운다(피드가 더는 내지 않는다)
            db_exec('UPDATE ' . T_EMERGENCY . ' SET ev = NULL WHERE service_id = ? AND uuid = ? AND kind = ? AND ev IS NOT NULL',
                [$sid, $uuid, 'issue']);
            em_put_event('em_verifier', $sid, $ext, $uid, $uuid, [
                'kind' => 'issue', 'ev' => $ev, 'reason' => null, 'issued_at' => $now, 'expires_at' => $exp,
            ]);
            return true;
        });
        if ($stored) {
            log_admin(0, 'emergency.issue', 'user', $uuid, null, ['service_id' => $sid, 'expires_at' => $exp]);
            $n++;
        }
    }
    return $n;
}

// ---------------------------------------------------------------------
// 폐기
// ---------------------------------------------------------------------

/**
 * 그 계정의 (선택: 한 서비스의) 살아 있는 값을 폐기한다. 값이 없는 서비스는 사건을 만들지 않는다.
 * 호출자의 트랜잭션 안에서도 밖에서도 부를 수 있다. 사용자 행이 이미 없어도 $uuid 로 된다.
 * @return int 폐기한 서비스 수
 */
function em_revoke_user(int $userId, string $reason, ?string $serviceId = null, ?string $uuid = null): int
{
    if ($uuid === null || $uuid === '') {
        $u = $userId > 0 ? db_val('SELECT uuid FROM ' . T_USERS . ' WHERE id = ?', [$userId]) : null;
        if ($u === null) {
            return 0;
        }
        $uuid = (string) $u;
    }
    $sql = 'SELECT e.service_id, e.external_id, e.kind FROM ' . T_EMERGENCY . ' e WHERE e.uuid = ?'
        . ' AND e.id = (SELECT MAX(x.id) FROM ' . T_EMERGENCY . ' x WHERE x.uuid = e.uuid AND x.service_id = e.service_id)';
    $p = [$uuid];
    if ($serviceId !== null) {
        $sql .= ' AND e.service_id = ?';
        $p[] = $serviceId;
    }
    $n = 0;
    foreach (db_all($sql, $p) as $r) {
        if ((string) $r['kind'] === 'issue') {
            em_revoke_one((string) $r['service_id'], (string) $r['external_id'], $userId, $uuid, $reason);
            $n++;
        }
    }
    return $n;
}

function em_revoke_one(string $sid, string $ext, int $userId, string $uuid, string $reason): void
{
    $now = now_utc();
    db_tx(function () use ($sid, $ext, $userId, $uuid, $reason, $now) {
        db_exec('UPDATE ' . T_EMERGENCY . ' SET ev = NULL WHERE service_id = ? AND uuid = ? AND kind = ? AND ev IS NOT NULL',
            [$sid, $uuid, 'issue']);
        em_put_event('em_revoke', $sid, $ext, $userId, $uuid, [
            'kind' => 'revoke', 'ev' => null, 'reason' => substr($reason, 0, 30), 'issued_at' => $now, 'expires_at' => null,
        ]);
    });
    log_admin(0, 'emergency.revoke', 'user', $uuid, null, ['service_id' => $sid, 'reason' => substr($reason, 0, 30)]);
}

/** 서비스별 자격을 다시 재서, 더는 못 가지는 서비스의 값만 폐기한다(정지·접근 제한·허용 끄기·차단). */
function em_reconcile_user(int $userId, string $reason = 'access_revoked'): int
{
    $user = $userId > 0 ? db_one('SELECT * FROM ' . T_USERS . ' WHERE id = ?', [$userId]) : null;
    if ($user === null) {
        return 0;
    }
    $n = 0;
    foreach (em_latest_issues('uuid = ?', [(string) $user['uuid']]) as $r) {
        $sid = (string) $r['service_id'];
        $ok  = em_eligible($user, db_one('SELECT * FROM ' . T_SERVICES . ' WHERE service_id = ?', [$sid]), em_is_linked($sid, $userId));
        if (!$ok[0]) {
            em_revoke_one($sid, (string) $r['external_id'], $userId, (string) $user['uuid'], $ok[1] !== '' ? $ok[1] : $reason);
            $n++;
        }
    }
    return $n;
}

/** 서비스 단위 재판정(접근 규칙 변경 · 허용 끄기 · 서비스 비활성). */
function em_reconcile_service(string $sid, string $reason = 'access_revoked'): int
{
    $n = 0;
    foreach (em_latest_issues('service_id = ?', [$sid]) as $r) {
        $uid  = (int) $r['user_id'];
        $user = $uid > 0 ? db_one('SELECT * FROM ' . T_USERS . ' WHERE id = ?', [$uid]) : null;
        if ($user === null) {
            continue;
        }
        $ok = em_eligible($user, db_one('SELECT * FROM ' . T_SERVICES . ' WHERE service_id = ?', [$sid]), em_is_linked($sid, $uid));
        if (!$ok[0]) {
            em_revoke_one($sid, (string) $r['external_id'], $uid, (string) $r['uuid'], $ok[1] !== '' ? $ok[1] : $reason);
            $n++;
        }
    }
    return $n;
}

/** (서비스, 계정)별 마지막 줄이 값 있는 issue 인 행들. $where 는 이 파일이 만든 고정 조각만 받는다. */
function em_latest_issues(string $where, array $p): array
{
    return db_all('SELECT e.service_id, e.external_id, e.user_id, e.uuid FROM ' . T_EMERGENCY . ' e WHERE e.' . $where
        . ' AND e.kind = ? AND e.ev IS NOT NULL AND e.id = (SELECT MAX(x.id) FROM ' . T_EMERGENCY
        . ' x WHERE x.uuid = e.uuid AND x.service_id = e.service_id)', array_merge($p, ['issue']));
}

/** 관리 화면용 — 그 계정의 살아 있는 값이 있는 서비스 목록(값은 내지 않는다). */
function em_live_services(string $uuid): array
{
    $out = [];
    foreach (em_latest_issues('uuid = ?', [$uuid]) as $r) {
        $e = db_one('SELECT expires_at FROM ' . T_EMERGENCY . ' WHERE service_id = ? AND uuid = ? AND kind = ? AND ev IS NOT NULL'
            . ' ORDER BY id DESC LIMIT 1', [(string) $r['service_id'], $uuid, 'issue']);
        $t = $e === null ? null : ts($e['expires_at']);
        if ($t !== null && $t > time()) {
            $out[] = ['service_id' => (string) $r['service_id'], 'expires_at' => iso((string) $e['expires_at'])];
        }
    }
    return $out;
}

// ---------------------------------------------------------------------
// 피드 (core/accountlink.php 의 al_feed 가 부른다)
// ---------------------------------------------------------------------

/**
 * 사건 줄 하나 → 피드 사건. 이미 대체·폐기되었거나 기한이 지난 값은 null(건너뛴다 — 커서는 나아간다).
 * 🔴 ev 가 응답에 실리는 유일한 곳이다.
 */
function em_feed_event(array $item): ?array
{
    $e = db_one('SELECT * FROM ' . T_EMERGENCY . ' WHERE item_id = ?', [(int) $item['id']]);
    if ($e === null) {
        return null;
    }
    $base = [
        'event_id'    => (int) $item['id'],
        'external_id' => (string) $e['external_id'],
        'uuid'        => (string) $e['uuid'],
        'at'          => iso((string) $item['done_at']),
    ];
    if ((string) $e['kind'] === 'revoke') {
        return array_merge($base, ['type' => 'emergency_revoke', 'reason' => $e['reason'] === null ? null : (string) $e['reason']]);
    }
    if ($e['ev'] === null) {
        return null;
    }
    $exp = ts($e['expires_at']);
    if ($exp === null || $exp <= time()) {
        return null;
    }
    $uid  = $e['user_id'] === null ? 0 : (int) $e['user_id'];
    $user = $uid > 0 ? db_one('SELECT * FROM ' . T_USERS . ' WHERE id = ?', [$uid]) : null;
    if ($user === null) {
        return null;
    }
    $ok = false;
    $ev = dec((string) $e['ev'], $ok);
    if (!$ok || $ev === '') {
        return null;
    }
    $g = effective_grades($user, service_access_us_row($uid, (string) $e['service_id']));
    return array_merge($base, [
        'type'       => 'emergency_verifier',
        'username'   => (string) $user['username'],
        'name'       => $user['name'] === null ? null : (string) $user['name'],
        'role'       => (string) $g['role'],
        'role_rank'  => rank_of(ROLE_RANK, (string) $g['role']),
        'status'     => (string) $user['status'],
        'ev'         => $ev,
        'issued_at'  => iso((string) $e['issued_at']),
        'expires_at' => iso((string) $e['expires_at']),
    ]);
}

/** gc — 기한 지난 값을 비우고, 값 없는 오래된 줄을 지운다. */
function em_gc(): void
{
    db_exec('UPDATE ' . T_EMERGENCY . ' SET ev = NULL WHERE ev IS NOT NULL AND expires_at < ?', [at_utc(-86400)]);
    $old = at_utc(-60 * 86400);
    db_delete_limited(T_EMERGENCY, 'ev IS NULL AND issued_at < ?', [$old], 1000);
    db_delete_limited(T_LINK_ITEMS, 'op IN (?, ?) AND done_at < ? AND id NOT IN (SELECT item_id FROM ' . T_EMERGENCY . ')',
        ['em_verifier', 'em_revoke', $old], 1000);
    em_reconcile_sweep();
}

/** 보존 sweep 이 어디까지 봤는지 저장하는 settings 키(마지막으로 본 emergency.id). */
const EM_SWEEP_CURSOR_KEY = 'em.sweep_cursor';

/**
 * M-3(D-365) · v1.9.2 커서 — 시간이 지나 자격을 잃었는데 사건이 없었던 대상을 정리하는 보존 job.
 * 살아 있는 값을 id 순으로 한 묶음(기본 100건)씩 em_eligible 로 다시 재서 잃은 것만 폐기한다.
 * 호출당 상한은 그대로 두되 **어디까지 봤는지 settings 에 저장**해 다음 호출이 이어서 한다.
 * 끝(묶음이 덜 찼음)에 닿으면 커서를 0 으로 되돌려 새 바퀴를 시작한다 — 한 바퀴 = ceil(N/100) 호출.
 * (예전 시각 기반 offset 은 호출 간격이 불규칙하면 같은 구간을 되풀이하고 다른 구간을 건너뛰었다.)
 */
function em_reconcile_sweep(int $limit = 100): int
{
    $cursor = (int) db_val('SELECT svalue FROM ' . T_SETTINGS . ' WHERE skey = ?', [EM_SWEEP_CURSOR_KEY]);
    $rows = db_all('SELECT e.id, e.service_id, e.external_id, e.user_id, e.uuid FROM ' . T_EMERGENCY . ' e'
        . ' WHERE e.kind = ? AND e.ev IS NOT NULL AND e.expires_at > ? AND e.id > ?'
        . ' AND e.id = (SELECT MAX(x.id) FROM ' . T_EMERGENCY . ' x WHERE x.uuid = e.uuid AND x.service_id = e.service_id)'
        . ' ORDER BY e.id ASC LIMIT ' . (int) $limit, ['issue', now_utc(), $cursor]);
    $n = 0;
    $last = 0;
    foreach ($rows as $r) {
        $last = (int) $r['id'];
        $uid  = $r['user_id'] === null ? 0 : (int) $r['user_id'];
        $user = $uid > 0 ? db_one('SELECT * FROM ' . T_USERS . ' WHERE id = ?', [$uid]) : null;
        $sid  = (string) $r['service_id'];
        $ok   = $user === null ? [false, 'deleted']
            : em_eligible($user, db_one('SELECT * FROM ' . T_SERVICES . ' WHERE service_id = ?', [$sid]), em_is_linked($sid, $uid));
        if (!$ok[0]) {   // SWEEP-REVOKE
            em_revoke_one($sid, (string) $r['external_id'], $uid, (string) $r['uuid'], $ok[1]);
            $n++;
        }
    }
    $next = count($rows) < $limit ? 0 : $last;   // SWEEP-CURSOR
    if ($next !== $cursor) {
        setting_set(EM_SWEEP_CURSOR_KEY, $next, 'int');
    }
    return $n;
}

// ---------------------------------------------------------------------
// 비밀번호 정책 강화 — role_rank 20 이상 (판정 D-362)
// ---------------------------------------------------------------------

/** 흔한 비밀번호 기반 단어. 소문자 비교, 앞뒤의 숫자·기호는 벗겨 본다. */
const EM_COMMON_PW = [
    'password', 'passw0rd', 'p@ssword', 'p@ssw0rd', 'qwerty', 'qwertyuiop', 'qwertyui', 'asdfgh', 'asdfghjkl', 'zxcvbn', 'zxcvbnm',
    'iloveyou', 'letmein', 'welcome', 'admin', 'administrator', 'root', 'login', 'master', 'monkey', 'dragon', 'sunshine',
    'princess', 'football', 'baseball', 'superman', 'batman', 'trustno1', 'abc123', 'abcdef', 'abcdefgh', 'changeme',
    'default', 'guest', 'user', 'test', 'tester', 'secret', 'shadow', 'michael', 'jennifer', 'hello', 'freedom', 'whatever',
    'qazwsx', 'qazwsxedc', '1qaz2wsx', 'zaq12wsx', 'q1w2e3r4', 'qwer1234', 'asdf1234', 'zxcv1234', 'aaaa1111', 'a1b2c3d4',
    'google', 'naver', 'daum', 'kakao', 'samsung', 'korea', 'korea1234', 'seoul', 'hunter', 'ulogin', 'research', 'system',
    'computer', 'internet', 'service', 'manager', 'company', 'office', 'summer', 'winter', 'spring', 'autumn', 'newyork',
    'pass', 'pass1234', 'passwd', 'pa55word', 'pa55w0rd', 'love', 'lovely', 'starwars', 'cheese', 'killer',
    '123456', '12345678', '123456789', '1234567890', '0123456789', '111111', '000000', '123123', '654321', '112233',
];

/** 비밀번호가 흔한 목록(변형 포함)에 걸리는가. */
function em_pw_is_common(string $pw, ?string $username = null): bool
{
    $l     = mb_strtolower($pw, 'UTF-8');
    $strip = (string) preg_replace('/[0-9!@#$%^&*._\-]+$/', '', $l);
    $core  = (string) preg_replace('/^[^a-z]+|[^a-z]+$/', '', strtr($l, ['@' => 'a', '0' => 'o', '1' => 'i', '3' => 'e', '$' => 's', '5' => 's']));
    foreach ([$l, $strip, $core] as $cand) {
        if ($cand !== '' && in_array($cand, EM_COMMON_PW, true)) {
            return true;
        }
    }
    foreach (EM_COMMON_PW as $w) {
        // 흔한 단어에 몇 글자만 덧붙인 형태(password2024!! 등). 단어가 비밀번호 대부분을 차지할 때만
        if (strlen($w) >= 6 && !ctype_digit($w) && strpos($l, $w) !== false && mb_strlen($l, 'UTF-8') <= strlen($w) + 6) {
            return true;
        }
    }
    if ($username !== null && strlen($username) >= 4 && strpos($l, mb_strtolower($username, 'UTF-8')) !== false) {
        return true;
    }
    $chars = preg_split('//u', $l, -1, PREG_SPLIT_NO_EMPTY);
    if (is_array($chars) && count(array_unique($chars)) <= 3) {
        return true;          // 같은 글자 반복
    }
    // 같은 낱말 반복(Password!Password! · letmeinletmein): 기호·숫자를 벗긴 글자가 한 덩어리의 반복이면
    $alpha = (string) preg_replace('/[^a-z]/', '', $l);
    if (strlen($alpha) >= 6 && preg_match('/^(.{3,}?)\1+$/', $alpha) === 1) {   // REPEAT-RULE
        return true;
    }
    $alnum = (string) preg_replace('/[^a-z0-9]/', '', $l);
    if (strlen($alnum) >= 8 && preg_match('/^(.{3,}?)\1+$/', $alnum) === 1) {
        return true;
    }
    return em_pw_has_keyboard_run($l);   // KEYBOARD-RULE
}

/** 키보드·숫자·알파벳 순서의 6글자 이상 연속(정방향·역방향). 1q2w3e4r5t6y · qwerty · 123456 · abcdef */
function em_pw_has_keyboard_run(string $l): bool
{
    foreach (['1q2w3e4r5t6y7u8i9o0p', 'qazwsxedcrfvtgbyhnujmikolp', '1qaz2wsx3edc4rfv5tgb', 'qwertyuiop', 'asdfghjkl', 'zxcvbnm',
              '1234567890', 'abcdefghijklmnopqrstuvwxyz', 'poiuytrewq', 'lkjhgfdsa', 'mnbvcxz'] as $seq) {
        foreach ([$seq, strrev($seq)] as $q) {
            $len = strlen($q);
            for ($i = 0; $i + 6 <= $len; $i++) {
                if (strpos($l, substr($q, $i, 6)) !== false) {
                    return true;
                }
            }
        }
    }
    return false;
}

/** role_rank 20 이상 계정의 강화 정책. 위반이면 v_fail. 기존 비밀번호는 다음 변경 때 적용한다(로그인을 막지 않는다). */
function em_enforce_strong_password(string $pw, ?string $username, int $roleRank, string $field = 'password'): void
{
    if ($roleRank < EM_STRONG_RANK) {
        return;
    }
    if (mb_strlen($pw, 'UTF-8') < EM_STRONG_MIN) {
        v_fail($field, '관리자 계정의 비밀번호는 ' . EM_STRONG_MIN . '자 이상이어야 합니다.');
    }
    if (em_pw_is_common($pw, $username)) {
        v_fail($field, '흔히 쓰는 비밀번호는 관리자 계정에 사용할 수 없습니다.');
    }
}
