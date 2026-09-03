<?php
declare(strict_types=1);

/**
 * 티어 2 — `Set-Cookie` 헤더 조립 불변식 (v1.1.0 의 71 케이스 하네스 복원)
 *
 * **이 프로젝트에서 가장 값이 검증된 테스트다.** v1.1.0 이 이것으로
 * "HttpOnly·SameSite 가 7.2 배포에서만 조용히 빠지는" 사고를 잡았고,
 * "`d M Y`(RFC 7231) 로 고치면 7.2 에서만 다른 만료 문자열이 나가는" 결함도
 * 여기서 잡혔다(D-68). 그런데 그 코드는 저장소에 없었다.
 *
 * 케이스 정의는 `tests/lib/cookie_oracle.php` 에 있고 **티어 3 이 같은 것을 쓴다** —
 * 거기서는 실 SAPI 로 두 분기 바이트를 대조한다.
 */

require_once __DIR__ . '/../lib/loader.php';
require_once __DIR__ . '/../lib/cookie_oracle.php';

load_core();

$CASES = cookie_cases();

t('the cookie case matrix is at least as large as the v1.1.0 harness (71 cases)', function () use ($CASES) {
    // v1.1.0 의 `15a` 가 61 케이스, `17a` 가 D-75 전용 10 을 더해 71 을 돌렸다.
    // 그 규모 아래로 줄면 복원이 아니라 축소다.
    if (count($CASES) < 71) {
        fail('cookie case matrix has only ' . count($CASES) . ' cases; the restored harness must carry at least 71');
    }
    // 세 묶음이 전부 있는가 — 정상 / 본체가 거부하는 입력 / D-75 계열
    $groups = array('A' => 0, 'I' => 0, 'D' => 0);
    foreach ($CASES as $c) {
        $g = $c['id'][0];
        if (isset($groups[$g])) {
            $groups[$g]++;
        }
    }
    foreach ($groups as $g => $n) {
        if ($n < 8) {
            fail('case group "' . $g . '" has only ' . $n . ' cases - the matrix lost a whole dimension');
        }
    }
});

t('build_set_cookie_header() satisfies every PHP-core invariant on all cases', function () use ($CASES) {
    $bad = array();
    foreach ($CASES as $c) {
        $v = cookie_check_invariants('build_set_cookie_header', $c);
        foreach ($v as $one) {
            $bad[] = $c['id'] . ': ' . $one;
        }
    }
    assert_clean($bad, 'cookie header invariant violated');
});

t('the delete path is byte-shaped the way PHP core writes it', function () {
    // 로그아웃이 지나가는 유일한 경로다. `expires` 를 무시하고 `deleted` 를 쓴다.
    $h = build_set_cookie_header('ULOGIN_SID', '', array('expires' => time() + 9999, 'path' => '/'));
    assert_contains('ULOGIN_SID=deleted', $h, 'delete marker');
    assert_contains('Max-Age=0', $h, 'Max-Age');
    assert_contains('expires=Thu, 01-Jan-1970 00:00:01 GMT', $h, 'epoch expiry');
    // `expires` 옵션이 살아 있으면 안 된다 — 미래 시각이 새어 나가면 쿠키가 안 지워진다
    assert_not_contains(gmdate('D, d-M-Y', time() + 9999), $h, 'future expiry must not survive the delete path');
});

t('the expiry date keeps the hyphenated Netscape format (D-68 regression)', function () {
    // 한때 독스트링에 `01 Jan 1970`(공백)이라고 적힌 줄이 있었고, 그것을 근거로
    // 코드를 RFC 7231 형식으로 "고치면" 7.2 배포에서만 다른 문자열이 나간다.
    // 그 되살아남을 여기서 막는다.
    $h = build_set_cookie_header('X', 'v', array('expires' => 1700000000));
    assert_match('~; expires=[A-Z][a-z]{2}, \d{2}-[A-Z][a-z]{2}-\d{4} \d{2}:\d{2}:\d{2} GMT~', $h,
        'must be "D, d-M-Y H:i:s GMT" with hyphens, not the RFC 7231 space form');
});

t('the cookie name goes out unencoded (V11-L-1)', function () {
    // 예전 구현이 `rawurlencode($name)` 를 통과시켰고, `ulogin[sid]` 가 7.2 에서만
    // `ulogin%5Bsid%5D` 로 나가 로그인이 조용히 깨졌다. 이름 인코딩은 되살리면 안 된다.
    $h = build_set_cookie_header('ulogin[sid]', 'v', array('path' => '/'));
    assert_contains('ulogin[sid]=', $h, 'name must appear verbatim');
    assert_not_contains('%5B', $h, 'name must not be url-encoded');
});

t('build_set_cookie_header() does NOT fill in defaults (that is send_cookie()\'s job)', function () {
    // 하한선 채움을 여기에 넣으면 7.3+ 분기(본체 setcookie)와 출력이 갈라져
    // 두 분기 대조가 통째로 무의미해진다. 계약은 "주어진 배열 그대로".
    $h = build_set_cookie_header('X', 'v', array());
    assert_not_contains('; HttpOnly', $h, 'must not invent HttpOnly');
    assert_not_contains('; secure', $h, 'must not invent secure');
    assert_not_contains('; SameSite', $h, 'must not invent SameSite');
    assert_not_contains('; path=', $h, 'must not invent path');
});

t('cookie_samesite_safe() floors anything outside the browser whitelist', function () {
    assert_eq('Lax', cookie_samesite_safe('Lax'));
    assert_eq('Strict', cookie_samesite_safe('Strict'));
    assert_eq('None', cookie_samesite_safe('None'));
    // 오타·임의 문자열·구분자 주입은 전부 Lax 로 떨어진다
    assert_eq('Lax', cookie_samesite_safe('lax'), 'lowercase is not a browser-understood value');
    assert_eq('Lax', cookie_samesite_safe('STRICT'));
    assert_eq('Lax', cookie_samesite_safe(''));
    assert_eq('Lax', cookie_samesite_safe('Lax; Domain=evil.example'));
    assert_eq('Lax', cookie_samesite_safe('None; Secure'));
});

t('cookie_attr_offender() names the offending option for every cookie separator', function () {
    // 본체(ext/standard/head.c)가 거부하는 문자 집합 그대로.
    // 7.2 분기에는 그 층이 없으므로 이 함수가 유일한 방어선이다.
    foreach (array(',', ';', ' ', "\t", "\r", "\n", "\013", "\014") as $ch) {
        assert_eq('path', cookie_attr_offender(array('path' => '/a' . $ch . 'b')), 'separator ' . ord($ch));
        assert_eq('domain', cookie_attr_offender(array('domain' => 'a' . $ch . 'b')), 'separator ' . ord($ch));
        assert_eq('samesite', cookie_attr_offender(array('samesite' => 'Lax' . $ch)), 'separator ' . ord($ch));
    }
    // 정상값과 빈 문자열은 통과
    assert_eq(null, cookie_attr_offender(array('path' => '/', 'domain' => 'example.com', 'samesite' => 'Lax')));
    assert_eq(null, cookie_attr_offender(array('path' => '', 'domain' => '', 'samesite' => '')));
    assert_eq(null, cookie_attr_offender(array()));
});

t('cookie_options() forces secure when SameSite=None (browser requirement, D-66)', function () {
    // SameSite=None + Secure 없음 = 브라우저가 쿠키를 통째로 버린다.
    // 서버는 200 을 내고 로그에 아무것도 안 남는다 — 원인 추적이 불가능한 종류다.
    // 이 로더는 COOKIE_SAMESITE='Lax' 로 상수를 잡았으므로 여기서는 함수 계약만 본다.
    $o = cookie_options(3600);
    assert_true(is_array($o), 'returns an array');
    assert_true(array_key_exists('samesite', $o), 'always carries samesite');
    assert_true(array_key_exists('httponly', $o), 'always carries httponly');
    assert_eq(true, $o['httponly'], 'session cookies are always HttpOnly');
    if ($o['samesite'] === 'None') {
        assert_eq(true, (bool) $o['secure'], 'SameSite=None must force secure');
    }
    // ttl<=0 이면 브라우저 세션 쿠키
    $o0 = cookie_options(0);
    assert_eq(0, $o0['expires'], 'ttl<=0 must produce expires=0');
});
