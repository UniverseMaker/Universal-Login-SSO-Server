<?php
declare(strict_types=1);
/**
 * POST /api/me/withdraw — 회원 탈퇴 (soft delete)
 * 담당 계약: 01_architect_api_contract.md §6-10
 * 응답 최상위 키: withdrawn, withdrawn_at, revoked_count, message
 *
 * 행을 지우지 않는다. status='withdrawn' + 개인정보 컬럼 NULL 로 익명화한다.
 *   - **로그·포인트 이력은 남긴다.** 감사 근거이고, 재가입 어뷰징 판정에 필요하다.
 *   - username / email 은 `wd_{id}_{원본}` 으로 치환한다. 그대로 두면 UNIQUE 제약
 *     때문에 같은 아이디·이메일로 재가입할 수 없다.
 *   - uuid 는 유지한다. admin_logs 의 target_id 가 uuid 라 지우면 추적이 끊긴다.
 *
 * 전 과정이 한 트랜잭션이다. 중간에 끊기면 "세션은 살아 있는데 탈퇴 처리된"
 * 계정이 남는다.
 */
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/accountlink.php';

only_method('POST');

$ctx = require_auth();
require_csrf();     // 쿠키 세션일 때만 실제로 검사한다. Bearer 는 면제.

$in       = body_json();
$password = (string) v_string($in, 'password', 1, 200, true);
$reason   = v_string($in, 'reason', 1, 255);
$confirm  = (string) v_string($in, 'confirm', 1, 190, true);

$user   = $ctx['user'];
$userId = (int) $user['id'];

// 1) 비밀번호
if (!password_verify($password, (string) $user['password_hash'])) {
    throw new AppError('AUTH_PASSWORD_MISMATCH', 400, ['field' => 'password']);
}

// 2) 2단계 확인 — 자신의 username 을 정확히 입력해야 한다
if (!hash_equals(normalize_username((string) $user['username']), normalize_username($confirm))) {
    v_fail('confirm', '확인 문구가 일치하지 않습니다. 아이디를 정확히 입력해 주세요.');
}

// 3) 시스템 잠금 방지 — 마지막 superadmin 은 탈퇴할 수 없다.
//    나가면 관리자 콘솔에 아무도 들어갈 수 없게 된다.
if ((string) $user['role'] === 'superadmin') {
    $others = (int) db_val(
        'SELECT COUNT(*) FROM ' . T_USERS . ' WHERE role = ? AND status = ? AND id <> ?',
        ['superadmin', 'active', $userId]
    );
    if ($others < 1) {
        throw new AppError('FORBIDDEN', 403, [
            'message' => '마지막 최고관리자는 탈퇴할 수 없습니다. 다른 최고관리자를 먼저 지정해 주세요.',
        ]);
    }
}

$now = now_utc();

/**
 * UNIQUE 충돌 방지용 접두사. 컬럼 길이를 넘지 않도록 **원본 쪽을** 잘라낸다.
 * 접두사를 잘라내면 `wd_` 표시가 사라져 탈퇴 계정인지 알 수 없게 된다.
 */
$prefix   = 'wd_' . $userId . '_';
$maskName = function (string $orig, int $maxLen) use ($prefix): string {
    $room = $maxLen - strlen($prefix);
    if ($room < 0) {
        $room = 0;
    }
    return $prefix . mb_substr($orig, 0, $room);
};

$newUsername = $maskName((string) $user['username'], 50);    // users.username VARCHAR(50)
$newEmail    = $maskName((string) $user['email'], 190);      // users.email    VARCHAR(190)

$revoked = db_tx(function () use ($userId, $now, $reason, $newUsername, $newEmail) {
    // 전 세션 회수 — 현재 세션도 포함한다
    $n = revoke_user_sessions($userId, 'withdraw');

    // 미사용 재설정·인증 토큰 전부 무효화. 탈퇴 후 링크로 되살릴 수 없게.
    db_exec('UPDATE ' . T_PW_RESETS . ' SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [$now, $userId]);
    db_exec('UPDATE ' . T_EMAIL_VERIFY . ' SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [$now, $userId]);

    // 개인정보 컬럼 익명화. points / total_points_* 와 원장은 건드리지 않는다.
    db_update(T_USERS, [
        'status'             => 'withdrawn',
        'withdrawn_at'       => $now,
        'withdraw_reason'    => $reason,
        'username'           => $newUsername,
        'email'              => $newEmail,
        'name'               => null,
        'nickname'           => null,
        'phone'              => null,
        'avatar_url'         => null,
        'last_login_ip'      => null,
        'two_factor_secret'  => null,
        'two_factor_enabled' => 0,
        'meta_json'          => null,
        'marketing_agreed'   => 0,
        'email_verified_at'  => null,
        // 비밀번호는 남겨두지 않는다. 사용 불가능한 난수 해시로 덮는다.
        'password_hash'       => password_hash(gen_token(32), PASSWORD_DEFAULT),
        'password_changed_at' => $now,
        'failed_login_count'  => 0,
        'locked_until'        => null,
        'updated_at'          => $now,
    ], 'id = ?', [$userId]);

    // 서비스 가입도 차단 상태로 내린다 (행은 남긴다 — 가입 이력이다)
    db_exec('UPDATE ' . T_USER_SERVICES . ' SET status = ? WHERE user_id = ?', ['blocked', $userId]);

    // v1.8.4 — 시스템 계정 연결 자동 해제(설계 92 E절, D-172 소비자). 같은 트랜잭션.
    // 각 시스템은 사건 피드 `unlinked` 로 그 로컬 계정의 uuid 묶음을 푼다(S-6).
    al_release_user($userId, 'withdraw');

    return $n;
});

clear_session_cookie();
log_login('logout', $userId, $newUsername, $ctx['session']['service_id'] ?? null);

json_out([
    'withdrawn'     => true,
    'withdrawn_at'  => iso($now),
    'revoked_count' => $revoked,
    'message'       => '탈퇴가 완료되었습니다. 이용해 주셔서 감사합니다.',
]);
