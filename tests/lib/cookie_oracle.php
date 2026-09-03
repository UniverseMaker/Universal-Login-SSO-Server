<?php
declare(strict_types=1);

/**
 * tests/lib/cookie_oracle.php — `Set-Cookie` 헤더 조립 케이스 행렬과 불변식 오라클
 *
 * ─────────────────────────────────────────────────────────────────────
 * **복원한 하네스다.** v1.1.0 의 `15a`/`17a` 라운드가 "61 케이스" → "71 케이스
 * SAME / DIFF 0" 을 만들어 냈고, 그 코드는 스크래치에만 있었다. 리포트에는 숫자만
 * 남고 코드는 사라졌다. 다음 버전이 쿠키 조립을 건드리면 처음부터 다시 만들어야 했다.
 * 여기가 그 자리다.
 *
 * **두 곳에서 쓴다 — 케이스 정의는 하나다.**
 *   · 티어 2 `t_10_cookie_header.php`  — `build_set_cookie_header()` 의 **불변식**.
 *                                        DB·서버 불필요. 밀리초 단위
 *   · 티어 3 `t_30_cookie_branches.php` — 실 SAPI 에서 **두 분기 바이트 대조**
 *                                        (7.3+ 본체 `setcookie()` vs 7.2 수동 조립).
 *                                        `php -S` 필요
 * ─────────────────────────────────────────────────────────────────────
 *
 * ─── 왜 골든 스냅샷이 아니라 불변식인가 (D-73) ────────────────────────
 * 지금 코드의 출력을 파일에 얼려 두고 대조하면, 그 테스트는 "코드가 코드대로
 * 동작한다"만 말한다. **자기 참조 단언이다.** 그래서 대신 PHP 본체
 * (`ext/standard/head.c` 의 `php_setcookie()`)가 지키는 성질을 **독립적으로**
 * 적는다 — 속성 유무의 조건, 출력 순서, 삭제 경로의 표기, 날짜 형식.
 * 이 성질들은 코드가 아니라 **본체의 계약**에서 나온다. 코드가 바뀌면 테스트가
 * 깨지고, 그것이 정확히 원하는 바다.
 * ─────────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/harness.php';

/**
 * 케이스 행렬. **정상 · 잘못된 입력 · D-75 계열**의 세 묶음.
 *
 * `expect`
 *   'header' — 문자열을 돌려줘야 한다. 불변식 전체가 적용된다
 *   'throw'  — `AppError` 를 던져야 한다 (본체가 거부하는 값)
 *
 * @return array<int, array>
 */
function cookie_cases(): array
{
    $now = time();
    $cs = array();

    $add = function (string $id, string $name, string $value, array $opt, string $expect = 'header') use (&$cs) {
        $cs[] = array('id' => $id, 'name' => $name, 'value' => $value, 'opt' => $opt, 'expect' => $expect);
    };

    // ── A. 정상 경로 ────────────────────────────────────────────────
    // 세션 쿠키의 실제 형태에서 출발해 속성을 하나씩 흔든다.
    $base = array('expires' => $now + 3600, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax');

    $add('A01', 'ULOGIN_SID', 'abc123', $base);
    $add('A02', 'ULOGIN_SID', 'abc123', array_merge($base, array('secure' => false)));
    $add('A03', 'ULOGIN_SID', 'abc123', array_merge($base, array('httponly' => false)));
    $add('A04', 'ULOGIN_SID', 'abc123', array_merge($base, array('secure' => false, 'httponly' => false)));
    $add('A05', 'ULOGIN_SID', 'abc123', array_merge($base, array('samesite' => 'Strict')));
    $add('A06', 'ULOGIN_SID', 'abc123', array_merge($base, array('samesite' => 'None')));
    $add('A07', 'ULOGIN_SID', 'abc123', array_merge($base, array('samesite' => '')));
    $add('A08', 'ULOGIN_SID', 'abc123', array_merge($base, array('path' => '')));
    $add('A09', 'ULOGIN_SID', 'abc123', array_merge($base, array('path' => '/app')));
    $add('A10', 'ULOGIN_SID', 'abc123', array_merge($base, array('path' => '/a/b/c')));
    $add('A11', 'ULOGIN_SID', 'abc123', array_merge($base, array('domain' => 'example.com')));
    $add('A12', 'ULOGIN_SID', 'abc123', array_merge($base, array('domain' => '.example.com')));
    $add('A13', 'ULOGIN_SID', 'abc123', array_merge($base, array('domain' => '')));
    $add('A14', 'ULOGIN_SID', 'abc123', array_merge($base, array('expires' => 0)));            // 브라우저 세션 쿠키
    $add('A15', 'ULOGIN_SID', 'abc123', array_merge($base, array('expires' => $now - 100)));   // 이미 과거
    $add('A16', 'ULOGIN_SID', 'abc123', array_merge($base, array('expires' => $now + 1)));
    $add('A17', 'ULOGIN_SID', 'abc123', array_merge($base, array('expires' => $now + 2592000)));  // 30일
    $add('A18', 'ULOGIN_SID', '', $base);                                                      // **삭제 경로**
    $add('A19', 'ULOGIN_SID', '', array('expires' => 0, 'path' => '/'));                       // 삭제 + 최소 옵션
    $add('A20', 'ULOGIN_CSRF', 'deadbeef', $base);
    $add('A21', 'ULOGIN_SID', 'a b+c', $base);                                                 // 인코딩 필요
    $add('A22', 'ULOGIN_SID', 'a=b;c', $base);                                                 // 구분자 -> 인코딩돼야 한다
    $add('A23', 'ULOGIN_SID', '한글값', $base);                                                // 비 ASCII
    $add('A24', 'ULOGIN_SID', '%2F%3A', $base);                                                // 이미 인코딩된 것
    $add('A25', 'ULOGIN_SID', str_repeat('x', 512), $base);                                    // 긴 값
    $add('A26', 'ULOGIN_SID', 'abc123', array('expires' => $now + 60));                        // 옵션 최소 (하한선 없음)
    $add('A27', 'ULOGIN_SID', 'abc123', array());                                              // 옵션 전무
    $add('A28', 'ulogin_sid_lower', 'v', $base);
    $add('A29', 'ULOGIN-SID', 'v', $base);                                                     // 하이픈 이름
    $add('A30', 'a', 'v', $base);                                                              // 1자 이름
    $add('A31', 'ULOGIN_SID', '0', $base);                                                     // falsy 값이지만 빈 문자열이 아니다
    $add('A32', 'ULOGIN_SID', 'v', array_merge($base, array('secure' => 1, 'httponly' => 1)));      // truthy 정수
    $add('A33', 'ULOGIN_SID', 'v', array_merge($base, array('secure' => 0, 'httponly' => 0)));      // falsy 정수
    $add('A34', 'ULOGIN_SID', 'v', array_merge($base, array('secure' => '1')));                     // truthy 문자열
    $add('A35', 'ULOGIN_SID', 'v', array_merge($base, array('secure' => '')));                      // falsy 문자열
    $add('A36', 'ULOGIN_SID', 'v', array_merge($base, array('expires' => (string) ($now + 60))));   // 숫자 문자열
    $add('A37', 'ULOGIN_SID', 'v', array('expires' => $now + 60, 'path' => '/', 'samesite' => 'None', 'secure' => true));
    $add('A38', 'ULOGIN_SID', 'v', array('expires' => $now + 60, 'domain' => 'sub.example.co.kr', 'path' => '/x'));
    // ── 이름에 **인코딩 대상 문자**가 든 경우 ────────────────────────
    // 본체는 이름을 검증만 하고 **원문 그대로** 내보낸다. 예전 구현이
    // `rawurlencode($name)` 를 통과시켰고 `ulogin[sid]` 가 7.2 에서만
    // `ulogin%5Bsid%5D` 로 나가 로그인이 조용히 깨졌다 (감사 V11-L-1).
    // `strpbrk($name, "=,; \t\r\n\013\014")` 는 이 문자들을 걸러내지 않는다 —
    // 그래서 "검증을 통과했으므로 인코딩은 항등이다" 라는 주석이 거짓이었다.
    $add('A39', 'ulogin[sid]', 'v', $base);
    $add('A40', 'ulogin+sid', 'v', $base);
    $add('A41', 'ulogin/sid', 'v', $base);
    $add('A42', 'ulogin:sid', 'v', $base);
    $add('A43', 'ulogin@sid', 'v', $base);
    $add('A44', 'ulogin!sid', 'v', $base);
    $add('A45', 'ulogin%sid', 'v', $base);
    $add('A46', 'ulogin(sid)', 'v', $base);
    $add('A47', 'ulogin*sid', 'v', $base);
    $add('A48', "ulogin'sid", 'v', $base);

    // ── I. 본체가 거부하는 입력 — 두 분기가 **같은 지점에서 같은 예외**로 실패해야 한다 ──
    // (D-65) 검증을 7.2 분기 안에만 두면 7.3+ 는 본체가 다른 방식으로 실패해 갈라진다.
    $seps = array(
        'comma'   => ',',
        'semi'    => ';',
        'space'   => ' ',
        'tab'     => "\t",
        'cr'      => "\r",
        'lf'      => "\n",
        'vt'      => "\013",
        'ff'      => "\014",
    );
    $i = 0;
    foreach ($seps as $sname => $ch) {
        $i++;
        $add(sprintf('I%02d', $i), 'ULOGIN_SID', 'v', array_merge($base, array('path' => '/a' . $ch . 'b')), 'throw');
        $i++;
        $add(sprintf('I%02d', $i), 'ULOGIN_SID', 'v', array_merge($base, array('domain' => 'a' . $ch . 'b')), 'throw');
        $i++;
        $add(sprintf('I%02d', $i), 'ULOGIN_SID', 'v', array_merge($base, array('samesite' => 'Lax' . $ch . 'x')), 'throw');
    }
    // 속성 주입의 실제 형태 — 이것이 통과하면 7.2 배포에서만 조용히 속성이 붙는다
    $i++;
    $add(sprintf('I%02d', $i), 'ULOGIN_SID', 'v', array('path' => '/; HttpOnly', 'httponly' => false), 'throw');
    $i++;
    $add(sprintf('I%02d', $i), 'ULOGIN_SID', 'v', array('domain' => 'evil.example, x', 'path' => '/'), 'throw');
    $i++;
    $add(sprintf('I%02d', $i), 'ULOGIN_SID', 'v', array('samesite' => 'Lax; Domain=evil.example', 'path' => '/'), 'throw');
    // 빈 문자열은 거부 대상이 아니다 (본체도 통과시키고 속성을 생략한다)
    $i++;
    $add(sprintf('I%02d', $i), 'ULOGIN_SID', 'v', array('path' => '', 'domain' => '', 'samesite' => ''));

    // ── D. D-75 계열 — `secure` 실효값이 걸린 조합 ───────────────────
    // 이 묶음은 티어 2 에서 조립 결과만 보고, `secure` 와 평문 HTTP 의 모순 판정은
    // `send_cookie()` 의 책임이라 티어 2 의 다른 파일과 티어 3 이 본다.
    $add('D01', 'ULOGIN_SID', 'v', array('secure' => true,  'httponly' => true,  'samesite' => 'None', 'path' => '/'));
    $add('D02', 'ULOGIN_SID', 'v', array('secure' => false, 'httponly' => true,  'samesite' => 'None', 'path' => '/'));
    $add('D03', 'ULOGIN_SID', 'v', array('secure' => true,  'httponly' => false, 'samesite' => 'Strict', 'path' => '/'));
    $add('D04', 'ULOGIN_SID', 'v', array('secure' => true,  'path' => '/'));
    $add('D05', 'ULOGIN_SID', 'v', array('httponly' => true, 'path' => '/'));
    $add('D06', 'ULOGIN_SID', '',  array('secure' => true,  'httponly' => true, 'samesite' => 'Lax', 'path' => '/'));
    $add('D07', 'ULOGIN_SID', '',  array('secure' => false, 'httponly' => true, 'samesite' => 'Lax', 'path' => '/'));
    $add('D08', 'ULOGIN_CSRF', 'v', array('secure' => true, 'httponly' => false, 'samesite' => 'Lax', 'path' => '/', 'expires' => $now + 3600));
    $add('D09', 'ULOGIN_SID', 'v', array('secure' => true, 'httponly' => true, 'samesite' => 'Lax', 'path' => '/', 'domain' => 'example.com', 'expires' => $now + 7200));
    $add('D10', 'ULOGIN_SID', 'v', array('secure' => true, 'httponly' => true, 'samesite' => 'Lax', 'path' => '/', 'expires' => $now + 1209600));

    return $cs;
}

/**
 * 한 케이스에 대한 **불변식 검사**. 위반 목록을 돌려준다 (빈 배열 = 통과).
 *
 * 성질은 전부 PHP 본체 `php_setcookie()` 의 계약에서 나온다. 코드의 현재 출력을
 * 얼려 둔 것이 아니다 — 그래야 코드가 틀렸을 때 테스트가 깨진다.
 *
 * @param callable $build `function(string $name, string $value, array $opt): string`
 */
function cookie_check_invariants(callable $build, array $case): array
{
    $v = array();
    $name = $case['name'];
    $value = $case['value'];
    $opt = $case['opt'];

    if ($case['expect'] === 'throw') {
        try {
            $build($name, $value, $opt);
            $v[] = 'expected a throw (PHP core rejects this value) but a header was returned';
        } catch (Throwable $e) {
            // 어떤 예외든 좋다. 중요한 것은 **조용히 통과하지 않는 것**이다.
        }
        return $v;
    }

    try {
        $h = $build($name, $value, $opt);
    } catch (Throwable $e) {
        return array('unexpected ' . get_class($e) . ': ' . $e->getMessage());
    }
    if (!is_string($h)) {
        return array('did not return a string');
    }

    // --- 1. 헤더 안전성. 여기가 뚫리면 헤더 인젝션이다 ---
    if (strpbrk($h, "\r\n\0") !== false) {
        $v[] = 'output contains CR, LF or NUL';
    }

    // --- 2. 이름은 **인코딩하지 않는다** (본체와 동일. 감사 V11-L-1) ---
    if (strncmp($h, $name . '=', strlen($name) + 1) !== 0) {
        $v[] = 'must start with the raw, unencoded name followed by "=" (got: ' . substr($h, 0, 40) . ')';
    }

    $isDelete = ($value === '');

    // --- 3. 삭제 경로 — 본체는 빈 값에 `deleted` 를 쓴다 (MSIE 대응) ---
    if ($isDelete) {
        if (strpos($h, $name . '=deleted') !== 0) {
            $v[] = 'empty value must produce "<name>=deleted"';
        }
        if (strpos($h, 'Max-Age=0') === false) {
            $v[] = 'delete path must carry Max-Age=0';
        }
        if (strpos($h, 'expires=Thu, 01-Jan-1970 00:00:01 GMT') === false) {
            $v[] = 'delete path must carry the epoch expiry in Netscape format';
        }
    } else {
        // --- 4. 값은 rawurlencode ---
        $enc = rawurlencode($value);
        if (strncmp($h, $name . '=' . $enc, strlen($name) + 1 + strlen($enc)) !== 0) {
            $v[] = 'value must be rawurlencode()d right after the name';
        }
        // --- 5. expires / Max-Age ---
        $exp = isset($opt['expires']) ? (int) $opt['expires'] : 0;
        if ($exp > 0) {
            if (strpos($h, '; expires=') === false) {
                $v[] = 'expires>0 must emit an "expires=" attribute';
            }
            if (!preg_match('~; Max-Age=(\d+)~', $h, $m)) {
                $v[] = 'expires>0 must emit "Max-Age="';
            } else {
                $want = max(0, $exp - time());
                // 초 경계에서 1초 어긋날 수 있다 — 17a 도 같은 스큐를 관측했다.
                if (abs(((int) $m[1]) - $want) > 2) {
                    $v[] = 'Max-Age should be max(0, expires-now)=' . $want . ' but is ' . $m[1];
                }
            }
            // --- 6. 날짜 형식은 **구 Netscape (하이픈)**. D-68 회귀 방지 ---
            if (preg_match('~; expires=([^;]+)~', $h, $m)) {
                if (preg_match('~^[A-Z][a-z]{2}, \d{2}-[A-Z][a-z]{2}-\d{4} \d{2}:\d{2}:\d{2} GMT$~', trim($m[1])) !== 1) {
                    $v[] = 'expires must use the hyphenated Netscape format "D, d-M-Y H:i:s GMT" (D-68), got "'
                        . trim($m[1]) . '"';
                }
            }
        } else {
            if (strpos($h, '; expires=') !== false) {
                $v[] = 'expires<=0 must emit no "expires=" attribute (browser session cookie)';
            }
            if (strpos($h, 'Max-Age=') !== false) {
                $v[] = 'expires<=0 must emit no "Max-Age"';
            }
        }
    }

    // --- 7. path / domain: 값이 빈 문자열이면 속성 자체를 생략한다 ---
    $path = isset($opt['path']) ? (string) $opt['path'] : '';
    if ($path !== '') {
        if (strpos($h, '; path=' . $path) === false) {
            $v[] = 'path="' . $path . '" must appear as "; path=' . $path . '"';
        }
    } elseif (strpos($h, '; path=') !== false) {
        $v[] = 'empty path must emit no path attribute';
    }
    $dom = isset($opt['domain']) ? (string) $opt['domain'] : '';
    if ($dom !== '') {
        if (strpos($h, '; domain=' . $dom) === false) {
            $v[] = 'domain="' . $dom . '" must appear as "; domain=' . $dom . '"';
        }
    } elseif (strpos($h, '; domain=') !== false) {
        $v[] = 'empty domain must emit no domain attribute';
    }

    // --- 8. 플래그는 실효값과 **정확히** 일치해야 한다 ---
    //     이 두 줄이 v1.1.0 의 사고("HttpOnly·SameSite 가 조용히 빠진다")를 직접 겨눈다.
    $wantSecure = !empty($opt['secure']);
    $hasSecure  = (strpos($h, '; secure') !== false);
    if ($wantSecure !== $hasSecure) {
        $v[] = 'secure flag mismatch: option says ' . ($wantSecure ? 'on' : 'off')
            . ' but header ' . ($hasSecure ? 'has' : 'lacks') . ' "; secure"';
    }
    $wantHttpOnly = !empty($opt['httponly']);
    $hasHttpOnly  = (strpos($h, '; HttpOnly') !== false);
    if ($wantHttpOnly !== $hasHttpOnly) {
        $v[] = 'HttpOnly flag mismatch: option says ' . ($wantHttpOnly ? 'on' : 'off')
            . ' but header ' . ($hasHttpOnly ? 'has' : 'lacks') . ' "; HttpOnly"';
    }
    $ss = isset($opt['samesite']) ? (string) $opt['samesite'] : '';
    if ($ss !== '') {
        if (strpos($h, '; SameSite=' . $ss) === false) {
            $v[] = 'samesite="' . $ss . '" must appear as "; SameSite=' . $ss . '"';
        }
    } elseif (strpos($h, '; SameSite') !== false) {
        $v[] = 'empty samesite must emit no SameSite attribute';
    }

    // --- 9. 속성 **순서**. 본체의 조립 순서를 그대로 따라야 한다 ---
    $order = array('; expires=', '; Max-Age=', '; path=', '; domain=', '; secure', '; HttpOnly', '; SameSite=');
    $prev = -1;
    $prevName = '';
    foreach ($order as $tokn) {
        $at = strpos($h, $tokn);
        if ($at === false) {
            continue;
        }
        if ($at < $prev) {
            $v[] = 'attribute order broken: "' . trim($tokn) . '" appears before "' . trim($prevName) . '"';
        }
        $prev = $at;
        $prevName = $tokn;
    }

    return $v;
}
