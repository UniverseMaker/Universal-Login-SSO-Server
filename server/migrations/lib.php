<?php
/**
 * server/migrations/lib.php — 마이그레이션 공용 함수 (v1.4.0 신규)
 * 담당 계약: 리더 판정 D-151(스키마 마이그레이션 기반)
 *
 * =====================================================================
 * **이 파일은 core/ 를 로드하지 않는다. 로드해서도 안 된다.**
 *
 * 이유는 install.php 가 core/ 를 로드하지 않는 것과 같다. 마이그레이션 러너는
 * **스키마가 코드보다 낡은 상태에서** 도는 코드다. core/ 의 SELECT 는 최신 스키마를
 * 전제하므로 그 상태에서 부르면 러너 자신이 죽는다 — 고치러 온 코드가 고칠 대상 때문에
 * 죽는 구조가 된다.
 *
 * 그래서 여기에는 PDO 와 순수 문자열 처리만 있다. declare(strict_types=1) 도 쓰지 않는다
 * (install.php 와 같은 이유 — 타입 선언 없이 방어적으로 캐스팅한다).
 *
 * **PHP 7.2 호환.** 화살표 함수·`??=`·`str_contains`·타입 프로퍼티 전부 금지.
 * 금지 문법 전체 목록은 core/bootstrap.php 상단에 있다.
 * =====================================================================
 *
 * 읽는 쪽 두 곳:
 *   - server/migrate.php  — 기존 설치본 업그레이드
 *   - server/install.php  — 신규 설치 후 baseline 기록
 * 둘 다 require 전에 `define('ULOGIN_MIGRATE_CTX', 1)` 를 한다.
 */

// 웹에서 직접 열리는 것을 막는다. migrations/.htaccess 가 이미 막지만
// .htaccess 가 무시되는 환경(nginx / AllowOverride None)이 있다.
if (!defined('ULOGIN_MIGRATE_CTX')) {
    header_remove('X-Powered-By');
    http_response_code(404);
    exit;
}

// ---------------------------------------------------------------------
// v1.8.0 — SQLite 기본 저장소. 이 파일이 core/ 에서 들이는 것은 **둘뿐**이다.
//   core/dbdriver.php — 드라이버 판정 · UPSERT 꼬리절 (쿼리 0줄)
//   core/ddl.php      — schema.sql → SQLite DDL 번역기 (문자열 처리만)
// 둘 다 앱 테이블을 읽지 않으므로 위 「core/ 를 로드하지 않는다」의 근거
// (core 의 SELECT 는 최신 스키마를 전제한다)에 걸리지 않는다 — dbport.php 와 같은 자리다.
// **방언 분기는 넘겨받은 PDO 자신에게 묻는다**(`ulogin_pdo_driver()`). 상수를 보지 않는다 —
// install.php 는 config.php 가 생기기 전에 이 파일을 부른다.
// ---------------------------------------------------------------------
require_once __DIR__ . '/../core/dbdriver.php';
require_once __DIR__ . '/../core/ddl.php';

/** 이 연결이 SQLite 인가. */
function mig_is_sqlite(PDO $pdo)
{
    return ulogin_pdo_driver($pdo) === 'sqlite';
}

/**
 * 빈 SQLite DB 에 schema.sql 전체를 번역해 만든다 — 신규 설치와 같은 최종 스키마.
 * 시험 하네스(경합 시험 등)와 도구가 쓴다. install.php 는 표별 결과를 화면에 그려야 해서
 * 자기 루프를 갖지만 **번역은 같은 함수(ddl_sqlite_create)** 를 지난다.
 *
 * 부르기 전에 필요한 것: `define('ULOGIN_MIGRATE_CTX', 1)` 하나 (DB_PREFIX 등 config 상수 불필요 —
 * prefix 는 인자로 받는다). `$pdo` 는 호출자가 연다: 앱과 같은 PRAGMA 를 원하면
 * `ulogin_pdo_open_sqlite($abs, array('create' => true, 'quick_check' => false))`.
 *
 * @param bool $baseline true 면 schema_migrations 에 migrations/ 전 파일을 baseline 으로 기록(설치와 동일)
 * @return array ['tables' => n, 'indexes' => n, 'baseline' => n]
 */
function mig_sqlite_create_schema(PDO $pdo, $schemaFile, $prefix, $baseline = true)
{
    if (!mig_is_sqlite($pdo)) {
        throw new RuntimeException('mig_sqlite_create_schema needs a sqlite connection');
    }
    $sql = @file_get_contents($schemaFile);
    if ($sql === false || trim((string) $sql) === '') {
        throw new RuntimeException('schema.sql 을 읽을 수 없습니다: ' . $schemaFile);
    }
    $blocks = ddl_extract_creates(mig_apply_prefix((string) $sql, $prefix));
    if ($blocks === array()) {
        // 0건을 「만들 것이 없다」로 읽지 않는다(규약 7).
        throw new RuntimeException('schema.sql 에서 CREATE TABLE 을 하나도 찾지 못했습니다');
    }
    $nt = 0;
    $ni = 0;
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        foreach ($blocks as $b) {
            $r = ddl_sqlite_create($b);
            $pdo->exec($r['table']);
            $nt++;
            foreach ($r['indexes'] as $ix) {
                $pdo->exec($ix);
                $ni++;
            }
        }
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ROLLBACK');
        } catch (Throwable $e2) {
            // 원 예외가 본체다
        }
        throw $e;
    }
    $nb = 0;
    if ($baseline) {
        $mtable = mig_ensure_table($pdo, $schemaFile, $prefix);
        $nb     = mig_stamp_baseline($pdo, mig_list(__DIR__), $mtable, 'install');
    }
    return array('tables' => $nt, 'indexes' => $ni, 'baseline' => $nb);
}

/** 마이그레이션 파일명 규약. 앞 4자리가 버전이고 그 순서로 적용한다. */
define('MIG_FILE_RE', '/^([0-9]{4})_([A-Za-z0-9._-]+)\.sql$/');

/** 시간 버킷·감사와 무관. 단순 UTC 문자열. */
function mig_now()
{
    return gmdate('Y-m-d H:i:s');
}

/**
 * `app_ulogin_` 리터럴을 실제 prefix 로 바꾼다.
 * 치환 **대상**은 코드 리터럴이고 치환 **값**은 install.php 가 정규식으로 검증한
 * prefix 다 — schema.sql 을 다루는 install.php 의 규약과 같다(M-12-11).
 */
function mig_apply_prefix($sql, $prefix)
{
    return str_replace('app_ulogin_', (string) $prefix, (string) $sql);
}

/**
 * 디렉토리의 마이그레이션 파일 목록. 버전 오름차순.
 *
 * **glob() 을 쓰지 않는다.** 이 프로젝트의 경로에는 대괄호가 들어 있고
 * PHP 의 glob() 은 그것을 문자 클래스로 해석해 **오류 없이 0건**을 돌려준다.
 * 조용히 실패하는 종류라 "0건"을 "대상 없음"으로 읽게 된다. scandir() + 수동 필터.
 *
 * @return array 각 원소 [version, filename, path, name, checksum, raw]
 */
function mig_list($dir)
{
    $out   = [];
    $names = @scandir($dir);
    if ($names === false) {
        return $out;
    }
    foreach ($names as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $m = [];
        if (preg_match(MIG_FILE_RE, $f, $m) !== 1) {
            continue;
        }
        $path = $dir . '/' . $f;
        if (!is_file($path)) {
            continue;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            continue;
        }
        $out[] = [
            'version'  => $m[1],
            'filename' => $f,
            'path'     => $path,
            'name'     => mig_header_name($raw, $f),
            'checksum' => hash('sha256', $raw),
            'raw'      => $raw,
        ];
    }
    usort($out, 'mig_cmp_version');
    return $out;
}

function mig_cmp_version($a, $b)
{
    return strcmp((string) $a['version'], (string) $b['version']);
}

/**
 * mig_status() 의 state 중 **사람이 손을 대야 하는** 것인가.
 *
 * `checksum-mismatch` 와 `orphan` 을 여기 넣은 이유: 적용은 끝났지만 파일과 기록이
 * 어긋난 상태이고, apply 는 그것을 만나면 중단한다. 상태 화면의 "0건" 이
 * "괜찮다" 로 읽히면 안 된다.
 */
function mig_needs_attention($state)
{
    return in_array((string) $state, ['pending', 'partial', 'failed', 'checksum-mismatch', 'orphan'], true);
}

/** 파일 상단의 `-- name: …` 한 줄. 없으면 파일명. */
function mig_header_name($raw, $fallback)
{
    $m = [];
    if (preg_match('/^--\s*name:\s*(.+)$/m', (string) $raw, $m) === 1) {
        return trim($m[1]);
    }
    return (string) $fallback;
}

/**
 * 마이그레이션 SQL 을 문장 단위로 자르고, 각 문장 앞의 `-- @skip-if:` 가드를 붙인다.
 *
 * 문자열('..')·식별자(`..`, "..")·주석(-- , #, C 스타일) 안의 세미콜론을
 * 문장 구분자로 오인하지 않는다 — install.php 의 ins_split_sql() 과 같은 성격이다.
 *
 * 가드 문법 (문장 **바로 앞**의 주석 줄에 쓴다. 한 문장에 여러 개 가능):
 *   -- @skip-if: table-exists  app_ulogin_foo
 *   -- @skip-if: column-exists app_ulogin_foo bar
 *   -- @skip-if: index-exists  app_ulogin_foo idx_foo_bar
 *   -- @skip-if: never
 *
 * @return array 각 원소 ['sql'=>…, 'guards'=>[[종류, 인자…], …], 'line'=>줄번호]
 */
function mig_split($sql)
{
    $sql    = (string) $sql;
    $stmts  = [];
    $buf    = '';
    $guards = [];
    $len    = strlen($sql);
    $i      = 0;
    $line   = 1;
    $startLine = 1;
    $inS = false; $inD = false; $inB = false;

    while ($i < $len) {
        $c = $sql[$i];
        if ($c === "\n") {
            $line++;
        }

        if (!$inS && !$inD && !$inB) {
            // -- 주석 (MySQL 은 '--' 뒤에 공백/개행을 요구한다)
            if ($c === '-' && $i + 1 < $len && $sql[$i + 1] === '-'
                && ($i + 2 >= $len || $sql[$i + 2] === ' ' || $sql[$i + 2] === "\t"
                    || $sql[$i + 2] === "\n" || $sql[$i + 2] === "\r")) {
                $j = $i;
                while ($j < $len && $sql[$j] !== "\n") {
                    $j++;
                }
                $comment = substr($sql, $i, $j - $i);
                // 가드는 '아직 문장이 시작되지 않은' 자리에서만 유효하다.
                if (trim($buf) === '') {
                    $g = mig_parse_guard($comment);
                    if ($g !== null) {
                        $guards[] = $g;
                    }
                }
                $i = $j;
                continue;
            }
            if ($c === '#') {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            if ($c === '/' && $i + 1 < $len && $sql[$i + 1] === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i   = ($end === false) ? $len : $end + 2;
                continue;
            }
            if ($c === ';') {
                if (trim($buf) !== '') {
                    $stmts[] = ['sql' => trim($buf), 'guards' => $guards, 'line' => $startLine];
                }
                $buf    = '';
                $guards = [];
                $i++;
                $startLine = $line;
                continue;
            }
        }

        if ($c === "'" && !$inD && !$inB) {
            // 이스케이프된 따옴표 처리
            if ($inS && $i + 1 < $len && $sql[$i + 1] === "'") {
                $buf .= "''";
                $i += 2;
                continue;
            }
            $inS = !$inS;
        } elseif ($c === '"' && !$inS && !$inB) {
            $inD = !$inD;
        } elseif ($c === '`' && !$inS && !$inD) {
            $inB = !$inB;
        } elseif ($c === '\\' && ($inS || $inD) && $i + 1 < $len) {
            $buf .= $sql[$i] . $sql[$i + 1];
            $i += 2;
            continue;
        }

        if (trim($buf) === '' && trim($c) !== '') {
            $startLine = $line;
        }
        $buf .= $c;
        $i++;
    }
    if (trim($buf) !== '') {
        $stmts[] = ['sql' => trim($buf), 'guards' => $guards, 'line' => $startLine];
    }
    return $stmts;
}

/**
 * `-- @skip-if: …` 한 줄을 파싱한다. 가드가 아니면 null.
 *
 * **`^--\s*@skip-if:` 로 줄머리에 고정한다.** 고정하지 않으면 설명문 안에서
 * `@skip-if:` 를 인용한 주석까지 가드로 읽는다 — 이 파일을 만들 때 실제로 그렇게 됐고,
 * `-- @skip-if:` 를 백틱으로 감싼 설명 한 줄이 가드 `` ` `` 로 파싱됐다.
 * (mig_lint 가 '알 수 없는 가드' 로 잡아 적용 전에 멈췄다 — fail-closed 가 동작했다)
 */
function mig_parse_guard($commentLine)
{
    $m = [];
    if (preg_match('/^--\s*@skip-if:\s*(.+)$/', trim((string) $commentLine), $m) !== 1) {
        return null;
    }
    $parts = preg_split('/\s+/', trim($m[1]));
    if (!is_array($parts) || $parts === []) {
        return null;
    }
    return $parts;
}

/**
 * DDL 문장인가. DDL 은 되돌릴 수 없고 트랜잭션도 걸리지 않으므로
 * **가드가 반드시 있어야** 부분 적용 후 재개가 성립한다.
 */
function mig_is_ddl($stmt)
{
    return preg_match('/^\s*(CREATE|ALTER|DROP|RENAME|TRUNCATE)\b/i', (string) $stmt) === 1;
}

/**
 * 파일 자체의 결함을 먼저 잡는다 — 적용을 시작하기 전에.
 * 가드 없는 DDL 이 하나라도 있으면 그 파일은 **재개할 수 없는 파일**이다.
 * @return array 사람이 읽는 오류 문자열 목록. 비어 있으면 정상
 */
function mig_lint($stmts)
{
    $errs = [];
    foreach ($stmts as $n => $s) {
        if (mig_is_ddl($s['sql']) && $s['guards'] === []) {
            $errs[] = '문장 ' . ($n + 1) . ' (파일 ' . $s['line'] . '행) 이 DDL 인데 `-- @skip-if:` 가드가 없다. '
                . '가드가 없으면 부분 적용 후 재개할 수 없다.';
        }
        foreach ($s['guards'] as $g) {
            $kind = isset($g[0]) ? $g[0] : '';
            if (!in_array($kind, ['table-exists', 'column-exists', 'index-exists', 'never'], true)) {
                $errs[] = '문장 ' . ($n + 1) . ' 의 알 수 없는 가드: ' . $kind;
                continue;
            }
            $need = ($kind === 'never') ? 1 : (($kind === 'column-exists' || $kind === 'index-exists') ? 3 : 2);
            if (count($g) < $need) {
                $errs[] = '문장 ' . ($n + 1) . ' 의 가드 인자가 모자란다: ' . implode(' ', $g);
            }
        }
    }
    return $errs;
}

/**
 * 이 문장을 **건너뛰어야** 하는가 (= 이미 적용돼 있는가).
 * 가드가 여럿이면 하나라도 만족하면 건너뛴다.
 */
function mig_should_skip(PDO $pdo, array $guards, $prefix)
{
    foreach ($guards as $g) {
        $kind = isset($g[0]) ? $g[0] : '';
        if ($kind === 'never') {
            continue;
        }
        $t = isset($g[1]) ? mig_apply_prefix($g[1], $prefix) : '';
        if ($kind === 'table-exists' && mig_table_exists($pdo, $t)) {
            return true;
        }
        if ($kind === 'column-exists' && mig_column_exists($pdo, $t, isset($g[2]) ? $g[2] : '')) {
            return true;
        }
        if ($kind === 'index-exists' && mig_index_exists($pdo, $t, isset($g[2]) ? $g[2] : '')) {
            return true;
        }
    }
    return false;
}

// information_schema 는 SQLite 에 없다(A19). sqlite_master · PRAGMA table_info 로 같은 질문에 답한다.
function mig_table_exists(PDO $pdo, $table)
{
    if (mig_is_sqlite($pdo)) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
        $st->execute([(string) $table]);
        return ((int) $st->fetchColumn()) > 0;
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES'
        . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $st->execute([(string) $table]);
    return ((int) $st->fetchColumn()) > 0;
}

function mig_column_exists(PDO $pdo, $table, $column)
{
    if (mig_is_sqlite($pdo)) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM pragma_table_info(?) WHERE name = ?');
        $st->execute([(string) $table, (string) $column]);
        return ((int) $st->fetchColumn()) > 0;
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS'
        . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([(string) $table, (string) $column]);
    return ((int) $st->fetchColumn()) > 0;
}

function mig_index_exists(PDO $pdo, $table, $index)
{
    if (mig_is_sqlite($pdo)) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name = ?");
        $st->execute([(string) $table, (string) $index]);
        return ((int) $st->fetchColumn()) > 0;
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS'
        . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $st->execute([(string) $table, (string) $index]);
    return ((int) $st->fetchColumn()) > 0;
}

/**
 * 마이그레이션 문장 하나를 실행한다. **prefix 치환·FROM SCHEMA 확장이 끝난** MySQL 문장을 받는다.
 * MySQL 은 그대로 exec. SQLite 는 core/ddl.php 로 번역해 exec (문장 1개 → 여러 개일 수 있다).
 *
 * `MODIFY COLUMN` (지금 0003·0004 의 COMMENT 문구 변경 2건) — SQLite 에 컬럼 주석이 없어
 * 적용할 내용이 없다. 다만 **살아 있는 컬럼이 그 정의와 같을 때만** no-op 이다 —
 * 타입·길이·NULL·DEFAULT 중 하나라도 다르면 예외로 멈춘다(설계 C-3). 조용히 건너뛰면
 * 「MySQL 은 바뀌었는데 SQLite 는 안 바뀐」 드리프트가 생긴다.
 * @return string 'exec' | 'noop'
 */
function mig_exec_statement(PDO $pdo, $sql)
{
    if (!mig_is_sqlite($pdo)) {
        $pdo->exec((string) $sql);
        return 'exec';
    }
    $t = ddl_sqlite_statement((string) $sql);
    if ($t['kind'] === 'modify_column') {
        $why = mig_sqlite_column_mismatch($pdo, $t['table'], $t['column']);
        if ($why !== null) {
            throw new RuntimeException('sqlite: MODIFY COLUMN ' . $t['table'] . '.' . $t['column']['name']
                . ' changes the definition (' . $why . ') - write an explicit copy-table migration');
        }
        error_log('[ulogin-migrate] sqlite: MODIFY COLUMN ' . $t['table'] . '.' . $t['column']['name']
            . ' only changes metadata SQLite does not store (COMMENT) - skipped as no-op');
        return 'noop';
    }
    foreach ($t['sql'] as $s) {
        $pdo->exec($s);
    }
    return 'exec';
}

/**
 * 살아 있는 SQLite 컬럼이 파싱된 정의(core/ddl.php ddl_parse_column 결과)와 같은가.
 * @return string|null 다른 점(사람용). 같으면 null
 */
function mig_sqlite_column_mismatch(PDO $pdo, $table, array $col)
{
    $st = $pdo->prepare('SELECT type, "notnull", dflt_value FROM pragma_table_info(?) WHERE name = ?');
    $st->execute([(string) $table, (string) $col['name']]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r === false) {
        return 'column missing';
    }
    if (strtoupper((string) $r['type']) !== ddl_sqlite_affinity($col['type'])) {
        return 'type';
    }
    if (((int) $r['notnull'] === 1) !== (bool) $col['notnull']) {
        return 'null';
    }
    $liveDef = $r['dflt_value'] === null ? null : (string) $r['dflt_value'];
    $wantDef = ($col['default'] === null || $col['default'] === 'NULL') ? null : (string) $col['default'];
    if ($liveDef !== $wantDef) {
        return 'default';
    }
    $live = mig_live_schema_sqlite($pdo, (string) $table, true);
    $lc   = isset($live[$table]['columns'][$col['name']]) ? $live[$table]['columns'][$col['name']] : null;
    $wantLen = (ddl_sqlite_affinity($col['type']) === 'TEXT') ? $col['len'] : null;
    if ($lc === null || $lc['len'] !== $wantLen) {
        return 'length';
    }
    return null;
}

// ---------------------------------------------------------------------
// schema_migrations 테이블 — DDL 의 정본은 schema.sql 이다
// ---------------------------------------------------------------------

/**
 * schema.sql 에서 테이블 하나의 CREATE 문을 뽑아 온다 (prefix 치환 전, 리터럴 상태).
 *
 * **왜 마이그레이션 파일에 CREATE 문을 적지 않는가:** 적으면 같은 테이블 정의가
 * schema.sql 과 마이그레이션 두 곳에 생기고, 이 프로젝트에서 반복된 사고가
 * 정확히 "두 정의가 갈라지는 것"이다. 그래서 마이그레이션 파일은
 *
 *     CREATE TABLE FROM SCHEMA app_ulogin_foo;
 *
 * 라고만 적고, 실행 시점에 이 함수가 schema.sql 의 정본 DDL 로 바꿔 넣는다.
 * 정의는 schema.sql 한 곳뿐이다.
 *
 * **`^` + `m` 으로 줄머리에 고정한다 — 같은 파일의 `mig_parse_guard()` 와 같은 이유다.**
 * 고정하지 않으면 schema.sql 의 **주석 안에서 DDL 을 인용한 한 줄**까지 정의로 읽는다.
 * `.*?` 가 최단 일치라 매치는 그 주석에서 시작해 아래의 진짜 정의를 통째로 삼키고,
 * 결과는 `CREATE TABLE … 형태로 적는다.\n-- …\nCREATE TABLE …` 라는 문법 오류 DDL 이다.
 * 재현: schema.sql 머리에 `` CREATE TABLE IF NOT EXISTS `app_ulogin_users` `` 를 인용한
 * 주석 한 줄을 넣으면 이 함수가 그 주석부터 매치한다(고정 후에는 정본 DDL 을 돌려준다).
 * 결과적으로 **주석이 DB 로 들어간다** — 조용히 틀리는 종류다.
 *
 * 대가: **들여쓴 `CREATE TABLE` 은 보이지 않는다.** schema.sql 의 17개 정의는 전부
 * 줄머리에 있고(들여쓴 정의 0건), 정본 스키마의 규약이 그것이다.
 */
function mig_table_ddl_from_schema($schemaFile, $table)
{
    static $cache = null;
    if ($cache === null) {
        $sql = @file_get_contents($schemaFile);
        if ($sql === false || trim((string) $sql) === '') {
            throw new RuntimeException('schema.sql 을 읽을 수 없습니다: ' . $schemaFile);
        }
        $cache = (string) $sql;
    }
    $m  = [];
    $re = '/^CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`' . preg_quote((string) $table, '/')
        . '`.*?\n\)\s*ENGINE[^;]*/ims';
    if (preg_match($re, $cache, $m) !== 1) {
        throw new RuntimeException('schema.sql 에서 `' . $table . '` 테이블 정의를 찾지 못했습니다.');
    }
    return $m[0];
}

/**
 * `CREATE TABLE FROM SCHEMA app_ulogin_foo` 가짜 문장을 정본 DDL 로 바꾼다.
 * 그 외 문장은 그대로 돌려준다. **prefix 치환 전** 문장을 받는다.
 */
function mig_expand_statement($stmt, $schemaFile)
{
    $m = [];
    if (preg_match('/^\s*CREATE\s+TABLE\s+FROM\s+SCHEMA\s+`?([A-Za-z0-9_]+)`?\s*$/i', (string) $stmt, $m) !== 1) {
        return (string) $stmt;
    }
    return mig_table_ddl_from_schema($schemaFile, $m[1]);
}

function mig_ensure_table(PDO $pdo, $schemaFile, $prefix)
{
    $table = mig_apply_prefix('app_ulogin_schema_migrations', $prefix);
    if (mig_table_exists($pdo, $table)) {
        return $table;
    }
    // SQLite 는 같은 정본 DDL 을 번역해 만든다 — 정의는 여전히 schema.sql 한 곳이다.
    mig_exec_statement($pdo, mig_apply_prefix(mig_table_ddl_from_schema($schemaFile, 'app_ulogin_schema_migrations'), $prefix));
    return $table;
}

/** @return array version => row */
function mig_applied_rows(PDO $pdo, $table)
{
    $out = [];
    $st  = $pdo->query('SELECT * FROM `' . $table . '`');
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(string) $r['version']] = $r;
    }
    return $out;
}

/**
 * 파일 목록 + DB 기록을 합쳐 사람이 읽는 상태표를 만든다.
 * @return array 각 원소 [version, filename, name, state, note]
 *   state: applied | baseline | pending | partial | failed | checksum-mismatch | orphan
 */
function mig_status(array $files, array $rows)
{
    $out  = [];
    $seen = [];
    foreach ($files as $f) {
        $v    = $f['version'];
        $seen[$v] = true;
        if (!isset($rows[$v])) {
            $out[] = ['version' => $v, 'filename' => $f['filename'], 'name' => $f['name'],
                      'state' => 'pending', 'note' => '아직 적용되지 않았다'];
            continue;
        }
        $r  = $rows[$v];
        $st = (string) $r['status'];
        if ((string) $r['checksum'] !== $f['checksum'] && $st !== 'failed' && $st !== 'running') {
            $out[] = ['version' => $v, 'filename' => $f['filename'], 'name' => $f['name'],
                      'state' => 'checksum-mismatch',
                      'note' => '적용된 뒤 파일이 바뀌었다. 적용 이력과 파일이 다르므로 진행하지 않는다'];
            continue;
        }
        if ($st === 'running') {
            $out[] = ['version' => $v, 'filename' => $f['filename'], 'name' => $f['name'],
                      'state' => 'partial',
                      'note' => '부분 적용 상태. ' . (int) $r['statements_done'] . '/' . (int) $r['statements_total']
                                . ' 문장까지 적용됐다. 재적용하면 가드가 이미 된 문장을 건너뛴다'];
            continue;
        }
        if ($st === 'failed') {
            $out[] = ['version' => $v, 'filename' => $f['filename'], 'name' => $f['name'],
                      'state' => 'failed',
                      'note' => (int) $r['statements_done'] . '/' . (int) $r['statements_total']
                                . ' 문장까지 적용된 뒤 실패했다: ' . (string) $r['error_text']];
            continue;
        }
        $out[] = ['version' => $v, 'filename' => $f['filename'], 'name' => $f['name'],
                  'state' => $st,
                  'note' => ($st === 'baseline'
                      ? '신규 설치라 schema.sql 이 이미 이 형태다 — 적용하지 않는다'
                      : '적용 완료 (' . (string) $r['finished_at'] . ')')];
    }
    foreach ($rows as $v => $r) {
        if (!isset($seen[$v])) {
            $out[] = ['version' => $v, 'filename' => (string) $r['filename'], 'name' => (string) $r['name'],
                      'state' => 'orphan',
                      'note' => 'DB 에 기록은 있는데 파일이 없다. 이 코드가 기록보다 낡았을 수 있다'];
        }
    }
    usort($out, 'mig_cmp_version');
    return $out;
}

/**
 * 미적용 파일만 순서대로 적용한다.
 *
 * **부분 적용을 다루는 방식** — MySQL 은 DDL 에 트랜잭션이 걸리지 않는다
 * (CREATE/ALTER 는 암묵적 커밋을 일으킨다). 그래서 롤백에 기대지 않고
 * **진행 상황을 DB 에 남긴다**:
 *   1) 적용 전에 status='running', statements_done=0 행을 만든다
 *   2) 문장 하나가 끝날 때마다 statements_done 을 올린다
 *   3) 전부 끝나면 status='applied'
 *   4) 예외가 나면 status='failed' + error_text
 * 중간에 프로세스가 죽으면 행은 'running' 으로 남는다 — 그 자체가
 * "어디까지 갔는지"의 기록이다. 다시 실행하면 같은 파일을 처음부터 돌리되
 * `@skip-if` 가드가 이미 적용된 문장을 건너뛴다. 그래서 가드 없는 DDL 을
 * mig_lint() 가 거부한다.
 *
 * @return array 사람이 읽는 진행 로그
 */
function mig_apply(PDO $pdo, array $files, array $rows, $table, $prefix, $by, $schemaFile)
{
    $log = [];
    foreach ($files as $f) {
        $v  = $f['version'];
        $r  = isset($rows[$v]) ? $rows[$v] : null;
        if ($r !== null && in_array((string) $r['status'], ['applied', 'baseline'], true)) {
            if ((string) $r['checksum'] !== $f['checksum']) {
                $log[] = ['v' => $v, 'ok' => false,
                          'msg' => '적용된 뒤 파일이 바뀌었다. 중단한다 (checksum 불일치)'];
                return $log;
            }
            $log[] = ['v' => $v, 'ok' => true, 'msg' => '건너뜀 (' . (string) $r['status'] . ')'];
            continue;
        }

        // 자를 때는 **리터럴 prefix 상태**로 자른다. `CREATE TABLE FROM SCHEMA` 확장이
        // schema.sql 의 리터럴 테이블명으로 조회하기 때문이다. 치환은 실행 직전에 한다.
        $stmts = mig_split($f['raw']);
        $errs  = mig_lint($stmts);
        if ($errs !== []) {
            $log[] = ['v' => $v, 'ok' => false, 'msg' => '마이그레이션 파일 결함: ' . implode(' / ', $errs)];
            return $log;
        }

        $total = count($stmts);
        $now   = mig_now();
        if ($r === null) {
            $ins = $pdo->prepare('INSERT INTO `' . $table . '`'
                . ' (version, name, filename, checksum, status, statements_total, statements_done, started_at, applied_by)'
                . ' VALUES (?,?,?,?,?,?,0,?,?)');
            $ins->execute([$v, $f['name'], $f['filename'], $f['checksum'], 'running', $total, $now, (string) $by]);
        } else {
            $upd = $pdo->prepare('UPDATE `' . $table . '` SET name=?, filename=?, checksum=?, status=?,'
                . ' statements_total=?, statements_done=0, started_at=?, finished_at=NULL, applied_by=?, error_text=NULL'
                . ' WHERE version=?');
            $upd->execute([$f['name'], $f['filename'], $f['checksum'], 'running', $total, $now, (string) $by, $v]);
        }

        $done    = 0;
        $skipped = 0;
        try {
            foreach ($stmts as $s) {
                if (mig_should_skip($pdo, $s['guards'], $prefix)) {
                    $skipped++;
                } elseif (mig_exec_statement($pdo, mig_apply_prefix(mig_expand_statement($s['sql'], $schemaFile), $prefix)) === 'noop') {
                    // SQLite 의 COMMENT 전용 MODIFY — 적용할 것이 없다. 건너뜀으로 센다(설계 C-3).
                    $skipped++;
                }
                $done++;
                $pdo->prepare('UPDATE `' . $table . '` SET statements_done=? WHERE version=?')
                    ->execute([$done, $v]);
            }
        } catch (Throwable $e) {
            $msg = mig_safe_error($e);
            $pdo->prepare('UPDATE `' . $table . '` SET status=?, error_text=? WHERE version=?')
                ->execute(['failed', $msg, $v]);
            $log[] = ['v' => $v, 'ok' => false,
                      'msg' => $done . '/' . $total . ' 문장까지 적용된 뒤 실패했다: ' . $msg];
            return $log;   // 뒤 마이그레이션은 손대지 않는다
        }

        $pdo->prepare('UPDATE `' . $table . '` SET status=?, finished_at=? WHERE version=?')
            ->execute(['applied', mig_now(), $v]);
        $log[] = ['v' => $v, 'ok' => true,
                  'msg' => '적용 완료 (' . $total . ' 문장 중 ' . $skipped . ' 건은 이미 적용돼 건너뜀)'];
    }
    return $log;
}

/** 신규 설치 직후 호출. schema.sql 이 이미 최종 형태이므로 전 파일을 baseline 으로 기록한다. */
function mig_stamp_baseline(PDO $pdo, array $files, $table, $by)
{
    $n   = 0;
    $now = mig_now();
    $st  = $pdo->prepare('INSERT INTO `' . $table . '`'
        . ' (version, name, filename, checksum, status, statements_total, statements_done, started_at, finished_at, applied_by)'
        . ' VALUES (?,?,?,?,?,0,0,?,?,?)'
        // MySQL 은 v1.7.0 과 같은 의미(ON DUPLICATE KEY UPDATE …=VALUES(…), error_text=NULL),
        // SQLite 는 ON CONFLICT(version) DO UPDATE … — 꼬리절은 한 함수가 만든다(core/dbdriver.php).
        . ulogin_sql_upsert_tail(ulogin_pdo_driver($pdo), ['version'], [
            'name' => 'new', 'filename' => 'new', 'checksum' => 'new', 'status' => 'new',
            'finished_at' => 'new', 'applied_by' => 'new', 'error_text' => 'null',
        ]));
    foreach ($files as $f) {
        $st->execute([$f['version'], $f['name'], $f['filename'], $f['checksum'], 'baseline', $now, $now, (string) $by]);
        $n++;
    }
    return $n;
}

/** DB 예외 메시지를 그대로 저장하지 않는다. 스키마·경로가 로그로 새는 것을 줄인다. */
function mig_safe_error(Throwable $e)
{
    $m = $e->getMessage();
    $m = preg_replace('/\s+/', ' ', (string) $m);
    return substr($m, 0, 400);
}

// =====================================================================
// 두 경로가 같은 스키마에 도달했는지 검증한다
//
// 신규 설치는 schema.sql 을, 기존 업그레이드는 migrations/ 를 지난다.
// 둘이 갈라지지 않게 하는 장치가 이것이다 — **살아 있는 DB 를 schema.sql 과 대조**한다.
// 마이그레이션에만 넣고 schema.sql 에 안 넣으면 신규 설치 쪽에서 '없음' 으로,
// schema.sql 에만 넣으면 업그레이드 쪽에서 '없음' 으로 잡힌다.
// 어느 쪽이든 한 명령으로 드러난다.
// =====================================================================

/**
 * schema.sql 파싱 → [table => ['columns'=>[name=>[type,len,null]], 'indexes'=>[name=>'col1,col2']]]
 *
 * **`^` + `m` 으로 줄머리에 고정한다 — `mig_parse_guard()`·`mig_table_ddl_from_schema()` 와 같은 이유다.**
 * 아래의 본문 루프가 `--` 로 시작하는 줄을 건너뛰므로 "주석이 컬럼으로 들어가는" 사고는
 * 원래도 없었지만, **주석이 인용한 테이블명이 정본에 없으면 결과가 크게 어긋난다**:
 * 매치가 그 주석에서 시작해 아래 첫 진짜 정의의 `\n) ENGINE` 까지 삼키므로
 * **유령 테이블 하나가 생기고 진짜 테이블 하나가 사라진다.**
 * 재현: schema.sql 머리에 `` CREATE TABLE IF NOT EXISTS `app_ulogin_ghost` ( `` 를 인용한
 * 주석을 넣으면 `app_ulogin_ghost`(컬럼 36개)가 생기고 `app_ulogin_users` 가 없어졌다.
 * 이 함수는 **두 설치 경로의 스키마 드리프트를 잡는 게이트**다 — 멀쩡한 DB 를 두고
 * "테이블이 없다" 고 보고하는 방향의 오류이므로 게이트로서 가장 나쁘다.
 *
 * 대가는 위 두 함수와 같다: **들여쓴 `CREATE TABLE` 은 보이지 않는다.**
 * 정본 schema.sql 의 정의는 전부 줄머리에 있다.
 */
function mig_expected_schema($schemaFile, $prefix)
{
    $sql = @file_get_contents($schemaFile);
    if ($sql === false) {
        throw new RuntimeException('schema.sql 을 읽을 수 없습니다: ' . $schemaFile);
    }
    return mig_expected_schema_from_sql((string) $sql, $prefix);
}

/**
 * mig_expected_schema() 의 본체 — 파일 대신 **원문 문자열**을 받는다(동등성 검사기의 변이 시험용).
 *
 * v1.8.0: 표마다 키 두 개를 더 돌려준다. **MySQL 대조에는 쓰이지 않는다**(mig_schema_diff 가
 * 살아 있는 쪽이 `strict` 일 때만 본다 — SQLite 경로).
 *   'unique'  => [인덱스명 => bool]       PRIMARY · UNIQUE KEY 는 true
 *   'collate' => [컬럼명 => 'nocase']     `-- @sqlite-collate: nocase` 바로 다음 컬럼
 */
function mig_expected_schema_from_sql($sql, $prefix)
{
    $sql = mig_apply_prefix((string) $sql, $prefix);

    $out = [];
    $mm  = [];
    preg_match_all('/^CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`([^`]+)`\s*\((.*?)\n\)\s*ENGINE/ism', $sql, $mm, PREG_SET_ORDER);
    foreach ($mm as $m) {
        $table = $m[1];
        $body  = $m[2];
        $cols  = [];
        $idx   = [];
        $uniq  = [];
        $coll  = [];
        $nocaseNext = false;
        foreach (explode("\n", $body) as $rawLine) {
            $line = trim($rawLine);
            if ($line === '') {
                continue;
            }
            if (substr($line, 0, 2) === '--') {
                if (preg_match('/^--\s*@sqlite-collate:\s*nocase\s*$/i', $line) === 1) {
                    $nocaseNext = true;
                }
                continue;
            }
            // 따옴표 문자열(COMMENT '…', DEFAULT '…')을 먼저 지운다. 괄호·백틱이 들어 있을 수 있다.
            $line = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/", "''", $line);
            $line = rtrim($line, ',');

            $g = [];
            if (preg_match('/^PRIMARY\s+KEY\s*\(([^)]*)\)/i', $line, $g) === 1) {
                $idx['PRIMARY']  = mig_norm_cols($g[1]);
                $uniq['PRIMARY'] = true;
                continue;
            }
            if (preg_match('/^(UNIQUE\s+)?KEY\s+`([^`]+)`\s*\(([^)]*)\)/i', $line, $g) === 1) {
                $idx[$g[2]]  = mig_norm_cols($g[3]);
                $uniq[$g[2]] = trim($g[1]) !== '';
                continue;
            }
            if (preg_match('/^`([^`]+)`\s+([A-Za-z]+)(?:\(([0-9]+)(?:,[0-9]+)?\))?(.*)$/', $line, $g) === 1) {
                $rest = strtoupper($g[4]);
                $cols[$g[1]] = [
                    'type' => strtolower($g[2]),
                    'len'  => ($g[3] === '' ? null : (int) $g[3]),
                    'null' => (strpos($rest, 'NOT NULL') === false),
                ];
                if ($nocaseNext) {
                    $coll[$g[1]] = 'nocase';
                }
                $nocaseNext = false;
            }
        }
        $out[$table] = ['columns' => $cols, 'indexes' => $idx, 'unique' => $uniq, 'collate' => $coll];
    }
    return $out;
}

function mig_norm_cols($s)
{
    $parts = explode(',', (string) $s);
    $out   = [];
    foreach ($parts as $p) {
        $p = trim($p);
        $p = trim($p, '`');
        // 접두 인덱스 표기(`col`(20)) 는 길이를 떼고 이름만 본다
        $p = preg_replace('/\(\s*[0-9]+\s*\)$/', '', $p);
        if ($p !== '') {
            $out[] = $p;
        }
    }
    return implode(',', $out);
}

/** 살아 있는 DB → 같은 형태 */
function mig_live_schema(PDO $pdo, $prefix)
{
    if (mig_is_sqlite($pdo)) {
        return mig_live_schema_sqlite($pdo, $prefix);
    }
    $out = [];
    $st  = $pdo->prepare('SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, NUMERIC_PRECISION, IS_NULLABLE'
        . ' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?'
        . ' ORDER BY TABLE_NAME, ORDINAL_POSITION');
    $st->execute([str_replace(['%', '_'], ['\\%', '\\_'], (string) $prefix) . '%']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $t = (string) $r['TABLE_NAME'];
        if (!isset($out[$t])) {
            $out[$t] = ['columns' => [], 'indexes' => []];
        }
        $type = strtolower((string) $r['DATA_TYPE']);
        $len  = null;
        if ($r['CHARACTER_MAXIMUM_LENGTH'] !== null && ($type === 'varchar' || $type === 'char')) {
            $len = (int) $r['CHARACTER_MAXIMUM_LENGTH'];
        }
        $out[$t]['columns'][(string) $r['COLUMN_NAME']] = [
            'type' => $type,
            'len'  => $len,
            'null' => ((string) $r['IS_NULLABLE'] === 'YES'),
        ];
    }
    $st = $pdo->prepare('SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME FROM information_schema.STATISTICS'
        . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ? ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX');
    $st->execute([str_replace(['%', '_'], ['\\%', '\\_'], (string) $prefix) . '%']);
    $acc = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $acc[(string) $r['TABLE_NAME']][(string) $r['INDEX_NAME']][] = (string) $r['COLUMN_NAME'];
    }
    foreach ($acc as $t => $ix) {
        if (!isset($out[$t])) {
            $out[$t] = ['columns' => [], 'indexes' => []];
        }
        foreach ($ix as $name => $cols) {
            $out[$t]['indexes'][$name] = implode(',', $cols);
        }
    }
    return $out;
}

/**
 * 살아 있는 **SQLite** DB → mig_live_schema() 와 같은 형태 (설계 C-4 겹 2).
 *
 * information_schema 가 없으므로 `sqlite_master` 와 PRAGMA table_info · index_list · index_xinfo
 * (테이블 값 함수 형태)로 읽는다. 타입은 저장 친화도(`integer`/`text`), 길이는 번역기가 남긴
 * `CHECK(length("c") <= n)` 를 **테이블 SQL 텍스트에서** 긁는다(pragma 에 안 보인다).
 * 표마다 `strict => true` 를 붙인다 — mig_schema_diff() 가 그것을 보고 SQLite 전용 대조
 * (친화도 · 길이 제약 누락 · UNIQUE 여부 · NOCASE)를 켠다. MySQL 쪽 대조는 그대로다.
 *
 * 테이블 이름 필터는 `LIKE` 를 쓰지 않고 **접두 문자열 비교**로 한다 — 이스케이프 규칙이
 * 저장소마다 다른 자리를 하나 덜 만든다(B 담당의 `ESCAPE '!'` 정정과 섞이지 않게).
 *
 * @param bool $exactTable true 면 $prefix 를 **테이블 이름 그대로**로 본다(한 표만 읽기)
 */
function mig_live_schema_sqlite(PDO $pdo, $prefix, $exactTable = false)
{
    $out = [];
    if ($exactTable) {
        $st = $pdo->prepare("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name = ?");
        $st->execute([(string) $prefix]);
    } else {
        $st = $pdo->prepare("SELECT name, sql FROM sqlite_master WHERE type = 'table'"
            . " AND substr(name, 1, ?) = ? AND name <> 'sqlite_sequence' ORDER BY name");
        $st->execute([strlen((string) $prefix), (string) $prefix]);
    }
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $tr) {
        $t   = (string) $tr['name'];
        $sql = (string) $tr['sql'];
        // 컬럼 정의 줄 — 번역기는 한 줄에 컬럼 하나를 쓴다. ADD COLUMN 으로 붙은 컬럼은
        // SQLite 가 같은 SQL 텍스트 끝에 `, "c" …` 로 이어 붙이므로 줄머리가 아닐 수 있다 → 따로 잡는다.
        $defs = [];
        $dm   = [];
        preg_match_all('/(?:^|,)\s*"([A-Za-z0-9_]+)"\s+(INTEGER|TEXT)\b([^,\n]*(?:\([^)]*\)[^,\n]*)*)/m', $sql, $dm, PREG_SET_ORDER);
        foreach ($dm as $d) {
            $defs[$d[1]] = $d[3];
        }
        $cols = [];
        $coll = [];
        $pk   = [];
        $ci   = $pdo->prepare('SELECT name, type, "notnull", pk FROM pragma_table_info(?) ORDER BY cid');
        $ci->execute([$t]);
        foreach ($ci->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $name = (string) $c['name'];
            $rest = isset($defs[$name]) ? $defs[$name] : '';
            $len  = null;
            $lm   = [];
            if (preg_match('/CHECK\(length\("' . preg_quote($name, '/') . '"\) <= ([0-9]+)\)/', $rest, $lm) === 1) {
                $len = (int) $lm[1];
            }
            $cols[$name] = [
                'type' => strtolower((string) $c['type']),
                'len'  => $len,
                'null' => ((int) $c['notnull'] === 0),
            ];
            if (stripos($rest, 'COLLATE NOCASE') !== false) {
                $coll[$name] = 'nocase';
            }
            if ((int) $c['pk'] > 0) {
                $pk[(int) $c['pk']] = $name;
            }
        }
        $idx  = [];
        $uniq = [];
        $icol = [];
        if ($pk !== []) {
            ksort($pk);
            $idx['PRIMARY']  = implode(',', $pk);
            $uniq['PRIMARY'] = true;
        }
        $il = $pdo->prepare('SELECT name, "unique", origin FROM pragma_index_list(?)');
        $il->execute([$t]);
        foreach ($il->fetchAll(PDO::FETCH_ASSOC) as $ix) {
            if ((string) $ix['origin'] === 'pk') {
                continue;   // 문자열 PK 의 자동 인덱스 — PRIMARY 로 이미 셌다
            }
            $xi = $pdo->prepare('SELECT name, coll FROM pragma_index_xinfo(?) WHERE "key" = 1 ORDER BY seqno');
            $xi->execute([(string) $ix['name']]);
            $names = [];
            $colls = [];
            foreach ($xi->fetchAll(PDO::FETCH_ASSOC) as $x) {
                $names[] = (string) $x['name'];
                $colls[] = strtoupper((string) $x['coll']);
            }
            $idx[(string) $ix['name']]  = implode(',', $names);
            $uniq[(string) $ix['name']] = ((int) $ix['unique'] === 1);
            $icol[(string) $ix['name']] = implode(',', $colls);
        }
        $out[$t] = ['columns' => $cols, 'indexes' => $idx, 'unique' => $uniq,
                    'collate' => $coll, 'index_coll' => $icol, 'strict' => true];
    }
    return $out;
}

/**
 * 기대 스키마 ↔ 실제 스키마 차이.
 *
 * v1.8.0: 살아 있는 쪽 표에 `strict` 가 있으면(SQLite) 대조를 넷 더 켠다 — MySQL 경로는 그대로다.
 *   ① 타입은 **친화도로** 비교한다(varchar→text 등. 매핑은 core/ddl.php 한 곳)
 *   ② 문자열 길이 제약이 **없으면** 차이다(MySQL 쪽은 「둘 다 있을 때만 비교」였다 — SQLite 에서
 *      그 규칙이면 번역기가 CHECK 를 빠뜨려도 조용하다)
 *   ③ UNIQUE 여부 ④ `NOCASE`(컬럼과 인덱스 양쪽)
 * @return array 사람이 읽는 차이 목록. 비어 있으면 두 경로가 같은 형태에 도달한 것이다
 */
function mig_schema_diff(array $expected, array $live)
{
    $diff = [];
    foreach ($expected as $t => $e) {
        if (!isset($live[$t])) {
            $diff[] = '테이블 없음: ' . $t;
            continue;
        }
        $strict = !empty($live[$t]['strict']);
        foreach ($e['columns'] as $c => $spec) {
            if (!isset($live[$t]['columns'][$c])) {
                $diff[] = '컬럼 없음: ' . $t . '.' . $c;
                continue;
            }
            $l = $live[$t]['columns'][$c];
            $wantType = $spec['type'];
            $isText   = false;
            if ($strict) {
                try {
                    $aff = ddl_sqlite_affinity($spec['type']);
                } catch (Throwable $ex) {
                    $aff = '?';
                }
                $wantType = strtolower($aff);
                $isText   = ($aff === 'TEXT');
            }
            if ($l['type'] !== $wantType) {
                $diff[] = '타입 다름: ' . $t . '.' . $c . ' schema.sql=' . $wantType . ' DB=' . $l['type'];
            }
            if ($strict && $isText && $spec['len'] !== null && $l['len'] === null) {
                $diff[] = '길이 제약 없음: ' . $t . '.' . $c . ' schema.sql=' . $spec['len'] . ' DB=(CHECK 없음)';
            } elseif ($spec['len'] !== null && $l['len'] !== null && $spec['len'] !== $l['len']) {
                $diff[] = '길이 다름: ' . $t . '.' . $c . ' schema.sql=' . $spec['len'] . ' DB=' . $l['len'];
            }
            if ($spec['null'] !== $l['null']) {
                $diff[] = 'NULL 허용 다름: ' . $t . '.' . $c;
            }
            if ($strict) {
                $wantNc = isset($e['collate'][$c]);
                $liveNc = isset($live[$t]['collate'][$c]);
                if ($wantNc !== $liveNc) {
                    $diff[] = '대소문자 비교 다름: ' . $t . '.' . $c . ' schema.sql=' . ($wantNc ? 'nocase' : 'binary')
                            . ' DB=' . ($liveNc ? 'nocase' : 'binary');
                }
            }
        }
        foreach ($e['indexes'] as $n => $cols) {
            if (!isset($live[$t]['indexes'][$n])) {
                $diff[] = '인덱스 없음: ' . $t . '.' . $n . ' (' . $cols . ')';
            } elseif ($live[$t]['indexes'][$n] !== $cols) {
                $diff[] = '인덱스 컬럼 다름: ' . $t . '.' . $n
                        . ' schema.sql=' . $cols . ' DB=' . $live[$t]['indexes'][$n];
            } elseif ($strict) {
                $wantU = !empty($e['unique'][$n]);
                $liveU = !empty($live[$t]['unique'][$n]);
                if ($wantU !== $liveU) {
                    $diff[] = 'UNIQUE 여부 다름: ' . $t . '.' . $n . ' schema.sql=' . ($wantU ? 'unique' : 'non-unique')
                            . ' DB=' . ($liveU ? 'unique' : 'non-unique');
                }
                if ($n !== 'PRIMARY') {
                    $wantColl = [];
                    foreach (explode(',', $cols) as $ic) {
                        $wantColl[] = isset($e['collate'][$ic]) ? 'NOCASE' : 'BINARY';
                    }
                    $lc = isset($live[$t]['index_coll'][$n]) ? $live[$t]['index_coll'][$n] : '';
                    if ($lc !== implode(',', $wantColl)) {
                        $diff[] = '인덱스 대소문자 비교 다름: ' . $t . '.' . $n . ' schema.sql=' . implode(',', $wantColl) . ' DB=' . $lc;
                    }
                }
            }
        }
        foreach ($live[$t]['columns'] as $c => $_) {
            if (!isset($e['columns'][$c])) {
                $diff[] = 'schema.sql 에 없는 컬럼이 DB 에 있다: ' . $t . '.' . $c;
            }
        }
        foreach ($live[$t]['indexes'] as $n => $_) {
            if (!isset($e['indexes'][$n])) {
                $diff[] = 'schema.sql 에 없는 인덱스가 DB 에 있다: ' . $t . '.' . $n;
            }
        }
    }
    foreach ($live as $t => $_) {
        if (!isset($expected[$t])) {
            $diff[] = 'schema.sql 에 없는 테이블이 DB 에 있다: ' . $t;
        }
    }
    return $diff;
}

// =====================================================================
// 🔴 번역 동등성 검사기 — 설계 C-4 겹 1 (DB 불필요. 릴리스 전에 개발자 PC 에서 무조건 돈다)
//
// 「정본이 하나(schema.sql) + 번역기」 결정의 유일한 약점은 **번역기가 조용히 틀리는 것**이다.
// 이 함수가 그것을 시끄럽게 만든다:
//   ① 메모리 SQLite 에 번역 DDL 을 **실제로 실행**한다(파싱되는가)
//   ② 그 DB 를 mig_live_schema() 로 읽어 schema.sql 기대값과 **양방향** 대조한다(strict:
//      친화도 · NULL · 길이 CHECK 누락 · UNIQUE 여부 · NOCASE — 컬럼과 인덱스 양쪽)
//   ③ 인덱스 이름이 **DB 전역에서** 유일한가(A-4 — SQLite 의 인덱스 이름공간은 테이블별이 아니다.
//      `CREATE INDEX IF NOT EXISTS` 는 다른 표의 같은 이름을 보고 **조용히 건너뛴다**)
//   ④ 처리한 표가 0개면 그 자체가 실패다(규약 7 — 검사기가 「0건」을 낼 수 없는 상태인지 먼저 본다)
// 겹 2(`migrate.php verify`)는 살아 있는 DB 가 있어야 돈다. 이것을 대신할 수 없다.
//
// @return array ['errors' => [사람용 문자열…], 'tables' => n, 'indexes' => n, 'columns' => n]
//         errors 가 비어 있어야 통과다.
// =====================================================================
function mig_sqlite_translation_check($schemaSql, $prefix = 'app_ulogin_')
{
    $errs = [];
    $res  = ['errors' => [], 'tables' => 0, 'indexes' => 0, 'columns' => 0];
    if (!extension_loaded('pdo_sqlite')) {
        $res['errors'][] = 'pdo_sqlite 확장이 없어 검사할 수 없다(통과가 아니다)';
        return $res;
    }
    $sql    = mig_apply_prefix((string) $schemaSql, $prefix);
    $blocks = ddl_extract_creates($sql);
    if ($blocks === []) {
        $res['errors'][] = 'CREATE TABLE 을 0개 찾았다 — 검사 대상이 없으면 통과가 아니다';
        return $res;
    }
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $seenIdx = [];
    foreach ($blocks as $b) {
        $name = '?';
        try {
            $p    = ddl_parse_create($b);
            $name = $p['table'];
            foreach ($p['indexes'] as $ix) {
                if (isset($seenIdx[$ix['name']])) {
                    $errs[] = '인덱스 이름이 DB 전역에서 겹친다: ' . $ix['name'] . ' (' . $seenIdx[$ix['name']] . ', ' . $name . ')';
                }
                $seenIdx[$ix['name']] = $name;
            }
            $r = ddl_sqlite_create($b);
            $pdo->exec($r['table']);
            foreach ($r['indexes'] as $ix) {
                $pdo->exec($ix);
            }
            $res['tables']++;
            $res['indexes'] += count($r['indexes']);
            $res['columns'] += count($p['columns']);
        } catch (Throwable $e) {
            $errs[] = '번역/실행 실패: ' . $name . ' — ' . $e->getMessage();
        }
    }
    $expected = mig_expected_schema_from_sql((string) $schemaSql, $prefix);
    if (count($expected) !== count($blocks)) {
        $errs[] = '기대 스키마 표 수(' . count($expected) . ')와 CREATE 덩어리 수(' . count($blocks) . ')가 다르다';
    }
    $live = mig_live_schema_sqlite($pdo, $prefix);
    foreach (mig_schema_diff($expected, $live) as $d) {
        $errs[] = $d;
    }
    $res['errors'] = $errs;
    return $res;
}
