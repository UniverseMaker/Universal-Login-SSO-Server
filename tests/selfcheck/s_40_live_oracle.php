<?php
declare(strict_types=1);

/**
 * 자기검증 — **티어 3 의 스키마 비교기 변이 시험.**
 *
 * `live_fingerprint_diff()` 가 "차이 0건" 을 내는 방법은 두 가지다: 두 스키마가
 * 정말 같거나, **비교기가 아무것도 보지 않거나.** 마이그레이션 두 경로 대조는
 * 이 저장소에서 가장 값비싼 회귀(업그레이드본만 조용히 다른 스키마)를 노리는데,
 * 그 게이트가 눈멀면 아무 경고 없이 항상 통과한다.
 *
 * 그래서 **같은 스키마 두 벌을 만들고 한쪽만 일부러 어긋나게 한 뒤** 비교기가
 * 그것을 잡는지 확인한다. 못 잡으면 종료코드 4.
 *
 * DB 가 없으면 SKIP 한다 — 그 경우 티어 1·2 의 자기검증만으로 러너가 검증된다.
 * (전부 SKIP 이면 종료코드 3 이다. `0` 이 아니다.)
 *
 * 판정이 뒤집혀 있다(`t()`): **비교기가 던져야 정상이다.**
 */

require_once __DIR__ . '/../lib/live.php';

/** 변이 실험용 DB 두 벌. 만들고 끝나면 러너의 teardown 이 지운다. */
function sc_two_schemas(): array
{
    static $pair = null;
    if ($pair !== null) {
        return $pair;
    }
    live_or_skip();
    $a = 'ulogin_test_oracle_a';
    $b = 'ulogin_test_oracle_b';
    $pa = live_fresh_db($a);
    $pb = live_fresh_db($b);
    // **같은 파일**을 두 번 적용한다. 이 시점에서 두 지문은 반드시 같아야 한다.
    live_apply_sql($pa, h_root() . '/server/schema.sql');
    live_apply_sql($pb, h_root() . '/server/schema.sql');
    $pair = array($a, $b, $pa, $pb);
    return $pair;
}

/** 한쪽에 변이를 심고 비교기를 돌린다. 하드 차이가 나와야 정상. */
function sc_mutate_and_diff(string $ddl): array
{
    list($a, $b, $pa, $pb) = sc_two_schemas();
    $pb->exec($ddl);
    try {
        $soft = array();
        $hard = live_fingerprint_diff(
            live_schema_fingerprint(live_pdo($a), $a, 'app_ulogin_'),
            live_schema_fingerprint(live_pdo($b), $b, 'app_ulogin_'),
            $soft, 'clean', 'mutated'
        );
        return array($hard, $soft);
    } finally {
        // 변이를 되돌린다. 다음 케이스가 앞 케이스의 변이를 함께 보면
        // "잡았다" 가 어느 변이 때문인지 알 수 없어진다.
        $pb2 = live_pdo($b);
        $pb2->exec('DROP DATABASE IF EXISTS `' . $b . '`');
        $root = live_pdo();
        $root->exec('CREATE DATABASE `' . $b . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        live_apply_sql(live_pdo($b), h_root() . '/server/schema.sql');
    }
}

t('the schema comparator sees a missing column', function () {
    list($hard) = sc_mutate_and_diff('ALTER TABLE `app_ulogin_users` DROP COLUMN `nickname`');
    if ($hard === array()) {
        return;   // 못 잡았다 -> invert 모드가 실패로 뒤집는다 -> 종료코드 4
    }
    fail('caught: ' . implode(' | ', $hard));
});

t('the schema comparator sees a changed column type', function () {
    list($hard) = sc_mutate_and_diff('ALTER TABLE `app_ulogin_users` MODIFY `username` VARCHAR(255) NOT NULL');
    if ($hard === array()) {
        return;
    }
    fail('caught: ' . implode(' | ', $hard));
});

t('the schema comparator sees a nullability change', function () {
    list($hard) = sc_mutate_and_diff('ALTER TABLE `app_ulogin_users` MODIFY `email` VARCHAR(190) NULL');
    if ($hard === array()) {
        return;
    }
    fail('caught: ' . implode(' | ', $hard));
});

t('the schema comparator sees a dropped index', function () {
    list($hard) = sc_mutate_and_diff('ALTER TABLE `app_ulogin_users` DROP INDEX `uq_users_username`');
    if ($hard === array()) {
        return;
    }
    fail('caught: ' . implode(' | ', $hard));
});

t('the schema comparator sees a missing table', function () {
    list($hard) = sc_mutate_and_diff('DROP TABLE `app_ulogin_user_consents`');
    if ($hard === array()) {
        return;
    }
    fail('caught: ' . implode(' | ', $hard));
});

// ── 음성 대조군 ──────────────────────────────────────────────────────
tn('the schema comparator stays quiet when both sides came from the same file', function () {
    live_or_skip();
    list($a, $b) = sc_two_schemas();
    $soft = array();
    $hard = live_fingerprint_diff(
        live_schema_fingerprint(live_pdo($a), $a, 'app_ulogin_'),
        live_schema_fingerprint(live_pdo($b), $b, 'app_ulogin_'),
        $soft, 'a', 'b'
    );
    assert_clean($hard, 'false positive on two identical schemas');
    assert_clean($soft, 'false positive (soft) on two identical schemas');
});

tn('the fingerprint is not empty (the collector is not blind)', function () {
    live_or_skip();
    list($a) = sc_two_schemas();
    $fp = live_schema_fingerprint(live_pdo($a), $a, 'app_ulogin_');
    if (count($fp['tables']) < 10) {
        fail('fingerprint has only ' . count($fp['tables']) . ' tables - the collector is blind');
    }
    if (count($fp['indexes']) < 10) {
        fail('fingerprint has only ' . count($fp['indexes']) . ' indexes - the collector is blind');
    }
    // 한 컬럼이 실제로 속성 전부를 담고 있는가
    $any = reset($fp['tables']);
    $col = reset($any);
    foreach (array('type', 'nullable', 'default', 'extra', 'comment', 'charset', 'collation', 'position') as $attr) {
        if (!array_key_exists($attr, $col)) {
            fail('the fingerprint does not record "' . $attr . '" - that attribute can drift unseen');
        }
    }
});
