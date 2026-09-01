<?php
declare(strict_types=1);

/**
 * core/serializer.php — user / session 직렬화 (화이트리스트)
 * 담당 계약: §3-1(user 33필드), §3-2(관리자 뷰 추가 필드), §3-4(session 11필드)
 *
 * 사용자 객체를 이 함수 밖에서 조립하지 않는다. 필드 누락·누출의 원인이 된다.
 * 값이 없으면 필드를 빼는 것이 아니라 null 을 넣는다 —
 * 프론트가 `'field' in user` 체크를 하지 않아도 되게 하기 위함이다.
 *
 * 절대 포함하지 않는 필드:
 *   id, password_hash, password_changed_at, two_factor_secret,
 *   memo/failed_login_count/locked_until/last_login_ip/withdraw_reason(관리자 뷰 제외),
 *   meta_json(원문 문자열)
 */

// 직접 실행 차단 — 리더 판정 D-34. bootstrap.php 를 거치지 않은 요청은 존재하지 않는 파일이다.
if (!defined('ULOGIN_BOOTSTRAPPED')) {
    // X-Powered-By 제거 — D-154(2). 이 경로는 bootstrap.php 를 거치지 않는다.
    header_remove('X-Powered-By');
    http_response_code(404);
    exit;
}

/**
 * @param array      $u         users 행
 * @param array|null $us        user_services 행 (서비스 컨텍스트)
 * @param bool       $adminView true 면 §3-2 필드를 추가한다
 * @param array      $extra     'session_count' / 'service_overrides' 를 미리 계산해 넘길 수 있다
 */
function serialize_user(array $u, ?array $us = null, bool $adminView = false, array $extra = []): array
{
    $g = effective_grades($u, $us);

    $out = [
        // 1~8 기본
        'uuid'       => isset($u['uuid']) ? (string) $u['uuid'] : null,
        'username'   => isset($u['username']) ? (string) $u['username'] : null,
        'email'      => isset($u['email']) ? (string) $u['email'] : null,
        'name'       => isset($u['name']) && $u['name'] !== null ? (string) $u['name'] : null,
        'nickname'   => isset($u['nickname']) && $u['nickname'] !== null ? (string) $u['nickname'] : null,
        'phone'      => isset($u['phone']) && $u['phone'] !== null ? (string) $u['phone'] : null,
        'avatar_url' => isset($u['avatar_url']) && $u['avatar_url'] !== null ? (string) $u['avatar_url'] : null,
        'status'     => isset($u['status']) ? (string) $u['status'] : null,

        // 9~17 3축 등급 (전부 effective 값)
        'account_level'      => $g['account_level'],
        'account_level_rank' => $g['account_level_rank'],
        'role'               => $g['role'],
        'role_rank'          => $g['role_rank'],
        'billing_tier'       => $g['billing_tier'],
        'billing_tier_rank'  => $g['billing_tier_rank'],
        'billing_started_at' => iso($g['billing_started_at']),
        'billing_expires_at' => iso($g['billing_expires_at']),
        'billing_active'     => (bool) $g['billing_active'],

        // 18~20 포인트 (v1.0.0 은 전역 포인트만 — 리더 판정 D-4)
        'points'              => (int) ($u['points'] ?? 0),
        'total_points_earned' => (int) ($u['total_points_earned'] ?? 0),
        'total_points_spent'  => (int) ($u['total_points_spent'] ?? 0),

        // 21~26 계정 속성
        'email_verified'     => !empty($u['email_verified_at']),
        'email_verified_at'  => iso($u['email_verified_at'] ?? null),
        'two_factor_enabled' => (bool) ($u['two_factor_enabled'] ?? false),
        'timezone'           => (string) ($u['timezone'] ?? 'Asia/Seoul'),
        'locale'             => (string) ($u['locale'] ?? 'ko-KR'),
        'marketing_agreed'   => (bool) ($u['marketing_agreed'] ?? false),

        // 27~30 이력
        'created_at'    => iso($u['created_at'] ?? null),
        'updated_at'    => iso($u['updated_at'] ?? null),
        'last_login_at' => iso($u['last_login_at'] ?? null),
        'login_count'   => (int) ($u['login_count'] ?? 0),

        // 31~33 서비스 컨텍스트
        'service_id'        => isset($us['service_id']) ? (string) $us['service_id'] : null,
        'service_joined_at' => isset($us['joined_at']) ? iso($us['joined_at']) : null,
        // 없어도 {} — null 도 [] 도 아니다 (빈 PHP 배열은 JSON 에서 [] 가 된다)
        'meta'              => json_object(json_col($u['meta_json'] ?? null)),
    ];

    if (!$adminView) {
        return $out;
    }

    // ---- §3-2 관리자 뷰 추가 필드 --------------------------------------
    $out['admin_view']          = true;
    $out['memo']                = isset($u['memo']) && $u['memo'] !== null ? (string) $u['memo'] : null;
    $out['last_login_ip']       = isset($u['last_login_ip']) && $u['last_login_ip'] !== null ? (string) $u['last_login_ip'] : null;
    $out['failed_login_count']  = (int) ($u['failed_login_count'] ?? 0);
    $out['locked_until']        = iso($u['locked_until'] ?? null);
    $out['withdrawn_at']        = iso($u['withdrawn_at'] ?? null);
    $out['withdraw_reason']     = isset($u['withdraw_reason']) && $u['withdraw_reason'] !== null ? (string) $u['withdraw_reason'] : null;
    // 오버라이드 적용 '전' 전역값. 관리자는 무엇을 고칠지 알아야 한다.
    $out['global_account_level'] = (string) ($u['account_level'] ?? 'basic');
    $out['global_role']          = (string) ($u['role'] ?? 'user');
    $out['global_billing_tier']  = (string) ($u['billing_tier'] ?? 'free');

    if (array_key_exists('session_count', $extra)) {
        $out['session_count'] = (int) $extra['session_count'];
    } else {
        $out['session_count'] = count_active_sessions((int) ($u['id'] ?? 0));
    }
    if (array_key_exists('service_overrides', $extra)) {
        $out['service_overrides'] = array_values((array) $extra['service_overrides']);
    } else {
        $out['service_overrides'] = load_service_overrides((int) ($u['id'] ?? 0));
    }
    return $out;
}

/** 유효(미회수·미만료) 세션 수. */
function count_active_sessions(int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }
    return (int) db_val(
        'SELECT COUNT(*) FROM ' . T_SESSIONS . ' WHERE user_id = ? AND revoked_at IS NULL AND expires_at > ?',
        [$userId, now_utc()]
    );
}

/** §3-2 service_overrides 항목 배열. */
function load_service_overrides(int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    $rows = db_all(
        'SELECT us.*, s.service_name FROM ' . T_USER_SERVICES . ' us'
        . ' LEFT JOIN ' . T_SERVICES . ' s ON s.service_id = us.service_id'
        . ' WHERE us.user_id = ? ORDER BY us.joined_at ASC',
        [$userId]
    );
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'service_id'         => (string) $r['service_id'],
            'service_name'       => $r['service_name'] !== null ? (string) $r['service_name'] : null,
            'account_level'      => $r['account_level'] !== null ? (string) $r['account_level'] : null,
            'role'               => $r['role'] !== null ? (string) $r['role'] : null,
            'billing_tier'       => $r['billing_tier'] !== null ? (string) $r['billing_tier'] : null,
            'billing_expires_at' => iso($r['billing_expires_at']),
            'points'             => (int) $r['points'],
            'status'             => (string) $r['status'],
            'joined_at'          => iso($r['joined_at']),
            'last_used_at'       => iso($r['last_used_at']),
        ];
    }
    return $out;
}

/**
 * 세션 직렬화. 계약 §3-4.
 *
 * session_id 는 **공개 식별자**다. 이 값으로는 인증할 수 없다 — 근거:
 *   1) require_auth() 는 Bearer 헤더든 ULOGIN_SID 쿠키든 값을 SHA-256 해서
 *      `sessions.access_token_hash` 로만 조회한다. `session_id` 를 인증자로
 *      받는 경로가 코드에 한 곳도 없다 (리더 판정 D-29 / 감사 C-1).
 *   2) set_session_cookie() 가 심는 값도 access_token 원문이지 session_id 가 아니다.
 * 따라서 이 값을 쿠키나 Authorization 헤더에 넣어도 401 이 난다.
 *
 * 계속 응답에 싣는 이유: 기기 목록 표시와
 * `DELETE /api/me/sessions.php?session_id=...` 가 이 식별자를 쓴다.
 *
 * *_token_hash 와 access_token/refresh_token 원문은 절대 나가지 않는다.
 */
function serialize_session(array $s, bool $isCurrent = true): array
{
    return [
        'session_id'         => (string) $s['session_id'],
        'auth_type'          => (string) $s['auth_type'],
        'service_id'         => isset($s['service_id']) && $s['service_id'] !== null ? (string) $s['service_id'] : null,
        'issued_at'          => iso($s['issued_at'] ?? null),
        'expires_at'         => iso($s['expires_at'] ?? null),
        'refresh_expires_at' => iso($s['refresh_expires_at'] ?? null),
        'last_seen_at'       => iso($s['last_seen_at'] ?? null),
        'device_label'       => isset($s['device_label']) && $s['device_label'] !== null ? (string) $s['device_label'] : null,
        'ip'                 => (string) ($s['ip'] ?? ''),
        'user_agent'         => (string) ($s['user_agent'] ?? ''),
        'is_current'         => $isCurrent,
    ];
}
