<?php
declare(strict_types=1);
/**
 * POST /server/api/platform/features.php — 기능 등록부 통째 교체 (s2s) (v1.9.0)
 * 담당 계약: 설계 116 §7 · D-326
 * 요청 {service_id, service_secret, features:[{id,name,default}]}  (id [a-z0-9_.]{1,64}, ≤ 64개, 중복 금지)
 * 응답 최상위 키: count
 * 빠진 id 는 사용자 지정에서 교집합으로 자연히 사라진다. 서비스의 감사 체인에 service 주체로 남긴다.
 */
define('ULOGIN_PLATFORM_PATH', 1);
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/platauth.php';

only_method('POST');
$in = plat_body(65536);
$c  = plat_caller($in, ['s2s' => true, 'user' => false, 'anon' => false]);
$sid = (string) $c['service']['service_id'];
rate_limit('plat:features:svc:' . $sid, 30, 60);

$err = null;
$reg = features_norm_registry($in['features'] ?? null, $err);
if ($reg === null) {
    throw new AppError('VALIDATION_FAILED', 400, ['field' => 'features', 'message' => (string) $err]);
}
$before = features_registry($c['service']);
db_exec('UPDATE ' . T_SERVICES . ' SET features_json = ?, updated_at = ? WHERE service_id = ?', [plat_json($reg), now_utc(), $sid]);
try {
    $row = audit_ulogin_row('service', null, 'features.registry.replace', 'service', $sid, [
        'features' => ['before' => $before === null ? null : count($before), 'after' => count($reg)],
    ]);
    audit_append([$row]);
} catch (Throwable $e) {
    error_log('[ulogin] features audit failed: ' . $e->getMessage());
    audit_mark_failure();
}
json_out(['count' => count($reg)]);
