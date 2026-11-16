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
 *                          · `s2s:authfail:{ip}:{service_id}` 10/900(v1.8.4 계정 push·피드의 인증 실패만 기록)
 *   - `secret_access_logs` `login:ip` · `exchange:ip` · **`refresh:ip` 120/60 +
 *                          `refresh:user` 60/3600**(V14b-H-1 이 추가). `source=session`
 *                          은 같은 session_id 로 접히므로 세션당 시간당 1행이다
 *   - `admin_logs`         관리자 자격증명이 필요하고 한 행이 실제 조작 하나에 대응한다
 *   - `point_logs`         차감액이 잔액을 줄이므로 잔액이 상한이다
 * 보존 정책(오래된 로그의 아카이브·삭제)은 D-156(6) 이 v1.9.0 으로 배정했다.
 *
 * ── v1.9.0 플랫폼 표 (설계 116 §5) ─────────────────────────────────────
 *   - `plat_logs`   **정리한다.** received_at < 지금 − `plat.log_retention_days`(기본 30) 를 2,000행씩.
 *                   수집 경로(api/platform/*)는 이 GC 를 건너뛴다(bootstrap, ULOGIN_PLATFORM_PATH)
 *   - `plat_audit`  **여기서 직접 지우지 않는다.** audit_prune() 만 지운다 — 지우기 전에 체인에
 *                   audit.prune 앵커를 쓰고 500행씩. 기준은 `plat.audit_retention_days`(기본 400)
 *   - `plat_usage` · `rc_*` · `plat_docs` · `plat_collections` 는 정리 대상이 아니다(데이터 자체다)
 *   위 네 로그 표는 여전히 정리하지 않는다(D-156(6) 의 나머지는 이 판 범위 밖).
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
        // v1.8.0 — `DELETE ... LIMIT` 는 SQLite 에 문법이 없다(설계 A11 · C-5, 실측
        // `near "LIMIT": syntax error`). 문장을 여기서 조립하지 않고 db_delete_limited()
        // 한 곳이 드라이버별로 만든다 — MySQL 은 종전 그대로 `DELETE ... LIMIT n`,
        // SQLite 는 `rowid IN (SELECT rowid ... LIMIT n)`. 호출자에 방언을 흩지 않는다.
        db_delete_limited(T_AUTH_CODES,   'expires_at < ?', [$now], 1000);
        db_delete_limited(T_RATE_LIMITS,  'expires_at < ?', [$now], 1000);
        db_delete_limited(T_PW_RESETS,    'expires_at < ?', [$now], 1000);
        db_delete_limited(T_EMAIL_VERIFY, 'expires_at < ?', [$now], 1000);

        // refresh 까지 만료된 세션만 지운다. refresh 가 살아 있으면 회전 대상이므로 남긴다.
        db_delete_limited(
            T_SESSIONS,
            '(refresh_expires_at IS NOT NULL AND refresh_expires_at < ?)'
            . ' OR (refresh_expires_at IS NULL AND expires_at < ?)',
            [$now, $now],
            1000
        );
    } catch (Throwable $e) {
        error_log('[ulogin] gc_expired failed: ' . $e->getMessage());
    }

    // v1.9.0 플랫폼 로그 보존 · 감사 보존 — 별도 try. 0007 미적용 설치본에서 표가 없어도 위 정리는 끝났다.
    try {
        if (defined('T_PLAT_LOGS') && db_table_exists(T_PLAT_LOGS)) {
            plat_gc();
        }
    } catch (Throwable $e) {
        error_log('[ulogin] gc platform failed: ' . $e->getMessage());
    }

    // v1.10.0 접근 기간 만료 — 입장을 잃은 계정의 세션 회수(설계 127 §2). 0010 미적용이면 함수가 0 을 낸다.
    try {
        access_gc_expired();
    } catch (Throwable $e) {
        error_log('[ulogin] gc access expiry failed: ' . $e->getMessage());
    }

    // v1.8.4 계정 연결 작업 — 별도 try. 0005 미적용 설치본에서 표가 없어도 위 정리는 이미 끝났다.
    // done 항목은 지우지 않는다(시스템 사건 피드의 원장). 규칙은 core/accountlink.php 의 al_gc() 한 곳.
    try {
        if (!function_exists('al_gc')) {
            require_once __DIR__ . '/accountlink.php';
        }
        al_gc();
    } catch (Throwable $e) {
        error_log('[ulogin] gc account link failed: ' . $e->getMessage());
    }
}

/** 플랫폼 보존 만료. 관리자 「지금 정리」(platform_logs.php purge_now)도 같은 규칙의 로그 쪽을 쓴다. */
function plat_gc(): void
{
    $days = (int) setting_get('plat.log_retention_days', (int) PLAT_LOG_RETENTION_DAYS);
    $days = max(1, min(365, $days));
    db_delete_limited(T_PLAT_LOGS, 'received_at < ?', [gmdate('Y-m-d H:i:s', time() - $days * 86400)], 2000);
    $adays = (int) setting_get('plat.audit_retention_days', (int) PLAT_AUDIT_RETENTION_DAYS);
    audit_prune(max(90, min(3650, $adays)));
    // 하루 1회 머리 사본 전체 대조(health 는 증분만 본다). 실패면 실패 표식 → health audit_chain_ok=false(24시간)
    if (setting_get('audit.full_check_day', null) !== gmdate('Y-m-d')) {
        setting_set('audit.full_check_day', gmdate('Y-m-d'), 'string');
        if (!audit_tail_check(true)['ok']) {
            error_log('[ulogin] daily audit head full check failed');
            audit_mark_failure();
        }
    }
}
