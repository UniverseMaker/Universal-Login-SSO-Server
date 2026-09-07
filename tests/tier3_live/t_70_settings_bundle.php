<?php
declare(strict_types=1);

/**
 * 티어 3 — **설정 번들 export / import** (`api/admin/settings_bundle.php`)
 *
 * ─── 왜 이 파일이 생겼나 ──────────────────────────────────────────────
 * v1.6.0 의 QA 가 이 스위트를 훑고 이렇게 적었다: **「스위트에 `settings_bundle` ·
 * `settingsdef` · `plan_hash` · `sb_` 를 이름으로 언급하는 케이스가 0건이다」**.
 * 그리고 그 라운드의 유일한 조건부 결함(Q-1 — 같은 번들을 두 번 적용하면
 * `secret_forced_disabled` 보호가 경고 없이 벗겨진다)이 **정확히 그 공백에서** 나왔다.
 * 사람이 손으로 두 번 적용해 보고서야 찾은 것이고, 「같은 번들 2회가 무변경인가」
 * 한 줄짜리 단언이 있었으면 사람 없이 잡혔다.
 *
 * 그래서 이 파일은 **새 기능을 이름으로 겨냥한다.** 스위트를 무르게 하는 것이
 * 아니라 덮이지 않던 표면을 덮는 것이다. 티어 1 의 일반 스윕(`php -l` ·
 * `json_out()` 배선 · 비로그인 폭발반경)은 이 파일 없이도 신규 코드를 포함하지만,
 * 그 셋은 **인증된 superadmin 이 실제로 무엇을 할 수 있는가**를 한 번도 묻지 않는다.
 *
 * ─── 무엇을 겨냥하는가 (QA `62e` §F-2 의 공백 목록) ────────────────────
 *   F-a  superadmin 인증 상태의 4동작 + admin 403 + 비로그인 401
 *   F-b  `expect_plan_hash` 강제 — 누락 / 형식오류 / 불일치 / 리플레이
 *   F-d  게이트 강제 — **`min_account_level: "basic"` 하한**(v1.6.0 Critical H-1) ·
 *        `allow_public` 두 곳 규약 · 과잉 차단 방지 대조군
 *   F-e  **멱등 · 왕복** — Q-1 이 나온 자리. export→import 무연산, 같은 번들 3회
 *   F-g  `plan_hash` 결정성 — 같은 입력은 같은 해시 · 값이 바뀌면 해시가 바뀐다 ·
 *        평문이 응답에 실리지 않는다
 *   + H-2 `gate_defeated_by_default_level` 경고 · H-3 `service_secret` 마스킹 ·
 *     M-4 본문 상한(`Transfer-Encoding: chunked` 포함) · M-5 자기 export 왕복
 *
 * ─── 못 잡는 것 ───────────────────────────────────────────────────────
 *  · **트랜잭션 원자성과 실제 경합**(F-h). `php -S` 는 단일 스레드라 요청을 직렬
 *    처리한다. 여기서 재는 「낡은 해시가 409 로 거절되는가」는 순차 판정이고,
 *    **두 apply 가 DB 안에서 실제로 겹치는 상황은 mod_php/FPM 에서만 난다.**
 *    미검증으로 남는다 — 서버 2대를 띄우는 하네스는 이 스위트의 범위를 넘는다
 *  · **가이드 문서와 오류 코드의 일치**(F-c). 문서 대조는 이 스위트의 범위 밖이다
 *  · PHP 7.2 에서의 동작. 개발 환경에 8.1 만 있다(`tools/php72_scan.php` 가 정적으로 본다)
 */

require_once __DIR__ . '/../lib/live.php';

$GLOBALS['SB'] = array('user' => null, 'pw' => 'Passw0rd!bundle', 'bearer' => null, 'adminBearer' => null);

/** superadmin 계정 하나 + 전역 admin 계정 하나. 권한 경계를 재려면 둘이 필요하다. */
function sb_accounts(): array
{
    $S = &$GLOBALS['SB'];
    if ($S['user'] !== null) {
        return $S;
    }
    $pdo = live_ready_install();
    live_server();
    live_reset_rate_limits();

    $mk = function (string $prefix, string $role, string $level) use ($pdo) {
        $u = $prefix . substr(bin2hex(random_bytes(4)), 0, 8);
        $r = live_req('POST', '/server/api/auth/register.php', array('json' => array(
            'username'         => $u,
            'email'            => $u . '@test.invalid',
            'password'         => $GLOBALS['SB']['pw'],
            'password_confirm' => $GLOBALS['SB']['pw'],
            'agree_tos'        => true,
            'agree_privacy'    => true,
        )));
        if ($r['status'] !== 201 && $r['status'] !== 200) {
            fail('could not create the ' . $role . ' account: HTTP ' . $r['status'] . ' ' . substr($r['body'], 0, 200));
        }
        // 전역 role 을 직접 올린다. 관리자 API 는 `users.role` 만 본다 (D-50).
        $st = $pdo->prepare('UPDATE app_ulogin_users SET role = ?, account_level = ? WHERE username = ?');
        $st->execute(array($role, $level, $u));
        $l = live_req('POST', '/server/api/auth/login.php',
            array('json' => array('username' => $u, 'password' => $GLOBALS['SB']['pw'])));
        if ($l['status'] !== 200 || !isset($l['json']['data']['access_token'])) {
            fail('could not log the ' . $role . ' account in: HTTP ' . $l['status']);
        }
        return array($u, (string) $l['json']['data']['access_token']);
    };

    list($su, $suTok)   = $mk('sbsu_', 'superadmin', 'staff');
    list($ad, $adTok)   = $mk('sbad_', 'admin', 'staff');
    $S['user']          = $su;
    $S['bearer']        = $suTok;
    $S['adminUser']     = $ad;
    $S['adminBearer']   = $adTok;
    return $S;
}

/** Bearer 를 실은 번들 요청. Bearer 는 CSRF 를 면제한다(`require_csrf()`). */
function sb_call(string $action, $payload, ?string $bearer = null): array
{
    $S = sb_accounts();
    $tok = $bearer === null ? $S['bearer'] : $bearer;
    $path = '/server/api/admin/settings_bundle.php' . ($action === '' ? '' : ('?action=' . $action));
    $opt = array('headers' => array('Authorization' => 'Bearer ' . $tok));
    if ($payload !== null) {
        $opt['json'] = $payload;
    }
    return live_req($payload === null ? 'GET' : 'POST', $path, $opt);
}

/** 오류 코드 목록. `errors` 는 `data` 안에 있다 (`error` 안이 아니다). */
function sb_codes(array $r): array
{
    $out = array();
    foreach ((isset($r['json']['data']['errors']) && is_array($r['json']['data']['errors'])
        ? $r['json']['data']['errors'] : array()) as $e) {
        $out[] = isset($e['code']) ? (string) $e['code'] : '?';
    }
    return $out;
}

/** 경고 코드 목록. dry-run 은 `plan.warnings`, apply 는 `warnings` 다. */
function sb_warncodes(array $r): array
{
    $w = array();
    if (isset($r['json']['data']['plan']['warnings']) && is_array($r['json']['data']['plan']['warnings'])) {
        $w = $r['json']['data']['plan']['warnings'];
    } elseif (isset($r['json']['data']['warnings']) && is_array($r['json']['data']['warnings'])) {
        $w = $r['json']['data']['warnings'];
    }
    $out = array();
    foreach ($w as $x) {
        $out[] = isset($x['code']) ? (string) $x['code'] : '?';
    }
    return $out;
}

/** 한 경로의 오류 항목. */
function sb_err_at(array $r, string $path): ?array
{
    foreach ((isset($r['json']['data']['errors']) && is_array($r['json']['data']['errors'])
        ? $r['json']['data']['errors'] : array()) as $e) {
        if (isset($e['path']) && $e['path'] === $path) {
            return $e;
        }
    }
    return null;
}

/** 서비스 한 덩어리. */
function sb_svc(string $sid, array $extra = array(), array $secrets = array()): array
{
    return array_merge(array(
        'service_id'         => $sid,
        'service_name'       => 'SB ' . $sid,
        'auto_join'          => true,
        'redirect_whitelist' => array('http://127.0.0.1/cb.html'),
        'secrets'            => $secrets,
    ), $extra);
}

/** 번들 요청 본문. */
function sb_bundle(array $services, array $top = array()): array
{
    return array('bundle' => array_merge(
        array('ulogin_settings_bundle' => '1.0', 'services' => $services), $top));
}

/** dry-run → apply. 계획 해시를 그대로 물려준다. */
function sb_apply(array $req): array
{
    $d = sb_call('dry-run', $req);
    if ($d['status'] !== 200) {
        return $d;
    }
    $req['expect_plan_hash'] = $d['json']['data']['plan_hash'];
    return sb_call('apply', $req);
}

/** 유일한 서비스 ID 하나. 케이스끼리 밟지 않게 한다. */
function sb_sid(string $tag): string
{
    return 'sb' . $tag . substr(bin2hex(random_bytes(3)), 0, 5);
}

/** `Transfer-Encoding: chunked` 로 본문을 보낸다 — `Content-Length` 가 **없는** 요청. */
function sb_chunked(string $path, string $body, string $bearer): array
{
    $c = live_config();
    live_server();
    $fp = @fsockopen('127.0.0.1', (int) $c['http_port'], $eno, $estr, 15);
    if ($fp === false) {
        fail('could not open a raw socket to the test server: ' . $estr);
    }
    $req = "POST " . $path . " HTTP/1.1\r\n"
        . "Host: 127.0.0.1:" . (string) ((int) $c['http_port']) . "\r\n"
        . "Content-Type: application/json\r\n"
        . "Authorization: Bearer " . $bearer . "\r\n"
        . "Transfer-Encoding: chunked\r\n"
        . "Connection: close\r\n\r\n";
    fwrite($fp, $req);
    fwrite($fp, dechex(strlen($body)) . "\r\n");
    $off = 0;
    while ($off < strlen($body)) {
        $n = fwrite($fp, substr($body, $off, 32768));
        if ($n === false || $n === 0) {
            break;
        }
        $off += $n;
    }
    fwrite($fp, "\r\n0\r\n\r\n");
    $all = '';
    while (!feof($fp)) {
        $all .= (string) fread($fp, 65536);
    }
    fclose($fp);
    $split = strpos($all, "\r\n\r\n");
    $head  = $split === false ? $all : substr($all, 0, $split);
    $bodyR = $split === false ? '' : substr($all, $split + 4);
    if (stripos($head, 'Transfer-Encoding: chunked') !== false) {
        $dec = '';
        $p   = 0;
        while ($p < strlen($bodyR)) {
            $nl = strpos($bodyR, "\r\n", $p);
            if ($nl === false) {
                break;
            }
            $len = (int) hexdec(trim(substr($bodyR, $p, $nl - $p)));
            if ($len === 0) {
                break;
            }
            $dec .= substr($bodyR, $nl + 2, $len);
            $p = $nl + 2 + $len + 2;
        }
        $bodyR = $dec;
    }
    $status = 0;
    if (preg_match('~^HTTP/1\.[01] (\d{3})~', $head, $m) === 1) {
        $status = (int) $m[1];
    }
    $j = json_decode($bodyR, true);
    return array('status' => $status, 'body' => $bodyR, 'json' => is_array($j) ? $j : null, 'headers' => array($head));
}

// =====================================================================
// F-a — 권한: 네 동작 전부 superadmin
// =====================================================================

t('settings_bundle: all four actions require superadmin (admin gets 403, anonymous gets 401)', function () {
    $S = sb_accounts();
    live_reset_rate_limits();
    $min = array('bundle' => array('ulogin_settings_bundle' => '1.0'));

    // 비로그인 — 4/4 가 401 이어야 하고 응답에 스키마 정보가 없어야 한다
    $anon = array();
    $anon['export']   = live_req('GET', '/server/api/admin/settings_bundle.php');
    foreach (array('validate', 'dry-run', 'apply') as $a) {
        $anon[$a] = live_req('POST', '/server/api/admin/settings_bundle.php?action=' . $a,
            array('json' => $min));
    }
    $bad = array();
    foreach ($anon as $k => $r) {
        if ($r['status'] !== 401) {
            $bad[] = 'anonymous ' . $k . ' answered HTTP ' . $r['status'] . ' (expected 401)';
        }
        // 인증 전에 표면을 알려 주지 않는다
        foreach (array('settings_keys', 'service_id', 'plan_hash') as $leak) {
            if (strpos($r['body'], $leak) !== false) {
                $bad[] = 'anonymous ' . $k . ' response mentions "' . $leak . '" before authenticating';
            }
        }
    }
    assert_clean($bad, 'anonymous access to the settings bundle');

    // 전역 admin — 4/4 가 403
    $bad = array();
    $r = sb_call('', null, $S['adminBearer']);
    if ($r['status'] !== 403) {
        $bad[] = 'admin export answered HTTP ' . $r['status'] . ' (expected 403)';
    }
    foreach (array('validate', 'dry-run', 'apply') as $a) {
        $r = sb_call($a, $min, $S['adminBearer']);
        if ($r['status'] !== 403) {
            $bad[] = 'admin ' . $a . ' answered HTTP ' . $r['status'] . ' (expected 403)';
        }
    }
    assert_clean($bad, 'global admin access to the settings bundle (D-50: only the global role counts)');

    // superadmin — 4/4 가 200
    $bad = array();
    $r = sb_call('', null);
    if ($r['status'] !== 200) {
        $bad[] = 'superadmin export answered HTTP ' . $r['status'] . ' ' . substr($r['body'], 0, 200);
    }
    assert_true(isset($r['json']['data']['bundle']['ulogin_settings_bundle']), 'export must carry the format discriminator');
    assert_true(isset($r['json']['data']['meta']['bundle_bytes']), 'export must carry meta.bundle_bytes');
    foreach (array('validate', 'dry-run', 'apply') as $a) {
        $req = $min;
        if ($a === 'apply') {
            $d = sb_call('dry-run', $min);
            $req['expect_plan_hash'] = $d['json']['data']['plan_hash'];
        }
        $r = sb_call($a, $req);
        if ($r['status'] !== 200) {
            $bad[] = 'superadmin ' . $a . ' answered HTTP ' . $r['status'] . ' ' . substr($r['body'], 0, 200);
        }
    }
    assert_clean($bad, 'superadmin access to the settings bundle');
});

t('settings_bundle: export never carries a secret value or a service secret hash', function () {
    $S = sb_accounts();
    live_reset_rate_limits();
    $sid = sb_sid('exp');
    $r = sb_apply(sb_bundle(array(sb_svc($sid, array('service_secret' => 'canary-service-secret-1'), array(
        array('secret_key' => 'ai_api_key', 'secret_value' => 'sk-CANARY-EXPORT-9', 'min_billing_tier' => 'lite'),
    )))));
    assert_eq(200, $r['status'], 'setup apply');

    $ex = sb_call('', null);
    assert_eq(200, $ex['status'], 'export');
    $bad = array();
    foreach (array('sk-CANARY-EXPORT-9', 'canary-service-secret-1', 'service_secret_hash', 'secret_value_enc') as $needle) {
        if (strpos($ex['body'], $needle) !== false) {
            $bad[] = 'the export body contains "' . $needle . '"';
        }
    }
    assert_clean($bad, 'secret material in the export (design decision 2 / D-190)');

    // 값의 유무만 알린다
    $found = null;
    foreach ($ex['json']['data']['bundle']['services'] as $svc) {
        if ($svc['service_id'] === $sid) {
            $found = $svc;
        }
    }
    assert_true($found !== null, 'the exported bundle must contain the service just created');
    assert_true($found['has_secret'] === true, 'has_secret must report that a service secret is set');
    assert_eq(null, $found['secrets'][0]['secret_value'], 'secrets[].secret_value must always be null');
    assert_true($found['secrets'][0]['value_present'] === true, 'value_present must report that a value exists');
});

// =====================================================================
// F-b — `expect_plan_hash` 강제
// =====================================================================

t('settings_bundle: apply refuses to run without a fresh plan hash (F-b)', function () {
    sb_accounts();
    live_reset_rate_limits();
    $sid = sb_sid('hash');
    $req = sb_bundle(array(sb_svc($sid, array(), array(
        array('secret_key' => 'ai_api_key', 'secret_value' => 'sk-HASH-1', 'min_billing_tier' => 'lite'),
    ))));

    $bad = array();

    // 1) 해시 없이
    $r = sb_call('apply', $req);
    if ($r['status'] !== 400 || ($r['json']['error']['code'] ?? null) !== 'VALIDATION_FAILED') {
        $bad[] = 'apply without expect_plan_hash: HTTP ' . $r['status']
            . ' code=' . ut_show($r['json']['error']['code'] ?? null) . ' (expected 400 VALIDATION_FAILED)';
    }
    if (($r['json']['error']['field'] ?? null) !== 'expect_plan_hash') {
        $bad[] = 'that 400 must name expect_plan_hash in error.field, got '
            . ut_show($r['json']['error']['field'] ?? null);
    }

    // 2) 형식 위반
    $r2 = $req;
    $r2['expect_plan_hash'] = 'not-a-hash!!';
    $r = sb_call('apply', $r2);
    if ($r['status'] !== 400) {
        $bad[] = 'apply with a malformed hash: HTTP ' . $r['status'] . ' (expected 400)';
    }

    // 3) 형식은 맞고 값이 틀린 해시 → 409 + 새 계획
    $r3 = $req;
    $r3['expect_plan_hash'] = str_repeat('a', 64);
    $r = sb_call('apply', $r3);
    if ($r['status'] !== 409) {
        $bad[] = 'apply with a well-formed but wrong hash: HTTP ' . $r['status'] . ' (expected 409)';
    }
    if (!isset($r['json']['data']['current_plan_hash']) || !isset($r['json']['data']['plan'])) {
        $bad[] = 'the 409 must hand back current_plan_hash and a recomputed plan';
    }
    assert_clean($bad, 'expect_plan_hash enforcement');

    // 4) 그 실패들이 DB 를 건드리지 않았다
    $pdo = live_ready_install();
    $st = $pdo->prepare('SELECT COUNT(*) c FROM app_ulogin_services WHERE service_id = ?');
    $st->execute(array($sid));
    assert_eq(0, (int) $st->fetch()['c'], 'a refused apply must not create the service row');

    // 5) 정상 apply → 같은 해시를 다시 쓰면 409 (리플레이 불가)
    $d = sb_call('dry-run', $req);
    assert_eq(200, $d['status'], 'dry-run');
    $h = $d['json']['data']['plan_hash'];
    $ok = $req;
    $ok['expect_plan_hash'] = $h;
    $a = sb_call('apply', $ok);
    assert_eq(200, $a['status'], 'apply with the fresh hash');
    $again = sb_call('apply', $ok);
    assert_eq(409, $again['status'], 'replaying a spent hash must be refused (the state it described is gone)');
});

t('settings_bundle: plan_hash is deterministic and never carries plaintext (F-g)', function () {
    sb_accounts();
    live_reset_rate_limits();
    $sid = sb_sid('det');
    $mk = function (string $value) use ($sid) {
        return sb_bundle(array(sb_svc($sid, array(), array(
            array('secret_key' => 'ai_api_key', 'secret_value' => $value, 'min_billing_tier' => 'lite'),
        ))));
    };
    $a = sb_call('dry-run', $mk('sk-DET-AAA'));
    $b = sb_call('dry-run', $mk('sk-DET-AAA'));
    $c = sb_call('dry-run', $mk('sk-DET-BBB'));
    assert_eq(200, $a['status'], 'dry-run A');
    $h1 = (string) $a['json']['data']['plan_hash'];
    $h2 = (string) $b['json']['data']['plan_hash'];
    $h3 = (string) $c['json']['data']['plan_hash'];
    assert_true(preg_match('~^[0-9a-f]{64}$~', $h1) === 1, 'plan_hash must be 64 lowercase hex chars');
    assert_eq($h1, $h2, 'the same bundle against the same state must produce the same hash');
    assert_true($h1 !== $h3, 'changing a secret value must change the hash - otherwise a spent hash applies new content');
    $bad = array();
    foreach (array($a, $c) as $r) {
        foreach (array('sk-DET-AAA', 'sk-DET-BBB') as $needle) {
            if (strpos($r['body'], $needle) !== false) {
                $bad[] = 'a dry-run response contains the plaintext "' . $needle . '"';
            }
        }
    }
    assert_clean($bad, 'plaintext in the dry-run response');
});

// =====================================================================
// F-d — 게이트 강제. **v1.6.0 Critical H-1 을 여기에 못박는다.**
// =====================================================================

t('settings_bundle: min_account_level "basic" is NOT a gate - it is every member default (H-1 / D-201)', function () {
    sb_accounts();
    live_reset_rate_limits();
    /*
     * v1.6.0 보안 감사 H-1, 리더가 Critical 로 상향(D-201).
     * `basic` 은 랭크 10 이라 종전 판정(「랭크 0 초과」)을 통과했다. 그런데 그것은
     * `users.account_level` 의 스키마 기본값이자 `api/auth/register.php` 가 신규
     * 가입자에게 박는 값이다 — **게이트를 걸어 놓은 것처럼 보이면서 아무도 막지 않았다.**
     * 실측으로 신규 일반 회원의 로그인 payload 에 키가 평문으로 실렸고,
     * `allow_public=0` 이라 게이트 점검 화면에도 뜨지 않았다.
     *
     * 이 케이스가 없으면 다음 라운드가 「랭크 0 초과」로 되돌려도 아무도 모른다.
     */
    $sid = sb_sid('h1');
    $bad = array();
    foreach (array('basic', 'guest') as $lvl) {
        foreach (array('validate', 'dry-run') as $action) {
            $r = sb_call($action, sb_bundle(array(sb_svc($sid, array(), array(
                array('secret_key' => 'ai_api_key', 'secret_value' => 'sk-H1', 'min_account_level' => $lvl),
            )))));
            if ($r['status'] !== 400 || !in_array('gate_required', sb_codes($r), true)) {
                $bad[] = $action . ' with min_account_level=' . $lvl . ': HTTP ' . $r['status']
                    . ' codes=' . json_encode(sb_codes($r)) . ' (expected 400 gate_required)';
            }
        }
    }
    assert_clean($bad, 'population-default values accepted as gates');

    // 오류가 무엇을 요구하는지 말해야 한다 — AI 가 이 문구를 읽고 고친다
    $r = sb_call('validate', sb_bundle(array(sb_svc($sid, array(), array(
        array('secret_key' => 'ai_api_key', 'secret_value' => 'sk-H1', 'min_account_level' => 'basic'),
    )))));
    $e = sb_err_at($r, '/services/0/secrets/0');
    assert_true($e !== null, 'the gate error must be attached to the secret');
    assert_true(strpos((string) $e['expected'], '> basic') !== false,
        'expected must state the floor, not "rank > 0" - got ' . ut_show($e['expected']));
    assert_true(strpos((string) $e['message'], 'min_billing_tier') !== false,
        'the message must recommend min_billing_tier (40b B-2: min_account_level=verified blocks everyone by default)');
    assert_true(strpos($r['body'], 'allow_public') === false,
        'the gate error must not teach allow_public to a request that did not ask for it (design decision 4-6 / D-190)');

    // **과잉 차단 방지.** 기본값을 넘는 값은 전부 게이트로 인정돼야 한다.
    $bad = array();
    foreach (array(
        array('min_billing_tier'  => 'lite'),
        array('min_account_level' => 'verified'),
        array('min_role'          => 'manager'),
    ) as $gate) {
        $r = sb_call('validate', sb_bundle(array(sb_svc($sid, array(), array(
            array_merge(array('secret_key' => 'ai_api_key', 'secret_value' => 'sk-H1'), $gate),
        )))));
        if ($r['status'] !== 200) {
            $bad[] = json_encode($gate) . ' was rejected: HTTP ' . $r['status']
                . ' codes=' . json_encode(sb_codes($r)) . ' (a real gate must pass)';
        }
    }
    assert_clean($bad, 'real gates rejected (over-blocking)');
});

t('settings_bundle: a gated secret really does stay out of a plain member payload (H-1 end to end)', function () {
    $S = sb_accounts();
    live_reset_rate_limits();
    $sid = sb_sid('e2e');
    $a = sb_apply(sb_bundle(array(sb_svc($sid, array(), array(
        array('secret_key' => 'ai_api_key', 'secret_value' => 'sk-E2E-GATED', 'min_billing_tier' => 'lite'),
        array('secret_key' => 'api_base', 'secret_value' => 'https://example.invalid/v1', 'value_type' => 'url'),
    )))));
    assert_eq(200, $a['status'], 'apply the gated service');

    $u = 'sbmem' . substr(bin2hex(random_bytes(4)), 0, 8);
    $r = live_req('POST', '/server/api/auth/register.php', array('json' => array(
        'username' => $u, 'email' => $u . '@test.invalid',
        'password' => $S['pw'], 'password_confirm' => $S['pw'],
        'agree_tos' => true, 'agree_privacy' => true,
    )));
    assert_true($r['status'] === 201 || $r['status'] === 200, 'a plain member can register');
    $l = live_req('POST', '/server/api/auth/login.php', array('json' => array(
        'username' => $u, 'password' => $S['pw'], 'service_id' => $sid)));
    assert_eq(200, $l['status'], 'the plain member can log in against the service');
    $pl = $l['json']['data']['payload'];
    assert_true(is_array($pl), 'login must carry a payload object');
    assert_true(!array_key_exists('ai_api_key', $pl),
        'the gated secret leaked to a brand-new member: ' . json_encode($pl));
    assert_true(strpos($l['body'], 'sk-E2E-GATED') === false, 'the gated plaintext must not appear anywhere in the response');
    assert_eq('https://example.invalid/v1', $pl['api_base'], 'an ungated non-sensitive value must still be delivered');
});

t('settings_bundle: allow_public needs both places, and the acknowledged row is remembered (M-5)', function () {
    sb_accounts();
    live_reset_rate_limits();
    $sid = sb_sid('ack');
    $sec = array('secret_key' => 'public_api_key', 'secret_value' => 'pk-ACK-1', 'allow_public' => true);

    // 한 곳만 적으면 통과하지 못한다
    $r = sb_call('dry-run', sb_bundle(array(sb_svc($sid, array(), array($sec)))));
    assert_eq(400, $r['status'], 'allow_public alone must not pass the gate');
    assert_true(in_array('gate_required', sb_codes($r), true), 'the code must stay gate_required');

    // 두 곳에 적으면 통과하고 경고가 붙는다
    $req = sb_bundle(array(sb_svc($sid, array(), array($sec))),
        array('acknowledge_public_secrets' => array($sid . '/public_api_key')));
    $a = sb_apply($req);
    assert_eq(200, $a['status'], 'both places acknowledged');
    assert_true(in_array('sensitive_secret_public', sb_warncodes($a), true),
        'an acknowledged public secret must still warn - got ' . json_encode(sb_warncodes($a)));

    // 그 승인이 행에 남아야 게이트 점검 화면이 의도한 공개와 빠뜨린 게이트를 가른다
    $pdo = live_ready_install();
    $st = $pdo->prepare('SELECT allow_public FROM app_ulogin_service_secrets WHERE service_id = ? AND secret_key = ?');
    $st->execute(array($sid, 'public_api_key'));
    assert_eq(1, (int) $st->fetch()['allow_public'], 'allow_public must be recorded on the row (migration 0002)');

    /*
     * M-5 — **자기 export 를 그대로 다시 넣을 수 있어야 한다.**
     * export 는 `acknowledge_public_secrets` 를 만들지 않는다(기제를 파일에 노출하지 않는다).
     * 그래서 이미 승인된 행에 대해서는 **DB 의 승인을 승인으로 인정한다.**
     * 인정하지 않으면 `bundle._notes[0]` 의 「이 파일은 그대로 import 할 수 있다」가
     * 깨지고, 그 자리에서 서버가 운영자에게 ack 우회법을 가르친다.
     */
    $ex = sb_call('', null);
    assert_eq(200, $ex['status'], 'export');
    assert_true(!isset($ex['json']['data']['bundle']['acknowledge_public_secrets']),
        'the export must not contain acknowledge_public_secrets');
    $round = array('bundle' => $ex['json']['data']['bundle']);
    $d = sb_call('dry-run', $round);
    assert_eq(200, $d['status'], 'a self export must survive dry-run: codes=' . json_encode(sb_codes($d)));
    // 왕복은 무연산이어야 한다
    $c = $d['json']['data']['plan']['counts'];
    $changes = (int) $c['settings_update'] + (int) $c['services_create'] + (int) $c['services_update']
        + (int) $c['secrets_create'] + (int) $c['secrets_update'] + (int) $c['deletes'];
    assert_eq(0, $changes, 'export -> import must be a no-op: ' . json_encode($c));
    // 새 키에는 두 곳 규약이 그대로 남는다
    $sid2 = sb_sid('ack2');
    $r = sb_call('dry-run', sb_bundle(array(sb_svc($sid2, array(), array(
        array('secret_key' => 'public_api_key', 'secret_value' => 'pk-NEW', 'allow_public' => true))))));
    assert_eq(400, $r['status'], 'a NEW key must still need both places - the database ack is per row');
});

t('settings_bundle: default_account_level that clears its own gate is REJECTED (H-2 / D-206)', function () {
    sb_accounts();
    live_reset_rate_limits();
    /*
     * `services.default_account_level` 은 grant-only 오버라이드다(D-35).
     * `default_account_level='vip'` 서비스에 `min_account_level='vip'` 게이트를 걸면
     * 가입하는 순간 전원이 vip 가 되고 그 게이트는 아무도 막지 않는다.
     *
     * **직전 라운드는 이것을 경고로 냈다.** 리더 판정 D-206 이 거절로 바꿨다 —
     * 경고는 읽히지 않고(이 프로젝트가 `fail_ratelimit` 와 게이트 점검에서 반복 확인했다),
     * 운영자가 apply 하면 키는 여전히 전원에게 나갔다. 거절이 옳은 이유는 그 조합이
     * 뜻하는 것이 **「이 서비스에 가입한 전원이 이 키를 받는다」**이고, 그것을 표현하는
     * 정직한 방법이 이미 있기 때문이다 — `allow_public: true` + 최상위
     * `acknowledge_public_secrets`. **두 경로를 하나의 선언으로 모은다.**
     *
     * 이 케이스가 없으면 다음 라운드가 경고로 되돌려도 아무도 모른다.
     */
    $bad = array();
    foreach (array('validate', 'dry-run') as $action) {
        $r = sb_call($action, sb_bundle(array(sb_svc(sb_sid('h2'), array('default_account_level' => 'vip'), array(
            array('secret_key' => 'ai_api_key', 'secret_value' => 'sk-H2', 'min_account_level' => 'vip'))))));
        if ($r['status'] !== 400 || !in_array('gate_required', sb_codes($r), true)) {
            $bad[] = $action . ': HTTP ' . $r['status'] . ' codes=' . json_encode(sb_codes($r))
                . ' (expected 400 gate_required)';
        }
    }
    assert_clean($bad, 'a gate defeated by default_account_level was accepted');

    // **오류 코드를 새로 만들지 않았다.** 같은 사실이므로 같은 코드다.
    $r = sb_call('dry-run', sb_bundle(array(sb_svc(sb_sid('h2'), array('default_account_level' => 'vip'), array(
        array('secret_key' => 'ai_api_key', 'secret_value' => 'sk-H2', 'min_account_level' => 'vip'))))));
    $e = sb_err_at($r, '/services/0/secrets/0');
    assert_true($e !== null, 'the error must be attached to the secret, not to the service');
    assert_eq('gate_required', (string) $e['code'],
        'the two paths to effective-public must share one error code (D-206)');
    // 원인은 말한다 — 진단에 필요하다
    assert_true(strpos((string) $e['message'], 'default_account_level') !== false,
        'the message must name the cause so the operator can find it - got ' . ut_show($e['message']));
    assert_true(strpos((string) $e['expected'], 'min_billing_tier') !== false,
        'expected must point at an axis a service field cannot grant - got ' . ut_show($e['expected']));
    /*
     * **문구 규율(D-190).** 두 우회를 알려 주지 않는다:
     *  · `allow_public` / `acknowledge_public_secrets` — AI 는 가장 짧은 통과 경로를 택한다
     *  · 「default_account_level 을 낮추십시오」 — 시크릿 하나를 통과시키려고 그 서비스
     *    전 회원의 등급 정책을 조용히 바꾼다
     */
    assert_true(strpos($r['body'], 'allow_public') === false,
        'the rejection must not teach allow_public to a request that did not ask for it (4-6 / D-190)');
    assert_true(strpos($r['body'], 'acknowledge_public_secrets') === false,
        'the rejection must not teach the acknowledgement list either');
    $bad = array();
    foreach (array('낮추', '내리', '지우', '없애', '제거') as $verb) {
        if (strpos((string) $e['message'], 'default_account_level') !== false
            && preg_match('/default_account_level[^.]{0,40}' . preg_quote($verb, '/') . '/u', (string) $e['message']) === 1) {
            $bad[] = 'the message tells the caller to lower/remove default_account_level ("' . $verb . '")';
        }
    }
    assert_clean($bad, 'the rejection points at the service-wide grade policy as the fix');

    // **거절된 것은 만들어지지 않는다** — 오류가 났는데 행이 남으면 거절이 아니다
    $sidR = sb_sid('h2rej');
    $a = sb_call('apply', array_merge(
        sb_bundle(array(sb_svc($sidR, array('default_account_level' => 'vip'), array(
            array('secret_key' => 'ai_api_key', 'secret_value' => 'sk-H2-NEVER', 'min_account_level' => 'vip'))))),
        array('expect_plan_hash' => str_repeat('0', 64))));
    assert_true($a['status'] !== 200, 'apply must not succeed for a rejected combination');
    $pdo = live_ready_install();
    $st = $pdo->prepare('SELECT COUNT(*) FROM app_ulogin_services WHERE service_id = ?');
    $st->execute(array($sidR));
    assert_eq(0, (int) $st->fetchColumn(), 'a rejected bundle must leave no service row');

    // **경고 코드는 사라졌다.** 거절이 되면 그 코드가 날 자리가 없다(도달 불가 코드를 남기지 않는다)
    assert_true(strpos($r['body'], 'gate_defeated_by_default_level') === false,
        'the warning code was replaced by the rejection - it must not survive as an unreachable code');
});

t('settings_bundle: the defeated gate passes only with the SAME declaration as allow_public (H-2 / D-206)', function () {
    $S = sb_accounts();
    live_reset_rate_limits();
    /*
     * **선언 형식을 새로 만들지 않았다.** 이유는 편의가 아니라 구조다 —
     * 그 선언만이 DB 에 `allow_public=1` 을 남기고, 그 표시가 게이트 점검 화면이
     * 실효 공개 시크릿을 찾는 유일한 근거다. 별도 키를 만들면 그 행이 `allow_public=0`
     * 으로 남아 **점검 화면에 뜨지 않는다** — 「탐지 화면이 잡지 못한다」가 H-1 을
     * Critical 로 올린 이유였다(D-201).
     */
    $sid = sb_sid('h2ok');
    $svc = sb_svc($sid, array('default_account_level' => 'vip'), array(
        array('secret_key' => 'ai_api_key', 'secret_value' => 'sk-H2-DECLARED',
              'min_account_level' => 'vip', 'allow_public' => true)));

    // 한 곳만 적으면 통과하지 못한다 — `allow_public` 경로와 완전히 같은 규약이다
    $one = sb_call('dry-run', sb_bundle(array($svc)));
    assert_eq(400, $one['status'], 'allow_public alone must not pass a defeated gate either');
    assert_true(in_array('gate_required', sb_codes($one), true), 'still gate_required');

    // 두 곳에 적으면 통과한다
    $req = sb_bundle(array($svc), array('acknowledge_public_secrets' => array($sid . '/ai_api_key')));
    $d = sb_call('dry-run', $req);
    assert_eq(200, $d['status'], 'the two-place declaration must pass: codes=' . json_encode(sb_codes($d)));
    assert_true(in_array('sensitive_secret_public', sb_warncodes($d), true),
        'the existing public warning must fire - got ' . json_encode(sb_warncodes($d)));
    assert_true(!in_array('gate_defeated_by_default_level', sb_warncodes($d), true),
        'the removed warning code must not reappear');

    $a = sb_apply($req);
    assert_eq(200, $a['status'], 'apply the declared bundle');
    /*
     * **탐지 화면이 이 행을 본다.** 게이트 판정이 `admin_secret_gate_is_effective()` 를
     * 그대로 쓰면 이 행은 「게이트 있음」으로 읽혀 `allow_public=0` 이 기록되고,
     * 그러면 선언을 받고 통과한 행이 점검 화면에서 사라진다.
     */
    $pdo = live_ready_install();
    $st = $pdo->prepare('SELECT allow_public FROM app_ulogin_service_secrets'
        . ' WHERE service_id = ? AND secret_key = ?');
    $st->execute(array($sid, 'ai_api_key'));
    assert_eq(1, (int) $st->fetchColumn(),
        'the declared row must record allow_public=1 - that flag is what the gate-check screen looks at');

    // 선언한 것은 실제로 전원에게 간다. 선언이 사실과 일치한다는 것이 거절을 푸는 근거다
    $u = 'sbh2m' . substr(bin2hex(random_bytes(4)), 0, 8);
    $r = live_req('POST', '/server/api/auth/register.php', array('json' => array(
        'username' => $u, 'email' => $u . '@test.invalid',
        'password' => $S['pw'], 'password_confirm' => $S['pw'],
        'agree_tos' => true, 'agree_privacy' => true)));
    assert_true($r['status'] === 201 || $r['status'] === 200, 'a plain member can register');
    $l = live_req('POST', '/server/api/auth/login.php', array('json' => array(
        'username' => $u, 'password' => $S['pw'], 'service_id' => $sid)));
    assert_eq(200, $l['status'], 'the plain member can log in');
    assert_eq('sk-H2-DECLARED', (string) $l['json']['data']['payload']['ai_api_key'],
        'the declaration says everyone gets it, and everyone does - that is why the declaration is honest');

    // 왕복: 자기 export 를 다시 넣어도 다시 막히지 않는다(M-5 가 이 조합에도 적용된다)
    $ex = sb_call('', null);
    assert_eq(200, $ex['status'], 'export');
    $back = sb_call('dry-run', array('bundle' => $ex['json']['data']['bundle']));
    assert_eq(200, $back['status'],
        'the row already carries the acknowledgement, so its own export must round-trip: '
        . json_encode(sb_codes($back)));
});

t('settings_bundle: the defeat check does not over-reject, and covers BOTH join paths (H-2 / D-206)', function () {
    sb_accounts();
    live_reset_rate_limits();
    /*
     * **`auto_join` 하나로는 부족하다.** `services.default_account_level` 은 두 경로에서
     * 부여된다 — `ensure_user_service()`(`auto_join=1` 필요)와 `api/auth/register.php`
     * (`allow_register=1` 필요. 그 파일이 「auto_join=0 이어도 명시적 가입 경로이므로
     * 만든다」로 명시한다). 직전 라운드는 `auto_join` 만 보았고, 그래서
     * `auto_join: false` + `allow_register`(기본값 1) 조합이 판정을 빠져나갔다.
     */
    $bad = array();
    $mk = function (array $svcExtra, array $secExtra) {
        return sb_bundle(array(sb_svc(sb_sid('h2x'), $svcExtra, array(array_merge(
            array('secret_key' => 'ai_api_key', 'secret_value' => 'sk-H2X'), $secExtra)))));
    };

    // 막아야 하는 것 — 두 가입 경로 각각
    foreach (array(
        array('label' => 'auto_join only',      'svc' => array('default_account_level' => 'vip',
            'auto_join' => true,  'allow_register' => false)),
        array('label' => 'allow_register only', 'svc' => array('default_account_level' => 'vip',
            'auto_join' => false, 'allow_register' => true)),
        array('label' => 'both open',           'svc' => array('default_account_level' => 'vip',
            'auto_join' => true,  'allow_register' => true)),
    ) as $t) {
        $r = sb_call('dry-run', $mk($t['svc'], array('min_account_level' => 'vip')));
        if ($r['status'] !== 400 || !in_array('gate_required', sb_codes($r), true)) {
            $bad[] = $t['label'] . ' was accepted: HTTP ' . $r['status'] . ' ' . json_encode(sb_codes($r));
        }
    }
    assert_clean($bad, 'a join path that grants default_account_level was missed');

    // **과잉 차단 방지.** 이 판정이 아무 때나 거절하면 정당한 번들이 막힌다
    $bad = array();
    foreach (array(
        // 두 가입 경로가 다 닫혀 있다 = 자동 부여 경로가 없다
        array('label' => 'no join path at all',
            'svc' => array('default_account_level' => 'vip', 'auto_join' => false, 'allow_register' => false),
            'sec' => array('min_account_level' => 'vip')),
        // default 가 게이트보다 낮다 = 무력화되지 않는다
        array('label' => 'default below the gate',
            'svc' => array('default_account_level' => 'basic'),
            'sec' => array('min_account_level' => 'vip')),
        array('label' => 'default one rank below the gate',
            'svc' => array('default_account_level' => 'verified'),
            'sec' => array('min_account_level' => 'vip')),
        // 다른 축에 실효 게이트가 있다 = 이 조합만으로는 열리지 않는다
        array('label' => 'a second real gate on billing_tier',
            'svc' => array('default_account_level' => 'vip'),
            'sec' => array('min_account_level' => 'vip', 'min_billing_tier' => 'lite')),
        array('label' => 'a second real gate on role',
            'svc' => array('default_account_level' => 'vip'),
            'sec' => array('min_account_level' => 'vip', 'min_role' => 'manager')),
        // default_account_level 이 아예 없다
        array('label' => 'no default_account_level',
            'svc' => array(),
            'sec' => array('min_account_level' => 'vip')),
        // 비민감 키는 게이트 자체를 요구받지 않는다 — 무력화되어도 오류가 아니다
        array('label' => 'a non-sensitive key with a defeated gate',
            'svc' => array('default_account_level' => 'vip'),
            'sec' => array('secret_key' => 'api_base', 'secret_value' => 'https://example.invalid/v1',
                'value_type' => 'url', 'min_account_level' => 'vip')),
    ) as $t) {
        $r = sb_call('dry-run', $mk($t['svc'], $t['sec']));
        if ($r['status'] !== 200) {
            $bad[] = $t['label'] . ' was rejected: HTTP ' . $r['status'] . ' ' . json_encode(sb_codes($r));
        }
    }
    assert_clean($bad, 'over-rejection by the defeat check');
});

t('settings_bundle: the defeat check reads the database, not just the bundle (H-2 / D-206)', function () {
    sb_accounts();
    live_reset_rate_limits();
    /*
     * **부분 갱신 번들이 판정을 빠져나가면 안 된다.** 실효값은 「번들이 명시하면 그 값,
     * 아니면 기존 행의 값」이다 — 게이트의 `$eff` 와 같은 규칙이다. 한쪽만 보면
     * 「먼저 서비스를 만들고, 다음 번들에 시크릿만 담는다」로 판정이 벗겨진다.
     */
    $sid = sb_sid('h2db');
    // 1) 게이트가 정상인 시크릿으로 서비스를 만든다. default_account_level 은 vip 다
    $a = sb_apply(sb_bundle(array(sb_svc($sid, array('default_account_level' => 'vip'), array(
        array('secret_key' => 'ai_api_key', 'secret_value' => 'sk-H2DB', 'min_billing_tier' => 'lite'))))));
    assert_eq(200, $a['status'], 'seed a service whose default_account_level is vip');

    // 2) 서비스 필드를 담지 않고 시크릿만 min_account_level=vip 로 바꾼다
    $only = sb_bundle(array(array(
        'service_id'   => $sid,
        'service_name' => 'SB ' . $sid,
        'secrets'      => array(array('secret_key' => 'ai_api_key', 'secret_value' => null,
            'min_account_level' => 'vip', 'min_billing_tier' => null)))));
    $r = sb_call('dry-run', $only);
    assert_eq(400, $r['status'],
        'the effective default_account_level lives in the database - a partial bundle must not escape: '
        . json_encode(sb_codes($r)));

    // 3) `on_conflict: skip` 도 판정을 조용히 없애지 못한다
    $skip = $only;
    $skip['bundle']['on_conflict'] = 'skip';
    $r = sb_call('dry-run', $skip);
    assert_eq(400, $r['status'],
        'on_conflict=skip must not silence the judgement - it would be a bypass: ' . json_encode(sb_codes($r)));

    /*
     * **`?action=validate` 는 반대 방향으로 틀린다.** DB 를 읽지 않으므로 이 경우를
     * 과소보고한다. 그 사실을 단언으로 못박는다 — 다음 라운드가 「validate 도 잡는다」로
     * 문서에 적지 않게 한다. 우회가 되지 않는 이유는 `apply` 가 트랜잭션 안에서
     * 계획을 다시 세우기 때문이다(위 2)의 dry-run 과 같은 판정을 받는다).
     */
    $v = sb_call('validate', $only);
    assert_eq(200, $v['status'],
        'validate is bundle-only by design and under-reports here; dry-run/apply is the enforcement point');
});

/** 관리자 콘솔의 시크릿 쓰기 경로. `secrets.php` 는 전역 `admin` 이면 된다(superadmin 이 아니다). */
function sb_secret_call(string $method, string $sid, ?array $body, ?int $id = null): array
{
    $S = sb_accounts();
    $path = '/server/api/admin/secrets.php?service_id=' . rawurlencode($sid)
        . ($id === null ? '' : ('&id=' . (string) $id));
    $opt = array('headers' => array('Authorization' => 'Bearer ' . $S['adminBearer']));
    if ($body !== null) {
        $opt['json'] = $body;
    }
    return live_req($method, $path, $opt);
}

t('secrets.php: the console write path applies the SAME H-2 judgement (D-206)', function () {
    sb_accounts();
    live_reset_rate_limits();
    /*
     * **H-2 의 피해는 그 행을 어떻게 만들었는지에 달려 있지 않다.** `min=vip` +
     * `default_account_level=vip` + 가입 경로가 열려 있으면 가입한 전원이 키를 받는다 —
     * 번들로 만들었든 콘솔로 만들었든 같다. 번들만 거절하면 **같은 제품의 두 부분이
     * 같은 DB 상태를 두고 서로 다른 말을 하고**, 엄격한 쪽이 AI 를 향하고 느슨한 쪽이
     * 사람을 향한다. 그 방향은 거꾸로다(리더 추가 지시).
     *
     * 선언 방법은 이 경로가 이미 갖고 있던 `allow_public: true` 한 곳이다 —
     * 새 파라미터도, 화면 변경도 없다. 번들이 두 곳을 요구하는 이유(AI 의 반사적 통과)가
     * 이 경로에는 없기 때문이다.
     */
    $sid = sb_sid('cons');
    $a = sb_apply(sb_bundle(array(sb_svc($sid, array('default_account_level' => 'vip'), array()))));
    assert_eq(200, $a['status'], 'seed a service whose default_account_level is vip');

    // 1) 거절 — 번들과 같은 조합, 같은 판정
    $r = sb_secret_call('POST', $sid, array(
        'secret_key' => 'ai_api_key', 'secret_value' => 'sk-CONSOLE',
        'min_account_level' => 'vip'));
    assert_eq(400, $r['status'],
        'the console must refuse the combination the bundle refuses: ' . substr($r['body'], 0, 240));
    $msg = isset($r['json']['error']['details']['message'])
        ? (string) $r['json']['error']['details']['message']
        : (string) (isset($r['json']['error']['message']) ? $r['json']['error']['message'] : $r['body']);
    assert_true(strpos($msg, 'default_account_level') !== false,
        'the console message must name the cause too - got ' . ut_show($msg));
    assert_true(strpos($msg, '낮추') === false && strpos($msg, '내리') === false,
        'and it must not send the operator off to rewrite the whole service grade policy');
    $pdo = live_ready_install();
    $st = $pdo->prepare('SELECT COUNT(*) FROM app_ulogin_service_secrets WHERE service_id = ? AND secret_key = ?');
    $st->execute(array($sid, 'ai_api_key'));
    assert_eq(0, (int) $st->fetchColumn(), 'a refused POST must not write the row');

    // 2) 같은 선언으로 통과 + 표시가 남는다
    $r = sb_secret_call('POST', $sid, array(
        'secret_key' => 'ai_api_key', 'secret_value' => 'sk-CONSOLE',
        'min_account_level' => 'vip', 'allow_public' => true));
    assert_true($r['status'] === 201 || $r['status'] === 200,
        'allow_public=true is this path\'s declaration: HTTP ' . $r['status'] . ' ' . substr($r['body'], 0, 200));
    $st = $pdo->prepare('SELECT id, allow_public FROM app_ulogin_service_secrets'
        . ' WHERE service_id = ? AND secret_key = ?');
    $st->execute(array($sid, 'ai_api_key'));
    $row = $st->fetch();
    assert_eq(1, (int) $row['allow_public'],
        'the acknowledged row must be marked, or the gate-check screen loses it');

    // 3) 과잉 차단 방지 — 진짜 게이트는 선언 없이 통과한다
    $r = sb_secret_call('POST', $sid, array(
        'secret_key' => 'other_api_key', 'secret_value' => 'sk-OK', 'min_billing_tier' => 'lite'));
    assert_true($r['status'] === 201 || $r['status'] === 200,
        'a real gate on an axis a service cannot grant needs no declaration: ' . substr($r['body'], 0, 200));

    // 4) PUT 이 게이트 필드를 건드리면 판정을 받는다
    $r = sb_secret_call('PUT', $sid, array('min_account_level' => 'vip'), (int) $row['id']);
    assert_eq(400, $r['status'],
        'a PUT that touches the gate fields is judged again - creating is not the only way in');
});

t('secrets.php: a row defeated LATER can still be edited, and services.php is the open door (D-206)', function () {
    sb_accounts();
    live_reset_rate_limits();
    /*
     * **요청 시점 검사로 닫을 수 없는 자리가 남는다.** 게이트가 정상인 시크릿을 먼저 만들고
     * **나중에** 서비스의 `default_account_level` 을 올리면, 그 조합을 만든 요청은
     * `services.php` 이고 그 요청은 시크릿을 보지 않는다.
     *
     * 그리고 그렇게 무력화된 기존 행은 **게이트 필드를 건드리지 않는 수정이 통과해야 한다** —
     * 막으면 운영자가 값을 고칠 수도, 게이트를 옮길 수도 없다(H-1 이 세운 구분).
     */
    $sid = sb_sid('late');
    $a = sb_apply(sb_bundle(array(sb_svc($sid, array(), array(
        array('secret_key' => 'ai_api_key', 'secret_value' => 'sk-LATE', 'min_account_level' => 'vip'))))));
    assert_eq(200, $a['status'], 'a vip gate with no default_account_level is a real gate');

    $pdo = live_ready_install();
    $st = $pdo->prepare('SELECT id FROM app_ulogin_service_secrets WHERE service_id = ? AND secret_key = ?');
    $st->execute(array($sid, 'ai_api_key'));
    $secId = (int) $st->fetchColumn();
    assert_true($secId > 0, 'the seeded secret exists');

    // **남아 있는 문**: services.php 가 시크릿을 보지 않고 조합을 만든다
    $S = sb_accounts();
    $r = live_req('PUT', '/server/api/admin/services.php?service_id=' . rawurlencode($sid), array(
        'headers' => array('Authorization' => 'Bearer ' . $S['adminBearer']),
        'json'    => array('default_account_level' => 'vip')));
    assert_eq(200, $r['status'],
        'services.php still accepts this - a request-time check on secrets cannot see it coming: '
        . substr($r['body'], 0, 200));

    // 게이트 필드를 건드리지 않는 수정은 통과한다
    $r = sb_secret_call('PUT', $sid, array('description' => 'edited after the service changed'), $secId);
    assert_eq(200, $r['status'],
        'an operator must still be able to edit a row that became defeated by someone else: '
        . substr($r['body'], 0, 200));

    // 게이트 필드를 건드리면 그 자리에서 판정을 받는다
    $r = sb_secret_call('PUT', $sid, array('min_role' => null), $secId);
    assert_eq(400, $r['status'],
        'touching a gate field re-judges with the service context: ' . substr($r['body'], 0, 200));

    // 그리고 번들 경로도 같은 말을 한다 — 두 부분이 같은 DB 상태를 두고 일치한다
    $d = sb_call('dry-run', sb_bundle(array(sb_svc($sid, array('default_account_level' => 'vip'), array(
        array('secret_key' => 'ai_api_key', 'secret_value' => null, 'min_account_level' => 'vip'))))));
    assert_eq(400, $d['status'], 'the bundle path agrees: ' . json_encode(sb_codes($d)));

    /*
     * ── 처방을 실제로 적용해 보고, **이 케이스가 남긴 상태를 스스로 되돌린다** ──
     *
     * 이 정리가 없으면 뒤에 오는 「a full export re-imported changes nothing」이 빨개진다 —
     * 이 케이스가 남긴 무력화된 행 때문에 그 설치본은 자기 export 를 다시 넣을 수 없다.
     * **그것은 테스트 오염이 아니라 실제 영향이다**(리포트 「기존 설치본 영향」).
     * 여기서는 그 사실을 확인한 뒤 처방(`min_billing_tier` 로 게이트를 옮긴다)이
     * 정말로 통하는지까지 잰다 — 처방이 통하지 않으면 위 거절은 막다른 길이다.
     */
    $r = sb_secret_call('PUT', $sid, array('min_billing_tier' => 'lite'), $secId);
    assert_eq(200, $r['status'],
        'the prescribed fix must be accepted, or the rejection is a dead end: ' . substr($r['body'], 0, 200));
    $st = $pdo->prepare('SELECT allow_public FROM app_ulogin_service_secrets WHERE id = ?');
    $st->execute(array($secId));
    assert_eq(0, (int) $st->fetchColumn(),
        'a real gate means no acknowledgement is on file - the gate-check screen must stop listing it');
    $back = sb_call('dry-run', array('bundle' => sb_call('', null)['json']['data']['bundle']));
    assert_eq(200, $back['status'],
        'and the installation can round-trip its own export again: ' . json_encode(sb_codes($back)));
});

t('settings_bundle: account_level is still the only axis a service field can grant (H-2 / D-206)', function () {
    sb_accounts();
    /*
     * 이 판정의 전제는 **「자동 부여 경로를 갖는 축은 account_level 하나」**다.
     * 그 전제가 스키마에서 무너지면(다음 라운드가 `default_role` 을 더하면)
     * `min_role` 을 권하는 오류 문구가 그 자리에서 함정이 된다. **전제를 못박는다.**
     */
    $pdo = live_ready_install();
    $rows = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS"
        . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'app_ulogin_services'"
        . " AND COLUMN_NAME LIKE 'default\\_%'")->fetchAll();
    $names = array();
    foreach ($rows as $r) {
        $names[] = (string) $r['COLUMN_NAME'];
    }
    sort($names);
    // 추출기를 먼저 검증한다(D-113) — 0건이면 아래 대조가 아무 근거가 아니다
    assert_true(count($names) >= 1,
        'the extractor found no default_% column at all - the assertion below would be vacuous');
    assert_eq(array('default_account_level'), $names,
        'a new default_% column means a new auto-grant axis: the gate_required message recommends'
        . ' min_billing_tier/min_role precisely because a service field cannot grant those');

    // `user_services` 의 role·billing_tier 오버라이드는 자동 경로에서 항상 NULL 이다(D-35).
    // 번들은 `user_services` 를 쓰지 않으므로 이 경로를 만들 수도 없다.
    $rows = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS"
        . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'app_ulogin_user_services'"
        . " AND COLUMN_NAME IN ('role', 'billing_tier')")->fetchAll();
    assert_eq(2, count($rows), 'the override columns must still exist - the D-35 claim is about their values');
});

t('settings_bundle: service_secret never appears in an error response (H-3 / D-203)', function () {
    sb_accounts();
    live_reset_rate_limits();
    /*
     * `sb_check_string()` 은 실패 시 원문 앞 200자를 `got` 에 담는다 — 오류 수리에는
     * 옳은 동작이다. **자격증명에는 옳지 않다.** `service_secret` 은 confidential
     * 클라이언트의 자격증명이고, 이 기능의 주 흐름이 「validate 실패 응답을 AI 대화창에
     * 붙여넣어 고치게 한다」이므로 그 유출은 예외가 아니라 설계된 경로였다.
     */
    $sid = sb_sid('h3');
    $short = 'topsecr';                                   // 8자 미만
    $long  = 'CLIENTSECRET-' . str_repeat('A', 300);       // 255자 초과

    $bad = array();
    $r = sb_call('validate', sb_bundle(array(array(
        'service_id' => $sid, 'service_name' => 'H3', 'service_secret' => $short))));
    if (strpos($r['body'], $short) !== false) {
        $bad[] = 'the too_short response contains the plaintext service_secret';
    }
    $e = sb_err_at($r, '/services/0/service_secret');
    if ($e === null || $e['code'] !== 'too_short') {
        $bad[] = 'expected a too_short error on /services/0/service_secret, got ' . ut_show($e);
    } elseif ($e['got'] !== '*** (7 chars)') {
        $bad[] = 'got must be masked but keep the length: ' . ut_show($e['got']);
    }

    $r = sb_call('validate', sb_bundle(array(array(
        'service_id' => $sid, 'service_name' => 'H3', 'service_secret' => $long))));
    if (strpos($r['body'], 'CLIENTSECRET-AAAA') !== false) {
        $bad[] = 'the too_long response contains a chunk of the plaintext service_secret';
    }
    $e = sb_err_at($r, '/services/0/service_secret');
    if ($e === null || $e['got'] !== '*** (313 chars)') {
        $bad[] = 'the too_long got must be "*** (313 chars)", got ' . ut_show($e === null ? null : $e['got']);
    }

    $r = sb_call('validate', sb_bundle(array(array(
        'service_id' => $sid, 'service_name' => 'H3', 'service_secret' => array('x' => 'seekrit')))));
    if (strpos($r['body'], 'seekrit') !== false) {
        $bad[] = 'the wrong_type response contains the nested plaintext';
    }
    $e = sb_err_at($r, '/services/0/service_secret');
    if ($e === null || $e['got_type'] !== 'object') {
        $bad[] = 'got_type must stay truthful for a wrong_type error, got ' . ut_show($e === null ? null : $e['got_type']);
    }
    assert_clean($bad, 'service_secret plaintext in validation errors');

    // **과잉 마스킹 방지.** 비밀이 아닌 문자열은 got 이 원문이어야 오류를 고칠 수 있다.
    $r = sb_call('validate', sb_bundle(array(array(
        'service_id' => $sid, 'service_name' => str_repeat('N', 120)))));
    $e = sb_err_at($r, '/services/0/service_name');
    assert_true($e !== null && strpos((string) $e['got'], 'NNNN') === 0,
        'service_name errors must keep the raw got so the sender can fix them: ' . ut_show($e === null ? null : $e['got']));
});

// =====================================================================
// F-e — 멱등 · 왕복. **Q-1 이 정확히 여기서 나왔다.**
// =====================================================================

t('settings_bundle: the same bundle applied three times stays a no-op (Q-1)', function () {
    sb_accounts();
    live_reset_rate_limits();
    /*
     * v1.6.0 QA Q-1. `secret_value: null` + `status: "active"` 는 가이드가 AI 에게
     * 시키는 모양이자 가이드 자신의 완전 예시다. 1회차는 `secret_forced_disabled` 로
     * disabled 를 강제했는데, **2회차가 경고 한 줄 없이 active 로 되돌렸다.**
     * 그 상태(값이 빈 active)는 `build_payload()` 가 조용히 건너뛰는 상태이고,
     * 코드 주석 자신이 금지한 상태다. 「한 번에 import 하고 사람이 값을 채운다」가
     * 이 기능의 정상 사용이므로 2회차는 예외 경로가 아니다.
     */
    $pdo = live_ready_install();
    $sid = sb_sid('q1');
    $req = sb_bundle(array(sb_svc($sid, array(), array(
        array('secret_key' => 'ai_api_key', 'secret_value' => null, 'value_type' => 'string',
              'min_billing_tier' => 'lite', 'status' => 'active'),
    ))));

    $ops = array();
    $sts = array();
    $wrn = array();
    for ($i = 1; $i <= 3; $i++) {
        $d = sb_call('dry-run', $req);
        if ($d['status'] !== 200) {
            fail('round ' . $i . ' dry-run returned HTTP ' . $d['status'] . ' ' . substr($d['body'], 0, 200));
        }
        $ops[] = (string) $d['json']['data']['plan']['services'][0]['secrets'][0]['op'];
        $wrn[] = in_array('secret_forced_disabled', sb_warncodes($d), true) ? 'warn' : 'silent';
        $ap = $req;
        $ap['expect_plan_hash'] = $d['json']['data']['plan_hash'];
        $a = sb_call('apply', $ap);
        if ($a['status'] !== 200) {
            fail('round ' . $i . ' apply returned HTTP ' . $a['status'] . ' ' . substr($a['body'], 0, 200));
        }
        $st = $pdo->prepare('SELECT status FROM app_ulogin_service_secrets WHERE service_id = ? AND secret_key = ?');
        $st->execute(array($sid, 'ai_api_key'));
        $sts[] = (string) $st->fetch()['status'];
    }

    assert_eq(array('disabled', 'disabled', 'disabled'), $sts,
        'a value-less secret must stay disabled on every application - "empty but active" is silently missing from payload');
    assert_eq(array('create', 'unchanged', 'unchanged'), $ops,
        'rounds 2 and 3 must report no change - the plan is what apply does');
    assert_eq(array('warn', 'warn', 'warn'), $wrn,
        'the secret_forced_disabled warning must not vanish on the second pass - a warning that disappears is worse than none');

    // 사람이 값을 채우면 그다음 같은 번들은 status 를 건드리지 않는다
    $sid2 = sb_sid('q1b');
    $a = sb_apply(sb_bundle(array(sb_svc($sid2, array(), array(
        array('secret_key' => 'ai_api_key', 'secret_value' => 'sk-FILLED',
              'min_billing_tier' => 'lite', 'status' => 'active'))))));
    assert_eq(200, $a['status'], 'a secret with a value applies');
    $a2 = sb_apply(sb_bundle(array(sb_svc($sid2, array(), array(
        array('secret_key' => 'ai_api_key', 'secret_value' => null,
              'min_billing_tier' => 'lite', 'status' => 'active'))))));
    assert_eq(200, $a2['status'], 'the same bundle with secret_value:null re-applies');
    $st = $pdo->prepare('SELECT status FROM app_ulogin_service_secrets WHERE service_id = ? AND secret_key = ?');
    $st->execute(array($sid2, 'ai_api_key'));
    assert_eq('active', (string) $st->fetch()['status'],
        'once a value exists the forcing must stop - otherwise filling a value in the console gets undone by the next import');
});

t('settings_bundle: a full export re-imported changes nothing (F-e round trip)', function () {
    sb_accounts();
    live_reset_rate_limits();
    $sid = sb_sid('rt');
    $a = sb_apply(sb_bundle(array(sb_svc($sid, array(
        'description'     => 'round trip',
        'is_confidential' => true,
        'service_secret'  => 'roundtrip-secret-1',
        'allowed_origins' => array('http://127.0.0.1'),
        'session_ttl'     => 1800,
    ), array(
        array('secret_key' => 'ai_api_key', 'secret_value' => 'sk-RT', 'min_billing_tier' => 'lite',
              'description' => 'the key', 'sort_order' => 3),
        array('secret_key' => 'api_base', 'secret_value' => 'https://example.invalid/v1', 'value_type' => 'url'),
    ))), array('settings' => array('signup_bonus_points' => 123))));
    assert_eq(200, $a['status'], 'setup apply: ' . substr($a['body'], 0, 200));

    $ex = sb_call('', null);
    assert_eq(200, $ex['status'], 'export');
    $round = array('bundle' => $ex['json']['data']['bundle']);

    $d = sb_call('dry-run', $round);
    assert_eq(200, $d['status'], 'the export must pass dry-run unchanged: codes=' . json_encode(sb_codes($d)));
    $c = $d['json']['data']['plan']['counts'];
    $changes = (int) $c['settings_update'] + (int) $c['services_create'] + (int) $c['services_update']
        + (int) $c['secrets_create'] + (int) $c['secrets_update'] + (int) $c['deletes'];
    assert_eq(0, $changes, 'export -> import must be a complete no-op: ' . json_encode($c));
    assert_true($d['json']['data']['plan']['deletes_possible'] === false,
        'deletes_possible is a promise, not a value - it must stay false');
    // environment 대조가 같은 설치본을 알아본다
    assert_true($d['json']['data']['plan']['environment_check']['compared'] === true,
        'the exported environment block must be compared, not ignored');
    assert_true($d['json']['data']['plan']['environment_check']['mismatches'] === array(),
        'the same installation must not report a mismatch: '
        . json_encode($d['json']['data']['plan']['environment_check']['mismatches']));

    $ap = $round;
    $ap['expect_plan_hash'] = $d['json']['data']['plan_hash'];
    $a2 = sb_call('apply', $ap);
    assert_eq(200, $a2['status'], 'apply the round trip');
    assert_eq(null, $a2['json']['data']['admin_log_id'],
        'a no-op apply must not write an audit row - otherwise the log fills with nothing');
});

// =====================================================================
// M-4 — 본문 상한. 헤더는 힌트이고 실제 길이가 검사다.
// =====================================================================

t('settings_bundle: the 1 MiB body limit holds without a Content-Length header (M-4 / D-204)', function () {
    $S = sb_accounts();
    live_reset_rate_limits();
    /*
     * 종전에는 `Content-Length` 헤더만 봤다. `Transfer-Encoding: chunked` 요청에는
     * 그 헤더가 없어 검사를 통째로 건너뛰었고, 1.2MB 본문이 `php -S` 와 실 Apache
     * mod_php 두 SAPI 모두에서 200 OK 로 파싱됐다. 지금은 **읽은 뒤에 잰다.**
     */
    $big = (string) json_encode(array(
        'bundle' => array('ulogin_settings_bundle' => '1.0'),
        'pad'    => str_repeat('x', 1200000),
    ));
    assert_true(strlen($big) > 1048576, 'the probe body must exceed SB_MAX_BODY');

    $r = sb_chunked('/server/api/admin/settings_bundle.php?action=validate', $big, $S['bearer']);
    assert_eq(400, $r['status'], 'a chunked over-size body must be refused');
    assert_true(in_array('body_too_large', sb_codes($r), true),
        'the code must be body_too_large - got ' . json_encode(sb_codes($r)));
    $e = sb_err_at($r, '/');
    assert_eq(strlen($big), (int) $e['got'], 'the error must report the byte count it actually measured');

    // 정직한 Content-Length 도 계속 막힌다 (조기 거절 경로)
    $r2 = sb_call('validate', json_decode($big, true));
    assert_eq(400, $r2['status'], 'an honest over-size Content-Length must still be refused');
    assert_true(in_array('body_too_large', sb_codes($r2), true), 'same code on the early path');

    // **과잉 차단 방지** — 정상 크기 chunked 요청은 그대로 통과해야 한다
    $ok = (string) json_encode(array('bundle' => array('ulogin_settings_bundle' => '1.0')));
    $r3 = sb_chunked('/server/api/admin/settings_bundle.php?action=validate', $ok, $S['bearer']);
    assert_eq(200, $r3['status'], 'a normal chunked request must still work: ' . substr($r3['body'], 0, 200));
    assert_true($r3['json']['data']['valid'] === true, 'and must validate');
});

// =====================================================================
// 배선 — 정의와 사용이 갈라지지 않는가
// =====================================================================

t('settings_bundle: the settings key table is the single definition (settingsdef.php)', function () {
    sb_accounts();
    live_reset_rate_limits();
    $defs = array();
    $src = (string) file_get_contents(h_root() . '/server/core/settingsdef.php');
    if (preg_match_all("~^\\s{8,}'([a-z0-9_]+)'\\s*=>\\s*array\\(|^\\s{8,}'([a-z0-9_]+)'\\s*=>\\s*\\[~m", $src, $m) > 0) {
        foreach ($m[1] as $i => $k) {
            $key = $k !== '' ? $k : $m[2][$i];
            if ($key !== '') {
                $defs[] = $key;
            }
        }
    }
    // 추출기를 먼저 검증한다 — 0건이나 헛것을 뽑으면 아래 단언이 아무 근거가 아니다
    assert_true(count($defs) >= 10,
        'the settings key extractor found only ' . count($defs) . ' keys - the extractor is broken, not the code');
    assert_true(in_array('signup_bonus_points', $defs, true) && in_array('auth_code_ttl', $defs, true),
        'the extractor must find known keys: ' . json_encode($defs));

    $ex = sb_call('', null);
    assert_eq(200, $ex['status'], 'export');
    $exported = array_keys($ex['json']['data']['bundle']['settings']);
    sort($defs);
    sort($exported);
    assert_eq($defs, $exported,
        'the exported settings keys must be exactly the definition table - a drift here is a silently ignored setting');
    assert_eq(count($defs), (int) $ex['json']['data']['meta']['settings_count'], 'meta.settings_count must match');

    // 정의에 없는 키는 조용히 무시되지 않고 거절된다
    $r = sb_call('validate', array('bundle' => array(
        'ulogin_settings_bundle' => '1.0',
        'settings' => array('sesion_ttl_default' => 900))));
    assert_eq(400, $r['status'], 'an unknown settings key must be refused, not ignored');
    $e = sb_err_at($r, '/settings/sesion_ttl_default');
    assert_true($e !== null && $e['code'] === 'unknown_key', 'code must be unknown_key: ' . ut_show($e));
    assert_true(isset($e['did_you_mean']) && $e['did_you_mean'] === 'session_ttl_default',
        'a near miss must suggest the real key: ' . ut_show($e === null ? null : ($e['did_you_mean'] ?? null)));
});
