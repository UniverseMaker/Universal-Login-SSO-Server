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
    // X-Powered-By 제거 — D-154(2). 이 경로는 bootstrap.php 를 거치지 않는다.
    header_remove('X-Powered-By');
    http_response_code(404);
    exit;
}
/**
 * 로그인 시도 기록. 성공·실패 모두 남긴다.
 *
 * @param string   $result  success|fail_credential|fail_locked|fail_status|fail_ratelimit
 *                          |fail_service_blocked|fail_client_auth|fail_migrate_auth
 *                          |fail_refresh_reuse|fail_refresh_revoked|logout
 *                          (fail_ratelimit 은 v1.4.0 부터 실제로 기록된다 — D-153.
 *                           그전까지 4곳에서 참조되고 0곳에서 기록돼 관리자 콘솔의
 *                           "실패·레이트리밋" 필터가 항상 0건이었고, 그것이
 *                           "공격이 없었다" 로 읽혔다.
 *                           fail_client_auth 는 v1.4.0 신규 — exchange 의 service_secret
 *                           오인증. 종전에는 어느 로그에도 남지 않았다.
 *                           fail_migrate_auth 는 server/migrate.php 의 superadmin 인증
 *                           실패다. 그 파일은 core/ 를 로드하지 않으므로 이 함수를 쓰지
 *                           못하고 INSERT 를 직접 쓴다 — 컬럼 구성이 바뀌면 두 곳이다.
 *                           fail_refresh_reuse 는 회수된 refresh 토큰의 재사용 =
 *                           **이 시스템의 유일한 능동 침해 탐지**다. v1.4.0 후속까지
 *                           error_log 한 줄뿐이라 관리자 콘솔에서 볼 수 없었다 — D-164.
 *                           **이 값은 회수 사유가 refresh_rotated / refresh_reuse 일
 *                           때만 쓴다.** 그 둘이 진짜 신호다.
 *                           fail_refresh_revoked 는 그 밖의 사유로 이미 회수된 세션의
 *                           refresh 가 온 경우다 — 로그아웃 · 마이페이지 세션정리 ·
 *                           비밀번호 변경 · 관리자 회수 **네 경로**가 여기 온다.
 *                           둘을 한 값으로 쓰면 정상 사용이 훨씬 잦으므로
 *                           fail_refresh_reuse 가 대부분 오탐이 되고, 그 값은
 *                           사용자 본인 이력에도 보인다(보안 재검증 A-6 오탐).
 *                           **강조 표시는 fail_refresh_reuse 에만 붙여야 한다.**)
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

/**
 * 시크릿 전달 감사 — 리더 판정 D-153 H-2 (보안 조사 A-3).
 * core/payload.php 의 build_payload() 만 호출한다.
 *
 * ── 왜 요청마다 INSERT 하지 않는가 ───────────────────────────────────
 * 이 경로는 이 시스템에서 가장 자주 불리는 곳이다. 로컬 앱은 `GET /api/auth/session`
 * 을 갱신 타이머로 반복 호출하고, 전역 상한이 `api:ip` 300/60 이므로 **정상 트래픽만으로도**
 * 분당 수백 건이 될 수 있다. 단순 INSERT 면
 *   - 하루 28만 행(분당 200회 기준) → 관리자 로그 화면이 이미 느리다는 지적이 있는데 더 느려진다
 *   - 그리고 로그가 커질수록 **신호가 묻힌다** — 28만 행을 사람이 읽지 않는다
 * 그래서 (session_id, source, 1시간 버킷) 으로 묶어 `hits` 를 올린다.
 *
 * 쓰기 비용은 요청당 UPSERT 1회다 — 같은 요청이 이미 하는
 * `sessions.last_seen_at` UPDATE · `user_services.last_used_at` UPDATE ·
 * 레이트리밋 2쿼리와 같은 자릿수이고, UNIQUE 키로 바로 찾아간다.
 *
 * ── 버킷이 묶지 **못하는** 것 — 보안 재검증 V14b-H-1 ──────────────────
 * UNIQUE 키가 `(session_id, source, window_start)` (`schema.sql` 의
 * `uq_secret_access_bucket`)이고 `rotate_refresh()` 는 **회전마다 새 session_id** 를
 * 만든다. 그래서 `source=session` 처럼 같은 세션이 반복 호출하는 경로는 설계대로
 * 한 행에 접히지만, `source=refresh` 는 **두 요청이 같은 버킷을 쓰는 일이 없다.**
 * 실측(같은 세션 GET session 20회 → 2행 / refresh 10회 → 10행 · refresh 150회 → 151행).
 *
 * 이 파일이 그 문제를 스스로 고칠 수는 없다 — 회전에 걸쳐 살아남는 계보 키
 * (`sessions.root_session_id`)가 필요하고 그것은 마이그레이션 대상이다(V14-M-3,
 * v1.5.0). 그래서 증가율은 **회전 자체의 상한**으로 묶었다:
 * `api/auth/refresh.php` 의 `refresh:ip` 와 `core/auth.php` `rotate_refresh()` 의
 * `refresh:user`. 사용자당 하루 1,440행이 상한이다.
 *
 * **이 표는 `gc_expired()` 대상이 아니다**(`core/gc.php` 참고). 보존 정책은
 * D-156(6) 이 v1.9.0 으로 배정했다. 상한이 레이트리밋뿐이라는 사실을 여기 적어 둔다 —
 * 다음에 이 표에 쓰는 경로를 더할 사람이 그 경로의 상한을 먼저 확인해야 한다.
 *
 * ── 잃는 것 ──────────────────────────────────────────────────────────
 * 개별 요청의 정확한 시각은 남지 않는다(first_at / last_at 만 남는다).
 * 대신 IP 는 first_ip / last_ip 를 따로 둬서 **같은 세션이 여러 IP 에서 쓰였다**는
 * 탈취 신호는 놓치지 않는다.
 *
 * ── 담지 않는 것 ─────────────────────────────────────────────────────
 * **시크릿 값은 어떤 형태로도 담지 않는다.** secret_keys 에는 키의 *이름*만 넣는다.
 * 그 이름조차 어떤 키가 존재하는지의 정보이므로 관리자 조회로만 노출한다.
 *
 * @param array|null $session sessions 행. session_id / user_id 를 읽는다
 * @param string[]   $keys    전달된 시크릿 키 이름
 */
function log_secret_access(string $serviceId, ?array $session, string $source, array $keys): void
{
    if ($session === null || empty($session['session_id']) || empty($session['user_id'])) {
        return;   // 문맥이 없으면 남길 것이 없다. 요청을 막지는 않는다
    }
    // 끄고 싶은 배포가 있을 수 있다. 값의 정본은 DB settings 이고, 행이 없을 때의
    // 폴백은 **SECRET_ACCESS_LOG 상수 하나**다 — core/bootstrap.php 가 구버전
    // config.php 를 위해 defined() 가드로 채운다. 여기 true 를 하드코딩하면
    // api/admin/settings.php 의 폴백과 갈라져 **화면이 '꺼짐' 인데 실제로는 기록**되는
    // 상태가 만들어진다(그 가드 블록의 주석 참고). 폴백 정의는 한 곳이어야 한다.
    if (!setting_get('secret_access_log', (bool) SECRET_ACCESS_LOG)) {
        return;
    }
    try {
        $now    = now_utc();
        $window = gmdate('Y-m-d H:00:00');   // 1시간 버킷
        $ip     = client_ip();
        sort($keys);
        $keyList = mb_substr(implode(',', $keys), 0, 255);
        db_exec(
            'INSERT INTO ' . T_SECRET_ACCESS
            . ' (user_id, service_id, session_id, source, window_start, hits, secret_keys,'
            . '  first_ip, last_ip, first_at, last_at)'
            . ' VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE hits = hits + 1, last_ip = VALUES(last_ip),'
            . ' last_at = VALUES(last_at), secret_keys = VALUES(secret_keys)',
            [
                (int) $session['user_id'], $serviceId, (string) $session['session_id'],
                substr($source, 0, 20), $window, $keyList, $ip, $ip, $now, $now,
            ]
        );
    } catch (Throwable $e) {
        // 감사 기록 실패가 시크릿 전달을 막지는 않는다. 다만 반드시 서버 로그에 남긴다 —
        // 조용히 사라지면 "기록이 없다" 를 "접근이 없었다" 로 읽게 된다.
        error_log('[ulogin] log_secret_access failed: ' . $e->getMessage());
    }
}

/** 서버 로그. 응답에는 절대 나가지 않는다. */
function log_error(string $message): void
{
    error_log('[ulogin] ' . $message);
}
