<div align="center">

# ulogin — 통합로그인 서버

**브라우저에 API 키를 두지 않는 로그인 서버.**
로컬 HTML 앱과 AI 앱의 로그인을 서버 한 곳으로 모으고, 로그인한 사용자에게만 서비스별 키와 설정을 내려 준다.

[![PHP 7.2+](https://img.shields.io/badge/PHP-7.2%2B-777bb4)](#기술-스택과-요구-사항)
[![SQLite 기본 · MySQL 선택](https://img.shields.io/badge/DB-SQLite%20%C2%B7%20MySQL-0f6e5e)](#기술-스택과-요구-사항)
[![Composer 0](https://img.shields.io/badge/Composer-0%20dependencies-555)](#기술-스택과-요구-사항)
[![Tests](https://img.shields.io/badge/tests-php%20tests%2Frun.php-2f6f4f)](#회귀-시험)
[![License MIT](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

한국어 · [English](README.en.md)

<img src="docs/images/04-admin-dashboard.png" alt="관리 콘솔 대시보드" width="860">

</div>

---

## 핵심 강점

| 영역 | 내용 |
|---|---|
| **AI 정보 관리** | LLM API 키, 모델 이름, API 주소, 호출 한도를 서비스 시크릿으로 서버에 둔다. 값은 AES-256-CBC로 암호화해 저장하고, 로그인 직후 `payload`로 그 사용자에게만 전달한다. 키마다 최소 계정등급·결제등급·역할을 걸 수 있고, 조건에 못 미치는 키는 응답에서 빠진다 |
| **SSO** | 1회용 code(60초) 교환 방식. PKCE는 S256만 받고, 공개 클라이언트(시크릿 없는 앱)는 PKCE가 필수다. 네이티브 앱은 사용자 지정 scheme 복귀 주소를 쓸 수 있다. 이미 로그인한 브라우저용 무음 SSO 경로가 따로 있다 |
| **계정 통합·연결** | 각 시스템이 자기 계정 목록을 서버 간 API로 보내면, 관리 콘솔에서 통합 계정과 묶는다. 메일이 같은 계정은 후보로 먼저 보여 준다 |
| **세부 접근 단계** | 계정 × 서비스마다 `none`·`view`·`use`·`admin` 4단계. 그룹 단위 부여, 계정별 허용·거부 덮어쓰기, 시작·종료 기간을 둘 수 있다. 거부가 언제나 이기고, 회수하면 그 서비스 세션을 끊는다. 판정 결과는 로그인·토큰 응답의 `access`·`access_level`로 전달된다 |
| **데이터 독립** | 관리자에게도 필요한 만큼만 보인다. 로그인 기록의 IP·User-Agent 원문은 최고관리자만, manager에게는 이메일·전화가 가려져 보이고, 사용자 소유 문서의 내용은 관리 콘솔에 나오지 않는다 |
| **가입 방식** | 가입을 막기(`closed`)·승인제(`approval`)·바로 가입(`open`) 중에서 고른다. 승인제에서는 신청이 관리 콘솔 「가입 신청」에 쌓이고, 관리자가 판단 자료를 보고 승인·거부한다 |
| **플랫폼 API** | 연결된 시스템이 함께 쓰는 기능 — 전역 설정(조건·버전·되돌리기), 운영 로그 수집, 감사 기록 수집(HMAC-SHA256 해시 체인과 검사), 문서 테이블, 사용량 집계, 공동 포인트(서버 간 조회·적립·사용·이력), 계정 찾기 |
| **비상 관리자** | 아이디 `admin`의 비상 계정. 비밀번호는 서버 PC에서만 정한다 — CLI, 또는 CLI가 만든 10분짜리 1회용 열쇠 링크로 여는 `/local-admin` 화면. 정하기 전에는 로그인할 수 없고, 관리 콘솔 전용이다 |
| **설치와 운영** | SQLite가 기본이라 DB 서버가 없어도 된다. MySQL은 설정 한 줄로 고른다. PHP 7.2 이상, Composer·빌드 도구 없이 파일 업로드로 끝난다. 스키마 변경은 마이그레이션 러너가 맡는다 |
| **설정 번들** | 서비스·시크릿·전역 설정을 JSON 한 파일로 내보내고 가져온다. 검증 → 계획 보기 → 적용 3단계이고, 내보내기에는 시크릿 값이 들어가지 않는다. AI가 번들을 쓰도록 만든 별도 가이드가 있다 |
| **회귀 시험** | `php tests/run.php` 하나로 정적 검사·단위 시험·실서버 시험 3단계를 돈다. 검사기가 결함을 실제로 잡는지 스스로 확인하는 자체 점검이 들어 있다 |
| **research-ui 테마** | 왼쪽 메뉴와 위쪽 띠로 된 관리 화면, 밝게·어둡게, 팔레트 선택. 390px 폭 휴대폰 화면에서도 쓸 수 있다 |

---

## 왜 만들었나

AI 앱을 HTML 한 파일로 만들면 API 키도 그 파일 안에 들어간다. 브라우저가 실행하는 JavaScript는 누구나 볼 수 있으므로, 배포하는 순간 키가 공개된다.

키를 서버에 두려면 회원가입, 로그인, 세션, 권한, 관리 화면이 필요하다. ulogin은 이 부분만 맡는다.

1. 앱은 로그인을 ulogin으로 넘긴다.
2. 로그인한 사용자에게 ulogin이 그 앱의 시크릿을 내려 준다. 등급이 모자라면 해당 키는 오지 않는다.
3. 앱은 받은 값을 메모리에만 두고 쓴다.

공용 호스팅(PHP만 있고 Composer·셸이 없는 환경)에 올릴 수 있게 만들었다. 여러 시스템을 한 계정으로 묶는 통합로그인으로도 쓴다.

---

## 아키텍처

```mermaid
flowchart LR
    subgraph Clients[연결 시스템]
        A[로컬 HTML · AI 앱<br/>ulogin-client.js]
        B[다른 웹 시스템<br/>서버 측 연동]
    end
    subgraph ulogin[ulogin 서버 · PHP 7.2+]
        P[public/<br/>로그인 · 마이페이지 · 관리 콘솔]
        API[server/api/<br/>auth · sso · me · admin · platform]
        CORE[server/core/<br/>판정 · 암호화 · 감사 체인]
    end
    DB[(SQLite 기본<br/>MySQL 선택)]
    A -- "로그인 이동 · code 교환 · payload" --> API
    B -- "service_secret: 계정 목록 · 로그 · 감사 · 설정" --> API
    P --> API
    API --> CORE --> DB
```

- 화면(`public/`)과 API(`server/api/`)는 파일 하나가 주소 하나다. `mod_rewrite`가 없어도 동작한다.
- 접근 판정은 `server/core/access.php` 한 곳에서 한다. 관리 화면도 서버가 판정한 값을 그대로 보여 준다.
- 브라우저 앱은 PKCE와 사용자 토큰으로, 다른 시스템의 서버는 `service_id` + `service_secret`으로 부른다.

### SSO 흐름

```mermaid
sequenceDiagram
    participant App as 내 앱 (브라우저)
    participant UL as ulogin
    participant User as 사용자
    App->>App: code_verifier 생성, state 저장
    App->>UL: /login?service_id&redirect_uri&state&code_challenge
    UL->>User: 로그인 화면 (이미 로그인했다면 생략)
    User->>UL: 아이디 · 비밀번호
    UL->>App: redirect_uri?code&state (code는 1회용 · 60초)
    App->>UL: POST /server/api/auth/exchange.php (code, code_verifier)
    UL->>App: access_token · refresh_token · user · payload
    Note over App: payload(키·모델 정보)는 메모리에만 둔다
```

---

## 화면

모든 캡처는 새로 설치한 데모 인스턴스에서 찍었다. 계정·메일·키는 전부 가짜 값이다.

### 로그인과 마이페이지

| | |
|---|---|
| ![로그인 화면](docs/images/01-login.png)<br>**로그인** — 왼쪽에 연결 시스템, 오른쪽에 로그인 폼 | ![로그인 화면, 어둡게](docs/images/02-login-dark.png)<br>**로그인 (어둡게)** — 같은 화면의 어둡게 모드 |
| ![마이페이지 포털](docs/images/03-mypage-portal.png)<br>**마이페이지 포털** — 계정등급·역할·결제등급 배지와 연결 시스템 바로 가기 | ![휴대폰 로그인](docs/images/20-mobile-login.png)<br>**휴대폰 390px** — 로그인 화면 |

### 관리 콘솔 — 회원과 서비스

| | |
|---|---|
| ![대시보드](docs/images/04-admin-dashboard.png)<br>**대시보드** — 회원·로그인·포인트·세션 지표 줄과 등급 분포 | ![회원](docs/images/05-admin-users.png)<br>**회원** — 상태·역할·계정등급·결제등급 필터 |
| ![계정 연결](docs/images/06-admin-links.png)<br>**계정 연결** — 시스템별 계정 수집 상태와 연결 현황 | ![서비스](docs/images/07-admin-services.png)<br>**서비스** — 복귀 주소 허용 목록, 허용 출처, 세션 수명 |
| ![시크릿](docs/images/08-admin-secrets.png)<br>**시크릿 (AI 정보)** — 키·모델·주소·한도. 목록의 값은 항상 `****`, 키마다 최소 등급 | ![설정 번들](docs/images/17-admin-settings-bundle.png)<br>**설정 번들** — JSON 한 파일로 내보내기·가져오기 |

### 관리 콘솔 — 접근 권한

| | |
|---|---|
| ![접근 권한 행렬](docs/images/09-admin-access-matrix.png)<br>**계정 × 서비스 행렬** — 칸마다 서버가 판정한 단계와 출처(규칙·그룹·덮어쓰기), 기한 | ![그룹](docs/images/10-admin-access-groups.png)<br>**그룹** — 그룹별 구성원과 서비스 단계 |

### 관리 콘솔 — 가입 신청과 비상 관리자

| | |
|---|---|
| ![가입 신청 목록](docs/images/22-admin-register-requests.png)<br>**가입 신청** — 승인제에서 들어온 신청과 상태(대기·승인·거부) | ![가입 신청 상세](docs/images/23-admin-register-request-detail.png)<br>**신청 상세(보고서)** — 판단 자료를 보고 승인·거부 |
| ![가입 방식 설정](docs/images/24-admin-settings-register-mode.png)<br>**가입 방식** — 가입 불가·가입 신청·즉시 승인 중 하나 | ![로컬 관리 화면](docs/images/25-local-admin.png)<br>**`/local-admin`** — 서버 PC에서 1회용 열쇠 링크로 연 비상 계정 설정 화면 |

### 관리 콘솔 — 로그와 플랫폼

| | |
|---|---|
| ![로그 통계](docs/images/11-admin-logs-stats.png)<br>**로그인 로그 통계** — 성공·실패·보안 신호 묶음 | ![감사 기록](docs/images/12-admin-audit-chain.png)<br>**감사 기록** — 해시 체인 검사 결과(정상, 검사 건수) |
| ![전역 설정](docs/images/13-admin-global-config.png)<br>**전역 설정** — 초안, 게시 버전, 조건부 값 | ![운영 로그](docs/images/16-admin-ops-logs.png)<br>**운영 로그** — 시스템이 보낸 로그를 서비스·레벨로 조회 |
| ![테이블](docs/images/14-admin-tables.png)<br>**테이블** — 서비스별 컬렉션과 문서 | ![사용량](docs/images/15-admin-usage.png)<br>**사용량** — 로그·감사·문서 읽기·쓰기 일별 집계 |

### 밝게·어둡게, 휴대폰

| | | |
|---|---|---|
| ![대시보드 어둡게](docs/images/18-admin-dashboard-dark.png)<br>**대시보드 (어둡게)** | ![접근 권한 어둡게](docs/images/19-admin-access-matrix-dark.png)<br>**접근 권한 (어둡게)** | ![휴대폰 마이페이지](docs/images/21-mobile-mypage.png)<br>**마이페이지 (390px)** |

---

## 기능

### AI 정보 관리 — 시크릿과 payload

- 서비스마다 시크릿(키-값)을 등록한다. 값 형식은 `string`·`json`·`url`.
- 저장은 AES-256-CBC 암호화. 목록·수정 응답의 값은 언제나 `****`이고, 평문 조회는 최고관리자만 하며 조회 사실이 감사 기록에 남는다.
- 키마다 최소 계정등급(`guest`~`staff`), 최소 결제등급(`free`~`enterprise`), 최소 역할을 건다. 조건에 못 미치면 오류 없이 그 키만 `payload`에서 빠진다.
- 이름이 민감해 보이는 키(`*_api_key`, `token`, `secret` 등)는 게이트 없이 저장할 수 없다. 공개가 의도라면 `allow_public`을 명시해야 한다.
- `view` 단계 계정은 시크릿을 받지 않는다(`payload`가 `{}`).
- 시크릿 전달은 계정·서비스·시간 창 단위로 기록된다.

### 인증과 SSO

- 회원가입·로그인·비밀번호 찾기·이메일 인증·세션 관리·탈퇴.
- 계정등급·역할·결제등급 3축 권한. 결제 만료 시 강등.
- SSO: 복귀 주소는 서비스별 허용 목록과 정확히 같아야 한다. code는 1회용·60초, PKCE는 S256만 받는다.
- 공개 클라이언트(`service_secret` 없는 서비스)는 PKCE가 필수다. `code_challenge` 없이 발급된 code는 교환에서 거절된다.
- 무음 SSO(`/server/api/sso/authorize_session.php`): 이미 로그인한 브라우저 세션으로 code를 다시 발급한다. 공개 클라이언트에는 열지 않는다.
- 공개 클라이언트(네이티브 앱)는 RFC 8252 형식의 사용자 지정 scheme 복귀 주소(예: `myapp://auth`)를 쓸 수 있다.
- 로그인 실패 잠금, 레이트리밋, CSRF 토큰, `HttpOnly`·`SameSite` 쿠키.

### 가입 방식

- 설정 `register_mode`: `closed`(가입 막기) · `approval`(승인제) · `open`(바로 가입).
- 승인제에서 가입 API는 `202`와 접수번호를 돌려주고 계정을 아직 만들지 않는다. 승인 전 로그인은 `AUTH_ACCOUNT_PENDING`.
- 관리 콘솔 「가입 신청」에서 판단 자료를 보고 승인·거부한다(admin 이상, 감사 기록). 신청자는 `auth/register_request_status.php`로 결과를 조회한다.
- 남용 방지: IP당 대기 신청 수, 거부 뒤 재신청 대기 기간, 전체 대기열 상한.

### 데이터 독립

- 로그인 기록·가입 신청의 IP·User-Agent 원문은 최고관리자만 본다.
- manager 등급에게는 이메일·전화가 가려진 값으로 보인다.
- 없는 아이디로 실패한 입력은 최고관리자에게도 글자 수만 보인다(아이디 칸에 비밀번호를 친 경우를 감안).
- 사용자 소유(`owner`) 컬렉션의 문서 내용은 관리 콘솔에 `null`로 나온다.

### 계정 통합·연결

- 각 시스템이 `/server/api/sso/accounts_push.php`로 자기 계정 목록을 보낸다(서버 간 인증).
- 관리 콘솔 「계정 연결」에서 통합 계정과 묶거나, 새로 만들거나, 무시한다. 계획 → 확정 2단계 작업으로 처리하고 작업 기록이 남는다.
- 비상 계정 표지가 붙은 계정은 자동으로 묶이지 않는다.

### 접근 단계와 그룹

- 서비스 규칙: `all`(모두 사용) · `all_view`(모두 보기) · `granted`(허가된 계정만) · `admin`(관리자만).
- 그룹에 서비스 단계를 주고 구성원을 넣는다. 구성원과 서비스 부여 모두 시작·종료 시각을 둘 수 있다.
- 계정별 덮어쓰기는 허용(단계 지정 가능) 또는 거부. 거부는 그룹·규칙보다 우선한다.
- 서비스가 단계를 이해한다고 선언(`level_aware`)하지 않으면 `view` 계정은 입장할 수 없다. 읽기 전용 계정이 쓰기 권한으로 들어가는 일을 막는다.
- 판정 결과는 `auth/login`(service_id 지정 시)·`auth/exchange`·`auth/session`·`auth/refresh` 응답에 `access_level`(자기 서비스 단계)과 `access`(서비스별 단계 맵)로, `sso/userinfo`에는 `access`로 실린다. 기존 `services`(bool) 필드는 그대로 둔다.
- 다른 시스템이 공유 대상을 고를 때 쓰는 서버 간 계정 찾기 `sso/user_search.php`(아이디 완전 일치 또는 이름 앞 2자 이상, 그 서비스에 `view` 이상인 계정만, uuid·아이디·이름만 반환). 서비스별로 켜야 쓸 수 있고(`can_search_users`), 결과 상한·빈도 제한·하루 상한·감사가 걸린다.

### 공동 포인트 (서버 간)

- `points/s2s_balance.php` · `s2s_earn.php` · `s2s_spend.php` · `s2s_history.php`. 인증은 본문의 `service_id` + `service_secret`, 대상 계정은 그 서비스에 `use` 이상이어야 한다.
- 서비스마다 적립·사용 허용과 24시간 한도를 둔다. 적립·사용은 멱등 키가 필수이고, 감사 체인에 기록된다.
- 브라우저 앱의 `points/consume.php`와 같은 원장을 쓴다.
- 서비스 안의 세부 기능(`features`)은 서비스가 등록하고, ulogin은 저장·전달만 한다.

### 플랫폼 API

연결된 시스템이 공통으로 쓰는 서버 기능이다. 인증은 `service_secret`(서버 간) 또는 그 서비스의 사용자 토큰.

| 기능 | 엔드포인트 | 관리 콘솔 |
|---|---|---|
| 전역 설정 | `platform/remote_config.php` — etag가 같으면 `changed:false` | 초안 → 게시, 버전 비교, 되돌리기, 조건부 값 |
| 운영 로그 | `platform/logs.php` — 배치 수집, 민감 값 가림 | 서비스·레벨·이벤트·기간 조회 |
| 감사 기록 | `platform/audit.php` — 행마다 `row_hash = HMAC-SHA256(K, prev_hash ‖ 행)` | 체인 검사(어긋난 첫 순번 표시), 내보내기 |
| 문서 테이블 | `platform/collections.php` · `platform/docs.php` — 컬렉션별 읽기·쓰기 규칙, 색인 3개 | 서비스 → 컬렉션 → 문서 조회 |
| 사용량 | 자동 집계 | 일별·서비스별 표 |

관리 콘솔에서 한 조작(권한 변경, 설정 게시 등)도 같은 감사 체인에 들어간다.

### 비상 관리자

```bash
php server/tools/emergency_admin.php --init --apply          # 아이디 admin 으로 미설정 상태의 비상 계정 생성
php server/tools/emergency_admin.php --set-password --apply  # 비밀번호 설정 (표준입력으로 두 번)
php server/tools/emergency_admin.php --status                # 상태 확인 (값은 출력하지 않는다)
php server/tools/emergency_admin.php --open-link             # /local-admin 1회용 열쇠 링크(10분)를 만들어 브라우저로 연다
```

- 공개 웹 화면으로는 비밀번호를 정할 수 없다. 서버 PC의 셸(`--set-password`)이나, 셸이 만든 열쇠 링크로 여는 `/local-admin`에서만 정한다.
- `/local-admin`은 루프백 주소·루프백 Host·프록시 헤더 없음 세 조건을 모두 만족하고 열쇠가 맞을 때만 열린다. 열쇠는 한 번 쓰면 사라지고, 파일에는 열쇠의 HMAC만 남는다. Windows에서는 `tools/emergency-admin.bat open`이 같은 일을 한다.
- 관리자 비밀번호 정책(10자 이상, 흔한 비밀번호와 그 변형 거절)과 실패 잠금이 그대로 적용된다. 설정·로그인은 감사 기록에 남고 값은 남지 않는다.

---

## 빠른 시작

### 1. 공용 호스팅에 설치

1. 저장소의 `server/`, `public/`, `examples/` 세 폴더를 웹 루트(또는 하위 폴더)에 올린다. 그 위치가 **설치 루트**다.
2. 브라우저로 `{설치루트}/server/install.php`를 연다.
3. 설치기 첫 화면이 **설치 토큰 파일 위치**를 알려 준다(공용 호스팅: `server/storage/.install-token`). FTP로 그 파일을 열어 값을 입력한다.
4. 환경 점검 → DB(SQLite 또는 MySQL) → 스키마 → 사이트 보안 → 관리자 계정 → 첫 서비스 → 시크릿 → 완료 순서로 진행한다.
5. 끝나면 설치기는 스스로 잠긴다. 관리 콘솔은 `{설치루트}/public/admin.html`이다.

자세한 절차와 문제 해결은 [`INSTALL.md`](INSTALL.md)에 있다.

### 2. 로컬에서 바로 띄우기

```bash
php -S 127.0.0.1:8080 -t . tools/router-ulogin.php
```

`http://127.0.0.1:8080/server/install.php`로 설치한 뒤 `http://127.0.0.1:8080/login`으로 들어간다. 라우터는 `/public` 없는 주소(`/login`, `/admin`, `/mypage`)를 화면 파일로 이어 준다.

### 3. 스키마 갱신

```bash
php server/migrate.php status   # 적용되지 않은 마이그레이션 확인
php server/migrate.php apply    # 적용
php server/migrate.php verify   # schema.sql과 실제 DB 대조
```

---

## 연동 예시

### 브라우저 앱 — `ulogin-client.js`

```html
<script src="https://login.example.com/public/assets/ulogin-client.js"></script>
<script>
const ulogin = new UloginClient({
  server: 'https://login.example.com',                     // 설치 루트
  serviceId: 'demo-ai',                                    // 관리 콘솔에 등록한 service_id
  redirectUri: location.origin + '/callback.html'          // 허용 목록과 같은 문자열
});

async function ask(prompt) {
  if (!ulogin.isLoggedIn()) return ulogin.login();         // 로그인 화면으로 이동
  await ulogin.getSession();                               // 토큰 확인 + payload 다시 받기
  const p = ulogin.getPayload();                           // { openai_api_key, ai_model, ... }
  if (!p.openai_api_key) throw new Error('이 등급에서는 AI 기능을 쓸 수 없습니다');
  // p.openai_api_key 로 AI 호출. 값은 저장하지 않는다
  await ulogin.consumePoints(10, { reason: 'usage' });
}
</script>
```

복귀 페이지(`callback.html`)에서는 같은 설정으로 `ulogin.handleCallback()` 한 줄을 부른다. 동작하는 전체 예제는 [`examples/local-ai-app.html`](examples/local-ai-app.html)이다.

### 서버 간 — 운영 로그 보내기

```bash
curl -s -X POST https://login.example.com/server/api/platform/logs.php \
  -H 'Content-Type: application/json' \
  -d '{"service_id":"demo-notes","service_secret":"<서비스 시크릿>",
       "events":[{"event_id":"8f0c6c1e-2b7a-4d2e-9a51-3c1d2e4f5a6b","ts":"2026-11-16T09:00:00Z",
                  "level":"error","event":"job.export.failed","message":"내보내기 실패"}]}'
```

응답은 `{"ok":true,"data":{"accepted":1,"duplicate":0,...},"error":null}` 형태다. Node·Python·PHP 예제는 [`examples/platform/`](examples/platform/)에 있다.

---

## 문서

| 문서 | 내용 |
|---|---|
| [`INSTALL.md`](INSTALL.md) | 설치, 설치 후 보안 확인, 운영, 문제 해결 |
| [`AI_INTEGRATION_GUIDE.md`](AI_INTEGRATION_GUIDE.md) | AI 에이전트가 이 파일 하나만 읽고 연동 코드를 쓰도록 만든 자립형 가이드. 엔드포인트·응답·오류 코드 전체 |
| [`AI_SETTINGS_JSON_GUIDE.md`](AI_SETTINGS_JSON_GUIDE.md) | 설정 번들 JSON 작성 가이드(AI용) |
| [`examples/README.md`](examples/README.md) | 로컬 앱 연동 절차 |
| [`tests/README.md`](tests/README.md) | 회귀 시험 구성과 각 단계가 덮는 범위 |
| [`tools/README.md`](tools/README.md) | 라우터와 운영 도구 |
| [`CHANGELOG.md`](CHANGELOG.md) | 변경 이력 |
| [`docs/PORTFOLIO.md`](docs/PORTFOLIO.md) | 설계 배경과 검증 과정(v1.3.0 시점 기록) |

---

## 기술 스택과 요구 사항

| 항목 | 내용 |
|---|---|
| 언어 | PHP 7.2 이상(8.x 동작). 필요한 확장: PDO(`pdo_sqlite` 또는 `pdo_mysql`), OpenSSL, mbstring |
| 저장소 | SQLite 기본. MySQL·MariaDB는 `config.php`의 `DB_DRIVER`로 선택, 표 이름 접두 `app_ulogin_` |
| 의존성 | 없음. Composer·npm·빌드 단계 없음 |
| 화면 | HTML·CSS·Vanilla JS, research-ui 테마(밝게·어둡게, 팔레트 선택) |
| 웹 서버 | Apache(`.htaccess` 포함) 또는 `php -S` + `tools/router-ulogin.php` |

---

## 회귀 시험

```bash
php tests/run.php
```

| 단계 | 내용 |
|---|---|
| 티어 1 정적 | 응답 필드와 화면 사용 필드, 오류 코드 표, 링크, PHP 7.2 문법 등 교차 검사 |
| 티어 2 단위 | 판정 함수, 게이트 동치, SQLite 저장소, 라우터 |
| 티어 3 실서버 | 샌드박스 설치 + `php -S`로 실제 HTTP 왕복(동시성, 쿠키, 마이그레이션, 플랫폼 API) |
| 자체 점검 | 결함을 심은 견본에서 검사기가 실제로 걸리는지 확인 |

종료 코드는 `0` 통과 · `1` 실패 · `2` 인자 오류 · `3` 실행된 시험 없음 · `4` 러너 고장이다. 시험 DB를 설정하지 않으면 MySQL이 필요한 티어 3 일부가 건너뛰어지며, 무엇이 빠졌는지는 [`tests/README.md`](tests/README.md)에 표로 있다.

---

## 알려진 제한

- **2FA는 없다.** 컬럼만 있고 기능은 구현되지 않았다.
- **`SECRET_ENC_KEY` 교체 경로가 없다.** 키를 바꾸면 저장된 시크릿을 복호화할 수 없다.
- **세션 절대 수명과 HSTS 헤더가 없다.** refresh를 계속하면 세션이 이어진다. HSTS는 웹 서버 설정으로 붙여야 한다.
- **로그 보존 정책이 없다.** 로그인·관리·포인트·시크릿 전달 기록은 자동으로 줄지 않는다.
- **기밀 클라이언트에는 PKCE를 강제하지 않는다.** `service_secret`이 있는 서비스는 `code_challenge` 없이도 교환할 수 있다. 공개 클라이언트는 PKCE가 필수라서 `crypto.subtle`이 없는 `file://`에서는 연동할 수 없다(로컬 웹서버로 열어야 한다).
- **`docs/PORTFOLIO.md`와 `docs/screenshots/`는 v1.3.0 시점 기록이다.** 최신 화면은 `docs/images/`에 있다.

---

## 라이선스

[MIT](LICENSE)
