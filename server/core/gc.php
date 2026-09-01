<?php
declare(strict_types=1);

/**
 * core/gc.php — 만료 데이터 확률적 정리
 * 담당: 스펙 §8-6(확률적 GC), schema.sql 하단 주석
 *
 * cron 이 없으므로 bootstrap 에서 요청당 1/100 확률로 호출한다.
 * GC 실패가 본 요청을 죽이면 안 된다.
 *
 * ── 정리하지 않는 표 — 감사 근거다 ───────────────────────────────────
 * `login_logs` · `point_logs` · `admin_logs` · **`secret_access_logs`** 네 개다.
 * v1.4.0 이 네 번째를 만들었고 이 문장은 그 사실을 반영하지 못한 채였다
 * (보안 재검증 V14b-H-1). 넷 다 무기한 누적되므로 **각 표에 쓰는 경로에
 * 레이트리밋이 있는지가 유일한 상한**이다. 새 경로를 더할 때 그것을 먼저 확인하라.
 *   - `login_logs`         `login:ip` 10/300 · `login:user` 5/300 · `exchange:ip` 30/300
 *   - `secret_access_logs` `login:ip` · `exchange:ip` · **`refresh:ip` 120/60 +
 *                          `refresh:user` 60/3600**(V14b-H-1 이 추가). `source=session`
 *                          은 같은 session_id 로 접히므로 세션당 시간당 1행이다
 *   - `admin_logs`         관리자 자격증명이 필요하고 한 행이 실제 조작 하나에 대응한다
 *   - `point_logs`         차감액이 잔액을 줄이므로 잔액이 상한이다
 * 보존 정책(오래된 로그의 아카이브·삭제)은 D-156(6) 이 v1.9.0 으로 배정했다.
 */

// 직접 실행 차단 — 리더 판정 D-34. bootstrap.php 를 거치지 않은 요청은 존재하지 않는 파일이다.
if (!defined('ULOGIN_BOOTSTRAPPED')) {
    // X-Powered-By 제거 — D-154(2). 이 경로는 bootstrap.php 를 거치지 않는다.
    header_remove('X-Powered-By');
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
