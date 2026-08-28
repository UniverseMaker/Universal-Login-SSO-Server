<?php
declare(strict_types=1);

/**
 * core/ratelimit.php — 고정 윈도우 레이트리밋 + 로그인 락아웃
 * 담당 계약: §2 RATE_LIMITED / AUTH_ACCOUNT_LOCKED, 스펙 §8-2, §8-3
 *
 * Redis 가 없으므로 MySQL 의 INSERT ... ON DUPLICATE KEY UPDATE 로 원자 증가시킨다.
 * SELECT 후 UPDATE 는 동시 요청에서 카운트를 놓친다.
 */


// 직접 실행 차단 — 리더 판정 D-34. bootstrap.php 를 거치지 않은 요청은 존재하지 않는 파일이다.
if (!defined('ULOGIN_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}
/**
 * 버킷 카운트를 1 올리고 한도를 넘으면 429 로 종료한다.
 *
 * @param string $bucket    예: 'login:ip:1.2.3.4'
 * @param int    $max       윈도우당 허용 횟수
 * @param int    $windowSec 윈도우 길이(초)
 */
function rate_limit(string $bucket, int $max, int $windowSec): void
{
    if ($windowSec <= 0) {
        return;
    }
    $bucket    = substr($bucket, 0, 150);
    $winStart  = intdiv(time(), $windowSec) * $windowSec;
    $start     = gmdate('Y-m-d H:i:s', $winStart);
    $expires   = gmdate('Y-m-d H:i:s', $winStart + $windowSec * 2);

    try {
        db_exec(
            'INSERT INTO ' . T_RATE_LIMITS . ' (bucket, window_start, count, expires_at) VALUES (?, ?, 1, ?)'
            . ' ON DUPLICATE KEY UPDATE count = count + 1',
            [$bucket, $start, $expires]
        );
        $c = (int) db_val('SELECT count FROM ' . T_RATE_LIMITS . ' WHERE bucket = ? AND window_start = ?',
            [$bucket, $start]);
    } catch (Throwable $e) {
        // 레이트리밋 저장소 장애로 서비스 전체를 세우지는 않는다. 대신 반드시 로그를 남긴다.
        error_log('[ulogin] rate_limit storage failure: ' . $e->getMessage());
        return;
    }

    if ($c > $max) {
        $retry = $winStart + $windowSec - time();
        throw new AppError('RATE_LIMITED', 429, ['retry_after' => max(1, $retry)]);
    }
}

/**
 * 로그인 락아웃 확인. 스펙 §8-2.
 * 잠겨 있으면 AUTH_ACCOUNT_LOCKED(423) + data.locked_until / retry_after.
 * 401·403 과 구분해야 프론트가 "잠시 후 다시" 안내를 정확히 띄운다.
 */
function assert_not_locked(array $user): void
{
    $until = ts($user['locked_until'] ?? null);
    if ($until === null || $until <= time()) {
        return;
    }
    throw new AppError('AUTH_ACCOUNT_LOCKED', 423, [
        'locked_until' => iso((string) $user['locked_until']),
        'retry_after'  => max(1, $until - time()),
    ]);
}

/** 로그인 실패 1회 반영. 임계치에 도달하면 잠근다. */
function register_login_failure(array $user): void
{
    $threshold = (int) setting_get('login_fail_threshold', LOCKOUT_THRESHOLD);
    $minutes   = (int) setting_get('login_lock_minutes', LOCKOUT_MINUTES);
    if ($threshold < 1) {
        $threshold = (int) LOCKOUT_THRESHOLD;
    }
    if ($minutes < 1) {
        $minutes = (int) LOCKOUT_MINUTES;
    }

    $count = (int) ($user['failed_login_count'] ?? 0) + 1;
    $data  = ['failed_login_count' => $count, 'updated_at' => now_utc()];
    if ($count >= $threshold) {
        // 2배 임계치를 넘기면 잠금 시간을 2배로 — 반복 무차별 대입의 비용을 올린다.
        $mult = ($count >= $threshold * 2) ? 2 : 1;
        $data['locked_until'] = at_utc($minutes * 60 * $mult);
    }
    db_update(T_USERS, $data, 'id = ?', [$user['id']]);
}

/** 로그인 성공 시 실패 카운터 초기화. */
function clear_login_failures(int $userId): void
{
    db_exec('UPDATE ' . T_USERS . ' SET failed_login_count = 0, locked_until = NULL WHERE id = ?', [$userId]);
}
