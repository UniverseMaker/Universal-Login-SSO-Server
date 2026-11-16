<?php
declare(strict_types=1);

/**
 * server/core/localonly.php — 「서버 PC 에서만」 판별 (v1.10.1 D-374). **판정은 이 함수 한 곳에만 있다.**
 *
 * 라우터(tools/router-ulogin.php)와 화면(server/local_admin.php)이 같은 함수를 부른다. 라우터가 막아도 화면이 한 번 더 본다.
 * 세 조건을 **모두** 만족해야 true — 하나라도 어긋나면 호출자는 404(존재를 알리지 않는다):
 *   1) REMOTE_ADDR 가 루프백(127.0.0.1 · ::1)
 *   2) Host 헤더가 `127.0.0.1:<port>` · `localhost:<port>` · `[::1]:<port>` (포트 필수)
 *   3) 프록시·터널 흔적 헤더가 **하나도 없음**: Cf-Connecting-Ip · Cf-Ray · Cdn-Loop · X-Forwarded-For · X-Forwarded-Host ·
 *      Forwarded · X-Real-Ip · X-Forwarded-Proto · True-Client-Ip · **Cf-* 접두 전부**(값이 비어 있어도 「있음」이다)
 *      (v1.10.1 보안 L-1 / D-375 — Cf-Ipcountry·Cf-Visitor·Cf-Warp-Tag-Id 등 Cloudflare 가 붙이는 다른 헤더도 터널 흔적이다)
 * Cloudflare 터널은 루프백(cloudflared)에서 들어오지만 공개 Host 와 Cf-* 헤더를 붙인다 — 셋을 함께 봐야 공개 요청이 열리지 않는다.
 * 의존성 없음(bootstrap 전에 라우터가 부른다). 직접 실행되면 404.
 */

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404);
    exit;
}

if (!function_exists('ulogin_local_request_ok')) {
    /** 프록시·터널 흔적 헤더($_SERVER 키). 이 목록을 줄이지 마라. 이 밖에 `HTTP_CF_` 접두는 전부 흔적이다(아래 접두 검사). */
    function ulogin_local_proxy_keys(): array
    {
        return ['HTTP_CF_CONNECTING_IP', 'HTTP_CF_RAY', 'HTTP_CDN_LOOP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_HOST',
                'HTTP_FORWARDED', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_PROTO', 'HTTP_TRUE_CLIENT_IP'];
    }

    /** v1.10.1 L-1 — 이 접두로 시작하는 $_SERVER 키가 하나라도 있으면 프록시·터널 흔적이다. */
    function ulogin_local_proxy_prefixes(): array
    {
        return ['HTTP_CF_'];
    }

    /** Host 헤더가 루프백 이름 + 포트인가. 반환: 정규화된 origin(`http://127.0.0.1:8080`) | null */
    function ulogin_local_host_origin(?string $host): ?string
    {
        if (!is_string($host) || preg_match('/^(127\.0\.0\.1|localhost|\[::1\]):([0-9]{1,5})\z/i', $host, $m) !== 1) {
            return null;
        }
        $port = (int) $m[2];
        if ($port < 1 || $port > 65535) {
            return null;
        }
        return 'http://' . strtolower($m[1]) . ':' . $port;
    }

    /** 세 조건 모두. $server 는 $_SERVER 모양. */
    function ulogin_local_request_ok(array $server): bool
    {
        $ra = (string) ($server['REMOTE_ADDR'] ?? '');
        if ($ra !== '127.0.0.1' && $ra !== '::1') {
            return false;
        }
        if (ulogin_local_host_origin(isset($server['HTTP_HOST']) ? (string) $server['HTTP_HOST'] : null) === null) {
            return false;
        }
        foreach (ulogin_local_proxy_keys() as $k) {
            if (array_key_exists($k, $server)) {
                return false;
            }
        }
        foreach (array_keys($server) as $sk) {
            foreach (ulogin_local_proxy_prefixes() as $pre) {
                if (strncasecmp((string) $sk, $pre, strlen($pre)) === 0) {   // PROXY-PREFIX
                    return false;
                }
            }
        }
        return true;
    }
}
