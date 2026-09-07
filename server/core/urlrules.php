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
     * @return string|null 정규화된 값 / 빈 문자열이면 **건너뛸 항목**(원문이 공백뿐) / null 이면 실패
     */
    function url_rule_check(string $raw, string $mode, ?string &$reason = null): ?string
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
        $p = parse_url($s);
        if ($p === false || empty($p['scheme']) || empty($p['host'])) {
            $reason = '절대 URL 형식이어야 합니다. (예: https://app.example.com/callback.html)';
            return null;
        }
        $scheme = strtolower((string) $p['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            $reason = 'http 또는 https 만 허용됩니다.';
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
