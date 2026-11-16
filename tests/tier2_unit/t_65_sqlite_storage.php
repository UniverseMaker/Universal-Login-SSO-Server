<?php
declare(strict_types=1);

/**
 * 티어 2 — v1.8.0 저장 계층 (SQLite 기본 + MySQL 선택). DB 서버 불필요 — 메모리·임시 SQLite 만 쓴다.
 * 담당: backend-A. 설계 `_workspace/80_architect_v18_sqlite_spec.md`
 *
 *   S-1·S-2  번역 동등성 검사기(C-4 겹 1): schema.sql 20표가 SQLite 로 파싱되고, 기대값과 양방향 0건
 *            + **검사기가 눈뜨고 있음** — schema 원문에 변이를 심으면 0건이 아니어야 한다
 *   S-3      미지원 구문은 예외(fail-closed)
 *   S-4      db_is_duplicate(): UNIQUE 만 참, CHECK·NOT NULL 은 거짓 (B-3)
 *   S-7      username·email 유일성이 대소문자 무시 (A-6)
 *   S-10     DB_DRIVER 미정의 → 'mysql', MySQL DSN 이 v1.7.0 과 글자 단위로 같다 (E-1)
 *   D-5-6    없는 파일·0바이트 파일을 **만들지 않고** 거부 / PRAGMA synchronous=FULL·WAL
 *   A-7      bool 바인딩이 0/1 로 바뀌고, TINYINT(1) 에 '' 가 들어가면 **오류**
 *
 * **이 파일은 SQLite 로 돈 결과다. MySQL 경로를 증명하지 않는다**(설계 F-4).
 */

require_once __DIR__ . '/../lib/loader.php';

load_core();
if (!defined('ULOGIN_MIGRATE_CTX')) {
    define('ULOGIN_MIGRATE_CTX', 1);
}
$GLOBALS['T65_SRV'] = h_root() . '/server';
require_once $GLOBALS['T65_SRV'] . '/core/dbport.php';
require_once $GLOBALS['T65_SRV'] . '/core/dbdriver.php';
require_once $GLOBALS['T65_SRV'] . '/core/db.php';
require_once $GLOBALS['T65_SRV'] . '/migrations/lib.php';

function t65_schema(): string
{
    return h_read($GLOBALS['T65_SRV'] . '/schema.sql');
}

function t65_mem(): PDO
{
    return new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
}

/** 임시 디렉터리 — 이름 접두 ulogin_test_ 가드(F-2 규약). 끝나면 지운다. */
function t65_tmpdir(): string
{
    $d = str_replace('\\', '/', sys_get_temp_dir()) . '/ulogin_test_t65_' . bin2hex(random_bytes(4));
    if (!@mkdir($d, 0700, true)) {
        throw new RuntimeException('cannot create temp dir');
    }
    return $d;
}

function t65_rmdir(string $d): void
{
    if (strpos(basename($d), 'ulogin_test_') !== 0) {
        return;   // 가드 — 접두가 아니면 지우지 않는다
    }
    foreach ((array) @scandir($d) as $f) {
        if ($f !== '.' && $f !== '..') {
            @unlink($d . '/' . $f);
        }
    }
    @rmdir($d);
}

t('S-1/S-2 schema.sql translates to SQLite and matches its own expectation both ways', function () {
    require_ext('pdo_sqlite');
    $r = mig_sqlite_translation_check(t65_schema());
    // 0건 가드 — 처리한 대상 수부터 본다(규약 7)
    assert_eq(31, $r['tables'], 'tables translated (v1.9.0 0007 +7, v1.9.1 0008 +1, v1.10.0 0010 +3)');
    // v1.8.4 마이그레이션 0005 — ext_accounts 6 · link_jobs 3 · link_job_items 2 = +11 (설계 92 C절 DDL 그대로)
    // v1.9.0 0007 — user_services 1 · rc_drafts 1 · rc_versions 1 · plat_logs 5 · plat_audit 5 · plat_collections 1 · plat_docs 5 · plat_usage 1 = +20
    assert_eq(95, $r['indexes'], 'indexes translated (design A-4 counted 56, +11 from 0005, +1 uq_services_former from 0006, +20 from 0007, +2 from 0008, +5 from 0010)');
    assert_true($r['columns'] > 150, 'columns translated: ' . $r['columns']);
    assert_clean($r['errors'], 'translation equivalence (C-4 layer 1)');
});

/**
 * 번역기 변이 흉내 — schema.sql 원문은 그대로(기대값), **번역 결과만** $mutate 로 바꿔 DB 를 만든 뒤 대조한다.
 * 원문을 바꾸면 기대값과 실제가 함께 바뀌어 대조는 언제나 같다 — 그것은 번역기 결함의 모형이 아니다.
 * @return array [diff, 변이가 실제로 바꾼 문장 수]
 */
function t65_diff_with_translator_mutation(callable $mutate): array
{
    $s   = t65_schema();
    $pdo = t65_mem();
    $hit = 0;
    foreach (ddl_extract_creates($s) as $b) {
        $r = ddl_sqlite_create($b);
        foreach (array_merge(array($r['table']), $r['indexes']) as $stmt) {
            $m = $mutate($stmt);
            if ($m !== $stmt) {
                $hit++;
            }
            $pdo->exec($m);
        }
    }
    return array(mig_schema_diff(mig_expected_schema_from_sql($s, 'app_ulogin_'), mig_live_schema($pdo, 'app_ulogin_')), $hit);
}

t('the equivalence checker is not blind - three translator mutations are each reported (C-4 / D-186)', function () {
    require_ext('pdo_sqlite');
    $cases = array(
        'VARCHAR length CHECK not generated' => array(
            function ($q) { return preg_replace('/ CHECK\(length\("[A-Za-z0-9_]+"\) <= [0-9]+\)/', '', $q); },
            '길이 제약 없음'),
        'COLLATE NOCASE not generated' => array(
            function ($q) { return str_replace(' COLLATE NOCASE', '', $q); },
            '대소문자 비교 다름'),
        'UNIQUE dropped from index translation' => array(
            function ($q) { return str_replace('CREATE UNIQUE INDEX', 'CREATE INDEX', $q); },
            'UNIQUE 여부 다름'),
    );
    foreach ($cases as $what => $c) {
        list($diff, $hit) = t65_diff_with_translator_mutation($c[0]);
        assert_true($hit > 0, 'mutation "' . $what . '" changed nothing - the mutation itself is blind');
        $found = 0;
        foreach ($diff as $d) {
            if (strpos($d, $c[1]) !== false) {
                $found++;
            }
        }
        assert_true($found > 0, 'mutation "' . $what . '" must produce "' . $c[1] . '" findings (diff=' . count($diff) . ')');
    }
    // 대조군 — 변이 없음은 0건
    list($diff0, $hit0) = t65_diff_with_translator_mutation(function ($q) { return $q; });
    assert_eq(0, $hit0);
    assert_clean($diff0, 'unmutated translation');
});

t('an index name reused across tables is reported (A-4 - SQLite index names are database-global)', function () {
    require_ext('pdo_sqlite');
    $s   = t65_schema();
    $old = 'KEY `idx_services_status` (`status`)';
    assert_eq(1, substr_count($s, $old), 'mutation site exists once');
    $r = mig_sqlite_translation_check(str_replace($old, 'KEY `idx_users_status` (`status`)', $s));
    assert_true($r['errors'] !== array(), 'reusing idx_users_status in services must be reported');
});

t('translator ignoring the NOCASE tag would be caught - expectation and live disagree', function () {
    require_ext('pdo_sqlite');
    // 번역기가 태그를 버린 상황을 만든다: 기대값은 원문(태그 있음), 실제 DB 는 태그 없는 원문으로 만든다.
    $s    = t65_schema();
    $live = str_replace("-- @sqlite-collate: nocase\n", "\n", $s);
    assert_ne($s, $live, 'tag removal site exists');
    $pdo = t65_mem();
    foreach (ddl_extract_creates($live) as $b) {
        $r = ddl_sqlite_create($b);
        $pdo->exec($r['table']);
        foreach ($r['indexes'] as $ix) {
            $pdo->exec($ix);
        }
    }
    $diff = mig_schema_diff(mig_expected_schema_from_sql($s, 'app_ulogin_'), mig_live_schema($pdo, 'app_ulogin_'));
    $hit  = 0;
    foreach ($diff as $d) {
        if (strpos($d, 'users.username') !== false || strpos($d, 'users.email') !== false
            || strpos($d, 'uq_users_username') !== false || strpos($d, 'uq_users_email') !== false) {
            $hit++;
        }
    }
    assert_true($hit >= 4, 'column + index collation drift on username and email must be reported (got ' . $hit . ')');
});

t('S-3 unsupported MySQL constructs throw instead of translating quietly', function () {
    $wrap = function ($line) {
        return "CREATE TABLE IF NOT EXISTS `t` (\n  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n  " . $line
            . ",\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    };
    $bad = array(
        'ENUM'             => "`s` ENUM('a','b') NOT NULL",
        'JSON'             => '`j` JSON NULL',
        'DECIMAL(10,2)'    => '`d` DECIMAL(10,2) NOT NULL',
        'BLOB'             => '`b` BLOB NULL',
        'INT(11) width'    => '`i` INT(11) NOT NULL',
        'ON UPDATE'        => '`u` DATETIME NOT NULL ON UPDATE CURRENT_TIMESTAMP',
        'CURRENT_TIMESTAMP default' => '`u` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'prefix index'     => "`v` VARCHAR(50) NOT NULL,\n  KEY `idx_v` (`v`(20))",
        'FULLTEXT'         => "`v` TEXT NULL,\n  FULLTEXT KEY `ft_v` (`v`)",
        'FOREIGN KEY'      => "`v` BIGINT NOT NULL,\n  FOREIGN KEY (`v`) REFERENCES `x` (`id`)",
        'malformed collate tag' => "-- @sqlite-collate: no-case\n  `v` VARCHAR(20) NOT NULL",
        'CHARACTER SET on column' => '`v` VARCHAR(20) CHARACTER SET latin1 NOT NULL',
    );
    foreach ($bad as $what => $line) {
        assert_throws('RuntimeException', function () use ($wrap, $line) {
            ddl_sqlite_create($wrap($line));
        }, $what . ' must be refused');
    }
    // 테이블 옵션 — 아는 4종 밖
    assert_throws('RuntimeException', function () {
        ddl_sqlite_create("CREATE TABLE IF NOT EXISTS `t` (\n  `id` INT NOT NULL,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB AUTO_INCREMENT=100");
    }, 'AUTO_INCREMENT=n table option');
    // ALTER — 지원하지 않는 절
    foreach (array('ALTER TABLE `t` DROP COLUMN `x`', 'ALTER TABLE `t` CHANGE `a` `b` INT', 'ALTER TABLE `t` ADD COLUMN `a` INT NULL, ADD COLUMN `b` INT NULL') as $a) {
        assert_throws('RuntimeException', function () use ($a) {
            ddl_sqlite_statement($a);
        }, $a);
    }
    assert_throws('RuntimeException', function () {
        ddl_sqlite_statement('DROP TABLE `t`');
    }, 'other DDL');
});

t('migration ALTERs translate: ADD COLUMN drops AFTER, ADD KEY becomes CREATE INDEX, MODIFY is metadata-only', function () {
    $r = ddl_sqlite_statement("ALTER TABLE `app_ulogin_auth_codes`\n  ADD COLUMN `issued_via` VARCHAR(16) NOT NULL DEFAULT 'password'\n  COMMENT 'x'\n  AFTER `code_challenge`");
    assert_eq('add_column', $r['kind']);
    assert_eq('ALTER TABLE "app_ulogin_auth_codes" ADD COLUMN "issued_via" TEXT NOT NULL DEFAULT \'password\' CHECK(length("issued_via") <= 16)', $r['sql'][0]);
    $r = ddl_sqlite_statement('ALTER TABLE `app_ulogin_users` ADD KEY `idx_users_points` (`points`)');
    assert_eq('CREATE INDEX IF NOT EXISTS "idx_users_points" ON "app_ulogin_users" ("points")', $r['sql'][0]);
    $r = ddl_sqlite_statement("ALTER TABLE `app_ulogin_login_logs`\n  MODIFY COLUMN `result` VARCHAR(30) NOT NULL\n  COMMENT 'a|b'");
    assert_eq('modify_column', $r['kind']);
    assert_eq(array(), $r['sql'], 'MODIFY has nothing to execute on SQLite');
});

t('MODIFY COLUMN is a no-op only when the live column already matches; a real change throws', function () {
    require_ext('pdo_sqlite');
    $pdo = t65_mem();
    mig_sqlite_create_schema($pdo, $GLOBALS['T65_SRV'] . '/schema.sql', 'app_ulogin_', false);
    assert_eq('noop', mig_exec_statement($pdo, "ALTER TABLE `app_ulogin_login_logs` MODIFY COLUMN `result` VARCHAR(30) NOT NULL COMMENT 'new words'"));
    assert_throws('RuntimeException', function () use ($pdo) {
        mig_exec_statement($pdo, "ALTER TABLE `app_ulogin_login_logs` MODIFY COLUMN `result` VARCHAR(60) NOT NULL COMMENT 'longer'");
    }, 'length change via MODIFY must not be skipped silently');
    assert_throws('RuntimeException', function () use ($pdo) {
        mig_exec_statement($pdo, "ALTER TABLE `app_ulogin_login_logs` MODIFY COLUMN `result` VARCHAR(30) NULL");
    }, 'NULL change via MODIFY must not be skipped silently');
});

t('S-4 db_is_duplicate: UNIQUE violation true, CHECK and NOT NULL violations false', function () {
    require_ext('pdo_sqlite');
    $pdo = t65_mem();
    mig_sqlite_create_schema($pdo, $GLOBALS['T65_SRV'] . '/schema.sql', 'app_ulogin_', false);
    $ins = function (array $v) use ($pdo) {
        $pdo->prepare('INSERT INTO app_ulogin_settings (skey, svalue, vtype, label, updated_at) VALUES (?,?,?,?,?)')->execute($v);
    };
    $ins(array('k1', 'v', 'string', null, '2026-11-10 00:00:00'));
    $caught = function (callable $f) {
        try {
            $f();
        } catch (Throwable $e) {
            return $e;
        }
        fail('expected a PDOException');
    };
    $dup = $caught(function () use ($ins) { $ins(array('k1', 'v', 'string', null, '2026-11-10 00:00:00')); });
    assert_true(db_is_duplicate($dup), 'UNIQUE/PK violation must be a duplicate');
    $chk = $caught(function () use ($ins) { $ins(array(str_repeat('x', 101), 'v', 'string', null, '2026-11-10 00:00:00')); });
    assert_eq('23000', (string) $chk->errorInfo[0], 'CHECK violation shares SQLSTATE 23000 (that is the trap)');
    assert_false(db_is_duplicate($chk), 'CHECK(length) violation must NOT be reported as a duplicate');
    $nn = $caught(function () use ($ins) { $ins(array('k2', 'v', 'string', null, null)); });
    assert_false(db_is_duplicate($nn), 'NOT NULL violation must NOT be reported as a duplicate');
    assert_false(db_is_duplicate(new RuntimeException('UNIQUE constraint failed')), 'non-PDO exceptions are never duplicates');
});

t('S-7 username and email uniqueness ignore ASCII case on SQLite (A-6)', function () {
    require_ext('pdo_sqlite');
    $pdo = t65_mem();
    mig_sqlite_create_schema($pdo, $GLOBALS['T65_SRV'] . '/schema.sql', 'app_ulogin_', false);
    $now = '2026-11-10 00:00:00';
    $q = $pdo->prepare('INSERT INTO app_ulogin_users (uuid, username, email, password_hash, created_at, updated_at) VALUES (?,?,?,?,?,?)');
    $q->execute(array(str_repeat('a', 36), 'alice', 'alice@example.com', 'h', $now, $now));
    foreach (array(array('ALICE', 'other@example.com'), array('bob', 'Alice@Example.COM')) as $p) {
        $e = null;
        $q = $pdo->prepare('INSERT INTO app_ulogin_users (uuid, username, email, password_hash, created_at, updated_at) VALUES (?,?,?,?,?,?)');
        try {
            $q->execute(array(str_repeat('b', 36), $p[0], $p[1], 'h', $now, $now));
        } catch (PDOException $ex) {
            $e = $ex;
        }
        assert_true($e !== null && db_is_duplicate($e), 'case variant ' . $p[0] . '/' . $p[1] . ' must hit the UNIQUE index');
    }
    $n = (int) $pdo->query("SELECT COUNT(*) FROM app_ulogin_users WHERE username = 'ALICE'")->fetchColumn();
    assert_eq(1, $n, '= comparison on username is case-insensitive like utf8mb4_unicode_ci');
});

t('A-7 bool parameters become 0/1, and a raw "" into TINYINT(1) is an error, not silent', function () {
    require_ext('pdo_sqlite');
    assert_eq(array(1, 0, 'x', 0, null), db_params(array(true, false, 'x', 0, null)));
    assert_eq(array(':a' => 0), db_params(array(':a' => false)), 'named keys kept');
    $pdo = t65_mem();
    mig_sqlite_create_schema($pdo, $GLOBALS['T65_SRV'] . '/schema.sql', 'app_ulogin_', false);
    $now = '2026-11-10 00:00:00';
    $q = $pdo->prepare('INSERT INTO app_ulogin_users (uuid, username, email, password_hash, marketing_agreed, created_at, updated_at) VALUES (?,?,?,?,?,?,?)');
    $threw = false;
    try {
        $q->execute(array(str_repeat('c', 36), 'carol', 'c@example.com', 'h', false, $now, $now));   // PHP false -> ''
    } catch (PDOException $e) {
        $threw = true;
    }
    assert_true($threw, 'false bound raw must be refused by CHECK(c IN (0, 1)) - otherwise WHERE flag = 0 silently misses it');
    $q = $pdo->prepare('INSERT INTO app_ulogin_users (uuid, username, email, password_hash, marketing_agreed, created_at, updated_at) VALUES (?,?,?,?,?,?,?)');
    $q->execute(array(str_repeat('d', 36), 'dave', 'd@example.com', 'h', '1', $now, $now));
    assert_eq('integer', (string) $pdo->query("SELECT typeof(marketing_agreed) FROM app_ulogin_users WHERE username = 'dave'")->fetchColumn(),
        "'1' string is coerced to integer by affinity and passes");
});

t('S-10 DB_DRIVER undefined means mysql, and the MySQL DSN is byte-identical to v1.7.0', function () {
    assert_false(defined('DB_DRIVER'), 'the test loader must not define DB_DRIVER');
    assert_eq('mysql', ulogin_db_driver_name());
    // v1.7.0 core/db.php · migrate.php: 'mysql:host=' . DB_HOST . ulogin_db_dsn_port() . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET
    assert_eq('mysql:host=localhost;dbname=ulogin_db;charset=utf8mb4', ulogin_mysql_dsn('localhost', '', 'ulogin_db', 'utf8mb4'));
    assert_eq('mysql:host=127.0.0.1;port=3307;dbname=x;charset=utf8mb4', ulogin_mysql_dsn('127.0.0.1', ';port=3307', 'x', 'utf8mb4'));
    // v1.7.0 install.php 사본과도 같다: 'mysql:host=' . host . (port==='' ? '' : ';port=' . (int)port) . ';dbname=' . name . ';charset=utf8mb4'
    $v17 = 'mysql:host=' . 'db.example' . (';port=' . (int) '3307') . ';dbname=' . 'n' . ';charset=utf8mb4';
    assert_eq($v17, ulogin_mysql_dsn('db.example', ';port=3307', 'n', 'utf8mb4'));
});

t('DB_DRIVER with any other value is refused (no fallback to mysql)', function () {
    foreach (array("'SQLite'", "'sqlite '", "'pgsql'", "''", 'true', '1') as $v) {
        list($code, $out) = run_php_snippet(
            "define('ULOGIN_MIGRATE_CTX', 1); define('DB_DRIVER', " . $v . ");\n"
            . "require " . var_export($GLOBALS['T65_SRV'] . '/core/dbdriver.php', true) . ";\n"
            . "ini_set('log_errors', '0');\n"
            . "try { echo ulogin_db_driver_name(); } catch (RuntimeException \$e) { echo 'REFUSED'; }\n"
        );
        assert_contains('REFUSED', $out, 'DB_DRIVER=' . $v);
    }
});

t('sqlite path resolution: relative to the install root, ".." folded, :memory: and file: refused', function () {
    $root = ulogin_install_root();
    assert_eq(dirname($root) . '/runtime/data/ulogin.sqlite', ulogin_sqlite_path_resolve('../runtime/data/ulogin.sqlite'),
        'default path lands in the PARENT of the install root (outside the document root, D-281)');
    assert_eq('C:/a/b/c.sqlite', ulogin_sqlite_path_resolve('C:\\a\\x\\..\\b\\.\\c.sqlite'));
    assert_eq('/srv/d.sqlite', ulogin_sqlite_path_resolve('/srv//d.sqlite'));
    foreach (array('', '   ', ':memory:', 'file:x.db', 'file:///x.db', 'x.db?mode=rwc') as $bad) {
        assert_throws('RuntimeException', function () use ($bad) {
            ulogin_sqlite_path_resolve($bad);
        }, 'path "' . $bad . '" must be refused');
    }
});

t('D-5-6 a missing or 0-byte database file is refused and NOT created; PRAGMAs are FULL + WAL', function () {
    require_ext('pdo_sqlite');
    $d = t65_tmpdir();
    try {
        $f = $d . '/ulogin_test_missing.sqlite';
        assert_throws('UloginDbUnavailable', function () use ($f) {
            ulogin_pdo_open_sqlite($f);
        }, 'missing file');
        clearstatcache();
        assert_false(is_file($f), 'the refused open must not leave a new empty database behind (design measurement 12)');

        touch($d . '/ulogin_test_empty.sqlite');
        assert_throws('UloginDbUnavailable', function () use ($d) {
            ulogin_pdo_open_sqlite($d . '/ulogin_test_empty.sqlite');
        }, '0-byte file');

        $g   = $d . '/ulogin_test_real.sqlite';
        $pdo = ulogin_pdo_open_sqlite($g, array('create' => true, 'quick_check' => false));
        mig_sqlite_create_schema($pdo, $GLOBALS['T65_SRV'] . '/schema.sql', 'app_ulogin_', true);
        assert_eq(2, (int) $pdo->query('PRAGMA synchronous')->fetchColumn(), 'synchronous must read back FULL (2)');
        assert_eq('wal', strtolower((string) $pdo->query('PRAGMA journal_mode')->fetchColumn()));
        assert_eq(5000, (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn());
        $pdo = null;
        $again = ulogin_pdo_open_sqlite($g);   // create=false, quick_check=true — 정상 파일은 열린다
        // 신규 설치는 migrations/ 전 파일을 baseline 으로 기록한다 — 0001~0006 (v1.8.4 에 0005, v1.8.6 에 0006 추가)
        assert_eq('10', (string) $again->query('SELECT COUNT(*) FROM app_ulogin_schema_migrations')->fetchColumn(), 'baseline rows');
        $again = null;
    } finally {
        t65_rmdir($d);
    }
});

t('a corrupted database file fails quick_check with UloginDbUnavailable (503 path)', function () {
    require_ext('pdo_sqlite');
    $d = t65_tmpdir();
    try {
        $g   = $d . '/ulogin_test_corrupt.sqlite';
        $pdo = ulogin_pdo_open_sqlite($g, array('create' => true, 'quick_check' => false));
        mig_sqlite_create_schema($pdo, $GLOBALS['T65_SRV'] . '/schema.sql', 'app_ulogin_', false);
        $pdo->exec('PRAGMA journal_mode = DELETE');   // 본체 파일에 다 쓰이게
        $pdo = null;
        $raw = (string) file_get_contents($g);
        assert_true(strlen($raw) > 8192, 'database has more than two pages');
        // 2번째 페이지 이후를 쓰레기로 덮는다(헤더는 둔다 — 파일은 열리지만 내용이 깨진 상태)
        $raw = substr($raw, 0, 4096) . str_repeat("\xA5", 4096) . substr($raw, 8192);
        file_put_contents($g, $raw);
        $threw = null;
        try {
            ulogin_pdo_open_sqlite($g);
        } catch (Throwable $e) {
            $threw = $e;
        }
        assert_true($threw !== null, 'opening a corrupted file must not succeed');
        assert_true($threw instanceof UloginDbUnavailable || $threw instanceof PDOException,
            'corruption surfaces as UloginDbUnavailable (quick_check) or a PDO error - got ' . get_class($threw));
    } finally {
        t65_rmdir($d);
    }
});

t('upsert tail: one function builds both dialects', function () {
    assert_eq(' ON DUPLICATE KEY UPDATE `c` = `c` + 1, `e` = VALUES(`e`), `l` = COALESCE(VALUES(`l`), `l`), `x` = NULL',
        ulogin_sql_upsert_tail('mysql', array('k'), array('c' => 'inc', 'e' => 'new', 'l' => 'coalesce', 'x' => 'null')));
    assert_eq(' ON CONFLICT(`a`, `b`) DO UPDATE SET `c` = `c` + 1, `e` = excluded.`e`',
        ulogin_sql_upsert_tail('sqlite', array('a', 'b'), array('c' => 'inc', 'e' => 'new')));
    assert_throws('InvalidArgumentException', function () {
        ulogin_sql_upsert_tail('sqlite', array(), array('c' => 'inc'));
    }, 'sqlite needs a conflict target');
    assert_throws('InvalidArgumentException', function () {
        ulogin_sql_upsert_tail('mysql', array('k'), array('c`; DROP' => 'new'));
    }, 'identifiers are validated');
});
