<?php
declare(strict_types=1);

/**
 * server/core/localkey.php — /local-admin 1회용 열쇠 링크 (v1.10.1 보안 M-2, D-375)
 * 담당 판정: `_workspace/128_leader_decisions_v1100.md` D-375 M-2 (Jupyter 방식)
 *
 * 문제: /local-admin 은 「서버 PC 에서 온 요청」만 보고 열렸다. 같은 PC 의 다른 Windows 계정·샌드박스도 127.0.0.1 로
 *       들어오므로 비상 최고관리자 비밀번호를 정할 수 있었다.
 * 해법: 열쇠를 가진 사람만 폼을 본다. 열쇠는 **서버 PC 의 운영 계정만 쓸 수 있는 파일**에서 나온다.
 *   1) CLI `tools\emergency-admin.bat open`(= `server/tools/emergency_admin.php --open-link`)이
 *      32바이트 난수 열쇠 k 를 만들고, `<runtime>/secrets/local-admin.key` 에 **HMAC(APP_KEY, k) 만** 쓴다(원문은 링크에만).
 *      파일 ACL = 실행 계정·SYSTEM·Administrators(상속 끊음, /T 없음 — 파일 하나에만 건다).
 *   2) 브라우저가 `/local-admin?k=…` 로 오면 hash_equals 로 맞춰 보고, 맞으면 같은 파일을 「세션」 단계로 바꿔 쓴다
 *      (새 난수 쿠키 값의 HMAC, 10분). 링크 열쇠는 그 순간 죽는다(1회용). 응답은 Set-Cookie + 302(k 없는 주소).
 *   3) 쿠키(HttpOnly · SameSite=Strict · Path=/local-admin · 10분)가 맞아야 폼이 보이고 POST 가 처리된다.
 *   4) 비밀번호를 정하는 데 성공하면 파일을 지우고 쿠키를 지운다.
 * HMAC 을 쓰는 이유: 파일에 쓸 수 있는 누군가가 자기 값을 심어도 APP_KEY(config.php, 운영 계정 ACL)를 모르면 열쇠를 못 만든다.
 *
 * runtime 위치는 config 가 가리키는 곳에서 구한다(ulogin_runtime_dir): DB_SQLITE_PATH 가 `<runtime>/data/<파일>` 모양이면
 * 그 `<runtime>`, 아니면(MySQL 등) 설치 루트의 형제 `runtime/`. 운영 = P/runtime → P/runtime/secrets.
 * 로드: bootstrap 을 지난 뒤 require_once(직접 실행되면 404). PHP 7.2 호환.
 */

if (!defined('ULOGIN_BOOTSTRAPPED')) {
    header_remove('X-Powered-By');
    http_response_code(404);
    exit;
}

const LA_KEY_TTL    = 600;                       // 링크·쿠키 모두 10분
const LA_KEY_FILE   = 'local-admin.key';
const LA_COOKIE     = 'ULOGIN_LA';
const LA_COOKIE_PATH = '/local-admin';
const LA_KEY_CTX    = 'ulogin-local-admin/1|';   // HMAC 문맥(다른 용도의 APP_KEY HMAC 과 섞이지 않게)
const LA_PORT_DEFAULT = 8080;

/** config 가 가리키는 runtime 폴더(슬래시 정규화, 끝 슬래시 없음). */
function ulogin_runtime_dir(): string
{
    if (db_driver() === 'sqlite' && defined('DB_SQLITE_PATH')) {
        $db   = ulogin_sqlite_path_resolve(DB_SQLITE_PATH);
        $data = dirname($db);
        if (strtolower(basename($data)) === 'data') {
            return str_replace('\\', '/', dirname($data));
        }
    }
    return ulogin_path_collapse(ulogin_install_root() . '/../runtime');
}

function la_secrets_dir(): string
{
    return ulogin_runtime_dir() . '/secrets';
}

function la_key_path(): string
{
    return la_secrets_dir() . '/' . LA_KEY_FILE;
}

function la_mac(string $tok): string
{
    return hash_hmac('sha256', LA_KEY_CTX . $tok, (string) APP_KEY);
}

function la_tok_shape_ok(string $t): bool
{
    return preg_match('/^[0-9a-f]{64}\z/', $t) === 1;
}

/**
 * Windows: 파일(또는 방금 만든 폴더) 하나의 ACL 을 「실행 계정 · SYSTEM · Administrators」로. 상속을 끊는다. /T 는 쓰지 않는다.
 * 그 밖의 OS: chmod 0600(폴더는 0700). 실패하면 false — 호출자가 fail-closed 로 처리한다.
 */
function la_acl_lock(string $path, bool $isDir = false): bool
{
    if (DIRECTORY_SEPARATOR !== '\\') {
        return @chmod($path, $isDir ? 0700 : 0600);
    }
    $user = (string) getenv('USERNAME');
    $dom  = (string) getenv('USERDOMAIN');
    if ($user === '' || preg_match('/^[^"%\\\\\/]{1,64}\z/u', $user) !== 1 || ($dom !== '' && preg_match('/^[^"%\\\\\/]{1,64}\z/u', $dom) !== 1)) {
        return false;
    }
    $who  = ($dom !== '' ? $dom . '\\' : '') . $user;
    $perm = $isDir ? '(OI)(CI)F' : 'F';
    $win  = str_replace('/', '\\', $path);
    if (strpos($win, '"') !== false || strpos($win, '%') !== false) {
        return false;
    }
    $cmd = 'icacls "' . $win . '" /inheritance:r /grant:r "' . $who . ':' . $perm . '" "*S-1-5-18:' . $perm . '" "*S-1-5-32-544:' . $perm . '"';
    $out = [];
    $rc  = 1;
    @exec($cmd . ' 2>&1', $out, $rc);
    return $rc === 0;
}

/** 파일을 잠그고 콜백에 (현재 레코드|null) 를 넘겨 새 레코드(또는 null=그대로)를 받는다. 파일이 없으면 $create 일 때만 만든다. */
function la_key_locked_update(callable $fn, bool $create)
{
    $path = la_key_path();
    if (!$create && !is_file($path)) {
        return $fn(null)[1] ?? null;
    }
    $h = @fopen($path, $create ? 'c+' : 'r+');
    if ($h === false) {
        if ($create) {
            throw new RuntimeException('local-admin key file cannot be opened');
        }
        return $fn(null)[1] ?? null;
    }
    try {
        if (!flock($h, LOCK_EX)) {
            throw new RuntimeException('local-admin key file lock failed');
        }
        $raw = stream_get_contents($h);
        $rec = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        list($new, $ret) = $fn(is_array($rec) ? $rec : null);
        if (is_array($new)) {
            ftruncate($h, 0);
            rewind($h);
            fwrite($h, (string) json_encode($new, JSON_UNESCAPED_SLASHES));
            fflush($h);
        }
        flock($h, LOCK_UN);
        return $ret;
    } finally {
        fclose($h);
    }
}

/**
 * 새 링크 열쇠. 앞 열쇠·세션은 덮어써져 죽는다. 반환: ['token' => 64hex(원문, 링크에만), 'exp' => unix, 'path' => 파일]
 * ACL 을 걸지 못하면 파일을 지우고 RuntimeException(fail-closed).
 */
function la_key_issue(): array
{
    $dir = la_secrets_dir();
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('cannot create ' . $dir);
        }
        if (!la_acl_lock($dir, true)) {   // 방금 만든 폴더에만 건다(이미 있던 폴더의 ACL 은 건드리지 않는다)
            throw new RuntimeException('cannot restrict the ACL of the new secrets folder');
        }
    }
    $tok = bin2hex(random_bytes(32));
    $exp = time() + LA_KEY_TTL;
    la_key_locked_update(function ($cur) use ($tok, $exp) {
        return [['v' => 1, 'stage' => 'link', 'mac' => la_mac($tok), 'exp' => $exp], null];
    }, true);
    $path = la_key_path();
    if (!la_acl_lock($path)) {
        @unlink($path);
        throw new RuntimeException('cannot restrict the ACL of ' . LA_KEY_FILE);
    }
    return ['token' => $tok, 'exp' => $exp, 'path' => $path];
}

/** 링크 열쇠 → 세션 쿠키 값. 맞으면 새 쿠키 원문(64hex), 아니면 null. 링크 열쇠는 한 번만 바뀐다(단계가 session 으로 넘어간다). */
function la_key_exchange(string $k): ?string
{
    if (!la_tok_shape_ok($k)) {
        return null;
    }
    $cookie = bin2hex(random_bytes(32));
    return la_key_locked_update(function ($rec) use ($k, $cookie) {
        if (!is_array($rec) || ($rec['stage'] ?? '') !== 'link' || !is_int($rec['exp'] ?? null) || $rec['exp'] <= time()
            || !is_string($rec['mac'] ?? null) || !hash_equals($rec['mac'], la_mac($k))) {   // KEY-CHECK
            return [null, null];
        }
        return [['v' => 1, 'stage' => 'session', 'mac' => la_mac($cookie), 'exp' => time() + LA_KEY_TTL], $cookie];
    }, false);
}

/** 쿠키 값이 살아 있는 세션 단계의 값인가. */
function la_session_ok(string $cookie): bool
{
    if (!la_tok_shape_ok($cookie)) {
        return false;
    }
    $raw = @file_get_contents(la_key_path());
    $rec = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    return is_array($rec) && ($rec['stage'] ?? '') === 'session' && is_int($rec['exp'] ?? null) && $rec['exp'] > time()
        && is_string($rec['mac'] ?? null) && hash_equals($rec['mac'], la_mac($cookie));   // SESSION-CHECK
}

/** 폐기 — 파일을 지운다. 남아 있으면(지울 수 없으면) 내용을 비워 무효로 만든다. */
function la_key_revoke(): void
{
    $path = la_key_path();
    if (is_file($path) && !@unlink($path)) {
        $e = error_get_last();
        error_log('[ulogin] local-admin key unlink failed: ' . (is_array($e) ? (string) $e['message'] : '?'));
        @file_put_contents($path, '');
    }
}

/** Set-Cookie 헤더 한 줄(PHP 7.2 의 setcookie 옵션 배열을 쓰지 않는다). Secure 는 붙이지 않는다 — 평문 루프백 화면이다. */
function la_cookie_header(string $val, int $maxAge): string
{
    return 'Set-Cookie: ' . LA_COOKIE . '=' . $val
        . '; Expires=' . gmdate('D, d M Y H:i:s', $maxAge > 0 ? time() + $maxAge : 0) . ' GMT'
        . '; Max-Age=' . max(0, $maxAge) . '; Path=' . LA_COOKIE_PATH . '; HttpOnly; SameSite=Strict';
}

/**
 * 링크를 만들 포트: 환경 ULOGIN_PORT → config LOCAL_ADMIN_PORT(선택 상수) → BASE_URL 이 루프백 주소면 그 포트 → 8080.
 */
function la_local_port(): int
{
    $env = getenv('ULOGIN_PORT');
    if (is_string($env) && preg_match('/^[0-9]{1,5}\z/', $env) === 1 && (int) $env >= 1 && (int) $env <= 65535) {
        return (int) $env;
    }
    if (defined('LOCAL_ADMIN_PORT') && is_int(LOCAL_ADMIN_PORT) && LOCAL_ADMIN_PORT >= 1 && LOCAL_ADMIN_PORT <= 65535) {
        return LOCAL_ADMIN_PORT;
    }
    if (defined('BASE_URL') && is_string(BASE_URL)
        && preg_match('#^http://(127\.0\.0\.1|localhost|\[::1\]):([0-9]{1,5})(/|\z)#i', BASE_URL, $m) === 1
        && (int) $m[2] >= 1 && (int) $m[2] <= 65535) {
        return (int) $m[2];
    }
    return LA_PORT_DEFAULT;
}

function la_link_url(string $tok, int $port): string
{
    return 'http://127.0.0.1:' . $port . '/local-admin?k=' . $tok;   // 라우터의 고정 철자(쿠키 Path 상수와 따로 둔다)
}
