<?php
declare(strict_types=1);

/**
 * `fixtures/positive/mutant_cookie.php` — **일부러 망가뜨린 헤더 조립기들.**
 *
 * **산출물이 아니다. 고치지 마라.** 여기 있는 함수는 전부 틀린 것이 정상이다.
 *
 * ─── 왜 필요한가 ──────────────────────────────────────────────────────
 * 티어 2 의 71 케이스 오라클이 "위반 0건"을 내는 방법은 두 가지다:
 *   (a) 코드가 정말 맞다
 *   (b) **불변식이 아무것도 검사하지 않는다**
 * 이 저장소에서 (b) 가 실제로 일어났다 — `tools/php72_scan.php` 에 규칙이
 * 영구히 발화하지 않는 버그가 있었다. 그래서 오라클에 **알려진 결함**을 먹여
 * 잡아내는지 확인한다. 못 잡으면 러너는 종료코드 4 로 멈춘다.
 *
 * 각 변이는 **실제로 이 저장소에서 났던 사고**를 재현한다.
 * ─────────────────────────────────────────────────────────────────────
 */

/**
 * 변이 1 — `HttpOnly` 를 떨어뜨린다.
 * v1.1.0 이 잡은 사고 그 자체: 7.2 분기에서 속성이 조용히 빠져,
 * 응답은 200 이고 로그인은 되는데 세션 쿠키를 JS 가 읽을 수 있게 된다.
 */
function mutant_drop_httponly(string $name, string $value, array $opt): string
{
    $opt['httponly'] = false;
    return build_set_cookie_header($name, $value, $opt);
}

/**
 * 변이 2 — `SameSite` 를 떨어뜨린다.
 * 같은 사고의 다른 절반. CSRF 방어의 한 층이 통째로 사라진다.
 */
function mutant_drop_samesite(string $name, string $value, array $opt): string
{
    unset($opt['samesite']);
    return build_set_cookie_header($name, $value, $opt);
}

/**
 * 변이 3 — 만료 날짜를 RFC 7231 공백 형식으로 쓴다.
 * **D-68 이 명시적으로 경고한 되돌림이다.** 독스트링에 한때 `01 Jan 1970`(공백)이라고
 * 적힌 줄이 있었고, 그것을 근거로 코드를 "고치면" 7.2 배포에서만 다른 만료 문자열이
 * 나간다. 12a 라운드가 두 분기 헤더 대조로 실측해 잡은 결함이 정확히 이것이다.
 */
function mutant_rfc7231_date(string $name, string $value, array $opt): string
{
    $h = build_set_cookie_header($name, $value, $opt);
    return preg_replace_callback('~; expires=([^;]+)~', function ($m) {
        return '; expires=' . str_replace('-', ' ', $m[1]);
    }, $h);
}

/**
 * 변이 4 — 이름을 URL 인코딩한다.
 * 감사 V11-L-1. 예전 구현이 이랬고 주석에 "검증을 통과했으므로 항등이다" 라고
 * 적혀 있었으나 **거짓이었다** — `ulogin[sid]` 가 7.2 에서만 `ulogin%5Bsid%5D` 로
 * 나가 로그인이 조용히 깨졌다.
 */
function mutant_encode_name(string $name, string $value, array $opt): string
{
    $h = build_set_cookie_header($name, $value, $opt);
    $eq = strpos($h, '=');
    if ($eq === false) {
        return $h;
    }
    return rawurlencode(substr($h, 0, $eq)) . substr($h, $eq);
}

/**
 * 변이 5 — 잘못된 옵션을 거부하지 않고 통과시킨다.
 * 7.2 분기에 본체의 검증 층이 없다는 사실 그 자체. 이것이 통과하면
 * `COOKIE_PATH = '/; HttpOnly'` 같은 설정이 속성 주입이 된다.
 */
function mutant_no_separator_check(string $name, string $value, array $opt): string
{
    $out = $name . '=' . rawurlencode($value);
    foreach (array('path', 'domain') as $k) {
        if (isset($opt[$k]) && (string) $opt[$k] !== '') {
            $out .= '; ' . $k . '=' . $opt[$k];
        }
    }
    if (!empty($opt['secure'])) {
        $out .= '; secure';
    }
    if (!empty($opt['httponly'])) {
        $out .= '; HttpOnly';
    }
    if (isset($opt['samesite']) && (string) $opt['samesite'] !== '') {
        $out .= '; SameSite=' . $opt['samesite'];
    }
    return $out;
}

/**
 * 변이 6 — 속성 순서를 뒤집는다.
 * 본체와 바이트가 달라진다. 기능은 대개 멀쩡해 보이지만, 두 분기 대조가
 * 순서를 안 보면 이 차이를 영원히 못 잡는다.
 */
function mutant_reorder(string $name, string $value, array $opt): string
{
    $h = build_set_cookie_header($name, $value, $opt);
    $parts = explode('; ', $h);
    $head = array_shift($parts);
    $parts = array_reverse($parts);
    array_unshift($parts, $head);
    return implode('; ', $parts);
}

/**
 * 변이 7 — 삭제 경로에서 `deleted` 대신 빈 값을 쓴다.
 * 본체가 `deleted` 를 쓰는 이유는 MSIE 가 빈 값으로는 쿠키를 지우지 않기 때문이다.
 * 로그아웃이 일부 브라우저에서만 안 되는, 재현이 어려운 종류가 된다.
 */
function mutant_empty_delete(string $name, string $value, array $opt): string
{
    if ($value !== '') {
        return build_set_cookie_header($name, $value, $opt);
    }
    return $name . '=; expires=Thu, 01-Jan-1970 00:00:01 GMT; Max-Age=0'
        . (isset($opt['path']) && $opt['path'] !== '' ? '; path=' . $opt['path'] : '');
}
