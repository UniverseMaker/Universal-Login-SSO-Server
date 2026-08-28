<?php
declare(strict_types=1);
/**
 * GET /api/sso/validate — SSO 진입 사전 검증
 * 담당 계약: 01_architect_api_contract.md §5-1
 * 응답 최상위 키: service_id, service_name, redirect_uri_valid, allow_register,
 *                 requires_pkce, is_confidential, site_name
 *
 * login.html 이 화면을 그리기 전에 이 엔드포인트로 service_id + redirect_uri 를
 * 먼저 검증한다. 검증 전에 리다이렉트하면 그 자체가 오픈 리다이렉트 취약점이다.
 *
 * redirect_uri 는 origin+path **완전 일치**만 통과한다. 접두사 매칭은
 * `http://localhost:5173/callback.html.evil.com` 류 우회를 허용하므로 금지다.
 * 비교는 core/auth.php 의 redirect_uri_allowed() 하나로만 한다.
 */
require_once __DIR__ . '/../../core/bootstrap.php';

only_method('GET');

// 쿼리 파라미터. 본문이 아니라 $_GET 이다.
$serviceId   = (string) v_string($_GET, 'service_id', 1, 64, true);
$redirectUri = (string) v_string($_GET, 'redirect_uri', 1, 500, true);

// 1) 서비스 — 없거나 status != 'active' 면 동일하게 SSO_INVALID_SERVICE.
//    "존재하지만 비활성"과 "아예 없음"을 구분하면 서비스 목록을 탐색할 수 있다.
$service = service_load($serviceId);
if ($service === null) {
    throw new AppError('SSO_INVALID_SERVICE', 400, ['field' => 'service_id']);
}

// 2) redirect_uri 화이트리스트 대조
if (!redirect_uri_allowed($service, $redirectUri)) {
    throw new AppError('SSO_INVALID_REDIRECT', 400, ['field' => 'redirect_uri']);
}

// 3) 가입 허용 여부는 전역 설정 AND 서비스 설정. 둘 중 하나라도 꺼져 있으면 false.
//    화면이 "회원가입" 링크를 띄울지 결정하는 값이므로 실제 register 정책과 같아야 한다.
$allowRegister = ((bool) setting_get('allow_register', ALLOW_REGISTER))
    && ((bool) $service['allow_register']);

json_out([
    'service_id'         => (string) $service['service_id'],
    'service_name'       => (string) $service['service_name'],
    'redirect_uri_valid' => true,
    'allow_register'     => $allowRegister,
    // v1.0.0 은 PKCE 를 강제하지 않는다. services 에 해당 컬럼이 없다 —
    // 클라이언트가 code_challenge 를 보내면 검증하고, 안 보내도 통과시킨다.
    'requires_pkce'      => false,
    'is_confidential'    => (bool) $service['is_confidential'],
    'site_name'          => (string) setting_get('site_name', SITE_NAME),
]);
