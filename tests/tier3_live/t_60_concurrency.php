<?php
declare(strict_types=1);

/**
 * 티어 3 — **동시성**
 *
 * v1.4.0 한 라운드에서 동시성 결함이 **두 번** 났고, 두 번 다 **순차 시험으로는
 * 안 잡혔다.** 하나는 레이트리밋 수정이 만든 "신호 소실 17~18/20" — 20건을 동시에
 * 보냈는데 그중 17~18건이 **429 도 아니고 정상 응답도 아닌** 상태가 됐다.
 * 순차로 돌리면 항상 통과한다.
 *
 * 그래서 여기서는 **자식 PHP 프로세스로 실제 동시 요청**을 보낸다.
 * `curl_multi` 를 쓰지 않는다 — 이 저장소의 규약은 "PHP CLI 하나뿐" 이다.
 *
 * ─── 못 잡는 것 ───────────────────────────────────────────────────────
 *  · `php -S` 는 요청을 **직렬로** 처리한다(단일 프로세스). 즉 여기서 재현되는
 *    것은 "같은 순간에 도착한 여러 요청" 이지 "DB 트랜잭션이 실제로 겹치는 상황"
 *    이 아니다. **진짜 경합은 mod_php/FPM 배포에서만 난다.**
 *    그럼에도 값이 있는 이유: 카운터 증가·멱등키·락아웃은 **요청 사이의 상태
 *    누적**이 문제이고, 그것은 직렬 처리에서도 드러난다. 실제로 "신호 소실"은
 *    응답 자체가 계약을 벗어난 형태라 여기서 잡힌다
 *  · 진짜 병렬 배포에서의 데드락·경합. **미검증으로 남는다**
 */

require_once __DIR__ . '/../lib/live.php';

$GLOBALS['CONC'] = array('user' => null, 'pw' => 'Passw0rd!conc', 'bearer' => null);

/** 동시성 케이스가 쓸 계정을 하나 만든다. */
function conc_account(): array
{
    $C = &$GLOBALS['CONC'];
    if ($C['user'] !== null) {
        return $C;
    }
    live_ready_install();
    live_server();
    // 앞 파일(회귀 종주)이 남긴 IP 카운터를 비운다. 안 비우면 이 계정의
    // **회원가입부터** 429 가 나고, 실패 원인이 엉뚱한 곳을 가리킨다.
    live_reset_rate_limits();
    $u = 'conc_' . substr(bin2hex(random_bytes(4)), 0, 8);
    $r = live_req('POST', '/server/api/auth/register.php', array('json' => array(
        'username'         => $u,
        'email'            => $u . '@test.invalid',
        'password'         => $C['pw'],
        'password_confirm' => $C['pw'],
        'agree_tos'        => true,
        'agree_privacy'    => true,
    )));
    if ($r['status'] !== 201 && $r['status'] !== 200) {
        fail('could not create the concurrency test account: HTTP ' . $r['status'] . ' ' . substr($r['body'], 0, 200));
    }
    $C['user'] = $u;
    $l = live_req('POST', '/server/api/auth/login.php', array('json' => array('username' => $u, 'password' => $C['pw'])));
    if ($l['status'] !== 200) {
        fail('could not log the concurrency test account in: HTTP ' . $l['status']);
    }
    $C['bearer'] = $l['json']['data']['access_token'];
    return $C;
}

t('20 simultaneous bad logins all produce a contract-shaped answer (no signal loss)', function () {
    $C = conc_account();
    // 20건을 동시에. 이 계정은 방금 만들어졌으므로 카운터가 깨끗하다.
    $res = live_parallel(20, 'POST', '/server/api/auth/login.php', array('json' => array(
        'username' => $C['user'],
        'password' => 'definitely-not-the-password',
    )));

    if (count($res) !== 20) {
        fail('only ' . count($res) . ' of 20 workers reported back - the parallel harness itself is broken');
    }

    /*
     * **여기가 "신호 소실" 을 잡는 자리다.**
     * 모든 응답이 셋 중 하나여야 한다:
     *   401 AUTH_INVALID_CREDENTIALS · 401/403 AUTH_ACCOUNT_LOCKED · 429 RATE_LIMITED
     * 그 밖의 것(빈 응답 · 500 · HTML · 200)은 전부 소실이다.
     */
    $allowed = array('AUTH_INVALID_CREDENTIALS', 'AUTH_ACCOUNT_LOCKED', 'RATE_LIMITED');
    $lost = array();
    $tally = array();
    foreach ($res as $i => $r) {
        $j = json_decode((string) $r['body'], true);
        $code = (is_array($j) && isset($j['error']['code'])) ? $j['error']['code'] : null;
        if ($r['status'] === 0 || $r['body'] === '') {
            $lost[] = '#' . $i . ': empty response (HTTP ' . $r['status'] . ')';
            continue;
        }
        if (!is_array($j)) {
            $lost[] = '#' . $i . ': non-JSON body "' . substr(str_replace("\n", ' ', $r['body']), 0, 80) . '"';
            continue;
        }
        if ($code === null || !in_array($code, $allowed, true)) {
            $lost[] = '#' . $i . ': HTTP ' . $r['status'] . ' code=' . ut_show($code)
                . ' (expected one of ' . implode('/', $allowed) . ')';
            continue;
        }
        if (!isset($tally[$code])) {
            $tally[$code] = 0;
        }
        $tally[$code]++;
    }
    assert_clean($lost, 'signal loss under 20 concurrent bad logins');

    // 429 에는 Retry-After 가 함께 나가야 한다 (계약 §8-3) — 본문 쪽 retry_after 로 확인한다
    $seen429 = false;
    foreach ($res as $r) {
        $j = json_decode((string) $r['body'], true);
        if (is_array($j) && isset($j['error']['code']) && $j['error']['code'] === 'RATE_LIMITED') {
            $seen429 = true;
            if (!isset($j['data']['retry_after'])) {
                fail('a RATE_LIMITED response carried no data.retry_after - the screen cannot tell the user when to retry');
            }
        }
    }
    note('20 concurrent bad logins: ' . json_encode($tally)
        . ($seen429 ? '' : ' (the rate limit did not trip at 20 attempts - check the thresholds if that is unexpected)'));
});

t('the lockout / rate limit actually stops the attack rather than just labelling it', function () {
    // 위 케이스가 "형태" 를 봤다면 이쪽은 "효과" 를 본다.
    // 20번 틀린 뒤에는 **올바른 비밀번호로도** 통과하면 안 된다 (락아웃 5회).
    $C = conc_account();
    $r = live_req('POST', '/server/api/auth/login.php', array('json' => array(
        'username' => $C['user'],
        'password' => $C['pw'],   // 진짜 비밀번호
    )));
    $code = isset($r['json']['error']['code']) ? $r['json']['error']['code'] : null;
    if ($r['status'] === 200) {
        fail('after 20 failed attempts the correct password still logged in - the lockout counter is not being persisted');
    }
    if (!in_array($code, array('AUTH_ACCOUNT_LOCKED', 'RATE_LIMITED'), true)) {
        fail('after 20 failed attempts the answer was ' . ut_show($code) . ' (HTTP ' . $r['status']
            . '), expected AUTH_ACCOUNT_LOCKED or RATE_LIMITED');
    }
});

t('a repeated idempotency_key never deducts points twice, even sent simultaneously', function () {
    live_ready_install();
    live_server();
    // **앞의 20건 폭주가 이 IP 의 카운터를 태웠다.** 비우지 않으면 회원가입이 429 가 되고
    // 이 케이스는 포인트와 무관한 이유로 실패한다. 레이트리밋 자체의 동작은
    // 위 두 케이스가 **비우기 전에** 이미 확인했다.
    live_reset_rate_limits();
    // 락아웃된 계정과 분리한다 — 이 케이스는 정상 계정이 필요하다.
    $u = 'idem_' . substr(bin2hex(random_bytes(4)), 0, 8);
    $pw = 'Passw0rd!idem';
    $reg = live_req('POST', '/server/api/auth/register.php', array('json' => array(
        'username' => $u, 'email' => $u . '@test.invalid',
        'password' => $pw, 'password_confirm' => $pw,
        'agree_tos' => true, 'agree_privacy' => true,
    )));
    if ($reg['status'] === 429) {
        // SKIP 으로 접으면 "레이트리밋 때문에 안 돌았다" 가 "통과" 로 보인다.
        fail('registration was rate limited even after resetting the counters - the reset is not working');
    }
    if ($reg['status'] !== 201 && $reg['status'] !== 200) {
        skip('registration is closed or failed in this configuration (HTTP ' . $reg['status'] . ')');
    }
    $login = live_req('POST', '/server/api/auth/login.php', array('json' => array('username' => $u, 'password' => $pw)));
    assert_eq(200, $login['status']);
    $bearer = $login['json']['data']['access_token'];

    $before = live_req('GET', '/server/api/me/index.php', array('headers' => array('Authorization' => 'Bearer ' . $bearer)));
    assert_eq(200, $before['status']);
    $b0 = (int) $before['json']['data']['user']['points'];
    if ($b0 < 10) {
        skip('the signup bonus is ' . $b0 . ' points - not enough to test a deduction');
    }

    $key = 'idem-' . bin2hex(random_bytes(8));
    $amount = 7;
    $res = live_parallel(8, 'POST', '/server/api/points/consume.php', array(
        'headers' => array('Authorization' => 'Bearer ' . $bearer),
        'json'    => array('amount' => $amount, 'reason' => 'test', 'idempotency_key' => $key),
    ));

    $ok = 0;
    $replay = 0;
    $bad = array();
    foreach ($res as $i => $r) {
        $j = json_decode((string) $r['body'], true);
        if (!is_array($j)) {
            $bad[] = '#' . $i . ': non-JSON body "' . substr(str_replace("\n", ' ', (string) $r['body']), 0, 80) . '"';
            continue;
        }
        if ($r['status'] === 200 && !empty($j['ok'])) {
            $ok++;
            if (!empty($j['data']['idempotent_replay'])) {
                $replay++;
            }
            continue;
        }
        // 동시 요청 중 하나가 CONFLICT 로 떨어지는 것은 허용한다 —
        // 조용히 두 번 차감하는 것보다 낫다. 다만 5xx 는 안 된다.
        $code = isset($j['error']['code']) ? $j['error']['code'] : null;
        if ($r['status'] >= 500) {
            $bad[] = '#' . $i . ': HTTP ' . $r['status'] . ' ' . ut_show($code) . ' - a 5xx under contention';
            continue;
        }
        if (!in_array($code, array('CONFLICT', 'POINT_INSUFFICIENT', 'RATE_LIMITED'), true)) {
            $bad[] = '#' . $i . ': HTTP ' . $r['status'] . ' ' . ut_show($code);
        }
    }
    assert_clean($bad, 'malformed answers under 8 concurrent identical consumes');

    // **핵심 단언: 잔액이 정확히 한 번만 줄었다.**
    $after = live_req('GET', '/server/api/me/index.php', array('headers' => array('Authorization' => 'Bearer ' . $bearer)));
    assert_eq(200, $after['status']);
    $b1 = (int) $after['json']['data']['user']['points'];
    assert_eq($b0 - $amount, $b1,
        'the balance must drop by exactly one deduction (' . $amount . ') no matter how many identical requests arrive');

    // 그리고 로그도 한 행이어야 한다. 잔액만 맞고 로그가 여러 행이면 감사가 거짓말을 한다.
    $pdo = live_ready_install();
    $st = $pdo->prepare('SELECT COUNT(*) c FROM app_ulogin_point_logs WHERE idempotency_key = ?');
    $st->execute(array($key));
    assert_eq(1, (int) $st->fetch()['c'], 'exactly one point_logs row must exist for one idempotency key');

    note('idempotency under contention: ' . $ok . '/8 answered 200 (' . $replay
        . ' flagged idempotent_replay), balance moved exactly once');
});

t('two different idempotency keys DO deduct twice (the guard is not over-broad)', function () {
    // 반대 방향 확인. 멱등 보호가 과하면 정상적인 두 번째 차감이 조용히 무시되고,
    // 그것은 "서비스는 제공했는데 포인트는 안 빠지는" 상태가 된다.
    live_ready_install();
    live_server();
    live_reset_rate_limits();
    $u = 'idem2_' . substr(bin2hex(random_bytes(4)), 0, 8);
    $pw = 'Passw0rd!idem2';
    $reg = live_req('POST', '/server/api/auth/register.php', array('json' => array(
        'username' => $u, 'email' => $u . '@test.invalid',
        'password' => $pw, 'password_confirm' => $pw,
        'agree_tos' => true, 'agree_privacy' => true,
    )));
    if ($reg['status'] === 429) {
        fail('registration was rate limited even after resetting the counters - the reset is not working');
    }
    if ($reg['status'] !== 201 && $reg['status'] !== 200) {
        skip('registration failed (HTTP ' . $reg['status'] . ')');
    }
    $login = live_req('POST', '/server/api/auth/login.php', array('json' => array('username' => $u, 'password' => $pw)));
    $bearer = $login['json']['data']['access_token'];
    $hdr = array('Authorization' => 'Bearer ' . $bearer);

    $b0 = (int) live_req('GET', '/server/api/me/index.php', array('headers' => $hdr))['json']['data']['user']['points'];
    if ($b0 < 20) {
        skip('signup bonus too small (' . $b0 . ')');
    }
    foreach (array('k-' . bin2hex(random_bytes(6)), 'k-' . bin2hex(random_bytes(6))) as $k) {
        $r = live_req('POST', '/server/api/points/consume.php', array(
            'headers' => $hdr,
            'json'    => array('amount' => 5, 'reason' => 'test', 'idempotency_key' => $k),
        ));
        assert_eq(200, $r['status'], 'consume with key ' . $k . ': ' . substr($r['body'], 0, 200));
        assert_true(empty($r['json']['data']['idempotent_replay']), 'a fresh key must not be flagged as a replay');
    }
    $b1 = (int) live_req('GET', '/server/api/me/index.php', array('headers' => $hdr))['json']['data']['user']['points'];
    assert_eq($b0 - 10, $b1, 'two distinct keys must deduct twice');
});
