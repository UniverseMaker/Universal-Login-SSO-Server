<?php
declare(strict_types=1);

/**
 * 티어 1 — 값 집합과 스키마 상수
 *
 * `login_logs.result` 는 이 저장소가 **같은 자리에서 두 번 틀린** 곳이다.
 * v1.4.0 에서 값이 10 → 11 로 늘었는데 화면의 필터 옵션은 8 에 머물렀다.
 * 단순 grep 이 이 값을 못 세는 이유가 검사기 안에 적혀 있다 (세 경로).
 */

require_once __DIR__ . '/../lib/checks.php';

$ROOT = h_root();

t('login_logs.result values agree across server, schema, admin API and screens', function () use ($ROOT) {
    assert_clean(chk_login_result_values($ROOT), 'login result value mismatch');
});

t('login result collector found all three write paths (not blind)', function () use ($ROOT) {
    // 이 케이스가 있는 이유: 위 검사가 "0건 불일치"를 내는 방법이 두 가지다 —
    // (a) 정말 일치한다  (b) 수집기가 아무것도 못 봤다.
    // (b) 를 배제하지 않으면 게이트는 조용히 항상 통과한다.
    $srv = ck_result_values_server($ROOT);
    if (count($srv) < 8) {
        fail('collected only ' . count($srv) . ' login result values from server code: ' . implode(', ', $srv)
            . ' - a plain grep of log_login() literals stops around 8; the ternary branch and the'
            . ' direct insert in migrate.php must also be seen');
    }
    // 삼항 분기가 실제로 잡혔는가 — 한 인자에 값이 둘인 경로
    $hasTernaryPair = in_array('fail_refresh_reuse', $srv, true) && in_array('fail_refresh_revoked', $srv, true);
    assert_true($hasTernaryPair, 'the ternary log_login($x ? "a" : "b") path was not collected');
    // migrate.php 의 직접 삽입 경로
    assert_true(in_array('fail_migrate_auth', $srv, true), 'the direct insert path (migrate.php) was not collected');
});

t('every migration DDL statement carries a @skip-if guard', function () use ($ROOT) {
    // 러너는 가드 없는 DDL 이 하나라도 있으면 **파일 전체를 거부한다.**
    // 업로드 후에 발견하면 스키마가 반쯤 적용된 상태에서 멈춘다.
    assert_clean(chk_migration_guards($ROOT), 'unguarded migration DDL');
});

t('table constants agree across config.sample.php, bootstrap.php and schema.sql (D-151)', function () use ($ROOT) {
    assert_clean(chk_table_constants($ROOT), 'table constant drift');
});
