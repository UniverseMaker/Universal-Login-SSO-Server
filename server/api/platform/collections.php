<?php
declare(strict_types=1);
/**
 * POST /server/api/platform/collections.php — 컬렉션 목록·정의 (s2s 전용) (v1.9.0)
 * 담당 계약: 설계 116 §6
 * 본문 op = list|upsert
 * 응답 최상위 키: list → items · upsert → collection
 * 색인이 바뀌면 reindex_pending:true (관리자 reindex 가 500건씩 채운다). **삭제는 관리자 콘솔만.**
 */
define('ULOGIN_PLATFORM_PATH', 1);
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/platauth.php';
require_once __DIR__ . '/../../core/docs.php';

only_method('POST');
$in = plat_body(65536);
$c  = plat_caller($in, ['s2s' => true, 'user' => false, 'anon' => false]);
$sid = (string) $c['service']['service_id'];
rate_limit('plat:colls:svc:' . $sid, 120, 60);

$op = $in['op'] ?? null;
if ($op === 'list') {
    $items = [];
    foreach (db_all('SELECT * FROM ' . T_PLAT_COLLS . ' WHERE service_id = ? AND status = ? ORDER BY name ASC', [$sid, 'active']) as $r) {
        $items[] = docs_coll_out($r);
    }
    json_out(['items' => $items]);
}
if ($op !== 'upsert') {
    throw new AppError('VALIDATION_FAILED', 400, ['field' => 'op', 'message' => 'op 는 list|upsert 입니다.']);
}
$coll = docs_coll_upsert($sid, $in);
json_out(['collection' => docs_coll_out($coll)]);
