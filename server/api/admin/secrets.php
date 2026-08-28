<?php
declare(strict_types=1);
/**
 * 서비스 시크릿 CRUD + 평문 조회 — /api/admin/secrets.php
 * 담당 계약: §8-15(목록) · §8-16(생성) · §8-17(수정) · §8-18(삭제) · §8-19(reveal)
 * 실제 호출(리더 판정 D-0):
 *   GET    /api/admin/secrets.php?service_id=...                (목록)
 *   GET    /api/admin/secrets.php?service_id=...&action=reveal&id=1   (평문 1건)
 *   POST   /api/admin/secrets.php?service_id=...
 *   PUT    /api/admin/secrets.php?service_id=...&id=1
 *   DELETE /api/admin/secrets.php?service_id=...&id=1
 * 최소 역할: admin / **reveal 은 superadmin** (리더 판정 D-7)
 *
 * 응답 최상위 키
 *   GET 목록 : service_id, items, total, page, per_page
 *   reveal   : id, secret_key, secret_value, value_type, revealed_at, admin_log_id
 *   POST     : secret, admin_log_id                                   (201)
 *   PUT      : secret, changed_fields, value_changed, admin_log_id
 *   DELETE   : deleted, id, secret_key, service_id, admin_log_id
 *
 * 값은 enc() 로 AES-256-CBC 암호화해 저장한다.
 * **리더 판정 D-24 / D-32: 목록·상세·POST·PUT 응답의 secret_value 는 is_masked 와 무관하게 항상 "****".**
 * 평문은 reveal 만 반환하며, **reveal 사실을 admin_logs 에 남긴다(값은 남기지 않는다)**.
 * is_masked 는 UI 힌트로만 남아 응답에 계속 포함된다.
 */
require_once __DIR__ . '/../../core/bootstrap.php';

only_method('GET', 'POST', 'PUT', 'DELETE');

// =====================================================================
// 헬퍼
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

if (!function_exists('admin_forbid')) {
    function admin_forbid(string $message, ?string $field = null): void
    {
        throw new AppError('FORBIDDEN', 403, ['message' => $message, 'field' => $field]);
    }
}

/**
 * 시크릿 행 → 응답 객체. 암호문·해시는 절대 내보내지 않는다.
 *
 * 리더 판정 D-24 / D-32 (H-2): **is_masked 값과 무관하게 secret_value 는 항상 "****"** 다.
 * 목록·상세·생성(POST)·수정(PUT) 응답이 모두 이 함수를 지나므로 평문 유출 경로가 한 곳도 남지 않는다.
 * 평문은 `?action=reveal`(superadmin + admin_logs 의 secret.reveal 기록) 한 경로에서만 나간다.
 *
 * is_masked 를 보안 경계로 쓸 수 없었던 이유: admin 이 `{"is_masked":false}` 로 PUT 한 뒤
 * 목록을 조회하면 superadmin 전용 reveal 과 그 감사 로그를 통째로 우회할 수 있었다.
 * 컬럼은 UI 힌트("값 보기" 버튼 노출 여부)로만 유지하고 응답에 계속 포함한다.
 */
function admin_secret_public(array $r): array
{
    $masked = (bool) $r['is_masked'];
    return [
        'id'                => (int) $r['id'],
        'secret_key'        => (string) $r['secret_key'],
        'secret_value'      => '****',
        'is_masked'         => $masked,
        'value_type'        => (string) $r['value_type'],
        'min_account_level' => $r['min_account_level'] !== null ? (string) $r['min_account_level'] : null,
        'min_billing_tier'  => $r['min_billing_tier'] !== null ? (string) $r['min_billing_tier'] : null,
        'min_role'          => $r['min_role'] !== null ? (string) $r['min_role'] : null,
        'sort_order'        => (int) $r['sort_order'],
        'description'       => $r['description'] !== null ? (string) $r['description'] : null,
        'status'            => (string) $r['status'],
        'created_at'        => iso($r['created_at']),
        'updated_at'        => iso($r['updated_at']),
    ];
}

/** 최소등급 enum. 계약 §8-17 실패 예시의 메시지 형식을 따른다. */
function admin_min_grade(array $in, string $key, array $allowed): ?string
{
    if (!array_key_exists($key, $in) || $in[$key] === null || $in[$key] === '') {
        return null;
    }
    $v = $in[$key];
    if (!is_string($v) || !in_array($v, $allowed, true)) {
        v_fail($key, $key . ' 값이 올바르지 않습니다. (' . implode('|', $allowed) . ')');
    }
    return $v;
}

// ---------------------------------------------------------------------
// D-54 (감사 C-3 잔여) — 게이트 없는 민감 시크릿을 API 계층에서 막는다
//
// 설치기(install.php)만 강제하면, 설치 후 관리자 콘솔의 POST /api/admin/secrets.php
// 로 게이트 없는 `ai_api_key` 를 그냥 만들 수 있다 → C-3 이 그 경로로 되살아난다.
// 강제는 **API 계층**에 있어야 두 경로가 모두 막힌다.
// ---------------------------------------------------------------------

if (!function_exists('admin_secret_key_is_sensitive')) {
    /**
     * 민감 키 판정. **정규식은 `install.php:1457` `ins_is_sensitive_key()` 와 글자 단위로 같다**
     * (출처: `_workspace/08a_fix2_install_report.md` §2 「민감 키 판정 규칙」).
     *
     * 규칙이 두 곳에서 갈라지면 한쪽만 막히는 상태가 된다. 설치기를 고칠 때 이 함수도 함께
     * 고쳐라 — 공용 파일로 뺄 수 없는 이유는 install.php 가 core/ 를 로드하지 않기 때문이다.
     *
     * 민감: `ai_api_key` `api_key` `apikey` `x_secret` `client-secret` `refresh_token`
     *       `db_password` `passwd` `aws_credentials` (대소문자 무시, 복수형 허용)
     * 비민감: `api_base` `model` `endpoint` `org_id` `api_key_hint` `keyring`
     */
    function admin_secret_key_is_sensitive(string $key): bool
    {
        return preg_match(
            '/(?:^|[_\-])(api_?key|secret|token|password|passwd|credential)s?$/',
            strtolower(trim($key))
        ) === 1;
    }
}

if (!function_exists('admin_secret_gate_is_effective')) {
    /**
     * 실효 게이트가 하나라도 걸려 있는가.
     *
     * **랭크 0 값은 게이트로 세지 않는다.** `guest`(0) / `free`(0) / `user`(0) 는
     * `rank_of()` 가 0 을 돌려주므로 `*_gte()` 가 항상 참 — "제한 없음"과 결과가 같다.
     * 이 값을 게이트로 인정하면 `min_billing_tier='free'` 로 "게이트를 건 척" 하고
     * 통과하는 우회가 생긴다(설치기 담당이 발견한 함정. 08a §2 예외·한계 1번).
     * 미지 열거값도 rank 0 이므로 같은 취급이다 — fail-closed 다.
     */
    function admin_secret_gate_is_effective(?string $minLevel, ?string $minTier, ?string $minRole): bool
    {
        if ($minLevel !== null && $minLevel !== '' && rank_of(ACCOUNT_LEVEL_RANK, $minLevel) > 0) {
            return true;
        }
        if ($minTier !== null && $minTier !== '' && rank_of(BILLING_TIER_RANK, $minTier) > 0) {
            return true;
        }
        if ($minRole !== null && $minRole !== '' && rank_of(ROLE_RANK, $minRole) > 0) {
            return true;
        }
        return false;
    }
}

if (!function_exists('admin_secret_guard_gate')) {
    /**
     * 민감 키 + 실효 게이트 0개 → 명시적 확인 플래그 `allow_public: true` 없이는 거부한다.
     *
     * 비민감 키(`api_base` / `model` 등)는 게이트 없이 허용한다 — 전부 잠그면 데모가 안 돌아
     * 운영자가 게이트를 통째로 풀어버린다. 그게 더 위험하다(리더 판정 D-47).
     *
     * @param array $body 요청 본문 (플래그를 읽는다)
     * @return bool 플래그로 통과했는가 (= admin_logs 에 그 사실을 남겨야 한다)
     */
    function admin_secret_guard_gate(string $key, ?string $minLevel, ?string $minTier,
                                     ?string $minRole, array $body): bool
    {
        if (!admin_secret_key_is_sensitive($key)) {
            return false;
        }
        if (admin_secret_gate_is_effective($minLevel, $minTier, $minRole)) {
            return false;
        }
        if (v_bool($body, 'allow_public', false, false) !== true) {
            // field 는 D-47 이 민감 값의 기본 게이트로 지정한 min_billing_tier 를 가리킨다.
            throw new AppError('VALIDATION_FAILED', 400, [
                'field'   => 'min_billing_tier',
                'message' => '민감한 시크릿(' . $key . ')에는 최소 등급 게이트가 필요합니다. '
                    . '게이트를 비우면 가입한 모든 사용자가 이 값을 받습니다. '
                    . 'min_billing_tier=lite 를 권장하며, 의도한 공개라면 allow_public=true 를 함께 보내십시오. '
                    . '(free / guest / user 는 랭크 0 이라 게이트로 인정되지 않습니다.)',
            ]);
        }
        return true;
    }
}

/** 값 검증. value_type 에 따라 JSON / URL 형식을 확인한다. */
function admin_secret_value(string $value, string $valueType): void
{
    if ($valueType === 'json') {
        $d = json_decode($value, true);
        if ($d === null && strtolower(trim($value)) !== 'null') {
            v_fail('secret_value', 'value_type 이 json 이면 유효한 JSON 문자열이어야 합니다.');
        }
    } elseif ($valueType === 'url') {
        $p = parse_url(trim($value));
        if ($p === false || empty($p['scheme']) || empty($p['host'])
            || !in_array(strtolower((string) $p['scheme']), ['http', 'https'], true)) {
            v_fail('secret_value', 'value_type 이 url 이면 http(s) 절대 URL 이어야 합니다.');
        }
    }
}

// =====================================================================

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$ctx    = require_auth();
require_global_role($ctx, 'admin');

$adminId   = (int) $ctx['user']['id'];
$now       = now_utc();
$serviceId = (string) v_string($_GET, 'service_id', 1, 64, true);

// 서비스 존재 확인 (status 무관 — 관리자는 disabled 서비스의 시크릿도 다룬다)
if (db_val('SELECT id FROM ' . T_SERVICES . ' WHERE service_id = ?', [$serviceId]) === null) {
    throw new AppError('NOT_FOUND', 404, ['message' => '서비스를 찾을 수 없습니다.']);
}

/** 이 서비스의 시크릿 1건. 다른 서비스의 id 를 넣어도 찾히지 않는다. */
$loadSecret = function ($rawId) use ($serviceId) {
    $id = (int) $rawId;
    if ($id <= 0) {
        throw new AppError('VALIDATION_FAILED', 400, ['field' => 'id', 'message' => '시크릿 id 가 필요합니다.']);
    }
    $r = db_one('SELECT * FROM ' . T_SECRETS . ' WHERE id = ? AND service_id = ?', [$id, $serviceId]);
    if ($r === null) {
        throw new AppError('NOT_FOUND', 404, ['message' => '시크릿을 찾을 수 없습니다.']);
    }
    return $r;
};

// -----------------------------------------------------------------
// GET — 목록 또는 reveal
// -----------------------------------------------------------------
if ($method === 'GET') {
    $action = v_enum($_GET, 'action', ['reveal'], false);

    if ($action === 'reveal') {
        // 리더 판정 D-7 / 계약 §8-19 — superadmin 전용
        if (!role_gte(ctx_global_role($ctx), 'superadmin')) {
            admin_forbid('시크릿 평문 조회는 최고관리자만 가능합니다.');
        }
        $row = $loadSecret(in_raw($_GET, 'id'));

        // 조회인데도 반드시 기록한다. 관리자 계정이 탈취되면 키가 통째로 샌다 —
        // 최소한 언제 누가 봤는지는 남아야 한다. **값은 기록하지 않는다.**
        $logId = admin_log_write($adminId, 'secret.reveal', 'secret', (string) $row['id'],
            null,
            ['service_id' => $serviceId, 'secret_key' => (string) $row['secret_key'], 'revealed' => true],
            null);

        json_out([
            'id'           => (int) $row['id'],
            'secret_key'   => (string) $row['secret_key'],
            'secret_value' => dec((string) $row['secret_value_enc']),
            'value_type'   => (string) $row['value_type'],
            'revealed_at'  => iso($now),
            'admin_log_id' => $logId,
        ]);
    }

    list($page, $perPage, $offset) = page_params($_GET, 20);

    $total = (int) db_val('SELECT COUNT(*) FROM ' . T_SECRETS . ' WHERE service_id = ?', [$serviceId]);
    $rows  = db_all(
        'SELECT * FROM ' . T_SECRETS . ' WHERE service_id = ?'
        . ' ORDER BY sort_order ASC, id ASC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
        [$serviceId]
    );

    $items = [];
    foreach ($rows as $r) {
        $items[] = admin_secret_public($r);
    }

    json_out([
        'service_id' => $serviceId,
        'items'      => $items,
        'total'      => $total,
        'page'       => $page,
        'per_page'   => $perPage,
    ]);
}

require_csrf();

// -----------------------------------------------------------------
// POST — 생성 (계약 §8-16)
// -----------------------------------------------------------------
if ($method === 'POST') {
    $body = body_json();

    $key = (string) v_string($body, 'secret_key', 2, 100, true);
    if (preg_match('/^[a-z0-9][a-z0-9_]{1,99}$/', $key) !== 1) {
        v_fail('secret_key', '시크릿 키는 영문 소문자·숫자로 시작하고 소문자·숫자·밑줄 2~100자여야 합니다.');
    }
    if (db_val('SELECT id FROM ' . T_SECRETS . ' WHERE service_id = ? AND secret_key = ?', [$serviceId, $key]) !== null) {
        throw new AppError('DUPLICATE_SECRET_KEY', 409, ['field' => 'secret_key']);
    }

    if (!array_key_exists('secret_value', $body) || $body['secret_value'] === null) {
        v_fail('secret_value', '필수 입력 항목입니다.');
    }
    if (!is_string($body['secret_value'])) {
        v_fail('secret_value', '문자열이어야 합니다.');
    }
    $value     = (string) $body['secret_value'];
    $valueType = (string) v_enum($body, 'value_type', ['string', 'json', 'url'], false, 'string');
    admin_secret_value($value, $valueType);

    $row = [
        'service_id'        => $serviceId,
        'secret_key'        => $key,
        'secret_value_enc'  => enc($value),          // 평문 저장 금지
        'value_type'        => $valueType,
        'min_account_level' => admin_min_grade($body, 'min_account_level', array_keys(ACCOUNT_LEVEL_RANK)),
        'min_billing_tier'  => admin_min_grade($body, 'min_billing_tier', array_keys(BILLING_TIER_RANK)),
        'min_role'          => admin_min_grade($body, 'min_role', array_keys(ROLE_RANK)),
        'is_masked'         => v_bool($body, 'is_masked', false, true) ? 1 : 0,
        'sort_order'        => (int) v_int($body, 'sort_order', -9999, 9999, false, 0),
        'description'       => v_string($body, 'description', 0, 255, false),
        'status'            => (string) v_enum($body, 'status', ['active', 'disabled'], false, 'active'),
        'created_at'        => $now,
        'updated_at'        => $now,
    ];

    // D-54: 게이트 없는 민감 시크릿은 여기서 막는다. DB 쓰기보다 앞이다.
    $ackPublic = admin_secret_guard_gate(
        $key, $row['min_account_level'], $row['min_billing_tier'], $row['min_role'], $body
    );

    try {
        $newId = db_insert(T_SECRETS, $row);
    } catch (Throwable $e) {
        if (db_is_duplicate($e)) {
            throw new AppError('DUPLICATE_SECRET_KEY', 409, ['field' => 'secret_key']);
        }
        throw $e;
    }

    // 값 평문은 남기지 않는다 — "(set)" 마커만 (계약 §8-16)
    $logAfter = [
        'service_id'   => $serviceId,
        'secret_key'   => $key,
        'value_type'   => $valueType,
        'is_masked'    => (bool) $row['is_masked'],
        'status'       => $row['status'],
        'secret_value' => '(set)',
    ];
    if ($ackPublic) {
        // D-54: 게이트 없이 만든 민감 시크릿이라는 사실을 감사 로그에 남긴다. 값은 남기지 않는다.
        $logAfter['allow_public'] = true;
        $logAfter['gate']         = 'none (sensitive key, acknowledged)';
    }
    $logId = admin_log_write($adminId, 'secret.create', 'secret', (string) $newId, null,
        $logAfter, v_string($body, 'reason', 0, 255, false));

    $fresh = db_one('SELECT * FROM ' . T_SECRETS . ' WHERE id = ?', [$newId]);
    json_out([
        // D-32: 생성 직후에도 값을 되돌려주지 않는다 — 항상 "****"
        'secret'       => admin_secret_public($fresh === null ? $row + ['id' => $newId] : $fresh),
        'admin_log_id' => $logId,
    ], 201);
}

// -----------------------------------------------------------------
// PUT — 수정 (계약 §8-17)
// -----------------------------------------------------------------
if ($method === 'PUT') {
    $body   = body_json();
    $target = $loadSecret(in_raw($_GET, 'id'));
    $id     = (int) $target['id'];

    $set     = [];
    $before  = [];
    $after   = [];
    $changed = [];

    $mark = function (string $field, $newVal) use (&$set, &$before, &$after, &$changed, $target) {
        $old  = array_key_exists($field, $target) ? $target[$field] : null;
        $same = ($old === null && $newVal === null)
            || ($old !== null && $newVal !== null && (string) $old === (string) $newVal);
        if ($same) {
            return;
        }
        $set[$field]    = $newVal;
        $before[$field] = $old;
        $after[$field]  = $newVal;
        $changed[]      = $field;
    };

    if (array_key_exists('secret_key', $body)) {
        $key = (string) v_string($body, 'secret_key', 2, 100, true);
        if (preg_match('/^[a-z0-9][a-z0-9_]{1,99}$/', $key) !== 1) {
            v_fail('secret_key', '시크릿 키는 영문 소문자·숫자로 시작하고 소문자·숫자·밑줄 2~100자여야 합니다.');
        }
        if ($key !== (string) $target['secret_key']) {
            $dup = db_val('SELECT id FROM ' . T_SECRETS . ' WHERE service_id = ? AND secret_key = ? AND id <> ?',
                [$serviceId, $key, $id]);
            if ($dup !== null) {
                throw new AppError('DUPLICATE_SECRET_KEY', 409, ['field' => 'secret_key']);
            }
        }
        $mark('secret_key', $key);
    }

    $valueType = (string) $target['value_type'];
    if (array_key_exists('value_type', $body)) {
        $valueType = (string) v_enum($body, 'value_type', ['string', 'json', 'url'], true);
        $mark('value_type', $valueType);
    }

    // secret_value: 미포함 = 건드리지 않음 / 빈 문자열 = 값을 비운다 / null = VALIDATION_FAILED
    $valueChanged = false;
    if (array_key_exists('secret_value', $body)) {
        if ($body['secret_value'] === null) {
            v_fail('secret_value', '값을 비우려면 빈 문자열을 보내 주세요. null 은 허용되지 않습니다.');
        }
        if (!is_string($body['secret_value'])) {
            v_fail('secret_value', '문자열이어야 합니다.');
        }
        $newValue = (string) $body['secret_value'];
        admin_secret_value($newValue, $valueType);
        $set['secret_value_enc'] = enc($newValue);
        $valueChanged            = true;
        $changed[]               = 'secret_value';
        // 값 평문은 어느 쪽에도 남기지 않는다 (계약 §8-17)
        $after['secret_value']   = '(changed)';
    }

    if (array_key_exists('min_account_level', $body)) {
        $mark('min_account_level', admin_min_grade($body, 'min_account_level', array_keys(ACCOUNT_LEVEL_RANK)));
    }
    if (array_key_exists('min_billing_tier', $body)) {
        $mark('min_billing_tier', admin_min_grade($body, 'min_billing_tier', array_keys(BILLING_TIER_RANK)));
    }
    if (array_key_exists('min_role', $body)) {
        $mark('min_role', admin_min_grade($body, 'min_role', array_keys(ROLE_RANK)));
    }
    if (array_key_exists('is_masked', $body)) {
        $mark('is_masked', v_bool($body, 'is_masked', true) ? 1 : 0);
    }
    if (array_key_exists('sort_order', $body)) {
        $mark('sort_order', v_int($body, 'sort_order', -9999, 9999, true));
    }
    if (array_key_exists('description', $body)) {
        $mark('description', v_string($body, 'description', 0, 255, false));
    }
    if (array_key_exists('status', $body)) {
        $mark('status', v_enum($body, 'status', ['active', 'disabled'], true));
    }

    // D-54: 키 이름 또는 게이트 3필드를 **건드리는** 요청이면 적용 후 상태를 검사한다.
    // 생성만 막고 수정을 열어두면 의미가 없다 — 게이트를 전부 지우는 PUT 이 정확히 그 경로다.
    // 반대로 이 4개를 건드리지 않는 요청(description·status·sort_order 만 바꾸는 등)은
    // 검사하지 않는다. 이번 수정 이전에 만들어진 게이트 없는 행의 편집을 통째로 막으면
    // 운영자가 값을 고칠 수도, 게이트를 걸 수도 없는 상태가 된다(게이트를 걸려면 PUT 이 필요하다).
    $gateTouched = array_key_exists('secret_key', $body)
        || array_key_exists('min_account_level', $body)
        || array_key_exists('min_billing_tier', $body)
        || array_key_exists('min_role', $body);
    $ackPublic = false;
    if ($gateTouched) {
        // $set 에 없으면 무변경 = 기존 값. $mark() 는 바뀔 때만 $set 에 넣는다.
        $ackPublic = admin_secret_guard_gate(
            array_key_exists('secret_key', $set)
                ? (string) $set['secret_key'] : (string) $target['secret_key'],
            array_key_exists('min_account_level', $set)
                ? $set['min_account_level'] : $target['min_account_level'],
            array_key_exists('min_billing_tier', $set)
                ? $set['min_billing_tier'] : $target['min_billing_tier'],
            array_key_exists('min_role', $set)
                ? $set['min_role'] : $target['min_role'],
            $body
        );
    }

    if ($set !== []) {
        $set['updated_at'] = $now;
        db_update(T_SECRETS, $set, 'id = ?', [$id]);
    }

    if ($ackPublic) {
        // D-54: 게이트를 비운 채로 통과했다는 사실을 감사 로그에 남긴다. 값은 남기지 않는다.
        $after['allow_public'] = true;
        $after['gate']         = 'none (sensitive key, acknowledged)';
    }

    $logId = null;
    if ($changed !== [] || $ackPublic) {
        $logId = admin_log_write($adminId, 'secret.update', 'secret', (string) $id,
            $before === [] ? null : $before, $after === [] ? null : $after,
            v_string($body, 'reason', 0, 255, false));
    }

    $fresh = db_one('SELECT * FROM ' . T_SECRETS . ' WHERE id = ?', [$id]);
    json_out([
        'secret'         => admin_secret_public($fresh === null ? $target : $fresh),
        'changed_fields' => $changed,
        'value_changed'  => $valueChanged,
        'admin_log_id'   => $logId,
    ]);
}

// -----------------------------------------------------------------
// DELETE — 삭제 (계약 §8-18)
// -----------------------------------------------------------------
$in     = array_merge(body_json(), $_GET);
$target = $loadSecret(in_raw($in, 'id'));
$id     = (int) $target['id'];
$key    = (string) $target['secret_key'];

db_exec('DELETE FROM ' . T_SECRETS . ' WHERE id = ? AND service_id = ?', [$id, $serviceId]);

$logId = admin_log_write($adminId, 'secret.delete', 'secret', (string) $id,
    ['service_id' => $serviceId, 'secret_key' => $key, 'value_type' => (string) $target['value_type']],
    ['deleted' => true],
    v_string($in, 'reason', 0, 255, false));

json_out([
    'deleted'      => true,
    'id'           => $id,
    'secret_key'   => $key,
    'service_id'   => $serviceId,
    'admin_log_id' => $logId,
]);
