<?php
declare(strict_types=1);
/**
 * POST /server/api/platform/docs.php — 문서 테이블 (v1.9.0)
 * 담당 계약: 설계 116 §6 · H-8
 * 본문 op = get|create|set|merge|delete|query
 * 응답 최상위 키: get·create·set·merge → doc · delete → deleted · query → items, next_cursor
 *
 * 갈래: s2s(service_secret) · user(Bearer 서비스 토큰, 컬렉션 규칙 적용) · anon(public 컬렉션 get·query 만)
 * 빈도: user 120/60(사용자당) · s2s 600/60 · anon 120/60(IP당). 하루 읽기 200,000 / 쓰기 50,000(서비스당).
 */
define('ULOGIN_PLATFORM_PATH', 1);
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/platauth.php';
require_once __DIR__ . '/../../core/docs.php';

only_method('POST');
$in = plat_body(DOCS_MAX_BYTES + 8192);
$c  = plat_caller($in, ['s2s' => true, 'user' => true, 'anon' => true]);
plat_rate($c, 'docs', 600, 120, 120);

$op = $in['op'] ?? null;
if (!in_array($op, ['get', 'create', 'set', 'merge', 'delete', 'query'], true)) {
    throw new AppError('VALIDATION_FAILED', 400, ['field' => 'op', 'message' => 'op 는 get|create|set|merge|delete|query 입니다.']);
}
if ($c['kind'] === 'anon' && !in_array($op, ['get', 'query'], true)) {
    throw new AppError('AUTH_REQUIRED', 401);
}
if (!in_array($op, ['get', 'query'], true)) {
    plat_require_use($c);   // v1.10.0 — view 단계는 읽기만
}
$sid  = (string) $c['service']['service_id'];
$coll = docs_coll_require($sid, $in['collection'] ?? null);

// M190-3 — user·anon 은 `_user` 지표·한도(기본의 50%). s2s 한도를 태우지 못한다.
if ($op === 'get' || $op === 'query') {
    plat_quota_check($c['service'], plat_metric_for($c['kind'], 'doc_reads'), 1, 'collection', '오늘 읽기 한도를 넘었습니다.');
} else {
    plat_quota_check($c['service'], plat_metric_for($c['kind'], 'doc_writes'), 1, 'collection', '오늘 쓰기 한도를 넘었습니다.');
}

if ($op === 'get') {
    $r = docs_op_get($c, $coll, $in);
    json_out(['doc' => $r['doc']]);
}
if ($op === 'create') {
    $r = docs_op_create($c, $coll, $in);
    json_out(['doc' => $r['doc']]);
}
if ($op === 'set' || $op === 'merge') {
    $r = docs_op_update($c, $coll, $in, $op === 'merge');
    json_out(['doc' => $r['doc']]);
}
if ($op === 'delete') {
    $r = docs_op_delete($c, $coll, $in);
    json_out(['deleted' => $r['deleted']]);
}
$r = docs_op_query($c, $coll, $in);
json_out(['items' => $r['items'], 'next_cursor' => $r['next_cursor']]);
