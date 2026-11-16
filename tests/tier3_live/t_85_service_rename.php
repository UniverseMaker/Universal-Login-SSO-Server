<?php
declare(strict_types=1);

/**
 * 티어 3 — 서비스 id 개명·별칭 (v1.8.6) — 설계 `_workspace/104_architect_v186_service_id_rename.md` G1~G12 · D-341
 *
 * **SQLite 로 실제로 돈다**(t_80 과 같은 방식). 설치 루트 사본 + 임시 SQLite + `php -S`(라우터)로 실제
 * 엔드포인트를 부르고, 개명 함수(`core/servicerename.php`)와 CLI(`tools/register_services.php`)는
 * 사본 위의 자식 PHP 로 부른다. 경합(G12)은 자식 PHP N개가 같은 DB 파일에서 실제 exchange 를 돈다.
 *
 * 변이(D-186): 「그 줄을 바꾼 사본」에서 **같은 시나리오가 실패해야** 통과다. 치환이 정확히 한 번 맞지
 * 않으면 HARNESS-FAIL(0건 치환의 거짓 초록 금지). 시드가 실제로 들어갔는지 먼저 센다(규약 7·8).
 *
 * 무접촉: 사본·DB·handoff 는 `sys_get_temp_dir()/ulogin_test_sr_*` 아래뿐(접두 가드). 포트는 OS 가 준 빈
 * 포트(8080 금지). 종료는 `taskkill /F /T /PID <자기 PID>`. 운영 `runtime/handoff/` 는 열지 않는다
 * (CLI 에는 항상 `--handoff-dir=<사본 폴더>` 를 준다). `glob()` 을 쓰지 않는다.
 *
 * 못 잡는 것: MySQL 경로의 개명 트랜잭션(InnoDB). MySQL 이 없으면 t_20 이 0006 업그레이드를 SKIP 한다.
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

$GLOBALS['SRT'] = array('boxes' => array(), 'registered' => false);

const SRT_PW  = 'Passw0rd!x9';
const SRT_CB  = 'http://127.0.0.1:9/sso/callback';
const SRT_TABLES = array('services', 'user_services', 'service_secrets', 'sessions', 'auth_codes', 'login_logs',
    'point_logs', 'secret_access_logs', 'ext_accounts', 'link_jobs', 'link_job_items', 'access_group_services');

// =====================================================================
// 하네스 (t_80 과 같은 규약, 접두만 다르다)
// =====================================================================

function srt_rm(string $p): void
{
    if (strpos(h_norm($p), '/ulogin_test_sr_') === false) {
        return;
    }
    srt_rm_inner($p);
}

function srt_rm_inner(string $p): void
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
            srt_rm_inner($p . '/' . $n);
        }
    }
    @rmdir($p);
}

function srt_copy(string $src, string $dst): int
{
    @mkdir($dst, 0777, true);
    $n = 0;
    foreach (scandir($src) as $e) {
        if ($e === '.' || $e === '..' || $e === 'config.php' || $e === '.install.lock' || $e === '.installed') {
            continue;
        }
        if (is_dir($src . '/' . $e)) {
            $n += srt_copy($src . '/' . $e, $dst . '/' . $e);
        } else {
            copy($src . '/' . $e, $dst . '/' . $e);
            $n++;
        }
    }
    return $n;
}

function srt_teardown(): void
{
    foreach ($GLOBALS['SRT']['boxes'] as $b) {
        if (!empty($b['pid'])) {
            proc_kill_tree((int) $b['pid']);
        }
        if (isset($b['proc']) && is_resource($b['proc'])) {
            @proc_close($b['proc']);
        }
    }
    usleep(200000);
    foreach ($GLOBALS['SRT']['boxes'] as $b) {
        srt_rm($b['base']);
    }
    $GLOBALS['SRT']['boxes'] = array();
}

function srt_ini(): string
{
    $ini = php_ini_loaded_file();
    if (!is_string($ini) || $ini === '' || !is_file($ini)) {
        skip('runner has no php.ini (-n?) — children would lack pdo_sqlite; run with -c <ini>');
    }
    return $ini;
}

function srt_free_port(): int
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

function srt_set_define(string $src, string $name, string $phpValue): string
{
    $re = '/^define\(\'' . preg_quote($name, '/') . '\',\s*[^\n]*?\);/m';
    $n = 0;
    $out = preg_replace($re, 'define(\'' . $name . '\', ' . $phpValue . ');', $src, 1, $n);
    if ($n !== 1 || !is_string($out)) {
        fail('HARNESS-FAIL config.sample.php: define(' . $name . ') not found exactly once');
    }
    return $out;
}

/** 사본 하나. `$mut` = [[상대경로, 찾을 문자열, 바꿀 문자열], …] — 각각 정확히 1회. */
function srt_box(string $key, array $mut = array(), bool $serve = true): array
{
    require_ext('pdo_sqlite');
    $ini = srt_ini();
    if (!$GLOBALS['SRT']['registered']) {
        $GLOBALS['SRT']['registered'] = true;
        register_shutdown_function('srt_teardown');
    }
    $base = h_norm(sys_get_temp_dir()) . '/ulogin_test_sr_' . getmypid() . '_' . bin2hex(random_bytes(3)) . '_' . $key;
    $root = $base . '/root';
    $n  = srt_copy(h_root() . '/server', $root . '/server');
    $n += srt_copy(h_root() . '/public', $root . '/public');
    @mkdir($root . '/tools', 0777, true);
    foreach (array('router-ulogin.php', 'register_services.php') as $t) {
        copy(h_root() . '/tools/' . $t, $root . '/tools/' . $t);
    }
    $B = array('base' => $base, 'root' => $root, 'key' => $key, 'pid' => 0, 'proc' => null, 'port' => 0);
    $GLOBALS['SRT']['boxes'][] = &$B;
    if ($n < 50 || !is_file($root . '/server/core/servicerename.php')) {
        fail('HARNESS-FAIL sandbox copy has ' . $n . ' files or misses core/servicerename.php (scandir blind?)');
    }
    foreach ($mut as $m) {
        list($rel, $from, $to) = $m;
        $f = $root . '/' . $rel;
        $s = (string) @file_get_contents($f);
        $c = substr_count($s, $from);
        if ($c !== 1) {
            fail('HARNESS-FAIL mutation "' . substr($from, 0, 70) . '" matched ' . $c . ' times in ' . $rel);
        }
        file_put_contents($f, str_replace($from, $to, $s));
    }
    @mkdir($base . '/data', 0777, true);
    @mkdir($base . '/handoff', 0777, true);
    $db = $base . '/data/ulogin_test_sr.sqlite';
    $B['db'] = $db;
    $B['handoff'] = $base . '/handoff';
    $pdo = new PDO('sqlite:' . $db, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    mig_sqlite_create_schema($pdo, $root . '/server/schema.sql', 'app_ulogin_', true);
    $pdo = null;

    $port = srt_free_port();
    $B['port'] = $port;
    $cfg = (string) file_get_contents(h_root() . '/server/config.sample.php');
    $cfg = srt_set_define($cfg, 'DB_DRIVER', "'sqlite'");
    $cfg = srt_set_define($cfg, 'DB_SQLITE_PATH', var_export($db, true));
    $cfg = srt_set_define($cfg, 'SECRET_ENC_KEY', "'" . bin2hex(random_bytes(32)) . "'");
    $cfg = srt_set_define($cfg, 'APP_KEY', "'" . bin2hex(random_bytes(32)) . "'");
    $cfg = srt_set_define($cfg, 'FORCE_HTTPS', 'false');
    $cfg = srt_set_define($cfg, 'COOKIE_SECURE', 'false');
    $cfg = srt_set_define($cfg, 'BASE_URL', "'http://127.0.0.1:" . $port . "'");
    $cfg = srt_set_define($cfg, 'ALLOW_REGISTER', 'true');
    file_put_contents($root . '/server/config.php', $cfg);
    $B['fx'] = srt_seed($B);

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
        $verFile = $root . '/public/version.txt';
        $ver = (string) file_get_contents($verFile);
        $token = bin2hex(random_bytes(8));
        file_put_contents($verFile, $token . "\n");
        $ok = false;
        for ($i = 0; $i < 100; $i++) {
            $r = srt_http($B, 'GET', '/version.txt');
            if ($r['status'] === 200 && trim($r['body']) === $token) {
                $ok = true;
                break;
            }
            usleep(100000);
        }
        file_put_contents($verFile, $ver);   // 개명 CLI 의 판 검사가 읽는다 — 원래 값으로
        if (!$ok) {
            fail('HARNESS-FAIL php -S on port ' . $port . ' did not answer with this run\'s sentinel');
        }
    }
    $copy = $B;
    unset($B);
    return $copy;
}

function srt_pdo(array $B): PDO
{
    $pdo = new PDO('sqlite:' . $B['db'], null, null, array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
    $pdo->exec('PRAGMA busy_timeout = 8000');
    return $pdo;
}

function srt_q(array $B, string $sql, array $p = array()): array
{
    $pdo = srt_pdo($B);
    $st = $pdo->prepare($sql);
    $st->execute($p);
    $rows = $st->fetchAll();
    $pdo = null;
    return $rows;
}

function srt_v(array $B, string $sql, array $p = array())
{
    $r = srt_q($B, $sql, $p);
    return $r === array() ? null : reset($r[0]);
}

function srt_x(array $B, string $sql, array $p = array()): int
{
    $pdo = srt_pdo($B);
    $st = $pdo->prepare($sql);
    $st->execute($p);
    $n = $st->rowCount();
    $pdo = null;
    return $n;
}

function srt_now(int $off = 0): string
{
    return gmdate('Y-m-d H:i:s', time() + $off);
}

function srt_iso(int $off = 0): string
{
    return gmdate('Y-m-d\TH:i:s\Z', time() + $off);
}

function srt_http(array $B, string $method, string $path, $body = null, ?string $bearer = null, ?string $cookie = null): array
{
    $s = @fsockopen('127.0.0.1', (int) $B['port'], $errno, $errstr, 5.0);
    if ($s === false) {
        return array('status' => 0, 'body' => '', 'json' => null);
    }
    stream_set_timeout($s, 30);
    $json = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $req = $method . ' ' . $path . " HTTP/1.0\r\nHost: 127.0.0.1:" . $B['port'] . "\r\nConnection: close\r\n";
    if ($bearer !== null) {
        $req .= 'Authorization: Bearer ' . $bearer . "\r\n";
    }
    if ($cookie !== null) {
        $req .= 'Cookie: ULOGIN_SID=' . $cookie . "\r\n";
    }
    if ($json !== null) {
        $req .= "Content-Type: application/json\r\nContent-Length: " . strlen($json) . "\r\n";
    }
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
    $sep = strpos($raw, "\r\n\r\n");
    $head = $sep === false ? $raw : substr($raw, 0, $sep);
    $bodyS = $sep === false ? '' : substr($raw, $sep + 4);
    $status = preg_match('~^HTTP/\d\.\d (\d{3})~', $head, $m) ? (int) $m[1] : 0;
    $j = json_decode($bodyS, true);
    return array('status' => $status, 'body' => $bodyS, 'json' => is_array($j) ? $j : null);
}

function srt_code(array $r): string
{
    return is_array($r['json']) && isset($r['json']['error']['code']) ? (string) $r['json']['error']['code'] : '';
}

function srt_ok(array $r, string $what): array
{
    if ($r['status'] !== 200 || !is_array($r['json']) || $r['json']['ok'] !== true) {
        fail($what . ': expected 200 ok, got ' . $r['status'] . ' ' . substr($r['body'], 0, 400));
    }
    return $r['json']['data'];
}

function srt_err(array $r, int $status, string $code, string $what): void
{
    if ($r['status'] !== $status || srt_code($r) !== $code) {
        fail($what . ': expected ' . $status . ' ' . $code . ', got ' . $r['status'] . ' ' . substr($r['body'], 0, 400));
    }
}

/**
 * 사본 위에서 PHP 코드를 자식으로 돈다(bootstrap + servicerename 로드 뒤). `return` 값을 JSON 으로 받는다.
 * 예외는 {exc, msg, kind, detail} 로 돌아온다. 환경변수 $env 는 자식에만 준다.
 */
function srt_php(array $B, string $code, array $env = array()): array
{
    $f = $B['base'] . '/child_' . bin2hex(random_bytes(4)) . '.php';
    $out = $B['base'] . '/child_' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($f, "<?php\n"
        . "require " . var_export($B['root'] . '/server/core/bootstrap.php', true) . ";\n"
        . "require_once " . var_export($B['root'] . '/server/core/servicerename.php', true) . ";\n"
        . "require_once " . var_export($B['root'] . '/server/core/accountlink.php', true) . ";\n"
        . "set_exception_handler(null);\n"
        . "try { \$__r = (function () { " . $code . " })(); }\n"
        . "catch (Throwable \$e) { \$__r = ['exc' => get_class(\$e), 'msg' => \$e->getMessage(),"
        . " 'kind' => isset(\$e->kind) ? \$e->kind : null, 'detail' => isset(\$e->detail) ? \$e->detail : null]; }\n"
        . "file_put_contents(" . var_export($out, true) . ", json_encode(\$__r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));\n");
    foreach ($env as $k => $v) {
        putenv($k . '=' . $v);
    }
    $r = proc_run_limited(h_q(h_php_bin()) . ' -c ' . h_q(srt_ini()) . ' ' . h_q($f), 120.0, $B['base']);
    foreach ($env as $k => $v) {
        putenv($k);
    }
    $j = is_file($out) ? json_decode((string) file_get_contents($out), true) : null;
    if (!is_array($j) && $j !== null) {
        $j = array('value' => $j);
    }
    if ($j === null) {
        fail('HARNESS-FAIL child produced no result (exit ' . $r['exit'] . '): ' . substr($r['out'], -600));
    }
    return $j;
}

/** CLI(register_services.php) — 언제나 사본 handoff 폴더. */
function srt_cli(array $B, string $args, array $env = array()): array
{
    foreach ($env as $k => $v) {
        putenv($k . '=' . $v);
    }
    $r = proc_run_limited(h_q(h_php_bin()) . ' -c ' . h_q(srt_ini()) . ' ' . h_q($B['root'] . '/tools/register_services.php')
        . ' ' . $args . ' --handoff-dir=' . h_q($B['handoff']), 180.0, $B['base']);
    foreach ($env as $k => $v) {
        putenv($k);
    }
    return $r;
}

// =====================================================================
// 픽스처 — 옛 id 'mgm'(confidential) · 다른 서비스 'office' · 사람 sa(superadmin)·u1·u2
// =====================================================================

function srt_seed(array $B): array
{
    $pdo = srt_pdo($B);
    $now = srt_now();
    $hash = password_hash(SRT_PW, PASSWORD_DEFAULT);
    $fx = array('users' => array(), 'tok' => array(), 'secret' => array());
    $insS = $pdo->prepare('INSERT INTO app_ulogin_services (service_id, service_name, service_secret_hash, is_confidential,'
        . ' redirect_whitelist, auto_join, allow_register, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
    foreach (array('mgm' => 'Demo App A', 'office' => 'Demo AI App A') as $sid => $name) {
        $sec = 'sec_' . $sid . '_' . bin2hex(random_bytes(16));
        $fx['secret'][$sid] = $sec;
        $insS->execute(array($sid, $name, password_hash($sec, PASSWORD_DEFAULT), 1, json_encode(array(SRT_CB)), 1, 1, 'active', $now, $now));
    }
    $insU = $pdo->prepare('INSERT INTO app_ulogin_users (uuid, username, email, password_hash, status, account_level, role,'
        . ' billing_tier, meta_json, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    foreach (array('sa' => 'superadmin', 'u1' => 'user', 'u2' => 'user') as $k => $role) {
        $uuid = gen_uuid4();
        $name = 'srt_' . $k;
        $meta = $k === 'u2' ? json_encode(array('setup_pending' => false, 'imported_from' => array('service_id' => 'mgm', 'external_id' => 'e2'))) : null;
        $insU->execute(array($uuid, $name, $name . '@test.invalid', $hash, 'active', 'basic', $role, 'free', $meta, $now, $now));
        $fx['users'][$k] = array('id' => (int) $pdo->lastInsertId(), 'uuid' => $uuid, 'username' => $name);
    }
    $fx['tok']['sa'] = srt_token($pdo, $fx['users']['sa']['id'], null);
    $pdo = null;
    return $fx;
}

function srt_token(PDO $pdo, int $uid, ?string $sid, ?string $revoked = null): string
{
    $tok = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO app_ulogin_sessions (session_id, user_id, service_id, auth_type, access_token_hash,'
        . ' issued_at, expires_at, last_seen_at, ip, user_agent, revoked_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute(array(bin2hex(random_bytes(32)), $uid, $sid, 'token', hash('sha256', $tok),
            srt_now(-5), srt_now(3600), srt_now(-5), '127.0.0.1', 'srt', $revoked));
    return $tok;
}

/** G3 시드 — 11개 표 전부에 mgm 행(그리고 office 행 대조군). 실제로 들어갔는지 센다. */
function srt_seed_all(array $B): array
{
    $u1 = $B['fx']['users']['u1']['id'];
    $u2 = $B['fx']['users']['u2']['id'];
    $now = srt_now();
    $pdo = srt_pdo($B);
    foreach (array('mgm', 'office') as $sid) {
        $pdo->prepare('INSERT INTO app_ulogin_user_services (user_id, service_id, status, joined_at) VALUES (?,?,?,?)')
            ->execute(array($u1, $sid, 'active', $now));
        $pdo->prepare('INSERT INTO app_ulogin_auth_codes (code_hash, user_id, service_id, redirect_uri, expires_at, created_ip, created_at)'
            . ' VALUES (?,?,?,?,?,?,?)')->execute(array(bin2hex(random_bytes(32)), $u1, $sid, SRT_CB, srt_now(60), '127.0.0.1', $now));
        $pdo->prepare('INSERT INTO app_ulogin_login_logs (user_id, username_input, service_id, result, ip, created_at) VALUES (?,?,?,?,?,?)')
            ->execute(array($u1, 'srt_u1', $sid, 'success', '127.0.0.1', $now));
        $pdo->prepare('INSERT INTO app_ulogin_point_logs (user_id, delta, balance_after, reason, service_id, created_at) VALUES (?,?,?,?,?,?)')
            ->execute(array($u1, 5, 5, 'usage', $sid, $now));
        $pdo->prepare('INSERT INTO app_ulogin_secret_access_logs (user_id, service_id, session_id, source, window_start, secret_keys,'
            . ' first_ip, last_ip, first_at, last_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute(array($u1, $sid, bin2hex(random_bytes(32)), 'exchange', $now, 'API_KEY', '127.0.0.1', '127.0.0.1', $now, $now));
        $pdo->prepare('INSERT INTO app_ulogin_ext_accounts (service_id, external_id, username, state, user_id, link_origin, linked_at,'
            . ' row_version, source_sent_at, first_seen_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute(array($sid, 'e1', 'hong', 'linked', $u1, 'attached', $now, 1, $now, $now, $now));
        $pdo->prepare('INSERT INTO app_ulogin_link_jobs (job_uuid, kind, service_id, status, created_at, updated_at) VALUES (?,?,?,?,?,?)')
            ->execute(array(gen_uuid4(), 'push', $sid, 'done', $now, $now));
        $job = (int) $pdo->lastInsertId();
        for ($k = 1; $k <= 3; $k++) {
            $pdo->prepare('INSERT INTO app_ulogin_link_job_items (job_id, seq, op, service_id, external_id, verdict, status, result_user_id,'
                . ' created_at, done_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute(array($job, $k, 'link', $sid, 'e' . $k, 'ok', 'done', $u1, $now, $now));
        }
        srt_token($pdo, $u1, $sid);
        srt_token($pdo, $u1, $sid, $now);   // 회수된 세션 — client_service_id 를 받지 않아야 한다
        // v1.10.0 — 그룹 단계 행(설계 127 §1 연쇄 처리). 개명이 이 표를 빠뜨리면 그룹 권한이 조용히 사라진다
        $pdo->prepare('INSERT INTO app_ulogin_access_groups (group_key, name, status, created_at, updated_at) VALUES (?,?,?,?,?)')
            ->execute(array('g-' . $sid, 'G ' . $sid, 'active', $now, $now));
        $pdo->prepare('INSERT INTO app_ulogin_access_group_services (group_id, service_id, level, updated_at) VALUES (?,?,?,?)')
            ->execute(array((int) $pdo->lastInsertId(), $sid, 'use', $now));
    }
    $pdo->prepare('INSERT INTO app_ulogin_admin_logs (admin_id, action, target_type, target_id, before_json, after_json, ip, created_at)'
        . ' VALUES (?,?,?,?,?,?,?,?)')->execute(array($B['fx']['users']['sa']['id'], 'service.update', 'service', 'mgm',
            '{"service_name":"x"}', '{"service_name":"Demo App A"}', '127.0.0.1', $now));
    $pdo = null;
    // 시크릿 1개 — 암호화는 사본의 키로(자식)
    srt_php($B, "db_insert(T_SECRETS, ['service_id' => 'mgm', 'secret_key' => 'API_KEY', 'secret_value_enc' => enc('v-123'),"
        . " 'status' => 'active', 'created_at' => now_utc(), 'updated_at' => now_utc()]); return ['ok' => true];");
    $counts = srt_counts($B, 'mgm');
    foreach (SRT_TABLES as $t) {
        if ($counts[$t] < 1) {
            fail('HARNESS-FAIL seed did not reach ' . $t . ' (count 0) — the rename check would be blind there');
        }
    }
    return $counts;
}

function srt_counts(array $B, string $sid): array
{
    $o = array();
    foreach (SRT_TABLES as $t) {
        $o[$t] = (int) srt_v($B, 'SELECT COUNT(*) FROM app_ulogin_' . $t . ' WHERE service_id = ?', array($sid));
    }
    return $o;
}

/** 전 표 스냅숏. sessions.last_seen_at 은 뺀다(요청마다 갱신 — 101 T9 교훈). $ignore = ['표.열', …] */
function srt_snap(array $B, array $ignore = array()): array
{
    $out = array();
    foreach (srt_q($B, "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'app_ulogin_%' ORDER BY name") as $t) {
        $name = (string) $t['name'];
        $rows = srt_q($B, 'SELECT * FROM "' . $name . '" ORDER BY rowid');
        foreach ($rows as &$r) {
            unset($r['last_seen_at']);
            foreach ($ignore as $ig) {
                list($it, $ic) = explode('.', $ig);
                if ('app_ulogin_' . $it === $name) {
                    unset($r[$ic]);
                }
            }
        }
        unset($r);
        $out[$name] = $rows;
    }
    return $out;
}

function srt_snap_diff(array $a, array $b): array
{
    $d = array();
    foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $t) {
        $x = isset($a[$t]) ? json_encode($a[$t]) : 'none';
        $y = isset($b[$t]) ? json_encode($b[$t]) : 'none';
        if ($x !== $y) {
            $d[] = $t;
        }
    }
    return $d;
}

function srt_rename(array $B, string $from, string $to, array $env = array()): array
{
    return srt_php($B, 'return service_rename(' . var_export($from, true) . ', ' . var_export($to, true) . ', '
        . (int) $B['fx']['users']['sa']['id'] . ');', $env);
}

/** 사용자 비밀번호 로그인 → code (authorize). */
function srt_authorize(array $B, string $sid, string $who = 'u1'): string
{
    srt_x($B, 'DELETE FROM app_ulogin_rate_limits');
    $d = srt_ok(srt_http($B, 'POST', '/server/api/sso/authorize.php', array('username' => 'srt_' . $who, 'password' => SRT_PW,
        'service_id' => $sid, 'redirect_uri' => SRT_CB, 'state' => 'st1')), 'authorize ' . $sid);
    return (string) $d['code'];
}

function srt_exchange(array $B, string $code, string $sid, string $secretOf = 'mgm'): array
{
    return srt_http($B, 'POST', '/server/api/auth/exchange.php', array('code' => $code, 'service_id' => $sid,
        'redirect_uri' => SRT_CB, 'service_secret' => $B['fx']['secret'][$secretOf]));
}

function srt_push(array $B, string $sid, array $accounts): array
{
    return srt_http($B, 'POST', '/server/api/sso/accounts_push.php', array('service_id' => $sid,
        'service_secret' => $B['fx']['secret']['mgm'], 'sync_id' => gen_uuid4(), 'page' => 1, 'final' => false,
        'sent_at' => srt_iso(), 'accounts' => $accounts));
}

function srt_feed(array $B, string $sid, int $after): array
{
    return srt_ok(srt_http($B, 'POST', '/server/api/sso/link_events.php', array('service_id' => $sid,
        'service_secret' => $B['fx']['secret']['mgm'], 'after' => $after)), 'link_events ' . $sid . ' after=' . $after);
}

function srt_event_ids(array $feed): array
{
    $o = array();
    foreach ($feed['events'] as $e) {
        $o[] = (int) $e['event_id'];
    }
    return $o;
}

/** 비밀 값이 없어야 한다 — 실패 메시지에 그 값을 찍지 않는다(assert_not_contains 는 찾는 값을 인쇄한다). */
function srt_absent(string $needle, string $hay, string $what): void
{
    if ($needle === '') {
        fail('HARNESS-FAIL empty needle for: ' . $what);
    }
    if (strpos($hay, $needle) !== false) {
        fail($what . ' (value withheld from this message)');
    }
}

/** 변이 사본에서 시나리오가 **실패해야** 한다. */
function srt_mutant(string $key, array $mut, callable $scenario, bool $serve = true): void
{
    $B = srt_box('mut_' . $key, $mut, $serve);
    $died = null;
    try {
        $scenario($B);
    } catch (Throwable $e) {
        $died = $e;
    }
    if ($died === null) {
        fail('mutant ' . $key . ' SURVIVED — the scenario does not guard the line it claims to');
    }
    note('mutant ' . $key . ' caught: ' . substr($died->getMessage(), 0, 150));
}

// =====================================================================
// 시나리오
// =====================================================================

/** G1 — 해석: 정식 · 별칭 · 끝난 별칭 · 없는 id */
function srt_sc_resolve(array $B): void
{
    srt_x($B, "UPDATE app_ulogin_services SET service_id = 'admin', former_service_id = 'mgm' WHERE service_id = 'mgm'");
    srt_x($B, "UPDATE app_ulogin_services SET service_id = 'task', former_service_id = 'mcc', former_until = ? WHERE service_id = 'office'",
        array(srt_now(-60)));
    $r = srt_php($B, "return ['a' => service_id_resolve('admin'), 'm' => service_id_resolve('mgm'), 'c' => service_id_resolve('mcc'),"
        . " 'z' => service_id_resolve('zzz'), 't' => service_id_taken('mcc'), 'tz' => service_id_taken('zzz')];");
    assert_eq(array('canonical' => 'admin', 'presented' => 'admin', 'via_alias' => false), $r['a'], 'canonical id resolves to itself');
    assert_eq(array('canonical' => 'admin', 'presented' => 'mgm', 'via_alias' => true), $r['m'], 'alias resolves to the new id');
    assert_eq(null, $r['c'], 'an ended alias (former_until past) does not resolve');
    assert_eq(null, $r['z'], 'unknown id does not resolve');
    assert_eq('former', $r['t'], 'an ended alias is still taken (no reuse)');
    assert_eq(null, $r['tz'], 'unknown id is free');
}

/** G3 — 개명 전수 + 감사 불변 + 세션 echo 기억 */
function srt_sc_rename_all(array $B): void
{
    $before = srt_seed_all($B);
    $logBefore = srt_q($B, 'SELECT * FROM app_ulogin_admin_logs ORDER BY id');
    $officeBefore = srt_counts($B, 'office');
    $r = srt_rename($B, 'mgm', 'admin');
    assert_eq('renamed', $r['status'] ?? ('EXC ' . ($r['msg'] ?? '?')), 'rename status');
    assert_eq(array_fill_keys(SRT_TABLES, 0), srt_counts($B, 'mgm'), 'no row keeps the old id (all 11 tables)');
    assert_eq($before, srt_counts($B, 'admin'), 'per-table counts moved 1:1');
    assert_eq($officeBefore, srt_counts($B, 'office'), 'other service untouched');
    $svc = srt_q($B, "SELECT former_service_id, former_until FROM app_ulogin_services WHERE service_id = 'admin'");
    assert_eq(array(array('former_service_id' => 'mgm', 'former_until' => null)), $svc, 'services row keeps the old id as alias');
    $meta = json_decode((string) srt_v($B, 'SELECT meta_json FROM app_ulogin_users WHERE id = ?', array($B['fx']['users']['u2']['id'])), true);
    assert_eq('admin', $meta['imported_from']['service_id'] ?? null, 'users.meta_json imported_from.service_id renamed');
    $cs = srt_q($B, "SELECT client_service_id, revoked_at IS NULL AS live FROM app_ulogin_sessions WHERE service_id = 'admin' ORDER BY id");
    assert_eq(array(array('client_service_id' => 'mgm', 'live' => 1), array('client_service_id' => null, 'live' => 0)), $cs,
        'live session remembers the old id for echo; revoked one does not');
    $logAfter = srt_q($B, 'SELECT * FROM app_ulogin_admin_logs ORDER BY id');
    assert_eq($logBefore, array_slice($logAfter, 0, count($logBefore)), 'existing admin_logs rows are byte-identical (immutable)');
    assert_eq(count($logBefore) + 1, count($logAfter), 'exactly one audit row added');
    $last = end($logAfter);
    assert_eq(array('service.rename', 'service', 'admin'), array($last['action'], $last['target_type'], $last['target_id']), 'audit row');
    $h = (string) srt_v($B, "SELECT service_secret_hash FROM app_ulogin_services WHERE service_id = 'admin'");
    srt_absent($h, (string) $last['after_json'] . (string) $last['before_json'], 'audit row carries no secret hash');
    assert_eq('ok', (string) srt_v($B, 'PRAGMA integrity_check'), 'integrity_check');
    $uq = srt_v($B, 'SELECT COUNT(*) FROM (SELECT user_id, service_id FROM app_ulogin_user_services GROUP BY 1, 2 HAVING COUNT(*) > 1)');
    assert_eq(0, (int) $uq, 'no UNIQUE duplicates (user_services)');
}

/** G4 — 원자성: 5번째 표 뒤 실패 주입 → 전부 그대로 */
function srt_sc_atomic(array $B): void
{
    srt_seed_all($B);
    $snap = srt_snap($B);
    $r = srt_rename($B, 'mgm', 'admin', array('ULOGIN_TEST_RENAME_FAIL_AFTER' => '5'));
    assert_contains('test-injected failure', (string) ($r['msg'] ?? json_encode($r)), 'the injected failure fired');
    assert_eq(array(), srt_snap_diff($snap, srt_snap($B)), 'tables changed by a failed rename');
}

/** G5 — 재실행 무변경 · 되돌리기 = 개명 전 · 고아 행이면 중단 */
function srt_sc_rerun_revert(array $B): void
{
    srt_seed_all($B);
    $pre = srt_snap($B, array('services.updated_at', 'sessions.client_service_id'));
    $logs0 = (int) srt_v($B, 'SELECT COUNT(*) FROM app_ulogin_admin_logs');
    assert_eq('renamed', srt_rename($B, 'mgm', 'admin')['status'] ?? null, 'first rename');
    $mid = srt_snap($B);
    $again = srt_rename($B, 'mgm', 'admin');
    assert_eq('already', $again['status'] ?? null, 'second run says already renamed');
    assert_eq(array(), srt_snap_diff($mid, srt_snap($B)), 'tables changed by the re-run');
    $rv = srt_php($B, 'return service_rename_revert(\'admin\', ' . (int) $B['fx']['users']['sa']['id'] . ');');
    assert_eq('reverted', $rv['status'] ?? ('EXC ' . ($rv['msg'] ?? '?')), 'revert status');
    $post = srt_snap($B, array('services.updated_at', 'sessions.client_service_id'));
    unset($pre['app_ulogin_admin_logs'], $post['app_ulogin_admin_logs']);
    assert_eq(array(), srt_snap_diff($pre, $post), 'after revert the DB equals the pre-rename state');
    assert_eq($logs0 + 2, (int) srt_v($B, 'SELECT COUNT(*) FROM app_ulogin_admin_logs'), 'rename + revert audit rows');
    assert_eq(0, (int) srt_v($B, 'SELECT COUNT(*) FROM app_ulogin_sessions WHERE client_service_id IS NOT NULL'), 'revert clears client_service_id');
    // 고아 행
    srt_x($B, "INSERT INTO app_ulogin_login_logs (service_id, result, ip, created_at) VALUES ('admin', 'fail_client_auth', '127.0.0.1', ?)",
        array(srt_now()));
    $snap = srt_snap($B);
    $o = srt_rename($B, 'mgm', 'admin');
    assert_eq('refused', $o['kind'] ?? null, 'orphan row refuses the rename (' . json_encode($o) . ')');
    assert_eq(array('app_ulogin_login_logs' => 1), $o['detail']['orphans'] ?? null, 'orphan counts are reported per table');
    assert_eq(array(), srt_snap_diff($snap, srt_snap($B)), 'tables changed by a refused rename');
}

/** G6 + 시스템 운용 시험 ① — 옛 id SSO 종주 · 새 id · 섞어 쓰기 · logout · userinfo · users.php */
function srt_sc_sso(array $B): void
{
    srt_x($B, 'DELETE FROM app_ulogin_rate_limits');
    $u1 = $B['fx']['users']['u1'];
    srt_seed_all($B);
    // 개명 전 기준값
    $pre = srt_ok(srt_exchange($B, srt_authorize($B, 'mgm'), 'mgm'), 'exchange before rename');
    $preBearer = (string) $pre['access_token'];
    $codeOldBefore  = srt_authorize($B, 'mgm');     // 옛 id 로 시작 → 개명 → 새 id 로 교환
    $codeOldBefore2 = srt_authorize($B, 'mgm');     // 옛 id 로 시작 → 개명 → 옛 id 로 교환
    assert_eq('renamed', srt_rename($B, 'mgm', 'admin')['status'] ?? null, 'rename');

    // validate — 보낸 id 를 되돌린다
    $v = srt_ok(srt_http($B, 'GET', '/server/api/sso/validate.php?service_id=mgm&redirect_uri=' . rawurlencode(SRT_CB)), 'validate old');
    assert_eq('mgm', $v['service_id'], 'validate echoes the old id');
    assert_eq('Demo App A', $v['service_name'], 'validate same service');
    $v2 = srt_ok(srt_http($B, 'GET', '/server/api/sso/validate.php?service_id=admin&redirect_uri=' . rawurlencode(SRT_CB)), 'validate new');
    assert_eq('admin', $v2['service_id'], 'validate new id');

    // 옛 id 종주
    $x = srt_ok(srt_exchange($B, srt_authorize($B, 'mgm'), 'mgm'), 'exchange old id');
    assert_eq(array('mgm', 'mgm'), array($x['user']['service_id'], $x['session']['service_id']), 'exchange echoes old id');
    assert_eq($pre['payload'], $x['payload'], 'payload (secrets) unchanged by the rename');
    assert_eq('v-123', $x['payload']['API_KEY'] ?? null, 'payload carries the secret (not an empty-equals-empty pass)');
    assert_eq($pre['account_link'], $x['account_link'], 'account_link unchanged by the rename');
    assert_true(is_array($x['account_link']) && $x['account_link']['external_id'] === 'e1', 'account_link present');
    $s = srt_ok(srt_http($B, 'GET', '/server/api/auth/session.php', null, (string) $x['access_token']), 'session old');
    assert_eq(array('mgm', 'mgm'), array($s['user']['service_id'], $s['session']['service_id']), 'session echoes old id');
    $rf = srt_ok(srt_http($B, 'POST', '/server/api/auth/refresh.php', array('refresh_token' => $x['refresh_token'])), 'refresh old');
    assert_eq(array('mgm', 'mgm'), array($rf['user']['service_id'], $rf['session']['service_id']), 'refresh echoes old id');
    $s2 = srt_ok(srt_http($B, 'GET', '/server/api/auth/session.php', null, (string) $rf['access_token']), 'session after refresh');
    assert_eq('mgm', $s2['session']['service_id'], 'rotation keeps the echo memory');
    // 개명 전에 받은 Bearer — 개명 뒤에도 옛 id 로 보인다
    $sp = srt_ok(srt_http($B, 'GET', '/server/api/auth/session.php', null, $preBearer), 'pre-rename bearer');
    assert_eq('mgm', $sp['session']['service_id'], 'pre-rename session still echoes the old id');

    // 새 id 종주
    $n = srt_ok(srt_exchange($B, srt_authorize($B, 'admin'), 'admin'), 'exchange new id');
    assert_eq(array('admin', 'admin'), array($n['user']['service_id'], $n['session']['service_id']), 'new id answers new id');
    assert_eq($pre['payload'], $n['payload'], 'same payload by the new id');
    $sn = srt_ok(srt_http($B, 'GET', '/server/api/auth/session.php', null, (string) $n['access_token']), 'session new');
    assert_eq('admin', $sn['session']['service_id'], 'session new id');

    // 섞어 쓰기 (시스템 운용 ①) — 응답은 각각 exchange 에 보낸 값
    $m1 = srt_ok(srt_exchange($B, $codeOldBefore, 'admin'), 'authorize(old, before rename) -> exchange(new)');
    assert_eq('admin', $m1['session']['service_id'], 'echo = value sent to exchange (new)');
    $m2 = srt_ok(srt_exchange($B, $codeOldBefore2, 'mgm'), 'authorize(old, before rename) -> exchange(old)');
    assert_eq('mgm', $m2['session']['service_id'], 'echo = value sent to exchange (old)');
    $m3 = srt_ok(srt_exchange($B, srt_authorize($B, 'admin'), 'mgm'), 'authorize(new) -> exchange(old)');
    assert_eq('mgm', $m3['session']['service_id'], 'echo old');
    $m4 = srt_ok(srt_exchange($B, srt_authorize($B, 'mgm'), 'admin'), 'authorize(old) -> exchange(new)');
    assert_eq('admin', $m4['session']['service_id'], 'echo new');

    // login.php (service_id 직접) · register 는 응답 echo
    srt_x($B, 'DELETE FROM app_ulogin_rate_limits');
    $lg = srt_ok(srt_http($B, 'POST', '/server/api/auth/login.php', array('username' => 'srt_u1', 'password' => SRT_PW, 'service_id' => 'mgm')), 'login old id');
    assert_eq('mgm', $lg['session']['service_id'], 'login echoes old id');
    assert_eq('admin', (string) srt_v($B, 'SELECT service_id FROM app_ulogin_sessions WHERE session_id = ?', array($lg['session']['session_id'])),
        'DB stores the new id');

    // userinfo 대응(/api/me · /api/auth/session 은 service_id 를 읽지 않는다) — 옛 id 를 실어도 200
    srt_ok(srt_http($B, 'GET', '/server/api/me/index.php?service_id=mgm', null, (string) $m3['access_token']), 'me with ?service_id=old');
    srt_ok(srt_http($B, 'GET', '/server/api/auth/session.php?service_id=mgm', null, (string) $m3['access_token']), 'session with ?service_id=old');
    // admin/users.php (AI 관리 운용) — 옛 id 도 받는다, 보낸 값을 되돌린다
    $ul = srt_ok(srt_http($B, 'GET', '/server/api/admin/users.php?service_id=mgm', null, $B['fx']['tok']['sa']), 'admin users ?service_id=old');
    $un = srt_ok(srt_http($B, 'GET', '/server/api/admin/users.php?service_id=admin', null, $B['fx']['tok']['sa']), 'admin users ?service_id=new');
    assert_eq('mgm', $ul['filters_applied']['service_id'] ?? null, 'users.php filters_applied echoes old id');
    assert_eq($un['total'], $ul['total'], 'old and new id filter the same members');
    assert_true((int) $ul['total'] >= 1, 'the filter matched members (not a silent 0)');

    // logout — Demo App A 방식(본문 service_id=옛 id) · Demo App C 방식(Bearer 만)
    $lo1 = srt_ok(srt_http($B, 'POST', '/server/api/auth/logout.php', array('service_id' => 'mgm'), (string) $m2['access_token']), 'logout with old id in body');
    assert_eq(1, (int) $lo1['revoked_count'], 'logout (old id body) revoked the session');
    $lo2 = srt_ok(srt_http($B, 'POST', '/server/api/auth/logout.php', array(), (string) $m3['access_token']), 'logout bearer only');
    assert_eq(1, (int) $lo2['revoked_count'], 'logout (bearer only) revoked the session');
    srt_err(srt_http($B, 'GET', '/server/api/auth/session.php', null, (string) $m2['access_token']), 401, 'AUTH_SESSION_EXPIRED', 'after logout');
}

/** G7 + 시스템 운용 시험 ② — s2s 옛 id · 피드 커서 연속 · 별칭 사용 기록 */
function srt_sc_s2s(array $B): void
{
    srt_x($B, 'DELETE FROM app_ulogin_rate_limits');
    srt_seed_all($B);
    $f0 = srt_feed($B, 'mgm', 0);
    assert_eq(3, count($f0['events']), 'feed before rename has the 3 seeded events');
    $cursor = (int) $f0['next_after'];
    assert_eq('renamed', srt_rename($B, 'mgm', 'admin')['status'] ?? null, 'rename');
    // 개명 뒤 새 사건 1개
    $job = (int) srt_v($B, "SELECT id FROM app_ulogin_link_jobs WHERE service_id = 'admin' LIMIT 1");
    srt_x($B, 'INSERT INTO app_ulogin_link_job_items (job_id, seq, op, service_id, external_id, verdict, status, result_user_id, created_at, done_at)'
        . " VALUES (?, 9, 'unlink', 'admin', 'e9', 'ok', 'done', ?, ?, ?)", array($job, $B['fx']['users']['u1']['id'], srt_now(), srt_now()));
    $newId = (int) srt_v($B, 'SELECT MAX(id) FROM app_ulogin_link_job_items');
    $fn = srt_feed($B, 'admin', $cursor);
    $fo = srt_feed($B, 'mgm', $cursor);
    assert_eq(array($newId), srt_event_ids($fn), 'old cursor + new id: exactly the new event (no gap, no duplicate)');
    assert_eq(array($newId), srt_event_ids($fo), 'old cursor + old id: same');
    $all = srt_feed($B, 'admin', 0);
    assert_eq(array_merge(srt_event_ids($f0), array($newId)), srt_event_ids($all), 'event ids unchanged by the rename');
    // validate 는 사용 기록을 남기지 않는다
    srt_x($B, "UPDATE app_ulogin_services SET former_last_used_at = NULL WHERE service_id = 'admin'");
    srt_ok(srt_http($B, 'GET', '/server/api/sso/validate.php?service_id=mgm&redirect_uri=' . rawurlencode(SRT_CB)), 'validate old');
    assert_eq(null, srt_v($B, "SELECT former_last_used_at FROM app_ulogin_services WHERE service_id = 'admin'"), 'validate (no secret) does not touch last-used');
    // push(옛 id) → 새 id 로 저장 · 사용 기록
    srt_ok(srt_push($B, 'mgm', array(array('external_id' => 'p1', 'status' => 'active', 'username' => 'pp'))), 'push old id');
    assert_eq(1, (int) srt_v($B, "SELECT COUNT(*) FROM app_ulogin_ext_accounts WHERE service_id = 'admin' AND external_id = 'p1'"), 'pushed row stored under the new id');
    $t1 = srt_v($B, "SELECT former_last_used_at FROM app_ulogin_services WHERE service_id = 'admin'");
    assert_true($t1 !== null, 'authenticated alias use is recorded');
    usleep(1300000);
    srt_ok(srt_push($B, 'mgm', array(array('external_id' => 'p2', 'status' => 'active'))), 'push old id again');
    assert_eq($t1, srt_v($B, "SELECT former_last_used_at FROM app_ulogin_services WHERE service_id = 'admin'"), 'second use within 10 min does not rewrite');
    srt_x($B, "UPDATE app_ulogin_services SET former_last_used_at = ? WHERE service_id = 'admin'", array(srt_now(-700)));
    srt_ok(srt_push($B, 'admin', array(array('external_id' => 'p3', 'status' => 'active'))), 'push new id');
    assert_eq(srt_now(-700), srt_v($B, "SELECT former_last_used_at FROM app_ulogin_services WHERE service_id = 'admin'"), 'new-id use is not alias use');
    srt_ok(srt_push($B, 'mgm', array(array('external_id' => 'p4', 'status' => 'active'))), 'push old id after 10 min');
    assert_true(strcmp((string) srt_v($B, "SELECT former_last_used_at FROM app_ulogin_services WHERE service_id = 'admin'"), srt_now(-60)) > 0, 'updated after the window');
}

/** G8 — 재사용 금지 · 관리자 API 옛 id 400 · 별칭 종료 */
function srt_sc_reuse(array $B): void
{
    srt_x($B, 'DELETE FROM app_ulogin_rate_limits');
    $sa = $B['fx']['tok']['sa'];
    // 개명 전: 새 id 등록은 CLI 가 거절(개명 먼저)
    $c0 = srt_cli($B, '--only=admin --dry-run');
    assert_eq(1, $c0['exit'], 'register --only=admin while mgm exists is refused (' . substr($c0['out'], -300) . ')');
    assert_contains('--rename=mgm:admin', $c0['out'], 'CLI points to --rename');
    assert_eq('renamed', srt_rename($B, 'mgm', 'admin')['status'] ?? null, 'rename');
    // 등록 경로(번들 코드 꺼내기 + sb_build_plan 의 service_id_taken)가 새 코드와 함께 끝까지 돈다
    $c1 = srt_cli($B, '--only=task --dry-run');
    assert_eq(0, $c1['exit'], 'register --only=task --dry-run plans a create (' . substr($c1['out'], -300) . ')');
    $c2 = srt_cli($B, '--only=admin --dry-run');
    assert_eq(5, $c2['exit'], 'register --only=admin after rename = already registered (exit 5)');
    $svc = array('service_id' => 'mgm', 'service_name' => 'x', 'is_confidential' => false, 'redirect_whitelist' => array(SRT_CB));
    $r = srt_http($B, 'POST', '/server/api/admin/services.php', $svc, $sa);
    srt_err($r, 409, 'DUPLICATE_SERVICE_ID', 'admin create with old id');
    $bundle = array('ulogin_settings_bundle' => '1.0', 'on_conflict' => 'merge', 'services' => array(
        array('service_id' => 'mgm', 'service_name' => 'x', 'is_confidential' => false, 'redirect_whitelist' => array(SRT_CB),
            'status' => 'active', 'secrets' => array())));
    $rb = srt_http($B, 'POST', '/server/api/admin/settings_bundle.php?action=dry-run', array('bundle' => $bundle), $sa);
    srt_err($rb, 409, 'CONFLICT', 'settings bundle creating the old id');
    assert_contains('개명 전', $rb['body'], 'bundle conflict says why');
    // 관리자 API 옛 id → 400
    $rs = srt_http($B, 'GET', '/server/api/admin/secrets.php?service_id=mgm', null, $sa);
    srt_err($rs, 400, 'VALIDATION_FAILED', 'admin secrets with old id');
    assert_eq('service_id', $rs['json']['error']['field'] ?? null, 'field');
    srt_err(srt_http($B, 'GET', '/server/api/admin/logs.php?type=login&service_id=mgm', null, $sa), 400, 'VALIDATION_FAILED', 'admin logs with old id');
    $list = srt_ok(srt_http($B, 'GET', '/server/api/admin/services.php?service_id=admin', null, $sa), 'admin services list');
    $it = $list['items'][0] ?? array();
    foreach (array('former_service_id', 'former_until', 'former_last_used_at') as $k) {
        assert_true(array_key_exists($k, $it), 'admin services item has key ' . $k);
    }
    assert_eq(array('mgm', null), array($it['former_service_id'], $it['former_until']), 'admin list shows the alias values');
    // 별칭 종료
    $e = srt_cli($B, '--end-alias=admin');
    assert_eq(0, $e['exit'], 'end-alias (' . substr($e['out'], -300) . ')');
    srt_err(srt_http($B, 'GET', '/server/api/sso/validate.php?service_id=mgm&redirect_uri=' . rawurlencode(SRT_CB)), 400, 'SSO_INVALID_SERVICE', 'validate old after end');
    $code = srt_authorize($B, 'admin');
    srt_err(srt_exchange($B, $code, 'mgm'), 400, 'SSO_INVALID_SERVICE', 'exchange old after end');
    srt_err(srt_push($B, 'mgm', array()), 401, 'SSO_CLIENT_AUTH_FAILED', 'push old after end');
    srt_ok(srt_exchange($B, srt_authorize($B, 'admin'), 'admin'), 'new id still works after end');
    srt_err(srt_http($B, 'POST', '/server/api/admin/services.php', $svc, $sa), 409, 'DUPLICATE_SERVICE_ID', 'old id still taken after end');
}

/** G10 — 비밀: handoff 에 secret 키 없음 · 출력·감사에 해시 없음 · ACL 상속 없음 · 사본 */
function srt_sc_handoff(array $B): void
{
    $hash = (string) srt_v($B, "SELECT service_secret_hash FROM app_ulogin_services WHERE service_id = 'mgm'");
    $r = srt_cli($B, '--rename=mgm:admin --with-handoff');
    assert_eq(0, $r['exit'], 'CLI rename (' . substr($r['out'], -500) . ')');
    $f = $B['handoff'] . '/admin.json';
    assert_true(is_file($f), 'rename handoff written');
    $doc = json_decode((string) file_get_contents($f), true);
    assert_eq('ulogin-link/1 rename', $doc['format'] ?? null, 'format');
    assert_eq(array('admin', 'mgm', true), array($doc['service_id'] ?? null, $doc['former_service_id'] ?? null, $doc['secret_unchanged'] ?? null), 'ids');
    $raw = (string) file_get_contents($f);
    assert_true(!array_key_exists('service_secret', $doc), 'no service_secret key at all');
    srt_absent($hash, $raw, 'no secret hash in the handoff');
    srt_absent($B['fx']['secret']['mgm'], $raw . $r['out'], 'no secret in handoff or stdout');
    srt_absent($hash, $r['out'], 'no hash in stdout');
    $log = (string) srt_v($B, "SELECT after_json FROM app_ulogin_admin_logs WHERE action = 'service.rename'");
    srt_absent($hash, $log, 'no hash in the audit row');
    $bak = null;
    foreach (scandir($B['base'] . '/data') as $e) {
        if (strpos($e, 'ulogin_pre_rename_') === 0) {
            $bak = $B['base'] . '/data/' . $e;
        }
    }
    assert_true($bak !== null && filesize($bak) > 0, 'SQLite backup copy next to the DB');
    $bp = new PDO('sqlite:' . $bak);
    assert_eq('mgm', (string) $bp->query("SELECT service_id FROM app_ulogin_services WHERE service_name = 'Demo App A'")->fetchColumn(), 'backup is the pre-rename state');
    $bp = null;
    if (DIRECTORY_SEPARATOR === '\\') {
        foreach (array($f, $bak) as $p) {
            $o = h_run('icacls ' . h_q(str_replace('/', '\\', $p)), $code, 30.0);
            assert_eq(0, $code, 'icacls readable');
            assert_true(strpos($o, '(I)') === false, 'no inherited ACE on ' . basename($p) . ': ' . $o);
        }
    }
    $again = srt_cli($B, '--rename=mgm:admin --with-handoff');
    assert_eq(0, $again['exit'], 're-run exit 0 (already renamed, nothing to do)');
    assert_contains('이미 개명됨', $again['out'], 're-run says already renamed');
}

/** G11 — 0004 모양 SQLite 에 0005 → 0006 연속 apply · verify · 재적용 무변경 */
function srt_sc_migrate(array $B): void
{
    $schema = (string) file_get_contents($B['root'] . '/server/schema.sql');
    $old = preg_replace('~^CREATE TABLE IF NOT EXISTS `app_ulogin_(?:ext_accounts|link_jobs|link_job_items)` \(.*?\n\) ENGINE[^;]*;~ms', '', $schema, -1, $nt);
    $old = preg_replace('~^\s*`purpose`[^\n]*\n~m', '', (string) $old, -1, $nc);
    $old = preg_replace('~^\s*`(?:former_service_id|former_until|former_last_used_at|client_service_id)`[^\n]*\n~m', '', (string) $old, -1, $n6);
    $old = preg_replace('~,\n\s*UNIQUE KEY `uq_services_former` \(`former_service_id`\)~', '', (string) $old, -1, $nu);
    // $nu 는 0 도 받는다 — 「schema.sql 에서 그 색인이 빠진」 변이 사본에서도 시나리오가 끝까지 가서 verify 가 잡아야 한다
    if ($nt !== 3 || $nc !== 1 || $n6 !== 4 || $nu > 1) {
        fail('HARNESS-FAIL could not derive the 0004-state schema (tables ' . $nt . ', purpose ' . $nc . ', 0006 cols ' . $n6 . ', uq ' . $nu . ')');
    }
    $oldFile = $B['base'] . '/schema_0004.sql';
    file_put_contents($oldFile, $old);
    $db = $B['base'] . '/data/ulogin_test_sr_mig.sqlite';
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
    $pdo->exec("INSERT INTO app_ulogin_services (service_id, service_name, redirect_whitelist, created_at, updated_at) VALUES ('mgm', 'x', '[]', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
    $pdo = null;
    $cfgFile = $B['root'] . '/server/config.php';
    file_put_contents($cfgFile, srt_set_define((string) file_get_contents($cfgFile), 'DB_SQLITE_PATH', var_export($db, true)));
    $run = function ($cmd) use ($B) {
        $code = 0;
        $out = h_run(h_q(h_php_bin()) . ' -c ' . h_q(srt_ini()) . ' ' . h_q($B['root'] . '/server/migrate.php') . ' ' . $cmd, $code);
        return array($code, $out);
    };
    list($c1, $o1) = $run('apply');
    assert_eq(0, $c1, 'migrate apply 0004 -> 0005 -> 0006 (' . substr($o1, -600) . ')');
    assert_contains('0005', $o1, 'apply ran 0005');
    assert_contains('0006', $o1, 'apply ran 0006');
    list($c2, $o2) = $run('verify');
    assert_eq(0, $c2, 'verify after upgrade equals schema.sql (' . substr($o2, -600) . ')');
    list($c3, $o3) = $run('apply');
    assert_eq(0, $c3, 'second apply is a no-op (' . substr($o3, -300) . ')');
    list($c4, $o4) = $run('status');
    assert_eq(0, $c4, 'status clean (' . substr($o4, -300) . ')');
    $pdo = new PDO('sqlite:' . $db, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    // v1.9.0 — 0007 도 같은 apply 에서 이어 돈다(0004 모양 → 0005 → 0006 → 0007). verify 가 그 최종 형태를 schema.sql 과 대조한다.
    assert_eq(10, (int) $pdo->query('SELECT COUNT(*) FROM app_ulogin_schema_migrations')->fetchColumn(), '10 migration rows (0001~0004 baseline + 0005~0010 applied)');
    $pdo->exec("UPDATE app_ulogin_services SET former_service_id = 'old1' WHERE service_id = 'mgm'");
    $pdo->exec("INSERT INTO app_ulogin_services (service_id, service_name, redirect_whitelist, created_at, updated_at) VALUES ('b', 'x', '[]', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
    $pdo->exec("INSERT INTO app_ulogin_services (service_id, service_name, redirect_whitelist, created_at, updated_at) VALUES ('c', 'x', '[]', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
    $dup = false;
    try {
        $pdo->exec("UPDATE app_ulogin_services SET former_service_id = 'old1' WHERE service_id = 'b'");
    } catch (PDOException $e) {
        $dup = stripos($e->getMessage(), 'UNIQUE') !== false;
    }
    $pdo = null;
    assert_true($dup, 'uq_services_former rejects a duplicate old id; NULLs coexist');
}

/** G12 — 개명 중 SSO 교환 N=8(자식 프로세스). 5xx 0 · 전부 200 또는 일관된 400 · 서비스 섞임 0 */
function srt_sc_race(array $B): void
{
    srt_x($B, 'DELETE FROM app_ulogin_rate_limits');
    $u1 = $B['fx']['users']['u1']['id'];
    $n = 8;
    $codes = array();
    for ($i = 0; $i < $n; $i++) {
        $c = bin2hex(random_bytes(32));
        srt_x($B, 'INSERT INTO app_ulogin_auth_codes (code_hash, user_id, service_id, redirect_uri, state, expires_at, created_ip, created_at)'
            . " VALUES (?, ?, 'mgm', ?, 'st', ?, '127.0.0.1', ?)", array(hash('sha256', $c), $u1, SRT_CB, srt_now(60), srt_now()));
        $codes[] = $c;
    }
    $dir = $B['base'] . '/race_' . bin2hex(random_bytes(3));
    @mkdir($dir, 0777, true);
    $start = microtime(true) + 1.5;
    $procs = array();
    for ($i = 0; $i <= $n; $i++) {
        $isRename = $i === $n;
        $w = $dir . '/w' . $i . '.php';
        $out = $dir . '/o' . $i . '.json';
        $sid = ($i % 2 === 0) ? 'mgm' : 'admin';
        $src = "<?php\n\$out = " . var_export($out, true) . ";\n"
            . "\$_SERVER['REQUEST_METHOD'] = 'POST'; \$_SERVER['REMOTE_ADDR'] = '10.85.0." . ($i + 1) . "'; \$_SERVER['HTTP_HOST'] = '127.0.0.1';\n"
            . "\$_SERVER['REQUEST_URI'] = '/server/api/auth/exchange.php'; \$_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';\n"
            . "\$_POST = " . var_export(array('code' => $codes[$isRename ? 0 : $i], 'service_id' => $sid, 'redirect_uri' => SRT_CB,
                'service_secret' => $B['fx']['secret']['mgm']), true) . ";\n"
            . "register_shutdown_function(function () use (\$out) { \$b = ''; while (ob_get_level() > 0) { \$b = (string) ob_get_clean() . \$b; }"
            . " \$st = http_response_code(); file_put_contents(\$out, json_encode(['status' => \$st === false ? 0 : (int) \$st, 'body' => \$b, 'fatal' => error_get_last()])); });\n"
            . "ob_start();\nrequire " . var_export($B['root'] . '/server/core/bootstrap.php', true) . ";\ndb();\n"
            . "while (microtime(true) < " . var_export($start, true) . ") {}\n"
            . ($isRename
                ? "require_once " . var_export($B['root'] . '/server/core/servicerename.php', true) . ";\n"
                    . "try { \$r = service_rename('mgm', 'admin', " . (int) $B['fx']['users']['sa']['id'] . "); http_response_code(200); echo json_encode(\$r); } catch (Throwable \$e) { http_response_code(599); echo \$e->getMessage(); }\nexit;\n"
                : "chdir(" . var_export($B['root'] . '/server/api/auth', true) . ");\nrequire " . var_export($B['root'] . '/server/api/auth/exchange.php', true) . ";\n");
        file_put_contents($w, $src);
        $cmd = h_q(h_php_bin()) . ' -c ' . h_q(srt_ini()) . ' ' . h_q($w);
        $pp = array();
        $p = @proc_open($cmd, array(0 => array('pipe', 'r'), 1 => array('file', $dir . '/so' . $i, 'w'), 2 => array('file', $dir . '/se' . $i, 'w')), $pp, $dir);
        if (!is_resource($p)) {
            fail('HARNESS-FAIL proc_open failed');
        }
        fclose($pp[0]);
        $procs[$i] = $p;
    }
    $deadline = proc_deadline();
    $hung = array();
    foreach ($procs as $i => $p) {
        $wt = proc_wait_or_kill($p, $deadline);
        if ($wt['timed_out']) {
            $hung[] = $i;
        }
    }
    if ($hung !== array()) {
        fail('HARNESS-TIMEOUT race workers killed: ' . implode(',', $hung));
    }
    $ok = 0;
    $consistent = 0;
    $bad = array();
    for ($i = 0; $i < $n; $i++) {
        $j = json_decode((string) @file_get_contents($dir . '/o' . $i . '.json'), true);
        $st = is_array($j) ? (int) $j['status'] : 0;
        $body = is_array($j) ? json_decode((string) $j['body'], true) : null;
        $sid = ($i % 2 === 0) ? 'mgm' : 'admin';
        if ($st === 200 && is_array($body) && ($body['data']['session']['service_id'] ?? null) === $sid) {
            $ok++;
        } elseif ($st === 400 && is_array($body) && in_array($body['error']['code'] ?? '', array('SSO_CODE_INVALID', 'SSO_INVALID_SERVICE'), true)) {
            $consistent++;
        } else {
            $bad[] = $i . ':' . $st . ' ' . substr(is_array($j) ? (string) $j['body'] : '', 0, 160);
        }
    }
    $rj = json_decode((string) @file_get_contents($dir . '/o' . $n . '.json'), true);
    note('G12 rename under ' . $n . ' concurrent exchanges: ok ' . $ok . ' · consistent 400 ' . $consistent . ' · bad ' . count($bad)
        . ' · rename ' . (is_array($rj) ? $rj['status'] : '?'));
    assert_eq(array(), $bad, 'no 5xx / wrong-service response during the rename');
    assert_eq(200, is_array($rj) ? (int) $rj['status'] : 0, 'rename itself committed (' . (is_array($rj) ? substr((string) $rj['body'], 0, 200) : '') . ')');
    assert_eq(0, (int) srt_v($B, "SELECT COUNT(*) FROM app_ulogin_auth_codes WHERE service_id = 'mgm'"), 'no code left on the old id');
    assert_eq('ok', (string) srt_v($B, 'PRAGMA integrity_check'), 'integrity_check');
}

/** 주석만 걷는다(문자열 리터럴은 남긴다 — 'service_id' 를 봐야 한다). */
function srt_strip_comments(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tk) {
        if (is_array($tk) && ($tk[0] === T_COMMENT || $tk[0] === T_DOC_COMMENT)) {
            $out .= ' ';
            continue;
        }
        $out .= is_array($tk) ? $tk[1] : $tk;
    }
    return $out;
}

/**
 * D-346 (보안 High H186-1) — 서비스 SSO 로 받은 토큰은 ulogin 관리자 API 에 통하지 않는다.
 * superadmin 이 시스템(mgm)에 실제로 로그인해 받은 토큰으로 관리자 API 를 Bearer·Cookie 양쪽으로 부른다.
 */
function srt_sc_admin_token(array $B): void
{
    srt_x($B, 'DELETE FROM app_ulogin_rate_limits');
    $code = srt_authorize($B, 'mgm', 'sa');
    $x = srt_ok(srt_exchange($B, $code, 'mgm'), 'superadmin SSO into mgm');
    $svcTok = (string) $x['access_token'];
    $own = $B['fx']['tok']['sa'];
    $gets = array('/server/api/admin/users.php', '/server/api/admin/services.php',
        '/server/api/admin/account_links.php?view=systems', '/server/api/admin/stats.php', '/server/api/admin/logs.php?type=admin');
    foreach ($gets as $p) {
        srt_ok(srt_http($B, 'GET', $p, null, $own), 'own ulogin session (no service_id) still reaches ' . $p);
        srt_err(srt_http($B, 'GET', $p, null, $svcTok), 403, 'FORBIDDEN', 'service token (Bearer) on ' . $p);
        srt_err(srt_http($B, 'GET', $p, null, null, $svcTok), 403, 'FORBIDDEN', 'service token (Cookie) on ' . $p);
    }
    srt_err(srt_http($B, 'POST', '/server/api/admin/account_link_ops.php', array('action' => 'plan', 'items' => array()), $svcTok),
        403, 'FORBIDDEN', 'service token on account_link_ops plan');
    srt_err(srt_http($B, 'POST', '/server/api/admin/settings_bundle.php?action=dry-run', array('bundle' => array('ulogin_settings_bundle' => '1.0')), $svcTok),
        403, 'FORBIDDEN', 'service token on settings_bundle');
    srt_err(srt_http($B, 'POST', '/server/api/admin/services.php', array('service_id' => 'evil', 'service_name' => 'x', 'redirect_whitelist' => array(SRT_CB)), $svcTok),
        403, 'FORBIDDEN', 'service token cannot create a service');
    assert_eq(0, (int) srt_v($B, "SELECT COUNT(*) FROM app_ulogin_services WHERE service_id = 'evil'"), 'nothing was created');
    // 서비스 토큰 자체는 정상 — 자기 서비스 경로는 그대로
    $s = srt_ok(srt_http($B, 'GET', '/server/api/auth/session.php', null, $svcTok), 'service token on session.php');
    assert_eq('mgm', $s['session']['service_id'], 'session.php still answers for the service');
}

/** G2 — 정적: 진입점이 v_service_id 를 쓴다. 대상 0개면 HARNESS-FAIL */
function srt_entry_counts(string $root): array
{
    $files = array();
    foreach (array('server/api/sso', 'server/api/auth', 'server/api/points', 'server/api/me') as $d) {
        $abs = $root . '/' . $d;
        foreach (is_dir($abs) ? scandir($abs) : array() as $e) {
            if (substr($e, -4) === '.php') {
                $files[] = $abs . '/' . $e;
            }
        }
    }
    $files[] = $root . '/server/core/accountlink.php';
    if (count($files) < 20) {
        fail('HARNESS-FAIL entry-point scan saw only ' . count($files) . ' files (scandir blind?)');
    }
    $old = 0;
    $new = 0;
    foreach ($files as $f) {
        $src = srt_strip_comments((string) file_get_contents($f));
        $old += preg_match_all("/v_string\\([^)]*'service_id'/", $src);
        $new += preg_match_all("/v_service_id\\(\\\$[A-Za-z_]+, 'service_id'/", $src);
    }
    return array($old, $new);
}

// =====================================================================
// 케이스
// =====================================================================

t('G2 static: system entry points read service_id through v_service_id (0 raw v_string, 9 v_service_id) + admin users.php', function () {
    list($old, $new) = srt_entry_counts(h_root());
    assert_eq(0, $old, 'raw v_string(... service_id) in system entry points');
    assert_eq(9, $new, 'v_service_id call sites in system entry points');
    $u = srt_strip_comments(h_read(h_root() . '/server/api/admin/users.php'));
    assert_true(strpos($u, "v_service_id(\$in, 'service_id')") !== false, 'admin/users.php (AI 관리 운용) resolves aliases');
    // 검사기 변이 — exchange 한 곳을 v_string 으로 되돌린 사본
    $tmp = h_norm(sys_get_temp_dir()) . '/ulogin_test_sr_g2_' . getmypid();
    srt_copy(h_root() . '/server', $tmp . '/server');
    $f = $tmp . '/server/api/auth/exchange.php';
    $s = (string) file_get_contents($f);
    $c = substr_count($s, "v_service_id(\$in, 'service_id', true)");
    file_put_contents($f, str_replace("v_service_id(\$in, 'service_id', true)", "v_string(\$in, 'service_id', 1, 64, true)", $s));
    list($o2, $n2) = srt_entry_counts($tmp);
    srt_rm($tmp);
    assert_eq(1, $c, 'mutation planted once');
    assert_eq(array(1, 8), array($o2, $n2), 'checker sees the reverted entry point');
});

$SRT_CASES = array(
    'G1 resolve: canonical / alias / ended alias / unknown; ended alias stays taken' => array('srt_sc_resolve', false),
    'G3 rename moves all 11 tables + users.meta_json, admin_logs immutable + 1 audit row, live sessions keep echo memory' => array('srt_sc_rename_all', false),
    'G4 atomic: failure injected after table 5 -> every table unchanged' => array('srt_sc_atomic', false),
    'G5 re-run is a no-op, revert restores the pre-rename DB, an orphan row refuses' => array('srt_sc_rerun_revert', false),
    'G6 SSO walk by old id after rename echoes the old id; new id answers new; mixed authorize/exchange; logout both ways; me; admin users.php' => array('srt_sc_sso', true),
    'G7 s2s by old id: rows stored under new id, feed cursor continuous across the rename, last-used only for authenticated use, 10-min cap' => array('srt_sc_s2s', true),
    'G8 old id cannot be reused (services, bundle, CLI), admin API refuses old id, end-alias stops resolution' => array('srt_sc_reuse', true),
    'G10 CLI rename handoff has no secret key, no hash anywhere, backup copy, no inherited ACE' => array('srt_sc_handoff', false),
    'G11 migration: 0004-shaped SQLite -> 0005 -> 0006 -> verify clean, re-apply no-op, uq_services_former' => array('srt_sc_migrate', false),
    'G12 rename while 8 exchanges run concurrently: no 5xx, no wrong-service answer' => array('srt_sc_race', false),
    'D-346 a service SSO token (session has service_id) gets 403 on every admin API, Bearer and Cookie; own session 200' => array('srt_sc_admin_token', true),
);
foreach ($SRT_CASES as $name => $spec) {
    t($name, function () use ($spec) {
        $fn = $spec[0];
        $fn(srt_box(substr(md5($fn), 0, 6), array(), $spec[1]));
    });
}

// ---- 변이 (D-186) — 변이 사본에서 같은 시나리오가 실패해야 한다 ----
$AU = 'server/core/auth.php';
$SR = 'server/core/servicerename.php';
$SRT_MUTANTS = array(
    'G1 former_until condition removed' => array(array(array($AU, " WHERE former_service_id = ? AND (former_until IS NULL OR former_until > ?)', [\$p, now_utc()]);",
        " WHERE former_service_id = ? AND ? IS NOT NULL', [\$p, now_utc()]);")), 'srt_sc_resolve', false),
    'G3 users.meta_json rewrite removed' => array(array(array($SR, "        \$j['imported_from']['service_id'] = \$to;", "        \$j['imported_from']['service_id'] = \$from;")), 'srt_sc_rename_all', false),
    'G3 admin_logs rewritten too' => array(array(array($SR, "    \$meta = 0;", "    db_exec('UPDATE ' . T_ADMIN_LOGS . ' SET target_id = ? WHERE target_id = ?', [\$to, \$from]);\n    \$meta = 0;")), 'srt_sc_rename_all', false),
    'G4 no transaction around the rename' => array(array(array($SR, "    return db_tx(function () use (\$from, \$to, \$adminId) {", "    return call_user_func(function () use (\$from, \$to, \$adminId) {")), 'srt_sc_atomic', false),
    'G5 orphan check removed' => array(array(array($SR, "    if (\$orphans !== []) {", "    if (false && \$orphans !== []) {")), 'srt_sc_rerun_revert', false),
    'G6 exchange echo removed' => array(array(array('server/api/auth/exchange.php', "service_id_echo(\$sessOut, \$issued['row']);", "// echo removed")), 'srt_sc_sso', true),
    'G6 exchange input not normalised' => array(array(array('server/api/auth/exchange.php', "\$serviceId   = (string) v_service_id(\$in, 'service_id', true);", "\$serviceId   = (string) v_string(\$in, 'service_id', 1, 64, true);")), 'srt_sc_sso', true),
    'G7 validate records alias use' => array(array(array('server/api/sso/validate.php', "\$allowRegister = registration_open(\$service);", "service_alias_touch((string) \$service['service_id']);\n\$allowRegister = registration_open(\$service);")), 'srt_sc_s2s', true),
    'G7 10-minute cap removed' => array(array(array($AU, "            . ' AND (former_last_used_at IS NULL OR former_last_used_at < ?)',", "            . ' AND (former_last_used_at IS NULL OR former_last_used_at < ? OR 1 = 1)',")), 'srt_sc_s2s', true),
    'G8 service_id_taken dropped from admin create' => array(array(array('server/api/admin/services.php', "    if (service_id_taken(\$serviceId) !== null) {", "    if (db_val('SELECT id FROM ' . T_SERVICES . ' WHERE service_id = ?', [\$serviceId]) !== null) {")), 'srt_sc_reuse', true),
    'G10 handoff carries the secret hash' => array(array(array('tools/register_services.php', "        'secret_unchanged'   => true,", "        'secret_unchanged'   => true, 'service_secret_hash' => (string) \$row['service_secret_hash'],")), 'srt_sc_handoff', false),
    'D-346 service-session guard removed from ctx_global_role' => array(array(array('server/core/perm.php',
        "    if (!empty(\$ctx['session']['service_id'])) {\n        return 'user';\n    }\n", '')), 'srt_sc_admin_token', true),
    'G11 uq_services_former missing from schema.sql' => array(array(array('server/schema.sql', ",\n  UNIQUE KEY `uq_services_former` (`former_service_id`)", '')), 'srt_sc_migrate', false),
);
foreach ($SRT_MUTANTS as $label => $spec) {
    t('mutant: ' . $label, function () use ($label, $spec) {
        srt_mutant(substr(md5($label), 0, 6), $spec[0], function ($B) use ($spec) {
            $fn = $spec[1];
            $fn($B);
        }, $spec[2]);
    });
}

srt_teardown();
