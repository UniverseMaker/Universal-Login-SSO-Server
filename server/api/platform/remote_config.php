<?php
declare(strict_types=1);
/**
 * GET|POST /server/api/platform/remote_config.php — 전역 설정 조회 (v1.9.0)
 * 담당 계약: 설계 116 §4
 *
 * GET  (user 갈래 — 브라우저, Bearer 서비스 토큰) 헤더 If-None-Match 선택
 *      응답 최상위 키: etag, versions, values  · 같으면 304 본문 없음 · 헤더 ETag · Cache-Control: private, no-cache
 * POST (s2s — 본문 service_id+service_secret, 선택 if_etag · uuid)
 *      응답 최상위 키: changed, etag  (+ 바뀌었으면 versions, params · uuid 를 주면 values)
 *
 * 초안은 절대 나가지 않는다(게시본만). 값은 비밀이 아니다(키 이름 가드 A7) — 그래서 이 경로만 캐시를 허용한다.
 * 빈도: user 60/60(사용자당) · s2s 120/60.
 */
define('ULOGIN_PLATFORM_PATH', 1);
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/platauth.php';
require_once __DIR__ . '/../../core/rc.php';

only_method('GET', 'POST');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    // user 갈래 전용 — 서비스는 토큰이 정한다. 쿼리의 service_id 는 대조용으로만 받는다.
    $q = [];
    if (isset($_GET['service_id']) && is_string($_GET['service_id']) && $_GET['service_id'] !== '') {
        $q['service_id'] = (string) $_GET['service_id'];
    }
    if (bearer_token() === null) {
        throw new AppError('AUTH_REQUIRED', 401);
    }
    $c = plat_caller($q, ['s2s' => false, 'user' => true, 'anon' => false]);
    rate_limit('plat:cfg:user:' . (int) $c['user']['id'], 60, 60);
    $sid = (string) $c['service']['service_id'];
    $versions = null;
    $params = rc_merged($sid, $versions);
    $role = global_role_of($c['user']);
    $feat = features_effective($c['service'], $c['user_service']);
    $feat = $feat === null ? [] : $feat;
    $etag = rc_etag($versions, rc_who_user($role, $feat));
    plat_usage_add($sid, 'cfg_reads', 1, true);

    response_headers(true);   // 이 경로만 private, no-cache (설계 §4)
    if (!headers_sent()) {
        header('ETag: "' . $etag . '"');
    }
    $inm = req_header('If-None-Match');
    if ($inm !== null && trim(str_replace(['W/', '"'], '', $inm)) === $etag) {
        http_response_code(304);
        if (!headers_sent()) {
            header('Cache-Control: private, no-cache');
        }
        exit;
    }
    json_out([
        'etag'     => $etag,
        'versions' => $versions,
        'values'   => json_object(rc_evaluate($params, $role, $feat)),
    ]);
}

// POST — s2s
$in = plat_body(65536);
$c  = plat_caller($in, ['s2s' => true, 'user' => false, 'anon' => false]);
$sid = (string) $c['service']['service_id'];
rate_limit('plat:cfg:svc:' . $sid, 120, 60);

$uuid = $in['uuid'] ?? null;
$user = null;
$us   = null;
if ($uuid !== null) {
    if (!is_string($uuid) || preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $uuid) !== 1) {
        throw new AppError('VALIDATION_FAILED', 400, ['field' => 'uuid', 'message' => 'uuid 형식이 올바르지 않습니다.']);
    }
    $user = db_one('SELECT * FROM ' . T_USERS . ' WHERE uuid = ?', [strtolower($uuid)]);
    if ($user === null) {
        throw new AppError('NOT_FOUND', 404, ['field' => 'uuid']);
    }
    $us = service_access_us_row((int) $user['id'], $sid);
}
$versions = null;
$params = rc_merged($sid, $versions);
$who = 's2s';
$role = 'user';
$feat = [];
if ($user !== null) {
    $role = global_role_of($user);
    $f = features_effective($c['service'], $us);
    $feat = $f === null ? [] : $f;
    $who = 's2s|' . rc_who_user($role, $feat);
}
$etag = rc_etag($versions, $who);
plat_usage_add($sid, 'cfg_reads', 1, true);

$ifEtag = $in['if_etag'] ?? null;
if (is_string($ifEtag) && $ifEtag === $etag) {
    json_out(['changed' => false, 'etag' => $etag]);
}
if ($user !== null) {
    json_out([
        'changed'  => true,
        'etag'     => $etag,
        'versions' => $versions,
        'params'   => json_object($params),
        'values'   => json_object(rc_evaluate($params, $role, $feat)),
    ]);
}
json_out([
    'changed'  => true,
    'etag'     => $etag,
    'versions' => $versions,
    'params'   => json_object($params),
]);
