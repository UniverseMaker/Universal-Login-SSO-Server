<?php
declare(strict_types=1);

/**
 * core/ratelimit.php — 고정 윈도우 레이트리밋 + 로그인 락아웃
 * 담당 계약: §2 RATE_LIMITED / AUTH_ACCOUNT_LOCKED, 스펙 §8-2, §8-3
 *
 * Redis 가 없으므로 DB 의 UPSERT 한 문장으로 원자 증가시킨다.
 * SELECT 후 UPDATE 는 동시 요청에서 카운트를 놓친다.
 *
 * v1.8.0 — UPSERT 문법이 저장소마다 다르다(설계 A2·B-4). MySQL 은
 * `ON DUPLICATE KEY UPDATE`, SQLite 는 `ON CONFLICT(cols) DO UPDATE` 이고 **충돌 컬럼을
 * 명시해야 한다.** 문장은 `db_upsert_increment()` 한 곳이 만든다 — 여기서 조립하지 않는다.
 * 실측(설계 부록 6·7): UPSERT 16프로세스 × 50회 = 800/800/800, 읽기-수정-쓰기 변이는 66·62·103.
 */


// 직접 실행 차단 — 리더 판정 D-34. bootstrap.php 를 거치지 않은 요청은 존재하지 않는 파일이다.
if (!defined('ULOGIN_BOOTSTRAPPED')) {
    // X-Powered-By 제거 — D-154(2). 이 경로는 bootstrap.php 를 거치지 않는다.
    header_remove('X-Powered-By');
    http_response_code(404);
    exit;
}
/**
 * 버킷 카운트를 1 올리고 한도를 넘으면 429 로 종료한다.
 *
 * @param string $bucket    예: 'login:ip:1.2.3.4'
 * @param int    $max       윈도우당 허용 횟수
 * @param int    $windowSec 윈도우 길이(초)
 *
 * ── 카운트 출력 인자를 두지 않는다 — 보안 재검증 V14b-M-1 ─────────────
 * v1.4.0 후속의 첫 수정은 여기에 `?int &$countOut` 을 달고 rate_limit_logged() 가
 * `count === max + 1` 로 "이 윈도우의 첫 429" 를 판정하게 했다. 그 판정이 깨진다.
 * INSERT ... ON DUPLICATE KEY UPDATE 는 원자적이지만 **뒤이은 SELECT 는 별도 문장**
 * 이므로, 동시에 도착한 요청들이 count 를 각각 올린 뒤 **전부 같은(더 큰) 값을 읽는다.**
 * `max + 1` 을 읽는 요청이 아무도 없으면 그 윈도우의 fail_ratelimit 행이 0 이 된다.
 * 실측(4워커, 경계 요청을 K개 겹침): K=2 에서 6/20, K=4 에서 10/20 이 신호 소실이고
 * 같은 하네스로 v1.3.0 형태(429 마다 기록)의 대조군은 0/20 이었다.
 * 그래서 인자를 되돌렸다 — **남겨 두면 같은 등호 판정이 다시 들어온다.**
 * 첫 429 는 rate_limit_logged() 가 UNIQUE 키로 원자적으로 주장한다.
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
        // 원자 증가 — (bucket, window_start) 는 uq_rate_limits 다. 이 두 컬럼이 충돌 대상이다.
        db_upsert_increment(T_RATE_LIMITS, ['bucket' => $bucket, 'window_start' => $start],
            'count', ['expires_at' => $expires]);
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
 * 세지 않고 **본다** — 이 윈도우의 카운트가 이미 $max 이상이면 429. 실패만 세는 버킷의 사전 검사용(v1.9.2).
 * 저장소 장애는 rate_limit() 과 같이 로그만 남기고 통과시킨다.
 */
function rate_limit_peek(string $bucket, int $max, int $windowSec): void
{
    if ($windowSec <= 0) {
        return;
    }
    $winStart = intdiv(time(), $windowSec) * $windowSec;
    try {
        $c = (int) db_val('SELECT count FROM ' . T_RATE_LIMITS . ' WHERE bucket = ? AND window_start = ?',
            [substr($bucket, 0, 150), gmdate('Y-m-d H:i:s', $winStart)]);
    } catch (Throwable $e) {
        error_log('[ulogin] rate_limit_peek storage failure: ' . $e->getMessage());
        return;
    }
    if ($c >= $max) {
        throw new AppError('RATE_LIMITED', 429, ['retry_after' => max(1, $winStart + $windowSec - time())]);
    }
}

/**
 * rate_limit() 과 같지만, 한도에 걸리면 `login_logs` 에 `fail_ratelimit` 을 남긴다.
 * 리더 판정 D-153 (보안 조사 A-7).
 *
 * **왜 rate_limit() 자체에 넣지 않는가:** 이 함수는 bootstrap.php 의 전역
 * `api:ip` 300/60 에도 쓰인다. 거기까지 login_logs 에 남기면 로그인과 무관한
 * 429 가 로그인 이력을 채워 신호가 묻힌다. 그래서 **자격증명·SSO 경로만**
 * 이 래퍼를 쓴다 — login / sso/authorize / auth/exchange.
 *
 * v1.3.0 까지 `fail_ratelimit` 은 **4곳에서 참조되고 0곳에서 기록됐다.**
 * 관리자 콘솔의 "실패 · 레이트리밋" 필터가 항상 0건을 돌려주었고,
 * 그것이 "레이트리밋에 걸린 시도가 없다" = "공격이 없었다" 로 읽혔다.
 * 무차별 대입이 심할수록 임계치 뒤의 시도가 전부 429 로 끝나 **로그에 덜 남는**
 * 구조였다 — 방향이 반대였다.
 *
 * 로그 기록은 log_login() 안에서 try/catch 된다. 기록 실패가 429 를 삼키지 않는다.
 *
 * ── 윈도우당 1행 — 보안 검증 V14-H-1 ────────────────────────────────
 * 첫 구현은 **429 마다** 1행을 남겼다. 실측: 존재하지 않는 계정으로 150회를 던지면
 * v1.3.0 은 5행(fail_credential 만), v1.4.0 은 150행(+ fail_ratelimit 145)이었다.
 * 요청 수와 로그 행수가 1:1 이면 두 가지가 동시에 일어난다.
 *   1) 저장소 고갈 — 상한은 전역 api:ip 300/60 하나뿐이고 로그 3종은 GC 대상이 아니다
 *   2) **고치려던 것의 반대** — 공격자가 자기 의지로 fail_ratelimit 행을 만들어
 *      진짜 fail_credential 행을 파묻을 수 있다
 * 그래서 **그 윈도우에서 한도를 처음 넘은 요청 한 번만** 남긴다.
 * 콘솔 필터는 여전히 의미를 갖는다 — 남는 것이 "공격이 시작된 시각" 이다.
 *
 * ── 「첫 429」를 세지 않고 **주장한다** — 보안 재검증 V14b-M-1 ─────────
 * 첫 수정은 `rate_limit()` 이 돌려준 카운트가 정확히 `max + 1` 인지 봤다. 증가(UPSERT)와
 * 읽기(SELECT)가 두 문장이라 동시 요청이 **전부 그 값을 지나쳐 읽고** 신호가 사라졌다
 * (실측 K=4 에서 10/20 소실. `exchange:ip` 에서는 그 행이 유일한 신호라 DB 에 한 행도
 * 남지 않았다). 카운트를 정확히 읽으려면 잠금이 필요하고, 로그 한 행을 위해
 * 레이트리밋 경로에 잠금을 넣는 것은 비용이 맞지 않는다.
 *
 * 그래서 세는 대신 **주장한다.** `(bucket, window_start)` 에 UNIQUE 키가 있으므로
 * (`schema.sql` 의 `uq_rate_limits`) 마커 행 INSERT 는 그 윈도우에서 **정확히 한 번만**
 * 성공한다. 성공한 요청이 기록한다 — 소실도 중복도 없고 추가 잠금도 없다.
 * 마커는 `!logged:` 접두사를 붙인 별도 버킷이라 실제 카운터와 섞이지 않고,
 * `rate_limits` 를 읽는 곳은 저장소 전체에서 이 파일과 `core/gc.php` 뿐이므로
 * (조회 API 0곳 — 확인) 관리자 화면에 나타나지 않는다. `expires_at` 을 넣어
 * `gc_expired()` 가 실제 카운터와 같이 치운다.
 *
 * ── 왜 H-2 처럼 시간 버킷 UPSERT 로 묶지 않았나 ─────────────────────
 * `secret_access_logs` 는 v1.4.0 이 새로 만든 표이고 관리자만 본다. `login_logs` 는
 * 다르다 — `GET /api/me/login-logs` 로 **사용자 본인에게도 보이는 표**이고,
 * `public/mypage.html` 의 로그인 이력 탭은 열이 「시각 · 결과 · IP · UA · 서비스」이며
 * 한 행을 `created_at` 한 시점으로 그린다(hits 열이 없다). 응답 항목도 6키 고정이다.
 * login_logs 를 버킷 UPSERT 로 바꾸면 `success` 를 포함한 **모든 행의 뜻**이 바뀌고,
 * "03:12 에 로그인이 있었나" 라는 그 화면의 유일한 질문에 답할 수 없게 된다.
 * hits 열을 응답에 더하는 것은 계약 shape 변경이라 이 라운드의 범위 밖이다.
 * 행 수는 (버킷 × 윈도우)당 1로 이미 묶이므로 UPSERT 로 얻을 것도 없다.
 */
function rate_limit_logged(string $bucket, int $max, int $windowSec,
                           ?int $userId = null, string $usernameInput = '', ?string $serviceId = null): void
{
    try {
        rate_limit($bucket, $max, $windowSec);
    } catch (AppError $e) {
        if ($e->code === 'RATE_LIMITED' && rate_limit_claim_first($bucket, $windowSec)) {
            log_login('fail_ratelimit', $userId, $usernameInput, $serviceId);
        }
        throw $e;
    }
}

/**
 * 이 요청이 「이 버킷·이 윈도우에서 429 를 처음 낸 요청」인가 — 원자적으로 주장한다.
 *
 * `uq_rate_limits (bucket, window_start)` UNIQUE 에 마커 행을 INSERT 한다.
 * 성공한 요청이 정확히 하나이므로 그 하나가 기록한다. `count` 를 읽지 않는다 —
 * 읽기는 증가와 별개 문장이라 동시성에서 값을 지나친다(V14b-M-1).
 *
 * 실패는 두 가지로 갈라 다룬다.
 *   - 중복키 = 이 윈도우에 이미 누군가 기록했다. **정상 경로**다. 판정은 `db_is_duplicate()`
 *     하나로 한다(MySQL 1062 · SQLite 19 + `UNIQUE constraint failed` 메시지). SQLite 의 19 는
 *     CHECK·NOT NULL 위반과 공용이라 errno 만 보면 저장소 결함이 「이미 기록됨」으로 읽혀
 *     **신호가 사라진다**(설계 B-3) — 그래서 직접 errno 를 보지 않는다.
 *   - 그 외의 오류 = 저장소 문제다. 삼키지 않고 서버 로그에 남긴 뒤 **기록하는 쪽으로**
 *     떨어진다 — 신호를 잃는 것이 중복 한 행보다 나쁘다.
 *
 * @return bool 기록해야 하는가
 */
function rate_limit_claim_first(string $bucket, int $windowSec): bool
{
    if ($windowSec <= 0) {
        return false;
    }
    // rate_limit() 이 자르는 것과 같은 길이로 먼저 자른 뒤 접두사를 붙인다.
    // bucket 컬럼은 VARCHAR(150) 이고 마커는 실제 카운터와 같은 키 공간을 쓰므로,
    // 접두사까지 합쳐 150 을 넘지 않게 한다.
    $marker   = substr('!logged:' . substr($bucket, 0, 150), 0, 150);
    $winStart = intdiv(time(), $windowSec) * $windowSec;
    try {
        db_exec(
            'INSERT INTO ' . T_RATE_LIMITS . ' (bucket, window_start, count, expires_at) VALUES (?, ?, 0, ?)',
            [
                $marker,
                gmdate('Y-m-d H:i:s', $winStart),
                gmdate('Y-m-d H:i:s', $winStart + $windowSec * 2),
            ]
        );
        return true;
    } catch (Throwable $e) {
        if (db_is_duplicate($e)) {
            return false;
        }
        error_log('[ulogin] rate_limit marker failed: ' . $e->getMessage());
        return true;
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

/**
 * 로그인 실패 1회 반영. 임계치에 도달하면 잠근다.
 *
 * ── 원자 증가 — 리더 판정 D-298 H-5 ──────────────────────────────────
 * v1.7.0 까지는 호출자가 **요청 시작 때 읽은** `$user['failed_login_count']` 에 1을 더해
 * 그 값을 써 넣었다(읽기-수정-쓰기). 동시 실패 요청이 겹치면 전부 같은 값을 읽고 같은
 * 값을 쓰므로 **카운트가 덜 올라가 락아웃이 늦게 걸린다** — 무차별 대입의 창이 넓어진다.
 * MySQL 에서도 같았다(저장소 문제가 아니다).
 *
 * 그래서 ① 증가는 DB 가 한다(`= failed_login_count + 1`, 한 문장이라 원자적)
 *        ② 잠금 판정은 **증가 뒤 다시 읽은 값**으로 한다.
 * ②의 읽기는 별개 문장이라 동시 요청들이 같은(더 큰) 값을 읽을 수 있다. 그래도 된다 —
 * 각 요청이 읽는 값은 **자기 증가분을 이미 포함**하므로, 임계치를 넘긴 증가를 한 요청은
 * 반드시 임계치 이상을 읽고 잠근다. 등호 판정(`=== threshold`)을 쓰지 않는 이유가 그것이다
 * (V14b-M-1 이 등호 판정으로 신호를 잃었다).
 *
 * 잠금 시각은 **늘리기만 한다**(`locked_until IS NULL OR locked_until < ?`). 동시 요청 중
 * 1배 잠금을 계산한 쪽이 2배 잠금을 나중에 덮어써 줄이는 일을 막는다. 두 저장소 모두
 * `Y-m-d H:i:s` UTC 문자열이라 문자열 비교가 시간 순서와 같다(설계 A-3).
 *
 * `$user` 에서는 id 만 쓴다. **카운트를 인자에서 읽지 마라** — 그것이 이 결함이었다.
 *
 * v1.10.0 감사 L-1 / D-372 — 비상 계정(account_kind=emergency) 처리를 **여기서도** 한다.
 *   authorize.php 는 D-234 로 불변인데 그 파일은 비상 계정을 모른다. 두 가지가 샜다:
 *   ① 미설정('!unset') 해시는 password_verify 가 bcrypt 없이 바로 false 를 돌려 응답 시간 차가 난다
 *      → 미설정이면 여기서 더미 bcrypt 를 한 번 돌린다(login.php 4-b 와 같은 규약). 실패 횟수는 세지 않는다.
 *   ② authorize 경로의 비상 계정 실패가 감사(emergency.login.failure)에 남지 않았다 → 여기서 남긴다.
 *   login.php 는 미설정을 앞에서 거르고 설정된 계정의 감사를 스스로 남기므로 $callerAudits=true 로 부른다.
 */
function register_login_failure(array $user, bool $callerAudits = false): void
{
    if ((string) ($user['account_kind'] ?? 'normal') === 'emergency') {   // EMERGENCY-FAIL-CORE
        $unset = (string) ($user['password_hash'] ?? '') === EMERGENCY_UNSET_HASH;
        if (!$callerAudits) {
            audit_emergency_event('emergency.login.failure', $user, ['reason' => $unset ? 'unset' : 'credential']);
        }
        if ($unset) {
            dummy_password_verify('emergency-unset');   // 시간 차 제거 — 값은 무엇이든 bcrypt 1회
            return;                                    // 잠금은 설정된 계정에만(login.php 4-b 와 같다)
        }
    }
    $threshold = (int) setting_get('login_fail_threshold', LOCKOUT_THRESHOLD);
    $minutes   = (int) setting_get('login_lock_minutes', LOCKOUT_MINUTES);
    if ($threshold < 1) {
        $threshold = (int) LOCKOUT_THRESHOLD;
    }
    if ($minutes < 1) {
        $minutes = (int) LOCKOUT_MINUTES;
    }
    $uid = (int) $user['id'];

    db_exec('UPDATE ' . T_USERS . ' SET failed_login_count = failed_login_count + 1, updated_at = ? WHERE id = ?',
        [now_utc(), $uid]);
    $count = (int) db_val('SELECT failed_login_count FROM ' . T_USERS . ' WHERE id = ?', [$uid]);

    if ($count >= $threshold) {
        // 2배 임계치를 넘기면 잠금 시간을 2배로 — 반복 무차별 대입의 비용을 올린다.
        $mult  = ($count >= $threshold * 2) ? 2 : 1;
        $until = at_utc($minutes * 60 * $mult);
        db_exec('UPDATE ' . T_USERS . ' SET locked_until = ?'
            . ' WHERE id = ? AND (locked_until IS NULL OR locked_until < ?)',
            [$until, $uid, $until]);
    }
}

// ---------------------------------------------------------------------
// v1.10.1 보안 L-3 (D-375) — 승인 대기 신청(users 행이 아직 없다)의 비밀번호 실패도 잠근다.
// 표를 바꾸지 않으려고 rate_limits 행 1개로 센다: bucket `login:pending:<신청 id>`, window_start 고정.
// 규칙은 계정 잠금과 같은 설정(login_fail_threshold · login_lock_minutes)을 쓴다:
//   실패할 때마다 count+1, expires_at = 지금 + 잠금 시간. count ≥ 임계이고 expires_at 이 미래면 423(검증 전에).
//   expires_at 이 지나면 그 묶음은 끝났다 — 다음 실패 때 지우고 1부터. 맞는 비밀번호(→ PENDING)면 지운다.
// ---------------------------------------------------------------------
const PENDING_FAIL_WINDOW = '2000-01-01 00:00:00';

/** [임계 횟수, 잠금 분] — register_login_failure() 와 같은 해석. */
function login_lock_policy(): array
{
    $threshold = (int) setting_get('login_fail_threshold', LOCKOUT_THRESHOLD);
    $minutes   = (int) setting_get('login_lock_minutes', LOCKOUT_MINUTES);
    if ($threshold < 1) {
        $threshold = (int) LOCKOUT_THRESHOLD;
    }
    if ($minutes < 1) {
        $minutes = (int) LOCKOUT_MINUTES;
    }
    return [$threshold, $minutes];
}

function pending_fail_bucket(int $requestId): string
{
    return 'login:pending:' . $requestId;
}

/** 잠겨 있으면 AUTH_ACCOUNT_LOCKED(423) — assert_not_locked() 와 같은 모양. */
function pending_assert_not_locked(int $requestId): void
{
    list($threshold) = login_lock_policy();
    $r = db_one('SELECT count, expires_at FROM ' . T_RATE_LIMITS . ' WHERE bucket = ? AND window_start = ?',
        [pending_fail_bucket($requestId), PENDING_FAIL_WINDOW]);
    if ($r === null) {
        return;
    }
    $until = ts($r['expires_at'] ?? null);
    if ($until === null || $until <= time() || (int) $r['count'] < $threshold) {   // PENDING-LOCK
        return;
    }
    throw new AppError('AUTH_ACCOUNT_LOCKED', 423, [
        'locked_until' => iso((string) $r['expires_at']),
        'retry_after'  => max(1, $until - time()),
    ]);
}

/** 실패 1회. 원자 증가(db_upsert_increment) — 읽기-수정-쓰기 금지(D-298 H-5 와 같은 이유). */
function pending_register_failure(int $requestId): void
{
    list(, $minutes) = login_lock_policy();
    $b = pending_fail_bucket($requestId);
    db_exec('DELETE FROM ' . T_RATE_LIMITS . ' WHERE bucket = ? AND window_start = ? AND expires_at <= ?',
        [$b, PENDING_FAIL_WINDOW, now_utc()]);
    db_upsert_increment(T_RATE_LIMITS, ['bucket' => $b, 'window_start' => PENDING_FAIL_WINDOW], 'count',
        ['expires_at' => at_utc($minutes * 60)]);
}

/** 맞는 비밀번호로 확인됐으면 실패 묶음을 지운다(계정의 clear_login_failures 와 같다). */
function pending_clear_failures(int $requestId): void
{
    db_exec('DELETE FROM ' . T_RATE_LIMITS . ' WHERE bucket = ? AND window_start = ?', [pending_fail_bucket($requestId), PENDING_FAIL_WINDOW]);
}

/** 로그인 성공 시 실패 카운터 초기화. */
function clear_login_failures(int $userId): void
{
    db_exec('UPDATE ' . T_USERS . ' SET failed_login_count = 0, locked_until = NULL WHERE id = ?', [$userId]);
}
