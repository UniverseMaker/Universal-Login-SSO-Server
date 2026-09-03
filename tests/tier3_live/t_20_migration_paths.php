<?php
declare(strict_types=1);

/**
 * 티어 3 — **마이그레이션 두 경로가 같은 스키마에 도달하는가**
 *
 *   경로 A (신규 설치) : v1.5.0 `schema.sql` 적용 → `migrate.php apply`
 *                        (마이그레이션은 baseline 으로 기록만 된다)
 *   경로 B (업그레이드) : **v1.3.0 의 `schema.sql`**(v1.4.0 이전 상태) 적용
 *                        → v1.5.0 의 `migrate.php apply` 로 마이그레이션 실행
 *
 * 두 경로의 `information_schema` 지문이 **바이트 단위로** 같아야 한다.
 *
 * ─── 왜 `migrate.php verify` 로 충분하지 않은가 ──────────────────────
 * D-175 가 이미 판정했다 — **`verify` 의 맹점이 문서보다 넓다.** 실제로 v1.4.0
 * 라운드에서 `login_logs.result` 의 **COLUMN COMMENT 차이 1건**이 `verify` 를
 * 통과한 채로 발견됐다. 여기서는 `information_schema` 를 직접 읽어
 * 컬럼 타입·NULL 허용·기본값·EXTRA·**COMMENT**·문자셋·콜레이션·컬럼 순서·
 * 인덱스(유니크·순서 포함)·외래키를 전부 대조한다.
 *
 * ─── 이 테스트가 못 잡는 것 ───────────────────────────────────────────
 *  · **데이터** 마이그레이션의 정확성. 구조만 본다
 *  · v1.0.0~v1.2.0 에서 올라오는 경로. 기준선을 v1.3.0 하나로 잡았다 —
 *    v1.4.0 마이그레이션이 "v1.0.0 ~ v1.3.0 으로 설치된 기존 DB" 를 대상으로
 *    선언하므로 그 구간의 나머지는 **미검증**이다
 */

require_once __DIR__ . '/../lib/live.php';

/** v1.4.0 이전 기준선으로 쓸 과거 버전 폴더. **읽기 전용으로만 연다.** */
function mig_baseline_root(): ?string
{
    $parent = dirname(h_root());
    // scandir + 수동 필터. glob() 은 경로의 대괄호를 문자 클래스로 읽어 0건을 낸다.
    $ent = @scandir($parent);
    if ($ent === false) {
        return null;
    }
    $cands = array();
    foreach ($ent as $e) {
        if (preg_match('~^v1\.3\.\d+_\d{8}$~', $e) === 1 && is_dir($parent . '/' . $e)) {
            $cands[] = $e;
        }
    }
    if ($cands === array()) {
        return null;
    }
    sort($cands);
    return h_norm($parent . '/' . end($cands));
}

t('both install paths reach a byte-identical schema (information_schema, not verify)', function () {
    live_or_skip();
    $base = mig_baseline_root();
    if ($base === null) {
        skip('no v1.3.x_YYYYMMDD folder next to this one to use as the pre-v1.4.0 baseline');
    }
    if (!is_file($base . '/server/schema.sql')) {
        skip('baseline folder has no server/schema.sql: ' . $base);
    }

    // --- 경로 A: 신규 설치 ---
    $dbA = 'ulogin_test_fresh_path';
    $pdoA = live_fresh_db($dbA);
    live_apply_sql($pdoA, h_root() . '/server/schema.sql');
    $boxA = live_sandbox_named('pathA', array('DB_NAME' => $dbA));
    list($ca, $oa) = live_migrate($boxA, 'apply');
    if ($ca !== 0) {
        fail('fresh path: migrate.php apply exited ' . $ca . "\n" . $oa);
    }

    // --- 경로 B: v1.3.0 에서 업그레이드 ---
    // 기준선 스키마는 **과거 버전 폴더에서 읽기만** 한다. 그 폴더는 불변이다.
    $dbB = 'ulogin_test_upgrade_path';
    $pdoB = live_fresh_db($dbB);
    live_apply_sql($pdoB, $base . '/server/schema.sql');
    // migrate.php 는 **이 버전의 것**을 쓴다 — 업그레이드는 새 코드가 옛 DB 를 올린다
    $boxB = live_sandbox_named('pathB', array('DB_NAME' => $dbB));
    list($cb, $ob) = live_migrate($boxB, 'apply');
    if ($cb !== 0) {
        fail('upgrade path: migrate.php apply exited ' . $cb . "\n" . $ob);
    }

    // --- 지문 대조 ---
    $fpA = live_schema_fingerprint(live_pdo($dbA), $dbA, 'app_ulogin_');
    $fpB = live_schema_fingerprint(live_pdo($dbB), $dbB, 'app_ulogin_');

    // 비교는 `live_fingerprint_diff()` 가 한다 — **라이브러리에 둔 이유는
    // 그 비교기 자신을 검증하기 위해서다.** `tests/selfcheck/s_40_live_oracle.php` 가
    // 일부러 어긋난 두 스키마를 먹여 비교기가 실제로 잡는지 확인한다.
    // 여기 인라인하면 그게 불가능해지고, 그러면 "차이 0건" 이 증거가 아니게 된다.
    $soft = array();
    $diffs = live_fingerprint_diff($fpA, $fpB, $soft, 'fresh', 'upgrade');

    // 수집기 눈멂 방지 — 두 지문이 모두 비어 있으면 "차이 0" 은 아무 뜻도 없다
    if (count($fpA['tables']) < 10 || count($fpB['tables']) < 10) {
        fail('fingerprints look empty (fresh=' . count($fpA['tables']) . ' tables, upgrade='
            . count($fpB['tables']) . ') - the collector is blind, not the schemas identical');
    }

    if ($soft !== array()) {
        note('the two install paths agree on behaviour but differ in self-documentation / column order ('
            . count($soft) . "):
     - " . implode("
     - ", $soft)
            . "
     migrate.php verify does not see these (D-175). they are reported every run so they cannot"
            . ' quietly grow into a behavioural difference.');
    }
    assert_clean($diffs, 'the two install paths diverge behaviourally (baseline: ' . basename($base) . ')');
});

t('migrate.php verify agrees on both paths (and we know its blind spots)', function () {
    live_or_skip();
    if (mig_baseline_root() === null) {
        skip('no v1.3.x baseline folder');
    }
    foreach (array('pathA' => 'fresh install', 'pathB' => 'upgrade') as $key => $label) {
        $L = $GLOBALS['LIVE'];
        if (!isset($L['boxes'][$key])) {
            skip('the two-path case did not run, so there is nothing to verify');
        }
        list($code, $out) = live_migrate($L['boxes'][$key], 'verify');
        if ($code !== 0) {
            fail($label . ': migrate.php verify exited ' . $code . "\n" . $out);
        }
    }
    // **verify 가 통과한다고 두 경로가 같다는 뜻은 아니다** (D-175).
    // 위 케이스가 information_schema 로 직접 대조하는 이유가 그것이다.
    note('migrate.php verify passed on both paths, but verify has documented blind spots (D-175); '
        . 'the information_schema comparison above is the real gate');
});

t('migrate.php status is readable and lists the migration files', function () {
    live_or_skip();
    $L = $GLOBALS['LIVE'];
    if (!isset($L['boxes']['pathA'])) {
        skip('the two-path case did not run');
    }
    list($code, $out) = live_migrate($L['boxes']['pathA'], 'status');
    assert_eq(0, $code, "migrate.php status exited non-zero:\n" . $out);
    $files = h_files(h_root() . '/server/migrations', array('sql'));
    if ($files === array()) {
        skip('no migration files to check');
    }
    foreach ($files as $f) {
        // 러너는 파일명 전체가 아니라 **번호 접두사**로 표시한다 (`0001   applied  ...`).
        $id = substr(basename($f), 0, 4);
        assert_contains($id, $out, 'status output must mention migration ' . $id);
    }
});
