<?php
declare(strict_types=1);

/**
 * tests/lib/harness.php — 파일 순회·소스 추출·경로
 *
 * PHP 7.2 호환. 의존성 0.
 *
 * ─────────────────────────────────────────────────────────────────────
 * **`glob()` 을 쓰지 않는다.** 이 저장소가 놓이는 경로에 대괄호가 들어가는
 * 사례가 실제로 있고(`.../[일반] .../`), `glob()` 은 패턴의 `[...]` 를 문자
 * 클래스로 해석해 **오류 없이 0건을 반환한다.** 조용히 실패하는 종류라
 * "0건"을 "대상이 없다"로 오독하게 된다. `tools/bump_asset_version.php` 와
 * 같이 `scandir()` + 수동 필터를 쓴다.
 * ─────────────────────────────────────────────────────────────────────
 */

/** 설치 루트 = `server/` 와 `public/` 을 가진 폴더. 이 파일의 조부모다. */
function h_root(): string
{
    return dirname(__DIR__, 2);
}

function h_tests_dir(): string
{
    return dirname(__DIR__);
}

/** 경로를 슬래시로 정규화. 비교·출력에 쓴다. */
function h_norm(string $p): string
{
    return str_replace('\\', '/', $p);
}

/** 루트 기준 상대 경로. 리포트 출력용. */
function h_rel(string $abs, ?string $base = null): string
{
    $base = h_norm($base === null ? h_root() : $base) . '/';
    $abs  = h_norm($abs);
    if (strncmp($abs, $base, strlen($base)) === 0) {
        return substr($abs, strlen($base));
    }
    return $abs;
}

/**
 * 재귀 파일 목록. **glob 금지 규약의 유일한 진입점.**
 *
 * @param string   $dir  대상 디렉토리 (없으면 빈 배열)
 * @param array    $exts 확장자 화이트리스트 (소문자, 점 없이). 빈 배열이면 전부
 * @param array    $skipDirs 이름이 일치하면 통째로 건너뛸 디렉토리
 * @return string[] 절대 경로. 정렬됨 (실행 순서를 결정적으로 만든다)
 *
 * `fixtures` 가 기본 제외에 있는 이유: `tests/fixtures/positive/` 는 **일부러
 * 깨뜨린 파일**을 담는다(문법 오류, 죽은 링크, 없는 함수 인용). 그것을 실제
 * 코드와 같이 훑으면 티어 1 이 자기 픽스처를 결함으로 보고한다.
 * 자기검증은 픽스처 루트를 `$dir` 로 **직접 지목**해 들어가므로 영향받지 않는다 —
 * 제외는 이름이 일치하는 디렉토리를 *통과*할 때만 걸린다.
 */
function h_files(string $dir, array $exts = array(), array $skipDirs = array('.git', 'vendor', 'node_modules', 'storage', 'fixtures')): array
{
    $out = array();
    if (!is_dir($dir)) {
        return $out;
    }
    $stack = array($dir);
    while ($stack !== array()) {
        $cur = array_pop($stack);
        $ent = @scandir($cur);
        if ($ent === false) {
            continue;
        }
        foreach ($ent as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = $cur . '/' . $e;
            if (is_dir($p)) {
                if (in_array($e, $skipDirs, true)) {
                    continue;
                }
                $stack[] = $p;
                continue;
            }
            if ($exts !== array()) {
                $x = strtolower((string) pathinfo($e, PATHINFO_EXTENSION));
                if (!in_array($x, $exts, true)) {
                    continue;
                }
            }
            $out[] = h_norm($p);
        }
    }
    sort($out);
    return $out;
}

/** 파일 내용. 못 읽으면 빈 문자열이 아니라 예외 — 조용한 0건을 만들지 않는다. */
function h_read(string $path): string
{
    $s = @file_get_contents($path);
    if ($s === false) {
        throw new RuntimeException('cannot read ' . $path);
    }
    return $s;
}

/**
 * PHP 소스에서 **주석과 문자열을 제거**한 코드만 남긴다.
 * 토크나이저를 쓴다 — 정규식으로는 문자열 안의 `//` 와 주석 안의 따옴표를 구분할 수 없다.
 */
function h_php_code_only(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tk) {
        if (is_array($tk)) {
            $id = $tk[0];
            if ($id === T_COMMENT || $id === T_DOC_COMMENT
                || $id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE) {
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

/** PHP 소스의 **주석만** 이어붙인다. 팬텀 식별자 검사용. */
function h_php_comments(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tk) {
        if (is_array($tk) && ($tk[0] === T_COMMENT || $tk[0] === T_DOC_COMMENT)) {
            $out .= $tk[1] . "\n";
        }
    }
    return $out;
}

/** PHP 소스의 모든 문자열 리터럴 값(따옴표 벗김). */
function h_php_strings(string $src): array
{
    $out = array();
    foreach (token_get_all($src) as $tk) {
        if (is_array($tk) && $tk[0] === T_CONSTANT_ENCAPSED_STRING) {
            $v = $tk[1];
            $q = $v[0];
            $inner = substr($v, 1, -1);
            if ($q === "'") {
                $inner = str_replace(array("\\'", '\\\\'), array("'", '\\'), $inner);
            }
            $out[] = $inner;
        }
    }
    return $out;
}

/** JS/HTML 에서 한 줄 주석·블록 주석을 지운다 (거친 근사. URL 의 `//` 를 보호한다). */
function h_js_strip_comments(string $src): string
{
    $src = preg_replace('~/\*.*?\*/~s', ' ', $src);
    $src = preg_replace('~(^|[^:"\'`\\\\])//[^\n]*~m', '$1', (string) $src);
    return (string) $src;
}

/**
 * 균형 잡힌 괄호 안의 내용을 뽑는다. `json_out(` 처럼 인자를 통째로 봐야 할 때.
 *
 * @param string $s      전체 소스
 * @param int    $openAt `(` 의 위치
 * @return string|null 괄호 안 문자열. 짝이 안 맞으면 null
 */
function h_balanced(string $s, int $openAt): ?string
{
    $n = strlen($s);
    if ($openAt >= $n || $s[$openAt] !== '(') {
        return null;
    }
    $d = 0;
    for ($i = $openAt; $i < $n; $i++) {
        $c = $s[$i];
        if ($c === '(') {
            $d++;
        } elseif ($c === ')') {
            $d--;
            if ($d === 0) {
                return substr($s, $openAt + 1, $i - $openAt - 1);
            }
        }
    }
    return null;
}

/**
 * 배열 리터럴의 **최상위** 문자열 키를 뽑는다. 중첩 배열 안의 키는 무시한다.
 * `['ok' => 1, 'items' => ['id' => 2]]` → ['ok', 'items']
 *
 * 리터럴이 아니면(변수·함수 호출) 빈 배열. **그것을 "키가 없다"로 읽으면 안 된다** —
 * 호출부가 판단할 수 있게 `$isLiteral` 을 함께 돌려준다.
 */
function h_array_top_keys(string $arrSrc, ?bool &$isLiteral = null): array
{
    $isLiteral = false;
    $s = trim($arrSrc);
    if ($s === '' || ($s[0] !== '[' && strncasecmp($s, 'array(', 6) !== 0)) {
        return array();
    }
    $isLiteral = true;
    // 토크나이저로 깊이를 세면서 깊이 1 의 `'key' =>` 만 수집
    $tokens = token_get_all('<?php ' . $s . ';');
    $depth = 0;
    $keys = array();
    $pendingKey = null;
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $tk = $tokens[$i];
        if (is_string($tk)) {
            if ($tk === '[' || $tk === '(') {
                $depth++;
                continue;
            }
            if ($tk === ']' || $tk === ')') {
                $depth--;
                $pendingKey = null;
                continue;
            }
            if ($tk === ',') {
                $pendingKey = null;
            }
            continue;
        }
        if ($tk[0] === T_ARRAY) {
            continue;   // 뒤따르는 '(' 가 깊이를 올린다
        }
        if ($tk[0] === T_WHITESPACE || $tk[0] === T_COMMENT || $tk[0] === T_DOC_COMMENT) {
            continue;
        }
        if ($tk[0] === T_CONSTANT_ENCAPSED_STRING) {
            $pendingKey = trim($tk[1], "'\"");
            continue;
        }
        if ($tk[0] === T_DOUBLE_ARROW) {
            if ($pendingKey !== null && $depth === 1) {
                $keys[] = $pendingKey;
            }
            $pendingKey = null;
            continue;
        }
        $pendingKey = null;
    }
    return array_values(array_unique($keys));
}

/**
 * 소스에서 `$fn(` 호출의 첫 인자를 전부 뽑는다.
 * @return array 각 원소 = 괄호 안 전체 문자열
 */
function h_call_args(string $src, string $fn): array
{
    $out = array();
    $off = 0;
    $len = strlen($fn);
    while (($p = strpos($src, $fn, $off)) !== false) {
        $off = $p + $len;
        // 앞 글자가 식별자면 다른 함수다 (`x_json_out` 이 `json_out` 에 걸리지 않게)
        if ($p > 0 && preg_match('/[A-Za-z0-9_$>]/', $src[$p - 1]) === 1) {
            continue;
        }
        $q = $p + $len;
        while ($q < strlen($src) && ($src[$q] === ' ' || $src[$q] === "\t" || $src[$q] === "\n" || $src[$q] === "\r")) {
            $q++;
        }
        if ($q >= strlen($src) || $src[$q] !== '(') {
            continue;
        }
        $inner = h_balanced($src, $q);
        if ($inner !== null) {
            $out[] = $inner;
        }
    }
    return $out;
}

/** 인자 목록을 최상위 콤마로 쪼갠다. */
function h_split_args(string $args): array
{
    $out = array();
    $d = 0;
    $cur = '';
    $n = strlen($args);
    $q = '';
    for ($i = 0; $i < $n; $i++) {
        $c = $args[$i];
        if ($q !== '') {
            $cur .= $c;
            if ($c === '\\' && $i + 1 < $n) {
                $cur .= $args[++$i];
                continue;
            }
            if ($c === $q) {
                $q = '';
            }
            continue;
        }
        if ($c === "'" || $c === '"') {
            $q = $c;
            $cur .= $c;
            continue;
        }
        if ($c === '(' || $c === '[' || $c === '{') {
            $d++;
        } elseif ($c === ')' || $c === ']' || $c === '}') {
            $d--;
        }
        if ($c === ',' && $d === 0) {
            $out[] = trim($cur);
            $cur = '';
            continue;
        }
        $cur .= $c;
    }
    if (trim($cur) !== '') {
        $out[] = trim($cur);
    }
    return $out;
}

/** 외부 명령 실행. stdout+stderr 와 종료코드를 함께 준다. */
function h_run(string $cmd, ?int &$code = null): string
{
    $out = array();
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return implode("\n", $out);
}

/** 이 러너를 돌리는 PHP 바이너리. 자식 프로세스에 같은 것을 쓴다. */
function h_php_bin(): string
{
    if (defined('PHP_BINARY') && PHP_BINARY !== '') {
        return PHP_BINARY;
    }
    return 'php';
}

/** 공백이 든 경로를 셸에 넘길 때. */
function h_q(string $s): string
{
    if (DIRECTORY_SEPARATOR === '\\') {
        return '"' . str_replace('"', '""', $s) . '"';
    }
    return escapeshellarg($s);
}
