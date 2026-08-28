<?php
declare(strict_types=1);

/**
 * core/db.php — PDO 싱글턴 + 쿼리 헬퍼 + settings K/V
 * 담당 계약: 전 엔드포인트의 데이터 접근
 *
 * 규약
 *   - 값은 예외 없이 바인딩한다. 문자열 결합 SQL 금지.
 *   - 테이블명은 T_* 상수만 쓴다 (config.php 가 DB_PREFIX 로 조립).
 *   - db_insert/db_update 의 컬럼명은 코드 리터럴로만 넘긴다.
 *     사용자 입력을 컬럼명으로 쓰면 백틱 이스케이프가 뚫린다.
 */


// 직접 실행 차단 — 리더 판정 D-34. bootstrap.php 를 거치지 않은 요청은 존재하지 않는 파일이다.
if (!defined('ULOGIN_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,   // 진짜 prepared statement 를 쓴다
        ]);
        // 앱이 UTC 로 넣고 UTC 로 읽는다. 세션 타임존을 못박아 NOW() 편차를 없앤다.
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

function db_all(string $sql, array $p = []): array
{
    $s = db()->prepare($sql);
    $s->execute($p);
    return $s->fetchAll();
}

/** @return array|null */
function db_one(string $sql, array $p = [])
{
    $s = db()->prepare($sql);
    $s->execute($p);
    $r = $s->fetch();
    return $r === false ? null : $r;
}

/** @return mixed|null */
function db_val(string $sql, array $p = [])
{
    $s = db()->prepare($sql);
    $s->execute($p);
    $v = $s->fetchColumn();
    return $v === false ? null : $v;
}

function db_exec(string $sql, array $p = []): int
{
    $s = db()->prepare($sql);
    $s->execute($p);
    return $s->rowCount();
}

function db_insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql  = 'INSERT INTO ' . $table . ' (`' . implode('`,`', $cols) . '`) VALUES ('
          . implode(',', array_fill(0, count($cols), '?')) . ')';
    db_exec($sql, array_values($data));
    return (int) db()->lastInsertId();
}

function db_update(string $table, array $data, string $where, array $wp = []): int
{
    $set = implode(', ', array_map(function ($c) { return '`' . $c . '` = ?'; }, array_keys($data)));
    return db_exec('UPDATE ' . $table . ' SET ' . $set . ' WHERE ' . $where,
        array_merge(array_values($data), $wp));
}

/**
 * 트랜잭션. 예외가 나면 롤백하고 그대로 다시 던진다.
 * 이미 트랜잭션 안이면 중첩 BEGIN 을 하지 않고 콜백만 실행한다
 * (MySQL 은 중첩 트랜잭션을 지원하지 않는다 — BEGIN 이 암묵적 커밋을 일으킨다).
 * @return mixed
 */
function db_tx(callable $fn)
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        return $fn($pdo);
    }
    $pdo->beginTransaction();
    try {
        $r = $fn($pdo);
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** UNIQUE 제약 위반인가 (중복 아이디/이메일 등의 경쟁 상태 판정용) */
function db_is_duplicate(Throwable $e): bool
{
    if (!($e instanceof PDOException)) {
        return false;
    }
    return isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062;
}

/** TEXT 컬럼의 JSON 문자열을 배열로. 깨져 있으면 $default. */
function json_col(?string $raw, array $default = []): array
{
    if ($raw === null || $raw === '') {
        return $default;
    }
    $v = json_decode($raw, true);
    return is_array($v) ? $v : $default;
}

// ---------------------------------------------------------------------
// settings (전역 설정 K/V) — 스펙 §7-2
//   DB 값이 있으면 그것을, 없으면 config.php 폴백을 쓴다.
//   테이블이 아직 없거나(설치 전) 조회에 실패하면 폴백으로 조용히 내려간다.
// ---------------------------------------------------------------------

/** @return array<string,array> skey => row */
function settings_all(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db_all('SELECT skey, svalue, vtype FROM ' . T_SETTINGS) as $r) {
                $cache[$r['skey']] = $r;
            }
        } catch (Throwable $e) {
            $cache = [];
        }
    }
    return $cache;
}

/**
 * 설정값 조회. vtype 에 따라 캐스팅한다.
 * @param mixed $default
 * @return mixed
 */
function setting_get(string $key, $default = null)
{
    $all = settings_all();
    if (!isset($all[$key])) {
        return $default;
    }
    $row = $all[$key];
    $v   = $row['svalue'];
    if ($v === null) {
        return $default;
    }
    switch ($row['vtype']) {
        case 'int':
            return (int) $v;
        case 'bool':
            return ($v === '1' || $v === 'true' || $v === 'on');
        case 'json':
            return json_col((string) $v);
        default:
            return (string) $v;
    }
}

/** 설정 저장(upsert). 관리자 API 에서 쓴다. */
function setting_set(string $key, $value, string $vtype = 'string', ?string $label = null): void
{
    if ($vtype === 'bool') {
        $sv = $value ? '1' : '0';
    } elseif ($vtype === 'json') {
        $sv = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        $sv = (string) $value;
    }
    db_exec(
        'INSERT INTO ' . T_SETTINGS . ' (skey, svalue, vtype, label, updated_at) VALUES (?, ?, ?, ?, ?)'
        . ' ON DUPLICATE KEY UPDATE svalue = VALUES(svalue), vtype = VALUES(vtype),'
        . ' label = COALESCE(VALUES(label), label), updated_at = VALUES(updated_at)',
        [$key, $sv, $vtype, $label, now_utc()]
    );
}
