<?php
declare(strict_types=1);
/**
 * GET /api/health — 헬스체크 · 자가 진단 (v1.4.0 신규)
 * 담당 계약: 신규 추가 항목. 리더 판정 D-154(3) / 운영 조사 C-1
 * 응답 최상위 키(익명): status
 * 응답 최상위 키(admin 이상): status, checks, php, db, schema, storage, installer, config, counts, time
 *
 * **기존 엔드포인트의 응답 shape 을 바꾸지 않는다.** 새로 추가된 경로다.
 *
 * ── 왜 필요한가 ──────────────────────────────────────────────────────
 * v1.3.0 까지 장애 진단의 유일한 수단이 **운영 config.php 에서 DEBUG=true 를 켜고
 * 재현하는 것**이었고, 문서가 스스로 그것을 위험하다고 경고했다. 켠 것을 되돌리지
 * 않으면 예외 메시지가 응답에 실린다.
 *
 * ── 무엇을 누구에게 보이는가 ────────────────────────────────────────
 * 이 저장소는 **공개**돼 있다. 버전 문자열 하나가 그 태그의 전체 소스와
 * 「고치지 않은 항목」목록으로 바로 이어진다. 그래서 노출을 두 단계로 나눈다.
 *
 *   익명           : `{"status":"ok"}` 또는 503 `{"status":"unavailable"}` — **그것뿐이다.**
 *                    모니터링이 필요로 하는 것은 살아 있는지 여부뿐이고,
 *                    PHP 버전·DB 종류·설치 상태는 정찰 재료다.
 *   role >= admin  : 아래 상세 전부.
 *
 * **admin 에게도 내보내지 않는 것:** DB 이름·계정·호스트, 파일 절대경로,
 * SECRET_ENC_KEY·APP_KEY·DB_PASS 의 값이나 그 일부.
 * 키는 `유효한 형식인가` 라는 **불리언**으로만 답한다.
 *
 * ── DB 가 죽었을 때 ─────────────────────────────────────────────────
 * 이 엔드포인트는 DB 장애에서도 답해야 한다. 그래서 인증보다 먼저 DB 를 직접
 * 두드려 보고, require_auth() 는 try 로 감싼다 — 인증이 죽어도 503 을 낼 수 있어야 한다.
 */
require_once __DIR__ . '/../core/bootstrap.php';

only_method('GET', 'HEAD');   // 모니터링 도구는 HEAD 를 쓰는 경우가 많다

// 모니터링이 자주 두드리는 경로다. 전역 api:ip 300/60 위에 전용 버킷을 더 둔다.
rate_limit('health:ip:' . client_ip(), 60, 60);

/** DB 왕복 1회. 예외를 밖으로 내보내지 않는다. */
function health_db_probe(): array
{
    try {
        $v = db_val('SELECT VERSION()');
        return ['connected' => true, 'server_version' => (string) $v];
    } catch (Throwable $e) {
        error_log('[ulogin] health db probe failed: ' . $e->getMessage());
        return ['connected' => false, 'server_version' => null];
    }
}

$db = health_db_probe();

// 인증. 실패·미인증·계정상태 예외 전부 '익명' 으로 떨어뜨린다 —
// 헬스체크가 계정 상태 때문에 답을 못 하면 진단 도구로 쓸 수 없다.
$ctx = null;
try {
    $ctx = require_auth(false);
} catch (Throwable $e) {
    $ctx = null;
}
$isAdmin = ($ctx !== null && role_gte(ctx_global_role($ctx), 'admin'));

if (!$isAdmin) {
    if (!$db['connected']) {
        json_err('SERVER_ERROR', 503, ['status' => 'unavailable']);
    }
    json_out(['status' => 'ok']);
}

// ---------------------------------------------------------------------
// 여기부터 role >= admin
// ---------------------------------------------------------------------

$checks = [];   // [key, ok(bool|null), note] — null 은 '판단하지 않음'

$phpOk = (PHP_VERSION_ID >= 70200);
$checks[] = ['key' => 'php_version', 'ok' => $phpOk,
             'note' => $phpOk ? null : 'PHP 7.2 미만에서는 동작을 보장하지 않습니다'];
$checks[] = ['key' => 'db', 'ok' => $db['connected'],
             'note' => $db['connected'] ? null : 'DB 에 접속할 수 없습니다'];

// 스키마 리비전 — 미적용 마이그레이션이 있으면 그것이 장애의 원인일 때가 많다.
$schema = ['migrations_table' => false, 'applied' => 0, 'pending' => [], 'latest_applied' => null];
if ($db['connected']) {
    try {
        $rows = db_all('SELECT version, status FROM ' . T_MIGRATIONS . ' ORDER BY version ASC');
        $schema['migrations_table'] = true;
        $done = [];
        foreach ($rows as $r) {
            if (in_array((string) $r['status'], ['applied', 'baseline'], true)) {
                $done[(string) $r['version']] = true;
                $schema['applied']++;
                $schema['latest_applied'] = (string) $r['version'];
            } else {
                $schema['pending'][] = (string) $r['version'] . ' (' . (string) $r['status'] . ')';
            }
        }
        // 파일은 있는데 기록이 없는 것도 미적용이다. glob() 금지 — scandir + 수동 필터.
        $names = @scandir(__DIR__ . '/../migrations');
        if ($names !== false) {
            foreach ($names as $f) {
                $m = [];
                if (preg_match('/^([0-9]{4})_[A-Za-z0-9._-]+\.sql$/', (string) $f, $m) === 1
                    && !isset($done[$m[1]])) {
                    $has = false;
                    foreach ($schema['pending'] as $p) {
                        if (strpos($p, $m[1]) === 0) {
                            $has = true;
                        }
                    }
                    if (!$has) {
                        $schema['pending'][] = $m[1] . ' (pending)';
                    }
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[ulogin] health schema probe failed: ' . $e->getMessage());
    }
}
sort($schema['pending']);
$checks[] = ['key' => 'schema', 'ok' => ($schema['migrations_table'] && $schema['pending'] === []),
             'note' => $schema['migrations_table']
                 ? ($schema['pending'] === [] ? null : '미적용 마이그레이션이 있습니다. server/migrate.php 를 여세요')
                 : '스키마 리비전 테이블이 없습니다. server/migrate.php 를 여세요'];

// 저장소 — 메일 드라이버 log 와 마이그레이션 시도 제한이 여기 쓴다.
$storageDir = __DIR__ . '/../storage';
$storage = [
    'exists'   => is_dir($storageDir),
    'writable' => is_dir($storageDir) && is_writable($storageDir),
];
$checks[] = ['key' => 'storage', 'ok' => $storage['writable'],
             'note' => $storage['writable'] ? null : 'server/storage/ 에 쓸 수 없습니다'];

// 설치기 — 남아 있으면 인증 없이 도는 코드가 서버에 있다는 뜻이다.
$installer = [
    'install_php_present' => is_file(__DIR__ . '/../install.php'),
    'lock_present'        => is_file(__DIR__ . '/../.install.lock') || is_file($storageDir . '/.installed'),
];
$checks[] = ['key' => 'installer_removed', 'ok' => !$installer['install_php_present'],
             'note' => $installer['install_php_present']
                 ? 'server/install.php 가 남아 있습니다. 잠금 파일이 재실행을 막지만 삭제가 확실한 방어입니다'
                 : null];

// 설정 — **값이 아니라 판정만** 싣는다.
$encOk = defined('SECRET_ENC_KEY') && strlen((string) SECRET_ENC_KEY) === 64
         && ctype_xdigit((string) SECRET_ENC_KEY);
$appOk = defined('APP_KEY') && strlen((string) APP_KEY) === 64 && ctype_xdigit((string) APP_KEY);
$https = request_is_https();
$config = [
    'force_https'          => defined('FORCE_HTTPS') && FORCE_HTTPS,
    'request_is_https'     => $https,
    'cookie_secure'        => defined('COOKIE_SECURE') && COOKIE_SECURE,
    'cookie_samesite'      => defined('COOKIE_SAMESITE') ? (string) COOKIE_SAMESITE : null,
    'trust_proxy'          => defined('TRUST_PROXY') && TRUST_PROXY,
    'trusted_proxies_set'  => defined('TRUSTED_PROXIES') && is_array(TRUSTED_PROXIES) && TRUSTED_PROXIES !== [],
    'mail_driver'          => defined('MAIL_DRIVER') ? (string) MAIL_DRIVER : null,
    'debug'                => defined('DEBUG') && DEBUG,
    'maintenance_mode'     => (bool) setting_get('maintenance_mode', defined('MAINTENANCE_MODE') ? MAINTENANCE_MODE : false),
    'secret_enc_key_valid' => $encOk,
    'app_key_valid'        => $appOk,
];
$checks[] = ['key' => 'secret_enc_key', 'ok' => $encOk,
             'note' => $encOk ? null : 'SECRET_ENC_KEY 가 64자 hex 가 아닙니다. 시크릿 복호화가 전부 실패합니다'];
$checks[] = ['key' => 'debug_off', 'ok' => !$config['debug'],
             'note' => $config['debug'] ? 'DEBUG=true 입니다. 예외 메시지가 응답에 실립니다 — 운영에서는 꺼야 합니다' : null];
$checks[] = ['key' => 'cookie_secure', 'ok' => ($config['cookie_secure'] || !$https),
             'note' => ($config['cookie_secure'] || !$https) ? null
                 : 'HTTPS 로 서비스하면서 COOKIE_SECURE=false 입니다'];
$checks[] = ['key' => 'proxy_config', 'ok' => !($config['trust_proxy'] && !$config['trusted_proxies_set']),
             'note' => ($config['trust_proxy'] && !$config['trusted_proxies_set'])
                 ? 'TRUST_PROXY=true 인데 TRUSTED_PROXIES 가 비어 있습니다 (fail-closed 로 500 이 납니다)'
                 : null];

$counts = ['users_active' => null, 'services_active' => null, 'sessions_live' => null];
if ($db['connected']) {
    try {
        $counts['users_active']    = (int) db_val('SELECT COUNT(*) FROM ' . T_USERS . ' WHERE status = ?', ['active']);
        $counts['services_active'] = (int) db_val('SELECT COUNT(*) FROM ' . T_SERVICES . ' WHERE status = ?', ['active']);
        $counts['sessions_live']   = (int) db_val('SELECT COUNT(*) FROM ' . T_SESSIONS
            . ' WHERE revoked_at IS NULL AND expires_at > ?', [now_utc()]);
    } catch (Throwable $e) {
        error_log('[ulogin] health counts failed: ' . $e->getMessage());
    }
}

$status = 'ok';
foreach ($checks as $c) {
    if ($c['ok'] === false) {
        $status = 'degraded';
    }
}
if (!$db['connected']) {
    $status = 'unavailable';
}

json_out([
    'status'    => $status,
    'checks'    => $checks,
    'php'       => ['version' => PHP_VERSION, 'sapi' => PHP_SAPI, 'supported' => $phpOk],
    'db'        => $db,          // 이름·계정·호스트는 담지 않는다
    'schema'    => $schema,
    'storage'   => $storage,
    'installer' => $installer,
    'config'    => $config,      // 값이 아니라 판정만
    'counts'    => $counts,
    'time'      => iso(now_utc()),
]);
// 상세 응답은 **항상 HTTP 200** 이다. 진단 보고 자체는 성공했고, 봉투의 ok 는
// 그 뜻이다. 장애 여부는 `status` 로 읽는다. 503 을 쓰는 것은 익명 프로브뿐이며
// (모니터링 도구가 읽는 것이 그것이다) 그 응답에는 status 외에 아무것도 없다.
