<?php
declare(strict_types=1);

/**
 * 티어 1 — 공용 테마(research-ui) 배선 (v1.8.3 / D-327)
 *
 * 공용 파일 셋은 원본 그대로 복사하고 ulogin 보정은 별도 파일에 둔다. 그 배선이
 * 조용히 끊기는 경우를 잡는다. 실제 화면 점검(조합별 RUI.audit·대비)은 브라우저가 필요해
 * 여기 들어 있지 않다 — `node tests/ui/theme_matrix.mjs` 가 맡는다.
 */

require_once __DIR__ . '/../lib/checks.php';

$ROOT = h_root();
$PAGES = array('index', 'login', 'register', 'forgot', 'reset', 'verify', 'mypage', 'admin');

t('rui-layer.css imports rui.css with ?g= equal to RUI.GUIDELINE (immutable cache key)', function () use ($ROOT) {
    $layer = h_read($ROOT . '/public/assets/rui-layer.css');
    $theme = h_read($ROOT . '/public/assets/rui-theme.js');
    if (preg_match('~@import\s+url\("rui\.css\?g=([A-Za-z0-9]+)"\)\s+layer\(rui\)~', $layer, $m) !== 1) {
        fail('rui-layer.css has no `@import url("rui.css?g=…") layer(rui)`');
    }
    if (preg_match("~RUI\\.GUIDELINE\\s*=\\s*'([A-Za-z0-9]+)'~", $theme, $g) !== 1) {
        fail('rui-theme.js has no RUI.GUIDELINE');
    }
    assert_eq($g[1], $m[1], 'rui.css cache key (?g=) must follow the copied research-ui edition');
});

t('every page loads rui-ulogin-pre.js before rui-theme.js, and the layer + ulogin css around style.css', function () use ($ROOT, $PAGES) {
    $bad = array();
    foreach ($PAGES as $p) {
        $s = h_read($ROOT . '/public/' . $p . '.html');
        $pre = strpos($s, 'assets/rui-ulogin-pre.js?v=');
        $rui = strpos($s, 'assets/rui-theme.js?v=');
        $lay = strpos($s, 'assets/rui-layer.css?v=');
        $sty = strpos($s, 'assets/style.css?v=');
        $ult = strpos($s, 'assets/ulogin-theme.css?v=');
        if ($pre === false || $rui === false || $pre > $rui) { $bad[] = $p . ': pre.js missing or after rui-theme.js'; }
        if ($lay === false || $sty === false || $ult === false || !($lay < $sty && $sty < $ult)) { $bad[] = $p . ': css order must be rui-layer < style < ulogin-theme'; }
        if (strpos($s, 'data-rui-font') === false) { $bad[] = $p . ': <html data-rui-font> missing (RUI.font() would inject a CDN stylesheet)'; }
    }
    assert_clean($bad, 'theme wiring');
});

t('pages carry no inline style attribute and no <style> block (CSP style-src self)', function () use ($ROOT, $PAGES) {
    $bad = array();
    foreach ($PAGES as $p) {
        $s = preg_replace('~<!--.*?-->~s', '', h_read($ROOT . '/public/' . $p . '.html'));
        if (preg_match('~<[a-z][^>]*\sstyle\s*=~i', $s)) { $bad[] = $p . ': style= attribute'; }
        if (preg_match('~<style[\s>]~i', $s)) { $bad[] = $p . ': <style> block'; }
    }
    assert_clean($bad, 'inline styles');
});

t('admin.html shell keeps the ids its inline script binds to', function () use ($ROOT) {
    $s = h_read($ROOT . '/public/admin.html');
    $bad = array();
    foreach (array('who', 'btn-logout', 'tabbar', 'boot', 'app', 'me-sub', 'me-badges') as $id) {
        if (substr_count($s, 'id="' . $id . '"') !== 1) { $bad[] = '#' . $id; }
    }
    if (strpos($s, 'data-ul-shell') === false) { $bad[] = '[data-ul-shell]'; }
    if (strpos($s, 'assets/admin-shell.js?v=') === false) { $bad[] = 'admin-shell.js'; }
    assert_clean($bad, 'admin shell ids');
});

t('register gates stay fail-closed in markup (v1.8.2)', function () use ($ROOT) {
    assert_match('~id="link-register"[^>]*\shidden~', h_read($ROOT . '/public/login.html'), 'login.html #link-register must start hidden');
    assert_match('~id="register-card"[^>]*\shidden~', h_read($ROOT . '/public/register.html'), 'register.html #register-card must start hidden');
});

t('shared research-ui files stay byte-identical to the G31 original (sha256, D-342)', function () use ($ROOT) {
    $want = array(
        'rui-theme.js'    => '1766a6aa002eb96105c593b4a89e90c1d1973e2d77cac83c13335b0362d44b77',   // 공개본: 시스템 바로 가기 목록을 예시값으로 바꾼 사본의 해시
        'rui-tokens.css'  => '46c93bc7eb256366e9232b775d90180f5249657d688ea70789d9176f4e87bc90',
        'rui.css'         => '9163adae9b429c47066441302fc099bd008657c1cf44779434b15b7fac0a53cb',
    );
    $bad = array();
    foreach ($want as $f => $sha) {
        $got = hash_file('sha256', $ROOT . '/public/assets/' . $f);
        if ($got !== $sha) { $bad[] = $f . ' differs from G31 original (fix in rui-ulogin-pre.js / theme-ui.js, never in the shared file)'; }
    }
    assert_clean($bad, 'shared files');
});

t('rui-ulogin-pre.js keeps its core behaviour (replaceState wrap, rui key filter, currentScript guard)', function () use ($ROOT) {
    $s = h_read($ROOT . '/public/assets/rui-ulogin-pre.js');
    $s = preg_replace('~/\*.*?\*/~s', '', $s);
    $bad = array();
    foreach (array(
        'hist.replaceState = function' => 'replaceState wrapper',
        "k !== 'rui'"                  => 'rui key filter',
        'document.currentScript'       => 'currentScript guard (L-1)',
        'rui-theme\.js'               => 'rui-theme.js source check (L-1)',
        'DOMContentLoaded'             => 'safety-net restore',
    ) as $needle => $label) {
        if (strpos($s, $needle) === false) { $bad[] = $label; }
    }
    assert_clean($bad, 'pre.js core');
});

t('G16 common login frame: login.html calls RUI.login with the fixed ulogin options, the other screens mount #ul-pane (v1.8.6 / D-337)', function () use ($ROOT) {
    $bad = array();
    $login = preg_replace('~/\*.*?\*/~s', '', h_read($ROOT . '/public/login.html'));
    if (preg_match('~RUI\.login\(shellHost, \{(.*?)\n  \}\);~s', $login, $m) !== 1) {
        $bad[] = 'login.html: RUI.login(shellHost, {…}) call not found';
    } else {
        foreach (array("self: 'ulogin'", 'systems: true', "idLabel: '아이디 또는 이메일'", "idAuto: 'username'", 'onSubmit: submitLogin', 'tagline:', 'desc:', 'version:') as $need) {
            if (strpos($m[1], $need) === false) { $bad[] = 'login.html RUI.login option missing: ' . $need; }
        }
        if (preg_match('~\bsso\s*:~', $m[1])) { $bad[] = 'login.html RUI.login must not pass sso (ulogin is the SSO server itself)'; }
    }
    // SSO 복귀: authorize 본문이 쿼리의 복귀 파라미터를 그대로 싣는다(변이: redirect_uri 누락).
    // 무음 경로(authorize_session)에도 같은 줄이 있으므로 **authorize 본문 블록 안에서만** 찾는다.
    if (preg_match("~if \(ssoReady\) \{\s*var body = \{(.*?)\};(.*?)apiPost\('/api/sso/authorize', body\)(.*?)location\.replace\(data\.redirect_to\)~s", $login, $sb) !== 1) {
        $bad[] = 'login.html: SSO authorize block (body → apiPost → location.replace(data.redirect_to)) not found';
    } else {
        foreach (array('username: username', 'password: password', 'service_id: sso.service_id', 'redirect_uri: sso.redirect_uri') as $need) {
            if (strpos($sb[1], $need) === false) { $bad[] = 'login.html authorize body: ' . $need; }
        }
        foreach (array('body.state = sso.state', 'body.code_challenge = sso.code_challenge') as $need) {
            if (strpos($sb[2], $need) === false) { $bad[] = 'login.html authorize body: ' . $need; }
        }
    }
    $shell = h_read($ROOT . '/public/assets/ulogin-shell.js');
    if (strpos($shell, 'RUI.login(host, base())') === false) { $bad[] = 'ulogin-shell.js no longer builds the frame with RUI.login'; }
    // v1.8.11 — mypage 는 로그인 틀이 아니라 앱 틀이다(아래 「mypage 앱 틀」 시험).
    foreach (array('register', 'forgot', 'reset', 'verify') as $p) {
        $s = h_read($ROOT . '/public/' . $p . '.html');
        $sh = strpos($s, 'assets/ulogin-shell.js?v=');
        $api = strpos($s, 'assets/api.js?v=');
        if (strpos($s, 'id="ul-shell"') === false || strpos($s, 'id="ul-pane"') === false) { $bad[] = $p . ': #ul-shell / #ul-pane missing'; }
        if ($sh === false || $api === false || $sh > $api) { $bad[] = $p . ': ulogin-shell.js must load before api.js (mount before page scripts)'; }
        if (strpos($s, 'class="page ul-auth"') !== false || strpos($s, 'ul-auth-brand') !== false) { $bad[] = $p . ': old self-drawn split layout left behind'; }
    }
    assert_clean($bad, 'G16 login frame');
});

t('mypage app frame: side menu + main bar (not the RUI.login split), portal from RUI.SYSTEMS, home tab first (v1.8.11)', function () use ($ROOT) {
    $bad = array();
    $s = h_read($ROOT . '/public/mypage.html');
    foreach (array('data-ul-shell', 'class="side"', 'class="main-bar"', 'id="ul-page-title"', 'id="tabbar"', 'data-ul-portal', 'assets/mypage-shell.js?v=', 'assets/mypage.css?v=') as $need) {
        if (strpos($s, $need) === false) { $bad[] = 'mypage.html missing ' . $need; }
    }
    foreach (array('id="ul-shell"', 'id="ul-pane"', 'assets/ulogin-shell.js') as $gone) {
        if (strpos($s, $gone) !== false) { $bad[] = 'mypage.html still uses the login split frame: ' . $gone; }
    }
    if (preg_match("~var TABS = \[\s*\{ key: 'home'~", $s) !== 1) { $bad[] = 'mypage.html: first tab must be home (내 시스템)'; }
    if (strpos($s, "R.SYSTEMS.filter(function (s) { return s && s.sso && s.url && s.id !== 'ulogin'; })") === false) { $bad[] = 'mypage.html: portal must list RUI.SYSTEMS with sso, minus ulogin'; }
    $sh = h_read($ROOT . '/public/assets/mypage-shell.js');
    foreach (array('home', 'info', 'security', 'points', 'services', 'sessions', 'loginlogs', 'withdraw') as $k) {
        if (preg_match('~\b' . $k . ': \{ icon: \'~', $sh) !== 1) { $bad[] = 'mypage-shell.js META lacks ' . $k; }
    }
    assert_clean($bad, 'mypage app frame');
});

t('G18 version.json is generated from version.txt and agrees with every ?v= (v1.8.7)', function () use ($ROOT, $PAGES) {
    $txt = trim(h_read($ROOT . '/public/version.txt'));
    $want = strpos($txt, '_') === false ? $txt : substr($txt, 0, strpos($txt, '_'));
    $j = json_decode(h_read($ROOT . '/public/version.json'), true);
    assert_true(is_array($j) && array_keys($j) === array('version'), 'version.json must hold only {"version":...}');
    assert_eq($want, $j['version'], 'version.json must equal version.txt before "_"');
    $bad = array();
    foreach ($PAGES as $p) {
        $h = h_read($ROOT . '/public/' . $p . '.html');
        if (preg_match_all('~assets/[A-Za-z0-9._-]+\?v=([0-9A-Za-z.-]+)~', $h, $m)) {
            foreach (array_unique($m[1]) as $v) { if ($v !== $want) { $bad[] = $p . '.html ?v=' . $v; } }
        }
    }
    assert_clean($bad, 'asset ?v= vs version.json');
});

t('G18 every page loads theme-ui.js (which calls RUI.updates with the ?v= version); no other screen code starts its own notice', function () use ($ROOT, $PAGES) {
    $bad = array();
    foreach ($PAGES as $p) {
        if (strpos(h_read($ROOT . '/public/' . $p . '.html'), 'assets/theme-ui.js?v=') === false) { $bad[] = $p . '.html lacks theme-ui.js'; }
    }
    $ui = h_read($ROOT . '/public/assets/theme-ui.js');
    if (strpos($ui, 'RUI.updates({ version: v })') === false) { $bad[] = 'theme-ui.js does not call RUI.updates({ version })'; }
    foreach (array('admin-shell.js', 'ulogin-shell.js', 'ui.js', 'api.js') as $f) {
        if (preg_match('~RUI\.updates\s*\(~', preg_replace('~/\*.*?\*/~s', '', h_read($ROOT . '/public/assets/' . $f))) === 1) { $bad[] = $f . ' calls RUI.updates itself'; }
    }
    assert_clean($bad, 'G18 wiring');
});
