<?php
declare(strict_types=1);

/**
 * 티어 1 — 문법과 기존 도구 위임
 *
 * 여기 세 검사 중 둘은 **새 코드가 아니라 호출**이다. `tools/php72_scan.php` 와
 * `tools/bump_asset_version.php` 는 "회귀 게이트로 쓸 수 있다"고 스스로 적어 두었는데
 * 아무것도 그것을 자동으로 돌리지 않았다 — 사람이 릴리스 절차 1·2단계를 기억해야 했다.
 * 그 기억을 코드로 옮긴다.
 */

require_once __DIR__ . '/../lib/checks.php';

$ROOT = h_root();

t('php -l passes on every .php in server/, tools/, tests/', function () use ($ROOT) {
    assert_clean(chk_php_syntax($ROOT), 'syntax errors');
});

t('tools/php72_scan.php reports ERROR 0 for server/, tools/, tests/', function () use ($ROOT) {
    // 스캐너는 PHP 8.x 로 돌려야 한다 — 8.0+ 문법을 *검출*하려면 그것을 파싱할 수 있는
    // 런타임이 필요하다. 이 러너를 8.x 로 돌리는 것이 전제다.
    // `--exclude=fixtures` — tests/fixtures/positive/ 는 일부러 깨뜨린 파일을 담는다.
    // 그것을 실제 코드와 같이 훑으면 스캐너가 자기 픽스처를 결함으로 보고한다.
    $r = ck_tool($ROOT, 'php72_scan.php', h_q($ROOT . '/server') . ' ' . h_q($ROOT . '/tools')
        . ' ' . h_q($ROOT . '/tests') . ' --exclude=fixtures');
    if ($r[0] === -1) {
        skip('tools/php72_scan.php not present');
    }
    if ($r[0] === 2) {
        fail('php72_scan.php exited 2 (usage/target error) - the gate did not actually run: ' . substr($r[1], -400));
    }
    assert_eq(0, $r[0], "php72_scan.php found PHP 7.3+ syntax\n" . $r[1]);
});

t('tools/bump_asset_version.php --dry-run exits 0 (no asset reference is missing ?v=)', function () use ($ROOT) {
    // 버전 인자는 **현재 값을 그대로** 준다. 목적은 치환이 아니라 판정이다.
    $ver = '0.0.0-probe';
    $vt = $ROOT . '/public/version.txt';
    if (is_file($vt)) {
        $raw = trim(h_read($vt));
        if (preg_match('~^(\d+\.\d+\.\d+)~', $raw, $m) === 1) {
            $ver = $m[1];
        }
    }
    $r = ck_tool($ROOT, 'bump_asset_version.php', $ver . ' --dry-run --dir=' . h_q($ROOT));
    if ($r[0] === -1) {
        skip('tools/bump_asset_version.php not present');
    }
    if ($r[0] === 2) {
        fail('bump_asset_version.php exited 2 (usage/IO error) - the gate did not actually run: ' . substr($r[1], -400));
    }
    if ($r[0] === 3) {
        // 3 = "치환할 자리를 하나도 갱신하지 못했다". 산출물 결함이 아니라 명령 문제다.
        // --from 을 주지 않았으므로 여기서 3 이 나오면 자산 참조 자체가 없다는 뜻이다.
        fail('bump_asset_version.php exited 3 - it found no asset reference to judge. that is a checker-blindness result, not a clean one.');
    }
    assert_eq(0, $r[0], "asset version gate failed\n" . $r[1]);
});

t('the test suite itself has no PHP 7.3+ syntax', function () use ($ROOT) {
    // 위 케이스에 tests/ 가 이미 포함돼 있지만, 그것이 조용히 빠지는 것을 막는 이중 확인이다.
    // 이 저장소의 배포 대상 최소 버전은 7.2 이고 tests/ 도 같은 규약을 따른다.
    $r = ck_tool($ROOT, 'php72_scan.php', h_q($ROOT . '/tests') . ' --exclude=fixtures');
    if ($r[0] === -1) {
        skip('tools/php72_scan.php not present');
    }
    assert_eq(0, $r[0], "tests/ itself uses 7.3+ syntax\n" . $r[1]);
});
