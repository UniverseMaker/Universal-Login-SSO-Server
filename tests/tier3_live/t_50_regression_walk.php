<?php
declare(strict_types=1);

/**
 * 티어 3 — **회귀 종주** (v1.1.0 `17a` 의 종주 시나리오 복원)
 *
 *   register 201 → login 200 → /api/me 200 → 비밀번호 변경 200 (현재 세션 생존)
 *   → logout (`Set-Cookie` 2줄) → /api/me **401**
 *
 * 각 화살표가 하나의 계약이다. 중간 한 곳이 끊기면 나머지가 전부 통과해도
 * 사용자는 로그인할 수 없다 — 이 프로젝트에서 가장 비싼 실패 방식이다.
 *
 * ─── 이 파일이 함께 확인하는 배선 (D-172) ────────────────────────────
 * 응답 필드를 **이름으로** 꺼내 쓴다. 서버가 필드명을 바꾸면 여기서 깨진다 —
 * 화면이 조용히 `undefined` 를 그리기 전에.
 */

require_once __DIR__ . '/../lib/live.php';

/** 이 파일 안에서 공유하는 상태. 케이스 순서가 곧 시나리오다. */
$GLOBALS['WALK'] = array(
    'user' => 'walk_' . substr(bin2hex(random_bytes(4)), 0, 8),
    'pw'   => 'Passw0rd!walk',
    'pw2'  => 'Passw0rd!walk2',
    'cookies' => array(),
    'bearer'  => null,
);

t('register creates the account and returns the serialized user', function () {
    live_ready_install();
    live_server();
    $W = &$GLOBALS['WALK'];
    $r = live_req('POST', '/server/api/auth/register.php', array('json' => array(
        'username'         => $W['user'],
        'email'            => $W['user'] . '@test.invalid',
        'password'         => $W['pw'],
        'password_confirm' => $W['pw'],
        'agree_tos'        => true,
        'agree_privacy'    => true,
    )));
    if ($r['status'] !== 201 && $r['status'] !== 200) {
        fail('register returned HTTP ' . $r['status'] . ' - ' . substr($r['body'], 0, 300));
    }
    assert_true($r['json']['ok'], 'ok flag');
    $d = $r['json']['data'];
    // 배선: 화면이 읽는 필드가 실제로 오는가
    assert_true(isset($d['user']), 'response must carry a "user" object');
    assert_eq($W['user'], $d['user']['username'], 'username round-trips');
    assert_true(isset($d['user']['uuid']), 'user must carry a uuid');
    assert_true(isset($d['user']['account_level']), 'user must carry account_level');
    assert_true(isset($d['user']['account_level_rank']), 'user must carry the rank, so the UI never compares strings');
    // 비밀번호 계열이 응답에 새면 안 된다
    $flat = json_encode($d);
    assert_not_contains($W['pw'], $flat, 'the password must never appear in a response');
    assert_not_contains('password_hash', $flat, 'the hash column must never be serialized');
});

t('login returns a session cookie, a bearer token and the same user', function () {
    live_ready_install();
    live_server();
    $W = &$GLOBALS['WALK'];
    $r = live_req('POST', '/server/api/auth/login.php', array('json' => array(
        'username' => $W['user'],
        'password' => $W['pw'],
    )));
    assert_eq(200, $r['status'], 'login: ' . substr($r['body'], 0, 300));
    $d = $r['json']['data'];
    assert_true(isset($d['access_token']), 'login must return access_token');
    assert_true(isset($d['expires_in']), 'login must return expires_in');
    assert_eq($W['user'], $d['user']['username']);

    // 세션 쿠키 속성 — INSTALL.md 가 배포 직후 개발자도구로 확인하라고 적은 항목
    $lines = live_set_cookie_lines($r);
    if ($lines === array()) {
        fail('login sent no Set-Cookie header at all');
    }
    $sid = null;
    foreach ($lines as $l) {
        if (strncmp($l, 'ULOGIN_SID=', 11) === 0) {
            $sid = $l;
        }
    }
    if ($sid === null) {
        fail('login sent Set-Cookie but not for ULOGIN_SID: ' . implode(' | ', $lines));
    }
    assert_contains('HttpOnly', $sid, 'the session cookie must be HttpOnly');
    assert_contains('SameSite=', $sid, 'the session cookie must declare SameSite');
    // 샌드박스는 평문 HTTP + COOKIE_SECURE=false 다. HTTPS 배포에서는 secure 가 필수인데
    // 그것은 여기서 검증할 수 없다 — 배포 후 확인 항목으로 남는다.

    $W['cookies'] = $r['cookies'];
    $W['bearer'] = $d['access_token'];
});

t('GET /api/me works with the cookie session and with the bearer token, and agrees', function () {
    live_ready_install();
    live_server();
    $W = $GLOBALS['WALK'];
    if ($W['bearer'] === null) {
        skip('login did not run');
    }
    $byCookie = live_req('GET', '/server/api/me/index.php', array('cookies' => $W['cookies']));
    assert_eq(200, $byCookie['status'], 'cookie session: ' . substr($byCookie['body'], 0, 200));

    $byBearer = live_req('GET', '/server/api/me/index.php', array(
        'headers' => array('Authorization' => 'Bearer ' . $W['bearer']),
    ));
    assert_eq(200, $byBearer['status'], 'bearer: ' . substr($byBearer['body'], 0, 200));

    // 두 인증 경로가 **같은 사용자**를 봐야 한다. 갈라지면 세션과 토큰이 다른
    // 계정을 가리킬 수 있다는 뜻이고, 그것은 조용한 권한 사고다.
    assert_eq(
        $byCookie['json']['data']['user']['uuid'],
        $byBearer['json']['data']['user']['uuid'],
        'the cookie session and the bearer token must resolve to the same account'
    );
    assert_eq($W['user'], $byCookie['json']['data']['user']['username']);
});

t('changing the password keeps the current session alive (and the old password stops working)', function () {
    live_ready_install();
    live_server();
    $W = &$GLOBALS['WALK'];
    if ($W['bearer'] === null) {
        skip('login did not run');
    }
    // Bearer 는 CSRF 면제다 (브라우저가 자동으로 실어 보내지 않으므로 전제가 없다).
    $r = live_req('PUT', '/server/api/me/password.php', array(
        'headers' => array('Authorization' => 'Bearer ' . $W['bearer']),
        'json'    => array(
            'current_password' => $W['pw'],
            'password'         => $W['pw2'],
            'password_confirm' => $W['pw2'],
        ),
    ));
    assert_eq(200, $r['status'], 'password change: ' . substr($r['body'], 0, 300));

    // **현재 세션은 살아 있어야 한다.** 비밀번호를 바꾼 사람을 로그아웃시키면
    // 사용자는 무슨 일이 났는지 모른 채 로그인 화면으로 떨어진다.
    $me = live_req('GET', '/server/api/me/index.php', array(
        'headers' => array('Authorization' => 'Bearer ' . $W['bearer']),
    ));
    assert_eq(200, $me['status'], 'the session that changed the password must survive');

    // 옛 비밀번호로는 더 이상 로그인되지 않는다
    $old = live_req('POST', '/server/api/auth/login.php', array('json' => array(
        'username' => $W['user'], 'password' => $W['pw'],
    )));
    assert_eq(401, $old['status'], 'the old password must stop working');
    assert_eq('AUTH_INVALID_CREDENTIALS', $old['json']['error']['code']);

    // 새 비밀번호로는 된다
    $new = live_req('POST', '/server/api/auth/login.php', array('json' => array(
        'username' => $W['user'], 'password' => $W['pw2'],
    )));
    assert_eq(200, $new['status'], 'the new password must work');
    $W['pw'] = $W['pw2'];
});

t('logout clears both cookies and the session stops working', function () {
    live_ready_install();
    live_server();
    $W = &$GLOBALS['WALK'];
    if ($W['bearer'] === null) {
        skip('login did not run');
    }
    $r = live_req('POST', '/server/api/auth/logout.php', array(
        'headers' => array('Authorization' => 'Bearer ' . $W['bearer']),
        'json'    => new stdClass(),
    ));
    assert_eq(200, $r['status'], 'logout: ' . substr($r['body'], 0, 300));

    // **Set-Cookie 2줄** — 세션 쿠키와 CSRF 쿠키를 함께 지운다.
    // `header()` 의 2번째 인자가 true 로 바뀌면 앞줄이 지워져 1줄이 된다.
    // 그 회귀는 로그아웃 후에도 CSRF 쿠키가 남는 형태로 조용히 나타난다.
    $lines = live_set_cookie_lines($r);
    if (count($lines) < 2) {
        fail('logout sent ' . count($lines) . ' Set-Cookie line(s), expected 2 (session + csrf): '
            . implode(' | ', $lines));
    }
    $deleted = 0;
    foreach ($lines as $l) {
        if (strpos($l, '=deleted') !== false || strpos($l, 'Max-Age=0') !== false) {
            $deleted++;
        }
    }
    assert_true($deleted >= 2, 'both cookies must be expired, not just re-set');

    // 그리고 토큰은 죽어야 한다
    $me = live_req('GET', '/server/api/me/index.php', array(
        'headers' => array('Authorization' => 'Bearer ' . $W['bearer']),
    ));
    assert_eq(401, $me['status'], 'the revoked token must be refused');
    $code = isset($me['json']['error']['code']) ? $me['json']['error']['code'] : null;
    if (!in_array($code, array('AUTH_REQUIRED', 'AUTH_SESSION_EXPIRED'), true)) {
        fail('after logout /api/me answered ' . ut_show($code) . ', expected AUTH_REQUIRED or AUTH_SESSION_EXPIRED');
    }
    $W['bearer'] = null;
});

t('the session_id is not accepted as a cookie value (D-29)', function () {
    // 쿠키에는 access_token **원문**이 들어간다. 세션 행의 id 나 다른 컬럼을
    // 쿠키로 보내면 통과해서는 안 된다.
    live_ready_install();
    live_server();
    $pdo = live_ready_install();
    $row = $pdo->query('SELECT id, access_token_hash FROM app_ulogin_sessions ORDER BY id DESC LIMIT 1')->fetch();
    if ($row === false) {
        skip('no session row to probe (the walk above did not run)');
    }
    foreach (array((string) $row['id'], (string) $row['access_token_hash']) as $wrong) {
        $r = live_req('GET', '/server/api/me/index.php', array('cookies' => array('ULOGIN_SID' => $wrong)));
        assert_eq(401, $r['status'], 'a cookie carrying ' . substr($wrong, 0, 12) . '... must not authenticate');
    }
});
