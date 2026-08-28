<?php
declare(strict_types=1);
/**
 * PUT /api/me/email — 이메일 변경
 * 담당 계약: 01_architect_api_contract.md §6-4, 리더 판정 D-9
 * 응답 최상위 키: email, email_verified, verification_sent, expires_in
 *
 * 변경 시 email_verified_at 을 NULL 로 되돌린다.
 * require_email_verify=1 이면 새 주소로 인증 메일을 보낸다.
 * 토큰 소모는 POST /api/auth/email/verify (api/auth/email_verify.php) 가 한다.
 */
require_once __DIR__ . '/../../core/bootstrap.php';

only_method('PUT');

$ctx = require_auth();
require_csrf();     // 쿠키 세션일 때만 실제로 검사한다. Bearer 는 면제.

$in       = body_json();
$password = (string) v_string($in, 'password', 1, 200, true);
$email    = (string) v_email($in, 'email', true);   // 소문자로 정규화되어 돌아온다

$user   = $ctx['user'];
$userId = (int) $user['id'];

// 1) 비밀번호 확인 — 세션 탈취만으로 이메일(= 계정 회수 경로)을 바꿀 수 없게 한다
if (!password_verify($password, (string) $user['password_hash'])) {
    throw new AppError('AUTH_PASSWORD_MISMATCH', 400, ['field' => 'password']);
}

// 2) 같은 주소면 거부한다. 통과시키면 email_verified_at 만 날아가
//    (require_email_verify=1 환경에서) 스스로 로그인 불가 상태를 만든다.
if (hash_equals(mb_strtolower((string) $user['email'], 'UTF-8'), $email)) {
    v_fail('email', '현재 사용 중인 이메일과 동일합니다.');
}

// 3) 중복 — 경쟁 상태는 UNIQUE 제약으로 한 번 더 막는다
if (db_val('SELECT id FROM ' . T_USERS . ' WHERE email = ? AND id <> ?', [$email, $userId]) !== null) {
    throw new AppError('DUPLICATE_EMAIL', 409, ['field' => 'email']);
}

$requireVerify = (bool) setting_get('require_email_verify', REQUIRE_EMAIL_VERIFY);
$ttl           = (int) EMAIL_VERIFY_TTL;
$now           = now_utc();
$ip            = client_ip();

// 4) 변경 + 토큰 발급을 한 트랜잭션으로
$token = null;
try {
    $token = db_tx(function () use ($userId, $email, $now, $ip, $ttl, $requireVerify) {
        db_exec(
            'UPDATE ' . T_USERS . ' SET email = ?, email_verified_at = NULL, updated_at = ? WHERE id = ?',
            [$email, $now, $userId]
        );

        // 이전 주소로 발급된 미사용 토큰은 전부 무효화한다. 남겨두면
        // 옛 링크를 눌러 지금 주소가 인증된 것으로 처리될 수 있다.
        db_exec('UPDATE ' . T_EMAIL_VERIFY . ' SET used_at = ? WHERE user_id = ? AND used_at IS NULL',
            [$now, $userId]);

        if (!$requireVerify) {
            return null;
        }
        $t = gen_token(32);
        db_insert(T_EMAIL_VERIFY, [
            'token_hash' => token_hash($t),
            'user_id'    => $userId,
            'email'      => $email,
            'expires_at' => at_utc($ttl),
            'created_ip' => $ip,
            'created_at' => $now,
        ]);
        return $t;
    });
} catch (Throwable $e) {
    if (db_is_duplicate($e)) {
        throw new AppError('DUPLICATE_EMAIL', 409, ['field' => 'email']);
    }
    throw $e;
}

// 5) 메일 — 트랜잭션 밖에서 보낸다. 발송이 느려도 DB 락을 잡고 있지 않게.
$sent = false;
if ($token !== null) {
    $site = (string) setting_get('site_name', SITE_NAME);
    $link = base_url() . '/public/verify.html?token=' . rawurlencode($token);
    $body = "이메일 주소 변경을 요청하셨습니다.\n\n"
          . "아래 링크를 눌러 새 이메일 인증을 완료해 주세요. (24시간 유효)\n\n"
          . $link . "\n\n"
          . "본인이 요청하지 않았다면 즉시 비밀번호를 변경해 주세요.\n";
    // 발송 실패해도 예외는 나오지 않는다(send_mail 이 흡수). 로그로만 남는다.
    if (!send_mail($email, '[' . $site . '] 이메일 인증', $body)) {
        log_error('email change verification mail not delivered. user_id=' . $userId);
    }
    // 발송이 실패해도 토큰은 유효하고 POST /api/auth/email/resend 로 재발송할 수 있다.
    // 따라서 verification_sent 는 "인증 절차가 시작됐다"는 뜻이며 실제 SMTP 전달
    // 성공 여부를 반영하지 않는다 (password_forgot.php 의 sent 와 같은 규약).
    $sent = true;
}

json_out([
    'email'             => $email,
    'email_verified'    => false,
    'verification_sent' => $sent,
    // 인증 메일을 보내지 않은 설치본(require_email_verify=0)에서는 0 이다.
    'expires_in'        => $sent ? $ttl : 0,
]);
