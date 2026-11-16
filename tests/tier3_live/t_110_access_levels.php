<?php
declare(strict_types=1);

/**
 * 티어 3 - v1.10.0 접근 단계 · 그룹 · 계정 찾기 · 데이터 독립 · 비상 관리자 계정 · 사용자 지정 scheme (설계 127 §7 · D-367 · D-369)
 *
 * 사본 서버(php -S, 빈 포트) + 임시 SQLite. 운영 DB·8080·3306 무접촉(접두 가드 ulogin_test_av_), 서버는 자기 PID 만 taskkill /T.
 * 하네스는 t_100_emergency.php 의 박스와 같다(접두만 다르다).
 * 덮는 것: T3-1 ~ T3-11 · D-369 4건 · 변이 7개(deny 우선 · 기간 · level_aware · bool view · user_search 거름 · 비상 SSO · owner data)
 * MySQL 쪽 0010 은 이 파일이 아니라 t_20_migration_paths(dbport 테스트 인스턴스가 있을 때만)가 덮는다.
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

const AV_PW = 'pf-Test-pass-9183';
const AV_EM_PW = 'Em-Strong-Pass-7319!q';
$GLOBALS['AV'] = array('boxes' => array(), 'registered' => false, 'p95' => null);

function av_rm(string $p): void
{
    if (strpos(h_norm($p), '/ulogin_test_av_') === false) {
        return;
    }
    av_rm_inner($p);
}

function av_rm_inner(string $p): void
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
            av_rm_inner($p . '/' . $n);
        }
    }
    @rmdir($p);
}

function av_copy(string $src, string $dst): int
{
    @mkdir($dst, 0777, true);
    $n = 0;
    foreach (scandir($src) as $e) {
        if ($e === '.' || $e === '..' || $e === 'config.php' || $e === '.install.lock' || $e === '.installed') {
            continue;
        }
        if (is_dir($src . '/' . $e)) {
            $n += av_copy($src . '/' . $e, $dst . '/' . $e);
        } else {
            copy($src . '/' . $e, $dst . '/' . $e);
            $n++;
        }
    }
    return $n;
}

function av_teardown(): void
{
    foreach ($GLOBALS['AV']['boxes'] as $b) {
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
    foreach ($GLOBALS['AV']['boxes'] as $b) {
        av_rm($b['base']);
    }
    $GLOBALS['AV']['boxes'] = array();
}

function av_ini(): string
{
    $ini = php_ini_loaded_file();
    if (!is_string($ini) || $ini === '' || !is_file($ini)) {
        skip('runner has no php.ini (-n?) — children would lack pdo_sqlite; run with -c <ini>');
    }
    return $ini;
}

function av_free_port(): int
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

function av_set_define(string $src, string $name, string $phpValue): string
{
    $re = '/^define\(\'' . preg_quote($name, '/') . '\',\s*[^\n]*?\);/m';
    $n = 0;
    $out = preg_replace($re, 'define(\'' . $name . '\', ' . $phpValue . ');', $src, 1, $n);
    if ($n !== 1 || !is_string($out)) {
        fail('HARNESS-FAIL config.sample.php: define(' . $name . ') not found exactly once');
    }
    return $out;
}

function av_box(string $key, array $mut = array()): array
{
    require_ext('pdo_sqlite');
    $ini = av_ini();
    if (!$GLOBALS['AV']['registered']) {
        $GLOBALS['AV']['registered'] = true;
        register_shutdown_function('av_teardown');
    }
    $base = h_norm(sys_get_temp_dir()) . '/ulogin_test_av_' . getmypid() . '_' . bin2hex(random_bytes(3)) . '_' . $key;
    $root = $base . '/root';
    $n  = av_copy(h_root() . '/server', $root . '/server');
    $n += av_copy(h_root() . '/public', $root . '/public');
    @mkdir($root . '/tools', 0777, true);
    copy(h_root() . '/tools/router-ulogin.php', $root . '/tools/router-ulogin.php');
    $B = array('base' => $base, 'root' => $root, 'key' => $key, 'pid' => 0, 'proc' => null, 'port' => 0);
    $GLOBALS['AV']['boxes'][] = &$B;
    if ($n < 50 || !is_file($root . '/server/api/platform/docs.php')) {
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
    $db = $base . '/data/ulogin_test_pf.sqlite';
    $B['db'] = $db;
    $pdo = new PDO('sqlite:' . $db, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    mig_sqlite_create_schema($pdo, $root . '/server/schema.sql', 'app_ulogin_', true);
    $pdo = null;
    $port = av_free_port();
    $B['port'] = $port;
    $cfg = (string) file_get_contents(h_root() . '/server/config.sample.php');
    $cfg = av_set_define($cfg, 'DB_DRIVER', "'sqlite'");
    $cfg = av_set_define($cfg, 'DB_SQLITE_PATH', var_export($db, true));
    $B['enc'] = bin2hex(random_bytes(32));
    $cfg = av_set_define($cfg, 'SECRET_ENC_KEY', "'" . $B['enc'] . "'");
    $cfg = av_set_define($cfg, 'APP_KEY', "'" . bin2hex(random_bytes(32)) . "'");
    $cfg = av_set_define($cfg, 'FORCE_HTTPS', 'false');
    $cfg = av_set_define($cfg, 'COOKIE_SECURE', 'false');
    $cfg = av_set_define($cfg, 'BASE_URL', "'http://127.0.0.1:" . $port . "'");
    // 무음 SSO 는 https 판정이 필요하다 — 루프백을 신뢰 프록시로 두고 그 한 요청만 X-Forwarded-Proto 를 싣는다
    $cfg = av_set_define($cfg, 'TRUST_PROXY', 'true');
    $cfg = av_set_define($cfg, 'TRUSTED_PROXIES', "['127.0.0.1']");
    file_put_contents($root . '/server/config.php', $cfg);
    $B['fx'] = av_seed($B);

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
        $r = av_http($B, 'GET', '/version.txt');
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

function av_pdo(array $B): PDO
{
    $pdo = new PDO('sqlite:' . $B['db'], null, null, array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
    $pdo->exec('PRAGMA busy_timeout = 8000');
    return $pdo;
}

function av_q(array $B, string $sql, array $p = array()): array
{
    $pdo = av_pdo($B);
    $st = $pdo->prepare($sql);
    $st->execute($p);
    $rows = $st->fetchAll();
    $pdo = null;
    return $rows;
}

function av_x(array $B, string $sql, array $p = array()): int
{
    $pdo = av_pdo($B);
    $st = $pdo->prepare($sql);
    $st->execute($p);
    $n = $st->rowCount();
    $pdo = null;
    return $n;
}

function av_now(int $off = 0): string
{
    return gmdate('Y-m-d H:i:s', time() + $off);
}

function av_iso(int $off = 0): string
{
    return gmdate('Y-m-d\TH:i:s\Z', time() + $off);
}

/** 원시 HTTP/1.0. $hdr = ['Name: v', …]. */
function av_http(array $B, string $method, string $path, $body = null, ?string $bearer = null, array $hdr = array()): array
{
    $s = @fsockopen('127.0.0.1', (int) $B['port'], $errno, $errstr, 5.0);
    if ($s === false) {
        return array('status' => 0, 'body' => '', 'json' => null, 'ms' => 0.0, 'head' => '');
    }
    stream_set_timeout($s, 30);
    $json = $body === null ? null : (is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $req = $method . ' ' . $path . " HTTP/1.0\r\nHost: 127.0.0.1:" . $B['port'] . "\r\nConnection: close\r\n";
    if ($bearer !== null) {
        $req .= 'Authorization: Bearer ' . $bearer . "\r\n";
    }
    foreach ($hdr as $h) {
        $req .= $h . "\r\n";
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
    return array('status' => $status, 'body' => $bodyS, 'json' => is_array($j) ? $j : null, 'ms' => $ms, 'head' => $head);
}

function av_code(array $r): string
{
    return is_array($r['json']) && isset($r['json']['error']['code']) ? (string) $r['json']['error']['code'] : '';
}

function av_field(array $r): ?string
{
    return is_array($r['json']) && isset($r['json']['error']['field']) ? (string) $r['json']['error']['field'] : null;
}

function av_ok(array $r, string $what): array
{
    if ($r['status'] !== 200 || !is_array($r['json']) || $r['json']['ok'] !== true) {
        fail($what . ': expected 200 ok, got ' . $r['status'] . ' ' . substr($r['body'], 0, 400));
    }
    return is_array($r['json']['data']) ? $r['json']['data'] : array();
}

function av_err(array $r, int $status, string $code, string $what, ?string $field = null): void
{
    if ($r['status'] !== $status || av_code($r) !== $code) {
        fail($what . ': expected ' . $status . ' ' . $code . ', got ' . $r['status'] . ' ' . substr($r['body'], 0, 400));
    }
    if ($field !== null && av_field($r) !== $field) {
        fail($what . ': expected field ' . $field . ', got ' . var_export(av_field($r), true));
    }
}

function av_token(PDO $pdo, int $uid, ?string $sid, string $type = 'token', ?string $csrf = null): string
{
    $tok = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO app_ulogin_sessions (session_id, user_id, service_id, auth_type, access_token_hash, csrf_token_hash,'
        . ' issued_at, expires_at, last_seen_at, ip, user_agent) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute(array(bin2hex(random_bytes(32)), $uid, $sid, $type, hash('sha256', $tok), $csrf === null ? null : hash('sha256', $csrf),
            av_now(-5), av_now(3600), av_now(-5), '127.0.0.1', 'pf'));
    return $tok;
}

function av_tok(array $B, int $uid, ?string $sid): string
{
    $pdo = av_pdo($B);
    $t = av_token($pdo, $uid, $sid);
    $pdo = null;
    return $t;
}

function av_reset_rl(array $B): void
{
    av_x($B, 'DELETE FROM app_ulogin_rate_limits');
}

function av_s2s(array $B, string $path, string $sid, array $body): array
{
    return av_http($B, 'POST', $path, array_merge(array('service_id' => $sid, 'service_secret' => $B['fx']['sec'][$sid]), $body));
}

function av_ev(array $over = array()): array
{
    return array_merge(array('event_id' => gen_uuid4(), 'ts' => av_iso(-10), 'level' => 'info', 'event' => 'job.done'), $over);
}

function av_aev(array $over = array()): array
{
    return array_merge(array('event_id' => gen_uuid4(), 'ts' => av_iso(-10), 'actor' => array('type' => 'admin', 'local_user' => 'boss'),
        'action' => 'user.role.update', 'target' => array('type' => 'user', 'id' => 'hong'), 'result' => 'success',
        'changes' => array('role' => array('before' => 'user', 'after' => 'admin'))), $over);
}

function av_admin(array $B, string $who, string $path, array $body): array
{
    return av_http($B, 'POST', $path, $body, $B['fx']['tok'][$who]);
}

function av_mutant(string $key, array $mut, callable $scenario): void
{
    $B = av_box('mut_' . $key, $mut);
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

/** 박스 안에서 PHP 를 돌린다(감사 정리·검증처럼 HTTP 경로가 없는 것). */
function av_cli(array $B, string $code): array
{
    $f = $B['root'] . '/server/tools/_pf_' . bin2hex(random_bytes(3)) . '.php';
    file_put_contents($f, "<?php\ndeclare(strict_types=1);\nrequire __DIR__ . '/../core/bootstrap.php';\n" . $code);
    $rc = 0;
    $out = h_run(h_q(h_php_bin()) . ' -c ' . h_q(av_ini()) . ' ' . h_q($f), $rc, 60.0);
    @unlink($f);
    return array($rc, $out);
}


// =====================================================================
// v1.10.0 — 씨앗 · 헬퍼
// =====================================================================

/** 서비스 4개(svc-a all · svc-v granted+aware+시크릿 · svc-u granted · svc-g granted) + 계정 + 토큰. */
function av_seed(array $B): array
{
    $pdo = av_pdo($B);
    $now = av_now();
    $hash = password_hash(AV_PW, PASSWORD_DEFAULT);
    $fx = array('u' => array(), 'tok' => array(), 'sec' => array());
    $ins = $pdo->prepare('INSERT INTO app_ulogin_services (service_id, service_name, service_secret_hash, is_confidential,'
        . ' redirect_whitelist, allowed_origins, auto_join, status, can_list_users, access_rule, level_aware, created_at, updated_at)'
        . ' VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach (array('svc-a' => array('all', 0), 'svc-v' => array('granted', 1), 'svc-u' => array('granted', 0),
                   'svc-g' => array('granted', 0), 'svc-s' => array('granted', 0)) as $sid => $cfg) {
        $sec = 'sec_' . $sid . '_' . bin2hex(random_bytes(16));
        $fx['sec'][$sid] = $sec;
        $ins->execute(array($sid, 'Svc ' . $sid, password_hash($sec, PASSWORD_DEFAULT), 1, json_encode(array('https://app.test/cb')),
            json_encode(array('http://app.test')), 1, 'active', 0, $cfg[0], $cfg[1], $now, $now));
    }
    // svc-v 시크릿 1개 — use 이상에게만 나가야 한다(K5)
    $iv = random_bytes(16);
    $ct = openssl_encrypt('k-123', 'aes-256-cbc', hex2bin($B['enc']), OPENSSL_RAW_DATA, $iv);
    $pdo->prepare('INSERT INTO app_ulogin_service_secrets (service_id, secret_key, secret_value_enc, value_type, status, sort_order,'
        . ' created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)')
        ->execute(array('svc-v', 'ai_key', base64_encode($iv . $ct), 'string', 'active', 0, $now, $now));
    $insU = $pdo->prepare('INSERT INTO app_ulogin_users (uuid, username, email, password_hash, name, phone, status, account_level, role,'
        . ' billing_tier, last_login_ip, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach (array('sa' => 'superadmin', 'ad' => 'admin', 'mg' => 'manager', 'u1' => 'user', 'u2' => 'user', 'u3' => 'user') as $k => $role) {
        $uuid = gen_uuid4();
        $insU->execute(array($uuid, 'av_' . $k, 'av_' . $k . '@test.invalid', $hash, $k === 'u1' ? '홍길동' : null, '010-1234-5678', 'active',
            'basic', $role, 'free', '203.0.113.77', $now, $now));
        $fx['u'][$k] = array('id' => (int) $pdo->lastInsertId(), 'uuid' => $uuid, 'username' => 'av_' . $k);
    }
    foreach (array('sa', 'ad', 'mg') as $k) {
        $fx['tok'][$k] = av_token($pdo, $fx['u'][$k]['id'], null);
    }
    $pdo = null;
    return $fx;
}

function av_pkce(): array
{
    $v = rtrim(strtr(base64_encode(random_bytes(40)), '+/', '-_'), '=');
    return array($v, rtrim(strtr(base64_encode(hash('sha256', $v, true)), '+/', '-_'), '='));
}

/** authorize(비밀번호) → exchange. 반환: [authorize 응답, exchange 응답] */
function av_sso(array $B, string $user, string $sid, string $redirect = 'https://app.test/cb', ?string $secret = null): array
{
    list($v, $c) = av_pkce();
    $a = av_http($B, 'POST', '/server/api/sso/authorize.php', array('username' => $user, 'password' => AV_PW, 'service_id' => $sid,
        'redirect_uri' => $redirect, 'code_challenge' => $c, 'code_challenge_method' => 'S256', 'state' => 'st1'));
    if ($a['status'] !== 200) {
        return array($a, null);
    }
    $body = array('code' => $a['json']['data']['code'], 'service_id' => $sid, 'redirect_uri' => $redirect, 'code_verifier' => $v);
    if ($secret !== null || isset($B['fx']['sec'][$sid])) {
        $body['service_secret'] = $secret !== null ? $secret : $B['fx']['sec'][$sid];
    }
    return array($a, av_http($B, 'POST', '/server/api/auth/exchange.php', $body));
}

function av_post(array $B, string $who, string $path, array $body): array
{
    return av_http($B, 'POST', $path, $body, $B['fx']['tok'][$who]);
}

function av_get(array $B, string $who, string $pathQuery): array
{
    return av_http($B, 'GET', $pathQuery, null, $B['fx']['tok'][$who]);
}

function av_override(array $B, string $uk, string $sid, array $over): array
{
    return av_post($B, 'sa', '/server/api/admin/service_access.php',
        array_merge(array('action' => 'set_override', 'uuid' => $B['fx']['u'][$uk]['uuid'], 'service_id' => $sid), $over));
}

// =====================================================================
// 시나리오
// =====================================================================

/** T3-1 · T3-2 · T3-3 · T3-4 · T3-6 + bool 맵(view=false) · deny 우선 */
function av_sc_levels(array $B): void
{
    av_reset_rl($B);
    // T3-1 view + aware → exchange 200 · access_level view · payload {}
    av_ok(av_override($B, 'u1', 'svc-v', array('grant' => 'allow', 'level' => 'view', 'note' => 'read only')), 'override view');
    list($a, $x) = av_sso($B, 'av_u1', 'svc-v');
    av_ok($a, 'authorize view+aware');
    $d = av_ok($x, 'exchange view+aware');
    assert_eq('view', $d['access_level'] ?? null, 'access_level view');
    assert_contains('"payload":{}', $x['body'], 'K5 view gets no secrets');
    assert_eq('view', $d['access']['svc-v']['level'] ?? null, 'access map has svc-v');
    assert_eq('use', $d['access']['svc-a']['level'] ?? null, 'access map has svc-a(rule all)');
    assert_eq(null, $d['access']['svc-a']['scopes'], 'S6 other service scopes null');
    assert_false(isset($d['access']['svc-u']), 'none services are omitted');
    $viewTok = (string) $d['access_token'];
    $s = av_ok(av_http($B, 'GET', '/server/api/auth/session.php', null, $viewTok), 'session view');
    assert_eq('view', $s['access_level'], 'session access_level');
    // userinfo: bool 은 view=false, access 는 view
    $ui = av_ok(av_http($B, 'POST', '/server/api/sso/userinfo.php', array('service_id' => 'svc-a', 'service_secret' => $B['fx']['sec']['svc-a'],
        'uuid' => $B['fx']['u']['u1']['uuid'])), 'userinfo');
    assert_true($ui['services']['svc-v'] === false, 'bool map: view is false (BOOL-VIEW)');
    assert_true($ui['services']['svc-a'] === true, 'bool map: use is true');
    assert_eq('view', $ui['access']['svc-v']['level'], 'userinfo access view');
    // use 로 올리면 시크릿이 나온다
    av_reset_rl($B);
    av_ok(av_override($B, 'u1', 'svc-v', array('grant' => 'allow', 'level' => 'use')), 'override use');
    list($a, $x) = av_sso($B, 'av_u1', 'svc-v');
    $d = av_ok($x, 'exchange use');
    assert_eq('k-123', $d['payload']['ai_key'] ?? null, 'use gets the secret');
    // T3-2 view + unaware → 403
    av_ok(av_override($B, 'u1', 'svc-u', array('grant' => 'allow', 'level' => 'view')), 'override view unaware');
    list($a, $x) = av_sso($B, 'av_u1', 'svc-u');
    av_err($a, 403, 'SERVICE_ACCESS_DENIED', 'K4 view on unaware service');
    // T3-3 revoke → 세션 회수 + 다음 session 403
    $tok = (string) $d['access_token'];
    $r = av_ok(av_post($B, 'sa', '/server/api/admin/service_access.php', array('action' => 'revoke', 'uuid' => $B['fx']['u']['u1']['uuid'],
        'service_id' => 'svc-v', 'note' => 'left team')), 'revoke');
    assert_true($r['revoked_sessions'] >= 1, 'revoke counted sessions (' . $r['revoked_sessions'] . ')');
    assert_eq('none', $r['effective']['level'], 'revoked level none');
    assert_true(av_http($B, 'GET', '/server/api/auth/session.php', null, $tok)['status'] === 401, 'revoked session is dead');
    av_err(av_http($B, 'GET', '/server/api/auth/session.php', null, av_tok($B, $B['fx']['u']['u1']['id'], 'svc-v')), 403,
        'SERVICE_ACCESS_DENIED', 'fresh token after revoke');
    // deny 우선 — rule all 서비스에서 deny 면 403
    av_ok(av_override($B, 'u2', 'svc-a', array('grant' => 'deny')), 'deny on all');
    av_err(av_http($B, 'GET', '/server/api/auth/session.php', null, av_tok($B, $B['fx']['u']['u2']['id'], 'svc-a')), 403,
        'SERVICE_ACCESS_DENIED', 'S2 deny beats rule all (DENY-FIRST)');
    // T3-4 ends_at 과거 → session 403
    av_ok(av_override($B, 'u3', 'svc-g', array('grant' => 'allow', 'level' => 'use')), 'allow u3');
    $t3 = av_tok($B, $B['fx']['u']['u3']['id'], 'svc-g');
    av_ok(av_http($B, 'GET', '/server/api/auth/session.php', null, $t3), 'u3 in window');
    av_x($B, 'UPDATE app_ulogin_user_services SET access_starts_at = ?, access_ends_at = ? WHERE user_id = ? AND service_id = ?',
        array(av_now(-7200), av_now(-60), $B['fx']['u']['u3']['id'], 'svc-g'));
    av_err(av_http($B, 'GET', '/server/api/auth/session.php', null, $t3), 403, 'SERVICE_ACCESS_DENIED', 'T3-4 expired window (PERIOD)');
    // 검증: 기간 역순 · allow 아닌 level · 비상 계정 대상은 아래 비상 시나리오
    av_err(av_override($B, 'u3', 'svc-g', array('grant' => 'allow', 'starts_at' => av_iso(100), 'ends_at' => av_iso(50))), 400,
        'VALIDATION_FAILED', 'ends before starts', 'ends_at');
    av_err(av_override($B, 'u3', 'svc-g', array('grant' => 'deny', 'level' => 'view')), 400, 'VALIDATION_FAILED', 'level without allow', 'level');
    // 쓰기는 superadmin 만 · 서비스 토큰 403
    av_err(av_post($B, 'ad', '/server/api/admin/service_access.php', array('action' => 'revoke', 'uuid' => $B['fx']['u']['u3']['uuid'],
        'service_id' => 'svc-g')), 403, 'FORBIDDEN', 'admin cannot write');
    // T3-6 행렬 셀 = 단건 판정
    $m = av_ok(av_get($B, 'ad', '/server/api/admin/access_matrix.php?per_page=50'), 'matrix');
    assert_eq(array('services', 'items', 'total', 'page', 'per_page'), array_keys($m), 'matrix keys');
    $checked = 0;
    foreach ($m['items'] as $it) {
        if (!in_array($it['username'], array('av_u1', 'av_u2', 'av_u3'), true)) {
            continue;
        }
        $one = av_ok(av_get($B, 'ad', '/server/api/admin/service_access.php?uuid=' . $it['uuid']), 'single ' . $it['username']);
        foreach ($one['systems'] as $sy) {
            $c = $it['cells'][$sy['service_id']];
            assert_eq(array($sy['level'], $sy['source'], $sy['until']), array($c['level'], $c['source'], $c['until']),
                'matrix cell == single for ' . $it['username'] . '/' . $sy['service_id']);
            $checked++;
        }
    }
    assert_true($checked >= 15, 'HARNESS-FAIL matrix compared only ' . $checked . ' cells');
}

/** T3-5 그룹 delete·disable → 영향 계정 회수 */
function av_sc_groups(array $B): void
{
    av_reset_rl($B);
    $g = av_ok(av_post($B, 'sa', '/server/api/admin/access_groups.php', array('action' => 'create', 'group_key' => 'team.a', 'name' => '팀 A')),
        'create group');
    assert_eq('team.a', $g['group']['group_key'], 'group key');
    av_err(av_post($B, 'sa', '/server/api/admin/access_groups.php', array('action' => 'create', 'group_key' => 'team.a', 'name' => 'x')), 409,
        'CONFLICT', 'duplicate group key');
    av_ok(av_post($B, 'sa', '/server/api/admin/access_groups.php', array('action' => 'set_service', 'group_key' => 'team.a',
        'service_id' => 'svc-g', 'level' => 'use', 'scopes' => null)), 'group service use');
    $add = av_ok(av_post($B, 'sa', '/server/api/admin/access_groups.php', array('action' => 'add_members', 'group_key' => 'team.a',
        'uuids' => array($B['fx']['u']['u1']['uuid'], $B['fx']['u']['u2']['uuid'], '00000000-0000-4000-8000-000000000000'))), 'add members');
    assert_eq(2, $add['added'], 'added 2');
    assert_eq(array(array('uuid' => '00000000-0000-4000-8000-000000000000', 'reason' => 'not_found')), $add['skipped'], 'skipped unknown');
    $t1 = av_tok($B, $B['fx']['u']['u1']['id'], 'svc-g');
    $t2 = av_tok($B, $B['fx']['u']['u2']['id'], 'svc-g');
    $s = av_ok(av_http($B, 'GET', '/server/api/auth/session.php', null, $t1), 'u1 via group');
    assert_eq('use', $s['access_level'], 'group level');
    $one = av_ok(av_get($B, 'ad', '/server/api/admin/service_access.php?uuid=' . $B['fx']['u']['u1']['uuid']), 'single');
    foreach ($one['systems'] as $sy) {
        if ($sy['service_id'] === 'svc-g') {
            assert_eq(array('use', 'group:team.a', array('team.a')), array($sy['level'], $sy['source'], $sy['groups']), 'source group');
        }
    }
    $l = av_ok(av_get($B, 'ad', '/server/api/admin/access_groups.php'), 'list');
    assert_eq(2, $l['items'][0]['member_count'], 'member_count');
    $up = av_ok(av_post($B, 'sa', '/server/api/admin/access_groups.php', array('action' => 'update', 'group_key' => 'team.a',
        'status' => 'disabled')), 'disable');
    assert_eq(2, $up['affected']['revoked_sessions'], 'disable revoked both sessions');
    assert_eq(2, $up['affected']['users'], 'disable affected 2 users');
    av_ok(av_post($B, 'sa', '/server/api/admin/access_groups.php', array('action' => 'update', 'group_key' => 'team.a', 'status' => 'active')),
        'enable');
    $t2b = av_tok($B, $B['fx']['u']['u2']['id'], 'svc-g');
    av_ok(av_http($B, 'GET', '/server/api/auth/session.php', null, $t2b), 'u2 back');
    av_err(av_post($B, 'sa', '/server/api/admin/access_groups.php', array('action' => 'delete', 'group_key' => 'team.a', 'confirm' => 'x')),
        400, 'VALIDATION_FAILED', 'delete confirm', 'confirm');
    $del = av_ok(av_post($B, 'sa', '/server/api/admin/access_groups.php', array('action' => 'delete', 'group_key' => 'team.a',
        'confirm' => 'team.a')), 'delete');
    assert_true($del['deleted'] === true && $del['affected']['revoked_sessions'] >= 1, 'delete revoked');
    av_err(av_http($B, 'GET', '/server/api/auth/session.php', null, av_tok($B, $B['fx']['u']['u2']['id'], 'svc-g')), 403,
        'SERVICE_ACCESS_DENIED', 'after delete');
    $aud = av_q($B, "SELECT action FROM app_ulogin_plat_audit WHERE action LIKE 'access.group%' ORDER BY id");
    $acts = array_values(array_unique(array_column($aud, 'action')));
    sort($acts);
    assert_eq(array('access.group.create', 'access.group.delete', 'access.group.members.add', 'access.group.service.set',
        'access.group.update'), $acts, 'group audit actions in the chain');
    // 서비스 토큰(H186-1) — 관리 API 403
    av_err(av_http($B, 'GET', '/server/api/admin/access_groups.php', null, av_tok($B, $B['fx']['u']['sa']['id'], 'svc-a')), 403,
        'FORBIDDEN', 'service token cannot read admin API');
}

/** T3-7 user_search */
function av_sc_search(array $B): void
{
    av_reset_rl($B);
    $pdo = av_pdo($B);
    $now = av_now();
    $insU = $pdo->prepare('INSERT INTO app_ulogin_users (uuid, username, email, password_hash, name, status, role, created_at, updated_at)'
        . ' VALUES (?,?,?,?,?,?,?,?,?)');
    $insUs = $pdo->prepare('INSERT INTO app_ulogin_user_services (user_id, service_id, status, joined_at, access_grant) VALUES (?,?,?,?,?)');
    for ($i = 0; $i < 21; $i++) {
        $insU->execute(array(gen_uuid4(), 'kim' . $i, 'kim' . $i . '@t.invalid', 'x', '김민수' . sprintf('%02d', $i), 'active', 'user', $now, $now));
        $insUs->execute(array((int) $pdo->lastInsertId(), 'svc-s', 'active', $now, 'allow'));
    }
    $insU->execute(array(gen_uuid4(), 'kimx', 'kimx@t.invalid', 'x', '김민준', 'active', 'user', $now, $now));   // svc-s 권한 없음
    $insU->execute(array(gen_uuid4(), 'kimz', 'kimz@t.invalid', 'x', '김민호', 'suspended', 'user', $now, $now));
    $pdo = null;
    $call = function (array $b) use ($B) {
        return av_http($B, 'POST', '/server/api/sso/user_search.php', array_merge(array('service_id' => 'svc-s',
            'service_secret' => $B['fx']['sec']['svc-s']), $b));
    };
    $d = av_ok($call(array('q' => '김')), '1 char');
    assert_eq(array(), $d['items'], '1 char matches username exactly only');
    $d = av_ok($call(array('q' => '김민', 'limit' => 20)), 'prefix');
    assert_eq(20, count($d['items']), '21 matches -> 20');
    assert_true($d['truncated'] === true, 'truncated');
    assert_eq(array('uuid', 'username', 'name'), array_keys($d['items'][0]), 'item has 3 fields only');
    $names = array_column($d['items'], 'name');
    assert_false(in_array('김민준', $names, true), 'no-permission account excluded (SEARCH-LEVEL)');
    assert_false(in_array('김민호', $names, true), 'suspended excluded');
    $d = av_ok($call(array('q' => '김민준')), 'no permission exact name');
    assert_eq(array(), $d['items'], 'no-permission account never appears');
    $d = av_ok($call(array('q' => 'KIM3')), 'username exact (case)');
    assert_eq(array('kim3'), array_column($d['items'], 'username'), 'username exact match');
    av_err($call(array('q' => str_repeat('가', 51))), 400, 'VALIDATION_FAILED', 'q too long', 'q');
    av_err($call(array('q' => "a\x01b")), 400, 'VALIDATION_FAILED', 'control char', 'q');
    $aud = av_q($B, "SELECT reason, changes_json FROM app_ulogin_plat_audit WHERE action = 'sso.user_search' ORDER BY id");
    assert_eq(4, count($aud), 'audited every authenticated call (400 before auth is not audited)');
    assert_not_contains('김민', (string) json_encode($aud, JSON_UNESCAPED_UNICODE), 'search text never stored');
    assert_contains('mode=prefix', (string) $aud[1]['reason'], 'reason has the mode');
    // 31회째 429 (서비스 30/60) — 지금까지 성공 호출 수를 센다
    $n = count($aud);
    $last = null;
    for ($i = $n; $i < 31; $i++) {
        $last = $call(array('q' => 'kim1'));
    }
    av_err($last === null ? $call(array('q' => 'kim1')) : $last, 429, 'RATE_LIMITED', '31st call');
    // 감사 실패 주입 → 500
    av_reset_rl($B);
    av_x($B, 'DROP TABLE app_ulogin_plat_audit');
    $r = $call(array('q' => 'kim1'));
    assert_eq(500, $r['status'], 'audit failure -> 500 (' . substr($r['body'], 0, 120) . ')');
}

/** T3-8 마스킹 · T3-9 owner 문서 */
function av_sc_mask(array $B): void
{
    av_reset_rl($B);
    av_x($B, 'INSERT INTO app_ulogin_login_logs (user_id, username_input, service_id, result, ip, user_agent, created_at) VALUES (?,?,?,?,?,?,?)',
        array(null, 'Secr3tpw', null, 'fail_credential', '203.0.113.77', 'Mozilla/5.0 (Windows NT 10.0) Chrome/120', av_now()));
    $who = array('mg' => array('203.0.113.x', 'a***@test.invalid'), 'ad' => array('203.0.113.x', 'av_u1@test.invalid'),
                 'sa' => array('203.0.113.77', 'av_u1@test.invalid'));
    foreach ($who as $k => $want) {
        $l = av_ok(av_get($B, $k, '/server/api/admin/logs.php?type=login&per_page=5'), 'logs as ' . $k);
        $row = $l['items'][0];
        assert_eq('(없는 아이디 8자)', $row['username_input'], 'unknown-id input hidden from ' . $k);
        assert_eq($want[0], $row['ip'], 'login ip as ' . $k);
        $d = av_ok(av_get($B, $k, '/server/api/admin/user_detail.php?uuid=' . $B['fx']['u']['u1']['uuid']), 'detail as ' . $k);
        assert_eq($want[1], $d['user']['email'], 'email as ' . $k);
        assert_eq($want[0], $d['user']['last_login_ip'], 'last_login_ip as ' . $k);
    }
    $l = av_ok(av_get($B, 'mg', '/server/api/admin/logs.php?type=login&q=Secr3t'), 'search masked input');
    assert_eq(0, (int) $l['total'], 'unknown-id input is not searchable (oracle closed)');
    // T3-9 owner 컬렉션
    av_ok(av_post($B, 'sa', '/server/api/admin/platform_tables.php', array('action' => 'upsert_collection', 'service_id' => 'svc-a',
        'name' => 'diary', 'read_rule' => 'owner', 'write_rule' => 'owner', 'indexes' => array())), 'owner collection');
    av_ok(av_http($B, 'POST', '/server/api/platform/docs.php', array('service_id' => 'svc-a', 'service_secret' => $B['fx']['sec']['svc-a'],
        'op' => 'create', 'collection' => 'diary', 'doc_id' => 'd1', 'owner_uuid' => $B['fx']['u']['u1']['uuid'],
        'data' => array('text' => 'my private note'))), 'doc create');
    $li = av_ok(av_get($B, 'sa', '/server/api/admin/platform_tables.php?service_id=svc-a&collection=diary'), 'list docs');
    assert_eq(null, $li['items'][0]['preview'], 'owner doc preview null (OWNER)');
    assert_true($li['items'][0]['data_bytes'] > 0, 'data_bytes given');
    $one = av_ok(av_get($B, 'sa', '/server/api/admin/platform_tables.php?service_id=svc-a&collection=diary&doc_id=d1'), 'doc');
    assert_eq(null, $one['doc']['data'], 'owner doc data null for superadmin');
    assert_not_contains('my private note', json_encode($li) . json_encode($one), 'no byte of the note');
    av_err(av_post($B, 'sa', '/server/api/admin/platform_tables.php', array('action' => 'doc_set', 'service_id' => 'svc-a',
        'collection' => 'diary', 'doc_id' => 'd1', 'data' => array('x' => 1), 'expected_version' => 1)), 403, 'FORBIDDEN', 'doc_set refused');
    av_err(av_post($B, 'sa', '/server/api/admin/platform_tables.php', array('action' => 'doc_delete', 'service_id' => 'svc-a',
        'collection' => 'diary', 'doc_id' => 'd1', 'expected_version' => 1)), 400, 'VALIDATION_FAILED', 'delete needs a reason', 'reason');
    av_ok(av_post($B, 'sa', '/server/api/admin/platform_tables.php', array('action' => 'doc_delete', 'service_id' => 'svc-a',
        'collection' => 'diary', 'doc_id' => 'd1', 'expected_version' => 1, 'reason' => 'user request')), 'delete with reason');
    assert_eq(1, count(av_q($B, "SELECT id FROM app_ulogin_plat_audit WHERE action = 'admin.doc.delete_owner'")), 'audited');
}

/** emergency_admin CLI 실행. $stdin 은 표준입력(파이프)으로 넘긴다. */
function av_em_cli(array $B, string $args, string $stdin = ''): array
{
    $cmd = h_q(h_php_bin()) . ' -c ' . h_q(av_ini()) . ' ' . h_q($B['root'] . '/server/tools/emergency_admin.php') . ' ' . $args;
    $desc = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
    $pipes = array();
    $p = proc_open($cmd, $desc, $pipes, $B['root'], null, array('bypass_shell' => true));
    if (!is_resource($p)) {
        fail('HARNESS-FAIL cannot run emergency_admin.php');
    }
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($p);
    return array($rc, $out);
}

/** T3-10 비상 계정 */
function av_sc_emergency(array $B): void
{
    av_reset_rl($B);
    list($rc, $o) = av_em_cli($B, '--init');
    assert_eq(0, $rc, 'init dry-run (' . $o . ')');
    assert_eq(0, count(av_q($B, "SELECT id FROM app_ulogin_users WHERE username = 'admin'")), 'dry-run wrote nothing');
    list($rc, $o) = av_em_cli($B, '--init --apply');
    assert_eq(0, $rc, 'init apply (' . $o . ')');
    list($rc, $o) = av_em_cli($B, '--init --apply');
    assert_eq(3, $rc, 'second init refused');
    list($rc, $o) = av_em_cli($B, '--set-password --password=Abcdefgh1234!x');
    assert_eq(2, $rc, 'argv password refused');
    $em = av_q($B, "SELECT * FROM app_ulogin_users WHERE username = 'admin'")[0];
    assert_eq(array('emergency', 'superadmin', '!unset'), array($em['account_kind'], $em['role'], $em['password_hash']), 'created unset');
    // 미설정 login 401 + fail_emergency_unset, 실패 횟수 안 셈
    av_err(av_http($B, 'POST', '/server/api/auth/login.php', array('username' => 'admin', 'password' => '!unset')), 401,
        'AUTH_INVALID_CREDENTIALS', 'unset login', 'password');
    assert_eq(1, count(av_q($B, "SELECT id FROM app_ulogin_login_logs WHERE result = 'fail_emergency_unset'")), 'logged unset');
    assert_eq(0, (int) av_q($B, "SELECT failed_login_count FROM app_ulogin_users WHERE username = 'admin'")[0]['failed_login_count'],
        'unset attempts are not counted');
    // 정책 위반은 거절
    list($rc, $o) = av_em_cli($B, '--set-password --apply', "short1!\nshort1!\n");
    assert_eq(1, $rc, 'policy enforced');
    list($rc, $o) = av_em_cli($B, '--set-password --apply', AV_EM_PW . "\nmismatch-" . AV_EM_PW . "\n");
    assert_eq(1, $rc, 'mismatch refused');
    list($rc, $o) = av_em_cli($B, '--set-password --apply', AV_EM_PW . "\n" . AV_EM_PW . "\n");
    assert_eq(0, $rc, 'set-password (' . $o . ')');
    assert_not_contains(AV_EM_PW, $o, 'CLI never prints the value');
    list($rc, $o) = av_em_cli($B, '--status');
    assert_contains('password=set', $o, 'status set');
    // 로그인: 콘솔 전용 · 1시간 · refresh 없음
    av_reset_rl($B);
    $d = av_ok(av_http($B, 'POST', '/server/api/auth/login.php', array('username' => 'admin', 'password' => AV_EM_PW, 'remember' => true)),
        'emergency login');
    assert_true($d['expires_in'] <= 3600, 'session 1h (' . $d['expires_in'] . ')');
    assert_eq(null, $d['refresh_token'], 'no refresh token');
    $etok = (string) $d['access_token'];
    av_err(av_http($B, 'POST', '/server/api/auth/login.php', array('username' => 'admin', 'password' => AV_EM_PW, 'service_id' => 'svc-a')), 403,
        'SERVICE_ACCESS_DENIED', 'emergency SSO (EM-SSO)');
    assert_eq(1, count(av_q($B, "SELECT id FROM app_ulogin_login_logs WHERE result = 'fail_emergency_scope'")), 'logged scope');
    assert_eq(0, count(av_q($B, 'SELECT id FROM app_ulogin_user_services WHERE user_id = ?', array((int) $em['id']))), 'no membership row');
    av_err(av_http($B, 'PUT', '/server/api/me/password.php', array('current_password' => AV_EM_PW, 'password' => 'Other-pass-123456',
        'password_confirm' => 'Other-pass-123456'), $etok), 403, 'FORBIDDEN', 'me/password blocked');
    av_err(av_http($B, 'PUT', '/server/api/admin/user_detail.php?uuid=' . $em['uuid'], array('name' => 'x'), $B['fx']['tok']['sa']), 403,
        'FORBIDDEN', 'admin edit blocked');
    av_err(av_override($B, 'u1', 'svc-a', array('grant' => 'allow', 'uuid' => $em['uuid'])), 400, 'VALIDATION_FAILED', 'no grants', 'uuid');
    av_err(av_http($B, 'POST', '/server/api/sso/userinfo.php', array('service_id' => 'svc-a', 'service_secret' => $B['fx']['sec']['svc-a'],
        'uuid' => $em['uuid'])), 404, 'NOT_FOUND', 'userinfo 404');
    $aud = array_column(av_q($B, "SELECT action FROM app_ulogin_plat_audit WHERE action LIKE 'emergency.%' ORDER BY id"), 'action');
    foreach (array('emergency.create', 'emergency.password.set', 'emergency.login.failure', 'emergency.login.success', 'emergency.sso.denied') as $a) {
        assert_true(in_array($a, $aud, true), 'audit ' . $a);
    }
    assert_not_contains(AV_EM_PW, json_encode(av_q($B, 'SELECT * FROM app_ulogin_plat_audit')) . json_encode(av_q($B, 'SELECT * FROM app_ulogin_admin_logs')),
        'value never in audit');
    // push extra.kind → is_emergency · plan 차단 · unify_admin skip
    $push = av_http($B, 'POST', '/server/api/sso/accounts_push.php', array('service_id' => 'svc-a', 'service_secret' => $B['fx']['sec']['svc-a'],
        'sync_id' => gen_uuid4(), 'page' => 1, 'final' => false, 'sent_at' => av_iso(), 'accounts' => array(
            array('external_id' => 'L1', 'status' => 'active', 'username' => 'admin', 'extra' => array('kind' => 'emergency')),
            array('external_id' => 'L2', 'status' => 'active', 'username' => 'av_u1'))));
    av_ok($push, 'push');
    $ext = av_q($B, "SELECT external_id, is_emergency FROM app_ulogin_ext_accounts WHERE service_id = 'svc-a' ORDER BY external_id");
    assert_eq(array(1, 0), array((int) $ext[0]['is_emergency'], (int) $ext[1]['is_emergency']), 'is_emergency stored from extra.kind');
    $plan = av_ok(av_post($B, 'sa', '/server/api/admin/account_link_ops.php', array('action' => 'plan', 'items' => array(
        array('op' => 'link', 'service_id' => 'svc-a', 'external_id' => 'L1', 'target' => array('uuid' => $B['fx']['u']['u1']['uuid'])),
        array('op' => 'link', 'service_id' => 'svc-a', 'external_id' => 'L2', 'target' => array('uuid' => $em['uuid']))))), 'plan');
    foreach ($plan['items'] as $pi) {
        assert_eq('invalid', $pi['verdict'], 'plan blocked ' . $pi['external_id']);
        assert_true(in_array('EMERGENCY_ACCOUNT', $pi['codes'], true), 'code EMERGENCY_ACCOUNT ' . $pi['external_id']);
    }
    $code = 0;
    $o = h_run(h_q(h_php_bin()) . ' -c ' . h_q(av_ini()) . ' ' . h_q($B['root'] . '/server/tools/unify_admin.php')
        . ' --link-username=admin --to=av_sa', $code, 60.0);
    assert_contains('skipped: emergency', $o, 'unify_admin skips emergency ext account');
    $o = h_run(h_q(h_php_bin()) . ' -c ' . h_q(av_ini()) . ' ' . h_q($B['root'] . '/server/tools/unify_admin.php')
        . ' --link-username=av_u1 --to=admin', $code, 60.0);
    assert_eq(3, $code, 'unify_admin --to emergency -> 3');
}

/** T3-11 0009 모양 SQLite → 0010 apply → verify → 재적용 무변경 */
function av_sc_migrate(array $B): void
{
    $schema = (string) file_get_contents($B['root'] . '/server/schema.sql');
    $old = preg_replace('~^CREATE TABLE IF NOT EXISTS `app_ulogin_(?:access_groups|access_group_members|access_group_services)` \(.*?\n\) ENGINE[^;]*;~ms',
        '', $schema, -1, $nt);
    $old = preg_replace('~^\s*`(?:level_aware|access_level|access_starts_at|access_ends_at|access_note|access_updated_by|access_updated_at|account_kind|is_emergency)`[^\n]*\n~m',
        '', (string) $old, -1, $nc);
    if ($nt !== 3 || $nc !== 9) {
        fail('HARNESS-FAIL could not derive the 0009-state schema (' . $nt . '/' . $nc . ')');
    }
    $oldFile = $B['base'] . '/schema_0009.sql';
    file_put_contents($oldFile, $old);
    $db = $B['base'] . '/data/ulogin_test_av_mig.sqlite';
    $pdo = new PDO('sqlite:' . $db, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    mig_sqlite_create_schema($pdo, $oldFile, 'app_ulogin_', false);
    $files = array();
    foreach (mig_list($B['root'] . '/server/migrations') as $f) {
        if (strcmp((string) $f['version'], '0010') < 0) {
            $files[] = $f;
        }
    }
    assert_eq(9, count($files), 'nine migrations before 0010');
    mig_stamp_baseline($pdo, $files, 'app_ulogin_schema_migrations', 'test');
    $pdo = null;
    $cfgFile = $B['root'] . '/server/config.php';
    $cfgOrig = (string) file_get_contents($cfgFile);
    file_put_contents($cfgFile, av_set_define($cfgOrig, 'DB_SQLITE_PATH', var_export($db, true)));
    $run = function ($cmd) use ($B) {
        $code = 0;
        $out = h_run(h_q(h_php_bin()) . ' -c ' . h_q(av_ini()) . ' ' . h_q($B['root'] . '/server/migrate.php') . ' ' . $cmd, $code);
        return array($code, $out);
    };
    try {
        list($c1, $o1) = $run('apply');
        assert_eq(0, $c1, 'migrate apply 0009 -> 0010 (' . substr($o1, -600) . ')');
        assert_contains('0010', $o1, 'apply ran 0010');
        list($c2, $o2) = $run('verify');
        assert_eq(0, $c2, 'verify after upgrade equals schema.sql (' . substr($o2, -600) . ')');
        list($c3, $o3) = $run('apply');
        assert_eq(0, $c3, 'second apply is a no-op (' . substr($o3, -300) . ')');
    } finally {
        file_put_contents($cfgFile, $cfgOrig);
    }
}

/** D-369 공개 클라이언트 사용자 지정 scheme */
function av_sc_scheme(array $B): void
{
    av_reset_rl($B);
    $svc = function (array $over) use ($B) {
        return av_post($B, 'sa', '/server/api/admin/services.php', array_merge(array('service_id' => 'hc-app', 'service_name' => 'HC',
            'is_confidential' => false, 'redirect_whitelist' => array('myapp://auth'), 'allowed_origins' => array()), $over));
    };
    av_err($svc(array('service_id' => 'hc-conf', 'is_confidential' => true, 'service_secret' => 'conf-secret-123456')), 400,
        'VALIDATION_FAILED', 'confidential + custom scheme refused', 'redirect_whitelist');
    av_err($svc(array('service_id' => 'hc-js', 'redirect_whitelist' => array('javascript:alert(1)'))), 400, 'VALIDATION_FAILED',
        'javascript: refused', 'redirect_whitelist');
    av_err($svc(array('service_id' => 'hc-data', 'redirect_whitelist' => array('data:text/html,x'))), 400, 'VALIDATION_FAILED',
        'data: refused', 'redirect_whitelist');
    $c = $svc(array());
    assert_eq(201, $c['status'], 'public + custom scheme accepted (' . substr($c['body'], 0, 200) . ')');
    av_err(av_http($B, 'PUT', '/server/api/admin/services.php?service_id=hc-app', array('is_confidential' => true,
        'service_secret' => 'conf-secret-123456'), $B['fx']['tok']['sa']), 400, 'VALIDATION_FAILED', 'switch to confidential with custom left',
        'redirect_whitelist');
    list($v, $ch) = av_pkce();
    $a = av_http($B, 'POST', '/server/api/sso/authorize.php', array('username' => 'av_u1', 'password' => AV_PW, 'service_id' => 'hc-app',
        'redirect_uri' => 'myapp://evil', 'code_challenge' => $ch, 'code_challenge_method' => 'S256'));
    av_err($a, 400, 'SSO_INVALID_REDIRECT', 'myapp://evil does not match', 'redirect_uri');
    $a = av_ok(av_http($B, 'POST', '/server/api/sso/authorize.php', array('username' => 'av_u1', 'password' => AV_PW, 'service_id' => 'hc-app',
        'redirect_uri' => 'myapp://auth', 'code_challenge' => $ch, 'code_challenge_method' => 'S256', 'state' => 's9')), 'authorize custom');
    assert_eq(0, strpos((string) $a['redirect_to'], 'myapp://auth?code='), 'redirect_to keeps the custom scheme');
    $x = av_ok(av_http($B, 'POST', '/server/api/auth/exchange.php', array('code' => $a['code'], 'service_id' => 'hc-app',
        'redirect_uri' => 'myapp://auth', 'code_verifier' => $v)), 'exchange custom');
    assert_true(isset($x['access_token']), 'token issued');
}

// =====================================================================
// 케이스
// =====================================================================

t('T3-1..4,6 levels: view+aware exchange, unaware 403, revoke, deny first, expired window, matrix == single', function () {
    av_sc_levels(av_box('levels'));
});
t('T3-5 groups: create/members/service, disable and delete revoke affected sessions, audit, service token 403', function () {
    av_sc_groups(av_box('groups'));
});
t('T3-7 user_search: 1-char exact, prefix, permission filter, 21 -> 20, 3 fields, 31st 429, audit failure 500', function () {
    av_sc_search(av_box('search'));
});
t('T3-8/9 masking by role and owner documents hidden from admins', function () {
    av_sc_mask(av_box('mask'));
});
t('T3-10 emergency admin: CLI only, unset 401, 1h console session, SSO 403, web edits blocked, push kind, plan block, unify skip', function () {
    av_sc_emergency(av_box('em'));
});
t('T3-11 migration 0010 on SQLite: 0009 state -> apply -> verify clean, re-apply no-op (MySQL: t_20 with a dbport test instance)', function () {
    av_sc_migrate(av_box('mig'));
});
t('D-369 custom scheme only for public clients; javascript/data refused; exact match; authorize + exchange carry it', function () {
    av_sc_scheme(av_box('scheme'));
});

// 변이(D-186) — 일곱 개 모두 빨개져야 한다
t('mutant A1: deny priority removed', function () {
    av_mutant('a1', array(array('server/core/access.php', "    if (\$g === 'deny' && \$gw) {", "    if (false) {")), 'av_sc_levels');
});
t('mutant A2: period ignored', function () {
    av_mutant('a2', array(array('server/core/access.php', "function access_win(\$s, \$e, int \$now): bool\n{",
        "function access_win(\$s, \$e, int \$now): bool\n{\n    return true;")), 'av_sc_levels');
});
t('mutant A3: level_aware ignored', function () {
    av_mutant('a3', array(array('server/core/access.php',
        "return \$r === ACCESS_RANK['view'] && \$svc !== null && (int) (\$svc['level_aware'] ?? 0) === 1;",
        "return \$r === ACCESS_RANK['view'];")), 'av_sc_levels');
});
t('mutant A4: bool map includes view', function () {
    av_mutant('a4', array(array('server/core/access.php', "        \$map[\$sid] = access_rank(\$d['level']) >= ACCESS_RANK['use'];",
        "        \$map[\$sid] = access_rank(\$d['level']) >= ACCESS_RANK['view'];")), 'av_sc_levels');
});
t('mutant A5: user_search level filter removed', function () {
    av_mutant('a5', array(array('server/api/sso/user_search.php', "if (access_rank(\$d['level']) >= ACCESS_RANK['view']) {   // USER-SEARCH-LEVEL",
        "if (true) {   // USER-SEARCH-LEVEL")), 'av_sc_search');
});
t('mutant A6: emergency account allowed into SSO', function () {
    av_mutant('a6', array(array('server/core/access.php', "    if ((string) (\$user['account_kind'] ?? 'normal') === 'emergency') {\n        return \$out('none', 'rule', 'emergency_account');",
        "    if (false) {\n        return \$out('none', 'rule', 'emergency_account');")), 'av_sc_emergency');
});
t('mutant A7: owner document data exposed', function () {
    av_mutant('a7', array(array('server/api/admin/platform_tables.php', "\$ownerOnly = (string) \$coll['read_rule'] === 'owner';   // OWNER-DATA-HIDDEN",
        "\$ownerOnly = false;   // OWNER-DATA-HIDDEN")), 'av_sc_mask');
});
