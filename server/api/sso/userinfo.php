<?php
declare(strict_types=1);
/**
 * POST /server/api/sso/userinfo.php — 계정 한 명의 정보 + 시스템별 접근 맵 (s2s) (v1.9.0)
 * 담당 계약: `_workspace/80_architect_v18_access_spec.md` §1-1 · §6-5 · 판정 D-350(키) · 설계 116 §7
 * 응답 최상위 키: uuid, username, name, status, role, role_rank, services, access
 *   access (v1.10.0, 설계 127 §3-2 — 순수 추가): {id: {level, scopes, until}} level ≥ view 만. scopes 는 호출 서비스만.
 *   비상 계정(account_kind=emergency)은 SSO 대상이 아니라 404 다(§6-2).
 *
 * role 은 **전역**(오버라이드 무시). services = 활성 서비스 전부 {id: bool}, status≠active 면 전부 false.
 * withdrawn 은 404 가 아니라 200(+ 전부 false). is_confidential=0 서비스는 401(검사 생략이 아니라 거절).
 * secret 은 응답·로그 어디에도 남기지 않는다. 빈도: IP 300/300(인증 전) · 서비스 600/300(인증 뒤).
 */
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/accountlink.php';

only_method('POST');
rate_limit('svcapi:ip:' . client_ip(), 300, 300);
$in = body_json();
$uuid = $in['uuid'] ?? null;
if (!is_string($uuid) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', strtolower($uuid)) !== 1) {
    throw new AppError('VALIDATION_FAILED', 400, ['field' => 'uuid', 'message' => 'uuid 형식이 올바르지 않습니다.']);
}
$svc = al_s2s_auth($in);
$sid = (string) $svc['service_id'];
rate_limit('svcapi:userinfo:' . $sid, 600, 300);

$user = db_one('SELECT * FROM ' . T_USERS . ' WHERE uuid = ?', [strtolower($uuid)]);
if ($user === null || is_emergency_user($user)) {
    throw new AppError('NOT_FOUND', 404, ['field' => 'uuid']);
}
$usBy = [];
foreach (db_all('SELECT * FROM ' . T_USER_SERVICES . ' WHERE user_id = ?', [(int) $user['id']]) as $r) {
    $usBy[(string) $r['service_id']] = $r;
}
$role = global_role_of($user);
plat_usage_add($sid, 'sso_userinfo', 1, true);

json_out([
    'uuid'      => (string) $user['uuid'],
    'username'  => (string) $user['username'],
    'name'      => ($user['name'] ?? null) === null || (string) $user['name'] === '' ? null : (string) $user['name'],
    'status'    => (string) $user['status'],
    'role'      => $role,
    'role_rank' => rank_of(ROLE_RANK, $role),
    'services'  => json_object(service_access_map($user, access_active_services(), $usBy)),
    'access'    => json_object(access_map($user, $sid, $usBy)),
]);
