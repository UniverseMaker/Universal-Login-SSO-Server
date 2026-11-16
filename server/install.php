<?php
/**
 * ulogin 웹 설치기 — server/install.php
 * ---------------------------------------------------------------------
 * 브라우저로 이 파일 하나를 열면 설치가 끝난다.
 *   1) 환경 점검   2) DB 접속   3) 스키마 적용   4) 사이트·전송 보안
 *   5) 관리자 계정 6) 첫 서비스 7) 시크릿(선택) 8) 설치 실행 + 마무리 안내
 *
 * [설계 제약 — 반드시 지킬 것]
 *  - **core/bootstrap.php 를 require 하지 않는다.** 설치 전에는 config.php 가
 *    없으므로 bootstrap 이 즉시 죽는다. 이 파일은 자체 완결이어야 한다.
 *    그래서 uuid 생성·AES 암호화·아이디 정규화를 여기에 직접 구현했고,
 *    구현은 core/crypto.php · core/auth.php 와 **바이트 단위로 동일**하다.
 *      · ins_enc()               ≡ core/crypto.php enc()   (base64(iv[16] . ct), AES-256-CBC)
 *      · ins_uuid4()             ≡ core/crypto.php gen_uuid4()
 *      · ins_normalize_username()≡ core/auth.php normalize_username()  (소문자화 + trim)
 *      · ins_validate_password() ≡ core/auth.php validate_password()
 *    특히 username 을 대문자로 저장하면 로그인·탈퇴 경로가 정규화된 소문자로
 *    조회하기 때문에 그 계정은 영구히 탈퇴 불가가 된다.
 *  - **PHP 7.2 호환** (v1.1.0 / 리더 판정 D-57 · D-60).
 *    사용자의 실제 호스팅이 PHP 7.2.34 다. 7.2 에서 돌아야 하고 7.4·8.x 에서도
 *    그대로 돌아야 한다(하위 호환이 아니라 **양방향 호환**).
 *    다음 문법·함수를 이 파일에 넣지 마라 — `php -l` 이 8.x 에서 돌기 때문에
 *    넣어도 통과한다. 7.2 배포에서만 파싱 에러 또는 조용한 오동작이 된다.
 *
 *    [PHP 8.0+ 금지]  match 식 / 이름 있는 인자 / 생성자 프로퍼티 승격 /
 *                     nullsafe `?->` / enum / readonly / `#[Attr]` /
 *                     union·`mixed`·`static` 반환 타입 / throw 식 /
 *                     str_contains() · str_starts_with() · str_ends_with()
 *    [PHP 7.4 금지]   화살표 함수 `fn() => …` / 타입 프로퍼티 `private int $x` /
 *                     널 병합 대입 `??=` / 배열 스프레드 `[...$a]` /
 *                     숫자 리터럴 구분자 `1_000` / 공변·반변 타입 /
 *                     `array_merge()` 인자 없는 호출
 *    [PHP 7.3 금지]   **함수·메서드 호출의 trailing comma** `f($a, $b,)`
 *                       (※ 배열 리터럴 `[$a, $b,]` 은 예전부터 허용 — 혼동 금지)
 *                     **setcookie() 옵션 배열** `setcookie($n, $v, ['samesite'=>…])`
 *                       (7.2 는 3번째 인자를 정수 expires 로 캐스팅해 조용히
 *                        SameSite·HttpOnly·Secure·Path 를 전부 잃는다)
 *                     array_key_first() / array_key_last() / is_countable() /
 *                     JSON_THROW_ON_ERROR / JsonException / hrtime() /
 *                     유연한(들여쓰기 허용) heredoc·nowdoc 종료 표시 /
 *                     list()·[] 구조분해의 참조 할당 `[&$a] = $arr` /
 *                     `Foo::{$bar}` 동적 클래스 상수 참조 /
 *                     `preg_quote()` 의 `#` 자동 이스케이프 의존
 *  - 외부 의존 0개. 스타일은 이 파일 안에 인라인한다.
 *
 * [보안 — 04_security_audit.md M-12 수용 기준]
 *  ※ 아래 「→」 는 **함수 이름 또는 절 이름**으로만 가리킨다. 행 번호는 적지 않는다 —
 *    편집 한 번에 낡는다(D-92). 없는 함수를 근거로 지목하는 것은 더 나쁘다(D-147 · D-148):
 *    검토자가 그 이름을 grep 하면 0건을 보고, 그러면 판단 대상은 코드가 아니라 작성자가 된다.
 *  M-12-1  자기 잠금 — 검사와 생성이 서로 다른 자리에 있다. 잠금 전용 함수는 없다.
 *          · 검사(차단): `자기 잠금 (M-12-1 / M-12-2)` 절의 **최상위 인라인 블록**.
 *            함수가 아니라 파일 본문이다 — 단계 분기보다 먼저 무조건 실행돼야 하기 때문이다.
 *            `.install.lock` · `storage/.installed` · **`config.php`** 중 하나라도 있으면 403.
 *            (config.php 를 함께 보는 이유: 락 파일만 지우고 재실행하는 우회를 막는다.
 *             표제에 세 번째 파일을 빠뜨리면 운영자가 두 개만 지우고 열린다고 오해한다)
 *          · 생성: ins_finalize() 의 `5) 자기 잠금` 단계.
 *          다시 찾으려면: grep -n 'INS_LOCK\|INS_INSTALLED\|INS_CONFIG' install.php
 *  M-12-2  잠금 해제 파라미터 없음 (?force= 류 미구현)                 → 파일 전체에 없음
 *  M-12-3  config.php 0600 / storage 0700 / mail 가드 파일 생성        → ins_finalize()
 *  M-12-4  키는 random_bytes(32), 화면 표시 금지                       → ins_finalize()
 *  M-12-5  BASE_URL 필수 입력 (H-4 대응)                              → step 4
 *  M-12-6  FORCE_HTTPS / COOKIE_SECURE 동시 결정                       → step 4 (단일 라디오)
 *  M-12-7  관리자 비밀번호 정책 + password_hash(PASSWORD_DEFAULT)      → step 5
 *  M-12-8  POST + 1회성 CSRF, DB 정보 GET 금지                        → ins_csrf_check()
 *  M-12-9  .htaccess 실효성 자체 진단 + HTTPS/mail()/rewrite 점검      → step 1, step 8
 *  M-12-10 오류에 자격증명·절대경로·스택트레이스 미노출                 → ins_safe_msg()
 *  M-12-11 DB_PREFIX 정규식 검증 후 schema.sql 치환                    → step 2 / ins_apply_schema()
 *
 * [보안 — 2회차 감사 / 리더 판정 라운드 2]
 *  D-47 (C-3) 시크릿 등급 게이트를 **등록 시점에** 강제한다.
 *             7단계가 시크릿마다 min_account_level / min_billing_tier / min_role 을
 *             받고, 민감 키(ins_is_sensitive_key())는 기본 min_billing_tier='lite',
 *             게이트를 비우려면 명시적 확인 체크박스를 받는다.
 *             → ins_secret_fields() · ins_gate_* · ins_step7() · ins_handle_step7()
 *             완료 화면은 게이트 없는 시크릿의 키 이름을 나열한다 → ins_finalize()
 *             ※ build_payload() 는 게이트가 비면 전원 통과가 정상 동작이다(D-47).
 *               공개 설정값(api_base·model)을 내려줄 방법이 필요하기 때문에 그쪽은
 *               고치지 않는다. 그래서 **여기가 유일한 통제 지점**이다.
 *  D-51 (L-8) TRUST_PROXY 상수를 생성한다(기본 false). 실제 판정 로직은 core/bootstrap.php.
 *  D-67 (V11-L-6) TRUST_PROXY 와 TRUSTED_PROXIES 는 **짝**이다. true + 빈 목록은 설정
 *             오류이고 core/bootstrap.php 가 500 으로 멈춘다. 두 상수의 생성 주석
 *             (ins_config_spec())과 완료 화면 "다음 단계" 안내에 그 사실을 함께 적는다.
 *  D-52 (M-15) 1단계 최상단 선점 경고 + 완료 화면 1번 항목 = install.php 삭제.
 *  D-75/D-76 (V11-M-1 · V11-L-4 · M-3 잔존분) 쿠키 4상수의 **잘못된 값이 이제 실패한다.**
 *             COOKIE_SECURE=true + 평문 HTTP → 500 / COOKIE_PATH·COOKIE_DOMAIN 에 쿠키
 *             구분자 → 500 / COOKIE_SAMESITE 목록 밖 → Lax 강제 + 로그 / 'None' → Secure 강제.
 *             판정은 전부 core/request.php 의 send_cookie() 안, **버전 분기 앞**에 있다.
 *             그 사실과 오류 로그 검색어를 ins_config_spec() 의 생성 주석에 적는다 —
 *             **config.sample.php 의 같은 블록과 핵심 주장이 일치해야 한다.** 한쪽만
 *             고치면 설치본(config.php)과 샘플이 서로 다른 말을 한다.
 *             설치기는 여전히 FORCE_HTTPS 와 COOKIE_SECURE 를 단일 라디오로 함께
 *             결정하므로(M-12-6) 정상 설치 경로에서는 이 모순이 만들어지지 않는다.
 */

// ---------------------------------------------------------------------
// 0. 안전 기본값 — 오류 화면에 경로·쿼리가 새지 않게 한다 (M-12-10)
// ---------------------------------------------------------------------
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);
// 리더 판정 D-154(2). 공용 호스팅의 PHP 기본값 expose_php=On 은 전 응답에
// `X-Powered-By: PHP/7.2.34` 를 실어 익명에게 마이너 버전을 알려준다.
// `expose_php` 는 ini_set() 으로 못 바꾼다(PHP_INI_PERDIR) — 헤더를 지우는 쪽이 유일한 수단이다.
// 이 파일은 core/bootstrap.php 를 거치지 않으므로 여기서 따로 지운다.
if (PHP_SAPI !== 'cli') {
    header_remove('X-Powered-By');
}
date_default_timezone_set('UTC');
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

define('INS_DIR',       __DIR__);
define('INS_CONFIG',    INS_DIR . '/config.php');
define('INS_SAMPLE',    INS_DIR . '/config.sample.php');
define('INS_SCHEMA',    INS_DIR . '/schema.sql');
define('INS_STORAGE',   INS_DIR . '/storage');
define('INS_MAILDIR',   INS_DIR . '/storage/mail');
define('INS_LOCK',      INS_DIR . '/.install.lock');
define('INS_INSTALLED', INS_DIR . '/storage/.installed');

define('INS_STEP_MIN', 1);
define('INS_STEP_MAX', 8);

// v1.8.0 — SQLite 기본 저장소 (설계 E-2). 기본 DB 경로는 **설치 루트 기준 상대 경로**이고
// 설치 루트(버전 폴더 = 상시 서버의 문서 루트)의 **부모** 아래라 URL 공간 밖이다(D-281 · H17-4).
define('INS_SQLITE_DEFAULT', '../runtime/data/ulogin.sqlite');

// 설치 토큰 (설계 G-5 · D-294) — 수명 60분(파일 mtime 기준) · 틀린 시도 5회/15분.
define('INS_TOKEN_TTL',      3600);
define('INS_TOKEN_MAX_FAIL', 5);
define('INS_TOKEN_WINDOW',   900);

// ---------------------------------------------------------------------
// 저장소 계층 — v1.8.0. **DSN 을 만드는 3곳(core/db.php · migrate.php · 여기)이 같은 함수를 쓴다**
// (core/dbdriver.php, 설계 A25). migrations/lib.php 가 dbdriver.php 와 번역기 core/ddl.php 를
// 함께 올린다. 셋 다 앱 테이블 쿼리가 0줄이라 「bootstrap 을 require 하지 않는다」는 위 제약을
// 깨지 않는다(config.php 를 읽지 않는다). 종전에는 3단계에서야 lib.php 를 올렸다.
// ---------------------------------------------------------------------
if (!defined('ULOGIN_MIGRATE_CTX')) {
    define('ULOGIN_MIGRATE_CTX', 1);
}
require_once INS_DIR . '/migrations/lib.php';
// v1.10.1 보안 L-2 (D-375) — 첫 superadmin 도 관리자 강화 규칙(10자 이상 · 흔한 비밀번호 거절)을 받는다.
// 규칙 본문은 core/pwstrong.php 하나(core/emergency.php 와 공용). 의존성 0(DB·config 안 읽음)이라 위 제약을 깨지 않는다.
require_once INS_DIR . '/core/pwstrong.php';

$INS_STEP_NAMES = [
    1 => '환경 점검',
    2 => 'DB 접속',
    3 => '스키마 적용',
    4 => '사이트·보안',
    5 => '관리자 계정',
    6 => '첫 서비스',
    7 => '시크릿',
    8 => '설치 완료',
];

// =====================================================================
// 공통 헬퍼
// =====================================================================

function ins_h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** 예외·오류 메시지에서 절대경로를 지운다. 호스팅 디렉토리 구조를 노출하지 않는다. */
function ins_safe_msg($msg)
{
    $s = (string) $msg;
    $s = str_replace(['\\', '/'], ['/', '/'], $s);
    $s = str_replace(str_replace('\\', '/', INS_DIR), '{server}', $s);
    $s = preg_replace('#[A-Za-z]:/[^\s\'"]+#', '{path}', $s);
    $s = preg_replace('#/(?:home|var|usr|srv|opt|tmp|www)/[^\s\'"]+#', '{path}', $s);
    if (strlen($s) > 400) {
        $s = substr($s, 0, 400) . '…';
    }
    return $s;
}

/** core/crypto.php gen_uuid4() 와 동일 구현 */
function ins_uuid4()
{
    $d    = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);   // version 4
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);   // variant 10
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

/**
 * core/crypto.php enc() 와 **바이트 단위로 동일**한 암호화.
 * 반환 형식: base64(iv[16] . ciphertext), AES-256-CBC, 값마다 랜덤 IV.
 * 형식이 어긋나면 dec() 가 빈 문자열을 돌려주고 payload 에서 시크릿이 조용히 사라진다.
 */
function ins_enc($plain, $keyHex)
{
    if (strlen($keyHex) !== 64 || !ctype_xdigit($keyHex)) {
        throw new RuntimeException('enc key format');
    }
    $key = hex2bin($keyHex);
    $iv  = random_bytes(16);
    $ct  = openssl_encrypt((string) $plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($ct === false) {
        throw new RuntimeException('openssl_encrypt failed');
    }
    return base64_encode($iv . $ct);
}

/** core/auth.php normalize_username() 과 동일 — 소문자화 + trim */
function ins_normalize_username($u)
{
    return mb_strtolower(trim((string) $u), 'UTF-8');
}

/** core/auth.php validate_username_format() 과 동일 정규식 */
function ins_validate_username_format($u)
{
    return preg_match('/^[a-z0-9_]{4,20}$/', (string) $u) === 1;
}

/**
 * core/auth.php validate_password() 와 동일 규칙.
 * 8자 이상 + 영문/숫자/특수 중 2종 이상 + username 과 동일 문자열 금지.
 * @return string|null 오류 메시지. null 이면 통과.
 */
function ins_validate_password($pw, $username)
{
    $pw = (string) $pw;
    if (mb_strlen($pw, 'UTF-8') < 8) {
        return '비밀번호는 8자 이상이어야 합니다.';
    }
    if (mb_strlen($pw, 'UTF-8') > 200) {
        return '비밀번호는 200자 이내여야 합니다.';
    }
    $kinds  = 0;
    $kinds += preg_match('/[A-Za-z]/', $pw) === 1 ? 1 : 0;
    $kinds += preg_match('/[0-9]/', $pw) === 1 ? 1 : 0;
    $kinds += preg_match('/[^A-Za-z0-9]/', $pw) === 1 ? 1 : 0;
    if ($kinds < 2) {
        return '비밀번호는 영문·숫자·특수문자 중 2종 이상을 포함해야 합니다.';
    }
    if ($username !== null && $username !== ''
        && mb_strtolower($pw, 'UTF-8') === mb_strtolower((string) $username, 'UTF-8')) {
        return '아이디와 동일한 비밀번호는 사용할 수 없습니다.';
    }
    return null;
}

/** core/response.php now_utc() 와 동일 */
function ins_now()
{
    return gmdate('Y-m-d H:i:s');
}

function ins_is_https()
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (strtolower((string) (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : '')) === 'https') {
        return true;
    }
    return (int) (isset($_SERVER['SERVER_PORT']) ? $_SERVER['SERVER_PORT'] : 0) === 443;
}

/**
 * 이 install.php 의 URL 에서 BASE_URL 후보를 추론한다.
 * install.php 는 {BASE_URL}/server/install.php 이므로 두 단계를 벗겨낸다.
 *
 * !! 이 값은 Host 헤더에서 왔다. 공격자가 정할 수 있는 값이므로
 *    **기본값 제안에만** 쓰고 사용자가 확인·확정한 값만 저장한다 (H-4 / M-12-5).
 */
function ins_guess_base_url()
{
    $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
    $host = preg_replace('/[^A-Za-z0-9\.\-:\[\]]/', '', $host);
    if ($host === '') {
        return '';
    }
    $scheme = ins_is_https() ? 'https' : 'http';
    $self   = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '/server/install.php';
    $dir    = rtrim(str_replace('\\', '/', dirname($self)), '/');            // .../server
    $root   = rtrim(str_replace('\\', '/', dirname($dir)), '/');             // ...
    if ($root === '.' || $root === '/') {
        $root = '';
    }
    return $scheme . '://' . $host . $root;
}

/** 이 install.php 가 놓인 server/ 디렉토리의 공개 URL (자체 진단용) */
function ins_server_url($baseUrl)
{
    $self = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '/server/install.php';
    $dir  = rtrim(str_replace('\\', '/', dirname($self)), '/');
    $seg  = basename($dir);
    return rtrim((string) $baseUrl, '/') . '/' . ($seg === '' || $seg === '.' ? 'server' : $seg);
}

/**
 * 자체 HTTP 진단 (M-12-9). curl 도 allow_url_fopen 도 없으면 null 을 돌려주고
 * 화면에서 "직접 확인" 안내로 대체한다. 자기 자신을 부르므로 타임아웃을 짧게 둔다.
 * @return array|null ['status'=>int, 'len'=>int]
 */
function ins_http_probe($url)
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        // 자기 자신에 대한 진단이다. 자체 서명 인증서 호스팅에서도 결과를 얻어야 한다.
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 0) {
            return null;
        }
        return ['status' => $code, 'len' => strlen((string) $body)];
    }
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create([
            'http'  => ['timeout' => 5, 'ignore_errors' => true, 'follow_location' => 0],
            'ssl'   => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if (!isset($http_response_header) || !is_array($http_response_header)) {
            return null;
        }
        $code = 0;
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m) === 1) {
                $code = (int) $m[1];
            }
        }
        if ($code === 0) {
            return null;
        }
        return ['status' => $code, 'len' => strlen((string) $body)];
    }
    return null;
}

/** mod_rewrite 감지. 확인 불가면 null (정보성 — 없어도 설치는 진행한다. D-8) */
function ins_has_mod_rewrite()
{
    if (function_exists('apache_get_modules')) {
        return in_array('mod_rewrite', apache_get_modules(), true);
    }
    if (getenv('HTTP_MOD_REWRITE') === 'On') {
        return true;
    }
    return null;
}

/** 여러 줄 입력 → 배열 (빈 줄·중복 제거) */
function ins_lines($raw)
{
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', (string) $raw) as $line) {
        $s = trim($line);
        if ($s === '' || in_array($s, $out, true)) {
            continue;
        }
        $out[] = $s;
    }
    return $out;
}

/**
 * api/admin/services.php 의 admin_url_list() 와 동일 규칙으로 검증·정규화한다.
 * 여기서 어긋나면 관리자 콘솔에서 다시 저장할 때 값이 바뀌어 화이트리스트가 깨진다.
 * @param string $mode 'redirect' | 'origin'
 * @return array [정규화 목록, 오류 메시지 또는 null]
 */
function ins_url_list($items, $mode)
{
    $out = [];
    foreach ($items as $s) {
        if (mb_strlen($s, 'UTF-8') > 500) {
            return [[], '각 항목은 500자 이내여야 합니다.'];
        }
        if ($mode === 'origin' && $s === 'null') {
            // file:// 에서 온 요청(Origin: null) 허용 표기
            if (!in_array('null', $out, true)) {
                $out[] = 'null';
            }
            continue;
        }
        if (strpos($s, '?') !== false) {
            return [[], '쿼리스트링(?)을 포함할 수 없습니다: ' . $s];
        }
        if (strpos($s, '#') !== false) {
            return [[], '프래그먼트(#)를 포함할 수 없습니다: ' . $s];
        }
        $p = parse_url($s);
        if ($p === false || empty($p['scheme']) || empty($p['host'])) {
            return [[], '절대 URL 형식이어야 합니다 (예: https://app.example.com/callback.html): ' . $s];
        }
        $scheme = strtolower((string) $p['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return [[], 'http 또는 https만 허용됩니다: ' . $s];
        }
        if ($mode === 'origin') {
            $path = isset($p['path']) ? (string) $p['path'] : '';
            if ($path !== '' && $path !== '/') {
                return [[], '허용 출처에는 경로를 포함할 수 없습니다 (scheme://host[:port]): ' . $s];
            }
            $s = $scheme . '://' . strtolower((string) $p['host'])
               . (isset($p['port']) ? ':' . (int) $p['port'] : '');
        }
        if (!in_array($s, $out, true)) {
            $out[] = $s;
        }
    }
    if (count($out) > 50) {
        return [[], '항목은 50개 이내여야 합니다.'];
    }
    return [$out, null];
}

/**
 * schema.sql 을 문장 단위로 자른다.
 * 문자열 리터럴('..'), 식별자(`..`, ".."), 라인 주석(-- , #), 블록 주석 안의
 * 세미콜론을 문장 구분자로 오인하지 않는다. schema.sql 끝의 GC 주석 블록에는
 * DELETE 문이 주석으로 들어 있어 단순 explode(';') 는 그것까지 실행해 버린다.
 */
function ins_split_sql($sql)
{
    $stmts = [];
    $buf   = '';
    $len   = strlen($sql);
    $i     = 0;
    $inS   = false;   // '...'
    $inD   = false;   // "..."
    $inB   = false;   // `...`

    while ($i < $len) {
        $c = $sql[$i];

        if (!$inS && !$inD && !$inB) {
            // -- 주석 (MySQL 은 '--' 뒤에 공백/개행이 와야 주석이다)
            if ($c === '-' && $i + 1 < $len && $sql[$i + 1] === '-'
                && ($i + 2 >= $len || $sql[$i + 2] === ' ' || $sql[$i + 2] === "\t"
                    || $sql[$i + 2] === "\n" || $sql[$i + 2] === "\r")) {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            if ($c === '#') {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            if ($c === '/' && $i + 1 < $len && $sql[$i + 1] === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i   = ($end === false) ? $len : $end + 2;
                continue;
            }
            if ($c === ';') {
                $t = trim($buf);
                if ($t !== '') {
                    $stmts[] = $t;
                }
                $buf = '';
                $i++;
                continue;
            }
        }

        // 이스케이프 문자는 다음 한 글자를 통째로 넘긴다
        if (($inS || $inD) && $c === '\\' && $i + 1 < $len) {
            $buf .= $c . $sql[$i + 1];
            $i   += 2;
            continue;
        }
        if ($c === "'" && !$inD && !$inB) {
            $inS = !$inS;
        } elseif ($c === '"' && !$inS && !$inB) {
            $inD = !$inD;
        } elseif ($c === '`' && !$inS && !$inD) {
            $inB = !$inB;
        }
        $buf .= $c;
        $i++;
    }
    $t = trim($buf);
    if ($t !== '') {
        $stmts[] = $t;
    }
    return $stmts;
}

/** CREATE TABLE 문에서 테이블명을 뽑는다. 못 뽑으면 null. */
function ins_stmt_table($stmt)
{
    if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i', $stmt, $m) === 1) {
        return $m[1];
    }
    return null;
}

/** PDO 오류를 자격증명 없이 한국어로 바꾼다 (M-12-10) */
function ins_pdo_reason(PDOException $e)
{
    $errno = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
    $state = (string) $e->getCode();
    $map   = [
        1045 => '아이디 또는 비밀번호가 맞지 않습니다. (MySQL 1045 Access denied)',
        1044 => '이 계정에 해당 데이터베이스 접근 권한이 없습니다. (MySQL 1044)',
        1049 => '해당 이름의 데이터베이스가 없습니다. 호스팅 관리자 페이지에서 먼저 생성하세요. (MySQL 1049)',
        2002 => 'DB 서버에 접속할 수 없습니다. 호스트명을 확인하세요. (MySQL 2002)',
        2005 => 'DB 호스트명을 찾을 수 없습니다. (MySQL 2005)',
        1130 => '이 서버에서의 접속이 허용되지 않은 계정입니다. (MySQL 1130)',
        1142 => 'CREATE 권한이 없습니다. DB 사용자에게 테이블 생성 권한이 필요합니다. (MySQL 1142)',
    ];
    if (isset($map[$errno])) {
        return $map[$errno];
    }
    if ($state === '2002' || $errno === 0) {
        return 'DB 서버에 접속할 수 없습니다. 호스트명·포트를 확인하세요.';
    }
    return 'DB 오류가 발생했습니다. (SQLSTATE ' . preg_replace('/[^A-Za-z0-9]/', '', $state)
         . ' / errno ' . $errno . ') 상세 사유는 서버 오류 로그에 기록했습니다.';
}

/** SQLite 오류를 경로 없이 한국어로 바꾼다 (M-12-10). */
function ins_sqlite_reason(Throwable $e)
{
    if ($e instanceof UloginDbUnavailable) {
        return 'DB 파일이 손상됐거나 비어 있습니다(무결성 검사 실패). 다른 경로를 쓰거나 백업에서 복구하세요.';
    }
    $m = (string) $e->getMessage();
    if (stripos($m, 'unable to open') !== false || strpos($m, '[14]') !== false) {
        return 'DB 파일을 열거나 만들 수 없습니다. 폴더가 있고 웹 서버 계정이 쓸 수 있는지 확인하세요.';
    }
    if (stripos($m, 'readonly') !== false || stripos($m, 'read-only') !== false) {
        return 'DB 파일이 읽기 전용입니다. 파일과 폴더의 쓰기 권한을 확인하세요.';
    }
    if (stripos($m, 'locked') !== false || stripos($m, 'busy') !== false) {
        return 'DB 파일을 다른 프로세스가 쓰고 있습니다. 잠시 뒤 다시 시도하세요.';
    }
    return 'SQLite 오류가 발생했습니다. 상세 사유는 서버 오류 로그에 기록했습니다.';
}

/**
 * 설치용 PDO. 자격증명은 예외 메시지로 밖에 내보내지 않는다.
 * **DSN·PRAGMA 는 core/dbdriver.php 가 만든다** — core/db.php · migrate.php 와 같은 함수(A25).
 */
function ins_pdo($db)
{
    if (isset($db['driver']) && $db['driver'] === 'sqlite') {
        // 🔴 create=true 는 **설치기만** 쓴다 — DB 파일을 만들 수 있는 것은 설치기뿐이다(D-5-6).
        //    요청 경로(core/db.php)와 러너(migrate.php)는 create=false 로, 없는 파일을 만들지 않는다.
        //    경로는 2단계에서 검증해 세션에 둔 절대 경로다. quick_check 는 기존 파일을 고른 경우를 덮는다.
        return ulogin_pdo_open_sqlite((string) $db['sqlite_abs'], array('create' => true, 'quick_check' => true));
    }
    // 포트 — v1.7.0. **설치기는 화면에서 받은 값을 쓴다**(환경변수나 상수를 보지 않는다).
    //   운영자가 2단계에서 입력한 포트로 실제 접속이 되는지 확인하는 것이 이 화면의 일이고,
    //   그 값이 그대로 config.php 의 DB_PORT 가 된다. 여기서 다른 출처를 섞으면
    //   「테스트는 통과했는데 설치 후에는 다른 포트로 붙는」 상태가 만들어진다.
    //   비어 있으면 포트 요소를 넣지 않는다 = v1.6.0 과 같은 동작(드라이버 기본값 3306).
    //   DSN 문자열은 v1.7.0 과 글자 단위로 같다: mysql:host=H[;port=N];dbname=D;charset=utf8mb4
    $port = isset($db['port']) ? trim((string) $db['port']) : '';
    return ulogin_pdo_open_mysql($db['host'], ($port === '' ? '' : ';port=' . (int) $port),
        $db['name'], 'utf8mb4', $db['user'], $db['pass']);
}

// =====================================================================
// config.php 생성
// =====================================================================

/**
 * config.php 로 정의할 상수 전체를 **하나의 순서 있는 명세**로 둔다.
 * config.sample.php 의 define() 집합과 정확히 일치해야 한다. 하나라도 빠지면
 * API 가 `Undefined constant` 로 죽는다.
 *   [상수명, PHP 리터럴 문자열, 섹션 제목(없으면 null), 주석(없으면 null)]
 */
function ins_config_spec($v)
{
    $q = function ($s) { return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $s) . "'"; };
    $b = function ($x) { return $x ? 'true' : 'false'; };

    // DB_PORT 는 **입력했을 때만** 넣는다 (v1.7.0 / D-241).
    //   비워 두면 상수 자체가 생기지 않고, core/dbport.php 의 defined() 가드가
    //   DSN 에 포트 요소를 넣지 않는다 = v1.0.0~v1.6.0 과 글자 단위로 같은 동작이다.
    //   값을 지어내 채우면 공용 호스팅 배포의 DSN 이 조용히 바뀐다 — 넣지 않는다.
    $port = isset($v['db']['port']) ? trim((string) $v['db']['port']) : '';
    $portRow = $port === ''
        ? []
        : [['DB_PORT', (string) (int) $port, null,
            "DB_PORT — DB 서버의 TCP 포트. 설치 2단계에서 입력한 값이다.\n"
          . "이 줄을 지우면 DSN 에 포트 요소가 사라져 **드라이버 기본값 3306** 으로 간다.\n"
          . "한 서버에 다른 MySQL 인스턴스가 함께 돌고 있다면 그 3306 이 **남의 DB** 일 수 있다.\n"
          . "값이 1~65535 정수가 아니면 요청이 500 이 된다 — 조용히 다른 포트로 넘어가지 않는다.\n"
          . "환경변수 ULOGIN_DB_PORT 가 설정돼 있으면 그쪽이 이긴다(서버 지킴이가 넣는 값).\n"
          . "판정식은 server/core/dbport.php 한 곳에만 있다.\n"
          . ">> 확인: GET /api/health (admin) 의 checks.db_port_pinned"]];

    // v1.8.0 (설계 E-1·E-2) — 저장소 2상수. **새 설치는 언제나 명시적으로 쓴다.**
    //   (미정의 = 'mysql' 은 v1.7.0 이하 config.php 를 위한 하위호환이지 새 설치의 기본이 아니다)
    //   config.sample.php · core/dbdriver.php 의 판정과 한 벌이다.
    $drv = (isset($v['db']['driver']) && $v['db']['driver'] === 'sqlite') ? 'sqlite' : 'mysql';
    $sqlitePath = ($drv === 'sqlite' && isset($v['db']['sqlite_path'])) ? (string) $v['db']['sqlite_path'] : '';

    return array_merge([
        ['DB_DRIVER',  $q($drv), 'DB',
            "DB_DRIVER — 저장소. 'sqlite' 또는 'mysql' 두 값뿐이다(그 밖은 500 — 조용히 넘어가지 않는다).\n"
          . "이 줄을 지우면 'mysql' 로 동작한다(v1.7.0 이하 config.php 와 같은 해석).\n"
          . "'sqlite' 이면 DB_HOST·DB_PORT·DB_NAME·DB_USER·DB_PASS·DB_CHARSET 는 읽지 않는다.\n"
          . "판정은 server/core/dbdriver.php 한 곳에만 있다."],
        ['DB_SQLITE_PATH', $q($sqlitePath), null,
            "DB_SQLITE_PATH — SQLite DB 파일. 상대 경로는 설치 루트(server/ 의 부모) 기준이다.\n"
          . "파일이 없거나 0바이트거나 무결성 검사에 실패하면 503 으로 멈춘다 — **빈 DB 를 만들지 않는다.**\n"
          . "서버가 켜진 채로 이 파일만 복사하지 마라(-wal 에 반영 안 된 기록이 있다).\n"
          . "SECRET_ENC_KEY(아래) 없이 DB 만 옮기면 서비스 시크릿을 복호화할 수 없다."],
        ['DB_HOST',    $q($v['db']['host']),   null, null],
    ], $portRow, [
        ['DB_NAME',    $q($v['db']['name']),   null, null],
        ['DB_USER',    $q($v['db']['user']),   null, null],
        ['DB_PASS',    $q($v['db']['pass']),   null, null],
        ['DB_CHARSET', $q('utf8mb4'),          null, null],
        ['DB_PREFIX',  $q($v['db']['prefix']), null, null],

        ['SECRET_ENC_KEY', $q($v['keys']['secret_enc_key']), '키',
            "SECRET_ENC_KEY : service_secrets 값 AES-256-CBC 암복호화 키. 64자 hex(32바이트)\n"
          . "                 !! 이 값을 잃으면 저장된 시크릿을 복호화할 수 없다 !!\n"
          . "APP_KEY        : 서명·CSRF 등 범용 앱 키. 64자 hex"],
        ['APP_KEY',        $q($v['keys']['app_key']), null, null],

        ['FORCE_HTTPS', $b($v['force_https']), '전송 보안',
            "FORCE_HTTPS=true 이면 평문 HTTP 요청을 301 로 https 로 돌린다.\n"
          . "HTTPS 없이 운영하면 access_token 과 AI API 키가 평문으로 전송된다. (리더 판정 D-6)"],
        // D-67(4): TRUST_PROXY 와 TRUSTED_PROXIES 는 **짝**이다. 한쪽만 읽고 설정하면 틀리므로
        // 두 주석에 같은 사실을 적는다. config.sample.php 의 같은 두 블록과 내용이 일치해야 한다.
        // 값 자체는 바꾸지 않는다 — 기본은 false / 빈 배열이다(D-51 fail-closed).
        ['TRUST_PROXY', 'false', null,
            "TRUST_PROXY — X-Forwarded-* 헤더를 프로토콜 판정에 쓸 것인가. 기본 false.\n"
          . "(security L-8 · V11-L-6 / D-51 · D-67)\n"
          . "\n"
          . "**이 상수와 아래 TRUSTED_PROXIES 는 짝이다. 한쪽만 읽고 설정하면 틀린다.**\n"
          . "\n"
          . "리버스 프록시·CDN(Cloudflare 등) 뒤에 있을 때만 true 로 한다.\n"
          . "서버가 인터넷에 직접 노출되어 있으면 false 그대로 둔다.\n"
          . "\n"
          . "!! true 로 하려면 TRUSTED_PROXIES 를 반드시 함께 채워야 한다 !!\n"
          . "TRUST_PROXY=true 인데 TRUSTED_PROXIES 가 비어 있으면 **설정 오류로 취급해 요청이\n"
          . "500 이 된다.** core/bootstrap.php 가 FORCE_HTTPS 리다이렉트 직전에 이 조합을\n"
          . "감지하면 리다이렉트하지 않고 500 으로 멈추고 error_log 에 사유를 남긴다 — 홉을\n"
          . "검증할 근거가 없는 상태에서 헤더를 신뢰하면 아래의 FORCE_HTTPS 우회가 되살아나고,\n"
          . "반대로 헤더를 조용히 무시하면 프록시 뒤에서 무한 리다이렉트가 나 원인이 가려진다.\n"
          . "그래서 어느 쪽으로도 조용히 넘어가지 않는다(fail-closed). 500 이 뜨면\n"
          . "TRUSTED_PROXIES 를 채우거나 TRUST_PROXY 를 false 로 되돌려라.\n"
          . "정확한 발동 지점은 FORCE_HTTPS 리다이렉트 **직전**이다 — 즉 FORCE_HTTPS=true(기본)\n"
          . "이고 서버가 그 요청을 https 로 인식하지 못했을 때 500 이 된다. 실제 https 로 들어온\n"
          . "요청(\$_SERVER['HTTPS'] 또는 포트 443)은 이 분기에 오지 않는다.\n"
          . ">> 오류 로그 검색어: TRUST_PROXY is on but TRUSTED_PROXIES is empty\n"
          . "\n"
          . "[v1.1.0 추가] 이 두 상수는 **쿠키 발급에도 영향을 준다.** 아래 COOKIE_SECURE 의\n"
          . "HTTPS 판정이 같은 request_is_https() 를 재사용하기 때문이다(D-75). TLS 를 종료하는\n"
          . "프록시 뒤인데 두 상수를 비워 두면 서버는 그 요청을 평문으로 보고, COOKIE_SECURE=true\n"
          . "와 겹쳐 쿠키를 발급하는 요청이 500 이 된다. 아래 COOKIE_SECURE 항목을 함께 읽어라.\n"
          . "\n"
          . "켜면 클라이언트가 X-Forwarded-Proto 로 프로토콜을 위조할 수 있으므로, 프록시가\n"
          . "그 헤더를 반드시 덮어쓰도록 설정되어 있어야 한다. 그렇지 않으면 헤더 한 줄로\n"
          . "FORCE_HTTPS 가 우회되어 평문 요청이 그대로 처리된다.\n"
          . "false 이면 \$_SERVER['HTTPS'] 와 포트만 보고 판정한다."],
        ['TRUSTED_PROXIES', '[]', null,
            "TRUSTED_PROXIES — 신뢰할 프록시의 IP 목록. 예: ['203.0.113.7', '198.51.100.10']\n"
          . "**위 TRUST_PROXY 와 짝이다.** 조합별 동작:\n"
          . "  · TRUST_PROXY=false                  → 이 목록은 쓰이지 않는다. 빈 배열로 둔다\n"
          . "  · TRUST_PROXY=true  + 목록이 비어 있음 → **설정 오류. 요청이 500 이 된다** (위 설명)\n"
          . "  · TRUST_PROXY=true  + 목록에 IP 있음  → REMOTE_ADDR 가 그 목록에 있는 요청에서만\n"
          . "    X-Forwarded-Proto / X-Forwarded-For 를 신뢰한다. core/transport.php 의\n"
          . "    trust_proxy_headers() 와 core/request.php 의 client_ip() 가 같은 기준을 쓴다\n"
          . "비어 있는 채로 TRUST_PROXY=false 이면 REMOTE_ADDR 만 신뢰한다(기본값·안전).\n"
          . "X-Forwarded-For 를 무조건 믿으면 헤더 위조로 레이트리밋이 통째로 우회된다.\n"
          . "프록시·CDN 의 실제 IP 는 호스팅·CDN 사업자에게 확인해 넣는다."],
        // v1.7.0 2차 (QA Q17-14 / D-272): 종전에는 config.sample.php 에만 있고 여기에는 없었다.
        // 기본값 1 이라 동작은 같지만, 웹 설치기를 쓴 운영자는 D-272 가 기대는 손잡이를 볼 수 없었다.
        // 주석은 config.sample.php 의 같은 블록과 핵심 주장이 일치해야 한다.
        ['TRUSTED_PROXY_HOPS', '1', null,
            "TRUSTED_PROXY_HOPS — 실제 클라이언트 IP 가 X-Forwarded-For 의 **오른쪽에서 몇 번째**인가.\n"
          . "기본 1 (= 맨 오른쪽). 리더 판정 D-272. TRUST_PROXY=true 이고 REMOTE_ADDR 가\n"
          . "TRUSTED_PROXIES 에 있을 때만 쓰인다.\n"
          . "Cloudflare / cloudflared 는 접속 IP 를 XFF **맨 끝에 덧붙이므로 1** 이다.\n"
          . "!! 정하기 전에 실제 체인 모양을 재라. 추측하지 마라 !!\n"
          . "**2 로 잘못 두면 1 보다 위험하다** — 그 자리를 클라이언트가 쓸 수 있다(QA 실측).\n"
          . "그 자리에 유효 IP 가 없으면 REMOTE_ADDR 로 떨어지고 서버 로그에 남는다.\n"
          . ">> 오류 로그 검색어: has no usable entry at position"],

        // D-76: 아래 4개 상수(COOKIE_SECURE / COOKIE_SAMESITE / COOKIE_PATH / COOKIE_DOMAIN)의
        // 주석은 **config.sample.php 의 같은 블록과 핵심 주장이 일치해야 한다.**
        // 한쪽만 고치면 설치본(config.php)과 샘플이 서로 다른 말을 한다.
        // 서술은 core/request.php 의 실제 검사(send_cookie 의 분기 앞 층)를 옮긴 것이다.
        ['COOKIE_NAME',      $q('ULOGIN_SID'),  '쿠키',
            "로그인 서버 자체 UI 전용. 로컬 앱은 Bearer 토큰을 쓴다.\n"
          . "\n"
          . "[v1.1.0 부터 이 절의 잘못된 값은 조용히 넘어가지 않는다] D-65 · D-66 · D-75 /\n"
          . "security V11-M-1 · V11-L-4 · M-3. core/request.php 의 send_cookie() 가\n"
          . "**PHP 버전 분기 앞에서** 값을 검사한다. 그래서 PHP 7.2 배포와 7.4·8.x 배포가\n"
          . "같은 지점에서 같은 예외로 실패한다 — 7.2 에서만 조용히 다르게 동작하던 경로를\n"
          . "없앴다(두 분기 불변식). 잘못된 값은 (a) 500 SERVER_ERROR 로 멈추거나\n"
          . "(b) 안전한 값으로 강제되며, 어느 쪽이든 서버 오류 로그(error_log)에\n"
          . "[ulogin] 로 시작하는 사유가 남는다. 500 응답 본문에는 이유가 없다\n"
          . "(내부 정보를 노출하지 않는다) — 호스팅의 PHP 오류 로그를 열고 항목별\n"
          . ">> 검색어로 찾아라."],
        ['COOKIE_CSRF_NAME', $q('ULOGIN_CSRF'), null, null],
        ['COOKIE_SECURE',    $b($v['force_https']), null,
            "COOKIE_SECURE — 쿠키에 Secure 속성을 붙일 것인가. (security M-3 / D-75)\n"
          . "\n"
          . "**FORCE_HTTPS 와 항상 같은 값이어야 한다.** 설치기는 두 값을 하나의 선택으로\n"
          . "묶어 함께 기록했다 — 손으로 편집할 때도 반드시 함께 바꾼다. 어긋난 두 방향이\n"
          . "각각 다르게 실패한다:\n"
          . "\n"
          . "· FORCE_HTTPS=true + COOKIE_SECURE=false\n"
          . "  301 리다이렉트 직전의 평문 요청에 세션 쿠키가 실려 그대로 노출된다.\n"
          . "  이 조합은 코드가 막지 않는다 — 조용히 위험하다. 만들지 마라.\n"
          . "\n"
          . "· FORCE_HTTPS=false + COOKIE_SECURE=true → **쿠키를 발급하는 요청이 500 이 된다**\n"
          . "  평문 HTTP 요청에서 Secure 쿠키를 내보내려 하면 send_cookie() 가 거기서 멈춘다.\n"
          . "  왜 멈추는가: **브라우저는 평문 연결에서 Secure 쿠키를 저장하지 않는다.**\n"
          . "  서버는 200 과 정상 Set-Cookie 를 내보내고 브라우저가 그것을 버리므로,\n"
          . "  \"로그인 API 는 200 인데 다음 요청에 세션이 없다\" 가 된다 — 사용자에게는\n"
          . "  \"로그인을 눌렀는데 다시 로그인 화면\"으로 보이고 서버에는 아무 오류도 남지\n"
          . "  않는다. 그 조용한 실패를 남겨 두는 대신 명시적으로 멈춘다(fail-closed.\n"
          . "  D-53 BASE_URL 미설정 · D-67 TRUST_PROXY 짝 누락과 같은 계열).\n"
          . "  Secure 를 자동으로 떼지 않는 이유: 그러면 세션 쿠키가 평문으로 흐른다 —\n"
          . "  이 시스템이 막으려는 것 자체다. 설정을 조용히 약화시키지 않는다.\n"
          . "\n"
          . "HTTP 로 운영해야 한다면 FORCE_HTTPS=false 와 COOKIE_SECURE=false 를 **함께**\n"
          . "내려라. 한쪽만 내리면 위 두 경우 중 하나가 된다.\n"
          . "\n"
          . "판정은 **쿠키 발급 시점에만** 한다. 그래서 500 이 나는 범위가 좁다 — 쿠키를\n"
          . "실제로 내보내는 요청(로그인·로그아웃·CSRF 발급)만 500 이고, Authorization: Bearer\n"
          . "로 호출하는 API 는 정상 200 이다(실측 확인). 증상이 비대칭으로 보인다:\n"
          . "**브라우저에서는 로그인이 안 되는데 Bearer 를 쓰는 로컬 AI 앱은 계속 동작한다.**\n"
          . "이 조합을 보면 이 항목을 의심하라.\n"
          . "\n"
          . "HTTPS 여부는 core/bootstrap.php 의 request_is_https() 로 판정한다 — 즉 TLS 를\n"
          . "종료하는 프록시 뒤라면 위 TRUST_PROXY·TRUSTED_PROXIES 를 올바로 채워야 이 판정이\n"
          . "https 로 인식된다. 프록시 뒤인데 두 상수를 비워 두고 COOKIE_SECURE=true 로 두면\n"
          . "쿠키 경로가 500 이 된다.\n"
          . "\n"
          . "!! 판정 조건을 정확히 알아 둘 것 — 이 상수만 보는 것이 아니다 !!\n"
          . "코드가 보는 것은 COOKIE_SECURE 상수가 아니라 **지금 내보내려는 쿠키의 실효\n"
          . "secure 값**이다. 정상 경로에서는 둘이 같지만, 아래 두 경우에는 이 상수가\n"
          . "false(또는 없음)여도 실효값이 true 로 강제되어 **똑같이 500 이 난다**:\n"
          . "  · COOKIE_SAMESITE = 'None'  → Secure 자동 강제 (브라우저 요구사항. 아래 항목)\n"
          . "  · COOKIE_SECURE 자체가 정의돼 있지 않음(구버전 config.php) → 안전한 쪽인\n"
          . "    true 로 채운다\n"
          . "두 경우 모두 \"브라우저가 버리는 쿠키\" 라는 점이 같기 때문에 함께 잡는다.\n"
          . "\n"
          . ">> 오류 로그 검색어: COOKIE_SECURE is on but the request is not HTTPS\n"
          . "   **주의: 이 로그 문구는 COOKIE_SECURE 만 언급한다.** 로그를 보고 config.php 를\n"
          . "   열었는데 COOKIE_SECURE 가 이미 false 라면 당황하지 말고 위 두 경우를 보라 —\n"
          . "   특히 COOKIE_SAMESITE 가 'None' 인지 먼저 확인하라. 문구는 가장 흔한 원인을\n"
          . "   가리키지만 실제 트리거는 실효 secure 값이다."],
        ['COOKIE_SAMESITE',  $q('Lax'), null,
            "COOKIE_SAMESITE — Lax · Strict · None **세 값만 유효하다.** (security V11-L-4 / D-66)\n"
          . "대소문자까지 정확히 일치해야 한다 — lax LAX 는 유효하지 않다.\n"
          . "\n"
          . "· 목록 밖의 값(오타 포함)은 **Lax 로 강제**되고 서버 오류 로그에 경고가 남는다.\n"
          . "  요청은 500 이 되지 않고 정상 처리된다 — 다만 당신이 적은 값은 쓰이지 않는다.\n"
          . "  'Lax; Domain=evil.example' 처럼 구분자를 넣어 속성을 덧붙이려 해도 같은\n"
          . "  화이트리스트에서 Lax 로 잘려 나간다(쿠키 속성 주입 차단).\n"
          . "  >> 오류 로그 검색어: invalid COOKIE_SAMESITE\n"
          . "\n"
          . "· 'None' 으로 하면 **Secure 가 자동으로 강제된다.** 브라우저 요구사항이다 —\n"
          . "  SameSite=None 인데 Secure 가 없는 쿠키는 Chrome·Firefox·Safari 가 통째로\n"
          . "  버리므로, 위 COOKIE_SECURE 항목과 같은 \"200 인데 세션 없음\" 이 된다.\n"
          . "  >> 오류 로그 검색어: COOKIE_SAMESITE=None requires Secure\n"
          . "\n"
          . "  !! 그 결과로 이어지는 것: 평문 HTTP 배포에서 'None' 을 쓰면 COOKIE_SECURE=false\n"
          . "  라도 Secure 가 강제되고, 곧바로 위 D-75 검사에 걸려 **쿠키 발급이 500 이 된다.**\n"
          . "  **'None' 은 HTTPS 전용 값이다 — 평문 HTTP 배포에서는 쓸 수 없다.**\n"
          . "  이때 로그에 남는 것은 SameSite 관련 문구가 아니라 위 COOKIE_SECURE 항목의\n"
          . "  COOKIE_SECURE is on but the request is not HTTPS 다(트리거가 실효 secure\n"
          . "  값이기 때문). COOKIE_SECURE=false 인데 그 로그가 보이면 이 값을 확인하라.\n"
          . "  'None' 이 필요한 경우는 로그인 화면을 다른 사이트의 iframe 에 띄우는 구성뿐이다\n"
          . "  — 그럴 일이 없으면 Lax 를 그대로 둔다."],
        ['COOKIE_DOMAIN',    $q(''),    null,
            "COOKIE_DOMAIN / COOKIE_PATH — 쿠키 구분자를 넣으면 **요청이 500 이 된다.**\n"
          . "(security V11-M-1 / D-65)\n"
          . "금지 문자: 콤마 , · 세미콜론 ; · 공백 · 탭 · CR · LF · 수직탭(\\013) · 폼피드(\\014)\n"
          . "\n"
          . "PHP 본체는 이 옵션들에 위 문자가 있으면 쿠키를 아예 보내지 않는다\n"
          . "(8.0+ ValueError / 7.3~7.4 경고 + false). **PHP 7.2 에는 그 층이 없어서**\n"
          . "COOKIE_PATH = '/; HttpOnly' 같은 값이 7.2 배포에서만 조용히 통과하고 HttpOnly\n"
          . "가 실제 속성으로 해석됐다(쿠키 속성 주입. 실측으로 확인된 결함이다).\n"
          . "그래서 core/request.php 가 **버전 분기 앞에서** 같은 검사를 한다 — 7.2 와 7.4·8.x\n"
          . "가 같은 지점에서 같은 예외(SERVER_ERROR 500)로 실패하고, 그 요청에는 Set-Cookie\n"
          . "가 한 줄도 나가지 않는다. 빈 문자열은 검사 대상이 아니다(속성을 생략한다).\n"
          . "\n"
          . "정상 값: COOKIE_PATH 는 / . 설치 경로가 하위 디렉터리여도 / 로 둔다.\n"
          . "COOKIE_DOMAIN 은 **빈 문자열**이 권장값이다 — 쿠키를 현재 호스트에만 준다.\n"
          . "서브도메인 공유가 필요할 때만 .example.com 처럼 넣고, 점·하이픈·영숫자만\n"
          . "쓴다. https:// · 포트 · 경로 · 여러 도메인 나열은 넣지 마라(위 금지 문자에\n"
          . "걸려 500 이 된다).\n"
          . "\n"
          . ">> 오류 로그 검색어: cookie option rejected\n"
          . "   (어느 키가 문제인지 함께 남는다 — invalid \"path\" / invalid \"domain\")"],
        ['COOKIE_PATH',      $q('/'),   null, null],

        ['MAIL_DRIVER', $q($v['mail_driver']), '메일',
            "MAIL_DRIVER = 'log'  → 발송하지 않고 server/storage/mail/ 에 파일로 저장 (v1.0.0 기본값)\n"
          . "              'mail' → PHP mail() 사용\n"
          . 'DB settings 테이블의 mail_driver 값이 있으면 그쪽이 우선한다.'],
        ['MAIL_FROM',      $q($v['mail_from']),      null, null],
        ['MAIL_FROM_NAME', $q($v['mail_from_name']), null, null],

        ['BASE_URL', $q($v['base_url']), 'BASE_URL',
            "비밀번호 재설정·이메일 인증 링크를 만들 때 쓰는 공개 주소 (끝에 / 없이)\n"
          . "!! 절대 비워두지 마라 !! (security H-4 / D-33 · D-44)\n"
          . "BASE_URL 이 비어 있으면 폴백하지 않고 메일 발송 자체를 중단한다(fail-closed).\n"
          . "반드시 설치 루트 절대 URL 을 넣어라.\n"
          . "예전 구현은 Host 헤더로 폴백했는데, Host 는 요청자가 정하는 값이라\n"
          . "공격자가 Host 를 바꿔 보낸 재설정 요청의 링크가 공격자 호스트로 만들어져\n"
          . "토큰이 유출됐다. 그래서 폴백을 제거했다.\n"
          . '이 값이 비면 재설정 메일이 조용히 발송되지 않는다 — 재설정이 안 되면 여기를 먼저 보라.'],

        ['SESSION_TTL',          '3600',    '정책 기본값',
            "DB settings 테이블 값이 있으면 그쪽이 우선한다. 여기 값은 settings 가\n비어 있을 때의 폴백이다."],
        ['REFRESH_TTL',          '1209600', null, 'refresh_token 수명(초) = 14일'],
        ['REMEMBER_TTL',         '2592000', null, 'remember=true 쿠키 수명(초) = 30일'],
        ['AUTH_CODE_TTL',        '60',      null, 'SSO code 수명(초)'],
        ['PW_RESET_TTL',         '1800',    null, '비밀번호 재설정 토큰 수명(초) = 30분'],
        ['EMAIL_VERIFY_TTL',     '86400',   null, '이메일 인증 토큰 수명(초) = 24시간'],
        ['LOCKOUT_THRESHOLD',    '5',       null, '연속 실패 몇 회에 잠글 것인가'],
        ['LOCKOUT_MINUTES',      '15',      null, '잠금 지속 시간(분)'],
        ['PASSWORD_MIN_LENGTH',  '8',       null, null],
        ['SIGNUP_BONUS_POINTS',  (string) (int) $v['signup_bonus'], null, null],
        ['ALLOW_REGISTER',       $b($v['allow_register']),        null, null],
        ['REGISTER_MODE',        $v['allow_register'] ? "'open'" : "'closed'", null,
            "가입 방식 closed|approval|open (v1.10.1). 화면 폴백 표시용 — 판정은 DB settings.register_mode 가 한다."],
        ['REQUIRE_EMAIL_VERIFY', $b($v['require_email_verify']),  null, null],
        ['MAINTENANCE_MODE',     'false', null, null],
        ['SITE_NAME',            $q($v['site_name']), null, null],
        ['SECRET_ACCESS_LOG',    'true', null,
            "시크릿(payload) 전달 감사 기록. 「누가 · 언제 · 어떤 키를」 받아 갔는지를\n"
          . "app_ulogin_secret_access_logs 에 남긴다. (세션 × 경로 × 1시간) 버킷이라\n"
          . "요청 수만큼 늘지 않는다. DB settings 의 secret_access_log 가 우선하고,\n"
          . '관리자 콘솔의 설정 화면에서 끌 수 있다.'],
        ['DEBUG',                'false', null, '운영에서는 반드시 false (security L-2)'],

        ['T_USERS',         "DB_PREFIX . 'users'",               '테이블 상수',
            "코드에서 테이블명 리터럴을 쓰지 않는다. prefix 가 바뀌면 전수 수정이 필요해진다."],
        ['T_SERVICES',      "DB_PREFIX . 'services'",            null, null],
        ['T_USER_SERVICES', "DB_PREFIX . 'user_services'",       null, null],
        ['T_SECRETS',       "DB_PREFIX . 'service_secrets'",     null, null],
        ['T_SESSIONS',      "DB_PREFIX . 'sessions'",            null, null],
        ['T_AUTH_CODES',    "DB_PREFIX . 'auth_codes'",          null, null],
        ['T_PW_RESETS',     "DB_PREFIX . 'password_resets'",     null, null],
        ['T_EMAIL_VERIFY',  "DB_PREFIX . 'email_verifications'", null, null],
        ['T_LOGIN_LOGS',    "DB_PREFIX . 'login_logs'",          null, null],
        ['T_POINT_LOGS',    "DB_PREFIX . 'point_logs'",          null, null],
        ['T_ADMIN_LOGS',    "DB_PREFIX . 'admin_logs'",          null, null],
        ['T_RATE_LIMITS',   "DB_PREFIX . 'rate_limits'",         null, null],
        ['T_SETTINGS',      "DB_PREFIX . 'settings'",            null, null],
        ['T_TERMS',         "DB_PREFIX . 'terms'",               null, null],
        ['T_CONSENTS',      "DB_PREFIX . 'user_consents'",       null, null],
        // v1.4.0 추가. **기존 설치본의 config.php 에는 이 두 줄이 없다** —
        // core/bootstrap.php 가 defined() 가드로 같은 값을 채워 넣는다.
        // 새 테이블 상수를 추가할 때는 여기와 그 가드 블록을 **함께** 고쳐라.
        ['T_MIGRATIONS',    "DB_PREFIX . 'schema_migrations'",   null, null],
        ['T_SECRET_ACCESS', "DB_PREFIX . 'secret_access_logs'",  null, null],
        // v1.8.4 추가(시스템 계정 연결, 마이그레이션 0005). 가드 블록과 함께 고쳤다.
        ['T_EXT_ACCOUNTS',  "DB_PREFIX . 'ext_accounts'",        null, null],
        ['T_LINK_JOBS',     "DB_PREFIX . 'link_jobs'",           null, null],
        ['T_LINK_ITEMS',    "DB_PREFIX . 'link_job_items'",      null, null],
        // v1.9.1 추가(비상 확인값, 마이그레이션 0008). 가드 블록과 함께 고쳤다.
        ['T_EMERGENCY',     "DB_PREFIX . 'emergency'",          null, null],
        // v1.10.0 추가(접근 단계 그룹, 마이그레이션 0010). 가드 블록과 함께 고쳤다.
        ['T_ACCESS_GROUPS',         "DB_PREFIX . 'access_groups'",         null, null],
        ['T_ACCESS_GROUP_MEMBERS',  "DB_PREFIX . 'access_group_members'",  null, null],
        ['T_ACCESS_GROUP_SERVICES', "DB_PREFIX . 'access_group_services'", null, null],
        // v1.10.1 추가(가입 신청, 마이그레이션 0011). 가드 블록과 함께 고쳤다.
        ['T_REGISTER_REQUESTS',     "DB_PREFIX . 'register_requests'",     null, null],
        // v1.9.0 추가(플랫폼, 마이그레이션 0007). 가드 블록과 함께 고쳤다.
        ['T_RC_DRAFTS',     "DB_PREFIX . 'rc_drafts'",           null, null],
        ['T_RC_VERSIONS',   "DB_PREFIX . 'rc_versions'",         null, null],
        ['T_PLAT_LOGS',     "DB_PREFIX . 'plat_logs'",           null, null],
        ['T_PLAT_AUDIT',    "DB_PREFIX . 'plat_audit'",          null, null],
        ['T_PLAT_COLLS',    "DB_PREFIX . 'plat_collections'",    null, null],
        ['T_PLAT_DOCS',     "DB_PREFIX . 'plat_docs'",           null, null],
        ['T_PLAT_USAGE',    "DB_PREFIX . 'plat_usage'",          null, null],
    ]);
}

function ins_render_config($v)
{
    $spec = ins_config_spec($v);
    $out  = "<?php\n"
          . "/**\n"
          . " * ulogin 설정 파일 — install.php 가 " . gmdate('Y-m-d H:i:s') . " UTC 에 생성했다.\n"
          . " *\n"
          . " * !! 이 파일은 절대 웹에서 읽히면 안 된다 !!\n"
          . " *    server/.htaccess 가 차단하지만, 배포 후 브라우저로 /server/config.php 를\n"
          . " *    직접 열어 403/404 인지 반드시 확인한다.\n"
          . " *\n"
          . " * 상수 집합은 config.sample.php 와 정확히 일치한다. 하나라도 지우면\n"
          . " * core/ 와 api/ 가 Undefined constant 로 죽는다.\n"
          . " */\n";

    $pad = 0;
    foreach ($spec as $row) {
        $pad = max($pad, strlen($row[0]));
    }
    foreach ($spec as $row) {
        list($name, $literal, $section, $comment) = $row;
        if ($section !== null) {
            $out .= "\n// ---------------------------------------------------------------------\n";
            $out .= '// ' . $section . "\n";
            if ($comment !== null) {
                foreach (explode("\n", $comment) as $line) {
                    $out .= '//   ' . $line . "\n";
                }
            }
            $out .= "// ---------------------------------------------------------------------\n";
        } elseif ($comment !== null) {
            $out .= "\n";
            foreach (explode("\n", $comment) as $line) {
                $out .= '// ' . $line . "\n";
            }
        }
        $out .= 'define(' . str_pad("'" . $name . "',", $pad + 3) . ' ' . $literal . ");\n";
    }
    return $out;
}

/**
 * 화면에 보여줄 config 텍스트에서 **비밀값만** 자리표시자로 바꾼다 (리더 판정 D-110).
 *
 * 대상은 셋뿐이다 — `DB_PASS` / `SECRET_ENC_KEY` / `APP_KEY`.
 * 나머지(DB_HOST·DB_NAME·DB_USER·BASE_URL…)는 운영자가 손으로 옮겨야 하는 설정값이고,
 * 가리면 파일을 만들 수 없게 된다.
 *
 * **이 함수는 원문을 대신하지 않는다.** 원문을 파일로 남기지 못했으면 호출부가
 * 원문을 그대로 보여준다 — 그것이 D-110 이 지키려던 '설치 실패 시 복구 수단' 이다.
 */
function ins_redact_config($text)
{
    $keys = ['DB_PASS', 'SECRET_ENC_KEY', 'APP_KEY'];
    foreach ($keys as $k) {
        $text = preg_replace(
            "/(define\\(\\s*'" . preg_quote($k, '/') . "'\\s*,\\s*)'(?:[^'\\\\]|\\\\.)*'/",
            "$1'__PASTE_FROM_GENERATED_FILE__'",
            (string) $text
        );
    }
    return (string) $text;
}

// =====================================================================
// 화면
// =====================================================================

/**
 * 이 패키지의 버전 문자열. 손으로 박아 두지 않는다 (리더 판정 D-99 · D-125).
 *
 * 여기에는 '범용 로그인 서비스 v1.1.0' 이 하드코딩되어 있었고, v1.2.0·v1.3.0 을
 * 지나도록 그대로 남아 설치기 첫 화면이 낡은 버전을 주장했다.
 * public/index.html 푸터에서 같은 결함을 없앤 D-99 와 같은 종류다.
 *
 * 버전의 단일 출처는 {설치루트}/public/version.txt 다 (D-84).
 * 경로는 install.php 자신이 이미 요구하는 배치에 의존한다 — 4단계 BASE_URL 안내가
 * "이 주소 아래에 /server/ 와 /public/ 가 있어야 합니다" 라고 명시한다(D-28).
 * 즉 dirname(INS_DIR) 아래의 public/ 은 설치기가 이미 전제하는 자리이고,
 * 새로운 가정을 들이지 않는다.
 *
 * **읽기 실패는 설치를 막지 않는다.** 설치기는 배포 초기에 도는 물건이라
 * 버전 표시 때문에 설치가 중단되면 본말전도다. 파일이 없든 권한이 없든 값이
 * 이상하든 빈 문자열을 돌려주고, 부제는 버전 없이 인쇄된다.
 *
 * @return string 예: '1.3.0_20260828' / 읽지 못하면 ''
 */
function ins_package_version()
{
    $path = dirname(INS_DIR) . '/public/version.txt';
    if (!@is_file($path) || !@is_readable($path)) {
        return '';
    }
    $raw = @file_get_contents($path, false, null, 0, 256);
    if ($raw === false || $raw === '') {
        return '';
    }
    // 첫 줄만 쓴다. 파일이 무엇이든 화면에 나가는 것은 짧은 안전 문자열뿐이다.
    // (strtok 은 전역 토큰 상태를 남기므로 쓰지 않는다)
    $parts = preg_split('/\r\n|\r|\n/', $raw, 2);
    $line  = trim($parts[0]);
    if ($line === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/', $line)) {
        return '';
    }
    return $line;
}

function ins_page_start($title, $step)
{
    global $INS_STEP_NAMES;
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo '<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="referrer" content="no-referrer">';
    echo '<title>ulogin 설치 · ' . ins_h($title) . '</title>';
    echo '<style>
:root{--bg:#f4f6f9;--card:#fff;--line:#dde3ea;--tx:#1f2733;--mut:#6b7787;--pri:#2f6feb;--ok:#1a8f4c;--warn:#b26a00;--bad:#c62828;}
*{box-sizing:border-box}
body{margin:0;padding:32px 16px 80px;background:var(--bg);color:var(--tx);
 font:15px/1.65 -apple-system,BlinkMacSystemFont,"Malgun Gothic","맑은 고딕",sans-serif}
.wrap{max-width:860px;margin:0 auto}
h1{font-size:22px;margin:0 0 4px}
h2{font-size:18px;margin:28px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--line)}
h3{font-size:15px;margin:20px 0 6px}
.sub{color:var(--mut);font-size:13px;margin:0 0 20px}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:24px 26px;margin-bottom:18px}
.steps{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:20px;font-size:12px}
.steps span{padding:4px 10px;border-radius:20px;background:#e7ebf1;color:var(--mut);white-space:nowrap}
.steps span.on{background:var(--pri);color:#fff;font-weight:700}
.steps span.done{background:#d7ecdd;color:var(--ok)}
table{width:100%;border-collapse:collapse;margin:10px 0;font-size:14px}
th,td{padding:8px 10px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}
th{background:#f7f9fc;font-weight:600;width:32%}
.ok{color:var(--ok);font-weight:700}
.bad{color:var(--bad);font-weight:700}
.warn{color:var(--warn);font-weight:700}
label{display:block;margin:14px 0 4px;font-weight:600;font-size:14px}
.hint{color:var(--mut);font-size:12.5px;margin:3px 0 0;font-weight:400}
input[type=text],input[type=password],input[type=email],input[type=url],textarea,select{
 width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:6px;font:14px/1.5 inherit;background:#fff;color:var(--tx)}
textarea{min-height:96px;font-family:ui-monospace,Consolas,monospace;font-size:13px}
button{margin-top:22px;padding:11px 26px;border:0;border-radius:7px;background:var(--pri);color:#fff;
 font-size:15px;font-weight:700;cursor:pointer}
button.ghost{background:#e7ebf1;color:var(--tx);margin-left:8px}
button:disabled{background:#aab3c0;cursor:not-allowed}
.msg{border-radius:8px;padding:13px 16px;margin:0 0 18px;font-size:14px}
.msg.err{background:#fdeaea;border:1px solid #f3c2c2;color:#8c1d1d}
.msg.good{background:#e8f6ec;border:1px solid #bfe3ca;color:#14602f}
.msg.big{background:#fff3e0;border:2px solid #e8a33d;color:#7a4a00;font-size:15px}
ul,ol{margin:8px 0 8px 20px;padding:0}
li{margin:4px 0}
code,pre{font-family:ui-monospace,Consolas,monospace}
code{background:#eef1f6;padding:1px 5px;border-radius:4px;font-size:13px}
pre{background:#1e2530;color:#e7edf5;padding:14px 16px;border-radius:8px;overflow:auto;font-size:12.5px;line-height:1.5}
.radio{display:block;border:1px solid var(--line);border-radius:8px;padding:12px 14px;margin:8px 0;cursor:pointer;font-weight:400}
.radio input{margin-right:8px}
.radio b{display:inline-block}
.foot{color:var(--mut);font-size:12px;text-align:center;margin-top:26px}
</style></head><body><div class="wrap">';
    $pkgVer = ins_package_version();
    echo '<h1>ulogin 설치</h1><p class="sub">통합로그인서비스'
       . ($pkgVer === '' ? '' : ' v' . ins_h($pkgVer))
       . ' — ' . ins_h($title) . '</p>';
    echo '<div class="steps">';
    foreach ($INS_STEP_NAMES as $n => $label) {
        $cls = $n === $step ? 'on' : ($n < $step ? 'done' : '');
        echo '<span class="' . $cls . '">' . $n . '. ' . ins_h($label) . '</span>';
    }
    echo '</div>';
}

function ins_page_end()
{
    echo '<p class="foot">설치가 끝나면 이 파일(install.php)을 서버에서 삭제하세요.</p>';
    echo '</div></body></html>';
}

/** 복구 불가능한 상황. 원인은 서버 로그에만 남긴다. */
function ins_fatal($msg, $logDetail = null)
{
    if ($logDetail !== null) {
        error_log('[ulogin-install] ' . $logDetail);
    }
    if (!headers_sent()) {
        http_response_code(500);
    }
    ins_page_start('오류', 0);
    echo '<div class="card"><div class="msg err">' . ins_h($msg) . '</div>';
    echo '<p class="hint">브라우저를 새로 열어 <code>install.php</code> 를 다시 실행하면 처음 단계부터 시작합니다.</p>';
    echo '</div>';
    ins_page_end();
    exit;
}

// =====================================================================
// 설치 토큰 — v1.8.0 설계 G-5 · 리더 판정 D-294 (Critical: 설치 선점)
//
// 터널이 이 PC 에서 돌아 **외부 요청의 REMOTE_ADDR 도 127.0.0.1** 이다. 그래서 「루프백만
// 허용」으로 막을 수 없고(D-234·D-272 와 같은 논증), Host 헤더도 클라이언트가 정한다(D-233).
// 설치 전 서버가 뜨는 순간 인터넷의 누구든 먼저 관리자가 될 수 있다.
// → **「이 PC 의 파일을 읽을 수 있다」는 증명을 요구한다**(Jupyter·GitLab 초기 비밀번호 방식).
//
//  · 토큰: random_bytes(32) → hex 64자. 설치 전에만, 파일이 없을 때 첫 요청이 만든다(배타 생성 'x')
//  · 🔴 위치(Windows): %ProgramData%/ulogin/web/.install-token — **프로젝트 트리에 두지 않는다.**
//    프로젝트 트리는 사용자 수용으로 CodexSandboxUsers 가 읽는다(D-282). 그 계정이 토큰을 읽으면
//    먼저 설치한다. ProgramData 쪽은 D-275 에서 BUILTIN\Users·CodexSandbox* 0건으로 좁혀 둔 트리다.
//    그 폴더가 없으면 **설치를 진행하지 않는다**(프로젝트 트리로 폴백하지 않는다 — fail-closed).
//    Windows 가 아니면(공용 호스팅) server/storage/.install-token (.htaccess 차단 + 라우터 denyTrees).
//  · 🔴 새 파일은 부모의 상속 ACL 을 받는다(D-264). 만든 직후 **사람이** `icacls` 로 확인한다 —
//    `/T` 를 붙이지 마라(D-274: <설치루트> 접합을 따라 들어간다).
//  · 수명: 60분(mtime). 지나면 거부 — 파일을 지우고 새로고침하면 새 토큰이 생긴다. 설치 완료 즉시 삭제
//  · 제출: 1단계 폼의 입력 칸 한 번. 맞으면 **세션 ID 를 갈고**(고정 공격 방지) 세션에 토큰의
//    SHA-256 을 둔다. 이후 매 POST 는 그 해시가 **지금 파일의 토큰**과 같은지 다시 본다 —
//    파일이 지워지거나 만료되면 진행 중인 설치도 멈춘다.
//    (설계는 「매 POST 에 hidden 으로 동봉」이었다. 그러면 토큰 값이 **매 응답 HTML 에 실린다.**
//     리더 지시 「토큰 값을 응답 본문에 싣지 마라」와 충돌해 세션 해시로 바꿨다. 설치기는
//     원래 PHP 세션으로 단계 상태를 들고 다닌다 — 설계 G-5 의 「세션을 쓰지 않는다」는 사실과 달랐다)
//  · 실패: 403 + 시도 제한 5회/15분 — migrate.php mig_throttle_consume() 규약(파일 하나를
//    flock(LOCK_EX) 로 잡은 채 읽고·판정하고·쓴다, **셀 수 없으면 거부**).
//    🔴 감사 M18-1: **맞는 토큰을 먼저 대조하고 틀린 것만 센다.** 토큰은 256비트라 무차별 대입이
//    성립하지 않고, 먼저 세면 공개 도메인의 5요청/15분이 운영자를 무기한 429 로 막는다.
//    빈 입력은 소모하지 않는다(V14-L-3 — 익명 5요청으로 운영자를 잠그는 범위를 넓히지 않는다).
//  · **토큰 값은 어떤 응답에도 싣지 않는다.** 경로는 비밀이 아니라 화면에 적는다.
// =====================================================================

/** 토큰 파일을 둘 디렉터리. null 이면 둘 곳이 없다(→ 설치 거부). */
function ins_token_dir()
{
    if (DIRECTORY_SEPARATOR === '\\') {
        $pd = getenv('ProgramData');
        if ($pd === false || trim((string) $pd) === '') {
            $pd = getenv('ALLUSERSPROFILE');
        }
        if ($pd === false || trim((string) $pd) === '') {
            return null;
        }
        $dir = rtrim(str_replace('\\', '/', (string) $pd), '/') . '/ulogin/web';
        return is_dir($dir) ? $dir : null;
    }
    if (!is_dir(INS_STORAGE)) {
        ins_write_guarded_dir(INS_STORAGE);
    }
    return is_dir(INS_STORAGE) ? INS_STORAGE : null;
}

function ins_token_file()
{
    $d = ins_token_dir();
    return $d === null ? null : $d . '/.install-token';
}

/** 화면에 적을 토큰 파일 위치(비밀이 아니다). */
function ins_token_where()
{
    if (DIRECTORY_SEPARATOR === '\\') {
        $f = ins_token_file();
        return $f !== null ? $f : '%ProgramData%/ulogin/web/.install-token';
    }
    return 'server/storage/.install-token';
}

/**
 * 토큰 파일이 없으면 만든다.
 * @return string 'ok' | 'expired' | 'nodir' | 'fail'
 */
function ins_token_ensure()
{
    $f = ins_token_file();
    if ($f === null) {
        return 'nodir';
    }
    clearstatcache(true, $f);
    if (is_file($f)) {
        $mt = @filemtime($f);
        if ($mt === false) {
            return 'fail';
        }
        return (time() - (int) $mt > INS_TOKEN_TTL) ? 'expired' : 'ok';
    }
    // 배타 생성 — 동시에 도착한 첫 요청 둘이 서로의 토큰을 덮어쓰지 않는다.
    $fh = @fopen($f, 'x');
    if ($fh === false) {
        clearstatcache(true, $f);
        return is_file($f) ? 'ok' : 'fail';
    }
    $ok = fwrite($fh, bin2hex(random_bytes(32)) . "\n") !== false;
    fflush($fh);
    fclose($fh);
    @chmod($f, 0600);
    if (!$ok) {
        @unlink($f);
        return 'fail';
    }
    // 값은 남기지 않는다. 위치만 — 운영자가 ACL 을 확인할 수 있게.
    error_log('[ulogin-install] install token file created: ' . $f
        . ' (valid 60 min). check its ACL with icacls (never with /T).');
    return 'ok';
}

/** 지금 유효한 토큰. 파일 없음·만료·형식 오류면 null. */
function ins_token_current()
{
    $f = ins_token_file();
    if ($f === null) {
        return null;
    }
    clearstatcache(true, $f);
    if (!is_file($f)) {
        return null;
    }
    $mt = @filemtime($f);
    if ($mt === false || time() - (int) $mt > INS_TOKEN_TTL) {
        return null;
    }
    $raw = @file_get_contents($f, false, null, 0, 256);
    $t   = strtolower(trim((string) $raw));
    return preg_match('/^[0-9a-f]{64}$/', $t) === 1 ? $t : null;
}

/** 이 세션이 지금의 토큰으로 인증돼 있는가. 파일이 바뀌거나 사라지면 false. */
function ins_token_session_ok()
{
    if (empty($_SESSION['ins_tok']) || !is_string($_SESSION['ins_tok'])) {
        return false;
    }
    $t = ins_token_current();
    return $t !== null && hash_equals((string) $_SESSION['ins_tok'], hash('sha256', $t));
}

function ins_token_throttle_file()
{
    $d = ins_token_dir();
    return $d === null ? null : $d . '/.install-token.throttle';
}

/**
 * 틀린 토큰 시도 제한 — **migrate.php mig_throttle_consume() 와 같은 규약**(V14-H-2 · V14b-M-2).
 * 핸들 하나를 flock(LOCK_EX) 로 잡은 채 읽고·판정하고·쓴다. 검증 **전에** 소모한다.
 * 한도를 넘으면 429 로 응답하고 끝낸다.
 * @return bool 셀 수 있었는가. **false 면 호출자가 거부한다** — 반환값을 버리지 마라.
 */
function ins_token_throttle_consume()
{
    $path = ins_token_throttle_file();
    if ($path === null) {
        return false;
    }
    $now = time();
    $fh  = @fopen($path, 'c+');
    if ($fh === false) {
        error_log('[ulogin-install] install token throttle file not writable: ' . $path);
        return false;
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        error_log('[ulogin-install] install token throttle lock failed: ' . $path);
        return false;
    }
    $raw = stream_get_contents($fh);
    $st  = ($raw === false) ? null : json_decode((string) $raw, true);
    if (!is_array($st) || !isset($st['first']) || ($now - (int) $st['first']) > INS_TOKEN_WINDOW) {
        $st = array('count' => 0, 'first' => $now);
    }
    if ((int) $st['count'] >= INS_TOKEN_MAX_FAIL) {
        $retry = INS_TOKEN_WINDOW - ($now - (int) $st['first']);
        flock($fh, LOCK_UN);
        fclose($fh);
        error_log('[ulogin-install] install token attempts exhausted. from '
            . (string) (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '?'));
        ins_token_page('설치 토큰 시도 횟수를 초과했습니다. ' . max(1, $retry) . '초 뒤에 다시 시도하세요.', 429);
    }
    $st['count'] = (int) $st['count'] + 1;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, (string) json_encode($st));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    @chmod($path, 0600);
    return true;
}

function ins_token_throttle_clear()
{
    $p = ins_token_throttle_file();
    if ($p !== null && is_file($p)) {
        @unlink($p);
    }
}

/** 토큰 입력 칸(HTML). 값은 절대 채우지 않는다. */
function ins_token_field()
{
    return '<label>설치 토큰'
         . '<input type="password" name="install_token" value="" required autocomplete="off" spellcheck="false" maxlength="128">'
         . '<span class="hint">이 서버를 운영하는 PC에서 <code>' . ins_h(ins_token_where()) . '</code> 파일을 열어 '
         . '내용(64자)을 붙여 넣으세요. 파일이 없으면 이 페이지를 한 번 새로고침하면 생깁니다. '
         . '<b>만든 지 60분이 지나면 무효</b>입니다. 그때는 파일을 지우고 새로고침하세요.</span></label>';
}

/** 토큰 요구 화면을 내고 끝낸다. */
function ins_token_page($msg, $status)
{
    if (!headers_sent()) {
        http_response_code((int) $status);
    }
    ins_page_start('설치 토큰 확인', 1);
    echo '<div class="card">';
    if ($msg !== null) {
        echo '<div class="msg err">' . ins_h($msg) . '</div>';
    }
    echo '<p>이 설치기는 <b>서버 PC의 파일을 읽을 수 있는 사람</b>만 진행할 수 있습니다. '
       . '공개 주소로 먼저 들어온 제3자가 관리자 계정을 정하는 것을 막기 위해서입니다.</p>';
    echo '<form method="post">' . ins_csrf_field() . '<input type="hidden" name="step" value="1">'
       . ins_token_field() . '<button type="submit">확인 후 다음</button></form></div>';
    ins_page_end();
    exit;
}

/** 토큰을 둘 곳이 없거나 만들 수 없다 — 설치를 진행하지 않는다(fail-closed). */
function ins_token_unavailable($state)
{
    error_log('[ulogin-install] install token unavailable (' . $state . '). installation refused.');
    if (!headers_sent()) {
        http_response_code(503);
    }
    ins_page_start('설치 토큰을 만들 수 없음', 0);
    echo '<div class="card"><div class="msg err"><b>설치 토큰 파일을 만들 수 없어 설치를 진행하지 않습니다.</b></div>';
    if (DIRECTORY_SEPARATOR === '\\') {
        echo '<p>서버 PC에 <code>%ProgramData%\\ulogin\\web</code> 폴더가 있어야 하고 웹 서버 계정이 그 폴더에 쓸 수 있어야 합니다. '
           . '토큰을 프로젝트 폴더에 두지 않는 이유는 다른 로컬 계정이 그 폴더를 읽을 수 있기 때문입니다.</p>';
    } else {
        echo '<p><code>server/storage/</code> 폴더를 웹 서버가 쓸 수 있게 해 주세요.</p>';
    }
    echo '</div>';
    ins_page_end();
    exit;
}

/**
 * 🔴 **설치 POST 의 관문.** 라우터가 CSRF 검사 직후, 단계 분기 **전에** 부른다.
 * 통과하지 못하면 응답하고 끝낸다. 이 한 줄을 지우면 토큰 없이 2단계로 넘어간다(변이 검증 항목).
 */
function ins_token_gate($submitted)
{
    if (ins_token_session_ok()) {
        return;
    }
    $state = ins_token_ensure();
    if ($state === 'nodir' || $state === 'fail') {
        ins_token_unavailable($state);
    }
    if ($state === 'expired') {
        ins_token_page('설치 토큰이 만료되었습니다(만든 지 60분 초과). 토큰 파일을 지운 뒤 이 페이지를 새로 여세요. 새 토큰이 만들어집니다.', 403);
    }
    $given = strtolower(trim((string) ins_post('install_token')));
    if ($submitted !== '1' || $given === '') {
        error_log('[ulogin-install] POST without a verified install token refused. from '
            . (string) (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '?'));
        ins_token_page('설치 토큰이 필요합니다.', 403);
    }
    $t = ins_token_current();
    if ($t !== null && preg_match('/^[0-9a-f]{64}$/', $given) === 1 && hash_equals($t, $given)) {
        // 256비트 토큰 — 무차별 대입이 성립하지 않으므로 **맞는 토큰은 제한에 걸리지 않는다**(감사 M18-1).
        // 먼저 세면 원격 5요청/15분으로 루프백 운영자까지 429 를 받는다(카운터가 하나다 — 실측).
        ins_token_throttle_clear();
        // 세션 고정 방지 — 토큰을 아는 사람이 공격자가 미리 심어 둔 세션 ID 를 인증해 주지 않게 한다.
        @session_regenerate_id(true);
        $_SESSION['ins_tok'] = hash('sha256', $t);
        return;
    }
    // 틀린 것만 센다. 한도 초과면 429(로그 범람·자원 소모 상한으로만 남긴다).
    // 셀 수 없으면 거부한다 — 반환값을 버리지 마라(V14b-M-2).
    if (!ins_token_throttle_consume()) {
        ins_token_unavailable('throttle');
    }
    error_log('[ulogin-install] wrong install token. from '
        . (string) (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '?'));
    ins_token_page('설치 토큰이 맞지 않습니다.', 403);
}

// =====================================================================
// 자기 잠금 (M-12-1 / M-12-2)
//   - `.install.lock` 또는 `storage/.installed` 또는 `config.php` 가 하나라도
//     있으면 즉시 차단한다. 락 파일만 지우고 재실행하는 우회를 막기 위해
//     config.php 존재도 함께 본다.
//   - 잠금 해제 파라미터(?force=1 등)는 **구현하지 않는다.**
// =====================================================================
$insLockedBy = [];
if (is_file(INS_LOCK)) {
    $insLockedBy[] = 'server/.install.lock';
}
if (is_file(INS_INSTALLED)) {
    $insLockedBy[] = 'server/storage/.installed';
}
if (is_file(INS_CONFIG)) {
    $insLockedBy[] = 'server/config.php';
}
if ($insLockedBy !== []) {
    http_response_code(403);
    ins_page_start('이미 설치됨', 0);
    echo '<div class="card"><div class="msg big"><b>이미 설치가 완료된 서버입니다.</b><br>'
       . '설치기는 더 이상 동작하지 않습니다.</div>';
    echo '<p>감지된 설치 흔적:</p><ul>';
    foreach ($insLockedBy as $f) {
        echo '<li><code>' . ins_h($f) . '</code></li>';
    }
    echo '</ul>';
    $stamp = is_file(INS_LOCK) ? trim((string) @file_get_contents(INS_LOCK)) : '';
    if ($stamp !== '') {
        echo '<p class="hint">잠금 파일 기록: <code>' . ins_h(substr($stamp, 0, 200)) . '</code></p>';
    }
    echo '<h3>지금 해야 할 일</h3><ol>'
       . '<li><b><code>server/install.php</code> 를 서버에서 삭제하세요.</b> 설치기는 인증 없이 도는 코드입니다.</li>'
       . '<li>로그인 화면과 관리자 콘솔은 <code>public/login.html</code>, <code>public/admin.html</code> 입니다.</li>'
       . '</ol>';
    echo '<h3>정말 다시 설치해야 한다면</h3>'
       . '<p class="hint">FTP·파일 관리자로 <code>server/.install.lock</code>, <code>server/storage/.installed</code>, '
       . '<code>server/config.php</code> 세 파일을 모두 손으로 지운 뒤 다시 실행하세요. '
       . '<b>기존 <code>config.php</code> 의 <code>SECRET_ENC_KEY</code> 를 잃으면 저장된 AI API 키를 복호화할 수 없습니다.</b> '
       . '지우기 전에 반드시 백업하세요.</p>';
    echo '</div>';
    ins_page_end();
    exit;
}

// =====================================================================
// 세션 (단계 간 상태 + 1회성 CSRF)  — M-12-8
//   DB 접속 정보는 브라우저 히든 필드로 왕복시키지 않고 서버 세션에만 둔다.
//   세션 파일은 웹 루트 밖(PHP 세션 디렉토리)에 있고 설치 완료 시 폐기한다.
// =====================================================================
if (session_status() !== PHP_SESSION_ACTIVE) {
    // 감사 H18-1 — 설치 세션 파일을 **토큰과 같은 신뢰 경계**에 둔다.
    // 검증된 세션은 토큰과 같은 권한이다. 세션 파일이 다른 계정이 읽고 쓰는 폴더
    // (session.save_path 미설정 → %TEMP%, 이 PC 에서 CodexSandboxUsers:(M))에 있으면,
    // 그 계정은 파일 이름(=세션 ID)과 평문 CSRF 를 읽어 **토큰 없이** 설치를 이어 간다(보안 실측).
    // 그래서 토큰 폴더(D-275 로 좁힌 ProgramData 트리) 아래에만 둔다. 못 두면 설치하지 않는다.
    // 새 폴더는 부모의 상속 ACL 을 받는다(D-264) — 처음 만든 뒤 icacls 로 확인한다(/T 금지, D-274).
    if (DIRECTORY_SEPARATOR === '\\') {
        $insTokDir = ins_token_dir();
        if ($insTokDir === null) {
            ins_token_unavailable('nodir');
        }
        $insSessDir = $insTokDir . '/install-sessions';
        if (!is_dir($insSessDir) && !@mkdir($insSessDir, 0700)) {
            ins_token_unavailable('sessdir');
        }
        if (@session_save_path($insSessDir) === false || !is_writable($insSessDir)) {
            ins_token_unavailable('sessdir');
        }
    }
    @session_name('ULOGIN_INSTALL');
    @session_set_cookie_params(0, '/', '', ins_is_https(), true);
    if (!@session_start()) {
        ins_fatal('PHP 세션을 시작할 수 없어 설치를 진행할 수 없습니다. 호스팅의 session.save_path 설정을 확인하세요.');
    }
}
if (empty($_SESSION['ins_csrf'])) {
    $_SESSION['ins_csrf'] = bin2hex(random_bytes(32));
}
if (!isset($_SESSION['ins']) || !is_array($_SESSION['ins'])) {
    $_SESSION['ins'] = [];
}
$S =& $_SESSION['ins'];

function ins_csrf_field()
{
    return '<input type="hidden" name="_csrf" value="' . ins_h($_SESSION['ins_csrf']) . '">';
}

/** 토큰 검증 후 즉시 회전한다(1회성). */
function ins_csrf_check()
{
    $t = isset($_POST['_csrf']) ? (string) $_POST['_csrf'] : '';
    if ($t === '' || empty($_SESSION['ins_csrf']) || !hash_equals((string) $_SESSION['ins_csrf'], $t)) {
        ins_fatal('설치 폼의 보안 토큰이 유효하지 않습니다. 페이지를 새로 열어 처음부터 다시 진행하세요.');
    }
    $_SESSION['ins_csrf'] = bin2hex(random_bytes(32));
}

function ins_post($k, $default = '')
{
    return isset($_POST[$k]) ? (is_string($_POST[$k]) ? $_POST[$k] : $default) : $default;
}

// =====================================================================
// 1단계 — 환경 점검
// =====================================================================

function ins_env_checks()
{
    $req  = [];
    $info = [];

    // D-57 / D-60 — 최소 요구 버전은 7.2.0 이다. 사용자의 실제 호스팅이 7.2.34 이고,
    // 코드는 7.2 문법만 쓰도록 정리되어 있다(파일 상단 금지 목록 참조).
    // 7.2·7.3 은 보안 지원이 끝난 버전이지만 **여기서 차단하지 않는다.**
    // 경고는 아래 정보성(권고) 항목에만 넣는다 — 필수 항목에 두면 다시 막힌 것처럼 보인다.
    $phpOk = version_compare(PHP_VERSION, '7.2.0', '>=');
    $req[] = ['PHP 버전 7.2 이상', $phpOk, PHP_VERSION];
    // v1.8.0 (설계 E-2): 필수는 「pdo_sqlite **또는** pdo_mysql」. 종전처럼 pdo_mysql 만 필수로 두면
    // SQLite 로 쓰려는 호스팅에서 설치가 막힌다. 두 확장의 유무는 아래 정보성 항목에 각각 보인다.
    $hasSqlite = extension_loaded('pdo_sqlite');
    $hasMysql  = extension_loaded('pdo_mysql');
    $req[] = ['PDO 저장소 드라이버 (pdo_sqlite 또는 pdo_mysql)', $hasSqlite || $hasMysql,
        ($hasSqlite || $hasMysql) ? '사용 가능' : '둘 다 없음: 호스팅에 pdo_sqlite 또는 pdo_mysql 활성화를 요청하세요'];
    $req[] = ['OpenSSL 확장 (openssl)', extension_loaded('openssl') && function_exists('openssl_encrypt'),
        extension_loaded('openssl') ? '사용 가능' : '없음'];
    $req[] = ['mbstring 확장', extension_loaded('mbstring'), extension_loaded('mbstring') ? '사용 가능' : '없음'];
    $req[] = ['JSON 확장', function_exists('json_encode'), function_exists('json_encode') ? '사용 가능' : '없음'];
    $req[] = ['schema.sql 읽기', is_readable(INS_SCHEMA), is_readable(INS_SCHEMA) ? '읽을 수 있음' : 'server/schema.sql을 찾을 수 없음'];
    $req[] = ['random_bytes() 사용 가능', function_exists('random_bytes'), function_exists('random_bytes') ? '사용 가능' : '없음'];

    // D-57 / D-60 — PHP 보안 지원 상태. **권고 항목이다. 설치를 막지 않는다.**
    // 정보성 표의 첫 줄에 둔다(가장 먼저 눈에 들어오는 자리).
    if (version_compare(PHP_VERSION, '7.3.0', '<')) {
        $info[] = ['PHP 보안 지원 상태', false,
            'PHP 7.2는 2019년 11월에 보안 지원이 종료된 버전입니다. 호스팅에 PHP 7.4 이상 옵션이 있으면 '
          . '올리는 것을 권합니다. 이 시스템은 AI API 키를 다룹니다. '
          . '— 설치는 이대로 진행됩니다(이 항목은 설치를 막지 않습니다).'];
    } elseif (version_compare(PHP_VERSION, '7.4.0', '<')) {
        $info[] = ['PHP 보안 지원 상태', false,
            'PHP 7.3은 2021년 12월에 보안 지원이 종료된 버전입니다. 호스팅에 PHP 7.4 이상 옵션이 있으면 '
          . '올리는 것을 권합니다. 이 시스템은 AI API 키를 다룹니다. '
          . '— 설치는 이대로 진행됩니다(이 항목은 설치를 막지 않습니다).'];
    } else {
        $info[] = ['PHP 보안 지원 상태', true,
            PHP_VERSION . ' — 7.4 이상입니다. (이 시스템은 7.2부터 동작하지만 높은 쪽이 낫습니다)'];
    }

    $info[] = ['PDO SQLite (pdo_sqlite)', $hasSqlite,
        $hasSqlite ? '사용 가능: SQLite 저장소(권장)를 고를 수 있습니다'
                   : '없음: SQLite를 고를 수 없습니다. MySQL 저장소로 설치하세요'];
    $info[] = ['PDO MySQL (pdo_mysql)', $hasMysql,
        $hasMysql ? '사용 가능: MySQL 저장소를 고를 수 있습니다' : '없음: MySQL 저장소를 고를 수 없습니다'];

    $rw = ins_has_mod_rewrite();
    $info[] = ['mod_rewrite', $rw === null ? null : $rw,
        $rw === null ? '확인 불가 (없어도 /api/auth/login.php 경로로 동작합니다)'
                     : ($rw ? '사용 가능: 확장자 없는 경로도 동작합니다'
                            : '없음: API는 .php 확장자 경로로 호출해야 합니다')];
    $info[] = ['mail() 함수', function_exists('mail'),
        function_exists('mail') ? '존재 (실제 발송 가능 여부는 호스팅에 따라 다릅니다)'
                                : '없음: 메일 드라이버를 log로만 쓸 수 있습니다'];
    $info[] = ['현재 접속 방식', ins_is_https(), ins_is_https() ? 'HTTPS' : 'HTTP (평문): 4단계에서 경고를 확인하세요'];
    $info[] = ['server/ 쓰기 권한', is_writable(INS_DIR),
        is_writable(INS_DIR) ? 'config.php를 자동 생성할 수 있습니다'
                             : '쓰기 불가: config.php 내용을 화면에 출력하니 손으로 만들면 됩니다'];
    $info[] = ['server/storage/ 쓰기 권한',
        is_dir(INS_STORAGE) ? is_writable(INS_STORAGE) : is_writable(INS_DIR),
        'log 메일 드라이버가 메일 본문을 여기에 저장합니다'];
    $info[] = ['config.sample.php', is_readable(INS_SAMPLE),
        is_readable(INS_SAMPLE) ? '존재 (상수 집합 대조용)' : '없음 (설치에는 영향 없음)'];

    return [$req, $info];
}

function ins_step1()
{
    list($req, $info) = ins_env_checks();
    $blocked = false;
    foreach ($req as $r) {
        if (!$r[1]) {
            $blocked = true;
        }
    }

    ins_page_start('환경 점검', 1);

    // D-52 / 보안 감사 M-15 — 설치 완료 전 선점 경고. 화면 최상단에 둔다.
    echo '<div class="card"><div class="msg big">'
       . '<b>이 파일은 누구나 접속할 수 있습니다.</b><br>'
       . '<code>install.php</code> 는 인증 없이 동작합니다. 업로드된 시점부터 설치가 끝나기 전까지는 '
       . '<b>이 주소를 먼저 여는 사람이 설치자가 됩니다</b> — 제3자가 자기 이메일로 최고관리자 계정을 만들고 '
       . 'DB·키·시크릿을 지정할 수 있습니다.<br>'
       . '<b>업로드 직후 즉시 설치를 마치고, 완료 후 이 파일을 삭제하세요.</b> '
       . '"나중에 설치"는 선택지가 아닙니다. 지금 끝낼 시간이 없다면 파일을 서버에서 내리고 나중에 다시 올리세요.'
       . '</div></div>';

    echo '<div class="card">';
    echo '<p>이 서버가 ulogin을 돌릴 수 있는지 먼저 확인합니다. '
       . '<b>필수 항목</b>이 하나라도 없으면 설치를 진행할 수 없습니다.</p>';

    echo '<h2>필수 항목</h2><table><tr><th>항목</th><th>결과</th><th>비고</th></tr>';
    foreach ($req as $r) {
        echo '<tr><td>' . ins_h($r[0]) . '</td><td class="' . ($r[1] ? 'ok">통과' : 'bad">실패')
           . '</td><td>' . ins_h($r[2]) . '</td></tr>';
    }
    echo '</table>';

    echo '<h2>정보성 항목 <span class="hint">(없어도 설치는 진행됩니다. 리더 판정 D-8)</span></h2>';
    echo '<table><tr><th>항목</th><th>결과</th><th>비고</th></tr>';
    foreach ($info as $r) {
        if ($r[1] === null) {
            $cls = 'warn">확인 불가';
        } elseif ($r[1]) {
            $cls = 'ok">확인';
        } else {
            $cls = 'warn">주의';
        }
        echo '<tr><td>' . ins_h($r[0]) . '</td><td class="' . $cls . '</td><td>' . ins_h($r[2]) . '</td></tr>';
    }
    echo '</table>';

    if ($blocked) {
        echo '<div class="msg err">필수 항목을 충족하지 못했습니다. 호스팅 관리자에게 위 확장 모듈 활성화를 요청한 뒤 이 페이지를 새로고침하세요.</div>';
        echo '<button disabled>다음 단계로</button>';
    } else {
        // 설치 토큰(설계 G-5) — 이 세션이 아직 토큰을 내지 않았으면 입력 칸을 붙인다. 값은 채우지 않는다.
        echo '<form method="post">' . ins_csrf_field()
           . '<input type="hidden" name="step" value="1">'
           . (ins_token_session_ok() ? '' : ins_token_field())
           . '<button type="submit">다음: DB 접속 정보 입력</button></form>';
    }
    echo '</div>';
    ins_page_end();
}

// =====================================================================
// 2단계 — DB 접속 (+ 접속 테스트)
// =====================================================================

function ins_step2($err = null, $vals = null)
{
    if ($vals === null) {
        // 포트 칸의 초기값은 환경변수 ULOGIN_DB_PORT 가 있으면 그 값으로 채운다 (v1.7.0).
        //   서버 지킴이가 기동 시 넣어 주는 값이고, 운영자가 그 번호를 따로 알아내야 하는
        //   상황을 만들지 않기 위해서다. **제안일 뿐이고 접속에 쓰이는 값은 입력된 값이다**
        //   — 운영자가 지우거나 고치면 그쪽이 쓰인다(ins_pdo 주석 참고).
        //   환경변수가 없으면 비워 둔다. 비워 두면 v1.6.0 과 같은 동작이다.
        $envPort = getenv('ULOGIN_DB_PORT');
        $envPort = ($envPort === false) ? '' : trim((string) $envPort);
        if (preg_match('/^[0-9]{1,5}$/', $envPort) !== 1) {
            $envPort = '';
        }
        $vals = ['host' => 'localhost', 'port' => $envPort, 'name' => '', 'user' => '', 'prefix' => 'app_ulogin_'];
    }
    if (!isset($vals['port'])) {
        $vals['port'] = '';
    }
    $hasSqlite = extension_loaded('pdo_sqlite');
    $hasMysql  = extension_loaded('pdo_mysql');
    if (!isset($vals['driver'])) {
        $vals['driver'] = $hasSqlite ? 'sqlite' : 'mysql';
    }
    if (!isset($vals['sqlite_path'])) {
        $vals['sqlite_path'] = INS_SQLITE_DEFAULT;
    }
    ins_page_start('DB 접속 정보', 2);
    echo '<div class="card">';
    if ($err !== null) {
        echo '<div class="msg err">' . ins_h($err) . '</div>';
    }
    echo '<form method="post">' . ins_csrf_field() . '<input type="hidden" name="step" value="2">';

    // v1.8.0 (설계 E-2) — 저장소 선택을 맨 위에. SQLite 를 고르면 경로 한 칸만 보이고
    // 호스트·계정·포트 칸은 숨긴다(CSS 형제 선택자 — 이 화면은 JS 를 쓰지 않는다).
    // 숨겨진 칸에는 required 를 걸지 않는다(브라우저가 제출을 막는다). 서버가 검증한다.
    echo '<style>.drv>input[type=radio]{margin:0 6px 0 0}.drv>label.opt{display:inline-block;font-weight:600;margin:6px 18px 6px 0}'
       . '#drv-sqlite:checked~.f-mysql{display:none}#drv-mysql:checked~.f-sqlite{display:none}</style>';
    echo '<h2>저장소</h2><div class="drv">';
    echo '<input type="radio" id="drv-sqlite" name="db_driver" value="sqlite"'
       . ($vals['driver'] === 'sqlite' ? ' checked' : '') . ($hasSqlite ? '' : ' disabled') . '>'
       . '<label class="opt" for="drv-sqlite">SQLite (권장)' . ($hasSqlite ? '' : ' — pdo_sqlite 없음') . '</label>';
    echo '<input type="radio" id="drv-mysql" name="db_driver" value="mysql"'
       . ($vals['driver'] === 'mysql' ? ' checked' : '') . ($hasMysql ? '' : ' disabled') . '>'
       . '<label class="opt" for="drv-mysql">MySQL / MariaDB' . ($hasMysql ? '' : ' — pdo_mysql 없음') . '</label>';

    echo '<div class="f-sqlite">';
    echo '<p class="hint">별도 DB 서버 없이 <b>파일 하나</b>에 저장합니다. 폴더째 복사하면 데이터가 함께 옮겨집니다. '
       . '단, 서버가 켜진 채로 파일만 복사하면 안 됩니다(아직 반영되지 않은 기록이 <code>-wal</code> 파일에 있습니다).</p>';
    echo '<label>DB 파일 경로<input type="text" name="db_sqlite_path" value="' . ins_h($vals['sqlite_path'])
       . '" autocomplete="off" spellcheck="false">'
       . '<span class="hint">상대 경로는 <b>설치 루트</b>(<code>server/</code> 의 상위 폴더) 기준입니다. '
       . '기본값 <code>' . ins_h(INS_SQLITE_DEFAULT) . '</code> 은 설치 루트 <b>바깥</b> — 웹에서 주소로 가리킬 수 없는 자리입니다. '
       . '<b>그대로 두세요.</b> 웹 문서 루트 안의 경로를 적으면 설치기가 그 파일이 웹으로 내려오지 않는지 직접 확인하고, '
       . '확인할 수 없거나 내려오면 진행하지 않습니다. 폴더가 없으면 만듭니다.</span></label>';
    echo '</div>';

    echo '<div class="f-mysql">';
    echo '<p>호스팅에서 발급받은 MySQL 접속 정보를 입력하세요. <b>DB는 미리 생성되어 있어야 합니다</b> — 설치기는 DB 자체를 만들지 않습니다.</p>';
    echo '<label>DB 호스트<input type="text" name="db_host" value="' . ins_h($vals['host'])
       . '" autocomplete="off"><span class="hint">대부분 <code>localhost</code>. 별도 DB 서버를 쓰면 호스팅이 안내한 주소.</span></label>';
    echo '<label>DB 포트<input type="text" name="db_port" value="' . ins_h($vals['port'])
       . '" autocomplete="off" inputmode="numeric" pattern="[0-9]{1,5}">'
       . '<span class="hint">비워 두면 기본값 <code>3306</code> 으로 접속합니다. '
       . '<b>같은 서버에 다른 MySQL 인스턴스가 함께 돌고 있다면 반드시 적으세요</b> — '
       . '비워 두면 그 3306이 다른 서비스의 DB 일 수 있고, 조용히 그쪽에 붙습니다. '
       . '호스팅이 포트를 따로 안내하지 않았다면 비워 두세요.</span></label>';
    echo '<label>DB 이름<input type="text" name="db_name" value="' . ins_h($vals['name'])
       . '" autocomplete="off"><span class="hint">예: <code>ulogin_db</code>. 호스팅이 발급한 이름을 그대로 쓰세요.</span></label>';
    echo '<label>DB 사용자<input type="text" name="db_user" value="' . ins_h($vals['user'])
       . '" autocomplete="off"><span class="hint">예: <code>ulogin_user</code>. 호스팅에 따라 DB 이름과 같은 값일 수 있습니다.</span></label>';
    echo '<label>DB 비밀번호<input type="password" name="db_pass" value="" autocomplete="new-password">'
       . '<span class="hint">화면·로그에 다시 표시하지 않습니다. 비밀번호가 없는 계정이면 비워 두세요.</span></label>';
    echo '</div></div>';   // .f-mysql · .drv
    echo '<label>테이블 prefix<input type="text" name="db_prefix" value="' . ins_h($vals['prefix'])
       . '" required autocomplete="off" pattern="[a-z0-9_]{1,32}">'
       . '<span class="hint">영문 소문자·숫자·밑줄 1~32자. 기본값 <code>app_ulogin_</code>. '
       . '한 DB를 여러 서비스가 공유하므로 prefix로 테이블을 구분합니다. '
       . '<b>설치 후에는 바꿀 수 없습니다.</b></span></label>';

    echo '<button type="submit">접속 테스트 후 다음</button></form></div>';
    ins_page_end();
}

/**
 * 경로가 웹 문서 루트(또는 설치 루트) 안인가. 안이면 그 디렉터리의 URL 경로 목록을 돌려준다.
 * 판정은 **realpath 접두 비교**(대소문자 무시) — 문자열 이름 비교로 새 판정을 만들지 않는다(D-3-1).
 * 이것은 경고·프로브 대상 고르기일 뿐이다. 실제 방어는 아래 HTTP 프로브다(설계 D-2 ④).
 * @return array URL 경로 목록(예: ['/server/storage/db']). 비어 있으면 둘 다 밖이다
 */
function ins_dir_url_paths($dir)
{
    $real = @realpath($dir);
    if ($real === false) {
        return array();
    }
    $real = rtrim(str_replace('\\', '/', $real), '/');
    $cands = array();
    $doc = isset($_SERVER['DOCUMENT_ROOT']) ? (string) $_SERVER['DOCUMENT_ROOT'] : '';
    if ($doc !== '') {
        $cands[] = array($doc, '');
    }
    // 설치 루트의 URL 경로 = install.php 의 URL 에서 두 단계(/server/install.php)를 벗긴 것
    $self = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '/server/install.php';
    $root = rtrim(str_replace('\\', '/', dirname(dirname($self))), '/');
    if ($root === '.' || $root === '/') {
        $root = '';
    }
    $cands[] = array(dirname(INS_DIR), $root);
    $out = array();
    foreach ($cands as $c) {
        $base = @realpath($c[0]);
        if ($base === false) {
            continue;
        }
        $base = rtrim(str_replace('\\', '/', $base), '/');
        $lr = strtolower($real);
        $lb = strtolower($base);
        if ($lr === $lb || strpos($lr, $lb . '/') === 0) {
            $rel = substr($real, strlen($base));
            $url = $c[1] . $rel;
            if (!in_array($url, $out, true)) {
                $out[] = $url;
            }
        }
    }
    return $out;
}

/** 2단계 — SQLite 를 고른 경우. */
function ins_handle_step2_sqlite(&$S, $vals)
{
    $raw    = trim(ins_post('db_sqlite_path'));
    $prefix = $vals['prefix'];
    if (!extension_loaded('pdo_sqlite')) {
        ins_step2('이 서버에는 pdo_sqlite 확장이 없어 SQLite를 쓸 수 없습니다. MySQL 저장소를 고르세요.', $vals);
        return;
    }
    if ($raw === '') {
        ins_step2('DB 파일 경로를 입력하세요. 모르면 기본값(' . INS_SQLITE_DEFAULT . ')을 그대로 쓰세요.', $vals);
        return;
    }
    if (preg_match('/^[a-z0-9_]{1,32}$/', $prefix) !== 1) {
        ins_step2('테이블 prefix는 영문 소문자·숫자·밑줄 1~32자여야 합니다. (입력값은 규칙에 맞지 않습니다)', $vals);
        return;
    }
    try {
        $abs = ulogin_sqlite_path_resolve($raw, dirname(INS_DIR));
    } catch (Throwable $e) {
        ins_step2('DB 파일 경로 형식을 받을 수 없습니다. 일반 파일 경로를 적으세요(:memory: · file: · ? 불가).', $vals);
        return;
    }
    if (substr($abs, -1) === '/' || basename($abs) === '' || is_dir($abs)) {
        ins_step2('DB 파일 경로가 폴더를 가리킵니다. 파일 이름까지 적으세요(예: …/ulogin.sqlite).', $vals);
        return;
    }
    $dir = dirname($abs);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        ins_step2('DB 파일을 둘 폴더를 만들 수 없습니다. 상위 폴더의 쓰기 권한을 확인하거나 다른 경로를 적으세요.', $vals);
        return;
    }
    // WAL 은 같은 폴더에 -wal · -shm 을 만든다 — 파일만이 아니라 **폴더**가 쓰기 가능해야 한다.
    if (!is_writable($dir)) {
        ins_step2('DB 폴더에 쓸 수 없습니다. 웹 서버 계정에게 그 폴더의 쓰기 권한을 주세요.', $vals);
        return;
    }
    clearstatcache(true, $abs);
    $existed = is_file($abs) && (int) @filesize($abs) > 0;

    $db = ['driver' => 'sqlite', 'sqlite_path' => $raw, 'sqlite_abs' => $abs,
           'host' => 'localhost', 'port' => '', 'name' => '', 'user' => '', 'pass' => '', 'prefix' => $prefix];
    try {
        $pdo = ins_pdo($db);
        $ver = (string) $pdo->query('SELECT sqlite_version()')->fetchColumn();
    } catch (Throwable $e) {
        error_log('[ulogin-install] sqlite open failed: ' . $e->getMessage());
        ins_step2('DB 파일을 열 수 없습니다. ' . ins_sqlite_reason($e), $vals);
        return;
    }

    // 설계 D-2 ④ — 문서 루트 안이면 **HTTP 로 실제로 긁어 본다.** 401/403/404 가 아니면 진행하지 않는다.
    // 확인할 수 없어도(null — curl 없음, php -S 자기 호출 대기 등) 진행하지 않는다(fail-closed).
    // 「.htaccess 가 있으니 안전하다」로 끝내지 않는다 — nginx·AllowOverride None 환경이 실재한다.
    $urls = ins_dir_url_paths($dir);
    if ($urls !== array()) {
        $host = isset($_SERVER['HTTP_HOST']) ? preg_replace('/[^A-Za-z0-9\.\-:\[\]]/', '', (string) $_SERVER['HTTP_HOST']) : '';
        $bad  = null;
        foreach ($urls as $u) {
            $url = ($host === '') ? null
                 : (ins_is_https() ? 'https' : 'http') . '://' . $host . $u . '/' . rawurlencode(basename($abs));
            $res = ($url === null) ? null : ins_http_probe($url);
            if ($res === null || !in_array((int) $res['status'], [401, 403, 404], true)) {
                $bad = ($res === null) ? '확인할 수 없음' : ('HTTP ' . (int) $res['status']);
                break;
            }
        }
        if ($bad !== null) {
            $pdo = null;
            if (!$existed) {
                // 이번에 만든 빈 파일만 치운다. 원래 있던 파일은 건드리지 않는다.
                @unlink($abs);
                @unlink($abs . '-wal');
                @unlink($abs . '-shm');
            }
            error_log('[ulogin-install] sqlite path is inside the web root and the download probe was not blocked ('
                . $bad . '). refused.');
            ins_step2('이 경로는 웹 문서 루트 안에 있고, 그 파일이 웹으로 내려오지 않는다는 것을 확인하지 못했습니다(' . $bad . '). '
                . '기본값(' . INS_SQLITE_DEFAULT . ')처럼 설치 루트 바깥 경로를 쓰세요.', $vals);
            return;
        }
    }

    $S['db']         = $db;
    $S['db_version'] = $ver;
    $S['schema']     = null;
    ins_step3(null);
}

function ins_handle_step2(&$S)
{
    // v1.8.0 — 저장소 선택. 값은 정확히 두 개. 그 밖은 거부(조용히 MySQL 로 떨어지지 않는다).
    $driver = ins_post('db_driver', 'mysql');
    if ($driver === 'sqlite') {
        ins_handle_step2_sqlite($S, [
            'driver' => 'sqlite', 'sqlite_path' => trim(ins_post('db_sqlite_path')),
            'host' => trim(ins_post('db_host')), 'port' => trim(ins_post('db_port')),
            'name' => trim(ins_post('db_name')), 'user' => trim(ins_post('db_user')),
            'prefix' => trim(ins_post('db_prefix')),
        ]);
        return;
    }
    if ($driver !== 'mysql') {
        ins_step2('저장소는 SQLite 또는 MySQL 중 하나를 고르세요.', null);
        return;
    }
    if (!extension_loaded('pdo_mysql')) {
        ins_step2('이 서버에는 pdo_mysql 확장이 없어 MySQL을 쓸 수 없습니다. SQLite 저장소를 고르세요.', null);
        return;
    }
    $host   = trim(ins_post('db_host'));
    $port   = trim(ins_post('db_port'));
    $name   = trim(ins_post('db_name'));
    $user   = trim(ins_post('db_user'));
    $pass   = ins_post('db_pass');
    $prefix = trim(ins_post('db_prefix'));
    $vals   = ['driver' => 'mysql', 'host' => $host, 'port' => $port, 'name' => $name, 'user' => $user, 'prefix' => $prefix,
               'sqlite_path' => trim(ins_post('db_sqlite_path'))];

    if ($host === '' || $name === '' || $user === '') {
        ins_step2('호스트 · DB 이름 · 사용자는 필수입니다.', $vals);
        return;
    }
    // 포트는 **선택**이다(비우면 드라이버 기본값). 적었다면 1~65535 정수여야 한다.
    //   `(int)` 로 뭉개지 않는다 — `(int)'3307abc'` 는 3307 이라 쓰레기 입력이
    //   그럴듯한 포트로 둔갑한다. 틀린 포트로 조용히 접속하는 경로를 만들지 않는다.
    if ($port !== '') {
        if (preg_match('/^[0-9]{1,5}$/', $port) !== 1 || (int) $port < 1 || (int) $port > 65535) {
            ins_step2('DB 포트는 1~65535 사이의 숫자여야 합니다. (모르면 비워 두세요)', $vals);
            return;
        }
    }
    // M-12-11: prefix 는 SQL 치환 문자열로 쓰인다. 정규식 통과 값만 받는다.
    if (preg_match('/^[a-z0-9_]{1,32}$/', $prefix) !== 1) {
        ins_step2('테이블 prefix는 영문 소문자·숫자·밑줄 1~32자여야 합니다. (입력값은 규칙에 맞지 않습니다)', $vals);
        return;
    }
    if (strlen($name) > 64 || preg_match('/[`\'"\\\\;]/', $name) === 1) {
        ins_step2('DB 이름에 사용할 수 없는 문자가 있습니다.', $vals);
        return;
    }

    $db = ['driver' => 'mysql', 'host' => $host, 'port' => $port, 'name' => $name, 'user' => $user, 'pass' => $pass, 'prefix' => $prefix,
           'sqlite_path' => '', 'sqlite_abs' => ''];
    try {
        $pdo = ins_pdo($db);
        $ver = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    } catch (PDOException $e) {
        error_log('[ulogin-install] db connect failed: ' . $e->getMessage());
        ins_step2('DB에 접속할 수 없습니다. ' . ins_pdo_reason($e), $vals);
        return;
    } catch (Throwable $e) {
        error_log('[ulogin-install] db connect error: ' . $e->getMessage());
        ins_step2('DB 접속 중 오류가 발생했습니다. 입력값을 다시 확인하세요.', $vals);
        return;
    }

    $S['db']         = $db;
    $S['db_version'] = $ver;
    $S['schema']     = null;
    ins_step3(null);
}

// =====================================================================
// 3단계 — 스키마 적용
// =====================================================================

/**
 * schema.sql 의 `app_ulogin_` 을 입력 prefix 로 치환한 뒤 문장 단위로 실행한다.
 * CREATE TABLE IF NOT EXISTS 라 재실행해도 안전하다.
 * @return array [결과행 목록, 실패 수]
 */
function ins_apply_schema($db)
{
    $sql = @file_get_contents(INS_SCHEMA);
    if ($sql === false || trim((string) $sql) === '') {
        throw new RuntimeException('schema.sql read failed');
    }
    // M-12-11: 치환 대상 문자열은 코드 리터럴, 치환 값은 정규식 검증을 통과한 prefix 뿐이다.
    $sql   = str_replace('app_ulogin_', $db['prefix'], (string) $sql);
    $stmts = ins_split_sql($sql);

    $pdo    = ins_pdo($db);
    $rows   = [];
    $failed = 0;
    $isSqlite = isset($db['driver']) && $db['driver'] === 'sqlite';

    if ($isSqlite) {
        // v1.8.0 (설계 C-3) — schema.sql(MySQL) 을 **번역기 core/ddl.php 로** SQLite DDL 로 바꿔 실행한다.
        // 정본은 schema.sql 하나다. ins_split_sql() 은 주석을 지워 `-- @sqlite-collate` 태그를 잃으므로
        // SQLite 는 주석째 자른 덩어리(ddl_extract_creates)를 쓴다. **두 방식의 문장 수가 다르면 멈춘다** —
        // CREATE TABLE 이 아닌 문장이나 들여쓴 정의가 생기면 그것이 조용히 빠지는 것을 막는다.
        $blocks = ddl_extract_creates($sql);
        if ($blocks === [] || count($blocks) !== count($stmts)) {
            error_log('[ulogin-install] sqlite schema split mismatch: blocks=' . count($blocks) . ' stmts=' . count($stmts));
            $rows[] = ['table' => 'schema.sql', 'ok' => false,
                       'note' => 'schema.sql의 구조를 SQLite로 번역할 수 없습니다(문장 ' . count($stmts)
                               . '개 중 CREATE TABLE ' . count($blocks) . '개). 서버 로그를 확인하세요.'];
            return [$rows, 1];
        }
        foreach ($blocks as $block) {
            $table = ins_stmt_table($block);
            $label = $table !== null ? $table : '(이름 없음)';
            try {
                $tr = ddl_sqlite_create($block);
                $pdo->exec($tr['table']);
                foreach ($tr['indexes'] as $ix) {
                    $pdo->exec($ix);
                }
                $st = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
                $st->execute([$label]);
                $exists = ((int) $st->fetchColumn()) > 0;
                $rows[] = ['table' => $label, 'ok' => $exists,
                           'note' => $exists ? '적용 완료 (인덱스 ' . count($tr['indexes']) . '개)' : '실행은 됐지만 테이블을 찾을 수 없습니다'];
                if (!$exists) {
                    $failed++;
                }
            } catch (PDOException $e) {
                error_log('[ulogin-install] sqlite DDL failed (' . $label . '): ' . $e->getMessage());
                $rows[] = ['table' => $label, 'ok' => false, 'note' => ins_sqlite_reason($e)];
                $failed++;
            } catch (RuntimeException $e) {
                // 번역기가 모르는 구문 — fail-closed. 사유는 로그에만(스키마 구조를 화면에 풀지 않는다).
                error_log('[ulogin-install] sqlite translation refused (' . $label . '): ' . $e->getMessage());
                $rows[] = ['table' => $label, 'ok' => false,
                           'note' => 'SQLite로 번역할 수 없는 정의가 있습니다. 상세 사유는 서버 오류 로그에 기록했습니다.'];
                $failed++;
            }
        }
        $stmts = [];   // 아래 MySQL 루프를 건너뛴다
    }

    foreach ($stmts as $stmt) {
        $table = ins_stmt_table($stmt);
        $label = $table !== null ? $table : (substr(preg_replace('/\s+/', ' ', $stmt), 0, 60) . '…');
        try {
            $pdo->exec($stmt);
            $exists = true;
            if ($table !== null) {
                // information_schema 로 확인한다. `SHOW TABLES LIKE ?` 는 쓸 수 없다 —
                // MariaDB 는 SHOW 문의 서버측 프리페어를 지원하지 않아
                // ATTR_EMULATE_PREPARES=false 인 이 연결에서 1064(syntax error near '?')
                // 가 나고, DDL 이 성공했는데도 15개 전부 '실패' 로 보고된다.
                // (MariaDB 10.4 실측 확인. 공용 호스팅 대다수가 MariaDB 다)
                $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES'
                    . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
                $st->execute([$table]);
                $exists = ((int) $st->fetchColumn()) > 0;
            }
            $rows[] = ['table' => $label, 'ok' => $exists, 'note' => $exists ? '적용 완료' : '실행은 됐지만 테이블을 찾을 수 없습니다'];
            if (!$exists) {
                $failed++;
            }
        } catch (PDOException $e) {
            error_log('[ulogin-install] DDL failed (' . $label . '): ' . $e->getMessage());
            $rows[] = ['table' => $label, 'ok' => false, 'note' => ins_pdo_reason($e)];
            $failed++;
        }
    }

    // -----------------------------------------------------------------
    // v1.4.0 (D-151) — 신규 설치의 스키마 리비전을 기록한다.
    //
    // schema.sql 은 **이미 최종 형태**다. 그래서 migrations/ 의 파일을 적용하지 않고
    // status='baseline' 으로 기록만 한다. 기록하지 않으면 다음에 migrate.php 가
    // "미적용 1건" 으로 보고 이미 있는 테이블·인덱스에 ALTER 를 시도한다.
    //
    // 이 한 블록이 **신규 설치와 기존 업그레이드가 같은 지점에서 만나게** 하는 자리다.
    // 확인 방법: 설치 직후 `php server/migrate.php verify` 가 '동일' 을 내야 한다.
    // -----------------------------------------------------------------
    if ($failed === 0) {
        try {
            // migrations/lib.php 는 파일 머리에서 이미 올렸다(v1.8.0 — 저장소 계층을 같이 쓴다).
            $mtable = mig_ensure_table($pdo, INS_SCHEMA, $db['prefix']);
            $mfiles = mig_list(INS_DIR . '/migrations');
            $n      = mig_stamp_baseline($pdo, $mfiles, $mtable, 'install');
            $rows[] = ['table' => '(스키마 리비전)', 'ok' => true,
                       'note' => 'migrations/ ' . $n . '건을 baseline으로 기록했습니다. 신규 설치라 적용하지 않습니다'];
        } catch (Throwable $e) {
            error_log('[ulogin-install] migration baseline stamp failed: ' . $e->getMessage());
            $rows[] = ['table' => '(스키마 리비전)', 'ok' => false,
                       'note' => '기록에 실패했습니다. 설치 후 server/migrate.php를 열어 상태를 확인하세요.'];
            $failed++;
        }
    }

    // v1.8.0 (설계 C-4 겹 2 · G-8 6단계) — SQLite 는 **만든 직후 schema.sql 과 대조한다.**
    // `migrate.php verify` 와 같은 함수다. 번역기가 조용히 틀렸으면 여기서 설치가 멈춘다.
    // (MySQL 경로는 v1.7.0 그대로 둔다 — 그 경로를 이 PC 에서 실측할 수 없다. H-2)
    if ($failed === 0 && $isSqlite) {
        try {
            $diff = mig_schema_diff(mig_expected_schema(INS_SCHEMA, $db['prefix']), mig_live_schema($pdo, $db['prefix']));
            if ($diff === []) {
                $rows[] = ['table' => '(schema.sql 대조)', 'ok' => true, 'note' => '동일: 번역된 스키마가 schema.sql과 같은 형태입니다'];
            } else {
                foreach ($diff as $d) {
                    error_log('[ulogin-install] sqlite schema verify: ' . $d);
                }
                $rows[] = ['table' => '(schema.sql 대조)', 'ok' => false,
                           'note' => count($diff) . '건 불일치. 상세는 서버 오류 로그에 기록했습니다.'];
                $failed++;
            }
        } catch (Throwable $e) {
            error_log('[ulogin-install] sqlite schema verify failed: ' . $e->getMessage());
            $rows[] = ['table' => '(schema.sql 대조)', 'ok' => false, 'note' => '대조에 실패했습니다. 서버 로그를 확인하세요.'];
            $failed++;
        }
    }
    return [$rows, $failed];
}

function ins_step3($result)
{
    global $S;
    ins_page_start('스키마 적용', 3);
    echo '<div class="card">';
    if (isset($S['db']['driver']) && $S['db']['driver'] === 'sqlite') {
        // 절대 경로는 화면에 내지 않는다(M-12-10). 운영자가 적은 값(기본은 상대 경로)만 보인다.
        echo '<p>저장소: <b>SQLite</b> ' . ins_h(isset($S['db_version']) ? $S['db_version'] : '?')
           . ' &nbsp;|&nbsp; 파일 <code>' . ins_h($S['db']['sqlite_path']) . '</code>'
           . ' &nbsp;|&nbsp; prefix <code>' . ins_h($S['db']['prefix']) . '</code></p>';
    } else {
        echo '<p>접속한 DB: <b>' . ins_h($S['db']['name']) . '</b> &nbsp;|&nbsp; MySQL '
           . ins_h(isset($S['db_version']) ? $S['db_version'] : '?')
           . ' &nbsp;|&nbsp; prefix <code>' . ins_h($S['db']['prefix']) . '</code></p>';
    }

    if ($result === null) {
        echo '<p><code>schema.sql</code> 의 테이블 20개를 만듭니다. 모든 문장이 '
           . '<code>CREATE TABLE IF NOT EXISTS</code> 이므로 이미 있는 테이블은 건드리지 않습니다. 여러 번 실행해도 안전합니다.</p>';
        echo '<div class="msg"><b>이미 v1.3.0 이하로 설치해 쓰고 있다면 이 설치기가 아니라 '
           . '<code>server/migrate.php</code> 를 쓰세요.</b> '
           . '<code>CREATE TABLE IF NOT EXISTS</code> 는 <b>이미 있는 테이블에 새 컬럼·인덱스를 넣지 않습니다</b> — '
           . '설치기를 다시 돌려도 스키마가 갱신되지 않습니다.</div>';
        echo '<form method="post">' . ins_csrf_field()
           . '<input type="hidden" name="step" value="3"><button type="submit">스키마 적용 실행</button></form>';
    } else {
        list($rows, $failed) = $result;
        echo '<table><tr><th>테이블</th><th>결과</th><th>비고</th></tr>';
        foreach ($rows as $r) {
            echo '<tr><td><code>' . ins_h($r['table']) . '</code></td><td class="'
               . ($r['ok'] ? 'ok">성공' : 'bad">실패') . '</td><td>' . ins_h($r['note']) . '</td></tr>';
        }
        echo '</table>';
        if ($failed > 0) {
            echo '<div class="msg err">' . (int) $failed . '개 문장이 실패했습니다. '
               . 'DB 사용자에게 <code>CREATE</code> 권한이 있는지 확인하고 다시 실행하세요.</div>';
            echo '<form method="post">' . ins_csrf_field()
               . '<input type="hidden" name="step" value="3"><button type="submit">다시 실행</button></form>';
        } else {
            echo '<div class="msg good">테이블 ' . count($rows) . '개가 모두 준비되었습니다.</div>';
            echo '<form method="post">' . ins_csrf_field()
               . '<input type="hidden" name="step" value="3x"><button type="submit">다음: 사이트·전송 보안 설정</button></form>';
        }
    }
    echo '</div>';
    ins_page_end();
}

// =====================================================================
// 4단계 — 사이트 · 전송 보안 (BASE_URL / FORCE_HTTPS+COOKIE_SECURE / 메일)
// =====================================================================

function ins_step4($err = null, $vals = null)
{
    global $S;
    if ($vals === null) {
        $vals = isset($S['site']) ? $S['site'] : [
            'base_url'       => ins_guess_base_url(),
            'site_name'      => 'ulogin',
            'mail_driver'    => 'log',
            'mail_from'      => '',
            'mail_from_name' => 'ulogin',
            'force_https'    => ins_is_https() ? '1' : '',
            // v1.10.1 — 기본값 closed(설계 130 §1-1 · D-371 Q-1). 켜면 open 으로 시드한다.
            'allow_register' => '',
            'require_verify' => '',
            'signup_bonus'   => '100',
        ];
    }
    $guess = ins_guess_base_url();

    ins_page_start('사이트·전송 보안', 4);
    echo '<div class="card">';
    if ($err !== null) {
        echo '<div class="msg err">' . ins_h($err) . '</div>';
    }

    echo '<h2>공개 주소 (BASE_URL)</h2>';
    echo '<div class="msg big"><b>이 값이 비면 비밀번호 재설정·이메일 인증 메일이 아예 발송되지 않습니다.</b><br>'
       . '재설정·인증 링크는 이 주소로 만들어집니다. 서버는 이 값이 비어 있을 때 '
       . '요청의 <code>Host</code> 헤더로 <b>폴백하지 않고 발송 자체를 중단합니다</b>(fail-closed). '
       . 'Host 는 <b>요청을 보낸 사람이 정하는 값</b>이라, 그것으로 링크를 만들면 공격자가 '
       . '자기 도메인을 Host로 넣어 재설정을 요청해 토큰을 그대로 받아갈 수 있기 때문입니다. '
       . '즉 이 값을 잘못 넣으면 계정 복구가 조용히 불가능해집니다. (보안 감사 H-4 / 리더 판정 D-33)</div>';
    if ($guess !== '') {
        echo '<p class="hint">현재 접속 정보로 추론한 값은 <code>' . ins_h($guess) . '</code> 입니다. '
           . '<b>이 값은 Host 헤더에서 왔으므로 반드시 눈으로 확인</b>하고, 실제 서비스 도메인이 다르면 고쳐 주세요.</p>';
    }
    echo '<form method="post">' . ins_csrf_field() . '<input type="hidden" name="step" value="4">';
    echo '<label>BASE_URL (필수)<input type="text" name="base_url" value="' . ins_h($vals['base_url'])
       . '" required autocomplete="off" placeholder="https://example.com/ulogin">'
       . '<span class="hint">끝에 <code>/</code> 를 붙이지 않습니다. 이 주소 아래에 <code>/server/</code> 와 <code>/public/</code> 가 있어야 합니다. '
       . '검증용: 로그인 화면은 <code>{BASE_URL}/public/login.html</code> 로 열립니다.</span></label>';

    echo '<h2>전송 보안 (HTTPS)</h2>';
    if (!ins_is_https()) {
        echo '<div class="msg big"><b>지금 이 설치 화면은 평문 HTTP로 열려 있습니다.</b><br>'
           . 'HTTPS 없이 운영하면 <b>토큰과 AI API 키가 평문으로 전송됩니다.</b> '
           . '같은 네트워크의 누구든 로그인 세션과 AI API 키를 그대로 볼 수 있고, '
           . '이 시스템의 존재 이유(키를 클라이언트에 노출하지 않는 것)가 성립하지 않습니다. '
           . '가능하면 설치를 멈추고 호스팅에서 HTTPS(무료 Let\'s Encrypt 등)를 먼저 켜세요.</div>';
    }
    echo '<p class="hint"><code>FORCE_HTTPS</code> 와 쿠키 <code>Secure</code> 속성은 <b>함께</b> 결정합니다. '
       . '따로 두면 한쪽만 바뀌어 세션 쿠키가 평문으로 전송됩니다. (보안 감사 M-3)</p>';
    $on  = $vals['force_https'] === '1' ? ' checked' : '';
    $off = $vals['force_https'] === '1' ? '' : ' checked';
    echo '<label class="radio"><input type="radio" name="force_https" value="1"' . $on . '>'
       . '<b>HTTPS 강제 (권장)</b><br><span class="hint">평문 HTTP 요청을 https로 301 리다이렉트하고, '
       . '세션 쿠키에 <code>Secure</code> 를 붙입니다. > <code>FORCE_HTTPS=true</code>, <code>COOKIE_SECURE=true</code></span></label>';
    echo '<label class="radio"><input type="radio" name="force_https" value="0"' . $off . '>'
       . '<b>HTTP 허용 (비권장)</b><br><span class="hint">HTTPS를 지원하지 않는 호스팅에서만 선택하세요. '
       . '토큰과 AI API 키가 평문으로 흐릅니다. > <code>FORCE_HTTPS=false</code>, <code>COOKIE_SECURE=false</code></span></label>';

    echo '<h2>사이트 · 메일</h2>';
    echo '<label>사이트 표시명<input type="text" name="site_name" value="' . ins_h($vals['site_name'])
       . '" required maxlength="60"><span class="hint">메일 제목과 화면에 쓰입니다.</span></label>';
    echo '<label>보내는 메일 주소 (MAIL_FROM)<input type="text" name="mail_from" value="' . ins_h($vals['mail_from'])
       . '" required placeholder="no-reply@example.com"><span class="hint">실제 도메인 주소를 쓰세요. 다른 도메인 주소는 스팸 처리됩니다.</span></label>';
    echo '<label>보내는 이름<input type="text" name="mail_from_name" value="' . ins_h($vals['mail_from_name'])
       . '" required maxlength="60"></label>';
    $logSel  = $vals['mail_driver'] === 'mail' ? '' : ' selected';
    $mailSel = $vals['mail_driver'] === 'mail' ? ' selected' : '';
    echo '<label>메일 발송 방식<select name="mail_driver">'
       . '<option value="log"' . $logSel . '>log: 발송하지 않고 server/storage/mail/ 에 파일로 저장 (기본값)</option>'
       . '<option value="mail"' . $mailSel . '>mail: PHP mail()로 실제 발송</option>'
       . '</select><span class="hint">v1.0.0 기본값은 <code>log</code> 입니다(리더 판정 D-1). '
       . '호스팅의 <code>mail()</code> 동작을 확인한 뒤 관리자 콘솔에서 <code>mail</code> 로 바꾸는 것을 권합니다. '
       . '<b>log를 쓰면 재설정 링크와 임시 비밀번호가 서버 파일에 평문으로 쌓입니다</b> — 마지막 단계의 경고를 읽어 주세요.</span></label>';

    echo '<h2>가입 정책</h2>';
    $arOn  = $vals['allow_register'] === '1' ? ' checked' : '';
    $rvOn  = $vals['require_verify'] === '1' ? ' checked' : '';
    echo '<label class="radio"><input type="checkbox" name="allow_register" value="1"' . $arOn . '>'
       . '신규 회원가입 허용 (<code>ALLOW_REGISTER</code>)</label>';
    echo '<label class="radio"><input type="checkbox" name="require_verify" value="1"' . $rvOn . '>'
       . '가입 시 이메일 인증 필수 (<code>REQUIRE_EMAIL_VERIFY</code>)<br>'
       . '<span class="hint">켜면 인증 전에는 로그인이 차단됩니다. <b>메일 발송이 확실히 동작할 때만</b> 켜세요. '
       . 'log 드라이버로도 <code>server/storage/mail/</code> 파일에서 링크를 꺼내 인증할 수는 있습니다.</span></label>';
    echo '<label>가입 보너스 포인트<input type="text" name="signup_bonus" value="' . ins_h($vals['signup_bonus'])
       . '" required><span class="hint">0 ~ 1000000 사이의 정수.</span></label>';

    echo '<button type="submit">다음: 관리자 계정 만들기</button></form></div>';
    ins_page_end();
}

function ins_handle_step4(&$S)
{
    $vals = [
        'base_url'       => trim(ins_post('base_url')),
        'site_name'      => trim(ins_post('site_name')),
        'mail_driver'    => ins_post('mail_driver') === 'mail' ? 'mail' : 'log',
        'mail_from'      => trim(ins_post('mail_from')),
        'mail_from_name' => trim(ins_post('mail_from_name')),
        'force_https'    => ins_post('force_https') === '1' ? '1' : '',
        'allow_register' => ins_post('allow_register') === '1' ? '1' : '',
        'require_verify' => ins_post('require_verify') === '1' ? '1' : '',
        'signup_bonus'   => trim(ins_post('signup_bonus')),
    ];

    // BASE_URL 필수 (M-12-5 / H-4)
    $base = rtrim($vals['base_url'], '/');
    if ($base === '') {
        ins_step4('BASE_URL은 필수입니다. 비워 둘 수 없습니다.', $vals);
        return;
    }
    $p = parse_url($base);
    if ($p === false || empty($p['scheme']) || empty($p['host'])
        || !in_array(strtolower((string) $p['scheme']), ['http', 'https'], true)) {
        ins_step4('BASE_URL은 http:// 또는 https:// 로 시작하는 절대 주소여야 합니다. (예: https://example.com/ulogin)', $vals);
        return;
    }
    if (isset($p['query']) || isset($p['fragment'])) {
        ins_step4('BASE_URL 에는 쿼리스트링(?)이나 프래그먼트(#)를 넣을 수 없습니다.', $vals);
        return;
    }
    if (mb_strlen($base, 'UTF-8') > 255) {
        ins_step4('BASE_URL이 너무 깁니다. (255자 이내)', $vals);
        return;
    }
    $vals['base_url'] = $base;

    if ($vals['force_https'] === '1' && strtolower((string) $p['scheme']) !== 'https') {
        ins_step4('HTTPS 강제를 선택했으므로 BASE_URL도 https:// 로 시작해야 합니다. '
                . '(HTTP로 운영해야 한다면 아래에서 "HTTP 허용"을 선택하세요)', $vals);
        return;
    }
    if ($vals['site_name'] === '' || mb_strlen($vals['site_name'], 'UTF-8') > 60) {
        ins_step4('사이트 표시명을 1~60자로 입력하세요.', $vals);
        return;
    }
    if (filter_var($vals['mail_from'], FILTER_VALIDATE_EMAIL) === false) {
        ins_step4('보내는 메일 주소 형식이 올바르지 않습니다.', $vals);
        return;
    }
    if ($vals['mail_from_name'] === '' || mb_strlen($vals['mail_from_name'], 'UTF-8') > 60) {
        ins_step4('보내는 이름을 1~60자로 입력하세요.', $vals);
        return;
    }
    if ($vals['mail_driver'] === 'mail' && !function_exists('mail')) {
        ins_step4('이 서버에는 mail() 함수가 없습니다. log 드라이버를 선택하세요.', $vals);
        return;
    }
    if (preg_match('/^\d{1,7}$/', $vals['signup_bonus']) !== 1 || (int) $vals['signup_bonus'] > 1000000) {
        ins_step4('가입 보너스 포인트는 0 ~ 1000000 사이의 정수여야 합니다.', $vals);
        return;
    }

    $S['site'] = $vals;
    ins_step5(null, null);
}

// =====================================================================
// 5단계 — 관리자 계정
// =====================================================================

function ins_step5($err = null, $vals = null)
{
    if ($vals === null) {
        $vals = ['username' => '', 'email' => '', 'name' => '관리자'];
    }
    ins_page_start('관리자 계정', 5);
    echo '<div class="card">';
    if ($err !== null) {
        echo '<div class="msg err">' . ins_h($err) . '</div>';
    }
    echo '<p>최고관리자(<code>superadmin</code>) 계정을 만듭니다. 이 계정만이 시크릿 평문 조회와 '
       . '탈퇴 계정 복구를 할 수 있습니다.</p>';
    echo '<form method="post">' . ins_csrf_field() . '<input type="hidden" name="step" value="5">';
    echo '<label>관리자 아이디<input type="text" name="admin_username" value="' . ins_h($vals['username'])
       . '" required autocomplete="off" pattern="[A-Za-z0-9_]{4,20}">'
       . '<span class="hint">영문·숫자·밑줄 4~20자. <b>대문자를 넣어도 소문자로 저장됩니다</b> — '
       . '서버가 아이디를 항상 소문자로 정규화해 조회하기 때문입니다. 로그인도 소문자로 하면 됩니다.</span></label>';
    echo '<label>관리자 이메일<input type="text" name="admin_email" value="' . ins_h($vals['email'])
       . '" required autocomplete="off" placeholder="admin@example.com">'
       . '<span class="hint">비밀번호를 잊었을 때 재설정 링크를 받는 주소입니다. 이메일 인증은 완료 상태로 생성됩니다.</span></label>';
    echo '<label>이름<input type="text" name="admin_name" value="' . ins_h($vals['name'])
       . '" maxlength="80"><span class="hint">선택 항목. 관리자 콘솔에 표시됩니다.</span></label>';
    echo '<label>비밀번호<input type="password" name="admin_pw" required autocomplete="new-password">'
       . '<span class="hint">10자 이상 + 영문·숫자·특수문자 중 2종 이상. 흔히 쓰는 비밀번호·반복·키보드 순서와 아이디를 넣은 비밀번호는 쓸 수 없습니다.</span></label>';
    echo '<label>비밀번호 확인<input type="password" name="admin_pw2" required autocomplete="new-password"></label>';
    echo '<button type="submit">다음: 첫 서비스 등록</button></form></div>';
    ins_page_end();
}

function ins_handle_step5(&$S)
{
    $rawUser = ins_post('admin_username');
    // core/auth.php normalize_username() 과 동일하게 소문자화한다.
    // 대문자로 저장하면 로그인·탈퇴 경로가 정규화된 소문자로 조회하므로
    // 그 계정은 영구히 탈퇴 불가가 된다.
    $username = ins_normalize_username($rawUser);
    $email    = mb_strtolower(trim(ins_post('admin_email')), 'UTF-8');
    $name     = trim(ins_post('admin_name'));
    $pw       = ins_post('admin_pw');
    $pw2      = ins_post('admin_pw2');
    $vals     = ['username' => $rawUser, 'email' => $email, 'name' => $name];

    if (!ins_validate_username_format($username)) {
        ins_step5('아이디는 영문 소문자·숫자·밑줄 4~20자여야 합니다. (대문자는 자동으로 소문자로 바뀝니다)', $vals);
        return;
    }
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email, 'UTF-8') > 190) {
        ins_step5('이메일 형식이 올바르지 않습니다.', $vals);
        return;
    }
    if (mb_strlen($name, 'UTF-8') > 80) {
        ins_step5('이름은 80자 이내여야 합니다.', $vals);
        return;
    }
    if ($pw !== $pw2) {
        ins_step5('비밀번호와 확인 값이 일치하지 않습니다.', $vals);
        return;
    }
    $pwErr = ins_validate_password($pw, $username);
    if ($pwErr === null) {
        // v1.10.1 L-2 — 이 계정은 superadmin 이다. core/auth.php validate_password() 가 rank 20 이상에 거는
        // 강화 규칙(pw_strong_problem)을 같은 함수로 건다. 일반 회원 규칙(위 ins_validate_password)은 그대로.
        $pwErr = pw_strong_problem((string) $pw, $username);
    }
    if ($pwErr !== null) {
        ins_step5($pwErr, $vals);
        return;
    }

    // 이미 같은 아이디/이메일이 있는지 미리 확인해 마지막 단계에서 실패하지 않게 한다.
    try {
        $pdo = ins_pdo($S['db']);
        $t   = $S['db']['prefix'] . 'users';
        $st  = $pdo->prepare('SELECT username, email FROM `' . $t . '` WHERE username = ? OR email = ? LIMIT 1');
        $st->execute([$username, $email]);
        $dup = $st->fetch();
        if ($dup !== false) {
            $which = ((string) $dup['username'] === $username) ? '아이디' : '이메일';
            ins_step5('이미 같은 ' . $which . '의 회원이 DB에 있습니다. 다른 값을 쓰거나, '
                    . '기존 계정을 직접 최고관리자로 승격하세요.', $vals);
            return;
        }
    } catch (PDOException $e) {
        error_log('[ulogin-install] admin dup check failed: ' . $e->getMessage());
        ins_step5('회원 테이블을 조회할 수 없습니다. ' . ins_pdo_reason($e), $vals);
        return;
    }

    $S['admin'] = [
        'username' => $username,
        'email'    => $email,
        'name'     => $name === '' ? null : $name,
        // 해시로 바로 바꿔 세션에 평문 비밀번호를 남기지 않는다.
        'pw_hash'  => password_hash($pw, PASSWORD_DEFAULT),
    ];
    ins_step6(null, null);
}

// =====================================================================
// 6단계 — 첫 서비스 등록
// =====================================================================

function ins_step6($err = null, $vals = null)
{
    global $S;
    if ($vals === null) {
        $base = isset($S['site']['base_url']) ? $S['site']['base_url'] : '';
        $vals = [
            'service_id'   => 'local-ai-app',
            'service_name' => '로컬 AI 앱',
            'redirects'    => "http://localhost:5173/callback.html\nhttp://127.0.0.1:5173/callback.html"
                            . ($base !== '' ? "\n" . $base . '/examples/callback.html' : ''),
            'origins'      => "http://localhost:5173\nhttp://127.0.0.1:5173",
        ];
    }
    ins_page_start('첫 서비스 등록', 6);
    echo '<div class="card">';
    if ($err !== null) {
        echo '<div class="msg err">' . ins_h($err) . '</div>';
    }
    echo '<p>로그인을 붙일 앱을 <b>서비스</b>로 등록합니다. 로컬 앱은 여기 등록된 '
       . '<code>service_id</code> 로 로그인하고, 등록되지 않은 복귀 주소·출처는 전부 차단됩니다.</p>';
    echo '<form method="post">' . ins_csrf_field() . '<input type="hidden" name="step" value="6">';

    echo '<label>service_id<input type="text" name="service_id" value="' . ins_h($vals['service_id'])
       . '" required autocomplete="off"><span class="hint">영문 소문자·숫자로 시작하고 소문자·숫자·<code>-</code>·<code>_</code> 3~64자. '
       . '앱 코드에 그대로 넣는 값입니다. 나중에 바꾸기 어렵습니다.</span></label>';
    echo '<label>서비스 이름<input type="text" name="service_name" value="' . ins_h($vals['service_name'])
       . '" required maxlength="100"><span class="hint">관리자 콘솔과 로그인 화면에 표시됩니다.</span></label>';

    echo '<label>복귀 주소 화이트리스트 (redirect_whitelist): 한 줄에 하나<textarea name="redirects" required>'
       . ins_h($vals['redirects']) . '</textarea>'
       . '<span class="hint">SSO 로그인 후 돌아갈 주소입니다. <b>완전 일치</b>로만 비교하므로 '
       . '실제 콜백 파일 경로까지 정확히 적어야 합니다. 쿼리스트링(<code>?</code>)은 넣을 수 없습니다.</span></label>';

    echo '<label>허용 출처 (allowed_origins): 한 줄에 하나<textarea name="origins" required>'
       . ins_h($vals['origins']) . '</textarea>'
       . '<span class="hint"><b>반드시 입력하세요.</b> 여기 없는 출처에서 온 브라우저 요청은 CORS로 전부 막혀 '
       . '로컬 앱이 아무 API도 호출하지 못합니다. 형식은 <code>scheme://host[:port]</code> — '
       . '경로를 붙이면 안 됩니다. <code>file://</code> 로 열린 페이지는 출처가 <code>null</code> 이므로 '
       . '한 줄에 <code>null</code> 만 적어 허용할 수 있지만, 로컬 웹서버'
       . '(<code>python -m http.server 5173</code>)를 쓰는 편이 안전합니다.</span></label>';

    echo '<button type="submit">다음: 시크릿 등록</button></form></div>';
    ins_page_end();
}

function ins_handle_step6(&$S)
{
    $vals = [
        'service_id'   => trim(ins_post('service_id')),
        'service_name' => trim(ins_post('service_name')),
        'redirects'    => ins_post('redirects'),
        'origins'      => ins_post('origins'),
    ];

    if (preg_match('/^[a-z0-9][a-z0-9\-_]{2,63}$/', $vals['service_id']) !== 1) {
        ins_step6('service_id는 영문 소문자·숫자로 시작하고 소문자·숫자·-·_ 3~64자여야 합니다.', $vals);
        return;
    }
    if ($vals['service_name'] === '' || mb_strlen($vals['service_name'], 'UTF-8') > 100) {
        ins_step6('서비스 이름을 1~100자로 입력하세요.', $vals);
        return;
    }

    list($redirects, $rErr) = ins_url_list(ins_lines($vals['redirects']), 'redirect');
    if ($rErr !== null) {
        ins_step6('복귀 주소: ' . $rErr, $vals);
        return;
    }
    if ($redirects === []) {
        ins_step6('복귀 주소를 최소 1개 입력하세요. 비어 있으면 SSO 로그인이 항상 실패합니다.', $vals);
        return;
    }
    list($origins, $oErr) = ins_url_list(ins_lines($vals['origins']), 'origin');
    if ($oErr !== null) {
        ins_step6('허용 출처: ' . $oErr, $vals);
        return;
    }
    if ($origins === []) {
        ins_step6('허용 출처를 최소 1개 입력하세요. 비어 있으면 로컬 앱의 모든 요청이 CORS로 차단됩니다.', $vals);
        return;
    }

    try {
        $pdo = ins_pdo($S['db']);
        $st  = $pdo->prepare('SELECT id FROM `' . $S['db']['prefix'] . 'services` WHERE service_id = ? LIMIT 1');
        $st->execute([$vals['service_id']]);
        if ($st->fetchColumn() !== false) {
            ins_step6('같은 service_id의 서비스가 이미 있습니다. 다른 값을 쓰세요.', $vals);
            return;
        }
    } catch (PDOException $e) {
        error_log('[ulogin-install] service dup check failed: ' . $e->getMessage());
        ins_step6('서비스 테이블을 조회할 수 없습니다. ' . ins_pdo_reason($e), $vals);
        return;
    }

    $S['service'] = [
        'service_id'   => $vals['service_id'],
        'service_name' => $vals['service_name'],
        'redirects'    => $redirects,
        'origins'      => $origins,
    ];
    $S['service_form'] = $vals;
    ins_step7(null);
}

// =====================================================================
// 7단계 — 시크릿 등록 (선택) + 등급 게이트  ← 리더 판정 D-47 / 보안 감사 C-3
//
//   시크릿은 `min_account_level` / `min_billing_tier` / `min_role` 이 **하나라도**
//   설정돼 있어야 등급 게이트가 동작한다. 세 값이 전부 NULL 이면
//   core/payload.php 의 build_payload() 는 검사를 건너뛰고 **가입한 전원**에게
//   그 값을 내려준다(그것이 정상 동작이다 — api_base·model 같은 공개 설정값을
//   내려줄 방법이 필요하기 때문). 따라서 게이트는 **등록 시점에** 정해야 하고,
//   이 화면이 그 유일한 통제 지점이다.
// =====================================================================

/**
 * 민감 키 판정 (D-47). 이 이름 규칙에 맞으면
 *   (1) 기본 게이트를 min_billing_tier='lite' 로 미리 선택하고
 *   (2) 게이트를 전부 비우려면 명시적 확인 체크박스를 요구한다.
 *
 * 규칙(v1.4.0 개정 — D-154(4)): 이름을 `_` `-` `.` 로 나눈 **낱말** 중 하나라도
 *       key / keys / apikey / apikeys / secret(s) / token(s) / password(s) / passwd /
 *       credential(s) / sk 이면 민감하다. 낱말 경계로 판정하므로 위치를 가리지 않는다.
 *
 *   민감  : ai_api_key, openai_key, claude_key, anthropic_key, private_key,
 *           ai_api_key_prod, api_key_v2, sk_live, x-api-key, secret_key,
 *           x_secret, refresh_token, db_password, aws_credentials, apikey
 *   비민감: api_base, model, endpoint, org_id, openai_org, keyring, monkey, turkey
 *
 * **왜 바꿨는가.** 종전 규칙은 `/(?:^|[_\-])(api_?key|secret|…)s?$/` 였다.
 * 대안 목록에 맨 `key` 가 없고(`api_?key` 는 리터럴 `api` 를 요구한다) 끝이 `$` 로
 * 고정돼 있어서, 이 시스템의 **대표 사용 사례에서 가장 자연스러운 이름들**이
 * 전부 빠져나갔다 — `openai_key` `claude_key` `private_key` `sk_live` `api_key_v2`,
 * 그리고 글자 그대로의 `secret_key` 까지. 게이트 없이 등록되면 그 서비스에 가입한
 * **모든** 회원의 payload 에 실린다.
 *
 * **`api_key_hint` 는 이제 민감으로 잡힌다.** 종전 독스트링은 이것을 비민감 예시로
 * 들었지만, `api_key_hint` 와 `api_key_v2` 는 이름 모양이 같아 규칙으로 가를 수 없다.
 * 둘 중 하나를 틀리게 판정해야 한다면 **닫는 쪽으로 틀리는 것이 맞다**(fail-closed) —
 * 비민감 값을 민감으로 보면 운영자가 확인 한 번을 더 하지만, 반대는 키가 샌다.
 *
 * 이름 기반 판정이므로 완전하지 않다 — 관리자 콘솔에서 다른 이름의 민감 값을
 * 추가할 때는 사람이 판단해야 한다. 그래서 완료 화면이 "게이트 없는 시크릿"
 * 목록을 따로 보여 준다.
 *
 * **정규식은 api/admin/secrets.php 의 admin_secret_key_is_sensitive() 와
 * 글자 단위로 같아야 한다.** 한쪽만 고치면 한쪽 경로만 막히는 상태가 된다.
 * (공용 파일로 뺄 수 없는 이유: install.php 는 core/ 를 로드하지 않는다)
 */
function ins_is_sensitive_key($key)
{
    $k = strtolower(trim((string) $key));
    return preg_match('/(?:^|[_\-.])(?:apikeys?|keys?|secrets?|tokens?|passwords?|passwd|credentials?|sk)(?:$|[_\-.])/', $k) === 1;
}

/**
 * 게이트 축별 선택 가능 값. **core/perm.php 의 RANK 맵과 같은 집합이어야 한다.**
 *   ACCOUNT_LEVEL_RANK = guest 0 / basic 10 / verified 20 / vip 30 / staff 90
 *   BILLING_TIER_RANK  = free 0  / lite 10  / pro 20      / enterprise 30
 *   ROLE_RANK          = user 0  / manager 10 / admin 20   / superadmin 30
 * api/admin/secrets.php 도 `array_keys(*_RANK)` 를 화이트리스트로 쓰므로,
 * 여기서 어긋나면 관리자 콘솔에서 다시 저장할 때 값이 거부된다.
 * 순서는 랭크 오름차순으로 둔다(화면 선택 순서 = 강도 순서).
 */
function ins_gate_options($axis)
{
    if ($axis === 'level') {
        return ['guest', 'basic', 'verified', 'vip', 'staff'];
    }
    if ($axis === 'tier') {
        return ['free', 'lite', 'pro', 'enterprise'];
    }
    return ['user', 'manager', 'admin', 'superadmin'];
}

/**
 * 축별 랭크 맵. **core/perm.php 의 *_RANK 와 값까지 같아야 한다.**
 * (공용 파일로 뺄 수 없는 이유: install.php 는 core/ 를 로드하지 않는다 —
 *  설치 전에는 config.php 가 없어 bootstrap.php 가 500 으로 죽는다.)
 */
function ins_gate_ranks($axis)
{
    if ($axis === 'level') {
        return ['guest' => 0, 'basic' => 10, 'verified' => 20, 'vip' => 30, 'staff' => 90];
    }
    if ($axis === 'tier') {
        return ['free' => 0, 'lite' => 10, 'pro' => 20, 'enterprise' => 30];
    }
    return ['user' => 0, 'manager' => 10, 'admin' => 20, 'superadmin' => 30];
}

/**
 * 축별 **모집단 기본값**. 이 값 이하로 건 게이트는 아무도 걸러내지 못한다.
 *
 * **`core/secretguard.php` 의 `SECRET_GATE_FLOOR` 와 같은 값이어야 한다.**
 * 종전에는 이 함수가 `guest`/`free`/`user`(랭크 0)만 돌려주어 `basic` 을 실효 게이트로
 * 셌다. 그런데 `basic` 은 `schema.sql` 의 users.account_level 기본값이자
 * `api/auth/register.php` 가 신규 가입자에게 박는 값이라 **전원이 이미 그 값을 갖고 있다** —
 * 걸어도 통과 조건이 "제한 없음"과 같다(v1.6.0 보안 감사 H-1 / 리더 판정 D-201).
 */
function ins_gate_axis_floor($axis)
{
    if ($axis === 'level') {
        return 'basic';
    }
    if ($axis === 'tier') {
        return 'free';
    }
    return 'user';
}

/**
 * 세 축 중 **실효 있는** 게이트가 하나라도 있는가. 없으면 가입한 전원이 이 값을 받는다.
 *
 * 문자열 같음 비교가 아니라 **랭크 비교**다 — `guest`(0) 는 floor `basic`(10) 보다 낮아
 * 문자열 비교로는 가려지지 않는다. 목록 밖의 값은 랭크 0 이 되어 게이트가 아니다(fail-closed).
 */
function ins_gate_effective($g)
{
    foreach (['level', 'tier', 'role'] as $axis) {
        $v = isset($g[$axis]) ? (string) $g[$axis] : '';
        if ($v === '') {
            continue;
        }
        $map   = ins_gate_ranks($axis);
        $rank  = isset($map[$v]) ? (int) $map[$v] : 0;
        $floor = ins_gate_axis_floor($axis);
        if ($rank > (int) $map[$floor]) {
            return true;
        }
    }
    return false;
}

/** 게이트를 사람이 읽는 한 줄로. 완료 화면·요약에 쓴다. */
function ins_gate_label($g)
{
    $out = [];
    if (!empty($g['level'])) {
        $out[] = 'account_level ≥ ' . $g['level'];
    }
    if (!empty($g['tier'])) {
        $out[] = 'billing_tier ≥ ' . $g['tier'];
    }
    if (!empty($g['role'])) {
        $out[] = 'role ≥ ' . $g['role'];
    }
    if ($out === []) {
        return '제한 없음 (가입한 전원)';
    }
    $s = implode(' / ', $out);
    return ins_gate_effective($g) ? $s : $s . ' (전 회원의 기본값 이하여서 전원 통과)';
}

/** 7단계에서 등록할 시크릿의 고정 명세. 폼과 저장 로직이 이 하나를 함께 본다. */
function ins_secret_fields()
{
    return [
        [
            'key'         => 'ai_api_key',
            'input'       => 'password',
            'value_type'  => 'string',
            'maxlen'      => 2000,
            'placeholder' => 'sk-...',
            'description' => 'AI 제공자 API 키',
            'hint'        => 'AI 제공자의 API 키. 이 값을 서버에 두는 것이 이 시스템의 존재 이유입니다.',
        ],
        [
            'key'         => 'api_base',
            'input'       => 'text',
            'value_type'  => 'url',
            'maxlen'      => 500,
            'placeholder' => 'https://api.openai.com/v1',
            'description' => 'AI API 기준 주소',
            'hint'        => 'API 엔드포인트 기준 주소. http/https 절대 URL. 비밀이 아닌 설정값입니다.',
        ],
        [
            'key'         => 'model',
            'input'       => 'text',
            'value_type'  => 'string',
            'maxlen'      => 200,
            'placeholder' => 'gpt-4o-mini',
            'description' => '기본 모델 이름',
            'hint'        => '기본 모델 이름. 비밀이 아닌 설정값입니다.',
        ],
    ];
}

/**
 * 기본 게이트 (D-47). 민감 키는 min_billing_tier='lite' 를 미리 선택한다.
 * 비민감 설정값은 게이트 없이 둔다 — 전부 잠그면 데모가 돌지 않아
 * 사용자가 게이트를 통째로 풀어 버리고, 그게 더 위험하다.
 */
function ins_secret_default_gate($key)
{
    if (ins_is_sensitive_key($key)) {
        return ['level' => '', 'tier' => 'lite', 'role' => ''];
    }
    return ['level' => '', 'tier' => '', 'role' => ''];
}

/** 게이트 <select> 하나. $axis = level|tier|role */
function ins_gate_select($name, $axis, $cur)
{
    $labels = [
        'guest'      => '비로그인/게스트(전원 통과, 게이트 효과 없음)',
        // basic 은 랭크 10 이지만 users.account_level 의 기본값이자 register.php 가
        // 신규 가입자에게 박는 값이다 — 걸어도 전원이 통과한다(H-1 / D-201).
        'basic'      => '가입 직후 기본 등급(전원 통과, 게이트 효과 없음)',
        'verified'   => '이메일 인증 완료 회원(기본 설치본에서는 아무도 해당되지 않습니다)',
        'vip'        => 'VIP 회원',
        'staff'      => '운영자 등급',
        'free'       => '무료(전원 통과, 게이트 효과 없음)',
        'lite'       => '유료 최저 등급 (권장)',
        'pro'        => '유료 상위 등급',
        'enterprise' => '최상위 결제 등급',
        'user'       => '일반 회원(전원 통과, 게이트 효과 없음)',
        'manager'    => '매니저 이상',
        'admin'      => '관리자 이상',
        'superadmin' => '최고관리자만',
    ];
    $h = '<select name="' . ins_h($name) . '">';
    $h .= '<option value=""' . ($cur === '' ? ' selected' : '') . '>제한 없음</option>';
    foreach (ins_gate_options($axis) as $o) {
        $lb = isset($labels[$o]) ? $labels[$o] : $o;
        $h .= '<option value="' . ins_h($o) . '"' . ($cur === $o ? ' selected' : '') . '>'
            . ins_h($o . ' 이상: ' . $lb) . '</option>';
    }
    return $h . '</select>';
}

/** 7단계 폼 상태의 빈 틀. 오류 재렌더에서 입력값·선택을 잃지 않게 한다. */
function ins_step7_blank_vals()
{
    $v = ['values' => [], 'gates' => [], 'ack' => [], 'kept' => []];
    foreach (ins_secret_fields() as $f) {
        $k = $f['key'];
        $v['values'][$k] = '';
        $v['gates'][$k]  = ins_secret_default_gate($k);
        $v['ack'][$k]    = false;
        $v['kept'][$k]   = false;
    }
    return $v;
}

function ins_step7($err = null, $vals = null)
{
    global $S;
    if ($vals === null) {
        $vals = ins_step7_blank_vals();
    }
    ins_page_start('시크릿 등록', 7);
    echo '<div class="card">';
    if ($err !== null) {
        echo '<div class="msg err">' . ins_h($err) . '</div>';
    }
    echo '<p>서비스 <code>' . ins_h($S['service']['service_id']) . '</code> 가 로그인한 사용자에게 내려줄 값입니다. '
       . 'AES-256-CBC로 암호화해 저장하고, 인증된 사용자에게만 <code>payload</code> 로 전달합니다.</p>';

    echo '<div class="msg big"><b>값마다 "받을 수 있는 최소 등급"을 반드시 정하세요.</b><br>'
       . '<b>게이트를 비우면 가입한 누구나 이 값을 받습니다.</b> 이 서버는 '
       . '<code>ALLOW_REGISTER</code> 가 켜져 있으면 누구나 가입할 수 있고, 이 서비스는 '
       . '로그인 시 자동 가입(<code>auto_join</code>)이므로 <b>가입 폼을 여는 것이 곧 이 값을 받는 절차</b>가 됩니다. '
       . '아래 <code>ai_api_key</code> 의 기본값은 <code>billing_tier ≥ lite</code>(유료 회원 이상)입니다. '
       . '(보안 감사 C-3 / 리더 판정 D-47)</div>';

    echo '<p class="hint">지금 건너뛰고 나중에 관리자 콘솔(<code>public/admin.html</code> > 서비스 > 시크릿)에서 등록해도 됩니다. '
       . '입력한 값은 <b>화면에 다시 표시하지 않습니다.</b> '
       . '값을 비워 두면 그 항목은 등록하지 않습니다.</p>';

    echo '<form method="post">' . ins_csrf_field() . '<input type="hidden" name="step" value="7">';

    foreach (ins_secret_fields() as $f) {
        $k         = $f['key'];
        $sensitive = ins_is_sensitive_key($k);
        $g         = isset($vals['gates'][$k]) ? $vals['gates'][$k] : ins_secret_default_gate($k);
        $kept      = !empty($vals['kept'][$k]);
        // 비밀 입력은 value 로 되그리지 않는다(DOM·스크린샷 유출). 대신 세션에 둔 값을
        // 유지할지 여부만 hidden 으로 왕복시킨다.
        $shownVal  = $f['input'] === 'password' ? '' : (isset($vals['values'][$k]) ? $vals['values'][$k] : '');

        echo '<h2>' . ins_h($k) . ($sensitive ? ' <span class="bad">민감 값</span>' : ' <span class="hint">설정값</span>') . '</h2>';
        echo '<label>값<input type="' . ins_h($f['input']) . '" name="sv_' . ins_h($k) . '" value="' . ins_h($shownVal)
           . '" autocomplete="off" placeholder="' . ins_h($f['placeholder']) . '">'
           . '<span class="hint">' . ins_h($f['hint']) . '</span></label>';
        if ($kept) {
            echo '<input type="hidden" name="keep_' . ins_h($k) . '" value="1">';
            echo '<p class="hint"><b>직전에 입력한 값을 그대로 유지합니다.</b> '
               . '다시 입력하면 새 값으로 바뀌고, 비워 두면 유지한 값이 등록됩니다.</p>';
        }

        echo '<label>최소 계정등급 (<code>min_account_level</code>)' . ins_gate_select('gl_' . $k, 'level', (string) $g['level']) . '</label>';
        echo '<label>최소 결제등급 (<code>min_billing_tier</code>)' . ins_gate_select('gt_' . $k, 'tier', (string) $g['tier']) . '</label>';
        echo '<label>최소 역할 (<code>min_role</code>)' . ins_gate_select('gr_' . $k, 'role', (string) $g['role']) . '</label>';
        echo '<p class="hint">세 개를 전부 <b>제한 없음</b>으로 두면 <b>게이트를 비우면 가입한 누구나 이 값을 받습니다.</b> '
           . '하나만 설정해도 게이트가 걸립니다(설정한 축만 검사합니다).</p>';

        if ($sensitive) {
            echo '<label class="radio"><input type="checkbox" name="ack_' . ins_h($k) . '" value="1"'
               . (!empty($vals['ack'][$k]) ? ' checked' : '') . '>'
               . '<b>게이트 없이 등록하겠습니다</b> — 가입한 모든 사용자가 <code>' . ins_h($k) . '</code> 평문을 받는 것을 이해했습니다.<br>'
               . '<span class="hint">위 세 개를 전부 "제한 없음"으로 두려면 이 항목을 체크해야 진행됩니다. '
               . '게이트를 설정했다면 체크하지 않아도 됩니다.</span></label>';
        } else {
            echo '<p class="hint">비밀이 아닌 설정값이므로 게이트를 비워 두어도 됩니다. 데모가 동작해야 하기 때문입니다.</p>';
        }
    }

    echo '<button type="submit">등록하고 설치 마무리</button>';
    echo '<button type="submit" name="skip" value="1" class="ghost">건너뛰고 설치 마무리</button>';
    echo '</form></div>';
    ins_page_end();
}

function ins_handle_step7(&$S)
{
    // 건너뛰기 — 세션에 남은 임시 입력값도 함께 버린다.
    if (ins_post('skip') === '1') {
        $S['secrets'] = [];
        unset($S['s7']);
        ins_finalize($S);
        return;
    }

    $stash   = (isset($S['s7']) && is_array($S['s7'])) ? $S['s7'] : [];
    $vals    = ins_step7_blank_vals();
    $secrets = [];

    foreach (ins_secret_fields() as $f) {
        $k   = $f['key'];
        $raw = trim(ins_post('sv_' . $k));
        // 오류 재렌더 후 비밀번호 입력란은 비어 있다. 유지 표시가 있으면 세션 값을 쓴다.
        if ($raw === '' && ins_post('keep_' . $k) === '1' && isset($stash[$k]) && $stash[$k] !== '') {
            $raw = (string) $stash[$k];
        }

        $g = [
            'level' => trim(ins_post('gl_' . $k)),
            'tier'  => trim(ins_post('gt_' . $k)),
            'role'  => trim(ins_post('gr_' . $k)),
        ];
        $ack = ins_post('ack_' . $k) === '1';

        $vals['values'][$k] = $f['input'] === 'password' ? '' : $raw;
        $vals['gates'][$k]  = $g;
        $vals['ack'][$k]    = $ack;
        $vals['kept'][$k]   = ($f['input'] === 'password' && $raw !== '');

        if ($raw === '') {
            continue;   // 입력하지 않은 항목은 등록하지 않는다
        }

        // 값 검증 (기존 규칙 유지)
        if (mb_strlen($raw, 'UTF-8') > (int) $f['maxlen']) {
            ins_step7_fail($S, $vals, $k . ' 가 너무 깁니다. (' . (int) $f['maxlen'] . '자 이내)');
            return;
        }
        if ($f['value_type'] === 'url') {
            $p = parse_url($raw);
            if ($p === false || empty($p['scheme']) || empty($p['host'])
                || !in_array(strtolower((string) $p['scheme']), ['http', 'https'], true)) {
                ins_step7_fail($S, $vals, $k . ' 는 http:// 또는 https:// 로 시작하는 절대 URL 이어야 합니다.');
                return;
            }
        }

        // 게이트 값 검증 — 화이트리스트 밖의 값은 저장하지 않는다.
        // (조용히 null 로 떨어뜨리면 게이트 없는 시크릿이 만들어진다)
        foreach (['level', 'tier', 'role'] as $axis) {
            if ($g[$axis] !== '' && !in_array($g[$axis], ins_gate_options($axis), true)) {
                ins_step7_fail($S, $vals, $k . ' 의 최소 등급 값이 올바르지 않습니다. 목록에서 다시 선택하세요.');
                return;
            }
        }

        // D-47 / C-3: 민감 키는 게이트가 비어 있으면 명시적 확인 없이 통과시키지 않는다.
        if (ins_is_sensitive_key($k) && !ins_gate_effective($g) && !$ack) {
            ins_step7_fail($S, $vals,
                $k . ' 은(는) 민감한 값인데 최소 등급이 하나도 설정되지 않았습니다. '
              . '이 상태로 등록하면 가입한 모든 사용자가 이 값을 평문으로 받습니다. '
              . '최소 등급을 설정하거나(권장: 최소 결제등급 = lite), '
              . '아래 "게이트 없이 등록하겠습니다" 를 체크한 뒤 다시 진행하세요.');
            return;
        }

        $secrets[] = [
            'secret_key'  => $k,
            'value'       => $raw,
            'value_type'  => $f['value_type'],
            'description' => $f['description'],
            'min_level'   => $g['level'],
            'min_tier'    => $g['tier'],
            'min_role'    => $g['role'],
        ];
    }

    $S['secrets'] = $secrets;
    unset($S['s7']);
    ins_finalize($S);
}

/**
 * 7단계 검증 실패. 입력한 비밀 값을 세션에 잠시 담아 두고 폼을 다시 그린다.
 * 재입력을 강요하면 사용자가 게이트를 통째로 풀어 버리는 쪽으로 도망간다 —
 * 그게 이 항목(C-3)에서 가장 피해야 할 결과다.
 * 세션은 ins_finalize() 끝에서 폐기되고, 화면에는 값을 다시 그리지 않는다.
 */
function ins_step7_fail(&$S, $vals, $msg)
{
    $stash = [];
    foreach (ins_secret_fields() as $f) {
        $k   = $f['key'];
        $raw = trim(ins_post('sv_' . $k));
        if ($raw === '' && ins_post('keep_' . $k) === '1'
            && isset($S['s7'][$k]) && $S['s7'][$k] !== '') {
            $raw = (string) $S['s7'][$k];
        }
        if ($f['input'] === 'password' && $raw !== '') {
            $stash[$k]        = $raw;
            $vals['kept'][$k] = true;
        }
    }
    $S['s7'] = $stash;
    ins_step7($msg, $vals);
}

// =====================================================================
// 8단계 — 설치 실행
//   순서가 중요하다. config.php 와 락 파일은 **DB 작업이 모두 끝난 뒤** 만든다.
//   먼저 만들면 중간 실패 시 자기 잠금에 걸려 재시도가 불가능해진다.
// =====================================================================

function ins_write_guarded_dir($dir)
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    @chmod($dir, 0700);
    // .htaccess 가 무시되는 환경(nginx 등)의 최후 방어 (보안 감사 H-5)
    if (is_dir($dir)) {
        if (!is_file($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', '');
        }
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess',
                "Require all denied\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\nOptions -Indexes\n");
        }
    }
    return is_dir($dir);
}

function ins_finalize(&$S)
{
    foreach (['db', 'site', 'admin', 'service'] as $k) {
        if (empty($S[$k])) {
            ins_fatal('설치 정보가 유실되었습니다(세션 만료). 페이지를 새로 열어 처음부터 다시 진행하세요.');
        }
    }

    // 1) 키 생성 — random_bytes(32). 화면에 표시하지 않는다 (M-12-4)
    $keys = [
        'secret_enc_key' => bin2hex(random_bytes(32)),
        'app_key'        => bin2hex(random_bytes(32)),
    ];

    $site = $S['site'];
    $cfg  = [
        'db'                   => $S['db'],
        'keys'                 => $keys,
        'force_https'          => $site['force_https'] === '1',
        'base_url'             => $site['base_url'],
        'site_name'            => $site['site_name'],
        'mail_driver'          => $site['mail_driver'],
        'mail_from'            => $site['mail_from'],
        'mail_from_name'       => $site['mail_from_name'],
        'allow_register'       => $site['allow_register'] === '1',
        'require_email_verify' => $site['require_verify'] === '1',
        'signup_bonus'         => (int) $site['signup_bonus'],
    ];

    $steps  = [];   // ['label'=>..., 'ok'=>bool, 'note'=>...]
    $now    = ins_now();
    $prefix = $S['db']['prefix'];
    // D-47(2): 완료 화면에서 "게이트 없는 시크릿"의 키 이름을 나열하기 위한 수집기
    $secretGates = [];   // [[키, 게이트 설명, 실효 여부], ...]
    $ungated     = [];   // 게이트가 비어 있는(=가입 전원 공개) 키 이름
    // 세션은 마지막에 폐기한다. 완료 화면에서 쓸 값은 먼저 지역 변수로 옮겨 둔다.
    $adminUsername = $S['admin']['username'];
    $serviceIdOut  = $S['service']['service_id'];

    // 2) DB 작업 — 한 트랜잭션
    $adminUuid = ins_uuid4();
    try {
        $pdo = ins_pdo($S['db']);
        $pdo->beginTransaction();

        // 2-1) 관리자 계정
        $st = $pdo->prepare('INSERT INTO `' . $prefix . 'users`'
            . ' (uuid, username, email, password_hash, password_changed_at, name,'
            . '  status, account_level, role, billing_tier, points, total_points_earned, total_points_spent,'
            . '  email_verified_at, timezone, locale, created_at, updated_at)'
            . ' VALUES (?,?,?,?,?,?, ?,?,?,?,?,?,?, ?,?,?,?,?)');
        $st->execute([
            $adminUuid,
            $S['admin']['username'],          // 소문자 정규화된 값
            $S['admin']['email'],
            $S['admin']['pw_hash'],
            $now,
            $S['admin']['name'],
            'active', 'staff', 'superadmin', 'free', 0, 0, 0,
            $now,                              // email_verified_at = 지금 (인증 완료 상태)
            'Asia/Seoul', 'ko-KR', $now, $now,
        ]);
        $adminId = (int) $pdo->lastInsertId();
        $steps[] = ['label' => '관리자 계정 <code>' . ins_h($S['admin']['username']) . '</code> (superadmin / staff / active)',
                    'ok' => true, 'note' => 'uuid ' . substr($adminUuid, 0, 8) . '…'];

        // 2-2) 첫 서비스
        $st = $pdo->prepare('INSERT INTO `' . $prefix . 'services`'
            . ' (service_id, service_name, service_secret_hash, is_confidential, redirect_whitelist,'
            . '  allowed_origins, auto_join, default_account_level, session_ttl, refresh_ttl,'
            . '  payload_fields, allow_register, status, description, created_at, updated_at)'
            . ' VALUES (?,?,?,?,?, ?,?,?,?,?, ?,?,?,?,?,?)');
        $st->execute([
            $S['service']['service_id'],
            $S['service']['service_name'],
            null, 0,
            json_encode($S['service']['redirects'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($S['service']['origins'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            1, null, 3600, 1209600,
            null, 1, 'active', '설치기가 만든 첫 서비스', $now, $now,
        ]);
        $steps[] = ['label' => '서비스 <code>' . ins_h($S['service']['service_id']) . '</code> 등록',
                    'ok' => true,
                    'note' => '복귀 주소 ' . count($S['service']['redirects']) . '건 / 허용 출처 '
                            . count($S['service']['origins']) . '건'];

        // 2-3) 시크릿 (선택) — 평문은 어디에도 남기지 않는다
        //      min_* 는 7단계에서 받은 값을 그대로 심는다. 빈 문자열은 NULL 이다
        //      (컬럼이 VARCHAR(20) NULL 이고, build_payload() 는 !empty() 로 판정한다).
        //      리더 판정 D-47 / 보안 감사 C-3 — 여기가 게이트를 정하는 유일한 지점이다.
        $secretCount = 0;
        if (!empty($S['secrets'])) {
            $st = $pdo->prepare('INSERT INTO `' . $prefix . 'service_secrets`'
                . ' (service_id, secret_key, secret_value_enc, value_type, min_account_level,'
                . '  min_billing_tier, min_role, is_masked, sort_order, description, status, created_at, updated_at)'
                . ' VALUES (?,?,?,?,?, ?,?,?,?,?,?,?,?)');
            foreach ($S['secrets'] as $i => $sec) {
                $gate = [
                    'level' => isset($sec['min_level']) ? (string) $sec['min_level'] : '',
                    'tier'  => isset($sec['min_tier'])  ? (string) $sec['min_tier']  : '',
                    'role'  => isset($sec['min_role'])  ? (string) $sec['min_role']  : '',
                ];
                $st->execute([
                    $S['service']['service_id'],
                    $sec['secret_key'],
                    ins_enc($sec['value'], $keys['secret_enc_key']),
                    $sec['value_type'],
                    $gate['level'] !== '' ? $gate['level'] : null,
                    $gate['tier']  !== '' ? $gate['tier']  : null,
                    $gate['role']  !== '' ? $gate['role']  : null,
                    1, $i, $sec['description'], 'active', $now, $now,
                ]);
                $secretCount++;
                $secretGates[] = [$sec['secret_key'], ins_gate_label($gate), ins_gate_effective($gate)];
                if (!ins_gate_effective($gate)) {
                    $ungated[] = (string) $sec['secret_key'];
                }
            }
        }
        $gateNote = [];
        foreach ($secretGates as $sg) {
            $gateNote[] = $sg[0] . ': ' . $sg[1];
        }
        $steps[] = ['label' => '시크릿 등록', 'ok' => true,
                    'note' => $secretCount > 0
                        ? $secretCount . '건 (AES-256-CBC 암호화 저장): ' . implode(' / ', $gateNote)
                        : '건너뜀: 관리자 콘솔에서 등록하세요'];

        // 2-4) 관리자의 서비스 가입 행 (콘솔에서 바로 보이게)
        $st = $pdo->prepare('INSERT INTO `' . $prefix . 'user_services`'
            . ' (user_id, service_id, account_level, role, billing_tier, billing_expires_at, points, status, joined_at, last_used_at)'
            . ' VALUES (?,?,?,?,?,?,?,?,?,?)');
        $st->execute([$adminId, $S['service']['service_id'], null, null, null, null, 0, 'active', $now, $now]);

        // 2-5) settings 시드 — DB 값이 config 상수보다 우선한다. 관리자 콘솔에서 바로 수정 가능.
        $seed = [
            ['allow_register',       $cfg['allow_register'] ? '1' : '0',       'bool',   '신규 가입 허용'],
            // v1.10.1 — 가입 방식 행(17번째 키). allow_register 와 같은 트랜잭션 의미로 맞춘다(open ⇔ 1).
            ['register_mode',        $cfg['allow_register'] ? 'open' : 'closed', 'string', '가입 방식'],
            ['require_email_verify', $cfg['require_email_verify'] ? '1' : '0', 'bool',   '가입 시 이메일 인증 필수'],
            ['signup_bonus_points',  (string) $cfg['signup_bonus'],            'int',    '가입 보너스 포인트'],
            ['login_fail_threshold', '5',                                      'int',    '락아웃 임계 실패 횟수'],
            ['login_lock_minutes',   '15',                                     'int',    '락아웃 지속 시간(분)'],
            ['password_min_length',  '8',                                      'int',    '비밀번호 최소 길이'],
            ['session_ttl_default',  '3600',                                   'int',    '기본 access 토큰 수명(초)'],
            ['refresh_ttl_default',  '1209600',                                'int',    '기본 refresh 토큰 수명(초)'],
            ['maintenance_mode',     '0',                                      'bool',   '점검 모드'],
            ['mail_driver',          $cfg['mail_driver'],                      'string', '메일 발송 방식 (mail|log)'],
            ['site_name',            $cfg['site_name'],                        'string', '사이트 표시명'],
            // v1.4.0 후속: 관리 가능한 13번째 키. 시드하지 않으면 관리자 화면에서
            // 처음 켜고 끌 때까지 DB 행이 없고, 그동안 값의 근거가 config.php 상수뿐이다.
            // 여기 순서는 $DEFS 와 같게 **맨 뒤**로 둔다.
            ['secret_access_log',    '1',                                      'bool',   '시크릿 전달 감사 기록'],
        ];
        // UPSERT 꼬리절은 core/dbdriver.php 한 함수가 만든다(A2) — MySQL 은 v1.7.0 과 같은 의미,
        // SQLite 는 ON CONFLICT(skey) DO UPDATE.
        $st = $pdo->prepare('INSERT INTO `' . $prefix . 'settings` (skey, svalue, vtype, label, updated_at)'
            . ' VALUES (?,?,?,?,?)'
            . ulogin_sql_upsert_tail(ulogin_pdo_driver($pdo), ['skey'],
                ['svalue' => 'new', 'vtype' => 'new', 'label' => 'new', 'updated_at' => 'new']));
        foreach ($seed as $row) {
            $st->execute([$row[0], $row[1], $row[2], $row[3], $now]);
        }
        $steps[] = ['label' => '전역 설정(settings) 초기화', 'ok' => true, 'note' => count($seed) . '개 키'];

        $pdo->commit();
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[ulogin-install] finalize db failed: ' . $e->getMessage());
        $reason = ($e instanceof PDOException) ? ins_pdo_reason($e) : ins_safe_msg($e->getMessage());
        ins_page_start('설치 실패', 8);
        echo '<div class="card"><div class="msg err"><b>DB 작업이 실패해 아무것도 저장하지 않았습니다.</b><br>'
           . ins_h($reason) . '</div>';
        echo '<p class="hint">트랜잭션을 되돌렸으므로 DB는 실행 전 상태입니다. '
           . '<code>config.php</code> 도 만들지 않았으니 이 페이지를 새로고침해 다시 시도할 수 있습니다.</p>';
        echo '<form method="post">' . ins_csrf_field()
           . '<input type="hidden" name="step" value="7x"><button type="submit">다시 시도</button></form></div>';
        ins_page_end();
        exit;
    }

    // 3) storage 디렉토리 + 웹 접근 가드 (M-12-3 / H-5)
    $stOk  = ins_write_guarded_dir(INS_STORAGE);
    $mlOk  = ins_write_guarded_dir(INS_MAILDIR);
    $steps[] = ['label' => '<code>server/storage/</code>, <code>server/storage/mail/</code> 생성 + 접근 차단 파일',
                'ok' => ($stOk && $mlOk),
                'note' => ($stOk && $mlOk) ? 'chmod 0700 + .htaccess + index.html'
                                           : '만들 수 없었습니다. FTP로 직접 만들고 쓰기 권한을 주세요.'];

    // 4) config.php 작성 — 마지막 직전. 실패하면 화면에 내용을 출력한다.
    $configText   = ins_render_config($cfg);
    $configWrote  = false;
    $configFallbk = null;
    if (@file_put_contents(INS_CONFIG, $configText, LOCK_EX) !== false) {
        $configWrote = true;
        if (!@chmod(INS_CONFIG, 0600)) {
            $steps[] = ['label' => '<code>config.php</code> 권한 0600', 'ok' => null,
                        'note' => 'chmod 실패. 호스팅 파일 관리자에서 권한을 600으로 바꿔 주세요.'];
        }
        $steps[] = ['label' => '<code>server/config.php</code> 생성', 'ok' => true,
                    'note' => count(ins_config_spec($cfg)) . '개 상수'];
    } else {
        // 백업 시도: storage/ 는 웹 접근이 차단된 디렉토리다.
        $fb = INS_STORAGE . '/config.php.generated.txt';
        if ($stOk && @file_put_contents($fb, $configText, LOCK_EX) !== false) {
            @chmod($fb, 0600);
            $configFallbk = 'server/storage/config.php.generated.txt';
        }
        $steps[] = ['label' => '<code>server/config.php</code> 생성', 'ok' => false,
                    'note' => '쓰기 권한이 없습니다. 아래 내용을 손으로 만들어 주세요.'];
    }

    // 5) 자기 잠금 (M-12-1)
    $stamp   = "installed_at=" . gmdate('Y-m-d\TH:i:s\Z') . "\nnonce=" . bin2hex(random_bytes(16)) . "\n";
    $lockOk  = @file_put_contents(INS_LOCK, $stamp, LOCK_EX) !== false;
    if ($lockOk) {
        @chmod(INS_LOCK, 0600);
    }
    $instOk = false;
    if ($stOk) {
        $instOk = @file_put_contents(INS_INSTALLED, $stamp, LOCK_EX) !== false;
        if ($instOk) {
            @chmod(INS_INSTALLED, 0600);
        }
    }
    $steps[] = ['label' => '설치기 자기 잠금', 'ok' => ($lockOk || $instOk || $configWrote),
                'note' => ($lockOk ? 'server/.install.lock 생성' : '.install.lock 생성 실패')
                        . ' / ' . ($instOk ? 'storage/.installed 생성' : 'storage/.installed 생성 실패')];

    // 5-1) 설치 토큰 삭제 — 설계 G-5 「설치 완료 즉시 삭제」. 잠금 뒤에 지운다
    //      (잠금 전에 지우면 그 사이에 새 토큰이 만들어질 수 있다).
    $tokFile = ins_token_file();
    $tokGone = true;
    if ($tokFile !== null) {
        clearstatcache(true, $tokFile);
        if (is_file($tokFile)) {
            $tokGone = @unlink($tokFile);
        }
    }
    ins_token_throttle_clear();
    unset($_SESSION['ins_tok']);
    $steps[] = ['label' => '설치 토큰 파일 삭제', 'ok' => $tokGone,
                'note' => $tokGone ? '삭제했습니다' : '지우지 못했습니다. ' . ins_token_where() . ' 를 손으로 지우세요'];

    // 6) 자체 진단 — .htaccess 가 실제로 먹는지 HTTP 로 확인 (M-12-9)
    $serverUrl = ins_server_url($cfg['base_url']);
    $probes    = [
        ['config.php',    $serverUrl . '/config.php'],
        ['core/db.php',   $serverUrl . '/core/db.php'],
        ['storage/mail/', $serverUrl . '/storage/mail/'],
        ['schema.sql',    $serverUrl . '/schema.sql'],
    ];
    $probeRows = [];
    foreach ($probes as $pr) {
        $res = ins_http_probe($pr[1]);
        if ($res === null) {
            $probeRows[] = [$pr[0], $pr[1], null, '자동 확인 불가 (curl·allow_url_fopen 없음): 브라우저로 직접 열어 보세요'];
            continue;
        }
        $blocked = in_array($res['status'], [401, 403, 404], true);
        $probeRows[] = [$pr[0], $pr[1], $blocked,
            'HTTP ' . $res['status'] . ($blocked ? ' — 차단됨 (정상)' : ' — 응답 ' . $res['len'] . '바이트. 노출되었을 수 있습니다')];
    }

    // 세션 폐기 — DB 비밀번호를 서버에 남기지 않는다
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        // PHP 7.2 호환 — **위치 인자 7개 형식을 유지한다.**
        // setcookie($name, $value, ['expires'=>…,'samesite'=>…]) 옵션 배열 형식은
        // PHP 7.3+ 전용이다. 7.2 는 3번째 인자를 정수 expires 로 캐스팅하므로
        // 배열이 0(=세션 쿠키)이 되어 **삭제가 조용히 실패**하고, path·secure·
        // httponly 도 기본값으로 떨어진다. 이 파일은 core/ 를 include 하지 않으므로
        // core/request.php 의 send_cookie() 래퍼를 쓸 수 없다 — 자체 처리한다.
        // SameSite 는 이 형식으로 지정할 수 없지만, 값이 빈 만료 쿠키(삭제)라 무해하다.
        @setcookie(session_name(), '', time() - 42000, '/', '', ins_is_https(), true);
    }
    @session_destroy();

    // -----------------------------------------------------------------
    // 완료 화면
    // -----------------------------------------------------------------
    ins_page_start('설치 완료', 8);
    echo '<div class="card">';
    echo '<div class="msg good"><b>설치가 끝났습니다.</b> 아래 ' . ($configWrote ? '3' : '4')
       . '가지를 지금 처리하세요. 이 화면을 닫으면 다시 볼 수 없습니다. '
       . '<b>1번(<code>install.php</code> 삭제)이 가장 급합니다.</b></div>';

    echo '<h2>설치 결과</h2><table><tr><th>항목</th><th>결과</th><th>비고</th></tr>';
    foreach ($steps as $s2) {
        if ($s2['ok'] === null) {
            $cls = 'warn">주의';
        } elseif ($s2['ok']) {
            $cls = 'ok">완료';
        } else {
            $cls = 'bad">실패';
        }
        echo '<tr><td>' . $s2['label'] . '</td><td class="' . $cls . '</td><td>' . ins_h($s2['note']) . '</td></tr>';
    }
    echo '</table>';
    echo '<p class="hint"><code>SECRET_ENC_KEY</code> 와 <code>APP_KEY</code> 는 '
       . '<code>random_bytes(32)</code> 로 새로 만들어 <code>config.php</code> 에만 기록했습니다. '
       . '브라우저 히스토리·스크린샷으로 새는 것을 막기 위해 <b>화면에는 표시하지 않습니다.</b> '
       . '<code>config.php</code> 를 백업해 두세요. 이 키를 잃으면 저장된 AI API 키를 복호화할 수 없습니다.</p>';

    // D-52: install.php 삭제를 1번 항목으로 올린다.
    echo '<h2>1. install.php를 삭제하세요 (필수)</h2>';
    echo '<div class="msg big"><b>서버에서 <code>server/install.php</code> 파일을 지금 삭제하세요.</b><br>'
       . '설치기는 <b>인증 없이 도는 코드</b>입니다. 삭제 전까지는 잠금 파일(<code>.install.lock</code>)이 '
       . '재실행을 막지만, 파일 자체가 없는 것이 유일하게 확실한 방어입니다.'
       . ($configWrote ? '' : '<br><b>단, 아래 2번의 <code>config.php</code> 내용을 먼저 복사해 두세요</b> — '
                            . '이 화면을 닫으면 키를 다시 볼 수 없습니다.')
       . '</div>';

    if (!$configWrote) {
        echo '<h2>2. config.php를 손으로 만들어 주세요 (필수)</h2>';
        if ($configFallbk !== null) {
            // 리더 판정 D-110. 쓰기 실패 시의 화면 덤프는 **설치 실패의 복구 수단**이므로
            // 없애지 않는다. 다만 파일로 남길 수 있었다면 화면에까지 평문을 뿌릴 이유가 없다 —
            // 그 화면은 캡처·공유되고, 평문 HTTP 로 열려 있을 수도 있다.
            // 그래서 **파일이 남은 경우에만** 시크릿 3개를 자리표시자로 바꾼다.
            echo '<div class="msg big"><b>같은 내용을 <code>' . ins_h($configFallbk) . '</code> 에 저장해 두었습니다.</b><br>'
               . 'FTP로 내려받아 <code>server/config.php</code> 로 이름을 바꾸고 권한을 <code>600</code> 으로 주세요. '
               . '이 파일이 없으면 모든 API가 500을 반환합니다.<br>'
               . '<b>옮긴 뒤에는 <code>' . ins_h($configFallbk) . '</code> 를 반드시 지우세요</b> — '
               . '암호화 키가 들어 있는 파일이 두 개가 됩니다.</div>';
            echo '<div class="msg">아래는 <b>같은 내용에서 비밀값 3개를 가린 것</b>입니다. '
               . '구조 확인용이며 이대로 복사하면 동작하지 않습니다. '
               . '<code>__PASTE_FROM_GENERATED_FILE__</code> 자리는 위 파일에서 가져와야 합니다.</div>';
            echo '<pre>' . ins_h(ins_redact_config($configText)) . '</pre>';
        } else {
            echo '<div class="msg big"><b>이 화면을 닫으면 아래 키를 다시 볼 수 없습니다.</b><br>'
               . '<code>server/storage/</code> 에도 쓸 수 없어 파일로 남기지 못했습니다. '
               . '아래가 <b>유일한 사본</b>이므로 비밀값을 그대로 표시합니다. '
               . '지금 복사해 <code>server/config.php</code> 로 저장하고 권한을 <code>600</code> 으로 바꾸세요.<br>'
               . '<b>이 화면을 캡처해 공유하면 안 됩니다.</b> DB 비밀번호와 시크릿 암호화 키가 들어 있습니다.</div>';
            echo '<pre>' . ins_h($configText) . '</pre>';
        }
    }

    // D-69(a) / 보안 감사 §C-4 조건 1 — 완료 화면의 PHP 보안 지원 종료 경고 1행.
    //   1단계 정보성 표에 같은 경고가 있지만 그 화면은 설치자가 가장 빨리 지나치는 화면이고,
    //   위 1번대로 install.php 를 삭제하면 그 뒤에는 이 사실을 다시 볼 곳이 없다.
    //   그래서 캡처·공유되는 이 화면에도 한 줄 남긴다. 번호 있는 필수 항목이 아니므로
    //   위 "3가지/4가지" 카운트는 그대로다.
    //   **PHP 7.4 이상에서는 아무것도 출력하지 않는다** — 불필요한 경고는 무시를 학습시킨다.
    //   여기서도 설치를 막지 않는다(D-60). 이미 설치는 끝났고, 이 줄은 권고다.
    //   PHP_VERSION_ID 는 PHP 5.2.7+ 상수다. 7.2 에서 정상 동작한다.
    if (PHP_VERSION_ID < 70400) {
        echo '<p><b class="warn">주의</b> 현재 PHP 버전(<code>' . ins_h(PHP_VERSION) . '</code>)은 '
           . '보안 지원이 종료된 버전입니다. 호스팅에 PHP 7.4 이상 옵션이 있으면 올리는 것을 권합니다. '
           . '이 시스템은 AI API 키를 다룹니다. '
           . '<span class="hint">이 설치기는 곧 삭제되므로 이 안내를 다시 볼 수 없습니다. '
           . '같은 내용이 <code>README.md</code> 의 PHP 버전 항목에 있습니다.</span></p>';
    }

    // D-47(2): "가입 개방 + 게이트 없는 시크릿" 조합 경고. 실제 게이트 없는 키를 나열한다.
    //   심각도를 나눈다 — api_base·model 처럼 **의도적으로 공개인 설정값**까지
    //   빨간 경고로 묶으면 경고가 늑대소년이 되고, 사용자가 다음엔 읽지 않는다.
    $ungatedSensitive = [];
    $ungatedPublic    = [];
    foreach ($ungated as $uk) {
        if (ins_is_sensitive_key($uk)) {
            $ungatedSensitive[] = $uk;
        } else {
            $ungatedPublic[] = $uk;
        }
    }
    $codeList = function ($arr) {
        $parts = [];
        foreach ($arr as $x) {
            $parts[] = '<code>' . ins_h($x) . '</code>';
        }
        return implode(', ', $parts);
    };

    echo '<h2>' . ($configWrote ? '2' : '3') . '. 시크릿 등급 게이트 확인</h2>';
    if ($ungatedSensitive !== []) {
        echo '<div class="msg big"><b>민감한 값에 등급 게이트가 없습니다. '
           . '이 서비스에 로그인한 모든 사용자가 그 값을 평문으로 받습니다.</b><br>'
           . '게이트 없는 민감 시크릿: ' . $codeList($ungatedSensitive) . '<br>';
        if ($cfg['allow_register']) {
            echo '<b>게다가 신규 회원가입(<code>ALLOW_REGISTER</code>)이 열려 있고 이 서비스는 '
               . '로그인 시 자동 가입(<code>auto_join=1</code>)입니다.</b> 두 조건이 겹치면 '
               . '<b>누구나 가입 폼을 채우는 것만으로 위 값을 받습니다.</b> '
               . '가입 자체는 정상 기능이므로 끄지 않았습니다. 대신 게이트를 거는 것이 맞습니다.<br>';
        } else {
            echo '신규 가입은 닫혀 있으므로(<code>ALLOW_REGISTER=false</code>) 노출 범위는 '
               . '관리자가 만든 계정으로 제한됩니다. 그래도 게이트를 걸어 두는 것이 안전합니다.<br>';
        }
        echo '<b>조치:</b> 관리자 콘솔 > 서비스 > <code>' . ins_h($serviceIdOut) . '</code> > 시크릿에서 '
           . '<code>min_billing_tier</code>(권장 <code>lite</code>) 또는 '
           . '<code>min_account_level</code> / <code>min_role</code> 을 설정하세요.</div>';
    } else {
        echo '<div class="msg big"><b>게이트(<code>min_account_level</code> / '
           . '<code>min_billing_tier</code> / <code>min_role</code>)를 비운 시크릿은 '
           . '그 서비스에 로그인한 모든 사용자에게 내려갑니다.</b><br>'
           . '가입이 열려 있고 서비스가 <code>auto_join=1</code> 이면 <b>가입한 누구나</b>가 됩니다. '
           . '지금 등록된 민감 값(<code>*_api_key</code>·<code>*_secret</code>·<code>*_token</code>·'
           . '<code>*_password</code>)에는 모두 게이트가 걸려 있습니다. '
           . '<b>관리자 콘솔에서 시크릿을 추가할 때도 같은 규칙을 지키세요</b> — '
           . '콘솔은 이 확인을 강제하지 않습니다.</div>';
    }
    if ($ungatedPublic !== []) {
        echo '<p class="hint">게이트 없이 등록된 <b>공개 설정값</b>: ' . $codeList($ungatedPublic)
           . ' — 비밀이 아닌 값이면 정상입니다(데모가 동작해야 하므로 일부러 열어 둡니다). '
           . '만약 이 중에 비밀로 다뤄야 할 값이 있다면 관리자 콘솔에서 게이트를 거세요.</p>';
    }
    if ($secretGates !== []) {
        echo '<table><tr><th>시크릿</th><th>게이트</th><th>판정</th></tr>';
        foreach ($secretGates as $sg) {
            echo '<tr><td><code>' . ins_h($sg[0]) . '</code></td><td>' . ins_h($sg[1]) . '</td>'
               . '<td class="' . ($sg[2] ? 'ok">게이트 있음' : 'bad">전원 공개') . '</td></tr>';
        }
        echo '</table>';
    }

    echo '<h2>' . ($configWrote ? '3' : '4') . '. 보안 확인 4항목: 브라우저로 직접 열어 보세요</h2>';
    echo '<p>아래 주소를 브라우저 새 탭에 붙여 열었을 때 <b>403 / 404 / 빈 화면</b>이 나와야 정상입니다. '
       . '내용이 보이면 <code>.htaccess</code> 가 무시되는 환경입니다. 그때는 '
       . '<code>server/</code> 디렉토리를 웹 루트 밖으로 옮기거나 호스팅 관리자에게 문의하세요. '
       . 'nginx 라면 <code>location ~ ^/server/(core|storage)/ { deny all; }</code> 를 추가해야 합니다.</p>';
    echo '<table><tr><th>확인 대상</th><th>설치기 자동 진단</th><th>결과</th></tr>';
    foreach ($probeRows as $r) {
        if ($r[2] === null) {
            $cls = 'warn">확인 불가';
        } elseif ($r[2]) {
            $cls = 'ok">차단 확인';
        } else {
            $cls = 'bad">노출 의심';
        }
        echo '<tr><td><code>' . ins_h($r[0]) . '</code><br><span class="hint">' . ins_h($r[1]) . '</span></td>'
           . '<td class="' . $cls . '</td><td>' . ins_h($r[3]) . '</td></tr>';
    }
    echo '</table>';
    echo '<p class="hint">자동 진단은 참고용입니다. 프록시·캐시 때문에 결과가 다를 수 있으니 '
       . '<b>브라우저로도 직접 확인</b>하세요.</p>';

    echo '<h2>바로 가기</h2>';
    $base = rtrim($cfg['base_url'], '/');
    echo '<ul>'
       . '<li>로그인 화면: <a href="' . ins_h($base . '/public/login.html') . '">' . ins_h($base . '/public/login.html') . '</a></li>'
       . '<li>관리자 콘솔: <a href="' . ins_h($base . '/public/admin.html') . '">' . ins_h($base . '/public/admin.html') . '</a>'
       . ' <span class="hint">아이디 <code>' . ins_h($adminUsername) . '</code> 로 로그인하세요 (소문자)</span></li>'
       . '<li>마이페이지: <a href="' . ins_h($base . '/public/mypage.html') . '">' . ins_h($base . '/public/mypage.html') . '</a></li>'
       . '<li>연동 데모: <code>examples/local-ai-app.html</code> (로컬 웹서버로 열어야 합니다: <code>python -m http.server 5173</code>)</li>'
       . '</ul>';

    if ($cfg['mail_driver'] === 'log') {
        echo '<h2>메일 <code>log</code> 드라이버 경고: 반드시 읽어 주세요</h2>';
        echo '<div class="msg big">현재 메일 발송 방식은 <code>log</code> 입니다. 메일을 보내지 않고 '
           . '<code>server/storage/mail/</code> 에 <b>파일로 쌓습니다.</b> 파일 확장자는 <code>.php</code> 이고 첫 줄이 <code>&lt;?php exit; ?&gt;</code> 라서 <b>브라우저로 열면 빈 화면</b>이 나옵니다. FTP·파일관리자로 내려받아 열어야 내용이 보입니다. 그 파일 안에는<br>'
           . '· 비밀번호 재설정 링크(<code>reset.html?token=…</code>): 그 자체로 계정을 탈취할 수 있는 토큰<br>'
           . '· 관리자가 발급한 <b>임시 비밀번호 평문</b><br>'
           . '· 이메일 인증 링크<br>'
           . '가 <b>평문으로</b> 들어 있습니다.</div>';
        echo '<p><b>정리 방법</b></p><ol>'
           . '<li>비밀번호 재설정을 처리한 뒤에는 <code>server/storage/mail/*.php</code> 를 즉시 삭제하세요. '
           . '토큰 수명(30분)이 지나도 임시 비밀번호는 계속 유효합니다.</li>'
           . '<li>호스팅의 <code>mail()</code> 이 동작한다면 관리자 콘솔 > 설정 > '
           . '<code>메일 발송 방식</code> 을 <code>mail</code> 로 바꾸세요. 그 뒤에는 파일이 쌓이지 않습니다.</li>'
           . '<li>위 보안 확인에서 <code>storage/mail/</code> 가 "차단 확인"으로 나왔는지 다시 보세요. '
           . '노출되어 있으면 <b>지금 즉시</b> 디렉토리를 웹 루트 밖으로 옮기세요.</li>'
           . '</ol>';
    }

    echo '<h2>다음 단계</h2><ol>'
       . '<li>관리자 콘솔에 로그인해 서비스 > 시크릿에서 AI API 키와 <b>최소 등급</b>을 등록·확인합니다. '
       . '게이트를 비운 시크릿은 그 서비스에 로그인한 전원에게 내려갑니다.</li>'
       // D-67(4): TRUST_PROXY 단독 안내는 위험하다. 그것만 켜면 요청이 500 이 된다.
       // 두 상수를 반드시 한 문장 안에서 함께 안내한다 (config.sample.php 의 두 블록과 같은 내용).
       . '<li><b>리버스 프록시·CDN(Cloudflare 등) 뒤에 있다면</b> <code>server/config.php</code> 의 '
       . '<code>TRUST_PROXY</code> 를 <code>true</code> 로 바꾸고 <b><code>TRUSTED_PROXIES</code> 에 '
       . '프록시 IP를 함께 채우세요.</b> 두 상수는 짝입니다. '
       . '<code>TRUST_PROXY=true</code> 인데 <code>TRUSTED_PROXIES</code> 가 비어 있으면 '
       . '<b>설정 오류로 취급해 요청이 500이 됩니다</b>(리다이렉트 무한 루프로 원인을 숨기지 않기 '
       . '위한 동작입니다. 서버 오류 로그에 사유가 남습니다). 기본값은 '
       . '<code>false</code> + 빈 배열입니다. 그 상태에서는 <code>X-Forwarded-Proto</code> 헤더를 '
       . '무시하고 서버가 직접 본 프로토콜만 봅니다. 켤 때는 <b>프록시가 그 헤더를 반드시 덮어쓰도록</b> '
       . '설정되어 있어야 합니다. 그렇지 않으면 클라이언트가 헤더 한 줄로 <code>FORCE_HTTPS</code> 를 '
       . '우회합니다. (보안 감사 L-8 · V11-L-6)</li>'
       . '<li>로컬 앱에 서버 주소 <code>' . ins_h($cfg['base_url']) . '</code> 와 '
       . '<code>service_id = "' . ins_h($serviceIdOut) . '"</code> 를 넣습니다.</li>'
       . '<li>복귀 주소를 추가해야 하면 관리자 콘솔 > 서비스 > 복귀 주소 화이트리스트에서 등록합니다. '
       . '등록되지 않은 주소로는 SSO가 실패합니다.</li>'
       . '<li>운영 중 <code>DEBUG</code> 를 <code>true</code> 로 켜지 마세요. 예외 메시지가 응답에 실립니다. '
       . '문제 조사 후에는 반드시 <code>false</code> 로 되돌립니다.</li>'
       . '</ol>';

    echo '</div>';
    ins_page_end();
    exit;
}

// =====================================================================
// 라우터
// =====================================================================

set_exception_handler(function ($e) {
    error_log('[ulogin-install] uncaught ' . get_class($e) . ': ' . $e->getMessage());
    ins_fatal('설치 중 예상하지 못한 오류가 발생했습니다. 상세 사유는 서버 오류 로그에 기록했습니다.');
});

$method = strtoupper((string) (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET'));

if ($method !== 'POST') {
    // GET 은 항상 1단계부터. DB 정보를 GET 쿼리로 받지 않는다 (M-12-8)
    // 설치 토큰 파일이 없으면 여기서 만든다(설계 G-5 — 서버에 기동 훅이 없어 첫 요청이 유일한 자리다).
    // 토큰을 둘 곳이 없으면 설치 화면 자체를 내지 않는다.
    $tokState = ins_token_ensure();
    if ($tokState === 'nodir' || $tokState === 'fail') {
        ins_token_unavailable($tokState);
    }
    $S = [];
    ins_step1();
    exit;
}

ins_csrf_check();
$submitted = ins_post('step');

// 🔴 설치 토큰 관문 (설계 G-5 · 배포 조건 9). **이 줄이 설치 선점을 막는 유일한 자리다.**
//    단계 분기보다 반드시 먼저 — 어느 단계 번호로 들어와도 토큰 없이 진행하지 못한다.
ins_token_gate($submitted);

if ($submitted === '1') {
    ins_step2(null, null);
} elseif ($submitted === '2') {
    ins_handle_step2($S);
} elseif ($submitted === '3') {
    if (empty($S['db'])) {
        ins_fatal('DB 접속 정보가 유실되었습니다(세션 만료). 페이지를 새로 열어 처음부터 다시 진행하세요.');
    }
    try {
        $res = ins_apply_schema($S['db']);
    } catch (PDOException $e) {
        error_log('[ulogin-install] schema connect failed: ' . $e->getMessage());
        ins_step3([[['table' => '(DB 접속)', 'ok' => false, 'note' => ins_pdo_reason($e)]], 1]);
        exit;
    } catch (Throwable $e) {
        error_log('[ulogin-install] schema read failed: ' . $e->getMessage());
        ins_step3([[['table' => 'schema.sql', 'ok' => false, 'note' => 'schema.sql을 읽을 수 없습니다. 파일이 함께 업로드되었는지 확인하세요.']], 1]);
        exit;
    }
    ins_step3($res);
} elseif ($submitted === '3x') {
    if (empty($S['db'])) {
        ins_fatal('DB 접속 정보가 유실되었습니다(세션 만료). 페이지를 새로 열어 처음부터 다시 진행하세요.');
    }
    ins_step4(null, null);
} elseif ($submitted === '4') {
    if (empty($S['db'])) {
        ins_fatal('DB 접속 정보가 유실되었습니다(세션 만료). 페이지를 새로 열어 처음부터 다시 진행하세요.');
    }
    ins_handle_step4($S);
} elseif ($submitted === '5') {
    if (empty($S['db']) || empty($S['site'])) {
        ins_fatal('설치 정보가 유실되었습니다(세션 만료). 페이지를 새로 열어 처음부터 다시 진행하세요.');
    }
    ins_handle_step5($S);
} elseif ($submitted === '6') {
    if (empty($S['admin'])) {
        ins_fatal('설치 정보가 유실되었습니다(세션 만료). 페이지를 새로 열어 처음부터 다시 진행하세요.');
    }
    ins_handle_step6($S);
} elseif ($submitted === '7') {
    if (empty($S['service'])) {
        ins_fatal('설치 정보가 유실되었습니다(세션 만료). 페이지를 새로 열어 처음부터 다시 진행하세요.');
    }
    ins_handle_step7($S);
} elseif ($submitted === '7x') {
    if (empty($S['service'])) {
        ins_fatal('설치 정보가 유실되었습니다(세션 만료). 페이지를 새로 열어 처음부터 다시 진행하세요.');
    }
    ins_finalize($S);
} else {
    ins_step1();
}
