<?php
declare(strict_types=1);
/**
 * POST /api/auth/email/resend — 이메일 인증 메일 재발송
 * 담당: 리더 판정 D-9
 * 실제 호출 경로: api/auth/email_resend.php  (리더 판정 D-0)
 * 응답 최상위 키: sent, message, expires_in
 *
 * 인증 불필요.
 *
 * **계정 존재 여부와 무관하게 항상 성공 응답을 반환한다.** 계정 열거 방지.
 * 이미 인증된 계정이어도 성공으로 응답하고 실제로는 보내지 않는다.
 * sent 는 항상 true 다 — 실제 발송 여부를 반영하지 않는다
 * (password_forgot.php 와 같은 규약).
 */
require_once __DIR__ . '/../../core/bootstrap.php';

only_method('POST');

rate_limit('emailresend:ip:' . client_ip(), 5, 3600);

$in    = body_json();
$email = (string) v_email($in, 'email', true);   // 형식 오류만 VALIDATION_FAILED

rate_limit('emailresend:email:' . $email, 3, 3600);

$ttl = (int) EMAIL_VERIFY_TTL;
$now = now_utc();
$ip  = client_ip();

// 존재하지 않는 계정이어도 아래 응답은 동일하다.
$user = db_one('SELECT id, username, email, status, email_verified_at FROM ' . T_USERS . ' WHERE email = ?', [$email]);

$eligible = $user !== null
    && (string) $user['status'] !== 'withdrawn'
    && empty($user['email_verified_at']);      // 이미 인증됐으면 보내지 않는다

if ($eligible) {
    try {
        $token = db_tx(function () use ($user, $email, $now, $ip, $ttl) {
            // 기존 미사용 토큰은 전부 무효화한 뒤 새로 발급한다 (D-9 — 토큰 누적 방지).
            // 여러 개가 살아 있으면 그중 하나만 유출돼도 계정이 인증된다.
            db_exec('UPDATE ' . T_EMAIL_VERIFY . ' SET used_at = ? WHERE user_id = ? AND used_at IS NULL',
                [$now, (int) $user['id']]);

            $t = gen_token(32);
            db_insert(T_EMAIL_VERIFY, [
                'token_hash' => token_hash($t),
                'user_id'    => (int) $user['id'],
                'email'      => $email,
                'expires_at' => at_utc($ttl),
                'created_ip' => $ip,
                'created_at' => $now,
            ]);
            return $t;
        });

        $site = (string) setting_get('site_name', SITE_NAME);
        $link = base_url() . '/public/verify.html?token=' . rawurlencode($token);
        $body = "이메일 인증을 요청하셨습니다.\n\n"
              . "아래 링크를 눌러 인증을 완료해 주세요. (24시간 유효)\n\n"
              . $link . "\n\n"
              . "본인이 요청하지 않았다면 이 메일을 무시하셔도 됩니다.\n";

        // 발송 실패해도 예외가 밖으로 나오지 않는다(send_mail 이 흡수).
        if (!send_mail($email, '[' . $site . '] 이메일 인증', $body)) {
            log_error('email verify mail not delivered. user_id=' . (int) $user['id']);
        }
    } catch (Throwable $e) {
        // 여기서 500 을 내보내면 "이 이메일은 계정이 있다"는 신호가 된다.
        log_error('email_resend failed: ' . $e->getMessage());
    }
}

json_out([
    'sent'       => true,
    'message'    => '인증 메일을 보냈습니다.',
    'expires_in' => $ttl,
]);
