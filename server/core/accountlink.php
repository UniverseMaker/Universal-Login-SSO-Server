<?php
declare(strict_types=1);

/**
 * core/accountlink.php — 시스템 계정 가져오기·이관·연결 (v1.8.4)
 * 담당 계약: `_workspace/92_architect_v182_account_link_spec.md` C·D·E절 · S-2 · S-4 · S-5
 *            리더 판정 D-324(H-2 스냅숏 최소화 · H-4 파일 가져오기) · D-328 · D-331
 *
 * 이 파일에 모인 것 — **여러 엔드포인트가 같은 규칙을 써야 하는 것만** 둔다:
 *   account_link_for()      exchange·session 응답의 `account_link` (S-5). 조회 한 곳
 *   al_s2s_auth()           시스템 → ulogin 서버 간 인증 (push·피드 공용, exchange 와 같은 방식)
 *   al_validate_payload()   S-2 본문 검증 — **push 와 파일 가져오기가 같은 함수를 쓴다**(H-4)
 *   al_ingest()             페이지 순서 · duplicate · 행 단위 단조(source_sent_at) · final → 사라짐
 *   al_feed()               연결 사건 피드 (S-4)
 *   al_release_user()       탈퇴·완전삭제가 부르는 자동 해제 (E절, D-172 소비자)
 *   al_purge_service()      서비스 삭제가 부르는 정리 (E절)
 *   al_issue_setup()        첫 비밀번호 설정 링크 (D절, 72h, 원문은 반환값으로 한 번만)
 *
 * **연결의 진실은 `ext_accounts.user_id` 하나다.** 한 외부 계정 ↔ ulogin 계정 하나, 한 ulogin 계정 ↔
 * 서비스당 외부 계정 하나는 **DB UNIQUE 두 개**가 강제한다. 여기서 같은 검사를 코드로 다시 하지
 * 않는다 — 코드 검사는 경합에서 진다(설계 A-2). 상태 전이는 조건부 UPDATE
 * (`row_version = ? AND state IN (…)`)가 원자적으로 판정한다.
 *
 * PHP 7.2 호환(core/bootstrap.php 상단 금지 목록).
 */

// 직접 실행 차단 — 리더 판정 D-34.
if (!defined('ULOGIN_BOOTSTRAPPED')) {
    header_remove('X-Powered-By');
    http_response_code(404);
    exit;
}

const AL_EXT_STATUS        = ['active', 'disabled', 'deleted'];
const AL_EXTRA_KEYS        = ['role', 'department', 'note', 'kind'];   // v1.10.0 kind — "emergency" 만 의미가 있다(설계 127 §6-3)
const AL_PUSH_MAX_ACCOUNTS = 200;        // S-1 한도: 요청당 계정 200개
const AL_PUSH_MAX_BYTES    = 262144;     // S-1 한도: 본문 256KB
const AL_FILE_MAX_ACCOUNTS = 2000;       // 파일 가져오기(H-4) — 한 파일 = 한 시스템 내보내기
const AL_FILE_MAX_BYTES    = 1048576;
const AL_SENT_AT_SKEW      = 300;        // S-1: sent_at 은 ulogin 시계 ±300초
const AL_PLAN_TTL          = 1800;       // G-4: plan +30분
const AL_SETUP_TTL         = 259200;     // D절: 첫 비밀번호 설정 링크 72h
const AL_COMMIT_CHUNK      = 25;         // G-5: 한 요청 25건(bcrypt 25회 ≈ 2s 상한)
const AL_PLAN_MAX_ITEMS    = 500;
const AL_AUTHFAIL_MAX      = 10;         // S-1: 인증 실패 15분 10회 넘으면 429
const AL_AUTHFAIL_WINDOW   = 900;
/** D-328 — 외부 계정의 `extra.role` 이 이 값이면 「시스템 관리자」로 본다. 비교는 소문자. */
const AL_ADMIN_EXTRA_ROLES = ['admin', 'superadmin', 'administrator'];

/**
 * 상태 전이 표(설계 C절). 이 표 밖의 전이는 BAD_TRANSITION(plan) / EXT_CHANGED(commit).
 * `linked → link` 는 「먼저 해제」 — 같은 plan 안의 해제+재연결은 plan 이 거부한다.
 */
const AL_TRANSITIONS = [
    'create_link' => ['from' => ['new', 'ignored', 'unlinked'], 'to' => 'linked'],
    'link'        => ['from' => ['new', 'ignored', 'unlinked'], 'to' => 'linked'],
    'ignore'      => ['from' => ['new', 'unlinked'],            'to' => 'ignored'],
    'unignore'    => ['from' => ['ignored'],                    'to' => 'new'],
    'unlink'      => ['from' => ['linked'],                     'to' => 'unlinked'],
];

/** 항목 판정 코드 중 「확정 가능하지만 확인 필요」 = warn. 나머지 코드는 invalid. */
const AL_WARN_CODES = ['SYSTEM_BOUND_OTHER', 'EXT_GONE', 'EXT_DISABLED', 'EXT_ROLE_ADMIN_LOW_RANK'];

/** 항목 하나의 실패. 봉투 에러가 아니다 — 그 항목만 failed/skipped 로 남기고 다음 항목으로 간다. */
class AlItemFail extends Exception
{
    /** @var string 항목 코드(G-4 열거) */
    public $itemCode;
    /** @var string failed|skipped */
    public $itemStatus;

    public function __construct(string $code, string $status = 'failed')
    {
        parent::__construct($code);
        $this->itemCode   = $code;
        $this->itemStatus = $status;
    }
}

// =====================================================================
// 공용 소품
// =====================================================================

/**
 * ISO8601(UTC 권장) → DB DATETIME(UTC). 형식이 아니면 null.
 * strtotime 은 "now"·"next monday" 같은 것도 받으므로 **모양을 먼저 고정한다.**
 * @param mixed $v
 */
function al_parse_iso($v): ?string
{
    if (!is_string($v)) {
        return null;
    }
    $s = trim($v);
    if (preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?)?(?:Z|[+-]\d{2}:?\d{2})?$/', $s) !== 1) {
        return null;
    }
    $t = strtotime($s);
    if ($t === false) {
        return null;
    }
    return gmdate('Y-m-d H:i:s', $t);
}

function al_is_uuid($v): bool
{
    return is_string($v)
        && preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $v) === 1;
}

/**
 * 선택 문자열 필드. 정수는 문자열로 받는다(이미 운영 중인 시스템이 숫자 id 를 그대로 보낸다).
 * 제어 문자를 지우고 앞뒤 공백을 자른 뒤 `$max` 자로 **자른다** — 계약 S-2 가 길이를 적지 않았고
 * 네 시스템이 이미 이 계약으로 운영 중이라(D-331) 거절보다 자르기가 맞다. 표시용 필드에만 쓴다.
 * @param mixed $v
 */
function al_opt_str($v, int $max): ?string
{
    if (is_int($v)) {
        $v = (string) $v;
    }
    if (!is_string($v)) {
        return null;
    }
    $s = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $v));
    if ($s === '') {
        return null;
    }
    return mb_substr($s, 0, $max, 'UTF-8');
}

/** 시스템이 보낸 extra 에서 화이트리스트 키만(S-2). 값은 문자열 ≤100자. 비면 null. */
function al_norm_extra($v): ?string
{
    if (!is_array($v)) {
        return null;
    }
    $out = [];
    foreach (AL_EXTRA_KEYS as $k) {
        if (!array_key_exists($k, $v)) {
            continue;
        }
        $s = al_opt_str($v[$k], 100);
        if ($s !== null) {
            $out[$k] = $s;
        }
    }
    if ($out === []) {
        return null;
    }
    $j = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return (is_string($j) && strlen($j) <= 4096) ? $j : null;
}

/** 외부 계정이 시스템에서 관리자로 표시됐는가 (D-328). */
function al_ext_is_admin(?string $extraJson): bool
{
    $x = json_col($extraJson);
    if (!isset($x['role']) || !is_string($x['role'])) {
        return false;
    }
    return in_array(strtolower(trim($x['role'])), AL_ADMIN_EXTRA_ROLES, true);
}

/** 서비스 존재 확인(관리자 화면용 — 비활성 서비스도 연결 관리 대상이다). */
function al_service_row(?string $sid): ?array
{
    if ($sid === null || $sid === '') {
        return null;
    }
    return db_one('SELECT service_id, service_name, status, is_confidential FROM ' . T_SERVICES . ' WHERE service_id = ?', [$sid]);
}

/** 그 서비스에 미처리 재수집 요청이 있는가 (S-2 응답 · S-4 응답의 `sync_requested`). */
function al_sync_requested(string $sid): bool
{
    return db_val('SELECT id FROM ' . T_LINK_JOBS . ' WHERE kind = ? AND service_id = ? AND status = ?',
        ['sync_request', $sid, 'requested']) !== null;
}

// =====================================================================
// S-5 — exchange · session 응답의 `account_link`
// =====================================================================

/**
 * 그 (사용자, 서비스)의 연결. 없으면 null. **`state='linked'` 행만** 본다.
 *
 * user_id 는 linked 일 때만 값이 있다는 불변식이 있지만 그 불변식에 기대지 않는다 —
 * 상태 조건이 빠지면 해제된 연결로 남의 로컬 계정에 들어가는 열쇠가 나간다.
 * 조회 실패(표 없음 = 0005 미적용 등)는 **삼키지 않는다** — null 을 돌려주면 시스템은
 * 「ulogin 이 연결을 끊었다」로 읽고 묶음을 푼다(S-5 null 규칙). 그보다 500 이 낫다.
 *
 * @return array{external_id:string,username:?string,linked_at:?string,link_origin:?string}|null
 */
function account_link_for(int $userId, ?string $serviceId): ?array
{
    if ($userId <= 0 || $serviceId === null || $serviceId === '') {
        return null;
    }
    $r = db_one('SELECT external_id, username, linked_at, link_origin FROM ' . T_EXT_ACCOUNTS
        . ' WHERE service_id = ? AND user_id = ? AND state = ?', [$serviceId, $userId, 'linked']);
    if ($r === null) {
        return null;
    }
    return [
        'external_id' => (string) $r['external_id'],
        'username'    => $r['username'] === null ? null : (string) $r['username'],
        'linked_at'   => iso($r['linked_at']),
        'link_origin' => $r['link_origin'] === null ? null : (string) $r['link_origin'],
    ];
}

// =====================================================================
// S-1 — 서버 간 인증 (push · 피드)
// =====================================================================

/** 이 IP 의 이번 15분 창 인증 실패 수. 읽기만 한다(증가는 실패한 뒤에만). */
function al_authfail_count(string $bucket): int
{
    $winStart = intdiv(time(), AL_AUTHFAIL_WINDOW) * AL_AUTHFAIL_WINDOW;
    try {
        return (int) db_val('SELECT count FROM ' . T_RATE_LIMITS . ' WHERE bucket = ? AND window_start = ?',
            [substr($bucket, 0, 150), gmdate('Y-m-d H:i:s', $winStart)]);
    } catch (Throwable $e) {
        error_log('[ulogin] s2s authfail read failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * 본문 `service_id` + `service_secret` — exchange 와 **같은 방식**(설계 B: 방식이 둘이면 약한 쪽이 우회로다).
 *
 *  · (IP, service_id) 별 실패 15분 10회가 차면 **검증 전에** 429 — 맞는 secret 으로 정오답을 가를 수 없게(429 는 맞든 틀리든 같다)
 *  · 없는 서비스 · confidential 아님 · secret 없음도 **더미 bcrypt 1회** 후 401(응답 시간으로 갈리지 않게)
 *  · 실패는 login_logs `fail_client_auth`. **secret 은 어디에도 남기지 않는다**
 *
 * @return array services 행(활성·confidential)
 */
function al_s2s_auth(array $in): array
{
    $sid    = (string) v_service_id($in, 'service_id', true);   // v1.8.6 — 옛 id 는 여기서 새 id 로(설계 104 C-1)
    $raw    = in_raw($in, 'service_secret');
    $secret = (is_string($raw) && $raw !== '' && strlen($raw) <= 255) ? $raw : null;

    // 버킷 = IP × service_id. 같은 PC 의 시스템들은 전부 127.0.0.1 로 보인다 — IP 하나로 묶으면 secret 이 낡은
    // 시스템 하나가 피드를 1분마다 두드리는 것만으로 **다른 시스템 전부의 push·피드를 15분씩 막는다.**
    // secret 은 256비트 난수라 서비스 id 를 바꿔 가며 버는 시도 횟수는 의미가 없다(전역 api:ip 300/60 도 걸린다).
    $bucket = 's2s:authfail:' . client_ip() . ':' . $sid;
    if (al_authfail_count($bucket) >= AL_AUTHFAIL_MAX) {
        if (rate_limit_claim_first($bucket, AL_AUTHFAIL_WINDOW)) {
            log_login('fail_ratelimit', null, '', $sid);
        }
        $winStart = intdiv(time(), AL_AUTHFAIL_WINDOW) * AL_AUTHFAIL_WINDOW;
        throw new AppError('RATE_LIMITED', 429, ['retry_after' => max(1, $winStart + AL_AUTHFAIL_WINDOW - time())]);
    }

    $svc    = db_one('SELECT * FROM ' . T_SERVICES . ' WHERE service_id = ? AND status = ?', [$sid, 'active']);
    // v1.9.0 보안 H190-1 (D-361) — **없는(또는 confidential 이 아닌) service_id** 의 시도는 IP 하나에 300초 10회.
    // 위 버킷은 IP×service_id 라 id 를 바꿔 가며 보내면 끝없이 더미 bcrypt(~70 ms)를 태워 작업자 1개를 포화시켰다.
    // **bcrypt 전에** 판정한다(초과 시 429, 해시 계산 없음). 실재하는 id 의 낡은 secret 은 이 버킷을 쓰지 않는다 —
    // 같은 PC(127.0.0.1)의 다른 시스템이 secret 교체 대기 중이어도 서로를 막지 않는다.
    $unknown = ($svc === null || empty($svc['is_confidential']));
    if ($unknown) {
        rate_limit('s2s:unknownsid:' . client_ip(), 10, 300);
    }
    $stored = $svc === null ? '' : (string) ($svc['service_secret_hash'] ?? '');
    $ok     = false;
    if ($svc !== null && !empty($svc['is_confidential']) && $stored !== '' && $secret !== null) {
        $ok = is_token_format($stored) ? hash_equals($stored, token_hash($secret)) : password_verify($secret, $stored);
    } else {
        dummy_password_verify((string) $secret);
    }
    if (!$ok) {
        // 없는 id 실패는 창(300초)당 1행만 남긴다(H190-1 — 실패마다 1행이면 공격이 로그를 채운다). 실재 id 는 종전대로.
        if (!$unknown || rate_limit_claim_first('s2s:unknownsid:log:' . client_ip(), 300)) {
            log_login('fail_client_auth', null, '', $sid);
        }
        rate_limit($bucket, AL_AUTHFAIL_MAX, AL_AUTHFAIL_WINDOW);
        throw new AppError('SSO_CLIENT_AUTH_FAILED', 401, ['field' => 'service_secret']);
    }
    service_alias_touch((string) $svc['service_id']);   // v1.8.6 — secret 이 맞은 별칭 사용만 센다(설계 104 C-1)
    return $svc;
}

// =====================================================================
// S-2 — 본문 검증 (push 와 파일 가져오기 공용, H-4)
// =====================================================================

/**
 * 계정 1개 정규화. 필수 = external_id · status. 그 밖은 선택이고 화이트리스트 밖 키는 **버린다**
 * (비밀번호·해시를 보내도 저장되지 않는다 — H-2 최소화).
 *
 * external_id: 1~128자 문자열(정수는 문자열로). **문자 집합을 좁히지 않는다** — office·manager(개명 전 aim) 관리자 키가
 * `"@admin"` 이다(D-331). 제어 문자만 거절하고 앞뒤 공백도 그대로 둔다(신원 키를 손대면 두 계정이 합쳐진다).
 * status: active|disabled|deleted, 그 밖의 문자열은 NULL 로 저장(스키마 COMMENT). 없으면 400.
 * deleted 면 email·name·extra·최종로그인을 저장하지 않는다(H-2 정리).
 */
function al_norm_account($a, string $f): array
{
    if (!is_array($a)) {
        v_fail($f, '계정 항목은 객체여야 합니다.');
    }
    $eid = array_key_exists('external_id', $a) ? $a['external_id'] : null;
    if (is_int($eid)) {
        $eid = (string) $eid;
    }
    if (!is_string($eid) || $eid === '' || !mb_check_encoding($eid, 'UTF-8')
        || mb_strlen($eid, 'UTF-8') > 128 || preg_match('/[\x00-\x1F\x7F]/', $eid) === 1) {
        v_fail($f . '.external_id', 'external_id는 1~128자 문자열이어야 합니다(제어 문자 불가).');
    }
    $stRaw = array_key_exists('status', $a) ? $a['status'] : null;
    if (!is_string($stRaw) || trim($stRaw) === '') {
        v_fail($f . '.status', 'status는 필수입니다(active|disabled|deleted).');
    }
    $st = strtolower(trim((string) $stRaw));
    $st = in_array($st, AL_EXT_STATUS, true) ? $st : null;

    $email = al_opt_str($a['email'] ?? null, 190);
    if ($email !== null) {
        $email = mb_strtolower($email, 'UTF-8');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $email = null;      // 쓸 수 없는 주소는 매칭 근거가 못 된다 — 저장하지 않는다
        }
    }
    $bound = $a['bound_uuid'] ?? null;
    $bound = al_is_uuid($bound) ? strtolower((string) $bound) : null;

    $row = [
        'external_id'       => $eid,
        'username'          => al_opt_str($a['username'] ?? null, 100),
        'email'             => $email,
        'name'              => al_opt_str($a['name'] ?? null, 80),
        'ext_status'        => $st,
        'ext_created_at'    => al_parse_iso($a['created_at'] ?? null),
        'ext_last_login_at' => al_parse_iso($a['last_login_at'] ?? null),
        'bound_uuid'        => $bound,
        'extra_json'        => al_norm_extra($a['extra'] ?? null),
        // v1.10.0 (설계 127 §6-3) — 시스템이 extra.kind="emergency" 로 보낸 계정. 표지는 시스템이 보낸 kind 만 믿는다
        // (아이디가 admin 이라는 이유로는 막지 않는다). 문자열 '0'|'1' — 스냅숏 비교(AL_SNAPSHOT_COLS)가 문자열이다.
        'is_emergency'      => (is_array($a['extra'] ?? null) && ($a['extra']['kind'] ?? null) === 'emergency') ? '1' : '0',
    ];
    if ($st === 'deleted') {
        $row['email']             = null;
        $row['name']              = null;
        $row['extra_json']        = null;
        $row['ext_last_login_at'] = null;
    }
    return $row;
}

/**
 * S-2 본문 → 정규화된 페이지. **push 와 파일 가져오기가 이 함수 하나를 쓴다**(H-4).
 * 차이는 `$fromFile` 두 가지뿐이다: sent_at ±300초 검사를 하지 않는다(내보낸 파일은 과거 시각이다) ·
 * sync_id·page 가 없으면 새 sync_id·1페이지로 본다. 계정 수 상한도 파일 쪽이 크다.
 *
 * @return array{sync_id:string,page:int,final:bool,sent_at:string,accounts:array}
 */
function al_validate_payload(array $in, bool $fromFile): array
{
    $syncRaw = in_raw($in, 'sync_id');
    if ($fromFile && ($syncRaw === null || $syncRaw === '')) {
        $syncId = gen_uuid4();
    } else {
        $syncId = (string) v_string($in, 'sync_id', 1, 64, true);
        if (preg_match('/^[A-Za-z0-9._:\-]{1,64}$/', $syncId) !== 1) {
            v_fail('sync_id', 'sync_id는 영문·숫자·._:- 1~64자여야 합니다(UUID 권장).');
        }
    }
    $pageRaw = in_raw($in, 'page');
    $page    = ($fromFile && ($pageRaw === null || $pageRaw === '')) ? 1 : (int) v_int($in, 'page', 1, 1000000, true);
    $final   = (bool) v_bool($in, 'final', false, false);

    $sentRaw = in_raw($in, 'sent_at');
    if ($fromFile && ($sentRaw === null || $sentRaw === '')) {
        $sentAt = now_utc();
    } else {
        $sentAt = al_parse_iso($sentRaw);
        if ($sentAt === null) {
            v_fail('sent_at', 'sent_at은 ISO8601 UTC(예: 2026-11-12T01:00:00Z)여야 합니다.');
        }
    }
    $skew = (int) ts($sentAt) - time();
    if (!$fromFile && abs($skew) > AL_SENT_AT_SKEW) {
        v_fail('sent_at', 'sent_at이 ulogin 시계와 ' . AL_SENT_AT_SKEW . '초 넘게 어긋납니다. 시계를 맞추세요.');
    }
    if ($fromFile && $skew > AL_SENT_AT_SKEW) {
        // 미래 시각 파일은 이후의 정상 push 를 「더 오래된 스냅숏」으로 만들어 막는다.
        v_fail('sent_at', 'sent_at이 미래 시각입니다.');
    }

    $acc = in_raw($in, 'accounts');
    if (!is_array($acc) || ($acc !== [] && array_keys($acc) !== range(0, count($acc) - 1))) {
        v_fail('accounts', 'accounts는 배열이어야 합니다.');
    }
    $max = $fromFile ? AL_FILE_MAX_ACCOUNTS : AL_PUSH_MAX_ACCOUNTS;
    if (count($acc) > $max) {
        v_fail('accounts', '한 번에 ' . $max . '개까지 보낼 수 있습니다. 페이지를 나누세요.');
    }
    $rows = [];
    $seen = [];
    foreach (array_values($acc) as $i => $a) {
        $r = al_norm_account($a, 'accounts[' . $i . ']');
        if (isset($seen[$r['external_id']])) {
            v_fail('accounts[' . $i . '].external_id', '같은 페이지에 같은 external_id가 두 번 있습니다.');
        }
        $seen[$r['external_id']] = true;
        $rows[] = $r;
    }
    return ['sync_id' => $syncId, 'page' => $page, 'final' => $final, 'sent_at' => $sentAt, 'accounts' => $rows];
}

// =====================================================================
// S-2 — 저장 (페이지 순서 · duplicate · 단조 · final)
// =====================================================================

const AL_SNAPSHOT_COLS = ['username', 'email', 'name', 'ext_status', 'ext_created_at', 'ext_last_login_at',
    'bound_uuid', 'extra_json', 'is_emergency'];

/**
 * 한 페이지를 저장한다. 재전송 방지 3겹(설계 B):
 *   ① sent_at ±300초 — al_validate_payload()
 *   ② (service_id, sync_id) UNIQUE + page 는 직전+1 만. 같은 번호 이하 = duplicate:true 무변경
 *   ③ 행 단위 source_sent_at 단조 — 더 오래된 스냅숏은 덮지 않는다
 * 한 페이지 = 트랜잭션 하나. final 이면 이번 전송에 없던 행에 gone_at(연결은 그대로).
 *
 * @param int|null $adminId 파일 가져오기면 그 관리자 id. push 는 null
 * @return array S-2 응답 data
 */
function al_ingest(array $svc, array $p, ?int $adminId): array
{
    $sid = (string) $svc['service_id'];
    return db_tx(function () use ($sid, $p, $adminId) {
        $now = now_utc();
        $res = ['sync_id' => $p['sync_id'], 'page' => $p['page'], 'accepted' => 0, 'created' => 0,
            'updated' => 0, 'unchanged' => 0, 'duplicate' => false, 'sync_requested' => false];

        $job = db_one('SELECT * FROM ' . T_LINK_JOBS . ' WHERE kind = ? AND service_id = ? AND sync_id = ?'
            . sql_for_update(), ['push', $sid, $p['sync_id']]);
        if ($job === null) {
            if ($p['page'] !== 1) {
                throw new AppError('VALIDATION_FAILED', 400, ['field' => 'page',
                    'message' => '페이지 순서가 맞지 않습니다. 1번을 보내세요.']);
            }
            try {
                $jobId = db_insert(T_LINK_JOBS, [
                    'job_uuid' => gen_uuid4(), 'kind' => 'push', 'service_id' => $sid, 'sync_id' => $p['sync_id'],
                    'status' => 'receiving', 'created_by' => $adminId, 'last_page' => 0,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            } catch (Throwable $e) {
                if (!db_is_duplicate($e)) {
                    throw $e;
                }
                // 같은 1페이지가 동시에 두 번 왔다 — 먼저 온 쪽이 저장한다. 이쪽은 재전송이다.
                $res['duplicate']      = true;
                $res['sync_requested'] = al_sync_requested($sid);
                return $res;
            }
            $job = ['id' => $jobId, 'last_page' => 0, 'status' => 'receiving', 'created_at' => $now];
        } else {
            $last = (int) $job['last_page'];
            if ($p['page'] <= $last) {
                $res['duplicate']      = true;
                $res['sync_requested'] = al_sync_requested($sid);
                return $res;
            }
            if ((string) $job['status'] === 'done') {
                throw new AppError('VALIDATION_FAILED', 400, ['field' => 'sync_id',
                    'message' => '이 전송은 final로 이미 끝났습니다. 새 sync_id로 보내세요.']);
            }
            if ($p['page'] !== $last + 1) {
                throw new AppError('VALIDATION_FAILED', 400, ['field' => 'page',
                    'message' => '페이지 순서가 맞지 않습니다. ' . ($last + 1) . '번을 보내세요.']);
            }
        }

        foreach ($p['accounts'] as $a) {
            $res['accepted']++;
            $cur = db_one('SELECT * FROM ' . T_EXT_ACCOUNTS . ' WHERE service_id = ? AND external_id = ?',
                [$sid, $a['external_id']]);
            if ($cur === null) {
                db_insert(T_EXT_ACCOUNTS, array_merge($a, [
                    'service_id' => $sid, 'state' => 'new', 'row_version' => 0,
                    'source_sent_at' => $p['sent_at'], 'last_sync_id' => $p['sync_id'],
                    'first_seen_at' => $now, 'updated_at' => $now,
                ]));
                $res['created']++;
                continue;
            }
            // ③ 단조 — 지금 가진 스냅숏이 더 새것이면 내용은 덮지 않는다. 「이번 전송에 있었다」만 남긴다
            //    (그래야 final 이 이 행을 사라짐으로 오판하지 않는다).
            if (strcmp((string) $cur['source_sent_at'], $p['sent_at']) > 0) {
                db_exec('UPDATE ' . T_EXT_ACCOUNTS . ' SET last_sync_id = ?, gone_at = NULL WHERE id = ?',
                    [$p['sync_id'], $cur['id']]);
                $res['unchanged']++;
                continue;
            }
            $diff = $cur['gone_at'] !== null;
            foreach (AL_SNAPSHOT_COLS as $c) {
                $old = $cur[$c] === null ? null : (string) $cur[$c];
                if ($old !== $a[$c]) {
                    $diff = true;
                    break;
                }
            }
            if ($diff) {
                db_update(T_EXT_ACCOUNTS, array_merge($a, [
                    'source_sent_at' => $p['sent_at'], 'last_sync_id' => $p['sync_id'],
                    'gone_at' => null, 'updated_at' => $now,
                ]), 'id = ?', [$cur['id']]);
                $res['updated']++;
            } else {
                db_exec('UPDATE ' . T_EXT_ACCOUNTS . ' SET source_sent_at = ?, last_sync_id = ? WHERE id = ?',
                    [$p['sent_at'], $p['sync_id'], $cur['id']]);
                $res['unchanged']++;
            }
        }

        $done = $p['final'];
        db_exec('UPDATE ' . T_LINK_JOBS . ' SET last_page = ?, item_count = item_count + ?, status = ?,'
            . ' updated_at = ?, finished_at = ? WHERE id = ?',
            [$p['page'], $res['accepted'], $done ? 'done' : 'receiving', $now, $done ? $now : null, $job['id']]);

        if ($done) {
            // 이번 전송(이 sync_id)에 없던 행 = 사라짐. 연결(user_id·state)은 건드리지 않는다 — 사라짐은 표식일 뿐이다.
            // 전체 전송 도중 끼어든 증분이 이미 보낸 행의 last_sync_id 를 덮었으면 그 행도 사라짐이 된다.
            // 다음 전체 전송이 바로잡고 연결에 영향이 없으므로 시각 비교로 막지 않는다(같은 초 경계에서 오판한다).
            db_exec('UPDATE ' . T_EXT_ACCOUNTS . ' SET gone_at = ?, updated_at = ? WHERE service_id = ?'
                . ' AND gone_at IS NULL AND (last_sync_id IS NULL OR last_sync_id <> ?)',
                [$now, $now, $sid, $p['sync_id']]);
            // 이 전송이 시작되기 전에 들어온 재수집 요청은 이것으로 채워졌다.
            db_exec('UPDATE ' . T_LINK_JOBS . ' SET status = ?, finished_at = ?, updated_at = ?'
                . ' WHERE kind = ? AND service_id = ? AND status = ? AND created_at <= ?',
                ['done', $now, $now, 'sync_request', $sid, 'requested', (string) $job['created_at']]);
        }
        $res['sync_requested'] = al_sync_requested($sid);
        return $res;
    });
}

// =====================================================================
// S-4 — 연결 사건 피드
// =====================================================================

/**
 * status=done 인 create_link·link·unlink 항목 = 사건. 커서는 항목 id.
 * uuid 는 사용자 행에서 읽고, 완전삭제로 행이 없으면 그 항목의 admin_logs 기록에서 읽는다
 * (자동 해제 항목은 지우기 **전에** 그 기록을 남긴다 — al_release_user()).
 */
function al_feed(string $sid, int $after, int $limit): array
{
    $rows = db_all('SELECT i.id, i.op, i.external_id, i.done_at, u.uuid FROM ' . T_LINK_ITEMS . ' i'
        . ' LEFT JOIN ' . T_USERS . ' u ON u.id = i.result_user_id'
        . ' WHERE i.service_id = ? AND i.status = ? AND i.op IN (?, ?, ?, ?, ?) AND i.id > ?'
        . ' ORDER BY i.id ASC LIMIT ' . (int) ($limit + 1),
        [$sid, 'done', 'create_link', 'link', 'unlink', 'em_verifier', 'em_revoke', $after]);
    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        array_pop($rows);
    }
    $events = [];
    $next   = $after;
    foreach ($rows as $r) {
        // v1.9.1 - 비상 확인값 사건(emergency/1). 값은 core/emergency.php 가 낸다. 건너뛰는 줄도 커서는 나아간다.
        if (in_array((string) $r['op'], ['em_verifier', 'em_revoke'], true)) {
            $next = (int) $r['id'];
            $evt  = em_feed_event($r);
            if ($evt !== null) {
                $events[] = $evt;
            }
            continue;
        }
        $uuid = $r['uuid'] === null ? null : (string) $r['uuid'];
        if ($uuid === null) {
            // v1.8.6 — QA Q-1(D-347): 연결(create_link·link) 항목은 before.uuid 가 null 이고 after.uuid 에 값이 있다.
            // 해제(unlink)는 반대다. 사건 종류에 맞는 쪽을 먼저 보고, 없으면 다른 쪽을 본다.
            $log = db_one('SELECT before_json, after_json FROM ' . T_ADMIN_LOGS . ' WHERE target_type = ? AND target_id = ?'
                . ' ORDER BY id ASC LIMIT 1', ['link_item', (string) $r['id']]);
            $b = json_col($log === null ? null : (string) $log['before_json']);
            $a = json_col($log === null ? null : (string) $log['after_json']);
            foreach (((string) $r['op'] === 'unlink') ? [$b, $a] : [$a, $b] as $j) {
                if (isset($j['uuid']) && is_string($j['uuid']) && $j['uuid'] !== '') {
                    $uuid = $j['uuid'];
                    break;
                }
            }
        }
        $events[] = [
            'event_id'    => (int) $r['id'],
            'type'        => ((string) $r['op'] === 'unlink') ? 'unlinked' : 'linked',
            'external_id' => (string) $r['external_id'],
            'uuid'        => $uuid,
            'at'          => iso($r['done_at']),
        ];
        $next = (int) $r['id'];
    }
    return ['events' => $events, 'next_after' => $next, 'has_more' => $hasMore];
}

// =====================================================================
// D절 — 첫 비밀번호 설정 링크
// =====================================================================

/**
 * 설정 토큰 1개. DB 에는 해시만. **원문이 든 URL 은 반환값으로만 나간다** —
 * admin_logs · 메일 폴더 · error_log 어디에도 쓰지 않는다(D-324). 호출자도 쓰지 마라.
 * @return array{url:string,expires_at:string}
 */
function al_issue_setup(int $userId): array
{
    $tok = gen_token(32);
    $exp = at_utc(AL_SETUP_TTL);
    db_insert(T_PW_RESETS, [
        'token_hash' => token_hash($tok),
        'user_id'    => $userId,
        'expires_at' => $exp,
        'created_ip' => client_ip(),
        'created_at' => now_utc(),
        'purpose'    => 'setup',
    ]);
    return [
        'url'        => base_url() . '/public/reset.html?token=' . rawurlencode($tok) . '&setup=1',
        'expires_at' => (string) iso($exp),
    ];
}

/** users.meta_json 의 setup_pending. */
function al_setup_pending(array $u): bool
{
    $m = json_col(isset($u['meta_json']) ? (string) $u['meta_json'] : null);
    return !empty($m['setup_pending']);
}

// =====================================================================
// E절 — 해제 · 자동 해제 · 서비스 정리
// =====================================================================

/** 해제 시 그 (사용자, 서비스)의 token 세션 회수 — 기존 Bearer 로 그 로컬 계정을 계속 쓰지 못하게. */
function al_revoke_link_sessions(int $userId, string $sid): int
{
    return db_exec('UPDATE ' . T_SESSIONS . ' SET revoked_at = ?, revoke_reason = ? WHERE user_id = ?'
        . ' AND service_id = ? AND auth_type = ? AND revoked_at IS NULL',
        [now_utc(), 'account_unlink', $userId, $sid, 'token']);
}

/**
 * 탈퇴·완전삭제 — 그 사용자의 연결 전부를 `kind='system'` 작업으로 해제하고 사건 피드에 남긴다.
 * **호출자의 트랜잭션 안에서 부른다**(db_tx 는 중첩 BEGIN 을 하지 않는다).
 * @return int 해제한 연결 수
 */
function al_release_user(int $userId, string $reason): int
{
    $rows = db_all('SELECT * FROM ' . T_EXT_ACCOUNTS . ' WHERE user_id = ? AND state = ?' . sql_for_update(),
        [$userId, 'linked']);
    if ($rows === []) {
        return 0;
    }
    $uuid = db_val('SELECT uuid FROM ' . T_USERS . ' WHERE id = ?', [$userId]);
    $now  = now_utc();
    $jobId = db_insert(T_LINK_JOBS, [
        'job_uuid' => gen_uuid4(), 'kind' => 'system', 'service_id' => null, 'sync_id' => null,
        'status' => 'done', 'created_by' => 0, 'item_count' => count($rows), 'ok_count' => count($rows),
        'plan_json' => json_encode(['reason' => substr($reason, 0, 40)]),
        'created_at' => $now, 'updated_at' => $now, 'finished_at' => $now,
    ]);
    $n = 0;
    foreach ($rows as $i => $r) {
        $changed = db_exec('UPDATE ' . T_EXT_ACCOUNTS . ' SET state = ?, user_id = NULL, last_user_id = ?,'
            . ' row_version = row_version + 1, updated_at = ? WHERE id = ? AND state = ?',
            ['unlinked', $userId, $now, $r['id'], 'linked']);
        if ($changed !== 1) {
            continue;
        }
        al_revoke_link_sessions($userId, (string) $r['service_id']);
        $itemId = db_insert(T_LINK_ITEMS, [
            'job_id' => $jobId, 'seq' => $i + 1, 'op' => 'unlink', 'service_id' => (string) $r['service_id'],
            'external_id' => (string) $r['external_id'], 'target_user_id' => $userId, 'expect_version' => (int) $r['row_version'],
            'verdict' => 'ok', 'status' => 'done', 'result_user_id' => $userId, 'created_at' => $now, 'done_at' => $now,
        ]);
        log_admin(0, 'account_link.unlink', 'link_item', (string) $itemId,
            ['state' => 'linked', 'uuid' => $uuid === null ? null : (string) $uuid,
             'service_id' => (string) $r['service_id'], 'external_id' => (string) $r['external_id']],
            ['state' => 'unlinked', 'reason' => $reason], 'system: ' . $reason);
        // v1.9.1 - 그 서비스의 비상 확인값도 폐기(emergency/1 1-1장). 같은 트랜잭션.
        em_revoke_user($userId, $reason === 'user_delete' ? 'deleted' : ($reason === 'withdraw' ? 'withdrawn' : 'unlinked'),
            (string) $r['service_id'], $uuid === null ? null : (string) $uuid);
        $n++;
    }
    return $n;
}

/**
 * 서비스 삭제 — 그 서비스의 외부 계정 스냅숏·미완 plan 항목·수신/재수집 작업을 지운다(H-2 정리).
 * 완료된 항목(사건 원장)은 남긴다. **호출자의 트랜잭션 안에서 부른다.**
 * @return int 지운 외부 계정 수
 */
function al_purge_service(string $sid): int
{
    // M-1(D-365): 그 서비스의 비상 확인값 행(봉인 ev 포함)도 같은 트랜잭션에서 지운다 — 같은 id 로 재등록해도 옛 값이 나가지 않는다.
    db_exec('DELETE FROM ' . T_EMERGENCY . ' WHERE service_id = ?', [$sid]);   // PURGE-EMERGENCY
    // v1.10.0 (설계 127 §1) — 그 서비스의 그룹 단계 행. 같은 id 로 재등록해도 옛 그룹 권한이 살아나지 않는다.
    if (defined('T_ACCESS_GROUP_SERVICES') && db_table_exists(T_ACCESS_GROUP_SERVICES)) {
        db_exec('DELETE FROM ' . T_ACCESS_GROUP_SERVICES . ' WHERE service_id = ?', [$sid]);
    }
    $n = db_exec('DELETE FROM ' . T_EXT_ACCOUNTS . ' WHERE service_id = ?', [$sid]);
    db_exec('DELETE FROM ' . T_LINK_ITEMS . ' WHERE service_id = ? AND status = ?', [$sid, 'planned']);
    db_exec('DELETE FROM ' . T_LINK_JOBS . ' WHERE service_id = ? AND kind IN (?, ?)', [$sid, 'push', 'sync_request']);
    return $n;
}

// =====================================================================
// GC (core/gc.php 가 부른다)
// =====================================================================

/** 만료 plan 7일(완료 항목 없는 것만) · push 작업 90일. done 항목은 피드 원장이라 지우지 않는다. */
function al_gc(): void
{
    $plan7 = at_utc(-7 * 86400);
    db_delete_limited(T_LINK_ITEMS, 'status <> ? AND job_id IN (SELECT id FROM ' . T_LINK_JOBS
        . ' WHERE kind = ? AND status IN (?, ?, ?) AND expires_at < ?)',
        ['done', 'plan', 'planned', 'expired', 'cancelled', $plan7], 1000);
    db_delete_limited(T_LINK_JOBS, 'kind = ? AND status IN (?, ?, ?) AND expires_at < ? AND id NOT IN (SELECT job_id FROM '
        . T_LINK_ITEMS . ')', ['plan', 'planned', 'expired', 'cancelled', $plan7], 200);
    db_delete_limited(T_LINK_JOBS, 'kind = ? AND created_at < ?', ['push', at_utc(-90 * 86400)], 200);
    em_gc();   // v1.9.1 - 비상 확인값 사건
}
