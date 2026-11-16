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
require_once __DIR__ . '/../../core/points.php';

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
// v1.10.1 — `s2s:` 는 공동 포인트 s2s 저장 키 자리다(설계 130 §4-5). 사용자가 서비스 키 자리를 미리 차지하지 못하게 거절한다.
if ($idemKey !== null && strncasecmp($idemKey, 's2s:', 4) === 0) {
    v_fail('idempotency_key', 's2s: 로 시작하는 키는 쓸 수 없습니다.');
}
// v1.10.1 보안 M-1 (D-375) — `s2s_` 접두 reason(s2s_earn·s2s_spend 등)은 공동 포인트 s2s 원장 전용이다.
// 사용자가 같은 reason 으로 행을 쌓아 서비스 한도 합산을 미리 채우지(서비스 사용 방해) 못하게 거절한다.
// 합산 쪽도 저장 키 `s2s:%` 행만 센다(core/points.php points_s2s_used) — 두 겹이다.
if (strncasecmp($reason, 's2s_', 4) === 0) {
    v_fail('reason', 's2s_ 로 시작하는 사유는 쓸 수 없습니다.');
}

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

// v1.10.1 — 잠금 + 멱등 확인 + 조건부 UPDATE(CAS) + 원장 본문은 core/points.php points_apply() 로 올렸다(설계 130 §4-4).
// 공동 포인트 s2s 4종이 같은 함수를 쓴다. 동작은 v1.10.0 과 같다(멱등 재실행은 비교 없이 기존 결과를 돌려준다).
$res = points_apply($userId, -$amount, [
    'reason'          => $reason,
    'service_id'      => $svcId,
    'ref_type'        => $refType,
    'ref_id'          => $refId,
    'memo'            => $memo,
    'idempotency_key' => $idemKey,
    'compare'         => false,
    'missing'         => ['AUTH_SESSION_EXPIRED', 401],
]);

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
