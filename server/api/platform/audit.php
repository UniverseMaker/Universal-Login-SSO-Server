<?php
declare(strict_types=1);
/**
 * POST /server/api/platform/audit.php — 감사 수집 (누가·무엇을·전후·언제·어디서) (v1.9.0)
 * 담당 계약: 설계 116 §0-8 (발송된 계약 — shape 을 바꾸지 마라) · §5 체인 · A5
 * 응답 최상위 키: accepted, duplicate, redacted, rejected, receipt
 *
 * **s2s 전용** — 브라우저(user 토큰)가 보낸 감사는 믿을 수 없다(403 FORBIDDEN).
 * 수정·삭제 API 가 없다. 보존 만료(audit_prune)로만 지워진다.
 * receipt = 이번 배치 마지막 행의 체인 위치·해시. 새로 들어간 행이 없으면(전부 중복) 현재 머리.
 */
define('ULOGIN_PLATFORM_PATH', 1);
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/platauth.php';

only_method('POST');
$in = plat_body(PLAT_BODY_MAX);
$c  = plat_caller($in, ['s2s' => true, 'user' => false, 'anon' => false]);
$svc = $c['service'];
$sid = (string) $svc['service_id'];
rate_limit('plat:audit:svc:' . $sid, 60, 60);

$v = plat_batch_validate($in, PLAT_BATCH_MAX, function ($it) use ($sid) {
    return plat_audit_item($it, $sid);
});

$have = plat_existing_event_ids(T_PLAT_AUDIT, $sid, array_map(function ($r) { return $r['event_id']; }, $v['rows']));
$new = [];
foreach ($v['rows'] as $r) {
    if (!isset($have[$r['event_id']])) {
        $new[] = $r;
    }
}
$duplicate = count($v['rows']) - count($new) + $v['dup_in_batch'];

if ($new !== []) {
    plat_quota_check($svc, 'audit_events', count($new), 'events');
}

audit_ensure_head();
$receipt = null;
$accepted = 0;
if ($new !== []) {
    try {
        $receipt = db_tx(function () use ($new, $sid) {
            $r = audit_append_in_tx($new);
            plat_usage_add($sid, 'audit_events', count($new));
            return $r;
        });
        $accepted = count($new);
    } catch (Throwable $e) {
        if (!db_is_duplicate($e)) {
            throw $e;
        }
        // 동시에 온 같은 event_id — 다시 걸러 한 번 더(체인은 롤백돼 그대로다).
        $have = plat_existing_event_ids(T_PLAT_AUDIT, $sid, array_map(function ($r) { return $r['event_id']; }, $new));
        $left = [];
        foreach ($new as $r) {
            if (!isset($have[$r['event_id']])) {
                $left[] = $r;
            }
        }
        $duplicate += count($new) - count($left);
        if ($left !== []) {
            $receipt = db_tx(function () use ($left, $sid) {
                $r = audit_append_in_tx($left);
                plat_usage_add($sid, 'audit_events', count($left));
                return $r;
            });
        }
        $accepted = count($left);
    }
}
if ($receipt !== null) {
    audit_head_note($receipt);   // M190-4 — 커밋된 머리를 DB 밖 사본에
}
if ($receipt === null) {
    // v1.9.2 L190-4 — 전역 머리는 남의 서비스 행이다. 이 서비스의 행만 본다: 이 배치의 event_id 가 가리키는 행 중 마지막,
    // 없으면(배치가 비었거나 전부 거절) 이 서비스의 마지막 행, 그것도 없으면 시작점.
    $mine = null;
    $eids = array_map(function ($r) { return $r['event_id']; }, $v['rows']);
    if ($eids !== []) {
        $mine = db_one('SELECT id, row_hash FROM ' . T_PLAT_AUDIT . ' WHERE service_id = ? AND event_id IN ('
            . implode(',', array_fill(0, count($eids), '?')) . ') ORDER BY id DESC LIMIT 1', array_merge([$sid], array_values($eids)));
    }
    if ($mine === null) {
        $mine = db_one('SELECT id, row_hash FROM ' . T_PLAT_AUDIT . ' WHERE service_id = ? ORDER BY id DESC LIMIT 1', [$sid]);
    }
    $receipt = $mine === null ? ['seq' => 0, 'hash' => AUDIT_GENESIS]
        : ['seq' => (int) $mine['id'], 'hash' => (string) $mine['row_hash']];   // RECEIPT-OWN
}

json_out([
    'accepted'  => $accepted,
    'duplicate' => $duplicate,
    'redacted'  => $v['redacted'],
    'rejected'  => $v['rejected'],
    'receipt'   => ['seq' => (int) $receipt['seq'], 'hash' => (string) $receipt['hash']],
]);
