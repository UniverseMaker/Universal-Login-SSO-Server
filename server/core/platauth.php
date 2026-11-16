<?php
declare(strict_types=1);

/**
 * core/platauth.php — 플랫폼 데이터 API 의 호출자 판정 (v1.9.0)
 * 담당 계약: 설계 116 §2 (인증 두 갈래 + anon) · §6 public 읽기(H-8) · 판정 D-346 H186-1
 *
 *   s2s  본문 service_id + service_secret → al_s2s_auth() 재사용(실패 버킷·별칭·dummy verify 포함). 자기 네임스페이스 전권
 *   user Authorization: Bearer + 세션 auth_type='token' + service_id NOT NULL. 본문 service_id 가 있으면 정식 id 가 같아야 한다
 *        → require_service_access()(집행 6번째 소비자). 컬렉션 규칙이 적용된다
 *   anon 둘 다 없음 + 본문 service_id → public 컬렉션 get·query 만(엔드포인트가 판정)
 *
 * 🔴 **둘 다 오면 400** field=service_secret — 갈래가 모호한 요청을 받지 않는다.
 * 🔴 **쿠키 세션은 무시한다** — 플랫폼 경로에 CSRF 면을 만들지 않는다. ulogin 자체 세션(service_id NULL) Bearer 는 403.
 * 관리자 API 는 이 함수를 쓰지 않는다(쿠키+CSRF+require_global_role 그대로).
 *
 * PHP 7.2 호환.
 */

if (!defined('ULOGIN_BOOTSTRAPPED')) {
    header_remove('X-Powered-By');
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/accountlink.php';

/**
 * @param array $in    본문
 * @param array $allow ['s2s'=>bool, 'user'=>bool, 'anon'=>bool]
 * @return array ['kind'=>'service'|'user'|'anon', 'service'=>services 행, 'user'=>users 행|null,
 *                'user_service'=>행|null, 'session'=>행|null]
 */
function plat_caller(array $in, array $allow): array
{
    plat_require_enabled();
    $bearer = bearer_token();
    $hasSecret = array_key_exists('service_secret', $in) && $in['service_secret'] !== null && $in['service_secret'] !== '';

    if ($bearer !== null && $hasSecret) {
        throw new AppError('VALIDATION_FAILED', 400, ['field' => 'service_secret',
            'message' => 'Bearer 토큰과 service_secret 을 함께 보낼 수 없습니다.']);
    }

    if ($hasSecret || ($bearer === null && empty($allow['anon']))) {
        if (empty($allow['s2s'])) {
            throw new AppError('FORBIDDEN', 403, ['message' => '서버 간 인증으로는 쓸 수 없는 경로입니다.']);
        }
        $svc = al_s2s_auth($in);   // 실패하면 401 SSO_CLIENT_AUTH_FAILED / 429
        return ['kind' => 'service', 'service' => $svc, 'user' => null, 'user_service' => null, 'session' => null];
    }

    if ($bearer !== null) {
        if (empty($allow['user'])) {
            throw new AppError('FORBIDDEN', 403, ['message' => '서버 간 인증(service_secret)이 필요한 경로입니다.']);
        }
        // 쿠키를 보지 않게 한다 — require_auth() 는 Bearer 가 있으면 Bearer 만 쓴다(우선순위). 그래도 지운다.
        unset($_COOKIE[COOKIE_NAME]);
        $ctx = require_auth();
        $sess = $ctx['session'];
        if ((string) ($sess['auth_type'] ?? '') !== 'token' || empty($sess['service_id'])) {
            throw new AppError('FORBIDDEN', 403, ['message' => '서비스 로그인 토큰이 필요합니다.']);
        }
        $sid = (string) $sess['service_id'];
        if (array_key_exists('service_id', $in) && $in['service_id'] !== null && $in['service_id'] !== '') {
            $claimed = v_service_id($in, 'service_id', false);
            if ($claimed !== $sid) {
                throw new AppError('FORBIDDEN', 403, ['message' => '토큰의 서비스와 요청의 서비스가 다릅니다.']);
            }
        }
        $svc = service_load($sid);
        if ($svc === null) {
            throw new AppError('FORBIDDEN', 403, ['message' => '사용할 수 없는 서비스입니다.']);
        }
        if (service_membership_blocked((int) $ctx['user']['id'], $sid)) {
            throw new AppError('FORBIDDEN', 403, ['message' => '이 서비스 이용이 제한되었습니다.']);
        }
        require_service_access($ctx['user'], $ctx['user_service'], $svc, 'platform');
        return ['kind' => 'user', 'service' => $svc, 'user' => $ctx['user'], 'user_service' => $ctx['user_service'],
                'session' => $sess];
    }

    // anon — public 읽기(H-8). 등록된 활성 서비스만. 브라우저면 그 서비스의 등록 출처여야 한다.
    $sid = v_service_id($in, 'service_id', true);
    $svc = service_load($sid);
    if ($svc === null) {
        throw new AppError('SSO_INVALID_SERVICE', 400, ['field' => 'service_id']);
    }
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN'] : '';
    if ($origin !== '' && !in_array($origin, json_col($svc['allowed_origins'] ?? null), true)) {
        throw new AppError('FORBIDDEN', 403, ['message' => '이 서비스에 등록된 출처가 아닙니다.']);
    }
    return ['kind' => 'anon', 'service' => $svc, 'user' => null, 'user_service' => null, 'session' => null];
}

/**
 * v1.10.0 (설계 127 §2) — user 갈래의 쓰기(create·set·merge·delete·logs)는 단계 ≥ use. view 는 읽기(get·query·config)만.
 * s2s·anon 갈래는 해당 없음(서비스 자신 · public 읽기). 거절은 SERVICE_ACCESS_DENIED 403.
 */
function plat_require_use(array $c): void
{
    if ($c['kind'] !== 'user') {
        return;
    }
    $d = access_level_eval($c['user'], $c['service'], $c['user_service']);
    if (access_rank($d['level']) < ACCESS_RANK['use']) {   // PLAT-WRITE-USE
        throw new AppError('SERVICE_ACCESS_DENIED', 403);
    }
}

/** 호출자별 빈도 버킷 — s2s 는 서비스, user 는 사용자, anon 은 IP. 인증 **뒤에** 건다(남의 버킷을 태우지 못하게). */
function plat_rate(array $c, string $what, int $svcMax, int $userMax, int $anonMax, int $window = 60): void
{
    $sid = (string) $c['service']['service_id'];
    if ($c['kind'] === 'service') {
        rate_limit('plat:' . $what . ':svc:' . $sid, $svcMax, $window);
    } elseif ($c['kind'] === 'user') {
        rate_limit('plat:' . $what . ':user:' . (int) $c['user']['id'], $userMax, $window);
    } else {
        rate_limit('plat:' . $what . ':anon:' . client_ip(), $anonMax, $window);
    }
}
