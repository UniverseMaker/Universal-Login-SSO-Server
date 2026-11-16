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
 *
 * ── 설치 전 — v1.8.0 설계 G-6 (응답 값 `status:"setup"` 추가. shape 무변경) ──────
 *   설치 전(`config.php` 없음)  : **200** `{"status":"setup"}`   — 고장이 아니다. 할 일은 「설치하라」
 *   DB 파일 없음·무결성 실패    : **503** `{"status":"unavailable"}` — 진짜 고장. 할 일은 「백업에서 복구」
 *   DB 접속 실패(MySQL)         : 503 (기존)
 *   정상                        : 200 `{"status":"ok"}` (기존)
 * 지킴이는 HTTP 코드만 보고(본문을 읽지 않는다) `restart_on_unhealthy:false` 다. 둘 다 503 이면
 * 사람이 할 행동이 다른데 같은 신호로 보인다(D-293 ③). 그래서 설치 대기는 200 이다.
 */

// ---------------------------------------------------------------------
// 설치 전 분기 — **bootstrap 보다 앞이어야 한다.** bootstrap 은 config.php 가 없으면
// 500 으로 끝내 이 코드에 도달하지 않는다(G-4 표).
//
// 봉투를 **손으로** 만든다 — 이 파일에서 그런 자리는 여기 하나다. response.php 를 올리려면
// ULOGIN_BOOTSTRAPPED 를 정의해야 하는데, 그 상수는 「bootstrap 을 거쳤다」는 뜻이라
// 설정 없이 정의하면 그 뜻이 흐려진다(D-34). 계약의 봉투 3키(ok·data·error)는 지킨다.
// 익명에게 더 주지 않는다 — 「설치되지 않았다」는 사실은 install.php 가 열려 있다는 것으로
// 이미 공개된다. 경로·버전·DB 정보는 싣지 않는다.
// ---------------------------------------------------------------------
if (!is_file(__DIR__ . '/../config.php')) {
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header_remove('X-Powered-By');
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    http_response_code(200);
    echo '{"ok":true,"data":{"status":"setup"},"error":null}';
    exit;
}

// v1.9.0 D-363 — health 는 quick_check 를 **매번** 돌린다(다른 경로는 프로세스 첫 요청 + 10분 간격 캐시).
define('ULOGIN_FORCE_QUICK_CHECK', 1);
require_once __DIR__ . '/../core/bootstrap.php';

only_method('GET', 'HEAD');   // 모니터링 도구는 HEAD 를 쓰는 경우가 많다

// 모니터링이 자주 두드리는 경로다. 전역 api:ip 300/60 위에 전용 버킷을 더 둔다.
rate_limit('health:ip:' . client_ip(), 60, 60);

/** DB 왕복 1회. 예외를 밖으로 내보내지 않는다. */
function health_db_probe(): array
{
    try {
        // MySQL `SELECT VERSION()` / SQLite 는 sqlite_version 함수 (A21). SQLite 는 여기서
        // 파일 존재·quick_check 가 함께 돈다(core/dbdriver.php) — 실패하면 아래 503 이다.
        $v = db_server_version();
        return ['connected' => true, 'server_version' => $v];
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
             'note' => $db['connected'] ? null : 'DB에 접속할 수 없습니다'];

// db_port_pinned — DB 포트가 **명시 설정돼 있는가** (v1.7.0 / D-241).
//   **값은 싣지 않는다.** 이 파일의 규약이 「DB 이름·계정·호스트를 담지 않는다 /
//   키는 불리언으로만 답한다」이고 포트도 같은 계열이다. 불리언 하나면 충분하다.
//
//   false 가 뜻하는 것: 환경변수 ULOGIN_DB_PORT 도 config.php 의 DB_PORT 도 없다
//   → DSN 에 포트 요소가 없다 → **드라이버 기본값 3306** 으로 간다.
//   한 PC 에 다른 인스턴스가 함께 돌면 그 3306 이 **남의 살아 있는 DB** 일 수 있다.
//   `db` 가 ok 인데 `db_port_pinned` 가 false 라면 **지금 어디에 붙어 있는지 확인하라** —
//   붙긴 붙었다는 사실이 올바른 DB 라는 뜻이 아니다. 그 조합이 가장 위험하다.
//
//   공용 호스팅 단독 배포에서는 false 가 정상이다(인스턴스가 하나뿐이다).
//   그래서 `ok` 를 false 로 내리지 않고 **note 로만 말한다** — 멀쩡한 배포의
//   헬스체크를 빨갛게 만들면 그 화면이 통째로 무시된다.
//
//   ── v1.7.0 2차 (QA Q17-9 / 리더 D-287): **위 문장이 코드와 갈라져 있었다** ──
//   주석은 「ok 를 내리지 않는다」였는데 코드는 `'ok' => $portPinned` 였고, 아래 루프가
//   `ok === false` 를 보고 `status = 'degraded'` 로 바꿨다. 그래서 **공용 호스팅 단독 배포
//   (이 시스템의 기본 형태)에서 status 가 항상 degraded** 였고 콘솔 머리줄이 「일부 점검 실패」를
//   썼다 — 그러면 storage 쓰기 불가 같은 **진짜 장애가 평상시와 구분되지 않는다.**
//   이제 모양을 둘로 나눈다(리더가 정한 모양 — 바꾸려면 리더에게 먼저):
//     `ok`     : **항상 true.** 포트 미고정은 그 자체로 장애가 아니다 → status 를 내리지 않는다
//     `pinned` : 고정 여부 불리언. 화면(admin.html 「서버 상태」 카드)은 **이 값을 읽는다**
//   `db` 가 ok 인데 `pinned` 가 false 인 조합 경보는 화면이 `pinned === false` 로 판정한다(frontend 짝).
//   이 항목에만 `pinned` 가 있다 — 다른 checks[] 행의 모양은 그대로다.
//
//   ── v1.8.0 (설계 E-3): **SQLite 경로에서는 `pinned: null`** = 판단하지 않음 ──
//   SQLite 에는 DB 포트가 없다. 여기서 false 를 내면 「3306 으로 갈 수 있다」는 **거짓 경보**가
//   되고, 거짓 경보가 쌓이면 그 화면이 통째로 무시된다(D-263·D-297). 화면은 `pinned === false`
//   로만 경보하므로 null 은 경보가 아니다. 행 모양(key·ok·pinned·note)은 그대로다.
try {
    $isSqlite = (db_driver() === 'sqlite');
} catch (Throwable $e) {
    $isSqlite = false;
}
$portPinned = $isSqlite ? null : ulogin_db_port_pinned();
$checks[] = ['key' => 'db_port_pinned', 'ok' => true, 'pinned' => $portPinned,
             'note' => $isSqlite
                 ? 'SQLite 저장소에는 DB 포트가 없습니다(해당 없음).'
                 : ($portPinned
                 ? null
                 : 'DB 포트가 설정되지 않아 드라이버 기본값(3306)으로 접속합니다. '
                 . '같은 서버에 다른 MySQL 인스턴스가 있으면 config.php에 DB_PORT를 지정하세요.')];

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
                 ? ($schema['pending'] === [] ? null : '미적용 마이그레이션이 있습니다. server/migrate.php를 여세요')
                 : '스키마 리비전 테이블이 없습니다. server/migrate.php를 여세요'];

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
                 ? 'server/install.php가 남아 있습니다. 잠금 파일이 재실행을 막지만 삭제가 확실한 방어입니다'
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
    // 홉 수는 **값 자체가 비밀이 아니고** 오설정 진단에 그 숫자가 필요하다
    // (「몇 번째를 쓰고 있나」를 모르면 client_ip() 결과를 해석할 수 없다). D-272.
    'trusted_proxy_hops'   => trusted_proxy_hops(),
    'mail_driver'          => defined('MAIL_DRIVER') ? (string) MAIL_DRIVER : null,
    'debug'                => defined('DEBUG') && DEBUG,
    'maintenance_mode'     => (bool) setting_get('maintenance_mode', defined('MAINTENANCE_MODE') ? MAINTENANCE_MODE : false),
    'secret_enc_key_valid' => $encOk,
    'app_key_valid'        => $appOk,
];
$checks[] = ['key' => 'secret_enc_key', 'ok' => $encOk,
             'note' => $encOk ? null : 'SECRET_ENC_KEY가 64자 hex가 아닙니다. 시크릿 복호화가 전부 실패합니다'];
$checks[] = ['key' => 'debug_off', 'ok' => !$config['debug'],
             'note' => $config['debug'] ? 'DEBUG=true 입니다. 예외 메시지가 응답에 실립니다. 운영에서는 꺼야 합니다' : null];
$checks[] = ['key' => 'cookie_secure', 'ok' => ($config['cookie_secure'] || !$https),
             'note' => ($config['cookie_secure'] || !$https) ? null
                 : 'HTTPS로 서비스하면서 COOKIE_SECURE=false 입니다'];
$checks[] = ['key' => 'proxy_config', 'ok' => !($config['trust_proxy'] && !$config['trusted_proxies_set']),
             'note' => ($config['trust_proxy'] && !$config['trusted_proxies_set'])
                 ? 'TRUST_PROXY=true 인데 TRUSTED_PROXIES가 비어 있습니다 (fail-closed로 500이 납니다)'
                 : null];

// proxy_trust_sane — **반대 방향**의 오설정 (감사 M17-2 / v1.7.0).
//   TRUST_PROXY=false 인데 앞단이 붙인 헤더가 루프백 peer 로 들어오는 상태.
//   `proxy_config` 가 잡는 것은 TRUST_PROXY=true + TRUSTED_PROXIES=[] 한 조합뿐이라
//   이 방향에는 신호가 하나도 없었다. 그 상태의 증상은 **아무 증상도 없는 것**이다 —
//   화면도 로그도 정상이고, 다만 모든 클라이언트가 127.0.0.1 로 집계된다.
//   **값은 싣지 않는다**(db_port_pinned · trust_proxy 와 같은 규약 — 불리언만).
$proxySane = !proxy_trust_probably_missing();
$checks[] = ['key' => 'proxy_trust_sane', 'ok' => $proxySane,
             'note' => $proxySane ? null
                 : '프록시가 붙인 헤더가 들어오는데 TRUST_PROXY가 꺼져 있습니다. '
                 . '모든 접속이 127.0.0.1로 집계되어 IP 레이트리밋이 공용 버킷 1개가 되고 '
                 . '로그인 이력의 IP가 전부 같아집니다. TRUST_PROXY=true + TRUSTED_PROXIES를 설정하세요.'];

// v1.9.0 (설계 116 §11 · H-3) — 감사 체인. 마지막 100행 검증 + 24시간 안 쓰기 실패 표식.
// 0007 미적용(표 없음)이면 null(판단하지 않음). 값(해시·키)은 싣지 않는다 — 불리언만.
$auditChainOk = null;
if ($db['connected']) {
    try {
        if (db_table_exists(T_PLAT_AUDIT)) {
            $maxSeq = (int) db_val('SELECT MAX(id) FROM ' . T_PLAT_AUDIT);
            $v = audit_verify(max(1, $maxSeq - 99), 100, false);   // 머리 사본은 증분 + 꼬리 1,000줄만(전체는 관리자 검증·하루 1회)
            $failAt = setting_get(AUDIT_FAIL_KEY, null);
            $recentFail = is_string($failAt) && (int) ts($failAt) > time() - 86400;
            $auditChainOk = $v['ok'] && !$recentFail;
        }
    } catch (Throwable $e) {
        error_log('[ulogin] health audit chain check failed: ' . $e->getMessage());
        $auditChainOk = false;
    }
}
// 앵커 새로 시작(M190-4 후속) — DB 밖 머리 사본을 최근 7일 안에 새로 만들었으면 알린다. 그 이전의 꼬리 절단은
// 이 사본으로 탐지할 수 없다(새 위치·삭제·첫 배포). 판정을 내리지 않는다(ok=null) — 정보다.
$anchorAt = $auditChainOk === null ? null : setting_get(AUDIT_ANCHOR_KEY, null);
if (is_string($anchorAt) && (int) ts($anchorAt) > time() - 7 * 86400) {
    $checks[] = ['key' => 'audit_anchor_restarted', 'ok' => null,
                 'note' => '감사 머리 앵커를 새로 시작했습니다(' . iso($anchorAt) . '). 그 이전의 꼬리 삭제는 이 앵커로 탐지할 수 없습니다'];
}
$checks[] = ['key' => 'audit_chain', 'ok' => $auditChainOk,
             'note' => $auditChainOk === false ? '감사 체인 검증 실패 또는 최근 24시간 감사 기록 실패가 있습니다. 관리자 플랫폼 로그에서 체인을 검증하세요' : null];

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

// v1.10.0 (설계 127 §6-2) — 비상 관리자 계정 상태. "unset"|"set"|null(없음·0010 미적용). 값·시각은 내지 않는다.
$emergencyAdmin = null;
if ($db['connected']) {
    try {
        $eh = db_val('SELECT password_hash FROM ' . T_USERS . ' WHERE account_kind = ? ORDER BY id ASC LIMIT 1', ['emergency']);
        if ($eh !== null) {
            $emergencyAdmin = ((string) $eh === EMERGENCY_UNSET_HASH) ? 'unset' : 'set';
        }
    } catch (Throwable $e) {
        $emergencyAdmin = null;   // 0010 미적용 — 컬럼이 없다
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
    'audit_chain_ok' => $auditChainOk,   // v1.9.0 — 1키 추가(설계 116 §11). null = 0007 미적용
    'emergency_admin' => $emergencyAdmin,   // v1.10.0 — 1키 추가(설계 127 §6-2)
    'time'      => iso(now_utc()),
]);
// 상세 응답은 **항상 HTTP 200** 이다. 진단 보고 자체는 성공했고, 봉투의 ok 는
// 그 뜻이다. 장애 여부는 `status` 로 읽는다. 503 을 쓰는 것은 익명 프로브뿐이며
// (모니터링 도구가 읽는 것이 그것이다) 그 응답에는 status 외에 아무것도 없다.
