<?php
declare(strict_types=1);

/**
 * 티어 1 — v1.9.0 플랫폼 정적 검사 (설계 116 §12 시험 ①)
 *
 *  1) `require_service_access(` 가 집행 6곳 **각각에** 있다 — 넷 중 셋이 막혀 운영자가 막혔다고 오판한 사고(v1.4.0)의 재발 방지.
 *     파일 이름을 하나씩 대조한다(총 개수만 세면 한 파일에 둘이 있어 빠진 곳을 덮는다).
 *  2) 접근 판정 파일에 `grades['role']`(서비스 오버라이드 반영) 0건 — 판정은 전역 role 만(80 §3-3)
 *  3) 새 파일에 `FOR UPDATE` · `ON DUPLICATE` 리터럴 0건 — 방언은 sql_for_update() · db_upsert_increment() 만
 *  4) 비밀 키 정규식은 core/platform.php **한 곳** — 사본이 생기면 마스킹·거절 기준이 갈라진다
 * 검사기 자신이 0건을 낼 수 없는 상태인지 먼저 본다(규약 7): 대상 파일이 실제로 읽혔는지 센다.
 */

require_once __DIR__ . '/../lib/checks.php';

$ROOT = h_root();

t('require_service_access( is called in each of the six enforcement files', function () use ($ROOT) {
    $files = array('server/api/auth/login.php', 'server/api/sso/authorize.php', 'server/api/sso/authorize_session.php',
        'server/api/auth/exchange.php', 'server/api/auth/session.php', 'server/core/platauth.php');
    $miss = array();
    foreach ($files as $f) {
        $src = h_read($ROOT . '/' . $f);
        if ($src === '') {
            fail('HARNESS-FAIL ' . $f . ' unreadable');
        }
        if (substr_count(h_php_code_only($src), 'require_service_access(') < 1) {
            $miss[] = $f;
        }
    }
    assert_eq(array(), $miss, 'files missing require_service_access(');
    // 방어층: build_payload 도 같은 단계 판정(access_level_eval)을 부른다 — v1.10.0 설계 127 §2
    assert_contains('access_level_eval(', h_php_code_only(h_read($ROOT . '/server/core/payload.php')), 'payload layer');
});

// v1.10.0 T1-1 — 판정 사본 금지(S1·D-207): service_access_decide( · access_level_decide( 호출은 access.php 밖에서 0건
t('T1-1 no decide() call outside core/access.php', function () use ($ROOT) {
    $hits = array();
    $files = array_merge(h_files($ROOT . '/server', array('php')));
    assert_true(count($files) > 80, 'HARNESS-FAIL server scan saw ' . count($files) . ' files (scandir blind?)');
    foreach ($files as $f) {
        $rel = h_rel($f, $ROOT);
        if ($rel === 'server/core/access.php') {
            continue;
        }
        $code = h_php_code_only(h_read($f));
        if (preg_match('/(?<![a-z_])(service_access_decide|access_level_decide)\s*\(/', $code) === 1) {
            $hits[] = $rel;
        }
    }
    assert_eq(array(), $hits, 'decide() called outside access.php');
    // 자기 점검: 같은 정규식이 실제 호출을 잡는가(규약 7)
    assert_eq(1, preg_match('/(?<![a-z_])(service_access_decide|access_level_decide)\s*\(/', '$d = access_level_decide($u);'), 'regex self-check');
});

// v1.10.0 T1-2 — user_search 는 email·phone·role 을 SELECT 하지 않는다(S7: 필드 3개)
t('T1-2 user_search selects no email/phone/role', function () use ($ROOT) {
    $src = h_read($ROOT . '/server/api/sso/user_search.php');
    assert_true(strlen($src) > 500, 'HARNESS-FAIL user_search.php unreadable');
    $code = h_php_code_only_keep_strings($src);
    preg_match_all("~'SELECT [^']*'|\\\$cols = '[^']*'~", $code, $m);
    assert_true(count($m[0]) >= 2, 'HARNESS-FAIL found ' . count($m[0]) . ' SELECT literals');
    $bad = array();
    foreach ($m[0] as $s) {
        // role 은 판정(admin 규칙의 전역 role)에만 읽고 응답에 싣지 않는다 — 설계 T1-2 와의 차이는 핸드백에 적었다
        if (preg_match('/\b(email|phone|meta_json|memo|last_login_ip)\b/i', $s) === 1) {
            $bad[] = $s;
        }
    }
    assert_eq(array(), $bad, 'SELECT literal exposes a forbidden column');
    // 응답 항목은 셋뿐이다
    assert_contains("'uuid'     => (string) \$u['uuid'],", $src, 'item uuid');
    assert_eq(0, preg_match("~'(email|phone|role|status)'\\s*=>~", $code), 'item carries a forbidden key');
});

// v1.10.0 T1-3 — 0010 가드 누락 0, ENUM·ON DUPLICATE 0
t('T1-3 migration 0010 guards every DDL and uses no dialect literal', function () use ($ROOT) {
    $f = $ROOT . '/server/migrations/0010_v1100_access_levels.sql';
    $sql = h_read($f);
    assert_true(strlen($sql) > 500, 'HARNESS-FAIL 0010 unreadable');
    if (!defined('ULOGIN_MIGRATE_CTX')) {
        define('ULOGIN_MIGRATE_CTX', 1);
    }
    require_once $ROOT . '/server/migrations/lib.php';
    $errs = mig_lint(mig_split($sql));
    assert_eq(array(), $errs, 'unguarded DDL in 0010');
    $code = (string) preg_replace('/^--.*$/m', '', $sql);
    assert_eq(0, preg_match('/\bENUM\b|ON\s+DUPLICATE|FOR\s+UPDATE/i', $code), 'dialect literal in 0010');
    assert_true(substr_count($code, 'ALTER TABLE') >= 10 && substr_count($code, 'CREATE TABLE FROM SCHEMA') === 3, 'HARNESS-FAIL 0010 statements');
});

// v1.10.0 T1-4 — 서비스 개명 표 목록에 group_services 포함(빠뜨리면 개명 뒤 그룹 권한이 조용히 사라진다)
t('T1-4 service rename moves access_group_services too', function () use ($ROOT) {
    $code = h_php_code_only(h_read($ROOT . '/server/core/servicerename.php'));
    assert_true(preg_match('/function service_rename_tables\(\): array\s*\{\s*return \[([^\]]*)\]/s', $code, $m) === 1, 'HARNESS-FAIL table list not found');
    assert_contains('T_ACCESS_GROUP_SERVICES', $m[1], 'rename table list');
});

// v1.10.0 T1-5 — 비상 계정 CLI 는 비밀번호를 argv 로 받지 않는다
t('T1-5 emergency_admin.php takes no password from argv/env', function () use ($ROOT) {
    $src = h_read($ROOT . '/server/tools/emergency_admin.php');
    assert_true(strlen($src) > 1000, 'HARNESS-FAIL emergency_admin.php unreadable');
    assert_eq(0, substr_count($src, '--password='), '--password= option');
    $code = h_php_code_only($src);
    assert_eq(0, preg_match('/getenv\s*\(|\$_ENV|\$_SERVER\s*\[\s*[\'"]argv/', $code), 'password from env');
    assert_contains("preg_match('/^--([a-z\\-]+)\$/'", $src, 'argv parser rejects --x=value');
    assert_contains('ea_read_secret(', $code, 'reads from stdin');
});

// v1.10.0 T1-6 — IP 를 자르는 코드는 mask_for_viewer() 안에만
t('T1-6 IP masking lives only in mask_for_viewer()', function () use ($ROOT) {
    $hits = array();
    foreach (h_files($ROOT . '/server', array('php')) as $f) {
        $rel = h_rel($f, $ROOT);
        $code = h_php_code_only_keep_strings(h_read($f));
        if ($rel === 'server/core/serializer.php') {
            $i = strpos($code, 'function mask_for_viewer(');
            $j = strpos($code, 'function mask_user_view(');
            assert_true($i !== false && $j !== false && $j > $i, 'HARNESS-FAIL mask_for_viewer body not found');
            $code = substr($code, 0, $i) . substr($code, $j);
        }
        if (preg_match("~\\.\\s*'\\.x'|'::/48'|inet_pton\\s*\\(\\s*\\\$v~", $code) === 1) {
            $hits[] = $rel;
        }
    }
    assert_eq(array(), $hits, 'IP truncation outside mask_for_viewer');
});

t('access decision never reads the service-override role', function () use ($ROOT) {
    // payload.php 는 대상이 아니다 — 시크릿 min_role 게이트가 effective role 을 쓰는 것은 v1.0.0 계약이다.
    // 그 파일의 접근 판정 입력은 grades['global_role'] · grades['access_grant'] 이고 아래 별도로 본다.
    foreach (array('server/core/access.php', 'server/core/platauth.php') as $f) {
        $code = h_php_code_only_keep_strings(h_read($ROOT . '/' . $f));
        assert_true(strlen($code) > 200, 'HARNESS-FAIL ' . $f . ' looks empty');
        assert_eq(0, preg_match_all("~grades(?:'\\])?\\[\\s*'role'\\s*\\]~", $code), $f . " uses grades['role']");
    }
    // v1.10.0 — 방어층은 계정 행을 다시 읽어 access_level_eval() 로 판정한다(그 안에서 global_role_of). grades['role'] 은 쓰지 않는다
    $pl = h_php_code_only_keep_strings(h_read($ROOT . '/server/core/payload.php'));
    $i = strpos($pl, 'access_level_eval(');
    assert_true($i !== false, 'payload access layer calls access_level_eval');
    assert_contains("\$grades['user_id']", $pl, 'payload access layer re-reads the account by id');
    $ac = h_php_code_only_keep_strings(h_read($ROOT . '/server/core/access.php'));
    assert_contains("global_role_of(\$user)\n    );", $ac, 'access_level_eval passes the GLOBAL role');
    // 검사기 자기 점검: 같은 정규식이 effective role 자리를 실제로 잡는가(눈먼 검사 방지, 규약 7)
    assert_eq(2, preg_match_all("~grades(?:'\\])?\\[\\s*'role'\\s*\\]~", "\$x = \$ctx['grades']['role']; \$y = \$grades['role'];"), 'regex self-check');
});

t('new v1.9.0 files contain no FOR UPDATE / ON DUPLICATE literal', function () use ($ROOT) {
    $files = array('server/core/platform.php', 'server/core/platauth.php', 'server/core/access.php', 'server/core/rc.php',
        'server/core/docs.php', 'server/api/platform/logs.php', 'server/api/platform/audit.php', 'server/api/platform/remote_config.php',
        'server/api/platform/docs.php', 'server/api/platform/collections.php', 'server/api/platform/features.php',
        'server/api/sso/userinfo.php', 'server/api/sso/users.php', 'server/api/admin/platform_config.php',
        'server/api/admin/platform_logs.php', 'server/api/admin/platform_tables.php', 'server/api/admin/platform_usage.php',
        'server/api/admin/service_access.php', 'server/migrations/0007_v190_platform.sql');
    $bad = array();
    foreach ($files as $f) {
        $src = h_read($ROOT . '/' . $f);
        if ($src === '') {
            fail('HARNESS-FAIL ' . $f . ' unreadable');
        }
        $code = substr($f, -4) === '.php' ? h_php_code_only_keep_strings($src) : (string) preg_replace('/^--.*$/m', '', $src);
        if (preg_match('/FOR\s+UPDATE|ON\s+DUPLICATE/i', $code) === 1) {
            $bad[] = $f;
        }
    }
    assert_eq(array(), $bad, 'files with a dialect literal');
});

t('the secret-key regex exists in exactly one place (core/platform.php)', function () use ($ROOT) {
    $hits = array();
    foreach (h_files($ROOT . '/server', array('php')) as $f) {
        if (strpos(h_read($f), 'passwd|secret|token') !== false) {
            $hits[] = h_rel($f, $ROOT);
        }
    }
    assert_eq(array('server/core/platform.php'), $hits, 'copies of the secret-key regex');
});
