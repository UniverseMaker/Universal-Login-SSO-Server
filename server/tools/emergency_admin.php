<?php
declare(strict_types=1);

// CLI 전용. 웹으로 열리면 아무것도 하지 않고 404.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * server/tools/emergency_admin.php — 비상 관리자 계정 local-admin/1 (v1.10.0)
 * 담당 계약: `_workspace/127_architect_v1100_design.md` §6 · 판정 D-367 Q-5
 *
 *   php server/tools/emergency_admin.php --init [--username=아이디] [--apply]
 *                                                                    비상 계정을 만든다(미설정 상태). 아이디 기본 admin
 *                                                                    (v1.10.0 감사 M-4 / D-372 — --username 은 --init 에서만, 비밀값이 아니다)
 *   php server/tools/emergency_admin.php --set-password [--apply]    비밀번호를 정한다(미설정 → 설정)
 *   php server/tools/emergency_admin.php --reset [--apply]           비밀번호를 바꾼다(설정 → 새 값)
 *   php server/tools/emergency_admin.php --unset [--apply]           다시 미설정(로그인 불가) + 세션 회수
 *   php server/tools/emergency_admin.php --unlock [--apply]          실패 잠금 해제 + 이 계정의 로그인 빈도 버킷 삭제
 *     (v1.10.0 감사 M-4 — --set-password·--reset 도 빈도 버킷을 지운다. 잠금만 풀면 `login:user:` 5/300 이 남아 계속 429)
 *   php server/tools/emergency_admin.php --status                    set|unset · 잠금 · 마지막 로그인(값은 내지 않는다)
 *   php server/tools/emergency_admin.php --open-link [--no-browser]  (v1.10.1 보안 M-2 / D-375) 로컬 화면 /local-admin 의
 *                                                                    1회용 열쇠(10분)를 만들고 기본 브라우저로 링크를 연다.
 *                                                                    열쇠 원문은 링크에만 있고 파일(runtime/secrets/local-admin.key)에는
 *                                                                    HMAC 만 남는다. --no-browser 면 열지 않고 링크를 출력한다.
 *                                                                    DB 를 바꾸지 않으므로 --apply 가 필요 없다. bat 의 `open` 이 이것이다.
 *
 * 「서버 PC 에서만」 = 이 셸이다(§6-1). v1.10.1 의 웹 화면 /local-admin 은 이 셸이 --open-link 로 만든 1회용 열쇠가
 * 있어야 열린다(M-2 / D-375) — 터널·같은 PC 의 다른 계정은 루프백으로 들어와도 열쇠 파일을 쓸 수도 읽을 수도 없다.
 * 비밀번호는 **표준입력으로 두 번** 받는다. argv·환경변수·파일로는 받지 않는다(프로세스 목록·기록에 남는다).
 *   터미널이면 화면에 보이지 않게 받는다(Windows: powershell Read-Host -AsSecureString, POSIX: stty -echo).
 *   파이프면 두 줄을 그대로 읽는다(자동 시험용). 어떤 경우에도 값을 출력·기록하지 않는다.
 * 관리자 비밀번호 정책(v1.9.1, v1.10.1 D-373 부터 10자 이상·흔한 패턴 거절)을 그대로 적용한다. password_epoch+1 · 세션 전부 회수 · 잠금 해제.
 * 기본은 dry-run(DB 무변경). `--apply` 로만 쓴다(--status 제외).
 * 감사(체인, 값 제외): emergency.create · emergency.password.set|reset|unset · emergency.unlock. 행위자는 system(cli).
 * 종료코드 0 정상 / 1 실패(정책 위반·불일치 포함) / 2 인자 / 3 전제 불충족(admin 이 이미 다른 계정 · 비상 계정 없음 · 상태 불일치)
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/localkey.php';   // v1.10.1 M-2 — --open-link

const EA_USERNAME = 'admin';
const EA_EMAIL    = 'admin@emergency.ulogin.invalid';

function ea_say(string $s): void
{
    fwrite(STDOUT, $s . "\n");
}

function ea_die(int $code, string $s): void
{
    fwrite(STDERR, $s . "\n");
    exit($code);
}

$opt = [];
$initUser = null;   // v1.10.0 감사 M-4 — --init 의 아이디(선택). 값을 받는 인자는 이것 하나뿐이다
foreach (array_slice($argv, 1) as $a) {
    if (strpos($a, '--username=') === 0) {
        $initUser = normalize_username(substr($a, strlen('--username=')));
        if (preg_match('/^[a-z0-9_]{4,20}$/', $initUser) !== 1) {
            ea_die(2, '--username 은 영문 소문자·숫자·밑줄 4~20자입니다.');
        }
        continue;
    }
    if (preg_match('/^--([a-z\-]+)$/', $a, $m) !== 1) {
        // 값을 붙인 인자(--x=…)는 받지 않는다 — 비밀번호를 argv 로 넘기는 습관을 막는다
        ea_die(2, '알 수 없는 인자: ' . (strpos($a, '=') !== false ? substr($a, 0, (int) strpos($a, '=')) . '=…' : substr($a, 0, 40)));
    }
    $opt[$m[1]] = true;
}
$acts = array_values(array_intersect(array_keys($opt), ['init', 'set-password', 'reset', 'unset', 'unlock', 'status', 'open-link']));
foreach (array_keys($opt) as $k) {
    if (!in_array($k, ['init', 'set-password', 'reset', 'unset', 'unlock', 'status', 'open-link', 'no-browser', 'apply'], true)) {
        ea_die(2, '알 수 없는 옵션: --' . $k);
    }
}
if (count($acts) !== 1) {
    ea_die(2, '--init | --set-password | --reset | --unset | --unlock | --status | --open-link 중 하나만 주세요.');
}
if (isset($opt['no-browser']) && $acts[0] !== 'open-link') {
    ea_die(2, '--no-browser 는 --open-link 와 함께만 씁니다.');
}
$act   = $acts[0];
$apply = isset($opt['apply']);
if ($initUser !== null && $act !== 'init') {
    ea_die(2, '--username 은 --init 과 함께만 씁니다.');
}
$eaName = $initUser ?? EA_USERNAME;

if (!db_column_exists(T_USERS, 'account_kind')) {
    ea_die(3, '마이그레이션 0010 이 적용되지 않았습니다. php server/migrate.php apply 를 먼저 실행하세요.');
}

// ---- --open-link (v1.10.1 M-2) -----------------------------------------
// 열쇠 원문은 링크에만 넣는다. 기본은 브라우저로 열고(Windows: start ""), 링크를 화면에 찍지 않는다.
if ($act === 'open-link') {
    try {
        $key = la_key_issue();
    } catch (Throwable $e) {
        ea_die(1, 'open-link: 열쇠 파일을 만들 수 없습니다(' . $e->getMessage() . '). 아무 링크도 만들지 않았습니다.');
    }
    $url = la_link_url($key['token'], la_local_port());
    ea_say('open-link: 열쇠를 만들었습니다 — 10분 안에, 한 번만 쓸 수 있습니다. 파일: ' . $key['path'] . ' (HMAC 만 저장)');
    if (isset($opt['no-browser'])) {
        ea_say('url: ' . $url);
        exit(0);
    }
    $opened = false;
    if (DIRECTORY_SEPARATOR === '\\') {
        // URL 은 [0-9a-z:/.?=] 만이라 따옴표 안에서 cmd 메타문자가 없다. start 의 첫 따옴표 인자는 창 제목 자리다.
        $ph = @popen('start "" "' . $url . '"', 'r');
        if (is_resource($ph)) {
            pclose($ph);
            $opened = true;
        }
    } else {
        foreach (['xdg-open', 'open'] as $bin) {
            $rc = 1;
            $o  = [];
            @exec('command -v ' . $bin . ' >/dev/null 2>&1 && ' . $bin . ' ' . escapeshellarg($url) . ' >/dev/null 2>&1', $o, $rc);
            if ($rc === 0) {
                $opened = true;
                break;
            }
        }
    }
    if (!$opened) {
        ea_say('open-link: 브라우저를 열지 못했습니다. 아래 링크를 서버 PC 의 브라우저 주소창에 붙여 넣으세요.');
        ea_say('url: ' . $url);
        exit(0);
    }
    ea_say('open-link: 기본 브라우저로 ' . la_link_url('…', la_local_port()) . ' 를 열었습니다. 비밀번호를 정하면 열쇠는 폐기됩니다.');
    exit(0);
}

/** 비상 계정 행 · 상태 — v1.10.1 D-374: 본체는 core/emergency.php(로컬 화면과 같은 함수). */
function ea_row(): ?array
{
    return em_local_row();
}

function ea_state(array $u): string
{
    return em_local_state($u);
}

function ea_print_status(array $u): void
{
    $lockTs = ts($u['locked_until'] ?? null);
    ea_say('status: username=' . (string) $u['username'] . ' kind=' . (string) ($u['account_kind'] ?? 'normal')
        . ' role=' . (string) $u['role'] . ' account=' . (string) $u['status']
        . ' password=' . ((string) ($u['account_kind'] ?? '') === 'emergency' ? ea_state($u) : '(일반 계정 — 표시하지 않음)'));
    ea_say('status: locked=' . ($lockTs !== null && $lockTs > time() ? 'yes(' . iso($u['locked_until']) . ')' : 'no')
        . ' failed_login_count=' . (int) ($u['failed_login_count'] ?? 0)
        . ' last_login_at=' . (($u['last_login_at'] ?? null) === null ? '-' : (string) iso($u['last_login_at'])));
}

const EA_VIA = 'cli emergency_admin';   // 감사 memo — 경로 표시(값 아님)

/** 숨김 입력 한 줄. 터미널이 아니면(파이프) 그대로 한 줄. 끝 개행만 뗀다. */
function ea_read_secret(string $prompt): string
{
    $tty = function_exists('stream_isatty') ? @stream_isatty(STDIN) : (function_exists('posix_isatty') ? @posix_isatty(STDIN) : false);
    if (!$tty) {
        $l = fgets(STDIN);
        return $l === false ? '' : rtrim($l, "\r\n");
    }
    if (DIRECTORY_SEPARATOR === '\\') {
        // powershell 이 콘솔에서 가려 받고, 평문은 파이프로만 넘어온다(화면·명령줄에 남지 않는다)
        $ps = '$s = Read-Host -AsSecureString -Prompt \'' . str_replace("'", "''", $prompt) . '\';'
            . ' $b = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($s);'
            . ' try { [Console]::Out.Write([Runtime.InteropServices.Marshal]::PtrToStringBSTR($b)) }'
            . ' finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($b) }';
        $desc = [0 => STDIN, 1 => ['pipe', 'w'], 2 => STDERR];
        $pipes = [];
        // PHP 7.2 호환: proc_open 배열 인자(7.4+)를 쓰지 않는다. 따옴표 문제를 없애려고 -EncodedCommand(UTF-16LE base64).
        $enc = base64_encode((string) mb_convert_encoding($ps, 'UTF-16LE', 'UTF-8'));
        $p = proc_open('powershell -NoProfile -EncodedCommand ' . $enc, $desc, $pipes);
        if (!is_resource($p)) {
            ea_die(1, '숨김 입력을 열 수 없습니다(powershell). 파이프로 두 줄을 넣어 실행하세요.');
        }
        $v = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($p);
        return rtrim($v, "\r\n");
    }
    fwrite(STDERR, $prompt . ': ');
    @shell_exec('stty -echo 2>/dev/null');
    $l = fgets(STDIN);
    @shell_exec('stty echo 2>/dev/null');
    fwrite(STDERR, "\n");
    return $l === false ? '' : rtrim($l, "\r\n");
}

/** 두 번 받아 정책을 적용한다. 실패하면 종료코드 1(값은 출력하지 않는다). */
function ea_new_password(string $username): string
{
    $a = ea_read_secret('새 비밀번호');
    $b = ea_read_secret('새 비밀번호 확인');
    if ($a === '' || !hash_equals($a, $b)) {
        ea_die(1, '두 입력이 같지 않거나 비어 있습니다. 아무것도 바꾸지 않았습니다.');
    }
    try {
        validate_password($a, $username, 'password', rank_of(ROLE_RANK, 'superadmin'));   // v1.9.1 관리자 정책(v1.10.1 D-373: 10자+)
    } catch (AppError $e) {
        ea_die(1, '정책 위반: ' . (string) ($e->extra['message'] ?? $e->code) . ' 아무것도 바꾸지 않았습니다.');
    }
    return $a;
}

ea_say($act === 'status' ? '[STATUS]' : ($apply ? '[APPLY] DB 를 바꿉니다.' : '[DRY-RUN] DB 를 바꾸지 않습니다. 실행은 --apply.'));

// ---- --init ----------------------------------------------------------
// v1.10.1 D-374 — 처리 본체는 core/emergency.php 의 em_local_*() (로컬 화면 /local-admin 과 같은 함수)
if ($act === 'init') {
    $taken = db_one('SELECT * FROM ' . T_USERS . ' WHERE username = ?', [$eaName]);
    $other = ea_row();
    if (!$apply) {
        if ($taken !== null || $other !== null) {
            ea_print_status($taken !== null ? $taken : $other);
            ea_die(3, $taken !== null ? 'init: 아이디 ' . $eaName . ' 가 이미 있습니다. 아무것도 바꾸지 않았습니다.'
                : 'init: 비상 계정이 이미 있습니다. 아무것도 바꾸지 않았습니다.');
        }
        if (db_val('SELECT id FROM ' . T_USERS . ' WHERE email = ?', [EA_EMAIL]) !== null) {
            ea_die(3, 'init: 예약 이메일이 다른 계정에 있습니다. 아무것도 바꾸지 않았습니다.');
        }
        ea_say('init: 만들 계정 username=' . $eaName . ' role=superadmin kind=emergency password=unset (dry-run)');
        exit(0);
    }
    try {
        em_local_init($eaName, EA_VIA);
    } catch (EmLocalError $e) {
        if ($taken !== null || $other !== null) {
            ea_print_status($taken !== null ? $taken : $other);
        }
        ea_die(3, $e->getMessage());
    }
    ea_say('init: 완료 — 비상 계정을 미설정 상태로 만들었습니다. 다음: --set-password --apply');
    exit(0);
}

$u = ea_row();
if ($u === null) {
    ea_die(3, '비상 계정이 없습니다. 먼저 --init --apply 를 실행하세요.');
}

if ($act === 'status') {
    ea_print_status($u);
    exit(0);
}

if ($act === 'set-password' || $act === 'reset') {
    $state = ea_state($u);
    if ($act === 'set-password' && $state !== 'unset') {
        ea_die(3, 'set-password: 이미 설정돼 있습니다. 바꾸려면 --reset 을 쓰세요.');
    }
    if ($act === 'reset' && $state !== 'set') {
        ea_die(3, 'reset: 아직 미설정입니다. --set-password 를 쓰세요.');
    }
    if (!$apply) {
        ea_say($act . ': 대상 ' . (string) $u['username'] . ' (' . $state . ' → set). 비밀번호는 --apply 때 표준입력으로 받습니다.');
        exit(0);
    }
    $pw = ea_new_password((string) $u['username']);
    try {
        $r = em_local_set_password($u, $pw, $act, EA_VIA);
    } catch (EmLocalError $e) {
        ea_die(3, $e->getMessage());
    } catch (AppError $e) {
        ea_die(1, '정책 위반: ' . (string) ($e->extra['message'] ?? $e->code) . ' 아무것도 바꾸지 않았습니다.');
    } finally {
        $pw = str_repeat("\0", strlen($pw));
        unset($pw);
    }
    ea_say($act . ': 완료 — 비밀번호를 정했습니다. 세션 ' . $r['revoked'] . '개를 끊었고 잠금과 로그인 빈도 제한(' . $r['cleared'] . '건)을 풀었습니다.');
    exit(0);
}

if ($act === 'unset') {
    if (!$apply) {
        ea_say('unset: 대상 ' . (string) $u['username'] . ' (' . ea_state($u) . ' → unset) (dry-run)');
        exit(0);
    }
    $revoked = em_local_unset($u, EA_VIA);
    ea_say('unset: 완료 — 미설정으로 되돌렸습니다(로그인 불가). 세션 ' . $revoked . '개를 끊었습니다.');
    exit(0);
}

// --unlock
if (!$apply) {
    ea_say('unlock: 대상 ' . (string) $u['username'] . ' failed_login_count=' . (int) $u['failed_login_count'] . ' (dry-run)');
    exit(0);
}
$cleared = em_local_unlock($u, EA_VIA);
ea_say('unlock: 완료 — 잠금과 로그인 빈도 제한(' . $cleared . '건)을 풀었습니다.');
exit(0);
