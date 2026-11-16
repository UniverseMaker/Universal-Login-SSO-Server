<?php
declare(strict_types=1);

// CLI 전용. 웹으로 열리면 아무것도 하지 않고 404.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * server/tools/emergency_admin.php — 비상 관리자 계정 local-admin/1 (v1.10.0)
 * 담당 계약: `_workspace/127_architect_v1100_design.md` §6 · 판정 D-367 Q-5
 *
 *   php server/tools/emergency_admin.php --init [--apply]            아이디 admin 으로 비상 계정을 만든다(미설정 상태)
 *   php server/tools/emergency_admin.php --set-password [--apply]    비밀번호를 정한다(미설정 → 설정)
 *   php server/tools/emergency_admin.php --reset [--apply]           비밀번호를 바꾼다(설정 → 새 값)
 *   php server/tools/emergency_admin.php --unset [--apply]           다시 미설정(로그인 불가) + 세션 회수
 *   php server/tools/emergency_admin.php --unlock [--apply]          실패 잠금 해제
 *   php server/tools/emergency_admin.php --status                    set|unset · 잠금 · 마지막 로그인(값은 내지 않는다)
 *
 * 「서버 PC 에서만」 = 이 셸이다(§6-1). 웹 화면은 없다 — 터널이 127.0.0.1 에서 들어와 웹에서는 로컬을 증명할 수 없다.
 * 비밀번호는 **표준입력으로 두 번** 받는다. argv·환경변수·파일로는 받지 않는다(프로세스 목록·기록에 남는다).
 *   터미널이면 화면에 보이지 않게 받는다(Windows: powershell Read-Host -AsSecureString, POSIX: stty -echo).
 *   파이프면 두 줄을 그대로 읽는다(자동 시험용). 어떤 경우에도 값을 출력·기록하지 않는다.
 * 관리자 비밀번호 정책(v1.9.1, 12자 이상·흔한 패턴 거절)을 그대로 적용한다. password_epoch+1 · 세션 전부 회수 · 잠금 해제.
 * 기본은 dry-run(DB 무변경). `--apply` 로만 쓴다(--status 제외).
 * 감사(체인, 값 제외): emergency.create · emergency.password.set|reset|unset · emergency.unlock. 행위자는 system(cli).
 * 종료코드 0 정상 / 1 실패(정책 위반·불일치 포함) / 2 인자 / 3 전제 불충족(admin 이 이미 다른 계정 · 비상 계정 없음 · 상태 불일치)
 */

require_once __DIR__ . '/../core/bootstrap.php';

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
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z\-]+)$/', $a, $m) !== 1) {
        // 값을 붙인 인자(--x=…)는 받지 않는다 — 비밀번호를 argv 로 넘기는 습관을 막는다
        ea_die(2, '알 수 없는 인자: ' . (strpos($a, '=') !== false ? substr($a, 0, (int) strpos($a, '=')) . '=…' : substr($a, 0, 40)));
    }
    $opt[$m[1]] = true;
}
$acts = array_values(array_intersect(array_keys($opt), ['init', 'set-password', 'reset', 'unset', 'unlock', 'status']));
foreach (array_keys($opt) as $k) {
    if (!in_array($k, ['init', 'set-password', 'reset', 'unset', 'unlock', 'status', 'apply'], true)) {
        ea_die(2, '알 수 없는 옵션: --' . $k);
    }
}
if (count($acts) !== 1) {
    ea_die(2, '--init | --set-password | --reset | --unset | --unlock | --status 중 하나만 주세요.');
}
$act   = $acts[0];
$apply = isset($opt['apply']);

if (!db_column_exists(T_USERS, 'account_kind')) {
    ea_die(3, '마이그레이션 0010 이 적용되지 않았습니다. php server/migrate.php apply 를 먼저 실행하세요.');
}

/** 비상 계정 행(account_kind=emergency). 없으면 null. */
function ea_row(): ?array
{
    return db_one('SELECT * FROM ' . T_USERS . ' WHERE account_kind = ? ORDER BY id ASC LIMIT 1', ['emergency']);
}

function ea_state(array $u): string
{
    return (string) $u['password_hash'] === EMERGENCY_UNSET_HASH ? 'unset' : 'set';
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

/** 감사 1건(값 제외). 행위자 system — admin_id 0, 체인의 sender_ip='cli'. */
function ea_audit(string $action, array $u, ?array $before, ?array $after): void
{
    log_admin(0, $action, 'user', (string) $u['uuid'], $before, $after, 'cli emergency_admin');
}

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
function ea_new_password(): string
{
    $a = ea_read_secret('새 비밀번호');
    $b = ea_read_secret('새 비밀번호 확인');
    if ($a === '' || !hash_equals($a, $b)) {
        ea_die(1, '두 입력이 같지 않거나 비어 있습니다. 아무것도 바꾸지 않았습니다.');
    }
    try {
        validate_password($a, EA_USERNAME, 'password', rank_of(ROLE_RANK, 'superadmin'));   // v1.9.1 관리자 정책(12자+)
    } catch (AppError $e) {
        ea_die(1, '정책 위반: ' . (string) ($e->extra['message'] ?? $e->code) . ' 아무것도 바꾸지 않았습니다.');
    }
    return $a;
}

ea_say($act === 'status' ? '[STATUS]' : ($apply ? '[APPLY] DB 를 바꿉니다.' : '[DRY-RUN] DB 를 바꾸지 않습니다. 실행은 --apply.'));

// ---- --init ----------------------------------------------------------
if ($act === 'init') {
    $taken = db_one('SELECT * FROM ' . T_USERS . ' WHERE username = ?', [EA_USERNAME]);
    if ($taken !== null) {
        ea_print_status($taken);
        ea_die(3, 'init: 아이디 ' . EA_USERNAME . ' 가 이미 있습니다. 아무것도 바꾸지 않았습니다.');
    }
    $other = ea_row();
    if ($other !== null) {
        ea_print_status($other);
        ea_die(3, 'init: 비상 계정이 이미 있습니다. 아무것도 바꾸지 않았습니다.');
    }
    if (db_val('SELECT id FROM ' . T_USERS . ' WHERE email = ?', [EA_EMAIL]) !== null) {
        ea_die(3, 'init: 예약 이메일이 다른 계정에 있습니다. 아무것도 바꾸지 않았습니다.');
    }
    if (!$apply) {
        ea_say('init: 만들 계정 username=' . EA_USERNAME . ' role=superadmin kind=emergency password=unset (dry-run)');
        exit(0);
    }
    $now = now_utc();
    $uuid = gen_uuid4();
    db_tx(function () use ($uuid, $now) {
        db_insert(T_USERS, [
            'uuid' => $uuid, 'username' => EA_USERNAME, 'email' => EA_EMAIL, 'password_hash' => EMERGENCY_UNSET_HASH,
            'name' => '비상 관리자', 'status' => 'active', 'account_level' => 'basic', 'role' => 'superadmin',
            'billing_tier' => 'free', 'account_kind' => 'emergency', 'created_at' => $now, 'updated_at' => $now,
        ]);
    });
    $u = ea_row();
    ea_audit('emergency.create', $u, null, ['username' => EA_USERNAME, 'role' => 'superadmin', 'state' => 'unset']);
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
    $pw = ea_new_password();
    $now = now_utc();
    $revoked = db_tx(function () use ($u, $pw, $now) {
        db_exec('UPDATE ' . T_USERS . ' SET password_hash = ?, password_changed_at = ?, password_epoch = password_epoch + 1,'
            . ' failed_login_count = 0, locked_until = NULL, updated_at = ? WHERE id = ? AND account_kind = ?',
            [password_hash($pw, PASSWORD_DEFAULT), $now, $now, (int) $u['id'], 'emergency']);
        return revoke_user_sessions((int) $u['id'], 'password_changed');
    });
    $pw = str_repeat("\0", strlen($pw));
    unset($pw);
    ea_audit($act === 'reset' ? 'emergency.password.reset' : 'emergency.password.set', $u,
        ['password' => '[REDACTED]', 'state' => $state], ['password' => '[REDACTED]', 'state' => 'set', 'revoked_sessions' => $revoked]);
    ea_say($act . ': 완료 — 비밀번호를 정했습니다. 세션 ' . $revoked . '개를 끊었고 잠금을 풀었습니다.');
    exit(0);
}

if ($act === 'unset') {
    if (!$apply) {
        ea_say('unset: 대상 ' . (string) $u['username'] . ' (' . ea_state($u) . ' → unset) (dry-run)');
        exit(0);
    }
    $before = ea_state($u);
    $now = now_utc();
    $revoked = db_tx(function () use ($u, $now) {
        db_exec('UPDATE ' . T_USERS . ' SET password_hash = ?, password_changed_at = ?, password_epoch = password_epoch + 1, updated_at = ?'
            . ' WHERE id = ? AND account_kind = ?', [EMERGENCY_UNSET_HASH, $now, $now, (int) $u['id'], 'emergency']);
        return revoke_user_sessions((int) $u['id'], 'password_changed');
    });
    ea_audit('emergency.password.unset', $u, ['password' => '[REDACTED]', 'state' => $before],
        ['password' => '[REDACTED]', 'state' => 'unset', 'revoked_sessions' => $revoked]);
    ea_say('unset: 완료 — 미설정으로 되돌렸습니다(로그인 불가). 세션 ' . $revoked . '개를 끊었습니다.');
    exit(0);
}

// --unlock
if (!$apply) {
    ea_say('unlock: 대상 ' . (string) $u['username'] . ' failed_login_count=' . (int) $u['failed_login_count'] . ' (dry-run)');
    exit(0);
}
db_exec('UPDATE ' . T_USERS . ' SET failed_login_count = 0, locked_until = NULL, updated_at = ? WHERE id = ? AND account_kind = ?',
    [now_utc(), (int) $u['id'], 'emergency']);
ea_audit('emergency.unlock', $u, ['failed_login_count' => (int) $u['failed_login_count']], ['failed_login_count' => 0]);
ea_say('unlock: 완료 — 잠금을 풀었습니다.');
exit(0);
