<?php
declare(strict_types=1);

/**
 * tests/lib/proc.php — 자식 프로세스 **강제 시간 제한** (리더 판정 D-339)
 *
 * 왜: v1.8.4 전체 실행에서 `t_61` 경합 자식 하나가 멈춰 러너가 **수 시간** 기다렸다
 * (`_workspace/101_backend_v184_report.md` §4). `proc_close()` 는 자식이 끝날 때까지 무한히 막힌다.
 * 멈춘 자식 하나가 배포 파이프라인 전체를 세운다 — 시험이 「실패」가 아니라 「끝나지 않음」이 된다.
 *
 * 규칙:
 *  · 기다림은 전부 `proc_wait_or_kill()` 을 지난다. 기한이 지나면 **그 자식의 PID 트리만**
 *    `taskkill /F /T /PID <자기 PID>` 로 죽이고 「시간 초과」를 돌려준다. 호출자는 그것을 FAIL 로 친다
 *  · PID 는 `proc_get_status()` 가 준 **우리가 띄운 프로세스**의 것이다. 이름·포트로 찾아 죽이지 않는다
 *    (사용자의 3306·운영 8080·지킴이를 건드리지 않는다 — CLAUDE.md 최우선 규칙 1(b))
 *  · `proc_open()` 은 Windows 에서 `cmd.exe /c …` 로 감싼다. `/T` 가 그 아래 php.exe 까지 데려간다.
 *    `proc_terminate()` 는 래퍼만 죽여 좀비가 남는다 — 쓰지 않는다
 *
 * 기한: 환경변수 `ULOGIN_TEST_CHILD_TIMEOUT`(초)로 바꿀 수 있다. 기본 180초 —
 * 정상 경합 라운드(N=32)는 수 초다. 넉넉하게 잡아 느린 PC 에서 거짓 FAIL 을 내지 않는다.
 */

const PROC_DEFAULT_TIMEOUT = 180;

/** 이번 실행의 자식 기한(초). */
function proc_child_timeout(): float
{
    $v = getenv('ULOGIN_TEST_CHILD_TIMEOUT');
    if (is_string($v) && is_numeric($v) && (float) $v > 0) {
        return (float) $v;
    }
    return (float) PROC_DEFAULT_TIMEOUT;
}

/** 지금부터 기한까지의 절대 시각. */
function proc_deadline(?float $sec = null): float
{
    return microtime(true) + ($sec === null ? proc_child_timeout() : $sec);
}

/** PID 하나의 트리를 죽인다 — **우리가 띄운 PID 만** 넘긴다. */
function proc_kill_tree(int $pid): void
{
    if ($pid <= 0) {
        return;
    }
    if (DIRECTORY_SEPARATOR === '\\') {
        $o = array();
        $rc = 0;
        @exec('taskkill /F /T /PID ' . $pid . ' 2>&1', $o, $rc);
        return;
    }
    if (function_exists('posix_kill')) {
        @posix_kill($pid, 9);
    }
}

/** 그 PID 가 아직 살아 있는가(Windows: tasklist). 시험이 「정말 죽였는가」를 볼 때 쓴다. */
function proc_pid_alive(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }
    if (DIRECTORY_SEPARATOR === '\\') {
        $o = array();
        $rc = 0;
        @exec('tasklist /FI "PID eq ' . $pid . '" /FO CSV /NH 2>&1', $o, $rc);
        foreach ($o as $line) {
            if (strpos($line, '"' . $pid . '"') !== false) {
                return true;
            }
        }
        return false;
    }
    return function_exists('posix_kill') ? @posix_kill($pid, 0) : false;
}

/**
 * 자식이 끝날 때까지 기다리되 기한을 넘기면 PID 트리를 죽인다.
 * @param resource $proc
 * @return array{timed_out:bool,exit:int,pid:int,waited:float}
 */
function proc_wait_or_kill($proc, float $deadline): array
{
    $t0  = microtime(true);
    $pid = 0;
    $exit = -1;
    if (!is_resource($proc)) {
        return array('timed_out' => false, 'exit' => -1, 'pid' => 0, 'waited' => 0.0);
    }
    $sleep = 2000;
    while (true) {
        $st = @proc_get_status($proc);
        if (!is_array($st)) {
            break;
        }
        $pid = (int) $st['pid'];
        if (!$st['running']) {
            $exit = (int) $st['exitcode'];
            @proc_close($proc);
            return array('timed_out' => false, 'exit' => $exit, 'pid' => $pid, 'waited' => microtime(true) - $t0);
        }
        if (microtime(true) >= $deadline) {
            proc_kill_tree($pid);
            // 트리를 죽였으니 proc_close 는 곧 돌아온다. 그래도 무한히 기다리지 않게 잠깐만 본다.
            for ($k = 0; $k < 50; $k++) {
                $st2 = @proc_get_status($proc);
                if (!is_array($st2) || !$st2['running']) {
                    break;
                }
                usleep(100000);
            }
            @proc_close($proc);
            return array('timed_out' => true, 'exit' => -1, 'pid' => $pid, 'waited' => microtime(true) - $t0);
        }
        usleep($sleep);
        if ($sleep < 50000) {
            $sleep *= 2;
        }
    }
    @proc_close($proc);
    return array('timed_out' => false, 'exit' => $exit, 'pid' => $pid, 'waited' => microtime(true) - $t0);
}

/**
 * 외부 명령 실행 + 기한. stdout·stderr 를 임시 파일로 받는다(파이프는 가득 차면 서로 막힌다).
 * @return array{out:string,exit:int,timed_out:bool,pid:int}
 */
function proc_run_limited(string $cmd, ?float $timeoutSec = null, ?string $cwd = null): array
{
    $tmp = sys_get_temp_dir() . '/ulogin_run_' . getmypid() . '_' . bin2hex(random_bytes(5)) . '.txt';
    $desc = array(0 => array('pipe', 'r'), 1 => array('file', $tmp, 'w'), 2 => array('file', $tmp, 'a'));
    $pp = array();
    $p = @proc_open($cmd, $desc, $pp, $cwd);
    if (!is_resource($p)) {
        @unlink($tmp);
        return array('out' => 'proc_open failed: ' . $cmd, 'exit' => -1, 'timed_out' => false, 'pid' => 0);
    }
    if (isset($pp[0]) && is_resource($pp[0])) {
        fclose($pp[0]);
    }
    $w = proc_wait_or_kill($p, proc_deadline($timeoutSec));
    $out = is_file($tmp) ? (string) @file_get_contents($tmp) : '';
    @unlink($tmp);
    if ($w['timed_out']) {
        $out .= "\n[tests/lib/proc.php] TIMEOUT after " . sprintf('%.1f', $w['waited']) . 's — killed own PID tree ' . $w['pid'];
    }
    return array('out' => rtrim($out, "\r\n"), 'exit' => $w['timed_out'] ? 124 : $w['exit'], 'timed_out' => $w['timed_out'], 'pid' => $w['pid']);
}
