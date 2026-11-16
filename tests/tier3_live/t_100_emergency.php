<?php
declare(strict_types=1);

/**
 * 티어 3 - v1.9.1 비상 로그인 확인값 (emergency/1 1-1장, D-362)
 *
 * 사본 서버(php -S, 빈 포트) + 임시 SQLite. 운영 DB·8080·3306 무접촉(접두 가드), 서버는 자기 PID 만 taskkill /T.
 * 덮는 것: 계산식(고정 벡터 + 계약 식 독립 재계산) · 서비스·uuid 묶임 · 발급 조건(연결 안 됨·접근 불가·허용 꺼짐·비활성 -> 미발급)
 *          · 폐기 사건 각 경로(비밀번호 변경·재설정·정지·탈퇴·삭제·접근 회수·허용 끔·관리자 강제·역할 변경)
 *          · ev 가 로그·감사·관리 응답·DB 평문에 0건 · rank 20 비밀번호 정책 · 로그인 지연
 * 변이 2: 서비스 id 를 식에서 뺌 / 비밀번호 변경 시 폐기 생략
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

const EP_PW = 'pf-Test-pass-9183';
$GLOBALS['EP'] = array('boxes' => array(), 'registered' => false, 'p95' => null);

function ep_rm(string $p): void
{
    if (strpos(h_norm($p), '/ulogin_test_ep_') === false) {
        return;
    }
    ep_rm_inner($p);
}

function ep_rm_inner(string $p): void
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
            ep_rm_inner($p . '/' . $n);
        }
    }
    @rmdir($p);
}

function ep_copy(string $src, string $dst): int
{
    @mkdir($dst, 0777, true);
    $n = 0;
    foreach (scandir($src) as $e) {
        if ($e === '.' || $e === '..' || $e === 'config.php' || $e === '.install.lock' || $e === '.installed') {
            continue;
        }
        if (is_dir($src . '/' . $e)) {
            $n += ep_copy($src . '/' . $e, $dst . '/' . $e);
        } else {
            copy($src . '/' . $e, $dst . '/' . $e);
            $n++;
        }
    }
    return $n;
}

function ep_teardown(): void
{
    foreach ($GLOBALS['EP']['boxes'] as $b) {
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
    foreach ($GLOBALS['EP']['boxes'] as $b) {
        ep_rm($b['base']);
    }
    $GLOBALS['EP']['boxes'] = array();
}

function ep_ini(): string
{
    $ini = php_ini_loaded_file();
    if (!is_string($ini) || $ini === '' || !is_file($ini)) {
        skip('runner has no php.ini (-n?) — children would lack pdo_sqlite; run with -c <ini>');
    }
    return $ini;
}

function ep_free_port(): int
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

function ep_set_define(string $src, string $name, string $phpValue): string
{
    $re = '/^define\(\'' . preg_quote($name, '/') . '\',\s*[^\n]*?\);/m';
    $n = 0;
    $out = preg_replace($re, 'define(\'' . $name . '\', ' . $phpValue . ');', $src, 1, $n);
    if ($n !== 1 || !is_string($out)) {
        fail('HARNESS-FAIL config.sample.php: define(' . $name . ') not found exactly once');
    }
    return $out;
}

function ep_box(string $key, array $mut = array()): array
{
    require_ext('pdo_sqlite');
    $ini = ep_ini();
    if (!$GLOBALS['EP']['registered']) {
        $GLOBALS['EP']['registered'] = true;
        register_shutdown_function('ep_teardown');
    }
    $base = h_norm(sys_get_temp_dir()) . '/ulogin_test_ep_' . getmypid() . '_' . bin2hex(random_bytes(3)) . '_' . $key;
    $root = $base . '/root';
    $n  = ep_copy(h_root() . '/server', $root . '/server');
    $n += ep_copy(h_root() . '/public', $root . '/public');
    @mkdir($root . '/tools', 0777, true);
    copy(h_root() . '/tools/router-ulogin.php', $root . '/tools/router-ulogin.php');
    $B = array('base' => $base, 'root' => $root, 'key' => $key, 'pid' => 0, 'proc' => null, 'port' => 0);
    $GLOBALS['EP']['boxes'][] = &$B;
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
    $port = ep_free_port();
    $B['port'] = $port;
    $cfg = (string) file_get_contents(h_root() . '/server/config.sample.php');
    $cfg = ep_set_define($cfg, 'DB_DRIVER', "'sqlite'");
    $cfg = ep_set_define($cfg, 'DB_SQLITE_PATH', var_export($db, true));
    $B['enc'] = bin2hex(random_bytes(32));
    $cfg = ep_set_define($cfg, 'SECRET_ENC_KEY', "'" . $B['enc'] . "'");
    $cfg = ep_set_define($cfg, 'APP_KEY', "'" . bin2hex(random_bytes(32)) . "'");
    $cfg = ep_set_define($cfg, 'FORCE_HTTPS', 'false');
    $cfg = ep_set_define($cfg, 'COOKIE_SECURE', 'false');
    $cfg = ep_set_define($cfg, 'BASE_URL', "'http://127.0.0.1:" . $port . "'");
    // 무음 SSO 는 https 판정이 필요하다 — 루프백을 신뢰 프록시로 두고 그 한 요청만 X-Forwarded-Proto 를 싣는다
    $cfg = ep_set_define($cfg, 'TRUST_PROXY', 'true');
    $cfg = ep_set_define($cfg, 'TRUSTED_PROXIES', "['127.0.0.1']");
    file_put_contents($root . '/server/config.php', $cfg);
    $B['fx'] = ep_seed($B);

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
        $r = ep_http($B, 'GET', '/version.txt');
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

function ep_pdo(array $B): PDO
{
    $pdo = new PDO('sqlite:' . $B['db'], null, null, array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
    $pdo->exec('PRAGMA busy_timeout = 8000');
    return $pdo;
}

function ep_q(array $B, string $sql, array $p = array()): array
{
    $pdo = ep_pdo($B);
    $st = $pdo->prepare($sql);
    $st->execute($p);
    $rows = $st->fetchAll();
    $pdo = null;
    return $rows;
}

function ep_x(array $B, string $sql, array $p = array()): int
{
    $pdo = ep_pdo($B);
    $st = $pdo->prepare($sql);
    $st->execute($p);
    $n = $st->rowCount();
    $pdo = null;
    return $n;
}

function ep_now(int $off = 0): string
{
    return gmdate('Y-m-d H:i:s', time() + $off);
}

function ep_iso(int $off = 0): string
{
    return gmdate('Y-m-d\TH:i:s\Z', time() + $off);
}

/** 원시 HTTP/1.0. $hdr = ['Name: v', …]. */
function ep_http(array $B, string $method, string $path, $body = null, ?string $bearer = null, array $hdr = array()): array
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

function ep_code(array $r): string
{
    return is_array($r['json']) && isset($r['json']['error']['code']) ? (string) $r['json']['error']['code'] : '';
}

function ep_field(array $r): ?string
{
    return is_array($r['json']) && isset($r['json']['error']['field']) ? (string) $r['json']['error']['field'] : null;
}

function ep_ok(array $r, string $what): array
{
    if ($r['status'] !== 200 || !is_array($r['json']) || $r['json']['ok'] !== true) {
        fail($what . ': expected 200 ok, got ' . $r['status'] . ' ' . substr($r['body'], 0, 400));
    }
    return is_array($r['json']['data']) ? $r['json']['data'] : array();
}

function ep_err(array $r, int $status, string $code, string $what, ?string $field = null): void
{
    if ($r['status'] !== $status || ep_code($r) !== $code) {
        fail($what . ': expected ' . $status . ' ' . $code . ', got ' . $r['status'] . ' ' . substr($r['body'], 0, 400));
    }
    if ($field !== null && ep_field($r) !== $field) {
        fail($what . ': expected field ' . $field . ', got ' . var_export(ep_field($r), true));
    }
}

function ep_token(PDO $pdo, int $uid, ?string $sid, string $type = 'token', ?string $csrf = null): string
{
    $tok = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO app_ulogin_sessions (session_id, user_id, service_id, auth_type, access_token_hash, csrf_token_hash,'
        . ' issued_at, expires_at, last_seen_at, ip, user_agent) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute(array(bin2hex(random_bytes(32)), $uid, $sid, $type, hash('sha256', $tok), $csrf === null ? null : hash('sha256', $csrf),
            ep_now(-5), ep_now(3600), ep_now(-5), '127.0.0.1', 'pf'));
    return $tok;
}

function ep_tok(array $B, int $uid, ?string $sid): string
{
    $pdo = ep_pdo($B);
    $t = ep_token($pdo, $uid, $sid);
    $pdo = null;
    return $t;
}

function ep_seed(array $B): array
{
    $pdo = ep_pdo($B);
    $now = ep_now();
    $hash = password_hash(EP_PW, PASSWORD_DEFAULT);
    $fx = array('u' => array(), 'tok' => array(), 'sec' => array());
    $ins = $pdo->prepare('INSERT INTO app_ulogin_services (service_id, service_name, service_secret_hash, is_confidential,'
        . ' redirect_whitelist, allowed_origins, auto_join, status, can_list_users, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    foreach (array('svc-a' => 0, 'svc-b' => 0, 'svc-m' => 1, 'svc-off' => 0) as $sid => $clu) {
        $sec = 'sec_' . $sid . '_' . bin2hex(random_bytes(16));
        $fx['sec'][$sid] = $sec;
        $ins->execute(array($sid, 'Svc ' . $sid, hash('sha256', $sec), 1, json_encode(array('http://127.0.0.1:9/cb', 'https://app.test/cb')),
            json_encode(array('http://app.test')), 1, $sid === 'svc-off' ? 'disabled' : 'active', $clu, $now, $now));
    }
    $insU = $pdo->prepare('INSERT INTO app_ulogin_users (uuid, username, email, password_hash, name, status, account_level, role,'
        . ' billing_tier, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    foreach (array('sa' => 'superadmin', 'ad' => 'admin', 'ov' => 'user', 'u1' => 'user', 'u2' => 'user', 'u3' => 'user') as $k => $role) {
        $uuid = gen_uuid4();
        $insU->execute(array($uuid, 'ep_' . $k, 'ep_' . $k . '@test.invalid', $hash, $k === 'u1' ? '홍길동' : null, 'active', 'basic',
            $role, 'free', $now, $now));
        $fx['u'][$k] = array('id' => (int) $pdo->lastInsertId(), 'uuid' => $uuid, 'username' => 'ep_' . $k);
    }
    // ov: 전역 user + svc-a 오버라이드 admin — 접근 판정은 전역만 본다(변이 M1 의 표적)
    $pdo->prepare('INSERT INTO app_ulogin_user_services (user_id, service_id, role, status, joined_at) VALUES (?,?,?,?,?)')
        ->execute(array($fx['u']['ov']['id'], 'svc-a', 'admin', 'active', $now));
    $fx['tok']['sa'] = ep_token($pdo, $fx['u']['sa']['id'], null);
    $fx['tok']['ad'] = ep_token($pdo, $fx['u']['ad']['id'], null);
    foreach (array('u1', 'u2', 'u3', 'ov') as $k) {
        $fx['tok'][$k] = ep_token($pdo, $fx['u'][$k]['id'], 'svc-a');
    }
    $fx['tok']['ad_svc'] = ep_token($pdo, $fx['u']['ad']['id'], 'svc-a');
    $fx['tok']['u1_self'] = ep_token($pdo, $fx['u']['u1']['id'], null);
    $pdo = null;
    return $fx;
}

function ep_reset_rl(array $B): void
{
    ep_x($B, 'DELETE FROM app_ulogin_rate_limits');
}

function ep_s2s(array $B, string $path, string $sid, array $body): array
{
    return ep_http($B, 'POST', $path, array_merge(array('service_id' => $sid, 'service_secret' => $B['fx']['sec'][$sid]), $body));
}

function ep_ev(array $over = array()): array
{
    return array_merge(array('event_id' => gen_uuid4(), 'ts' => ep_iso(-10), 'level' => 'info', 'event' => 'job.done'), $over);
}

function ep_aev(array $over = array()): array
{
    return array_merge(array('event_id' => gen_uuid4(), 'ts' => ep_iso(-10), 'actor' => array('type' => 'admin', 'local_user' => 'boss'),
        'action' => 'user.role.update', 'target' => array('type' => 'user', 'id' => 'hong'), 'result' => 'success',
        'changes' => array('role' => array('before' => 'user', 'after' => 'admin'))), $over);
}

function ep_admin(array $B, string $who, string $path, array $body): array
{
    return ep_http($B, 'POST', $path, $body, $B['fx']['tok'][$who]);
}

function ep_mutant(string $key, array $mut, callable $scenario): void
{
    $B = ep_box('mut_' . $key, $mut);
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
function ep_cli(array $B, string $code): array
{
    $f = $B['root'] . '/server/tools/_pf_' . bin2hex(random_bytes(3)) . '.php';
    file_put_contents($f, "<?php\ndeclare(strict_types=1);\nrequire __DIR__ . '/../core/bootstrap.php';\n" . $code);
    $rc = 0;
    $out = h_run(h_q(h_php_bin()) . ' -c ' . h_q(ep_ini()) . ' ' . h_q($f), $rc, 60.0);
    @unlink($f);
    return array($rc, $out);
}

require_once h_root() . '/server/core/emergency.php';

$GLOBALS['EM_EVS'] = array();

// =====================================================================
// 헬퍼
// =====================================================================

function em_b64d(string $s): string
{
    return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
}

/** 계약 식 그대로 독립 재계산(서버 코드와 별개의 구현). */
function em_expect_ev(string $pw, string $salt, string $sid, string $uuid): string
{
    $dk  = hash_pbkdf2('sha256', $pw, $salt, 310000, 32, true);
    $mac = hash_hmac('sha256', 'emergency/1|' . $sid . '|' . $uuid, $dk, true);
    $b   = function ($x) {
        return rtrim(strtr(base64_encode($x), '+/', '-_'), '=');
    };
    return 'ev1$pbkdf2-sha256$310000$' . $b($salt) . '$' . $b($mac);
}

function em_check_ev(string $ev, string $pw, string $sid, string $uuid, string $what): void
{
    $p = explode('$', $ev);
    if (count($p) !== 5 || $p[0] !== 'ev1' || $p[1] !== 'pbkdf2-sha256' || $p[2] !== '310000') {
        fail($what . ': ev shape ' . substr($ev, 0, 40));
    }
    $salt = em_b64d($p[3]);
    assert_eq(16, strlen($salt), $what . ' salt 16 bytes');
    assert_eq(em_expect_ev($pw, $salt, $sid, $uuid), $ev, $what . ' ev equals the contract formula');
    $GLOBALS['EM_EVS'][] = $ev;
}

function em_user(array $B, string $name, string $role = 'user'): array
{
    $pdo  = ep_pdo($B);
    $uuid = gen_uuid4();
    $pdo->prepare('INSERT INTO app_ulogin_users (uuid, username, email, password_hash, name, status, account_level, role, billing_tier,'
        . ' created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute(array($uuid, $name, $name . '@test.invalid', password_hash(EP_PW, PASSWORD_DEFAULT), null, 'active', 'basic', $role, 'free', ep_now(), ep_now()));
    $id  = (int) $pdo->lastInsertId();
    $pdo = null;
    return array('id' => $id, 'uuid' => $uuid, 'username' => $name);
}

function em_service(array &$B, string $sid, array $over = array()): void
{
    $sec = 'sec_' . $sid . '_' . bin2hex(random_bytes(16));
    $B['fx']['sec'][$sid] = $sec;
    $now = ep_now();
    $r = array_merge(array('service_id' => $sid, 'service_name' => 'Svc ' . $sid, 'service_secret_hash' => hash('sha256', $sec),
        'is_confidential' => 1, 'redirect_whitelist' => json_encode(array('https://app.test/cb')), 'allowed_origins' => json_encode(array()),
        'auto_join' => 1, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now), $over);
    $cols = array_keys($r);
    $pdo = ep_pdo($B);
    $pdo->prepare('INSERT INTO app_ulogin_services (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
        ->execute(array_values($r));
    $pdo = null;
}

function em_link(array $B, array $u, string $sid): void
{
    $now = ep_now();
    ep_x($B, 'INSERT INTO app_ulogin_ext_accounts (service_id, external_id, username, state, user_id, link_origin, linked_at, row_version,'
        . ' source_sent_at, first_seen_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        array($sid, 'ext-' . $u['username'] . '-' . $sid, $u['username'], 'linked', $u['id'], 'attached', $now, 0, $now, $now, $now));
}

function em_login(array $B, string $username, string $pw = EP_PW): array
{
    ep_reset_rl($B);
    return ep_http($B, 'POST', '/server/api/auth/login.php', array('username' => $username, 'password' => $pw));
}

function em_feed(array $B, string $sid, int $after = 0): array
{
    return ep_ok(ep_s2s($B, '/server/api/sso/link_events.php', $sid, array('after' => $after, 'limit' => 500)), 'feed ' . $sid);
}

function em_evts(array $feed, ?string $uuid = null, ?string $type = null): array
{
    $o = array();
    foreach ($feed['events'] as $e) {
        if (($uuid === null || $e['uuid'] === $uuid) && ($type === null || $e['type'] === $type)) {
            $o[] = $e;
        }
    }
    return $o;
}

function em_cursor(array $B, string $sid): int
{
    return (int) em_feed($B, $sid)['next_after'];
}

/** 사용자 하나를 만들고 서비스에 연결해 로그인시킨다. 반환: [user, bearer, 로그인 뒤 커서]. */
function em_ready(array $B, string $name, string $role = 'user', string $sid = 'svc-a'): array
{
    $u = em_user($B, $name, $role);
    em_link($B, $u, $sid);
    $d = ep_ok(em_login($B, $name), 'login ' . $name);
    $f = em_evts(em_feed($B, $sid), $u['uuid'], 'emergency_verifier');
    assert_eq(1, count($f), 'verifier issued at login for ' . $name);
    em_check_ev($f[0]['ev'], EP_PW, $sid, $u['uuid'], 'login ev ' . $name);
    return array($u, (string) $d['access_token'], em_cursor($B, $sid));
}

function em_revoked(array $B, string $sid, string $uuid, int $after, string $reason, string $what): void
{
    $rv = em_evts(em_feed($B, $sid, $after), $uuid, 'emergency_revoke');
    assert_eq(1, count($rv), $what . ': exactly one emergency_revoke');
    assert_eq($reason, $rv[0]['reason'], $what . ': reason');
    assert_eq(array(), em_evts(em_feed($B, $sid), $uuid, 'emergency_verifier'), $what . ': superseded verifier is no longer delivered');
}

// =====================================================================
// 시나리오
// =====================================================================

function em_sc_issue(array $B): array
{
    // 고정 벡터(파이썬 hashlib 로 계산해 박은 값 — 서버 함수와 계약 식 둘 다와 대조)
    $salt = '';
    for ($i = 0; $i < 16; $i++) {
        $salt .= chr($i);
    }
    $vec  = 'ev1$pbkdf2-sha256$310000$AAECAwQFBgcICQoLDA0ODw$tJCcbUPlto1HFF8JG6YownrBBmP77F2MjcLi-mPYlPc';
    $uuid = '11111111-2222-4333-8444-555555555555';
    assert_eq($vec, em_ev_from_dk(em_dk('Vector-Passw0rd!', $salt), $salt, 'svc-a', $uuid), 'fixed vector (server functions)');
    assert_eq($vec, em_expect_ev('Vector-Passw0rd!', $salt, 'svc-a', $uuid), 'fixed vector (independent formula)');
    assert_true(em_ev_from_dk(em_dk('Vector-Passw0rd!', $salt), $salt, 'svc-b', $uuid) !== $vec, 'service id is bound into the mac');

    em_service($B, 'svc-g', array('access_rule' => 'granted'));
    ep_x($B, 'UPDATE app_ulogin_services SET emergency_allow = 0 WHERE service_id = ?', array('svc-m'));
    $u1 = $B['fx']['u']['u1'];
    $u2 = $B['fx']['u']['u2'];
    $u3 = $B['fx']['u']['u3'];
    foreach (array('svc-a', 'svc-b', 'svc-m', 'svc-off', 'svc-g') as $sid) {
        em_link($B, $u1, $sid);
    }
    ep_x($B, 'INSERT INTO app_ulogin_user_services (user_id, service_id, status, joined_at, access_grant) VALUES (?,?,?,?,?)',
        array($u1['id'], 'svc-g', 'active', ep_now(), 'allow'));
    em_link($B, $u2, 'svc-a');
    em_link($B, $u2, 'svc-g');

    $r1 = em_login($B, $u1['username']);
    ep_ok($r1, 'u1 login');
    assert_true(strpos($r1['body'], 'ev1$') === false, 'login response carries no ev');
    foreach (array('svc-a', 'svc-b', 'svc-g') as $sid) {
        $e = em_evts(em_feed($B, $sid), $u1['uuid'], 'emergency_verifier');
        assert_eq(1, count($e), 'u1 verifier on ' . $sid);
        em_check_ev($e[0]['ev'], EP_PW, $sid, $u1['uuid'], 'u1 ' . $sid);
        assert_eq('ep_u1', $e[0]['username'], 'username');
        assert_eq('홍길동', $e[0]['name'], 'name');
        assert_eq('user', $e[0]['role'], 'role');
        assert_eq(0, $e[0]['role_rank'], 'role_rank');
        assert_eq('active', $e[0]['status'], 'status');
        assert_eq('ext-ep_u1-' . $sid, $e[0]['external_id'], 'external_id');
        $dur = strtotime($e[0]['expires_at']) - strtotime($e[0]['issued_at']);
        assert_true(abs($dur - 30 * 86400) < 120, 'default 30 days: ' . $dur);
        foreach (array('event_id', 'type', 'external_id', 'uuid', 'at', 'username', 'name', 'role', 'role_rank', 'status', 'ev', 'issued_at', 'expires_at') as $k) {
            assert_true(array_key_exists($k, $e[0]), 'event key ' . $k);
        }
    }
    assert_eq(array(), em_evts(em_feed($B, 'svc-m'), null, 'emergency_verifier'), 'no verifier on svc-m (allow off)');
    // 비활성 서비스는 피드 인증이 401 이라 표로 확인한다
    assert_eq(0, count(ep_q($B, 'SELECT id FROM app_ulogin_emergency WHERE service_id = ?', array('svc-off'))), 'no emergency row for the disabled service');
    $ea = em_evts(em_feed($B, 'svc-a'), $u1['uuid'], 'emergency_verifier');
    $eb = em_evts(em_feed($B, 'svc-b'), $u1['uuid'], 'emergency_verifier');
    assert_true($ea[0]['ev'] !== $eb[0]['ev'], 'per-service ev differs');
    // 중복 억제: 10분 안 재로그인은 새 사건을 만들지 않는다
    $cur = em_cursor($B, 'svc-a');
    ep_ok(em_login($B, $u1['username']), 'u1 login again');
    assert_eq(array(), em_evts(em_feed($B, 'svc-a', $cur), $u1['uuid']), 'no new event for a re-login within 10 minutes');

    // u2: svc-a 만(svc-g 는 granted + inherit 이라 접근 불가)
    ep_ok(em_login($B, $u2['username']), 'u2 login');
    assert_eq(1, count(em_evts(em_feed($B, 'svc-a'), $u2['uuid'], 'emergency_verifier')), 'u2 on svc-a');
    assert_eq(array(), em_evts(em_feed($B, 'svc-g'), $u2['uuid']), 'u2 denied on svc-g -> none');
    // u3: 연결 없음
    $t3 = em_login($B, $u3['username']);
    ep_ok($t3, 'u3 login');
    foreach (array('svc-a', 'svc-b', 'svc-g') as $sid) {
        assert_eq(array(), em_evts(em_feed($B, $sid), $u3['uuid']), 'u3 not linked -> none on ' . $sid);
    }
    // 틀린 비밀번호로는 발급되지 않는다
    $cur = em_cursor($B, 'svc-b');
    em_login($B, $u1['username'], 'wrong-Password-1!');
    assert_eq(array(), em_evts(em_feed($B, 'svc-b', $cur), $u1['uuid']), 'failed login issues nothing');
    foreach (em_feed($B, 'svc-b')['events'] as $e) {
        assert_true(strpos((string) $e['external_id'], '-svc-a') === false, 'svc-b feed has no svc-a events');
    }
    note('login latency (SQLite, single worker): with 3 issuances ' . round($r1['ms']) . ' ms, without issuance ' . round($t3['ms']) . ' ms');
    return $B;
}

function em_sc_pwchange(array $B): void
{
    list($u, $bearer, $cur) = em_ready($B, 'em_pw1');
    ep_x($B, 'UPDATE app_ulogin_services SET emergency_days = 7 WHERE service_id = ?', array('svc-a'));
    $new = 'New-Passw0rd-7712x';
    ep_ok(ep_http($B, 'PUT', '/server/api/me/password.php', array('current_password' => EP_PW, 'password' => $new, 'password_confirm' => $new), $bearer), 'change pw');
    $f  = em_feed($B, 'svc-a', $cur);
    $rv = em_evts($f, $u['uuid'], 'emergency_revoke');
    $vf = em_evts($f, $u['uuid'], 'emergency_verifier');
    assert_eq(1, count($rv), 'revoke on password change');
    assert_eq('password_changed', $rv[0]['reason'], 'reason');
    assert_eq(1, count($vf), 'new verifier after the change');
    assert_true($rv[0]['event_id'] < $vf[0]['event_id'], 'revoke precedes the new value');
    em_check_ev($vf[0]['ev'], $new, 'svc-a', $u['uuid'], 'new ev');
    $dur = strtotime($vf[0]['expires_at']) - strtotime($vf[0]['issued_at']);
    assert_true(abs($dur - 7 * 86400) < 120, 'per-service days (7): ' . $dur);
    ep_x($B, 'UPDATE app_ulogin_services SET emergency_days = 30 WHERE service_id = ?', array('svc-a'));
}

function em_sc_paths(array $B): void
{
    $sa = $B['fx']['tok']['sa'];
    // 비밀번호 재설정
    list($u, $bearer, $cur) = em_ready($B, 'em_rs1');
    $tok = bin2hex(random_bytes(32));
    ep_x($B, 'INSERT INTO app_ulogin_password_resets (token_hash, user_id, expires_at, created_ip, created_at, purpose) VALUES (?,?,?,?,?,?)',
        array(hash('sha256', $tok), $u['id'], ep_now(3600), '127.0.0.1', ep_now(), 'reset'));
    $new = 'Reset-Passw0rd-5521x';
    ep_ok(ep_http($B, 'POST', '/server/api/auth/password_reset.php', array('token' => $tok, 'password' => $new, 'password_confirm' => $new)), 'reset');
    $f = em_feed($B, 'svc-a', $cur);
    assert_eq('password_reset', em_evts($f, $u['uuid'], 'emergency_revoke')[0]['reason'], 'reset: reason');
    $vf = em_evts($f, $u['uuid'], 'emergency_verifier');
    assert_eq(1, count($vf), 'reset: re-issued with the new password');
    em_check_ev($vf[0]['ev'], $new, 'svc-a', $u['uuid'], 'reset ev');

    // 정지
    list($u, $bearer, $cur) = em_ready($B, 'em_su1');
    ep_ok(ep_http($B, 'POST', '/server/api/admin/user_status.php?uuid=' . $u['uuid'], array('action' => 'suspend'), $sa), 'suspend');
    em_revoked($B, 'svc-a', $u['uuid'], $cur, 'suspended', 'suspend');

    // 관리자 임시 비밀번호 초기화
    list($u, $bearer, $cur) = em_ready($B, 'em_ar1');
    ep_ok(ep_http($B, 'POST', '/server/api/admin/user_status.php?uuid=' . $u['uuid'], array('action' => 'reset-password'), $sa), 'admin reset');
    em_revoked($B, 'svc-a', $u['uuid'], $cur, 'admin_reset', 'admin reset');

    // 탈퇴
    list($u, $bearer, $cur) = em_ready($B, 'em_wd1');
    ep_ok(ep_http($B, 'POST', '/server/api/me/withdraw.php', array('password' => EP_PW, 'reason' => 'test', 'confirm' => $u['username']), $bearer), 'withdraw');
    em_revoked($B, 'svc-a', $u['uuid'], $cur, 'withdrawn', 'withdraw');

    // 완전삭제 — 사용자 행이 없어도 uuid 로 폐기 사건이 나간다
    list($u, $bearer, $cur) = em_ready($B, 'em_dl1');
    ep_ok(ep_http($B, 'DELETE', '/server/api/admin/user_detail.php?uuid=' . $u['uuid'] . '&confirm=' . $u['username'], null, $sa), 'hard delete');
    em_revoked($B, 'svc-a', $u['uuid'], $cur, 'deleted', 'hard delete');

    // 접근 회수(계정별 deny)
    list($u, $bearer, $cur) = em_ready($B, 'em_ac1');
    ep_ok(ep_http($B, 'POST', '/server/api/admin/service_access.php', array('action' => 'set_grant', 'uuid' => $u['uuid'], 'service_id' => 'svc-a', 'grant' => 'deny'), $sa), 'deny');
    em_revoked($B, 'svc-a', $u['uuid'], $cur, 'access_revoked', 'deny grant');

    // 접근 규칙 변경(서비스 단위)
    list($u, $bearer, $cur) = em_ready($B, 'em_ru1', 'user', 'svc-b');
    ep_ok(ep_http($B, 'POST', '/server/api/admin/service_access.php', array('action' => 'set_rule', 'service_id' => 'svc-b', 'access_rule' => 'admin'), $sa), 'rule');
    em_revoked($B, 'svc-b', $u['uuid'], $cur, 'access_revoked', 'rule change');
    ep_ok(ep_http($B, 'POST', '/server/api/admin/service_access.php', array('action' => 'set_rule', 'service_id' => 'svc-b', 'access_rule' => 'all'), $sa), 'rule back');

    // 서비스의 「비상 로그인 허용」을 끔
    list($u, $bearer, $cur) = em_ready($B, 'em_ed1', 'user', 'svc-b');
    ep_ok(ep_http($B, 'PUT', '/server/api/admin/services.php?service_id=svc-b', array('emergency_allow' => false), $sa), 'allow off');
    em_revoked($B, 'svc-b', $u['uuid'], $cur, 'emergency_disabled', 'allow off');
    $cur2 = em_cursor($B, 'svc-b');
    em_login($B, $u['username']);
    assert_eq(array(), em_evts(em_feed($B, 'svc-b', $cur2), $u['uuid']), 'allow off -> login does not issue');
    ep_ok(ep_http($B, 'PUT', '/server/api/admin/services.php?service_id=svc-b', array('emergency_allow' => true), $sa), 'allow on');

    // 역할 변경
    list($u, $bearer, $cur) = em_ready($B, 'em_ro1');
    ep_ok(ep_http($B, 'PUT', '/server/api/admin/user_detail.php?uuid=' . $u['uuid'], array('uuid' => $u['uuid'], 'role' => 'manager'), $sa), 'role');
    em_revoked($B, 'svc-a', $u['uuid'], $cur, 'role_changed', 'role change');

    // 관리자 강제 폐기: 최고관리자만
    list($u, $bearer, $cur) = em_ready($B, 'em_fr1');
    $g = ep_ok(ep_http($B, 'GET', '/server/api/admin/emergency.php?uuid=' . $u['uuid'], null, $sa), 'live list');
    assert_eq('svc-a', $g['services'][0]['service_id'], 'live list names the service');
    assert_true(strpos(json_encode($g), 'ev1$') === false, 'live list has no value');
    ep_err(ep_http($B, 'POST', '/server/api/admin/emergency.php', array('uuid' => $u['uuid']), $B['fx']['tok']['ad']), 403, 'FORBIDDEN', 'admin (not superadmin) cannot force-revoke');
    $p = ep_ok(ep_http($B, 'POST', '/server/api/admin/emergency.php', array('uuid' => $u['uuid']), $sa), 'force revoke');
    assert_eq(1, $p['revoked'], 'revoked count');
    em_revoked($B, 'svc-a', $u['uuid'], $cur, 'admin_revoke', 'force revoke');
    $g = ep_ok(ep_http($B, 'GET', '/server/api/admin/emergency.php?uuid=' . $u['uuid'], null, $sa), 'live list after');
    assert_eq(array(), $g['services'], 'nothing live after force revoke');
    $p = ep_ok(ep_http($B, 'POST', '/server/api/admin/emergency.php', array('uuid' => $u['uuid']), $sa), 'force revoke twice');
    assert_eq(0, $p['revoked'], 'second force revoke is a no-op (no extra event)');
}

function em_sc_leak(array $B): void
{
    $evs = $GLOBALS['EM_EVS'];
    assert_true(count($evs) >= 10, 'harness saw ev values: ' . count($evs));
    $needles = array();
    foreach ($evs as $ev) {
        $needles[] = $ev;
        $needles[] = substr($ev, strrpos($ev, '$') + 1);   // mac 부분
    }
    $needles = array_values(array_unique($needles));
    $hay = array();
    foreach (array($B['db'], $B['db'] . '-wal', $B['base'] . '/php_error.log', $B['base'] . '/server.log') as $f) {
        if (is_file($f)) {
            $hay[$f] = (string) file_get_contents($f);
        }
    }
    assert_true(isset($hay[$B['db']]) && strlen($hay[$B['db']]) > 1000, 'db bytes read');
    foreach ($hay as $f => $bytes) {
        assert_true(strpos($bytes, 'ev1$pbkdf2') === false, 'no plaintext ev prefix in ' . basename($f));
        foreach ($needles as $n) {
            assert_true(strpos($bytes, $n) === false, 'ev/mac not in ' . basename($f));
        }
    }
    // 봉인: emergency.ev 는 평문이 아니다
    $rows = ep_q($B, 'SELECT ev FROM app_ulogin_emergency WHERE ev IS NOT NULL LIMIT 5');
    assert_true(count($rows) > 0 && strpos((string) $rows[0]['ev'], 'ev1$') === false, 'ev stored sealed');
    // 관리 API 응답
    $sa = $B['fx']['tok']['sa'];
    $u1 = $B['fx']['u']['u1'];
    foreach (array('/server/api/admin/users.php', '/server/api/admin/user_detail.php?uuid=' . $u1['uuid'], '/server/api/admin/services.php',
        '/server/api/admin/logs.php?type=admin', '/server/api/admin/platform_logs.php', '/server/api/admin/emergency.php?uuid=' . $u1['uuid'],
        '/server/api/admin/account_links.php') as $p) {
        $r = ep_http($B, 'GET', $p, null, $sa);
        assert_true(in_array($r['status'], array(200, 400, 404, 405), true), $p . ' answered ' . $r['status']);
        assert_true(strpos($r['body'], 'ev1$') === false, $p . ' carries no ev');
        foreach ($needles as $n) {
            assert_true(strpos($r['body'], $n) === false, $p . ' carries no ev/mac');
        }
    }
    // 감사: issue/revoke 행은 있고 값은 없다
    $a     = ep_q($B, 'SELECT action, after_json FROM app_ulogin_admin_logs WHERE action IN (?, ?)', array('emergency.issue', 'emergency.revoke'));
    $kinds = array();
    foreach ($a as $row) {
        $kinds[$row['action']] = true;
        assert_true(strpos((string) $row['after_json'], 'ev1') === false, 'audit row has no value');
    }
    assert_true(isset($kinds['emergency.issue']) && isset($kinds['emergency.revoke']), 'audit has issue and revoke rows');
    $pa = ep_q($B, 'SELECT * FROM app_ulogin_plat_audit WHERE action IN (?, ?) LIMIT 3', array('emergency.issue', 'emergency.revoke'));
    assert_true(count($pa) > 0, 'audit chain rows exist');
    assert_true(strpos(json_encode($pa), 'ev1$') === false, 'audit chain rows have no value');
}

function em_sc_policy(array $B): void
{
    foreach (array('Password123!', 'password', 'Qwerty!234567', 'admin1234567', 'aaaaaaaaaaaa', 'Welcome2024!!', 'iloveyou1234') as $p) {
        assert_true(em_pw_is_common($p, 'someone'), 'common: ' . $p);
    }
    foreach (array('Zx9-quill-Tempo-47', 'correct-horse-battery-9', 'Tr0ub4dor&3-xylem') as $p) {
        assert_true(!em_pw_is_common($p, 'someone'), 'not common: ' . $p);
    }
    $ad  = em_user($B, 'em_adm1', 'admin');
    $bad = array('Abcdefgh1!x' => 'too short (11)', 'Password12345!' => 'common word', 'qwerty123456!' => 'common word 2');
    foreach ($bad as $pw => $why) {
        $tok = ep_tok($B, $ad['id'], null);
        ep_err(ep_http($B, 'PUT', '/server/api/me/password.php', array('current_password' => EP_PW, 'password' => $pw, 'password_confirm' => $pw), $tok),
            400, 'VALIDATION_FAILED', 'admin policy: ' . $why, 'password');
    }
    $tok = ep_tok($B, $ad['id'], null);
    $ok  = 'Zx9-quill-Tempo-47';
    ep_ok(ep_http($B, 'PUT', '/server/api/me/password.php', array('current_password' => EP_PW, 'password' => $ok, 'password_confirm' => $ok), $tok), 'admin strong pw accepted');
    // 일반 사용자(rank 0)는 기존 정책 그대로(8자 허용)
    $us    = em_user($B, 'em_plain1', 'user');
    $tok   = ep_tok($B, $us['id'], null);
    $short = 'Ab3dEf7h';
    ep_ok(ep_http($B, 'PUT', '/server/api/me/password.php', array('current_password' => EP_PW, 'password' => $short, 'password_confirm' => $short), $tok), 'plain user keeps the old policy');
    // 기존 약한 비밀번호의 로그인은 막지 않는다
    em_user($B, 'em_weakadm', 'admin');
    ep_ok(em_login($B, 'em_weakadm'), 'existing weak admin password can still log in');
}

// =====================================================================
// 케이스
// =====================================================================

$EM_MAIN = null;
$EM_GET  = function () use (&$EM_MAIN) {
    if ($EM_MAIN === null) {
        $EM_MAIN = ep_box('main');
    }
    return $EM_MAIN;
};
t('issue: formula (fixed vector + independent), conditions (not linked / no access / allow off / disabled), fields, 30 days, dedupe', function () use ($EM_GET, &$EM_MAIN) {
    $EM_MAIN = em_sc_issue($EM_GET());
});
t('revoke: password change then re-issue with the new password and per-service days', function () use ($EM_GET) {
    em_sc_pwchange($EM_GET());
});
t('revoke paths: reset, suspend, admin reset, withdraw, hard delete, deny grant, rule change, allow off, role change, force revoke', function () use ($EM_GET) {
    em_sc_paths($EM_GET());
});
t('no ev in logs, audit, admin responses, php error log or DB bytes; stored sealed', function () use ($EM_GET) {
    em_sc_leak($EM_GET());
});
t('password policy: role_rank >= 20 needs 12+ chars and no common password; plain users unchanged; existing passwords still log in', function () use ($EM_GET) {
    em_sc_policy($EM_GET());
});

function em_mutant(string $key, array $mut, callable $scenario): void
{
    $B    = ep_box('mut_' . $key, $mut);
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

t('mutant E1: service id dropped from the mac message', function () {
    em_mutant('e1', array(array('server/core/emergency.php', "EM_PREFIX . \$serviceId . '|' . \$uuid", "EM_PREFIX . \$uuid")),
        function ($B) {
            em_sc_issue($B);
        });
});
t('mutant E2: no revoke on password change', function () {
    em_mutant('e2', array(array('server/api/me/password.php', "    em_revoke_user(\$userId, 'password_changed');", "    // removed")),
        function ($B) {
            em_sc_pwchange($B);
        });
});

// =====================================================================
// 보안 감사 수정(D-365): H-1 · M-1 · M-2 · M-3 · L
// =====================================================================

/** H-1: 계산 뒤·저장 전에 비밀번호가 바뀌었거나 정지됐으면 옛 비밀번호의 값을 내지 않는다. */
function em_sc_h1(array $B): void
{
    // (a) 비밀번호가 PBKDF2 계산 뒤 verify 전에 바뀜 -> verify 가 버린다
    $u = em_user($B, 'em_h1a');
    em_link($B, $u, 'svc-a');
    $code = '$u = db_one("SELECT * FROM " . T_USERS . " WHERE id = ?", [' . $u['id'] . ']);'
        . '$plan = em_issue_prepare($u, true); $plan["dk"] = em_dk("' . EP_PW . '", $plan["salt"]);'
        . 'db_exec("UPDATE " . T_USERS . " SET password_hash = ?, password_changed_at = ? WHERE id = ?", [password_hash("Other-Pass-9918x", PASSWORD_DEFAULT), gmdate("Y-m-d H:i:s", time() + 2), ' . $u['id'] . ']);'
        . '$pca = em_issue_verify($plan, "' . EP_PW . '"); echo $pca === null ? "DROP" : "PASS:" . em_issue_store($plan, $pca);';
    list($rc, $out) = ep_cli($B, $code);
    assert_true(strpos((string) $out, 'DROP') !== false, 'H-1a: stale password dropped at verify (rc ' . $rc . ')');
    assert_eq(0, count(ep_q($B, 'SELECT id FROM app_ulogin_emergency WHERE uuid = ?', array($u['uuid']))), 'H-1a: no row stored');

    // (b) verify 뒤·저장 트랜잭션 전에 정지 -> 트랜잭션 안 재확인이 버린다
    $u2 = em_user($B, 'em_h1b');
    em_link($B, $u2, 'svc-a');
    $code = '$u = db_one("SELECT * FROM " . T_USERS . " WHERE id = ?", [' . $u2['id'] . ']);'
        . '$plan = em_issue_prepare($u, true); $plan["dk"] = em_dk("' . EP_PW . '", $plan["salt"]);'
        . '$pca = em_issue_verify($plan, "' . EP_PW . '"); if ($pca === null) { echo "VERIFY-FAIL"; exit; }'
        . 'db_exec("UPDATE " . T_USERS . " SET status = ? WHERE id = ?", ["suspended", ' . $u2['id'] . ']);'
        . 'echo "STORED:" . em_issue_store($plan, $pca);';
    list($rc, $out) = ep_cli($B, $code);
    assert_true(strpos((string) $out, 'STORED:0') !== false, 'H-1b: suspended between verify and store -> discarded (rc ' . $rc . ')');
    assert_eq(0, count(ep_q($B, 'SELECT id FROM app_ulogin_emergency WHERE uuid = ?', array($u2['uuid']))), 'H-1b: no row stored');

    // (c) v1.9.2: password_changed_at 은 그대로(같은 초에 두 번 바뀐 경우)인데 password_epoch 만 +1 -> 그래도 버린다
    $u3 = em_user($B, 'em_h1c');
    em_link($B, $u3, 'svc-a');
    $code = '$u = db_one("SELECT * FROM " . T_USERS . " WHERE id = ?", [' . $u3['id'] . ']);'
        . '$plan = em_issue_prepare($u, true); $plan["dk"] = em_dk("' . EP_PW . '", $plan["salt"]);'
        . '$pca = em_issue_verify($plan, "' . EP_PW . '");'
        . 'db_exec("UPDATE " . T_USERS . " SET password_epoch = password_epoch + 1 WHERE id = ?", [' . $u3['id'] . ']);'
        . 'echo "STORED:" . em_issue_store($plan, (string) $pca);';
    list($rc, $out) = ep_cli($B, $code);
    assert_true(strpos((string) $out, 'STORED:0') !== false, 'H-1c: password_epoch moved with an unchanged timestamp -> discarded (rc ' . $rc . ')');

    // 정상 경로는 그대로 발급한다
    $u4 = em_user($B, 'em_h1d');
    em_link($B, $u4, 'svc-a');
    list($rc, $out) = ep_cli($B, '$u = db_one("SELECT * FROM " . T_USERS . " WHERE id = ?", [' . $u4['id'] . ']); echo "N:" . em_issue_for_user($u, "' . EP_PW . '", true);');
    assert_true(strpos((string) $out, 'N:1') !== false, 'H-1d: undisturbed issue still stores (rc ' . $rc . ')');
}

/** M-1: 서비스 삭제가 emergency 행을 지운다(같은 id 재등록 시 옛 값이 나가지 않는다). */
function em_sc_m1(array $B): void
{
    em_service($B, 'svc-del');
    $u = em_user($B, 'em_m1');
    em_link($B, $u, 'svc-del');
    $d = ep_ok(em_login($B, 'em_m1'), 'login');
    assert_true(count(ep_q($B, 'SELECT id FROM app_ulogin_emergency WHERE service_id = ?', array('svc-del'))) > 0, 'M-1: rows exist before delete');
    $r = ep_http($B, 'DELETE', '/server/api/admin/services.php?service_id=svc-del&force=1&confirm=svc-del&reason=t', null, $B['fx']['tok']['sa']);
    ep_ok($r, 'delete service');
    assert_eq(0, count(ep_q($B, 'SELECT id FROM app_ulogin_emergency WHERE service_id = ?', array('svc-del'))), 'M-1: emergency rows purged with the service');
}

/** M-2: 성공한 비밀번호 변경은 계정당 시간당 10회. v1.9.2: 틀린 값은 세션별 별도 버킷 — 한 세션의 실패가 본인의 변경을 막지 않는다. */
function em_sc_m2(array $B): void
{
    $u   = em_user($B, 'em_m2');
    $tok = ep_tok($B, $u['id'], null);
    $pw  = EP_PW;
    for ($i = 1; $i <= 10; $i++) {
        $new = 'Zx9-quill-Tempo-' . (40 + $i);
        $r = ep_http($B, 'PUT', '/server/api/me/password.php', array('current_password' => $pw, 'password' => $new, 'password_confirm' => $new), $tok);
        ep_ok($r, 'change ' . $i);
        $pw = $new;
    }
    ep_err(ep_http($B, 'PUT', '/server/api/me/password.php', array('current_password' => $pw, 'password' => 'Zx9-quill-Tempo-99', 'password_confirm' => 'Zx9-quill-Tempo-99'), $tok),
        429, 'RATE_LIMITED', 'change 11 is limited');
}

/** v1.9.2 L-2: 틀린 현재 비밀번호 10회는 그 세션만 막는다. 본인의 다른 세션은 성공 변경을 할 수 있다. */
function em_sc_pwfail(array $B): void
{
    $u    = em_user($B, 'em_pwf');
    $evil = ep_tok($B, $u['id'], null);
    $own  = ep_tok($B, $u['id'], null);
    $bad  = array('current_password' => 'wrong-Password-1!', 'password' => 'Zx9-quill-Tempo-47', 'password_confirm' => 'Zx9-quill-Tempo-47');
    for ($i = 1; $i <= 10; $i++) {
        ep_err(ep_http($B, 'PUT', '/server/api/me/password.php', $bad, $evil), 400, 'AUTH_PASSWORD_MISMATCH', 'wrong ' . $i);
    }
    ep_err(ep_http($B, 'PUT', '/server/api/me/password.php', $bad, $evil), 429, 'RATE_LIMITED', 'wrong 11 limited for that session');
    ep_err(ep_http($B, 'PUT', '/server/api/me/password.php',
        array('current_password' => EP_PW, 'password' => 'Zx9-quill-Tempo-47', 'password_confirm' => 'Zx9-quill-Tempo-47'), $evil),
        429, 'RATE_LIMITED', 'even the right password is refused on the exhausted session');
    ep_ok(ep_http($B, 'PUT', '/server/api/me/password.php',
        array('current_password' => EP_PW, 'password' => 'Zx9-quill-Tempo-47', 'password_confirm' => 'Zx9-quill-Tempo-47'), $own), 'owner other session still changes');
}

/** v1.9.2 L-3: sweep 은 커서를 저장해 호출마다 이어서 본다 — 한 호출 상한, 한 바퀴를 모두 훑고 0 으로. */
function em_sc_cursor(array $B): void
{
    $top = (int) ep_q($B, 'SELECT COALESCE(MAX(id), 0) AS m FROM app_ulogin_emergency')[0]['m'];
    $ids = array();
    foreach (array('em_cu1', 'em_cu2', 'em_cu3') as $nm) {
        list($u, $bearer, $cur) = em_ready($B, $nm);
        $ids[] = $u;
    }
    ep_x($B, 'DELETE FROM app_ulogin_settings WHERE skey = ?', array('em.sweep_cursor'));
    ep_x($B, 'INSERT INTO app_ulogin_settings (skey, svalue, vtype, updated_at) VALUES (?,?,?,?)', array('em.sweep_cursor', (string) $top, 'int', ep_now()));   // 내 행부터 훑는다
    // 마지막 사용자만 사건 없이 자격 상실 -> 한 바퀴 안에서 정확히 1건 폐기돼야 한다(상한 1 로 세 번 호출)
    ep_x($B, 'UPDATE app_ulogin_users SET status = ? WHERE id = ?', array('suspended', $ids[2]['id']));
    $code = '$o = []; for ($i = 0; $i < 3; $i++) { $n = em_reconcile_sweep(1);'
        . ' $o[] = $n . "/" . (int) db_val("SELECT svalue FROM " . T_SETTINGS . " WHERE skey = ?", [EM_SWEEP_CURSOR_KEY]); }'
        . ' $n = em_reconcile_sweep(1); $o[] = $n . "/" . (int) db_val("SELECT svalue FROM " . T_SETTINGS . " WHERE skey = ?", [EM_SWEEP_CURSOR_KEY]);'
        . ' echo "R:" . implode(",", $o);';
    list($rc, $out) = ep_cli($B, $code);
    $m = array();
    assert_true(preg_match('/R:(\d+)\/(\d+),(\d+)\/(\d+),(\d+)\/(\d+),(\d+)\/(\d+)/', (string) $out, $m) === 1, 'cursor probe: ' . substr((string) $out, 0, 200) . ' rc ' . $rc);
    $c1 = (int) $m[2];
    $c2 = (int) $m[4];
    $c3 = (int) $m[6];
    assert_true($c1 > $top && $c2 > $c1 && $c3 > $c2, 'cursor strictly advances each call: ' . $c1 . ',' . $c2 . ',' . $c3);
    assert_eq(1, (int) $m[1] + (int) $m[3] + (int) $m[5], 'each live row is visited once in a lap -> exactly 1 revoked');
    assert_eq(0, (int) $m[8], 'after the last row the cursor wraps to 0');
}

/** M-3: 사건 없이 자격을 잃은 대상을 보존 job(sweep)이 폐기한다. */
function em_sc_m3(array $B): void
{
    list($u, $bearer, $cur) = em_ready($B, 'em_m3');
    ep_x($B, 'UPDATE app_ulogin_users SET status = ? WHERE id = ?', array('suspended', $u['id']));   // 사건 없이 자격 상실
    list($rc, $out) = ep_cli($B, 'echo "N:" . em_reconcile_sweep();');
    assert_true(strpos((string) $out, 'N:1') !== false, 'M-3: sweep revoked one (rc ' . $rc . ' out ' . substr((string) $out, 0, 120) . ')');
    em_revoked($B, 'svc-a', $u['uuid'], $cur, 'suspended', 'sweep');
    list($rc, $out) = ep_cli($B, 'echo "N:" . em_reconcile_sweep();');
    assert_true(strpos((string) $out, 'N:0') !== false, 'M-3: second sweep is a no-op');
    // gc 가 sweep 을 부른다(연결 고리)
    $src = (string) file_get_contents(h_root() . '/server/core/emergency.php');
    assert_true(strpos($src, "    em_reconcile_sweep();\n}") !== false, 'em_gc calls the sweep');
    assert_true(strpos((string) file_get_contents(h_root() . '/server/core/accountlink.php'), 'em_gc();') !== false, 'al_gc calls em_gc');
}

/** L: 반복·키보드 패턴·단어 덧붙임. */
function em_sc_lowpw(array $B): void
{
    foreach (array('Password!Password!', 'letmeinletmein', 'Abcdef1234!Abcdef1234!', '1q2w3e4r5t6y', 'qwertyuiop12', 'Qwerty!2345678', 'Summer2024!x', 'zxcvbnm98765') as $p) {
        assert_true(em_pw_is_common($p, 'someone'), 'rejected: ' . $p);
    }
    $ad = em_user($B, 'em_lowadm', 'admin');
    foreach (array('Password!Password!', '1q2w3e4r5t6y', 'letmeinletmein') as $pw) {
        $tok = ep_tok($B, $ad['id'], null);
        ep_err(ep_http($B, 'PUT', '/server/api/me/password.php', array('current_password' => EP_PW, 'password' => $pw, 'password_confirm' => $pw), $tok),
            400, 'VALIDATION_FAILED', 'admin policy L: ' . $pw, 'password');
    }
}

t('H-1: password changed or account suspended after the slow computation -> value discarded (verify + in-transaction recheck)', function () use ($EM_GET) {
    em_sc_h1($EM_GET());
});
t('M-1: deleting a service purges its emergency rows', function () use ($EM_GET) {
    em_sc_m1($EM_GET());
});
t('M-2: password change limited to 10 per hour per account', function () use ($EM_GET) {
    em_sc_m2($EM_GET());
});
t('L-2: wrong current passwords are limited per session, not per account', function () use ($EM_GET) {
    em_sc_pwfail($EM_GET());
});
t('L-3: sweep keeps a cursor and finishes a lap across calls', function () use ($EM_GET) {
    em_sc_cursor($EM_GET());
});
t('M-3: retention sweep revokes values whose eligibility vanished without an event', function () use ($EM_GET) {
    em_sc_m3($EM_GET());
});
t('L: repeated word, keyboard runs and word+suffix rejected for rank >= 20', function () use ($EM_GET) {
    em_sc_lowpw($EM_GET());
});

t('mutant H1a: password re-verify removed', function () {
    em_mutant('h1a', array(array('server/core/emergency.php', "|| !password_verify(\$password, (string) \$cur['password_hash'])) {", ") {")),
        function ($B) {
            em_sc_h1($B);
        });
});
t('mutant H1b: in-transaction recheck removed', function () {
    em_mutant('h1b', array(array('server/core/emergency.php', "if (\$c2 === null || (string) (int) \$c2['password_epoch'] !== \$pca   // TXCHECK", "if (false   // TXCHECK")),
        function ($B) {
            em_sc_h1($B);
        });
});
t('mutant M1: purge line removed', function () {
    em_mutant('m1', array(array('server/core/accountlink.php', "    db_exec('DELETE FROM ' . T_EMERGENCY . ' WHERE service_id = ?', [\$sid]);   // PURGE-EMERGENCY", "    // removed")),
        function ($B) {
            em_sc_m1($B);
        });
});
t('mutant M2: password-change rate limit removed', function () {
    em_mutant('m2', array(array('server/api/me/password.php', "rate_limit('pwchg:u:' . \$userId, 10, 3600);   // PWCHG-LIMIT", "// removed")),
        function ($B) {
            em_sc_m2($B);
        });
});
t('mutant L1: txcheck on epoch removed (same-second change)', function () {
    em_mutant('l1', array(array('server/core/emergency.php', "(string) (int) \$c2['password_epoch'] !== \$pca   // TXCHECK", "false   // TXCHECK")),
        function ($B) {
            em_sc_h1($B);
        });
});
t('mutant L2: fail bucket peek removed', function () {
    em_mutant('l2', array(array('server/api/me/password.php', "rate_limit_peek('pwchg:fail:s:' . \$sessionId, 10, 3600);   // PWCHG-FAIL-PEEK", "// removed")),
        function ($B) {
            em_sc_pwfail($B);
        });
});
t('mutant L2b: success bucket removed', function () {
    em_mutant('l2b', array(array('server/api/me/password.php', "rate_limit('pwchg:u:' . \$userId, 10, 3600);   // PWCHG-LIMIT", "// removed")),
        function ($B) {
            em_sc_m2($B);
        });
});
t('mutant L3: cursor never saved', function () {
    em_mutant('l3', array(array('server/core/emergency.php', "if (\$next !== \$cursor) {", "if (false) {")),
        function ($B) {
            em_sc_cursor($B);
        });
});
t('mutant M3: sweep never revokes', function () {
    em_mutant('m3', array(array('server/core/emergency.php', "if (!\$ok[0]) {   // SWEEP-REVOKE", "if (false) {   // SWEEP-REVOKE")),
        function ($B) {
            em_sc_m3($B);
        });
});
t('mutant L: repeat and keyboard rules removed', function () {
    em_mutant('l', array(
        array('server/core/emergency.php', "preg_match('/^(.{3,}?)\\1+\$/', \$alpha) === 1) {   // REPEAT-RULE", "false) {   // REPEAT-RULE"),
        array('server/core/emergency.php', "if (strlen(\$alnum) >= 8 && preg_match(", "if (false && preg_match("),
        array('server/core/emergency.php', "return em_pw_has_keyboard_run(\$l);   // KEYBOARD-RULE", "return false;   // KEYBOARD-RULE")),
        function ($B) {
            em_sc_lowpw($B);
        });
});
