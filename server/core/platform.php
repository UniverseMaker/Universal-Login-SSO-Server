<?php
declare(strict_types=1);

/**
 * core/platform.php — 통합로그인 플랫폼 공용 규칙 (v1.9.0)
 * 담당 계약: `_workspace/116_architect_v190_platform.md` §0 · §1 A7 · §5 · §10 / 판정 D-354
 *
 * 여기 모인 것 — 플랫폼 경로 여러 곳이 **같은 규칙**을 써야 하는 것만:
 *   plat_secret_key()     비밀값 키 이름 가드 (A7 — 설정 키 · 로그 data · 감사 changes · 문서 필드). **정규식은 여기 한 곳**
 *   plat_redact()         로그 data · 감사 changes 의 서버 쪽 마스킹(0-8 ① 키 · ② 이메일)
 *   plat_ingest_*()       0-7 · 0-8 수집 공용(크기 → 본문 → 인증 → 빈도 → 검증 → 중복 → 트랜잭션 1개)
 *   plat_usage_add()      사용량(서비스 × UTC 일 × 지표). db_upsert_increment(..., $by)
 *   plat_quota()          서비스별 상한(platform_json.quota — 기본값보다 낮게만)
 *   plat_require_enabled() 전역 차단 스위치 `plat.enabled`
 *
 * 🔴 트랜잭션 안에는 INSERT/UPDATE 만 둔다(설계 §10-2). 검증·마스킹·JSON 직렬화는 밖에서.
 * 🔴 이 파일에 `FOR UPDATE`·`ON DUPLICATE` 리터럴을 쓰지 않는다(시험 ① 정적 검사).
 *
 * PHP 7.2 호환(core/bootstrap.php 상단 금지 목록).
 */

// 직접 실행 차단 — 리더 판정 D-34.
if (!defined('ULOGIN_BOOTSTRAPPED')) {
    header_remove('X-Powered-By');
    http_response_code(404);
    exit;
}

const PLAT_LEVELS          = ['debug' => 0, 'info' => 1, 'warn' => 2, 'error' => 3, 'fatal' => 4];
const PLAT_BATCH_MAX       = 100;        // 0-6 배치 ≤ 100건
const PLAT_USER_BATCH_MAX  = 20;         // §5 user 갈래 로그 배치 ≤ 20
const PLAT_BODY_MAX        = 262144;     // 0-6 본문 ≤ 256 KiB
const PLAT_ITEM_MAX        = 8192;       // 0-6 항목 1건 직렬화 ≤ 8 KiB
const PLAT_MSG_MAX         = 1000;
const PLAT_TAGS_MAX        = 8;
const PLAT_TS_PAST         = 1209600;    // 0-4 [지금-14일,
const PLAT_TS_FUTURE       = 300;        //       지금+5분]
const PLAT_CHANGES_MAX     = 32;         // 0-8 changes 최대 32필드
const PLAT_VALUE_MAX       = 200;        // 0-8 ⑥ 값 하나 ≤ 200자
/** 하루 상한(UTC) 기본값. 서비스 platform_json.quota 가 **낮추기만** 할 수 있다. */
const PLAT_QUOTA_DEFAULTS  = [
    'log_events'   => 200000,
    'audit_events' => 50000,
    'doc_reads'    => 200000,
    'doc_writes'   => 50000,
    // v1.9.0 보안 M190-3 (D-361) — user·anon 갈래는 **따로 센다**(기본 한도의 50%). 사용자 한 명이 하루 한도를
    // 태워 서비스 서버(s2s)의 쓰기·로그를 막지 못하게 한다. s2s 지표에는 user·anon 이 더해지지 않는다.
    'log_events_user' => 100000,
    'doc_reads_user'  => 100000,
    'doc_writes_user' => 25000,
];
/** 갈래별 지표 이름 — s2s 는 기본 이름, user·anon 은 `_user` 접미. */
function plat_metric_for(string $kind, string $metric): string
{
    return $kind === 'service' ? $metric : $metric . '_user';
}
/** 사용량 지표(설계 §3 plat_usage.metric). 이 밖의 이름은 기록하지 않는다. */
const PLAT_METRICS = ['log_events', 'log_bytes', 'audit_events', 'cfg_reads', 'doc_reads', 'doc_writes', 'denied',
                      'sso_userinfo', 'sso_users_subjects', 'log_events_user', 'doc_reads_user', 'doc_writes_user',
                      'sso_user_search',    // v1.10.0 (설계 127 §3-3)
                      'points_balance', 'points_earn', 'points_spend', 'points_history'];   // v1.10.1 (설계 130 §4-6)

/**
 * A7 · 0-8 ① — 키 이름에 이 낱말이 **들어가면**(대소문자 무시) 비밀값으로 본다.
 * 각 시스템이 같은 규칙으로 마스킹한다(계약 §0 에 같은 목록). **정규식은 이 상수 하나다.**
 * 「포함」이라 `mapping`(pin) · `monkey`(key) 도 걸린다 — 계약 문장 그대로다(넓게 막는 쪽).
 * v1.9.0 보안 L190-2 — `pwd|passcode|passphrase|sessid|session_?id|jwt|bearer` 를 더했다. 감사 제안의 `pass|sess` 는
 * `compass`·`assessment` 같은 일반 필드 이름까지 거절(설정 키·문서 필드는 400)하게 돼 구체 낱말로 좁혔다.
 */
const PLAT_SECRET_KEY_RE = '/password|passwd|secret|token|api_key|apikey|key|hash|otp|pin|credential|cookie|authorization|pwd|passcode|passphrase|sessid|session_?id|jwt|bearer/i';
const PLAT_REDACTED      = '[REDACTED]';
const PLAT_EMAIL_RE      = '/^([^@\s])[^@\s]*(@[^@\s]+\.[^@\s]+)$/u';

/** 비밀값 키 이름인가 (A7). */
function plat_secret_key(string $k): bool
{
    return preg_match(PLAT_SECRET_KEY_RE, $k) === 1;
}

/** 이메일 형태 문자열이면 `o***@example.com`. 아니면 null. (0-8 ②) */
function plat_mask_email(string $s): ?string
{
    $m = [];
    if (strlen($s) > 254 || preg_match(PLAT_EMAIL_RE, $s, $m) !== 1) {
        return null;
    }
    $masked = $m[1] . '***' . $m[2];
    return $masked === $s ? null : $masked;
}

/** 문자열 안의 이메일 형태 낱말을 전부 가린다(0-8 ②, 단어 단위). */
function plat_mask_emails_in(string $s): string
{
    if (strpos($s, '@') === false) {
        return $s;
    }
    return (string) preg_replace_callback('/[^\s@<>()"\',;:]+@[^\s@<>()"\',;:]+\.[A-Za-z]{2,}/u', function ($m) {
        $e = plat_mask_email($m[0]);
        return $e === null ? $m[0] : $e;
    }, $s);
}

/** v1.9.2 L190-3 — 배열 중첩이 $max 단을 넘는가(깊이 17 이상이면 참). 비밀 키 검사가 17단부터 눈이 먼 것을 막는다. */
function plat_depth_over($v, int $max = 16, int $d = 0): bool
{
    if (!is_array($v)) {
        return false;
    }
    if ($d >= $max) {
        return $v !== [];
    }
    foreach ($v as $x) {
        if (plat_depth_over($x, $max, $d + 1)) {
            return true;
        }
    }
    return false;
}

/**
 * 값 하나(재귀)를 서버 규칙으로 가린다. 바뀐 것이 있으면 $hit = true.
 *   · 비밀 키의 값 → "[REDACTED]" (이미 "[REDACTED]" 면 그대로 — 그쪽이 지킨 것이다)
 *   · 이메일 형태 문자열 → 로컬부 첫 글자 + ***
 * @param mixed $v
 * @return mixed
 */
function plat_redact($v, bool &$hit, int $depth = 0)
{
    if ($depth > 16) {
        $hit = true;
        return '[DEPTH]';
    }
    if (is_string($v)) {
        // L190-2 — 문자열 **안의** 이메일도 단어 단위로 가린다(통째 이메일만 보던 종전은 "to a@b.com" 을 놓쳤다)
        $m = plat_mask_emails_in($v);
        if ($m !== $v) {
            $hit = true;
        }
        return $m;
    }
    if (!is_array($v)) {
        return $v;
    }
    $out = [];
    foreach ($v as $k => $x) {
        if (is_string($k) && plat_secret_key($k)) {
            if ($x !== PLAT_REDACTED) {
                $hit = true;
            }
            $out[$k] = PLAT_REDACTED;
            continue;
        }
        $out[$k] = plat_redact($x, $hit, $depth + 1);
    }
    return $out;
}

/** 0-8 ⑥ — 값 하나 ≤ 200자, 객체·배열은 `"{keys:a,b}"` 요약. 서버가 한 번 더 강제한다. */
function plat_change_value($v)
{
    if (is_array($v)) {
        $keys = array_slice(array_map('strval', array_keys($v)), 0, 20);
        return mb_substr('{keys:' . implode(',', $keys) . '}', 0, PLAT_VALUE_MAX, 'UTF-8');
    }
    if (is_string($v) && mb_strlen($v, 'UTF-8') > PLAT_VALUE_MAX) {
        return mb_substr($v, 0, PLAT_VALUE_MAX, 'UTF-8');
    }
    return $v;
}

/**
 * 감사 changes `{필드:{before,after}}` 를 정규화·마스킹한다. 모양이 틀리면 null(→ invalid_field).
 * @return array|null
 */
function plat_norm_changes($c, bool &$hit): ?array
{
    if ($c === null) {
        return [];
    }
    if (!is_array($c) || ($c !== [] && array_keys($c) === range(0, count($c) - 1))) {
        return null;
    }
    if (count($c) > PLAT_CHANGES_MAX) {
        return null;
    }
    $out = [];
    foreach ($c as $field => $ba) {
        $f = (string) $field;
        if ($f === '' || mb_strlen($f, 'UTF-8') > 64 || !is_array($ba)) {
            return null;
        }
        $before = array_key_exists('before', $ba) ? $ba['before'] : null;
        $after  = array_key_exists('after', $ba) ? $ba['after'] : null;
        if (plat_secret_key($f)) {
            if ($before !== PLAT_REDACTED && $before !== null) {
                $hit = true;
            }
            if ($after !== PLAT_REDACTED && $after !== null) {
                $hit = true;
            }
            $out[$f] = ['before' => PLAT_REDACTED, 'after' => PLAT_REDACTED];
            continue;
        }
        $out[$f] = [
            'before' => plat_change_value(plat_redact($before, $hit)),
            'after'  => plat_change_value(plat_redact($after, $hit)),
        ];
    }
    return $out;
}

// =====================================================================
// 공용 소품
// =====================================================================

/** 사건 id — 소문자 8-4-4-4-12 (계약 0-4: UUIDv4 소문자). */
function plat_is_event_id($v): bool
{
    return is_string($v) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $v) === 1;
}

/**
 * ISO8601 → DB DATETIME(초 단위). 시각까지 있어야 한다. `Z` 또는 오프셋. 밀리초 허용·버림.
 * 모양이 아니면 null.
 */
function plat_parse_ts($v): ?string
{
    if (!is_string($v) || strlen($v) > 40) {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/', $v) !== 1) {
        return null;
    }
    $t = strtotime($v);
    return $t === false ? null : gmdate('Y-m-d H:i:s', $t);
}

/** 문자열 선택 필드: 제어 문자를 지우고 $max 로 자른다. 문자열이 아니면 null. */
function plat_opt_str($v, int $max): ?string
{
    if (is_int($v)) {
        $v = (string) $v;
    }
    if (!is_string($v)) {
        return null;
    }
    $s = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v));
    return $s === '' ? null : mb_substr($s, 0, $max, 'UTF-8');
}

/** JSON 직렬화 — 프로젝트 규약 플래그. 실패하면 null. */
function plat_json($v): ?string
{
    $j = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($j) ? $j : null;
}

/** 오늘(UTC) 'YYYY-MM-DD'. */
function plat_today(): string
{
    return gmdate('Y-m-d');
}

/** 다음 UTC 자정까지 남은 초. */
function plat_secs_to_midnight(): int
{
    return 86400 - (time() % 86400);
}

/**
 * 전역 차단 스위치(설정 `plat.enabled`, 폴백 상수 PLAT_ENABLED). 꺼져 있으면 403 FORBIDDEN.
 * 계약 0-5: 403 그 밖 = 「플랫폼 사용이 막힘」 → 1시간 간격 재시도.
 */
function plat_require_enabled(): void
{
    if (!setting_get('plat.enabled', (bool) PLAT_ENABLED)) {
        throw new AppError('FORBIDDEN', 403, ['message' => '통합로그인 플랫폼이 꺼져 있습니다.']);
    }
}

/**
 * 본문을 크기 상한과 함께 읽는다 — 헤더(힌트) → 본문 → 실측(chunked 도 같은 상한, D-204).
 * 넘으면 400 VALIDATION_FAILED field=body (계약 0-5: 배치를 반으로 나눠 다시).
 */
function plat_body(int $max = PLAT_BODY_MAX): array
{
    $clen = isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] !== '' ? (int) $_SERVER['CONTENT_LENGTH'] : -1;
    $msg  = '본문이 너무 큽니다. ' . $max . ' 바이트 이내로 나누세요.';
    if ($clen > $max) {
        throw new AppError('VALIDATION_FAILED', 400, ['field' => 'body', 'message' => $msg]);
    }
    $in  = body_json();
    $raw = body_raw_bytes();
    if ($raw === null) {
        $raw = strlen((string) plat_json($in));
    }
    if ($raw > $max) {
        throw new AppError('VALIDATION_FAILED', 400, ['field' => 'body', 'message' => $msg]);
    }
    return $in;
}

/** 서비스 platform_json 객체(잘못된 JSON 은 빈 객체 — 기본값만 쓴다). */
function plat_service_opts(array $svc): array
{
    $raw = $svc['platform_json'] ?? null;
    if ($raw === null || $raw === '') {
        return [];
    }
    $v = json_decode((string) $raw, true);
    if (!is_array($v)) {
        error_log('[ulogin] platform_json is not an object. service=' . (string) $svc['service_id']);
        return [];
    }
    return $v;
}

/** 하루 상한. 서비스가 정한 값은 기본값보다 **낮을 때만** 쓴다. */
function plat_quota(array $svc, string $metric): int
{
    $def = PLAT_QUOTA_DEFAULTS[$metric] ?? 0;
    $o   = plat_service_opts($svc);
    if (isset($o['quota'][$metric]) && is_int($o['quota'][$metric]) && $o['quota'][$metric] >= 0
        && $o['quota'][$metric] < $def) {
        return $o['quota'][$metric];
    }
    return $def;
}

/** 오늘 사용량. 표가 없거나 실패하면 0(상한 판정이 서비스를 세우지 않게 — 기록 실패는 로그). */
function plat_usage_today(string $sid, string $metric): int
{
    try {
        return (int) db_val('SELECT `value` FROM ' . T_PLAT_USAGE . ' WHERE service_id = ? AND `day` = ? AND metric = ?',
            [$sid, plat_today(), $metric]);
    } catch (Throwable $e) {
        error_log('[ulogin] plat usage read failed: ' . $e->getMessage());
        return 0;
    }
}

/** 사용량 증가. 트랜잭션 안에서 부르면 같이 커밋된다. $swallow 면 실패를 삼킨다(읽기 지표). */
function plat_usage_add(string $sid, string $metric, int $by = 1, bool $swallow = false): void
{
    if ($by <= 0 || !in_array($metric, PLAT_METRICS, true)) {
        return;
    }
    try {
        db_upsert_increment(T_PLAT_USAGE, ['service_id' => $sid, 'day' => plat_today(), 'metric' => $metric], 'value', [], [], $by);
    } catch (Throwable $e) {
        if (!$swallow) {
            throw $e;
        }
        error_log('[ulogin] plat usage add failed: ' . $e->getMessage());
    }
}

/** 하루 상한 확인 — 넘으면 403 QUOTA_EXCEEDED (계약 0-7). `$adding` 을 더한 값이 상한을 넘으면 거절. */
function plat_quota_check(array $svc, string $metric, int $adding, string $field, string $message = ''): void
{
    $q = plat_quota($svc, $metric);
    if (plat_usage_today((string) $svc['service_id'], $metric) + $adding > $q) {
        plat_usage_add((string) $svc['service_id'], 'denied', 1, true);
        throw new AppError('QUOTA_EXCEEDED', 403, ['field' => $field,
            'message' => $message !== '' ? $message : '오늘 전송 한도를 넘었습니다.']);
    }
}

// =====================================================================
// 0-7 로그 수집 — 항목 검증 (트랜잭션 밖)
// =====================================================================

/**
 * 로그 항목 하나 → 저장할 행. 거절이면 ['reject' => reason].
 * @param mixed $it
 * @return array ['row'=>…, 'redacted'=>bool, 'truncated'=>bool] | ['reject'=>string, 'event_id'=>?string]
 */
function plat_log_item($it, string $sid, string $source, ?string $forceUuid): array
{
    $eid = is_array($it) && isset($it['event_id']) && is_string($it['event_id']) ? $it['event_id'] : null;
    if (!is_array($it)) {
        return ['reject' => 'invalid_field', 'event_id' => null];
    }
    $ser = plat_json($it);
    if ($ser === null || strlen($ser) > PLAT_ITEM_MAX) {
        return ['reject' => 'item_too_large', 'event_id' => $eid];
    }
    if (!plat_is_event_id($eid)) {
        return ['reject' => 'invalid_event_id', 'event_id' => $eid];
    }
    $ts = plat_parse_ts($it['ts'] ?? null);
    if ($ts === null) {
        return ['reject' => 'invalid_ts', 'event_id' => $eid];
    }
    $t = (int) ts($ts);
    if ($t < time() - PLAT_TS_PAST || $t > time() + PLAT_TS_FUTURE) {
        return ['reject' => 'ts_out_of_range', 'event_id' => $eid];
    }
    $level = $it['level'] ?? null;
    if (!is_string($level) || !isset(PLAT_LEVELS[$level])) {
        return ['reject' => 'invalid_level', 'event_id' => $eid];
    }
    $event = $it['event'] ?? null;
    if (!is_string($event) || preg_match('/^[a-z0-9_.:-]{1,64}$/', $event) !== 1) {
        return ['reject' => 'invalid_event', 'event_id' => $eid];
    }
    // 선택 필드
    $truncated = false;
    $msg = null;
    if (array_key_exists('message', $it) && $it['message'] !== null) {
        if (!is_string($it['message'])) {
            return ['reject' => 'invalid_field', 'event_id' => $eid];
        }
        $msg = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $it['message']);
        if (mb_strlen($msg, 'UTF-8') > PLAT_MSG_MAX) {
            $msg = mb_substr($msg, 0, PLAT_MSG_MAX, 'UTF-8');
            $truncated = true;
        }
    }
    $tags = null;
    if (array_key_exists('tags', $it) && $it['tags'] !== null) {
        if (!is_array($it['tags']) || count($it['tags']) > PLAT_TAGS_MAX) {
            return ['reject' => 'invalid_field', 'event_id' => $eid];
        }
        $tt = [];
        foreach ($it['tags'] as $tg) {
            if (!is_string($tg) || preg_match('/^[a-z0-9_.:-]{1,32}$/', $tg) !== 1) {
                return ['reject' => 'invalid_field', 'event_id' => $eid];
            }
            $tt[$tg] = true;
        }
        $tags = $tt === [] ? null : ',' . implode(',', array_keys($tt)) . ',';
    }
    $uuid = null;
    if ($forceUuid !== null) {
        $uuid = $forceUuid;   // §5 user 갈래 — 토큰 주인으로 덮어쓴다
    } elseif (array_key_exists('uuid', $it) && $it['uuid'] !== null) {
        if (!is_string($it['uuid']) || preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $it['uuid']) !== 1) {
            return ['reject' => 'invalid_field', 'event_id' => $eid];
        }
        $uuid = strtolower($it['uuid']);
    }
    $hit  = false;
    $data = null;
    if (array_key_exists('data', $it) && $it['data'] !== null) {
        if (!is_array($it['data']) || ($it['data'] !== [] && array_keys($it['data']) === range(0, count($it['data']) - 1))) {
            return ['reject' => 'invalid_field', 'event_id' => $eid];
        }
        $data = plat_json(plat_redact($it['data'], $hit));
        if ($data === null) {
            return ['reject' => 'invalid_field', 'event_id' => $eid];
        }
    }
    if ($msg !== null) {
        // 메시지 본문 안의 이메일은 단어 단위로 가린다(키가 없으므로 ②만).
        $msg2 = plat_mask_emails_in($msg);
        if ($msg2 !== $msg) {
            $hit = true;
            $msg = $msg2;
        }
    }
    return [
        'row' => [
            'service_id'  => $sid,
            'event_id'    => $eid,
            'ts'          => $ts,
            'received_at' => now_utc(),
            'level'       => $level,
            'event'       => $event,
            'message'     => $msg,
            'tags'        => $tags,
            'uuid'        => $uuid,
            'local_user'  => plat_opt_str($it['local_user'] ?? null, 128),
            'request_id'  => plat_opt_str($it['request_id'] ?? null, 64),
            'source'      => $source,
            'sender_ip'   => client_ip(),
            'data_json'   => $data,
        ],
        'bytes'     => strlen($ser),
        'redacted'  => $hit,
        'truncated' => $truncated,
    ];
}

/**
 * 감사 항목 하나 → 행(체인 컬럼 제외). 거절이면 ['reject' => reason].
 * @param mixed $it
 */
function plat_audit_item($it, string $sid): array
{
    $eid = is_array($it) && isset($it['event_id']) && is_string($it['event_id']) ? $it['event_id'] : null;
    if (!is_array($it)) {
        return ['reject' => 'invalid_field', 'event_id' => null];
    }
    $ser = plat_json($it);
    if ($ser === null || strlen($ser) > PLAT_ITEM_MAX) {
        return ['reject' => 'item_too_large', 'event_id' => $eid];
    }
    if (!plat_is_event_id($eid)) {
        return ['reject' => 'invalid_event_id', 'event_id' => $eid];
    }
    $ts = plat_parse_ts($it['ts'] ?? null);
    if ($ts === null) {
        return ['reject' => 'invalid_ts', 'event_id' => $eid];
    }
    $t = (int) ts($ts);
    if ($t < time() - PLAT_TS_PAST || $t > time() + PLAT_TS_FUTURE) {
        return ['reject' => 'ts_out_of_range', 'event_id' => $eid];
    }
    $actor = $it['actor'] ?? null;
    if (!is_array($actor) || !in_array($actor['type'] ?? null, ['user', 'admin', 'system', 'service'], true)) {
        return ['reject' => 'invalid_field', 'event_id' => $eid];
    }
    $aUuid = null;
    if (isset($actor['uuid']) && $actor['uuid'] !== null) {
        if (!is_string($actor['uuid']) || preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $actor['uuid']) !== 1) {
            return ['reject' => 'invalid_field', 'event_id' => $eid];
        }
        $aUuid = strtolower($actor['uuid']);
    }
    $aLocal = plat_opt_str($actor['local_user'] ?? null, 128);
    if (in_array($actor['type'], ['user', 'admin'], true) && $aUuid === null && $aLocal === null) {
        return ['reject' => 'invalid_field', 'event_id' => $eid];
    }
    $action = $it['action'] ?? null;
    if (!is_string($action) || preg_match('/^[a-z0-9_.]{1,64}$/', $action) !== 1) {
        return ['reject' => 'invalid_field', 'event_id' => $eid];
    }
    $target = $it['target'] ?? null;
    if (!is_array($target) || !is_string($target['type'] ?? null) || preg_match('/^[a-z0-9_]{1,32}$/', (string) $target['type']) !== 1) {
        return ['reject' => 'invalid_field', 'event_id' => $eid];
    }
    $result = $it['result'] ?? null;
    if (!in_array($result, ['success', 'failure', 'denied'], true)) {
        return ['reject' => 'invalid_field', 'event_id' => $eid];
    }
    $hit = false;
    $changes = plat_norm_changes($it['changes'] ?? null, $hit);
    if ($changes === null) {
        return ['reject' => 'invalid_field', 'event_id' => $eid];
    }
    $ip = plat_opt_str($it['ip'] ?? null, 45);
    if ($ip !== null && filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return ['reject' => 'invalid_field', 'event_id' => $eid];
    }
    $tid = $target['id'] ?? null;
    return [
        'row' => [
            'service_id'   => $sid,
            'event_id'     => $eid,
            'ts'           => $ts,
            'received_at'  => now_utc(),
            'actor_type'   => (string) $actor['type'],
            'actor_uuid'   => $aUuid,
            'actor_local'  => $aLocal,
            'action'       => $action,
            'target_type'  => (string) $target['type'],
            'target_id'    => plat_opt_str($tid, 128),
            'result'       => (string) $result,
            'changes_json' => $changes === [] ? null : plat_json($changes),
            'ip'           => $ip,
            'user_agent'   => plat_opt_str($it['user_agent'] ?? null, 255),
            'request_id'   => plat_opt_str($it['request_id'] ?? null, 64),
            'reason'       => plat_opt_str($it['reason'] ?? null, 255),
            'sender_ip'    => client_ip(),
        ],
        'redacted' => $hit,
    ];
}

/** `(service_id, event_id)` 중 이미 있는 것 — 1회 SELECT (설계 §5 빠른 경로). */
function plat_existing_event_ids(string $table, string $sid, array $eids): array
{
    if ($eids === []) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($eids), '?'));
    $have = [];
    foreach (db_all('SELECT event_id FROM ' . $table . ' WHERE service_id = ? AND event_id IN (' . $ph . ')',
        array_merge([$sid], array_values($eids))) as $r) {
        $have[(string) $r['event_id']] = true;
    }
    return $have;
}

/**
 * 배치 검증 공통: events 배열 → [rows(인덱스 순), rejected[], counts]. 같은 배치 안의 중복 event_id 는 duplicate.
 * @param callable $itemFn function($item): array (plat_log_item / plat_audit_item 래퍼)
 */
function plat_batch_validate(array $in, int $max, callable $itemFn): array
{
    $events = $in['events'] ?? null;
    if (!is_array($events) || ($events !== [] && array_keys($events) !== range(0, count($events) - 1))) {
        throw new AppError('VALIDATION_FAILED', 400, ['field' => 'events', 'message' => 'events 는 배열이어야 합니다.']);
    }
    if (count($events) > $max) {
        throw new AppError('VALIDATION_FAILED', 400, ['field' => 'body',
            'message' => '한 번에 ' . $max . '건까지 보낼 수 있습니다. 배치를 나누세요.']);
    }
    $rows = [];
    $rejected = [];
    $redacted = 0;
    $truncated = 0;
    $bytes = 0;
    $seen = [];
    $dupInBatch = 0;
    foreach ($events as $i => $it) {
        $r = $itemFn($it);
        if (isset($r['reject'])) {
            $rejected[] = ['index' => (int) $i, 'event_id' => $r['event_id'], 'reason' => $r['reject']];
            continue;
        }
        $eid = (string) $r['row']['event_id'];
        if (isset($seen[$eid])) {
            $dupInBatch++;
            continue;
        }
        $seen[$eid] = true;
        $rows[] = $r['row'];
        $redacted  += !empty($r['redacted']) ? 1 : 0;
        $truncated += !empty($r['truncated']) ? 1 : 0;
        $bytes     += (int) ($r['bytes'] ?? 0);
    }
    return ['rows' => $rows, 'rejected' => $rejected, 'redacted' => $redacted, 'truncated' => $truncated,
            'bytes' => $bytes, 'dup_in_batch' => $dupInBatch];
}
