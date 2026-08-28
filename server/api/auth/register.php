<?php
declare(strict_types=1);
/**
 * POST /api/auth/register — 회원가입
 * 담당 계약: 01_architect_api_contract.md §4-1
 * 응답 최상위 키: user, email_verification_required, granted_points, auto_login
 */
require_once __DIR__ . '/../../core/bootstrap.php';

// 1) 메서드
only_method('POST');

// 2) 레이트리밋 (인증·DB 쓰기보다 먼저)
rate_limit('register:ip:' . client_ip(), 5, 3600);

// 3) 입력
$in = body_json();

if (!setting_get('allow_register', ALLOW_REGISTER)) {
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

// service_id 는 선택. 주어졌으면 실재하는 활성 서비스여야 한다.
$serviceId = v_string($in, 'service_id', 1, 64);
$service   = null;
if ($serviceId !== null) {
    $service = service_require($serviceId);
    if (empty($service['allow_register'])) {
        throw new AppError('REGISTRATION_DISABLED', 403);
    }
}

// 4) 중복 확인 (경쟁 상태는 UNIQUE 제약으로 한 번 더 막는다)
if (db_val('SELECT id FROM ' . T_USERS . ' WHERE username = ?', [$username]) !== null) {
    throw new AppError('DUPLICATE_USERNAME', 409, ['field' => 'username']);
}
if (db_val('SELECT id FROM ' . T_USERS . ' WHERE email = ?', [$email]) !== null) {
    throw new AppError('DUPLICATE_EMAIL', 409, ['field' => 'email']);
}

// 5) 생성 — users + user_services + point_logs + user_consents 를 한 트랜잭션으로
$requireVerify = (bool) setting_get('require_email_verify', REQUIRE_EMAIL_VERIFY);
$bonus         = (int) setting_get('signup_bonus_points', SIGNUP_BONUS_POINTS);
if ($bonus < 0) {
    $bonus = 0;
}
$now = now_utc();
$ip  = client_ip();

try {
    $userId = db_tx(function () use ($username, $email, $password, $name, $nickname, $phone,
                                     $marketing, $requireVerify, $bonus, $service, $now, $ip) {
        // users 행을 변수로 잡아 둔다 — 아래 user_services 의 grant-only 판정이
        // **같은 전역 등급**을 보아야 한다(D-49). 리터럴을 두 번 쓰면 어긋난다.
        $userRow = [
            'uuid'                => gen_uuid4(),
            'username'            => $username,
            'email'               => $email,
            'password_hash'       => password_hash($password, PASSWORD_DEFAULT),
            'password_changed_at' => $now,
            'name'                => $name,
            'nickname'            => $nickname,
            'phone'               => $phone,
            'status'              => $requireVerify ? 'pending' : 'active',
            'account_level'       => 'basic',
            'role'                => 'user',
            'billing_tier'        => 'free',
            'points'              => $bonus,
            'total_points_earned' => $bonus,
            'total_points_spent'  => 0,
            'marketing_agreed'    => $marketing ? 1 : 0,
            'timezone'            => 'Asia/Seoul',
            'locale'              => 'ko-KR',
            'created_at'          => $now,
            'updated_at'          => $now,
        ];
        $uid = db_insert(T_USERS, $userRow);

        // 서비스 매핑 (auto_join=0 이어도 명시적 가입 경로이므로 만든다)
        //
        // 등급 오버라이드는 **grant-only** 다 — 리더 판정 D-49 / 감사 M-16 / QA Q-3.
        // 종전에는 `default_account_level` 을 그대로 박아 넣어, 서비스 기본값이 'basic'
        // 이면 이후 이메일 인증으로 전역 등급이 'verified' 로 올라도 그 서비스에서는
        // 오버라이드 'basic' 이 이겨 `min_account_level='verified'` 시크릿이 조용히 사라졌다.
        // 판정 규칙은 core/auth.php 의 service_grade_overrides() 한 곳에만 있고
        // 자동 가입(ensure_user_service)과 이 경로가 그것을 공유한다. 여기서 재구현하지 마라.
        if ($service !== null) {
            $ov = service_grade_overrides($service, $userRow);
            db_insert(T_USER_SERVICES, [
                'user_id'       => $uid,
                'service_id'    => (string) $service['service_id'],
                'account_level' => $ov['account_level'],   // NULL = 전역 상속
                'role'          => $ov['role'],            // 항상 NULL
                'billing_tier'  => $ov['billing_tier'],    // 항상 NULL
                'status'        => 'active',
                'joined_at'     => $now,
                'last_used_at'  => $now,
            ]);
        }

        // 가입 보너스 원장
        if ($bonus > 0) {
            log_point($uid, [
                'delta'         => $bonus,
                'balance_after' => $bonus,
                'reason'        => 'signup_bonus',
                'service_id'    => $service['service_id'] ?? null,
                'memo'          => '가입 보너스',
            ]);
        }

        // 약관 동의 이력 — 발행된 최신 버전 기준
        $codes = ['tos', 'privacy'];
        if ($marketing) {
            $codes[] = 'marketing';
        }
        foreach ($codes as $code) {
            $t = db_one(
                'SELECT id FROM ' . T_TERMS . ' WHERE code = ? AND published_at IS NOT NULL'
                . ' ORDER BY published_at DESC, id DESC LIMIT 1',
                [$code]
            );
            if ($t === null) {
                continue;   // 약관이 아직 등록되지 않은 설치본에서도 가입은 되어야 한다
            }
            db_insert(T_CONSENTS, [
                'user_id'   => $uid,
                'terms_id'  => (int) $t['id'],
                'agreed'    => 1,
                'agreed_at' => $now,
                'ip'        => $ip,
            ]);
        }
        return $uid;
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
    $token = gen_token(32);
    db_insert(T_EMAIL_VERIFY, [
        'token_hash' => token_hash($token),
        'user_id'    => $userId,
        'email'      => $email,
        'expires_at' => at_utc((int) EMAIL_VERIFY_TTL),
        'created_ip' => $ip,
        'created_at' => $now,
    ]);
    $link = base_url() . '/public/verify.html?token=' . rawurlencode($token);
    send_mail(
        $email,
        '[' . setting_get('site_name', SITE_NAME) . '] 이메일 인증',
        "아래 링크를 눌러 이메일 인증을 완료해 주세요. (24시간 유효)\n\n" . $link . "\n"
    );
}

// 7) 응답 — 가입 직후 토큰은 발급하지 않는다(auto_login 은 항상 false).
//    가입 플로우와 로그인 플로우를 섞지 않기 위함이다.
$user = db_one('SELECT * FROM ' . T_USERS . ' WHERE id = ?', [$userId]);
$us   = $service !== null
    ? db_one('SELECT * FROM ' . T_USER_SERVICES . ' WHERE user_id = ? AND service_id = ?', [$userId, $service['service_id']])
    : null;

json_out([
    'user'                        => serialize_user($user, $us),
    'email_verification_required' => $requireVerify,
    'granted_points'              => $bonus,
    'auto_login'                  => false,
], 201);
