<?php
declare(strict_types=1);

/**
 * 티어 2 — `ck_opaque_shape_hint()` 자신을 검증한다 (D-113)
 *
 * `tier1_static/t_50_wiring.php` 가 이 함수의 결과로 **판정을 낸다** — 하나뿐인
 * 불투명 `json_out($var)` 호출부의 키를 손으로 못박고 그 목록과 대조한다.
 * 그러면 이 함수가 눈이 멀 때(리터럴을 못 찾을 때) 그 케이스는 **"바뀐 것이 없다"** 가
 * 아니라 **"0건"** 을 낼 수 있다. 이 저장소에서 27번 난 그 모양이다.
 *
 * 호출부에 `0건 → fail` 가드가 이미 있지만, 그 가드는 "완전히 멀었을 때" 만 잡는다.
 * **일부만 놓치는** 눈멂은 잡지 못한다 — 아래가 그 방향을 본다.
 *
 * 대상 코드를 읽지 않는 순수 함수 테스트다. DB·서버·확장 모듈이 필요 없다.
 */

require_once __DIR__ . '/../lib/checks.php';

/**
 * 임시 루트에 PHP 조각 하나를 놓고 `ck_opaque_shape_hint()` 를 돌린다.
 * 반환은 정렬된 키 배열. 임시 파일은 반드시 지운다.
 */
function op_hint(string $php): array
{
    $dir = sys_get_temp_dir() . '/ulogin_op_' . getmypid() . '_' . mt_rand(1000, 9999);
    @mkdir($dir . '/server/api', 0777, true);
    $f = $dir . '/server/api/probe.php';
    file_put_contents($f, $php);
    try {
        return ck_opaque_shape_hint($dir, 'server/api/probe.php');
    } finally {
        @unlink($f);
        @rmdir($dir . '/server/api');
        @rmdir($dir . '/server');
        @rmdir($dir);
    }
}

t('it resolves a plain return literal', function () {
    $k = op_hint("<?php\nreturn ['a' => 1, 'b' => 2];\n");
    assert_eq(array('a', 'b'), $k, 'top-level keys of a short-syntax return literal');
});

t('it resolves array() syntax too (the codebase mixes both)', function () {
    $k = op_hint("<?php\nreturn array('a' => 1, 'b' => 2);\n");
    assert_eq(array('a', 'b'), $k, 'top-level keys of a long-syntax return literal');
});

t('it takes depth-1 keys only, not nested ones', function () {
    // 중첩 키를 최상위로 올려 세면 못박은 목록이 부풀고, 그러면 호출부가
    // "새 키가 생겼다" 는 거짓 실패를 낸다 — 없는 결함을 만드는 방향이다.
    $k = op_hint("<?php\nreturn ['a' => ['nested' => 1, 'deeper' => ['x' => 2]], 'b' => 3];\n");
    assert_eq(array('a', 'b'), $k, 'nested keys must not be counted as top-level');
});

t('it resolves a return nested inside a closure (this is the real call site shape)', function () {
    // `api/admin/user_points.php` 가 정확히 이 모양이다: db_tx(function () { … return [ … ]; })
    $k = op_hint("<?php\n\$out = db_tx(function () {\n    return ['points' => 1, 'delta' => 2];\n});\njson_out(\$out);\n");
    assert_eq(array('delta', 'points'), $k, 'keys inside a closure return');
});

t('it unions every return literal in the file (a partial read is worse than none)', function () {
    // 조건 분기로 두 shape 이 나오는 파일에서 첫 것만 읽으면, 못박은 목록은
    // 통과하는데 **두 번째 shape 의 키는 아무도 소비자를 배정하지 않는다.**
    $k = op_hint("<?php\nif (\$x) {\n    return ['a' => 1];\n}\nreturn ['b' => 2];\n");
    assert_eq(array('a', 'b'), $k, 'both return literals must be unioned');
});

t('it ignores a return literal that only appears inside a comment', function () {
    // 주석 속 예시가 키로 세어지면 못박은 목록이 부풀고 거짓 실패가 난다.
    // `checks.php` 의 `chk_table_constants()` 가 같은 계열 결함으로 실측 재현됐다.
    $k = op_hint("<?php\n/*\n * 예시: return ['ghost' => 1];\n */\nreturn ['real' => 1];\n");
    assert_eq(array('real'), $k, 'a commented-out return must not contribute keys');
});

t('it stays empty when the return is a bare variable (it must not invent a shape)', function () {
    // 여기서 무언가를 지어내면 호출부가 "shape 을 안다" 고 착각한다.
    // 빈 배열이면 호출부의 `0건 -> fail` 가드가 걸린다 — 그것이 옳은 방향이다.
    $k = op_hint("<?php\n\$out = build();\nreturn \$out;\n");
    assert_eq(array(), $k, 'a non-literal return must yield no keys');
});

t('it returns empty for a file that does not exist (no crash, no invention)', function () {
    assert_eq(array(), ck_opaque_shape_hint(sys_get_temp_dir(), 'server/api/does_not_exist.php'),
        'a missing file must yield no keys rather than throwing');
});

t('the real call site still resolves to a non-empty shape (the resolver is not blind here)', function () {
    // 이 함수의 유일한 실사용 대상이다. 여기서 0 이면 티어 1 의 못박기가 무의미해진다.
    $k = ck_opaque_shape_hint(h_root(), 'server/api/admin/user_points.php');
    if (count($k) < 5) {
        fail('resolved only ' . count($k) . ' key(s) from the real opaque call site - the resolver is blind');
    }
});
