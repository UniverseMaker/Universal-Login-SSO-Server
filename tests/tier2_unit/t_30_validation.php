<?php
declare(strict_types=1);

/**
 * 티어 2 — 입력 검증·페이징 clamp·시각 변환·암복호 왕복
 *
 * 경계값만 본다. 여기서 새는 것은 대개 조용히 샌다 — 400 이 아니라
 * "값이 이상한 채로 통과" 가 이 계층의 실패 방식이다.
 */

require_once __DIR__ . '/../lib/loader.php';

load_core();

// ---------------------------------------------------------------------
// v_string
// ---------------------------------------------------------------------

t('v_string trims, enforces both bounds and counts characters not bytes', function () {
    require_ext('mbstring');
    assert_eq('abc', v_string(array('k' => '  abc  '), 'k'), 'must trim');
    // **길이는 mb_strlen 이다.** 바이트로 세면 한글 이름이 3배로 계산돼
    // 정상 입력이 거부된다 — 그 방향의 실패는 사용자만 겪고 로그에 안 남는다.
    assert_eq('가나다', v_string(array('k' => '가나다'), 'k', 3, 3), '3 chars, 9 bytes');
    assert_throws('AppError', function () {
        v_string(array('k' => '가나다라'), 'k', 1, 3);
    }, '4 chars must exceed a max of 3');
    // 경계 그 자체는 통과해야 한다
    assert_eq('abcde', v_string(array('k' => 'abcde'), 'k', 5, 5));
    assert_throws('AppError', function () {
        v_string(array('k' => 'abcd'), 'k', 5, 10);
    });
});

t('v_string treats whitespace-only as absent', function () {
    // 공백만 넣은 필수 항목이 통과하면 빈 사용자명이 DB 에 들어간다.
    assert_throws('AppError', function () {
        v_string(array('k' => '   '), 'k', 1, 10, true);
    }, 'whitespace-only must fail a required field');
    assert_eq('fallback', v_string(array('k' => '   '), 'k', 1, 10, false, 'fallback'));
    assert_eq('fallback', v_string(array(), 'k', 1, 10, false, 'fallback'));
    assert_eq(null, v_string(array(), 'k'));
});

t('v_string refuses non-scalar input instead of stringifying it', function () {
    // `(string) []` 는 "Array" 다. 조용히 통과시키면 그 문자열이 DB 에 저장된다.
    foreach (array(array('x'), true, false) as $bad) {
        assert_throws('AppError', function () use ($bad) {
            v_string(array('k' => $bad), 'k', 0, 255, true);
        }, 'non-scalar ' . gettype($bad));
    }
});

t('a validation failure names the field so the form can highlight it', function () {
    $e = assert_throws('AppError', function () {
        v_string(array(), 'username', 1, 10, true);
    });
    assert_eq('VALIDATION_FAILED', $e->code);
    assert_eq(400, $e->status);
    assert_true(isset($e->extra['field']), 'error must carry a field name');
    assert_eq('username', $e->extra['field']);
});

// ---------------------------------------------------------------------
// v_int / v_bool / v_email / v_enum
// ---------------------------------------------------------------------

t('v_int enforces bounds and rejects non-numeric input', function () {
    assert_eq(5, v_int(array('k' => '5'), 'k'));
    assert_eq(5, v_int(array('k' => 5), 'k'));
    assert_eq(-3, v_int(array('k' => '-3'), 'k'));
    assert_eq(1, v_int(array('k' => '1'), 'k', 1, 10), 'lower boundary passes');
    assert_eq(10, v_int(array('k' => '10'), 'k', 1, 10), 'upper boundary passes');
    assert_throws('AppError', function () {
        v_int(array('k' => '0'), 'k', 1, 10);
    });
    assert_throws('AppError', function () {
        v_int(array('k' => '11'), 'k', 1, 10);
    });
    foreach (array('abc', '1abc', true, array(1)) as $bad) {
        assert_throws('AppError', function () use ($bad) {
            v_int(array('k' => $bad), 'k');
        }, 'non-numeric ' . gettype($bad));
    }
    assert_eq(7, v_int(array(), 'k', null, null, false, 7), 'default when absent');
});

t('v_bool accepts the form-transport spellings and refuses everything else', function () {
    foreach (array('1', 'true', 'TRUE', 'on', 'yes', 'y', true) as $tv) {
        assert_eq(true, v_bool(array('k' => $tv), 'k'), 'truthy: ' . ut_show($tv));
    }
    foreach (array('0', 'false', 'off', 'no', 'n', false) as $fv) {
        // false 와 '0' 은 in_raw 에서 '' 취급되지 않아야 한다 — 그러면 기본값으로 새어 나간다
        assert_eq(false, v_bool(array('k' => $fv), 'k'), 'falsy: ' . ut_show($fv));
    }
    assert_throws('AppError', function () {
        v_bool(array('k' => 'maybe'), 'k');
    });
    assert_eq(null, v_bool(array(), 'k'));
    assert_eq(true, v_bool(array(), 'k', false, true), 'default when absent');
});

t('v_email lowercases and validates', function () {
    require_ext('mbstring');
    assert_eq('a@b.co', v_email(array('k' => '  A@B.CO '), 'k'));
    foreach (array('nope', 'a@', '@b.co', 'a b@c.co') as $bad) {
        assert_throws('AppError', function () use ($bad) {
            v_email(array('k' => $bad), 'k');
        }, 'invalid: ' . $bad);
    }
});

t('v_enum is the only safe route for values that cannot be bound (sort columns)', function () {
    $allowed = array('created_at', 'username', 'points');
    assert_eq('username', v_enum(array('k' => 'username'), 'k', $allowed));
    // 이 함수가 새면 정렬 컬럼이 SQL 로 그대로 들어간다
    assert_throws('AppError', function () use ($allowed) {
        v_enum(array('k' => 'id; DROP TABLE x'), 'k', $allowed);
    });
    assert_throws('AppError', function () use ($allowed) {
        v_enum(array('k' => 'USERNAME'), 'k', $allowed);
    }, 'comparison is strict and case-sensitive');
    assert_eq('created_at', v_enum(array(), 'k', $allowed, false, 'created_at'));
});

// ---------------------------------------------------------------------
// page_params — D-154(1) 딥 오프셋
// ---------------------------------------------------------------------

t('page_params clamps instead of erroring, on both axes', function () {
    // clamp 여야 하는 이유: 400 을 내면 관리자 콘솔의 페이저가 그린 번호 버튼이
    // 깨진다. 계약 §1 의 per_page 규약과도 어긋난다.
    list($p, $per, $off) = page_params(array('page' => 1, 'per_page' => 20));
    assert_eq(array(1, 20, 0), array($p, $per, $off));

    list($p, $per, $off) = page_params(array('page' => 3, 'per_page' => 10));
    assert_eq(array(3, 10, 20), array($p, $per, $off));

    // per_page 상한 100
    list($p, $per) = page_params(array('per_page' => 5000));
    assert_eq(100, $per);
    // page 상한 PAGE_MAX. 없으면 OFFSET 이 수천만이 되고 MySQL 은 버릴 행을 먼저 읽는다
    list($p, $per, $off) = page_params(array('page' => 999999, 'per_page' => 100));
    assert_eq(PAGE_MAX, $p);
    assert_eq((PAGE_MAX - 1) * 100, $off);
    // 최대 OFFSET 이 100만을 넘지 않아야 한다 (상한 설계의 근거)
    if ($off > 1000000) {
        fail('max OFFSET is ' . $off . ' - deeper than the 1,000,000 the clamp was sized for');
    }

    // 하한. 0·음수·비수치는 1 로
    foreach (array(0, -1, -999, 'abc') as $bad) {
        list($p) = page_params(array('page' => $bad));
        assert_eq(1, $p, 'page=' . ut_show($bad));
    }
    list($p, $per) = page_params(array('per_page' => 0), 25);
    assert_eq(25, $per, 'per_page<1 falls back to the caller default');
});

// ---------------------------------------------------------------------
// 시각 변환 — 계약 §1 시각 규약
// ---------------------------------------------------------------------

t('ts() and iso() round-trip UTC and treat the zero date as null', function () {
    assert_eq(null, ts(null));
    assert_eq(null, ts(''));
    assert_eq(null, ts('0000-00-00 00:00:00'), 'MySQL zero date must read as null, not 1970');
    assert_eq(null, iso(null));
    assert_eq(null, iso('0000-00-00 00:00:00'));

    // 저장은 UTC DATETIME, 응답은 ISO8601 Z. 이 변환이 로컬 타임존을 타면
    // 화면의 시각이 배포 서버마다 달라진다.
    assert_eq('2026-08-25T04:12:00Z', iso('2026-08-25 04:12:00'));
    $t = ts('2026-08-25 04:12:00');
    assert_eq(gmmktime(4, 12, 0, 8, 25, 2026), $t);
    // 왕복
    assert_eq('2026-08-25 04:12:00', gmdate('Y-m-d H:i:s', (int) $t));
});

t('now_utc() and at_utc() stay on UTC', function () {
    assert_match('~^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$~', now_utc());
    $n = ts(now_utc());
    assert_true(abs($n - time()) <= 2, 'now_utc must be the current UTC instant');
    $later = ts(at_utc(3600));
    assert_true(abs(($later - $n) - 3600) <= 2, 'at_utc(3600) must be an hour ahead');
});

t('json_object() keeps an empty map an object, not an array', function () {
    // 계약 §3-1 #33(meta) / §3-5(payload) 는 비어도 `{}` 를 요구한다.
    // PHP 의 빈 배열은 json_encode 에서 `[]` 가 되고, 프론트가 Object.keys() 를
    // 바로 쓰지 못한다.
    assert_eq('{}', json_encode(json_object(array())));
    assert_eq('{"a":1}', json_encode(json_object(array('a' => 1))));
});

// ---------------------------------------------------------------------
// 암복호·토큰·PKCE
// ---------------------------------------------------------------------

t('enc/dec round-trips and never produces the same ciphertext twice', function () {
    require_ext('openssl');
    $plain = 'sk-secret-value-0123456789';
    $a = enc($plain);
    $b = enc($plain);
    assert_eq($plain, dec($a));
    assert_eq($plain, dec($b));
    // 고정 IV 면 같은 평문이 같은 암호문이 되어 값 비교(= 시크릿 추측)를 허용한다
    assert_ne($a, $b, 'a fixed IV would let anyone compare secrets by ciphertext');
    // 빈 문자열·유니코드·긴 값
    assert_eq('', dec(enc('')));
    assert_eq('한글 시크릿', dec(enc('한글 시크릿')));
    assert_eq(str_repeat('z', 5000), dec(enc(str_repeat('z', 5000))));
});

t('dec() returns an empty string on garbage instead of throwing', function () {
    require_ext('openssl');
    // 예외를 밖으로 흘리면 복호 실패가 500 이 되고, 그것은 DB 의 한 행 때문에
    // 목록 전체가 죽는다는 뜻이다.
    foreach (array('', 'not-base64!!', base64_encode('short'), base64_encode(str_repeat('x', 40))) as $g) {
        assert_eq('', dec($g), 'garbage: ' . substr($g, 0, 16));
    }
});

t('token format check is strict about length and hex', function () {
    $tok = gen_token(32);
    assert_eq(64, strlen($tok));
    assert_true(is_token_format($tok));
    assert_false(is_token_format(null));
    assert_false(is_token_format(''));
    // 대문자 hex 도 형식으로는 통과한다 - ctype_xdigit 은 대소문자를 가리지 않는다.
    // 여기서 기록해 두는 이유: 이 함수는 **형식 필터**이지 정규화가 아니다.
    // 저장·조회는 token_hash() 를 거치고 그것은 원문 바이트에 민감하다 -
    // 대문자 토큰은 형식을 통과하지만 해시가 달라 조회에서 0건이 된다.
    assert_true(is_token_format(strtoupper($tok)), 'uppercase hex passes the format filter');
    assert_ne(token_hash($tok), token_hash(strtoupper($tok)), 'but the hash differs, so case still matters downstream');
    assert_eq(strtolower($tok), $tok, 'gen_token itself must emit lowercase');
    assert_false(is_token_format(substr($tok, 0, 63)));
    assert_false(is_token_format($tok . 'a'));
    assert_false(is_token_format(str_repeat('g', 64)), 'g is not a hex digit');
    // 두 번 부르면 달라야 한다
    assert_ne($tok, gen_token(32));
});

t('pkce_verify passes only the matching S256 challenge', function () {
    $verifier  = base64url(random_bytes(32));
    $challenge = base64url(hash('sha256', $verifier, true));
    assert_true(pkce_verify($challenge, $verifier));
    assert_false(pkce_verify($challenge, $verifier . 'x'));
    assert_false(pkce_verify($challenge, base64url(random_bytes(32))));
    // challenge 가 없으면 PKCE 미사용 클라이언트 — 통과
    assert_true(pkce_verify(null, null));
    assert_true(pkce_verify('', 'anything'));
    // challenge 가 있는데 verifier 가 없으면 실패해야 한다. 반대면 PKCE 가 무의미해진다
    assert_false(pkce_verify($challenge, null));
    assert_false(pkce_verify($challenge, ''));
});

t('base64url output carries no padding and no + or /', function () {
    for ($i = 1; $i <= 40; $i++) {
        $s = base64url(random_bytes($i));
        assert_not_contains('=', $s, 'padding at length ' . $i);
        assert_not_contains('+', $s, 'plus at length ' . $i);
        assert_not_contains('/', $s, 'slash at length ' . $i);
    }
});

t('gen_uuid4 has the version and variant bits set', function () {
    for ($i = 0; $i < 20; $i++) {
        $u = gen_uuid4();
        assert_match('~^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$~', $u);
    }
});
