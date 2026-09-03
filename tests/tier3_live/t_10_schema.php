<?php
declare(strict_types=1);

/**
 * 티어 3 — `schema.sql` 이 실 MariaDB/MySQL 에 **무수정으로** 적용되는가
 *
 * `README.md` 의 "알려진 제한 · 미검증 항목" 표는 "MariaDB 10.4.32 에 schema.sql
 * 무수정 적용 15/15 테이블" 을 **일회성 주장**으로 적고 있다. 이 파일은 그것을
 * **매 실행마다 재확인**한다 — 미검증 항목 하나가 상시 검증으로 바뀐다.
 */

require_once __DIR__ . '/../lib/live.php';

t('schema.sql applies unmodified to a real MySQL/MariaDB', function () {
    live_or_skip();
    $pdo = live_fresh_db('ulogin_test_fresh');
    $n = live_apply_sql($pdo, h_root() . '/server/schema.sql');
    if ($n < 10) {
        fail('only ' . $n . ' statements executed from schema.sql - the splitter is blind, not the schema empty');
    }
    // 스키마가 선언한 CREATE TABLE 수와 실제로 생긴 테이블 수가 같아야 한다.
    // 하나라도 조용히 빠지면 그 테이블을 쓰는 엔드포인트가 배포 후에 죽는다.
    //
    // `^` + `m` 으로 **줄머리에 고정한다.** 고정하지 않으면 주석 안에서 DDL 을 인용한
    // 한 줄까지 선언으로 세고, 이 단언은 "실제로 생긴 테이블 수"와 비교하므로
    // **대상이 멀쩡한데 빨개진다.** 그 오탐은 안전망을 무르게 하는 쪽으로 작용한다 —
    // 다음 사람이 스키마를 고치는 대신 자기 주석의 표현을 바꿔 피해 간다
    // (v1.5.0 에서 실제로 그렇게 됐다). 세는 대상을 좁히는 것이 아니라
    // **선언과 인용을 구분하는 것**이다. 정본 DDL 을 뽑는
    // `server/migrations/lib.php` 의 `mig_table_ddl_from_schema()` 도 같은 고정을 쓴다.
    $declared = preg_match_all('~^CREATE\s+TABLE~im', h_read(h_root() . '/server/schema.sql'));
    $st = $pdo->query("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'ulogin_test_fresh'");
    $actual = (int) $st->fetch()['c'];
    assert_eq($declared, $actual, 'declared CREATE TABLE count vs tables actually created');
});

t('every table is InnoDB / utf8mb4 as the schema declares', function () {
    live_or_skip();
    $pdo = live_pdo('ulogin_test_fresh');
    $bad = array();
    $st = $pdo->query(
        "SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
           FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'ulogin_test_fresh'"
    );
    foreach ($st->fetchAll() as $r) {
        if (strcasecmp((string) $r['ENGINE'], 'InnoDB') !== 0) {
            $bad[] = $r['TABLE_NAME'] . ' engine is ' . $r['ENGINE'] . ', not InnoDB';
        }
        if (strncmp((string) $r['TABLE_COLLATION'], 'utf8mb4', 7) !== 0) {
            $bad[] = $r['TABLE_NAME'] . ' collation is ' . $r['TABLE_COLLATION'] . ', not utf8mb4_*';
        }
    }
    assert_clean($bad, 'storage engine / collation drift');
});

t('the MySQL idiom the code depends on actually works here (SUM(col = ?))', function () {
    // `api/admin/stats.php` 가 `SUM(status = ?)` 로 불리언을 정수처럼 센다.
    // 이것이 SQLite 스텁을 버린 이유 중 하나다 — 여기서 실제로 도는지 확인한다.
    live_or_skip();
    $pdo = live_pdo('ulogin_test_fresh');
    // NOT NULL 이고 기본값이 없는 컬럼(uuid·created_at 등)은 애플리케이션이 채운다.
    // 목록을 손으로 적으면 스키마가 바뀔 때마다 이 테스트가 스키마 때문에 깨진다 —
    // 대상이 아닌 이유로 빨개지는 테스트는 곧 무시된다. information_schema 에서 받아 온다.
    $need = $pdo->query(
        "SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA='ulogin_test_fresh' AND TABLE_NAME='app_ulogin_users'
            AND IS_NULLABLE='NO' AND COLUMN_DEFAULT IS NULL AND EXTRA NOT LIKE '%auto_increment%'"
    )->fetchAll();
    $fill = array(
        'username'      => array('t_a', 't_b'),
        'status'        => array('active', 'suspended'),
        'password_hash' => array('x', 'x'),
        'email'         => array('a@t.invalid', 'b@t.invalid'),
        'uuid'          => array('11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222'),
    );
    $cols = array();
    foreach ($need as $r) {
        $cols[] = (string) $r['COLUMN_NAME'];
    }
    foreach (array('username', 'status') as $must) {
        if (!in_array($must, $cols, true)) {
            $cols[] = $must;
        }
    }
    $rows = array();
    for ($i = 0; $i < 2; $i++) {
        $vals = array();
        foreach ($cols as $ci => $col) {
            if (isset($fill[$col])) {
                $vals[] = $pdo->quote($fill[$col][$i]);
                continue;
            }
            $dt = '';
            foreach ($need as $r) {
                if ((string) $r['COLUMN_NAME'] === $col) {
                    $dt = strtolower((string) $r['DATA_TYPE']);
                }
            }
            if ($dt === 'datetime' || $dt === 'timestamp' || $dt === 'date') {
                $vals[] = $pdo->quote(gmdate('Y-m-d H:i:s'));
            } elseif (in_array($dt, array('int', 'bigint', 'tinyint', 'smallint', 'decimal'), true)) {
                $vals[] = '0';
            } else {
                $vals[] = $pdo->quote('t' . $ci . $i);
            }
        }
        $rows[] = '(' . implode(',', $vals) . ')';
    }
    $pdo->exec('INSERT INTO app_ulogin_users (`' . implode('`,`', $cols) . '`) VALUES ' . implode(',', $rows));
    $st = $pdo->prepare('SELECT SUM(status = ?) n FROM app_ulogin_users');
    $st->execute(array('active'));
    assert_eq(1, (int) $st->fetch()['n'], 'SUM(status = ?) must count matching rows as 1');
    $pdo->exec("DELETE FROM app_ulogin_users WHERE username IN ('t_a','t_b')");
});

t('login_logs.result is wide enough for the longest value the server writes', function () {
    // v1.4.0 에서 값이 10 -> 11 로 늘었다. 다음에 더 긴 값이 생기면 여기서 잘린다 —
    // MySQL 은 기본 모드에서 **조용히 자른다**(STRICT 가 아니면 경고만).
    live_or_skip();
    require_once __DIR__ . '/../lib/checks.php';
    $values = ck_result_values_server(h_root());
    if ($values === array()) {
        fail('collected 0 login result values - the collector is blind');
    }
    $pdo = live_pdo('ulogin_test_fresh');
    $st = $pdo->query(
        "SELECT CHARACTER_MAXIMUM_LENGTH n FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA='ulogin_test_fresh' AND TABLE_NAME='app_ulogin_login_logs' AND COLUMN_NAME='result'"
    );
    $row = $st->fetch();
    if ($row === false) {
        fail('login_logs.result column not found');
    }
    $cap = (int) $row['n'];
    $longest = '';
    foreach ($values as $v) {
        if (strlen($v) > strlen($longest)) {
            $longest = $v;
        }
    }
    if (strlen($longest) > $cap) {
        fail('login_logs.result is VARCHAR(' . $cap . ') but the server writes "' . $longest
            . '" (' . strlen($longest) . ' chars) - MySQL truncates this silently outside STRICT mode');
    }
});
