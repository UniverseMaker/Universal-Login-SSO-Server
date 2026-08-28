<?php
declare(strict_types=1);
/**
 * POST /api/auth/password/reset — 토큰으로 비밀번호 재설정
 * 담당 계약: 01_architect_api_contract.md §4-8
 * 실제 호출 경로: api/auth/password_reset.php  (리더 판정 D-0)
 * 응답 최상위 키: reset, revoked_count
 *
 * 토큰 없음·만료·이미 사용됨을 모두 TOKEN_INVALID 로 통일한다.
 * 구분하면 유효한 토큰을 탐색하는 오라클이 된다.
 */
require_once __DIR__ . '/../../core/bootstrap.php';

only_method('POST');

rate_limit('pwreset:ip:' . client_ip(), 20, 3600);

$in      = body_json();
$token   = (string) v_string($in, 'token', 1, 128, true);
$pw      = (string) v_string($in, 'password', 1, 200, true);
$confirm = (string) v_string($in, 'password_confirm', 1, 200, true);

if (!hash_equals($pw, $confirm)) {
    v_fail('password_confirm', '비밀번호가 일치하지 않습니다.');
}

if (!is_token_format($token)) {
    throw new AppError('TOKEN_INVALID', 400, ['field' => 'token']);
}
$row = db_one('SELECT * FROM ' . T_PW_RESETS . ' WHERE token_hash = ?', [token_hash($token)]);
if ($row === null || $row['used_at'] !== null) {
    throw new AppError('TOKEN_INVALID', 400, ['field' => 'token']);
}
$exp = ts($row['expires_at']);
if ($exp === null || $exp <= time()) {
    throw new AppError('TOKEN_INVALID', 400, ['field' => 'token']);
}

$user = db_one('SELECT * FROM ' . T_USERS . ' WHERE id = ?', [$row['user_id']]);
if ($user === null || (string) $user['status'] === 'withdrawn') {
    throw new AppError('TOKEN_INVALID', 400, ['field' => 'token']);
}

validate_password($pw, (string) $user['username']);

$now = now_utc();

// 토큰 소모 + 비밀번호 교체 + 전 세션 회수를 한 트랜잭션으로 묶는다.
// 중간에 끊기면 "비밀번호는 바뀌었는데 토큰은 살아 있는" 상태가 남는다.
$revoked = db_tx(function () use ($row, $user, $pw, $now) {
    $used = db_exec('UPDATE ' . T_PW_RESETS . ' SET used_at = ? WHERE id = ? AND used_at IS NULL', [$now, $row['id']]);
    if ($used !== 1) {
        // 동시 요청에서 다른 쪽이 먼저 소모했다.
        throw new AppError('TOKEN_INVALID', 400, ['field' => 'token']);
    }
    db_exec(
        'UPDATE ' . T_USERS . ' SET password_hash = ?, password_changed_at = ?, failed_login_count = 0,'
        . ' locked_until = NULL, updated_at = ? WHERE id = ?',
        [password_hash($pw, PASSWORD_DEFAULT), $now, $now, $user['id']]
    );
    // 같은 사용자의 다른 미사용 재설정 토큰도 무효화한다.
    db_exec('UPDATE ' . T_PW_RESETS . ' SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [$now, $user['id']]);

    return revoke_user_sessions((int) $user['id'], 'password_changed');
});

json_out([
    'reset'         => true,
    'revoked_count' => $revoked,
]);
