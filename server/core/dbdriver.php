<?php
/**
 * core/dbdriver.php — 저장소 드라이버 판정 + PDO 연결. **DSN 을 만드는 유일한 곳** (v1.8.0)
 * 담당 계약: `_workspace/80_architect_v18_sqlite_spec.md` §A25 · §B-2 · §D-5-1 · §D-5-6 · §E-1
 *
 * =====================================================================
 * ▣ 왜 이 파일이 있는가 — **DSN 3곳이 한 함수를 부른다**(A25)
 *
 * PDO 연결을 여는 자리가 셋이다.
 *   server/core/db.php   — 일반 요청 경로 (bootstrap 경유)
 *   server/migrate.php   — 마이그레이션 러너 (bootstrap 도 core/db.php 도 로드하지 않는다)
 *   server/install.php   — 설치기 (config.php 가 아직 없다. 화면에서 받은 값을 쓴다)
 * v1.7.0 까지 셋이 각자 `'mysql:host=' . …` 를 조립했다. 한쪽에만 저장소 분기를 넣으면
 * **마이그레이션만 다른 DB 에 붙는** 사고가 난다 — v1.7.0 의 「포트를 빠뜨려 3306 에 붙는다」
 * (V14b-M-3 · D-241)와 같은 계열이다. 그래서 **DSN 문자열과 연결 직후 설정(PRAGMA·
 * time_zone)까지 이 파일의 함수가 만든다.** 세 곳은 값만 넘긴다.
 *
 * 포트 **판정**은 여전히 `core/dbport.php` 한 곳이다(D-241. 그 파일은 한 글자도 고치지 않았다).
 * 이 파일은 그 함수가 만든 **포트 조각 문자열**을 받아 끼울 뿐이다.
 *
 * ▣ 쿼리가 없다 — 그래서 migrate.php 가 로드해도 된다
 * 앱 테이블을 읽는 SQL 이 한 줄도 없다. PRAGMA 와 `quick_check` 뿐이다.
 * migrate.php 가 core/ 를 피하는 근거(「core 의 SELECT 는 최신 스키마를 전제한다」)에
 * 걸리지 않는다 — `core/dbport.php`·`core/transport.php` 와 같은 자리다.
 * **이 파일에 앱 테이블 쿼리·설정 상수 의존을 들이지 마라.** 들이는 순간 그 근거가 깨진다.
 * (예외: `ulogin_db_driver_name()` 이 `DB_DRIVER` 상수 하나를 읽는다. 없으면 'mysql'.)
 *
 * ▣ 직접 실행 가드 — 두 상수 중 하나 (dbport.php 와 같다)
 *   `ULOGIN_BOOTSTRAPPED` (core 경로) 또는 `ULOGIN_MIGRATE_CTX` (러너·설치기 경로)
 *
 * `declare(strict_types=1)` 를 쓰지 않는다 — migrate.php·install.php 가 그 선언 없이 돈다.
 * **PHP 7.2 호환.** 금지 문법은 core/bootstrap.php 상단 목록.
 * =====================================================================
 */

if (!defined('ULOGIN_BOOTSTRAPPED') && !defined('ULOGIN_MIGRATE_CTX')) {
    header_remove('X-Powered-By');
    http_response_code(404);
    exit;
}

if (!class_exists('UloginDbUnavailable', false)) {
    /**
     * 저장소를 **지금 쓸 수 없다** — DB 파일 없음 · 0바이트 · `quick_check` 실패.
     *
     * `RuntimeException`(설정 오류 → 500)과 구분하는 이유: 지킴이는 503 이면 재시작하지 않는다
     * (D-250 · 설계 G-2). 손상된 DB 는 **재시작으로 낫지 않으므로** 재시작 루프에 넣지 않는다.
     * bootstrap 의 예외 처리기가 이 클래스를 503 `SERVER_ERROR` 로 내보낸다(에러 코드 무변경).
     */
    class UloginDbUnavailable extends RuntimeException
    {
    }
}

/**
 * 이 설치본이 쓰는 저장소. **`DB_DRIVER` 미정의 = `'mysql'`** (설계 E-1).
 *
 * 기존 MySQL 설치본의 config.php 에는 이 상수가 없다. 없을 때의 동작이 v1.7.0 과
 * **완전히 같아야** 한다 — `DB_PORT` 의 `defined()` 가드(D-241)와 같은 원칙이다.
 * 값은 정확히 `'sqlite'`·`'mysql'` 둘뿐이다. 대소문자·공백 변형도 받지 않는다.
 * **그 밖의 값은 예외다. 폴백하지 않는다** — 오타가 조용히 MySQL 로 떨어지면
 * 포트 미지정 → 3306 → 남의 DB 로 이어질 수 있다(dbport.php 머리 주석).
 *
 * @return string 'sqlite' | 'mysql'
 * @throws RuntimeException 정의돼 있는데 값이 둘 중 하나가 아닐 때
 */
function ulogin_db_driver_name()
{
    if (!defined('DB_DRIVER')) {
        return 'mysql';
    }
    $v = DB_DRIVER;
    if ($v === 'sqlite' || $v === 'mysql') {
        return $v;
    }
    error_log('[ulogin] DB_DRIVER must be exactly \'sqlite\' or \'mysql\'. refusing to guess.');
    throw new RuntimeException('invalid DB_DRIVER');
}

/** 설치 루트(= `server/` 의 부모). 슬래시 정규화. 상대 DB 경로의 기준이다(E-1). */
function ulogin_install_root()
{
    return str_replace('\\', '/', dirname(dirname(__DIR__)));
}

/**
 * `DB_SQLITE_PATH` 를 절대 경로로 푼다.
 *
 * - 상대 경로는 **설치 루트 기준**이다. 설치기가 쓰는 기본값 `../runtime/data/ulogin.sqlite` 는
 *   설치 루트(버전 폴더)의 **부모** 아래 = 문서 루트 밖이다(D-281 · 보안 H17-4).
 *   절대 경로를 코드·설정에 박지 않는 것이 G10 의 규약이다 — 폴더째 옮겨도 그대로 돈다.
 * - `..` 는 **글자 단위로** 접는다(realpath 를 쓰지 않는다 — 파일이 아직 없을 수 있다).
 * - `:memory:`·`file:` URI 는 거부한다. 앞의 것은 **조용히 빈 DB 로 운영**되고,
 *   뒤의 것은 아래 「파일이 있는가」 검사를 우회하는 문법이다.
 *
 * @param string      $raw  설정값 그대로
 * @param string|null $base 상대 경로 기준. null 이면 설치 루트
 * @return string 슬래시 정규화된 절대 경로
 * @throws RuntimeException 비었거나 받을 수 없는 형식
 */
function ulogin_sqlite_path_resolve($raw, $base = null)
{
    if (!is_string($raw) || trim($raw) === '') {
        error_log('[ulogin] DB_DRIVER is sqlite but DB_SQLITE_PATH is empty or not a string.');
        throw new RuntimeException('DB_SQLITE_PATH empty');
    }
    $p = trim($raw);
    if (strpos($p, "\0") !== false || strlen($p) > 1024) {
        throw new RuntimeException('DB_SQLITE_PATH malformed');
    }
    $low = strtolower($p);
    if ($low === ':memory:' || strpos($low, 'file:') === 0 || strpos($p, '?') !== false) {
        error_log('[ulogin] DB_SQLITE_PATH must be a plain file path (no :memory:, file: URI or query).');
        throw new RuntimeException('DB_SQLITE_PATH not a plain path');
    }
    $p = str_replace('\\', '/', $p);
    $isAbs = (preg_match('#^[A-Za-z]:/#', $p) === 1) || (substr($p, 0, 1) === '/');
    if (!$isAbs) {
        $b = ($base === null) ? ulogin_install_root() : str_replace('\\', '/', (string) $base);
        $p = rtrim($b, '/') . '/' . $p;
    }
    return ulogin_path_collapse($p);
}

/** `.`·`..`·중복 슬래시를 글자 단위로 접는다. 드라이브 문자·UNC 머리는 보존한다. */
function ulogin_path_collapse($p)
{
    $head = '';
    if (preg_match('#^([A-Za-z]:)/#', $p, $m) === 1) {
        $head = $m[1] . '/';
        $p    = substr($p, 3);
    } elseif (substr($p, 0, 2) === '//') {
        $head = '//';
        $p    = substr($p, 2);
    } elseif (substr($p, 0, 1) === '/') {
        $head = '/';
        $p    = substr($p, 1);
    }
    $out = array();
    foreach (explode('/', $p) as $seg) {
        if ($seg === '' || $seg === '.') {
            continue;
        }
        if ($seg === '..') {
            if ($out !== array() && end($out) !== '..') {
                array_pop($out);
            } elseif ($head === '') {
                $out[] = '..';
            }
            continue;
        }
        $out[] = $seg;
    }
    return $head . implode('/', $out);
}

/**
 * MySQL DSN. **글자 단위로 v1.7.0 과 같다** — `mysql:host=H{포트조각};dbname=N;charset=C`.
 * 포트 조각은 호출자가 넘긴다: 요청 경로·러너는 `ulogin_db_dsn_port()`(dbport.php),
 * 설치기는 화면 입력값으로 만든 `''` 또는 `';port=N'`.
 */
function ulogin_mysql_dsn($host, $portFragment, $name, $charset)
{
    return 'mysql:host=' . $host . $portFragment . ';dbname=' . $name . ';charset=' . $charset;
}

/** 세 연결 지점이 공유하는 PDO 옵션. `PDO::ATTR_TIMEOUT` 은 넣지 않는다(SQLite 드라이버가 거부 — 설계 측정 11). */
function ulogin_pdo_options()
{
    return array(
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,   // 진짜 prepared statement 를 쓴다
    );
}

/** MySQL 연결. v1.7.0 의 세 사본과 같은 동작(DSN · 옵션 · `SET time_zone`). */
function ulogin_pdo_open_mysql($host, $portFragment, $name, $charset, $user, $pass)
{
    $pdo = new PDO(ulogin_mysql_dsn($host, $portFragment, $name, $charset), $user, $pass, ulogin_pdo_options());
    // 앱이 UTC 로 넣고 UTC 로 읽는다. 세션 타임존을 못박아 NOW() 편차를 없앤다.
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

/**
 * SQLite 연결.
 *
 * 🔴 **없는 파일을 열면 SQLite 가 빈 DB 를 만든다**(설계 측정 12). 경로를 한 글자 틀리면
 * **사용자 전원이 사라진 빈 서비스가 조용히 뜬다.** 그래서 `create=false`(기본)이면
 * 열기 **전에** 파일이 있고 0바이트가 아닌지 본다. 없으면 `UloginDbUnavailable`(→ 503).
 * **`create=true` 는 설치기 2단계만 쓴다** — DB 파일을 만들 수 있는 것은 설치기뿐이다.
 * (0바이트 검사: PDO 가 만든 직후의 파일은 0바이트다. 검사와 열기 사이에 지워져 다시
 *  만들어지는 경합이 남는데, 그 경우에도 아래 `quick_check` 뒤의 첫 쿼리가 「테이블 없음」
 *  으로 실패한다 — 빈 DB 로 **쓰기가 성공하는** 경로는 남지 않는다.)
 *
 * 연결 직후 PRAGMA 4개(설계 B-2 · D-5-1):
 *   busy_timeout=5000 (먼저 — 아래 WAL 전환이 잠금을 기다리게)
 *   journal_mode=WAL  (백업 프로세스의 읽기가 쓰기를 막지 않게)
 *   synchronous=FULL  (🔴 정전 대비의 본체. 매 커밋 fsync. **읽어서 2 가 아니면 거부한다**)
 *   foreign_keys=ON   (지금 FK 제약은 0개라 동작은 같다. 기본이 OFF 라는 사실을 남긴다)
 *
 * `quick_check` (D-5-6): 결과가 `ok` 가 아니면 **쓰기를 멈춘다** — 예외(503). 자동 복구하지 않는다.
 *
 * @param string $absPath 절대 경로 (`ulogin_sqlite_path_resolve()` 결과)
 * @param array  $opt     ['create' => bool, 'quick_check' => bool]
 */
function ulogin_pdo_open_sqlite($absPath, array $opt = array())
{
    $create = !empty($opt['create']);
    $check  = !array_key_exists('quick_check', $opt) || !empty($opt['quick_check']);

    if (!extension_loaded('pdo_sqlite')) {
        error_log('[ulogin] DB_DRIVER is sqlite but the pdo_sqlite extension is not loaded.');
        throw new RuntimeException('pdo_sqlite missing');
    }
    if (!$create) {
        clearstatcache(true, $absPath);
        if (!is_file($absPath)) {
            error_log('[ulogin] sqlite database file not found. refusing to create an empty one - '
                . 'check DB_SQLITE_PATH (relative paths resolve from the install root). '
                . 'restore from backup if the file was lost.');
            throw new UloginDbUnavailable('sqlite file missing');
        }
        if ((int) @filesize($absPath) === 0) {
            error_log('[ulogin] sqlite database file is 0 bytes. refusing to run on an empty database.');
            throw new UloginDbUnavailable('sqlite file empty');
        }
    }

    $pdo = new PDO('sqlite:' . $absPath, null, null, ulogin_pdo_options());
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $jm = strtolower((string) $pdo->query('PRAGMA journal_mode = WAL')->fetchColumn());
    $pdo->exec('PRAGMA synchronous = FULL');
    $pdo->exec('PRAGMA foreign_keys = ON');

    $sync = (int) $pdo->query('PRAGMA synchronous')->fetchColumn();
    if ($sync !== 2) {
        error_log('[ulogin] sqlite synchronous is ' . $sync . ' after setting FULL. refusing to write without fsync.');
        throw new UloginDbUnavailable('sqlite synchronous not FULL');
    }
    if ($jm !== 'wal') {
        // 내구성은 synchronous=FULL 이 지킨다. WAL 이 안 되면 백업 읽기가 쓰기를 잠깐 막을 뿐이다.
        // 멈추지 않되 반드시 남긴다 — 네트워크 드라이브·읽기 전용 폴더에서 이렇게 된다.
        error_log('[ulogin] sqlite journal_mode is "' . $jm . '" (expected wal). continuing with synchronous=FULL.');
    }

    if ($check) {
        $rows = $pdo->query('PRAGMA quick_check')->fetchAll(PDO::FETCH_COLUMN);
        if (count($rows) !== 1 || strtolower((string) $rows[0]) !== 'ok') {
            error_log('[ulogin] sqlite quick_check FAILED - writes stopped (503). do not restart; '
                . 'restore the newest backup that passes integrity_check. first finding: '
                . substr((string) (isset($rows[0]) ? $rows[0] : '(none)'), 0, 200));
            throw new UloginDbUnavailable('sqlite quick_check failed');
        }
    }
    return $pdo;
}

/** 이 PDO 가 어느 드라이버인가 — 상수가 아니라 **연결 자체**에 묻는다. 'sqlite' | 'mysql' | … */
function ulogin_pdo_driver(PDO $pdo)
{
    return (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
}

/**
 * UPSERT 꼬리절. 두 방언을 **한 함수**가 만든다(A2·A3).
 *
 *   mysql : ` ON DUPLICATE KEY UPDATE `c` = VALUES(`c`), …`
 *   sqlite: ` ON CONFLICT(`k1`, `k2`) DO UPDATE SET `c` = excluded.`c`, …`
 *
 * **SQLite 는 충돌 대상 컬럼을 명시해야 한다** — MySQL 처럼 「어느 UNIQUE 든」이 없다.
 * 그래서 `$conflictCols` 가 필수이고, 그 목록이 실제 UNIQUE/PK 와 다르면 SQLite 가 오류를 낸다
 * (조용히 INSERT 로 떨어지지 않는다).
 *
 * @param string $driver       'sqlite' | 'mysql'
 * @param array  $conflictCols UNIQUE(또는 PK) 를 이루는 컬럼 목록
 * @param array  $updates      col => 'new' | 'inc' | 'coalesce' | 'null'
 *                             new=새 값으로 · inc=기존+1 · coalesce=새 값이 NULL 이면 기존 유지 · null=NULL
 */
function ulogin_sql_upsert_tail($driver, array $conflictCols, array $updates)
{
    $q = function ($c) {
        if (!is_string($c) || preg_match('/^[A-Za-z0-9_]+$/', $c) !== 1) {
            throw new InvalidArgumentException('bad column identifier');
        }
        return '`' . $c . '`';
    };
    if ($updates === array()) {
        throw new InvalidArgumentException('upsert needs at least one update column');
    }
    $sqlite = ($driver === 'sqlite');
    $sets = array();
    foreach ($updates as $col => $mode) {
        $c   = $q($col);
        $new = $sqlite ? 'excluded.' . $c : 'VALUES(' . $c . ')';
        if ($mode === 'new') {
            $sets[] = $c . ' = ' . $new;
        } elseif ($mode === 'inc') {
            $sets[] = $c . ' = ' . $c . ' + 1';
        } elseif ($mode === 'add') {
            // v1.9.0 — 증가량을 VALUES 에서 받는다(db_upsert_increment 의 $by). 배치 건수를 한 문장으로 더한다.
            $sets[] = $c . ' = ' . $c . ' + ' . $new;
        } elseif ($mode === 'coalesce') {
            $sets[] = $c . ' = COALESCE(' . $new . ', ' . $c . ')';
        } elseif ($mode === 'null') {
            $sets[] = $c . ' = NULL';
        } else {
            throw new InvalidArgumentException('unknown upsert mode');
        }
    }
    if (!$sqlite) {
        return ' ON DUPLICATE KEY UPDATE ' . implode(', ', $sets);
    }
    if ($conflictCols === array()) {
        throw new InvalidArgumentException('sqlite upsert needs conflict columns');
    }
    $k = array();
    foreach ($conflictCols as $c) {
        $k[] = $q($c);
    }
    return ' ON CONFLICT(' . implode(', ', $k) . ') DO UPDATE SET ' . implode(', ', $sets);
}
