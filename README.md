# ulogin — 범용 로그인 · SSO 서버

### Universal Login & SSO Server

**로컬 HTML/AI 앱의 JS에서 API 키를 없애기 위해, 로그인만 서버로 분리하고 인증된 사용자에게 등급에 맞는 시크릿만 반환하는 인증 서버.**

**An auth server that takes the API key out of your app's JavaScript. It moves only the login to the server, then hands each authenticated user exactly the secrets their tier allows — nothing more.**

`PHP 7.2+` · `MySQL / MariaDB` · `PDO` · **Composer 없음 / no Composer** · **빌드 도구 없음 / no build step** · **외부 의존 0개 / zero dependencies** · `MIT`

공용 호스팅에 폴더째 올리고 브라우저로 설치기를 한 번 열면 끝난다.
Upload the folder to any shared host, open the web installer once, and you are done.

---

## 목차 / Contents

| 절 | Section |
|---|---|
| [왜 만들었나 — 문제 정의](#왜-만들었나--문제-정의) | [Why this exists](#왜-만들었나--문제-정의) |
| [스크린샷](#스크린샷--screenshots) | [Screenshots](#스크린샷--screenshots) |
| [주요 기능](#주요-기능--features) | [Features](#주요-기능--features) |
| [아키텍처와 SSO 플로우](#아키텍처와-sso-플로우--architecture--sso-flow) | [Architecture & SSO flow](#아키텍처와-sso-플로우--architecture--sso-flow) |
| [빠른 시작](#빠른-시작--quick-start) | [Quick start](#빠른-시작--quick-start) |
| [기능별 스크린샷](#기능별-스크린샷--feature-gallery) | [Feature gallery](#기능별-스크린샷--feature-gallery) |
| [기술 스택과 제약](#기술-스택과-제약--stack--constraints) | [Stack & constraints](#기술-스택과-제약--stack--constraints) |
| [보안 설계 요약](#보안-설계-요약--security-design) | [Security design](#보안-설계-요약--security-design) |
| [문서](#문서--documentation) | [Documentation](#문서--documentation) |
| [알려진 제한 · 미검증 항목](#알려진-제한--미검증-항목--known-limits--unverified) | [Known limits & unverified](#알려진-제한--미검증-항목--known-limits--unverified) |
| [라이선스](#라이선스--license) | [License](#라이선스--license) |

---

## 왜 만들었나 — 문제 정의

로컬에서 도는 AI 앱을 HTML 한 파일로 만들었다고 하자. 잘 돌아간다.
그런데 그것을 남에게 주는 순간, 같이 딸려 나가는 것이 하나 있다.

```html
<!-- 배포와 동시에 공개된다 -->
<script>const OPENAI_KEY = "sk-...";</script>
```

**이게 왜 치명적인가.** 배포된 HTML의 JavaScript는 비밀이 아니다. 브라우저가 실행하려면 평문이어야
하므로 페이지 소스 보기나 개발자도구를 여는 **누구나** 그 문자열을 그대로 읽는다. 난독화는 대책이
아니다 — 실행 직전에는 평문으로 돌아오고, Network 탭에는 `Authorization` 헤더가 그대로 찍힌다.
그리고 키가 새면 **제3자가 당신 계정으로 호출하고 청구서는 당신에게 온다.** AI 제공자의 API 키에는
기본적으로 사용자별 한도가 없다.

그러면 서버를 두면 된다. 그런데 그 순간 만들어야 할 것이 갑자기 늘어난다 — 회원가입, 로그인,
비밀번호 찾기, 세션 관리, 사용량 과금, 관리자 화면. **정작 만들고 싶었던 것은 AI 앱이었다.**

**English.** Say you built an AI app as a single HTML file. It works. But the moment you hand it to
someone else, something ships with it: your API key.

**Why that is fatal.** JavaScript in a deployed page is not a secret. The browser needs the plaintext
to run it, so *anyone* who opens View Source or DevTools reads that string verbatim. Obfuscation is
not a defense — it unwinds right before execution, and the `Authorization` header shows up in the
Network tab anyway. Once the key leaks, **someone else makes the calls and you get the bill.**
Provider API keys have no per-user spending limit by default.

The obvious fix is to put a backend in front of it. But that drags in everything you did not set out
to build — signup, login, password reset, sessions, metering, an admin console. **You wanted to build
an AI app.**

### 그래서 — 로그인만 서버로 분리한다 / So: move only the login

ulogin은 그 사이를 고른다. **로그인만** 서버가 맡고, 앱은 여전히 정적 HTML 한 장이다.
빌드도, 서버사이드 렌더링도, 프록시도 없다. 바뀌는 것은 **키의 출처** 하나다 —
하드코딩이 아니라 **로그인 응답**에서 온다.

```
기존:   [로컬 앱 JS] ── sk-xxxx 하드코딩 ──→ [AI API]        ← 키가 공개된다
        before: the key is in the file everyone can read

ulogin: [로컬 앱 JS] ── 로그인 ──→ [ulogin 서버]
                                     ↓ 3축 등급 판정 / tier check
                     ←── 등급을 통과한 시크릿만 ──┘
            └── 받은 키로 직접 호출 ──→ [AI API]
            └── 호출마다 포인트 차감 ──→ [ulogin 서버]
```

그리고 서버가 **"누가 어떤 키를 받을 수 있는가"** 를 판정한다. 등급이 모자라면 그 키는 응답에서
**에러 없이 조용히 빠진다.** 앱은 받은 것으로 AI를 직접 부르고, 호출할 때마다 포인트가 깎인다.
시크릿은 클라이언트의 **메모리에만** 머문다 — `localStorage`에 넣으면 이 시스템을 만든 이유가 사라진다.

**English.** ulogin takes the middle path. The server owns **only the login**; your app stays a single
static HTML file — no build, no SSR, no proxy. The one thing that changes is **where the key comes
from**: not a hardcoded literal, but the login response.

The server then decides **which secrets this particular user may receive.** If their tier does not
clear a secret's gate, that key is **silently omitted** from the response — not an error. The app
calls the AI provider directly with whatever it got, and spends points per call. Secrets live in the
client's **memory only**; putting them in `localStorage` would defeat the whole point.

### 여기에 배포 환경 제약이 겹친다 / Shared hosting shaped the rest

이 프로젝트가 겨냥한 곳은 **공용 호스팅**이다. 그 환경이 아키텍처의 절반을 결정했다.

| 제약 / Constraint | 설계에 준 영향 / Consequence |
|---|---|
| **DB를 하나만 준다**<br>Only one database | 테이블 prefix `app_ulogin_` 로 다른 서비스와 한 DB를 공유한다<br>All 15 tables share one DB behind a prefix |
| **SSH·CLI가 없다**<br>No shell access | `composer install`을 돌릴 곳이 없다 → **외부 의존 0개.** PDO·OpenSSL·mbstring 내장 확장만 쓴다<br>Zero dependencies; built-in PHP extensions only |
| **빌드 도구가 없다**<br>No npm / bundler | 프론트는 **Vanilla JS + 정적 HTML** 8개<br>8 static screens, vanilla JS |
| **`mod_rewrite`가 없을 수 있다**<br>Rewrite may be unavailable | **파일 경로 = URL 경로.** 단일 라우터였다면 rewrite가 죽는 순간 **API 전체가 404**가 된다<br>File path *is* the URL; a single router would 404 the whole API |
| **PHP가 7.2일 수 있다**<br>The interpreter may be 7.2 | 7.2 ~ 8.x **양방향 호환.** 그 과정에서 실제 결함 1건을 잡았다 (아래 보안 절)<br>Same code runs on 7.2 through 8.x |

**English.** The target is **shared hosting**, and that environment decided half of the architecture.
The table above lists each constraint and what it forced.

> **왜 Firebase Auth · Auth0 · Keycloak이 아닌가**, 그리고 각 설계 결정의 근거는
> [`docs/PORTFOLIO.md`](docs/PORTFOLIO.md) 1~2절에 있다.
> **Why not Firebase Auth / Auth0 / Keycloak**, and the reasoning behind every design decision:
> see sections 1–2 of [`docs/PORTFOLIO.md`](docs/PORTFOLIO.md).

---

## 스크린샷 / Screenshots

**전부 실제로 설치·구동한 화면을 브라우저로 촬영한 것이다. 합성·모의 이미지가 아니다.**
모든 장면은 촬영 직전에 "기대 문자열이 DOM에 있는가"를 단언한 뒤 캡처했고, 등장하는 데이터는 전부
가상이다 — `demo_*` 계정, 예약 도메인 `example.com`, 문서용 예약 IP 대역, 데모용 가짜 AI 키.

**Every screenshot below is a real capture from a running installation** — none are mock-ups. Each
frame was asserted against the live DOM before capture, and all data shown is fictitious.

| | |
|---|---|
| ![SSO 모드 로그인 화면](docs/screenshots/login-sso.png)<br>**SSO 모드 — 헤더에 요청한 서비스명("Demo AI App에 로그인")이 표시된 상태**<br>로그인 화면이 어느 앱의 요청인지 사용자에게 밝히고, 끝나면 그 앱으로 되돌아간다.<br>*SSO mode — the requesting service name appears in the header.* | ![로그인 후 로컬 AI 앱 화면](docs/screenshots/demo-after-login.png)<br>**로컬 AI 앱, 로그인 후 — 3축 등급 배지 · 포인트 · 서버가 내려준 `payload`(키는 앞 6자만 표시)**<br>키는 하드코딩이 아니라 로그인 응답에서 오고, 화면에는 가려 표시하며 `localStorage`에 저장하지 않는다.<br>*After sign-in — tier badges, points, and the server-issued payload with the key truncated to 6 characters.* |
| ![관리자 대시보드 화면](docs/screenshots/admin-dashboard.png)<br>**관리자 대시보드 — 회원·로그인·포인트·세션 타일과 3축 등급 분포 막대**<br>권한 모델이 3축이라는 것이 통계 화면에 그대로 드러난다.<br>*Dashboard — member, login, point and session tiles plus three-axis tier distribution.* | ![로그인·로그아웃 응답의 Set-Cookie 실측 화면](docs/screenshots/set-cookie-headers.png)<br>**로그인·로그아웃 응답의 실측 `Set-Cookie`와, 로그아웃 전후 `/api/me`의 200 → 401**<br>공개용으로 **토큰 값만** 앞 8자를 남기고 잘랐다 — 속성은 실측 원문 그대로다.<br>*The actual `Set-Cookie` headers, and `/api/me` going 200 to 401 across sign-out.* |

**전체 35장은 아래 [기능별 스크린샷](#기능별-스크린샷--feature-gallery)에 묶음별로 있다.**
각 장이 무엇을 증명하는지, 촬영 환경과 가상 데이터의 근거는
[`docs/screenshots/INDEX.md`](docs/screenshots/INDEX.md)에 한/영으로 적혀 있다.
All 35 frames are grouped in the [feature gallery](#기능별-스크린샷--feature-gallery) below; the
capture environment and per-frame rationale live in `docs/screenshots/INDEX.md`.

---

## 주요 기능 / Features

| 영역 / Area | 내용 / What you get |
|---|---|
| **회원**<br>Accounts | 회원가입 · 로그인 · 로그아웃 · 토큰 갱신 · 비밀번호 찾기/재설정 · 이메일 인증 · 다중 기기 세션 관리 · 탈퇴. 마이페이지 **7탭**<br>Signup, login, refresh, password reset, email verification, per-device session control, account deletion. A 7-tab account page |
| **인증 · SSO**<br>Auth & SSO | 리다이렉트 SSO 3단계(`validate` → `authorize` → `exchange`) · 1회용 `code`(60초) · **PKCE (S256)** · `state` 검증 · `redirect_whitelist` **완전 일치** · Bearer 토큰 또는 쿠키 세션<br>Three-step redirect SSO with a 60-second single-use code, PKCE S256, state check, exact-match redirect whitelist |
| **시크릿 금고**<br>Secret vault | AI 키 등 서비스 시크릿을 **AES-256-CBC로 DB에 암호화 저장.** 시크릿마다 최소 등급 3개를 걸고, 통과한 것만 `payload`로 반환한다. 미달분은 **에러 없이 생략**된다<br>Service secrets encrypted at rest; each carries three tier gates; only what the user clears is returned, the rest silently omitted |
| **3축 등급**<br>Three-axis tiers | **계정등급 / 역할 / 결제등급**이 서로 독립이다. 각 값에 **랭크 정수**가 있어 문자열 비교로 생기는 조용한 오작동을 막는다. 서비스별 오버라이드는 **올려주기만**(grant-only) 한다<br>Account level, role and billing tier are independent axes, compared by integer rank; per-service overrides can only raise, never demote |
| **포인트**<br>Points | 전역 잔액 · 앱이 호출마다 차감 · **멱등키** 지원(같은 키로 재요청해도 중복 차감 없음) · 부족 시 `POINT_INSUFFICIENT`<br>Global balance, per-call spend, idempotency keys, explicit insufficient-balance error |
| **관리자 콘솔**<br>Admin console | **6탭** — 대시보드 / 회원 / 서비스 / 시크릿 / 로그 / 설정. 회원 등급·포인트 조정, 정지·복구, 세션 강제 종료, 서비스 CRUD, 시크릿 게이트 관리, 로그인/관리자 로그, **시크릿 전달 로그**, 전역 설정 **13키**<br>Six tabs covering users, services, secrets, logs (including a secret-delivery audit trail) and 13 global settings |
| **웹 설치기**<br>Web installer | 브라우저로 한 번 열면 **8단계**로 끝난다 — 환경 점검 · DB 접속 · 스키마 적용 · 키 생성 · 관리자 계정 · 첫 서비스 · 시크릿 · **자기 잠금**<br>One browser visit, eight steps, then it locks itself |
| **운영·진단**<br>Ops & diagnostics | **헬스체크** `GET /api/health` — 익명에게는 「살아 있나」만, 관리자에게는 9항목 자가 진단. **스키마 마이그레이션 러너** `server/migrate.php` — 적용 이력을 DB에 남기고, 중간에 끊겨도 어디까지 갔는지 알 수 있다<br>A health endpoint with two disclosure levels, and a schema migration runner that records how far it got |

**English.** Everything above ships in the box. There is no paid tier, no external service, and no
account to create anywhere else.

---

## 아키텍처와 SSO 플로우 / Architecture & SSO flow

```
┌──────────────────────────────┐        ┌──────────────────────────────────────────┐
│  내 로컬 HTML/AI 앱            │        │  ulogin 서버 / server  ({설치루트})         │
│  my app — no keys, static    │        │                                          │
│                              │  ①     │  public/     화면 8개 (Vanilla JS)         │
│  ulogin-client.js  ──────────┼─ 로그인 →│    login · register · forgot · reset      │
│                              │        │    verify · mypage · admin · index        │
│                              │        │  ────────────────────────────────────────  │
│  callback.html  ←────────────┼─ code ─ │  server/api/   엔드포인트 32파일            │
│    ↓ handleCallback()        │        │    auth · sso · me · points · admin       │
│                              │  ②     │  ────────────────────────────────────────  │
│                              │─ 교환 →  │  server/core/  내부 모듈 13개              │
│                              │←─ 토큰 ─ │    bootstrap  db  request  response       │
│                              │  payload│    auth  perm  payload  crypto            │
│  ③ payload.ai_api_key        │        │    ratelimit  logger  serializer           │
│     메모리에만 / memory only    │        │    mailer  gc                             │
│         ↓                    │        │  ────────────────────────────────────────  │
│  ④ AI 호출 + 포인트 차감        │  ⑤     │  MySQL   app_ulogin_* 15 테이블             │
│                              │───────→│    시크릿은 AES-256-CBC 암호화 저장           │
└──────────────────────────────┘        └──────────────────────────────────────────┘
```

**모든 요청이 `core/bootstrap.php` 한 곳을 지난다.** 그 파일이 설정 로드 · CORS · HTTPS 강제 ·
상수 정의를 맡고, `core/`의 나머지 **12개** 파일은 상단에서 `ULOGIN_BOOTSTRAPPED` 상수가 없으면
404로 즉시 종료한다. **웹서버 설정이 아니라 코드로** 직접 실행을 막으므로 `.htaccess`가 무시되는
환경에서도 유효하다.

응답 봉투는 전 경로에서 하나다. **`data`를 벗겨서 써야 한다.**

**English.** Every request passes through `core/bootstrap.php`, which loads config, handles CORS,
enforces HTTPS and defines the boot constant. The other **12** files in `core/` bail out with a 404
unless that constant is set — the guard is **in the code, not in the web server config**, so it holds
even where `.htaccess` is ignored. Every endpoint answers in one envelope shape; unwrap `data`.

```json
{ "ok": true,  "data": { }, "error": null }
{ "ok": false, "data": null, "error": { "code": "AUTH_REQUIRED", "message": "...", "field": null } }
```

### SSO 플로우 / The SSO round trip

```mermaid
sequenceDiagram
    autonumber
    participant App as "로컬 앱 / local app (no keys)"
    participant Srv as "ulogin 서버 / server"
    participant AI as "AI API"

    App->>Srv: GET sso/validate.php?service_id=&redirect_uri=
    Note over Srv: 서비스 존재 · 화이트리스트 완전 일치<br/>checked BEFORE any redirect
    Srv-->>App: 200 service info

    Note over App: state + code_verifier 생성<br/>challenge = SHA256(verifier)
    App->>Srv: 리다이렉트 / redirect to public/login.html

    Srv->>Srv: POST sso/authorize.php (credentials + challenge)
    Note over Srv: 레이트리밋 · 락아웃 · 상태 검사<br/>issue single-use code, 60s TTL
    Srv-->>App: 복귀 / back to callback.html?code=&state=

    Note over App: state 대조. 불일치면 즉시 중단<br/>compare state, abort on mismatch
    App->>Srv: POST auth/exchange.php (code + code_verifier)
    Note over Srv: code 1회성 소비 · PKCE S256 검증<br/>3축 등급 판정 → payload 조립
    Srv-->>App: 200 { user, session, payload }

    Note over App: payload 는 메모리에만 / memory only
    App->>Srv: POST points/consume.php (idempotency key)
    Srv-->>App: 200 { balance }
    App->>AI: POST (Authorization: Bearer payload.ai_api_key)
    AI-->>App: 응답 / response
```

**왜 두 단계인가.** 시크릿을 리다이렉트 URL에 실을 수 없기 때문이다. URL은 브라우저 히스토리,
북마크, `Referer` 헤더, **웹서버 액세스 로그**, 프록시 로그에 전부 남고, 액세스 로그는 애플리케이션
코드로 지울 수 없다. 그래서 URL에는 **60초짜리 1회용 `code`** 만 실어 보내고, 시크릿은 앱이 그
`code`를 **POST 본문**으로 바꿔 받는다. POST 본문은 액세스 로그에 남지 않는다.
PKCE가 그 60초 창까지 닫는다 — `code`를 훔쳐도 `code_verifier`가 없으면 교환할 수 없다.

**English.** Why two steps? **A secret cannot travel in a URL.** URLs land in browser history,
bookmarks, the `Referer` header, **web server access logs** and proxy logs — and access logs are
written before your application code ever runs, so you cannot scrub them. So the redirect carries only
a **single-use 60-second `code`**, and the app trades that code for the secrets over a **POST body**,
which access logs do not record. PKCE closes the remaining 60-second window: stealing the code is
useless without the `code_verifier`, which never leaves the app's memory.

### API 한눈에 / API at a glance

| 항목 / Item | 값 / Value | 다시 세는 명령 / Recount |
|---|---|---|
| 엔드포인트 파일 / endpoint files | **33** | `find server/api -name "*.php" \| wc -l` |
| **메서드 × 경로 조합** / method × path pairs | **45** | `grep -rho "only_method([^)]*)" server/api \| grep -o "'" \| wc -l` → ÷2 |
| DB 테이블 / tables | **17** | `grep -c "CREATE TABLE IF NOT EXISTS" server/schema.sql` |
| 에러 코드 / error codes | **31** | `grep -o "'[A-Z_]\{4,\}' *=>" server/core/response.php \| wc -l` |

그룹별 분포(45 기준) / by group: `admin` 19 · `auth` 11 · `me` 10 · `health` 2 · `sso` 2 · `points` 1.
(2026-09-01 실측 / measured — 위 명령을 그룹 디렉토리마다 돌린 값이다)

> **엔드포인트 개수는 계수 기준을 밝히지 않으면 반드시 갈라진다.**
> 이 README의 **45**는 **메서드 × 경로 조합**(`only_method()`가 허용하는 메서드의 총합)이고
> **`HEAD`를 포함한다** — v1.4.0의 `GET`/`HEAD /api/health`가 2로 세어지는 것이 그 때문이다.
> `HEAD`를 빼면 **44**다.
> [`AI_INTEGRATION_GUIDE.md`](AI_INTEGRATION_GUIDE.md) 5절의 요약표는 같은 API를,
> 쿼리 파라미터로 동작·최소역할이 갈리는 3건(`admin/secrets.php?action=reveal` ·
> `admin/logs.php?type=admin` · `admin/logs.php?type=secret_access`)을 별행으로 세므로 **47행**이 된다.
> **45 · 44 · 47은 같은 API를 세 기준으로 센 값이고 셋 다 맞다. 한쪽으로 통일하지 마라.**
>
> **다만 그 요약표는 지금 45행에서 동결돼 있다** — v1.4.0이 더한 2행(`api/health` ·
> `logs.php?type=secret_access`)이 그 표에 아직 없다. 그 절의 본문을 바꾸면 이미 그것을
> 인용한 연동 앱이 깨지므로, 문서 머리의 「v1.4.0 갱신 고지」에 보정을 모아 두었다.
>
> **Counting endpoints without stating the basis always diverges.** The **45** here counts
> **method × path pairs and includes `HEAD`** (44 without it). The reference table in
> `AI_INTEGRATION_GUIDE.md` §5 splits three query-parameter variants onto their own lines, which comes
> to **47 rows** — though that table is currently frozen at 45 rows, with the two v1.4.0 additions
> listed in the "v1.4.0 갱신 고지" box at the top of that file instead. All three numbers are correct;
> none should be "fixed" to match the others.

논리 경로는 `/api/auth/login` 형태지만 **실제 호출에는 `.php` 확장자를 붙인다** —
`{설치루트}/server/api/auth/login.php`. 동적 세그먼트는 쓰지 않고 **쿼리 파라미터**로 받는다
(`?uuid=`). 이것이 위의 "파일 경로 = URL 경로" 결정의 직접적인 결과다.

**English.** Logical paths look like `/api/auth/login`, but **the real call includes the `.php`
extension**. There are no dynamic path segments — everything is a query parameter (`?uuid=`). That is
the direct consequence of the "file path *is* the URL" decision above.

---

## 빠른 시작 / Quick start

**필요한 것 / You need:** PHP **7.2 이상** with `pdo_mysql` · `openssl` · `mbstring` · `json`,
그리고 **미리 만들어 둔 빈 MySQL/MariaDB 데이터베이스 하나**.
설치기는 DB 자체를 만들지 않는다 / the installer does not create the database itself.

### 1. 올린다 / Upload

이 저장소의 내용을 **그대로** 호스팅에 올린다. 그 위치가 **설치 루트**다.
Upload the contents of this repository as-is. Wherever it lands is your **install root**.

```
https://example.com/ulogin/          ← 설치 루트 / install root
├── server/     API · core · schema.sql · install.php
├── public/     화면 8개 / the 8 screens
└── examples/   동작하는 데모 / a working demo
```

### 2. 설치기를 연다 / Run the installer

```
https://example.com/ulogin/server/install.php
```

**8단계**를 순서대로 따라간다 / eight steps, in order:

| # | 단계 / Step | 무엇을 하나 / What happens |
|---|---|---|
| 1 | 환경 점검 / Environment | 필수 **7항목**을 검사한다. **"PHP 버전 7.2 이상 / 통과"** 를 확인하라<br>Seven required checks; PHP 7.1 and below is the only version that blocks |
| 2 | DB 접속 / Database | host · db · user · password · prefix 입력 후 접속 테스트 |
| 3 | 스키마 적용 / Schema | `CREATE TABLE IF NOT EXISTS` **17개** (v1.3.0은 15개). 결과 표의 **17줄이 전부 "성공"** 이어야 한다. 재실행해도 안전하다<br>17 tables in v1.4.0 — all 17 rows must read "성공" |
| 4 | 사이트·보안 / Site | 사이트명 · `BASE_URL` · HTTPS 강제와 쿠키 `Secure`를 **한 쌍으로** 결정한다 |
| 5 | 관리자 계정 / Admin | 첫 `superadmin` 계정을 만든다 |
| 6 | 첫 서비스 / Service | `service_id` · `redirect_whitelist` · `allowed_origins` |
| 7 | 시크릿 / Secrets | AI 키 등을 **등급 게이트와 함께** 등록한다 |
| 8 | 완료 / Done | 키를 생성하고 `config.php`를 쓴 뒤 **자기 자신을 잠근다** |

### 3. 잠그고 확인한다 / Lock down and verify

```
1. server/install.php 를 삭제한다 / delete the installer
2. 브라우저로 {설치루트}/server/config.php 를 연다 → 403 이나 빈 화면이어야 한다
3. 로그인 1회 → 개발자도구 Network → login.php 응답의 Set-Cookie 에
   HttpOnly · SameSite=Lax (HTTPS 면 secure) 가 붙었는지 확인한다
4. 관리자 토큰으로 헬스체크 1회 — checks 9 항목이 전부 ok:true 인지 본다
   curl -sS '{설치루트}/server/api/health.php' -H 'Authorization: Bearer <관리자토큰>'
```

**3번은 건너뛰지 마라.** 이것이 정적 검증으로 메울 수 없는 유일한 공백이고, 확인 비용은 30초다.
속성이 하나라도 없으면 **즉시 배포를 중단하라.** 이유는 아래 [보안 설계 요약](#보안-설계-요약--security-design)의
PHP 7.2 항목에 있다.

**English.** **Do not skip step 3.** It is the one gap static verification cannot close, and it costs
30 seconds. If any cookie attribute is missing, **stop the deployment** — the reason is in the PHP 7.2
row of [Security design](#보안-설계-요약--security-design) below.

전 과정(준비물 · 업로드 경로 · 8단계 화면별 입력값 · 설치 후 보안 확인 4항목 · 트러블슈팅)은
[`INSTALL.md`](INSTALL.md)에 있다. / The full walkthrough lives in [`INSTALL.md`](INSTALL.md).

#### 이미 v1.3.0 이하를 운영 중이라면 / Upgrading an existing install

**파일만 올려서는 안 된다.** v1.4.0은 테이블 2개·컬럼 1개·인덱스 3개를 더한다.
업로드 직후 `{설치루트}/server/migrate.php`를 **한 번** 열어 `superadmin`으로 인증하고 적용한다.
**백업을 먼저 받아라 — `down` 마이그레이션은 없다.** 셸이 있으면
`php server/migrate.php status` → `apply` → `verify`도 같은 일을 한다.
빠뜨렸는지는 헬스체크의 `checks.schema`가 알려준다.
절차 전문은 [`INSTALL.md` 8-2절](INSTALL.md#8-2-2단계--업로드와-스키마-마이그레이션)에 있다.
**신규 설치는 해당 없다** — 설치기가 최신 스키마를 적용하고 마이그레이션을 `baseline`으로 기록한다.

**English.** Upgrading from v1.3.0 or earlier changes the database. After uploading, open
`{install root}/server/migrate.php` once, authenticate as `superadmin`, and apply.
**Back up first — there is no `down` migration.** With shell access,
`php server/migrate.php status|apply|verify` does the same. Full procedure:
[`INSTALL.md` §8-2](INSTALL.md#8-2-2단계--업로드와-스키마-마이그레이션). Fresh installs are unaffected.

### 앱에 붙이기 — 4줄 / Wiring your app in four lines

```html
<!-- ?v= 는 캐시 무효화용이다. 지우면 브라우저가 옛 클라이언트를 최대 1년간 계속 쓴다
     (자산에 immutable 이 걸려 있다). {버전} 은 자리표시자다 —
     {설치루트}/public/version.txt 첫 줄의 `_` 앞부분을 그대로 넣는다.
     Keep the ?v= — assets are served immutable, so without it a stale client can stick for a year.
     {버전} is a placeholder: use the part before `_` in public/version.txt. -->
<script src="https://example.com/ulogin/public/assets/ulogin-client.js?v={버전}"></script>
<script>
  var ulogin = new UloginClient({
    server:      'https://example.com/ulogin',       // 설치 루트 한 칸. /server 나 /public 을 붙이지 않는다
    serviceId:   'local-ai-app',
    redirectUri: location.origin + '/callback.html'  // redirect_whitelist 와 한 글자도 다르지 않게
  });
</script>
```

복붙하면 그대로 도는 완전한 앱(`app.html` + `callback.html`)은
[`examples/`](examples/)와 [`AI_INTEGRATION_GUIDE.md`](AI_INTEGRATION_GUIDE.md) 11절에 있다.
A complete, runnable two-file app is in [`examples/`](examples/) and §11 of the integration guide.

---

## 기능별 스크린샷 / Feature gallery

촬영본 **35장 전체**다. 위 [스크린샷](#스크린샷--screenshots)의 4장도 여기에 각자의 묶음으로 다시 들어 있다.
캡션의 출처와 촬영 환경(Apache 2.4.58 + PHP 8.1.27 / Chrome headless 1280×800)은
[`docs/screenshots/INDEX.md`](docs/screenshots/INDEX.md)에 있다.

All 35 frames, grouped by feature. The four highlighted above reappear here in their own group.

### 설치기 8단계 / Installer — 8 steps

| | |
|---|---|
| ![1단계 환경 점검](docs/screenshots/install-01-env-check.png)<br>**1. 환경 점검** — PHP 7.2 이상, PDO MySQL, OpenSSL, mbstring 등 필수 7항목이 모두 "통과"<br>*Step 1 — all 7 required items pass* | ![2단계 DB 접속 정보](docs/screenshots/install-02-db.png)<br>**2. DB 접속** — 호스트·DB명·계정·테이블 prefix 입력<br>*Step 2 — host, name, user and table prefix* |
| ![3단계 스키마 적용](docs/screenshots/install-03-schema.png)<br>**3. 스키마 적용** — 테이블 생성 완료. **이 장은 v1.3.0 빌드에서 촬영한 것이라 화면에 15개가 보인다** — v1.4.0 배포본에서는 `schema_migrations` · `secret_access_logs`가 더해져 **17줄**이 나온다<br>*Step 3 — captured on a v1.3.0 build (15 tables); v1.4.0 lists 17* | ![4단계 사이트·전송 보안](docs/screenshots/install-04-site-security.png)<br>**4. 사이트·전송 보안** — BASE_URL, HTTPS 강제, 메일 드라이버, 가입 정책<br>*Step 4 — base URL, HTTPS enforcement, mail driver, signup policy* |
| ![5단계 관리자 계정 생성](docs/screenshots/install-05-admin.png)<br>**5. 첫 최고관리자 계정 생성** — 기본 비밀번호가 존재하는 상태를 애초에 없앤다<br>*Step 5 — create the first superadmin account* | ![6단계 첫 서비스 등록](docs/screenshots/install-06-service.png)<br>**6. 첫 서비스 등록** — `service_id`, 복귀 주소 화이트리스트, 허용 출처<br>*Step 6 — service ID, redirect whitelist, allowed origins* |
| ![7단계 시크릿 등록](docs/screenshots/install-07-secrets.png)<br>**7. 시크릿 등록** — 값과 함께 **등급 게이트**(`min_billing_tier`)를 지정한다<br>*Step 7 — each secret value carries a tier gate* | ![8단계 설치 완료](docs/screenshots/install-08-done.png)<br>**8. 완료** — 결과 요약과 `install.php` 삭제 안내. 생성된 `SECRET_ENC_KEY`·`APP_KEY`는 **화면에 표시하지 않는다**<br>*Step 8 — summary; the generated keys are never printed* |

### 인증 화면 / Authentication

| | |
|---|---|
| ![안내 화면](docs/screenshots/index.png)<br>**안내 화면** — 서비스 소개와 로그인·회원가입 진입. 프레임워크·번들러 없이 정적 HTML 한 장이다<br>*Landing page — one static HTML file, no framework* | ![일반 로그인 화면](docs/screenshots/login.png)<br>**일반 로그인** — 아이디 저장, 로그인 유지. 유지 여부에 따라 세션 쿠키의 수명이 달라진다<br>*Standard sign-in with "remember ID" and "stay signed in"* |
| ![SSO 모드 로그인 화면](docs/screenshots/login-sso.png)<br>**SSO 모드** — 헤더에 요청한 서비스명("Demo AI App에 로그인")이 표시된 상태<br>*SSO mode — the requesting service name appears in the header* | ![로그인 실패 화면](docs/screenshots/login-error.png)<br>**로그인 실패** — 아이디·비밀번호 중 무엇이 틀렸는지 알려주지 않는 공통 메시지(사용자 열거 방지)<br>*A single generic message — no user enumeration* |
| ![회원가입 화면](docs/screenshots/register.png)<br>**회원가입** — 비밀번호 강도 표시와 필수 약관 동의. 동의 이력이 별도 테이블에 남는다<br>*Sign-up with password strength meter and recorded consent* | ![비밀번호 찾기 화면](docs/screenshots/forgot.png)<br>**비밀번호 찾기** — 존재하지 않는 이메일에도 같은 응답을 돌려준다<br>*Password reset request — identical response either way* |

### 마이페이지 7탭 / My page — 7 tabs

7장 모두 상단에 **3축 등급 배지**(계정등급 · 역할 · 결제등급)가 함께 보인다.
All seven show the three-axis tier badges in the header.

| | |
|---|---|
| ![마이페이지 정보 탭](docs/screenshots/mypage-info.png)<br>**정보** — 계정 정보 조회와 수정. 등급·역할·포인트·아이디·이메일은 이 폼으로 바꿀 수 없다(권한 상승 경로 차단)<br>*Info — tiers, role, points, ID and email are not editable here* | ![마이페이지 보안 탭](docs/screenshots/mypage-security.png)<br>**보안** — 비밀번호 변경, 이메일 변경. 민감한 변경은 정보 수정 폼과 분리되어 있다<br>*Security — sensitive changes are kept off the profile form* |
| ![마이페이지 포인트 탭](docs/screenshots/mypage-points.png)<br>**포인트** — 잔액과 적립·사용 이력. AI 호출 차감도 여기에 기록된다<br>*Points — balance and full transaction history* | ![마이페이지 세션 탭](docs/screenshots/mypage-sessions.png)<br>**세션** — 유효 세션 목록과 개별 회수. 사용자가 자기 세션을 직접 끊을 수 있다<br>*Sessions — active sessions with individual revocation* |
| ![마이페이지 로그인 이력 탭](docs/screenshots/mypage-login-logs.png)<br>**로그인 이력** — 성공·실패 기록과 IP. 실패까지 남아 계정 도용 시도를 사용자가 본다<br>*Login history — successes, failures and IPs* | ![마이페이지 서비스 탭](docs/screenshots/mypage-services.png)<br>**서비스** — 이 계정이 연결된 서비스. 서비스별 가입·최근 사용 시각을 따로 관리한다<br>*Services — per-service join and last-used timestamps* |
| ![마이페이지 탈퇴 탭](docs/screenshots/mypage-withdraw.png)<br>**탈퇴** — 사유 입력과 확인 절차. 즉시 삭제가 아니라 상태 전이로 처리되고 사유가 남는다<br>*Withdrawal — a state transition with a recorded reason, not a delete* | |

### 관리자 콘솔 / Admin console

| | |
|---|---|
| ![관리자 대시보드](docs/screenshots/admin-dashboard.png)<br>**대시보드** — 회원·로그인·포인트·세션 타일과 3축 등급 분포 막대<br>*Dashboard — tiles plus three-axis tier distribution* | ![관리자 회원 목록](docs/screenshots/admin-users.png)<br>**회원 목록** — 6종 필터, 3축 배지, 유효 세션 수, 포인트. 상태·역할·계정등급·결제등급을 각각 독립적으로 거른다<br>*Member list — six independent filters* |
| ![관리자 회원 상세 모달](docs/screenshots/admin-user-detail.png)<br>**회원 상세** — 45개 필드와 **서비스별 오버라이드 표.** 전역 등급과 서비스별 등급, 그리고 유효값이 한 화면에 보인다<br>*Member detail — 45 fields plus per-service overrides* | ![관리자 서비스 관리 화면](docs/screenshots/admin-services.png)<br>**서비스 관리** — `service_id`, 복귀 주소 화이트리스트, 허용 출처, `session_ttl`<br>*Services — whitelist, origins and per-service TTLs* |
| ![시크릿 목록 — 값이 전부 마스킹된 기본 상태](docs/screenshots/admin-secrets.png)<br>**시크릿 목록 (기본 상태)** — 값이 전부 `****`. 목록 화면은 등급과 무관하게 **항상** 마스킹한다<br>*Secret list — every value masked, always* | ![시크릿 평문 조회 모달](docs/screenshots/admin-secrets-reveal.png)<br>**"값 보기"로 평문을 조회한 순간** — **최고관리자 전용**이고, 조회 사실이 `admin_logs`에 `secret.reveal`로 남는다(값은 남기지 않는다). 모달 뒤의 목록은 여전히 `****`. 값 `sk-demo-…`는 데모용 가짜다<br>*Superadmin only; the lookup itself is audit-logged, the value is not* |
| ![관리자 로그인 로그](docs/screenshots/admin-logs-login.png)<br>**로그인 로그** — 결과별 필터와 집계, 입력된 아이디·IP·User-Agent. 없는 계정에 대한 시도까지 별도 결과값으로 기록한다<br>*Login log — filtered by outcome, including attempts on non-existent accounts* | ![관리자 조작 로그](docs/screenshots/admin-logs-admin.png)<br>**관리자 조작 로그** — 조작 종류, 대상, **변경 전/후**, 사유. 등급 변경·시크릿 조회·세션 회수가 전부 남는다<br>*Admin action log — action, target, before/after, reason* |
| ![관리자 전역 설정 화면](docs/screenshots/admin-settings.png)<br>**전역 설정** — 정의된 12개 키. 정의되지 않은 키를 보내면 서버가 거부한다(임의 설정 주입 차단)<br>*Global settings — the 12 defined keys; anything else is rejected* | |

> **위 두 시크릿 화면은 반드시 짝으로 보라.** 오른쪽만 떼어 보면 정반대로 읽힌다 — 기본값은 마스킹이고,
> 평문은 최고관리자의 `?action=reveal` **한 경로**에서만, 감사 기록을 남기면서 나간다.
> **Read the two secret frames as a pair.** The right-hand one alone reads backwards: masking is the
> default, and plaintext leaves through exactly one superadmin path that writes an audit record.

### 로컬 AI 앱 데모 / Local AI app demo

| | |
|---|---|
| ![미로그인 상태의 데모 앱](docs/screenshots/demo-before-login.png)<br>**미로그인** — "이 페이지의 소스에는 AI API 키가 들어 있지 않습니다". 이 저장소가 풀려는 문제 그 자체다<br>*Signed out — the page source contains no AI API key* | ![로그인 후 데모 앱](docs/screenshots/demo-after-login.png)<br>**로그인 후** — 3축 등급 배지, 포인트, 서버가 내려준 `payload`(키는 앞 6자만 표시)<br>*After sign-in — badges, points, and the issued payload* |
| ![AI 호출과 포인트 차감](docs/screenshots/demo-ai-call.png)<br>**AI 호출** — 프롬프트 전송 → 포인트 10P 차감(1,260 → 1,250) → 응답. 차감은 호출 **전에** 서버에서 일어나고, 부족하면 AI를 부르지 않는다<br>*Prompt sent, 10 points deducted server-side first, then the response* | 이 화면의 AI 응답은 **로컬 목(mock) 엔드포인트**가 만든 것이다. 촬영에 외부 AI 제공자를 부르지 않기 위해서이며, `api_base` 값이 화면에 그대로 보인다. ulogin 쪽 동작(등급 확인 → payload 전달 → 포인트 차감)은 전부 실제 서버가 처리했다.<br><br>*The AI reply comes from a local mock endpoint (its URL is visible on screen); everything on the ulogin side was done by the real server.* |

### 검증 · 운영 / Verification

| | |
|---|---|
| ![Set-Cookie 실측 화면](docs/screenshots/set-cookie-headers.png)<br>**쿠키 속성 실측** — 로그인·로그아웃 응답의 `Set-Cookie`와 `/api/me`의 200 → 401. `HttpOnly`·`SameSite=Lax`가 실제로 붙고, 로그아웃이 쿠키 2종을 만료시키며 서버 세션도 함께 회수한다<br>*Measured cookie attributes and session revocation on sign-out* | ![캐시 무효화 실측 화면](docs/screenshots/cache-busting.png)<br>**캐시 무효화 실측** — `public/login.html`이 실제로 요청한 자산 3건이 전부 같은 `?v=` 값을 달고 나가고, 응답은 `max-age=31536000, immutable`. **이 장은 v1.3.0 빌드에서 촬영한 것이라 화면의 값이 `?v=1.3.0`이다** — 배포본마다 그 자리가 그 버전의 값(v1.5.0이면 `?v=1.5.0`)으로 바뀐다<br>*Assets cached for a year, invalidated by the version query* |

> 이 두 장은 개발자도구 화면이 아니라, Chrome DevTools Protocol로 받은 **응답 헤더·요청 URL 원문을
> 그대로 페이지에 렌더해** 촬영한 것이다. 표시된 문자열은 손으로 입력하지 않았다.
> **한 가지 예외는 표시된 생략이다** — `set-cookie-headers.png`의 세션 토큰 값만 앞 8자를 남기고 잘랐고,
> 잘랐다는 사실을 화면에 명시했다. **속성부는 게시 전에 원문과 바이트 단위로 대조했다.**
>
> These two render raw CDP-captured headers verbatim onto a page. The only alteration is a labelled
> elision of the token value; every cookie attribute is byte-for-byte the measured original.

---

## 기술 스택과 제약 / Stack & constraints

| | |
|---|---|
| **런타임 / Runtime** | PHP **7.2 이상** (7.2 ~ 8.x 양방향 호환) · MySQL 또는 MariaDB · PDO(`ATTR_EMULATE_PREPARES=false`) |
| **의존성 / Dependencies** | **없다.** Composer도 `vendor/`도 없다. PHP 내장 확장만 쓴다 — `pdo_mysql` · `openssl` · `mbstring` · `json`<br>**None.** No Composer, no `vendor/`, built-in extensions only |
| **프론트 / Front end** | 정적 HTML **8개** + Vanilla JS. 프레임워크·번들러·`node_modules` 없음<br>8 static screens, vanilla JS, no framework and no bundler |
| **DB / Database** | 테이블 **17개** (v1.3.0은 15개 — `schema_migrations` · `secret_access_logs`가 늘었다), 전부 `app_ulogin_` prefix, `utf8mb4`, `ROW_FORMAT=DYNAMIC`. **DB 1개를 다른 서비스와 공유해도 된다**<br>17 prefixed tables that can share a single database with other apps |
| **라우팅 / Routing** | **파일 경로 = URL 경로.** `mod_rewrite`는 확장자를 생략하게 해 주는 편의일 뿐, 없어도 전부 동작한다<br>The file path is the URL; rewrite is a convenience, not a requirement |
| **배포 / Deploy** | 폴더째 업로드 → `install.php` 1회. 빌드 단계 **0개**<br>Upload the folder, open the installer once. Zero build steps |
| **설정 / Config** | `server/config.php` 상수 **54개** (v1.3.0은 51개 — `SECRET_ACCESS_LOG` · `T_MIGRATIONS` · `T_SECRET_ACCESS`가 늘었다). 설치기가 생성하며 **저장소에는 커밋되지 않는다**(`.gitignore`). 템플릿은 `server/config.sample.php`<br>54 constants, generated by the installer, never committed |
| **회귀 테스트 / Tests** | `tests/` — 3티어 **89케이스**, **의존성 0**(PHP CLI 하나. PHPUnit도 빌드도 없다). `php tests/run.php --tier=1,2`는 DB 없이 약 6초에 돈다. **`tools/`와 같이 서버에 올리지 않는다** — [`tests/README.md`](tests/README.md)<br>A three-tier, dependency-free regression suite added in v1.5.0; **not uploaded to the server** |

### PHP 7.2 — 동작한다. 다만 알고 쓰라 / It runs on 7.2, but know what that means

**최소 요구 버전은 PHP 7.2.0이고, 7.2에서 정상 동작하게 만드는 것이 v1.1.0의 목표였다.**
쿠키 보호 속성(`HttpOnly` · `SameSite` · `Secure`)도 7.2에서 빠지지 않는다.

**그럼에도 호스팅 제어판에 PHP 7.4 이상 옵션이 있으면 그쪽을 골라라.**
PHP 7.2는 **2019-11-30**, 7.3은 **2021-12-06**에 보안 지원이 끝났다. 이후 발견된 인터프리터·확장
모듈 결함에는 패치가 나오지 않는다. 이 서버는 AI 키를 암호화 저장하고 인증된 사용자에게 반환하므로
**런타임 취약점의 영향 범위에 그 키가 들어간다.** 일반 웹앱보다 실패의 대가가 크다.

**설치기는 7.2·7.3을 차단하지 않는다.** 정보성 항목에 주황색 "주의"로 같은 사실을 표시하고 설치는
그대로 진행된다. 필수 항목에서 실패로 뜨는 것은 **7.1 이하**뿐이다.

**English.** The minimum is PHP **7.2.0**, and making 7.2 work correctly was the goal of v1.1.0 —
cookie protections (`HttpOnly`, `SameSite`, `Secure`) do not silently vanish there.

**Even so, pick 7.4+ if your host offers it.** PHP 7.2 went end-of-life on **2019-11-30** and 7.3 on
**2021-12-06**; interpreter-level flaws found since then will never be patched. Because this server
stores AI keys and hands them to authenticated users, **those keys are inside the blast radius of any
runtime vulnerability.** The installer does not block 7.2 or 7.3 — it flags them in amber and
proceeds. Only 7.1 and below fail the required check.

무엇이 여전히 안전하고 무엇이 그렇지 않은지를 함수 단위로 나눈 분석, 그리고 실행 모드(SAPI)에 따른
위험 판정은 [`docs/PORTFOLIO.md`](docs/PORTFOLIO.md) 5-6절과 [`INSTALL.md`](INSTALL.md) 0-1절에 있다.
A function-by-function breakdown of what 7.2 does and does not change is in `docs/PORTFOLIO.md` §5-6.

---

## 보안 설계 요약 / Security design

**v1.3.0까지: 미해결 Critical 0건 / 미해결 High 0건.**
보안 감사 **4회차**와 QA **3회차**를 거쳤고, 그때까지 발견된 Critical 3건과 High 5건은 **전부 수정 확인**됐다.

**v1.4.0이 닫은 것 — 이미 공개된 코드에 있던 Critical 1건 · High 2건.**

| 심각도 | 무엇이었나 |
|---|---|
| **Critical** | 서비스를 `disabled`로 바꿔도 `GET /api/auth/session`이 **시크릿 평문을 계속 반환**했다. 반환 경로 넷 중 **셋은 실제로 막혀서** 운영자가 막혔다고 결론짓게 만들었다 |
| **High** | `exchange`가 클라이언트 인증을 `code` 형식 검사보다 먼저 해서, 익명이 **401/400 두 응답으로 `service_secret`의 정오답을 가릴 수 있었다.** 실패가 어느 로그에도 남지 않았다 |
| **High** | **시크릿 반환(`payload`)이 무기록이었다.** 탈취 토큰으로 분당 200회 키를 받아가도 남는 것이 `last_seen_at`뿐이었고, 반대로 드문 관리자 조회는 기록됐다 — 감사 대상이 뒤집혀 있었다 |

**여기에 이 라운드가 만들고 이 라운드가 닫은 High 3건이 더 있다** — 새로 연 `migrate.php` 표면의
경쟁 조건·`FORCE_HTTPS` 누락, 그리고 레이트리밋 기록이 429마다 1행을 남기던 문제다.
보안 검증이 **배포 보류**를 냈고, 같은 폴더에서 셋 다 닫았다.

**독립 재검증이 그 셋을 전부 수정확인했고, 그 자리에서 High 1건을 새로 찾았다.**
새로 연 시크릿 전달 감사 로그가 **토큰 갱신 요청과 1:1로 늘고 자동 정리가 없었다**(유효 계정 하나로 하루 43만 행).
그것도 같은 폴더에서 닫았다 — 갱신 경로에 전용 레이트리밋을 걸어 **계정당 하루 1,440행**으로 묶었다.
**행이 요청과 1:1인 비율 자체는 남아 있고**(v1.5.0), 보존 정책은 v1.9.0이다.
같은 라운드의 QA는 **기능 회귀 0건**을 확인하고 **「서버가 만든 것을 화면이 쓰지 않는다」** 유형의
경계면 결함 High 3건을 찾았다 — 관리자 콘솔의 집계 배선·표식·로그 라벨이고, 셋 다 이 라운드에서 닫혔다.

> **정직하게 적는다 — 마지막 수정들에 대한 독립 재검증은 없다.**
> 보안 감사는 **6회차**까지 돌았고 그 뒤의 수정(위 신규 High 1건 + Medium 4건), 프론트 수정, 문서 수정은
> **담당 자신의 실측**이 근거다. **보안 7회차와 QA 2회차는 돌지 않았다.**
> 그래서 **「v1.4.0의 미해결 High 0」을 확정 판정으로 단언하지 않는다** — v1.3.0까지는 그것이
> 4회차 감사의 확정 판정이었고 v1.4.0은 아직 그 상태가 아니다.
> 라운드가 어떻게 끝났는지의 전수 기록은 [`CHANGELOG.md`](CHANGELOG.md)의 `v1.4.0` 절
> **「검증 라운드가 어떻게 끝났나」** 표에 있다. **그 절을 함께 읽으라.**

남은 Medium·Low는 숨기지 않고 [알려진 제한](#알려진-제한--미검증-항목--known-limits--unverified)에 적었다.

**English.** Through v1.3.0: zero unresolved Critical, zero unresolved High, across four audit rounds
and three QA rounds. **v1.4.0 closed one Critical and two Highs that were already in the published
code** — secrets kept flowing from a disabled service, an oracle that revealed whether a
`service_secret` guess was right, and the complete absence of an audit trail on secret delivery.
Three further Highs were introduced and closed inside the same round, on the newly added migration
screen. The independent re-verification confirmed all three and found one more High — the new
secret-delivery audit log grew one row per token refresh with no cleanup — which was also closed in
the same folder by rate-limiting that path. **The last round of fixes has not been independently
re-verified**: the security audit ran six rounds and QA one, and everything after that rests on each
owner's own measurements. **We therefore do not claim "zero unresolved High" for v1.4.0.**
See the `v1.4.0` section of [`CHANGELOG.md`](CHANGELOG.md) for the full round-by-round record.

| 위협 / Threat | 어떻게 막았는가 / How it is stopped |
|---|---|
| **AI 키가 클라이언트에 노출**<br>Key exposed in the client | 키는 DB에 **AES-256-CBC**로만 저장된다. 등급 게이트를 통과한 것만 `payload`로 나가고, 클라이언트는 **메모리에만** 보관한다<br>Encrypted at rest, gated on the way out, memory-only on the way in |
| **가입만 하면 키를 받는 기본 설치**<br>A default install that hands keys to anyone | 설치기 7단계가 시크릿마다 등급 게이트를 받는다. 민감 키(`*_api_key` · `*_secret` · `*_token` · `*_password`)는 기본 `min_billing_tier='lite'`. 게이트를 비우려면 명시적 확인이 필요하고, 관리자 API도 같은 검사를 한다 |
| **랭크 0 게이트로 위장**<br>A gate that gates nothing | `free` / `guest` / `user`는 랭크 0이라 전원 통과다. 설치기와 관리자 API가 이 값을 게이트로 세지 않는다 |
| **시크릿 평문이 목록에 실림**<br>Plaintext secrets in a list response | 목록·상세·생성·수정 응답의 `secret_value`는 **항상 `****`**. 평문은 superadmin의 `?action=reveal` **한 경로**로만 나가고, 그 조회가 감사 로그에 남는다 |
| **세션 ID를 훔쳐 인증**<br>Stolen session identifier | 쿠키에 담기는 값을 `session_id`가 아니라 `access_token`으로 바꾸고, 조회를 `access_token_hash` 단일 경로로 통일했다. **토큰은 전부 SHA-256으로 저장한다** — DB 덤프가 유출돼도 살아 있는 세션이 되지 않는다 |
| **관리자가 상급 계정을 탈취**<br>Admin escalating through another admin | 대상 계정을 변경하는 모든 관리자 경로의 **진입부**에 랭크 가드가 있다. 자신과 **같거나 높은** 랭크의 **다른** 계정은 어떤 변경도 할 수 없다 — 막지 않으면 "이메일 변경 → 비밀번호 찾기 → 탈취"가 수평으로 성립한다 |
| **서비스 오버라이드로 전역 관리자 상승**<br>Per-service role leaking into server admin | 관리자 API의 랭크 판정은 행위자·대상 모두 **전역 `users.role`만** 본다 |
| **오픈 리다이렉트 (SSO)**<br>Open redirect | `redirect_whitelist` **완전 일치.** 접두사 매칭이 아니다 — `https://app.example.com`을 접두사로 허용하면 `https://app.example.com.evil.test`가 통과한다 |
| **비밀번호 재설정 링크 탈취**<br>Reset-link hijack via Host header | `base_url()`이 `Host` 헤더로 폴백하지 않는다. `BASE_URL`이 비면 **메일 발송 자체를 중단한다**(fail-closed) |
| **SQL 인젝션 / XSS / CSRF** | 전 경로 PDO prepared statement(테이블명은 상수, prefix는 정규식 검증 후 치환) · 프론트에 `innerHTML` 계열 **0건** · 쿠키 세션 경로에 `access_token_hash` 기반 **세션 바인딩** CSRF 토큰 |
| **무차별 대입**<br>Brute force | 레이트리밋 + 계정 락아웃(기본 5회 / 15분). `login:ip` · `login:user` 버킷을 SSO와 **공유**해 우회 경로로 시도 횟수가 2배가 되지 않게 했다 |
| **PHP 7.2에서 쿠키 보호 속성이 조용히 사라짐**<br>Cookie flags silently dropped on 7.2 | `setcookie()`의 옵션 배열은 **7.3+ 전용**이다. 7.2에서 그 배열을 넘기면 만료 인자로 캐스팅되어 **`SameSite`가 통째로 사라지고 `HttpOnly`·`Secure`도 기본값으로 떨어진다 — 오류 하나 없이.** `send_cookie()` 래퍼가 `PHP_VERSION_ID`로 분기해 7.2에서는 `Set-Cookie` 헤더를 직접 조립한다. 두 분기의 동등성은 관측이 아니라 **불변식**이다 — 값 검증을 버전 분기보다 **앞**에서 하므로 잘못된 설정에서 양쪽이 같은 지점에서 같은 이유로 실패한다 |
| **메일 로그 평문 노출**<br>Plaintext reset links on disk | 다층 방어 — `storage/.htaccess` + `storage/index.html` + 메일 파일 확장자 `.php` + 첫 줄 `<?php exit; ?>` + 파일명 8바이트 난수 + `chmod 0700`/`0600` |
| **설치기 선점**<br>Installer hijack | 자기 잠금 **3종**(`.install.lock` · `storage/.installed` · `config.php`). 잠금 해제 파라미터는 **구현하지 않았다.** 설치기 자체도 POST + 1회용 CSRF를 쓰고, 생성한 키를 화면에 표시하지 않는다 |
| **비활성 서비스에서 키가 계속 나감**<br>Secrets outliving a disabled service | `build_payload()`가 **함수 안에서** 서비스 상태를 직접 본다. 호출부 네 곳에 조건을 흩어 두면 한쪽만 고쳐지므로, 판정은 **`service_load()` 한 곳**을 재사용한다. 서비스를 끄면 `payload`가 `{}`가 되고 **세션은 유지된다**(회원 단위 차단과 같은 모양) |
| **시크릿을 누가 언제 받아 갔나**<br>No audit trail on secret delivery | 새 표 `secret_access_logs`. 같은 세션의 반복 조회는 **요청마다 INSERT하지 않는다** — 그 경로가 가장 자주 불려서 분당 200회면 하루 28만 행이 되고 그 부피가 신호를 묻는다. `(세션, 출처, 1시간)`으로 묶고 `hits`를 올린다: 같은 상황이 **하루 24행 + `hits` 288,000**이 되어 숫자 하나가 오히려 더 크게 보인다. **키의 값은 담지 않는다 — 이름만.** *단 **토큰 갱신은 접히지 않는다*** — 회전마다 새 세션 식별자가 생겨 버킷 키가 갈린다. 그래서 갱신 경로에는 전용 레이트리밋으로 **상한**을 걸었다(계정당 하루 1,440행). 비율 자체는 v1.5.0 |
| **스키마를 바꾸는 웹 화면 자체**<br>The migration screen as a new surface | `server/migrate.php`는 **삭제하지 않는 파일**이다(다음 버전에서 또 필요하다). 그래서 `install.php`와 같은 수준의 보호를 받는다 — 미인증 `GET`은 로그인 폼뿐, **매 요청** superadmin 자격증명, 시도 제한 5회/15분(하나의 배타 잠금 안에서 검증 **전에** 소모), 계정 락아웃 존중, 실패의 DB 기록, `FORCE_HTTPS`에서 평문 요청 **403 거부**(리다이렉트가 아니다 — 301로 POST를 되던지면 비밀번호가 다시 나간다) |

### 잘못된 설정은 조용히 넘어가지 않는다 (fail-closed)

**설정이 틀렸는데 응답은 정상인 경로를 없앴다.** 아래 조건에서는 그 자리에서 500으로 멈추고
서버 오류 로그에 사유를 한 줄 남긴다. 500 본문에는 원인이 없다 — 내부 정보를 노출하지 않기 위해서다.
**그러므로 500을 만나면 응답이 아니라 로그를 먼저 열어야 한다.**

- `FORCE_HTTPS=true`인데 `BASE_URL`이 비어 있고 요청이 https가 아니다
- `TRUST_PROXY=true`인데 `TRUSTED_PROXIES`가 빈 목록이다 (짝이 맞지 않는 프록시 설정)
- 실효 `secure`가 켜진 쿠키를 **평문 HTTP 요청**에서 발급하려 한다
- `COOKIE_PATH`·`COOKIE_DOMAIN`에 쿠키 금지 문자가 들어 있다

**English.** There is no path where a misconfiguration produces a normal-looking response. Each
condition above stops the request with a 500 and writes one line to the server error log. The 500 body
deliberately carries no cause, so **when you hit one, open the log, not the response.** Exact log
strings and per-cause remedies are in [`INSTALL.md`](INSTALL.md) §7.

### 화면에 붙는 헤더 / Headers on the screens

`public/.htaccess`가 `Header always set` **8줄**을 건다 — **보안 헤더 5종**
(`Content-Security-Policy` · `X-Frame-Options: DENY` · `X-Content-Type-Options: nosniff` ·
`Referrer-Policy: strict-origin-when-cross-origin` · `Permissions-Policy` 지시자 **14개**)
**+ 캐시 정책 3종**(HTML `no-store` / 자산 `immutable` / `version.txt` `no-store`).
개수를 옮겨 적지 말고 다시 세라 / recount rather than copy:

```bash
grep -c 'Header always set' public/.htaccess                  # 8 — 전체
grep -c 'Header always set Cache-Control' public/.htaccess     # 3 — 캐시분. 차가 보안 헤더 수다
grep -o 'Permissions-Policy "[^"]*"' public/.htaccess | grep -o '=()' | wc -l   # 14 — 지시자 수
```

(2026-08-30 실측 / measured. 세 번째 명령에서 `grep -c`가 아니라 `grep -o … | wc -l`을 쓰는 것은
`-c`가 **일치 횟수가 아니라 일치한 줄 수**를 세기 때문이다 — 한 줄짜리 지시문에서는 항상 1이 나온다.)

**`.htaccess`가 무시되는 환경(nginx · `AllowOverride None`)에서는 이 8줄이 통째로 사라지고, 500도
나지 않는다 — 조용히 빠진다.** 대체 스니펫이 [`INSTALL.md`](INSTALL.md) 7-9절에 있다.
**배포 후 30초 확인:** 개발자도구 Network에서 `public/login.html` 응답에
`Content-Security-Policy`가 있는지 본다. 없으면 그 환경이다.

**English.** Those 8 directives disappear entirely — without any error — wherever `.htaccess` is
ignored. **After deploying, check once:** open DevTools → Network → `public/login.html` and look for
`Content-Security-Policy`. If it is absent, apply the nginx/Apache replacement snippets in
`INSTALL.md` §7-9.

발견해서 고친 취약점의 **재현 시나리오와 수정 전후 대조**는
[`docs/PORTFOLIO.md`](docs/PORTFOLIO.md) 5절에 있다.
Reproduction scenarios for every vulnerability that was found and fixed: `docs/PORTFOLIO.md` §5.

---

## 문서 / Documentation

| 문서 / Document | 읽는 사람 / Audience | 내용 / Contents |
|---|---|---|
| [`INSTALL.md`](INSTALL.md) | 배포하는 사람<br>Whoever deploys it | 준비물 · 업로드 · 설치기 8단계 · 설치 후 보안 확인 4항목 · **헬스체크** · 서비스/시크릿 등록 · HTTP 운영 · 트러블슈팅 21항목 · **버전 갱신과 스키마 마이그레이션**<br>Requirements through troubleshooting, step by step — including the health endpoint and the schema migration procedure |
| [`AI_INTEGRATION_GUIDE.md`](AI_INTEGRATION_GUIDE.md) | **다른 AI 에이전트**<br>Another AI agent | **이 파일 하나만 읽고 연동 코드를 쓸 수 있는 자립형 단일 문서.** 엔드포인트 전체 레퍼런스 · 요청/응답 JSON 전문 · 에러 코드 전수 · 프레임워크별 완전 예제 · 함정 목록<br>Self-contained: full endpoint reference, complete JSON, every error code, runnable examples |
| [`docs/PORTFOLIO.md`](docs/PORTFOLIO.md) | 기술 리뷰어<br>Technical reviewers | **"어떻게, 왜 그렇게 결정했는가."** 설계 결정의 근거 · 막은 취약점의 재현 시나리오 · 검증 방법론 · 정량 지표. 문서 끝에 English Summary<br>The reasoning, the reproductions, the methodology, the numbers |
| [`examples/README.md`](examples/README.md) | 로컬 앱 개발자<br>App developers | 3분 연동 가이드 / a three-minute integration walkthrough |
| [`tools/README.md`](tools/README.md) | 릴리스를 내보내는 사람<br>Whoever cuts a release | 자산 버전 일괄 치환 · PHP 7.2 호환성 스캐너. **서버에 올리지 않는 폴더다**<br>Release-time CLI scripts — **not uploaded to the server** |
| [`tests/README.md`](tests/README.md) | 코드를 고치는 사람<br>Whoever changes the code | **3티어 89케이스 회귀 스위트** (v1.5.0 신설, 의존성 0). 각 티어가 무엇을 잡고 **무엇을 못 잡는지**, 종료코드 5가지와 `3`·`4`가 따로 있는 이유. **서버에 올리지 않는 폴더다**<br>What the suite catches, **what it does not**, and why "nothing ran" has its own exit code |
| [`CHANGELOG.md`](CHANGELOG.md) | 전원 / everyone | 버전별 변경 이력. **각 라운드가 무엇을 바꿨고 무엇을 닫지 않았는지의 정본**<br>Release history — the canonical record of what each round changed and what it left open |

> **이 저장소에는 최신 버전 하나만 들어 있다.** 이전 릴리스는 git 태그로 관리한다.
> 그래서 `CHANGELOG.md`·`INSTALL.md`에 남아 있는 **버전 폴더 두 개를 나란히 비교하는 재현 명령**
> (`diff -rq v1.1.0_… v1.2.0_…` 형태)은 클론한 저장소에서 그대로 돌아가지 않는다.
> 그 명령들은 **당시 검증 이력의 근거로 보존된 것**이며, 다시 돌리려면 해당 태그를 함께 체크아웃해야 한다.
>
> **This repository holds only the latest version;** earlier releases live as git tags. Reproduction
> commands in `CHANGELOG.md` and `INSTALL.md` that diff two version folders side by side therefore
> will not run from a fresh clone — they are preserved as the record of what was verified at the time.
> Check out the matching tags to re-run them.

---

## 알려진 제한 · 미검증 항목 / Known limits & unverified

**완성된 것처럼 쓰지 않는다.** / **Nothing here is dressed up as finished.**

### 없거나 불완전한 것 / Missing or incomplete

| 항목 / Item | 상태 / State |
|---|---|
| **2FA** | 컬럼과 응답 필드만 있고 **기능이 없다.** `two_factor_enabled`는 항상 `false`이고 마이페이지에 "준비 중"으로 표시된다<br>Column and response field only; no implementation |
| **서비스별 포인트** | `user_services.points` 컬럼은 있으나 **쓰지 않는다.** 전역 포인트만 동작한다<br>Per-service points are not wired up; only the global balance works |
| **휴면 자동 전이** | `dormant` 상태는 있으나 **자동 전이가 없다.** 관리자가 수동으로만 설정한다<br>The dormant state exists but nothing transitions into it automatically |
| **`services.payload_fields`** | 저장·반환만 되고 **실제 필터링에 쓰이지 않는다. 동작하지 않는 보안 통제이므로 이것에 기대지 마라** — 접근 통제는 시크릿의 등급 게이트가 전부다<br>Stored and echoed but never enforced — **do not rely on it as a control** |
| **`require_tier()` · `can()` · `CAN_MAP`** | 미사용 API 표면이다. 그 결과 **`TIER_INSUFFICIENT`는 기본 구성에서 발생하지 않는다.** 연동 측이 등급 게이팅을 붙일 자리로 남겨 뒀다<br>Unused surface left for integrators |
| **`SECRET_ENC_KEY` 회전 경로**<br>Key rotation | **없다 (v1.4.0 미해결 · Critical).** 지금 그 키를 바꾸면 저장된 시크릿을 복호화할 수 없고 **`payload`가 조용히 `{}`가 된다** — 에러가 아니라 빈 객체다. 복구 방법은 시크릿 전량 재등록뿐이다<br>Changing the key silently empties every payload; there is no migration path |
| **일괄 세션 회수**<br>Bulk session revocation | **서비스·전역 단위로 세션을 한 번에 끊는 수단이 없다 (v1.4.0 미해결 · High).** 그래서 **서비스를 껐다 켜면 탈취된 토큰도 함께 되살아난다.** v1.4.0은 「끈 동안 `payload`가 `{}`가 된다」까지만 한다 — 침해 대응으로 껐다면 **끈 상태를 유지해야 한다**<br>Turning a service off and on again restores stolen tokens too |
| **세션 절대 수명 · HSTS**<br>Absolute session lifetime, HSTS | **둘 다 없다 (v1.4.0 미해결 · High).** refresh를 계속 돌리면 세션이 무한히 연장된다. `FORCE_HTTPS`는 301로 올려 보내지만 **첫 요청은 평문으로 한 번 나간다.** *같은 감사 항목의 다른 절반이었던 「refresh 전용 레이트리밋 없음」은 v1.4.0에서 닫혔다* — 갱신 횟수는 IP 120회/60초 · 계정 60회/3600초로 제한된다<br>Sessions extend indefinitely through refresh; the first request can still go out in the clear |
| **시크릿 전달 로그의 보존 정책**<br>Audit log retention | **없다 (v1.4.0 미해결).** `secret_access_logs` · `login_logs` · `admin_logs` · `point_logs` **네 표는 자동으로 줄지 않는다** — 감사 근거이므로 일부러 지우지 않는다. 상한은 각 경로의 레이트리밋뿐이다(시크릿 전달 로그: **계정당 하루 1,440행**, IP당 `min(172,800, 1,440 × 그 IP의 계정 수)`). 용량 측정 SQL과 기간을 명시한 수동 삭제 절차는 [`INSTALL.md`](INSTALL.md) 4-8절에 있다. 보존 GC는 v1.9.0<br>Four audit tables never shrink on their own; rate limits are the only ceiling until v1.9.0 |

### 구조상의 제약 / Structural constraints

| 항목 / Item | 내용 / Detail |
|---|---|
| **화면은 `public/` 직하에만** | `api.js`의 `ULOGIN_API_BASE = '../server'`가 상대 경로다. `public/` 아래에 하위 디렉토리를 만들어 화면을 추가하면 **API 호출이 전부 어긋난다**<br>Screens must live directly in `public/`; the API base is a relative path |
| **`service_id`는 변경 불가** | 세션·시크릿·가입 매핑·로그가 문자열 키로 참조한다. 새로 만들고 기존 것을 `disabled`로 내려야 한다<br>Immutable once created |
| **`BASE_URL`은 사실상 필수** | 비어 있으면 (a) 메일 발송이 전면 중단되고 (b) HTTPS 강제 리다이렉트가 500으로 멈춘다. **(a)는 응답이 "보냈습니다"라서 조용히 실패한다**<br>Effectively required; when empty, mail silently stops |
| **`auto_join=0` 서비스** | 로그인은 200으로 성공하는데 `payload`만 `{}`라서 **무증상 실패로 보인다**<br>Login succeeds but the payload is empty — an easy trap |
| **게이트 강제는 생성·수정 시점만** | 그 이전에 만들어진 게이트 없는 시크릿은 그대로 동작한다 — **v1.4.0이 민감 키 판정 규칙을 넓혔지만 기존 행은 건드리지 않는다.** 대신 관리자 콘솔 [시크릿] 탭의 **「게이트 점검」 카드**가 「이름이 민감 + 실효 게이트 0개」인 행을 목록으로 보여 준다(**자동 변경은 하지 않는다** — 운영 중인 서비스의 등급을 코드가 임의로 올리면 정상 연동 앱이 갑자기 막힌다). 판정이 이름 기반이라 `openai_org` · `api_base` 같은 이름은 비민감으로 남는다<br>Enforced at write time only; v1.4.0 widened the name rule and added a review screen, but never rewrites existing rows |
| **CSP의 `'unsafe-inline'`** | 화면 8개 중 **7개**가 인라인 `<script>`를 갖는다. **빌드 도구가 없어** sha256도 nonce도 쓸 수 없어 받아들인 타협이다. 그럼에도 `connect-src 'self'`가 남아 **XSS가 성립해도 데이터를 밖으로 보낼 통로가 없다**<br>An accepted trade-off; `connect-src 'self'` still closes the exfiltration path |

**남아 있는 Medium·Low 보안 항목** — 배포를 막지는 않지만 알고 운영해야 한다. 대표적인 것:
계정 열거(응답 시간 차이 및 `DUPLICATE_EMAIL`), 락아웃 남용에 의한 서비스 거부,
**PKCE 미강제**(`file://`에서는 `crypto.subtle`이 없어 클라이언트가 조용히 생략한다),
`rate_limit()`의 저장소 장애 시 fail-open, `enc()`/`dec()`의 **MAC 부재**(암호문 변조 미탐지),
`sso/authorize`가 `redirect_uri`의 쿼리스트링을 보존하는 데서 오는 `code` 주입 여지.
전체 목록과 각각의 영향은 [`docs/PORTFOLIO.md`](docs/PORTFOLIO.md) 5-8절에 있다.

**English.** A handful of Medium/Low findings were accepted rather than fixed — account enumeration,
lockout-driven denial of service, PKCE not being mandatory (`crypto.subtle` is unavailable over
`file://`, so the client skips it), a fail-open rate limiter under storage failure, and no MAC on the
AES layer. None of them block deployment, but you should know they are there. Full list with impact:
`docs/PORTFOLIO.md` §5-8.

### 검증하지 못한 것 / What was not verified

**정직하게 적는다. 실 호스팅과 개발 환경 사이의 간격이다.**

| # | 항목 / Item | 어디까지 확인했나 / How far it got | 배포 시 할 일 / What to do |
|---|---|---|---|
| 1 | **실 호스팅의 PHP 7.2 인터프리터**<br>A real 7.2 runtime | **개발 환경에 PHP 8.1만 있었다.** 토크나이저 스캐너(규칙 50종, ERROR 0)와, 버전 조건을 뒤집은 사본으로 7.2 쿠키 분기를 **강제 실행**해 헤더 바이트 동일 + 실 HTTP 왕복까지 확인했다. 그래도 **인터프리터 자체의 동작 차이**는 정적 스캔으로만 커버된다 | 설치기 1단계에서 **"PHP 버전 7.2 이상 / 통과"** 가 뜨는지, 8단계까지 가는지 확인한다 |
| 2 | **nginx · LiteSpeed**<br>Non-Apache web servers | **이 환경에 nginx가 없어 `nginx -t`조차 돌리지 못했다.** 확인한 것은 스니펫의 헤더 값이 `public/.htaccess`의 정본과 **문자 단위로 같다**는 것뿐이다. `add_header` 비상속은 nginx 문서상의 동작이며 실측이 아니다 | 설정 후 `nginx -t` → `INSTALL.md` 7-9절의 `curl` 3줄. **`Cache-Control`은 보이는데 CSP가 사라졌다면 헤더 재삽입 줄이 빠진 것이다** |
| 3 | **실 공용 호스팅의 `.htaccess`**<br>`.htaccess` on the real host | **Apache 쪽은 확인됐다** — 실 Apache **2.4.58 + `mod_headers`** 로 `public/.htaccess`·`examples/.htaccess`를 파싱시켜 보안 5종 + 캐시 3종이 실제 응답에 나가는 것과, `AllowOverride None` 대체 스니펫이 `httpd -t` Syntax OK로 동작하는 것까지 실측했다. **남은 것은 그 서버다** — Apache 버전 · `AllowOverride` 값 · `mod_headers` 유무 · 제공자 단 프록시는 확인할 수 없다 | 위 "배포 후 30초 확인"을 한다 |
| 4 | **배포 호스팅 MySQL의 전 엔드포인트 e2e** | 임시 **MariaDB 10.4.32**에 `schema.sql`을 무수정 적용(v1.4.0 기준 **17/17**)하고 실 PDO 경로로 가입·로그인·`/api/me`·로그아웃·SSO 3단계·PKCE 왕복까지 확인했다. v1.4.0에서는 **두 설치 경로(신규 `schema.sql` / 기존 `migrations/`)가 같은 스키마에 도달하는지**를 `information_schema` 직접 비교로 **두 담당이 각자** 확인했다(**291항목**과 **321항목** — 세는 집합이 다르다. 러너의 `verify`를 쓰지 않는 독립 하네스). **동작에 영향을 주는 축은 양쪽 다 차이 0**이고, 넓게 센 쪽이 찾은 차이 2건은 컬럼 주석과 컬럼 순서로 **둘 다 동작 영향이 없다.** 다만 호스팅의 MySQL 버전과 `sql_mode`는 다를 수 있다 | 설치 후 로그인 → `payload` 수신 → 포인트 차감까지 1회 종주한다. **기존 설치본을 갱신했다면 `migrate.php verify`도 함께.** 그 명령이 **비교하지 않는 8항목**(인덱스 `UNIQUE` 여부 등)은 [`INSTALL.md`](INSTALL.md) 8-2절의 SQL로 따로 확인한다 |
| 5 | **`mail()` 실제 발송** | SMTP 환경이 없다. 기본 드라이버가 `log`다 | 콘솔에서 `mail`로 바꾼 뒤 비밀번호 찾기를 1회 실행한다 |
| 6 | **브라우저 CORS 프리플라이트 · 쿠키 실전송** | 코드와 `allowed_origins` 파싱까지만 확인했다 | 로컬 앱에서 로그인해 개발자도구 Network 탭으로 확인한다 |
| 7 | **2FA** | **미구현이라 감사 대상 코드가 없다** | — |

> **3번을 "Apache는 됐으니 nginx도 될 것"으로 읽지 마라.** Apache에서 통과한 것이 nginx에서 통과한다는
> 뜻이 아니고, 두 항목은 검증 상태가 **다르다.**
> **Do not read row 3 as covering row 2.** Apache passing says nothing about nginx; the two rows have
> genuinely different verification status.

**v1.4.0에서 닫힌 「다음 단계」 항목** — `X-Powered-By` 제거(PHP 응답 + 정적 파일 `.htaccess` 3곳),
`fail_ratelimit`이 기록되지 않던 문제, 시크릿 전달의 무기록.
**진행 중인 PHP 버전을 사람이 볼 수 있게 하는 것**도 절반 닫혔다 — 관리자 콘솔 화면은 아직이지만
헬스체크가 `php.version` · `php.supported`를 관리자에게 낸다.

**v1.5.0에서 닫힌 「다음 단계」 항목** — **회귀 테스트 러너.** `tests/`가 그것이다
(3티어 89케이스, 의존성 0). 다만 **덮지 못하는 자리를 함께 열거했다** —
SSO 3단 종주 · 실 PHP 7.2 인터프리터 · `.htaccess`의 실제 효력 · HTTPS 경로 ·
진짜 병렬 처리는 이 스위트 바깥이고, 아래 「검증하지 못한 것」이 계속 유일한 확인이다.

**아직 남아 있는 것** — 인라인 스크립트 분리(`'unsafe-inline'` 제거), 로그아웃 감사 로그 1행 누락 제거,
데모 폴더 전용 CSP 설계, 관리자 헬퍼 중복 정리, 암복호 MAC 추가, `SECRET_ENC_KEY` 회전 경로,
일괄 세션 회수, 2FA, **SSO 플로우의 자동 종주**(회귀 스위트의 가장 큰 구멍).
각 항목이 **왜 그 라운드에 들어가지 않았는지**까지 [`docs/PORTFOLIO.md`](docs/PORTFOLIO.md) 9-3절에 적었다.

**English. Closed in v1.4.0** — the `X-Powered-By` header (both the PHP responses and the three
`.htaccess` files that cover static files), the rate-limit result that was defined but never written,
and the missing audit trail on secret delivery. Surfacing the running PHP version is half-closed: the
health endpoint reports it to admins, though the console screen does not yet.
**Closed in v1.5.0** — the regression test runner. `tests/` is that runner: three tiers, 89 cases,
zero dependencies. Its README also enumerates what it does **not** cover, which is a longer list than
you might expect: the SSO round trip, a real 7.2 interpreter, whether `.htaccess` actually takes
effect, the HTTPS path, and genuine request parallelism all remain outside it.
**Still open** — moving inline scripts out to drop `'unsafe-inline'`, a one-row audit-log gap on
logout, a CSP for the demo folder, de-duplicating admin helpers, a MAC on the encryption layer,
a rotation path for `SECRET_ENC_KEY`, bulk session revocation, 2FA, and automated coverage of the SSO
flow. `docs/PORTFOLIO.md` §9-3 explains why each one was not in the release it was deferred from.

---

## 라이선스 / License

**MIT.** 전문은 [`LICENSE`](LICENSE)에 있다. / Full text in [`LICENSE`](LICENSE).

상업적 이용·수정·재배포·비공개 사용 모두 허용되며, 저작권 고지와 라이선스 전문을 함께 배포하면 된다.
**보증은 없다** — 이 소프트웨어는 AI API 키를 다루므로, 배포 전에 위의
[알려진 제한 · 미검증 항목](#알려진-제한--미검증-항목--known-limits--unverified)을 읽고
[빠른 시작](#빠른-시작--quick-start) 3번의 30초 확인을 직접 수행하라.

**English.** MIT — commercial use, modification, redistribution and private use are all permitted;
keep the copyright notice and license text with the software. **It comes with no warranty.** Because
this software handles AI API keys, read
[Known limits & unverified](#알려진-제한--미검증-항목--known-limits--unverified) before you deploy, and
run the 30-second check in [Quick start](#빠른-시작--quick-start) step 3 yourself.
