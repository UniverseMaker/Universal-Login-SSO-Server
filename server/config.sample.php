<?php
/**
 * ulogin 설정 템플릿
 * ---------------------------------------------------------------------
 * 이 파일을 `config.php` 로 복사한 뒤 값을 채운다.
 * install.php 가 자동으로 생성해 주므로 수동 편집은 재설치·이전 시에만 필요하다.
 *
 * !! config.php 는 절대 웹에서 읽히면 안 된다 !!
 *    server/.htaccess 가 차단하지만, 배포 후 브라우저로 직접 열어 403 인지 반드시 확인한다.
 *
 * 이 샘플 파일에는 실제 비밀번호·키를 적지 않는다. 값은 전부 __CHANGE_ME__ 로 둔다.
 *
 * [PHP 요구 버전 — PHP 7.2.0 이상] (v1.1.0 / 리더 판정 D-57 · D-60)
 *   7.2 에서 동작하고 7.4·8.x 에서도 그대로 동작한다.
 *   ※ PHP 7.2 는 2019년 11월에 보안 지원이 종료된 버전이다. 호스팅에 7.4 이상
 *     옵션이 있으면 올리는 것을 권한다 — 이 시스템은 AI API 키를 다룬다.
 *   이 파일과 config.php 는 define() 만 쓰므로 버전 의존 문법이 없다. 손으로
 *   편집할 때도 7.3+ 전용 문법(함수 호출의 trailing comma 등)을 넣지 마라.
 *
 * [상수 집합 동기화 규약 — 중요]
 *   이 파일의 define() 목록은 install.php 가 생성하는 config.php 와 **정확히 같아야 한다**
 *   (현재 54개 — 2026-09-03 실측. 두 파일의 상수 이름 집합이 완전히 일치한다.
 *   재현: 이 파일의 `^define('NAME'` 를 뽑은 목록과 install.php 의 ins_config_spec()
 *   항목 목록을 각각 정렬해 diff 하면 차이가 없고, 양쪽 계수가 54다. 계수는
 *   `grep -c` 로 세지 마라 — 그것은 매치가 아니라 **줄 수**를 센다).
 *   core/ 와 api/ 는 여기 있는 상수를 조건 없이 참조하므로,
 *   하나라도 빠지면 해당 엔드포인트가 `Undefined constant` 로 죽는다.
 *   상수를 추가·삭제할 때는 install.php 의 ins_config_spec() 도 함께 고쳐야 한다.
 */

// ---------------------------------------------------------------------
// DB
// ---------------------------------------------------------------------
define('DB_HOST',    'localhost');
define('DB_NAME',    'ulogin_db');
define('DB_USER',    'ulogin_user');
define('DB_PASS',    '__CHANGE_ME__');
define('DB_CHARSET', 'utf8mb4');
define('DB_PREFIX',  'app_ulogin_');

// ---------------------------------------------------------------------
// 키
//   SECRET_ENC_KEY : service_secrets 값 AES-256-CBC 암복호화 키. 64자 hex(32바이트)
//                    설치 시 bin2hex(random_bytes(32)) 로 생성한다.
//                    !! 이 값을 잃으면 저장된 시크릿을 복호화할 수 없다 !!
//   APP_KEY        : 서명·CSRF 등 범용 앱 키. 64자 hex
// ---------------------------------------------------------------------
define('SECRET_ENC_KEY', '__64_HEX_CHARS__');
define('APP_KEY',        '__64_HEX_CHARS__');

// ---------------------------------------------------------------------
// 전송 보안
//   FORCE_HTTPS=true 이면 평문 HTTP 요청을 301 로 https 로 돌린다.
//   HTTPS 없이 운영하면 access_token 과 AI API 키가 평문으로 전송된다.
//   호스팅이 HTTPS 를 지원하지 않는 경우에만 false 로 내린다. (리더 판정 D-6)
// ---------------------------------------------------------------------
define('FORCE_HTTPS', true);

// TRUST_PROXY — X-Forwarded-* 헤더를 프로토콜 판정에 쓸 것인가. 기본 false.
//   (보안 감사 L-8 · V11-L-6 / 리더 판정 D-51 · D-67)
//
//   **이 상수와 아래 TRUSTED_PROXIES 는 짝이다. 한쪽만 읽고 설정하면 틀린다.**
//
//   리버스 프록시·CDN(Cloudflare 등) 뒤에 있을 때만 true 로 한다.
//   서버가 인터넷에 직접 노출되어 있으면 false 그대로 둔다.
//
//   !! true 로 하려면 TRUSTED_PROXIES 를 반드시 함께 채워야 한다 !!
//   TRUST_PROXY=true 인데 TRUSTED_PROXIES 가 비어 있으면 **설정 오류로 취급해 요청이 500 이
//   된다.** core/bootstrap.php 가 FORCE_HTTPS 리다이렉트 직전에 이 조합을 감지하면
//   리다이렉트하지 않고 500 으로 멈추고 error_log 에 사유를 남긴다 — 홉을 검증할 근거가
//   없는 상태에서 헤더를 신뢰하면 아래의 FORCE_HTTPS 우회가 되살아나고, 반대로 헤더를
//   조용히 무시하면 프록시 뒤에서 무한 리다이렉트가 나 원인이 가려진다. 그래서 어느 쪽으로도
//   조용히 넘어가지 않는다(fail-closed). 500 이 뜨면 TRUSTED_PROXIES 를 채우거나
//   TRUST_PROXY 를 false 로 되돌려라.
//   정확한 발동 지점은 FORCE_HTTPS 리다이렉트 **직전**이다 — 즉 FORCE_HTTPS=true(기본)
//   이고 서버가 그 요청을 https 로 인식하지 못했을 때 500 이 된다. 실제 https 로
//   들어온 요청($_SERVER['HTTPS'] 또는 포트 443)은 이 분기에 오지 않는다.
//   >> 오류 로그 검색어: TRUST_PROXY is on but TRUSTED_PROXIES is empty
//
//   [v1.1.0 추가] 이 두 상수는 **쿠키 발급에도 영향을 준다.** 아래 COOKIE_SECURE 의
//   HTTPS 판정이 같은 request_is_https() 를 재사용하기 때문이다(D-75). TLS 를 종료하는
//   프록시 뒤인데 두 상수를 비워 두면 서버는 그 요청을 평문으로 보고, COOKIE_SECURE=true
//   와 겹쳐 쿠키를 발급하는 요청이 500 이 된다. 아래 COOKIE_SECURE 항목을 함께 읽어라.
//
//   켜면 클라이언트가 `X-Forwarded-Proto` 로 프로토콜을 위조할 수 있으므로, 프록시가
//   그 헤더를 반드시 덮어쓰도록 설정되어 있어야 한다. 그렇지 않으면
//   `X-Forwarded-Proto: https` 한 줄로 FORCE_HTTPS 가 우회되어 평문 요청이 그대로 처리된다.
//   false 이면 $_SERVER['HTTPS'] 와 포트만 보고 판정한다.
define('TRUST_PROXY', false);

// TRUSTED_PROXIES — 신뢰할 프록시의 IP 목록. 예: ['203.0.113.7', '198.51.100.10']
//   **위 TRUST_PROXY 와 짝이다.** 조합별 동작:
//     · TRUST_PROXY=false                  → 이 목록은 쓰이지 않는다. 빈 배열로 둔다
//     · TRUST_PROXY=true  + 목록이 비어 있음 → **설정 오류. 요청이 500 이 된다** (위 설명 참조)
//     · TRUST_PROXY=true  + 목록에 IP 있음  → REMOTE_ADDR 가 그 목록에 있는 요청에서만
//       X-Forwarded-Proto / X-Forwarded-For 를 신뢰한다. core/bootstrap.php 의
//       trust_proxy_headers() 와 core/request.php 의 client_ip() 가 **같은 기준**을 쓴다
//   비어 있는 채로 TRUST_PROXY=false 이면 REMOTE_ADDR 만 신뢰한다(기본값·안전).
//   X-Forwarded-For 를 무조건 믿으면 헤더 위조로 레이트리밋이 통째로 우회된다.
//   프록시·CDN 의 실제 IP 는 호스팅·CDN 사업자에게 확인해 넣는다.
define('TRUSTED_PROXIES', []);

// ---------------------------------------------------------------------
// 쿠키 (로그인 서버 자체 UI 전용. 로컬 앱은 Bearer 토큰을 쓴다)
//
//   [v1.1.0 부터 이 절의 잘못된 값은 조용히 넘어가지 않는다]
//   리더 판정 D-65 · D-66 · D-75 / 보안 감사 V11-M-1 · V11-L-4 · M-3.
//   core/request.php 의 send_cookie() 가 **PHP 버전 분기 앞에서** 값을 검사한다.
//   그래서 PHP 7.2 배포와 7.4·8.x 배포가 같은 지점에서 같은 예외로 실패한다 —
//   7.2 에서만 조용히 다르게 동작하던 경로를 없앴다(두 분기 불변식).
//   잘못된 값은 (a) 500 SERVER_ERROR 로 멈추거나 (b) 안전한 값으로 강제되며,
//   **어느 쪽이든 서버 오류 로그(error_log)에 `[ulogin]` 로 시작하는 사유가 남는다.**
//   500 응답 본문에는 이유가 없다(내부 정보를 노출하지 않는다) — 호스팅의 PHP
//   오류 로그를 열고 아래 항목별 >> 검색어로 찾아라.
// ---------------------------------------------------------------------
define('COOKIE_NAME',      'ULOGIN_SID');
define('COOKIE_CSRF_NAME', 'ULOGIN_CSRF');

// COOKIE_SECURE — 쿠키에 `Secure` 속성을 붙일 것인가. 기본 true.
//   (보안 감사 M-3 / 리더 판정 D-75)
//
//   **FORCE_HTTPS 와 항상 같은 값이어야 한다.** install.php 는 두 값을 하나의 선택으로
//   묶어 함께 기록한다 — 손으로 편집할 때도 반드시 함께 바꾼다. 어긋난 두 방향이
//   각각 다르게 실패한다:
//
//   · FORCE_HTTPS=true + COOKIE_SECURE=false
//     301 리다이렉트 직전의 평문 요청에 세션 쿠키가 실려 그대로 노출된다.
//     이 조합은 코드가 막지 않는다 — 조용히 위험하다. 만들지 마라.
//
//   · FORCE_HTTPS=false + COOKIE_SECURE=true → **쿠키를 발급하는 요청이 500 이 된다**
//     평문 HTTP 요청에서 `Secure` 쿠키를 내보내려 하면 send_cookie() 가 거기서 멈춘다.
//     왜 멈추는가: **브라우저는 평문 연결에서 `Secure` 쿠키를 저장하지 않는다.**
//     서버는 200 과 정상 Set-Cookie 를 내보내고 브라우저가 그것을 버리므로,
//     "로그인 API 는 200 인데 다음 요청에 세션이 없다" 가 된다 — 사용자에게는
//     "로그인을 눌렀는데 다시 로그인 화면"으로 보이고 **서버에는 아무 오류도 남지
//     않는다.** 그 조용한 실패를 남겨 두는 대신 명시적으로 멈춘다(fail-closed.
//     D-53 BASE_URL 미설정 · D-67 TRUST_PROXY 짝 누락과 같은 계열).
//     `Secure` 를 자동으로 떼지 않는 이유: 그러면 세션 쿠키가 평문으로 흐른다 —
//     이 시스템이 막으려는 것 자체다. 설정을 조용히 약화시키지 않는다.
//
//   HTTP 로 운영해야 한다면 FORCE_HTTPS=false 와 COOKIE_SECURE=false 를 **함께**
//   내려라. 한쪽만 내리면 위 두 경우 중 하나가 된다.
//
//   판정은 **쿠키 발급 시점에만** 한다. 그래서 500 이 나는 범위가 좁다 —
//   쿠키를 실제로 내보내는 요청(로그인·로그아웃·CSRF 발급)만 500 이고,
//   `Authorization: Bearer` 로 호출하는 API 는 정상 200 이다(실측 확인).
//   증상이 비대칭으로 보인다: **브라우저에서는 로그인이 안 되는데 Bearer 를 쓰는
//   로컬 AI 앱은 계속 동작한다.** 이 조합을 보면 이 항목을 의심하라.
//
//   HTTPS 여부는 core/bootstrap.php 의 request_is_https() 로 판정한다 — 즉 TLS 를
//   종료하는 프록시 뒤라면 위 TRUST_PROXY·TRUSTED_PROXIES 를 올바로 채워야 이 판정이
//   https 로 인식된다. 프록시 뒤인데 두 상수를 비워 두고 COOKIE_SECURE=true 로 두면
//   쿠키 경로가 500 이 된다.
//
//   !! 판정 조건을 정확히 알아 둘 것 — 이 상수만 보는 것이 아니다 !!
//   코드가 보는 것은 `COOKIE_SECURE` 상수가 아니라 **지금 내보내려는 쿠키의 실효
//   `secure` 값**이다. 정상 경로에서는 둘이 같지만, 아래 두 경우에는 이 상수가
//   `false`(또는 없음)여도 실효값이 `true` 로 강제되어 **똑같이 500 이 난다**:
//     · COOKIE_SAMESITE = 'None'  → Secure 자동 강제 (브라우저 요구사항. 아래 항목)
//     · COOKIE_SECURE 자체가 정의돼 있지 않음(구버전 config.php) → 안전한 쪽인
//       true 로 채운다
//   두 경우 모두 "브라우저가 버리는 쿠키" 라는 점이 같기 때문에 함께 잡는다.
//
//   >> 오류 로그 검색어: COOKIE_SECURE is on but the request is not HTTPS
//      **주의: 이 로그 문구는 `COOKIE_SECURE` 만 언급한다.** 로그를 보고 config.php 를
//      열었는데 `COOKIE_SECURE` 가 이미 `false` 라면 당황하지 말고 위 두 경우를 보라 —
//      특히 `COOKIE_SAMESITE` 가 `'None'` 인지 먼저 확인하라. 문구는 가장 흔한 원인을
//      가리키지만 실제 트리거는 실효 `secure` 값이다.
define('COOKIE_SECURE',    true);

// COOKIE_SAMESITE — `Lax` · `Strict` · `None` **세 값만 유효하다.** 기본 Lax.
//   (보안 감사 V11-L-4 / 리더 판정 D-66)
//   대소문자까지 정확히 일치해야 한다 — `lax` `LAX` 는 유효하지 않다.
//
//   · 목록 밖의 값(오타 포함)은 **`Lax` 로 강제**되고 서버 오류 로그에 경고가 남는다.
//     요청은 500 이 되지 않고 정상 처리된다 — 다만 당신이 적은 값은 쓰이지 않는다.
//     `'Lax; Domain=evil.example'` 처럼 구분자를 넣어 속성을 덧붙이려 해도 같은
//     화이트리스트에서 `Lax` 로 잘려 나간다(쿠키 속성 주입 차단).
//     >> 오류 로그 검색어: invalid COOKIE_SAMESITE
//
//   · `'None'` 으로 하면 **`Secure` 가 자동으로 강제된다.** 브라우저 요구사항이다 —
//     SameSite=None 인데 Secure 가 없는 쿠키는 Chrome·Firefox·Safari 가 통째로
//     버리므로, 위 COOKIE_SECURE 항목과 같은 "200 인데 세션 없음" 이 된다.
//     >> 오류 로그 검색어: COOKIE_SAMESITE=None requires Secure
//
//     !! 그 결과로 이어지는 것: 평문 HTTP 배포에서 `'None'` 을 쓰면 COOKIE_SECURE=false
//     라도 `Secure` 가 강제되고, 곧바로 위 D-75 검사에 걸려 **쿠키 발급이 500 이 된다.**
//     **`'None'` 은 HTTPS 전용 값이다 — 평문 HTTP 배포에서는 쓸 수 없다.**
//     이때 로그에 남는 것은 SameSite 관련 문구가 아니라 위 COOKIE_SECURE 항목의
//     `COOKIE_SECURE is on but the request is not HTTPS` 다(트리거가 실효 `secure`
//     값이기 때문). `COOKIE_SECURE=false` 인데 그 로그가 보이면 이 값을 확인하라.
//     `'None'` 이 필요한 경우는 로그인 화면을 다른 사이트의 iframe 에 띄우는 구성뿐이다
//     — 그럴 일이 없으면 `Lax` 를 그대로 둔다.
define('COOKIE_SAMESITE',  'Lax');

// COOKIE_DOMAIN / COOKIE_PATH — 쿠키 구분자를 넣으면 **요청이 500 이 된다.**
//   (보안 감사 V11-M-1 / 리더 판정 D-65)
//   금지 문자: 콤마 `,` · 세미콜론 `;` · 공백 · 탭 · CR · LF · 수직탭(\013) · 폼피드(\014)
//
//   PHP 본체는 이 옵션들에 위 문자가 있으면 쿠키를 아예 보내지 않는다
//   (8.0+ ValueError / 7.3~7.4 경고 + false). **PHP 7.2 에는 그 층이 없어서**
//   `COOKIE_PATH = '/; HttpOnly'` 같은 값이 7.2 배포에서만 조용히 통과하고 `HttpOnly`
//   가 실제 속성으로 해석됐다(쿠키 속성 주입. 실측으로 확인된 결함이다).
//   그래서 core/request.php 가 **버전 분기 앞에서** 같은 검사를 한다 — 7.2 와 7.4·8.x
//   가 같은 지점에서 같은 예외(SERVER_ERROR 500)로 실패하고, 그 요청에는 Set-Cookie
//   가 한 줄도 나가지 않는다. 빈 문자열은 검사 대상이 아니다(속성을 생략한다).
//
//   정상 값: COOKIE_PATH 는 `/`. 설치 경로가 하위 디렉터리여도 `/` 로 둔다.
//   COOKIE_DOMAIN 은 **빈 문자열**이 권장값이다 — 쿠키를 현재 호스트에만 준다.
//   서브도메인 공유가 필요할 때만 `.example.com` 처럼 넣고, 점·하이픈·영숫자만
//   쓴다. `https://` · 포트 · 경로 · 여러 도메인 나열은 넣지 마라(위 금지 문자에
//   걸려 500 이 된다).
//
//   >> 오류 로그 검색어: cookie option rejected
//      (어느 키가 문제인지 함께 남는다 — invalid "path" / invalid "domain")
define('COOKIE_DOMAIN',    '');
define('COOKIE_PATH',      '/');

// ---------------------------------------------------------------------
// 메일
//   MAIL_DRIVER = 'log'  → 발송하지 않고 server/storage/mail/ 에 파일로 저장 (v1.0.0 기본값)
//                 'mail' → PHP mail() 사용
//   DB settings 테이블의 mail_driver 값이 있으면 그쪽이 우선한다.
// ---------------------------------------------------------------------
define('MAIL_DRIVER',    'log');
define('MAIL_FROM',      'no-reply@example.com');
define('MAIL_FROM_NAME', 'ulogin');

// ---------------------------------------------------------------------
// BASE_URL — 비밀번호 재설정·이메일 인증 링크를 만들 때 쓰는 공개 주소 (끝에 / 없이)
//
//   !! 절대 비워두지 마라 !! (보안 감사 H-4 / 리더 판정 D-33 · D-44)
//   **BASE_URL 이 비어 있으면 폴백하지 않고 메일 발송 자체를 중단한다**(fail-closed).
//   반드시 설치 루트 절대 URL 을 넣어라.
//
//   왜 폴백을 없앴는가: 예전 구현은 요청의 `Host` 헤더(own_origin())로 폴백했다.
//   Host 는 **요청을 보낸 쪽이 정하는 값**이다. 공격자가 `Host: evil.example.net`
//   을 붙여 비밀번호 찾기를 호출하면 메일 본문 링크가
//   `https://evil.example.net/public/reset.html?token=...` 로 만들어지고,
//   피해자가 그 링크를 누르는 순간 재설정 토큰이 공격자 서버의 액세스 로그로
//   넘어간다 → 임의 계정 탈취. 그래서 폴백을 제거했다.
//
//   부작용을 알아 둘 것: 이 값이 비면 비밀번호 재설정·이메일 인증 메일이
//   **조용히 발송되지 않는다**(에러 응답은 계정 열거 방지 규약 때문에 그대로
//   '보냈습니다'로 나간다). 재설정이 안 될 때 가장 먼저 이 값을 확인하라.
//
//   실제 형태 예시: 'https://example.com/ulogin'
//   (이 주소 아래에 /server/ 와 /public/ 가 있어야 한다.
//    검증: {BASE_URL}/public/login.html 이 열리면 맞는 값이다)
//   install.php 는 이 값을 **필수 입력**으로 받는다.
// ---------------------------------------------------------------------
define('BASE_URL', 'https://__CHANGE_ME__');

// ---------------------------------------------------------------------
// 정책 기본값
//   DB settings 테이블 값이 있으면 그쪽이 우선한다. 여기 값은 settings 가
//   비어 있을 때의 폴백이다.
// ---------------------------------------------------------------------
define('SESSION_TTL',          3600);      // access_token 수명(초)
define('REFRESH_TTL',          1209600);   // refresh_token 수명(초) = 14일
define('REMEMBER_TTL',         2592000);   // remember=true 쿠키 수명(초) = 30일
define('AUTH_CODE_TTL',        60);        // SSO code 수명(초)
define('PW_RESET_TTL',         1800);      // 비밀번호 재설정 토큰 수명(초) = 30분
define('EMAIL_VERIFY_TTL',     86400);     // 이메일 인증 토큰 수명(초) = 24시간
define('LOCKOUT_THRESHOLD',    5);         // 연속 실패 몇 회에 잠글 것인가
define('LOCKOUT_MINUTES',      15);        // 잠금 지속 시간(분)
define('PASSWORD_MIN_LENGTH',  8);
define('SIGNUP_BONUS_POINTS',  100);
define('ALLOW_REGISTER',       true);
define('REQUIRE_EMAIL_VERIFY', false);
define('MAINTENANCE_MODE',     false);
define('SITE_NAME',            'ulogin');
// 시크릿(payload) 전달 감사 기록. true 면 「누가 · 언제 · 어떤 키를」 받아 갔는지를
// app_ulogin_secret_access_logs 에 남긴다. (세션 × 경로 × 1시간) 버킷 UPSERT 라
// 요청 수만큼 늘지 않는다. 관리자 콘솔의 「시크릿 전달 로그」가 이 표를 읽는다.
// **DB settings 의 secret_access_log 값이 있으면 그쪽이 우선한다** — 관리자 API 로
// 끌 수 있다(§8-22 의 13번째 키). 여기 값은 그 행이 없을 때의 폴백이다.
// 기존 설치본의 config.php 에 이 줄이 없어도 core/bootstrap.php 가 같은 값으로 채운다.
define('SECRET_ACCESS_LOG',    true);
// DEBUG=true 이면 예외 메시지(SQL 오류문·컬럼명)가 응답 본문에 실린다.
// 운영에서는 반드시 false. install.php 는 항상 false 로 기록한다. (보안 감사 L-2)
define('DEBUG',                false);

// ---------------------------------------------------------------------
// 테이블 상수
//   코드에서 테이블명 리터럴을 쓰지 않는다. prefix 가 바뀌면 전수 수정이 필요해진다.
// ---------------------------------------------------------------------
define('T_USERS',         DB_PREFIX . 'users');
define('T_SERVICES',      DB_PREFIX . 'services');
define('T_USER_SERVICES', DB_PREFIX . 'user_services');
define('T_SECRETS',       DB_PREFIX . 'service_secrets');
define('T_SESSIONS',      DB_PREFIX . 'sessions');
define('T_AUTH_CODES',    DB_PREFIX . 'auth_codes');
define('T_PW_RESETS',     DB_PREFIX . 'password_resets');
define('T_EMAIL_VERIFY',  DB_PREFIX . 'email_verifications');
define('T_LOGIN_LOGS',    DB_PREFIX . 'login_logs');
define('T_POINT_LOGS',    DB_PREFIX . 'point_logs');
define('T_ADMIN_LOGS',    DB_PREFIX . 'admin_logs');
define('T_RATE_LIMITS',   DB_PREFIX . 'rate_limits');
define('T_SETTINGS',      DB_PREFIX . 'settings');
define('T_TERMS',         DB_PREFIX . 'terms');
define('T_CONSENTS',      DB_PREFIX . 'user_consents');
// v1.4.0 추가.
//   **기존 설치본의 config.php 에는 아래 두 줄이 없다.** 그래서 core/bootstrap.php 가
//   같은 값을 defined() 가드로 채워 넣는다 — 없으면 업그레이드한 순간
//   Undefined constant 로 전 엔드포인트가 죽는다.
//   새 테이블 상수를 추가할 때는 여기 · install.php 의 ins_config_spec() ·
//   core/bootstrap.php 의 가드 블록 **셋을 함께** 고쳐라.
define('T_MIGRATIONS',    DB_PREFIX . 'schema_migrations');
define('T_SECRET_ACCESS', DB_PREFIX . 'secret_access_logs');
