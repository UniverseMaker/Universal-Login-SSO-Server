# ulogin — 범용 로그인 서비스 (PHP + MySQL SSO)

**버전** `v1.2.0_20260828` · **환경** 공용 호스팅 / PHP 7.2+ / MySQL·MariaDB / PDO / Composer 없음 / 외부 의존 0개

> **PHP 7.2에서 동작하지만, 7.2는 보안 지원이 끝난 버전이다.** 바로 아래 [PHP 요구 버전](#php-요구-버전--72-이상)을 먼저 읽어라.

---

## 무엇을 해결하는가

로컬 HTML 앱이나 AI 앱을 만들면 반드시 부딪히는 문제가 있다. **AI API 키를 어디에 둘 것인가.**
JS에 넣으면 브라우저 개발자도구를 여는 누구나 그 키를 가져간다. 그렇다고 서버를 통째로 만들자니
로그인·회원관리·과금까지 전부 새로 짜야 한다.

**ulogin은 로그인만 서버로 분리한다.** 앱의 JS에는 키가 한 글자도 없다.
사용자가 로그인하면 서버가 **그 사용자의 등급에 맞는 키만** 골라 내려준다.
등급이 부족하면 그 키는 응답에서 조용히 빠진다. 앱은 받은 키로 AI를 직접 호출하고,
호출할 때마다 서버에서 포인트를 차감한다. 앱 코드는 여전히 정적 HTML 하나다.

```
기존:  [로컬 앱 JS]  ─ sk-xxxx 하드코딩 ─→  [AI API]      ← 키가 공개된다
ulogin: [로컬 앱 JS]  ─ 로그인 ─→ [ulogin 서버] ─ 등급 판정 → 키 반환 ─→ [AI API]
```

---

## PHP 요구 버전 — 7.2 이상

**최소 요구 버전은 PHP 7.2.0이다.** 설치기 1단계가 표시하는 문구도 "PHP 버전 7.2 이상"이다.
7.2부터 8.x까지 같은 코드가 그대로 동작한다(하위 호환이 아니라 **양방향 호환**이다).

> ### PHP 7.2를 쓰기 전에 반드시 알아야 할 것
>
> **PHP 7.2는 2019년 11월 30일에 보안 지원이 종료됐다. 알려진 취약점에 패치가 나오지 않는다.**
> 이 시스템은 AI API 키를 다루므로, 호스팅에 PHP 7.4 이상 옵션이 있으면 올리는 것을 권한다.
> **7.2에서도 정상 동작하도록 만들어져 있다.**
>
> 조금 더 자세히:
>
> - **PHP 7.2 — 2019-11-30 보안 지원 종료.** PHP 7.3도 **2021-12-06**에 종료됐다.
>   두 버전 모두 이후 발견된 인터프리터·확장 모듈 취약점에 패치가 제공되지 않는다.
> - 이 서버는 **AI API 키를 DB에 AES-256-CBC로 암호화 저장하고, 인증된 사용자에게 반환한다.**
>   런타임 취약점의 영향 범위에 **그 키가 들어간다.** 일반 웹앱보다 실패의 대가가 크다.
> - 그래도 **7.2에서 정상 동작하게 만드는 것이 v1.1.0의 목표였다.** 버전을 올리라는 *요구*가 아니라
>   *권고*다. 호스팅 제어판에 "PHP 버전 선택" 메뉴가 있고 7.4 이상을 고를 수 있다면 그쪽을 고르면 된다.
> - **설치기는 7.2·7.3을 차단하지 않는다.** 1단계 "정보성 항목" 표의 1번 행에 주황색 "주의"로
>   같은 사실을 표시하고 **설치는 그대로 진행된다.** 필수 항목에서 실패로 뜨는 것은 **7.1 이하**뿐이다.

### 버전별로 어떻게 갈리는가

| 실행 중인 PHP | 설치기 1단계 필수 항목 | 정보성 항목 1번 행 | 설치 |
|---|---|---|---|
| 7.1 이하 | **실패** | 주의 | **차단됨** — 호스팅 제어판에서 PHP를 올려야 한다 |
| 7.2.x | 통과 | **주의 (보안 지원 종료)** | 진행된다 |
| 7.3.x | 통과 | **주의 (보안 지원 종료)** | 진행된다 |
| 7.4 이상 | 통과 | 확인 | 진행된다 |

### 쿠키 보호 속성은 7.2에서도 정상 적용된다

`SameSite`·`HttpOnly`·`Secure`는 **PHP 7.2에서도 빠지지 않는다.**

`setcookie()`의 옵션 배열 형식은 PHP 7.3부터만 동작한다. 7.2에서 옵션 배열을 넘기면 그것이
만료 시각 인자로 캐스팅되어 **`SameSite`가 통째로 사라지고 `HttpOnly`·`Secure`·`path`도 기본값으로
떨어진다.** 오류 하나 없이 조용히 그렇게 된다. v1.1.0은 이 지점을 래퍼 하나로 막았다.

```
send_cookie()  ─ PHP_VERSION_ID >= 70300 ─→ setcookie($name, $value, $optionsArray)
               ─ PHP_VERSION_ID <  70300 ─→ header('Set-Cookie: …', false) 로 직접 조립
```

두 분기가 **같은 `Set-Cookie` 헤더 문자열**을 만드는지 16개 케이스로 바이트 단위 대조했고 전부 일치했다
(`_workspace/12a_fix72_core_report.md`). 이후 보안 감사가 이 대조를 독립적으로 재현하고 **총 50케이스**로
확장해 **운영 경로 25/25 바이트 동일**을 확인했다(`_workspace/13_security_v1.1.md` §A-3).
갈라진 나머지는 전부 `cookie_options()`와 `install.php`가 만들어낼 수 없는 입력이었고, 그 격차도
**두 분기 공통 지점에서 검증하도록 고쳐 동등성을 불변식으로 만들었다**(D-65 / D-66 — `## 보안 설계 요약` 참조).
세션 쿠키·CSRF 쿠키 발급과 삭제가 모두 이 한 함수를 지난다.

**그래도 배포 후 1회는 눈으로 확인하라.** 조용히 깨질 수 있었던 유일한 지점이고 확인 비용이 30초다.
절차는 `INSTALL.md` 3절 5번 항목에 있다.

### 7.2에서 무엇이 안전하고 무엇이 그렇지 않은가

위의 EOL 블록은 **왜 올려야 하는가**를 말한다. 이 절은 **올리지 않고 쓰는 동안 정확히 무엇이 위험한가**를
층으로 나눠 적는다. 두 개는 다른 질문이다. 근거는 보안 감사 v1.1.0 §C의 실측이다
(`_workspace/13_security_v1.1.md`).

**결론부터 — 위험은 애플리케이션 층이 아니라 인터프리터 층에 있다.**

#### 애플리케이션 층 — 7.2에서도 동작이 달라지지 않는다 (함수별 실측)

| 이 코드가 의존하는 것 | 7.2 대비 |
|---|---|
| `password_hash` / `password_verify` / `password_needs_rehash` | `PASSWORD_DEFAULT`는 7.2도 8.1도 **bcrypt cost 10**이다(실측: `password_get_info` → `{"algo":"2y","cost":10}`, `password_needs_rehash` → `false`). 7.2에서 만든 `$2y$10$…` 해시가 7.4·8.x에서 그대로 검증되고 재해시도 발생하지 않는다 — **7.2 ↔ 7.4 양방향 이관에서 비밀번호가 그대로 동작한다** |
| `PASSWORD_DEFAULT` 상수의 **타입** (7.2는 `int 1`, 7.4+는 `string "2y"`) | 코드는 이 값을 `password_hash`·`password_needs_rehash`에 **넘기기만** 하고 저장·비교하지 않는다(전수 확인) → 무영향 |
| `random_bytes` / `random_int` | 7.0+. CSPRNG 소스 동일 |
| `openssl_encrypt` / `openssl_decrypt` + `aes-256-cbc` + `OPENSSL_RAW_DATA` | 5.4+부터 동일 시그니처·동일 결과. **시크릿 암호화가 7.2에서 약해지지 않는다** |
| `PDO` / `pdo_mysql` + `ATTR_EMULATE_PREPARES=false` | 7.2 존재. 서버측 prepare 동작 동일 |
| `hash_equals` / `hash('sha256')` | 동일 |
| `json_encode` / `json_decode` | 5.2+. `JSON_THROW_ON_ERROR`(7.3)는 **쓰지 않는다** |
| `preg_*` | **7.3에서 정규식 엔진이 PCRE1(8.x) → PCRE2로 교체됐다.** 이 라운드에서 실제로 확인한 것은 **PCRE2 전용 문법에 대한 의존이 0건**이라는 것이다 — `server/` 전수에서 `/u` 플래그 · 룩비하인드 `(?<…)` · `\p{…}` · 소유 수량자 **0건**(재확인 실측). 쓰이는 것은 ASCII 문자클래스 + `{n,m}` 상한 있는 수량자뿐이다(`/^[a-z0-9_]{4,20}$/`, `/^[\x20-\x7E]*$/`, UUID·날짜 패턴, `/\r\n\|\r\|\n/` 같은 개행 분리 등). **두 엔진이 동일하게 해석하는 부분집합만 쓴다** → 엔진 교체의 영향을 받지 않는다 |
| `mb_strlen` / `mb_substr` / `mb_strtolower` / `mb_internal_encoding` | 전부 4.x·5.x. `mb_str_split`(7.4)은 쓰지 않는다 |
| `filter_var` | `FILTER_VALIDATE_IP` · `FILTER_VALIDATE_EMAIL`만 쓴다(5.2+). `FILTER_VALIDATE_URL`은 **쓰지 않는다** — 7.x에 남아 있는 URL 검증 우회 계열과 무관하다 |
| 쿠키 보호 속성 (`HttpOnly` · `Secure` · `SameSite`) | 위 절의 `send_cookie()` 래퍼로 **7.2에서도 같은 헤더가 나간다** |

**따라서 "7.2라서 이 시스템의 인증·암호화가 약해진다"는 명제는 이 코드에서 성립하지 않는다.**
7.4로 올려서 얻는 것은 애플리케이션 동작의 개선이 아니라 **인터프리터 패치**다.

#### 인터프리터 층 — 여기가 실제 위험이고, 애플리케이션 코드로는 막을 수 없다

7.2의 마지막 릴리스는 **7.2.34 (2020-10)**이다. 그 이후 발견된 인터프리터·확장 모듈 결함에는
**어떤 패치도 나오지 않는다.** 위험의 본질은 특정 CVE 번호가 아니라 이 사실이다.

이 시스템의 구성에서 **가장 현실적인 것은 하나**다.

> **공용 호스팅의 php-fpm 권한 상승 계열** (CVE-2021-21703 계열 — 7.3.33 / 7.4.26에서 수정됐고 **7.2에는 패치가 없다**).
>
> 성립 조건: 호스팅이 PHP를 **php-fpm으로 실행**하고, **같은 서버에 다른 고객 계정이 함께 올라가 있는** 것.
> 공용 호스팅에서는 그 구성이 흔하다.
>
> 성공하면 공격자가 `server/config.php`를 읽고 그 안의 **`SECRET_ENC_KEY`와 DB 자격증명**을 얻는다.
> 그 두 개가 함께 있으면 **DB에 저장된 AI 키가 전량 복호화된다.** 이 시스템이 다루는 자산이
> 곧 그 키이므로, 일반 웹앱보다 실패의 대가가 크다.
>
> **이것은 환경 위험이다.** 애플리케이션에서 방어할 수단이 없다 — `SECRET_ENC_KEY`는 복호화에
> 반드시 필요하므로 서버 프로세스가 읽을 수 있는 곳에 있어야 한다.

반대로, 7.2.34 이후 공개된 권고 중 **이 시스템에서는 성립하지 않는 것들**도 구분해 둔다.
"7.2는 전부 위험하다"는 식의 뭉갠 서술은 판단에 도움이 되지 않는다.

| 사안 | 이 시스템에서의 판정 |
|---|---|
| `pdo_mysql` / mysqlnd 버퍼 오버플로우 (CVE-2022-31626 계열) | **성립하지 않는다.** 공격자가 **DB 접속 비밀번호를 통제**해야 하는데, 여기서는 `config.php`에 관리자가 넣는 값이다 |
| PHP-CGI 인자 주입 RCE (CVE-2024-4577) | **조건 미충족(추정).** PHP를 **CGI 모드로, Windows에서** 특정 코드페이지로 돌릴 때만 성립한다. 대상 호스팅이 리눅스면 무관하다 — 그래도 아래 실행 모드 확인에 포함시켰다 |
| `password_verify('', $hash)` 우회 (CVE-2024-3096) | **도달 불가.** 저장된 해시가 **NUL로 시작하는** 비밀번호에서 만들어졌을 때만 성립하는데, `v_string()`의 `trim()`이 PHP 기본 문자셋(`" \t\n\r\0\x0B"`)에 NUL을 포함하므로 선행 NUL이 저장 전에 제거된다. **다만 이것은 우연한 방어였다** — v1.1.0에서 `validate_password()`에 NUL **명시 차단**을 넣어 우연에 의존하지 않게 했다(감사 V11-L-5 / D-66 — `## 보안 설계 요약`의 "비밀번호에 NUL을 넣어 정책 우회" 행) |
| `__Host-` / `__Secure-` 쿠키 접두사 우회 (CVE-2024-2756) | **무관.** 이 시스템은 두 접두사를 쓰지 않는다. **다만 "7.2에서 `__Host-` 접두사를 붙여 강화하자"는 시도는 하지 마라** — 7.2에는 이 CVE의 패치가 없어서 **거짓 안심**이 된다 |

> 위 CVE 식별자는 7.2.34 이후 공개된 권고 중 이 코드가 쓰는 확장에 해당하는 것을 정리한 것이며,
> **실제 7.2 빌드로 대조 검증하지는 못했다**(`## 미검증 항목` 10번). 결론은 식별자의 정확성에
> 의존하지 않는다 — **7.2에는 어떤 인터프리터 결함에도 패치가 나오지 않는다**는 것이 위험의 본질이다.

#### 그래서 배포 전에 할 일 — 호스팅의 PHP 실행 모드를 확인하라

위 판정이 갈리는 지점이 **실행 모드(SAPI)** 하나다. 7.2를 계속 쓸 수밖에 없다면, 최소한
자기가 어느 구성에 있는지는 알고 있어야 한다.

**방법 1 — 호스팅 제어판.** "PHP 버전" / "PHP 핸들러" / "PHP 실행 방식" 메뉴에 보통
`PHP-FPM` · `FastCGI(CGI)` · `mod_php` · `LiteSpeed` 중 하나가 표시된다.

**방법 2 — 직접 확인 (30초).** `INSTALL.md` 0-1절의 `phpcheck.php` 스니펫이 **`실행 모드(SAPI)` 행을
이미 출력한다.** 그것을 쓰면 된다. 그 파일을 아직 안 만들었다면 아래 한 줄만으로도 충분하다 —
설치 루트에 올리고 브라우저로 열어 본 뒤 **즉시 삭제한다.**

```php
<?php echo PHP_SAPI, ' / ', PHP_VERSION;
```

출력 해석:

| 출력 | 실행 모드 | 위 표의 의미 |
|---|---|---|
| `fpm-fcgi` | **php-fpm** | 위 권한 상승 계열의 성립 조건에 해당한다. 같은 서버에 다른 테넌트가 있는지까지 확인하라 |
| `cgi-fcgi` | FastCGI / CGI | CVE-2024-4577은 **Windows + 특정 코드페이지**에서만 성립한다. 리눅스면 무관 |
| `apache2handler` | mod_php | 위 두 계열과 무관. `php_flag engine off`가 동작하는 구성이다(`## 미검증 항목` 4번) |
| `litespeed` | LiteSpeed SAPI | php-fpm이 아니다. 다만 자체 SAPI도 7.2 대상 패치는 없다 |

**방법 3 — 호스팅사에 물어볼 문장.** 그대로 복사해 쓰면 된다.

```
1. PHP를 php-fpm / FastCGI(CGI) / mod_php / LiteSpeed 중 무엇으로 실행하고 있습니까?
2. 같은 서버에 다른 고객 계정이 함께 올라가 있는 공용 환경입니까?
3. PHP 7.4 이상을 선택할 수 있습니까? 가능하면 어떤 절차로 바꿉니까?
```

3번이 "가능하다"면 **거기서 끝이다.** 이 절 전체가 필요 없어진다 — 7.4 이상으로 올려라.

---

## 아키텍처

```
┌────────────────────────┐                      ┌──────────────────────────────────┐
│  내 로컬 HTML/AI 앱      │                      │  ulogin 서버 ({설치루트})           │
│  (키 없음, 정적 파일)      │                      │                                  │
│                        │  ① login()           │  public/login.html               │
│  ulogin-client.js  ────┼──── 리다이렉트 ───────→ │    ↓ 아이디/비번                   │
│                        │                      │  api/sso/validate.php  (사전 검증)  │
│                        │                      │  api/sso/authorize.php (code 발급)  │
│  callback.html    ←────┼──── code + state ──── │    60초 · 1회용 · PKCE             │
│    ↓ handleCallback()  │                      │                                  │
│                        │  ② exchange          │  api/auth/exchange.php            │
│                        │─────────────────────→ │    → access/refresh token         │
│                        │←──────────────────────│    → user · session · payload     │
│                        │                      │      (등급 통과한 시크릿만)          │
│  ③ payload.ai_api_key  │                      │                                  │
│     (메모리에만 보관)     │                      │  MySQL: app_ulogin_* 15 테이블      │
│         ↓              │                      │   users · services · secrets(AES) │
│  ④ AI 호출 + 포인트 차감  │  api/points/consume  │   sessions · logs · settings …    │
│                        │─────────────────────→ │                                  │
└────────────────────────┘                      └──────────────────────────────────┘
```

세 줄 설명:

1. **앱은 키를 갖지 않는다.** 리다이렉트 SSO(PKCE + `state`)로 로그인하고, 복귀 시 1회용 `code`를
   토큰과 `payload`로 교환한다. `payload`가 그 사용자에게 허용된 시크릿의 전부다.
2. **서버가 등급을 판정한다.** 계정등급 · 역할 · 결제등급 3축을 **랭크 정수**로 비교해
   시크릿마다 걸린 게이트를 통과하는 것만 `payload`에 담는다. 미달분은 에러 없이 빠진다.
3. **시크릿은 DB에 AES-256-CBC로 암호화 저장된다.** 관리자 콘솔의 목록에도 평문은 나오지 않고,
   최고관리자의 "값 보기" 한 경로에서만 나가며 그 조회가 감사 로그에 남는다.

---

## 디렉토리 구조

```
v1.2.0_20260828/                    ← 이 폴더의 내용을 그대로 올린다. 그 위치가 "설치 루트"
├── README.md                       이 문서
├── INSTALL.md                      설치 절차 (사람용)
├── AI_INTEGRATION_GUIDE.md         연동 가이드 (다른 AI 에이전트용 자립형 단일 문서)
├── CHANGELOG.md                    버전 이력
│
├── server/
│   ├── install.php                 웹 설치기 (8단계). 설치 후 삭제한다
│   ├── config.sample.php           설정 템플릿 (상수 51개, 실제 값 없음)
│   ├── config.php                  설치기가 생성. 웹 접근 차단 필수
│   ├── schema.sql                  DDL 15 테이블 (CREATE TABLE IF NOT EXISTS)
│   ├── .htaccess                   rewrite(편의) + 확장자·설정파일 차단
│   ├── core/                       내부 모듈 13개 + .htaccess
│   │     bootstrap.php  db.php     request.php  response.php  auth.php
│   │     perm.php       payload.php crypto.php  ratelimit.php logger.php
│   │     serializer.php mailer.php  gc.php
│   ├── api/                        엔드포인트 32파일
│   │   ├── auth/   register login logout refresh session csrf
│   │   │           password_forgot password_reset exchange
│   │   │           email_verify email_resend
│   │   ├── sso/    validate authorize
│   │   ├── me/     index password email sessions login_logs services points withdraw
│   │   ├── points/ consume
│   │   └── admin/  stats users user_detail user_points user_status
│   │                user_sessions services secrets logs settings
│   └── storage/                    메일 로그 출력. 웹 접근 차단 필수
│         .htaccess  index.html  mail/
│
├── public/                         화면. 반드시 이 디렉토리 "직하"에만 둔다
│   ├── .htaccess                   보안 헤더(CSP·X-Frame-Options·Referrer-Policy 등)
│   │                               + 캐시 정책 3종(HTML·자산·version.txt) + 목록 노출 차단
│   ├── version.txt                 지금 서버에 올라간 버전 문자열 한 줄. 배포 반영 확인 수단
│   ├── index.html login.html register.html forgot.html reset.html
│   ├── verify.html mypage.html admin.html
│   └── assets/  style.css  api.js  ui.js  ulogin-client.js
│                                   HTML 의 참조 URL 에는 `?v={버전}` 이 붙어 있다 (캐시 무효화)
│
└── examples/
    ├── .htaccess                   데모 HTML 의 Cache-Control: no-store + 점파일 차단
    │                               callback.html 이 복귀 주소 화이트리스트 기본값이라 필요하다
    │                               (보안 헤더 5종은 데모를 깨뜨려 의도적으로 넣지 않았다 —
    │                                이유는 이 파일 머리 주석에 있다)
    ├── local-ai-app.html           동작하는 데모 (키 없음)
    ├── callback.html               복귀 처리
    └── README.md                   3분 연동 가이드
```

> **`public/` 아래에 하위 디렉토리를 만들어 화면을 추가하지 마라.**
> `api.js`의 `ULOGIN_API_BASE = '../server'`가 상대 경로라 그 전제가 깨지면 API 호출이 전부 어긋난다.

---

## 주요 기능

### 회원

- 회원가입 (아이디·이메일·비밀번호, 가입 보너스 포인트)
- 로그인 / 로그아웃 / 토큰 갱신
- 비밀번호 찾기 → 메일 링크 → 재설정
- 이메일 인증 (발송·재발송·소비). `REQUIRE_EMAIL_VERIFY`로 필수화 가능
- 마이페이지 **7탭** — 정보 / 보안 / 포인트 / 세션 / 로그인 이력 / 서비스 / 탈퇴
- 다중 기기 세션 관리 (개별 종료 가능)
- 탈퇴 (비밀번호 + 아이디 재입력 확인). 포인트 잔액은 보존된다

### 인증

- **리다이렉트 SSO** — `validate` → `authorize`(60초 1회용 `code`) → `exchange`
- **PKCE (S256)** — `code_challenge`를 보내면 검증한다. 보안 컨텍스트가 아니면 클라이언트가 생략한다
- **`state` 검증** — 클라이언트가 대조한다. 불일치 시 교환을 중단한다
- **Bearer 토큰** (로컬 앱) 또는 **쿠키 세션** (서버 자체 UI). 인증자는 `access_token` 해시로 통일
- `redirect_whitelist` **완전 일치** 검사 — 오픈 리다이렉트를 만들지 않는다
- 서비스별 `allowed_origins` 기반 CORS
- 레이트리밋 + 계정 락아웃 (기본 5회 / 15분)

### 포인트 · 등급

- 전역 포인트 잔액. 적립은 관리자만, 차감은 앱이 `points/consume`으로 한다
- **멱등키** 지원 — 같은 키로 재요청하면 중복 차감되지 않는다
- 3축 등급(계정등급 / 역할 / 결제등급) × 서비스별 오버라이드
- 결제등급 만료(`billing_expires_at`) 시 자동 강등 판정

### 관리자 (콘솔 6탭)

**대시보드 / 회원 / 서비스 / 시크릿 / 로그 / 설정**

- 대시보드 — 회원 수·가입 추이·로그인 통계
- 회원 — 목록·검색·상세·등급 조정·포인트 조정·정지/복구·비밀번호 초기화·세션 강제 종료·하드 삭제
- 서비스 — 등록·수정·복귀주소·허용출처·세션 수명·`auto_join`·`disabled` 전환
- 시크릿 — 서비스별 등록·등급 게이트·**superadmin 전용 평문 조회(감사 로그 기록)**
- 로그 — 로그인 로그 / 관리자 조작 로그
- 설정 — 전역 설정 **12개 키** (가입 허용, 이메일 인증 필수, 보너스 포인트, 락아웃 임계·시간,
  비밀번호 최소 길이, 세션·refresh 기본 수명, 점검 모드, 메일 드라이버, 사이트명, SSO code 수명)

---

## API 요약

**엔드포인트 파일 32개 / 메서드 × 경로 조합 43개.**

> **43과 45 — 어느 기준으로 센 숫자인가.** 이 문서의 **43**은 **메서드 × 경로 조합 43개**
> (`only_method()`가 허용하는 메서드의 총합)이다. `AI_INTEGRATION_GUIDE.md` 5절의 요약표는
> **같은 43개를** 쿼리 파라미터로 동작이 갈리는 2건(`admin/secrets.php` GET의 `action=reveal`,
> `admin/logs.php` GET의 `type=login`/`type=admin`)을 별행으로 세어 **45행**으로 적는다.
> **45 − 2 = 43.** 두 숫자는 같은 API를 다른 기준으로 센 것이고 **둘 다 맞다** —
> 관리자 설정 키의 `11`(설치기 시드)과 `12`(관리자 API)가 둘 다 맞는 것과 같다.
> **한쪽으로 통일하지 마라.** 다시 세는 명령은 아래와 같다.
>
> ```bash
> # 43 — 메서드 × 경로 조합
> grep -rho "only_method([^)]*)" server/api | grep -o "'" | wc -l | awk '{print $1/2}'
> # 45 — AI 가이드 5-6절 요약표의 행 수
> awk '/^### 5-6\./,/^---$/' AI_INTEGRATION_GUIDE.md | grep -c '^| [0-9]'
> ```

논리 경로는 `/api/auth/login` 형태지만 **실제 호출은 `.php` 확장자를 붙인다** —
`{설치루트}/server/api/auth/login.php`. 동적 세그먼트는 쓰지 않고 **쿼리 파라미터**로 받는다.

응답 봉투는 항상 아래 형태다. **`data`를 벗겨서 써야 한다.**

```json
{ "ok": true,  "data": { }, "error": null }
{ "ok": false, "data": null, "error": { "code": "AUTH_REQUIRED", "message": "...", "field": null } }
```

`error`의 키는 **`code` · `message` · `field` 셋뿐이다**(`core/response.php`). `field`는 입력값 오류일
때만 채워지고 없으면 `null`이지만 **키는 항상 존재한다.** 부가정보(`retry_after` · `locked_until` ·
`shortfall` 등)는 `error` 안이 아니라 **최상위 `data`** 에 실려 온다 — 부가정보가 없으면 `data`는 `null`이다.

### 인증 (`api/auth/`)

| 메서드 | 실제 호출 경로 | 인증 | 설명 |
|---|---|---|---|
| POST | `auth/register.php` | 없음 | 회원가입 |
| POST | `auth/login.php` | 없음 | 직접 로그인 (서버 자체 UI용) |
| POST | `auth/logout.php` | Bearer/쿠키 | 세션 회수 |
| POST | `auth/refresh.php` | refresh token | 토큰 갱신 |
| GET | `auth/session.php` | Bearer/쿠키 | 세션·user·payload 재조회 |
| GET | `auth/csrf.php` | 쿠키 | CSRF 토큰 발급 |
| POST | `auth/password_forgot.php` | 없음 | 재설정 메일 요청 (항상 성공 응답) |
| POST | `auth/password_reset.php` | 토큰 | 비밀번호 재설정 |
| POST | `auth/exchange.php` | 없음 | SSO `code` → 토큰·`payload` 교환 |
| POST | `auth/email_verify.php` | 없음 | 이메일 인증 토큰 소비 |
| POST | `auth/email_resend.php` | 없음 | 인증 메일 재발송 (항상 성공 응답) |

### SSO (`api/sso/`)

| 메서드 | 실제 호출 경로 | 인증 | 설명 |
|---|---|---|---|
| GET | `sso/validate.php?service_id=&redirect_uri=` | 없음 | 리다이렉트 **전** 사전 검증 |
| POST | `sso/authorize.php` | 없음 | 자격증명 검증 → `code` 발급(60초 1회용) |

### 내 정보 (`api/me/`) — 전부 Bearer 또는 쿠키 필요

| 메서드 | 실제 호출 경로 | 설명 |
|---|---|---|
| GET / PUT | `me/index.php` | 내 정보 조회 / 수정 |
| PUT | `me/password.php` | 비밀번호 변경 |
| PUT | `me/email.php` | 이메일 변경 |
| GET / DELETE | `me/sessions.php?session_id=` | 세션 목록 / 개별 종료 |
| GET | `me/login_logs.php?page=&per_page=` | 로그인 이력 |
| GET | `me/services.php` | 가입한 서비스 목록 (`membership_status` 포함) |
| GET | `me/points.php?page=&per_page=` | 포인트 내역 |
| POST | `me/withdraw.php` | 탈퇴 (`password` · `reason` · `confirm` 필수) |

### 포인트 (`api/points/`)

| 메서드 | 실제 호출 경로 | 설명 |
|---|---|---|
| POST | `points/consume.php` | 포인트 차감. 멱등키 지원 |

### 관리자 (`api/admin/`) — 최소 역할은 **전역 `users.role`** 로 판정한다

| 메서드 | 실제 호출 경로 | 최소 역할 | 설명 |
|---|---|---|---|
| GET | `admin/stats.php` | manager | 대시보드 통계 |
| GET | `admin/users.php?q=&status=&page=` | manager | 회원 목록 |
| GET | `admin/user_detail.php?uuid=` | manager | 회원 상세 |
| PUT | `admin/user_detail.php?uuid=` | admin | 회원 수정 |
| DELETE | `admin/user_detail.php?uuid=&confirm=` | **superadmin** | 하드 삭제 |
| POST | `admin/user_points.php?uuid=` | admin | 포인트 조정 |
| POST | `admin/user_status.php?uuid=` | admin | `action`: suspend / restore / reset-password |
| DELETE | `admin/user_sessions.php?uuid=` | admin | 세션 강제 종료 |
| GET/POST/PUT/DELETE | `admin/services.php` | admin | 서비스 관리 |
| GET/POST/PUT/DELETE | `admin/secrets.php?service_id=` | admin | 시크릿 관리 |
| GET | `admin/secrets.php?service_id=&action=reveal&id=` | **superadmin** | 평문 1건 조회 (감사 로그 기록) |
| GET | `admin/logs.php?type=login\|admin` | manager / admin | 로그 조회 |
| GET / PUT | `admin/settings.php` | admin | 전역 설정 12개 |

### 에러 코드 (31개)

```
VALIDATION_FAILED          AUTH_INVALID_CREDENTIALS  AUTH_REQUIRED
AUTH_SESSION_EXPIRED       AUTH_ACCOUNT_LOCKED       AUTH_ACCOUNT_SUSPENDED
AUTH_ACCOUNT_WITHDRAWN     AUTH_ACCOUNT_DORMANT      AUTH_ACCOUNT_PENDING
AUTH_EMAIL_UNVERIFIED      AUTH_PASSWORD_MISMATCH    FORBIDDEN
TIER_INSUFFICIENT          NOT_FOUND                 DUPLICATE_USERNAME
DUPLICATE_EMAIL            DUPLICATE_SERVICE_ID      DUPLICATE_SECRET_KEY
POINT_INSUFFICIENT         CONFLICT                  SSO_INVALID_SERVICE
SSO_INVALID_REDIRECT       SSO_CODE_INVALID          SSO_CLIENT_AUTH_FAILED
TOKEN_INVALID              CSRF_FAILED               RATE_LIMITED
REGISTRATION_DISABLED      MAINTENANCE_MODE          METHOD_NOT_ALLOWED
SERVER_ERROR
```

> 각 엔드포인트의 요청·응답 JSON 전문과 에러별 처리 방법은 `AI_INTEGRATION_GUIDE.md`에 있다.

---

## 권한 모델

**3축이 서로 독립이다.** 하나의 계정이 세 가지 축의 값을 동시에 갖는다.

| 축 | 컬럼 | 값과 **랭크** | 무엇을 정하는가 |
|---|---|---|---|
| **계정등급** | `account_level` | `guest`(0) · `basic`(10) · `verified`(20) · `vip`(30) · `staff`(90) | 회원 자격 단계. 가입·이메일 인증·수동 승급으로 오른다 |
| **역할** | `role` | `user`(0) · `manager`(10) · `admin`(20) · `superadmin`(30) | 관리 권한 |
| **결제등급** | `billing_tier` | `free`(0) · `lite`(10) · `pro`(20) · `enterprise`(30) | 유료 기능 접근. `billing_expires_at` 만료 시 `free`로 강등 |

### 반드시 랭크 정수로 비교하라

**문자열 비교는 틀린다.** `'verified' > 'vip'` 는 사전순으로 `false`지만 랭크로는 `20 < 30`이다.
클라이언트에서 등급으로 UI를 가릴 때도 랭크 맵을 두고 정수로 비교해야 한다.

```js
var ACCOUNT_LEVEL_RANK = { guest: 0, basic: 10, verified: 20, vip: 30, staff: 90 };
var ROLE_RANK          = { user: 0, manager: 10, admin: 20, superadmin: 30 };
var BILLING_TIER_RANK  = { free: 0, lite: 10, pro: 20, enterprise: 30 };

function rankOf(map, v) { return Object.prototype.hasOwnProperty.call(map, v) ? map[v] : 0; }

// 올바름 — 랭크 정수 비교
if (rankOf(BILLING_TIER_RANK, user.billing_tier) >= BILLING_TIER_RANK.lite) { showProFeature(); }

// 틀림 — 문자열 비교
if (user.billing_tier >= 'lite') { /* 'pro' > 'lite' 는 우연히 true, 'enterprise' 는 false */ }
```

> **화면을 가리는 것은 편의일 뿐이다. 실제 방어는 서버에서 한다.**
> 클라이언트가 무엇을 숨기든 서버가 등급을 다시 판정한다.

### 유효 등급 계산 순서

1. 전역 값 (`users.account_level` / `role` / `billing_tier`)
2. **서비스 오버라이드** (`user_services.account_level` / `role` / `billing_tier`) — `NULL`이면 전역 상속
3. **결제 만료 강등** — `billing_expires_at`이 지났으면 `free`로 내린다
4. **`user_services.status = 'blocked'`** 이면 그 서비스에서 `payload`가 빈 객체가 되고 로그인이 `FORBIDDEN`

**서비스 오버라이드는 올려주기만 한다 (grant-only).** 자동 가입·명시적 가입 모두,
서비스의 `default_account_level`이 사용자의 전역 등급보다 **랭크가 높을 때만** 오버라이드로 기록된다.
전역 `verified` 사용자가 서비스 기본값 `basic` 때문에 강등되는 일은 없다 (리더 판정 D-35 / D-49).

> **서비스 오버라이드 `role`은 로그인 서버의 관리 권한이 되지 않는다.**
> 관리자 API는 행위자·대상 모두 **전역 `users.role`만** 본다 (리더 판정 D-50).
> 특정 서비스에서 `admin` 오버라이드를 받은 사용자가 서버 전체의 관리자가 되면 그 자체가 권한 상승이다.

### 관리자 조작의 대상 랭크 규칙

자신과 **같거나 높은** `role_rank`를 가진 **다른** 계정은 어떤 변경도 할 수 없다
(리더 판정 D-30 / D-41). `admin`이 다른 `admin`의 이메일을 바꿀 수 있으면
"이메일 변경 → 비밀번호 찾기 → 탈취" 경로가 수평으로 성립하기 때문이다.

---

## 로컬 앱 연동

앱 코드에 키가 없는 완전한 최소 예제다. 그대로 복사해 두 파일을 만들면 동작한다.

### `app.html`

```html
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <title>내 AI 앱</title>
</head>
<body>
  <!-- 이 파일에는 API 키가 없습니다. 로그인 후 서버에서 받아옵니다. -->
  <div id="status">확인 중…</div>
  <div id="app" hidden>
    <p id="who"></p>
    <textarea id="prompt" rows="4" cols="60" placeholder="프롬프트를 입력하세요"></textarea><br>
    <button id="send">보내기 (10P)</button>
    <button id="logout">로그아웃</button>
    <pre id="out"></pre>
  </div>

  <!-- ?v= 는 캐시 무효화용이다. 서버를 새 버전으로 갱신하면 이 값도 함께 올린다.
       지우면 브라우저가 옛 ulogin-client.js 를 무기한 계속 쓴다 (## 배포 반영과 캐시 무효화). -->
  <script src="https://example.com/ulogin/public/assets/ulogin-client.js?v=1.2.0"></script>
  <script>
  (function () {
    'use strict';

    // server 는 "설치 루트" 한 칸이다. /server 나 /public 을 붙이지 않는다.
    var ulogin = new UloginClient({
      server:      'https://example.com/ulogin',
      serviceId:   'local-ai-app',
      redirectUri: location.origin + '/callback.html'   // redirect_whitelist 와 완전히 같은 문자열
    });

    var $ = function (id) { return document.getElementById(id); };

    // 세션이 만료되면 재로그인을 유도한다.
    ulogin.onExpire(function () {
      $('status').hidden = false;
      $('status').textContent = '세션이 만료되었습니다. 다시 로그인하세요.';
      $('app').hidden = true;
    });

    async function boot() {
      if (!ulogin.isLoggedIn()) {
        $('status').textContent = '로그인이 필요합니다.';
        ulogin.login();          // 로그인 서버로 이동한다
        return;
      }

      var s;
      try {
        s = await ulogin.getSession();   // 토큰 검증 + payload 재수신 (새로고침 후 필수)
      } catch (err) {
        $('status').textContent = '세션 확인 실패: ' + err.code;
        return;
      }

      $('status').hidden = true;
      $('app').hidden = false;
      $('who').textContent =
        s.user.username + ' / 계정등급 ' + s.user.account_level +
        ' / 역할 ' + s.user.role + ' / 결제등급 ' + s.user.billing_tier +
        ' / 포인트 ' + s.user.points;

      var p = ulogin.getPayload();
      if (!p.ai_api_key) {
        // 등급 미달이면 에러가 아니라 키가 조용히 빠진다. 반드시 부재를 분기하라.
        $('out').textContent = '이 등급에서는 AI 기능을 쓸 수 없습니다.';
        $('send').disabled = true;
      }
    }

    $('send').addEventListener('click', async function () {
      var p = ulogin.getPayload();
      if (!p.ai_api_key) { return; }

      $('out').textContent = '호출 중…';
      try {
        // 먼저 포인트를 차감한다. 부족하면 여기서 멈춘다.
        var pt = await ulogin.consumePoints(10, { reason: 'ai_call' });

        var res = await fetch(p.api_base + '/chat/completions', {
          method:  'POST',
          headers: {
            'Content-Type':  'application/json',
            'Authorization': 'Bearer ' + p.ai_api_key
          },
          body: JSON.stringify({
            model:    p.model,
            messages: [{ role: 'user', content: $('prompt').value }]
          })
        });
        var json = await res.json();
        $('out').textContent =
          (json.choices && json.choices[0] ? json.choices[0].message.content : JSON.stringify(json)) +
          '\n\n남은 포인트: ' + pt.balance;
      } catch (err) {
        $('out').textContent = '실패: ' + (err.code || err.message);
      }
    });

    $('logout').addEventListener('click', async function () {
      await ulogin.logout();
      location.reload();
    });

    boot();
  })();
  </script>
</body>
</html>
```

### `callback.html`

```html
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <title>로그인 처리 중…</title>
</head>
<body>
  <p id="msg">로그인 처리 중…</p>

  <!-- ?v= 는 캐시 무효화용이다. 서버를 새 버전으로 갱신하면 이 값도 함께 올린다.
       지우면 브라우저가 옛 ulogin-client.js 를 무기한 계속 쓴다 (## 배포 반영과 캐시 무효화). -->
  <script src="https://example.com/ulogin/public/assets/ulogin-client.js?v=1.2.0"></script>
  <script>
  (function () {
    'use strict';

    // app.html 과 **같은 값**으로 하나 더 만든다.
    var ulogin = new UloginClient({
      server:      'https://example.com/ulogin',
      serviceId:   'local-ai-app',
      redirectUri: location.origin + '/callback.html'
    });

    ulogin.handleCallback().catch(function (err) {
      document.getElementById('msg').textContent = '로그인 실패: ' + err.code + ' — ' + err.message;
    });
    // 성공하면 클라이언트가 알아서 return_to(로그인 버튼을 누른 화면)로 돌려보낸다.
  })();
  </script>
</body>
</html>
```

**`redirectUri`에 넣은 문자열을 관리자 콘솔의 `redirect_whitelist`에 한 글자도 다르지 않게 등록해야 한다.**
완전 일치로만 비교한다.

**`payload`를 저장하지 마라.** `localStorage`에 넣으면 이 시스템을 만든 이유가 사라진다.
클라이언트는 `payload`를 **메모리에만** 두고, 새로고침 후에는 `getSession()`으로 다시 받는다.

---

## 운영 — 관리자 콘솔에서 하는 일

```
{설치루트}/public/admin.html
```

| 탭 | 최소 역할 | 하는 일 |
|---|---|---|
| **대시보드** | manager | 회원 수·가입 추이·로그인 성공/실패 통계 |
| **회원** | manager (읽기) / admin (조작) | 검색·상세·등급 조정·포인트 조정·정지/복구·비밀번호 초기화·세션 종료 / superadmin: 하드 삭제 |
| **서비스** | admin | 등록·수정·복귀주소·허용출처·`auto_join`·세션 수명·`disabled` 전환 |
| **시크릿** | admin / superadmin(평문) | 서비스별 등록·등급 게이트·값 보기 |
| **로그** | manager(로그인) / admin(관리자) | 로그인 로그 7종 결과 · 관리자 조작 이력 |
| **설정** | admin | 전역 설정 12개 키 |

`manager`로 로그인하면 **대시보드 / 회원(읽기 전용) / 로그** 3탭만 보인다.
나머지 탭은 비활성이 아니라 **DOM에서 제거**된다 (리더 판정 D-36).

**로그인 로그 `result` 7종:**
`success` · `fail_credential` · `fail_locked` · `fail_status` · `fail_ratelimit` ·
`fail_service_blocked` · `logout`

### 정기 점검 항목

- **시크릿 게이트** — 게이트 열이 비어 있는 민감 키가 없는지 확인한다.
  게이트 강제는 **생성·수정 시점**만 막으므로 그 이전에 만들어진 행은 그대로 동작한다
- **`storage/mail/`** — `log` 드라이버를 쓴다면 재설정 링크와 임시 비밀번호가 쌓인다.
  처리 후 즉시 삭제한다. `mail()`이 동작하면 설정에서 `mail`로 바꾼다
- **`DEBUG`** — `server/config.php`에서 항상 `false`인지 확인한다
- **관리자 로그** — `secret.reveal` 기록을 확인한다. 누가 언제 어떤 키의 평문을 봤는지 남는다

---

## 배포 반영과 캐시 무효화

**v1.2.0에서 추가된 장치다.** 이 절이 없던 v1.1.0까지는 새 버전을 올려도 브라우저가 옛 화면을
계속 쓰는 일이 있었고, **반영됐는지 확인할 수단조차 없었다.**

갱신 절차 자체는 `INSTALL.md`의 `## 8. 새 버전으로 갱신하기`에 7단계로 있다.
이 절은 **그 절차가 무엇에 의존하는지**를 설명한다.

### 장치 셋

| 장치 | 파일 | 무엇을 하는가 |
|---|---|---|
| **자산 URL의 `?v={버전}`** | `public/*.html` · `examples/*.html`의 `<link>`·`<script src>` | 버전이 바뀌면 URL이 바뀌므로 브라우저에게는 **처음 보는 주소**다. 무조건 새로 받는다. **주 수단** |
| **`public/version.txt`** | 버전 문자열 한 줄 (예: `1.2.0_20260828`) | 지금 서버에 올라간 버전을 밖에서 확인하는 수단 |
| **`public/.htaccess` 캐시 3종** | HTML `no-store` / 자산 `public, max-age=31536000, immutable` / `version.txt` `no-store` | 위 두 장치를 뒷받침하는 **보강**이다. 이것이 주 수단은 아니다 |
| **`examples/.htaccess`** | 데모 HTML `no-store` (+ 점파일 차단) | `examples/callback.html`이 **복귀 주소 화이트리스트 기본값**이라 SSO 플로우의 실참여자다. 이 HTML이 캐시되면 그 안의 옛 `?v=`가 굳고 참조 자산이 `immutable` 1년으로 잠긴다. **보안 헤더 5종은 데모를 깨뜨려 의도적으로 넣지 않았다** — 이유는 그 파일 머리 주석에 있다 |
| **`public/*.html` · `examples/*.html`의 `<meta http-equiv="Cache-Control">`** | HTML `no-store` (보조) | 현대 브라우저는 이 meta를 무시할 수 있다. **보증이 아니라 보조다.** 데모를 로컬 앱으로 복사해 갈 때 `.htaccess`는 따라가지 않으므로 복사본에 남는 유일한 수단이다 |

### 왜 `?v=`를 주 수단으로 골랐는가

**서버 설정에 의존하지 않기 때문이다.** 그리고 지금 문제의 근원이 바로 그 설정이 안 먹는 환경일
가능성이 크다.

`public/.htaccess`의 캐시 헤더는 전부 `<IfModule mod_headers.c>` 안에 있다.
그 모듈이 없거나, `AllowOverride None`이거나, nginx·LiteSpeed 비호환 모드이면
**`.htaccess`는 통째로 무시되고 오류도 나지 않는다.** 조용히 아무 일도 일어나지 않는다.
공용 호스팅에서 실제로 흔한 조건이고, **배포자는 왜 반영이 안 되는지 알 방법이 없다.**

`?v=`는 그 계층을 건너뛴다.

| 방식 | 무엇에 의존하는가 | `.htaccess`가 무시되면 |
|---|---|---|
| `Cache-Control` 헤더 | `mod_headers` · `AllowOverride` · 웹서버 종류 · 중간 프록시 | **사라진다** |
| `<meta http-equiv="Cache-Control">` | 브라우저가 이 meta를 존중하는지 (현대 브라우저는 무시할 수 있다) | 남지만 **보증이 못 된다** |
| **자산 URL의 `?v=`** | **아무것도 의존하지 않는다.** URL이 다르면 다른 자원이다 | **그대로 동작한다** |

캐싱을 끄는 방식이 아니라는 점도 중요하다. **같은 버전 안에서는 캐시가 계속 효력을 가진다** —
자산에 `immutable`을 걸어 둘 수 있는 이유가 바로 버전 쿼리가 있기 때문이다.

> **`?v=` 방식의 유일한 전제는 "HTML은 항상 새로 받는다"** 이다. HTML이 캐시되면
> 새 자산 URL을 가리키는 **새 HTML조차 도달하지 않아** 이 방식이 무력해진다.
> 그 구멍은 프론트 코드로 닫을 수 없고 웹서버 설정으로만 닫힌다 —
> nginx / `AllowOverride None` 환경용 스니펫이 `INSTALL.md` 7-9절 `#### 캐시 헤더 3종`에 있다.
> **`.htaccess`가 무시되는 환경이라면 그 스니펫이 선택이 아니라 필수다.**

#### 그 전제가 깨지는 것은 "링크·주소창 재진입"뿐이다 — 새로고침은 설정 없이도 동작한다

위 전제가 깨진다는 것이 **"아무것도 반영되지 않는다"는 뜻은 아니다.** 깨지는 경로는 좁다.
`mod_headers`가 **없는** Apache 인스턴스를 별도로 만들어 실측했다
(2026-08-28, `_workspace/25_qa_v1.2.md` `## A. 사용자 문제 해결 확인`).

| 사용자가 한 동작 | `mod_headers` 있음 (의도한 배포 형태) | `mod_headers` 없음 / `.htaccess` 무시 |
|---|---|---|
| **일반 새로고침** (`F5` · `Ctrl+R`) | **반영된다** | **반영된다** |
| **링크를 눌러 다시 들어오기 / 주소창에서 Enter / 북마크** | **반영된다** (HTML `no-store`) | **지연될 수 있다.** HTML이 캐시에서 나오고 그 안의 `?v=`가 옛 값이다 |

**일반 새로고침은 브라우저가 최상위 문서를 항상 서버에 재검증한다.** 그래서 캐시 헤더가 없어도
새 HTML이 오고, 새 HTML이 가리키는 새 자산 URL이 브라우저에 도달한다.
링크·주소창 재진입은 그 재검증을 하지 않으므로 HTML이 캐시에서 나올 수 있다.

**"반영이 안 된다"는 신고를 받으면 이것을 먼저 물어라 —
"새로고침해도 안 되는가, 링크로 들어갔을 때만 안 되는가."** 원인이 갈린다.

| 신고 내용 | 원인 | 조치 |
|---|---|---|
| **링크로 들어갈 때만 안 된다** | 웹서버 설정에서 HTML `no-store`가 빠졌다 | `INSTALL.md` 7-9절 `#### 캐시 헤더 3종`의 스니펫. 그때까지는 새로고침 1회로 해소된다 |
| **새로고침해도 안 된다** | 여기가 원인이 아니다 — `?v=` 값이 안 올라갔거나, 업로드가 안 됐거나, PHP 코드 캐시다 | `INSTALL.md` 8-1 · 8-3 · 8-6절 |

> **그래도 그 스니펫은 필수다.** 방문자에게 "새로고침해 주세요"를 요구할 수 없고
> (`### 강제 새로고침은 해결책이 아니다`), 로그인 링크·SSO 복귀 리다이렉트·북마크로 들어오는
> 경로가 실제 사용의 대부분이다. **다만 그 설정이 없어도 `?v=` 방식이 통째로 죽는 것은 아니다** —
> 서버 설정에 의존하지 않는 방식을 고른 이유가 그것이다.

### `public/version.txt`의 용도

```bash
curl -sS https://your-host.com/ulogin/public/version.txt
```

```
1.2.0_20260828
```

- **업로드가 실제로 됐는지 확인하는 수단이다.** 올린 값과 다른 값이 보이면 업로드가 안 됐거나
  다른 경로에 올라갔다. 그 자리에서 멈추고 확인해야 한다
- `.htaccess`에서 `no-store`다. 확인 수단이 캐시되면 그 수단이 거짓말을 시작한다.
  그래서 브라우저보다 **`curl`을 먼저 쓰라** — `curl`은 캐시를 쓰지 않는다
- 형식은 `{버전}_{YYYYMMDD}`로 **버전 폴더명과 1:1 대응**한다.
  자산 URL의 `?v=1.2.0`(짧은 값, Network 탭에서 읽는 값)과 **형식이 다른 것은 의도다**

### API가 새 코드로 도는지 확인 — `no-store` 단독이면 옛 코드다

자산과 HTML이 새것이어도 **PHP는 별개다.** 서버가 PHP 코드 캐시(opcache)를 쓰면 파일을 새로
올려도 옛 코드가 계속 실행된다. v1.2.0은 그 판별 수단을 응답 헤더에 만들어 두었다.

```bash
curl -sS -D - -o /dev/null "https://your-host.com/ulogin/server/api/me/index.php" | grep -i "cache-control\|pragma\|expires"
```

| 보이는 것 | 판정 |
|---|---|
| `Cache-Control: no-store, no-cache, must-revalidate, max-age=0` + `Pragma: no-cache` + `Expires: 0` | **v1.2.0 코드가 돌고 있다** |
| `Cache-Control: no-store` **단독** | **v1.1.0 이하의 PHP 코드가 돌고 있다** — opcache가 옛 opcode를 쓰는 것이다 |

`core/response.php`의 `response_headers()`는 **모든 JSON 응답이 지나는 단일 지점**이다.
그래서 응답에 `Pragma`가 없다는 것만으로 그 파일의 옛 버전이 실행되고 있음을 알 수 있다.
로그인도 DB도 필요 없다. 진단과 조치는 `INSTALL.md` 8-6절에 있다.

### 강제 새로고침은 해결책이 아니다

`Ctrl+Shift+R`(macOS `Cmd+Shift+R`)는 **내 브라우저의 캐시만 무시한다.**

- **방문자에게 요구할 수 없다.** 로그인 화면을 여는 사람 전원에게 전달할 방법이 없다
- **원인을 덮는다.** 그것으로 정상 동작하면 고쳤다고 착각하지만 HTML 캐싱이나 opcache는 그대로다
- **PHP에는 듣지 않는다.** opcache는 서버 쪽 캐시다

**쓸 곳은 진단이다.** 강제 새로고침으로만 정상이면 HTML이 캐시되고 있다는 확정 증거이고,
조치는 `INSTALL.md` 7-9절 `#### 캐시 헤더 3종`의 웹서버 설정이다.

> **의심 신호는 비대칭이다 — 자산·HTML은 바뀌었는데 API 동작만 그대로다.**
> opcache는 `.php`만 캐시한다. `.html`·`.css`·`.js`·`.txt`는 영향을 받지 않는다.

### 버전 문자열 일괄 치환 스크립트 — 배포물이 아니다

자산 참조는 `public/*.html`과 `examples/*.html`에 흩어져 있다. 손으로 넣으면 다음 릴리스에서
반드시 일부가 빠지고, 빠진 그 자산만 옛 것으로 남는다. 그래서 일괄 치환 스크립트를 둔다.

```
_workspace/bump_asset_version.php
```

> **이 파일은 배포물이 아니다. 호스팅에 업로드하지 마라.** 버전 폴더에 복사해 넣어서도 안 된다.
> `_workspace/`(작업 이력 폴더)에 두고 릴리스마다 재사용한다.
> 같은 폴더의 `php72_scan.php`(PHP 7.2 호환성 스캐너)도 마찬가지로 배포물이 아니다.

```bash
cd _workspace
php bump_asset_version.php <새버전> --dir=../<버전폴더> --version-txt=<새버전>_<YYYYMMDD>
```

스크립트는 파일별 참조 수와 변경 수를 출력하고, **버전 쿼리가 없는 참조를 발견하면 보고한 뒤
종료코드 1로 실패한다.** 조용히 붙여 주지 않는다 — 그러면 왜 빠져 있었는지가 묻히고 다음
릴리스에서 반복된다. 사용법·옵션·종료코드는 `INSTALL.md` 8-1절에 전부 있다.

### `?v=`와 `UloginClient.version`은 별개다

혼동이 반복되는 지점이므로 못박아 둔다.

| 값 | 현재 | 무엇인가 | 버전을 올릴 때 |
|---|---|---|---|
| 자산 URL의 `?v=` | `1.2.0` | 브라우저 캐시를 깨기 위한 **URL 파라미터** | **바꾼다** (bump 스크립트) |
| `public/version.txt` | `1.2.0_20260828` | 배포 반영 확인용 **버전 문자열** | **바꾼다** (`--version-txt=`) |
| `UloginClient.version` | `'1.0.0'` | 연동 클라이언트의 **공개 API 버전** | **바꾸지 않는다.** 실행 코드가 바뀌지 않았으므로 `'1.0.0'`이 정상 상태다 |

**셋을 같은 값으로 맞추려 하지 마라.** 서로 다른 것을 가리킨다.

### v1.2.0에서 자산 파일 내용은 바뀌지 않았다

정직하게 적어 둔다. `public/assets/` 4개 파일(`style.css` · `api.js` · `ui.js` ·
`ulogin-client.js`)은 **v1.1.0과 바이트 단위로 같다.** 바뀐 것은 그 파일을 가리키는
**참조 URL**이다.

```bash
# 프로젝트 루트에서 (두 버전 폴더가 나란히 있을 때)
diff -rq v1.1.0_20260826/public/assets v1.2.0_20260828/public/assets
```

따라서 v1.2.0으로 갱신할 때 자산을 다시 받는 것 자체에는 실이득이 없다.
**이 릴리스의 목적은 다음 릴리스부터 자산 변경이 확실히 반영되게 하는 장치를 넣는 것이다.**

---

## 보안 설계 요약 — 무엇을 어떻게 막았는가

**미해결 Critical 0건 / 미해결 High 0건.**
v1.0.0 감사 1회차의 배포 차단 7건(C-1 C-2 H-1 H-2 H-3 H-4 H-5)은 전부 수정 확인되었고,
2회차에서 나온 Critical 1건(C-3)과 Medium 4건(M-13 ~ M-16), Low 1건(L-8)은
수정 라운드 2(리더 판정 D-47 ~ D-55)에서 처리되었다.

**v1.1.0에서 쿠키 전송 경로를 대상으로 3회차 감사를 돌렸다** — 신규 Critical 0 / High 0,
Medium 1(V11-M-1) + Low 6(V11-L-1 ~ L-6), **배포 차단 0건.** 그 7건은
리더 판정 D-65 ~ D-68로 같은 라운드에서 처리했고, 아래 표의 해당 행에 반영되어 있다.
같은 라운드 마무리에서 **D-75**가 v1.0.0 감사의 M-3 잔존분(`COOKIE_SECURE` ↔ `FORCE_HTTPS` 런타임
교차 검증)을 닫았다 — 평문 HTTP 요청에 `Secure` 쿠키를 내보내려 하면 그 요청이 500으로 멈춘다.
설정 오류로 500이 나는 경로 전체는 아래 **`## 잘못된 설정은 조용히 넘어가지 않는다 (fail-closed)`** 에 정리했다.
**v1.2.0에서 캐시·배포 반영 변경분을 대상으로 4회차 감사를 돌렸다** — 신규 Critical 0 / High 0,
Medium 1(V12-M-1) + Low 2(V12-L-1 · V12-L-2), **배포 차단 0건.**
셋 다 **문서 수정만으로 닫혔고 실행 코드 변경을 요구하지 않았다**(리더 판정 D-95 ~ D-99).
이 라운드는 **범위 감사**였다 — 감사가 `diff -rq`로 `server/api/**` 32파일, `server/core/`의
`response.php` 외 12파일, `schema.sql`, `install.php`, `public/assets/**` 4파일이 v1.1.0과
**바이트 동일**함을 확인했고, 그래서 인증·세션·SSO·시크릿·SQL·XSS·CSRF·권한·레이트리밋은
**재감사하지 않았다**(구조적으로 회귀할 수 없다). 그 영역의 판정 근거는 여전히 1~3회차 감사다 —
`_workspace/13_security_v1.1.md` · `04_security_audit.md`.
남아 있는 Medium·Low는 `## 알려진 제한`의 `### 남아 있는 보안 항목`에 있다.

| 위협 | 어떻게 막았는가 |
|---|---|
| **AI 키가 클라이언트에 노출** | 키는 서버 DB에 AES-256-CBC로만 저장. 등급 게이트를 통과한 것만 `payload`로 전달. 클라이언트는 메모리에만 보관 |
| **가입만 하면 키를 받는 기본 설치** (C-3) | 설치기 7단계가 시크릿마다 등급 게이트를 받는다. 민감 키(`*_api_key`·`*_secret`·`*_token`·`*_password`)는 기본 `min_billing_tier='lite'`. 게이트를 비우려면 명시적 확인이 필요하다. 관리자 API도 같은 검사를 한다(D-47 / D-54) |
| **랭크 0 게이트로 위장** | `free` / `guest` / `user`는 랭크 0이라 전원 통과다. 설치기와 관리자 API가 이 값을 게이트로 세지 않는다 |
| **관리자 콘솔에서 실수로 게이트 해제** | 콘솔에는 `allow_public` 컨트롤이 **없다**. 게이트 없는 민감 시크릿은 API 직접 호출로만 만들 수 있다 — 의도적 마찰이다(D-55) |
| **시크릿 평문이 목록에 실림** (H-2) | `is_masked` 값과 무관하게 목록·상세·생성·수정 응답의 `secret_value`는 **항상 `****`**. 평문은 superadmin의 `?action=reveal` 한 경로에서만 나가고 `admin_logs`에 기록된다(D-24 / D-32) |
| **세션 ID를 훔쳐 인증** (C-1) | 쿠키에 담기는 값을 `session_id` → `access_token`으로 교체하고, 조회를 `access_token_hash` 단일 경로로 통일했다. `session_id`는 기기 목록 표시용으로만 응답에 남는다(D-29) |
| **관리자가 상급 계정을 탈취** (C-2 / H-3) | 대상 계정을 변경하는 모든 관리자 경로의 **진입부**에 랭크 가드를 뒀다. 자신과 같거나 높은 랭크의 **다른** 계정은 어떤 변경도 불가(D-30 / D-41). 사본 4개의 본문 동일성을 SHA로 검증 |
| **서비스 오버라이드로 전역 관리자 상승** (M-13) | 관리자 API의 랭크 판정은 행위자·대상 모두 **전역 `users.role`만** 본다(D-50) |
| **차단된 회원이 키를 계속 수령** (H-1) | `user_services.status='blocked'`면 `payload`가 빈 배열, 로그인은 `FORBIDDEN`(403). `blocked` 전환 시 그 서비스의 세션을 회수한다(D-31 / D-50) |
| **미가입자가 키를 수령** (M-14) | 활성 `user_services` 행이 있을 때만 시크릿을 담는다. `auto_join=0` 서비스는 로그인은 되지만 `payload`가 `{}`다(D-48) |
| **비밀번호 재설정 링크 탈취** (H-4) | `base_url()`이 `Host` 헤더로 폴백하지 않는다. `BASE_URL`이 비면 **메일 발송 자체를 중단**한다(fail-closed, D-33) |
| **`Host` 헤더 주입 오픈 리다이렉트** (M-2) | `FORCE_HTTPS` 301의 목적지 호스트를 `BASE_URL`에서만 뽑는다. `BASE_URL`이 비면 리다이렉트하지 않고 500으로 멈춘다. `//`로 시작하는 `REQUEST_URI`를 정규화한다(D-53) |
| **`X-Forwarded-Proto` 위조로 HTTPS 강제 우회** (L-8) | `TRUST_PROXY` 상수(기본 `false`). 꺼져 있으면 `X-Forwarded-*`를 무시한다. 켠 경우에도 **`TRUSTED_PROXIES`에 있는 `REMOTE_ADDR`에서 온 요청만** 헤더를 신뢰한다 — 프로토콜 판정(`trust_proxy_headers()`)과 IP 판정(`client_ip()`)이 **같은 조건식**을 쓴다. `TRUST_PROXY=true`인데 `TRUSTED_PROXIES`가 비어 있으면 **설정 오류로 보고 요청을 500으로 멈춘다**(무한 리다이렉트로 원인을 숨기지 않는다). (D-51 / D-53 / D-67) |
| **메일 로그 평문 노출** (H-5) | 다층 방어 — `storage/.htaccess` + `storage/index.html` + 메일 파일 확장자 `.php` + 첫 줄 `<?php exit; ?>` + 파일명 8바이트 난수 + `chmod 0700`/`0600`(D-34) |
| **`core/*.php` 직접 실행** | `core/`에는 `.php` 파일이 **13개** 있다. 그중 `bootstrap.php` **1개**가 `ULOGIN_BOOTSTRAPPED`를 **정의**하고, 나머지 **12개**가 상단에서 그 상수가 없으면 `404`로 즉시 종료한다(**정의 1 + 검사 12 = 13**). `.htaccess` 없이도 막힌다(D-34 / D-43). <br>*파일이 늘거나 줄면 고칠 숫자는 이 셋 전부다 — `grep -l "defined('ULOGIN_BOOTSTRAPPED')" server/core/*.php`로 다시 센다* |
| **오픈 리다이렉트 (SSO)** | `redirect_whitelist` **완전 일치** 검사. 접두사 매칭이 아니다 |
| **CSRF** | 쿠키 세션 경로에 `access_token_hash` 기반 세션 바인딩 CSRF 토큰(D-42) |
| **PHP 7.2에서 쿠키 보호 속성이 조용히 사라짐** | `setcookie()` 옵션 배열은 7.3+ 전용이다. 7.2에서는 그 배열이 만료 인자로 캐스팅되어 `SameSite`가 사라지고 `HttpOnly`·`Secure`도 빠진다. `send_cookie()` 래퍼가 `PHP_VERSION_ID`로 분기해 7.2에서는 `Set-Cookie` 헤더를 직접 조립한다. **두 분기 동등성은 관측이 아니라 불변식이다** — 하한선 채움·`SameSite` 화이트리스트·`path`/`domain` 구분자 검증을 **버전 분기보다 앞**에서 하고, 잘못된 설정에서 양쪽이 같은 지점에서 같은 이유로 실패한다(D-65 / D-66). 감사가 총 50케이스로 재대조해 **운영 경로 25/25 바이트 동일**을 확인했다 (D-59) |
| **`public/` 화면의 클릭재킹 · MIME 스니핑 · 리퍼러 유출 · 스크립트 외부 유출** | `public/.htaccess`가 `X-Frame-Options: DENY` + CSP `frame-ancestors 'none'`(클릭재킹), `X-Content-Type-Options: nosniff`(MIME 스니핑), `Referrer-Policy: strict-origin-when-cross-origin`(URL의 토큰이 교차 출처로 새는 것), `Permissions-Policy`(카메라·마이크·위치 등 미사용 브라우저 기능 차단. 개수는 `grep -o 'Permissions-Policy "[^"]*"' public/.htaccess \| grep -c '=()'` 로 다시 센다), CSP `connect-src 'self'` · `form-action 'none'` · `object-src 'none'`(XSS가 성립해도 데이터를 밖으로 내보낼 통로 차단)을 붙인다. `.html`에는 `Cache-Control: no-store`도 붙는다. <br>**언제 안 막히나 — `.htaccess`가 무시되는 환경(nginx / `AllowOverride None`)에서는 이 8개(보안 헤더 5종 + v1.2.0에서 늘어난 캐시 정책 3종)가 통째로 사라진다.** 개수를 옮겨 적지 말고 다시 세라 — `grep -c 'Header always set' public/.htaccess`(전체) · `grep -c 'Header always set Cache-Control' public/.htaccess`(캐시분). 차가 보안 헤더 수다. `public/*.html` 8개에 meta 대체가 **0건**이고, `frame-ancestors`는 meta로 지정해도 무시되므로 클릭재킹 방어는 **어떤 경우에도 서버 설정이 필요하다.** `mod_headers`가 없으면 500이 아니라 **헤더만 조용히 빠진다.** 대응 스니펫과 상세는 **아래 `### M-4 보충`** (감사 M-4 — 절반 미해결) |
| **SQL 인젝션** | 전 경로 PDO prepared statement. 테이블명은 상수, prefix는 정규식 검증 후 치환 |
| **XSS** | 프론트에 `innerHTML` 계열 0건. `ui.js`가 렌더 시 거부 가드를 둔다 |
| **무차별 대입** | 레이트리밋 + 계정 락아웃(기본 5회/15분). `login:ip`/`login:user` 버킷을 SSO와 공유해 우회 경로로 시도 횟수가 2배가 되지 않게 했다(D-15) |
| **비밀번호에 NUL을 넣어 정책 우회** | `password_hash()`는 bcrypt = C 문자열이라 **첫 NUL에서 절단**된다. 막지 않으면 "8자 이상·2종 이상"을 통과한 비밀번호가 실제로는 앞 3자 해시로 저장되고 그 3자만으로 로그인이 된다(감사 실측). `validate_password()`가 NUL을 포함한 비밀번호를 `VALIDATION_FAILED`(400)로 **거부**한다 — 가입·비밀번호 재설정·비밀번호 변경 **세 저장 경로 전부**가 이 함수를 지난다. 이전의 유일한 방어는 `v_string()`의 `trim()`이 선행·후행 NUL을 지워 주는 **우연**이었다(감사 V11-L-5 / D-66) |
| **설치기 선점** (M-15) | 설치기 1단계 최상단 경고 + 완료 화면 1번 항목 = 삭제. 자기 잠금 3종(`.install.lock` / `storage/.installed` / `config.php`). 잠금 해제 파라미터는 구현하지 않았다(D-52) |
| **설치기 자체 보안** | POST + 1회용 CSRF, DB 정보는 서버 세션에만, 오류 메시지에서 절대경로 제거, 키는 `random_bytes(32)`로 생성해 화면에 표시하지 않음, `config.php` `0600` |

---

## 잘못된 설정은 조용히 넘어가지 않는다 (fail-closed)

**v1.1.0에서 "설정이 틀렸는데 응답은 정상"인 경로가 없어졌다.** 그 자리에서
`SERVER_ERROR`(500)로 멈추고 **서버 오류 로그에 사유를 한 줄 남긴다.**
500 응답 본문에는 원인이 없다 — 내부 정보(SQL·경로·스택)를 노출하지 않도록 그렇게 만들었다.
**원인은 로그에만 있다.** 그것이 이 설계의 요점이므로, 500을 만나면 응답이 아니라 로그를 먼저 열어야 한다.

| 조건 | 결과 | 500이 되는 범위 | 오류 로그 검색어 |
|---|---|---|---|
| `FORCE_HTTPS=true` + **`BASE_URL` 미설정** (비었거나 `__` 자리표시자가 남아 있음) + 비-https 요청 | **500** (D-53. v1.0.0부터) | 서버가 https로 인식하지 못한 **전 요청** | `FORCE_HTTPS is on but BASE_URL is not configured` |
| `TRUST_PROXY=true` + **`TRUSTED_PROXIES`가 빈 목록** + `FORCE_HTTPS=true` + 비-https 요청 | **500** (D-67. v1.1.0 신규) | 서버가 https로 인식하지 못한 **전 요청** | `TRUST_PROXY is on but TRUSTED_PROXIES is empty` |
| 실효 `secure`가 켜진 쿠키를 **평문 요청**에서 발급 | **500** (D-75. v1.1.0 신규) | **쿠키를 발급하는 요청만** | `COOKIE_SECURE is on but the request is not HTTPS` |
| `COOKIE_PATH`·`COOKIE_DOMAIN`에 쿠키 금지 문자 (`,` `;` 공백 탭 CR LF VT FF) | **500** (D-65. v1.1.0 신규) | **쿠키를 발급하는 요청만** | `cookie option rejected` |
| `COOKIE_SAMESITE`가 `Lax`·`Strict`·`None` **밖의 값** | **500이 아니다.** `Lax`로 강제하고 요청은 정상 처리된다 | — (응답은 200) | `invalid COOKIE_SAMESITE` |

> **마지막 행을 500으로 착각하지 마라.** `send_cookie()`는 `COOKIE_PATH`·`COOKIE_DOMAIN`의
> 구분자 검사보다 **먼저** `samesite`를 화이트리스트로 깎는다. 그래서 `COOKIE_SAMESITE`에 무엇을
> 적어도 — 오타(`lax`)든 `'Lax; Domain=evil.example'` 같은 속성 주입 시도든 — 그 값이 구분자
> 검사에 **도달하지 못한다.** 결과는 항상 `SameSite=Lax` + 로그 1줄이고 **응답은 200이다.**
> 적어 둔 값이 쓰이지 않는다는 사실을 로그 말고는 알 방법이 없으므로, `COOKIE_SAMESITE`를 손으로
> 고쳤다면 로그를 한 번 확인하라.

검색어는 `error_log()`가 내보내는 문자열의 **문자 단위 부분문자열**이다. 그대로 복사해 서버 오류
로그를 검색하면 된다. `cookie option rejected`만 앞에 문제 키가 함께 붙어
`invalid "path" cookie option rejected (config error)` 형태로 나가므로, **어느 상수가 문제인지도
같은 줄에 있다**(`"path"` 또는 `"domain"`).

> 4행의 구분자 검사는 **빈 문자열을 검사하지 않는다.** 기본값 `define('COOKIE_DOMAIN', '');`는
> 안전하다(그 경우 `domain` 속성 자체가 붙지 않는다). 값을 채울 때만 이 검사에 걸린다.

### `COOKIE_SECURE=false`인데 500이 나는 두 경로

D-75의 판정 조건은 상수 `COOKIE_SECURE`가 아니라 **그 요청에서 내보내려는 쿠키의 실효 `secure` 값**이다.
정상 경로에서는 둘이 같지만, 아래 두 경우는 `COOKIE_SECURE`가 `false`(또는 아예 없음)여도
실효값이 `true`로 강제되어 **똑같이 500이 난다.**

| 경로 | 왜 |
|---|---|
| **`COOKIE_SAMESITE='None'`** | 브라우저 요구사항이라 `secure`를 자동 강제한다(D-66). 즉 **`'None'`은 HTTPS 전용 값이다** — 평문 HTTP 배포에서는 `define('COOKIE_SAMESITE', 'Lax');`여야 한다 |
| **`COOKIE_SECURE`가 `config.php`에 정의되어 있지 않다** (손으로 만든 구버전 `config.php`) | 속성 하한선이 안전한 쪽인 `true`로 채운다(D-65 / 감사 V11-L-3) |

> **로그 문구는 `COOKIE_SECURE`만 언급한다.** 로그를 보고 `config.php`를 열었는데 `COOKIE_SECURE`가
> 이미 `false`라면 위 두 경로를 보라 — 특히 **`COOKIE_SAMESITE`가 `'None'`인지 먼저 확인하라.**
> 문구는 가장 흔한 원인을 가리키지만 실제 트리거는 실효 `secure` 값이다.

### 증상이 비대칭으로 보인다 — 그것이 진단 단서다

D-75 판정은 `bootstrap.php`가 아니라 **`send_cookie()` 안에** 있다. 전역에서 막으면 쿠키를 전혀
내보내지 않는 요청까지 함께 죽는다. 그래서 **쿠키를 실제로 내보내거나 지우는 요청만** 멈춘다.

**실측:** 모순 설정(`COOKIE_SECURE=true` + 평문 HTTP)에서 `api/**` 32파일 × GET+POST = 64요청 중
5xx는 `GET /api/auth/csrf.php` **1건**뿐이었다. 같은 상태에서 `Authorization: Bearer`로 호출한
`GET /api/me` · `GET /api/me/sessions` · `GET /api/me/points`는 **전부 200**이고
**서버 로그도 0줄**이었다.

> **브라우저에서는 로그인이 안 되는데 Bearer를 쓰는 로컬 AI 앱은 조회가 계속 동작한다** — 이 조합이면
> 쿠키 설정(위 표 3·4행)이다. 반대로 **모든 요청이 500**이면 위 표 1·2행(`BASE_URL` · 프록시)이다.
> 이 비대칭 하나로 원인 범위가 절반으로 줄어든다.

**경계는 인증 방식이 아니라 "그 엔드포인트가 `Set-Cookie`를 내보내는가"다.** Bearer로 호출해도
쿠키를 **삭제**하는 엔드포인트는 500이 된다 — `POST /api/auth/logout` · `POST /api/me/withdraw` ·
`DELETE /api/me/sessions`(**지우는 대상이 현재 세션일 때만**) 세 곳이고, 셋 다 인증 방식과 무관하게
`clear_session_cookie()`를 부른다. 반대로 로컬 앱의 로그인 경로인 `POST /api/auth/exchange`는
**쿠키를 심지 않으므로**(`set_cookie => false`) 영향이 없다.

> 위 세 곳은 **DB 작업을 커밋한 뒤** 마지막에 쿠키를 지우다 500이 된다. 즉 **500이 나도 세션 회수와
> 탈퇴 처리는 이미 완료되어 있다.** 연동 앱이 로그아웃 500을 보고 재시도하지 않도록
> `AI_INTEGRATION_GUIDE.md` 함정 **(47)** 에 같은 사실을 적었다.
>
> **단, 완료되지 않는 것이 하나 있다 — 감사 로그 1행이다.**
> DB 상태 변경(세션 회수·탈퇴)은 완료되지만 **감사 로그 1행이 누락된다.**
> `login_logs`에 `result='logout'`으로 남아야 할 그 1행이다 —
> `log_login()`이 `clear_session_cookie()`의 **다음 줄**에 있어서, 500이 그 앞에서 요청을 끊으면
> 실행되지 않는다: `server/api/auth/logout.php:30-31` · `server/api/me/withdraw.php:113-114` ·
> `server/api/me/sessions.php:88-89` 세 곳 모두 같은 순서다.
> **실측:** 모순 설정에서 500이면 `result='logout'` 행이 **3 → 3**(증가 없음), 정상 설정에서 200이면
> **3 → 4**다(2026-08-26 실측). **보안 영향은 없다** — 회수는 커밋됐고 재시도하면 401이다.
> 영향받는 것은 **운영**이다: `login_logs`로 로그아웃을 추적하면 그 사용자에게
> **로그아웃 기록이 없는 로그인**이 남는다. 이 모순 설정을 고칠 때까지는 세션 종료 여부를
> `login_logs`가 아니라 `sessions` 테이블의 `revoke_reason`으로 확인하라.
> 코드 수정(`log_login()`을 앞으로 이동)은 `## v1.3 검토 목록` **5번**이다.

**HTTP로 운영한다면** `FORCE_HTTPS=false`와 `COOKIE_SECURE=false`를 **함께** 내리고
`COOKIE_SAMESITE`가 `'Lax'`인지 확인하라. 설치기는 두 값을 하나의 라디오로 함께 결정하므로
(D-60) **정상 설치 경로에서는 이 모순이 만들어지지 않는다.** 손으로 `config.php`를 고친 배포에서만 생긴다.

원인별 대응 절차는 `INSTALL.md` **7-21절**(로그 문장별 원인 표)과 **7-7절**(쿠키 500)에 있다.
`INSTALL.md`의 표는 위 4개 500 원인에 **`config.php` 자체가 없을 때**(로그:
`config.php not found. run install.php first`)를 더해 5행이다.

---

## 알려진 제한

**완성된 것처럼 쓰지 않는다.** 아래는 v1.1.0에서 실제로 없거나 불완전한 것들이다.
v1.1.0은 PHP 7.2 호환과 그 라운드의 자체 보안 감사 후속(D-65 ~ D-68, D-75)만 다뤘으므로
**아래 항목은 v1.0.0에서 하나도 줄지 않았다.**

### 기능 미구현 · 미사용

1. **2FA는 컬럼과 응답 필드만 있고 기능이 없다.** `two_factor_enabled`는 항상 `false`이고,
   마이페이지 보안 탭에 "준비 중" 비활성 항목으로 표시된다. (D-2)
2. **서비스별 포인트(`user_services.points`)는 사용하지 않는다.** 전역 포인트만 쓴다.
   컬럼은 유지하되 API·UI에서 노출·차감하지 않는다. `GET /api/me/services` 응답에도 없다. (D-4)
3. **휴면(`dormant`) 자동 전이가 없다.** 관리자가 수동으로만 설정한다.
   로그인 시 `dormant`면 `AUTH_ACCOUNT_DORMANT`(403)로 차단한다. (D-3)
4. **`require_tier()` / `can()` / `require_can()` / `require_level()` 과 `CAN_MAP`은 미사용이다.**
   v1.1.0의 관리자 API는 `require_role`만 쓴다. 그 결과 **`TIER_INSUFFICIENT`는 기본 구성에서 발생하지 않는다.**
   이 API 표면은 서비스 연동 측이 등급 게이팅을 붙일 때 쓰라고 남겨 뒀다. (D-39)
5. **`revoke_reason='expired'`는 미사용 열거값이다.** 그 값을 만드는 코드가 없다. (D-39)
6. **`services.payload_fields`는 저장·반환만 되고 실제 필터링에 쓰이지 않는다.**
   `build_payload()`가 이 컬럼을 읽지 않는다. **동작하지 않는 보안 통제이므로 이것에 기대지 마라.**
   접근 통제는 시크릿의 등급 게이트가 전부다. (보안 감사 M-10 — 미해결)
7. **약관(`terms`)을 등록하기 전에 가입한 회원은 `user_consents` 행이 없다.**
   설치기가 빈 약관을 심지 않기 때문이다. 약관 동의 이력이 필요하면 약관을 먼저 등록해야 한다.

### 데이터 · 감사

8. **하드 삭제 후에도 `point_logs`·`admin_logs`에 삭제된 회원의 내부 ID가 남는다.**
   두 테이블의 `user_id`·`admin_id`가 `NOT NULL`이라 익명화할 수 없다. `login_logs`만 익명화된다.
   로그를 지우면 감사 추적이 끊기므로 행을 보존하고 개수만 보고하는 쪽을 택했다. (D-19)
9. **`reset-password`가 임시 비밀번호를 평문으로 반환한다.** 기본 메일 드라이버가 `log`이므로
   마스킹하면 계정 복구가 불가능해진다. UI는 "다시 볼 수 없습니다" 경고와 함께 1회만 표시하고
   `admin_logs`에는 남기지 않는다. (D-21)
10. **`recent_points[].admin_id`가 내부 ID가 아니라 조작자 uuid 문자열이다.**
    `users.id` 비노출 규칙을 우선한 결과다. (D-22)

### 배포 · 구조 제약

11. **`ULOGIN_API_BASE`가 상대 경로(`'../server'`)이고, 그 전제는 "화면은 `public/` 직하"다.**
    `public/` 아래에 하위 디렉토리를 만들어 화면을 추가하면 API 호출이 전부 어긋난다.
    변형 배치를 하려면 `public/assets/api.js` 40행을 직접 고쳐야 한다. (D-28)
12. **`.htaccess`가 무시되는 환경(nginx, `AllowOverride None`)에서는 `storage/` · `schema.sql` ·
    `config.php`가 보호되지 않고, `public/`의 보안 헤더도 통째로 사라진다.**
    `core/*.php`는 코드 가드가 있어 안전하지만 나머지는 웹서버 설정에 의존한다.
    `INSTALL.md` 7-9절의 nginx deny 스니펫(`server/`쪽)과
    아래 **`### M-4 보충`의 헤더 스니펫(`public/`쪽)을 둘 다** 적용해야 한다. 한쪽만으로는 부족하다.
13. **`python -m http.server`는 PHP를 실행하지 못한다.** 경로·화면 확인용으로만 쓸 수 있다.
    API까지 돌리려면 `php -S` 같은 PHP 가능한 서버가 필요하다.
14. **`service_id`는 생성 후 변경할 수 없다.** 세션·시크릿·가입 매핑·로그가 문자열 키로 참조한다.
    새로 만들고 기존 것을 `disabled`로 내려야 한다.
15. **`BASE_URL`은 사실상 필수 설정이다.** 비어 있으면 (a) 메일 발송이 전면 중단되고(D-33)
    (b) HTTPS 강제 리다이렉트가 500으로 멈춘다(D-53).
    **(a)는 응답이 "보냈습니다"라서 조용히 실패하고, (b)는 응답 본문에 원인이 없어서 조용해 보인다** —
    (b)의 사유는 서버 오류 로그에 남는다(`FORCE_HTTPS is on but BASE_URL is not configured`).
    설정 오류로 500이 나는 경로 전체는 위 `## 잘못된 설정은 조용히 넘어가지 않는다 (fail-closed)`에 있다.
16. **`auto_join=0` 서비스는 가입 절차를 별도로 거쳐야 `payload`가 나온다.**
    로그인은 200으로 성공하는데 `payload`만 `{}`라서 **무증상 실패로 보인다.** (D-48 / QA Q-15)
17. **게이트 강제는 생성·수정 시점만 막는다.** 그 이전에 만들어진 게이트 없는 시크릿은 그대로 동작한다.
    정기 점검이 필요하다. 또한 이름 기반 판정이라 `openai_org` 같은 이름은 민감 키로 잡히지 않는다.

### 기술 부채 (다음 버전 정리 과제)

기능·표면이 새로 필요한 항목은 이 절이 아니라 아래 **`## v1.3 검토 목록`**에 있다.

18. **관리자 헬퍼 10개가 `function_exists` 가드로 `api/admin/` 10개 파일에 중복 선언되어 있다** (31 사본).
    요청당 admin 파일 1개만 로드되므로 실제 충돌은 없고 본문 SHA 동일성은 검증되었다.
    `core/admin_util.php` 추출은 다음 버전 과제다. (D-20)
19. **민감 키 판정 정규식이 `server/install.php`와 `server/api/admin/secrets.php` 두 곳에 복제되어 있다.**
    `install.php`가 `core/`를 로드하지 않아 공용 파일로 뺄 수 없었다. 현재 바이트 동일이지만
    한쪽만 고치면 갈라진다.

### 남아 있는 보안 항목 (배포 차단은 아님)

보안 감사 2회차에서 **Medium·Low로 분류되어 v1.0.0에서 처리하지 않았고, v1.1.0에서도 그대로 남은 것들**이다.
배포를 막지는 않지만 알고 운영해야 한다.

| # | 항목 | 영향 |
|---|---|---|
| M-1 | `sso/authorize`가 요청 `redirect_uri`의 쿼리스트링을 보존해 `code`를 뒤에 붙인다 | 공격자 `code` 주입(로그인 CSRF) 가능성 |
| M-4 | `public/.htaccess`의 보안 헤더가 **`.htaccess`가 무시되는 환경에서는 통째로 사라진다**(대체 수단 없음). 토큰이 URL 쿼리로 유통되는 경로가 남아 있다 | nginx·`AllowOverride None`에서 CSP·프레임 보호가 전부 없어진다. 재설정·인증 토큰이 웹서버 액세스 로그에 남는다 — 헤더로는 막을 수 없다. **아래 보충 참조** |
| M-5 | 계정 열거 — 응답 시간 차이 및 `DUPLICATE_EMAIL` | 가입된 이메일 존재 여부를 알아낼 수 있다 |
| M-6 | 락아웃·레이트리밋 남용으로 타인의 로그인을 차단할 수 있다 | 서비스 거부 |
| M-7 | PKCE가 강제되지 않고 `file://`에서는 클라이언트가 조용히 생략한다. 난수 폴백이 `Math.random()` | 보안 컨텍스트가 아닌 환경에서 code 가로채기 방어가 약해진다 |
| M-8 | `rate_limit()`이 저장소 장애 시 fail-open 한다 | DB 장애 시 레이트리밋이 통째로 열린다 |
| M-9 | access token TTL을 설정에서 최대 7일까지 올릴 수 있다 | 스펙의 "≤1시간" 의도와 어긋난다. 기본값은 1시간이다 |
| M-11 | 비밀번호 재확인 경로에 전용 레이트리밋이 없다 | 비밀번호 추측 시도를 늦추지 못한다 |
| L-1 | `login` / `register`에 CSRF 보호가 없다 | 강제 로그인(login CSRF) |
| L-3 | `login:user` 버킷이 username / email로 갈려 같은 계정에 2배 시도가 가능하다 | 락아웃 임계가 실질 2배 |
| L-4 | `require_csrf()`에 이중 제출 쿠키 폴백이 있다 | 세션 바인딩보다 약한 경로가 남아 있다 |
| L-5 | `enc()`/`dec()`에 무결성 검증(MAC)이 없다 | 암호문 변조를 탐지하지 못한다 |
| L-6 | `allowed_origins`에 문자열 `'null'`을 등록할 수 있다 | `file://` 페이지에서 API 호출이 가능해진다 (경고는 표시된다) |

> **v1.2.0 감사는 이 목록을 늘리지도 줄이지도 않았다.** `server/**`와 `public/assets/**`가 v1.1.0과
> 바이트 동일하므로 근거 코드가 변하지 않았다. v1.2.0 신규 3건(V12-M-1 · V12-L-1 · V12-L-2)은
> **전부 문서 항목이고 같은 라운드에서 닫혔다.**
>
> **다만 `M-7`에 배포 지연 위험이 새로 붙었다.** `M-7`의 대상 파일은
> `public/assets/ulogin-client.js`이고, v1.2.0이 그 파일에 `immutable, max-age=31536000`을 걸었다.
> **`?v=` 없이 그 파일을 로드한 연동 앱에서는 `M-7`을 고쳐도 최대 1년간 도달하지 않는다** —
> URL이 같으니 캐시를 깰 수단이 없고, 이미 나간 `immutable` 응답을 서버가 철회할 방법도 없다.
> 그래서 5개 문서의 모든 `<script src>` 예시에 `?v=` 를 붙였고
> (`INSTALL.md` 5-4절이 이 라운드에 정정됐다 — 감사 `V12-M-1`),
> `INSTALL.md` 8-1절이 **"새 버전을 올릴 때 연동 앱 개발자에게 `?v=` 값을 알려라"** 를 절차로
> 못박았다. **연동 앱의 HTML은 릴리스 스크립트의 사정권 밖이다.**
>
> **`M-4`의 "대체 수단 없음"은 v1.2.0에서 좁아졌다** — `INSTALL.md` 7-9절이 nginx·Apache
> 대체 스니펫(보안 5종 + 캐시 3종 + `examples/`)을 제공한다. **문서가 설정을 적용해 주지는
> 않으므로 스니펫을 넣지 않으면 구멍은 그대로다.** 아래 보충 참조.

### M-4 보충 — `public/.htaccess`는 **있다.** 없는 것은 그 대체 수단이다

보안 감사 2회차의 M-4는 "`public/`에 보안 헤더 `.htaccess`가 없다"였다.
**그 절반은 v1.0.0의 마무리 수정 라운드(fix3)에서 `public/.htaccess`를 신설해 대응됐다.**
감사 문서는 그 이전 시점 기준이라 "없다"로 남아 있었다. v1.1.0에서 이 서술을 사실에 맞게 고쳤다.
아래가 현재 상태다 — 무엇이 되고 무엇이 안 되는지 그대로 적는다.

**되는 것 — `public/.htaccess`가 붙이는 것 (파일: `public/.htaccess`)**

| 항목 | 값 |
|---|---|
| `Content-Security-Policy` | `default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; frame-src 'none'; worker-src 'none'; manifest-src 'none'; base-uri 'self'; form-action 'none'; frame-ancestors 'none'` |
| `X-Frame-Options` | `DENY` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` — 교차 출처에는 **출처만** 나가므로 토큰이 실린 경로·쿼리가 밖으로 새지 않는다. `server/.htaccess`의 `no-referrer`와 값이 다른 것은 **의도**다(문서 없는 `Origin: null` 회색지대를 만들지 않기 위함) |
| `Permissions-Policy` | 미사용 브라우저 기능 `=()`. 다시 세는 명령: `grep -o 'Permissions-Policy "[^"]*"' public/.htaccess \| grep -c '=()'` (2026-08-28 실측 **14**) |
| `X-Content-Type-Options` | `nosniff` |
| `Cache-Control` | **v1.2.0에서 3종으로 늘었다.** `.html` → `no-store` / `assets/*.{css,js}` → `public, max-age=31536000, immutable` / `version.txt` → `no-store`. 자산을 오래 캐시해도 안전한 이유는 참조 URL에 `?v={버전}`이 붙어 있기 때문이다 (`## 배포 반영과 캐시 무효화`) |

부수적으로 `Options -Indexes` · `DirectoryIndex index.html` · `^\.` 파일 차단(=이 `.htaccess` 자신)도 걸린다.
헤더는 전부 `<IfModule mod_headers.c>` 안에 있다 — `mod_headers`가 없으면 500이 아니라 **헤더만 조용히 빠진다.**

`reset.html`·`verify.html`은 로드 직후 `history.replaceState`로 주소창에서 `token`을 지운다
(`reset.html:115` / `verify.html:99`). 브라우저 히스토리·북마크·화면 공유 노출은 이것으로 막힌다.

**안 되는 것 — 이것이 M-4의 남은 절반이다**

1. **`.htaccess`가 무시되는 환경에서는 위 표의 헤더가 통째로 사라지고, 대체 수단이 없다.**
   지시자 개수로는 **8개**다 — 보안 헤더 **5종** + v1.2.0에서 늘어난 캐시 정책 **3종**.
   다시 세는 명령은 `grep -c 'Header always set' public/.htaccess`(전체)와
   `grep -c 'Header always set Cache-Control' public/.htaccess`(캐시분)이고 차가 보안 헤더 수다.
   nginx · LiteSpeed 비호환 모드 · Apache `AllowOverride None`이 그 환경이다.
   `public/*.html` 8개에 `<meta http-equiv="Content-Security-Policy">`가 **0건**이고
   `<meta name="referrer">`도 **0건**이다(grep 확인). 그래서 헤더가 빠지면 정책도 함께 사라진다.
   HTML 8개에 한 번에 meta를 넣는 것은 v1.0.1 과제다 — 8개 중 일부만 넣으면 두 곳이 갈라진다.
   그리고 **`frame-ancestors`는 meta로는 무시되므로** 클릭재킹 방어는 어떤 경우에도
   `X-Frame-Options` 헤더, 즉 웹서버 설정이 필요하다.
2. **토큰이 URL 쿼리로 최초 1회 전달되는 것 자체는 막지 못한다.**
   `reset.html?token={64hex}` · `verify.html?token={64hex}`의 그 GET 요청 라인은
   **웹서버 액세스 로그에 그대로 남는다.** `replaceState`는 요청이 끝난 뒤에 동작하고,
   `Referrer-Policy`는 외부 전송만 막는다. 어떤 헤더로도 해결되지 않는 구조적 잔여 위험이다.
   호스팅의 액세스 로그 보존 기간과 접근 권한을 확인하라.
3. **CSP `script-src`에 `'unsafe-inline'`이 들어 있다.** 화면 8개 중 **7개**가 화면 로직을
   인라인 `<script>` 블록으로 갖고 있다(`index.html`만 스크립트가 없다). 빌드 도구가 없는
   배포 모델이라 sha256 해시는 HTML을 한 글자 고치면 전 화면이 조용히 죽고, 정적 파일이라
   nonce는 매 요청 갱신이 불가능하다. **그래서 받아들인 타협이다.**
   그럼에도 이 정책이 여전히 막는 것: 외부 도메인 스크립트 로드, `eval`(`'unsafe-eval'` 없음),
   `<object>`/`<embed>`, `<base>` 하이재킹, iframe 삽입, 그리고 **`connect-src 'self'` —
   XSS가 성립해도 훔친 데이터를 밖으로 보낼 통로가 없다.** 관리자 콘솔이 공격자가 심은
   닉네임·메모를 관리자가 열어보는 화면이므로 이 한 줄이 실질 방어의 핵심이다.
   인라인 블록을 `assets/*.js`로 빼서 `'unsafe-inline'`을 지우는 것은 v1.0.1 과제다.
4. ~~**`.htaccess`를 실제 Apache로 파싱해 보지 못했다.**~~ → **v1.2.0 검증 라운드에 해소됐다.**
   QA가 실 **Apache 2.4.58 + `mod_headers`** 로 이 파일을 파싱시켜 문법 오류 없이 읽히는 것과
   `public/login.html` 응답에 **보안 5종 + 캐시 3종이 함께** 나가는 것을 실측했다
   (`_workspace/25_qa_v1.2.md` E14·E15). **남는 것은 실 공용 호스팅이다** — 그 서버의 Apache
   버전·`AllowOverride` 값·`mod_headers` 유무·제공자 단 프록시는 확인할 수 없다
   (`## 미검증 항목` 3번). **nginx·LiteSpeed는 여전히 미검증이다**(같은 목록 11번) —
   Apache가 됐다는 것이 nginx가 된다는 뜻이 아니다.

**배포 후 30초 확인 (반드시 하라)**

브라우저 개발자도구 → Network → `public/login.html` 요청 → Response Headers에
`Content-Security-Policy`가 있는지 본다. 없으면 `.htaccess`가 무시되는 환경이므로 아래를 적용해야 한다.
(`login.html`이 200이 아니라 **500**이면 `.htaccess` 문법 오류다.)

**nginx — `public/`용 헤더 설정 (`public/.htaccess` 대체)**

`server/`쪽 deny 규칙은 `INSTALL.md` 7-9절에 있다. **이것은 그것과 별개로 추가해야 하는 화면용 헤더다.**
`/ulogin`은 도메인 루트 기준 설치 경로다. 루트에 설치했다면 지운다.

```nginx
location ^~ /ulogin/public/ {
    autoindex off;

    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "DENY" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Permissions-Policy "accelerometer=(), autoplay=(), camera=(), display-capture=(), encrypted-media=(), fullscreen=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(), usb=()" always;
    add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; frame-src 'none'; worker-src 'none'; manifest-src 'none'; base-uri 'self'; form-action 'none'; frame-ancestors 'none'" always;

    # .html 만 캐시 금지. assets/*.{css,js} 는 이 블록에 들어오지 않으므로 평소대로 캐시된다.
    location ~ \.html$ {
        # nginx 의 add_header 는 상속되지 않는다 — 하위 블록에 add_header 가
        # 하나라도 있으면 상위 블록의 것을 전부 버린다. 그래서 위 5줄을 여기에 복사한다.
        # 지울 수 있는 줄이 아니다. 지우면 .html 응답에서 CSP 가 사라진다.
        add_header X-Content-Type-Options "nosniff" always;
        add_header X-Frame-Options "DENY" always;
        add_header Referrer-Policy "strict-origin-when-cross-origin" always;
        add_header Permissions-Policy "accelerometer=(), autoplay=(), camera=(), display-capture=(), encrypted-media=(), fullscreen=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(), usb=()" always;
        add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; frame-src 'none'; worker-src 'none'; manifest-src 'none'; base-uri 'self'; form-action 'none'; frame-ancestors 'none'" always;
        add_header Cache-Control "no-store" always;
    }
}
```

```bash
sudo nginx -t && sudo systemctl reload nginx
```

**Apache인데 `AllowOverride None`이라면** — 가상호스트 설정에 넣는다. Apache는 `Header`가 하위 컨텍스트로
상속되므로 nginx처럼 복사할 필요가 없다.

```apache
<Directory "/var/www/html/ulogin/public">
    Options -Indexes
    DirectoryIndex index.html
    <IfModule mod_headers.c>
        Header always set X-Content-Type-Options "nosniff"
        Header always set X-Frame-Options "DENY"
        Header always set Referrer-Policy "strict-origin-when-cross-origin"
        Header always set Permissions-Policy "accelerometer=(), autoplay=(), camera=(), display-capture=(), encrypted-media=(), fullscreen=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(), usb=()"
        Header always set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; frame-src 'none'; worker-src 'none'; manifest-src 'none'; base-uri 'self'; form-action 'none'; frame-ancestors 'none'"
    </IfModule>
    <FilesMatch "\.html$">
        <IfModule mod_headers.c>
            Header always set Cache-Control "no-store"
        </IfModule>
    </FilesMatch>
</Directory>
```

> **이 스니펫에는 v1.2.0의 캐시 규칙 3종이 빠져 있다 — 보안 헤더만 다룬다.**
> 보안 헤더 5종과 캐시 3종을 **합본**으로 담은 스니펫이 `INSTALL.md` 7-9절
> `#### 캐시 헤더 3종`에 있다. `location`이 같으므로 **둘 다 넣지 말고 합본 하나만 넣어라.**
> nginx의 **`add_header`는 상속되지 않는다** — 하위 `location`에 캐시 헤더를 넣는 순간
> 그 응답에서 보안 헤더 5종이 **조용히 사라진다** — 합본이 그 함정을 처리해 둔 형태다.
>
> **이 두 스니펫은 `public/`만 덮는다. `examples/`는 별도다.**
> `examples/`에는 보안 헤더를 넣지 않고 **HTML `no-store`만** 넣는다 — 데모 두 개가
> `<style>` 블록과 교차 출처 `fetch`를 쓰기 때문에 `public/`의 CSP 값을 그대로 걸면 깨진다
> (`{설치루트}/examples/.htaccess` 머리 주석에 판단 근거가 있다).
> `examples/`용 블록도 **`INSTALL.md` 7-9절 `#### 캐시 헤더 3종`의 합본에 함께 들어 있다.**
>
> **값을 손으로 옮겨 적지 마라.** 위 두 스니펫의 정본은 `public/.htaccess`의 `Header always set` 줄이다.
> 그 파일을 고치면 여기도 함께 고쳐야 한다.
> **검증 상태는 두 스니펫이 다르다** — **Apache `<Directory>` 스니펫은 v1.2.0 검증 라운드에
> QA가 `AllowOverride None` 환경에 실제로 적용해 확인했다**(`httpd -t` Syntax OK + 기대값 표 충족.
> `_workspace/25_qa_v1.2.md` F4). **nginx 스니펫은 여전히 미검증이다** — 이 환경에 nginx가 없어
> `nginx -t`조차 돌리지 못했다(`## 미검증 항목` 11번). 어느 쪽이든 적용 후 위의
> "배포 후 30초 확인"을 다시 하라.
>
> **`ULOGIN_API_BASE`를 다른 도메인의 절대 URL로 바꾸면 `connect-src`에 그 출처를 추가해야 한다.**
> 안 하면 전 화면의 API 호출이 콘솔에 CSP 위반만 남기고 실패한다(`## 알려진 제한` 11번).

---

## v1.3 검토 목록

> **이 절은 v1.1.0 라운드에서 `## v1.2 검토 목록`이라는 이름으로 만들어졌다.**
> **v1.2.0은 이 목록의 1~7번을 하나도 처리하지 않았다** — 그 라운드는 배포 반영·캐시 무효화만 다뤘다
> (`## 배포 반영과 캐시 무효화`). 그래서 이름만 v1.3으로 옮겼고 **내용은 그대로다.**
> `CHANGELOG.md`의 v1.1.0 절과 `INSTALL.md` `## 변경 이력`의 2026-08-26 행들이
> 옛 이름(`## v1.2 검토 목록`)으로 이 절을 가리킨다 — **같은 절이다.** 그 기록은 당시 판정을
> 그대로 보존하기 위해 고치지 않았다.
> **8번은 v1.2.0 구현 라운드에서, 9·10번은 v1.2.0 검증 라운드에서 새로 관측된 항목이다**
> (9번 = 보안 감사 §B-4 / 10번 = `examples/.htaccess` 신설 과정의 실측).

**여기 있는 것은 v1.1.0에도 v1.2.0에도 들어 있지 않다.** 다음 버전에서 검토하기로 리더가 판정한 항목이다.
`## 알려진 제한`의 `### 기술 부채`가 "코드 정리 과제"라면, 이 목록은 **기능·표면이 새로 필요한 것**이다.
**한 커밋 안에서 여러 곳을 함께 고쳐야 해서 이번 라운드에 넣을 수 없었던 문서 항목도 여기에 둔다**(4번).
**코드 변경은 두 줄의 순서를 바꾸는 것뿐이지만 정상 경로의 동작까지 함께 바뀌어 리더 판정이 필요한 항목도 둔다**(5번).
**규약(D-61)이나 문서 소유권 때문에 이번 라운드에 손댈 수 없었던 표기 항목도 둔다**(6·7번).

| # | 항목 | 왜 필요한가 | 판정 |
|---|---|---|---|
| 1 | **관리자 콘솔이 실행 중인 PHP 버전과 EOL 상태를 표시한다** (`PHP_VERSION_ID < 70400`이면 경고). 구현 규모는 `GET /api/admin/stats` 응답에 `php_version` 문자열 + `php_eol` 불리언 1쌍을 추가하고 대시보드 탭이 그것을 렌더하는 것 | **설치기의 7.2 경고는 `install.php`를 삭제하면 사라진다.** 그 경고는 1단계 정보성 표와 완료 화면 두 곳에 있고, 완료 화면은 설치자가 딱 한 번 본다. 2-9절대로 `install.php`를 지우면 **그 뒤로 이 사실을 서버 어디에서도 다시 볼 수 없다.** 시스템을 나중에 인수하는 운영자는 자기가 EOL 인터프리터 위에서 AI 키를 다루고 있다는 것을 모른 채 운영하게 된다. `README.md`가 남지만, 문서는 배포 폴더와 함께 유실되기 쉽고 콘솔은 매일 열린다 | **v1.2로 이월** (감사 §C-4 조건 2 / 리더 판정 D-69(b)). v1.1.0 범위를 넘고 **콘솔·API 양쪽 변경**이 필요해서다 |
| 2 | `public/*.html` 8개의 인라인 `<script>`를 `assets/*.js`로 빼서 CSP의 `script-src 'unsafe-inline'`을 지운다 | 8개 중 7개가 인라인 스크립트를 갖고 있어 현재는 `'unsafe-inline'`이 필수다. 빌드 도구가 없어 sha256·nonce 어느 쪽도 쓸 수 없다 (`### M-4 보충` ③) | v1.0.1 과제로 이미 등록돼 있다 |
| 3 | `.htaccess` 무시 환경용 meta 대체 헤더를 `public/*.html` **8개에 동시에** 넣는다 | 일부만 넣으면 화면마다 방어 수준이 갈라진다. `frame-ancestors`는 meta로 무시되므로 완전 대체는 불가하고 **부분 완화**다 (`### M-4 보충` ①) | v1.0.1 과제로 이미 등록돼 있다 |
| 4 | **`INSTALL.md` 7-7절 제목(`… / 로그인만 500이다`)을 절 내용에 맞게 넓힌다** — 그 절은 이제 `logout`·`withdraw`·현재 세션 `DELETE`의 500까지 다룬다(`#### 경계는 인증 방식이 아니라 Set-Cookie 발행 여부다` 소절) | 제목이 절 내용보다 좁다. 목차에서 제목만 보고 "로그아웃 500은 여기가 아니다"라고 판단해 절을 건너뛸 수 있다 | **v1.2로 이월.** 제목을 바꾸면 GitHub 앵커 슬러그가 바뀌어 **`INSTALL.md` 내부 참조 5곳**(본문 링크 5곳 + 헤딩 자신 = 총 6곳)이 함께 깨진다 — **제목 변경과 그 6곳 갱신을 한 커밋에서 함께** 해야 한다. **줄 번호를 적지 않는다**(문서가 편집되면 즉시 낡는다 — 실제로 이 행이 적어 두었던 줄 번호 4개가 한 라운드 뒤에 낡았다). 착수 시 아래로 현재 위치를 다시 뽑아라: `grep -n '7-7-쿠키가-저장되지-않는다' INSTALL.md` (본문 링크 5행) · `grep -n '^### 7-7\.' INSTALL.md` (헤딩) v1.1.0에서는 앵커 무변경이 지시 조건이었다 (`_workspace/18c` `## 고치지 못한 것` 1번) |
| 5 | **`log_login('logout', …)`을 `clear_session_cookie()` 앞으로 옮긴다** — 파일 3곳에서 인접한 두 줄의 순서를 바꾼다: `server/api/auth/logout.php:30-31` · `server/api/me/withdraw.php:113-114` · `server/api/me/sessions.php:88-89` | 지금은 D-75의 500이 나면 **DB 상태 변경(세션 회수·탈퇴)은 완료되지만 감사 로그 1행이 누락된다** — `login_logs`의 `result='logout'` 행이다(실측: 500이면 3→3, 200이면 3→4). 운영자가 `login_logs`로 추적하면 **로그아웃 기록이 없는 로그인**이 남는다. `### 증상이 비대칭으로 보인다` 절에 이 예외를 서술해 두었으나 **서술이 예외 자체를 제거하지는 못한다** | **v1.2로 이월.** ① 이 500은 **잘못된 설정에서만** 발생하므로 배포를 막지 않는다(정상 설치 경로에서는 D-60이 모순을 만들지 않는다). ② 순서를 바꾸면 **정상 경로(200)의 기록 시점도 함께 바뀐다** — 쿠키 삭제 전 기록이 되므로 3파일 동시 변경 + 회귀 확인이 필요하다. 순서 변경이 정상 경로에 미치는 영향까지 포함해 **리더 판정 대상**이다 (`_workspace/19_qa_v1.1_report.md` `Q11-2`) |
| 6 | **`AI_INTEGRATION_GUIDE.md` 5절 제목의 `(45개)`를 `(45행 = 43조합)`으로 바꾼다** — 5-6절 표 제목 `(45개 한눈에)`도 함께 | 45와 43은 **계수 기준이 다른 두 숫자**다. **43** = `server/api/**`의 `only_method()`가 허용하는 메서드 총합(메서드 × 경로 조합), **45** = 5-6절 요약표의 **행 수** — 쿼리 파라미터로 동작이 갈리는 2건(`admin/secrets.php` GET `action=reveal` · `admin/logs.php` GET `type=admin`)을 별행으로 센 것이다. `45 − 2 = 43`. 제목의 `(45개)`는 기준을 적지 않아 "엔드포인트가 45개"로 읽힌다 | **v1.2로 이월 — 이월 사유는 D-61이다.** 5·6·10절 **본문 불변**이 v1.1.0 문서 라운드의 지시 조건이어서 제목을 건드릴 수 없었다. 대신 절 밖(`## API 요약`의 인용 블록 · AI 가이드 `## 이 문서의 버전`)에 계수 기준을 명시했다. **5절을 여는 라운드가 오면 함께 바꾼다** (D-80 후속) |
| 7 | **`_workspace/01_architect_api_contract.md:51`의 개수 라벨을 정정한다** — 정정 문안은 **"요약표 45행 = 43개 메서드×경로 조합"** | 계약서가 **"메서드×실제 파일 45개"** 라고 적는다. 45는 **요약표 행 수** 기준인데 "메서드×실제 파일"은 **조합** 기준의 이름이라 수치와 라벨이 어긋난다(6번과 같은 원인). 같은 줄의 `논리 엔드포인트 47개`는 기준을 라벨에 적고 있으므로 **고칠 곳은 45의 라벨 한 군데뿐**이다 | **다음 버전 architect의 계약서 갱신 과제** (D-81). 이월 사유: 계약서를 열면 **D-23 동기화 검증**(엔드포인트·필드·에러코드 **차집합 0**)을 다시 돌려야 하고, 라벨 한 줄 때문에 그 비용을 쓸 이유가 없다. 산출물 문서 4개는 계수 기준을 이미 명시했으므로 **배포 영향 0**이다 |
| 8 | **`core/bootstrap.php`의 `FORCE_HTTPS` 301 리다이렉트와 OPTIONS 204 응답에 캐시 헤더가 없다** — `core/bootstrap.php:262`(301) · `core/bootstrap.php:273`(204) | **301은 브라우저가 사실상 영구 캐시한다.** v1.2.0에서 `core/response.php`의 JSON 응답 전 경로에 `no-store, no-cache, must-revalidate, max-age=0`을 넣었지만 이 두 경로는 `response.php`를 지나지 않는다 | **판정: 조치 권고 없음(기록만 한다).** 301의 대상은 같은 경로의 `https://` 뿐이고 사용자별로 다르지 않아 캐시돼도 잘못된 곳으로 가지 않는다. 204는 `Access-Control-Max-Age: 600`으로 **의도적** 캐시이며 `Vary: Origin`(`core/request.php:373`)이 오염을 막는다. **시크릿·사용자 데이터를 싣는 응답은 전부 `response.php`를 지난다.** 다만 `core/` 소유자가 그 파일을 여는 라운드가 오면 함께 판단할 사안이라 남긴다 (`_workspace/22b_cachebust_backend_report.md` `## 이 파일 밖의 응답 경로`) |
| 9 | **`X-Powered-By` 응답 헤더를 제거한다** — `server/core/bootstrap.php`에 `header_remove('X-Powered-By');` 한 줄. 확인: `grep -rn "header_remove\|expose_php" server/` 가 **현재 0건**이다(2026-08-28 실측) | **이 시스템에서 가장 큰 버전 노출 경로다.** 공용 호스팅의 PHP 기본값은 `expose_php=On`이고, 그러면 모든 응답에 `X-Powered-By: PHP/7.2.34` 가 붙어 **PHP 마이너 버전**이 익명에게 나간다. 앱 버전(`?v=1.2.0` · `version.txt`)보다 공격에 직접 쓰인다 — **7.2는 보안 지원이 끝난 버전이다**(`## 알려진 제한`). 보안 감사가 `public/version.txt` 노출 판정에서 "version.txt보다 우선순위가 높은 대응"으로 지목했다(`_workspace/24_security_v1.2.md` §B-4 / 리더 판정 D-99(3)) | **v1.3으로 이월. 권고이며 배포 차단이 아니다.** 이월 사유: ① **v1.2.0 라운드의 범위는 캐시 무효화다.** 이 라운드는 `server/core/response.php`의 헤더 3줄만 건드리고 `server/api/**` 32파일과 `core/` 나머지를 v1.1.0과 **바이트 동일**하게 유지하는 것이 요구 조건이었다(`## 배포 반영과 캐시 무효화`). ② **응답 헤더를 지우는 변경은 별도 회귀 확인이 필요하다** — `bootstrap.php`는 모든 요청이 지나는 단일 관문이고, 301·204·JSON·설치기까지 출력 경로가 4갈래다. 헤더 하나를 지우는 것이라도 그 4갈래를 다시 실측해야 하며 이 라운드의 검증 범위(캐시 헤더) 밖이다. ③ 비용은 사실상 0이므로 다음 라운드에서 `bootstrap.php`를 여는 어떤 작업과도 함께 처리할 수 있다 |
| 10 | **`examples/` 전용 CSP 설계** — `examples/.htaccess`에는 캐시 정책만 있고 **보안 헤더 5종이 없다.** `public/`의 값을 그대로 옮길 수 없다는 것이 v1.2.0 검증 라운드에 **실측으로 확정됐다** | `public/`의 CSP를 그대로 걸면 두 데모가 깨진다. 실측(`_workspace/26a_fix_examples_report.md`): `style-src 'self'`를 걸면 `local-ai-app.html`의 `document.styleSheets.length`가 **0**이 되고(데모 둘은 스타일 전체를 `<style>` 블록으로 갖는다. `public/*.html` 8개는 `<style>` 0건이라 그 값이 성립하는 것이다), `connect-src 'self'`는 사용자가 입력한 AI 제공자 주소로의 교차 출처 `fetch` — **데모의 본체** — 를 네트워크에 닿기 전에 막는다. <br>**지금 열려 있는 것은 XSS가 성립했을 때의 유출 통로(`connect-src`)다.** `payload`(AI 키) 자체는 이 HTML이 아니라 브라우저 메모리·로컬 저장소에 있고 그 경로를 좁히는 것은 CSP가 아니라 `ulogin-client.js`의 저장 정책이므로, **CSP 부재가 키 노출 경로를 새로 여는 것은 아니다** | **v1.3으로 이월.** 이월 사유: 데모에 맞는 값을 만드는 것은 **새 정책을 설계하는 일**이고 **별도 보안 감사가 필요하다.** v1.2.0 라운드의 범위는 캐시이고, 프론트가 단독으로 결정할 사안이 아니라고 판단해 `examples/.htaccess` 머리 주석에 판단 근거를 남긴 채 넘겼다. **조용히 생략한 것이 아니다** |

코드 정리 과제(중복 헬퍼 추출, 정규식 복제 해소 등)는 `CHANGELOG.md` `## 다음 버전 예정`에 있다.

---

## 미검증 항목

**이 목록은 정직하게 적는다.** 아래는 v1.2.0 릴리스 시점에 **실제로 확인하지 못한** 것들이다.
배포 담당은 실환경에서 반드시 직접 확인해야 한다.
v1.0.0의 9개는 **항목 번호를 그대로 유지하고**, v1.1.0에서 **10번**, v1.2.0에서 **11번이 추가됐다.**
**v1.2.0 검증 라운드에서 3번과 11번이 부분 검증됐다** — QA가 실 Apache 2.4.58 + `mod_headers`로
`public/.htaccess` 파싱(보안 5종 + 캐시 3종)과 `AllowOverride None` 스니펫 적용을 확인했다.
**둘 다 항목을 지우지 않았다. 3번에는 실 공용 호스팅이, 11번에는 nginx·LiteSpeed가 그대로 남는다** —
`nginx`가 이 개발 환경에 없어 `nginx -t`조차 돌리지 못했다. **Apache가 됐다는 것이 nginx가 된다는 뜻이 아니다.**
`7번`은 v1.1.0 라운드에 QA가 검증을 완주해 상태가 갱신됐다(같은 이유로 항목을 지우지 않았다 — 실 호스팅 몫이 남는다).
`CHANGELOG.md` `### 남은 미검증 항목`이 "v1.0.0의 9건은 그대로 유효"라고 적는 것은 **이 갱신 이전 기준**이다.
번호는 달라지지 않았고 **7번의 상태만** 이 표에서 바뀌었다.

| # | 항목 | 왜 확인하지 못했나 | 배포 시 무엇을 하면 되나 |
|---|---|---|---|
| 1 | **배포 호스팅의 MySQL에서의 API 왕복 e2e** | v1.0.0 시점에는 MySQL 인스턴스가 없어 DDL 15/15만 확인하고 나머지는 SQLite 하네스로 했다. **v1.1.0에서 실 MariaDB 10.4.32에 `schema.sql`을 무수정 적용(15/15)하고 셰임 없는 실 PDO 경로로 가입·로그인·`/api/me`·로그아웃·SSO 3단계·PKCE 왕복까지 확인했다.** 다만 **배포 호스팅의 MySQL 버전과 `sql_mode`는 다를 수 있고**, 전 엔드포인트의 기능 e2e는 아니다 | 설치 후 로그인 → `payload` 수신 → 포인트 차감까지 실제로 1회 종주한다 |
| 2 | **PHP 7.4 런타임** | 로컬에 8.1만 있다. 금지 문법(`match` / `str_contains` / `?->` / 유니온 타입 / enum) 정적 검사로 대체했다. v1.1.0에서 토크나이저 스캐너(`_workspace/php72_scan.php`, 규칙 50종)로 7.3·7.4·8.0+ 전용 문법 **검출 0건**을 재확인했다 | 7.4 호스팅이면 설치기 1단계 통과 후 로그인까지 해 본다 |
| 3 | **`.htaccess` 실효성 — Apache 부분은 v1.2.0 라운드에 검증됐다(`public/` · `examples/` 양쪽). 남은 것은 실 호스팅뿐이다.** `public/.htaccess`는 보안 헤더 **5종**에 더해 v1.2.0에서 **캐시 정책 3종**(HTML `no-store` / 자산 `immutable` / `version.txt` `no-store`)까지 담는다(개수는 `grep -c 'Header always set' public/.htaccess` = 전체, `grep -c 'Header always set Cache-Control' …` = 캐시분으로 다시 센다). **v1.2.0 QA가 실 Apache 2.4.58 + `mod_headers`로 이 파일을 파싱시켜 보안 5종 + 캐시 3종이 실제로 응답에 나가는 것을 확인했다** | **Apache: 확인했다** — QA가 `public/.htaccess`를 문서 루트에 놓고 Apache 2.4.58(`AllowOverride All`, `LoadModule headers_module`)로 기동해 문법 오류 없이 파싱되는 것과, `login.html` 응답에 보안 5종 + `no-store`가 **함께** 나가고 자산에 `immutable`, `version.txt`에 `no-store`가 붙는 것을 실측했다(`_workspace/25_qa_v1.2.md` E14·E15). **`examples/.htaccess`(신규)도 같은 Apache로 확인했다** — `httpd -t` Syntax OK, 두 데모 HTML 응답에 `no-store`, `examples/.htaccess` 자신과 디렉토리 목록은 **403**, 보안 헤더는 **의도한 대로 0건**, 그리고 **링크 재진입에서 옛 HTML이 나오던 것이 닫히는 것까지 액세스 로그로 재현**했다(`_workspace/26a_fix_examples_report.md`). **남은 미검증: 실 공용 호스팅** — 그 서버의 Apache 버전·`AllowOverride` 값·`mod_headers` 유무·제공자 단 리버스 프록시가 헤더를 어떻게 다루는지는 확인할 수 없다. **nginx·LiteSpeed는 11번** | `INSTALL.md` 3절의 **4항목을 브라우저로 직접 확인**한다. 캐시 3종은 `INSTALL.md` 7-9절 `##### 적용됐는지 확인하는 방법`의 `curl` 3줄로 확인한다. **자산 URL의 `?v=` 주 수단은 이 확인과 무관하게 동작한다** |
| 4 | **`php_flag engine off`가 mod_php 없는 환경(PHP-FPM/CGI)에서 500을 내지 않는지** | `<IfModule mod_php{,5,7}.c>` 가드는 정적 검토만 했다 | 설치 직후 `{설치루트}/server/storage/mail/`에 접속해 500이 아닌지 본다 |
| 5 | **`mail()` 실제 발송** | SMTP 환경 없음. v1.0.0 기본값이 `log`다 | 관리자 콘솔에서 `mail`로 바꾼 뒤 비밀번호 찾기를 1회 실행해 본다 |
| 6 | **브라우저 CORS 프리플라이트 실송출 / 쿠키 실제 전송** | 코드와 `allowed_origins` 파싱만 확인했다 | 로컬 앱에서 로그인해 개발자도구 Network 탭으로 확인한다 |
| 7 | **`install.php` 실제 설치 실행 (8단계 왕복 · 자기 잠금 · 자체 진단)** — **v1.1.0에서 QA가 검증 완료.** 남는 것은 **실 호스팅(PHP 7.2.34)에서의 설치**뿐이다 | v1.0.0에서는 실행하면 산출물 폴더에 `config.php`·락 파일을 만들어 QA의 "구현 파일 수정 금지" 제약과 충돌했다(`php -l`·상수 집합 51=51·`ins_split_sql()` 실행만 확인). **v1.1.0에서 QA가 산출물이 아닌 스크래치 사본 + 임시 DB로 1~8단계를 완주했다** (`_workspace/19_qa_v1.1_report.md` F-14). 검증 범위: 1단계 필수 7항목 전부 통과(**"PHP 버전 7.2 이상 / 통과 / 8.1.27"** 실측) · 3단계 DDL **15/15** · 5단계 관리자(`superadmin`/`staff`/`active`) · 6단계 서비스 · 7단계 시크릿 스킵 · **8단계 완료 화면 렌더 정상** · 자기 잠금(`.install.lock` + `storage/.installed`) 생성 후 **재POST 403** · 생성 `config.php` **51상수** + `php -l` OK + 7.2 스캔 **0/0** · 그 결과물로 **`POST /api/auth/login` 200 + 정상 `Set-Cookie`**. 생성 `config.php`가 `FORCE_HTTPS=false`와 `COOKIE_SECURE=false`를 **함께** 내려, 위 `## 잘못된 설정은 조용히 넘어가지 않는다 (fail-closed)`의 **"정상 설치 경로에서는 이 모순이 만들어지지 않는다"(D-60) 주장도 실측으로 확인됐다.** **남는 미검증은 실행 환경이다** — QA가 쓴 것은 **PHP 8.1.27 + 임시 MariaDB 10.4.32**이고, **실 호스팅의 PHP 7.2.34 인터프리터에서 이 8단계를 돌린 담당은 없다**(10번과 같은 공백이다. 호스팅 MySQL의 버전·`sql_mode` 차이는 1번) | 이것이 곧 배포 절차다. `INSTALL.md` 2절 그대로 진행한다. **절차 자체는 QA가 완주했으므로 다시 설계할 필요가 없다** — 실 호스팅의 7.2에서 1단계 표가 뜨는지, 8단계 완료 화면까지 가는지만 확인하면 된다(10번의 확인과 같은 1회 작업이다). 참고: 이 배포물 폴더에는 `config.php`·락 파일이 없다(QA는 스크래치 사본에서 돌렸다) |
| 8 | **실환경 리버스 프록시 검증** | nginx/Cloudflare 앞단 없음. `php -S`로 SAPI·헤더·301은 실측했다 | `TRUST_PROXY`를 켤 때는 **`TRUSTED_PROXIES`에 프록시 IP를 함께 채운다**(짝이다 — 비우면 요청이 500이 된다, D-67). 그리고 **프록시가 `X-Forwarded-Proto`를 덮어쓰는지** 확인한다. 절차는 `INSTALL.md` 3-3절 |
| 9 | **2FA** | 미구현(D-2)이라 감사 대상 코드가 없다 | — |
| 11 | **`.htaccess`가 무시되는 환경용 설정 스니펫 — 절반만 남았다.** (v1.2.0 신규) **Apache `AllowOverride None` 스니펫은 검증됐다. nginx 스니펫은 미검증이다.** 대상은 `INSTALL.md` 7-9절의 캐시 합본 스니펫과 `README.md` `### M-4 보충`의 보안 헤더 스니펫 | **Apache `<Directory>` 스니펫: 확인했다** — v1.2.0 QA가 `AllowOverride None` + `### M-4 보충`의 `<Directory>`(보안 5종) + 7-9절의 `<FilesMatch>` 3종(캐시)을 합쳐 실제로 기동했고, `httpd -t` **Syntax OK** 후 7-9절 `##### 적용됐는지 확인하는 방법`의 기대값 표를 **전부 충족**했다(`_workspace/25_qa_v1.2.md` F4). <br>**nginx 스니펫: 여전히 미검증** — **이 개발 환경에 nginx가 없다.** `nginx -t`를 돌려 본 담당이 없고, `include`로 보안 헤더를 재삽입하는 형태가 실제로 의도대로 동작하는지 확인하지 못했다. 확인한 것은 **헤더 값이 `public/.htaccess`의 정본과 문자 단위로 같다는 것**뿐이다(2026-08-28 실측). **`add_header` 비상속은 nginx 문서상의 동작이며 실측이 아니다.** LiteSpeed 비호환 모드도 재현하지 못했다. <br>**`examples/` 블록은 양쪽 다 미검증이다** — 7-9절의 `examples/` 스니펫(nginx `location` · Apache `<Directory>`)은 신설된 `examples/.htaccess`와 **정책이 등가임을 대조로 확인했을 뿐**(HTML `no-store` + 목록 차단, 보안 헤더 없음) 그 블록 자체를 웹서버에 적용해 본 담당은 없다. 검증된 것은 `.htaccess` 쪽이다 | **nginx**: 설정을 넣은 뒤 `sudo nginx -t`로 문법을 확인하고, `INSTALL.md` 7-9절 `##### 적용됐는지 확인하는 방법`의 `curl` 3줄을 돌린다. **`login.html`에서 `Cache-Control`은 보이는데 `Content-Security-Policy`가 사라졌다면 `include` 줄이 빠진 것이다.** <br>**Apache `AllowOverride None`**: 절차 자체는 검증됐으므로 그대로 넣고 `curl` 3줄로 최종 확인만 한다 |
| 10 | **실제 PHP 7.2 런타임 실행** (v1.1.0 신규) | **개발 환경이 PHP 8.1뿐이어서 7.2로 파싱한 것이 아니다.** 8.1의 `token_get_all()`로 스캔했고(ERROR 0건), 7.2 쿠키 분기는 `PHP_VERSION_ID` 조건을 뒤집은 사본으로 **강제 실행**해 헤더 16/16 바이트 동일 + 실 HTTP 왕복(로그인 200 → `/api/me` 200 → 로그아웃 → 401)까지 확인했다. 그래도 **7.2 인터프리터 자체의 동작 차이**는 정적 스캔으로만 커버된다 | **실 호스팅(7.2.x)에서 `install.php` 1단계를 열어 화면이 뜨는지 확인하는 것이 유일한 최종 증명이다.** 이어서 로그인 1회 → `Set-Cookie` 속성 확인(`INSTALL.md` 3절 5번) |

---

## 버전 / 변경 이력

| 버전 | 날짜 | 내용 |
|---|---|---|
| `v1.2.0_20260828` | 2026-08-28 | **배포 반영 / 캐시 무효화.** 자산 참조 URL에 `?v=1.2.0` 버전 쿼리, `public/.htaccess`에 캐시 정책 3종(HTML `no-store` / 자산 `public, max-age=31536000, immutable` / `version.txt` `no-store`), `public/version.txt` 신설(배포 반영 확인 수단), API 응답 캐시 헤더를 `no-store` 한 줄에서 **3줄**로 보강(`no-store, no-cache, must-revalidate, max-age=0` + `Pragma: no-cache` + `Expires: 0`). 문서에 **`INSTALL.md` `## 8. 새 버전으로 갱신하기`** 7단계와 nginx 캐시 스니펫·opcache 진단을 신설했다(D-83 ~ D-89). **응답 shape 변경 없음 — v1.0.0·v1.1.0과 API 호환.** 엔드포인트·필드·에러 코드·DB 스키마가 하나도 바뀌지 않았고 **동작 변경도 없다** — 바뀐 것은 응답 헤더 3줄과 자산 참조 URL이다 (`## 배포 반영과 캐시 무효화`). <br>**검증 라운드**(보안 감사 `24_security_v1.2.md` = 배포 가능 / QA `25_qa_v1.2.md` = 조건부 배포 가능)에서 나온 **Medium 2 · Low 4를 같은 폴더에서 닫았다**(D-95 ~ D-99) — 문서 5개 + `examples/*.html` 2개 + **`examples/.htaccess` 신설** + `public/index.html` 푸터에서 버전 문자열 제거. **`server/api/**`·`server/core/**`는 그 후속에서도 한 파일도 변하지 않았다.** 미해결 **Critical 0 / High 0.** 상세는 `CHANGELOG.md` `### 검증 라운드 결과 — v1.2.0` |
| `v1.1.0_20260826` | 2026-08-26 | **PHP 최소 요구 버전 7.4 → 7.2.** 쿠키 전송을 `send_cookie()` 양방향 호환 래퍼로 교체(7.2에서 `SameSite`·`HttpOnly`·`Secure`가 사라지던 문제 해결), 설치기 환경 점검 게이트 완화 + 7.2 EOL 경고. 같은 라운드의 3회차 보안 감사에서 나온 7건(쿠키 두 분기 동등성 불변식화, 비밀번호 NUL 차단, 프록시 설정 fail-closed)을 처리했다(D-65 ~ D-68). 마무리로 **평문 HTTP에 `Secure` 쿠키를 내보내려 하면 500으로 멈추게 했다**(D-75 — `## 잘못된 설정은 조용히 넘어가지 않는다 (fail-closed)`). **응답 shape 변경 없음 — v1.0.0과 API 호환.** 단, D-67·D-75는 **잘못된 설정에서의 동작 변경**이다(조용한 실패 → 명시적 500) |
| `v1.0.0_20260825` | 2026-08-25 | 최초 릴리스 |

상세는 `CHANGELOG.md` 참조.

**버전 규칙:** `v{major}.{minor}.{patch}_{YYYYMMDD}`
**모든 수정은 직전 버전을 복사해 새 버전 폴더를 만든 뒤 그 안에서만 한다.**
기존 버전 폴더의 파일·데이터는 수정·삭제하지 않는다.

---

## 관련 문서

| 문서 | 읽는 사람 | 내용 |
|---|---|---|
| `INSTALL.md` | 배포하는 사람 | 준비물부터 트러블슈팅까지 설치 전 과정 |
| `AI_INTEGRATION_GUIDE.md` | **다른 AI 에이전트** | 이 파일 하나만 읽고 연동 코드를 쓸 수 있는 자립형 단일 문서 |
| `examples/README.md` | 로컬 앱 개발자 | 3분 연동 가이드 |
| `CHANGELOG.md` | 전원 | 버전별 변경 이력 |
