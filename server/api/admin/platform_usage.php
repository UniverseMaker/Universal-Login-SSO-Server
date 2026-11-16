<?php
declare(strict_types=1);
/**
 * GET /server/api/admin/platform_usage.php?days=30&service_id — 플랫폼 사용량 (v1.9.0)
 * 담당 계약: 설계 116 §8
 * 응답 최상위 키: items, totals    (items[]: service_id, day, metric, value · totals: {metric: n})
 * 쿠키 세션 + 전역 role admin.
 */
require_once __DIR__ . '/../../core/bootstrap.php';

only_method('GET');
$ctx = require_auth();
require_global_role($ctx, 'admin');

$days = v_int($_GET, 'days', 1, 366, false, 30);
$sid  = v_string($_GET, 'service_id', 1, 64, false);
admin_service_id_reject_former($sid);
$from = gmdate('Y-m-d', time() - ((int) $days - 1) * 86400);
$w = '`day` >= ?';
$p = [$from];
if ($sid !== null) {
    $w .= ' AND service_id = ?';
    $p[] = $sid;
}
$items = [];
$totals = [];
foreach (db_all('SELECT service_id, `day`, metric, `value` FROM ' . T_PLAT_USAGE . ' WHERE ' . $w
    . ' ORDER BY `day` DESC, service_id ASC, metric ASC LIMIT 20000', $p) as $r) {
    $items[] = ['service_id' => (string) $r['service_id'], 'day' => (string) $r['day'], 'metric' => (string) $r['metric'],
        'value' => (int) $r['value']];
    $totals[(string) $r['metric']] = ($totals[(string) $r['metric']] ?? 0) + (int) $r['value'];
}
json_out(['items' => $items, 'totals' => json_object($totals)]);
