<?php
declare(strict_types=1);
/**
 * POST /api/auth/register_request_status — 가입 신청 결과 조회 (v1.10.1 신규 · 인증 불필요)
 * 담당 계약: 설계 130 §2-4
 * 요청: {"receipt": "<64 hex>"}  — 접수번호는 URL 에 싣지 않으려고 본문으로만 받는다
 * 응답 최상위 키: status, created_at, decided_at, notice
 *   없는 접수번호 404 NOT_FOUND · 형식 오류 400 VALIDATION_FAILED · 빈도 regstat:ip 30/300
 * 내부 사유(decision_reason)·계정 정보는 내보내지 않는다. notice 는 관리자가 신청자에게 쓴 안내다.
 */
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/regreq.php';

only_method('POST');
rate_limit('regstat:ip:' . client_ip(), 30, 300);

$in = body_json();
$receipt = in_raw($in, 'receipt');
if (!is_string($receipt) || preg_match('/^[0-9a-f]{64}$/', strtolower(trim($receipt))) !== 1) {
    throw new AppError('VALIDATION_FAILED', 400, ['field' => 'receipt', 'message' => '접수번호 형식이 올바르지 않습니다.']);
}
$st = db_table_exists(T_REGISTER_REQUESTS) ? regreq_status_by_receipt(strtolower(trim($receipt))) : null;
if ($st === null) {
    throw new AppError('NOT_FOUND', 404, ['field' => 'receipt']);
}
json_out([
    'status'     => $st['status'],
    'created_at' => $st['created_at'],
    'decided_at' => $st['decided_at'],
    'notice'     => $st['notice'],
]);
