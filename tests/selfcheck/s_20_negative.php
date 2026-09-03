<?php
declare(strict_types=1);

/**
 * 자기검증 — **음성 대조군.** 판정을 뒤집지 않는다 (`tn()`).
 *
 * `tests/fixtures/negative/root/` 는 결함이 하나도 없는 미니 설치 루트다.
 * 다만 검사기를 속이기 쉬운 합법 패턴을 모아 두었다 — 배열 리터럴의 trailing comma,
 * 과거 릴리스를 가리키는 주석, 문자열 안의 `//`, 주석 안의 `href=`.
 *
 * **여기서 한 건이라도 나오면 그 검사기는 과검출이다.** 과검출하는 게이트는
 * 며칠 안에 사람이 무시하게 되고, 무시되는 게이트는 없는 게이트다.
 * 이 조사에서 실제로 겪은 함정이 그 방향이었다 — HTML 만 grep 해서
 * `ui.js` 가 런타임에 설정하는 ARIA 역할을 "없다"고 보고할 뻔했다.
 */

require_once __DIR__ . '/../lib/checks.php';

$NG = h_tests_dir() . '/fixtures/negative/root';

if (!is_dir($NG)) {
    throw new RuntimeException('negative fixture root missing: ' . $NG);
}

tn('chk_php_syntax stays quiet on legal PHP 7.2', function () use ($NG) {
    assert_clean(chk_php_syntax($NG, array('server')), 'false positive');
});

tn('chk_endpoints stays quiet when every call and file line up', function () use ($NG) {
    assert_clean(chk_endpoints($NG), 'false positive');
});

tn('chk_error_codes stays quiet when the three sets agree', function () use ($NG) {
    assert_clean(chk_error_codes($NG), 'false positive');
});

tn('chk_page_links ignores external URLs and links inside comments', function () use ($NG) {
    assert_clean(chk_page_links($NG), 'false positive');
});

tn('chk_phantom_identifiers stays quiet on comments citing functions that do exist', function () use ($NG) {
    assert_clean(chk_phantom_identifiers($NG), 'false positive');
});

tn('chk_line_citations stays quiet when no comment carries a line number', function () use ($NG) {
    assert_clean(chk_line_citations($NG), 'false positive');
});

tn('chk_login_result_values stays quiet when all four views agree', function () use ($NG) {
    assert_clean(chk_login_result_values($NG), 'false positive');
});

tn('chk_migration_guards stays quiet on a guarded DDL', function () use ($NG) {
    assert_clean(chk_migration_guards($NG), 'false positive');
});

tn('chk_htaccess_rules stays quiet when the rules are present', function () use ($NG) {
    assert_clean(chk_htaccess_rules($NG), 'false positive');
});

tn('chk_unconsumed_response_fields stays quiet when every field is read', function () use ($NG) {
    assert_clean(chk_unconsumed_response_fields($NG), 'false positive');
});

tn('chk_table_constants stays quiet when the three places agree', function () use ($NG) {
    assert_clean(chk_table_constants($NG), 'false positive');
});

tn('the trailing comma in an array literal is not reported as 7.3+ call syntax', function () use ($NG) {
    // 이 저장소가 자체 스캐너를 만든 이유가 정확히 이 구분이다 —
    // 배열 리터럴의 trailing comma(5.x 부터 합법)와 함수 **호출**의 것(7.3+)은
    // grep 으로 구분할 수 없다. 음성 대조군에 그 패턴을 심어 두고 확인한다.
    $r = ck_tool(h_root(), 'php72_scan.php', h_q($NG . '/server'));
    if ($r[0] === -1) {
        skip('tools/php72_scan.php not present');
    }
    assert_eq(0, $r[0], "php72_scan.php over-detected on the negative control:\n" . $r[1]);
});
