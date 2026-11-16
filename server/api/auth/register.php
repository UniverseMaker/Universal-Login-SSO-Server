<?php
declare(strict_types=1);
/**
 * POST /api/auth/register — 회원가입 (open) / 가입 신청 (approval)
 * 담당 계약: 01_architect_api_contract.md §4-1 · 설계 130 §1-3 · §2-4 (v1.10.1)
 * 응답 최상위 키
 *   open     201: user, email_verification_required, granted_points, auto_login, approval_required(false)
 *   approval 202: approval_required(true), request{status, created_at}, receipt, auto_login(false)
 *   closed   403: REGISTRATION_DISABLED (종전과 같다)
 */
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/regreq.php';

// 1) 메서드
only_method('POST');

// 2) 레이트리밋 (인증·DB 쓰기보다 먼저)
rate_limit('register:ip:' . client_ip(), 5, 3600);

// 3) 입력
$in = body_json();

// 가입 방식 판정은 core/auth.php 의 register_mode_effective() 한 곳에만 있다(v1.10.1 — v1.8.2 registration_open 의 후계).
// 화면용 공개 읽기 api/auth/register_status.php 가 같은 함수를 부른다 — 여기서 식을 다시 쓰지 마라.
// 전역이 closed 면 입력 검증 전에 거절한다(빈 본문도 403 — v1.10.0 과 같은 응답).
$mode = register_mode_effective(null);
if ($mode === 'closed') {
    throw new AppError('REGISTRATION_DISABLED', 403);
}

$username = normalize_username((string) v_string($in, 'username', 4, 20, true));
validate_username_format($username);

$email    = (string) v_email($in, 'email', true);
$password = (string) v_string($in, 'password', 1, 200, true);
$confirm  = (string) v_string($in, 'password_confirm', 1, 200, true);
if (!hash_equals($password, $confirm)) {
    v_fail('password_confirm', '비밀번호가 일치하지 않습니다.');
}
validate_password($password, $username);

$name     = v_string($in, 'name', 1, 80);
$nickname = v_string($in, 'nickname', 1, 50);
$phone    = v_string($in, 'phone', 1, 30);
$marketing = (bool) v_bool($in, 'marketing_agreed', false, false);

// 약관 동의는 값이 true 여야 한다. 누락도 false 도 거부.
if (v_bool($in, 'agree_tos', false, false) !== true) {
    v_fail('agree_tos', '이용약관에 동의해야 가입할 수 있습니다.');
}
if (v_bool($in, 'agree_privacy', false, false) !== true) {
    v_fail('agree_privacy', '개인정보 처리방침에 동의해야 가입할 수 있습니다.');
}

// v1.10.1 선택 키 3개(approval 에서만 저장한다). entry 는 enum 밖이면 register_page.
$redirectIn = v_string($in, 'redirect_uri', 0, 500);
$entryIn    = in_raw($in, 'entry');
$noteIn     = v_string($in, 'applicant_note', 0, 500);

// service_id 는 선택. 주어졌으면 실재하는 활성 서비스여야 한다.
$serviceId = v_service_id($in, 'service_id');   // v1.8.6 — 옛 id 는 여기서 새 id 로(설계 104 C-1)
$service   = null;
if ($serviceId !== null) {
    $service = service_require($serviceId);
    $mode    = register_mode_effective($service);   // min(전역, 서비스 상한)
    if ($mode === 'closed') {
        throw new AppError('REGISTRATION_DISABLED', 403);
    }
}

// 4) 중복 확인 — users 와 유효한 pending 신청 둘 다(경쟁 상태는 UNIQUE 제약 · tx 재확인으로 한 번 더 막는다)
regreq_assert_unique($username, $email, true);

// 4-b) approval — 신청 행만 만든다. users 행은 관리자 승인 때 만든다(설계 130 §2-1).
if ($mode === 'approval') {
    $sub = regreq_submit([
        'username'       => $username,
        'email'          => $email,
        'password'       => $password,
        'name'           => $name,
        'nickname'       => $nickname,
        'phone'          => $phone,
        'marketing'      => $marketing,
        'applicant_note' => ($noteIn === null || $noteIn === '') ? null : $noteIn,
        'entry'          => is_string($entryIn) ? $entryIn : null,
        'redirect_uri'   => $redirectIn,
    ], $service);
    json_out([
        'approval_required' => true,
        'request'           => ['status' => 'pending', 'created_at' => iso($sub['created_at'])],
        // 접수번호 원문은 이 응답 1회만. DB 에는 sha256 만 있다.
        'receipt'           => $sub['receipt'],
        'auto_login'        => false,
    ], 202);
}

// 5) 생성 — users + user_services + point_logs + user_consents 를 한 트랜잭션으로(core/auth.php register_create_user)
$requireVerify = (bool) setting_get('require_email_verify', REQUIRE_EMAIL_VERIFY);
$bonus         = (int) setting_get('signup_bonus_points', SIGNUP_BONUS_POINTS);
if ($bonus < 0) {
    $bonus = 0;
}
$now = now_utc();
$ip  = client_ip();
$pwHash = password_hash($password, PASSWORD_DEFAULT);

try {
    $userId = db_tx(function () use ($username, $email, $pwHash, $name, $nickname, $phone,
                                     $marketing, $requireVerify, $bonus, $service, $now, $ip) {
        return register_create_user([
            'username'       => $username,
            'email'          => $email,
            'password_hash'  => $pwHash,
            'name'           => $name,
            'nickname'       => $nickname,
            'phone'          => $phone,
            'marketing'      => $marketing,
            'require_verify' => $requireVerify,
            'bonus'          => $bonus,
            'consents'       => null,   // 지금 발행된 최신 약관
        ], $service, $now, $ip, null);
    });
} catch (Throwable $e) {
    if (db_is_duplicate($e)) {
        // 동시 가입 경쟁. 어느 쪽이 걸렸는지 다시 확인해 정확한 코드를 준다.
        if (db_val('SELECT id FROM ' . T_USERS . ' WHERE username = ?', [$username]) !== null) {
            throw new AppError('DUPLICATE_USERNAME', 409, ['field' => 'username']);
        }
        throw new AppError('DUPLICATE_EMAIL', 409, ['field' => 'email']);
    }
    throw $e;
}

// 6) 이메일 인증 메일 (require_email_verify=1 일 때만)
if ($requireVerify) {
    register_send_verify_mail($userId, $email, $ip);
}

// 7) 응답 — 가입 직후 토큰은 발급하지 않는다(auto_login 은 항상 false).
//    가입 플로우와 로그인 플로우를 섞지 않기 위함이다.
$user = db_one('SELECT * FROM ' . T_USERS . ' WHERE id = ?', [$userId]);
$us   = $service !== null
    ? db_one('SELECT * FROM ' . T_USER_SERVICES . ' WHERE user_id = ? AND service_id = ?', [$userId, $service['service_id']])
    : null;

// v1.8.6 — service_id 는 클라이언트가 보낸 값을 되돌린다(설계 104 C-2)
$userOut = serialize_user($user, $us);
service_id_echo($userOut, null);
json_out([
    'user'                        => $userOut,
    'email_verification_required' => $requireVerify,
    'granted_points'              => $bonus,
    'auto_login'                  => false,
    'approval_required'           => false,   // v1.10.1 순수 추가(설계 130 §1-3)
], 201);
