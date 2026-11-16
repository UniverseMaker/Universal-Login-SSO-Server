<?php
declare(strict_types=1);

/**
 * 티어 2 — D-363 quick_check 주기화 (프로세스 첫 요청 1회 + 최대 10분 간격, 실패 캐시는 fail-closed).
 * 시각은 db_sqlite_quick_check_cached() 의 $now 인자로 주입한다. 캐시의 `n` = 실제로 검사를 돈 횟수.
 */

require_once __DIR__ . '/../lib/loader.php';

load_core();
if (!defined('ULOGIN_MIGRATE_CTX')) {
    define('ULOGIN_MIGRATE_CTX', 1);
}
$GLOBALS['T96_SRV'] = h_root() . '/server';
require_once $GLOBALS['T96_SRV'] . '/core/dbport.php';
require_once $GLOBALS['T96_SRV'] . '/core/dbdriver.php';
require_once $GLOBALS['T96_SRV'] . '/core/db.php';
require_once $GLOBALS['T96_SRV'] . '/migrations/lib.php';

function t96_tmpdir(): string
{
    $d = str_replace('\\', '/', sys_get_temp_dir()) . '/ulogin_test_t96_' . bin2hex(random_bytes(4));
    if (!@mkdir($d, 0700, true)) {
        throw new RuntimeException('cannot create temp dir');
    }
    return $d;
}

function t96_rmdir(string $d): void
{
    if (strpos(basename($d), 'ulogin_test_') !== 0) {
        return;
    }
    foreach ((array) @scandir($d) as $f) {
        if ($f !== '.' && $f !== '..') {
            @unlink($d . '/' . $f);
        }
    }
    @rmdir($d);
}

function t96_cache(string $g): array
{
    $j = json_decode((string) @file_get_contents(db_quick_check_cache_file($g)), true);
    return is_array($j) ? $j : array();
}

/** 정상 DB 를 만들어 $fn($pdo, $g) 를 돌린다. */
function t96_with_db(callable $fn): void
{
    require_ext('pdo_sqlite');
    $d = t96_tmpdir();
    try {
        $g   = $d . '/ulogin_test_qc.sqlite';
        $pdo = ulogin_pdo_open_sqlite($g, array('create' => true, 'quick_check' => false));
        mig_sqlite_create_schema($pdo, $GLOBALS['T96_SRV'] . '/schema.sql', 'app_ulogin_', false);
        $fn($pdo, $g);
        $pdo = null;
    } finally {
        t96_rmdir($d);
    }
}

/** 실패 캐시 시나리오 — $fnName 이 실패 캐시를 지키면 true. */
function t96_failed_cache_holds(string $fnName): bool
{
    $held = false;
    t96_with_db(function ($pdo, $g) use ($fnName, &$held) {
        $t = 1000000;
        file_put_contents(db_quick_check_cache_file($g), json_encode(array('pid' => getmypid(), 'at' => $t, 'ok' => false, 'n' => 1)));
        try {
            $fnName($pdo, $g, false, $t + 60);
        } catch (UloginDbUnavailable $e) {
            $held = true;
        }
    });
    return $held;
}

t('first request runs the check once; a second request within 10 minutes does not', function () {
    t96_with_db(function ($pdo, $g) {
        db_sqlite_quick_check_cached($pdo, $g, false, 5000);
        assert_eq(1, t96_cache($g)['n'], 'first request checks');
        db_sqlite_quick_check_cached($pdo, $g, false, 5000 + 599);
        assert_eq(1, t96_cache($g)['n'], 'second request inside 10 minutes must not check');
        assert_eq(5000, t96_cache($g)['at'], 'cache time unchanged');
    });
});

t('a check runs again after 10 minutes', function () {
    t96_with_db(function ($pdo, $g) {
        db_sqlite_quick_check_cached($pdo, $g, false, 5000);
        db_sqlite_quick_check_cached($pdo, $g, false, 5000 + 600);
        assert_eq(2, t96_cache($g)['n'], 're-check at the 10-minute mark');
        assert_eq(5600, t96_cache($g)['at']);
    });
});

t('a new process (different pid in cache) checks on its first request', function () {
    t96_with_db(function ($pdo, $g) {
        file_put_contents(db_quick_check_cache_file($g), json_encode(array('pid' => getmypid() + 1, 'at' => 5000, 'ok' => true, 'n' => 7)));
        db_sqlite_quick_check_cached($pdo, $g, false, 5001);
        assert_eq(8, t96_cache($g)['n'], 'foreign pid cache must not be trusted');
    });
});

t('force (health) checks every time', function () {
    t96_with_db(function ($pdo, $g) {
        db_sqlite_quick_check_cached($pdo, $g, true, 5000);
        db_sqlite_quick_check_cached($pdo, $g, true, 5001);
        assert_eq(5001, t96_cache($g)['at'], 'force ignores the cache and re-checks (cache time advances)');
    });
});

t('a cached failure keeps every request failing (fail-closed) until the next check passes', function () {
    t96_with_db(function ($pdo, $g) {
        $t = 1000000;
        file_put_contents(db_quick_check_cache_file($g), json_encode(array('pid' => getmypid(), 'at' => $t, 'ok' => false, 'n' => 1)));
        assert_throws('UloginDbUnavailable', function () use ($pdo, $g, $t) {
            db_sqlite_quick_check_cached($pdo, $g, false, $t + 1);
        }, 'cached failure -> 503 path');
        assert_throws('UloginDbUnavailable', function () use ($pdo, $g, $t) {
            db_sqlite_quick_check_cached($pdo, $g, false, $t + 599);
        }, 'still failing just before 10 minutes');
        assert_eq(1, t96_cache($g)['n'], 'no re-check while the failure is cached');
        db_sqlite_quick_check_cached($pdo, $g, false, $t + 600);   // 재검사 — 정상 DB 라 통과
        $c = t96_cache($g);
        assert_true($c['ok'] === true && $c['n'] === 2, 'passing re-check clears the failure');
        db_sqlite_quick_check_cached($pdo, $g, false, $t + 601);
    });
});

t('mutation: a variant that ignores the cached failure is caught by the failure scenario', function () {
    assert_true(t96_failed_cache_holds('db_sqlite_quick_check_cached'), 'real function holds the failure');
    $src = (string) file_get_contents($GLOBALS['T96_SRV'] . '/core/db.php');
    assert_true(preg_match('/function db_sqlite_quick_check_cached\(.*?\r?\n}\r?\n/s', $src, $m) === 1, 'function source found');
    $mut = str_replace('function db_sqlite_quick_check_cached(', 'function db_qc_mutant(', $m[0], $cnt1);
    $mut = str_replace("if (\$c['ok'] !== true) {", 'if (false) {', $mut, $cnt2);
    assert_eq(1, $cnt1, 'rename applied');
    assert_eq(1, $cnt2, 'mutation applied');
    eval($mut);
    assert_false(t96_failed_cache_holds('db_qc_mutant'), 'mutant must NOT hold the failure - the scenario sees it');
});

t('a genuinely corrupted database fails the first check and stays failed within 10 minutes', function () {
    require_ext('pdo_sqlite');
    $d = t96_tmpdir();
    try {
        $g   = $d . '/ulogin_test_corrupt.sqlite';
        $pdo = ulogin_pdo_open_sqlite($g, array('create' => true, 'quick_check' => false));
        mig_sqlite_create_schema($pdo, $GLOBALS['T96_SRV'] . '/schema.sql', 'app_ulogin_', false);
        $pdo->exec('PRAGMA journal_mode = DELETE');
        $pdo = null;
        $raw = (string) file_get_contents($g);
        file_put_contents($g, substr($raw, 0, 4096) . str_repeat("\xA5", 4096) . substr($raw, 8192));
        try {
            $p2 = ulogin_pdo_open_sqlite($g, array('create' => false, 'quick_check' => false));
            assert_throws('UloginDbUnavailable', function () use ($p2, $g) {
                db_sqlite_quick_check_cached($p2, $g, false, 9000);
            }, 'first check fails');
            assert_throws('UloginDbUnavailable', function () use ($p2, $g) {
                db_sqlite_quick_check_cached($p2, $g, false, 9100);
            }, 'cached failure holds');
            $p2 = null;
        } catch (PDOException $e) {
            // 열기 단계에서 이미 깨짐으로 드러나는 환경 — 캐시 경로까지 가지 않는다(안전 쪽)
        }
    } finally {
        t96_rmdir($d);
    }
});
