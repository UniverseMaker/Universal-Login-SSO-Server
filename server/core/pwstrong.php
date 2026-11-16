<?php
declare(strict_types=1);

/**
 * server/core/pwstrong.php — 관리자·비상 계정 비밀번호 강화 규칙 (v1.9.1 D-362, v1.10.1 D-373 10자)
 * 담당 판정: `_workspace/128_leader_decisions_v1100.md` D-375 L-2
 *
 * v1.10.1 보안 L-2 — 설치기(server/install.php)의 첫 superadmin 도 같은 규칙을 써야 한다. 설치기는 bootstrap 을
 * require 하지 않으므로(config.php 가 아직 없다) 규칙 본문을 **의존성 없는 이 파일**로 옮겼다. core/emergency.php 와
 * install.php 가 둘 다 이 파일을 require 한다 — 규칙 사본은 0개다(10자·흔한 비밀번호 목록·반복·키보드 연속).
 * 이 파일은 DB·config·다른 core 함수를 부르지 않는다(mb_*·preg_* 만). 직접 실행되면 404.
 * PHP 7.2 호환(core/bootstrap.php 상단 금지 목록).
 */

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404);
    exit;
}

// 반드시 require_once 로 올린다(상수·함수 재정의 방지). const 는 블록 안에 둘 수 없어 감싸지 않는다.
const EM_STRONG_MIN  = 10;      // v1.10.1 D-373 — 12 → 10. 흔한 비밀번호·패턴 거절은 그대로

/** 흔한 비밀번호 기반 단어. 소문자 비교, 앞뒤의 숫자·기호는 벗겨 본다. */
const EM_COMMON_PW = [
    'password', 'passw0rd', 'p@ssword', 'p@ssw0rd', 'qwerty', 'qwertyuiop', 'qwertyui', 'asdfgh', 'asdfghjkl', 'zxcvbn', 'zxcvbnm',
    'iloveyou', 'letmein', 'welcome', 'admin', 'administrator', 'root', 'login', 'master', 'monkey', 'dragon', 'sunshine',
    'princess', 'football', 'baseball', 'superman', 'batman', 'trustno1', 'abc123', 'abcdef', 'abcdefgh', 'changeme',
    'default', 'guest', 'user', 'test', 'tester', 'secret', 'shadow', 'michael', 'jennifer', 'hello', 'freedom', 'whatever',
    'qazwsx', 'qazwsxedc', '1qaz2wsx', 'zaq12wsx', 'q1w2e3r4', 'qwer1234', 'asdf1234', 'zxcv1234', 'aaaa1111', 'a1b2c3d4',
    'google', 'naver', 'daum', 'kakao', 'samsung', 'korea', 'korea1234', 'seoul', 'hunter', 'ulogin', 'research', 'system',
    'computer', 'internet', 'service', 'manager', 'company', 'office', 'summer', 'winter', 'spring', 'autumn', 'newyork',
    'pass', 'pass1234', 'passwd', 'pa55word', 'pa55w0rd', 'love', 'lovely', 'starwars', 'cheese', 'killer',
    '123456', '12345678', '123456789', '1234567890', '0123456789', '111111', '000000', '123123', '654321', '112233',
];

/** 비밀번호가 흔한 목록(변형 포함)에 걸리는가. */
function em_pw_is_common(string $pw, ?string $username = null): bool
{
    $l     = mb_strtolower($pw, 'UTF-8');
    $strip = (string) preg_replace('/[0-9!@#$%^&*._\-]+$/', '', $l);
    $core  = (string) preg_replace('/^[^a-z]+|[^a-z]+$/', '', strtr($l, ['@' => 'a', '0' => 'o', '1' => 'i', '3' => 'e', '$' => 's', '5' => 's']));
    foreach ([$l, $strip, $core] as $cand) {
        if ($cand !== '' && in_array($cand, EM_COMMON_PW, true)) {
            return true;
        }
    }
    foreach (EM_COMMON_PW as $w) {
        // 흔한 단어에 몇 글자만 덧붙인 형태(password2024!! 등). 단어가 비밀번호 대부분을 차지할 때만
        if (strlen($w) >= 6 && !ctype_digit($w) && strpos($l, $w) !== false && mb_strlen($l, 'UTF-8') <= strlen($w) + 6) {
            return true;
        }
    }
    if ($username !== null && strlen($username) >= 4 && strpos($l, mb_strtolower($username, 'UTF-8')) !== false) {
        return true;
    }
    $chars = preg_split('//u', $l, -1, PREG_SPLIT_NO_EMPTY);
    if (is_array($chars) && count(array_unique($chars)) <= 3) {
        return true;          // 같은 글자 반복
    }
    // 같은 낱말 반복(Password!Password! · letmeinletmein): 기호·숫자를 벗긴 글자가 한 덩어리의 반복이면
    $alpha = (string) preg_replace('/[^a-z]/', '', $l);
    if (strlen($alpha) >= 6 && preg_match('/^(.{3,}?)\1+$/', $alpha) === 1) {   // REPEAT-RULE
        return true;
    }
    $alnum = (string) preg_replace('/[^a-z0-9]/', '', $l);
    if (strlen($alnum) >= 8 && preg_match('/^(.{3,}?)\1+$/', $alnum) === 1) {
        return true;
    }
    return em_pw_has_keyboard_run($l);   // KEYBOARD-RULE
}

/** 키보드·숫자·알파벳 순서의 6글자 이상 연속(정방향·역방향). 1q2w3e4r5t6y · qwerty · 123456 · abcdef */
function em_pw_has_keyboard_run(string $l): bool
{
    foreach (['1q2w3e4r5t6y7u8i9o0p', 'qazwsxedcrfvtgbyhnujmikolp', '1qaz2wsx3edc4rfv5tgb', 'qwertyuiop', 'asdfghjkl', 'zxcvbnm',
              '1234567890', 'abcdefghijklmnopqrstuvwxyz', 'poiuytrewq', 'lkjhgfdsa', 'mnbvcxz'] as $seq) {
        foreach ([$seq, strrev($seq)] as $q) {
            $len = strlen($q);
            for ($i = 0; $i + 6 <= $len; $i++) {
                if (strpos($l, substr($q, $i, 6)) !== false) {
                    return true;
                }
            }
        }
    }
    return false;
}

/**
 * 강화 규칙 위반 문구. 통과면 null. em_enforce_strong_password()(core/emergency.php)와 설치기가 같은 문구를 쓴다.
 * 길이 → 흔한 비밀번호 순서(종전 em_enforce_strong_password 와 같다).
 */
function pw_strong_problem(string $pw, ?string $username): ?string
{
    if (mb_strlen($pw, 'UTF-8') < EM_STRONG_MIN) {
        return '관리자 계정의 비밀번호는 ' . EM_STRONG_MIN . '자 이상이어야 합니다.';
    }
    if (em_pw_is_common($pw, $username)) {
        return '흔히 쓰는 비밀번호는 관리자 계정에 사용할 수 없습니다.';
    }
    return null;
}
