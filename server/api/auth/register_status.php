<?php
declare(strict_types=1);
/**
 * GET /api/auth/register_status — 신규 가입을 받는가 (v1.8.2 신규 · 인증 불필요)
 * 담당 계약: 신규 추가 항목(순수 추가 — 기존 엔드포인트 shape 무변경)
 * 응답 최상위 키: allow_register
 *
 * 왜 있는가: v1.8.1 까지 화면이 가입 허용 여부를 받는 길은 SSO 경로의 `sso/validate` 뿐이었다.
 * SSO 파라미터 없이 `/login` 을 열면 값을 받을 데가 없어 **전역 가입 차단 상태에서도**
 * 「회원가입」 링크가 보였고 `/register` 폼도 열렸다(가입 API 는 403 으로 막혔다 — 표시만의 문제).
 *
 * 판정은 `register.php` 와 **같은 함수** `registration_open()`(core/auth.php)다.
 * 두 곳이 다른 값을 내면 링크는 보이는데 가입은 막히는 지금의 문제가 그대로 돌아온다.
 *
 * 내보내는 것은 **전역 설정 하나의 불리언**뿐이다. 서비스별 값은 받지 않는다 —
 * `service_id` 를 받으면 익명 서비스 존재 탐색 창구가 하나 더 생긴다(감사 M17-7 과 같은 축).
 * 서비스별 판단은 SSO 진입 화면이 `sso/validate` 의 `allow_register` 로 이미 한다.
 * 이 값은 비밀이 아니다 — 가입 폼에 POST 해 보면 누구나 알 수 있는 사실이다.
 *
 * 레이트리밋: 전용 버킷을 두지 않는다. 전역 `api:ip` 300/60(bootstrap)이 걸린다.
 * 정상 사용은 로그인·가입 화면을 열 때 1회다.
 */
require_once __DIR__ . '/../../core/bootstrap.php';

only_method('GET');

json_out([
    'allow_register' => registration_open(),
]);
