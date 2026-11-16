<?php
declare(strict_types=1);
/**
 * POST /api/points/consume — 포인트 차감
 * 담당 계약: 01_architect_api_contract.md §7-1, 리더 판정 D-4
 * 응답 최상위 키: points, delta, balance_after, total_points_spent,
 *                 log_id, idempotent_replay, created_at
 *
 * 전역 포인트(users.points)만 다룬다 (D-4).
 *
 * amount 는 **양의 정수**다. 서버가 delta = -amount 로 기록한다.
 * 클라이언트가 delta 를 직접 보내게 하면 양수 delta 로 스스로 적립하는 구멍이 된다.
 * 적립은 관리자 엔드포인트로만 한다.
 *
 * 처리는 트랜잭션 + 잠근 읽기. 잠금이 없으면 동시 요청이 같은 잔액을 읽어 이중 차감된다.
 * v1.8.0 — 잠금 수단이 저장소마다 다르다(설계 B-2). MySQL 은 `SELECT ... FOR UPDATE`
 * (sql_for_update()), SQLite 는 db_tx() 의 `BEGIN IMMEDIATE`(트랜잭션 직렬화).
 * 그 위에 **조건부 UPDATE(읽은 잔액과 같을 때만 쓴다)** 를 2차 방어로 둔다 —
 * 잠금이 어떤 이유로 빠져도 이중 차감 대신 500 이 나고, 원장은 어긋나지 않는다.
 *
 * idempotency_key 가 같은 요청이 이미 있으면 재차감하지 않고 기존 결과를
 * 그대로 반환한다(200, idempotent_replay: true). 네트워크 재시도로 인한
 * 이중 차감은 애플리케이션 로직만으로 막을 수 없다 —
 * point_logs 의 (user_id, idempotency_key) UNIQUE 가 최종 방어선이다.
 */
require_once __DIR__ . '/../../core/bootstrap.php';

only_method('POST');

$ctx    = require_auth();
require_csrf();     // 쿠키 세션일 때만 실제로 검사한다. Bearer 는 면제.

$userId = (int) $ctx['user']['id'];

// 사용자 단위 레이트리밋. IP 단위로 걸면 같은 사무실의 다른 사용자가 함께 막힌다.
rate_limit('consume:user:' . $userId, 120, 60);

$in       = body_json();
$amount   = (int) v_int($in, 'amount', 1, 100000000, true);
$reason   = (string) v_string($in, 'reason', 1, 100, false, 'usage');
$svcId    = v_service_id($in, 'service_id');   // v1.8.6 — 원장에는 새 id 로 남는다(설계 104 C-1)
$refType  = v_string($in, 'ref_type', 1, 40);
$refId    = v_string($in, 'ref_id', 1, 64);
$memo     = v_string($in, 'memo', 1, 255);
$idemKey  = v_string($in, 'idempotency_key', 1, 100);

// service_id 를 주면 실재하는 활성 서비스여야 한다. 오타로 원장이 오염되면
// 서비스별 사용량 집계가 조용히 어긋난다.
if ($svcId !== null) {
    service_require($svcId);
}

$now = now_utc();

/** 재실행(replay) 응답 조립. 잔액 2종은 현재 값, 나머지는 원래 기록 그대로. */
$replayOut = function (array $log) use ($userId) {
    $u = db_one('SELECT points, total_points_spent FROM ' . T_USERS . ' WHERE id = ?', [$userId]);
    json_out([
        'points'             => (int) ($u['points'] ?? 0),
        'delta'              => (int) $log['delta'],
        'balance_after'      => (int) $log['balance_after'],
        'total_points_spent' => (int) ($u['total_points_spent'] ?? 0),
        'log_id'             => (int) $log['id'],
        'idempotent_replay'  => true,
        'created_at'         => iso($log['created_at']),
    ]);
};

try {
    $res = db_tx(function () use ($userId, $amount, $reason, $svcId, $refType, $refId, $memo, $idemKey, $now) {
        // 1) 잔액을 잠근다. 이 락이 이중 차감을 막는 1차 수단이다(2차는 아래 조건부 UPDATE).
        $u = db_one('SELECT id, points, total_points_spent FROM ' . T_USERS . ' WHERE id = ?' . sql_for_update(), [$userId]);
        if ($u === null) {
            throw new AppError('AUTH_SESSION_EXPIRED', 401);
        }

        // 2) 락을 잡은 뒤에 멱등 키를 확인한다. 락 전에 보면 두 요청이
        //    동시에 "없음"을 읽고 둘 다 차감한다.
        if ($idemKey !== null) {
            $prev = db_one(
                'SELECT * FROM ' . T_POINT_LOGS . ' WHERE user_id = ? AND idempotency_key = ?',
                [$userId, $idemKey]
            );
            if ($prev !== null) {
                return ['replay' => true, 'log' => $prev];
            }
        }

        $before = (int) $u['points'];
        $after  = $before - $amount;
        if ($after < 0) {
            // 계약 §7-1 실패 예시: data 에 points / required / shortfall
            throw new AppError('POINT_INSUFFICIENT', 409, [
                'field'     => 'amount',
                'points'    => $before,
                'required'  => $amount,
                'shortfall' => $amount - $before,
            ]);
        }
        $spent = (int) $u['total_points_spent'] + $amount;

        // 조건부 UPDATE — 읽은 잔액이 그대로일 때만 쓴다(설계 B-2 의 3겹째).
        // 잠금이 제대로 걸려 있으면 항상 1행이다. 0행이면 잠금이 빠졌다는 뜻이고,
        // 그때 쓰면 이중 차감이므로 **던져서 롤백한다**(500 + 로그. 조용히 넘어가지 않는다).
        // amount >= 1 이라 points 가 반드시 바뀐다 — rowCount 의 MySQL/SQLite 의미 차이
        // (값이 안 바뀌면 MySQL 0 · SQLite 1, 설계 A32)에 기대지 않는다.
        $cas = db_exec(
            'UPDATE ' . T_USERS . ' SET points = ?, total_points_spent = ?, updated_at = ?'
            . ' WHERE id = ? AND points = ? AND total_points_spent = ?',
            [$after, $spent, $now, $userId, $before, (int) $u['total_points_spent']]
        );
        if ($cas !== 1) {
            throw new RuntimeException('points consume: balance changed under the lock (rows=' . $cas
                . ', user_id=' . $userId . '). the row lock / BEGIN IMMEDIATE is not in effect.');
        }

        // 3) 원장. balance_after 를 남기지 않으면 사후에 잔액과 원장이
        //    어긋났는지 검증할 수단이 없다.
        $logId = log_point($userId, [
            'delta'           => -$amount,
            'balance_after'   => $after,
            'reason'          => $reason,
            'service_id'      => $svcId,
            'ref_type'        => $refType,
            'ref_id'          => $refId,
            'idempotency_key' => $idemKey,
            'memo'            => $memo,
        ]);

        return [
            'replay'             => false,
            'points'             => $after,
            'delta'              => -$amount,
            'balance_after'      => $after,
            'total_points_spent' => $spent,
            'log_id'             => $logId,
        ];
    });
} catch (Throwable $e) {
    // 동시 요청이 같은 멱등 키로 들어와 UNIQUE 에 걸린 경우.
    // 이쪽 트랜잭션은 롤백됐고 상대가 차감을 완료했다 — 그 결과를 돌려준다.
    if ($idemKey !== null && db_is_duplicate($e)) {
        $prev = db_one('SELECT * FROM ' . T_POINT_LOGS . ' WHERE user_id = ? AND idempotency_key = ?',
            [$userId, $idemKey]);
        if ($prev !== null) {
            $replayOut($prev);
        }
    }
    throw $e;
}

if (!empty($res['replay'])) {
    $replayOut($res['log']);
}

json_out([
    'points'             => (int) $res['points'],
    'delta'              => (int) $res['delta'],
    'balance_after'      => (int) $res['balance_after'],
    'total_points_spent' => (int) $res['total_points_spent'],
    'log_id'             => (int) $res['log_id'],
    'idempotent_replay'  => false,
    'created_at'         => iso($now),
]);
