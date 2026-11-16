<?php
declare(strict_types=1);

/**
 * core/urlrules.php — redirect_whitelist / allowed_origins 항목 규칙 (단일 정의)
 * 담당 계약: §8-12 · §8-13 (서비스 CRUD) · §8-26 ~ §8-28 (설정 번들 검증)
 *
 * ── 왜 이 파일이 생겼나 ───────────────────────────────────────────────
 * 규칙은 `api/admin/services.php` 의 `admin_url_list()` 안에 있었다. v1.6.0 의
 * 설정 번들 검증기가 같은 두 배열을 받는데, **검증만 같아야 하는 것이 아니라
 * 정규화도 같아야 한다.** 정규화가 갈리면(예: 한쪽만 host 를 소문자로) 번들의
 * 값과 DB 의 값이 문자열로 달라지고, 그러면 **같은 번들을 두 번 넣어도 매번
 * "변경" 으로 잡혀 멱등이 조용히 깨진다.** 그래서 규칙과 정규화를 한 곳에 둔다.
 *
 * `admin_url_list()` 는 이 함수를 호출하는 껍데기로 남는다 — 배열 수준의 검사
 * (문자열 배열인가 · 50개 이내인가)와 실패 방식(`v_fail()` 로 던진다)은 그쪽 몫이다.
 * 번들 검증기는 같은 판정을 쓰면서 실패를 **배열로 모은다**.
 *
 * 로드 방법: `core/bootstrap.php` 를 지난 뒤 `require_once` 한다.
 */


// 직접 실행 차단 — 리더 판정 D-34. bootstrap.php 를 거치지 않은 요청은 존재하지 않는 파일이다.
if (!defined('ULOGIN_BOOTSTRAPPED')) {
    // X-Powered-By 제거 — D-154(2). 이 경로는 bootstrap.php 를 거치지 않는다.
    header_remove('X-Powered-By');
    http_response_code(404);
    exit;
}

if (!function_exists('url_rule_check')) {
    /**
     * URL 항목 한 개를 검사하고 정규화한다. **판정만 하고 던지지 않는다.**
     *
     * $mode = 'redirect' : 절대 URL. 쿼리스트링·프래그먼트 금지(비교가 origin+path 라서)
     *         'origin'   : scheme://host[:port] 만. 경로 금지.
     *                      문자열 "null" 은 file:// 에서 온 요청(Origin: null) 허용용으로 통과
     *
     * @param  string      $reason 실패 사유. 성공이면 건드리지 않는다
     * @param  bool        $publicClient v1.10.0 D-369 — **is_confidential=0 서비스의 redirect** 에서만 true 를 넘긴다.
     *                     그때만 RFC 8252 사용자 지정 scheme(예: myapp://auth)을 받는다. 그 밖은 http/https 그대로
     * @return string|null 정규화된 값 / 빈 문자열이면 **건너뛸 항목**(원문이 공백뿐) / null 이면 실패
     */
    function url_rule_check(string $raw, string $mode, ?string &$reason = null, bool $publicClient = false): ?string
    {
        $s = trim($raw);
        if ($s === '') {
            return '';                       // 호출자는 이 항목을 목록에 넣지 않는다
        }
        if (mb_strlen($s, 'UTF-8') > 500) {
            $reason = '각 항목은 500자 이내여야 합니다.';
            return null;
        }
        if ($mode === 'origin' && $s === 'null') {
            return 'null';
        }
        if (strpos($s, '?') !== false) {
            $reason = $mode === 'redirect'
                ? '복귀 주소에 쿼리스트링을 포함할 수 없습니다.'
                : '허용 출처에 쿼리스트링을 포함할 수 없습니다.';
            return null;
        }
        if (strpos($s, '#') !== false) {
            $reason = '프래그먼트(#)를 포함할 수 없습니다.';
            return null;
        }
        if ($mode === 'redirect' && $publicClient) {
            $cs = url_custom_scheme_check($s, $reason);
            if ($cs !== false) {
                return $cs;                  // 사용자 지정 scheme 항목: 정규화값 또는 null(거절)
            }
        }
        $p = parse_url($s);
        if ($p === false || empty($p['scheme']) || empty($p['host'])) {
            $reason = '절대 URL 형식이어야 합니다. (예: https://app.example.com/callback.html)';
            return null;
        }
        $scheme = strtolower((string) $p['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            $reason = 'http 또는 https만 허용됩니다.';
            return null;
        }
        if ($mode === 'origin') {
            $path = isset($p['path']) ? (string) $p['path'] : '';
            if ($path !== '' && $path !== '/') {
                $reason = '허용 출처에는 경로를 포함할 수 없습니다. (scheme://host[:port])';
                return null;
            }
            return $scheme . '://' . strtolower((string) $p['host'])
                . (isset($p['port']) ? ':' . (int) $p['port'] : '');
        }
        return $s;
    }
}

if (!function_exists('url_custom_scheme_check')) {
    /**
     * 공개 클라이언트 복귀 주소의 사용자 지정 scheme 판정 (v1.10.0 D-369).
     * @return string|null|false  false = 사용자 지정 scheme 항목이 아니다(호출자가 http/https 규칙으로 계속)
     *                            null  = 사용자 지정 scheme 인데 규칙 위반($reason)
     *                            string = 정규화값(scheme 소문자, 나머지 원문)
     */
    function url_custom_scheme_check(string $s, ?string &$reason = null)
    {
        if (preg_match('/^([A-Za-z][A-Za-z0-9+.\-]*):(.*)$/s', $s, $m) !== 1) {
            return false;
        }
        $scheme = strtolower($m[1]);
        if ($scheme === 'http' || $scheme === 'https') {
            return false;
        }
        // RFC 8252 private-use scheme 으로 쓰지 못하는 이름(D-369). http/https 는 원래 규칙으로 간다
        // v1.10.0 감사 L-3 / D-372 — OS·브라우저가 다른 앱을 여는 scheme 추가: Windows 셸(search-ms·shell·ms-*),
        // 전화·문자·메일(tel·sms·mailto), Android intent. 점(.) 필수는 걸지 않는다(myapp 등록값 유지).
        $deny = ['javascript', 'data', 'file', 'vbscript', 'blob', 'about', 'ftp', 'ws', 'wss', 'http', 'https',
                 'search-ms', 'shell', 'mailto', 'tel', 'sms', 'intent'];
        if (in_array($scheme, $deny, true) || strncmp($scheme, 'ms-', 3) === 0 || preg_match('/^[a-z][a-z0-9+.\-]{2,63}$/', $scheme) !== 1) {
            $reason = '이 scheme 은 복귀 주소로 쓸 수 없습니다. (공개 클라이언트의 사용자 지정 scheme 은 영문 소문자 3~64자)';
            return null;
        }
        $rest = $m[2];
        if (preg_match('/[\x00-\x20\x7F\\\\]/', $rest) === 1) {
            $reason = '복귀 주소에 공백·제어문자·역슬래시를 넣을 수 없습니다.';
            return null;
        }
        if (strncmp($rest, '//', 2) === 0) {
            $auth = substr($rest, 2);
            $slash = strpos($auth, '/');
            $host = $slash === false ? $auth : substr($auth, 0, $slash);
            $path = $slash === false ? '' : substr($auth, $slash);
            if (strpos($host, '@') !== false) {
                $reason = '복귀 주소에 사용자 정보(user@)를 넣을 수 없습니다.';
                return null;
            }
            if ($host === '') {
                $reason = '사용자 지정 scheme 복귀 주소에는 host 또는 경로가 있어야 합니다. (예: myapp://auth)';
                return null;
            }
            return $scheme . ':' . $rest;
        }
        if (preg_match('/^[0-9]/', $rest) === 1) {
            // 'localhost:5173/cb' 처럼 scheme 을 빠뜨린 host:port 가 사용자 지정 scheme 으로 읽히는 것을 막는다
            $reason = '절대 URL 형식이어야 합니다. (예: https://app.example.com/callback.html 또는 myapp://auth)';
            return null;
        }
        if ($rest === '' || $rest === '/') {
            $reason = '사용자 지정 scheme 복귀 주소에는 host 또는 경로가 있어야 합니다. (예: myapp://auth)';
            return null;
        }
        return $scheme . ':' . $rest;
    }
}
