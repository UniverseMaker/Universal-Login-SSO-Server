<?php
declare(strict_types=1);

/**
 * core/crypto.php — 난수·해시·AES 암복호화·PKCE
 * 담당 계약: §4-9(exchange PKCE), 스펙 §6-1(시크릿 저장), §8-6(토큰 생성)
 *
 * rand()/mt_rand()/uniqid() 는 토큰 생성에 쓰지 않는다. 예측 가능하다.
 */


// 직접 실행 차단 — 리더 판정 D-34. bootstrap.php 를 거치지 않은 요청은 존재하지 않는 파일이다.
if (!defined('ULOGIN_BOOTSTRAPPED')) {
    // X-Powered-By 제거 — D-154(2). 이 경로는 bootstrap.php 를 거치지 않는다.
    header_remove('X-Powered-By');
    http_response_code(404);
    exit;
}
/** 암호학적 난수 토큰. 기본 32바이트 = 64자 hex. */
function gen_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

/** DB 에 저장·조회할 토큰 해시. 원문은 절대 저장하지 않는다. */
function token_hash(string $t): string
{
    return hash('sha256', $t);
}

/** 64자 hex 토큰 형식인가. 조회 전에 걸러 무의미한 쿼리를 줄인다. */
function is_token_format(?string $t, int $len = 64): bool
{
    return is_string($t) && strlen($t) === $len && ctype_xdigit($t);
}

function gen_uuid4(): string
{
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);   // version 4
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);   // variant 10
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

/** SECRET_ENC_KEY 를 원시 32바이트로. 형식이 틀리면 즉시 실패시킨다. */
function enc_key(): string
{
    static $key = null;
    if ($key === null) {
        $hex = defined('SECRET_ENC_KEY') ? (string) SECRET_ENC_KEY : '';
        if (strlen($hex) !== 64 || !ctype_xdigit($hex)) {
            // 잘못된 키로 암호화하면 복호화 불가능한 데이터가 쌓인다. 조용히 넘어가면 안 된다.
            throw new AppError('SERVER_ERROR', 500);
        }
        $key = (string) hex2bin($hex);
    }
    return $key;
}

/**
 * AES-256-CBC 암호화. IV 는 값마다 새로 만들어 암호문 앞에 붙인다.
 * 고정 IV 는 같은 평문이 같은 암호문이 되어 값 비교를 허용한다.
 * 반환 형식: base64(iv[16] . ciphertext)
 */
function enc(string $plain): string
{
    $iv = random_bytes(16);
    $ct = openssl_encrypt($plain, 'aes-256-cbc', enc_key(), OPENSSL_RAW_DATA, $iv);
    if ($ct === false) {
        throw new AppError('SERVER_ERROR', 500);
    }
    return base64_encode($iv . $ct);
}

/**
 * enc() 의 역. 복호화 실패 시 빈 문자열(예외를 밖으로 흘리지 않는다).
 *
 * **빈 문자열은 두 가지를 뜻한다** — `enc('')` 를 정상 복호화한 결과이거나, 복호화 실패다.
 * 그 둘을 가려야 하는 호출부는 `$ok` 를 받아라. 가리지 않으면 「값이 비어 있다」를
 * 「복호화가 실패했다」로 오진하는 로그가 남는다(v1.6.0 QA Q-1 에서 실제로 그랬다).
 * 선택 인자이므로 기존 호출부는 그대로 동작한다.
 *
 * @param bool|null $ok (out) 복호화 자체가 성공했는가. 값의 유무와는 별개다
 */
function dec(string $b64, ?bool &$ok = null): string
{
    $ok  = false;
    $raw = base64_decode($b64, true);
    if ($raw === false || strlen($raw) <= 16) {
        return '';
    }
    $pt = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', enc_key(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
    if ($pt === false) {
        return '';
    }
    $ok = true;
    return $pt;
}

/**
 * PKCE S256 검증. 계약 §4-9.
 *   challenge 가 없으면(PKCE 미사용 클라이언트) 통과.
 *   challenge 가 있는데 verifier 가 없으면 실패.
 */
function pkce_verify(?string $challenge, ?string $verifier): bool
{
    if ($challenge === null || $challenge === '') {
        return true;
    }
    if ($verifier === null || $verifier === '') {
        return false;
    }
    $calc = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    return hash_equals($challenge, $calc);
}

/** base64url 인코딩 (PKCE challenge 생성 등에 쓴다) */
function base64url(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}
