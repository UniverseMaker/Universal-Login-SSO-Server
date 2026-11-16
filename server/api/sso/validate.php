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

// ---------------------------------------------------------------------
// 익명 화이트리스트 열거 — **이월 항목을 이번 라운드에 닫는다** (감사 M17-7).
// 출처: `42b_security_v14.md:590` (V14 A-11, Medium, **미수정 이월**).
//
// 이 엔드포인트는 **인증 없이** 세 가지를 구분해 답한다:
//   없는 service_id → SSO_INVALID_SERVICE / 있는 service_id + 미등록 redirect_uri
//   → SSO_INVALID_REDIRECT / 둘 다 맞음 → 200 + service_name·is_confidential·site_name
// 전용 버킷이 없어 전역 `api:ip` 300/60 하나였고, 그것은 **IP 당 하루 432,000회** 탐색이다.
//
// v1.7.0 이 더한 것: 무음 경로(`authorize_session.php`)가 같은 두 코드를 구분해
// 돌려주는 **두 번째 창구**를 열었다. 그쪽 버킷은 `sso_silent:user` 30/300 으로 이미 좁다.
// **「추측할 것이 아무것도 없다」가 아니라, 추측 대상이 비밀번호에서
//   화이트리스트 항목으로 바뀐 것**이다 — 설계가 그 축을 보지 않았다.
//
// 피해 자체는 낮다(읽을 수는 있어도 **등록할 수는 없다** — 서비스 등록은 role>=admin).
// 실질은 정찰이다: 어떤 시스템이 이 ulogin 을 쓰는가 / 콜백 경로가 무엇인가.
//
// **오류 코드 셋을 하나로 합치지 않는다.** `public/login.html` 의 `showSsoError()` 가 띄우는
// 「화이트리스트에 등록돼 있지 않습니다」 안내가 그 구분에 의존하고, 운영에서 실제로 쓰인다.
// **속도만 묶는다.** 정상 사용은 SSO 진입 1회당 1호출이다.
rate_limit('sso_validate:ip:' . client_ip(), 60, 300);

// 쿼리 파라미터. 본문이 아니라 $_GET 이다.
$serviceId   = (string) v_service_id($_GET, 'service_id', true);   // v1.8.6 — 옛 id 는 여기서 새 id 로(설계 104 C-1)
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
//    그래서 register.php 와 **같은 함수**를 부른다(v1.8.2 — core/auth.php registration_open()).
$allowRegister = registration_open($service);

json_out([
    // v1.8.6 — 옛 id 로 물으면 옛 id 를 되돌린다(설계 104 C-2). 전환 전 응답과 글자 그대로 같다.
    'service_id'         => service_id_out((string) $service['service_id'], null),
    'service_name'       => (string) $service['service_name'],
    'redirect_uri_valid' => true,
    'allow_register'     => $allowRegister,
    // v1.0.0 은 PKCE 를 강제하지 않는다. services 에 해당 컬럼이 없다 —
    // 클라이언트가 code_challenge 를 보내면 검증하고, 안 보내도 통과시킨다.
    'requires_pkce'      => false,
    'is_confidential'    => (bool) $service['is_confidential'],
    'site_name'          => (string) setting_get('site_name', SITE_NAME),
]);
