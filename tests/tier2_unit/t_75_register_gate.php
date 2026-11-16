<?php
declare(strict_types=1);

/**
 * 티어 2 — 가입 차단이 화면에 반영되는가 (v1.8.2 · 주관 세션 지적)
 *
 * v1.8.1 실측: 전역 가입 차단(`allow_register`=0 / `ALLOW_REGISTER=false`)인데 SSO 파라미터 없이
 * `/login` 을 열면 「회원가입」 링크가 보이고 `/register` 폼도 열렸다. 가입 API 는 403 으로 막혔다.
 * 고친 것: 공개 읽기 `GET /api/auth/register_status` (판정 함수 `registration_open()` 을 register.php 와 공유)
 *          + login.html 링크 fail-closed + register.html 폼 대신 안내.
 *
 * 무엇을 재나
 *   R) **같은 함수인가** — 설정 4상태(config true/false × settings 행 없음/0/1)에서
 *      `register_status.allow_register` 가 **실제 가입 POST 가 REGISTRATION_DISABLED 로 거절되지 않는가**와
 *      언제나 같다. 서비스 단위(`sso/validate` · `register` + service_id)도 같은 함수로 같은 값을 낸다.
 *   S) 화면 — `/login`·`/register`(별칭)와 `/public/…` 양쪽이 링크·폼을 **hidden 으로 시작**하고
 *      `allow_register === true` 일 때만 연다(값을 못 받으면 닫힌 채). 브라우저 없이 보는 정적 단언이다 —
 *      실제 렌더링 확인은 이 시험의 범위 밖이다(구현자 보고에 별도 실측).
 *
 * DB 서버 불필요 — 임시 SQLite 파일. **티어 3 이 아니라 여기 두는 이유는 t_70 과 같다.**
 * 서버: 빈 포트(8080 금지) · `bypass_shell` 로 띄워 PID 가 php.exe 자신 · `taskkill /F /T /PID` 자기 것만.
 * 픽스처 이름 접두 `ulogin_test_` 가드. `glob()` 을 쓰지 않는다.
 * 자식 `php -S` 에는 **러너가 받은 ini** 를 그대로 준다(`-n` 이면 pdo_sqlite 가 빠진다 → SKIP).
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

$GLOBALS['RG'] = array('servers' => array(), 'dirs' => array());

function rg_rm_rf(string $dir): void
{
    if (strpos(basename($dir), 'ulogin_test_') !== 0 && strpos(basename(dirname($dir)), 'ulogin_test_') !== 0) {
        return;   // 가드 — 접두가 아니면 지우지 않는다
    }
    rg_rm_rf_inner($dir);
}

function rg_rm_rf_inner(string $p): void
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
            rg_rm_rf_inner($p . '/' . $n);
        }
    }
    @rmdir($p);
}

function rg_copy_dir(string $src, string $dst): void
{
    @mkdir($dst, 0777, true);
    foreach (scandir($src) as $n) {
        if ($n === '.' || $n === '..') {
            continue;
        }
        if (is_dir($src . '/' . $n)) {
            rg_copy_dir($src . '/' . $n, $dst . '/' . $n);
        } else {
            copy($src . '/' . $n, $dst . '/' . $n);
        }
    }
}

/** config.sample.php 의 define 한 줄을 바꾼다. 못 찾으면 실패 — 조용히 표본값이 남지 않게. */
function rg_set_define(string $src, string $name, string $phpValue): string
{
    $re = '/^define\(\'' . preg_quote($name, '/') . '\',\s*[^\n]*?\);/m';
    $n = 0;
    $out = preg_replace($re, 'define(\'' . $name . '\', ' . $phpValue . ');', $src, 1, $n);
    if ($n !== 1 || !is_string($out)) {
        fail('config.sample.php: define(' . $name . ') not found exactly once');
    }
    return $out;
}

function rg_ini_or_skip(): string
{
    $ini = php_ini_loaded_file();
    if (!is_string($ini) || $ini === '' || !is_file($ini)) {
        skip('runner has no php.ini (-n?) — the child php -S would lack pdo_sqlite; run with -c <ini>');
    }
    return $ini;
}

function rg_free_port(): int
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

/** 원시 HTTP/1.0. */
function rg_http(int $port, string $method, string $path, ?string $json = null): array
{
    $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 5.0);
    if ($s === false) {
        return array('status' => 0, 'body' => '', 'json' => null);
    }
    stream_set_timeout($s, 10);
    $req = $method . ' ' . $path . " HTTP/1.0\r\nHost: 127.0.0.1:" . $port . "\r\nConnection: close\r\n";
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
    $body = $sep === false ? '' : substr($raw, $sep + 4);
    $status = preg_match('~^HTTP/\d\.\d (\d{3})~', $head, $m) ? (int) $m[1] : 0;
    $j = json_decode($body, true);
    return array('status' => $status, 'body' => $body, 'json' => is_array($j) ? $j : null);
}

/**
 * 설치 루트 사본 + SQLite + php -S. 키별 1회.
 * @return array ['port'=>int, 'db'=>string]
 */
function rg_server(string $key, bool $configAllow): array
{
    if (isset($GLOBALS['RG']['servers'][$key])) {
        return $GLOBALS['RG']['servers'][$key];
    }
    require_ext('pdo_sqlite');
    $ini = rg_ini_or_skip();
    $base = str_replace('\\', '/', sys_get_temp_dir()) . '/ulogin_test_rg_' . getmypid() . '_' . bin2hex(random_bytes(3))
        . '_' . $key;
    $root = $base . '/root';
    $GLOBALS['RG']['dirs'][] = $base;
    rg_copy_dir(h_root() . '/server', $root . '/server');
    rg_copy_dir(h_root() . '/public', $root . '/public');
    @mkdir($root . '/tools', 0777, true);
    copy(h_root() . '/tools/router-ulogin.php', $root . '/tools/router-ulogin.php');
    @mkdir($base . '/data', 0777, true);
    $db = $base . '/data/ulogin.sqlite';           // 문서 루트 밖

    $pdo = new PDO('sqlite:' . $db, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    mig_sqlite_create_schema($pdo, h_root() . '/server/schema.sql', 'app_ulogin_', true);
    $pdo = null;

    $port = rg_free_port();
    $cfg = (string) file_get_contents(h_root() . '/server/config.sample.php');
    $cfg = rg_set_define($cfg, 'DB_DRIVER', "'sqlite'");
    $cfg = rg_set_define($cfg, 'DB_SQLITE_PATH', var_export($db, true));
    $cfg = rg_set_define($cfg, 'SECRET_ENC_KEY', "'" . bin2hex(random_bytes(32)) . "'");
    $cfg = rg_set_define($cfg, 'APP_KEY', "'" . bin2hex(random_bytes(32)) . "'");
    $cfg = rg_set_define($cfg, 'FORCE_HTTPS', 'false');
    $cfg = rg_set_define($cfg, 'COOKIE_SECURE', 'false');
    $cfg = rg_set_define($cfg, 'BASE_URL', "'http://127.0.0.1:" . $port . "'");
    $cfg = rg_set_define($cfg, 'ALLOW_REGISTER', $configAllow ? 'true' : 'false');
    file_put_contents($root . '/server/config.php', $cfg);

    $log = $base . '/server.log';
    $cmd = h_q(h_php_bin()) . ' -c ' . h_q($ini) . ' -S 127.0.0.1:' . $port . ' -t ' . h_q($root) . ' '
        . h_q($root . '/tools/router-ulogin.php');
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
    $GLOBALS['RG']['servers'][$key] = array('port' => $port, 'db' => $db, 'proc' => $proc, 'pid' => (int) $st['pid']);
    // 센티널: 이 실행이 심은 version.txt — 「우리 서버 + 우리 루트」
    $token = bin2hex(random_bytes(8));
    file_put_contents($root . '/public/version.txt', $token . "\n");
    $ok = false;
    for ($i = 0; $i < 100; $i++) {
        $r = rg_http($port, 'GET', '/version.txt');
        if ($r['status'] === 200 && trim($r['body']) === $token) {
            $ok = true;
            break;
        }
        usleep(100000);
    }
    if (!$ok) {
        fail('php -S on port ' . $port . ' did not answer with this run\'s sentinel');
    }
    return $GLOBALS['RG']['servers'][$key];
}

/** settings.allow_register 행: null = 행 없음 / '0' / '1' */
function rg_setting(string $db, ?string $v): void
{
    $pdo = new PDO('sqlite:' . $db, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $pdo->exec("DELETE FROM app_ulogin_settings WHERE skey = 'allow_register'");
    if ($v !== null) {
        $st = $pdo->prepare('INSERT INTO app_ulogin_settings (skey, svalue, vtype, label, updated_at) VALUES (?,?,?,?,?)');
        $st->execute(array('allow_register', $v, 'bool', '신규 가입 허용', gmdate('Y-m-d H:i:s')));
    }
    $pdo = null;
}

function rg_teardown(): void
{
    foreach ($GLOBALS['RG']['servers'] as $sv) {
        if ($sv['pid'] > 0 && DIRECTORY_SEPARATOR === '\\') {
            $o = array();
            $rc = 0;
            @exec('taskkill /F /T /PID ' . $sv['pid'] . ' 2>&1', $o, $rc);
        }
        @proc_terminate($sv['proc'], 9);
        @proc_close($sv['proc']);
    }
    $GLOBALS['RG']['servers'] = array();
    usleep(200000);
    foreach ($GLOBALS['RG']['dirs'] as $d) {
        rg_rm_rf($d);
    }
    $GLOBALS['RG']['dirs'] = array();
}
register_shutdown_function('rg_teardown');

/** 한 상태에서: 공개 읽기 값 + 실제 가입 POST 가 게이트에서 막혔는가. */
function rg_probe(int $port): array
{
    $s = rg_http($port, 'GET', '/server/api/auth/register_status.php');
    assert_eq(200, $s['status'], 'register_status status (body: ' . substr($s['body'], 0, 200) . ')');
    assert_true(is_array($s['json']) && $s['json']['ok'] === true, 'register_status envelope ok');
    assert_eq(array('allow_register'), array_keys($s['json']['data']), 'register_status data keys (pure addition, one key)');
    assert_true(is_bool($s['json']['data']['allow_register']), 'allow_register is a JSON boolean');
    // 본문 {} — 게이트를 지나면 VALIDATION_FAILED(400), 막히면 REGISTRATION_DISABLED(403). 사용자를 만들지 않는다.
    $p = rg_http($port, 'POST', '/server/api/auth/register.php', '{}');
    $code = is_array($p['json']) && is_array($p['json']['error']) ? (string) $p['json']['error']['code'] : '';
    assert_true($code !== '' && $code !== 'RATE_LIMITED', 'register POST must reach the gate (got ' . $p['status'] . ' ' . $code . ')');
    return array($s['json']['data']['allow_register'], $code !== 'REGISTRATION_DISABLED', $p['status'], $code);
}

// =====================================================================
// R) 공개 읽기와 가입 API 가 같은 값을 낸다 — 4상태
// =====================================================================

$RG_STATES = array(
    // [서버 키, config ALLOW_REGISTER, settings 행, 기대 allow]
    array('cfgT', true,  null, true),
    array('cfgT', true,  '0',  false),
    array('cfgT', true,  '1',  true),
    array('cfgF', false, null, false),
    array('cfgF', false, '1',  true),
    array('cfgF', false, '0',  false),
);

foreach ($RG_STATES as $st) {
    list($key, $cfgAllow, $row, $want) = $st;
    $label = 'ALLOW_REGISTER=' . ($cfgAllow ? 'true' : 'false') . ' settings=' . ($row === null ? '(none)' : $row);
    t('R ' . $label . ': register_status says ' . ($want ? 'true' : 'false') . ' and register agrees',
        function () use ($key, $cfgAllow, $row, $want) {
            $sv = rg_server($key, $cfgAllow);
            rg_setting($sv['db'], $row);
            list($pub, $passed, $status, $code) = rg_probe($sv['port']);
            assert_eq($want, $pub, 'register_status.allow_register');
            assert_eq($want, $passed, 'register POST passed the gate (got ' . $status . ' ' . $code . ')');
            if (!$want) {
                assert_eq(403, $status, 'blocked register status');
            }
        });
}

// 서비스 단위 — validate 와 register(+service_id) 가 같은 함수로 같은 값을 낸다. 전역은 열어 둔다.
t('R service allow_register=0: validate says false, register with service_id is refused, global read stays true',
    function () {
        $sv = rg_server('cfgT', true);
        rg_setting($sv['db'], '1');
        $pdo = new PDO('sqlite:' . $sv['db'], null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        $pdo->exec("DELETE FROM app_ulogin_services WHERE service_id IN ('rg-closed','rg-open')");
        $ins = $pdo->prepare('INSERT INTO app_ulogin_services (service_id, service_name, redirect_whitelist, allow_register,'
            . ' status, created_at, updated_at) VALUES (?,?,?,?,?,?,?)');
        $now = gmdate('Y-m-d H:i:s');
        $ins->execute(array('rg-closed', 'RG Closed', '["http://localhost:5173/cb"]', 0, 'active', $now, $now));
        $ins->execute(array('rg-open', 'RG Open', '["http://localhost:5173/cb"]', 1, 'active', $now, $now));
        $pdo = null;
        $q = '&redirect_uri=' . rawurlencode('http://localhost:5173/cb');
        $vc = rg_http($sv['port'], 'GET', '/server/api/sso/validate.php?service_id=rg-closed' . $q);
        $vo = rg_http($sv['port'], 'GET', '/server/api/sso/validate.php?service_id=rg-open' . $q);
        assert_eq(200, $vc['status'], 'validate rg-closed (' . substr($vc['body'], 0, 200) . ')');
        assert_false($vc['json']['data']['allow_register'], 'validate rg-closed allow_register');
        assert_true($vo['json']['data']['allow_register'], 'validate rg-open allow_register');
        $p = rg_http($sv['port'], 'POST', '/server/api/auth/register.php', json_encode(array(
            'username' => 'rgtest01', 'email' => 'rg@example.invalid', 'password' => 'Abcd1234!x',
            'password_confirm' => 'Abcd1234!x', 'agree_tos' => true, 'agree_privacy' => true,
            'service_id' => 'rg-closed')));
        assert_eq(403, $p['status'], 'register via closed service');
        assert_eq('REGISTRATION_DISABLED', (string) $p['json']['error']['code'], 'register via closed service code');
        assert_true(rg_http($sv['port'], 'GET', '/server/api/auth/register_status.php')['json']['data']['allow_register'],
            'global read ignores services (no service_id parameter)');
    });

// v1.8.4 — 시험 공백 L-5(`_workspace/96_verify_v182.md`). 위 케이스는 전역 '1' 에서만 서비스를 돌려서
// 「서비스 값만 보는」 옛 판정(`(bool) $service['allow_register']`)을 심어도 통과했다(변이 N7 생존).
// 전역 차단 × 서비스 허용 — 전역이 이겨야 한다.
t('R global allow_register=0 x service allow_register=1: validate says false and register(+service_id) is refused (L-5)',
    function () {
        $sv = rg_server('cfgT', true);
        rg_setting($sv['db'], '0');
        $pdo = new PDO('sqlite:' . $sv['db'], null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        $pdo->exec("DELETE FROM app_ulogin_services WHERE service_id = 'rg-open'");
        $now = gmdate('Y-m-d H:i:s');
        $pdo->prepare('INSERT INTO app_ulogin_services (service_id, service_name, redirect_whitelist, allow_register,'
            . ' status, created_at, updated_at) VALUES (?,?,?,?,?,?,?)')
            ->execute(array('rg-open', 'RG Open', '["http://localhost:5173/cb"]', 1, 'active', $now, $now));
        $pdo = null;
        $vo = rg_http($sv['port'], 'GET', '/server/api/sso/validate.php?service_id=rg-open&redirect_uri='
            . rawurlencode('http://localhost:5173/cb'));
        assert_eq(200, $vo['status'], 'validate rg-open (' . substr($vo['body'], 0, 200) . ')');
        assert_true($vo['json']['data']['allow_register'] === false, 'global off wins over service on (validate)');
        $p = rg_http($sv['port'], 'POST', '/server/api/auth/register.php', json_encode(array(
            'username' => 'rgtest02', 'email' => 'rg2@example.invalid', 'password' => 'Abcd1234!x',
            'password_confirm' => 'Abcd1234!x', 'agree_tos' => true, 'agree_privacy' => true,
            'service_id' => 'rg-open')));
        assert_eq(403, $p['status'], 'register via open service while global is off');
        assert_eq('REGISTRATION_DISABLED', (string) $p['json']['error']['code'], 'register via open service code');
        rg_setting($sv['db'], '1');
    });

t('R register_status is GET only and needs no session', function () {
    $sv = rg_server('cfgT', true);
    $p = rg_http($sv['port'], 'POST', '/server/api/auth/register_status.php', '{}');
    assert_eq(405, $p['status'], 'POST register_status');
});

// =====================================================================
// S) 화면 — 별칭과 /public/ 둘 다. 링크·폼은 hidden 으로 시작하고 true 일 때만 열린다
// =====================================================================

$RG_PAGES = array(
    '/login' => 'login', '/login.html' => 'login', '/public/login.html' => 'login',
    '/register' => 'register', '/register.html' => 'register', '/public/register.html' => 'register',
);
foreach ($RG_PAGES as $path => $kind) {
    t('S ' . $path . ' starts closed and opens only on allow_register === true', function () use ($path, $kind) {
        $sv = rg_server('cfgF', false);
        $r = rg_http($sv['port'], 'GET', $path);
        assert_eq(200, $r['status'], 'GET ' . $path);
        $b = $r['body'];
        assert_contains("apiGet('/api/auth/register_status')", $b, 'page reads the public gate');
        if ($kind === 'login') {
            assert_match('~<a [^>]*id="link-register"[^>]*\shidden>~', $b, 'register link is hidden in markup');
            assert_contains('linkRegister.hidden = !(data && data.allow_register === true);', $b, 'reveal only on true');
            assert_contains('.catch(function () { linkRegister.hidden = true; });', $b, 'hide on failure');
            assert_contains("linkRegister.hidden = (info.allow_register !== true);", $b, 'SSO path: hide unless true');
            assert_not_contains('info.allow_register === false', $b, 'old fail-open test must be gone');
        } else {
            assert_match('~<section [^>]*id="register-card"[^>]*\shidden>~', $b, 'register form card is hidden in markup');
            assert_match('~<section [^>]*id="closed-card"[^>]*\shidden>~', $b, 'closed notice card exists');
            assert_contains('if (data && data.allow_register === true) {', $b, 'form opens only on true');
            assert_contains('현재 신규 가입 중단, 관리자 문의', $b, 'closed notice text');
            // v1.8.4 — 시험 공백 L-6. login.html 은 catch 문자열을 고정했는데 register.html 은 then 조건만
            // 고정해서, 요청 실패 시 폼을 여는 변이(N5: catch 본문 → `cardReg.hidden = false;`)가 통과했다.
            assert_contains("' 잠시 후 다시 시도해 주세요.', 'danger', true);", $b, 'request failure keeps the form closed with a retry notice');
            assert_eq(1, substr_count($b, 'cardReg.hidden = false'), 'the form opens in exactly one place (allow_register === true)');
        }
        // v1.8.3 — 판 번호를 박지 않는다(판마다 이 줄이 깨졌다). public/version.txt 가 정본이고
        // 화면의 ?v= 가 **전부** 그 값이어야 한다(남은 옛 값 = 그 자산이 갱신되지 않는다).
        $vt = trim(h_read(h_root() . '/public/version.txt'));
        if (preg_match('~^(\d+\.\d+\.\d+)~', $vt, $vm) !== 1) {
            fail('public/version.txt has no version: ' . $vt);
        }
        assert_contains('?v=' . $vm[1], $b, 'asset version');
        preg_match_all('~\?v=([0-9][0-9A-Za-z._-]*)~', $b, $all);
        assert_eq(array($vm[1]), array_values(array_unique($all[1])), 'every ?v= on the page equals version.txt');
    });
}

rg_teardown();
