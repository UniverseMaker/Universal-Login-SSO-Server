<?php
declare(strict_types=1);

/**
 * 티어 1 — v1.10.2 가입 신청 화면 ↔ 서버 응답 키 대조 (admin-regreq.js ↔ register_requests.php · regreq.php)
 *
 *  1) 상세 응답은 {request:…} 래퍼다. 화면은 그 래퍼를 풀어서 showDetail 에 넘긴다(풀지 않으면 r.status 가 undefined).
 *  2) 화면이 읽는 r.* · svc.* · SIGNALS 키는 모두 서버가 실제로 내는 키다(상세 = regreq_item full, 목록 = 비-full).
 *  3) 목록 응답의 d.items · d.page · d.per_page · d.total 은 서버 json_out 키와 같다.
 * 변이: 래퍼를 풀지 않던 v1.10.1 코드를 되돌려 넣으면 검사가 실패해야 한다(같은 시험 안에서 실제로 심어 본다).
 * 검사기가 0건을 낼 수 없는 상태인지 먼저 본다(규약 7): 읽힌 키 수가 하한 아래면 HARNESS-FAIL.
 */

require_once __DIR__ . '/../lib/checks.php';

/** 화면 소스를 서버 소스와 대조해 문제 목록을 돌려준다. */
function t81_problems(string $js, string $rr, string $api): array
{
    $bad = array();
    // 1) 래퍼 풀기: 상세 apiGet 콜백이 받은 값을 그대로 showDetail 에 넘기면 안 된다
    if (preg_match('~apiGet\(\s*API\s*,\s*\{\s*id\s*:\s*id\s*\}\s*\)\s*\.then\(\s*function\s*\(\s*(\w+)\s*\)\s*\{\s*showDetail\(\s*\1\s*\)~', $js) === 1) {
        $bad[] = 'detail response passed to showDetail without unwrapping {request:…}';
    }
    if (preg_match('~function\s+openDetail\b.*?\.request\b.*?showDetail\(~s', $js) !== 1) {
        $bad[] = 'openDetail does not read .request before showDetail';
    }
    // 서버가 정말 request 로 감싸는지
    if (strpos($api, "json_out(['request' => regreq_item(") === false) {
        $bad[] = 'server detail wrapper key is no longer "request" (update the screen and this test)';
    }
    // 2) 서버 키
    if (preg_match('~function regreq_item\b(.*?)\n\}\n~s', $rr, $m) !== 1) { fail('HARNESS-FAIL regreq_item not found'); }
    $item = $m[1];
    $cut = strpos($item, 'if (!$full)');
    $listKeys = array();
    $fullKeys = array();
    preg_match_all("~'([a-z_0-9]+)'\s*=>~", substr($item, 0, $cut), $a);
    $listKeys = $a[1];
    preg_match_all("~'([a-z_0-9]+)'\s*=>~", substr($item, $cut), $b);
    $fullKeys = array_merge($listKeys, $b[1]);
    if (preg_match('~function regreq_signals\b.*?\n\}\n~s', $rr, $m2) !== 1) { fail('HARNESS-FAIL regreq_signals not found'); }
    $sigSrc = substr($m2[0], strpos($m2[0], 'if (!$full)'));
    preg_match_all("~'([a-z_0-9]+)'\s*=>~", $sigSrc, $s);
    $sigKeys = $s[1];
    $svcKeys = array('id', 'name', 'status', 'allow_register');   // regreq_item 의 $svc 배열(아래에서 서버 소스로 다시 확인)
    foreach ($svcKeys as $k) {
        if (strpos($item, "'" . $k . "' =>") === false) { $bad[] = 'server svc key missing: ' . $k; }
    }
    if (count($fullKeys) < 20 || count($sigKeys) < 8) { fail('HARNESS-FAIL too few server keys read: ' . count($fullKeys) . '/' . count($sigKeys)); }

    // 3) 화면이 읽는 키
    if (preg_match('~function showDetail\b(.*?)\n  function decide~s', $js, $d) !== 1) { fail('HARNESS-FAIL showDetail not found'); }
    preg_match_all('~\br\.([a-z_0-9]+)~', $d[1], $rk);
    $read = array_unique($rk[1]);
    if (count($read) < 15) { fail('HARNESS-FAIL too few screen keys read: ' . count($read)); }
    foreach ($read as $k) { if (!in_array($k, $fullKeys, true)) { $bad[] = 'detail reads r.' . $k . ' which the server does not send'; } }
    preg_match_all('~\bsvc\.([a-z_0-9]+)~', $d[1], $sk);
    foreach (array_unique($sk[1]) as $k) { if (!in_array($k, $svcKeys, true)) { $bad[] = 'detail reads svc.' . $k . ' which the server does not send'; } }
    if (preg_match('~var SIGNALS = \[(.*?)\];~s', $js, $sg) !== 1) { fail('HARNESS-FAIL SIGNALS not found'); }
    preg_match_all("~\['([a-z_0-9]+)'\s*,~", $sg[1], $sgk);
    if (count($sgk[1]) !== 8) { $bad[] = 'SIGNALS count ' . count($sgk[1]) . ' != 8'; }
    foreach ($sgk[1] as $k) { if (!in_array($k, $sigKeys, true)) { $bad[] = 'SIGNALS key ' . $k . ' not sent by server'; } }
    foreach ($sigKeys as $k) { if (!in_array($k, $sgk[1], true)) { $bad[] = 'server signal ' . $k . ' not shown by screen'; } }
    // 목록: renderList / sourceText 가 읽는 r.*
    if (preg_match('~function sourceText\b.*?\n  \}~s', $js, $st) !== 1 || preg_match('~function renderList\b.*?\n  function openDetail~s', $js, $rl) !== 1) {
        fail('HARNESS-FAIL list functions not found');
    }
    preg_match_all('~\br\.([a-z_0-9]+)~', $st[0] . $rl[0], $lk);
    foreach (array_unique($lk[1]) as $k) { if (!in_array($k, $listKeys, true)) { $bad[] = 'list reads r.' . $k . ' which the list item does not send'; } }
    // 목록 래퍼
    preg_match_all('~\bd\.(items|page|per_page|total)\b~', $rl[0] . $js, $dk);
    foreach (array('items', 'page', 'per_page', 'total') as $k) {
        if (strpos($api, "'" . $k . "'") === false) { $bad[] = 'server list lacks ' . $k; }
    }
    // 결정 응답: warnings 는 최상위
    if (strpos($js, 'res.warnings') !== false && strpos($api, "'warnings' =>") === false) { $bad[] = 'decide reads res.warnings, server does not send it'; }
    return $bad;
}

t('admin-regreq.js reads exactly the keys register_requests.php sends, and unwraps {request:…}', function () {
    $root = h_root();
    $js  = h_read($root . '/public/assets/admin-regreq.js');
    $rr  = h_read($root . '/server/core/regreq.php');
    $api = h_read($root . '/server/api/admin/register_requests.php');
    if (strlen($js) < 500 || strlen($rr) < 500 || strlen($api) < 500) { fail('HARNESS-FAIL source unreadable'); }
    assert_clean(t81_problems($js, $rr, $api), 'regreq screen vs server keys');

    // 변이: v1.10.1 의 결함 코드(래퍼를 풀지 않음)를 되돌려 넣으면 잡혀야 한다
    $mut = preg_replace('~apiGet\(API, \{ id: id \}\)\.then\(function \(d\) \{.*?showDetail\(r\);\s*\}\)~s',
        'apiGet(API, { id: id }).then(function (r) { showDetail(r); })', $js, 1, $n);
    assert_eq(1, $n, 'mutation applied');
    assert_true(count(t81_problems($mut, $rr, $api)) > 0, 'mutant (unwrapped response) must be caught');
    // 변이 2: 존재하지 않는 키를 읽는 화면
    $mut2 = str_replace('r.decision_reason', 'r.decided_reason', $js);
    assert_true(count(t81_problems($mut2, $rr, $api)) > 0, 'mutant (wrong key) must be caught');
});
