<?php
declare(strict_types=1);
/**
 * POST /server/api/points/s2s_earn.php — 공동 포인트 적립 (서버 간, v1.10.1)
 * 담당 계약: 설계 130 §4-1 · §4-2 · §4-4 · §4-5 · §4-6 · 판정 D-371 Q-5
 * 요청: {service_id, service_secret, uuid, amount(정수), idempotency_key(필수), ref_type?, ref_id?, memo?}
 * 응답 최상위 키: uuid, delta, points, balance_after, log_id, idempotent_replay, created_at
 * 인증 순서는 sso/userinfo.php 와 같다: IP 빈도 → uuid 형식 → al_s2s_auth(본문 secret, confidential 만) → 서비스 빈도.
 * 대상 판정·한도·멱등·원장·감사는 core/points.php (points_s2s_move → points_s2s_target → points_apply).
 */
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/accountlink.php';
require_once __DIR__ . '/../../core/points.php';

only_method('POST');
rate_limit('svcapi:ip:' . client_ip(), 300, 300);
$in = body_json();
points_s2s_uuid($in);
$svc = al_s2s_auth($in);
rate_limit('svcapi:points:' . (string) $svc['service_id'], 600, 300);

points_s2s_move($svc, $in, 'earn');
