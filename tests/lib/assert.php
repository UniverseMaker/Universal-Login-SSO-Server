<?php
declare(strict_types=1);

/**
 * tests/lib/assert.php — 단언·케이스 등록
 *
 * PHP 7.2 호환. 의존성 0. `tools/` 의 규약을 따른다 (CLI 전용, 네트워크·확장 모듈 없음).
 *
 * 설계 하나만 기억하면 된다: **`t()` 는 등록이 아니라 즉시 실행이다.**
 * 등록 후 일괄 실행 방식은 테스트 파일이 파일 스코프에서 준비한 상태(임시 DB 핸들,
 * 서버 프로세스)를 클로저 밖으로 살려 둬야 하고, 그 수명이 어긋나면 러너가
 * "실패"가 아니라 "치명적 오류"로 죽는다. 즉시 실행이면 파일이 위에서 아래로
 * 읽히는 순서 그대로 돌고, 중간에서 죽어도 그때까지의 결과가 남는다.
 */

class TestFail extends Exception
{
}

/** 케이스 하나를 건너뛴다. 환경 부재(DB 없음)에만 쓴다. 결함을 숨기는 데 쓰지 마라. */
class TestSkip extends Exception
{
}

/**
 * 러너 전역 상태.
 *
 * 전역 변수를 쓰는 이유: 테스트 파일은 `include` 로 들어오고 함수 스코프가 없다.
 * 싱글턴 클래스를 만들어도 접근 경로가 길어질 뿐 동일하다.
 */
$GLOBALS['UT'] = array(
    'pass'    => 0,
    'fail'    => 0,
    'skip'    => 0,
    'ran'     => 0,        // pass + fail. **skip 은 포함하지 않는다** — 종료코드 3 의 근거
    'fails'   => array(),  // 실패 상세
    'skips'   => array(),  // 스킵 사유 (집계용)
    'file'    => '',       // 현재 테스트 파일
    'tier'    => 0,
    'filter'  => '',
    'verbose' => false,
    'started' => 0.0,
    // 자기검증 모드: 켜면 "실패해야 정상" 으로 판정이 뒤집힌다 (양성 픽스처)
    'invert'  => false,
    'inv_detected' => 0,   // invert 모드에서 실제로 잡아낸 건수
    // ── 자기검증 계수는 **양성과 음성을 나눠 센다** ─────────────────────────
    //
    // 나누지 않으면 요약 줄이 거짓말을 한다. 이전 판(`inv_detected . '/' . ran`)은
    // 분모에 **양성 + 음성 대조군을 합쳐** 넣고 라벨을 `n/m planted defects detected`
    // 라고 붙였다. 실측 `18/32` 는 "양성 18개를 전부 잡고 음성 14개가 전부 조용했다"
    // 인데, 라벨대로 읽으면 **"결함 14개를 놓쳤다"** 가 된다. 놓쳤다면 설계상
    // 종료코드 4 인데 종료코드는 0 이었으니, 읽는 사람은 라벨과 종료코드 중
    // 하나를 틀린 것으로 여기게 된다 — 이 저장소가 반복해 고쳐 온
    // **「0건을 결함 없음으로 읽는」 것과 같은 계열**의 결함이다.
    //
    // 실제 게이트는 처음부터 옳았다: 양성이 하나라도 통과하면 `fail++` 이고
    // run.php 가 그 자리에서 종료코드 4 로 멈춘다. **틀린 것은 라벨뿐이었다.**
    // 그래서 아래 4개를 따로 세고 요약 줄을 양성/음성으로 쪼개 인쇄한다.
    'inv_pos'        => 0,   // 실행된 양성 픽스처 수 (SKIP 제외)
    'inv_pos_detect' => 0,   // 그중 검사기가 잡아낸 수. 같지 않으면 종료코드 4
    'inv_neg'        => 0,   // 실행된 음성 대조군 수 (SKIP 제외)
    'inv_neg_silent' => 0,   // 그중 조용했던 수. 같지 않으면 과검출
    // `tn()` 이 자기검증 중임을 `t()` 에 알리는 플래그. 본 실행(티어 1~3)에서는 항상 false
    'neg_mode'       => false,
    // NOTE — `tools/php72_scan.php` 의 ERROR/NOTE 규율을 그대로 가져왔다.
    // 동작을 깨뜨리지는 않지만 사람이 봐야 하는 것. 기본은 종료코드에 반영하지 않고,
    // `--notes-are-errors` 를 주면 실패로 승격된다.
    'notes'   => array(),
    'notes_are_errors' => false,
);

function ut_state(): array
{
    return $GLOBALS['UT'];
}

/**
 * 케이스 하나.
 *
 * @param string   $name 사람이 읽는 이름. `--filter` 대상이기도 하다
 * @param callable $fn   본문. 단언 실패는 TestFail, 환경 부재는 TestSkip
 */
function t(string $name, callable $fn): void
{
    $S = &$GLOBALS['UT'];
    if ($S['filter'] !== '' && stripos($name, $S['filter']) === false && stripos($S['file'], $S['filter']) === false) {
        return;
    }
    try {
        $fn();
        if ($S['invert']) {
            // 양성 픽스처인데 통과했다 = 검사기가 결함을 못 봤다
            $S['inv_pos']++;
            $S['fail']++;
            $S['ran']++;
            $S['fails'][] = array(
                'file' => $S['file'],
                'name' => $name,
                'msg'  => 'POSITIVE FIXTURE PASSED - the checker did not detect the planted defect',
            );
            return;
        }
        if ($S['neg_mode']) {
            // 음성 대조군이 조용했다 = 정상
            $S['inv_neg']++;
            $S['inv_neg_silent']++;
        }
        $S['pass']++;
        $S['ran']++;
        if ($S['verbose']) {
            fwrite(STDOUT, "    ok   " . $name . "\n");
        }
    } catch (TestSkip $e) {
        $S['skip']++;
        $S['skips'][] = array(
            'file' => $S['file'],
            'name' => $name,
            'msg'  => $e->getMessage(),
            // 자기검증 단계의 SKIP 인지 표시한다. run.php 가 본 실행 전에 skips 를
            // 비우므로, 구분해 두지 않으면 **자기검증이 몇 건 안 돌았는지가 사라진다.**
            'phase' => ($S['invert'] || $S['neg_mode']) ? 'selfcheck' : 'main',
        );
        if ($S['verbose']) {
            fwrite(STDOUT, "    skip " . $name . " (" . $e->getMessage() . ")\n");
        }
    } catch (Throwable $e) {
        // TestFail 이 아닌 예외(TypeError, Error, PDOException…)도 실패로 잡는다.
        // 러너가 그 자리에서 죽으면 나머지 케이스가 통째로 사라진다.
        if ($S['invert']) {
            $S['inv_detected']++;
            $S['inv_pos']++;
            $S['inv_pos_detect']++;
            $S['pass']++;
            $S['ran']++;
            if ($S['verbose']) {
                fwrite(STDOUT, "    ok(detected) " . $name . "\n");
            }
            return;
        }
        if ($S['neg_mode']) {
            // 음성 대조군이 발화했다 = 과검출. 실패로 잡는다
            $S['inv_neg']++;
        }
        $S['fail']++;
        $S['ran']++;
        $msg = $e->getMessage();
        if (!($e instanceof TestFail)) {
            $msg = get_class($e) . ': ' . $msg . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
        }
        $S['fails'][] = array('file' => $S['file'], 'name' => $name, 'msg' => $msg);
        if ($S['verbose']) {
            fwrite(STDOUT, "    FAIL " . $name . "\n         " . $msg . "\n");
        }
    }
}

/**
 * **판정을 뒤집지 않는** 케이스. 자기검증 단계의 **음성 대조군**에 쓴다.
 *
 * `t()` 는 자기검증 중 판정이 뒤집힌다(양성 픽스처는 실패해야 정상). 그런데
 * 검사기 검증에는 반대 방향도 필요하다 — **합법인 코드에서 조용해야 한다.**
 * 과검출하는 검사기는 사람이 곧 무시하게 되고, 무시되는 게이트는 없는 게이트다.
 * `tn()` 은 어느 모드에서도 "통과해야 통과"다.
 */
function tn(string $name, callable $fn): void
{
    $S = &$GLOBALS['UT'];
    $was = $S['invert'];
    $wasNeg = $S['neg_mode'];
    $S['invert'] = false;
    // **자기검증 중일 때만** 음성 대조군으로 센다. 티어 1~3 에서 `tn()` 을 쓰면
    // `$was` 가 false 이므로 계수에 들어가지 않는다 — 그때는 그냥 보통 케이스다.
    $S['neg_mode'] = $was;
    t($name, $fn);
    $S['invert']   = $was;
    $S['neg_mode'] = $wasNeg;
}

/**
 * 확장 모듈이 없으면 그 케이스를 SKIP 한다.
 *
 * **실패가 아니라 스킵인 이유:** 확장 부재는 산출물의 결함이 아니라 러너를 돌리는
 * 환경의 문제다. 실패로 처리하면 사람이 산출물에서 없는 결함을 찾게 된다
 * (`tools/bump_asset_version.php` 가 종료코드 `1` 과 `3` 을 나눈 것과 같은 이유).
 *
 * **그리고 전부 스킵되면 종료코드 3 이다. 절대 0 이 아니다.**
 */
function require_ext(string ...$exts): void
{
    $missing = array();
    foreach ($exts as $e) {
        if (!extension_loaded($e)) {
            $missing[] = $e;
        }
    }
    if ($missing !== array()) {
        skip('PHP extension(s) not loaded: ' . implode(', ', $missing)
            . ' - the product requires them; enable them in php.ini and re-run');
    }
}

/** 케이스 본문에서 호출. 환경이 없다. */
function skip(string $why): void
{
    throw new TestSkip($why);
}

/**
 * NOTE 급 발견. **케이스를 실패시키지 않는다** — `--notes-are-errors` 를 줬을 때만 실패한다.
 *
 * `tools/php72_scan.php` 의 ERROR / NOTE 구분과 같은 규율이다. 동작을 깨뜨리지 않지만
 * 사람이 봐야 하는 것(낡은 줄 번호 인용, 소비자 미배정 필드)을 여기에 담는다.
 *
 * **조용히 삼키지 않는다** — 러너는 NOTE 를 항상 인쇄하고 집계에 넣는다.
 * `t()` 안에서 호출해야 어느 케이스의 NOTE 인지 남는다.
 */
function note(string $msg): void
{
    $S = &$GLOBALS['UT'];
    $S['notes'][] = array('file' => $S['file'], 'msg' => $msg);
    if ($S['notes_are_errors']) {
        throw new TestFail('NOTE promoted to error: ' . $msg);
    }
}

/**
 * 발견 목록이 비었는지 단언. 검사기 반환값을 그대로 넘긴다.
 * @param bool $asNote true 면 실패가 아니라 NOTE 로 기록한다
 */
function assert_clean(array $findings, string $what, bool $asNote = false): void
{
    if ($findings === array()) {
        return;
    }
    $body = $what . ' - ' . count($findings) . " finding(s):\n     - " . implode("\n     - ", $findings);
    if ($asNote) {
        note($body);
        return;
    }
    throw new TestFail($body);
}

/** 무조건 실패. 도달하면 안 되는 지점 표시용. */
function fail(string $why): void
{
    throw new TestFail($why);
}

function ut_show($v): string
{
    if (is_string($v)) {
        return '"' . $v . '"';
    }
    if (is_bool($v)) {
        return $v ? 'true' : 'false';
    }
    if ($v === null) {
        return 'null';
    }
    if (is_array($v)) {
        $j = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $j === false ? '(unencodable array)' : $j;
    }
    if (is_object($v)) {
        return get_class($v);
    }
    return (string) $v;
}

function assert_eq($expected, $actual, string $what = ''): void
{
    if ($expected !== $actual) {
        throw new TestFail(($what !== '' ? $what . ': ' : '')
            . 'expected ' . ut_show($expected) . ' got ' . ut_show($actual));
    }
}

function assert_ne($unexpected, $actual, string $what = ''): void
{
    if ($unexpected === $actual) {
        throw new TestFail(($what !== '' ? $what . ': ' : '')
            . 'expected NOT ' . ut_show($unexpected));
    }
}

function assert_true($v, string $what = ''): void
{
    if ($v !== true) {
        throw new TestFail(($what !== '' ? $what . ': ' : '') . 'expected true got ' . ut_show($v));
    }
}

function assert_false($v, string $what = ''): void
{
    if ($v !== false) {
        throw new TestFail(($what !== '' ? $what . ': ' : '') . 'expected false got ' . ut_show($v));
    }
}

/** $hay 에 $needle 이 들어 있는가. 부분 문자열. */
function assert_contains(string $needle, string $hay, string $what = ''): void
{
    if ($needle !== '' && strpos($hay, $needle) === false) {
        throw new TestFail(($what !== '' ? $what . ': ' : '')
            . ut_show($needle) . ' not found in ' . ut_show($hay));
    }
}

function assert_not_contains(string $needle, string $hay, string $what = ''): void
{
    if ($needle !== '' && strpos($hay, $needle) !== false) {
        throw new TestFail(($what !== '' ? $what . ': ' : '')
            . ut_show($needle) . ' MUST NOT appear in ' . ut_show($hay));
    }
}

function assert_match(string $re, string $s, string $what = ''): void
{
    if (preg_match($re, $s) !== 1) {
        throw new TestFail(($what !== '' ? $what . ': ' : '') . ut_show($s) . ' does not match ' . $re);
    }
}

/**
 * $fn 이 던지는가. 던지지 않으면 실패.
 * @param string $class 기대 예외 클래스. ''(빈문자열)이면 아무 Throwable 이나
 * @return Throwable 잡은 예외 (추가 단언용)
 */
function assert_throws(string $class, callable $fn, string $what = ''): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($class !== '' && !($e instanceof $class)) {
            throw new TestFail(($what !== '' ? $what . ': ' : '')
                . 'expected ' . $class . ' got ' . get_class($e) . ' (' . $e->getMessage() . ')');
        }
        return $e;
    }
    throw new TestFail(($what !== '' ? $what . ': ' : '') . 'expected ' . ($class === '' ? 'a throw' : $class) . ' but nothing was thrown');
}

/** $fn 이 던지지 않는가. */
function assert_no_throw(callable $fn, string $what = '')
{
    try {
        return $fn();
    } catch (Throwable $e) {
        throw new TestFail(($what !== '' ? $what . ': ' : '')
            . 'unexpected ' . get_class($e) . ': ' . $e->getMessage());
    }
}

/**
 * 두 목록의 양방향 차집합. 계약 ↔ 코드 대조의 기본 도구 (D-23).
 * @return array {only_left: [...], only_right: [...]}
 */
function diff_both(array $left, array $right): array
{
    $l = array_values(array_unique($left));
    $r = array_values(array_unique($right));
    sort($l);
    sort($r);
    return array(
        'only_left'  => array_values(array_diff($l, $r)),
        'only_right' => array_values(array_diff($r, $l)),
    );
}

/**
 * 차집합이 양쪽 다 비었는지 단언. 아니면 어느 쪽이 남았는지 보여준다.
 * @param array $allow_left  왼쪽에만 있어도 되는 값 (사유가 코드 주석에 있어야 한다)
 * @param array $allow_right 오른쪽에만 있어도 되는 값
 */
function assert_no_diff(array $left, array $right, string $lname, string $rname, array $allow_left = array(), array $allow_right = array()): void
{
    $d = diff_both($left, $right);
    $ol = array_values(array_diff($d['only_left'], $allow_left));
    $orr = array_values(array_diff($d['only_right'], $allow_right));
    if ($ol === array() && $orr === array()) {
        return;
    }
    $m = array();
    if ($ol !== array()) {
        $m[] = 'only in ' . $lname . ' (' . count($ol) . '): ' . implode(', ', $ol);
    }
    if ($orr !== array()) {
        $m[] = 'only in ' . $rname . ' (' . count($orr) . '): ' . implode(', ', $orr);
    }
    throw new TestFail(implode(' | ', $m));
}
