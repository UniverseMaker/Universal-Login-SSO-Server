<?php
declare(strict_types=1);

/**
 * core/db.php — PDO 싱글턴 + 쿼리 헬퍼 + settings K/V
 * 담당 계약: 전 엔드포인트의 데이터 접근 / v1.8.0 설계 §A·§B-2·§B-3·§B-4·§C-5 (SQLite 기본 + MySQL 선택)
 *
 * 규약
 *   - 값은 예외 없이 바인딩한다. 문자열 결합 SQL 금지.
 *   - 테이블명은 T_* 상수만 쓴다 (config.php 가 DB_PREFIX 로 조립).
 *   - db_insert/db_update 의 컬럼명은 코드 리터럴로만 넘긴다.
 *     사용자 입력을 컬럼명으로 쓰면 백틱 이스케이프가 뚫린다.
 *
 * ── v1.8.0 — 두 저장소. **방언 문장을 호출자에 흩지 않는다** ─────────────
 * 아래 헬퍼가 방언 차이를 한 자리에서 만든다. 새 쿼리를 쓰는 담당은
 * `FOR UPDATE`·`ON DUPLICATE KEY UPDATE`·`DELETE … LIMIT`·`information_schema`·
 * `SELECT VERSION()` 를 **직접 쓰지 말고** 이 헬퍼를 부른다(설계 0-2절):
 *
 *   sql_for_update()                       ''(sqlite) | ' FOR UPDATE'(mysql) — SELECT 끝에 붙인다
 *   db_upsert_increment($t,$keys,$inc,$extra[,$insertOnly])  원자 증가 UPSERT (B-4)
 *   db_delete_limited($t,$where,$params,$limit)              DELETE … LIMIT n (C-5)
 *   db_is_duplicate($e)                    UNIQUE 위반만 참. CHECK/NOT NULL 위반은 거짓 (B-3)
 *   db_is_lock_conflict($e)                교착·잠금 대기 초과 (mysql 1213·1205 / sqlite 5·6)
 *   db_table_exists($t) · db_column_exists($t,$c)            information_schema 대체 (A19)
 *   db_server_version()                    SELECT VERSION() 대체 (A21)
 *   db_driver()                            'sqlite' | 'mysql'
 *
 * 연결 자체(DSN · PRAGMA · time_zone)는 `core/dbdriver.php` 가 만든다 — migrate.php·install.php
 * 와 **같은 함수**다(A25). 여기에 DSN 을 다시 적지 마라.
 */


// 직접 실행 차단 — 리더 판정 D-34. bootstrap.php 를 거치지 않은 요청은 존재하지 않는 파일이다.
if (!defined('ULOGIN_BOOTSTRAPPED')) {
    // X-Powered-By 제거 — D-154(2). 이 경로는 bootstrap.php 를 거치지 않는다.
    header_remove('X-Powered-By');
    http_response_code(404);
    exit;
}

/** 'sqlite' | 'mysql'. `DB_DRIVER` 미정의면 'mysql' — v1.7.0 설치본이 한 글자도 안 바뀐다(E-1). */
function db_driver(): string
{
    return ulogin_db_driver_name();
}

function db(): PDO
{
    static $pdo  = null;
    static $fail = null;
    if ($pdo === null) {
        // 저장소를 못 쓰는 상태(파일 없음·무결성 실패)는 한 요청 안에서 다시 열어 보지 않는다.
        // quick_check 를 실패할 때마다 다시 돌리면 같은 요청에서 비용만 쌓인다.
        if ($fail !== null) {
            throw $fail;
        }
        try {
            if (db_driver() === 'sqlite') {
                // 🔴 create=false — 없는 파일을 만들지 않는다(설계 측정 12). 설치기만 만든다.
                // v1.9.0 D-363 — quick_check 는 여기서 돌리지 않고 db_sqlite_quick_check_cached() 가 정한다
                // (프로세스 첫 요청 1회 + 이후 최대 10분 간격, 결과는 DB 옆 캐시 파일). 실패는 그대로 fail-closed.
                $abs = ulogin_sqlite_path_resolve(defined('DB_SQLITE_PATH') ? DB_SQLITE_PATH : '');
                $pdo = ulogin_pdo_open_sqlite($abs, array('create' => false, 'quick_check' => false));
                try {
                    db_sqlite_quick_check_cached($pdo, $abs, defined('ULOGIN_FORCE_QUICK_CHECK'));
                } catch (UloginDbUnavailable $e) {
                    $pdo = null;
                    throw $e;
                }
            } else {
                // 포트 조각은 core/dbport.php 가 만든다 — `''` 또는 `';port=3307'`.
                // **판정을 여기에 복사하지 마라.** 상수도 환경변수도 없으면 빈 문자열이라
                // v1.6.0 과 **글자 단위로 같은 DSN** 이다.
                $pdo = ulogin_pdo_open_mysql(DB_HOST, ulogin_db_dsn_port(), DB_NAME, DB_CHARSET, DB_USER, DB_PASS);
            }
        } catch (UloginDbUnavailable $e) {
            $fail = $e;
            throw $e;
        }
    }
    return $pdo;
}

// ---------------------------------------------------------------------
// v1.9.0 D-363 — `PRAGMA quick_check` 주기화 (D-5-6 의 의미는 그대로, 빈도만 바꾼다)
//
// 요청마다 돌리면 비용이 DB 크기에 비례한다(실측: 10만 행 DB 에서 auth/csrf 160~190 ms, 빈 DB 35~57 ms).
// 플랫폼 로그·감사가 같은 파일(H-2)에 쌓이면 단일 작업자의 **모든 경로**가 느려진다.
//   · 프로세스 첫 요청에 1회(캐시의 pid 가 다르면 = 새 프로세스) + 이후 최대 10분 간격
//   · 마지막 결과·시각은 DB 파일 옆 `<DB파일>.quick_check`(버전 폴더 밖, 만들 때 ACL 좁힘)
//   · 🔴 **fail-closed 유지** — 마지막 검사가 실패면 다음 검사가 통과할 때까지 모든 요청이 UloginDbUnavailable
//     (bootstrap 이 503 + 로그. D-5-6 그대로). 캐시를 못 읽거나 깨졌으면 검사를 돌린다(캐시를 믿지 않는다)
//   · health(ULOGIN_FORCE_QUICK_CHECK) · migrate.php verify(ulogin_pdo_open_sqlite 기본값)는 매번 검사한다
// ---------------------------------------------------------------------

const DB_QUICK_CHECK_INTERVAL = 600;

function db_quick_check_cache_file(string $abs): string
{
    return $abs . '.quick_check';
}

/** 캐시가 유효하면 그 결과를 쓰고, 아니면 검사해 캐시를 갱신한다. 실패(검사 또는 캐시된 실패)면 예외. */
function db_sqlite_quick_check_cached(PDO $pdo, string $abs, bool $force, $now = null): void
{
    $cf = db_quick_check_cache_file($abs);
    $c  = null;
    if (is_file($cf)) {
        $j = json_decode((string) @file_get_contents($cf), true);
        if (is_array($j) && isset($j['pid'], $j['at'], $j['ok']) && is_int($j['pid']) && is_int($j['at']) && is_bool($j['ok'])) {
            $c = $j;
        }
    }
    $now = is_int($now) ? $now : time();   // 시험이 시각을 주입한다(운영 호출은 인자 없음)
    if (!$force && $c !== null && $c['pid'] === getmypid() && $now - $c['at'] >= 0 && $now - $c['at'] < DB_QUICK_CHECK_INTERVAL) {
        if ($c['ok'] !== true) {
            // 캐시된 실패 — 다음 검사(최대 10분 뒤)가 통과할 때까지 막는다. 로그는 남긴다(조용히 막지 않는다).
            error_log('[ulogin] sqlite quick_check failed earlier - writes stay stopped (503) until the next check passes.');
            throw new UloginDbUnavailable('sqlite quick_check failed (cached)');
        }
        return;
    }
    $rows = $pdo->query('PRAGMA quick_check')->fetchAll(PDO::FETCH_COLUMN);
    $ok = (count($rows) === 1 && strtolower((string) $rows[0]) === 'ok');
    $n = isset($c['n']) && is_int($c['n']) ? $c['n'] + 1 : 1;
    db_quick_check_cache_write($cf, ['pid' => getmypid(), 'at' => $now, 'ok' => $ok, 'n' => $n]);
    if (!$ok) {
        error_log('[ulogin] sqlite quick_check FAILED - writes stopped (503). do not restart; '
            . 'restore the newest backup that passes integrity_check. first finding: '
            . substr((string) (isset($rows[0]) ? $rows[0] : '(none)'), 0, 200));
        throw new UloginDbUnavailable('sqlite quick_check failed');
    }
}

/** 제자리 쓰기(같은 파일 → ACL 유지). 처음 만들면 ACL 을 좁힌다. 실패는 로그만(다음 요청이 다시 검사한다 = 안전 쪽). */
function db_quick_check_cache_write(string $cf, array $v): void
{
    $new = !is_file($cf);
    $fh = @fopen($cf, 'c');
    if ($fh === false) {
        error_log('[ulogin] quick_check cache not writable: ' . basename($cf));
        return;
    }
    if ($new && !ulogin_file_acl_restrict($cf)) {
        error_log('[ulogin] quick_check cache ACL could not be narrowed: ' . basename($cf));
    }
    if (flock($fh, LOCK_EX)) {
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string) json_encode($v));
        fflush($fh);
        flock($fh, LOCK_UN);
    }
    fclose($fh);
}

function ulogin_sys32(string $exe): string
{
    $root = getenv('SystemRoot');
    if (!is_string($root) || $root === '') {
        $root = 'C:/Windows';
    }
    return '"' . str_replace('\\', '/', $root) . '/System32/' . $exe . '"';
}

/**
 * 새 파일의 ACL 을 좁힌다 — 상속 끊고 현재 사용자(SID) + SYSTEM + Administrators 만 F. 비 Windows 는 0600.
 * 규칙은 tools/register_services.php 의 rs_acl_restrict()(handoff 파일)와 같다(그쪽은 CLI 전용이라 불러올 수 없다).
 * 쓰는 곳: 감사 머리 사본(logger.php) · quick_check 캐시(위). 실패하면 false.
 */
function ulogin_file_acl_restrict(string $file): bool
{
    if (stripos(PHP_OS, 'WIN') !== 0) {
        return @chmod($file, 0600);
    }
    $out = [];
    $rc  = 1;
    @exec(ulogin_sys32('whoami.exe') . ' /user /fo csv /nh', $out, $rc);
    $row = ($rc === 0 && isset($out[0])) ? str_getcsv(trim((string) $out[0])) : [];
    $sid = isset($row[1]) ? trim((string) $row[1]) : '';
    $grant = ' "*S-1-5-18:(F)" "*S-1-5-32-544:(F)"';
    if (preg_match('/^S-1-5-21-[0-9-]+$/', $sid) === 1) {
        $grant = ' "*' . $sid . ':(F)"' . $grant;
    }
    $out = [];
    $rc  = 1;
    @exec(ulogin_sys32('icacls.exe') . ' ' . escapeshellarg($file) . ' /inheritance:r /grant:r' . $grant . ' 2>&1', $out, $rc);
    return $rc === 0;
}

/**
 * 바인딩 값 정리 — **PHP `bool` 을 정수로 바꾼다** (설계 A-7).
 *
 * `execute([false])` 는 PDO 기본 `PARAM_STR` 로 바인딩되고 SQLite 는 그것을 **빈 문자열**로
 * 저장한다(실측). 그 행은 `WHERE flag = 0` 에도 `= 1` 에도 걸리지 않는데 PHP 쪽에서는
 * `(int)'' === 0` 이라 **화면은 정상으로 보인다** — 집계·필터만 조용히 어긋난다.
 * 규약(0-2절 7: `? 1 : 0` 으로 넘겨라)은 그대로 두고, 이 헬퍼를 거치는 경로에서는
 * 규약을 한 번 어겨도 데이터가 깨지지 않게 한다. (스키마도 `TINYINT(1)` 에 `CHECK(c IN (0,1))`
 * 를 걸어 이 경로 밖에서 들어온 `''` 를 **오류로** 만든다 — core/ddl.php)
 */
function db_params(array $p): array
{
    foreach ($p as $k => $v) {
        if (is_bool($v)) {
            $p[$k] = $v ? 1 : 0;
        }
    }
    return $p;
}

function db_all(string $sql, array $p = []): array
{
    $s = db()->prepare($sql);
    $s->execute(db_params($p));
    return $s->fetchAll();
}

/** @return array|null */
function db_one(string $sql, array $p = [])
{
    $s = db()->prepare($sql);
    $s->execute(db_params($p));
    $r = $s->fetch();
    return $r === false ? null : $r;
}

/** @return mixed|null */
function db_val(string $sql, array $p = [])
{
    $s = db()->prepare($sql);
    $s->execute(db_params($p));
    $v = $s->fetchColumn();
    return $v === false ? null : $v;
}

function db_exec(string $sql, array $p = []): int
{
    $s = db()->prepare($sql);
    $s->execute(db_params($p));
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

/** SQLite 트랜잭션이 열려 있는가 — 자체 플래그. `exec('BEGIN …')` 은 PDO 의 inTransaction() 에 안 잡힌다(실측). */
function db_sqlite_tx_flag(?bool $set = null): bool
{
    static $open = false;
    if ($set !== null) {
        $open = $set;
    }
    return $open;
}

/**
 * 트랜잭션. 예외가 나면 롤백하고 그대로 다시 던진다.
 * 이미 트랜잭션 안이면 중첩 BEGIN 을 하지 않고 콜백만 실행한다
 * (MySQL 은 중첩 트랜잭션을 지원하지 않는다 — BEGIN 이 암묵적 커밋을 일으킨다).
 *
 * ── SQLite: `BEGIN IMMEDIATE` (설계 B-2) ─────────────────────────────
 * 기본 `BEGIN`(deferred)은 읽기로 시작해 첫 쓰기에서 쓰기 잠금으로 **승격**한다. 동시에
 * 승격하려는 트랜잭션은 되돌릴 수 없어 즉시 `SQLITE_BUSY` 가 난다 — busy_timeout 도
 * 소용없다(실측: 16 중 15 `database is locked`). IMMEDIATE 는 **첫 문장부터 쓰기 잠금**을
 * 잡으므로 뒤에 온 트랜잭션은 busy_timeout 동안 줄을 선다. SQLite 의 쓰기 동시성 단위는
 * DB 전체라 이것이 MySQL `FOR UPDATE` 행 잠금을 대신한다. **호출자 19곳이 전부 쓰기다**
 * (설계 확인) — 읽기 전용 변종을 두지 않는다.
 * 🔴 `BEGIN IMMEDIATE` 를 `BEGIN` 으로 바꾸지 마라 — 경합 시험 변이 M1 이 그 상태다.
 * **조건부 UPDATE(`… AND used_at IS NULL` + rowCount 판정)는 호출자에 그대로 둔다** — 2차 방어.
 *
 * MySQL 경로는 v1.7.0 과 같다(beginTransaction / inTransaction).
 * @return mixed
 */
function db_tx(callable $fn)
{
    $pdo = db();
    if (db_driver() === 'sqlite') {
        if (db_sqlite_tx_flag()) {
            return $fn($pdo);
        }
        $pdo->exec('BEGIN IMMEDIATE');
        db_sqlite_tx_flag(true);
        try {
            $r = $fn($pdo);
            $pdo->exec('COMMIT');
            db_sqlite_tx_flag(false);
            return $r;
        } catch (Throwable $e) {
            db_sqlite_tx_flag(false);
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable $e2) {
                // SQLite 가 오류와 함께 이미 롤백한 경우(SQLITE_FULL 등) ROLLBACK 이 실패한다. 원 예외가 본체다.
                error_log('[ulogin] sqlite rollback after error failed: ' . $e2->getMessage());
            }
            throw $e;
        }
    }

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

/**
 * 행 잠금 꼬리. `SELECT … WHERE …` **끝에** 붙인다 — `'SELECT …' . sql_for_update()`.
 * SQLite 에는 문법이 없다(`near "FOR": syntax error`). 대신 `db_tx()` 가 `BEGIN IMMEDIATE`
 * 로 트랜잭션 전체를 직렬화한다. **선행 공백까지 이 함수가 만든다** — 값만 공유하면 한쪽이
 * 공백을 빠뜨린다(dbport.php 가 포트 조각을 통째로 만드는 것과 같은 이유. D-241·D-263).
 */
function sql_for_update(): string
{
    return db_driver() === 'sqlite' ? '' : ' FOR UPDATE';
}

/**
 * 원자 증가 UPSERT (설계 B-4). 레이트리밋·감사 버킷이 쓴다.
 *
 *   INSERT INTO $table (keys…, $incCol, extra…, insertOnly…) VALUES (…, 1, …)
 *   충돌 시: $incCol = $incCol + 1, extra 각 컬럼 = 새 값. insertOnly 는 **건드리지 않는다**
 *
 * 🔴 `$keys` 는 **충돌을 판정하는 UNIQUE(또는 PK) 컬럼 전부**여야 한다. SQLite 는 충돌 대상을
 * 명시해야 하고(MySQL 처럼 「아무 UNIQUE 나」가 없다) 목록이 틀리면 오류로 멈춘다.
 * 읽기 후 UPDATE 로 바꾸지 마라 — 16×50 경합에서 800 중 700건 이상이 샜다(설계 실측).
 *
 * @param array $keys       col => value (UNIQUE 를 이루는 컬럼)
 * @param string $incCol    증가 컬럼. 새 행이면 1
 * @param array $extra      col => value. INSERT 에 넣고 충돌 시 새 값으로 덮는다
 * @param array $insertOnly col => value. INSERT 에만 넣는다 (예: first_ip · first_at)
 * @return int rowCount
 */
function db_upsert_increment(string $table, array $keys, string $incCol, array $extra = [], array $insertOnly = [], int $by = 1): int
{
    if ($keys === []) {
        throw new InvalidArgumentException('db_upsert_increment needs conflict keys');
    }
    $cols = array_merge(array_keys($keys), [$incCol], array_keys($extra), array_keys($insertOnly));
    if (count(array_unique($cols)) !== count($cols)) {
        throw new InvalidArgumentException('db_upsert_increment column listed twice');
    }
    foreach ($cols as $c) {
        if (!is_string($c) || preg_match('/^[A-Za-z0-9_]+$/', $c) !== 1) {
            throw new InvalidArgumentException('bad column identifier');
        }
    }
    // v1.9.0 — $by(기본 1). 1 이면 문장이 종전과 글자까지 같다(`c = c + 1`). 그 밖은 `c = c + VALUES(c)`.
    $vals = array_merge(array_values($keys), [$by], array_values($extra), array_values($insertOnly));
    $updates = [$incCol => ($by === 1 ? 'inc' : 'add')];
    foreach (array_keys($extra) as $c) {
        $updates[$c] = 'new';
    }
    $sql = 'INSERT INTO ' . $table . ' (`' . implode('`, `', $cols) . '`) VALUES ('
         . implode(', ', array_fill(0, count($cols), '?')) . ')'
         . ulogin_sql_upsert_tail(db_driver(), array_keys($keys), $updates);
    return db_exec($sql, $vals);
}

/**
 * `DELETE … LIMIT n` (설계 C-5). SQLite 에는 문법이 없고(`near "LIMIT"`), 공통형도 없다
 * (MySQL 은 같은 테이블 서브쿼리를 1093 으로 막는다). 그래서 분기를 이 한 자리에 둔다.
 *
 *   mysql : DELETE FROM t WHERE {where} LIMIT n                    (v1.7.0 그대로)
 *   sqlite: DELETE FROM t WHERE rowid IN (SELECT rowid FROM t WHERE {where} LIMIT n)
 *
 * `$where` 는 코드 리터럴이다(db_update 의 `$where` 와 같은 규약). 값은 `$params` 로 바인딩.
 */
function db_delete_limited(string $table, string $where, array $params, int $limit): int
{
    $limit = max(1, $limit);
    if (db_driver() === 'sqlite') {
        return db_exec('DELETE FROM ' . $table . ' WHERE rowid IN (SELECT rowid FROM ' . $table
            . ' WHERE ' . $where . ' LIMIT ' . $limit . ')', $params);
    }
    return db_exec('DELETE FROM ' . $table . ' WHERE ' . $where . ' LIMIT ' . $limit, $params);
}

/**
 * UNIQUE 제약 위반인가 (중복 아이디/이메일·멱등키·레이트리밋 마커의 경쟁 판정용) — 설계 B-3.
 *
 * MySQL: errno 1062.
 * SQLite: `["23000", 19, …]` 인데 **19 는 UNIQUE·CHECK·NOT NULL 위반에 모두 쓰인다**(실측).
 * SQLSTATE 만 보고 참을 주면 `CHECK(length)` 위반(= 너무 긴 입력)이 「중복 아이디」로
 * 보고된다 — 사용자는 쓰지 않은 아이디가 쓰였다는 말을 듣고, rate_limit_claim_first() 는
 * CHECK 위반을 「이미 주장됨」으로 읽어 신호를 잃는다. 그래서 **메시지를 대조한다.**
 * 🔴 메시지 대조를 지우지 마라.
 */
function db_is_duplicate(Throwable $e): bool
{
    if (!($e instanceof PDOException)) {
        return false;
    }
    $info = is_array($e->errorInfo) ? $e->errorInfo : [];
    if (isset($info[1]) && (int) $info[1] === 1062) {
        return true;
    }
    $state = isset($info[0]) ? (string) $info[0] : (string) $e->getCode();
    $msg   = isset($info[2]) && is_string($info[2]) ? $info[2] : $e->getMessage();
    return $state === '23000' && stripos($msg, 'UNIQUE constraint failed') !== false;
}

/**
 * 교착·잠금 대기 초과인가 — 재시도·409 로 바꿀 수 있는 경합 오류.
 * MySQL 1213(deadlock) · 1205(lock wait timeout) / SQLite 5(SQLITE_BUSY) · 6(SQLITE_LOCKED).
 * **그 밖의 오류는 거짓이다** — 삼키면 진짜 결함이 조용해진다(설계 B-3).
 */
function db_is_lock_conflict(Throwable $e): bool
{
    if (!($e instanceof PDOException)) {
        return false;
    }
    $info  = is_array($e->errorInfo) ? $e->errorInfo : [];
    $errno = isset($info[1]) ? (int) $info[1] : 0;
    if ($errno === 1213 || $errno === 1205) {
        return true;
    }
    $state = isset($info[0]) ? (string) $info[0] : '';
    return ($errno === 5 || $errno === 6) && $state === 'HY000';
}

/** 테이블이 있는가 (A19 — information_schema 대체). */
function db_table_exists(string $table): bool
{
    if (db_driver() === 'sqlite') {
        return (int) db_val("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]) > 0;
    }
    return (int) db_val('SELECT COUNT(*) FROM information_schema.TABLES'
        . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]) > 0;
}

/** 컬럼이 있는가 (A19 — `core/secretguard.php` 의 allow_public 확인 등). */
function db_column_exists(string $table, string $column): bool
{
    if (db_driver() === 'sqlite') {
        return (int) db_val('SELECT COUNT(*) FROM pragma_table_info(?) WHERE name = ?', [$table, $column]) > 0;
    }
    return (int) db_val('SELECT COUNT(*) FROM information_schema.COLUMNS'
        . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]) > 0;
}

/** 저장소 서버 버전 문자열 (A21). 연결 확인 겸용 — health 가 쓴다. */
function db_server_version(): string
{
    return (string) db_val(db_driver() === 'sqlite' ? 'SELECT sqlite_version()' : 'SELECT VERSION()');
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
        . ulogin_sql_upsert_tail(db_driver(), ['skey'], [
            'svalue' => 'new', 'vtype' => 'new', 'label' => 'coalesce', 'updated_at' => 'new',
        ]),
        [$key, $sv, $vtype, $label, now_utc()]
    );
}
