<?php
declare(strict_types=1);

/**
 * core/docs.php — 문서 테이블(서비스별 컬렉션/문서) (v1.9.0)
 * 담당 계약: 설계 116 §6 · §10-2·3 · H-8
 *
 * 규칙   read: service(s2s만) · owner(s2s + 주인) · auth(s2s + 그 서비스 로그인 사용자) · public(+ 토큰 없는 요청)
 *        write: service(s2s만) · owner(s2s 전부 · user 는 생성=주인 자신 강제 · 자기 문서 수정·삭제)
 * 🔴 남의 owner 문서를 읽거나 고치면 **404**(존재 오라클 제거). user 질의에는 owner_uuid == 나 를 **서버가 덧붙인다.**
 * 🔴 set·merge·delete 는 expected_version **필수** — `UPDATE … WHERE id=? AND version=?` rowCount 0 → 409 CONFLICT.
 * 🔴 생성 상한은 `UPDATE … SET doc_count=doc_count+1 WHERE id=? AND doc_count<max_docs` rowCount 0 → 403 (잠금 없이 원자적).
 * 색인 3슬롯(ix1..ix3, 이진 비교). int 는 '%016d'(n+10^15) 문자열 — 문자열 비교만으로 정렬된다.
 * 커서 = base64url {o, id, h}(h = 질의 해시). 오프셋 없음.
 *
 * PHP 7.2 호환. `FOR UPDATE`·`ON DUPLICATE` 리터럴 없음.
 */

if (!defined('ULOGIN_BOOTSTRAPPED')) {
    header_remove('X-Powered-By');
    http_response_code(404);
    exit;
}

const DOCS_COLLS_MAX     = 20;
const DOCS_MAX_DOCS      = 50000;
const DOCS_MAX_BYTES     = 262144;
const DOCS_INT_LIM       = 1000000000000000;   // 10^15
const DOCS_READ_RULES    = ['service', 'owner', 'auth', 'public'];
const DOCS_WRITE_RULES   = ['service', 'owner'];
const DOCS_NAME_RE       = '/^[a-z][a-z0-9_]{0,63}$/';
const DOCS_FIELD_RE      = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/';
const DOCS_ID_RE         = '/^[A-Za-z0-9_.:-]{1,64}$/';
const DOCS_OPS           = ['==', '<', '<=', '>', '>='];

function docs_fail(string $field, string $msg, int $status = 400, string $code = 'VALIDATION_FAILED'): void
{
    throw new AppError($code, $status, ['field' => $field, 'message' => $msg]);
}

/** 컬렉션 행(상태 무관). */
function docs_coll(string $sid, string $name): ?array
{
    return db_one('SELECT * FROM ' . T_PLAT_COLLS . ' WHERE service_id = ? AND name = ?', [$sid, $name]);
}

/** 활성 컬렉션을 요구한다. 없거나 꺼졌으면 404. */
function docs_coll_require(string $sid, $name): array
{
    if (!is_string($name) || preg_match(DOCS_NAME_RE, $name) !== 1) {
        docs_fail('collection', '컬렉션 이름은 [a-z][a-z0-9_] 1~64자입니다.');
    }
    $c = docs_coll($sid, $name);
    if ($c === null || (string) $c['status'] !== 'active') {
        throw new AppError('NOT_FOUND', 404, ['field' => 'collection', 'message' => '컬렉션이 없습니다.']);
    }
    return $c;
}

function docs_indexes(array $coll): array
{
    $ix = json_col($coll['index_json'] ?? null);
    $out = [];
    foreach ($ix as $i => $d) {
        if (is_array($d) && isset($d['field'], $d['type'])) {
            $out[] = ['field' => (string) $d['field'], 'type' => (string) $d['type']];
        }
    }
    return array_slice($out, 0, 3);
}

/** 컬렉션 응답 모양. */
function docs_coll_out(array $c): array
{
    return [
        'name'            => (string) $c['name'],
        'read_rule'       => (string) $c['read_rule'],
        'write_rule'      => (string) $c['write_rule'],
        'indexes'         => docs_indexes($c),
        'max_docs'        => (int) $c['max_docs'],
        'max_doc_bytes'   => (int) $c['max_doc_bytes'],
        'doc_count'       => (int) $c['doc_count'],
        'reindex_pending' => !empty($c['reindex_pending']),
    ];
}

/** 색인 정의 검증·정규화. */
function docs_norm_indexes($v): array
{
    if ($v === null) {
        return [];
    }
    if (!is_array($v) || ($v !== [] && array_keys($v) !== range(0, count($v) - 1)) || count($v) > 3) {
        docs_fail('indexes', '색인은 3개 이하의 배열입니다.');
    }
    $out = [];
    $seen = [];
    foreach ($v as $d) {
        if (!is_array($d) || !is_string($d['field'] ?? null) || preg_match(DOCS_FIELD_RE, (string) $d['field']) !== 1
            || !in_array($d['type'] ?? null, ['str', 'int'], true)) {
            docs_fail('indexes', '색인은 {field:[A-Za-z_][A-Za-z0-9_]*, type:str|int} 입니다.');
        }
        if ($d['field'] === 'owner_uuid' || isset($seen[$d['field']]) || plat_secret_key((string) $d['field'])) {
            docs_fail('indexes', '쓸 수 없는 색인 필드입니다: ' . (string) $d['field']);
        }
        $seen[$d['field']] = true;
        $out[] = ['field' => (string) $d['field'], 'type' => (string) $d['type']];
    }
    return $out;
}

/**
 * 컬렉션 만들기/바꾸기. 색인이 바뀌면(문서가 있을 때) reindex_pending=1.
 */
function docs_coll_upsert(string $sid, array $in): array
{
    $name = $in['name'] ?? null;
    if (!is_string($name) || preg_match(DOCS_NAME_RE, $name) !== 1) {
        docs_fail('name', '컬렉션 이름은 [a-z][a-z0-9_] 1~64자입니다.');
    }
    $rr = $in['read_rule'] ?? null;
    if (!in_array($rr, DOCS_READ_RULES, true)) {
        docs_fail('read_rule', 'read_rule 은 service|owner|auth|public 입니다.');
    }
    $wr = $in['write_rule'] ?? null;
    if (!in_array($wr, DOCS_WRITE_RULES, true)) {
        docs_fail('write_rule', 'write_rule 은 service|owner 입니다.');
    }
    // v1.9.0 보안 M190-2 (D-361) — 주인이 쓸 수 있는 컬렉션은 주인이 읽을 수도 있어야 한다. read=service · write=owner 면
    // 주인이 merge 응답(data 전문)으로 서비스 전용 필드를 읽고 고친다 — 그 조합을 받지 않는다.
    if ($wr === 'owner' && $rr === 'service') {
        docs_fail('read_rule', 'write_rule=owner 이면 read_rule 은 owner·auth·public 중 하나여야 합니다.');
    }
    $ix = docs_norm_indexes($in['indexes'] ?? null);
    $md = $in['max_docs'] ?? 5000;
    if (!is_int($md) || $md < 1 || $md > DOCS_MAX_DOCS) {
        docs_fail('max_docs', 'max_docs 는 1~' . DOCS_MAX_DOCS . ' 입니다.');
    }
    $mb = $in['max_doc_bytes'] ?? 16384;
    if (!is_int($mb) || $mb < 256 || $mb > DOCS_MAX_BYTES) {
        docs_fail('max_doc_bytes', 'max_doc_bytes 는 256~' . DOCS_MAX_BYTES . ' 입니다.');
    }
    $now = now_utc();
    $ixJson = plat_json($ix);
    $cur = docs_coll($sid, $name);
    if ($cur === null) {
        if ((int) db_val('SELECT COUNT(*) FROM ' . T_PLAT_COLLS . ' WHERE service_id = ?', [$sid]) >= DOCS_COLLS_MAX) {
            throw new AppError('QUOTA_EXCEEDED', 403, ['field' => 'name', 'message' => '서비스당 컬렉션은 20개까지입니다.']);
        }
        try {
            db_insert(T_PLAT_COLLS, [
                'service_id' => $sid, 'name' => $name, 'read_rule' => $rr, 'write_rule' => $wr, 'index_json' => $ixJson,
                'max_docs' => $md, 'max_doc_bytes' => $mb, 'doc_count' => 0, 'reindex_pending' => 0, 'status' => 'active',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        } catch (Throwable $e) {
            if (!db_is_duplicate($e)) {
                throw $e;
            }
        }
    } else {
        $changed = plat_json(docs_indexes($cur)) !== $ixJson;
        db_update(T_PLAT_COLLS, [
            'read_rule' => $rr, 'write_rule' => $wr, 'index_json' => $ixJson, 'max_docs' => $md, 'max_doc_bytes' => $mb,
            'reindex_pending' => ($changed && (int) $cur['doc_count'] > 0) ? 1 : (int) $cur['reindex_pending'],
            'status' => 'active', 'updated_at' => $now,
        ], 'id = ?', [(int) $cur['id']]);
    }
    return (array) docs_coll($sid, $name);
}

/** 데이터 객체 검증 — 객체 · 크기 · 비밀 키 이름(A7). 반환: 직렬화 문자열. */
function docs_check_data($data, array $coll): string
{
    if (!is_array($data) || ($data !== [] && array_keys($data) === range(0, count($data) - 1))) {
        docs_fail('data', 'data 는 객체여야 합니다.');
    }
    if (plat_depth_over($data)) {   // DEPTH-CAP
        docs_fail('data', 'data 의 중첩이 너무 깊습니다(16단 이하).');
    }
    if (rc_like_has_secret($data)) {
        docs_fail('data', '비밀값으로 보이는 키(password·token·key …)는 저장할 수 없습니다.');
    }
    $j = json_encode($data === [] ? new stdClass() : $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($j)) {
        docs_fail('data', 'data 를 JSON 으로 만들 수 없습니다.');
    }
    if (strlen($j) > (int) $coll['max_doc_bytes']) {
        docs_fail('data', '문서가 너무 큽니다(최대 ' . (int) $coll['max_doc_bytes'] . ' 바이트).');
    }
    return $j;
}

function rc_like_has_secret($v, int $d = 0): bool
{
    if (!is_array($v) || $d > 16) {
        return false;
    }
    foreach ($v as $k => $x) {
        if ((is_string($k) && plat_secret_key($k)) || rc_like_has_secret($x, $d + 1)) {
            return true;
        }
    }
    return false;
}

/** 색인 값 하나 → 슬롯 문자열. null/없음 → null. 형이 틀리면 400. */
function docs_slot_value($v, string $type, string $field): ?string
{
    if ($v === null) {
        return null;
    }
    if ($type === 'int') {
        if (!is_int($v) || $v < -DOCS_INT_LIM || $v > DOCS_INT_LIM) {
            docs_fail('data', '색인 필드 ' . $field . ' 는 [-10^15, 10^15] 정수여야 합니다.');
        }
        return sprintf('%016d', $v + DOCS_INT_LIM);
    }
    if (!is_string($v) || mb_strlen($v, 'UTF-8') > 191) {
        docs_fail('data', '색인 필드 ' . $field . ' 는 191자 이하 문자열이어야 합니다.');
    }
    return $v;
}

function docs_slots(array $coll, array $data): array
{
    $out = ['ix1' => null, 'ix2' => null, 'ix3' => null];
    foreach (docs_indexes($coll) as $i => $ix) {
        $out['ix' . ($i + 1)] = docs_slot_value($data[$ix['field']] ?? null, $ix['type'], $ix['field']);
    }
    return $out;
}

function docs_out(array $d): array
{
    $data = json_decode((string) $d['data_json'], true);
    return [
        'doc_id'     => (string) $d['doc_id'],
        'version'    => (int) $d['version'],
        'owner_uuid' => $d['owner_uuid'] === null ? null : (string) $d['owner_uuid'],
        'data'       => json_object(is_array($data) ? $data : []),
        'created_at' => iso($d['created_at']),
        'updated_at' => iso($d['updated_at']),
    ];
}

/** 읽기 권한. 거절 시 예외. owner 규칙의 user 는 호출자가 주인 필터를 붙인다. */
function docs_can_read(array $c, array $coll): void
{
    $rule = (string) $coll['read_rule'];
    if ($c['kind'] === 'service') {
        return;
    }
    if ($c['kind'] === 'anon') {
        if ($rule === 'public') {
            return;
        }
        throw new AppError('AUTH_REQUIRED', 401);
    }
    if ($rule === 'service') {
        throw new AppError('FORBIDDEN', 403, ['message' => '서비스 서버만 읽을 수 있는 컬렉션입니다.']);
    }
}

/** user 가 이 문서를 볼 수 있는가(owner 규칙이면 주인만). 안 되면 404. */
function docs_visible(array $c, array $coll, array $doc): void
{
    if ($c['kind'] === 'user' && (string) $coll['read_rule'] === 'owner'
        && (string) ($doc['owner_uuid'] ?? '') !== (string) $c['user']['uuid']) {
        throw new AppError('NOT_FOUND', 404, ['field' => 'doc_id']);
    }
}

/** 쓰기 권한(생성 외). user 는 write_rule=owner + 자기 문서만. 남의 문서는 404. */
function docs_can_write(array $c, array $coll, ?array $doc): void
{
    if ($c['kind'] === 'service') {
        return;
    }
    if ($c['kind'] !== 'user' || (string) $coll['write_rule'] !== 'owner') {
        throw new AppError('FORBIDDEN', 403, ['message' => '이 컬렉션에 쓸 권한이 없습니다.']);
    }
    if ($doc !== null && (string) ($doc['owner_uuid'] ?? '') !== (string) $c['user']['uuid']) {
        throw new AppError('NOT_FOUND', 404, ['field' => 'doc_id']);
    }
}

function docs_find(string $sid, string $coll, $docId): array
{
    if (!is_string($docId) || preg_match(DOCS_ID_RE, $docId) !== 1) {
        docs_fail('doc_id', 'doc_id 는 [A-Za-z0-9_.:-] 1~64자입니다.');
    }
    $d = db_one('SELECT * FROM ' . T_PLAT_DOCS . ' WHERE service_id = ? AND collection = ? AND doc_id = ?', [$sid, $coll, $docId]);
    if ($d === null) {
        throw new AppError('NOT_FOUND', 404, ['field' => 'doc_id']);
    }
    return $d;
}

function docs_expected($in): int
{
    $v = $in['expected_version'] ?? null;
    if (!is_int($v) || $v < 1) {
        docs_fail('expected_version', 'expected_version(정수)이 필요합니다.');
    }
    return $v;
}

// =====================================================================
// 조작
// =====================================================================

function docs_op_get(array $c, array $coll, array $in): array
{
    docs_can_read($c, $coll);
    $d = docs_find((string) $coll['service_id'], (string) $coll['name'], $in['doc_id'] ?? null);
    docs_visible($c, $coll, $d);
    plat_usage_add((string) $coll['service_id'], plat_metric_for($c['kind'], 'doc_reads'), 1, true);
    return ['doc' => docs_out($d)];
}

function docs_op_create(array $c, array $coll, array $in): array
{
    if ($c['kind'] === 'anon') {
        throw new AppError('AUTH_REQUIRED', 401);
    }
    if ($c['kind'] === 'user' && (string) $coll['write_rule'] !== 'owner') {
        throw new AppError('FORBIDDEN', 403, ['message' => '이 컬렉션에 쓸 권한이 없습니다.']);
    }
    $sid = (string) $coll['service_id'];
    $docId = $in['doc_id'] ?? null;
    if ($docId === null || $docId === '') {
        $docId = gen_uuid4();
    } elseif (!is_string($docId) || preg_match(DOCS_ID_RE, $docId) !== 1) {
        docs_fail('doc_id', 'doc_id 는 [A-Za-z0-9_.:-] 1~64자입니다.');
    }
    // v1.9.0 보안 M190-1 (D-361) — user 는 doc_id 를 서버에 맡기거나(null) **자기 uuid** 만 쓸 수 있다.
    // 「doc_id = 사용자 uuid」 설계에서 남의 uuid 로 먼저 만들어 그 사람의 프로필·설정 문서를 가로채는 것을 막는다.
    if ($c['kind'] === 'user' && isset($in['doc_id']) && $in['doc_id'] !== '' && (string) $docId !== (string) $c['user']['uuid']) {
        throw new AppError('FORBIDDEN', 403, ['field' => 'doc_id',
            'message' => '사용자는 doc_id 를 비우거나(서버가 정함) 자기 uuid 로만 만들 수 있습니다.']);
    }
    if ($c['kind'] === 'user') {
        $owner = (string) $c['user']['uuid'];   // 주인 = 자신 강제(입력 owner_uuid 무시)
    } else {
        $owner = $in['owner_uuid'] ?? null;
        if ($owner !== null && (!is_string($owner) || !al_is_uuid($owner))) {
            docs_fail('owner_uuid', 'owner_uuid 형식이 올바르지 않습니다.');
        }
        $owner = $owner === null ? null : strtolower($owner);
    }
    $data = $in['data'] ?? [];
    $json = docs_check_data($data, $coll);
    $slots = docs_slots($coll, is_array($data) ? $data : []);
    $now = now_utc();
    $by = $c['kind'] === 'user' ? 'user:' . (string) $c['user']['uuid'] : 'service';
    $row = array_merge([
        'service_id' => $sid, 'collection' => (string) $coll['name'], 'doc_id' => (string) $docId, 'owner_uuid' => $owner,
        'data_json' => $json, 'version' => 1, 'created_by' => $by, 'created_at' => $now, 'updated_at' => $now,
    ], $slots);
    $wm = plat_metric_for($c['kind'], 'doc_writes');
    try {
        db_tx(function () use ($coll, $row, $sid, $wm) {
            $n = db_exec('UPDATE ' . T_PLAT_COLLS . ' SET doc_count = doc_count + 1 WHERE id = ? AND doc_count < max_docs',
                [(int) $coll['id']]);
            if ($n === 0) {
                throw new AppError('QUOTA_EXCEEDED', 403, ['field' => 'collection', 'message' => '컬렉션 문서 수 한도를 넘었습니다.']);
            }
            db_insert(T_PLAT_DOCS, $row);
            plat_usage_add($sid, $wm, 1);
        });
    } catch (Throwable $e) {
        if (db_is_duplicate($e)) {
            throw new AppError('CONFLICT', 409, ['field' => 'doc_id', 'message' => '같은 doc_id 가 이미 있습니다.']);
        }
        throw $e;
    }
    $d = docs_find($sid, (string) $coll['name'], (string) $docId);
    return ['doc' => docs_out($d)];
}

/** set(교체) · merge(최상위 키 병합, null=키 삭제). */
function docs_op_update(array $c, array $coll, array $in, bool $merge): array
{
    $sid = (string) $coll['service_id'];
    $d = docs_find($sid, (string) $coll['name'], $in['doc_id'] ?? null);
    if ($c['kind'] === 'user' && (string) $coll['read_rule'] === 'owner') {
        docs_visible($c, $coll, $d);
    }
    docs_can_write($c, $coll, $d);
    $exp = docs_expected($in);
    $data = $in['data'] ?? null;
    if (!is_array($data)) {
        docs_fail('data', 'data 는 객체여야 합니다.');
    }
    if ($merge) {
        $cur = json_decode((string) $d['data_json'], true);
        $cur = is_array($cur) ? $cur : [];
        foreach ($data as $k => $v) {
            if ($v === null) {
                unset($cur[$k]);
            } else {
                $cur[$k] = $v;
            }
        }
        $data = $cur;
    }
    $json = docs_check_data($data, $coll);
    $slots = docs_slots($coll, $data);
    $wm = plat_metric_for($c['kind'], 'doc_writes');
    $n = (int) db_tx(function () use ($d, $exp, $json, $slots, $sid, $wm) {
        $n = db_exec('UPDATE ' . T_PLAT_DOCS . ' SET data_json = ?, version = version + 1, ix1 = ?, ix2 = ?, ix3 = ?, updated_at = ?'
            . ' WHERE id = ? AND version = ?', [$json, $slots['ix1'], $slots['ix2'], $slots['ix3'], now_utc(), (int) $d['id'], $exp]);
        if ($n > 0) {
            plat_usage_add($sid, $wm, 1);
        }
        return $n;
    });
    if ($n === 0) {
        throw new AppError('CONFLICT', 409, ['field' => 'expected_version', 'message' => '문서가 먼저 바뀌었습니다. 다시 읽고 고치세요.']);
    }
    return ['doc' => docs_out(docs_find($sid, (string) $coll['name'], (string) $d['doc_id']))];
}

function docs_op_delete(array $c, array $coll, array $in): array
{
    $sid = (string) $coll['service_id'];
    $d = docs_find($sid, (string) $coll['name'], $in['doc_id'] ?? null);
    if ($c['kind'] === 'user' && (string) $coll['read_rule'] === 'owner') {
        docs_visible($c, $coll, $d);
    }
    docs_can_write($c, $coll, $d);
    $exp = docs_expected($in);
    $wm = plat_metric_for($c['kind'], 'doc_writes');
    $n = (int) db_tx(function () use ($d, $exp, $coll, $sid, $wm) {
        $n = db_exec('DELETE FROM ' . T_PLAT_DOCS . ' WHERE id = ? AND version = ?', [(int) $d['id'], $exp]);
        if ($n > 0) {
            db_exec('UPDATE ' . T_PLAT_COLLS . ' SET doc_count = doc_count - 1 WHERE id = ? AND doc_count > 0', [(int) $coll['id']]);
            plat_usage_add($sid, $wm, 1);
        }
        return $n;
    });
    if ($n === 0) {
        throw new AppError('CONFLICT', 409, ['field' => 'expected_version', 'message' => '문서가 먼저 바뀌었습니다. 다시 읽고 고치세요.']);
    }
    return ['deleted' => true];
}

/** 질의 값 → 슬롯 표현. */
function docs_where_value($v, string $type): string
{
    if ($type === 'int') {
        if (!is_int($v) || $v < -DOCS_INT_LIM || $v > DOCS_INT_LIM) {
            docs_fail('where', 'int 색인에는 [-10^15, 10^15] 정수로만 질의합니다.');
        }
        return sprintf('%016d', $v + DOCS_INT_LIM);
    }
    if (!is_string($v) || mb_strlen($v, 'UTF-8') > 191) {
        docs_fail('where', 'str 색인에는 191자 이하 문자열로만 질의합니다.');
    }
    return $v;
}

function docs_op_query(array $c, array $coll, array $in): array
{
    docs_can_read($c, $coll);
    $sid = (string) $coll['service_id'];
    $slots = [];
    foreach (docs_indexes($coll) as $i => $ix) {
        $slots[$ix['field']] = ['col' => 'ix' . ($i + 1), 'type' => $ix['type']];
    }
    $where = $in['where'] ?? [];
    if (!is_array($where) || ($where !== [] && array_keys($where) !== range(0, count($where) - 1)) || count($where) > 3) {
        docs_fail('where', 'where 는 3개 이하의 배열입니다.');
    }
    $sql = ['service_id = ?', 'collection = ?'];
    $p = [$sid, (string) $coll['name']];
    $norm = [];
    $rangeField = null;
    foreach ($where as $w) {
        if (!is_array($w) || !is_string($w['field'] ?? null) || !in_array($w['op'] ?? null, DOCS_OPS, true) || !array_key_exists('value', $w)) {
            docs_fail('where', '조건은 {field, op(== < <= > >=), value} 입니다.');
        }
        $f = (string) $w['field'];
        $op = (string) $w['op'];
        if ($f === 'owner_uuid') {
            if ($op !== '==' || !is_string($w['value']) || !al_is_uuid($w['value'])) {
                docs_fail('where', 'owner_uuid 는 == uuid 로만 질의합니다.');
            }
            $sql[] = 'owner_uuid = ?';
            $p[] = strtolower((string) $w['value']);
            $norm[] = [$f, $op, strtolower((string) $w['value'])];
            continue;
        }
        if (!isset($slots[$f])) {
            docs_fail('where', '색인되지 않은 필드로는 조회할 수 없습니다.');
        }
        if (!empty($coll['reindex_pending'])) {
            throw new AppError('CONFLICT', 409, ['field' => 'where', 'message' => '색인을 다시 만드는 중입니다. 잠시 뒤 다시 조회하세요.']);
        }
        if ($op !== '==') {
            if ($rangeField !== null && $rangeField !== $f) {
                docs_fail('where', '범위 조건은 한 필드에만 쓸 수 있습니다.');
            }
            $rangeField = $f;
        }
        $val = docs_where_value($w['value'], $slots[$f]['type']);
        $sql[] = $slots[$f]['col'] . ' ' . ($op === '==' ? '=' : $op) . ' ?';
        $p[] = $val;
        $norm[] = [$f, $op, $val];
    }
    // owner 규칙의 user — 서버가 owner_uuid == 나 를 **강제로** 덧붙인다
    $forced = null;
    if ($c['kind'] === 'user' && (string) $coll['read_rule'] === 'owner') {
        $forced = (string) $c['user']['uuid'];
        $sql[] = 'owner_uuid = ?';
        $p[] = $forced;
    }
    // 정렬
    $order = $in['order'] ?? null;
    $ofield = null;
    $dir = 'asc';
    if ($order !== null) {
        if (!is_array($order) || !is_string($order['field'] ?? null)) {
            docs_fail('order', 'order 는 {field, dir} 입니다.');
        }
        $ofield = (string) $order['field'];
        $dir = isset($order['dir']) ? (string) $order['dir'] : 'asc';
        if (!in_array($dir, ['asc', 'desc'], true)) {
            docs_fail('order', 'dir 은 asc|desc 입니다.');
        }
        if (!isset($slots[$ofield])) {
            docs_fail('order', '색인 필드로만 정렬할 수 있습니다.');
        }
        if (!empty($coll['reindex_pending'])) {
            throw new AppError('CONFLICT', 409, ['field' => 'where', 'message' => '색인을 다시 만드는 중입니다. 잠시 뒤 다시 조회하세요.']);
        }
    }
    if ($rangeField !== null && $ofield === null) {
        $ofield = $rangeField;
    }
    if ($rangeField !== null && $ofield !== $rangeField) {
        docs_fail('order', '범위 조건이 있으면 그 필드로 정렬해야 합니다.');
    }
    $limit = $in['limit'] ?? 20;
    if (!is_int($limit) || $limit < 1 || $limit > 100) {
        docs_fail('limit', 'limit 은 1~100 입니다.');
    }
    $h = substr(hash('sha256', (string) plat_json([(string) $coll['name'], $norm, $ofield, $dir, $forced])), 0, 16);
    $ocol = $ofield === null ? null : $slots[$ofield]['col'];
    $cur = $in['cursor'] ?? null;
    if ($cur !== null && $cur !== '') {
        $raw = is_string($cur) ? base64_decode(strtr($cur, '-_', '+/'), true) : false;
        $cj = $raw === false ? null : json_decode((string) $raw, true);
        if (!is_array($cj) || !isset($cj['id'], $cj['h']) || !is_int($cj['id']) || !is_string($cj['h']) || !hash_equals($h, $cj['h'])
            || !array_key_exists('o', $cj) || ($cj['o'] !== null && !is_string($cj['o']))) {
            docs_fail('cursor', '커서가 이 질의의 것이 아닙니다.');
        }
        $cid = (int) $cj['id'];
        if ($ocol === null) {
            $sql[] = $dir === 'asc' ? 'id > ?' : 'id < ?';
            $p[] = $cid;
        } elseif ($cj['o'] === null) {
            if ($dir === 'asc') {
                $sql[] = '((' . $ocol . ' IS NULL AND id > ?) OR ' . $ocol . ' IS NOT NULL)';
            } else {
                $sql[] = '(' . $ocol . ' IS NULL AND id < ?)';
            }
            $p[] = $cid;
        } else {
            if ($dir === 'asc') {
                $sql[] = '(' . $ocol . ' > ? OR (' . $ocol . ' = ? AND id > ?))';
            } else {
                $sql[] = '(' . $ocol . ' < ? OR (' . $ocol . ' = ? AND id < ?) OR ' . $ocol . ' IS NULL)';
            }
            array_push($p, $cj['o'], $cj['o'], $cid);
        }
    }
    $d = $dir === 'asc' ? 'ASC' : 'DESC';
    $orderSql = $ocol === null ? 'id ' . $d : $ocol . ' ' . $d . ', id ' . $d;
    $rows = db_all('SELECT * FROM ' . T_PLAT_DOCS . ' WHERE ' . implode(' AND ', $sql) . ' ORDER BY ' . $orderSql
        . ' LIMIT ' . ($limit + 1), $p);
    $more = count($rows) > $limit;
    if ($more) {
        $rows = array_slice($rows, 0, $limit);
    }
    $items = array_map('docs_out', $rows);
    $next = null;
    if ($more && $rows !== []) {
        $last = $rows[count($rows) - 1];
        $next = rtrim(strtr(base64_encode((string) plat_json(['o' => $ocol === null ? null : $last[$ocol],
            'id' => (int) $last['id'], 'h' => $h])), '+/', '-_'), '=');
    }
    plat_usage_add($sid, plat_metric_for($c['kind'], 'doc_reads'), max(1, count($items)), true);
    return ['items' => $items, 'next_cursor' => $next];
}

/** 관리자 재색인 — 500건씩. 반환 {processed, done, next_after}. */
function docs_reindex(array $coll, int $after): array
{
    $rows = db_all('SELECT id, data_json FROM ' . T_PLAT_DOCS . ' WHERE service_id = ? AND collection = ? AND id > ? ORDER BY id ASC LIMIT 500',
        [(string) $coll['service_id'], (string) $coll['name'], $after]);
    $last = $after;
    db_tx(function () use ($rows, $coll, &$last) {
        foreach ($rows as $r) {
            $data = json_decode((string) $r['data_json'], true);
            $slots = ['ix1' => null, 'ix2' => null, 'ix3' => null];
            try {
                $slots = docs_slots($coll, is_array($data) ? $data : []);
            } catch (AppError $e) {
                // 새 색인 형에 맞지 않는 옛 문서 — 그 슬롯은 비운다(질의에 안 걸린다)
            }
            db_exec('UPDATE ' . T_PLAT_DOCS . ' SET ix1 = ?, ix2 = ?, ix3 = ? WHERE id = ?',
                [$slots['ix1'], $slots['ix2'], $slots['ix3'], (int) $r['id']]);
            $last = (int) $r['id'];
        }
    });
    $done = count($rows) < 500;
    if ($done) {
        db_exec('UPDATE ' . T_PLAT_COLLS . ' SET reindex_pending = 0, updated_at = ? WHERE id = ?', [now_utc(), (int) $coll['id']]);
    }
    return ['processed' => count($rows), 'done' => $done, 'next_after' => $done ? null : $last];
}
