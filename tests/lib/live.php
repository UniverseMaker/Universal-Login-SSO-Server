<?php
declare(strict_types=1);

/**
 * tests/lib/live.php — 티어 3 실측 하네스 (실 MySQL/MariaDB + `php -S`)
 *
 * ─── SQLite 스텁을 쓰지 않는 이유 ─────────────────────────────────────
 * `server/schema.sql` 은 MySQL 전용이다 — `ENGINE=InnoDB`, `ROW_FORMAT=DYNAMIC`,
 * `utf8mb4_unicode_ci`, `BIGINT UNSIGNED`. 게다가 코드가 `SUM(status = ?)` 처럼
 * **불리언을 정수로 쓰는 MySQL 관용구**에 의존한다. SQLite 용 두 번째 스키마를
 * 만드는 순간 **두 스키마가 갈라지고, 그 드리프트가 정확히 이 프로젝트가 반복해
 * 맞은 경계면 버그의 종류다.** 실 DB 를 쓰고, 없으면 SKIP(종료코드 3)한다.
 *
 * ─── 대상 트리를 절대 건드리지 않는다 ────────────────────────────────
 * 웹서버는 `server/` · `public/` · `examples/` 를 **임시 폴더로 복사한 샌드박스**
 * 에 대고 띄운다. `config.php` 도 거기에만 쓴다. 저장소 트리에는 파일 하나
 * 만들지 않는다 — 테스트가 대상을 고치면 테스트가 아니다.
 *
 * ─── 부산물은 실패해도 정리된다 ──────────────────────────────────────
 * `register_shutdown_function` 으로 서버 종료 · DB DROP · 샌드박스 삭제를 건다.
 * 예외로 죽든 단언 실패로 죽든 같은 경로를 지난다.
 *
 * ─── 접속 정보 ───────────────────────────────────────────────────────
 * 환경변수가 1순위, `tests/config.local.php` 가 2순위다. 둘 다 없으면 전부 SKIP.
 *   ULOGIN_TEST_DB_HOST  ULOGIN_TEST_DB_PORT  ULOGIN_TEST_DB_USER
 *   ULOGIN_TEST_DB_PASS  ULOGIN_TEST_DB_NAME  ULOGIN_TEST_HTTP_PORT
 * 자세한 것은 `tests/config.sample.php`.
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/assert.php';

/** 이 실행에서 만든 것들. 종료 시 전부 되돌린다. */
$GLOBALS['LIVE'] = array(
    'cfg'      => null,
    'sandbox'  => null,
    'proc'     => null,
    'procPipes' => array(),
    'baseUrl'  => null,
    'dbs'      => array(),   // 만든 DB 이름들
    'pdo'      => null,
    'registered' => false,
);

/**
 * 접속 설정. 없으면 null (= 티어 3 전부 SKIP).
 * @return array|null
 */
function live_config(): ?array
{
    $L = &$GLOBALS['LIVE'];
    if ($L['cfg'] !== null) {
        return $L['cfg'] === false ? null : $L['cfg'];
    }
    $c = array(
        'db_host'   => getenv('ULOGIN_TEST_DB_HOST'),
        'db_port'   => getenv('ULOGIN_TEST_DB_PORT'),
        'db_user'   => getenv('ULOGIN_TEST_DB_USER'),
        'db_pass'   => getenv('ULOGIN_TEST_DB_PASS'),
        'db_name'   => getenv('ULOGIN_TEST_DB_NAME'),
        'http_port' => getenv('ULOGIN_TEST_HTTP_PORT'),
    );
    $file = h_tests_dir() . '/config.local.php';
    if (is_file($file)) {
        $fromFile = require $file;
        if (is_array($fromFile)) {
            foreach ($fromFile as $k => $v) {
                if ($c[$k] === false || $c[$k] === '' || $c[$k] === null) {
                    $c[$k] = $v;
                }
            }
        }
    }
    // 최소 요건: 호스트와 사용자. 비밀번호는 빈 문자열일 수 있다.
    if ($c['db_host'] === false || $c['db_host'] === '' || $c['db_user'] === false || $c['db_user'] === '') {
        $L['cfg'] = false;
        return null;
    }
    $c['db_port']   = (int) ($c['db_port'] ?: 3306);
    $c['db_pass']   = $c['db_pass'] === false ? '' : (string) $c['db_pass'];
    $c['db_name']   = $c['db_name'] ?: 'ulogin_test';
    $c['http_port'] = (int) ($c['http_port'] ?: 8199);
    $L['cfg'] = $c;
    return $c;
}

/** 티어 3 케이스의 첫 줄. 환경이 없으면 SKIP 한다. */
function live_or_skip(): array
{
    $c = live_config();
    if ($c === null) {
        skip('no test database configured (set ULOGIN_TEST_DB_* or create tests/config.local.php; see tests/config.sample.php)');
    }
    if (!extension_loaded('pdo_mysql')) {
        skip('PHP extension pdo_mysql is not loaded');
    }
    live_register_teardown();
    return $c;
}

function live_register_teardown(): void
{
    $L = &$GLOBALS['LIVE'];
    if ($L['registered']) {
        return;
    }
    $L['registered'] = true;
    // **실패해도 정리한다.** 예외로 죽든 단언 실패로 죽든 이 경로를 지난다.
    register_shutdown_function('live_teardown');
}

/**
 * 서버 계층(DB 자체)에 붙는다. 특정 스키마가 아니다.
 * @throws TestSkip 붙을 수 없으면 SKIP — 실패가 아니다. 환경의 문제다
 */
function live_pdo(?string $dbName = null): PDO
{
    $c = live_config();
    if ($c === null) {
        skip('no test database configured');
    }
    $dsn = 'mysql:host=' . $c['db_host'] . ';port=' . $c['db_port'] . ';charset=utf8mb4';
    if ($dbName !== null) {
        $dsn .= ';dbname=' . $dbName;
    }
    try {
        return new PDO($dsn, $c['db_user'], $c['db_pass'], array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ));
    } catch (PDOException $e) {
        skip('cannot reach the test database at ' . $c['db_host'] . ':' . $c['db_port']
            . ' as "' . $c['db_user'] . '" (' . $e->getMessage() . ')');
    }
    // @phpstan-ignore-next-line  skip() 는 항상 던진다
    throw new RuntimeException('unreachable');
}

/**
 * 빈 DB 를 만든다. 이미 있으면 지우고 다시 만든다.
 * **테스트 전용 이름만 받는다** — 실수로 운영 DB 를 지우지 않게 접두사를 강제한다.
 */
function live_fresh_db(string $name): PDO
{
    if (strpos($name, 'ulogin_test') !== 0) {
        throw new RuntimeException('refusing to create/drop a database whose name does not start with "ulogin_test": ' . $name);
    }
    $pdo = live_pdo();
    $pdo->exec('DROP DATABASE IF EXISTS `' . $name . '`');
    $pdo->exec('CREATE DATABASE `' . $name . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $GLOBALS['LIVE']['dbs'][$name] = true;
    return live_pdo($name);
}

/**
 * `.sql` 파일을 **무수정으로** 적용한다.
 *
 * 부수 효과가 크다 — `README.md` 의 "MariaDB 에 schema.sql 무수정 적용 15/15" 는
 * 일회성 주장이었는데, 이 경로는 그것을 **매 실행마다 재확인**한다.
 *
 * @param string $prefix `DB_PREFIX`. 스키마의 접두사와 다르면 치환한다
 * @return int 실행한 문장 수
 */
function live_apply_sql(PDO $pdo, string $sqlPath, string $fromPrefix = '', string $toPrefix = ''): int
{
    $sql = h_read($sqlPath);
    if ($fromPrefix !== '' && $toPrefix !== '' && $fromPrefix !== $toPrefix) {
        $sql = str_replace($fromPrefix, $toPrefix, $sql);
    }
    $n = 0;
    foreach (live_split_sql($sql) as $stmt) {
        $pdo->exec($stmt);
        $n++;
    }
    return $n;
}

/**
 * `;` 로 문장을 쪼갠다. 문자열 리터럴과 `--` 주석 안의 `;` 는 건드리지 않는다.
 * 이 스키마에는 트리거·프로시저가 없으므로 DELIMITER 처리는 필요 없다 —
 * 생기면 여기가 먼저 깨진다(조용히 틀리지 않는다).
 */
function live_split_sql(string $sql): array
{
    $out = array();
    $cur = '';
    $n = strlen($sql);
    $q = '';
    for ($i = 0; $i < $n; $i++) {
        $ch = $sql[$i];
        if ($q !== '') {
            $cur .= $ch;
            if ($ch === '\\' && $i + 1 < $n) {
                $cur .= $sql[++$i];
                continue;
            }
            if ($ch === $q) {
                $q = '';
            }
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $q = $ch;
            $cur .= $ch;
            continue;
        }
        // 줄 주석
        if ($ch === '-' && $i + 1 < $n && $sql[$i + 1] === '-') {
            while ($i < $n && $sql[$i] !== "\n") {
                $i++;
            }
            $cur .= "\n";
            continue;
        }
        if ($ch === '#') {
            while ($i < $n && $sql[$i] !== "\n") {
                $i++;
            }
            $cur .= "\n";
            continue;
        }
        if ($ch === '/' && $i + 1 < $n && $sql[$i + 1] === '*') {
            $end = strpos($sql, '*/', $i);
            $i = $end === false ? $n : $end + 1;
            continue;
        }
        if ($ch === ';') {
            if (trim($cur) !== '') {
                $out[] = trim($cur);
            }
            $cur = '';
            continue;
        }
        $cur .= $ch;
    }
    if (trim($cur) !== '') {
        $out[] = trim($cur);
    }
    return $out;
}

/**
 * `information_schema` 에서 스키마의 **구조 지문**을 뽑는다.
 *
 * `migrate.php verify` 가 보지 않는 것까지 본다 (D-175 — verify 의 맹점이
 * 문서보다 넓다). 컬럼 타입·NULL 허용·기본값·**COMMENT**·인덱스·유니크·
 * 외래키까지 전부 담는다. 두 경로(신규 설치 / 업그레이드)의 지문이 같아야 한다.
 *
 * @return array 정규화된 비교용 배열
 */
function live_schema_fingerprint(PDO $pdo, string $dbName, string $prefix): array
{
    $fp = array('tables' => array(), 'indexes' => array(), 'fks' => array());

    $st = $pdo->prepare(
        'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA,
                COLUMN_COMMENT, CHARACTER_SET_NAME, COLLATION_NAME, ORDINAL_POSITION
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ?
          ORDER BY TABLE_NAME, ORDINAL_POSITION'
    );
    $st->execute(array($dbName));
    foreach ($st->fetchAll() as $r) {
        $t = live_strip_prefix((string) $r['TABLE_NAME'], $prefix);
        $fp['tables'][$t][(string) $r['COLUMN_NAME']] = array(
            'type'      => (string) $r['COLUMN_TYPE'],
            'nullable'  => (string) $r['IS_NULLABLE'],
            'default'   => $r['COLUMN_DEFAULT'],
            'extra'     => (string) $r['EXTRA'],
            'comment'   => (string) $r['COLUMN_COMMENT'],
            'charset'   => (string) $r['CHARACTER_SET_NAME'],
            'collation' => (string) $r['COLLATION_NAME'],
            'position'  => (int) $r['ORDINAL_POSITION'],
        );
    }

    $st = $pdo->prepare(
        'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, INDEX_TYPE
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = ?
          ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX'
    );
    $st->execute(array($dbName));
    foreach ($st->fetchAll() as $r) {
        $t = live_strip_prefix((string) $r['TABLE_NAME'], $prefix);
        $k = $t . '.' . $r['INDEX_NAME'];
        if (!isset($fp['indexes'][$k])) {
            $fp['indexes'][$k] = array('unique' => ((int) $r['NON_UNIQUE'] === 0), 'type' => (string) $r['INDEX_TYPE'], 'cols' => array());
        }
        $fp['indexes'][$k]['cols'][] = (string) $r['COLUMN_NAME'];
    }

    $st = $pdo->prepare(
        'SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
           FROM information_schema.KEY_COLUMN_USAGE
          WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL
          ORDER BY TABLE_NAME, COLUMN_NAME'
    );
    $st->execute(array($dbName));
    foreach ($st->fetchAll() as $r) {
        $fp['fks'][] = live_strip_prefix((string) $r['TABLE_NAME'], $prefix) . '.' . $r['COLUMN_NAME']
            . ' -> ' . live_strip_prefix((string) $r['REFERENCED_TABLE_NAME'], $prefix) . '.' . $r['REFERENCED_COLUMN_NAME'];
    }

    ksort($fp['tables']);
    ksort($fp['indexes']);
    sort($fp['fks']);
    foreach ($fp['tables'] as $t => $cols) {
        ksort($cols);
        $fp['tables'][$t] = $cols;
    }
    return $fp;
}

/**
 * 두 스키마 지문을 대조한다.
 *
 * **테스트가 아니라 라이브러리에 두는 이유:** 이 비교기 자신을 검증해야 하기 때문이다.
 * `tests/selfcheck/s_40_live_oracle.php` 가 **일부러 어긋난 두 스키마**를 먹여
 * 비교기가 실제로 잡는지 확인한다. 테스트 파일 안에 인라인하면 그게 불가능해지고,
 * 그러면 "차이 0건" 이 증거가 아니게 된다.
 *
 * @param array $soft (out) 동작은 같지만 갈린 것 — COMMENT · 컬럼 순서
 * @return array 하드 차이 목록 (빈 배열 = 동작상 동일)
 */
function live_fingerprint_diff(array $fpA, array $fpB, array &$soft = array(), string $labelA = 'A', string $labelB = 'B'): array
{
    $diffs = array();
    $soft = array();

    $tA = array_keys($fpA['tables']);
    $tB = array_keys($fpB['tables']);
    foreach (array_diff($tA, $tB) as $x) {
        $diffs[] = 'table "' . $x . '" exists only in ' . $labelA;
    }
    foreach (array_diff($tB, $tA) as $x) {
        $diffs[] = 'table "' . $x . '" exists only in ' . $labelB;
    }

    foreach (array_intersect($tA, $tB) as $tbl) {
        $cA = $fpA['tables'][$tbl];
        $cB = $fpB['tables'][$tbl];
        foreach (array_diff(array_keys($cA), array_keys($cB)) as $col) {
            $diffs[] = $tbl . '.' . $col . ' exists only in ' . $labelA;
        }
        foreach (array_diff(array_keys($cB), array_keys($cA)) as $col) {
            $diffs[] = $tbl . '.' . $col . ' exists only in ' . $labelB;
        }
        foreach (array_intersect(array_keys($cA), array_keys($cB)) as $col) {
            foreach ($cA[$col] as $attr => $va) {
                $vb = $cB[$col][$attr];
                if ($va === $vb) {
                    continue;
                }
                /*
                 * 동작은 같지만 갈리는 두 속성.
                 *  comment  : `ALTER TABLE ... ADD COLUMN` 은 기존 컬럼의 COMMENT 를
                 *             갱신하지 않는다. 업그레이드본의 DB 자기문서화가 낡는다
                 *  position : `ADD COLUMN` 은 끝에 붙는다. `SELECT *` 의 열 순서가 다르다.
                 *             코드가 열 이름으로 읽으므로 무해하다
                 * 나머지(type · nullable · default · extra · charset · collation)는 전부 하드다 —
                 * 하나라도 갈리면 같은 쿼리가 두 설치본에서 다르게 동작한다.
                 */
                if ($attr === 'comment' || $attr === 'position') {
                    $soft[] = $tbl . '.' . $col . ' ' . $attr . ': ' . $labelA . '=' . ut_show($va)
                        . ' ' . $labelB . '=' . ut_show($vb);
                    continue;
                }
                $diffs[] = $tbl . '.' . $col . ' ' . $attr . ': ' . $labelA . '=' . ut_show($va)
                    . ' ' . $labelB . '=' . ut_show($vb);
            }
        }
    }

    foreach (array_diff(array_keys($fpA['indexes']), array_keys($fpB['indexes'])) as $ix) {
        $diffs[] = 'index ' . $ix . ' exists only in ' . $labelA;
    }
    foreach (array_diff(array_keys($fpB['indexes']), array_keys($fpA['indexes'])) as $ix) {
        $diffs[] = 'index ' . $ix . ' exists only in ' . $labelB;
    }
    foreach (array_intersect(array_keys($fpA['indexes']), array_keys($fpB['indexes'])) as $ix) {
        if ($fpA['indexes'][$ix] !== $fpB['indexes'][$ix]) {
            $diffs[] = 'index ' . $ix . ' differs: ' . $labelA . '=' . ut_show($fpA['indexes'][$ix])
                . ' ' . $labelB . '=' . ut_show($fpB['indexes'][$ix]);
        }
    }

    foreach (array_diff($fpA['fks'], $fpB['fks']) as $fk) {
        $diffs[] = 'FK ' . $fk . ' exists only in ' . $labelA;
    }
    foreach (array_diff($fpB['fks'], $fpA['fks']) as $fk) {
        $diffs[] = 'FK ' . $fk . ' exists only in ' . $labelB;
    }

    sort($diffs);
    sort($soft);
    return $diffs;
}

function live_strip_prefix(string $name, string $prefix): string
{
    if ($prefix !== '' && strncmp($name, $prefix, strlen($prefix)) === 0) {
        return substr($name, strlen($prefix));
    }
    return $name;
}

// =====================================================================
// 샌드박스 웹루트 + php -S
// =====================================================================

/** 폴더 재귀 복사. `glob()` 을 쓰지 않는다. */
function live_copy_dir(string $src, string $dst): void
{
    if (!is_dir($dst) && !@mkdir($dst, 0777, true) && !is_dir($dst)) {
        throw new RuntimeException('cannot create ' . $dst);
    }
    $ent = scandir($src);
    if ($ent === false) {
        throw new RuntimeException('cannot read ' . $src);
    }
    foreach ($ent as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        $s = $src . '/' . $e;
        $d = $dst . '/' . $e;
        if (is_dir($s)) {
            live_copy_dir($s, $d);
            continue;
        }
        if (!@copy($s, $d)) {
            throw new RuntimeException('cannot copy ' . $s);
        }
    }
}

function live_rm_rf(string $dir): void
{
    if (!is_dir($dir)) {
        @unlink($dir);
        return;
    }
    $ent = @scandir($dir);
    foreach ($ent === false ? array() : $ent as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        $p = $dir . '/' . $e;
        if (is_dir($p)) {
            live_rm_rf($p);
        } else {
            @unlink($p);
        }
    }
    @rmdir($dir);
}

/**
 * 샌드박스 설치 루트를 만든다. **저장소 트리는 읽기만 한다.**
 * @param array $configOverride config.php 에 넣을 상수 덮어쓰기
 * @return string 샌드박스 루트 경로
 */
function live_sandbox(array $configOverride = array()): string
{
    $L = &$GLOBALS['LIVE'];
    if ($L['sandbox'] !== null) {
        return $L['sandbox'];
    }
    $L['sandbox'] = live_sandbox_named('main', $configOverride);
    return $L['sandbox'];
}

/**
 * 메인 샌드박스가 가리키는 DB 를 **빈 스키마 상태로** 만든다.
 * 종주·폭발반경·동시성 케이스가 공유하는 준비 단계다.
 *
 * 한 번만 돈다 — 케이스마다 다시 만들면 앞 케이스가 만든 회원이 사라져
 * 케이스 사이에 순서 의존이 생긴다. 대신 각 케이스가 **자기 이름의 계정**을 쓴다.
 */
function live_ready_install(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $c = live_or_skip();
    $pdo = live_fresh_db($c['db_name']);
    live_apply_sql($pdo, h_root() . '/server/schema.sql');
    return $pdo;
}

/**
 * 레이트리밋 카운터와 계정 락아웃을 비운다.
 *
 * **왜 필요한가:** 레이트리밋은 **IP 단위**이고 테스트는 전부 같은 IP(127.0.0.1)에서
 * 온다. 앞 케이스가 로그인을 20번 실패시키면 뒤 케이스의 **회원가입조차** 429 가 된다.
 * 그러면 실패 원인이 "테스트가 서로를 밟았다" 인데 리포트에는 "포인트 차감 실패" 로
 * 보인다 — 대상이 아닌 이유로 빨개지는 테스트는 곧 무시된다.
 *
 * 카운터를 비우는 것은 **테스트 준비**이지 결함 은폐가 아니다. 레이트리밋 자체의
 * 동작은 `t_60_concurrency.php` 가 비우기 **전에** 확인한다.
 */
function live_reset_rate_limits(): void
{
    $c = live_config();
    if ($c === null) {
        return;
    }
    $pdo = live_pdo($c['db_name']);
    $pdo->exec('DELETE FROM app_ulogin_rate_limits');
    // 계정 락아웃 컬럼도 함께. 컬럼 이름이 바뀌면 조용히 넘어가지 않게 존재를 확인한다.
    $cols = $pdo->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'app_ulogin_users'
            AND COLUMN_NAME IN ('failed_login_count', 'locked_until')"
    )->fetchAll();
    $names = array();
    foreach ($cols as $r) {
        $names[] = (string) $r['COLUMN_NAME'];
    }
    if (in_array('failed_login_count', $names, true) && in_array('locked_until', $names, true)) {
        $pdo->exec('UPDATE app_ulogin_users SET failed_login_count = 0, locked_until = NULL');
    }
}

/**
 * 이름이 다른 샌드박스를 여러 개 만든다. 마이그레이션 두 경로 대조에 필요하다 —
 * 두 경로는 **서로 다른 DB** 를 쓰므로 `config.php` 도 두 벌이어야 한다.
 *
 * @param string $srcRoot 복사할 원본 설치 루트. 생략하면 이 저장소.
 *                        v1.3.0 같은 **과거 버전 폴더를 읽기 전용으로** 지목할 때 쓴다
 */
function live_sandbox_named(string $key, array $configOverride = array(), ?string $srcRoot = null): string
{
    $L = &$GLOBALS['LIVE'];
    if (!isset($L['boxes'])) {
        $L['boxes'] = array();
    }
    if (isset($L['boxes'][$key])) {
        return $L['boxes'][$key];
    }
    live_register_teardown();
    $c = live_config();
    $root = $srcRoot === null ? h_root() : $srcRoot;
    $box = h_norm(sys_get_temp_dir() . '/ulogin_live_' . $key . '_' . bin2hex(random_bytes(5)));
    foreach (array('server', 'public', 'examples') as $d) {
        if (is_dir($root . '/' . $d)) {
            live_copy_dir($root . '/' . $d, $box . '/' . $d);
        }
    }
    @mkdir($box . '/server/storage', 0777, true);
    // config.php 는 **샌드박스에만** 쓴다. 저장소 트리에는 아무것도 만들지 않는다.
    file_put_contents($box . '/server/config.php', live_config_php($c, $configOverride));
    $L['boxes'][$key] = $box;
    return $box;
}

/**
 * 샌드박스 안에서 `server/migrate.php` 를 CLI 로 돌린다.
 * @return array [exitCode, output]
 */
function live_migrate(string $box, string $cmd): array
{
    $code = 0;
    $out = h_run(h_q(h_php_bin()) . ' ' . h_q($box . '/server/migrate.php') . ' ' . $cmd, $code);
    return array($code, $out);
}

/** 샌드박스용 `config.php` 본문. `config.sample.php` 와 같은 상수 집합이다. */
function live_config_php(array $c, array $override = array()): string
{
    $v = array(
        'DB_HOST'    => $c['db_host'] . ($c['db_port'] !== 3306 ? ':' . $c['db_port'] : ''),
        'DB_NAME'    => $c['db_name'],
        'DB_USER'    => $c['db_user'],
        'DB_PASS'    => $c['db_pass'],
        'DB_CHARSET' => 'utf8mb4',
        'DB_PREFIX'  => 'app_ulogin_',
        'SECRET_ENC_KEY' => str_repeat('0123456789abcdef', 4),
        'APP_KEY'        => str_repeat('fedcba9876543210', 4),
        'FORCE_HTTPS'    => false,
        'TRUST_PROXY'    => false,
        'COOKIE_NAME'      => 'ULOGIN_SID',
        'COOKIE_CSRF_NAME' => 'ULOGIN_CSRF',
        'COOKIE_SECURE'    => false,   // php -S 는 평문이다. true 면 D-75 가 전부 500 을 만든다
        'COOKIE_SAMESITE'  => 'Lax',
        'COOKIE_DOMAIN'    => '',
        'COOKIE_PATH'      => '/',
        'MAIL_DRIVER'      => 'log',
        'MAIL_FROM'        => 'no-reply@test.invalid',
        'MAIL_FROM_NAME'   => 'ulogin-test',
        'BASE_URL'         => 'http://127.0.0.1:' . $c['http_port'],
        'SESSION_TTL'          => 3600,
        'REFRESH_TTL'          => 1209600,
        'REMEMBER_TTL'         => 2592000,
        'AUTH_CODE_TTL'        => 60,
        'PW_RESET_TTL'         => 1800,
        'EMAIL_VERIFY_TTL'     => 86400,
        'LOCKOUT_THRESHOLD'    => 5,
        'LOCKOUT_MINUTES'      => 15,
        'PASSWORD_MIN_LENGTH'  => 8,
        'SIGNUP_BONUS_POINTS'  => 100,
        'ALLOW_REGISTER'       => true,
        'REQUIRE_EMAIL_VERIFY' => false,
        'MAINTENANCE_MODE'     => false,
        'SITE_NAME'            => 'ulogin-test',
        'SECRET_ACCESS_LOG'    => true,
        'DEBUG'                => false,
    );
    foreach ($override as $k => $vv) {
        $v[$k] = $vv;
    }
    $s = "<?php\n// generated by tests/lib/live.php - sandbox only. never uploaded.\n";
    foreach ($v as $k => $vv) {
        $s .= "define(" . var_export($k, true) . ", " . var_export($vv, true) . ");\n";
    }
    $s .= "define('TRUSTED_PROXIES', array());\n";
    $tables = array(
        'T_USERS' => 'users', 'T_SERVICES' => 'services', 'T_USER_SERVICES' => 'user_services',
        'T_SECRETS' => 'service_secrets', 'T_SESSIONS' => 'sessions', 'T_AUTH_CODES' => 'auth_codes',
        'T_PW_RESETS' => 'password_resets', 'T_EMAIL_VERIFY' => 'email_verifications',
        'T_LOGIN_LOGS' => 'login_logs', 'T_POINT_LOGS' => 'point_logs', 'T_ADMIN_LOGS' => 'admin_logs',
        'T_RATE_LIMITS' => 'rate_limits', 'T_SETTINGS' => 'settings', 'T_TERMS' => 'terms',
        'T_CONSENTS' => 'user_consents', 'T_MIGRATIONS' => 'schema_migrations',
        'T_SECRET_ACCESS' => 'secret_access_logs',
    );
    foreach ($tables as $k => $suffix) {
        $s .= "define(" . var_export($k, true) . ", DB_PREFIX . " . var_export($suffix, true) . ");\n";
    }
    return $s;
}

/**
 * 내장 웹서버를 띄운다. 준비될 때까지 기다린다.
 * @return string base URL
 */
function live_server(): string
{
    $L = &$GLOBALS['LIVE'];
    if ($L['baseUrl'] !== null) {
        return $L['baseUrl'];
    }
    $c = live_config();
    $box = live_sandbox();
    $port = (int) $c['http_port'];
    $cmd = h_q(h_php_bin()) . ' -S 127.0.0.1:' . $port . ' -t ' . h_q($box);
    /*
     * **stdout·stderr 를 파이프가 아니라 파일로 보낸다.**
     *
     * 파이프로 받으면 `php -S` 가 요청마다 한 줄씩 로그를 쓰고, 아무도 그 파이프를
     * 읽지 않으면 OS 버퍼(Windows 기준 수 KB)가 차는 순간 **서버가 write 에서 블록된다.**
     * 71 케이스쯤에서 정확히 그 일이 났고, 러너는 응답을 기다리며 영원히 멈췄다.
     * 증상이 "테스트가 느리다" 로 보여서 원인을 오해하기 쉬운 종류다.
     * 파일로 보내면 버퍼 한계가 없고, 실패했을 때 서버 로그를 읽을 수도 있다.
     */
    $logFile = $box . '/_server.log';
    $desc = array(
        0 => array('pipe', 'r'),
        1 => array('file', $logFile, 'a'),
        2 => array('file', $logFile, 'a'),
    );
    $pipes = array();
    $proc = @proc_open($cmd, $desc, $pipes, $box);
    if (!is_resource($proc)) {
        skip('cannot start the PHP built-in server on port ' . $port);
    }
    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);   // stdin 을 닫는다. 서버는 읽지 않는다
    }
    $L['proc'] = $proc;
    $L['procPipes'] = array();
    $L['serverLog'] = $logFile;

    // 준비 대기. 최대 10초.
    $base = 'http://127.0.0.1:' . $port;
    $ready = false;
    for ($i = 0; $i < 100; $i++) {
        $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($s !== false) {
            fclose($s);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    if (!$ready) {
        skip('the PHP built-in server did not come up on port ' . $port . ' within 10s (is the port taken?)');
    }

    /*
     * **포트가 열렸다고 우리 서버라는 뜻은 아니다.**
     *
     * 앞 실행이 남긴 `php -S` 가 같은 포트를 붙들고 있으면 새 서버는 bind 에
     * 실패하는데, 위 `fsockopen` 은 **좀비 서버에 붙어서 성공한다.** 그러면
     * 이후 모든 요청이 **다른 문서 루트**(다른 코드·다른 config)로 가고,
     * 테스트는 통과하거나 실패하되 어느 쪽도 이번 코드에 대한 판정이 아니다.
     * 이 저장소가 반복해 맞은 "조용히 틀린 결과" 의 전형이라 센티널로 못박는다.
     */
    $token = bin2hex(random_bytes(8));
    file_put_contents($box . '/_sentinel.txt', $token);
    $ctx = stream_context_create(array('http' => array(
        'method' => 'GET', 'header' => "Connection: close\r\n",
        'ignore_errors' => true, 'timeout' => 5, 'protocol_version' => 1.1,
    )));
    $got = @file_get_contents($base . '/_sentinel.txt', false, $ctx);
    if (trim((string) $got) !== $token) {
        skip('port ' . $port . ' is answering, but not from this run\'s sandbox'
            . ' - another php -S (probably left over from a previous run) still holds it.'
            . ' kill it, or set ULOGIN_TEST_HTTP_PORT to a free port.');
    }

    $L['baseUrl'] = $base;
    return $base;
}

/**
 * HTTP 요청. **확장 모듈을 쓰지 않는다** (curl 없이 스트림 래퍼로).
 *
 * @param array $opt  headers(array) · body(string|array) · cookies(array by ref 는 아니다)
 * @return array {status, headers(array of raw lines), body, cookies(name=>value), json(array|null)}
 */
function live_req(string $method, string $path, array $opt = array()): array
{
    $base = live_server();
    $url = $base . $path;
    $headers = isset($opt['headers']) ? $opt['headers'] : array();
    $body = null;
    if (isset($opt['json'])) {
        $body = json_encode($opt['json'], JSON_UNESCAPED_UNICODE);
        $headers['Content-Type'] = 'application/json';
    } elseif (isset($opt['body'])) {
        $body = is_array($opt['body']) ? http_build_query($opt['body']) : (string) $opt['body'];
        if (!isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        }
    }
    if (!empty($opt['cookies'])) {
        $pairs = array();
        foreach ($opt['cookies'] as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }
        $headers['Cookie'] = implode('; ', $pairs);
    }
    // `php -S` 는 단일 스레드다. keep-alive 로 연결을 붙들면 다음 요청이 대기한다.
    if (!isset($headers['Connection'])) {
        $headers['Connection'] = 'close';
    }
    $hdrLines = '';
    foreach ($headers as $k => $v) {
        $hdrLines .= $k . ': ' . $v . "\r\n";
    }
    $ctx = stream_context_create(array('http' => array(
        'method'           => $method,
        'header'           => $hdrLines,
        'content'          => $body === null ? '' : $body,
        'ignore_errors'    => true,    // 4xx·5xx 도 본문을 받는다
        'follow_location'  => 0,       // 리다이렉트를 따라가지 않는다. 그 자체가 검사 대상이다
        'timeout'          => 15,
        'protocol_version' => 1.1,
    )));
    $raw = @file_get_contents($url, false, $ctx);
    $lines = isset($http_response_header) ? $http_response_header : array();
    $status = 0;
    $cookies = array();
    foreach ($lines as $l) {
        if (preg_match('~^HTTP/\d(?:\.\d)? (\d{3})~', $l, $m)) {
            $status = (int) $m[1];   // 마지막 상태줄이 유효하다
            continue;
        }
        if (stripos($l, 'Set-Cookie:') === 0) {
            $cv = trim(substr($l, 11));
            $eq = strpos($cv, '=');
            $sc = strpos($cv, ';');
            if ($eq !== false) {
                $nm = substr($cv, 0, $eq);
                $val = $sc === false ? substr($cv, $eq + 1) : substr($cv, $eq + 1, $sc - $eq - 1);
                $cookies[$nm] = $val;
            }
        }
    }
    $json = null;
    if ($raw !== false && $raw !== '') {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            $json = $j;
        }
    }
    return array(
        'status'  => $status,
        'headers' => $lines,
        'body'    => $raw === false ? '' : $raw,
        'cookies' => $cookies,
        'json'    => $json,
    );
}

/** 응답에서 `Set-Cookie` 원문 줄만. 속성 검사용. */
function live_set_cookie_lines(array $res): array
{
    $out = array();
    foreach ($res['headers'] as $l) {
        if (stripos($l, 'Set-Cookie:') === 0) {
            $out[] = trim(substr($l, 11));
        }
    }
    return $out;
}

/**
 * N 개의 요청을 **동시에** 보낸다. 자식 PHP 프로세스로 병렬화한다.
 *
 * `curl_multi` 를 쓰지 않는 이유: 확장 의존을 만들지 않는다. 이 저장소의
 * 규약은 "PHP CLI 하나뿐" 이다.
 *
 * **왜 동시성이 필요한가:** v1.4.0 에서 동시성 결함이 **두 번** 났고
 * 순차 시험으로는 둘 다 안 잡혔다. 레이트리밋과 멱등키는 순차로는
 * 항상 통과하고 동시에만 깨진다.
 *
 * @return array 각 원소 {status, body}
 */
function live_parallel(int $n, string $method, string $path, array $opt = array()): array
{
    $base = live_server();
    $tmp = sys_get_temp_dir() . '/ulogin_par_' . bin2hex(random_bytes(6));
    @mkdir($tmp, 0777, true);
    $script = $tmp . '/worker.php';
    $payload = array(
        'url'     => $base . $path,
        'method'  => $method,
        'headers' => isset($opt['headers']) ? $opt['headers'] : array(),
        'cookies' => isset($opt['cookies']) ? $opt['cookies'] : array(),
        'json'    => isset($opt['json']) ? $opt['json'] : null,
    );
    file_put_contents($tmp . '/req.json', json_encode($payload));
    file_put_contents($script, live_worker_source());

    $procs = array();
    for ($i = 0; $i < $n; $i++) {
        $cmd = h_q(h_php_bin()) . ' ' . h_q($script) . ' ' . h_q($tmp . '/req.json') . ' ' . h_q($tmp . '/out_' . $i . '.json');
        $desc = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
        $pp = array();
        $procs[$i] = array(@proc_open($cmd, $desc, $pp, $tmp), $pp);
    }
    $out = array();
    foreach ($procs as $i => $pr) {
        if (!is_resource($pr[0])) {
            continue;
        }
        foreach ($pr[1] as $p) {
            if (is_resource($p)) {
                @fclose($p);
            }
        }
        proc_close($pr[0]);
        $f = $tmp . '/out_' . $i . '.json';
        $j = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
        $out[] = is_array($j) ? $j : array('status' => 0, 'body' => '');
    }
    live_rm_rf($tmp);
    return $out;
}

/** 병렬 워커의 소스. 별도 파일로 두지 않는다 — 부산물 하나를 줄인다. */
function live_worker_source(): string
{
    return <<<'WORKER'
<?php
// tests/lib/live.php 가 생성한 임시 워커. 동시 요청 한 건을 보낸다.
$req = json_decode(file_get_contents($argv[1]), true);
$h = '';
foreach ($req['headers'] as $k => $v) { $h .= $k . ': ' . $v . "\r\n"; }
if (!empty($req['cookies'])) {
    $p = array();
    foreach ($req['cookies'] as $k => $v) { $p[] = $k . '=' . $v; }
    $h .= 'Cookie: ' . implode('; ', $p) . "\r\n";
}
$body = '';
if ($req['json'] !== null) { $body = json_encode($req['json']); $h .= "Content-Type: application/json\r\n"; }
$ctx = stream_context_create(array('http' => array(
    'method' => $req['method'], 'header' => $h, 'content' => $body,
    'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 15, 'protocol_version' => 1.1,
)));
$raw = @file_get_contents($req['url'], false, $ctx);
$st = 0;
foreach (isset($http_response_header) ? $http_response_header : array() as $l) {
    if (preg_match('~^HTTP/\d(?:\.\d)? (\d{3})~', $l, $m)) { $st = (int) $m[1]; }
}
file_put_contents($argv[2], json_encode(array('status' => $st, 'body' => $raw === false ? '' : $raw)));
WORKER;
}

// =====================================================================
// 정리 — 실패해도 돈다
// =====================================================================

function live_teardown(): void
{
    $L = &$GLOBALS['LIVE'];

    if (is_resource($L['proc'])) {
        foreach ($L['procPipes'] as $p) {
            if (is_resource($p)) {
                @fclose($p);
            }
        }
        /*
         * **Windows 에서 `proc_terminate()` 만으로는 서버가 안 죽는다.**
         *
         * `proc_open()` 은 명령을 `cmd.exe /c ...` 로 감싸므로 `proc_terminate()` 가
         * 죽이는 것은 그 래퍼뿐이고 `php -S` 는 살아남는다. 실측한 결과:
         *   · 포트 8199 가 계속 잡혀 있어 **다음 실행이 남의 서버에 붙는다**
         *     (readiness 검사는 통과한다 — 그래서 조용히 틀린다)
         *   · 서버가 로그 파일을 열고 있어 샌드박스 삭제가 실패하고
         *     임시 폴더가 실행마다 하나씩 쌓인다
         * `taskkill /T` 로 **프로세스 트리째** 죽인다.
         */
        $st = @proc_get_status($L['proc']);
        $pid = (is_array($st) && isset($st['pid'])) ? (int) $st['pid'] : 0;
        if (DIRECTORY_SEPARATOR === '\\' && $pid > 0) {
            $ignore = array();
            $rc = 0;
            @exec('taskkill /F /T /PID ' . $pid . ' 2>&1', $ignore, $rc);
        }
        @proc_terminate($L['proc'], 9);
        @proc_close($L['proc']);
        $L['proc'] = null;
        // 파일 핸들이 실제로 풀릴 때까지 잠깐 기다린다. 안 기다리면 rm 이 실패한다.
        for ($i = 0; $i < 20; $i++) {
            $s = @fsockopen('127.0.0.1', (int) ($L['cfg']['http_port'] ?? 0), $e1, $e2, 0.1);
            if ($s === false) {
                break;
            }
            fclose($s);
            usleep(100000);
        }
    }

    if ($L['dbs'] !== array() && $L['cfg'] !== null && $L['cfg'] !== false) {
        try {
            $pdo = new PDO(
                'mysql:host=' . $L['cfg']['db_host'] . ';port=' . $L['cfg']['db_port'] . ';charset=utf8mb4',
                $L['cfg']['db_user'],
                $L['cfg']['db_pass'],
                array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
            );
            foreach (array_keys($L['dbs']) as $db) {
                if (strpos($db, 'ulogin_test') === 0) {
                    $pdo->exec('DROP DATABASE IF EXISTS `' . $db . '`');
                }
            }
        } catch (Throwable $e) {
            fwrite(STDERR, "[tests] warning: could not drop the test database(s): " . $e->getMessage() . "\n");
            fwrite(STDERR, "[tests]          left behind: " . implode(', ', array_keys($L['dbs'])) . "\n");
        }
        $L['dbs'] = array();
    }

    if (!empty($L['boxes'])) {
        $stuck = array();
        foreach ($L['boxes'] as $box) {
            live_rm_rf($box);
            // **못 지웠으면 알린다.** 조용히 남기면 실행마다 임시 폴더가 하나씩
            // 쌓이고, 각 폴더에는 DB 비밀번호가 든 config.php 가 들어 있다.
            if (is_dir($box)) {
                // 핸들이 늦게 풀릴 수 있다. 한 번 더 시도한다.
                usleep(300000);
                live_rm_rf($box);
            }
            if (is_dir($box)) {
                $stuck[] = $box;
            }
        }
        if ($stuck !== array()) {
            fwrite(STDERR, "[tests] warning: could not delete " . count($stuck) . " sandbox folder(s).\n");
            fwrite(STDERR, "[tests]          each one holds a generated server/config.php with the test DB password.\n");
            foreach ($stuck as $s) {
                fwrite(STDERR, "[tests]          " . $s . "\n");
            }
        }
        $L['boxes'] = array();
    }
    $L['sandbox'] = null;
}
