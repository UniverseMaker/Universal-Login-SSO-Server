<?php
declare(strict_types=1);
/**
 * GET /api/auth/csrf — CSRF 토큰 발급 (쿠키 세션 UI 전용)
 * 담당 계약: 01_architect_api_contract.md §4-6, 스펙 §8-4
 * 응답 최상위 키: csrf_token, expires_in
 *
 * 비로그인 상태에서도 호출 가능하다(로그인 폼용). 로그인 후 호출하면
 * 세션 행의 csrf_token_hash 와 연결된다.
 */
require_once __DIR__ . '/../../core/bootstrap.php';

only_method('GET');

$token = csrf_token();

json_out([
    'csrf_token' => $token,
    'expires_in' => (int) SESSION_TTL,
]);
