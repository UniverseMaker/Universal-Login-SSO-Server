<?php
declare(strict_types=1);

/**
 * 티어 2 — `tools/router-ulogin.php` 의 `/public` 없는 별칭 (v1.8.1 / 리더 판정 D-313·D-316)
 *
 * **이 파일만 `php -S` 를 띄운다.** 그래도 티어 3 이 아니라 여기 두는 이유: DB 가 필요 없다.
 * 티어 3 은 「DB 가 없으면 전부 SKIP → `--tier=3` 이 종료코드 3」이 설계이고, DB 없이 도는
 * 케이스를 거기 섞으면 그 신호가 거짓이 된다. 이 파일은 DB 없이 **언제나** 돈다.
 *
 * 무엇을 재나
 *   A) 별칭 표 — `/` · `/{page}` · `/{page}.html` · `/assets/*` · `/version.txt` 가
 *      `public/` 의 **같은 바이트**를 **public 영역 헤더(CSP)** 와 함께 내는가. 기존 `/public/…` 도.
 *   B) 우회 — 민감 파일을 별칭 철자로 노리는 요청이 **하나도 200 이 아니고**, 표지(marker)
 *      문자열이 본문에 **나오지 않는가.** 요청은 원시 소켓으로 보낸다(`..`·`%2e` 를 클라이언트가
 *      펴 버리지 않게).
 *   C) 접합 — `public/assets` 가 설치 루트 안의 다른 폴더를 가리키는 접합일 때 별칭이 404 인가.
 *      그리고 **변이**: 접두 검사 한 줄을 지운 라우터 사본에서는 같은 요청이 200 으로 새는가.
 *      (변이가 통과하지 않으면 그 한 줄이 아무것도 지키지 않는다는 뜻이다 — 규약 D-186)
 *
 * 서버: 빈 포트를 OS 에게 받아 쓴다(8080 은 절대 쓰지 않는다). 센티널로 「우리 서버」를 확인한다.
 * 종료: `taskkill /F /T /PID <자기 PID>` — `bypass_shell` 로 띄워 PID 가 `php.exe` 자신이다.
 * 픽스처는 `sys_get_temp_dir()` 아래(경로에 대괄호가 없다). `glob()` 을 쓰지 않는다.
 */

$GLOBALS['RA'] = array('servers' => array(), 'dirs' => array(), 'junctions' => array());

function ra_router_src(): string
{
    return h_root() . '/tools/router-ulogin.php';
}

function ra_copy_dir(string $src, string $dst): void
{
    @mkdir($dst, 0777, true);
    foreach (scandir($src) as $n) {
        if ($n === '.' || $n === '..') {
            continue;
        }
        $s = $src . '/' . $n;
        $d = $dst . '/' . $n;
        if (is_dir($s)) {
            ra_copy_dir($s, $d);
        } else {
            copy($s, $d);
        }
    }
}

function ra_rm_rf(string $dir): void
{
    if (!file_exists($dir) && !is_link($dir)) {
        return;
    }
    if (is_file($dir) || is_link($dir)) {
        @unlink($dir);
        return;
    }
    foreach (scandir($dir) as $n) {
        if ($n === '.' || $n === '..') {
            continue;
        }
        ra_rm_rf($dir . '/' . $n);
    }
    @rmdir($dir);
}

function ra_put(string $path, string $body): void
{
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $body);
}

/**
 * 합성 설치 루트. `public/` 은 **실물 사본**이고, 민감 자리에는 표지 파일을 심는다.
 * @param bool $junction  true 면 `public/assets` 를 `docs/` 로 가는 접합으로 바꾼다
 */
function ra_fixture(bool $junction): string
{
    $root = str_replace('\\', '/', sys_get_temp_dir()) . '/ulogin_ra_' . getmypid() . '_' . mt_rand(10000, 99999)
        . ($junction ? '_j' : '');
    $GLOBALS['RA']['dirs'][] = $root;
    ra_copy_dir(h_root() . '/public', $root . '/public');
    ra_put($root . '/server/config.php', "<?php echo 'CFG_' . 'MARKER_RAN';\n// CFG_MARKER_SRC\n");
    ra_put($root . '/server/config.sample.php', "<?php // CFG_MARKER_SRC\n");
    ra_put($root . '/server/schema.sql', "-- SCHEMA_MARKER\n");
    ra_put($root . '/server/core/bootstrap.php', "<?php echo 'CORE_' . 'MARKER_RAN';\n");
    ra_put($root . '/tools/router-ulogin.php', "<?php // TOOLS_MARKER\n");
    ra_put($root . '/tests/run.php', "<?php // TESTS_MARKER\n");
    // 설치 루트 직하에는 config.php·schema.sql 이 **없다**(실물 배치). 거기 심으면 별칭과 무관한
    // 「루트의 PHP 는 실행된다」를 재게 된다(Apache 도 같다). 별칭이 server/ 의 것을 끌어오는지만 본다.
    ra_put($root . '/.htaccess', "# DOT_MARKER\n");
    ra_put($root . '/public/assets/probe.php', "<?php echo 'PROBE_' . 'MARKER_RAN';\n");
    ra_put($root . '/docs/leak.js', "/* LEAK_MARKER */\n");
    // v1.8.2 (검증 89 M-1·L-3) — 변이 M3·M9·C17-1(8.3)이 노릴 자리. 실물 public/ 에는 없는 파일이다.
    ra_put($root . '/server/.htaccess', "# SRVHT_MARKER\n");
    ra_put($root . '/public/unlisted.html', "<!-- UNLISTED_MARKER -->\n");      // 별칭 페이지 목록 밖
    ra_put($root . '/public/assets/sub/x.css', "/* SUB_MARKER */\n");           // assets 두 조각
    // v1.8.9 허용 목록 — 화면이 안 쓰는 파일(설치 루트 문서·migrate.php·그 밖 .php·public 의 .txt/.json)
    ra_put($root . '/README.md', "AL_MARKER_README
");
    ra_put($root . '/LICENSE', "AL_MARKER_LICENSE
");
    ra_put($root . '/docs/PORTFOLIO.md', "AL_MARKER_DOCS
");
    ra_put($root . '/server/migrate.php', "<?php echo 'AL_MARKER_' . 'MIGRATE_RAN';
");
    ra_put($root . '/server/other.php', "<?php echo 'AL_MARKER_' . 'OTHER_RAN';
");
    ra_put($root . '/server/api/ok.php', "<?php echo 'API_' . 'OK_RAN';
");
    ra_put($root . '/public/notes.txt', "AL_MARKER_TXT
");
    ra_put($root . '/public/data.json', "{\"AL_MARKER_JSON\":1}
");
    ra_put($root . '/examples/ex.html', "<!-- EX_OK -->
");
    ra_put($root . '/examples/README.md', "AL_MARKER_EXREADME
");
    if ($junction) {
        ra_rm_rf($root . '/public/assets');
        $link = str_replace('/', '\\', $root . '/public/assets');
        $tgt  = str_replace('/', '\\', $root . '/docs');
        $out = array();
        $rc = 0;
        exec('cmd /c mklink /J ' . h_q($link) . ' ' . h_q($tgt) . ' 2>&1', $out, $rc);
        if ($rc !== 0 || !is_file($root . '/public/assets/leak.js')) {
            skip('cannot create a directory junction here (mklink /J rc=' . $rc . ')');
        }
        $GLOBALS['RA']['junctions'][] = $root . '/public/assets';
    }
    return $root;
}

function ra_free_port(): int
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
        // 비어 있는지 한 번 더 — 이미 누가 듣고 있으면 쓰지 않는다
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

/**
 * 서버 하나(키별로 1회). 반환: 포트.
 * @param string $key       캐시 키
 * @param bool   $junction  픽스처 종류
 * @param string $router    라우터 파일 경로
 */
function ra_server(string $key, bool $junction, string $router): int
{
    if (isset($GLOBALS['RA']['servers'][$key])) {
        return $GLOBALS['RA']['servers'][$key]['port'];
    }
    if (!is_file($router)) {
        fail('router not found: ' . $router);
    }
    $root = ra_fixture($junction);
    $port = ra_free_port();
    $token = bin2hex(random_bytes(8));
    // 센티널은 별칭 대상(version.txt)에 심는다 — 「우리 서버 + 우리 라우터」를 한 번에 확인한다
    file_put_contents($root . '/public/version.txt', $token . "\n");
    $cmd = h_q(h_php_bin()) . ' -n -S 127.0.0.1:' . $port . ' -t ' . h_q($root) . ' ' . h_q($router);
    $log = $root . '.server.log';
    $GLOBALS['RA']['dirs'][] = $log;
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
    $GLOBALS['RA']['servers'][$key] = array('port' => $port, 'proc' => $proc, 'pid' => (int) $st['pid'],
        'root' => $root);
    $ok = false;
    for ($i = 0; $i < 100; $i++) {
        $r = ra_get($port, '/version.txt', 0.5);
        if ($r['status'] === 200 && trim($r['body']) === $token) {
            $ok = true;
            break;
        }
        usleep(100000);
    }
    if (!$ok) {
        fail('router server on port ' . $port . ' did not answer with this run\'s sentinel');
    }
    // 이후 케이스가 실물 version.txt 와 비교할 수 있게 되돌린다
    copy(h_root() . '/public/version.txt', $root . '/public/version.txt');
    return $port;
}

/** 원시 HTTP/1.0 GET. 경로를 **한 글자도 바꾸지 않고** 보낸다. */
function ra_get(int $port, string $path, float $timeout = 5.0): array
{
    $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, $timeout);
    if ($s === false) {
        return array('status' => 0, 'headers' => array(), 'body' => '');
    }
    stream_set_timeout($s, 5);
    fwrite($s, 'GET ' . $path . " HTTP/1.0\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
    $raw = '';
    while (!feof($s)) {
        $chunk = fread($s, 65536);
        if ($chunk === false || $chunk === '') {
            $meta = stream_get_meta_data($s);
            if ($meta['timed_out']) {
                break;
            }
            if ($chunk === false) {
                break;
            }
        }
        $raw .= $chunk;
    }
    fclose($s);
    $sep = strpos($raw, "\r\n\r\n");
    $head = $sep === false ? $raw : substr($raw, 0, $sep);
    $body = $sep === false ? '' : substr($raw, $sep + 4);
    $lines = explode("\r\n", $head);
    $status = 0;
    if (isset($lines[0]) && preg_match('~^HTTP/\d\.\d (\d{3})~', $lines[0], $m)) {
        $status = (int) $m[1];
    }
    $hdr = array();
    foreach (array_slice($lines, 1) as $l) {
        $c = strpos($l, ':');
        if ($c !== false) {
            $hdr[strtolower(trim(substr($l, 0, $c)))] = trim(substr($l, $c + 1));
        }
    }
    return array('status' => $status, 'headers' => $hdr, 'body' => $body);
}

function ra_teardown(): void
{
    foreach ($GLOBALS['RA']['servers'] as $k => $sv) {
        if ($sv['pid'] > 0 && DIRECTORY_SEPARATOR === '\\') {
            $o = array();
            $rc = 0;
            @exec('taskkill /F /T /PID ' . $sv['pid'] . ' 2>&1', $o, $rc);
        }
        @proc_terminate($sv['proc'], 9);
        @proc_close($sv['proc']);
    }
    $GLOBALS['RA']['servers'] = array();
    usleep(200000);
    // 접합은 **먼저 링크만** 지운다 — rmdir 은 접합 자신을 지우고 대상을 건드리지 않는다
    foreach ($GLOBALS['RA']['junctions'] as $j) {
        @rmdir($j);
    }
    $GLOBALS['RA']['junctions'] = array();
    foreach ($GLOBALS['RA']['dirs'] as $d) {
        ra_rm_rf($d);
    }
    $GLOBALS['RA']['dirs'] = array();
}
register_shutdown_function('ra_teardown');

function ra_main(): int
{
    return ra_server('main', false, ra_router_src());
}

// =====================================================================
// A) 별칭 표
// =====================================================================

$RA_ALIAS = array(
    '/'                     => 'index.html',
    '/index'                => 'index.html',
    '/index.html'           => 'index.html',
    '/version.txt'          => 'version.txt',
    '/version.json'         => 'version.json',
    '/assets/api.js'        => 'assets/api.js',
    '/assets/api.js?v=1.8.1' => 'assets/api.js',
    '/assets/style.css?v=1.8.1' => 'assets/style.css',
    '/login?service_id=demo&redirect_uri=http%3A%2F%2Flocalhost%2Fcb&state=s' => 'login.html',
    '/public/login.html'    => 'login.html',
    '/public/'              => 'index.html',
    '/public/assets/ui.js'  => 'assets/ui.js',
);
foreach (array('admin', 'forgot', 'login', 'mypage', 'register', 'reset', 'verify') as $pg) {
    $RA_ALIAS['/' . $pg] = $pg . '.html';
    $RA_ALIAS['/' . $pg . '.html'] = $pg . '.html';
}

foreach ($RA_ALIAS as $reqPath => $file) {
    t('alias ' . $reqPath . ' serves public/' . $file, function () use ($reqPath, $file) {
        $port = ra_main();
        $r = ra_get($port, $reqPath);
        assert_eq(200, $r['status'], 'status of ' . $reqPath);
        $want = (string) file_get_contents(h_root() . '/public/' . $file);
        assert_true($r['body'] === $want, 'body of ' . $reqPath . ' must be byte-identical to public/' . $file
            . ' (got ' . strlen($r['body']) . 'B, want ' . strlen($want) . 'B)');
        $ext = substr($file, strrpos($file, '.') + 1);
        if ($ext === 'html') {
            // public 영역 헤더가 별칭 주소에도 붙어야 한다(D-236 — 로컬 통과/호스팅 실패 방지)
            assert_true(isset($r['headers']['content-security-policy']), 'CSP header on ' . $reqPath);
            assert_eq('DENY', isset($r['headers']['x-frame-options']) ? $r['headers']['x-frame-options'] : '',
                'X-Frame-Options on ' . $reqPath);
            assert_eq('no-store', isset($r['headers']['cache-control']) ? $r['headers']['cache-control'] : '',
                'Cache-Control on ' . $reqPath);
        }
        if ($ext === 'js' || $ext === 'css') {
            assert_contains('immutable', isset($r['headers']['cache-control']) ? $r['headers']['cache-control'] : '',
                'asset cache on ' . $reqPath);
        }
        if ($file === 'version.json') {
            assert_eq('no-store', isset($r['headers']['cache-control']) ? $r['headers']['cache-control'] : '',
                'version.json must not be cached');
            assert_eq('no-store', isset($r['headers']['cdn-cache-control']) ? $r['headers']['cdn-cache-control'] : '',
                'version.json CDN-Cache-Control');
        }
        if ($file === 'version.txt') {
            assert_eq('no-store', isset($r['headers']['cache-control']) ? $r['headers']['cache-control'] : '',
                'version.txt must not be cached');
        }
    });
}

// =====================================================================
// B) 우회 시도 — 200 이 하나도 없어야 하고, 표지가 본문에 나오면 안 된다
// =====================================================================

$RA_BYPASS = array(
    '/config', '/config.php', '/schema.sql', '/.htaccess',
    '/server/config.php', '/server/config', '/server/schema.sql', '/server/core/bootstrap.php',
    '/tools/router-ulogin.php', '/tools/', '/tools', '/tests/run.php',
    '/../server/config.php', '/login.html/../../server/config.php', '/login/../server/config.php',
    '/assets/../../server/config.php', '/assets/../server/schema.sql',
    '/%2e%2e/server/config.php', '/%2E%2E/server/config.php', '/assets/%2e%2e/%2e%2e/server/config.php',
    '/login.html%2f..%2f..%2fserver%2fconfig.php', '/assets/..%2f..%2fserver%2fconfig.php',
    '/assets/..%5c..%5cserver%5cconfig.php', '/assets%2f..%2fserver%2fconfig.php',
    '/LOGIN', '/Login.html', '/LOGIN.HTML', '/ASSETS/API.JS', '/assets/API.JS', '/Version.txt',
    '/login.', '/login.html.', '/login%20', '/login.html%20', '/version.txt.', '/assets/api.js.',
    '/login::$DATA', '/login.html::$DATA', '/assets/api.js::$DATA', '/assets::$INDEX_ALLOCATION/api.js',
    '/login%00', '/login.html%00.txt', '/login/', '/assets/', '/assets',
    '/assets/probe.php', '/assets/.htaccess', '/assets/sub/x.css', '/index.php', '/login.php',
    '/CONFIG~1.PHP', '/server/CONFIG~1.PHP', '/SCHEMA~1.SQL',
    '/login.html/', '/login.html/x', '/assets/api.js/x.css', '/config.sample', '/server',
    // v1.8.2 — 검증 89 M-1: v1.7.0 C17-1·C17-2 철자. 라우터가 판마다 바뀌는 파일이 됐는데
    // 이것을 지키는 시험이 트리에 없었다. (a-0) 허용 글자 줄 하나가 홀로 막는다 — 아래 변이 MA0 참조.
    '/server::$INDEX_ALLOCATION/schema.sql', '/server/core::$INDEX_ALLOCATION/bootstrap.php',
    '/server./schema.sql', '/server/core./bootstrap.php', '/server/HTACCE~1',
    '/public::$INDEX_ALLOCATION/login.html',                 // CSP 없는 200 금지 (C17-2 헤더 축)
    '/tools::$INDEX_ALLOCATION/router-ulogin.php',
    // v1.8.2 — 검증 89 L-1: 끝 `\n`. `$` 앵커는 이것을 통과시켰다(v1.8.1 에서 /login%0a 가 200)
    '/login%0a', '/login.html%0a', '/assets/api.js%0a', '/server/core%0a/bootstrap.php',
    // v1.8.2 — 검증 89 L-3: 생존 변이 M1(대소문자 재확인) · M3(페이지 목록) · M9(assets 한 조각)
    '/assets/API.js', '/assets/Api.js', '/unlisted', '/unlisted.html',
);

$RA_MARKERS = array('CFG_MARKER', 'SCHEMA_MARKER', 'CORE_MARKER', 'TOOLS_MARKER', 'TESTS_MARKER',
    'ROOTCFG_MARKER', 'DOT_MARKER', 'PROBE_MARKER', 'LEAK_MARKER', 'SRVHT_MARKER', 'UNLISTED_MARKER',
    'SUB_MARKER');

foreach ($RA_BYPASS as $reqPath) {
    t('bypass ' . $reqPath . ' does not leak', function () use ($reqPath, $RA_MARKERS) {
        $port = ra_main();
        $r = ra_get($port, $reqPath);
        assert_true($r['status'] !== 0, 'server must answer ' . $reqPath);
        assert_true($r['status'] !== 200, $reqPath . ' must not be 200 (got ' . $r['status'] . ', '
            . strlen($r['body']) . 'B)');
        foreach ($RA_MARKERS as $mk) {
            assert_not_contains($mk, $r['body'], 'marker ' . $mk . ' in body of ' . $reqPath);
        }
    });
}

// L-1 — `%0a` 조각은 (a-0) 에서 **403** 으로 끊겨야 한다. 「200 이 아니다」만으로는 `$` 로 되돌린
// (a-0) 을 못 잡는다: 그 경우 문자열 판정을 통과하고 OS 가 파일을 못 열어 404 가 난다(유출 없음).
// 끊기는 자리를 응답 코드로 고정해 둔다 — 변이 MZ1 이 이 단언을 깨야 한다.
t('L-1 a trailing %0a segment is refused at (a-0) with 403, not left to the filesystem', function () {
    $r = ra_get(ra_main(), '/server/core%0a/bootstrap.php');
    assert_eq(403, $r['status'], '/server/core%0a/bootstrap.php');
    assert_eq(403, ra_get(ra_main(), '/login%0a')['status'], '/login%0a');
});

// =====================================================================
// C) 접합 + 변이 — 접두 검사 한 줄이 실제로 지키고 있는가
// =====================================================================

t('alias through a junction out of public/assets is 404 (realpath prefix check)', function () {
    if (DIRECTORY_SEPARATOR !== '\\') {
        skip('junction case is Windows-only');
    }
    $port = ra_server('junction', true, ra_router_src());
    $r = ra_get($port, '/assets/leak.js');
    assert_eq(404, $r['status'], 'alias must not follow a junction out of public/assets');
    assert_not_contains('LEAK_MARKER', $r['body'], 'leak body');
    // 대조: 같은 서버의 정상 별칭은 산다(서버가 죽어서 404 인 것이 아님을 보인다)
    assert_eq(200, ra_get($port, '/login')['status'], 'control: /login on the junction fixture');
});

/**
 * v1.8.9 — 허용 목록(r_allowed)이 두 번째 겹으로 들어왔다. 아래 변이는 **그 변이가 지우는 한 겹**을
 * 시험한다 — 허용 목록까지 켜 두면 다른 겹이 막아 「사본이 새지 않는다」가 되어 변이가 눈먼다.
 * 그래서 변이 사본에서는 허용 목록 두 곳을 끈다. 허용 목록 자신의 변이는 아래 E) 가 따로 잰다.
 */
function ra_allow_off(string $src, string $key): string
{
    foreach (array('if (!r_allowed($clean)) {', 'if (!r_allowed($canon)) {') as $a) {
        assert_eq(1, substr_count($src, $a), 'mutation ' . $key . ': allow-list anchor must appear exactly once: ' . $a);
        $src = str_replace($a, 'if (false) {', $src);
    }
    return $src;
}

t('mutation: the same request leaks once the prefix-check line is removed', function () {
    if (DIRECTORY_SEPARATOR !== '\\') {
        skip('junction case is Windows-only');
    }
    $src = (string) file_get_contents(ra_router_src());
    $needle = "if (strpos(\$realFile, \$realPub . '/') !== 0) {";
    $n = substr_count($src, $needle);
    assert_eq(1, $n, 'mutation anchor must appear exactly once in the router (else this mutation is blind)');
    $mut = ra_allow_off(str_replace($needle, 'if (false) {', $src), 'junction mutation');
    $dir = str_replace('\\', '/', sys_get_temp_dir()) . '/ulogin_ra_mut_' . getmypid() . '_' . mt_rand(10000, 99999);
    @mkdir($dir, 0777, true);
    $GLOBALS['RA']['dirs'][] = $dir;
    file_put_contents($dir . '/router-ulogin.php', $mut);
    $port = ra_server('mutant', true, $dir . '/router-ulogin.php');
    $r = ra_get($port, '/assets/leak.js');
    assert_eq(200, $r['status'], 'mutant router must leak (otherwise the check line guards nothing)');
    assert_contains('LEAK_MARKER', $r['body'], 'mutant leak body');
});

// =====================================================================
// D) 변이 — v1.8.2 (검증 89 M-1 · L-1 · L-3). 「위 B 표가 눈떠 있는가」
//
// 각 변이는 **라우터 한 자리**를 바꾼 사본으로 서버를 띄우고, 같은 요청이 원본에서는 막히고
// 사본에서는 새는지 본다. 사본에서도 막히면 그 자리를 지키는 시험이 없다는 뜻이다(규약 D-186).
// 바꿀 문자열이 원본에 **정확히 1번** 나와야 한다 — 0번이면 변이가 아무것도 안 바꾼 채 「통과」한다.
//
//   $probes: [요청 경로, 원본 기대 상태, 사본 기대 상태, 사본 본문에 있어야 할 표지(없으면 '')]
// =====================================================================

function ra_mutant_router(string $key, array $edits): string
{
    $src = (string) file_get_contents(ra_router_src());
    foreach ($edits as $needle => $repl) {
        assert_eq(1, substr_count($src, $needle),
            'mutation ' . $key . ': anchor must appear exactly once (else this mutation is blind): ' . $needle);
        $src = str_replace($needle, $repl, $src);
    }
    $src = ra_allow_off($src, $key);
    $dir = str_replace('\\', '/', sys_get_temp_dir()) . '/ulogin_ra_mut_' . $key . '_' . getmypid() . '_'
        . mt_rand(10000, 99999);
    @mkdir($dir, 0777, true);
    $GLOBALS['RA']['dirs'][] = $dir;
    file_put_contents($dir . '/router-ulogin.php', $src);
    return $dir . '/router-ulogin.php';
}

$RA_MUTANTS = array(
    // M-1 — (a-0) 허용 글자 줄 제거. C17-1·C17-2 의 스트림 접미 철자가 되살아나야 한다.
    'MA0' => array(
        'windows' => true,
        'edits'   => array("if (preg_match('/^[A-Za-z0-9._-]+\\z/', \$s) !== 1) {" => 'if (false) {'),
        'probes'  => array(
            array('/server::$INDEX_ALLOCATION/schema.sql', 403, 200, 'SCHEMA_MARKER'),
        ),
    ),
    // L-1 — (a-0) 의 끝 앵커만 `$` 로. 유출은 없고 끊기는 자리가 403(a-0) → 404(파일시스템)로 밀린다.
    'MZ1' => array(
        'windows' => false,
        'edits'   => array("'/^[A-Za-z0-9._-]+\\z/'" => "'/^[A-Za-z0-9._-]+\$/'"),
        'probes'  => array(
            array('/server/core%0a/bootstrap.php', 403, 404, ''),
        ),
    ),
    // L-1 — 세 정규식(a-0 · 페이지 · assets) 모두 `$` 로 = v1.8.1 상태. `/login%0a` 가 200 이 된다.
    'MZ3' => array(
        'windows' => false,
        'edits'   => array(
            "'/^[A-Za-z0-9._-]+\\z/'" => "'/^[A-Za-z0-9._-]+\$/'",
            "'#^/([a-z]+)(\\.html)?\\z#'" => "'#^/([a-z]+)(\\.html)?\$#'",
            "woff|woff2|map))\\z#'" => "woff|woff2|map))\$#'",
        ),
        'probes'  => array(
            array('/login%0a', 403, 200, ''),
            array('/assets/api.js%0a', 403, 200, ''),
        ),
    ),
    // L-3 M1 — 별칭 대소문자 재확인 줄 제거. Windows 는 `API.js` 로 `api.js` 를 연다.
    'M1' => array(
        'windows' => true,
        'edits'   => array('if (basename($realFile) !== basename($clean)) {' => 'if (false) {'),
        'probes'  => array(
            array('/assets/API.js', 404, 200, ''),
        ),
    ),
    // L-3 M3 — 별칭 페이지 허용 목록 제거. public/ 의 목록 밖 html 이 `/unlisted` 로 열린다.
    'M3' => array(
        'windows' => false,
        'edits'   => array(' && in_array($m[1], $pages, true)) {' => ') {'),
        'probes'  => array(
            array('/unlisted', 404, 200, 'UNLISTED_MARKER'),
        ),
    ),
    // L-3 M9 — assets 조각에 `/` 허용. 두 조각 경로가 별칭이 된다.
    'M9' => array(
        'windows' => false,
        'edits'   => array('[A-Za-z0-9_-][A-Za-z0-9._-]*\\.(css|js|' => '[A-Za-z0-9_-][A-Za-z0-9._/-]*\\.(css|js|'),
        'probes'  => array(
            array('/assets/sub/x.css', 404, 200, 'SUB_MARKER'),
        ),
    ),
);

foreach ($RA_MUTANTS as $mkey => $mu) {
    t('mutation ' . $mkey . ': the bypass table catches it (original blocks, mutant leaks)',
        function () use ($mkey, $mu) {
            if ($mu['windows'] && DIRECTORY_SEPARATOR !== '\\') {
                skip('mutation ' . $mkey . ' depends on Windows path parsing');
            }
            $router = ra_mutant_router($mkey, $mu['edits']);
            $mport = ra_server('mut_' . $mkey, false, $router);
            $oport = ra_main();
            foreach ($mu['probes'] as $p) {
                list($path, $wantOrig, $wantMut, $marker) = $p;
                $o = ra_get($oport, $path);
                assert_eq($wantOrig, $o['status'], 'original router on ' . $path);
                $m = ra_get($mport, $path);
                assert_eq($wantMut, $m['status'], 'mutant ' . $mkey . ' on ' . $path
                    . ' (if this equals the original, nothing in the suite guards that line)');
                if ($marker !== '') {
                    assert_contains($marker, $m['body'], 'mutant ' . $mkey . ' body of ' . $path);
                }
            }
        });
}

// =====================================================================
// E) L-2 — `php -S` 밖에서 실행되면 즉시 끝난다 (첫 줄 SAPI 가드)
// =====================================================================

t('L-2 the router exits silently outside cli-server (first-line SAPI guard)', function () {
    $out = h_run(h_q(h_php_bin()) . ' -n -d display_errors=1 -d error_reporting=-1 ' . h_q(ra_router_src()), $rc);
    assert_eq('', trim($out), 'router run under the CLI SAPI must print nothing');
    // 대조(변이): 가드 줄을 지운 사본은 CLI 에서 경고·출력을 낸다 — 즉 위 단언이 가드를 보고 있다
    $src = (string) file_get_contents(ra_router_src());
    $guard = "if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }";
    assert_eq(1, substr_count($src, $guard), 'guard line must appear exactly once');
    assert_eq(0, strpos($src, "<?php\n" . $guard), 'guard must be the first statement of the file');
    $dir = str_replace('\\', '/', sys_get_temp_dir()) . '/ulogin_ra_mut_L2_' . getmypid() . '_' . mt_rand(10000, 99999);
    @mkdir($dir, 0777, true);
    $GLOBALS['RA']['dirs'][] = $dir;
    file_put_contents($dir . '/router-ulogin.php', str_replace($guard, '', $src));
    $mout = h_run(h_q(h_php_bin()) . ' -n -d display_errors=1 -d error_reporting=-1 '
        . h_q($dir . '/router-ulogin.php'), $mrc);
    assert_ne('', trim($mout), 'mutant without the guard must produce output under the CLI SAPI');
});

// =====================================================================
// E) 허용 목록 (v1.8.9) — 운영 실측(v1.8.8)의 /README.md·/INSTALL.md 200 · /server/migrate.php 200 이 재발하지 않는가.
//    표: [요청 경로, 기대 상태, 본문에 있어야 할 것 또는 없어야 할 표지]. 변이: 허용 목록 두 줄을 끈 사본은 새야 한다.
// =====================================================================

t('E allow list: unused files 404, entry points still served', function () {
    $port = ra_main();
    $no = array('/README.md', '/LICENSE', '/docs/PORTFOLIO.md', '/server/migrate.php', '/server/migrate',
        '/server/other.php', '/server/other', '/public/notes.txt', '/public/data.json', '/examples/README.md',
        '/public/README.md', '/examples/');
    $bad = array();
    foreach ($no as $path) {
        $r = ra_get($port, $path);
        if ($r['status'] !== 404 || strpos($r['body'], 'AL_MARKER') !== false) {
            $bad[] = $path . ' -> ' . $r['status'];
        }
    }
    assert_eq(array(), $bad, 'every unused path must be 404 without its marker');
    $yes = array('/login' => 'html', '/public/login.html' => 'html', '/version.txt' => '', '/version.json' => '', '/public/assets/OFL-Pretendard.txt' => '',
        '/assets/style.css' => '', '/server/api/ok' => 'API_OK_RAN', '/server/api/ok.php' => 'API_OK_RAN',
        '/examples/ex.html' => 'EX_OK');
    $bad = array();
    foreach ($yes as $path => $want) {
        $r = ra_get($port, $path);
        if ($r['status'] !== 200 || ($want !== '' && $want !== 'html' && strpos($r['body'], $want) === false)) {
            $bad[] = $path . ' -> ' . $r['status'];
        }
    }
    assert_eq(array(), $bad, 'allowed paths must be 200');
});

t('E allow list mutation: with the two allow-list lines off, README.md and migrate.php leak', function () {
    $router = ra_mutant_router('E1', array());   // ra_mutant_router 가 허용 목록 두 줄을 끈다
    $port = ra_server('mut_E1', false, $router);
    $r = ra_get($port, '/README.md');
    assert_eq(200, $r['status'], 'mutant must leak README.md (else the allow list guards nothing)');
    assert_contains('AL_MARKER_README', $r['body'], 'mutant leak body');
    $r = ra_get($port, '/server/other');
    assert_eq(200, $r['status'], 'mutant must run server/other.php');
});

ra_teardown();
