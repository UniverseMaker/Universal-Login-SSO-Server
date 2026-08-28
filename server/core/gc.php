<?php
declare(strict_types=1);

/**
 * core/gc.php — 만료 데이터 확률적 정리
 * 담당: 스펙 §8-6(확률적 GC), schema.sql 하단 주석
 *
 * cron 이 없으므로 bootstrap 에서 요청당 1/100 확률로 호출한다.
 * 로그 테이블(login_logs / point_logs / admin_logs)은 정리하지 않는다 — 감사 근거다.
 * GC 실패가 본 요청을 죽이면 안 된다.
 */

// 직접 실행 차단 — 리더 판정 D-34. bootstrap.php 를 거치지 않은 요청은 존재하지 않는 파일이다.
if (!defined('ULOGIN_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}
function gc_expired(): void
{
    $now = now_utc();
    try {
        db_exec('DELETE FROM ' . T_AUTH_CODES . ' WHERE expires_at < ? LIMIT 1000', [$now]);
        db_exec('DELETE FROM ' . T_RATE_LIMITS . ' WHERE expires_at < ? LIMIT 1000', [$now]);
        db_exec('DELETE FROM ' . T_PW_RESETS . ' WHERE expires_at < ? LIMIT 1000', [$now]);
        db_exec('DELETE FROM ' . T_EMAIL_VERIFY . ' WHERE expires_at < ? LIMIT 1000', [$now]);

        // refresh 까지 만료된 세션만 지운다. refresh 가 살아 있으면 회전 대상이므로 남긴다.
        db_exec(
            'DELETE FROM ' . T_SESSIONS
            . ' WHERE (refresh_expires_at IS NOT NULL AND refresh_expires_at < ?)'
            . '    OR (refresh_expires_at IS NULL AND expires_at < ?)'
            . ' LIMIT 1000',
            [$now, $now]
        );
    } catch (Throwable $e) {
        error_log('[ulogin] gc_expired failed: ' . $e->getMessage());
    }
}
