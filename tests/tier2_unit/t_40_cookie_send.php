<?php
declare(strict_types=1);

/**
 * 티어 2 — `send_cookie()` 의 하한선과 fail-closed 3종
 *
 * `build_set_cookie_header()` 는 "주어진 배열 그대로" 만 진다. 누락을 안전한 쪽으로
 * 채우고 모순을 거부하는 것은 전부 `send_cookie()` 의 책임이고, 그 판정은 반드시
 * **버전 분기보다 앞**에 있어야 한다 (D-65). 분기 안에 두면 7.2 와 7.3+ 가
 * 서로 다른 방식으로 실패해 두 분기가 다시 갈라진다.
 *
 * ─── CLI 에서 볼 수 있는 것과 없는 것 ─────────────────────────────────
 *  볼 수 있다 : 반환값(true/false), 던지는 예외, 하한선이 적용된 뒤의 판정
 *  볼 수 없다 : **실제로 나가는 헤더 바이트.** CLI SAPI 에서 `header()` 는 무동작이고
 *               `headers_list()` 는 빈 배열이며 `setcookie()` 도 아무것도 안 남긴다.
 *               두 분기 바이트 대조는 **티어 3** 이 `php -S` 로 한다
 * ─────────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/../lib/loader.php';

load_core();

/** 스텁 토글. 이 파일 안에서만 쓴다. */
function with_https(bool $on, callable $fn)
{
    $was = $GLOBALS['ULOGIN_TEST_IS_HTTPS'];
    $GLOBALS['ULOGIN_TEST_IS_HTTPS'] = $on;
    try {
        return $fn();
    } finally {
        $GLOBALS['ULOGIN_TEST_IS_HTTPS'] = $was;
    }
}

t('send_cookie rejects an invalid cookie name and returns false without throwing', function () {
    // 이름은 `build_set_cookie_header()` 에서 **무인코딩으로** 나간다(본체와 동일).
    // 즉 이 검증이 이름의 유일한 방어선이다. 뚫리면 헤더 인젝션이다.
    with_https(true, function () {
        foreach (array('', 'a=b', 'a,b', 'a;b', 'a b', "a\tb", "a\rb", "a\nb", "a\013b", "a\014b", "a\0b") as $bad) {
            assert_false(send_cookie($bad, 'v', array('path' => '/')), 'name ' . json_encode($bad));
        }
        // 정상 이름은 통과
        assert_true(send_cookie('ULOGIN_SID', 'v', array('path' => '/')));
    });
});

t('send_cookie throws on an unknown option key instead of ignoring it', function () {
    // 본체는 알 수 없는 키를 거부한다(8.0+ ValueError / 7.3~7.4 경고+false).
    // 7.2 분기가 조용히 무시하면 오타 난 키(`samesit`)가 **7.2 에서만** 속성 누락이 된다.
    with_https(true, function () {
        $e = assert_throws('AppError', function () {
            send_cookie('X', 'v', array('samesit' => 'Lax'));
        }, 'a typo in an option key must not pass silently');
        assert_eq('SERVER_ERROR', $e->code);
        assert_eq(500, $e->status);

        assert_throws('AppError', function () {
            send_cookie('X', 'v', array('path' => '/', 'maxage' => 60));
        });
        assert_throws('AppError', function () {
            send_cookie('X', 'v', array('Secure' => true));
        }, 'option keys are lowercase; "Secure" is unknown');
    });
});

t('send_cookie throws on a cookie separator in path, domain or samesite', function () {
    // `COOKIE_PATH = "/; HttpOnly"` 같은 설정이 7.2 배포에서만 조용히 통과하면
    // 속성 주입이 된다 — 실측으로 `httponly=false` 인데 `HttpOnly` 가 붙었다.
    with_https(true, function () {
        assert_throws('AppError', function () {
            send_cookie('X', 'v', array('path' => '/; HttpOnly'));
        });
        assert_throws('AppError', function () {
            send_cookie('X', 'v', array('domain' => 'a,b'));
        });
    });
});

t('send_cookie floors missing attributes to the safe side, before the version branch', function () {
    // 옵션 배열이 불완전하게 넘어오면 `Set-Cookie: ULOGIN_SID=v` (속성 전무)가
    // 조용히 나간다 — 발견이 매우 어려운 종류다. 하한선은 그것을 막는다.
    // CLI 에서 헤더를 볼 수 없으므로 **하한선의 관측 가능한 결과**로 판정한다:
    // COOKIE_SECURE=true 인 이 로더에서 `secure` 를 생략하면 true 로 채워지고,
    // 따라서 평문 HTTP 에서 D-75 가 발동해야 한다.
    with_https(false, function () {
        assert_throws('AppError', function () {
            send_cookie('X', 'v', array('path' => '/'));   // secure 미지정
        }, 'a missing secure must be floored to COOKIE_SECURE(=true), which then trips D-75 on plain HTTP');
    });
    // HTTPS 에서는 같은 호출이 통과한다 — 하한선 자체가 실패를 만드는 게 아니다
    with_https(true, function () {
        assert_true(send_cookie('X', 'v', array('path' => '/')));
    });
});

t('SameSite=None forces secure even when the caller said false (D-66)', function () {
    // 브라우저 요구사항. Secure 없는 SameSite=None 쿠키는 통째로 버려지고,
    // 서버는 200 을 내며 로그에 아무것도 남지 않는다.
    with_https(false, function () {
        assert_throws('AppError', function () {
            send_cookie('X', 'v', array('path' => '/', 'secure' => false, 'samesite' => 'None'));
        }, 'None must force secure=true, which then trips D-75 on plain HTTP');
    });
    with_https(true, function () {
        assert_true(send_cookie('X', 'v', array('path' => '/', 'secure' => false, 'samesite' => 'None')));
    });
});

t('an invalid samesite value is floored, not passed through (V11-L-4)', function () {
    with_https(true, function () {
        // 오타는 통과하되 Lax 로 떨어진다 (예외가 아니다 — 설정 오타로 로그인을 막지 않는다)
        assert_true(send_cookie('X', 'v', array('path' => '/', 'samesite' => 'lax')));
        assert_true(send_cookie('X', 'v', array('path' => '/', 'samesite' => 'bogus')));
        // 속성 주입 시도도 마찬가지로 **떨어진다.** 예외가 아니라 Lax 로 바뀐다.
        //
        // 순서를 기록해 둔다 — 하한선(화이트리스트)이 `cookie_attr_offender()` 보다
        // **앞**이다. 그래서 `'Lax; Domain=evil.example'` 는 구분자 검사에 닿기 전에
        // 화이트리스트 밖으로 걸려 Lax 가 되고, 구분자 검사는 깨끗한 'Lax' 를 본다.
        // 결과적으로 주입 문자열은 헤더에 **절대 도달하지 않는다** — 안전하다.
        // `send_cookie()` 경로에서 samesite 에 대한 구분자 검사가 사실상 도달 불가라는
        // 뜻이기도 하다. 그 검사는 `build_set_cookie_header()` 를 직접 부르는 코드를
        // 위한 두 번째 방어선으로 남아 있다(그쪽은 하한선을 거치지 않는다).
        // **이 순서를 뒤집지 마라** — 뒤집으면 오타 하나가 로그인을 500 으로 만든다.
        assert_true(send_cookie('X', 'v', array('path' => '/', 'samesite' => 'Lax; Domain=evil.example')),
            'an injection attempt in samesite is floored to the whitelist, not raised');
        // 그리고 하한선을 거치지 않는 직접 호출은 여전히 거부된다
        assert_throws('AppError', function () {
            build_set_cookie_header('X', 'v', array('path' => '/', 'samesite' => 'Lax; Domain=evil.example'));
        }, 'the direct call has no flooring, so the separator check must fire there');
    });
});

t('D-75: a secure cookie on a plain-HTTP request is refused, not silently downgraded', function () {
    // 자동 강등(secure 를 떼기)을 하면 세션 쿠키가 평문으로 흐른다 —
    // 이 시스템이 막으려는 것 자체다. 설정을 조용히 약화시키는 대신 멈추고 알린다.
    with_https(false, function () {
        $e = assert_throws('AppError', function () {
            send_cookie('X', 'v', array('path' => '/', 'secure' => true));
        });
        assert_eq('SERVER_ERROR', $e->code);
        assert_eq(500, $e->status);
    });
    // secure 가 아닌 쿠키는 평문에서도 정상이다 — 판정이 과하면 평문 배포가 통째로 죽는다
    with_https(false, function () {
        assert_true(send_cookie('X', 'v', array('path' => '/', 'secure' => false)));
    });
    // HTTPS 면 통과
    with_https(true, function () {
        assert_true(send_cookie('X', 'v', array('path' => '/', 'secure' => true)));
    });
});

t('the D-75 judgement reads the effective secure value, not the COOKIE_SECURE constant', function () {
    // 하한선이 `secure` 를 강제하는 두 경로(상수 미정의 / SameSite=None)는
    // 상수만 보면 검사를 빠져나가면서 `Secure` 는 붙은 채로 나간다 —
    // D-75 가 없애려는 "200 인데 세션 없음" 이 그대로 남는다.
    // 이 로더는 COOKIE_SECURE=true 라 그 경로를 여기서 완전히 재현할 수 없다.
    // 상수를 바꾼 세계는 **자식 프로세스**로 본다 (상수는 재정의가 불가능하다).
    $root = str_replace('\\', '/', h_root());
    $code = <<<'PHPSNIPPET'
$root = ROOT_PLACEHOLDER;
define('ULOGIN_BOOTSTRAPPED', 1);
// COOKIE_SECURE 를 **정의하지 않는다** - 구버전 config.php 를 흉내 낸다.
define('DB_PREFIX', 'x_');
define('COOKIE_PATH', '/');
define('COOKIE_SAMESITE', 'Lax');
function request_is_https() { return false; }   // 평문 HTTP
require $root . '/server/core/response.php';
require $root . '/server/core/request.php';
try {
    // secure 를 주지 않는다. 하한선이 COOKIE_SECURE 미정의 -> true 로 채워야 한다.
    $r = send_cookie('X', 'v', array('path' => '/'));
    echo "NO_THROW:" . var_export($r, true) . "\n";
} catch (Throwable $e) {
    echo "THREW:" . get_class($e) . ':' . $e->getMessage() . "\n";
}
PHPSNIPPET;
    $code = str_replace('ROOT_PLACEHOLDER', var_export($root, true), $code);
    list($exit, $out) = run_php_snippet($code);
    if (strpos($out, 'THREW:') === false) {
        fail('with COOKIE_SECURE undefined on plain HTTP, send_cookie must floor secure to true and then refuse. got: '
            . trim($out));
    }
    assert_contains('AppError', $out, 'must fail with the standard AppError envelope, not a raw error');
});
