<?php
declare(strict_types=1);
/**
 * 전체 설정 번들 export / import — /api/admin/settings_bundle.php
 * 담당 계약: 설계 `_workspace/60_architect_v16_settings_spec.md` 5절 (계약서 §8-25 ~ §8-28 초안)
 * 실제 호출:
 *   GET  /api/admin/settings_bundle.php                    (export)
 *   POST /api/admin/settings_bundle.php?action=validate    (스키마 검증만. DB 를 읽지 않는다)
 *   POST /api/admin/settings_bundle.php?action=dry-run     (검증 + 현재 DB 대조. 계획 산출. 쓰기 0)
 *   POST /api/admin/settings_bundle.php?action=apply       (적용. 단일 트랜잭션)
 * 최소 역할: **네 동작 전부 superadmin** (설계 판정 3)
 *
 * 응답 최상위 키
 *   GET      : bundle, meta
 *   validate : valid, checked, warnings
 *   dry-run  : valid, plan_hash, plan, checked
 *   apply    : applied, plan_hash, result, warnings, admin_log_id
 *
 * ── 이 파일이 지키는 판정 (어기면 기능 자체가 뒤집힌다) ─────────────────
 *  · **export 는 시크릿 값을 절대 내보내지 않는다. import 는 받는다.** 비대칭이 의도다
 *    (설계 판정 2 / 리더 D-190). 이 파일의 사용 흐름은 "export 파일을 AI 대화창에
 *    붙여넣는 것" 이고, 그 흐름 자체가 유출 경로다.
 *  · **apply 응답에 `service_secret_plain` 을 싣지 않는다** (리더 D-192).
 *    원칙: **시크릿은 서버가 생성했고 그것이 볼 수 있는 유일한 기회일 때만 응답에 싣는다.**
 *    `api/admin/services.php` 의 POST·PUT 는 서버가 생성하므로 돌려주지 않으면 운영자가
 *    영원히 알 수 없다 — 그래서 싣는다. import 는 **번들이 값을 줬으므로** 운영자가 이미
 *    갖고 있고, 싣는 것은 응답 본문·프록시 로그·브라우저 히스토리에 자격증명 사본을
 *    하나 더 만들면서 얻는 정보가 0이다. `?action=reveal` 이 따로 있는 이유도 같다.
 *    **두 엔드포인트의 관행 차이는 불일치가 아니라 이 원칙의 결과다.**
 *  · **import 는 어떤 행도 삭제하지 않는다. INSERT 와 UPDATE 만 한다.**
 *    `plan.deletes_possible` 는 항상 false, `counts.deletes` 는 항상 0 — 값이 아니라 약속이다.
 *  · **`config.php`(층 A)를 쓰지 않는다.** export 만 읽기 전용으로 29개 상수를 싣는다.
 *  · **약관(층 E)은 읽지도 쓰지도 않는다** (리더 D-191).
 *  · **`gate_required` 메시지는 `allow_public` 을 해법으로 제시하지 않는다** (설계 판정 4-6).
 *    AI 는 오류 메시지를 읽고 가장 짧은 통과 경로를 택하므로, 알려 주면 이 기능이
 *    「민감 키의 게이트를 자동으로 벗기는 도구」가 된다.
 *  · **실효 공개로 가는 경로는 둘이고, 오류 코드와 선언은 하나다** (리더 판정 D-206).
 *    (1) 게이트가 없거나 각 축의 기본값 이하다(H-1) — (2) 게이트는 걸렸지만 같은 서비스의
 *    `default_account_level` 이 그 등급을 가입자 전원에게 자동으로 부여한다(H-2).
 *    둘 다 `gate_required` 로 **거절**하고, 둘 다 시크릿의 `allow_public: true` + 최상위
 *    `acknowledge_public_secrets` 로만 통과한다. 선언이 하나여야 하는 이유는 그것이
 *    DB `allow_public=1` 을 남겨 **게이트 점검 화면에 뜨게** 만드는 유일한 표시이기 때문이다.
 *
 * ── V14-H-1 · H-2 · H-3 이 여기서 재발하지 않는 이유 ────────────────────
 *  · **H-1(로그 폭증)**: 실패한 apply·validate 는 `admin_logs` 에 **한 행도 남기지 않는다.**
 *    실패는 `error_log()` 로만 간다. 요청 수에 비례해 커지는 표는 `rate_limits` 뿐이고
 *    그 표에는 `core/gc.php` 의 확률적 GC 가 이미 붙어 있다. 자체 카운터를 만들지 않는다.
 *  · **H-2(경쟁 조건)**: `expect_plan_hash` 낙관적 동시성 **+ 트랜잭션 안의 `FOR UPDATE`**.
 *    해시만으로는 두 apply 가 둘 다 커밋 전에 상태를 읽는 창이 남는다 — 그것이 H-2 와
 *    정확히 같은 읽기-수정-쓰기 형태다. 그래서 계획을 **잠근 읽기 위에서** 다시 세운다.
 *  · **H-3(FORCE_HTTPS 우회)**: 이 파일은 `core/bootstrap.php` 를 로드한다. H-3 은
 *    `migrate.php` 가 부트스트랩을 우회한 것이 원인이었고, 그 우회 이유(스키마가 코드보다
 *    낡은 상태에서 돌아야 한다)가 여기에는 없다. **부트스트랩을 우회하지 마라.**
 */
require_once __DIR__ . '/../../core/bootstrap.php';
// 판정 규칙은 전부 core 가 소유한다. 이 파일에 사본을 만들지 않는다 —
// 민감 키 정규식은 이미 install.php 와 두 곳에 있었고, v1.4.0 이 그 둘을 48키로 대조해야 했다.
require_once __DIR__ . '/../../core/secretguard.php';
require_once __DIR__ . '/../../core/settingsdef.php';
require_once __DIR__ . '/../../core/urlrules.php';

only_method('GET', 'POST');

// =====================================================================
// 상수 — 상한과 포맷 판별자
// =====================================================================

/** 번들 스키마 버전. 상위 마이너도 거절한다(조용한 하위 처리 금지). */
const SB_VERSION = '1.0';
/** 본문 상한. 설계 판정 3 안전장치 1. */
const SB_MAX_BODY = 1048576;
/** 오류 수집 상한. 넘으면 truncated. */
const SB_MAX_ERRORS = 200;
const SB_MAX_SERVICES = 100;
const SB_MAX_SECRETS_PER_SERVICE = 100;
const SB_MAX_SECRETS_TOTAL = 500;
const SB_MAX_ACK = 20;

/**
 * export 가 넣고 import 가 **받아서 무시하는** 최상위 예약 키.
 * 이 5개 밖의 모르는 최상위 키는 `unknown_key` 로 거절한다(설계 판정 4-2).
 */
const SB_RESERVED_TOP = ['generated_at', 'generator', 'environment', 'excluded', '_notes'];

/**
 * 같은 이유로 **객체 안에서** 받아서 무시하는 정보용 키.
 *
 * 설계 4-2 는 최상위 5개만 적었지만, export 의 `services[]` 에는 `has_secret` 이,
 * `secrets[]` 에는 `value_present` 가 들어간다(5-1 예시). 이 둘을 거절하면
 * **export 한 파일을 그대로 import 할 수 없다** — 번들 `_notes` 가 스스로 약속한
 * 왕복이 깨진다. 그래서 최상위 예약 키와 같은 방식으로 **닫힌 목록**에 넣는다.
 * 값을 읽지 않는다. 목록에 없는 키는 여전히 오류다.
 */
const SB_RESERVED_SERVICE = ['has_secret'];
const SB_RESERVED_SECRET  = ['value_present'];

// =====================================================================
// 감사 로그 헬퍼
//
// `admin_log_write()` 는 이 저장소의 관리자 엔드포인트 7개가 각자 같은 몸통을
// `function_exists` 가드로 갖고 있는 기존 관행이다. 여기서 8번째를 만든다 —
// **새로 발명한 패턴이 아니다.** 한 곳으로 합치는 것은 7개 파일을 함께 건드리는
// 별건이고, 이 라운드에서 하면 검증 담당이 도는 동안 같은 파일을 고치게 된다(D-173).
// 리포트의 「판단이 필요한 것」에 남긴다.
// =====================================================================

if (!function_exists('admin_log_write')) {
    function admin_log_write(int $adminId, string $action, string $targetType, ?string $targetId,
                             ?array $before = null, ?array $after = null, ?string $memo = null): ?int
    {
        log_admin($adminId, $action, $targetType, $targetId, $before, $after, $memo);
        $id = (int) db()->lastInsertId();
        if ($id <= 0) {
            return null;
        }
        $chk = db_val('SELECT id FROM ' . T_ADMIN_LOGS . ' WHERE id = ? AND admin_id = ? AND action = ?',
            [$id, $adminId, substr($action, 0, 60)]);
        return $chk === null ? null : (int) $chk;
    }
}

// =====================================================================
// 1. 오류 수집기 — 기존 v_* 를 쓰지 않는 이유
//
// `v_string()` `v_int()` `v_enum()` 은 **첫 오류에서 예외를 던진다.** 그 방식이면
// 필드 12개가 틀린 번들을 고치는 데 왕복 12회가 필요하고, AI 가 스스로 고쳐야 하는
// 이 기능에서 그것은 실패다(설계 판정 4-3). 그래서 오류를 배열로 모은다.
// PHP 7.2 문법만 쓴다 — 화살표 함수·`??=`·`enum` 금지.
// =====================================================================

/** JSON Pointer(RFC 6901). `~` → `~0`, `/` → `~1`. */
function sb_ptr(array $seg): string
{
    if ($seg === []) {
        return '/';
    }
    $out = '';
    foreach ($seg as $s) {
        $out .= '/' . str_replace(['~', '/'], ['~0', '~1'], (string) $s);
    }
    return $out;
}

/** 리스트인가(연속된 0..n-1 정수 키). JSON 배열/객체 구분에 쓴다. */
function sb_is_list(array $a): bool
{
    $i = 0;
    foreach ($a as $k => $unused) {
        if ($k !== $i) {
            return false;
        }
        $i++;
    }
    return true;
}

/**
 * `got` / `got_type` 쌍.
 *
 * **PHP 의 한계를 적어 둔다:** `body_json()` 은 `json_decode(..., true)` 를 쓰므로
 * JSON 의 `{}` 와 `[]` 가 **둘 다 빈 PHP 배열**이 된다. 그래서 빈 값 하나만으로는
 * 객체와 배열을 가를 수 없다. 이 검증기는 빈 배열을 **그 자리가 기대하는 쪽**으로
 * 읽는다(`settings: []` 는 빈 객체 = 무연산, `services: {}` 는 빈 배열 = 무연산).
 * 안전한 방향의 오차다 — 어느 쪽으로 읽어도 아무것도 바뀌지 않는다.
 *
 * @param mixed $v
 */
function sb_got($v): array
{
    if (is_array($v)) {
        return ['got' => sb_is_list($v) ? '[...]' : '{...}', 'got_type' => sb_is_list($v) ? 'array' : 'object'];
    }
    if ($v === null) {
        return ['got' => null, 'got_type' => 'null'];
    }
    if (is_bool($v)) {
        return ['got' => $v, 'got_type' => 'boolean'];
    }
    if (is_int($v)) {
        return ['got' => $v, 'got_type' => 'integer'];
    }
    if (is_float($v)) {
        return ['got' => $v, 'got_type' => 'number'];
    }
    $s = (string) $v;
    if (mb_strlen($s, 'UTF-8') > 200) {
        return ['got' => mb_substr($s, 0, 200, 'UTF-8'), 'got_type' => 'string', 'got_truncated' => true];
    }
    return ['got' => $s, 'got_type' => 'string'];
}

/** 키 자체가 없을 때. */
function sb_got_missing(): array
{
    return ['got' => null, 'got_type' => 'missing'];
}

/**
 * 시크릿 값 자리의 `got`.
 *
 * **평문을 절대 싣지 않는다.** 오류 응답은 화면·브라우저 콘솔·프록시 로그로 흐른다.
 * 타입만 참값으로 남긴다(설계 6-1).
 * @param mixed $v
 */
function sb_got_masked($v): array
{
    $t = sb_got($v);
    return ['got' => '***', 'got_type' => $t['got_type']];
}

/**
 * **원문을 `got` 에 실어서는 안 되는 필드 이름의 단일 목록.**
 *
 * ── 왜 목록으로 관리하는가 ────────────────────────────────────────────
 * v1.6.0 보안 감사 H-3(리더 판정 D-203): `secret_value` 자리는 `sb_got_masked()` 로
 * 막았는데 `service_secret` 자리 한 곳만 그 규약 밖에 있었다. `sb_check_string()` 이
 * 실패 시 `sb_got()` 로 원문 앞 200자를 담고, `service_secret` 이 그 함수를 쓴다.
 * 실측: `"service_secret":"topsecr"` → 응답의 `got` 에 `"topsecr"` 그대로.
 * 313자 값은 앞 200자가 실렸다 — 실제 클라이언트 시크릿(32~64자)이면 전량이다.
 * **한 자리를 막은 것이 다른 자리를 막았다는 뜻이 아니었다.** 그래서 「어느 필드가
 * 비밀 성격인가」를 여기 한 곳에 두고, 문자열 검사기가 그것을 참조한다.
 * 필드를 새로 더할 때 이 목록만 보면 된다.
 *
 * `secret_value` 는 자기 호출부에서 이미 `sb_got_masked()` 를 쓰지만(그 응답 모양은
 * 가이드 §7 예시가 `"got":"***"` 로 못박고 있다) 목록에도 이름을 남긴다 —
 * 다음 라운드가 그 필드를 `sb_check_string()` 으로 옮겨도 조용히 새지 않는다.
 */
const SB_SECRET_LABELS = ['service_secret', 'secret_value'];

/**
 * 자격증명 자리의 `got`. **값을 싣지 않고 길이만 알려 준다.**
 *
 * 길이는 자격증명이 아니고, `too_short`/`too_long` 을 고치는 데 필요한 유일한 정보다.
 * 문자열이 아니면 타입만 참값으로 남긴다(`sb_got_masked()` 와 같은 규약).
 * @param mixed $v
 */
function sb_got_secret($v): array
{
    if (!is_string($v)) {
        return sb_got_masked($v);
    }
    return ['got' => '*** (' . (string) mb_strlen($v, 'UTF-8') . ' chars)', 'got_type' => 'string'];
}

/**
 * 오류 1건 추가. 상한 SB_MAX_ERRORS 를 넘으면 더 담지 않는다(호출자가 truncated 를 본다).
 * 항목 키 순서는 설계 6-1 표 그대로다.
 */
function sb_add(array &$errs, string $path, string $code, string $message, string $expected,
                array $gotInfo, ?string $didYouMean = null): void
{
    if (count($errs) >= SB_MAX_ERRORS) {
        return;
    }
    $e = [
        'path'     => $path,
        'code'     => $code,
        'message'  => $message,
        'expected' => $expected,
        'got'      => $gotInfo['got'],
        'got_type' => $gotInfo['got_type'],
    ];
    if ($didYouMean !== null && $didYouMean !== '') {
        $e['did_you_mean'] = $didYouMean;
    }
    if (isset($gotInfo['got_truncated'])) {
        $e['got_truncated'] = true;
    }
    $errs[] = $e;
}

/**
 * 경고 1건. 경고는 적용을 막지 않는다. 코드는 **닫힌 7개** — 설계 6-4 그대로다
 * (`acknowledge_unused` · `empty_redirect_whitelist` · `maintenance_mode_on` ·
 *  `registration_disabled` · `secret_audit_off` · `secret_forced_disabled` ·
 *  `sensitive_secret_public`).
 *
 * **`gate_defeated_by_default_level` 는 없다.** 직전 라운드가 H-2 를 경고로 넣어 8개가
 * 됐는데, 리더 판정 D-206 이 그것을 **거절**(`gate_required`)로 바꿨다. 거절이 되면 그
 * 경고가 날 자리가 남지 않는다 —
 *   - 민감 키 + 무력화된 게이트 → `gate_required` (오류)
 *   - 같은 것 + 두 곳 선언 → `sensitive_secret_public` (기존 경고. 사실이 정확히 그것이다)
 *   - 다른 축에 실효 게이트가 있다 → 열리지 않으므로 신호 없음
 *   - **비민감 키 + 무력화된 게이트** → 신호 없음. 게이트 자체를 요구하지 않는 자리이므로
 *     (비민감 키는 게이트가 아예 없어도 아무 신호가 없다) 여기만 경고하면 규칙이 하나 늘고
 *     그 코드는 이 좁은 조건으로만 도달해 문서에서 먼저 낡는다
 * 그래서 코드를 **지웠다.** 도달 불가 코드를 남기면 문서가 그것을 설명해야 한다.
 *
 * 화면(`renderBundleWarnings()`)은 코드별 라벨 표 없이 `message`·`code`·`path` 를
 * 일반적으로 그리므로 코드 목록이 바뀌어도 렌더링은 영향받지 않는다.
 * 계약서·가이드는 이 라운드 코드 확정 뒤에 갱신한다(D-174).
 */
function sb_warn(array &$warnings, string $path, string $code, string $message): void
{
    $warnings[] = ['path' => $path, 'code' => $code, 'message' => $message];
}

/**
 * 오타 후보. Levenshtein 거리 ≤ 2 일 때만 돌려준다(설계 6-1).
 * `levenshtein()` 은 바이트 기반이라 한글에는 쓸모가 없다 — 그러나 이 함수가 비교하는
 * 것은 설정 키·열거값뿐이고 그것들은 전부 ASCII 다. 후보가 ASCII 가 아니면 건너뛴다.
 */
function sb_suggest(string $bad, array $candidates): ?string
{
    if ($bad === '' || strlen($bad) > 60) {
        return null;
    }
    $best = null;
    $bestD = 3;
    foreach ($candidates as $c) {
        if (!is_string($c) || $c === '' || preg_match('/^[\x20-\x7e]+$/', $c) !== 1) {
            continue;
        }
        $d = levenshtein(strtolower($bad), strtolower($c));
        if ($d < $bestD) {
            $bestD = $d;
            $best  = $c;
        }
    }
    return $best;
}

/** 열거값 목록 문자열. `expected` 형식을 한 곳에서 만든다. */
function sb_enum_expected(array $vals, bool $nullable): string
{
    $v = $vals;
    if ($nullable) {
        $v[] = 'null';
    }
    return 'one of: ' . implode(', ', $v);
}

// =====================================================================
// 2. 타입별 검사기 — 전부 「오류를 담고 계속 간다」
// =====================================================================

/**
 * @param mixed $val
 * @return bool|null 검증 통과 시 값, 실패면 null
 */
function sb_check_bool(array &$errs, string $path, $val): ?bool
{
    if (!is_bool($val)) {
        sb_add($errs, $path, 'wrong_type', '참/거짓(boolean)이어야 합니다. 문자열 "true" 가 아니라 JSON 의 true 를 쓰십시오.',
            'boolean', sb_got($val));
        return null;
    }
    return $val;
}

/**
 * @param mixed $val
 * @return int|null
 */
function sb_check_int(array &$errs, string $path, $val, ?int $min, ?int $max, string $label): ?int
{
    if (!is_int($val)) {
        sb_add($errs, $path, 'wrong_type', $label . ' 은(는) 정수여야 합니다.', 'integer', sb_got($val));
        return null;
    }
    if (($min !== null && $val < $min) || ($max !== null && $val > $max)) {
        sb_add($errs, $path, 'out_of_range',
            $label . ' 는 ' . (string) $min . ' 이상 ' . (string) $max . ' 이하의 정수여야 합니다.',
            'integer ' . (string) $min . '..' . (string) $max, sb_got($val));
        return null;
    }
    return $val;
}

/**
 * @param mixed $val
 * @return string|null
 */
function sb_check_string(array &$errs, string $path, $val, int $min, int $max, string $label): ?string
{
    // **H-3 / D-203.** 비밀 성격의 필드는 실패해도 원문을 응답에 싣지 않는다.
    // 목록은 SB_SECRET_LABELS 한 곳에만 있다 — `$label` 은 이 함수의 전 호출부에서
    // 필드 이름(설정 키 포함)이고, 설정 키 13개에는 비밀 성격의 이름이 없다.
    // 오류 코드·`expected`·`message` 는 그대로 둔다. `got` 만 마스킹한다.
    $secret = in_array($label, SB_SECRET_LABELS, true);
    if (!is_string($val)) {
        sb_add($errs, $path, 'wrong_type', $label . ' 은(는) 문자열이어야 합니다.', 'string',
            $secret ? sb_got_secret($val) : sb_got($val));
        return null;
    }
    $n = mb_strlen($val, 'UTF-8');
    if ($n > $max) {
        sb_add($errs, $path, 'too_long', $label . ' 은(는) ' . (string) $max . '자 이내여야 합니다.',
            'string, <=' . (string) $max . ' chars', $secret ? sb_got_secret($val) : sb_got($val));
        return null;
    }
    if ($n < $min) {
        sb_add($errs, $path, 'too_short', $label . ' 은(는) ' . (string) $min . '자 이상이어야 합니다.',
            'string, >=' . (string) $min . ' chars', $secret ? sb_got_secret($val) : sb_got($val));
        return null;
    }
    return $val;
}

/**
 * @param mixed $val
 * @return string|null
 */
function sb_check_enum(array &$errs, string $path, $val, array $allowed, string $label): ?string
{
    if (!is_string($val)) {
        sb_add($errs, $path, 'wrong_type', $label . ' 은(는) 문자열이어야 합니다.', 'string', sb_got($val));
        return null;
    }
    if (!in_array($val, $allowed, true)) {
        sb_add($errs, $path, 'not_in_enum', $label . ' 값이 올바르지 않습니다.',
            sb_enum_expected($allowed, false), sb_got($val), sb_suggest($val, $allowed));
        return null;
    }
    return $val;
}

/**
 * @param mixed $val
 * @return string|null
 */
function sb_check_pattern(array &$errs, string $path, $val, string $regex, string $message, string $label): ?string
{
    if (!is_string($val)) {
        sb_add($errs, $path, 'wrong_type', $label . ' 은(는) 문자열이어야 합니다.', 'string', sb_got($val));
        return null;
    }
    if (preg_match($regex, $val) !== 1) {
        sb_add($errs, $path, 'pattern_mismatch', $message, 'regex ' . $regex, sb_got($val));
        return null;
    }
    return $val;
}

/**
 * URL 배열. 항목 규칙은 core/urlrules.php 의 url_rule_check() 하나뿐이다.
 * @param mixed $val
 */
function sb_check_url_list(array &$errs, array $seg, $val, string $mode, string $label): array
{
    $path = sb_ptr($seg);
    if (!is_array($val)) {
        sb_add($errs, $path, 'not_an_array', $label . ' 은(는) 문자열 배열이어야 합니다.', 'array', sb_got($val));
        return [];
    }
    $out = [];
    $i   = 0;
    foreach ($val as $item) {
        $ip = sb_ptr(array_merge($seg, [(string) $i]));
        $i++;
        if (!is_string($item)) {
            sb_add($errs, $ip, 'wrong_type', '각 항목은 문자열이어야 합니다.', 'string', sb_got($item));
            continue;
        }
        $reason = null;
        $norm   = url_rule_check($item, $mode, $reason);
        if ($norm === null) {
            sb_add($errs, $ip, 'pattern_mismatch', (string) $reason,
                $mode === 'origin' ? 'absolute http(s) origin: scheme://host[:port]'
                                   : 'absolute http(s) URL without query string or fragment',
                sb_got($item));
            continue;
        }
        if ($norm === '') {
            continue;
        }
        if (!in_array($norm, $out, true)) {
            $out[] = $norm;
        }
    }
    if (count($out) > 50) {
        sb_add($errs, $path, 'too_many', $label . ' 항목은 50개 이내여야 합니다.', 'array, <=50 items',
            ['got' => count($out), 'got_type' => 'integer']);
    }
    return $out;
}

// =====================================================================
// 3. 번들 검증 — 오류 전량 수집
// =====================================================================

/**
 * 번들을 검증하고 **정규화된 실행용 표현**을 만든다.
 *
 * @param mixed $bundle       요청 본문의 `bundle`
 * @param array $errs         (out) 오류 배열
 * @param array $warnings     (out) DB 를 보지 않고 낼 수 있는 경고
 * @param bool  $gateFromBundleOnly
 *        true  = `?action=validate`. DB 를 읽지 않으므로 게이트 판정을 **번들 값만으로** 한다.
 *                부분 갱신 번들(게이트 필드를 생략한)에서는 `gate_required` 를 **과보고할 수 있다** —
 *                fail-closed 쪽의 오차이고, 설계 7-4 #11 이 그 동작을 명시적으로 요구한다.
 *                **H-2 조합 판정(D-206)은 반대 방향으로 틀린다** — `default_account_level` 이
 *                번들에 없고 DB 에만 있으면 여기서는 보이지 않아 **과소보고**한다.
 *                그 경우는 DB 를 읽는 dry-run/apply 가 잡아 거절한다. `apply` 는 트랜잭션 안에서
 *                계획을 다시 세우므로 「validate 만 통과시켜 우회」는 성립하지 않는다.
 *        false = dry-run / apply. 게이트 판정은 **DB 와 합친 실효 값**으로 계획 단계에서 한다.
 * @return array 정규화 표현
 */
function sb_validate($bundle, array &$errs, array &$warnings, bool $gateFromBundleOnly): array
{
    $norm = [
        'on_conflict' => 'merge',
        'settings'    => [],
        'services'    => [],
        'ack'         => [],
        'environment' => null,
    ];

    if (!is_array($bundle) || ($bundle !== [] && sb_is_list($bundle))) {
        sb_add($errs, '/', 'bundle_not_recognized',
            'ulogin 설정 번들이 아닙니다. 최상위에 "ulogin_settings_bundle": "1.0" 이 있어야 합니다.',
            'object with key "ulogin_settings_bundle"', sb_got($bundle));
        return $norm;
    }
    if (!array_key_exists('ulogin_settings_bundle', $bundle)) {
        sb_add($errs, '/', 'bundle_not_recognized',
            'ulogin 설정 번들이 아닙니다. 최상위에 "ulogin_settings_bundle": "1.0" 이 있어야 합니다.',
            'object with key "ulogin_settings_bundle"', sb_got_missing());
        return $norm;                                    // 다른 검증을 시작하지 않는다
    }
    $ver = $bundle['ulogin_settings_bundle'];
    if (!is_string($ver)) {
        sb_add($errs, '/ulogin_settings_bundle', 'wrong_type', '포맷 판별자는 문자열이어야 합니다.',
            '"' . SB_VERSION . '"', sb_got($ver));
        return $norm;
    }
    if ($ver !== SB_VERSION) {
        // 상위 마이너를 조용히 하위 처리하지 않는다 — 모르는 키를 무시하면
        // **사용자가 적은 설정이 반영되지 않았는데 성공을 본다.**
        sb_add($errs, '/ulogin_settings_bundle', 'bundle_version_unsupported',
            '이 서버가 처리할 수 있는 번들 버전은 ' . SB_VERSION . ' 뿐입니다. '
            . '상위 버전을 조용히 하위 처리하면 적은 설정이 반영되지 않은 채 성공으로 보입니다.',
            '"' . SB_VERSION . '"', sb_got($ver));
        return $norm;
    }

    // --- 최상위 키 -------------------------------------------------
    $topAllowed = array_merge(
        ['ulogin_settings_bundle', 'on_conflict', 'settings', 'services', 'acknowledge_public_secrets'],
        SB_RESERVED_TOP
    );
    foreach ($bundle as $k => $unused) {
        $key = (string) $k;
        if (in_array($key, $topAllowed, true)) {
            continue;
        }
        sb_add($errs, sb_ptr([$key]), 'unknown_key',
            '정의되지 않은 최상위 키입니다. 오타로 만든 키는 조용히 무시하지 않고 거절합니다.',
            sb_enum_expected($topAllowed, false), sb_got($key), sb_suggest($key, $topAllowed));
    }

    if (array_key_exists('on_conflict', $bundle)) {
        $oc = sb_check_enum($errs, '/on_conflict', $bundle['on_conflict'], ['merge', 'skip', 'fail'], 'on_conflict');
        if ($oc !== null) {
            $norm['on_conflict'] = $oc;
        }
    }

    // --- environment (읽고 무시한다. 다만 대조 재료로만 꺼내 둔다) ----
    if (array_key_exists('environment', $bundle) && is_array($bundle['environment'])
        && isset($bundle['environment']['constants']) && is_array($bundle['environment']['constants'])) {
        $norm['environment'] = $bundle['environment']['constants'];
    }

    // --- settings --------------------------------------------------
    $defs = settings_defs();
    if (array_key_exists('settings', $bundle)) {
        $s = $bundle['settings'];
        if (!is_array($s)) {
            sb_add($errs, '/settings', 'not_an_object', 'settings 는 객체여야 합니다.', 'object', sb_got($s));
        } elseif ($s !== [] && sb_is_list($s)) {
            sb_add($errs, '/settings', 'not_an_object', 'settings 는 키/값 객체여야 합니다. 배열이 아닙니다.',
                'object', sb_got($s));
        } else {
            $keys = array_keys($defs);
            foreach ($s as $k => $v) {
                $key  = (string) $k;
                $path = sb_ptr(['settings', $key]);
                if (!isset($defs[$key])) {
                    sb_add($errs, $path, 'unknown_key',
                        '정의되지 않은 설정 키입니다. 오타로 만든 키는 조용히 무시하지 않고 거절합니다.',
                        sb_enum_expected($keys, false), sb_got($key), sb_suggest($key, $keys));
                    continue;
                }
                $def = $defs[$key];
                if ($def[0] === 'bool') {
                    $val = sb_check_bool($errs, $path, $v);
                } elseif ($def[0] === 'int') {
                    $val = sb_check_int($errs, $path, $v, $def[3], $def[4], $key);
                } elseif (is_array($def[5])) {
                    $val = sb_check_enum($errs, $path, $v, $def[5], $key);
                } else {
                    $len = is_array($def[6]) ? $def[6] : [1, 100];
                    $val = sb_check_string($errs, $path, $v, (int) $len[0], (int) $len[1], $key);
                }
                if ($val !== null) {
                    $norm['settings'][$key] = $val;
                }
            }
        }
    }

    // --- acknowledge_public_secrets --------------------------------
    if (array_key_exists('acknowledge_public_secrets', $bundle)) {
        $a = $bundle['acknowledge_public_secrets'];
        if (!is_array($a) || ($a !== [] && !sb_is_list($a))) {
            sb_add($errs, '/acknowledge_public_secrets', 'not_an_array',
                'acknowledge_public_secrets 는 "<service_id>/<secret_key>" 문자열 배열이어야 합니다.',
                'array', sb_got($a));
        } else {
            if (count($a) > SB_MAX_ACK) {
                sb_add($errs, '/acknowledge_public_secrets', 'too_many',
                    'acknowledge_public_secrets 항목은 ' . (string) SB_MAX_ACK . '개 이내여야 합니다.',
                    'array, <=' . (string) SB_MAX_ACK . ' items', ['got' => count($a), 'got_type' => 'integer']);
            }
            $i = 0;
            foreach ($a as $item) {
                $ip = sb_ptr(['acknowledge_public_secrets', (string) $i]);
                $i++;
                $v = sb_check_pattern($errs, $ip, $item,
                    '~^[a-z0-9][a-z0-9\-_]{2,63}/[a-z0-9][a-z0-9_]{1,99}$~',
                    '"<service_id>/<secret_key>" 형식이어야 합니다.', 'acknowledge_public_secrets 항목');
                if ($v !== null && !in_array($v, $norm['ack'], true)) {
                    $norm['ack'][] = $v;
                }
            }
        }
    }

    // --- services --------------------------------------------------
    if (array_key_exists('services', $bundle)) {
        $svcs = $bundle['services'];
        if (!is_array($svcs) || ($svcs !== [] && !sb_is_list($svcs))) {
            sb_add($errs, '/services', 'not_an_array', 'services 는 객체 배열이어야 합니다.', 'array', sb_got($svcs));
        } else {
            if (count($svcs) > SB_MAX_SERVICES) {
                sb_add($errs, '/services', 'too_many',
                    'services 는 ' . (string) SB_MAX_SERVICES . '개 이내여야 합니다.',
                    'array, <=' . (string) SB_MAX_SERVICES . ' items',
                    ['got' => count($svcs), 'got_type' => 'integer']);
            }
            $seenSid    = [];
            $totalSecs  = 0;
            $ackTouched = [];
            $idx        = 0;
            foreach ($svcs as $svc) {
                $one = sb_validate_service($errs, $warnings, $svc, $idx, $seenSid, $totalSecs,
                    $ackTouched, $norm['ack'], $gateFromBundleOnly);
                $idx++;
                if ($one !== null) {
                    $norm['services'][] = $one;
                }
            }
            if ($totalSecs > SB_MAX_SECRETS_TOTAL) {
                sb_add($errs, '/services', 'too_many',
                    '번들 전체의 시크릿은 ' . (string) SB_MAX_SECRETS_TOTAL . '개 이내여야 합니다.',
                    'array, <=' . (string) SB_MAX_SECRETS_TOTAL . ' items',
                    ['got' => $totalSecs, 'got_type' => 'integer']);
            }
            // 목록에만 있고 대응하는 allow_public: true 가 없는 항목 — 경고다.
            // 게이트를 나중에 채운 뒤 목록을 지우는 것을 잊었을 뿐이고, 그것은 안전한 방향이다.
            foreach ($norm['ack'] as $ackItem) {
                if (!in_array($ackItem, $ackTouched, true)) {
                    sb_warn($warnings, '/acknowledge_public_secrets', 'acknowledge_unused',
                        $ackItem . ' 는 승인 목록에 있으나 대응하는 allow_public: true 가 없습니다. '
                        . '게이트를 채운 뒤 목록에서 지우지 않았을 수 있습니다.');
                }
            }
        }
    }

    return $norm;
}

/**
 * `services[$idx]` 하나. 실패해도 null 을 돌려주고 계속 간다.
 * @param mixed $svc
 * @return array|null
 */
function sb_validate_service(array &$errs, array &$warnings, $svc, int $idx, array &$seenSid,
                             int &$totalSecs, array &$ackTouched, array $ack, bool $gateFromBundleOnly)
{
    $seg  = ['services', (string) $idx];
    $path = sb_ptr($seg);
    if (!is_array($svc) || ($svc !== [] && sb_is_list($svc))) {
        sb_add($errs, $path, 'not_an_object', '서비스 항목은 객체여야 합니다.', 'object', sb_got($svc));
        return null;
    }

    $allowed = [
        'service_id', 'service_name', 'description', 'is_confidential', 'service_secret',
        'redirect_whitelist', 'allowed_origins', 'auto_join', 'allow_register',
        'default_account_level', 'session_ttl', 'refresh_ttl', 'payload_fields', 'status', 'secrets',
    ];
    $allowedAll = array_merge($allowed, SB_RESERVED_SERVICE);
    foreach ($svc as $k => $unused) {
        $key = (string) $k;
        if (in_array($key, $allowedAll, true)) {
            continue;
        }
        sb_add($errs, sb_ptr(array_merge($seg, [$key])), 'unknown_key',
            '서비스 객체에 정의되지 않은 키입니다.', sb_enum_expected($allowed, false),
            sb_got($key), sb_suggest($key, $allowed));
    }

    // --- service_id (멱등 키) --------------------------------------
    //
    // **service_id 가 틀려도 나머지 필드 검증을 멈추지 않는다.** 멈추면 AI 는 오류를
    // 파도로 받는다 — service_id 를 고쳐 다시 보내야 그제서야 시크릿의 게이트 오류를 본다.
    // 설계 6-2 의 기준 「한 번의 왕복으로 전부 알 수 있다」가 그 자리에서 깨진다.
    // 실행용 표현만 버리고(`$usable = false`) 오류 수집은 끝까지 간다.
    $sid    = null;
    $usable = true;
    if (!array_key_exists('service_id', $svc)) {
        sb_add($errs, sb_ptr(array_merge($seg, ['service_id'])), 'missing_required',
            'service_id 는 필수입니다. 기존 행을 찾는 멱등 키입니다.', 'string (required)', sb_got_missing());
        $usable = false;
    } else {
        $sid = sb_check_pattern($errs, sb_ptr(array_merge($seg, ['service_id'])), $svc['service_id'],
            '/^[a-z0-9][a-z0-9\-_]{2,63}$/',
            '서비스 ID 는 영문 소문자·숫자로 시작하고 소문자·숫자·-·_ 3~64자여야 합니다. 대문자는 쓸 수 없습니다.',
            'service_id');
        if ($sid === null) {
            $usable = false;
        } elseif (in_array($sid, $seenSid, true)) {
            // 조용히 마지막 것을 쓰지 않는다 — 어느 쪽이 이겨야 하는지 알 수 없다.
            sb_add($errs, $path, 'duplicate_in_bundle',
                '같은 service_id 가 번들 안에 두 번 나옵니다: ' . $sid, 'unique service_id',
                ['got' => $sid, 'got_type' => 'string']);
            $usable = false;
        } else {
            $seenSid[] = $sid;
        }
    }
    $sidLabel = $sid === null ? '(service_id 미상)' : $sid;

    $out = [
        'path'       => $path,
        'service_id' => (string) $sid,
        'fields'     => [],       // 번들이 **명시한** 필드만. merge 는 이것만 갱신한다
        'secret'     => null,     // service_secret 평문 (있으면)
        'secrets'    => [],
    ];

    // --- 나머지 필드 ------------------------------------------------
    if (!array_key_exists('service_name', $svc)) {
        sb_add($errs, sb_ptr(array_merge($seg, ['service_name'])), 'missing_required',
            'service_name 은 필수입니다.', 'string (required)', sb_got_missing());
    } else {
        $v = sb_check_string($errs, sb_ptr(array_merge($seg, ['service_name'])), $svc['service_name'], 1, 100, 'service_name');
        if ($v !== null) {
            $out['fields']['service_name'] = $v;
        }
    }
    if (array_key_exists('description', $svc)) {
        if ($svc['description'] === null) {
            $out['fields']['description'] = null;
        } else {
            $v = sb_check_string($errs, sb_ptr(array_merge($seg, ['description'])), $svc['description'], 0, 255, 'description');
            if ($v !== null) {
                $out['fields']['description'] = $v;
            }
        }
    }
    foreach (['is_confidential', 'auto_join', 'allow_register'] as $bk) {
        if (array_key_exists($bk, $svc)) {
            $v = sb_check_bool($errs, sb_ptr(array_merge($seg, [$bk])), $svc[$bk]);
            if ($v !== null) {
                $out['fields'][$bk] = $v ? 1 : 0;
            }
        }
    }
    if (array_key_exists('service_secret', $svc)) {
        // **평문 자격증명 자리다.** `sb_check_string()` 이 SB_SECRET_LABELS 를 보고
        // `got` 을 `*** (N chars)` 로 바꾼다 — 원문은 응답에 실리지 않는다(H-3 / D-203).
        $v = sb_check_string($errs, sb_ptr(array_merge($seg, ['service_secret'])), $svc['service_secret'], 8, 255, 'service_secret');
        if ($v !== null) {
            $out['secret'] = $v;
        }
    }
    if (array_key_exists('redirect_whitelist', $svc)) {
        $list = sb_check_url_list($errs, array_merge($seg, ['redirect_whitelist']),
            $svc['redirect_whitelist'], 'redirect', 'redirect_whitelist');
        $out['fields']['redirect_whitelist'] = json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $statusHere = array_key_exists('status', $svc) && is_string($svc['status']) ? $svc['status'] : 'active';
        if ($list === [] && $statusHere === 'active') {
            // 오류가 아니다 — 서버-투-서버 전용 서비스는 유효한 구성이다.
            sb_warn($warnings, $path, 'empty_redirect_whitelist',
                $sidLabel . ' 의 redirect_whitelist 가 비어 있고 status 가 active 입니다. '
                . 'redirect_uri 는 완전 일치 비교라 이 서비스로는 SSO 가 완료될 수 없습니다.');
        }
    }
    if (array_key_exists('allowed_origins', $svc)) {
        $list = sb_check_url_list($errs, array_merge($seg, ['allowed_origins']),
            $svc['allowed_origins'], 'origin', 'allowed_origins');
        $out['fields']['allowed_origins'] = json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if (array_key_exists('default_account_level', $svc)) {
        if ($svc['default_account_level'] === null) {
            $out['fields']['default_account_level'] = null;
        } else {
            $v = sb_check_enum($errs, sb_ptr(array_merge($seg, ['default_account_level'])),
                $svc['default_account_level'], array_keys(ACCOUNT_LEVEL_RANK), 'default_account_level');
            if ($v !== null) {
                $out['fields']['default_account_level'] = $v;
            }
        }
    }
    if (array_key_exists('session_ttl', $svc)) {
        $v = sb_check_int($errs, sb_ptr(array_merge($seg, ['session_ttl'])), $svc['session_ttl'], 60, 604800, 'session_ttl');
        if ($v !== null) {
            $out['fields']['session_ttl'] = $v;
        }
    }
    if (array_key_exists('refresh_ttl', $svc)) {
        $v = sb_check_int($errs, sb_ptr(array_merge($seg, ['refresh_ttl'])), $svc['refresh_ttl'], 300, 31536000, 'refresh_ttl');
        if ($v !== null) {
            $out['fields']['refresh_ttl'] = $v;
        }
    }
    if (array_key_exists('status', $svc)) {
        $v = sb_check_enum($errs, sb_ptr(array_merge($seg, ['status'])), $svc['status'], ['active', 'disabled'], 'status');
        if ($v !== null) {
            $out['fields']['status'] = $v;
        }
    }
    if (array_key_exists('payload_fields', $svc)) {
        if ($svc['payload_fields'] === null) {
            $out['fields']['payload_fields'] = null;          // NULL = 기본셋
        } elseif (!is_array($svc['payload_fields']) || ($svc['payload_fields'] !== [] && !sb_is_list($svc['payload_fields']))) {
            sb_add($errs, sb_ptr(array_merge($seg, ['payload_fields'])), 'not_an_array',
                'payload_fields 는 문자열 배열 또는 null 이어야 합니다.', 'array', sb_got($svc['payload_fields']));
        } else {
            $pf = [];
            $i  = 0;
            foreach ($svc['payload_fields'] as $f) {
                $fp = sb_ptr(array_merge($seg, ['payload_fields', (string) $i]));
                $i++;
                $v = sb_check_pattern($errs, $fp, $f, '/^[a-z0-9_]{1,50}$/',
                    '필드명은 영문 소문자·숫자·밑줄 1~50자여야 합니다. 대문자는 쓸 수 없습니다.', 'payload_fields 항목');
                if ($v !== null && !in_array($v, $pf, true)) {
                    $pf[] = $v;
                }
            }
            $out['fields']['payload_fields'] = json_encode($pf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    // --- secrets ---------------------------------------------------
    if (array_key_exists('secrets', $svc)) {
        $secs = $svc['secrets'];
        if (!is_array($secs) || ($secs !== [] && !sb_is_list($secs))) {
            sb_add($errs, sb_ptr(array_merge($seg, ['secrets'])), 'not_an_array',
                'secrets 는 객체 배열이어야 합니다.', 'array', sb_got($secs));
        } else {
            if (count($secs) > SB_MAX_SECRETS_PER_SERVICE) {
                sb_add($errs, sb_ptr(array_merge($seg, ['secrets'])), 'too_many',
                    '서비스 하나의 시크릿은 ' . (string) SB_MAX_SECRETS_PER_SERVICE . '개 이내여야 합니다.',
                    'array, <=' . (string) SB_MAX_SECRETS_PER_SERVICE . ' items',
                    ['got' => count($secs), 'got_type' => 'integer']);
            }
            $totalSecs += count($secs);
            // H-2 / D-206 — `?action=validate` 는 DB 를 읽지 않으므로 **번들에 적힌 값만**으로
            // 자동 부여 등급을 잡는다. `auto_join`·`allow_register` 가 번들에 없으면
            // 스키마 기본값 1 을 가정한다(fail-closed — 이쪽으로 틀리면 과보고이고,
            // 과보고는 설계 7-4 #11 이 이 동작에 대해 이미 허용한 방향이다).
            // **`default_account_level` 이 번들에 없고 DB 에만 있으면 여기서는 잡히지 않는다** —
            // 그 경우는 DB 를 읽는 dry-run/apply 가 잡고 거절한다. `apply` 는 트랜잭션 안에서
            // 계획을 다시 세우므로 「validate 만 통과시켜 우회」는 성립하지 않는다.
            $autoLevel = sb_service_auto_level($out['fields'], null, true);
            $seenKey = [];
            $j = 0;
            foreach ($secs as $sec) {
                $one = sb_validate_secret($errs, $warnings, $sec, $seg, $j, (string) $sid, $seenKey,
                    $ackTouched, $ack, $gateFromBundleOnly, $autoLevel);
                $j++;
                if ($one !== null) {
                    $out['secrets'][] = $one;
                }
            }
        }
    }

    return $usable ? $out : null;
}

/**
 * `services[$i].secrets[$j]` 하나.
 * @param mixed $sec
 * @return array|null
 */
function sb_validate_secret(array &$errs, array &$warnings, $sec, array $svcSeg, int $j, string $sid,
                            array &$seenKey, array &$ackTouched, array $ack, bool $gateFromBundleOnly,
                            ?string $autoLevel = null)
{
    $seg  = array_merge($svcSeg, ['secrets', (string) $j]);
    $path = sb_ptr($seg);
    if (!is_array($sec) || ($sec !== [] && sb_is_list($sec))) {
        sb_add($errs, $path, 'not_an_object', '시크릿 항목은 객체여야 합니다.', 'object', sb_got($sec));
        return null;
    }

    $allowed = [
        'secret_key', 'secret_value', 'value_type', 'min_account_level', 'min_billing_tier',
        'min_role', 'is_masked', 'allow_public', 'sort_order', 'description', 'status',
    ];
    $allowedAll = array_merge($allowed, SB_RESERVED_SECRET);
    foreach ($sec as $k => $unused) {
        $key = (string) $k;
        if (in_array($key, $allowedAll, true)) {
            continue;
        }
        sb_add($errs, sb_ptr(array_merge($seg, [$key])), 'unknown_key',
            '시크릿 객체에 정의되지 않은 키입니다.', sb_enum_expected($allowed, false),
            sb_got($key), sb_suggest($key, $allowed));
    }

    // secret_key 가 틀려도 나머지 필드 검증을 멈추지 않는다 — 위 sb_validate_service() 와 같은 이유다.
    $key    = null;
    $usable = true;
    if (!array_key_exists('secret_key', $sec)) {
        sb_add($errs, sb_ptr(array_merge($seg, ['secret_key'])), 'missing_required',
            'secret_key 는 필수입니다. 서비스 안에서 기존 행을 찾는 멱등 키입니다.',
            'string (required)', sb_got_missing());
        $usable = false;
    } else {
        $key = sb_check_pattern($errs, sb_ptr(array_merge($seg, ['secret_key'])), $sec['secret_key'],
            '/^[a-z0-9][a-z0-9_]{1,99}$/',
            '시크릿 키는 영문 소문자·숫자로 시작하고 소문자·숫자·밑줄 2~100자여야 합니다.', 'secret_key');
        if ($key === null) {
            $usable = false;
        } elseif (in_array($key, $seenKey, true)) {
            sb_add($errs, $path, 'duplicate_in_bundle',
                '같은 secret_key 가 이 서비스 안에 두 번 나옵니다: ' . $key, 'unique (service_id, secret_key)',
                ['got' => $key, 'got_type' => 'string']);
            $usable = false;
        } else {
            $seenKey[] = $key;
        }
    }

    $out = [
        'path'        => $path,
        'secret_key'  => (string) $key,
        'fields'      => [],
        'value'       => null,     // 문자열이면 그 값, null 이면 "값을 주지 않았다"
        'value_given' => false,
        'allow_public' => false,
    ];

    $valueType = 'string';
    if (array_key_exists('value_type', $sec)) {
        $v = sb_check_enum($errs, sb_ptr(array_merge($seg, ['value_type'])), $sec['value_type'],
            ['string', 'json', 'url'], 'value_type');
        if ($v !== null) {
            $out['fields']['value_type'] = $v;
            $valueType = $v;
        }
    }
    if (array_key_exists('secret_value', $sec) && $sec['secret_value'] !== null) {
        if (!is_string($sec['secret_value'])) {
            // **평문 자리이므로 got 을 마스킹한다.** 타입만 참값이다.
            sb_add($errs, sb_ptr(array_merge($seg, ['secret_value'])), 'wrong_type',
                'secret_value 는 문자열 또는 null 이어야 합니다.', 'string', sb_got_masked($sec['secret_value']));
        } else {
            $reason = null;
            if (!secret_value_rule_check($sec['secret_value'], $valueType, $reason)) {
                sb_add($errs, sb_ptr(array_merge($seg, ['secret_value'])), 'wrong_type', (string) $reason,
                    $valueType === 'json' ? 'string parseable as JSON' : 'absolute http(s) URL',
                    sb_got_masked($sec['secret_value']));
            } else {
                $out['value']       = $sec['secret_value'];
                $out['value_given'] = true;
            }
        }
    }

    foreach (['min_account_level' => ACCOUNT_LEVEL_RANK,
              'min_billing_tier'  => BILLING_TIER_RANK,
              'min_role'          => ROLE_RANK] as $gk => $rankMap) {
        if (!array_key_exists($gk, $sec)) {
            continue;
        }
        if ($sec[$gk] === null) {
            $out['fields'][$gk] = null;
            continue;
        }
        $vals = array_keys($rankMap);
        if (!is_string($sec[$gk])) {
            sb_add($errs, sb_ptr(array_merge($seg, [$gk])), 'wrong_type', $gk . ' 은(는) 문자열 또는 null 이어야 합니다.',
                'string', sb_got($sec[$gk]));
            continue;
        }
        if (!in_array($sec[$gk], $vals, true)) {
            sb_add($errs, sb_ptr(array_merge($seg, [$gk])), 'not_in_enum', $gk . ' 값이 올바르지 않습니다.',
                sb_enum_expected($vals, true), sb_got($sec[$gk]), sb_suggest($sec[$gk], $vals));
            continue;
        }
        $out['fields'][$gk] = $sec[$gk];
    }

    if (array_key_exists('is_masked', $sec)) {
        $v = sb_check_bool($errs, sb_ptr(array_merge($seg, ['is_masked'])), $sec['is_masked']);
        if ($v !== null) {
            $out['fields']['is_masked'] = $v ? 1 : 0;
        }
    }
    if (array_key_exists('sort_order', $sec)) {
        $v = sb_check_int($errs, sb_ptr(array_merge($seg, ['sort_order'])), $sec['sort_order'], -9999, 9999, 'sort_order');
        if ($v !== null) {
            $out['fields']['sort_order'] = $v;
        }
    }
    if (array_key_exists('description', $sec)) {
        if ($sec['description'] === null) {
            $out['fields']['description'] = null;
        } else {
            $v = sb_check_string($errs, sb_ptr(array_merge($seg, ['description'])), $sec['description'], 0, 255, 'description');
            if ($v !== null) {
                $out['fields']['description'] = $v;
            }
        }
    }
    if (array_key_exists('status', $sec)) {
        $v = sb_check_enum($errs, sb_ptr(array_merge($seg, ['status'])), $sec['status'], ['active', 'disabled'], 'status');
        if ($v !== null) {
            $out['fields']['status'] = $v;
        }
    }
    if (array_key_exists('allow_public', $sec)) {
        $v = sb_check_bool($errs, sb_ptr(array_merge($seg, ['allow_public'])), $sec['allow_public']);
        if ($v === true) {
            $out['allow_public'] = true;
            $ackTouched[] = $sid . '/' . (string) $key;
        }
    }

    if ($gateFromBundleOnly && $key !== null) {
        sb_gate_judge($errs, $warnings, $path, $sid, $key,
            array_key_exists('min_account_level', $out['fields']) ? $out['fields']['min_account_level'] : null,
            array_key_exists('min_billing_tier', $out['fields']) ? $out['fields']['min_billing_tier'] : null,
            array_key_exists('min_role', $out['fields']) ? $out['fields']['min_role'] : null,
            $out['allow_public'], $ack, $autoLevel);
    }

    return $usable ? $out : null;
}

/**
 * D-54 / D-47 게이트 강제. **이 기능이 게이트 우회로가 되지 않게 하는 자리다.**
 *
 * 설계 판정 4-6 — **`allow_public` 을 해법으로 제시하지 않는다.**
 * `api/admin/secrets.php` 의 `admin_secret_guard_gate()` 는 사람에게 「의도한 공개라면
 * allow_public=true 를 함께 보내십시오」라고 알려 준다. 사람에게는 맞는 문구다.
 * **AI 는 오류 메시지를 읽고 가장 짧은 통과 경로를 택한다** — 게이트를 거는 대신 그 플래그를
 * 붙인다. 그러면 이 기능은 「민감 키의 게이트를 자동으로 벗기는 도구」가 된다.
 * 그래서 여기서는 `min_billing_tier` 를 거는 것만 해법으로 제시한다.
 *
 * 그리고 **`allow_public: true` 는 두 곳에 적어야 통과한다** — 시크릿 객체의 그 필드와
 * 번들 최상위 `acknowledge_public_secrets` 목록. 한 곳이면 오류 메시지에 대한 패턴
 * 반응으로 채워진다. 두 곳이면 번들 전체를 보고 사람이 의도적으로 적어야 한다.
 * 목록을 언급하는 문구는 **이미 `allow_public: true` 를 적은 요청에만** 나간다 —
 * 그렇지 않은 요청에는 그 우회로의 존재 자체를 알리지 않는다.
 *
 * ── 실효 공개로 가는 **두 번째 경로**도 이 자리로 모은다 (H-2 / 리더 판정 D-206) ──
 * `min_account_level: "vip"` + 같은 서비스 `default_account_level: "vip"` 조합은
 * 게이트가 걸린 것처럼 보이면서 **가입한 전원을 통과**시킨다(근거는
 * `secret_gate_level_defeated_by_auto()` 독스트링). 직전 라운드는 그것을 **경고**로 냈고,
 * 「운영자가 apply 하면 키는 여전히 전원에게 나간다」가 남았다.
 * **경고는 읽히지 않는다** — 이 프로젝트가 `fail_ratelimit`(참조 4곳 · 기록 0곳)과
 * 게이트 점검 화면에서 반복 확인했다. 그래서 **거절**로 바꿨다.
 *
 * **오류 코드를 새로 만들지 않고 `gate_required` 를 재사용한다.** 그 조합이 실제로 뜻하는
 * 것은 「이 서비스에 가입한 전원이 이 키를 받는다」이고, 그것은 게이트가 없는 것과 **같은
 * 사실**이다. 사실이 같으면 코드도 같아야 한다 — 같은 사실에 두 코드를 주면 AI 는 두 규칙을
 * 배우고, 그중 하나만 문서에 남으면 다른 하나가 낡는다.
 *
 * **통과 방법도 새로 만들지 않는다** — 시크릿의 `allow_public: true` + 최상위
 * `acknowledge_public_secrets`, 기존과 **같은 경로**다. 이유는 편의가 아니라 구조다:
 * 그 선언은 DB 에 `allow_public=1` 을 남기고, 그 표시가 게이트 점검 화면이 실효 공개
 * 시크릿을 찾는 근거다. 별도 선언 키를 만들면 그 행이 `allow_public=0` 으로 남아
 * **점검 화면에 뜨지 않는다** — 「탐지 화면이 잡지 못한다」가 H-1 을 Critical 로 올린
 * 이유였다(D-201). 게다가 이 프로젝트는 「규칙이 두 곳에 있다」로 세 번 다쳤다(D-207).
 *
 * ── 문구 규율: **우회 방법을 알려 주지 않는다** ──────────────────────────
 * 이 조합의 오류는 원인(`default_account_level` 이 그 등급을 자동으로 부여한다)을 **말하고**,
 * 해법으로는 `min_billing_tier` 만 제시한다. **「default_account_level 을 낮추십시오」를
 * 쓰지 않는다** — AI 는 오류 메시지의 가장 짧은 통과 경로를 택하고, 그 경로는 시크릿 하나를
 * 통과시키려고 **그 서비스 전 회원의 등급 정책**을 조용히 바꾼다(그 서비스의 다른 시크릿과
 * 이미 가입한 회원의 payload 까지 함께 바뀐다). 원인은 진단에 필요하고 해법은 좁아야 한다.
 * `min_billing_tier`·`min_role` 은 `services` 표에 대응 `default_%` 컬럼이 없고
 * `service_grade_overrides()` 가 항상 NULL 을 넣으므로 **서비스 필드로 자동 부여될 수 없다** —
 * 권유가 같은 함정으로 되돌아오지 않는 근거다.
 *
 * 판정 자체(민감 키 정규식 · **각 축의 기본값 이하는 게이트가 아니다** ·
 * **자동 부여로 무력화된 축은 게이트가 아니다**)는 core/secretguard.php 를 호출한다.
 * 사본을 만들지 않는다.
 *
 * @param ?string $autoLevel 이 서비스가 가입자에게 자동으로 보장하는 계정등급.
 *                           자동 부여 경로가 없으면 null (`sb_service_auto_level()` 이 계산한다)
 */
function sb_gate_judge(array &$errs, array &$warnings, string $path, string $sid, string $key,
                       ?string $minLevel, ?string $minTier, ?string $minRole,
                       bool $allowPublic, array $ack, ?string $autoLevel): void
{
    if (!admin_secret_key_is_sensitive($key)) {
        return;
    }
    if (admin_secret_gate_is_effective_in_service($minLevel, $minTier, $minRole, $autoLevel)) {
        return;
    }
    // 두 원인을 가른다. 겹치면(예: min_account_level='basic' + default='vip') **H-1 쪽이
    // 먼저다** — `basic` 은 애초에 게이트가 아니었고, 그것을 조합 문제로 설명하면
    // 운영자가 default 를 고치고 여전히 막힌다.
    $defeated = secret_gate_level_defeated_by_auto($minLevel, $autoLevel);

    // H-1 / D-201: 기준이 「랭크 0 초과」에서 「각 축의 기본값 초과」로 바뀌었다.
    // `basic` 은 랭크 10 이지만 전 회원의 기본값이라 게이트가 아니다.
    $expected = $defeated
        ? 'min_billing_tier > free 또는 min_role > user'
            . ' (이 서비스의 default_account_level 이 min_account_level 게이트를 자동으로 통과시킨다)'
        : 'min_account_level > basic, min_billing_tier > free, min_role > user 중 하나 이상';
    $got      = [
        'got' => [
            'min_account_level' => $minLevel,
            'min_billing_tier'  => $minTier,
            'min_role'          => $minRole,
        ],
        'got_type' => 'object',
    ];
    // 두 원인의 첫 문장만 다르다. 뒤따르는 승인 규약 문구는 공통이다 —
    // 「어느 원인이든 실효 공개는 하나의 선언으로만 통과한다」가 이 판정의 요점이다.
    $why = $defeated
        ? '민감한 시크릿(' . $key . ')의 min_account_level(' . (string) $minLevel . ') 게이트가 '
            . '이 서비스에서는 아무도 막지 않습니다. 이 서비스는 default_account_level('
            . (string) $autoLevel . ') 을 가입하는 회원에게 자동으로 보장하므로, 가입한 전원이 '
            . '그 등급을 갖고 게이트를 통과합니다.'
        : '민감한 시크릿(' . $key . ')에는 최소 등급 게이트가 필요합니다. '
            . '게이트를 비우면 가입한 모든 사용자가 이 값을 받습니다.';
    $how = $defeated
        // 이 축은 서비스 필드로 자동 부여될 수 없다 — 권유가 같은 함정으로 돌아오지 않는다.
        ? 'min_billing_tier 를 lite 이상으로 설정하십시오. 결제등급과 역할은 서비스가 '
            . '자동으로 부여하지 않습니다.'
        : 'min_billing_tier 를 lite 이상으로 설정하십시오. '
            . '(guest / basic 계정등급, free 결제등급, user 역할은 전 회원의 기본값이라 '
            . '게이트로 인정되지 않습니다 — 걸어도 전원이 통과합니다. '
            . 'min_account_level 로 걸려면 verified 이상이어야 하는데, 기본 설치본에서는 '
            . '아무도 verified 가 아니므로 그 서비스의 전 회원이 차단됩니다.)';
    if (!$allowPublic) {
        sb_add($errs, $path, 'gate_required', $why . ' ' . $how, $expected, $got);
        return;
    }
    if (!in_array($sid . '/' . $key, $ack, true)) {
        sb_add($errs, $path, 'gate_required',
            '민감한 시크릿(' . $key . ')을 게이트 없이 공개하려면 번들 최상위 '
            . 'acknowledge_public_secrets 에 "' . $sid . '/' . $key . '" 를 함께 적어야 합니다. '
            . '두 곳에 적게 하는 이유는 오류 메시지를 보고 반사적으로 통과시키지 못하게 하려는 것입니다. '
            . ($defeated
                ? 'min_account_level(' . (string) $minLevel . ') 은 이 서비스의 '
                    . 'default_account_level(' . (string) $autoLevel . ') 때문에 게이트로 세지 않습니다. '
                    . '게이트를 거는 편이 옳다면 min_billing_tier 를 lite 이상으로 설정하십시오.'
                : '게이트를 거는 편이 옳다면 min_billing_tier 를 lite 이상으로 설정하십시오.'),
            $expected, $got);
        return;
    }
    sb_warn($warnings, $path, 'sensitive_secret_public',
        '민감한 시크릿(' . $sid . '/' . $key . ')이 게이트 없이 공개됩니다. '
        . '가입한 모든 사용자가 이 값을 받습니다. 의도한 것인지 확인하십시오.'
        . ($defeated
            ? ' (min_account_level(' . (string) $minLevel . ') 은 이 서비스의 '
                . 'default_account_level(' . (string) $autoLevel . ') 이 자동으로 통과시킵니다.)'
            : ''));
}

/**
 * 이 서비스가 가입자에게 자동으로 보장하는 계정등급 — **번들의 실효값으로.**
 *
 * **규칙 자체는 `core/secretguard.php` 의 `secret_service_auto_level()` 에만 있다**
 * (어느 플래그가 부여 경로를 여는가 · `auto_join OR allow_register` 인 이유 · `status` 를
 * 보지 않는 이유). 이 함수가 하는 일은 **그 규칙에 넣을 실효값을 만드는 것**뿐이다 —
 * 같은 규칙을 관리자 콘솔(`api/admin/secrets.php`)도 써야 하고, 그쪽은 번들이 없다.
 *
 * 실효값 규칙은 게이트의 `$eff` 와 같다: **번들이 명시하면 그 값, 아니면 기존 행의 값.**
 * 한쪽만 보면 부분 갱신 번들(서비스 필드를 생략한)이 판정을 빠져나간다.
 *
 * @param bool       $fieldsApply 번들의 서비스 필드가 실제로 쓰이는가(`on_conflict=skip` 이면 false).
 *                                false 면 실효값은 DB 값 그대로다 — 그러지 않으면 `skip` 이
 *                                「게이트가 열려 있다」는 판정만 조용히 없애는 우회가 된다
 * @param ?array     $cur         기존 `services` 행. 없으면 null (그때는 스키마 기본값을 쓴다)
 */
function sb_service_auto_level(array $fields, ?array $cur, bool $fieldsApply): ?string
{
    $exists = $cur !== null;
    $flag = function (string $col, bool $schemaDefault) use ($fields, $cur, $exists, $fieldsApply) {
        if ($fieldsApply && array_key_exists($col, $fields)) {
            return (int) $fields[$col] === 1;
        }
        if ($exists) {
            return (int) $cur[$col] === 1;
        }
        return $schemaDefault;       // 신규 서비스 — schema.sql 의 DEFAULT (둘 다 1)
    };
    if ($fieldsApply && array_key_exists('default_account_level', $fields)) {
        $v = $fields['default_account_level'];
        $lvl = ($v === null || (string) $v === '') ? null : (string) $v;
    } elseif ($exists && $cur['default_account_level'] !== null && (string) $cur['default_account_level'] !== '') {
        $lvl = (string) $cur['default_account_level'];
    } else {
        $lvl = null;                 // 신규 서비스의 default_account_level 은 NULL 이다
    }
    return secret_service_auto_level($lvl, $flag('auto_join', true), $flag('allow_register', true));
}

// =====================================================================
// 4. 계획 — 현재 DB 와 대조
// =====================================================================

/** 서비스 행에서 비교·해시에 쓰는 컬럼만. */
function sb_service_state(?array $r): ?array
{
    if ($r === null) {
        return null;
    }
    return [
        'service_name'          => (string) $r['service_name'],
        'description'           => $r['description'] !== null ? (string) $r['description'] : null,
        'is_confidential'       => (int) $r['is_confidential'],
        'redirect_whitelist'    => (string) $r['redirect_whitelist'],
        'allowed_origins'       => $r['allowed_origins'] !== null ? (string) $r['allowed_origins'] : null,
        'auto_join'             => (int) $r['auto_join'],
        'allow_register'        => (int) $r['allow_register'],
        'default_account_level' => $r['default_account_level'] !== null ? (string) $r['default_account_level'] : null,
        'session_ttl'           => (int) $r['session_ttl'],
        'refresh_ttl'           => (int) $r['refresh_ttl'],
        'payload_fields'        => $r['payload_fields'] !== null ? (string) $r['payload_fields'] : null,
        'status'                => (string) $r['status'],
        // 해시는 그대로 담지 않는다. **있는가**와 **어떤 것인가**만 남긴다.
        'service_secret'        => ($r['service_secret_hash'] !== null && $r['service_secret_hash'] !== '')
            ? hash('sha256', (string) $r['service_secret_hash']) : null,
    ];
}

/** 시크릿 행에서 비교·해시에 쓰는 컬럼만. 값은 **평문의 해시**로만 담는다. */
function sb_secret_state(?array $r): ?array
{
    if ($r === null) {
        return null;
    }
    return [
        'value_type'        => (string) $r['value_type'],
        'min_account_level' => $r['min_account_level'] !== null ? (string) $r['min_account_level'] : null,
        'min_billing_tier'  => $r['min_billing_tier'] !== null ? (string) $r['min_billing_tier'] : null,
        'min_role'          => $r['min_role'] !== null ? (string) $r['min_role'] : null,
        'is_masked'         => (int) $r['is_masked'],
        'sort_order'        => (int) $r['sort_order'],
        'description'       => $r['description'] !== null ? (string) $r['description'] : null,
        'status'            => (string) $r['status'],
        'allow_public'      => array_key_exists('allow_public', $r) ? (int) $r['allow_public'] : 0,
        'value_sha'         => hash('sha256', dec((string) $r['secret_value_enc'])),
    ];
}

/**
 * 재귀 정규화(맵은 키 정렬). 해시 입력을 결정적으로 만든다.
 * @param mixed $v
 * @return mixed
 */
function sb_canon($v)
{
    if (!is_array($v)) {
        return $v;
    }
    $isList = sb_is_list($v);
    $out = [];
    foreach ($v as $k => $x) {
        $out[$k] = sb_canon($x);
    }
    if (!$isList) {
        ksort($out, SORT_STRING);
    }
    return $out;
}

/**
 * `plan_hash` — dry-run 강제의 기계 장치.
 *
 * 들어가는 것: 정규화된 번들(실행에 영향을 주는 부분만) + **영향받는 현재 행의 값** +
 *             `on_conflict` + environment 대조 결과.
 * 들어가지 않는 것: 타임스탬프, 요청자, `generated_at`, 예약 키.
 *
 * *왜 잠금이 아닌가:* cron 도 Redis 도 없고, 고아 잠금은 화면을 영구히 잠근다.
 * **낡은 계획을 거절하는 것이 잠금보다 안전하고 상태가 없다.**
 * (트랜잭션 안의 `FOR UPDATE` 는 별개다 — 그것은 요청 수명 안에서만 산다.)
 */
function sb_plan_hash(array $normForHash, array $state, string $onConflict, array $envMismatch): string
{
    $input = [
        'bundle'      => $normForHash,
        'state'       => $state,
        'on_conflict' => $onConflict,
        'environment' => $envMismatch,
    ];
    return hash('sha256', (string) json_encode(sb_canon($input),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * 번들의 `environment` 를 **현재 설치본과 대조**한다 (리더 D-193).
 *
 * ── 왜 파일명이 아니라 본문인가 ───────────────────────────────────────
 * 다운로드 파일명에는 호스트명을 넣지 않는다. 이 파일의 다음 행동이 AI 대화창
 * 붙여넣기라면 **파일명은 대화·티켓·스크린샷에 인용될 가능성이 가장 높은 부분**이고
 * 파일 본문이 사라져도 남는다. 그래서 「잘못된 설치본에 import」 위험은 파일명이
 * 아니라 **파일 안에서** 막는다. 파일명은 힌트이고 사람이 무시할 수 있다. 대조는 검사다.
 *
 * ── 어느 필드로 대조하는가 · 왜 그 셋인가 ─────────────────────────────
 *  · `BASE_URL`   — 설치본 정체성의 가장 강한 단일 신호다. **호스트명을 이미 담고 있다.**
 *                   그래서 `environment` 에 호스트명 필드를 따로 넣지 않았다 — 파일명과
 *                   달리 본문은 열어 봐야 보이고, 같은 정보를 두 번 담을 이유가 없다.
 *  · `DB_PREFIX`  — 다르면 두 설치본의 **테이블 이름 공간이 통째로 다르다.** 값이 아니라
 *                   구조가 다르다는 뜻이라 성격이 다른 신호다.
 *  · `SITE_NAME`  — 사람이 붙인 이름. prod/staging 처럼 BASE_URL 이 비슷한 쌍을 가른다.
 * 나머지 26개 상수는 **설치본마다 정당하게 다를 수 있는 정책값**이라 대조하지 않는다
 * (예: staging 의 `DEBUG=true`). 대조 대상을 늘리면 정상 이식마다 경고가 나고,
 * 매번 나오는 경고는 읽히지 않는다.
 *
 * ── 거절하지 않는다 ───────────────────────────────────────────────────
 * 의도적 이식(운영본 설정을 새 설치본에 옮기기)은 정당한 용례다. 그래서 **경고**다.
 * `plan.environment_check` 가 보여주고, 사용자는 그 상태로 apply 할 수 있다.
 *
 * @return array{compared:bool,matches:bool,mismatches:array,note:string}
 */
function sb_environment_check(?array $bundleConstants): array
{
    $fields = ['BASE_URL', 'DB_PREFIX', 'SITE_NAME'];
    if ($bundleConstants === null) {
        return [
            'compared'   => false,
            'matches'    => true,
            'mismatches' => [],
            'note'       => '번들에 environment 블록이 없어 대조하지 않았습니다. '
                . '어느 설치본에서 나온 번들인지 서버가 알 수 없습니다.',
        ];
    }
    $mismatches = [];
    foreach ($fields as $f) {
        if (!array_key_exists($f, $bundleConstants)) {
            continue;                      // 번들이 그 값을 주지 않았다 — 대조 대상이 아니다
        }
        $cur = defined($f) ? constant($f) : null;
        $cur = $cur === null ? null : (string) $cur;
        $b   = $bundleConstants[$f] === null ? null : (string) $bundleConstants[$f];
        if ($b !== $cur) {
            $mismatches[] = ['field' => $f, 'bundle' => $b, 'current' => $cur];
        }
    }
    return [
        'compared'   => true,
        'matches'    => $mismatches === [],
        'mismatches' => $mismatches,
        'note'       => $mismatches === []
            ? '번들의 environment 가 이 설치본과 일치합니다.'
            : '이 번들은 다른 설치본에서 나왔을 수 있습니다. 의도한 이식이면 그대로 적용해도 됩니다. '
                . '거절하지 않습니다 — 확인만 하십시오.',
    ];
}

/**
 * 계획을 만든다. **쓰기를 하지 않는다.**
 *
 * @param bool $forUpdate true 면 대상 행을 `FOR UPDATE` 로 **잠그고** 읽는다(apply 전용).
 *                        낙관적 해시만으로는 두 apply 가 둘 다 커밋 전에 읽는 창이 남고,
 *                        그것이 V14-H-2 와 같은 읽기-수정-쓰기 형태다.
 * @param array $errs     (out) 계획 단계에서만 알 수 있는 오류(mutual_dependency · 실효 게이트)
 * @param array $warnings (in/out) 검증 단계 경고에 이어 붙인다
 */
function sb_build_plan(array $norm, bool $forUpdate, bool $tenseDone, array &$errs, array &$warnings): array
{
    $onConflict = $norm['on_conflict'];
    $defs       = settings_defs();
    $conflicts  = [];
    $state      = ['settings' => [], 'services' => [], 'secrets' => []];
    $hasAllowPublicCol = admin_secrets_has_allow_public();

    // ---------- settings ----------
    $sKeys = array_keys($norm['settings']);
    $rows  = $forUpdate ? admin_settings_rows_for_update($sKeys) : admin_settings_rows($sKeys);
    $planSettings = ['create_or_update' => [], 'unchanged' => [], 'skipped' => []];
    $opSettings   = [];
    foreach ($norm['settings'] as $k => $to) {
        $def  = $defs[$k];
        $from = admin_setting_value($k, $def, $rows);
        $state['settings'][$k] = $from;
        $entry = ['path' => sb_ptr(['settings', $k]), 'skey' => $k, 'from' => $from, 'to' => $to];
        if ($from === $to) {
            $planSettings['unchanged'][] = $entry;
            continue;
        }
        $planSettings['create_or_update'][] = $entry;
        $opSettings[$k] = ['from' => $from, 'to' => $to, 'vtype' => $def[0], 'label' => $def[1]];

        // 상호 의존 경고 3건 — 값이 실제로 그 방향으로 **바뀔 때만** 낸다.
        if ($k === 'maintenance_mode' && $to === true) {
            sb_warn($warnings, $entry['path'], 'maintenance_mode_on',
                '점검 모드를 켭니다. 적용 직후 manager 미만 전원이 503 을 받습니다.');
        }
        if ($k === 'allow_register' && $to === false) {
            sb_warn($warnings, $entry['path'], 'registration_disabled',
                '신규 가입을 막습니다. 이후 회원가입이 거부됩니다.');
        }
        if ($k === 'secret_access_log' && $to === false) {
            sb_warn($warnings, $entry['path'], 'secret_audit_off',
                '시크릿 전달 감사 기록을 끕니다. 누가 언제 어떤 키를 받아 갔는지 더 이상 남지 않습니다. '
                . '번들 안에 묻혀 들어오기 쉬운 값이므로 의도한 것인지 확인하십시오.');
        }
    }

    // ---------- services · secrets ----------
    $planServices = [];
    $opServices   = [];
    $pending      = [];
    $counts = [
        'settings_update' => count($opSettings), 'settings_unchanged' => count($planSettings['unchanged']),
        'settings_skipped' => 0,
        'services_create' => 0, 'services_update' => 0, 'services_unchanged' => 0, 'services_skipped' => 0,
        'secrets_create' => 0, 'secrets_update' => 0, 'secrets_unchanged' => 0, 'secrets_skipped' => 0,
        'deletes' => 0,
    ];

    foreach ($norm['services'] as $svc) {
        $sid = $svc['service_id'];
        $cur = $forUpdate
            ? db_one('SELECT * FROM ' . T_SERVICES . ' WHERE service_id = ? FOR UPDATE', [$sid])
            : db_one('SELECT * FROM ' . T_SERVICES . ' WHERE service_id = ?', [$sid]);
        $state['services'][$sid] = sb_service_state($cur);

        $exists = $cur !== null;
        if ($exists && $onConflict === 'fail') {
            $conflicts[] = ['path' => $svc['path'], 'kind' => 'service', 'service_id' => $sid,
                'message' => '이미 존재하는 service_id 입니다.'];
        }

        $svcOp   = $exists ? ($onConflict === 'skip' ? 'skip' : 'update') : 'create';
        $set     = [];
        $before  = [];
        $after   = [];
        $changed = [];

        if ($svcOp !== 'skip') {
            foreach ($svc['fields'] as $col => $val) {
                $old = $exists ? $cur[$col] : null;
                $same = $exists && (($old === null && $val === null)
                    || ($old !== null && $val !== null && (string) $old === (string) $val));
                if ($same) {
                    continue;
                }
                $set[$col]      = $val;
                $before[$col]   = $exists ? $old : null;
                $after[$col]    = $val;
                $changed[]      = $col;
            }
            // service_secret 은 해시로 저장돼 비교가 다르다 — password_verify 로 멱등을 본다.
            // 암호문 비교로는 성립하지 않는다(bcrypt 는 솔트가 매번 다르다).
            if ($svc['secret'] !== null) {
                $curHash = $exists && $cur['service_secret_hash'] !== null ? (string) $cur['service_secret_hash'] : '';
                if ($curHash === '' || !password_verify($svc['secret'], $curHash)) {
                    $set['service_secret_hash'] = password_hash($svc['secret'], PASSWORD_DEFAULT);
                    $changed[]                  = 'service_secret';
                    $before['service_secret']   = $curHash === '' ? null : '(previous)';
                    $after['service_secret']    = '(changed)';
                }
            }
            // is_confidential 인데 시크릿이 어디에도 없으면 그 서비스로는 exchange 가 끝나지 않는다.
            // **DB 를 봐야 판정할 수 있어서 여기 있다** — `?action=validate` 는 이 검사를 하지 못한다.
            $confEff = array_key_exists('is_confidential', $svc['fields'])
                ? ((int) $svc['fields']['is_confidential'] === 1)
                : ($exists && (int) $cur['is_confidential'] === 1);
            $hasSecret = $svc['secret'] !== null
                || ($exists && $cur['service_secret_hash'] !== null && $cur['service_secret_hash'] !== '');
            if ($confEff && !$hasSecret) {
                sb_add($errs, $svc['path'], 'mutual_dependency',
                    'is_confidential 이 true 인데 service_secret 이 번들에도 DB 에도 없습니다. '
                    . 'confidential 클라이언트는 exchange 에서 service_secret 을 검증받으므로 로그인이 완료될 수 없습니다.',
                    'is_confidential=true requires service_secret (8..255 chars) in the bundle or an existing one in the database',
                    ['got' => ['is_confidential' => true, 'service_secret' => null], 'got_type' => 'object']);
            }
            if ($svcOp === 'create') {
                $counts['services_create']++;
            } elseif ($changed !== []) {
                $counts['services_update']++;
            } else {
                $svcOp = 'unchanged';
                $counts['services_unchanged']++;
            }
        } else {
            $counts['services_skipped']++;
        }

        // H-2 / 리더 판정 D-202 → **D-206 으로 거절이 되었다.**
        //
        // 이 서비스가 가입자에게 자동으로 부여하는 계정등급을 **시크릿 루프 전에** 구한다.
        // 직전 라운드는 루프 **뒤에서** 같은 값을 구해 경고를 냈다. 게이트 판정 자체가
        // 이 값을 필요로 하게 되었으므로 자리가 앞으로 온다 — 판정과 신호가 한 자리에 있어야
        // 「경고는 났는데 거절은 안 됐다」가 다시 생기지 않는다.
        //
        // 근거·실효값 규칙·`auto_join OR allow_register` 는 `sb_service_auto_level()` 에 있다.
        $autoLevel = sb_service_auto_level($svc['fields'], $cur, $svcOp !== 'skip');

        $planSecrets = [];
        $opSecrets   = [];
        foreach ($svc['secrets'] as $sec) {
            $one = sb_plan_secret($sid, $sec, $onConflict, $forUpdate, $tenseDone, $hasAllowPublicCol,
                $norm['ack'], $autoLevel, $state, $conflicts, $counts, $pending, $errs, $warnings);
            $planSecrets[] = $one['plan'];
            if ($one['op'] !== null) {
                $opSecrets[] = $one['op'];
            }
        }

        $planServices[] = [
            'path'           => $svc['path'],
            'service_id'     => $sid,
            'op'             => $svcOp,
            'changed_fields' => $changed,
            'secrets'        => $planSecrets,
        ];
        $opServices[] = [
            'op'         => $svcOp,
            'service_id' => $sid,
            'set'        => $set,
            'before'     => $before,
            'after'      => $after,
            'changed'    => $changed,
            'secrets'    => $opSecrets,
        ];
    }

    $env = sb_environment_check($norm['environment']);

    $plan = [
        'settings'              => $planSettings,
        'services'              => $planServices,
        'counts'                => $counts,
        'secrets_pending_value' => $pending,
        'warnings'              => $warnings,
        'environment_check'     => $env,
        'on_conflict'           => $onConflict,
        // 값이 아니라 **약속**이다. 화면이 이것을 근거로 "삭제되는 것 없음" 을 표시한다.
        // 이 두 필드가 false/0 이 아닌 값을 갖는 순간이 오면 설계가 바뀐 것이다.
        'deletes_possible'      => false,
    ];

    // 해시 입력에서 예약 키·경로 문자열을 뺀다 — 실행에 영향을 주는 것만 담는다.
    $normForHash = [
        'settings' => $norm['settings'],
        'ack'      => $norm['ack'],
        'services' => [],
    ];
    foreach ($norm['services'] as $svc) {
        $secs = [];
        foreach ($svc['secrets'] as $sec) {
            $secs[] = [
                'secret_key'   => $sec['secret_key'],
                'fields'       => $sec['fields'],
                'allow_public' => $sec['allow_public'],
                'value_given'  => $sec['value_given'],
                // 평문을 해시 입력에 그대로 넣지 않는다. 같은 값이면 같은 다이제스트다.
                'value_sha'    => $sec['value_given'] ? hash('sha256', (string) $sec['value']) : null,
            ];
        }
        $normForHash['services'][] = [
            'service_id' => $svc['service_id'],
            'fields'     => $svc['fields'],
            'secret_sha' => $svc['secret'] !== null ? hash('sha256', (string) $svc['secret']) : null,
            'secrets'    => $secs,
        ];
    }

    return [
        'plan'      => $plan,
        'conflicts' => $conflicts,
        'hash'      => sb_plan_hash($normForHash, $state, $onConflict, $env['mismatches']),
        'ops'       => ['settings' => $opSettings, 'services' => $opServices],
        'counts'    => $counts,
        'pending'   => $pending,
        'environment_check' => $env,
    ];
}

/** 시크릿 1건의 계획. */
function sb_plan_secret(string $sid, array $sec, string $onConflict, bool $forUpdate, bool $tenseDone,
                        bool $hasAllowPublicCol, array $ack, ?string $autoLevel, array &$state,
                        array &$conflicts, array &$counts, array &$pending, array &$errs,
                        array &$warnings): array
{
    $key  = $sec['secret_key'];
    $ref  = $sid . '/' . $key;
    $cur  = $forUpdate
        ? db_one('SELECT * FROM ' . T_SECRETS . ' WHERE service_id = ? AND secret_key = ? FOR UPDATE', [$sid, $key])
        : db_one('SELECT * FROM ' . T_SECRETS . ' WHERE service_id = ? AND secret_key = ?', [$sid, $key]);
    $state['secrets'][$ref] = sb_secret_state($cur);
    $exists = $cur !== null;

    if ($exists && $onConflict === 'fail') {
        $conflicts[] = ['path' => $sec['path'], 'kind' => 'secret', 'service_id' => $sid,
            'secret_key' => $key, 'message' => '이미 존재하는 시크릿 키입니다.'];
    }

    // 실효 게이트 = 번들이 명시한 값 + 나머지는 기존 행의 값. 이것이 적용 후의 실제 상태다.
    $eff = [];
    foreach (['min_account_level', 'min_billing_tier', 'min_role'] as $gk) {
        if (array_key_exists($gk, $sec['fields'])) {
            $eff[$gk] = $sec['fields'][$gk];
        } elseif ($exists) {
            $eff[$gk] = $cur[$gk] !== null ? (string) $cur[$gk] : null;
        } else {
            $eff[$gk] = null;
        }
    }
    // ── M-5: **이미 이 행에 대해 받아 둔 승인은 다시 요구하지 않는다** ──────────
    // `allow_public=1` 은 「민감 키 + 실효 게이트 0개」인 행에만 기록된다(아래 $ackNow 조건).
    // 즉 그 행은 **정확히 지금 요구하려는 그 승인을 이미 받았다.** 그런데 export 는
    // `acknowledge_public_secrets` 를 만들지 않으므로, 그런 행이 있는 설치본은
    // 자기 export 를 그대로 import 할 수 없었다 — `_notes[0]` 의 「이 파일은 그대로
    // import 할 수 있다」가 깨지는 자리에서 서버가 운영자에게 ack 우회법을 가르쳤다.
    // **새 키에는 적용되지 않는다**($exists=false 면 $curAck=0) — 두 곳에 적게 하는
    // 규약의 목적(번들 전체를 보고 사람이 의도적으로 적는다)은 그대로다.
    // `?action=validate` 는 DB 를 읽지 않으므로 여전히 `gate_required` 를 과보고한다 —
    // fail-closed 방향이고 설계 7-4 #11 이 그 동작을 명시적으로 요구한다.
    $curAck   = ($exists && array_key_exists('allow_public', $cur)) ? (int) $cur['allow_public'] : 0;
    $ackHere  = $ack;
    if ($sec['allow_public'] && $curAck === 1 && !in_array($ref, $ackHere, true)) {
        $ackHere[] = $ref;
    }
    sb_gate_judge($errs, $warnings, $sec['path'], $sid, $key,
        $eff['min_account_level'], $eff['min_billing_tier'], $eff['min_role'], $sec['allow_public'],
        $ackHere, $autoLevel);

    // **`skip` 도 게이트 판정을 이미 받았다** — 위 `sb_gate_judge()` 가 이 분기보다 앞이다.
    // H-2 조합 판정도 그 안에 있으므로 같은 보호를 받는다. 그러지 않으면 `on_conflict=skip`
    // 이 「게이트가 열려 있다」는 판정만 조용히 없애는 우회가 된다.
    if ($exists && $onConflict === 'skip') {
        $counts['secrets_skipped']++;
        if (dec((string) $cur['secret_value_enc']) === '') {
            $pending[] = sb_pending_entry($sid, $key);
        }
        return [
            'plan' => ['path' => $sec['path'], 'secret_key' => $key, 'op' => 'skip',
                'changed_fields' => [], 'value_action' => 'keep'],
            'op'   => null,
        ];
    }

    $set     = [];
    $before  = [];
    $after   = [];
    $changed = [];
    foreach ($sec['fields'] as $col => $val) {
        $old  = $exists ? $cur[$col] : null;
        $same = $exists && (($old === null && $val === null)
            || ($old !== null && $val !== null && (string) $old === (string) $val));
        if ($same) {
            continue;
        }
        $set[$col]    = $val;
        $before[$col] = $exists ? $old : null;
        $after[$col]  = $val;
        $changed[]    = $col;
    }

    // 값 비교 — **`dec(저장값) === 들어온값`.** AES-CBC 는 IV 가 매번 달라
    // 암호문끼리 비교하면 같은 값도 항상 "변경" 으로 잡혀 멱등이 성립하지 않는다.
    $curPlain    = $exists ? dec((string) $cur['secret_value_enc']) : '';
    $valueAction = 'keep';
    $forced      = null;
    if ($sec['value_given']) {
        if (!$exists || $curPlain !== (string) $sec['value']) {
            $set['secret_value_enc'] = enc((string) $sec['value']);
            $changed[]               = 'secret_value';
            $after['secret_value']   = '(changed)';    // 값 평문은 어느 쪽에도 남기지 않는다
            $valueAction             = 'set';
        } else {
            $valueAction = 'unchanged';
        }
    } elseif (!$exists || $curPlain === '') {
        // **값이 없다.** 신규 행이거나, 기존 행인데 저장된 값이 비어 있다.
        // 어느 쪽이든 status 를 disabled 로 강제한다.
        // 값이 빈 active 행은 payload.php 의 build_payload() 가 조용히 건너뛰고
        // error_log 한 줄만 남긴다 — 연동 앱에는 "키가 없다" 로만 보이고 서버는 200 이다.
        // disabled 는 같은 결과를 **의도된 상태로** 만든다.
        //
        // ── `$curPlain === ''` 가 왜 조건에 있는가 (v1.6.0 QA Q-1) ──────────
        // 종전 조건은 `!$exists` 뿐이었다. 그래서 **같은 번들을 두 번 적용하면**
        // 1회차에 강제한 disabled 가 2회차에 경고 한 줄 없이 active 로 되돌아갔다.
        // 가이드가 AI 에게 시키는 모양(`secret_value: null` + `status: "active"`)과
        // 가이드 자신의 완전 예시가 정확히 그 조합이고, 「AI 가 만들고 한 번에 import」한
        // 뒤 같은 파일을 다시 넣는 것이 이 기능의 정상 사용이다. 즉 예외 경로가 아니었다.
        // 몇 줄 아래 `secrets_pending_value` 판정은 이미 `$curPlain === ''` 를 보고 있었다 —
        // **계획은 알고 있는데 status 강제만 그것을 쓰지 않았다.**
        //
        // 판단: 번들이 명시한 `status: "active"` 를 **경고와 함께 덮어쓴다**(거절하지 않는다).
        // 거절하면 사람이 값을 채우기 전에는 그 번들 전체를 적용할 수 없게 되고,
        // 그것은 「한 번에 import」라는 이 기능의 목적과 정면으로 충돌한다.
        if (!$exists) {
            $set['secret_value_enc'] = enc('');
        }
        // 기존 행의 빈 값은 다시 쓰지 않는다 — 같은 평문을 새 IV 로 재암호화하면
        // 바뀐 것이 없는데 매 회차 UPDATE 가 나가고 updated_at 이 올라간다.
        $valueAction = 'leave_empty';
        $forced      = 'disabled';

        // **강제 결과를 현재 값과 대조해 정규화한다.** 이것이 없으면 이미 disabled 인 행이
        // 매 회차 `op=update  changed=["status"]` 로 보고돼 멱등이 보고 단계에서 깨진다.
        $curStatus = $exists ? (string) $cur['status'] : null;
        $sIdx      = array_search('status', $changed, true);
        if ($curStatus === 'disabled') {
            unset($set['status'], $before['status'], $after['status']);
            if ($sIdx !== false) {
                array_splice($changed, (int) $sIdx, 1);
            }
        } else {
            $set['status']   = 'disabled';
            $after['status'] = 'disabled';
            if ($exists && !array_key_exists('status', $before)) {
                $before['status'] = $curStatus;
            }
            if ($sIdx === false) {
                $changed[] = 'status';
            }
        }
        sb_warn($warnings, $sec['path'], 'secret_forced_disabled',
            ($exists ? '저장된 secret_value 가 비어 있어 ' : 'secret_value 가 없어 ')
            . 'status 를 disabled 로 '
            . ($curStatus === 'disabled'
                ? ($tenseDone ? '유지했습니다.' : '유지합니다.')
                : ($tenseDone ? '만들었습니다.' : '만듭니다.'))
            . ($tenseDone ? '' : ' 값이 빈 active 시크릿은 payload 에서 조용히 빠져 원인을 찾기 어렵습니다.'
                . ' 번들의 status 값보다 이 강제가 우선합니다.'));
    }

    if ((!$exists) || $curPlain === '') {
        if ($valueAction !== 'set') {
            $pending[] = sb_pending_entry($sid, $key);
        }
    }

    // allow_public 표시(마이그레이션 0002). 컬럼이 없으면 쓰지 않고 넘어간다 —
    // 코드를 올리고 아직 migrate.php 를 돌리지 않은 설치본이 존재한다.
    // `$ackHere` 를 쓴다 — 위 게이트 판정이 통과시킨 것과 **같은 목록**이어야 한다.
    // `$ack` 를 그대로 보면 M-5 왕복에서 게이트는 통과하는데 표시가 0 으로 내려가
    // 그 행이 다음 왕복에서 다시 막힌다(그리고 점검 화면이 승인을 잃는다).
    //
    // **게이트 판정도 같은 함수여야 한다**(H-2 / D-206). `admin_secret_gate_is_effective()`
    // 를 그대로 쓰면 「min_account_level 은 걸려 있지만 default_account_level 이 통과시키는」
    // 행이 「게이트 있음」으로 읽혀 `allow_public=0` 이 기록된다. 그러면 선언을 받고 통과한
    // 행이 **게이트 점검 화면에 뜨지 않는다** — 거절을 선언으로 통과시키는 설계의 대가가
    // 「탐지 화면에 남는다」인데 그 대가가 사라진다. 위 게이트 판정과 **같은 판정**을 쓴다.
    $ackNow = ($sec['allow_public'] && admin_secret_key_is_sensitive($key)
        && !admin_secret_gate_is_effective_in_service($eff['min_account_level'],
            $eff['min_billing_tier'], $eff['min_role'], $autoLevel)
        && in_array($ref, $ackHere, true)) ? 1 : 0;
    if ($hasAllowPublicCol && $curAck !== $ackNow) {
        $set['allow_public'] = $ackNow;
    }

    if (!$exists) {
        $counts['secrets_create']++;
        $op = 'create';
    } elseif ($changed !== [] || array_key_exists('allow_public', $set)) {
        $counts['secrets_update']++;
        $op = 'update';
    } else {
        $counts['secrets_unchanged']++;
        $op = 'unchanged';
    }

    $planRow = ['path' => $sec['path'], 'secret_key' => $key, 'op' => $op,
        'changed_fields' => $changed, 'value_action' => $valueAction];
    if ($forced !== null) {
        $planRow['forced_status'] = $forced;
    }

    return [
        'plan' => $planRow,
        'op'   => ['op' => $op, 'secret_key' => $key, 'id' => $exists ? (int) $cur['id'] : 0,
            'set' => $set, 'before' => $before, 'after' => $after, 'changed' => $changed],
    ];
    // 직전 라운드는 `eff`·`sensitive` 를 함께 올려 호출부의 H-2 **경고**가 그것을 썼다.
    // D-206 으로 판정이 `sb_gate_judge()` 안으로 들어가면서 호출부가 그 값을 쓰지 않는다 —
    // 쓰지 않는 반환 필드를 남기면 다음 라운드가 그것을 판정 근거로 오해한다.
}

function sb_pending_entry(string $sid, string $key): array
{
    return [
        'service_id' => $sid,
        'secret_key' => $key,
        'how'        => '관리자 콘솔 > 서비스 > ' . $sid . ' > 시크릿 에서 값을 넣고 status 를 active 로 바꾸십시오.',
    ];
}

// =====================================================================
// 5. 요청 처리
// =====================================================================

$ctx = require_auth();
// 네 동작 전부 superadmin. 역할에 따라 응답 shape 이 달라지지 않게 하려는 것도 이유다 —
// 이 프로젝트에서 경계면 불일치가 가장 흔한 런타임 버그다(설계 판정 3).
if (!role_gte(ctx_global_role($ctx), 'superadmin')) {
    throw new AppError('FORBIDDEN', 403, ['message' => '설정 번들은 최고관리자만 다룰 수 있습니다.', 'field' => null]);
}

$method  = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$adminId = (int) $ctx['user']['id'];

// -----------------------------------------------------------------
// GET — export
//
// 감사 로그를 남기지 않는다: 이 응답의 정보량은 `GET admin/services` +
// `GET admin/secrets` + `GET admin/settings` 를 합친 것과 같고 그 셋은 로그를
// 남기지 않는다. 평문을 내보내는 `secret.reveal` 과는 성질이 다르다.
// **판정 2 가 뒤집혀 값이 실리게 되면 이 판단도 함께 뒤집어야 한다.**
// -----------------------------------------------------------------
if ($method === 'GET') {
    $defs = settings_defs();
    $rows = admin_settings_rows(array_keys($defs));
    $settings = [];
    foreach ($defs as $k => $def) {
        $settings[$k] = admin_setting_value($k, $def, $rows);
    }

    $svcRows = db_all('SELECT * FROM ' . T_SERVICES . ' ORDER BY service_id ASC');
    $services = [];
    $secretCount = 0;
    $secretWithValue = 0;
    foreach ($svcRows as $r) {
        $sid  = (string) $r['service_id'];
        $secs = [];
        foreach (db_all('SELECT * FROM ' . T_SECRETS . ' WHERE service_id = ?'
            . ' ORDER BY sort_order ASC, id ASC', [$sid]) as $s) {
            $present = dec((string) $s['secret_value_enc']) !== '';
            $secretCount++;
            if ($present) {
                $secretWithValue++;
            }
            $secs[] = [
                'secret_key'        => (string) $s['secret_key'],
                // **항상 null 이다.** 값의 유무만 value_present 로 알린다(설계 판정 2).
                'secret_value'      => null,
                'value_present'     => $present,
                'value_type'        => (string) $s['value_type'],
                'min_account_level' => $s['min_account_level'] !== null ? (string) $s['min_account_level'] : null,
                'min_billing_tier'  => $s['min_billing_tier'] !== null ? (string) $s['min_billing_tier'] : null,
                'min_role'          => $s['min_role'] !== null ? (string) $s['min_role'] : null,
                'is_masked'         => (bool) $s['is_masked'],
                'allow_public'      => array_key_exists('allow_public', $s) && (bool) $s['allow_public'],
                'sort_order'        => (int) $s['sort_order'],
                'description'       => $s['description'] !== null ? (string) $s['description'] : null,
                'status'            => (string) $s['status'],
            ];
        }
        $pf = $r['payload_fields'];
        $services[] = [
            'service_id'            => $sid,
            'service_name'          => (string) $r['service_name'],
            'description'           => $r['description'] !== null ? (string) $r['description'] : null,
            'is_confidential'       => (bool) $r['is_confidential'],
            // service_secret_hash 는 어떤 응답에도 넣지 않는다. 설정 여부만 알린다.
            'has_secret'            => $r['service_secret_hash'] !== null && $r['service_secret_hash'] !== '',
            'redirect_whitelist'    => array_values(json_col($r['redirect_whitelist'])),
            'allowed_origins'       => array_values(json_col($r['allowed_origins'])),
            'auto_join'             => (bool) $r['auto_join'],
            'allow_register'        => (bool) $r['allow_register'],
            'default_account_level' => $r['default_account_level'] !== null ? (string) $r['default_account_level'] : null,
            'session_ttl'           => (int) $r['session_ttl'],
            'refresh_ttl'           => (int) $r['refresh_ttl'],
            'payload_fields'        => ($pf === null || $pf === '') ? null : array_values(json_col($pf)),
            'status'                => (string) $r['status'],
            'secrets'               => $secs,
        ];
    }

    // config.php 상수 — **허용 목록이다. 차단 목록이 아니다.**
    // 다음 버전이 상수를 추가하면 차단 목록은 조용히 새 상수를 노출한다.
    // 허용 목록은 조용히 빠뜨린다 — 틀린다면 닫는 쪽으로 틀리는 것이 맞다(fail-closed).
    // 각 항목은 [상수명, 타입]. 상수가 없는 낡은 config.php 에서도 죽지 않게 defined() 로 본다(D-151).
    $constSpec = [
        ['DB_PREFIX', 's'], ['DB_CHARSET', 's'],
        ['FORCE_HTTPS', 'b'], ['TRUST_PROXY', 'b'],
        ['COOKIE_NAME', 's'], ['COOKIE_CSRF_NAME', 's'], ['COOKIE_SECURE', 'b'],
        ['COOKIE_SAMESITE', 's'], ['COOKIE_PATH', 's'], ['COOKIE_DOMAIN', 's'],
        ['MAIL_DRIVER', 's'], ['MAIL_FROM_NAME', 's'],
        ['BASE_URL', 's'], ['SITE_NAME', 's'],
        ['SESSION_TTL', 'i'], ['REFRESH_TTL', 'i'], ['REMEMBER_TTL', 'i'], ['AUTH_CODE_TTL', 'i'],
        ['PW_RESET_TTL', 'i'], ['EMAIL_VERIFY_TTL', 'i'],
        ['LOCKOUT_THRESHOLD', 'i'], ['LOCKOUT_MINUTES', 'i'], ['PASSWORD_MIN_LENGTH', 'i'],
        ['SIGNUP_BONUS_POINTS', 'i'],
        ['ALLOW_REGISTER', 'b'], ['REQUIRE_EMAIL_VERIFY', 'b'], ['MAINTENANCE_MODE', 'b'],
        ['SECRET_ACCESS_LOG', 'b'], ['DEBUG', 'b'],
    ];
    $constants = [];
    foreach ($constSpec as $c) {
        if (!defined($c[0])) {
            $constants[$c[0]] = null;
            continue;
        }
        $v = constant($c[0]);
        if ($c[1] === 'i') {
            $constants[$c[0]] = (int) $v;
        } elseif ($c[1] === 'b') {
            $constants[$c[0]] = (bool) $v;
        } else {
            $constants[$c[0]] = (string) $v;
        }
    }

    $bundle = [
        'ulogin_settings_bundle' => SB_VERSION,
        'generated_at'           => gmdate('Y-m-d\TH:i:s\Z'),
        'generator'              => 'ulogin 1.6.0',
        'on_conflict'            => 'merge',
        'settings'               => $settings,
        'services'               => $services,
        'environment'            => [
            '_readonly' => true,
            '_note'     => 'config.php 의 값이다. import 는 이 블록을 적용하지 않는다(값을 쓰지 않는다). '
                . '다만 BASE_URL · DB_PREFIX · SITE_NAME 을 현재 설치본과 대조해 다르면 경고한다. '
                . '값을 바꾸려면 server/config.php 를 직접 편집하거나 재설치한다.',
            'constants' => $constants,
            'value_omitted_constants' => [
                'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS',
                'SECRET_ENC_KEY', 'APP_KEY', 'TRUSTED_PROXIES', 'MAIL_FROM',
            ],
            'derived_table_constants' => 17,
            'constants_total'         => 54,
        ],
        'excluded' => [
            'secret_values' => [
                'included' => false,
                'reason'   => '이 서버의 목적은 AI API 키를 글자로 노출하지 않는 것이다. export 파일은 AI 대화창에 붙여넣기 쉬운 형태이므로 값을 담지 않는다.',
                'how_to_set' => 'import JSON 의 secrets[].secret_value 에 값을 적거나, import 후 관리자 콘솔 > 서비스 > 시크릿에서 채운다.',
                'value_present_field' => '각 시크릿의 value_present 가 현재 값의 유무를 알린다.',
            ],
            'config_php' => [
                'included' => 'partial',
                'reason'   => '런타임에 config.php 를 쓰지 않는다. SECRET_ENC_KEY 가 잘못되면 저장된 모든 시크릿이 영구히 복호화 불가능해지고, 부분 쓰기는 관리자 콘솔을 포함한 전 엔드포인트를 500 으로 만든다.',
                'managed_by' => 'server/install.php · 수동 편집',
                'constants_with_value'    => 29,
                'constants_value_omitted' => 8,
                'constants_derived'       => 17,
            ],
            'terms' => [
                'included' => false,
                'reason'   => 'app_ulogin_terms 에 쓰는 코드가 아직 없어 import 로 만든 행을 그 뒤 수정·발행할 수 없다. 관리되지 않는 법률 문서는 0행보다 나쁘다 — 관리되고 있다고 오해되기 때문이다. v1.8.0 이 이 표를 소유한다.',
            ],
            'users' => [
                'included' => false,
                'reason'   => '설정이 아니라 개인정보 데이터다. app_ulogin_users · app_ulogin_user_services 는 이 기능의 범위 밖이다.',
            ],
            'sessions_and_logs' => [
                'included' => false,
                'reason'   => '런타임 상태다. 이식하면 같은 토큰이 두 설치본에서 유효해진다.',
            ],
            'schema_migrations' => [
                'included' => false,
                'reason'   => '스키마 리비전은 server/migrate.php 가 소유한다.',
            ],
        ],
        '_notes' => [
            '이 파일은 그대로 import 할 수 있다. generated_at · generator · environment · excluded · _notes 는 import 가 받아서 무시한다.',
            'import 는 어떤 행도 삭제하지 않는다. INSERT 와 UPDATE 만 한다. 은퇴는 status를 disabled 로 바꾼다.',
            'apply 전에 반드시 dry-run 을 호출해야 한다. apply 는 dry-run 이 준 expect_plan_hash 를 필수로 받는다.',
            'AI 에게 이 파일을 고치게 할 때는 secret_value 를 null 로 두게 하고, 값은 사람이 채워 넣어라.',
            // M-1 / 리더 판정 D-204 — 이 블록에 자격증명은 없지만, TRUST_PROXY ·
            // FORCE_HTTPS · COOKIE_SECURE · COOKIE_SAMESITE · COOKIE_DOMAIN ·
            // LOCKOUT_THRESHOLD · LOCKOUT_MINUTES · SECRET_ACCESS_LOG · DEBUG 아홉 개가
            // 모이면 「어떤 공격이 이 설치본에서 성립하는가」의 체크리스트가 된다.
            // 가장 붙여넣기 쉬운 파일에 가장 붙여넣지 말아야 할 블록이 들어 있었다.
            'environment 블록은 이 설치본의 방어 설정(프록시 신뢰 · 쿠키 속성 · 락아웃 임계 · 감사 on/off) '
                . '요약이다. 외부(AI 대화창 · 티켓 · 스크린샷)에 붙여넣을 때는 이 블록을 지워라. '
                . 'import 는 없어도 동작한다 — 설치본 대조만 건너뛴다.',
        ],
    ];

    json_out([
        'bundle' => $bundle,
        'meta'   => [
            'service_count'           => count($services),
            'secret_count'            => $secretCount,
            'secret_with_value_count' => $secretWithValue,
            'settings_count'          => count($settings),
            'bundle_bytes'            => strlen((string) json_encode($bundle,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ],
    ]);
}

// -----------------------------------------------------------------
// POST — validate / dry-run / apply
// -----------------------------------------------------------------
require_csrf();

$action = v_enum($_GET, 'action', ['validate', 'dry-run', 'apply'], true);

/** 검증 오류만 담은 400. `errors` 는 **`data` 에 있다. `error` 안이 아니다.** */
function sb_fail(array $errs, ?array $checked, string $message, ?string $field): void
{
    $extra = [
        'errors'      => array_slice($errs, 0, SB_MAX_ERRORS),
        'error_count' => count($errs),
        'truncated'   => count($errs) >= SB_MAX_ERRORS,
    ];
    if ($checked !== null) {
        $extra['checked'] = $checked;
    }
    $extra['field']   = $field;
    $extra['message'] = $message;
    json_err('VALIDATION_FAILED', 400, $extra);
}

// ---- 본문 크기 (1) 헤더로 하는 **조기** 거절 ------------------------------
//
// **이것은 값싼 조기 거절이고, 진짜 검사는 아래 (2) 다.** `Content-Length` 는 힌트다 —
// `Transfer-Encoding: chunked` 요청에는 그 헤더가 없어서 이 검사를 통째로 건너뛴다
// (v1.6.0 보안 감사 M-4 / 리더 판정 D-204. 실측: 1.2MB 본문이 `php -S` 와
//  Apache 2.4 + mod_php 8.1.27 두 SAPI 모두에서 200 OK 로 파싱됐다).
// 그래도 남겨 두는 이유: 헤더 한 줄만 읽고 본문을 읽기 전에 끊을 수 있다.
//
// 레이트리밋보다 **앞**이다: 크기 실수 한 번으로 시간당 10회뿐인 apply 예산을
// 태우지 않는 편이 낫다. 크기 남용은 bootstrap 의 전역 `api:ip` 300/60 이 이미 막는다.
$clen = isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] !== ''
    ? (int) $_SERVER['CONTENT_LENGTH'] : -1;
if ($clen > SB_MAX_BODY) {
    sb_fail([[
        'path' => '/', 'code' => 'body_too_large',
        'message' => '번들이 너무 큽니다. ' . (string) SB_MAX_BODY . ' 바이트 이내여야 합니다.',
        'expected' => '<= ' . (string) SB_MAX_BODY . ' bytes',
        'got' => $clen, 'got_type' => 'integer',
    ]], null, '번들이 너무 큽니다.', 'body');
}

/** `post_max_size` 같은 ini 크기 표기를 바이트로. 0 이면 무제한이거나 읽을 수 없다. */
function sb_ini_bytes(string $v): int
{
    $v = trim($v);
    if ($v === '' || $v === '0' || $v === '-1') {
        return 0;
    }
    $unit = strtolower(substr($v, -1));
    $num  = (int) $v;
    if ($unit === 'g') {
        return $num * 1024 * 1024 * 1024;
    }
    if ($unit === 'm') {
        return $num * 1024 * 1024;
    }
    if ($unit === 'k') {
        return $num * 1024;
    }
    return $num;
}

$postMax = sb_ini_bytes((string) ini_get('post_max_size'));
$req     = body_json();

// ---- 본문 크기 (2) **읽은 뒤에 잰다. 이것이 실제 상한이다.** -----------------
//
// `body_raw_bytes()` 는 `body_json()` 이 `php://input` 에서 읽은 그 자리에서 센 값이다
// (`core/request.php`). 스트림을 다시 읽어 재지 않는다 — 재읽기가 빈 문자열을 돌려주는
// SAPI 에서는 검사가 **조용히 통과**한다. 폼 전송 경로에서는 null 이므로, 그때는
// 파싱 결과를 다시 직렬화한 길이로 잰다(정확히 같은 값은 아니지만 상한 판정에는 충분하다).
$rawLen = body_raw_bytes();
if ($rawLen === null) {
    $rawLen = strlen((string) json_encode($req, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
if ($rawLen > SB_MAX_BODY) {
    sb_fail([[
        'path' => '/', 'code' => 'body_too_large',
        'message' => '번들이 너무 큽니다. ' . (string) SB_MAX_BODY . ' 바이트 이내여야 합니다. '
            . '(Content-Length 없이 보낸 요청도 같은 상한을 받습니다.)',
        'expected' => '<= ' . (string) SB_MAX_BODY . ' bytes',
        'got' => $rawLen, 'got_type' => 'integer',
    ]], null, '번들이 너무 큽니다.', 'body');
}

// ---- 본문이 사라졌는가 --------------------------------------------------
//
// **판정은 「본문이 실제로 사라졌는가」로 한다. `post_max_size` 비교만으로 거절하지 않는다.**
//
// ── 실측 기록 (v1.6.0 수정 라운드에서 다시 쟀다. 이전 주석은 틀렸다) ─────────
// 종전 주석은 「`post_max_size` 를 넘으면 PHP 는 `php://input` 과 `$_POST` 를 비운다.
// mod_php 는 버린다」고 단언했다. **`application/json` 본문에 대해서는 반대다.**
//   · `php -S` (cli-server) · `post_max_size=1K` · JSON 5,052 B
//       → 200 OK. `bundle_bytes` 가 참값으로 나왔다 = 본문이 그대로 파싱됐다
//   · Apache 2.4 + mod_php 8.1.27 · `post_max_size=1K` · 같은 요청
//       → 200 OK. **버리지 않았다.** (PHP 는 경고 한 줄만 남긴다)
// 즉 아래 `$byPostMax` 분기는 **두 SAPI 모두에서 도달하지 않는다.**
// 그래도 지우지 않는 이유: nginx + PHP-FPM 과 `enable_post_data_reading=Off` 는
// 재현하지 못했고, 거기서는 본문이 실제로 버려질 수 있다. **도달하지 않는 진단을
// 남기는 것은 값싸고, 없는데 필요해지는 것은 비싸다.** 다만 **주석이 그 사실을
// 말해야 한다** — 낡은 단언은 이 결함(M-4)을 「이미 검토한 것」으로 보이게 만들었다.
$dropped   = false;
$byPostMax = false;
if ($clen > 0 && $req === []) {
    if ($postMax > 0 && $clen > $postMax) {
        $dropped   = true;
        $byPostMax = true;
    } elseif ($clen > 32) {
        // 32바이트를 넘는 본문이 빈 배열로 파싱됐다 = 본문이 도착하지 않았다.
        // (`{}` `[]` 같은 정상적인 빈 본문은 그 길이에 한참 못 미친다.)
        $dropped = true;
    }
}
if ($dropped) {
    sb_fail([[
        'path' => '/', 'code' => 'body_missing_or_dropped',
        'message' => '요청 본문이 서버에 도착하지 않았습니다. '
            . ($byPostMax
                ? 'Content-Length(' . (string) $clen . ' 바이트)가 PHP 의 post_max_size('
                    . (string) $postMax . ' 바이트)를 넘고 본문이 비어 있습니다 — 이 실행 환경은 '
                    . '초과 본문을 버립니다. php.ini 의 post_max_size 를 올리십시오.'
                : 'Content-Length 는 ' . (string) $clen . ' 바이트인데 파싱된 본문이 비었습니다. '
                    . 'php.ini 의 post_max_size 와 중간 프록시의 본문 크기 제한을 확인하십시오.'),
        'expected' => 'non-empty body',
        'got' => $clen, 'got_type' => 'integer',
    ]], null, '요청 본문이 도착하지 않았습니다.', 'body');
}

// ---- 레이트리밋 ---------------------------------------------------
// `core/ratelimit.php` 를 쓴다 — **자체 카운터를 만들지 않는다.** V14-H-2 가 거기서 났다.
// `rate_limit_logged()` 가 아니라 `rate_limit()` 이다: 실패가 `login_logs` 를
// 채우면 AI 루프가 표를 채우고 진짜 신호가 묻힌다(V14-H-1 과 같은 형태).
if ($action === 'apply') {
    rate_limit('bundle:apply:user:' . (string) $adminId, 10, 3600);
} else {
    rate_limit('bundle:check:user:' . (string) $adminId, 60, 3600);
}

$bundleIn = array_key_exists('bundle', $req) ? $req['bundle'] : null;
if (!array_key_exists('bundle', $req)) {
    sb_fail([[
        'path' => '/', 'code' => 'bundle_not_recognized',
        'message' => '요청 본문에 bundle 이 없습니다. {"bundle": { ... }} 형태로 보내십시오.',
        'expected' => 'object with key "bundle"',
        'got' => null, 'got_type' => 'missing',
    ]], null, 'ulogin 설정 번들이 아닙니다.', 'bundle');
}

$errs     = [];
$warnings = [];
$norm     = sb_validate($bundleIn, $errs, $warnings, $action === 'validate');

// `on_conflict` 는 **요청 최상위가 번들 안의 값을 이긴다** — 명시적 조작이 파일보다 우선한다.
// (번들 파일에 실행 옵션이 스며들어 다른 설치본으로 따라가는 것을 막는다.)
if (array_key_exists('on_conflict', $req)) {
    $oc = v_enum($req, 'on_conflict', ['merge', 'skip', 'fail'], true);
    $norm['on_conflict'] = (string) $oc;
}

$bundleBytes = strlen((string) json_encode(is_array($bundleIn) ? $bundleIn : [],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$secretsSeen = 0;
foreach ($norm['services'] as $sv) {
    $secretsSeen += count($sv['secrets']);
}
$checked = [
    'bundle_version' => (is_array($bundleIn) && isset($bundleIn['ulogin_settings_bundle'])
        && is_string($bundleIn['ulogin_settings_bundle'])) ? $bundleIn['ulogin_settings_bundle'] : null,
    'settings_keys'  => count($norm['settings']),
    'services'       => count($norm['services']),
    'secrets'        => $secretsSeen,
    'bundle_bytes'   => $bundleBytes,
];

if ($errs !== []) {
    $first = $errs[0]['code'];
    if ($first === 'bundle_not_recognized') {
        sb_fail($errs, $checked, 'ulogin 설정 번들이 아닙니다.', 'ulogin_settings_bundle');
    }
    if ($first === 'bundle_version_unsupported') {
        sb_fail($errs, $checked, '지원하지 않는 번들 버전입니다.', 'ulogin_settings_bundle');
    }
    sb_fail($errs, $checked, '번들 검증에 실패했습니다. 오류 ' . (string) count($errs) . '건. data.errors 를 보십시오.', 'bundle');
}

// ---- validate: 여기서 끝난다. DB 를 한 번도 읽지 않았다 -------------
//
// **이 응답에는 설치본에 관한 정보가 하나도 없다** — 기존 service_id 목록도,
// 어느 행이 이미 있는지도 없다. 그래서 이 응답은 AI 대화창에 붙여넣어도 안전한
// 유일한 응답이고, 가이드가 AI 에게 시키는 루프가 정확히 이 엔드포인트다.
if ($action === 'validate') {
    json_out(['valid' => true, 'checked' => $checked, 'warnings' => $warnings]);
}

// ---- dry-run --------------------------------------------------------
if ($action === 'dry-run') {
    $built = sb_build_plan($norm, false, false, $errs, $warnings);
    if ($errs !== []) {
        sb_fail($errs, $checked, '번들 검증에 실패했습니다. 오류 ' . (string) count($errs) . '건. data.errors 를 보십시오.', 'bundle');
    }
    if ($built['conflicts'] !== []) {
        json_err('CONFLICT', 409, [
            'conflicts'      => $built['conflicts'],
            'conflict_count' => count($built['conflicts']),
            'on_conflict'    => $norm['on_conflict'],
            'hint'           => 'on_conflict 를 "merge"(있는 필드만 갱신) 또는 "skip"(기존 행 보존) 으로 바꾸십시오.',
            'field'          => 'on_conflict',
            'message'        => '기존 설정과 충돌합니다. 충돌 ' . (string) count($built['conflicts']) . '건.',
        ]);
    }
    $built['plan']['warnings'] = $warnings;
    json_out([
        'valid'     => true,
        'plan_hash' => $built['hash'],
        'plan'      => $built['plan'],
        'checked'   => $checked,
    ]);
}

// ---- apply ----------------------------------------------------------
//
// `expect_plan_hash` 는 **필수**다. 그 값은 dry-run 응답만이 준다 —
// **dry-run 을 건너뛸 방법이 없다.** 절차 의존 방어는 이 프로젝트에서 반복 실패했다.
$expect = array_key_exists('expect_plan_hash', $req) && is_string($req['expect_plan_hash'])
    ? trim($req['expect_plan_hash']) : '';
if ($expect === '') {
    json_err('VALIDATION_FAILED', 400, [
        'field'   => 'expect_plan_hash',
        'message' => 'expect_plan_hash 가 필요합니다. 먼저 ?action=dry-run 으로 계획을 확인하십시오.',
    ]);
}
if (!is_token_format($expect)) {
    json_err('VALIDATION_FAILED', 400, [
        'field'   => 'expect_plan_hash',
        'message' => 'expect_plan_hash 형식이 올바르지 않습니다. dry-run 응답의 plan_hash 를 그대로 보내십시오.',
    ]);
}
$reason = v_string($req, 'reason', 0, 255, false);

$planErrs     = [];
$applyWarn    = $warnings;
$applied      = null;

// **전부 아니면 전무.** 이 기능의 쓰기는 전부 DML(INSERT/UPDATE)이라 DDL 이 없고,
// 따라서 암묵 커밋이 없다 — 트랜잭션 하나로 감쌀 수 있다. `migrate.php` 가 못 했던 것이다.
// 계획을 **트랜잭션 안에서 잠근 읽기 위에** 다시 세우는 것이 V14-H-2 형태의 창을 닫는다.
/**
 * 동시성으로 진 요청을 **409 로 정규화한다.**
 *
 * ── 왜 필요한가 (실측) ────────────────────────────────────────────────
 * `FOR UPDATE` 는 **이미 있는 행**에는 레코드 잠금을 걸어 뒤에 온 apply 를 확실히
 * 세운다. 그러나 **아직 없는 행**에는 갭 잠금밖에 걸 수 없고 **갭 잠금끼리는 서로
 * 충돌하지 않는다** — 두 apply 가 같은 새 `service_id` 를 만들려 하면 둘 다 읽기를
 * 통과한 뒤 INSERT 단계에서 삽입 의도 잠금이 서로의 갭 잠금과 충돌해 **교착(1213)**
 * 이나 중복키(1062)가 난다. 실측으로 5라운드 중 1라운드가 그 경로였다(200/500).
 *
 * 데이터는 어느 쪽으로든 안전했다 — 트랜잭션이 진 쪽을 통째로 되돌린다. 문제는
 * **운영자가 받는 답**이었다. 500(SERVER_ERROR)은 "무엇을 해야 하는가" 를 말하지 않는다.
 * 원인이 「내가 본 계획이 낡았다」인 것은 확실하므로, 답도 그것이어야 한다 — 409 다.
 *
 * 잡는 것만 잡는다: 교착(1213) · 잠금 대기 초과(1205) · 중복키(1062). 나머지 DB 오류는
 * 그대로 올려 보내 `SERVER_ERROR` 가 되게 한다 — 삼키면 진짜 결함이 조용해진다.
 */
function sb_is_race_error(Throwable $e): bool
{
    if (!($e instanceof PDOException)) {
        return false;
    }
    $errno = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
    return in_array($errno, [1213, 1205, 1062], true);
}

$applyFn = function () use ($norm, $expect, $adminId, $reason, &$planErrs, &$applyWarn) {
    $built = sb_build_plan($norm, true, true, $planErrs, $applyWarn);
    if ($planErrs !== []) {
        // 계획 단계에서만 알 수 있는 오류(DB 대조가 필요한 것들). 아무것도 적용하지 않는다.
        throw new AppError('VALIDATION_FAILED', 400, [
            'errors'      => array_slice($planErrs, 0, SB_MAX_ERRORS),
            'error_count' => count($planErrs),
            'truncated'   => count($planErrs) >= SB_MAX_ERRORS,
            'field'       => 'bundle',
            'message'     => '번들 검증에 실패했습니다. 오류 ' . (string) count($planErrs) . '건. data.errors 를 보십시오.',
        ]);
    }
    if ($built['conflicts'] !== []) {
        throw new AppError('CONFLICT', 409, [
            'conflicts'      => $built['conflicts'],
            'conflict_count' => count($built['conflicts']),
            'on_conflict'    => $norm['on_conflict'],
            'hint'           => 'on_conflict 를 "merge"(있는 필드만 갱신) 또는 "skip"(기존 행 보존) 으로 바꾸십시오.',
            'field'          => 'on_conflict',
            'message'        => '기존 설정과 충돌합니다. 충돌 ' . (string) count($built['conflicts']) . '건.',
        ]);
    }
    if (!hash_equals($built['hash'], $expect)) {
        $built['plan']['warnings'] = $applyWarn;
        throw new AppError('CONFLICT', 409, [
            'expected_plan_hash' => $expect,
            'current_plan_hash'  => $built['hash'],
            'reason'             => '계획을 만든 뒤 설정이 바뀌었거나, 다른 관리자가 먼저 적용했습니다.',
            'hint'               => 'dry-run 을 다시 호출해 새 계획을 확인한 뒤 그 plan_hash 로 다시 apply 하십시오. 아무것도 적용되지 않았습니다.',
            // 설계 5-4 가 "새 계획을 함께 준다" 고 적었다. 그것을 여기 싣는다.
            'plan'               => $built['plan'],
            'field'              => 'expect_plan_hash',
            'message'            => '계획이 낡았습니다. 적용하지 않았습니다.',
        ]);
    }

    $now    = now_utc();
    $result = [
        'settings' => ['updated' => [], 'unchanged' => [], 'skipped' => []],
        'services' => ['created' => [], 'updated' => [], 'unchanged' => [], 'skipped' => []],
        'secrets'  => ['created' => [], 'updated' => [], 'unchanged' => [], 'skipped' => []],
    ];
    foreach ($built['plan']['settings']['unchanged'] as $u) {
        $result['settings']['unchanged'][] = $u['skey'];
    }

    // --- settings ------------------------------------------------
    $sBefore = [];
    $sAfter  = [];
    foreach ($built['ops']['settings'] as $k => $o) {
        setting_set($k, $o['to'], $o['vtype'], $o['label']);
        $result['settings']['updated'][] = $k;
        $sBefore[$k] = $o['from'];
        $sAfter[$k]  = $o['to'];
    }
    if ($sAfter !== []) {
        // 행별 기존 action 이름을 그대로 쓴다 — 기존 감사 화면과 필터가 그대로 동작한다.
        admin_log_write($adminId, 'setting.update', 'setting',
            implode(',', array_slice(array_keys($sAfter), 0, 5)), $sBefore, $sAfter,
            'bundle:' . substr($built['hash'], 0, 16));
    }

    // --- services · secrets --------------------------------------
    foreach ($built['ops']['services'] as $svc) {
        $sid = $svc['service_id'];
        if ($svc['op'] === 'create') {
            $row = array_merge([
                'service_id'            => $sid,
                'service_name'          => $sid,
                'service_secret_hash'   => null,
                'is_confidential'       => 0,
                'redirect_whitelist'    => '[]',
                'allowed_origins'       => '[]',
                'auto_join'             => 1,
                'default_account_level' => null,
                'session_ttl'           => (int) SESSION_TTL,
                'refresh_ttl'           => (int) REFRESH_TTL,
                'payload_fields'        => null,
                'allow_register'        => 1,
                'status'                => 'active',
                'description'           => null,
            ], $svc['set']);
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            db_insert(T_SERVICES, $row);
            $result['services']['created'][] = $sid;
            admin_log_write($adminId, 'service.create', 'service', $sid, null,
                array_merge($svc['after'], ['service_id' => $sid]), 'bundle:' . substr($built['hash'], 0, 16));
        } elseif ($svc['op'] === 'update') {
            $set = $svc['set'];
            $set['updated_at'] = $now;
            db_update(T_SERVICES, $set, 'service_id = ?', [$sid]);
            $result['services']['updated'][] = $sid;
            admin_log_write($adminId, 'service.update', 'service', $sid,
                $svc['before'] === [] ? null : $svc['before'],
                $svc['after'] === [] ? null : $svc['after'], 'bundle:' . substr($built['hash'], 0, 16));
        } elseif ($svc['op'] === 'skip') {
            $result['services']['skipped'][] = $sid;
        } else {
            $result['services']['unchanged'][] = $sid;
        }

        foreach ($svc['secrets'] as $sec) {
            $ref = $sid . '/' . $sec['secret_key'];
            if ($sec['op'] === 'create') {
                $row = array_merge([
                    'service_id'        => $sid,
                    'secret_key'        => $sec['secret_key'],
                    'secret_value_enc'  => enc(''),
                    'value_type'        => 'string',
                    'min_account_level' => null,
                    'min_billing_tier'  => null,
                    'min_role'          => null,
                    'is_masked'         => 1,
                    'sort_order'        => 0,
                    'description'       => null,
                    'status'            => 'active',
                ], $sec['set']);
                $row['created_at'] = $now;
                $row['updated_at'] = $now;
                db_insert(T_SECRETS, $row);
                $result['secrets']['created'][] = $ref;
                admin_log_write($adminId, 'secret.create', 'secret', $ref, null,
                    array_merge($sec['after'], ['service_id' => $sid, 'secret_key' => $sec['secret_key']]),
                    'bundle:' . substr($built['hash'], 0, 16));
            } elseif ($sec['op'] === 'update') {
                $set = $sec['set'];
                $set['updated_at'] = $now;
                db_update(T_SECRETS, $set, 'id = ?', [$sec['id']]);
                $result['secrets']['updated'][] = $ref;
                admin_log_write($adminId, 'secret.update', 'secret', $ref,
                    $sec['before'] === [] ? null : $sec['before'],
                    $sec['after'] === [] ? null : $sec['after'], 'bundle:' . substr($built['hash'], 0, 16));
            } elseif ($sec['op'] === 'skip') {
                $result['secrets']['skipped'][] = $ref;
            } else {
                $result['secrets']['unchanged'][] = $ref;
            }
        }
    }

    $counts = $built['counts'];
    $result['counts'] = $counts;
    $result['secrets_pending_value'] = $built['pending'];
    // environment 대조는 계획의 일부다. 적용 결과에도 그대로 실어 화면이 같은 것을 보게 한다.
    $result['environment_check'] = $built['environment_check'];

    // 요약 1행. **무변경 apply 는 감사 로그를 남기지 않는다** — settings.php 의 PUT 가
    // updated 가 비면 로그를 남기지 않는 것과 같은 규칙이다.
    // **번들 전문도 시크릿 값도 남기지 않는다.** 카운트와 대상 키 목록(자름)만이다.
    $changedAny = $counts['settings_update'] + $counts['services_create'] + $counts['services_update']
        + $counts['secrets_create'] + $counts['secrets_update'];
    $logId = null;
    if ($changedAny > 0) {
        $logId = admin_log_write($adminId, 'setting.bundle_apply', 'setting', 'bundle', null, [
            'counts'   => $counts,
            'settings' => array_slice($result['settings']['updated'], 0, 20),
            'services' => array_slice(array_merge($result['services']['created'], $result['services']['updated']), 0, 20),
            'secrets'  => array_slice(array_merge($result['secrets']['created'], $result['secrets']['updated']), 0, 20),
            'on_conflict' => $norm['on_conflict'],
        ], 'bundle:' . substr($built['hash'], 0, 16) . ($reason !== null ? ' ' . $reason : ''));
    }

    return ['hash' => $built['hash'], 'result' => $result, 'admin_log_id' => $logId];
};

try {
    $applied = db_tx($applyFn);
} catch (Throwable $e) {
    if (!sb_is_race_error($e)) {
        throw $e;
    }
    // 상세는 서버 로그에만. 응답에 SQL·errno 를 싣지 않는다.
    error_log('[ulogin] settings bundle apply lost a concurrent race: ' . $e->getMessage());
    throw new AppError('CONFLICT', 409, [
        'expected_plan_hash' => $expect,
        'current_plan_hash'  => null,
        'reason'             => '다른 관리자가 같은 대상을 동시에 적용했습니다.',
        'hint'               => 'dry-run 을 다시 호출해 새 계획을 확인한 뒤 그 plan_hash 로 다시 apply 하십시오. 아무것도 적용되지 않았습니다.',
        'field'              => 'expect_plan_hash',
        'message'            => '동시에 적용된 변경과 충돌했습니다. 적용하지 않았습니다.',
    ]);
}

json_out([
    'applied'      => true,
    'plan_hash'    => $applied['hash'],
    'result'       => $applied['result'],
    'warnings'     => $applyWarn,
    'admin_log_id' => $applied['admin_log_id'],
]);
