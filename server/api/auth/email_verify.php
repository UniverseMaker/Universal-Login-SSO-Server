<?php
declare(strict_types=1);
/**
 * POST /api/auth/email/verify — 이메일 인증 토큰 소모
 * 담당: 리더 판정 D-9 (계약 §4·§6 의 구멍 보완)
 * 실제 호출 경로: api/auth/email_verify.php  (리더 판정 D-0)
 * 응답 최상위 키: verified, email, account_level, auto_login
 *
 * 인증 불필요 — 토큰 자체가 자격증명이다.
 *
 * 실패 사유(없음 / 만료 / 이미 사용됨 / 대상 주소 불일치)는 전부 TOKEN_INVALID 로
 * 통일한다. 구분하면 유효한 토큰을 탐색하는 오라클이 된다.
 *
 * auto_login 은 항상 false 다. 인증과 로그인 플로우를 섞지 않는다 —
 * 메일 링크 클릭만으로 세션이 생기면 메일 계정 탈취가 곧 로그인이 된다.
 */
require_once __DIR__ . '/../../core/bootstrap.php';

only_method('POST');

rate_limit('emailverify:ip:' . client_ip(), 30, 3600);

$in    = body_json();
$token = (string) v_string($in, 'token', 1, 128, true);

if (!is_token_format($token)) {
    throw new AppError('TOKEN_INVALID', 400, ['field' => 'token']);
}

$now = now_utc();

$out = db_tx(function () use ($token, $now) {
    // 토큰을 잠근 채로 읽는다. 잠금이 없으면 동시 요청이 같은 토큰을 두 번 쓴다.
    // v1.8.0 — MySQL 은 sql_for_update() 의 ' FOR UPDATE'(행 잠금), SQLite 는 db_tx() 의
    // BEGIN IMMEDIATE(트랜잭션 직렬화)가 그 일을 한다(설계 B-2). 아래 조건부 UPDATE 는 2차 방어다.
    $row = db_one('SELECT * FROM ' . T_EMAIL_VERIFY . ' WHERE token_hash = ?' . sql_for_update(), [token_hash($token)]);
    if ($row === null || $row['used_at'] !== null) {
        throw new AppError('TOKEN_INVALID', 400, ['field' => 'token']);
    }
    $exp = ts($row['expires_at']);
    if ($exp === null || $exp <= time()) {
        throw new AppError('TOKEN_INVALID', 400, ['field' => 'token']);
    }

    $user = db_one('SELECT * FROM ' . T_USERS . ' WHERE id = ?' . sql_for_update(), [$row['user_id']]);
    if ($user === null || (string) $user['status'] === 'withdrawn') {
        throw new AppError('TOKEN_INVALID', 400, ['field' => 'token']);
    }

    // 토큰이 가리키는 주소와 현재 계정 주소가 달라졌다면(그 사이에 또 변경)
    // 이 토큰은 무효다. 통과시키면 지금 주소가 인증되지 않았는데 인증된 것으로 표시된다.
    if (!hash_equals(mb_strtolower((string) $user['email'], 'UTF-8'),
                     mb_strtolower((string) $row['email'], 'UTF-8'))) {
        throw new AppError('TOKEN_INVALID', 400, ['field' => 'token']);
    }

    // 소모 — 조건부 UPDATE 로 원자성을 한 번 더 보장한다
    $used = db_exec('UPDATE ' . T_EMAIL_VERIFY . ' SET used_at = ? WHERE id = ? AND used_at IS NULL',
        [$now, $row['id']]);
    if ($used !== 1) {
        throw new AppError('TOKEN_INVALID', 400, ['field' => 'token']);
    }
    // 같은 사용자의 다른 미사용 토큰도 무효화한다 (토큰 누적 방지)
    db_exec('UPDATE ' . T_EMAIL_VERIFY . ' SET used_at = ? WHERE user_id = ? AND used_at IS NULL',
        [$now, $row['user_id']]);

    $set = [
        'email_verified_at' => $now,
        'updated_at'        => $now,
    ];

    // pending 이면 active 로 전환한다 (D-9). 그 외 상태(suspended/dormant)는
    // 관리자가 만든 상태이므로 인증으로 되돌리지 않는다.
    if ((string) $user['status'] === 'pending') {
        $set['status'] = 'active';
    }

    // guest / basic 이면 verified 로 승급. verified 이상은 그대로 둔다
    // (vip / staff 를 verified 로 내리면 강등이 된다).
    $level = (string) $user['account_level'];
    if (in_array($level, ['guest', 'basic'], true)) {
        $set['account_level'] = 'verified';
        $level = 'verified';
    }

    db_update(T_USERS, $set, 'id = ?', [(int) $user['id']]);

    return [
        'email'         => (string) $user['email'],
        'account_level' => $level,
    ];
});

json_out([
    'verified'      => true,
    'email'         => $out['email'],
    'account_level' => $out['account_level'],
    'auto_login'    => false,
]);
