<?php
declare(strict_types=1);
/**
 * POST /server/api/platform/logs.php — 운영 로그·이벤트 수집 (v1.9.0)
 * 담당 계약: 설계 116 §0-7 (각 시스템에 이미 발송된 계약 — 응답 shape 을 바꾸지 마라) · §5 · H-11
 * 응답 최상위 키: accepted, duplicate, truncated, redacted, rejected
 *
 * s2s(본문 service_id+service_secret) 기본. user 갈래(Bearer)는 서비스 platform_json.logs_from_users=true 일 때만,
 * 배치 ≤ 20 · 30회/60초 사용자당 · uuid 는 토큰 주인으로 덮어쓴다(H-11 기본 꺼짐).
 * 빠른 경로(§5): 크기(헤더) → 본문 → 크기(실측) → 인증 → 빈도 → 항목 검증·마스킹(트랜잭션 밖) → 중복 SELECT 1회
 *                → **트랜잭션 1개**(INSERT 반복 + plat_usage 증가) → 응답.
 */
define('ULOGIN_PLATFORM_PATH', 1);
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/platauth.php';

only_method('POST');
$in = plat_body(PLAT_BODY_MAX);
$c  = plat_caller($in, ['s2s' => true, 'user' => true, 'anon' => false]);
plat_require_use($c);   // v1.10.0 — view 단계는 로그를 쓰지 못한다
$svc = $c['service'];
$sid = (string) $svc['service_id'];

$max = PLAT_BATCH_MAX;
$forceUuid = null;
if ($c['kind'] === 'user') {
    $opts = plat_service_opts($svc);
    if (empty($opts['logs_from_users'])) {
        throw new AppError('FORBIDDEN', 403, ['message' => '이 서비스는 브라우저 로그 수집을 켜지 않았습니다.']);
    }
    rate_limit('plat:logs:user:' . (int) $c['user']['id'], 30, 60);
    $max = PLAT_USER_BATCH_MAX;
    $forceUuid = (string) $c['user']['uuid'];
} else {
    rate_limit('plat:logs:svc:' . $sid, 60, 60);
}

$source = $c['kind'] === 'user' ? 'user' : 's2s';
$v = plat_batch_validate($in, $max, function ($it) use ($sid, $source, $forceUuid) {
    return plat_log_item($it, $sid, $source, $forceUuid);
});

$have = plat_existing_event_ids(T_PLAT_LOGS, $sid, array_map(function ($r) { return $r['event_id']; }, $v['rows']));
$new = [];
foreach ($v['rows'] as $r) {
    if (!isset($have[$r['event_id']])) {
        $new[] = $r;
    }
}
$duplicate = count($v['rows']) - count($new) + $v['dup_in_batch'];

$metric = plat_metric_for($c['kind'], 'log_events');   // M190-3 — user 갈래는 log_events_user
if ($new !== []) {
    plat_quota_check($svc, $metric, count($new), 'events');
}

$accepted = 0;
if ($new !== []) {
    $bytes = $v['bytes'];
    try {
        $accepted = (int) db_tx(function () use ($new, $sid, $bytes, $metric) {
            foreach ($new as $r) {
                db_insert(T_PLAT_LOGS, $r);
            }
            plat_usage_add($sid, $metric, count($new));
            plat_usage_add($sid, 'log_bytes', $bytes);
            return count($new);
        });
    } catch (Throwable $e) {
        // 같은 event_id 가 동시에 두 번 왔다 — 하나씩 다시 넣고 중복은 duplicate 로 센다(멱등 = 성공).
        if (!db_is_duplicate($e)) {
            throw $e;
        }
        $accepted = 0;
        foreach ($new as $r) {
            try {
                db_insert(T_PLAT_LOGS, $r);
                $accepted++;
            } catch (Throwable $e2) {
                if (!db_is_duplicate($e2)) {
                    throw $e2;
                }
                $duplicate++;
            }
        }
        plat_usage_add($sid, $metric, $accepted, true);
    }
}

json_out([
    'accepted'  => $accepted,
    'duplicate' => $duplicate,
    'truncated' => $v['truncated'],
    'redacted'  => $v['redacted'],
    'rejected'  => $v['rejected'],
]);
