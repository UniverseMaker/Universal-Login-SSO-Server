<?php
declare(strict_types=1);
/**
 * 서비스 CRUD — /api/admin/services.php
 * 담당 계약: §8-11(GET 목록) · §8-12(POST 생성) · §8-13(PUT 수정) · §8-14(DELETE 삭제)
 * 실제 호출(리더 판정 D-0):
 *   GET    /api/admin/services.php?q=&status=&service_id=&page=&per_page=
 *   POST   /api/admin/services.php
 *   PUT    /api/admin/services.php?service_id=...
 *   DELETE /api/admin/services.php?service_id=...   (force / confirm / reason 은 쿼리·본문 모두 가능)
 * 최소 역할: admin
 *
 * 응답 최상위 키
 *   GET    : items, total, page, per_page
 *   POST   : service, service_secret_plain, admin_log_id            (201)
 *   PUT    : service, service_secret_plain, changed_fields, admin_log_id
 *   DELETE : deleted, service_id, purged, admin_log_id
 *
 * service_secret_hash 는 어떤 응답에도 포함하지 않는다. 설정 여부만 has_secret 으로 알린다.
 * service_secret 은 password_hash(PASSWORD_DEFAULT) 로 저장한다
 * (exchange.php 의 검증 순서 — backend_report 2-9 와 맞춘 것).
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

/** 서비스 행 → 응답 객체 (계약 §8-11 필드 순서). service_secret_hash 는 절대 넣지 않는다. */
function admin_service_public(array $r, int $secretCount, int $userCount): array
{
    $payloadFields = $r['payload_fields'];
    return [
        'service_id'            => (string) $r['service_id'],
        'service_name'          => (string) $r['service_name'],
        'description'           => $r['description'] !== null ? (string) $r['description'] : null,
        'is_confidential'       => (bool) $r['is_confidential'],
        'has_secret'            => $r['service_secret_hash'] !== null && $r['service_secret_hash'] !== '',
        'redirect_whitelist'    => array_values(json_col($r['redirect_whitelist'])),
        'allowed_origins'       => array_values(json_col($r['allowed_origins'])),
        'auto_join'             => (bool) $r['auto_join'],
        'default_account_level' => $r['default_account_level'] !== null ? (string) $r['default_account_level'] : null,
        'session_ttl'           => (int) $r['session_ttl'],
        'refresh_ttl'           => (int) $r['refresh_ttl'],
        // NULL = 기본셋. 문자열로 내보내지 않는다(파싱된 배열 또는 null)
        'payload_fields'        => ($payloadFields === null || $payloadFields === '')
            ? null : array_values(json_col($payloadFields)),
        'allow_register'        => (bool) $r['allow_register'],
        'status'                => (string) $r['status'],
        'secret_count'          => $secretCount,
        'user_count'            => $userCount,
        'created_at'            => iso($r['created_at']),
        'updated_at'            => iso($r['updated_at']),
    ];
}

/**
 * URL 배열 검증.
 * $mode = 'redirect' : 절대 URL. 쿼리스트링·프래그먼트 금지(비교가 origin+path 라서)
 *         'origin'   : scheme://host[:port] 만. 경로 금지. 문자열 "null" 은 file:// 허용용으로 통과
 * @param mixed $v
 */
function admin_url_list($v, string $field, string $mode): array
{
    if ($v === null) {
        return [];
    }
    if (!is_array($v)) {
        v_fail($field, '문자열 배열이어야 합니다.');
    }
    $out = [];
    foreach ($v as $item) {
        if (!is_string($item)) {
            v_fail($field, '각 항목은 문자열이어야 합니다.');
        }
        $s = trim($item);
        if ($s === '') {
            continue;
        }
        if (mb_strlen($s, 'UTF-8') > 500) {
            v_fail($field, '각 항목은 500자 이내여야 합니다.');
        }
        if ($mode === 'origin' && $s === 'null') {
            // file:// 에서 온 요청(Origin: null) 허용 표기
            $out[] = 'null';
            continue;
        }
        if (strpos($s, '?') !== false) {
            v_fail($field, $mode === 'redirect'
                ? '복귀 주소에 쿼리스트링을 포함할 수 없습니다.'
                : '허용 출처에 쿼리스트링을 포함할 수 없습니다.');
        }
        if (strpos($s, '#') !== false) {
            v_fail($field, '프래그먼트(#)를 포함할 수 없습니다.');
        }
        $p = parse_url($s);
        if ($p === false || empty($p['scheme']) || empty($p['host'])) {
            v_fail($field, '절대 URL 형식이어야 합니다. (예: https://app.example.com/callback.html)');
        }
        $scheme = strtolower((string) $p['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            v_fail($field, 'http 또는 https 만 허용됩니다.');
        }
        if ($mode === 'origin') {
            $path = isset($p['path']) ? (string) $p['path'] : '';
            if ($path !== '' && $path !== '/') {
                v_fail($field, '허용 출처에는 경로를 포함할 수 없습니다. (scheme://host[:port])');
            }
            $s = $scheme . '://' . strtolower((string) $p['host'])
                . (isset($p['port']) ? ':' . (int) $p['port'] : '');
        }
        if (!in_array($s, $out, true)) {
            $out[] = $s;
        }
    }
    if (count($out) > 50) {
        v_fail($field, '항목은 50개 이내여야 합니다.');
    }
    return $out;
}

/** 서비스 1건 조회 (status 무관 — 관리자는 disabled 서비스도 다룬다) */
function admin_service_row(?string $serviceId): array
{
    $sid = $serviceId === null ? '' : trim($serviceId);
    if ($sid === '') {
        throw new AppError('VALIDATION_FAILED', 400, ['field' => 'service_id', 'message' => 'service_id 가 필요합니다.']);
    }
    $r = db_one('SELECT * FROM ' . T_SERVICES . ' WHERE service_id = ?', [$sid]);
    if ($r === null) {
        throw new AppError('NOT_FOUND', 404, ['message' => '서비스를 찾을 수 없습니다.']);
    }
    return $r;
}

/** @return array{0:int,1:int} [secret_count, user_count] */
function admin_service_counts(string $serviceId): array
{
    $s = (int) db_val('SELECT COUNT(*) FROM ' . T_SECRETS . ' WHERE service_id = ?', [$serviceId]);
    $u = (int) db_val('SELECT COUNT(*) FROM ' . T_USER_SERVICES . ' WHERE service_id = ?', [$serviceId]);
    return [$s, $u];
}

// =====================================================================

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$ctx    = require_auth();
require_global_role($ctx, 'admin');

$adminId = (int) $ctx['user']['id'];
$now     = now_utc();

// -----------------------------------------------------------------
// GET — 목록 (계약 §8-11)
// -----------------------------------------------------------------
if ($method === 'GET') {
    $q      = v_string($_GET, 'q', 1, 100, false);
    $status = v_enum($_GET, 'status', ['active', 'disabled'], false);
    $only   = v_string($_GET, 'service_id', 1, 64, false);
    list($page, $perPage, $offset) = page_params($_GET, 20);

    $where  = ['1 = 1'];
    $params = [];
    if ($q !== null) {
        $like     = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $where[]  = '(service_id LIKE ? OR service_name LIKE ? OR description LIKE ?)';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if ($status !== null) { $where[] = 'status = ?';     $params[] = $status; }
    if ($only !== null)   { $where[] = 'service_id = ?'; $params[] = $only; }
    $whereSql = implode(' AND ', $where);

    $total = (int) db_val('SELECT COUNT(*) FROM ' . T_SERVICES . ' WHERE ' . $whereSql, $params);
    $rows  = db_all(
        'SELECT * FROM ' . T_SERVICES . ' WHERE ' . $whereSql
        . ' ORDER BY created_at DESC, id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
        $params
    );

    // N+1 방지 — 현재 페이지의 service_id 만 IN 으로 묶어 집계 2회
    $secretCounts = [];
    $userCounts   = [];
    if ($rows !== []) {
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (string) $r['service_id'];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        foreach (db_all('SELECT service_id, COUNT(*) AS c FROM ' . T_SECRETS
            . ' WHERE service_id IN (' . $ph . ') GROUP BY service_id', $ids) as $x) {
            $secretCounts[(string) $x['service_id']] = (int) $x['c'];
        }
        foreach (db_all('SELECT service_id, COUNT(*) AS c FROM ' . T_USER_SERVICES
            . ' WHERE service_id IN (' . $ph . ') GROUP BY service_id', $ids) as $x) {
            $userCounts[(string) $x['service_id']] = (int) $x['c'];
        }
    }

    $items = [];
    foreach ($rows as $r) {
        $sid     = (string) $r['service_id'];
        $items[] = admin_service_public($r, $secretCounts[$sid] ?? 0, $userCounts[$sid] ?? 0);
    }

    json_out(['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
}

require_csrf();

// -----------------------------------------------------------------
// POST — 생성 (계약 §8-12)
// -----------------------------------------------------------------
if ($method === 'POST') {
    $body = body_json();

    $serviceId = (string) v_string($body, 'service_id', 3, 64, true);
    if (preg_match('/^[a-z0-9][a-z0-9\-_]{2,63}$/', $serviceId) !== 1) {
        v_fail('service_id', '서비스 ID 는 영문 소문자·숫자로 시작하고 소문자·숫자·-·_ 3~64자여야 합니다.');
    }
    if (db_val('SELECT id FROM ' . T_SERVICES . ' WHERE service_id = ?', [$serviceId]) !== null) {
        throw new AppError('DUPLICATE_SERVICE_ID', 409, ['field' => 'service_id']);
    }

    $isConfidential = (bool) v_bool($body, 'is_confidential', false, false);
    $secretPlain    = v_string($body, 'service_secret', 8, 255, false);
    if ($isConfidential && $secretPlain === null) {
        v_fail('service_secret', 'confidential 서비스는 service_secret 이 필요합니다.');
    }

    $row = [
        'service_id'            => $serviceId,
        'service_name'          => (string) v_string($body, 'service_name', 1, 100, true),
        'service_secret_hash'   => $secretPlain !== null ? password_hash($secretPlain, PASSWORD_DEFAULT) : null,
        'is_confidential'       => $isConfidential ? 1 : 0,
        'redirect_whitelist'    => json_encode(
            admin_url_list(in_raw($body, 'redirect_whitelist'), 'redirect_whitelist', 'redirect'),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ),
        'allowed_origins'       => json_encode(
            admin_url_list(in_raw($body, 'allowed_origins'), 'allowed_origins', 'origin'),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ),
        'auto_join'             => v_bool($body, 'auto_join', false, true) ? 1 : 0,
        'default_account_level' => v_enum($body, 'default_account_level', array_keys(ACCOUNT_LEVEL_RANK), false),
        'session_ttl'           => (int) v_int($body, 'session_ttl', 60, 604800, false, (int) SESSION_TTL),
        'refresh_ttl'           => (int) v_int($body, 'refresh_ttl', 300, 31536000, false, (int) REFRESH_TTL),
        'payload_fields'        => null,
        'allow_register'        => v_bool($body, 'allow_register', false, true) ? 1 : 0,
        'status'                => (string) v_enum($body, 'status', ['active', 'disabled'], false, 'active'),
        'description'           => v_string($body, 'description', 0, 255, false),
        'created_at'            => $now,
        'updated_at'            => $now,
    ];

    if (array_key_exists('payload_fields', $body) && $body['payload_fields'] !== null) {
        if (!is_array($body['payload_fields'])) {
            v_fail('payload_fields', '문자열 배열 또는 null 이어야 합니다.');
        }
        $pf = [];
        foreach ($body['payload_fields'] as $f) {
            if (!is_string($f) || preg_match('/^[a-z0-9_]{1,50}$/', $f) !== 1) {
                v_fail('payload_fields', '필드명은 영문 소문자·숫자·밑줄 1~50자여야 합니다.');
            }
            $pf[] = $f;
        }
        $row['payload_fields'] = json_encode(array_values(array_unique($pf)),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    try {
        db_insert(T_SERVICES, $row);
    } catch (Throwable $e) {
        if (db_is_duplicate($e)) {
            throw new AppError('DUPLICATE_SERVICE_ID', 409, ['field' => 'service_id']);
        }
        throw $e;
    }

    $logId = admin_log_write($adminId, 'service.create', 'service', $serviceId, null, [
        'service_id'     => $serviceId,
        'service_name'   => $row['service_name'],
        'status'         => $row['status'],
        'is_confidential' => (bool) $isConfidential,
        // 시크릿 평문·해시는 남기지 않는다
        'service_secret' => $secretPlain !== null ? '(set)' : null,
    ], v_string($body, 'reason', 0, 255, false));

    $fresh = admin_service_row($serviceId);
    list($sc, $uc) = admin_service_counts($serviceId);

    json_out([
        'service'              => admin_service_public($fresh, $sc, $uc),
        // 평문은 이 응답에서 1회만. 다시 조회할 수 없다.
        'service_secret_plain' => $secretPlain,
        'admin_log_id'         => $logId,
    ], 201);
}

// -----------------------------------------------------------------
// PUT — 수정 (계약 §8-13)
// -----------------------------------------------------------------
if ($method === 'PUT') {
    $body    = body_json();
    $target  = admin_service_row(v_string($_GET, 'service_id', 1, 64, true));
    $sid     = (string) $target['service_id'];

    // service_id 자체는 변경할 수 없다 (다른 테이블이 문자열 키로 참조한다)
    if (array_key_exists('service_id', $body) && (string) $body['service_id'] !== $sid) {
        v_fail('service_id', '서비스 ID 는 변경할 수 없습니다. 새로 만들고 기존 서비스를 disabled 로 내려 주세요.');
    }

    $set     = [];
    $before  = [];
    $after   = [];
    $changed = [];

    $mark = function (string $field, string $col, $newVal) use (&$set, &$before, &$after, &$changed, $target) {
        $old  = array_key_exists($col, $target) ? $target[$col] : null;
        $same = ($old === null && $newVal === null)
            || ($old !== null && $newVal !== null && (string) $old === (string) $newVal);
        if ($same) {
            return;
        }
        $set[$col]      = $newVal;
        $before[$field] = $old;
        $after[$field]  = $newVal;
        $changed[]      = $field;
    };

    if (array_key_exists('service_name', $body)) {
        $mark('service_name', 'service_name', v_string($body, 'service_name', 1, 100, true));
    }
    if (array_key_exists('description', $body)) {
        $mark('description', 'description', v_string($body, 'description', 0, 255, false));
    }
    if (array_key_exists('redirect_whitelist', $body)) {
        $mark('redirect_whitelist', 'redirect_whitelist', json_encode(
            admin_url_list($body['redirect_whitelist'], 'redirect_whitelist', 'redirect'),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    if (array_key_exists('allowed_origins', $body)) {
        $mark('allowed_origins', 'allowed_origins', json_encode(
            admin_url_list($body['allowed_origins'], 'allowed_origins', 'origin'),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    if (array_key_exists('auto_join', $body)) {
        $mark('auto_join', 'auto_join', v_bool($body, 'auto_join', true) ? 1 : 0);
    }
    if (array_key_exists('allow_register', $body)) {
        $mark('allow_register', 'allow_register', v_bool($body, 'allow_register', true) ? 1 : 0);
    }
    if (array_key_exists('default_account_level', $body)) {
        $mark('default_account_level', 'default_account_level',
            $body['default_account_level'] === null
                ? null : v_enum($body, 'default_account_level', array_keys(ACCOUNT_LEVEL_RANK), true));
    }
    if (array_key_exists('session_ttl', $body)) {
        $mark('session_ttl', 'session_ttl', v_int($body, 'session_ttl', 60, 604800, true));
    }
    if (array_key_exists('refresh_ttl', $body)) {
        $mark('refresh_ttl', 'refresh_ttl', v_int($body, 'refresh_ttl', 300, 31536000, true));
    }
    if (array_key_exists('status', $body)) {
        $mark('status', 'status', v_enum($body, 'status', ['active', 'disabled'], true));
    }
    if (array_key_exists('payload_fields', $body)) {
        if ($body['payload_fields'] === null) {
            $mark('payload_fields', 'payload_fields', null);
        } else {
            if (!is_array($body['payload_fields'])) {
                v_fail('payload_fields', '문자열 배열 또는 null 이어야 합니다.');
            }
            $pf = [];
            foreach ($body['payload_fields'] as $f) {
                if (!is_string($f) || preg_match('/^[a-z0-9_]{1,50}$/', $f) !== 1) {
                    v_fail('payload_fields', '필드명은 영문 소문자·숫자·밑줄 1~50자여야 합니다.');
                }
                $pf[] = $f;
            }
            $mark('payload_fields', 'payload_fields',
                json_encode(array_values(array_unique($pf)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    // --- 시크릿 -------------------------------------------------
    $regenerate  = (bool) v_bool($body, 'regenerate_secret', false, false);
    $secretPlain = null;
    $givenSecret = v_string($body, 'service_secret', 8, 255, false);
    if ($regenerate) {
        $secretPlain = gen_token(24);          // 48자 hex
    } elseif ($givenSecret !== null) {
        $secretPlain = $givenSecret;
    }
    if ($secretPlain !== null) {
        $set['service_secret_hash'] = password_hash($secretPlain, PASSWORD_DEFAULT);
        $changed[]                  = 'service_secret';
        // 평문·해시는 로그에 남기지 않는다 (계약 §8-13: "(changed)" 마커만)
        $before['service_secret']   = '(previous)';
        $after['service_secret']    = '(changed)';
    }
    if (array_key_exists('is_confidential', $body)) {
        $newConf = (bool) v_bool($body, 'is_confidential', true);
        $hasHash = ($target['service_secret_hash'] !== null && $target['service_secret_hash'] !== '')
            || $secretPlain !== null;
        if ($newConf && !$hasHash) {
            v_fail('service_secret', 'confidential 로 전환하려면 service_secret 이 필요합니다.');
        }
        $mark('is_confidential', 'is_confidential', $newConf ? 1 : 0);
    }

    if ($set !== []) {
        $set['updated_at'] = $now;
        db_update(T_SERVICES, $set, 'service_id = ?', [$sid]);
    }

    $logId = null;
    if ($changed !== []) {
        $logId = admin_log_write($adminId, 'service.update', 'service', $sid,
            $before === [] ? null : $before, $after === [] ? null : $after,
            v_string($body, 'reason', 0, 255, false));
    }

    $fresh = admin_service_row($sid);
    list($sc, $uc) = admin_service_counts($sid);

    json_out([
        'service'              => admin_service_public($fresh, $sc, $uc),
        'service_secret_plain' => $secretPlain,
        'changed_fields'       => $changed,
        'admin_log_id'         => $logId,
    ]);
}

// -----------------------------------------------------------------
// DELETE — 삭제 (계약 §8-14)
// -----------------------------------------------------------------
$in     = array_merge(body_json(), $_GET);
$target = admin_service_row(v_string($in, 'service_id', 1, 64, true));
$sid    = (string) $target['service_id'];

list($secretCount, $userCount) = admin_service_counts($sid);

$force   = (bool) v_bool($in, 'force', false, false);
$confirm = v_string($in, 'confirm', 1, 64, false);
$reason  = v_string($in, 'reason', 0, 255, false);

// 가입 사용자가 있으면 기본 거부한다 — 실수 한 번으로 복구 불가능한 손실이 난다
if ($userCount > 0 && !$force) {
    throw new AppError('CONFLICT', 409, [
        'message'      => '가입 사용자가 있는 서비스입니다. force 옵션과 확인 문구가 필요합니다.',
        'user_count'   => $userCount,
        'secret_count' => $secretCount,
    ]);
}
if ($userCount > 0 && ($confirm === null || !hash_equals($sid, (string) $confirm))) {
    throw new AppError('VALIDATION_FAILED', 400, [
        'field'   => 'confirm',
        'message' => '확인 문구가 일치하지 않습니다. 서비스 ID 를 정확히 입력해 주세요.',
    ]);
}

$purged = db_tx(function () use ($sid, $adminId, $reason, $target) {
    $p = [
        'service_secrets' => db_exec('DELETE FROM ' . T_SECRETS . ' WHERE service_id = ?', [$sid]),
        'user_services'   => db_exec('DELETE FROM ' . T_USER_SERVICES . ' WHERE service_id = ?', [$sid]),
        // 계약 §8-14: '유효한' 세션만 제거한다(회수 이력은 남긴다)
        'sessions'        => db_exec('DELETE FROM ' . T_SESSIONS . ' WHERE service_id = ? AND revoked_at IS NULL', [$sid]),
        'auth_codes'      => db_exec('DELETE FROM ' . T_AUTH_CODES . ' WHERE service_id = ?', [$sid]),
    ];
    db_exec('DELETE FROM ' . T_SERVICES . ' WHERE service_id = ?', [$sid]);

    $logId = admin_log_write($adminId, 'service.delete', 'service', $sid,
        ['service_id' => $sid, 'service_name' => (string) $target['service_name'], 'status' => (string) $target['status']],
        ['deleted' => true, 'purged' => $p],
        $reason);

    return ['purged' => $p, 'admin_log_id' => $logId];
});

json_out([
    'deleted'      => true,
    'service_id'   => $sid,
    'purged'       => $purged['purged'],
    'admin_log_id' => $purged['admin_log_id'],
]);
