<?php
/**
 * server/migrate.php — 스키마 마이그레이션 러너 (v1.4.0 신규)
 * 담당 계약: 리더 판정 D-151
 *
 * =====================================================================
 * **이 파일은 core/bootstrap.php 를 로드하지 않는다.**
 *
 * 마이그레이션 러너는 **스키마가 코드보다 낡은 상태**에서 도는 코드다.
 * core/ 의 SELECT 는 최신 스키마를 전제하므로 그 상태에서 부르면 러너 자신이
 * 죽는다 — 고치러 온 코드가 고칠 대상 때문에 죽는 구조가 된다.
 * install.php 가 core/bootstrap.php 를 로드하지 않는 것과 같은 이유다.
 *
 * 그래서 여기에는 config.php(상수) + PDO + migrations/lib.php 가 있고,
 * core/ 에서 오는 것은 **`core/transport.php` 하나**뿐이다 — 그 파일은
 * `$_SERVER` 4키 + `TRUST_PROXY`/`TRUSTED_PROXIES` 만 보고 DB·스키마·다른 core/
 * 파일을 건드리지 않으므로 위 근거에 걸리지 않는다(보안 재검증 V14b-M-3).
 * **그 파일을 통해 DB·설정·응답 헬퍼가 들어오면 위 근거가 깨진다.**
 * 다른 core/ 파일을 여기 require 하지 마라.
 *
 * **PHP 7.2 호환.** 금지 문법 목록은 core/bootstrap.php 상단에 있다.
 * =====================================================================
 *
 * 어디서 도는가 — 두 가지 진입점을 둔다. 공용 호스팅에는 cron 도 셸도
 * 보장되지 않으므로 **웹 화면이 정본**이고 CLI 는 있으면 편한 쪽이다.
 *
 *   웹  : {설치루트}/server/migrate.php 를 브라우저로 연다.
 *         superadmin 아이디·비밀번호를 매 요청 함께 보낸다(세션을 만들지 않는다).
 *   CLI : php server/migrate.php status|apply|verify
 *         셸이 있으면 인증 없이 쓴다 — 셸 접근 자체가 이미 더 큰 권한이다.
 *
 * **미인증 GET 은 아무 정보도 주지 않는다.** 저장소가 공개돼 있으므로
 * 버전·미적용 목록·DB 상태는 전부 인증 뒤에만 보인다.
 *
 * **`FORCE_HTTPS` 가 켜져 있으면 평문 HTTP 요청을 403 으로 거부한다** — 리다이렉트가
 * 아니다(V14-H-3). 이 파일은 core/bootstrap.php 의 301 을 지나지 않고, 매 요청
 * 최고관리자 비밀번호를 받는다. 301 로 되던지면 브라우저가 그 비밀번호를 다시 보낸다.
 *
 * 되돌릴 수 없는 작업이다. down 마이그레이션을 두지 않는다 —
 * 공용 호스팅에서 신뢰할 수 있는 롤백은 **DB 백업**뿐이고, 반쪽짜리 down 은
 * 있다는 사실 자체가 백업을 건너뛰게 만든다. 화면이 백업을 먼저 요구한다.
 */

if (!defined('ULOGIN_MIGRATE_CTX')) {
    define('ULOGIN_MIGRATE_CTX', 1);
}

define('MIG_DIR',      __DIR__ . '/migrations');
define('MIG_SCHEMA',   __DIR__ . '/schema.sql');
define('MIG_CONFIG',   __DIR__ . '/config.php');
define('MIG_STORAGE',  __DIR__ . '/storage');
define('MIG_THROTTLE', MIG_STORAGE . '/.migrate_throttle');
define('MIG_IS_CLI',   PHP_SAPI === 'cli');

if (!MIG_IS_CLI) {
    // D-154(2). 공용 호스팅의 expose_php=On 기본값이 전 응답에 PHP 마이너 버전을
    // 실어 보낸다. 소스가 공개된 지금 버전 지문의 값어치가 올라갔다.
    header_remove('X-Powered-By');
}

ini_set('display_errors', MIG_IS_CLI ? '1' : '0');   // 웹에서는 경로·스택을 내보내지 않는다
ini_set('log_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('UTC');

require_once MIG_DIR . '/lib.php';

// ---------------------------------------------------------------------
// 설정 · DB
// ---------------------------------------------------------------------

function mig_fail($msg, $status = 500)
{
    if (MIG_IS_CLI) {
        fwrite(STDERR, $msg . "\n");
        exit(2);
    }
    http_response_code($status);
    mig_html_start();
    echo '<div class="card"><div class="bad"><b>' . mig_h($msg) . '</b></div></div>';
    mig_html_end();
    exit;
}

if (!is_file(MIG_CONFIG)) {
    mig_fail('server/config.php 가 없습니다. 먼저 install.php 로 설치하세요.', 500);
}
require_once MIG_CONFIG;

// ---------------------------------------------------------------------
// 전송 보안 — 보안 검증 V14-H-3
//
// 이 파일은 core/bootstrap.php 를 지나지 않으므로 거기 있는 FORCE_HTTPS 처리도
// 지나지 않는다. 실측: 같은 config 로 `/api/*` 는 301, `install.php` 는 403(설치 잠금)
// 인데 **migrate.php 만 평문 200 으로 열리고 비밀번호 입력란을 내보냈다.**
// 이 화면은 매 요청 superadmin 비밀번호를 POST 로 받는다. HSTS 도 없어서(A-8 미해결)
// 브라우저가 대신 막아 주지도 않는다 — 북마크의 http:// 링크 한 번이면 성립한다.
//
// **리다이렉트가 아니라 거부다.** 301 은 두 가지를 만든다.
//   1) POST 를 되던지면 브라우저가 비밀번호를 다시 보내면서, 평문 전송이 이미
//      한 번 일어난 사실을 가린다. 자격증명은 재전송이 아니라 폐기가 맞다.
//   2) 목적지를 만들려면 호스트가 필요하다. `HTTP_HOST` 는 클라이언트가 정하는
//      값이라 그대로 쓰면 호스트 헤더 주입이 되고(감사 M-2 / D-53), BASE_URL 을
//      쓰면 BASE_URL 이 비었거나 깨진 배포에서 또 갈라진다.
// 거부는 어느 쪽도 필요로 하지 않는다. 그래서 GET·POST 모두 403 이다 —
// `install.php` 가 열지 않기로 한 상태에 403 을 쓰는 것과 같은 자리다.
// 올바른 주소는 **본문에 글자로만** 적는다(Location 헤더를 만들지 않는다).
//
// ── 프록시 헤더 — 보안 재검증 V14b-M-3 ────────────────────────────────
// 첫 구현은 프록시 헤더를 보지 않았다. 조건식을 복제하지 않으려는 판단이었고
// (D-51·D-67 이 정확히 그 사고다) 그 판단 자체는 옳았다. 그러나 결과가 나빴다:
// `TRUST_PROXY=true` + `TRUSTED_PROXIES` 를 올바로 채운 **정상 프록시 배포**에서
// `X-Forwarded-Proto: https` 를 받은 `/api/*` 는 200 인데 이 화면만 GET·POST 모두
// 403 이었다(실측). 그리고 **CLI 가 없을 가능성이 가장 높은 배포**(공용 호스팅 + CDN)가
// 정확히 그 구성이다 — 그 운영자에게 이 화면은 존재하지 않게 되고, 남는 결론은
// 「FORCE_HTTPS 를 끈다」가 된다. 그건 D-6 을 되돌린다.
//
// 그래서 복제하지 않고 **정의를 옮겼다** — `core/transport.php` 가 그 세 함수를
// 갖는다. 그 파일은 `$_SERVER` 4키 + `TRUST_PROXY`/`TRUSTED_PROXIES` 만 참조하고
// DB·스키마·다른 core/ 파일을 건드리지 않는다(기계 확인). 이 파일 최상단이
// 「core/ 를 로드하지 않는다」고 적은 근거는 **core/ 의 SELECT 가 최신 스키마를
// 전제한다**는 것이고, 전송 판정은 그 근거에 걸리지 않는다.
// 이 파일이 `ULOGIN_MIGRATE_CTX` 를 정의하므로 transport.php 의 직접 실행 차단도 통과한다.
// **transport.php 에 DB·설정·응답 헬퍼를 들이지 마라** — 들이는 순간 이 require 가
// 위 근거를 깨뜨린다.
// ---------------------------------------------------------------------
require_once __DIR__ . '/core/transport.php';

if (!MIG_IS_CLI && defined('FORCE_HTTPS') && FORCE_HTTPS) {
    // 프록시 설정 모순은 리다이렉트·거부보다 **먼저** 잡는다 — core/bootstrap.php 의
    // FORCE_HTTPS 분기와 같은 순서다(D-67). `TRUST_PROXY=true` + `TRUSTED_PROXIES=[]`
    // 이면 홉을 검증할 근거가 없어 헤더를 무시하게 되고, 정상 프록시 배포가
    // **원인 없이** 403 을 받는다. 조용히 넘어가지 않는다 — fail-closed 로 멈추고
    // 사유는 서버 로그에만 남긴다.
    if (trust_proxy_misconfigured()) {
        error_log('[ulogin-migrate] TRUST_PROXY is on but TRUSTED_PROXIES is empty. '
            . 'proxy headers cannot be verified. set TRUSTED_PROXIES to your proxy IP(s) in config.php.');
        mig_fail('서버 설정이 완결되지 않았습니다. 서버 로그를 확인하세요.', 500);
    }
    if (!request_is_https()) {
        error_log('[ulogin-migrate] refused a plaintext request while FORCE_HTTPS is on. from '
            . (string) (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '?'));
        $migUrl = '';
        if (defined('BASE_URL') && is_string(BASE_URL) && strpos((string) BASE_URL, 'https://') === 0
            && strpos((string) BASE_URL, '__') === false) {
            $migUrl = rtrim((string) BASE_URL, '/') . '/server/migrate.php';
        }
        http_response_code(403);
        mig_html_start();
        echo '<div class="card"><div class="bad"><b>HTTPS 로 접속하세요.</b><br>'
           . '이 서버는 <code>FORCE_HTTPS</code> 가 켜져 있습니다. 평문 HTTP 연결에서는 '
           . '이 화면이 자격증명을 받지 않습니다 — 최고관리자 비밀번호가 그대로 흐르기 때문입니다.</div>';
        if ($migUrl !== '') {
            echo '<p>올바른 주소: <code>' . mig_h($migUrl) . '</code></p>';
        }
        echo '<p class="hint">평문으로 비밀번호를 이미 보냈다면 <b>그 비밀번호는 노출된 것으로 보고 바꾸십시오.</b></p>';
        echo '</div>';
        mig_html_end();
        exit;
    }
}

function mig_pdo()
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

// ---------------------------------------------------------------------
// 인증 (웹 전용) — 세션을 만들지 않는다
//
// 매 요청 superadmin 아이디·비밀번호를 함께 받는다. 세션 쿠키가 없으므로
// CSRF 로 이 화면을 대신 조작할 수 없다(공격자는 비밀번호를 모른다).
// 조회 컬럼은 v1.0.0 부터 있던 것만 쓴다 — 스키마가 낡은 DB 에서도 동작해야 한다.
//
// 방어는 셋이다. 보안 검증 V14-H-2 로 셋 다 이 라운드에 들어왔다 — 그전까지 이 화면은
// superadmin 계정에 대한 **레이트리밋 없음 · 락아웃 없음 · DB 기록 없음**의 오라클이었다.
//   1) 파일 기반 시도 제한 5회/15분 — **하나의 배타 잠금 안에서 검증 전에** 소모한다
//   2) `users.locked_until` 존중 — login.php 로 잠긴 계정은 여기서도 잠긴다
//   3) 실패를 `login_logs`(`fail_migrate_auth`)에 남긴다 — 관리자 콘솔에서 보인다
// ---------------------------------------------------------------------

/**
 * 파일 기반 시도 제한 — **검증을 시작하기 전에 한 번의 배타 잠금 안에서 소모한다.**
 * 보안 검증 V14-H-2.
 *
 * 종전 구조는 읽기(`mig_throttle_check`)와 쓰기(`mig_throttle_fail`)가 분리돼 있었고,
 * 그 사이에 `password_verify`(bcrypt, 수십 ms)가 들어갔다. `LOCK_EX` 는 쓰기 순간만
 * 잠그므로 동시에 도착한 요청들은 **전부 같은 count 를 읽고** 각자 `읽은값+1` 을 썼다.
 * 실측: 4워커 동시 16건 → 전부 인증 처리, 429 0건, 카운터 4(상한 5).
 * 순차 7회는 정상이었다 — **동시성에서만 깨지는** 결함이었다.
 *
 * 그래서 셋을 바꿨다.
 *   1) 핸들 하나를 `flock(LOCK_EX)` 로 잡은 채 **읽고 · 판정하고 · 쓰고** 놓는다.
 *      읽기-수정-쓰기 사이에 다른 프로세스가 끼어들 틈이 없다.
 *   2) 카운트를 **비밀번호 검증 전에** 올린다(낙관적 소모). 검증 뒤에 올리면
 *      느린 검증 구간이 다시 경쟁 구간이 되고, 검증 도중에 죽은 요청은 공짜가 된다.
 *      성공하면 mig_throttle_clear() 가 되돌린다.
 *   3) DB 장애 등으로 검증에 못 가는 경로도 이미 소모된 상태다 — 종전에는 그 분기가
 *      mig_throttle_fail() 을 부르지 않아 **무비용 재시도**였다(V14-L-1 의 절반).
 *
 * `rate_limits` 테이블을 쓰지 않는 이유는 그대로 유효하다 — 이 러너는 **스키마가
 * 코드보다 낡은 상태**에서 도는 코드이고, 고치려는 대상에 제한 장치가 의존하면
 * 그 대상이 깨졌을 때 러너도 함께 멈춘다. 잠금은 DB 없이도 성립한다.
 *
 * ── 세지 못하면 **거부한다** — 보안 재검증 V14b-M-2 ────────────────────
 * 첫 구현은 이 함수가 `false` 를 돌려주고 `sleep(1)` 로 비용을 만들었는데,
 * **호출부가 그 반환값을 버렸다.** 결과(실측): throttle 파일을 쓸 수 없는 상태에서
 * 순차 12건 → 12 처리 / 0 차단, 4워커 동시 20건 → 20 처리 / 0 차단(5.4초).
 * `sleep(1)` 은 동시성에서 비용이 되지 못한다 — 잠든 시간이 병렬로 겹치기 때문이다.
 * 그 상태의 이 화면은 **「레이트리밋 없음 · 락아웃 안 올림」의 superadmin 비밀번호
 * 오라클**이고, V14-H-2 로 방금 닫은 것이 조용히 무효가 된다.
 *
 * 이 프로젝트의 fail-closed 규약(D-53·D-67·D-75)이 겨눈 그 형태다 — 같은 파일이
 * `config.php` 부재에는 500 을 내면서 `storage/` 쓰기 불가에는 통과시키는 것은 비대칭이다.
 * 그래서 호출부가 반환값을 받아 **503 으로 거부**한다(`mig_web_auth()` 참고).
 * `sleep(1)` 도 지웠다 — 거부하는 요청에 지연을 붙이면 요청마다 PHP 워커를
 * 1초씩 붙잡아 **자기 DoS 증폭기**가 될 뿐이고, 시도 자체가 없으므로 만들 비용도 없다.
 * 오설정 배포에서 이 화면이 잠기지만 그 배포에는 CLI 대안이 있고 문구가 그것을 가리킨다.
 *
 * @return bool 카운터를 실제로 셀 수 있었는가. **false 를 무시하면 위 결함이 돌아온다.**
 */
function mig_throttle_consume()
{
    $max    = 5;
    $window = 900;
    $now    = time();

    $fh = @fopen(MIG_THROTTLE, 'c+');
    if ($fh === false) {
        // storage/ 에 쓸 수 없으면 횟수를 셀 수 없다 → 호출부가 거부한다.
        error_log('[ulogin-migrate] throttle file not writable: ' . MIG_THROTTLE);
        return false;
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        error_log('[ulogin-migrate] throttle lock failed: ' . MIG_THROTTLE);
        return false;
    }

    $raw = stream_get_contents($fh);
    $st  = ($raw === false) ? null : json_decode((string) $raw, true);
    if (!is_array($st) || !isset($st['first']) || ($now - (int) $st['first']) > $window) {
        $st = array('count' => 0, 'first' => $now);
    }

    if ((int) $st['count'] >= $max) {
        $retry = $window - ($now - (int) $st['first']);
        flock($fh, LOCK_UN);
        fclose($fh);
        mig_fail('시도 횟수를 초과했습니다. ' . max(1, $retry) . '초 뒤에 다시 시도하세요.', 429);
    }

    $st['count'] = (int) $st['count'] + 1;
    $enc = json_encode($st);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, (string) $enc);
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    @chmod(MIG_THROTTLE, 0600);
    return true;
}

function mig_throttle_clear()
{
    @unlink(MIG_THROTTLE);
}

/**
 * 인증 실패를 DB 에도 남긴다 — 보안 검증 V14-H-2.
 *
 * 종전에는 `error_log` 한 줄뿐이었다. 그 결과 이 화면은 superadmin 계정에 대한
 * **레이트리밋 없음 · 락아웃 없음 · DB 기록 없음**의 비밀번호 오라클이었다.
 * 관리자 콘솔의 로그인 로그가 `login.php` 의 시도만 보여 주므로, 여기서 벌어지는
 * 무차별 대입은 콘솔에서 **존재하지 않는 사건**이었다.
 *
 * `core/` 를 로드할 수 없으므로 INSERT 를 직접 쓴다. `login_logs` 는 v1.0.0 부터
 * 있고 컬럼 구성이 그때와 같으므로 **낡은 스키마에서도 안전하다**(v1.0.0 schema.sql
 * 의 login_logs 정의와 대조 확인). `result` 는 VARCHAR(30) 이라 DDL 변경도 없다.
 * 기록 실패가 응답을 막지 않는다 — 이 화면은 DB 가 깨진 상태에서도 떠야 한다.
 */
function mig_log_auth_fail($userId, $usernameInput)
{
    try {
        $q = mig_pdo()->prepare('INSERT INTO `' . DB_PREFIX . 'login_logs`'
            . ' (user_id, username_input, service_id, result, ip, user_agent, created_at)'
            . ' VALUES (?, ?, NULL, ?, ?, ?, ?)');
        $q->execute(array(
            $userId,
            mb_substr((string) $usernameInput, 0, 190, 'UTF-8'),
            'fail_migrate_auth',
            (string) (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''),
            mb_substr((string) (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''), 0, 255, 'UTF-8'),
            gmdate('Y-m-d H:i:s'),
        ));
    } catch (Throwable $e) {
        error_log('[ulogin-migrate] login_logs write failed: ' . $e->getMessage());
    }
}

/** @return array users 행(축소). 실패하면 응답하고 종료한다. */
function mig_web_auth()
{
    $u = isset($_POST['username']) ? (string) $_POST['username'] : '';
    $p = isset($_POST['password']) ? (string) $_POST['password'] : '';
    if ($u === '' || $p === '') {
        // 빈 입력은 DB 도 bcrypt 도 건드리지 않고 아무것도 알려주지 않는다.
        // 여기서 카운터를 소모하면 익명 5요청으로 운영자를 15분 잠그는 자기 DoS 만
        // 넓어진다(V14-L-3). 비용을 붙일 대상이 아니다.
        mig_login_form('아이디와 비밀번호를 입력하세요.');
        exit;
    }

    // 시도 횟수를 **검증보다 먼저** 소모한다(V14-H-2). 아래의 어느 경로로 빠져나가든
    // 비용이 이미 지불돼 있어야 한다 — 인증 성공만 mig_throttle_clear() 로 되돌린다.
    //
    // **셀 수 없으면 자격증명을 받지 않는다** — 보안 재검증 V14b-M-2 (fail-closed).
    // 종전에는 이 반환값을 버렸고, 그래서 storage/ 를 쓸 수 없는 배포에서
    // 시도 제한이 통째로 사라졌다(실측: 동시 20건 → 20 처리 / 0 차단).
    // install.php 가 storage/ 쓰기 가능을 **설치 요건**으로 검사하는 것과 같은 자리다.
    if (!mig_throttle_consume()) {
        mig_fail('시도 횟수를 기록할 수 없어 요청을 받지 않았습니다. '
            . 'server/storage/ 가 웹 서버에게 쓰기 가능한지 확인한 뒤 다시 시도하세요. '
            . '(권한을 고칠 수 없으면 셸에서 php server/migrate.php status 를 쓰십시오.)', 503);
    }

    $row = null;
    try {
        // 조회 컬럼은 v1.0.0 부터 있던 것만 쓴다 — 스키마가 낡은 DB 에서도 동작해야 한다.
        // locked_until 도 v1.0.0 부터 있다(v1.0.0 schema.sql 의 users 정의와 대조 확인).
        $q = mig_pdo()->prepare('SELECT id, username, password_hash, role, status, locked_until FROM `'
            . DB_PREFIX . 'users` WHERE username = ? LIMIT 1');
        $q->execute([mb_strtolower(trim($u), 'UTF-8')]);
        $row = $q->fetch();
        if ($row === false) {
            $row = null;
        }
    } catch (Throwable $e) {
        // V14-L-1: 미인증 POST 로 도달할 수 있는 분기다. DB 상태를 익명에게 확인해 주지
        // 않는다. 사유는 서버 로그에만 남긴다 — 이 파일의 다른 500 들과 같은 문구다.
        error_log('[ulogin-migrate] auth query failed: ' . $e->getMessage());
        mig_fail('요청을 처리할 수 없습니다. 서버 로그를 확인하세요.', 500);
    }

    // 계정이 없어도 반드시 한 번 돌린다 — 즉시 반환하면 응답 시간 차이로
    // 계정 존재 여부가 새어나간다. core/auth.php 의 dummy_password_verify() 와 같은 규약.
    $hash = ($row === null) ? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG'
                            : (string) $row['password_hash'];
    $ok   = password_verify($p, $hash);

    // 계정 락아웃을 존중한다 — V14-H-2. 종전에는 locked_until 을 읽지도 쓰지도 않아
    // `login.php` 로 잠긴 계정이 이 화면에서는 그대로 인증됐다. 두 입구가 같은 계정에
    // 대해 다른 판정을 내리면, 잠금은 **덜 지켜지는 쪽**의 값이 된다.
    // 비밀번호 검증 뒤에 판정한다 — 앞에 두면 잠긴 계정만 password_verify 를 건너뛰어
    // 응답 시간으로 잠금 여부가 새어나간다.
    $locked = false;
    if ($row !== null && !empty($row['locked_until'])) {
        $until = strtotime((string) $row['locked_until'] . ' UTC');
        $locked = ($until !== false && $until > time());
    }

    if (!$ok || $locked || $row === null
        || (string) $row['role'] !== 'superadmin' || (string) $row['status'] !== 'active') {
        error_log('[ulogin-migrate] auth failed from ' . (string) (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '?'));
        mig_log_auth_fail($row === null ? null : (int) $row['id'], $u);
        // 사유를 구분하지 않는다 — 계정 열거·역할 열거·잠금 여부 열거 방지
        mig_login_form('인증에 실패했습니다.');
        exit;
    }
    mig_throttle_clear();
    return $row;
}

// ---------------------------------------------------------------------
// 동작
// ---------------------------------------------------------------------

/** @return array [files, rows, table] */
function mig_load()
{
    $pdo   = mig_pdo();
    $table = mig_ensure_table($pdo, MIG_SCHEMA, DB_PREFIX);
    $files = mig_list(MIG_DIR);
    $rows  = mig_applied_rows($pdo, $table);
    return [$files, $rows, $table];
}

function mig_do_verify()
{
    $expected = mig_expected_schema(MIG_SCHEMA, DB_PREFIX);
    $live     = mig_live_schema(mig_pdo(), DB_PREFIX);
    return mig_schema_diff($expected, $live);
}

// ---------------------------------------------------------------------
// CLI
// ---------------------------------------------------------------------

if (MIG_IS_CLI) {
    $cmd = isset($argv[1]) ? (string) $argv[1] : 'status';
    try {
        if ($cmd === 'status') {
            list($files, $rows, $table) = mig_load();
            $st = mig_status($files, $rows);
            echo "=== 마이그레이션 상태 (" . DB_NAME . ") ===\n";
            if ($st === []) {
                echo "마이그레이션 파일이 없습니다: " . MIG_DIR . "\n";
            }
            $pending = 0;
            foreach ($st as $r) {
                printf("%-6s %-14s %-40s %s\n", $r['version'], $r['state'], $r['name'], $r['note']);
                if (mig_needs_attention($r['state'])) {
                    $pending++;
                }
            }
            echo "\n미적용/미완료: " . $pending . "건\n";
            exit($pending === 0 ? 0 : 1);
        }
        if ($cmd === 'apply') {
            list($files, $rows, $table) = mig_load();
            $log = mig_apply(mig_pdo(), $files, $rows, $table, DB_PREFIX, 'cli', MIG_SCHEMA);
            $bad = 0;
            foreach ($log as $l) {
                echo ($l['ok'] ? '  OK  ' : ' FAIL ') . $l['v'] . '  ' . $l['msg'] . "\n";
                if (!$l['ok']) {
                    $bad++;
                }
            }
            if ($bad === 0) {
                $diff = mig_do_verify();
                echo "\nverify: " . ($diff === [] ? "schema.sql 과 동일\n" : count($diff) . "건 불일치\n");
                foreach ($diff as $d) {
                    echo '  - ' . $d . "\n";
                }
                exit($diff === [] ? 0 : 1);
            }
            exit(1);
        }
        if ($cmd === 'verify') {
            mig_load();   // 리비전 테이블 존재 보장 (schema.sql 대조 대상에 포함된다)
            $diff = mig_do_verify();
            if ($diff === []) {
                echo "OK — 살아 있는 DB 가 schema.sql 과 같은 형태다.\n";
                exit(0);
            }
            echo "불일치 " . count($diff) . "건:\n";
            foreach ($diff as $d) {
                echo '  - ' . $d . "\n";
            }
            exit(1);
        }
        fwrite(STDERR, "usage: php server/migrate.php status|apply|verify\n");
        exit(2);
    } catch (Throwable $e) {
        fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
        exit(2);
    }
}

// ---------------------------------------------------------------------
// 웹
// ---------------------------------------------------------------------

function mig_h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mig_html_start($title = '스키마 마이그레이션')
{
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
    echo '<!doctype html><html lang="ko"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<meta name="robots" content="noindex,nofollow">';
    echo '<title>' . mig_h($title) . '</title><style>';
    echo 'body{font:15px/1.7 system-ui,-apple-system,"Malgun Gothic",sans-serif;background:#f5f6f8;color:#222;margin:0;padding:24px}';
    echo '.wrap{max-width:920px;margin:0 auto}.card{background:#fff;border:1px solid #dde;border-radius:8px;padding:20px;margin-bottom:16px}';
    echo 'h1{font-size:20px;margin:0 0 16px}h2{font-size:16px;margin:20px 0 8px}';
    echo 'table{width:100%;border-collapse:collapse;font-size:13px}th,td{border:1px solid #e3e6ea;padding:6px 8px;text-align:left;vertical-align:top}';
    echo 'th{background:#f2f4f7}code{background:#f2f4f7;padding:1px 4px;border-radius:3px}';
    echo '.bad{color:#a11;background:#fdeaea;border:1px solid #f3c6c6;padding:10px;border-radius:6px}';
    echo '.good{color:#136;background:#eaf3fd;border:1px solid #c6dcf3;padding:10px;border-radius:6px}';
    echo '.warn{background:#fff7e0;border:1px solid #f0dca0;padding:10px;border-radius:6px}';
    echo 'input{font:inherit;padding:6px 8px;border:1px solid #ccd;border-radius:4px;width:260px}';
    echo 'button{font:inherit;padding:8px 16px;border:0;border-radius:4px;background:#2d6cdf;color:#fff;cursor:pointer}';
    echo 'button.danger{background:#b23}';
    echo '</style></head><body><div class="wrap"><h1>ulogin — 스키마 마이그레이션</h1>';
}

function mig_html_end()
{
    echo '</div></body></html>';
}

function mig_login_form($msg = null, $action = 'status')
{
    mig_html_start();
    echo '<div class="card">';
    if ($msg !== null) {
        echo '<div class="bad">' . mig_h($msg) . '</div>';
    }
    echo '<p>최고관리자(<code>superadmin</code>) 계정으로 인증하세요. '
       . '이 화면은 세션을 만들지 않습니다 — 매 요청 아이디·비밀번호를 함께 보냅니다.</p>';
    echo '<form method="post"><input type="hidden" name="action" value="' . mig_h($action) . '">';
    echo '<p><input type="text" name="username" placeholder="아이디" autocomplete="username"></p>';
    echo '<p><input type="password" name="password" placeholder="비밀번호" autocomplete="current-password"></p>';
    echo '<p><button type="submit">확인</button></p></form>';
    echo '</div>';
    mig_html_end();
}

$action = isset($_POST['action']) ? (string) $_POST['action'] : '';
if (strtoupper((string) (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET')) !== 'POST') {
    // 미인증 GET — 로그인 폼 외에는 아무것도 보여주지 않는다.
    mig_login_form(null, 'status');
    exit;
}
if (!in_array($action, ['status', 'apply', 'verify'], true)) {
    mig_login_form('알 수 없는 요청입니다.');
    exit;
}

$admin = mig_web_auth();

try {
    list($files, $rows, $table) = mig_load();
} catch (Throwable $e) {
    error_log('[ulogin-migrate] load failed: ' . $e->getMessage());
    mig_fail('마이그레이션 상태를 읽을 수 없습니다. 서버 로그를 확인하세요.', 500);
}

$applyLog = null;
if ($action === 'apply') {
    if (!isset($_POST['confirm_backup']) || (string) $_POST['confirm_backup'] !== 'yes') {
        $action = 'status';
    } else {
        try {
            $applyLog = mig_apply(mig_pdo(), $files, $rows, $table, DB_PREFIX,
                (string) $admin['username'], MIG_SCHEMA);
        } catch (Throwable $e) {
            error_log('[ulogin-migrate] apply failed: ' . $e->getMessage());
            $applyLog = [['v' => '-', 'ok' => false, 'msg' => '적용 중 오류가 발생했습니다. 서버 로그를 확인하세요.']];
        }
        list($files, $rows, $table) = mig_load();
    }
}

mig_html_start();

if ($applyLog !== null) {
    echo '<div class="card"><h2>적용 결과</h2><table><tr><th>버전</th><th>결과</th><th>내용</th></tr>';
    foreach ($applyLog as $l) {
        echo '<tr><td>' . mig_h($l['v']) . '</td><td>' . ($l['ok'] ? 'OK' : '<b class="bad">FAIL</b>')
           . '</td><td>' . mig_h($l['msg']) . '</td></tr>';
    }
    echo '</table></div>';
}

$status  = mig_status($files, $rows);
$pending = 0;
foreach ($status as $r) {
    if (mig_needs_attention($r['state'])) {
        $pending++;
    }
}

echo '<div class="card"><h2>마이그레이션 상태</h2>';
if ($status === []) {
    echo '<p>마이그레이션 파일이 없습니다.</p>';
} else {
    echo '<table><tr><th>버전</th><th>상태</th><th>이름</th><th>비고</th></tr>';
    foreach ($status as $r) {
        echo '<tr><td>' . mig_h($r['version']) . '</td><td>' . mig_h($r['state']) . '</td><td>'
           . mig_h($r['name']) . '</td><td>' . mig_h($r['note']) . '</td></tr>';
    }
    echo '</table>';
}
echo '<p>미적용·미완료: <b>' . $pending . '</b>건</p></div>';

if ($pending > 0) {
    echo '<div class="card"><h2>적용</h2>';
    echo '<div class="warn"><b>되돌릴 수 없습니다.</b> MySQL 은 DDL 에 트랜잭션이 걸리지 않아 '
       . '중간에 실패해도 앞 문장은 되돌아가지 않습니다. down 마이그레이션은 제공하지 않습니다 — '
       . '공용 호스팅에서 신뢰할 수 있는 복구 수단은 <b>DB 백업</b>뿐입니다. '
       . '<b>지금 phpMyAdmin 등으로 전체 덤프를 받으세요.</b><br>'
       . '중간에 실패하면 이 표에 <code>partial</code> 또는 <code>failed</code> 와 함께 '
       . '<b>몇 번째 문장까지 적용됐는지</b>가 남습니다. 다시 실행하면 이미 적용된 문장은 건너뜁니다.</div>';
    echo '<form method="post"><input type="hidden" name="action" value="apply">';
    echo '<input type="hidden" name="confirm_backup" value="yes">';
    echo '<p><input type="text" name="username" placeholder="아이디" autocomplete="username"></p>';
    echo '<p><input type="password" name="password" placeholder="비밀번호" autocomplete="current-password"></p>';
    echo '<p><button type="submit" class="danger">백업을 받았습니다 — 적용</button></p></form></div>';
}

echo '<div class="card"><h2>두 경로 대조 (verify)</h2>';
echo '<p>신규 설치는 <code>schema.sql</code> 을, 기존 설치본은 <code>migrations/</code> 를 지납니다. '
   . '아래는 <b>지금 이 DB</b> 를 <code>schema.sql</code> 과 대조한 결과입니다. '
   . '두 경로 모두 이 대조를 통과해야 같은 스키마에 도달한 것입니다.</p>';
try {
    $diff = mig_do_verify();
    if ($diff === []) {
        echo '<div class="good"><b>동일합니다.</b> 이 DB 는 schema.sql 과 같은 형태입니다.</div>';
    } else {
        echo '<div class="bad"><b>' . count($diff) . '건 불일치</b></div><ul>';
        foreach ($diff as $d) {
            echo '<li>' . mig_h($d) . '</li>';
        }
        echo '</ul>';
    }
} catch (Throwable $e) {
    error_log('[ulogin-migrate] verify failed: ' . $e->getMessage());
    echo '<div class="bad">대조에 실패했습니다. 서버 로그를 확인하세요.</div>';
}
echo '</div>';

echo '<div class="card"><h2>CLI</h2><p>셸이 있으면 이렇게 쓸 수 있습니다.</p>'
   . '<pre><code>php server/migrate.php status'
   . "\n" . 'php server/migrate.php apply'
   . "\n" . 'php server/migrate.php verify</code></pre>'
   . '<p>종료코드 — <code>0</code> 정상 / <code>1</code> 미적용 또는 불일치 있음 / <code>2</code> 사용법·환경 오류.</p></div>';

mig_html_end();
