<?php
declare(strict_types=1);

/**
 * 티어 2 — v1.10.1 순수 함수 (설계 130 §5 tier2)
 *   · 1-1 결정 순서(행 없음 × allow_register 행 0/1, 무효 값 → closed) · 1-2 9칸 표 · 옛 키 쓰기 규칙
 *   · points_json 검증(키 누락·음수·문자열·소수·여분 키·상한) · s2s 저장 키(서비스 2곳 같은 키 → 다른 저장 키)
 *   · settingsdef 17번째 = register_mode · 서버 PC 판별 3조건
 * DB 를 열지 않는다(함수가 받은 행 배열만 본다).
 */

require_once __DIR__ . '/../lib/loader.php';

load_core();
require_once h_root() . '/server/core/dbport.php';
require_once h_root() . '/server/core/dbdriver.php';
require_once h_root() . '/server/core/db.php';
require_once h_root() . '/server/core/auth.php';
require_once h_root() . '/server/core/points.php';
require_once h_root() . '/server/core/settingsdef.php';
require_once h_root() . '/server/core/localonly.php';
require_once h_root() . '/server/core/pwstrong.php';
require_once h_root() . '/server/core/regreq.php';

function t99_rows(?string $mode, ?string $allow): array
{
    $r = array();
    if ($mode !== null) {
        $r['register_mode'] = array('svalue' => $mode);
    }
    if ($allow !== null) {
        $r['allow_register'] = array('svalue' => $allow);
    }
    return $r;
}

t('1-1 decision order: register_mode row wins, invalid -> closed, no row follows allow_register', function () {
    foreach (array('closed', 'approval', 'open') as $m) {
        assert_eq($m, register_mode_global_from_rows(t99_rows($m, '1')), 'row ' . $m . ' with allow=1');
        assert_eq($m, register_mode_global_from_rows(t99_rows($m, '0')), 'row ' . $m . ' with allow=0');
    }
    foreach (array('', 'OPEN', 'Open ', 'yes', '1', 'approved') as $bad) {
        assert_eq('closed', register_mode_global_from_rows(t99_rows($bad, '1')), 'invalid row "' . $bad . '" -> closed');
    }
    assert_eq('closed', register_mode_global_from_rows(array('register_mode' => array('svalue' => null), 'allow_register' => array('svalue' => '1'))),
        'NULL row -> closed');
    assert_eq('open', register_mode_global_from_rows(t99_rows(null, '1')), 'no row + allow_register 1 -> open');
    assert_eq('closed', register_mode_global_from_rows(t99_rows(null, '0')), 'no row + allow_register 0 -> closed (operation D-315)');
    assert_eq(ALLOW_REGISTER ? 'open' : 'closed', register_mode_global_from_rows(array()), 'no rows -> ALLOW_REGISTER constant');
});

t('1-2 nine cells: effective = min(global, service cap)', function () {
    $svcOn  = array('service_id' => 'a', 'allow_register' => 1);
    $svcOff = array('service_id' => 'b', 'allow_register' => 0);
    $want = array(
        'closed'   => array('closed', 'closed', 'closed'),
        'approval' => array('approval', 'closed', 'approval'),
        'open'     => array('open', 'closed', 'open'),
    );
    foreach ($want as $g => $w) {
        assert_eq($w, array(
            register_mode_stricter($g, register_mode_service_cap($svcOn)),
            register_mode_stricter($g, register_mode_service_cap($svcOff)),
            register_mode_stricter($g, register_mode_service_cap(null)),
        ), 'global ' . $g);
    }
    assert_eq('closed', register_mode_stricter('open', 'bogus'), 'unknown value -> closed');
});

t('legacy allow_register writes never loosen approval; allow_register always equals (mode === open)', function () {
    $cases = array(
        array(null, false, 'open', 'closed'), array(null, false, 'approval', 'closed'),
        array(null, true, 'closed', 'open'), array(null, true, 'approval', 'approval'), array(null, true, 'open', 'open'),
        array('approval', true, 'closed', 'approval'), array('open', false, 'closed', 'open'), array(null, null, 'approval', 'approval'),
        array('bogus', null, 'open', 'closed'),
    );
    foreach ($cases as $c) {
        list($m, $a, $cur, $want) = $c;
        $r = register_mode_write_target($m, $a, $cur);
        assert_eq(array($want, $want === 'open'), $r, json_encode($c));
    }
});

t('points_json validation: exactly seven typed keys, otherwise disabled', function () {
    $ok = array('enabled' => true, 'earn' => true, 'spend' => false, 'per_tx_max' => 1000, 'daily_user_earn_max' => 5000,
        'daily_user_spend_max' => 10000, 'daily_service_earn_max' => 100000);
    assert_eq($ok, points_cfg_check($ok), 'valid');
    $shuffled = array_reverse($ok, true);
    assert_eq(array_keys($ok), array_keys((array) points_cfg_check($shuffled)), 'canonical key order');
    $bad = array(
        'missing key'      => array_diff_key($ok, array('spend' => 1)),
        'negative'         => array_merge($ok, array('per_tx_max' => -1)),
        'zero'             => array_merge($ok, array('daily_user_earn_max' => 0)),
        'string number'    => array_merge($ok, array('per_tx_max' => '1000')),
        'float'            => array_merge($ok, array('per_tx_max' => 10.5)),
        'bool as int'      => array_merge($ok, array('enabled' => 1)),
        'per_tx over 1e6'  => array_merge($ok, array('per_tx_max' => 1000001)),
        'daily over 1e8'   => array_merge($ok, array('daily_service_earn_max' => 100000001)),
        'extra key'        => array_merge($ok, array('note' => 'x')),
        'list'             => array_values($ok),
        'empty'            => array(),
        'null'             => null,
        'string'           => 'enabled',
    );
    foreach ($bad as $what => $v) {
        $reason = null;
        assert_eq(null, points_cfg_check($v, $reason), $what);
        assert_true(is_string($reason) && $reason !== '', $what . ' has a reason');
    }
    assert_eq(null, points_cfg_from_row(array('service_id' => 'x', 'points_json' => null)), 'NULL column -> disabled');
    assert_eq(null, points_cfg_from_row(array('service_id' => 'x', 'points_json' => '{broken')), 'broken JSON -> disabled');
    assert_eq($ok, points_cfg_from_row(array('service_id' => 'x', 'points_json' => json_encode($ok))), 'stored JSON round trip');
});

t('s2s store key: s2s: + sha256(sid \\n key), 68 chars, different services never collide', function () {
    $a = points_s2s_store_key('learning', 'quiz-2026-11-16-778');
    $b = points_s2s_store_key('office', 'quiz-2026-11-16-778');
    assert_eq('s2s:' . hash('sha256', "learning\nquiz-2026-11-16-778"), $a, 'formula');
    assert_eq(68, strlen($a), 'length fits idempotency_key VARCHAR(100)');
    assert_ne($a, $b, 'same key, two services');
    assert_eq(1, preg_match(POINTS_S2S_KEY_RE, 'quiz-2026-11-16-778'), 'key pattern accepts the design example');
    assert_eq(0, preg_match(POINTS_S2S_KEY_RE, 'short'), 'key pattern refuses < 8');
});

t('settingsdef: register_mode is the 17th key with the closed|approval|open enum', function () {
    $d = settings_defs();
    $keys = array_keys($d);
    assert_eq(17, count($keys), 'key count');
    assert_eq('register_mode', $keys[16], '17th key');
    assert_eq(array('closed', 'approval', 'open'), $d['register_mode'][5], 'enum');
    assert_eq('REGISTER_MODE', $d['register_mode'][2], 'fallback constant');
});

t('D-374 server-pc test: all three conditions or nothing', function () {
    $ok = array('REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8080');
    assert_true(ulogin_local_request_ok($ok), 'loopback + loopback host:port');
    assert_true(ulogin_local_request_ok(array('REMOTE_ADDR' => '::1', 'HTTP_HOST' => '[::1]:8080')), 'ipv6 loopback');
    assert_true(ulogin_local_request_ok(array('REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'LOCALHOST:80')), 'localhost any case');
    $no = array(
        'remote not loopback' => array('REMOTE_ADDR' => '192.168.0.5') + $ok,
        'remote mapped v4'    => array('REMOTE_ADDR' => '::ffff:127.0.0.1') + $ok,
        'public host'         => array('HTTP_HOST' => 'login.example.com') + $ok,
        'host without port'   => array('HTTP_HOST' => '127.0.0.1') + $ok,
        'host lookalike'      => array('HTTP_HOST' => '127.0.0.1.evil.test:80') + $ok,
        'host port 0'         => array('HTTP_HOST' => 'localhost:0') + $ok,
        'no host'             => array('REMOTE_ADDR' => '127.0.0.1'),
    );
    foreach (ulogin_local_proxy_keys() as $k) {
        $no[$k . ' present (empty)'] = $ok + array($k => '');
    }
    foreach ($no as $what => $srv) {
        assert_false(ulogin_local_request_ok($srv), $what);
    }
    assert_eq(9, count(ulogin_local_proxy_keys()), 'nine proxy headers (D-374 seven + v1.10.1 L-1 X-Forwarded-Proto, True-Client-Ip)');
});

t('v1.10.1 L-1: every Cf-* header and X-Forwarded-Proto / True-Client-Ip close the local page', function () {
    $ok = array('REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8080');
    foreach (array('HTTP_CF_IPCOUNTRY', 'HTTP_CF_VISITOR', 'HTTP_CF_WARP_TAG_ID', 'HTTP_CF_ACCESS_JWT_ASSERTION', 'http_cf_x',
                   'HTTP_X_FORWARDED_PROTO', 'HTTP_TRUE_CLIENT_IP') as $k) {
        assert_false(ulogin_local_request_ok($ok + array($k => '')), $k . ' present');
    }
    assert_true(ulogin_local_request_ok($ok + array('HTTP_CFX' => '1', 'HTTP_USER_AGENT' => 'cf')), 'non-Cf headers do not close it');
});

t('v1.10.1 L-2: installer and core share pw_strong_problem (10 chars, common refused)', function () {
    assert_eq(10, EM_STRONG_MIN, 'minimum 10');
    assert_true(pw_strong_problem('Zk4!mq7Tb', 'admin1') !== null, '9 chars refused');
    assert_eq(null, pw_strong_problem('Zk4!mq7Tbx', 'admin1'), '10 chars ok');
    foreach (array('Password1!', 'Admin12345!', 'qwerty!2345', 'abcabcabc1') as $p) {
        assert_true(pw_strong_problem($p, 'admin1') !== null, 'common refused: ' . $p);
    }
    assert_true(pw_strong_problem('xmyadmin99Q!', 'myadmin99') !== null, 'username inside refused');
});

t('v1.10.1 L-4: register net key = IPv4 /24, IPv6 /48, mapped v4 is v4', function () {
    assert_eq(regreq_net_key('203.0.113.5'), regreq_net_key('203.0.113.250'), 'same /24');
    assert_true(regreq_net_key('203.0.113.5') !== regreq_net_key('203.0.114.5'), 'other /24');
    assert_eq(regreq_net_key('2001:db8:1:ffff::1'), regreq_net_key('2001:db8:1::9'), 'same /48');
    assert_true(regreq_net_key('2001:db8:1::1') !== regreq_net_key('2001:db8:2::1'), 'other /48');
    assert_eq(regreq_net_key('203.0.113.5'), regreq_net_key('::ffff:203.0.113.9'), 'mapped v4 = v4');
    assert_eq(null, regreq_net_key('not-an-ip'), 'garbage -> null');
});
