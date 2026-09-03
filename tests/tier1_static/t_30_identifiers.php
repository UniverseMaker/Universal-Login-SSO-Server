<?php
declare(strict_types=1);

/**
 * 티어 1 — 주석·문서가 인용하는 식별자와 위치
 *
 * D-148 (팬텀 함수명) 과 D-92·D-147 (줄 번호 인용) 을 회귀 게이트로 만든다.
 * 둘 다 동작을 깨뜨리지 않는다. 대신 **다음 사람을 없는 자리로 보낸다** —
 * 이 저장소에서 실제로 라운드를 소모한 유형이다.
 */

require_once __DIR__ . '/../lib/checks.php';

$ROOT = h_root();

t('no comment cites a function that does not exist (D-148 phantom identifier)', function () use ($ROOT) {
    // 함수가 아닌데 `이름()` 꼴로 정당하게 인용되는 것들.
    // **늘리기 전에 그것이 정말 함수가 아닌지 확인하라.**
    $allow = array(
        'setcookie',            // PHP 본체. get_defined_functions() 에 있지만 SAPI 에 따라 빠질 수 있다
        'headers_list',
        'sapi_header_op',       // PHP 내부 C 함수. 주석이 설명용으로 인용한다
        'php_setcookie',        // 같은 것 — setcookie() 의 C 구현체 이름. 바이트 동등성 설명에 필요하다
        'mysql_real_escape_string',
        // 아래 둘은 `migrate.php` 가 **과거 구조를 서술하면서** 인용한다
        // ("종전 구조는 읽기와 쓰기가 분리돼 있었고…"). 지금 없는 것이 정상이다.
        'mig_throttle_fail',
        'mig_throttle_check',
    );
    assert_clean(chk_phantom_identifiers($ROOT, $allow), 'phantom identifier in a comment');
});

t('phantom-identifier collector actually parsed comments (not blind)', function () use ($ROOT) {
    $defined = ck_defined_php_functions($ROOT);
    if (count($defined) < 50) {
        fail('collected only ' . count($defined) . ' function definitions from server/tools/tests - the collector is blind');
    }
});

t('code comments do not carry line numbers (D-92 / D-147)', function () use ($ROOT) {
    // NOTE 급이다. 동작을 깨뜨리지 않으므로 기본 실행에서 종료코드를 바꾸지 않는다.
    // `--notes-are-errors` 를 주면 실패로 승격된다 — tools/php72_scan.php 와 같은 규율.
    //
    // 왜 그냥 두지 않는가: "한 번 편집하면 낡는다" 가 이미 두 번 관측됐고
    // (D-92 는 리포트에서, D-147·32l 은 코드 주석에서), 낡은 인용은 다음 사람을
    // 조용히 틀린 줄로 보낸다. 고치는 방법은 번호를 **갱신하는 것이 아니라 지우는 것**이다 —
    // 갱신하면 다음 편집에 또 낡는다. 함수명·절 이름으로 가리켜라.
    assert_clean(chk_line_citations($ROOT), 'line-number citation in a code comment', true);
});
