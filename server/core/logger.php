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
 * @param string   $result  success|success_silent|issued_silent|fail_credential|fail_locked
 *                          |fail_status|fail_ratelimit|fail_service_blocked|fail_client_auth
 *                          |fail_migrate_auth|fail_refresh_reuse|fail_refresh_revoked|logout
 *                          (issued_silent 은 v1.7.0 수정 라운드 신규 — 무음 SSO code 를
 *                           **발급한** 시점이다(api/sso/authorize_session.php).
 *                           아래 success_silent 는 그 code 가 **교환된** 시점이고
 *                           **둘은 다른 사건이다.** 1차 구현에는 교환 쪽만 있어서
 *                           「발급됐으나 교환되지 않은」 무음 인가가 **로그 0행**이었다 —
 *                           제3자가 피해자 브라우저를 무음 경로로 몰아넣고 서비스가
 *                           state 불일치로 거절하는 경우가 정확히 그것이고,
 *                           **공격 시도가 가장 잘 보여야 할 경우**였다(감사 M17-3).
 *                           이름이 success 로 시작하지 않지만 **실패가 아니다.**
 *                           경고색으로 칠하지 마라 — 중립이다.
 *                          (success_silent 은 v1.7.0 신규 — 무재인증(무음) SSO 로 발급된
 *                           code 를 exchange 가 교환했다. **비밀번호를 한 번도 묻지 않은
 *                           로그인**이다. 무음 경로에 동의 화면을 두지 않기로 한 판정
 *                           (D-235)의 조건이 「사후에 구분해 볼 수 있을 것」이었고 이 값이
 *                           그 수단이다. 이름이 `success` 로 시작하는 **정상 경로**이므로
 *                           실패·경고와 같은 색으로 칠하면 안 된다. 기록하는 곳은
 *                           api/auth/exchange.php 한 곳이고 판정 근거는 auth_codes.issued_via 다.
 *                           fail_ratelimit 은 v1.4.0 부터 실제로 기록된다 — D-153.
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
        $logId = db_insert(T_ADMIN_LOGS, [
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
        return;
    }
    // v1.9.0 (설계 116 §5) — 같은 내용을 감사 체인(service_id 'ulogin')에도 쓴다. 관리자 화면 감사 탭이
    // 전 시스템과 ulogin 을 한 저장소로 본다. event_id 는 admin_logs.id 에서 결정적으로 만든다 —
    // 소급 CLI(tools/audit_backfill.php)가 같은 행을 두 번 넣지 않게(UNIQUE 가 거절한다).
    // H-3: 실패는 삼키고 error_log + 실패 표식(health audit_chain_ok=false). 관리 조작을 막지 않는다.
    try {
        audit_log_admin_row((int) $logId, $adminId, $action, $targetType, $targetId, $before, $after, $memo);
    } catch (Throwable $e) {
        error_log('[ulogin] log_admin audit chain write failed: ' . $e->getMessage());
        audit_mark_failure();
    }
}

/** admin_logs 행 하나 → 감사 체인. log_admin() 과 소급 CLI 가 같이 쓴다. */
function audit_log_admin_row(int $logId, int $adminId, string $action, string $targetType, ?string $targetId,
                             ?array $before, ?array $after, ?string $memo, ?string $at = null, ?string $ip = null,
                             ?string $reason = null): ?array
{
    if (!defined('T_PLAT_AUDIT') || !db_table_exists(T_PLAT_AUDIT)) {
        return null;   // 0007 미적용 — 감사 체인이 아직 없다
    }
    $uuid = db_val('SELECT uuid FROM ' . T_USERS . ' WHERE id = ?', [$adminId]);
    $row  = audit_ulogin_row($adminId > 0 ? 'admin' : 'system', $uuid === null ? null : (string) $uuid, $action,
        $targetType, $targetId, audit_changes_from($before, $after), $reason !== null ? $reason : $memo);
    $h = hash('sha256', 'ulogin-admin-log:' . $logId);
    $row['event_id'] = substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-a' . substr($h, 17, 3)
        . '-' . substr($h, 20, 12);
    if ($at !== null) {
        $row['ts'] = $at;
    }
    if ($ip !== null) {
        $row['ip'] = $ip;
    }
    return audit_append([$row]);
}

// =====================================================================
// v1.9.0 — 감사 해시 체인 (설계 116 §5 · A5 · H-3 · H-4 · H-9)
//
//   row_hash = HMAC-SHA256(K, prev_hash . "\n" . canon(행)),  K = HMAC-SHA256('ulogin-audit-chain-v1', SECRET_ENC_KEY)
//   머리 `audit.chain_head` = "{seq}:{hash}" (settings 표, 관리자 화면 비노출). 같은 트랜잭션에서 sql_for_update()
//   로 읽고 갱신한다 — SQLite 는 db_tx() 의 BEGIN IMMEDIATE 가 직렬화한다.
//   DB 만 가진 공격자는 키가 없어 위조할 수 없고, config 까지 가진 공격자는 시스템 쪽 receipt 와 어긋난다.
//   키가 없으면 500(D-53 fail-closed) — 평문 sha256 으로 떨어지지 않는다.
// =====================================================================

const AUDIT_GENESIS     = '0000000000000000000000000000000000000000000000000000000000000000';
const AUDIT_HEAD_KEY    = 'audit.chain_head';
const AUDIT_FAIL_KEY    = 'audit.write_failed_at';
const AUDIT_CANON_COLS  = ['id', 'service_id', 'event_id', 'ts', 'received_at', 'actor_type', 'actor_uuid', 'actor_local',
                           'action', 'target_type', 'target_id', 'result', 'changes_json', 'ip', 'user_agent',
                           'request_id', 'reason', 'sender_ip'];
const AUDIT_VERIFY_MAX  = 5000;
const AUDIT_PRUNE_CHUNK = 500;

/** 체인 키. SECRET_ENC_KEY 가 없거나 64 hex 가 아니면 예외(→ 500). */
function audit_chain_key(): string
{
    if (!defined('SECRET_ENC_KEY') || preg_match('/^[0-9a-fA-F]{64}$/', (string) SECRET_ENC_KEY) !== 1) {
        throw new RuntimeException('audit chain key unavailable: SECRET_ENC_KEY missing or malformed');
    }
    return hash_hmac('sha256', 'ulogin-audit-chain-v1', (string) SECRET_ENC_KEY, true);
}

/** 정규 직렬화 — 키 정렬 JSON, 값은 문자열|null(드라이버마다 정수 반환 여부가 달라도 같은 바이트가 되게). */
function audit_canon(array $row): string
{
    $c = [];
    foreach (AUDIT_CANON_COLS as $k) {
        $v = $row[$k] ?? null;
        $c[$k] = $v === null ? null : (string) $v;
    }
    ksort($c);
    return (string) json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function audit_row_hash(string $prev, array $row, string $key): string
{
    return hash_hmac('sha256', $prev . "\n" . audit_canon($row), $key);
}

/** 머리 행이 없으면 만든다(트랜잭션 밖 — 경합하면 UNIQUE 가 한 번만 통과시킨다). */
function audit_ensure_head(): void
{
    if (db_val('SELECT skey FROM ' . T_SETTINGS . ' WHERE skey = ?', [AUDIT_HEAD_KEY]) !== null) {
        return;
    }
    try {
        db_insert(T_SETTINGS, ['skey' => AUDIT_HEAD_KEY, 'svalue' => '0:' . AUDIT_GENESIS, 'vtype' => 'string',
            'label' => null, 'updated_at' => now_utc()]);
    } catch (Throwable $e) {
        if (!db_is_duplicate($e)) {
            throw $e;
        }
    }
}

/**
 * 감사 행 여러 개를 체인에 잇는다. **반드시 트랜잭션 안에서 부른다**(db_tx 를 직접 열면 중첩은 안쪽만 실행된다).
 * $rows 의 각 원소는 체인 컬럼(id·prev_hash·row_hash)이 없는 행. 반환: 마지막 행의 receipt {seq, hash} | null.
 */
function audit_append_in_tx(array $rows): ?array
{
    if ($rows === []) {
        return null;
    }
    $key  = audit_chain_key();
    $head = (string) db_val('SELECT svalue FROM ' . T_SETTINGS . ' WHERE skey = ?' . sql_for_update(), [AUDIT_HEAD_KEY]);
    $p = strpos($head, ':');
    if ($p === false) {
        throw new RuntimeException('audit chain head is malformed');
    }
    $seq  = (int) substr($head, 0, $p);
    $prev = substr($head, $p + 1);
    // 머리가 표보다 뒤처졌으면(머리 갱신 없이 행만 들어간 상태 = 손상) 멈춘다 — 조용히 덮어 잇지 않는다.
    $max = (int) db_val('SELECT MAX(id) FROM ' . T_PLAT_AUDIT);
    if ($max !== $seq) {
        throw new RuntimeException('audit chain head (' . $seq . ') does not match table max id (' . $max . ')');
    }
    foreach ($rows as $r) {
        $seq++;
        $r['id']        = $seq;
        $r['prev_hash'] = $prev;
        $r['row_hash']  = audit_row_hash($prev, $r, $key);
        db_insert(T_PLAT_AUDIT, $r);
        $prev = $r['row_hash'];
    }
    db_exec('UPDATE ' . T_SETTINGS . ' SET svalue = ?, updated_at = ? WHERE skey = ?', [$seq . ':' . $prev, now_utc(), AUDIT_HEAD_KEY]);
    return ['seq' => $seq, 'hash' => $prev];
}

/** 트랜잭션을 스스로 여는 판(관리자 기록·CLI 용). */
function audit_append(array $rows): ?array
{
    audit_ensure_head();
    $r = db_tx(function () use ($rows) {
        return audit_append_in_tx($rows);
    });
    if ($r !== null) {
        audit_head_note($r);
    }
    return $r;
}

// ---------------------------------------------------------------------
// v1.9.0 보안 M190-4 (D-361) — 체인 머리의 **DB 밖** 사본. 머리를 같은 DB settings 에만 두면 DB 쓰기 권한만으로
// 마지막 행을 지우고 머리를 되돌려 흔적 없이 최근 기록을 지울 수 있었다(verify ok). 커밋된 머리 `{seq}:{hash}` 를
// **DB 파일 옆**(`<DB파일>.audit_head.log`, SQLite) 에 한 줄씩 덧붙이고(추가 전용), verify·health 가 파일의 최대 seq 와
// DB 를 대조한다. 버전 폴더 안(server/storage)에 두면 다음 판 배포(접합 전환)에서 사라지거나 옛 판에 남아 거짓 실패·
// 앵커 단절이 난다 — 그래서 DB 와 같은 영속 위치다(D-361 후속). MySQL 은 DB 파일이 없으므로 상수 AUDIT_HEAD_PATH 가
// 있으면 그 경로, 없으면 server/storage/ (공용 호스팅은 접합 배포가 아니다 — 판을 바꿀 때 이 파일을 함께 옮겨라).
// 파일이 없으면 첫 확인·첫 기록 때 **현재 DB 머리로 초기화**하고(조용히 ok) health 에 「앵커 새로 시작」을 띄운다.
// 만들 때 ACL 을 소유자 + SYSTEM + Administrators 로 좁힌다(tools/register_services.php 의 handoff 쓰기와 같은 규칙).
// ---------------------------------------------------------------------

const AUDIT_HEAD_FILE_SUFFIX = '.audit_head.log';
const AUDIT_ANCHOR_KEY       = 'audit.head_anchor_started_at';
const AUDIT_CHECKED_KEY      = 'audit.head_checked_offset';   // health 의 증분 대조 지점(바이트)
const AUDIT_TAIL_LINES       = 1000;                           // health 가 매번 다시 보는 꼬리 줄 수
const AUDIT_LINE_BYTES       = 72;                             // 줄 하나 상한(seq ≤ 6자리 + ':' + 64 + 개행) — 꼬리 창 계산용

function audit_head_file(): string
{
    if (defined('AUDIT_HEAD_PATH') && is_string(AUDIT_HEAD_PATH) && AUDIT_HEAD_PATH !== '') {
        return (string) AUDIT_HEAD_PATH;
    }
    if (db_driver() === 'sqlite') {
        return ulogin_sqlite_path_resolve(defined('DB_SQLITE_PATH') ? DB_SQLITE_PATH : '') . AUDIT_HEAD_FILE_SUFFIX;
    }
    return __DIR__ . '/../storage/ulogin' . AUDIT_HEAD_FILE_SUFFIX;
}

/** 파일을 배타 생성하고 ACL 을 좁힌 뒤 현재 DB 머리 한 줄로 시작한다. 시작 시각을 남긴다(health 표시). */
function audit_head_init(): void
{
    $f = audit_head_file();
    $h = @fopen($f, 'x');
    if ($h === false) {
        return;   // 이미 있다(경합) 또는 쓸 수 없다 — 아래 쓰기가 실패를 기록한다
    }
    fclose($h);
    if (!ulogin_file_acl_restrict($f)) {
        error_log('[ulogin] audit head file ACL could not be narrowed: ' . basename($f));
        audit_mark_failure();
    }
    $max = (int) db_val('SELECT MAX(id) FROM ' . T_PLAT_AUDIT);
    if ($max > 0) {
        $hash = (string) db_val('SELECT row_hash FROM ' . T_PLAT_AUDIT . ' WHERE id = ?', [$max]);
        @file_put_contents($f, $max . ':' . $hash . "\n", FILE_APPEND | LOCK_EX);
    }
    try {
        setting_set(AUDIT_ANCHOR_KEY, now_utc(), 'string');
    } catch (Throwable $e) {
        error_log('[ulogin] audit anchor start marker failed: ' . $e->getMessage());
    }
}

/** 트랜잭션 밖이면 바로, 안이면 요청 끝에 — 그때 DB 에 그 행이 실제로 커밋됐는지 확인하고 적는다(롤백된 머리를 적지 않는다). */
function audit_head_note(array $receipt): void
{
    static $queue = null;
    if ($queue === null) {
        $queue = [];
        register_shutdown_function(function () use (&$queue) {
            foreach ($queue as $q) {
                audit_head_write($q);
            }
            $queue = [];
        });
    }
    $inTx = db_driver() === 'sqlite' ? db_sqlite_tx_flag() : db()->inTransaction();
    if ($inTx) {
        $queue[] = $receipt;
        return;
    }
    audit_head_write($receipt);
}

function audit_head_write(array $receipt): void
{
    try {
        $seq = (int) $receipt['seq'];
        $h = db_val('SELECT row_hash FROM ' . T_PLAT_AUDIT . ' WHERE id = ?', [$seq]);
        if ($h === null || !hash_equals((string) $h, (string) $receipt['hash'])) {
            return;   // 커밋되지 않았다(바깥 트랜잭션 롤백) — 적지 않는다
        }
        if (!is_file(audit_head_file())) {
            audit_head_init();   // 처음 — 만들고 ACL 을 좁힌 뒤 지금 머리로 시작(이 줄과 같은 값이 들어갈 수 있다)
        }
        if (@file_put_contents(audit_head_file(), $seq . ':' . (string) $h . "\n", FILE_APPEND | LOCK_EX) === false) {
            error_log('[ulogin] audit head file is not writable: ' . basename(audit_head_file()));
            audit_mark_failure();
        }
    } catch (Throwable $e) {
        error_log('[ulogin] audit head file write failed: ' . $e->getMessage());
        audit_mark_failure();
    }
}

/**
 * 꼬리 대조 — DB 머리 설정 = MAX(id), 그리고 DB 밖 머리 사본의 줄들이 DB 와 맞는가.
 *
 * v1.9.0 보안 M190-5 (재검증) — 줄을 대조한다. 실패 조건 셋:
 *   ① 같은 seq 에 서로 다른 해시 두 줄  ② 줄의 seq 가 DB 에 없다(정리된 앞부분 < MIN(id) 은 제외)  ③ 해시가 다르다
 * 마지막 줄만 보면, 꼬리를 자른 뒤 정상 감사가 1건 더 들어와 같은 seq 가 새 해시로 다시 적히는 순간 탐지가 사라졌다.
 *
 * 범위(성능, 리더 지시) — 감사는 하루 5만 건까지라 파일이 그만큼 자란다. 단일 작업자에서 health 마다 전체를 읽지 않는다:
 *   $full=false (health) : **마지막 검증 지점(바이트 오프셋) 이후 새 줄 + 꼬리 1,000줄**. 같은 seq 중복 검사는 이 범위 안에서.
 *                          통과하면 오프셋을 파일 끝으로 옮긴다(설정 audit.head_checked_offset). 처음(오프셋 없음)은 꼬리만.
 *   $full=true           : 파일 전체. 관리자 「검증」(platform_logs verify) · CLI(audit_backfill) · 하루 1회 정리 job(plat_gc).
 * 파일은 audit_prune() 이 지운 범위의 줄을 함께 잘라 크기를 유지한다(제자리 재작성 — ACL 유지).
 * @return array{ok:bool, first_bad_seq:?int}
 */
function audit_tail_check(bool $full = true): array
{
    $max = (int) db_val('SELECT MAX(id) FROM ' . T_PLAT_AUDIT);
    $head = (string) db_val('SELECT svalue FROM ' . T_SETTINGS . ' WHERE skey = ?', [AUDIT_HEAD_KEY]);
    $p = strpos($head, ':');
    if ($head !== '' && ($p === false || (int) substr($head, 0, $p) !== $max)) {
        return ['ok' => false, 'first_bad_seq' => $max + 1];
    }
    $f = audit_head_file();
    if (!is_file($f)) {
        // 파일이 없다(첫 실행 · 새 위치로 옮긴 직후) — 현재 DB 머리로 시작하고 조용히 ok. health 가 「앵커 새로 시작」을 띄운다.
        audit_head_init();
        return ['ok' => true, 'first_bad_seq' => null];
    }
    clearstatcache(true, $f);
    $size  = (int) @filesize($f);
    $start = 0;
    if (!$full) {
        $saved = setting_get(AUDIT_CHECKED_KEY, null);
        $tailStart = max(0, $size - AUDIT_TAIL_LINES * AUDIT_LINE_BYTES);
        $start = (is_string($saved) && ctype_digit($saved) && (int) $saved <= $size) ? min((int) $saved, $tailStart) : $tailStart;
    }
    $fh = @fopen($f, 'rb');
    if ($fh === false) {
        return ['ok' => false, 'first_bad_seq' => $max + 1];
    }
    if ($start > 0) {
        fseek($fh, $start - 1);
        if (fgetc($fh) !== "\n") {
            fgets($fh);   // 줄 중간에서 시작했다 — 그 줄은 버린다
        }
    }
    $map = [];
    $bad = [];
    while (($line = fgets($fh)) !== false) {
        $m = [];
        if (preg_match('/^(\d+):([0-9a-f]{64})$/', trim($line), $m) !== 1) {
            continue;
        }
        $s = (int) $m[1];
        if (isset($map[$s]) && !hash_equals($map[$s], $m[2])) {
            $bad[] = $s;   // ①
        }
        $map[$s] = $m[2];
    }
    fclose($fh);
    $min = (int) db_val('SELECT MIN(id) FROM ' . T_PLAT_AUDIT);
    $seqs = [];
    foreach (array_keys($map) as $s) {
        if ($max === 0 || $s >= $min) {
            $seqs[] = $s;
        }
    }
    foreach (array_chunk($seqs, 500) as $chunk) {
        $have = [];
        foreach (db_all('SELECT id, row_hash FROM ' . T_PLAT_AUDIT . ' WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')',
            $chunk) as $r) {
            $have[(int) $r['id']] = (string) $r['row_hash'];
        }
        foreach ($chunk as $s) {
            if (!isset($have[$s]) || !hash_equals($have[$s], $map[$s])) {
                $bad[] = $s;   // ② · ③
            }
        }
    }
    if ($bad !== []) {
        return ['ok' => false, 'first_bad_seq' => min($bad)];
    }
    if ((string) setting_get(AUDIT_CHECKED_KEY, '') !== (string) $size) {   // 바뀔 때만 쓴다(health 마다 fsync 하지 않게)
        try {
            setting_set(AUDIT_CHECKED_KEY, (string) $size, 'string');
        } catch (Throwable $e) {
            error_log('[ulogin] audit head check offset not saved: ' . $e->getMessage());
        }
    }
    return ['ok' => true, 'first_bad_seq' => null];
}

/** audit_prune() 뒤 — 지운 범위(seq ≤ $upto)의 줄을 잘라 파일 크기를 유지한다. 제자리 재작성(같은 파일 → ACL 유지), 쓰기 잠금 안에서. */
function audit_head_file_trim(int $upto): void
{
    $f = audit_head_file();
    if (!is_file($f)) {
        return;
    }
    $fh = @fopen($f, 'c+');
    if ($fh === false || !flock($fh, LOCK_EX)) {
        error_log('[ulogin] audit head file trim: cannot lock');
        return;
    }
    $keep = '';
    rewind($fh);
    while (($line = fgets($fh)) !== false) {
        $m = [];
        if (preg_match('/^(\d+):([0-9a-f]{64})$/', trim($line), $m) === 1 && (int) $m[1] > $upto) {
            $keep .= trim($line) . "\n";
        }
    }
    rewind($fh);
    ftruncate($fh, 0);
    fwrite($fh, $keep);
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    try {
        setting_set(AUDIT_CHECKED_KEY, '0', 'string');   // 오프셋이 의미를 잃었다 — 다음 health 는 꼬리부터
    } catch (Throwable $e) {
        error_log('[ulogin] audit head check offset reset failed: ' . $e->getMessage());
    }
}

/**
 * 체인 검증. from_seq 부터 최대 5,000행. 첫 행의 앞 행이 없으면(정리됨) audit.prune 앵커가 그 자리를 이어야 한다.
 * @return array{checked:int, ok:bool, first_bad_seq:?int, last_seq:?int}
 */
function audit_verify(int $fromSeq, int $limit = AUDIT_VERIFY_MAX, bool $fullTail = true): array
{
    $key   = audit_chain_key();
    $limit = max(1, min(AUDIT_VERIFY_MAX, $limit));
    $rows  = db_all('SELECT * FROM ' . T_PLAT_AUDIT . ' WHERE id >= ? ORDER BY id ASC LIMIT ' . $limit, [max(1, $fromSeq)]);
    if ($rows === []) {
        $t = audit_tail_check($fullTail);   // 전부 지워졌는데 파일 머리는 남아 있는 경우
        return ['checked' => 0, 'ok' => $t['ok'], 'first_bad_seq' => $t['first_bad_seq'], 'last_seq' => null];
    }
    $first = $rows[0];
    $fid   = (int) $first['id'];
    $before = db_one('SELECT id, row_hash FROM ' . T_PLAT_AUDIT . ' WHERE id < ? ORDER BY id DESC LIMIT 1', [$fid]);
    if ($before !== null) {
        $expectPrev = ((int) $before['id'] === $fid - 1) ? (string) $before['row_hash'] : null;
    } elseif ($fid === 1) {
        $expectPrev = AUDIT_GENESIS;
    } else {
        $expectPrev = audit_prune_anchor_hash($fid - 1);
    }
    $checked = 0;
    $prevId  = $fid - 1;
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $checked++;
        if ($id !== $prevId + 1 || $expectPrev === null || !hash_equals($expectPrev, (string) $r['prev_hash'])
            || !hash_equals(audit_row_hash((string) $r['prev_hash'], $r, $key), (string) $r['row_hash'])) {
            return ['checked' => $checked, 'ok' => false, 'first_bad_seq' => $id, 'last_seq' => $id];
        }
        $expectPrev = (string) $r['row_hash'];
        $prevId = $id;
    }
    // M190-4 — 끝까지 읽었으면 꼬리를 DB 밖 머리 사본과 대조한다(마지막 행 삭제 + 머리 되돌림 탐지)
    if (count($rows) < $limit || $prevId === (int) db_val('SELECT MAX(id) FROM ' . T_PLAT_AUDIT)) {
        $t = audit_tail_check($fullTail);
        if (!$t['ok']) {
            return ['checked' => $checked, 'ok' => false, 'first_bad_seq' => $t['first_bad_seq'], 'last_seq' => $prevId];
        }
    }
    return ['checked' => $checked, 'ok' => true, 'first_bad_seq' => null, 'last_seq' => $prevId];
}

/** to_seq = $seq 인 audit.prune 앵커가 남긴 마지막 지운 행의 hash. 없으면 null. */
function audit_prune_anchor_hash(int $seq): ?string
{
    $rows = db_all('SELECT changes_json FROM ' . T_PLAT_AUDIT . ' WHERE service_id = ? AND action = ? ORDER BY id DESC LIMIT 50',
        ['ulogin', 'audit.prune']);
    foreach ($rows as $r) {
        $c = json_col($r['changes_json']);
        if (isset($c['to_seq']['after'], $c['last_hash']['after']) && (int) $c['to_seq']['after'] === $seq) {
            return (string) $c['last_hash']['after'];
        }
    }
    return null;
}

/**
 * 보존 만료 감사 행 정리 — **유일하게 감사 행을 지우는 함수.** 요청당 500행.
 * 지우기 전에 체인 머리에 audit.prune 앵커(지운 범위 · 마지막 행 hash)를 먼저 쓴다. 검증은 그 앵커부터 이어진다.
 * @return int 지운 행 수
 */
function audit_prune(int $retentionDays): int
{
    $cut  = gmdate('Y-m-d H:i:s', time() - max(1, $retentionDays) * 86400);
    $minR = db_one('SELECT MIN(id) AS lo FROM ' . T_PLAT_AUDIT);
    $lo   = $minR === null ? 0 : (int) $minR['lo'];
    if ($lo <= 0) {
        return 0;
    }
    $loRow = db_one('SELECT id, received_at FROM ' . T_PLAT_AUDIT . ' WHERE id = ?', [$lo]);
    if ($loRow === null || (string) $loRow['received_at'] >= $cut) {
        return 0;
    }
    // 이번 묶음의 끝: lo 부터 500행 안에서 received_at < cut 인 마지막 연속 행
    $cand = db_all('SELECT id, received_at, row_hash FROM ' . T_PLAT_AUDIT . ' WHERE id >= ? ORDER BY id ASC LIMIT '
        . AUDIT_PRUNE_CHUNK, [$lo]);
    $hi = 0;
    $hiHash = '';
    foreach ($cand as $c) {
        if ((string) $c['received_at'] >= $cut) {
            break;
        }
        $hi = (int) $c['id'];
        $hiHash = (string) $c['row_hash'];
    }
    if ($hi === 0) {
        return 0;
    }
    $anchor = audit_ulogin_row('system', null, 'audit.prune', 'audit', $lo . '-' . $hi, [
        'from_seq'  => ['before' => null, 'after' => $lo],
        'to_seq'    => ['before' => null, 'after' => $hi],
        'last_hash' => ['before' => null, 'after' => $hiHash],
    ], 'retention ' . $retentionDays . 'd');
    audit_ensure_head();
    $rc = null;
    $n = (int) db_tx(function () use ($anchor, $lo, $hi, &$rc) {
        $rc = audit_append_in_tx([$anchor]);
        return db_exec('DELETE FROM ' . T_PLAT_AUDIT . ' WHERE id >= ? AND id <= ?', [$lo, $hi]);
    });
    if ($rc !== null) {
        audit_head_note($rc);
    }
    if ($n > 0) {
        audit_head_file_trim($hi);   // 지운 범위의 머리 사본 줄도 잘라 파일 크기를 유지한다
    }
    return $n;
}

/** ulogin 자기 감사 행(체인 컬럼 제외). */
function audit_ulogin_row(string $actorType, ?string $actorUuid, string $action, string $targetType, ?string $targetId,
                          ?array $changes, ?string $reason = null): array
{
    $j = $changes === null || $changes === [] ? null : json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return [
        'service_id'   => 'ulogin',
        'event_id'     => gen_uuid4(),
        'ts'           => now_utc(),
        'received_at'  => now_utc(),
        'actor_type'   => $actorType,
        'actor_uuid'   => $actorUuid,
        'actor_local'  => null,
        'action'       => substr(strtolower((string) preg_replace('/[^a-zA-Z0-9_.]/', '_', $action)), 0, 64),
        'target_type'  => substr(strtolower((string) preg_replace('/[^a-zA-Z0-9_]/', '_', $targetType)), 0, 32),
        'target_id'    => $targetId === null ? null : mb_substr($targetId, 0, 128, 'UTF-8'),
        'result'       => 'success',
        'changes_json' => is_string($j) ? $j : null,
        'ip'           => PHP_SAPI === 'cli' ? null : client_ip(),
        'user_agent'   => PHP_SAPI === 'cli' ? null : mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255, 'UTF-8'),
        'request_id'   => null,
        'reason'       => $reason === null ? null : mb_substr($reason, 0, 255, 'UTF-8'),
        'sender_ip'    => PHP_SAPI === 'cli' ? 'cli' : client_ip(),
    ];
}

/** admin_logs 의 before/after 를 감사 changes {필드:{before,after}} 로 (변경 필드만, 비밀 키 마스킹). */
function audit_changes_from(?array $before, ?array $after): ?array
{
    if ($before === null && $after === null) {
        return null;
    }
    $keys = array_unique(array_merge(array_keys((array) $before), array_keys((array) $after)));
    $c = [];
    foreach (array_slice($keys, 0, 32) as $k) {
        $c[(string) $k] = [
            'before' => is_array($before) && array_key_exists($k, $before) ? $before[$k] : null,
            'after'  => is_array($after) && array_key_exists($k, $after) ? $after[$k] : null,
        ];
    }
    $hit = false;
    $n = function_exists('plat_norm_changes') ? plat_norm_changes($c, $hit) : null;
    return $n === null ? null : $n;
}

/** 감사 쓰기 실패 표식(H-3) — health 의 audit_chain_ok 가 24시간 동안 false 를 낸다. 실패해도 삼킨다. */
function audit_mark_failure(): void
{
    try {
        setting_set(AUDIT_FAIL_KEY, now_utc(), 'string');
    } catch (Throwable $e) {
        error_log('[ulogin] audit failure marker could not be written: ' . $e->getMessage());
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
        // v1.8.0 — UPSERT 문장은 db_upsert_increment() 가 드라이버별로 만든다(설계 A2·B-4).
        // 충돌 대상 = uq_secret_access_bucket (session_id, source, window_start).
        //   hits                         새 행 1 · 충돌 시 +1
        //   last_ip · last_at · secret_keys  충돌 시 새 값으로 덮는다   (종전 VALUES(col))
        //   user_id · service_id · first_ip · first_at  INSERT 에만 — **덮지 않는다**.
        //     first_* 를 덮으면 「같은 세션이 다른 IP 에서 쓰였다」는 탈취 신호가 사라진다.
        db_upsert_increment(
            T_SECRET_ACCESS,
            [
                'session_id'   => (string) $session['session_id'],
                'source'       => substr($source, 0, 20),
                'window_start' => $window,
            ],
            'hits',
            ['last_ip' => $ip, 'last_at' => $now, 'secret_keys' => $keyList],
            ['user_id' => (int) $session['user_id'], 'service_id' => $serviceId, 'first_ip' => $ip, 'first_at' => $now]
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
