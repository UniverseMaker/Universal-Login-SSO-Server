<?php
declare(strict_types=1);

/**
 * 티어 3 — **폭발 반경** (v1.1.0 `17a` 의 "api/** 32파일 × GET+POST = 64요청" 복원)
 *
 * 모든 엔드포인트에 **인증 없이** GET 과 POST 를 한 번씩 던지고, 응답이 계약의
 * 봉투를 지키는지 본다. 목적은 기능 검증이 아니라 **경계면의 최저 보증**이다:
 *
 *   · 어떤 엔드포인트도 인증 없이 500 을 내지 않는다 (설정 문제는 fail-closed 로
 *     의도된 500 이 있을 수 있고, 그것은 여기서 목록으로 드러난다)
 *   · 모든 응답이 `{ok, data, error}` 봉투다. HTML 오류 페이지가 새어 나오면
 *     프론트의 `api()` 가 파싱에 실패하고 화면은 아무 말도 못 한다
 *   · 응답에 **서버 절대 경로·SQL·스택 트레이스**가 없다
 *   · 캐시 헤더가 붙어 있다 (세션·시크릿을 싣는 응답이다)
 *
 * ─── 못 잡는 것 ───────────────────────────────────────────────────────
 *  · **인증된 상태의 동작.** 여기는 익명 요청만 본다. 로그인 후의 흐름은
 *    `t_50_regression_walk.php` 가 본다
 *  · `.htaccess` 재작성. `php -S` 는 그것을 읽지 않으므로 물리 경로
 *    (`/server/api/auth/login.php`)로 부른다. **계약의 확장자 없는 경로가
 *    실제로 도달하는지는 여기서 검증되지 않는다** — 배포 후 확인 항목이다
 */

require_once __DIR__ . '/../lib/live.php';
require_once __DIR__ . '/../lib/checks.php';

/** 응답에 절대 경로·SQL·스택이 새어 나왔는가. */
function blast_leak(string $body): ?string
{
    $needles = array(
        'C:\\\\'            => 'a Windows absolute path',
        '/var/www'          => 'a unix absolute path',
        '#0 '               => 'a stack trace frame',
        'Stack trace'       => 'a stack trace',
        'SQLSTATE'          => 'a PDO/SQL error',
        'SELECT '           => 'raw SQL',
        'PDOException'      => 'a PDO exception class name',
        'Fatal error'       => 'a PHP fatal error',
        'Warning:'          => 'a PHP warning',
        'Notice:'           => 'a PHP notice',
        'Deprecated:'       => 'a PHP deprecation',
    );
    foreach ($needles as $n => $what) {
        if (strpos($body, str_replace('\\\\', '\\', $n)) !== false) {
            return $what . ' (' . $n . ')';
        }
    }
    return null;
}

t('every endpoint answers the standard envelope to an anonymous GET and POST', function () {
    live_ready_install();
    live_server();

    $eps = ck_endpoint_files(h_root());   // ['/api/auth/login', ...]
    if (count($eps) < 20) {
        fail('collected only ' . count($eps) . ' endpoints - the collector is blind');
    }

    $bad = array();
    $statuses = array();
    $n = 0;
    foreach ($eps as $ep) {
        $path = '/server' . $ep . '.php';
        foreach (array('GET', 'POST') as $m) {
            $n++;
            $opt = ($m === 'POST') ? array('json' => new stdClass()) : array();
            $r = live_req($m, $path, $opt);

            if ($r['status'] === 0) {
                $bad[] = $m . ' ' . $ep . ': no response at all';
                continue;
            }
            $statuses[$m . ' ' . $ep] = $r['status'];

            // 1) 봉투
            if ($r['json'] === null) {
                $bad[] = $m . ' ' . $ep . ' (HTTP ' . $r['status'] . '): body is not JSON - "'
                    . substr(str_replace("\n", ' ', $r['body']), 0, 100) . '"';
                continue;
            }
            foreach (array('ok', 'data', 'error') as $k) {
                if (!array_key_exists($k, $r['json'])) {
                    $bad[] = $m . ' ' . $ep . ': envelope is missing "' . $k . '"';
                }
            }
            if (isset($r['json']['ok']) && $r['json']['ok'] === false) {
                $e = isset($r['json']['error']) ? $r['json']['error'] : null;
                if (!is_array($e) || !isset($e['code'])) {
                    $bad[] = $m . ' ' . $ep . ': ok=false but error.code is missing';
                } elseif (!array_key_exists('field', $e)) {
                    // 계약 §1-1: field 는 값이 없어도 키를 유지한다
                    $bad[] = $m . ' ' . $ep . ': error object has no "field" key (contract keeps it even when null)';
                }
            }

            // 2) 정보 노출
            $leak = blast_leak($r['body']);
            if ($leak !== null) {
                $bad[] = $m . ' ' . $ep . ' (HTTP ' . $r['status'] . '): response leaks ' . $leak;
            }

            // 3) 캐시 헤더 — 이 응답들은 세션과 시크릿을 싣는다
            $hasNoStore = false;
            foreach ($r['headers'] as $h) {
                if (stripos($h, 'Cache-Control:') === 0 && stripos($h, 'no-store') !== false) {
                    $hasNoStore = true;
                }
            }
            if (!$hasNoStore) {
                $bad[] = $m . ' ' . $ep . ': no "Cache-Control: no-store" on a JSON API response';
            }
        }
    }
    if ($n < 40) {
        fail('only ' . $n . ' requests were made - the sweep is not actually covering the API surface');
    }
    assert_clean($bad, 'endpoint blast radius (' . $n . ' anonymous requests)');
    note('blast radius: ' . $n . ' anonymous requests over ' . count($eps) . ' endpoints, all well-formed');
});

t('no endpoint returns 5xx to an anonymous request except the documented fail-closed ones', function () {
    live_ready_install();
    live_server();
    $eps = ck_endpoint_files(h_root());
    $fivexx = array();
    foreach ($eps as $ep) {
        foreach (array('GET', 'POST') as $m) {
            $opt = ($m === 'POST') ? array('json' => new stdClass()) : array();
            $r = live_req($m, '/server' . $ep . '.php', $opt);
            if ($r['status'] >= 500) {
                $code = isset($r['json']['error']['code']) ? $r['json']['error']['code'] : '?';
                $fivexx[] = $m . ' ' . $ep . ' -> ' . $r['status'] . ' ' . $code;
            }
        }
    }
    /*
     * 이 샌드박스는 **정상 설정**이다 (BASE_URL 있음 · COOKIE_SECURE=false · 평문).
     * 그래서 fail-closed 3종(D-53 · D-67 · D-75)이 발동할 이유가 없다.
     * 여기서 500 이 나오면 그것은 설정이 아니라 **코드**다.
     */
    assert_clean($fivexx, 'anonymous 5xx on a correctly configured install');
});

t('an unauthenticated request to a protected endpoint says AUTH_REQUIRED, not 500 or 200', function () {
    live_ready_install();
    live_server();
    // 인증이 필요한 대표 엔드포인트. 익명이면 401 이어야 한다 —
    // 200 이면 인증 게이트가 없는 것이고, 500 이면 게이트가 깨진 것이다.
    $protectedGet = array(
        '/server/api/me/index.php',
        '/server/api/me/sessions.php',
        '/server/api/me/points.php',
        '/server/api/me/services.php',
        '/server/api/me/login_logs.php',
        '/server/api/admin/users.php',
        '/server/api/admin/stats.php',
        '/server/api/admin/logs.php',
    );
    $bad = array();
    foreach ($protectedGet as $p) {
        $r = live_req('GET', $p, array());
        $code = isset($r['json']['error']['code']) ? $r['json']['error']['code'] : null;
        if ($r['status'] !== 401 || $code !== 'AUTH_REQUIRED') {
            $bad[] = $p . ' -> HTTP ' . $r['status'] . ' ' . ut_show($code) . ' (expected 401 AUTH_REQUIRED)';
        }
    }
    assert_clean($bad, 'authentication gate');
});

t('a wrong HTTP method is refused with METHOD_NOT_ALLOWED, not a crash', function () {
    live_ready_install();
    live_server();
    // login 은 POST 전용, me/password 는 PUT 전용이다.
    $r = live_req('GET', '/server/api/auth/login.php');
    $code = isset($r['json']['error']['code']) ? $r['json']['error']['code'] : null;
    assert_eq(405, $r['status'], 'GET on a POST-only endpoint');
    assert_eq('METHOD_NOT_ALLOWED', $code);

    $r2 = live_req('POST', '/server/api/me/password.php', array('json' => new stdClass()));
    $code2 = isset($r2['json']['error']['code']) ? $r2['json']['error']['code'] : null;
    // 인증 게이트와 메서드 게이트 중 어느 쪽이 먼저인지는 구현 선택이다.
    // 중요한 것은 **둘 중 하나로 깔끔하게 거부**하는 것.
    if (!in_array($r2['status'], array(401, 405), true)) {
        fail('POST on a PUT-only endpoint gave HTTP ' . $r2['status'] . ' ' . ut_show($code2));
    }
});
