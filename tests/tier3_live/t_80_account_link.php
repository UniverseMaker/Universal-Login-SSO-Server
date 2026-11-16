<?php
declare(strict_types=1);

/**
 * 티어 3 — 시스템 계정 가져오기·이관·연결 (v1.8.4) — 설계 92 T절 T1~T11 · D-328 · D-331
 *
 * **SQLite 로 실제로 돈다**(MySQL 불필요 — t_61 과 같은 방식). 설치 루트 사본 + 임시 SQLite +
 * `php -S`(라우터 포함)로 실제 엔드포인트를 부르고, 경합(T1)은 자식 PHP N개가 같은 DB 파일에서
 * 실제 commit 엔드포인트를 동시에 돈다(`php -S` 는 Windows 에서 작업자 1개라 HTTP 로는 겹치지 않는다).
 *
 * 변이(D-186): 시나리오마다 「그 줄을 지운 사본」을 만들어 **같은 시나리오가 실패해야** 통과다.
 * 변이 치환이 정확히 한 번 맞지 않으면 HARNESS-FAIL(치환 0건인데 결과를 내는 거짓 초록을 막는다).
 *
 * 무접촉: 사본·DB 는 `sys_get_temp_dir()/ulogin_test_al_*` 아래뿐(접두 가드). 포트는 OS 가 준 빈 포트
 * (8080 금지). 종료는 `taskkill /F /T /PID <자기 PID>`(bypass_shell 이라 PID 가 php.exe 자신).
 * `glob()` 을 쓰지 않는다. 자식 `php -S` 에는 러너가 받은 ini 를 그대로 준다(-n 이면 pdo_sqlite 없음 → SKIP).
 *
 * 못 잡는 것: MySQL `FOR UPDATE` 행 잠금(SQLite 경로만 잰다). MySQL 업그레이드 경로의 0005 는
 * t_20_migration_paths 가 MySQL 이 있을 때 덮는다(없으면 SKIP).
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

$GLOBALS['ALT'] = array('boxes' => array(), 'registered' => false);

const ALT_PW = 'Passw0rd!x9';

// =====================================================================
// 하네스
// =====================================================================

function alt_rm_rf(string $p): void
{
    if (strpos(h_norm($p), '/ulogin_test_al_') === false) {
        return;   // 접두 가드 — 우리 사본이 아니면 지우지 않는다
    }
    alt_rm_inner($p);
}

function alt_rm_inner(string $p): void
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
            alt_rm_inner($p . '/' . $n);
        }
    }
    @rmdir($p);
}

function alt_copy(string $src, string $dst): int
{
    @mkdir($dst, 0777, true);
    $n = 0;
    foreach (scandir($src) as $e) {
        if ($e === '.' || $e === '..' || $e === 'config.php' || $e === '.install.lock' || $e === '.installed') {
            continue;
        }
        if (is_dir($src . '/' . $e)) {
            $n += alt_copy($src . '/' . $e, $dst . '/' . $e);
        } else {
            copy($src . '/' . $e, $dst . '/' . $e);
            $n++;
        }
    }
    return $n;
}

function alt_teardown(): void
{
    foreach ($GLOBALS['ALT']['boxes'] as $b) {
        if (!empty($b['pid']) && DIRECTORY_SEPARATOR === '\\') {
            $o = array();
            $rc = 0;
            @exec('taskkill /F /T /PID ' . (int) $b['pid'] . ' 2>&1', $o, $rc);
        }
        if (isset($b['proc']) && is_resource($b['proc'])) {
            @proc_terminate($b['proc'], 9);
            @proc_close($b['proc']);
        }
    }
    usleep(200000);
    foreach ($GLOBALS['ALT']['boxes'] as $b) {
        alt_rm_rf($b['base']);
    }
    $GLOBALS['ALT']['boxes'] = array();
}

function alt_ini(): string
{
    $ini = php_ini_loaded_file();
    if (!is_string($ini) || $ini === '' || !is_file($ini)) {
        skip('runner has no php.ini (-n?) — children would lack pdo_sqlite; run with -c <ini>');
    }
    return $ini;
}

function alt_free_port(): int
{
    for ($i = 0; $i < 10; $i++) {
        $srv = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($srv === false) {
            continue;
        }
        $name = (string) stream_socket_get_name($srv, false);
        fclose($srv);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        if ($port <= 1024 || $port === 8080) {
            continue;
        }
        $probe = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
        if ($probe !== false) {
            fclose($probe);
            continue;
        }
        return $port;
    }
    skip('no free local port');
    return 0;
}

function alt_set_define(string $src, string $name, string $phpValue): string
{
    $re = '/^define\(\'' . preg_quote($name, '/') . '\',\s*[^\n]*?\);/m';
    $n = 0;
    $out = preg_replace($re, 'define(\'' . $name . '\', ' . $phpValue . ');', $src, 1, $n);
    if ($n !== 1 || !is_string($out)) {
        fail('HARNESS-FAIL config.sample.php: define(' . $name . ') not found exactly once');
    }
    return $out;
}

/**
 * 사본 하나. `$mut` = [[상대경로, 찾을 문자열, 바꿀 문자열], …] — **각각 정확히 1회** 맞아야 한다.
 * 서버는 `$serve` 일 때만 띄운다(경합·마이그레이션 시나리오는 서버가 필요 없다).
 */
function alt_box(string $key, array $mut = array(), bool $serve = true): array
{
    require_ext('pdo_sqlite');
    $ini = alt_ini();
    if (!$GLOBALS['ALT']['registered']) {
        $GLOBALS['ALT']['registered'] = true;
        register_shutdown_function('alt_teardown');
    }
    $base = h_norm(sys_get_temp_dir()) . '/ulogin_test_al_' . getmypid() . '_' . bin2hex(random_bytes(3)) . '_' . $key;
    $root = $base . '/root';
    $n  = alt_copy(h_root() . '/server', $root . '/server');
    $n += alt_copy(h_root() . '/public', $root . '/public');
    @mkdir($root . '/tools', 0777, true);
    copy(h_root() . '/tools/router-ulogin.php', $root . '/tools/router-ulogin.php');
    $B = array('base' => $base, 'root' => $root, 'key' => $key, 'pid' => 0, 'proc' => null, 'port' => 0);
    $GLOBALS['ALT']['boxes'][] = &$B;
    if ($n < 50 || !is_file($root . '/server/api/admin/account_link_ops.php')) {
        fail('HARNESS-FAIL sandbox copy has ' . $n . ' files or misses the endpoints under test (scandir blind?)');
    }
    foreach ($mut as $m) {
        list($rel, $from, $to) = $m;
        $f = $root . '/' . $rel;
        $s = (string) @file_get_contents($f);
        $c = substr_count($s, $from);
        if ($c !== 1) {
            fail('HARNESS-FAIL mutation "' . substr($from, 0, 60) . '" matched ' . $c . ' times in ' . $rel);
        }
        file_put_contents($f, str_replace($from, $to, $s));
    }
    @mkdir($base . '/data', 0777, true);
    $db = $base . '/data/ulogin_test_al.sqlite';
    $B['db'] = $db;
    $pdo = new PDO('sqlite:' . $db, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    mig_sqlite_create_schema($pdo, $root . '/server/schema.sql', 'app_ulogin_', true);
    $pdo = null;

    $port = alt_free_port();
    $B['port'] = $port;
    $cfg = (string) file_get_contents(h_root() . '/server/config.sample.php');
    $cfg = alt_set_define($cfg, 'DB_DRIVER', "'sqlite'");
    $cfg = alt_set_define($cfg, 'DB_SQLITE_PATH', var_export($db, true));
    $cfg = alt_set_define($cfg, 'SECRET_ENC_KEY', "'" . bin2hex(random_bytes(32)) . "'");
    $cfg = alt_set_define($cfg, 'APP_KEY', "'" . bin2hex(random_bytes(32)) . "'");
    $cfg = alt_set_define($cfg, 'FORCE_HTTPS', 'false');
    $cfg = alt_set_define($cfg, 'COOKIE_SECURE', 'false');
    $cfg = alt_set_define($cfg, 'BASE_URL', "'http://127.0.0.1:" . $port . "'");
    $cfg = alt_set_define($cfg, 'ALLOW_REGISTER', 'true');
    file_put_contents($root . '/server/config.php', $cfg);
    $B['fx'] = alt_seed($B);

    if ($serve) {
        $log = $base . '/server.log';
        $B['log'] = $log;
        $cmd = h_q(h_php_bin()) . ' -c ' . h_q($ini) . ' -d error_log=' . h_q($base . '/php_error.log')
            . ' -S 127.0.0.1:' . $port . ' -t ' . h_q($root) . ' ' . h_q($root . '/tools/router-ulogin.php');
        $desc = array(0 => array('pipe', 'r'), 1 => array('file', $log, 'a'), 2 => array('file', $log, 'a'));
        $pipes = array();
        $proc = @proc_open($cmd, $desc, $pipes, $root, null, array('bypass_shell' => true));
        if (!is_resource($proc)) {
            skip('cannot start php -S');
        }
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        $st = proc_get_status($proc);
        $B['proc'] = $proc;
        $B['pid']  = (int) $st['pid'];
        $token = bin2hex(random_bytes(8));
        file_put_contents($root . '/public/version.txt', $token . "\n");
        $ok = false;
        for ($i = 0; $i < 100; $i++) {
            $r = alt_http($B, 'GET', '/version.txt');
            if ($r['status'] === 200 && trim($r['body']) === $token) {
                $ok = true;
                break;
            }
            usleep(100000);
        }
        if (!$ok) {
            fail('HARNESS-FAIL php -S on port ' . $port . ' did not answer with this run\'s sentinel');
        }
    }
    $copy = $B;
    unset($B);
    return $copy;
}

function alt_pdo(array $B): PDO
{
    $pdo = new PDO('sqlite:' . $B['db'], null, null, array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
    $pdo->exec('PRAGMA busy_timeout = 8000');
    return $pdo;
}

function alt_q(array $B, string $sql, array $p = array()): array
{
    $pdo = alt_pdo($B);
    $st = $pdo->prepare($sql);
    $st->execute($p);
    $rows = $st->fetchAll();
    $pdo = null;
    return $rows;
}

function alt_x(array $B, string $sql, array $p = array()): int
{
    $pdo = alt_pdo($B);
    $st = $pdo->prepare($sql);
    $st->execute($p);
    $n = $st->rowCount();
    $pdo = null;
    return $n;
}

function alt_now(int $off = 0): string
{
    return gmdate('Y-m-d H:i:s', time() + $off);
}

function alt_iso(int $off = 0): string
{
    return gmdate('Y-m-d\TH:i:s\Z', time() + $off);
}

/** 원시 HTTP/1.0. $body 배열은 JSON 으로. */
function alt_http(array $B, string $method, string $path, $body = null, ?string $bearer = null): array
{
    $s = @fsockopen('127.0.0.1', (int) $B['port'], $errno, $errstr, 5.0);
    if ($s === false) {
        return array('status' => 0, 'body' => '', 'json' => null, 'ms' => 0.0);
    }
    stream_set_timeout($s, 30);
    $json = $body === null ? null : (is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $req = $method . ' ' . $path . " HTTP/1.0\r\nHost: 127.0.0.1:" . $B['port'] . "\r\nConnection: close\r\n";
    if ($bearer !== null) {
        $req .= 'Authorization: Bearer ' . $bearer . "\r\n";
    }
    if ($json !== null) {
        $req .= "Content-Type: application/json\r\nContent-Length: " . strlen($json) . "\r\n";
    }
    $t0 = microtime(true);
    fwrite($s, $req . "\r\n" . ($json === null ? '' : $json));
    $raw = '';
    while (!feof($s)) {
        $c = fread($s, 65536);
        if ($c === false || ($c === '' && stream_get_meta_data($s)['timed_out'])) {
            break;
        }
        $raw .= $c;
    }
    fclose($s);
    $ms = (microtime(true) - $t0) * 1000;
    $sep = strpos($raw, "\r\n\r\n");
    $head = $sep === false ? $raw : substr($raw, 0, $sep);
    $bodyS = $sep === false ? '' : substr($raw, $sep + 4);
    $status = preg_match('~^HTTP/\d\.\d (\d{3})~', $head, $m) ? (int) $m[1] : 0;
    $j = json_decode($bodyS, true);
    return array('status' => $status, 'body' => $bodyS, 'json' => is_array($j) ? $j : null, 'ms' => $ms);
}

function alt_code(array $r): string
{
    return is_array($r['json']) && isset($r['json']['error']['code']) ? (string) $r['json']['error']['code'] : '';
}

function alt_field(array $r): ?string
{
    return is_array($r['json']) && isset($r['json']['error']['field']) ? (string) $r['json']['error']['field'] : null;
}

function alt_ok(array $r, string $what): array
{
    if ($r['status'] !== 200 || !is_array($r['json']) || $r['json']['ok'] !== true) {
        fail($what . ': expected 200 ok, got ' . $r['status'] . ' ' . substr($r['body'], 0, 400));
    }
    return $r['json']['data'];
}

function alt_err(array $r, int $status, string $code, string $what): void
{
    if ($r['status'] !== $status || alt_code($r) !== $code) {
        fail($what . ': expected ' . $status . ' ' . $code . ', got ' . $r['status'] . ' ' . substr($r['body'], 0, 400));
    }
}

// =====================================================================
// 픽스처
// =====================================================================

function alt_seed(array $B): array
{
    $pdo = alt_pdo($B);
    $now = alt_now();
    $hash = password_hash(ALT_PW, PASSWORD_DEFAULT);
    $fx = array('users' => array(), 'tok' => array(), 'secret' => array());
    $svcs = array('svc-a', 'svc-l', 'svc-m', 'svc-c', 'svc-r');
    $insS = $pdo->prepare('INSERT INTO app_ulogin_services (service_id, service_name, service_secret_hash, is_confidential,'
        . ' redirect_whitelist, auto_join, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)');
    foreach ($svcs as $sid) {
        $sec = 'sec_' . $sid . '_' . bin2hex(random_bytes(16));
        $fx['secret'][$sid] = $sec;
        $insS->execute(array($sid, 'Svc ' . $sid, password_hash($sec, PASSWORD_DEFAULT), 1,
            json_encode(array('http://127.0.0.1:9/cb')), 1, 'active', $now, $now));
    }
    $insS->execute(array('svc-pub', 'Public', null, 0, json_encode(array('http://127.0.0.1:9/cb')), 1, 'active', $now, $now));
    $insU = $pdo->prepare('INSERT INTO app_ulogin_users (uuid, username, email, password_hash, status, account_level, role,'
        . ' billing_tier, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $people = array('sa' => 'superadmin', 'sa2' => 'superadmin', 'ad' => 'admin', 'mg' => 'manager', 'ov' => 'user');
    for ($i = 1; $i <= 9; $i++) {
        $people['u' . $i] = 'user';
    }
    foreach ($people as $k => $role) {
        $uuid = gen_uuid4();
        $name = 'alt_' . $k;
        $insU->execute(array($uuid, $name, $name . '@test.invalid', $hash, 'active', 'basic', $role, 'free', $now, $now));
        $fx['users'][$k] = array('id' => (int) $pdo->lastInsertId(), 'uuid' => $uuid, 'username' => $name);
    }
    // ov: 전역 user + svc-a 오버라이드 superadmin (T3 — 관리자 API 는 전역 role 만 본다)
    $pdo->prepare('INSERT INTO app_ulogin_user_services (user_id, service_id, role, status, joined_at) VALUES (?,?,?,?,?)')
        ->execute(array($fx['users']['ov']['id'], 'svc-a', 'superadmin', 'active', $now));
    foreach (array('sa' => null, 'sa2' => null, 'ad' => null, 'mg' => null, 'ov' => 'svc-a') as $k => $sid) {
        $fx['tok'][$k] = alt_token($pdo, $fx['users'][$k]['id'], $sid);
    }
    $pdo = null;
    return $fx;
}

function alt_token(PDO $pdo, int $uid, ?string $sid, string $type = 'token'): string
{
    $tok = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO app_ulogin_sessions (session_id, user_id, service_id, auth_type, access_token_hash,'
        . ' issued_at, expires_at, last_seen_at, ip, user_agent) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute(array(bin2hex(random_bytes(32)), $uid, $sid, $type, hash('sha256', $tok),
            alt_now(-5), alt_now(3600), alt_now(-5), '127.0.0.1', 'alt'));
    return $tok;
}

function alt_token_b(array $B, int $uid, ?string $sid): string
{
    $pdo = alt_pdo($B);
    $t = alt_token($pdo, $uid, $sid);
    $pdo = null;
    return $t;
}

function alt_reset_rl(array $B): void
{
    alt_x($B, 'DELETE FROM app_ulogin_rate_limits');
}

/** 계정 목록을 새 sync_id 1페이지로 보낸다. */
function alt_push(array $B, string $sid, array $accounts, array $over = array()): array
{
    $body = array_merge(array(
        'service_id' => $sid, 'service_secret' => $B['fx']['secret'][$sid], 'sync_id' => gen_uuid4(), 'page' => 1,
        'final' => false, 'sent_at' => alt_iso(), 'accounts' => $accounts,
    ), $over);
    return alt_http($B, 'POST', '/server/api/sso/accounts_push.php', $body);
}

function alt_ops(array $B, string $who, array $body): array
{
    return alt_http($B, 'POST', '/server/api/admin/account_link_ops.php', $body, $B['fx']['tok'][$who]);
}

function alt_get(array $B, string $who, string $qs): array
{
    return alt_http($B, 'GET', '/server/api/admin/account_links.php?' . $qs, null, $B['fx']['tok'][$who]);
}

function alt_ext(array $B, string $sid, string $eid): ?array
{
    $r = alt_q($B, 'SELECT * FROM app_ulogin_ext_accounts WHERE service_id = ? AND external_id = ?', array($sid, $eid));
    return $r === array() ? null : $r[0];
}

/** plan 1개(항목 1개) → commit. 반환: [plan data, commit data] */
function alt_link(array $B, string $who, string $op, string $sid, string $eid, ?string $uuid): array
{
    $e = alt_ext($B, $sid, $eid);
    $it = array('op' => $op, 'service_id' => $sid, 'external_id' => $eid,
        'expect_version' => $e === null ? null : (int) $e['row_version']);
    if ($uuid !== null) {
        $it['target'] = array('uuid' => $uuid);
    }
    $p = alt_ok(alt_ops($B, $who, array('action' => 'plan', 'items' => array($it))), 'plan ' . $op . ' ' . $eid);
    $c = alt_ok(alt_ops($B, $who, array('action' => 'commit', 'plan_id' => $p['plan_id'])), 'commit ' . $op . ' ' . $eid);
    return array($p, $c);
}

function alt_plan1(array $B, string $who, string $op, string $sid, string $eid, ?string $uuid): array
{
    $e = alt_ext($B, $sid, $eid);
    $it = array('op' => $op, 'service_id' => $sid, 'external_id' => $eid,
        'expect_version' => $e === null ? null : (int) $e['row_version']);
    if ($uuid !== null) {
        $it['target'] = array('uuid' => $uuid);
    }
    return alt_ok(alt_ops($B, $who, array('action' => 'plan', 'items' => array($it))), 'plan ' . $op . ' ' . $eid);
}

/** 표 전체 덤프(문자열). 비밀 원문이 DB 어디에도 없는지 볼 때 쓴다. */
function alt_dump_all(array $B): string
{
    $out = '';
    foreach (alt_q($B, "SELECT name FROM sqlite_master WHERE type = 'table'") as $t) {
        $out .= json_encode(alt_q($B, 'SELECT * FROM "' . $t['name'] . '"'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return $out;
}

function alt_dump(array $B, string $table): string
{
    return json_encode(alt_q($B, 'SELECT * FROM ' . $table . ' ORDER BY id'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function alt_assert_keys(array $want, array $data, string $what): void
{
    assert_eq($want, array_keys($data), $what . ' data keys');
}

function alt_code_seed(array $B, int $uid, string $sid): string
{
    $code = bin2hex(random_bytes(32));
    alt_x($B, 'INSERT INTO app_ulogin_auth_codes (code_hash, user_id, service_id, redirect_uri, state, code_challenge,'
        . ' expires_at, used_at, created_ip, created_at) VALUES (?,?,?,?,?,?,?,NULL,?,?)',
        array(hash('sha256', $code), $uid, $sid, 'http://127.0.0.1:9/cb', 'st', null, alt_now(60), '127.0.0.1', alt_now()));
    return $code;
}

function alt_exchange(array $B, int $uid, string $sid): array
{
    $code = alt_code_seed($B, $uid, $sid);
    return alt_ok(alt_http($B, 'POST', '/server/api/auth/exchange.php', array('code' => $code, 'service_id' => $sid,
        'redirect_uri' => 'http://127.0.0.1:9/cb', 'service_secret' => $B['fx']['secret'][$sid])), 'exchange ' . $sid);
}

/** 변이 사본에서 시나리오가 **실패해야** 한다. */
function alt_mutant(string $key, array $mut, callable $scenario, bool $serve = true): void
{
    $B = alt_box('mut_' . $key, $mut, $serve);
    $died = null;
    try {
        $scenario($B);
    } catch (Throwable $e) {
        $died = $e;
    }
    if ($died === null) {
        fail('mutant ' . $key . ' SURVIVED — the scenario does not guard the line it claims to');
    }
    note('mutant ' . $key . ' caught: ' . substr($died->getMessage(), 0, 140));
}

// =====================================================================
// 시나리오
// =====================================================================

/** T4 — push 형태 · 재전송 3겹 · final · @ external_id(D-331) · 한도 */
function alt_sc_push(array $B): void
{
    alt_reset_rl($B);
    $sid = 'svc-a';
    $s1 = gen_uuid4();
    $acc = array(
        array('external_id' => '@admin', 'username' => 'Admin', 'email' => 'Admin@Example.com', 'name' => '관리자',
            'status' => 'active', 'extra' => array('role' => 'admin', 'password' => 'nope', 'department' => '연구팀')),
        array('external_id' => '1042', 'username' => 'hong', 'email' => 'hong@example.com', 'status' => 'active',
            'created_at' => '2025-03-02T00:00:00Z', 'last_login_at' => '2026-11-01T08:00:00Z', 'password_hash' => 'x'),
        array('external_id' => 77, 'username' => 'gd', 'status' => 'disabled'),
    );
    $r = alt_push($B, $sid, $acc, array('sync_id' => $s1));
    $d = alt_ok($r, 'push page 1');
    alt_assert_keys(array('sync_id', 'page', 'accepted', 'created', 'updated', 'unchanged', 'duplicate', 'sync_requested'), $d, 'push');
    assert_eq(array(3, 3, 0, 0, false), array($d['accepted'], $d['created'], $d['updated'], $d['unchanged'], $d['duplicate']), 'push counts');
    $adm = alt_ext($B, $sid, '@admin');
    assert_true($adm !== null, 'external_id "@admin" stored (D-331)');
    assert_eq('admin@example.com', $adm['email'], 'email lower-cased');
    assert_eq('{"role":"admin","department":"연구팀"}', $adm['extra_json'], 'extra whitelist only (password dropped)');
    assert_not_contains('nope', alt_dump($B, 'app_ulogin_ext_accounts'), 'unknown extra key value not stored');
    assert_true(alt_ext($B, $sid, '77') !== null, 'integer external_id stored as string');

    $snap = alt_dump($B, 'app_ulogin_ext_accounts');
    $d2 = alt_ok(alt_push($B, $sid, $acc, array('sync_id' => $s1)), 'push page 1 again');
    assert_true($d2['duplicate'] === true, 'same (sync_id,page) = duplicate:true');
    assert_eq(0, $d2['created'] + $d2['updated'], 'duplicate changes nothing');
    assert_eq($snap, alt_dump($B, 'app_ulogin_ext_accounts'), 'rows unchanged after duplicate');

    $r3 = alt_push($B, $sid, $acc, array('sync_id' => $s1, 'page' => 3));
    alt_err($r3, 400, 'VALIDATION_FAILED', 'page skip');
    assert_eq('page', alt_field($r3), 'page skip field');
    assert_contains('2번', (string) $r3['json']['error']['message'], 'page skip says which page to send');

    $r4 = alt_push($B, $sid, $acc, array('sync_id' => $s1, 'page' => 2, 'sent_at' => alt_iso(-301)));
    alt_err($r4, 400, 'VALIDATION_FAILED', 'sent_at 301s old');
    assert_eq('sent_at', alt_field($r4), 'sent_at field');

    // ③ 단조 — 더 오래된 스냅숏(100초 전)은 덮지 않는다
    $old = alt_ok(alt_push($B, $sid, array(array('external_id' => '1042', 'username' => 'hong', 'name' => '옛이름',
        'status' => 'active')), array('sent_at' => alt_iso(-100))), 'older snapshot');
    assert_eq(1, $old['unchanged'], 'older snapshot counted unchanged');
    assert_eq(null, alt_ext($B, $sid, '1042')['name'], 'older snapshot did not overwrite');
    $new = alt_ok(alt_push($B, $sid, array(array('external_id' => '1042', 'username' => 'hong', 'email' => 'hong@example.com',
        'name' => '홍길동', 'status' => 'active', 'created_at' => '2025-03-02T00:00:00Z',
        'last_login_at' => '2026-11-01T08:00:00Z')), array('sent_at' => alt_iso(5))), 'newer snapshot');
    assert_eq(1, $new['updated'], 'newer snapshot updates');
    assert_eq('홍길동', alt_ext($B, $sid, '1042')['name'], 'newer snapshot wrote');

    // final — 이번 전송에 없던 77 은 사라짐, 상태는 그대로
    $s4 = gen_uuid4();
    alt_ok(alt_push($B, $sid, array($acc[0], array('external_id' => '1042', 'username' => 'hong', 'status' => 'active')),
        array('sync_id' => $s4, 'final' => true, 'sent_at' => alt_iso(10))), 'final push');
    $g = alt_ext($B, $sid, '77');
    assert_true($g['gone_at'] !== null, 'absent account marked gone on final');
    assert_eq('new', $g['state'], 'gone does not change state');
    $r5 = alt_push($B, $sid, $acc, array('sync_id' => $s4, 'page' => 2, 'sent_at' => alt_iso(10)));
    alt_err($r5, 400, 'VALIDATION_FAILED', 'page after final');
    assert_eq('sync_id', alt_field($r5), 'page after final field');

    $many = array();
    for ($i = 0; $i < 201; $i++) {
        $many[] = array('external_id' => 'm' . $i, 'status' => 'active');
    }
    $r6 = alt_push($B, $sid, $many);
    alt_err($r6, 400, 'VALIDATION_FAILED', '201 accounts');
    assert_eq('accounts', alt_field($r6), '201 accounts field');
    $r7 = alt_push($B, $sid, array(array('external_id' => str_repeat('x', 129), 'status' => 'active')));
    alt_err($r7, 400, 'VALIDATION_FAILED', 'external_id 129 chars');
    $r8 = alt_push($B, $sid, array(array('external_id' => 'nostatus')));
    assert_eq('accounts[0].status', alt_field($r8), 'missing status field');
    $big = alt_http($B, 'POST', '/server/api/sso/accounts_push.php', '{"service_id":"svc-a","pad":"' . str_repeat('a', 262200) . '"}');
    alt_err($big, 400, 'VALIDATION_FAILED', 'body over 256KB');
}

/** T10 — 인증 실패 401 + 로그 · 더미 bcrypt(시간) · 15분 10회 뒤 429 · secret 미기록. 전용 사본에서. */
function alt_sc_auth(array $B): void
{
    alt_reset_rl($B);
    $url = '/server/api/sso/accounts_push.php';
    $base = array('sync_id' => gen_uuid4(), 'page' => 1, 'sent_at' => alt_iso(), 'accounts' => array());
    $r = alt_http($B, 'POST', $url, array_merge($base, array('service_id' => 'svc-pub', 'service_secret' => 'whatever-pub')));
    alt_err($r, 401, 'SSO_CLIENT_AUTH_FAILED', 'non-confidential service');
    assert_eq('service_secret', alt_field($r), '401 field');
    alt_err(alt_http($B, 'POST', $url, array_merge($base, array('service_id' => 'no-such', 'service_secret' => 'x'))),
        401, 'SSO_CLIENT_AUTH_FAILED', 'unknown service');
    $wrongSecret = 'WRONG_' . bin2hex(random_bytes(12));
    $wrong = array();
    $miss  = array();
    for ($i = 0; $i < 4; $i++) {
        $a = alt_http($B, 'POST', $url, array_merge($base, array('service_id' => 'svc-a', 'service_secret' => $wrongSecret)));
        alt_err($a, 401, 'SSO_CLIENT_AUTH_FAILED', 'wrong secret ' . $i);
        $wrong[] = $a['ms'];
        $m = alt_http($B, 'POST', $url, array_merge($base, array('service_id' => 'svc-a')));
        alt_err($m, 401, 'SSO_CLIENT_AUTH_FAILED', 'missing secret ' . $i);
        $miss[] = $m['ms'];
    }
    sort($wrong);
    sort($miss);
    $mw = ($wrong[1] + $wrong[2]) / 2;
    $mm = ($miss[1] + $miss[2]) / 2;
    note(sprintf('s2s auth timing: wrong-secret median %.1fms, missing-secret median %.1fms', $mw, $mm));
    assert_true($mm >= 0.5 * $mw, 'missing secret takes bcrypt time too (dummy verify) — wrong ' . round($mw) . 'ms / missing ' . round($mm) . 'ms');
    for ($i = 0; $i < 2; $i++) {
        alt_err(alt_http($B, 'POST', $url, array_merge($base, array('service_id' => 'svc-a', 'service_secret' => $wrongSecret))),
            401, 'SSO_CLIENT_AUTH_FAILED', 'wrong secret ' . (9 + $i));
    }
    $logs = alt_q($B, "SELECT COUNT(*) AS n FROM app_ulogin_login_logs WHERE result = 'fail_client_auth'");
    // v1.9.0 H190-1 (D-361) — 없는·비confidential id 실패(svc-pub · no-such)는 IP 창(300초)당 1행만 남긴다.
    // 실재 id(svc-a)의 실패 10건은 종전대로 전부 남는다 → 1 + 10 = 11
    assert_eq(11, (int) $logs[0]['n'], 'every known-id failure is in login_logs; unknown-id failures once per window');
    // 11번째 — 맞는 secret 이어도 429(정오답을 가를 수 없다)
    $ok = alt_http($B, 'POST', $url, array_merge($base, array('service_id' => 'svc-a', 'service_secret' => $B['fx']['secret']['svc-a'])));
    alt_err($ok, 429, 'RATE_LIMITED', 'after 10 failures even the right secret');
    // 버킷은 (IP, service_id) — 같은 PC 의 다른 시스템은 막히지 않는다
    alt_ok(alt_http($B, 'POST', '/server/api/sso/link_events.php', array('service_id' => 'svc-l',
        'service_secret' => $B['fx']['secret']['svc-l'])), 'another service from the same IP is not locked out');
    $all = alt_dump_all($B) . (string) @file_get_contents($B['log']) . (string) @file_get_contents($B['base'] . '/php_error.log');
    assert_not_contains($wrongSecret, $all, 'wrong secret is not logged anywhere');
    assert_not_contains($B['fx']['secret']['svc-a'], $all, 'right secret is not logged anywhere');
}

/** T10 의 시간 부분만(더미 제거 변이용). */
function alt_sc_timing(array $B): void
{
    alt_reset_rl($B);
    $url = '/server/api/sso/accounts_push.php';
    $base = array('sync_id' => gen_uuid4(), 'page' => 1, 'sent_at' => alt_iso(), 'accounts' => array(), 'service_id' => 'svc-a');
    $w = array();
    $m = array();
    for ($i = 0; $i < 4; $i++) {
        $w[] = alt_http($B, 'POST', $url, array_merge($base, array('service_secret' => 'bad' . $i)))['ms'];
        $m[] = alt_http($B, 'POST', $url, $base)['ms'];
    }
    sort($w);
    sort($m);
    $mw = ($w[1] + $w[2]) / 2;
    $mm = ($m[1] + $m[2]) / 2;
    assert_true($mm >= 0.5 * $mw, 'missing secret takes bcrypt time too — wrong ' . round($mw) . 'ms / missing ' . round($mm) . 'ms');
}

/** T3 — 읽기 admin · 쓰기 superadmin · 전역 role 만 */
function alt_sc_perm(array $B): void
{
    alt_reset_rl($B);
    alt_err(alt_get($B, 'mg', 'view=systems'), 403, 'FORBIDDEN', 'manager GET');
    alt_err(alt_get($B, 'ov', 'view=systems'), 403, 'FORBIDDEN', 'service-override superadmin GET');
    alt_ok(alt_get($B, 'ad', 'view=systems'), 'admin GET');
    alt_ok(alt_get($B, 'sa', 'view=systems'), 'superadmin GET');
    $plan = array('action' => 'plan', 'items' => array(array('op' => 'ignore', 'service_id' => 'svc-l', 'external_id' => 'p-x')));
    alt_err(alt_ops($B, 'ov', $plan), 403, 'FORBIDDEN', 'service-override superadmin plan');
    alt_err(alt_ops($B, 'ad', $plan), 403, 'FORBIDDEN', 'admin plan');
    alt_err(alt_ops($B, 'ad', array('action' => 'commit', 'plan_id' => gen_uuid4())), 403, 'FORBIDDEN', 'admin commit');
    alt_err(alt_ops($B, 'ad', array('action' => 'import', 'service_id' => 'svc-l', 'file' => array())), 403, 'FORBIDDEN', 'admin import');
    alt_err(alt_ops($B, 'mg', array('action' => 'sync_request', 'service_id' => 'svc-l')), 403, 'FORBIDDEN', 'manager sync_request');
    $sr = alt_ok(alt_ops($B, 'ad', array('action' => 'sync_request', 'service_id' => 'svc-l')), 'admin sync_request');
    alt_assert_keys(array('service_id', 'sync_requested_at'), $sr, 'sync_request');
    alt_ok(alt_ops($B, 'sa', $plan), 'superadmin plan');
}

/** T9 — plan 은 상태 불변 · LINK_PLAN_INVALID 네 갈래 */
function alt_sc_plan(array $B): void
{
    alt_reset_rl($B);
    $u = $B['fx']['users'];
    alt_ok(alt_push($B, 'svc-l', array(array('external_id' => 'p1', 'status' => 'active'),
        array('external_id' => 'p2', 'status' => 'active'), array('external_id' => 'p3', 'status' => 'active'))), 'seed');
    // sessions 는 인증 상태 열만 본다 — require_auth() 가 요청마다 last_seen_at 을 갱신하므로(정상 동작),
    // 그 열까지 넣으면 두 덤프 사이에 초가 바뀔 때 거짓 실패가 난다(frontend 전체 실행에서 실제로 났다).
    $state = function () use ($B) {
        return alt_dump($B, 'app_ulogin_ext_accounts') . alt_dump($B, 'app_ulogin_users')
            . json_encode(alt_q($B, 'SELECT id, user_id, service_id, auth_type, revoked_at, revoke_reason FROM app_ulogin_sessions ORDER BY id'))
            . json_encode(alt_q($B, 'SELECT * FROM app_ulogin_password_resets ORDER BY id'));
    };
    $before = $state();
    $p = alt_plan1($B, 'sa', 'link', 'svc-l', 'p1', $u['u1']['uuid']);
    alt_assert_keys(array('plan_id', 'expires_at', 'summary', 'items', 'new_users'), $p, 'plan');
    assert_eq(array('ok' => 1, 'warn' => 0, 'invalid' => 0), $p['summary'], 'plan summary');
    assert_eq(array('seq', 'op', 'service_id', 'external_id', 'verdict', 'codes'), array_keys($p['items'][0]), 'plan item keys');
    assert_eq($before, $state(), 'plan changed no state (ext · users · sessions · password_resets)');

    $c = alt_ops($B, 'sa2', array('action' => 'commit', 'plan_id' => $p['plan_id']));
    alt_err($c, 409, 'LINK_PLAN_INVALID', 'another superadmin commits my plan');
    assert_eq('plan_id', alt_field($c), 'LINK_PLAN_INVALID field');
    $x = alt_ok(alt_ops($B, 'sa', array('action' => 'cancel', 'plan_id' => $p['plan_id'])), 'cancel');
    alt_assert_keys(array('plan_id', 'status'), $x, 'cancel');
    alt_err(alt_ops($B, 'sa', array('action' => 'commit', 'plan_id' => $p['plan_id'])), 409, 'LINK_PLAN_INVALID', 'commit cancelled');
    $p2 = alt_plan1($B, 'sa', 'link', 'svc-l', 'p2', $u['u2']['uuid']);
    alt_x($B, 'UPDATE app_ulogin_link_jobs SET expires_at = ? WHERE job_uuid = ?', array(alt_now(-1), $p2['plan_id']));
    alt_err(alt_ops($B, 'sa', array('action' => 'commit', 'plan_id' => $p2['plan_id'])), 409, 'LINK_PLAN_INVALID', 'commit expired');
    $p3 = alt_plan1($B, 'sa', 'link', 'svc-l', 'p3', $u['u3']['uuid']);
    $c3 = alt_ok(alt_ops($B, 'sa', array('action' => 'commit', 'plan_id' => $p3['plan_id'])), 'commit p3');
    alt_assert_keys(array('job_id', 'status', 'processed', 'remaining', 'results', 'totals'), $c3, 'commit');
    assert_eq(array('seq', 'status', 'error_code', 'user', 'setup_url', 'setup_expires_at'), array_keys($c3['results'][0]), 'commit result keys');
    assert_eq('done', $c3['status'], 'commit status done');
    alt_err(alt_ops($B, 'sa', array('action' => 'commit', 'plan_id' => $p3['plan_id'])), 409, 'LINK_PLAN_INVALID', 'commit done plan');
    alt_err(alt_ops($B, 'sa', array('action' => 'plan', 'items' => array())), 400, 'VALIDATION_FAILED', 'plan with 0 items');
}

/** T1(순차) — 낡은 미리보기로 주인이 바뀌지 않는다(row_version) */
function alt_sc_stale(array $B): void
{
    alt_reset_rl($B);
    $u = $B['fx']['users'];
    alt_ok(alt_push($B, 'svc-l', array(array('external_id' => 'x1', 'status' => 'active'))), 'seed');
    $p1 = alt_plan1($B, 'sa', 'link', 'svc-l', 'x1', $u['u1']['uuid']);
    $p2 = alt_plan1($B, 'sa', 'link', 'svc-l', 'x1', $u['u2']['uuid']);
    assert_eq('ok', $p2['items'][0]['verdict'], 'stale plan looked fine when planned');
    alt_ok(alt_ops($B, 'sa', array('action' => 'commit', 'plan_id' => $p1['plan_id'])), 'commit p1');
    list(, $cu) = alt_link($B, 'sa', 'unlink', 'svc-l', 'x1', null);
    assert_eq('done', $cu['results'][0]['status'], 'unlink done');
    $c2 = alt_ok(alt_ops($B, 'sa', array('action' => 'commit', 'plan_id' => $p2['plan_id'])), 'commit stale p2');
    assert_eq('failed', $c2['results'][0]['status'], 'stale commit failed');
    assert_eq('EXT_CHANGED', $c2['results'][0]['error_code'], 'stale commit code');
    assert_eq('partial', $c2['status'], 'job partial');
    $e = alt_ext($B, 'svc-l', 'x1');
    assert_eq(array('unlinked', null), array($e['state'], $e['user_id']), 'owner did not change');
}

/** T2 — 한 사람 = 서비스당 외부 계정 하나(UNIQUE 가 진다) */
function alt_sc_uq(array $B): void
{
    alt_reset_rl($B);
    $u = $B['fx']['users'];
    alt_ok(alt_push($B, 'svc-l', array(array('external_id' => 'q1', 'status' => 'active'),
        array('external_id' => 'q2', 'status' => 'active'), array('external_id' => 'q3', 'status' => 'active'))), 'seed');
    $pa = alt_plan1($B, 'sa', 'link', 'svc-l', 'q1', $u['u4']['uuid']);
    $pb = alt_plan1($B, 'sa', 'link', 'svc-l', 'q2', $u['u4']['uuid']);
    assert_eq('ok', $pb['items'][0]['verdict'], 'second plan ok at plan time');
    alt_ok(alt_ops($B, 'sa', array('action' => 'commit', 'plan_id' => $pa['plan_id'])), 'commit a');
    $cb = alt_ok(alt_ops($B, 'sa', array('action' => 'commit', 'plan_id' => $pb['plan_id'])), 'commit b');
    assert_eq(array('failed', 'USER_ALREADY_LINKED_SERVICE'), array($cb['results'][0]['status'], $cb['results'][0]['error_code']), 'second link');
    $n = alt_q($B, "SELECT COUNT(*) AS n FROM app_ulogin_ext_accounts WHERE service_id = 'svc-l' AND user_id = ?", array($u['u4']['id']));
    assert_eq(1, (int) $n[0]['n'], 'one external account per service per user');
    $pc = alt_plan1($B, 'sa', 'link', 'svc-l', 'q3', $u['u4']['uuid']);
    assert_eq('invalid', $pc['items'][0]['verdict'], 'plan after link');
    assert_eq(array('USER_ALREADY_LINKED_SERVICE'), $pc['items'][0]['codes'], 'plan after link code');
}

/** D-328 — 시스템 관리자 계정을 rank<20 계정에 붙이면 경고(차단 아님). D-331 — "@admin" 연결 가능 */
function alt_sc_warn(array $B): void
{
    alt_reset_rl($B);
    $u = $B['fx']['users'];
    alt_ok(alt_push($B, 'svc-m', array(array('external_id' => '@admin', 'username' => 'admin', 'status' => 'active',
        'extra' => array('role' => 'admin')), array('external_id' => 'w-off', 'status' => 'disabled'))), 'seed');
    $low = alt_plan1($B, 'sa', 'link', 'svc-m', '@admin', $u['u5']['uuid']);
    assert_eq('warn', $low['items'][0]['verdict'], 'admin ext -> rank 0 user is a warning');
    assert_eq(array('EXT_ROLE_ADMIN_LOW_RANK'), $low['items'][0]['codes'], 'warning code');
    $hi = alt_plan1($B, 'sa', 'link', 'svc-m', '@admin', $u['ad']['uuid']);
    assert_eq('ok', $hi['items'][0]['verdict'], 'admin ext -> rank 20 user is fine');
    $c = alt_ok(alt_ops($B, 'sa', array('action' => 'commit', 'plan_id' => $low['plan_id'])), 'warn commits');
    assert_eq('done', $c['results'][0]['status'], 'warning does not block');
    $dis = alt_plan1($B, 'sa', 'link', 'svc-m', 'w-off', $u['u6']['uuid']);
    assert_eq(array('warn', array('EXT_DISABLED')), array($dis['items'][0]['verdict'], $dis['items'][0]['codes']), 'disabled ext warns');
    $acc = alt_ok(alt_get($B, 'sa', 'view=accounts&service_id=svc-m&state=linked'), 'accounts view');
    alt_assert_keys(array('items', 'page', 'per_page', 'total'), $acc, 'accounts');
    $codes = array();
    foreach ($acc['items'][0]['conflicts'] as $cf) {
        $codes[] = $cf['code'];
    }
    assert_true(in_array('EXT_ROLE_ADMIN_LOW_RANK', $codes, true), 'accounts view shows the D-328 conflict');
}

/** T7 — exchange·session 의 account_link 3상태 · 기존 키 무파괴 · state 조건 */
function alt_sc_exchange(array $B): void
{
    alt_reset_rl($B);
    $u = $B['fx']['users']['u9'];
    alt_ok(alt_push($B, 'svc-r', array(array('external_id' => 'e1', 'username' => 'hong', 'status' => 'active'))), 'seed');
    $x0 = alt_exchange($B, $u['id'], 'svc-r');
    $old = array('user', 'session', 'access_token', 'refresh_token', 'token_type', 'expires_in', 'refresh_expires_in',
        'state', 'issued_via', 'payload');
    assert_eq(array(), array_values(array_diff($old, array_keys($x0))), 'old exchange keys all still there');
    assert_true(array_key_exists('account_link', $x0) && $x0['account_link'] === null, 'not linked = account_link:null');
    alt_link($B, 'sa', 'link', 'svc-r', 'e1', $u['uuid']);
    $x1 = alt_exchange($B, $u['id'], 'svc-r');
    $al = $x1['account_link'];
    assert_eq(array('external_id', 'username', 'linked_at', 'link_origin'), array_keys((array) $al), 'account_link keys');
    assert_eq(array('e1', 'hong', 'attached'), array($al['external_id'], $al['username'], $al['link_origin']), 'account_link values');
    assert_match('~^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$~', (string) $al['linked_at'], 'linked_at ISO8601');
    $s = alt_ok(alt_http($B, 'GET', '/server/api/auth/session.php', null, $x1['access_token']), 'session');
    assert_eq($al, $s['account_link'], 'session carries the same account_link');
    $s2 = alt_ok(alt_http($B, 'GET', '/server/api/auth/session.php', null, $B['fx']['tok']['sa']), 'service-less session');
    assert_true(array_key_exists('account_link', $s2) && $s2['account_link'] === null, 'service-less session = null');
    // 불변식이 깨진 행(state≠linked 인데 user_id 가 있다)도 열쇠가 되지 않는다
    alt_x($B, 'INSERT INTO app_ulogin_ext_accounts (service_id, external_id, state, user_id, row_version, source_sent_at,'
        . ' first_seen_at, updated_at) VALUES (?,?,?,?,?,?,?,?)', array('svc-m', 'ghost', 'unlinked', $u['id'], 3, alt_now(), alt_now(), alt_now()));
    $x2 = alt_exchange($B, $u['id'], 'svc-m');
    assert_true($x2['account_link'] === null, 'only state=linked rows answer account_link');
}

/** T5·T6 — 새 계정 이관 · 기본 등급 · 설정 링크 1회 노출 · 72h · 재발급 · 가입 우회 0 */
function alt_sc_create(array $B): void
{
    alt_reset_rl($B);
    alt_x($B, "INSERT INTO app_ulogin_settings (skey, svalue, vtype, label, updated_at) VALUES ('allow_register','0','bool','x',?)", array(alt_now()));
    alt_ok(alt_push($B, 'svc-l', array(array('external_id' => 'n-1', 'username' => 'Kim', 'email' => 'kim@example.com',
        'name' => '김철수', 'status' => 'active'), array('external_id' => 'n-3', 'username' => 'lee', 'status' => 'active'))), 'seed l');
    alt_ok(alt_push($B, 'svc-m', array(array('external_id' => 'k-3', 'username' => 'kim01', 'email' => 'kim@example.com',
        'status' => 'active'))), 'seed m');
    $count = function () use ($B) {
        return (int) alt_q($B, 'SELECT COUNT(*) AS n FROM app_ulogin_users')[0]['n'];
    };
    $n0 = $count();
    $p = alt_ok(alt_ops($B, 'sa', array('action' => 'plan',
        'new_users' => array(array('ref' => 'r1', 'username' => 'KimCS', 'email' => 'kim@example.com', 'name' => '김철수',
            'role' => 'superadmin', 'account_level' => 'staff', 'billing_tier' => 'enterprise')),
        'items' => array(
            array('op' => 'create_link', 'service_id' => 'svc-l', 'external_id' => 'n-1', 'target' => array('ref' => 'r1'), 'expect_version' => 0),
            array('op' => 'link', 'service_id' => 'svc-m', 'external_id' => 'k-3', 'target' => array('ref' => 'r1'), 'expect_version' => 0),
        ))), 'plan create');
    assert_eq(array('ok' => 2, 'warn' => 0, 'invalid' => 0), $p['summary'], 'create plan summary');
    assert_eq(array('ref', 'verdict', 'codes', 'suggested_username', 'attach_candidate'), array_keys($p['new_users'][0]), 'new_users keys');
    $c = alt_ok(alt_ops($B, 'sa', array('action' => 'commit', 'plan_id' => $p['plan_id'], 'seqs' => array(1, 2))), 'commit create');
    assert_eq(array('done', 0, array('done' => 2, 'failed' => 0, 'skipped' => 0)), array($c['status'], $c['remaining'], $c['totals']), 'commit totals');
    $r1 = $c['results'][0];
    $r2 = $c['results'][1];
    assert_eq('kimcs', $r1['user']['username'], 'new username normalized');
    assert_eq($r1['user']['uuid'], $r2['user']['uuid'], 'ref link went to the new account');
    assert_true(is_string($r1['setup_url']) && is_string($r1['setup_expires_at']), 'setup link returned once');
    assert_true($r2['setup_url'] === null, 'no setup link on plain link');
    assert_match('~^http://127\.0\.0\.1:\d+/public/reset\.html\?token=[0-9a-f]{64}&setup=1$~', $r1['setup_url'], 'setup url form');
    preg_match('~token=([0-9a-f]{64})~', $r1['setup_url'], $m);
    $tok = $m[1];
    $exp = strtotime((string) $r1['setup_expires_at']);
    assert_true(abs($exp - (time() + 259200)) < 120, 'setup link lives 72h');
    $nu = alt_q($B, 'SELECT * FROM app_ulogin_users WHERE uuid = ?', array($r1['user']['uuid']))[0];
    assert_eq(array('user', 'basic', 'free', 'active'), array($nu['role'], $nu['account_level'], $nu['billing_tier'], $nu['status']),
        'imported account gets defaults only (body role/level ignored)');
    assert_true(strpos((string) $nu['meta_json'], '"setup_pending":true') !== false, 'setup_pending set');
    assert_eq($n0 + 1, $count(), 'exactly one account created');
    // 원문은 DB·admin_logs·메일 폴더·서버 로그·작업 조회 어디에도 없다
    $mail = '';
    $md = $B['root'] . '/server/storage/mail';
    if (is_dir($md)) {
        foreach (scandir($md) as $f) {
            if (is_file($md . '/' . $f)) {
                $mail .= (string) file_get_contents($md . '/' . $f);
            }
        }
    }
    $hay = alt_dump_all($B) . $mail . (string) @file_get_contents($B['log']) . (string) @file_get_contents($B['base'] . '/php_error.log');
    assert_not_contains($tok, $hay, 'setup token raw value stored nowhere');
    assert_not_contains('reset.html', alt_dump($B, 'app_ulogin_admin_logs'), 'admin_logs carry no setup URL');
    $job = alt_ok(alt_get($B, 'sa', 'view=job&job_id=' . $c['job_id']), 'job view');
    alt_assert_keys(array('job', 'items'), $job, 'job');
    assert_not_contains($tok, json_encode($job), 'job view does not repeat the token');
    assert_not_contains('setup_url', json_encode($job), 'job view has no setup_url');
    $pr = alt_q($B, "SELECT purpose FROM app_ulogin_password_resets WHERE token_hash = ?", array(hash('sha256', $tok)));
    assert_eq('setup', $pr[0]['purpose'], 'stored as purpose=setup (hash only)');

    // 가입 차단 상태 — 공개 가입은 403, 사용자 수 그대로
    $reg = alt_http($B, 'POST', '/server/api/auth/register.php', array('username' => 'altreg01', 'email' => 'r@test.invalid',
        'password' => 'Abcd1234!x', 'password_confirm' => 'Abcd1234!x', 'agree_tos' => true, 'agree_privacy' => true));
    alt_err($reg, 403, 'REGISTRATION_DISABLED', 'register while closed');
    // 재발급 — 이전 링크 무효
    $sl = alt_ok(alt_ops($B, 'sa', array('action' => 'setup_link', 'uuid' => $r1['user']['uuid'])), 'setup_link');
    alt_assert_keys(array('uuid', 'setup_url', 'setup_expires_at', 'invalidated'), $sl, 'setup_link');
    assert_eq(1, $sl['invalidated'], 'previous setup link invalidated');
    preg_match('~token=([0-9a-f]{64})~', $sl['setup_url'], $m2);
    $reset = function ($t) use ($B) {
        return alt_http($B, 'POST', '/server/api/auth/password_reset.php', array('token' => $t,
            'password' => 'NewPassw0rd!1', 'password_confirm' => 'NewPassw0rd!1'));
    };
    alt_err($reset($tok), 400, 'TOKEN_INVALID', 'old setup link after reissue');
    alt_ok($reset($m2[1]), 'consume setup link');
    assert_eq($n0 + 1, $count(), 'consuming a setup link creates no account');
    $nu2 = alt_q($B, 'SELECT meta_json FROM app_ulogin_users WHERE uuid = ?', array($r1['user']['uuid']))[0];
    assert_true(strpos((string) $nu2['meta_json'], 'setup_pending') === false, 'setup_pending cleared');
    alt_ok(alt_http($B, 'POST', '/server/api/auth/login.php', array('username' => 'kimcs', 'password' => 'NewPassw0rd!1')), 'login with the new password');
    alt_err(alt_ops($B, 'sa', array('action' => 'setup_link', 'uuid' => $r1['user']['uuid'])), 409, 'CONFLICT', 'setup_link on a set account');
    // 72h 지난 링크
    $p3 = alt_ok(alt_ops($B, 'sa', array('action' => 'plan',
        'new_users' => array(array('ref' => 'r2', 'username' => 'leecs', 'email' => 'lee@example.com')),
        'items' => array(array('op' => 'create_link', 'service_id' => 'svc-l', 'external_id' => 'n-3', 'target' => array('ref' => 'r2'))))), 'plan r2');
    $c3 = alt_ok(alt_ops($B, 'sa', array('action' => 'commit', 'plan_id' => $p3['plan_id'])), 'commit r2');
    preg_match('~token=([0-9a-f]{64})~', (string) $c3['results'][0]['setup_url'], $m3);
    alt_x($B, 'UPDATE app_ulogin_password_resets SET expires_at = ? WHERE token_hash = ?', array(alt_now(-1), hash('sha256', $m3[1])));
    alt_err($reset($m3[1]), 400, 'TOKEN_INVALID', 'expired setup link');
}

/** 새 계정 판정 — 아이디 중복·형식 · 이메일 중복(붙이기 후보) · ref 의존 실패(commit 시점) */
function alt_sc_newuser(array $B): void
{
    alt_reset_rl($B);
    alt_ok(alt_push($B, 'svc-l', array(array('external_id' => 'd-1', 'status' => 'active'),
        array('external_id' => 'd-2', 'status' => 'active'))), 'seed l');
    alt_ok(alt_push($B, 'svc-m', array(array('external_id' => 'd-3', 'status' => 'active'))), 'seed m');
    $p = alt_ok(alt_ops($B, 'sa', array('action' => 'plan',
        'new_users' => array(array('ref' => 'a', 'username' => 'alt_u1', 'email' => 'alt_u2@test.invalid'),
            array('ref' => 'b', 'username' => 'x!', 'email' => null)),
        'items' => array(
            array('op' => 'create_link', 'service_id' => 'svc-l', 'external_id' => 'd-1', 'target' => array('ref' => 'a')),
            array('op' => 'create_link', 'service_id' => 'svc-l', 'external_id' => 'd-2', 'target' => array('ref' => 'b')),
            array('op' => 'link', 'service_id' => 'svc-m', 'external_id' => 'd-3', 'target' => array('ref' => 'a'))))), 'plan dup');
    $nu = $p['new_users'];
    assert_eq(array('DUPLICATE_USERNAME', 'DUPLICATE_EMAIL'), $nu[0]['codes'], 'dup username + dup email');
    assert_eq('alt_u1_2', $nu[0]['suggested_username'], 'suggested username');
    assert_eq($B['fx']['users']['u2']['uuid'], $nu[0]['attach_candidate']['uuid'], 'duplicate email offers the owner as attach candidate');
    assert_eq(array('USERNAME_INVALID', 'EMAIL_REQUIRED'), $nu[1]['codes'], 'invalid username + no email');
    assert_eq(array('DEPENDENCY_FAILED'), $p['items'][2]['codes'], 'link on a failing ref');
    // commit 시점 실패 — 계획 때 멀쩡했던 아이디를 그 사이 누가 가져갔다
    $p2 = alt_ok(alt_ops($B, 'sa', array('action' => 'plan',
        'new_users' => array(array('ref' => 'c', 'username' => 'race_name', 'email' => 'racename@test.invalid')),
        'items' => array(
            array('op' => 'link', 'service_id' => 'svc-m', 'external_id' => 'd-3', 'target' => array('ref' => 'c')),
            array('op' => 'create_link', 'service_id' => 'svc-l', 'external_id' => 'd-1', 'target' => array('ref' => 'c'))))), 'plan ok');
    assert_eq(2, $p2['summary']['ok'], 'plan ok before the name is taken');
    alt_x($B, 'INSERT INTO app_ulogin_users (uuid, username, email, password_hash, status, account_level, role, billing_tier,'
        . ' created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
        array(gen_uuid4(), 'race_name', 'other@test.invalid', 'x', 'active', 'basic', 'user', 'free', alt_now(), alt_now()));
    $c = alt_ok(alt_ops($B, 'sa', array('action' => 'commit', 'plan_id' => $p2['plan_id'])), 'commit');
    $by = array();
    foreach ($c['results'] as $r) {
        $by[$r['seq']] = array($r['status'], $r['error_code']);
    }
    assert_eq(array('failed', 'DUPLICATE_USERNAME'), $by[2], 'create_link fails at commit');
    assert_eq(array('skipped', 'DEPENDENCY_FAILED'), $by[1], 'dependent link skipped');
    assert_eq('partial', $c['status'], 'partial');
    assert_eq('new', alt_ext($B, 'svc-l', 'd-1')['state'], 'failed create rolled back the link');
    $rp = alt_ok(alt_ops($B, 'sa', array('action' => 'plan', 'retry_job_id' => $c['job_id'])), 'retry plan');
    assert_eq(2, count($rp['items']), 'retry plan carries the failed and skipped items');
    assert_eq(array('DUPLICATE_USERNAME'), $rp['new_users'][0]['codes'], 'retry re-judges the new account');
}

/** T8 — 해제 → 그 (사용자,서비스) token 세션만 회수 · 피드 · 탈퇴/완전삭제 자동 해제 · 서비스 삭제 정리 */
function alt_sc_unlink(array $B): void
{
    alt_reset_rl($B);
    $u6 = $B['fx']['users']['u6'];
    $u7 = $B['fx']['users']['u7'];
    alt_ok(alt_push($B, 'svc-l', array(array('external_id' => 'w1', 'status' => 'active'), array('external_id' => 'w3', 'status' => 'active'))), 'seed l');
    alt_ok(alt_push($B, 'svc-m', array(array('external_id' => 'w2', 'status' => 'active'))), 'seed m');
    $feed = function ($sid, $after, $limit) use ($B) {
        return alt_ok(alt_http($B, 'POST', '/server/api/sso/link_events.php', array('service_id' => $sid,
            'service_secret' => $B['fx']['secret'][$sid], 'after' => $after, 'limit' => $limit)), 'feed ' . $sid);
    };
    // 커서 — 앞 시나리오가 남긴 사건 뒤부터 본다(피드는 누적 원장이다)
    $curL = $feed('svc-l', 0, 500)['next_after'];
    $curM = $feed('svc-m', 0, 500)['next_after'];
    $tA = alt_token_b($B, $u6['id'], 'svc-l');
    $tB = alt_token_b($B, $u6['id'], 'svc-m');
    $tC = alt_token_b($B, $u6['id'], null);
    alt_link($B, 'sa', 'link', 'svc-l', 'w1', $u6['uuid']);
    alt_link($B, 'sa', 'link', 'svc-m', 'w2', $u6['uuid']);
    alt_link($B, 'sa', 'unlink', 'svc-l', 'w1', null);
    $rev = function ($t) use ($B) {
        return alt_q($B, 'SELECT revoked_at, revoke_reason FROM app_ulogin_sessions WHERE access_token_hash = ?', array(hash('sha256', $t)))[0];
    };
    assert_eq('account_unlink', $rev($tA)['revoke_reason'], 'token session of (user, service) revoked on unlink');
    assert_eq(null, $rev($tB)['revoked_at'], 'other service session untouched');
    assert_eq(null, $rev($tC)['revoked_at'], 'service-less session untouched');
    $f1 = $feed('svc-l', $curL, 1);
    alt_assert_keys(array('events', 'next_after', 'has_more', 'sync_requested'), $f1, 'feed');
    assert_eq(array('event_id', 'type', 'external_id', 'uuid', 'at'), array_keys($f1['events'][0]), 'event keys');
    assert_eq(array('linked', 'w1', $u6['uuid'], true), array($f1['events'][0]['type'], $f1['events'][0]['external_id'],
        $f1['events'][0]['uuid'], $f1['has_more']), 'first event');
    $f2 = $feed('svc-l', $f1['next_after'], 100);
    assert_eq(array('unlinked', 'w1', false), array($f2['events'][0]['type'], $f2['events'][0]['external_id'], $f2['has_more']), 'second event');
    $fm = $feed('svc-m', $curM, 100);
    foreach ($fm['events'] as $ev) {
        assert_true($ev['external_id'] !== 'w1', 'a service sees only its own events');
    }
    alt_err(alt_http($B, 'POST', '/server/api/sso/link_events.php', array('service_id' => 'svc-l', 'service_secret' => 'bad')),
        401, 'SSO_CLIENT_AUTH_FAILED', 'feed with a wrong secret');
    // 탈퇴 — 남은 연결(w2) 자동 해제 + 피드
    alt_ok(alt_http($B, 'POST', '/server/api/me/withdraw.php', array('password' => ALT_PW, 'confirm' => $u6['username']), $tC), 'withdraw');
    assert_eq('unlinked', alt_ext($B, 'svc-m', 'w2')['state'], 'withdraw releases links');
    $fm2 = $feed('svc-m', 0, 100);
    $last = end($fm2['events']);
    assert_eq(array('unlinked', 'w2', $u6['uuid']), array($last['type'], $last['external_id'], $last['uuid']), 'withdraw event');
    // 완전삭제 — 사용자 행이 사라져도 피드의 uuid 가 남는다
    alt_link($B, 'sa', 'link', 'svc-l', 'w3', $u7['uuid']);
    $del = alt_ok(alt_http($B, 'DELETE', '/server/api/admin/user_detail.php?uuid=' . $u7['uuid'] . '&confirm=' . $u7['username'],
        null, $B['fx']['tok']['sa']), 'hard delete');
    assert_eq(1, $del['purged']['account_links'], 'hard delete reports released links');
    assert_eq('unlinked', alt_ext($B, 'svc-l', 'w3')['state'], 'hard delete releases links');
    $fl = $feed('svc-l', 0, 100);
    $last = end($fl['events']);
    assert_eq(array('unlinked', 'w3', $u7['uuid']), array($last['type'], $last['external_id'], $last['uuid']), 'hard delete event keeps uuid');
    // v1.8.6 Q-1 — 커서를 0 부터 다시 읽는 시스템: 완전삭제된 사람의 **과거 linked 사건**도 uuid 가 남는다
    $w3 = array();
    foreach ($fl['events'] as $ev) {
        if ($ev['external_id'] === 'w3') {
            $w3[] = array($ev['type'], $ev['uuid']);
        }
    }
    assert_eq(array(array('linked', $u7['uuid']), array('unlinked', $u7['uuid'])), $w3, 'replayed linked + unlinked events of a hard-deleted user both carry the uuid');
    foreach ($fl['events'] as $ev) {
        assert_true(is_string($ev['uuid']) && $ev['uuid'] !== '', 'no event in the replayed feed has uuid null (event ' . $ev['event_id'] . ')');
    }
    // 서비스 삭제 — 스냅숏·수신 작업 정리
    alt_ok(alt_push($B, 'svc-c', array(array('external_id' => 'c1', 'status' => 'active'), array('external_id' => 'c2', 'status' => 'active'))), 'seed c');
    alt_ok(alt_ops($B, 'ad', array('action' => 'sync_request', 'service_id' => 'svc-c')), 'sync_request c');
    // v1.8.6 Q-3 — 가입자 0명이어도 외부 계정이 있으면 force + 확인 문구 없이는 지우지 않는다
    assert_eq(0, (int) alt_q($B, "SELECT COUNT(*) AS n FROM app_ulogin_user_services WHERE service_id = 'svc-c'")[0]['n'], 'svc-c has no members (the case under test)');
    $nf = alt_http($B, 'DELETE', '/server/api/admin/services.php?service_id=svc-c', null, $B['fx']['tok']['sa']);
    alt_err($nf, 409, 'CONFLICT', 'service delete without force while external accounts exist');
    assert_eq(2, (int) ($nf['json']['data']['ext_account_count'] ?? -1), 'conflict reports the external account count');
    alt_err(alt_http($B, 'DELETE', '/server/api/admin/services.php?service_id=svc-c&force=1', null, $B['fx']['tok']['sa']),
        400, 'VALIDATION_FAILED', 'force without the confirm text');
    assert_eq(2, (int) alt_q($B, "SELECT COUNT(*) AS n FROM app_ulogin_ext_accounts WHERE service_id = 'svc-c'")[0]['n'], 'refused deletes removed nothing');
    $sd = alt_ok(alt_http($B, 'DELETE', '/server/api/admin/services.php?service_id=svc-c&force=1&confirm=svc-c', null,
        $B['fx']['tok']['sa']), 'service delete');
    assert_eq(2, $sd['purged']['ext_accounts'], 'service delete purges snapshots');
    assert_eq(0, (int) alt_q($B, "SELECT COUNT(*) AS n FROM app_ulogin_ext_accounts WHERE service_id = 'svc-c'")[0]['n'], 'no snapshot left');
    assert_eq(0, (int) alt_q($B, "SELECT COUNT(*) AS n FROM app_ulogin_link_jobs WHERE service_id = 'svc-c'")[0]['n'], 'no push/sync job left');
}

/** 재수집 요청 · 조회 view 형태 · 파일 가져오기(H-4, push 와 같은 검증) */
function alt_sc_views(array $B): void
{
    alt_reset_rl($B);
    $u = $B['fx']['users'];
    $sr = alt_ok(alt_ops($B, 'ad', array('action' => 'sync_request', 'service_id' => 'svc-a')), 'sync_request');
    $d = alt_ok(alt_push($B, 'svc-a', array(array('external_id' => 'v1', 'username' => 'alt_u8', 'email' => 'v1@x.invalid', 'status' => 'active'))), 'push');
    assert_true($d['sync_requested'] === true, 'push answers sync_requested:true while a request is open');
    $d2 = alt_ok(alt_push($B, 'svc-a', array(array('external_id' => 'v1', 'username' => 'alt_u8', 'status' => 'active')),
        array('final' => true, 'sent_at' => alt_iso(2))), 'full push');
    assert_true($d2['sync_requested'] === false, 'a full push fulfils the request');
    $sys = alt_ok(alt_get($B, 'ad', 'view=systems'), 'systems');
    alt_assert_keys(array('systems'), $sys, 'systems');
    $row = null;
    foreach ($sys['systems'] as $s) {
        if ($s['service_id'] === 'svc-a') {
            $row = $s;
        }
        if ($s['service_id'] === 'svc-pub') {
            assert_true($s['supported'] === false, 'never-pushed service is unsupported');
        }
    }
    assert_eq(array('service_id', 'service_name', 'service_status', 'supported', 'last_push_at', 'last_full_sync_at',
        'sync_requested_at', 'counts'), array_keys($row), 'system keys');
    assert_eq(array('total', 'new', 'ignored', 'linked', 'unlinked', 'gone'), array_keys($row['counts']), 'counts keys');
    assert_true($row['supported'] === true && $row['last_full_sync_at'] !== null && $row['sync_requested_at'] === null, 'svc-a supported, synced');
    $acc = alt_ok(alt_get($B, 'ad', 'view=accounts&service_id=svc-a&candidate=has&q=v1'), 'accounts has candidate');
    assert_eq(1, $acc['total'], 'username match is a candidate');
    $it = $acc['items'][0];
    assert_eq(array('service_id', 'external_id', 'username', 'email', 'name', 'ext_status', 'ext_created_at', 'ext_last_login_at',
        'state', 'row_version', 'gone', 'is_emergency', 'extra', 'linked_user', 'link_origin', 'linked_at', 'bound_uuid', 'candidates', 'conflicts'),
        array_keys($it), 'account item keys');
    assert_eq(array('uuid', 'username', 'email', 'match', 'already_linked_here', 'role_rank', 'status', 'is_emergency'), array_keys($it['candidates'][0]), 'candidate keys');
    assert_eq($u['u8']['uuid'], $it['candidates'][0]['uuid'], 'candidate is alt_u8');
    alt_err(alt_get($B, 'ad', 'view=accounts&service_id=no-such'), 400, 'VALIDATION_FAILED', 'accounts unknown service');
    // 서버 쪽 상태 필터 — svc-a 는 alt_sc_push 가 남긴 77(disabled→사라짐) · @admin · 1042 · v1 이 있다
    alt_ok(alt_push($B, 'svc-a', array(array('external_id' => 'v-dis', 'status' => 'disabled'),
        array('external_id' => 'v-del', 'status' => 'deleted'))), 'push disabled/deleted');
    $ids = function ($qs) use ($B) {
        $o = array();
        foreach (alt_ok(alt_get($B, 'ad', 'view=accounts&service_id=svc-a&per_page=100&' . $qs), $qs)['items'] as $x) {
            $o[] = $x['external_id'];
        }
        sort($o);
        return $o;
    };
    assert_eq(array('v-dis'), $ids('ext=disabled'), 'ext=disabled');
    assert_true(in_array('v-del', $ids('ext=gone'), true) && !in_array('v-dis', $ids('ext=gone'), true), 'ext=gone = gone_at or deleted');
    $pres = $ids('ext=present');
    assert_true(in_array('v1', $pres, true) && !in_array('v-dis', $pres, true) && !in_array('v-del', $pres, true), 'ext=present');
    $both = array_merge($ids('state=linked'), $ids('state=not_linked'));
    sort($both);
    assert_eq($ids('state=all'), $both, 'state=linked + state=not_linked = all');
    alt_err(alt_get($B, 'ad', 'view=accounts&ext=weird'), 400, 'VALIDATION_FAILED', 'accounts bad ext filter');
    alt_err(alt_get($B, 'ad', 'view=accounts&sort=password'), 400, 'VALIDATION_FAILED', 'accounts bad sort');
    $per = alt_ok(alt_get($B, 'ad', 'view=person&uuid=' . $u['u8']['uuid']), 'person');
    alt_assert_keys(array('user', 'systems'), $per, 'person');
    assert_eq(array('uuid', 'username', 'email', 'name', 'status', 'setup_pending', 'role_rank', 'is_emergency'), array_keys($per['user']), 'person user keys');
    assert_eq(array('service_id', 'service_name', 'link', 'candidates'), array_keys($per['systems'][0]), 'person system keys');
    alt_err(alt_get($B, 'ad', 'view=person&uuid=' . gen_uuid4()), 404, 'NOT_FOUND', 'person unknown uuid');
    $gr = alt_ok(alt_get($B, 'ad', 'view=groups'), 'groups');
    alt_assert_keys(array('groups', 'page', 'per_page', 'total'), $gr, 'groups');
    $jobs = alt_ok(alt_get($B, 'ad', 'view=jobs&kind=push'), 'jobs');
    alt_assert_keys(array('items', 'page', 'per_page', 'total'), $jobs, 'jobs');
    assert_eq(array('job_id', 'kind', 'status', 'service_id', 'created_by', 'created_at', 'finished_at', 'expires_at',
        'item_count', 'totals'), array_keys($jobs['items'][0]), 'job keys');
    alt_err(alt_get($B, 'ad', 'view=job&job_id=' . gen_uuid4()), 404, 'NOT_FOUND', 'job unknown');
    // 파일 가져오기 — push 와 같은 검증(256KB·200개 한도만 크다, sent_at 창 없음)
    $file = array('service_id' => 'svc-pub', 'service_secret' => 'IGNORED-' . bin2hex(random_bytes(4)), 'sent_at' => '2026-01-01T00:00:00Z',
        'final' => true, 'accounts' => array(array('external_id' => '@admin', 'status' => 'active', 'username' => 'adm'),
            array('external_id' => 'f-2', 'status' => 'gone-ish')));
    $im = alt_ok(alt_ops($B, 'sa', array('action' => 'import', 'service_id' => 'svc-pub', 'file' => $file)), 'import');
    alt_assert_keys(array('sync_id', 'page', 'accepted', 'created', 'updated', 'unchanged', 'duplicate', 'sync_requested'), $im, 'import');
    assert_eq(2, $im['created'], 'import created');
    assert_eq(null, alt_ext($B, 'svc-pub', 'f-2')['ext_status'], 'unknown status stored as NULL');
    assert_not_contains($file['service_secret'], alt_dump_all($B), 'file service_secret not stored');
    alt_err(alt_ops($B, 'sa', array('action' => 'import', 'service_id' => 'svc-a', 'file' => $file)), 400, 'VALIDATION_FAILED', 'file for another service');
    $bad = $file;
    $bad['accounts'][] = array('external_id' => 'nostatus');
    $ri = alt_ops($B, 'sa', array('action' => 'import', 'service_id' => 'svc-pub', 'file' => $bad));
    assert_eq('accounts[2].status', alt_field($ri), 'import uses the push validator (same field path)');
    $sys2 = alt_ok(alt_get($B, 'ad', 'view=systems'), 'systems after import');
    foreach ($sys2['systems'] as $s) {
        if ($s['service_id'] === 'svc-pub') {
            assert_true($s['supported'] === false && $s['last_full_sync_at'] !== null, 'file import is not "push supported" but counts as a full sync');
        }
    }
}

// =====================================================================
// 경합 (T1) — 자식 N개가 같은 외부 계정을 서로 다른 사람에게 동시에 commit
// =====================================================================

function alt_worker_source(): string
{
    return <<<'WORKER'
<?php
// generated by tests/tier3_live/t_80_account_link.php - sandbox only.
$job = json_decode((string) file_get_contents($argv[1]), true);
$GLOBALS['__R'] = array('out' => $argv[2], 'start' => (float) $job['start'], 'spin_at' => null);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', $argv[2] . '.log');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '10.80.0.' . (int) $job['idx'];
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/server/api/admin/account_link_ops.php';
$_SERVER['SCRIPT_NAME'] = $_SERVER['REQUEST_URI'];
$_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $job['bearer'];
$_POST = $job['post'];
register_shutdown_function(function () {
    $R = $GLOBALS['__R'];
    $body = '';
    while (ob_get_level() > 0) {
        $body = (string) ob_get_clean() . $body;
    }
    $st = http_response_code();
    file_put_contents($R['out'], json_encode(array('status' => $st === false ? 0 : (int) $st, 'body' => $body,
        'spin_lead' => $R['spin_at'] === null ? null : $R['start'] - $R['spin_at'], 'fatal' => error_get_last())));
});
ob_start();
require $job['box'] . '/server/core/bootstrap.php';
db();
$GLOBALS['__R']['spin_at'] = microtime(true);
while (microtime(true) < $job['start']) {
}
chdir($job['box'] . '/server/api/admin');
require $job['box'] . '/server/api/admin/account_link_ops.php';
WORKER;
}

function alt_spawn(array $B, array $jobs, float $lead): array
{
    $dir = $B['base'] . '/run_' . bin2hex(random_bytes(3));
    @mkdir($dir, 0777, true);
    $worker = $dir . '/worker.php';
    file_put_contents($worker, alt_worker_source());
    $start = microtime(true) + $lead;
    $ini = alt_ini();
    $procs = array();
    foreach ($jobs as $i => $job) {
        $job['box'] = $B['root'];
        $job['start'] = $start;
        $job['idx'] = $i + 1;
        $jf = $dir . '/job_' . $i . '.json';
        file_put_contents($jf, json_encode($job));
        $out = $dir . '/out_' . $i . '.json';
        $cmd = h_q(h_php_bin()) . ' -c ' . h_q($ini) . ' ' . h_q($worker) . ' ' . h_q($jf) . ' ' . h_q($out);
        $desc = array(0 => array('pipe', 'r'), 1 => array('file', $dir . '/so_' . $i, 'w'), 2 => array('file', $dir . '/se_' . $i, 'w'));
        $pp = array();
        $p = @proc_open($cmd, $desc, $pp, $dir);
        if (!is_resource($p)) {
            fail('HARNESS-FAIL proc_open failed for worker ' . $i);
        }
        fclose($pp[0]);
        $procs[$i] = array($p, $out);
    }
    $res = array();
    // D-339 — 기한을 넘긴 자식은 자기 PID 트리만 죽이고 하네스 실패로 끝낸다.
    $deadline = proc_deadline();
    $timedOut = array();
    foreach ($procs as $i => $pr) {
        $w = proc_wait_or_kill($pr[0], $deadline);
        if ($w['timed_out']) {
            $timedOut[] = $i . '(pid ' . $w['pid'] . ')';
        }
        $j = is_file($pr[1]) ? json_decode((string) file_get_contents($pr[1]), true) : null;
        if (!is_array($j)) {
            $j = array('status' => 0, 'body' => '', 'spin_lead' => null,
                'fatal' => 'no result; stderr: ' . substr((string) @file_get_contents($dir . '/se_' . $i), -300));
        }
        $res[$i] = $j;
    }
    if ($timedOut !== array()) {
        fail('HARNESS-TIMEOUT ' . count($timedOut) . ' worker(s) killed after ' . proc_child_timeout() . 's: ' . implode(', ', $timedOut));
    }
    return $res;
}

/** 한 라운드: 외부 계정 1개 · 계획 N개(사람 N명) · 동시 commit N개 → 정확히 1개 done. */
function alt_sc_race(array $B, int $n, int $rounds): void
{
    for ($r = 1; $r <= $rounds; $r++) {
        alt_reset_rl($B);
        $eid = 'race-' . $n . '-' . $r;
        alt_ok(alt_push($B, 'svc-l', array(array('external_id' => $eid, 'status' => 'active'))), 'seed race');
        $pdo = alt_pdo($B);
        $users = array();
        for ($k = 0; $k < $n; $k++) {
            $uuid = gen_uuid4();
            $nm = 'race' . $n . '_' . $r . '_' . $k;
            $pdo->prepare('INSERT INTO app_ulogin_users (uuid, username, email, password_hash, status, account_level, role,'
                . ' billing_tier, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute(array($uuid, $nm, $nm . '@test.invalid', 'x', 'active', 'basic', 'user', 'free', alt_now(), alt_now()));
            $users[] = array('id' => (int) $pdo->lastInsertId(), 'uuid' => $uuid);
        }
        $pdo = null;
        $plans = array();
        foreach ($users as $usr) {
            $plans[] = alt_plan1($B, 'sa', 'link', 'svc-l', $eid, $usr['uuid'])['plan_id'];
        }
        $jobs = array();
        foreach ($plans as $pid) {
            $jobs[] = array('bearer' => $B['fx']['tok']['sa'], 'post' => array('action' => 'commit', 'plan_id' => $pid));
        }
        $res = alt_spawn($B, $jobs, 1.2 + 0.1 * $n);
        $done = 0;
        $lost = 0;
        $err = array();
        foreach ($res as $x) {
            if ($x['spin_lead'] === null || (float) $x['spin_lead'] <= 0.0) {
                fail('HARNESS-FAIL worker reached the start line late or died: ' . substr(json_encode($x), 0, 300));
            }
            $j = json_decode((string) $x['body'], true);
            if (!is_array($j) || $j['ok'] !== true || (int) $x['status'] >= 500) {
                $err[] = substr((string) $x['body'], 0, 200) . ' ' . json_encode($x['fatal']);
                continue;
            }
            $st = $j['data']['results'][0]['status'];
            $code = $j['data']['results'][0]['error_code'];
            if ($st === 'done') {
                $done++;
            } elseif ($st === 'failed' && in_array($code, array('EXT_ALREADY_LINKED', 'EXT_CHANGED'), true)) {
                $lost++;
            } else {
                $err[] = $st . ' ' . $code;
            }
        }
        $row = alt_ext($B, 'svc-l', $eid);
        $integ = alt_q($B, 'PRAGMA integrity_check');
        $doneItems = (int) alt_q($B, "SELECT COUNT(*) AS n FROM app_ulogin_link_job_items WHERE external_id = ? AND status = 'done'",
            array($eid))[0]['n'];
        note(sprintf('race N=%d round %d: done=%d lost=%d err=%d items_done=%d owner=%s integrity=%s', $n, $r, $done, $lost,
            count($err), $doneItems, (string) $row['user_id'], (string) reset($integ[0])));
        assert_eq(array(), $err, 'no 5xx / non-envelope / unexpected item code under contention');
        assert_eq(1, $done, 'exactly one commit links the account');
        assert_eq($n - 1, $lost, 'the rest fail with EXT_ALREADY_LINKED / EXT_CHANGED');
        assert_eq(1, $doneItems, 'one done item (feed sees one linked event)');
        assert_eq('linked', $row['state'], 'state linked');
        assert_eq('ok', (string) reset($integ[0]), 'integrity_check');
    }
}

// =====================================================================
// T11 — 마이그레이션 0005 (SQLite): 0004 상태 → apply → verify · 재적용 무변경 · NULL 다중 UNIQUE
// =====================================================================

function alt_sc_migrate(array $B): void
{
    $schema = (string) file_get_contents($B['root'] . '/server/schema.sql');
    // 0004 까지의 모양 = 0005 가 더하는 것(표 3개 · purpose 컬럼)을 뺀 schema.sql
    $old = preg_replace('~^CREATE TABLE IF NOT EXISTS `app_ulogin_(?:ext_accounts|link_jobs|link_job_items)` \(.*?\n\) ENGINE[^;]*;~ms', '', $schema, -1, $nt);
    $old = preg_replace('~^\s*`purpose`[^\n]*\n~m', '', (string) $old, -1, $nc);
    if ($nt < 1 || $nc !== 1) {
        fail('HARNESS-FAIL could not derive the 0004-state schema (tables ' . $nt . ', purpose ' . $nc . ')');
    }
    $oldFile = $B['base'] . '/schema_0004.sql';
    file_put_contents($oldFile, $old);
    $db = $B['base'] . '/data/ulogin_test_al_mig.sqlite';
    $pdo = new PDO('sqlite:' . $db, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    mig_sqlite_create_schema($pdo, $oldFile, 'app_ulogin_', false);
    $files = array();
    foreach (mig_list($B['root'] . '/server/migrations') as $f) {
        if (strcmp((string) $f['version'], '0005') < 0) {
            $files[] = $f;
        }
    }
    if (count($files) !== 4) {
        fail('HARNESS-FAIL expected 4 migrations before 0005, found ' . count($files));
    }
    mig_stamp_baseline($pdo, $files, 'app_ulogin_schema_migrations', 'test');
    $pdo->exec("INSERT INTO app_ulogin_password_resets (token_hash, user_id, expires_at, created_ip, created_at)"
        . " VALUES ('" . str_repeat('a', 64) . "', 1, '2030-01-01 00:00:00', '127.0.0.1', '2026-01-01 00:00:00')");
    $pdo = null;
    $cfgFile = $B['root'] . '/server/config.php';
    $cfg = (string) file_get_contents($cfgFile);
    file_put_contents($cfgFile, alt_set_define($cfg, 'DB_SQLITE_PATH', var_export($db, true)));
    $run = function ($cmd) use ($B) {
        $code = 0;
        $out = h_run(h_q(h_php_bin()) . ' -c ' . h_q(alt_ini()) . ' ' . h_q($B['root'] . '/server/migrate.php') . ' ' . $cmd, $code);
        return array($code, $out);
    };
    list($c1, $o1) = $run('apply');
    assert_eq(0, $c1, 'migrate apply 0004 -> 0005 (' . substr($o1, -600) . ')');
    assert_contains('0005', $o1, 'apply ran 0005');
    assert_eq(3, $nt, 'the 0004-state schema lacked all three 0005 tables');
    list($c2, $o2) = $run('verify');
    assert_eq(0, $c2, 'verify after upgrade equals schema.sql (' . substr($o2, -600) . ')');
    list($c3, $o3) = $run('apply');
    assert_eq(0, $c3, 'second apply is a no-op (' . substr($o3, -300) . ')');
    list($c4, $o4) = $run('status');
    assert_eq(0, $c4, 'status clean (' . substr($o4, -300) . ')');
    $pdo = new PDO('sqlite:' . $db, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    assert_eq('reset', (string) $pdo->query('SELECT purpose FROM app_ulogin_password_resets')->fetchColumn(), 'existing reset row gets purpose=reset');
    $ins = $pdo->prepare('INSERT INTO app_ulogin_ext_accounts (service_id, external_id, state, user_id, source_sent_at, first_seen_at,'
        . ' updated_at) VALUES (?,?,?,?,?,?,?)');
    $ins->execute(array('s', 'a', 'new', null, alt_now(), alt_now(), alt_now()));
    $ins->execute(array('s', 'b', 'new', null, alt_now(), alt_now(), alt_now()));
    $ins->execute(array('s', 'c', 'linked', 7, alt_now(), alt_now(), alt_now()));
    $dup = false;
    try {
        $ins->execute(array('s', 'd', 'linked', 7, alt_now(), alt_now(), alt_now()));
    } catch (PDOException $e) {
        $dup = stripos($e->getMessage(), 'UNIQUE') !== false;
    }
    $pdo = null;
    assert_true($dup, 'uq_ext_accounts_user rejects a second account for the same (service, user); NULLs coexist');
}

// =====================================================================
// 케이스
// =====================================================================

$ALT_MAIN = null;
$ALT_GET = function () use (&$ALT_MAIN) {
    if ($ALT_MAIN === null) {
        $ALT_MAIN = alt_box('main');
    }
    return $ALT_MAIN;
};

$ALT_CASES = array(
    'T4 push shape, replay guards (duplicate / page order / sent_at / monotonic), final -> gone, limits, "@admin" id' => 'alt_sc_push',
    'T3 read=admin, write=superadmin, global role only (service override superadmin is refused)' => 'alt_sc_perm',
    'T9 plan changes no state; LINK_PLAN_INVALID for foreign / cancelled / expired / finished plans' => 'alt_sc_plan',
    'T1 a stale preview cannot move an account to a new owner (row_version)' => 'alt_sc_stale',
    'T2 one external account per service per ulogin user (UNIQUE decides at commit)' => 'alt_sc_uq',
    'D-328 admin-flagged external account onto rank<20 user = warning, not a block; disabled = warning' => 'alt_sc_warn',
    'T7 exchange/session account_link: null / object / only state=linked; old keys intact' => 'alt_sc_exchange',
    'T5 T6 import creates one default-grade account; setup link once, hashed, 72h, reissue voids old, no signup bypass' => 'alt_sc_create',
    'new-account verdicts and commit-time dependency failure, retry plan' => 'alt_sc_newuser',
    'T8 unlink revokes that service\'s token sessions; feed; withdraw/hard delete auto-release; service delete purges' => 'alt_sc_unlink',
    'sync request, view shapes, file import shares the push validator (H-4)' => 'alt_sc_views',
);
foreach ($ALT_CASES as $name => $fn) {
    t($name, function () use ($ALT_GET, $fn) {
        $fn($ALT_GET());
    });
}

t('T10 s2s auth: 401 + login log, dummy bcrypt timing, 429 after 10 failures, secrets never logged', function () {
    alt_sc_auth(alt_box('auth'));
});

t('T1 race: N concurrent commits of one external account to different users -> exactly one done (SQLite, real processes)', function () use ($ALT_GET) {
    $B = $ALT_GET();
    $quick = getenv('ULOGIN_RACE_QUICK') === '1';
    alt_sc_race($B, 4, $quick ? 1 : 3);
    alt_sc_race($B, 8, 1);
});

t('T11 migration 0005 on SQLite: 0004 state -> apply -> verify clean, re-apply no-op, NULL multi-UNIQUE', function () {
    alt_sc_migrate(alt_box('mig', array(), false));
});

// ---- 변이 (D-186) — 변이 사본에서 같은 시나리오가 실패해야 한다 ----
$AL = 'server/core/accountlink.php';
$OPS = 'server/api/admin/account_link_ops.php';
$ALT_MUTANTS = array(
    'sent_at window removed' => array(array(array($AL, '    if (!$fromFile && abs($skew) > AL_SENT_AT_SKEW) {',
        '    if (false && !$fromFile && abs($skew) > AL_SENT_AT_SKEW) {')), 'alt_sc_push'),
    'page order check removed' => array(array(array($AL, '            if ($p[\'page\'] !== $last + 1) {',
        '            if (false && $p[\'page\'] !== $last + 1) {')), 'alt_sc_push'),
    'duplicate page check removed' => array(array(array($AL, '            if ($p[\'page\'] <= $last) {',
        '            if (false && $p[\'page\'] <= $last) {')), 'alt_sc_push'),
    'monotonic source_sent_at removed' => array(array(array($AL, '            if (strcmp((string) $cur[\'source_sent_at\'], $p[\'sent_at\']) > 0) {',
        '            if (false && strcmp((string) $cur[\'source_sent_at\'], $p[\'sent_at\']) > 0) {')), 'alt_sc_push'),
    'account_link_for ignores state' => array(array(array($AL, ". ' WHERE service_id = ? AND user_id = ? AND state = ?', [\$serviceId, \$userId, 'linked']);",
        ". ' WHERE service_id = ? AND user_id = ? AND state <> ?', [\$serviceId, \$userId, 'x-none']);")), 'alt_sc_exchange'),
    'row_version guard removed' => array(array(array($OPS, "\$sql .= ' WHERE id = ? AND row_version = ? AND state IN (' . \$fromPh . ')';",
        "\$sql .= ' WHERE id = ? AND row_version >= 0 * ? AND state IN (' . \$fromPh . ')';")), 'alt_sc_stale'),
    'uq_ext_accounts_user removed from schema' => array(array(array('server/schema.sql', "  UNIQUE KEY `uq_ext_accounts_user` (`service_id`, `user_id`),\n", '')), 'alt_sc_uq'),
    'require_global_role -> effective role' => array(array(
        array('server/api/admin/account_links.php', "require_global_role(\$ctx, CAN_MAP['account_link.view']);", "require_role(\$ctx, CAN_MAP['account_link.view']);"),
        array($OPS, "require_global_role(\$ctx, CAN_MAP['account_link.view']);", "require_role(\$ctx, CAN_MAP['account_link.view']);"),
        array($OPS, "    require_global_role(\$ctx, CAN_MAP['account_link.manage']);", "    require_role(\$ctx, CAN_MAP['account_link.manage']);")), 'alt_sc_perm'),
    'setup URL written into admin_logs' => array(array(array($OPS, "\$after['setup_link_issued'] = true;", "\$after['setup_link_issued'] = \$setup['url'];")), 'alt_sc_create'),
    'body role passed into the new account' => array(array(
        array($OPS, "            'name'     => al_opt_str(\$n['name'] ?? null, 80),\n        ];",
            "            'name'     => al_opt_str(\$n['name'] ?? null, 80), 'role' => isset(\$n['role']) ? (string) \$n['role'] : 'user',\n        ];"),
        array($OPS, "\$pj[] = ['ref' => \$nu['ref'], 'username' => \$nu['username'], 'email' => \$nu['email'], 'name' => \$nu['name']];",
            "\$pj[] = ['ref' => \$nu['ref'], 'username' => \$nu['username'], 'email' => \$nu['email'], 'name' => \$nu['name'], 'role' => \$nu['role']];"),
        array($OPS, "                        'role'                => 'user',", "                        'role'                => (string) (\$nu['role'] ?? 'user'),")), 'alt_sc_create'),
    'unlink session revoke removed' => array(array(array($OPS, '                al_revoke_link_sessions((int) $ext[\'user_id\'], $sid);', '                // removed')), 'alt_sc_unlink'),
    'plan executes a transition' => array(array(array($OPS, "        \$codes   = alo_sort_codes(\$codes);",
        "        db_exec('UPDATE ' . T_EXT_ACCOUNTS . ' SET row_version = row_version + 1 WHERE service_id = ? AND external_id = ?', [\$it['service_id'], \$it['external_id']]);\n        \$codes   = alo_sort_codes(\$codes);")), 'alt_sc_plan'),
    'dummy bcrypt removed from s2s auth' => array(array(array($AL, '        dummy_password_verify((string) $secret);', '        // removed')), 'alt_sc_timing'),
    'Q-1 feed fallback reads before_json only (v1.8.4 shape)' => array(array(array($AL, "            foreach (((string) \$r['op'] === 'unlink') ? [\$b, \$a] : [\$a, \$b] as \$j) {",
        "            foreach ([\$b] as \$j) {")), 'alt_sc_unlink'),
    'Q-3 external-account guard removed from service delete' => array(array(array('server/api/admin/services.php',
        '$needForce = $userCount > 0 || $extCount > 0;', '$needForce = $userCount > 0;')), 'alt_sc_unlink'),
);
foreach ($ALT_MUTANTS as $label => $spec) {
    t('mutant: ' . $label, function () use ($label, $spec) {
        alt_mutant(substr(md5($label), 0, 6), $spec[0], $spec[1]);
    });
}
t('mutant: both conditional-UPDATE guards removed (race must catch)', function () use ($OPS) {
    alt_mutant('race', array(array($OPS, "\$sql .= ' WHERE id = ? AND row_version = ? AND state IN (' . \$fromPh . ')';",
        "\$sql .= ' WHERE id = ? AND row_version >= 0 * ? AND (1 = 1 OR state IN (' . \$fromPh . '))';")), function ($B) {
        alt_sc_race($B, 4, 1);
    });
});
t('mutant: one table dropped from schema.sql (migration must catch)', function () {
    alt_mutant('schema', array(array('server/schema.sql', 'CREATE TABLE IF NOT EXISTS `app_ulogin_link_job_items` (',
        'CREATE TABLE IF NOT EXISTS `app_ulogin_link_job_itemz` (')), function ($B) {
        alt_sc_migrate($B);
    }, false);
});

alt_teardown();
