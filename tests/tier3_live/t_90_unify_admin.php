<?php
declare(strict_types=1);

/**
 * 티어 3 — 관리자 계정 통합 CLI (v1.8.11, D-352) — server/tools/unify_admin.php
 *
 * 사본 서버 + 임시 SQLite 에서 CLI 를 실제로 돌린다(MySQL 불필요). 운영 DB·8080 무접촉:
 * 사본은 sys_get_temp_dir()/ulogin_test_ua_* 아래뿐(접두 가드), 서버는 띄우지 않는다. glob() 미사용.
 *
 * 덮는 것: dry-run 무변경 · 개명(충돌 거절 포함) · 연결(링크 사건 발행) · 이미 연결된 것/삭제된 계정 건너뜀 ·
 *          재실행 멱등 · 나중에 생긴 opsuser 계정은 다시 돌리면 붙음 · 웹 접근 404.
 * 변이(D-186): dry-run 가드를 지운 사본에서 같은 시나리오가 실패해야 한다.
 */

require_once __DIR__ . '/../lib/loader.php';

load_core();
if (!defined('ULOGIN_MIGRATE_CTX')) {
    define('ULOGIN_MIGRATE_CTX', 1);
}
require_once h_root() . '/server/core/dbport.php';
require_once h_root() . '/server/core/dbdriver.php';
require_once h_root() . '/server/core/db.php';
require_once h_root() . '/server/migrations/lib.php';

$GLOBALS['UAT'] = array('boxes' => array(), 'registered' => false);

function uat_rm(string $p): void
{
    if (strpos(h_norm($p), '/ulogin_test_ua_') === false) {
        return;   // 접두 가드
    }
    uat_rm_inner($p);
}

function uat_rm_inner(string $p): void
{
    if (is_file($p) || is_link($p)) {
        @unlink($p);
        return;
    }
    if (!is_dir($p)) {
        return;
    }
    foreach (scandir($p) as $n) {
        if ($n !== '.' && $n !== '..') {
            uat_rm_inner($p . '/' . $n);
        }
    }
    @rmdir($p);
}

function uat_copy(string $src, string $dst): int
{
    @mkdir($dst, 0777, true);
    $n = 0;
    foreach (scandir($src) as $e) {
        if ($e === '.' || $e === '..' || $e === 'config.php' || $e === '.install.lock' || $e === '.installed') {
            continue;
        }
        if (is_dir($src . '/' . $e)) {
            $n += uat_copy($src . '/' . $e, $dst . '/' . $e);
        } else {
            copy($src . '/' . $e, $dst . '/' . $e);
            $n++;
        }
    }
    return $n;
}

function uat_teardown(): void
{
    foreach ($GLOBALS['UAT']['boxes'] as $b) {
        uat_rm($b);
    }
    $GLOBALS['UAT']['boxes'] = array();
}

function uat_set_define(string $src, string $name, string $phpValue): string
{
    $re = '/^define\(\'' . preg_quote($name, '/') . '\',\s*[^\n]*?\);/m';
    $n = 0;
    $out = preg_replace($re, 'define(\'' . $name . '\', ' . $phpValue . ');', $src, 1, $n);
    if ($n !== 1 || !is_string($out)) {
        fail('HARNESS-FAIL config.sample.php: define(' . $name . ') not found exactly once');
    }
    return $out;
}

/** 사본 + SQLite + 픽스처. $mut = [[상대경로, 찾을 문자열, 바꿀 문자열]] — 각각 정확히 1회 맞아야 한다. */
function uat_box(string $key, array $mut = array()): array
{
    require_ext('pdo_sqlite');
    $ini = php_ini_loaded_file();
    if (!is_string($ini) || $ini === '' || !is_file($ini)) {
        skip('runner has no php.ini (-n?) — child would lack pdo_sqlite; run with -c <ini>');
    }
    if (!$GLOBALS['UAT']['registered']) {
        $GLOBALS['UAT']['registered'] = true;
        register_shutdown_function('uat_teardown');
    }
    $base = h_norm(sys_get_temp_dir()) . '/ulogin_test_ua_' . getmypid() . '_' . bin2hex(random_bytes(3)) . '_' . $key;
    $GLOBALS['UAT']['boxes'][] = $base;
    $root = $base . '/root';
    $n = uat_copy(h_root() . '/server', $root . '/server');
    if ($n < 50 || !is_file($root . '/server/tools/unify_admin.php')) {
        fail('HARNESS-FAIL sandbox copy has ' . $n . ' files or misses tools/unify_admin.php (scandir blind?)');
    }
    foreach ($mut as $m) {
        list($rel, $from, $to) = $m;
        $s = (string) @file_get_contents($root . '/' . $rel);
        $c = substr_count($s, $from);
        if ($c !== 1) {
            fail('HARNESS-FAIL mutation "' . substr($from, 0, 60) . '" matched ' . $c . ' times in ' . $rel);
        }
        file_put_contents($root . '/' . $rel, str_replace($from, $to, $s));
    }
    @mkdir($base . '/data', 0777, true);
    $db = $base . '/data/ulogin_test_ua.sqlite';
    $pdo = new PDO('sqlite:' . $db, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    mig_sqlite_create_schema($pdo, $root . '/server/schema.sql', 'app_ulogin_', true);

    $cfg = (string) file_get_contents(h_root() . '/server/config.sample.php');
    $cfg = uat_set_define($cfg, 'DB_DRIVER', "'sqlite'");
    $cfg = uat_set_define($cfg, 'DB_SQLITE_PATH', var_export($db, true));
    $cfg = uat_set_define($cfg, 'SECRET_ENC_KEY', "'" . bin2hex(random_bytes(32)) . "'");
    $cfg = uat_set_define($cfg, 'APP_KEY', "'" . bin2hex(random_bytes(32)) . "'");
    $cfg = uat_set_define($cfg, 'FORCE_HTTPS', 'false');
    $cfg = uat_set_define($cfg, 'COOKIE_SECURE', 'false');
    file_put_contents($root . '/server/config.php', $cfg);

    $now = gmdate('Y-m-d H:i:s');
    $insS = $pdo->prepare('INSERT INTO app_ulogin_services (service_id, service_name, service_secret_hash, is_confidential,'
        . ' redirect_whitelist, auto_join, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)');
    foreach (array('svc-a', 'svc-b', 'svc-c', 'svc-d', 'svc-e') as $sid) {
        $insS->execute(array($sid, 'Svc ' . $sid, password_hash('x' . $sid, PASSWORD_DEFAULT), 1,
            json_encode(array('http://127.0.0.1:9/cb')), 1, 'active', $now, $now));
    }
    $insU = $pdo->prepare('INSERT INTO app_ulogin_users (uuid, username, email, password_hash, status, account_level, role,'
        . ' billing_tier, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $ids = array();
    foreach (array('admin' => 'superadmin', 'other' => 'user') as $name => $role) {
        $insU->execute(array(gen_uuid4(), $name, $name . '@test.invalid', password_hash('Pw-' . $name, PASSWORD_DEFAULT),
            'active', 'basic', $role, 'free', $now, $now));
        $ids[$name] = (int) $pdo->lastInsertId();
    }
    $insE = $pdo->prepare('INSERT INTO app_ulogin_ext_accounts (service_id, external_id, username, ext_status, state, user_id,'
        . ' row_version, source_sent_at, first_seen_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $ext = array(
        array('svc-a', '11', 'opsuser', 'active', 'new', null),
        array('svc-b', '12', 'OpsUser', 'active', 'ignored', null),
        array('svc-c', '13', 'opsuser', 'deleted', 'new', null),
        array('svc-d', '14', 'opsuser', 'active', 'linked', $ids['other']),
        array('svc-e', '15', 'someone', 'active', 'new', null),
    );
    foreach ($ext as $e) {
        $insE->execute(array($e[0], $e[1], $e[2], $e[3], $e[4], $e[5], 1, $now, $now, $now));
    }
    $pdo = null;
    return array('root' => $root, 'base' => $base, 'db' => $db, 'ini' => $ini);
}

function uat_pdo(array $B): PDO
{
    return new PDO('sqlite:' . $B['db'], null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
}

function uat_q(array $B, string $sql, array $p = array()): array
{
    $st = uat_pdo($B)->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
}

function uat_cli(array $B, string $args): array
{
    return proc_run_limited(h_q(h_php_bin()) . ' -c ' . h_q($B['ini']) . ' ' . h_q($B['root'] . '/server/tools/unify_admin.php')
        . ' ' . $args, 120.0, $B['base']);
}

function uat_state(array $B, string $sid): string
{
    $r = uat_q($B, 'SELECT state FROM app_ulogin_ext_accounts WHERE service_id = ?', array($sid));
    return (string) $r[0]['state'];
}

/** 시나리오 전체. 변이 사본에서는 어딘가에서 fail 해야 한다. */
function uat_scenario(array $B): void
{
    // 0) 웹 접근 불가 — CLI 가 아니면 404 (여기서는 가드 문자열이 첫 실행문인지로 확인)
    $src = (string) file_get_contents($B['root'] . '/server/tools/unify_admin.php');
    assert_match('/^<\?php\s+declare\(strict_types=1\);\s+\/\/[^\n]*\nif \(PHP_SAPI !== \'cli\'\) \{ http_response_code\(404\); exit; \}/', $src, 'CLI guard first');

    // 1) dry-run 은 아무것도 바꾸지 않는다
    $r = uat_cli($B, '--rename-user=admin:opsuser --link-username=opsuser --to=opsuser');
    $users = uat_q($B, 'SELECT username FROM app_ulogin_users ORDER BY id');
    assert_eq('admin', $users[0]['username'], 'dry-run must not rename');
    assert_contains('DRY-RUN', $r['out'], 'dry-run banner');
    assert_eq('new', uat_state($B, 'svc-a'), 'dry-run must not link');

    // 2) 충돌 — 새 아이디가 이미 다른 사용자 것이면 거절(exit 3), 무변경
    $r = uat_cli($B, '--rename-user=admin:other --apply');
    assert_eq(3, $r['exit'], 'rename conflict exit');
    assert_eq('admin', uat_q($B, 'SELECT username FROM app_ulogin_users ORDER BY id')[0]['username'], 'conflict leaves name');
    $r = uat_cli($B, '--rename-user=admin:ab --apply');
    assert_eq(2, $r['exit'], 'bad format exit');

    // 3) 개명 + 연결 apply
    $r = uat_cli($B, '--rename-user=admin:opsuser --link-username=opsuser --to=opsuser --apply');
    assert_eq(0, $r['exit'], 'apply exit: ' . $r['out']);
    assert_eq('opsuser', uat_q($B, 'SELECT username FROM app_ulogin_users ORDER BY id')[0]['username'], 'renamed');
    $log = uat_q($B, 'SELECT before_json, after_json FROM app_ulogin_admin_logs WHERE action = ?', array('user.rename'));
    assert_eq(1, count($log), 'rename audited');
    assert_eq('linked', uat_state($B, 'svc-a'), 'svc-a linked');
    assert_eq('linked', uat_state($B, 'svc-b'), 'svc-b (ignored, case-insensitive) linked');
    assert_eq('new', uat_state($B, 'svc-c'), 'deleted ext account skipped');
    assert_eq('linked', uat_state($B, 'svc-d'), 'svc-d stays');
    $own = uat_q($B, 'SELECT user_id FROM app_ulogin_ext_accounts WHERE service_id = ?', array('svc-d'));
    $other = uat_q($B, 'SELECT id FROM app_ulogin_users WHERE username = ?', array('other'));
    assert_eq((int) $other[0]['id'], (int) $own[0]['user_id'], 'svc-d still owned by other');
    assert_eq('new', uat_state($B, 'svc-e'), 'other username untouched');
    assert_contains('svc-a - 연결', $r['out'], 'report line svc-a');
    assert_contains('svc-d - 건너뜀', $r['out'], 'report line svc-d');
    assert_not_contains('Pw-', $r['out'], 'no secrets in output');

    // 4) 링크 사건이 발행됐다(시스템이 받는 feed)
    $items = uat_q($B, 'SELECT service_id, op, status, result_user_id FROM app_ulogin_link_job_items WHERE op = ? AND status = ?',
        array('link', 'done'));
    assert_eq(2, count($items), 'two done link items = two link_events');
    $dsId = (int) uat_q($B, 'SELECT id FROM app_ulogin_users WHERE username = ?', array('opsuser'))[0]['id'];
    foreach ($items as $it) {
        assert_eq($dsId, (int) $it['result_user_id'], 'event points to opsuser');
    }

    // 5) 재실행 멱등 — 아무것도 더 바뀌지 않는다
    $before = array(uat_q($B, 'SELECT COUNT(*) AS c FROM app_ulogin_link_job_items'), uat_q($B, 'SELECT COUNT(*) AS c FROM app_ulogin_admin_logs'));
    $r = uat_cli($B, '--rename-user=admin:opsuser --link-username=opsuser --to=opsuser --apply');
    assert_eq(0, $r['exit'], 're-run exit: ' . $r['out']);
    $after = array(uat_q($B, 'SELECT COUNT(*) AS c FROM app_ulogin_link_job_items'), uat_q($B, 'SELECT COUNT(*) AS c FROM app_ulogin_admin_logs'));
    assert_eq($before, $after, 're-run changes nothing');
    assert_contains('이미 opsuser 에 연결됨', $r['out'], 're-run reports already linked');

    // 6) 나중에 생긴 opsuser 계정은 다시 돌리면 붙는다
    $pdo = uat_pdo($B);
    $now = gmdate('Y-m-d H:i:s');
    $pdo->prepare('INSERT INTO app_ulogin_ext_accounts (service_id, external_id, username, ext_status, state, row_version,'
        . ' source_sent_at, first_seen_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute(array('svc-e', '99', 'opsuser', 'active', 'new', 1, $now, $now, $now));
    $pdo = null;
    $r = uat_cli($B, '--link-username=opsuser --to=opsuser --apply');
    assert_eq(0, $r['exit'], 'late exit: ' . $r['out']);
    $st = uat_q($B, 'SELECT state FROM app_ulogin_ext_accounts WHERE service_id = ? AND external_id = ?', array('svc-e', '99'));
    assert_eq('linked', $st[0]['state'], 'late account linked');
}

t('T1 unify_admin: dry-run, rename, link, idempotent re-run, late account, link events', function () {
    uat_scenario(uat_box('main'));
});

t('T1b unify_admin --service: only that service, alias accepted, unknown exits 3', function () {
    $B = uat_box('svc');
    $r = uat_cli($B, '--rename-user=admin:opsuser --apply');
    assert_eq(0, $r['exit'], 'rename exit: ' . $r['out']);
    $r = uat_cli($B, '--link-username=opsuser --to=opsuser --service=svc-a --apply');
    assert_eq(0, $r['exit'], 'svc exit: ' . $r['out']);
    assert_eq('linked', uat_state($B, 'svc-a'), 'scoped service linked');
    assert_eq('ignored', uat_state($B, 'svc-b'), 'other service untouched');
    assert_not_contains('svc-b', $r['out'], 'other service not even reported');
    $r = uat_cli($B, '--link-username=opsuser --to=opsuser --service=nope --apply');
    assert_eq(3, $r['exit'], 'unknown service exit');
    assert_eq('ignored', uat_state($B, 'svc-b'), 'unknown service changes nothing');
    $pdo = uat_pdo($B);
    $pdo->exec("UPDATE app_ulogin_services SET service_id = 'svc-b2', former_service_id = 'svc-b' WHERE service_id = 'svc-b'");
    $pdo->exec("UPDATE app_ulogin_ext_accounts SET service_id = 'svc-b2' WHERE service_id = 'svc-b'");
    $pdo = null;
    $r = uat_cli($B, '--link-username=opsuser --to=opsuser --service=svc-b --apply');
    assert_eq(0, $r['exit'], 'alias exit: ' . $r['out']);
    assert_eq('linked', uat_state($B, 'svc-b2'), 'alias resolves to canonical');
});

t('T2b mutant: --service filter removed must be caught', function () {
    $B = uat_box('svcmut', array(array('server/tools/unify_admin.php', '$svcSql = \' AND service_id = ?\';', '$svcSql = \' AND ? IS NOT NULL\';')));
    $died = null;
    try {
        uat_cli($B, '--rename-user=admin:opsuser --apply');
        $r = uat_cli($B, '--link-username=opsuser --to=opsuser --service=svc-a --apply');
        assert_eq('ignored', uat_state($B, 'svc-b'), 'other service untouched');
    } catch (Throwable $e) {
        $died = $e;
    }
    if ($died === null) {
        fail('mutant SURVIVED - scope filter unguarded');
    }
});

t('T2 mutant: dry-run guard removed must be caught', function () {
    $B = uat_box('mut', array(array('server/tools/unify_admin.php', '    } elseif (!$apply) {', '    } elseif (false) {')));
    $died = null;
    try {
        uat_scenario($B);
    } catch (Throwable $e) {
        $died = $e;
    }
    if ($died === null) {
        fail('mutant SURVIVED - scenario does not guard the dry-run line');
    }
    note('mutant caught: ' . substr($died->getMessage(), 0, 120));
});

uat_teardown();
