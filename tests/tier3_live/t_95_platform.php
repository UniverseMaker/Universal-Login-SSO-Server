<?php
declare(strict_types=1);

/**
 * 티어 3 — v1.9.0 통합로그인 플랫폼 (설계 116 §12 시험 ②~⑨ + 변이 5종, D-186)
 *
 * 사본 서버(php -S, 빈 포트) + 임시 SQLite. 운영 DB·8080·3306 무접촉: 사본은 sys_get_temp_dir()/ulogin_test_pf_* 아래뿐
 * (접두 가드), 서버는 자기 PID 만 taskkill /T. glob() 미사용.
 *
 * 덮는 것: ② 규칙 행렬(read 4 × write 2 × 갈래 3) ③ 체인(변조 → verify 가 그 seq, prune 앵커 뒤 통과, 키 없는 재계산 위조 탐지)
 *          ④ ETag 304 · s2s changed:false ⑤ 커서 변조 400 ⑥ 버전 경합 409 · doc_count 상한 ⑦ 마스킹(키·이메일)
 *          ⑧ 수집 100건 p95 ⑨ 0007 SQLite apply→verify→재적용 no-op→씨앗
 *          + 접근 제한 5경로 403 + payload 층 · features · userinfo/users · 관리자 API 권한(H186-1)
 * 변이: 접근 판정 effective role · 마스킹 생략 · owner 필터 생략 · HMAC 대신 평문 sha256 · expected_version 무시
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

const PF_PW = 'pf-Test-pass-9183';
$GLOBALS['PF'] = array('boxes' => array(), 'registered' => false, 'p95' => null);

function pf_rm(string $p): void
{
    if (strpos(h_norm($p), '/ulogin_test_pf_') === false) {
        return;
    }
    pf_rm_inner($p);
}

function pf_rm_inner(string $p): void
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
            pf_rm_inner($p . '/' . $n);
        }
    }
    @rmdir($p);
}

function pf_copy(string $src, string $dst): int
{
    @mkdir($dst, 0777, true);
    $n = 0;
    foreach (scandir($src) as $e) {
        if ($e === '.' || $e === '..' || $e === 'config.php' || $e === '.install.lock' || $e === '.installed') {
            continue;
        }
        if (is_dir($src . '/' . $e)) {
            $n += pf_copy($src . '/' . $e, $dst . '/' . $e);
        } else {
            copy($src . '/' . $e, $dst . '/' . $e);
            $n++;
        }
    }
    return $n;
}

function pf_teardown(): void
{
    foreach ($GLOBALS['PF']['boxes'] as $b) {
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
    foreach ($GLOBALS['PF']['boxes'] as $b) {
        pf_rm($b['base']);
    }
    $GLOBALS['PF']['boxes'] = array();
}

function pf_ini(): string
{
    $ini = php_ini_loaded_file();
    if (!is_string($ini) || $ini === '' || !is_file($ini)) {
        skip('runner has no php.ini (-n?) — children would lack pdo_sqlite; run with -c <ini>');
    }
    return $ini;
}

function pf_free_port(): int
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

function pf_set_define(string $src, string $name, string $phpValue): string
{
    $re = '/^define\(\'' . preg_quote($name, '/') . '\',\s*[^\n]*?\);/m';
    $n = 0;
    $out = preg_replace($re, 'define(\'' . $name . '\', ' . $phpValue . ');', $src, 1, $n);
    if ($n !== 1 || !is_string($out)) {
        fail('HARNESS-FAIL config.sample.php: define(' . $name . ') not found exactly once');
    }
    return $out;
}

function pf_box(string $key, array $mut = array()): array
{
    require_ext('pdo_sqlite');
    $ini = pf_ini();
    if (!$GLOBALS['PF']['registered']) {
        $GLOBALS['PF']['registered'] = true;
        register_shutdown_function('pf_teardown');
    }
    $base = h_norm(sys_get_temp_dir()) . '/ulogin_test_pf_' . getmypid() . '_' . bin2hex(random_bytes(3)) . '_' . $key;
    $root = $base . '/root';
    $n  = pf_copy(h_root() . '/server', $root . '/server');
    $n += pf_copy(h_root() . '/public', $root . '/public');
    @mkdir($root . '/tools', 0777, true);
    copy(h_root() . '/tools/router-ulogin.php', $root . '/tools/router-ulogin.php');
    $B = array('base' => $base, 'root' => $root, 'key' => $key, 'pid' => 0, 'proc' => null, 'port' => 0);
    $GLOBALS['PF']['boxes'][] = &$B;
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
    $port = pf_free_port();
    $B['port'] = $port;
    $cfg = (string) file_get_contents(h_root() . '/server/config.sample.php');
    $cfg = pf_set_define($cfg, 'DB_DRIVER', "'sqlite'");
    $cfg = pf_set_define($cfg, 'DB_SQLITE_PATH', var_export($db, true));
    $B['enc'] = bin2hex(random_bytes(32));
    $cfg = pf_set_define($cfg, 'SECRET_ENC_KEY', "'" . $B['enc'] . "'");
    $cfg = pf_set_define($cfg, 'APP_KEY', "'" . bin2hex(random_bytes(32)) . "'");
    $cfg = pf_set_define($cfg, 'FORCE_HTTPS', 'false');
    $cfg = pf_set_define($cfg, 'COOKIE_SECURE', 'false');
    $cfg = pf_set_define($cfg, 'BASE_URL', "'http://127.0.0.1:" . $port . "'");
    // 무음 SSO 는 https 판정이 필요하다 — 루프백을 신뢰 프록시로 두고 그 한 요청만 X-Forwarded-Proto 를 싣는다
    $cfg = pf_set_define($cfg, 'TRUST_PROXY', 'true');
    $cfg = pf_set_define($cfg, 'TRUSTED_PROXIES', "['127.0.0.1']");
    file_put_contents($root . '/server/config.php', $cfg);
    $B['fx'] = pf_seed($B);

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
        $r = pf_http($B, 'GET', '/version.txt');
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

function pf_pdo(array $B): PDO
{
    $pdo = new PDO('sqlite:' . $B['db'], null, null, array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
    $pdo->exec('PRAGMA busy_timeout = 8000');
    return $pdo;
}

function pf_q(array $B, string $sql, array $p = array()): array
{
    $pdo = pf_pdo($B);
    $st = $pdo->prepare($sql);
    $st->execute($p);
    $rows = $st->fetchAll();
    $pdo = null;
    return $rows;
}

function pf_x(array $B, string $sql, array $p = array()): int
{
    $pdo = pf_pdo($B);
    $st = $pdo->prepare($sql);
    $st->execute($p);
    $n = $st->rowCount();
    $pdo = null;
    return $n;
}

function pf_now(int $off = 0): string
{
    return gmdate('Y-m-d H:i:s', time() + $off);
}

function pf_iso(int $off = 0): string
{
    return gmdate('Y-m-d\TH:i:s\Z', time() + $off);
}

/** 원시 HTTP/1.0. $hdr = ['Name: v', …]. */
function pf_http(array $B, string $method, string $path, $body = null, ?string $bearer = null, array $hdr = array()): array
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

function pf_code(array $r): string
{
    return is_array($r['json']) && isset($r['json']['error']['code']) ? (string) $r['json']['error']['code'] : '';
}

function pf_field(array $r): ?string
{
    return is_array($r['json']) && isset($r['json']['error']['field']) ? (string) $r['json']['error']['field'] : null;
}

function pf_ok(array $r, string $what): array
{
    if ($r['status'] !== 200 || !is_array($r['json']) || $r['json']['ok'] !== true) {
        fail($what . ': expected 200 ok, got ' . $r['status'] . ' ' . substr($r['body'], 0, 400));
    }
    return is_array($r['json']['data']) ? $r['json']['data'] : array();
}

function pf_err(array $r, int $status, string $code, string $what, ?string $field = null): void
{
    if ($r['status'] !== $status || pf_code($r) !== $code) {
        fail($what . ': expected ' . $status . ' ' . $code . ', got ' . $r['status'] . ' ' . substr($r['body'], 0, 400));
    }
    if ($field !== null && pf_field($r) !== $field) {
        fail($what . ': expected field ' . $field . ', got ' . var_export(pf_field($r), true));
    }
}

function pf_token(PDO $pdo, int $uid, ?string $sid, string $type = 'token', ?string $csrf = null): string
{
    $tok = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO app_ulogin_sessions (session_id, user_id, service_id, auth_type, access_token_hash, csrf_token_hash,'
        . ' issued_at, expires_at, last_seen_at, ip, user_agent) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute(array(bin2hex(random_bytes(32)), $uid, $sid, $type, hash('sha256', $tok), $csrf === null ? null : hash('sha256', $csrf),
            pf_now(-5), pf_now(3600), pf_now(-5), '127.0.0.1', 'pf'));
    return $tok;
}

function pf_tok(array $B, int $uid, ?string $sid): string
{
    $pdo = pf_pdo($B);
    $t = pf_token($pdo, $uid, $sid);
    $pdo = null;
    return $t;
}

function pf_seed(array $B): array
{
    $pdo = pf_pdo($B);
    $now = pf_now();
    $hash = password_hash(PF_PW, PASSWORD_DEFAULT);
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
        $insU->execute(array($uuid, 'pf_' . $k, 'pf_' . $k . '@test.invalid', $hash, $k === 'u1' ? '홍길동' : null, 'active', 'basic',
            $role, 'free', $now, $now));
        $fx['u'][$k] = array('id' => (int) $pdo->lastInsertId(), 'uuid' => $uuid, 'username' => 'pf_' . $k);
    }
    // ov: 전역 user + svc-a 오버라이드 admin — 접근 판정은 전역만 본다(변이 M1 의 표적)
    $pdo->prepare('INSERT INTO app_ulogin_user_services (user_id, service_id, role, status, joined_at) VALUES (?,?,?,?,?)')
        ->execute(array($fx['u']['ov']['id'], 'svc-a', 'admin', 'active', $now));
    $fx['tok']['sa'] = pf_token($pdo, $fx['u']['sa']['id'], null);
    $fx['tok']['ad'] = pf_token($pdo, $fx['u']['ad']['id'], null);
    foreach (array('u1', 'u2', 'u3', 'ov') as $k) {
        $fx['tok'][$k] = pf_token($pdo, $fx['u'][$k]['id'], 'svc-a');
    }
    $fx['tok']['ad_svc'] = pf_token($pdo, $fx['u']['ad']['id'], 'svc-a');
    $fx['tok']['u1_self'] = pf_token($pdo, $fx['u']['u1']['id'], null);
    $pdo = null;
    return $fx;
}

function pf_reset_rl(array $B): void
{
    pf_x($B, 'DELETE FROM app_ulogin_rate_limits');
}

function pf_s2s(array $B, string $path, string $sid, array $body): array
{
    return pf_http($B, 'POST', $path, array_merge(array('service_id' => $sid, 'service_secret' => $B['fx']['sec'][$sid]), $body));
}

function pf_ev(array $over = array()): array
{
    return array_merge(array('event_id' => gen_uuid4(), 'ts' => pf_iso(-10), 'level' => 'info', 'event' => 'job.done'), $over);
}

function pf_aev(array $over = array()): array
{
    return array_merge(array('event_id' => gen_uuid4(), 'ts' => pf_iso(-10), 'actor' => array('type' => 'admin', 'local_user' => 'boss'),
        'action' => 'user.role.update', 'target' => array('type' => 'user', 'id' => 'hong'), 'result' => 'success',
        'changes' => array('role' => array('before' => 'user', 'after' => 'admin'))), $over);
}

function pf_admin(array $B, string $who, string $path, array $body): array
{
    return pf_http($B, 'POST', $path, $body, $B['fx']['tok'][$who]);
}

function pf_mutant(string $key, array $mut, callable $scenario): void
{
    $B = pf_box('mut_' . $key, $mut);
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
function pf_cli(array $B, string $code): array
{
    $f = $B['root'] . '/server/tools/_pf_' . bin2hex(random_bytes(3)) . '.php';
    file_put_contents($f, "<?php\ndeclare(strict_types=1);\nrequire __DIR__ . '/../core/bootstrap.php';\n" . $code);
    $rc = 0;
    $out = h_run(h_q(h_php_bin()) . ' -c ' . h_q(pf_ini()) . ' ' . h_q($f), $rc, 60.0);
    @unlink($f);
    return array($rc, $out);
}

// =====================================================================
// 시나리오
// =====================================================================

/** ⑦ 마스킹 · 멱등 · 거절 사유 · 한도 · 갈래 */
function pf_sc_logs(array $B): void
{
    pf_reset_rl($B);
    $e1 = pf_ev(array('data' => array('job_id' => 4, 'password' => 'hunter2', 'nested' => array('api_key' => 'x')),
        'message' => 'mail to hong@example.com failed', 'tags' => array('export', 'batch'), 'uuid' => $B['fx']['u']['u1']['uuid']));
    $e2 = pf_ev(array('level' => 'loud'));
    $e3 = pf_ev(array('ts' => pf_iso(-86400 * 20)));
    $e4 = pf_ev(array('event' => 'Bad Event'));
    $d = pf_ok(pf_s2s($B, '/server/api/platform/logs.php', 'svc-a', array('events' => array($e1, $e2, $e3, $e4))), 'logs batch');
    assert_eq(array('accepted', 'duplicate', 'truncated', 'redacted', 'rejected'), array_keys($d), 'logs response keys (§0-7)');
    assert_eq(1, $d['accepted'], 'one accepted');
    assert_eq(1, $d['redacted'], 'server redacted the item with secret keys / email');
    $reasons = array();
    foreach ($d['rejected'] as $r) {
        $reasons[$r['index']] = $r['reason'];
    }
    assert_eq(array(1 => 'invalid_level', 2 => 'ts_out_of_range', 3 => 'invalid_event'), $reasons, 'rejection reasons by index');
    $row = pf_q($B, 'SELECT * FROM app_ulogin_plat_logs WHERE event_id = ?', array($e1['event_id']))[0];
    assert_not_contains('hunter2', (string) $row['data_json'], 'password value never stored');
    assert_contains('[REDACTED]', (string) $row['data_json'], 'redaction marker stored');
    assert_not_contains('"x"', (string) $row['data_json'], 'nested api_key masked');
    assert_contains('h***@example.com', (string) $row['message'], 'email in message masked');
    assert_eq(',export,batch,', (string) $row['tags'], 'tags stored in ,a,b, form');
    // 멱등
    $d2 = pf_ok(pf_s2s($B, '/server/api/platform/logs.php', 'svc-a', array('events' => array($e1, $e1))), 'resend');
    assert_eq(0, $d2['accepted'], 'resend accepted 0');
    assert_eq(2, $d2['duplicate'], 'resend counted as duplicate (stored + in-batch)');
    // 한도·인증·갈래
    $many = array();
    for ($i = 0; $i < 101; $i++) {
        $many[] = pf_ev();
    }
    pf_err(pf_s2s($B, '/server/api/platform/logs.php', 'svc-a', array('events' => $many)), 400, 'VALIDATION_FAILED', '101 events', 'body');
    pf_err(pf_http($B, 'POST', '/server/api/platform/logs.php', array('service_id' => 'svc-a', 'service_secret' => 'wrong', 'events' => array())),
        401, 'SSO_CLIENT_AUTH_FAILED', 'bad secret', 'service_secret');
    pf_err(pf_http($B, 'POST', '/server/api/platform/logs.php', array('events' => array(pf_ev())), $B['fx']['tok']['u1']),
        403, 'FORBIDDEN', 'user branch logs off by default (H-11)');
    pf_err(pf_http($B, 'POST', '/server/api/platform/logs.php', array('service_id' => 'svc-a', 'service_secret' => $B['fx']['sec']['svc-a'],
        'events' => array()), $B['fx']['tok']['u1']), 400, 'VALIDATION_FAILED', 'bearer + secret together', 'service_secret');
    // 브라우저 수집 켜면 uuid 는 토큰 주인으로 덮인다
    pf_x($B, "UPDATE app_ulogin_services SET platform_json = '{\"logs_from_users\":true}' WHERE service_id = 'svc-a'");
    $e5 = pf_ev(array('uuid' => $B['fx']['u']['u2']['uuid']));
    $d5 = pf_ok(pf_http($B, 'POST', '/server/api/platform/logs.php', array('events' => array($e5)), $B['fx']['tok']['u1']), 'user branch on');
    assert_eq(1, $d5['accepted'], 'user log accepted');
    assert_eq($B['fx']['u']['u1']['uuid'], (string) pf_q($B, 'SELECT uuid FROM app_ulogin_plat_logs WHERE event_id = ?', array($e5['event_id']))[0]['uuid'],
        'uuid overwritten with token owner');
    pf_x($B, "UPDATE app_ulogin_services SET platform_json = NULL WHERE service_id = 'svc-a'");
    // 일일 상한
    pf_x($B, "UPDATE app_ulogin_services SET platform_json = '{\"quota\":{\"log_events\":2}}' WHERE service_id = 'svc-b'");
    pf_ok(pf_s2s($B, '/server/api/platform/logs.php', 'svc-b', array('events' => array(pf_ev(), pf_ev()))), 'within quota');
    pf_err(pf_s2s($B, '/server/api/platform/logs.php', 'svc-b', array('events' => array(pf_ev()))), 403, 'QUOTA_EXCEEDED', 'quota', 'events');
    $u = pf_q($B, "SELECT value FROM app_ulogin_plat_usage WHERE service_id = 'svc-b' AND metric = 'log_events'");
    assert_eq(2, (int) $u[0]['value'], 'usage counted with $by in one statement');
}

/** ③ 체인 · receipt · 변조 탐지 · 키 없는 위조 탐지 · user 갈래 금지 */
function pf_sc_audit(array $B): void
{
    pf_reset_rl($B);
    $a1 = pf_aev(array('changes' => array('password' => array('before' => 'old', 'after' => 'new'),
        'email' => array('before' => 'opsuser@example.com', 'after' => 'x@y.org'))));
    $a2 = pf_aev();
    $d = pf_ok(pf_s2s($B, '/server/api/platform/audit.php', 'svc-a', array('events' => array($a1, $a2))), 'audit batch');
    assert_eq(array('accepted', 'duplicate', 'redacted', 'rejected', 'receipt'), array_keys($d), 'audit response keys (§0-8)');
    assert_eq(2, $d['accepted'], 'two accepted');
    assert_eq(1, $d['redacted'], 'password change + email masked');
    assert_match('/^[0-9a-f]{64}$/', (string) $d['receipt']['hash'], 'receipt hash');
    $row = pf_q($B, 'SELECT * FROM app_ulogin_plat_audit WHERE event_id = ?', array($a1['event_id']))[0];
    assert_not_contains('"old"', (string) $row['changes_json'], 'password before never stored');
    assert_contains('o***@example.com', (string) $row['changes_json'], 'email masked');
    $last = pf_q($B, 'SELECT id, row_hash FROM app_ulogin_plat_audit ORDER BY id DESC LIMIT 1')[0];
    assert_eq((int) $last['id'], (int) $d['receipt']['seq'], 'receipt seq = last row');
    assert_eq((string) $last['row_hash'], (string) $d['receipt']['hash'], 'receipt hash = last row hash');
    $dup = pf_ok(pf_s2s($B, '/server/api/platform/audit.php', 'svc-a', array('events' => array($a2))), 'audit resend');
    assert_eq(array(0, 1), array($dup['accepted'], $dup['duplicate']), 'audit resend is duplicate');
    pf_err(pf_http($B, 'POST', '/server/api/platform/audit.php', array('events' => array(pf_aev())), $B['fx']['tok']['u1']),
        403, 'FORBIDDEN', 'audit is s2s only');
    $v = pf_ok(pf_admin($B, 'ad', '/server/api/admin/platform_logs.php', array('action' => 'verify', 'from_seq' => 1)), 'verify');
    assert_eq(array('checked', 'ok', 'first_bad_seq', 'last_seq'), array_keys($v), 'verify keys');
    assert_true($v['ok'] === true, 'chain verifies');
    // 키 없는 위조: 행 하나를 바꾸고 뒤 행 전부를 **평문 sha256** 으로 다시 잇는다 — HMAC 이면 잡혀야 한다
    $rows = pf_q($B, 'SELECT * FROM app_ulogin_plat_audit ORDER BY id ASC');
    $target = (int) $rows[0]['id'];
    $prev = (string) $rows[0]['prev_hash'];
    $pdo = pf_pdo($B);
    foreach ($rows as $r) {
        if ((int) $r['id'] === $target) {
            $r['reason'] = 'forged';
        }
        $r['prev_hash'] = $prev;
        $c = array();
        foreach (array('id', 'service_id', 'event_id', 'ts', 'received_at', 'actor_type', 'actor_uuid', 'actor_local', 'action',
            'target_type', 'target_id', 'result', 'changes_json', 'ip', 'user_agent', 'request_id', 'reason', 'sender_ip') as $k) {
            $c[$k] = $r[$k] === null ? null : (string) $r[$k];
        }
        ksort($c);
        $h = hash('sha256', $prev . "\n" . json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $pdo->prepare('UPDATE app_ulogin_plat_audit SET reason = ?, prev_hash = ?, row_hash = ? WHERE id = ?')
            ->execute(array($r['reason'], $prev, $h, (int) $r['id']));
        $prev = $h;
    }
    $pdo = null;
    $v2 = pf_ok(pf_admin($B, 'ad', '/server/api/admin/platform_logs.php', array('action' => 'verify', 'from_seq' => 1)), 'verify forged');
    assert_true($v2['ok'] === false, 'a keyless re-chain is detected');
    assert_eq($target, (int) $v2['first_bad_seq'], 'first bad seq = forged row');
}

/** ③ 단순 변조 + prune 앵커 */
function pf_sc_prune(array $B): void
{
    pf_reset_rl($B);
    $evs = array();
    for ($i = 0; $i < 6; $i++) {
        $evs[] = pf_aev();
    }
    pf_ok(pf_s2s($B, '/server/api/platform/audit.php', 'svc-b', array('events' => $evs)), 'audit 6');
    $ids = array_map(function ($r) { return (int) $r['id']; }, pf_q($B, 'SELECT id FROM app_ulogin_plat_audit ORDER BY id ASC'));
    // 앞 4행을 아주 오래된 것으로 만들고(received_at 은 체인 입력이라 해시도 다시 계산해야 하므로 CLI 로 정리만 본다)
    list($rc, $out) = pf_cli($B, '$rows = db_all("SELECT * FROM " . T_PLAT_AUDIT . " ORDER BY id ASC");'
        . '$k = audit_chain_key(); $prev = AUDIT_GENESIS; $i = 0;'
        . 'foreach ($rows as $r) { if ($i < 4) { $r["received_at"] = "2020-01-01 00:00:00"; } $r["prev_hash"] = $prev;'
        . ' $r["row_hash"] = audit_row_hash($prev, $r, $k); db_exec("UPDATE " . T_PLAT_AUDIT . " SET received_at = ?, prev_hash = ?, row_hash = ? WHERE id = ?",'
        . ' [$r["received_at"], $r["prev_hash"], $r["row_hash"], (int) $r["id"]]); $prev = $r["row_hash"]; $i++; }'
        . 'db_exec("UPDATE " . T_SETTINGS . " SET svalue = ? WHERE skey = ?", [count($rows) . ":" . $prev, AUDIT_HEAD_KEY]);'
        . '@unlink(audit_head_file()); audit_head_init();'
        . 'echo "@@PF@@" . json_encode(["before" => audit_verify(1), "pruned" => audit_prune(90), "after" => audit_verify(1)]) . "@@PF@@";');
    assert_eq(0, $rc, 'prune cli (' . substr($out, -300) . ')');
    $j = preg_match('/@@PF@@(.*?)@@PF@@/s', $out, $mm) === 1 ? json_decode($mm[1], true) : null;
    assert_true(is_array($j) && $j['before']['ok'] === true, 'chain ok before prune (' . (isset($mm[1]) ? $mm[1] : substr($out, 0, 400)) . ')');
    assert_eq(4, (int) $j['pruned'], 'four expired rows pruned');
    assert_true($j['after']['ok'] === true, 'verify continues from the prune anchor (' . json_encode($j['after']) . ')');
    $anc = pf_q($B, "SELECT changes_json FROM app_ulogin_plat_audit WHERE action = 'audit.prune'");
    assert_eq(1, count($anc), 'one prune anchor row');
    // 단순 변조 → 그 seq
    $mid = (int) pf_q($B, "SELECT MIN(id) AS m FROM app_ulogin_plat_audit")[0]['m'] + 1;
    pf_x($B, "UPDATE app_ulogin_plat_audit SET action = 'doc.delete' WHERE id = ?", array($mid));
    $v = pf_ok(pf_admin($B, 'ad', '/server/api/admin/platform_logs.php', array('action' => 'verify', 'from_seq' => 1)), 'verify tampered');
    assert_eq(array(false, $mid), array($v['ok'], $v['first_bad_seq']), 'tampered row reported');
    unset($ids);
}

/** ④ 전역 설정 — 게시·경합·ETag 304·조건·되돌리기·비밀 키 거절·권한 */
function pf_sc_config(array $B): void
{
    pf_reset_rl($B);
    $P = '/server/api/admin/platform_config.php';
    pf_err(pf_admin($B, 'ad', $P, array('action' => 'save_draft', 'scope' => '*', 'key' => 'notice.banner', 'value' => 'x')),
        403, 'FORBIDDEN', 'admin cannot write config (H-5 superadmin)');
    pf_err(pf_admin($B, 'sa', $P, array('action' => 'save_draft', 'scope' => '*', 'key' => 'ai.api_key', 'value' => 'x')),
        400, 'VALIDATION_FAILED', 'secret-looking key refused (A7)', 'key');
    pf_ok(pf_admin($B, 'sa', $P, array('action' => 'save_draft', 'scope' => '*', 'key' => 'notice.banner', 'value' => '점검 22시')), 'draft g');
    pf_ok(pf_admin($B, 'sa', $P, array('action' => 'save_draft', 'scope' => 'svc-a', 'key' => 'export.max_rows', 'value' => 5000,
        'conditions' => array(array('when' => array('role_min' => 'admin'), 'value' => 50000)))), 'draft s');
    $g = pf_ok(pf_http($B, 'GET', $P . '?scope=svc-a', null, $B['fx']['tok']['ad']), 'get scope');
    assert_eq(array('scope', 'drafts', 'published', 'versions', 'dirty'), array_keys($g), 'admin config GET keys');
    assert_true($g['dirty'] === true && $g['published'] === null, 'unpublished draft is dirty');
    // 초안은 클라이언트에 안 나간다
    $c0 = pf_ok(pf_http($B, 'GET', '/server/api/platform/remote_config.php', null, $B['fx']['tok']['u1']), 'client before publish');
    assert_eq(array(), (array) $c0['values'], 'drafts never reach clients');
    pf_ok(pf_admin($B, 'sa', $P, array('action' => 'publish', 'scope' => '*', 'expected_version' => 0)), 'publish g1');
    $p1 = pf_ok(pf_admin($B, 'sa', $P, array('action' => 'publish', 'scope' => 'svc-a', 'expected_version' => 0)), 'publish s1');
    assert_eq(1, (int) $p1['published']['version'], 'version 1');
    pf_err(pf_admin($B, 'sa', $P, array('action' => 'publish', 'scope' => 'svc-a', 'expected_version' => 0)), 409, 'CONFLICT',
        'stale expected_version', 'expected_version');
    $c1 = pf_http($B, 'GET', '/server/api/platform/remote_config.php', null, $B['fx']['tok']['u1']);
    $d1 = pf_ok($c1, 'client get');
    assert_eq(array('etag', 'versions', 'values'), array_keys($d1), 'client config keys');
    assert_eq(array('export.max_rows' => 5000, 'notice.banner' => '점검 22시'), (array) $d1['values'], 'merged values for user');
    assert_match('/Cache-Control: private, no-cache/i', $c1['head'], 'private, no-cache');
    assert_match('/ETag: "' . $d1['etag'] . '"/', $c1['head'], 'ETag header');
    $c2 = pf_http($B, 'GET', '/server/api/platform/remote_config.php', null, $B['fx']['tok']['u1'], array('If-None-Match: "' . $d1['etag'] . '"'));
    assert_eq(304, $c2['status'], 'If-None-Match -> 304');
    assert_eq('', $c2['body'], '304 has no body');
    $dA = pf_ok(pf_http($B, 'GET', '/server/api/platform/remote_config.php', null, $B['fx']['tok']['ad_svc']), 'admin user get');
    assert_eq(50000, $dA['values']['export.max_rows'], 'role_min condition uses global role');
    $dOv = pf_ok(pf_http($B, 'GET', '/server/api/platform/remote_config.php', null, $B['fx']['tok']['ov']), 'override user get');
    assert_eq(5000, $dOv['values']['export.max_rows'], 'service override admin does not satisfy role_min');
    // s2s
    $s1 = pf_ok(pf_s2s($B, '/server/api/platform/remote_config.php', 'svc-a', array()), 's2s');
    assert_eq(array('changed', 'etag', 'versions', 'params'), array_keys($s1), 's2s changed keys');
    $s2 = pf_ok(pf_s2s($B, '/server/api/platform/remote_config.php', 'svc-a', array('if_etag' => $s1['etag'])), 's2s same');
    assert_eq(array('changed' => false, 'etag' => $s1['etag']), $s2, 's2s changed:false');
    $s3 = pf_ok(pf_s2s($B, '/server/api/platform/remote_config.php', 'svc-a', array('uuid' => $B['fx']['u']['ad']['uuid'])), 's2s uuid');
    assert_eq(50000, $s3['values']['export.max_rows'], 's2s per-user values');
    // 되돌리기
    pf_ok(pf_admin($B, 'sa', $P, array('action' => 'save_draft', 'scope' => 'svc-a', 'key' => 'export.max_rows', 'value' => 10)), 'draft 2');
    pf_ok(pf_admin($B, 'sa', $P, array('action' => 'publish', 'scope' => 'svc-a', 'expected_version' => 1)), 'publish 2');
    $rb = pf_ok(pf_admin($B, 'sa', $P, array('action' => 'rollback', 'scope' => 'svc-a', 'to_version' => 1)), 'rollback');
    assert_eq(3, (int) $rb['published']['version'], 'rollback is a new version');
    $df = pf_ok(pf_http($B, 'GET', $P . '?scope=svc-a&diff=2', null, $B['fx']['tok']['ad']), 'diff');
    assert_eq(array('added', 'removed', 'changed'), array_keys($df), 'diff keys');
    assert_eq('export.max_rows', $df['changed'][0]['key'] ?? null, 'diff shows the reverted key');
    $d3 = pf_ok(pf_http($B, 'GET', '/server/api/platform/remote_config.php', null, $B['fx']['tok']['u1']), 'after rollback');
    assert_eq(5000, $d3['values']['export.max_rows'], 'rolled back value');
    assert_ne($d1['etag'], $d3['etag'], 'etag moved with version');
    // 서비스 토큰은 관리자 API 에서 user (H186-1)
    pf_err(pf_http($B, 'GET', $P . '?scope=*', null, $B['fx']['tok']['ad_svc']), 403, 'FORBIDDEN', 'service token on admin API');
    pf_err(pf_http($B, 'GET', '/server/api/platform/remote_config.php', null, $B['fx']['tok']['u1_self']), 403, 'FORBIDDEN', 'ulogin self session on platform');
}

/** ② 규칙 행렬 · ⑤ 커서 · ⑥ 버전 경합 · 상한 */
function pf_sc_docs(array $B): void
{
    pf_reset_rl($B);
    $D = '/server/api/platform/docs.php';
    $C = '/server/api/platform/collections.php';
    $up = pf_ok(pf_s2s($B, $C, 'svc-a', array('op' => 'upsert', 'name' => 'notes', 'read_rule' => 'owner', 'write_rule' => 'owner',
        'indexes' => array(array('field' => 'status', 'type' => 'str'), array('field' => 'due', 'type' => 'int')), 'max_docs' => 6)), 'upsert');
    assert_eq(array('collection'), array_keys($up), 'collections upsert keys');
    assert_eq(array('name', 'read_rule', 'write_rule', 'indexes', 'max_docs', 'max_doc_bytes', 'doc_count', 'reindex_pending'),
        array_keys($up['collection']), 'collection keys');
    pf_err(pf_http($B, 'POST', $C, array('op' => 'list'), $B['fx']['tok']['u1']), 403, 'FORBIDDEN', 'collections user branch');
    $u1 = $B['fx']['tok']['u1'];
    $u2 = $B['fx']['tok']['u2'];
    $mk = function (string $tok, array $data) use ($B, $D) {
        return pf_ok(pf_http($B, 'POST', $D, array('op' => 'create', 'collection' => 'notes', 'data' => $data,
            'owner_uuid' => '00000000-0000-4000-8000-000000000000'), $tok), 'create')['doc'];
    };
    $a = $mk($u1, array('title' => 'a', 'status' => 'open', 'due' => 20261120));
    assert_eq(array('doc_id', 'version', 'owner_uuid', 'data', 'created_at', 'updated_at'), array_keys($a), 'doc keys');
    assert_eq($B['fx']['u']['u1']['uuid'], $a['owner_uuid'], 'user create forces owner = self');
    for ($i = 1; $i <= 4; $i++) {
        $mk($u1, array('title' => 'n' . $i, 'status' => 'open', 'due' => 20261100 + $i));
    }
    pf_err(pf_http($B, 'POST', $D, array('op' => 'get', 'collection' => 'notes', 'doc_id' => $a['doc_id']), $u2), 404, 'NOT_FOUND',
        'other user cannot read owner doc');
    pf_err(pf_http($B, 'POST', $D, array('op' => 'merge', 'collection' => 'notes', 'doc_id' => $a['doc_id'], 'data' => array('x' => 1),
        'expected_version' => 1), $u2), 404, 'NOT_FOUND', 'other user cannot write owner doc');
    $q2 = pf_ok(pf_http($B, 'POST', $D, array('op' => 'query', 'collection' => 'notes', 'where' => array()), $u2), 'u2 query');
    assert_eq(0, count($q2['items']), 'owner filter forced: u2 sees none of u1');
    $q2b = pf_ok(pf_http($B, 'POST', $D, array('op' => 'query', 'collection' => 'notes',
        'where' => array(array('field' => 'owner_uuid', 'op' => '==', 'value' => $B['fx']['u']['u1']['uuid']))), $u2), 'u2 query owner u1');
    assert_eq(0, count($q2b['items']), 'asking for u1 explicitly still returns nothing');
    $qs = pf_ok(pf_s2s($B, $D, 'svc-a', array('op' => 'query', 'collection' => 'notes')), 's2s query');
    assert_eq(5, count($qs['items']), 's2s sees all');
    // 커서 페이지
    $seen = array();
    $cur = null;
    for ($pg = 0; $pg < 5; $pg++) {
        $q = pf_ok(pf_http($B, 'POST', $D, array('op' => 'query', 'collection' => 'notes', 'where' => array(
            array('field' => 'status', 'op' => '==', 'value' => 'open'), array('field' => 'due', 'op' => '>=', 'value' => 20261101)),
            'order' => array('field' => 'due', 'dir' => 'asc'), 'limit' => 2, 'cursor' => $cur), $u1), 'page ' . $pg);
        assert_eq(array('items', 'next_cursor'), array_keys($q), 'query keys');
        foreach ($q['items'] as $it) {
            $seen[] = (int) $it['data']['due'];
        }
        $cur = $q['next_cursor'];
        if ($cur === null) {
            break;
        }
    }
    assert_eq(array(20261101, 20261102, 20261103, 20261104, 20261120), $seen, 'cursor pages in order, no dup/gap');
    $q = pf_ok(pf_http($B, 'POST', $D, array('op' => 'query', 'collection' => 'notes', 'order' => array('field' => 'due', 'dir' => 'desc'),
        'limit' => 2), $u1), 'desc');
    $raw = json_decode(base64_decode(strtr((string) $q['next_cursor'], '-_', '+/')), true);
    $raw['h'] = str_repeat('0', 16);
    $bad = rtrim(strtr(base64_encode((string) json_encode($raw)), '+/', '-_'), '=');
    pf_err(pf_http($B, 'POST', $D, array('op' => 'query', 'collection' => 'notes', 'order' => array('field' => 'due', 'dir' => 'desc'),
        'cursor' => $bad), $u1), 400, 'VALIDATION_FAILED', 'tampered cursor', 'cursor');
    pf_err(pf_http($B, 'POST', $D, array('op' => 'query', 'collection' => 'notes', 'order' => array('field' => 'due', 'dir' => 'asc'),
        'cursor' => (string) $q['next_cursor']), $u1), 400, 'VALIDATION_FAILED', 'cursor of another query', 'cursor');
    pf_err(pf_http($B, 'POST', $D, array('op' => 'query', 'collection' => 'notes', 'where' => array(
        array('field' => 'title', 'op' => '==', 'value' => 'a'))), $u1), 400, 'VALIDATION_FAILED', 'non-indexed field', 'where');
    // ⑥ 낙관적 잠금
    pf_err(pf_http($B, 'POST', $D, array('op' => 'set', 'collection' => 'notes', 'doc_id' => $a['doc_id'], 'data' => array('t' => 1)), $u1),
        400, 'VALIDATION_FAILED', 'expected_version required', 'expected_version');
    $m = pf_ok(pf_http($B, 'POST', $D, array('op' => 'merge', 'collection' => 'notes', 'doc_id' => $a['doc_id'],
        'data' => array('title' => null, 'tag' => 'x'), 'expected_version' => 1), $u1), 'merge')['doc'];
    assert_eq(2, $m['version'], 'version bumped');
    assert_false(array_key_exists('title', (array) $m['data']), 'merge null deletes key');
    pf_err(pf_http($B, 'POST', $D, array('op' => 'set', 'collection' => 'notes', 'doc_id' => $a['doc_id'], 'data' => array('t' => 1),
        'expected_version' => 1), $u1), 409, 'CONFLICT', 'stale version', 'expected_version');
    // 상한 (max_docs=6, 5개 있음)
    $mk($u1, array('status' => 'x'));
    pf_err(pf_http($B, 'POST', $D, array('op' => 'create', 'collection' => 'notes', 'data' => array()), $u1), 403, 'QUOTA_EXCEEDED', 'max_docs');
    assert_eq(6, (int) pf_q($B, "SELECT doc_count FROM app_ulogin_plat_collections WHERE name = 'notes'")[0]['doc_count'], 'doc_count exact');
    pf_err(pf_http($B, 'POST', $D, array('op' => 'create', 'collection' => 'notes', 'data' => array('password' => 'p')), $u1),
        400, 'VALIDATION_FAILED', 'secret key in doc', 'data');
    pf_ok(pf_http($B, 'POST', $D, array('op' => 'delete', 'collection' => 'notes', 'doc_id' => $a['doc_id'], 'expected_version' => 2), $u1), 'del');
    assert_eq(5, (int) pf_q($B, "SELECT doc_count FROM app_ulogin_plat_collections WHERE name = 'notes'")[0]['doc_count'], 'count after delete');

    // ② 규칙 행렬: read 4 × 갈래 3 (get) · write 2 × 갈래 3 (create)
    $expectRead = array(  // [service, user, anon]
        'service' => array(200, 403, 401), 'owner' => array(200, 200, 401), 'auth' => array(200, 200, 401), 'public' => array(200, 200, 200));
    foreach ($expectRead as $rr => $want) {
        foreach (array('service', 'owner') as $wr) {
            $name = 'm_' . $rr . '_' . $wr;
            if ($rr === 'service' && $wr === 'owner') {
                // M190-2 — 주인이 쓰는데 못 읽는 조합은 만들지 않는다
                pf_err(pf_s2s($B, $C, 'svc-b', array('op' => 'upsert', 'name' => $name, 'read_rule' => $rr, 'write_rule' => $wr)),
                    400, 'VALIDATION_FAILED', 'read=service + write=owner refused (M190-2)', 'read_rule');
                continue;
            }
            pf_ok(pf_s2s($B, $C, 'svc-b', array('op' => 'upsert', 'name' => $name, 'read_rule' => $rr, 'write_rule' => $wr)), 'upsert ' . $name);
            $doc = pf_ok(pf_s2s($B, $D, 'svc-b', array('op' => 'create', 'collection' => $name, 'data' => array('v' => 1),
                'owner_uuid' => $B['fx']['u']['u3']['uuid'])), 'seed ' . $name)['doc'];
            $tokB = pf_tok($B, $B['fx']['u']['u3']['id'], 'svc-b');
            $got = array(
                pf_s2s($B, $D, 'svc-b', array('op' => 'get', 'collection' => $name, 'doc_id' => $doc['doc_id']))['status'],
                pf_http($B, 'POST', $D, array('op' => 'get', 'collection' => $name, 'doc_id' => $doc['doc_id']), $tokB)['status'],
                pf_http($B, 'POST', $D, array('service_id' => 'svc-b', 'op' => 'get', 'collection' => $name, 'doc_id' => $doc['doc_id']))['status'],
            );
            assert_eq($want, $got, 'read matrix ' . $name . ' [s2s,user,anon]');
            $wantW = $wr === 'owner' ? array(200, 200, 401) : array(200, 403, 401);
            $gotW = array(
                pf_s2s($B, $D, 'svc-b', array('op' => 'create', 'collection' => $name, 'data' => array()))['status'],
                pf_http($B, 'POST', $D, array('op' => 'create', 'collection' => $name, 'data' => array()), $tokB)['status'],
                pf_http($B, 'POST', $D, array('service_id' => 'svc-b', 'op' => 'create', 'collection' => $name, 'data' => array()))['status'],
            );
            assert_eq($wantW, $gotW, 'write matrix ' . $name . ' [s2s,user,anon]');
        }
    }
    $anonOrigin = pf_http($B, 'POST', $D, array('service_id' => 'svc-b', 'op' => 'query', 'collection' => 'm_public_owner'), null,
        array('Origin: http://evil.test'));
    pf_err($anonOrigin, 403, 'FORBIDDEN', 'anon from an unregistered origin');
}

/** 접근 제한 — 5경로 403 · payload 층 · 기록 · 세션 회수 · features · H-6 */
function pf_sc_access(array $B): void
{
    pf_reset_rl($B);
    $SA = '/server/api/admin/service_access.php';
    $u1 = $B['fx']['u']['u1'];
    // features 등록부 없음 → 키 없음
    $s0 = pf_ok(pf_http($B, 'GET', '/server/api/auth/session.php', null, $B['fx']['tok']['u1']), 'session before');
    assert_false(array_key_exists('features', $s0), 'no registry -> no features key');
    pf_ok(pf_s2s($B, '/server/api/platform/features.php', 'svc-a', array('features' => array(
        array('id' => 'export', 'name' => '내보내기', 'default' => true), array('id' => 'report.view', 'default' => false)))), 'registry');
    $s1 = pf_ok(pf_http($B, 'GET', '/server/api/auth/session.php', null, $B['fx']['tok']['u1']), 'session features');
    assert_eq(array('export'), $s1['features'], 'default features');
    pf_ok(pf_admin($B, 'sa', $SA, array('action' => 'set_user_features', 'service_id' => 'svc-a', 'uuid' => $u1['uuid'],
        'features' => array('report.view', 'ghost'))), 'user features');
    $s2 = pf_ok(pf_http($B, 'GET', '/server/api/auth/session.php', null, $B['fx']['tok']['u1']), 'session features 2');
    assert_eq(array('report.view'), $s2['features'], 'user pick ∩ registry');
    // 규칙 granted → 5경로 403
    pf_err(pf_admin($B, 'ad', $SA, array('action' => 'set_rule', 'service_id' => 'svc-a', 'access_rule' => 'granted')), 403, 'FORBIDDEN',
        'admin cannot change rules');
    $rr = pf_ok(pf_admin($B, 'sa', $SA, array('action' => 'set_rule', 'service_id' => 'svc-a', 'access_rule' => 'granted')), 'rule granted');
    // v1.10.0 (설계 127 §2) — 규칙 변경으로 입장을 잃은 계정의 세션은 바로 회수된다. 403 은 새 토큰으로 본다
    assert_true($rr['revoked_sessions'] >= 1, 'rule change revoked the sessions that lost access');
    assert_eq(401, pf_http($B, 'GET', '/server/api/auth/session.php', null, $B['fx']['tok']['u1'])['status'], 'revoked session is dead');
    $pdo = pf_pdo($B);
    $freshTok = pf_token($pdo, $u1['id'], 'svc-a');
    $pdo = null;
    $logs0 = (int) pf_q($B, "SELECT COUNT(*) AS c FROM app_ulogin_login_logs WHERE result = 'fail_service_access'")[0]['c'];
    pf_err(pf_http($B, 'GET', '/server/api/auth/session.php', null, $freshTok), 403, 'SERVICE_ACCESS_DENIED', 'session');
    pf_err(pf_http($B, 'POST', '/server/api/auth/login.php', array('username' => $u1['username'], 'password' => PF_PW, 'service_id' => 'svc-a')),
        403, 'SERVICE_ACCESS_DENIED', 'login with service_id');
    pf_ok(pf_http($B, 'POST', '/server/api/auth/login.php', array('username' => $u1['username'], 'password' => PF_PW)), 'plain login unaffected');
    pf_err(pf_http($B, 'POST', '/server/api/sso/authorize.php', array('username' => $u1['username'], 'password' => PF_PW, 'service_id' => 'svc-a',
        'redirect_uri' => 'http://127.0.0.1:9/cb', 'state' => 's')), 403, 'SERVICE_ACCESS_DENIED', 'authorize');
    $csrf = bin2hex(random_bytes(16));
    $pdo = pf_pdo($B);
    $ck = pf_token($pdo, $u1['id'], null, 'cookie', $csrf);
    $pdo = null;
    pf_err(pf_http($B, 'POST', '/server/api/sso/authorize_session.php', array('service_id' => 'svc-a', 'redirect_uri' => 'https://app.test/cb',
        'state' => 's', 'code_challenge' => str_repeat('A', 43), 'code_challenge_method' => 'S256'), null,
        array('Cookie: ULOGIN_SID=' . $ck, 'X-CSRF-Token: ' . $csrf, 'X-Forwarded-Proto: https')), 403, 'SERVICE_ACCESS_DENIED', 'authorize_session');
    $code = bin2hex(random_bytes(32));
    pf_x($B, 'INSERT INTO app_ulogin_auth_codes (code_hash, user_id, service_id, redirect_uri, state, code_challenge, expires_at, used_at,'
        . ' created_ip, created_at) VALUES (?,?,?,?,?,?,?,NULL,?,?)',
        array(hash('sha256', $code), $u1['id'], 'svc-a', 'http://127.0.0.1:9/cb', 'st', null, pf_now(60), '127.0.0.1', pf_now()));
    $sess0 = (int) pf_q($B, 'SELECT COUNT(*) AS c FROM app_ulogin_sessions')[0]['c'];
    pf_err(pf_http($B, 'POST', '/server/api/auth/exchange.php', array('code' => $code, 'service_id' => 'svc-a',
        'redirect_uri' => 'http://127.0.0.1:9/cb', 'service_secret' => $B['fx']['sec']['svc-a'])), 403, 'SERVICE_ACCESS_DENIED', 'exchange');
    assert_true(pf_q($B, 'SELECT used_at FROM app_ulogin_auth_codes WHERE code_hash = ?', array(hash('sha256', $code)))[0]['used_at'] !== null,
        'exchange refusal keeps the code consumed');
    assert_eq($sess0, (int) pf_q($B, 'SELECT COUNT(*) AS c FROM app_ulogin_sessions')[0]['c'], 'no session minted on refusal');
    $logs1 = (int) pf_q($B, "SELECT COUNT(*) AS c FROM app_ulogin_login_logs WHERE result = 'fail_service_access'")[0]['c'];
    assert_eq($logs0 + 5, $logs1, 'each of the five refusals logged fail_service_access');
    pf_err(pf_http($B, 'POST', '/server/api/platform/docs.php', array('op' => 'query', 'collection' => 'notes'), $freshTok),
        403, 'SERVICE_ACCESS_DENIED', 'platform user branch (6th)');
    // allow → 통과, 서버 판정값
    $g = pf_ok(pf_admin($B, 'sa', $SA, array('action' => 'set_grant', 'service_id' => 'svc-a', 'uuid' => $u1['uuid'], 'grant' => 'allow')), 'allow');
    assert_eq(array(true, 'granted_allow'), array($g['allowed'], $g['reason']), 'server verdict');
    pf_ok(pf_http($B, 'GET', '/server/api/auth/session.php', null, $freshTok), 'session after allow');
    $view = pf_ok(pf_http($B, 'GET', $SA . '?uuid=' . $u1['uuid'], null, $B['fx']['tok']['ad']), 'user view');
    assert_eq(array('uuid', 'systems'), array_keys($view), 'service_access ?uuid keys');
    $sv = pf_ok(pf_http($B, 'GET', $SA . '?service_id=svc-a', null, $B['fx']['tok']['ad']), 'service view');
    assert_eq(array('service_id', 'access_rule', 'can_list_users', 'level_aware', 'features', 'grants'), array_keys($sv),
        'service_access ?service_id keys (+ v1.10.0 level_aware)');
    // deny → 세션 회수
    $g2 = pf_ok(pf_admin($B, 'sa', $SA, array('action' => 'set_grant', 'service_id' => 'svc-a', 'uuid' => $u1['uuid'], 'grant' => 'deny')), 'deny');
    assert_true($g2['revoked_sessions'] >= 1, 'removing access revokes that service\'s sessions');
    pf_err(pf_http($B, 'GET', '/server/api/auth/session.php', null, $freshTok), 401, 'AUTH_SESSION_EXPIRED', 'revoked');
    // admin 규칙: 전역 admin 은 통과, 서비스 오버라이드 admin(ov)은 거절, deny 는 superadmin 도
    pf_ok(pf_admin($B, 'sa', $SA, array('action' => 'set_rule', 'service_id' => 'svc-a', 'access_rule' => 'admin')), 'rule admin');
    // v1.10.0 — 앞의 granted 규칙 변경이 ad_svc·ov 세션도 회수했다. 새 토큰으로 본다
    $pdo = pf_pdo($B);
    $adSvcTok = pf_token($pdo, $B['fx']['u']['ad']['id'], 'svc-a');
    $ovTok = pf_token($pdo, $B['fx']['u']['ov']['id'], 'svc-a');
    $pdo = null;
    pf_ok(pf_http($B, 'GET', '/server/api/auth/session.php', null, $adSvcTok), 'global admin passes admin rule');
    pf_err(pf_http($B, 'GET', '/server/api/auth/session.php', null, $ovTok), 403, 'SERVICE_ACCESS_DENIED',
        'service-override admin is NOT admin for access');
    pf_ok(pf_admin($B, 'sa', $SA, array('action' => 'set_rule', 'service_id' => 'svc-a', 'access_rule' => 'all')), 'rule all');
    // payload 층: refresh 경로 대신 build_payload 직접 — 시크릿 1개를 두고 granted 로 바꾼 뒤 session 은 403, 그 판정 없이도 payload {}
    unset($view);
}

/** userinfo · users */
function pf_sc_sso_info(array $B): void
{
    pf_reset_rl($B);
    $u1 = $B['fx']['u']['u1'];
    $d = pf_ok(pf_s2s($B, '/server/api/sso/userinfo.php', 'svc-m', array('uuid' => $u1['uuid'])), 'userinfo');
    assert_eq(array('uuid', 'username', 'name', 'status', 'role', 'role_rank', 'services', 'access'), array_keys($d), 'userinfo keys (D-350 + v1.10.0 access)');
    assert_eq('홍길동', $d['name'], 'name');
    assert_false(array_key_exists('svc-off', $d['services']), 'disabled service has no key');
    assert_true($d['services']['svc-b'] === true, 'rule all -> true');
    pf_err(pf_s2s($B, '/server/api/sso/userinfo.php', 'svc-m', array('uuid' => '00000000-0000-4000-8000-000000000000')), 404, 'NOT_FOUND',
        'unknown uuid', 'uuid');
    pf_err(pf_s2s($B, '/server/api/sso/userinfo.php', 'svc-m', array('uuid' => 'nope')), 400, 'VALIDATION_FAILED', 'bad uuid', 'uuid');
    pf_err(pf_http($B, 'POST', '/server/api/sso/userinfo.php', array('service_id' => 'svc-m', 'service_secret' => 'x', 'uuid' => $u1['uuid'])),
        401, 'SSO_CLIENT_AUTH_FAILED', 'userinfo bad secret');
    $l = pf_ok(pf_s2s($B, '/server/api/sso/users.php', 'svc-m', array('page' => 1, 'per_page' => 2)), 'users');
    assert_eq(array('items', 'total', 'page', 'per_page'), array_keys($l), 'users keys');
    assert_eq(array('uuid', 'username', 'name', 'role', 'role_rank', 'status'), array_keys($l['items'][0]), 'users item keys');
    pf_err(pf_s2s($B, '/server/api/sso/users.php', 'svc-a', array()), 403, 'FORBIDDEN', 'users without can_list_users');
    $aud = pf_q($B, "SELECT COUNT(*) AS c FROM app_ulogin_plat_audit WHERE action = 'sso.users.list'");
    assert_eq(1, (int) $aud[0]['c'], 'users call left an audit row');
    $all = (string) json_encode(pf_q($B, 'SELECT * FROM app_ulogin_plat_audit'));
    assert_not_contains($B['fx']['sec']['svc-m'], $all, 'secret never in audit');
}

/** 관리자 감사 탭 — log_admin 이중 기록 · 조회 키 · 내보내기 */
function pf_sc_admin_views(array $B): void
{
    pf_reset_rl($B);
    $n = (int) pf_q($B, "SELECT COUNT(*) AS c FROM app_ulogin_plat_audit WHERE service_id = 'ulogin' AND actor_type = 'admin'")[0]['c'];
    assert_true($n >= 1, 'log_admin wrote ulogin audit rows (' . $n . ')');
    $al = (int) pf_q($B, 'SELECT COUNT(*) AS c FROM app_ulogin_admin_logs')[0]['c'];
    assert_eq($al, $n, 'every admin_logs row has its audit twin');
    $L = pf_ok(pf_http($B, 'GET', '/server/api/admin/platform_logs.php?kind=logs&limit=2', null, $B['fx']['tok']['ad']), 'logs view');
    assert_eq(array('items', 'next_cursor'), array_keys($L), 'platform_logs keys');
    $A = pf_ok(pf_http($B, 'GET', '/server/api/admin/platform_logs.php?kind=audit&service_id=ulogin', null, $B['fx']['tok']['ad']), 'audit view');
    assert_true(count($A['items']) >= 1 && isset($A['items'][0]['seq'], $A['items'][0]['actor']), 'audit items shape');
    $ex = pf_http($B, 'GET', '/server/api/admin/platform_logs.php?kind=audit&format=jsonl', null, $B['fx']['tok']['sa']);
    assert_eq(200, $ex['status'], 'export 200');
    assert_contains('"row_hash"', $ex['body'], 'export carries chain hashes');
    pf_err(pf_http($B, 'GET', '/server/api/admin/platform_logs.php?kind=audit&format=jsonl', null, $B['fx']['tok']['ad']), 403, 'FORBIDDEN',
        'export is superadmin');
    $U = pf_ok(pf_http($B, 'GET', '/server/api/admin/platform_usage.php?days=7', null, $B['fx']['tok']['ad']), 'usage');
    assert_eq(array('items', 'totals'), array_keys($U), 'usage keys');
    $T = pf_ok(pf_http($B, 'GET', '/server/api/admin/platform_tables.php?service_id=svc-a', null, $B['fx']['tok']['ad']), 'tables');
    assert_eq(array('items'), array_keys($T), 'tables keys');
    $h = pf_ok(pf_http($B, 'GET', '/server/api/health.php', null, $B['fx']['tok']['ad']), 'health');
    assert_true(array_key_exists('audit_chain_ok', $h), 'health audit_chain_ok key');
    $r = pf_ok(pf_admin($B, 'sa', '/server/api/admin/platform_logs.php', array('action' => 'set_retention', 'logs_days' => 10, 'audit_days' => 120)),
        'retention');
    assert_eq(array('logs_days' => 10, 'audit_days' => 120), $r, 'retention keys');
}

/** ⑧ 100건 배치 p95 (SQLite, 실서버 왕복) */
function pf_sc_perf(array $B): void
{
    pf_reset_rl($B);
    $ms = array();
    for ($k = 0; $k < 20; $k++) {
        $evs = array();
        for ($i = 0; $i < 100; $i++) {
            $evs[] = pf_ev(array('message' => 'perf ' . $i, 'data' => array('i' => $i, 'k' => $k)));
        }
        $r = pf_s2s($B, '/server/api/platform/logs.php', 'svc-a', array('events' => $evs));
        $d = pf_ok($r, 'perf batch ' . $k);
        assert_eq(100, $d['accepted'], 'perf batch accepted 100');
        $ms[] = $r['ms'];
        pf_reset_rl($B);
    }
    $one = array();
    for ($k = 0; $k < 20; $k++) {
        $r = pf_s2s($B, '/server/api/platform/logs.php', 'svc-a', array('events' => array(pf_ev())));
        pf_ok($r, 'baseline 1-event');
        $one[] = $r['ms'];
        pf_reset_rl($B);
    }
    sort($one);
    note(sprintf('baseline 1-event batch round trip: p50 %.1f ms · p95 %.1f ms (same path: bootstrap + 2 rate buckets + s2s auth + 1 commit, synchronous=FULL)',
        $one[9], $one[18]));
    sort($ms);
    $p95 = $ms[(int) ceil(0.95 * count($ms)) - 1];
    $GLOBALS['PF']['p95'] = $p95;
    note(sprintf('logs 100-event batch round trip: p50 %.1f ms · p95 %.1f ms · max %.1f ms (20 runs, SQLite, php -S)',
        $ms[9], $p95, $ms[19]));
    assert_true($p95 < 1000.0, 'p95 under a generous 1s guard (target 50 ms reported above)');
}

/** ⑨ 0007 SQLite — 0006 상태 → apply → verify → 재적용 no-op → 씨앗 */
function pf_sc_migrate(array $B): void
{
    $schema = (string) file_get_contents($B['root'] . '/server/schema.sql');
    $old = preg_replace('~^CREATE TABLE IF NOT EXISTS `app_ulogin_(?:rc_drafts|rc_versions|plat_logs|plat_audit|plat_collections|plat_docs|plat_usage)` \(.*?\n\) ENGINE[^;]*;~ms',
        '', $schema, -1, $nt);
    $old = preg_replace('~^\s*`(?:access_rule|can_list_users|platform_json|access_grant)`[^\n]*\n~m', '', (string) $old, -1, $nc);
    $old = preg_replace('~^\s*`features_json`[^\n]*\n~m', '', (string) $old, -1, $nf);
    $old = preg_replace('~,\n\s*KEY `idx_us_access_grant`[^\n]*~', '', (string) $old, -1, $ni);
    if ($nt !== 7 || $nc !== 4 || $nf !== 2 || $ni !== 1) {
        fail('HARNESS-FAIL could not derive the 0006-state schema (' . $nt . '/' . $nc . '/' . $nf . '/' . $ni . ')');
    }
    $oldFile = $B['base'] . '/schema_0006.sql';
    file_put_contents($oldFile, $old);
    $db = $B['base'] . '/data/ulogin_test_pf_mig.sqlite';
    $pdo = new PDO('sqlite:' . $db, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    mig_sqlite_create_schema($pdo, $oldFile, 'app_ulogin_', false);
    $files = array();
    foreach (mig_list($B['root'] . '/server/migrations') as $f) {
        if (strcmp((string) $f['version'], '0007') < 0) {
            $files[] = $f;
        }
    }
    assert_eq(6, count($files), 'six migrations before 0007');
    mig_stamp_baseline($pdo, $files, 'app_ulogin_schema_migrations', 'test');
    $now = pf_now();
    foreach (array('manager', 'office', 'task') as $sid) {
        $pdo->prepare('INSERT INTO app_ulogin_services (service_id, service_name, redirect_whitelist, created_at, updated_at) VALUES (?,?,?,?,?)')
            ->execute(array($sid, $sid, '[]', $now, $now));
    }
    $pdo = null;
    $cfgFile = $B['root'] . '/server/config.php';
    $cfgOrig = (string) file_get_contents($cfgFile);
    file_put_contents($cfgFile, pf_set_define($cfgOrig, 'DB_SQLITE_PATH', var_export($db, true)));
    $run = function ($cmd) use ($B) {
        $code = 0;
        $out = h_run(h_q(h_php_bin()) . ' -c ' . h_q(pf_ini()) . ' ' . h_q($B['root'] . '/server/migrate.php') . ' ' . $cmd, $code);
        return array($code, $out);
    };
    try {
        list($c1, $o1) = $run('apply');
        assert_eq(0, $c1, 'migrate apply 0006 -> 0007 (' . substr($o1, -600) . ')');
        assert_contains('0007', $o1, 'apply ran 0007');
        list($c2, $o2) = $run('verify');
        assert_eq(0, $c2, 'verify after upgrade equals schema.sql (' . substr($o2, -600) . ')');
        list($c3, $o3) = $run('apply');
        assert_eq(0, $c3, 'second apply is a no-op (' . substr($o3, -300) . ')');
    } finally {
        file_put_contents($cfgFile, $cfgOrig);
    }
    $pdo = new PDO('sqlite:' . $db, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
    $rows = array();
    foreach ($pdo->query('SELECT service_id, access_rule, can_list_users FROM app_ulogin_services ORDER BY service_id') as $r) {
        $rows[$r['service_id']] = $r['access_rule'] . '/' . (int) $r['can_list_users'];
    }
    assert_eq(array('manager' => 'admin/1', 'office' => 'admin/0', 'task' => 'all/0'), $rows, 'H-7 seeds (office id as-is)');
    $pdo = null;
}

/** H190-1 — 없는 service_id 실패는 IP 10/300 뒤 429(bcrypt 전). 실재 id 의 낡은 secret 은 그 버킷과 무관 */
function pf_sc_h1(array $B): void
{
    pf_reset_rl($B);
    $ms401 = array();
    $ms429 = array();
    for ($i = 0; $i < 16; $i++) {
        $r = pf_http($B, 'POST', '/server/api/platform/logs.php', array('service_id' => 'zz' . bin2hex(random_bytes(4)),
            'service_secret' => 'x', 'events' => array()));
        if ($r['status'] === 401) {
            $ms401[] = $r['ms'];
        } elseif ($r['status'] === 429) {
            $ms429[] = $r['ms'];
        }
    }
    sort($ms401);
    sort($ms429);
    $med = function (array $a) {
        return $a === array() ? -1.0 : (float) $a[(int) floor((count($a) - 1) / 2)];
    };
    note(sprintf('H190-1 unknown service_id: 401 x%d median %.1f ms (dummy bcrypt) · 429 x%d median %.1f ms (no bcrypt)',
        count($ms401), $med($ms401), count($ms429), $med($ms429)));
    assert_eq(10, count($ms401), 'first 10 unknown-id attempts are 401');
    assert_eq(6, count($ms429), 'the rest are 429 before any bcrypt');
    assert_true($med($ms429) < $med($ms401), '429 is cheaper than the bcrypt 401');
    $logged = (int) pf_q($B, "SELECT COUNT(*) AS c FROM app_ulogin_login_logs WHERE result = 'fail_client_auth'")[0]['c'];
    assert_eq(1, $logged, 'unknown-id failures leave one login_logs row per window');
    // 실재 id 는 영향 없음 — 낡은 secret 은 401(429 아님), 맞는 secret 은 200
    pf_err(pf_http($B, 'POST', '/server/api/platform/logs.php', array('service_id' => 'svc-a', 'service_secret' => 'stale', 'events' => array())),
        401, 'SSO_CLIENT_AUTH_FAILED', 'known id with a stale secret is not caught by the unknown-id bucket');
    pf_ok(pf_s2s($B, '/server/api/platform/logs.php', 'svc-a', array('events' => array(pf_ev()))), 'known id with the right secret');
}

/** M190-1 · M190-3 · L190-1 · L190-2 */
function pf_sc_m1m3(array $B): void
{
    pf_reset_rl($B);
    $D = '/server/api/platform/docs.php';
    pf_ok(pf_s2s($B, '/server/api/platform/collections.php', 'svc-m', array('op' => 'upsert', 'name' => 'profiles',
        'read_rule' => 'owner', 'write_rule' => 'owner')), 'profiles');
    $t1 = pf_tok($B, $B['fx']['u']['u1']['id'], 'svc-m');
    $t2 = pf_tok($B, $B['fx']['u']['u2']['id'], 'svc-m');
    pf_err(pf_http($B, 'POST', $D, array('op' => 'create', 'collection' => 'profiles', 'doc_id' => $B['fx']['u']['u1']['uuid'],
        'data' => array('plan' => 'pro')), $t2), 403, 'FORBIDDEN', 'user cannot squat another user\'s uuid as doc_id (M190-1)', 'doc_id');
    pf_err(pf_http($B, 'POST', $D, array('op' => 'create', 'collection' => 'profiles', 'doc_id' => 'my-own-name', 'data' => array()), $t2),
        403, 'FORBIDDEN', 'user cannot pick arbitrary doc_id');
    $own = pf_ok(pf_http($B, 'POST', $D, array('op' => 'create', 'collection' => 'profiles', 'doc_id' => $B['fx']['u']['u1']['uuid'],
        'data' => array('plan' => 'free')), $t1), 'own uuid as doc_id')['doc'];
    assert_eq($B['fx']['u']['u1']['uuid'], $own['doc_id'], 'own uuid accepted');
    // M190-3 — user 갈래 쓰기 한도는 따로 — 다 써도 s2s 쓰기는 된다
    pf_x($B, "UPDATE app_ulogin_services SET platform_json = '{\"quota\":{\"doc_writes_user\":2,\"doc_writes\":3}}' WHERE service_id = 'svc-m'");
    pf_ok(pf_http($B, 'POST', $D, array('op' => 'create', 'collection' => 'profiles', 'data' => array()), $t2), 'user write 2/2');
    pf_err(pf_http($B, 'POST', $D, array('op' => 'create', 'collection' => 'profiles', 'data' => array()), $t2), 403, 'QUOTA_EXCEEDED',
        'user write over the user quota');
    pf_ok(pf_s2s($B, $D, 'svc-m', array('op' => 'create', 'collection' => 'profiles', 'data' => array())), 's2s write still allowed (M190-3)');
    $u = array();
    foreach (pf_q($B, "SELECT metric, value FROM app_ulogin_plat_usage WHERE service_id = 'svc-m'") as $r) {
        $u[$r['metric']] = (int) $r['value'];
    }
    assert_eq(array(2, 1), array($u['doc_writes_user'] ?? -1, $u['doc_writes'] ?? -1), 'user and s2s writes counted apart');
    // L190-1 — 예약 id
    $r = pf_admin($B, 'sa', '/server/api/admin/services.php', array('service_id' => 'ulogin', 'service_name' => 'x',
        'redirect_whitelist' => array('https://app.test/cb')));
    pf_err($r, 409, 'DUPLICATE_SERVICE_ID', 'ulogin is a reserved service_id (L190-1)', 'service_id');
    // L190-2 — 넓힌 키 · 문자열 안 이메일
    $e = pf_ev(array('data' => array('pwd' => 'p1', 'passcode' => '1234', 'sessid' => 's', 'note' => 'sent to kim@example.org today')));
    $d = pf_ok(pf_s2s($B, '/server/api/platform/logs.php', 'svc-m', array('events' => array($e))), 'L2 log');
    assert_eq(1, $d['redacted'], 'redacted');
    $row = (string) pf_q($B, 'SELECT data_json FROM app_ulogin_plat_logs WHERE event_id = ?', array($e['event_id']))[0]['data_json'];
    foreach (array('"p1"', '"1234"', '"s"', 'kim@example.org') as $leak) {
        assert_not_contains($leak, $row, 'L190-2 leak ' . $leak);
    }
    assert_contains('k***@example.org', $row, 'email inside a string masked');
}

/** M190-4 — 마지막 행 삭제 + 머리 되돌림을 DB 밖 머리 사본이 잡는다 */
function pf_sc_tail(array $B): void
{
    pf_reset_rl($B);
    pf_ok(pf_s2s($B, '/server/api/platform/audit.php', 'svc-a', array('events' => array(pf_aev(), pf_aev(), pf_aev()))), 'audit 3');
    $v = pf_ok(pf_admin($B, 'ad', '/server/api/admin/platform_logs.php', array('action' => 'verify', 'from_seq' => 1)), 'verify');
    assert_true($v['ok'] === true, 'intact chain verifies');
    $hf = $B['db'] . '.audit_head.log';
    assert_true(is_file($hf), 'head copy file written next to the DB file (outside the version tree)');
    assert_false(is_file($B['root'] . '/server/storage/ulogin.audit_head.log'), 'nothing under the version folder');
    if (DIRECTORY_SEPARATOR === '\\') {
        $o = array();
        $rc = 0;
        @exec('icacls ' . escapeshellarg(str_replace('/', '\\', $hf)) . ' 2>&1', $o, $rc);
        $acl = implode("\n", $o);
        assert_eq(0, $rc, 'icacls readable');
        assert_not_contains('(I)', $acl, 'no inherited ACE on the head file');
        assert_not_contains('Users:', $acl, 'BUILTIN\\Users has no access');
    }
    // 파일을 지우면(새 위치·첫 배포) 현재 머리로 조용히 다시 시작하고 health 가 알린다
    @unlink($hf);
    pf_x($B, "DELETE FROM app_ulogin_settings WHERE skey = 'audit.head_anchor_started_at'");
    $v0 = pf_ok(pf_admin($B, 'ad', '/server/api/admin/platform_logs.php', array('action' => 'verify', 'from_seq' => 1)), 'verify re-anchor');
    assert_true($v0['ok'] === true && is_file($hf), 'missing anchor re-initialised quietly');
    $h0 = pf_ok(pf_http($B, 'GET', '/server/api/health.php', null, $B['fx']['tok']['ad']), 'health re-anchor');
    $keys = array_map(function ($c) { return $c['key']; }, $h0['checks']);
    assert_true(in_array('audit_anchor_restarted', $keys, true), 'health shows the anchor restart');
    $rows = pf_q($B, 'SELECT id, row_hash FROM app_ulogin_plat_audit ORDER BY id ASC');
    $n = count($rows);
    pf_x($B, 'DELETE FROM app_ulogin_plat_audit WHERE id = ?', array((int) $rows[$n - 1]['id']));
    pf_x($B, "UPDATE app_ulogin_settings SET svalue = ? WHERE skey = 'audit.chain_head'",
        array($rows[$n - 2]['id'] . ':' . $rows[$n - 2]['row_hash']));
    $v2 = pf_ok(pf_admin($B, 'ad', '/server/api/admin/platform_logs.php', array('action' => 'verify', 'from_seq' => 1)), 'verify truncated');
    assert_true($v2['ok'] === false, 'tail truncation + head rollback detected (M190-4)');
    assert_eq((int) $rows[$n - 1]['id'], (int) $v2['first_bad_seq'], 'first bad = the removed seq');
    $h = pf_ok(pf_http($B, 'GET', '/server/api/health.php', null, $B['fx']['tok']['ad']), 'health');
    assert_true($h['audit_chain_ok'] === false, 'health reports the truncated chain');
    // M190-5 — 자른 뒤 정상 감사가 1건 더 들어와 같은 seq 가 새 해시로 다시 적혀도 탐지가 남는다
    pf_ok(pf_s2s($B, '/server/api/platform/audit.php', 'svc-a', array('events' => array(pf_aev()))), 'one more audit after truncation');
    $v3 = pf_ok(pf_admin($B, 'ad', '/server/api/admin/platform_logs.php', array('action' => 'verify', 'from_seq' => 1)), 'verify after refill');
    assert_true($v3['ok'] === false, 'refilled seq does not hide the truncation (M190-5)');
    assert_eq((int) $rows[$n - 1]['id'], (int) $v3['first_bad_seq'], 'first bad = the overwritten seq');
    $h2 = pf_ok(pf_http($B, 'GET', '/server/api/health.php', null, $B['fx']['tok']['ad']), 'health after refill');
    assert_true($h2['audit_chain_ok'] === false, 'health still false after refill');
}

/** 머리 사본 10만 줄에서 health 1회 지연(증분+꼬리)과 관리자 전체 대조 지연 · prune 이 파일을 함께 자르는가 */
function pf_sc_headperf(array $B): void
{
    pf_reset_rl($B);
    $base = array();
    for ($i = 0; $i < 3; $i++) {
        $base[] = pf_http($B, 'GET', '/server/api/health.php', null, $B['fx']['tok']['ad'])['ms'];
    }
    list($rc, $out) = pf_cli($B, 'audit_ensure_head(); $t0 = microtime(true);'
        . 'for ($c = 0; $c < 20; $c++) { $rows = [];'
        . ' for ($i = 0; $i < 5000; $i++) { $r = audit_ulogin_row("system", null, "perf.row", "perf", (string) $i, null); $rows[] = $r; }'
        . ' $rc = db_tx(function () use ($rows) { return audit_append_in_tx($rows); }); }'
        . '$lines = ""; foreach (db_all("SELECT id, row_hash FROM " . T_PLAT_AUDIT . " ORDER BY id ASC") as $r) { $lines .= $r["id"] . ":" . $r["row_hash"] . "\\n"; }'
        . '@unlink(audit_head_file()); audit_head_init(); file_put_contents(audit_head_file(), $lines, FILE_APPEND | LOCK_EX);'
        . 'db_exec("DELETE FROM " . T_SETTINGS . " WHERE skey = ?", [AUDIT_CHECKED_KEY]);'
        . 'echo "@@PF@@" . json_encode(["rows" => (int) db_val("SELECT COUNT(*) FROM " . T_PLAT_AUDIT), "sec" => round(microtime(true) - $t0, 1)]) . "@@PF@@";');
    assert_eq(0, $rc, 'seed 100k (' . substr($out, -300) . ')');
    $hf = $B['db'] . '.audit_head.log';
    $lines = count(file($hf));
    assert_true($lines >= 100000, 'head copy has 100k lines (' . $lines . ')');
    $t = array();
    for ($i = 0; $i < 3; $i++) {
        $r = pf_http($B, 'GET', '/server/api/health.php', null, $B['fx']['tok']['ad']);
        $h = pf_ok($r, 'health ' . $i);
        assert_true($h['audit_chain_ok'] === true, 'health ok at 100k lines');
        $t[] = $r['ms'];
    }
    $cs = array();
    for ($i = 0; $i < 3; $i++) {
        $cs[] = pf_http($B, 'GET', '/server/api/auth/csrf.php')['ms'];
    }
    note(sprintf('same DB, an endpoint with no audit work (auth/csrf): %.0f / %.0f / %.0f ms (D-363: quick_check once per process + 10 min — was 160~190 ms when it ran per request)',
        $cs[0], $cs[1], $cs[2]));
    $max = (int) pf_q($B, 'SELECT MAX(id) AS m FROM app_ulogin_plat_audit')[0]['m'];
    $rv = pf_admin($B, 'ad', '/server/api/admin/platform_logs.php', array('action' => 'verify', 'from_seq' => $max - 10));
    $v = pf_ok($rv, 'admin full verify');
    assert_true($v['ok'] === true, 'full tail check ok');
    note(sprintf('head copy %d lines: health %.0f / %.0f / %.0f ms (incremental + tail 1000; empty-chain baseline %.0f / %.0f ms) · admin verify with full file check %.0f ms',
        $lines, $t[0], $t[1], $t[2], $base[1], $base[2], $rv['ms']));
    assert_true($t[2] < 2000.0, 'health stays bounded at 100k lines');
    // prune 이 머리 사본도 자른다 — 오래된 행 1,000개를 만료시켜 정리
    pf_x($B, "UPDATE app_ulogin_plat_audit SET received_at = '2020-01-01 00:00:00' WHERE id <= 1000");
    list($rc2, $out2) = pf_cli($B, 'echo "@@PF@@" . json_encode(["n" => audit_prune(90), "n2" => audit_prune(90), "v" => audit_tail_check(true)]) . "@@PF@@";');
    assert_eq(0, $rc2, 'prune cli');
    $j = preg_match('/@@PF@@(.*?)@@PF@@/s', $out2, $mm) === 1 ? json_decode($mm[1], true) : null;
    assert_eq(1000, (int) $j['n'] + (int) $j['n2'], 'pruned 1000 rows in two chunks');
    assert_true($j['v']['ok'] === true, 'chain + head copy still consistent after prune');
    clearstatcache();
    $after = count(file($hf));
    assert_true($after <= $lines - 1000 + 2 + 5, 'head copy trimmed with the prune (' . $lines . ' -> ' . $after . ')');
}

/** D-363 — quick_check 캐시: 첫 요청 1회 · 10분 안 재사용 · 캐시된 실패는 503 유지 · 10분 지나면 재검사 · health 는 매번 */
function pf_sc_quickcheck(array $B): void
{
    pf_reset_rl($B);
    $cf = $B['db'] . '.quick_check';
    $rd = function () use ($cf) {
        clearstatcache();
        $j = json_decode((string) @file_get_contents($cf), true);
        return is_array($j) ? $j : array();
    };
    pf_ok(pf_http($B, 'GET', '/server/api/auth/csrf.php'), 'first request');
    $c1 = $rd();
    assert_true(isset($c1['pid'], $c1['at'], $c1['n']) && $c1['ok'] === true, 'cache written next to the DB (' . json_encode($c1) . ')');
    for ($i = 0; $i < 3; $i++) {
        pf_ok(pf_http($B, 'GET', '/server/api/auth/csrf.php'), 'cached request ' . $i);
    }
    assert_eq($c1['n'], $rd()['n'], 'no re-check within 10 minutes');
    // 캐시된 실패 → 모든 요청 503(fail-closed) — 같은 프로세스·10분 안
    file_put_contents($cf, json_encode(array('pid' => $c1['pid'], 'at' => time(), 'ok' => false, 'n' => $c1['n'])));
    // (auth/csrf 는 DB 를 읽지 않고 rate_limit 은 저장소 장애를 삼킨다 — DB 를 쓰는 경로로 본다)
    $r = pf_http($B, 'POST', '/server/api/auth/login.php', array('username' => 'pf_u3', 'password' => PF_PW));
    pf_err($r, 503, 'SERVER_ERROR', 'cached quick_check failure keeps every request stopped');
    pf_err(pf_s2s($B, '/server/api/platform/logs.php', 'svc-a', array('events' => array(pf_ev()))), 503, 'SERVER_ERROR', 'platform path stopped too');
    // 10분 지남(시각 주입) → 재검사 → 통과하면 풀린다
    file_put_contents($cf, json_encode(array('pid' => $c1['pid'], 'at' => time() - 601, 'ok' => false, 'n' => $c1['n'])));
    pf_ok(pf_http($B, 'GET', '/server/api/auth/csrf.php'), 're-check after 10 minutes passes');
    $c2 = $rd();
    assert_true($c2['ok'] === true && $c2['n'] === $c1['n'] + 1, 're-checked once and cached ok');
    // 다른 프로세스(pid 다름) → 첫 요청이므로 검사
    file_put_contents($cf, json_encode(array('pid' => $c1['pid'] + 1, 'at' => time(), 'ok' => true, 'n' => $c2['n'])));
    pf_ok(pf_http($B, 'GET', '/server/api/auth/csrf.php'), 'new process');
    assert_eq($c2['n'] + 1, $rd()['n'], 'a new process checks on its first request');
    // health 는 매번
    $n0 = $rd()['n'];
    pf_ok(pf_http($B, 'GET', '/server/api/health.php'), 'health 1');
    pf_ok(pf_http($B, 'GET', '/server/api/health.php'), 'health 2');
    assert_eq($n0 + 2, $rd()['n'], 'health checks every time');
    // 깨진 캐시는 믿지 않는다 → 검사
    file_put_contents($cf, '{broken');
    pf_ok(pf_http($B, 'GET', '/server/api/auth/csrf.php'), 'broken cache');
    assert_true(($rd()['ok'] ?? null) === true, 'broken cache replaced by a fresh check');
    if (DIRECTORY_SEPARATOR === '\\') {
        $o = array();
        $rc = 0;
        @exec('icacls ' . escapeshellarg(str_replace('/', '\\', $cf)) . ' 2>&1', $o, $rc);
        assert_not_contains('(I)', implode("\n", $o), 'cache file ACL narrowed (no inherited ACE)');
    }
}

// =====================================================================
// 케이스
// =====================================================================

$PF_MAIN = null;
$PF_GET = function () use (&$PF_MAIN) {
    if ($PF_MAIN === null) {
        $PF_MAIN = pf_box('main');
    }
    return $PF_MAIN;
};
$PF_CASES = array(
    'logs: masking (key + email), idempotent duplicate, reject reasons, size/auth/branch limits, quota with $by' => 'pf_sc_logs',
    'audit: receipt = chain head, s2s only, HMAC chain catches a keyless re-chain at the forged seq' => 'pf_sc_audit',
    'config: drafts hidden, publish 409, ETag 304, private no-cache, global-role conditions, s2s changed:false, rollback' => 'pf_sc_config',
    'docs: rule matrix 4x2x3, owner 404 + forced filter, cursor paging + tamper 400, version 409, max_docs 403' => 'pf_sc_docs',
    'access: five paths + platform 403 SERVICE_ACCESS_DENIED + logged, allow/deny, revoke on removal, global role only, features' => 'pf_sc_access',
    'sso userinfo/users: D-350 keys, flag-based 403 after auth, audit row, no secret leak' => 'pf_sc_sso_info',
    'admin views: log_admin twin rows, logs/audit/usage/tables keys, export superadmin, health key, retention' => 'pf_sc_admin_views',
    'perf: 100-event log batch p95 (SQLite)' => 'pf_sc_perf',
);
foreach ($PF_CASES as $name => $fn) {
    t($name, function () use ($PF_GET, $fn) {
        $fn($PF_GET());
    });
}

t('H190-1: unknown service_id failures hit a per-IP 10/300 bucket before bcrypt; known ids unaffected', function () {
    pf_sc_h1(pf_box('h1'));
});
t('M190-1 doc_id squat refused · M190-3 user quota apart from s2s · L190-1 reserved id · L190-2 wider masking', function () {
    pf_sc_m1m3(pf_box('m1m3'));
});
t('M190-4: tail truncation with head rollback is detected via the out-of-DB head copy', function () {
    pf_sc_tail(pf_box('tail'));
});

t('D-363 quick_check cache: once per process + 10 min, cached failure stays 503, re-check after 10 min, health every time', function () {
    pf_sc_quickcheck(pf_box('qc'));
});
t('mutant S-QC: cached failure treated as ok', function () {
    pf_mutant('sqc', array(array('server/core/db.php', "        if (\$c['ok'] !== true) {", "        if (false) {")), 'pf_sc_quickcheck');
});

t('head copy at 100k lines: health bounded (incremental + tail), admin full check, prune trims the file', function () {
    pf_sc_headperf(pf_box('headperf'));
});

t('audit prune writes an anchor first; verify continues past it; simple tamper reported', function () {
    pf_sc_prune(pf_box('prune'));
});

t('migration 0007 on SQLite: 0006 state -> apply -> verify clean, re-apply no-op, H-7 seeds', function () {
    pf_sc_migrate(pf_box('mig'));
});

// 변이(D-186) — 다섯 개가 전부 빨개져야 한다
t('mutant M1: access decided with effective (override) role', function () {
    pf_mutant('m1', array(array('server/core/access.php', "        global_role_of(\$user)\n    );",
        "        (string) effective_grades(\$user, \$us)['role']\n    );")), 'pf_sc_access');
});
t('mutant M2: server-side masking skipped', function () {
    pf_mutant('m2', array(array('server/core/platform.php', "        if (is_string(\$k) && plat_secret_key(\$k)) {\n            if (\$x !== PLAT_REDACTED) {",
        "        if (false) {\n            if (\$x !== PLAT_REDACTED) {")), 'pf_sc_logs');
});
t('mutant M3: owner filter not forced on user queries', function () {
    pf_mutant('m3', array(array('server/core/docs.php', "        \$sql[] = 'owner_uuid = ?';\n        \$p[] = \$forced;",
        "        \$sql[] = '1 = 1';")), 'pf_sc_docs');
});
t('mutant M4: plain sha256 instead of HMAC', function () {
    pf_mutant('m4', array(array('server/core/logger.php', "    return hash_hmac('sha256', \$prev . \"\\n\" . audit_canon(\$row), \$key);",
        "    return hash('sha256', \$prev . \"\\n\" . audit_canon(\$row));")), 'pf_sc_audit');
});
t('mutant S-H1: unknown-id bucket removed', function () {
    pf_mutant('sh1', array(array('server/core/accountlink.php', "        rate_limit('s2s:unknownsid:' . client_ip(), 10, 300);\n", '')), 'pf_sc_h1');
});
t('mutant S-M1: user doc_id restriction removed', function () {
    pf_mutant('sm1', array(array('server/core/docs.php', "if (\$c['kind'] === 'user' && isset(\$in['doc_id'])", "if (false && isset(\$in['doc_id'])")), 'pf_sc_m1m3');
});
t('mutant S-M2: read=service + write=owner accepted again', function () {
    pf_mutant('sm2', array(array('server/core/docs.php', "    if (\$wr === 'owner' && \$rr === 'service') {", "    if (false) {")), 'pf_sc_docs');
});
t('mutant S-M3: user and s2s share one quota metric', function () {
    pf_mutant('sm3', array(array('server/core/platform.php', "    return \$kind === 'service' ? \$metric : \$metric . '_user';", "    return \$metric;")),
        'pf_sc_m1m3');
});
t('mutant S-M4: tail check disabled', function () {
    pf_mutant('sm4', array(array('server/core/logger.php', "        \$t = audit_tail_check(\$fullTail);\n        if (!\$t['ok']) {\n            return ['checked' => \$checked,",
        "        \$t = ['ok' => true];\n        if (!\$t['ok']) {\n            return ['checked' => \$checked,")), 'pf_sc_tail');
});
t('mutant S-M5: only the last line of the head copy is checked', function () {
    pf_mutant('sm5', array(array('server/core/logger.php', "        if (isset(\$map[\$s]) && !hash_equals(\$map[\$s], \$m[2])) {",
        "        if (false) {")), 'pf_sc_tail');
});
t('mutant S-L1: reserved id check removed', function () {
    pf_mutant('sl1', array(array('server/core/auth.php', "        return 'reserved';", "        return null;")), 'pf_sc_m1m3');
});
t('mutant S-L2: email-in-string masking removed', function () {
    pf_mutant('sl2', array(array('server/core/platform.php', "        \$m = plat_mask_emails_in(\$v);", "        \$m = \$v;")), 'pf_sc_m1m3');
});
t('mutant M5: expected_version ignored on set/merge', function () {
    pf_mutant('m5', array(array('server/core/docs.php', "            . ' WHERE id = ? AND version = ?', [\$json,", "            . ' WHERE id = ? AND ? > 0', [\$json,")),
        'pf_sc_docs');
});

// =====================================================================
// v1.9.2 — L190-3 중첩 깊이 상한 · L190-4 감사 receipt 는 자기 서비스 행만
// =====================================================================

function pf_nest(int $levels, string $leafKey)
{
    $v = array($leafKey => 'x');
    for ($i = 1; $i < $levels; $i++) {
        $v = array('n' => $v);
    }
    return $v;
}

function pf_sc_l34(array $B): void
{
    pf_reset_rl($B);
    $D = '/server/api/platform/docs.php';
    $C = '/server/api/platform/collections.php';
    pf_ok(pf_s2s($B, $C, 'svc-a', array('op' => 'upsert', 'name' => 'deep', 'read_rule' => 'owner', 'write_rule' => 'owner')), 'upsert');
    $u1 = $B['fx']['tok']['u1'];
    // L3: 18단 중첩 안의 비밀 키 — 이전에는 200 으로 저장됐다. 이제 400.
    $r = pf_http($B, 'POST', $D, array('op' => 'create', 'collection' => 'deep', 'data' => pf_nest(18, 'password')), $u1);
    pf_err($r, 400, 'VALIDATION_FAILED', 'deep secret key refused', 'data');
    // 깊이 16단의 평범한 문서는 그대로 받는다(상한이 정상 사용을 막지 않는다)
    pf_ok(pf_http($B, 'POST', $D, array('op' => 'create', 'collection' => 'deep', 'data' => pf_nest(15, 'plain')), $u1), 'shallow doc still accepted');

    // L4: svc-a 가 X 를 보낸다 -> svc-b 가 Y 를 보낸다(전역 머리는 Y) -> svc-a 가 X 를 다시 보낸다(전부 중복)
    $A = '/server/api/platform/audit.php';
    $x = pf_aev();
    $d1 = pf_ok(pf_s2s($B, $A, 'svc-a', array('events' => array($x))), 'a first');
    pf_ok(pf_s2s($B, $A, 'svc-b', array('events' => array(pf_aev()))), 'b event moves the global head');
    $d2 = pf_ok(pf_s2s($B, $A, 'svc-a', array('events' => array($x))), 'a resend');
    assert_eq(0, (int) $d2['accepted'], 'resend accepted nothing');
    assert_eq($d1['receipt']['seq'], $d2['receipt']['seq'], 'receipt of an all-duplicate batch is this service\'s own row, not the global head');
    assert_eq($d1['receipt']['hash'], $d2['receipt']['hash'], 'own row hash');
}

t('v1.9.2 L190-3/L190-4: deep nesting capped with 400 · all-duplicate audit receipt is the caller\'s own row', function () {
    pf_sc_l34(pf_box('l34'));
});
t('mutant S-L3: depth cap removed in docs', function () {
    pf_mutant('sl3', array(array('server/core/docs.php', "if (plat_depth_over(\$data)) {   // DEPTH-CAP", "if (false) {   // DEPTH-CAP")), 'pf_sc_l34');
});
t('mutant S-L4: receipt falls back to the global head', function () {
    pf_mutant('sl4', array(array('server/api/platform/audit.php', "    \$receipt = \$mine === null ? ['seq' => 0, 'hash' => AUDIT_GENESIS]", "    \$mine = null; \$hd = (string) db_val('SELECT svalue FROM ' . T_SETTINGS . ' WHERE skey = ?', [AUDIT_HEAD_KEY]); \$mine = ['id' => (int) substr(\$hd, 0, (int) strpos(\$hd, ':')), 'row_hash' => substr(\$hd, (int) strpos(\$hd, ':') + 1)];\n    \$receipt = \$mine === null ? ['seq' => 0, 'hash' => AUDIT_GENESIS]")), 'pf_sc_l34');
});
