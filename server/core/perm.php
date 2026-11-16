<?php
declare(strict_types=1);

/**
 * core/perm.php — 3축 등급 판정
 * 담당 계약: 스펙 §2(권한 모델), §2-2(effective), §2-3(can), 계약 §3-1 #9~#17
 *
 * 문자열 비교 금지. 'vip' > 'staff' 는 PHP 에서 참이다 — 문자열 비교는 등급 우회를 만든다.
 * 반드시 _RANK 맵으로 정수 비교한다.
 */

// 직접 실행 차단 — 리더 판정 D-34. bootstrap.php 를 거치지 않은 요청은 존재하지 않는 파일이다.
if (!defined('ULOGIN_BOOTSTRAPPED')) {
    // X-Powered-By 제거 — D-154(2). 이 경로는 bootstrap.php 를 거치지 않는다.
    header_remove('X-Powered-By');
    http_response_code(404);
    exit;
}

const ACCOUNT_LEVEL_RANK = ['guest' => 0,  'basic' => 10,   'verified' => 20, 'vip' => 30, 'staff' => 90];
const ROLE_RANK          = ['user' => 0,   'manager' => 10, 'admin' => 20,    'superadmin' => 30];
const BILLING_TIER_RANK  = ['free' => 0,   'lite' => 10,    'pro' => 20,      'enterprise' => 30];

/** 스펙 §2-3. 조작 키 → 최소 역할. 랭크 비교만으로는 나중에 못 쪼갠다. */
const CAN_MAP = [
    'admin.access'          => 'manager',
    'stats.view'            => 'manager',
    'user.list'             => 'manager',
    'user.view'             => 'manager',
    'user.update'           => 'admin',
    'user.points.adjust'    => 'admin',
    'user.suspend'          => 'admin',
    'user.restore'          => 'admin',
    'user.reset_password'   => 'admin',
    'user.sessions.revoke'  => 'admin',
    'user.delete'           => 'superadmin',
    'service.manage'        => 'admin',
    'secret.manage'         => 'admin',
    'secret.reveal'         => 'superadmin',
    'logs.login.view'       => 'manager',
    'logs.admin.view'       => 'admin',
    'settings.manage'       => 'admin',
    // v1.8.4 시스템 계정 연결(설계 92 A-5 · G절). 판정은 require_global_role() — 전역 users.role 만.
    // 쓰기가 superadmin 인 이유: 연결은 「남의 시스템 로컬 계정으로 들어가는 열쇠」를 주는 조작이다.
    'account_link.view'     => 'admin',
    'account_link.manage'   => 'superadmin',
    // v1.10.1 가입 신청(설계 130 §2-5 · D-371 Q-3). 판정은 require_global_role() — 전역 users.role 만. 결정은 감사 필수.
    'register.view'         => 'admin',
    'register.decide'       => 'admin',
];

function rank_of(array $map, ?string $v): int
{
    return $map[$v ?? ''] ?? 0;
}

function level_gte(?string $a, ?string $b): bool
{
    return rank_of(ACCOUNT_LEVEL_RANK, $a) >= rank_of(ACCOUNT_LEVEL_RANK, $b);
}

function role_gte(?string $a, ?string $b): bool
{
    return rank_of(ROLE_RANK, $a) >= rank_of(ROLE_RANK, $b);
}

function tier_gte(?string $a, ?string $b): bool
{
    return rank_of(BILLING_TIER_RANK, $a) >= rank_of(BILLING_TIER_RANK, $b);
}

/**
 * 전역값 + 서비스 오버라이드 + 결제 만료를 반영한 유효 등급.
 * 등급 판정은 반드시 이 함수를 거친다. 컬럼을 직접 읽어 비교하면 오버라이드와 만료가 누락된다.
 *
 * 계약 §3-1 #17: billing_active = tier != 'free' && (expires_at === null || expires_at > now)
 * 만료됐으면 tier 를 free 로 강등해서 보여준다. DB 값은 그대로 둔다 (cron 이 없어 배치 강등이 불가능하다).
 *
 * 반환 배열에는 등급 3축 외에 `membership_status` 가 함께 실린다 (리더 판정 D-31).
 * build_payload() 가 호출부 수정 없이 차단 회원을 걸러낼 수 있게 하기 위한 것이다 —
 * `$us` 를 payload 까지 손으로 전달하는 방식은 호출부 4곳 중 하나만 빠져도 구멍이 된다.
 * 서비스 컨텍스트가 없으면 null, 가입 행이 있으면 'active'|'blocked'.
 * **응답에 그대로 싣지 마라.** 이 배열은 판정용이고, 노출은 serialize_user() /
 * api/me/services.php 가 필드를 골라 담당한다.
 *
 * @param array      $u  users 행
 * @param array|null $us user_services 행 (서비스 컨텍스트가 없으면 null)
 */
function effective_grades(array $u, ?array $us = null): array
{
    $level = ($us['account_level'] ?? null) ?: ($u['account_level'] ?? 'basic');
    $role  = ($us['role']          ?? null) ?: ($u['role']          ?? 'user');
    $tier  = ($us['billing_tier']  ?? null) ?: ($u['billing_tier']  ?? 'free');

    // 오버라이드 tier 가 있으면 만료도 오버라이드 쪽을 본다.
    if (($us['billing_tier'] ?? null)) {
        $exp = $us['billing_expires_at'] ?? null;
    } else {
        $exp = ($u['billing_expires_at'] ?? null);
        if ($exp === null) {
            $exp = ($us['billing_expires_at'] ?? null);
        }
    }
    $started = $u['billing_started_at'] ?? null;

    $expTs = ts($exp);
    if ($expTs !== null && $expTs <= time()) {
        $tier = 'free';                 // 판정 시점 강등
    }
    $active = ($tier !== 'free');

    return [
        'account_level'      => $level,
        'account_level_rank' => rank_of(ACCOUNT_LEVEL_RANK, $level),
        'role'               => $role,
        'role_rank'          => rank_of(ROLE_RANK, $role),
        'billing_tier'       => $tier,
        'billing_tier_rank'  => rank_of(BILLING_TIER_RANK, $tier),
        'billing_started_at' => $started,
        'billing_expires_at' => $exp,
        'billing_active'     => $active,
        // D-31: 서비스 가입 상태. null=서비스 컨텍스트 없음. 'blocked' 면 payload 를 비운다.
        'membership_status'  => $us === null ? null : (string) ($us['status'] ?? 'active'),
        // v1.9.0 (80 §5-3) — build_payload() 의 접근 판정 방어층용. **응답에 싣지 마라.**
        // global_role 은 users.role 그대로(오버라이드 무시) — 접근 판정은 전역 role 만 본다.
        'global_role'        => global_role_of($u),
        'access_grant'       => $us === null ? 'inherit' : (string) ($us['access_grant'] ?? 'inherit'),
        // v1.10.0 (설계 127 §2) — build_payload() 방어층이 단계 판정에 쓸 계정 id. **응답에 싣지 마라.**
        'user_id'            => isset($u['id']) ? (int) $u['id'] : 0,
    ];
}

/**
 * 조작 키 단위 판정. 스펙 §2-3.
 *
 * **판정 기준: effective role** (서비스 오버라이드 반영). `$ctx['grades']` 를 읽는다.
 * 관리자 API 의 권한 판정에는 쓰지 마라 — 리더 판정 D-50 이 관리자 API 를
 * **전역 role** 기준으로 확정했다. 전역 기준이 필요하면 `require_global_role()` 을 쓴다.
 * (v1.0.0 에서 이 함수의 호출부는 0곳이다. 서비스 연동용 공개 API — 리더 판정 D-39)
 */
function can(array $ctx, string $action): bool
{
    if (!isset(CAN_MAP[$action])) {
        return false;   // 매핑이 없는 조작은 기본 거부
    }
    return role_gte($ctx['grades']['role'] ?? null, CAN_MAP[$action]);
}

function require_can(array $ctx, string $action): void
{
    if (!can($ctx, $action)) {
        throw new AppError('FORBIDDEN', 403);
    }
}

/**
 * **판정 기준: effective role** (서비스 오버라이드 반영).
 *
 * 이름에 기준이 드러나지 않아 혼동의 원인이었다(감사 M-13). 두 기준을 정리하면:
 *   - `require_role()`        → effective. 서비스 컨텍스트 안에서의 권한. 서비스 게이팅용
 *   - `require_global_role()` → 전역 `users.role`. **관리자 API 전용** (리더 판정 D-50)
 *
 * 관리자 API 에서는 이 함수를 쓰지 마라. 특정 서비스에서 `admin` 오버라이드를 받은
 * 사용자가 이 함수를 통과하면 **로그인 서버 전체의 관리자**가 된다 — 그 자체가 권한 상승이다.
 * v1.0.0 에서 이 함수의 호출부는 0곳이다(관리자 API 12곳이 전부 D-50 으로 옮겨갔다).
 */
function require_role(array $ctx, string $min): void
{
    if (!role_gte($ctx['grades']['role'] ?? null, $min)) {
        throw new AppError('FORBIDDEN', 403);
    }
}

// ---------------------------------------------------------------------
// 전역 role 판정 — 관리자 API 전용 (리더 판정 D-50 / 보안 감사 M-13)
// ---------------------------------------------------------------------
/*
 * 관리자 API 의 권한 판정은 **행위자·대상 모두 전역 `users.role` 만** 쓴다.
 *
 * 서비스별 오버라이드(`user_services.role`)는 "그 서비스 안에서의 권한"이다.
 * 특정 서비스에서 `admin` 오버라이드를 받은 사용자가 로그인 서버 전체의 관리자가
 * 되어서는 안 된다. 그 승격 경로 자체가 권한 상승이다.
 *
 * 반대로 오버라이드가 전역 권한을 **낮추지도** 않는다. 전역 `admin` 이 어떤 서비스에서
 * `user` 오버라이드를 받았어도 관리자 API 는 그대로 쓸 수 있다 — 판정에 오버라이드가
 * 아예 개입하지 않기 때문이다.
 *
 * 여기서 `effective_grades()` 를 부르지 않는 이유: `effective_grades($u, null)` 은
 * 지금은 전역 role 을 돌려주지만, 그 함수의 계약은 "오버라이드 반영"이다.
 * 나중에 `$us` 를 스스로 조회하도록 바뀌면 관리자 판정이 조용히 effective 로 되돌아간다.
 * 전역 기준은 전역 기준만 읽는 함수로 못박는다.
 */

/**
 * `users` 행의 전역 role. `user_services` 오버라이드를 보지 않는다.
 * 값이 없으면 최하위 `'user'` — fail-closed 다(랭크 0 이라 어떤 관리자 게이트도 못 넘는다).
 *
 * @param array|null $userRow users 행
 */
function global_role_of(?array $userRow): string
{
    $r = $userRow === null ? null : ($userRow['role'] ?? null);
    if (!is_string($r) || $r === '') {
        return 'user';
    }
    return $r;
}

/**
 * 행위자(요청자)의 전역 role. `$ctx['grades']` 가 아니라 `$ctx['user']`(= users 행)를 읽는다.
 * `$ctx['user']` 가 없으면 `'user'` — fail-closed.
 *
 * v1.8.6 — 리더 판정 D-346 / 보안 High H186-1: **서비스 세션(세션 행 service_id 있음)은 전역 역할이 없다.**
 * 시스템 SSO 로 받은 토큰은 그 시스템을 위한 것이다. superadmin 이 어느 시스템에 로그인해 받은 토큰이
 * ulogin 관리자 API 전체(회원·서비스·계정 연결·설정 번들)에 통했다 — Bearer 라 CSRF 도 거치지 않는다.
 * 그 시스템 하나가 뚫리면 ulogin 관리자가 넘어간다. 그래서 전역 역할을 `user` 로 본다.
 * 판정 기준은 **정식 id 칸 `service_id`** 다. 개명 echo 용 `client_service_id`(0006)는 보지 않는다 —
 * 별칭으로 들어온 세션도 service_id 는 언제나 채워져 있다.
 * ulogin 자체 화면의 세션(login.html → /api/auth/login, service_id 없음)은 그대로다.
 */
function ctx_global_role(array $ctx): string
{
    if (!empty($ctx['session']['service_id'])) {
        return 'user';
    }
    $u =isset($ctx['user']) && is_array($ctx['user']) ? $ctx['user'] : null;
    return global_role_of($u);
}

/** `require_role()` 의 전역 role 판정 버전. 관리자 API 는 반드시 이쪽을 쓴다(D-50). */
function require_global_role(array $ctx, string $min): void
{
    if (!role_gte(ctx_global_role($ctx), $min)) {
        throw new AppError('FORBIDDEN', 403);
    }
}

function require_level(array $ctx, string $min): void
{
    if (!level_gte($ctx['grades']['account_level'] ?? null, $min)) {
        throw new AppError('FORBIDDEN', 403);
    }
}

function require_tier(array $ctx, string $min): void
{
    if (!tier_gte($ctx['grades']['billing_tier'] ?? null, $min)) {
        throw new AppError('TIER_INSUFFICIENT', 403, ['required' => $min]);
    }
}
