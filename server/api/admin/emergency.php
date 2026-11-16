<?php
declare(strict_types=1);
/**
 * GET|POST /server/api/admin/emergency.php — 비상 확인값 관리 (v1.9.1, emergency/1 1-1장)
 * 담당 계약: 주관 계약 비상복구모드-계약-emergency1.md 1-1장 · 판정 D-362
 *
 * GET  ?uuid                     -> uuid, services[{service_id, expires_at}]   살아 있는 확인값이 있는 서비스(값은 주지 않는다)
 * POST {uuid[, service_id]}      -> uuid, revoked(폐기한 서비스 수)             관리자 강제 폐기. 최고관리자만.
 *
 * ev 는 이 엔드포인트 응답·감사에 나가지 않는다.
 */
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/accountlink.php';

only_method('GET', 'POST');
$ctx = require_auth();
require_global_role($ctx, 'superadmin');
$adminId = (int) $ctx['user']['id'];
$method  = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

$in   = $method === 'GET' ? $_GET : body_json();
$uuid = $in['uuid'] ?? null;
if (!is_string($uuid) || !al_is_uuid($uuid)) {
    v_fail('uuid', '회원 식별자 형식이 올바르지 않습니다.');
}
$u = db_one('SELECT id, uuid FROM ' . T_USERS . ' WHERE uuid = ?', [strtolower($uuid)]);
if ($u === null) {
    throw new AppError('NOT_FOUND', 404, ['field' => 'uuid', 'message' => '회원을 찾을 수 없습니다.']);
}

if ($method === 'GET') {
    json_out(['uuid' => (string) $u['uuid'], 'services' => em_live_services((string) $u['uuid'])]);
}

require_csrf();
$sid = null;
if (isset($in['service_id']) && $in['service_id'] !== null && $in['service_id'] !== '') {
    $sid = (string) v_string($in, 'service_id', 1, 64, true);
    admin_service_id_reject_former($sid);
}
$n = em_revoke_user((int) $u['id'], 'admin_revoke', $sid, (string) $u['uuid']);
log_admin($adminId, 'emergency.admin_revoke', 'user', (string) $u['uuid'], null,
    ['service_id' => $sid, 'revoked' => $n]);
json_out(['uuid' => (string) $u['uuid'], 'revoked' => $n]);
