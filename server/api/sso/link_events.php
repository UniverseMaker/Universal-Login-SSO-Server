<?php
declare(strict_types=1);
/**
 * POST /server/api/sso/link_events.php — 연결 사건 피드 (v1.8.4)
 * 담당 계약: 설계 92 S-1 · S-4 (다른 시스템이 이 계약대로 이미 운영 중 — 응답 shape 을 바꾸지 마라)
 * 응답 최상위 키: events, next_after, has_more, sync_requested
 *   events[] = {event_id, type(linked|unlinked), external_id, uuid, at}
 *
 * 서버 대 서버 전용. 인증은 accounts_push 와 같은 al_s2s_auth(). 분당 30회.
 * 자기 서비스의 사건만 나간다(service_id 는 인증된 서비스로 고정 — 본문 값으로 남의 피드를 못 읽는다).
 */
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/accountlink.php';

only_method('POST');

$in  = body_json();
$svc = al_s2s_auth($in);
$sid = (string) $svc['service_id'];
rate_limit('s2s:feed:' . $sid, 30, 60);

$after = (int) v_int($in, 'after', 0, PHP_INT_MAX, false, 0);
$limit = (int) v_int($in, 'limit', 1, 500, false, 100);

$f = al_feed($sid, $after, $limit);

json_out([
    'events'         => $f['events'],
    'next_after'     => $f['next_after'],
    'has_more'       => $f['has_more'],
    'sync_requested' => al_sync_requested($sid),
]);
