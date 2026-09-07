<?php
declare(strict_types=1);

/**
 * 티어 2 — 민감 키 · 게이트 판정의 **사본 간 패리티** (리더 판정 D-207)
 *
 * ── 왜 이 파일이 있나 ────────────────────────────────────────────────
 * 「규칙이 두 곳에 있다」가 이 저장소에서 **세 번** 났다.
 *   1. 민감 키 정규식 (PHP ↔ JS) — v1.4.0 에 48키를 **손으로** 대조해 찾았다
 *   2. 게이트 판정 (PHP ↔ JS) — 서버가 「랭크 0 초과」에서 「각 축의 기본값 초과」로
 *      바뀌었는데(H-1 / D-201) `public/admin.html` 의 사본이 그대로 남아
 *      게이트 점검 화면이 `basic` 행을 **과소 보고**했다. 게이트가 없는데
 *      있다고 보이는, 위험한 쪽의 오차다
 *   3. `install.php` 의 `ins_gate_effective()` — 같은 결함, 다른 코드 모양.
 *      「사본을 찾으라」는 지시로는 안 걸리는 자리였다
 * 세 번 모두 **사람이 손으로 대조해서** 찾았다. 그것을 매 실행 자동으로 한다.
 *
 * ── 규칙이 실제로 몇 곳에 있나 (셋이다) ──────────────────────────────
 *   A. `server/core/secretguard.php`  — 시크릿 CRUD · 설정 번들 검증의 정본
 *   B. `server/install.php`           — `core/` 를 로드할 수 없어 자기 사본을 갖는다
 *   C. `public/admin.html`            — 조회용 판정 API 가 없어 화면이 직접 계산한다
 * **A 가 정답이다.** 실제 차단은 A 가 한다. B·C 는 A 와 같은 답을 내야 한다.
 * 근본 해법(서버가 판정을 응답에 실어 주면 C 가 사라진다)은 v1.7.0 과제다(D-207).
 *
 * ── 각자의 엔진으로 돌린다 ───────────────────────────────────────────
 * 소스를 눈으로 대조하지 않는다. **PCRE 는 PHP 가, ECMAScript 는 Node 가** 돌린다.
 * v1.4.0 QA 가 60키를 두 엔진에 넣어 불일치 0 을 확인한 방식이다.
 *   A → 이 프로세스 (`load_core()` + `require secretguard.php`)
 *   B → 자식 PHP 프로세스 (install.php 에서 함수 소스만 뽑아 넣는다.
 *        install.php 는 그대로 require 할 수 없다 — 최상위에서 화면을 그린다)
 *   C → Node (admin.html 의 `@parity-begin` 표식 사이를 뽑아 넣는다)
 *
 * ── 규칙을 이 파일에 **다시 적지 않는다** ────────────────────────────
 * 정규식도, 랭크 숫자도, 축 기본값도 여기 적지 않는다. 적으면 네 번째 사본이 된다.
 * 세 곳에서 **뽑아서** 대조한다. 대신 판정이 눈멀지 않게 두 가지를 덧붙인다:
 *   · 케이스 행렬이 한쪽으로 쏠려 있지 않은지 센다(양성·음성이 둘 다 나와야 한다)
 *   · 리더 판정이 **명시적으로 이름을 부른 몇 개**만 기대값으로 박는다
 *     (`min_account_level: "basic"` 은 게이트가 아니다 — D-201 / `ai_api_key` 는
 *      민감하다 · `api_base` 는 아니다 — v1.4.0 D-154(4))
 *
 * ── 검사기를 먼저 검증한다 (D-113 · D-186) ───────────────────────────
 * 이 파일의 마지막 두 케이스가 **일부러 갈라진 사본**을 만들어 넣는다.
 * 대조가 그것을 못 잡으면 「불일치 0」은 아무 증거도 아니다.
 * 변이는 치환 대상이 **정확히 1회** 있는지 먼저 확인한다 — 없는 문자열을 치환하면
 * 변이가 무동작이 되고 "잡았다"가 거짓이 된다.
 *
 * **잡히지 않는다고 확인한 변이 1건을 여기 적어 둔다** — `gateRankOf()` 의
 * `Object.prototype.hasOwnProperty.call(m, value)` 가드를 `m[value]` 로 바꿔도
 * 이 행렬에서 불일치가 **0건**이다. 눈먼 것이 아니라 **결과가 실제로 같다**:
 * 프로토타입 이름(`constructor` · `toString` · `__proto__` · `hasOwnProperty`)은
 * 함수·객체를 돌려주고 그것을 랭크와 `>` 로 비교하면 NaN 비교라 언제나 false 가 된다.
 * 그래서 그 가드는 지금 관측 가능한 방어가 아니라 **앞으로를 위한 것**이다
 * (비교가 `>=` 로 바뀌거나 랭크를 산술에 쓰게 되면 관측 가능해진다).
 * 프로토타입 이름 케이스는 행렬에 그대로 남긴다 — 그때 잡히게 하려고 남긴다.
 * 잡히지 않는 변이를 「잡았다」로 세지 않기 위해 변이 목록에서는 뺐다.
 * ─────────────────────────────────────────────────────────────────────
 *
 * Node 가 없으면 C 쪽 케이스만 SKIP 한다. A ↔ B 대조와 정적 텍스트 대조는 계속 돈다.
 * `ULOGIN_TEST_NODE` 환경변수로 Node 실행 파일을 지정할 수 있다.
 */

require_once __DIR__ . '/../lib/loader.php';

load_core();
require_once h_root() . '/server/core/secretguard.php';

// =====================================================================
// 뽑아내기 — 세 사본의 소스를 원본 파일에서 직접 읽는다
// =====================================================================

/**
 * `admin.html` 의 `@parity-begin <name>` … `@parity-end <name>` 사이 소스.
 *
 * 표식이 없으면 **예외를 던진다.** 화면 담당이 표식을 지우면 이 테스트가 실패해야 한다 —
 * 조용히 "0건"이 되면 검사가 사라진 것을 아무도 모른다.
 */
function gp_js_block(string $src, string $name): string
{
    $begin = '/* @parity-begin ' . $name;
    $end   = '/* @parity-end ' . $name . ' */';
    $i = strpos($src, $begin);
    if ($i === false) {
        throw new RuntimeException('parity marker "@parity-begin ' . $name . '" is gone from the source'
            . ' - the PHP<->JS comparison cannot run. restore the marker or update this test on purpose.');
    }
    // 시작 표식 자신이 블록 주석이다. 그 주석의 닫는 `*/` 뒤부터가 본문이다.
    $afterBegin = strpos($src, '*/', $i);
    if ($afterBegin === false) {
        throw new RuntimeException('unterminated @parity-begin comment for "' . $name . '"');
    }
    $afterBegin += 2;
    $j = strpos($src, $end, $afterBegin);
    if ($j === false) {
        throw new RuntimeException('parity marker "@parity-end ' . $name . ' */" is gone from the source');
    }
    if (strpos($src, $begin, $afterBegin) !== false) {
        throw new RuntimeException('parity marker "@parity-begin ' . $name . '" appears more than once');
    }
    $body = substr($src, $afterBegin, $j - $afterBegin);
    if (trim($body) === '') {
        throw new RuntimeException('parity block "' . $name . '" is empty');
    }
    return $body;
}

/**
 * PHP 소스에서 이름으로 함수 정의 **텍스트**를 뽑는다.
 *
 * 토크나이저로 중괄호를 센다 — 문자열 리터럴 안의 `{` 는 한 토큰 안에 들어 있어
 * `=== '{'` 비교에 걸리지 않는다. 정규식으로는 그것을 가를 수 없다.
 */
function gp_php_functions(string $src, array $names): array
{
    $tk  = token_get_all($src);
    $n   = count($tk);
    $out = array();
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($tk[$i]) || $tk[$i][0] !== T_FUNCTION) {
            continue;
        }
        $j = $i + 1;
        while ($j < $n && is_array($tk[$j]) && $tk[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j >= $n || !is_array($tk[$j]) || $tk[$j][0] !== T_STRING) {
            continue;
        }
        $nm = $tk[$j][1];
        if (!in_array($nm, $names, true)) {
            continue;
        }
        $depth = 0;
        $buf   = '';
        $sawBrace = false;
        for ($k = $i; $k < $n; $k++) {
            $txt = is_array($tk[$k]) ? $tk[$k][1] : $tk[$k];
            $buf .= $txt;
            if ($txt === '{') {
                $depth++;
                $sawBrace = true;
            } elseif ($txt === '}') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
        }
        if (!$sawBrace || $depth !== 0) {
            throw new RuntimeException('cannot brace-match function ' . $nm);
        }
        $out[$nm] = $buf;
    }
    $missing = array_diff($names, array_keys($out));
    if ($missing !== array()) {
        throw new RuntimeException('function(s) not found: ' . implode(', ', $missing));
    }
    return $out;
}

/**
 * 정규식 리터럴 하나만 든 소스에서 패턴 본문을 뽑는다(구분자·수식자 제외).
 *
 * `<?php` 를 붙여서 넘긴다 — 없으면 `token_get_all()` 이 조각 전체를
 * `T_INLINE_HTML` 한 덩이로 읽어 **문자열 리터럴을 0개 돌려준다.**
 * 조용히 0건이 되는 자리라 여기서 명시해 둔다.
 */
function gp_pcre_body(string $fnSrc, string $where): string
{
    $lits = h_php_strings('<?php ' . $fnSrc);
    $hit  = array();
    foreach ($lits as $s) {
        if (strlen($s) > 2 && $s[0] === '/' && substr($s, -1) === '/') {
            $hit[] = substr($s, 1, -1);
        }
    }
    if (count($hit) !== 1) {
        throw new RuntimeException($where . ': expected exactly 1 `/.../` pattern literal, found ' . count($hit));
    }
    return $hit[0];
}

/** JS 블록에서 `var <name> = /.../;` 의 패턴 본문. */
function gp_js_regex_body(string $block, string $name): string
{
    if (preg_match('~var\s+' . preg_quote($name, '~') . '\s*=\s*\r?\n?\s*/(.*?)/\s*;~s', $block, $m) !== 1) {
        throw new RuntimeException('cannot find JS regex literal for ' . $name);
    }
    return $m[1];
}

// =====================================================================
// 실행 — 각자의 엔진
// =====================================================================

/** Node 실행 파일. 없으면 null. */
function gp_node_bin(): ?string
{
    static $bin = false;
    if ($bin !== false) {
        return $bin;
    }
    $cands = array();
    $env = getenv('ULOGIN_TEST_NODE');
    if (is_string($env) && $env !== '') {
        $cands[] = $env;
    }
    $cands[] = 'node';
    foreach ($cands as $c) {
        $code = 0;
        $out = h_run(h_q($c) . ' --version', $code);
        if ($code === 0 && preg_match('/^v\d+\./', trim($out)) === 1) {
            $bin = $c;
            return $bin;
        }
    }
    $bin = null;
    return $bin;
}

/** `__PARITY__{json}` 한 줄을 골라 디코드. 엔진의 잡음(startup warning)을 건너뛴다. */
function gp_pick_payload(string $out, string $who): array
{
    $found = null;
    foreach (preg_split('/\r?\n/', $out) as $line) {
        $p = strpos($line, '__PARITY__');
        if ($p !== false) {
            $found = substr($line, $p + 10);
        }
    }
    if ($found === null) {
        fail($who . ' produced no __PARITY__ line. raw output:' . "\n" . substr($out, 0, 2000));
    }
    $d = json_decode((string) $found, true);
    if (!is_array($d)) {
        fail($who . ' produced an unparseable __PARITY__ payload: ' . substr((string) $found, 0, 500));
    }
    return $d;
}

/** 임시 파일에 코드를 쓰고 Node 로 돌린다. */
function gp_run_node(string $js, string $who): array
{
    $bin = gp_node_bin();
    if ($bin === null) {
        skip('node is not on PATH (set ULOGIN_TEST_NODE to its full path)');
    }
    $f = sys_get_temp_dir() . '/ulogin_parity_' . bin2hex(random_bytes(6)) . '.js';
    if (@file_put_contents($f, $js) === false) {
        throw new RuntimeException('cannot write ' . $f);
    }
    try {
        $code = 0;
        $out  = h_run(h_q($bin) . ' ' . h_q($f), $code);
        if ($code !== 0) {
            fail($who . ' exited ' . $code . ':' . "\n" . substr($out, 0, 2000));
        }
        return gp_pick_payload($out, $who);
    } finally {
        @unlink($f);
    }
}

// =====================================================================
// 케이스 행렬 — 경계를 겨눈다
// =====================================================================

/**
 * 게이트 케이스 = `[min_account_level, min_billing_tier, min_role]`.
 *
 * 축별 단독 훑기 + 3축 조합. 겨누는 경계:
 *   · 각 축의 **기본값 정확히** (`basic` / `free` / `user`) — H-1 이 난 자리
 *   · 그 **한 단계 위**(게이트여야 한다) · **한 단계 아래**(아니어야 한다)
 *   · `null` · 빈 문자열 · 모르는 값 · 대문자 · 앞뒤 공백 · `'0'`
 *   · **다른 축의 값**을 넣은 것 (`min_role: 'basic'`)
 *   · JS 객체 프로토타입 이름 (`constructor` · `__proto__` · `hasOwnProperty`) —
 *     `hasOwnProperty` 가드를 빼면 JS 만 답이 달라지는 자리다
 */
function gp_gate_cases(): array
{
    $axis = array(
        'account_level' => array(null, '', 'guest', 'basic', 'verified', 'vip', 'staff',
            'BASIC', 'Basic', ' basic', 'basic ', 'basic2', 'root', '0',
            'free', 'user', 'constructor', 'toString', '__proto__', 'hasOwnProperty'),
        'billing_tier' => array(null, '', 'free', 'lite', 'pro', 'enterprise',
            'FREE', ' free', 'lite ', 'root', '0', 'basic', 'user',
            'constructor', '__proto__', 'hasOwnProperty'),
        'role' => array(null, '', 'user', 'manager', 'admin', 'superadmin',
            'USER', ' user', 'manager ', 'root', '0', 'basic', 'free',
            'constructor', '__proto__', 'hasOwnProperty'),
    );
    $cases = array();
    foreach ($axis['account_level'] as $v) {
        $cases[] = array($v, null, null);
    }
    foreach ($axis['billing_tier'] as $v) {
        $cases[] = array(null, $v, null);
    }
    foreach ($axis['role'] as $v) {
        $cases[] = array(null, null, $v);
    }
    // 3축 조합 — 「한 축만 실효」 「전부 기본값」 「기본값 + 미지값」이 섞이는 자리
    $cl = array(null, 'guest', 'basic', 'verified');
    $ct = array(null, 'free', 'lite', 'root');
    $cr = array(null, 'user', 'manager', 'root');
    foreach ($cl as $a) {
        foreach ($ct as $b) {
            foreach ($cr as $c) {
                $cases[] = array($a, $b, $c);
            }
        }
    }
    return $cases;
}

/**
 * 민감 키 케이스. 원문 그대로 base64 로 실어 보낸다 — `\0` 과 비ASCII 가 섞여 있고
 * 인코딩 왕복에서 조용히 바뀌면 대조가 거짓이 된다.
 *
 * 겨누는 경계: 낱말 구분자(`_` `-` `.`) · 복수형 · 대소문자 · 앞뒤 공백 ·
 * **PHP `trim()` 과 JS `trim()` 의 charlist 차이**(`\0` · NBSP · U+2028 · BOM · `\f`) ·
 * **PHP `strtolower()` 와 JS `toLowerCase()` 의 차이**(U+212A 켈빈 기호 → ASCII `k`) ·
 * 낱말 안에 묻힌 키워드(`monkey` · `whisk` · `tokenizer`).
 */
function gp_key_cases(): array
{
    $plain = array(
        // 민감해야 하는 쪽
        'ai_api_key', 'ai_api_key_prod', 'api_key', 'api_key_v2', 'api_key_hint',
        'apikey', 'apikeys', 'openai_key', 'claude_key', 'anthropic_key',
        'private_key', 'secret_key', 'secret', 'secrets', 'x_secret',
        'client_secret', 'client-secret', 'x-api-key', 'refresh_token',
        'access_token', 'token', 'tokens', 'password', 'passwords',
        'db_password', 'passwd', 'credential', 'credentials', 'aws_credentials',
        'sk', 'sk_live', 'my_sk', 'sk_x', 'a.sk.b', 'key', 'keys',
        'KEY', 'API_KEY', 'Api.Key', 'a_KEY_b', 'A.SK.B', 'max_tokens',
        // 민감하지 않아야 하는 쪽
        'api_base', 'model', 'model_name', 'endpoint', 'org_id', 'openai_org',
        'keyring', 'keyed', 'monkey', 'basket', 'tokenizer', 'secretive',
        'password1', 'sk1', '1sk', 'whisk', 'skey', 'sky', 'keyx',
        'mytoken', 'tokenx', 'credentialing', 'base_url', 'region', 'timeout',
        // 형태 경계
        '', ' ', '_', '.', '-', 'key.', '.key', '_key_', 'a--key', 'key-',
        '  api_key  ', "\tapi_key", "api_key\n", "api_key\n\n", "a\nkey", "key\napi",
    );
    $raw = array(
        "api_key\x00",              // PHP trim 은 깎고 JS trim 은 안 깎는다
        "\x00api_key",
        "api_key\x0b",              // 수직 탭 — 양쪽 charlist 에 다 있다
        "api_key\x0c",              // 폼 피드 — PHP charlist 에 **없다**, JS trim 은 깎는다
        "api_key\xc2\xa0",          // NBSP — JS trim 만 깎는다
        "\xc2\xa0api_key",
        "api_key\xe2\x80\xa8",      // U+2028 — JS trim 만 깎는다
        "api_key\xef\xbb\xbf",      // BOM — JS trim 만 깎는다
        "api_\xe2\x84\xaaey",       // U+212A 켈빈 기호 — JS toLowerCase 만 ASCII k 로 내린다
        "\xe2\x84\xaaey_x",
        "\xd0\xba\xd0\xbb\xd1\x8e\xd1\x87",  // 비ASCII 이름
        "\xe3\x82\xad\xe3\x83\xbc",
    );
    return array_merge($plain, $raw);
}

// =====================================================================
// 준비 — 세 사본에서 뽑고, 세 엔진을 돌린다 (한 번만)
// =====================================================================

/** @return array 뽑아낸 소스·정적 값 */
function gp_sources(): array
{
    static $s = null;
    if ($s !== null) {
        return $s;
    }
    $root = h_root();
    $html = h_read($root . '/public/admin.html');
    $ins  = h_read($root . '/server/install.php');
    $guard = h_read($root . '/server/core/secretguard.php');

    $insFns = gp_php_functions($ins, array(
        'ins_is_sensitive_key', 'ins_gate_ranks', 'ins_gate_axis_floor', 'ins_gate_effective',
    ));
    $guardFns = gp_php_functions($guard, array('admin_secret_key_is_sensitive'));

    $rankBlock = gp_js_block($html, 'rank-map');
    $gateBlock = gp_js_block($html, 'secret-gate');

    $s = array(
        'html'       => $html,
        'insFns'     => $insFns,
        'rankBlock'  => $rankBlock,
        'gateBlock'  => $gateBlock,
        're_core'    => gp_pcre_body($guardFns['admin_secret_key_is_sensitive'], 'core/secretguard.php'),
        're_install' => gp_pcre_body($insFns['ins_is_sensitive_key'], 'install.php'),
        're_js'      => gp_js_regex_body($gateBlock, 'SENSITIVE_KEY_RE'),
    );
    return $s;
}

/** 케이스를 base64+json 으로 봉해 두 자식 엔진에 같은 바이트로 넘긴다. */
function gp_payload_b64(): string
{
    static $b = null;
    if ($b !== null) {
        return $b;
    }
    $keys = array();
    foreach (gp_key_cases() as $k) {
        $keys[] = base64_encode($k);
    }
    $b = base64_encode((string) json_encode(array('gate' => gp_gate_cases(), 'key' => $keys)));
    return $b;
}

/** JS 하네스 소스. `$mutate` 가 주어지면 뽑아낸 소스를 갈라 놓는다. */
function gp_js_harness(?array $mutate = null): string
{
    $s = gp_sources();
    $body = $s['rankBlock'] . "\n" . $s['gateBlock'];
    if ($mutate !== null) {
        list($from, $to) = $mutate;
        $n = substr_count($body, $from);
        if ($n !== 1) {
            fail('mutation target must occur exactly once in the extracted JS, found ' . $n
                . ' for: ' . $from . ' — a no-op mutation would make "CAUGHT" a lie');
        }
        $body = str_replace($from, $to, $body);
    }
    return "'use strict';\n"
        . $body . "\n"
        . "var __in = JSON.parse(Buffer.from('" . gp_payload_b64() . "', 'base64').toString('utf8'));\n"
        . "var __gate = __in.gate.map(function (c) {\n"
        . "  return secretGateIsEffective({ min_account_level: c[0], min_billing_tier: c[1], min_role: c[2] }) ? 1 : 0;\n"
        . "});\n"
        . "var __key = __in.key.map(function (b) {\n"
        . "  return secretKeyIsSensitive(Buffer.from(b, 'base64').toString('utf8')) ? 1 : 0;\n"
        . "});\n"
        . "process.stdout.write('__PARITY__' + JSON.stringify({ gate: __gate, key: __key,"
        . " rank: RANK, floor: GATE_FLOOR }) + '\\n');\n";
}

/** install.php 사본을 자식 PHP 프로세스에서 돌린다. */
function gp_install_run(?array $mutate = null): array
{
    $s = gp_sources();
    $body = implode("\n\n", array_values($s['insFns']));
    if ($mutate !== null) {
        list($from, $to) = $mutate;
        $n = substr_count($body, $from);
        if ($n !== 1) {
            fail('mutation target must occur exactly once in the extracted install.php source, found ' . $n
                . ' for: ' . $from);
        }
        $body = str_replace($from, $to, $body);
    }
    $code = $body . "\n"
        . '$in = json_decode(base64_decode(\'' . gp_payload_b64() . '\'), true);' . "\n"
        . '$gate = array();' . "\n"
        . 'foreach ($in[\'gate\'] as $c) {' . "\n"
        . '    $gate[] = ins_gate_effective(array(\'level\' => $c[0], \'tier\' => $c[1], \'role\' => $c[2])) ? 1 : 0;' . "\n"
        . '}' . "\n"
        . '$key = array();' . "\n"
        . 'foreach ($in[\'key\'] as $b) {' . "\n"
        . '    $key[] = ins_is_sensitive_key(base64_decode($b)) ? 1 : 0;' . "\n"
        . '}' . "\n"
        . '$rank = array(\'account_level\' => ins_gate_ranks(\'level\'),' . "\n"
        . '    \'billing_tier\' => ins_gate_ranks(\'tier\'), \'role\' => ins_gate_ranks(\'role\'));' . "\n"
        . '$floor = array(\'account_level\' => ins_gate_axis_floor(\'level\'),' . "\n"
        . '    \'billing_tier\' => ins_gate_axis_floor(\'tier\'), \'role\' => ins_gate_axis_floor(\'role\'));' . "\n"
        . 'echo "__PARITY__" . json_encode(array(\'gate\' => $gate, \'key\' => $key,'
        . ' \'rank\' => $rank, \'floor\' => $floor)) . "\n";' . "\n";
    list($rc, $out) = run_php_snippet($code);
    if ($rc !== 0) {
        fail('install.php parity child exited ' . $rc . ':' . "\n" . substr($out, 0, 2000));
    }
    return gp_pick_payload($out, 'install.php parity child');
}

/** 정본(A) 을 이 프로세스에서 돌린다. */
function gp_core_run(): array
{
    $gate = array();
    foreach (gp_gate_cases() as $c) {
        $gate[] = admin_secret_gate_is_effective($c[0], $c[1], $c[2]) ? 1 : 0;
    }
    $key = array();
    foreach (gp_key_cases() as $k) {
        $key[] = admin_secret_key_is_sensitive($k) ? 1 : 0;
    }
    return array(
        'gate'  => $gate,
        'key'   => $key,
        'rank'  => array(
            'account_level' => ACCOUNT_LEVEL_RANK,
            'billing_tier'  => BILLING_TIER_RANK,
            'role'          => ROLE_RANK,
        ),
        'floor' => SECRET_GATE_FLOOR,
    );
}

/** 사람이 읽는 케이스 이름. */
function gp_gate_name(array $c): string
{
    $f = function ($v) {
        if ($v === null) {
            return 'null';
        }
        return "'" . $v . "'";
    };
    return 'level=' . $f($c[0]) . ' tier=' . $f($c[1]) . ' role=' . $f($c[2]);
}

function gp_key_name(string $k): string
{
    $safe = '';
    $n = strlen($k);
    for ($i = 0; $i < $n; $i++) {
        $ch = $k[$i];
        $o  = ord($ch);
        $safe .= ($o >= 0x20 && $o < 0x7f) ? $ch : sprintf('\x%02x', $o);
    }
    return '"' . $safe . '"';
}

/** 두 판정 결과를 케이스 단위로 비교해 불일치 목록을 만든다. */
function gp_diff(array $left, array $right, string $lname, string $rname): array
{
    $bad   = array();
    $gates = gp_gate_cases();
    $keys  = gp_key_cases();
    if (count($left['gate']) !== count($gates) || count($right['gate']) !== count($gates)) {
        fail('gate result count mismatch: ' . $lname . '=' . count($left['gate'])
            . ' ' . $rname . '=' . count($right['gate']) . ' expected=' . count($gates));
    }
    if (count($left['key']) !== count($keys) || count($right['key']) !== count($keys)) {
        fail('key result count mismatch: ' . $lname . '=' . count($left['key'])
            . ' ' . $rname . '=' . count($right['key']) . ' expected=' . count($keys));
    }
    foreach ($gates as $i => $c) {
        if ((int) $left['gate'][$i] !== (int) $right['gate'][$i]) {
            $bad[] = 'gate[' . $i . '] ' . gp_gate_name($c) . ' : '
                . $lname . '=' . ($left['gate'][$i] ? 'effective' : 'no gate') . ' but '
                . $rname . '=' . ($right['gate'][$i] ? 'effective' : 'no gate');
        }
    }
    foreach ($keys as $i => $k) {
        if ((int) $left['key'][$i] !== (int) $right['key'][$i]) {
            $bad[] = 'key[' . $i . '] ' . gp_key_name($k) . ' : '
                . $lname . '=' . ($left['key'][$i] ? 'sensitive' : 'not sensitive') . ' but '
                . $rname . '=' . ($right['key'][$i] ? 'sensitive' : 'not sensitive');
        }
    }
    return $bad;
}

// =====================================================================
// 케이스
// =====================================================================

t('the parity markers are still in admin.html and removing one breaks this test', function () {
    $s = gp_sources();
    assert_ne('', trim($s['rankBlock']), 'the rank-map block must not be empty');
    assert_ne('', trim($s['gateBlock']), 'the secret-gate block must not be empty');
    // 표식이 사라지면 **예외**여야 한다. 조용히 빈 블록이 되면 뒤의 "불일치 0"이 거짓이 된다.
    $stripped = str_replace('@parity-begin secret-gate', 'parity-begin-removed', $s['html']);
    assert_throws('RuntimeException', function () use ($stripped) {
        gp_js_block($stripped, 'secret-gate');
    }, 'a missing begin marker must throw, not return an empty block');
    $noEnd = str_replace('/* @parity-end secret-gate */', '', $s['html']);
    assert_throws('RuntimeException', function () use ($noEnd) {
        gp_js_block($noEnd, 'secret-gate');
    }, 'a missing end marker must throw');
    // 블록 안에 랭크 숫자를 다시 적으면 사본이 하나 더 생긴다 — 규약을 검사로 만든다
    assert_not_contains('basic: ', $s['gateBlock'],
        'the secret-gate block must not re-declare rank numbers; it must read them from RANK');
});

t('the sensitive-key pattern is byte-identical in all three copies', function () {
    $s = gp_sources();
    $bad = array();
    if ($s['re_core'] !== $s['re_install']) {
        $bad[] = 'server/core/secretguard.php vs server/install.php differ' . "\n"
            . '      core   : ' . $s['re_core'] . "\n"
            . '      install: ' . $s['re_install'];
    }
    if ($s['re_core'] !== $s['re_js']) {
        $bad[] = 'server/core/secretguard.php vs public/admin.html differ' . "\n"
            . '      core: ' . $s['re_core'] . "\n"
            . '      js  : ' . $s['re_js'];
    }
    assert_clean($bad, 'the sensitive-key pattern has drifted between copies');
    // 뽑아낸 것이 실제 정규식인지 — 빈 문자열을 대조해도 통과하면 이 케이스가 눈먼 것이다
    assert_true(strlen($s['re_core']) > 20, 'the extracted pattern looks too short to be the real one');
});

t('the rank ladders and the axis floors are identical in all three copies', function () {
    $core = gp_core_run();
    $ins  = gp_install_run();
    $bad  = array();
    foreach (array('account_level', 'billing_tier', 'role') as $ax) {
        if ($core['rank'][$ax] !== $ins['rank'][$ax]) {
            $bad[] = 'rank map "' . $ax . '" differs between core/perm.php and install.php: '
                . json_encode($core['rank'][$ax]) . ' vs ' . json_encode($ins['rank'][$ax]);
        }
        if ((string) $core['floor'][$ax] !== (string) $ins['floor'][$ax]) {
            $bad[] = 'axis floor "' . $ax . '" differs between core/secretguard.php and install.php: '
                . $core['floor'][$ax] . ' vs ' . $ins['floor'][$ax];
        }
    }
    assert_clean($bad, 'the rank ladder or the axis floor has drifted between the PHP copies');
});

t('the gate and sensitive-key judgements agree between core/secretguard.php and install.php', function () {
    $core = gp_core_run();
    $ins  = gp_install_run();
    assert_clean(gp_diff($core, $ins, 'core', 'install.php'),
        'install.php judges differently from core/secretguard.php');
});

t('the case matrix is two-sided (a one-sided matrix would blind the comparison)', function () {
    // D-186: 쿠키 71케이스가 전부 `rawurlencode` 항등이라 오라클이 눈멀어 있었다.
    // 같은 사고를 막는다 — 두 답이 다 나오는지, 그리고 몇 건씩인지 센다.
    $core = gp_core_run();
    $gOn  = array_sum($core['gate']);
    $gOff = count($core['gate']) - $gOn;
    $kOn  = array_sum($core['key']);
    $kOff = count($core['key']) - $kOn;
    assert_true($gOn >= 10, 'too few "effective gate" cases (' . $gOn . ')');
    assert_true($gOff >= 10, 'too few "no gate" cases (' . $gOff . ')');
    assert_true($kOn >= 20, 'too few "sensitive" cases (' . $kOn . ')');
    assert_true($kOff >= 20, 'too few "not sensitive" cases (' . $kOff . ')');
    note('parity matrix: gate ' . count($core['gate']) . ' cases (' . $gOn . ' effective / '
        . $gOff . ' no gate) · key ' . count($core['key']) . ' cases (' . $kOn . ' sensitive / '
        . $kOff . ' not sensitive)');
});

t('the judgement anchors named by the leader decisions hold in the PHP source of truth', function () {
    // 규칙을 다시 적지 않는 대신, **판정문이 이름을 부른** 경계만 기대값으로 박는다.
    // D-201 / H-1 — 각 축의 기본값은 게이트가 아니다
    assert_false(admin_secret_gate_is_effective('basic', null, null), 'basic must not count as a gate');
    assert_false(admin_secret_gate_is_effective(null, 'free', null), 'free must not count as a gate');
    assert_false(admin_secret_gate_is_effective(null, null, 'user'), 'user must not count as a gate');
    assert_false(admin_secret_gate_is_effective('guest', 'free', 'user'), 'all-floor must not count');
    assert_false(admin_secret_gate_is_effective(null, null, null), 'no gate at all');
    // 한 단계 위는 게이트다
    assert_true(admin_secret_gate_is_effective('verified', null, null));
    assert_true(admin_secret_gate_is_effective(null, 'lite', null));
    assert_true(admin_secret_gate_is_effective(null, null, 'manager'));
    // 모르는 값은 게이트가 아니다 (fail-closed: 게이트로 세면 없는 방어를 있다고 본다)
    assert_false(admin_secret_gate_is_effective('root', 'root', 'root'));
    // v1.4.0 D-154(4) — 이름이 부른 예시
    assert_true(admin_secret_key_is_sensitive('ai_api_key'));
    assert_true(admin_secret_key_is_sensitive('openai_key'));
    assert_true(admin_secret_key_is_sensitive('secret_key'));
    assert_false(admin_secret_key_is_sensitive('api_base'));
    assert_false(admin_secret_key_is_sensitive('keyring'));
});

t('the gate and sensitive-key judgements agree between PHP and the admin.html JS (each in its own engine)', function () {
    $core = gp_core_run();
    $js   = gp_run_node(gp_js_harness(), 'admin.html JS parity harness');
    $bad  = array();
    foreach (array('account_level', 'billing_tier', 'role') as $ax) {
        if ($core['rank'][$ax] !== $js['rank'][$ax]) {
            $bad[] = 'rank map "' . $ax . '" differs between core/perm.php and admin.html RANK: '
                . json_encode($core['rank'][$ax]) . ' vs ' . json_encode($js['rank'][$ax]);
        }
        if ((string) $core['floor'][$ax] !== (string) $js['floor'][$ax]) {
            $bad[] = 'axis floor "' . $ax . '" differs between SECRET_GATE_FLOOR and admin.html GATE_FLOOR: '
                . $core['floor'][$ax] . ' vs ' . $js['floor'][$ax];
        }
    }
    $bad = array_merge($bad, gp_diff($core, $js, 'PHP(core)', 'JS(admin.html)'));
    assert_clean($bad, 'the admin.html JS copy judges differently from the server');
});

/**
 * 변이로 대조를 검증한다 (D-113 · D-186).
 *
 * 각 변이는 **실제로 났던 결함이나 그 이웃**이다. 변이마다 불일치가 **1건 이상**
 * 나와야 한다. 0건이면 그 규칙에 대해 이 대조가 눈멀어 있다는 뜻이고,
 * 그러면 위의 「불일치 0」은 그 규칙에 대해 아무 증거도 아니다.
 */
t('every planted JS mutation is CAUGHT by the comparison (no blind mutations)', function () {
    $core = gp_core_run();
    $mutations = array(
        // v1.6.0 에 실제로 난 갈라짐 그 자체 — 축 기본값을 랭크 0 값으로 되돌린다.
        // 그러면 판정이 「랭크 0 초과」로 돌아가고 basic 이 다시 게이트로 세진다.
        'the actual v1.6.0 drift: account_level floor back to the rank-0 value'
            => array("account_level: 'basic'", "account_level: 'guest'"),
        // 반대 방향 — 기본값을 한 단계 올리면 실효 게이트를 게이트가 아니라고 본다
        'billing_tier floor raised one rung'
            => array("billing_tier: 'free'", "billing_tier: 'lite'"),
        // v1.4.0 이 넓힌 대안 중 맨 `keys?` 를 다시 뺀다 (그때의 결함 모양)
        'sensitive pattern loses the bare key/keys alternative'
            => array('apikeys?|keys?|', 'apikeys?|'),
        // 미지 값이 fail-open 으로 뒤집히는 경우 — 없는 게이트를 있다고 본다
        'unknown values rank high instead of 0 (fail-open)'
            => array('? m[value] : 0', '? m[value] : 100'),
        // 엔진 차이 그 자체 — JS 의 trim 은 PHP 의 trim 과 charlist 가 다르다
        'PHP-equivalent trim replaced by String.prototype.trim'
            => array("k.replace(PHP_TRIM_RE, '')", 'k.trim()'),
        // 엔진 차이 그 자체 — toLowerCase 는 U+212A 를 ASCII k 로 내린다
        'ASCII-only lowercase replaced by toLowerCase'
            => array("asciiLower(k.replace(PHP_TRIM_RE, ''))", "k.replace(PHP_TRIM_RE, '').toLowerCase()"),
        // 랭크 숫자가 갈라지는 경우
        'the RANK ladder itself drifts (basic 10 -> 5)'
            => array('basic: 10', 'basic: 5'),
    );
    $blind = array();
    $caught = array();
    foreach ($mutations as $label => $m) {
        $mut = gp_run_node(gp_js_harness($m), 'mutant harness [' . $label . ']');
        $bad = gp_diff($core, $mut, 'PHP(core)', 'JS(mutant)');
        // 랭크·floor 표 자체가 갈라지는 변이는 값 대조에서도 잡혀야 한다
        foreach (array('account_level', 'billing_tier', 'role') as $ax) {
            if ($core['rank'][$ax] !== $mut['rank'][$ax] || (string) $core['floor'][$ax] !== (string) $mut['floor'][$ax]) {
                $bad[] = 'table drift on ' . $ax;
            }
        }
        if ($bad === array()) {
            $blind[] = 'mutation NOT caught: ' . $label
                . ' — the comparison is blind to this rule, so "0 mismatches" proves nothing about it';
            continue;
        }
        $caught[] = $label . ' -> ' . count($bad) . ' mismatch(es)';
    }
    assert_clean($blind, 'a planted mutation slipped through the parity comparison');
    assert_eq(count($mutations), count($caught), 'every mutation must be accounted for');
    note('JS mutations caught: ' . implode(' · ', $caught));
});

t('the service-aware gate judgement is reported as NOT mirrored by the screen', function () {
    // D-206 이 `admin_secret_gate_is_effective_in_service()` 를 추가했고, 그 독스트링이
    // 「다른 호출부로 확장하는 것은 이 라운드의 범위가 아니다」라고 적었다.
    // **그것이 곧 공백이다** — 게이트 점검 화면은 시크릿 한 건의 min_* 만 보므로
    // 「서비스의 default_account_level 이 게이트를 자동으로 연다」(H-2)를 잡지 못한다.
    // 공백을 리포트에만 적으면 다음 라운드에 잊힌다. **매 실행 눈에 보이게** 남긴다.
    $guard = h_read(h_root() . '/server/core/secretguard.php');
    $hasSvc = strpos($guard, 'function admin_secret_gate_is_effective_in_service') !== false;
    if (!$hasSvc) {
        note('core/secretguard.php has no service-aware gate judgement in this tree'
            . ' - nothing to mirror, and nothing to report as a gap');
        return;
    }
    $gateBlock = gp_sources()['gateBlock'];
    $mirrored = (strpos($gateBlock, 'default_account_level') !== false);
    if ($mirrored) {
        note('the admin.html gate block now reads default_account_level too'
            . ' - extend this parity file to compare admin_secret_gate_is_effective_in_service()'
            . ' in its own engine, or the new rule has two unchecked copies');
        return;
    }
    // **서버 쓰기 경로의 공백은 닫혔다**(D-206 후속 — 리포트 62g 「콘솔 쓰기 경로」).
    // `api/admin/secrets.php` 도 이제 `admin_secret_gate_is_effective_in_service()` 를 쓴다.
    // 그 사실을 여기서 코드로 확인한다 — 문장으로만 적으면 다시 갈라져도 조용하다.
    $consoleGuard = h_read(h_root() . '/server/api/admin/secrets.php');
    $consoleClosed = strpos($consoleGuard, 'admin_secret_gate_is_effective_in_service') !== false;
    note('KNOWN GAP (reported every run): core/secretguard.php has'
        . ' admin_secret_gate_is_effective_in_service() (D-206) but the admin.html gate scan'
        . ' mirrors only admin_secret_gate_is_effective(). so the screen cannot see the H-2 shape'
        . ' (min_account_level above the floor + the same service default_account_level at or above it'
        . ' -> every member of that service passes). the screen says so in words; it does not detect it.'
        . ' the SERVER write paths no longer disagree: '
        . ($consoleClosed
            ? 'api/admin/secrets.php now judges with the service-aware rule too, so the combination'
            . ' is refused on both the console and the settings-bundle import path. what remains is'
            . ' api/admin/services.php - raising default_account_level does not look at that service\'s'
            . ' secrets, so a two-step route can still produce the shape, and only this screen would'
            . ' surface it. that is the D-207 v1.7.0 item (let the server compute the verdict).'
            : 'NO - api/admin/secrets.php still writes with the single-secret judgement, so the'
            . ' combination is accepted through the console and only refused on the bundle path.'
            . ' this line is stale: re-read 62g before trusting it.'));
});

t('a planted install.php mutation is CAUGHT by the comparison', function () {
    $core = gp_core_run();
    // D-208 이 찾은 결함 그 자체 — install.php 가 랭크 0 값을 축 기본값으로 쓰면
    // basic 이 다시 실효 게이트로 세진다.
    $mut = gp_install_run(array("return 'basic';", "return 'guest';"));
    $bad = gp_diff($core, $mut, 'PHP(core)', 'PHP(install.php mutant)');
    if ((string) $core['floor']['account_level'] === (string) $mut['floor']['account_level']) {
        $bad[] = 'the mutation did not even change the reported floor';
    }
    assert_true($bad !== array(),
        'the install.php mutation was NOT caught — the comparison is blind to that copy');
    note('install.php mutation caught: ' . count($bad) . ' mismatch(es)');
});
