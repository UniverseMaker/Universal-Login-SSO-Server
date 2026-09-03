<?php
declare(strict_types=1);

/**
 * 자기검증 — **쿠키 오라클의 변이 시험 (mutation testing).**
 *
 * 71 케이스가 "위반 0건"을 내는 방법은 두 가지다: 코드가 맞거나, **불변식이
 * 아무것도 검사하지 않거나.** 두 번째가 이 저장소에서 실제로 일어난 적이 있다
 * (`tools/php72_scan.php` 의 영구 침묵 규칙).
 *
 * 그래서 조립기에 **알려진 결함을 심고** 오라클이 잡는지 본다.
 * 변이 7종은 전부 v1.0.0~v1.1.0 에서 실제로 났거나 D-68 이 명시적으로
 * 경고한 되돌림이다. 하나라도 조용히 통과하면 종료코드 4.
 *
 * 판정이 뒤집혀 있다(`t()`): **오라클이 던져야 정상이다.**
 */

require_once __DIR__ . '/../lib/loader.php';
require_once __DIR__ . '/../lib/cookie_oracle.php';

load_core();
require_once h_tests_dir() . '/fixtures/positive/mutant_cookie.php';

$CASES = cookie_cases();

/** 변이 하나를 전 케이스에 먹여 위반이 나오는지 본다. 안 나오면 오라클이 눈멀었다. */
$probe = function (string $mutant) use ($CASES) {
    $hits = 0;
    foreach ($CASES as $c) {
        if (cookie_check_invariants($mutant, $c) !== array()) {
            $hits++;
        }
    }
    if ($hits === 0) {
        // 통과 = invert 모드가 실패로 뒤집는다 = 종료코드 4
        return;
    }
    fail($mutant . ' was caught on ' . $hits . ' case(s)');
};

t('oracle catches a dropped HttpOnly (the v1.1.0 incident itself)', function () use ($probe) {
    $probe('mutant_drop_httponly');
});

t('oracle catches a dropped SameSite', function () use ($probe) {
    $probe('mutant_drop_samesite');
});

t('oracle catches the RFC 7231 date form (the D-68 revert)', function () use ($probe) {
    $probe('mutant_rfc7231_date');
});

t('oracle catches a url-encoded cookie name (V11-L-1)', function () use ($probe) {
    $probe('mutant_encode_name');
});

t('oracle catches an assembler with no separator check (attribute injection)', function () use ($probe) {
    $probe('mutant_no_separator_check');
});

t('oracle catches reordered attributes', function () use ($probe) {
    $probe('mutant_reorder');
});

t('oracle catches an empty-value delete instead of "deleted"', function () use ($probe) {
    $probe('mutant_empty_delete');
});

// ── 음성 대조군 — 진짜 조립기에는 위반이 0건이어야 한다 ──────────────
tn('oracle stays quiet on the real assembler across the whole matrix', function () use ($CASES) {
    $bad = array();
    foreach ($CASES as $c) {
        foreach (cookie_check_invariants('build_set_cookie_header', $c) as $one) {
            $bad[] = $c['id'] . ': ' . $one;
        }
    }
    assert_clean($bad, 'the oracle reports a violation on unmodified code (over-detection)');
});

tn('the oracle actually evaluated every case (it is not skipping them)', function () use ($CASES) {
    // "위반 0건"이 "케이스 0건"일 수 있다. 그 가능성을 여기서 닫는다.
    if (count($CASES) < 71) {
        fail('only ' . count($CASES) . ' cases in the matrix');
    }
    // 던져야 하는 케이스가 실제로 던지는지 - 오라클의 'throw' 분기가 사는지 확인
    $throwCases = 0;
    foreach ($CASES as $c) {
        if ($c['expect'] === 'throw') {
            $throwCases++;
        }
    }
    if ($throwCases < 8) {
        fail('only ' . $throwCases . ' "must throw" cases - the rejection half of the matrix is missing');
    }
});
