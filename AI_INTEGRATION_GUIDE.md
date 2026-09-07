# ulogin v1.6.0 — AI 에이전트용 연동 가이드 (자립형 단일 문서)

> **이 문서의 독자는 사람이 아니라 다른 AI 에이전트입니다.**
> "이 로그인 서버를 내 앱에 붙여 줘"라는 요청을 받은 LLM이, **이 파일 하나만 읽고** 추가 질문 없이 작동하는 코드를 쓸 수 있도록 만들어졌습니다.
> 다른 파일(README.md, INSTALL.md, API 계약서, 소스 코드)을 열 필요가 없습니다. 필요한 내용은 전부 이 문서 안에 중복해서 적었습니다.
>
> 사용자가 채워야 하는 값은 `<<이런 형태>>`로 표시했고, **그 값을 어디서 얻는지**까지 각 자리에 적어 두었습니다.

---

## v1.6.0 갱신 고지 — **기존 연동 앱에 대한 API 변경 0입니다. 이 절은 30초면 끝납니다**

**v1.5.0 → v1.6.0에서 연동 앱이 고칠 것은 `?v=` 값 하나뿐입니다.**

기존 엔드포인트 · 실제 호출 URL · 메서드 · HTTP 상태 코드 · 응답 봉투 · **에러 코드 31개** ·
`user` 33필드 · `session` 11필드 · `payload` 구조 · 3축 등급과 랭크 정수 ·
**어떤 시크릿이 `payload` 에 실리는가의 판정** · 기존 레이트리밋 한도 · 쿠키 동작 —
**하나도 바뀌지 않았습니다.**
**신규 DB 컬럼·테이블·마이그레이션도 0건**이므로 서버 운영자가 `migrate.php`를 다시 돌릴
필요도 없습니다(v1.4.0 이하에서 올라오는 경우는 그 릴리스들의 마이그레이션이 여전히 남아 있습니다).

| 이 릴리스가 한 것 | 연동 앱이 알아야 하나 |
|---|---|
| **관리자 전용 엔드포인트 4동작 추가** — 설정 번들 export / validate / dry-run / apply. 파일 1개(`server/api/admin/settings_bundle.php`), **네 동작 모두 `superadmin` 전용** | **거의 아니오.** 일반 연동 앱은 이 경로를 호출하지 않습니다. 5절 요약표에 4행이 늘었습니다(47 → **51행**) |
| `server/core/` 에 파일 3개 추가 — 민감 키·게이트 판정, 설정 13키 정의, URL 규칙을 **한 곳으로 모은 것** | **아니오.** 응답 shape 변경 0. 옮긴 규칙 중 **게이트 실효성 판정 하나는 이 라운드에 좁아졌습니다** — 아래 행 |
| **시크릿의 「실효 게이트」 판정이 좁아졌다** — `min_account_level: "basic"` 은 이제 게이트가 아니고(각 축의 **모집단 기본값**이라 전원이 통과합니다), 같은 서비스의 `default_account_level` 이 그 등급을 자동으로 보장하면 게이트로 세지 않습니다 | **당신의 앱이 `POST`/`PUT /api/admin/secrets.php` 를 호출한다면 예.** 전에 201/200 이던 요청 본문이 **400**(`VALIDATION_FAILED` · `field: min_billing_tier`)일 수 있습니다. 처방은 `min_billing_tier` 를 `lite` 이상으로. **로그인·SSO·`payload` 수신·포인트 경로는 전부 무영향입니다** — 전달 판정은 `core/payload.php` 가 독립적으로 하고 이 함수를 부르지 않습니다 |
| 신규 문서 `AI_SETTINGS_JSON_GUIDE.md` — **설정 번들 JSON을 만드는 AI를 위한 자립형 문서** | **설정 JSON을 만들라는 요청을 받았다면 그 문서를 읽으세요.** 이 문서는 **연동 코드**를 다루고, 그 문서는 **설정 파일**을 다룹니다 |
| 자산 URL의 `?v=` 값이 `1.5.0` → **`1.6.0`** | **예 — 한 가지만.** 당신 앱의 `<script src>`에 붙인 `?v=` 값을 서버 배포본에 맞추세요 |

**그러므로 v1.0.0 ~ v1.5.0용으로 쓴 연동 코드는 수정 없이 v1.6.0에서 동작합니다** —
위 표의 게이트 판정 행에 해당하는 관리자 API 호출 코드가 있는 경우만 예외입니다.

> **설정 JSON을 만드는 일이라면 이 문서가 아닙니다.**
> 같은 폴더의 **`AI_SETTINGS_JSON_GUIDE.md`** 를 읽으세요. 그 문서 하나에
> 번들 스키마 전체 · 검증 오류 코드 18개 전수 · 경고 코드 7개 전수 · 실패 예시 21건이
> 들어 있고, 이 문서를 함께 읽을 필요가 없습니다.
> **두 문서의 경계:** 앱에서 로그인시키고 시크릿을 받아 쓰는 코드 → **이 문서**.
> 서비스·시크릿·전역 설정을 JSON으로 만들어 넣기 → **그 문서**.

**v1.4.0 이하 서버에 붙이는 경우라면 아래 절들을 계속 읽으세요.**

---

## v1.5.0 갱신 고지 — **API 변경 0입니다. 이 절은 30초면 끝납니다**

**v1.4.0 → v1.5.0에서 연동 앱이 고칠 것은 아무것도 없습니다.**

엔드포인트 · 실제 호출 URL · 메서드 · HTTP 상태 코드 · 응답 봉투 · 에러 코드 31개 ·
`user` 33필드 · `session` 11필드 · `payload` 구조 · 3축 등급과 랭크 정수 · 게이트 판정 규칙 ·
레이트리밋 한도 · 쿠키 동작 — **하나도 바뀌지 않았습니다.**

**근거:** 이 릴리스가 편집한 서버 PHP는 5개 파일이고 **전부 주석입니다.**
주석·공백을 뺀 **실행 토큰 diff가 5개 전부 0**입니다(v1.4.0 폴더와 직접 비교, 재현 가능).
동작이 바뀔 수 있는 자리가 없습니다.

| 이 릴리스가 한 것 | 연동 앱이 알아야 하나 |
|---|---|
| **`tests/` 회귀 테스트 스위트 추가** (3티어 99케이스, 의존성 0) | **아니오.** 저장소에만 있고 서버에 올라가지 않습니다 |
| 서버 PHP 5개 파일의 **주석 정정** (팬텀 함수명 1 · 낡은 줄 번호 인용 6 · 사실 오류 1) | **아니오.** 실행 토큰 diff 0 |
| **마이그레이션 `0003`** — `login_logs.result`의 **컬럼 COMMENT**를 두 설치 경로에서 같게 만듦 | **아니오.** DB 메타데이터입니다. **다만 서버 운영자는 `server/migrate.php`를 한 번 더 돌려야 합니다** |
| 자산 URL의 `?v=` 값이 `1.4.0` → **`1.5.0`** | **예 — 한 가지만.** 당신 앱의 `<script src>`에 붙인 `?v=` 값을 서버 배포본에 맞추세요 |

**그러므로 v1.0.0 ~ v1.4.0용으로 쓴 연동 코드는 수정 없이 v1.5.0에서 동작합니다.**
할 일은 `?v=` 값을 맞추는 것 하나입니다(14-11절 (49)).

> **v1.3.0 이하 서버에 붙이는 경우라면 아래 「v1.4.0 갱신 고지」를 계속 읽으세요.**
> v1.3.0 → v1.4.0에는 실제로 동작이 바뀐 자리가 셋 있고, 그 절이 그것을 다룹니다.
> **v1.4.0 서버에 붙이는 경우에는 이 절에서 멈추고 [0. 30초 요약](#0-30초-요약)으로 가도 됩니다.**

---

## v1.4.0 갱신 고지 — 읽기 전에 이 절만 먼저 보세요

**세 가지입니다. ①은 연동 코드에 영향이 있고, ②는 이 문서를 읽는 방법이고, ③은 붙을 서버가 구버전일 때입니다.**

### ① API 동작 변경 1건 — 「변경 0」이 아닙니다

응답 봉투 · 최상위 키 · 에러 코드 목록(31개) · 인증 흐름은 **바뀌지 않았습니다.** 표면은 **추가만** 됐습니다.
그런데 **동작이 바뀐 자리가 세 곳** 있습니다. 셋 다 정상 연동 앱을 깨지 않지만, 알고 있어야 합니다.

| # | 무엇이 | v1.3.0 | v1.4.0 |
|---|---|---|---|
| 1 | **존재하지 않는 `code`** + 틀린 `service_secret`으로 `exchange` | `401 SSO_CLIENT_AUTH_FAILED` | **`400 SSO_CODE_INVALID`** |
| 2 | 서비스가 `disabled`일 때 `GET /api/auth/session`의 `payload` | 시크릿 평문 | **`{}`** (HTTP 200 · 세션 유지) |
| 3 | `POST /api/auth/refresh.php`의 **429 `RATE_LIMITED`** | 전역 `api:ip` 300회/60초만 있었다 | **전용 레이트리밋이 생겼습니다** — IP 120회/60초 · **계정 60회/3600초** |

> **3번을 반드시 처리하세요.** 5절 (4) `refresh` 항목의 오류 목록에 **`RATE_LIMITED`를 적어 두었습니다** —
> 종전에는 빠져 있었고, 그 자리를 채우려고 동결된 5절을 이 라운드에서 부분적으로 열었습니다(연 자리 전부는 아래 ②).
> **429를 받은 refresh 토큰은 죽지 않습니다**(실측). 그러므로 **재로그인으로 보내면 안 됩니다** —
> 응답 `data.retry_after`(초)만큼 기다린 뒤 **같은 토큰으로** 다시 시도하면 200이 됩니다.
> 정상 앱이 이 한도에 닿는 경우는 갱신 타이머가 폭주할 때와 `session_ttl`이 아주 짧은 배포입니다.
> 계정 한도가 시간당 60회이므로 기본값(`SESSION_TTL` 3600초)에서는 기기 10대까지 여유가 있습니다.
> 처리 코드는 10-2절 `RATE_LIMITED` 행의 지침(즉시 재시도 루프 금지)과 같습니다.

**`SSO_CLIENT_AUTH_FAILED`는 없어지지 않았습니다.** *유효한* `code` + 틀린 `service_secret`에서는
종전과 똑같이 401로 나옵니다. **그 분기를 지우지 마세요.**
상세는 [「v1.3.0 → v1.4.0 에서 무엇이 바뀌었나」](#v130--v140-에서-무엇이-바뀌었나-연동-관점) 절입니다.

### ② 6절 · 10절 본문의 숫자 3개는 v1.3.0 값에서 멈춰 있습니다 (5절은 이 라운드에서 일부 열었습니다)

**6절 · 10절 본문은 의도적으로 동결돼 있습니다**(리더 판정 D-61). 이미 여러 연동 앱이 그 절을
인용하고 있어, 절 구조와 문장을 바꾸면 인용이 깨집니다. **대신 낡은 숫자를 여기 모아 보정합니다.**

**5절은 v1.4.0 마감 라운드에서 예외적으로 열렸습니다**(리더 판정 D-178). 동결의 목적은 레퍼런스의
안정성이었는데, 이 라운드가 `refresh`에 새 레이트리밋을 걸면서 **동결이 5절을 능동적으로 틀리게 만들고
연동 앱이 정상 세션을 강제 로그아웃하는 데까지 갔기** 때문입니다. **열린 자리는 정확히 넷입니다** —
① (4) `refresh` 항목의 오류 목록에 `RATE_LIMITED` 추가 + 레이트리밋 수치 표기,
② 5절 제목과 5-6절 표 제목의 계수 기준 표기,
③ 5-6절 요약표에 v1.4.0 신설 2행(46 `GET /api/health.php` · 47 `logs.php?type=secret_access`) 추가,
④ 그 두 행에 상세 항목이 없다는 안내 1문단.
**(1)~(45) 항목의 요청·응답 JSON, 나머지 오류 목록, 절·항목 번호는 한 글자도 바뀌지 않았습니다.**
6절·10절은 열지 않았습니다.

| 그 절에 적힌 값 | 어디 | **v1.4.0의 실제 값** |
|---|---|---|
| 관리자 `21개` | 5-5절 제목 | **22.** `logs.php?type=secret_access`가 늘었습니다. 5-5절 제목의 21은 **그 절에 상세가 있는 항목 수**이고, 새 1건은 5-6절 요약표 47행에만 있습니다 |
| `login_logs.result` **7종** | 6-7절 | **11종.** `fail_client_auth` · `fail_migrate_auth` · `fail_refresh_reuse` · `fail_refresh_revoked`가 늘었습니다 |
| DB 테이블 **15개** | 6-8절 제목 | **17개.** `schema_migrations` · `secret_access_logs`가 늘었습니다 |

**그 절들의 나머지 내용은 v1.4.0에서도 그대로 맞습니다** — 요청·응답 JSON 필드, 에러 코드 31개,
`user` 33필드 / `session` 11필드, 게이트 판정 규칙은 하나도 바뀌지 않았습니다.
**늘어난 것을 빼면 5절·6절·10절을 그대로 믿어도 됩니다.**

> **6-7절의 `fail_ratelimit` 설명 한 줄은 v1.4.0에서 사실이 아니게 됐습니다.**
> 그 절은 "실제로 기록되지 않습니다"라고 적고 있는데, **v1.4.0부터 기록됩니다.**
> 자격증명·SSO 경로(`login` · `sso/authorize` · `auth/exchange`)에서 한도를 처음 넘은
> 그 한 번만 남습니다. 연동 앱이 읽는 값이 아니라 관리자 로그의 값입니다.

### ③ 붙을 서버가 v1.3.0 이하라면 — 헛걸음을 막습니다

**먼저 서버 버전을 확인하세요. 이것이 이 문서를 쓸 수 있는지 판정하는 유일한 방법입니다.**

```bash
curl -sS '<<설치루트>>/public/version.txt'
```

| 출력 | 무엇을 해야 하나 |
|---|---|
| `1.5.0_20260901` | **이 문서가 대상으로 하는 버전입니다.** 그대로 쓰세요 |
| `1.4.0_20260901` | **연동 관점에서 v1.5.0과 동일합니다**(API 변경 0 — 위 「v1.5.0 갱신 고지」). 이 문서를 그대로 쓰세요. `?v=` 값만 `1.4.0`으로 맞추면 됩니다 |
| `1.3.0_20260828` · `1.2.0_20260828` | **v1.3.0 이하 서버입니다.** 이 문서를 그대로 써도 **연동 코드는 동작합니다** — 아래 표의 4가지만 다릅니다 |
| `404` | v1.1.0 이하입니다(`version.txt`는 v1.2.0 신설). 연동 코드는 동일합니다 |
| 그 밖의 값 | 이 문서보다 새로운(또는 다른) 배포본입니다. 그 배포본에 함께 들어 있는 같은 이름의 문서를 쓰세요 |

**v1.3.0 이하 서버에서 다른 것 4가지 — 이것만 알면 됩니다.**

| 무엇 | v1.3.0 이하에서 |
|---|---|
| `GET /api/health.php` | **없습니다 — 404.** 살아 있는지 보려면 `GET /api/auth/csrf.php`가 200인지 보세요 |
| `exchange`의 에러 코드 | 존재하지 않는 `code` + 틀린 secret이 **401 `SSO_CLIENT_AUTH_FAILED`** 입니다(v1.4.0은 400). **두 코드를 모두 처리하면 양쪽에서 동작합니다** |
| 서비스가 `disabled`일 때 `GET /api/auth/session` | **시크릿이 그대로 나옵니다.** 당신 앱이 고칠 것은 없지만, 그 서버는 서버 쪽 결함을 안고 있습니다 — 운영자에게 v1.4.0 이상으로 올리라고 알리세요 |
| `GET /api/admin/secrets`의 항목 키 | **13개**입니다(v1.4.0은 `allow_public`이 더해져 14개). 관리자 API를 쓰는 경우만 해당합니다 |

**그 밖에는 전부 같습니다.** 엔드포인트 경로 · 요청/응답 JSON · 3축 등급 · `payload` 구조 ·
`ulogin-client.js` 사용법은 v1.0.0부터 v1.4.0까지 동일합니다.

---

## 0. 30초 요약

### 이게 뭔가

로컬 HTML 앱이나 데스크톱 앱의 JavaScript에 **OpenAI API 키 같은 시크릿을 하드코딩하지 않기 위한** PHP + MySQL 인증 서버입니다.
로그인만 이 서버로 분리하고, **인증에 성공한 사용자에게만** 서비스 시크릿(API 키 등)을 JSON 응답 본문으로 내려줍니다.

```
[내 로컬 앱]  ──로그인 필요──▶  [ulogin 서버]  ──자격검증──▶  [MySQL]
     ▲                                │
     └────── code ──▶ exchange ──────┘
            응답에 access_token + payload.ai_api_key 포함
     이후 앱이 payload.ai_api_key 로 AI API 직접 호출 (메모리에만 보관)
```

### 가장 짧은 성공 경로 (복붙 가능, 이대로 돌아갑니다)

앱 페이지 `app.html`과 콜백 페이지 `callback.html` 두 개만 있으면 됩니다.

**`app.html`**

```html
<!DOCTYPE html>
<html lang="ko">
<head><meta charset="utf-8"><title>내 AI 앱</title></head>
<body>
<button id="btn-login">로그인</button>
<pre id="out">로그인이 필요합니다.</pre>

<!-- <<설치루트>> = ulogin 을 업로드한 위치. 예: https://example.com/ulogin
     이 값은 ulogin 관리자에게 물어보거나, 관리자 콘솔 주소에서 /public/admin.html 을 뗀 것입니다.
     {버전} 은 당신이 연동하는 서버에 올라간 배포본의 버전입니다. 자리표시자이므로 그대로 두지 말고
     실제 값으로 바꾸세요 — 값은 <<설치루트>>/public/version.txt 의 첫 줄에서 _ 앞부분입니다
     (확인 방법은 14-11절 (49)).
     ?v= 는 브라우저 캐시 무효화용입니다. 지우지 마세요 — 지우면 서버가 새 버전으로
     갱신돼도 브라우저가 옛 ulogin-client.js 를 무기한 계속 씁니다(14-11절). -->
<script src="<<설치루트>>/public/assets/ulogin-client.js?v={버전}"></script>
<script>
  var ulogin = new UloginClient({
    server:      '<<설치루트>>',                        // 예: 'https://example.com/ulogin'
    serviceId:   '<<서비스ID>>',                        // 예: 'local-ai-app'. 관리자 콘솔 → 서비스 탭에서 등록한 값
    redirectUri: location.origin + '/callback.html'     // 관리자 콘솔 → 서비스 탭 → redirect_whitelist 에 등록한 값과 완전 일치해야 함
  });

  document.getElementById('btn-login').onclick = function () {
    ulogin.login();          // ulogin 로그인 화면으로 이동한다
  };

  if (ulogin.isLoggedIn()) {
    ulogin.getSession().then(function (data) {
      // data = { user, session, expires_in, payload }
      var key = data.payload.ai_api_key;               // 등급이 모자라면 이 키는 아예 없다(undefined)
      document.getElementById('out').textContent =
        data.user.username + ' 님 · 포인트 ' + data.user.points +
        ' · AI 키 ' + (key ? '수신됨(' + key.slice(0, 6) + '…)' : '없음(등급 부족 또는 미가입)');
    }).catch(function (e) {
      document.getElementById('out').textContent = '세션 오류: ' + e.code;
    });
  }
</script>
</body>
</html>
```

**`callback.html`**

```html
<!DOCTYPE html>
<html lang="ko">
<head><meta charset="utf-8"><title>로그인 처리 중</title></head>
<body>
<p id="msg">로그인 처리 중입니다…</p>
<!-- ?v= 는 캐시 무효화용입니다. 지우지 마세요 (14-11절).
     {버전} 은 자리표시자입니다 — <<설치루트>>/public/version.txt 의 첫 줄에서 _ 앞부분으로 바꾸고,
     위 app.html 과 같은 값을 쓰세요. -->
<script src="<<설치루트>>/public/assets/ulogin-client.js?v={버전}"></script>
<script>
  var ulogin = new UloginClient({
    server:      '<<설치루트>>',
    serviceId:   '<<서비스ID>>',
    redirectUri: location.origin + '/callback.html'
  });
  ulogin.handleCallback().catch(function (e) {
    document.getElementById('msg').textContent = '로그인 실패: ' + e.code + ' — ' + e.message;
  });
</script>
</body>
</html>
```

**이 시점에 정상이면 무엇이 보이는가:** `app.html`에서 로그인 버튼을 누르면 ulogin 로그인 화면으로 이동하고, 아이디·비밀번호를 넣으면 `callback.html`을 거쳐 `app.html`로 되돌아오며, `사용자명 님 · 포인트 100 · AI 키 수신됨(sk-abc…)` 같은 문자열이 보입니다.
**실패하면 확인할 것:** 브라우저 콘솔의 에러 코드. `SSO_INVALID_REDIRECT`면 `redirectUri`가 화이트리스트와 한 글자라도 다른 것이고, `SSO_INVALID_SERVICE`면 `serviceId`가 없거나 `disabled` 상태이며, `AI 키 없음`이면 8절(시크릿)의 등급 게이트 또는 서비스 가입 문제입니다.

### 3줄 요약

1. 모든 API 응답은 **`{"ok":bool,"data":{...},"error":{...}}` 봉투**로 감싸여 옵니다. 항상 `data`를 벗겨서 쓰세요.
2. 시크릿(`payload`)은 **`login` / `exchange` / `refresh` / `session` 4개 응답에만** 실립니다. `GET /api/me`에는 없습니다.
3. 모든 URL은 **`.php` 확장자와 쿼리 파라미터**로 호출합니다. 경로 세그먼트(`/users/{uuid}`)는 이 구현에 존재하지 않습니다.

---

## 1. 이 시스템의 정체

### 1-1. 무엇을 해결하는가

로컬 HTML 앱(파일 하나로 도는 AI 챗봇, 사내 도구, Electron 앱 등)에서 OpenAI·Anthropic 같은 AI API를 호출하려면 API 키가 필요합니다. 그런데 그 키를 JS 소스에 넣으면 **앱을 받은 사람 누구나 키를 꺼내 쓸 수 있습니다.**

ulogin은 이 문제만 해결합니다.

- 로그인 기능을 서버(PHP + MySQL)로 분리한다
- 로그인에 성공하고, **등급 조건을 만족하는 사용자에게만** 시크릿을 JSON 응답으로 내려준다
- 앱은 그 키를 **메모리에만** 두고 AI API를 직접 호출한다
- 호출 1회당 포인트를 차감해 사용량을 통제한다

### 1-2. 무엇이 아닌가 (중요)

| 아니다 | 설명 |
|---|---|
| 범용 OAuth 2.0 / OIDC 서버가 아니다 | authorization code 흐름을 **닮았을 뿐** 표준이 아닙니다. `/.well-known/openid-configuration`, `id_token`(JWT), `scope`, `/token` 표준 엔드포인트가 **없습니다.** OAuth 라이브러리(oidc-client, passport-oauth2 등)를 붙이면 실패합니다. 이 문서의 방식대로 직접 HTTP 호출을 쓰세요 |
| AI API 프록시가 아니다 | ulogin은 OpenAI를 대신 호출해 주지 않습니다. 키를 앱에 **넘겨줄 뿐**이고, AI 호출은 앱이 직접 합니다 |
| JWT 서버가 아니다 | `access_token`은 64자 hex 난수 문자열입니다. 서명도 payload도 없습니다. 디코딩할 수 없고, 서버 DB 조회로만 검증됩니다 |
| 결제 시스템이 아니다 | `billing_tier`는 등급 라벨일 뿐이고 결제 연동은 없습니다. 관리자가 수동으로 등급과 만료일을 지정합니다 |
| 다중 테넌트 SaaS가 아니다 | 하나의 설치본이 여러 **서비스**를 `service_id`로 구분해 관리합니다. 사용자 계정은 설치본 전체에서 공유됩니다 |

### 1-3. 용어 정의표

| 용어 | 정의 |
|---|---|
| **설치 루트** | ulogin 폴더를 업로드한 위치의 URL. 예: `https://example.com/ulogin`. 이 아래에 `server/`, `public/`, `examples/`가 있습니다. 문서 전체에서 `<<설치루트>>`로 표기합니다 |
| **service_id** | 이 서버에 등록된 **연동 앱 1개**의 문자열 키. 예: `local-ai-app`. 형식은 `^[a-z0-9][a-z0-9\-_]{2,63}$`. **생성 후 변경 불가**입니다 |
| **payload** | 로그인 응답에 실려 오는 **서비스 시크릿 묶음**. 예: `{"ai_api_key":"sk-proj-xxxx","api_base":"https://api.openai.com/v1","model":"gpt-4o-mini"}`. 이 시스템의 존재 이유입니다 |
| **시크릿(secret)** | `service_id`별로 등록된 키-값 1건. `app_ulogin_service_secrets` 테이블에 AES-256-CBC로 암호화되어 저장됩니다 |
| **게이트(gate)** | 시크릿마다 붙일 수 있는 최소 등급 조건(`min_account_level` / `min_billing_tier` / `min_role`). 조건 미달이면 그 키가 payload에서 **조용히 빠집니다** |
| **access_token** | API 인증용 64자 hex 문자열. `Authorization: Bearer {값}`으로 보냅니다. 기본 수명 3600초 |
| **refresh_token** | access_token 재발급용 64자 hex 문자열. 1회용이며 사용하면 회전(새 값 발급)됩니다. 기본 수명 1209600초(14일) |
| **session_id** | 세션의 **공개 식별자** 64자 hex. 기기 목록 표시와 세션 개별 종료에 씁니다. **이 값으로는 인증할 수 없습니다** |
| **auth code** | SSO 복귀 URL에 붙어 오는 1회용 교환 코드 64자 hex. 수명 60초. `POST /api/auth/exchange.php`로 토큰과 payload로 바꿉니다 |
| **3축 등급** | `account_level`(계정등급) / `role`(역할) / `billing_tier`(결제등급). 각각 `*_rank` 정수를 함께 제공합니다 |
| **서비스 가입** | `app_ulogin_user_services` 테이블의 (사용자 × 서비스) 행. 이 행이 있어야 payload를 받습니다 |
| **effective 등급** | 전역 등급 → 서비스별 오버라이드 → 결제 만료 강등을 순서대로 적용한 최종 등급. API가 반환하는 등급은 전부 effective 값입니다 |
| **관리자 콘솔** | `<<설치루트>>/public/admin.html`. 서비스·시크릿·회원·설정을 관리하는 웹 화면 |

### 1-4. 기술 전제

| 항목 | 값 |
|---|---|
| 서버 | **PHP 7.2+** / PDO_MySQL / OpenSSL / mbstring. Composer 사용 안 함. 7.2부터 8.x까지 같은 코드가 동작합니다 |
| DB | MySQL. 테이블 접두사 `app_ulogin_` (설치 시 변경 가능), 테이블 **17개** (v1.3.0은 15개. 6-8절 표는 15개에서 동결돼 있습니다 — 문서 머리 ②) |
| 시각 | **모든 시각은 UTC**로 저장되고 ISO8601 `"2026-08-26T04:12:00Z"` 형식으로 반환됩니다 |
| 문자셋 | `utf8mb4`. 응답은 `Content-Type: application/json; charset=utf-8` |
| 라우팅 | 파일 = URL. `mod_rewrite` 없이도 `.php` 경로로 동작합니다 |
| 인증 전송 | `Authorization: Bearer` 헤더(우선) 또는 `X-Auth-Token` 헤더(CGI 폴백) 또는 `ULOGIN_SID` 쿠키 |

> **서버의 PHP 버전은 당신이 쓸 코드에 영향을 주지 않습니다.** API 응답 형태·필드·에러 코드가
> 버전에 따라 달라지지 않습니다. 서버가 PHP 7.2든 8.3이든 이 문서의 모든 예제가 그대로 동작합니다.
> 단 하나 알아 둘 것은 **PHP 7.2 호스팅에서의 쿠키 속성**입니다 — 14절 함정 **(46)** 을 보세요.
> (쿠키는 ulogin 자체 웹 UI가 쓰는 것이고, Bearer 토큰으로 연동하는 당신 앱과는 무관합니다.)

---

## 2. 사전 준비 — 관리자 콘솔에서 무엇을 등록하는가

연동 코드를 쓰기 전에 **ulogin 관리자가 아래 3가지를 등록해야 합니다.** 당신(AI 에이전트)이 관리자 계정을 가지고 있다면 직접 하고, 아니면 사용자에게 아래 절차를 그대로 전달하세요.

### 2-0. 설치 루트와 URL 구조 (가장 흔한 실패 지점)

ulogin은 **버전 폴더 내용을 그대로 업로드**하는 구조입니다. 그 업로드 위치가 **설치 루트**입니다.

```
<<설치루트>>/                       ← 예: https://example.com/ulogin
├── server/
│   ├── api/       ← 모든 API 엔드포인트 (.php 파일)
│   ├── core/      ← 내부 라이브러리 (웹 직접 접근 차단됨)
│   ├── config.php ← 설정 (웹 접근 차단됨)
│   └── storage/   ← 메일 로그 (웹 접근 차단됨)
├── public/        ← 사람이 보는 화면 (login.html, mypage.html, admin.html …)
│   └── assets/    ← ulogin-client.js, api.js, ui.js, style.css
└── examples/      ← 데모 앱
```

| 자원 | 실제 URL (이 형태 그대로 호출하세요) |
|---|---|
| API | `<<설치루트>>/server/api/auth/login.php` 처럼 **`server/api/` + `.php`** |
| 로그인 화면 | `<<설치루트>>/public/login.html` |
| 회원가입 화면 | `<<설치루트>>/public/register.html` |
| 비밀번호 찾기 | `<<설치루트>>/public/forgot.html` |
| 비밀번호 재설정 | `<<설치루트>>/public/reset.html` (메일 링크가 `?token=` 을 붙여 옵니다) |
| 이메일 인증 | `<<설치루트>>/public/verify.html` (메일 링크가 `?token=` 을 붙여 옵니다) |
| 마이페이지 | `<<설치루트>>/public/mypage.html` |
| 관리자 콘솔 | `<<설치루트>>/public/admin.html` |
| 연동 클라이언트 JS | `<<설치루트>>/public/assets/ulogin-client.js?v={버전}` — `?v=` 는 캐시 무효화용입니다. `{버전}` 은 자리표시자이고, 넣을 값은 `<<설치루트>>/public/version.txt` 첫 줄의 `_` 앞부분입니다 (14-11절 (49)) |
| 데모 앱 | `<<설치루트>>/examples/local-ai-app.html` |

**구체 예시** — 설치 루트가 `https://example.com/ulogin`이면 로그인 API는 정확히
`https://example.com/ulogin/server/api/auth/login.php` 입니다.

> **`.php`를 빼면 안 되는 이유:** 이 구현은 `mod_rewrite`가 없는 공용 호스팅에서도 돌아가도록 **파일 경로 = URL 경로**로 만들어졌습니다. `.htaccess`의 rewrite 규칙이 살아 있는 환경에서는 `.php`를 생략할 수 있지만, 그것은 **편의 기능**이고 보장되지 않습니다. 항상 `.php`를 붙이세요.
>
> **경로 세그먼트를 쓰면 안 되는 이유:** `/api/admin/users/{uuid}` 같은 동적 세그먼트도 rewrite에 의존합니다. 그래서 이 구현은 **전부 쿼리 파라미터**입니다: `/api/admin/user_detail.php?uuid=...`.

### 2-1. 서비스 등록 (필수)

**화면:** `<<설치루트>>/public/admin.html` → 상단 탭 **[서비스]** → 우측 **[+ 서비스 추가]** 버튼

입력할 항목:

| 필드 | 설명 | 예시 |
|---|---|---|
| `service_id` | 연동 앱의 고유 키. **생성 후 변경 불가** | `local-ai-app` |
| `service_name` | 화면에 보일 이름 | `로컬 AI 앱` |
| `description` | 설명(선택) | `데스크톱 AI 챗봇` |
| `redirect_whitelist` | **복귀 주소 목록.** 줄바꿈으로 여러 개. `origin + path` 완전 일치 비교 | `http://localhost:5173/callback.html` |
| `allowed_origins` | **CORS 허용 출처.** `scheme://host[:port]`만, 경로 금지 | `http://localhost:5173` |
| `auto_join` | 켜면 최초 로그인 시 서비스 가입 행이 자동 생성됨. **끄면 payload가 빈 객체가 됩니다** | 켬 |
| `allow_register` | 이 서비스 경유 회원가입 허용 | 켬 |
| `default_account_level` | 자동 가입 시 **최소 보장 등급**(올려주기만 함, 강등 없음). 비워도 됩니다 | 비움 |
| `session_ttl` | access_token 수명(초). 60~604800 | `3600` |
| `refresh_ttl` | refresh_token 수명(초). 300~31536000 | `1209600` |
| `payload_fields` | 예약 필드. v1.0.0에서는 비워 둡니다 | 비움 |
| `is_confidential` | 켜면 `exchange` 호출 시 `service_secret`이 필수가 됨. **브라우저 앱은 반드시 끕니다** | 끔 |
| `status` | `active` 또는 `disabled` | `active` |

> **`service_id`는 왜 변경 불가인가:** 세션 행, 시크릿, 서비스 가입 매핑, 로그인 로그가 전부 이 **문자열 키**를 참조합니다. 바꾸면 그 참조가 전부 끊깁니다. 이름을 바꾸고 싶으면 **새 `service_id`로 만들고 기존 것을 `disabled`로 내리세요.** 서버도 이 요청을 거부합니다(`VALIDATION_FAILED`: "서비스 ID 는 변경할 수 없습니다").

**`redirect_whitelist` 작성 규칙 (실패 1순위):**

- **`origin + path` 완전 일치**입니다. 접두사 매칭이 아닙니다.
- 쿼리스트링(`?`)과 프래그먼트(`#`)를 넣을 수 없습니다. 서버가 거부합니다.
- 기본 포트는 정규화됩니다: `https://a.com:443/cb.html` = `https://a.com/cb.html`. 그 외 포트는 정확히 일치해야 합니다.
- 트레일링 슬래시 하나만 다른 것도 정규화 규칙상 `/`만 있는 경로는 빈 경로로 취급되지만, `/callback.html`과 `/callback.html/`은 **다릅니다.**
- 앱이 `http://localhost:5173/callback.html`에서 도는데 화이트리스트에 `http://127.0.0.1:5173/callback.html`만 있으면 **호스트가 달라서 실패합니다.**

**`allowed_origins` 작성 규칙:**

- `scheme://host[:port]` 형태만. 경로가 있으면 서버가 거부합니다.
- **`file://`로 연 페이지는 Origin이 `null`이라 CORS가 통과하지 않습니다.** 로컬 웹서버로 여세요. 예: `python -m http.server 5173` 으로 띄운 뒤 `http://localhost:5173/app.html` 로 접속합니다.
- 문자열 `"null"`을 화이트리스트에 넣는 것은 기술적으로 허용되지만, **어떤 로컬 파일이든 통과하게 되므로 넣지 마세요.**

### 2-2. 시크릿 등록 (필수 — 이게 이 시스템의 목적)

**화면:** `<<설치루트>>/public/admin.html` → 상단 탭 **[시크릿]** → 상단 서비스 선택 드롭다운에서 `<<서비스ID>>` 선택 → **[+ 시크릿 추가]** 버튼

| 필드 | 설명 |
|---|---|
| `secret_key` | payload에 실릴 키 이름. 형식 `^[a-z0-9][a-z0-9_]{1,99}$`. 예: `ai_api_key` |
| `secret_value` | 실제 값. AES-256-CBC로 암호화 저장됩니다 |
| `value_type` | `string`(기본) / `json` / `url`. `json`이면 payload에 **파싱된 객체**로 실립니다 |
| `min_account_level` | 게이트. `guest` `basic` `verified` `vip` `staff` 중 하나 또는 비움 |
| `min_billing_tier` | 게이트. `free` `lite` `pro` `enterprise` 중 하나 또는 비움 |
| `min_role` | 게이트. `user` `manager` `admin` `superadmin` 중 하나 또는 비움 |
| `is_masked` | 콘솔 표시 힌트일 뿐입니다. **보안 경계가 아닙니다** |
| `sort_order` | payload 조립 순서. -9999~9999 |
| `status` | `active`면 payload에 실립니다. `disabled`면 제외됩니다 |

**게이트를 반드시 걸어야 하는 이유 (금지 + 이유 + 대안):**

- **금지:** 민감한 키(`ai_api_key`, `*_secret`, `*_token`, `*_password`, `*_credential`)를 게이트 없이 등록하지 마세요.
- **이유:** 게이트가 비어 있으면 `build_payload()`가 등급 검사를 건너뛰므로, **그 서비스에 가입한 모든 사용자**가 그 키를 받습니다. `allow_register`가 켜져 있고 `auto_join`도 켜져 있으면, 아무나 가입만 하면 AI 키를 받게 됩니다. 서버를 분리한 의미가 사라집니다.
- **대안:** `min_billing_tier = lite` 이상을 거세요. 서버가 이것을 강제합니다 — 민감한 이름의 키를 게이트 없이 등록하려 하면 **400 `VALIDATION_FAILED`**로 거부됩니다.

**랭크 0 값은 게이트로 인정되지 않습니다.** `min_billing_tier='free'`, `min_account_level='guest'`, `min_role='user'`는 전부 랭크 0이라 모든 사용자가 통과합니다. 서버는 이것을 "게이트 없음"으로 판정하고 똑같이 거부합니다.

**게이트 없는 민감 시크릿은 관리자 콘솔로 만들 수 없습니다.** 콘솔 폼에 그 옵션(`allow_public`)이 없습니다 — 클릭 한 번으로 도달할 수 있으면 안 되기 때문에 의도적으로 뺐습니다. 정말로 공개하려면 **API를 직접 호출**해야 합니다:

```bash
curl -X POST '<<설치루트>>/server/api/admin/secrets.php?service_id=<<서비스ID>>' \
  -H 'Authorization: Bearer <<관리자ACCESS_TOKEN>>' \
  -H 'Content-Type: application/json' \
  -d '{"secret_key":"ai_api_key","secret_value":"sk-proj-xxxxxxxxxxxxxxxx","allow_public":true}'
```

이 경우 `admin_logs`에 `allow_public: true` 사실이 기록됩니다(값 자체는 기록되지 않습니다).

**비민감 키는 게이트 없이 등록하세요.** `api_base`, `model`, `endpoint`, `org_id` 같은 설정값은 민감 이름 판정에 걸리지 않으므로 게이트 없이 저장됩니다. 전부 잠그면 데모가 돌지 않아 운영자가 게이트를 통째로 풀어 버리게 되므로, 이 구분이 필요합니다.

**권장 등록 예시(3건):**

| secret_key | value_type | min_billing_tier | 값 예시 |
|---|---|---|---|
| `ai_api_key` | `string` | `lite` | `sk-proj-xxxxxxxx` |
| `api_base` | `url` | (비움) | `https://api.openai.com/v1` |
| `model` | `string` | (비움) | `gpt-4o-mini` |

이렇게 하면 `free` 등급 사용자는 `{"api_base":"https://api.openai.com/v1","model":"gpt-4o-mini"}`만 받고, `lite` 이상만 `ai_api_key`를 추가로 받습니다.

### 2-3. 테스트 계정 등급 올리기 (필수)

게이트를 `min_billing_tier=lite`로 걸었다면, 테스트 계정의 결제등급을 올려야 키가 나옵니다.

**화면:** `<<설치루트>>/public/admin.html` → 탭 **[회원]** → 대상 계정 클릭 → 상세 패널에서 `billing_tier`를 `lite` 이상으로, `billing_expires_at`을 미래 날짜 또는 비움(무기한)으로 → 저장

**이 시점에 정상이면 무엇이 보이는가:** 그 계정으로 로그인하면 `payload`에 `ai_api_key`가 포함됩니다.
**실패하면 확인할 것:** (a) `billing_expires_at`이 과거면 판정 시점에 `free`로 강등됩니다. (b) 그 사용자가 그 서비스에 **가입**되어 있어야 합니다. 미가입이면 payload 가 빈 객체가 됩니다 — 8-3절에 확인 방법이 있습니다.

### 2-4. 값이 어디서 오는지 정리

| 자리표시자 | 어디서 얻는가 |
|---|---|
| `<<설치루트>>` | ulogin 관리자에게 문의. 또는 관리자 콘솔 URL `https://…/public/admin.html`에서 `/public/admin.html`을 뗀 나머지 |
| `<<서비스ID>>` | 관리자 콘솔 → [서비스] 탭 목록의 `service_id` 열. 새로 만들었다면 그때 입력한 값 |
| `<<복귀주소>>` | 내 앱의 콜백 페이지 절대 URL. 관리자 콘솔 → [서비스] → `redirect_whitelist`에 **똑같이** 등록되어 있어야 함 |
| `<<ACCESS_TOKEN>>` | `login` / `exchange` / `refresh` 응답의 `data.access_token` |
| `<<REFRESH_TOKEN>>` | 같은 응답의 `data.refresh_token` |
| `<<관리자ACCESS_TOKEN>>` | `role`이 `admin` 이상인 계정으로 `POST /api/auth/login.php` 한 응답의 `data.access_token` |
| `<<SERVICE_SECRET>>` | `is_confidential=true`인 서비스를 만들 때 입력했거나 서버가 생성해 준 값. 응답의 `data.service_secret_plain`에 **1회만** 나옵니다 |

---

## 3. 인증 방식 2가지 — 어느 것을 언제 쓰는가

### 3-1. 판단 기준 (이 표만 보고 고르세요)

| 상황 | 쓸 방식 |
|---|---|
| 브라우저에서 도는 외부 앱 (로컬 HTML, 다른 도메인 웹앱, Electron) | **A. SSO 리다이렉트** |
| 사용자에게 아이디·비밀번호를 직접 입력받아도 되는 신뢰 환경 (서버사이드 백엔드, CLI 도구, 배치 스크립트) | **B. 직접 로그인** |
| ulogin 서버 자신의 화면(`login.html` 등) | B (이미 구현되어 있음) |
| 모바일 앱 / 네이티브 앱 | A. 시스템 브라우저를 열고 커스텀 스킴은 쓸 수 없으므로 `http(s)` 콜백 페이지를 두세요 |

**핵심 차이:** A는 **비밀번호가 당신 앱을 거치지 않습니다.** B는 당신 앱이 비밀번호를 직접 받아 서버로 전달합니다. 외부 앱에는 A를, 자체 백엔드에는 B를 쓰세요.

### 3-2. 방식 A — SSO 리다이렉트 (권장)

```
앱 → <<설치루트>>/public/login.html?service_id=…&redirect_uri=…&state=…&code_challenge=…
   → (사용자가 ulogin 화면에서 로그인)
   → login.html 이 POST /api/sso/authorize.php 호출 → data.redirect_to 로 이동
   → <<복귀주소>>?code=…&state=…
   → 앱이 state 대조 후 POST /api/auth/exchange.php
   → { access_token, refresh_token, user, session, payload }
```

- **시크릿은 URL에 절대 실리지 않습니다.** URL에는 1회용 `code`만 옵니다. 실제 키는 `exchange` **POST 응답 본문**으로만 나갑니다. 이유: URL에 실으면 브라우저 히스토리·Referer 헤더·프록시 로그에 AI 키가 남습니다.
- `code` 수명은 **60초**, **1회용**입니다.
- `state`는 CSRF 방어입니다. **반드시 앱이 만들고, 복귀 시 대조하고, 불일치면 교환하지 마세요.**
- PKCE(`code_challenge` / `code_verifier`)는 **선택**입니다. 보내면 서버가 검증하고, 안 보내도 통과합니다. `S256`만 지원합니다(`plain` 금지).

### 3-3. 방식 B — 직접 로그인

```
앱(서버사이드) → POST /api/auth/login.php {username, password, service_id}
              → { access_token, refresh_token, user, session, payload }
```

한 번의 호출로 끝납니다. 브라우저 JS에서 이 방식을 쓰면 **당신 앱이 사용자 비밀번호를 보게 되므로** 외부 앱에서는 쓰지 마세요.

### 3-4. PKCE 제약 (브라우저 앱에서 걸리는 지점)

- PKCE 생성에는 `crypto.subtle.digest('SHA-256', ...)`가 필요합니다.
- `crypto.subtle`은 **secure context**(HTTPS 또는 `http://localhost`)에서만 존재합니다.
- **`file:///C:/…/app.html`로 연 페이지에서는 `crypto.subtle`이 없습니다.** `ulogin-client.js`는 이 경우 경고를 남기고 **PKCE 없이** 진행합니다(서버가 PKCE를 강제하지 않으므로 로그인은 성공합니다).
- 다만 `file://`은 **CORS도 막힙니다**(Origin이 `null`). 그러므로 로컬 웹서버로 여는 것이 사실상 필수입니다:

```bash
# 앱 파일이 있는 폴더에서
python -m http.server 5173
# 그다음 http://localhost:5173/app.html 로 접속
```

> **주의:** `python -m http.server`는 **PHP를 실행하지 못합니다.** 이것은 *당신의 앱*을 여는 용도입니다. ulogin 서버 자체는 PHP가 도는 호스팅에 이미 올라가 있어야 합니다.

---

## 4. SSO 플로우 전체 — 단계별 실제 요청/응답

### 4-1. 시퀀스

```
 내 앱                     ulogin login.html            ulogin API                  DB
   │                            │                           │                        │
 1 │ state·verifier 생성        │                           │                        │
   │ sessionStorage 저장        │                           │                        │
 2 │──── location.assign ──────▶│                           │                        │
   │   ?service_id&redirect_uri │                           │                        │
   │   &state&code_challenge    │                           │                        │
 3 │                            │─ GET /api/sso/validate.php▶│─ 서비스·화이트리스트 확인▶│
   │                            │◀──── 200 {서비스 정보} ────│                        │
 4 │                            │ (사용자가 ID/PW 입력)      │                        │
 5 │                            │─ POST /api/sso/authorize.php▶ 자격검증 + code 발급 ─▶│
   │                            │◀── 200 {redirect_to,code} ─│                        │
 6 │◀─ location.replace(redirect_to) ?code=…&state=… ───────│                        │
 7 │ state 대조 (불일치면 중단)  │                           │                        │
 8 │──────────────── POST /api/auth/exchange.php ──────────▶│─ code 소모 + 세션 발급 ─▶│
   │◀── 200 {access_token, refresh_token, user, session, payload} ───────────────────│
 9 │ payload.ai_api_key 로 AI API 직접 호출 (메모리에만 보관)  │                        │
```

### 4-2. 1단계 — state와 PKCE 생성 (앱이 한다)

```javascript
// state: 64자 hex 난수. sessionStorage 에 저장하고 복귀 시 대조한다.
function randomHex(byteLen) {
  var b = new Uint8Array(byteLen);
  crypto.getRandomValues(b);
  var s = '';
  for (var i = 0; i < b.length; i++) s += ('0' + b[i].toString(16)).slice(-2);
  return s;
}

// PKCE: verifier(43~128자) → S256 → base64url = challenge
// crypto.subtle 이 없으면(file:// 등) null 을 돌려주고 PKCE 없이 진행한다.
function makePkce() {
  var verifier = randomHex(32);   // 64자 hex — 43~128자 범위를 만족한다
  if (!(window.crypto && crypto.subtle && crypto.subtle.digest)) {
    return Promise.resolve({ verifier: null, challenge: null });
  }
  var bytes = new TextEncoder().encode(verifier);
  return crypto.subtle.digest('SHA-256', bytes).then(function (buf) {
    var arr = new Uint8Array(buf), bin = '';
    for (var i = 0; i < arr.length; i++) bin += String.fromCharCode(arr[i]);
    var b64 = btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    return { verifier: verifier, challenge: b64 };
  }).catch(function () {
    return { verifier: null, challenge: null };
  });
}
```

**저장해야 하는 값:** `state`, `code_verifier`, (선택) 복귀 후 돌아갈 앱 내부 경로.
**저장 위치:** `sessionStorage`. `localStorage`가 아닙니다 — 탭을 닫으면 사라져야 하는 일회성 값입니다.

### 4-3. 2단계 — 로그인 화면으로 이동

이동할 URL:

```
<<설치루트>>/public/login.html?service_id=<<서비스ID>>&redirect_uri=<<복귀주소>>&state={state}&code_challenge={challenge}
```

**실제 예시:**

```
https://example.com/ulogin/public/login.html?service_id=local-ai-app&redirect_uri=http%3A%2F%2Flocalhost%3A5173%2Fcallback.html&state=9f3c1a7e5d2b8046c1937fa5e0d4b8261c7f3a95e08d2b46c1937fa5e0d4b826&code_challenge=rZ8kQvN2sYb1TfXpJ7mA0hLdWcE4gUiO6nK9yBxSvQs
```

받는 쿼리 파라미터:

| 파라미터 | 필수 | 설명 |
|---|---|---|
| `service_id` | 예 | 등록된 `service_id` |
| `redirect_uri` | 예 | 화이트리스트에 등록된 복귀 주소. **URL 인코딩해서 붙이세요** |
| `state` | 아니오(사실상 필수) | 앱이 만든 난수. 복귀 시 그대로 돌아옵니다 |
| `code_challenge` | 아니오 | PKCE S256 challenge |
| `prompt` | 아니오 | 값을 읽기는 하지만 v1.0.0에서 동작 차이가 없습니다. 이 화면은 항상 자격증명을 다시 묻습니다 |

**이 시점에 정상이면 무엇이 보이는가:** ulogin 로그인 화면이 뜨고, 화면 어딘가에 요청한 서비스 이름이 표시됩니다.
**실패하면 확인할 것:** "허용되지 않은 복귀 주소입니다"가 뜨면 `redirect_uri`가 화이트리스트와 다릅니다. 화면이 그 값을 그대로 보여 주므로 관리자에게 그 문자열을 전달해 등록을 요청하세요. "알 수 없는 서비스입니다"면 `service_id`가 틀렸거나 서비스가 `disabled`입니다.

### 4-4. 3단계 — 로그인 화면의 사전 검증 (login.html이 자동으로 한다)

`login.html`은 화면을 그리기 전에 아래를 호출합니다. 당신 앱이 직접 호출할 일은 없지만, 진단할 때 유용합니다.

```bash
curl -s '<<설치루트>>/server/api/sso/validate.php?service_id=<<서비스ID>>&redirect_uri=http%3A%2F%2Flocalhost%3A5173%2Fcallback.html'
```

성공 응답 (전문):

```json
{
  "ok": true,
  "data": {
    "service_id": "local-ai-app",
    "service_name": "로컬 AI 앱",
    "redirect_uri_valid": true,
    "allow_register": true,
    "requires_pkce": false,
    "is_confidential": false,
    "site_name": "ulogin"
  },
  "error": null
}
```

실패 응답 (전문):

```json
{
  "ok": false,
  "data": null,
  "error": {
    "code": "SSO_INVALID_REDIRECT",
    "message": "허용되지 않은 복귀 주소입니다. 서비스 관리자에게 문의해 주세요.",
    "field": "redirect_uri"
  }
}
```

### 4-5. 5단계 — 자격 검증과 code 발급 (login.html이 자동으로 한다)

`login.html`이 제출 시 호출합니다. **당신 앱이 직접 호출하는 경우는 자체 로그인 UI를 만들 때뿐입니다.**

```bash
curl -s -X POST '<<설치루트>>/server/api/sso/authorize.php' \
  -H 'Content-Type: application/json' \
  -d '{
    "username": "hong",
    "password": "Passw0rd!",
    "service_id": "local-ai-app",
    "redirect_uri": "http://localhost:5173/callback.html",
    "state": "9f3c1a7e5d2b8046c1937fa5e0d4b8261c7f3a95e08d2b46c1937fa5e0d4b826",
    "code_challenge": "rZ8kQvN2sYb1TfXpJ7mA0hLdWcE4gUiO6nK9yBxSvQs",
    "code_challenge_method": "S256"
  }'
```

성공 응답 (전문):

```json
{
  "ok": true,
  "data": {
    "redirect_to": "http://localhost:5173/callback.html?code=7d1f9a2b3c4d5e6f708192a3b4c5d6e7f8091a2b3c4d5e6f708192a3b4c5d6e7&state=9f3c1a7e5d2b8046c1937fa5e0d4b8261c7f3a95e08d2b46c1937fa5e0d4b826",
    "code": "7d1f9a2b3c4d5e6f708192a3b4c5d6e7f8091a2b3c4d5e6f708192a3b4c5d6e7",
    "state": "9f3c1a7e5d2b8046c1937fa5e0d4b8261c7f3a95e08d2b46c1937fa5e0d4b826",
    "expires_in": 60
  },
  "error": null
}
```

**`redirect_to`를 서버가 만들어 줍니다. 직접 조립하지 마세요.** 프래그먼트(`#`)가 있는 복귀 주소에서 쿼리를 붙일 위치가 달라지는데, 그 처리가 서버에 이미 들어 있습니다. 조립 로직이 두 곳에 있으면 반드시 어긋납니다.

> **이 엔드포인트는 `POST /api/auth/login.php`와 레이트리밋 버킷을 공유합니다.** IP당 5분에 10회, 아이디당 5분에 5회. 두 경로 합산입니다.

### 4-6. 6단계 — 복귀

브라우저가 아래 주소로 이동합니다:

```
http://localhost:5173/callback.html?code=7d1f9a2b3c4d5e6f708192a3b4c5d6e7f8091a2b3c4d5e6f708192a3b4c5d6e7&state=9f3c1a7e5d2b8046c1937fa5e0d4b8261c7f3a95e08d2b46c1937fa5e0d4b826
```

### 4-7. 7단계 — state 대조 (앱이 반드시 한다)

```javascript
var params  = new URLSearchParams(location.search);
var code    = params.get('code');
var state   = params.get('state');
var saved   = sessionStorage.getItem('ulogin_state');
sessionStorage.removeItem('ulogin_state');

if (!code) throw new Error('code 가 없습니다.');
if (!saved || !state || state !== saved) {
  // 여기서 막지 못하면 공격자가 자기 code 를 밀어 넣어 세션을 주입할 수 있다.
  throw new Error('state 불일치 — 교환하지 않습니다.');
}
```

**금지 + 이유 + 대안:**
- **금지:** `state` 대조를 생략하고 바로 `exchange`를 호출하는 것.
- **이유:** 공격자가 자기 계정의 `code`가 붙은 링크를 피해자에게 열게 하면, 피해자의 앱이 **공격자 계정으로 로그인**됩니다(세션 주입). 이후 피해자가 앱에 입력하는 내용이 공격자 계정에 쌓입니다.
- **대안:** 위 코드처럼 `sessionStorage`에 저장한 값과 정확히 비교하고, 불일치면 교환하지 말고 로그인을 처음부터 다시 시작하세요.

### 4-8. 8단계 — code를 토큰 + payload로 교환

```bash
curl -s -X POST '<<설치루트>>/server/api/auth/exchange.php' \
  -H 'Content-Type: application/json' \
  -d '{
    "code": "7d1f9a2b3c4d5e6f708192a3b4c5d6e7f8091a2b3c4d5e6f708192a3b4c5d6e7",
    "service_id": "local-ai-app",
    "redirect_uri": "http://localhost:5173/callback.html",
    "code_verifier": "8c2ea1b0d9f8e7c6b5a4938271605f4e3d2c1b0a9f8e7d6c5b4a3928170605f4",
    "device_label": "Chrome / Windows"
  }'
```

성공 응답 (전문 — `user` 객체는 33필드 전부가 실제로 옵니다):

```json
{
  "ok": true,
  "data": {
    "user": {
      "uuid": "3f1b8c2a-7d4e-4a91-9c33-2b6e0f5a1d77",
      "username": "hong",
      "email": "hong@example.com",
      "name": "홍길동",
      "nickname": "길동",
      "phone": null,
      "avatar_url": null,
      "status": "active",
      "account_level": "verified",
      "account_level_rank": 20,
      "role": "user",
      "role_rank": 0,
      "billing_tier": "lite",
      "billing_tier_rank": 10,
      "billing_started_at": "2026-08-01T00:00:00Z",
      "billing_expires_at": "2026-12-31T23:59:59Z",
      "billing_active": true,
      "points": 100,
      "total_points_earned": 100,
      "total_points_spent": 0,
      "email_verified": true,
      "email_verified_at": "2026-08-02T05:11:00Z",
      "two_factor_enabled": false,
      "timezone": "Asia/Seoul",
      "locale": "ko-KR",
      "marketing_agreed": false,
      "created_at": "2026-08-01T09:00:00Z",
      "updated_at": "2026-08-26T04:12:00Z",
      "last_login_at": "2026-08-26T04:12:00Z",
      "login_count": 12,
      "service_id": "local-ai-app",
      "service_joined_at": "2026-08-01T09:00:05Z",
      "meta": {}
    },
    "session": {
      "session_id": "a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90",
      "auth_type": "token",
      "service_id": "local-ai-app",
      "issued_at": "2026-08-26T04:12:00Z",
      "expires_at": "2026-08-26T05:12:00Z",
      "refresh_expires_at": "2026-09-09T04:12:00Z",
      "last_seen_at": "2026-08-26T04:12:00Z",
      "device_label": "Chrome / Windows",
      "ip": "203.0.113.9",
      "user_agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36",
      "is_current": true
    },
    "access_token": "9a8b7c6d5e4f30291827364554637281a9b8c7d6e5f4a3b2c1d0e9f8a7b6c5d4",
    "refresh_token": "1122334455667788990011223344556677889900aabbccddeeff001122334455",
    "token_type": "Bearer",
    "expires_in": 3600,
    "refresh_expires_in": 1209600,
    "state": "9f3c1a7e5d2b8046c1937fa5e0d4b8261c7f3a95e08d2b46c1937fa5e0d4b826",
    "payload": {
      "ai_api_key": "sk-proj-xxxxxxxxxxxxxxxx",
      "api_base": "https://api.openai.com/v1",
      "model": "gpt-4o-mini"
    }
  },
  "error": null
}
```

**클라이언트가 저장·검증해야 하는 값:**

| 값 | 저장 위치 | 이유 |
|---|---|---|
| `access_token` | `localStorage` 또는 메모리 | API 호출마다 `Authorization: Bearer`로 보냄 |
| `refresh_token` | `localStorage` 또는 메모리 | 만료 시 재발급용 |
| `expires_in` | 계산해서 `Date.now() + expires_in*1000` 저장 | 갱신 타이머용 |
| `session_id` | `localStorage` 가능 | 기기 목록 표시·개별 종료용. 인증자가 아니므로 노출돼도 안전 |
| `payload` | **메모리만** | 8-4절의 저장 금지 규칙 |
| `state` | 저장하지 않음 | 이미 7단계에서 대조 완료. 응답의 `state`는 확인용 |

실패 응답 (전문):

```json
{
  "ok": false,
  "data": null,
  "error": {
    "code": "SSO_CODE_INVALID",
    "message": "인증 코드가 유효하지 않습니다. 다시 로그인해 주세요.",
    "field": null
  }
}
```

> **`SSO_CODE_INVALID`는 6가지 원인을 하나로 묶습니다:** code 없음 / 만료(60초 초과) / 이미 사용됨 / `service_id` 불일치 / `redirect_uri` 불일치 / PKCE 불일치. 구분해서 알려주면 공격자에게 "어느 단계까지 성공했는지" 알려주는 오라클이 되기 때문입니다. **처리 방법은 하나입니다 — 로그인을 처음부터 다시 시작하세요.**

**재시도 규칙:** 네트워크 오류로 `exchange`가 실패했다면 같은 `code`로 **한 번 더** 시도해도 됩니다(서버에 도달하지 않았을 수 있으므로). 그러나 `SSO_CODE_INVALID`를 받았다면 재시도는 무의미합니다 — code는 이미 소모되었거나 무효입니다.

### 4-9. 9단계 — 이후 API 호출

모든 인증 API는 이 헤더를 붙입니다:

```
Authorization: Bearer <<ACCESS_TOKEN>>
```

`Authorization` 헤더가 PHP에 전달되지 않는 CGI 모드 호스팅에서는 대체 헤더가 있습니다:

```
X-Auth-Token: <<ACCESS_TOKEN>>
```

서버는 두 헤더를 모두 읽습니다. `Authorization`이 우선입니다.

### 4-10. 토큰 갱신

`access_token`이 만료되기 전에 갱신합니다. **만료 60초 전**에 미리 도는 타이머를 권장합니다.

```bash
curl -s -X POST '<<설치루트>>/server/api/auth/refresh.php' \
  -H 'Content-Type: application/json' \
  -d '{"refresh_token":"<<REFRESH_TOKEN>>","device_label":"Chrome / Windows"}'
```

성공 응답 (전문 — `user`는 33필드 전부, 여기서는 지면상 주요 필드만 표시하되 **실제 응답에는 전부 옵니다**):

```json
{
  "ok": true,
  "data": {
    "user": { "uuid": "3f1b8c2a-7d4e-4a91-9c33-2b6e0f5a1d77", "username": "hong", "email": "hong@example.com", "name": "홍길동", "nickname": "길동", "phone": null, "avatar_url": null, "status": "active", "account_level": "verified", "account_level_rank": 20, "role": "user", "role_rank": 0, "billing_tier": "lite", "billing_tier_rank": 10, "billing_started_at": "2026-08-01T00:00:00Z", "billing_expires_at": "2026-12-31T23:59:59Z", "billing_active": true, "points": 90, "total_points_earned": 100, "total_points_spent": 10, "email_verified": true, "email_verified_at": "2026-08-02T05:11:00Z", "two_factor_enabled": false, "timezone": "Asia/Seoul", "locale": "ko-KR", "marketing_agreed": false, "created_at": "2026-08-01T09:00:00Z", "updated_at": "2026-08-26T05:00:00Z", "last_login_at": "2026-08-26T04:12:00Z", "login_count": 12, "service_id": "local-ai-app", "service_joined_at": "2026-08-01T09:00:05Z", "meta": {} },
    "session": {
      "session_id": "ffeeddccbbaa99887766554433221100ffeeddccbbaa99887766554433221100",
      "auth_type": "token",
      "service_id": "local-ai-app",
      "issued_at": "2026-08-26T05:05:00Z",
      "expires_at": "2026-08-26T06:05:00Z",
      "refresh_expires_at": "2026-09-09T05:05:00Z",
      "last_seen_at": "2026-08-26T05:05:00Z",
      "device_label": "Chrome / Windows",
      "ip": "203.0.113.9",
      "user_agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36",
      "is_current": true
    },
    "access_token": "0f0e0d0c0b0a09080706050403020100f0e0d0c0b0a090807060504030201000",
    "refresh_token": "aabbccddeeff00112233445566778899aabbccddeeff001122334455667788990",
    "token_type": "Bearer",
    "expires_in": 3600,
    "refresh_expires_in": 1209600,
    "payload": {
      "ai_api_key": "sk-proj-xxxxxxxxxxxxxxxx",
      "api_base": "https://api.openai.com/v1",
      "model": "gpt-4o-mini"
    }
  },
  "error": null
}
```

**refresh의 4가지 성질 — 전부 중요합니다:**

1. **1회용입니다.** 갱신하면 옛 `refresh_token`은 즉시 폐기되고 새 값이 나옵니다. 새 값으로 교체하세요.
2. **`session_id`도 새 값이 됩니다.** 세션 행 자체가 새로 만들어집니다. 옛 `session_id`를 계속 쓰면 기기 목록에서 찾을 수 없습니다.
3. **`payload`가 재계산됩니다.** 등급이 바뀌었거나 시크릿이 교체됐을 수 있으므로 **반드시 새 payload로 갈아 끼우세요.** 옛 키를 계속 쓰면 폐기된 API 키로 호출하게 됩니다.
4. **재사용을 탐지하면 그 사용자의 모든 세션이 회수됩니다.** 이미 폐기된 `refresh_token`을 다시 보내면 토큰 탈취로 간주해 전체 로그아웃됩니다. 갱신 요청이 여러 개 동시에 나가지 않도록 **하나로 합치세요**(진행 중인 갱신 Promise를 재사용).

실패 응답 (전문):

```json
{
  "ok": false,
  "data": null,
  "error": {
    "code": "AUTH_SESSION_EXPIRED",
    "message": "세션이 만료되었습니다. 다시 로그인해 주세요.",
    "field": null
  }
}
```

이 응답을 받으면 **로컬 토큰을 전부 지우고 로그인 화면으로 보내세요.**

### 4-11. 세션 재검증 + payload 재수신

앱을 다시 열었을 때(새로고침 등) `access_token`이 아직 유효한지 확인하고 payload를 다시 받는 경로입니다.

```bash
curl -s '<<설치루트>>/server/api/auth/session.php' \
  -H 'Authorization: Bearer <<ACCESS_TOKEN>>'
```

성공 응답 (전문 — `user` 33필드 전부 옵니다):

```json
{
  "ok": true,
  "data": {
    "user": { "uuid": "3f1b8c2a-7d4e-4a91-9c33-2b6e0f5a1d77", "username": "hong", "email": "hong@example.com", "name": "홍길동", "nickname": "길동", "phone": null, "avatar_url": null, "status": "active", "account_level": "verified", "account_level_rank": 20, "role": "user", "role_rank": 0, "billing_tier": "lite", "billing_tier_rank": 10, "billing_started_at": "2026-08-01T00:00:00Z", "billing_expires_at": "2026-12-31T23:59:59Z", "billing_active": true, "points": 90, "total_points_earned": 100, "total_points_spent": 10, "email_verified": true, "email_verified_at": "2026-08-02T05:11:00Z", "two_factor_enabled": false, "timezone": "Asia/Seoul", "locale": "ko-KR", "marketing_agreed": false, "created_at": "2026-08-01T09:00:00Z", "updated_at": "2026-08-26T05:00:00Z", "last_login_at": "2026-08-26T04:12:00Z", "login_count": 12, "service_id": "local-ai-app", "service_joined_at": "2026-08-01T09:00:05Z", "meta": {} },
    "session": {
      "session_id": "a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90",
      "auth_type": "token",
      "service_id": "local-ai-app",
      "issued_at": "2026-08-26T04:12:00Z",
      "expires_at": "2026-08-26T05:12:00Z",
      "refresh_expires_at": "2026-09-09T04:12:00Z",
      "last_seen_at": "2026-08-26T04:50:00Z",
      "device_label": "Chrome / Windows",
      "ip": "203.0.113.9",
      "user_agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36",
      "is_current": true
    },
    "expires_in": 1320,
    "payload": {
      "ai_api_key": "sk-proj-xxxxxxxxxxxxxxxx",
      "api_base": "https://api.openai.com/v1",
      "model": "gpt-4o-mini"
    }
  },
  "error": null
}
```

> **`expires_in`의 의미가 여기서만 다릅니다.** `login` / `exchange` / `refresh`의 `expires_in`은 **발급 TTL**(예: 3600)이고, `GET /api/auth/session.php`의 `expires_in`은 **남은 초**(예: 1320)입니다. 두 경우 모두 "지금부터 이 초 뒤에 만료"라는 뜻이므로 `Date.now() + expires_in * 1000`으로 계산하면 동일하게 동작합니다.

### 4-12. 로그아웃

```bash
curl -s -X POST '<<설치루트>>/server/api/auth/logout.php' \
  -H 'Authorization: Bearer <<ACCESS_TOKEN>>' \
  -H 'Content-Type: application/json' \
  -d '{"all_devices":false}'
```

성공 응답 (전문):

```json
{ "ok": true, "data": { "revoked_count": 1, "all_devices": false }, "error": null }
```

**이미 만료된 토큰으로 호출하면 200이 아니라 401 `AUTH_SESSION_EXPIRED`가 옵니다.** 클라이언트는 **401도 로그아웃 성공으로 처리**하세요(어차피 세션이 없으므로 결과가 같습니다). 로컬 토큰을 지우면 끝입니다.

---

## 5. 엔드포인트 전체 레퍼런스 (51행 = 46조합)

### 5-0. 공통 규칙

**응답 봉투 — 예외 없이 모든 응답이 이 형태입니다.**

```json
{ "ok": true,  "data": { }, "error": null }
```

```json
{ "ok": false, "data": null, "error": { "code": "…", "message": "…", "field": null } }
```

- `ok`가 `true`면 `data`에 결과가 들어 있고 `error`는 `null`입니다.
- `ok`가 `false`면 `error.code`가 항상 있고, `error.field`는 **키가 항상 존재**하며 값은 `null`일 수 있습니다.
- 실패 시 `data`는 보통 `null`이지만 부가정보가 있으면 객체가 옵니다(`retry_after`, `locked_until`, `points`, `required`, `shortfall`, `allowed`, `email`, `user_count`, `secret_count`).
- **목록 응답은 배열이 아니라 객체입니다:** `{"items":[…],"total":123,"page":1,"per_page":20}`. `data.items`를 꺼내야 배열입니다.
- 페이징 파라미터는 `page`(1부터) / `per_page`(1~100). 100을 넘겨도 에러가 아니라 100으로 잘립니다.
- 빈 맵(`meta`, `payload`, `filters_applied`)은 `[]`가 아니라 **`{}`**로 옵니다.

**인증 방식 표기**

| 표기 | 의미 |
|---|---|
| 없음 | 인증 불필요 |
| Bearer | `Authorization: Bearer <<ACCESS_TOKEN>>` (또는 `X-Auth-Token` 헤더, 또는 `ULOGIN_SID` 쿠키) |
| Bearer+CSRF | 쿠키로 인증할 때만 `X-CSRF-Token` 헤더가 추가로 필요합니다. **Bearer로 인증하면 CSRF 검사는 면제됩니다** |

**최소 역할 표기:** 관리자 API의 권한은 **전역 `users.role`만** 봅니다. 서비스별 `role` 오버라이드는 로그인 서버의 관리 권한이 되지 않습니다.

**공통 레이트리밋:** 모든 요청에 IP당 60초 300회 상한이 걸립니다. 초과 시 429 `RATE_LIMITED` + `Retry-After` 응답 헤더.

---

### 5-1. 인증 (`server/api/auth/`) — 11개

#### (1) POST `<<설치루트>>/server/api/auth/register.php` — 회원가입

인증: 없음 · 레이트리밋: IP당 1시간 5회

요청:

```json
{
  "username": "hong",
  "email": "hong@example.com",
  "password": "Passw0rd!",
  "password_confirm": "Passw0rd!",
  "name": "홍길동",
  "nickname": "길동",
  "phone": "010-1234-5678",
  "marketing_agreed": false,
  "agree_tos": true,
  "agree_privacy": true,
  "service_id": "local-ai-app"
}
```

| 필드 | 필수 | 규칙 |
|---|---|---|
| `username` | 예 | 소문자 정규화 후 `^[a-z0-9_]{4,20}$` |
| `email` | 예 | 소문자 정규화. 3~190자 |
| `password` | 예 | 최소 8자(설정 가능), 영문·숫자·특수문자 중 2종 이상, 아이디와 동일 불가, 200자 이내 |
| `password_confirm` | 예 | `password`와 일치 |
| `name` / `nickname` / `phone` | 아니오 | 각 80 / 50 / 30자 이내 |
| `marketing_agreed` | 아니오 | 기본 `false` |
| `agree_tos` | 예 | **`true`여야 함.** 누락도 `false`도 거부 |
| `agree_privacy` | 예 | **`true`여야 함** |
| `service_id` | 아니오 | 주면 그 서비스 가입 행도 함께 만듭니다 |

성공 (201) 전문:

```json
{
  "ok": true,
  "data": {
    "user": {
      "uuid": "3f1b8c2a-7d4e-4a91-9c33-2b6e0f5a1d77",
      "username": "hong",
      "email": "hong@example.com",
      "name": "홍길동",
      "nickname": "길동",
      "phone": "010-1234-5678",
      "avatar_url": null,
      "status": "active",
      "account_level": "basic",
      "account_level_rank": 10,
      "role": "user",
      "role_rank": 0,
      "billing_tier": "free",
      "billing_tier_rank": 0,
      "billing_started_at": null,
      "billing_expires_at": null,
      "billing_active": false,
      "points": 100,
      "total_points_earned": 100,
      "total_points_spent": 0,
      "email_verified": false,
      "email_verified_at": null,
      "two_factor_enabled": false,
      "timezone": "Asia/Seoul",
      "locale": "ko-KR",
      "marketing_agreed": false,
      "created_at": "2026-08-26T04:00:00Z",
      "updated_at": "2026-08-26T04:00:00Z",
      "last_login_at": null,
      "login_count": 0,
      "service_id": "local-ai-app",
      "service_joined_at": "2026-08-26T04:00:00Z",
      "meta": {}
    },
    "email_verification_required": false,
    "granted_points": 100,
    "auto_login": false
  },
  "error": null
}
```

> **`auto_login`은 항상 `false`입니다.** 가입 직후 토큰을 발급하지 않습니다. 가입 후 바로 로그인시키려면 앱이 `POST /api/auth/login.php`를 이어서 호출하세요.

가능한 에러: `VALIDATION_FAILED`(400) · `DUPLICATE_USERNAME`(409) · `DUPLICATE_EMAIL`(409) · `REGISTRATION_DISABLED`(403) · `SSO_INVALID_SERVICE`(400) · `RATE_LIMITED`(429) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

```bash
curl -s -X POST '<<설치루트>>/server/api/auth/register.php' \
  -H 'Content-Type: application/json' \
  -d '{"username":"hong","email":"hong@example.com","password":"Passw0rd!","password_confirm":"Passw0rd!","agree_tos":true,"agree_privacy":true,"service_id":"<<서비스ID>>"}'
```

#### (2) POST `<<설치루트>>/server/api/auth/login.php` — 직접 로그인

인증: 없음 · 레이트리밋: IP당 5분 10회 + 아이디당 5분 5회 (`sso/authorize.php`와 버킷 공유)

요청:

```json
{
  "username": "hong",
  "password": "Passw0rd!",
  "remember": false,
  "device_label": "Chrome / Windows",
  "service_id": "local-ai-app"
}
```

- `username`에는 **아이디 또는 이메일** 둘 다 넣을 수 있습니다.
- `remember: true`면 쿠키 수명이 30일로 늘어납니다. Bearer 토큰 수명에는 영향이 없습니다.
- `service_id`를 주면 **응답에 `payload`가 실립니다.** 안 주면 `payload`는 `{}`입니다.

성공 (200) 전문:

```json
{
  "ok": true,
  "data": {
    "user": {
      "uuid": "3f1b8c2a-7d4e-4a91-9c33-2b6e0f5a1d77",
      "username": "hong",
      "email": "hong@example.com",
      "name": "홍길동",
      "nickname": "길동",
      "phone": null,
      "avatar_url": null,
      "status": "active",
      "account_level": "verified",
      "account_level_rank": 20,
      "role": "user",
      "role_rank": 0,
      "billing_tier": "lite",
      "billing_tier_rank": 10,
      "billing_started_at": "2026-08-01T00:00:00Z",
      "billing_expires_at": "2026-12-31T23:59:59Z",
      "billing_active": true,
      "points": 100,
      "total_points_earned": 100,
      "total_points_spent": 0,
      "email_verified": true,
      "email_verified_at": "2026-08-02T05:11:00Z",
      "two_factor_enabled": false,
      "timezone": "Asia/Seoul",
      "locale": "ko-KR",
      "marketing_agreed": false,
      "created_at": "2026-08-01T09:00:00Z",
      "updated_at": "2026-08-26T04:12:00Z",
      "last_login_at": "2026-08-26T04:12:00Z",
      "login_count": 12,
      "service_id": "local-ai-app",
      "service_joined_at": "2026-08-01T09:00:05Z",
      "meta": {}
    },
    "session": {
      "session_id": "a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90",
      "auth_type": "token",
      "service_id": "local-ai-app",
      "issued_at": "2026-08-26T04:12:00Z",
      "expires_at": "2026-08-26T05:12:00Z",
      "refresh_expires_at": "2026-09-09T04:12:00Z",
      "last_seen_at": "2026-08-26T04:12:00Z",
      "device_label": "Chrome / Windows",
      "ip": "203.0.113.9",
      "user_agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126.0",
      "is_current": true
    },
    "access_token": "9a8b7c6d5e4f30291827364554637281a9b8c7d6e5f4a3b2c1d0e9f8a7b6c5d4",
    "refresh_token": "1122334455667788990011223344556677889900aabbccddeeff001122334455",
    "token_type": "Bearer",
    "expires_in": 3600,
    "refresh_expires_in": 1209600,
    "payload": {
      "ai_api_key": "sk-proj-xxxxxxxxxxxxxxxx",
      "api_base": "https://api.openai.com/v1",
      "model": "gpt-4o-mini"
    }
  },
  "error": null
}
```

가능한 에러: `VALIDATION_FAILED`(400) · `AUTH_INVALID_CREDENTIALS`(401) · `AUTH_ACCOUNT_LOCKED`(423, `data.locked_until`·`data.retry_after`) · `AUTH_ACCOUNT_SUSPENDED`(403) · `AUTH_ACCOUNT_WITHDRAWN`(403) · `AUTH_ACCOUNT_DORMANT`(403) · `AUTH_ACCOUNT_PENDING`(403) · `AUTH_EMAIL_UNVERIFIED`(403, `data.email`) · `FORBIDDEN`(403, 이 서비스에서 차단된 회원) · `MAINTENANCE_MODE`(503) · `SSO_INVALID_SERVICE`(400) · `RATE_LIMITED`(429) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

```bash
curl -s -X POST '<<설치루트>>/server/api/auth/login.php' \
  -H 'Content-Type: application/json' \
  -d '{"username":"hong","password":"Passw0rd!","service_id":"<<서비스ID>>"}'
```

#### (3) POST `<<설치루트>>/server/api/auth/logout.php` — 로그아웃

인증: Bearer+CSRF

요청: `{"all_devices": false}` — `true`면 그 사용자의 모든 세션을 회수합니다.

성공 전문:

```json
{ "ok": true, "data": { "revoked_count": 1, "all_devices": false }, "error": null }
```

가능한 에러: `AUTH_REQUIRED`(401) · `AUTH_SESSION_EXPIRED`(401) · `CSRF_FAILED`(403, 쿠키 인증일 때만) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

```bash
curl -s -X POST '<<설치루트>>/server/api/auth/logout.php' \
  -H 'Authorization: Bearer <<ACCESS_TOKEN>>' \
  -H 'Content-Type: application/json' \
  -d '{"all_devices":false}'
```

#### (4) POST `<<설치루트>>/server/api/auth/refresh.php` — 토큰 회전

인증: 없음 (refresh_token 자체가 자격증명) · 레이트리밋: **IP당 60초 120회 · 계정당 1시간 60회** (v1.4.0 신설)

요청: `{"refresh_token":"<<REFRESH_TOKEN>>","device_label":"Chrome / Windows"}`
`refresh_token`은 **정확히 64자**여야 합니다. 길이가 다르면 즉시 401입니다.

성공 응답의 키 구성은 4-10절 전문과 같습니다: `user`, `session`, `access_token`, `refresh_token`, `token_type`, `expires_in`, `refresh_expires_in`, `payload`.

가능한 에러: `VALIDATION_FAILED`(400) · `AUTH_SESSION_EXPIRED`(401 — 없음·만료·재사용 탐지·비밀번호 변경 후 전부 이 코드) · `AUTH_ACCOUNT_SUSPENDED`·`AUTH_ACCOUNT_WITHDRAWN`·`AUTH_ACCOUNT_DORMANT`·`AUTH_ACCOUNT_PENDING`(403) · **`RATE_LIMITED`(429, `data.retry_after`)** · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

> **429를 401과 같이 처리하면 안 됩니다.** `RATE_LIMITED`는 **토큰이 유효한데 지금 받아주지 않는다**는 뜻이고,
> **그 429는 `refresh_token`을 폐기하지 않습니다**(실측). `data.retry_after`(초)만큼 기다린 뒤
> **같은 토큰으로** 다시 보내면 200이 됩니다. 이것을 `AUTH_SESSION_EXPIRED`처럼 재로그인 분기로 보내면
> **살아 있는 세션을 강제로 로그아웃시킵니다.** 처리 지침은 10-2절 `RATE_LIMITED` 행과 같습니다(즉시 재시도 루프 금지).

```bash
curl -s -X POST '<<설치루트>>/server/api/auth/refresh.php' \
  -H 'Content-Type: application/json' \
  -d '{"refresh_token":"<<REFRESH_TOKEN>>"}'
```

#### (5) GET `<<설치루트>>/server/api/auth/session.php` — 세션 확인 + payload 재발급

인증: Bearer · 요청 본문 없음

성공 응답 키: `user`, `session`, `expires_in`(**남은 초**), `payload`. 전문은 4-11절에 있습니다.

가능한 에러: `AUTH_REQUIRED`(401) · `AUTH_SESSION_EXPIRED`(401) · 계정 상태 4종(403) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

```bash
curl -s '<<설치루트>>/server/api/auth/session.php' \
  -H 'Authorization: Bearer <<ACCESS_TOKEN>>'
```

#### (6) GET `<<설치루트>>/server/api/auth/csrf.php` — CSRF 토큰 발급

인증: 없음 (비로그인 상태에서도 호출 가능)

**쿠키 세션 UI 전용입니다. Bearer를 쓰는 외부 앱은 이 엔드포인트가 필요 없습니다.**

성공 전문:

```json
{ "ok": true, "data": { "csrf_token": "5e4d3c2b1a09f8e7d6c5b4a39281706f5e4d3c2b1a09f8e7d6c5b4a39281706f", "expires_in": 3600 }, "error": null }
```

받은 값을 이후 쓰기 요청의 `X-CSRF-Token` 헤더에 넣습니다. 응답과 함께 `ULOGIN_CSRF` 쿠키도 설정됩니다.

가능한 에러: `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

#### (7) POST `<<설치루트>>/server/api/auth/exchange.php` — SSO code 교환

인증: 없음 · 레이트리밋: IP당 5분 30회

요청:

```json
{
  "code": "7d1f9a2b3c4d5e6f708192a3b4c5d6e7f8091a2b3c4d5e6f708192a3b4c5d6e7",
  "service_id": "local-ai-app",
  "redirect_uri": "http://localhost:5173/callback.html",
  "code_verifier": "8c2ea1b0d9f8e7c6b5a4938271605f4e3d2c1b0a9f8e7d6c5b4a3928170605f4",
  "service_secret": "<<SERVICE_SECRET>>",
  "device_label": "Chrome / Windows"
}
```

- `code` / `service_id` / `redirect_uri` 필수. `code_verifier`는 PKCE를 썼을 때만. `service_secret`은 `is_confidential=true`인 서비스에서만 필수.
- `redirect_uri`는 **authorize 때 보낸 값과 완전히 같아야** 합니다.

성공 응답 전문은 4-8절에 있습니다. 키: `user`, `session`, `access_token`, `refresh_token`, `token_type`, `expires_in`, `refresh_expires_in`, `state`, `payload`.

가능한 에러: `VALIDATION_FAILED`(400) · `SSO_INVALID_SERVICE`(400) · `SSO_CLIENT_AUTH_FAILED`(401) · `SSO_CODE_INVALID`(400) · 계정 상태 4종(403) · `FORBIDDEN`(403, 서비스 차단) · `RATE_LIMITED`(429) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

#### (8) POST `<<설치루트>>/server/api/auth/password_forgot.php` — 비밀번호 재설정 링크 요청

> 계약서의 논리 경로는 `/api/auth/password/forgot`이지만 **실제 파일은 `password_forgot.php`** 입니다.

인증: 없음 · 레이트리밋: IP당 1시간 5회 + 이메일당 1시간 3회

요청: `{"email":"hong@example.com"}`

성공 전문 (**계정이 없어도 완전히 동일한 응답**):

```json
{
  "ok": true,
  "data": {
    "sent": true,
    "message": "입력하신 이메일로 재설정 링크를 보냈습니다. 메일이 오지 않으면 주소를 확인해 주세요.",
    "expires_in": 1800
  },
  "error": null
}
```

`sent`는 **항상 `true`**입니다. 실제 발송 여부를 반영하지 않습니다 — 계정 존재 여부가 새어나가지 않게 하기 위함입니다.
메일 링크 형태: `<<설치루트>>/public/reset.html?token=…` (수명 1800초 = 30분)

가능한 에러: `VALIDATION_FAILED`(400, 이메일 형식) · `RATE_LIMITED`(429) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

#### (9) POST `<<설치루트>>/server/api/auth/password_reset.php` — 토큰으로 비밀번호 재설정

> 논리 경로 `/api/auth/password/reset` → **실제 파일 `password_reset.php`**

인증: 없음 · 레이트리밋: IP당 1시간 20회

요청: `{"token":"…64자 hex…","password":"NewPassw0rd!","password_confirm":"NewPassw0rd!"}`

성공 전문:

```json
{ "ok": true, "data": { "reset": true, "revoked_count": 3 }, "error": null }
```

성공하면 그 사용자의 **모든 세션이 회수**됩니다.

가능한 에러: `VALIDATION_FAILED`(400) · `TOKEN_INVALID`(400 — 없음·만료·이미 사용됨을 전부 하나로 통일) · `RATE_LIMITED`(429) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

#### (10) POST `<<설치루트>>/server/api/auth/email_verify.php` — 이메일 인증 토큰 소모

> 논리 경로 `/api/auth/email/verify` → **실제 파일 `email_verify.php`**

인증: 없음 · 레이트리밋: IP당 1시간 30회

요청: `{"token":"…64자 hex…"}`

성공 전문:

```json
{
  "ok": true,
  "data": { "verified": true, "email": "hong@example.com", "account_level": "verified", "auto_login": false },
  "error": null
}
```

인증 성공 시: `email_verified_at` 기록 / `status`가 `pending`이면 `active`로 전환 / `account_level`이 `guest`나 `basic`이면 `verified`로 승급(그 이상은 그대로 둡니다 — 내리면 강등이 되므로).
`auto_login`은 **항상 `false`**입니다. 메일 링크 클릭만으로 세션을 만들지 않습니다.

가능한 에러: `VALIDATION_FAILED`(400) · `TOKEN_INVALID`(400) · `RATE_LIMITED`(429) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

#### (11) POST `<<설치루트>>/server/api/auth/email_resend.php` — 인증 메일 재발송

> 논리 경로 `/api/auth/email/resend` → **실제 파일 `email_resend.php`**

인증: 없음 · 레이트리밋: IP당 1시간 5회 + 이메일당 1시간 3회

요청: `{"email":"hong@example.com"}`

성공 전문 (**계정이 없거나 이미 인증됐어도 동일**):

```json
{ "ok": true, "data": { "sent": true, "message": "인증 메일을 보냈습니다.", "expires_in": 86400 }, "error": null }
```

기존 미사용 토큰은 전부 무효화되고 새 토큰이 발급됩니다.

가능한 에러: `VALIDATION_FAILED`(400) · `RATE_LIMITED`(429) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

---

### 5-2. SSO (`server/api/sso/`) — 2개

#### (12) GET `<<설치루트>>/server/api/sso/validate.php?service_id=…&redirect_uri=…`

인증: 없음 · 쿼리 파라미터 2개 모두 필수

성공/실패 전문은 4-4절에 있습니다. 응답 키: `service_id`, `service_name`, `redirect_uri_valid`, `allow_register`, `requires_pkce`(v1.0.0에서 항상 `false`), `is_confidential`, `site_name`.

가능한 에러: `VALIDATION_FAILED`(400) · `SSO_INVALID_SERVICE`(400) · `SSO_INVALID_REDIRECT`(400) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

```bash
curl -s '<<설치루트>>/server/api/sso/validate.php?service_id=<<서비스ID>>&redirect_uri=http%3A%2F%2Flocalhost%3A5173%2Fcallback.html'
```

#### (13) POST `<<설치루트>>/server/api/sso/authorize.php` — 자격 검증 + code 발급

인증: 없음 · 레이트리밋: `login.php`와 공유 (IP 5분 10회 / 아이디 5분 5회)

요청/응답 전문은 4-5절에 있습니다. 응답 키: `redirect_to`, `code`, `state`, `expires_in`.

가능한 에러: `VALIDATION_FAILED`(400 — `code_challenge_method`가 `S256`이 아니면 여기) · `SSO_INVALID_SERVICE`(400) · `SSO_INVALID_REDIRECT`(400) · `AUTH_INVALID_CREDENTIALS`(401) · `AUTH_ACCOUNT_LOCKED`(423) · 계정 상태 4종(403) · `AUTH_EMAIL_UNVERIFIED`(403) · `FORBIDDEN`(403, 서비스 차단) · `MAINTENANCE_MODE`(503) · `RATE_LIMITED`(429) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

---

### 5-3. 내 정보 (`server/api/me/`) — 10개

#### (14) GET `<<설치루트>>/server/api/me/index.php` — 내 정보 조회

> 논리 경로 `/api/me` → **실제 파일 `me/index.php`**

인증: Bearer

성공 전문:

```json
{
  "ok": true,
  "data": {
    "user": {
      "uuid": "3f1b8c2a-7d4e-4a91-9c33-2b6e0f5a1d77",
      "username": "hong",
      "email": "hong@example.com",
      "name": "홍길동",
      "nickname": "길동",
      "phone": null,
      "avatar_url": null,
      "status": "active",
      "account_level": "verified",
      "account_level_rank": 20,
      "role": "user",
      "role_rank": 0,
      "billing_tier": "lite",
      "billing_tier_rank": 10,
      "billing_started_at": "2026-08-01T00:00:00Z",
      "billing_expires_at": "2026-12-31T23:59:59Z",
      "billing_active": true,
      "points": 90,
      "total_points_earned": 100,
      "total_points_spent": 10,
      "email_verified": true,
      "email_verified_at": "2026-08-02T05:11:00Z",
      "two_factor_enabled": false,
      "timezone": "Asia/Seoul",
      "locale": "ko-KR",
      "marketing_agreed": false,
      "created_at": "2026-08-01T09:00:00Z",
      "updated_at": "2026-08-26T05:00:00Z",
      "last_login_at": "2026-08-26T04:12:00Z",
      "login_count": 12,
      "service_id": "local-ai-app",
      "service_joined_at": "2026-08-01T09:00:05Z",
      "meta": {}
    },
    "session": {
      "session_id": "a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90",
      "auth_type": "token",
      "service_id": "local-ai-app",
      "issued_at": "2026-08-26T04:12:00Z",
      "expires_at": "2026-08-26T05:12:00Z",
      "refresh_expires_at": "2026-09-09T04:12:00Z",
      "last_seen_at": "2026-08-26T05:00:00Z",
      "device_label": "Chrome / Windows",
      "ip": "203.0.113.9",
      "user_agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126.0",
      "is_current": true
    }
  },
  "error": null
}
```

> **이 응답에는 `payload`가 없습니다.** 시크릿이 필요하면 `GET /api/auth/session.php`를 쓰세요.

가능한 에러: `AUTH_REQUIRED`(401) · `AUTH_SESSION_EXPIRED`(401) · 계정 상태 4종(403) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

```bash
curl -s '<<설치루트>>/server/api/me/index.php' \
  -H 'Authorization: Bearer <<ACCESS_TOKEN>>'
```

#### (15) PUT `<<설치루트>>/server/api/me/index.php` — 내 정보 수정

인증: Bearer+CSRF

요청 — **수정 가능한 필드는 8개뿐**입니다:

```json
{
  "name": "홍길동",
  "nickname": "길동",
  "phone": "010-1234-5678",
  "avatar_url": "https://cdn.example.com/a.png",
  "timezone": "Asia/Seoul",
  "locale": "ko-KR",
  "marketing_agreed": true,
  "meta": { "theme": "dark" }
}
```

| 필드 | 규칙 |
|---|---|
| `name` | 80자 이내. `null`을 보내면 지워집니다 |
| `nickname` | 50자 이내. `null` 가능 |
| `phone` | 30자 이내, `^[0-9+\-() .]{4,30}$`. `null` 가능 |
| `avatar_url` | 255자 이내. **`http`/`https`만 허용**(`javascript:`·`data:` 거부). `null` 가능 |
| `timezone` | 실제 존재하는 타임존 이름 또는 `UTC`. `null`이면 `Asia/Seoul`로 되돌아갑니다 |
| `locale` | `^[a-zA-Z]{2,3}(-[A-Za-z0-9]{2,8})?$`. `null`이면 `ko-KR` |
| `marketing_agreed` | 불리언 |
| `meta` | JSON 객체. 인코딩 후 8KB 이내. `null`이면 지워집니다 |

`account_level` / `role` / `billing_tier` / `points` / `status` / `username` / `email`을 함께 보내도 **조용히 무시**됩니다(에러가 아닙니다). 받은 `user` 객체를 통째로 PUT해도 안전합니다.

성공 전문:

```json
{
  "ok": true,
  "data": {
    "user": {
      "uuid": "3f1b8c2a-7d4e-4a91-9c33-2b6e0f5a1d77",
      "username": "hong",
      "email": "hong@example.com",
      "name": "홍길동",
      "nickname": "길동",
      "phone": "010-1234-5678",
      "avatar_url": "https://cdn.example.com/a.png",
      "status": "active",
      "account_level": "verified",
      "account_level_rank": 20,
      "role": "user",
      "role_rank": 0,
      "billing_tier": "lite",
      "billing_tier_rank": 10,
      "billing_started_at": "2026-08-01T00:00:00Z",
      "billing_expires_at": "2026-12-31T23:59:59Z",
      "billing_active": true,
      "points": 90,
      "total_points_earned": 100,
      "total_points_spent": 10,
      "email_verified": true,
      "email_verified_at": "2026-08-02T05:11:00Z",
      "two_factor_enabled": false,
      "timezone": "Asia/Seoul",
      "locale": "ko-KR",
      "marketing_agreed": true,
      "created_at": "2026-08-01T09:00:00Z",
      "updated_at": "2026-08-26T05:40:00Z",
      "last_login_at": "2026-08-26T04:12:00Z",
      "login_count": 12,
      "service_id": "local-ai-app",
      "service_joined_at": "2026-08-01T09:00:05Z",
      "meta": { "theme": "dark" }
    },
    "updated_fields": ["nickname", "marketing_agreed", "meta"]
  },
  "error": null
}
```

`updated_fields`는 **실제로 값이 바뀐 필드만** 담습니다. 값이 같으면 빈 배열입니다.

가능한 에러: `VALIDATION_FAILED`(400) · `AUTH_REQUIRED`(401) · `AUTH_SESSION_EXPIRED`(401) · `CSRF_FAILED`(403) · 계정 상태 4종(403) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

#### (16) PUT `<<설치루트>>/server/api/me/password.php` — 비밀번호 변경

인증: Bearer+CSRF

요청: `{"current_password":"Passw0rd!","password":"NewPassw0rd!","password_confirm":"NewPassw0rd!"}`

성공 전문:

```json
{ "ok": true, "data": { "changed": true, "revoked_count": 2, "password_changed_at": "2026-08-26T05:30:00Z" }, "error": null }
```

**현재 세션은 유지되고 다른 세션만 회수됩니다.** 지금 쓰던 토큰을 계속 쓰면 됩니다.

가능한 에러: `VALIDATION_FAILED`(400) · `AUTH_PASSWORD_MISMATCH`(400, 현재 비밀번호 틀림) · `AUTH_REQUIRED`(401) · `AUTH_SESSION_EXPIRED`(401) · `CSRF_FAILED`(403) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

#### (17) PUT `<<설치루트>>/server/api/me/email.php` — 이메일 변경

인증: Bearer+CSRF

요청: `{"password":"Passw0rd!","email":"new@example.com"}` — 비밀번호 확인이 **필수**입니다.

성공 전문:

```json
{ "ok": true, "data": { "email": "new@example.com", "email_verified": false, "verification_sent": true, "expires_in": 86400 }, "error": null }
```

변경하면 `email_verified_at`이 `null`로 되돌아갑니다. `require_email_verify=0`인 설치본에서는 메일을 보내지 않고 `verification_sent: false`, `expires_in: 0`이 옵니다.

가능한 에러: `VALIDATION_FAILED`(400, 현재와 같은 주소도 여기) · `AUTH_PASSWORD_MISMATCH`(400) · `DUPLICATE_EMAIL`(409) · `AUTH_REQUIRED`(401) · `CSRF_FAILED`(403) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

#### (18) GET `<<설치루트>>/server/api/me/sessions.php` — 내 세션(기기) 목록

인증: Bearer · 쿼리: `page`, `per_page`

성공 전문:

```json
{
  "ok": true,
  "data": {
    "items": [
      {
        "session_id": "a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90",
        "auth_type": "token",
        "service_id": "local-ai-app",
        "issued_at": "2026-08-26T04:12:00Z",
        "expires_at": "2026-08-26T05:12:00Z",
        "refresh_expires_at": "2026-09-09T04:12:00Z",
        "last_seen_at": "2026-08-26T05:00:00Z",
        "device_label": "Chrome / Windows",
        "ip": "203.0.113.9",
        "user_agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126.0",
        "is_current": true
      }
    ],
    "total": 1,
    "page": 1,
    "per_page": 20
  },
  "error": null
}
```

가능한 에러: `AUTH_REQUIRED`(401) · `AUTH_SESSION_EXPIRED`(401) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

#### (19) DELETE `<<설치루트>>/server/api/me/sessions.php?session_id=…` — 세션 1건 종료

> 계약서의 `/api/me/sessions/{session_id}` → **쿼리 파라미터 `?session_id=`** 로 대체되었습니다.

인증: Bearer+CSRF · `session_id`는 쿼리 또는 본문(쿼리가 우선)

성공 전문:

```json
{ "ok": true, "data": { "revoked": true, "session_id": "a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90", "was_current": false }, "error": null }
```

남의 `session_id`를 넣으면 `FORBIDDEN`이 아니라 **`NOT_FOUND`(404)** 입니다. 403을 주면 "그 세션은 존재한다"를 알려주는 오라클이 되기 때문입니다.

가능한 에러: `VALIDATION_FAILED`(400) · `NOT_FOUND`(404) · `AUTH_REQUIRED`(401) · `CSRF_FAILED`(403) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

```bash
curl -s -X DELETE '<<설치루트>>/server/api/me/sessions.php?session_id=a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90' \
  -H 'Authorization: Bearer <<ACCESS_TOKEN>>'
```

#### (20) GET `<<설치루트>>/server/api/me/login_logs.php` — 내 로그인 기록

> 논리 경로 `/api/me/login-logs` → **실제 파일 `login_logs.php`** (하이픈이 언더스코어입니다)

인증: Bearer · 쿼리: `page`, `per_page`, `result`, `from`(YYYY-MM-DD), `to`(YYYY-MM-DD)
`result` 허용값 7종: `success` `fail_credential` `fail_locked` `fail_status` `fail_ratelimit` `fail_service_blocked` `logout`

성공 전문:

```json
{
  "ok": true,
  "data": {
    "items": [
      {
        "id": 812,
        "result": "success",
        "service_id": "local-ai-app",
        "ip": "203.0.113.9",
        "user_agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126.0",
        "created_at": "2026-08-26T04:12:00Z"
      }
    ],
    "total": 1,
    "page": 1,
    "per_page": 20
  },
  "error": null
}
```

가능한 에러: `VALIDATION_FAILED`(400) · `AUTH_REQUIRED`(401) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

#### (21) GET `<<설치루트>>/server/api/me/services.php` — 내가 가입한 서비스

인증: Bearer · 쿼리: `page`, `per_page`

성공 전문:

```json
{
  "ok": true,
  "data": {
    "items": [
      {
        "service_id": "local-ai-app",
        "service_name": "로컬 AI 앱",
        "description": "데스크톱 AI 챗봇",
        "status": "active",
        "membership_status": "active",
        "account_level": "verified",
        "account_level_rank": 20,
        "role": "user",
        "role_rank": 0,
        "billing_tier": "lite",
        "billing_tier_rank": 10,
        "billing_expires_at": "2026-12-31T23:59:59Z",
        "billing_active": true,
        "joined_at": "2026-08-01T09:00:05Z",
        "last_used_at": "2026-08-26T05:00:00Z"
      }
    ],
    "total": 1,
    "page": 1,
    "per_page": 20
  },
  "error": null
}
```

- `status` = **서비스 자체**의 상태(`active` / `disabled`). 서비스가 삭제됐으면 `null`입니다.
- `membership_status` = **이 사용자의 가입 상태**(`active` / `blocked`). `blocked`면 그 서비스 로그인이 403으로 거부되고 payload도 비어 있습니다. 두 필드는 서로 다른 것입니다.
- 서비스별 포인트는 응답에 포함되지 않습니다(v1.0.0은 전역 포인트만 씁니다).

가능한 에러: `AUTH_REQUIRED`(401) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

#### (22) GET `<<설치루트>>/server/api/me/points.php` — 포인트 잔액 + 이력

인증: Bearer · 쿼리: `page`, `per_page`, `reason`, `service_id`

성공 전문:

```json
{
  "ok": true,
  "data": {
    "points": 90,
    "total_points_earned": 100,
    "total_points_spent": 10,
    "items": [
      { "id": 42, "delta": -10, "balance_after": 90, "reason": "usage", "service_id": "local-ai-app", "ref_type": "chat", "ref_id": "msg_7", "memo": null, "created_at": "2026-08-26T05:00:00Z" },
      { "id": 1, "delta": 100, "balance_after": 100, "reason": "signup_bonus", "service_id": "local-ai-app", "ref_type": null, "ref_id": null, "memo": "가입 보너스", "created_at": "2026-08-01T09:00:00Z" }
    ],
    "total": 2,
    "page": 1,
    "per_page": 20
  },
  "error": null
}
```

잔액 3종이 목록과 **같은 응답에** 들어 있으므로 요청을 두 번 할 필요가 없습니다.

가능한 에러: `VALIDATION_FAILED`(400) · `AUTH_REQUIRED`(401) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

#### (23) POST `<<설치루트>>/server/api/me/withdraw.php` — 회원 탈퇴

인증: Bearer+CSRF

요청: `{"password":"Passw0rd!","reason":"사용하지 않아서","confirm":"hong"}`

- `confirm`에는 **자신의 아이디를 정확히** 입력해야 합니다(2단계 확인). 다르면 `VALIDATION_FAILED`입니다.

성공 전문:

```json
{
  "ok": true,
  "data": {
    "withdrawn": true,
    "withdrawn_at": "2026-08-26T06:00:00Z",
    "revoked_count": 2,
    "message": "탈퇴가 완료되었습니다. 이용해 주셔서 감사합니다."
  },
  "error": null
}
```

행을 지우지 않는 소프트 삭제입니다. `username` / `email`은 `wd_{내부id}_{원본}` 형태로 치환되고 개인정보 컬럼은 비워집니다. 포인트 잔액과 원장은 **유지**됩니다(관리자 복구를 위해).

가능한 에러: `VALIDATION_FAILED`(400) · `AUTH_PASSWORD_MISMATCH`(400) · `FORBIDDEN`(403, 마지막 최고관리자는 탈퇴 불가) · `AUTH_REQUIRED`(401) · `CSRF_FAILED`(403) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

---

### 5-4. 포인트 (`server/api/points/`) — 1개

#### (24) POST `<<설치루트>>/server/api/points/consume.php` — 포인트 차감

인증: Bearer+CSRF · 레이트리밋: 사용자당 60초 120회

요청:

```json
{
  "amount": 10,
  "reason": "usage",
  "service_id": "local-ai-app",
  "ref_type": "chat",
  "ref_id": "msg_7",
  "memo": "gpt-4o-mini 1회 호출",
  "idempotency_key": "b2d1f0e7-3c44-4a8e-9d2b-6f1a0c7e5d33"
}
```

| 필드 | 필수 | 규칙 |
|---|---|---|
| `amount` | 예 | **양의 정수** 1~100000000. 서버가 `delta = -amount`로 기록합니다 |
| `reason` | 아니오 | 기본 `usage`. 100자 이내 |
| `service_id` | 아니오 | 주면 실재하는 활성 서비스여야 합니다 |
| `ref_type` / `ref_id` | 아니오 | 40 / 64자 이내. 어떤 작업에 대한 차감인지 추적용 |
| `memo` | 아니오 | 255자 이내 |
| `idempotency_key` | 아니오(**권장**) | 100자 이내. 같은 키로 두 번 보내면 재차감하지 않고 첫 결과를 그대로 돌려줍니다 |

> **`delta`를 직접 보낼 수 없습니다.** 클라이언트가 부호를 정하게 하면 양수 `delta`로 스스로 적립하는 구멍이 됩니다. 적립은 관리자 API로만 가능합니다.

성공 전문:

```json
{
  "ok": true,
  "data": {
    "points": 90,
    "delta": -10,
    "balance_after": 90,
    "total_points_spent": 10,
    "log_id": 42,
    "idempotent_replay": false,
    "created_at": "2026-08-26T05:00:00Z"
  },
  "error": null
}
```

멱등 재실행이면 `idempotent_replay: true`가 오고 잔액은 재차감되지 않습니다.

잔액 부족 (409) 전문:

```json
{
  "ok": false,
  "data": { "points": 5, "required": 10, "shortfall": 5 },
  "error": { "code": "POINT_INSUFFICIENT", "message": "포인트가 부족합니다.", "field": "amount" }
}
```

가능한 에러: `VALIDATION_FAILED`(400) · `POINT_INSUFFICIENT`(409) · `SSO_INVALID_SERVICE`(400) · `AUTH_REQUIRED`(401) · `AUTH_SESSION_EXPIRED`(401) · `CSRF_FAILED`(403) · `RATE_LIMITED`(429) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

```bash
curl -s -X POST '<<설치루트>>/server/api/points/consume.php' \
  -H 'Authorization: Bearer <<ACCESS_TOKEN>>' \
  -H 'Content-Type: application/json' \
  -d '{"amount":10,"reason":"usage","service_id":"<<서비스ID>>","idempotency_key":"b2d1f0e7-3c44-4a8e-9d2b-6f1a0c7e5d33"}'
```

---

### 5-5. 관리자 (`server/api/admin/`) — 21개

> **이 `21개`가 무엇을 센 값인가 — 두 값이 우연히 같으므로 기준을 밝힙니다.**
>
> **이 절에 상세가 적혀 있는 항목의 개수**입니다. 아래 `(25)`~`(45)` = 21개.
> 그리고 **`server/api/admin/` 의 메서드 × 경로 조합**도 v1.6.0에서 **21**이 됐습니다
> (설정 번들 파일 1개의 `GET` + `POST` 가 더해져 19 → 21). **두 값은 같은 것을 세지
> 않습니다** — 상세 항목 쪽은 같은 파일의 쿼리 변종을 별개 항목으로 갖고, 조합 쪽은 갖지
> 않습니다. 지금은 우연히 일치합니다.
>
> **설정 번들 4동작의 상세는 이 절에 없습니다.** [5-6절 요약표](#5-6-엔드포인트-요약표-51행--46조합)의
> 48~51행에 있고, 그 기능을 쓰는 방법 전부는 같은 폴더의 `AI_SETTINGS_JSON_GUIDE.md` 에
> 있습니다. **그래서 5-1~5-5의 상세 합(45)과 요약표(51)가 다릅니다.**

**관리자 API 공통 규칙 3가지**

1. 인증은 Bearer, 쓰기 요청은 쿠키 인증일 때만 CSRF가 추가로 필요합니다.
2. 권한 판정은 **전역 `users.role`만** 봅니다. 어떤 서비스에서 `admin` 오버라이드를 받았다고 해서 로그인 서버의 관리자가 되지 않습니다. 반대로 서비스에서 `user` 오버라이드를 받아도 전역 `admin`이면 관리자 API를 그대로 쓸 수 있습니다.
3. **대상 계정 방어:** 자신과 같거나 높은 `role_rank`를 가진 **다른** 계정은 어떤 변경도 할 수 없습니다. `superadmin`도 자신보다 낮은 계정만 변경할 수 있습니다. 위반 시 `FORBIDDEN`(403). 자기 자신에 대한 변경은 통과하고, 그 뒤의 개별 규칙(마지막 superadmin 보호, 자기 강등 금지, 자기 정지·삭제 금지)이 처리합니다.

조회(GET)는 감사 로그를 남기지 않고, 변경은 `admin_logs`에 기록되며 응답에 `admin_log_id`(정수 또는 `null`)가 옵니다.

#### (25) GET `<<설치루트>>/server/api/admin/stats.php` — 대시보드 통계

최소 역할: `manager`

성공 전문:

```json
{
  "ok": true,
  "data": {
    "users": { "total": 128, "active": 120, "pending": 2, "suspended": 3, "dormant": 1, "withdrawn": 2, "new_today": 4, "new_7d": 19, "new_30d": 61 },
    "logins": { "today": 37, "success_7d": 210, "fail_7d": 12, "unique_users_7d": 58 },
    "points": { "total_balance": 12800, "earned_7d": 1900, "spent_7d": 640 },
    "by_billing_tier": { "free": 100, "lite": 20, "pro": 7, "enterprise": 1 },
    "by_account_level": { "guest": 0, "basic": 80, "verified": 42, "vip": 5, "staff": 1 },
    "by_role": { "user": 124, "manager": 2, "admin": 1, "superadmin": 1 },
    "services": { "total": 3, "active": 2, "disabled": 1 },
    "sessions": { "active": 41 },
    "generated_at": "2026-08-26T06:00:00Z"
  },
  "error": null
}
```

`by_*` 3개는 **값이 0인 키도 반드시 포함**됩니다. 키 존재 여부를 검사할 필요가 없습니다.
`by_billing_tier`는 만료된 결제등급을 `free`로 강등해서 셉니다(판정 규칙과 동일).

가능한 에러: `AUTH_REQUIRED`(401) · `FORBIDDEN`(403) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

```bash
curl -s '<<설치루트>>/server/api/admin/stats.php' \
  -H 'Authorization: Bearer <<관리자ACCESS_TOKEN>>'
```

#### (26) GET `<<설치루트>>/server/api/admin/users.php` — 회원 목록

최소 역할: `manager`

쿼리: `q`(아이디·이메일·이름·닉네임 부분 일치) · `status` · `role` · `account_level` · `billing_tier` · `service_id` · `from` · `to` · `sort`(`created_at` `last_login_at` `username` `points` `login_count` 중 하나) · `order`(`asc`/`desc`) · `page` · `per_page`(기본 20)

성공 전문:

```json
{
  "ok": true,
  "data": {
    "items": [
      {
        "uuid": "3f1b8c2a-7d4e-4a91-9c33-2b6e0f5a1d77",
        "username": "hong",
        "email": "hong@example.com",
        "name": "홍길동",
        "nickname": "길동",
        "phone": null,
        "avatar_url": null,
        "status": "active",
        "account_level": "verified",
        "account_level_rank": 20,
        "role": "user",
        "role_rank": 0,
        "billing_tier": "lite",
        "billing_tier_rank": 10,
        "billing_started_at": "2026-08-01T00:00:00Z",
        "billing_expires_at": "2026-12-31T23:59:59Z",
        "billing_active": true,
        "points": 90,
        "total_points_earned": 100,
        "total_points_spent": 10,
        "email_verified": true,
        "email_verified_at": "2026-08-02T05:11:00Z",
        "two_factor_enabled": false,
        "timezone": "Asia/Seoul",
        "locale": "ko-KR",
        "marketing_agreed": false,
        "created_at": "2026-08-01T09:00:00Z",
        "updated_at": "2026-08-26T05:00:00Z",
        "last_login_at": "2026-08-26T04:12:00Z",
        "login_count": 12,
        "service_id": null,
        "service_joined_at": null,
        "meta": {},
        "admin_view": true,
        "memo": null,
        "last_login_ip": "203.0.113.9",
        "failed_login_count": 0,
        "locked_until": null,
        "withdrawn_at": null,
        "withdraw_reason": null,
        "global_account_level": "verified",
        "global_role": "user",
        "global_billing_tier": "lite",
        "session_count": 1,
        "service_overrides": []
      }
    ],
    "total": 128,
    "page": 1,
    "per_page": 20,
    "filters_applied": { "status": "active" }
  },
  "error": null
}
```

**목록에서는 `service_overrides`가 항상 빈 배열입니다.** 상세 조회에서만 채워집니다.
`filters_applied`는 실제로 적용된 필터만 담고, 없으면 `{}`입니다. `sort`/`order`는 여기에 들어가지 않습니다.

가능한 에러: `VALIDATION_FAILED`(400, 허용되지 않은 `sort`/`order`/필터값) · `AUTH_REQUIRED`(401) · `FORBIDDEN`(403) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

```bash
curl -s '<<설치루트>>/server/api/admin/users.php?status=active&sort=created_at&order=desc&page=1&per_page=20' \
  -H 'Authorization: Bearer <<관리자ACCESS_TOKEN>>'
```

#### (27) GET `<<설치루트>>/server/api/admin/user_detail.php?uuid=…` — 회원 상세

> 계약서의 `GET /api/admin/users/{uuid}` → **`user_detail.php?uuid=`**

최소 역할: `manager`

성공 전문:

```json
{
  "ok": true,
  "data": {
    "user": {
      "uuid": "3f1b8c2a-7d4e-4a91-9c33-2b6e0f5a1d77",
      "username": "hong",
      "email": "hong@example.com",
      "name": "홍길동",
      "nickname": "길동",
      "phone": null,
      "avatar_url": null,
      "status": "active",
      "account_level": "verified",
      "account_level_rank": 20,
      "role": "user",
      "role_rank": 0,
      "billing_tier": "lite",
      "billing_tier_rank": 10,
      "billing_started_at": "2026-08-01T00:00:00Z",
      "billing_expires_at": "2026-12-31T23:59:59Z",
      "billing_active": true,
      "points": 90,
      "total_points_earned": 100,
      "total_points_spent": 10,
      "email_verified": true,
      "email_verified_at": "2026-08-02T05:11:00Z",
      "two_factor_enabled": false,
      "timezone": "Asia/Seoul",
      "locale": "ko-KR",
      "marketing_agreed": false,
      "created_at": "2026-08-01T09:00:00Z",
      "updated_at": "2026-08-26T05:00:00Z",
      "last_login_at": "2026-08-26T04:12:00Z",
      "login_count": 12,
      "service_id": null,
      "service_joined_at": null,
      "meta": {},
      "admin_view": true,
      "memo": null,
      "last_login_ip": "203.0.113.9",
      "failed_login_count": 0,
      "locked_until": null,
      "withdrawn_at": null,
      "withdraw_reason": null,
      "global_account_level": "verified",
      "global_role": "user",
      "global_billing_tier": "lite",
      "session_count": 1,
      "service_overrides": [
        {
          "service_id": "local-ai-app",
          "service_name": "로컬 AI 앱",
          "account_level": null,
          "role": null,
          "billing_tier": null,
          "billing_expires_at": null,
          "points": 0,
          "status": "active",
          "joined_at": "2026-08-01T09:00:05Z",
          "last_used_at": "2026-08-26T05:00:00Z"
        }
      ]
    },
    "recent_logins": [
      { "id": 812, "result": "success", "service_id": "local-ai-app", "ip": "203.0.113.9", "created_at": "2026-08-26T04:12:00Z" }
    ],
    "recent_points": [
      { "id": 42, "delta": -10, "balance_after": 90, "reason": "usage", "service_id": "local-ai-app", "memo": null, "admin_id": null, "created_at": "2026-08-26T05:00:00Z" }
    ],
    "active_sessions": [
      {
        "session_id": "a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90",
        "auth_type": "token",
        "service_id": "local-ai-app",
        "issued_at": "2026-08-26T04:12:00Z",
        "expires_at": "2026-08-26T05:12:00Z",
        "refresh_expires_at": "2026-09-09T04:12:00Z",
        "last_seen_at": "2026-08-26T05:00:00Z",
        "device_label": "Chrome / Windows",
        "ip": "203.0.113.9",
        "user_agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126.0",
        "is_current": false
      }
    ]
  },
  "error": null
}
```

`recent_points[].admin_id`는 내부 숫자 ID가 아니라 **조작한 관리자의 uuid 문자열**이거나 `null`입니다.

가능한 에러: `VALIDATION_FAILED`(400, uuid 형식 오류) · `NOT_FOUND`(404) · `AUTH_REQUIRED`(401) · `FORBIDDEN`(403) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

#### (28) PUT `<<설치루트>>/server/api/admin/user_detail.php?uuid=…` — 회원 수정

최소 역할: `admin` · CSRF 필요

요청 (보낸 키만 처리됩니다):

```json
{
  "account_level": "vip",
  "role": "manager",
  "billing_tier": "pro",
  "billing_started_at": "2026-08-01",
  "billing_expires_at": "2027-08-01",
  "status": "active",
  "memo": "VIP 전환 요청 처리",
  "name": "홍길동",
  "nickname": "길동",
  "phone": "010-1234-5678",
  "email": "new@example.com",
  "service_overrides": [
    {
      "service_id": "local-ai-app",
      "account_level": "vip",
      "role": null,
      "billing_tier": "pro",
      "billing_expires_at": "2027-08-01",
      "status": "active"
    }
  ],
  "reason": "고객 요청"
}
```

- `points`나 `password`를 보내면 **명시적으로 거부**됩니다(`VALIDATION_FAILED`). 각각 전용 엔드포인트를 쓰세요.
- `role` 규칙: 값이 실제로 바뀔 때만 검사합니다(같은 값 재전송은 no-op이라 폼 전체 전송이 안전합니다). 자신보다 높은 권한 부여 불가, 자기 강등 불가, 마지막 `superadmin` 강등 불가.
- `status`를 `suspended` / `dormant` / `withdrawn`으로 바꾸면 그 사용자의 **모든 세션이 회수**됩니다.
- `email`을 바꾸면 `email_verified_at`이 `null`로 되돌아갑니다.
- 서비스 오버라이드의 `status`를 `blocked`로 만들면 **그 서비스의 세션만** 즉시 회수됩니다(전역 세션과 다른 서비스 세션은 유지됩니다).
- 날짜 필드는 `YYYY-MM-DD` 또는 ISO8601을 받습니다.

성공 전문:

```json
{
  "ok": true,
  "data": {
    "user": {
      "uuid": "3f1b8c2a-7d4e-4a91-9c33-2b6e0f5a1d77",
      "username": "hong",
      "email": "new@example.com",
      "name": "홍길동",
      "nickname": "길동",
      "phone": "010-1234-5678",
      "avatar_url": null,
      "status": "active",
      "account_level": "vip",
      "account_level_rank": 30,
      "role": "manager",
      "role_rank": 10,
      "billing_tier": "pro",
      "billing_tier_rank": 20,
      "billing_started_at": "2026-08-01T00:00:00Z",
      "billing_expires_at": "2027-08-01T00:00:00Z",
      "billing_active": true,
      "points": 90,
      "total_points_earned": 100,
      "total_points_spent": 10,
      "email_verified": false,
      "email_verified_at": null,
      "two_factor_enabled": false,
      "timezone": "Asia/Seoul",
      "locale": "ko-KR",
      "marketing_agreed": false,
      "created_at": "2026-08-01T09:00:00Z",
      "updated_at": "2026-08-26T06:05:00Z",
      "last_login_at": "2026-08-26T04:12:00Z",
      "login_count": 12,
      "service_id": null,
      "service_joined_at": null,
      "meta": {},
      "admin_view": true,
      "memo": "VIP 전환 요청 처리",
      "last_login_ip": "203.0.113.9",
      "failed_login_count": 0,
      "locked_until": null,
      "withdrawn_at": null,
      "withdraw_reason": null,
      "global_account_level": "vip",
      "global_role": "manager",
      "global_billing_tier": "pro",
      "session_count": 1,
      "service_overrides": [
        { "service_id": "local-ai-app", "service_name": "로컬 AI 앱", "account_level": "vip", "role": null, "billing_tier": "pro", "billing_expires_at": "2027-08-01T00:00:00Z", "points": 0, "status": "active", "joined_at": "2026-08-01T09:00:05Z", "last_used_at": "2026-08-26T05:00:00Z" }
      ]
    },
    "changed_fields": ["account_level", "role", "billing_tier", "billing_expires_at", "memo", "email", "service_overrides"],
    "admin_log_id": 907
  },
  "error": null
}
```

가능한 에러: `VALIDATION_FAILED`(400) · `DUPLICATE_EMAIL`(409) · `NOT_FOUND`(404) · `FORBIDDEN`(403) · `AUTH_REQUIRED`(401) · `CSRF_FAILED`(403) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

#### (29) DELETE `<<설치루트>>/server/api/admin/user_detail.php?uuid=…&confirm={username}` — 회원 하드 삭제

최소 역할: `superadmin` · CSRF 필요
`confirm`에 **대상의 아이디를 정확히** 넣어야 합니다. 쿼리 또는 본문(쿼리 우선).

성공 전문:

```json
{
  "ok": true,
  "data": {
    "deleted": true,
    "uuid": "3f1b8c2a-7d4e-4a91-9c33-2b6e0f5a1d77",
    "username": "hong",
    "purged": { "sessions": 2, "user_services": 1, "user_consents": 2, "auth_codes": 0, "password_resets": 1, "email_verifications": 0 },
    "anonymized": { "login_logs": 34, "point_logs": 12, "admin_logs": 0 },
    "admin_log_id": 908
  },
  "error": null
}
```

> **하드 삭제 후에도 `point_logs`와 `admin_logs`에는 삭제된 회원의 내부 숫자 ID가 남습니다.** 두 테이블의 참조 컬럼이 `NOT NULL`이라 익명화할 수 없습니다. `anonymized`의 그 두 값은 "지운 개수"가 아니라 **남아 있는 행 수**입니다. 실제로 `user_id`가 `NULL`로 바뀌는 것은 `login_logs`뿐입니다.

가능한 에러: `VALIDATION_FAILED`(400, `confirm` 불일치) · `NOT_FOUND`(404) · `FORBIDDEN`(403 — 자기 자신 / 마지막 superadmin / 동급 이상 계정) · `AUTH_REQUIRED`(401) · `CSRF_FAILED`(403) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

#### (30) POST `<<설치루트>>/server/api/admin/user_points.php?uuid=…` — 포인트 조정

> 계약서의 `POST /api/admin/users/{uuid}/points` → **`user_points.php?uuid=`**

최소 역할: `admin` · CSRF 필요

요청: `{"delta":500,"reason":"admin_adjust","memo":"이벤트 보상"}`
`delta`는 **부호 있는 정수**(-1000000000 ~ 1000000000)이고 `0`은 거부됩니다. 조정 후 잔액이 음수이거나 20억을 넘으면 거부됩니다.

성공 전문:

```json
{
  "ok": true,
  "data": {
    "points": 590,
    "delta": 500,
    "balance_after": 590,
    "total_points_earned": 600,
    "total_points_spent": 10,
    "log_id": 43,
    "admin_log_id": 909,
    "created_at": "2026-08-26T06:10:00Z"
  },
  "error": null
}
```

가능한 에러: `VALIDATION_FAILED`(400) · `POINT_INSUFFICIENT`(409, 회수량이 잔액 초과 — `data.points`·`data.required`·`data.shortfall` 포함) · `NOT_FOUND`(404) · `FORBIDDEN`(403) · `AUTH_REQUIRED`(401) · `CSRF_FAILED`(403) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

#### (31) POST `<<설치루트>>/server/api/admin/user_status.php?uuid=…` — 정지 / 복구 / 비밀번호 초기화

> 계약서의 `.../suspend`, `.../restore`, `.../reset-password` 3개가 **한 파일 + 본문 `action` 필드**로 합쳐졌습니다.

최소 역할: `admin` · CSRF 필요 · `action`은 `suspend` | `restore` | `reset-password` (필수)

**(a) `action: "suspend"`** — 요청 `{"action":"suspend","reason":"약관 위반","memo":"3차 경고"}`

```json
{
  "ok": true,
  "data": { "uuid": "3f1b8c2a-7d4e-4a91-9c33-2b6e0f5a1d77", "status": "suspended", "previous_status": "active", "revoked_count": 2, "suspended_at": "2026-08-26T06:20:00Z", "admin_log_id": 910 },
  "error": null
}
```

**(b) `action: "restore"`** — 요청 `{"action":"restore","new_username":"hong2","new_email":"hong2@example.com","reason":"오처리 복구"}`

`new_username` / `new_email`은 **탈퇴 계정 복구에서만** 지정할 수 있습니다. 탈퇴 계정 복구 자체는 `superadmin`만 가능합니다. 탈퇴 시 붙었던 `wd_{id}_` 접두사는 자동으로 벗겨집니다.

```json
{
  "ok": true,
  "data": { "uuid": "3f1b8c2a-7d4e-4a91-9c33-2b6e0f5a1d77", "status": "active", "previous_status": "suspended", "username": "hong", "email": "hong@example.com", "restored_at": "2026-08-26T06:25:00Z", "admin_log_id": 911 },
  "error": null
}
```

**(c) `action: "reset-password"`** — 요청 `{"action":"reset-password","send_email":false,"reason":"본인 요청"}`

```json
{
  "ok": true,
  "data": { "uuid": "3f1b8c2a-7d4e-4a91-9c33-2b6e0f5a1d77", "temp_password": "K7m#pQ2xR9tA", "email_sent": false, "revoked_count": 2, "password_changed_at": "2026-08-26T06:30:00Z", "admin_log_id": 912 },
  "error": null
}
```

> **`temp_password`는 이 응답에서 딱 한 번만 보입니다.** 다시 조회할 수 없고 감사 로그에도 남지 않습니다. `send_email: true`로 보내고 실제 발송에 성공하면 `temp_password`는 `"********"`로 마스킹됩니다.

가능한 에러: `VALIDATION_FAILED`(400) · `CONFLICT`(409 — 이미 정지됨 / 이미 활성 / 탈퇴 계정은 정지 불가 / 아이디·이메일 중복) · `NOT_FOUND`(404) · `FORBIDDEN`(403) · `AUTH_REQUIRED`(401) · `CSRF_FAILED`(403) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

#### (32) DELETE `<<설치루트>>/server/api/admin/user_sessions.php?uuid=…` — 회원 전 세션 강제 종료

> 계약서의 `DELETE /api/admin/users/{uuid}/sessions` → **`user_sessions.php?uuid=`**

최소 역할: `admin` · CSRF 필요 · 선택 본문 `{"reason":"보안 사고 대응"}`

성공 전문:

```json
{
  "ok": true,
  "data": { "uuid": "3f1b8c2a-7d4e-4a91-9c33-2b6e0f5a1d77", "revoked_count": 3, "revoked_at": "2026-08-26T06:40:00Z", "admin_log_id": 913 },
  "error": null
}
```

가능한 에러: `VALIDATION_FAILED`(400) · `NOT_FOUND`(404) · `FORBIDDEN`(403) · `AUTH_REQUIRED`(401) · `CSRF_FAILED`(403) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

#### (33) GET `<<설치루트>>/server/api/admin/services.php` — 서비스 목록

최소 역할: `admin` · 쿼리: `q`, `status`(`active`/`disabled`), `service_id`, `page`, `per_page`

성공 전문:

```json
{
  "ok": true,
  "data": {
    "items": [
      {
        "service_id": "local-ai-app",
        "service_name": "로컬 AI 앱",
        "description": "데스크톱 AI 챗봇",
        "is_confidential": false,
        "has_secret": false,
        "redirect_whitelist": ["http://localhost:5173/callback.html"],
        "allowed_origins": ["http://localhost:5173"],
        "auto_join": true,
        "default_account_level": null,
        "session_ttl": 3600,
        "refresh_ttl": 1209600,
        "payload_fields": null,
        "allow_register": true,
        "status": "active",
        "secret_count": 3,
        "user_count": 128,
        "created_at": "2026-08-01T00:00:00Z",
        "updated_at": "2026-08-20T00:00:00Z"
      }
    ],
    "total": 1,
    "page": 1,
    "per_page": 20
  },
  "error": null
}
```

`service_secret_hash`는 **절대 응답에 포함되지 않습니다.** 대신 `has_secret` 불리언이 옵니다.

가능한 에러: `VALIDATION_FAILED`(400) · `AUTH_REQUIRED`(401) · `FORBIDDEN`(403) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

#### (34) POST `<<설치루트>>/server/api/admin/services.php` — 서비스 생성

최소 역할: `admin` · CSRF 필요

요청:

```json
{
  "service_id": "local-ai-app",
  "service_name": "로컬 AI 앱",
  "description": "데스크톱 AI 챗봇",
  "redirect_whitelist": ["http://localhost:5173/callback.html"],
  "allowed_origins": ["http://localhost:5173"],
  "auto_join": true,
  "allow_register": true,
  "default_account_level": null,
  "session_ttl": 3600,
  "refresh_ttl": 1209600,
  "payload_fields": null,
  "is_confidential": false,
  "service_secret": null,
  "status": "active",
  "reason": "신규 앱 연동"
}
```

- `service_id` 형식: `^[a-z0-9][a-z0-9\-_]{2,63}$`
- `redirect_whitelist` / `allowed_origins`: 절대 URL, `http`/`https`만, 쿼리(`?`)·프래그먼트(`#`) 금지, 각 항목 500자 이내, 최대 50개. `allowed_origins`는 경로를 넣을 수 없습니다(`scheme://host[:port]` 형태만).
- `is_confidential: true`면 `service_secret`(8~255자)이 필수입니다.
- `session_ttl` 60~604800 · `refresh_ttl` 300~31536000

성공 (201) 전문:

```json
{
  "ok": true,
  "data": {
    "service": {
      "service_id": "local-ai-app",
      "service_name": "로컬 AI 앱",
      "description": "데스크톱 AI 챗봇",
      "is_confidential": false,
      "has_secret": false,
      "redirect_whitelist": ["http://localhost:5173/callback.html"],
      "allowed_origins": ["http://localhost:5173"],
      "auto_join": true,
      "default_account_level": null,
      "session_ttl": 3600,
      "refresh_ttl": 1209600,
      "payload_fields": null,
      "allow_register": true,
      "status": "active",
      "secret_count": 0,
      "user_count": 0,
      "created_at": "2026-08-26T07:00:00Z",
      "updated_at": "2026-08-26T07:00:00Z"
    },
    "service_secret_plain": null,
    "admin_log_id": 914
  },
  "error": null
}
```

`service_secret_plain`은 **이 응답에서 한 번만** 보입니다. 이후 조회할 수 없습니다.

가능한 에러: `VALIDATION_FAILED`(400) · `DUPLICATE_SERVICE_ID`(409) · `AUTH_REQUIRED`(401) · `FORBIDDEN`(403) · `CSRF_FAILED`(403) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

#### (35) PUT `<<설치루트>>/server/api/admin/services.php?service_id=…` — 서비스 수정

최소 역할: `admin` · CSRF 필요 · 보낸 키만 처리

수정 가능한 필드: `service_name` `description` `redirect_whitelist` `allowed_origins` `auto_join` `allow_register` `default_account_level` `session_ttl` `refresh_ttl` `status` `payload_fields` `is_confidential` `service_secret` `regenerate_secret`(true면 서버가 48자 hex를 새로 만듭니다) `reason`

**`service_id`는 변경할 수 없습니다.** 본문에 다른 값을 넣으면 `VALIDATION_FAILED`로 거부되며 메시지는 "서비스 ID 는 변경할 수 없습니다. 새로 만들고 기존 서비스를 disabled 로 내려 주세요."입니다.

성공 전문:

```json
{
  "ok": true,
  "data": {
    "service": {
      "service_id": "local-ai-app",
      "service_name": "로컬 AI 앱",
      "description": "데스크톱 AI 챗봇",
      "is_confidential": false,
      "has_secret": false,
      "redirect_whitelist": ["http://localhost:5173/callback.html", "https://myapp.example.com/callback.html"],
      "allowed_origins": ["http://localhost:5173", "https://myapp.example.com"],
      "auto_join": true,
      "default_account_level": null,
      "session_ttl": 3600,
      "refresh_ttl": 1209600,
      "payload_fields": null,
      "allow_register": true,
      "status": "active",
      "secret_count": 3,
      "user_count": 128,
      "created_at": "2026-08-01T00:00:00Z",
      "updated_at": "2026-08-26T07:20:00Z"
    },
    "service_secret_plain": null,
    "changed_fields": ["redirect_whitelist", "allowed_origins"],
    "admin_log_id": 915
  },
  "error": null
}
```

가능한 에러: `VALIDATION_FAILED`(400) · `NOT_FOUND`(404) · `AUTH_REQUIRED`(401) · `FORBIDDEN`(403) · `CSRF_FAILED`(403) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

```bash
curl -s -X PUT '<<설치루트>>/server/api/admin/services.php?service_id=<<서비스ID>>' \
  -H 'Authorization: Bearer <<관리자ACCESS_TOKEN>>' \
  -H 'Content-Type: application/json' \
  -d '{"redirect_whitelist":["http://localhost:5173/callback.html","https://myapp.example.com/callback.html"],"allowed_origins":["http://localhost:5173","https://myapp.example.com"]}'
```

#### (36) DELETE `<<설치루트>>/server/api/admin/services.php?service_id=…` — 서비스 삭제

최소 역할: `admin` · CSRF 필요
가입자가 1명 이상이면 `force: true` **와** `confirm`(= `service_id` 문자열 그대로)이 함께 필요합니다.

성공 전문:

```json
{
  "ok": true,
  "data": {
    "deleted": true,
    "service_id": "local-ai-app",
    "purged": { "service_secrets": 3, "user_services": 128, "sessions": 41, "auth_codes": 0 },
    "admin_log_id": 916
  },
  "error": null
}
```

가입자가 있는데 `force`가 없을 때 (409) 전문:

```json
{
  "ok": false,
  "data": { "user_count": 128, "secret_count": 3 },
  "error": { "code": "CONFLICT", "message": "가입 사용자가 있는 서비스입니다. force 옵션과 확인 문구가 필요합니다.", "field": null }
}
```

가능한 에러: `CONFLICT`(409) · `VALIDATION_FAILED`(400, `confirm` 불일치) · `NOT_FOUND`(404) · `AUTH_REQUIRED`(401) · `FORBIDDEN`(403) · `CSRF_FAILED`(403) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

#### (37) GET `<<설치루트>>/server/api/admin/secrets.php?service_id=…` — 시크릿 목록

최소 역할: `admin` · 쿼리: `service_id`(필수), `page`, `per_page`

성공 전문:

```json
{
  "ok": true,
  "data": {
    "service_id": "local-ai-app",
    "items": [
      { "id": 1, "secret_key": "ai_api_key", "secret_value": "****", "is_masked": true, "value_type": "string", "min_account_level": null, "min_billing_tier": "lite", "min_role": null, "sort_order": 0, "description": "OpenAI 키", "status": "active", "created_at": "2026-08-01T00:00:00Z", "updated_at": "2026-08-01T00:00:00Z" },
      { "id": 2, "secret_key": "api_base", "secret_value": "****", "is_masked": false, "value_type": "url", "min_account_level": null, "min_billing_tier": null, "min_role": null, "sort_order": 1, "description": null, "status": "active", "created_at": "2026-08-01T00:00:00Z", "updated_at": "2026-08-01T00:00:00Z" },
      { "id": 3, "secret_key": "model", "secret_value": "****", "is_masked": false, "value_type": "string", "min_account_level": null, "min_billing_tier": null, "min_role": null, "sort_order": 2, "description": null, "status": "active", "created_at": "2026-08-01T00:00:00Z", "updated_at": "2026-08-01T00:00:00Z" }
    ],
    "total": 3,
    "page": 1,
    "per_page": 20
  },
  "error": null
}
```

> **`secret_value`는 `is_masked` 값과 무관하게 항상 `"****"` 입니다.** 목록·상세·생성·수정 응답 전부 그렇습니다. 평문은 아래 `reveal` 한 경로에서만 나갑니다. `is_masked`는 콘솔의 "값 보기" 버튼 노출 여부 힌트일 뿐이며 **보안 경계가 아닙니다.**

가능한 에러: `VALIDATION_FAILED`(400) · `NOT_FOUND`(404, 서비스 없음) · `AUTH_REQUIRED`(401) · `FORBIDDEN`(403) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

#### (38) GET `<<설치루트>>/server/api/admin/secrets.php?service_id=…&action=reveal&id=1` — 시크릿 평문 조회

최소 역할: **`superadmin`** (이 하나만 `admin`으로 불충분합니다)
조회 사실이 `admin_logs`에 `secret.reveal`로 기록됩니다(값 자체는 기록되지 않습니다).

성공 전문:

```json
{
  "ok": true,
  "data": { "id": 1, "secret_key": "ai_api_key", "secret_value": "sk-proj-xxxxxxxxxxxxxxxx", "value_type": "string", "revealed_at": "2026-08-26T07:00:00Z", "admin_log_id": 917 },
  "error": null
}
```

가능한 에러: `VALIDATION_FAILED`(400) · `NOT_FOUND`(404) · `FORBIDDEN`(403, `admin`이지만 `superadmin`이 아님) · `AUTH_REQUIRED`(401) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

#### (39) POST `<<설치루트>>/server/api/admin/secrets.php?service_id=…` — 시크릿 생성

최소 역할: `admin` · CSRF 필요

요청:

```json
{
  "secret_key": "ai_api_key",
  "secret_value": "sk-proj-xxxxxxxxxxxxxxxx",
  "value_type": "string",
  "min_account_level": null,
  "min_billing_tier": "lite",
  "min_role": null,
  "is_masked": true,
  "sort_order": 0,
  "description": "OpenAI 키",
  "status": "active",
  "allow_public": false,
  "reason": "신규 등록"
}
```

- `secret_key` 형식: `^[a-z0-9][a-z0-9_]{1,99}$`
- `value_type`이 `json`이면 `secret_value`가 유효한 JSON 문자열이어야 하고, `url`이면 `http(s)` 절대 URL이어야 합니다.
- **민감 키 판정 정규식(소문자 변환 후):** `/(?:^|[_\-])(api_?key|secret|token|password|passwd|credential)s?$/`
  민감으로 판정되는 예: `ai_api_key` `api_key` `apikey` `x_secret` `client-secret` `refresh_token` `db_password` `passwd` `aws_credentials`
  민감이 아닌 예: `api_base` `model` `endpoint` `org_id` `api_key_hint` `keyring`
- 민감 키인데 **실효 게이트가 0개**면 `allow_public: true` 없이는 400으로 거부됩니다. 랭크 0 값(`free` / `guest` / `user`)은 게이트로 인정되지 않습니다.

성공 (201) 전문:

```json
{
  "ok": true,
  "data": {
    "secret": { "id": 1, "secret_key": "ai_api_key", "secret_value": "****", "is_masked": true, "value_type": "string", "min_account_level": null, "min_billing_tier": "lite", "min_role": null, "sort_order": 0, "description": "OpenAI 키", "status": "active", "created_at": "2026-08-26T07:10:00Z", "updated_at": "2026-08-26T07:10:00Z" },
    "admin_log_id": 918
  },
  "error": null
}
```

게이트 거부 (400) 전문:

```json
{
  "ok": false,
  "data": null,
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "민감한 시크릿(ai_api_key)에는 최소 등급 게이트가 필요합니다. 게이트를 비우면 가입한 모든 사용자가 이 값을 받습니다. min_billing_tier=lite 를 권장하며, 의도한 공개라면 allow_public=true 를 함께 보내십시오. (free / guest / user 는 랭크 0 이라 게이트로 인정되지 않습니다.)",
    "field": "min_billing_tier"
  }
}
```

가능한 에러: `VALIDATION_FAILED`(400) · `DUPLICATE_SECRET_KEY`(409) · `NOT_FOUND`(404) · `AUTH_REQUIRED`(401) · `FORBIDDEN`(403) · `CSRF_FAILED`(403) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

```bash
curl -s -X POST '<<설치루트>>/server/api/admin/secrets.php?service_id=<<서비스ID>>' \
  -H 'Authorization: Bearer <<관리자ACCESS_TOKEN>>' \
  -H 'Content-Type: application/json' \
  -d '{"secret_key":"ai_api_key","secret_value":"sk-proj-xxxx","min_billing_tier":"lite","description":"OpenAI 키"}'
```

#### (40) PUT `<<설치루트>>/server/api/admin/secrets.php?service_id=…&id=1` — 시크릿 수정

최소 역할: `admin` · CSRF 필요 · 보낸 키만 처리

수정 가능한 필드: `secret_key` `secret_value` `value_type` `min_account_level` `min_billing_tier` `min_role` `is_masked` `sort_order` `description` `status` `allow_public` `reason`

**게이트 검사는 수정에도 똑같이 적용됩니다.** `secret_key` 또는 게이트 3개 중 하나라도 요청에 들어 있으면 재검사합니다. 그래서 아래 두 우회가 모두 막힙니다:
- 기존 게이트를 전부 지우는 PUT
- 비민감 키(`api_base`)를 민감한 이름(`stolen_token`)으로 개명하는 PUT

`secret_value`에 `null`을 보내면 거부됩니다. 값을 비우려면 빈 문자열을 보내세요.

성공 전문:

```json
{
  "ok": true,
  "data": {
    "secret": { "id": 1, "secret_key": "ai_api_key", "secret_value": "****", "is_masked": true, "value_type": "string", "min_account_level": null, "min_billing_tier": "pro", "min_role": null, "sort_order": 0, "description": "OpenAI 키", "status": "active", "created_at": "2026-08-01T00:00:00Z", "updated_at": "2026-08-26T07:30:00Z" },
    "changed_fields": ["secret_value", "min_billing_tier"],
    "value_changed": true,
    "admin_log_id": 919
  },
  "error": null
}
```

가능한 에러: `VALIDATION_FAILED`(400) · `DUPLICATE_SECRET_KEY`(409) · `NOT_FOUND`(404) · `AUTH_REQUIRED`(401) · `FORBIDDEN`(403) · `CSRF_FAILED`(403) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

#### (41) DELETE `<<설치루트>>/server/api/admin/secrets.php?service_id=…&id=1` — 시크릿 삭제

최소 역할: `admin` · CSRF 필요 · 선택 본문 `{"reason":"교체 완료"}`

성공 전문:

```json
{
  "ok": true,
  "data": { "deleted": true, "id": 1, "secret_key": "ai_api_key", "service_id": "local-ai-app", "admin_log_id": 920 },
  "error": null
}
```

가능한 에러: `VALIDATION_FAILED`(400) · `NOT_FOUND`(404) · `AUTH_REQUIRED`(401) · `FORBIDDEN`(403) · `CSRF_FAILED`(403) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

#### (42) GET `<<설치루트>>/server/api/admin/logs.php?type=login` — 로그인 로그

> 계약서의 `/api/admin/logs/login` → **`logs.php?type=login`**

최소 역할: `manager`
쿼리: `type=login`(기본값), `q`, `result`, `service_id`, `ip`, `uuid`, `from`, `to`, `page`, `per_page`
`result` 허용값 7종: `success` `fail_credential` `fail_locked` `fail_status` `fail_ratelimit` `fail_service_blocked` `logout`

성공 전문:

```json
{
  "ok": true,
  "data": {
    "items": [
      {
        "id": 812,
        "user_uuid": "3f1b8c2a-7d4e-4a91-9c33-2b6e0f5a1d77",
        "username": "hong",
        "username_input": "hong",
        "service_id": "local-ai-app",
        "result": "success",
        "ip": "203.0.113.9",
        "user_agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126.0",
        "created_at": "2026-08-26T04:12:00Z"
      }
    ],
    "total": 1,
    "page": 1,
    "per_page": 20,
    "summary": { "success": 210, "fail_credential": 8, "fail_locked": 1, "fail_status": 2, "fail_ratelimit": 0, "fail_service_blocked": 1, "logout": 96 }
  },
  "error": null
}
```

`summary`는 **7개 키를 항상 전부** 포함합니다(값이 0인 것도).

가능한 에러: `VALIDATION_FAILED`(400) · `NOT_FOUND`(404, `uuid` 필터의 회원 없음) · `AUTH_REQUIRED`(401) · `FORBIDDEN`(403) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

#### (43) GET `<<설치루트>>/server/api/admin/logs.php?type=admin` — 관리자 조작 로그

> 계약서의 `/api/admin/logs/admin` → **`logs.php?type=admin`**

최소 역할: **`admin`** (`manager`는 이 타입에 접근할 수 없습니다)
쿼리: `type=admin`, `action`, `target_type`, `target_id`, `admin_uuid`, `from`, `to`, `page`, `per_page`

성공 전문:

```json
{
  "ok": true,
  "data": {
    "items": [
      {
        "id": 918,
        "admin_uuid": "aa11bb22-cc33-4d44-8e55-ff6677889900",
        "admin_username": "root",
        "action": "secret.create",
        "target_type": "secret",
        "target_id": "1",
        "target_label": "local-ai-app / ai_api_key",
        "before": null,
        "after": { "service_id": "local-ai-app", "secret_key": "ai_api_key", "value_type": "string", "is_masked": true, "status": "active", "secret_value": "(set)" },
        "memo": "신규 등록",
        "ip": "203.0.113.9",
        "created_at": "2026-08-26T07:10:00Z"
      }
    ],
    "total": 1,
    "page": 1,
    "per_page": 20
  },
  "error": null
}
```

`action` 값 목록: `user.update` `user.delete` `user.suspend` `user.restore` `user.reset_password` `user.points.adjust` `user.sessions.revoke` `service.create` `service.update` `service.delete` `secret.create` `secret.update` `secret.delete` `secret.reveal` `setting.update`
`target_type` 값 목록: `user` `service` `secret` `setting` `session`

가능한 에러: `VALIDATION_FAILED`(400) · `NOT_FOUND`(404) · `AUTH_REQUIRED`(401) · `FORBIDDEN`(403) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

#### (44) GET `<<설치루트>>/server/api/admin/settings.php` — 전역 설정 조회

최소 역할: `admin`

성공 전문 (**설정 키는 정확히 12개**):

```json
{
  "ok": true,
  "data": {
    "items": [
      { "skey": "allow_register", "svalue": true, "vtype": "bool", "label": "신규 가입 허용", "updated_at": null },
      { "skey": "require_email_verify", "svalue": false, "vtype": "bool", "label": "가입 시 이메일 인증 필수", "updated_at": null },
      { "skey": "signup_bonus_points", "svalue": 100, "vtype": "int", "label": "가입 보너스 포인트", "updated_at": null },
      { "skey": "login_fail_threshold", "svalue": 5, "vtype": "int", "label": "락아웃 임계 실패 횟수", "updated_at": null },
      { "skey": "login_lock_minutes", "svalue": 15, "vtype": "int", "label": "락아웃 지속 시간(분)", "updated_at": null },
      { "skey": "password_min_length", "svalue": 8, "vtype": "int", "label": "비밀번호 최소 길이", "updated_at": null },
      { "skey": "session_ttl_default", "svalue": 3600, "vtype": "int", "label": "기본 access 토큰 수명(초)", "updated_at": null },
      { "skey": "refresh_ttl_default", "svalue": 1209600, "vtype": "int", "label": "기본 refresh 토큰 수명(초)", "updated_at": null },
      { "skey": "maintenance_mode", "svalue": false, "vtype": "bool", "label": "점검 모드", "updated_at": null },
      { "skey": "mail_driver", "svalue": "log", "vtype": "string", "label": "메일 발송 방식 (mail|log)", "updated_at": null },
      { "skey": "site_name", "svalue": "ulogin", "vtype": "string", "label": "사이트 표시명", "updated_at": null },
      { "skey": "auth_code_ttl", "svalue": 60, "vtype": "int", "label": "SSO code 수명(초)", "updated_at": null }
    ],
    "total": 12,
    "map": {
      "allow_register": true,
      "require_email_verify": false,
      "signup_bonus_points": 100,
      "login_fail_threshold": 5,
      "login_lock_minutes": 15,
      "password_min_length": 8,
      "session_ttl_default": 3600,
      "refresh_ttl_default": 1209600,
      "maintenance_mode": false,
      "mail_driver": "log",
      "site_name": "ulogin",
      "auth_code_ttl": 60
    }
  },
  "error": null
}
```

가능한 에러: `AUTH_REQUIRED`(401) · `FORBIDDEN`(403) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

#### (45) PUT `<<설치루트>>/server/api/admin/settings.php` — 전역 설정 변경

최소 역할: `admin` · CSRF 필요

요청: `{"settings":{"allow_register":false,"signup_bonus_points":50},"reason":"가입 일시 중단"}`

허용 범위: `signup_bonus_points` 0~1000000 · `login_fail_threshold` 1~100 · `login_lock_minutes` 1~1440 · `password_min_length` 8~64 · `session_ttl_default` 60~604800 · `refresh_ttl_default` 300~31536000 · `auth_code_ttl` 30~600 · `mail_driver`는 `mail` 또는 `log` · `site_name`은 1~100자

성공 전문:

```json
{
  "ok": true,
  "data": {
    "updated": ["allow_register", "signup_bonus_points"],
    "unchanged": [],
    "map": {
      "allow_register": false,
      "require_email_verify": false,
      "signup_bonus_points": 50,
      "login_fail_threshold": 5,
      "login_lock_minutes": 15,
      "password_min_length": 8,
      "session_ttl_default": 3600,
      "refresh_ttl_default": 1209600,
      "maintenance_mode": false,
      "mail_driver": "log",
      "site_name": "ulogin",
      "auth_code_ttl": 60
    },
    "admin_log_id": 921
  },
  "error": null
}
```

정의되지 않은 키를 보내면 `NOT_FOUND`(404)가 오고 `error.field`에 그 키 이름이 담깁니다.

가능한 에러: `VALIDATION_FAILED`(400) · `NOT_FOUND`(404) · `AUTH_REQUIRED`(401) · `FORBIDDEN`(403) · `CSRF_FAILED`(403) · `METHOD_NOT_ALLOWED`(405) · `SERVER_ERROR`(500)

---

### 5-6. 엔드포인트 요약표 (51행 = 46조합)

**계수 기준을 함께 적습니다 — 이 프로젝트에서 43과 45가 반복해 갈라진 원인이 「기준을 라벨에 안 적은 것」이었습니다.**
**51행** = **메서드 × 경로 조합 46**(각 파일이 허용하는 메서드를 경로마다 센 값, `HEAD` 제외) + **쿼리로 동작·최소역할이 갈리는 별행 5**(`admin/secrets.php?action=reveal` · `admin/logs.php?type=admin` · `admin/logs.php?type=secret_access` · `admin/settings_bundle.php?action=` **3종이 1조합에서 3행이 되므로 +2**).
`HEAD`를 포함하면 조합은 47입니다(`api/health.php`가 `GET`과 `HEAD`를 함께 받습니다).

| # | 메서드 | 실제 URL (`<<설치루트>>` 뒤에 붙입니다) | 인증 | 최소 역할 |
|---|---|---|---|---|
| 1 | POST | `/server/api/auth/register.php` | 없음 | — |
| 2 | POST | `/server/api/auth/login.php` | 없음 | — |
| 3 | POST | `/server/api/auth/logout.php` | Bearer+CSRF | — |
| 4 | POST | `/server/api/auth/refresh.php` | 없음 | — |
| 5 | GET | `/server/api/auth/session.php` | Bearer | — |
| 6 | GET | `/server/api/auth/csrf.php` | 없음 | — |
| 7 | POST | `/server/api/auth/exchange.php` | 없음 | — |
| 8 | POST | `/server/api/auth/password_forgot.php` | 없음 | — |
| 9 | POST | `/server/api/auth/password_reset.php` | 없음 | — |
| 10 | POST | `/server/api/auth/email_verify.php` | 없음 | — |
| 11 | POST | `/server/api/auth/email_resend.php` | 없음 | — |
| 12 | GET | `/server/api/sso/validate.php?service_id=…&redirect_uri=…` | 없음 | — |
| 13 | POST | `/server/api/sso/authorize.php` | 없음 | — |
| 14 | GET | `/server/api/me/index.php` | Bearer | — |
| 15 | PUT | `/server/api/me/index.php` | Bearer+CSRF | — |
| 16 | PUT | `/server/api/me/password.php` | Bearer+CSRF | — |
| 17 | PUT | `/server/api/me/email.php` | Bearer+CSRF | — |
| 18 | GET | `/server/api/me/sessions.php` | Bearer | — |
| 19 | DELETE | `/server/api/me/sessions.php?session_id=…` | Bearer+CSRF | — |
| 20 | GET | `/server/api/me/login_logs.php` | Bearer | — |
| 21 | GET | `/server/api/me/services.php` | Bearer | — |
| 22 | GET | `/server/api/me/points.php` | Bearer | — |
| 23 | POST | `/server/api/me/withdraw.php` | Bearer+CSRF | — |
| 24 | POST | `/server/api/points/consume.php` | Bearer+CSRF | — |
| 25 | GET | `/server/api/admin/stats.php` | Bearer | manager |
| 26 | GET | `/server/api/admin/users.php` | Bearer | manager |
| 27 | GET | `/server/api/admin/user_detail.php?uuid=…` | Bearer | manager |
| 28 | PUT | `/server/api/admin/user_detail.php?uuid=…` | Bearer+CSRF | admin |
| 29 | DELETE | `/server/api/admin/user_detail.php?uuid=…&confirm=…` | Bearer+CSRF | superadmin |
| 30 | POST | `/server/api/admin/user_points.php?uuid=…` | Bearer+CSRF | admin |
| 31 | POST | `/server/api/admin/user_status.php?uuid=…` | Bearer+CSRF | admin |
| 32 | DELETE | `/server/api/admin/user_sessions.php?uuid=…` | Bearer+CSRF | admin |
| 33 | GET | `/server/api/admin/services.php` | Bearer | admin |
| 34 | POST | `/server/api/admin/services.php` | Bearer+CSRF | admin |
| 35 | PUT | `/server/api/admin/services.php?service_id=…` | Bearer+CSRF | admin |
| 36 | DELETE | `/server/api/admin/services.php?service_id=…` | Bearer+CSRF | admin |
| 37 | GET | `/server/api/admin/secrets.php?service_id=…` | Bearer | admin |
| 38 | GET | `/server/api/admin/secrets.php?service_id=…&action=reveal&id=…` | Bearer | superadmin |
| 39 | POST | `/server/api/admin/secrets.php?service_id=…` | Bearer+CSRF | admin |
| 40 | PUT | `/server/api/admin/secrets.php?service_id=…&id=…` | Bearer+CSRF | admin |
| 41 | DELETE | `/server/api/admin/secrets.php?service_id=…&id=…` | Bearer+CSRF | admin |
| 42 | GET | `/server/api/admin/logs.php?type=login` | Bearer | manager |
| 43 | GET | `/server/api/admin/logs.php?type=admin` | Bearer | admin |
| 44 | GET | `/server/api/admin/settings.php` | Bearer | admin |
| 45 | PUT | `/server/api/admin/settings.php` | Bearer+CSRF | admin |
| 46 | GET | `/server/api/health.php` | 없음 (선택적 Bearer) | — (`admin` 이상이면 상세 10필드) |
| 47 | GET | `/server/api/admin/logs.php?type=secret_access` | Bearer | admin |
| 48 | GET | `/server/api/admin/settings_bundle.php` | Bearer | **superadmin** |
| 49 | POST | `/server/api/admin/settings_bundle.php?action=validate` | Bearer+CSRF | **superadmin** |
| 50 | POST | `/server/api/admin/settings_bundle.php?action=dry-run` | Bearer+CSRF | **superadmin** |
| 51 | POST | `/server/api/admin/settings_bundle.php?action=apply` | Bearer+CSRF | **superadmin** |

**48 ~ 51은 v1.6.0 신설이고, 상세는 이 문서가 아니라 [`AI_SETTINGS_JSON_GUIDE.md`](AI_SETTINGS_JSON_GUIDE.md)에 있습니다.**
설정 번들 export / import 경로이고 **네 동작 모두 `superadmin` 전용**입니다 —
`admin`으로 호출하면 넷 다 403 `FORBIDDEN`입니다. **일반 연동 앱은 이 경로를 호출하지 않습니다.**
그 문서에 요청·응답 JSON 전문, 검증 오류 코드 18개 전수, 경고 코드 7개 전수가 있습니다.
**v1.5.0 이하 서버에서는 이 네 행이 404입니다**(파일이 없습니다).
`Authorization: Bearer`로 호출하면 CSRF 헤더가 필요 없습니다 — 이 문서의 다른 POST와 같은 규칙입니다(5-0절).

**46 · 47은 v1.4.0 신설이라 위 `(1)`~`(45)` 상세 항목이 없습니다.** 상세는
[「v1.3.0 → v1.4.0 에서 무엇이 바뀌었나」](#v130--v140-에서-무엇이-바뀌었나-연동-관점) 절의 **(2) 추가된 표면** 표에 있습니다.
46은 `HEAD`도 받습니다(모니터링 도구용).
**v1.3.0 이하 서버에서는** 46이 **404**(파일이 없습니다), 47은 **400 `VALIDATION_FAILED`**(`type`이 `login`·`admin` 두 값만 허용되던 시절의 값 검사에 걸립니다 — 404가 아닙니다)입니다.

---

## 6. 데이터 사전

### 6-1. `user` 객체 — 33필드 (모든 응답에서 동일)

값이 없어도 **필드가 빠지지 않고 `null`이 들어옵니다.** `'field' in user` 검사를 할 필요가 없습니다.

| # | 필드 | 타입 | null 가능 | 의미 |
|---|---|---|---|---|
| 1 | `uuid` | string(36) | 아니오 | 외부 노출 식별자. **모든 API가 이것을 씁니다.** 내부 숫자 id는 절대 노출되지 않습니다 |
| 2 | `username` | string(50) | 아니오 | 로그인 아이디. 소문자로 정규화되어 저장됩니다 |
| 3 | `email` | string(190) | 아니오 | 이메일. 소문자 정규화 |
| 4 | `name` | string(80) | 예 | 실명 |
| 5 | `nickname` | string(50) | 예 | 별명 |
| 6 | `phone` | string(30) | 예 | 전화번호 |
| 7 | `avatar_url` | string(255) | 예 | 프로필 이미지 URL. `http`/`https`만 저장됩니다 |
| 8 | `status` | string | 아니오 | `active` `pending` `suspended` `dormant` `withdrawn` 중 하나 |
| 9 | `account_level` | string | 아니오 | **effective** 계정등급. `guest` `basic` `verified` `vip` `staff` |
| 10 | `account_level_rank` | int | 아니오 | 위 값의 정수 랭크. `guest`0 `basic`10 `verified`20 `vip`30 `staff`90 |
| 11 | `role` | string | 아니오 | **effective** 역할. `user` `manager` `admin` `superadmin` |
| 12 | `role_rank` | int | 아니오 | `user`0 `manager`10 `admin`20 `superadmin`30 |
| 13 | `billing_tier` | string | 아니오 | **effective** 결제등급. `free` `lite` `pro` `enterprise`. **만료되면 판정 시점에 `free`로 강등되어 반환됩니다** |
| 14 | `billing_tier_rank` | int | 아니오 | `free`0 `lite`10 `pro`20 `enterprise`30 |
| 15 | `billing_started_at` | string ISO8601 | 예 | 결제 시작 시각(UTC) |
| 16 | `billing_expires_at` | string ISO8601 | 예 | 결제 만료 시각(UTC). `null`이면 무기한 |
| 17 | `billing_active` | bool | 아니오 | `billing_tier !== 'free'` (강등 후 판정). 즉 만료됐으면 `false` |
| 18 | `points` | int | 아니오 | 현재 포인트 잔액(전역). 음수가 되지 않습니다 |
| 19 | `total_points_earned` | int | 아니오 | 누적 적립 |
| 20 | `total_points_spent` | int | 아니오 | 누적 사용 |
| 21 | `email_verified` | bool | 아니오 | `email_verified_at`이 있으면 `true` |
| 22 | `email_verified_at` | string ISO8601 | 예 | 이메일 인증 완료 시각 |
| 23 | `two_factor_enabled` | bool | 아니오 | **v1.0.0에서는 항상 `false`** (컬럼과 필드만 있고 기능 미구현) |
| 24 | `timezone` | string(40) | 아니오 | 기본 `Asia/Seoul`. 표시용일 뿐 저장 시각에는 영향이 없습니다 |
| 25 | `locale` | string(10) | 아니오 | 기본 `ko-KR` |
| 26 | `marketing_agreed` | bool | 아니오 | 마케팅 수신 동의 |
| 27 | `created_at` | string ISO8601 | 예 | 가입 시각 |
| 28 | `updated_at` | string ISO8601 | 예 | 마지막 수정 시각 |
| 29 | `last_login_at` | string ISO8601 | 예 | 마지막 로그인 시각 |
| 30 | `login_count` | int | 아니오 | 누적 로그인 횟수 |
| 31 | `service_id` | string(64) | 예 | **이 응답의 서비스 컨텍스트.** `service_id` 없이 로그인했거나 관리자 목록 조회면 `null` |
| 32 | `service_joined_at` | string ISO8601 | 예 | 이 서비스에 가입한 시각 |
| 33 | `meta` | object | 아니오 | 앱이 자유롭게 쓰는 확장 필드. 비어도 **`{}`** 이며 `null`이 아닙니다 |

### 6-2. 관리자 뷰 추가 필드 — 12개

`GET /api/admin/users.php`, `GET|PUT /api/admin/user_detail.php` 응답의 `user`에는 위 33개에 더해 아래 12개가 붙습니다.

| 필드 | 타입 | null 가능 | 의미 |
|---|---|---|---|
| `admin_view` | bool | 아니오 | 항상 `true`. 이 객체가 관리자 뷰임을 나타냅니다 |
| `memo` | string | 예 | 관리자 전용 메모. 본인 응답에는 절대 나오지 않습니다 |
| `last_login_ip` | string(45) | 예 | 마지막 로그인 IP |
| `failed_login_count` | int | 아니오 | 연속 로그인 실패 횟수 |
| `locked_until` | string ISO8601 | 예 | 락아웃 해제 시각 |
| `withdrawn_at` | string ISO8601 | 예 | 탈퇴 시각 |
| `withdraw_reason` | string | 예 | 탈퇴 사유 |
| `global_account_level` | string | 아니오 | **오버라이드 적용 전** 전역 계정등급 |
| `global_role` | string | 아니오 | **오버라이드 적용 전** 전역 역할 |
| `global_billing_tier` | string | 아니오 | **오버라이드 적용 전** 전역 결제등급 |
| `session_count` | int | 아니오 | 유효(미회수·미만료) 세션 수 |
| `service_overrides` | array | 아니오 | 아래 6-3. **목록 응답에서는 항상 빈 배열**, 상세 응답에서만 채워집니다 |

### 6-3. `service_overrides[]` 항목 — 10필드

| 필드 | 타입 | null 가능 | 의미 |
|---|---|---|---|
| `service_id` | string | 아니오 | 서비스 키 |
| `service_name` | string | 예 | 서비스 이름. 서비스가 삭제됐으면 `null` |
| `account_level` | string | 예 | 이 서비스에서의 계정등급 오버라이드. **`null`이면 전역값 상속** |
| `role` | string | 예 | 역할 오버라이드. `null`이면 전역값 상속 |
| `billing_tier` | string | 예 | 결제등급 오버라이드. `null`이면 전역값 상속 |
| `billing_expires_at` | string ISO8601 | 예 | 이 서비스의 결제 만료 시각 |
| `points` | int | 아니오 | 서비스 전용 포인트. **v1.0.0 미사용** (항상 0이고 어디서도 차감되지 않습니다) |
| `status` | string | 아니오 | `active` 또는 `blocked` |
| `joined_at` | string ISO8601 | 예 | 가입 시각 |
| `last_used_at` | string ISO8601 | 예 | 마지막 사용 시각 |

### 6-4. `session` 객체 — 11필드

| 필드 | 타입 | null 가능 | 의미 |
|---|---|---|---|
| `session_id` | string(64) | 아니오 | **공개 식별자.** 이 값으로는 인증할 수 없습니다. 기기 목록 표시와 세션 개별 종료에만 씁니다 |
| `auth_type` | string(10) | 아니오 | **v1.0.0에서는 항상 `"token"`입니다.** 이 필드로 분기하지 마세요 |
| `service_id` | string(64) | 예 | 이 세션의 서비스. 로그인 서버 자체 세션이면 `null` |
| `issued_at` | string ISO8601 | 예 | 발급 시각 |
| `expires_at` | string ISO8601 | 예 | access 만료 시각 |
| `refresh_expires_at` | string ISO8601 | 예 | refresh 만료 시각. refresh를 발급하지 않았으면 `null` |
| `last_seen_at` | string ISO8601 | 예 | 마지막 인증 성공 시각 (요청마다 갱신됩니다) |
| `device_label` | string(80) | 예 | 사람이 읽는 기기명. 예: `"Chrome / Windows"` |
| `ip` | string(45) | 아니오 | 발급 시점 IP |
| `user_agent` | string(255) | 아니오 | 발급 시점 User-Agent |
| `is_current` | bool | 아니오 | 지금 요청에 쓰인 세션인가 |

**절대 나오지 않는 값:** `access_token_hash`, `refresh_token_hash`, `csrf_token_hash`, 그리고 토큰 원문. 토큰 원문은 발급 응답의 최상위 `access_token` / `refresh_token`에서만 나옵니다.

### 6-5. `payload` 객체

- 타입: 객체. 비어 있으면 **`{}`** 이며 `null`도 `[]`도 아닙니다.
- 키 이름은 관리자가 등록한 `secret_key` 그대로입니다. 형식은 `^[a-z0-9][a-z0-9_]{1,99}$`.
- 값의 타입은 `value_type`에 따라 다릅니다:
  - `string` → JSON 문자열
  - `url` → JSON 문자열 (http/https 절대 URL)
  - `json` → **파싱된 객체 또는 배열** (문자열이 아닙니다). 파싱에 실패하면 원본 문자열이 들어갑니다
- 실려 오는 응답: `login` / `exchange` / `refresh` / `session` **4개뿐**입니다.

전형적인 예:

```json
{
  "ai_api_key": "sk-proj-xxxxxxxxxxxxxxxx",
  "api_base": "https://api.openai.com/v1",
  "model": "gpt-4o-mini",
  "limits": { "max_tokens": 2048, "temperature": 0.7 }
}
```

(`limits`는 `value_type: "json"`으로 등록한 경우입니다.)

### 6-6. 3축 등급의 값 집합과 랭크 정수

```
account_level : guest(0)  basic(10)  verified(20)  vip(30)  staff(90)
role          : user(0)   manager(10) admin(20)    superadmin(30)
billing_tier  : free(0)   lite(10)   pro(20)       enterprise(30)
```

**`staff`가 90인 것에 주의하세요.** `vip`(30)와 `staff`(90) 사이에 큰 간격이 있습니다. 값 사이에 새 등급을 끼워 넣을 여지를 남긴 것입니다.

### 6-7. 열거값 전체 목록

| 대상 | 허용값 |
|---|---|
| `users.status` | `active` `pending` `suspended` `dormant` `withdrawn` |
| `user_services.status` (= `membership_status`) | `active` `blocked` |
| `services.status` | `active` `disabled` |
| `service_secrets.status` | `active` `disabled` |
| `service_secrets.value_type` | `string` `json` `url` |
| `sessions.auth_type` | `cookie` `token` (v1.0.0에서는 실제로 `token`만 발급됩니다) |
| `sessions.revoke_reason` | `logout` `logout_all` `password_changed` `admin` `expired` `refresh_rotated` `refresh_reuse` `withdraw` (`expired`는 v1.0.0에서 만들어지지 않는 미사용 값입니다) |
| `login_logs.result` (**7종**) | `success` `fail_credential` `fail_locked` `fail_status` `fail_ratelimit` `fail_service_blocked` `logout` |
| `point_logs.reason` | 자유 문자열(100자). 관례값: `signup_bonus` `admin_adjust` `purchase` `refund` `usage` `withdraw_clear`(미사용) |
| `admin_logs.target_type` | `user` `service` `secret` `setting` `session` |
| `terms.code` | `tos` `privacy` `marketing` |
| 설정 `vtype` | `string` `int` `bool` `json` |

`login_logs.result` 값의 의미:

| 값 | 언제 기록되는가 |
|---|---|
| `success` | 로그인 성공. SSO 플로우에서는 `exchange`가 성공할 때 **1건만** 기록됩니다(`authorize`는 성공 로그를 남기지 않습니다) |
| `fail_credential` | 아이디 없음 또는 비밀번호 틀림 |
| `fail_locked` | 락아웃 상태에서 시도 |
| `fail_status` | 점검 모드 / 이메일 미인증 / 계정 정지·탈퇴·휴면·대기 |
| `fail_ratelimit` | 레이트리밋 초과 (v1.0.0 코드 경로에서는 실제로 기록되지 않습니다 — 레이트리밋은 DB 쓰기 전에 예외를 던집니다) |
| `fail_service_blocked` | 전역 계정은 정상이지만 **그 서비스에서만 차단**된 회원의 시도 |
| `logout` | 로그아웃, 세션 개별 종료, 탈퇴 |

### 6-8. DB 테이블 15개 (접두사 `app_ulogin_`)

앱 개발에 직접 필요하지는 않지만, 관리자 API 응답의 구조를 이해할 때 참고가 됩니다.

| 테이블 | 용도 |
|---|---|
| `users` | 회원 계정 |
| `services` | 연동 서비스 등록 정보 |
| `user_services` | (사용자 × 서비스) 가입 매핑 + 등급 오버라이드 |
| `service_secrets` | 서비스별 시크릿 (AES-256-CBC 암호화 저장) |
| `sessions` | 세션 (access/refresh/csrf 토큰의 SHA-256 해시만 저장) |
| `auth_codes` | SSO 1회용 code (SHA-256만 저장) |
| `password_resets` | 비밀번호 재설정 토큰 |
| `email_verifications` | 이메일 인증 토큰 |
| `login_logs` | 로그인 시도 이력 |
| `point_logs` | 포인트 원장 |
| `admin_logs` | 관리자 조작 감사 로그 |
| `rate_limits` | 레이트리밋 카운터 |
| `settings` | 전역 설정 12개 |
| `terms` | 약관 |
| `user_consents` | 약관 동의 이력 |

### 6-9. 시각 규약

- **저장·반환 모두 UTC**입니다. 형식은 `"2026-08-26T04:12:00Z"` (ISO8601, 끝에 `Z`).
- 사용자의 `timezone` 필드는 **표시용 힌트**일 뿐, 응답 시각을 바꾸지 않습니다.
- 앱에서 로컬 시각으로 보여주려면 직접 변환하세요:

```javascript
// UTC ISO8601 → 사용자 로컬 시각 문자열
function toLocal(iso) {
  if (!iso) return '-';
  var d = new Date(iso);          // 'Z' 가 붙어 있으므로 Date 가 UTC 로 정확히 파싱한다
  if (isNaN(d.getTime())) return '-';
  return d.toLocaleString();
}
```

**`Z`를 떼고 파싱하면 브라우저가 로컬 시각으로 해석해 최대 하루까지 어긋납니다.** 문자열을 자르지 마세요.

---

## 7. 권한·등급 판정

### 7-1. 3축 모델

이 시스템은 서로 독립적인 세 축으로 권한을 판정합니다.

| 축 | 무엇을 나타내는가 | 값 |
|---|---|---|
| `account_level` | 계정의 **신뢰 수준**. 이메일 인증 여부, VIP 지정 등 | `guest` `basic` `verified` `vip` `staff` |
| `role` | **관리 권한**. 로그인 서버의 관리자 기능 접근 | `user` `manager` `admin` `superadmin` |
| `billing_tier` | **결제 등급**. 유료 기능 게이팅 | `free` `lite` `pro` `enterprise` |

세 축은 **곱하기가 아니라 각각 독립**입니다. 시크릿 게이트는 세 축을 모두 만족해야 통과합니다(AND).

### 7-2. effective 등급 계산 규칙 (이 순서 그대로)

```
1) 전역값을 읽는다            users.account_level / users.role / users.billing_tier
2) 서비스 오버라이드가 있으면 그것으로 덮는다
                              user_services.account_level / role / billing_tier
                              (해당 컬럼이 NULL 이면 전역값을 그대로 쓴다)
3) 결제 만료를 확인한다
                              billing_expires_at 이 있고 지금보다 과거이면
                              billing_tier 를 'free' 로 강등한다 (DB 값은 바꾸지 않는다)
4) billing_active = (billing_tier !== 'free')
```

만료 시각을 어느 쪽에서 읽는지도 규칙이 있습니다:
- 서비스 오버라이드에 `billing_tier`가 있으면 **그 행의 `billing_expires_at`**을 봅니다.
- 오버라이드가 없으면 전역 `users.billing_expires_at`을 보고, 그것이 `null`이면 서비스 행의 값을 봅니다.

**API가 반환하는 `account_level` / `role` / `billing_tier`와 각 `*_rank`는 전부 이 계산을 마친 effective 값입니다.** 앱은 추가 계산을 할 필요가 없습니다.

### 7-3. 자동 가입 시 등급은 올라가기만 합니다 (grant-only)

서비스의 `default_account_level`은 **"자동 가입 시 최소 보장 등급"**입니다.

- 사용자의 전역 `account_level` 랭크가 `default_account_level`보다 **낮을 때만** 오버라이드로 기록됩니다.
- 같거나 높으면 오버라이드는 `NULL`(전역 상속)로 남습니다.
- `role`과 `billing_tier` 오버라이드는 자동 가입에서 **항상 `NULL`**입니다.

이 규칙이 없으면, 전역 `verified` 사용자가 `default_account_level='basic'`인 서비스에 가입할 때 `basic`으로 **강등**되어 `min_account_level='verified'` 시크릿이 조용히 사라집니다.

### 7-4. 클라이언트에서 등급으로 UI를 가리는 올바른 방법

**금지 + 이유 + 대안**

- **금지:** `if (user.billing_tier > 'lite')` 처럼 **문자열을 비교**하는 것.
- **이유:** 문자열 비교는 사전순입니다. `'vip' > 'staff'`가 `true`가 되고, `'free' > 'enterprise'`도 `true`입니다. 등급 게이팅이 완전히 뒤집힙니다.
- **대안:** 서버가 함께 주는 `*_rank` 정수로 비교하세요.

```javascript
// 등급 랭크 상수 — 서버와 같은 값이다
var ACCOUNT_LEVEL_RANK = { guest: 0, basic: 10, verified: 20, vip: 30, staff: 90 };
var ROLE_RANK          = { user: 0, manager: 10, admin: 20, superadmin: 30 };
var BILLING_TIER_RANK  = { free: 0, lite: 10, pro: 20, enterprise: 30 };

// 서버가 주는 *_rank 를 그대로 쓰는 것이 가장 안전하다.
// 위 상수는 서버가 rank 를 주지 않는 값(예: 시크릿 게이트 문자열)을 비교할 때 쓴다.
function tierGte(userTierRank, minTierName) {
  return userTierRank >= (BILLING_TIER_RANK[minTierName] || 0);
}

// 사용 예
if (tierGte(user.billing_tier_rank, 'lite')) {
  showProFeatures();
} else {
  showUpgradeNotice();
}

// 관리자 메뉴 노출
if (user.role_rank >= ROLE_RANK.manager) {
  showAdminLink();
}
```

> **화면을 가리는 것은 편의일 뿐 방어가 아닙니다.** 실제 방어는 서버가 합니다. 등급이 모자란 사용자는 애초에 `payload`에 키를 받지 못하고, 관리자 API는 403을 돌려줍니다.

### 7-5. 관리자 권한은 전역 `role`만 봅니다

- 관리자 API(`/server/api/admin/*.php`)의 권한 판정은 **`users.role`(전역)** 만 사용합니다.
- 어떤 서비스에서 `role: "admin"` 오버라이드를 받았다고 해서 로그인 서버의 관리자가 되지 **않습니다.** 그 승격 경로 자체가 권한 상승이기 때문입니다.
- 반대로 전역 `admin`인 사용자가 어떤 서비스에서 `role: "user"` 오버라이드를 받아도 **관리자 API는 그대로 쓸 수 있습니다.** 판정에 오버라이드가 개입하지 않기 때문입니다.
- 그러나 `user` 객체의 `role` / `role_rank`는 **effective 값**(오버라이드 반영)입니다. 관리자 뷰에서는 `global_role` 필드로 전역값을 따로 확인할 수 있습니다.

즉 관리자 화면 노출 조건을 `user.role_rank`로 판단하면 서비스 컨텍스트에 따라 어긋날 수 있습니다. 확실히 하려면 **`service_id` 없이 로그인**하거나(그러면 오버라이드가 없어 effective = 전역), 관리자 API를 호출해 403 여부로 확인하세요.

### 7-6. 관리자 역할별로 할 수 있는 일

| 조작 | 최소 역할 |
|---|---|
| 관리자 콘솔 진입 · 대시보드 통계 | `manager` |
| 회원 목록 조회 · 회원 상세 조회 | `manager` |
| 로그인 로그 조회 | `manager` |
| 회원 수정 · 포인트 조정 · 정지 · 복구 · 비밀번호 초기화 · 세션 강제 종료 | `admin` |
| 서비스 CRUD · 시크릿 CRUD · 전역 설정 변경 · 관리자 조작 로그 조회 | `admin` |
| 회원 하드 삭제 · **시크릿 평문 조회(reveal)** · 탈퇴 계정 복구 | `superadmin` |

**대상 계정 방어(모든 변경 조작에 공통):** 자신과 같거나 높은 `role_rank`를 가진 **다른** 계정은 어떤 변경도 할 수 없습니다. `admin`이 다른 `admin`의 이메일을 바꿔 비밀번호 재설정으로 계정을 탈취하는 경로를 막기 위한 것입니다.

---

## 8. 시크릿(payload) 사용법 — 이 시스템의 존재 이유

### 8-1. 언제 오는가

| 엔드포인트 | `payload` 포함 |
|---|---|
| `POST /server/api/auth/login.php` | **예** (요청에 `service_id`를 줬을 때) |
| `POST /server/api/auth/exchange.php` | **예** |
| `POST /server/api/auth/refresh.php` | **예** (세션에 `service_id`가 있을 때) |
| `GET /server/api/auth/session.php` | **예** (세션에 `service_id`가 있을 때) |
| `GET /server/api/me/index.php` | **아니오** |
| 그 밖의 모든 엔드포인트 | **아니오** |

`service_id` 컨텍스트가 없는 세션(로그인 시 `service_id`를 주지 않음)은 위 4곳에서도 `payload`가 `{}`입니다.

### 8-2. 어떤 키가 실리는가 — 판정 순서

```
1) service_id 가 없다               → {}  (빈 객체)
2) 그 서비스의 활성 가입 행이 없다   → {}  (미가입 또는 blocked)
3) 그 서비스의 status='active' 시크릿을 sort_order, id 순으로 훑는다
4) 각 시크릿마다:
     min_account_level 이 있고 사용자의 effective account_level 랭크가 미달  → 이 키를 건너뛴다
     min_billing_tier  이 있고 사용자의 effective billing_tier  랭크가 미달  → 이 키를 건너뛴다
     min_role          이 있고 사용자의 effective role          랭크가 미달  → 이 키를 건너뛴다
     복호화에 실패하면(암호화 키 교체 등)                                      → 이 키를 건너뛴다
5) 남은 키만 담아서 반환한다
```

### 8-3. 미가입이면 payload가 빈 객체입니다 (무증상 실패 1순위)

**`auto_join = 0`인 서비스에서 아직 가입하지 않은 사용자는 로그인에 성공해도 `payload`가 `{}`입니다.**

- 로그인 자체는 **거부되지 않습니다.** 가입 심사 중인 사용자도 로그인은 되어야 하기 때문입니다.
- 그래서 증상이 "에러 없이 키만 안 옴"입니다. 이걸 모르면 원인을 찾기 어렵습니다.
- `auto_join = 1`이면 첫 로그인 시 가입 행이 자동 생성되므로 이 문제가 생기지 않습니다.

**확인 방법:** `GET /server/api/me/services.php`를 호출해 그 `service_id`가 목록에 있는지 보세요.
- 목록에 **없으면** 미가입입니다 → 관리자 콘솔 [서비스] 탭에서 `auto_join`을 켜거나, [회원] 탭 → 대상 회원 → `service_overrides`에 그 서비스를 추가하세요.
- 목록에 있는데 `membership_status`가 `blocked`면 차단된 것입니다 → 로그인 자체가 403 `FORBIDDEN`("이 서비스 이용이 제한되었습니다")로 거부됩니다.

### 8-4. 등급이 모자라면 키가 조용히 빠집니다

**에러가 나지 않습니다.** 그 키가 응답에서 그냥 없어집니다.

이렇게 만든 이유: 에러를 내면 "이 서비스에는 `ai_api_key`라는 키가 존재한다"는 사실을 알려주는 오라클이 되기 때문입니다. 존재 여부 자체가 정보입니다.

**그래서 앱은 반드시 부재 분기를 처리해야 합니다:**

```javascript
// 금지: 키가 항상 있다고 가정
// var key = payload.ai_api_key;
// callOpenAI(key);                 // key 가 undefined 면 401 이 나고 원인을 알 수 없다

// 올바른 처리
function useSecrets(payload) {
  var key = payload && payload.ai_api_key;
  if (!key) {
    // 사용자에게 왜 안 되는지 알려준다. 세 가지 원인이 있다.
    showNotice(
      'AI 기능을 사용할 수 없습니다.\n' +
      '· 결제 등급이 부족할 수 있습니다 (관리자에게 문의)\n' +
      '· 이 서비스에 가입되어 있지 않을 수 있습니다\n' +
      '· 관리자가 아직 키를 등록하지 않았을 수 있습니다'
    );
    disableAiFeatures();
    return;
  }
  var base  = (payload && payload.api_base) || 'https://api.openai.com/v1';
  var model = (payload && payload.model)    || 'gpt-4o-mini';
  enableAiFeatures(key, base, model);
}
```

### 8-5. 게이트 없는 시크릿은 전원에게 공개됩니다

`min_account_level` / `min_billing_tier` / `min_role`이 **모두 비어 있으면** 등급 검사를 건너뜁니다. 즉 **그 서비스에 가입한 모든 사용자**가 그 값을 받습니다.

- `allow_register`가 켜져 있고 `auto_join`도 켜져 있으면 → 아무나 가입만 하면 그 키를 받습니다.
- 랭크 0 값(`free` / `guest` / `user`)을 걸어도 마찬가지입니다. 랭크 0은 전원 통과이므로 게이트로 인정되지 않습니다.

서버가 이것을 막습니다: 민감한 이름(`*_api_key`, `*_secret`, `*_token`, `*_password`, `*_passwd`, `*_credential(s)`)의 시크릿을 게이트 없이 만들거나 수정하려 하면 400 `VALIDATION_FAILED`로 거부됩니다. 관리자 콘솔에는 이 검사를 우회하는 컨트롤이 아예 없습니다 — 의도한 공개라면 API로 `allow_public: true`를 보내야 합니다.

`api_base` / `model` 같은 **비민감 설정값은 게이트 없이 등록하는 것이 정상**입니다.

### 8-6. payload를 저장하지 마세요

**금지 + 이유 + 대안**

- **금지:** `localStorage.setItem('payload', JSON.stringify(payload))` — `sessionStorage`, IndexedDB, 쿠키, 파일 저장도 전부 금지입니다.
- **이유:**
  1. `localStorage`는 **만료가 없습니다.** 사용자의 등급이 내려가거나 관리자가 키를 폐기해도, 앱은 옛 키를 계속 씁니다.
  2. 같은 origin에서 실행되는 **모든 스크립트가 읽을 수 있습니다.** XSS 하나로 API 키가 통째로 유출됩니다. 확장 프로그램도 접근할 수 있습니다.
  3. 브라우저 개발자도구에서 **평문으로 그대로 보입니다.** 사용자 PC를 잠깐 쓴 사람도 꺼낼 수 있습니다.
  4. 이 서버를 만든 이유가 "키를 클라이언트에 두지 않는 것"인데, 저장하면 **하드코딩과 같은 상태**가 됩니다.
- **대안:** JS **변수(메모리)** 에만 두고, 페이지를 새로 열 때마다 `GET /server/api/auth/session.php`로 다시 받으세요. 그 호출은 `access_token`만 있으면 되고 수십 밀리초면 끝납니다.

```javascript
// 올바른 패턴 — 모듈 스코프 변수. 새로고침하면 사라진다.
var _payload = null;

function loadSecrets(accessToken, serverRoot) {
  return fetch(serverRoot + '/server/api/auth/session.php', {
    headers: { 'Authorization': 'Bearer ' + accessToken },
    cache: 'no-store'
  }).then(function (r) { return r.json(); }).then(function (json) {
    if (!json.ok) throw new Error(json.error.code);
    _payload = json.data.payload || {};      // 메모리에만 둔다
    return _payload;
  });
}

// 저장은 access_token / refresh_token 까지만 한다.
// 이 둘은 만료가 있고, 서버가 언제든 회수할 수 있으며, 그 자체로 AI 예산을 태우지 않는다.
```

**`access_token`과 `refresh_token`은 `localStorage`에 두어도 됩니다.** 만료가 있고, 서버가 회수할 수 있고, 유출돼도 그 계정의 포인트 한도까지만 피해가 나기 때문입니다. `payload`는 그렇지 않습니다 — AI API 키는 **당신 조직의 결제 계정에 직접 청구**됩니다.

### 8-7. 키 교체가 일어나면

관리자가 시크릿 값을 바꾸면, 다음 `refresh` 또는 `session` 호출부터 새 값이 내려옵니다. 기존에 메모리에 들고 있던 값은 폐기된 키일 수 있습니다.

**그래서 `refresh` 응답의 `payload`를 반드시 갈아 끼우세요.** 옛 값을 유지하면 폐기된 키로 계속 호출해 401을 맞습니다.

```javascript
// refresh 후 payload 를 통째로 교체한다. 병합(merge)하지 않는다 —
// 등급이 내려가 키가 빠졌는데 병합하면 없어져야 할 키가 남는다.
_payload = data.payload || {};
```

---

## 9. 포인트

### 9-1. 개념

- 포인트는 **전역 1개**입니다(`users.points`). 서비스별 포인트 컬럼이 있지만 v1.0.0에서는 쓰지 않습니다.
- 가입 시 보너스가 지급됩니다(기본 100, 관리자 설정 `signup_bonus_points`).
- 앱은 **차감만** 할 수 있습니다. 적립은 관리자 API로만 가능합니다.
- 모든 증감이 `point_logs`에 원장으로 남고, 각 행에 `balance_after`(처리 후 잔액)가 기록됩니다.

### 9-2. 잔액 조회

세 가지 경로가 있습니다. 상황에 맞게 고르세요.

| 경로 | 언제 |
|---|---|
| `user.points` (로그인/session 응답 안에) | 이미 받은 데이터에 들어 있음. 추가 호출 불필요 |
| `GET /server/api/me/points.php` | 이력까지 함께 필요할 때. 잔액을 **지금 다시 읽어** 반환합니다 |
| `POST /server/api/points/consume.php` 응답의 `points` | 차감 직후의 최신 잔액 |

### 9-3. 차감

```javascript
// AI 호출 1회당 10포인트 차감
function consumePoints(serverRoot, accessToken, serviceId, amount, refId) {
  return fetch(serverRoot + '/server/api/points/consume.php', {
    method: 'POST',
    headers: {
      'Authorization': 'Bearer ' + accessToken,
      'Content-Type': 'application/json'
    },
    cache: 'no-store',
    body: JSON.stringify({
      amount: amount,                       // 양의 정수만. 서버가 음수로 바꿔 기록한다
      reason: 'usage',
      service_id: serviceId,
      ref_type: 'chat',
      ref_id: refId,
      idempotency_key: makeUuid()           // 아래 9-4 참고
    })
  }).then(function (r) { return r.json(); }).then(function (json) {
    if (!json.ok) {
      var e = new Error(json.error.message);
      e.code = json.error.code;
      e.data = json.data;                   // POINT_INSUFFICIENT 면 {points, required, shortfall}
      throw e;
    }
    return json.data;                       // {points, delta, balance_after, total_points_spent, log_id, idempotent_replay, created_at}
  });
}

// RFC 4122 v4 UUID 생성 (crypto.randomUUID 가 없는 환경까지 커버)
function makeUuid() {
  if (window.crypto && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }
  var b = new Uint8Array(16);
  (window.crypto || window.msCrypto).getRandomValues(b);
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  var h = [];
  for (var i = 0; i < 16; i++) h.push(('0' + b[i].toString(16)).slice(-2));
  return h.slice(0, 4).join('') + '-' + h.slice(4, 6).join('') + '-' +
         h.slice(6, 8).join('') + '-' + h.slice(8, 10).join('') + '-' + h.slice(10, 16).join('');
}
```

### 9-4. 멱등키(`idempotency_key`) 사용법

**왜 필요한가:** 네트워크가 불안정하면 요청이 서버에 도달했는데 응답만 못 받는 경우가 있습니다. 그때 재시도하면 **두 번 차감**됩니다. 애플리케이션 로직만으로는 막을 수 없습니다.

**규칙:**
- **작업 1건당 키 1개**입니다. 재시도할 때는 **같은 키**를 다시 보내야 의미가 있습니다.
- 매번 새로 만들면 멱등성이 사라집니다. 즉 아래처럼 쓰세요:

```javascript
// 올바른 패턴: 작업 시작 시 키를 한 번 만들고, 재시도에서 재사용한다
var jobKey = makeUuid();

function chargeWithRetry(serverRoot, token, serviceId, amount, attempt) {
  attempt = attempt || 0;
  return fetch(serverRoot + '/server/api/points/consume.php', {
    method: 'POST',
    headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json' },
    cache: 'no-store',
    body: JSON.stringify({ amount: amount, reason: 'usage', service_id: serviceId, idempotency_key: jobKey })
  }).then(function (r) { return r.json(); }).then(function (json) {
    if (json.ok) return json.data;
    var e = new Error(json.error.message);
    e.code = json.error.code;
    e.data = json.data;
    throw e;
  }).catch(function (err) {
    // 네트워크 실패만 재시도한다. 서버가 코드로 답한 실패는 재시도해도 같다.
    if (!err.code && attempt < 2) {
      return chargeWithRetry(serverRoot, token, serviceId, amount, attempt + 1);
    }
    throw err;
  });
}
```

같은 키로 두 번째 요청이 들어오면 응답에 `"idempotent_replay": true`가 오고 잔액은 그대로입니다.

### 9-5. 잔액 부족 처리

409 응답의 `data`에 정확한 수치가 들어 있습니다.

```javascript
consumePoints(SERVER, token, SERVICE_ID, 10, 'msg_7')
  .then(function (d) {
    updateBalanceUi(d.points);              // 최신 잔액으로 UI 갱신
    return callAiApi();
  })
  .catch(function (e) {
    if (e.code === 'POINT_INSUFFICIENT') {
      // e.data = { points: 5, required: 10, shortfall: 5 }
      showNotice(
        '포인트가 부족합니다. 현재 ' + e.data.points + 'P, ' +
        '필요 ' + e.data.required + 'P (부족 ' + e.data.shortfall + 'P)'
      );
      updateBalanceUi(e.data.points);
      return;
    }
    throw e;
  });
```

### 9-6. 차감 시점 — 호출 전인가 후인가

**AI 호출 전에 차감하세요.** 이유:

- 호출 후에 차감하면, 잔액이 0인 사용자가 AI API를 먼저 쓰고 나서 차감에 실패합니다. 즉 **무료로 호출**하게 됩니다.
- 반대로 AI 호출이 실패했을 때 포인트를 돌려주려면 관리자 API가 필요한데, 앱은 그 권한이 없습니다.
- 그러므로 실무적으로는 "차감 → 호출 → 실패 시 사용자에게 안내"가 맞고, 환불이 꼭 필요하면 관리자가 `user_points.php`로 수동 조정합니다.

---

## 10. 에러 처리 전표

### 10-1. 에러 응답 형태 (다시 한번)

```json
{
  "ok": false,
  "data": null,
  "error": { "code": "AUTH_SESSION_EXPIRED", "message": "세션이 만료되었습니다. 다시 로그인해 주세요.", "field": null }
}
```

- `error.code`로 분기하세요. `error.message`는 사람에게 보여주는 문구이고 바뀔 수 있습니다.
- `error.field`는 입력값 오류일 때 어느 필드인지 알려줍니다. 없으면 `null`입니다(키는 항상 존재합니다).
- 부가정보가 있으면 `data`가 객체로 옵니다.

### 10-2. 서버 에러 코드 전체 — 31개

| 코드 | HTTP | 의미 | 클라이언트가 할 일 | 사용자에게 보여줄 문구 |
|---|---|---|---|---|
| `VALIDATION_FAILED` | 400 | 입력값 형식·범위 오류 | `error.field`가 가리키는 입력란에 `error.message`를 표시. 재시도 가능 | 입력값을 확인해 주세요. |
| `AUTH_INVALID_CREDENTIALS` | 401 | 아이디 없음 또는 비밀번호 틀림 (**구분되지 않음**) | 로그인 폼에 표시. 남은 시도 횟수를 세지 마세요(서버가 관리) | 아이디 또는 비밀번호가 올바르지 않습니다. |
| `AUTH_REQUIRED` | 401 | 토큰이 없거나 형식이 잘못됨 | 로컬 토큰 삭제 후 로그인 화면으로 | 로그인이 필요합니다. |
| `AUTH_SESSION_EXPIRED` | 401 | 세션 만료·회수됨, refresh 무효·재사용 탐지, 비밀번호 변경 후 | `refresh_token`이 있으면 갱신 1회 시도. 그것도 실패하면 전체 삭제 후 재로그인 | 세션이 만료되었습니다. 다시 로그인해 주세요. |
| `AUTH_ACCOUNT_LOCKED` | 423 | 연속 로그인 실패로 잠김. `data.locked_until`(ISO8601), `data.retry_after`(초) | `retry_after`만큼 로그인 버튼 비활성화 후 안내 | 로그인 시도 횟수를 초과했습니다. N분 후 다시 시도해 주세요. |
| `AUTH_ACCOUNT_SUSPENDED` | 403 | 계정 정지 | 재시도 불가. 문의 안내 표시 | 정지된 계정입니다. 관리자에게 문의해 주세요. |
| `AUTH_ACCOUNT_WITHDRAWN` | 403 | 탈퇴한 계정 | 재시도 불가. 재가입 안내 | 탈퇴한 계정입니다. |
| `AUTH_ACCOUNT_DORMANT` | 403 | 휴면 계정 | 재시도 불가. 문의 안내 (자동 해제 기능이 없습니다) | 휴면 상태인 계정입니다. 관리자에게 문의해 주세요. |
| `AUTH_ACCOUNT_PENDING` | 403 | 가입 처리 미완료 | 이메일 인증 안내 + 재발송 링크 노출 | 가입 처리가 완료되지 않은 계정입니다. |
| `AUTH_EMAIL_UNVERIFIED` | 403 | 이메일 미인증 (`require_email_verify=1`). `data.email` 포함 | `POST /server/api/auth/email_resend.php` 버튼 노출 | 이메일 인증이 필요합니다. 받은 메일의 링크를 확인해 주세요. |
| `AUTH_PASSWORD_MISMATCH` | 400 | 현재 비밀번호가 틀림 (비밀번호·이메일 변경, 탈퇴 시) | 해당 입력란에 표시. 재시도 가능 | 현재 비밀번호가 올바르지 않습니다. |
| `FORBIDDEN` | 403 | 권한 부족, 관리자 대상 방어 위반, **서비스 차단 회원** | `error.message`를 그대로 표시. 재시도해도 같습니다 | 권한이 없습니다. / 이 서비스 이용이 제한되었습니다. |
| `TIER_INSUFFICIENT` | 403 | 결제등급 부족. `data.required`에 필요 등급 | 업그레이드 안내. **v1.0.0 기본 구성에서는 발생하지 않습니다** (서비스 연동 측이 등급 게이팅을 붙였을 때만) | 이용 등급이 부족합니다. |
| `NOT_FOUND` | 404 | 대상 없음 (남의 리소스도 이 코드로 통일) | 목록을 다시 불러오세요 | 대상을 찾을 수 없습니다. |
| `DUPLICATE_USERNAME` | 409 | 아이디 중복 | 아이디 입력란에 표시. 다른 값으로 재시도 | 이미 사용 중인 아이디입니다. |
| `DUPLICATE_EMAIL` | 409 | 이메일 중복 | 이메일 입력란에 표시 | 이미 사용 중인 이메일입니다. |
| `DUPLICATE_SERVICE_ID` | 409 | `service_id` 중복 (관리자) | 다른 `service_id`로 재시도 | 이미 사용 중인 서비스 ID 입니다. |
| `DUPLICATE_SECRET_KEY` | 409 | 같은 서비스에 같은 `secret_key` (관리자) | 기존 항목을 수정하세요 | 이미 등록된 시크릿 키입니다. |
| `POINT_INSUFFICIENT` | 409 | 포인트 부족. `data.points` / `data.required` / `data.shortfall` | 잔액 UI 갱신 + 충전 안내. AI 호출을 진행하지 마세요 | 포인트가 부족합니다. (현재 NP / 필요 NP) |
| `CONFLICT` | 409 | 현재 상태에서 불가능한 전이 (이미 정지됨, 이미 활성, 가입자 있는 서비스 삭제 등) | `error.message` 표시. 상태를 다시 조회하세요 | 현재 상태에서는 처리할 수 없습니다. |
| `SSO_INVALID_SERVICE` | 400 | `service_id`가 없거나 `disabled` | **설정 오류입니다.** 관리자에게 `service_id` 등록을 요청 | 알 수 없는 서비스입니다. |
| `SSO_INVALID_REDIRECT` | 400 | `redirect_uri`가 화이트리스트에 없음 | **설정 오류입니다.** 사용한 `redirect_uri` 문자열을 그대로 관리자에게 전달해 등록 요청 | 허용되지 않은 복귀 주소입니다. 서비스 관리자에게 문의해 주세요. |
| `SSO_CODE_INVALID` | 400 | code 없음·만료(60초)·재사용·`service_id` 불일치·`redirect_uri` 불일치·PKCE 불일치 (**6원인 통합**) | 재시도하지 말고 **로그인을 처음부터** 다시 시작 | 인증 코드가 유효하지 않습니다. 다시 로그인해 주세요. |
| `SSO_CLIENT_AUTH_FAILED` | 401 | `is_confidential` 서비스인데 `service_secret`이 틀림 | **설정 오류입니다.** 브라우저 앱이면 서비스의 `is_confidential`을 꺼야 합니다 | 서비스 인증에 실패했습니다. |
| `TOKEN_INVALID` | 400 | 재설정·인증 토큰이 없음·만료·이미 사용됨 (**통합**) | 링크를 다시 요청하게 안내 | 링크가 만료되었거나 이미 사용되었습니다. 다시 요청해 주세요. |
| `CSRF_FAILED` | 403 | CSRF 토큰 불일치 (쿠키 인증에서만 발생) | `GET /server/api/auth/csrf.php`로 새 토큰을 받고 재시도. **Bearer를 쓰면 애초에 발생하지 않습니다** | 요청이 유효하지 않습니다. 새로고침 후 다시 시도해 주세요. |
| `RATE_LIMITED` | 429 | 레이트리밋 초과. `data.retry_after`(초) + `Retry-After` 응답 헤더 | `retry_after`만큼 기다린 뒤 재시도. **즉시 재시도 루프를 만들지 마세요** | 요청이 너무 많습니다. N초 후 다시 시도해 주세요. |
| `REGISTRATION_DISABLED` | 403 | 전역 또는 서비스 단위로 가입이 꺼져 있음 | 가입 폼을 숨기고 안내 표시 | 현재 신규 가입을 받지 않습니다. |
| `MAINTENANCE_MODE` | 503 | 점검 모드 (`manager` 이상은 통과) | 점검 안내 화면. 잠시 후 재시도 | 서비스 점검 중입니다. 잠시 후 다시 시도해 주세요. |
| `METHOD_NOT_ALLOWED` | 405 | HTTP 메서드가 틀림. `data.allowed`에 허용 목록 + `Allow` 응답 헤더 | **코드 버그입니다.** 요약표에서 올바른 메서드를 확인하세요 | 허용되지 않은 요청 방식입니다. |
| `SERVER_ERROR` | 500 | 서버 내부 오류. 설치 전(`config.php` 없음)에도 이 코드 | 잠시 후 재시도. 반복되면 서버 로그 확인 필요 | 일시적인 오류가 발생했습니다. |

### 10-3. `ulogin-client.js`가 추가로 던지는 클라이언트 전용 코드 — 5개

이 코드들은 **서버가 보내지 않습니다.** `ulogin-client.js`를 쓸 때만 나타납니다.

| 코드 | 언제 | 처리 |
|---|---|---|
| `NETWORK_ERROR` | `fetch` 자체가 실패 (오프라인·CORS 차단·서버 다운·DNS) | 네트워크 확인 안내. CORS라면 `allowed_origins` 등록 문제입니다 |
| `CONFIG_ERROR` | 생성자에 `server` / `serviceId` / `redirectUri` 중 하나가 빠짐 | **코드 버그입니다.** 세 옵션을 모두 넘기세요 |
| `CALLBACK_NO_CODE` | 콜백 URL에 `code` 파라미터가 없음, 또는 재교환할 코드가 메모리에 없음 | 로그인을 처음부터 다시 시작 |
| `STATE_MISMATCH` | 저장한 `state`와 돌아온 `state`가 다름 | **교환하지 말고** 로그인을 처음부터 다시 시작. 공격일 수 있습니다 |
| `NOT_LOGGED_IN` | 인증이 필요한 메서드를 토큰 없이 호출 | 로그인 화면으로 보내세요 |

### 10-4. 상태 코드별 일반 대응

| HTTP | 일반 대응 |
|---|---|
| 400 | 요청이 잘못됨. `error.field`를 보고 고칩니다. 그대로 재시도하면 같은 결과입니다 |
| 401 | 인증 실패. `refresh` 1회 → 실패하면 재로그인 |
| 403 | 권한·상태 문제. 재시도해도 같습니다. 사용자에게 이유를 알려주세요 |
| 404 | 대상 없음. 목록을 다시 불러오세요 |
| 405 | 메서드 오류. 코드 버그입니다 |
| 409 | 상태 충돌·중복. 사용자 입력을 바꾸거나 상태를 다시 조회하세요 |
| 423 | 계정 잠김. `data.retry_after`만큼 대기 |
| 429 | 레이트리밋. `data.retry_after`만큼 대기 |
| 500 | 서버 오류. 지수 백오프로 재시도(최대 2~3회) |
| 503 | 점검 중. 사용자에게 안내하고 자동 재시도는 하지 마세요 |

### 10-5. 401을 만났을 때의 표준 처리 흐름

```javascript
// 모든 인증 API 호출을 이 함수로 감싼다.
// 401 을 만나면 refresh 를 딱 한 번 시도하고, 그것도 실패하면 로그아웃 처리한다.
var _refreshInFlight = null;    // 동시 갱신을 하나로 합친다 (refresh 재사용 탐지 방지)

function authedFetch(serverRoot, path, options, tokens, onExpire) {
  options = options || {};
  var headers = options.headers || {};
  headers['Authorization'] = 'Bearer ' + tokens.accessToken;
  if (options.body) headers['Content-Type'] = 'application/json';

  return fetch(serverRoot + path, {
    method: options.method || 'GET',
    headers: headers,
    body: options.body ? JSON.stringify(options.body) : null,
    cache: 'no-store',
    credentials: 'omit'
  }).then(function (r) {
    return r.json().then(function (json) { return { status: r.status, json: json }; });
  }).then(function (res) {
    if (res.json.ok) return res.json.data;

    var code = res.json.error.code;
    if (res.status === 401 && !options._retried && tokens.refreshToken) {
      if (!_refreshInFlight) {
        _refreshInFlight = fetch(serverRoot + '/server/api/auth/refresh.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          cache: 'no-store',
          body: JSON.stringify({ refresh_token: tokens.refreshToken })
        }).then(function (r) { return r.json(); }).then(function (j) {
          _refreshInFlight = null;
          if (!j.ok) throw new Error(j.error.code);
          tokens.accessToken  = j.data.access_token;
          tokens.refreshToken = j.data.refresh_token;
          return j.data;
        }).catch(function (e) {
          _refreshInFlight = null;
          throw e;
        });
      }
      return _refreshInFlight.then(function () {
        options._retried = true;
        return authedFetch(serverRoot, path, options, tokens, onExpire);
      }).catch(function () {
        if (typeof onExpire === 'function') onExpire();
        throw makeApiError(res.json, res.status);
      });
    }
    throw makeApiError(res.json, res.status);
  });
}

function makeApiError(json, status) {
  var e = new Error((json && json.error && json.error.message) || '요청에 실패했습니다.');
  e.code   = (json && json.error && json.error.code) || 'SERVER_ERROR';
  e.status = status;
  e.field  = (json && json.error && json.error.field) || null;
  e.data   = (json && json.data) || null;
  return e;
}
```

---

## 11. 완전한 클라이언트 코드

### 11-1. 방법 A — `ulogin-client.js`를 쓰는 최소 버전

서버가 제공하는 클라이언트 라이브러리를 쓰면 state 생성·PKCE·토큰 저장·자동 갱신·재시도가 전부 처리됩니다. **의존성이 없는 순수 JS 파일 하나**입니다.

로드 경로: `<<설치루트>>/public/assets/ulogin-client.js?v={버전}`  (`?v=` 는 캐시 무효화용입니다 — 14-11절. `{버전}` 에 넣을 값은 `<<설치루트>>/public/version.txt` 첫 줄의 `_` 앞부분입니다)

**초기화는 4줄입니다:**

```javascript
var ulogin = new UloginClient({
  server:      '<<설치루트>>',                       // 예: 'https://example.com/ulogin'
  serviceId:   '<<서비스ID>>',                       // 예: 'local-ai-app'
  redirectUri: location.origin + '/callback.html'    // 화이트리스트 등록값과 완전 일치
});
```

**생성자 옵션 전체**

| 옵션 | 기본값 | 설명 |
|---|---|---|
| `server` | (필수) | **설치 루트.** 끝 슬래시 없이. 내부에서 `server + '/server'`(API)와 `server + '/public/login.html'`(로그인 화면)을 파생합니다 |
| `serviceId` | (필수) | 등록된 `service_id` |
| `redirectUri` | (필수) | 콜백 페이지 절대 URL |
| `apiBase` | `server + '/server'` | 변형 배치에서 API 루트를 직접 지정 |
| `loginUrl` | `server + '/public/login.html'` | 변형 배치에서 로그인 화면 URL을 직접 지정 |
| `apiExt` | `'.php'` | `mod_rewrite`가 살아 있는 환경에서 `''`로 바꿀 수 있습니다 |
| `refreshSkew` | `60` | 만료 몇 초 전에 미리 갱신할지 |
| `deviceLabel` | User-Agent에서 자동 추출 | 기기 이름 |
| `storage` | `'localStorage'` | `'localStorage'` / `'sessionStorage'` / `'memory'` |
| `storagePrefix` | `'ulogin_' + serviceId + '_'` | 저장 키 접두사 |

**공개 메서드 전체**

| 메서드 | 반환 | 설명 |
|---|---|---|
| `login(opts)` | Promise | 로그인 화면으로 이동. `opts.returnTo`로 복귀할 앱 내부 경로 지정 |
| `handleCallback(opts)` | Promise<data> | 콜백 페이지에서 **1회** 호출. state 대조 → exchange → URL 정리 → 복귀. `opts.redirect: false`면 복귀하지 않고 데이터만 반환 |
| `retryCallback(opts)` | Promise<data> | `handleCallback`이 **네트워크 오류**로 실패했을 때만 재시도 |
| `canRetryCallback()` | bool | 재교환 가능한 code가 메모리에 남아 있는가 |
| `getSession()` | Promise<data> | `GET /api/auth/session.php` 호출. `{user, session, expires_in, payload}` |
| `getPayload()` | object\|null | 메모리에 있는 payload |
| `getUser()` | object\|null | 메모리에 있는 user |
| `getSessionInfo()` | object\|null | 메모리에 있는 session |
| `getAccessToken()` | string\|null | 저장된 access_token |
| `getExpiresAt()` | number | 만료 시각(epoch ms). 없으면 0 |
| `getSessionId()` | string\|null | 저장된 session_id |
| `consumePoints(amount, opts)` | Promise<data> | 포인트 차감. `opts`: `reason` `serviceId` `refType` `refId` `memo` `idempotencyKey`(생략하면 UUID 자동 생성) |
| `logout(opts)` | Promise<data> | 로그아웃. `opts.allDevices: true`면 전체 기기 |
| `isLoggedIn()` | bool | 유효한 토큰(또는 갱신 가능한 상태)을 갖고 있는가 |
| `onExpire(cb)` | function | 세션 만료 콜백 등록. 반환값을 호출하면 해제됩니다 |
| `destroy()` | void | 타이머·핸들러 정리(SPA 언마운트용). 토큰은 지우지 않습니다 |

`UloginClient.Error`는 예외 클래스이고, 던져지는 객체에 `code` / `message` / `status` / `data` / `field`가 있습니다.
`UloginClient.version`은 `'1.0.0'`입니다.

**`payload`는 메모리에만 보관됩니다.** `ulogin-client.js`는 `payload` / `user` / `session`을 저장소에 절대 쓰지 않습니다. 저장하는 것은 `access_token` / `refresh_token` / `expires_at` / `session_id` 네 개뿐입니다.

### 11-2. 방법 B — 라이브러리 없이 직접 구현한 완전한 단일 파일 앱

아래 두 파일을 그대로 저장하고 `<<설치루트>>` / `<<서비스ID>>`만 바꾸면 동작합니다. **외부 의존성이 하나도 없습니다.**

#### `app.html`

```html
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ulogin 연동 AI 앱</title>
<style>
  body { font-family: system-ui, -apple-system, "Malgun Gothic", sans-serif; max-width: 760px; margin: 40px auto; padding: 0 16px; line-height: 1.6; }
  .card { border: 1px solid #ddd; border-radius: 8px; padding: 16px; margin: 16px 0; }
  .row { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
  .muted { color: #666; font-size: 0.9em; }
  .err { color: #b00020; }
  button { padding: 8px 16px; border-radius: 6px; border: 1px solid #888; background: #fff; cursor: pointer; }
  button:disabled { opacity: 0.5; cursor: not-allowed; }
  textarea { width: 100%; min-height: 90px; padding: 8px; box-sizing: border-box; }
  pre { background: #f6f6f6; padding: 12px; border-radius: 6px; white-space: pre-wrap; word-break: break-word; }
  code { background: #f0f0f0; padding: 1px 4px; border-radius: 3px; }
</style>
</head>
<body>

<!--
  이 파일에는 API 키가 없습니다. 로그인 후 ulogin 서버에서 받아옵니다.
  받은 키는 자바스크립트 변수(메모리)에만 두고 어떤 저장소에도 쓰지 않습니다.
-->

<h1>ulogin 연동 AI 앱</h1>

<div class="card" id="box-anon">
  <p>로그인이 필요합니다.</p>
  <button id="btn-login">ulogin으로 로그인</button>
</div>

<div class="card" id="box-user" hidden>
  <div class="row">
    <strong id="u-name">-</strong>
    <span class="muted" id="u-grades">-</span>
    <span class="muted">포인트 <strong id="u-points">-</strong></span>
    <button id="btn-logout">로그아웃</button>
  </div>
  <p class="muted" id="u-secret">-</p>
</div>

<div class="card" id="box-ai" hidden>
  <p>프롬프트를 입력하고 <strong>전송</strong>을 누르면 호출 1회당 <strong>10 포인트</strong>가 차감됩니다.</p>
  <textarea id="prompt" placeholder="예: 한국의 수도는 어디인가요?"></textarea>
  <div class="row" style="margin-top:8px;">
    <button id="btn-send">전송</button>
    <span class="muted" id="ai-status"></span>
  </div>
  <pre id="ai-out" hidden></pre>
</div>

<p class="err" id="global-err"></p>

<script>
(function () {
  'use strict';

  /* ================= 설정 — 이 두 줄만 바꾸면 됩니다 ================= */
  var SERVER      = '<<설치루트>>';   // 예: 'https://example.com/ulogin'  (끝 슬래시 없이)
  var SERVICE_ID  = '<<서비스ID>>';   // 예: 'local-ai-app'
  var REDIRECT_URI = location.origin + location.pathname.replace(/[^/]*$/, '') + 'callback.html';
  var COST_PER_CALL = 10;             // 호출 1회당 차감할 포인트
  /* ================================================================= */

  var API = SERVER + '/server/api';   // 모든 API 는 여기 아래에 .php 로 있다
  var LOGIN_URL = SERVER + '/public/login.html';

  var K_ACCESS  = 'demo_' + SERVICE_ID + '_access_token';
  var K_REFRESH = 'demo_' + SERVICE_ID + '_refresh_token';
  var K_EXPIRES = 'demo_' + SERVICE_ID + '_expires_at';
  var SS_STATE  = 'demo_' + SERVICE_ID + '_state';
  var SS_VERIFY = 'demo_' + SERVICE_ID + '_verifier';

  /* payload 는 메모리에만 둔다. 저장소에 쓰지 않는다. */
  var _payload = null;
  var _user = null;
  var _refreshInFlight = null;

  /* ---------- 유틸 ---------- */

  function $(id) { return document.getElementById(id); }

  function store(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function put(k, v) {
    try {
      if (v === null || v === undefined || v === '') localStorage.removeItem(k);
      else localStorage.setItem(k, String(v));
    } catch (e) { /* 사생활 보호 모드 등 */ }
  }

  function randomHex(byteLen) {
    var b = new Uint8Array(byteLen);
    (window.crypto || window.msCrypto).getRandomValues(b);
    var s = '';
    for (var i = 0; i < b.length; i++) s += ('0' + b[i].toString(16)).slice(-2);
    return s;
  }

  function makeUuid() {
    if (window.crypto && typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    var b = new Uint8Array(16);
    (window.crypto || window.msCrypto).getRandomValues(b);
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    var h = [];
    for (var i = 0; i < 16; i++) h.push(('0' + b[i].toString(16)).slice(-2));
    return h.slice(0, 4).join('') + '-' + h.slice(4, 6).join('') + '-' +
           h.slice(6, 8).join('') + '-' + h.slice(8, 10).join('') + '-' + h.slice(10, 16).join('');
  }

  function makePkce() {
    var verifier = randomHex(32);
    if (!(window.crypto && crypto.subtle && crypto.subtle.digest)) {
      // file:// 이나 비-HTTPS 환경. 서버가 PKCE 를 강제하지 않으므로 없이 진행한다.
      return Promise.resolve({ verifier: null, challenge: null });
    }
    var bytes = new TextEncoder().encode(verifier);
    return crypto.subtle.digest('SHA-256', bytes).then(function (buf) {
      var arr = new Uint8Array(buf), bin = '';
      for (var i = 0; i < arr.length; i++) bin += String.fromCharCode(arr[i]);
      var b64 = btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
      return { verifier: verifier, challenge: b64 };
    }).catch(function () {
      return { verifier: null, challenge: null };
    });
  }

  function apiError(json, status) {
    var e = new Error((json && json.error && json.error.message) || '요청에 실패했습니다.');
    e.code = (json && json.error && json.error.code) || 'SERVER_ERROR';
    e.status = status;
    e.field = (json && json.error && json.error.field) || null;
    e.data = (json && json.data) || null;
    return e;
  }

  /* ---------- HTTP ---------- */

  function rawFetch(path, opts) {
    opts = opts || {};
    var headers = { 'Accept': 'application/json' };
    if (opts.body) headers['Content-Type'] = 'application/json';
    if (opts.token) headers['Authorization'] = 'Bearer ' + opts.token;

    return fetch(API + path, {
      method: opts.method || 'GET',
      headers: headers,
      body: opts.body ? JSON.stringify(opts.body) : null,
      mode: 'cors',
      cache: 'no-store',
      credentials: 'omit'      // Bearer 전용. 쿠키를 안 보내므로 CSRF 대상이 아니다
    }).catch(function (e) {
      var err = new Error('서버에 연결할 수 없습니다. (' + (e && e.message ? e.message : 'fetch 실패') + ')');
      err.code = 'NETWORK_ERROR';
      err.status = 0;
      throw err;
    }).then(function (res) {
      return res.json().catch(function () {
        var err = new Error('서버 응답을 해석할 수 없습니다.');
        err.code = 'SERVER_ERROR';
        err.status = res.status;
        throw err;
      }).then(function (json) {
        if (!json || json.ok !== true) throw apiError(json, res.status);
        return json.data;
      });
    });
  }

  function doRefresh() {
    var rt = store(K_REFRESH);
    if (!rt) return Promise.reject(apiError({ error: { code: 'AUTH_SESSION_EXPIRED' } }, 401));
    if (_refreshInFlight) return _refreshInFlight;   // 동시 갱신을 하나로 합친다

    _refreshInFlight = rawFetch('/auth/refresh.php', {
      method: 'POST',
      body: { refresh_token: rt }
    }).then(function (data) {
      _refreshInFlight = null;
      applyAuth(data);
      return data;
    }).catch(function (e) {
      _refreshInFlight = null;
      clearAuth();
      throw e;
    });
    return _refreshInFlight;
  }

  /* 인증이 필요한 호출. 401 이면 refresh 를 딱 한 번 시도한다. */
  function authed(path, opts, retried) {
    var at = store(K_ACCESS);
    if (!at) return Promise.reject(apiError({ error: { code: 'AUTH_REQUIRED' } }, 401));
    var o = {};
    for (var k in opts) if (Object.prototype.hasOwnProperty.call(opts, k)) o[k] = opts[k];
    o.token = at;

    return rawFetch(path, o).catch(function (e) {
      if (!retried && e.status === 401 &&
          (e.code === 'AUTH_SESSION_EXPIRED' || e.code === 'AUTH_REQUIRED')) {
        return doRefresh().then(function () {
          return authed(path, opts, true);
        });
      }
      throw e;
    });
  }

  /* ---------- 인증 상태 ---------- */

  function applyAuth(data) {
    if (data.access_token) put(K_ACCESS, data.access_token);
    if (data.refresh_token) put(K_REFRESH, data.refresh_token);
    if (typeof data.expires_in === 'number') put(K_EXPIRES, Date.now() + data.expires_in * 1000);
    if (data.user) _user = data.user;
    // payload 는 매번 통째로 교체한다. 병합하면 등급 강등으로 빠져야 할 키가 남는다.
    if (Object.prototype.hasOwnProperty.call(data, 'payload')) _payload = data.payload || {};
  }

  function clearAuth() {
    put(K_ACCESS, null); put(K_REFRESH, null); put(K_EXPIRES, null);
    _payload = null; _user = null;
  }

  function isLoggedIn() {
    if (!store(K_ACCESS)) return false;
    var exp = parseInt(store(K_EXPIRES), 10);
    if (!exp || isNaN(exp)) return true;
    if (Date.now() < exp) return true;
    return !!store(K_REFRESH);         // 만료됐어도 refresh 가 있으면 복구 가능
  }

  /* ---------- 로그인 / 로그아웃 ---------- */

  function startLogin() {
    return makePkce().then(function (pkce) {
      var state = randomHex(32);
      try {
        sessionStorage.setItem(SS_STATE, state);
        if (pkce.verifier) sessionStorage.setItem(SS_VERIFY, pkce.verifier);
        else sessionStorage.removeItem(SS_VERIFY);
      } catch (e) {
        showError('sessionStorage 를 쓸 수 없어 state 검증이 불가능합니다. 시크릿 모드를 해제해 주세요.');
        return;
      }
      var q = '?service_id=' + encodeURIComponent(SERVICE_ID) +
              '&redirect_uri=' + encodeURIComponent(REDIRECT_URI) +
              '&state=' + encodeURIComponent(state) +
              (pkce.challenge ? '&code_challenge=' + encodeURIComponent(pkce.challenge) : '');
      location.assign(LOGIN_URL + q);
    });
  }

  function doLogout() {
    var at = store(K_ACCESS);
    var done = function () { clearAuth(); render(); };
    if (!at) { done(); return Promise.resolve(); }
    return rawFetch('/auth/logout.php', {
      method: 'POST', token: at, body: { all_devices: false }
    }).then(done).catch(function (e) {
      // 401 도 로그아웃 성공으로 처리한다 — 어차피 세션이 없다
      if (e.code === 'AUTH_REQUIRED' || e.code === 'AUTH_SESSION_EXPIRED') { done(); return; }
      done();
      showError('로그아웃 중 오류: ' + e.message);
    });
  }

  /* ---------- 포인트 ---------- */

  function consume(amount, refId, idemKey) {
    return authed('/points/consume.php', {
      method: 'POST',
      body: {
        amount: amount,
        reason: 'usage',
        service_id: SERVICE_ID,
        ref_type: 'chat',
        ref_id: refId,
        idempotency_key: idemKey
      }
    });
  }

  /* ---------- AI 호출 ---------- */

  /* payload 로 받은 키로 OpenAI 호환 Chat Completions 엔드포인트를 직접 호출한다.
     이 함수는 ulogin 서버를 거치지 않는다. */
  function callAi(prompt) {
    var key   = _payload && _payload.ai_api_key;
    var base  = (_payload && _payload.api_base) || 'https://api.openai.com/v1';
    var model = (_payload && _payload.model) || 'gpt-4o-mini';
    if (!key) return Promise.reject(new Error('AI 키가 없습니다.'));

    return fetch(base.replace(/\/+$/, '') + '/chat/completions', {
      method: 'POST',
      headers: { 'Authorization': 'Bearer ' + key, 'Content-Type': 'application/json' },
      body: JSON.stringify({
        model: model,
        messages: [{ role: 'user', content: prompt }]
      })
    }).then(function (r) {
      return r.json().then(function (j) {
        if (!r.ok) throw new Error((j && j.error && j.error.message) || ('AI 호출 실패 (HTTP ' + r.status + ')'));
        return (j.choices && j.choices[0] && j.choices[0].message && j.choices[0].message.content) || '(빈 응답)';
      });
    });
  }

  /* ---------- 화면 ---------- */

  function maskKey(v) {
    if (typeof v !== 'string' || v.length <= 6) return '******';
    return v.slice(0, 6) + '…' + '*'.repeat(Math.min(8, v.length - 6));
  }

  function showError(msg) { $('global-err').textContent = msg || ''; }

  function render() {
    var logged = isLoggedIn() && !!_user;
    $('box-anon').hidden = logged;
    $('box-user').hidden = !logged;
    $('box-ai').hidden = !logged;
    if (!logged) return;

    $('u-name').textContent = (_user.nickname || _user.name || _user.username) + ' 님';
    $('u-grades').textContent =
      '계정 ' + _user.account_level + '(' + _user.account_level_rank + ') · ' +
      '역할 ' + _user.role + '(' + _user.role_rank + ') · ' +
      '결제 ' + _user.billing_tier + '(' + _user.billing_tier_rank + ')' +
      (_user.billing_active ? '' : ' · 결제 비활성');
    $('u-points').textContent = _user.points;

    var key = _payload && _payload.ai_api_key;
    if (key) {
      $('u-secret').textContent =
        'AI 키 수신됨: ' + maskKey(key) +
        ' · 모델 ' + ((_payload && _payload.model) || 'gpt-4o-mini');
      $('btn-send').disabled = false;
    } else {
      $('u-secret').textContent =
        'AI 키를 받지 못했습니다. 결제 등급이 부족하거나, 이 서비스에 가입되어 있지 않거나, ' +
        '관리자가 아직 키를 등록하지 않았습니다.';
      $('btn-send').disabled = true;
    }
  }

  /* ---------- 부팅 ---------- */

  function boot() {
    $('btn-login').onclick = function () { showError(''); startLogin(); };
    $('btn-logout').onclick = function () { showError(''); doLogout(); };

    $('btn-send').onclick = function () {
      var prompt = $('prompt').value.trim();
      if (!prompt) { showError('프롬프트를 입력해 주세요.'); return; }
      showError('');
      $('btn-send').disabled = true;
      $('ai-status').textContent = '포인트 차감 중…';
      $('ai-out').hidden = true;

      var refId = 'msg_' + Date.now();
      var idemKey = makeUuid();        // 이 작업 1건의 키. 재시도해도 같은 값을 쓴다.

      consume(COST_PER_CALL, refId, idemKey).then(function (p) {
        _user.points = p.points;
        _user.total_points_spent = p.total_points_spent;
        render();
        $('ai-status').textContent = 'AI 호출 중…';
        return callAi(prompt);
      }).then(function (text) {
        $('ai-status').textContent = '';
        $('ai-out').hidden = false;
        $('ai-out').textContent = text;
      }).catch(function (e) {
        $('ai-status').textContent = '';
        if (e.code === 'POINT_INSUFFICIENT') {
          var d = e.data || {};
          showError('포인트가 부족합니다. 현재 ' + d.points + 'P / 필요 ' + d.required + 'P (부족 ' + d.shortfall + 'P)');
          if (typeof d.points === 'number') { _user.points = d.points; render(); }
        } else if (e.code === 'AUTH_SESSION_EXPIRED' || e.code === 'AUTH_REQUIRED') {
          clearAuth();
          render();
          showError('세션이 만료되었습니다. 다시 로그인해 주세요.');
        } else {
          showError(e.message || String(e));
        }
      }).then(function () {
        $('btn-send').disabled = !(_payload && _payload.ai_api_key);
      });
    };

    if (!isLoggedIn()) { render(); return; }

    // 앱을 열 때마다 세션을 재검증하고 payload 를 다시 받는다.
    // payload 를 저장하지 않으므로 이 호출이 반드시 필요하다.
    authed('/auth/session.php', { method: 'GET' }).then(function (data) {
      applyAuth(data);
      render();
    }).catch(function (e) {
      clearAuth();
      render();
      if (e.code !== 'AUTH_REQUIRED' && e.code !== 'AUTH_SESSION_EXPIRED') {
        showError(e.message || String(e));
      }
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
</script>
</body>
</html>
```

#### `callback.html`

```html
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>로그인 처리 중</title>
<style>
  body { font-family: system-ui, -apple-system, "Malgun Gothic", sans-serif; max-width: 620px; margin: 80px auto; padding: 0 16px; line-height: 1.6; }
  .err { color: #b00020; }
  button { padding: 8px 16px; border-radius: 6px; border: 1px solid #888; background: #fff; cursor: pointer; }
</style>
</head>
<body>
<h1>로그인 처리 중</h1>
<p id="msg">잠시만 기다려 주세요…</p>
<p class="err" id="err"></p>
<p id="actions" hidden><button id="btn-back">앱으로 돌아가기</button></p>

<script>
(function () {
  'use strict';

  /* ================= app.html 과 같은 값을 넣습니다 ================= */
  var SERVER     = '<<설치루트>>';   // 예: 'https://example.com/ulogin'
  var SERVICE_ID = '<<서비스ID>>';   // 예: 'local-ai-app'
  /* ================================================================ */

  var API = SERVER + '/server/api';
  var REDIRECT_URI = location.origin + location.pathname;   // 이 페이지 자신의 절대 URL
  var APP_URL = location.pathname.replace(/[^/]*$/, '') + 'app.html';

  var K_ACCESS  = 'demo_' + SERVICE_ID + '_access_token';
  var K_REFRESH = 'demo_' + SERVICE_ID + '_refresh_token';
  var K_EXPIRES = 'demo_' + SERVICE_ID + '_expires_at';
  var SS_STATE  = 'demo_' + SERVICE_ID + '_state';
  var SS_VERIFY = 'demo_' + SERVICE_ID + '_verifier';

  function $(id) { return document.getElementById(id); }
  function put(k, v) { try { localStorage.setItem(k, String(v)); } catch (e) { /* ignore */ } }

  function fail(msg) {
    $('msg').textContent = '';
    $('err').textContent = msg;
    $('actions').hidden = false;
  }

  $('btn-back').onclick = function () { location.replace(APP_URL); };

  var params = new URLSearchParams(location.search);
  var code  = params.get('code');
  var state = params.get('state');

  var savedState = null, verifier = null;
  try {
    savedState = sessionStorage.getItem(SS_STATE);
    verifier   = sessionStorage.getItem(SS_VERIFY);
    sessionStorage.removeItem(SS_STATE);
    sessionStorage.removeItem(SS_VERIFY);
  } catch (e) { /* ignore */ }

  if (!code) {
    fail('인증 코드가 없습니다. 로그인을 다시 시작해 주세요.');
    return;
  }
  // ★ state 대조. 불일치면 교환하지 않는다 — 세션 주입 방어의 유일한 지점이다.
  if (!savedState || !state || state !== savedState) {
    fail('보안 검증(state)에 실패했습니다. 로그인을 다시 시작해 주세요.');
    return;
  }

  var body = {
    code: code,
    service_id: SERVICE_ID,
    redirect_uri: REDIRECT_URI
  };
  if (verifier) body.code_verifier = verifier;

  fetch(API + '/auth/exchange.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
    body: JSON.stringify(body),
    mode: 'cors',
    cache: 'no-store',
    credentials: 'omit'
  }).then(function (r) {
    return r.json().then(function (j) { return { status: r.status, json: j }; });
  }).then(function (res) {
    var j = res.json;
    if (!j || j.ok !== true) {
      var c = (j && j.error && j.error.code) || 'SERVER_ERROR';
      var m = (j && j.error && j.error.message) || '로그인에 실패했습니다.';
      fail('[' + c + '] ' + m);
      return;
    }
    var d = j.data;
    put(K_ACCESS, d.access_token);
    put(K_REFRESH, d.refresh_token);
    put(K_EXPIRES, Date.now() + (d.expires_in || 0) * 1000);
    // payload 는 저장하지 않는다. app.html 이 GET /api/auth/session.php 로 다시 받는다.

    // 히스토리에 code/state 가 남지 않게 URL 을 정리하고 앱으로 돌아간다
    try { history.replaceState(null, '', location.pathname); } catch (e) { /* ignore */ }
    location.replace(APP_URL);
  }).catch(function (e) {
    fail('서버에 연결할 수 없습니다. (' + (e && e.message ? e.message : 'fetch 실패') + ')');
  });
})();
</script>
</body>
</html>
```

#### 실행 방법

```bash
# app.html 과 callback.html 이 있는 폴더에서
python -m http.server 5173
```

그다음 브라우저에서 `http://localhost:5173/app.html`을 엽니다.

> `python -m http.server`는 **PHP를 실행하지 못합니다.** 이것은 *당신의 앱 파일*을 HTTP로 서빙하기 위한 것이고, ulogin 서버는 PHP가 도는 호스팅에 별도로 올라가 있어야 합니다.
> `file:///…/app.html`로 직접 열면 Origin이 `null`이라 CORS가 막히고 `crypto.subtle`도 없습니다. 반드시 로컬 웹서버를 쓰세요.

**관리자 콘솔에 등록해야 하는 값 (이 예제 기준):**

| 항목 | 값 |
|---|---|
| `redirect_whitelist` | `http://localhost:5173/callback.html` |
| `allowed_origins` | `http://localhost:5173` |

**이 시점에 정상이면 무엇이 보이는가:**
1. `app.html`에 "로그인이 필요합니다" + 버튼
2. 버튼 클릭 → ulogin 로그인 화면
3. 로그인 → `callback.html`이 잠깐 보였다가 → `app.html`로 복귀
4. 사용자명 · 3축 등급 · 포인트 · `AI 키 수신됨: sk-pro…********` 표시
5. 프롬프트 입력 → 전송 → 포인트가 10 줄고 AI 응답이 표시됨

**실패하면 확인할 것:** 15절의 진단 절차를 순서대로 따라가세요.

---

## 12. 프레임워크별 연동

### 12-1. Vanilla JS (모듈 형태)

`ulogin.js`로 저장해 `<script type="module">`에서 `import`하거나, 번들러에서 씁니다.

```javascript
/* ulogin.js — 의존성 없는 ulogin 연동 모듈 (ES2015+) */

const RANKS = {
  account_level: { guest: 0, basic: 10, verified: 20, vip: 30, staff: 90 },
  role:          { user: 0, manager: 10, admin: 20, superadmin: 30 },
  billing_tier:  { free: 0, lite: 10, pro: 20, enterprise: 30 }
};

export class UloginError extends Error {
  constructor(code, message, status, data, field) {
    super(message || code);
    this.name = 'UloginError';
    this.code = code;
    this.status = status || 0;
    this.data = data || null;
    this.field = field || null;
  }
}

export class Ulogin {
  /**
   * @param {object} opt
   *   server      : 설치 루트 URL (끝 슬래시 없이). 예: 'https://example.com/ulogin'
   *   serviceId   : 관리자 콘솔에 등록한 service_id
   *   redirectUri : 콜백 페이지 절대 URL (redirect_whitelist 등록값과 완전 일치)
   */
  constructor(opt) {
    if (!opt || !opt.server) throw new UloginError('CONFIG_ERROR', 'server 가 필요합니다.');
    if (!opt.serviceId) throw new UloginError('CONFIG_ERROR', 'serviceId 가 필요합니다.');
    if (!opt.redirectUri) throw new UloginError('CONFIG_ERROR', 'redirectUri 가 필요합니다.');

    this.server = String(opt.server).replace(/\/+$/, '');
    this.api = this.server + '/server/api';
    this.loginUrl = this.server + '/public/login.html';
    this.serviceId = String(opt.serviceId);
    this.redirectUri = String(opt.redirectUri);

    const p = 'ulogin_' + this.serviceId + '_';
    this.keys = { access: p + 'access_token', refresh: p + 'refresh_token', expires: p + 'expires_at' };
    this.ss = { state: p + 'state', verifier: p + 'verifier' };

    // 메모리 전용 — 저장소에 쓰지 않는다
    this.user = null;
    this.session = null;
    this.payload = null;
    this._refreshing = null;
  }

  /* ---------- 저장소 ---------- */
  _get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  _put(k, v) {
    try {
      if (v === null || v === undefined || v === '') localStorage.removeItem(k);
      else localStorage.setItem(k, String(v));
    } catch (e) { /* private mode */ }
  }

  getAccessToken() { return this._get(this.keys.access); }
  getRefreshToken() { return this._get(this.keys.refresh); }

  isLoggedIn() {
    if (!this.getAccessToken()) return false;
    const exp = parseInt(this._get(this.keys.expires), 10);
    if (!exp || Number.isNaN(exp)) return true;
    if (Date.now() < exp) return true;
    return !!this.getRefreshToken();
  }

  /* ---------- HTTP ---------- */
  async _raw(path, { method = 'GET', body = null, token = null } = {}) {
    const headers = { Accept: 'application/json' };
    if (body) headers['Content-Type'] = 'application/json';
    if (token) headers['Authorization'] = 'Bearer ' + token;

    let res;
    try {
      res = await fetch(this.api + path, {
        method, headers,
        body: body ? JSON.stringify(body) : null,
        mode: 'cors', cache: 'no-store', credentials: 'omit'
      });
    } catch (e) {
      throw new UloginError('NETWORK_ERROR', '서버에 연결할 수 없습니다. (' + e.message + ')', 0);
    }

    let json;
    try {
      json = await res.json();
    } catch (e) {
      throw new UloginError('SERVER_ERROR', '서버 응답을 해석할 수 없습니다.', res.status);
    }
    if (!json || json.ok !== true) {
      const err = (json && json.error) || {};
      throw new UloginError(err.code || 'SERVER_ERROR', err.message, res.status, json && json.data, err.field);
    }
    return json.data;
  }

  async _refresh() {
    const rt = this.getRefreshToken();
    if (!rt) throw new UloginError('AUTH_SESSION_EXPIRED', '세션이 만료되었습니다.', 401);
    if (this._refreshing) return this._refreshing;

    this._refreshing = (async () => {
      try {
        const data = await this._raw('/auth/refresh.php', { method: 'POST', body: { refresh_token: rt } });
        this._apply(data);
        return data;
      } catch (e) {
        this.clear();
        throw e;
      } finally {
        this._refreshing = null;
      }
    })();
    return this._refreshing;
  }

  async _authed(path, opts = {}, retried = false) {
    const at = this.getAccessToken();
    if (!at) throw new UloginError('AUTH_REQUIRED', '로그인이 필요합니다.', 401);
    try {
      return await this._raw(path, { ...opts, token: at });
    } catch (e) {
      if (!retried && e.status === 401 && (e.code === 'AUTH_SESSION_EXPIRED' || e.code === 'AUTH_REQUIRED')) {
        await this._refresh();
        return this._authed(path, opts, true);
      }
      throw e;
    }
  }

  _apply(data) {
    if (data.access_token) this._put(this.keys.access, data.access_token);
    if (data.refresh_token) this._put(this.keys.refresh, data.refresh_token);
    if (typeof data.expires_in === 'number') this._put(this.keys.expires, Date.now() + data.expires_in * 1000);
    if (data.user) this.user = data.user;
    if (data.session) this.session = data.session;
    // payload 는 통째로 교체. 병합하지 않는다.
    if (Object.prototype.hasOwnProperty.call(data, 'payload')) this.payload = data.payload || {};
    return data;
  }

  clear() {
    this._put(this.keys.access, null);
    this._put(this.keys.refresh, null);
    this._put(this.keys.expires, null);
    this.user = null; this.session = null; this.payload = null;
  }

  /* ---------- PKCE / state ---------- */
  static _hex(n) {
    const b = new Uint8Array(n);
    crypto.getRandomValues(b);
    return Array.from(b, x => x.toString(16).padStart(2, '0')).join('');
  }

  static async _pkce() {
    const verifier = Ulogin._hex(32);
    if (!(globalThis.crypto && crypto.subtle && crypto.subtle.digest)) {
      return { verifier: null, challenge: null };
    }
    try {
      const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(verifier));
      const bin = String.fromCharCode(...new Uint8Array(buf));
      const challenge = btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
      return { verifier, challenge };
    } catch (e) {
      return { verifier: null, challenge: null };
    }
  }

  /* ---------- 공개 API ---------- */

  /** 로그인 화면으로 이동한다. 이 함수는 반환되지 않는다(페이지 이동). */
  async login() {
    const pkce = await Ulogin._pkce();
    const state = Ulogin._hex(32);
    try {
      sessionStorage.setItem(this.ss.state, state);
      if (pkce.verifier) sessionStorage.setItem(this.ss.verifier, pkce.verifier);
      else sessionStorage.removeItem(this.ss.verifier);
    } catch (e) {
      throw new UloginError('CONFIG_ERROR', 'sessionStorage 를 쓸 수 없어 state 검증이 불가능합니다.', 0);
    }
    const q = new URLSearchParams({
      service_id: this.serviceId,
      redirect_uri: this.redirectUri,
      state
    });
    if (pkce.challenge) q.set('code_challenge', pkce.challenge);
    location.assign(this.loginUrl + '?' + q.toString());
  }

  /** 콜백 페이지에서 1회 호출한다. */
  async handleCallback() {
    const params = new URLSearchParams(location.search);
    const code = params.get('code');
    const state = params.get('state');

    let saved = null, verifier = null;
    try {
      saved = sessionStorage.getItem(this.ss.state);
      verifier = sessionStorage.getItem(this.ss.verifier);
      sessionStorage.removeItem(this.ss.state);
      sessionStorage.removeItem(this.ss.verifier);
    } catch (e) { /* ignore */ }

    if (!code) throw new UloginError('CALLBACK_NO_CODE', '인증 코드가 없습니다.', 400);
    if (!saved || !state || state !== saved) {
      throw new UloginError('STATE_MISMATCH', '보안 검증(state)에 실패했습니다.', 400);
    }

    const body = { code, service_id: this.serviceId, redirect_uri: this.redirectUri };
    if (verifier) body.code_verifier = verifier;

    const data = await this._raw('/auth/exchange.php', { method: 'POST', body });
    this._apply(data);

    try { history.replaceState(null, '', location.pathname); } catch (e) { /* ignore */ }
    return data;
  }

  /** 세션 재검증 + payload 재수신. 앱을 열 때마다 호출한다. */
  async getSession() {
    const data = await this._authed('/auth/session.php', { method: 'GET' });
    return this._apply(data);
  }

  /** 포인트 차감. idempotencyKey 를 넘기면 재시도해도 이중 차감되지 않는다. */
  async consumePoints(amount, { reason = 'usage', refType = null, refId = null, memo = null, idempotencyKey = null } = {}) {
    const body = { amount, reason, service_id: this.serviceId };
    if (refType) body.ref_type = refType;
    if (refId) body.ref_id = refId;
    if (memo) body.memo = memo;
    body.idempotency_key = idempotencyKey || (crypto.randomUUID ? crypto.randomUUID() : Ulogin._hex(16));

    const data = await this._authed('/points/consume.php', { method: 'POST', body });
    if (this.user && typeof data.points === 'number') {
      this.user.points = data.points;
      this.user.total_points_spent = data.total_points_spent;
    }
    return data;
  }

  async logout({ allDevices = false } = {}) {
    const at = this.getAccessToken();
    if (!at) { this.clear(); return { revoked_count: 0, all_devices: allDevices }; }
    try {
      const data = await this._raw('/auth/logout.php', {
        method: 'POST', token: at, body: { all_devices: allDevices }
      });
      this.clear();
      return data;
    } catch (e) {
      this.clear();
      // 401 도 로그아웃 성공으로 본다
      if (e.code === 'AUTH_REQUIRED' || e.code === 'AUTH_SESSION_EXPIRED') {
        return { revoked_count: 0, all_devices: allDevices };
      }
      throw e;
    }
  }

  /* ---------- 등급 판정 헬퍼 (문자열 비교 금지) ---------- */

  /** 예: ulogin.gte('billing_tier', 'lite') */
  gte(axis, minValue) {
    if (!this.user) return false;
    const table = RANKS[axis];
    if (!table) return false;
    const mine = this.user[axis + '_rank'];
    if (typeof mine !== 'number') return false;
    return mine >= (table[minValue] || 0);
  }

  /** payload 에서 키를 안전하게 꺼낸다. 없으면 fallback. */
  secret(key, fallback = null) {
    if (!this.payload) return fallback;
    const v = this.payload[key];
    return (v === undefined || v === null) ? fallback : v;
  }
}
```

사용:

```javascript
import { Ulogin, UloginError } from './ulogin.js';

const ulogin = new Ulogin({
  server: '<<설치루트>>',
  serviceId: '<<서비스ID>>',
  redirectUri: location.origin + '/callback.html'
});

// 콜백 페이지에서
// await ulogin.handleCallback();

// 앱 페이지에서
if (ulogin.isLoggedIn()) {
  try {
    await ulogin.getSession();
    if (ulogin.gte('billing_tier', 'lite') && ulogin.secret('ai_api_key')) {
      enableAi(ulogin.secret('ai_api_key'), ulogin.secret('api_base', 'https://api.openai.com/v1'));
    } else {
      showUpgradeNotice();
    }
  } catch (e) {
    if (e instanceof UloginError && e.status === 401) ulogin.login();
    else console.error(e.code, e.message);
  }
} else {
  document.querySelector('#login').onclick = () => ulogin.login();
}
```

### 12-2. React

`src/ulogin.js`에 위 12-1의 `Ulogin` 클래스를 그대로 두고, 아래 훅과 컴포넌트를 씁니다.

```jsx
/* src/useUlogin.js */
import { useState, useEffect, useCallback, useRef } from 'react';
import { Ulogin, UloginError } from './ulogin';

const SERVER = import.meta.env.VITE_ULOGIN_SERVER;         // 예: 'https://example.com/ulogin'
const SERVICE_ID = import.meta.env.VITE_ULOGIN_SERVICE_ID; // 예: 'local-ai-app'

export function useUlogin() {
  // 인스턴스는 한 번만 만든다. 리렌더마다 새로 만들면 메모리의 payload 가 사라진다.
  const clientRef = useRef(null);
  if (clientRef.current === null) {
    clientRef.current = new Ulogin({
      server: SERVER,
      serviceId: SERVICE_ID,
      redirectUri: window.location.origin + '/callback'
    });
  }
  const ulogin = clientRef.current;

  const [state, setState] = useState({
    loading: true,
    loggedIn: false,
    user: null,
    payload: null,
    error: null
  });

  const sync = useCallback(() => {
    setState({
      loading: false,
      loggedIn: ulogin.isLoggedIn() && !!ulogin.user,
      user: ulogin.user,
      payload: ulogin.payload,
      error: null
    });
  }, [ulogin]);

  useEffect(() => {
    let cancelled = false;

    if (!ulogin.isLoggedIn()) {
      setState({ loading: false, loggedIn: false, user: null, payload: null, error: null });
      return () => { cancelled = true; };
    }

    ulogin.getSession()
      .then(() => { if (!cancelled) sync(); })
      .catch((e) => {
        if (cancelled) return;
        ulogin.clear();
        setState({
          loading: false, loggedIn: false, user: null, payload: null,
          error: e instanceof UloginError ? e : new UloginError('SERVER_ERROR', String(e), 0)
        });
      });

    return () => { cancelled = true; };
  }, [ulogin, sync]);

  const login = useCallback(() => ulogin.login(), [ulogin]);

  const logout = useCallback(async () => {
    await ulogin.logout();
    setState({ loading: false, loggedIn: false, user: null, payload: null, error: null });
  }, [ulogin]);

  const consume = useCallback(async (amount, opts) => {
    const data = await ulogin.consumePoints(amount, opts);
    sync();
    return data;
  }, [ulogin, sync]);

  const refreshSession = useCallback(async () => {
    await ulogin.getSession();
    sync();
  }, [ulogin, sync]);

  return { ...state, ulogin, login, logout, consume, refreshSession };
}
```

```jsx
/* src/CallbackPage.jsx — 라우터에서 /callback 경로에 연결한다 */
import { useEffect, useState, useRef } from 'react';
import { Ulogin } from './ulogin';

const SERVER = import.meta.env.VITE_ULOGIN_SERVER;
const SERVICE_ID = import.meta.env.VITE_ULOGIN_SERVICE_ID;

export default function CallbackPage() {
  const [msg, setMsg] = useState('로그인 처리 중입니다…');
  const ranRef = useRef(false);   // StrictMode 의 이중 실행에서 code 를 두 번 쓰지 않게 한다

  useEffect(() => {
    if (ranRef.current) return;
    ranRef.current = true;

    const ulogin = new Ulogin({
      server: SERVER,
      serviceId: SERVICE_ID,
      redirectUri: window.location.origin + '/callback'
    });

    ulogin.handleCallback()
      .then(() => { window.location.replace('/'); })
      .catch((e) => { setMsg('로그인 실패: [' + e.code + '] ' + e.message); });
  }, []);

  return <p>{msg}</p>;
}
```

```jsx
/* src/App.jsx */
import { useState } from 'react';
import { useUlogin } from './useUlogin';

export default function App() {
  const { loading, loggedIn, user, payload, error, login, logout, consume } = useUlogin();
  const [prompt, setPrompt] = useState('');
  const [answer, setAnswer] = useState('');
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState('');

  if (loading) return <p>확인 중…</p>;

  if (!loggedIn) {
    return (
      <div>
        {error && <p style={{ color: '#b00020' }}>[{error.code}] {error.message}</p>}
        <button onClick={login}>ulogin으로 로그인</button>
      </div>
    );
  }

  const apiKey = payload?.ai_api_key ?? null;
  const apiBase = payload?.api_base ?? 'https://api.openai.com/v1';
  const model = payload?.model ?? 'gpt-4o-mini';

  async function send() {
    if (!prompt.trim()) { setNotice('프롬프트를 입력해 주세요.'); return; }
    setBusy(true); setNotice(''); setAnswer('');
    try {
      await consume(10, { refType: 'chat', refId: 'msg_' + Date.now() });
      const res = await fetch(apiBase.replace(/\/+$/, '') + '/chat/completions', {
        method: 'POST',
        headers: { Authorization: 'Bearer ' + apiKey, 'Content-Type': 'application/json' },
        body: JSON.stringify({ model, messages: [{ role: 'user', content: prompt }] })
      });
      const j = await res.json();
      if (!res.ok) throw new Error(j?.error?.message || ('AI 호출 실패 (HTTP ' + res.status + ')'));
      setAnswer(j.choices?.[0]?.message?.content ?? '(빈 응답)');
    } catch (e) {
      if (e.code === 'POINT_INSUFFICIENT') {
        setNotice(`포인트가 부족합니다. 현재 ${e.data.points}P / 필요 ${e.data.required}P`);
      } else if (e.code === 'AUTH_SESSION_EXPIRED' || e.code === 'AUTH_REQUIRED') {
        setNotice('세션이 만료되었습니다. 다시 로그인해 주세요.');
      } else {
        setNotice(e.message || String(e));
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <div>
      <p>
        <strong>{user.nickname || user.name || user.username} 님</strong>{' '}
        <span>계정 {user.account_level}({user.account_level_rank}) · 결제 {user.billing_tier}({user.billing_tier_rank})</span>{' '}
        <span>포인트 {user.points}</span>{' '}
        <button onClick={logout}>로그아웃</button>
      </p>

      {apiKey ? (
        <p>AI 키 수신됨: {apiKey.slice(0, 6)}…</p>
      ) : (
        <p style={{ color: '#b00020' }}>
          AI 키를 받지 못했습니다. 결제 등급이 부족하거나, 이 서비스에 가입되어 있지 않거나,
          관리자가 아직 키를 등록하지 않았습니다.
        </p>
      )}

      <textarea value={prompt} onChange={(e) => setPrompt(e.target.value)} rows={4} style={{ width: '100%' }} />
      <button onClick={send} disabled={busy || !apiKey}>{busy ? '처리 중…' : '전송 (10P)'}</button>

      {notice && <p style={{ color: '#b00020' }}>{notice}</p>}
      {answer && <pre style={{ whiteSpace: 'pre-wrap' }}>{answer}</pre>}
    </div>
  );
}
```

`.env` 파일:

```
VITE_ULOGIN_SERVER=<<설치루트>>
VITE_ULOGIN_SERVICE_ID=<<서비스ID>>
```

**React에서 주의할 점 2가지**

1. **`Ulogin` 인스턴스를 `useRef`로 한 번만 만드세요.** 리렌더마다 새로 만들면 메모리에 있던 `payload`가 사라져 매번 `getSession()`을 다시 해야 합니다.
2. **StrictMode의 이중 `useEffect` 실행에 주의하세요.** `handleCallback()`이 두 번 돌면 두 번째는 이미 소모된 code로 `SSO_CODE_INVALID`를 받습니다. 위 예제처럼 `useRef` 플래그로 1회만 돌게 하세요.

### 12-3. Node.js 서버사이드

서버에서 쓰는 경우는 **직접 로그인(방식 B)** 이 적합합니다. 사용자 비밀번호를 서버가 받아 ulogin으로 전달합니다.

```javascript
/* ulogin-node.js — Node 18+ (내장 fetch 사용). 외부 의존성 없음 */

const SERVER = process.env.ULOGIN_SERVER;          // 예: 'https://example.com/ulogin'
const SERVICE_ID = process.env.ULOGIN_SERVICE_ID;  // 예: 'server-agent'
const API = SERVER + '/server/api';

class UloginError extends Error {
  constructor(code, message, status, data, field) {
    super(message || code);
    this.name = 'UloginError';
    this.code = code;
    this.status = status || 0;
    this.data = data || null;
    this.field = field || null;
  }
}

async function call(path, { method = 'GET', body = null, token = null } = {}) {
  const headers = { Accept: 'application/json' };
  if (body) headers['Content-Type'] = 'application/json';
  if (token) headers['Authorization'] = 'Bearer ' + token;

  let res;
  try {
    res = await fetch(API + path, {
      method,
      headers,
      body: body ? JSON.stringify(body) : null
    });
  } catch (e) {
    throw new UloginError('NETWORK_ERROR', 'ulogin 서버에 연결할 수 없습니다: ' + e.message, 0);
  }

  let json;
  try {
    json = await res.json();
  } catch (e) {
    throw new UloginError('SERVER_ERROR', 'ulogin 응답을 해석할 수 없습니다.', res.status);
  }
  if (!json || json.ok !== true) {
    const err = json?.error || {};
    throw new UloginError(err.code || 'SERVER_ERROR', err.message, res.status, json?.data, err.field);
  }
  return json.data;
}

/** 직접 로그인. 토큰과 payload 를 함께 받는다. */
export async function login(username, password, deviceLabel = 'node-server') {
  return call('/auth/login.php', {
    method: 'POST',
    body: {
      username,
      password,
      service_id: SERVICE_ID,
      device_label: deviceLabel
    }
  });
}

export async function refresh(refreshToken) {
  return call('/auth/refresh.php', {
    method: 'POST',
    body: { refresh_token: refreshToken, device_label: 'node-server' }
  });
}

export async function getSession(accessToken) {
  return call('/auth/session.php', { method: 'GET', token: accessToken });
}

export async function consumePoints(accessToken, amount, idempotencyKey, opts = {}) {
  return call('/points/consume.php', {
    method: 'POST',
    token: accessToken,
    body: {
      amount,
      reason: opts.reason || 'usage',
      service_id: SERVICE_ID,
      ref_type: opts.refType || null,
      ref_id: opts.refId || null,
      memo: opts.memo || null,
      idempotency_key: idempotencyKey
    }
  });
}

export async function logout(accessToken, allDevices = false) {
  try {
    return await call('/auth/logout.php', {
      method: 'POST',
      token: accessToken,
      body: { all_devices: allDevices }
    });
  } catch (e) {
    // 이미 만료된 세션이면 401 이 온다. 로그아웃 성공과 같게 처리한다.
    if (e.code === 'AUTH_REQUIRED' || e.code === 'AUTH_SESSION_EXPIRED') {
      return { revoked_count: 0, all_devices: allDevices };
    }
    throw e;
  }
}

/**
 * 세션 1개를 들고 있는 간단한 매니저.
 * access_token 이 만료되면 자동으로 갱신한다.
 */
export class UloginSession {
  constructor() {
    this.accessToken = null;
    this.refreshToken = null;
    this.expiresAt = 0;
    this.user = null;
    this.payload = null;     // 메모리에만 둔다. 파일이나 DB 에 쓰지 않는다.
    this._refreshing = null;
  }

  _apply(data) {
    if (data.access_token) this.accessToken = data.access_token;
    if (data.refresh_token) this.refreshToken = data.refresh_token;
    if (typeof data.expires_in === 'number') this.expiresAt = Date.now() + data.expires_in * 1000;
    if (data.user) this.user = data.user;
    if (Object.prototype.hasOwnProperty.call(data, 'payload')) this.payload = data.payload || {};
    return data;
  }

  async signIn(username, password) {
    return this._apply(await login(username, password));
  }

  /** 만료 60초 전이면 미리 갱신한다. 동시 호출은 하나로 합친다. */
  async ensureFresh() {
    if (!this.accessToken) throw new UloginError('AUTH_REQUIRED', '로그인이 필요합니다.', 401);
    if (Date.now() < this.expiresAt - 60000) return;
    if (this._refreshing) { await this._refreshing; return; }

    this._refreshing = (async () => {
      try {
        this._apply(await refresh(this.refreshToken));
      } finally {
        this._refreshing = null;
      }
    })();
    await this._refreshing;
  }

  async secret(key, fallback = null) {
    await this.ensureFresh();
    if (this.payload === null) this._apply(await getSession(this.accessToken));
    const v = this.payload?.[key];
    return (v === undefined || v === null) ? fallback : v;
  }

  async charge(amount, idempotencyKey, opts = {}) {
    await this.ensureFresh();
    const data = await consumePoints(this.accessToken, amount, idempotencyKey, opts);
    if (this.user) {
      this.user.points = data.points;
      this.user.total_points_spent = data.total_points_spent;
    }
    return data;
  }
}

export { UloginError };
```

사용 예 (`crypto.randomUUID`는 Node 16.7+ 내장):

```javascript
import { randomUUID } from 'node:crypto';
import { UloginSession, UloginError } from './ulogin-node.js';

const s = new UloginSession();

try {
  await s.signIn(process.env.ULOGIN_USERNAME, process.env.ULOGIN_PASSWORD);

  const apiKey = await s.secret('ai_api_key');
  if (!apiKey) {
    console.error('AI 키를 받지 못했습니다. 등급 또는 서비스 가입 상태를 확인하세요.');
    process.exit(1);
  }
  const apiBase = await s.secret('api_base', 'https://api.openai.com/v1');
  const model = await s.secret('model', 'gpt-4o-mini');

  const jobKey = randomUUID();          // 이 작업 1건의 멱등키. 재시도에도 같은 값을 쓴다.
  await s.charge(10, jobKey, { refType: 'batch', refId: 'job-001' });

  const res = await fetch(apiBase.replace(/\/+$/, '') + '/chat/completions', {
    method: 'POST',
    headers: { Authorization: 'Bearer ' + apiKey, 'Content-Type': 'application/json' },
    body: JSON.stringify({ model, messages: [{ role: 'user', content: '한 문장으로 자기소개해 줘.' }] })
  });
  const j = await res.json();
  if (!res.ok) throw new Error(j?.error?.message || ('AI 호출 실패 HTTP ' + res.status));
  console.log(j.choices?.[0]?.message?.content ?? '(빈 응답)');
} catch (e) {
  if (e instanceof UloginError) {
    if (e.code === 'POINT_INSUFFICIENT') {
      console.error(`포인트 부족: 현재 ${e.data.points}P / 필요 ${e.data.required}P`);
    } else {
      console.error(`[${e.code}] ${e.message}`);
    }
  } else {
    console.error(e);
  }
  process.exit(1);
}
```

**서버사이드에서 주의할 점 3가지**

1. **`payload`를 파일·DB·로그에 쓰지 마세요.** 프로세스 메모리에만 두고, 재시작하면 다시 받으세요. 서버 로그에 API 키가 남는 것이 가장 흔한 유출 경로입니다.
2. **CSRF는 신경 쓰지 않아도 됩니다.** Bearer 토큰으로 인증하면 CSRF 검사가 면제됩니다. `X-CSRF-Token` 헤더를 만들 필요가 없습니다.
3. **로그인 계정을 공유하지 마세요.** 여러 워커가 같은 계정으로 각각 로그인하면 세션이 여러 개 생기는데, 그건 정상입니다. 다만 `refresh_token`을 여러 프로세스가 **동시에** 쓰면 재사용 탐지로 전체 세션이 회수됩니다. 프로세스마다 별도로 `signIn()` 하거나, 갱신을 한 프로세스로 몰아주세요.

### 12-4. PHP 서버사이드 (참고)

ulogin과 같은 호스팅의 다른 PHP 앱에서 쓰는 경우입니다.

> **아래 예제 코드 자체는 PHP 7.4 이상이 필요합니다.** ulogin 서버는 7.2에서도 동작하지만,
> 이 예제가 **타입 프로퍼티**(`private string $api;`)를 쓰기 때문입니다 — 7.4에서 추가된 문법이고
> **7.2·7.3에서는 파싱조차 되지 않습니다**(`PHP Parse error`).
>
> **PHP 7.2·7.3 호스팅에서 쓰려면 프로퍼티 선언 2줄에서 타입만 떼면 됩니다.** 나머지는 그대로 동작합니다.
>
> ```php
> // 7.4+
> private string $api;
> private string $serviceId;
>
> // 7.2 / 7.3 — 타입만 뗀다
> /** @var string */ private $api;
> /** @var string */ private $serviceId;
> ```
>
> 메서드의 파라미터·반환 타입(`string`, `?array`, `: array`)과 `declare(strict_types=1)`,
> null 병합 `??`은 **7.2에서도 정상 동작하므로 고칠 필요가 없습니다.**

```php
<?php
declare(strict_types=1);

/**
 * ulogin 연동 최소 클라이언트 (cURL 사용, 외부 라이브러리 없음)
 *
 * 이 예제 파일 자체의 요구 버전: PHP 7.4+ (타입 프로퍼티 사용).
 * PHP 7.2 / 7.3 에서 쓰려면 아래 두 프로퍼티 선언의 타입만 떼면 된다.
 * ※ ulogin 서버의 요구 버전은 PHP 7.2+ 이고, 이 제약과 무관하다.
 */
final class UloginClient
{
    private string $api;
    private string $serviceId;

    public function __construct(string $installRoot, string $serviceId)
    {
        // $installRoot 예: 'https://example.com/ulogin'
        $this->api       = rtrim($installRoot, '/') . '/server/api';
        $this->serviceId = $serviceId;
    }

    /**
     * @return array 응답 봉투의 data 부분
     * @throws RuntimeException 실패 시. 메시지는 "[CODE] 사람이 읽는 문구"
     */
    private function call(string $path, string $method = 'GET', ?array $body = null, ?string $token = null): array
    {
        $ch = curl_init($this->api . $path);
        $headers = ['Accept: application/json'];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POSTFIELDS     => $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE),
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('[NETWORK_ERROR] ulogin 서버에 연결할 수 없습니다: ' . $err);
        }
        curl_close($ch);

        $json = json_decode((string) $raw, true);
        if (!is_array($json)) {
            throw new RuntimeException('[SERVER_ERROR] ulogin 응답을 해석할 수 없습니다.');
        }
        if (($json['ok'] ?? false) !== true) {
            $code = $json['error']['code'] ?? 'SERVER_ERROR';
            $msg  = $json['error']['message'] ?? '요청에 실패했습니다.';
            throw new RuntimeException('[' . $code . '] ' . $msg);
        }
        return is_array($json['data'] ?? null) ? $json['data'] : [];
    }

    /** 직접 로그인. 반환값에 access_token / refresh_token / user / payload 가 들어 있다. */
    public function login(string $username, string $password): array
    {
        return $this->call('/auth/login.php', 'POST', [
            'username'     => $username,
            'password'     => $password,
            'service_id'   => $this->serviceId,
            'device_label' => 'php-server',
        ]);
    }

    /** 세션 재검증 + payload 재수신 */
    public function session(string $accessToken): array
    {
        return $this->call('/auth/session.php', 'GET', null, $accessToken);
    }

    /** 토큰 회전 */
    public function refresh(string $refreshToken): array
    {
        return $this->call('/auth/refresh.php', 'POST', ['refresh_token' => $refreshToken]);
    }

    /** 포인트 차감 */
    public function consume(string $accessToken, int $amount, string $idempotencyKey, array $opt = []): array
    {
        return $this->call('/points/consume.php', 'POST', [
            'amount'          => $amount,
            'reason'          => $opt['reason'] ?? 'usage',
            'service_id'      => $this->serviceId,
            'ref_type'        => $opt['ref_type'] ?? null,
            'ref_id'          => $opt['ref_id'] ?? null,
            'memo'            => $opt['memo'] ?? null,
            'idempotency_key' => $idempotencyKey,
        ], $accessToken);
    }

    /** 로그아웃. 이미 만료된 세션이어도 예외를 던지지 않는다. */
    public function logout(string $accessToken, bool $allDevices = false): array
    {
        try {
            return $this->call('/auth/logout.php', 'POST', ['all_devices' => $allDevices], $accessToken);
        } catch (RuntimeException $e) {
            if (strpos($e->getMessage(), '[AUTH_REQUIRED]') === 0
                || strpos($e->getMessage(), '[AUTH_SESSION_EXPIRED]') === 0) {
                return ['revoked_count' => 0, 'all_devices' => $allDevices];
            }
            throw $e;
        }
    }
}

// ---- 사용 예 ----
$ulogin = new UloginClient('<<설치루트>>', '<<서비스ID>>');

try {
    $auth = $ulogin->login(getenv('ULOGIN_USERNAME'), getenv('ULOGIN_PASSWORD'));

    // payload 는 변수에만 둔다. 세션·파일·DB·로그에 쓰지 않는다.
    $apiKey  = $auth['payload']['ai_api_key'] ?? null;
    $apiBase = $auth['payload']['api_base'] ?? 'https://api.openai.com/v1';
    $model   = $auth['payload']['model'] ?? 'gpt-4o-mini';

    if ($apiKey === null) {
        throw new RuntimeException('AI 키를 받지 못했습니다. 등급 또는 서비스 가입 상태를 확인하세요.');
    }

    // 멱등키는 작업 1건당 1개. 재시도해도 같은 값을 쓴다.
    $idemKey = bin2hex(random_bytes(16));
    $ulogin->consume($auth['access_token'], 10, $idemKey, ['ref_type' => 'batch', 'ref_id' => 'job-001']);

    echo "AI 키 수신 완료. 잔액: " . $auth['user']['points'] . "P\n";
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
```

---

## 13. 연동 완료 체크리스트 — 15항목

각 항목의 "어떻게 확인하는가"를 그대로 실행해 보세요. 15개가 전부 통과하면 연동이 끝난 것입니다.

> **v1.4.0 이상 서버라면 0번을 먼저 하세요.** 서버가 살아 있고 스키마가 최신인지를
> 한 번에 봅니다 — 아래 15개 중 여럿이 그 두 가지 때문에 실패합니다.
>
> ```bash
> curl -sS -o /dev/null -w '%{http_code}\n' '<<설치루트>>/server/api/health.php'
> ```
>
> `200`이면 정상, `503`이면 그 서버의 DB가 죽은 것이고, `404`면 v1.3.0 이하 배포본입니다
> (그 경우 이 0번을 건너뛰고 아래 2번을 쓰세요). **인증이 필요 없습니다.**

| # | 항목 | 어떻게 확인하는가 | 통과 기준 |
|---|---|---|---|
| 1 | 설치 루트가 맞다 | 브라우저로 `<<설치루트>>/public/login.html`을 연다 | 로그인 화면이 뜬다. 404가 아니다 |
| 2 | API 경로가 맞다 | `curl -s '<<설치루트>>/server/api/auth/csrf.php'` | `{"ok":true,"data":{"csrf_token":"…","expires_in":…},"error":null}` 가 나온다. HTML이 나오면 경로가 틀린 것이다 |
| 3 | `service_id`가 등록되어 있다 | `curl -s '<<설치루트>>/server/api/sso/validate.php?service_id=<<서비스ID>>&redirect_uri=<복귀주소를 URL인코딩>'` | `"redirect_uri_valid": true` |
| 4 | `redirect_uri`가 화이트리스트에 있다 | 위와 같은 명령 | `SSO_INVALID_REDIRECT`가 나오지 않는다 |
| 5 | CORS `allowed_origins`가 등록되어 있다 | 앱 페이지에서 `fetch('<<설치루트>>/server/api/auth/csrf.php')` 실행 후 개발자도구 콘솔 확인 | CORS 오류가 없고, 네트워크 탭 응답 헤더에 `Access-Control-Allow-Origin`이 내 origin으로 있다 |
| 6 | 로그인 → 복귀가 된다 | 앱에서 로그인 버튼 클릭 → ulogin 화면에서 로그인 | 콜백 페이지를 거쳐 앱으로 돌아오고 URL에 `code`/`state`가 남아 있지 않다 |
| 7 | `state` 검증이 동작한다 | 콜백 URL의 `state` 값을 임의로 한 글자 바꿔 다시 연다 | 앱이 교환하지 않고 "보안 검증 실패"를 표시한다 |
| 8 | 토큰이 저장된다 | 개발자도구 → Application → Local Storage 확인 | `access_token` / `refresh_token` / `expires_at`이 있다 |
| 9 | **`payload`가 저장되어 있지 않다** | 같은 화면에서 `ai_api_key` 문자열을 검색한다 (Local Storage / Session Storage / IndexedDB / Cookies 전부) | **아무 곳에도 없다.** 하나라도 있으면 8-6절의 저장 금지 규칙 위반이다 |
| 10 | `payload`에 키가 온다 | 앱에서 `GET /server/api/auth/session.php` 응답을 콘솔에 찍는다 | `data.payload.ai_api_key`가 문자열로 있다 |
| 11 | 키 부재 분기가 동작한다 | 관리자 콘솔에서 그 시크릿의 `min_billing_tier`를 `enterprise`로 올리고 앱을 새로고침 | 앱이 에러로 죽지 않고 "AI 키를 받지 못했습니다" 안내를 표시한다. **확인 후 원래 값으로 되돌린다** |
| 12 | 등급 비교에 `*_rank`를 쓴다 | 앱 소스에서 `billing_tier >`, `role >`, `account_level >` 를 검색한다 | 문자열 비교가 **0건**이다. 전부 `*_rank` 정수 비교다 |
| 13 | 포인트 차감이 동작하고 멱등하다 | 같은 `idempotency_key`로 차감을 두 번 호출한다 | 두 번째 응답에 `"idempotent_replay": true`가 오고 잔액이 한 번만 줄어든다 |
| 14 | 토큰 갱신이 동작한다 | Local Storage의 `expires_at`을 `0`으로 바꾸고 앱을 새로고침 | 자동으로 `refresh`가 돌고 로그인 상태가 유지된다. `refresh_token` 값이 새 값으로 바뀌어 있다 |
| 15 | 세션 만료 처리가 동작한다 | Local Storage의 `access_token`과 `refresh_token`을 둘 다 지우고 앱을 새로고침 | 앱이 에러로 죽지 않고 로그인 화면(또는 로그인 버튼)으로 되돌아간다 |

### 13-1. 관리자 쪽 확인 6항목 (관리자 권한이 있을 때)

**뒤 3항목은 v1.4.0에서 추가됐습니다.** v1.3.0 이하 서버에서는 앞 3항목만 확인하세요 —
「시크릿 전달 로그」 서브탭과 헬스체크가 그 배포본에는 없습니다.

| 항목 | 확인 방법 | 통과 기준 |
|---|---|---|
| 시크릿에 게이트가 걸려 있다 | 관리자 콘솔 [시크릿] 탭에서 민감 키의 `min_billing_tier` 열 확인 | `lite` 이상이 걸려 있다. 비어 있거나 `free`면 전원 공개 상태다 |
| 로그인 로그가 남는다 | 관리자 콘솔 [로그] 탭 → 로그인 로그 | 방금 로그인이 `success`로 **1건만** 있다. SSO 로그인 1회에 2건이 남으면 안 된다 |
| 사용자가 서비스에 가입되어 있다 | 관리자 콘솔 [회원] 탭 → 대상 회원 → `service_overrides` | 그 `service_id` 행이 있고 `status`가 `active`다 |
| **서비스 자체가 `활성`이다** (v1.4.0) | 관리자 콘솔 [서비스] 탭에서 그 `service_id`의 상태 | `활성`이다. **`비활성`이면 v1.4.0부터 `payload`가 `{}`가 된다** — 15-6절 0) |
| **스키마가 최신이다** (v1.4.0) | `curl -sS '<<설치루트>>/server/api/health.php' -H 'Authorization: Bearer <<관리자ACCESS_TOKEN>>'` | `data.checks` 중 `schema`가 `ok:true`, `data.schema.pending`이 `[]`. **비어 있지 않으면 운영자가 `server/migrate.php`를 돌리지 않은 것이다** |
| **시크릿 전달 로그가 남는다** (v1.4.0) | 관리자 콘솔 [로그] 탭 → 「시크릿 전달 로그」 서브탭 | 방금 `payload`를 받은 기록이 있다. **한 줄 = 한 번이 아니다** — 줄은 `(세션, 출처, 1시간)` 단위이고 횟수는 `hits` 열이다 |

---

## 14. 함정 목록 — 실제로 걸리는 것들

### 14-1. URL과 배포 구조

**(1) `.php`를 빼면 404가 납니다.**
이 구현은 `mod_rewrite` 없이 도는 것을 전제로 만들어졌습니다. `.htaccess`의 rewrite가 살아 있으면 확장자를 생략할 수 있지만, 공용 호스팅에서는 `AllowOverride None`이거나 `mod_rewrite`가 없는 경우가 흔합니다. **항상 `.php`를 붙이세요.**

**(2) 경로 세그먼트는 존재하지 않습니다.**
`/api/admin/users/3f1b…` 같은 경로는 이 서버에 없습니다. `/server/api/admin/user_detail.php?uuid=3f1b…`처럼 **쿼리 파라미터**를 쓰세요. 계약서 문서에 논리 경로가 적혀 있어도 실제 호출은 이 형태입니다.

**(3) 파일명이 논리 경로와 다른 곳이 5군데 있습니다.**

| 논리 경로 | 실제 파일 |
|---|---|
| `/api/me` | `/server/api/me/index.php` |
| `/api/me/login-logs` | `/server/api/me/login_logs.php` |
| `/api/auth/password/forgot` | `/server/api/auth/password_forgot.php` |
| `/api/auth/password/reset` | `/server/api/auth/password_reset.php` |
| `/api/auth/email/verify` · `/api/auth/email/resend` | `/server/api/auth/email_verify.php` · `email_resend.php` |

**(4) 설치 루트를 잘못 잡으면 전부 404입니다.**
`<<설치루트>>`는 `server/`와 `public/`의 **부모**입니다. `https://example.com/ulogin/public`을 설치 루트로 넣으면 API 경로가 `https://example.com/ulogin/public/server/api/…`가 되어 전부 404입니다.

**(5) `ulogin-client.js`의 `server` 옵션도 설치 루트 하나입니다.**
`apiBase`나 `loginUrl`을 따로 넣지 마세요. 내부에서 `server + '/server'`와 `server + '/public/login.html'`을 파생합니다. 두 값을 따로 넣으면 반드시 어긋납니다.

### 14-2. CORS와 로컬 실행

**(6) `file://`에서는 CORS가 통과하지 않습니다.**
`file:///C:/…/app.html`로 열면 Origin이 `null`이라 서버가 `Access-Control-Allow-Origin` 헤더를 붙이지 않습니다. 로컬 웹서버를 쓰세요:

```bash
python -m http.server 5173
```

**(7) `python -m http.server`는 PHP를 실행하지 못합니다.**
이 명령은 *당신의 앱 파일*을 HTTP로 서빙할 뿐입니다. ulogin 서버 자체는 PHP가 도는 호스팅에 올라가 있어야 합니다. 이걸로 ulogin을 열려고 하면 PHP 소스가 그대로 다운로드됩니다.

**(8) `allowed_origins`에 경로를 넣으면 등록이 거부됩니다.**
`http://localhost:5173/app.html`은 안 됩니다. `http://localhost:5173`만 넣으세요. 반대로 `redirect_whitelist`에는 **경로까지 정확히** 넣어야 합니다.

**(9) `localhost`와 `127.0.0.1`은 다릅니다.**
화이트리스트 비교는 호스트 문자열 완전 일치입니다. 앱이 `http://localhost:5173`에서 도는데 `http://127.0.0.1:5173`만 등록되어 있으면 실패합니다. 둘 다 등록하거나 하나로 통일하세요.

### 14-3. PKCE와 state

**(10) `file://`에서는 `crypto.subtle`이 없어 PKCE를 만들 수 없습니다.**
`crypto.subtle`은 secure context(HTTPS 또는 `http://localhost`)에서만 존재합니다. 서버가 PKCE를 강제하지 않으므로 없이도 로그인은 되지만, 어차피 (6)의 CORS 문제로 `file://`은 못 씁니다.

**(11) `state`를 검증하지 않으면 세션 주입에 뚫립니다.**
공격자가 자기 계정의 `code`가 붙은 링크를 피해자에게 열게 하면, 피해자의 앱이 공격자 계정으로 로그인됩니다. **반드시 `sessionStorage`에 저장한 값과 대조하고, 불일치면 교환하지 마세요.**

**(12) `code`는 60초·1회용입니다.**
콜백 페이지에서 사용자가 뭔가를 확인하게 만들지 말고 **즉시 교환**하세요. React StrictMode처럼 `useEffect`가 두 번 도는 환경에서는 두 번째 교환이 `SSO_CODE_INVALID`가 됩니다.

### 14-4. payload와 시크릿

**(13) 미가입자는 payload가 빈 객체입니다.**
`auto_join = 0`인 서비스에서 아직 가입하지 않은 사용자는 **로그인은 성공하고 payload만 `{}`**입니다. 에러가 없어서 원인을 찾기 어렵습니다. `GET /server/api/me/services.php`로 가입 여부를 확인하세요.

**(14) 등급 미달 키는 조용히 빠집니다.**
에러가 아니라 그 키가 응답에서 없어집니다. **반드시 `if (!payload.ai_api_key)` 분기를 두세요.** 없으면 `undefined`를 AI API에 보내 원인 불명의 401을 맞습니다.

**(15) 게이트 없는 민감 키는 가입자 전원에게 공개됩니다.**
`min_*`가 전부 비어 있으면 등급 검사를 건너뜁니다. 랭크 0 값(`free`/`guest`/`user`)도 게이트로 인정되지 않습니다. 서버가 민감한 이름의 키에 대해 이것을 막지만, 이름이 민감 판정에 걸리지 않는 키(예: `openai_credentials_v2` — `credentials`로 끝나지 않고 `_v2`로 끝남)는 통과할 수 있으니 직접 확인하세요.

**(16) 게이트 없는 민감 시크릿은 관리자 콘솔로 만들 수 없습니다.**
콘솔에 `allow_public` 컨트롤이 아예 없습니다. 의도한 공개라면 API로 `allow_public: true`를 보내야 합니다. 이것은 버그가 아니라 의도적인 마찰입니다.

**(17) `payload`를 저장하면 서버를 분리한 의미가 사라집니다.**
`localStorage`에 넣으면 만료가 없고, XSS 하나로 유출되고, 개발자도구에서 평문으로 보입니다. **메모리에만 두고 새로고침할 때마다 `GET /server/api/auth/session.php`로 다시 받으세요.**

**(18) `refresh` 후 `payload`를 병합하지 말고 통째로 교체하세요.**
등급이 내려가서 빠져야 할 키가 병합 때문에 남으면, 폐기된 키로 계속 호출하게 됩니다.

**(19) `GET /api/me`에는 `payload`가 없습니다.**
시크릿을 받는 경로는 `login` / `exchange` / `refresh` / `session` **4곳뿐**입니다.

### 14-5. 등급과 권한

**(20) 등급을 문자열로 비교하면 뒤집힙니다.**
`'vip' > 'staff'`는 `true`이고 `'free' > 'enterprise'`도 `true`입니다. 사전순 비교이기 때문입니다. **반드시 `account_level_rank` / `role_rank` / `billing_tier_rank` 정수로 비교하세요.**

**(21) `staff`의 랭크는 90입니다.**
`vip`(30) 다음이 90입니다. 등급 사이를 균등하게 가정하고 계산하지 마세요.

**(22) `billing_tier`는 만료되면 응답에서 `free`로 보입니다.**
DB 값은 그대로지만 API가 판정 시점에 강등해서 반환합니다. `billing_active`가 `false`면 만료된 것입니다. 앱은 이 값을 그대로 믿으면 됩니다.

**(23) 관리자 API 권한은 전역 `role`만 봅니다.**
서비스별 `role` 오버라이드는 로그인 서버의 관리 권한이 되지 않습니다. 반대로 `user` 객체의 `role`/`role_rank`는 **effective 값**(오버라이드 반영)이므로, 관리자 메뉴 노출 조건으로 쓰면 서비스 컨텍스트에 따라 어긋날 수 있습니다.

**(24) 자동 가입은 등급을 올리기만 합니다.**
서비스의 `default_account_level`은 "최소 보장 등급"입니다. 전역 등급이 더 높으면 오버라이드가 기록되지 않습니다. 강등되지 않습니다.

### 14-6. 응답 형태

**(25) 목록 응답은 배열이 아닙니다.**
`{"items":[…],"total":…,"page":…,"per_page":…}` 객체입니다. `data.map(...)`이 아니라 `data.items.map(...)`입니다.

**(26) 봉투를 벗기는 것을 잊지 마세요.**
`json.data.user`이지 `json.user`가 아닙니다. 성공 여부는 HTTP 상태가 아니라 `json.ok`로도 판단할 수 있습니다(둘 다 일관됩니다).

**(27) 빈 맵은 `{}`이고 `null`이나 `[]`가 아닙니다.**
`payload`, `meta`, `filters_applied`가 그렇습니다. `Object.keys(payload).length === 0`으로 비었는지 확인하세요.

**(28) `error.field`는 값이 없어도 키가 존재합니다.**
`'field' in error` 검사는 항상 참입니다. 값이 `null`인지 보세요.

**(29) `session.auth_type`은 항상 `"token"`입니다.**
v1.0.0에서 `"cookie"`가 나오지 않습니다. 이 필드로 분기하지 마세요.

**(30) `expires_in`의 의미가 엔드포인트마다 다릅니다.**
`login`/`exchange`/`refresh`는 **발급 TTL**, `GET /api/auth/session.php`는 **남은 초**입니다. 둘 다 `Date.now() + expires_in * 1000`으로 계산하면 되므로 실무상 문제는 없지만, 값의 크기만 보고 판단하지 마세요.

### 14-7. 토큰과 세션

**(31) `refresh_token`은 1회용이고 재사용하면 전체 세션이 회수됩니다.**
갱신 요청이 동시에 여러 개 나가면 그중 하나는 이미 폐기된 토큰을 쓰게 되고, 서버가 이것을 탈취로 판단해 **그 사용자의 모든 세션을 끊습니다.** 진행 중인 갱신 Promise를 재사용해 하나로 합치세요.

**(32) `session_id`로는 인증할 수 없습니다.**
`session_id`는 공개 식별자입니다. 쿠키나 `Authorization` 헤더에 넣으면 401이 납니다. 인증에는 `access_token`을 쓰세요.

**(33) 비밀번호를 바꾸면 그 이전에 발급된 세션이 전부 끊깁니다.**
`PUT /api/me/password.php`로 본인이 바꾸는 경우에는 **현재 세션만 살아남습니다.** 관리자가 초기화하거나 재설정 링크로 바꾸면 **전부 끊깁니다.**

**(34) 로그아웃 시 401도 성공으로 처리하세요.**
이미 만료된 토큰으로 로그아웃하면 401이 옵니다. 결과적으로 세션이 없으므로 목적은 달성된 것입니다. 로컬 토큰만 지우면 됩니다.

**(35) `Authorization` 헤더가 전달되지 않는 호스팅이 있습니다.**
CGI 모드 PHP에서 흔합니다. 그럴 때는 `X-Auth-Token: <<ACCESS_TOKEN>>` 헤더를 대신 쓰세요. 서버가 두 헤더를 모두 읽습니다.

**(36) Bearer를 쓰면 CSRF 토큰이 필요 없습니다.**
`X-CSRF-Token`은 쿠키 세션 UI 전용입니다. Bearer로 인증하면 서버가 CSRF 검사를 건너뜁니다.

### 14-8. 시각과 인코딩

**(37) 모든 시각은 UTC입니다.**
`"2026-08-26T04:12:00Z"` 형식입니다. 한국 시각으로 보면 9시간 차이가 나는 것이 **정상**입니다. 표시할 때만 변환하세요.

**(38) ISO8601 문자열에서 `Z`를 떼지 마세요.**
`Z`가 없으면 브라우저가 로컬 시각으로 해석해 최대 하루까지 어긋납니다. `new Date(iso)`에 원본 문자열을 그대로 넣으세요.

**(39) 아이디와 이메일은 소문자로 정규화됩니다.**
`Hong@Example.com`으로 가입해도 `hong@example.com`으로 저장됩니다. 로그인할 때도 대소문자를 가리지 않습니다.

### 14-9. 서버 설정 (관리자에게 전달할 내용)

**(40) `BASE_URL`이 비어 있으면 두 기능이 조용히 멈춥니다.**
`server/config.php`의 `BASE_URL`이 비었거나 `__CHANGE_ME__` 같은 자리표시자면:
- **메일 발송이 전면 중단됩니다** — 비밀번호 찾기와 이메일 인증이 "보냈습니다" 응답만 주고 실제로는 아무것도 보내지 않습니다.
- **`FORCE_HTTPS` 리다이렉트가 500으로 멈춥니다** — HTTP로 접근하면 리다이렉트 대신 `SERVER_ERROR`가 납니다.

둘 다 "에러 메시지 없이 안 되는" 형태라 원인을 찾기 어렵습니다. `BASE_URL`은 **설치 루트의 절대 URL**(예: `https://example.com/ulogin`)이어야 합니다.

**(41) `TRUST_PROXY`의 기본값은 `false`입니다.**
리버스 프록시나 CDN 뒤에 있지 않으면 그대로 두세요. 켜면 클라이언트가 `X-Forwarded-Proto`와 `X-Forwarded-For`를 위조해 HTTPS 강제와 레이트리밋을 우회할 수 있습니다. 켤 때는 프록시가 그 헤더들을 **반드시 덮어쓰도록** 설정되어 있어야 하고, `TRUSTED_PROXIES`에 프록시 IP를 넣어야 합니다.
**두 상수는 짝입니다 — `TRUST_PROXY=true`인데 `TRUSTED_PROXIES`가 빈 목록이면 v1.1.0은 그것을 설정 오류로 보고 그 요청을 500으로 멈춥니다**(리더 판정 D-67. 로그 검색어: `TRUST_PROXY is on but TRUSTED_PROXIES is empty`). 발동 조건은 `FORCE_HTTPS=true`(기본값)이고 서버가 그 요청을 https로 인식하지 못한 경우입니다 — 실제 https 요청은 이 판정에 도달하지 않습니다. 이 500은 쿠키와 무관하므로 **Bearer 요청까지 함께 500이 됩니다** — 아래 **(47)** 의 구분 표를 보세요.

**(42) `service_id`는 생성 후 변경할 수 없습니다.**
세션·시크릿·가입 매핑·로그가 전부 이 문자열 키를 참조합니다. 서버가 변경 요청을 거부합니다. 이름을 바꿔야 하면 **새로 만들고 기존 것을 `disabled`로 내리세요.**

**(43) 메일은 기본적으로 파일로 저장됩니다.**
`mail_driver`의 기본값이 `log`입니다. 실제 발송 대신 `server/storage/mail/` 아래에 파일로 쌓입니다. 파일 확장자는 `.txt`가 아니라 **`.php`**이고 첫 줄에 `<?php exit; ?>`가 들어 있어 직접 열어도 내용이 나오지 않습니다. **그 파일들에는 비밀번호 재설정 링크와 임시 비밀번호가 평문으로 들어 있으므로**, 실 서비스로 전환할 때는 `mail_driver`를 `mail`로 바꾸고 기존 파일을 정리해야 합니다.

**(44) `server/core/*.php`는 직접 실행할 수 없습니다.**
브라우저로 `<<설치루트>>/server/core/db.php`를 열면 404가 납니다. `bootstrap.php`가 `ULOGIN_BOOTSTRAPPED` 상수를 정의하고, 나머지 `core/*.php`가 그 상수 없이는 종료하기 때문입니다. `server/api/**`에는 이 가드가 없습니다(직접 호출이 정상 경로이기 때문).

**(45) `install.php`는 업로드 직후 즉시 실행하고 삭제해야 합니다.**
업로드 시점부터 설치 완료까지 **누구나 접속해 관리자 계정을 만들 수 있습니다.** 업로드 → 즉시 설치 → `install.php` 삭제를 하나의 연속 작업으로 하세요.

**(46) PHP 7.2 호스팅의 쿠키 속성 — 정상 적용되지만 배포 후 1회 확인하세요.**
ulogin 서버는 PHP **7.2**부터 동작합니다(최소 요구 버전 7.2.0). 그런데 `setcookie()`의 **옵션 배열 형식은 PHP 7.3부터만** 동작합니다. 7.2에서 옵션 배열을 넘기면 그것이 만료 시각 인자로 정수 캐스팅되어 **`SameSite`가 통째로 사라지고 `HttpOnly`·`Secure`·`path`도 기본값으로 떨어집니다.** 오류 하나 없이 조용히 그렇게 됩니다.

**v1.1.0은 이 지점을 막았습니다.** `send_cookie()` 래퍼가 `PHP_VERSION_ID`로 분기해서 7.3 이상에서는 `setcookie()` 옵션 배열을 쓰고, **7.2에서는 `Set-Cookie` 헤더를 직접 조립합니다.** 두 분기가 같은 헤더 문자열을 만드는지 16개 케이스로 바이트 단위 대조해 전부 일치했습니다. **그러므로 7.2 호스팅에서도 `HttpOnly`·`Secure`·`SameSite=Lax`가 정상 적용됩니다.**

**그래도 배포 후 한 번은 눈으로 확인하세요.** 개발 환경에 PHP 7.2 바이너리가 없어 7.2 인터프리터 자체로는 실행하지 못했고(정적 스캔으로만 커버), 조용히 깨질 수 있었던 유일한 지점이기 때문입니다. 확인 비용은 30초입니다.

브라우저 개발자도구(`F12`) → **Network** 탭 → ulogin 로그인 화면에서 **로그인 버튼 클릭** → 목록에서 **`login.php`** 요청 선택 → **Response Headers** 의 **`Set-Cookie`** 를 봅니다. 아래 3개가 모두 붙어 있어야 정상입니다.

```
Set-Cookie: ULOGIN_SID=3f9a…c1; path=/; secure; HttpOnly; SameSite=Lax
                                        ^^^^^^  ^^^^^^^^  ^^^^^^^^^^^^^
```

`curl`로도 확인할 수 있습니다.

```bash
curl -s -i -X POST '<<설치루트>>/server/api/auth/login.php' \
  -H 'Content-Type: application/json' \
  -d '{"username":"<<아이디>>","password":"<<비밀번호>>","service_id":"<<서비스ID>>"}' \
  | grep -i '^set-cookie'
# 기대: Set-Cookie: ULOGIN_SID=...; path=/; secure; HttpOnly; SameSite=Lax
#  (HTTP 로 서비스하는 서버라면 secure 는 없습니다 — 그건 COOKIE_SECURE=false 설정 때문이며 정상입니다)
```

하나라도 없으면 순서대로 확인합니다: **① `server/config.php`의 `COOKIE_SECURE`** (`Secure`만 없을 때) → **② HTTPS로 서비스하는지** → **③ 실제 실행 중인 PHP 버전** → **④ 배포된 파일이 v1.1.0인지** (`server/core/request.php`에 `send_cookie` 문자열이 있어야 합니다. 없으면 v1.0.0 파일이 올라간 것입니다).

> **이것은 ulogin 자체 웹 UI(쿠키 세션)의 문제이고, Bearer 토큰으로 연동하는 당신 앱의 코드에는 영향이 없습니다.** 당신 앱은 `Authorization: Bearer` 헤더를 쓰므로 쿠키를 쓰지 않습니다. 그래도 사용자가 SSO 로그인을 하려면 ulogin 로그인 화면을 거치고, 그 화면의 세션이 쿠키로 유지되므로 **연동 전체의 보안 전제에 포함됩니다.** 관리자에게 이 확인을 전달하세요.

**(47) 쿠키를 내보내는 요청만 500이라면 서버 config 문제이고, 당신의 요청 문제가 아닙니다.**

v1.1.0은 **쿠키 설정이 모순인 상태를 조용히 넘기지 않고 500으로 멈춥니다**(리더 판정 D-75).
`Secure` 속성이 붙은 쿠키는 브라우저가 평문 HTTP 연결에서 **저장하지 않으므로**, 그대로 두면
"로그인 API는 200인데 다음 요청에 세션이 없는" 조용한 실패가 됩니다. 그래서 서버는
쿠키를 발급하려는 그 요청을 500으로 끊고 오류 로그에 사유를 남깁니다.

**판정이 전역(`bootstrap.php`)이 아니라 쿠키 발급 함수(`send_cookie()`) 안에 있습니다.**
그래서 **쿠키를 실제로 발급하거나 삭제하는 요청만** 500이 됩니다. 그 경계는 인증 방식이 아니라
**"그 엔드포인트가 `Set-Cookie`를 내보내는가"** 입니다 — Bearer로 호출해도 쿠키를 지우는
엔드포인트는 500이 됩니다. 아래 표가 그 경계이고, 각 행은 서버 코드에서 직접 확인한 것입니다.

| Bearer로 호출하는 경로 | 모순 설정에서 |
|---|---|
| **SSO 로그인 전체** — `POST /api/sso/authorize.php` → `POST /api/auth/exchange.php` | **영향 없음.** `exchange.php`는 쿠키를 심지 않습니다(`set_cookie => false` — 로컬 앱은 Bearer를 쓰므로 의도적입니다) |
| `GET /api/me` · `GET /api/me/sessions` · `GET /api/me/points` 등 **조회 전반** | **200** (실측 확인) |
| `POST /api/auth/refresh.php` | **영향 없음.** 요청에 세션 쿠키가 묶여 있지 않으면 쿠키를 발급하지 않습니다 |
| `POST /api/points/consume.php`, `PUT /api/me/*` 등 | **영향 없음** (쿠키를 내보내지 않습니다) |
| `GET /api/auth/csrf.php` | **500.** Bearer 연동에서는 부를 필요가 없습니다 (아래 1번) |
| **`POST /api/auth/logout.php`** | **500.** 이 엔드포인트는 인증 방식과 무관하게 쿠키 삭제를 시도합니다 (아래 3번을 꼭 읽으세요) |
| **`POST /api/me/withdraw.php`** | **500.** 같은 이유입니다 (아래 3번) |
| **`DELETE /api/me/sessions.php?session_id=`** — 지우는 세션이 **지금 쓰고 있는 세션일 때만** | **500.** 다른 기기의 세션을 지우는 것은 영향 없습니다 |

실측 근거(모순 설정 그대로 측정): `api/**` 32개 파일 × GET+POST = **64요청** 중 5xx는
`GET /api/auth/csrf.php` **1건**뿐이었고, `Authorization: Bearer`로 호출한 `GET /api/me` ·
`/api/me/sessions` · `/api/me/points`는 **전부 200**, 그 3요청 동안 서버 오류 로그는 **0줄**이었습니다.
(그 스윕은 미인증 요청이었으므로 `logout`·`withdraw`는 쿠키 삭제에 도달하기 전에 `AUTH_REQUIRED`로
끝났습니다. 위 표의 그 세 행은 **측정값이 아니라 코드 경로**로 확인한 것입니다.)

**그래서 당신이 할 일:**

1. **`csrf.php`를 부르지 마세요.** CSRF 토큰은 **쿠키 세션 전용**입니다. `Authorization: Bearer`로
   호출하면 서버가 CSRF를 검사하지 않습니다(함정 **(36)**). Bearer 전용 연동에서 이 엔드포인트는
   **필요 없습니다.**
2. **서버 도달 확인에 `csrf.php`를 쓰지 마세요.** 15-17절의 진단 스크립트 1단계가 이 엔드포인트를
   쓰므로, 이 상태에서는 서버가 살아 있는데도 `서버 도달 : NG - SERVER_ERROR`로 나옵니다.
   `GET /server/api/sso/validate.php?service_id=…&redirect_uri=…`로 대신 확인하세요 —
   쿠키를 발급하지 않으므로 이 모순의 영향을 받지 않습니다.
3. **`logout`·`withdraw`가 500이어도 DB 상태 변경(세션 회수·탈퇴)은 완료됩니다.** 두 엔드포인트는
   **세션 회수(그리고 탈퇴 처리)를 DB에 커밋한 뒤** 마지막에 쿠키를 지우려다 500이 됩니다.
   즉 500을 받아도 **세션은 실제로 회수되었습니다.** 함정 **(34)** 와 같은 방침으로 처리하세요 —
   **로컬 토큰을 삭제하고 로그아웃 성공으로 간주하십시오.** `2xx`가 아니라고 재시도하면
   두 번째 호출은 **401**(회수된 토큰이므로)로 옵니다 — 함정 **(34)** 대로 그것도 성공으로
   처리하면 됩니다. 500에 재시도 루프를 걸지 마세요.

   > **다만 완료되지 않는 것이 하나 있습니다 — 감사 로그 1행입니다.**
   > DB 상태 변경(세션 회수·탈퇴)은 완료되지만 **감사 로그 1행이 누락됩니다.**
   > `login_logs`에 `result='logout'`으로 남아야 할 그 1행입니다 — 기록 함수가 쿠키를 지우는
   > 호출의 **다음 줄**에 있어서, 500이 그 앞에서 요청을 끊으면 실행되지 않습니다
   > (`server/api/auth/logout.php` · `server/api/me/withdraw.php` · `server/api/me/sessions.php`
   > 세 곳 모두 같은 순서입니다). 실측: 모순 설정에서 500이면 로그 행이 **3 → 3**(증가 없음),
   > 정상 설정에서 200이면 **3 → 4**입니다(2026-08-26 실측).
   > **당신의 연동 코드가 할 일은 없습니다** — 세션은 회수됐고 재시도하면 401입니다.
   > 영향받는 것은 **서버 운영자**입니다: `login_logs`로 로그아웃을 추적하면 그 사용자에게
   > **로그아웃 기록이 없는 로그인**이 남습니다. 이 상태를 관리자에게 알릴 때 그 사실을 함께 전하세요.
4. **`/api/me`가 200인데 `csrf.php`만 500이면 서버 config 문제입니다.** 당신 코드를 고칠 것이 없습니다.
   아래 문장을 관리자에게 전달하세요.

> **관리자에게 전달할 내용.** 서버 오류 로그에서 다음 문자열을 검색하십시오.
>
> ```
> COOKIE_SECURE is on but the request is not HTTPS
> ```
>
> 있으면 `server/config.php`에서 **`FORCE_HTTPS`와 `COOKIE_SECURE`를 함께** 내리거나(평문 HTTP 운영)
> HTTPS로 서비스하십시오. `COOKIE_SECURE`가 이미 `false`인데 이 로그가 보이면
> **`COOKIE_SAMESITE`가 `'None'`인지 확인하십시오** — `'None'`은 `Secure`를 자동 강제하므로
> HTTPS 전용 값입니다(평문 HTTP에서는 `'Lax'`여야 합니다). `COOKIE_SECURE` 줄 자체가 없는
> 구버전 `config.php`도 같은 이유로 500이 납니다. 절차는 `INSTALL.md` 7-7절·7-21절에 있습니다.

**반대로 `/api/me`까지 포함해 모든 요청이 500이면 원인이 다릅니다.** 그때는 쿠키가 아니라
전역 설정 오류이고, 로그 문장으로 구분합니다.

| 로그에 있는 문장 | 원인 |
|---|---|
| `COOKIE_SECURE is on but the request is not HTTPS` | 위 항목. **쿠키 발급 요청만** 500 |
| `cookie option rejected` | `COOKIE_PATH`·`COOKIE_DOMAIN`에 `,` `;` 공백 등 쿠키 금지 문자. **쿠키 발급 요청만** 500 |
| `TRUST_PROXY is on but TRUSTED_PROXIES is empty` | 프록시 설정이 짝이 맞지 않는다(함정 **(41)**). `FORCE_HTTPS=true`일 때 https로 인식되지 않은 **전 요청** 500 |
| `FORCE_HTTPS is on but BASE_URL is not configured` | `BASE_URL` 미설정(함정 **(40)**). `FORCE_HTTPS=true`일 때 https로 인식되지 않은 **전 요청** 500 |
| `config.php not found. run install.php first` | 설치가 안 됐거나 `config.php`가 읽히지 않는다. **전 요청** 500 |

> **`invalid COOKIE_SAMESITE`는 500이 아닙니다.** `COOKIE_SAMESITE`에 `Lax`·`Strict`·`None` 밖의
> 값이 들어가면 서버가 `Lax`로 강제하고 경고만 남깁니다 — 요청은 **정상 처리(200)** 됩니다.
> 이 문장만 있고 500이 없다면 원인이 다른 곳입니다.

**500 응답 본문에는 원인이 없습니다.** 어느 경우든 `{"ok":false,"data":null,"error":{"code":"SERVER_ERROR",
"message":"일시적인 오류가 발생했습니다.","field":null}}`이고, 이것은 내부 정보를 노출하지 않으려는
의도입니다. **원인은 서버 오류 로그에만 있습니다.**

### 14-10. 이 버전의 알려진 제한

연동 코드를 쓸 때 "왜 안 되지"를 줄이기 위한 목록입니다. **아래는 버그가 아니라 의도적으로 구현하지 않은 것들입니다.**
v1.1.0은 PHP 7.2 호환만, v1.2.0은 배포 반영·캐시 무효화만, v1.3.0은 공개 패키징만 다룬 라운드여서
**v1.3.0까지 이 목록은 v1.0.0에서 하나도 줄지 않았습니다.**

**v1.4.0에서 줄어든 것은 11번 하나입니다** — `fail_ratelimit`이 이제 실제로 기록됩니다.
대신 v1.4.0이 **닫지 않고 다음 버전으로 넘긴 것**을 16~19번으로 더했습니다.
그 넷은 연동 앱이 고칠 수 없지만, **증상을 앱의 버그로 오진단하지 않기 위해** 알아야 합니다.

| # | 제한 |
|---|---|
| 1 | **2FA는 컬럼과 응답 필드만 있고 기능이 없습니다.** `two_factor_enabled`는 항상 `false`입니다 |
| 2 | **서비스별 포인트(`user_services.points`)는 사용하지 않습니다.** 항상 0이고 어디서도 차감되지 않습니다. 포인트는 전역 하나입니다 |
| 3 | **휴면(`dormant`) 자동 전이가 없습니다.** 관리자가 수동으로 설정할 때만 그 상태가 됩니다. 자동 해제도 없습니다 |
| 4 | **`TIER_INSUFFICIENT`는 기본 구성에서 발생하지 않습니다.** `require_tier()` / `can()`은 서비스 연동 측이 등급 게이팅을 붙일 때 쓰라고 남겨 둔 미사용 API입니다 |
| 5 | **`revoke_reason='expired'`는 만들어지지 않는 미사용 열거값입니다** |
| 6 | **하드 삭제 후에도 `point_logs`·`admin_logs`에 삭제된 회원의 내부 숫자 ID가 남습니다.** 두 컬럼이 `NOT NULL`이라 익명화할 수 없습니다 |
| 7 | **관리자 헬퍼 9개가 `function_exists` 가드로 10개 파일에 중복 선언되어 있습니다.** 요청당 파일 1개만 로드되므로 충돌은 없습니다. 다음 버전 정리 과제입니다 |
| 8 | **약관을 등록하기 전에 가입한 회원은 동의 이력 행이 없습니다.** 설치기가 빈 약관을 심지 않기 때문입니다 |
| 9 | **`public/assets/api.js`의 API 베이스가 상대 경로(`'../server'`)입니다.** 그 전제는 "화면은 `public/` 직하에만 둔다"입니다. `public/sub/foo.html` 같은 하위 디렉토리에 화면을 추가하면 경로가 깨집니다. 이것은 ulogin 자체 UI의 제약이며, 당신 앱에는 영향이 없습니다 |
| 10 | **`requires_pkce`는 항상 `false`입니다.** 서비스별로 PKCE를 강제하는 설정이 없습니다. 클라이언트가 보내면 검증하고, 안 보내면 통과시킵니다 |
| 11 | ~~**`login_logs.result`의 `fail_ratelimit`은 실제로 기록되지 않습니다.**~~ → **v1.4.0에서 닫혔습니다.** 자격증명·SSO 경로(`login` · `sso/authorize` · `auth/exchange`)에서 **윈도우당 한도를 처음 넘은 그 한 번만** 기록됩니다. 전역 `api:ip` 버킷에는 붙이지 않았습니다(로그인과 무관한 429가 로그인 이력을 채우면 신호가 다시 묻힙니다). **v1.3.0 이하에서 이 필터가 0건이었던 것은 「공격이 없었다」는 뜻이 아니라 기록하는 코드가 없었다는 뜻입니다 — 과거 데이터를 소급해 채울 방법은 없습니다** |
| 12 | **전 엔드포인트의 MySQL 왕복 e2e 테스트가 수행되지 않았습니다.** v1.1.0에서 실 MariaDB 10.4.32에 DDL 15개를 무수정 적용하고 가입·로그인·`/api/me`·로그아웃·SSO 3단계·PKCE 왕복까지 실제 PDO 경로로 확인했지만, **배포 호스팅의 MySQL 버전과 `sql_mode`는 다를 수 있고** 전 엔드포인트의 기능 e2e는 아닙니다 |
| 13 | **PHP 7.2·7.4 런타임에서의 실행이 확인되지 않았습니다.** 개발 환경이 PHP 8.1뿐이어서 정적 검사로 대체했습니다 — 토크나이저 스캐너(검출 규칙 50종)로 7.3·7.4·8.0+ 전용 문법 **검출 0건**, 7.2에 없는 함수 사용 **0건**을 확인했습니다. 7.2 쿠키 분기는 버전 조건을 뒤집은 사본으로 강제 실행해 헤더 16/16 바이트 동일 + 실 HTTP 왕복까지 확인했습니다. **7.2 인터프리터 자체의 동작 차이는 정적 스캔으로만 커버됩니다** — 함정 **(46)** 의 확인 절차를 배포 후 1회 실행하세요 |
| 14 | **`.htaccess`의 실효성 — Apache 쪽은 v1.2.0 검증 라운드에 확인됐고, nginx·LiteSpeed와 실 호스팅은 여전히 미검증입니다.** 확인된 것: 실 **Apache 2.4.58 + `mod_headers`** 로 `public/.htaccess`가 파싱되어 **보안 헤더 5종 + 캐시 정책 3종이 실제로 응답에 나갑니다.** 확인되지 않은 것: **nginx·LiteSpeed 비호환 모드**(그 환경에서는 이 파일이 통째로 무시되고 오류도 나지 않습니다), 그리고 **실 공용 호스팅의 서버 버전·`AllowOverride` 값·제공자 단 프록시.** **Apache가 됐다는 것이 nginx가 된다는 뜻은 아닙니다.** 배포 후 `<<설치루트>>/server/config.php`와 `<<설치루트>>/server/storage/mail/`을 브라우저로 열어 각각 차단되는지 확인해야 합니다 — 다만 자산 URL의 `?v=`는 이 파일에 의존하지 않습니다(14-11절) |
| 15 | **브라우저 CORS·쿠키 실송출이 확인되지 않았습니다.** 실제 배포 후 확인이 필요합니다 |
| 16 | **`SECRET_ENC_KEY`를 회전하는 경로가 없습니다** (v1.4.0 미해결 · Critical). 운영자가 그 키를 바꾸면 DB의 시크릿을 복호화할 수 없게 되고, **`payload`가 조용히 `{}`가 됩니다.** 에러가 아니라 빈 객체로 나타나므로 앱에서는 8-3절(미가입)과 구분되지 않습니다. **`payload`가 갑자기 비었고 회원·서비스·등급 어느 것도 바뀌지 않았다면 운영자에게 키를 바꿨는지 물어보세요.** v1.9.0 배정 |
| 17 | **서비스·전역 단위 일괄 세션 회수가 없습니다** (v1.4.0 미해결 · High). 그래서 **서비스를 껐다 켜면 탈취된 토큰도 함께 되살아납니다.** v1.4.0은 「끄면 `payload`가 `{}`가 된다」까지만 합니다. 침해 대응으로 서비스를 껐다면 **끈 상태를 유지해야** 하며, 다시 켜는 순간 그 사이 회전하지 않은 토큰도 키를 받습니다. v1.9.0 배정 |
| 18 | **세션에 절대 수명이 없고 refresh 전용 레이트리밋이 없습니다** (v1.4.0 미해결 · High). refresh를 계속 돌리면 세션이 무한히 연장됩니다. 앱 입장에서는 편하지만, **사용자를 주기적으로 다시 로그인시키고 싶다면 앱이 스스로 시각을 재야 합니다** — 서버가 끊어 주지 않습니다. v1.5.0 검토 |
| 19 | **HSTS 헤더가 없습니다** (v1.4.0 미해결 · High). `FORCE_HTTPS`가 켜져 있으면 서버가 301로 올려 보내지만, **첫 요청은 평문으로 한 번 나갑니다.** 앱의 `<<설치루트>>`를 반드시 `https://`로 시작하도록 **하드코딩하세요** — `http://`로 적어 두고 리다이렉트에 기대지 마세요 |

---

### 14-11. 자산 캐시 — 당신의 앱도 같은 문제를 겪습니다

**이 함정은 ulogin 서버만의 문제가 아닙니다.** `ulogin-client.js`를 `<script src>`로 불러 쓰는
**당신의 앱에서 그대로 재현됩니다.** v1.2.0이 이 문제를 닫기 위해 만들어진 라운드입니다.

**(48) `<script src>`에서 `?v=`를 지우지 마세요.**
지우면 브라우저가 `ulogin-client.js`를 **무기한 캐시합니다.** 이 파일에는 캐시 헤더도 버전 쿼리도
없던 시절 실제로 그런 일이 있었습니다 — 서버를 새 버전으로 갱신해도 앱에서는 아무것도 바뀌지
않고, 사용자에게 강제 새로고침(`Ctrl+Shift+R`)을 요구할 수도 없습니다.

```html
<!-- 이렇게 씁니다 ({버전} 은 자리표시자입니다 — 아래 (49)로 실제 값을 구해 바꾸세요) -->
<script src="<<설치루트>>/public/assets/ulogin-client.js?v={버전}"></script>

<!-- 이렇게 쓰지 마세요 -->
<script src="<<설치루트>>/public/assets/ulogin-client.js"></script>
```

**(49) `{버전}`에 넣을 값은 `version.txt` 한 줄에서 구합니다. 서버에 올라간 버전과 맞추세요.**

```bash
curl -sS '<<설치루트>>/public/version.txt'
```

출력은 `{버전}_{YYYYMMDD}` 형식의 한 줄입니다. **아래는 형식을 보이기 위한 예시일 뿐이고,
실제 값은 당신이 연동하는 서버에 올라간 배포본에 따라 다릅니다 — 반드시 위 `curl`로 직접 확인하세요.**

```
1.5.0_20260901
```

`_` 앞부분(위 예시라면 `1.5.0`)이 `?v=`에 넣을 값이고, 날짜까지 포함한 전체가 서버 배포본의 버전 문자열입니다.
**`curl`을 쓰세요 — `curl`은 캐시하지 않습니다.** 브라우저로 볼 때는 시크릿 창에서 열어야
합니다. 이 파일은 서버 쪽에서 `Cache-Control: no-store`로 나가지만, 그 헤더는
`.htaccess`가 무시되는 환경에서 사라질 수 있습니다.

`404`가 나오면 **v1.1.0 이하의 배포본**입니다(이 파일은 v1.2.0에서 신설됐습니다).
그 경우 `?v=`를 붙여도 해가 없습니다 — 서버는 쿼리스트링을 무시하고 같은 파일을 내줍니다.

**(50) `?v=`와 `UloginClient.version`은 서로 다른 것입니다. 같은 값으로 맞추려 하지 마세요.**

| 값 | 무엇을 넣는가 / 무엇이 나오는가 | 무엇인가 |
|---|---|---|
| 자산 URL의 `?v=` | `{버전}` — `version.txt` 첫 줄의 `_` 앞부분 | 브라우저 캐시를 깨기 위한 **URL 파라미터.** 서버 배포 버전과 함께 올라간다 |
| `<<설치루트>>/public/version.txt` | `{버전}_{YYYYMMDD}` 한 줄 — 서버가 내려주는 값 | 서버 배포본의 **버전 문자열.** 반영 확인 수단 |
| `UloginClient.version` | `'1.0.0'` | 클라이언트 라이브러리의 **공개 API 버전.** 실행 코드가 바뀌지 않았으므로 이 값이 정상이다 |

`UloginClient.version`이 `'1.0.0'`인 것을 "옛 파일을 받았다"로 오독하지 마세요.
**그 문자열은 v1.2.0 · v1.3.0 · v1.4.0에서도 `'1.0.0'`입니다.** 판정 근거는 `version.txt`입니다.

**(51) 서버 API 응답이 옛 동작을 보이면 서버 쪽 PHP 캐시(opcache)입니다. 당신이 고칠 수 없습니다.**

증상은 **비대칭으로 나타납니다** — 화면·자산은 새것인데 **API 동작만 그대로**입니다.
한 줄로 판별할 수 있습니다.

```bash
curl -sS -D - -o /dev/null '<<설치루트>>/server/api/me/index.php' | grep -i "cache-control\|pragma\|expires"
```

| 보이는 것 | 판정 |
|---|---|
| `Cache-Control: no-store, no-cache, must-revalidate, max-age=0` + `Pragma: no-cache` + `Expires: 0` | **v1.2.0 이상의 코드가 돌고 있습니다** (v1.3.0의 `server/`는 v1.2.0과 바이트 동일하고 v1.4.0도 이 헤더를 바꾸지 않았습니다 — 이 헤더로는 v1.2.0 이상을 서로 구분할 수 없습니다. 구분은 `version.txt`, 또는 `api/health.php`가 200인지로 합니다) |
| `Cache-Control: no-store` **단독** | **v1.1.0 이하의 PHP 코드가 돌고 있습니다.** 파일은 올라갔는데 서버가 옛 opcode를 실행하는 상태입니다 |

이 요청은 **로그인 없이 보내도 됩니다.** 인증이 없어 `401 AUTH_REQUIRED`가 돌아오지만
확인 대상은 본문이 아니라 헤더입니다(`-o /dev/null`이 본문을 버리고 `-D -`가 헤더만 출력합니다).

**연동 코드가 할 일은 없습니다.** 이것은 서버 관리자가 호스팅 제어판에서 PHP를 재시작하거나
opcache를 비워야 하는 문제입니다. **관리자에게 위 `curl` 출력을 그대로 전달하세요** —
"반영이 안 돼요"보다 이 두 줄이 훨씬 빠르게 해결됩니다.

**(52) 브라우저 강제 새로고침은 진단 수단이지 해결책이 아닙니다.**
`Ctrl+Shift+R`는 **내 브라우저의 캐시만** 무시합니다. 앱을 쓰는 사람 전원에게 요구할 수 없고,
서버 쪽 opcache에는 아예 듣지 않습니다. 강제 새로고침으로만 정상 동작한다면 그것은
**HTML 또는 자산이 캐시되고 있다는 확정 증거**이며, 조치는 `?v=` 값 확인과 서버 쪽 캐시 헤더 설정입니다.

**(53) `?v=`를 올려도 사용자의 브라우저에 도달하지 않는 경우가 하나 있습니다 — 당신 앱의 HTML이 캐시될 때입니다.**

`?v=`가 동작하는 원리는 **새 HTML이 새 자산 URL을 가리키는 것**입니다. 그러므로 전제가 하나
있습니다 — **HTML 자체는 캐시되지 않아야 합니다.** 당신 앱의 HTML이 캐시되면 그 안의 옛 `?v=`가
그대로 굳고, 브라우저는 서버에 묻지도 않고 옛 `ulogin-client.js`를 씁니다.

**다만 깨지는 경로는 좁습니다.** `mod_headers`가 없는 웹서버로 실측한 결과입니다(2026-08-28).

| 사용자가 한 동작 | 앱 HTML에 `no-store`가 있을 때 | 없을 때 |
|---|---|---|
| **일반 새로고침** (`F5` · `Ctrl+R`) | 반영됩니다 | **반영됩니다** |
| **링크를 눌러 다시 들어오기 / 주소창에서 Enter / 북마크** | 반영됩니다 | **지연될 수 있습니다.** HTML이 캐시에서 나오고 그 안의 `?v=`가 옛 값입니다 |

일반 새로고침은 브라우저가 **최상위 문서를 항상 서버에 재검증**하므로 캐시 헤더가 없어도 새 HTML이
옵니다. 링크·주소창 재진입은 그 재검증을 하지 않습니다.
**즉 `?v=`가 무력해지는 경로는 새로고침이 아니라 재진입에 한정됩니다.**

**당신 앱에서 할 수 있는 조치 두 가지입니다.** 첫째가 확실하고 둘째는 보조 수단입니다.

1. **앱을 서비스하는 웹서버에서 HTML에 `Cache-Control: no-store`를 붙입니다.** 확실한 방법입니다
2. 서버 설정을 만질 수 없다면 `<head>`에 아래를 넣습니다. **보조 수단입니다** — 현대 브라우저는
   이 `<meta>`를 무시할 수 있어 보증이 되지 않습니다

```html
<meta http-equiv="Cache-Control" content="no-store">
```

**그리고 사용자 신고를 이렇게 갈라서 받으세요 — 원인과 담당자가 달라집니다.**

| 신고 내용 | 원인 | 누가 고치는가 |
|---|---|---|
| **새로고침해도 안 됩니다** | 앱 HTML의 `?v=` 값 자체가 옛 값이거나, 서버에 새 버전이 안 올라갔거나, 서버 쪽 PHP 캐시입니다 | 앱의 `?v=`는 **당신**이 (49)로 확인해 고칩니다. 서버 쪽이면 **ulogin 관리자**입니다 — `curl '<<설치루트>>/public/version.txt'` 출력을 함께 전달하세요 |
| **새로고침하면 되는데 링크로 들어가면 안 됩니다** | 당신 앱의 HTML이 브라우저 캐시에서 나옵니다 | **당신**입니다 — 위 1·2번 조치 |

> **ulogin 설치본의 `examples/` 데모를 복사해 쓰고 있다면** 그 두 HTML의 `<head>`에는 이미
> 위 `<meta>`가 들어 있습니다. 원본 폴더에는 `.htaccess`도 함께 있지만 **복사본에는 따라오지
> 않습니다** — 그래서 복사본에서는 1번 조치를 당신 쪽 웹서버에서 해야 합니다.

---

## 15. 진단 절차

증상별로 위에서 아래로 실행하세요. 각 단계에 `curl` 명령 전문이 있습니다.

### 15-1. 0단계 — 서버가 살아 있는지

**먼저 버전을 확인하세요.** 이 한 줄이 다음 표들을 읽는 방법을 정합니다.

```bash
curl -sS '<<설치루트>>/public/version.txt'
# 기대 출력: 1.5.0_20260901   ← 이 문서가 대상으로 하는 버전
#   1.4.0_20260901 이면 연동 관점에서 동일합니다 (API 변경 0). ?v= 값만 1.4.0 으로 맞추세요.
#   1.3.0_20260828 · 1.2.0_20260828 이면 문서 머리 「v1.4.0 갱신 고지」 ③ 의 표 4행을 보세요.
#   404 면 v1.1.0 이하입니다 (이 파일은 v1.2.0 신설). 연동 코드는 동일합니다.
```

**v1.4.0 이상이면 헬스체크가 가장 짧은 경로입니다.** 인증이 필요 없습니다.

```bash
curl -s -i '<<설치루트>>/server/api/health.php'
```

| 결과 | 뜻 | 조치 |
|---|---|---|
| `200` + `{"ok":true,"data":{"status":"ok"},"error":null}` | **정상.** PHP도 DB도 살아 있습니다 | 다음 단계로 |
| `503` + `{"ok":false,"data":{"status":"unavailable"},…}` | PHP는 도는데 **DB가 죽었습니다** | 서버 문제입니다. 운영자에게 알리세요 — 앱에서 고칠 것이 없습니다 |
| `404` | **v1.3.0 이하 배포본**이거나 경로가 틀렸습니다 | 아래 `csrf.php` 표로 내려가세요. `version.txt`로 버전을 확인하면 어느 쪽인지 갈립니다 |
| `429` + `RATE_LIMITED` | 이 경로의 레이트리밋은 **`health:ip` 60회/1분**입니다 | 모니터링 주기를 1분 이상으로 두세요 |

> **익명에게는 `status` 한 줄뿐입니다.** 버전·PHP 버전·DB 종류·설치 상태는 나오지 않습니다.
> 상세 10필드는 `role >= admin` 토큰을 함께 보낼 때만 나옵니다.
> **인증에 실패해도 401이 아니라 익명 응답이 나갑니다** — 그러므로
> **"상세가 안 보인다"는 토큰 문제이고 서버 장애가 아닙니다.**
>
> **상세 응답은 장애 중에도 항상 HTTP 200입니다.** 상태 코드로 판단하지 말고 `data.status`
> (`ok` / `degraded` / `unavailable`)로 읽으세요. `503`은 익명 프로브에서만 씁니다.

**`404`가 나왔거나 v1.3.0 이하 서버라면** 아래를 씁니다.

```bash
curl -s -i '<<설치루트>>/server/api/auth/csrf.php'
```

| 결과 | 원인 | 조치 |
|---|---|---|
| `{"ok":true,"data":{"csrf_token":…}}` | 정상 | 다음 단계로 |
| HTTP 404 + HTML | 설치 루트나 경로가 틀림 | `<<설치루트>>` 값을 확인. `server/`와 `public/`의 **부모** 디렉토리 URL이어야 함 |
| PHP 소스가 그대로 다운로드됨 | 웹서버가 PHP를 실행하지 않음 | 호스팅에 PHP가 활성화되어 있는지 확인. `python -m http.server`로 열고 있지 않은지 확인 |
| `{"ok":false,"error":{"code":"SERVER_ERROR"}}` | `server/config.php`가 없음(설치 전) 또는 DB 연결 실패 | `install.php`로 설치를 완료. 이미 설치했다면 서버 에러 로그 확인 |
| HTTP 500 + 빈 화면 | PHP 치명적 오류 | 서버 에러 로그 확인. 임시로 `config.php`의 `DEBUG`를 `true`로 바꿔 원인을 본 뒤 **반드시 되돌린다** |
| 연결 자체가 안 됨 | DNS·방화벽·HTTPS 미지원 | 도메인과 프로토콜(`http` vs `https`) 확인 |

### 15-2. 증상: 로그인 화면에서 "알 수 없는 서비스입니다"

```bash
curl -s '<<설치루트>>/server/api/sso/validate.php?service_id=<<서비스ID>>&redirect_uri=http%3A%2F%2Flocalhost%3A5173%2Fcallback.html'
```

- `SSO_INVALID_SERVICE`가 나오면: `service_id`가 등록되어 있지 않거나 `status`가 `disabled`입니다.
- **조치:** 관리자 콘솔 [서비스] 탭에서 그 `service_id`가 있는지, `status`가 `active`인지 확인하세요. 오타(하이픈 vs 언더스코어, 대소문자)도 흔한 원인입니다. `service_id`는 소문자만 허용됩니다.

관리자 권한이 있으면 목록으로 직접 확인할 수 있습니다:

```bash
curl -s '<<설치루트>>/server/api/admin/services.php' \
  -H 'Authorization: Bearer <<관리자ACCESS_TOKEN>>'
```

### 15-3. 증상: "허용되지 않은 복귀 주소입니다"

같은 `validate.php` 호출에서 `SSO_INVALID_REDIRECT`가 나옵니다.

**확인 순서:**

1. 앱이 실제로 보내는 `redirect_uri`를 정확히 확인합니다. 브라우저 콘솔에서:

```javascript
console.log(location.origin + '/callback.html');
```

2. 관리자 콘솔 [서비스] 탭에서 그 서비스의 `redirect_whitelist`와 **글자 단위로** 비교합니다.

**흔한 불일치 원인:**

| 원인 | 예 |
|---|---|
| 호스트가 다름 | 앱은 `localhost`, 등록은 `127.0.0.1` |
| 포트가 다름 | 앱은 `:5173`, 등록은 `:3000` |
| 프로토콜이 다름 | 앱은 `http`, 등록은 `https` |
| 경로가 다름 | 앱은 `/callback.html`, 등록은 `/callback` |
| 트레일링 슬래시 | 앱은 `/callback.html`, 등록은 `/callback.html/` |
| 쿼리스트링이 붙음 | 앱이 `?from=x`를 붙여 보냄 (화이트리스트에 쿼리를 넣을 수 없으므로 앱에서 빼야 함) |

**조치:** 관리자 콘솔 [서비스] 탭 → 그 서비스 편집 → `redirect_whitelist`에 앱이 보내는 문자열을 **그대로** 추가합니다.

```bash
curl -s -X PUT '<<설치루트>>/server/api/admin/services.php?service_id=<<서비스ID>>' \
  -H 'Authorization: Bearer <<관리자ACCESS_TOKEN>>' \
  -H 'Content-Type: application/json' \
  -d '{"redirect_whitelist":["http://localhost:5173/callback.html"]}'
```

### 15-4. 증상: 브라우저 콘솔에 CORS 오류

전형적인 메시지: `Access to fetch at '…' from origin 'http://localhost:5173' has been blocked by CORS policy`

**확인:**

```bash
curl -s -i -X OPTIONS '<<설치루트>>/server/api/auth/session.php' \
  -H 'Origin: http://localhost:5173' \
  -H 'Access-Control-Request-Method: GET'
```

- 응답 헤더에 `Access-Control-Allow-Origin: http://localhost:5173`이 **있어야** 정상입니다.
- 없으면 그 origin이 어떤 활성 서비스의 `allowed_origins`에도 등록되어 있지 않은 것입니다.

**조치:**

```bash
curl -s -X PUT '<<설치루트>>/server/api/admin/services.php?service_id=<<서비스ID>>' \
  -H 'Authorization: Bearer <<관리자ACCESS_TOKEN>>' \
  -H 'Content-Type: application/json' \
  -d '{"allowed_origins":["http://localhost:5173"]}'
```

**Origin이 `null`이라고 나오면** `file://`로 페이지를 연 것입니다. 로컬 웹서버를 쓰세요(`python -m http.server 5173`).

### 15-5. 증상: 로그인은 되는데 이후 API가 401

**1) 토큰이 실제로 저장됐는지 확인** — 개발자도구 → Application → Local Storage

**2) 토큰이 서버에 도달하는지 확인**

```bash
curl -s '<<설치루트>>/server/api/auth/session.php' \
  -H 'Authorization: Bearer <<ACCESS_TOKEN>>'
```

| 결과 | 원인 | 조치 |
|---|---|---|
| 정상 응답 | 서버는 정상. 앱이 헤더를 안 붙이고 있음 | 앱 코드에서 `Authorization` 헤더를 확인 |
| `AUTH_REQUIRED` | 토큰 형식 오류 또는 헤더 미전달 | 토큰이 64자 hex인지 확인. 그래도 안 되면 `X-Auth-Token` 헤더로 바꿔 시도 |
| `AUTH_SESSION_EXPIRED` | 만료·회수됨 | `refresh`로 갱신하거나 재로그인 |

**3) `Authorization` 헤더가 PHP에 전달되지 않는 호스팅인지 확인**

```bash
curl -s '<<설치루트>>/server/api/auth/session.php' \
  -H 'X-Auth-Token: <<ACCESS_TOKEN>>'
```

`Authorization`으로는 401인데 `X-Auth-Token`으로는 200이면 **CGI 모드에서 헤더가 잘리는 것**입니다. 앱이 `X-Auth-Token`을 쓰도록 바꾸세요.

### 15-6. 증상: 로그인은 되는데 `payload`가 비어 있다 (`{}`)

가장 흔한 무증상 실패입니다. **순서대로 확인하세요.**

**0) 그 `service_id`로 새로 로그인해 본다 — 30초로 절반을 가릅니다** (v1.4.0)

```bash
curl -s '<<설치루트>>/server/api/sso/validate.php?service_id=<<서비스ID>>&redirect_uri=http%3A%2F%2Flocalhost%3A5173%2Fcallback.html'
```

| 결과 | 판정 |
|---|---|
| `400 SSO_INVALID_SERVICE` | **서비스 자체가 없거나 `disabled`입니다.** 아래 1)~5)를 볼 필요가 없습니다 — 운영자에게 그 서비스를 `활성`으로 바꿔 달라고 하세요 |
| `200` | 서비스는 살아 있습니다. 아래 1)로 가세요 |

**왜 이것이 0번인가:** **v1.4.0부터 서비스가 `disabled`면 `payload`가 `{}`가 됩니다.**
v1.3.0까지는 이 상황에서 시크릿이 그대로 나왔기 때문에 이 원인이 존재하지 않았습니다.
**세션은 살아 있고 HTTP 200이 그대로 오므로**, 아래 1)~5)를 아무리 확인해도 원인이 나오지 않습니다.
서비스를 다시 켜면 그다음 요청부터 `payload`가 돌아옵니다.

> **서비스를 껐다 켠 직후에 `refresh`를 돈 세션은 서비스별 TTL이 아니라 전역 기본값을 받습니다.**
> 동작에는 문제가 없고 수명만 달라집니다. 재활성 뒤의 회전부터 다시 서비스 값을 씁니다.

**1) 로그인 요청에 `service_id`를 넣었는가**

`service_id` 없이 로그인하면 `payload`는 항상 `{}`입니다. SSO 플로우에서는 `exchange` 요청의 `service_id`가 그 역할을 합니다.

**2) 그 서비스에 가입되어 있는가**

```bash
curl -s '<<설치루트>>/server/api/me/services.php' \
  -H 'Authorization: Bearer <<ACCESS_TOKEN>>'
```

- `data.items`에 그 `service_id`가 **없으면 미가입**입니다.
  - **조치 A:** 관리자 콘솔 [서비스] 탭에서 `auto_join`을 켭니다. 다음 로그인부터 자동 가입됩니다.
  - **조치 B:** 관리자 콘솔 [회원] 탭 → 대상 회원 → 서비스 오버라이드에 그 서비스를 추가합니다.
- 있는데 `membership_status`가 `blocked`면 차단된 것입니다. 이 경우 그 `service_id`로 로그인 자체가 403 `FORBIDDEN`으로 거부되므로, 여기까지 왔다면 해당하지 않습니다.

**3) 시크릿이 등록되어 있는가**

```bash
curl -s '<<설치루트>>/server/api/admin/secrets.php?service_id=<<서비스ID>>' \
  -H 'Authorization: Bearer <<관리자ACCESS_TOKEN>>'
```

- `data.total`이 0이면 시크릿이 하나도 없는 것입니다. 등록하세요.
- 있는데 `status`가 `disabled`면 payload에 실리지 않습니다.

**4) 등급 게이트를 통과하는가**

위 목록 응답의 `min_account_level` / `min_billing_tier` / `min_role`과, 사용자의 등급을 비교합니다.

```bash
curl -s '<<설치루트>>/server/api/auth/session.php' \
  -H 'Authorization: Bearer <<ACCESS_TOKEN>>'
```

응답의 `data.user.account_level_rank` / `role_rank` / `billing_tier_rank`를 확인하고, 게이트 값의 랭크와 비교하세요.

```
account_level : guest 0 · basic 10 · verified 20 · vip 30 · staff 90
role          : user 0 · manager 10 · admin 20 · superadmin 30
billing_tier  : free 0 · lite 10 · pro 20 · enterprise 30
```

사용자 랭크 < 게이트 랭크인 축이 하나라도 있으면 그 키는 빠집니다.

**조치:** 관리자 콘솔 [회원] 탭에서 대상 회원의 `billing_tier`를 올리고, `billing_expires_at`이 미래인지(또는 비어 있는지) 확인하세요. **과거 날짜면 판정 시점에 `free`로 강등됩니다.**

**5) 복호화가 실패하고 있는가**

위 4단계를 모두 통과했는데도 키가 없다면, 시크릿 복호화가 실패했을 수 있습니다(`config.php`의 `SECRET_ENC_KEY`가 등록 시점과 달라진 경우). 서버 에러 로그에서 `secret decrypt failed`를 찾으세요. 이 경우 그 시크릿을 삭제하고 다시 등록해야 합니다.

**이것은 앱에서 진단할 수 없습니다 — 운영자에게 물어야 합니다.**
`SECRET_ENC_KEY`를 안전하게 바꾸는 경로가 아직 없어서(14-10절 16번),
**운영자가 그 키를 바꾸면 전 서비스의 `payload`가 동시에 `{}`가 됩니다.**
그러므로 **여러 사용자·여러 서비스에서 한꺼번에 `{}`가 되기 시작했다면 5)가 1순위입니다** —
0)~4)는 서비스나 회원 단위로 갈리므로 그런 모양이 되지 않습니다.

> **`role >= admin` 토큰이 있으면 헬스체크가 이 항목을 직접 알려줍니다.**
> `GET /api/health.php`의 `checks` 중 `secret_enc_key`가 `ok:false`면
> 그 키가 64자 hex가 아니라는 뜻이고, **그 상태에서는 복호화가 전부 실패합니다.**
> 값 자체는 응답에 담기지 않습니다 — 「유효한 형식인가」 불리언뿐입니다.

### 15-7. 증상: `SSO_CODE_INVALID`

6가지 원인이 하나의 코드로 통합되어 있습니다. 아래를 순서대로 확인하세요.

| 원인 | 확인 방법 |
|---|---|
| code 만료(60초 초과) | 콜백 페이지에서 사용자 확인 단계를 거치고 있지 않은지. **즉시 교환**해야 합니다 |
| code 재사용 | `handleCallback()`이 두 번 돌고 있지 않은지. React StrictMode에서 흔합니다 |
| `service_id` 불일치 | authorize 때와 exchange 때 같은 값인지 |
| `redirect_uri` 불일치 | authorize 때와 exchange 때 **완전히 같은 문자열**인지. `location.origin + '/callback.html'`과 `location.href`(쿼리 포함)는 다릅니다 |
| PKCE 불일치 | `code_challenge`를 보냈으면 `code_verifier`도 보내야 합니다. `sessionStorage`가 비워졌는지 확인 |
| code 자체가 없음/형식 오류 | 콜백 URL에 `?code=`가 실제로 붙어 있는지 |

**재현 테스트:**

```bash
# 1) code 발급
curl -s -X POST '<<설치루트>>/server/api/sso/authorize.php' \
  -H 'Content-Type: application/json' \
  -d '{"username":"hong","password":"Passw0rd!","service_id":"<<서비스ID>>","redirect_uri":"http://localhost:5173/callback.html","state":"teststate"}'

# 2) 위 응답의 data.code 를 즉시(60초 이내) 교환한다
curl -s -X POST '<<설치루트>>/server/api/auth/exchange.php' \
  -H 'Content-Type: application/json' \
  -d '{"code":"<1번 응답의 code>","service_id":"<<서비스ID>>","redirect_uri":"http://localhost:5173/callback.html"}'
```

2번이 성공하면 서버는 정상이고, 앱 코드에 문제가 있는 것입니다. 2번을 **한 번 더** 실행하면 `SSO_CODE_INVALID`가 나오는 것이 정상입니다(1회용).

### 15-8. 증상: 429 `RATE_LIMITED`

```bash
curl -s -i -X POST '<<설치루트>>/server/api/auth/login.php' \
  -H 'Content-Type: application/json' \
  -d '{"username":"hong","password":"wrong","service_id":"<<서비스ID>>"}'
```

응답 헤더의 `Retry-After`와 본문의 `data.retry_after`가 대기해야 할 초입니다.

| 버킷 | 상한 |
|---|---|
| 전 API 공통 (IP) | 60초 300회 |
| 로그인 (IP) — `login.php`와 `sso/authorize.php` 합산 | 5분 10회 |
| 로그인 (아이디) — 두 경로 합산 | 5분 5회 |
| 회원가입 (IP) | 1시간 5회 |
| SSO 교환 (IP) | 5분 30회 |
| 비밀번호 찾기 (IP / 이메일) | 1시간 5회 / 3회 |
| 이메일 재발송 (IP / 이메일) | 1시간 5회 / 3회 |
| 비밀번호 재설정 (IP) | 1시간 20회 |
| 이메일 인증 (IP) | 1시간 30회 |
| 포인트 차감 (사용자) | 60초 120회 |
| **토큰 갱신 (IP)** — `refresh.php`, v1.4.0 신설 | **60초 120회** |
| **토큰 갱신 (계정)** — `refresh.php`, v1.4.0 신설 | **3600초 60회** |
| SSO 클라이언트 인증 실패 (`service_id`) — v1.4.0 신설 | 900초 10회. **실패 경로에서만 오릅니다** |
| 헬스체크 (IP) — v1.4.0 신설 | 60초 60회 |

**조치:** 개발 중이라면 `retry_after`만큼 기다리세요. **즉시 재시도 루프를 만들면 계속 429가 납니다.** 자동 갱신 타이머가 폭주하고 있지 않은지도 확인하세요.

> **`refresh.php`의 429는 토큰을 죽이지 않습니다**(실측). 재로그인 흐름으로 보내지 말고
> `retry_after` 뒤에 **같은 `refresh_token`으로** 다시 시도하세요.
> 계정 버킷(`refresh:user`)은 IP가 아니라 계정 단위이므로, 같은 계정을 쓰는 다른 기기의 갱신도 같은 한도를 씁니다.
> 갱신율은 `기기수 × (3600 ÷ session_ttl)`입니다 — 기본값 3600초면 시간당 1회이므로 60배 여유가 있습니다.
> **모든 창은 고정 구간이고 슬라이딩이 아닙니다** — 3600초 창은 UTC 정시에 리셋되므로
> `retry_after`가 알려주는 시각까지만 기다리면 됩니다. **수명의 절반마다 미리 갱신하는 앱은
> 위 식의 두 배로 셉니다** — 서버의 `session_ttl`이 짧은 배포에 붙일 때는 갱신 주기를 확인하세요
> (그 값은 서버 운영자가 정하며 연동 앱은 `session` 객체의 `expires_at`으로만 알 수 있습니다).

### 15-9. 증상: 423 `AUTH_ACCOUNT_LOCKED`

연속 로그인 실패로 계정이 잠긴 것입니다. 응답의 `data.locked_until`(ISO8601)과 `data.retry_after`(초)를 보세요.

- 기본값: 5회 실패 시 15분 잠금. 10회를 넘기면 30분으로 늘어납니다.
- **해제 방법 3가지:**
  1. `retry_after`만큼 기다린다
  2. 비밀번호 재설정을 완료한다(잠금이 함께 풀립니다)
  3. 관리자가 그 회원의 `status`를 `active`로 저장한다(`locked_until`이 초기화됩니다)

### 15-10. 증상: 400 `VALIDATION_FAILED`인데 어느 필드인지 모르겠다

`error.field`에 필드명이 들어 있습니다.

```bash
curl -s -X POST '<<설치루트>>/server/api/auth/register.php' \
  -H 'Content-Type: application/json' \
  -d '{"username":"ab","email":"not-an-email","password":"1","password_confirm":"1"}'
```

응답 예:

```json
{
  "ok": false,
  "data": null,
  "error": { "code": "VALIDATION_FAILED", "message": "4자 이상 입력해 주세요.", "field": "username" }
}
```

**서버는 첫 번째로 걸린 오류 하나만 반환합니다.** 여러 필드가 잘못되어 있으면 고칠 때마다 다음 오류가 나옵니다.

### 15-11. 증상: 405 `METHOD_NOT_ALLOWED`

HTTP 메서드가 틀린 것입니다. 응답의 `data.allowed` 배열과 `Allow` 헤더에 허용 목록이 있습니다.

```bash
curl -s -i -X GET '<<설치루트>>/server/api/auth/login.php'
```

```json
{
  "ok": false,
  "data": { "allowed": ["POST"] },
  "error": { "code": "METHOD_NOT_ALLOWED", "message": "허용되지 않은 요청 방식입니다.", "field": "method" }
}
```

**조치:** 5-6절의 요약표에서 올바른 메서드를 확인하세요.

### 15-12. 증상: 403 `FORBIDDEN` — "이 서비스 이용이 제한되었습니다"

전역 계정은 정상인데 그 서비스에서만 차단된 상태입니다(`user_services.status = 'blocked'`).

**확인 (관리자 권한 필요):**

```bash
curl -s '<<설치루트>>/server/api/admin/user_detail.php?uuid=<<대상UUID>>' \
  -H 'Authorization: Bearer <<관리자ACCESS_TOKEN>>'
```

`data.user.service_overrides`에서 그 `service_id` 행의 `status`를 확인합니다.

**조치:**

```bash
curl -s -X PUT '<<설치루트>>/server/api/admin/user_detail.php?uuid=<<대상UUID>>' \
  -H 'Authorization: Bearer <<관리자ACCESS_TOKEN>>' \
  -H 'Content-Type: application/json' \
  -d '{"service_overrides":[{"service_id":"<<서비스ID>>","status":"active"}],"reason":"차단 해제"}'
```

**주의:** 회원이 **탈퇴**하면 모든 서비스 가입이 `blocked`로 내려갑니다. 그 경우 계정 자체를 복구해야 합니다.

### 15-13. 증상: 비밀번호 찾기 메일이 오지 않는다

응답은 항상 `{"sent":true}`이므로 성공/실패를 응답으로 구분할 수 없습니다(계정 열거 방지). **아래를 순서대로 확인하세요.**

1. **`mail_driver` 설정 확인** (관리자 권한 필요)

```bash
curl -s '<<설치루트>>/server/api/admin/settings.php' \
  -H 'Authorization: Bearer <<관리자ACCESS_TOKEN>>'
```

`data.map.mail_driver`가 `"log"`면 **실제 발송이 아니라 파일 저장**입니다. 파일은 `server/storage/mail/` 아래에 `.php` 확장자로 쌓입니다(웹으로는 볼 수 없고 FTP·SSH로 확인해야 합니다).

2. **`BASE_URL`이 설정되어 있는지 확인**

`server/config.php`의 `BASE_URL`이 비었거나 `__CHANGE_ME__` 같은 자리표시자면, **메일 발송 자체가 중단됩니다.** 링크를 만들 수 없기 때문입니다. 응답은 여전히 `{"sent":true}`이고 서버 에러 로그에만 사유가 남습니다.

3. **실제 발송으로 전환**

```bash
curl -s -X PUT '<<설치루트>>/server/api/admin/settings.php' \
  -H 'Authorization: Bearer <<관리자ACCESS_TOKEN>>' \
  -H 'Content-Type: application/json' \
  -d '{"settings":{"mail_driver":"mail"},"reason":"실제 발송으로 전환"}'
```

호스팅이 PHP `mail()`을 지원하지 않으면 이래도 안 갑니다. 그 경우 `log` 드라이버로 두고 관리자가 파일에서 링크를 꺼내 전달하는 방식으로 운영해야 합니다.

### 15-14. 증상: 한글이 깨진다

응답의 `Content-Type`이 `application/json; charset=utf-8`인지 확인하세요.

```bash
curl -s -i '<<설치루트>>/server/api/auth/csrf.php' | head -20
```

깨진다면 DB 문자셋 문제입니다. `config.php`의 `DB_CHARSET`이 `utf8mb4`인지, 테이블이 `utf8mb4_unicode_ci`로 만들어졌는지 확인하세요.

### 15-15. 증상: 시각이 9시간 어긋난다

**정상입니다.** 서버는 UTC로 저장·반환하고, 한국 시각(KST)은 UTC+9입니다.

```bash
curl -s '<<설치루트>>/server/api/admin/stats.php' \
  -H 'Authorization: Bearer <<관리자ACCESS_TOKEN>>'
```

`data.generated_at`이 현재 UTC 시각과 맞으면 정상입니다. 앱에서 로컬 시각으로 보여주려면 `new Date(iso).toLocaleString()`으로 변환하세요. **`Z`를 떼고 파싱하지 마세요.**

### 15-16. 증상: 500 오류만 나오고 원인을 모르겠다

기본적으로 `display_errors`가 꺼져 있어 상세 오류가 나오지 않습니다.

**임시 진단 방법:**
1. `server/config.php`의 `define('DEBUG', false);`를 `true`로 바꿉니다.
2. 다시 호출하면 응답의 `data.debug`에 예외 메시지가 들어옵니다.
3. **원인을 확인한 뒤 반드시 `false`로 되돌리세요.** `true`로 두면 파일 경로와 SQL 구조가 외부에 노출됩니다.

서버 에러 로그(호스팅 제어판의 `error_log`)에는 `DEBUG`와 무관하게 항상 `[ulogin]` 접두사로 기록됩니다. 그쪽을 먼저 보세요.

### 15-17. 진단용 한 줄 스크립트 (브라우저 콘솔)

앱 페이지의 개발자도구 콘솔에 붙여넣으면 현재 상태를 한 번에 확인할 수 있습니다.

```javascript
(async function diagnose() {
  var SERVER = '<<설치루트>>';
  var SERVICE_ID = '<<서비스ID>>';
  var token = localStorage.getItem('ulogin_' + SERVICE_ID + '_access_token')
           || localStorage.getItem('demo_' + SERVICE_ID + '_access_token');

  console.log('--- ulogin 진단 ---');
  console.log('설치 루트   :', SERVER);
  console.log('service_id  :', SERVICE_ID);
  console.log('현재 origin :', location.origin);
  console.log('토큰 보유   :', token ? '있음 (' + token.length + '자)' : '없음');

  // 1) 서버 도달 확인
  try {
    var r1 = await fetch(SERVER + '/server/api/auth/csrf.php', { cache: 'no-store' });
    var j1 = await r1.json();
    console.log('서버 도달   :', j1.ok ? 'OK' : 'NG - ' + j1.error.code);
  } catch (e) {
    console.error('서버 도달   : 실패 -', e.message, '(CORS 또는 네트워크)');
    return;
  }

  // 2) 서비스/복귀주소 확인
  var redirectUri = location.origin + location.pathname.replace(/[^/]*$/, '') + 'callback.html';
  try {
    var r2 = await fetch(SERVER + '/server/api/sso/validate.php'
      + '?service_id=' + encodeURIComponent(SERVICE_ID)
      + '&redirect_uri=' + encodeURIComponent(redirectUri), { cache: 'no-store' });
    var j2 = await r2.json();
    console.log('service_id  :', j2.ok ? 'OK (' + j2.data.service_name + ')' : 'NG - ' + j2.error.code);
    console.log('redirect_uri:', redirectUri, j2.ok ? '(화이트리스트 통과)' : '(등록 필요)');
  } catch (e) {
    console.error('서비스 확인 : 실패 -', e.message);
  }

  if (!token) { console.log('--- 여기까지. 로그인이 필요합니다. ---'); return; }

  // 3) 세션 + payload 확인
  try {
    var r3 = await fetch(SERVER + '/server/api/auth/session.php', {
      headers: { Authorization: 'Bearer ' + token }, cache: 'no-store'
    });
    var j3 = await r3.json();
    if (!j3.ok) { console.log('세션        : NG -', j3.error.code); return; }
    var u = j3.data.user, p = j3.data.payload || {};
    console.log('사용자      :', u.username, '/ 상태', u.status);
    console.log('등급        :',
      'account=' + u.account_level + '(' + u.account_level_rank + ')',
      'role=' + u.role + '(' + u.role_rank + ')',
      'billing=' + u.billing_tier + '(' + u.billing_tier_rank + ')',
      'active=' + u.billing_active);
    console.log('세션 서비스 :', j3.data.session.service_id || '(없음 — payload 가 비는 원인)');
    console.log('포인트      :', u.points);
    console.log('payload 키  :', Object.keys(p).length ? Object.keys(p).join(', ') : '(비어 있음)');
    if (!Object.keys(p).length) {
      console.warn('payload 가 비었습니다. 확인 순서: (1) 로그인에 service_id 를 넣었는가 '
        + '(2) 그 서비스에 가입되어 있는가 (3) 시크릿이 등록·활성 상태인가 (4) 등급 게이트를 통과하는가');
    }
  } catch (e) {
    console.error('세션 확인   : 실패 -', e.message);
  }

  // 4) 저장소에 payload 가 새고 있지 않은지
  var leak = [];
  try {
    for (var i = 0; i < localStorage.length; i++) {
      var k = localStorage.key(i);
      var v = localStorage.getItem(k) || '';
      if (/api_key|secret|token/i.test(v) && !/^ulogin_.*_(access|refresh)_token$/.test(k)
          && !/_(access|refresh)_token$/.test(k)) {
        leak.push(k);
      }
    }
  } catch (e) { /* ignore */ }
  console.log('저장소 누출 :', leak.length ? '주의 — ' + leak.join(', ') : '없음');
  console.log('--- 진단 끝 ---');
})();
```

---

## 이 문서의 버전

| 항목 | 값 |
|---|---|
| 대상 시스템 | ulogin — 범용 로그인 서비스 (PHP + MySQL SSO) |
| 대상 버전 | **v1.6.0** (`version.txt` 문자열 `1.6.0_20260903`) |
| 문서 생성일 | 2026-09-03 (v1.6.0 기준 갱신) |
| **서버 버전 확인** | `curl -sS '<<설치루트>>/public/version.txt'` → `1.6.0_20260903` (이 파일은 v1.2.0 신설. 404면 v1.1.0 이하입니다) |
| **v1.5.0 대비 API 변경** | **기존 엔드포인트의 경로·응답 shape·에러 코드 31개에 대해 0.** 늘어난 것은 관리자 전용 4동작(요약표 48~51)입니다. **한 가지 동작 변경:** `POST`/`PUT /api/admin/secrets.php` 의 게이트 검증이 좁아져 전에 통과했던 요청 본문이 400일 수 있습니다(문서 머리의 「v1.6.0 갱신 고지」 표). **로그인·SSO·`payload`·포인트 경로는 무영향입니다.** `1.5.0_20260901`·`1.4.0_20260901`을 내는 서버에도 이 문서를 그대로 쓰세요 — 그 서버들은 좁아진 판정을 갖지 않으므로 위 400이 나지 않습니다 |
| **자산 URL 버전 쿼리** | `?v={버전}` — `<script src>`에 붙입니다. `{버전}`은 자리표시자이고 넣을 값은 위 `version.txt` 첫 줄의 `_` 앞부분입니다. 지우면 브라우저가 옛 `ulogin-client.js`를 계속 씁니다 (14-11절) |
| **서버 요구 PHP** | **7.2.0 이상** (7.2 ~ 8.x 동일 동작) |
| 대응 엔드포인트 수 | **기준을 함께 적습니다 — 이 프로젝트에서 43과 45가 반복해 갈라진 원인이 「기준을 안 적은 것」이었습니다.** ① **메서드 × 경로 조합 47**(`only_method()`가 허용하는 메서드의 총합, `HEAD` 포함) ② **`HEAD` 제외 46** ③ **요약표 행 51**(②에 쿼리로 동작·최소역할이 갈리는 5건을 별행으로: `admin/secrets.php?action=reveal` · `admin/logs.php?type=admin` · `admin/logs.php?type=secret_access` · `admin/settings_bundle.php?action=` 3종이 1조합에서 3행). **세 값 전부 맞습니다.** API 파일 **34개** |
| **5절 요약표의 실제 행 수** | **51행 — 위 ③과 같은 값입니다.** v1.4.0 마감 라운드(D-178)가 2행(`GET`/`HEAD /api/health.php` · `admin/logs.php?type=secret_access`)을, v1.6.0이 4행(설정 번들 4동작)을 그 표에 넣었습니다. 상세 항목 `(1)`~`(45)`는 그대로이므로 **그 6행에만 상세가 없습니다** — 46·47의 상세는 「v1.3.0 → v1.4.0」 절의 (2) 표에, 48~51의 상세는 **`AI_SETTINGS_JSON_GUIDE.md`** 에 있습니다 |
| **동결 절 SHA-256 기준선** | 다음 라운드가 대조할 값입니다. **5절** `c8a2f7bd66633978cf71fabdbd8754fbb30706a568b055f1ff479cbb74c6d86e` · **6절** `1e05ed509b88ee0c633e43651807957725f17126774923daa103de4eed2a1a47` · **10절** `b419ff68165dc855a74b30b84c6a25459701ec2995e815988c14ed05a491ff6f`. **6절·10절은 v1.4.0 마감 라운드 이후 불변입니다**(v1.5.0·v1.6.0 모두 두 절을 열지 않았고, 매 라운드 같은 방법으로 다시 계산해 일치를 확인했습니다). **5절은 v1.6.0에서 두 번 바뀌었습니다** — v1.5.0 마감 시점의 값이 `5d0945a5e6bf9832c79a3da3ccf22e1a502f98d9a9389c7952fef894139f14d0`, 이 릴리스의 첫 문서 라운드가 제목의 계수와 5-6절 요약표(4행 추가)를 고쳐 `79da1798c68077d03f8cb5f2bbde8cfc537e77716187f3e01335fcc7dc3011b6` 이 됐고, 마감 라운드가 **5-5절 제목의 `21개`가 무엇을 센 값인지**를 그 절 머리에 적어 위 값이 됐습니다. **상세 항목 `(1)`~`(45)`의 번호와 문장은 세 번 모두 손대지 않았습니다** — 그것을 인용한 연동 앱의 인용이 깨지지 않습니다 |
| 위 기준선의 계산 방법 | 절의 범위는 **줄 번호가 아니라 제목 앵커**로 잡습니다 — 그 절의 `## ` 제목 행부터 **다음 `## ` 제목 행 직전까지**(코드펜스 안의 `## `는 제목이 아닙니다). 그 구간을 `\n`으로 이어 붙여 UTF-8 바이트로 SHA-256을 냅니다. **줄 범위로 잡으면 앞 절에서 행이 늘 때 거짓 불일치가 납니다** — v1.6.0이 5절 **앞**에 갱신 고지 절을 하나 더했으므로 그 차이가 이 라운드에서 실제로 드러납니다. 추출기는 쓰기 전에 대조군 2개(5절 앞 삽입 → 세 값 불변 / 6절 본문 1글자 변형 → 6절만 변함)로 먼저 검증했습니다 |
| 에러 코드 수 | 서버 **31개** + `ulogin-client.js` 전용 **5개** — **v1.4.0에서 변경 없음** |
| `user` 객체 필드 수 | 33개 (관리자 뷰에서 12개 추가) — **변경 없음** |
| `session` 객체 필드 수 | 11개 — **변경 없음** |
| 관리자 설정 키 수 | **13개** (v1.3.0은 12개. `secret_access_log`가 **맨 뒤**에 추가됐고 앞 12개의 인덱스는 보존됩니다) |
| `login_logs.result` 값 수 | **11개** (v1.3.0은 7개). 조회 필터 화이트리스트(`admin/logs.php` · `me/login-logs`) 양쪽이 같은 11값입니다 |
| `admin/secrets` 항목 키 수 | **14개** (v1.3.0은 13개. `allow_public` 추가) |
| 레이트리밋 버킷(고유 이름) | **18개** (v1.5.0은 16개 · v1.3.0은 12개. v1.6.0이 `bundle:apply:user` · `bundle:check:user` 를 더했고 **둘 다 설정 번들 경로 전용**입니다. 그 앞 라운드가 `exchange:clientauth` · `health:ip` · **`refresh:ip`** · **`refresh:user`** 를 더했습니다). **15-8절 표는 그 둘을 싣지 않습니다** — 연동 앱이 닿지 않는 관리자 경로이고, 그 표는 연동 앱이 실제로 맞는 429만 다룹니다. 전수를 다시 세는 명령은 `grep -rhoE "rate_limit(_logged)?\(\s*'[^']*'" server/ \| sed "s/.*('//" \| sed "s/'$//" \| sort -u \| wc -l` 입니다 |
| 3축 등급 | `account_level` 5값 / `role` 4값 / `billing_tier` 4값 |
| `ulogin-client.js` 버전 | `UloginClient.version === '1.0.0'` — **v1.1.0·v1.2.0·v1.3.0에서도 이 문자열은 `'1.0.0'`입니다** (아래 주의). 서버 버전 판정은 위 `version.txt`로 하세요 |
| DB 테이블 수 | **17개** (접두사 `app_ulogin_`). v1.3.0은 15개. **6-8절 표는 15개에서 동결돼 있습니다** — 늘어난 것은 `schema_migrations` · `secret_access_logs` 둘이고, 어느 쪽도 연동 앱이 읽지 않습니다 |
| **DB 스키마 변경** | **있습니다.** 기존 설치본은 `server/migrate.php`를 한 번 돌려야 합니다. **v1.4.0에서 올라오는 경우에도 그렇습니다** — v1.5.0이 마이그레이션 `0003`(컬럼 COMMENT만)을 더했습니다. **이것은 서버 운영자의 일이며 연동 앱이 할 일은 없습니다** — 다만 `GET /api/admin/logs.php?type=secret_access`가 500을 내면 운영자가 `0001`을 돌리지 않은 것입니다 |

### v1.5.0 → v1.6.0 에서 무엇이 바뀌었나 (연동 관점)

**기존 엔드포인트에 대한 API 변경 0. 연동 앱이 고칠 것은 `?v=` 값 하나입니다.**

v1.6.0은 **설정을 JSON 한 파일로 넣고 빼는 기능**을 더한 라운드입니다.
그 기능은 관리자(정확히는 `superadmin`) 전용이고, 연동 앱이 쓰는 경로에 손대지 않았습니다.

| 바뀌지 않은 것 | 바뀐 것 |
|---|---|
| 기존 엔드포인트 전체 · 실제 호출 URL · 메서드 · HTTP 상태 코드 | **요약표에 4행이 늘었습니다**(47 → **51행**) — `admin/settings_bundle.php` 의 `GET` + `POST ?action=` 3종. **네 동작 모두 `superadmin` 전용**입니다 |
| 응답 봉투 `{ok, data, error}` · 에러 코드 **31개** · `error` 구조 | 자산 URL의 버전 쿼리가 `?v=1.6.0` 으로 올라갔습니다. **당신 앱의 `<script src>`도 같은 값으로 맞추세요**(14-11절 (49)) |
| `user` **33필드** · 관리자 뷰 추가 **12필드** · `session` **11필드** · `payload` 구조 | `<<설치루트>>/public/version.txt` 가 `1.6.0_20260903` 을 냅니다 |
| 3축 등급의 값 집합과 랭크 정수 · effective 등급 계산 순서 · grant-only 규칙 | 신규 문서 `AI_SETTINGS_JSON_GUIDE.md` — **설정 번들 JSON을 만드는 AI를 위한 자립형 문서**. 검증 오류 코드 18개 · 경고 7개 전수 |
| **`payload` 반환 정책과 등급 판정** — 로그인·`/api/me` 가 어떤 키를 싣는지의 규칙은 **한 토큰도 바뀌지 않았습니다.** 시크릿 전달 판정은 `core/payload.php` 가 독립적으로 하고 아래 「바뀐 것」의 함수를 부르지 않습니다 | **시크릿의 「실효 게이트」 판정이 두 번 좁아졌습니다 — `POST`/`PUT /api/admin/secrets.php` 와 설정 번들 import 에만 영향이 있습니다.** ① `min_account_level: "basic"` 은 이제 게이트가 아닙니다(각 축의 **모집단 기본값**이라 전원이 통과합니다. `free`·`user` 는 종전에도 아니었습니다) ② 그 축을 같은 서비스의 `default_account_level` 이 자동으로 보장하면 게이트로 세지 않습니다(`auto_join` **또는** `allow_register` 가 열려 있을 때. **둘 다 스키마 기본값 `1`**). **전에 201/200 이던 요청 본문이 이제 400(`VALIDATION_FAILED` · `field: min_billing_tier`)일 수 있습니다** — 관리자 API를 호출하는 코드를 가진 앱만 확인하면 됩니다. 처방은 `min_billing_tier` 를 `lite` 이상으로. **게이트 필드를 건드리지 않는 `PUT`(값·설명·`status`·정렬)은 재판정하지 않습니다** |
| `admin/secrets` 응답의 필드와 shape · 상태 코드 · `error.code` | `server/core/` 에 파일 3개(`secretguard.php` · `settingsdef.php` · `urlrules.php`). **전부 기존 규칙을 한 곳으로 모은 것이고, 그중 판정 하나가 위와 같이 좁아졌습니다** |
| 레이트리밋 기존 버킷과 한도 · 쿠키 동작 · fail-closed 3종 · 서버 요구 PHP **7.2+** | 레이트리밋 버킷 2개 추가 — `bundle:apply:user:` 10회/1시간 · `bundle:check:user:` 60회/1시간. **설정 번들 경로 전용**이고 기존 버킷은 무변경입니다(버킷 총수 16 → **18**) |
| **DB 스키마** — 신규 컬럼·테이블·마이그레이션 **0건** | `admin_logs.action` 에 값 하나 추가 — `setting.bundle_apply`. 기존 값은 그대로이고, 번들이 만든 행별 로그는 **기존 action 이름**(`service.create` 등)을 씁니다. 관리자 감사 화면의 필터가 그대로 동작합니다 |

**그러므로 v1.0.0 ~ v1.5.0용으로 쓴 연동 코드는 수정 없이 v1.6.0에서 동작합니다.**
**한 가지 예외:** 당신의 앱이 `POST`/`PUT /api/admin/secrets.php` 로 시크릿을 만들거나
고친다면 위 표의 게이트 판정 행을 확인하세요. **로그인·SSO·`payload` 수신·포인트 경로는
전부 무영향입니다.**

> **설정 JSON을 만들라는 요청이라면 이 문서가 아니라 `AI_SETTINGS_JSON_GUIDE.md` 입니다.**
> 이 문서는 **연동 코드**를, 그 문서는 **설정 파일**을 다룹니다. 둘 다 자립형이므로
> 함께 읽을 필요가 없습니다.

### v1.4.0 → v1.5.0 에서 무엇이 바뀌었나 (연동 관점)

**API 변경 0. 연동 앱이 고칠 것은 아무것도 없습니다.**

v1.5.0은 **회귀 안전망을 만든 라운드**입니다. 서버의 실행 코드를 한 토큰도 바꾸지 않았습니다.

| 바뀌지 않은 것 | 바뀐 것 |
|---|---|
| 엔드포인트 전체 · 실제 호출 URL · 메서드 · HTTP 상태 코드 | 자산 URL의 버전 쿼리가 `?v=1.5.0`으로 올라갔습니다. **당신 앱의 `<script src>`도 같은 값으로 맞추세요**(14-11절 (49)) |
| 응답 봉투 `{ok, data, error}` · 에러 코드 **31개** · `error` 구조 | `<<설치루트>>/public/version.txt` 가 `1.5.0_20260901` 을 냅니다 |
| `user` **33필드** · 관리자 뷰 추가 **12필드** · `session` **11필드** · `payload` 구조 | 저장소에 `tests/` 가 생겼습니다 — 3티어 99케이스, 의존성 0. **서버에 올라가지 않습니다** |
| 3축 등급의 값 집합과 랭크 정수 · effective 등급 계산 순서 · grant-only 규칙 | 서버 PHP 5개 파일의 **주석**이 정정됐습니다 (팬텀 함수명 1 · 낡은 줄 번호 인용 6 · 사실 오류 1) |
| 시크릿 게이트 판정 규칙(D-47 / D-54) · `payload` 반환 정책 | 마이그레이션 **`0003`** 이 늘었습니다 — `login_logs.result`의 **컬럼 COMMENT**를 두 설치 경로에서 같게 만듭니다 |
| 레이트리밋 버킷 **16개**와 한도 · 쿠키 동작 · fail-closed 3종 · 서버 요구 PHP **7.2+** | — |
| 관리자 설정 키 **13개** · `admin/secrets` 항목 키 **14개** · `login_logs.result` **11값** · DB 테이블 **17개** | — |
| `ulogin-client.js` — `UloginClient.version`은 여전히 **`'1.0.0'`** 입니다 | — |

**「API 변경 0」의 근거는 문구가 아니라 측정입니다.** 편집된 서버 PHP 5개 파일
(`api/admin/settings.php` · `api/admin/user_detail.php` · `core/mailer.php` ·
`core/request.php` · `install.php`)에서 **주석과 공백을 뺀 토큰 열의 SHA-256이
v1.4.0 파일과 전부 같습니다.** `server/schema.sql`의 변경도 **`--` 로 시작하는 24행의
추가뿐이고 삭제가 0행**입니다.

> **`0003`은 연동 앱에 보이지 않습니다.** 바꾸는 것은 `login_logs.result` 컬럼의
> **COMMENT 메타데이터**이고, 그 컬럼의 **값 집합은 v1.4.0의 11값 그대로입니다.**
> `GET /api/me/login-logs`가 내는 `result` 값도 그대로입니다.
> **운영자가 `migrate.php`를 안 돌린 서버에서도 API 응답은 같습니다** —
> 다른 것은 그 DB의 컬럼 주석이 11값 중 7값만 말한다는 것뿐입니다.

**v1.0.0 ~ v1.4.0용으로 쓴 연동 코드는 수정 없이 v1.5.0에서 동작합니다.**
확인할 것은 하나입니다 — `?v=` 값을 서버 배포본에 맞추는 것(14-11절 (49)).

### v1.3.0 → v1.4.0 에서 무엇이 바뀌었나 (연동 관점)

**API 변경은 「0」이 아닙니다. 동작이 바뀐 자리가 셋입니다.** 다만 **정상 연동 앱은 깨지지 않습니다** —
그 근거를 아래에 실측과 함께 적습니다. 「변경 0」이라고 읽고 넘어가면 안 되는 라운드입니다.
(에러 코드 목록 31개는 그대로이고, 세 번째는 기존 코드가 새 자리에서 나올 수 있게 된 것입니다.)

v1.4.0은 두 가지를 한 라운드입니다 — ① 스키마 마이그레이션 기반을 놓고,
② 이미 공개된 코드에 있던 Critical 1건과 High 2건을 닫았습니다.

#### (1) 바뀐 동작 3건

**① `exchange`의 에러 코드가 1케이스에서 401 → 400이 됩니다**

| 케이스 | v1.3.0 | v1.4.0 |
|---|---|---|
| **존재하지 않는 `code`** + 틀린 `service_secret` | 401 `SSO_CLIENT_AUTH_FAILED` | **400 `SSO_CODE_INVALID`** |
| **유효한 `code`** + 틀린 `service_secret` | 401 `SSO_CLIENT_AUTH_FAILED` | **401 `SSO_CLIENT_AUTH_FAILED`** (변화 없음) |
| 유효한 `code` + 맞는 `service_secret` | 200 | 200 (변화 없음) |

**정상 연동 앱이 깨지지 않는 근거는 두 번째 줄입니다.**
`SSO_CLIENT_AUTH_FAILED`는 **없어지지 않았습니다** — 10-2절의 31개 목록에 그대로 있고,
*유효한* `code`로 호출하는 앱에서는 종전과 똑같이 나옵니다.
**에러 코드 표에서 사라졌다고 읽고 그 분기를 지우면 안 됩니다.**

바뀐 것은 **`code`가 애초에 유효하지 않은 경우**이고, 그때 앱은 어차피 실패합니다.
「문제가 둘일 때 먼저 발견된 것을 알려준다」로 바뀌었을 뿐입니다.

*왜 바꿨나:* 종전 순서는 클라이언트 인증을 `code` 형식 검사보다 **먼저** 했습니다.
그래서 익명이 쓰레기 `code`를 들고 `service_secret`만 바꿔 가며
**401(틀림) / 400(맞음)** 으로 정답을 가릴 수 있었고, 그 실패가 어느 로그에도 남지 않았습니다.
**응답 코드 두 개가 그 자체로 오라클이었기 때문에 그 구분을 없애는 것이 수정입니다.**

**부수 효과 2건은 연동 관점의 동작 변경입니다 — 이쪽이 실제로 걸릴 수 있습니다.**

| 무엇 | 무엇을 해야 하나 |
|---|---|
| **인증에 실패한 `code`는 소모됩니다.** 종전에는 같은 `code`로 60초간 재시도할 수 있었습니다 | 재시도하려면 `POST /api/sso/authorize`부터 다시 도세요. **같은 `code`를 다시 보내면 `SSO_CODE_INVALID`입니다** |
| **`service_secret`을 잘못 설정한 앱은 10회 실패 후 그 `service_id` 전체가 900초간 429**가 됩니다. 버킷 키가 IP가 아니라 `service_id`입니다 | 의도된 동작입니다. **버킷은 실패 경로에서만 오릅니다** — 맞는 secret을 보내는 앱은 버킷이 소진된 상태에서도 200을 받습니다. 개발 중에 secret을 오타로 두고 반복 호출하지 마세요 |

**② 서비스가 `disabled`일 때 `payload`가 비워집니다 — 세션은 유지됩니다**

| | v1.3.0 | v1.4.0 |
|---|---|---|
| `GET /api/auth/session` (서비스가 `disabled`) | **200 · `payload` = 시크릿 평문** | **200 · `payload` = `{}`** |
| HTTP 상태 · 최상위 키 | — | **바뀌지 않았습니다** |
| 세션 · 토큰 | — | **회수하지 않습니다.** 재로그인이 필요 없습니다 |
| `login` / `exchange` / `refresh` | 400 / 400 / 200 + `{}` | **변화 없음** |

**연동 앱이 해야 할 일: `payload`가 `{}`인 경우를 이미 처리하고 있다면 아무것도 없습니다.**
그 모양은 v1.0.0부터 미가입·등급 미달에서 나왔고, `refresh` 경로는 이 상황에서도
v1.3.0부터 `{}`를 냈습니다. **`payload.ai_api_key`가 항상 있다고 가정한 앱만 영향을 받으며,
그 가정은 v1.0.0 계약에서도 틀렸습니다**(8-3절 · 8-4절).

*왜 바꿨나:* `require_auth()`가 세션 행의 `service_id`를 그대로 `build_payload()`에 넘기면서
**서비스 자체의 상태를 한 번도 읽지 않았습니다.** 검사하던 것은 회원별 가입 상태뿐이었습니다.
그래서 침해를 의심한 관리자가 서비스를 껐을 때, **네 반환 경로 중 셋은 실제로 막히고
`GET /api/auth/session`만 시크릿을 계속 냈습니다** — 운영자는 셋이 막히는 것을 보고
막혔다고 결론짓습니다. 탈취된 Bearer 토큰이 최대 7일간 키를 계속 받아갈 수 있었습니다.

> **`payload`가 `{}`일 때 확인할 것이 하나 늘었습니다.**
> 종전 3가지(미가입 · 등급 미달 · 서비스 차단)에 **「서비스 자체가 비활성」** 이 더해집니다.
> 판별은 간단합니다 — **그 `service_id`로 새로 로그인해 보면 `400 SSO_INVALID_SERVICE`가 납니다.**
> 15-6절에 순서대로 적었습니다.

**③ `POST /api/auth/refresh.php`가 429를 낼 수 있습니다 — 새 에러 코드는 아닙니다**

| | v1.3.0 | v1.4.0 |
|---|---|---|
| 갱신 요청의 상한 | 전역 `api:ip` **300회/60초** 하나뿐 | **+ `refresh:ip` 120회/60초 · `refresh:user` 60회/3600초** |
| 초과 시 응답 | 429 `RATE_LIMITED` (전역 한도에서) | **429 `RATE_LIMITED`** (같은 코드. `data`는 `retry_after` 하나) |
| 429를 받은 `refresh_token` | — | **죽지 않습니다.** 한도가 풀리면 같은 토큰이 200을 받습니다(실측) |

**연동 앱이 해야 할 일: 429를 재로그인이 아니라 재시도로 처리하는 것입니다.**
`AUTH_SESSION_EXPIRED`(401)와 달리 이것은 **토큰이 유효한데 지금 받아주지 않는다**는 뜻입니다.
401에서 재로그인하고 429에서 기다리는 두 분기를 나눠 두세요. 10-2절의 `RATE_LIMITED` 지침이 그대로 적용됩니다.

*왜 넣었나:* 시크릿 전달 감사 로그(`secret_access_logs`)가 **갱신 요청과 1:1로 늘어나는데**
그 표에는 자동 정리가 없었습니다. 갱신마다 새 세션 식별자가 생겨 시간 버킷이 접히지 않기 때문입니다.
유효 계정 하나로 하루 43만 행을 만들 수 있었고, 그것이 이 라운드의 보안 재검증에서 High로 판정됐습니다.
**계정 버킷이 그 증가를 하루 1,440행으로 묶습니다.** 1:1 비율 자체를 없애는 것은 v1.5.0 과제입니다.

#### (2) 추가된 표면 — 전부 additive입니다

| 추가 | 무엇 | 연동 앱이 알아야 하나 |
|---|---|---|
| `GET`/`HEAD /api/health.php` | 헬스체크. 익명에게는 `{"status":"ok"}` 그것뿐(DB 장애면 **503** + `{"status":"unavailable"}`), `role >= admin`에게는 상세 10필드 | **살아 있는지 확인하는 데 쓸 수 있습니다.** 15-1절이 이제 이것을 씁니다. 레이트리밋 `health:ip` **60회/1분** |
| `GET /api/admin/logs.php?type=secret_access` | 시크릿이 누구에게 언제 전달됐는지의 감사 로그. `admin` 이상 | 관리자 API를 쓰는 경우만 |
| `service_secrets.allow_public` | `GET /api/admin/secrets`의 항목 키가 13 → **14**. **기존 13키는 무변경** | 관리자 API를 쓰는 경우만. **마이그레이션을 안 돌린 서버에서는 항상 `false`로 옵니다**(500이 나지 않습니다) |
| `login_logs.result` 열거값 4종 추가 | `fail_client_auth` · `fail_migrate_auth` · `fail_refresh_reuse` · `fail_refresh_revoked` (7 → **11**) | 6-7절 표가 7종에서 동결돼 있습니다. `GET /api/me/login-logs`의 `result` 값으로 이 넷이 올 수 있습니다 — **모르는 값을 만나면 그대로 표시하도록 쓰세요.** `fail_refresh_revoked`는 이름만 `fail`이고 **정상 경로**입니다(로그아웃·비밀번호 변경·관리자 회수 뒤의 갱신 시도). 탈취 신호로 강조하지 마세요 — 그 신호는 `fail_refresh_reuse` 하나입니다 |
| 관리자 설정 키 `secret_access_log` | 13번째, **맨 뒤**. `GET /api/admin/settings.php`의 `total`이 12 → **13** | `total === 12`를 단정하는 코드가 있으면 고치세요. **앞 12개의 인덱스는 보존됩니다.** 이 키만 `PUT`에 **`superadmin`이 필요합니다** — `admin` 토큰으로 이 키를 담아 보내면 403 `FORBIDDEN`이고 **같은 요청의 다른 키도 저장되지 않습니다**(부분 적용 없음). 다른 12개 키는 종전처럼 `admin`입니다 |
| `POST /api/auth/refresh.php`의 전용 레이트리밋 | IP 120회/60초 · **계정 60회/3600초**. 새 에러 코드가 아니라 기존 `RATE_LIMITED`(429)입니다 | **처리해야 합니다.** 위 문서 머리 「① 동작 변경」 3번을 보세요. **429가 토큰을 죽이지 않으므로 재로그인이 아니라 재시도가 맞습니다** |
| `page` 상한 `PAGE_MAX = 10000` | 종전에는 `page`에 상한이 없었습니다 | **에러가 아니라 clamp입니다.** `page=999999`를 보내면 400이 아니라 200이 오고, **응답의 `page`가 10000**입니다. 요청한 값이 그대로 돌아온다고 가정하지 마세요 |
| `server/migrate.php` | 스키마 마이그레이션 화면(HTML, JSON 봉투를 쓰지 않습니다) | **운영자의 도구입니다.** 연동 앱이 호출할 것이 없습니다 |
| `X-Powered-By` 제거 | 응답 헤더에서 사라집니다 | 본문·상태 코드는 무변경 |

#### (3) 바뀌지 않았다고 확인한 것

**v1.3.0 서버와 v1.4.0 서버를 동시에 띄우고 같은 시드에 같은 요청을 보내 대조했습니다 —
26케이스 SAME / DIFF 0**(위 (1)의 알려진 2건은 별도 확인).

| 바뀌지 않은 것 |
|---|
| 엔드포인트 경로 · 실제 호출 URL · 메서드 · HTTP 상태 코드 |
| 응답 봉투 `{ok, data, error}` · 에러 코드 **31개** · `error` 구조 |
| `user` **33필드** · 관리자 뷰 추가 **12필드** · `session` **11필드** · `payload` 구조 |
| 3축 등급의 값 집합과 랭크 정수 · effective 등급 계산 순서 · grant-only 규칙 |
| 시크릿 게이트 판정 규칙(D-47 / D-54) — **규칙 자체는 그대로입니다** |
| 저장 포맷 — `password_hash(PASSWORD_DEFAULT)` · `openssl_encrypt(aes-256-cbc, base64(iv+ct))` |
| 쿠키 동작 · fail-closed 3종(D-53 · D-67 · D-75) · 서버 요구 PHP **7.2+** |
| `ulogin-client.js` — `UloginClient.version`은 여전히 **`'1.0.0'`** 입니다 |

> **민감 키 판정 정규식의 범위는 넓어졌습니다** — `openai_key` · `claude_key` ·
> `anthropic_key` · `private_key` · `sk_live` · `secret_key` · `x-api-key` · 맨 `key`가
> 게이트 없이 등록될 수 있었습니다. **이것은 관리자가 시크릿을 새로 등록·수정할 때만 걸립니다.**
> **이미 등록된 행은 건드리지 않으므로 연동 앱이 받는 `payload`는 달라지지 않습니다.**
> 부작용 1건: `api_key_hint`처럼 비민감이던 이름이 이제 민감으로 잡혀
> 관리자 콘솔에서 게이트 없이 등록하려면 명시적 승인이 필요합니다.

**v1.0.0 ~ v1.3.0용으로 쓴 연동 코드는 위 (1)의 2건만 알고 있으면 수정 없이 v1.4.0에서 동작합니다.**
그리고 `?v=` 값을 서버 배포본에 맞추세요(14-11절 (49)).

### v1.2.0 → v1.3.0 에서 무엇이 바뀌었나 (연동 관점)

**API 변경 0. 연동 앱이 고칠 것은 아무것도 없습니다.**

v1.3.0은 **저장소를 공개하기 위한 라운드**이고, 서버 실행 코드는 손대지 않았습니다.
`server/api/**` **32개 파일 전량**과 `server/core/**` **전량**이 v1.2.0과 **바이트 단위로 동일**합니다
(2026-08-30 `diff -rq` 실측). 그러므로 **엔드포인트 · 요청/응답 JSON · 에러 코드 · 인증 흐름 ·
3축 등급 판정 · `payload` 구조 중 어느 것도 바뀌지 않았습니다.**

| 바뀌지 않은 것 | 바뀐 것 |
|---|---|
| `server/api/**` 32파일 · `server/core/**` — **바이트 동일** | 자산 URL의 버전 쿼리가 `?v=1.3.0`으로 올라갔습니다. **당신 앱의 `<script src>`도 같은 값으로 맞추세요**(14-11절 (49)) |
| 엔드포인트 전체 · 실제 호출 URL · 메서드 · HTTP 상태 | `<<설치루트>>/public/version.txt` 가 `1.3.0_20260828` 을 냅니다 |
| 요청·응답 JSON 필드 전부 (`user` 33필드 · `session` 11필드 · `payload`) | 이 문서를 포함한 문서들이 `<script src>` 예시의 버전 값을 **`?v={버전}` 자리표시자**로 바꿨습니다 — 릴리스마다 낡던 값이었습니다 |
| 에러 코드 36개 · `error` 봉투 구조 · 캐시 헤더 3줄 | `server/config.sample.php` 의 DB명 **예시**가 일반적인 값으로 바뀌었습니다(설치기 입력값이므로 연동과 무관) |
| 3축 등급 값·랭크 · 게이트 판정 규칙 · 시크릿 반환 정책 | 공개 패키징이 더해졌습니다 — `README.md` 전면 재작성, `docs/PORTFOLIO.md`, 스크린샷, `LICENSE`, `.gitignore`, `.gitattributes` |
| DB 스키마 15테이블 · 관리자 설정 키 12개 · 서버 요구 PHP **7.2+** | 설치기 화면 부제 문구 |

**v1.0.0 · v1.1.0 · v1.2.0용으로 쓴 연동 코드는 수정 없이 v1.3.0에서 동작합니다.**
확인할 것은 하나입니다 — `?v=` 값을 서버 배포본에 맞추는 것(14-11절 (49)).

> **`server/install.php` 도 바뀌었지만 연동과 무관합니다.** 설치 시점에 한 번 쓰고 삭제하는
> 파일이며, 바뀐 것은 화면 부제·주석뿐입니다.

### v1.1.0 → v1.2.0 에서 무엇이 바뀌었나 (연동 관점)

**API는 바뀌지 않았습니다.** v1.2.0은 **"새 버전을 올려도 브라우저에 반영되지 않는다"는 문제를 닫은
라운드**이고, 자산 URL에 버전 쿼리를 붙이고 응답 캐시 헤더를 보강한 것이 전부입니다.

| 바뀌지 않은 것 | 바뀐 것 |
|---|---|
| 엔드포인트 전체(5절 요약표 45행 = 메서드 × 경로 조합 43개) · 실제 호출 URL · 메서드 | `<script src>`의 자산 URL에 **`?v=1.2.0`** 이 붙었습니다 (14-11절) |
| 요청·응답 JSON 필드 전부 (`user` 33필드 · `session` 11필드 · `payload`) | `<<설치루트>>/public/version.txt` **신설** — 서버 배포본 버전을 HTTP로 확인할 수 있게 됐습니다 |
| 에러 코드 36개 · HTTP 상태 · `error` 봉투 구조 | API 응답의 캐시 헤더가 `Cache-Control: no-store` 한 줄에서 **3줄**로 늘었습니다: `no-store, no-cache, must-revalidate, max-age=0` + `Pragma: no-cache` + `Expires: 0` |
| 3축 등급 값·랭크 · 게이트 판정 규칙 | `public/.htaccess`에 캐시 정책 3종(HTML `no-store` / 자산 `immutable` / `version.txt` `no-store`) |
| DB 스키마 15테이블 · 관리자 설정 키 12개 | — |
| 서버 요구 PHP **7.2+** · 쿠키 동작 · fail-closed 3종(D-53·D-67·D-75) | — |

**그러므로 v1.0.0·v1.1.0용으로 쓴 연동 코드는 수정 없이 v1.2.0에서 동작합니다.**
**단 한 가지만 하세요 — `<script src>`에 `?v={버전}`을 붙이는 것입니다**(`{버전}`에 넣을 값은 14-11절 (49)). 붙이지 않아도 동작은
하지만, 다음 갱신에서 브라우저가 옛 `ulogin-client.js`를 계속 쓰게 됩니다.

**v1.2.0에는 "잘못된 설정에서의 동작 변경"조차 없습니다.** v1.1.0의 D-67·D-75와 달리 이 라운드가
바꾼 것은 응답 **헤더** 3줄과 자산 참조 URL, 그리고 파일 1개 추가입니다.
DB 마이그레이션도 없습니다.

> **응답 헤더가 3줄이 아니라 `no-store` 한 줄로 보이면** 서버 파일은 v1.2.0인데 PHP가 옛 코드를
> 실행하고 있는 것입니다(opcache). 판별 명령과 대응은 **14-11절 (51)** 에 있습니다.

### v1.0.0 → v1.1.0 에서 무엇이 바뀌었나 (연동 관점)

**API는 바뀌지 않았습니다.** v1.1.0은 **PHP 최소 요구 버전을 7.4 → 7.2로 낮춘 라운드**이고,
쿠키 전송 코드를 PHP 7.2 호환 래퍼로 교체한 것이 전부입니다.

| 바뀌지 않은 것 | 바뀐 것 |
|---|---|
| 엔드포인트 전체(5절 요약표 45행 = 메서드 × 경로 조합 43개) · 실제 호출 URL · 메서드 | 서버 요구 PHP 버전: 7.4+ → **7.2+** |
| 요청·응답 JSON 필드 전부 (`user` 33필드 · `session` 11필드 · `payload`) | 쿠키 전송이 `send_cookie()` 래퍼를 지난다 (7.2에서 `SameSite`·`HttpOnly`·`Secure`가 사라지던 문제 해결) |
| 에러 코드 36개 · HTTP 상태 · `error` 봉투 구조 | 설치기 환경 점검 게이트 완화 + 7.2·7.3 EOL 경고(정보성) |
| 3축 등급 값·랭크 · 게이트 판정 규칙 | — |
| DB 스키마 15테이블 · 관리자 설정 키 12개 | — |

**그러므로 v1.0.0용으로 쓴 연동 코드는 수정 없이 v1.1.0에서 동작합니다.**

> **본문에 남아 있는 "v1.0.0에서는 …" 서술은 v1.1.0에도 그대로 유효합니다.**
> (예: `two_factor_enabled`는 항상 `false`, `auth_type`은 항상 `"token"`, `requires_pkce`는 항상 `false`,
> `TIER_INSUFFICIENT`는 기본 구성에서 발생하지 않음.)
> v1.1.0은 **응답 shape 변경 없음 — v1.0.0과 API 호환**이므로 그 서술을 고치지 않았습니다.
> v1.1.0이 바꾼 것은 **잘못된 설정에서의 동작**뿐입니다(D-67·D-75가 조용한 실패를 500으로 바꿨습니다 —
> 14-9절 참조). 정상 설정에서 정상 요청을 보내면 응답의 키·형식·상태코드가 v1.0.0과 같습니다.
> **버전 숫자가 아니라 서술 내용을 그대로 믿으면 됩니다.**

> **`UloginClient.version` 주의:** v1.1.0은 `public/assets/ulogin-client.js`의 **실행 코드를 0행 바꾸지 않았으므로**
> 그 파일의 버전 문자열은 **여전히 `'1.0.0'`** 입니다. 이 파일 자체는 v1.1.0 라운드에서 **두 번 수정됐지만
> 두 번 모두 주석만이고 실행 라인은 578행 그대로 diff 0입니다**(2026-08-26 실측 — 문자열·템플릿·정규식
> 리터럴을 인식하는 주석 제거기로 실행 라인만 뽑아 비교했습니다). 그러므로 이 파일은 **v1.0.0과
> 바이트 단위로 같지 않고 크기도 다릅니다** — **파일 크기나 해시로 "같은 파일인가"를 판정하지 마세요.
> 판정은 아래 1)의 버전 문자열로 합니다.** 이 값은 클라이언트 라이브러리의 버전이고
> **서버 배포본의 버전이 아닙니다.** 아래 확인 명령 1)의 기대값이 `'1.0.0'`인 이유입니다.
> 서버가 v1.1.0인지 확인하려면 아래 3)을 쓰세요.

**버전 불일치를 감지하는 방법:**

```bash
# 0) 서버 배포본 버전 확인 — v1.2.0 부터 가능합니다. 이것이 가장 확실한 판정입니다.
curl -sS '<<설치루트>>/public/version.txt'
# 기대 출력: 1.5.0_20260901   ← 이 문서가 대상으로 하는 버전
#   1.4.0_20260901 이 나오면 연동 관점에서 동일합니다 (API 변경 0).
#     ?v= 값만 1.4.0 으로 맞추면 됩니다.
#   404 가 나오면 v1.1.0 이하의 배포본입니다 (이 파일은 v1.2.0 에서 신설됐습니다).
#   1.3.0_20260828 또는 1.2.0_20260828 이 나오면 v1.3.0 이하 배포본입니다.
#     연동 코드는 그대로 동작하고, 다른 것은 4가지뿐입니다 —
#     문서 머리 "v1.4.0 갱신 고지" ③의 표를 보세요.
#   그 밖의 다른 값이 나오면 이 문서보다 새로운(또는 다른) 배포본입니다.

# 1) 클라이언트 라이브러리 버전 확인
curl -s '<<설치루트>>/public/assets/ulogin-client.js' | grep -o "UloginClient.version = '[^']*'"
# 기대 출력: UloginClient.version = '1.0.0'
#   ※ v1.1.0 · v1.2.0 · v1.3.0 · v1.4.0 · v1.5.0 에서도 '1.0.0' 입니다. 실행 코드가 바뀌지 않았기 때문입니다
#      (파일 자체는 주석만 손댔고 실행 라인은 v1.0.0 대비 diff 0 입니다 — 578/578행, 2026-08-30 실측.
#       그래서 파일 크기는 v1.0.0 과 다릅니다. 크기가 아니라 이 문자열로 판정하세요).
#      '1.0.0' 이 아닌 값이 나오면 이 문서보다 새로운(또는 다른) 배포본입니다.

# 2) 관리자 설정 키 개수 확인
curl -s '<<설치루트>>/server/api/admin/settings.php' \
  -H 'Authorization: Bearer <<관리자ACCESS_TOKEN>>'
# 기대: data.total === 13   ← v1.4.0 · v1.5.0
#   12 가 나오면 v1.3.0 이하 배포본입니다 (secret_access_log 가 13번째로 추가됐습니다).

# 3) 헬스체크 존재 확인 — v1.4.0 이상에서만 200 입니다. 인증이 필요 없습니다.
curl -sS -o /dev/null -w '%{http_code}\n' '<<설치루트>>/server/api/health.php'
# 기대: 200   ← v1.4.0 이상
#   404 가 나오면 v1.3.0 이하 배포본입니다.
#   503 이면 v1.4.0 이상인데 **그 서버의 DB 가 죽어 있습니다** — 버전 문제가 아닙니다.
```

**3) 서버 배포본이 v1.0.0인지 v1.1.0인지는 HTTP로 알 수 없습니다.**
(**v1.2.0 이상인지는 위 0)의 `version.txt`로 알 수 있습니다** — 그 파일이 v1.2.0 신설이기 때문입니다.
아래 서술은 `version.txt`가 `404`인 배포본, 즉 v1.1.0 이하를 구분하는 이야기입니다.)
**정상 설정에서는** 두 버전의 API·응답 shape이 완전히 동일하기 때문입니다(그래서 연동 코드도 동일합니다).
잘못된 설정이라면 D-67·D-75의 500이 사실상 지문이 되지만, 그것에 의존하는 판별은 쓸 수 없습니다 —
"모순 설정인 배포에서만" 성립하는 판별이고, 그 상태 자체가 먼저 고쳐져야 하는 것이기 때문입니다.
꼭 구분해야 한다면 **FTP·파일 관리자로 `server/core/request.php`를 열어 `send_cookie` 함수가
있는지** 보세요 — 있으면 v1.1.0 이상, 없으면 v1.0.0입니다. 웹으로는 열리지 않습니다(404).

1)·2) 중 하나라도 기대값과 다르면 이 문서의 내용과 실제 서버 버전이 어긋난 것입니다. 그 경우 서버를 배포한 폴더에 함께 들어 있는 같은 이름의 최신 문서를 사용하세요.
