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
 *
 * v1.8.4 — 이관 계정의 첫 비밀번호 설정 토큰(`password_resets.purpose='setup'`, 72h)도 이 경로로 소비한다.
 * **기존 계정의 비밀번호만 바꾼다** — 계정을 만들지 않으므로 가입 차단(D-315)의 우회로가 아니다.
 * 성공하면 `meta_json.setup_pending` 을 지운다(어느 토큰으로 바꿨든 — 비밀번호가 정해졌다는 사실은 같다).
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
emergency_target_guard($user);   // v1.10.0 §6-1 — 비상 계정은 웹으로 초기화하지 않는다(CLI 전용)

validate_password($pw, (string) $user['username'], 'password', rank_of(ROLE_RANK, global_role_of($user)));   // v1.9.1 D-362

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
        'UPDATE ' . T_USERS . ' SET password_hash = ?, password_changed_at = ?, password_epoch = password_epoch + 1, failed_login_count = 0,'
        . ' locked_until = NULL, updated_at = ? WHERE id = ?',
        [password_hash($pw, PASSWORD_DEFAULT), $now, $now, $user['id']]
    );
    // 같은 사용자의 다른 미사용 재설정 토큰도 무효화한다(설정 토큰 포함).
    db_exec('UPDATE ' . T_PW_RESETS . ' SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [$now, $user['id']]);

    // v1.8.4 — 첫 비밀번호가 정해졌다. 관리자 화면의 「설정 대기」와 setup_link 재발급 조건이 이 값을 본다.
    $meta = json_col(isset($user['meta_json']) ? (string) $user['meta_json'] : null);
    if (array_key_exists('setup_pending', $meta)) {
        unset($meta['setup_pending']);
        db_exec('UPDATE ' . T_USERS . ' SET meta_json = ? WHERE id = ?', [
            $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $user['id'],
        ]);
    }

    // v1.9.1 - 옛 비밀번호의 비상 확인값 폐기(새 값 발급 전)
    em_revoke_user((int) $user['id'], 'password_reset');

    return revoke_user_sessions((int) $user['id'], 'password_changed');
});

// v1.9.1 - 재설정도 비밀번호 직접 인증 시점이다(emergency/1 1-1장)
$freshU = db_one('SELECT * FROM ' . T_USERS . ' WHERE id = ?', [(int) $user['id']]);
if ($freshU !== null) {
    em_issue_for_user($freshU, $pw, true);
}

json_out([
    'reset'         => true,
    'revoked_count' => $revoked,
]);
