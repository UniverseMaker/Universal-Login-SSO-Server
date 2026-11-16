<?php
declare(strict_types=1);
/**
 * /local-admin — 비상 관리자 계정 로컬 설정 화면 (v1.10.1 D-374, D-367 Q-5 「CLI 만」을 보완)
 * 담당 판정: `_workspace/128_leader_decisions_v1100.md` D-374 · D-373(10자)
 *
 * 열리는 조건 = core/localonly.php ulogin_local_request_ok() 의 세 조건 **전부**(루프백 REMOTE_ADDR · 루프백 Host+포트 ·
 * 프록시/터널 헤더 0개). 하나라도 어긋나면 404 — 화면이 있다는 사실도 알리지 않는다. 라우터가 먼저 보고 여기서 한 번 더 본다.
 * 공용 호스팅(Apache)에서는 server/.htaccess 허용 목록(api/·install·migrate)에 없어 404 다 — 그쪽은 CLI 만 쓴다.
 *
 * 동작: 상태(미설정·설정됨·잠김·계정 없음) · 정하기(계정이 없으면 init 포함) · 바꾸기 · 잠김 풀기.
 *   처리 함수는 CLI(server/tools/emergency_admin.php)와 **같은** core/emergency.php em_local_*() 다.
 * 방어: CSRF 1회용 토큰(10분, DB 에는 sha256 만) · Origin(없으면 Referer)이 같은 루프백 origin · 빈도 제한 ·
 *   Cache-Control: no-store · 값(비밀번호)은 응답·로그·감사 어디에도 남기지 않는다(감사는 [REDACTED]).
 * 화면은 public/ 의 공용 틀(G16 RUI.login + #ul-pane)을 쓰고, 스크립트·스타일은 assets/local-admin.{js,css}(인라인 없음).
 *
 * v1.10.1 보안 M-2 (D-375) — **1회용 열쇠 링크.** 로컬 조건만으로는 같은 PC 의 다른 계정·샌드박스를 막지 못한다.
 *   열쇠는 서버 PC 에서 `tools\emergency-admin.bat open` 이 만들고 브라우저로 `?k=…` 를 연다(core/localkey.php).
 *   · k 가 맞으면 Set-Cookie(ULOGIN_LA, HttpOnly · SameSite=Strict · Path=/local-admin · 10분) + 302 → k 없는 주소
 *   · 쿠키가 없거나 틀리면(로컬 조건은 통과) 폼·상태를 숨기고 「bat open 을 실행하세요」 안내만. POST 는 403
 *   · 로컬 조건을 못 넘으면 지금처럼 404
 *   · 비밀번호를 정하는 데 성공하면 열쇠 파일과 쿠키를 폐기한다
 */

require_once __DIR__ . '/core/localonly.php';

if (!ulogin_local_request_ok($_SERVER)) {
    header_remove('X-Powered-By');
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo "404 Not Found\n";
    exit;
}

define('ULOGIN_LOCAL_ONLY_PAGE', 1);   // bootstrap 의 FORCE_HTTPS 리다이렉트 제외 — 위 판정을 통과한 루프백 요청에만 선다
require_once __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/core/localkey.php';   // v1.10.1 M-2 — 1회용 열쇠 링크

const LA_TOKEN_TTL = 600;
const LA_VIA       = 'local-admin web';

header('Cache-Control: no-store');
header('CDN-Cache-Control: no-store');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; "
    . "connect-src 'self'; object-src 'none'; frame-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");

/** 1회용 CSRF 토큰 — rate_limits 표에 `la:csrf:<sha256>` 행으로 둔다(새 표 없이, gc 가 만료 행을 지운다). */
function la_token_issue(): string
{
    $t = gen_token(24);
    $now = time();
    db_insert(T_RATE_LIMITS, [
        'bucket'       => 'la:csrf:' . token_hash($t),
        'window_start' => gmdate('Y-m-d H:i:s', $now),
        'count'        => 0,
        'expires_at'   => gmdate('Y-m-d H:i:s', $now + LA_TOKEN_TTL),
    ]);
    return $t;
}

/** 소모 — 지운 행이 정확히 1이어야 통과(같은 토큰 두 번째는 0행). */
function la_token_consume(string $t): bool
{
    if (preg_match('/^[0-9a-f]{48}$/', $t) !== 1) {
        return false;
    }
    return db_exec('DELETE FROM ' . T_RATE_LIMITS . ' WHERE bucket = ? AND expires_at > ?',
        ['la:csrf:' . token_hash($t), now_utc()]) === 1;
}

/** Origin(없으면 Referer)이 이 요청의 Host 와 같은 루프백 origin 인가. 둘 다 없으면 거절. */
function la_origin_ok(): bool
{
    $self = ulogin_local_host_origin((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($self === null) {
        return false;
    }
    $o = $_SERVER['HTTP_ORIGIN'] ?? null;
    if (is_string($o) && $o !== '') {
        return strtolower($o) === $self;
    }
    $r = $_SERVER['HTTP_REFERER'] ?? null;
    if (is_string($r) && $r !== '') {
        $l = strtolower($r);
        return $l === $self || strpos($l, $self . '/') === 0;
    }
    return false;
}

function la_h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function la_asset_ver(): string
{
    $j = @file_get_contents(__DIR__ . '/../public/version.json');
    $d = is_string($j) ? json_decode($j, true) : null;
    $v = is_array($d) && isset($d['version']) ? (string) $d['version'] : '';
    return preg_match('/^[0-9A-Za-z._-]{1,32}$/', $v) === 1 ? $v : '0';
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$flash  = null;   // ['ok'|'bad', 문장]
$status = 200;
$authed = false;  // v1.10.1 M-2 — 열쇠 쿠키가 맞을 때만 true
const LA_OPEN_HINT = '서버 PC에서 tools\emergency-admin.bat open 을 실행하세요.';

try {
    if ($method !== 'GET' && $method !== 'POST') {
        http_response_code(405);
        header('Allow: GET, POST');
        exit;
    }
    // v1.10.1 M-2 — 링크 열쇠 교환(1회용). 맞으면 쿠키로 바꾸고 주소에서 k 를 지운다.
    if ($method === 'GET' && array_key_exists('k', $_GET)) {
        rate_limit('la:key:' . client_ip(), 20, 300);
        $cookieTok = la_key_exchange(is_string($_GET['k']) ? $_GET['k'] : '');
        if ($cookieTok !== null) {
            header(la_cookie_header($cookieTok, LA_KEY_TTL), false);
            header('Location: /local-admin', true, 302);
            exit;
        }
        $status = 403;
        $flash  = ['bad', '열쇠가 맞지 않거나, 만료됐거나(10분), 이미 쓰였습니다. ' . LA_OPEN_HINT];
    }
    $authed = la_session_ok(is_string($_COOKIE[LA_COOKIE] ?? null) ? $_COOKIE[LA_COOKIE] : '');
    if (!db_column_exists(T_USERS, 'account_kind')) {
        throw new EmLocalError('state', '마이그레이션 0010 이 적용되지 않았습니다. php server/migrate.php apply 를 먼저 실행하세요.');
    }
    if ($method === 'POST') {
        rate_limit('la:post:' . client_ip(), 10, 300);
        $tok = (string) ($_POST['t'] ?? '');
        if (!$authed) {   // LA-AUTH — 열쇠 없이 온 POST 는 아무것도 하지 않는다(CSRF 토큰보다 먼저)
            $status = 403;
            $flash  = ['bad', '열쇠가 없거나 만료됐습니다(10분). ' . LA_OPEN_HINT . ' 아무것도 바꾸지 않았습니다.'];
        } elseif (!la_origin_ok()) {
            $status = 403;
            $flash  = ['bad', '요청 출처를 확인할 수 없습니다. 이 화면을 다시 열어 주세요.'];
        } elseif (!la_token_consume($tok)) {
            $status = 403;
            $flash  = ['bad', '화면이 만료됐습니다(10분 · 한 번만 쓸 수 있음). 다시 시도해 주세요.'];
        } else {
            $act = (string) ($_POST['act'] ?? '');
            $u   = em_local_row();
            if ($act === 'set') {
                $pw  = (string) ($_POST['pw'] ?? '');
                $pw2 = (string) ($_POST['pw2'] ?? '');
                if ($pw === '' || !hash_equals($pw, $pw2)) {
                    $status = 400;
                    $flash  = ['bad', '두 입력이 같지 않거나 비어 있습니다. 아무것도 바꾸지 않았습니다.'];
                } else {
                    try {
                        // 계정이 없으면 만들기부터(CLI --init 과 같은 함수). 정책 위반이면 만든 계정은 미설정으로 남는다.
                        if ($u === null) {
                            validate_password($pw, EM_LOCAL_USERNAME, 'password', rank_of(ROLE_RANK, 'superadmin'));
                            $u = em_local_init(EM_LOCAL_USERNAME, LA_VIA);
                        }
                        $mode = em_local_state($u) === 'unset' ? 'set-password' : 'reset';
                        $r = em_local_set_password($u, $pw, $mode, LA_VIA);
                        // v1.10.1 M-2 — 정하기에 성공하면 열쇠와 쿠키를 폐기한다(다시 바꾸려면 bat open 부터)
                        la_key_revoke();
                        header(la_cookie_header('', 0), false);
                        $authed = false;
                        $flash = ['ok', ($mode === 'reset' ? '비밀번호를 바꿨습니다.' : '비밀번호를 정했습니다.')
                            . ' 세션 ' . $r['revoked'] . '개를 끊었고 잠금과 로그인 빈도 제한을 풀었습니다. 이 열쇠는 폐기했습니다.'];
                    } catch (AppError $e) {
                        $status = 400;
                        $flash  = ['bad', '정책 위반: ' . (string) ($e->extra['message'] ?? $e->code) . ' 아무것도 바꾸지 않았습니다.'];
                    } finally {
                        $pw = $pw2 = str_repeat("\0", 16);
                        unset($pw, $pw2, $_POST['pw'], $_POST['pw2']);
                    }
                }
            } elseif ($act === 'unlock') {
                if ($u === null) {
                    $status = 409;
                    $flash  = ['bad', '비상 계정이 아직 없습니다.'];
                } else {
                    $n = em_local_unlock($u, LA_VIA);
                    $flash = ['ok', '잠김을 풀었습니다(로그인 빈도 제한 ' . $n . '건 삭제).'];
                }
            } else {
                $status = 400;
                $flash  = ['bad', '알 수 없는 동작입니다.'];
            }
        }
    } else {
        rate_limit('la:get:' . client_ip(), 60, 300);
    }
} catch (EmLocalError $e) {
    $status = 409;
    $flash  = ['bad', $e->getMessage()];
} catch (AppError $e) {
    $status = (int) $e->status;
    $flash  = ['bad', $e->code === 'RATE_LIMITED' ? '요청이 너무 많습니다. 잠시 후 다시 시도해 주세요.' : '처리하지 못했습니다(' . $e->code . ').'];
} catch (Throwable $e) {
    error_log('[ulogin] local-admin: ' . get_class($e) . ': ' . $e->getMessage());
    $status = 500;
    $flash  = ['bad', '처리 중 오류가 났습니다. 서버 로그를 확인하세요. 명령줄 도구는 그대로 쓸 수 있습니다.'];
}

// ---- 화면 ------------------------------------------------------------
// v1.10.1 M-2 — 열쇠가 없으면 상태·폼을 그리지 않는다(CSRF 토큰도 만들지 않는다 — DB 에 아무것도 쓰지 않는다).
$u = null;
$token = '';
try {
    if ($authed) {
        $u = em_local_row();
        $token = la_token_issue();
    }
} catch (Throwable $e) {
    error_log('[ulogin] local-admin render: ' . get_class($e) . ': ' . $e->getMessage());
    $status = $status === 200 ? 500 : $status;
}
$state  = $u === null ? 'none' : em_local_state($u);
$locked = $u !== null && em_local_locked($u);
$badge  = $u === null ? ['pending', '계정 없음'] : ($locked ? ['suspended', '잠김'] : ($state === 'set' ? ['active', '설정됨'] : ['pending', '미설정']));
$name   = $u === null ? EM_LOCAL_USERNAME : (string) $u['username'];
$setLabel = $state === 'set' ? '비밀번호 바꾸기' : '비밀번호 정하기';
$v = la_asset_ver();

http_response_code($status);
header('Content-Type: text/html; charset=UTF-8');
?><!DOCTYPE html>
<html lang="ko" data-rui-font>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>비상 관리자 계정 · ulogin</title>
<script src="/assets/rui-ulogin-pre.js?v=<?= la_h($v) ?>"></script>
<script src="/assets/rui-theme.js?v=<?= la_h($v) ?>"></script>
<link rel="stylesheet" href="/assets/rui-tokens.css?v=<?= la_h($v) ?>">
<link rel="stylesheet" href="/assets/rui-layer.css?v=<?= la_h($v) ?>">
<link rel="stylesheet" href="/assets/style.css?v=<?= la_h($v) ?>">
<link rel="stylesheet" href="/assets/ulogin-theme.css?v=<?= la_h($v) ?>">
<link rel="stylesheet" href="/assets/local-admin.css?v=<?= la_h($v) ?>">
<script src="/assets/theme-ui.js?v=<?= la_h($v) ?>" defer></script>
</head>
<body class="ul-auth-body">
<div id="ul-shell" class="rui ul-rui"></div>
<div class="ul-own ul-pane" id="ul-pane">
  <section class="card auth-card la-card" id="la-card">
    <div class="card-head">
      <h1>비상 관리자 계정</h1>
    </div>
    <div class="card-body">
<?php if ($authed): ?>
      <p class="la-status">아이디 <b><?= la_h($name) ?></b>
        <span class="badge badge-status" id="la-badge" data-value="<?= la_h($badge[0]) ?>"><?= la_h($badge[1]) ?></span></p>
<?php endif; ?>
      <ul class="la-notes">
        <li>서버 PC에서만 열리는 화면(공개 주소·터널로는 열리지 않음)</li>
        <li>통합로그인 관리자 계정을 잃었을 때 콘솔에 들어가는 비상용 계정(최고관리자 · 세션 1시간 · SSO 불가)</li>
        <li>10자 이상, 흔한 비밀번호 거절. 값은 해시로만 저장되고 화면·기록에 남지 않음</li>
        <li>정하거나 바꾸면 이 계정의 기존 세션은 모두 끊기고 잠김도 풀림</li>
      </ul>
<?php if ($flash !== null): ?>
      <div class="alert <?= $flash[0] === 'ok' ? 'alert-success' : 'alert-danger' ?> la-flash" role="<?= $flash[0] === 'ok' ? 'status' : 'alert' ?>">
        <div class="alert-body"><?= la_h($flash[1]) ?></div>
      </div>
<?php endif; ?>
<?php if (!$authed): ?>
      <div class="alert alert-info la-guide" id="la-guide" role="note">
        <div class="alert-body">이 화면은 1회용 열쇠 링크로만 엽니다. <?= la_h(LA_OPEN_HINT) ?>
          명령이 기본 브라우저로 열쇠 링크를 엽니다(10분 · 한 번만 쓸 수 있음).</div>
      </div>
<?php endif; ?>
<?php if ($authed && $token !== ''): ?>
      <form method="post" action="/local-admin" class="form la-form" id="la-set" autocomplete="off">
        <input type="hidden" name="t" value="<?= la_h($token) ?>">
        <input type="hidden" name="act" value="set">
        <div class="field">
          <label for="la-pw">새 비밀번호</label>
          <div class="la-pw-row">
            <input type="password" id="la-pw" name="pw" autocomplete="new-password" required minlength="10" maxlength="200"
                   placeholder="10자 이상" aria-describedby="la-strength-text">
            <button type="button" class="btn btn-ghost btn-sm la-eye" data-la-eye="la-pw" aria-pressed="false" aria-controls="la-pw">보기</button>
          </div>
          <div class="strength" id="la-strength" data-level="0">
            <div class="strength-bar"><i></i></div>
            <div class="strength-text" id="la-strength-text" role="status" aria-live="polite"></div>
          </div>
        </div>
        <div class="field">
          <label for="la-pw2">새 비밀번호 확인</label>
          <div class="la-pw-row">
            <input type="password" id="la-pw2" name="pw2" autocomplete="new-password" required minlength="10" maxlength="200"
                   placeholder="한 번 더">
            <button type="button" class="btn btn-ghost btn-sm la-eye" data-la-eye="la-pw2" aria-pressed="false" aria-controls="la-pw2">보기</button>
          </div>
          <span class="hint" id="la-match" role="status" aria-live="polite"></span>
        </div>
        <button type="submit" class="btn btn-primary" id="la-set-btn"><?= la_h($setLabel) ?></button>
      </form>
<?php if ($u !== null): ?>
      <form method="post" action="/local-admin" class="la-form la-unlock" id="la-unlock">
        <input type="hidden" name="t" value="<?= la_h($token) ?>">
        <input type="hidden" name="act" value="unlock">
        <button type="submit" class="btn btn-secondary"<?= $locked || (int) ($u['failed_login_count'] ?? 0) > 0 ? '' : ' disabled' ?>>잠김 풀기</button>
        <span class="text-xs text-muted">실패 <?= (int) ($u['failed_login_count'] ?? 0) ?>회<?= $locked ? ' · 잠김' : '' ?></span>
      </form>
<?php endif; ?>
<?php endif; ?>
      <p class="la-cli text-xs text-muted">이 화면 열기: <code>tools\emergency-admin.bat open</code> ·
        명령줄(같은 처리): <code>tools\emergency-admin.bat --status</code> ·
        <code>--set-password --apply</code> · <code>--reset --apply</code> · <code>--unlock --apply</code> · <code>--unset --apply</code></p>
    </div>
  </section>
</div>
<script src="/assets/ulogin-shell.js?v=<?= la_h($v) ?>"></script>
<script src="/assets/local-admin.js?v=<?= la_h($v) ?>"></script>
</body>
</html>
