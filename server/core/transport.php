<?php
declare(strict_types=1);

/**
 * core/transport.php — 요청의 전송 보안 판정 (프록시 헤더 신뢰 · https 여부)
 * 담당: 리더 판정 D-51 · D-67 / 보안 재검증 V14b-M-3
 *
 * =====================================================================
 * **이 파일만은 server/migrate.php 도 로드한다.**
 *
 * 이 세 함수는 v1.4.0 후속까지 core/bootstrap.php 안에 있었다. 그래서
 * bootstrap 을 거치지 않는 migrate.php 는 그 판정을 쓸 수 없었고,
 * `$_SERVER['HTTPS']` 와 포트 443 만 보는 **축약 사본**을 갖고 있었다.
 * 결과(보안 재검증 V14b-M-3, 실측): `TRUST_PROXY=true` + `TRUSTED_PROXIES` 를
 * 올바로 채운 **정상 프록시 배포**에서 `X-Forwarded-Proto: https` 를 받은
 * `/api/*` 는 200 인데 `migrate.php` 만 **GET·POST 모두 403** 이었다.
 * 공용 호스팅 + CDN 은 CLI 가 없을 가능성이 가장 높은 배포이고, 그 배포의
 * 운영자에게 마이그레이션 화면이 존재하지 않게 된다.
 *
 * 조건식을 두 곳에 두는 것이 D-51·D-67 이 겨눈 사고이므로, 사본을 고치는 대신
 * **정의를 한 파일로 뺐다.** 이 파일이 참조하는 것은 전수로 다음뿐이다
 * (기계 확인: db_* / setting_get / require / include / T_* / json_out / json_err /
 *  log_* 전부 0건. 양성 대조로 같은 검사가 core/auth.php 의 db_* 와
 *  core/payload.php 의 T_SECRETS 를 찾아냄을 먼저 확인했다):
 *
 *   $_SERVER['REMOTE_ADDR'] · ['HTTPS'] · ['SERVER_PORT'] · ['HTTP_X_FORWARDED_PROTO']
 *   상수 TRUST_PROXY · TRUSTED_PROXIES   (둘 다 defined() 가드로 방어한다)
 *
 * **DB 도 스키마도 다른 core/ 파일도 건드리지 않는다.** 그래서 「러너는 고치려는
 * 스키마에 의존하면 안 된다」는 migrate.php 의 전제와 충돌하지 않는다 — 그 전제가
 * 금지하는 것은 core/db.php 계열이고 전송 판정이 아니다. 이 파일에
 * **DB·설정·응답 헬퍼를 절대 들이지 마라.** 들이는 순간 그 전제가 깨진다.
 * =====================================================================
 *
 * **PHP 7.2 호환.** 금지 문법 목록은 core/bootstrap.php 상단에 있다.
 *
 * 함수 정의는 전부 function_exists 가드 안에 있다 — bootstrap.php 와 migrate.php 가
 * 같은 요청에서 둘 다 이 파일을 require 할 수 있고(CLI 러너가 아닌 경우는 없지만
 * 보장할 수 없다), 중복 정의는 치명적 오류다.
 */

// 직접 실행 차단 — 리더 판정 D-34. 단 **두 문맥**을 허용한다.
//   ULOGIN_BOOTSTRAPPED — core/bootstrap.php 경로
//   ULOGIN_MIGRATE_CTX  — server/migrate.php 경로 (bootstrap 을 거치지 않는다)
// 다른 core/ 파일과 달리 조건이 둘인 이유가 이 파일의 존재 이유 자체다.
if (!defined('ULOGIN_BOOTSTRAPPED') && !defined('ULOGIN_MIGRATE_CTX')) {
    // X-Powered-By 제거 — D-154(2). 이 경로는 bootstrap.php 를 거치지 않는다.
    header_remove('X-Powered-By');
    http_response_code(404);
    exit;
}
// ---------------------------------------------------------------------
// 프록시 헤더를 신뢰할 수 있는 요청인가 — 리더 판정 D-51 / 감사 L-8
//
// `X-Forwarded-*` 는 **클라이언트가 마음대로 붙일 수 있는 문자열**이다.
// 종전 코드는 `X-Forwarded-Proto: https` 한 줄을 무검증으로 믿어서,
// `curl -H 'X-Forwarded-Proto: https' http://…` 로 FORCE_HTTPS 를 통째로 우회할 수 있었다.
//
// 신뢰 조건 (**전부** 만족해야 한다) — 리더 판정 D-67 / 감사 V11-L-6:
//   1) `TRUST_PROXY` 가 정의돼 있고 true — 리버스 프록시·CDN 뒤에 있을 때만 켠다.
//      상수가 없는(구버전) config.php 로도 죽지 않아야 하므로 defined() 로 방어한다.
//      **미정의는 false 와 같다** = 헤더를 무시한다(fail-closed).
//   2) `TRUSTED_PROXIES` 가 **비어 있지 않고** REMOTE_ADDR 이 그 목록에 있어야 한다.
//      비어 있으면 홉을 검증할 근거가 아예 없으므로 신뢰하지 않는다.
//
// **여기 있던 주석은 거짓이었다** (D-67 이 정정을 지시한 항목). 종전 코드는
// `TRUSTED_PROXIES === []` 일 때 `return true` 였고, 주석은 "core/request.php 의
// client_ip() 와 같은 기준" 이라고 단언했다. 사실이 아니었다 —
// `core/request.php` 의 `client_ip()` 는 `$proxies !== [] && in_array($remote, $proxies)`
// 를 요구하므로 같은 설정에서 X-Forwarded-For 를 **무시**한다. 그래서 `TRUST_PROXY=true` +
// `TRUSTED_PROXIES=[]` 에서는 `curl -H 'X-Forwarded-Proto: https' http://…` 한 줄로
// FORCE_HTTPS 가 우회됐다(감사 L-8 재발). 이제 두 함수의 조건식이 실제로 동일하다.
// **한쪽만 고치지 마라.** `core/request.php` 의 `client_ip()` 와 이 함수를 항상 함께 본다.
//
// 상수 자체(`TRUST_PROXY`)는 config.sample.php / install.php 담당이 추가한다.
// ---------------------------------------------------------------------
if (!function_exists('trust_proxy_headers')) {
    function trust_proxy_headers(): bool
    {
        if (!defined('TRUST_PROXY') || !TRUST_PROXY) {
            return false;
        }
        $proxies = defined('TRUSTED_PROXIES') && is_array(TRUSTED_PROXIES) ? TRUSTED_PROXIES : [];
        if ($proxies === []) {
            // 설정 오류다. 조용히 무시하면 프록시 뒤 배포에서 FORCE_HTTPS 무한
            // 리다이렉트가 나고 원인이 숨는다 — 그래서 FORCE_HTTPS 분기가
            // trust_proxy_misconfigured() 로 이 상태를 감지해 500 으로 멈춘다 (D-67).
            return false;
        }
        return in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), $proxies, true);
    }
}

/**
 * 프록시 설정이 모순 상태인가 — 리더 판정 D-67.
 *
 * `TRUST_PROXY=true` 는 "나는 프록시 뒤에 있다" 는 선언이고,
 * `TRUSTED_PROXIES=[]` 는 "어떤 홉도 신뢰하지 않는다" 는 선언이다. 둘이 함께 있으면
 * 두 선언이 서로를 부정한다. 이 상태에서 프로토콜 헤더를 **무시**하면
 * (프록시가 https 를 받아 http 로 전달하는 정상 구성에서) FORCE_HTTPS 301 이
 * 영원히 되돌아오는 **무한 리다이렉트**가 되고, 로그에도 원인이 남지 않는다.
 * 반대로 **신뢰**하면 누구의 `X-Forwarded-Proto` 든 믿어 FORCE_HTTPS 가 우회된다.
 *
 * 어느 쪽으로도 조용히 넘어가지 않는다 — D-53 의 `BASE_URL` 미설정 처리와 같이
 * **fail-closed 로 멈추고 사유를 서버 로그에 남긴다.**
 */
if (!function_exists('trust_proxy_misconfigured')) {
    function trust_proxy_misconfigured(): bool
    {
        if (!defined('TRUST_PROXY') || !TRUST_PROXY) {
            return false;
        }
        $proxies = defined('TRUSTED_PROXIES') && is_array(TRUSTED_PROXIES) ? TRUSTED_PROXIES : [];
        return $proxies === [];
    }
}

/**
 * **역방향** 오설정 — 프록시 뒤인데 신뢰 설정이 꺼져 있는가. 감사 M17-2.
 *
 * `trust_proxy_misconfigured()` 가 잡는 것은 `TRUST_PROXY=true` + `TRUSTED_PROXIES=[]`
 * 한 조합뿐이다. **반대 방향에는 가드가 하나도 없었다:**
 * `TRUST_PROXY=false` 인데 모든 요청의 peer 가 루프백이면, 그 배포는 거의 확실히
 * 같은 PC 의 역프록시·터널 뒤이고 `client_ip()` 가 **전원 `127.0.0.1`** 을 돌려준다.
 *
 *   · `api:ip:127.0.0.1` 300/60 · `login:ip:…` · `sso_silent:ip:…` 가 **전 인터넷
 *     공용 버킷 1개**가 된다. 한 명이 분당 300회를 쏘면 전 세계 사용자가 429 다 —
 *     가장 싼 서비스 거부.
 *   · `login_logs.ip` · `sessions.ip` · `auth_codes.created_ip` 가 전부 같은 값이 되어
 *     **감사 기록이 통째로 무의미**해진다. 마이페이지 로그인 이력도 전부 같은 IP 다.
 *   · **운영자에게는 아무 신호도 가지 않는다.** 화면도 로그도 정상으로 보인다.
 *
 * **멈추지는 않는다.** 정상 로컬 개발이 정확히 이 모양이라 500 을 내면 개발이 막힌다.
 * D-53·D-67·D-75 의 fail-closed 3종과 같은 계열이되 **시끄럽게만** 만든다.
 * 「앞단이 붙인 흔적(X-Forwarded-For · CF-Connecting-IP)이 있는데 우리가 안 보고 있다」
 * 를 조건으로 두어 **그냥 로컬 개발에서는 발화하지 않게** 한다.
 */
if (!function_exists('proxy_trust_probably_missing')) {
    function proxy_trust_probably_missing(): bool
    {
        // **`TRUST_PROXY` 상수가 아니라 `trust_proxy_headers()` 를 본다** (감사 M17-8 / D-67).
        // 1차는 상수를 직접 봤다. 그래서 `TRUST_PROXY=true` + `TRUSTED_PROXIES` 에 **실제 peer 가
        // 없는**(예: 엣지 주소 `10.0.0.5` 만 적고 터널 peer `127.0.0.1` 은 빠진) 상태를 놓쳤다.
        // 그 상태의 증상 — 전원 127.0.0.1 집계 · 공용 버킷 1개 — 이 **이 함수가 잡으려는
        // 증상 그 자체**인데, 500 가드(trust_proxy_misconfigured)도 이 가드도 침묵했다.
        // 「헤더를 실제로 믿는가」의 정의는 trust_proxy_headers() 하나다. 사본을 만들지 마라 —
        // client_ip() 가 같은 이유로 조건식 사본을 버렸다(D-272).
        if (trust_proxy_headers()) {
            return false;
        }
        $remote = (string) (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');
        if ($remote !== '127.0.0.1' && $remote !== '::1') {
            return false;
        }
        return isset($_SERVER['HTTP_X_FORWARDED_FOR']) || isset($_SERVER['HTTP_CF_CONNECTING_IP']);
    }
}

/**
 * 이 요청이 https 로 들어왔는가.
 * 프록시 헤더는 trust_proxy_headers() 가 true 일 때만 본다 (D-51).
 */
if (!function_exists('request_is_https')) {
    function request_is_https(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        if (!trust_proxy_headers()) {
            return false;   // X-Forwarded-* 는 보지 않는다
        }
        // 프록시가 홉을 콤마로 이어 붙이는 경우가 있다("https, http").
        // 가장 앞(클라이언트에 가장 가까운 홉)이 실제 클라이언트 프로토콜이다.
        $xfp = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if ($xfp === '') {
            return false;
        }
        if (strpos($xfp, ',') !== false) {
            $hops = explode(',', $xfp);
            $xfp  = trim((string) $hops[0]);
        }
        return $xfp === 'https';
    }
}
