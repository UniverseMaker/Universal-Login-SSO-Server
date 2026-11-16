<?php
declare(strict_types=1);

/**
 * core/rc.php — 전역 설정(Remote Config) (v1.9.0)
 * 담당 계약: 설계 116 §4 · H-5(게시 superadmin)
 *
 *   범위(scope) = '*'(전역) | service_id. 초안(rc_drafts) → 게시(rc_versions, version+1) → 클라이언트.
 *   **초안은 클라이언트에 절대 나가지 않는다.** 병합: 전역 최신 게시본 → 서비스 최신 게시본이 같은 키를 덮는다.
 *   조건 = [{"when":{"role_min":"admin"},"value":…},{"when":{"feature":"export"},"value":…}] 위에서부터 첫 일치.
 *   role_min 은 **전역 role**(D-50), feature 는 §7 유효 기능. 조건은 표시 분기용이지 권한 게이트가 아니다
 *   (비밀값 금지가 전제 — 키 이름 가드 A7).
 *   되돌리기 = 옛 스냅숏을 **새 버전**으로 게시 + 초안도 그 내용으로 교체(이력 보존).
 *
 * PHP 7.2 호환. 이 파일에 `FOR UPDATE`·`ON DUPLICATE` 리터럴 없음 — 경합은 UNIQUE(scope,version) 가 가른다.
 */

if (!defined('ULOGIN_BOOTSTRAPPED')) {
    header_remove('X-Powered-By');
    http_response_code(404);
    exit;
}

const RC_KEY_RE        = '/^[a-z][a-z0-9_.]{0,63}$/';
const RC_VALUE_MAX     = 16384;      // 값 = 임의 JSON ≤ 16 KiB
const RC_KEYS_MAX      = 200;        // 범위당 ≤ 200키
const RC_SNAPSHOT_MAX  = 524288;     // 스냅숏 ≤ 512 KiB
const RC_COND_MAX      = 10;

/** 범위 검증 — '*' 또는 등록된 서비스(상태 무관). 반환: 정식 범위 문자열. */
function rc_scope_check($scope): string
{
    if ($scope === '*') {
        return '*';
    }
    if (!is_string($scope) || $scope === '' || strlen($scope) > 64) {
        throw new AppError('VALIDATION_FAILED', 400, ['field' => 'scope', 'message' => 'scope 는 * 또는 서비스 id 입니다.']);
    }
    if (db_val('SELECT service_id FROM ' . T_SERVICES . ' WHERE service_id = ?', [$scope]) === null) {
        throw new AppError('NOT_FOUND', 404, ['field' => 'scope', 'message' => '그런 서비스가 없습니다.']);
    }
    return $scope;
}

/** 키 검증 — 형식 + 비밀 키 이름 가드(A7). */
function rc_key_check($key): string
{
    if (!is_string($key) || preg_match(RC_KEY_RE, $key) !== 1) {
        throw new AppError('VALIDATION_FAILED', 400, ['field' => 'key', 'message' => '키는 [a-z][a-z0-9_.] 1~64자입니다.']);
    }
    if (plat_secret_key($key)) {
        throw new AppError('VALIDATION_FAILED', 400, ['field' => 'key',
            'message' => '비밀값으로 보이는 키 이름은 쓸 수 없습니다. 설정은 그 서비스 사용자 전원이 읽습니다.']);
    }
    return $key;
}

/** 값 안의 객체 키도 비밀 키 이름이면 거절(A7). */
function rc_value_check($v, string $field): void
{
    $j = plat_json($v);
    if ($j === null || strlen($j) > RC_VALUE_MAX) {
        throw new AppError('VALIDATION_FAILED', 400, ['field' => $field, 'message' => '값은 16KiB 이하의 JSON 이어야 합니다.']);
    }
    if (plat_depth_over($v)) {   // DEPTH-CAP
        throw new AppError('VALIDATION_FAILED', 400, ['field' => $field, 'message' => '값의 중첩이 너무 깊습니다(16단 이하).']);
    }
    $hit = false;
    $chk = plat_redact($v, $hit);
    unset($chk);
    if ($hit && rc_has_secret_key($v)) {
        throw new AppError('VALIDATION_FAILED', 400, ['field' => $field, 'message' => '비밀값으로 보이는 키를 값에 넣을 수 없습니다.']);
    }
}

function rc_has_secret_key($v, int $d = 0): bool
{
    if (!is_array($v) || $d > 16) {
        return false;
    }
    foreach ($v as $k => $x) {
        if ((is_string($k) && plat_secret_key($k)) || rc_has_secret_key($x, $d + 1)) {
            return true;
        }
    }
    return false;
}

/** 조건 배열 검증·정규화. */
function rc_conditions_check($c): array
{
    if ($c === null) {
        return [];
    }
    if (!is_array($c) || ($c !== [] && array_keys($c) !== range(0, count($c) - 1)) || count($c) > RC_COND_MAX) {
        throw new AppError('VALIDATION_FAILED', 400, ['field' => 'conditions', 'message' => '조건은 10개 이하의 배열입니다.']);
    }
    $out = [];
    foreach ($c as $x) {
        if (!is_array($x) || !isset($x['when']) || !is_array($x['when']) || !array_key_exists('value', $x) || count($x['when']) !== 1) {
            throw new AppError('VALIDATION_FAILED', 400, ['field' => 'conditions', 'message' => '조건은 {when:{…},value} 모양입니다.']);
        }
        $w = $x['when'];
        if (isset($w['role_min'])) {
            if (!is_string($w['role_min']) || !isset(ROLE_RANK[$w['role_min']])) {
                throw new AppError('VALIDATION_FAILED', 400, ['field' => 'conditions', 'message' => 'role_min 값이 올바르지 않습니다.']);
            }
            $when = ['role_min' => $w['role_min']];
        } elseif (isset($w['feature'])) {
            if (!is_string($w['feature']) || preg_match('/^[a-z0-9_.]{1,64}$/', $w['feature']) !== 1) {
                throw new AppError('VALIDATION_FAILED', 400, ['field' => 'conditions', 'message' => 'feature 값이 올바르지 않습니다.']);
            }
            $when = ['feature' => $w['feature']];
        } else {
            throw new AppError('VALIDATION_FAILED', 400, ['field' => 'conditions', 'message' => '조건은 role_min 또는 feature 입니다.']);
        }
        rc_value_check($x['value'], 'conditions');
        $out[] = ['when' => $when, 'value' => $x['value']];
    }
    return $out;
}

/** 초안 목록(키 순). */
function rc_drafts(string $scope): array
{
    return db_all('SELECT pkey, value_json, cond_json, description, updated_at FROM ' . T_RC_DRAFTS
        . ' WHERE scope = ? ORDER BY pkey ASC', [$scope]);
}

/** 초안 → 스냅숏 배열 {key:{value,conditions}} (키 정렬). */
function rc_snapshot_from_drafts(string $scope): array
{
    $snap = [];
    foreach (rc_drafts($scope) as $d) {
        $snap[(string) $d['pkey']] = [
            'value'      => json_decode((string) $d['value_json'], true),
            'conditions' => $d['cond_json'] === null ? [] : json_col((string) $d['cond_json']),
        ];
    }
    ksort($snap);
    return $snap;
}

/** 최신 게시본 행. 없으면 null. */
function rc_latest(string $scope): ?array
{
    return db_one('SELECT * FROM ' . T_RC_VERSIONS . ' WHERE scope = ? ORDER BY version DESC LIMIT 1', [$scope]);
}

/** 게시본 스냅숏(배열). 없으면 []. */
function rc_snapshot_of(?array $ver): array
{
    if ($ver === null) {
        return [];
    }
    $s = json_decode((string) $ver['snapshot'], true);
    return is_array($s) ? $s : [];
}

/**
 * 게시. expected_version = 지금 최신 버전(없으면 0). 다르면 409 CONFLICT field=expected_version.
 * @return array {version, etag, published_at}
 */
function rc_publish(string $scope, int $expected, ?string $note, int $adminId, string $source = 'publish', ?int $fromVersion = null,
                    ?array $snapOverride = null): array
{
    $snap = $snapOverride !== null ? $snapOverride : rc_snapshot_from_drafts($scope);
    if (count($snap) > RC_KEYS_MAX) {
        throw new AppError('VALIDATION_FAILED', 400, ['field' => 'scope', 'message' => '범위당 키는 200개까지입니다.']);
    }
    $json = json_encode($snap === [] ? new stdClass() : $snap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || strlen($json) > RC_SNAPSHOT_MAX) {
        throw new AppError('VALIDATION_FAILED', 400, ['field' => 'scope', 'message' => '스냅숏이 512KiB 를 넘습니다.']);
    }
    $etag = hash('sha256', $json);
    $now  = now_utc();
    try {
        $ver = (int) db_tx(function () use ($scope, $expected, $json, $etag, $source, $fromVersion, $note, $adminId, $now) {
            $cur = (int) db_val('SELECT MAX(version) FROM ' . T_RC_VERSIONS . ' WHERE scope = ?', [$scope]);
            if ($expected >= 0 && $cur !== $expected) {
                throw new AppError('CONFLICT', 409, ['field' => 'expected_version',
                    'message' => '다른 관리자가 먼저 게시했습니다. 새로고침하세요.']);
            }
            db_insert(T_RC_VERSIONS, [
                'scope' => $scope, 'version' => $cur + 1, 'snapshot' => $json, 'etag' => $etag, 'source' => $source,
                'from_version' => $fromVersion, 'note' => $note === null ? null : mb_substr($note, 0, 255, 'UTF-8'),
                'published_by' => $adminId, 'published_at' => $now,
            ]);
            return $cur + 1;
        });
    } catch (Throwable $e) {
        if (db_is_duplicate($e)) {
            throw new AppError('CONFLICT', 409, ['field' => 'expected_version',
                'message' => '다른 관리자가 먼저 게시했습니다. 새로고침하세요.']);
        }
        throw $e;
    }
    return ['version' => $ver, 'etag' => $etag, 'published_at' => iso($now)];
}

/** 되돌리기 — 옛 스냅숏을 새 버전으로 + 초안 교체(설명은 같은 키면 유지). */
function rc_rollback(string $scope, int $toVersion, ?string $note, int $adminId): array
{
    $old = db_one('SELECT * FROM ' . T_RC_VERSIONS . ' WHERE scope = ? AND version = ?', [$scope, $toVersion]);
    if ($old === null) {
        throw new AppError('NOT_FOUND', 404, ['field' => 'to_version', 'message' => '그 버전이 없습니다.']);
    }
    $snap = rc_snapshot_of($old);
    $pub  = rc_publish($scope, -1, $note, $adminId, 'rollback', $toVersion, $snap);
    $descs = [];
    foreach (rc_drafts($scope) as $d) {
        $descs[(string) $d['pkey']] = $d['description'];
    }
    db_tx(function () use ($scope, $snap, $descs, $adminId) {
        db_exec('DELETE FROM ' . T_RC_DRAFTS . ' WHERE scope = ?', [$scope]);
        foreach ($snap as $k => $p) {
            db_insert(T_RC_DRAFTS, [
                'scope' => $scope, 'pkey' => (string) $k,
                'value_json' => (string) plat_json($p['value'] ?? null),
                'cond_json' => empty($p['conditions']) ? null : plat_json($p['conditions']),
                'description' => $descs[(string) $k] ?? null, 'updated_by' => $adminId, 'updated_at' => now_utc(),
            ]);
        }
    });
    return $pub;
}

/** 초안이 최신 게시본과 다른가. */
function rc_dirty(string $scope): bool
{
    $a = rc_snapshot_from_drafts($scope);
    $b = rc_snapshot_of(rc_latest($scope));
    ksort($b);
    return plat_json($a) !== plat_json($b);
}

/** 두 스냅숏 차이 — {added[], removed[], changed[{key,before,after}]} */
function rc_diff(array $from, array $to): array
{
    $added = [];
    $removed = [];
    $changed = [];
    foreach ($to as $k => $v) {
        if (!array_key_exists($k, $from)) {
            $added[] = (string) $k;
        } elseif (plat_json($from[$k]) !== plat_json($v)) {
            $changed[] = ['key' => (string) $k, 'before' => $from[$k], 'after' => $v];
        }
    }
    foreach ($from as $k => $v) {
        if (!array_key_exists($k, $to)) {
            $removed[] = (string) $k;
        }
    }
    sort($added);
    sort($removed);
    return ['added' => $added, 'removed' => $removed, 'changed' => $changed];
}

/** 전역 → 서비스 병합한 params {key:{value,conditions}}. */
function rc_merged(string $serviceId, ?array &$versions = null): array
{
    $g = rc_latest('*');
    $s = rc_latest($serviceId);
    $versions = ['global' => $g === null ? 0 : (int) $g['version'], 'service' => $s === null ? 0 : (int) $s['version']];
    $p = rc_snapshot_of($g);
    foreach (rc_snapshot_of($s) as $k => $v) {
        $p[$k] = $v;
    }
    ksort($p);
    return $p;
}

/** 한 사용자 기준 평가 — 조건 위에서부터 첫 일치. role_min=전역 role, feature=유효 기능. */
function rc_evaluate(array $params, string $globalRole, array $features): array
{
    $out = [];
    foreach ($params as $k => $p) {
        $val = $p['value'] ?? null;
        foreach ((array) ($p['conditions'] ?? []) as $c) {
            $w = $c['when'] ?? [];
            if ((isset($w['role_min']) && role_gte($globalRole, (string) $w['role_min']))
                || (isset($w['feature']) && in_array((string) $w['feature'], $features, true))) {
                $val = $c['value'] ?? null;
                break;
            }
        }
        $out[(string) $k] = $val;
    }
    return $out;
}

/** etag — 조건 결과가 사람마다 다르므로 그 입력(role_rank · 정렬한 features)을 넣는다. 앞 32자. */
function rc_etag(array $versions, string $who): string
{
    return substr(hash('sha256', $versions['global'] . '|' . $versions['service'] . '|' . $who), 0, 32);
}

function rc_who_user(string $globalRole, array $features): string
{
    sort($features);
    return rank_of(ROLE_RANK, $globalRole) . '|' . implode(',', $features);
}
