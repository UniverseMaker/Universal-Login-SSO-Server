<?php
declare(strict_types=1);

/**
 * 티어 2 — 자식 프로세스 강제 시간 제한 (리더 판정 D-339) · tests/lib/proc.php
 *
 * 지난 판(v1.8.4)에 경합 자식 하나가 멈춰 러너가 수 시간 기다렸다. 이 파일은
 *  ① 멈춘 자식을 **실제로 심어**(무한 sleep) 기한 뒤 FAIL 판정 + 자기 PID 트리 종료를 확인하고
 *  ② 정상 자식은 그대로 끝나는지(거짓 시간 초과 없음) 확인하고
 *  ③ tier3 의 병렬 자식 기다림 3곳(race_spawn · alt_spawn · live_parallel)이 맨 proc_close() 로
 *     돌아가지 않았는지 정적으로 고정한다 — 검사기 자체도 변이(맨 proc_close 를 심은 사본)로 검증한다.
 * 실제 경합 하네스(race_spawn)에 멈춘 자식을 넣는 종단 시험은 tier3 `t_61` 마지막 케이스다.
 *
 * 무접촉: 띄우는 것은 이 러너의 PHP 자식뿐이고, 죽이는 PID 는 proc_get_status() 가 준 그 자식의 것뿐이다.
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/assert.php';

function ct_script(string $body): string
{
    $f = h_norm(sys_get_temp_dir()) . '/ulogin_test_ct_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($f, "<?php\n" . $body);
    $GLOBALS['CT_FILES'][] = $f;
    return $f;
}
$GLOBALS['CT_FILES'] = array();
register_shutdown_function(function () {
    foreach ($GLOBALS['CT_FILES'] as $f) {
        @unlink($f);
        @unlink($f . '.pid');
    }
});

/** 함수 정의부터 다음 최상위 정의(줄머리 `function ` · `t(`) 앞까지. 없으면 null. */
function ct_fn_body(string $src, string $fn): ?string
{
    $src = str_replace("\r\n", "\n", $src);
    $at = strpos($src, "\nfunction " . $fn . '(');
    if ($at === false) {
        return null;
    }
    $rest = substr($src, $at + 1);
    if (preg_match('/\n(?:function |t\()/', $rest, $m, PREG_OFFSET_CAPTURE, 1) === 1) {
        $rest = substr($rest, 0, (int) $m[0][1]);
    }
    return $rest;
}

/** 기다림 함수가 기한을 지나는가: proc_wait_or_kill 있음 + 맨 proc_close( 없음. 문제 목록. */
function ct_wait_problems(string $src, string $fn): array
{
    $b = ct_fn_body($src, $fn);
    if ($b === null) {
        return array($fn . ': function not found (checker blind)');
    }
    $p = array();
    if (strpos($b, 'proc_wait_or_kill(') === false) {
        $p[] = $fn . ': does not wait through proc_wait_or_kill()';
    }
    if (preg_match('/(?<![a-z_])proc_close\(/', $b) === 1) {
        $p[] = $fn . ': calls bare proc_close() — an unbounded wait';
    }
    return $p;
}

t('D-339 a hung child is killed after the deadline and reported as a timeout (exit 124), not waited on', function () {
    $f = ct_script('file_put_contents(__FILE__ . ".pid", (string) getmypid()); while (true) { sleep(1); }');
    $t0 = microtime(true);
    $r = proc_run_limited(h_q(h_php_bin()) . ' -n ' . h_q($f), 2.0);
    $took = microtime(true) - $t0;
    assert_true($r['timed_out'], 'timed_out flag');
    assert_eq(124, $r['exit'], 'timeout exit code');
    assert_contains('TIMEOUT', $r['out'], 'output says TIMEOUT');
    assert_true($took < 15.0, 'returned within deadline + grace (took ' . sprintf('%.1f', $took) . 's)');
    $pid = is_file($f . '.pid') ? (int) file_get_contents($f . '.pid') : 0;
    assert_true($pid > 0, 'the planted child actually started (wrote its PID)');
    usleep(300000);
    assert_true(!proc_pid_alive($pid), 'child php pid ' . $pid . ' is dead (PID tree kill, not just the cmd.exe wrapper)');
});

t('D-339 a normal child finishes with its own exit code and output (no false timeout)', function () {
    $f = ct_script('echo "hello"; exit(7);');
    $r = proc_run_limited(h_q(h_php_bin()) . ' -n ' . h_q($f), 30.0);
    assert_true(!$r['timed_out'], 'not timed out');
    assert_eq(7, $r['exit'], 'exit code passes through');
    assert_eq('hello', $r['out'], 'stdout captured');
});

t('D-339 h_run() is bounded too: a hung command returns code 124 instead of blocking the runner', function () {
    $f = ct_script('while (true) { sleep(1); }');
    $code = 0;
    $t0 = microtime(true);
    $out = h_run(h_q(h_php_bin()) . ' -n ' . h_q($f), $code, 2.0);
    assert_eq(124, $code, 'h_run timeout code');
    assert_contains('TIMEOUT', $out, 'h_run output');
    assert_true(microtime(true) - $t0 < 15.0, 'h_run gave up in time');
    $code2 = 0;
    $f2 = ct_script('echo "a  " . PHP_EOL . "b";');
    $out2 = h_run(h_q(h_php_bin()) . ' -n ' . h_q($f2), $code2);
    assert_eq(0, $code2, 'h_run normal code');
    assert_eq("a\nb", $out2, 'h_run keeps the old exec() shape (per-line rtrim, LF)');
});

t('D-339 every tier3 parallel-child wait goes through the deadline (race_spawn · alt_spawn · live_parallel)', function () {
    $sites = array(
        array('tests/tier3_live/t_61_code_race.php', 'race_spawn'),
        array('tests/tier3_live/t_80_account_link.php', 'alt_spawn'),
        array('tests/lib/live.php', 'live_parallel'),
    );
    $bad = array();
    foreach ($sites as $s) {
        $src = h_read(h_root() . '/' . $s[0]);
        $bad = array_merge($bad, ct_wait_problems($src, $s[1]));
    }
    assert_eq(array(), $bad, 'unbounded waits');
    // 검사기 변이 — 맨 proc_close 를 되살린 사본을 반드시 잡아야 한다
    $src = h_read(h_root() . '/tests/tier3_live/t_61_code_race.php');
    $mut = str_replace('$w = proc_wait_or_kill($pr[0], $deadline);', 'proc_close($pr[0]); $w = array(\'timed_out\' => false, \'pid\' => 0);', $src, $n);
    assert_eq(1, $n, 'mutation planted exactly once');
    assert_true(count(ct_wait_problems($mut, 'race_spawn')) === 2, 'checker catches a bare proc_close() + missing deadline wait');
});
