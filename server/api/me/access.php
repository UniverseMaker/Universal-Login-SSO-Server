<?php
declare(strict_types=1);
/**
 * GET /api/me/access — 내가 볼 수 있는 시스템(단계 ≥ view) (v1.10.0)
 * 담당 계약: `_workspace/127_architect_v1100_design.md` §3-4 · 판정 D-367
 * 응답 최상위 키: items   (items[]: service_id, service_name, level, until)
 *
 * 본인 것만. 마이페이지 포털 바로 가기에 쓴다. 판정은 access_level_eval() 하나(S1) — 화면은 다시 계산하지 않는다.
 * 서비스 토큰(세션 service_id 있음)으로는 다른 시스템 목록을 주지 않는다 — ulogin 자체 세션만(H186-1 과 같은 선).
 * 401 AUTH_REQUIRED.
 */
require_once __DIR__ . '/../../core/bootstrap.php';

only_method('GET');

$ctx  = require_auth();
if (!empty($ctx['session']['service_id'])) {
    throw new AppError('FORBIDDEN', 403, ['message' => '통합로그인 화면의 세션으로만 볼 수 있습니다.']);
}
$user = $ctx['user'];

$svcs = access_active_services();
$items = [];
foreach (access_levels_for_user($user, $svcs) as $sid => $d) {
    if (access_rank($d['level']) < ACCESS_RANK['view']) {
        continue;
    }
    $name = $sid;
    foreach ($svcs as $s) {
        if ((string) $s['service_id'] === (string) $sid) {
            $name = (string) $s['service_name'];
            break;
        }
    }
    $items[] = ['service_id' => (string) $sid, 'service_name' => $name, 'level' => $d['level'], 'until' => $d['until']];
}
json_out(['items' => $items]);
