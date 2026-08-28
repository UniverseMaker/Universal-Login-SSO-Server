<?php
declare(strict_types=1);
/**
 * PUT /api/me/password — 비밀번호 변경
 * 담당 계약: 01_architect_api_contract.md §6-3
 * 응답 최상위 키: changed, revoked_count, password_changed_at
 *
 * 성공 시 **현재 세션을 제외한** 모든 세션을 회수하고 password_changed_at 을 갱신한다.
 *
 * [주의 — 현재 세션을 살리기 위한 처리]
 * require_auth() 는 `sessions.issued_at >= users.password_changed_at` 을 검사한다.
 * password_changed_at 만 now 로 올리면 **현재 세션도 다음 요청에서 만료**된다.
 * 그래서 현재 세션의 issued_at 을 같은 시각으로 재발급 처리한다.
 * (계약 §6-3 이 "현재 세션은 유지"를 요구하므로 이쪽을 맞춘다)
 */
require_once __DIR__ . '/../../core/bootstrap.php';

only_method('PUT');

$ctx = require_auth();
require_csrf();     // 쿠키 세션일 때만 실제로 검사한다. Bearer 는 면제.

$in      = body_json();
$current = (string) v_string($in, 'current_password', 1, 200, true);
$pw      = (string) v_string($in, 'password', 1, 200, true);
$confirm = (string) v_string($in, 'password_confirm', 1, 200, true);

$user      = $ctx['user'];
$userId    = (int) $user['id'];
$sessionId = (int) $ctx['session']['id'];

// 1) 현재 비밀번호 — 계정 열거 위험이 없는 경로이므로 전용 코드를 쓴다.
if (!password_verify($current, (string) $user['password_hash'])) {
    throw new AppError('AUTH_PASSWORD_MISMATCH', 400, ['field' => 'current_password']);
}

// 2) 새 비밀번호
if (!hash_equals($pw, $confirm)) {
    v_fail('password_confirm', '비밀번호가 일치하지 않습니다.');
}
validate_password($pw, (string) $user['username']);
if (hash_equals($current, $pw)) {
    v_fail('password', '현재 비밀번호와 다른 비밀번호를 입력해 주세요.');
}

$now = now_utc();

// 3) 교체 + 세션 정리를 한 트랜잭션으로. 중간에 끊기면
//    "비밀번호는 바뀌었는데 옛 세션이 살아 있는" 상태가 남는다.
$revoked = db_tx(function () use ($userId, $sessionId, $pw, $now) {
    db_exec(
        'UPDATE ' . T_USERS . ' SET password_hash = ?, password_changed_at = ?, failed_login_count = 0,'
        . ' locked_until = NULL, updated_at = ? WHERE id = ?',
        [password_hash($pw, PASSWORD_DEFAULT), $now, $now, $userId]
    );

    // 미사용 재설정 토큰도 무효화한다. 비밀번호를 방금 바꾼 사용자에게
    // 살아 있는 재설정 링크가 남아 있으면 안 된다.
    db_exec('UPDATE ' . T_PW_RESETS . ' SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [$now, $userId]);

    // 현재 세션만 남기고 전부 회수
    $n = revoke_user_sessions($userId, 'password_changed', $sessionId);

    // 현재 세션은 password_changed_at 과 같은 시각에 재발급된 것으로 처리한다.
    // (issued_at < password_changed_at 이면 require_auth 가 다음 요청에서 끊는다)
    db_exec('UPDATE ' . T_SESSIONS . ' SET issued_at = ? WHERE id = ?', [$now, $sessionId]);

    return $n;
});

json_out([
    'changed'             => true,
    'revoked_count'       => $revoked,
    'password_changed_at' => iso($now),
]);
