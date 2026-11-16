<?php
declare(strict_types=1);

/**
 * 티어 3 — **진짜 병렬 쓰기 경합** (SQLite) — 설계 B-5 · 배포 조건 1·2·3 (D-285 · D-298)
 *
 * ─── 왜 HTTP 가 아닌가 (설계 B-5-1) ─────────────────────────────────────
 * `php -S` 는 Windows 에서 작업자 1개다. 요청 16개를 동시에 보내도 **한 줄로 처리**되어
 * DB 트랜잭션이 겹치지 않는다(`t_60_concurrency.php` 머리 주석의 C-5).
 * 그래서 여기서는 **자식 PHP 프로세스 N개가 같은 SQLite 파일을 직접 열고 배포 트리의
 * 실제 코드 경로를 돈다.** 자식은 샌드박스의 `server/core/bootstrap.php` 를 먼저 로드하고
 * (연결·PRAGMA 까지 끝낸 뒤) 시작 시각까지 스핀 대기한 다음 실제 엔드포인트 파일
 * (`api/auth/exchange.php` 등)을 `require` 한다. 엔드포인트의 `require_once bootstrap` 은
 * 이미 로드된 파일이라 건너뛰고, **경합 창에는 엔드포인트 본문만 남는다.**
 *
 * ─── 요청 본문 주입 — 배포물에 테스트 분기를 넣지 않는다 ─────────────────
 * `body_json()` 은 Content-Type 이 JSON 이 아니고 `$_POST` 가 차 있으면 `$_POST` 를 쓴다
 * (`core/request.php` — 폼 전송 경로). 자식은 `CONTENT_TYPE` 을 폼으로 두고 `$_POST` 를
 * 채운다. **배포 코드는 한 글자도 테스트용으로 바뀌지 않았다.**
 *
 * ─── 대상 트리를 건드리지 않는다 ───────────────────────────────────────
 * `server/` 를 임시 샌드박스로 복사하고 `config.php` 도 거기에만 쓴다. **변이(D-186)도
 * 샌드박스 사본에만 심는다.** DB 파일은 `ulogin_test_*.sqlite` 접두로만 만든다
 * (`live_fresh_db()` 의 `ulogin_test` 접두 가드와 같은 규약). `runtime/data/` 는 열지 않는다.
 * MySQL 상수에는 **포트 1** 을 넣는다 — 드라이버 분기가 어긋나 MySQL 로 새면 3306 이 아니라
 * 연결 거부로 시끄럽게 죽는다.
 *
 * ─── 판정 ────────────────────────────────────────────────────────────
 * 「성공 1개」만 보면 deferred `BEGIN` 변이(M1)도 통과한다(실제로 1개만 성공한다).
 * **5xx·예외 0건 · INVALID N−1 · DB 사후 상태 · integrity_check** 까지 본다(설계 B-5-3).
 * 그리고 **자식이 시작 시각 전에 스핀에 들어갔는지**를 잰다. 늦게 뜬 자식이 있으면
 * 그 라운드는 경합이 아니므로 **하네스 실패**로 친다 — 겹치지 않은 시험의 초록은 거짓이다.
 *
 * ─── 변이 (D-186 · 규약 8) — 변이에서 통과하면 시험이 눈먼 것이다 ────────
 *   M1  BEGIN IMMEDIATE → BEGIN                 반드시 실패(5xx 섞임)
 *   M2  트랜잭션 제거 + 조건부 UPDATE 제거        반드시 실패(다중 성공)
 *   M3  조건부 UPDATE 만 제거 (IMMEDIATE 유지)    측정 — IMMEDIATE 한 겹으로 버티는가
 *   M4  트랜잭션만 제거 (조건부 UPDATE 유지)      측정 — 조건부 UPDATE 한 겹으로 버티는가
 * M3·M4 는 **2×2 대조**다. M2(둘 다 제거)가 실패하고 M3·M4 가 각각 통과하면
 * 「두 겹이 **각자** 막는다」가 측정으로 성립한다. M3·M4 가 통과하는 것은 눈먼 것이 아니다 —
 * 같은 변이 지점을 합친 M2 가 실패함으로써 검출력이 확인된다.
 *
 * ─── 못 잡는 것 ──────────────────────────────────────────────────────
 *  · MySQL `FOR UPDATE` 의 실제 행 잠금(설계 F-4). 이 파일은 SQLite 경로만 잰다
 *  · 정전(전원 차단). 강제 종료 시험은 D-5-6 이 따로 다룬다
 *
 * 환경변수 `ULOGIN_RACE_QUICK=1` 이면 라운드 수를 줄인다(개발 중 반복용). **배포 판정에는
 * 쓰지 마라** — 배포 조건 1 은 N=4·8·16·32 각 5라운드다.
 */

require_once __DIR__ . '/../lib/live.php';

$GLOBALS['RACE'] = array('boxes' => array(), 'registered' => false);

/** 배포 조건 1 의 사양값. 설계 B-5-2 */
function race_plan(): array
{
    $quick = getenv('ULOGIN_RACE_QUICK') === '1';
    return array(
        'ns'        => $quick ? array(4, 16) : array(4, 8, 16, 32),
        'rounds'    => $quick ? 2 : 5,
        'mut_n'     => 16,
        'mut_rounds' => $quick ? 2 : 5,
    );
}

function race_require(): void
{
    require_ext('pdo_sqlite');
    $root = h_root() . '/server';
    foreach (array('core/bootstrap.php', 'core/db.php', 'api/auth/exchange.php') as $f) {
        if (!is_file($root . '/' . $f)) {
            fail('missing ' . $f . ' in the tree under test');
        }
    }
}

function race_cleanup(): void
{
    foreach ($GLOBALS['RACE']['boxes'] as $b) {
        live_rm_rf($b);
    }
    $GLOBALS['RACE']['boxes'] = array();
}

/**
 * `server/` 를 복사한 샌드박스. 변이는 이 사본에만 심는다.
 * @return string 샌드박스 루트(그 아래 server/)
 */
function race_box(string $label): string
{
    if (!$GLOBALS['RACE']['registered']) {
        $GLOBALS['RACE']['registered'] = true;
        register_shutdown_function('race_cleanup');   // 실패해도 지운다
    }
    $box = h_norm(sys_get_temp_dir() . '/ulogin_race_' . $label . '_' . bin2hex(random_bytes(4)));
    live_copy_dir(h_root() . '/server', $box . '/server');
    @mkdir($box . '/db', 0777, true);
    $GLOBALS['RACE']['boxes'][] = $box;
    return $box;
}

/** 이 라운드의 DB 경로. **접두 가드** — `ulogin_test_` 밖의 이름은 만들지 않는다. */
function race_db_path(string $box, string $tag): string
{
    $name = 'ulogin_test_' . preg_replace('~[^a-z0-9_]~', '_', strtolower($tag)) . '.sqlite';
    if (strpos($name, 'ulogin_test_') !== 0) {
        fail('refusing a test database name outside the ulogin_test_ prefix: ' . $name);
    }
    return $box . '/db/' . $name;
}

/** 이전 라운드의 DB 와 `-wal`·`-shm` 을 지운다(남으면 다음 라운드가 옛 상태를 본다 — 설계 F-2). */
function race_db_remove(string $path): void
{
    foreach (array('', '-wal', '-shm', '-journal') as $sfx) {
        if (is_file($path . $sfx)) {
            @unlink($path . $sfx);
        }
    }
}

/** 샌드박스 config.php 를 이 DB 로 다시 쓴다. */
function race_write_config(string $box, string $dbPath): void
{
    $c = array('db_host' => '127.0.0.1', 'db_port' => 1, 'db_user' => 'nobody', 'db_pass' => '',
        'db_name' => 'ulogin_test_race_unused', 'http_port' => 9);
    $php = live_config_php($c, array(
        'DB_DRIVER'      => 'sqlite',
        'DB_SQLITE_PATH' => $dbPath,
        'DB_HOST'        => '127.0.0.1',
        'DB_PORT'        => 1,            // 어긋나 MySQL 로 새면 3306 이 아니라 연결 거부
        'LOCKOUT_THRESHOLD' => 5,
        'LOCKOUT_MINUTES'   => 15,
    ));
    file_put_contents($box . '/server/config.php', $php);
}

/** 자식 PHP 의 공통 앞부분 — 러너와 같은 ini 를 쓴다. */
function race_php_cmd(): string
{
    $ini = php_ini_loaded_file();
    return h_q(h_php_bin()) . ($ini !== false && $ini !== '' ? ' -c ' . h_q($ini) : ' -n');
}

/**
 * 스키마를 만든다. **배포 트리의 번역기로** — 시험이 자기 스키마를 따로 들고 있으면
 * 그것이 정확히 설계 C-2 가 막으려던 「정본 둘」이다.
 */
function race_apply_schema(string $box, string $dbPath): void
{
    race_db_remove($dbPath);
    $res = race_run_one($box, array('mode' => 'schema', 'db' => $dbPath));
    if (!isset($res['result']['tables']) || (int) $res['result']['tables'] < 1) {
        fail('schema build in the sandbox failed: ' . race_show($res));
    }
}

/** 부모가 DB 를 직접 본다(시드·사후 확인). 자식과 같은 PRAGMA 를 건다. */
function race_pdo(string $dbPath): PDO
{
    if (!is_file($dbPath)) {
        fail('test database was not created: ' . $dbPath);
    }
    $pdo = new PDO('sqlite:' . $dbPath, null, null, array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ));
    $pdo->exec('PRAGMA busy_timeout = 5000');
    return $pdo;
}

function race_now(int $off = 0): string
{
    return gmdate('Y-m-d H:i:s', time() + $off);
}

/** 계정 1 · 서비스 1. 반환: [user_id, service_id, redirect_uri] */
function race_seed_base(PDO $pdo, int $points = 0): array
{
    $now = race_now();
    $u = 'race_' . bin2hex(random_bytes(3));
    $pdo->prepare('INSERT INTO app_ulogin_users (uuid, username, email, password_hash, status, account_level, role,'
        . ' billing_tier, points, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(array(
            sprintf('%08s-0000-4000-8000-%012s', bin2hex(random_bytes(4)), bin2hex(random_bytes(6))),
            $u, $u . '@test.invalid', password_hash('x', PASSWORD_DEFAULT),
            'active', 'basic', 'user', 'free', $points, $now, $now,
        ));
    $uid = (int) $pdo->lastInsertId();
    $redirect = 'http://127.0.0.1:9/cb';
    $pdo->prepare('INSERT INTO app_ulogin_services (service_id, service_name, is_confidential, redirect_whitelist,'
        . ' auto_join, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(array('race-app', 'race app', 0, json_encode(array($redirect)), 1, 'active', $now, $now));
    return array($uid, 'race-app', $redirect);
}

/** 경쟁 시험의 PKCE verifier(43~128자). race-app 은 공개 클라이언트라 v1.10.0 감사 H-1 부터 PKCE 가 필수다. */
const RACE_VERIFIER = 'race-verifier-0123456789abcdefghijklmnopqrstuvwxyz';

/** 유효한 code 1개(S256 challenge 포함). 반환: [code 원문, auth_codes.id] */
function race_seed_code(PDO $pdo, int $uid, string $sid, string $redirect): array
{
    $code = bin2hex(random_bytes(32));
    $challenge = rtrim(strtr(base64_encode(hash('sha256', RACE_VERIFIER, true)), '+/', '-_'), '=');
    $pdo->prepare('INSERT INTO app_ulogin_auth_codes (code_hash, user_id, service_id, redirect_uri, state,'
        . ' code_challenge, expires_at, used_at, created_ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)')
        ->execute(array(hash('sha256', $code), $uid, $sid, $redirect, 'st-race', $challenge,
            race_now(300), '127.0.0.1', race_now()));
    return array($code, (int) $pdo->lastInsertId());
}

/** Bearer 토큰 세션 1개(포인트 차감 시험용). 반환: access_token 원문 */
function race_seed_bearer(PDO $pdo, int $uid): string
{
    $tok = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO app_ulogin_sessions (session_id, user_id, service_id, auth_type, access_token_hash,'
        . ' issued_at, expires_at, last_seen_at, ip, user_agent) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(array(bin2hex(random_bytes(32)), $uid, 'token', hash('sha256', $tok),
            race_now(-5), race_now(3600), race_now(-5), '127.0.0.1', 'race'));
    return $tok;
}

// =====================================================================
// 자식 프로세스
// =====================================================================

/** 자식 소스. 샌드박스에 파일로 쓴다(부산물은 샌드박스와 함께 지워진다). */
function race_worker_source(): string
{
    return <<<'WORKER'
<?php
// generated by tests/tier3_live/t_61_code_race.php - sandbox only. never uploaded.
$job = json_decode((string) file_get_contents($argv[1]), true);
$idx = (int) $argv[2];
$GLOBALS['__R'] = array('out' => $argv[3], 'idx' => $idx, 'start' => (float) $job['start'],
    'spin_at' => null, 't0' => null, 'result' => null);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', $argv[3] . '.log');
$_SERVER['REQUEST_METHOD']  = 'POST';
$_SERVER['REMOTE_ADDR']     = '10.61.' . intdiv($idx, 250) . '.' . (($idx % 250) + 1);
$_SERVER['HTTP_HOST']       = '127.0.0.1';
$_SERVER['SERVER_NAME']     = '127.0.0.1';
$_SERVER['SERVER_PORT']     = '80';
$_SERVER['HTTP_USER_AGENT'] = 'ulogin-race/' . $idx;
$_SERVER['REQUEST_URI']     = isset($job['uri']) ? $job['uri'] : '/';
$_SERVER['SCRIPT_NAME']     = $_SERVER['REQUEST_URI'];
$_SERVER['CONTENT_TYPE']    = 'application/x-www-form-urlencoded';
if (isset($job['bearer'])) {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $job['bearer'];
}
$_POST = isset($job['post']) ? $job['post'] : array();
register_shutdown_function(function () {
    $R = $GLOBALS['__R'];
    $body = '';
    while (ob_get_level() > 0) {
        $body = (string) ob_get_clean() . $body;
    }
    $st = http_response_code();
    file_put_contents($R['out'], json_encode(array(
        'idx' => $R['idx'], 'status' => $st === false ? 0 : (int) $st, 'body' => $body,
        'spin_lead' => $R['spin_at'] === null ? null : $R['start'] - $R['spin_at'],
        'late' => $R['t0'] === null ? null : $R['t0'] - $R['start'],
        'result' => $R['result'], 'fatal' => error_get_last(),
    )));
});
ob_start();
if ($job['mode'] === 'hang') {
    // D-339 시험 전용 — 「멈춘 자식」. 자기 PID 를 적고 끝나지 않는다. 하네스가 기한 뒤 죽여야 한다.
    file_put_contents($argv[3] . '.pid', (string) getmypid());
    while (true) {
        sleep(1);
    }
}
if ($job['mode'] === 'schema') {
    $GLOBALS['__R']['result'] = race_child_schema($job);
    exit;
}
// 연결·PRAGMA 까지 끝낸 뒤 스핀 — 경합 창에 부트스트랩 비용을 넣지 않는다.
require $job['box'] . '/server/core/bootstrap.php';
db();
$GLOBALS['__R']['spin_at'] = microtime(true);
while (microtime(true) < $job['start']) {
    // spin
}
$GLOBALS['__R']['t0'] = microtime(true);
if ($job['mode'] === 'http') {
    chdir(dirname($job['script']));
    require $job['script'];
    exit;
}
$GLOBALS['__R']['result'] = race_child_call($job);
exit;

/** 연산 직접 호출 — 레이트리밋 · 마커 · 락아웃 카운트 */
function race_child_call(array $job)
{
    $op = $job['op'];
    if ($op === 'ratelimit') {
        $hit = 0;
        for ($i = 0; $i < (int) $job['times']; $i++) {
            try {
                rate_limit($job['bucket'], (int) $job['max'], (int) $job['window']);
            } catch (AppError $e) {
                $hit++;
            }
        }
        return array('limited' => $hit);
    }
    if ($op === 'claim') {
        return array('claimed' => rate_limit_claim_first($job['bucket'], (int) $job['window']));
    }
    if ($op === 'lockfail') {
        for ($i = 0; $i < (int) $job['times']; $i++) {
            // 실제 로그인 경로처럼 **요청마다 사용자 행을 새로 읽는다**(api/auth/login.php).
            $u = db_one('SELECT * FROM ' . T_USERS . ' WHERE id = ?', array((int) $job['uid']));
            register_login_failure($u);
        }
        return array('done' => (int) $job['times']);
    }
    return array('error' => 'unknown op ' . $op);
}
WORKER
    . "\n" . race_schema_child_fn();
}

/**
 * 스키마 생성 자식 함수 — **배포 트리의 번역기를 부른다.**
 * `migrations/lib.php` 의 `mig_sqlite_create_schema()` → `core/ddl.php` 의 번역기. 신규 설치와 같은
 * 최종 스키마다(설치기도 같은 번역 함수를 지난다). PRAGMA 는 앱과 같은 열기 함수가 건다.
 * 부모 프로세스가 아니라 자식에서 하는 이유: 그 파일들은 직접 실행 차단 가드를 갖고 있어
 * 러너 프로세스에 들이면 안 된다(가드가 exit 한다).
 */
function race_schema_child_fn(): string
{
    return <<<'FN'
function race_child_schema(array $job)
{
    define('ULOGIN_MIGRATE_CTX', 1);
    require $job['box'] . '/server/migrations/lib.php';
    $pdo = ulogin_pdo_open_sqlite($job['db'], array('create' => true, 'quick_check' => false));
    // baseline=true — 신규 설치와 같은 최종 상태(schema_migrations 기록 포함)
    $r = mig_sqlite_create_schema($pdo, $job['box'] . '/server/schema.sql', 'app_ulogin_', true);
    $pdo = null;
    return $r;
}
FN;
}

/** 자식 1개를 동기로(스키마 생성용). */
function race_run_one(string $box, array $job): array
{
    $job['box'] = $box;
    $job['start'] = microtime(true);
    $r = race_spawn($box, array($job), 0.0);
    return $r[0];
}

/**
 * 자식 N개를 띄우고 전부 끝날 때까지 기다린다.
 * @param array $jobs   자식별 작업(길이 = N)
 * @param float $lead   지금부터 시작 시각까지(초). 자식 기동 시차를 덮을 만큼
 * @return array        자식별 결과
 */
function race_spawn(string $box, array $jobs, float $lead): array
{
    $dir = $box . '/run_' . bin2hex(random_bytes(4));
    @mkdir($dir, 0777, true);
    $worker = $dir . '/worker.php';
    file_put_contents($worker, race_worker_source());
    $start = microtime(true) + $lead;
    $procs = array();
    foreach ($jobs as $i => $job) {
        $job['box'] = $box;
        $job['start'] = $start;
        $jf = $dir . '/job_' . $i . '.json';
        file_put_contents($jf, json_encode($job));
        $out = $dir . '/out_' . $i . '.json';
        $cmd = race_php_cmd() . ' ' . h_q($worker) . ' ' . h_q($jf) . ' ' . $i . ' ' . h_q($out);
        $desc = array(
            0 => array('pipe', 'r'),
            1 => array('file', $dir . '/stdout_' . $i . '.txt', 'w'),
            2 => array('file', $dir . '/stderr_' . $i . '.txt', 'w'),
        );
        $pp = array();
        $p = @proc_open($cmd, $desc, $pp, $dir);
        if (!is_resource($p)) {
            fail('proc_open failed for worker ' . $i . ' - the harness itself is broken');
        }
        fclose($pp[0]);
        $procs[$i] = array($p, $out);
    }
    $res = array();
    // D-339 — 기한을 넘긴 자식은 **자기 PID 트리만** 죽이고 하네스 실패로 끝낸다(무한 대기 금지).
    $deadline = proc_deadline();
    $timedOut = array();
    foreach ($procs as $i => $pr) {
        $w = proc_wait_or_kill($pr[0], $deadline);
        if ($w['timed_out']) {
            $timedOut[] = $i . '(pid ' . $w['pid'] . ')';
        }
        $j = is_file($pr[1]) ? json_decode((string) file_get_contents($pr[1]), true) : null;
        if (!is_array($j)) {
            $j = array('idx' => $i, 'status' => 0, 'body' => '', 'result' => null, 'late' => null,
                'spin_lead' => null, 'fatal' => 'worker wrote no result. stderr: '
                . substr((string) @file_get_contents($dir . '/stderr_' . $i . '.txt'), -400));
        }
        $log = $pr[1] . '.log';
        $j['log'] = is_file($log) ? substr((string) file_get_contents($log), -600) : '';
        $res[$i] = $j;
    }
    if ($timedOut !== array()) {
        fail('HARNESS-TIMEOUT ' . count($timedOut) . ' worker(s) did not finish within '
            . proc_child_timeout() . 's and were killed (own PID tree only): ' . implode(', ', $timedOut));
    }
    return $res;
}

function race_show($v): string
{
    return substr(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 900);
}

/** 봉투에서 결과 분류: OK / 오류코드 / ERR(봉투 아님·5xx) */
function race_classify(array $r): string
{
    $j = json_decode((string) $r['body'], true);
    if (!is_array($j) || !array_key_exists('ok', $j)) {
        return 'ERR';
    }
    if ($j['ok'] === true) {
        return 'OK';
    }
    $code = isset($j['error']['code']) ? (string) $j['error']['code'] : '';
    if ($code === '' || $code === 'SERVER_ERROR' || (int) $r['status'] >= 500) {
        return 'ERR';
    }
    return $code;
}

/**
 * 동기화 검사 — 모든 자식이 시작 시각 **전에** 스핀에 들어갔는가.
 * 아니면 그 라운드는 겹치지 않았을 수 있다 → 하네스 실패(초록이 거짓이 된다).
 */
function race_assert_synced(array $res, string $what): void
{
    foreach ($res as $r) {
        if ($r['spin_lead'] === null) {
            fail($what . ': worker ' . $r['idx'] . ' died before reaching the start line (bootstrap/db() failed in the sandbox) '
                . race_show($r));
        }
        if ((float) $r['spin_lead'] <= 0.0) {
            fail($what . ': worker ' . $r['idx'] . ' reached the start line late (spin_lead='
                . var_export($r['spin_lead'], true) . ') - the round did not overlap; increase the lead. '
                . race_show($r));
        }
    }
}

// =====================================================================
// T-RACE-1 — code 1회 사용 (설계 B-5-2)
// =====================================================================

/**
 * 한 라운드: 새 DB · code 1개 · 동시 N개 exchange.
 * @return array 라운드 요약 {n, ok, invalid, err, err_samples, used, sessions, logs, integrity}
 */
function race_exchange_round(string $box, int $n, string $tag): array
{
    $db = race_db_path($box, $tag);
    race_write_config($box, $db);
    race_apply_schema($box, $db);
    $pdo = race_pdo($db);
    list($uid, $sid, $redirect) = race_seed_base($pdo);
    list($code, $codeId) = race_seed_code($pdo, $uid, $sid, $redirect);
    $pdo = null;

    $script = $box . '/server/api/auth/exchange.php';
    $jobs = array();
    for ($i = 0; $i < $n; $i++) {
        $jobs[] = array('mode' => 'http', 'script' => $script, 'uri' => '/server/api/auth/exchange.php',
            'post' => array('code' => $code, 'service_id' => $sid, 'redirect_uri' => $redirect, 'code_verifier' => RACE_VERIFIER));
    }
    $res = race_spawn($box, $jobs, 1.5 + 0.08 * $n);
    race_assert_synced($res, 'exchange N=' . $n);

    $sum = array('n' => $n, 'ok' => 0, 'invalid' => 0, 'err' => 0, 'other' => 0, 'err_samples' => array());
    foreach ($res as $r) {
        $c = race_classify($r);
        if ($c === 'OK') {
            $sum['ok']++;
        } elseif ($c === 'SSO_CODE_INVALID') {
            $sum['invalid']++;
        } elseif ($c === 'ERR') {
            $sum['err']++;
            if (count($sum['err_samples']) < 2) {
                $sum['err_samples'][] = array('status' => $r['status'], 'body' => substr((string) $r['body'], 0, 160),
                    'log' => substr((string) $r['log'], -240), 'fatal' => $r['fatal']);
            }
        } else {
            $sum['other']++;
            if (count($sum['err_samples']) < 2) {
                $sum['err_samples'][] = array('code' => $c, 'body' => substr((string) $r['body'], 0, 160));
            }
        }
    }
    $pdo = race_pdo($db);
    $sum['used']      = (int) $pdo->query('SELECT COUNT(*) FROM app_ulogin_auth_codes WHERE id = ' . $codeId
        . ' AND used_at IS NOT NULL')->fetchColumn();
    $sum['sessions']  = (int) $pdo->query("SELECT COUNT(*) FROM app_ulogin_sessions WHERE service_id = 'race-app'")->fetchColumn();
    $sum['logs']      = (int) $pdo->query("SELECT COUNT(*) FROM app_ulogin_login_logs WHERE service_id = 'race-app'"
        . " AND result IN ('success', 'success_silent')")->fetchColumn();
    $sum['integrity'] = (string) $pdo->query('PRAGMA integrity_check')->fetchColumn();
    $pdo = null;
    race_db_remove($db);
    return $sum;
}

/** 배포 조건 1 의 기대값을 한 라운드에 대조. 위반 목록(비면 통과) */
function race_exchange_violations(array $s): array
{
    $v = array();
    if ($s['ok'] !== 1)               { $v[] = 'ok=' . $s['ok'] . ' (want 1)'; }
    if ($s['invalid'] !== $s['n'] - 1) { $v[] = 'invalid=' . $s['invalid'] . ' (want ' . ($s['n'] - 1) . ')'; }
    if ($s['err'] !== 0)              { $v[] = 'err(5xx/non-envelope)=' . $s['err']; }
    if ($s['other'] !== 0)            { $v[] = 'other=' . $s['other']; }
    if ($s['used'] !== 1)             { $v[] = 'used_at rows=' . $s['used']; }
    if ($s['sessions'] !== 1)         { $v[] = 'sessions=' . $s['sessions']; }
    if ($s['logs'] !== 1)             { $v[] = 'success login_logs=' . $s['logs']; }
    if ($s['integrity'] !== 'ok')     { $v[] = 'integrity=' . $s['integrity']; }
    return $v;
}

function race_line(array $s): string
{
    return 'N=' . $s['n'] . ' ok=' . $s['ok'] . ' invalid=' . $s['invalid'] . ' err=' . $s['err']
        . ' other=' . $s['other'] . ' used=' . $s['used'] . ' sessions=' . $s['sessions']
        . ' logs=' . $s['logs'] . ' integrity=' . $s['integrity'];
}

/**
 * 샌드박스 사본에 변이를 심는다. **심을 자리를 못 찾으면 실패** — 변이가 안 심겼는데
 * 「변이에서도 통과」로 읽히면 그것이 이 프로젝트의 거짓 결과 유형이다.
 */
function race_mutate(string $box, string $rel, string $pattern, string $replace, string $what): void
{
    $f = $box . '/server/' . $rel;
    $src = (string) file_get_contents($f);
    $n = 0;
    $out = preg_replace($pattern, $replace, $src, -1, $n);
    if ($out === null || $n < 1) {
        fail('mutation ' . $what . ' found no site in ' . $rel . ' (pattern ' . $pattern . ') - the mutation harness is blind');
    }
    file_put_contents($f, $out);
}

/**
 * 경합 창 넓히기 — code 를 읽은 직후 30ms 를 쉰다. **샌드박스 사본에만** 심는다.
 *
 * ── 왜 필요한가 (실측. 이 파일의 첫 측정이 눈멀었다) ──────────────────────
 * 넓히기 없이 돌린 첫 측정에서 **M1·M2 가 둘 다 살아남았다**(N=16 × 2라운드, 전부 ok=1·err=0).
 * 원인: exchange.php 는 code 를 읽기 **전에** `rate_limit_logged('exchange:ip:…')` 로 **쓴다.**
 * SQLite 의 쓰기 잠금은 DB 전체 하나라, 동시에 출발한 자식들이 그 쓰기에서 busy 처리기의
 * 지수 대기(1·2·5·10…100ms)로 **흩어진다.** 이긴 쪽의 교환(수 ms)이 끝난 뒤에야 다음이
 * code 를 읽으니 트랜잭션이 겹치지 않았다 — 「성공 1」은 보장 때문이 아니라 **시차 때문**이었다.
 * 설계의 축소 모형(부록 3~5)에는 앞의 쓰기가 없어서 겹쳤다.
 * 운영에서 그 시차가 늘 있다는 보장은 없다(MySQL 은 행 잠금이라 이 흩어짐이 없고, 요청 하나가
 * GC·스케줄링으로 수십 ms 멈추는 일은 흔하다). 그래서 **읽기와 소모 사이를 30ms 로 벌려**
 * 그 창 안에 여러 요청이 동시에 있게 만든다. 넓힌 상태에서 정상 코드가 통과하고 변이가
 * 실패해야 검출력이 성립한다 — 정상 코드도 같은 넓히기로 돈다(T-RACE-1 넓힘 케이스).
 */
function race_widen(string $box): void
{
    race_mutate($box, 'api/auth/exchange.php',
        '~(\$row = db_one\(\'SELECT \* FROM \' \. T_AUTH_CODES [^;]*;)~',
        '$1 usleep(30000); /* race widening - sandbox only */', 'race-window widening');
}

/** 변이별 상자(전부 넓힘 적용). 같은 변이는 한 번만 만든다. 'W' = 정상 코드 + 넓힘 */
function race_mutant_box(string $m): string
{
    static $boxes = array();
    if (isset($boxes[$m])) {
        return $boxes[$m];
    }
    $box = race_box(strtolower($m));
    race_widen($box);
    $noTx   = array('~\$result\s*=\s*db_tx\(\s*function~', '$result = call_user_func(function');
    $noCond = array('~SET used_at = \? WHERE id = \? AND used_at IS NULL~', 'SET used_at = ? WHERE id = ?');
    if ($m === 'M1') {
        race_mutate($box, 'core/db.php', '~BEGIN IMMEDIATE~', 'BEGIN', 'M1 (BEGIN IMMEDIATE -> BEGIN)');
    } elseif ($m === 'M2') {
        race_mutate($box, 'api/auth/exchange.php', $noTx[0], $noTx[1], 'M2 (drop transaction)');
        race_mutate($box, 'api/auth/exchange.php', $noCond[0], $noCond[1], 'M2 (drop conditional UPDATE)');
    } elseif ($m === 'M3') {
        race_mutate($box, 'api/auth/exchange.php', $noCond[0], $noCond[1], 'M3 (drop conditional UPDATE)');
    } elseif ($m === 'M4') {
        race_mutate($box, 'api/auth/exchange.php', $noTx[0], $noTx[1], 'M4 (drop transaction)');
    }
    $boxes[$m] = $box;
    return $box;
}

function race_main_box(): string
{
    static $box = null;
    if ($box === null) {
        $box = race_box('main');
    }
    return $box;
}

t('T-RACE-1 exchange: same code x N concurrent (N=4,8,16,32 x 5 rounds) -> exactly 1 success, N-1 SSO_CODE_INVALID, 0 errors', function () {
    race_require();
    $plan = race_plan();
    $box = race_main_box();
    $lines = array();
    $bad = array();
    foreach ($plan['ns'] as $n) {
        for ($r = 1; $r <= $plan['rounds']; $r++) {
            $s = race_exchange_round($box, $n, 'x_n' . $n . '_r' . $r);
            $v = race_exchange_violations($s);
            $lines[] = 'r' . $r . ' ' . race_line($s) . ($v === array() ? '' : '  <-- ' . implode(', ', $v));
            if ($v !== array()) {
                $bad[] = 'N=' . $n . ' r' . $r . ': ' . implode(', ', $v) . ' ' . race_show($s['err_samples']);
            }
        }
    }
    note("T-RACE-1 rounds:\n      " . implode("\n      ", $lines));
    if ($bad !== array()) {
        fail(count($bad) . ' round(s) violated the one-time-use guarantee: ' . implode(' | ', $bad));
    }
});

t('T-RACE-1 widened (30ms between read and consume): N=4,8,16,32 x 5 rounds -> still exactly 1 success, 0 errors', function () {
    race_require();
    $plan = race_plan();
    $box = race_mutant_box('W');
    $lines = array();
    $bad = array();
    foreach ($plan['ns'] as $n) {
        for ($r = 1; $r <= $plan['rounds']; $r++) {
            $s = race_exchange_round($box, $n, 'w_n' . $n . '_r' . $r);
            $v = race_exchange_violations($s);
            $lines[] = 'r' . $r . ' ' . race_line($s) . ($v === array() ? '' : '  <-- ' . implode(', ', $v));
            if ($v !== array()) {
                $bad[] = 'N=' . $n . ' r' . $r . ': ' . implode(', ', $v) . ' ' . race_show($s['err_samples']);
            }
        }
    }
    note("T-RACE-1 widened rounds:
      " . implode("
      ", $lines));
    if ($bad !== array()) {
        fail(count($bad) . ' widened round(s) violated the one-time-use guarantee: ' . implode(' | ', $bad));
    }
});

/**
 * 변이 하나를 돌려 라운드 요약을 모은다.
 * @return array [lines, summaries]
 */
function race_run_mutant(string $m): array
{
    $plan = race_plan();
    $box = race_mutant_box($m);
    $lines = array();
    $sums = array();
    for ($r = 1; $r <= $plan['mut_rounds']; $r++) {
        $s = race_exchange_round($box, $plan['mut_n'], strtolower($m) . '_r' . $r);
        $v = race_exchange_violations($s);
        $sums[] = $s;
        $lines[] = $m . ' r' . $r . ' ' . race_line($s) . ($v === array() ? '' : '  <-- ' . implode(', ', $v));
    }
    note($m . " rounds:\n      " . implode("\n      ", $lines));
    return array($lines, $sums);
}

t('T-RACE-1 mutation M1 (BEGIN IMMEDIATE -> deferred BEGIN) is DETECTED: errors appear', function () {
    race_require();
    list(, $sums) = race_run_mutant('M1');
    $errs = 0;
    foreach ($sums as $s) {
        $errs += $s['err'];
    }
    if ($errs === 0) {
        fail('M1 survived: deferred BEGIN produced 0 errors in every round - the race test cannot see SQLITE_BUSY. '
            . race_show($sums));
    }
});

t('T-RACE-1 mutation M2 (no transaction + unconditional UPDATE) is DETECTED: multiple successes', function () {
    race_require();
    list(, $sums) = race_run_mutant('M2');
    $multi = 0;
    foreach ($sums as $s) {
        if ($s['ok'] > 1 || $s['sessions'] > 1) {
            $multi++;
        }
    }
    if ($multi === 0) {
        fail('M2 survived: with both guards removed every round still had a single success - the test does not overlap the requests. '
            . race_show($sums));
    }
});

t('T-RACE-1 mutation M3 (conditional UPDATE removed, BEGIN IMMEDIATE kept): IMMEDIATE alone still holds', function () {
    race_require();
    list(, $sums) = race_run_mutant('M3');
    $bad = array();
    foreach ($sums as $i => $s) {
        $v = race_exchange_violations($s);
        if ($v !== array()) {
            $bad[] = 'r' . ($i + 1) . ': ' . implode(', ', $v);
        }
    }
    if ($bad !== array()) {
        fail('BEGIN IMMEDIATE alone did not serialize the exchange: ' . implode(' | ', $bad));
    }
});

t('T-RACE-1 mutation M4 (transaction removed, conditional UPDATE kept): conditional UPDATE alone still holds', function () {
    race_require();
    list(, $sums) = race_run_mutant('M4');
    $bad = array();
    foreach ($sums as $i => $s) {
        if ($s['ok'] !== 1 || $s['used'] !== 1 || $s['sessions'] !== 1) {
            $bad[] = 'r' . ($i + 1) . ': ok=' . $s['ok'] . ' sessions=' . $s['sessions'];
        }
    }
    if ($bad !== array()) {
        fail('the conditional UPDATE alone did not keep the code single-use: ' . implode(' | ', $bad));
    }
});

// =====================================================================
// T-RACE-2 — 레이트리밋 원자 증가 (설계 B-5-4) · H-5 락아웃 카운트 (D-298)
// =====================================================================

/** 연산 라운드: 새 DB · 자식 16개가 같은 op 를 돈다. */
function race_call_round(string $box, string $tag, array $job, int $procs, ?callable $seed = null): array
{
    $db = race_db_path($box, $tag);
    race_write_config($box, $db);
    race_apply_schema($box, $db);
    $ctx = array();
    if ($seed !== null) {
        $pdo = race_pdo($db);
        $ctx = $seed($pdo);
        $pdo = null;
        foreach ($ctx as $k => $v) {
            $job[$k] = $v;
        }
    }
    $jobs = array_fill(0, $procs, $job + array('mode' => 'call', 'uri' => '/race'));
    $res = race_spawn($box, $jobs, 1.5 + 0.08 * $procs);
    race_assert_synced($res, $tag);
    $errs = array();
    foreach ($res as $r) {
        if (!is_array($r['result']) || isset($r['result']['error']) || $r['fatal'] !== null) {
            $errs[] = race_show($r);
        }
    }
    return array($db, $res, $errs, $ctx);
}

function race_rl_round(string $box, string $tag): array
{
    list($db, , $errs) = race_call_round($box, $tag, array('op' => 'ratelimit', 'bucket' => 'race:rl',
        'max' => 100000000, 'window' => 86400, 'times' => 50), 16);
    $pdo = race_pdo($db);
    $total = (int) $pdo->query("SELECT COALESCE(SUM(count), 0) FROM app_ulogin_rate_limits WHERE bucket = 'race:rl'")->fetchColumn();
    $rows  = (int) $pdo->query("SELECT COUNT(*) FROM app_ulogin_rate_limits WHERE bucket = 'race:rl'")->fetchColumn();
    $integ = (string) $pdo->query('PRAGMA integrity_check')->fetchColumn();
    $pdo = null;
    race_db_remove($db);
    return array('total' => $total, 'rows' => $rows, 'integrity' => $integ, 'errs' => $errs);
}

/** 레이트리밋 변이: 원자 UPSERT → 읽기-수정-쓰기 */
function race_rl_mutant_box(): string
{
    static $box = null;
    if ($box !== null) {
        return $box;
    }
    $box = race_box('rl_rmw');
    $rmw = '$__c = db_val(\'SELECT count FROM \' . T_RATE_LIMITS . \' WHERE bucket = ? AND window_start = ?\', [$bucket, $start]);'
        . ' if ($__c === null) { db_exec(\'INSERT INTO \' . T_RATE_LIMITS . \' (bucket, window_start, count, expires_at) VALUES (?, ?, 1, ?)\', [$bucket, $start, $expires]); }'
        . ' else { db_exec(\'UPDATE \' . T_RATE_LIMITS . \' SET count = ? WHERE bucket = ? AND window_start = ?\', [(int) $__c + 1, $bucket, $start]); }';
    race_mutate($box, 'core/ratelimit.php', '~db_upsert_increment\(\s*T_RATE_LIMITS\b.*?\);~s', str_replace('$', '\$', $rmw),
        'rate limit UPSERT -> read-modify-write');
    return $box;
}

t('T-RACE-2 rate_limit(): 16 processes x 50 increments = exactly 800 (3 rounds)', function () {
    race_require();
    $box = race_main_box();
    $lines = array();
    $bad = array();
    for ($r = 1; $r <= 3; $r++) {
        $s = race_rl_round($box, 'rl_r' . $r);
        $lines[] = 'r' . $r . ' total=' . $s['total'] . ' rows=' . $s['rows'] . ' integrity=' . $s['integrity']
            . ' worker_errors=' . count($s['errs']);
        if ($s['total'] !== 800 || $s['integrity'] !== 'ok' || $s['errs'] !== array()) {
            $bad[] = end($lines) . ' ' . implode(' ', array_slice($s['errs'], 0, 1));
        }
    }
    note("T-RACE-2 rounds:\n      " . implode("\n      ", $lines));
    if ($bad !== array()) {
        fail('rate-limit counter is not atomic: ' . implode(' | ', $bad));
    }
});

t('T-RACE-2 mutation: read-modify-write increment is DETECTED (< 800)', function () {
    race_require();
    $box = race_rl_mutant_box();
    $lines = array();
    $lost = 0;
    for ($r = 1; $r <= 3; $r++) {
        $s = race_rl_round($box, 'rlm_r' . $r);
        $lines[] = 'r' . $r . ' total=' . $s['total'];
        if ($s['total'] < 800) {
            $lost++;
        }
    }
    note("T-RACE-2 mutant (read-modify-write) rounds:\n      " . implode("\n      ", $lines));
    if ($lost === 0) {
        fail('the read-modify-write mutant also reached 800 in every round - the counter test does not overlap. ' . implode(' ', $lines));
    }
});

t('T-RACE-2 rate_limit_claim_first(): 16 concurrent claims -> exactly 1 winner, 1 marker row', function () {
    race_require();
    $box = race_main_box();
    list($db, $res, $errs) = race_call_round($box, 'claim', array('op' => 'claim', 'bucket' => 'race:mark',
        'window' => 86400), 16);
    $wins = 0;
    foreach ($res as $r) {
        if (is_array($r['result']) && !empty($r['result']['claimed'])) {
            $wins++;
        }
    }
    $pdo = race_pdo($db);
    $rows = (int) $pdo->query("SELECT COUNT(*) FROM app_ulogin_rate_limits WHERE bucket = '!logged:race:mark'")->fetchColumn();
    $pdo = null;
    race_db_remove($db);
    note('T-RACE-2 claim: winners=' . $wins . ' marker_rows=' . $rows . ' worker_errors=' . count($errs));
    if ($errs !== array()) {
        fail('worker errors: ' . $errs[0]);
    }
    assert_eq(1, $wins, 'claim winners (a CHECK/NOT NULL failure misread as a duplicate would make this 0; a missed duplicate makes it >1)');
    assert_eq(1, $rows, 'marker rows');
});

/** H-5 한 라운드. 반환 {count, locked, errs} */
function race_lock_round(string $box, string $tag): array
{
    list($db, , $errs, $ctx) = race_call_round($box, $tag, array('op' => 'lockfail', 'times' => 5), 16,
        function (PDO $pdo) {
            list($uid) = race_seed_base($pdo);
            return array('uid' => $uid);
        });
    $pdo = race_pdo($db);
    $row = $pdo->query('SELECT failed_login_count, locked_until FROM app_ulogin_users WHERE id = ' . (int) $ctx['uid'])->fetch();
    $pdo = null;
    race_db_remove($db);
    return array('count' => (int) $row['failed_login_count'], 'locked' => $row['locked_until'], 'errs' => $errs);
}

function race_lock_mutant_box(): string
{
    static $box = null;
    if ($box !== null) {
        return $box;
    }
    $box = race_box('lock_rmw');
    // v1.7.0 의 형태로 되돌린다 — 요청 시작 때 읽은 카운트 + 1 을 써 넣는다.
    race_mutate($box, 'core/ratelimit.php',
        '~SET failed_login_count = failed_login_count \+ 1, updated_at = \? WHERE id = \?\',\s*\[now_utc\(\), \$uid\]\);~',
        'SET failed_login_count = ?, updated_at = ? WHERE id = ?\', [(int) (\$user[\'failed_login_count\'] ?? 0) + 1, now_utc(), \$uid]);',
        'H-5 atomic increment -> v1.7.0 read-modify-write');
    return $box;
}

t('H-5 register_login_failure(): 16 processes x 5 failures = failed_login_count exactly 80, account locked (3 rounds)', function () {
    race_require();
    $box = race_main_box();
    $lines = array();
    $bad = array();
    for ($r = 1; $r <= 3; $r++) {
        $s = race_lock_round($box, 'lock_r' . $r);
        $lines[] = 'r' . $r . ' failed_login_count=' . $s['count'] . ' locked_until=' . var_export($s['locked'], true)
            . ' worker_errors=' . count($s['errs']);
        if ($s['count'] !== 80 || $s['locked'] === null || $s['errs'] !== array()) {
            $bad[] = end($lines) . ' ' . implode(' ', array_slice($s['errs'], 0, 1));
        }
    }
    note("H-5 rounds:\n      " . implode("\n      ", $lines));
    if ($bad !== array()) {
        fail('lockout counter is not atomic: ' . implode(' | ', $bad));
    }
});

t('H-5 mutation: v1.7.0 read-modify-write failure count is DETECTED (< 80)', function () {
    race_require();
    $box = race_lock_mutant_box();
    $lines = array();
    $lost = 0;
    for ($r = 1; $r <= 3; $r++) {
        $s = race_lock_round($box, 'lockm_r' . $r);
        $lines[] = 'r' . $r . ' failed_login_count=' . $s['count'];
        if ($s['count'] < 80) {
            $lost++;
        }
    }
    note("H-5 mutant rounds:\n      " . implode("\n      ", $lines));
    if ($lost === 0) {
        fail('the v1.7.0 read-modify-write mutant also counted 80 every round - the lockout test does not overlap. ' . implode(' ', $lines));
    }
});

// =====================================================================
// T-RACE-3 — 나머지 1회성 자원 (설계 B-5-5): 포인트 이중 차감 · 멱등키 · 이메일 인증 토큰
// =====================================================================

/** 동시 N개 HTTP 라운드(엔드포인트 지정). 반환 [db, res] — DB 는 호출자가 지운다 */
function race_http_round(string $box, string $tag, string $rel, int $n, callable $seed, callable $jobFor): array
{
    $db = race_db_path($box, $tag);
    race_write_config($box, $db);
    race_apply_schema($box, $db);
    $pdo = race_pdo($db);
    $ctx = $seed($pdo);
    $pdo = null;
    $jobs = array();
    for ($i = 0; $i < $n; $i++) {
        $jobs[] = array('mode' => 'http', 'script' => $box . '/server/' . $rel, 'uri' => '/server/' . $rel) + $jobFor($ctx, $i);
    }
    $res = race_spawn($box, $jobs, 1.5 + 0.08 * $n);
    race_assert_synced($res, $tag);
    return array($db, $res, $ctx);
}

t('T-RACE-3 points/consume: 8 concurrent x 10pt on a 50pt balance -> exactly 5 succeed, balance 0, ledger consistent (3 rounds)', function () {
    race_require();
    $box = race_main_box();
    $lines = array();
    $bad = array();
    for ($r = 1; $r <= 3; $r++) {
        list($db, $res, $ctx) = race_http_round($box, 'pt_r' . $r, 'api/points/consume.php', 8,
            function (PDO $pdo) {
                list($uid) = race_seed_base($pdo, 50);
                return array('uid' => $uid, 'bearer' => race_seed_bearer($pdo, $uid));
            },
            function (array $ctx, int $i) {
                return array('bearer' => $ctx['bearer'], 'post' => array('amount' => '10', 'reason' => 'race'));
            });
        $ok = 0; $insuf = 0; $other = array();
        foreach ($res as $x) {
            $c = race_classify($x);
            if ($c === 'OK') { $ok++; } elseif ($c === 'POINT_INSUFFICIENT') { $insuf++; } else { $other[] = $c . ' ' . substr((string) $x['body'], 0, 120) . ' ' . substr((string) $x['log'], -160); }
        }
        $pdo = race_pdo($db);
        $u = $pdo->query('SELECT points, total_points_spent FROM app_ulogin_users WHERE id = ' . (int) $ctx['uid'])->fetch();
        $logs = (int) $pdo->query('SELECT COUNT(*) FROM app_ulogin_point_logs WHERE user_id = ' . (int) $ctx['uid'])->fetchColumn();
        $minBal = $pdo->query('SELECT MIN(balance_after) FROM app_ulogin_point_logs WHERE user_id = ' . (int) $ctx['uid'])->fetchColumn();
        $pdo = null;
        race_db_remove($db);
        $lines[] = 'r' . $r . ' ok=' . $ok . ' insufficient=' . $insuf . ' other=' . count($other) . ' points=' . $u['points']
            . ' spent=' . $u['total_points_spent'] . ' ledger_rows=' . $logs . ' min_balance_after=' . var_export($minBal, true);
        if ($ok !== 5 || $insuf !== 3 || $other !== array() || (int) $u['points'] !== 0 || (int) $u['total_points_spent'] !== 50
            || $logs !== 5 || (int) $minBal !== 0) {
            $bad[] = end($lines) . ' ' . implode(' / ', array_slice($other, 0, 1));
        }
    }
    note("T-RACE-3 consume rounds:\n      " . implode("\n      ", $lines));
    if ($bad !== array()) {
        fail('double spend or ledger drift: ' . implode(' | ', $bad));
    }
});

t('T-RACE-3 points/consume idempotency_key: 8 concurrent same key -> 1 charge + 7 identical replays (3 rounds)', function () {
    race_require();
    $box = race_main_box();
    $lines = array();
    $bad = array();
    for ($r = 1; $r <= 3; $r++) {
        list($db, $res, $ctx) = race_http_round($box, 'idem_r' . $r, 'api/points/consume.php', 8,
            function (PDO $pdo) {
                list($uid) = race_seed_base($pdo, 100);
                return array('uid' => $uid, 'bearer' => race_seed_bearer($pdo, $uid));
            },
            function (array $ctx, int $i) {
                return array('bearer' => $ctx['bearer'], 'post' => array('amount' => '7', 'reason' => 'race',
                    'idempotency_key' => 'race-key-1'));
            });
        $fresh = 0; $replay = 0; $other = array(); $logIds = array();
        foreach ($res as $x) {
            $j = json_decode((string) $x['body'], true);
            if (is_array($j) && !empty($j['ok'])) {
                $logIds[(int) $j['data']['log_id']] = true;
                if (!empty($j['data']['idempotent_replay'])) { $replay++; } else { $fresh++; }
            } else {
                $other[] = race_classify($x) . ' ' . substr((string) $x['body'], 0, 120) . ' ' . substr((string) $x['log'], -160);
            }
        }
        $pdo = race_pdo($db);
        $pts = (int) $pdo->query('SELECT points FROM app_ulogin_users WHERE id = ' . (int) $ctx['uid'])->fetchColumn();
        $logs = (int) $pdo->query("SELECT COUNT(*) FROM app_ulogin_point_logs WHERE idempotency_key = 'race-key-1'")->fetchColumn();
        $pdo = null;
        race_db_remove($db);
        $lines[] = 'r' . $r . ' fresh=' . $fresh . ' replay=' . $replay . ' other=' . count($other) . ' distinct_log_ids='
            . count($logIds) . ' points=' . $pts . ' ledger_rows=' . $logs;
        if ($fresh !== 1 || $replay !== 7 || count($logIds) !== 1 || $pts !== 93 || $logs !== 1) {
            $bad[] = end($lines) . ' ' . implode(' / ', array_slice($other, 0, 1));
        }
    }
    note("T-RACE-3 idempotency rounds:\n      " . implode("\n      ", $lines));
    if ($bad !== array()) {
        fail('idempotency key did not hold under concurrency: ' . implode(' | ', $bad));
    }
});

t('T-RACE-3 auth/email_verify: same token x 8 concurrent -> exactly 1 verified, 7 TOKEN_INVALID (3 rounds)', function () {
    race_require();
    $box = race_main_box();
    $lines = array();
    $bad = array();
    for ($r = 1; $r <= 3; $r++) {
        list($db, $res) = race_http_round($box, 'ev_r' . $r, 'api/auth/email_verify.php', 8,
            function (PDO $pdo) {
                list($uid) = race_seed_base($pdo);
                $email = (string) $pdo->query('SELECT email FROM app_ulogin_users WHERE id = ' . (int) $uid)->fetchColumn();
                $tok = bin2hex(random_bytes(32));
                $pdo->prepare('INSERT INTO app_ulogin_email_verifications (token_hash, user_id, email, expires_at, used_at,'
                    . ' created_ip, created_at) VALUES (?, ?, ?, ?, NULL, ?, ?)')
                    ->execute(array(hash('sha256', $tok), $uid, $email, race_now(600), '127.0.0.1', race_now()));
                return array('tok' => $tok);
            },
            function (array $ctx, int $i) {
                return array('post' => array('token' => $ctx['tok']));
            });
        $ok = 0; $inv = 0; $other = array();
        foreach ($res as $x) {
            $c = race_classify($x);
            if ($c === 'OK') { $ok++; } elseif ($c === 'TOKEN_INVALID') { $inv++; } else { $other[] = $c . ' ' . substr((string) $x['body'], 0, 120) . ' ' . substr((string) $x['log'], -160); }
        }
        $pdo = race_pdo($db);
        $used = (int) $pdo->query('SELECT COUNT(*) FROM app_ulogin_email_verifications WHERE used_at IS NOT NULL')->fetchColumn();
        $pdo = null;
        race_db_remove($db);
        $lines[] = 'r' . $r . ' ok=' . $ok . ' token_invalid=' . $inv . ' other=' . count($other) . ' used_rows=' . $used;
        if ($ok !== 1 || $inv !== 7 || $other !== array() || $used !== 1) {
            $bad[] = end($lines) . ' ' . implode(' / ', array_slice($other, 0, 1));
        }
    }
    note("T-RACE-3 email_verify rounds:\n      " . implode("\n      ", $lines));
    if ($bad !== array()) {
        fail('email verification token was not single-use: ' . implode(' | ', $bad));
    }
});


// ---------------------------------------------------------------------
// D-339 — 자식 프로세스 강제 시간 제한. **변이 = 멈춘 자식 하나를 실제로 심는다.**
// 지난 판에 경합 자식 하나가 수 시간 멈췄다(101 §4). race_spawn() 이 기한 뒤 그 자식의
// PID 트리만 죽이고 HARNESS-TIMEOUT 으로 **실패**해야 한다 — 기다리면 이 케이스 자체가 끝나지 않는다.
// 정상 자식(같은 라운드의 짝)은 끝까지 결과를 낸다.
// ---------------------------------------------------------------------
t('D-339 a hung race worker is killed after the deadline (own PID tree) and the round FAILS instead of waiting', function () {
    race_require();
    $box  = race_box('hang');
    $prev = getenv('ULOGIN_TEST_CHILD_TIMEOUT');
    putenv('ULOGIN_TEST_CHILD_TIMEOUT=4');
    $t0 = microtime(true);
    $msg = null;
    try {
        race_spawn($box, array(array('mode' => 'hang'), array('mode' => 'hang')), 0.0);
    } catch (Throwable $e) {
        $msg = $e->getMessage();
    }
    putenv($prev === false ? 'ULOGIN_TEST_CHILD_TIMEOUT' : 'ULOGIN_TEST_CHILD_TIMEOUT=' . $prev);
    $took = microtime(true) - $t0;
    assert_true($msg !== null, 'race_spawn must FAIL on a hung worker (it returned normally)');
    assert_contains('HARNESS-TIMEOUT', (string) $msg, 'failure names the timeout');
    assert_true($took < 30.0, 'gave up within the deadline + kill grace (took ' . sprintf('%.1f', $took) . 's)');
    // 자식 php.exe 가 정말 죽었는가 — 래퍼(cmd.exe)만 죽이면 좀비가 남는다
    $pids = array();
    foreach (scandir($box) as $e) {
        if (strpos($e, 'run_') !== 0) {
            continue;
        }
        foreach (scandir($box . '/' . $e) as $f) {
            if (substr($f, -4) === '.pid') {
                $pids[] = (int) file_get_contents($box . '/' . $e . '/' . $f);
            }
        }
    }
    assert_eq(2, count($pids), 'both hung workers started and wrote their PID (the plant took)');
    usleep(500000);
    foreach ($pids as $p) {
        assert_true(!proc_pid_alive($p), 'hung worker php.exe pid ' . $p . ' is gone (tree kill, not wrapper kill)');
    }
    note('D-339: 2 hung workers killed in ' . sprintf('%.1f', $took) . 's (deadline 4s)');
});
