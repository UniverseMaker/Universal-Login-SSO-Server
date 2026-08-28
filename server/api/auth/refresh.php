<?php
declare(strict_types=1);
/**
 * POST /api/auth/refresh — refresh 토큰 회전
 * 담당 계약: 01_architect_api_contract.md §4-4, 스펙 §5-2
 * 응답 최상위 키: user, session, access_token, refresh_token, token_type,
 *                 expires_in, refresh_expires_in, payload
 *
 * 사용된 refresh 는 즉시 폐기되고 session_id 도 새 값이 된다.
 * payload 는 재계산한다 — 등급이 바뀌었거나 시크릿이 교체됐을 수 있고,
 * 그러면 로컬 앱이 폐기된 API 키를 계속 쓰게 된다.
 */
require_once __DIR__ . '/../../core/bootstrap.php';

only_method('POST');

$in       = body_json();
$refresh  = (string) v_string($in, 'refresh_token', 64, 64, true);
$devLabel = v_string($in, 'device_label', 1, 80);

$r = rotate_refresh($refresh, ['device_label' => $devLabel]);

json_out([
    'user'               => serialize_user($r['user'], $r['user_service']),
    'session'            => serialize_session($r['session'], true),
    'access_token'       => $r['access_token'],
    'refresh_token'      => $r['refresh_token'],
    'token_type'         => 'Bearer',
    'expires_in'         => $r['expires_in'],
    'refresh_expires_in' => $r['refresh_expires_in'],
    'payload'            => json_object(build_payload($r['session']['service_id'] ?? null, $r['grades'])),
]);
