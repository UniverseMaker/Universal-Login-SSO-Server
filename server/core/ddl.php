<?php
/**
 * core/ddl.php — MySQL DDL(`schema.sql` · 마이그레이션) → SQLite DDL 번역기 (v1.8.0 신규)
 * 담당 계약: `_workspace/80_architect_v18_sqlite_spec.md` §A-3 · §A-4 · §A-6 · §C-2(b) · §C-3
 *
 * =====================================================================
 * ▣ 정본은 지금도 `schema.sql` **하나**다 (설계 C-2 판정 (b))
 *
 * SQLite DDL 을 파일로 따로 두지 않는다. 두 벌이 되면 사람이 한쪽만 고치는 일이
 * **반드시** 일어난다(이 프로젝트의 반복 사고 유형). 이 파일이 `schema.sql` 의 MySQL DDL 을
 * **실행 시점에** SQLite DDL 로 바꾼다. 호출자는 둘이다 — 사본을 만들지 마라:
 *   server/migrations/lib.php (mig_ensure_table · mig_apply · mig_sqlite_create_schema)
 *   server/install.php        (ins_apply_schema)
 *
 * ▣ 🔴 번역기가 **조용히 틀리는 것**이 이 결정의 유일한 약점이다. 그래서:
 *   1) **아는 것만 번역하고 나머지는 전부 예외다**(fail-closed). 타입 8종(A-3 표의 11행) ·
 *      컬럼 속성 6종 · 키 3종 · 테이블 옵션 4종 밖은 `RuntimeException` 으로 설치가 멈춘다.
 *      `ENUM`·`SET`·`JSON`·`DECIMAL`·`BLOB`·`GEOMETRY`·접두 인덱스·`FULLTEXT`·`FOREIGN KEY`·
 *      `GENERATED`·`ON UPDATE`·`CHECK`·`INT(11)` 같은 표시 폭 — 전부 예외.
 *   2) 번역 결과를 `schema.sql` 기대값과 **양방향 대조**한다 — `migrations/lib.php` 의
 *      `mig_sqlite_translation_check()`(설계 C-4 겹 1, DB 불필요)와
 *      `migrate.php verify`(겹 2, 살아 있는 DB).
 *
 * ▣ 매핑 (A-3)
 *   BIGINT [UNSIGNED] AUTO_INCREMENT + PRIMARY KEY(id) → INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT
 *      (**INTEGER 여야** rowid 별칭이 된다. AUTOINCREMENT 는 삭제된 id 재사용을 막는다 — A-5)
 *   BIGINT · INT [UNSIGNED]  → INTEGER   (UNSIGNED 제거. 음수 방어는 애플리케이션 몫)
 *   TINYINT(1)               → INTEGER + CHECK(c IN (0, 1))
 *   VARCHAR(n) · CHAR(n)     → TEXT + CHECK(length(c) <= n)  (SQLite 는 길이를 강제하지 않는다 — 측정 13)
 *   TEXT · LONGTEXT          → TEXT
 *   DATETIME                 → TEXT   (UTC 'Y-m-d H:i:s'. 문자열 비교 = 시간 순서)
 *   `-- @sqlite-collate: nocase` 바로 다음 컬럼 → COLLATE NOCASE (A-6. 인덱스에도 따라간다)
 *   컬럼 `COLLATE utf8mb4_bin` (v1.9.0, 설계 116 §3) → COLLATE BINARY. **이 한 종만** — 그 밖의 컬럼 COLLATE 는 예외
 *   UNIQUE KEY / KEY         → CREATE [UNIQUE] INDEX IF NOT EXISTS (별도 문장. 이름은 DB 전역 — A-4)
 *   제거: UNSIGNED · COMMENT · ENGINE · ROW_FORMAT · [DEFAULT] CHARSET · COLLATE=… · AFTER
 *
 * ▣ `TINYINT(1)` 의 `CHECK(c IN (0, 1))` — 설계 표 밖의 추가 (backend 판단, 리포트에 적었다)
 *   PHP `false` 를 바인딩하면 SQLite 는 `''` 를 저장하고 `WHERE flag = 0`·`= 1` **둘 다에 안 걸린다**
 *   (A-7, 실측). 이 CHECK 가 그 값을 **오류로** 바꾼다 — 조용한 손상이 시끄러운 실패가 된다.
 *   `'0'`·`'1'` 문자열은 INTEGER 친화도로 먼저 정수가 되므로 통과한다. NULL 은 통과한다.
 *
 * 쿼리가 없다(문자열 처리만). migrate.php 가 로드해도 되는 이유가 dbport.php 와 같다.
 * `declare(strict_types=1)` 없음 · **PHP 7.2 호환**.
 * =====================================================================
 */

if (!defined('ULOGIN_BOOTSTRAPPED') && !defined('ULOGIN_MIGRATE_CTX')) {
    header_remove('X-Powered-By');
    http_response_code(404);
    exit;
}

/** @throws RuntimeException 항상 */
function ddl_fail($msg)
{
    throw new RuntimeException('ddl(sqlite): ' . $msg);
}

/** SQLite 식별자. 이 번역기는 `[A-Za-z0-9_]` 밖의 이름을 받지 않는다. */
function ddl_ident($s)
{
    if (!is_string($s) || preg_match('/^[A-Za-z0-9_]+$/', $s) !== 1) {
        ddl_fail('bad identifier');
    }
    return '"' . $s . '"';
}

/**
 * MySQL 타입 → SQLite 저장 친화도. **표에 없는 타입은 예외.**
 * `migrations/lib.php` 의 스키마 대조도 이 함수로 기대 타입을 만든다 — 매핑은 여기 한 곳이다.
 */
function ddl_sqlite_affinity($mysqlType)
{
    $t = strtoupper((string) $mysqlType);
    if ($t === 'BIGINT' || $t === 'INT' || $t === 'TINYINT') {
        return 'INTEGER';
    }
    if ($t === 'VARCHAR' || $t === 'CHAR' || $t === 'TEXT' || $t === 'LONGTEXT' || $t === 'DATETIME') {
        return 'TEXT';
    }
    ddl_fail('unsupported column type ' . preg_replace('/[^A-Za-z0-9_]/', '', $t));
}

/**
 * 컬럼 정의 뒷부분(`NOT NULL DEFAULT 'x' COMMENT '…'`)을 토큰으로 자른다.
 * 문자열 리터럴 안의 괄호·쉼표·`--` 를 구문으로 오인하지 않는다.
 * @return array 각 원소 ['t' => 'str'|'num'|'word'|'ident'|'punct', 'v' => 원문]
 */
function ddl_tokens($s)
{
    $s   = (string) $s;
    $len = strlen($s);
    $i   = 0;
    $out = array();
    while ($i < $len) {
        $c = $s[$i];
        if ($c === ' ' || $c === "\t" || $c === "\r" || $c === "\n") {
            $i++;
            continue;
        }
        if ($c === '-' && $i + 1 < $len && $s[$i + 1] === '-') {
            break;   // 줄 끝 주석
        }
        if ($c === "'") {
            $j = $i + 1;
            $closed = false;
            while ($j < $len) {
                if ($s[$j] === '\\') {
                    $j += 2;
                    continue;
                }
                if ($s[$j] === "'") {
                    if ($j + 1 < $len && $s[$j + 1] === "'") {
                        $j += 2;
                        continue;
                    }
                    $closed = true;
                    break;
                }
                $j++;
            }
            if (!$closed) {
                ddl_fail('unterminated string literal');
            }
            $out[] = array('t' => 'str', 'v' => substr($s, $i, $j - $i + 1));
            $i = $j + 1;
            continue;
        }
        if ($c === '`') {
            $j = strpos($s, '`', $i + 1);
            if ($j === false) {
                ddl_fail('unterminated identifier');
            }
            $out[] = array('t' => 'ident', 'v' => substr($s, $i + 1, $j - $i - 1));
            $i = $j + 1;
            continue;
        }
        $m = array();
        if (preg_match('/\G-?[0-9]+(?:\.[0-9]+)?/', $s, $m, 0, $i) === 1) {
            $out[] = array('t' => 'num', 'v' => $m[0]);
            $i += strlen($m[0]);
            continue;
        }
        if (preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $s, $m, 0, $i) === 1) {
            $out[] = array('t' => 'word', 'v' => strtoupper($m[0]));
            $i += strlen($m[0]);
            continue;
        }
        $out[] = array('t' => 'punct', 'v' => $c);
        $i++;
    }
    return $out;
}

/**
 * 컬럼 정의 한 개를 파싱한다. `$def` 는 컬럼 이름 **뒤**의 문자열.
 * @return array name·type·len·notnull·default·autoinc·nocase·after
 */
function ddl_parse_column($name, $def, $nocase)
{
    ddl_ident($name);
    $m = array();
    if (preg_match('/^\s*([A-Za-z]+)\s*(?:\(\s*([0-9]+)\s*(,\s*[0-9]+\s*)?\))?(.*)$/s', (string) $def, $m) !== 1) {
        ddl_fail('cannot parse column `' . $name . '`');
    }
    $type = strtoupper($m[1]);
    $len  = ($m[2] === '') ? null : (int) $m[2];
    if (isset($m[3]) && $m[3] !== '') {
        ddl_fail('precision/scale is not supported (`' . $name . '`)');
    }
    $aff = ddl_sqlite_affinity($type);   // 표에 없으면 여기서 예외

    // 길이 규칙 — A-3 표 그대로. 그 밖은 예외(예: INT(11) 표시 폭, TEXT(100)).
    if ($type === 'VARCHAR' || $type === 'CHAR') {
        if ($len === null || $len < 1) {
            ddl_fail($type . ' needs a length (`' . $name . '`)');
        }
    } elseif ($type === 'TINYINT') {
        if ($len !== 1) {
            ddl_fail('only TINYINT(1) is supported (`' . $name . '`)');
        }
    } elseif ($len !== null) {
        ddl_fail($type . '(n) is not supported (`' . $name . '`)');
    }

    $col = array(
        'name' => $name, 'type' => $type, 'len' => $len, 'notnull' => false,
        'default' => null, 'autoinc' => false, 'nocase' => (bool) $nocase, 'after' => null, 'binary' => false,
    );
    $tk = ddl_tokens($m[4]);
    $n  = count($tk);
    for ($i = 0; $i < $n; $i++) {
        $t = $tk[$i];
        $w = $t['t'] === 'word' ? $t['v'] : null;
        if ($w === 'UNSIGNED') {
            if ($aff !== 'INTEGER') {
                ddl_fail('UNSIGNED on a non-integer column (`' . $name . '`)');
            }
            continue;
        }
        if ($w === 'NOT' && $i + 1 < $n && $tk[$i + 1]['t'] === 'word' && $tk[$i + 1]['v'] === 'NULL') {
            $col['notnull'] = true;
            $i++;
            continue;
        }
        if ($w === 'NULL') {
            continue;   // 명시적 NULL 허용 = 기본
        }
        if ($w === 'DEFAULT' && $i + 1 < $n) {
            $d = $tk[$i + 1];
            if ($d['t'] === 'num') {
                $col['default'] = $d['v'];
            } elseif ($d['t'] === 'str') {
                // 백슬래시 이스케이프는 두 방언의 의미가 다르다. 지금 0건 — 나오면 멈춘다.
                if (strpos($d['v'], '\\') !== false) {
                    ddl_fail('backslash in DEFAULT literal (`' . $name . '`)');
                }
                $col['default'] = $d['v'];
            } elseif ($d['t'] === 'word' && $d['v'] === 'NULL') {
                $col['default'] = 'NULL';
            } else {
                ddl_fail('unsupported DEFAULT expression (`' . $name . '`)');
            }
            $i++;
            continue;
        }
        // v1.9.0 — 이진 비교 1종(설계 116 §3). MySQL 기본 콜레이션은 대소문자 무시라 SQLite(이진)와
        // `==`·범위 결과가 갈린다. 그 밖의 COLLATE 는 아래 unsupported 로 떨어진다(fail-closed).
        if ($w === 'COLLATE' && $i + 1 < $n && $tk[$i + 1]['t'] === 'word' && $tk[$i + 1]['v'] === 'UTF8MB4_BIN') {
            if ($aff !== 'TEXT') {
                ddl_fail('COLLATE on a non-text column (`' . $name . '`)');
            }
            $col['binary'] = true;
            $i++;
            continue;
        }
        if ($w === 'AUTO_INCREMENT') {
            if ($aff !== 'INTEGER') {
                ddl_fail('AUTO_INCREMENT on a non-integer column (`' . $name . '`)');
            }
            $col['autoinc'] = true;
            continue;
        }
        if ($w === 'COMMENT' && $i + 1 < $n && $tk[$i + 1]['t'] === 'str') {
            $i++;
            continue;   // SQLite 에 컬럼 주석이 없다
        }
        if ($w === 'AFTER' && $i + 1 < $n && $tk[$i + 1]['t'] === 'ident') {
            ddl_ident($tk[$i + 1]['v']);
            $col['after'] = $tk[$i + 1]['v'];   // SQLite 는 항상 맨 뒤 — 호출자가 버린다(A28)
            $i++;
            continue;
        }
        ddl_fail('unsupported column attribute "' . preg_replace('/[^A-Za-z0-9_(),=]/', '', (string) $t['v'])
            . '" (`' . $name . '`)');
    }
    if ($col['nocase'] && $aff !== 'TEXT') {
        ddl_fail('@sqlite-collate on a non-text column (`' . $name . '`)');
    }
    return $col;
}

/** 파싱된 컬럼 → SQLite 컬럼 정의. `$inlinePk` 면 `PRIMARY KEY AUTOINCREMENT` 를 붙인다. */
function ddl_sqlite_column_sql(array $c, $inlinePk)
{
    $id  = ddl_ident($c['name']);
    $aff = ddl_sqlite_affinity($c['type']);
    $s   = $id . ' ' . $aff;
    if ($c['notnull']) {
        $s .= ' NOT NULL';
    }
    if ($inlinePk) {
        $s .= ' PRIMARY KEY AUTOINCREMENT';
    }
    if ($c['default'] !== null) {
        $s .= ' DEFAULT ' . $c['default'];
    }
    if ($c['nocase']) {
        $s .= ' COLLATE NOCASE';
    } elseif (!empty($c['binary'])) {
        $s .= ' COLLATE BINARY';
    }
    if ($aff === 'TEXT' && $c['len'] !== null) {
        $s .= ' CHECK(length(' . $id . ') <= ' . (int) $c['len'] . ')';
    }
    if ($c['type'] === 'TINYINT') {
        $s .= ' CHECK(' . $id . ' IN (0, 1))';
    }
    return $s;
}

/** 인덱스 컬럼 목록 `` `a`, `b` `` → ['a','b']. 접두 길이·정렬 방향·식은 예외. */
function ddl_index_cols($s)
{
    $out = array();
    foreach (explode(',', (string) $s) as $p) {
        $p = trim($p);
        $m = array();
        if (preg_match('/^`([A-Za-z0-9_]+)`$/', $p, $m) !== 1) {
            ddl_fail('unsupported index column "' . preg_replace('/[^A-Za-z0-9_`() ]/', '', $p)
                . '" (prefix length / ASC / DESC / expression are not translated)');
        }
        $out[] = $m[1];
    }
    if ($out === array()) {
        ddl_fail('empty index column list');
    }
    return $out;
}

/**
 * `CREATE TABLE IF NOT EXISTS `t` ( … ) ENGINE=…` 한 덩어리를 파싱한다.
 * **한 줄에 정의 하나**가 schema.sql 의 규약이다(설계 0-2절 2). 알아볼 수 없는 줄은 예외.
 * @return array table·columns·pk·indexes
 */
function ddl_parse_create($sql)
{
    $m = array();
    if (preg_match('/^\s*CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`([A-Za-z0-9_]+)`\s*\((.*)\n\)\s*([^;]*);?\s*$/is', (string) $sql, $m) !== 1) {
        ddl_fail('not a "CREATE TABLE IF NOT EXISTS `name` (\n…\n) ENGINE=…" block');
    }
    $table = $m[1];
    $tail  = $m[3];
    // 테이블 옵션 — 아는 4종만 지운다. 남는 것이 있으면(AUTO_INCREMENT=, COMMENT=, PARTITION …) 예외.
    $rest = preg_replace(array(
        '/\bENGINE\s*=\s*[A-Za-z0-9_]+/i',
        '/\bROW_FORMAT\s*=\s*[A-Za-z0-9_]+/i',
        '/\b(?:DEFAULT\s+)?(?:CHARSET|CHARACTER\s+SET)\s*=\s*[A-Za-z0-9_]+/i',
        '/\bCOLLATE\s*=\s*[A-Za-z0-9_]+/i',
    ), '', $tail);
    if (trim((string) $rest) !== '') {
        ddl_fail('unsupported table option on `' . $table . '`');
    }

    $cols    = array();
    $pk      = null;
    $indexes = array();
    $pending = false;
    foreach (explode("\n", $m[2]) as $raw) {
        $line = trim($raw);
        if ($line === '') {
            continue;
        }
        if (substr($line, 0, 2) === '--') {
            if (stripos($line, '@sqlite-collate') !== false) {
                $g = array();
                if (preg_match('/^--\s*@sqlite-collate:\s*nocase\s*$/i', $line, $g) !== 1) {
                    // 철자가 틀린 태그를 조용히 버리면 **대소문자를 구분하는 신원 컬럼**이 생긴다.
                    ddl_fail('malformed @sqlite-collate tag in `' . $table . '` (only "-- @sqlite-collate: nocase")');
                }
                if ($pending) {
                    ddl_fail('two @sqlite-collate tags in a row in `' . $table . '`');
                }
                $pending = true;
            }
            continue;
        }
        $line = rtrim($line, ',');
        $g = array();
        if (preg_match('/^PRIMARY\s+KEY\s*\((.*)\)$/i', $line, $g) === 1) {
            if ($pending) {
                ddl_fail('@sqlite-collate must be directly above a column in `' . $table . '`');
            }
            if ($pk !== null) {
                ddl_fail('two PRIMARY KEY lines in `' . $table . '`');
            }
            $pk = ddl_index_cols($g[1]);
            continue;
        }
        if (preg_match('/^(UNIQUE\s+)?KEY\s+`([A-Za-z0-9_]+)`\s*\((.*)\)$/i', $line, $g) === 1) {
            if ($pending) {
                ddl_fail('@sqlite-collate must be directly above a column in `' . $table . '`');
            }
            if (isset($indexes[$g[2]])) {
                ddl_fail('duplicate index name ' . $g[2]);
            }
            $indexes[$g[2]] = array('name' => $g[2], 'unique' => trim($g[1]) !== '', 'cols' => ddl_index_cols($g[3]));
            continue;
        }
        if (preg_match('/^`([A-Za-z0-9_]+)`\s+(.+)$/s', $line, $g) === 1) {
            if (isset($cols[$g[1]])) {
                ddl_fail('duplicate column ' . $g[1]);
            }
            $c = ddl_parse_column($g[1], $g[2], $pending);
            if ($c['after'] !== null) {
                ddl_fail('AFTER inside CREATE TABLE (`' . $g[1] . '`)');
            }
            $cols[$g[1]] = $c;
            $pending     = false;
            continue;
        }
        ddl_fail('unrecognised line in `' . $table . '`: ' . substr(preg_replace('/[^A-Za-z0-9_ `(),]/', '', $line), 0, 50));
    }
    if ($pending) {
        ddl_fail('dangling @sqlite-collate tag at the end of `' . $table . '`');
    }
    if ($cols === array()) {
        ddl_fail('no columns in `' . $table . '`');
    }
    foreach ((array) $pk as $pc) {
        if (!isset($cols[$pc])) {
            ddl_fail('PRIMARY KEY names an unknown column in `' . $table . '`');
        }
    }
    foreach ($indexes as $ix) {
        foreach ($ix['cols'] as $ic) {
            if (!isset($cols[$ic])) {
                ddl_fail('index ' . $ix['name'] . ' names an unknown column');
            }
        }
    }
    $auto = array();
    foreach ($cols as $c) {
        if ($c['autoinc']) {
            $auto[] = $c['name'];
        }
    }
    if (count($auto) > 1 || ($auto !== array() && $pk !== $auto)) {
        ddl_fail('AUTO_INCREMENT must be the single-column PRIMARY KEY in `' . $table . '`');
    }
    return array('table' => $table, 'columns' => $cols, 'pk' => $pk, 'indexes' => $indexes);
}

/** 인덱스 하나 → `CREATE [UNIQUE] INDEX IF NOT EXISTS`. */
function ddl_sqlite_index_sql($table, array $ix)
{
    $cs = array();
    foreach ($ix['cols'] as $c) {
        $cs[] = ddl_ident($c);
    }
    return 'CREATE ' . ($ix['unique'] ? 'UNIQUE ' : '') . 'INDEX IF NOT EXISTS ' . ddl_ident($ix['name'])
         . ' ON ' . ddl_ident($table) . ' (' . implode(', ', $cs) . ')';
}

/**
 * schema.sql 의 CREATE TABLE 한 덩어리 → SQLite.
 * @return array ['table' => 'CREATE TABLE …', 'indexes' => ['CREATE INDEX …', …], 'name' => 테이블명]
 *         **인덱스가 별도 문장이다** — SQLite 는 테이블 정의 안에 KEY 를 둘 수 없다.
 */
function ddl_sqlite_create($mysqlCreate)
{
    $p      = ddl_parse_create($mysqlCreate);
    $inline = null;
    foreach ($p['columns'] as $c) {
        if ($c['autoinc']) {
            $inline = $c['name'];
        }
    }
    $defs = array();
    foreach ($p['columns'] as $c) {
        $defs[] = '  ' . ddl_sqlite_column_sql($c, $c['name'] === $inline);
    }
    if ($p['pk'] !== null && $inline === null) {
        $pcs = array();
        foreach ($p['pk'] as $c) {
            $pcs[] = ddl_ident($c);
        }
        $defs[] = '  PRIMARY KEY (' . implode(', ', $pcs) . ')';
    }
    $idx = array();
    foreach ($p['indexes'] as $ix) {
        $idx[] = ddl_sqlite_index_sql($p['table'], $ix);
    }
    return array(
        'name'    => $p['table'],
        'table'   => 'CREATE TABLE IF NOT EXISTS ' . ddl_ident($p['table']) . " (\n" . implode(",\n", $defs) . "\n)",
        'indexes' => $idx,
    );
}

/**
 * 마이그레이션의 `ALTER TABLE` 한 문장 → SQLite (설계 C-3).
 *   ADD [COLUMN] `c` …        → ALTER TABLE "t" ADD COLUMN …   (AFTER 제거)
 *   ADD [UNIQUE] KEY `n` (…)  → CREATE [UNIQUE] INDEX IF NOT EXISTS …
 *   MODIFY [COLUMN] `c` …     → kind=modify. **실행할 SQL 이 없다.** 호출자(migrations/lib.php)가
 *                               살아 있는 컬럼과 대조해 **정의가 같을 때만** no-op 으로 넘긴다
 *                               (지금 2건은 COMMENT 문구만 바꾼다). 다르면 예외 — 타입·길이 변경은
 *                               「새 테이블 만들고 복사」를 마이그레이션에 명시적으로 써야 한다
 *   그 밖(DROP · CHANGE · RENAME · 여러 절)  → 예외
 * @return array ['kind' => add_column|add_index|modify_column, 'table', 'sql' => [], 'column' => 파싱 결과|null]
 */
function ddl_sqlite_alter($stmt)
{
    $m = array();
    if (preg_match('/^\s*ALTER\s+TABLE\s+`([A-Za-z0-9_]+)`\s+(.*)$/is', (string) $stmt, $m) !== 1) {
        ddl_fail('unsupported ALTER form');
    }
    $table = $m[1];
    $rest  = trim($m[2]);
    $g     = array();
    if (preg_match('/^ADD\s+(UNIQUE\s+)?(?:KEY|INDEX)\s+`([A-Za-z0-9_]+)`\s*\(([^)]*)\)\s*$/is', $rest, $g) === 1) {
        $ix = array('name' => $g[2], 'unique' => trim($g[1]) !== '', 'cols' => ddl_index_cols($g[3]));
        return array('kind' => 'add_index', 'table' => $table, 'sql' => array(ddl_sqlite_index_sql($table, $ix)), 'column' => null);
    }
    if (preg_match('/^ADD\s+(?:COLUMN\s+)?`([A-Za-z0-9_]+)`\s+(.+)$/is', $rest, $g) === 1) {
        $c = ddl_parse_column($g[1], $g[2], false);
        if ($c['autoinc']) {
            ddl_fail('ADD COLUMN … AUTO_INCREMENT');
        }
        return array('kind' => 'add_column', 'table' => $table,
            'sql' => array('ALTER TABLE ' . ddl_ident($table) . ' ADD COLUMN ' . ddl_sqlite_column_sql($c, false)),
            'column' => $c);
    }
    if (preg_match('/^MODIFY\s+(?:COLUMN\s+)?`([A-Za-z0-9_]+)`\s+(.+)$/is', $rest, $g) === 1) {
        $c = ddl_parse_column($g[1], $g[2], false);
        return array('kind' => 'modify_column', 'table' => $table, 'sql' => array(), 'column' => $c);
    }
    ddl_fail('unsupported ALTER clause on `' . $table . '` (only ADD COLUMN / ADD [UNIQUE] KEY / MODIFY COLUMN)');
}

/**
 * 문장 하나를 번역한다. CREATE TABLE · ALTER TABLE 은 위 함수로, **그 밖의 DDL 은 예외**,
 * DDL 이 아닌 문장(INSERT·UPDATE)은 그대로 돌려준다.
 * @return array ['kind' => create|add_column|add_index|modify_column|dml, 'sql' => [실행할 문장…], …]
 */
function ddl_sqlite_statement($stmt)
{
    $s = (string) $stmt;
    if (preg_match('/^\s*CREATE\s+TABLE\b/i', $s) === 1) {
        $r = ddl_sqlite_create($s);
        return array('kind' => 'create', 'table' => $r['name'], 'sql' => array_merge(array($r['table']), $r['indexes']), 'column' => null);
    }
    if (preg_match('/^\s*ALTER\s+TABLE\b/i', $s) === 1) {
        return ddl_sqlite_alter($s);
    }
    if (preg_match('/^\s*(CREATE|DROP|RENAME|TRUNCATE|ALTER)\b/i', $s) === 1) {
        ddl_fail('unsupported DDL statement');
    }
    return array('kind' => 'dml', 'table' => null, 'sql' => array($s), 'column' => null);
}

/**
 * schema.sql 원문에서 `CREATE TABLE IF NOT EXISTS` 덩어리를 **주석째** 뽑는다.
 * `install.php` 의 `ins_split_sql()` 은 주석을 지우므로 `-- @sqlite-collate` 태그를 잃는다 —
 * 그래서 SQLite 경로는 이 함수로 자른다. 정규식은 `mig_table_ddl_from_schema()`·
 * `mig_expected_schema()` 와 같은 모양(줄머리 고정 — 주석 안의 인용을 정의로 읽지 않는다).
 * @return array 덩어리 문자열 목록 (schema.sql 순서)
 */
function ddl_extract_creates($schemaSql)
{
    $mm = array();
    preg_match_all('/^CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`[^`]+`.*?\n\)\s*ENGINE[^;]*/ims', (string) $schemaSql, $mm);
    return $mm[0];
}
