# 로컬 앱에 ulogin 붙이기 — 3분 가이드

내 로컬 HTML/AI 앱에서 **API 키를 JS에 넣지 않고** 쓰기 위한 절차다.
키는 앱이 아니라 로그인 서버가 갖고 있고, 로그인한 사용자에게만 등급에 맞춰 내려준다.

| 파일 | 역할 |
|---|---|
| `local-ai-app.html` | 완성된 데모 앱. 로그인 → 시크릿 수신 → AI 호출 → 포인트 차감 → 로그아웃 |
| `callback.html` | 복귀(콜백) 처리 페이지. `code`를 토큰·시크릿으로 교환하고 원래 화면으로 돌려보낸다 |
| `../public/assets/ulogin-client.js` | 앱이 가져다 쓰는 연동 클라이언트. 의존성 0, `<script src>` 하나 |
| `.htaccess` | 위 두 HTML 에 `Cache-Control: no-store` 를 붙인다. **점(`.`)으로 시작해 FTP 에서 숨겨지기 쉬우니 업로드 시 확인하라** (`## 운영 배포 시 체크리스트`) |

> 두 데모 HTML 의 `<script src>` 에는 **`?v=` 버전 쿼리**가 붙어 있다. 캐시 무효화용이며 지우면 안 된다.
> 실제 값은 배포본마다 다르므로 파일을 열어 확인하라 — 이 문서의 예시에서는 `?v={버전}` 으로 적는다.
> 아래 [자산 버전과 배포 반영 확인](#자산-버전과-배포-반영-확인) 을 보라.

---

## 0-A. 배포 레이아웃과 "설치 루트" — 여기만 틀리면 전부 404다

**저장소 루트의 `server/` · `public/` · `examples/` 세 디렉토리를 그대로** 업로드한다.
그 위치를 **설치 루트**라고 부르고, 이 문서에서 `{설치루트}`는 항상 그 주소다.
(`README.md` · `docs/` · `LICENSE` 같은 문서는 서버에 올릴 필요가 없다.)

```
{설치루트}/                      ← 예: https://example.com/ulogin   (도메인 루트여도 되고 서브디렉토리여도 된다)
├── server/    api/ core/ config.php install.php schema.sql storage/
├── public/    login.html register.html … assets/
└── examples/  local-ai-app.html callback.html
```

| 자원 | URL |
|---|---|
| API | `{설치루트}/server/api/auth/exchange.php` 처럼 `{설치루트}/server/api/...` |
| 로그인 화면 | `{설치루트}/public/login.html` |
| 연동 클라이언트 | `{설치루트}/public/assets/ulogin-client.js` |
| 데모 | `{설치루트}/examples/local-ai-app.html` · `{설치루트}/examples/callback.html` |

**`UloginClient`에 넘기는 `server` 값은 설치 루트 한 칸이다.** `/server` 나 `/public` 을 붙이지 않는다.
클라이언트가 내부에서 두 값을 파생시킨다.

```
apiBase  = server + '/server'              → {설치루트}/server/api/...
loginUrl = server + '/public/login.html'
```

레이아웃을 바꿔 올렸다면(예: `public/` 내용을 도메인 루트에 직접) `apiBase` · `loginUrl` 옵션으로 각각 덮어쓴다.

---

## 0. 먼저 알아야 할 것 — `file://`로 열면 동작하지 않는다

`local-ai-app.html`을 파일 탐색기에서 더블클릭해 여는(`file://`) 방식은 **쓸 수 없다.**

| 막히는 것 | 이유 |
|---|---|
| PKCE (`crypto.subtle`) | 보안 컨텍스트(HTTPS 또는 `localhost`)에서만 동작한다. `file://`은 아니다 |
| 로그인 서버 API 호출 | `file://`의 origin은 `null`이라 CORS가 통과되지 않는다 |
| AI 서버 호출 | 같은 이유로 막힌다 |

**반드시 로컬 웹서버로 열어라.** 저장소 루트(`server/` · `public/` · `examples/` 가 보이는 자리)에서:

```bash
php -S localhost:5173 -t .
```

> **`php -S`를 쓰려면 로컬에 PHP가 있어야 한다. 요구 버전은 서버와 같은 PHP 7.2 이상이다**
> (7.2부터 8.x까지 같은 코드가 동작한다). 확인:
>
> ```bash
> php -v
> ```
>
> 7.2·7.3은 보안 지원이 끝난 버전이므로, 고를 수 있다면 7.4 이상을 쓰는 편이 낫다.
> 로컬 개발에서는 어느 쪽이든 동작에 차이가 없다.

그리고 브라우저에서 **http://localhost:5173/examples/local-ai-app.html** 로 접속한다.
이때 **설치 루트는 `http://localhost:5173`** 이다 (문서 루트가 곧 버전 폴더이므로).

> **`python -m http.server 5173` 이나 `npx serve -l 5173` 은 PHP를 실행하지 못한다.**
> 정적 파일만 서빙하므로 `server/api/*.php` 를 부르면 PHP 소스가 그대로 내려오고 로그인이 되지 않는다.
> 화면·경로 확인용으로만 쓸 수 있다. **API까지 돌리려면 위의 `php -S` 처럼 PHP가 도는 서버여야 하고,
> `server/config.php` 설치(`INSTALL.md` 2절)를 먼저 끝내야 한다.**
> 포트를 바꾸면 3단계의 `redirect_uri`도 같이 바뀌므로 1단계 화이트리스트를 다시 등록해야 한다.

---

## 1단계 — 서비스 등록 (관리자 콘솔 `admin.html` → 서비스 탭)

| 항목 | 데모용 값 |
|---|---|
| `service_id` | `local-ai-chat` |
| 서비스명 | 로컬 AI 챗 |
| `redirect_whitelist` | `["http://localhost:5173/examples/callback.html"]` |
| `allowed_origins` | `["http://localhost:5173"]` |
| **`auto_join`** | **켜짐 (`1`)** ← 기본값. 끄면 `payload`가 빈 객체가 된다 (아래 참조) |
| confidential 여부 | **아니오** (로컬 앱은 시크릿을 숨길 수 없으므로 public client) |

- `redirect_whitelist`는 **origin + path 완전 일치**로 검사한다. 접두사 매칭이 아니다.
  `http://localhost:5173/examples/callback.html` 와 `.../callback.html/` 는 다른 값이다.
- 화이트리스트에 없는 주소로 로그인을 시도하면 로그인 화면에서 `SSO_INVALID_REDIRECT`로 막힌다.
  **리다이렉트가 일어나기 전에** 막히므로, 이 단계를 건너뛰면 로그인 자체가 시작되지 않는다.
- `allowed_origins`는 브라우저 CORS용이다. 앱을 다른 포트에서 열면 여기도 추가해야 한다.
- `service_id`는 **생성 후 바꿀 수 없다.** 세션·시크릿·가입 매핑·로그가 이 문자열을 키로 참조한다.
  바꿔야 하면 새로 만들고 기존 것을 `disabled`로 내린다.

### `auto_join`을 끄면 아무 에러 없이 `payload`가 비어 버린다

**모르면 무증상 실패로 보이는 항목이다.** 반드시 읽어라.

`auto_join=1`(기본값)이면 사용자가 그 `service_id`로 처음 로그인할 때
가입 행(`user_services`)이 자동으로 생긴다. **`payload`는 그 행이 있어야 나온다.**

`auto_join=0`으로 등록하면 이렇게 된다.

| 무슨 일이 | 결과 |
|---|---|
| 미가입 사용자가 그 `service_id`로 로그인 | **200 성공.** 에러가 나지 않는다 (가입 심사 중인 사용자도 로그인은 할 수 있어야 하므로) |
| 그 응답의 `payload` | **빈 객체 `{}`** — 시크릿이 하나도 오지 않는다 |
| 데모 화면 | "이 등급에서는 AI 기능을 쓸 수 없습니다" 만 뜬다 |

**생성 경로 3곳(설치기 · 관리자 API · 관리자 콘솔)의 기본값이 전부 `auto_join=1`이므로,
일부러 끄지 않았다면 이 문제는 생기지 않는다.**

껐다면 관리자 콘솔 → 회원 → 대상 회원 상세 → 서비스 오버라이드에서
그 서비스에 대한 가입 행을 만들어 줘야 `payload`가 나온다.

## 2단계 — 시크릿 등록 (관리자 콘솔 → 시크릿 탭, `service_id=local-ai-chat`)

로그인 성공 시 `payload` 객체로 내려갈 키-값이다. 데모는 아래 3개를 쓴다.
**시크릿을 등록할 때는 값과 함께 "최소 등급"(등급 게이트) 3축을 반드시 정한다.**

| `secret_key` | 값 예시 | 종류 | 최소 등급 (권장) |
|---|---|---|---|
| `ai_api_key` | `sk-...` (실제 AI 제공자 키) | **민감 값** | **`min_billing_tier = lite`** ← 설치기 기본값 |
| `api_base` | `https://api.openai.com/v1` | 설정값 | 제한 없음 |
| `model` | `gpt-4o-mini` | 설정값 | 제한 없음 |

등록 폼의 셀렉트 3개가 게이트 3축이다. **셋 중 하나만 설정해도 게이트가 걸린다**(설정한 축만 검사한다).

| 셀렉트 | 상수 | 값과 랭크 |
|---|---|---|
| 최소 계정등급 | `min_account_level` | `guest`(0) · `basic`(10) · `verified`(20) · `vip`(30) · `staff`(90) |
| 최소 결제등급 | `min_billing_tier` | `free`(0) · `lite`(10) · `pro`(20) · `enterprise`(30) |
| 최소 역할 | `min_role` | `user`(0) · `manager`(10) · `admin`(20) · `superadmin`(30) |

> ### 게이트를 비우면 그 서비스에 로그인한 전원이 그 값을 받는다
>
> 세 축을 전부 "제한 없음"으로 두면 서버가 등급 검사를 **건너뛴다.**
> 이 서비스는 `auto_join=1`이고 신규 가입이 열려 있으므로 **가입 폼을 채우는 것이 곧 키를 받는 절차**가 된다.
> 게이트 없는 `ai_api_key`는 이 시스템을 쓰는 이유를 통째로 무효화한다.
>
> **랭크 0 값은 게이트가 아니다.** `min_billing_tier='free'` / `min_account_level='guest'` /
> `min_role='user'`는 걸어 놓은 것처럼 보이지만 랭크가 0이라 **전원 통과**다.
>
> 관리자 API는 민감 키(`*_api_key` · `*_secret` · `*_token` · `*_password` 계열)에
> 실효 게이트가 하나도 없는 생성·수정 요청을 **400으로 거부**한다.
> 관리자 콘솔에는 이 검사를 우회할 컨트롤이 **일부러 없다.**

- **등급 조건에 미달하는 시크릿은 에러가 아니라 `payload`에서 조용히 빠진다.**
  그래서 데모는 `payload.ai_api_key`가 없으면 "이 등급에서는 AI 기능을 쓸 수 없습니다"를 띄운다.
  기본 게이트(`lite`)를 그대로 두었다면 **회원의 `billing_tier`를 `lite` 이상으로 올려야** 키가 내려온다
  (관리자 콘솔 → 회원 → 등급 조정).
- **목록에 평문은 절대 나오지 않는다.** `is_masked` 값과 무관하게 항상 `****`로 표시된다.
  평문은 최고관리자의 "값 보기" 한 경로에서만 볼 수 있고 그 조회가 감사 로그에 남는다.
- `api_base`는 OpenAI 호환 엔드포인트의 base다. 데모는 여기에 `/chat/completions`를 붙여 호출한다.
- 키 이름을 바꾸면 앱 코드도 같이 바꿔야 한다. `payload`의 키는 관리자가 정한 `secret_key` 그대로다.

## 3단계 — 클라이언트 4줄 초기화

```html
<!-- {버전} 은 자리표시자다. 실제 값은 {설치루트}/public/version.txt 첫 줄의 _ 앞부분이다
     — 아래 "자산 버전과 배포 반영 확인" 절 참조. -->
<script src="https://your-host.com/ulogin/public/assets/ulogin-client.js?v={버전}"></script>
<script>
const ulogin = new UloginClient({
  server: 'https://your-host.com/ulogin',              // ★ 설치 루트 한 칸 (끝 슬래시 없이)
                                                       //   /server 나 /public 을 붙이지 않는다
  serviceId: 'local-ai-chat',                          // 1단계에서 등록한 service_id
  redirectUri: location.origin + '/examples/callback.html'  // 1단계 화이트리스트와 완전히 같은 문자열
});
</script>
```

위 `server` 값 하나로 클라이언트가 아래를 만든다. 브라우저 개발자도구 Network 탭에서 확인할 수 있다.

| 클라이언트가 부르는 것 | 실제 URL |
|---|---|
| `POST /api/auth/exchange` | `https://your-host.com/ulogin/server/api/auth/exchange.php` |
| `GET /api/auth/session` | `https://your-host.com/ulogin/server/api/auth/session.php` |
| `POST /api/auth/refresh` | `https://your-host.com/ulogin/server/api/auth/refresh.php` |
| `POST /api/auth/logout` | `https://your-host.com/ulogin/server/api/auth/logout.php` |
| `POST /api/points/consume` | `https://your-host.com/ulogin/server/api/points/consume.php` |
| 로그인 화면 이동 | `https://your-host.com/ulogin/public/login.html?service_id=…` |

`callback.html`에도 **같은 값으로** 하나 더 만든다. 그리고 한 줄:

```js
ulogin.handleCallback().catch(err => { /* err.code 로 분기해 화면에 표시 */ });
```

성공하면 클라이언트가 알아서 `return_to`(로그인 버튼을 누른 그 화면)로 되돌려 보낸다.

앱 화면에서 쓰는 것은 이것뿐이다.

```js
if (!ulogin.isLoggedIn()) ulogin.login();      // 로그인 서버로 이동
const s = await ulogin.getSession();           // 토큰 검증 + payload 재수신 (새로고침 후 필수)
const p = ulogin.getPayload();                 // { ai_api_key, api_base, model }
await ulogin.consumePoints(10, { reason: 'usage' });
await ulogin.logout();
ulogin.onExpire(() => { /* 재로그인 유도 */ });
```

**옵션**

| 옵션 | 기본값 | 언제 바꾸나 |
|---|---|---|
| `apiBase` | `server + '/server'` | `server/` 를 다른 위치에 올렸을 때. 전체 URL로 넣는다 |
| `loginUrl` | `server + '/public/login.html'` | 로그인 화면을 다른 위치에 올렸을 때(예: `public/` 내용을 도메인 루트에 직접 배치 → `'https://your-host.com/login.html'`) |
| `storage` | `'localStorage'` | `'sessionStorage'`(탭 닫으면 로그아웃) / `'memory'`(새로고침하면 로그아웃) |
| `apiExt` | `'.php'` | 서버에서 `mod_rewrite`가 동작해 확장자를 생략할 수 있으면 `''` |
| `refreshSkew` | `60` | 만료 몇 초 전에 자동 갱신할지 |
| `deviceLabel` | UA 추정값 | 세션 목록에 표시될 기기명을 직접 정할 때 |

> **폐기된 옵션:** `loginPath`. 기본값 `'/login.html'`은 이 레이아웃에서 맞지 않아 제거했다.
> 넘기면 `server + loginPath` 로 조립해 주지만 `console.warn`이 뜬다. `loginUrl`(전체 URL)을 쓴다.

## 4단계 — 로컬 웹서버로 열기

**저장소 루트**(`server/` · `public/` · `examples/` 가 보이는 자리)에서 실행한다.

```bash
php -S localhost:5173 -t .
```

→ http://localhost:5173/examples/local-ai-app.html

> **`python -m http.server 5173` 도 되지만 PHP를 실행하지 못한다.**
> 정적 파일만 서빙하므로 `server/api/*.php` 를 부르면 PHP **소스가 그대로 내려온다.**
> 화면·경로 확인용으로는 쓸 수 있어도 로그인은 되지 않는다.
> **API까지 실제로 돌리려면 위처럼 PHP가 동작하는 서버여야 하고,
> `server/config.php` 설치(`INSTALL.md` 2절)를 먼저 끝내야 한다.**
> 이미 운영 서버에 설치를 마쳤다면 설치 루트를 그 주소(`https://your-host.com/ulogin`)로 두고
> 앱만 로컬에서 열어도 된다 — 그 경우 앱의 origin을 `allowed_origins`에 추가해야 한다.

데모 화면의 **"연결 설정"** 을 펼쳐 아래 3개를 넣고 저장한다. `localStorage`의
`ulogin_demo_config` 키에 저장되고 `callback.html`도 **같은 키·같은 의미**로 읽는다. 파일을 고칠 필요가 없다.

| 입력 칸 | 넣는 값 (위 로컬 서버 예) |
|---|---|
| ulogin 설치 루트 URL | `http://localhost:5173` ← **한 칸만.** `/server`·`/public` 을 붙이지 않는다 |
| `service_id` | `local-ai-chat` |
| API 확장자 | `.php` (rewrite가 동작하는 서버면 비워 둔다) |

설치 루트에 `/server` 나 `/public` 을 붙여 저장하려 하면 데모가 저장을 거부하고 안내한다.
운영 서버라면 설치 루트는 `https://your-host.com/ulogin` 처럼 버전 폴더를 올린 그 주소다.

이제 **로그인** 버튼 → 로그인 서버 → 복귀 → 사용자 정보·등급 배지·포인트·`payload`(키는 앞 6자만)
→ 프롬프트 전송(10P 차감 후 AI 호출) → 로그아웃 까지 그대로 돌아간다.

---

## 동작 순서 (무엇이 어디서 일어나는가)

```
[내 앱]  ulogin.login()
          state(32B) + PKCE verifier/challenge 생성 → sessionStorage 저장
          → {설치루트}/public/login.html?service_id=&redirect_uri=&state=&code_challenge=
[로그인서버]  GET  {설치루트}/server/api/sso/validate.php   (실패하면 리다이렉트하지 않고 화면에 오류)
              POST {설치루트}/server/api/sso/authorize.php → code 발급(60초, 1회용)
              location.replace(data.redirect_to)
[callback.html]  ulogin.handleCallback()
                  URL state ↔ 저장된 state 대조 (불일치 → 교환 중단)
                  POST {설치루트}/server/api/auth/exchange.php {code, service_id, redirect_uri, code_verifier}
                  ← access_token / refresh_token / user / session / payload
                  history.replaceState 로 URL에서 code·state 제거
                  → return_to 로 복귀
[내 앱]  ulogin.getSession() → payload 사용 → AI 호출 + consumePoints
```

### 무엇을 어디에 저장하는가

| 값 | 저장 위치 | 이유 |
|---|---|---|
| `access_token` `refresh_token` `expires_at` `session_id` | `storage` (기본 `localStorage`) | 새로고침 후에도 로그인 유지 |
| `payload` (AI 키 등) · `user` · `session` | **메모리만** | 저장하면 이 시스템을 만든 이유가 사라진다. 새로고침 시 `getSession()`으로 재수신 |
| `state` `code_verifier` `return_to` | `sessionStorage` (로그인 중에만) | 탭 단위 1회용. 콜백에서 소비하고 즉시 삭제 |

`localStorage` 키 이름은 `ulogin_{service_id}_access_token` 형식이다. 한 origin에서 여러 서비스를
붙여도 충돌하지 않는다.

---

## 자산 버전과 배포 반영 확인

로그인 서버를 **새 버전으로 갱신할 때마다** 자산 URL 의 `?v=` 값이 바뀐다.

```html
<script src="{설치루트}/public/assets/ulogin-client.js?v={버전}"></script>
```

`{버전}` 은 자리표시자다. 넣을 값은 `{설치루트}/public/version.txt` 첫 줄의 `_` 앞부분이며,
확인 방법은 바로 아래 [서버에 지금 어느 버전이 올라가 있는지 확인](#서버에-지금-어느-버전이-올라가-있는지-확인) 에 있다.

- 이 쿼리가 **캐시 무효화의 주 수단이다.** 값이 바뀌면 브라우저에게는 처음 보는 URL 이므로
  무조건 새로 받는다. 서버의 `mod_headers`·`.htaccess`·프록시 설정에 의존하지 않는다.
- **지우지 마라.** 없으면 브라우저가 옛 `ulogin-client.js` 를 무기한 계속 쓰고,
  서버를 갱신해도 앱에서는 아무것도 바뀌지 않는다.
- 내 앱에 클라이언트를 붙일 때는 **서버에 올라간 버전과 같은 값**을 쓴다.
  서버 갱신 후 앱 쪽 `?v=` 를 안 바꿔도 파일 자체는 새것을 받지만(URL 이 같아 캐시가
  유지될 수 있으므로), 확실히 하려면 서버 버전에 맞춰 같이 올린다.

> `?v=` 는 자산 URL 전용이다. `UloginClient.version`(현재 `'1.0.0'`)은 클라이언트의
> **공개 API 버전**이고 이것과 별개다. 둘을 같은 값으로 맞추려 하지 마라.

### 서버에 지금 어느 버전이 올라가 있는지 확인

```bash
curl https://your-host.com/ulogin/public/version.txt
```

브라우저로 `{설치루트}/public/version.txt` 를 열어도 된다. 한 줄로 버전 문자열이 나온다.

- 올린 버전과 **다른 값**이 보이면 업로드가 안 됐거나 다른 경로에 올라갔다.
- 값이 맞는데도 화면 동작이 그대로면 개발자도구 Network 탭에서 자산 요청 URL 의 `?v=`
  를 확인한다. 자산은 새것인데 API 동작만 옛것이면 서버 PHP 쪽 캐시(opcache) 문제다 —
  진단 절차는 `INSTALL.md` 를 보라.

### "반영이 안 된다"를 두 갈래로 갈라서 신고하라

원인이 완전히 다르고, 물어보지 않으면 구분이 안 된다.
`mod_headers` 가 **없는** 웹서버로 실측한 결과다(2026-08-28).

| 무엇을 했을 때 안 되는가 | 원인 | 누가 고치는가 |
|---|---|---|
| **일반 새로고침(`F5`)해도 안 된다** | 서버에 새 버전이 안 올라갔거나, 자산 `?v=` 가 안 바뀌었거나, PHP 코드 캐시다 | **ulogin 관리자.** `version.txt` 값과 `?v=` 값을 함께 알려 준다 |
| **새로고침하면 되는데 링크·주소창으로 들어가면 안 된다** | HTML 이 브라우저 캐시에서 나온다. 웹서버에 HTML `no-store` 설정이 빠졌다 | **ulogin 관리자** (`INSTALL.md` 7-9절의 웹서버 설정). 그때까지는 새로고침 1회로 해소된다 |

**일반 새로고침은 최상위 문서를 항상 서버에 재검증한다.** 그래서 캐시 헤더가 없어도 새 HTML 이
오고, 새 HTML 이 가리키는 새 자산 URL 이 브라우저에 도달한다. 링크·주소창 재진입은 그 재검증을
하지 않는다 — **`?v=` 가 무력해지는 경로는 새로고침이 아니라 재진입에 한정된다.**

> **이 폴더에는 `.htaccess` 가 있다.** 데모 HTML 두 개에 `Cache-Control: no-store` 를 붙여
> 위 두 번째 갈래를 막는다 — `callback.html` 은 데모가 아니라 **복귀 주소 화이트리스트의
> 기본값**이라 실제 SSO 플로우가 지나가기 때문이다.
> **데모를 내 앱으로 복사해 갈 때 이 파일은 따라가지 않는다.** 그래서 두 HTML 의 `<head>` 에도
> `<meta http-equiv="Cache-Control" content="no-store">` 를 함께 넣어 두었다 —
> 복사본에 남는 유일한 수단이지만 **보조 수단이다**(현대 브라우저는 이 meta 를 무시할 수 있다).
> **주 수단은 어느 쪽도 아니라 자산 URL 의 `?v=` 쿼리다.**

---

## 자주 막히는 곳

| 증상 | 원인 / 해결 |
|---|---|
| 로그인 버튼을 누르면 **404** (로그인 화면이 안 나온다) | 설치 루트가 틀렸다. 주소창의 URL이 `{설치루트}/public/login.html` 형태인지 본다. `.../ulogin/server/public/login.html` 처럼 `server`가 끼어 있으면 설정에 `/server`를 붙여 넣은 것이다 |
| Network 탭에서 API 요청이 **404** | 같은 원인. 요청 URL이 `{설치루트}/server/api/auth/exchange.php` 여야 한다. `/public/server/api/...` 나 `/server/server/api/...` 면 설치 루트를 고친다 |
| `<script src>`가 404 | 클라이언트는 `{설치루트}/public/assets/ulogin-client.js` 에 있다. `{설치루트}/assets/...` 가 아니다 |
| 로그인 화면에 "허용되지 않은 복귀 주소입니다" | `redirect_uri`가 화이트리스트와 한 글자라도 다르다. 데모 화면에 표시되는 `redirect_uri` 문자열을 그대로 복사해 등록한다 |
| 콜백에서 `STATE_MISMATCH` | 콜백 페이지를 직접 열거나 즐겨찾기·새로고침으로 다시 방문했다. 앱의 로그인 버튼부터 다시 시작한다 |
| 콜백에서 `SSO_CODE_INVALID` | code가 만료(60초)됐거나 이미 사용됐다. 또는 `redirect_uri`·PKCE 불일치. 다시 로그인한다 |
| `NETWORK_ERROR` (Failed to fetch) | 서버 주소 오타 / 서버 다운 / `allowed_origins`에 앱 origin이 없음(CORS). 콜백 화면의 "교환 다시 시도"로 재시도할 수 있다(60초 내) |
| `payload`가 `{}` | **원인 4가지를 순서대로 확인하라.** ① **미가입** — `auto_join=0` 서비스인데 가입 행이 없다(1단계 참조). ② **등급 미달** — 시크릿 게이트를 넘지 못했다. 에러 없이 그 키만 빠진다. ③ **서비스 차단** — `user_services.status='blocked'`. 이때는 `service_id`를 붙인 로그인이 `FORBIDDEN`(403)으로 막힌다. ④ **서비스 자체가 비활성** — 관리자 콘솔 [서비스] 탭에서 그 `service_id`가 `활성`인지 본다. **v1.4.0부터 비활성 서비스는 `payload`를 `{}`로 낸다**(그전에는 시크릿이 그대로 나왔다). 서비스에 시크릿이 아예 없는 경우도 `{}`다 |
| `payload`가 `{}` — **④인지 30초로 가리는 방법** | 새 탭에서 아래를 열어 본다. `400 SSO_INVALID_SERVICE`가 나오면 ④다. `200`이면 ①②③으로 돌아간다.<br>`{설치루트}/server/api/sso/validate.php?service_id=local-ai-chat&redirect_uri=<복귀주소를 URL인코딩>` |
| 관리자 로그 화면에서 **「시크릿 전달 로그」의 「전체 N건」이 예상보다 작다** | **한 줄이 한 번이 아니다.** 그 표의 줄은 `(세션, 출처, 1시간)` 단위이고 실제 전달 횟수는 `hits` 열이다. 정상 폴링 앱 하나가 하루 종일 돌면 **24줄 + `hits` 수만**이 된다 |
| `POINT_INSUFFICIENT` | 포인트가 부족하다. 적립은 관리자만 할 수 있다 |
| 새로고침하면 AI 키가 사라짐 | 정상이다. `payload`는 메모리에만 있다. `getSession()`을 한 번 부르면 다시 채워진다 |
| 콘솔에 "crypto.subtle을 쓸 수 없어 PKCE 없이 진행합니다" | `file://` 또는 HTTP(비 localhost)로 열었다. HTTPS 또는 `http://localhost`로 열어야 PKCE가 걸린다 |

---

## 운영 배포 시 체크리스트

- [ ] **HTTPS로 서비스한다.** HTTP로 운영하면 토큰과 AI 키가 평문으로 흘러간다
- [ ] `redirect_whitelist`에 운영 도메인의 콜백 주소를 등록하고, 개발용 `localhost` 항목은 지운다
- [ ] `allowed_origins`도 운영 도메인만 남긴다
- [ ] **시크릿 게이트를 확인한다** — 민감 키(`*_api_key`·`*_secret`·`*_token`·`*_password`)에 실효 게이트가
      비어 있으면 그 서비스에 로그인한 전원이 값을 받는다. `free`/`guest`/`user`는 랭크 0이라 게이트가 아니다
- [ ] `auto_join`을 껐다면, 가입 행이 없는 회원에게는 `payload`가 `{}`로 나간다는 점을 앱이 처리하는지 확인한다
- [ ] **서비스 상태가 `활성`인지 확인한다.** v1.4.0부터 서비스를 비활성으로 내리면
      `payload`가 `{}`가 된다 — HTTP 200이 그대로 오고 세션도 살아 있으므로 **앱에서는 무증상으로 보인다**
- [ ] **`payload`가 `{}`인 경우를 앱이 처리하는지 한 번 실측한다.** 관리자 콘솔에서 그 시크릿의
      게이트를 일시적으로 올려 `{}`를 만들고, 앱이 죽지 않고 안내를 표시하는지 본 뒤 **원래 값으로 되돌린다**
- [ ] `storage`를 `'sessionStorage'`로 바꿀지 검토한다(공용 PC 대응)
- [ ] 앱 소스에 키가 하드코딩돼 있지 않은지 확인한다 — 이 데모의 두 HTML에는 없다
- [ ] **이 폴더의 `.htaccess`를 함께 올렸는지 확인한다.** 점(`.`)으로 시작해 FTP 클라이언트에서
      숨겨지기 쉽다. 없으면 `callback.html`이 브라우저에 캐시되고, 그 안의 옛 `?v=`가 굳어
      **`ulogin-client.js`가 최대 1년치로 잠긴다**(그 자산에는 `immutable`이 걸려 있다)
- [ ] **`<script src>`의 `?v=` 값을 서버에 올라간 버전과 맞춘다.** 서버를 갱신할 때마다
      `curl {설치루트}/public/version.txt`로 확인하고 앱 쪽도 같이 올린다 —
      **연동 앱의 HTML은 서버 릴리스 스크립트의 대상이 아니다**

---

## 변경 이력

| 날짜 | 변경 내용 | 사유 |
|---|---|---|
| 2026-09-01 | **`자주 막히는 곳`의 `payload`가 `{}` 행을 원인 3가지 → 4가지로.** ④ 「서비스 자체가 비활성」 추가 + `validate.php` 한 줄로 가리는 방법 + 「시크릿 전달 로그」의 줄 수를 횟수로 오독하는 것을 막는 행 | **리더 판정 D-152.** v1.3.0까지는 비활성 서비스에서 시크릿이 그대로 나왔으므로 **이 원인이 존재하지 않았다.** v1.4.0이 닫으면서 무증상 실패의 원인이 하나 늘었다 |
| 2026-09-01 | 운영 배포 체크리스트에 **서비스 상태 확인**과 **`payload={}` 처리 실측** 2항목 추가 | 위와 같은 이유. 앱이 `{}`를 처리하지 않으면 운영자의 「잠시 끄기」 조작이 앱 장애로 나타난다 |
| 2026-08-28 (검증 후속) | **`### "반영이 안 된다"를 두 갈래로 갈라서 신고하라` 신설** — 새로고침으로도 안 되는 경우와 링크 재진입에서만 안 되는 경우를 원인·담당까지 갈라 놓은 표. 신설된 `examples/.htaccess`와 `<meta>` 보조 수단의 관계도 명시 | **리더 판정 D-95 / D-96.** QA가 `mod_headers` 없는 환경을 별도로 만들어 확인했다 — **그 환경에서도 일반 새로고침으로는 반영된다.** 즉 `?v=`가 무력해지는 경로는 재진입에 한정되는데, 그 구분이 문서에 없어 신고와 진단이 엉켰다 |
| 2026-08-28 (검증 후속) | 운영 배포 체크리스트에 **`examples/.htaccess` 업로드 확인**과 **`?v=` 값을 서버 버전과 맞추기** 2항목 추가 | **QA Q12-1 / 보안 감사 V12-M-1.** `examples/`가 캐시 정책 밖이었고, 연동 앱의 HTML은 서버 릴리스 스크립트의 사정권 밖이다 |
| 2026-08-28 | 버전 폴더 경로를 **`v1.2.0_20260828`** 으로 갱신 (0-A절 · 0절 · 4단계) | v1.2.0 릴리스. 이전 버전 폴더는 수정하지 않는 규칙이라 경로가 어긋나 있었다 |
| 2026-08-28 | **`## 자산 버전과 배포 반영 확인`** 절 신설. 3단계 `<script src>` 예시에 `?v=1.2.0` 반영. 상단 파일 표에 안내 추가 | 리더 판정 D-84 / D-86. 자산에 버전 쿼리도 캐시 헤더도 없어 브라우저가 무기한 캐싱했고, 갱신 반영을 확인할 수단이 없었다. **연동 절차 자체는 바뀌지 않았다** |
| 2026-08-26 | 버전 폴더 경로를 **`v1.1.0_20260826`** 으로 갱신 (3곳: 0-A절 · 0절 · 4단계) | v1.1.0 릴리스. 이전 버전 폴더는 수정하지 않는 규칙이라 경로가 어긋나 있었다 |
| 2026-08-26 | `php -S` 절에 **요구 PHP 버전 7.2 이상** 명시 (`php -v` 확인 명령 포함) | 리더 판정 D-57 / D-61. 서버 최소 요구 버전이 7.4 → 7.2로 낮아졌다. **연동 절차는 바뀌지 않았다** |
| 2026-08-26 | 1단계에 **`auto_join` 설명 추가** — 끄면 `payload`가 아무 에러 없이 `{}`가 된다 | 리더 판정 D-48 / QA Q-15. 없으면 무증상 실패로 보인다 |
| 2026-08-26 | 2단계 시크릿 등록에 **등급 게이트 3축 선택 절차** 추가. 랭크 0 값이 게이트가 아니라는 점, 관리자 API의 400 거부, 콘솔에 우회 컨트롤이 없다는 점 명시 | 리더 판정 D-47 / D-54 / D-55 (보안 감사 C-3) |
| 2026-08-26 | 목록 응답의 `secret_value`가 `is_masked`와 무관하게 항상 `****`임을 명시 | 리더 판정 D-24 / D-32 |
| 2026-08-26 | 로컬 서버 예시를 `php -S`로 교체. `python -m http.server`는 **PHP를 실행하지 못한다**는 점을 두 곳에 명시 | 리더 판정 D-45 #4 |
| 2026-08-26 | 트러블슈팅의 `payload`가 `{}` 항목을 **원인 3가지(미가입 / 등급 미달 / 서비스 차단)** 로 확장 | 리더 판정 D-31 / D-48 |
| 2026-08-26 | 운영 배포 체크리스트에 시크릿 게이트·`auto_join` 항목 추가 | 위와 동일 |
| 2026-08-25 | 최초 작성 (설치 루트 기준 URL 반영) | 리더 판정 D-28 |
