<?php
declare(strict_types=1);

/**
 * 티어 1 — v1.10.1 정적 검사 (설계 130 §5 tier1 · D-373 · D-374)
 *
 *  1) 가입 방식 판정 함수 register_mode_effective() 정의는 1개, register·register_status·validate 가 그것을 부른다.
 *     settings 의 allow_register 를 직접 읽는 판정 사본(setting_get('allow_register')) 0건.
 *  2) s2s 4파일: al_s2s_auth( 앞에 DB 를 쓰는 호출이 없다. 대상 판정 함수가 is_emergency_user · access_rank 를 부른다.
 *  3) 한도 합산(points_s2s_limits)은 points_apply 의 잠금 안 콜백에서만 부른다 — 변이 ⑦(잠금 밖으로)을 이 검사가 잡는다.
 *     (php -S 는 Windows 에서 단일 작업자라 동시성 시험으로는 재현되지 않는다.) 변이를 실제로 심어 빨개지는지 같이 본다.
 *  4) 0011 에 ENUM · ON DUPLICATE · FOR UPDATE 0회(주석 제외).
 *  5) 「서버 PC」 판정 ulogin_local_request_ok() 정의 1개, 라우터와 화면 둘 다 부른다.
 * 검사기 자신이 0건을 낼 수 없는 상태인지 먼저 본다(규약 7): 대상 파일이 실제로 읽혔는지 센다.
 */

require_once __DIR__ . '/../lib/checks.php';

$ROOT = h_root();

function t80_code(string $rel): string
{
    $src = h_read(h_root() . '/' . $rel);
    if (strlen($src) < 100) {
        fail('HARNESS-FAIL ' . $rel . ' unreadable or empty');
    }
    return h_php_code_only_keep_strings($src);
}

t('register_mode_effective() is defined once and is the only gate (register, register_status, validate)', function () use ($ROOT) {
    $defs = 0;
    $copies = array();
    $n = 0;
    foreach (h_files($ROOT . '/server', array('php')) as $f) {
        $n++;
        $code = h_php_code_only_keep_strings(h_read($f));   // 문자열을 남겨야 'allow_register' 인자가 보인다
        $defs += preg_match_all('~function\s+register_mode_effective\s*\(~', $code);
        if (preg_match("~setting_get\(\s*'allow_register'~", $code) === 1) {
            $copies[] = h_rel($f, $ROOT);
        }
    }
    assert_true($n > 50, 'HARNESS-FAIL server tree scan saw only ' . $n . ' files');
    assert_eq(1, $defs, 'definitions of register_mode_effective');
    assert_eq(array(), $copies, "files reading setting_get('allow_register') directly (gate copy)");
    // 검사기가 눈멀지 않았는지 — 같은 정규식이 옛 판정 한 줄을 실제로 잡는가(규약 7)
    assert_eq(1, preg_match("~setting_get\(\s*'allow_register'~", h_php_code_only_keep_strings("<?php if (!(bool) setting_get('allow_register', ALLOW_REGISTER)) {}")),
        'HARNESS-FAIL the copy detector cannot see the v1.8.2 expression');
    foreach (array('server/api/auth/register.php', 'server/api/auth/register_status.php', 'server/api/sso/validate.php') as $f) {
        assert_contains('register_mode_effective(', t80_code($f), $f);
    }
});

t('s2s points: no DB call before al_s2s_auth, target check calls is_emergency_user and access_rank', function () {
    foreach (array('balance', 'earn', 'spend', 'history') as $op) {
        $f = 'server/api/points/s2s_' . $op . '.php';
        $code = t80_code($f);
        $auth = strpos($code, 'al_s2s_auth(');
        assert_true($auth !== false, $f . ' calls al_s2s_auth');
        if (preg_match('~\b(db_one|db_all|db_val|db_exec|db_insert|db_tx|points_s2s_target|points_s2s_move|points_apply)\s*\(~', $code, $m, PREG_OFFSET_CAPTURE) === 1) {
            assert_true($m[0][1] > $auth, $f . ': ' . $m[1][0] . ' appears before al_s2s_auth');
        }
        assert_true(preg_match('~points_s2s_(target|move)\s*\(~', $code) === 1, $f . ' goes through the shared target check');
    }
    // 문자열 리터럴(사유 코드)까지 봐야 하므로 원문에서 주석만 신경 쓰지 않고 자른다
    $core = h_read(h_root() . '/server/core/points.php');
    $i = strpos($core, 'function points_s2s_target(');
    $j = strpos($core, 'function points_s2s_used(');
    assert_true($i !== false && $j !== false && $j > $i, 'HARNESS-FAIL points_s2s_target body not found');
    $body = substr($core, $i, $j - $i);
    assert_contains('is_emergency_user(', $body, 'target check: emergency account');
    assert_contains("access_rank('use')", $body, 'target check: access level >= use');
    // 순서: 비상 → 상태 → 단계 → 설정
    $o = array(strpos($body, 'is_emergency_user('), strpos($body, "'user_inactive'"), strpos($body, "access_rank('use')"), strpos($body, "'points_disabled'"));
    assert_eq($o, array_values(array_filter($o, 'is_int')), 'all four steps present');
    $sorted = $o;
    sort($sorted);
    assert_eq($sorted, $o, 'target check order: emergency -> status -> level -> points_json');
    $hist = t80_code('server/api/points/s2s_history.php');
    assert_contains("' WHERE user_id = ? AND service_id = ?'", $hist, 'history filters by the calling service');
    assert_contains("'s2s:'", t80_code('server/api/points/consume.php'), 'consume refuses the s2s: key space');
});

/** 한도 합산이 points_apply(…, 콜백) 의 콜백 안에만 있는가. 반환: 위반 설명 | null */
function t80_limits_inside_lock(string $code): ?string
{
    $calls = preg_match_all('~points_s2s_limits\s*\(~', $code) - preg_match_all('~function\s+points_s2s_limits\s*\(~', $code);
    if ($calls !== 1) {
        return 'points_s2s_limits( is called ' . $calls . ' time(s) outside its definition (expected exactly 1)';
    }
    $a = strpos($code, '$res = points_apply(');
    if ($a === false) {
        return 'points_apply( call in points_s2s_move not found';
    }
    $open = strpos($code, '(', $a);
    $args = h_balanced($code, $open);
    if ($args === null) {
        return 'points_apply( arguments not balanced';
    }
    if (preg_match('~function\s*\([^)]*\)\s*use\s*\([^)]*\)\s*\{[^{}]*points_s2s_limits\s*\(~', $args) !== 1) {
        return 'points_s2s_limits( is not inside the points_apply in-transaction callback';
    }
    return null;
}

t('limit sums run inside the points_apply lock (mutant 7 caught statically)', function () {
    $code = t80_code('server/core/points.php');
    assert_eq(null, t80_limits_inside_lock($code), 'limits inside lock');
    // 변이 ⑦ — 한도 검사를 잠금 밖(points_apply 앞)으로 옮긴다
    $from1 = "    \$res = points_apply(\$userId, \$delta, [";
    $from2 = "], function (array \$u) use (\$cfg, \$sid, \$userId, \$op, \$x) {\n        points_s2s_limits(\$cfg, \$sid, \$userId, \$op, \$x['amount']);\n    });";
    assert_eq(1, substr_count($code, $from1), 'HARNESS-FAIL mutation anchor 1');
    $src = h_read(h_root() . '/server/core/points.php');
    assert_eq(1, substr_count($src, $from2), 'HARNESS-FAIL mutation anchor 2');
    $mut = str_replace($from2, '], null);', $src);
    $mut = str_replace($from1, "    points_s2s_limits(\$cfg, \$sid, \$userId, \$op, \$x['amount']);\n" . $from1, $mut);
    assert_true(t80_limits_inside_lock(h_php_code_only($mut)) !== null, 'mutant 7 (limits outside the lock) must be caught');
});

t('migration 0011 has no dialect-only tokens', function () {
    $sql = h_read(h_root() . '/server/migrations/0011_v1101_register_points.sql');
    assert_true(strlen($sql) > 200, 'HARNESS-FAIL 0011 unreadable');
    $body = preg_replace('~^\s*--[^\n]*$~m', '', $sql);
    foreach (array('ENUM', 'ON DUPLICATE', 'FOR UPDATE') as $tok) {
        assert_eq(0, substr_count(strtoupper((string) $body), $tok), '0011 contains ' . $tok);
    }
    assert_contains('CREATE TABLE FROM SCHEMA app_ulogin_register_requests;', $sql, 'table from schema.sql');
});

t('server-pc test ulogin_local_request_ok() is defined once and used by the router and the screen', function () use ($ROOT) {
    $defs = 0;
    foreach (array_merge(h_files($ROOT . '/server', array('php')), array($ROOT . '/tools/router-ulogin.php')) as $f) {
        $defs += preg_match_all('~function\s+ulogin_local_request_ok\s*\(~', h_php_code_only(h_read($f)));
    }
    assert_eq(1, $defs, 'definitions of ulogin_local_request_ok');
    assert_contains('ulogin_local_request_ok($_SERVER)', t80_code('tools/router-ulogin.php'), 'router');
    $la = t80_code('server/local_admin.php');
    assert_contains('ulogin_local_request_ok($_SERVER)', $la, 'screen');
    $raw = h_read(h_root() . '/server/local_admin.php');
    $pc = strpos($raw, 'if (!ulogin_local_request_ok($_SERVER))');
    $pb = strpos($raw, "require_once __DIR__ . '/core/bootstrap.php';");
    assert_true($pc !== false && $pb !== false && $pc < $pb, 'screen checks before bootstrap');
    foreach (array('em_local_set_password(', 'em_local_unlock(', 'em_local_init(') as $fn) {
        assert_contains($fn, $la, 'screen uses the shared function ' . $fn);
        assert_contains($fn, t80_code('server/tools/emergency_admin.php'), 'CLI uses the shared function ' . $fn);
    }
    $lo = t80_code('server/core/localonly.php');
    foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_CF_RAY', 'HTTP_CDN_LOOP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_HOST',
                   'HTTP_FORWARDED', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_PROTO', 'HTTP_TRUE_CLIENT_IP', 'HTTP_CF_') as $k) {
        assert_contains("'" . $k . "'", $lo, 'proxy header list has ' . $k);
    }
});

t('v1.10.1 D-375: M-1 consume/limit filter, M-2 key gate before csrf, L-2 installer uses the shared admin rule', function () {
    $c = t80_code('server/api/points/consume.php');
    assert_contains("strncasecmp(\$reason, 's2s_', 4)", $c, 'M-1 consume refuses s2s_ reasons');
    $core = h_read(h_root() . '/server/core/points.php');
    $i = strpos($core, 'function points_s2s_used(');
    $j = strpos($core, 'function points_s2s_limits(');
    assert_true($i !== false && $j !== false && $j > $i, 'HARNESS-FAIL points_s2s_used body not found');
    assert_eq(2, substr_count(substr($core, $i, $j - $i), 'AND idempotency_key LIKE ?'), 'M-1 both sums filter on the s2s: key');
    $raw = h_read(h_root() . '/server/local_admin.php');
    $pa = strpos($raw, 'if (!$authed) {   // LA-AUTH');
    $po = strpos($raw, '} elseif (!la_origin_ok()) {');
    $pt = strpos($raw, "\$token = la_token_issue();");
    assert_true($pa !== false && $po !== false && $pa < $po, 'M-2 key gate comes before origin/csrf');
    assert_true($pt !== false && strrpos(substr($raw, 0, $pt), 'if ($authed) {') !== false, 'M-2 csrf token is issued only with the key');
    assert_contains("require_once __DIR__ . '/core/localkey.php';", $raw, 'M-2 screen loads localkey');
    assert_contains("'open-link'", t80_code('server/tools/emergency_admin.php'), 'M-2 CLI has --open-link');
    $bat = h_read(h_root() . '/tools/emergency-admin.bat');
    assert_contains('if /i "%~1"=="open" set "EA_ARGS=--open-link"', $bat, 'M-2 bat maps open');
    assert_false((bool) preg_match('~[^\x09\x0a\x0d\x20-\x7e]~', $bat), 'bat stays ASCII');
    $ins = h_read(h_root() . '/server/install.php');
    assert_contains("require_once INS_DIR . '/core/pwstrong.php';", $ins, 'L-2 installer loads the shared rule');
    $k = strpos($ins, 'function ins_handle_step5(');
    assert_true($k !== false && strpos($ins, 'pw_strong_problem(', $k) !== false, 'L-2 step 5 applies pw_strong_problem');
    $defs = 0;
    foreach (h_files(h_root() . '/server', array('php')) as $f) {
        $defs += preg_match_all('~function\s+em_pw_is_common\s*\(~', h_php_code_only(h_read($f)));
    }
    assert_eq(1, $defs, 'one copy of the common-password rule');
});
