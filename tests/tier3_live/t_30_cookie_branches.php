<?php
declare(strict_types=1);

/**
 * 티어 3 — **두 쿠키 분기의 바이트 동등성** (v1.1.0 의 "71 케이스 SAME / DIFF 0" 복원)
 *
 * `send_cookie()` 는 PHP 버전으로 갈린다:
 *   real : `PHP_VERSION_ID >= 70300` → `setcookie($name, $value, $options)` (본체)
 *   leg  : 그 미만 → `build_set_cookie_header()` 로 조립해 `header()` 로 직접 전송
 *
 * 로컬에 7.2 인터프리터가 없으므로 15a·17a 와 같은 방법을 쓴다 —
 * **버전 조건을 뒤집은 사본**을 샌드박스에 만들고 그쪽으로 요청을 보낸다.
 * 두 응답의 `Set-Cookie` 바이트가 같아야 한다.
 *
 * ─── **왜 `send_cookie()` 를 지나야 하는가** ──────────────────────────
 * `setcookie()` 와 `build_set_cookie_header()` 를 **직접** 비교하면 안 된다.
 * 실측해 보면 PHP 본체는 `samesite` 의 구분자를 검증하지 않는다 — `Lax; Domain=x`
 * 를 그대로 헤더에 싣는다(CR/LF 만 SAPI 가 막는다). 이 저장소의 조립기는 그것을
 * 거부하므로, 직접 비교하면 **의도된 하드닝이 DIFF 로 보고된다.**
 * 정상 경로에서는 `send_cookie()` 의 하한선이 두 분기 앞에서 `samesite` 를
 * 화이트리스트로 떨어뜨리므로 그 입력이 분기에 도달하지 않는다. 동등성은
 * **분기의 동등성**이지 두 조립 함수의 동등성이 아니다 (D-65 가 검증을 분기
 * **앞**에 둔 이유가 정확히 이것이다).
 *
 * ─── 못 잡는 것 ───────────────────────────────────────────────────────
 *  · **실 PHP 7.2 인터프리터.** 여기서 도는 것은 8.x 이고 7.2 분기는 강제 실행이다.
 *    인터프리터 자체의 차이는 `tools/php72_scan.php` 정적 검사로만 덮인다
 *  · 브라우저가 그 헤더를 어떻게 저장하는가. 배포 후 개발자도구의 몫이다
 *  · `Max-Age` 1초 스큐. 두 분기를 별도 요청으로 보내므로 초 경계를 넘으면
 *    1 만큼 다르다. **±1 을 허용하고, 그 사실을 NOTE 로 남긴다** — 조용히
 *    무시하면 진짜 1초 차이(계산식 오류)도 같이 묻힌다
 */

require_once __DIR__ . '/../lib/live.php';
require_once __DIR__ . '/../lib/cookie_oracle.php';

/**
 * 샌드박스에 프로브 두 벌을 심는다. **저장소 트리가 아니라 임시 복사본이다.**
 * 샌드박스는 실행이 끝나면 통째로 지워진다.
 */
function cookie_probe_install(): void
{
    $box = live_sandbox();
    if (is_file($box . '/server/_probe_real.php')) {
        return;
    }
    // 7.2 분기를 강제하는 request.php 사본. 조건 하나만 뒤집는다.
    $src = h_read($box . '/server/core/request.php');
    $needle = 'if (PHP_VERSION_ID >= 70300) {';
    if (strpos($src, $needle) === false) {
        throw new RuntimeException('the version branch in send_cookie() no longer looks like "'
            . $needle . '" - this probe forces the legacy path by flipping exactly that line, '
            . 'so it must be updated together with the code');
    }
    $legacy = str_replace($needle, 'if (false) {   /* probe: legacy branch forced */', $src);
    file_put_contents($box . '/server/core/_request_forced_legacy.php', $legacy);

    file_put_contents($box . '/server/_probe_real.php', cookie_probe_source('core/request.php'));
    file_put_contents($box . '/server/_probe_leg.php', cookie_probe_source('core/_request_forced_legacy.php'));
}

function cookie_probe_source(string $requestFile): string
{
    $tpl = <<<'PROBE'
<?php
/**
 * tests/tier3_live/t_30_cookie_branches.php 가 **샌드박스에만** 심는 프로브.
 * 저장소에는 없다.
 *
 * 요청 본문의 케이스로 send_cookie() 를 한 번 부르고, SAPI 가 실제로 내보낸
 * Set-Cookie 헤더 바이트를 그대로 돌려준다.
 */
define('ULOGIN_BOOTSTRAPPED', 1);
require __DIR__ . '/config.php';
require __DIR__ . '/core/response.php';
// D-75 판정을 통과시켜 **조립만** 비교한다. D-75 자체는 티어 2 가 본다.
function request_is_https() { return true; }
require __DIR__ . '/REQUEST_FILE';

$in = json_decode(file_get_contents('php://input'), true);
$res = array('ret' => null, 'err' => null, 'cookie' => null, 'now' => time());
header_remove('Set-Cookie');
try {
    $res['ret'] = send_cookie((string) $in['name'], (string) $in['value'], is_array($in['opt']) ? $in['opt'] : array());
} catch (Throwable $e) {
    $res['err'] = get_class($e) . ':' . $e->getMessage();
}
$lines = array();
foreach (headers_list() as $h) {
    if (stripos($h, 'Set-Cookie:') === 0) {
        $lines[] = trim(substr($h, 11));
    }
}
$res['cookie'] = $lines === array() ? null : end($lines);
header_remove('Set-Cookie');
header('Content-Type: application/json');
echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
PROBE;
    return str_replace('REQUEST_FILE', $requestFile, $tpl);
}

/** `Max-Age` 를 자리표시자로 바꾼다. 두 요청 사이의 1초 스큐를 흡수한다. */
function cookie_norm_maxage(?string $h, ?int &$maxAge = null): ?string
{
    $maxAge = null;
    if ($h === null) {
        return null;
    }
    return preg_replace_callback('~Max-Age=(\d+)~', function ($m) use (&$maxAge) {
        $maxAge = (int) $m[1];
        return 'Max-Age=<n>';
    }, $h);
}

/** `expires=` 날짜도 같은 이유로 자리표시자로. 값은 따로 꺼내 초 단위로 비교한다. */
function cookie_norm_expires(?string $h, ?string &$exp = null): ?string
{
    $exp = null;
    if ($h === null) {
        return null;
    }
    return preg_replace_callback('~expires=([^;]+)~', function ($m) use (&$exp) {
        $exp = trim($m[1]);
        return 'expires=<d>';
    }, $h);
}

t('the two send_cookie() branches emit byte-identical headers on every case (SAME / DIFF 0)', function () {
    live_or_skip();
    live_server();
    cookie_probe_install();

    $cases = cookie_cases();
    if (count($cases) < 71) {
        fail('the case matrix shrank to ' . count($cases) . ' - this is meant to restore the 71-case harness');
    }

    $same = 0;
    $bothRefuse = 0;
    $skewOnly = 0;
    $diff = array();

    foreach ($cases as $c) {
        if ($GLOBALS['UT']['verbose']) {
            // 71×2 요청은 몇 초 걸린다. 진행이 안 보이면 "멈췄다" 로 읽힌다.
            fwrite(STDERR, '      probe ' . $c['id'] . "\n");
        }
        $payload = array('json' => array('name' => $c['name'], 'value' => $c['value'], 'opt' => $c['opt']));
        $rr = live_req('POST', '/server/_probe_real.php', $payload);
        $rl = live_req('POST', '/server/_probe_leg.php', $payload);

        if ($rr['status'] !== 200 || $rr['json'] === null || $rl['status'] !== 200 || $rl['json'] === null) {
            $diff[] = $c['id'] . ': probe unreachable (real HTTP ' . $rr['status'] . ', leg HTTP ' . $rl['status']
                . ') real-body=' . substr(str_replace("\n", ' ', $rr['body']), 0, 80)
                . ' leg-body=' . substr(str_replace("\n", ' ', $rl['body']), 0, 80);
            continue;
        }
        $a = $rr['json'];
        $b = $rl['json'];

        // 1) 예외 동등성 — "잘못된 입력에서 같은 실패" 가 D-65 의 목표다
        if ($a['err'] !== null || $b['err'] !== null) {
            if ($a['err'] === $b['err']) {
                $bothRefuse++;
            } else {
                $diff[] = $c['id'] . ': the branches fail differently (real=' . ut_show($a['err'])
                    . ' leg=' . ut_show($b['err']) . ')';
            }
            continue;
        }
        // 2) 반환값 동등성
        if ($a['ret'] !== $b['ret']) {
            $diff[] = $c['id'] . ': return value differs (real=' . ut_show($a['ret']) . ' leg=' . ut_show($b['ret']) . ')';
            continue;
        }
        // 3) 헤더 유무
        if (($a['cookie'] === null) !== ($b['cookie'] === null)) {
            $diff[] = $c['id'] . ': one branch emitted a Set-Cookie and the other did not (real='
                . ut_show($a['cookie']) . ' leg=' . ut_show($b['cookie']) . ')';
            continue;
        }
        if ($a['cookie'] === null) {
            $bothRefuse++;
            continue;
        }
        // 4) 바이트 대조 — 시간 의존 부분만 자리표시자로 바꾼 뒤
        $ma = null;
        $mb = null;
        $ea = null;
        $eb = null;
        $na = cookie_norm_expires(cookie_norm_maxage($a['cookie'], $ma), $ea);
        $nb = cookie_norm_expires(cookie_norm_maxage($b['cookie'], $mb), $eb);
        if ($na !== $nb) {
            $diff[] = $c['id'] . ":\n       real=" . $a['cookie'] . "\n       leg =" . $b['cookie'];
            continue;
        }
        // 시간 부분은 ±1초까지 허용한다. 두 분기를 별도 요청으로 보내므로 초 경계를
        // 넘으면 1 만큼 어긋난다 — 17a 도 같은 스큐를 관측했다.
        // **2초 이상 벌어지면 계산식이 다른 것이고, 그것은 DIFF 다.**
        if ($ma !== null && $mb !== null && abs($ma - $mb) > 1) {
            $diff[] = $c['id'] . ': Max-Age differs by ' . abs($ma - $mb) . 's (real=' . $ma . ' leg=' . $mb
                . ') - more than the 1s request skew, so the formula differs';
            continue;
        }
        if ($ea !== null && $eb !== null && $ea !== $eb) {
            $ta = strtotime(str_replace('-', ' ', $ea));
            $tb = strtotime(str_replace('-', ' ', $eb));
            if ($ta === false || $tb === false || abs($ta - $tb) > 1) {
                $diff[] = $c['id'] . ': expires differs (real=' . $ea . ' leg=' . $eb . ')';
                continue;
            }
            $skewOnly++;
        }
        $same++;
    }

    // "DIFF 0" 이 "케이스 0" 일 수 있다. 그 가능성을 여기서 닫는다.
    $comparable = $same + $bothRefuse;
    if ($comparable < 71) {
        fail('only ' . $comparable . ' cases produced a comparable result (' . $same . ' same, '
            . $bothRefuse . ' both-refused) - a "DIFF 0" from this few cases is not evidence');
    }
    if ($bothRefuse < 8) {
        fail('only ' . $bothRefuse . ' cases were refused by both branches - the "same failure on bad input"'
            . ' half of D-65 is not actually being exercised');
    }
    assert_clean($diff, 'the forced-7.2 branch and the PHP core branch disagree');
    note('cookie branches: SAME ' . $same . ' / both-refused ' . $bothRefuse . ' / DIFF 0'
        . ' over ' . count($cases) . ' cases'
        . ($skewOnly > 0 ? ' (' . $skewOnly . ' matched only after absorbing a 1s request skew)' : '')
        . ' - restores the v1.1.0 "71 cases SAME / DIFF 0" harness');
});

t('a session cookie from the real branch carries HttpOnly and SameSite', function () {
    // 배포 직후 사람이 개발자도구로 확인하라고 INSTALL.md 가 적어 둔 항목이다.
    // 여기서는 평문 HTTP 라 `secure` 는 대상이 아니다 (샌드박스는 COOKIE_SECURE=false).
    live_or_skip();
    live_server();
    cookie_probe_install();
    foreach (array('/server/_probe_real.php' => 'real', '/server/_probe_leg.php' => 'legacy') as $p => $label) {
        $r = live_req('POST', $p, array('json' => array(
            'name'  => 'ULOGIN_SID',
            'value' => 'sample',
            'opt'   => array('expires' => time() + 3600, 'path' => '/', 'secure' => false,
                             'httponly' => true, 'samesite' => 'Lax'),
        )));
        assert_eq(200, $r['status'], $label . ' probe');
        $h = (string) $r['json']['cookie'];
        assert_contains('HttpOnly', $h, $label . ' branch must carry HttpOnly');
        assert_contains('SameSite=Lax', $h, $label . ' branch must carry SameSite');
        assert_not_contains('; secure', $h, $label . ' branch must not claim secure on a non-secure cookie');
    }
});

t('PHP core does NOT validate separators in samesite - the repo hardening is deliberate', function () {
    /*
     * 이 케이스는 **회귀 방지가 아니라 근거 보존**이다.
     *
     * 위 동등성 테스트가 `send_cookie()` 를 지나는 이유를 실측으로 남긴다:
     * 본체 `setcookie()` 는 `samesite` 의 `;` 를 거르지 않고 그대로 헤더에 싣는다.
     * 이 저장소의 `cookie_attr_offender()` 는 거부한다 — **본체보다 엄격하다.**
     * 두 조립 함수를 직접 비교하면 그 하드닝이 DIFF 로 보인다.
     *
     * 언젠가 PHP 가 이 값을 검증하기 시작하면 이 케이스가 실패한다. 그때는
     * 위 테스트의 설명을 갱신할 신호이지, 코드를 고칠 신호가 아니다.
     */
    live_or_skip();
    live_server();
    cookie_probe_install();
    $box = live_sandbox();
    $raw = $box . '/server/_probe_raw_core.php';
    if (!is_file($raw)) {
        file_put_contents($raw, "<?php\n"
            . "\$ok = setcookie('X', 'v', array('path' => '/', 'samesite' => 'Lax; Domain=evil.invalid'));\n"
            . "\$l = array();\n"
            . "foreach (headers_list() as \$h) { if (stripos(\$h, 'Set-Cookie:') === 0) { \$l[] = trim(substr(\$h, 11)); } }\n"
            . "header_remove('Set-Cookie');\n"
            . "header('Content-Type: application/json');\n"
            . "echo json_encode(array('ret' => \$ok, 'cookie' => \$l === array() ? null : end(\$l)));\n");
    }
    $r = live_req('GET', '/server/_probe_raw_core.php');
    assert_eq(200, $r['status']);
    $j = $r['json'];
    assert_true(is_array($j), 'raw core probe must answer JSON');
    if ($j['ret'] === true && $j['cookie'] !== null && strpos((string) $j['cookie'], 'Domain=evil.invalid') !== false) {
        // 예상대로다. 본체는 통과시킨다.
        note('confirmed on this interpreter: PHP core setcookie() lets a separator through in samesite ('
            . $j['cookie'] . '). the repo refuses it - that hardening is why the equivalence test compares '
            . 'send_cookie() branches rather than the two assemblers directly.');
        return;
    }
    note('PHP core now appears to validate samesite separators (ret=' . ut_show($j['ret'])
        . ', cookie=' . ut_show($j['cookie']) . '). the explanation in this file should be revisited - '
        . 'the code is fine either way.');
});
