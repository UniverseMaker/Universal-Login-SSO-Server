<?php
declare(strict_types=1);
/**
 * POST /api/admin/user_status.php?uuid=... — 정지 / 복구 / 비밀번호 초기화
 * 담당 계약: §8-7(suspend) · §8-8(restore) · §8-9(reset-password)
 * 리더 판정 D-0: 세 논리 경로를 본문 `action` 필드로 구분한다.
 *   action = "suspend" | "restore" | "reset-password"
 * 최소 역할: admin (단 withdrawn → active 복구는 superadmin)
 *
 * 응답 최상위 키
 *   suspend        : uuid, status, previous_status, revoked_count, suspended_at, admin_log_id
 *   restore        : uuid, status, previous_status, username, email, restored_at, admin_log_id
 *   reset-password : uuid, temp_password, email_sent, revoked_count, password_changed_at, admin_log_id
 *
 * 임시 비밀번호는 admin_logs 에 절대 기록하지 않는다.
 */
require_once __DIR__ . '/../../core/bootstrap.php';

only_method('POST');

// =====================================================================
// 공통 헬퍼 (관리자 API 전용)
// =====================================================================

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

if (!function_exists('admin_is_last_superadmin')) {
    function admin_is_last_superadmin(array $target): bool
    {
        if (!role_gte(admin_role_of($target), 'superadmin')) {
            return false;
        }
        $others = (int) db_val(
            'SELECT COUNT(*) FROM ' . T_USERS . ' WHERE role = ? AND status = ? AND id <> ?',
            ['superadmin', 'active', (int) $target['id']]
        );
        return $others === 0;
    }
}

if (!function_exists('admin_guard_destructive')) {
    /** 계약 §8 공통 규칙 2·3·5. $verb: '정지' | '삭제' | '비밀번호를 초기화' */
    function admin_guard_destructive(array $ctx, array $target, string $verb): void
    {
        if (admin_is_self($ctx, $target)) {
            admin_forbid('자신의 계정은 ' . $verb . '할 수 없습니다.');
        }
        if (admin_is_last_superadmin($target)) {
            admin_forbid('마지막 최고관리자 계정은 ' . $verb . '할 수 없습니다.');
        }
        if (role_gte(admin_role_of($target), ctx_global_role($ctx))) {
            admin_forbid('자신과 같거나 높은 권한의 계정은 ' . $verb . '할 수 없습니다.');
        }
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

if (!function_exists('admin_temp_password')) {
    /**
     * 읽어서 옮겨 적을 수 있는 임시 비밀번호.
     * 혼동되는 글자(I l 1 O 0)를 뺀 알파벳에서 random_bytes 기반으로 뽑고,
     * 대문자·소문자·숫자·기호를 각각 최소 1개 보장한 뒤 섞는다.
     * rand()/mt_rand()/uniqid() 는 쓰지 않는다 — 예측 가능하다.
     */
    function admin_temp_password(int $length = 12): string
    {
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghijkmnpqrstuvwxyz';
        $digit = '23456789';
        $sym   = '#@$%!*+=?';
        $all   = $upper . $lower . $digit . $sym;

        $pick = function (string $set): string {
            return $set[random_int(0, strlen($set) - 1)];
        };

        if ($length < 8) {
            $length = 8;
        }
        $chars = [$pick($upper), $pick($lower), $pick($digit), $pick($sym)];
        for ($i = count($chars); $i < $length; $i++) {
            $chars[] = $pick($all);
        }
        // Fisher-Yates (shuffle() 은 암호학적 난수가 아니다)
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            $t = $chars[$i];
            $chars[$i] = $chars[$j];
            $chars[$j] = $t;
        }
        return implode('', $chars);
    }
}

// =====================================================================

$ctx = require_auth();
require_global_role($ctx, 'admin');
require_csrf();

$target = admin_user_by_uuid(in_raw($_GET, 'uuid'));
$uid    = (int) $target['id'];

// D-30: action 3종(suspend / restore / reset-password) 전부에 적용되는 진입부 랭크 가드.
// restore 분기에는 가드가 아예 없었다(H-3) — 정지된 상급 계정을 되살려 탈취할 수 있었다.
// action 별 개별 가드(admin_guard_destructive / 탈퇴복구 superadmin 재검사)는 아래에 그대로 남긴다.
admin_guard_target_rank($ctx, $target, '변경');

$body   = body_json();
$action = v_enum($body, 'action', ['suspend', 'restore', 'reset-password'], true);
$reason = v_string($body, 'reason', 0, 255, false);
$memo   = v_string($body, 'memo', 0, 255, false);

$logMemo = trim((string) $reason . ($memo !== null ? ' / ' . $memo : ''));
if ($logMemo === '') {
    $logMemo = null;
}

$adminId  = (int) $ctx['user']['id'];
$now      = now_utc();
$prevStat = (string) $target['status'];

// -----------------------------------------------------------------
// suspend — 계약 §8-7
// -----------------------------------------------------------------
if ($action === 'suspend') {
    admin_guard_destructive($ctx, $target, '정지');

    if ($prevStat === 'suspended') {
        throw new AppError('CONFLICT', 409, ['message' => '이미 정지된 계정입니다.']);
    }
    if ($prevStat === 'withdrawn') {
        throw new AppError('CONFLICT', 409, ['message' => '탈퇴한 계정은 정지할 수 없습니다.']);
    }

    $revoked = db_tx(function () use ($uid, $now) {
        db_update(T_USERS, ['status' => 'suspended', 'updated_at' => $now], 'id = ?', [$uid]);
        return revoke_user_sessions($uid, 'admin');
    });

    $logId = admin_log_write($adminId, 'user.suspend', 'user', (string) $target['uuid'],
        ['status' => $prevStat], ['status' => 'suspended', 'sessions_revoked' => $revoked], $logMemo);

    json_out([
        'uuid'            => (string) $target['uuid'],
        'status'          => 'suspended',
        'previous_status' => $prevStat,
        'revoked_count'   => $revoked,
        'suspended_at'    => iso($now),
        'admin_log_id'    => $logId,
    ]);
}

// -----------------------------------------------------------------
// restore — 계약 §8-8
// -----------------------------------------------------------------
if ($action === 'restore') {
    if ($prevStat === 'active') {
        throw new AppError('CONFLICT', 409, ['message' => '이미 활성 상태인 계정입니다.']);
    }
    // 탈퇴 계정 복구는 superadmin 만
    if ($prevStat === 'withdrawn' && !role_gte(ctx_global_role($ctx), 'superadmin')) {
        admin_forbid('탈퇴 계정 복구는 최고관리자만 가능합니다.');
    }
    // H-3 후반부: new_username / new_email 은 탈퇴 시 붙은 wd_{id}_ 접두사 충돌을 푸는 수단이다.
    // 모든 상태에서 허용하면 '복구' 가 임의 식별자 재지정 경로가 된다.
    if ($prevStat !== 'withdrawn'
        && (array_key_exists('new_username', $body) || array_key_exists('new_email', $body))) {
        v_fail('new_email', '아이디·이메일 재지정은 탈퇴 계정 복구에서만 가능합니다.');
    }

    $username = (string) $target['username'];
    $email    = (string) $target['email'];

    // 탈퇴 시 붙은 wd_{id}_ 접두사를 떼어낸다 (계약 §8-8)
    if ($prevStat === 'withdrawn') {
        $prefix = 'wd_' . $uid . '_';
        if (strpos($username, $prefix) === 0) {
            $username = substr($username, strlen($prefix));
        }
        if (strpos($email, $prefix) === 0) {
            $email = substr($email, strlen($prefix));
        }
    }

    // 관리자가 새 값을 지정하면 그것을 쓴다
    $newUsername = v_string($body, 'new_username', 1, 50, false);
    if ($newUsername !== null) {
        $username = normalize_username($newUsername);
        validate_username_format($username);
    }
    $newEmail = v_email($body, 'new_email', false);
    if ($newEmail !== null) {
        $email = $newEmail;
    }

    // 탈퇴 사이에 같은 값으로 다른 계정이 생겼을 수 있다 — 조용히 덮어쓰지 않는다
    if ($username !== (string) $target['username']) {
        $dup = db_val('SELECT id FROM ' . T_USERS . ' WHERE username = ? AND id <> ?', [$username, $uid]);
        if ($dup !== null) {
            throw new AppError('CONFLICT', 409, [
                'field'   => 'new_username',
                'message' => '원래 아이디가 이미 사용 중입니다. 새 아이디를 지정해 주세요.',
            ]);
        }
    }
    if ($email !== (string) $target['email']) {
        $dup = db_val('SELECT id FROM ' . T_USERS . ' WHERE email = ? AND id <> ?', [$email, $uid]);
        if ($dup !== null) {
            throw new AppError('CONFLICT', 409, [
                'field'   => 'new_email',
                'message' => '원래 이메일이 이미 사용 중입니다. 새 이메일을 지정해 주세요.',
            ]);
        }
    }

    try {
        db_update(T_USERS, [
            'status'             => 'active',
            'username'           => $username,
            'email'              => $email,
            'withdrawn_at'       => null,
            'withdraw_reason'    => null,
            'locked_until'       => null,
            'failed_login_count' => 0,
            'updated_at'         => $now,
        ], 'id = ?', [$uid]);
    } catch (Throwable $e) {
        if (db_is_duplicate($e)) {
            throw new AppError('CONFLICT', 409, [
                'field'   => 'new_username',
                'message' => '원래 아이디 또는 이메일이 이미 사용 중입니다. 새 값을 지정해 주세요.',
            ]);
        }
        throw $e;
    }

    $logId = admin_log_write($adminId, 'user.restore', 'user', (string) $target['uuid'],
        ['status' => $prevStat, 'username' => (string) $target['username'], 'email' => (string) $target['email']],
        ['status' => 'active', 'username' => $username, 'email' => $email],
        $logMemo);

    json_out([
        'uuid'            => (string) $target['uuid'],
        'status'          => 'active',
        'previous_status' => $prevStat,
        'username'        => $username,
        'email'           => $email,
        'restored_at'     => iso($now),
        'admin_log_id'    => $logId,
    ]);
}

// -----------------------------------------------------------------
// reset-password — 계약 §8-9
// -----------------------------------------------------------------
admin_guard_destructive($ctx, $target, '비밀번호를 초기화');

$sendEmail = (bool) v_bool($body, 'send_email', false, false);

$minLen = (int) setting_get('password_min_length', defined('PASSWORD_MIN_LENGTH') ? PASSWORD_MIN_LENGTH : 8);
$temp   = admin_temp_password(max(12, min(32, $minLen)));

$revoked = db_tx(function () use ($uid, $temp, $now) {
    db_update(T_USERS, [
        'password_hash'        => password_hash($temp, PASSWORD_DEFAULT),
        'password_changed_at'  => $now,
        'failed_login_count'   => 0,
        'locked_until'         => null,
        'updated_at'           => $now,
    ], 'id = ?', [$uid]);
    // 비밀번호가 바뀌면 기존 세션은 전부 무효다
    return revoke_user_sessions($uid, 'admin');
});

$emailSent = false;
if ($sendEmail) {
    $site = (string) setting_get('site_name', defined('SITE_NAME') ? SITE_NAME : 'ulogin');
    $body_ = "안녕하세요.\n\n"
        . $site . " 관리자가 임시 비밀번호를 발급했습니다.\n\n"
        . "아이디: " . (string) $target['username'] . "\n"
        . "임시 비밀번호: " . $temp . "\n\n"
        . "로그인 후 반드시 비밀번호를 변경해 주세요.\n";
    $emailSent = send_mail((string) $target['email'], '[' . $site . '] 임시 비밀번호 안내', $body_);
}

$logId = admin_log_write($adminId, 'user.reset_password', 'user', (string) $target['uuid'],
    null,
    // 비밀번호는 어떤 형태로도 남기지 않는다
    ['password_changed_at' => $now, 'sessions_revoked' => $revoked, 'email_sent' => $emailSent],
    $logMemo);

json_out([
    'uuid' => (string) $target['uuid'],
    // 메일 발송에 성공했을 때만 마스킹한다. 발송이 실패했는데 값을 숨기면
    // 관리자가 계정을 복구할 방법이 사라진다 (리더 판정 D-1: 기본 드라이버는 log).
    'temp_password'       => ($sendEmail && $emailSent) ? '********' : $temp,
    'email_sent'          => $emailSent,
    'revoked_count'       => $revoked,
    'password_changed_at' => iso($now),
    'admin_log_id'        => $logId,
]);
