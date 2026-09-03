<?php
declare(strict_types=1);

/**
 * tests/run.php — 회귀 테스트 러너
 *
 * PHP 7.2 호환 · Composer 없음 · 확장 모듈 없음(티어 3 의 `pdo_mysql` 제외) · 빌드 없음.
 * `tools/` 의 규약을 그대로 확장한다 — CLI 전용, `glob()` 금지, **조치가 다르면 종료코드를 나눈다.**
 *
 *   php tests/run.php                       전부 (자기검증 → 티어 1 → 2 → 3)
 *   php tests/run.php --tier=1               티어 1 만
 *   php tests/run.php --tier=1,2 --verbose
 *   php tests/run.php --filter=cookie        이름·파일명에 'cookie' 가 든 케이스만
 *   php tests/run.php --list                 무엇이 돌지 목록만
 *   php tests/run.php --notes-are-errors     NOTE 도 종료코드에 반영 (tools/php72_scan.php 와 같은 규율)
 *   php tests/run.php --no-selfcheck         자기검증 생략 (디버깅 전용. CI 에서 쓰지 마라)
 *
 * ─── 종료코드 ─────────────────────────────────────────────────────────
 *   0  전부 통과. **그리고 실제로 1건 이상 실행됐다**
 *   1  실패 있음                        → 산출물(코드·문서)을 고쳐라
 *   2  사용법 오류 / 대상 없음           → 명령·경로를 고쳐라
 *   3  **한 건도 실행되지 않았다**(전부 SKIP) → 환경을 고쳐라 (티어 3 DB 미설정이 대표)
 *   4  **러너 자신이 고장이다** → 러너를 고쳐라
 *      양성 픽스처가 통과했다(검사기가 눈이 멀었다) 또는
 *      음성 대조군이 발화했다(과검출) 또는 자기검증이 0건 실행됐다
 *
 * `3` 과 `4` 를 `0` 에서 분리한 것이 이 설계의 요점이다.
 * 이 저장소에서 검사기 거짓 결과가 21번 났고 그중 여럿이 **「0건」을 「결함 없음」으로**
 * 읽히게 했다. "통과"와 "아무것도 안 돌았다"가 같은 코드를 쓰면 회귀 게이트는
 * **조용히 항상 통과한다.**
 * ─────────────────────────────────────────────────────────────────────
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('UTC');

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/harness.php';

/*
 * ---------------------------------------------------------------------
 * `error_log()` 를 임시 파일로 돌린다.
 *
 * 대상 코드는 fail-closed 경로마다 `error_log()` 로 한 줄씩 남긴다 — 그것이 설계다
 * (D-53 · D-67 · D-75). 그런데 CLI 에서 `error_log()` 의 기본 목적지는 **stderr** 라,
 * 잘못된 입력을 일부러 먹이는 테스트가 돌면 수십 줄이 결과와 섞여 **진짜 실패가
 * 스크롤 밖으로 밀려난다.**
 *
 * 끄지 않고 **모아서 센다.** 0 으로 만들면 "로그를 남기는가" 라는 계약을 확인할
 * 길이 사라진다. 파일은 종료 시 반드시 지운다 — 실패해도, 예외로 죽어도.
 * ---------------------------------------------------------------------
 */
$ULOGIN_ERRLOG = sys_get_temp_dir() . '/ulogin_tests_errlog_' . getmypid() . '.log';
ini_set('log_errors', '1');
ini_set('error_log', $ULOGIN_ERRLOG);
register_shutdown_function(function () use ($ULOGIN_ERRLOG) {
    if (is_file($ULOGIN_ERRLOG)) {
        @unlink($ULOGIN_ERRLOG);
    }
});

const EXIT_OK      = 0;
const EXIT_FAIL    = 1;
const EXIT_USAGE   = 2;
const EXIT_NOTHING = 3;
const EXIT_BROKEN  = 4;

$TIER_DIRS = array(
    1 => 'tier1_static',
    2 => 'tier2_unit',
    3 => 'tier3_live',
);

// ---------------------------------------------------------------------
// 인자
// ---------------------------------------------------------------------
$tiers      = array(1, 2, 3);
$filter     = '';
$listOnly   = false;
$verbose    = false;
$selfcheck  = true;
$notesErr   = false;

for ($i = 1; $i < $argc; $i++) {
    $a = $argv[$i];
    if ($a === '--list') {
        $listOnly = true;
    } elseif ($a === '--verbose' || $a === '-v') {
        $verbose = true;
    } elseif ($a === '--no-selfcheck') {
        $selfcheck = false;
    } elseif ($a === '--notes-are-errors') {
        $notesErr = true;
    } elseif (strncmp($a, '--tier=', 7) === 0) {
        $tiers = array();
        foreach (explode(',', substr($a, 7)) as $t) {
            $t = (int) trim($t);
            if (!isset($TIER_DIRS[$t])) {
                fwrite(STDERR, "run.php: unknown tier '" . $t . "'. valid: 1, 2, 3\n");
                exit(EXIT_USAGE);
            }
            $tiers[] = $t;
        }
        if ($tiers === array()) {
            fwrite(STDERR, "run.php: --tier= needs at least one tier\n");
            exit(EXIT_USAGE);
        }
        sort($tiers);
    } elseif (strncmp($a, '--filter=', 9) === 0) {
        $filter = substr($a, 9);
        if ($filter === '') {
            fwrite(STDERR, "run.php: --filter= needs a value\n");
            exit(EXIT_USAGE);
        }
    } elseif ($a === '--help' || $a === '-h') {
        fwrite(STDOUT, "usage: php tests/run.php [--tier=1,2,3] [--filter=STR] [--list] [--verbose] [--notes-are-errors] [--no-selfcheck]\n");
        exit(EXIT_OK);
    } else {
        fwrite(STDERR, "run.php: unknown argument '" . $a . "'\n");
        fwrite(STDERR, "usage: php tests/run.php [--tier=1,2,3] [--filter=STR] [--list] [--verbose] [--notes-are-errors] [--no-selfcheck]\n");
        exit(EXIT_USAGE);
    }
}

// ---------------------------------------------------------------------
// 탐색 — scandir 만 쓴다 (glob 금지: 경로의 대괄호가 0건을 만든다)
// ---------------------------------------------------------------------
/** @return string[] `t_*.php` 절대경로, 정렬 */
function discover(string $dir): array
{
    $out = array();
    if (!is_dir($dir)) {
        return $out;
    }
    $ent = scandir($dir);
    if ($ent === false) {
        return $out;
    }
    foreach ($ent as $e) {
        if (strncmp($e, 't_', 2) !== 0) {
            continue;
        }
        if (substr($e, -4) !== '.php') {
            continue;
        }
        $p = $dir . '/' . $e;
        if (is_file($p)) {
            $out[] = h_norm($p);
        }
    }
    sort($out);
    return $out;
}

$plan = array();
$missingTierDirs = array();
foreach ($tiers as $t) {
    $d = __DIR__ . '/' . $TIER_DIRS[$t];
    if (!is_dir($d)) {
        $missingTierDirs[] = $TIER_DIRS[$t];
        continue;
    }
    $fs = discover($d);
    if ($fs === array()) {
        $missingTierDirs[] = $TIER_DIRS[$t] . ' (no t_*.php)';
        continue;
    }
    $plan[$t] = $fs;
}

if ($plan === array()) {
    fwrite(STDERR, "run.php: no test files found for tier(s) " . implode(',', $tiers) . "\n");
    if ($missingTierDirs !== array()) {
        fwrite(STDERR, "         missing: " . implode(', ', $missingTierDirs) . "\n");
    }
    exit(EXIT_USAGE);
}

if ($listOnly) {
    foreach ($plan as $t => $fs) {
        fwrite(STDOUT, "tier " . $t . " (" . $TIER_DIRS[$t] . ")\n");
        foreach ($fs as $f) {
            fwrite(STDOUT, "  " . basename($f) . "\n");
        }
    }
    exit(EXIT_OK);
}

// ---------------------------------------------------------------------
// 0단계 — 러너 자기검증 (양성 픽스처)
//
// **검사기를 먼저 검증한다.** 이 저장소에서 검사기 거짓 결과가 21번 났고,
// `tools/php72_scan.php` 에서는 규칙이 영구히 발화하지 않는 버그가 실제로 잡혔다.
// 여기서는 판정을 뒤집어 돌린다 — 일부러 깨뜨린 대상에 대해 **검사기가 던져야 정상**이다.
// 던지지 않으면(= 결함을 못 봤으면) 종료코드 4 로 즉시 멈춘다.
// 이 단계가 통과하지 못하면 뒤의 "0건"은 아무 증거도 아니다.
// ---------------------------------------------------------------------
$selfNote = 'skipped';
if ($selfcheck) {
    $sdir  = __DIR__ . '/selfcheck';
    $sfs   = array();
    if (is_dir($sdir)) {
        $ent = scandir($sdir);
        foreach ($ent === false ? array() : $ent as $e) {
            if (strncmp($e, 's_', 2) === 0 && substr($e, -4) === '.php' && is_file($sdir . '/' . $e)) {
                $sfs[] = h_norm($sdir . '/' . $e);
            }
        }
        sort($sfs);
    }
    if ($sfs === array()) {
        fwrite(STDERR, "run.php: self-check files missing (tests/selfcheck/s_*.php).\n");
        fwrite(STDERR, "         without them a '0 findings' result is not evidence. refusing to report a pass.\n");
        exit(EXIT_BROKEN);
    }

    $GLOBALS['UT']['notes_are_errors'] = $notesErr;
    $GLOBALS['UT']['invert']  = true;
    $GLOBALS['UT']['verbose'] = $verbose;
    $GLOBALS['UT']['filter']  = '';   // 자기검증은 필터를 받지 않는다. 항상 전부 돈다
    // 이 단계는 **두 방향**을 함께 돈다:
    //   양성 픽스처 `t()`  — 일부러 깨뜨린 미니 루트. 검사기가 조용하면 고장이다
    //   음성 대조군 `tn()` — 합법인 코드. 검사기가 발화하면 과검출이다
    // 요약 줄이 이 둘을 나눠 인쇄한다. 합쳐서 한 분수로 찍으면 라벨이 거짓말을 한다.
    fwrite(STDOUT, "== self-check (positive fixtures must be detected; negative controls must stay silent) ==\n");
    foreach ($sfs as $f) {
        $GLOBALS['UT']['file'] = h_rel($f, h_tests_dir());
        fwrite(STDOUT, "  " . basename($f) . "\n");
        try {
            require $f;
        } catch (Throwable $e) {
            fwrite(STDERR, "  !! self-check file crashed: " . basename($f) . " - "
                . get_class($e) . ': ' . $e->getMessage() . "\n");
            exit(EXIT_BROKEN);
        }
    }
    $S = $GLOBALS['UT'];
    if ($S['ran'] === 0) {
        fwrite(STDERR, "run.php: self-check executed 0 cases. the runner cannot vouch for itself.\n");
        exit(EXIT_BROKEN);
    }
    if ($S['fail'] > 0) {
        // 두 방향을 갈라서 인쇄한다. 원인이 다르고 조치가 다르다:
        //   양성 미검출 → 검사기가 눈이 멀었다. 실제 코드에서도 0건을 보고할 것이다
        //   음성 발화   → 과검출. 사람이 곧 게이트를 무시하게 된다
        $missed = array();
        $noisy  = array();
        foreach ($S['fails'] as $f) {
            if (strpos($f['msg'], 'POSITIVE FIXTURE PASSED') === 0) {
                $missed[] = $f;
            } else {
                $noisy[] = $f;
            }
        }
        fwrite(STDERR, "\n!! RUNNER IS BROKEN - " . $S['fail'] . " self-check case(s) failed\n");
        if ($missed !== array()) {
            fwrite(STDERR, "   " . count($missed) . " positive fixture(s) went UNDETECTED:\n");
            foreach ($missed as $f) {
                fwrite(STDERR, "     - " . $f['file'] . ' :: ' . $f['name'] . "\n");
            }
            fwrite(STDERR, "   a checker that cannot see a planted defect will report 0 findings on real code too.\n");
        }
        if ($noisy !== array()) {
            fwrite(STDERR, "   " . count($noisy) . " negative control(s) FIRED on legal code (over-detection):\n");
            foreach ($noisy as $f) {
                fwrite(STDERR, "     - " . $f['file'] . ' :: ' . $f['name'] . "\n       " . $f['msg'] . "\n");
            }
            fwrite(STDERR, "   an over-detecting gate gets ignored, and an ignored gate is no gate.\n");
        }
        exit(EXIT_BROKEN);
    }
    /*
     * 요약 줄은 **양성과 음성을 나눠** 인쇄한다.
     *
     * 이전 판은 `inv_detected . '/' . ran` 을 `n/m planted defects detected` 로 찍었다.
     * `ran` 은 양성 + 음성 대조군의 합이므로 실측 `18/32` 가 라벨대로는
     * **"결함 14개를 놓쳤다"** 로 읽혔다. 실제로는 양성 18개를 전부 잡고 음성
     * 14개가 전부 조용한, 정확히 원하는 상태였다. 게이트 자체는 옳았다 —
     * 양성이 하나라도 통과하면 위의 `$S['fail'] > 0` 분기가 종료코드 4 로 멈춘다.
     * **틀린 것은 라벨이었고, 라벨이 틀리면 사람이 종료코드를 의심한다.**
     *
     * 그리고 **자기검증의 SKIP 을 반드시 같은 줄에 드러낸다.** 티어 3 용 오라클
     * 픽스처는 DB 가 없으면 SKIP 되고 SKIP 은 `ran` 에 들어가지 않는다 — 즉
     * 분모가 조용히 줄어든다(실측 39 → 32). 아래에서 `skips` 를 비우기까지 하므로
     * 적어 두지 않으면 "무엇이 자기검증되지 않았는지"가 완전히 사라진다.
     */
    $selfSkips = array();
    foreach ($S['skips'] as $s) {
        if (isset($s['phase']) && $s['phase'] === 'selfcheck') {
            $selfSkips[] = $s;
        }
    }
    $selfNote = sprintf(
        'positives %d/%d detected · negatives %d/%d silent',
        $S['inv_pos_detect'], $S['inv_pos'], $S['inv_neg_silent'], $S['inv_neg']
    );
    if ($selfSkips !== array()) {
        $selfNote .= sprintf(
            ' · %d fixture(s) SKIPPED (not self-verified)',
            count($selfSkips)
        );
    }
    fwrite(STDOUT, "   " . $selfNote . "\n");
    if ($selfSkips !== array()) {
        // 사유별로 접어서, 어느 검사기가 자기검증되지 않았는지 파일명까지 보여준다.
        $by = array();
        foreach ($selfSkips as $s) {
            $k = $s['msg'];
            if (!isset($by[$k])) {
                $by[$k] = array();
            }
            $by[$k][] = $s['file'] . ' :: ' . $s['name'];
        }
        fwrite(STDOUT, "   -- self-check SKIPS: these checkers were NOT verified in this run --\n");
        foreach ($by as $msg => $names) {
            fwrite(STDOUT, "      x" . count($names) . "  " . $msg . "\n");
            foreach ($names as $n) {
                fwrite(STDOUT, "            " . $n . "\n");
            }
        }
    }
    fwrite(STDOUT, "\n");

    // 본 실행을 위해 상태 초기화
    $GLOBALS['UT']['invert']         = false;
    $GLOBALS['UT']['neg_mode']       = false;
    $GLOBALS['UT']['inv_detected']   = 0;
    $GLOBALS['UT']['inv_pos']        = 0;
    $GLOBALS['UT']['inv_pos_detect'] = 0;
    $GLOBALS['UT']['inv_neg']        = 0;
    $GLOBALS['UT']['inv_neg_silent'] = 0;
    $GLOBALS['UT']['pass']  = 0;
    $GLOBALS['UT']['fail']  = 0;
    $GLOBALS['UT']['skip']  = 0;
    $GLOBALS['UT']['ran']   = 0;
    $GLOBALS['UT']['fails'] = array();
    $GLOBALS['UT']['skips'] = array();
    $GLOBALS['UT']['notes'] = array();
}

// ---------------------------------------------------------------------
// 본 실행
// ---------------------------------------------------------------------
$GLOBALS['UT']['filter']  = $filter;
$GLOBALS['UT']['verbose'] = $verbose;
$GLOBALS['UT']['notes_are_errors'] = $notesErr;
$GLOBALS['UT']['started'] = microtime(true);

$perTier = array();
foreach ($plan as $t => $fs) {
    $before = array($GLOBALS['UT']['pass'], $GLOBALS['UT']['fail'], $GLOBALS['UT']['skip']);
    $t0 = microtime(true);
    fwrite(STDOUT, "== tier " . $t . " - " . $TIER_DIRS[$t] . " ==\n");
    $GLOBALS['UT']['tier'] = $t;
    foreach ($fs as $f) {
        $GLOBALS['UT']['file'] = h_rel($f, h_tests_dir());
        if ($verbose) {
            fwrite(STDOUT, "  " . basename($f) . "\n");
        }
        try {
            require $f;
        } catch (Throwable $e) {
            // 파일 자체가 죽었다. 남은 케이스는 사라지지만 러너는 계속 간다.
            $GLOBALS['UT']['fail']++;
            $GLOBALS['UT']['ran']++;
            $GLOBALS['UT']['fails'][] = array(
                'file' => h_rel($f, h_tests_dir()),
                'name' => '(file aborted)',
                'msg'  => get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(),
            );
        }
    }
    $perTier[$t] = array(
        'pass' => $GLOBALS['UT']['pass'] - $before[0],
        'fail' => $GLOBALS['UT']['fail'] - $before[1],
        'skip' => $GLOBALS['UT']['skip'] - $before[2],
        'sec'  => microtime(true) - $t0,
    );
    fwrite(STDOUT, sprintf(
        "   pass %d  fail %d  skip %d   (%.2fs)\n\n",
        $perTier[$t]['pass'], $perTier[$t]['fail'], $perTier[$t]['skip'], $perTier[$t]['sec']
    ));
}

// ---------------------------------------------------------------------
// 보고
// ---------------------------------------------------------------------
$S = $GLOBALS['UT'];
$elapsed = microtime(true) - $S['started'];

if ($S['fails'] !== array()) {
    fwrite(STDOUT, "---- FAILURES (" . count($S['fails']) . ") ----\n");
    $i = 0;
    foreach ($S['fails'] as $f) {
        $i++;
        fwrite(STDOUT, $i . ") " . $f['file'] . "\n   " . $f['name'] . "\n   " . $f['msg'] . "\n");
    }
    fwrite(STDOUT, "\n");
}

if ($S['skips'] !== array() && !$verbose) {
    // 스킵은 조용히 넘기지 않는다. 사유별로 접어서 보여준다.
    $by = array();
    foreach ($S['skips'] as $s) {
        $k = $s['msg'];
        if (!isset($by[$k])) {
            $by[$k] = 0;
        }
        $by[$k]++;
    }
    fwrite(STDOUT, "---- SKIPPED (" . $S['skip'] . ") ----\n");
    foreach ($by as $msg => $n) {
        fwrite(STDOUT, "  x" . $n . "  " . $msg . "\n");
    }
    fwrite(STDOUT, "\n");
}

if ($S['notes'] !== array()) {
    // NOTE 는 종료코드에 안 들어가도 **항상 인쇄한다.** 조용히 접히면 없는 것과 같다.
    fwrite(STDOUT, "---- NOTES (" . count($S['notes']) . ") - not in the exit code; re-run with --notes-are-errors ----\n");
    $i = 0;
    foreach ($S['notes'] as $nt) {
        $i++;
        fwrite(STDOUT, $i . ") " . $nt['file'] . "\n   " . $nt['msg'] . "\n");
    }
    fwrite(STDOUT, "\n");
}

// 대상 코드가 남긴 error_log 줄 수. **0 이 아닌 것이 정상이다** —
// 이 스위트는 잘못된 입력을 일부러 먹이고, 그때마다 코드가 한 줄씩 남기는 것이 설계다.
$errLines = 0;
if (is_file($ULOGIN_ERRLOG)) {
    $errLines = max(0, count(file($ULOGIN_ERRLOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)));
}

fwrite(STDOUT, "self-check : " . $selfNote . "\n");
fwrite(STDOUT, "error_log  : " . $errLines . " line(s) captured from the code under test"
    . " (expected: the fail-closed paths log on purpose; the file is deleted on exit)\n");
fwrite(STDOUT, sprintf("total      : pass %d  fail %d  skip %d  note %d  (executed %d)  %.2fs\n",
    $S['pass'], $S['fail'], $S['skip'], count($S['notes']), $S['ran'], $elapsed));

if ($S['fail'] > 0) {
    fwrite(STDOUT, "result     : FAIL (exit 1) - fix the product\n");
    exit(EXIT_FAIL);
}
if ($S['ran'] === 0) {
    fwrite(STDOUT, "result     : NOTHING RAN (exit 3) - every case was skipped. fix the environment,\n");
    fwrite(STDOUT, "             not the code. this is NOT a pass.\n");
    exit(EXIT_NOTHING);
}
fwrite(STDOUT, "result     : PASS (exit 0)\n");
exit(EXIT_OK);
