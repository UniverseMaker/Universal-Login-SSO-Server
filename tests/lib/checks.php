<?php
declare(strict_types=1);

/**
 * tests/lib/checks.php — 티어 1 정적 검사기 본체
 *
 * **모든 검사기는 `$root`(설치 루트) 하나만 받는 순수 함수다.** 이것이 규약이다.
 * 그래야 같은 함수를 `tests/fixtures/positive/*`(일부러 깨뜨린 미니 루트)에 대고
 * 돌려 **검사기 자신을 검증**할 수 있다. 검사기가 실제 코드를 직접 열어 보게
 * 만들면 자기검증이 불가능해지고, 그러면 "0건"이 증거가 아니게 된다.
 *
 * 반환은 발견 문자열의 배열이다. 빈 배열 = 깨끗함.
 * **줄 번호를 발견 문자열에 넣지 마라** (D-92) — 파일명 + 식별자로 가리킨다.
 * 유일한 예외가 `chk_line_citations()` 인데, 그 검사의 대상이 줄 번호 자체다.
 */

require_once __DIR__ . '/harness.php';

// =====================================================================
// 공통 수집기
// =====================================================================

/** 프론트엔드 소스 전체 (public/ + examples/ 의 html·js). */
function ck_front_files(string $root): array
{
    return array_merge(
        h_files($root . '/public', array('html', 'js')),
        h_files($root . '/examples', array('html', 'js'))
    );
}

function ck_front_blob(string $root): string
{
    static $cache = array();
    if (isset($cache[$root])) {
        return $cache[$root];
    }
    $b = '';
    foreach (ck_front_files($root) as $f) {
        $b .= h_read($f) . "\n";
    }
    $cache[$root] = $b;
    return $b;
}

/** `api.js` 의 LOGICAL_PATH_ALIASES 맵. 논리 경로 → 물리 경로. */
function ck_path_aliases(string $root): array
{
    $f = $root . '/public/assets/api.js';
    if (!is_file($f)) {
        return array();
    }
    $s = h_read($f);
    $p = strpos($s, 'LOGICAL_PATH_ALIASES');
    if ($p === false) {
        return array();
    }
    $o = strpos($s, '{', $p);
    $c = strpos($s, '}', $o === false ? $p : $o);
    if ($o === false || $c === false) {
        return array();
    }
    $body = substr($s, $o, $c - $o);
    $out = array();
    if (preg_match_all('~[\'"](/api/[^\'"]+)[\'"]\s*:\s*[\'"](/api/[^\'"]+)[\'"]~', $body, $m)) {
        foreach ($m[1] as $i => $k) {
            $out[$k] = $m[2][$i];
        }
    }
    return $out;
}

// =====================================================================
// 1. PHP 문법 — 전 파일 `php -l`
// =====================================================================

function chk_php_syntax(string $root, array $dirs = array('server', 'tools', 'tests')): array
{
    $bad = array();
    $php = h_php_bin();
    foreach ($dirs as $d) {
        foreach (h_files($root . '/' . $d, array('php')) as $f) {
            $code = 0;
            $out = h_run(h_q($php) . ' -n -l ' . h_q($f), $code);
            if ($code !== 0) {
                $bad[] = h_rel($f, $root) . ' : ' . trim(preg_replace('/\s+/', ' ', $out));
            }
        }
    }
    return $bad;
}

// =====================================================================
// 2. 엔드포인트 ↔ 프론트 호출 — 양방향 차집합
//
// 잡는 것: 없는 URL 을 부르는 화면(즉시 404), 아무도 안 부르는 엔드포인트(죽은 API).
// 못 잡는 것: 경로는 맞는데 **메서드**가 틀린 호출. 정적으로 메서드를 잇지 못한다.
// =====================================================================

/** 실제 엔드포인트 파일 → 논리 경로 (`server/api/me/index.php` → `/api/me/index`) */
function ck_endpoint_files(string $root): array
{
    $out = array();
    foreach (h_files($root . '/server/api', array('php')) as $f) {
        $rel = h_rel($f, $root . '/server');           // api/me/index.php
        $out[] = '/' . substr($rel, 0, -4);            // /api/me/index
    }
    sort($out);
    return $out;
}

/** 프론트가 문자열 리터럴로 쓰는 `/api/...` 경로. 별칭을 물리 경로로 해소한다. */
function ck_endpoint_calls(string $root): array
{
    $alias = ck_path_aliases($root);
    $out = array();
    foreach (ck_front_files($root) as $f) {
        $s = h_read($f);
        if (preg_match_all('~[\'"`](/api/[A-Za-z0-9_/\-]*)[\'"`]~', $s, $m)) {
            foreach ($m[1] as $p) {
                $p = rtrim($p, '/');
                if ($p === '/api' || $p === '') {
                    continue;
                }
                $out[] = isset($alias[$p]) ? $alias[$p] : $p;
            }
        }
    }
    return array_values(array_unique($out));
}

/**
 * @param array $allowUncalled 호출부가 없어도 되는 엔드포인트. **사유를 호출부에 적어라.**
 */
function chk_endpoints(string $root, array $allowUncalled = array()): array
{
    $files = ck_endpoint_files($root);
    $calls = ck_endpoint_calls($root);
    $out = array();
    foreach (array_diff($calls, $files) as $c) {
        $out[] = 'front-end calls ' . $c . ' but server/api' . substr($c, 4) . '.php does not exist';
    }
    foreach (array_diff($files, $calls, $allowUncalled) as $f) {
        $out[] = 'endpoint ' . $f . ' exists but no front-end file calls it';
    }
    sort($out);
    return $out;
}

// =====================================================================
// 3. 에러 코드 3자 대조
//   서버가 던지는 코드  ↔  response.php ERROR_MESSAGES  ↔  api.js ERROR_MESSAGES
//
// 프론트 표에 없는 코드가 서버에서 나오면 화면은 서버 message 에만 의존한다.
// 서버가 던지지 않는 코드가 표에만 있으면 그 분기는 영원히 실행되지 않는다.
// =====================================================================

function ck_codes_thrown(string $root): array
{
    $out = array();
    foreach (h_files($root . '/server', array('php')) as $f) {
        $s = h_read($f);
        if (preg_match_all('~(?:new\s+AppError|json_err)\s*\(\s*[\'"]([A-Z][A-Z_]{2,})[\'"]~', $s, $m)) {
            foreach ($m[1] as $c) {
                $out[] = $c;
            }
        }
    }
    return array_values(array_unique($out));
}

function ck_codes_server_table(string $root): array
{
    $f = $root . '/server/core/response.php';
    if (!is_file($f)) {
        return array();
    }
    $s = h_read($f);
    $p = strpos($s, 'ERROR_MESSAGES');
    if ($p === false) {
        return array();
    }
    $o = strpos($s, '[', $p);
    $c = strpos($s, '];', $o === false ? $p : $o);
    if ($o === false || $c === false) {
        return array();
    }
    $body = substr($s, $o, $c - $o);
    $out = array();
    if (preg_match_all('~[\'"]([A-Z][A-Z_]{2,})[\'"]\s*=>~', $body, $m)) {
        $out = $m[1];
    }
    return array_values(array_unique($out));
}

function ck_codes_client_table(string $root): array
{
    $f = $root . '/public/assets/api.js';
    if (!is_file($f)) {
        return array();
    }
    $s = h_read($f);
    $p = strpos($s, 'var ERROR_MESSAGES');
    if ($p === false) {
        $p = strpos($s, 'ERROR_MESSAGES =');
    }
    if ($p === false) {
        return array();
    }
    $o = strpos($s, '{', $p);
    $c = strpos($s, '};', $o === false ? $p : $o);
    if ($o === false || $c === false) {
        return array();
    }
    $body = substr($s, $o, $c - $o);
    $out = array();
    if (preg_match_all('~(?:^|[\s{,])([A-Z][A-Z_]{2,})\s*:~m', $body, $m)) {
        $out = $m[1];
    }
    return array_values(array_unique($out));
}

/**
 * @param array $clientOnly 서버가 응답하지 않는 클라이언트 전용 의사 코드
 */
function chk_error_codes(string $root, array $clientOnly = array()): array
{
    $thrown = ck_codes_thrown($root);
    $srv    = ck_codes_server_table($root);
    $cli    = ck_codes_client_table($root);
    $out    = array();

    if ($srv === array()) {
        return array('could not read ERROR_MESSAGES from server/core/response.php - the checker is blind, not the code clean');
    }
    if ($cli === array()) {
        return array('could not read ERROR_MESSAGES from public/assets/api.js - the checker is blind, not the code clean');
    }

    foreach (array_diff($thrown, $srv) as $c) {
        $out[] = 'server throws ' . $c . ' but response.php ERROR_MESSAGES has no entry (falls back to the generic message)';
    }
    foreach (array_diff($srv, $thrown) as $c) {
        $out[] = 'response.php ERROR_MESSAGES defines ' . $c . ' but no server file throws it (dead entry)';
    }
    foreach (array_diff($thrown, $cli) as $c) {
        $out[] = 'server throws ' . $c . ' but api.js ERROR_MESSAGES has no fallback text';
    }
    foreach (array_diff($cli, $thrown, $clientOnly) as $c) {
        $out[] = 'api.js ERROR_MESSAGES defines ' . $c . ' but no server file throws it (dead branch)';
    }
    sort($out);
    return $out;
}

// =====================================================================
// 4. 화면 링크 대상 실재
// =====================================================================

function chk_page_links(string $root): array
{
    $out = array();
    foreach (ck_front_files($root) as $f) {
        $s = h_js_strip_comments(h_read($f));
        // **상대 링크의 기준은 스크립트가 있는 폴더가 아니라 화면이 있는 폴더다.**
        // `public/assets/api.js` 가 `location.href = 'login.html'` 을 하면 그것은
        // `public/assets/login.html` 이 아니라 **그 스크립트를 불러온 페이지**,
        // 즉 `public/login.html` 로 간다. 파일 위치로 풀면 없는 404 를 만들어낸다 —
        // 방향이 반대인 거짓(없는 결함을 지어내는 쪽)이라 더 나쁘다.
        $dir = dirname($f);
        foreach (array('/public', '/examples') as $webroot) {
            $wr = h_norm($root . $webroot);
            if (strncmp(h_norm($f), $wr . '/', strlen($wr) + 1) === 0) {
                $dir = $wr;
                break;
            }
        }
        $re = '~(?:href\s*=\s*|location\.href\s*=\s*|location\.replace\s*\(\s*|window\.open\s*\(\s*)'
            . '[\'"]([A-Za-z0-9_\-./]+\.html)(?:[?#][^\'"]*)?[\'"]~';
        if (!preg_match_all($re, $s, $m)) {
            continue;
        }
        foreach (array_unique($m[1]) as $t) {
            if (strncmp($t, 'http', 4) === 0 || strncmp($t, '//', 2) === 0) {
                continue;
            }
            $abs = $t[0] === '/' ? $root . $t : $dir . '/' . $t;
            // realpath 로 `..` 를 접는다. 없으면 그대로 두고 is_file 로 판정
            $rp = realpath($abs);
            if ($rp === false || !is_file($rp)) {
                $out[] = h_rel($f, $root) . ' links to "' . $t . '" which does not exist';
            }
        }
    }
    sort($out);
    return array_values(array_unique($out));
}

// =====================================================================
// 5. 팬텀 식별자 — 주석이 인용한 함수명이 실제로 정의돼 있는가 (D-148)
//
// 잡는 것: `require_role()` 처럼 주석·문서가 부르는데 그런 함수가 없는 경우.
// 못 잡는 것: 이름은 맞는데 **의미가 틀린** 인용. 그건 사람이 읽어야 한다.
// =====================================================================

function ck_defined_php_functions(string $root): array
{
    $out = array();
    foreach (array_merge(
        h_files($root . '/server', array('php')),
        h_files($root . '/tools', array('php')),
        h_files($root . '/tests', array('php'))
    ) as $f) {
        $code = h_php_code_only(h_read($f));
        if (preg_match_all('~\bfunction\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(~', $code, $m)) {
            foreach ($m[1] as $n) {
                $out[$n] = true;
            }
        }
    }
    return $out;
}

function chk_phantom_identifiers(string $root, array $allow = array()): array
{
    $defined = ck_defined_php_functions($root);
    $internal = get_defined_functions();
    $builtin = array();
    foreach ($internal['internal'] as $n) {
        $builtin[strtolower($n)] = true;
    }
    // PHP 제어 구조·언어 구조는 함수가 아니지만 `foo(` 꼴로 인용된다
    foreach (array('if', 'for', 'foreach', 'while', 'switch', 'catch', 'fn', 'function', 'return',
                   'echo', 'print', 'list', 'array', 'isset', 'unset', 'empty', 'eval', 'exit',
                   'die', 'include', 'require', 'include_once', 'require_once', 'new', 'match',
                   'use', 'and', 'or', 'not', 'do', 'try', 'else', 'elseif', 'declare') as $k) {
        $builtin[$k] = true;
    }

    $out = array();
    foreach (h_files($root . '/server', array('php')) as $f) {
        $cm = h_php_comments(h_read($f));
        // 앞 글자 배제 목록에 `.` 과 `*` 이 들어 있는 이유:
        //   `.` — `Object.keys()` 같은 메서드 인용. 전역 함수가 아니다
        //   `*` — `` `*_gte()` `` 처럼 **와일드카드로 함수군을 가리키는** 표기
        // 그리고 이름은 **글자로 시작**해야 한다. `_gte` 같은 접미사 조각을 잡지 않는다.
        if (!preg_match_all('~(?<![A-Za-z0-9_$>:.*\\\\])([a-z][a-z0-9_]{3,})\s*\(\s*\)~', $cm, $m)) {
            continue;
        }
        foreach (array_unique($m[1]) as $n) {
            if (isset($defined[$n]) || isset($builtin[strtolower($n)]) || in_array($n, $allow, true)) {
                continue;
            }
            $out[] = h_rel($f, $root) . ' cites ' . $n . '() in a comment but no such function is defined anywhere in server/, tools/ or tests/';
        }
    }
    sort($out);
    return array_values(array_unique($out));
}

// =====================================================================
// 6. 코드 주석의 줄 번호 인용 (D-92 / D-147)
//
// 한 번 편집하면 낡는다. 이 저장소는 이미 그것을 두 번 겪었다.
// **NOTE 급이다** — 동작을 깨뜨리지는 않는다. 다만 사람을 틀린 자리로 보낸다.
// 여기서는 인용을 찾는 데 그치지 않고 **실제로 낡았는지까지 판정한다.**
// =====================================================================

/**
 * 인용 `file.ext:N` 이 가리키는 파일을 찾아, 그 줄이 인용 문맥의 키워드를 담고 있는지 본다.
 * 키워드를 못 정하면 판정하지 않고 인용 사실만 보고한다.
 */
function chk_line_citations(string $root): array
{
    $out = array();
    // 인용 대상 후보 파일 인덱스: 파일명(끝부분) → 절대경로들
    $index = array();
    foreach (array_merge(
        h_files($root . '/server', array('php', 'sql')),
        h_files($root . '/public', array('html', 'js')),
        h_files($root . '/tools', array('php'))
    ) as $f) {
        $index[strtolower(basename($f))][] = $f;
    }

    foreach (h_files($root . '/server', array('php')) as $f) {
        $cm = h_php_comments(h_read($f));
        if (!preg_match_all('~([A-Za-z0-9_\-./]+\.(?:php|sql|js|html)):(\d+)~', $cm, $m, PREG_SET_ORDER)) {
            continue;
        }
        foreach ($m as $hit) {
            $cited = $hit[1];
            $line  = (int) $hit[2];
            $base  = strtolower(basename($cited));
            $where = h_rel($f, $root);
            if (!isset($index[$base])) {
                $out[] = $where . ' cites "' . $hit[0] . '" (D-92: comments must not carry line numbers; the cited file was not found either)';
                continue;
            }
            // 후보가 여럿이면 경로 꼬리가 맞는 것을 고른다
            $target = null;
            foreach ($index[$base] as $cand) {
                if (substr(h_norm($cand), -strlen($cited)) === h_norm($cited)) {
                    $target = $cand;
                    break;
                }
            }
            if ($target === null) {
                $target = $index[$base][0];
            }
            $total = count(file($target, FILE_IGNORE_NEW_LINES));
            $tag = $line > $total ? ' - past end of file (' . $total . ' lines)' : '';
            $out[] = $where . ' cites "' . $hit[0] . '" -> ' . h_rel($target, $root) . $tag
                . ' (D-92: line numbers in comments go stale on the first edit; point with a function or section name)';
        }
    }
    sort($out);
    return $out;
}

// =====================================================================
// 7. `login_logs.result` 값 전수 — 4자 대조
//
// **단순 grep 은 여기서 틀린다.** 값이 세 경로로 만들어진다:
//   (a) `log_login('literal', …)` 직접 호출
//   (b) `log_login($x ? 'a' : 'b', …)` 삼항 — 인자 하나에 값이 둘
//   (c) `server/migrate.php` 의 `db_insert(DB_PREFIX . 'login_logs', …)` 직접 삽입
// (a) 만 세면 8~10 이 나오고 실제는 11 이다.
// =====================================================================

function ck_result_values_server(string $root): array
{
    $out = array();
    foreach (h_files($root . '/server', array('php')) as $f) {
        $code = h_php_code_only_keep_strings(h_read($f));
        // (a)+(b) log_login 의 첫 인자에 등장하는 모든 문자열 리터럴
        foreach (h_call_args($code, 'log_login') as $args) {
            $parts = h_split_args($args);
            if ($parts === array()) {
                continue;
            }
            if (preg_match_all('~[\'"]([a-z][a-z0-9_]*)[\'"]~', $parts[0], $m)) {
                foreach ($m[1] as $v) {
                    $out[$v] = true;
                }
            }
        }
        // (c) login_logs 테이블에 직접 INSERT 하는 경로
        if (strpos($code, "'login_logs'") !== false || strpos($code, 'T_LOGIN_LOGS') !== false) {
            if (preg_match_all('~[\'"]result[\'"]\s*=>\s*[\'"]([a-z][a-z0-9_]*)[\'"]~', $code, $m)) {
                foreach ($m[1] as $v) {
                    $out[$v] = true;
                }
            }
            // db_insert(..., [ 'result' 가 아니라 위치 인자로 들어가는 배열도 훑는다
            if (preg_match_all('~[\'"]((?:ok|success|logout|fail)[a-z0-9_]*)[\'"]~', $code, $m)) {
                foreach ($m[1] as $v) {
                    if (strpos($v, 'fail_migrate') === 0) {
                        $out[$v] = true;
                    }
                }
            }
        }
    }
    return array_keys($out);
}

/** 주석은 지우고 문자열은 **남긴다**. 값 수집에는 문자열이 필요하다. */
function h_php_code_only_keep_strings(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tk) {
        if (is_array($tk)) {
            if ($tk[0] === T_COMMENT || $tk[0] === T_DOC_COMMENT) {
                $out .= ' ';
                continue;
            }
            $out .= $tk[1];
            continue;
        }
        $out .= $tk;
    }
    return $out;
}

function ck_result_values_schema(string $root): array
{
    $f = $root . '/server/schema.sql';
    if (!is_file($f)) {
        return array();
    }
    $s = h_read($f);
    if (!preg_match('~`result`[^\n]*COMMENT\s*\'([^\']*)\'~', $s, $m)) {
        return array();
    }
    return array_values(array_filter(array_map('trim', explode('|', $m[1]))));
}

function ck_result_values_admin_api(string $root): array
{
    $f = $root . '/server/api/admin/logs.php';
    if (!is_file($f)) {
        return array();
    }
    $s = h_php_code_only_keep_strings(h_read($f));
    if (!preg_match('~\$results\s*=\s*(\[|array\()(.*?)(\]|\))\s*;~s', $s, $m)) {
        return array();
    }
    $out = array();
    if (preg_match_all('~[\'"]([a-z][a-z0-9_]*)[\'"]~', $m[2], $mm)) {
        $out = $mm[1];
    }
    return array_values(array_unique($out));
}

function ck_result_values_front(string $root): array
{
    $out = array();
    foreach (ck_front_files($root) as $f) {
        $s = h_read($f);
        if (preg_match_all('~[\'"]((?:success|logout|fail_[a-z_]+))[\'"]~', $s, $m)) {
            foreach ($m[1] as $v) {
                $out[$v] = true;
            }
        }
        if (preg_match_all('~value="((?:success|logout|fail_[a-z_]+))"~', $s, $m)) {
            foreach ($m[1] as $v) {
                $out[$v] = true;
            }
        }
    }
    return array_keys($out);
}

function chk_login_result_values(string $root): array
{
    $srv    = ck_result_values_server($root);
    $schema = ck_result_values_schema($root);
    $admin  = ck_result_values_admin_api($root);
    $front  = ck_result_values_front($root);
    $out    = array();

    if ($srv === array()) {
        return array('collected 0 login result values from server code - the collector is blind, not the code clean');
    }
    if ($schema === array()) {
        return array('could not read the login_logs.result COMMENT from server/schema.sql - the collector is blind');
    }

    foreach (array_diff($srv, $schema) as $v) {
        $out[] = 'server writes login result "' . $v . '" but schema.sql login_logs.result COMMENT does not list it';
    }
    foreach (array_diff($schema, $srv) as $v) {
        $out[] = 'schema.sql lists login result "' . $v . '" but no server path writes it (dead value)';
    }
    foreach (array_diff($srv, $admin) as $v) {
        $out[] = 'server writes login result "' . $v . '" but api/admin/logs.php $results does not accept it as a filter';
    }
    foreach (array_diff($srv, $front) as $v) {
        $out[] = 'server writes login result "' . $v . '" but no screen has a label or filter option for it';
    }
    sort($out);
    return $out;
}

// =====================================================================
// 8. 마이그레이션 DDL 가드 — 가드 없는 DDL 은 러너가 파일 전체를 거부한다
// =====================================================================

function chk_migration_guards(string $root): array
{
    $out = array();
    $dir = $root . '/server/migrations';
    $files = h_files($dir, array('sql'));
    if ($files === array() && is_dir($dir)) {
        return array();   // 스키마를 안 바꾼 릴리스. 정상
    }
    foreach ($files as $f) {
        $s = h_read($f);
        $lines = explode("\n", $s);
        $pendingGuard = false;
        $n = 0;
        foreach ($lines as $ln) {
            $tl = ltrim($ln);
            if (strpos($tl, '@skip-if') !== false) {
                $pendingGuard = true;
                continue;
            }
            if ($tl === '' || strncmp($tl, '--', 2) === 0) {
                continue;
            }
            if (preg_match('~^(ALTER|CREATE|DROP|RENAME|TRUNCATE)\s~i', $tl) === 1) {
                $n++;
                if (!$pendingGuard) {
                    $out[] = basename($f) . ' has a DDL statement (' . strtoupper(substr($tl, 0, 6))
                        . '...) with no "-- @skip-if:" guard above it; the runner rejects the whole file';
                }
                $pendingGuard = false;
            }
        }
        if ($n === 0) {
            $out[] = basename($f) . ' contains no DDL at all - is it an empty migration?';
        }
    }
    sort($out);
    return array_values(array_unique($out));
}

// =====================================================================
// 9. `.htaccess` 규칙 존재
//
// 잡는 것: 규칙이 파일에서 사라진 것.
// **못 잡는 것: Apache 가 실제로 그 헤더를 내보내는지.** `AllowOverride None`
// 이나 nginx 에서는 파일 통째로 무시된다. 여기는 테스트로 메울 수 없다 —
// 배포 후 `curl -I` 확인이 계속 필요하다.
// =====================================================================

function chk_htaccess_rules(string $root): array
{
    $want = array(
        'public/.htaccess' => array(
            'Options -Indexes',
            'X-Content-Type-Options',
            'X-Frame-Options',
            'Referrer-Policy',
            'Content-Security-Policy',
            'X-Powered-By',
            'max-age=31536000, immutable',
            'no-store',
        ),
        'server/.htaccess' => array(
            'Options -Indexes',
        ),
        'tools/.htaccess' => array(
            'denied',
        ),
        'examples/.htaccess' => array(
            'no-store',
        ),
    );
    $out = array();
    foreach ($want as $rel => $needles) {
        $p = $root . '/' . $rel;
        if (!is_file($p)) {
            $out[] = $rel . ' is missing';
            continue;
        }
        $s = h_read($p);
        foreach ($needles as $nd) {
            if (strpos($s, $nd) === false) {
                $out[] = $rel . ' no longer contains "' . $nd . '"';
            }
        }
    }
    sort($out);
    return $out;
}

// =====================================================================
// 10·11. 생산자 ↔ 소비자 배선 (D-172 / D-181 규약 1)
//
// 10 = 서버가 만드는데 아무 화면도 언급하지 않는 응답 필드 (소비자 미배정)
// 11 = 화면이 읽는데 어느 서버 응답에도 없는 필드 (undefined 읽기)
//
// **못 잡는 것:** 이름은 맞는데 **의미가 다른** 배선, 그리고 런타임에 조립되는
// 응답(`json_out($out)`). 후자는 `chk_unanalyzable_responses()` 가 따로 센다 —
// 조용히 "키 0개"로 접히지 않게.
// =====================================================================

/** @return array key => [파일…] */
function ck_response_keys(string $root): array
{
    $keys = array();
    foreach (h_files($root . '/server/api', array('php')) as $f) {
        $s = h_php_code_only_keep_strings(h_read($f));
        foreach (h_call_args($s, 'json_out') as $a) {
            $parts = h_split_args($a);
            if ($parts === array()) {
                continue;
            }
            $isLit = false;
            foreach (h_array_top_keys($parts[0], $isLit) as $k) {
                $keys[$k][] = h_rel($f, $root);
            }
        }
    }
    return $keys;
}

/** 정적으로 shape 을 알 수 없는 `json_out()` 호출부. 반환값은 파일 목록. */
function chk_unanalyzable_responses(string $root): array
{
    $out = array();
    foreach (h_files($root . '/server/api', array('php')) as $f) {
        $s = h_php_code_only_keep_strings(h_read($f));
        foreach (h_call_args($s, 'json_out') as $a) {
            $parts = h_split_args($a);
            if ($parts === array()) {
                continue;
            }
            $isLit = false;
            h_array_top_keys($parts[0], $isLit);
            if (!$isLit) {
                $out[] = h_rel($f, $root) . ' :: json_out(' . substr(preg_replace('/\s+/', ' ', $parts[0]), 0, 40) . ')';
            }
        }
    }
    sort($out);
    return array_values(array_unique($out));
}

/**
 * 정적으로 shape 을 알 수 없는 호출부의 **최선 추정 shape.**
 *
 * `chk_unanalyzable_responses()` 는 "몇 군데가 안 보인다" 만 세고 **무엇이 안 보이는지는
 * 말하지 않는다.** 그래서 NOTE 를 읽은 사람이 손으로 확인할 대상을 찾을 수가 없다 —
 * `json_out($out)` 이라는 표현만 받으니 `$out` 이 어디서 조립됐는지부터 다시 찾아야 한다.
 * NOTE 는 종료코드에 안 들어가므로, **안내가 부실하면 그 NOTE 는 없는 것과 같다.**
 *
 * 그래서 같은 파일의 `return [ … ];` 리터럴에서 깊이 1 의 키를 긁어 온다.
 *
 * **이것은 정본 shape 이 아니라 안내용 추정이다.** 조건 분기로 키가 갈리거나 `+=` 로
 * 덧붙는 조립은 놓친다. 그래서 이 함수의 결과로 **판정하지 않는다** — 사람이 볼
 * 목록을 만들고, 판정은 호출부가 **손으로 못박은 기대 목록**과 대조해서 한다.
 *
 * @return string[] 정렬된 키 이름. 리터럴 `return` 이 없으면 빈 배열
 */
function ck_opaque_shape_hint(string $root, string $relFile): array
{
    $f = h_norm($root . '/' . ltrim($relFile, '/'));
    if (!is_file($f)) {
        return array();
    }
    $s = h_php_code_only_keep_strings(h_read($f));
    $keys = array();
    // `return [` / `return array(` 부터 균형이 맞는 닫힘까지 잘라 h_array_top_keys 에 넘긴다
    $off = 0;
    while (preg_match('~\breturn\s*(\[|array\s*\()~', $s, $m, PREG_OFFSET_CAPTURE, $off) === 1) {
        // `h_array_top_keys()` 는 소스가 `[` 또는 `array(` 로 **시작**해야 리터럴로 인정한다.
        // 그래서 슬라이스는 `array` 키워드부터 뜨고(`$sliceStart`), 균형은 여는 괄호부터
        // 센다(`$braceAt`). 이 둘을 같은 위치로 두면 긴 문법에서 조용히 키 0개가 된다 —
        // 티어 2 의 `t_50_opaque_shape.php` 가 그 방향을 실제로 잡았다.
        $sliceStart = $m[1][1];
        $isShort    = substr($s, $sliceStart, 1) === '[';
        $open       = $isShort ? '[' : '(';
        $close      = $isShort ? ']' : ')';
        $braceAt    = $isShort ? $sliceStart : strpos($s, '(', $sliceStart);
        if ($braceAt === false) {
            break;
        }
        $depth = 0;
        $end   = -1;
        $len   = strlen($s);
        for ($i = $braceAt; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === $open) {
                $depth++;
            } elseif ($c === $close) {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }
        if ($end === -1) {
            break;
        }
        $isLit = false;
        foreach (h_array_top_keys(substr($s, $sliceStart, $end - $sliceStart + 1), $isLit) as $k) {
            $keys[$k] = true;
        }
        $off = $end + 1;
    }
    $out = array_keys($keys);
    sort($out);
    return $out;
}

/**
 * @param array $allow 소비자가 없어도 되는 필드명. **호출부가 사유를 적는다.**
 *                     여기서 걸러야 하는 이유: 발견 문자열에는 생산자 파일 목록이
 *                     붙어 있어 호출부에서 `array_diff` 로 못 뺀다.
 */
function chk_unconsumed_response_fields(string $root, array $allow = array()): array
{
    $keys = ck_response_keys($root);
    if ($keys === array()) {
        return array('collected 0 response keys - the collector is blind, not the code clean');
    }
    $blob = ck_front_blob($root);
    $out = array();
    foreach ($keys as $k => $where) {
        if (in_array($k, $allow, true)) {
            continue;
        }
        if (preg_match('~[\'"`.\[]' . preg_quote($k, '~') . '\b~', $blob) === 1) {
            continue;
        }
        $out[] = $k . ' (from ' . implode(', ', array_values(array_unique($where))) . ')';
    }
    sort($out);
    return $out;
}

// =====================================================================
// 12. 테이블 상수 3자 한 벌 (D-151)
//   bootstrap.php 의 defined() 가드  ↔  config.sample.php  ↔  schema.sql 의 CREATE TABLE
//
// 셋이 갈라지면 업그레이드 직후 전 엔드포인트가 죽는다 — v1.4.0 이 실제로 그
// 위험을 문서화하면서 가드를 넣었다. 그 가드가 다음 스키마 변경에서 빠지는 것을 막는다.
// =====================================================================

function chk_table_constants(string $root): array
{
    $sample = $root . '/server/config.sample.php';
    $boot   = $root . '/server/core/bootstrap.php';
    $schema = $root . '/server/schema.sql';
    foreach (array($sample, $boot, $schema) as $p) {
        if (!is_file($p)) {
            return array('missing ' . h_rel($p, $root) . ' - cannot cross-check table constants');
        }
    }
    $out = array();

    // config.sample.php 의 T_* → 테이블 접미사
    $ss = h_read($sample);
    $inSample = array();
    if (preg_match_all('~define\(\s*\'(T_[A-Z_]+)\'\s*,\s*DB_PREFIX\s*\.\s*\'([A-Za-z0-9_]+)\'~', $ss, $m)) {
        foreach ($m[1] as $i => $c) {
            $inSample[$c] = $m[2][$i];
        }
    }
    // bootstrap.php 의 하위호환 가드
    $bs = h_read($boot);
    $inBoot = array();
    if (preg_match_all('~defined\(\s*\'(T_[A-Z_]+)\'\s*\)[^\n]*\n\s*define\(\s*\'\\1\'\s*,\s*DB_PREFIX\s*\.\s*\'([A-Za-z0-9_]+)\'~', $bs, $m)) {
        foreach ($m[1] as $i => $c) {
            $inBoot[$c] = $m[2][$i];
        }
    }
    // schema.sql 의 CREATE TABLE 접미사
    //
    // **`^` + `m` 으로 줄머리에 고정한다 — `server/migrations/lib.php` 의
    // `mig_parse_guard()`·`mig_table_ddl_from_schema()`·`mig_expected_schema()` 와 같은 이유다.**
    // 고정하지 않으면 `--` 주석 안에서 **인용된** `CREATE TABLE` 문자열이 정의로 세어진다.
    // 이 검사는 D-151 게이트다 — 세 곳(bootstrap 가드·config.sample 상수·schema DDL)이
    // 갈라지면 업그레이드 직후 전 엔드포인트가 죽는다. 그 게이트가 주석 한 줄로 뒤집힌다.
    //
    // 재현(임시 루트에 schema.sql 만 바꿔 넣고 이 함수를 직접 호출):
    //   1) 거짓 양성 — 머리에 `--   CREATE TABLE IF NOT EXISTS \`app_ulogin_ghost\` (` 주석을
    //      넣으면 정본 0건이 **1건**이 된다:
    //      `schema.sql creates table "ghost" but config.sample.php has no T_* constant for it`.
    //      계수도 17 → 18 로 늘었다.
    //   2) **거짓 음성(게이트가 죽는 방향)** — `app_ulogin_terms` DDL 블록을 통째로 지우면
    //      게이트가 옳게 1건을 낸다(`T_TERMS -> "terms" but schema.sql has no CREATE TABLE`).
    //      그런데 머리 주석이 그 이름을 인용해 두면 **0건으로 조용해진다.** 실제 드리프트를
    //      두고 "깨끗함"을 보고하는 것이므로 게이트로서 가장 나쁜 오류다.
    // 고정한 뒤 두 재현 모두 사라지고 정본의 정의 17개는 그대로 17개로 잡힌다.
    //
    // 대가: **들여쓴 `CREATE TABLE` 은 보이지 않는다.** `mig_expected_schema()` 가 감수한
    // 것과 같은 대가이고, 같은 파일을 읽는 두 파서가 같은 규약을 쓰는 것이 옳다 —
    // 정본 schema.sql 의 정의 17개는 전부 줄머리에 있다(들여쓴 정의 0건, 실측).
    $sc = h_read($schema);
    $inSchema = array();
    if (preg_match_all('~^CREATE TABLE(?: IF NOT EXISTS)?\s+`?([a-z_]+)`?~im', $sc, $m)) {
        foreach ($m[1] as $t) {
            $inSchema[$t] = true;
        }
    }
    if ($inSample === array() || $inSchema === array()) {
        return array('collected 0 table constants or 0 CREATE TABLE statements - the collector is blind');
    }
    // 접두사를 벗긴 이름으로 비교
    $prefix = 'app_ulogin_';
    if (preg_match('~define\(\s*\'DB_PREFIX\'\s*,\s*\'([a-z_]*)\'~', $ss, $m)) {
        $prefix = $m[1];
    }
    $schemaSuffix = array();
    foreach (array_keys($inSchema) as $t) {
        if (strncmp($t, $prefix, strlen($prefix)) === 0) {
            $schemaSuffix[substr($t, strlen($prefix))] = true;
        }
    }
    foreach ($inSample as $c => $suf) {
        if (!isset($schemaSuffix[$suf])) {
            $out[] = 'config.sample.php defines ' . $c . ' -> "' . $suf . '" but schema.sql has no CREATE TABLE for it';
        }
    }
    foreach (array_keys($schemaSuffix) as $suf) {
        if (!in_array($suf, array_values($inSample), true)) {
            $out[] = 'schema.sql creates table "' . $suf . '" but config.sample.php has no T_* constant for it';
        }
    }
    // bootstrap 가드는 sample 과 값이 같아야 한다
    foreach ($inBoot as $c => $suf) {
        if (isset($inSample[$c]) && $inSample[$c] !== $suf) {
            $out[] = 'bootstrap.php back-compat guard sets ' . $c . ' -> "' . $suf
                . '" but config.sample.php says "' . $inSample[$c] . '" (D-151: the two must match)';
        }
    }
    sort($out);
    return $out;
}

// =====================================================================
// 13. `tools/` 위임 — 이미 있는 게이트를 실제로 돌린다
//
// `php72_scan.php` 와 `bump_asset_version.php` 는 회귀 게이트로 쓸 수 있게
// 만들어져 있는데 **아무것도 그것을 자동으로 돌리지 않는다.** 사람이 릴리스
// 절차에서 기억해야 한다. 여기가 그 호출부다 — 새 코드가 아니라 호출이다.
// =====================================================================

/** @return array [code, output] */
function ck_tool(string $root, string $tool, string $args): array
{
    $p = $root . '/tools/' . $tool;
    if (!is_file($p)) {
        return array(-1, 'tools/' . $tool . ' not found');
    }
    $code = 0;
    $out = h_run(h_q(h_php_bin()) . ' -n ' . h_q($p) . ' ' . $args, $code);
    return array($code, $out);
}
