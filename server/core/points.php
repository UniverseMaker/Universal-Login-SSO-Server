<?php
declare(strict_types=1);

/**
 * core/points.php — 포인트 원장 변경 공통(points_apply) · 공동 포인트 s2s 허용·한도 (v1.10.1)
 * 담당 계약: 설계 130 §4 · 판정 D-371 Q-5(성공 건은 감사 해시 체인에도)
 *
 * points_apply() 는 v1.10.0 `api/points/consume.php` 의 트랜잭션 본문(잠금 → 멱등 확인 → 조건부 UPDATE(CAS) → log_point)
 * 을 올린 것이다. consume 과 s2s 4종이 공유한다 — 잠금·CAS 를 두 곳에 따로 쓰면 한쪽이 빠진다.
 * 잠금: MySQL 은 sql_for_update()(s2s 는 **services 행 → users 행 순서**), SQLite 는 db_tx() 의 BEGIN IMMEDIATE.
 * 로드: bootstrap 을 지난 뒤 require_once.
 */

if (!defined('ULOGIN_BOOTSTRAPPED')) {
    header_remove('X-Powered-By');
    http_response_code(404);
    exit;
}

const POINTS_CFG_BOOL      = ['enabled', 'earn', 'spend'];
const POINTS_CFG_INT_MAX   = ['per_tx_max' => 1000000, 'daily_user_earn_max' => 100000000,
                              'daily_user_spend_max' => 100000000, 'daily_service_earn_max' => 100000000];
const POINTS_BALANCE_MAX   = 2000000000;   // users.points 는 INT
const POINTS_S2S_KEY_RE    = '/^[A-Za-z0-9._:-]{8,100}$/';
const POINTS_S2S_PREFIX    = 's2s:';
const POINTS_WINDOW_SEC    = 86400;        // 「일」= 최근 24시간 이동 창(설계 §4-3)

// =====================================================================
// 서비스별 허용·한도 — services.points_json
// =====================================================================

/**
 * points 설정 검증. 7키가 정확히 있고 형이 맞아야 한다 — 하나라도 틀리면 **전체 무효**(null).
 * 저장 경로(admin/services · 설정 번들)와 읽기 경로(s2s)가 같은 함수를 쓴다.
 * @param mixed $v
 * @return array|null 키 순서가 고정된 정규형
 */
function points_cfg_check($v, ?string &$reason = null): ?array
{
    $reason = null;
    if (!is_array($v) || $v === [] || array_keys($v) === range(0, count($v) - 1)) {
        $reason = 'points 는 객체여야 합니다.';
        return null;
    }
    $out = [];
    foreach (POINTS_CFG_BOOL as $k) {
        if (!array_key_exists($k, $v) || !is_bool($v[$k])) {
            $reason = $k . ' 는 true/false 여야 합니다.';
            return null;
        }
        $out[$k] = $v[$k];
    }
    foreach (POINTS_CFG_INT_MAX as $k => $max) {
        if (!array_key_exists($k, $v) || !is_int($v[$k]) || $v[$k] < 1 || $v[$k] > $max) {
            $reason = $k . ' 는 1 ~ ' . $max . ' 사이의 정수여야 합니다.';
            return null;
        }
        $out[$k] = $v[$k];
    }
    foreach ($v as $k => $unused) {
        if (!array_key_exists((string) $k, $out)) {
            $reason = '정의되지 않은 키입니다: ' . (string) $k;
            return null;
        }
    }
    return $out;
}

function points_cfg_encode(array $cfg): string
{
    return (string) json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** services 행의 points_json → 정규형 | null(NULL·깨짐·형 틀림 = 막음, fail-closed). */
function points_cfg_from_row(?array $svc): ?array
{
    $raw = $svc['points_json'] ?? null;
    if ($raw === null || (string) $raw === '') {
        return null;
    }
    $dec = json_decode((string) $raw, true);
    $cfg = points_cfg_check($dec);
    if ($cfg === null) {
        log_throttled('points_cfg_invalid', '[ulogin] services.points_json is invalid for ' . (string) ($svc['service_id'] ?? '?')
            . ' - treated as disabled');
    }
    return $cfg;
}

/** 저장 키 = 's2s:' + sha256(sid \n key) — 68자. 서비스끼리 같은 키를 써도 충돌하지 않는다(설계 §4-5). */
function points_s2s_store_key(string $sid, string $key): string
{
    return POINTS_S2S_PREFIX . hash('sha256', $sid . "\n" . $key);
}

// =====================================================================
// 원장 변경 공통
// =====================================================================

/**
 * 잔액 변경. 성공이면 ['replay'=>false, points, delta, balance_after, total_points_spent, total_points_earned, log_id, before],
 * 멱등 재실행이면 ['replay'=>true, 'log'=>원장 행].
 *
 * @param array $meta reason, service_id, ref_type, ref_id, memo, idempotency_key(저장 키 | null),
 *                    compare(bool — 재실행 시 (reason, delta, service_id, ref_type, ref_id) 비교, 다르면 409),
 *                    lock_service(string|null — 먼저 잠글 services.service_id),
 *                    missing([code, status] — 사용자 행이 없을 때)
 * @param callable|null $inTxCheck function(array $userRow): void — 잠근 뒤(멱등 확인 뒤) 부른다. 한도 검사 자리
 */
function points_apply(int $userId, int $delta, array $meta, ?callable $inTxCheck = null): array
{
    if ($delta === 0) {
        throw new InvalidArgumentException('points_apply: delta must not be 0');
    }
    $idem    = $meta['idempotency_key'] ?? null;
    $compare = !empty($meta['compare']);
    $now     = now_utc();
    $replayCheck = function (array $prev) use ($compare, $delta, $meta): array {
        if ($compare) {
            $same = (string) $prev['reason'] === (string) $meta['reason']
                && (int) $prev['delta'] === $delta
                && (string) ($prev['service_id'] ?? '') === (string) ($meta['service_id'] ?? '')
                && (string) ($prev['ref_type'] ?? '') === (string) ($meta['ref_type'] ?? '')
                && (string) ($prev['ref_id'] ?? '') === (string) ($meta['ref_id'] ?? '');
            if (!$same) {
                throw new AppError('CONFLICT', 409, ['field' => 'idempotency_key', 'reason' => 'idempotency_mismatch']);
            }
        }
        return ['replay' => true, 'log' => $prev];
    };

    try {
        return db_tx(function () use ($userId, $delta, $meta, $idem, $inTxCheck, $now, $replayCheck) {
            // 0) 서비스 행 먼저(MySQL 교착 방지 순서: services → users). consume 은 services 를 잠그지 않는다.
            if (!empty($meta['lock_service'])) {
                db_val('SELECT id FROM ' . T_SERVICES . ' WHERE service_id = ?' . sql_for_update(), [(string) $meta['lock_service']]);
            }
            // 1) 잔액을 잠근다. 이 락이 이중 변경을 막는 1차 수단이다(2차는 아래 조건부 UPDATE).
            $u = db_one('SELECT id, points, total_points_spent, total_points_earned FROM ' . T_USERS . ' WHERE id = ?'
                . sql_for_update(), [$userId]);
            if ($u === null) {
                $miss = $meta['missing'] ?? ['NOT_FOUND', 404];
                throw new AppError((string) $miss[0], (int) $miss[1]);
            }
            // 2) 락을 잡은 뒤에 멱등 키를 확인한다. 락 전에 보면 두 요청이 동시에 「없음」을 읽고 둘 다 바꾼다.
            if ($idem !== null) {
                $prev = db_one('SELECT * FROM ' . T_POINT_LOGS . ' WHERE user_id = ? AND idempotency_key = ?', [$userId, $idem]);
                if ($prev !== null) {
                    return $replayCheck($prev);
                }
            }
            // 3) 한도 — 잠근 뒤에 센다(밖에서 세면 동시 요청이 같은 합계를 읽고 둘 다 통과한다)
            if ($inTxCheck !== null) {
                $inTxCheck($u);
            }
            $before = (int) $u['points'];
            $after  = $before + $delta;
            if ($after < 0) {
                throw new AppError('POINT_INSUFFICIENT', 409, [
                    'field'     => 'amount',
                    'points'    => $before,
                    'required'  => -$delta,
                    'shortfall' => -$delta - $before,
                ]);
            }
            if ($after > POINTS_BALANCE_MAX) {
                throw new AppError('CONFLICT', 409, ['field' => 'amount', 'reason' => 'balance_overflow']);
            }
            $spent  = (int) $u['total_points_spent'];
            $earned = (int) $u['total_points_earned'];
            if ($delta < 0) {
                $spentNew = $spent - $delta;
                $cas = db_exec('UPDATE ' . T_USERS . ' SET points = ?, total_points_spent = ?, updated_at = ?'
                    . ' WHERE id = ? AND points = ? AND total_points_spent = ?',
                    [$after, $spentNew, $now, $userId, $before, $spent]);
                $spent = $spentNew;
            } else {
                $earnedNew = $earned + $delta;
                $cas = db_exec('UPDATE ' . T_USERS . ' SET points = ?, total_points_earned = ?, updated_at = ?'
                    . ' WHERE id = ? AND points = ? AND total_points_earned = ?',
                    [$after, $earnedNew, $now, $userId, $before, $earned]);
                $earned = $earnedNew;
            }
            // 조건부 UPDATE — 0행이면 잠금이 빠졌다는 뜻이고 그때 쓰면 이중 변경이므로 **던져서 롤백한다**.
            // |delta| >= 1 이라 points 가 반드시 바뀐다 — rowCount 의 MySQL/SQLite 의미 차이(설계 A32)에 기대지 않는다.
            if ($cas !== 1) {
                throw new RuntimeException('points_apply: balance changed under the lock (rows=' . $cas
                    . ', user_id=' . $userId . '). the row lock / BEGIN IMMEDIATE is not in effect.');
            }
            $logId = log_point($userId, [
                'delta'           => $delta,
                'balance_after'   => $after,
                'reason'          => (string) $meta['reason'],
                'service_id'      => $meta['service_id'] ?? null,
                'ref_type'        => $meta['ref_type'] ?? null,
                'ref_id'          => $meta['ref_id'] ?? null,
                'idempotency_key' => $idem,
                'memo'            => $meta['memo'] ?? null,
            ]);
            return [
                'replay'              => false,
                'before'              => $before,
                'points'              => $after,
                'delta'               => $delta,
                'balance_after'       => $after,
                'total_points_spent'  => $spent,
                'total_points_earned' => $earned,
                'log_id'              => $logId,
                'created_at'          => $now,
            ];
        });
    } catch (Throwable $e) {
        // 동시 요청이 같은 멱등 키로 들어와 UNIQUE 에 걸린 경우 — 이쪽은 롤백됐고 상대가 완료했다. 그 결과로 답한다.
        if ($idem !== null && db_is_duplicate($e)) {
            $prev = db_one('SELECT * FROM ' . T_POINT_LOGS . ' WHERE user_id = ? AND idempotency_key = ?', [$userId, $idem]);
            if ($prev !== null) {
                return $replayCheck($prev);
            }
        }
        throw $e;
    }
}

// =====================================================================
// 공동 포인트 s2s (설계 130 §4)
// =====================================================================

/**
 * 대상 판정(4종 공통, **순서 고정** — 설계 §4-2). al_s2s_auth() 를 지난 뒤에만 부른다.
 *   1. uuid 없음 · 비상 계정 → 404 NOT_FOUND
 *   2. status ≠ active → 403 FORBIDDEN reason=user_inactive
 *   3. 접근 단계 < use → 403 SERVICE_ACCESS_DENIED (view 는 거절)
 *   4. points_json NULL·깨짐·enabled=false → 403 FORBIDDEN reason=points_disabled · earn/spend 꺼짐 → earn_disabled|spend_disabled
 * @return array [user, cfg]
 */
function points_s2s_target(array $svc, string $uuid, string $op): array
{
    $sid  = (string) $svc['service_id'];
    $user = db_one('SELECT * FROM ' . T_USERS . ' WHERE uuid = ?', [strtolower($uuid)]);
    if ($user === null || is_emergency_user($user)) {
        throw new AppError('NOT_FOUND', 404, ['field' => 'uuid']);
    }
    if ((string) $user['status'] !== 'active') {
        log_throttled('points_s2s_deny', '[ulogin] points s2s ' . $op . ' denied (user_inactive) for ' . $sid);
        throw new AppError('FORBIDDEN', 403, ['reason' => 'user_inactive']);
    }
    $us = db_one('SELECT * FROM ' . T_USER_SERVICES . ' WHERE user_id = ? AND service_id = ?', [(int) $user['id'], $sid]);
    $d  = access_level_eval($user, $svc, $us);
    if (access_rank((string) ($d['level'] ?? 'none')) < access_rank('use')) {
        log_throttled('points_s2s_deny', '[ulogin] points s2s ' . $op . ' denied (access level) for ' . $sid);
        throw new AppError('SERVICE_ACCESS_DENIED', 403);
    }
    $cfg = points_cfg_from_row($svc);
    if ($cfg === null || $cfg['enabled'] !== true) {
        log_throttled('points_s2s_deny', '[ulogin] points s2s ' . $op . ' denied (points_disabled) for ' . $sid);
        throw new AppError('FORBIDDEN', 403, ['reason' => 'points_disabled']);
    }
    if ($op === 'earn' && $cfg['earn'] !== true) {
        throw new AppError('FORBIDDEN', 403, ['reason' => 'earn_disabled']);
    }
    if ($op === 'spend' && $cfg['spend'] !== true) {
        throw new AppError('FORBIDDEN', 403, ['reason' => 'spend_disabled']);
    }
    return [$user, $cfg];
}

/**
 * 최근 24시간 사용량 SUM(ABS(delta)). $userId=null 이면 서비스 전체.
 * v1.10.1 보안 M-1 (D-375) — s2s 경로가 쓴 행(저장 키 `s2s:` 접두, points_s2s_store_key)만 센다.
 * reason 만 보면 사용자가 consume 으로 reason='s2s_earn' 행을 쌓아 서비스 한도를 채울 수 있었다.
 * consume.php 는 `s2s:` 키와 `s2s_` reason 을 둘 다 거절하므로 이 조건을 만족하는 행은 s2s 4종만 만든다.
 */
function points_s2s_used(string $sid, ?int $userId, string $reason): int
{
    $since = gmdate('Y-m-d H:i:s', time() - POINTS_WINDOW_SEC);
    $s2s   = POINTS_S2S_PREFIX . '%';   // 's2s:%' — 접두에 LIKE 특수문자(% _ !)가 없다
    if ($userId === null) {
        return (int) db_val('SELECT COALESCE(SUM(ABS(delta)), 0) FROM ' . T_POINT_LOGS
            . ' WHERE service_id = ? AND reason = ? AND created_at >= ? AND idempotency_key LIKE ?', [$sid, $reason, $since, $s2s]);
    }
    return (int) db_val('SELECT COALESCE(SUM(ABS(delta)), 0) FROM ' . T_POINT_LOGS
        . ' WHERE user_id = ? AND service_id = ? AND reason = ? AND created_at >= ? AND idempotency_key LIKE ?',
        [$userId, $sid, $reason, $since, $s2s]);
}

/** 한도 검사. **points_apply 의 $inTxCheck 안에서**(잠근 뒤) 부른다. 넘으면 429 QUOTA_EXCEEDED. */
function points_s2s_limits(array $cfg, string $sid, int $userId, string $op, int $amount): void
{
    $q = function (string $reason, int $limit, ?int $used) use ($amount) {
        log_throttled('points_s2s_quota', '[ulogin] points s2s quota ' . $reason);
        throw new AppError('QUOTA_EXCEEDED', 429, ['field' => 'amount', 'reason' => $reason, 'limit' => $limit,
            'used_24h' => $used, 'requested' => $amount]);
    };
    if ($amount > $cfg['per_tx_max']) {
        $q('per_tx', (int) $cfg['per_tx_max'], null);
    }
    if ($op === 'earn') {
        $u = points_s2s_used($sid, $userId, 's2s_earn');
        if ($u + $amount > $cfg['daily_user_earn_max']) {
            $q('daily_user_earn', (int) $cfg['daily_user_earn_max'], $u);
        }
        $s = points_s2s_used($sid, null, 's2s_earn');
        if ($s + $amount > $cfg['daily_service_earn_max']) {
            $q('daily_service_earn', (int) $cfg['daily_service_earn_max'], $s);
        }
        return;
    }
    $u = points_s2s_used($sid, $userId, 's2s_spend');
    if ($u + $amount > $cfg['daily_user_spend_max']) {
        $q('daily_user_spend', (int) $cfg['daily_user_spend_max'], $u);
    }
}

/** balance 응답의 limits. 남은 양은 0 아래로 내려가지 않는다. */
function points_s2s_limits_view(array $cfg, string $sid, int $userId): array
{
    $earnLeft = min($cfg['daily_user_earn_max'] - points_s2s_used($sid, $userId, 's2s_earn'),
                    $cfg['daily_service_earn_max'] - points_s2s_used($sid, null, 's2s_earn'));
    $spendLeft = $cfg['daily_user_spend_max'] - points_s2s_used($sid, $userId, 's2s_spend');
    return [
        'per_tx_max'          => (int) $cfg['per_tx_max'],
        'earn_remaining_24h'  => max(0, (int) $earnLeft),
        'spend_remaining_24h' => max(0, (int) $spendLeft),
        'earn'                => $cfg['earn'] === true,
        'spend'               => $cfg['spend'] === true,
    ];
}

/**
 * 성공 건을 감사 해시 체인에도 1행(D-371 Q-5 — 값 이동이므로). event_id 는 원장 id 에서 결정적으로 만든다.
 * 실패는 삼키고 실패 표식(H-3 — log_admin 과 같은 규칙). 원장(point_logs)이 1차 감사다.
 */
function points_s2s_audit(string $sid, array $user, string $op, array $res): void
{
    try {
        if (!defined('T_PLAT_AUDIT') || !db_table_exists(T_PLAT_AUDIT)) {
            return;
        }
        $row = audit_ulogin_row('service', null, 'points.s2s_' . $op, 'user', (string) $user['uuid'],
            ['points' => ['before' => (int) $res['before'], 'after' => (int) $res['balance_after']]],
            'log_id=' . (int) $res['log_id']);
        $row['actor_local'] = $sid;
        $h = hash('sha256', 'ulogin-point-log:' . (int) $res['log_id']);
        $row['event_id'] = substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-a' . substr($h, 17, 3)
            . '-' . substr($h, 20, 12);
        audit_append([$row]);
    } catch (Throwable $e) {
        error_log('[ulogin] points s2s audit chain write failed: ' . $e->getMessage());
        audit_mark_failure();
    }
}

/** earn/spend 입력. amount 는 **JSON 정수**만(문자열·소수 거절). */
function points_s2s_input(array $in): array
{
    $amount = $in['amount'] ?? null;
    if (!is_int($amount) || $amount < 1 || $amount > 100000000) {
        v_fail('amount', 'amount 는 1 ~ 100000000 사이의 정수여야 합니다.');
    }
    $key = $in['idempotency_key'] ?? null;
    if (!is_string($key) || preg_match(POINTS_S2S_KEY_RE, $key) !== 1) {
        v_fail('idempotency_key', 'idempotency_key 는 필수이며 영문·숫자·._:- 8~100자여야 합니다.');
    }
    return [
        'amount'   => $amount,
        'key'      => $key,
        'ref_type' => v_string($in, 'ref_type', 1, 40),
        'ref_id'   => v_string($in, 'ref_id', 1, 64),
        'memo'     => v_string($in, 'memo', 1, 255),
    ];
}

/** s2s 공통 머리: IP 빈도 → uuid 형식 → al_s2s_auth → 서비스 빈도. **DB 를 쓰는 판정은 이 뒤다.** */
function points_s2s_uuid(array $in): string
{
    $uuid = $in['uuid'] ?? null;
    if (!is_string($uuid) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', strtolower($uuid)) !== 1) {
        throw new AppError('VALIDATION_FAILED', 400, ['field' => 'uuid', 'message' => 'uuid 형식이 올바르지 않습니다.']);
    }
    return strtolower($uuid);
}

/** earn·spend 본체(두 파일이 같은 흐름을 쓴다 — 한쪽만 고쳐지지 않게). */
function points_s2s_move(array $svc, array $in, string $op): void
{
    $sid = (string) $svc['service_id'];
    $uuid = points_s2s_uuid($in);
    $x   = points_s2s_input($in);
    list($user, $cfg) = points_s2s_target($svc, $uuid, $op);
    $userId = (int) $user['id'];
    $delta  = $op === 'earn' ? $x['amount'] : -$x['amount'];
    $res = points_apply($userId, $delta, [
        'reason'          => $op === 'earn' ? 's2s_earn' : 's2s_spend',
        'service_id'      => $sid,
        'ref_type'        => $x['ref_type'],
        'ref_id'          => $x['ref_id'],
        'memo'            => $x['memo'],
        'idempotency_key' => points_s2s_store_key($sid, $x['key']),
        'compare'         => true,
        'lock_service'    => $sid,
        'missing'         => ['NOT_FOUND', 404],
    ], function (array $u) use ($cfg, $sid, $userId, $op, $x) {
        points_s2s_limits($cfg, $sid, $userId, $op, $x['amount']);
    });
    plat_usage_add($sid, 'points_' . $op, 1, true);
    if (!empty($res['replay'])) {
        $log = $res['log'];
        $cur = (int) db_val('SELECT points FROM ' . T_USERS . ' WHERE id = ?', [$userId]);
        json_out([
            'uuid'              => (string) $user['uuid'],
            'delta'             => (int) $log['delta'],
            'points'            => $cur,
            'balance_after'     => (int) $log['balance_after'],
            'log_id'            => (int) $log['id'],
            'idempotent_replay' => true,
            'created_at'        => iso($log['created_at']),
        ]);
    }
    points_s2s_audit($sid, $user, $op, $res);
    json_out([
        'uuid'              => (string) $user['uuid'],
        'delta'             => (int) $res['delta'],
        'points'            => (int) $res['points'],
        'balance_after'     => (int) $res['balance_after'],
        'log_id'            => (int) $res['log_id'],
        'idempotent_replay' => false,
        'created_at'        => iso($res['created_at']),
    ]);
}
