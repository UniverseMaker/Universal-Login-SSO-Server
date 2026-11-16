<?php
declare(strict_types=1);
/**
 * POST /api/auth/password/forgot — 비밀번호 재설정 링크 요청
 * 담당 계약: 01_architect_api_contract.md §4-7
 * 실제 호출 경로: api/auth/password_forgot.php  (리더 판정 D-0)
 * 응답 최상위 키: sent, message, expires_in
 *
 * **계정 존재 여부와 무관하게 항상 성공 응답을 반환한다.** 계정 열거 방지.
 * sent 는 항상 true 다. 실제 발송 여부를 반영하지 않는다.
 */
require_once __DIR__ . '/../../core/bootstrap.php';

only_method('POST');

rate_limit('pwforgot:ip:' . client_ip(), 5, 3600);

$in    = body_json();
$email = (string) v_email($in, 'email', true);   // 형식 오류만 VALIDATION_FAILED

rate_limit('pwforgot:email:' . $email, 3, 3600);

$ttl = (int) PW_RESET_TTL;

// 존재하지 않는 계정이어도 아래 응답은 동일하다.
$user = db_one('SELECT id, username, email, status, account_kind FROM ' . T_USERS . ' WHERE email = ?', [$email]);

// v1.10.0 §6-1 — 비상 계정에는 재설정 메일을 만들지 않는다(응답은 같다 — 존재 여부를 알리지 않는다)
if ($user !== null && !is_emergency_user($user) && in_array((string) $user['status'], ['active', 'pending', 'dormant'], true)) {
    try {
        $token = gen_token(32);
        db_insert(T_PW_RESETS, [
            'token_hash' => token_hash($token),
            'user_id'    => (int) $user['id'],
            'expires_at' => at_utc($ttl),
            'created_ip' => client_ip(),
            'created_at' => now_utc(),
        ]);

        $site = (string) setting_get('site_name', SITE_NAME);
        $link = base_url() . '/public/reset.html?token=' . rawurlencode($token);
        $body = "비밀번호 재설정을 요청하셨습니다.\n\n"
              . "아래 링크를 눌러 새 비밀번호를 설정해 주세요. (30분간 유효)\n\n"
              . $link . "\n\n"
              . "본인이 요청하지 않았다면 이 메일을 무시하셔도 됩니다.\n";

        // 발송 실패해도 예외가 밖으로 나오지 않는다(send_mail 이 흡수). 로그로만 남는다.
        if (!send_mail($email, '[' . $site . '] 비밀번호 재설정 안내', $body)) {
            log_error('password reset mail not delivered. user_id=' . (int) $user['id']);
        }
    } catch (Throwable $e) {
        // 여기서 500 을 내보내면 "이 이메일은 계정이 있다"는 신호가 된다.
        log_error('password_forgot failed: ' . $e->getMessage());
    }
}

json_out([
    'sent'       => true,
    'message'    => '입력하신 이메일로 재설정 링크를 보냈습니다. 메일이 오지 않으면 주소를 확인해 주세요.',
    'expires_in' => $ttl,
]);
