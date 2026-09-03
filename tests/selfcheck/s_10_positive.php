<?php
declare(strict_types=1);

/**
 * 자기검증 — **양성 픽스처.** 판정이 뒤집혀 있다.
 *
 * 각 케이스는 `tests/fixtures/positive/root/` (일부러 깨뜨린 미니 설치 루트)에 대고
 * 티어 1 검사기를 돌린다. **검사기가 결함을 잡아 던져야 정상**이다.
 * 하나라도 조용히 통과하면 러너는 종료코드 4 로 멈추고 본 실행을 하지 않는다.
 *
 * 왜 이 단계 없이는 안 되는가: 이 저장소에서 검사기 거짓 결과가 21번 났고,
 * `tools/php72_scan.php` 에서는 규칙이 **영구히 발화하지 않는** 버그가 잡혔다.
 * 검증되지 않은 검사기의 "0건"은 결함이 없다는 뜻이 아니라 **아무 뜻도 아니다.**
 */

require_once __DIR__ . '/../lib/checks.php';

$FX = h_tests_dir() . '/fixtures/positive/root';

if (!is_dir($FX)) {
    // 픽스처가 없으면 자기검증이 성립하지 않는다. 조용히 넘기지 않는다.
    throw new RuntimeException('positive fixture root missing: ' . $FX);
}

t('chk_php_syntax sees a file with unbalanced braces', function () use ($FX) {
    assert_clean(chk_php_syntax($FX, array('server')), 'planted syntax error');
});

t('chk_endpoints sees both a call with no file and a file with no caller', function () use ($FX) {
    $f = chk_endpoints($FX);
    // 두 방향 모두 나와야 한다. 한 방향만 잡는 검사기는 절반만 사는 것이다.
    $hasMissing = false;
    $hasOrphan  = false;
    foreach ($f as $one) {
        if (strpos($one, 'does not exist') !== false) {
            $hasMissing = true;
        }
        if (strpos($one, 'no front-end file calls it') !== false) {
            $hasOrphan = true;
        }
    }
    if (!$hasMissing) {
        return;   // 못 잡았다 = 통과 = invert 모드가 실패로 뒤집는다
    }
    if (!$hasOrphan) {
        return;
    }
    assert_clean($f, 'planted endpoint mismatch');
});

t('chk_error_codes sees a thrown code missing from both tables and a dead table entry', function () use ($FX) {
    $f = chk_error_codes($FX);
    $hasThrownNotInTable = false;
    $hasDeadEntry = false;
    foreach ($f as $one) {
        if (strpos($one, 'FIXTURE_ONLY_CODE') !== false) {
            $hasThrownNotInTable = true;
        }
        if (strpos($one, 'FIXTURE_DEAD_CODE') !== false || strpos($one, 'FIXTURE_CLIENT_ONLY') !== false) {
            $hasDeadEntry = true;
        }
    }
    if (!$hasThrownNotInTable || !$hasDeadEntry) {
        return;
    }
    assert_clean($f, 'planted error code mismatch');
});

t('chk_page_links sees a link to a file that does not exist', function () use ($FX) {
    assert_clean(chk_page_links($FX), 'planted dead link');
});

t('chk_phantom_identifiers sees a comment citing an undefined function', function () use ($FX) {
    $f = chk_phantom_identifiers($FX);
    $hit = false;
    foreach ($f as $one) {
        if (strpos($one, 'fixture_no_such_function') !== false) {
            $hit = true;
        }
    }
    if (!$hit) {
        return;
    }
    assert_clean($f, 'planted phantom identifier');
});

t('chk_line_citations sees a line number in a code comment', function () use ($FX) {
    assert_clean(chk_line_citations($FX), 'planted line-number citation');
});

t('chk_login_result_values sees a value the schema, the admin filter and the screens all miss', function () use ($FX) {
    $f = chk_login_result_values($FX);
    $n = 0;
    foreach ($f as $one) {
        if (strpos($one, 'fail_planted') !== false) {
            $n++;
        }
    }
    // 스키마 · 관리자 필터 · 화면 — 세 방향 모두에서 나와야 한다
    if ($n < 3) {
        return;
    }
    assert_clean($f, 'planted login result value');
});

t('chk_migration_guards sees DDL with no @skip-if above it', function () use ($FX) {
    assert_clean(chk_migration_guards($FX), 'planted unguarded DDL');
});

t('chk_htaccess_rules sees the canonical rules gone', function () use ($FX) {
    assert_clean(chk_htaccess_rules($FX), 'planted missing htaccess rules');
});

t('chk_unconsumed_response_fields sees a field no screen reads', function () use ($FX) {
    $f = chk_unconsumed_response_fields($FX);
    $hit = false;
    foreach ($f as $one) {
        if (strncmp($one, 'nobody_reads_this', 17) === 0) {
            $hit = true;
        }
    }
    if (!$hit) {
        return;
    }
    assert_clean($f, 'planted unconsumed field');
});

t('chk_table_constants sees a constant with no table, a table with no constant and a guard mismatch', function () use ($FX) {
    $f = chk_table_constants($FX);
    $a = false;
    $b = false;
    $c = false;
    foreach ($f as $one) {
        if (strpos($one, 'T_PHANTOM') !== false) {
            $a = true;
        }
        if (strpos($one, 'unconstanted') !== false) {
            $b = true;
        }
        if (strpos($one, 'sessions_TYPO') !== false) {
            $c = true;
        }
    }
    if (!$a || !$b || !$c) {
        return;
    }
    assert_clean($f, 'planted table constant drift');
});
