<?php
declare(strict_types=1);
/**
 * DELETE /api/admin/user_sessions.php?uuid=... — 회원 전 세션 강제 종료
 * 담당 계약: §8-10 (논리 경로 DELETE /api/admin/users/{uuid}/sessions)
 * 최소 역할: admin
 * 응답 최상위 키: uuid, revoked_count, revoked_at, admin_log_id
 *
 * 회수 사유는 revoke_reason='admin' 으로 남긴다.
 */
require_once __DIR__ . '/../../core/bootstrap.php';

only_method('DELETE');

if (!function_exists('admin_log_write')) {
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

if (!function_exists('admin_forbid')) {
    function admin_forbid(string $message, ?string $field = null): void
    {
        throw new AppError('FORBIDDEN', 403, ['message' => $message, 'field' => $field]);
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

// 쿼리·본문 양쪽에서 파라미터를 받는다 (쿼리가 우선)
$in     = array_merge(body_json(), $_GET);
$target = admin_user_by_uuid(in_raw($in, 'uuid'));
$uid    = (int) $target['id'];

// D-30 진입부 가드. 기존 인라인 가드(계약 §8 공통 5의 확장)와 판정 내용이 같으므로
// 공통 함수 호출로 통일했다 — 사본이 여러 곳에 흩어지면 한쪽만 고쳐지는 사고가 난다.
// 자기 자신은 통과한다 — 스스로 전 기기 로그아웃하는 것은 정상 조작이다.
admin_guard_target_rank($ctx, $target, '세션을 종료');

$reason = v_string($in, 'reason', 0, 255, false);
$now    = now_utc();

$revoked = revoke_user_sessions($uid, 'admin');

$logId = admin_log_write(
    (int) $ctx['user']['id'], 'user.sessions.revoke', 'user', (string) $target['uuid'],
    null, ['sessions_revoked' => $revoked], $reason
);

json_out([
    'uuid'          => (string) $target['uuid'],
    'revoked_count' => $revoked,
    'revoked_at'    => iso($now),
    'admin_log_id'  => $logId,
]);
