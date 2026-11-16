<?php
declare(strict_types=1);
/**
 * POST /server/api/sso/accounts_push.php — 시스템이 자기 로컬 계정 목록을 보낸다 (v1.8.4)
 * 담당 계약: 설계 92 S-1 · S-2 (다른 시스템이 이 계약대로 이미 운영 중 — 응답 shape 을 바꾸지 마라)
 * 응답 최상위 키: sync_id, page, accepted, created, updated, unchanged, duplicate, sync_requested
 *
 * **서버 대 서버 전용.** 인증은 본문 service_id + service_secret(exchange 와 같은 방식).
 * 브라우저 호출을 전제하지 않는다. secret 은 로그·응답 어디에도 남지 않는다.
 *
 * 순서: 크기(헤더 힌트) → 본문 → 크기(실측) → 인증 → 분당 60회 → 검증 → 저장.
 * 인증을 검증보다 먼저 하는 이유: 인증 없이 200개 계정 파싱 비용을 쓰게 하지 않는다.
 * 같은 검증·저장 함수를 관리자 「파일 가져오기」(admin/account_link_ops.php action=import)가 쓴다(H-4).
 */
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/accountlink.php';

only_method('POST');

$clen = isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] !== '' ? (int) $_SERVER['CONTENT_LENGTH'] : -1;
if ($clen > AL_PUSH_MAX_BYTES) {
    throw new AppError('VALIDATION_FAILED', 400, ['field' => 'body',
        'message' => '본문이 너무 큽니다. ' . AL_PUSH_MAX_BYTES . ' 바이트 이내로 페이지를 나누세요.']);
}
$in = body_json();
// 실제 상한 — Content-Length 없는 chunked 요청도 같은 상한을 받는다(D-204 와 같은 이유).
$rawLen = body_raw_bytes();
if ($rawLen === null) {
    $rawLen = strlen((string) json_encode($in, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
if ($rawLen > AL_PUSH_MAX_BYTES) {
    throw new AppError('VALIDATION_FAILED', 400, ['field' => 'body',
        'message' => '본문이 너무 큽니다. ' . AL_PUSH_MAX_BYTES . ' 바이트 이내로 페이지를 나누세요.']);
}

$svc = al_s2s_auth($in);
rate_limit('s2s:push:' . (string) $svc['service_id'], 60, 60);

$p = al_validate_payload($in, false);
$r = al_ingest($svc, $p, null);

json_out([
    'sync_id'        => $r['sync_id'],
    'page'           => $r['page'],
    'accepted'       => $r['accepted'],
    'created'        => $r['created'],
    'updated'        => $r['updated'],
    'unchanged'      => $r['unchanged'],
    'duplicate'      => $r['duplicate'],
    'sync_requested' => $r['sync_requested'],
]);
