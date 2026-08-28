<?php
declare(strict_types=1);

/**
 * core/logger.php — login_logs / point_logs / admin_logs 기록
 * 담당 계약: 스펙 §7-1(감사 로그 규칙), §8, 계약 §6-7
 *
 * 로그 기록 실패가 본 요청을 죽이면 안 된다. 전부 try/catch 로 감싸고
 * 실패는 error_log() 로만 남긴다.
 */


// 직접 실행 차단 — 리더 판정 D-34. bootstrap.php 를 거치지 않은 요청은 존재하지 않는 파일이다.
if (!defined('ULOGIN_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}
/**
 * 로그인 시도 기록. 성공·실패 모두 남긴다.
 *
 * @param string   $result  success|fail_credential|fail_locked|fail_status|fail_ratelimit|fail_service_blocked|logout
 * @param int|null $userId  존재하지 않는 계정 시도면 null
 */
function log_login(string $result, ?int $userId, string $usernameInput = '', ?string $serviceId = null): void
{
    try {
        db_insert(T_LOGIN_LOGS, [
            'user_id'        => $userId,
            'username_input' => mb_substr($usernameInput, 0, 190),
            'service_id'     => $serviceId,
            'result'         => substr($result, 0, 30),
            'ip'             => client_ip(),
            'user_agent'     => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'created_at'     => now_utc(),
        ]);
    } catch (Throwable $e) {
        error_log('[ulogin] log_login failed: ' . $e->getMessage());
    }
}

/**
 * 포인트 원장 기록. **반드시 잔액을 잠근 트랜잭션 안에서** 호출한다.
 * balance_after 를 남기지 않으면 사후에 원장과 잔액이 어긋났는지 검증할 수단이 없다.
 *
 * @param array $d delta, balance_after, reason 필수 / service_id, ref_type, ref_id,
 *                 idempotency_key, memo, admin_id 선택
 */
function log_point(int $userId, array $d): int
{
    return db_insert(T_POINT_LOGS, [
        'user_id'         => $userId,
        'service_id'      => $d['service_id'] ?? null,
        'delta'           => (int) $d['delta'],
        'balance_after'   => (int) $d['balance_after'],
        'reason'          => substr((string) $d['reason'], 0, 100),
        'ref_type'        => isset($d['ref_type']) ? substr((string) $d['ref_type'], 0, 40) : null,
        'ref_id'          => isset($d['ref_id']) ? substr((string) $d['ref_id'], 0, 64) : null,
        'idempotency_key' => isset($d['idempotency_key']) ? substr((string) $d['idempotency_key'], 0, 100) : null,
        'memo'            => isset($d['memo']) ? mb_substr((string) $d['memo'], 0, 255) : null,
        'admin_id'        => isset($d['admin_id']) ? (int) $d['admin_id'] : null,
        'created_at'      => now_utc(),
    ]);
}

/**
 * 관리자 조작 감사 로그. 상태 변경은 예외 없이 남긴다(조회는 남기지 않는다.
 * 단 secret.reveal 은 예외적으로 남긴다).
 *
 * before/after 에는 **변경된 필드만** 담는다. 전체 행 덤프는 password_hash·memo 를
 * 로그로 새게 만들고 무엇이 바뀌었는지 읽기도 어렵다.
 * 시크릿 값은 어떤 경우에도 담지 않는다 — "(changed)" 같은 마커만 남긴다.
 */
function log_admin(int $adminId, string $action, string $targetType, ?string $targetId,
                   ?array $before = null, ?array $after = null, ?string $memo = null): void
{
    try {
        db_insert(T_ADMIN_LOGS, [
            'admin_id'    => $adminId,
            'action'      => substr($action, 0, 60),
            'target_type' => substr($targetType, 0, 40),
            'target_id'   => $targetId !== null ? substr($targetId, 0, 64) : null,
            'before_json' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'after_json'  => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'memo'        => $memo !== null ? mb_substr($memo, 0, 255) : null,
            'ip'          => client_ip(),
            'created_at'  => now_utc(),
        ]);
    } catch (Throwable $e) {
        error_log('[ulogin] log_admin failed: ' . $e->getMessage());
    }
}

/** 서버 로그. 응답에는 절대 나가지 않는다. */
function log_error(string $message): void
{
    error_log('[ulogin] ' . $message);
}
