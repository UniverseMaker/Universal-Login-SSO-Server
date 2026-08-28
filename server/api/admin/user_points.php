<?php
declare(strict_types=1);
/**
 * POST /api/admin/user_points.php?uuid=... — 관리자 포인트 조정
 * 담당 계약: §8-6 (논리 경로 POST /api/admin/users/{uuid}/points)
 * 최소 역할: admin
 * 응답 최상위 키: points, delta, balance_after, total_points_earned, total_points_spent,
 *                 log_id, admin_log_id, created_at
 *
 * 트랜잭션 + SELECT ... FOR UPDATE 로 잔액을 잠근다.
 * FOR UPDATE 없이 읽고 쓰면 동시 요청에서 잔액과 원장이 어긋난다 — 사후 복구가 불가능하다.
 * 결과 잔액이 음수가 되면 POINT_INSUFFICIENT(409).
 */
require_once __DIR__ . '/../../core/bootstrap.php';

only_method('POST');

if (!function_exists('admin_log_write')) {
    /** 감사 로그 기록 + id 회수. logger.php 의 log_admin() 은 void 다. */
    function admin_log_write(int $adminId, string $action, string $targetType, ?string $targetId,
                             ?array $before = null, ?array $after = null, ?string $memo = null): ?int
    {
        log_admin($adminId, $action, $targetType, $targetId, $before, $after, $memo);
        $id = (int) db()->lastInsertId();
        if ($id <= 0) {
            return null;
        }
        $chk = db_val('SELECT id FROM ' . T_ADMIN_LOGS . ' WHERE id = ? AND admin_id = ? AND action = ?',
            [$id, $adminId, substr($action, 0, 60)]);
        return $chk === null ? null : (int) $chk;
    }
}

if (!function_exists('admin_user_by_uuid')) {
    /** @param mixed $raw */
    function admin_user_by_uuid($raw): array
    {
        $uuid = is_string($raw) ? trim($raw) : '';
        if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $uuid) !== 1) {
            throw new AppError('VALIDATION_FAILED', 400, [
                'field' => 'uuid', 'message' => '회원 식별자 형식이 올바르지 않습니다.',
            ]);
        }
        $u = db_one('SELECT * FROM ' . T_USERS . ' WHERE uuid = ?', [$uuid]);
        if ($u === null) {
            throw new AppError('NOT_FOUND', 404, ['message' => '회원을 찾을 수 없습니다.']);
        }
        return $u;
    }
}

if (!function_exists('admin_forbid')) {
    function admin_forbid(string $message, ?string $field = null): void
    {
        throw new AppError('FORBIDDEN', 403, ['message' => $message, 'field' => $field]);
    }
}

if (!function_exists('admin_role_of')) {
    /**
     * 대상 계정의 랭크 판정 기준 — **전역 `users.role` 만** 본다 (리더 판정 D-50 / 감사 M-13).
     *
     * 종전에는 `effective_grades($user, null)` 을 거쳤다. `$us = null` 이라 결과는 같았지만,
     * 그 함수의 계약은 "오버라이드 반영"이므로 기준이 이름에 드러나지 않았다.
     * 행위자는 effective, 대상은 전역이라는 비대칭이 여기서 생겼다(M-13).
     * 이제 행위자(`ctx_global_role()`)와 대상(이 함수)이 같은 함수 계열을 쓴다.
     *
     * 문자열을 직접 비교하지 않는다 — 비교는 항상 `role_gte()` 정수 랭크로 한다.
     */
    function admin_role_of(array $user): string
    {
        return global_role_of($user);
    }
}

if (!function_exists('admin_is_self')) {
    function admin_is_self(array $ctx, array $target): bool
    {
        return (int) $ctx['user']['id'] === (int) $target['id'];
    }
}

if (!function_exists('admin_guard_target_rank')) {
    /**
     * 리더 판정 D-30 (C-2 / H-3): 대상 계정을 변경하는 모든 관리자 경로의 **진입부** 가드.
     *
     * 규칙: 자신과 같거나 높은 role_rank 를 가진 '다른' 계정에는 어떤 변경도 허용하지 않는다.
     *       (superadmin 도 자신보다 낮은 계정만 변경할 수 있다.)
     *       자신에 대한 변경은 통과시키고, 뒤따르는 개별 자기방어 규칙
     *       (마지막 superadmin 보호 / 자기 강등 금지 / 자기 정지·삭제 금지)이 처리한다.
     *
     * 필드별로 가드를 흩뿌리면 필드가 추가될 때마다 구멍이 생긴다 — C-2 의 email 경로가 그랬다.
     * 진입부 1곳에서 판정하고, 필드별 규칙은 그 뒤에 둔다.
     * 거부는 조작이 아니므로 admin_logs 에 기록하지 않는다.
     *
     * @param string $what '변경' | '수정' | '삭제' | '포인트를 조정' | '세션을 종료'
     */
    function admin_guard_target_rank(array $ctx, array $target, string $what = '변경'): void
    {
        if (admin_is_self($ctx, $target)) {
            return;
        }
        if (role_gte(admin_role_of($target), ctx_global_role($ctx))) {
            admin_forbid('자신과 같거나 높은 권한의 계정은 ' . $what . '할 수 없습니다.');
        }
    }
}

$ctx = require_auth();
require_global_role($ctx, 'admin');
require_csrf();

$target = admin_user_by_uuid(in_raw($_GET, 'uuid'));
$uid    = (int) $target['id'];

// D-30: 포인트 조정도 '대상 계정 변경' 이다. 상급 계정의 잔액을 임의로 만질 수 없다.
admin_guard_target_rank($ctx, $target, '포인트를 조정');

$body  = body_json();
$delta = (int) v_int($body, 'delta', -1000000000, 1000000000, true);
if ($delta === 0) {
    v_fail('delta', '0 이 아닌 값을 입력해 주세요.');
}
$reason = v_string($body, 'reason', 1, 100, false, 'admin_adjust');
$memo   = v_string($body, 'memo', 0, 255, false);

$adminId = (int) $ctx['user']['id'];
$now     = now_utc();

$out = db_tx(function () use ($uid, $delta, $reason, $memo, $adminId, $now, $target) {
    // 잔액을 잠근 뒤 읽는다
    $u = db_one(
        'SELECT id, uuid, points, total_points_earned, total_points_spent FROM ' . T_USERS . ' WHERE id = ? FOR UPDATE',
        [$uid]
    );
    if ($u === null) {
        throw new AppError('NOT_FOUND', 404, ['message' => '회원을 찾을 수 없습니다.']);
    }

    $before = (int) $u['points'];
    $after  = $before + $delta;
    if ($after < 0) {
        throw new AppError('POINT_INSUFFICIENT', 409, [
            'field'     => 'delta',
            'message'   => '회수할 포인트가 잔액보다 많습니다.',
            'points'    => $before,
            'required'  => abs($delta),
            'shortfall' => abs($delta) - $before,
        ]);
    }

    // points 는 INT 컬럼이다. 상한을 넘기면 DB 가 거부하므로 미리 막는다.
    if ($after > 2000000000) {
        v_fail('delta', '조정 후 잔액이 허용 범위를 초과합니다.');
    }

    $earned = (int) $u['total_points_earned'] + ($delta > 0 ? $delta : 0);
    $spent  = (int) $u['total_points_spent'] + ($delta < 0 ? -$delta : 0);

    db_update(T_USERS, [
        'points'              => $after,
        'total_points_earned' => $earned,
        'total_points_spent'  => $spent,
        'updated_at'          => $now,
    ], 'id = ?', [$uid]);

    $logId = log_point($uid, [
        'delta'         => $delta,
        'balance_after' => $after,
        'reason'        => $reason,
        'memo'          => $memo,
        'admin_id'      => $adminId,
        'ref_type'      => 'admin',
    ]);

    $adminLogId = admin_log_write(
        $adminId, 'user.points.adjust', 'user', (string) $target['uuid'],
        ['points' => $before],
        ['points' => $after, 'delta' => $delta, 'reason' => $reason, 'point_log_id' => $logId],
        $memo
    );

    return [
        'points'              => $after,
        'delta'               => $delta,
        'balance_after'       => $after,
        'total_points_earned' => $earned,
        'total_points_spent'  => $spent,
        'log_id'              => $logId,
        'admin_log_id'        => $adminLogId,
        'created_at'          => iso($now),
    ];
});

json_out($out);
