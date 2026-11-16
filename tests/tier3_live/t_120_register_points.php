<?php
declare(strict_types=1);

/**
 * 티어 3 - v1.10.1 가입 방식 3택 · 가입 신청 승인 · 공동 포인트 s2s · 관리자 비밀번호 10자(설계 130 §5 · D-371 · D-373)
 *
 * 사본 서버(php -S, 빈 포트) + 임시 SQLite. 운영 DB·8080·3306 무접촉(접두 가드 ulogin_test_rp_), 서버는 자기 PID 만 taskkill /T.
 * 하네스는 t_110_access_levels.php 의 박스와 같은 모양이다(접두만 다르다).
 * php -S 는 Windows 에서 단일 작업자라 진짜 동시 실행은 재현되지 않는다 — 「잠금 안의 한도 합산」(변이 ⑦)은
 * tier1 t_80 의 정적 검사가 지키고, 여기서는 같은 키 2연속 요청의 원장 1행만 본다.
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

const RP_PW = 'Rp-Test-pass-5521';
$GLOBALS['RP'] = array('boxes' => array(), 'registered' => false);

function rp_rm(string $p): void
{
    if (strpos(h_norm($p), '/ulogin_test_rp_') === false) {
        return;
    }
    rp_rm_inner($p);
}

function rp_rm_inner(string $p): void
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
            rp_rm_inner($p . '/' . $n);
        }
    }
    @rmdir($p);
}

function rp_copy(string $src, string $dst): int
{
    @mkdir($dst, 0777, true);
    $n = 0;
    foreach (scandir($src) as $e) {
        if ($e === '.' || $e === '..' || $e === 'config.php' || $e === '.install.lock' || $e === '.installed') {
            continue;
        }
        if (is_dir($src . '/' . $e)) {
            $n += rp_copy($src . '/' . $e, $dst . '/' . $e);
        } else {
            copy($src . '/' . $e, $dst . '/' . $e);
            $n++;
        }
    }
    return $n;
}

function rp_teardown(): void
{
    foreach ($GLOBALS['RP']['boxes'] as $b) {
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
    foreach ($GLOBALS['RP']['boxes'] as $b) {
        rp_rm($b['base']);
    }
    $GLOBALS['RP']['boxes'] = array();
}

function rp_ini(): string
{
    $ini = php_ini_loaded_file();
    if (!is_string($ini) || $ini === '' || !is_file($ini)) {
        skip('runner has no php.ini (-n?) - children would lack pdo_sqlite; run with -c <ini>');
    }
    return $ini;
}

function rp_free_port(): int
{
    for ($i = 0; $i < 10; $i++) {
        $srv = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($srv === false) {
            continue;
        }
        $name = (string) stream_socket_get_name($srv, false);
        fclose($srv);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        if ($port <= 1024 || $port === 8080 || $port === 3306) {
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

function rp_set_define(string $src, string $name, string $phpValue): string
{
    $re = '/^define\(\'' . preg_quote($name, '/') . '\',\s*[^\n]*?\);/m';
    $n = 0;
    $out = preg_replace($re, 'define(\'' . $name . '\', ' . $phpValue . ');', $src, 1, $n);
    if ($n !== 1 || !is_string($out)) {
        fail('HARNESS-FAIL config.sample.php: define(' . $name . ') not found exactly once');
    }
    return $out;
}

function rp_box(string $key, array $mut = array()): array
{
    require_ext('pdo_sqlite');
    $ini = rp_ini();
    if (!$GLOBALS['RP']['registered']) {
        $GLOBALS['RP']['registered'] = true;
        register_shutdown_function('rp_teardown');
    }
    $base = h_norm(sys_get_temp_dir()) . '/ulogin_test_rp_' . getmypid() . '_' . bin2hex(random_bytes(3)) . '_' . $key;
    $root = $base . '/root';
    $n  = rp_copy(h_root() . '/server', $root . '/server');
    $n += rp_copy(h_root() . '/public', $root . '/public');
    @mkdir($root . '/tools', 0777, true);
    copy(h_root() . '/tools/router-ulogin.php', $root . '/tools/router-ulogin.php');
    $B = array('base' => $base, 'root' => $root, 'key' => $key, 'pid' => 0, 'proc' => null, 'port' => 0);
    $GLOBALS['RP']['boxes'][] = &$B;
    if ($n < 50 || !is_file($root . '/server/api/points/s2s_earn.php') || !is_file($root . '/server/api/admin/register_requests.php')) {
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
    $db = $base . '/data/ulogin_test_rp.sqlite';
    $B['db'] = $db;
    $pdo = new PDO('sqlite:' . $db, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    mig_sqlite_create_schema($pdo, $root . '/server/schema.sql', 'app_ulogin_', true);
    $pdo = null;
    $port = rp_free_port();
    $B['port'] = $port;
    $cfg = (string) file_get_contents(h_root() . '/server/config.sample.php');
    $cfg = rp_set_define($cfg, 'DB_DRIVER', "'sqlite'");
    $cfg = rp_set_define($cfg, 'DB_SQLITE_PATH', var_export($db, true));
    $B['enc'] = bin2hex(random_bytes(32));
    $cfg = rp_set_define($cfg, 'SECRET_ENC_KEY', "'" . $B['enc'] . "'");
    $cfg = rp_set_define($cfg, 'APP_KEY', "'" . bin2hex(random_bytes(32)) . "'");
    $cfg = rp_set_define($cfg, 'FORCE_HTTPS', 'false');
    $cfg = rp_set_define($cfg, 'COOKIE_SECURE', 'false');
    $cfg = rp_set_define($cfg, 'BASE_URL', "'http://127.0.0.1:" . $port . "'");
    file_put_contents($root . '/server/config.php', $cfg);
    $B['fx'] = rp_seed($B);

    $log = $base . '/server.log';
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
        $r = rp_http($B, 'GET', '/version.txt');
        if ($r['status'] === 200 && trim($r['body']) === $token) {
            $ok = true;
            break;
        }
        usleep(100000);
    }
    if (!$ok) {
        fail('HARNESS-FAIL php -S on port ' . $port . ' did not answer with this run\'s sentinel');
    }
    $copy = $B;
    unset($B);
    return $copy;
}

function rp_pdo(array $B): PDO
{
    $pdo = new PDO('sqlite:' . $B['db'], null, null, array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
    $pdo->exec('PRAGMA busy_timeout = 8000');
    return $pdo;
}

function rp_q(array $B, string $sql, array $p = array()): array
{
    $pdo = rp_pdo($B);
    $st = $pdo->prepare($sql);
    $st->execute($p);
    $rows = $st->fetchAll();
    $pdo = null;
    return $rows;
}

function rp_v(array $B, string $sql, array $p = array())
{
    $r = rp_q($B, $sql, $p);
    return $r === array() ? null : reset($r[0]);
}

function rp_x(array $B, string $sql, array $p = array()): int
{
    $pdo = rp_pdo($B);
    $st = $pdo->prepare($sql);
    $st->execute($p);
    $n = $st->rowCount();
    $pdo = null;
    return $n;
}

function rp_now(int $off = 0): string
{
    return gmdate('Y-m-d H:i:s', time() + $off);
}

function rp_http(array $B, string $method, string $path, $body = null, ?string $bearer = null, array $hdr = array()): array
{
    $s = @fsockopen('127.0.0.1', (int) $B['port'], $errno, $errstr, 5.0);
    if ($s === false) {
        return array('status' => 0, 'body' => '', 'json' => null, 'head' => '');
    }
    stream_set_timeout($s, 30);
    fwrite($s, rp_req_text($B, $method, $path, $body, $bearer, $hdr));
    return rp_read($s);
}

function rp_req_text(array $B, string $method, string $path, $body, ?string $bearer, array $hdr): string
{
    $json = $body === null ? null : (is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $req = $method . ' ' . $path . " HTTP/1.0\r\nHost: 127.0.0.1:" . $B['port'] . "\r\nConnection: close\r\nUser-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36\r\n";
    if ($bearer !== null) {
        $req .= 'Authorization: Bearer ' . $bearer . "\r\n";
    }
    foreach ($hdr as $h) {
        $req .= $h . "\r\n";
    }
    if ($json !== null) {
        $req .= "Content-Type: application/json\r\nContent-Length: " . strlen($json) . "\r\n";
    }
    return $req . "\r\n" . ($json === null ? '' : $json);
}

/** @param resource $s */
function rp_read($s): array
{
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
    return array('status' => $status, 'body' => $bodyS, 'json' => is_array($j) ? $j : null, 'head' => $head);
}

function rp_code(array $r): string
{
    return is_array($r['json']) && isset($r['json']['error']['code']) ? (string) $r['json']['error']['code'] : '';
}

function rp_ok(array $r, string $what, int $status = 200): array
{
    if ($r['status'] !== $status || !is_array($r['json']) || $r['json']['ok'] !== true) {
        fail($what . ': expected ' . $status . ' ok, got ' . $r['status'] . ' ' . substr($r['body'], 0, 400));
    }
    return is_array($r['json']['data']) ? $r['json']['data'] : array();
}

function rp_err(array $r, int $status, string $code, string $what, ?string $reason = null): void
{
    if ($r['status'] !== $status || rp_code($r) !== $code) {
        fail($what . ': expected ' . $status . ' ' . $code . ', got ' . $r['status'] . ' ' . substr($r['body'], 0, 400));
    }
    if ($reason !== null) {
        $got = is_array($r['json']['data'] ?? null) ? ($r['json']['data']['reason'] ?? null) : null;
        if ($got !== $reason) {
            fail($what . ': expected data.reason ' . $reason . ', got ' . var_export($got, true));
        }
    }
}

function rp_token(PDO $pdo, int $uid, ?string $csrf = null): string
{
    $tok = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO app_ulogin_sessions (session_id, user_id, service_id, auth_type, access_token_hash, csrf_token_hash,'
        . ' issued_at, expires_at, last_seen_at, ip, user_agent) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute(array(bin2hex(random_bytes(32)), $uid, null, 'token', hash('sha256', $tok), $csrf === null ? null : hash('sha256', $csrf),
            rp_now(-5), rp_now(3600), rp_now(-5), '127.0.0.1', 'rp'));
    return $tok;
}

function rp_reset_rl(array $B): void
{
    rp_x($B, 'DELETE FROM app_ulogin_rate_limits');
}

function rp_pcfg(array $over = array()): string
{
    return json_encode(array_merge(array('enabled' => true, 'earn' => true, 'spend' => true, 'per_tx_max' => 100,
        'daily_user_earn_max' => 300, 'daily_user_spend_max' => 1000, 'daily_service_earn_max' => 500), $over));
}

/** 서비스 6개 · 계정 7개 · 약관 2개 · 토큰 3개. 가입 방식은 v1.10.0 운영과 같은 「행 없음 + allow_register=0」 */
function rp_seed(array $B): array
{
    $pdo = rp_pdo($B);
    $now = rp_now();
    $hash = password_hash(RP_PW, PASSWORD_DEFAULT);
    $fx = array('u' => array(), 'tok' => array(), 'sec' => array());
    $ins = $pdo->prepare('INSERT INTO app_ulogin_services (service_id, service_name, service_secret_hash, is_confidential,'
        . ' redirect_whitelist, allowed_origins, auto_join, allow_register, status, access_rule, level_aware, points_json, created_at, updated_at)'
        . ' VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach (array('svc-p' => array('all', 0, 1, rp_pcfg()), 'svc-o' => array('all', 0, 1, rp_pcfg()),
                   'svc-n' => array('all', 0, 1, null), 'svc-v' => array('all_view', 1, 1, rp_pcfg()),
                   'svc-r' => array('all', 0, 1, null), 'svc-c' => array('all', 0, 0, null)) as $sid => $c) {
        $sec = 'sec_' . $sid . '_' . bin2hex(random_bytes(16));
        $fx['sec'][$sid] = $sec;
        $ins->execute(array($sid, 'Svc ' . $sid, password_hash($sec, PASSWORD_DEFAULT), 1, json_encode(array('https://app.test/cb')),
            json_encode(array('http://app.test')), 1, $c[2], 'active', $c[0], $c[1], $c[3], $now, $now));
    }
    $insU = $pdo->prepare('INSERT INTO app_ulogin_users (uuid, username, email, password_hash, status, account_level, role,'
        . ' billing_tier, points, total_points_earned, account_kind, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach (array('sa' => array('superadmin', 'active', 'normal'), 'ad' => array('admin', 'active', 'normal'),
                   'mg' => array('manager', 'active', 'normal'), 'u1' => array('user', 'active', 'normal'),
                   'u3' => array('user', 'active', 'normal'), 'us' => array('user', 'suspended', 'normal'),
                   'em' => array('superadmin', 'active', 'emergency')) as $k => $c) {
        $uuid = gen_uuid4();
        $insU->execute(array($uuid, 'rp_' . $k, 'rp_' . $k . '@test.invalid', $hash, $c[1], 'basic', $c[0], 'free', 1000, 1000, $c[2], $now, $now));
        $fx['u'][$k] = array('id' => (int) $pdo->lastInsertId(), 'uuid' => $uuid, 'username' => 'rp_' . $k);
    }
    foreach (array('sa', 'ad', 'mg', 'u1') as $k) {
        $fx['tok'][$k] = rp_token($pdo, $fx['u'][$k]['id']);
    }
    $fx['csrf'] = bin2hex(random_bytes(16));
    $fx['tok']['ad_cookie'] = rp_token($pdo, $fx['u']['ad']['id'], $fx['csrf']);
    $insT = $pdo->prepare('INSERT INTO app_ulogin_terms (code, version, title, content, is_required, published_at, created_at) VALUES (?,?,?,?,?,?,?)');
    foreach (array('tos', 'privacy') as $c) {
        $insT->execute(array($c, '1.0', $c, $c, 1, rp_now(-3600), $now));
    }
    $pdo->prepare('INSERT INTO app_ulogin_settings (skey, svalue, vtype, label, updated_at) VALUES (?,?,?,?,?)')
        ->execute(array('allow_register', '0', 'bool', '신규 가입 허용', $now));
    $pdo = null;
    return $fx;
}

function rp_mutant(string $key, array $mut, callable $scenario): void
{
    $B = rp_box('mut_' . $key, $mut);
    $died = null;
    try {
        $scenario($B);
    } catch (Throwable $e) {
        $died = $e;
    }
    if ($died === null) {
        fail('mutant ' . $key . ' SURVIVED - the scenario does not guard the line it claims to');
    }
    note('mutant ' . $key . ' caught: ' . substr($died->getMessage(), 0, 140));
}

function rp_cli(array $B, string $code): array
{
    $f = $B['root'] . '/server/tools/_rp_' . bin2hex(random_bytes(3)) . '.php';
    file_put_contents($f, "<?php\ndeclare(strict_types=1);\nrequire __DIR__ . '/../core/bootstrap.php';\n" . $code);
    $rc = 0;
    $out = h_run(h_q(h_php_bin()) . ' -c ' . h_q(rp_ini()) . ' ' . h_q($f), $rc, 60.0);
    @unlink($f);
    return array($rc, $out);
}

// ---------------------------------------------------------------- 공통 동작

function rp_mode(array $B, string $mode): void
{
    rp_ok(rp_http($B, 'PUT', '/server/api/admin/settings.php', array('settings' => array('register_mode' => $mode)), $B['fx']['tok']['sa']),
        'PUT register_mode=' . $mode);
}

function rp_status(array $B): array
{
    return rp_ok(rp_http($B, 'GET', '/server/api/auth/register_status.php'), 'register_status');
}

function rp_reg_body(string $u, array $over = array()): array
{
    return array_merge(array('username' => $u, 'email' => $u . '@corp.test', 'password' => RP_PW, 'password_confirm' => RP_PW,
        'name' => '신청자', 'agree_tos' => true, 'agree_privacy' => true), $over);
}

function rp_register(array $B, string $u, array $over = array()): array
{
    rp_reset_rl($B);
    return rp_http($B, 'POST', '/server/api/auth/register.php', rp_reg_body($u, $over));
}

function rp_login(array $B, string $u, string $pw): array
{
    rp_reset_rl($B);
    return rp_http($B, 'POST', '/server/api/auth/login.php', array('username' => $u, 'password' => $pw));
}

function rp_admin_post(array $B, string $who, array $body): array
{
    return rp_http($B, 'POST', '/server/api/admin/register_requests.php', $body, $B['fx']['tok'][$who]);
}

function rp_s2s(array $B, string $op, string $sid, array $body, ?string $secret = null): array
{
    return rp_http($B, 'POST', '/server/api/points/s2s_' . $op . '.php',
        array_merge(array('service_id' => $sid, 'service_secret' => $secret !== null ? $secret : $B['fx']['sec'][$sid]), $body));
}

function rp_validate(array $B, string $sid): array
{
    return rp_ok(rp_http($B, 'GET', '/server/api/sso/validate.php?service_id=' . $sid . '&redirect_uri=' . rawurlencode('https://app.test/cb')),
        'validate ' . $sid);
}

// =====================================================================
// 시나리오
// =====================================================================

/** closed — v1.10.0 운영(D-315)과 같은가: 행 없음 + allow_register=0 */
function rp_sc_closed(array $B): void
{
    $s = rp_status($B);
    assert_eq(array('allow_register' => false, 'register_mode' => 'closed'), $s, 'closed register_status');
    rp_reset_rl($B);
    rp_err(rp_http($B, 'POST', '/server/api/auth/register.php', '{}'), 403, 'REGISTRATION_DISABLED', 'closed register {}');
    rp_err(rp_register($B, 'closed01'), 403, 'REGISTRATION_DISABLED', 'closed register full body');
    $v = rp_validate($B, 'svc-r');
    assert_false($v['allow_register'], 'closed validate allow_register');
    assert_eq('closed', $v['register_mode'], 'closed validate register_mode');
    assert_eq(0, (int) rp_v($B, 'SELECT COUNT(*) FROM app_ulogin_register_requests'), 'no request rows when closed');
}

/** 9칸 표(전역 × 서비스 상한) — validate 와 register_status 가 같은 함수 */
function rp_sc_matrix(array $B): void
{
    $want = array(
        'closed'   => array('svc-r' => 'closed',   'svc-c' => 'closed', '' => 'closed'),
        'approval' => array('svc-r' => 'approval', 'svc-c' => 'closed', '' => 'approval'),
        'open'     => array('svc-r' => 'open',     'svc-c' => 'closed', '' => 'open'),
    );
    foreach ($want as $g => $row) {
        rp_mode($B, $g);
        foreach ($row as $sid => $exp) {
            $got = $sid === '' ? rp_status($B)['register_mode'] : rp_validate($B, $sid)['register_mode'];
            assert_eq($exp, $got, 'matrix global=' . $g . ' service=' . ($sid === '' ? '(direct)' : $sid));
        }
    }
    // 동기화: approval 이면 allow_register=0 (v1.10.0 으로 되돌려도 닫힘), open 이면 1
    rp_mode($B, 'approval');
    assert_eq('0', (string) rp_v($B, "SELECT svalue FROM app_ulogin_settings WHERE skey = 'allow_register'"), 'approval syncs allow_register=0');
    // 옛 키 true 는 approval 을 open 으로 올리지 않는다
    rp_ok(rp_http($B, 'PUT', '/server/api/admin/settings.php', array('settings' => array('allow_register' => true)), $B['fx']['tok']['sa']), 'PUT legacy true');
    assert_eq('approval', rp_status($B)['register_mode'], 'legacy allow_register=true keeps approval');
    rp_ok(rp_http($B, 'PUT', '/server/api/admin/settings.php', array('settings' => array('allow_register' => false)), $B['fx']['tok']['sa']), 'PUT legacy false');
    assert_eq('closed', rp_status($B)['register_mode'], 'legacy allow_register=false closes');
    $g = rp_ok(rp_http($B, 'GET', '/server/api/admin/settings.php', null, $B['fx']['tok']['sa']), 'GET settings');
    assert_eq('closed', $g['map']['register_mode'], 'settings map register_mode');
    assert_eq('register_mode', $g['items'][16]['skey'], '17th settings item is register_mode');
    // 무효 값은 closed(fail-closed)
    rp_x($B, "UPDATE app_ulogin_settings SET svalue = 'bogus' WHERE skey = 'register_mode'");
    assert_eq('closed', rp_status($B)['register_mode'], 'invalid register_mode row -> closed');
    rp_mode($B, 'closed');
}

function rp_sc_open(array $B): void
{
    rp_mode($B, 'open');
    assert_eq('1', (string) rp_v($B, "SELECT svalue FROM app_ulogin_settings WHERE skey = 'allow_register'"), 'open syncs allow_register=1');
    $d = rp_ok(rp_register($B, 'open0001'), 'open register', 201);
    assert_false($d['approval_required'], 'open approval_required=false');
    assert_true(isset($d['user']['uuid']), 'open returns user');
    assert_eq(1, (int) rp_v($B, "SELECT COUNT(*) FROM app_ulogin_users WHERE username = 'open0001'"), 'open creates the user');
    rp_ok(rp_login($B, 'open0001', RP_PW), 'open login');
    rp_mode($B, 'closed');
}

function rp_sc_approval(array $B): void
{
    rp_mode($B, 'approval');
    $s = rp_status($B);
    assert_eq(array('allow_register' => true, 'register_mode' => 'approval'), $s, 'approval register_status');

    // 1) 접수 — 202 + receipt, users 0, 메일 인증 토큰 0(우회할 대상이 없다)
    $d = rp_ok(rp_register($B, 'appl0001', array('applicant_note' => '연구실 신규')), 'approval register', 202);
    assert_true($d['approval_required'] === true && $d['auto_login'] === false, 'approval flags');
    assert_eq('pending', $d['request']['status'], 'request.status');
    assert_match('~^[0-9a-f]{64}$~', (string) $d['receipt'], 'receipt is 64 hex');
    assert_false(array_key_exists('user', $d), 'no user object in 202');
    $receipt1 = (string) $d['receipt'];
    assert_eq(0, (int) rp_v($B, "SELECT COUNT(*) FROM app_ulogin_users WHERE username = 'appl0001'"), 'approval creates no users row');
    assert_eq(0, (int) rp_v($B, 'SELECT COUNT(*) FROM app_ulogin_email_verifications'), 'approval issues no email verification token');
    $row = rp_q($B, "SELECT * FROM app_ulogin_register_requests WHERE username = 'appl0001'")[0];
    assert_eq(hash('sha256', $receipt1), (string) $row['receipt_hash'], 'only the receipt hash is stored');
    assert_eq('direct', (string) $row['source'], 'source direct');
    assert_true((string) $row['password_hash'] !== '', 'pending keeps the password hash');

    // 2) 승인 전 로그인 — 맞는 비밀번호만 PENDING
    rp_err(rp_login($B, 'appl0001', RP_PW), 403, 'AUTH_ACCOUNT_PENDING', 'pending login, right password');
    rp_err(rp_login($B, 'appl0001', 'Wrong-pass-0000'), 401, 'AUTH_INVALID_CREDENTIALS', 'pending login, wrong password');
    rp_err(rp_login($B, 'appl0001@corp.test', RP_PW), 403, 'AUTH_ACCOUNT_PENDING', 'pending login by email');

    // 3) 중복 — 대기 신청과 겹치면 409
    rp_err(rp_register($B, 'appl0001', array('email' => 'other1@corp.test')), 409, 'DUPLICATE_USERNAME', 'duplicate pending username');
    rp_err(rp_register($B, 'appl0009', array('email' => 'appl0001@corp.test')), 409, 'DUPLICATE_EMAIL', 'duplicate pending email');

    // 4) 서비스 경유 · redirect 대조 · IP 대기 상한(127.0.0.1 에 3건이면 4번째 429)
    rp_ok(rp_register($B, 'appl0002', array('service_id' => 'svc-r', 'redirect_uri' => 'https://app.test/cb', 'entry' => 'sso')), 'approval via service', 202);
    rp_ok(rp_register($B, 'appl0003', array('service_id' => 'svc-r', 'redirect_uri' => 'https://evil.test/cb', 'entry' => 'weird')), 'approval bad redirect', 202);
    $r2 = rp_q($B, "SELECT * FROM app_ulogin_register_requests WHERE username = 'appl0002'")[0];
    $r3 = rp_q($B, "SELECT * FROM app_ulogin_register_requests WHERE username = 'appl0003'")[0];
    assert_eq(array('service', 'svc-r', 'https://app.test/cb', 1, 'sso'),
        array($r2['source'], $r2['service_id'], $r2['redirect_uri'], (int) $r2['redirect_valid'], $r2['entry']), 'service request row');
    assert_eq(array(null, 0, 'register_page'), array($r3['redirect_uri'], (int) $r3['redirect_valid'], $r3['entry']), 'unlisted redirect not stored');
    rp_err(rp_register($B, 'appl0004'), 429, 'RATE_LIMITED', 'ip pending cap', 'ip_pending_cap');
    rp_x($B, "UPDATE app_ulogin_register_requests SET ip = '203.0.113.9' WHERE username IN ('appl0002','appl0003')");

    // 5) 서비스 allow_register=0 이면 approval 전역에서도 closed
    rp_err(rp_register($B, 'appl0005', array('service_id' => 'svc-c')), 403, 'REGISTRATION_DISABLED', 'approval x service closed');

    // 6) 결과 조회
    $st = rp_ok(rp_http($B, 'POST', '/server/api/auth/register_request_status.php', array('receipt' => $receipt1)), 'status by receipt');
    assert_eq(array('status', 'created_at', 'decided_at', 'notice'), array_keys($st), 'status keys');
    assert_eq('pending', $st['status'], 'status pending');
    rp_err(rp_http($B, 'POST', '/server/api/auth/register_request_status.php', array('receipt' => str_repeat('0', 64))), 404, 'NOT_FOUND', 'unknown receipt');

    // 7) 관리 목록·상세 — 권한 · 가리기
    rp_err(rp_http($B, 'GET', '/server/api/admin/register_requests.php', null, $B['fx']['tok']['mg']), 403, 'FORBIDDEN', 'manager list');
    $la = rp_ok(rp_http($B, 'GET', '/server/api/admin/register_requests.php?status=pending', null, $B['fx']['tok']['ad']), 'admin list');
    $ls = rp_ok(rp_http($B, 'GET', '/server/api/admin/register_requests.php?status=pending', null, $B['fx']['tok']['sa']), 'superadmin list');
    assert_eq(3, $la['total'], 'pending total');
    $byU = array();
    foreach ($la['items'] as $it) {
        $byU[$it['username']] = $it;
    }
    $byS = array();
    foreach ($ls['items'] as $it) {
        $byS[$it['username']] = $it;
    }
    assert_eq('127.0.0.x', $byU['appl0001']['ip'], 'admin sees masked ip');
    assert_eq('127.0.0.1', $byS['appl0001']['ip'], 'superadmin sees raw ip');
    assert_eq('Svc svc-r', $byU['appl0002']['service_name'], 'service_name in list');
    foreach (array('id', 'status', 'username', 'source', 'service_id', 'service_name', 'ip', 'ua_label', 'created_at', 'signals') as $k) {
        assert_true(array_key_exists($k, $byU['appl0001']), 'list item key ' . $k);
    }
    $det = rp_ok(rp_http($B, 'GET', '/server/api/admin/register_requests.php?id=' . (int) $row['id'], null, $B['fx']['tok']['ad']), 'detail');
    $req = $det['request'];
    assert_eq(array('same_ip_24h', 'same_ip_30d', 'same_email_domain_30d', 'users_same_email_domain', 'prior_rejected_same_email',
        'prior_rejected_same_ip_30d', 'username_taken', 'email_taken'), array_keys($req['signals']), 'eight signals');
    assert_eq(2, $req['signals']['same_email_domain_30d'], 'same domain count excludes self');
    assert_eq('연구실 신규', $req['applicant_note'], 'applicant note');
    foreach (array('entry', 'redirect_uri', 'redirect_valid', 'decided_at', 'decision_reason', 'decision_notice', 'service', 'expires_at') as $k) {
        assert_true(array_key_exists($k, $req), 'detail key ' . $k);
    }

    // 8) CSRF — 쿠키 세션은 X-CSRF-Token 없이 결정할 수 없다
    $c = rp_http($B, 'POST', '/server/api/admin/register_requests.php', array('action' => 'approve', 'id' => (int) $row['id']), null,
        array('Cookie: ULOGIN_SID=' . $B['fx']['tok']['ad_cookie']));
    rp_err($c, 403, 'CSRF_FAILED', 'cookie session without csrf');
    rp_err(rp_admin_post($B, 'mg', array('action' => 'approve', 'id' => (int) $row['id'])), 403, 'FORBIDDEN', 'manager approve');

    // 9) 승인 → 계정 · 보너스 · 동의(신청 시각) · 해시 삭제 · 감사
    $ap = rp_ok(rp_admin_post($B, 'ad', array('action' => 'approve', 'id' => (int) $row['id'], 'memo' => '교내 확인')), 'approve');
    assert_eq('approved', $ap['request']['status'], 'approved');
    assert_eq(array(), $ap['warnings'], 'no warnings');
    $u = rp_q($B, "SELECT * FROM app_ulogin_users WHERE username = 'appl0001'");
    assert_eq(1, count($u), 'user created on approval');
    assert_eq($ap['request']['user_uuid'], (string) $u[0]['uuid'], 'user_uuid');
    assert_eq(100, (int) $u[0]['points'], 'signup bonus');
    assert_eq(array((string) $row['created_at'], (string) $row['created_at']),
        array_map(function ($x) { return (string) $x['agreed_at']; },
            rp_q($B, 'SELECT agreed_at FROM app_ulogin_user_consents WHERE user_id = ?', array((int) $u[0]['id']))), 'consents dated at request time');
    assert_eq(null, rp_v($B, 'SELECT password_hash FROM app_ulogin_register_requests WHERE id = ?', array((int) $row['id'])), 'hash wiped on approval');
    assert_eq(1, (int) rp_v($B, "SELECT COUNT(*) FROM app_ulogin_admin_logs WHERE action = 'register.approve'"), 'approve audited');
    assert_true((int) rp_v($B, "SELECT COUNT(*) FROM app_ulogin_plat_audit WHERE action = 'register.approve'") >= 1, 'approve on the audit chain');
    rp_ok(rp_login($B, 'appl0001', RP_PW), 'login after approval');
    $again = rp_admin_post($B, 'ad', array('action' => 'approve', 'id' => (int) $row['id']));
    rp_err($again, 409, 'CONFLICT', 'double approve', 'already_decided');

    // 10) 승인 시점 중복 — 409, 신청은 pending 그대로
    rp_x($B, 'INSERT INTO app_ulogin_users (uuid, username, email, password_hash, status, account_level, role, billing_tier, created_at, updated_at)'
        . " VALUES (?, 'appl0002', 'x2@corp.test', 'x', 'active', 'basic', 'user', 'free', ?, ?)", array(gen_uuid4(), rp_now(), rp_now()));
    rp_err(rp_admin_post($B, 'ad', array('action' => 'approve', 'id' => (int) $r2['id'])), 409, 'DUPLICATE_USERNAME', 'approve after username taken');
    assert_eq('pending', (string) rp_v($B, 'SELECT status FROM app_ulogin_register_requests WHERE id = ?', array((int) $r2['id'])), 'stays pending');

    // 11) 거부 — 사유 필수 · 해시 삭제 · 안내 문구 조회 · 7일 재신청 금지
    rp_err(rp_admin_post($B, 'ad', array('action' => 'reject', 'id' => (int) $r3['id'])), 400, 'VALIDATION_FAILED', 'reject without reason');
    rp_ok(rp_admin_post($B, 'ad', array('action' => 'reject', 'id' => (int) $r3['id'], 'reason' => '외부인', 'notice' => '소속 확인이 안 됩니다')), 'reject');
    assert_eq(null, rp_v($B, 'SELECT password_hash FROM app_ulogin_register_requests WHERE id = ?', array((int) $r3['id'])), 'hash wiped on reject');
    assert_eq(1, (int) rp_v($B, "SELECT COUNT(*) FROM app_ulogin_admin_logs WHERE action = 'register.reject'"), 'reject audited');
    rp_x($B, "DELETE FROM app_ulogin_register_requests WHERE username = 'appl0002'");
    $rr = rp_register($B, 'appl0006', array('email' => 'appl0003@corp.test'));
    rp_err($rr, 429, 'RATE_LIMITED', 'recently rejected email');
    assert_false(isset($rr['json']['data']['reason']), 'v1.10.1 L-4: the recently-rejected refusal carries no reason');

    // 12) 대기열 상한 200
    $pdo = rp_pdo($B);
    $ins = $pdo->prepare('INSERT INTO app_ulogin_register_requests (receipt_hash, status, username, email, email_domain, ip, created_at, expires_at)'
        . ' VALUES (?,?,?,?,?,?,?,?)');
    for ($i = 0; $i < 200; $i++) {
        $ins->execute(array(hash('sha256', 'q' . $i), 'pending', 'qf' . $i, 'qf' . $i . '@q.test', 'q.test', '198.51.100.' . ($i % 250), rp_now(), rp_now(86400)));
    }
    $pdo = null;
    $stats = rp_ok(rp_http($B, 'GET', '/server/api/admin/stats.php', null, $B['fx']['tok']['ad']), 'stats');
    assert_eq(200, $stats['users']['register_requests_pending'], 'stats register_requests_pending');
    assert_true(array_key_exists('pending', $stats['users']), 'stats keeps users.pending');
    rp_err(rp_register($B, 'appl0007'), 429, 'RATE_LIMITED', 'queue full', 'queue_full');
    rp_x($B, "DELETE FROM app_ulogin_register_requests WHERE username LIKE 'qf%'");
    rp_mode($B, 'closed');
}

/** 공동 포인트 s2s */
function rp_sc_points(array $B): void
{
    $u1 = $B['fx']['u']['u1']['uuid'];
    $earn = function (string $key, int $amt, string $sid = 'svc-p', ?string $uuid = null) use ($B, $u1) {
        return rp_s2s($B, 'earn', $sid, array('uuid' => $uuid === null ? $u1 : $uuid, 'amount' => $amt, 'idempotency_key' => $key,
            'ref_type' => 'quiz', 'ref_id' => '778', 'memo' => '퀴즈 만점'));
    };
    rp_reset_rl($B);
    rp_err(rp_s2s($B, 'balance', 'svc-p', array('uuid' => $u1), 'wrong-secret-xxxxxxxx'), 401, 'SSO_CLIENT_AUTH_FAILED', 'wrong secret');
    rp_err(rp_s2s($B, 'balance', 'svc-p', array('uuid' => $B['fx']['u']['em']['uuid'])), 404, 'NOT_FOUND', 'emergency account');
    rp_err(rp_s2s($B, 'balance', 'svc-p', array('uuid' => gen_uuid4())), 404, 'NOT_FOUND', 'unknown uuid');
    rp_err(rp_s2s($B, 'earn', 'svc-p', array('uuid' => $B['fx']['u']['us']['uuid'], 'amount' => 1, 'idempotency_key' => 'susp-0001')),
        403, 'FORBIDDEN', 'suspended', 'user_inactive');
    rp_err(rp_s2s($B, 'earn', 'svc-v', array('uuid' => $u1, 'amount' => 1, 'idempotency_key' => 'view-0001')), 403, 'SERVICE_ACCESS_DENIED', 'view level');
    rp_err(rp_s2s($B, 'balance', 'svc-n', array('uuid' => $u1)), 403, 'FORBIDDEN', 'points_json NULL', 'points_disabled');
    rp_err(rp_s2s($B, 'earn', 'svc-p', array('uuid' => $u1, 'amount' => 1)), 400, 'VALIDATION_FAILED', 'missing idempotency_key');
    rp_err(rp_s2s($B, 'earn', 'svc-p', array('uuid' => $u1, 'amount' => '5', 'idempotency_key' => 'str-amount-1')), 400, 'VALIDATION_FAILED', 'string amount');

    $bal = rp_ok(rp_s2s($B, 'balance', 'svc-p', array('uuid' => $u1)), 'balance');
    assert_eq(1000, $bal['points'], 'balance points');
    assert_eq(array('per_tx_max' => 100, 'earn_remaining_24h' => 300, 'spend_remaining_24h' => 1000, 'earn' => true, 'spend' => true),
        $bal['limits'], 'balance limits');

    // 적립 · 멱등 재실행 · 다른 내용 409
    $e1 = rp_ok($earn('quiz-2026-11-16-778', 50), 'earn 50');
    assert_eq(array(50, 1050, 1050, false), array($e1['delta'], $e1['points'], $e1['balance_after'], $e1['idempotent_replay']), 'earn result');
    $e1b = rp_ok($earn('quiz-2026-11-16-778', 50), 'earn replay');
    assert_true($e1b['idempotent_replay'] === true && $e1b['log_id'] === $e1['log_id'] && $e1b['points'] === 1050, 'replay does not move points');
    rp_err($earn('quiz-2026-11-16-778', 60), 409, 'CONFLICT', 'same key other amount', 'idempotency_mismatch');
    assert_eq(1, (int) rp_v($B, "SELECT COUNT(*) FROM app_ulogin_point_logs WHERE service_id = 'svc-p' AND reason = 's2s_earn'"), 'ledger 1 row for k1');
    $stored = (string) rp_v($B, 'SELECT idempotency_key FROM app_ulogin_point_logs WHERE id = ?', array($e1['log_id']));
    assert_eq('s2s:' . hash('sha256', "svc-p\nquiz-2026-11-16-778"), $stored, 'stored key = s2s: + sha256(sid \\n key)');
    // 같은 키라도 다른 서비스면 충돌하지 않는다
    $o1 = rp_ok($earn('quiz-2026-11-16-778', 10, 'svc-o'), 'other service, same key');
    assert_false($o1['idempotent_replay'], 'other service is not a replay');

    // 한도 4종
    rp_err($earn('per-tx-000001', 101), 429, 'QUOTA_EXCEEDED', 'per_tx', 'per_tx');
    rp_ok($earn('dayu-0000001', 100), 'earn 100 (150)');
    rp_ok($earn('dayu-0000002', 100), 'earn 100 (250)');
    $q = $earn('dayu-0000003', 100);
    rp_err($q, 429, 'QUOTA_EXCEEDED', 'daily_user_earn', 'daily_user_earn');
    assert_eq(array(300, 250, 100), array($q['json']['data']['limit'], $q['json']['data']['used_24h'], $q['json']['data']['requested']), 'quota data');
    $u3 = $B['fx']['u']['u3']['uuid'];
    rp_ok($earn('days-0000001', 100, 'svc-p', $u3), 'u3 earn 100 (svc 350)');
    rp_ok($earn('days-0000002', 100, 'svc-p', $u3), 'u3 earn 100 (svc 450)');
    rp_err($earn('days-0000003', 100, 'svc-p', $u3), 429, 'QUOTA_EXCEEDED', 'daily_service_earn', 'daily_service_earn');
    $spend = function (string $key, int $amt) use ($B, $u1) {
        return rp_s2s($B, 'spend', 'svc-p', array('uuid' => $u1, 'amount' => $amt, 'idempotency_key' => $key));
    };
    // 잔액 1250. 잔액 부족은 한도 안에서만 볼 수 있다 — per_tx 100 이라 u3(잔액 1200)로 2000 은 per_tx 가 먼저다
    rp_x($B, "UPDATE app_ulogin_users SET points = 30 WHERE id = ?", array($B['fx']['u']['u3']['id']));
    $ins = rp_s2s($B, 'spend', 'svc-p', array('uuid' => $u3, 'amount' => 50, 'idempotency_key' => 'insuf-000001'));
    rp_err($ins, 409, 'POINT_INSUFFICIENT', 'insufficient');
    assert_eq(array(30, 50, 20), array($ins['json']['data']['points'], $ins['json']['data']['required'], $ins['json']['data']['shortfall']), 'insufficient data');
    rp_x($B, "UPDATE app_ulogin_services SET points_json = ? WHERE service_id = 'svc-p'", array(rp_pcfg(array('daily_user_spend_max' => 150))));
    rp_ok($spend('spend-000001', 100), 'spend 100');
    rp_err($spend('spend-000002', 100), 429, 'QUOTA_EXCEEDED', 'daily_user_spend', 'daily_user_spend');
    $sp = rp_ok($spend('spend-000003', 40), 'spend 40');
    assert_eq(-40, $sp['delta'], 'spend delta is negative');
    rp_x($B, "UPDATE app_ulogin_services SET points_json = ? WHERE service_id = 'svc-p'", array(rp_pcfg(array('spend' => false))));
    rp_err($spend('spend-000004', 1), 403, 'FORBIDDEN', 'spend disabled', 'spend_disabled');
    rp_x($B, "UPDATE app_ulogin_services SET points_json = ? WHERE service_id = 'svc-p'", array('{"enabled":true}'));
    rp_err($spend('spend-000005', 1), 403, 'FORBIDDEN', 'broken points_json', 'points_disabled');
    rp_x($B, "UPDATE app_ulogin_services SET points_json = ? WHERE service_id = 'svc-p'", array(rp_pcfg(array('per_tx_max' => 1000, 'daily_user_earn_max' => 100000))));

    // history — 호출 서비스의 행만
    $h = rp_ok(rp_s2s($B, 'history', 'svc-p', array('uuid' => $u1, 'limit' => 2)), 'history');
    assert_eq(2, count($h['items']), 'history limit');
    assert_true(is_int($h['next_before_id']), 'history cursor');
    $all = rp_ok(rp_s2s($B, 'history', 'svc-p', array('uuid' => $u1, 'limit' => 100)), 'history all');
    $foreign = array_map('intval', array_column(rp_q($B, "SELECT id FROM app_ulogin_point_logs WHERE service_id <> 'svc-p' OR service_id IS NULL"), 'id'));
    assert_true($foreign !== array(), 'HARNESS-FAIL there must be rows of another service to leak');
    foreach ($all['items'] as $it) {
        assert_false(in_array($it['log_id'], $foreign, true), 'history leaks a row of another service: ' . $it['log_id']);
    }
    assert_eq((int) rp_v($B, "SELECT COUNT(*) FROM app_ulogin_point_logs WHERE service_id = 'svc-p' AND user_id = ?", array($B['fx']['u']['u1']['id'])),
        count($all['items']), 'history returns every own row');
    assert_eq(array('log_id', 'delta', 'balance_after', 'reason', 'ref_type', 'ref_id', 'memo', 'created_at'), array_keys($all['items'][0]), 'history item keys');

    // consume 는 s2s: 키를 거절한다
    rp_err(rp_http($B, 'POST', '/server/api/points/consume.php', array('amount' => 1, 'idempotency_key' => 's2s:abc'), $B['fx']['tok']['u1']),
        400, 'VALIDATION_FAILED', 'consume refuses s2s: keys');
    rp_ok(rp_http($B, 'POST', '/server/api/points/consume.php', array('amount' => 1, 'idempotency_key' => 'user-key-1'), $B['fx']['tok']['u1']), 'consume still works');

    // 같은 키 2연속(소켓 둘을 먼저 열어 보낸다) → 원장 1행
    $body = json_encode(array('service_id' => 'svc-p', 'service_secret' => $B['fx']['sec']['svc-p'], 'uuid' => $u1, 'amount' => 7, 'idempotency_key' => 'dup-par-0001'));
    $socks = array();
    for ($i = 0; $i < 2; $i++) {
        $sk = @fsockopen('127.0.0.1', (int) $B['port'], $en, $es, 5.0);
        stream_set_timeout($sk, 30);
        fwrite($sk, rp_req_text($B, 'POST', '/server/api/points/s2s_earn.php', $body, null, array()));
        $socks[] = $sk;
    }
    $res = array_map('rp_read', $socks);
    assert_eq(array(200, 200), array($res[0]['status'], $res[1]['status']), 'both answered 200');
    assert_eq(1, (int) rp_v($B, 'SELECT COUNT(*) FROM app_ulogin_point_logs WHERE idempotency_key = ?', array('s2s:' . hash('sha256', "svc-p\ndup-par-0001"))),
        'one ledger row for two same-key requests');

    // 감사 체인 · 사용량 · 마이페이지 service_name
    assert_true((int) rp_v($B, "SELECT COUNT(*) FROM app_ulogin_plat_audit WHERE action = 'points.s2s_earn'") >= 5, 'earn on the audit chain');
    assert_true((int) rp_v($B, "SELECT COUNT(*) FROM app_ulogin_plat_audit WHERE action = 'points.s2s_spend'") >= 2, 'spend on the audit chain');
    assert_true((int) rp_v($B, "SELECT COALESCE(SUM(value),0) FROM app_ulogin_plat_usage WHERE service_id = 'svc-p' AND metric = 'points_earn'") >= 5, 'usage metric');
    $mp = rp_ok(rp_http($B, 'GET', '/server/api/me/points.php?service_id=svc-p', null, $B['fx']['tok']['u1']), 'me/points');
    assert_true($mp['items'] !== array(), 'me/points has rows');
    foreach ($mp['items'] as $it) {
        assert_eq('svc-p', $it['service_id'], 'me/points service filter');
        assert_eq('Svc svc-p', $it['service_name'], 'me/points service_name');
    }
}

/** 관리 API services.points · 설정 번들 export */
function rp_sc_services(array $B): void
{
    $sa = $B['fx']['tok']['sa'];
    $bad = rp_http($B, 'PUT', '/server/api/admin/services.php?service_id=svc-n', array('points' => array('enabled' => true)), $sa);
    rp_err($bad, 400, 'VALIDATION_FAILED', 'invalid points');
    assert_eq('points', $bad['json']['error']['field'] ?? null, 'invalid points field');
    $neg = rp_http($B, 'PUT', '/server/api/admin/services.php?service_id=svc-n', array('points' => json_decode(rp_pcfg(array('per_tx_max' => -1)), true)), $sa);
    rp_err($neg, 400, 'VALIDATION_FAILED', 'negative per_tx_max');
    $ok = rp_ok(rp_http($B, 'PUT', '/server/api/admin/services.php?service_id=svc-n', array('points' => json_decode(rp_pcfg(), true)), $sa), 'set points');
    assert_eq(json_decode(rp_pcfg(), true), $ok['service']['points'], 'service.points echo');
    assert_true(in_array('points', $ok['changed_fields'], true), 'changed_fields has points');
    assert_eq(1, (int) rp_v($B, "SELECT COUNT(*) FROM app_ulogin_admin_logs WHERE action = 'service.points' AND target_id = 'svc-n'"), 'service.points audited');
    rp_ok(rp_http($B, 'PUT', '/server/api/admin/services.php?service_id=svc-n', array('points' => null), $sa), 'clear points');
    assert_eq(null, rp_v($B, "SELECT points_json FROM app_ulogin_services WHERE service_id = 'svc-n'"), 'points cleared');
    $list = rp_ok(rp_http($B, 'GET', '/server/api/admin/services.php?service_id=svc-p', null, $sa), 'services list');
    assert_true(array_key_exists('points', $list['items'][0]), 'list has points');

    $ex = rp_ok(rp_http($B, 'GET', '/server/api/admin/settings_bundle.php', null, $sa), 'bundle export');
    $bundle = isset($ex['bundle']) ? $ex['bundle'] : $ex;
    assert_true(array_key_exists('register_mode', $bundle['settings']), 'bundle settings has register_mode');
    $svcs = array();
    foreach ($bundle['services'] as $sv) {
        $svcs[$sv['service_id']] = $sv;
    }
    assert_true(array_key_exists('points', $svcs['svc-p']), 'bundle service has points');
    // 번들로 approval → 같은 번들 두 번째 dry-run 은 무변경(멱등)
    $b = array('ulogin_settings_bundle' => $bundle['ulogin_settings_bundle'], 'settings' => array('register_mode' => 'approval'),
        'services' => array(array('service_id' => 'svc-n', 'service_name' => 'Svc svc-n', 'points' => json_decode(rp_pcfg(), true))));
    $req = array('bundle' => $b, 'on_conflict' => 'merge');
    $dr = rp_ok(rp_http($B, 'POST', '/server/api/admin/settings_bundle.php?action=dry-run', $req, $sa), 'bundle dry-run');
    $ap = rp_http($B, 'POST', '/server/api/admin/settings_bundle.php?action=apply', array_merge($req, array('expect_plan_hash' => $dr['plan_hash'])), $sa);
    rp_ok($ap, 'bundle apply');
    assert_eq('approval', rp_status($B)['register_mode'], 'bundle set approval');
    assert_eq('0', (string) rp_v($B, "SELECT svalue FROM app_ulogin_settings WHERE skey = 'allow_register'"), 'bundle synced allow_register');
    assert_true(rp_v($B, "SELECT points_json FROM app_ulogin_services WHERE service_id = 'svc-n'") !== null, 'bundle wrote points_json');
    $dr2 = rp_ok(rp_http($B, 'POST', '/server/api/admin/settings_bundle.php?action=dry-run', $req, $sa), 'bundle dry-run 2');
    assert_eq(0, (int) $dr2['plan']['counts']['settings_update'], 'second dry-run changes no setting');
    assert_eq(0, (int) $dr2['plan']['counts']['services_update'], 'second dry-run changes no service');
    rp_mode($B, 'closed');
}

/** D-373 관리자·비상 계정 비밀번호 10자 경계 */
function rp_sc_pw(array $B): void
{
    list($rc, $out) = rp_cli($B, '
$t = function ($pw, $rank) { try { validate_password($pw, "someone", "password", $rank); return "ok"; } catch (AppError $e) { return "no"; } };
echo json_encode(array(
  "admin9"   => $t("Zk4!mq7Tb", 20),
  "admin10"  => $t("Zk4!mq7Tbx", 20),
  "super10"  => $t("Zk4!mq7Tbx", 30),
  "common10" => $t("Password1!", 20),
  "repeat10" => $t("abcabcabc1", 20),
  "user8"    => $t("Zk4!mq7T", 0),
  "min"      => EM_STRONG_MIN,
));');
    // 자식의 stderr(확장 경고)가 같은 출력에 섞인다 — JSON 한 덩어리만 꺼낸다
    $j = preg_match('~\{"admin9"[^}]*\}~', $out, $m) === 1 ? json_decode($m[0], true) : null;
    assert_true(is_array($j), 'cli output rc=' . $rc . ' (' . substr((string) preg_replace('~^(Cannot find module|MIB search|PHP Warning|Warning).*$~m', '', $out), 0, 900) . ')');
    assert_eq(array('admin9' => 'no', 'admin10' => 'ok', 'super10' => 'ok', 'common10' => 'no', 'repeat10' => 'no', 'user8' => 'ok', 'min' => 10),
        $j, 'admin password boundary 9 no / 10 ok, common still refused, user rule unchanged');
}

/** 0011 — 0010 모양 SQLite → apply → verify → 재적용 무변경 → closed 동치 */
function rp_sc_migrate(array $B): void
{
    $schema = (string) file_get_contents($B['root'] . '/server/schema.sql');
    $old = preg_replace('~^CREATE TABLE IF NOT EXISTS `app_ulogin_register_requests` \(.*?\n\) ENGINE[^;]*;~ms', '', $schema, -1, $nt);
    $old = preg_replace('~^\s*`points_json`[^\n]*\n~m', '', (string) $old, -1, $nc);
    $old = preg_replace('~,\n\s*KEY `idx_point_logs_service` \(`service_id`, `created_at`\)~', '', (string) $old, -1, $nk);
    if ($nt !== 1 || $nc !== 1 || $nk !== 1) {
        fail('HARNESS-FAIL could not derive the 0010-state schema (' . $nt . '/' . $nc . '/' . $nk . ')');
    }
    $oldFile = $B['base'] . '/schema_0010.sql';
    file_put_contents($oldFile, $old);
    $db = $B['base'] . '/data/ulogin_test_rp_mig.sqlite';
    $pdo = new PDO('sqlite:' . $db, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    mig_sqlite_create_schema($pdo, $oldFile, 'app_ulogin_', false);
    $files = array();
    foreach (mig_list($B['root'] . '/server/migrations') as $f) {
        if (strcmp((string) $f['version'], '0011') < 0) {
            $files[] = $f;
        }
    }
    assert_eq(10, count($files), 'ten migrations before 0011');
    mig_stamp_baseline($pdo, $files, 'app_ulogin_schema_migrations', 'test');
    $pdo->exec("INSERT INTO app_ulogin_settings (skey, svalue, vtype, label, updated_at) VALUES ('allow_register','0','bool','x','2026-11-16 00:00:00')");
    $pdo = null;
    $cfgFile = $B['root'] . '/server/config.php';
    $cfgOrig = (string) file_get_contents($cfgFile);
    file_put_contents($cfgFile, rp_set_define($cfgOrig, 'DB_SQLITE_PATH', var_export($db, true)));
    $run = function ($cmd) use ($B) {
        $code = 0;
        $out = h_run(h_q(h_php_bin()) . ' -c ' . h_q(rp_ini()) . ' ' . h_q($B['root'] . '/server/migrate.php') . ' ' . $cmd, $code);
        return array($code, $out);
    };
    try {
        list($c1, $o1) = $run('apply');
        assert_eq(0, $c1, 'migrate apply 0010 -> 0011 (' . substr($o1, -600) . ')');
        assert_contains('0011', $o1, 'apply ran 0011');
        list($c2, $o2) = $run('verify');
        assert_eq(0, $c2, 'verify after upgrade equals schema.sql (' . substr($o2, -600) . ')');
        list($c3, $o3) = $run('apply');
        assert_eq(0, $c3, 'second apply is a no-op (' . substr($o3, -300) . ')');
        list($c4, $o4) = rp_cli($B, 'echo "MODE=" . register_mode_effective(null);');
        assert_contains('MODE=closed', $o4, 'after 0011 a v1.10.0 closed install stays closed');
        $pdo = new PDO('sqlite:' . $db);
        assert_eq(0, (int) $pdo->query("SELECT COUNT(*) FROM app_ulogin_settings WHERE skey = 'register_mode'")->fetchColumn(), '0011 writes no register_mode row');
        $pdo = null;
    } finally {
        file_put_contents($cfgFile, $cfgOrig);
    }
}

/** 원시 폼 POST(application/x-www-form-urlencoded). $hdr 에 Host 를 넣으면 기본 Host 를 대신한다. */
function rp_raw(array $B, string $method, string $path, ?array $form, array $hdr): array
{
    $s = @fsockopen('127.0.0.1', (int) $B['port'], $errno, $errstr, 5.0);
    if ($s === false) {
        return array('status' => 0, 'body' => '', 'json' => null, 'head' => '');
    }
    stream_set_timeout($s, 30);
    $hasHost = false;
    foreach ($hdr as $h) {
        if (stripos($h, 'host:') === 0) {
            $hasHost = true;
        }
    }
    $req = $method . ' ' . $path . " HTTP/1.0\r\n" . ($hasHost ? '' : 'Host: 127.0.0.1:' . $B['port'] . "\r\n") . "Connection: close\r\n";
    foreach ($hdr as $h) {
        $req .= $h . "\r\n";
    }
    $body = $form === null ? '' : http_build_query($form);
    if ($form !== null) {
        $req .= "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\n";
    }
    fwrite($s, $req . "\r\n" . $body);
    return rp_read($s);
}

function rp_la_token(array $r): string
{
    if (preg_match('~name="t" value="([0-9a-f]{48})"~', $r['body'], $m) !== 1) {
        fail('no csrf token on the local-admin page (status ' . $r['status'] . ')');
    }
    return $m[1];
}

/** v1.10.1 M-2 — CLI 로 1회용 열쇠를 만들고(--open-link --no-browser) 링크의 k 를 돌려준다. */
function rp_la_open(array $B): string
{
    $rc = 0;
    $out = h_run(h_q(h_php_bin()) . ' -c ' . h_q(rp_ini()) . ' ' . h_q($B['root'] . '/server/tools/emergency_admin.php')
        . ' --open-link --no-browser', $rc, 60.0);
    if ($rc !== 0 || preg_match('~url: http://127\.0\.0\.1:(\d+)/local-admin\?k=([0-9a-f]{64})~', $out, $m) !== 1) {
        fail('open-link failed rc=' . $rc . ' ' . substr((string) preg_replace('~^(Cannot find module|MIB search|PHP Warning|Warning).*$~m', '', $out), 0, 600));
    }
    assert_eq((string) $B['port'], $m[1], 'open-link port comes from config (loopback BASE_URL)');
    return $m[2];
}

function rp_la_keyfile(array $B): string
{
    return $B['base'] . '/secrets/local-admin.key';
}

/** 응답 머리의 ULOGIN_LA Set-Cookie 한 줄(없으면 null) */
function rp_la_setcookie(array $r): ?string
{
    return preg_match('~(?mi)^Set-Cookie:\s*(ULOGIN_LA=[^\r\n]*)~', $r['head'], $m) === 1 ? $m[1] : null;
}

/** k → 쿠키 값(302 · Set-Cookie 속성 확인 포함) */
function rp_la_session(array $B, string $k): string
{
    $r = rp_raw($B, 'GET', '/local-admin?k=' . $k, null, array());
    assert_eq(302, $r['status'], 'good key -> 302 (' . substr(strip_tags($r['body']), 0, 200) . ')');
    assert_match('~(?mi)^Location:\s*/local-admin\s*$~', $r['head'], 'redirect drops k from the address');
    $sc = rp_la_setcookie($r);
    assert_true($sc !== null, 'good key sets the ULOGIN_LA cookie');
    assert_match('~^ULOGIN_LA=[0-9a-f]{64};~', (string) $sc, 'cookie value is 64 hex');
    foreach (array('~;\s*Path=/local-admin(;|$)~', '~;\s*HttpOnly(;|$)~i', '~;\s*SameSite=Strict(;|$)~i', '~;\s*Max-Age=600(;|$)~') as $re) {
        assert_match($re, (string) $sc, 'cookie attribute ' . $re);
    }
    assert_false((bool) preg_match('~;\s*Secure~i', (string) $sc), 'no Secure on the plain-http loopback page');
    preg_match('~^ULOGIN_LA=([0-9a-f]{64})~', (string) $sc, $m);
    return $m[1];
}

function rp_la_cookie(string $c): string
{
    return 'Cookie: ULOGIN_LA=' . $c;
}

/** 열쇠 없이 보이는 화면 — 안내만, 폼·상태·토큰 없음 */
function rp_la_assert_guide(array $r, string $what): void
{
    assert_contains('emergency-admin.bat open', $r['body'], $what . ': shows the open hint');
    assert_not_contains('id="la-set"', $r['body'], $what . ': no password form');
    assert_not_contains('name="t"', $r['body'], $what . ': no csrf token');
    assert_not_contains('id="la-badge"', $r['body'], $what . ': no status badge');
}

/** D-374 /local-admin — 서버 PC 판별 3조건 · CSRF · Origin · 같은 처리 함수 · 값 무기록
 *  + v1.10.1 M-2 1회용 열쇠(쿠키 없으면 안내만 · 열쇠 없는 POST 403 · 재사용·만료·틀린 열쇠 거절 · 쿠키 Path · 성공 시 폐기) */
function rp_sc_local(array $B): void
{
    $self = 'http://127.0.0.1:' . $B['port'];
    $emId = $B['fx']['u']['em']['id'];
    @unlink(rp_la_keyfile($B));
    // 1) 열쇠 없이 — 로컬 조건은 통과하지만 안내만
    $g = rp_raw($B, 'GET', '/local-admin', null, array());
    assert_eq(200, $g['status'], 'local GET without key (' . substr($g['body'], 0, 200) . ')');
    assert_contains('비상 관리자 계정', $g['body'], 'page title');
    assert_match('~(?mi)^Cache-Control: no-store~', $g['head'], 'no-store');
    rp_la_assert_guide($g, 'no key');
    assert_eq(null, rp_la_setcookie($g), 'no cookie without a key');
    foreach (array(
        'public Host'        => array('Host: login.example.com'),
        'Host without port'  => array('Host: 127.0.0.1'),
        'Cf-Ray'             => array('Cf-Ray: 8a1b2c3d4e5f-ICN'),
        'Cf-Connecting-Ip'   => array('Cf-Connecting-Ip: 203.0.113.5'),
        'X-Forwarded-For'    => array('X-Forwarded-For: 203.0.113.5'),
        'X-Forwarded-Host'   => array('X-Forwarded-Host: login.example.com'),
        'Forwarded'          => array('Forwarded: for=203.0.113.5'),
        'X-Real-Ip'          => array('X-Real-Ip: 203.0.113.5'),
        'Cdn-Loop'           => array('Cdn-Loop: cloudflare'),
        'Cf-Ipcountry (L-1)' => array('Cf-Ipcountry: KR'),
        'Cf-Visitor (L-1)'   => array('Cf-Visitor: {"scheme":"https"}'),
        'X-Forwarded-Proto (L-1)' => array('X-Forwarded-Proto: https'),
        'True-Client-Ip (L-1)'    => array('True-Client-Ip: 203.0.113.5'),
    ) as $what => $h) {
        $r = rp_raw($B, 'GET', '/local-admin', null, $h);
        assert_eq(404, $r['status'], $what . ' -> 404');
        assert_not_contains('비상 관리자', $r['body'], $what . ' leaks nothing');
    }
    assert_eq(404, rp_raw($B, 'GET', '/server/local_admin.php', null, array())['status'], 'direct file path is 404');
    assert_eq(404, rp_raw($B, 'GET', '/local-admin/', null, array())['status'], 'trailing slash is 404');
    assert_eq(404, rp_raw($B, 'POST', '/local-admin', array('act' => 'unlock'), array('Host: login.example.com'))['status'], 'public POST 404');

    $hash0 = (string) rp_v($B, 'SELECT password_hash FROM app_ulogin_users WHERE id = ?', array($emId));
    $newPw = 'Qv7#mLp2xZ';   // 10자
    // 2) 열쇠 없는 POST — CSRF 토큰·Origin 이 맞아도 거절(토큰은 DB 에 직접 심는다. 열쇠 없이는 화면이 토큰을 주지 않는다)
    $planted = bin2hex(random_bytes(24));
    rp_x($B, 'INSERT INTO app_ulogin_rate_limits (bucket, window_start, count, expires_at) VALUES (?,?,?,?)',
        array('la:csrf:' . hash('sha256', $planted), rp_now(), 0, rp_now(600)));
    $r = rp_raw($B, 'POST', '/local-admin', array('t' => $planted, 'act' => 'set', 'pw' => $newPw, 'pw2' => $newPw), array('Origin: ' . $self));
    assert_eq(403, $r['status'], 'POST without key is refused even with a valid csrf token and Origin');
    assert_contains('열쇠가 없거나', $r['body'], 'no-key POST message');
    assert_eq($hash0, (string) rp_v($B, 'SELECT password_hash FROM app_ulogin_users WHERE id = ?', array($emId)), 'no-key POST changes nothing');
    $r = rp_raw($B, 'POST', '/local-admin', array('t' => $planted, 'act' => 'set', 'pw' => $newPw, 'pw2' => $newPw),
        array('Origin: ' . $self, rp_la_cookie(str_repeat('ab', 32))));
    assert_eq(403, $r['status'], 'POST with a forged cookie is refused');

    // 3) 열쇠 발급 — 파일에는 HMAC 만, 원문 없음 · ACL(Windows)
    $k = rp_la_open($B);
    $kf = rp_la_keyfile($B);
    assert_true(is_file($kf), 'key file written under <runtime>/secrets');
    $raw = (string) file_get_contents($kf);
    assert_not_contains($k, $raw, 'key file never holds the key itself');
    $rec = json_decode($raw, true);
    assert_eq('link', $rec['stage'] ?? null, 'key file stage link');
    assert_true(is_int($rec['exp'] ?? null) && $rec['exp'] > time() + 500 && $rec['exp'] <= time() + 600, 'link lives 10 minutes');
    if (DIRECTORY_SEPARATOR === '\\') {
        $acl = h_run('icacls ' . h_q(str_replace('/', '\\', $kf)), $rcA, 30.0);
        assert_eq(0, $rcA, 'icacls readable (' . substr($acl, 0, 200) . ')');
        assert_not_contains('(I)', $acl, 'key file ACL is not inherited');
        assert_contains('SYSTEM:(F)', $acl, 'SYSTEM full');
        assert_contains('Administrators:(F)', $acl, 'Administrators full');
        assert_contains(getenv('USERNAME') . ':(F)', $acl, 'running user full');
        foreach (array('BUILTIN\\Users', 'Everyone', 'Authenticated Users', 'CodexSandbox') as $bad) {
            assert_not_contains($bad, $acl, 'key file ACL has no ' . $bad);
        }
    }
    // 틀린 열쇠 · 모양이 틀린 열쇠 → 403 안내, 쿠키 없음. 진짜 열쇠는 아직 살아 있다
    foreach (array(str_repeat('0', 64), substr($k, 0, 63), $k . 'x', strtoupper($k)) as $bad) {
        $r = rp_raw($B, 'GET', '/local-admin?k=' . rawurlencode($bad), null, array());
        assert_eq(403, $r['status'], 'wrong key -> 403');
        assert_eq(null, rp_la_setcookie($r), 'wrong key sets no cookie');
        rp_la_assert_guide($r, 'wrong key');
    }
    // 4) 맞는 열쇠 → 쿠키 + 302 · 재사용 거절
    $c = rp_la_session($B, $k);
    $again = rp_raw($B, 'GET', '/local-admin?k=' . $k, null, array());
    assert_eq(403, $again['status'], 'key reuse -> 403');
    assert_eq(null, rp_la_setcookie($again), 'key reuse sets no cookie');
    rp_la_assert_guide($again, 'key reuse');
    assert_eq('session', json_decode((string) file_get_contents($kf), true)['stage'] ?? null, 'file moved to the session stage');
    assert_not_contains($c, (string) file_get_contents($kf), 'key file never holds the cookie value');
    $ck = rp_la_cookie($c);

    // 5) 쿠키로 — 폼 · 상태 · 토큰
    $g = rp_raw($B, 'GET', '/local-admin', null, array($ck));
    assert_eq(200, $g['status'], 'GET with the cookie');
    assert_contains('data-value="active">설정됨<', $g['body'], 'status badge set');
    assert_contains('id="la-set"', $g['body'], 'form shown with the cookie');
    assert_contains('emergency-admin.bat', $g['body'], 'CLI shown');

    // CSRF 없음 · 출처 없음 · 다른 출처 → 거절, 해시 그대로
    $r = rp_raw($B, 'POST', '/local-admin', array('act' => 'set', 'pw' => $newPw, 'pw2' => $newPw), array('Origin: ' . $self, $ck));
    assert_eq(403, $r['status'], 'POST without csrf token');
    $t = rp_la_token(rp_raw($B, 'GET', '/local-admin', null, array($ck)));
    $r = rp_raw($B, 'POST', '/local-admin', array('t' => $t, 'act' => 'set', 'pw' => $newPw, 'pw2' => $newPw), array($ck));
    assert_eq(403, $r['status'], 'POST without Origin/Referer');
    $t = rp_la_token(rp_raw($B, 'GET', '/local-admin', null, array($ck)));
    $r = rp_raw($B, 'POST', '/local-admin', array('t' => $t, 'act' => 'set', 'pw' => $newPw, 'pw2' => $newPw), array('Origin: http://evil.test', $ck));
    assert_eq(403, $r['status'], 'POST with a foreign Origin');
    assert_eq($hash0, (string) rp_v($B, 'SELECT password_hash FROM app_ulogin_users WHERE id = ?', array($emId)), 'refused posts change nothing');

    // 잠김 풀기(열쇠는 그대로 남는다)
    rp_x($B, 'UPDATE app_ulogin_users SET failed_login_count = 7, locked_until = ? WHERE id = ?', array(rp_now(900), $emId));
    $g = rp_raw($B, 'GET', '/local-admin', null, array($ck));
    assert_contains('data-value="suspended">잠김<', $g['body'], 'status badge locked');
    $r = rp_raw($B, 'POST', '/local-admin', array('t' => rp_la_token($g), 'act' => 'unlock'), array('Origin: ' . $self, $ck));
    assert_eq(200, $r['status'], 'unlock');
    assert_eq(array(0, null), array((int) rp_v($B, 'SELECT failed_login_count FROM app_ulogin_users WHERE id = ?', array($emId)),
        rp_v($B, 'SELECT locked_until FROM app_ulogin_users WHERE id = ?', array($emId))), 'unlocked');
    assert_eq(1, (int) rp_v($B, "SELECT COUNT(*) FROM app_ulogin_admin_logs WHERE action = 'emergency.unlock' AND memo = 'local-admin web'"), 'unlock audited');
    assert_true(is_file($kf), 'unlock keeps the key');

    // 9자 → 정책 위반, 10자 → 바꾸기(CLI 와 같은 함수) · 토큰 재사용 거절 · 성공하면 열쇠·쿠키 폐기
    $t = rp_la_token(rp_raw($B, 'GET', '/local-admin', null, array($ck)));
    $r = rp_raw($B, 'POST', '/local-admin', array('t' => $t, 'act' => 'set', 'pw' => 'Qv7#mLp2x', 'pw2' => 'Qv7#mLp2x'), array('Origin: ' . $self, $ck));
    assert_eq(400, $r['status'], '9 chars refused');
    assert_contains('정책 위반', $r['body'], 'policy message');
    assert_true(is_file($kf), 'a refused set keeps the key');
    $t = rp_la_token($r);
    $r = rp_raw($B, 'POST', '/local-admin', array('t' => $t, 'act' => 'set', 'pw' => $newPw, 'pw2' => $newPw), array('Referer: ' . $self . '/local-admin', $ck));
    assert_eq(200, $r['status'], '10 chars accepted (' . substr(strip_tags($r['body']), 0, 300) . ')');
    assert_contains('비밀번호를 바꿨습니다', $r['body'], 'reset message');
    assert_not_contains($newPw, $r['body'], 'response never echoes the value');
    $gone = rp_la_setcookie($r);
    assert_true($gone !== null && preg_match('~^ULOGIN_LA=;~', $gone) === 1 && preg_match('~Max-Age=0(;|$)~', $gone) === 1
        && preg_match('~Path=/local-admin(;|$)~', $gone) === 1, 'success clears the cookie on its path: ' . (string) $gone);
    clearstatcache();
    assert_false(is_file($kf), 'success deletes the key file (server log: ' . substr((string) @file_get_contents($B['base'] . '/php_error.log'), -400) . ')');
    rp_la_assert_guide($r, 'after success');
    $r2 = rp_raw($B, 'POST', '/local-admin', array('t' => $t, 'act' => 'unlock'), array('Origin: ' . $self, $ck));
    assert_eq(403, $r2['status'], 'old cookie after success is refused');
    rp_la_assert_guide(rp_raw($B, 'GET', '/local-admin', null, array($ck)), 'old cookie after success');
    assert_true($hash0 !== (string) rp_v($B, 'SELECT password_hash FROM app_ulogin_users WHERE id = ?', array($emId)), 'hash changed');
    $log = rp_q($B, "SELECT after_json, memo FROM app_ulogin_admin_logs WHERE action = 'emergency.password.reset' ORDER BY id DESC LIMIT 1");
    assert_eq(1, count($log), 'reset audited');
    assert_eq('local-admin web', (string) $log[0]['memo'], 'audit memo marks the web path');
    assert_not_contains($newPw, (string) $log[0]['after_json'], 'audit has no value');
    rp_ok(rp_login($B, 'rp_em', $newPw), 'emergency login with the new password');

    // 6) 만료 — 링크 열쇠 만료 · 세션 만료
    $k2 = rp_la_open($B);
    $rec = json_decode((string) file_get_contents($kf), true);
    $rec['exp'] = time() - 1;
    file_put_contents($kf, json_encode($rec));
    $r = rp_raw($B, 'GET', '/local-admin?k=' . $k2, null, array());
    assert_eq(403, $r['status'], 'expired key -> 403');
    assert_eq(null, rp_la_setcookie($r), 'expired key sets no cookie');
    $k3 = rp_la_open($B);
    assert_true($k3 !== $k2, 'each open makes a new key');
    $c3 = rp_la_session($B, $k3);
    $rec = json_decode((string) file_get_contents($kf), true);
    $rec['exp'] = time() - 1;
    file_put_contents($kf, json_encode($rec));
    rp_la_assert_guide(rp_raw($B, 'GET', '/local-admin', null, array(rp_la_cookie($c3))), 'expired session');
    // 새 open 은 앞 세션을 죽인다
    $c4 = rp_la_session($B, rp_la_open($B));
    rp_la_open($B);
    rp_la_assert_guide(rp_raw($B, 'GET', '/local-admin', null, array(rp_la_cookie($c4))), 'a new open revokes the previous session');
    @unlink($kf);
}

/** v1.10.1 M-1 — consume 은 s2s_ reason 을 거절하고, 사용자가 만든 행은 s2s 한도 합산에 들어가지 않는다 */
function rp_sc_m1(array $B): void
{
    $u3 = $B['fx']['u']['u3'];
    foreach (array('s2s_earn', 'S2S_SPEND', 's2s_anything') as $reason) {
        $r = rp_http($B, 'POST', '/server/api/points/consume.php', array('amount' => 1, 'reason' => $reason), $B['fx']['tok']['u1']);
        rp_err($r, 400, 'VALIDATION_FAILED', 'consume refuses reason ' . $reason);
        assert_eq('reason', $r['json']['error']['field'] ?? null, 'field reason for ' . $reason);
    }
    rp_ok(rp_http($B, 'POST', '/server/api/points/consume.php', array('amount' => 1, 'reason' => 'usage_s2s'), $B['fx']['tok']['u1']), 'other reasons still work');
    // 수정 전 consume 이 만들 수 있던 행(reason s2s_earn, 저장 키가 s2s: 아님)을 svc-o · u3 에 심는다 — 일일 한도 300 중 250
    rp_x($B, 'UPDATE app_ulogin_users SET points = 5000 WHERE id = ?', array($u3['id']));
    rp_x($B, 'INSERT INTO app_ulogin_point_logs (user_id, delta, balance_after, reason, service_id, idempotency_key, created_at)'
        . ' VALUES (?,?,?,?,?,?,?)', array($u3['id'], 250, 5000, 's2s_earn', 'svc-o', 'user-plant-0001', rp_now()));
    rp_x($B, "UPDATE app_ulogin_services SET points_json = ? WHERE service_id = 'svc-o'", array(rp_pcfg()));
    rp_reset_rl($B);
    $usedU = (int) rp_v($B, "SELECT COALESCE(SUM(ABS(delta)),0) FROM app_ulogin_point_logs WHERE service_id = 'svc-o' AND user_id = ?"
        . " AND reason = 's2s_earn' AND idempotency_key LIKE 's2s:%'", array($u3['id']));
    $usedS = (int) rp_v($B, "SELECT COALESCE(SUM(ABS(delta)),0) FROM app_ulogin_point_logs WHERE service_id = 'svc-o'"
        . " AND reason = 's2s_earn' AND idempotency_key LIKE 's2s:%'");
    $bal = rp_ok(rp_s2s($B, 'balance', 'svc-o', array('uuid' => $u3['uuid'])), 'svc-o balance');
    assert_eq(min(300 - $usedU, 500 - $usedS), $bal['limits']['earn_remaining_24h'], 'the planted user row is not in the limit sums');
    $e = rp_s2s($B, 'earn', 'svc-o', array('uuid' => $u3['uuid'], 'amount' => 100, 'idempotency_key' => 'm1-earn-0001'));
    rp_ok($e, 'earn 100 is not blocked by the planted 250');
}

/** v1.10.1 L-3 — 승인 대기 신청의 비밀번호 실패도 잠금(임계 5 · 잠금 15분) */
function rp_sc_pending_lock(array $B): void
{
    rp_mode($B, 'approval');
    rp_ok(rp_register($B, 'lock0001'), 'pending request for lock test', 202);
    $id = (int) rp_v($B, "SELECT id FROM app_ulogin_register_requests WHERE username = 'lock0001'");
    $try = function (string $pw) use ($B) {
        // 로그인 빈도 버킷(login:ip · login:user)만 지운다 — 잠금 카운터(login:pending:)는 남긴다
        rp_x($B, "DELETE FROM app_ulogin_rate_limits WHERE bucket NOT LIKE 'login:pending:%'");
        return rp_http($B, 'POST', '/server/api/auth/login.php', array('username' => 'lock0001', 'password' => $pw));
    };
    rp_err($try(RP_PW), 403, 'AUTH_ACCOUNT_PENDING', 'right password before any failure');
    for ($i = 1; $i <= 5; $i++) {
        rp_err($try('Wrong-pass-' . $i . '000'), 401, 'AUTH_INVALID_CREDENTIALS', 'wrong password #' . $i);
    }
    assert_eq(5, (int) rp_v($B, 'SELECT count FROM app_ulogin_rate_limits WHERE bucket = ?', array('login:pending:' . $id)), 'five failures counted');
    $l = $try(RP_PW);
    rp_err($l, 423, 'AUTH_ACCOUNT_LOCKED', 'locked after 5 failures, even with the right password');
    assert_true(is_int($l['json']['data']['retry_after'] ?? null) && $l['json']['data']['retry_after'] > 0, 'retry_after');
    rp_err($try('Wrong-pass-6000'), 423, 'AUTH_ACCOUNT_LOCKED', 'still locked');
    assert_eq(5, (int) rp_v($B, 'SELECT count FROM app_ulogin_rate_limits WHERE bucket = ?', array('login:pending:' . $id)), 'locked attempts do not verify or count');
    // 잠금 시간이 지나면 풀리고, 맞는 비밀번호는 PENDING + 카운터 삭제
    rp_x($B, 'UPDATE app_ulogin_rate_limits SET expires_at = ? WHERE bucket = ?', array(rp_now(-1), 'login:pending:' . $id));
    rp_err($try(RP_PW), 403, 'AUTH_ACCOUNT_PENDING', 'lock expired');
    assert_eq(0, (int) rp_v($B, 'SELECT COUNT(*) FROM app_ulogin_rate_limits WHERE bucket = ?', array('login:pending:' . $id)), 'right password clears the counter');
    rp_x($B, "DELETE FROM app_ulogin_register_requests WHERE username = 'lock0001'");
    rp_mode($B, 'closed');
}

/** v1.10.1 L-4 — 최근 거부는 사유 없는 일반 429 · 같은 망(/24) 대기 상한 */
function rp_sc_l4(array $B): void
{
    rp_mode($B, 'approval');
    rp_x($B, "DELETE FROM app_ulogin_register_requests WHERE status = 'pending'");
    // 최근 거부된 이메일
    $pdo = rp_pdo($B);
    $pdo->prepare('INSERT INTO app_ulogin_register_requests (receipt_hash, status, username, email, email_domain, ip, created_at, expires_at, decided_at)'
        . ' VALUES (?,?,?,?,?,?,?,?,?)')->execute(array(hash('sha256', 'rej-l4'), 'rejected', 'rejl4001', 'rejl4@corp.test', 'corp.test',
        '203.0.113.77', rp_now(-7200), rp_now(86400), rp_now(-3600)));
    $pdo = null;
    $r = rp_register($B, 'rejl4002', array('email' => 'rejl4@corp.test'));
    rp_err($r, 429, 'RATE_LIMITED', 'recently rejected email is refused');
    assert_eq(array('retry_after'), array_keys(is_array($r['json']['data'] ?? null) ? $r['json']['data'] : array()),
        'refusal looks like a plain rate limit (no reason)');
    assert_not_contains('reject', strtolower($r['body']), 'body says nothing about a rejection');
    // 같은 /24 의 대기 10건(IP 는 서로 다름 — IP 상한 3 은 걸리지 않는다) → 127.0.0.1 의 새 신청은 net_pending_cap
    $pdo = rp_pdo($B);
    $ins = $pdo->prepare('INSERT INTO app_ulogin_register_requests (receipt_hash, status, username, email, email_domain, ip, created_at, expires_at)'
        . ' VALUES (?,?,?,?,?,?,?,?)');
    for ($i = 0; $i < 10; $i++) {
        $ins->execute(array(hash('sha256', 'net' . $i), 'pending', 'netl4' . $i, 'netl4' . $i . '@n.test', 'n.test', '127.0.0.' . (20 + $i), rp_now(), rp_now(86400)));
    }
    $pdo = null;
    rp_err(rp_register($B, 'netl4100'), 429, 'RATE_LIMITED', 'same /24 pending cap', 'net_pending_cap');
    rp_x($B, "UPDATE app_ulogin_register_requests SET ip = '198.51.100.9' WHERE username = 'netl40'");
    rp_ok(rp_register($B, 'netl4101'), 'one below the /24 cap is accepted', 202);
    rp_x($B, "DELETE FROM app_ulogin_register_requests WHERE username LIKE 'netl4%' OR username LIKE 'rejl4%'");
    rp_mode($B, 'closed');
}

// =====================================================================
// 실행
// =====================================================================

$GLOBALS['RPB'] = null;
function rp_main(): array
{
    if ($GLOBALS['RPB'] === null) {
        $GLOBALS['RPB'] = rp_box('main');
    }
    return $GLOBALS['RPB'];
}

t('closed: same as v1.10.0 (D-315) - register_status, register 403, validate', function () {
    rp_sc_closed(rp_main());
});
t('mode matrix 9 cells, allow_register sync, legacy key rule, invalid -> closed, 17th key', function () {
    rp_sc_matrix(rp_main());
});
t('open: 201 + approval_required=false, immediate login', function () {
    rp_sc_open(rp_main());
});
t('approval: 202 receipt, no users row, PENDING login, caps, admin list/detail/approve/reject, csrf, stats', function () {
    rp_sc_approval(rp_main());
});
t('points s2s: auth, target order, earn/spend, idempotency, 4 limits, history isolation, audit, me/points', function () {
    rp_sc_points(rp_main());
});
t('services.points validation + audit, settings bundle carries register_mode and points (idempotent)', function () {
    rp_sc_services(rp_main());
});
t('D-373 admin/emergency password minimum 10 (9 refused, 10 accepted)', function () {
    rp_sc_pw(rp_main());
});
t('D-374 /local-admin: 404 unless loopback addr + loopback Host:port + no proxy headers; csrf/origin; same functions as CLI + M-2 one-time key', function () {
    rp_sc_local(rp_main());
});
t('v1.10.1 M-1: consume refuses s2s_ reasons; user rows never count toward s2s limits', function () {
    rp_sc_m1(rp_main());
});
t('v1.10.1 L-3: failed passwords on a pending request lock it like an account', function () {
    rp_sc_pending_lock(rp_main());
});
t('v1.10.1 L-4: recently rejected -> plain 429 without reason; /24 pending cap', function () {
    rp_sc_l4(rp_main());
});
t('migration 0011 on SQLite: 0010 state -> apply -> verify clean, re-apply no-op, closed stays closed', function () {
    rp_sc_migrate(rp_main());
});

// ---- 변이(설계 130 §5) - 심은 뒤 위 시나리오의 해당 부분이 빨개지는가. 7번(잠금 밖 합산)은 tier1 t_80 이 지킨다
t('mutant R1: min(global, service) -> max', function () {
    rp_mutant('r1', array(array('server/core/auth.php', 'REGISTER_MODE_RANK[$a] <= REGISTER_MODE_RANK[$b] ? $a : $b',
        'REGISTER_MODE_RANK[$a] >= REGISTER_MODE_RANK[$b] ? $a : $b')), 'rp_sc_matrix');
});
t('mutant R2: approval path skipped (users row before approval)', function () {
    rp_mutant('r2', array(array('server/api/auth/register.php', "if (\$mode === 'approval') {", "if (\$mode === 'approval' && false) {")), 'rp_sc_approval');
});
t('mutant P3: s2s access level check removed', function () {
    rp_mutant('p3', array(array('server/core/points.php', "if (access_rank((string) (\$d['level'] ?? 'none')) < access_rank('use')) {", 'if (false) {')),
        'rp_sc_points');
});
t('mutant P4: idempotency compare ignores delta', function () {
    rp_mutant('p4', array(array('server/core/points.php', "                && (int) \$prev['delta'] === \$delta\n", '')), 'rp_sc_points');
});
t('mutant P5: history without service_id filter', function () {
    rp_mutant('p5', array(array('server/api/points/s2s_history.php', "' WHERE user_id = ? AND service_id = ?'", "' WHERE user_id = ? AND ? IS NOT NULL'")),
        'rp_sc_points');
});
t('mutant R6: invalid register_mode -> open', function () {
    rp_mutant('r6', array(array('server/core/auth.php', "treated as closed');\n        return 'closed';", "treated as closed');\n        return 'open';")),
        'rp_sc_matrix');
});
t('mutant D373: admin minimum back to 12', function () {
    rp_mutant('d373', array(array('server/core/pwstrong.php', 'const EM_STRONG_MIN  = 10;', 'const EM_STRONG_MIN  = 12;')), 'rp_sc_pw');
});
t('mutant L374: Host check removed from the server-pc test', function () {
    rp_mutant('l374', array(array('server/core/localonly.php',
        "        if (ulogin_local_host_origin(isset(\$server['HTTP_HOST']) ? (string) \$server['HTTP_HOST'] : null) === null) {
            return false;
        }
",
        '')), 'rp_sc_local');
});
t('mutant L374b: proxy header check removed from the server-pc test', function () {
    rp_mutant('l374b', array(array('server/core/localonly.php', "            if (array_key_exists(\$k, \$server)) {
                return false;
            }
", '')),
        'rp_sc_local');
});

// ---- v1.10.1 보안 판정 D-375 변이 — 심은 뒤 해당 시나리오가 빨개지는가
t('mutant M1a: consume accepts s2s_ reasons again', function () {
    rp_mutant('m1a', array(array('server/api/points/consume.php', "if (strncasecmp(\$reason, 's2s_', 4) === 0) {", 'if (false) {')), 'rp_sc_m1');
});
t('mutant M1b: s2s limit sums count every row with the reason (no s2s: key filter)', function () {
    rp_mutant('m1b', array(array('server/core/points.php',
        "' WHERE user_id = ? AND service_id = ? AND reason = ? AND created_at >= ? AND idempotency_key LIKE ?',",
        "' WHERE user_id = ? AND service_id = ? AND reason = ? AND created_at >= ? AND ? IS NOT NULL',")), 'rp_sc_m1');
});
t('mutant M2a: POST without the key cookie is processed', function () {
    rp_mutant('m2a', array(array('server/local_admin.php', 'if (!$authed) {   // LA-AUTH', 'if (false) {   // LA-AUTH')), 'rp_sc_local');
});
t('mutant M2b: link key compare removed (any 64-hex key works)', function () {
    rp_mutant('m2b', array(array('server/core/localkey.php', "|| !is_string(\$rec['mac'] ?? null) || !hash_equals(\$rec['mac'], la_mac(\$k))) {   // KEY-CHECK",
        ") {   // KEY-CHECK")), 'rp_sc_local');
});
t('mutant M2c: link key expiry ignored', function () {
    rp_mutant('m2c', array(array('server/core/localkey.php', "|| !is_int(\$rec['exp'] ?? null) || \$rec['exp'] <= time()\n", "\n")), 'rp_sc_local');
});
t('mutant M2d: link key is not consumed (stays a link after the exchange)', function () {
    rp_mutant('m2d', array(array('server/core/localkey.php', "return [['v' => 1, 'stage' => 'session', 'mac' => la_mac(\$cookie), 'exp' => time() + LA_KEY_TTL], \$cookie];",
        "return [null, \$cookie];")), 'rp_sc_local');
});
t('mutant M2e: cookie Path widened to /', function () {
    rp_mutant('m2e', array(array('server/core/localkey.php', "const LA_COOKIE_PATH = '/local-admin';", "const LA_COOKIE_PATH = '/';")), 'rp_sc_local');
});
t('mutant M2f: success keeps the key', function () {
    rp_mutant('m2f', array(array('server/local_admin.php', "                        la_key_revoke();\n", '')), 'rp_sc_local');
});
t('mutant L1: Cf-* prefix check removed from the server-pc test', function () {
    rp_mutant('l1', array(array('server/core/localonly.php', "if (strncasecmp((string) \$sk, \$pre, strlen(\$pre)) === 0) {   // PROXY-PREFIX",
        "if (false) {   // PROXY-PREFIX")), 'rp_sc_local');
});
t('mutant L3: failed pending passwords are not counted', function () {
    rp_mutant('l3', array(array('server/api/auth/login.php', "        pending_register_failure(\$pend['id']);   // PENDING-FAIL-COUNT\n", '')), 'rp_sc_pending_lock');
});
t('mutant L4a: /24 pending cap removed', function () {
    rp_mutant('l4a', array(array('server/core/regreq.php', 'if (regreq_net_pending_count($ip, $now) >= REGREQ_NET_PENDING_CAP) {   // NET-CAP',
        'if (false) {   // NET-CAP')), 'rp_sc_l4');
});
t('mutant L4b: recently_rejected reason exposed again', function () {
    rp_mutant('l4b', array(array('server/core/regreq.php', "throw regreq_generic_refusal('recently_rejected');",
        "throw new AppError('RATE_LIMITED', 429, ['reason' => 'recently_rejected']);")), 'rp_sc_l4');
});
