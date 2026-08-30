<?php
declare(strict_types=1);

/**
 * php72_scan.php — PHP 7.2 호환성 정적 스캐너 (token_get_all 기반)
 * ---------------------------------------------------------------------
 * 리더 판정 D-58: "PHP 8.1 의 `php -l` 은 7.4 전용 문법도 통과시키므로 무용하다.
 *                 token_get_all() 을 쓰는 자체 스캐너를 만들어라."
 *
 * **배포물이 아니다.** 저장소의 `tools/` 에 들어 있지만 **서버에 올리지 마라.**
 * 릴리스를 준비하는 사람이 자기 PC 에서 돌리는 도구다.
 *
 * 실행 (저장소 루트에서):
 *   php tools/php72_scan.php <경로|파일> [...]        # 기본: ERROR 만 종료코드에 반영
 *   php tools/php72_scan.php <경로> --notes-are-errors # NOTE 도 종료코드에 반영
 *   php tools/php72_scan.php <경로> --list-funcs       # 호출하는 전역 함수 전체 목록 덤프
 *   php tools/php72_scan.php <경로> --exclude=public/assets
 *
 *   이 저장소에 대한 표준 사용:
 *     php tools/php72_scan.php server tools
 *
 * 종료코드: 0 = 검출 0건, 1 = 검출 있음, 2 = 사용법/파싱 오류
 *
 * 억제:
 *   같은 줄 또는 바로 윗줄에 `php72-scan: ignore <rule>` 주석이 있으면 그 검출을 건너뛴다.
 *   버전 분기(PHP_VERSION_ID >= 70300)로 감싼 의도적 7.3+ 경로에만 쓴다.
 *
 * 이 스캐너 자체는 PHP 7.2 문법으로 작성했다. 다만 8.0+ 문법을 검출하려면
 * **PHP 8.x 로 실행**해야 한다(7.2 의 token_get_all 은 8.0 문법에서 파싱 실패한다).
 */

// ---------------------------------------------------------------------
// 0. 인자
// ---------------------------------------------------------------------

$paths            = [];
$excludes         = [];
$notesAreErrors   = false;
$listFuncs        = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--notes-are-errors') { $notesAreErrors = true; continue; }
    if ($arg === '--list-funcs')       { $listFuncs      = true; continue; }
    if (strpos($arg, '--exclude=') === 0) { $excludes[] = str_replace('\\', '/', substr($arg, 10)); continue; }
    if (strpos($arg, '--') === 0) { fwrite(STDERR, "unknown option: $arg\n"); exit(2); }
    $paths[] = $arg;
}
if (!$paths) {
    fwrite(STDERR, "usage: php php72_scan.php <path> [...] [--exclude=frag] [--notes-are-errors] [--list-funcs]\n");
    exit(2);
}

// ---------------------------------------------------------------------
// 1. 7.2 이후에 추가된 전역 함수 / 상수 / 클래스
// ---------------------------------------------------------------------

$FUNCS_AFTER_72 = [
    // 7.3
    'array_key_first' => '7.3', 'array_key_last' => '7.3', 'hrtime' => '7.3',
    'is_countable' => '7.3', 'gc_status' => '7.3', 'net_get_interfaces' => '7.3',
    'openssl_pkey_derive' => '7.3', 'normalizer_get_raw_decomposition' => '7.3',
    'ldap_exop_refresh' => '7.3', 'socket_wsaprotocol_info_export' => '7.3',
    'socket_wsaprotocol_info_import' => '7.3', 'socket_wsaprotocol_info_release' => '7.3',
    'fpm_get_status' => '7.3',
    // 7.4
    'mb_str_split' => '7.4', 'get_mangled_object_vars' => '7.4', 'password_algos' => '7.4',
    'openssl_x509_verify' => '7.4', 'pcntl_unshare' => '7.4', 'imagecreatefromtga' => '7.4',
    'sapi_windows_set_ctrl_handler' => '7.4', 'sapi_windows_generate_ctrl_event' => '7.4',
    'mb_convert_encoding_all' => '7.4',
    // 8.0
    'str_contains' => '8.0', 'str_starts_with' => '8.0', 'str_ends_with' => '8.0',
    'fdiv' => '8.0', 'get_debug_type' => '8.0', 'preg_last_error_msg' => '8.0',
    'get_resource_id' => '8.0',
    // 8.1
    'array_is_list' => '8.1', 'enum_exists' => '8.1', 'fsync' => '8.1', 'fdatasync' => '8.1',
    'mysqli_fetch_column' => '8.1', 'sodium_crypto_stream_xchacha20' => '8.1',
    // 8.2
    'mysqli_execute_query' => '8.2', 'curl_upkeep' => '8.2', 'ini_parse_quantity' => '8.2',
    'memory_reset_peak_usage' => '8.2', 'openssl_cipher_key_length' => '8.2',
    // 8.3
    'json_validate' => '8.3', 'mb_str_pad' => '8.3', 'str_increment' => '8.3',
    'str_decrement' => '8.3', 'stream_context_set_options' => '8.3',
    'ldap_connect_wallet' => '8.3',
    // 8.4
    'array_find' => '8.4', 'array_find_key' => '8.4', 'array_any' => '8.4', 'array_all' => '8.4',
    'mb_trim' => '8.4', 'mb_ltrim' => '8.4', 'mb_rtrim' => '8.4',
    'mb_ucfirst' => '8.4', 'mb_lcfirst' => '8.4', 'fpow' => '8.4',
    'request_parse_body' => '8.4', 'http_get_last_response_headers' => '8.4',
    'bcdivmod' => '8.4', 'grapheme_str_split' => '8.4', 'pcntl_waitid' => '8.4',
];

$CONSTS_AFTER_72 = [
    // 7.3
    'JSON_THROW_ON_ERROR' => '7.3', 'PASSWORD_ARGON2ID' => '7.3',
    'MB_CASE_FOLD' => '7.3', 'MB_CASE_UPPER_SIMPLE' => '7.3', 'MB_CASE_LOWER_SIMPLE' => '7.3',
    'MB_CASE_TITLE_SIMPLE' => '7.3', 'MB_CASE_FOLD_SIMPLE' => '7.3',
    'FILTER_SANITIZE_ADD_SLASHES' => '7.3', 'LDAP_EXOP_REFRESH' => '7.3',
    // 7.4
    'PASSWORD_ARGON2_PROVIDER' => '7.4', 'MB_ONIGURUMA_VERSION' => '7.4',
    'PHP_WINDOWS_EVENT_CTRL_C' => '7.4', 'PHP_WINDOWS_EVENT_CTRL_BREAK' => '7.4',
    'T_FN' => '7.4', 'T_COALESCE_EQUAL' => '7.4',
    // 8.0
    'FILTER_VALIDATE_BOOL' => '8.0', 'T_NULLSAFE_OBJECT_OPERATOR' => '8.0',
    'T_MATCH' => '8.0', 'T_NAME_QUALIFIED' => '8.0', 'T_NAME_FULLY_QUALIFIED' => '8.0',
    'T_NAME_RELATIVE' => '8.0', 'T_ATTRIBUTE' => '8.0',
    // 8.1
    'T_ENUM' => '8.1', 'T_READONLY' => '8.1', 'MYSQLI_REFRESH_REPLICA' => '8.1',
    // 8.2
    'FILTER_FLAG_GLOBAL_RANGE' => '8.2',
    // 8.3
    'ROUND_HALF_UP_BC' => '8.4',
];

$CLASSES_AFTER_72 = [
    'JsonException' => '7.3',
    'WeakReference' => '7.4', 'FFI' => '7.4', 'FFI\\CData' => '7.4',
    'WeakMap' => '8.0', 'Attribute' => '8.0', 'Stringable' => '8.0',
    'ValueError' => '8.0', 'UnhandledMatchError' => '8.0',
    'ReflectionUnionType' => '8.0', 'PhpToken' => '8.0',
    'Fiber' => '8.1', 'ReturnTypeWillChange' => '8.1',
    'ReflectionEnum' => '8.1', 'ReflectionIntersectionType' => '8.1',
    'AllowDynamicProperties' => '8.2', 'SensitiveParameter' => '8.2',
];

/** 인자 형태가 7.3+/7.4+ 를 요구하는 시그니처 변경. [함수 => [인자인덱스0base, 요구버전, 설명]] */
$ARG_ARRAY_SIGNATURES = [
    'setcookie'                 => [2, '7.3', 'setcookie() 3번째 인자 옵션 배열'],
    'setrawcookie'              => [2, '7.3', 'setrawcookie() 3번째 인자 옵션 배열'],
    'session_set_cookie_params' => [0, '7.3', 'session_set_cookie_params() 배열 형태'],
    'strip_tags'                => [1, '7.4', 'strip_tags() 2번째 인자 배열'],
    'proc_open'                 => [0, '7.4', 'proc_open() 1번째 인자 배열'],
];

/** 인자 개수가 늘어나면 상위 버전을 요구하는 함수. [함수 => [최대허용개수, 요구버전]] */
$ARG_COUNT_LIMITS = [
    'preg_replace_callback'       => [4, '7.4'],
    'preg_replace_callback_array' => [3, '7.4'],
    'array_key_exists'            => [2, '7.2'],
];

/** 인자 개수가 너무 적으면 상위 버전을 요구하는 함수. [함수 => [최소필요개수, 요구버전]] */
$ARG_COUNT_MINS = [
    'array_merge'           => [1, '7.4'],
    'array_merge_recursive' => [1, '7.4'],
    'array_push'            => [2, '7.3'],
    'array_unshift'         => [2, '7.3'],
];

// ---------------------------------------------------------------------
// 2. 토큰 ID 헬퍼 — 구버전 PHP 에서 실행해도 죽지 않게 defined() 로 감싼다
// ---------------------------------------------------------------------

/** @return int 없는 토큰이면 절대 매치되지 않는 음수 */
function tid(string $name): int
{
    static $seq = -1000;
    static $miss = [];
    if (defined($name)) {
        return (int) constant($name);
    }
    if (!isset($miss[$name])) {
        $miss[$name] = $seq--;
    }
    return $miss[$name];
}

$T_FN        = tid('T_FN');
$T_COAL_EQ   = tid('T_COALESCE_EQUAL');
$T_NULLSAFE  = tid('T_NULLSAFE_OBJECT_OPERATOR');
$T_MATCH     = tid('T_MATCH');
$T_ATTRIBUTE = tid('T_ATTRIBUTE');
$T_ENUM      = tid('T_ENUM');
$T_READONLY  = tid('T_READONLY');
$T_NAME_Q    = tid('T_NAME_QUALIFIED');
$T_NAME_FQ   = tid('T_NAME_FULLY_QUALIFIED');
$T_NAME_REL  = tid('T_NAME_RELATIVE');

// ---------------------------------------------------------------------
// 3. 파일 수집
// ---------------------------------------------------------------------

/** @return string[] */
function collect_php_files(array $paths, array $excludes): array
{
    $out = [];
    foreach ($paths as $p) {
        if (is_file($p)) { $out[] = $p; continue; }
        if (!is_dir($p)) { fwrite(STDERR, "not found: $p\n"); exit(2); }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if (!$f->isFile()) { continue; }
            if (strtolower($f->getExtension()) !== 'php') { continue; }
            $out[] = $f->getPathname();
        }
    }
    $keep = [];
    foreach ($out as $f) {
        $norm = str_replace('\\', '/', $f);
        if (strpos($norm, '/vendor/') !== false || strpos($norm, '/node_modules/') !== false) { continue; }
        $skip = false;
        foreach ($excludes as $ex) {
            if ($ex !== '' && strpos($norm, $ex) !== false) { $skip = true; break; }
        }
        if (!$skip) { $keep[] = $norm; }
    }
    sort($keep);
    return array_values(array_unique($keep));
}

// ---------------------------------------------------------------------
// 4. 스캐너
// ---------------------------------------------------------------------

class Finding
{
    public $file; public $line; public $rule; public $requires; public $severity; public $detail;
    public function __construct(string $file, int $line, string $rule, string $requires, string $severity, string $detail = '')
    {
        $this->file = $file; $this->line = $line; $this->rule = $rule;
        $this->requires = $requires; $this->severity = $severity; $this->detail = $detail;
    }
}

class Scanner
{
    /** @var Finding[] */
    public $findings = [];
    /** @var array<string,int> */
    public $globalFuncs = [];

    private $file;
    /** @var array 유의 토큰만: [id, text, line] */
    private $t = [];
    /** @var array<int,string> line => 그 줄과 윗줄의 주석 텍스트 */
    private $ignores = [];
    private $maps;

    public function __construct(array $maps) { $this->maps = $maps; }

    private function add(int $line, string $rule, string $requires, string $severity, string $detail = ''): void
    {
        // 억제 주석 확인 (같은 줄 또는 위 2줄)
        for ($l = $line; $l >= $line - 2 && $l > 0; $l--) {
            if (isset($this->ignores[$l]) && strpos($this->ignores[$l], 'ignore ' . $rule) !== false) {
                return;
            }
        }
        $this->findings[] = new Finding($this->file, $line, $rule, $requires, $severity, $detail);
    }

    public function scanFile(string $file): void
    {
        $this->file = $file;
        $src = (string) file_get_contents($file);
        try {
            $raw = token_get_all($src);
        } catch (Throwable $e) {
            $this->findings[] = new Finding($file, 0, 'PARSE_ERROR', '-', 'ERROR', $e->getMessage());
            return;
        }

        $this->t = [];
        $this->ignores = [];
        foreach ($raw as $tok) {
            if (is_array($tok)) {
                $id = $tok[0]; $text = $tok[1]; $line = $tok[2];
                if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                    if (strpos($text, 'php72-scan:') !== false) {
                        $n = substr_count($text, "\n");
                        for ($k = 0; $k <= $n; $k++) {
                            $this->ignores[$line + $k] = ($this->ignores[$line + $k] ?? '') . ' ' . $text;
                        }
                    }
                    continue;
                }
                if ($id === T_WHITESPACE) { continue; }
                // PHP 8.1 은 '&' 를 T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG /
                // T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG 로 쪼갠다.
                // 하위 버전과 동일하게 단일 문자 토큰으로 정규화한다.
                if ($id === tid('T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG')
                    || $id === tid('T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG')) {
                    $this->t[] = [null, '&', $line];
                    continue;
                }
                $this->t[] = [$id, $text, $line];
            } else {
                $this->t[] = [null, $tok, $this->lastLine()];
            }
        }
        // 단일문자 토큰의 라인번호 보정 (token_get_all 은 문자 토큰에 라인을 안 준다)
        $this->fixCharLines($raw);

        $this->ruleTokens();
        $this->ruleBrackets();
        $this->ruleDeclarations();
        $this->ruleNames();
        $this->ruleHeredoc($raw);
        $this->ruleInheritance();
    }

    private function lastLine(): int
    {
        $n = count($this->t);
        return $n > 0 ? (int) $this->t[$n - 1][2] : 1;
    }

    /** 문자 토큰에 정확한 라인번호를 채운다 */
    private function fixCharLines(array $raw): void
    {
        $line = 1; $idx = 0;
        foreach ($raw as $tok) {
            if (is_array($tok)) {
                $isSig = !in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
                if ($isSig) { $this->t[$idx][2] = $tok[2]; $idx++; }
                $line = $tok[2] + substr_count($tok[1], "\n");
            } else {
                $this->t[$idx][2] = $line; $idx++;
            }
        }
    }

    private function id(int $i): ?int   { return isset($this->t[$i]) ? $this->t[$i][0] : null; }
    private function tx(int $i): string { return isset($this->t[$i]) ? (string) $this->t[$i][1] : ''; }
    private function ln(int $i): int    { return isset($this->t[$i]) ? (int) $this->t[$i][2] : 0; }
    private function isChar(int $i, string $c): bool { return $this->id($i) === null && $this->tx($i) === $c; }

    // -----------------------------------------------------------------
    // 규칙 A. 단일 토큰으로 판정되는 것
    // -----------------------------------------------------------------
    private function ruleTokens(): void
    {
        global $T_FN, $T_COAL_EQ, $T_NULLSAFE, $T_MATCH, $T_ATTRIBUTE, $T_ENUM, $T_READONLY;
        $n = count($this->t);
        for ($i = 0; $i < $n; $i++) {
            $id = $this->id($i); $tx = $this->tx($i); $ln = $this->ln($i);
            if ($id === null) { continue; }

            if ($id === $T_FN && strtolower($tx) === 'fn') {
                $this->add($ln, 'ARROW_FN', '7.4', 'ERROR', '화살표 함수 fn()');
            } elseif ($id === $T_COAL_EQ) {
                $this->add($ln, 'NULL_COALESCE_ASSIGN', '7.4', 'ERROR', '??= 연산자');
            } elseif ($id === $T_NULLSAFE) {
                $this->add($ln, 'NULLSAFE_OPERATOR', '8.0', 'ERROR', '널세이프 ?->');
            } elseif ($id === $T_MATCH && strtolower($tx) === 'match' && $this->isChar($i + 1, '(')
                      && $this->id($i - 1) !== T_OBJECT_OPERATOR && $this->id($i - 1) !== T_DOUBLE_COLON
                      && $this->id($i - 1) !== T_FUNCTION) {
                $this->add($ln, 'MATCH_EXPR', '8.0', 'ERROR', 'match 식');
            } elseif ($id === $T_ATTRIBUTE) {
                $this->add($ln, 'ATTRIBUTE', '8.0', 'ERROR', '어트리뷰트 #[...]');
            } elseif ($id === $T_ENUM && strtolower($tx) === 'enum' && $this->id($i + 1) === T_STRING) {
                $this->add($ln, 'ENUM', '8.1', 'ERROR', 'enum 선언');
            } elseif ($id === $T_READONLY && strtolower($tx) === 'readonly') {
                $this->add($ln, 'READONLY', '8.1', 'ERROR', 'readonly 프로퍼티');
            } elseif (($id === T_LNUMBER || $id === T_DNUMBER) && strpos($tx, '_') !== false) {
                $this->add($ln, 'NUMERIC_SEPARATOR', '7.4', 'ERROR', '숫자 구분자 ' . $tx);
            } elseif ($id === T_VARIABLE && $this->id($i + 1) === T_DOUBLE_COLON && $this->id($i + 2) === T_CLASS) {
                $this->add($ln, 'OBJECT_CLASS_CONST', '8.0', 'ERROR', '$obj::class');
            } elseif ($id === T_THROW) {
                $p = $this->id($i - 1); $pt = $this->tx($i - 1);
                $exprCtx = in_array($pt, ['=', '?', ':', ',', '(', '&&', '||', '??'], true)
                           || $p === T_COALESCE || $p === T_BOOLEAN_AND || $p === T_BOOLEAN_OR
                           || $p === T_DOUBLE_ARROW;
                if ($exprCtx) {
                    $this->add($ln, 'THROW_EXPRESSION', '8.0', 'ERROR', 'throw 를 식으로 사용');
                }
            }
        }
    }

    // -----------------------------------------------------------------
    // 규칙 B. 괄호 문맥이 필요한 것 — trailing comma, spread, named args
    // -----------------------------------------------------------------
    private function ruleBrackets(): void
    {
        global $T_FN, $T_NAME_Q, $T_NAME_FQ, $T_NAME_REL, $T_MATCH;
        $n = count($this->t);
        $stack = [];   // 각 원소: ['kind'=>string, 'open'=>int]

        $callPrevIds = [T_STRING, T_VARIABLE, T_CLASS, $T_NAME_Q, $T_NAME_FQ, $T_NAME_REL];
        $ctrlIds = [
            T_IF, T_ELSEIF, T_WHILE, T_FOR, T_FOREACH, T_SWITCH, T_CATCH, T_DECLARE,
            T_EXIT, T_PRINT, T_CLONE, T_ECHO, T_RETURN, T_EVAL, T_INCLUDE, T_INCLUDE_ONCE,
            T_REQUIRE, T_REQUIRE_ONCE, T_YIELD, T_NEW, $T_MATCH,
        ];

        for ($i = 0; $i < $n; $i++) {
            $id = $this->id($i); $tx = $this->tx($i); $ln = $this->ln($i);

            if ($id === null && $tx === '(') {
                $p = $this->id($i - 1); $pt = $this->tx($i - 1);
                $kind = 'group';
                if ($p === T_FUNCTION || $p === $T_FN) {
                    $kind = 'params';
                } elseif ($p === T_STRING && ($this->id($i - 2) === T_FUNCTION)) {
                    $kind = 'params';
                } elseif ($p === T_ARRAY) {
                    $kind = 'array';
                } elseif ($p === T_ISSET || $p === T_UNSET) {
                    $kind = 'isset';
                } elseif ($p === T_LIST) {
                    $kind = 'list';
                } elseif ($p === T_USE) {
                    $kind = 'use';
                } elseif ($p !== null && in_array($p, $ctrlIds, true)) {
                    $kind = 'ctrl';
                } elseif ($p !== null && in_array($p, $callPrevIds, true)) {
                    $kind = 'call';
                } elseif ($p === null && in_array($pt, [')', ']', '}'], true)) {
                    $kind = 'call';
                }
                $stack[] = ['kind' => $kind, 'open' => $i];
                continue;
            }

            if ($id === null && $tx === '[') {
                // 배열 리터럴인가 인덱스 접근인가
                $p = $this->id($i - 1); $pt = $this->tx($i - 1);
                $isDim = in_array($p, [T_VARIABLE, T_STRING, T_CONSTANT_ENCAPSED_STRING, $T_NAME_Q, $T_NAME_FQ], true)
                         || ($p === null && in_array($pt, [')', ']', '}'], true));
                $stack[] = ['kind' => $isDim ? 'dim' : 'array', 'open' => $i];
                continue;
            }

            if ($id === null && ($tx === ')' || $tx === ']')) {
                $frame = array_pop($stack);
                $kind = $frame ? $frame['kind'] : 'group';
                // trailing comma
                if ($this->isChar($i - 1, ',')) {
                    if ($tx === ')') {
                        if ($kind === 'call') {
                            $this->add($this->ln($i - 1), 'TRAILING_COMMA_CALL', '7.3', 'ERROR',
                                '함수/메서드 호출의 trailing comma');
                        } elseif ($kind === 'params') {
                            $this->add($this->ln($i - 1), 'TRAILING_COMMA_PARAMS', '8.0', 'ERROR',
                                '파라미터 목록의 trailing comma');
                        } elseif ($kind === 'use') {
                            $this->add($this->ln($i - 1), 'TRAILING_COMMA_CLOSURE_USE', '8.0', 'ERROR',
                                'closure use() 의 trailing comma');
                        } elseif ($kind === 'isset' || $kind === 'list') {
                            $this->add($this->ln($i - 1), 'TRAILING_COMMA_ISSET', '8.0', 'ERROR',
                                'isset/unset/list 의 trailing comma');
                        }
                        // 'array'(=array(...)) 는 예전부터 허용
                    }
                    // ']' 배열 리터럴 trailing comma 는 항상 허용
                }
                // 반환 타입 검사
                if ($tx === ')' && $kind === 'params' && $this->isChar($i + 1, ':')) {
                    $this->checkReturnType($i + 2);
                }
                continue;
            }

            if ($id === T_ELLIPSIS) {
                $kind = $stack ? $stack[count($stack) - 1]['kind'] : 'group';
                if ($kind === 'array') {
                    $this->add($ln, 'ARRAY_SPREAD', '7.4', 'ERROR', '배열 리터럴 안의 스프레드 [...$a]');
                } elseif ($kind === 'call') {
                    // 유일한 인자면 first-class callable (8.1)
                    $prevOpen = $stack[count($stack) - 1]['open'];
                    if ($prevOpen === $i - 1 && $this->isChar($i + 1, ')')) {
                        $this->add($ln, 'FIRST_CLASS_CALLABLE', '8.1', 'ERROR', 'foo(...) 문법');
                    }
                }
                continue;
            }

            // named arguments — call 문맥의 인자 시작 위치에서 T_STRING ':'
            if ($id === null && $tx === ':' && $stack) {
                $kind = $stack[count($stack) - 1]['kind'];
                if ($kind === 'call' && $this->id($i - 1) === T_STRING) {
                    $before = $i - 2;
                    if ($this->isChar($before, '(') || $this->isChar($before, ',')) {
                        $this->add($ln, 'NAMED_ARGUMENT', '8.0', 'ERROR', 'named argument ' . $this->tx($i - 1) . ':');
                    }
                }
                continue;
            }

            // 구조분해 참조 할당 [&$a, &$b] = ... / list(&$a) = ...
            if ($id === null && $tx === '&' && $stack) {
                $kind = $stack[count($stack) - 1]['kind'];
                $open = $stack[count($stack) - 1]['open'];
                // 인터섹션 타입 A&B (8.1) — 파라미터 목록 안에서 T_STRING & T_STRING
                if ($kind === 'params' && $this->id($i - 1) === T_STRING && $this->id($i + 1) === T_STRING) {
                    $this->add($ln, 'INTERSECTION_TYPE', '8.1', 'ERROR', '인터섹션 타입 A&B');
                    continue;
                }
                if (($kind === 'array' || $kind === 'list')
                    && ($this->isChar($i - 1, '[') || $this->isChar($i - 1, '(') || $this->isChar($i - 1, ','))
                    && $this->id($i + 1) === T_VARIABLE) {
                    // 대입 대상인지 확인: 닫는 괄호 뒤가 '='
                    $close = $this->matchClose($open);
                    if ($close !== null && $this->isChar($close + 1, '=')) {
                        $this->add($ln, 'DESTRUCTURE_BY_REF', '7.3', 'ERROR', '구조분해 참조 할당');
                    }
                }
                continue;
            }

            // 파라미터 목록: 유니온/인터섹션 타입, 생성자 프로퍼티 승격
            if ($stack && $stack[count($stack) - 1]['kind'] === 'params') {
                if (in_array($id, [T_PUBLIC, T_PROTECTED, T_PRIVATE], true)) {
                    $this->add($ln, 'CTOR_PROPERTY_PROMOTION', '8.0', 'ERROR', '생성자 프로퍼티 승격');
                }
                if ($id === null && $tx === '|' && $this->id($i - 1) === T_STRING
                    && ($this->id($i + 1) === T_STRING || $this->isChar($i + 1, '?'))) {
                    // 뒤에 변수가 오면 타입, 아니면 비트연산
                    $j = $i + 1;
                    while ($this->id($j) === T_STRING || $this->isChar($j, '|') || $this->isChar($j, '?')
                           || $this->id($j) === T_NS_SEPARATOR) { $j++; }
                    if ($this->id($j) === T_VARIABLE || $this->id($j) === T_ELLIPSIS || $this->isChar($j, '&')) {
                        $this->add($ln, 'UNION_TYPE_PARAM', '8.0', 'ERROR', '파라미터 유니온 타입');
                    }
                }
            }
        }
    }

    /** $open 위치의 여는 괄호에 대응하는 닫는 괄호 인덱스 */
    private function matchClose(int $open): ?int
    {
        $openCh = $this->tx($open);
        $closeCh = $openCh === '(' ? ')' : ']';
        $depth = 0; $n = count($this->t);
        for ($i = $open; $i < $n; $i++) {
            if ($this->id($i) !== null) { continue; }
            $c = $this->tx($i);
            if ($c === '(' || $c === '[') { $depth++; }
            elseif ($c === ')' || $c === ']') { $depth--; if ($depth === 0) { return $c === $closeCh ? $i : null; } }
        }
        return null;
    }

    /** 반환 타입 토큰열 검사. $i 는 ':' 다음 인덱스 */
    private function checkReturnType(int $i): void
    {
        $parts = []; $ln = $this->ln($i); $n = count($this->t);
        for ($j = $i; $j < $n; $j++) {
            if ($this->isChar($j, '{') || $this->isChar($j, ';')) { break; }
            $parts[] = $this->tx($j);
            if (count($parts) > 24) { break; }
        }
        $type = implode('', $parts);
        $low = strtolower($type);
        if (strpos($type, '|') !== false) {
            $this->add($ln, 'UNION_TYPE_RETURN', '8.0', 'ERROR', '반환 유니온 타입 ' . $type);
        }
        if (strpos($type, '&') !== false) {
            $this->add($ln, 'INTERSECTION_TYPE', '8.1', 'ERROR', '반환 인터섹션 타입 ' . $type);
        }
        if ($low === 'static' || $low === '?static') {
            $this->add($ln, 'STATIC_RETURN_TYPE', '8.0', 'ERROR', 'static 반환 타입');
        }
        if ($low === 'mixed' || $low === '?mixed') {
            $this->add($ln, 'MIXED_TYPE', '8.0', 'ERROR', 'mixed 타입');
        }
        if ($low === 'never') {
            $this->add($ln, 'NEVER_TYPE', '8.1', 'ERROR', 'never 반환 타입');
        }
        if (in_array($low, ['null', 'false', 'true'], true)) {
            $this->add($ln, 'STANDALONE_NULL_FALSE_TRUE_TYPE', '8.2', 'ERROR', '단독 ' . $low . ' 타입');
        }
    }

    // -----------------------------------------------------------------
    // 규칙 C. 선언부 — 타입 프로퍼티, non-capturing catch
    // -----------------------------------------------------------------
    private function ruleDeclarations(): void
    {
        global $T_READONLY;
        $n = count($this->t);
        $parenDepth = 0;
        for ($i = 0; $i < $n; $i++) {
            $id = $this->id($i); $tx = $this->tx($i);
            if ($id === null && $tx === '(') { $parenDepth++; continue; }
            if ($id === null && $tx === ')') { $parenDepth--; continue; }

            // 타입 프로퍼티: 클래스 본문(괄호 밖)에서 가시성 지시자 → 타입 → $var
            if ($parenDepth === 0 && in_array($id, [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR], true)) {
                $j = $i + 1; $sawType = false; $guard = 0;
                while ($guard++ < 12) {
                    $jid = $this->id($j); $jtx = $this->tx($j);
                    if ($jid === T_STATIC || $jid === $T_READONLY) { $j++; continue; }
                    if ($jid === T_FUNCTION || $jid === T_CONST) { break; }
                    if ($jid === T_VARIABLE) {
                        if ($sawType) {
                            $this->add($this->ln($i), 'TYPED_PROPERTY', '7.4', 'ERROR',
                                '타입 프로퍼티 ' . $jtx);
                        }
                        break;
                    }
                    if ($jid === T_STRING || $jid === T_ARRAY || $jid === T_CALLABLE
                        || $jid === T_NS_SEPARATOR || $jid === null && in_array($jtx, ['?', '|'], true)) {
                        $sawType = true; $j++; continue;
                    }
                    break;
                }
                continue;
            }

            // non-capturing catch (8.0): catch ( Type ) — 변수 없음
            if ($id === T_CATCH && $this->isChar($i + 1, '(')) {
                $close = $this->matchClose($i + 1);
                if ($close !== null) {
                    $hasVar = false;
                    for ($k = $i + 2; $k < $close; $k++) {
                        if ($this->id($k) === T_VARIABLE) { $hasVar = true; break; }
                    }
                    if (!$hasVar) {
                        $this->add($this->ln($i), 'NON_CAPTURING_CATCH', '8.0', 'ERROR', '변수 없는 catch');
                    }
                }
            }
        }
    }

    // -----------------------------------------------------------------
    // 규칙 D. 함수/상수/클래스 이름 + 시그니처 변경
    // -----------------------------------------------------------------
    private function ruleNames(): void
    {
        global $T_NAME_Q, $T_NAME_FQ;
        $maps = $this->maps;
        $n = count($this->t);
        for ($i = 0; $i < $n; $i++) {
            $id = $this->id($i);
            if ($id !== T_STRING && $id !== $T_NAME_Q && $id !== $T_NAME_FQ) { continue; }
            $raw = $this->tx($i);
            $name = ltrim($raw, '\\');
            $ln = $this->ln($i);
            $p = $this->id($i - 1);
            $isMember = ($p === T_OBJECT_OPERATOR || $p === T_DOUBLE_COLON
                         || $p === tid('T_NULLSAFE_OBJECT_OPERATOR'));
            $isDecl = ($p === T_FUNCTION || $p === T_CONST || $p === T_CLASS
                       || $p === T_INTERFACE || $p === T_TRAIT || $p === tid('T_ENUM')
                       || $p === T_NAMESPACE || $p === T_USE || $p === T_AS);

            if ($this->isChar($i + 1, '(')) {
                if ($isMember || $isDecl) { continue; }
                if ($p === T_NEW) {   // 클래스 인스턴스화
                    $this->checkClassName($name, $ln);
                    continue;
                }
                $lower = strtolower($name);
                $this->globalFuncs[$lower] = ($this->globalFuncs[$lower] ?? 0) + 1;
                if (isset($maps['funcs'][$lower])) {
                    $this->add($ln, 'FUNC_NOT_IN_72', $maps['funcs'][$lower], 'ERROR',
                        $lower . '() 는 PHP ' . $maps['funcs'][$lower] . ' 에서 추가되었다');
                }
                $this->checkCallSignature($lower, $i + 1, $ln);
                continue;
            }

            // 상수
            if (!$isMember && !$isDecl && $this->id($i + 1) !== T_DOUBLE_COLON
                && $this->id($i + 1) !== T_VARIABLE && $p !== T_NEW
                && $p !== T_INSTANCEOF && $p !== T_EXTENDS && $p !== T_IMPLEMENTS) {
                if (isset($maps['consts'][$name])) {
                    $this->add($ln, 'CONST_NOT_IN_72', $maps['consts'][$name], 'ERROR',
                        $name . ' 는 PHP ' . $maps['consts'][$name] . ' 에서 추가된 상수다');
                    continue;
                }
            }

            // 클래스 참조
            if ($p === T_NEW || $p === T_INSTANCEOF || $p === T_EXTENDS || $p === T_IMPLEMENTS
                || $this->id($i + 1) === T_DOUBLE_COLON) {
                $this->checkClassName($name, $ln);
            }
        }
    }

    private function checkClassName(string $name, int $ln): void
    {
        if (isset($this->maps['classes'][$name])) {
            $this->add($ln, 'CLASS_NOT_IN_72', $this->maps['classes'][$name], 'ERROR',
                $name . ' 는 PHP ' . $this->maps['classes'][$name] . ' 에서 추가되었다');
        }
    }

    /** 호출 인자 목록을 최상위 콤마로 쪼개 [ [시작idx, 끝idx], ... ] 반환 */
    private function splitArgs(int $openParen): array
    {
        $close = $this->matchClose($openParen);
        if ($close === null) { return []; }
        $args = []; $depth = 0; $start = $openParen + 1;
        if ($start >= $close) { return []; }
        for ($i = $openParen + 1; $i < $close; $i++) {
            if ($this->id($i) !== null) { continue; }
            $c = $this->tx($i);
            if ($c === '(' || $c === '[' || $c === '{') { $depth++; }
            elseif ($c === ')' || $c === ']' || $c === '}') { $depth--; }
            elseif ($c === ',' && $depth === 0) { $args[] = [$start, $i - 1]; $start = $i + 1; }
        }
        if ($start <= $close - 1) { $args[] = [$start, $close - 1]; }
        return $args;
    }

    private function checkCallSignature(string $fn, int $openParen, int $ln): void
    {
        $maps = $this->maps;
        $needSig   = isset($maps['argArray'][$fn]);
        $needMax   = isset($maps['argMax'][$fn]);
        $needMin   = isset($maps['argMin'][$fn]);
        if (!$needSig && !$needMax && !$needMin) { return; }

        $args = $this->splitArgs($openParen);
        $cnt  = count($args);

        if ($needSig) {
            list($idx, $ver, $desc) = $maps['argArray'][$fn];
            if (isset($args[$idx])) {
                $s = $args[$idx][0];
                $first = $this->id($s);
                $isArrayLit = ($first === T_ARRAY) || $this->isChar($s, '[');
                if ($isArrayLit) {
                    $this->add($ln, 'ARG_ARRAY_FORM', $ver, 'ERROR', $desc . ' (배열 리터럴)');
                } else {
                    // 변수/함수호출이면 정적으로 판정 불가 → 수동 확인
                    $isScalar = in_array($first, [T_LNUMBER, T_DNUMBER, T_CONSTANT_ENCAPSED_STRING], true);
                    if (!$isScalar) {
                        $this->add($ln, 'ARG_ARRAY_FORM_MANUAL', $ver, 'NOTE',
                            $desc . ' — 3번째 인자가 리터럴이 아니다. 배열이면 PHP ' . $ver . ' 필요');
                    }
                }
            }
        }
        if ($needMax) {
            list($max, $ver) = $maps['argMax'][$fn];
            if ($cnt > $max) {
                $this->add($ln, 'ARG_COUNT_TOO_MANY', $ver, 'ERROR',
                    $fn . '() 인자 ' . $cnt . '개 — ' . $max . '개 초과는 PHP ' . $ver . ' 필요');
            }
        }
        if ($needMin) {
            list($min, $ver) = $maps['argMin'][$fn];
            if ($cnt < $min) {
                $this->add($ln, 'ARG_COUNT_TOO_FEW', $ver, 'ERROR',
                    $fn . '() 인자 ' . $cnt . '개 — ' . $min . '개 미만은 PHP ' . $ver . ' 필요');
            }
        }
    }

    // -----------------------------------------------------------------
    // 규칙 E. 유연 heredoc (7.3) — 종료 마커 들여쓰기
    // -----------------------------------------------------------------
    private function ruleHeredoc(array $raw): void
    {
        foreach ($raw as $tok) {
            if (!is_array($tok) || $tok[0] !== T_END_HEREDOC) { continue; }
            if (preg_match('/^\s+/', $tok[1])) {
                $this->add((int) $tok[2], 'FLEXIBLE_HEREDOC', '7.3', 'ERROR', '들여쓴 heredoc 종료 마커');
            }
        }
    }

    // -----------------------------------------------------------------
    // 규칙 F. 상속 — 공변 반환 / 반공변 파라미터는 정적 판정이 어렵다. 목록만 낸다
    // -----------------------------------------------------------------
    private function ruleInheritance(): void
    {
        $n = count($this->t);
        for ($i = 0; $i < $n; $i++) {
            if ($this->id($i) !== T_EXTENDS && $this->id($i) !== T_IMPLEMENTS) { continue; }
            $kw = strtolower($this->tx($i));
            $target = $this->tx($i + 1);
            $this->add($this->ln($i), 'INHERITANCE_MANUAL_CHECK', '7.4', 'NOTE',
                $kw . ' ' . $target . ' — 공변 반환/반공변 파라미터 수동 확인 대상');
        }
    }
}

// ---------------------------------------------------------------------
// 5. 실행
// ---------------------------------------------------------------------

$files = collect_php_files($paths, $excludes);
$scanner = new Scanner([
    'funcs'    => array_change_key_case($FUNCS_AFTER_72, CASE_LOWER),
    'consts'   => $CONSTS_AFTER_72,
    'classes'  => $CLASSES_AFTER_72,
    'argArray' => $ARG_ARRAY_SIGNATURES,
    'argMax'   => $ARG_COUNT_LIMITS,
    'argMin'   => $ARG_COUNT_MINS,
]);

foreach ($files as $f) {
    $scanner->scanFile($f);
}

if ($listFuncs) {
    $fn = $scanner->globalFuncs;
    ksort($fn);
    echo "=== 호출하는 전역 함수 (" . count($fn) . "개) ===\n";
    foreach ($fn as $name => $c) {
        echo $name . ' (' . $c . ")\n";
    }
    echo "\n";
}

$errors = [];
$notes  = [];
foreach ($scanner->findings as $f) {
    if ($f->severity === 'ERROR') { $errors[] = $f; } else { $notes[] = $f; }
}

$cmp = function ($a, $b) {
    if ($a->file !== $b->file) { return strcmp($a->file, $b->file); }
    return $a->line - $b->line;
};
usort($errors, $cmp);
usort($notes, $cmp);

echo "=== php72_scan — 스캔 파일 " . count($files) . "개 ===\n\n";

echo "--- ERROR (" . count($errors) . "건) ---\n";
foreach ($errors as $f) {
    printf("%s:%d  [%s] 요구 PHP %s  %s\n", $f->file, $f->line, $f->rule, $f->requires, $f->detail);
}
echo "\n--- NOTE (" . count($notes) . "건, 수동 확인) ---\n";
foreach ($notes as $f) {
    printf("%s:%d  [%s] 요구 PHP %s  %s\n", $f->file, $f->line, $f->rule, $f->requires, $f->detail);
}

$byRule = [];
foreach ($scanner->findings as $f) {
    $byRule[$f->severity . ' ' . $f->rule] = ($byRule[$f->severity . ' ' . $f->rule] ?? 0) + 1;
}
ksort($byRule);
echo "\n--- 규칙별 집계 ---\n";
foreach ($byRule as $r => $c) { echo $r . ': ' . $c . "\n"; }

$fail = count($errors) > 0 || ($notesAreErrors && count($notes) > 0);
echo "\n결과: ERROR " . count($errors) . " / NOTE " . count($notes) . " => 종료코드 " . ($fail ? 1 : 0) . "\n";
exit($fail ? 1 : 0);
