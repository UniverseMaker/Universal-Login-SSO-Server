# Screenshot Index — ulogin v1.3.0

이 폴더의 스크린샷은 **실제로 설치·구동한 화면을 브라우저로 촬영한 것**입니다.
합성·모의(mock-up) 이미지가 아니며, 모든 장면은 촬영 직전에 "기대 문자열이 DOM에 있는가"를 단언한 뒤 캡처했습니다.

All screenshots in this folder were **captured from a real, running installation** in a browser.
None are mock-ups. Every frame was asserted against the live DOM before capture.

| 항목 | 값 |
|---|---|
| 서버 | Apache 2.4.58 (Win64) + PHP 8.1.27 (mod_php) |
| DB | MariaDB (임시 인스턴스 · 촬영 후 삭제) |
| 브라우저 | Chrome headless, 뷰포트 1280×800, `deviceScaleFactor=1` |
| 접속 | `http://127.0.0.1:8911` (로컬 평문 HTTP) |
| 장수 | 35장 (우선순위 1 = 28장, 우선순위 2 = 7장) |

**데이터는 전부 가상입니다.** 계정은 `demo_*`, 이메일은 예약 도메인 `example.com`,
인물명은 가상 인물, IP는 문서용 예약 대역(`203.0.113.0/24` · `198.51.100.0/24`),
AI 키는 `sk-demo-0000000000000000` 입니다. 실제 자격증명은 한 건도 사용하지 않았습니다.

All data is fictitious: `demo_*` accounts, the reserved `example.com` domain, fictional person names,
documentation-reserved IP ranges, and a placeholder AI key.

---

## 설치기 / Installer

| 파일명 | 한국어 캡션 | English caption | 무엇을 증명하는가 |
|---|---|---|---|
| `install-01-env-check.png` | 1단계 환경 점검 — PHP 7.2 이상, PDO MySQL, OpenSSL, mbstring 등 필수 7항목이 모두 "통과" | Step 1 environment check — all 7 required items pass, including "PHP 7.2 or newer" | 설치 전 요구사항을 서버에서 직접 검사하며, 필수 항목이 하나라도 없으면 진행을 막는다 |
| `install-02-db.png` | 2단계 DB 접속 정보 — 호스트·DB명·계정·테이블 prefix 입력 | Step 2 database connection — host, name, user and table prefix | DB 1개를 prefix로 공유하는 공용 호스팅 전제를 설치기가 그대로 지원한다 |
| `install-03-schema.png` | 3단계 스키마 적용 — 15개 테이블 생성 완료 | Step 3 schema applied — 15 tables created | 마이그레이션 도구 없이 설치기가 스키마를 적용하고 결과를 표로 보고한다 |
| `install-04-site-security.png` | 4단계 사이트·전송 보안 — BASE_URL, HTTPS 강제, 메일 드라이버, 가입 정책 | Step 4 site and transport security — base URL, HTTPS enforcement, mail driver, signup policy | 전송 보안 라디오 하나가 `FORCE_HTTPS`와 `COOKIE_SECURE`를 함께 정해 둘이 어긋날 수 없게 만든다 |
| `install-05-admin.png` | 5단계 첫 최고관리자 계정 생성 | Step 5 create the first superadmin account | 설치 과정에서 관리자 계정을 만들어, 기본 비밀번호가 존재하는 상태를 애초에 없앤다 |
| `install-06-service.png` | 6단계 첫 서비스 등록 — `service_id`, 복귀 주소 화이트리스트, 허용 출처 | Step 6 register the first service — service ID, redirect whitelist, allowed origins | 등록되지 않은 복귀 주소·출처는 전부 차단된다는 SSO 전제가 설치 시점에 세워진다 |
| `install-07-secrets.png` | 7단계 시크릿 등록 — 값과 함께 **등급 게이트**(`min_billing_tier`)를 지정 | Step 7 register secrets — each value carries a tier gate | 시크릿마다 최소 등급을 걸 수 있고, 등급이 모자란 사용자에게는 그 키가 조용히 빠진다 |
| `install-08-done.png` | 8단계 설치 완료 — 결과 요약과 `install.php` 삭제 안내 | Step 8 installation complete — summary and instruction to delete `install.php` | 생성된 `SECRET_ENC_KEY`·`APP_KEY`를 **화면에 표시하지 않는다** (완료 화면에 64자 hex 0건을 자동 검사했다) |

## 인증 화면 / Authentication screens

| 파일명 | 한국어 캡션 | English caption | 무엇을 증명하는가 |
|---|---|---|---|
| `index.png` | 안내 화면 — 서비스 소개와 로그인·회원가입 진입 | Landing page with entry points to sign in and sign up | 프레임워크·번들러 없이 정적 HTML 한 장으로 동작한다 |
| `login.png` | 일반 로그인 — 아이디 저장, 로그인 유지 옵션 | Standard sign-in with "remember ID" and "stay signed in" | 로그인 유지 여부에 따라 세션 쿠키의 수명이 달라진다 |
| `login-sso.png` | SSO 모드 — 헤더에 요청한 서비스명("Demo AI App에 로그인")이 표시된 상태 | SSO mode — the requesting service name appears in the header | 로그인 화면이 어느 앱의 요청인지 사용자에게 밝히고, 끝나면 그 앱으로 되돌아간다 |
| `login-error.png` | 로그인 실패 — 아이디·비밀번호 중 무엇이 틀렸는지 알려주지 않는 공통 메시지 | Failed sign-in — a single generic message | 계정 존재 여부를 응답으로 흘리지 않는다 (사용자 열거 방지) |
| `register.png` | 회원가입 — 비밀번호 강도 표시와 필수 약관 동의 | Sign-up with password strength meter and required consent | 약관 동의 이력이 별도 테이블에 남는다 |
| `forgot.png` | 비밀번호 찾기 — 재설정 메일 요청 | Password reset request | 존재하지 않는 이메일에도 같은 응답을 돌려준다 |

## 마이페이지 / My page (7 tabs)

7장 모두 상단에 **3축 등급 배지**(계정등급 · 역할 · 결제등급)가 함께 보입니다.
All seven show the **three-axis tier badges** (account level, role, billing tier) in the header.

| 파일명 | 한국어 캡션 | English caption | 무엇을 증명하는가 |
|---|---|---|---|
| `mypage-info.png` | 정보 탭 — 계정 정보 조회와 수정 폼 | Info tab — account details and edit form | 등급·역할·포인트·아이디·이메일은 이 폼으로 바꿀 수 없다(권한 상승 경로 차단) |
| `mypage-security.png` | 보안 탭 — 비밀번호 변경, 이메일 변경 | Security tab — change password and email | 민감한 변경은 정보 수정 폼과 분리되어 있다 |
| `mypage-points.png` | 포인트 탭 — 잔액과 적립·사용 이력 | Points tab — balance and transaction history | 모든 포인트 증감이 이력으로 남고 AI 호출 차감도 여기에 기록된다 |
| `mypage-sessions.png` | 세션 탭 — 유효 세션 목록과 개별 회수 | Sessions tab — active sessions with individual revocation | 사용자가 자기 세션을 직접 끊을 수 있다 |
| `mypage-login-logs.png` | 로그인 이력 탭 — 성공·실패 기록과 IP | Login history tab — successes, failures and IPs | 실패 기록까지 남아 계정 도용 시도를 사용자가 볼 수 있다 |
| `mypage-services.png` | 서비스 탭 — 이 계정이 연결된 서비스 | Services tab — services this account is linked to | 서비스별 가입·최근 사용 시각을 따로 관리한다 |
| `mypage-withdraw.png` | 탈퇴 탭 — 사유 입력과 확인 절차 | Withdrawal tab — reason and confirmation | 탈퇴는 즉시 삭제가 아니라 상태 전이로 처리되고 사유가 남는다 |

## 관리자 콘솔 / Admin console

| 파일명 | 한국어 캡션 | English caption | 무엇을 증명하는가 |
|---|---|---|---|
| `admin-dashboard.png` | 대시보드 — 회원·로그인·포인트·세션 타일과 3축 등급 분포 막대 | Dashboard — member, login, point and session tiles plus three-axis tier distribution | 권한 모델이 3축이라는 것이 통계 화면에 그대로 드러난다 |
| `admin-users.png` | 회원 목록 — 6종 필터, 3축 배지, 유효 세션 수, 포인트 | Member list — six filters, three-axis badges, session count, points | 상태·역할·계정등급·결제등급을 각각 독립적으로 걸러 찾을 수 있다 |
| `admin-user-detail.png` | 회원 상세 모달 — 45개 필드와 서비스별 오버라이드 표 | Member detail modal — 45 fields plus per-service overrides | 전역 등급과 서비스별 등급이 따로 있고 유효값이 어떻게 정해지는지 한 화면에 보인다 |
| `admin-services.png` | 서비스 관리 — `service_id`, 복귀 주소 화이트리스트, 허용 출처, `session_ttl` | Service management — service ID, redirect whitelist, allowed origins, TTLs | 서비스마다 세션 수명과 허용 주소를 따로 정한다 |
| `admin-secrets.png` | 시크릿 목록 — 값이 전부 `****` 로 마스킹된 상태. **`admin-secrets-reveal.png` 와 짝으로 보십시오** | Secret list — every value masked as `****`. **Read together with `admin-secrets-reveal.png`** | 목록 화면은 등급과 무관하게 항상 마스킹한다. 평문은 최고관리자의 "값 보기"에서만 보이고 그 조회는 감사 로그에 남는다 |
| `admin-secrets-reveal.png` | "값 보기" 로 평문을 조회한 순간. **최고관리자 전용**이며, 조회 사실이 `admin_logs` 에 `secret.reveal` 로 남습니다(값은 남기지 않습니다). 모달 뒤의 목록은 여전히 `****` 입니다 — **`admin-secrets.png`(기본 상태)와 짝으로 보십시오.** 값 `sk-demo-0000000000000000` 은 데모용 가짜입니다 | The moment a plaintext secret is revealed. **Superadmin only**, and the lookup itself is written to `admin_logs` as `secret.reveal` (the value is not). The list behind the modal is still masked — **read together with `admin-secrets.png`.** The value shown is a fake demo placeholder | 평문이 새는 장면이 아니라 **평문이 어떤 조건에서만 나가는지**를 보여준다. 기본은 마스킹, 노출은 최상위 권한 한정, 그리고 조회 자체가 감사 기록으로 남는다 |
| `admin-logs-login.png` | 로그인 로그 — 결과별 필터와 집계, 입력된 아이디·IP·User-Agent | Login log — filters and counts by outcome, with attempted ID, IP and user agent | 없는 계정에 대한 시도까지 별도 결과값으로 기록한다 |
| `admin-logs-admin.png` | 관리자 조작 로그 — 조작 종류, 대상, 변경 전/후, 사유 | Admin action log — action, target, before/after values, reason | 관리자의 등급 변경·시크릿 수정·세션 회수가 전부 변경 전후와 함께 남는다 |
| `admin-settings.png` | 전역 설정 — 정의된 12개 키 | Global settings — the 12 defined keys | 정의되지 않은 키를 보내면 서버가 거부한다(임의 설정 주입 차단) |

## 로컬 AI 앱 데모 / Local AI app demo

| 파일명 | 한국어 캡션 | English caption | 무엇을 증명하는가 |
|---|---|---|---|
| `demo-before-login.png` | 미로그인 상태 — "이 페이지의 소스에는 AI API 키가 들어 있지 않습니다" | Signed out — the page source contains no AI API key | 이 저장소가 풀려는 문제 그 자체. 배포된 JS를 아무리 뜯어도 키가 없다 |
| `demo-after-login.png` | 로그인 후 — 3축 등급 배지, 포인트, 서버가 내려준 payload (키는 앞 6자만 표시) | After sign-in — tier badges, points, and the server-issued payload with the key truncated to 6 characters | 키는 로그인 뒤 서버가 등급을 확인하고 내려주며, 화면에는 가려 표시하고 `localStorage`에 저장하지 않는다 |
| `demo-ai-call.png` | 프롬프트 전송 → 포인트 10P 차감(1,260 → 1,250) → AI 응답 | Prompt sent, 10 points deducted (1,260 → 1,250), AI response rendered | 호출 전에 서버에서 먼저 차감하고, 부족하면 AI를 부르지 않는다 |

> `demo-ai-call.png` 의 AI 응답은 **로컬 목(mock) 엔드포인트**가 만든 것입니다.
> 촬영에 외부 AI 제공자를 부르지 않기 위해서이며, `api_base` 값이 화면에 그대로 보입니다.
> ulogin 쪽 동작(등급 확인 → payload 전달 → 포인트 차감)은 전부 실제 서버가 처리한 것입니다.
>
> The AI reply in `demo-ai-call.png` comes from a **local mock endpoint** (its URL is visible on screen),
> so that capturing required no external provider. Everything on the ulogin side — tier check, payload
> delivery, point deduction — was performed by the real server.

## 검증 · 운영 / Verification

| 파일명 | 한국어 캡션 | English caption | 무엇을 증명하는가 |
|---|---|---|---|
| `set-cookie-headers.png` | 로그인·로그아웃 응답의 **실측** `Set-Cookie` 와, 로그아웃 전후 `/api/me` 의 200 → 401. **토큰 값만 앞 8자를 남기고 잘랐습니다(`…[생략 56자]`) — 속성은 실측 원문 그대로이며 한 바이트도 고치지 않았습니다** | The **actual** `Set-Cookie` headers from sign-in and sign-out, and `/api/me` going 200 → 401 across sign-out. **Only the token value is truncated (first 8 chars, with an explicit elision marker); every attribute is the unmodified measured original** | 세션 쿠키에 `HttpOnly`·`SameSite=Lax` 가 실제로 붙고, 로그아웃이 쿠키 2종을 만료시키며 서버 세션도 함께 회수한다. 이 장의 증거는 **속성**이지 토큰 값이 아니다 — 값은 매 로그인 달라지고 아무것도 증명하지 않는다 |
| `cache-busting.png` | `public/login.html` 이 실제로 요청한 자산 URL 3건 — 전부 `?v=1.3.0`, 응답은 `max-age=31536000, immutable` | The three asset requests actually made by `public/login.html`, all carrying `?v=1.3.0`, served with a one-year immutable cache | 자산을 1년 캐시하면서도 버전을 올리면 새로 받게 되는 구조 |

> `set-cookie-headers.png` 와 `cache-busting.png` 는 브라우저 개발자도구 화면이 아니라,
> Chrome DevTools Protocol 로 받은 **응답 헤더·요청 URL 원문을 그대로 페이지에 렌더해** 촬영한 것입니다.
> 표시된 문자열은 손으로 입력하지 않았고, 캡처 시점의 실제 왕복에서 나온 값입니다.
>
> **한 가지 예외는 표시된 생략입니다.** `set-cookie-headers.png` 의 세션 토큰 값은 공개용으로
> 앞 8자만 남기고 잘랐고, 잘랐다는 사실을 화면에 `…[생략 56자]` 로 명시했습니다.
> **속성(`Path`·`HttpOnly`·`SameSite`·`Max-Age`·`expires`)은 실측 원문 그대로입니다** — 게시 전에
> 속성부가 원문과 바이트 단위로 같은지 자동 대조했습니다. 위조가 아니라 표시된 생략입니다.
>
> These two are not DevTools screenshots. The raw headers and request URLs were captured over the
> Chrome DevTools Protocol and rendered verbatim onto a page. No string was typed by hand.
> **One exception is a marked elision:** the session token value in `set-cookie-headers.png` is
> truncated to its first 8 characters, and the elision is labelled on screen. **Every cookie attribute
> is byte-for-byte the measured original**, verified automatically before publishing.
