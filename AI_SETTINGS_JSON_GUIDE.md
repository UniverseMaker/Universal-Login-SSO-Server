# ulogin v1.6.0 — 설정 번들 JSON 작성 가이드 (AI 에이전트용 자립형 단일 문서)

> **이 문서의 독자는 사람이 아니라 다른 AI 에이전트입니다.**
> "ulogin 서버 설정을 JSON으로 만들어 줘"라는 요청을 받은 LLM이, **이 파일 하나만 읽고**
> 추가 질문 없이 **서버가 받아들이는 번들**을 만들 수 있도록 썼습니다.
> 다른 파일(`README.md`, `INSTALL.md`, `AI_INTEGRATION_GUIDE.md`, API 계약서, 소스 코드)을
> 열 필요가 없습니다. 필요한 내용은 전부 이 문서 안에 중복해서 적었습니다.
>
> 이 문서에 적힌 **오류 코드 18개 · 경고 코드 7개 · 필드 제약 · 정규식 · 상·하한은
> 전부 `server/api/admin/settings_bundle.php` 와 `server/core/` 의 실제 코드에서
> 뽑은 값입니다**(2026-09-03 실측). 추측한 값이 없습니다.
>
> **v1.6.0 마감 시점에 게이트 판정이 두 번 좁아졌습니다.** 이 문서 바로 다음 절
> 「게이트로 쓰면 서버가 거절하는 값」을 먼저 읽으세요 — v1.5.0까지 통과했던 두 형태가
> 이제 거절됩니다. 그 두 형태는 **당신이 낼 가능성이 가장 높은 형태**입니다.

---

## 이 문서가 맨 앞에 두는 세 문장

이 세 가지를 어기면 번들이 거절되거나, 더 나쁘게는 **사람이 의도하지 않은 결과**가 적용됩니다.

1. **import는 어떤 행도 삭제하지 않습니다. INSERT와 UPDATE만 합니다.**
   "번들에 없는 것은 지운다" 같은 모드는 **존재하지 않습니다.** 무언가를 은퇴시키려면
   `"status": "disabled"` 를 쓰세요. 지우는 방법을 찾지 마세요 — 없습니다.
2. **`secret_value` 는 항상 `null` 로 두세요. 실제 키 값은 사람이 채웁니다.**
   서버는 값을 받기는 하지만, 당신(AI)이 그 자리에 값을 적으면 **API 키가 AI 대화창을
   지나가게 됩니다.** 이 서버가 존재하는 이유가 정확히 그것을 막는 것입니다.
   자세한 이유와 2단계 절차는 [3절](#3-시크릿-값은-언제나-null-로-만드세요)에 있습니다.
3. **`allow_public` 필드를 쓰지 마세요. 필요해 보이면 사람에게 물으세요.**
   `gate_required` 오류를 없애는 지름길처럼 보이지만, 그것은 **민감한 API 키를 가입한
   모든 사용자에게 열어 주는** 필드입니다. 올바른 해법은 `min_billing_tier` 를 거는 것입니다.

---

## 게이트로 쓰면 서버가 거절하는 값 — 이 절을 먼저 읽으세요

**v1.6.0이 게이트 판정을 바꿨습니다.** 아래 두 형태는 **게이트처럼 보이는데 아무도 막지
않습니다.** 이제 서버가 `gate_required` 로 **거절합니다** — 그래서 이 절을 문서 맨 앞에
둡니다. 이 규칙을 모르면 당신은 같은 번들을 만들고 매번 거절당합니다.

### 금지 1. `basic` · `free` · `user` 를 게이트로 쓰지 마세요 — **각 축의 기본값입니다**

| 축 | 이 값을 게이트로 쓰면 안 됩니다 | 왜 |
|---|---|---|
| `min_account_level` | `"guest"` · **`"basic"`** | `basic` 은 `users.account_level` 의 스키마 기본값이고, 회원가입 코드가 신규 가입자에게 박는 값입니다. **가입한 전원이 이미 `basic` 입니다** |
| `min_billing_tier` | **`"free"`** | `users.billing_tier` 의 기본값이고, 결제가 만료되면 되돌아오는 값입니다 |
| `min_role` | **`"user"`** | `users.role` 의 기본값이고, 역할 판정이 실패했을 때의 fail-closed 기본값입니다 |

`basic` 은 랭크가 10이라 0이 아닙니다. **그래서 v1.5.0까지는 통과했습니다.** 통과했지만
아무도 막지 못했습니다 — `min_account_level: "basic"` 으로 게이트를 건 `ai_api_key` 가
신규 일반 회원의 로그인 응답에 평문으로 실렸습니다. 판정 기준이 「랭크 0 초과」에서
**「각 축의 기본값 초과」** 로 바뀐 이유입니다.

**쓸 수 있는 값:** `min_account_level` 은 `verified` 이상, `min_billing_tier` 는 `lite` 이상,
`min_role` 은 `manager` 이상. **판단 근거가 없으면 `"min_billing_tier": "lite"` 를 쓰세요.**

> **`min_account_level: "verified"` 를 기본으로 쓰지 마세요.** 기본 설치본에는 `verified`
> 회원이 한 명도 없어서 그 서비스의 **전 회원이 차단됩니다.** 서버가 오류 메시지에 그
> 사실을 함께 적어 줍니다.

### 금지 2. `min_*` 게이트를 같은 서비스의 `default_account_level` 이 통과시키는 조합

```json
{ "service_id": "my-chat", "service_name": "내 채팅",
  "default_account_level": "vip",
  "secrets": [ { "secret_key": "ai_api_key", "secret_value": null, "min_account_level": "vip" } ] }
```

**`vip` 는 기본값 `basic` 을 넘으므로 금지 1에는 걸리지 않습니다.** 그런데 이 서비스는
`default_account_level: "vip"` 를 **가입하는 회원에게 자동으로 보장**하므로, 가입한 전원이
`vip` 가 되어 게이트를 통과합니다. **두 필드가 서로 다른 자리에 있어서 사람도 잘 못
알아챕니다.** 이제 서버가 거절합니다.

**이 조합이 성립하는 조건 — 두 플래그 중 하나만 열려 있어도 됩니다:**

| 플래그 | 어떤 가입 경로를 여는가 | 스키마 기본값 |
|---|---|---|
| `auto_join` | 최초 로그인 시 자동 가입 | **`true`** |
| `allow_register` | `service_id` 를 동반한 회원가입 | **`true`** |

**둘 다 기본값이 `true` 입니다.** 그러므로 두 플래그를 아예 적지 않은 서비스도 이 조합에
걸립니다. `auto_join: false` 만 적어도 걸립니다 — `allow_register` 가 여전히 열려 있기
때문입니다. 두 플래그를 **모두 `false`** 로 닫으면 그 서비스에 회원이 붙지 않으므로
게이트가 유지되고, 번들은 통과합니다.

`status: "disabled"` 인 서비스도 판정 대상입니다. 지금은 가입을 받지 않지만 다음 번들이
`active` 로 되살릴 수 있고, 그때 게이트가 조용히 열려 있으면 안 됩니다(fail-closed).

**고치는 방법은 하나입니다 — `min_billing_tier` 를 `lite` 이상으로 옮기세요.**
`min_billing_tier` 와 `min_role` 은 `services` 표에 대응하는 `default_%` 컬럼이 없어서
**서비스 필드로 자동 부여될 수 없습니다.** 그래서 이 권유가 같은 함정으로 돌아오지 않습니다.

**`default_account_level` 을 낮추는 것으로 고치지 마세요.** 시크릿 하나를 통과시키려고
**그 서비스 전 회원의 등급 정책**을 바꾸는 것이고, 그 서비스의 다른 시크릿과 이미 가입한
회원이 받는 payload 까지 함께 움직입니다. 서버의 오류 메시지도 그 해법을 알려 주지 않습니다.

### 거절을 통과하는 유일한 방법 — 그리고 그 선언의 뜻

두 금지 모두 **하나의 오류(`gate_required`)와 하나의 선언**으로 모입니다.
선언은 **두 곳에 함께** 적어야 합니다:

1. 그 시크릿 객체에 `"allow_public": true`
2. **그리고** 번들 최상위 `acknowledge_public_secrets` 배열에 `"<service_id>/<secret_key>"`

**그 선언의 뜻은 이것입니다 —**

> **「이 서비스에 가입한 전원이 이 API 키를 받는다.」**

그것이 사실이기 때문에 서버가 통과시킵니다. 정당한 의도일 수 있지만, **그 판단은 당신이
하지 않습니다.** 당신이 할 일은 게이트를 옮기는 것이고, 그래도 공개가 필요하다면
**사람에게 물어 그 사람이 두 곳을 적게** 하는 것입니다.
자세한 것은 [4-5절](#4-5-allow_public-과-acknowledge_public_secrets--쓰지-마세요)에 있습니다.

---

## 0. 30초 요약 — 여기까지만 읽어도 유효한 번들을 만들 수 있습니다

### 최소 유효 번들은 한 줄입니다

```json
{ "ulogin_settings_bundle": "1.0" }
```

**필수 필드는 이것 하나뿐입니다.** 이 번들은 유효하고, 계획이 비고, 적용해도 아무것도
바뀌지 않습니다(무연산). 가장 흔한 실패가 **필수 필드를 오해해 과잉 생성하는 것**이므로
이것을 맨 앞에 둡니다.

### 실용 최소 번들 — 서비스 1개 + 게이트 걸린 시크릿 1개

```json
{
  "ulogin_settings_bundle": "1.0",
  "services": [
    {
      "service_id": "my-chat",
      "service_name": "내 AI 채팅",
      "redirect_whitelist": ["http://localhost:5173/callback.html"],
      "allowed_origins": ["http://localhost:5173"],
      "secrets": [
        { "secret_key": "ai_api_key", "secret_value": null, "min_billing_tier": "lite" }
      ]
    }
  ]
}
```

이 번들이 하는 일: `my-chat` 서비스를 만들고, 로컬 앱의 복귀 주소를 허용하고,
`ai_api_key` 라는 시크릿 **자리**를 만듭니다(값은 비어 있고 `status` 는 `disabled` 로
들어갑니다 — [4-4-2절](#4-4-2-secret_value-의-세-가지-상태) 참조).

### 당신이 도는 루프 (6단계 — 이 문서의 핵심 절차)

```
1. 번들을 만든다.
2. POST <<설치루트>>/server/api/admin/settings_bundle.php?action=validate 로 보낸다.
3. ok:true  → 사람에게 넘긴다.
   ok:false → data.errors 를 읽는다.   ※ error.errors 가 아니다. data.errors 다.
4. 각 항목의 path(JSON Pointer) 자리를 expected 에 맞게 고친다.
   did_you_mean 이 있으면 그 값을 그대로 쓴다.
5. 2로 돌아간다. data.errors 가 빌 때까지.
   3회 반복해도 같은 오류가 남으면 멈추고, 그 오류 항목을 그대로 사람에게 보여준다.
6. ?action=dry-run 과 ?action=apply 는 당신이 돌리지 않는다. 사람이 한다.
```

**왜 `validate` 만 당신이 돌려도 되는가:** `validate` 응답에는 **그 설치본에 관한 정보가
하나도 없습니다.** 기존 `service_id` 목록도, 어느 행이 이미 있는지도, DB 내용도 없습니다.
스키마·타입·범위만 봅니다(DB를 한 번도 읽지 않습니다). 그래서 **이 응답은 AI 대화창에
붙여넣어도 안전한 유일한 응답**입니다. `dry-run` 과 `apply` 는 설치본의 현재 상태를
응답에 담으므로 사람이 다뤄야 합니다.

**그리고 그 성질의 대가가 있습니다 — `validate` 는 최종 판정이 아닙니다.**
DB를 읽지 않으므로 두 가지를 **과소보고**합니다: `is_confidential` + `service_secret` 의
상호 의존, 그리고 **서비스의 `default_account_level` 이 게이트를 무력화하는 조합**
([12-2절](#12-2-validate-와-dry-run-의-판정-차이-3건)). **`ok: true` 를 「적용해도 된다」로
보고하지 마세요** — 사람에게 「`dry-run` 에서 게이트 오류가 더 나올 수 있습니다」를 함께
전달하세요.

### 자리표시자

| 자리표시자 | 무엇인가 | 어디서 얻는가 |
|---|---|---|
| `<<설치루트>>` | ulogin이 설치된 URL의 루트. 예: `https://example.com/ulogin` | 사람에게 물으세요. 관리자 콘솔 주소에서 `/public/admin.html` 을 뗀 것입니다 |
| `<<SUPERADMIN_ACCESS_TOKEN>>` | 최고관리자 계정의 access token | 사람이 관리자 콘솔에 로그인해 얻습니다. **당신이 로그인하지 않습니다** |

---

## 1. 이 기능의 정체 — 무엇이고 무엇이 아닌가

### 1-1. 무엇인가

ulogin은 로컬 HTML/AI 앱의 JavaScript에 API 키를 넣지 않기 위해, 로그인만 서버로 분리하고
**인증된 사용자에게만 서비스 시크릿을 내려주는** PHP + MySQL 인증 서버입니다.

이 문서가 다루는 것은 그 서버의 **설정을 JSON 한 파일로 넣고 빼는 기능**입니다.
관리자 콘솔에서 화면을 여러 번 오가며 하던 등록 작업을, **JSON 하나를 만들어 한 번에**
적용할 수 있게 합니다. 그 JSON을 만드는 것이 당신의 일입니다.

### 1-2. 무엇이 아닌가 — **이것은 "풀 셋팅"이 아닙니다**

**이 JSON으로 바꿀 수 없는 것이 있습니다. 숨기지 않고 적습니다.**

| 바꿀 수 없는 것 | 어디서 바꾸는가 |
|---|---|
| DB 접속 정보 (`DB_HOST` `DB_NAME` `DB_USER` `DB_PASS`) | `server/config.php` 직접 편집 또는 재설치 |
| 암호화 키 (`SECRET_ENC_KEY` `APP_KEY`) | 같음. **한 글자만 바뀌어도 저장된 전 시크릿이 영구히 복구 불가능해집니다** |
| HTTPS 강제·프록시 (`FORCE_HTTPS` `TRUST_PROXY` `TRUSTED_PROXIES`) | 같음 |
| 쿠키 설정 (`COOKIE_NAME` `COOKIE_SECURE` `COOKIE_SAMESITE` `COOKIE_PATH` `COOKIE_DOMAIN`) | 같음 |
| 서버 주소 (`BASE_URL`) · 테이블 접두 (`DB_PREFIX`) | 같음 |
| 약관 (`app_ulogin_terms`) | **아직 어디서도 편집할 수 없습니다.** [8절](#8-범위-밖--왜-빠졌는지까지-적습니다) |
| 회원 · 회원×서비스 매핑 | 관리자 콘솔 > 회원 |
| 세션 · 인증코드 · 각종 로그 | 이식 대상이 아닙니다 |
| 스키마 리비전 | `server/migrate.php` |

**바꿀 수 있는 것은 세 가지입니다.**

| 층 | 대상 | 이 문서의 절 |
|---|---|---|
| B | 전역 설정 13키 (`app_ulogin_settings`) | [4-2](#4-2-settings--전역-설정-13키) |
| C | 서비스 (`app_ulogin_services`) | [4-3](#4-3-services--서비스-15필드) |
| D | 서비스 시크릿 (`app_ulogin_service_secrets`) | [4-4](#4-4-servicessecrets--시크릿-11필드) |

### 1-3. 용어

| 용어 | 뜻 |
|---|---|
| **번들(bundle)** | 이 문서가 다루는 JSON 객체 전체. 최상위에 `ulogin_settings_bundle` 을 가집니다 |
| **서비스(service)** | 이 로그인 서버에 붙는 앱 하나. `service_id` 라는 문자열이 그 이름표입니다 |
| **시크릿(secret)** | 서비스가 로그인 성공 시 받아 가는 키/값. AI API 키가 대표적입니다 |
| **게이트(gate)** | 시크릿을 받으려면 넘어야 하는 최소 등급. `min_account_level` · `min_billing_tier` · `min_role` 세 축 |
| **payload** | 로그인 성공 시 클라이언트가 받는 시크릿 묶음. 게이트를 통과한 것만 담깁니다 |
| **plan(계획)** | `dry-run` 이 돌려주는, "무엇이 생기고 무엇이 덮이는가"의 행 단위 목록 |
| **`plan_hash`** | 그 계획의 지문. `apply` 가 **필수로** 받습니다. [9절](#9-절차--validate--dry-run--apply) |
| **멱등(idempotent)** | 같은 번들을 두 번 넣어도 두 번째는 아무것도 바꾸지 않습니다. 이 기능은 멱등입니다 |

### 1-4. 이 기능이 절대 하지 않는 일 — 안전 성질

| 성질 | 무엇을 뜻하는가 |
|---|---|
| **삭제 없음** | `plan.counts.deletes` 는 **항상 0**, `plan.deletes_possible` 은 **항상 `false`** 입니다. 값이 아니라 약속입니다 |
| **전부 아니면 전무** | `apply` 는 단일 DB 트랜잭션 안에서 돕니다. 중간에 실패하면 앞선 행도 남지 않습니다 |
| **멱등** | 같은 번들 2회차는 전부 `unchanged` 로 나오고 감사 로그도 남지 않습니다 |
| **계획 없는 적용 없음** | `apply` 는 `dry-run` 이 준 `expect_plan_hash` 없이는 호출할 수 없습니다 |
| **export는 시크릿 값을 절대 내보내지 않음** | 내보낸 파일의 `secret_value` 는 언제나 `null` 입니다 |

---

## 2. 엔드포인트 — 실제 호출 형태

**네 동작 전부 최소 역할이 `superadmin`(전역 role) 입니다.** `admin` 이 호출하면 네 동작 모두
403 `FORBIDDEN` 입니다.

| 동작 | 메서드 | 실제 URL | 응답 `data` 최상위 키 |
|---|---|---|---|
| export | `GET` | `<<설치루트>>/server/api/admin/settings_bundle.php` | `bundle`, `meta` |
| 검증 | `POST` | `<<설치루트>>/server/api/admin/settings_bundle.php?action=validate` | `valid`, `checked`, `warnings` |
| 계획 | `POST` | `<<설치루트>>/server/api/admin/settings_bundle.php?action=dry-run` | `valid`, `plan_hash`, `plan`, `checked` |
| 적용 | `POST` | `<<설치루트>>/server/api/admin/settings_bundle.php?action=apply` | `applied`, `plan_hash`, `result`, `warnings`, `admin_log_id` |

> **URL에 `.php` 확장자를 붙여 쓰세요.** `mod_rewrite` 가 동작하는 환경에서는 확장자를 뗀
> `/server/api/admin/settings_bundle` 도 동작하지만, **동작하지 않는 호스팅이 있습니다.**
> 확장자를 붙인 형태는 어디서나 동작합니다.
> **`?action=` 은 경로가 아니라 쿼리 파라미터입니다.** 경로에 붙이지 마세요.

### 2-1. 요청 본문의 모양 — **번들을 그대로 본문으로 보내지 마세요**

```json
{
  "bundle": { "ulogin_settings_bundle": "1.0" },
  "on_conflict": "merge",
  "expect_plan_hash": "(apply 에서만. dry-run 응답의 plan_hash 를 그대로)",
  "reason": "(선택. 255자 이내. 감사 로그 메모)"
}
```

| 본문 키 | 필수 | 어느 동작에서 | 설명 |
|---|---|---|---|
| `bundle` | **예** | validate · dry-run · apply | 번들 객체. 이것이 없으면 `bundle_not_recognized` |
| `on_conflict` | 아니오 | 셋 다 | `merge`(기본) \| `skip` \| `fail`. [9-3절](#9-3-on_conflict--기존-행을-만났을-때) |
| `expect_plan_hash` | **apply에서만 필수** | apply | dry-run 응답의 `plan_hash` 를 그대로 |
| `reason` | 아니오 | apply | 0~255자. 감사 로그에 남습니다 |

**`bundle` 로 한 겹 감싸는 이유:** `on_conflict` 같은 **실행 옵션**과 **번들 내용**을 섞으면
번들 파일에 실행 옵션이 스며들고, 그 파일을 다른 설치본에 넣을 때 의도치 않은 옵션이 따라갑니다.
`on_conflict` 는 편의상 번들 **안**에도 쓸 수 있지만, 둘 다 있으면 **요청 최상위가 이깁니다**
(명시적 조작이 파일보다 우선).

### 2-2. 응답 봉투 — 항상 이 모양입니다

성공:

```json
{ "ok": true, "data": { }, "error": null }
```

실패:

```json
{ "ok": false, "data": { }, "error": { "code": "FORBIDDEN", "message": "설정 번들은 최고관리자만 다룰 수 있습니다.", "field": null } }
```

> **검증 오류 배열은 `data.errors` 에 있습니다. `error.errors` 는 존재하지 않습니다.**
> 서버의 응답 조립기가 `error` 객체를 `{code, message, field}` 세 키로 **고정**하고
> 나머지를 `data` 로 옮기기 때문입니다. 이 자리를 틀리면 오류를 하나도 못 읽습니다.

### 2-3. curl 예시 — 손으로 먼저 확인하세요

```bash
# 1) 검증 (당신이 도는 루프)
curl -sS -X POST '<<설치루트>>/server/api/admin/settings_bundle.php?action=validate' \
  -H 'Authorization: Bearer <<SUPERADMIN_ACCESS_TOKEN>>' \
  -H 'Content-Type: application/json' \
  -d '{"bundle":{"ulogin_settings_bundle":"1.0"}}'
# 기대: {"ok":true,"data":{"valid":true,"checked":{...},"warnings":[]},"error":null}

# 2) 계획 (사람이 한다)
curl -sS -X POST '<<설치루트>>/server/api/admin/settings_bundle.php?action=dry-run' \
  -H 'Authorization: Bearer <<SUPERADMIN_ACCESS_TOKEN>>' \
  -H 'Content-Type: application/json' \
  -d @bundle-wrapped.json
# 기대: data.plan_hash 가 64자 16진 문자열로 나온다

# 3) 적용 (사람이 한다. 2)의 plan_hash 를 그대로 넣는다)
curl -sS -X POST '<<설치루트>>/server/api/admin/settings_bundle.php?action=apply' \
  -H 'Authorization: Bearer <<SUPERADMIN_ACCESS_TOKEN>>' \
  -H 'Content-Type: application/json' \
  -d '{"bundle":{ ... },"expect_plan_hash":"<<2)에서 받은 값>>"}'

# 4) 현재 설정 내보내기 (사람이 한다)
curl -sS '<<설치루트>>/server/api/admin/settings_bundle.php' \
  -H 'Authorization: Bearer <<SUPERADMIN_ACCESS_TOKEN>>'
# 기대: data.bundle 이 그대로 다시 import 가능한 번들이다. 시크릿 값은 전부 null 이다
```

> **`Authorization: Bearer` 로 호출하면 CSRF 토큰이 필요 없습니다.** 브라우저 쿠키 세션으로
> 호출하는 경우에만 CSRF 헤더가 필요합니다. 당신이 코드를 쓴다면 Bearer를 쓰세요.

### 2-4. 상한과 레이트리밋 — 코드에서 뽑은 실제 값

| 항목 | 값 | 넘으면 |
|---|---|---|
| 요청 본문 | 1,048,576 바이트 (1 MiB) | `body_too_large` |
| `services` 항목 수 | 100 | `too_many` |
| 서비스 하나의 `secrets` 수 | 100 | `too_many` |
| 번들 전체의 시크릿 수 | 500 | `too_many` |
| `redirect_whitelist` · `allowed_origins` 항목 수 | 각 50 | `too_many` |
| 위 두 배열의 각 항목 길이 | 500자 | `pattern_mismatch` |
| `acknowledge_public_secrets` 항목 수 | 20 | `too_many` |
| 한 응답에 담기는 오류 수 | 200 | `data.truncated: true` |
| `validate` · `dry-run` 호출 | 사용자당 **60회 / 1시간** | 429 `RATE_LIMITED` |
| `apply` 호출 | 사용자당 **10회 / 1시간** | 429 `RATE_LIMITED` |

**당신의 루프는 `validate` 버킷(60회/시간)을 씁니다.** 3회 반복 규칙을 지키면 넉넉합니다.

---

## 3. 시크릿 값은 언제나 `null` 로 만드세요

**이 절이 이 문서에서 가장 중요합니다.**

### 3-1. 비대칭 — export는 값을 주지 않고, import는 값을 받습니다

| 방향 | 시크릿 값 | 왜 |
|---|---|---|
| **export** (`GET`) | **절대 내보내지 않습니다.** `secret_value` 는 항상 `null` 이고, 값의 유무만 `value_present`(bool)로 알립니다 | 아래 |
| **import** (`apply`) | **받습니다.** `secret_value` 에 문자열을 적으면 그 값이 저장됩니다 | 값을 넣을 다른 방법이 이 경로와 관리자 콘솔뿐입니다 |

**의도된 비대칭입니다.** 실수나 불일치가 아닙니다.

### 3-2. export가 값을 주지 않는 이유 — 이 기능 자신의 사용 흐름이 유출 경로입니다

사람이 이 기능을 쓰는 흐름은 「설정을 내보낸다 → **그 파일을 AI 대화창에 붙여 넣고 고쳐
달라고 한다** → 다시 넣는다」입니다. 그 흐름이 이 기능의 존재 이유입니다.

export 파일에 평문 키가 들어 있으면, **그 순간 전 서비스의 AI API 키가 제3자 LLM으로
넘어갑니다.** 이 서버는 **"AI API 키를 JS 소스에서 없애기 위해"** 존재합니다. 그 약속을
지키는 시스템이 **키를 글자로 담은 파일을 운영자 다운로드 폴더에 만들어 주는 것**은
정면 충돌입니다.

그래서 export는 값을 담지 않습니다. **그 결과 export 파일은 AI 대화창에 붙여도 안전합니다.**
그 성질이 이 문서를 성립시키는 전제입니다.

### 3-3. 그래서 당신이 할 일 — 2단계 절차

**1단계 (당신):** `secret_value` 를 `null` 로 둔 번들을 만듭니다.

```json
{
  "secret_key": "ai_api_key",
  "secret_value": null,
  "value_type": "string",
  "min_billing_tier": "lite",
  "description": "OpenAI 호환 API 키. 값은 사람이 넣는다"
}
```

**2단계 (사람):** 둘 중 하나를 고릅니다. **이 선택지를 사람에게 그대로 보여주세요.**

| 방법 | 절차 | 대가 |
|---|---|---|
| (a) 번들에 값을 적어 넣는다 | 사람이 자기 편집기에서 `"secret_value": null` 을 `"secret_value": "sk-…"` 로 바꾸고, 그 파일을 관리자 콘솔의 import 화면에 올립니다 | **그 JSON 파일 자체가 평문 키를 담은 파일이 됩니다.** 다 쓰면 지워야 합니다. 그 파일을 AI에게 다시 보여주면 안 됩니다 |
| (b) 관리자 콘솔에서 손으로 채운다 | 값을 비운 채 import → 관리자 콘솔 > 서비스 > (해당 서비스) > 시크릿 에서 값을 넣고 `status` 를 `active` 로 바꿉니다 | 두 단계지만 **키가 파일에도 대화창에도 남지 않습니다** |

**당신은 (b)를 권하세요.** 그리고 **어느 쪽이든 값을 당신이 만들어 내지 마세요** — 당신은
그 사람의 실제 API 키를 알 수 없고, 그럴듯한 문자열을 지어내면 서버는 그것을 받아들여
저장하고 연동 앱은 **인증 실패를 원인 불명으로** 겪습니다.

### 3-4. 값이 없는 시크릿은 어떻게 되나요

**신규로 만들어지되 `status` 가 `disabled` 로 강제됩니다.** 그리고:

- 계획·결과의 `secrets_pending_value[]` 에 「채워야 할 목록」으로 올라옵니다
- `secret_forced_disabled` **경고**가 나옵니다(오류가 아닙니다 — 적용은 됩니다)
- 번들에 `"status": "active"` 를 적어도 무시되고 `disabled` 가 됩니다. 그 사실을 경고가 알립니다

**왜 강제하는가:** 값이 빈 `active` 시크릿은 로그인 응답의 payload에서 **조용히 빠집니다.**
서버는 200을 돌려주고 연동 앱에는 "키가 없다"로만 보이며, 원인은 서버 오류 로그 한 줄에만
남습니다. `disabled` 는 같은 결과를 **의도된 상태로** 만듭니다.

**기존 행에 대해서는 다릅니다.** 이미 값이 있는 시크릿에 `secret_value: null` 을 보내면
**값을 건드리지 않습니다.** 게이트·설명·정렬만 갱신합니다. 그래서 export한 파일(값이 전부
`null`)을 그대로 다시 import해도 **기존 값이 지워지지 않습니다.**

---

## 4. 전체 스키마

### 4-1. 최상위

```
{
  "ulogin_settings_bundle": "1.0",          필수  포맷 판별자 + 스키마 버전
  "on_conflict": "merge",                   선택  merge|skip|fail   기본 merge
  "settings": { ... },                      선택  전역 설정 13키
  "services": [ ... ],                      선택  서비스 배열
  "acknowledge_public_secrets": [ ... ],     선택  거의 쓰지 마세요 (4-5절)
  --- 아래 5개는 export 가 넣고 import 가 받아서 "무시" 하는 예약 키입니다 ---
  "generated_at": "2026-09-03T04:11:07Z",
  "generator": "ulogin 1.6.0",
  "environment": { ... },
  "excluded": { ... },
  "_notes": [ ... ]
}
```

| 키 | 필수 | 타입 | 허용값 / 제약 | 기본값 |
|---|---|---|---|---|
| `ulogin_settings_bundle` | **예** | string | 정확히 `"1.0"` | — |
| `on_conflict` | 아니오 | string | `merge` \| `skip` \| `fail` | `merge` |
| `settings` | 아니오 | object | 13키. 있는 키만 적용 | 없으면 설정 변경 없음 |
| `services` | 아니오 | array of object | 최대 100 | 없으면 서비스 변경 없음 |
| `acknowledge_public_secrets` | 아니오 | array of string | `"<service_id>/<secret_key>"`. 최대 20 | `[]` |
| `generated_at` `generator` `environment` `excluded` `_notes` | 아니오 | — | **받아서 무시합니다** | — |

**최상위 규칙 4가지**

| 규칙 | 내용 |
|---|---|
| 필수 키 | `ulogin_settings_bundle` **하나뿐**입니다 |
| 최소 유효 번들 | `{"ulogin_settings_bundle":"1.0"}` — 유효하며 아무것도 바꾸지 않습니다 |
| 모르는 키 | **오류 `unknown_key`.** 위 예약 키 5개만 예외입니다. **조용히 무시하지 않습니다** |
| `settings` 와 `services` 가 둘 다 없어도 | **유효합니다.** 계획이 비고 적용은 무연산입니다. 오류가 아닙니다 |

**버전 필드가 `"version"` 이 아니라 `ulogin_settings_bundle` 인 이유:** 이 키 하나가
**포맷 판별자를 겸합니다.** 아무 JSON 파일을 넣었을 때 필드 하나하나에서 실패하는 대신
`bundle_not_recognized` 하나로 끝납니다.

| `ulogin_settings_bundle` 의 값 | 처리 |
|---|---|
| `"1.0"` | 처리합니다 |
| 키 자체가 없음 | `bundle_not_recognized`. **다른 검증을 시작하지 않습니다** |
| `"1.1"` 등 같은 메이저의 상위 마이너 | `bundle_version_unsupported`. **거절합니다** — 조용히 하위 처리하지 않습니다 |
| `"2.0"` 등 | `bundle_version_unsupported` |
| 문자열이 아님 | `wrong_type` |

### 4-2. `settings` — 전역 설정 13키

**전부 선택입니다. 적은 키만 적용됩니다.** 적지 않은 키는 건드리지 않습니다.

`기본값` 칸은 **DB에 행이 없을 때 서버가 읽는 `config.php` 상수의 기본 배포값**입니다.
설치본이 그 상수를 고쳤다면 실제 폴백은 다를 수 있습니다.

| 키 | 타입 | 최소 | 최대 | 허용값 | 폴백 상수 | 기본값 |
|---|---|---|---|---|---|---|
| `allow_register` | boolean | — | — | `true` \| `false` | `ALLOW_REGISTER` | `true` |
| `require_email_verify` | boolean | — | — | `true` \| `false` | `REQUIRE_EMAIL_VERIFY` | `false` |
| `signup_bonus_points` | integer | 0 | 1000000 | — | `SIGNUP_BONUS_POINTS` | `100` |
| `login_fail_threshold` | integer | 1 | 100 | — | `LOCKOUT_THRESHOLD` | `5` |
| `login_lock_minutes` | integer | 1 | 1440 | — | `LOCKOUT_MINUTES` | `15` |
| `password_min_length` | integer | 8 | 64 | — | `PASSWORD_MIN_LENGTH` | `8` |
| `session_ttl_default` | integer | 60 | 604800 | — | `SESSION_TTL` | `3600` |
| `refresh_ttl_default` | integer | 300 | 31536000 | — | `REFRESH_TTL` | `1209600` |
| `maintenance_mode` | boolean | — | — | `true` \| `false` | `MAINTENANCE_MODE` | `false` |
| `mail_driver` | string | 1자 | 100자 | `mail` \| `log` | `MAIL_DRIVER` | `log` |
| `site_name` | string | 1자 | 100자 | — | `SITE_NAME` | `ulogin` |
| `auth_code_ttl` | integer | 30 | 600 | — | `AUTH_CODE_TTL` | `60` |
| `secret_access_log` | boolean | — | — | `true` \| `false` | `SECRET_ACCESS_LOG` | `true` |

**타입 주의 — 가장 흔한 실수:**

- boolean은 JSON의 `true` / `false` 입니다. **문자열 `"true"` 는 `wrong_type` 으로 거절됩니다.**
- integer는 JSON의 숫자입니다. **문자열 `"3600"` 은 `wrong_type` 입니다.**
- 소수(`3600.5`)도 integer가 아니므로 `wrong_type` 입니다.

**세 키는 값을 바꾸면 경고가 붙습니다** — 오류가 아니지만, 사람이 반드시 봐야 하는 값입니다.
`maintenance_mode` 를 `true` 로 · `allow_register` 를 `false` 로 · `secret_access_log` 를
`false` 로 바꾸는 경우입니다. [5절](#5-경고-코드--닫힌-7개-전수) 참조.
**요청받지 않았다면 이 세 키를 번들에 넣지 마세요.**

### 4-3. `services[]` — 서비스 15필드

| 필드 | 타입 | 필수 | 제약 | 신규 생성 시 기본값 |
|---|---|---|---|---|
| `service_id` | string | **예** | 정규식 `^[a-z0-9][a-z0-9\-_]{2,63}$` (소문자·숫자로 시작, 3~64자) | — (멱등 키) |
| `service_name` | string | **예** | 1~100자 | — |
| `description` | string \| null | 아니오 | 0~255자 | `null` |
| `is_confidential` | boolean | 아니오 | — | `false` |
| `service_secret` | string | 조건부 | 8~255자. `is_confidential: true` 이면 필요 | 없음 |
| `redirect_whitelist` | array of string | 아니오 | 절대 http(s) URL. **쿼리스트링·프래그먼트 금지.** 각 500자, 최대 50개 | `[]` |
| `allowed_origins` | array of string | 아니오 | `scheme://host[:port]` 만. **경로 금지.** 리터럴 `"null"` 허용. 각 500자, 최대 50개 | `[]` |
| `auto_join` | boolean | 아니오 | — | `true` |
| `allow_register` | boolean | 아니오 | — | `true` |
| `default_account_level` | string \| null | 아니오 | `guest` \| `basic` \| `verified` \| `vip` \| `staff` | `null` |
| `session_ttl` | integer | 아니오 | 60 ~ 604800 | `3600` |
| `refresh_ttl` | integer | 아니오 | 300 ~ 31536000 | `1209600` |
| `payload_fields` | array of string \| null | 아니오 | 각 항목 `^[a-z0-9_]{1,50}$`. `null` = 기본셋 | `null` |
| `status` | string | 아니오 | `active` \| `disabled` | `active` |
| `secrets` | array of object | 아니오 | 최대 100개 | `[]` |

**추가로 받아서 무시하는 키 1개:** `has_secret`. export가 넣는 정보용 필드입니다.
export한 파일을 그대로 다시 import할 수 있게 하려고 예약해 두었습니다. **당신이 쓰지 마세요.**

**`service_id` 는 바꿀 수 없습니다.** 다른 테이블이 이 문자열로 서비스를 참조하기 때문입니다.
번들은 `service_id` 로 기존 행을 **찾을** 뿐이고, 바꾸는 방법을 제공하지 않습니다.
이름을 바꾸고 싶다면 `service_name` 을 바꾸세요.

**`redirect_whitelist` 를 반드시 채우세요.** 복귀 주소는 **완전 일치 비교**입니다.
비어 있으면 그 서비스로는 SSO 로그인이 절대 완료되지 않습니다(경고가 나갑니다).
앱의 콜백 페이지 URL을 있는 그대로, 쿼리 없이 적으세요.

**`allowed_origins` 의 `"null"`:** `file://` 로 연 HTML 페이지는 브라우저가 `Origin: null` 을
보냅니다. 그 경우를 허용하려면 리터럴 문자열 `"null"` 을 배열에 넣습니다. **단 그것은
모든 로컬 파일에 문을 여는 것이므로, 개발 중에만 쓰고 사람에게 알리세요.**

**`is_confidential` 과 `service_secret` 의 상호 의존:**
`is_confidential: true` 인데 번들에도 `service_secret` 이 없고 **DB에도 기존 값이 없으면**
`mutual_dependency` 오류입니다. 서버 대 서버로 토큰을 교환할 때 이 값으로 클라이언트를
검증하기 때문에, 없으면 로그인이 완료될 수 없습니다.
**주의:** 이 판정은 DB를 봐야 하므로 `validate` 에서는 나오지 않습니다. [12-2절](#12-2-validate-와-dry-run-의-판정-차이-3건) 참조.

### 4-4. `services[].secrets[]` — 시크릿 11필드

| 필드 | 타입 | 필수 | 제약 | 신규 생성 시 기본값 |
|---|---|---|---|---|
| `secret_key` | string | **예** | 정규식 `^[a-z0-9][a-z0-9_]{1,99}$` (소문자·숫자로 시작, 2~100자. **하이픈 불가**) | — (서비스 안의 멱등 키) |
| `secret_value` | string \| null | 아니오 | `value_type` 에 따라 추가 검증 | `null` — **당신은 언제나 `null`** |
| `value_type` | string | 아니오 | `string` \| `json` \| `url` | `string` |
| `min_account_level` | string \| null | 아니오 | `guest` \| `basic` \| `verified` \| `vip` \| `staff` | `null` |
| `min_billing_tier` | string \| null | 아니오 | `free` \| `lite` \| `pro` \| `enterprise` | `null` |
| `min_role` | string \| null | 아니오 | `user` \| `manager` \| `admin` \| `superadmin` | `null` |
| `is_masked` | boolean | 아니오 | — | `true` |
| `allow_public` | boolean | 아니오 | **쓰지 마세요.** [4-5절](#4-5-allow_public-과-acknowledge_public_secrets--쓰지-마세요) | `false` |
| `sort_order` | integer | 아니오 | -9999 ~ 9999 | `0` |
| `description` | string \| null | 아니오 | 0~255자 | `null` |
| `status` | string | 아니오 | `active` \| `disabled` | 값이 있으면 `active`, 없으면 **`disabled` 강제** |

**추가로 받아서 무시하는 키 1개:** `value_present`. export가 넣는 정보용 필드입니다.
**당신이 쓰지 마세요.**

#### 4-4-1. `value_type` 별 추가 검증

| `value_type` | `secret_value` 에 요구되는 것 |
|---|---|
| `string` | 없음 |
| `json` | `JSON.parse` 가 성공해야 합니다. 리터럴 `"null"` 도 통과합니다 |
| `url` | `http` 또는 `https` 로 시작하는 절대 URL이어야 합니다 |

`secret_value` 가 `null` 이면 이 검증은 아예 돌지 않습니다 — 당신이 `null` 을 쓰는 한
이 오류를 만날 일이 없습니다.

#### 4-4-2. `secret_value` 의 세 가지 상태

| 번들의 `secret_value` | 기존 행이 **없을** 때 | 기존 행이 **있을** 때 |
|---|---|---|
| 문자열 (예: `"sk-…"`) | 행을 만들고 값을 암호화해 저장. `status` 는 번들 값(기본 `active`) | 값이 다르면 갱신, 같으면 `unchanged` |
| `null` 또는 키 자체가 없음 | 행을 만들고 **값은 비우고 `status` 를 `disabled` 로 강제.** `secrets_pending_value[]` 에 오르고 `secret_forced_disabled` 경고 | **값을 건드리지 않습니다.** 게이트·설명·정렬만 갱신 |

#### 4-4-3. 게이트 — 민감한 키에는 반드시 등급 제한을 거세요

**서버는 `secret_key` 의 이름을 보고 민감한 키인지 스스로 판정합니다.**
이름을 `_` `-` `.` 로 나눈 **낱말** 중 하나라도 아래에 해당하면 민감합니다(대소문자 무시):

```
apikey  apikeys  key  keys  secret  secrets  token  tokens
password  passwords  passwd  credential  credentials  sk
```

| 민감하다고 판정되는 이름 | 민감하지 않은 이름 |
|---|---|
| `ai_api_key` `openai_key` `claude_key` `anthropic_key` `private_key` | `api_base` |
| `api_key` `apikey` `api_key_v2` `ai_api_key_prod` | `model` |
| `secret_key` `x_secret` `client-secret` | `endpoint` |
| `refresh_token` `sk_live` `x-api-key` | `org_id` `openai_org` |
| `db_password` `passwd` `aws_credentials` | `keyring` (낱말이 `keyring` 이라 해당 없음) |

**민감한 키에는 게이트가 최소 하나 있어야 합니다.** 없으면 `gate_required` 오류입니다.

**게이트로 인정되는 값과 인정되지 않는 값 — 이것을 틀리는 일이 가장 많습니다.**

**판정 기준은 랭크가 0인지가 아니라, 그 축의 「모집단 기본값」을 넘는지입니다.**
기본값이란 신규 가입자가 그 자리에 갖게 되는 값입니다 — 즉 **전원이 이미 갖고 있는 값**이라
그 값으로 게이트를 걸면 아무도 막지 못합니다.

| 축 | 기본값 (게이트로 **인정되지 않음**) | 기본값 이하 (같이 인정되지 않음) | 게이트로 인정되는 값 |
|---|---|---|---|
| `min_account_level` | **`basic`** (랭크 10) | `guest` (0) | `verified`(20) · `vip`(30) · `staff`(90) |
| `min_billing_tier` | **`free`** (랭크 0) | — | `lite`(10) · `pro`(20) · `enterprise`(30) |
| `min_role` | **`user`** (랭크 0) | — | `manager`(10) · `admin`(20) · `superadmin`(30) |

**`null` · 빈 문자열 · 목록에 없는 값도 게이트가 아닙니다.** 모르는 열거값은 랭크 0으로
취급되어 어떤 기본값도 넘지 못합니다(fail-closed). `"min_account_level": "BASIC"` 처럼
대문자로 쓴 것도 모르는 값이므로 게이트가 아닙니다 — 그런데 그 값은 `not_in_enum` 으로
먼저 걸립니다.

**`"min_billing_tier": "free"` 는 게이트가 아닙니다.** 랭크 0이라 "제한 없음"과 결과가 같습니다.
**`"min_account_level": "basic"` 도 게이트가 아닙니다.** 랭크는 10이지만 전 회원의 기본값입니다.
둘 다 게이트를 건 것처럼 보이지만 **가입한 전원이 통과합니다.** 그래서 서버는
그것을 `gate_required` 로 거절합니다.

**그리고 게이트 값이 기본값을 넘어도 무력화될 수 있습니다** — 같은 서비스의
`default_account_level` 이 그 등급을 자동으로 보장하는 경우입니다. 아래 4-4-4절.

**권장 기본:** 판단 근거가 없으면 `"min_billing_tier": "lite"` 를 쓰세요.
그 다음 사람에게 "이 키를 어느 등급부터 받게 할지" 물으세요.

**`min_account_level` 로 게이트를 걸지 마세요 — 두 방향으로 다 틀립니다.**
`basic` 은 아무도 막지 않고, `verified` 는 (기본 설치본에 `verified` 회원이 없으므로)
전원을 막습니다. 서버의 오류 메시지가 그 두 사실을 함께 알려 줍니다.

#### 4-4-4. 게이트가 서비스 필드로 무력화되는 조합 — 서버가 거절합니다

`services[].default_account_level` 은 **그 서비스에 가입하는 회원에게 그 등급을 자동으로
보장**합니다(전역 등급보다 높을 때만 부여하는 grant-only 오버라이드이고, 유효 등급 계산이
그 오버라이드를 전역값보다 먼저 읽습니다).

그래서 아래 조합은 **게이트가 걸려 있는데 그 서비스 가입자 전원이 통과합니다:**

```
services[].default_account_level            의 랭크  >=  secrets[].min_account_level 의 랭크
  그리고
services[].auto_join == true  또는  services[].allow_register == true   (둘 다 기본값 true)
  그리고
그 시크릿에 다른 축의 실효 게이트가 없다 (min_billing_tier / min_role)
```

이 세 줄이 동시에 참이면 `gate_required` 로 **거절됩니다.**

| 판정에 들어가는 것 | 판정에 들어가지 않는 것 |
|---|---|
| 번들이 적은 `default_account_level` · `auto_join` · `allow_register` | `status` (`disabled` 여도 판정합니다 — 다음 번들이 되살릴 수 있습니다) |
| **번들이 그 필드를 생략하면 DB의 현재 값** | `min_billing_tier` · `min_role` (자동 부여 경로가 없습니다) |
| 신규 서비스라면 스키마 기본값 (`auto_join`·`allow_register` = `true`, `default_account_level` = `null`) | 다른 서비스의 필드 |

**`on_conflict: "skip"` 으로 이 판정을 피할 수 없습니다.** `skip` 이면 서비스 필드가
적용되지 않으므로 실효값은 DB 값이 되고, 판정은 그 DB 값으로 계속 돕니다.

**고치는 방법:** `min_billing_tier` 를 `lite` 이상으로 옮기세요.
**`default_account_level` 을 낮추지 마세요** — 그 서비스 전 회원의 등급 정책이 바뀝니다.

### 4-5. `allow_public` 과 `acknowledge_public_secrets` — 쓰지 마세요

이 둘은 **민감한 키를 게이트 없이 전원에게 여는** 장치입니다. 다음 조건을 **전부** 만족해야
통과합니다:

1. 시크릿 객체에 `"allow_public": true`
2. **그리고** 번들 최상위 `acknowledge_public_secrets` 배열에 `"<service_id>/<secret_key>"`

한 곳만 적으면 `gate_required` 로 거절됩니다. 두 곳 다 적으면 통과하되
`sensitive_secret_public` **경고**가 나옵니다.

**이 선언은 「실효 게이트가 없는 민감 키」 전부에 대한 하나의 선언입니다.**
게이트를 아예 비운 경우, 기본값(`basic`/`free`/`user`)으로 건 경우,
`default_account_level` 이 무력화한 경우 — **세 경우가 모두 같은 오류(`gate_required`)를 내고
같은 선언으로만 통과합니다.** 새 키나 새 확인 파라미터는 없습니다.

**그 선언이 사실로 말하는 것:**

> **이 서비스에 가입한 전원이 이 키를 받는다.**

세 경우가 실제로 뜻하는 사실이 그것이기 때문에 하나로 모았습니다.
그리고 이 선언만이 DB에 `allow_public = 1` 을 남기고, **그 표시가 관리자 콘솔의 「게이트
점검」 화면이 실효 공개 시크릿을 찾는 유일한 근거**입니다. 다른 방법으로 통과시키면 그 행이
`allow_public = 0` 으로 남아 **점검 화면에 뜨지 않습니다** — 그것이 이 라운드에 이 판정을
Critical로 올린 이유입니다.

**두 곳에 적게 만든 이유는 당신 때문입니다.** 오류 메시지를 읽고 가장 짧은 통과 경로를 택하는
습성이 있고, 한 곳이면 그 습성만으로 게이트가 벗겨집니다. 두 곳이면 **번들 전체를 보고
사람이 의도적으로 적어야** 합니다.

**규칙: 이 필드가 필요하다고 느끼면, 그것은 게이트를 걸어야 한다는 신호입니다.**
`min_billing_tier` 를 `lite` 이상으로 설정하세요. 그래도 공개가 필요하다면 **당신이 결정하지
말고 사람에게 물으세요.**

`acknowledge_public_secrets` 에 항목을 적었는데 대응하는 `allow_public: true` 가 없으면
`acknowledge_unused` **경고**입니다(오류가 아닙니다 — 게이트를 채운 뒤 목록 정리를 잊은
경우이고, 그것은 안전한 방향입니다).

---

## 5. 경고 코드 — 닫힌 7개 전수

**경고는 거절이 아닙니다.** 경고가 있어도 번들은 유효하고 적용됩니다.
`validate` 응답의 `data.warnings[]`, `dry-run` 의 `data.plan.warnings[]`,
`apply` 의 `data.warnings[]` 에 담깁니다.

경고 항목의 모양:

```json
{ "path": "/services/0/secrets/0", "code": "secret_forced_disabled", "message": "…" }
```

**오류와 경고의 차이 — 기계가 판정하는 방법:**

| | 오류 | 경고 |
|---|---|---|
| 어디에 | `data.errors[]` | `data.warnings[]` (dry-run은 `data.plan.warnings[]`) |
| HTTP | 400 (`ok: false`) | 200 (`ok: true`) |
| 결과 | **아무것도 적용되지 않음** | 적용됨 |
| 당신이 할 일 | 고쳐서 다시 `validate` | **고치지 마세요.** 사람에게 그대로 전달 |

| # | `code` | 언제 나오는가 | 어느 동작에서 |
|---|---|---|---|
| 1 | `maintenance_mode_on` | `maintenance_mode` 가 `false` → `true` 로 **바뀝니다.** 적용 직후 `manager` 미만 전원이 503을 받습니다 | dry-run · apply |
| 2 | `registration_disabled` | `allow_register` 가 `true` → `false` 로 바뀝니다. 이후 신규 가입이 거부됩니다 | dry-run · apply |
| 3 | `secret_audit_off` | `secret_access_log` 가 `true` → `false` 로 바뀝니다. **누가 언제 어떤 키를 받아 갔는지 더 이상 남지 않습니다** | dry-run · apply |
| 4 | `secret_forced_disabled` | 값 없는 **신규** 시크릿을 `disabled` 로 만듭니다 | dry-run · apply |
| 5 | `sensitive_secret_public` | 민감한 키가 이중 확인을 거쳐 게이트 없이 공개됩니다. 원인이 `default_account_level` 무력화였다면 `message` 끝에 그 사실이 괄호로 붙습니다 | validate · dry-run · apply |
| 6 | `empty_redirect_whitelist` | `active` 서비스의 `redirect_whitelist` 가 비어 있습니다. 그 서비스로는 SSO가 완료될 수 없습니다 | validate · dry-run · apply |
| 7 | `acknowledge_unused` | `acknowledge_public_secrets` 항목에 대응하는 `allow_public: true` 가 없습니다 | validate · dry-run · apply |

**1~4번은 `validate` 에서 나오지 않습니다.** "바뀌는가"를 판정하려면 현재 DB 값을 읽어야 하고,
`validate` 는 DB를 읽지 않기 때문입니다. `dry-run` 이 최종 판정입니다.

> **`environment` 불일치는 경고 코드가 아닙니다.** 경고 코드 집합을 7개로 닫아 두기 위해
> **별도 블록** `plan.environment_check` 로 나갑니다. [10절](#10-environment-대조--다른-설치본의-번들인가) 참조.

> **게이트 무력화에는 별도 경고 코드가 없습니다.** 그 조합은 **오류(`gate_required`)** 이므로
> 경고로 나올 자리가 없습니다. 남는 경우는 「비민감 키 + 무력화된 게이트」 하나인데,
> 비민감 키는 **게이트가 아예 없어도 아무 신호가 없습니다** — 그 자리만 경고하면 없는 걱정을
> 만듭니다. 그래서 경고 코드는 이 릴리스에서 늘지 않았고 **7개 그대로**입니다.
> 다른 문서나 낡은 응답에서 `gate_defeated_by_default_level` 같은 코드를 봤다면 그것은
> 개발 중간 상태이고, 배포된 v1.6.0 서버는 그 코드를 내지 않습니다.

---

## 6. 오류 코드 — 닫힌 18개 전수

**"기타 오류" 항목이 없습니다.** 아래 18개가 검증기가 낼 수 있는 코드의 전부입니다.
이 밖의 코드를 받았다면 그것은 이 문서보다 새로운(또는 다른) 서버입니다.

### 6-1. 오류 항목의 모양

```json
{
  "path": "/services/0/secrets/1/min_billing_tier",
  "code": "not_in_enum",
  "message": "min_billing_tier 값이 올바르지 않습니다.",
  "expected": "one of: free, lite, pro, enterprise, null",
  "got": "premium",
  "got_type": "string",
  "did_you_mean": "pro"
}
```

| 필드 | 항상 있나 | 의미 |
|---|---|---|
| `path` | 예 | **JSON Pointer (RFC 6901).** 번들 루트 기준. `~` 는 `~0`, `/` 는 `~1` 로 이스케이프됩니다 |
| `code` | 예 | 아래 18개 중 하나 |
| `message` | 예 | 사람이 읽는 한국어 한 문장 |
| `expected` | 예 | **기계 판독 가능한 기대 문법.** 값 도메인을 전부 적습니다 |
| `got` | 예 | 실제 값. 200자에서 잘립니다. **`secret_value` 자리에서는 언제나 `"***"`** |
| `got_type` | 예 | `string` \| `integer` \| `number` \| `boolean` \| `array` \| `object` \| `null` \| `missing` |
| `did_you_mean` | 아니오 | 오타 후보. 편집 거리 2 이하일 때만. **있으면 그 값을 그대로 쓰세요** |
| `got_truncated` | 아니오 | `got` 을 잘랐는가 |

**`path` 를 그대로 따라가면 고칠 자리를 정확히 찾을 수 있습니다.**
`/services/2/secrets/0/min_billing_tier` = `bundle["services"][2]["secrets"][0]["min_billing_tier"]`.

**오류는 첫 건에서 멈추지 않고 전량 수집됩니다.** 12개가 틀린 번들은 12건을 한 번에
돌려줍니다. 한 번의 왕복으로 전부 고치세요.

### 6-2. 18개 전수

| # | `code` | 무엇이 잘못됐나 | 어떻게 고치나 |
|---|---|---|---|
| 1 | `bundle_not_recognized` | 최상위에 `ulogin_settings_bundle` 이 없거나, 요청 본문에 `bundle` 키가 없습니다 | 번들 최상위에 `"ulogin_settings_bundle": "1.0"` 을 넣고, 요청 본문을 `{"bundle": { …번들… }}` 로 감싸세요. **이 오류가 나면 다른 검증은 시작조차 하지 않으므로, 다른 오류가 없다는 뜻이 아닙니다** |
| 2 | `bundle_version_unsupported` | `ulogin_settings_bundle` 값이 `"1.0"` 이 아닙니다 | `"1.0"` 으로 고치세요. 상위 버전을 조용히 하위 처리하지 않습니다 — 그러면 적은 설정이 반영되지 않은 채 성공으로 보입니다 |
| 3 | `missing_required` | 필수 필드가 없습니다. 대상은 셋뿐입니다: `service_id` · `service_name` · `secret_key` | `path` 가 가리키는 자리에 그 필드를 추가하세요 |
| 4 | `unknown_key` | 정의되지 않은 키입니다. **오타를 조용히 무시하지 않습니다** | `expected` 에 허용 키 전체 목록이 있습니다. `did_you_mean` 이 있으면 그 값으로 바꾸세요. 없으면 그 키를 지우세요 |
| 5 | `wrong_type` | 타입이 다릅니다. `value_type` 이 `json`/`url` 인데 값 형식이 어긋난 경우도 여기입니다 | `expected` 가 요구하는 타입으로 바꾸세요. 흔한 원인: 문자열 `"true"`(→ `true`), 문자열 `"3600"`(→ `3600`) |
| 6 | `out_of_range` | 정수가 최소·최대를 벗어났습니다 | `expected` 가 `integer <min>..<max>` 형식으로 범위를 줍니다. 그 안의 값으로 바꾸세요 |
| 7 | `too_long` | 문자열이 상한을 넘었습니다 | `expected` 가 `string, <=<n> chars`. 줄이세요 |
| 8 | `too_short` | 문자열이 하한에 못 미칩니다 | `expected` 가 `string, >=<n> chars`. 늘리세요. 빈 문자열을 `service_name` 에 넣은 경우가 흔합니다 |
| 9 | `too_many` | 배열 항목 수가 상한을 넘었습니다 (서비스 100 / 서비스당 시크릿 100 / 시크릿 총 500 / URL 목록 각 50 / ack 20) | 나눠서 여러 번 적용하거나 항목을 줄이세요 |
| 10 | `not_in_enum` | 열거값 밖의 값입니다 | `expected` 에 허용값이 전부 있습니다. `did_you_mean` 이 있으면 그 값을 쓰세요 |
| 11 | `pattern_mismatch` | 정규식에 맞지 않습니다. URL 규칙 위반도 여기입니다 | `expected` 가 `regex <패턴>` 또는 URL 규칙 문장을 줍니다. 대문자·쿼리스트링·경로가 원인인 경우가 대부분입니다 |
| 12 | `not_an_object` | 객체여야 하는 자리에 다른 것이 있습니다 (`settings`, `services[]` 항목, `secrets[]` 항목) | `{ }` 로 감싸세요. 배열을 넣지 마세요 |
| 13 | `not_an_array` | 배열이어야 하는 자리에 다른 것이 있습니다 (`services`, `secrets`, `redirect_whitelist`, `allowed_origins`, `payload_fields`, `acknowledge_public_secrets`) | `[ ]` 로 감싸세요 |
| 14 | `duplicate_in_bundle` | 같은 `service_id` 가 번들에 두 번, 또는 같은 `secret_key` 가 한 서비스 안에 두 번 있습니다 | 하나로 합치세요. **서버가 조용히 마지막 것을 쓰지 않습니다** — 어느 쪽이 이겨야 하는지 알 수 없기 때문입니다 |
| 15 | `mutual_dependency` | `is_confidential: true` 인데 `service_secret` 이 번들에도 DB에도 없습니다 | `service_secret` 에 8~255자 문자열을 넣거나, `is_confidential` 을 `false` 로 바꾸세요. **이 값은 사람이 정해야 합니다 — 임의로 지어내지 마세요.** 이 오류는 `validate` 에서 나오지 않고 `dry-run`/`apply` 에서만 나옵니다 |
| 16 | `gate_required` | 민감한 이름의 시크릿에 실효 게이트가 하나도 없습니다. **원인이 셋입니다** — ① 게이트를 아예 비웠다 ② 각 축의 기본값(`basic`·`free`·`user`)이나 그 이하로 걸었다 ③ 같은 서비스의 `default_account_level` 이 `min_account_level` 게이트를 자동으로 통과시킨다 | `min_billing_tier` 를 `lite` 이상으로 설정하세요. `expected` 가 어느 원인인지 알려 줍니다 — ③이면 `expected` 에 `default_account_level` 이 언급됩니다. [4-4-3절](#4-4-3-게이트--민감한-키에는-반드시-등급-제한을-거세요) · [4-4-4절](#4-4-4-게이트가-서비스-필드로-무력화되는-조합--서버가-거절합니다) |
| 17 | `body_too_large` | 요청 본문이 1,048,576 바이트를 넘었습니다 | 번들을 나눠서 여러 번 적용하세요. 서비스를 몇 개씩 끊는 것이 가장 쉽습니다 |
| 18 | `body_missing_or_dropped` | 본문이 서버에 도착하지 않았습니다 | **당신의 JSON 문제가 아닙니다.** 서버의 `php.ini` `post_max_size` 또는 중간 프록시의 본문 크기 제한입니다. `message` 가 어느 쪽인지 알려 줍니다. **사람에게 그대로 전달하세요** |

### 6-3. 오류 응답 전문 예시

`validate` 가 오류 3건을 찾은 경우입니다. **`errors` 가 `data` 안에 있다는 점을 보세요.**

```json
{
  "ok": false,
  "data": {
    "errors": [
      {
        "path": "/settings/signup_bonus_points",
        "code": "out_of_range",
        "message": "signup_bonus_points 는 0 이상 1000000 이하의 정수여야 합니다.",
        "expected": "integer 0..1000000",
        "got": 99999999,
        "got_type": "integer"
      },
      {
        "path": "/settings/sesion_ttl_default",
        "code": "unknown_key",
        "message": "정의되지 않은 설정 키입니다. 오타로 만든 키는 조용히 무시하지 않고 거절합니다.",
        "expected": "one of: allow_register, require_email_verify, signup_bonus_points, login_fail_threshold, login_lock_minutes, password_min_length, session_ttl_default, refresh_ttl_default, maintenance_mode, mail_driver, site_name, auth_code_ttl, secret_access_log",
        "got": "sesion_ttl_default",
        "got_type": "string",
        "did_you_mean": "session_ttl_default"
      },
      {
        "path": "/services/0/secrets/0",
        "code": "gate_required",
        "message": "민감한 시크릿(ai_api_key)에는 최소 등급 게이트가 필요합니다. 게이트를 비우면 가입한 모든 사용자가 이 값을 받습니다. min_billing_tier 를 lite 이상으로 설정하십시오. (guest / basic 계정등급, free 결제등급, user 역할은 전 회원의 기본값이라 게이트로 인정되지 않습니다 — 걸어도 전원이 통과합니다. min_account_level 로 걸려면 verified 이상이어야 하는데, 기본 설치본에서는 아무도 verified 가 아니므로 그 서비스의 전 회원이 차단됩니다.)",
        "expected": "min_account_level > basic, min_billing_tier > free, min_role > user 중 하나 이상",
        "got": { "min_account_level": null, "min_billing_tier": "free", "min_role": null },
        "got_type": "object"
      }
    ],
    "error_count": 3,
    "truncated": false,
    "checked": {
      "bundle_version": "1.0",
      "settings_keys": 2,
      "services": 1,
      "secrets": 1,
      "bundle_bytes": 468
    }
  },
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "번들 검증에 실패했습니다. 오류 3건. data.errors 를 보십시오.",
    "field": "bundle"
  }
}
```

포맷 자체를 못 알아본 경우 — **다른 검증을 시작하지 않습니다:**

```json
{
  "ok": false,
  "data": {
    "errors": [
      {
        "path": "/",
        "code": "bundle_not_recognized",
        "message": "ulogin 설정 번들이 아닙니다. 최상위에 \"ulogin_settings_bundle\": \"1.0\" 이 있어야 합니다.",
        "expected": "object with key \"ulogin_settings_bundle\"",
        "got": null,
        "got_type": "missing"
      }
    ],
    "error_count": 1,
    "truncated": false,
    "checked": { "bundle_version": null, "settings_keys": 0, "services": 0, "secrets": 0, "bundle_bytes": 88 }
  },
  "error": { "code": "VALIDATION_FAILED", "message": "ulogin 설정 번들이 아닙니다.", "field": "ulogin_settings_bundle" }
}
```

**게이트가 `default_account_level` 로 무력화된 경우의 `gate_required` — `message` 와
`expected` 가 위와 다릅니다.** `code` 는 같습니다. 원인을 가리는 것은 `expected` 입니다.

```json
{
  "path": "/services/0/secrets/0",
  "code": "gate_required",
  "message": "민감한 시크릿(ai_api_key)의 min_account_level(vip) 게이트가 이 서비스에서는 아무도 막지 않습니다. 이 서비스는 default_account_level(vip) 을 가입하는 회원에게 자동으로 보장하므로, 가입한 전원이 그 등급을 갖고 게이트를 통과합니다. min_billing_tier 를 lite 이상으로 설정하십시오. 결제등급과 역할은 서비스가 자동으로 부여하지 않습니다.",
  "expected": "min_billing_tier > free 또는 min_role > user (이 서비스의 default_account_level 이 min_account_level 게이트를 자동으로 통과시킨다)",
  "got": { "min_account_level": "vip", "min_billing_tier": null, "min_role": null },
  "got_type": "object"
}
```

**두 원인이 겹치면(예: `min_account_level: "basic"` + `default_account_level: "vip"`)
기본값 쪽 문구가 나옵니다.** `basic` 은 애초에 게이트가 아니었으므로, 그것을 조합 문제로
설명하면 서비스 필드를 고친 뒤에도 여전히 막힙니다.

**이 오류 메시지는 `allow_public` 도 `acknowledge_public_secrets` 도 언급하지 않습니다.**
당신이 오류 메시지를 읽고 가장 짧은 통과 경로를 택하는 것을 막기 위해 일부러 그렇게 했습니다.
그 두 필드가 문구에 등장하는 것은 **이미 `allow_public: true` 를 적어 보낸 요청**에
대해서만입니다(그때는 목록에 무엇을 적어야 하는지 알려 줍니다).

### 6-4. 봉투 수준의 에러 코드 (`error.code`) — 검증 코드와 다른 층입니다

**이 기능은 새 API 에러 코드를 하나도 만들지 않았습니다.** 기존 코드만 씁니다.
검증 세부는 언제나 `data.errors[].code` 에 실립니다.

| `error.code` | HTTP | 언제 | 당신이 할 일 |
|---|---|---|---|
| `VALIDATION_FAILED` | 400 | 번들 검증 실패, `expect_plan_hash` 누락·형식 오류 | `data.errors` 를 읽고 고치세요. `data` 가 `null` 이면 `error.message` 를 읽으세요 |
| `CONFLICT` | 409 | `on_conflict: "fail"` 인데 기존 행이 있음 / `expect_plan_hash` 불일치 / 동시 적용 경쟁에서 짐 | **사람에게 넘기세요.** 아무것도 적용되지 않았습니다 |
| `FORBIDDEN` | 403 | 호출자가 `superadmin` 이 아님 | 사람에게 최고관리자 계정으로 다시 시도하라고 알리세요 |
| `AUTH_REQUIRED` | 401 | 토큰이 없거나 만료 | 사람에게 다시 로그인하라고 알리세요 |
| `CSRF_FAILED` | 403 | 쿠키 세션인데 CSRF 헤더가 없음 | `Authorization: Bearer` 로 호출하세요 |
| `RATE_LIMITED` | 429 | 시간당 한도 초과 (`data.retry_after` 초) | **재시도 루프를 돌리지 마세요.** `retry_after` 만큼 기다리거나 사람에게 알리세요 |
| `SERVER_ERROR` | 500 | 서버 내부 오류 | 번들 문제가 아닙니다. 사람에게 서버 오류 로그 확인을 요청하세요 |

---

## 7. 실패 예시 — 틀린 JSON과 그때 나오는 실제 오류 21건

**이 절이 당신에게 가장 유용합니다.** 각 예시는 `bundle` 안에 들어가는 조각입니다.

**(19)·(20)·(21)은 v1.6.0에서 새로 거절되기 시작한 형태입니다.** v1.5.0까지는 200으로
통과했고, 통과한 결과가 「게이트를 걸어 놓은 것처럼 보이면서 아무도 막지 않는다」였습니다.
그 셋을 먼저 읽어 두면 왕복이 줄어듭니다.

### (1) 필수 판별자가 없다

```json
{ "settings": { "site_name": "x" } }
```
→ `bundle_not_recognized` · `path: "/"` · `got_type: "missing"`
**왜:** 필수 필드는 `ulogin_settings_bundle` 하나뿐인데 그것이 빠졌습니다.
**고침:** 최상위에 `"ulogin_settings_bundle": "1.0"` 을 추가합니다.

### (2) 상위 마이너 버전

```json
{ "ulogin_settings_bundle": "1.1", "settings": {} }
```
→ `bundle_version_unsupported` · `expected: "\"1.0\""`
**왜:** 상위 버전을 조용히 하위 처리하면, 그 버전에만 있는 키가 무시되어 **사용자가 적은
설정이 반영되지 않았는데 성공을 봅니다.**
**고침:** `"1.0"` 으로 바꿉니다.

### (3) 설정 키 오타

```json
{ "ulogin_settings_bundle": "1.0", "settings": { "sesion_ttl_default": 3600 } }
```
→ `unknown_key` · `path: "/settings/sesion_ttl_default"` · `did_you_mean: "session_ttl_default"`
**왜:** 오타를 무시하면 반영되지 않은 값을 반영된 줄로 압니다.
**고침:** `did_you_mean` 의 값으로 키 이름을 바꿉니다.

### (4) boolean에 문자열

```json
{ "ulogin_settings_bundle": "1.0", "settings": { "allow_register": "true" } }
```
→ `wrong_type` · `expected: "boolean"` · message: `참/거짓(boolean)이어야 합니다. 문자열 "true" 가 아니라 JSON 의 true 를 쓰십시오.`
**고침:** `"true"` → `true` (따옴표 제거).

### (5) 정수 범위 초과

```json
{ "ulogin_settings_bundle": "1.0", "settings": { "auth_code_ttl": 3600 } }
```
→ `out_of_range` · `expected: "integer 30..600"`
**왜:** SSO code는 1회용 단기 자격증명입니다. 수명이 길면 탈취된 code의 유효 창이 그만큼 넓어집니다.
**고침:** 30~600 사이의 값. 기본 `60` 을 권합니다.

### (6) `service_id` 에 대문자

```json
{ "ulogin_settings_bundle": "1.0", "services": [ { "service_id": "My-Chat", "service_name": "내 채팅" } ] }
```
→ `pattern_mismatch` · `expected: "regex /^[a-z0-9][a-z0-9\-_]{2,63}$/"`
**고침:** `"my-chat"` — 전부 소문자로.

### (7) 복귀 주소에 쿼리스트링

```json
{ "ulogin_settings_bundle": "1.0", "services": [ { "service_id": "my-chat", "service_name": "내 채팅",
  "redirect_whitelist": ["http://localhost:3000/cb.html?x=1"] } ] }
```
→ `pattern_mismatch` · message: `복귀 주소에 쿼리스트링을 포함할 수 없습니다.`
**왜:** 복귀 주소 비교가 **완전 일치**입니다. 쿼리가 붙으면 절대 맞지 않습니다.
**고침:** `"http://localhost:3000/cb.html"`.

### (8) 허용 출처에 경로

```json
{ "ulogin_settings_bundle": "1.0", "services": [ { "service_id": "my-chat", "service_name": "내 채팅",
  "allowed_origins": ["https://a.example.com/app"] } ] }
```
→ `pattern_mismatch` · message: `허용 출처에는 경로를 포함할 수 없습니다. (scheme://host[:port])`
**고침:** `"https://a.example.com"` — 경로를 뗍니다.

### (9) confidential인데 시크릿이 없다

```json
{ "ulogin_settings_bundle": "1.0", "services": [ { "service_id": "internal-tools",
  "service_name": "내부 도구", "is_confidential": true } ] }
```
→ `mutual_dependency` (DB에도 기존 값이 없을 때). **`validate` 에서는 나오지 않고 `dry-run` 에서 나옵니다.**
**왜:** confidential 클라이언트는 토큰 교환 단계에서 `service_secret` 을 검증받습니다. 없으면 로그인이 완료될 수 없습니다.
**고침:** `is_confidential` 을 `false` 로 바꾸거나, **사람에게 `service_secret` 값을 받아** 8~255자로 넣습니다.

### (10) 모집단 기본값을 게이트로 쓴다 — `free`

```json
{ "secret_key": "ai_api_key", "secret_value": null, "min_billing_tier": "free" }
```
→ `gate_required` · `got: {"min_account_level":null,"min_billing_tier":"free","min_role":null}`
· `expected: "min_account_level > basic, min_billing_tier > free, min_role > user 중 하나 이상"`
**왜:** `free` 는 `users.billing_tier` 의 기본값이라 "제한 없음"과 결과가 같습니다.
**게이트를 건 척하고 전원이 통과합니다.**
**고침:** `"lite"` 이상으로.

### (11) 민감한 이름인데 게이트가 없다

```json
{ "secret_key": "openai_key", "secret_value": null }
```
→ `gate_required`
**왜:** 이름을 낱말로 나누면 `openai` + `key` 이고, `key` 가 민감 낱말입니다.
**고침:** `"min_billing_tier": "lite"` 를 추가합니다.

### (12) 같은 `service_id` 가 두 번

```json
{ "ulogin_settings_bundle": "1.0", "services": [
  { "service_id": "my-chat", "service_name": "A" },
  { "service_id": "my-chat", "service_name": "B" } ] }
```
→ `duplicate_in_bundle` · `path: "/services/1"` · `expected: "unique service_id"`
**왜:** 어느 쪽이 이겨야 하는지 알 수 없습니다. 조용히 마지막 것을 쓰지 않습니다.
**고침:** 두 객체를 하나로 합칩니다.

### (13) `payload_fields` 에 대문자

```json
{ "ulogin_settings_bundle": "1.0", "services": [ { "service_id": "my-chat", "service_name": "내 채팅",
  "payload_fields": ["userName"] } ] }
```
→ `pattern_mismatch` · `expected: "regex /^[a-z0-9_]{1,50}$/"`
**고침:** `"username"` — 소문자·숫자·밑줄만.

### (14) `value_type: "json"` 인데 값이 JSON이 아니다

```json
{ "secret_key": "model_config", "secret_value": "not json", "value_type": "json" }
```
→ `wrong_type` · `expected: "string parseable as JSON"` · **`got` 은 `"***"`** (시크릿 값 자리이므로 마스킹)
**고침:** `"{\"model\":\"gpt-4o-mini\"}"` 처럼 파싱 가능한 JSON 문자열로. **또는 `secret_value` 를 `null` 로 두고 사람이 채우게 하세요.**

### (15) `allow_public` 만 붙였다

```json
{ "secret_key": "ai_api_key", "secret_value": null, "allow_public": true }
```
(최상위에 `acknowledge_public_secrets` 없음)
→ `gate_required` · message가 최상위 목록에도 적으라고 알려 줍니다
**당신이 할 일:** 목록을 채우지 **말고** `min_billing_tier` 를 걸거나 사람에게 물으세요. [4-5절](#4-5-allow_public-과-acknowledge_public_secrets--쓰지-마세요)

### (16) `expect_plan_hash` 없이 apply

```json
{ "bundle": { "ulogin_settings_bundle": "1.0" } }
```
(`?action=apply` 로 보냄)
→ 400 `VALIDATION_FAILED` · `data: null` · `error.field: "expect_plan_hash"`
· `error.message: "expect_plan_hash 가 필요합니다. 먼저 ?action=dry-run 으로 계획을 확인하십시오."`
**왜:** 계획을 보지 않은 적용은 없습니다. 이것이 dry-run을 기계적으로 강제하는 장치입니다.
**당신이 할 일:** **`apply` 를 호출하지 마세요.** 사람이 합니다.

### (17) 최상위에 없는 키를 넣었다

```json
{ "ulogin_settings_bundle": "1.0", "version": "1.0", "description": "내 설정" }
```
→ `unknown_key` 2건 (`/version`, `/description`)
**왜:** 최상위 허용 키는 `ulogin_settings_bundle` `on_conflict` `settings` `services`
`acknowledge_public_secrets` + 예약 5개뿐입니다. 자유 메모를 넣을 자리가 없습니다.
**고침:** 두 키를 지웁니다. 메모가 필요하면 각 객체의 `description` 필드를 쓰세요.

### (18) `secrets` 를 객체로 넣었다

```json
{ "ulogin_settings_bundle": "1.0", "services": [ { "service_id": "my-chat", "service_name": "내 채팅",
  "secrets": { "ai_api_key": { "min_billing_tier": "lite" } } } ] }
```
→ `not_an_array` · `expected: "array"` · message: `secrets 는 객체 배열이어야 합니다.`
**왜:** `secrets` 는 **키를 이름으로 갖는 맵이 아니라 객체의 배열**입니다. 키는 각 객체 안의 `secret_key` 필드입니다.
**고침:** `"secrets": [ { "secret_key": "ai_api_key", "secret_value": null, "min_billing_tier": "lite" } ]`

### (19) 모집단 기본값을 게이트로 쓴다 — `basic` (**v1.6.0에서 새로 거절됩니다**)

```json
{ "secret_key": "ai_api_key", "secret_value": null, "min_account_level": "basic" }
```
→ `gate_required` · `got: {"min_account_level":"basic","min_billing_tier":null,"min_role":null}`
· `expected: "min_account_level > basic, min_billing_tier > free, min_role > user 중 하나 이상"`
**왜:** `basic` 은 랭크 10이라 0이 아닙니다. **그런데 `users.account_level` 의 스키마
기본값이고 회원가입 코드가 신규 가입자에게 박는 값이라 전원이 이미 갖고 있습니다.**
v1.5.0까지는 통과했고, 통과한 결과가 「신규 일반 회원의 로그인 payload에 API 키가 평문으로
실린다」였습니다. `"guest"` 도 같은 이유로 거절됩니다(기본값보다 낮습니다).
**고침:** `"min_billing_tier": "lite"` 로 바꿉니다. `min_account_level` 을 쓰겠다면
`verified` 이상이어야 하지만, 기본 설치본에는 `verified` 회원이 없어 전원이 차단됩니다.

### (20) `default_account_level` 이 게이트를 자동으로 통과시킨다 (**v1.6.0에서 새로 거절됩니다**)

```json
{ "ulogin_settings_bundle": "1.0", "services": [ {
  "service_id": "my-chat", "service_name": "내 채팅",
  "default_account_level": "vip",
  "secrets": [ { "secret_key": "ai_api_key", "secret_value": null, "min_account_level": "vip" } ] } ] }
```
→ `gate_required` · `path: "/services/0/secrets/0"`
· `expected: "min_billing_tier > free 또는 min_role > user (이 서비스의 default_account_level 이 min_account_level 게이트를 자동으로 통과시킨다)"`
**왜:** `vip` 는 기본값 `basic` 을 넘으므로 (19)에는 걸리지 않습니다. 그런데 이 서비스가
`vip` 를 가입자에게 자동으로 보장하므로 **가입한 전원이 그 게이트를 통과합니다.**
`auto_join` 과 `allow_register` 를 적지 않았고 **둘 다 기본값이 `true`** 이므로 가입 경로가
열려 있습니다.
**고침:** `min_account_level` 을 지우고 `"min_billing_tier": "lite"` 를 넣습니다.
**`default_account_level` 을 낮추지 마세요** — 그 서비스 전 회원의 등급 정책이 바뀝니다.

### (21) `auto_join: false` 만 닫아서 (20)을 피하려 한다 — **여전히 거절됩니다**

```json
{ "ulogin_settings_bundle": "1.0", "services": [ {
  "service_id": "my-chat", "service_name": "내 채팅",
  "auto_join": false,
  "default_account_level": "vip",
  "secrets": [ { "secret_key": "ai_api_key", "secret_value": null, "min_account_level": "vip" } ] } ] }
```
→ `gate_required` (20과 같은 `expected`)
**왜:** `default_account_level` 을 부여하는 가입 경로가 **둘**입니다 —
`auto_join` 이 여는 「최초 로그인 자동 가입」과 `allow_register` 가 여는
「`service_id` 동반 회원가입」. 여기서 `allow_register` 를 적지 않았고 기본값이 `true` 라
두 번째 경로가 열려 있습니다. 그 경로로 가입한 회원에게 실제로 `vip` 가 기록되고 키가
전달되는 것이 실측으로 확인됐습니다.
**고침:** `min_billing_tier` 로 게이트를 옮깁니다. 두 플래그를 **모두 `false`** 로 닫는 것도
판정을 통과시키지만, 그러면 **아무도 그 서비스에 가입할 수 없습니다** — 게이트를 옮기는 것이
거의 항상 옳은 답입니다.

---

## 8. 범위 밖 — 왜 빠졌는지까지 적습니다

**이유 없이 이름만 적어 두면 다음번에 또 넣으려 시도하게 됩니다.** 그래서 이유를 적습니다.

| 빠진 것 | 왜 빠졌는가 | 대신 무엇을 하는가 |
|---|---|---|
| **시크릿 평문 값** (export 방향) | 이 기능의 사용 흐름이 「파일을 AI 대화창에 붙여넣기」이고, 그 흐름 자체가 유출 경로입니다. 이 서버는 API 키를 글자로 남기지 않기 위해 존재합니다 | import는 값을 받습니다. 한 건 조회는 관리자 콘솔의 시크릿 값 보기(최고관리자 전용, 감사 기록 남음) |
| **`config.php` 쓰기** (DB 접속·암호화 키·쿠키·HTTPS·`BASE_URL`·`DB_PREFIX`) | 네 가지가 각각 치명적입니다. ① `SECRET_ENC_KEY` 가 한 글자만 바뀌면 저장된 전 시크릿이 **영구히 복구 불가능**해지고, 실패가 조용합니다(로그인은 200인데 payload가 빕니다). ② 웹 프로세스에 상시 파일 쓰기 권한이 생깁니다. ③ 부분 쓰기가 나면 **관리자 콘솔을 포함한 전 엔드포인트가 500**이 되고, 그것을 고치는 화면도 그 500 안에 있습니다. ④ 테이블 상수 17개는 `DB_PREFIX` 파생이라 접두를 바꾸면 17개 테이블이 통째로 고아가 됩니다 | `server/install.php` 로 재설치하거나 `server/config.php` 를 직접 편집합니다. export는 비민감 상수 29개를 **읽기 전용**으로 보여주기만 합니다 |
| **패스프레이즈로 암호화한 시크릿 번들** | 값은 있지만 KDF 선택·인증 암호화·포맷 버전·전용 테스트가 필요한 **별개의 기능**입니다. 이 번들에 섞으면 「export 파일은 AI 대화창에 붙여도 안전하다」는 성질이 사라지고, 그 성질이 이 문서를 성립시키는 전제입니다 | 다음 버전의 별도 산출물로 제안되어 있습니다 |
| **삭제 모드** (`replace` / `delete_missing`) | **AI가 블록 하나를 빠뜨린 JSON 하나로 운영 데이터가 사라집니다.** 지워지는 것은 복구 불가능한 암호문 시크릿 · 가입 이력 · 세션입니다 | `"status": "disabled"` 로 은퇴시킵니다 |
| **서비스 시크릿 재발급** (`regenerate_secret`) | ① 번들이 멱등하지 않게 됩니다(매번 "변경"으로 잡힙니다). ② 생성된 평문을 응답에 실어야 하고, 그 응답은 운영자가 어딘가에 붙여넣습니다 | 관리자 콘솔 > 서비스 수정 화면 |
| **약관** (`app_ulogin_terms`) | **관리 화면이 없어서, import로 만든 행을 그 뒤 아무도 수정·발행할 수 없습니다.** 「import만이 편집기인 법률 문서」는 없는 것보다 나쁩니다 — 없는 것은 없다고 알지만, 있는데 관리 불가능한 것은 **관리되고 있다고 오해됩니다.** 이 표는 다음 버전이 소유합니다 | 이 버전에서는 손대지 않습니다 |
| **회원 · 회원×서비스 매핑** | 설정이 아니라 개인정보 데이터입니다. 이 기능의 목적 밖입니다 | 관리자 콘솔 > 회원 |
| **세션 · 인증코드 · 각종 로그** | 런타임 상태입니다. 이식하면 **같은 토큰이 두 설치본에서 유효**해집니다 | 이식하지 않습니다 |
| **스키마 리비전** | `server/migrate.php` 가 소유합니다. 설정이 아닙니다 | `server/migrate.php` 를 실행합니다 |

**export한 번들의 `excluded` 블록이 위 내용을 파일 자체에 담고 있습니다.**
문서가 아니라 파일이 스스로 경계를 말하게 한 것입니다. 그 블록은 import가 받아서 무시합니다.

---

## 9. 절차 — validate → dry-run → apply

### 9-1. 세 단계가 하는 일

| 단계 | DB를 읽나 | DB를 쓰나 | 무엇을 판정하나 | 누가 하나 |
|---|---|---|---|---|
| `validate` | **아니오** | 아니오 | 스키마·타입·범위·번들 안의 중복·게이트(번들 값만으로) | **당신(AI)** |
| `dry-run` | 예 (읽기만) | 아니오 | 위 + 현재 DB와 대조해 **무엇이 생기고 무엇이 덮이는지** | 사람 |
| `apply` | 예 | **예** | dry-run과 같은 판정 + `expect_plan_hash` 대조 | 사람 |

**「누가 하나」 열은 규범이고, 서버가 강제하는 것은 아닙니다.** 이 경계에 관한 정직한
설명은 [9-6절](#9-6-dry-run과-apply를-사람이-하는-것은-절차이고-서버가-막는-것이-아닙니다)에 있습니다.

**`validate` 가 놓치는 것이 하나 있습니다.** 번들이 서비스 필드를 생략하면 `validate` 는
DB의 `default_account_level` 을 볼 수 없으므로 [4-4-4절](#4-4-4-게이트가-서비스-필드로-무력화되는-조합--서버가-거절합니다)의
조합을 **판정하지 못합니다.** `dry-run` 이 그것을 잡습니다. 자세한 것은
[12-2절](#12-2-validate-와-dry-run-의-판정-차이-3건)에 있습니다.

### 9-2. `expect_plan_hash` 가 왜 필수인가

`apply` 는 `expect_plan_hash` 를 **필수 인자**로 받습니다. 그 값을 주는 곳은 `dry-run` 응답
하나뿐입니다. 그러므로 **dry-run을 건너뛰고 apply를 호출할 방법이 없습니다.**

**왜 이렇게 만들었나:** "적용 전에 계획을 확인하세요"를 **권고**로 두면 지켜지지 않습니다.
이 프로젝트에서 절차에 의존한 방어가 반복해 실패했습니다. 해시를 필수 인자로 만들면
그 절차가 **기계적으로 강제**됩니다.

**그리고 그 해시는 낡은 계획을 거절합니다.** 해시에는 정규화된 번들 + **영향받는 현재 행의
값** + `on_conflict` + `environment` 대조 결과가 들어갑니다. dry-run 이후 누군가 설정을
바꿨다면 해시가 달라지고, `apply` 는 409 `CONFLICT` 로 거절하며 **아무것도 적용하지 않습니다.**

해시에 들어가지 **않는** 것: 타임스탬프, 요청자, `generated_at`.
같은 상태에 같은 번들이면 언제 계산해도 같은 해시가 나옵니다.

```json
{
  "ok": false,
  "data": {
    "expected_plan_hash": "9f2c4b8e…",
    "current_plan_hash": "5a8e1c93…",
    "reason": "계획을 만든 뒤 설정이 바뀌었거나, 다른 관리자가 먼저 적용했습니다.",
    "hint": "dry-run 을 다시 호출해 새 계획을 확인한 뒤 그 plan_hash 로 다시 apply 하십시오. 아무것도 적용되지 않았습니다.",
    "plan": { }
  },
  "error": { "code": "CONFLICT", "message": "계획이 낡았습니다. 적용하지 않았습니다.", "field": "expect_plan_hash" }
}
```

409 응답에는 **새로 계산한 계획이 함께 실립니다.** 사람이 그것을 보고 새 `plan_hash` 로
다시 시도할 수 있습니다.

### 9-3. `on_conflict` — 기존 행을 만났을 때

| 값 | 기존 `service_id` / `secret_key` 를 만나면 | 언제 쓰는가 |
|---|---|---|
| `merge` (기본) | **번들에 있는 필드만** 갱신합니다. 번들에 없는 필드는 건드리지 않습니다 | 반복 import로 수렴시킬 때. **당신이 사람과 주고받으며 고치는 흐름이 이것입니다** |
| `skip` | 기존 행은 건드리지 않고 `skipped` 에 넣습니다. **그 서비스 아래의 새 시크릿은 만듭니다**(행 단위 판정입니다) | 이미 있는 것은 보존하고 **새 것만** 추가할 때 |
| `fail` | **아무것도 적용하지 않고** 409 + `data.conflicts[]` | 깨끗한 설치본에만 넣을 것을 확인하고 싶을 때 |

**`merge` 가 기본인 이유:** 사람의 흐름이 "AI가 만든다 → 넣는다 → 고친다 → 다시 넣는다"입니다.
`merge` 는 재-import를 번들 상태로의 **수렴**으로 만듭니다. `fail` 이 기본이면 두 번째
import부터 항상 막혀서 그 흐름이 성립하지 않습니다.

**설정 13키는 `on_conflict` 의 영향을 받지 않습니다.** 언제나 있는 키만 갱신합니다.
`fail` 의 충돌 판정 대상은 서비스와 시크릿뿐입니다.

### 9-4. dry-run 응답 — 사람이 무엇을 보게 되는가

```json
{
  "ok": true,
  "data": {
    "valid": true,
    "plan_hash": "9f2c4b8e1a7d3056c4e8b2a9f17d503e6c1b8a4f92d7e05c3b6a8f14d29e7b05",
    "plan": {
      "settings": {
        "create_or_update": [
          { "path": "/settings/site_name", "skey": "site_name", "from": "ulogin", "to": "내 AI 앱 로그인" }
        ],
        "unchanged": [],
        "skipped": []
      },
      "services": [
        {
          "path": "/services/0",
          "service_id": "my-chat",
          "op": "create",
          "changed_fields": ["service_name", "redirect_whitelist"],
          "secrets": [
            {
              "path": "/services/0/secrets/0",
              "secret_key": "ai_api_key",
              "op": "create",
              "changed_fields": ["min_billing_tier"],
              "value_action": "leave_empty",
              "forced_status": "disabled"
            }
          ]
        }
      ],
      "counts": {
        "settings_update": 1, "settings_unchanged": 0, "settings_skipped": 0,
        "services_create": 1, "services_update": 0, "services_unchanged": 0, "services_skipped": 0,
        "secrets_create": 1, "secrets_update": 0, "secrets_unchanged": 0, "secrets_skipped": 0,
        "deletes": 0
      },
      "secrets_pending_value": [
        { "service_id": "my-chat", "secret_key": "ai_api_key", "how": "관리자 콘솔 > 서비스 > my-chat > 시크릿 에서 값을 넣고 status 를 active 로 바꾸십시오." }
      ],
      "warnings": [
        {
          "path": "/services/0/secrets/0",
          "code": "secret_forced_disabled",
          "message": "secret_value 가 없어 status 를 disabled 로 만듭니다. 값이 빈 active 시크릿은 payload 에서 조용히 빠져 원인을 찾기 어렵습니다."
        }
      ],
      "on_conflict": "merge",
      "deletes_possible": false
    },
    "checked": { "bundle_version": "1.0", "settings_keys": 1, "services": 1, "secrets": 1, "bundle_bytes": 402 }
  },
  "error": null
}
```

`op` 의 값은 `create` · `update` · `unchanged` · `skip` 넷입니다. **`delete` 는 없습니다.**

### 9-5. apply 응답

```json
{
  "ok": true,
  "data": {
    "applied": true,
    "plan_hash": "9f2c4b8e1a7d3056c4e8b2a9f17d503e6c1b8a4f92d7e05c3b6a8f14d29e7b05",
    "result": {
      "settings": { "updated": ["site_name"], "unchanged": [], "skipped": [] },
      "services": { "created": ["my-chat"], "updated": [], "unchanged": [], "skipped": [] },
      "secrets": { "created": ["my-chat/ai_api_key"], "updated": [], "unchanged": [], "skipped": [] },
      "counts": {
        "settings_update": 1, "settings_unchanged": 0, "settings_skipped": 0,
        "services_create": 1, "services_update": 0, "services_unchanged": 0, "services_skipped": 0,
        "secrets_create": 1, "secrets_update": 0, "secrets_unchanged": 0, "secrets_skipped": 0,
        "deletes": 0
      },
      "secrets_pending_value": [
        { "service_id": "my-chat", "secret_key": "ai_api_key", "how": "관리자 콘솔 > 서비스 > my-chat > 시크릿 에서 값을 넣고 status 를 active 로 바꾸십시오." }
      ],
      "environment_check": { "compared": false, "matches": true, "mismatches": [], "note": "…" }
    },
    "warnings": [
      { "path": "/services/0/secrets/0", "code": "secret_forced_disabled", "message": "secret_value 가 없어 status 를 disabled 로 만들었습니다." }
    ],
    "admin_log_id": 812
  },
  "error": null
}
```

**두 번째로 같은 번들을 적용하면 전부 `unchanged` 이고 `admin_log_id` 는 `null` 입니다.**
무변경 적용은 감사 로그를 남기지 않습니다. 다만 `secrets_pending_value` 는 **무변경
적용에서도 그대로 나옵니다** — 값이 여전히 비어 있다는 사실은 변경 여부와 무관하게 참이고,
사람이 봐야 하는 것이기 때문입니다.

**두 번째 apply에는 새 `expect_plan_hash` 가 필요합니다.** 첫 apply로 DB가 바뀌었으므로
이전 해시는 무효입니다 — 그것이 낡은 계획 거절 장치의 **정상 동작**입니다.
dry-run을 다시 호출해 새 해시를 받으세요.

### 9-6. dry-run과 apply를 사람이 하는 것은 절차이고 서버가 막는 것이 아닙니다

**정직하게 적습니다.** 이 문서는 네 곳에서 「`dry-run` 과 `apply` 는 당신이 돌리지 않는다」고
적습니다. 그것은 **규범이고 절차 의존 방어**입니다.

**서버가 실제로 강제하는 것은 하나뿐입니다 — 최고관리자 자격증명입니다.**

| 무엇 | 서버가 막는가 |
|---|---|
| 계획을 보지 않은 적용 | **막습니다.** `apply` 는 `expect_plan_hash` 를 필수로 받고, 그 해시는 `dry-run` 만 발급합니다. DB가 바뀌면 무효가 됩니다 |
| 최고관리자가 아닌 호출자 | **막습니다.** 네 동작 모두 403입니다 |
| 무제한 시도 | **막습니다.** `apply` 는 시간당 10회, 검증은 60회입니다 |
| **「호출자가 사람인가 AI인가」** | **막지 못합니다.** 그런 구분이 없습니다. 토큰 스코프 개념이 없어서 `validate` 만 할 수 있는 토큰을 발급할 수 없습니다 |

**그래서 이 기능의 전제는 이것입니다 —**

> **AI에게 최고관리자 자격증명을 주지 마십시오.**

그 토큰을 가진 주체는 무엇이든 `apply` 를 호출할 수 있고, 서버는 그것을 구분할 방법이
없습니다. 이 문서의 규범은 **자격증명이 사람의 손에 있을 때만** 방어가 됩니다.

**당신(AI)이 지킬 것:** `<<SUPERADMIN_ACCESS_TOKEN>>` 을 요청받아 갖게 되었더라도
`?action=validate` 외의 동작을 호출하지 마세요. 사람에게 번들 파일과 함께
「관리자 콘솔 > 설정 번들 > `2. 계획 보기` → `3. 적용`」 절차를 넘기세요.

---

## 10. `environment` 대조 — 다른 설치본의 번들인가

### 10-1. 무엇을 대조하나

export한 번들에는 `environment.constants` 블록이 들어 있습니다. import는 그 블록의 값을
**적용하지 않습니다**(읽기 전용입니다). 대신 **세 필드를 현재 설치본과 대조**합니다.

| 필드 | 왜 이 셋인가 |
|---|---|
| `BASE_URL` | 설치본 정체성의 가장 강한 단일 신호입니다. 호스트명을 이미 담고 있습니다 |
| `DB_PREFIX` | 다르면 두 설치본의 **테이블 이름 공간이 통째로 다릅니다.** 값이 아니라 구조가 다른 것입니다 |
| `SITE_NAME` | 사람이 붙인 이름입니다. prod/staging처럼 `BASE_URL` 이 비슷한 쌍을 가릅니다 |

나머지 상수는 대조하지 않습니다 — 설치본마다 정당하게 다를 수 있는 정책값이기 때문입니다.
**매번 나오는 경고는 읽히지 않습니다.**

### 10-2. 어떻게 나오나 — **거절이 아니라 검사입니다**

`dry-run` 의 `data.plan.environment_check`, `apply` 의 `data.result.environment_check` 에 실립니다.

```json
{
  "compared": true,
  "matches": false,
  "mismatches": [
    { "field": "BASE_URL", "bundle": "https://staging.example.com/ulogin", "current": "https://example.com/ulogin" },
    { "field": "SITE_NAME", "bundle": "staging", "current": "운영" }
  ],
  "note": "…"
}
```

| 상황 | `compared` | `matches` |
|---|---|---|
| 번들에 `environment` 가 있고 현재와 같다 | `true` | `true` |
| 번들에 `environment` 가 있고 다르다 | `true` | `false` + `mismatches[]` |
| 번들에 `environment` 가 없다 (당신이 만든 번들은 대부분 여기) | `false` | 일치했다고 **주장하지 않습니다** |

**불일치는 거절 사유가 아닙니다.** 다른 설치본으로 설정을 의도적으로 옮기는 것은 정당한
용례입니다. 계획이 보여 주고, 사람이 그 상태로 적용할 수 있습니다.

**다만 불일치는 `plan_hash` 를 바꿉니다** — dry-run을 받은 뒤 `environment` 만 바꿔치기해
apply할 수 없습니다.

### 10-3. 당신이 할 일

**`environment` 블록을 새로 만들지 마세요.** export한 파일을 고치는 경우라면 그 블록을
**그대로 두세요**(값을 지어내 채우면 대조가 무의미해집니다). 처음부터 만드는 번들이라면
그 키를 아예 넣지 마세요 — 그러면 `compared: false` 가 되고, 그것이 정직한 상태입니다.

### 10-4. **`environment.constants` 를 대화창·티켓·스크린샷에 붙여넣지 마세요**

**이 블록에 자격증명은 없습니다.** DB 비밀번호·`SECRET_ENC_KEY`·`APP_KEY`·`MAIL_FROM`·
`TRUSTED_PROXIES` 를 포함한 8개 상수는 **값이 아예 실리지 않습니다**
(`value_omitted_constants` 목록에 이름만 남습니다).

**그런데 값이 실린 29개 중 9개가 모이면 「어떤 공격이 이 설치본에서 성립하는가」의
체크리스트가 됩니다.**

| 상수 | 무엇을 알려 주는가 |
|---|---|
| `TRUST_PROXY` | 클라이언트 IP를 헤더에서 읽는가 → **IP 기반 락아웃·레이트리밋을 헤더로 우회할 수 있는가** |
| `FORCE_HTTPS` | 평문 접속을 리다이렉트하는가 |
| `COOKIE_SECURE` | 세션 쿠키가 평문 채널로 나가는가 |
| `COOKIE_SAMESITE` | 크로스사이트 요청에 쿠키가 실리는가 → CSRF 표면 |
| `COOKIE_DOMAIN` | 서브도메인 전체에 쿠키가 퍼지는가 |
| `LOCKOUT_THRESHOLD` | 몇 번 틀리면 잠기는가 → **그 아래로 맞춰 무제한 시도할 수 있다** |
| `LOCKOUT_MINUTES` | 얼마나 기다리면 풀리는가 |
| `SECRET_ACCESS_LOG` | **시크릿 접근이 기록되는가** — 꺼져 있으면 흔적이 남지 않는다 |
| `DEBUG` | 오류 응답에 내부 정보가 실리는가 |

**export 파일은 이 시스템에서 가장 붙여넣기 쉬운 파일입니다.** 그 안에
**가장 붙여넣지 말아야 할 블록**이 들어 있습니다. export 파일의 `_notes` 마지막 항목이
같은 경고를 파일 자체에 담고 있습니다.

**당신이 할 일:**

1. **`environment` 블록이 있는 export 파일을 당신의 응답에 그대로 되풀어 쓰지 마세요.**
   고쳐야 할 부분만 인용하세요.
2. 사람이 그 파일을 당신에게 붙여넣었다면, **그 블록을 지우고 다시 붙여넣으라고 알리세요.**
   `import` 는 그 블록이 없어도 동작합니다 — 설치본 대조만 건너뜁니다(`compared: false`).
3. 당신이 만든 번들을 사람에게 넘길 때는 `environment` 를 아예 넣지 마세요.

---

## 11. 완전 예시 — 전 필드를 쓴 번들

```json
{
  "ulogin_settings_bundle": "1.0",
  "on_conflict": "merge",
  "settings": {
    "allow_register": true,
    "require_email_verify": false,
    "signup_bonus_points": 300,
    "login_fail_threshold": 5,
    "login_lock_minutes": 15,
    "password_min_length": 10,
    "session_ttl_default": 3600,
    "refresh_ttl_default": 1209600,
    "maintenance_mode": false,
    "mail_driver": "log",
    "site_name": "내 AI 앱 로그인",
    "auth_code_ttl": 60,
    "secret_access_log": true
  },
  "services": [
    {
      "service_id": "local-ai-chat",
      "service_name": "로컬 AI 채팅",
      "description": "브라우저에서 도는 AI 채팅 앱",
      "is_confidential": false,
      "redirect_whitelist": [
        "http://localhost:5173/callback.html",
        "https://chat.example.com/callback.html"
      ],
      "allowed_origins": [
        "http://localhost:5173",
        "https://chat.example.com"
      ],
      "auto_join": true,
      "allow_register": true,
      "default_account_level": "basic",
      "session_ttl": 3600,
      "refresh_ttl": 1209600,
      "payload_fields": ["uuid", "username", "nickname", "account_level", "billing_tier"],
      "status": "active",
      "secrets": [
        {
          "secret_key": "ai_api_key",
          "secret_value": null,
          "value_type": "string",
          "min_account_level": null,
          "min_billing_tier": "lite",
          "min_role": null,
          "is_masked": true,
          "allow_public": false,
          "sort_order": 0,
          "description": "OpenAI 호환 API 키. 값은 사람이 넣는다",
          "status": "active"
        },
        {
          "secret_key": "api_base",
          "secret_value": "https://api.openai.com/v1",
          "value_type": "url",
          "min_account_level": null,
          "min_billing_tier": null,
          "min_role": null,
          "is_masked": false,
          "allow_public": false,
          "sort_order": 10,
          "description": "엔드포인트 주소. 민감하지 않으므로 게이트 없음",
          "status": "active"
        },
        {
          "secret_key": "model",
          "secret_value": "gpt-4o-mini",
          "value_type": "string",
          "min_account_level": null,
          "min_billing_tier": null,
          "min_role": null,
          "is_masked": false,
          "allow_public": false,
          "sort_order": 20,
          "description": null,
          "status": "active"
        }
      ]
    },
    {
      "service_id": "internal-tools",
      "service_name": "내부 도구",
      "description": "서버-투-서버 전용",
      "is_confidential": true,
      "service_secret": "__사람이 채운다. 8~255자__",
      "redirect_whitelist": [],
      "allowed_origins": [],
      "auto_join": false,
      "allow_register": false,
      "default_account_level": null,
      "session_ttl": 900,
      "refresh_ttl": 604800,
      "payload_fields": null,
      "status": "active",
      "secrets": [
        {
          "secret_key": "webhook_url",
          "secret_value": null,
          "value_type": "url",
          "min_account_level": null,
          "min_billing_tier": null,
          "min_role": "admin",
          "is_masked": true,
          "allow_public": false,
          "sort_order": 0,
          "description": "관리자 전용",
          "status": "active"
        }
      ]
    }
  ],
  "acknowledge_public_secrets": []
}
```

**이 예시에서 주목할 점 4가지**

1. **`ai_api_key` 와 `webhook_url` 은 `secret_value: null` 입니다.** 이름이 민감하므로
   게이트를 걸었습니다(`min_billing_tier: "lite"` / `min_role: "admin"`).
   `api_base` 와 `model` 은 민감하지 않으므로 값을 그대로 담았고 게이트가 없습니다.
2. **두 번째 서비스는 경고 1건을 만듭니다.** `redirect_whitelist` 가 비었는데 `status` 가
   `active` 이므로 `empty_redirect_whitelist` 입니다. **의도된 것입니다** —
   서버 대 서버 전용 서비스는 복귀 주소가 필요 없습니다. 경고가 오류가 아닌 이유를
   보여주려고 일부러 넣었습니다.
3. **`service_secret` 자리에 자리표시자를 남겼습니다.** 이 값은 사람이 정합니다.
   그대로 두면 8자를 넘으므로 형식 검증은 통과하지만, **이 문자열이 실제 시크릿이 되어
   버리므로 사람에게 반드시 바꾸라고 알리세요.** 더 안전한 방법은 이 서비스를
   `"is_confidential": false` 로 만들거나, 이 서비스 블록을 번들에서 빼고 사람이 관리자
   콘솔에서 만들게 하는 것입니다.
4. **첫 서비스에 `"default_account_level": "basic"` 이 있는데도 이 번들은 통과합니다.**
   그 서비스의 `ai_api_key` 게이트가 `min_billing_tier` 축에 걸려 있고,
   `min_account_level` 은 `null` 이기 때문입니다 — **무력화될 게이트가 그 축에 없습니다.**
   만약 그 시크릿에 `"min_account_level": "basic"` 을 함께 적었다면
   [(19)](#19-모집단-기본값을-게이트로-쓴다--basic-v160에서-새로-거절됩니다)로 거절되고,
   `"vip"` 를 적고 `default_account_level` 도 `"vip"` 로 올렸다면
   [(20)](#20-default_account_level-이-게이트를-자동으로-통과시킨다-v160에서-새로-거절됩니다)으로
   거절됩니다. **게이트를 `min_billing_tier` 축에 두면 이 함정 전체를 피할 수 있습니다** —
   그 축은 서비스 필드로 자동 부여될 수 없습니다.

### 11-1. 이 문서의 JSON 예시를 검증하는 명령

```bash
# 이 파일이 있는 폴더에서. ```json 펜스만 뽑아 전건 파싱한다.
PYTHONIOENCODING=utf-8 python - <<'PYEOF'
import json, io
lines = io.open('AI_SETTINGS_JSON_GUIDE.md', encoding='utf-8').read().split('\n')
blocks, i = [], 0
while i < len(lines):
    if lines[i].strip() == '```json':
        j = i + 1
        while j < len(lines) and lines[j].strip() != '```':
            j += 1
        blocks.append((i + 2, '\n'.join(lines[i+1:j]))); i = j + 1
    else:
        i += 1
bad = 0
for ln, b in blocks:
    try: json.loads(b)
    except Exception as e: bad += 1; print('FAIL line %d: %s' % (ln, e))
print('%d blocks, %d failed' % (len(blocks), bad))
PYEOF
```

기대 출력: `failed` 가 `0`.
**파서 자신도 대조하세요** — 유효한 조각 하나와 콤마가 남은 깨진 조각 하나를 각각 넣어
전자는 통과하고 후자는 실패하는지 확인한 뒤 결과를 믿으세요.

---

## 12. 알아 두어야 할 비대칭·예외 7건

**이것들을 모르면 "왜 여기서는 오류가 안 나지"로 헤매게 됩니다.**

### 12-1. export가 넣고 import가 무시하는 정보용 키 7개

| 자리 | 키 | 왜 있는가 |
|---|---|---|
| 최상위 | `generated_at` `generator` `environment` `excluded` `_notes` | export 파일을 **그대로 다시 import할 수 있게** 하기 위해서입니다 |
| `services[]` 안 | `has_secret` | 같음. 서비스 시크릿의 유무를 알리는 정보용 필드입니다 |
| `secrets[]` 안 | `value_present` | 같음. 시크릿 값의 유무를 알리는 정보용 필드입니다 |

**이 7개는 닫힌 목록입니다.** 이 밖의 모르는 키는 여전히 `unknown_key` 로 거절됩니다.
**당신이 이 키들을 새로 쓰지는 마세요** — export 파일을 고치는 경우에만 그대로 두면 됩니다.

### 12-2. `validate` 와 `dry-run` 의 판정 차이 3건

| 항목 | `validate` | `dry-run` / `apply` | 오차 방향 |
|---|---|---|---|
| `mutual_dependency` (`is_confidential` + `service_secret`) | **나오지 않습니다.** DB를 읽지 않아 기존 값이 있는지 알 수 없습니다 | 정확히 판정합니다 | 여는 쪽 (과소보고) |
| `gate_required` — 게이트 축의 실효값 | **번들 값만으로 판정합니다.** 게이트 필드를 생략한 부분 갱신 번들에서 **과보고할 수 있습니다** | DB의 기존 게이트와 합친 실효 값으로 정확히 판정합니다 | 닫는 쪽 (과대보고) |
| `gate_required` — **서비스 필드 무력화**([4-4-4절](#4-4-4-게이트가-서비스-필드로-무력화되는-조합--서버가-거절합니다)) | 번들이 `default_account_level`·`auto_join`·`allow_register` 를 적지 않으면 **판정하지 못합니다** | DB의 `services` 행을 읽어 정확히 판정합니다 | **여는 쪽 (과소보고)** |

**「`validate` 의 오차는 언제나 닫는 쪽」이라고 읽지 마세요 — 사실이 아닙니다.**
위 표의 1행과 3행은 **여는 쪽**입니다. `validate` 가 `ok: true` 를 준 번들이 `dry-run` 에서
`gate_required` 로 거절될 수 있고, **그것이 정상**입니다.

**왜 그런가:** `validate` 응답에는 그 설치본에 관한 정보가 하나도 없습니다(그래서 대화창에
붙여넣어도 안전한 유일한 응답입니다). 그 성질과 「DB를 읽어야 아는 판정」은 양립할 수
없습니다. **최종 판정은 언제나 `dry-run` 입니다.**

**당신이 할 일 두 가지:**

1. **`validate` 통과를 「적용해도 된다」로 보고하지 마세요.** 사람에게 넘길 때
   「`dry-run` 에서 게이트 오류가 더 나올 수 있습니다」를 함께 적으세요.
2. **번들에 서비스 필드를 명시적으로 적으면 `validate` 도 그 조합을 잡습니다.**
   기존 서비스를 고치는 번들이라면 `default_account_level`·`auto_join`·`allow_register` 를
   현재 값과 같은 값으로 함께 적어 두는 것이 안전합니다 — 같은 값이면 `unchanged` 가 되고,
   판정은 앞당겨집니다.

**과보고(닫는 쪽) 상황:** 이미 게이트가 걸려 있는 시크릿의 `description` 만 고치는 번들을
만들면, `validate` 가 `gate_required` 를 낼 수 있습니다. 그때도 같은 처방입니다 —
**게이트 필드를 번들에 명시적으로 함께 적으세요**(`"min_billing_tier": "lite"` 를 다시 적는
것은 안전합니다 — 같은 값이면 `unchanged` 가 됩니다).

### 12-3. 오류의 `got` 이 `"***"` 인 자리

`/services/*/secrets/*/secret_value` 자리의 오류는 **실제 값을 싣지 않습니다.**
`got` 이 언제나 `"***"` 이고 `got_type` 만 참값입니다.
오류 응답이 화면·브라우저 콘솔·프록시 로그로 흐르기 때문입니다.
당신이 그 자리를 고칠 때는 **원래 보낸 값**을 보세요, 응답의 `got` 이 아니라.

### 12-4. 실패한 적용은 감사 로그에 남지 않습니다

`apply` 가 실패하면(검증 실패·해시 불일치·트랜잭션 롤백) 관리자 감사 로그에 **한 행도
남지 않습니다.** 서버의 PHP 오류 로그에만 남습니다.
**대안이 더 나빴기 때문입니다** — 트랜잭션 안에 기록하면 롤백과 함께 사라지고, 밖에
기록하면 "적용된 것처럼 보이는 행"이 남습니다.
성공한 적용은 반드시 남고, 시간당 10회 제한이 무제한 시도를 막습니다.

### 12-5. 이 기능은 "한 번에 바로 쓰기"를 절반만 충족합니다

**정직하게 적습니다.** import는 서비스·게이트·정책·시크릿의 **껍데기까지** 한 번에 만듭니다.
시크릿의 **값**은 사람이 따로 넣어야 합니다([3절](#3-시크릿-값은-언제나-null-로-만드세요)).
이 사실을 사람에게 숨기지 마세요. "설정이 전부 끝났습니다"라고 보고하면
**연동 앱이 키를 못 받는 원인 불명 상태**를 만듭니다.

**당신이 사람에게 넘길 때 반드시 함께 전달할 문장:**

> 이 번들은 시크릿의 **자리**만 만듭니다. 실제 키 값은 적용 후 관리자 콘솔에서
> 채우고 `status` 를 `active` 로 바꾸셔야 합니다. 적용 응답의
> `secrets_pending_value` 목록이 채워야 할 항목을 알려 줍니다.

### 12-6. **낡은 게이트 행이 있는 설치본은 자기 export를 다시 import할 수 없습니다**

**당신이 이 상황을 만날 가능성이 높습니다.** 사람이 「지금 설정을 내보냈으니 이걸 고쳐 줘」로
export 파일을 건네는 것이 이 기능의 가장 자연스러운 사용 흐름이고, **v1.5.0 이하에서
만들어진 시크릿 행에는 v1.6.0이 거절하는 게이트가 남아 있을 수 있습니다.**

번들 파일에는 아무 잘못이 없습니다. **서버가 그 행을 거절하는 것이 옳습니다** — 그 행은
지금 이 순간에도 실제로 아무도 막지 않고 있고, 왕복을 통과시키는 것은 「현재 상태가
안전하다」고 말하는 것이기 때문입니다.

**막히는 두 형태:**

| export에 남아 있는 것 | 왜 거절되는가 |
|---|---|
| `"min_account_level": "basic"` (또는 `"guest"`) + 다른 축 게이트 없음 | 기본값 이하라 게이트가 아닙니다. v1.5.0까지는 통과했습니다 |
| `"min_account_level": X` + 같은 서비스 `"default_account_level"` 이 X 이상 + `auto_join` 또는 `allow_register` 가 열림 | 서비스가 그 등급을 자동으로 보장합니다 |

**막히지 않는 것:** 그 행에 이미 `allow_public: true` 가 기록돼 있으면(= 과거에 사람이
의도적으로 공개를 승인한 행) **DB의 그 승인을 승인으로 인정**하므로 `dry-run` 이 통과합니다.
`acknowledge_public_secrets` 를 손으로 채워 넣지 않아도 됩니다.
다만 **`validate` 는 DB를 읽지 않으므로 그 경우에도 `gate_required` 를 과보고합니다** —
`dry-run` 이 최종 판정입니다.

#### 처방 — 사람에게 이 절차를 넘기세요

**당신이 번들을 고쳐서 해결하는 것이 1순위입니다.** 그 시크릿 블록의 `min_account_level` 을
지우고 `"min_billing_tier": "lite"`(또는 사람이 정한 등급)를 적으면, 그 번들의
`dry-run` → `apply` 가 통과하면서 **DB의 낡은 게이트도 함께 고쳐집니다.**

번들을 통하지 않고 관리자 콘솔에서 직접 고치는 방법도 있습니다. 그 경로의 실제 동작:

| 관리자 콘솔에서 그 행에 하는 일 | 결과 |
|---|---|
| **게이트를 옮긴다** — `min_billing_tier` 를 `lite` 로 | **통과.** 그 뒤 `allow_public` 표시가 `0` 으로 내려가고, 그 설치본은 **자기 export를 다시 왕복할 수 있게 됩니다** |
| `description` · `status` · `sort_order` · 시크릿 **값**만 고친다 | **통과합니다.** 게이트 필드를 건드리지 않는 수정은 재판정하지 않습니다 — 그러지 않으면 운영자가 값도 못 고치고 게이트도 못 거는 막다른 길에 갇힙니다 |
| `secret_key` 또는 `min_account_level`·`min_billing_tier`·`min_role` 중 하나라도 건드린다 | **적용 후 상태로 재판정합니다.** 여전히 실효 게이트가 없으면 **400** (`error.code: VALIDATION_FAILED` · `error.field: min_billing_tier`) |
| 그 행을 새로 만든다 (신규 등록) | **재판정합니다.** 같은 400 |

**관리자 콘솔의 400 메시지는 `allow_public=true` 를 언급합니다.** 번들 경로와 달리 그 문구는
사람에게 하는 말이기 때문입니다. **당신은 그 문구를 근거로 번들에 `allow_public` 을 넣지
마세요** — 사람이 화면에서 응답을 즉시 보고 판단하는 상황과, 당신이 만든 파일을 사람이
검토 없이 적용하는 상황은 다릅니다.

#### 사람에게 전달할 문장

> 이 설치본의 시크릿 중 일부는 v1.5.0까지 허용됐지만 v1.6.0이 「게이트가 아니다」로
> 판정하는 등급 제한을 갖고 있습니다. 그 행들은 **지금도 그 서비스 가입자 전원에게
> 열려 있습니다.** 이 번들은 그 게이트를 `min_billing_tier` 로 옮겨 고칩니다.
> 전원 공개가 실제 의도였다면 그 시크릿에 `allow_public: true` 를 적고 번들 최상위
> `acknowledge_public_secrets` 에도 함께 적으셔야 하며, 그때 그 행은 관리자 콘솔의
> 「게이트 점검」 화면에 「공개를 승인한 행」으로 표시됩니다.

### 12-7. 관리자 콘솔로 만들 수 있고 아직 탐지되지 않는 조합 하나

**정직하게 적습니다.** 게이트가 정상인 시크릿을 먼저 만든 **뒤에** 관리자 콘솔의
서비스 수정 화면에서 `default_account_level` 을 올리면, 그 요청은 그 서비스의 시크릿을
보지 않으므로 [4-4-4절](#4-4-4-게이트가-서비스-필드로-무력화되는-조합--서버가-거절합니다)의
조합이 **두 단계로 만들어질 수 있습니다.**

| 경로 | 지금 막히는가 |
|---|---|
| 번들 import (`settings_bundle.php`) | **막힙니다** |
| 관리자 콘솔 시크릿 등록·수정 (`secrets.php`) | **막힙니다** |
| 관리자 콘솔 **서비스** 수정 (`services.php`) 으로 `default_account_level` 을 나중에 올린다 | **막지 않습니다** — 그 한 요청 때문에 서비스 등급 정책 변경 전체를 막으면 과잉 차단이 되고, 그 요청에는 공개를 선언할 자리가 없습니다 |

**그렇게 만들어진 행은 「게이트 점검」 화면에도 뜨지 않습니다**(그 화면은 시크릿의 `min_*` 만
봅니다). 화면이 그 공백을 글자로 고지하고, 회귀 스위트가 매 실행 그 사실을 출력합니다.
**근본 해법은 다음 버전 과제입니다** — 서버가 판정을 계산해 응답에 실어 주면 화면이
그 조합을 탐지합니다.

**당신에게 의미하는 것:** 당신이 만든 번들이 `dry-run` 200을 받았다는 것은
**「이 번들이 그 조합을 만들지 않는다」**는 뜻이고, **「이 설치본에 그 조합이 없다」**는
뜻은 아닙니다. 그 구분을 사람에게 전달하세요.

---

## 13. 완료 체크리스트 — 번들을 사람에게 넘기기 전에

**21항목.** 각 항목에 **어떻게 확인하는가**를 함께 적었습니다.
**19~21은 v1.6.0에서 추가된 항목입니다.**

| # | 항목 | 확인 방법 |
|---|---|---|
| 1 | 최상위에 `"ulogin_settings_bundle": "1.0"` 이 있다 | 눈으로. 없으면 다른 검증이 시작조차 안 됩니다 |
| 2 | JSON이 실제로 파싱된다 | `python -c "import json,sys;json.load(open('bundle.json',encoding='utf-8'))"` |
| 3 | 모든 `secret_value` 가 `null` 이다 | `grep -c '"secret_value": null' bundle.json` 와 `grep -c '"secret_value"' bundle.json` 의 값이 같은가 |
| 4 | `allow_public` 이 하나도 없다 | `grep -c allow_public bundle.json` → 0 (또는 전부 `false`) |
| 5 | `acknowledge_public_secrets` 가 비어 있다 | 눈으로 |
| 6 | 민감한 이름의 시크릿에 전부 게이트가 있다 | 키 이름에 `key` `secret` `token` `password` `credential` `sk` 낱말이 있으면 `min_billing_tier` 가 `lite` 이상인가 |
| 7 | 게이트 값에 `free` `guest` **`basic`** `user` 가 없다 | `grep -n '"free"\|"guest"\|"basic"\|"user"' bundle.json` → `min_account_level`·`min_billing_tier`·`min_role` 자리에 없어야 함. **`basic` 은 `default_account_level` 자리에는 있어도 됩니다** |
| 8 | `service_id` 가 전부 소문자·숫자·`-`·`_` 이고 3~64자 | 정규식 `^[a-z0-9][a-z0-9\-_]{2,63}$` |
| 9 | `service_id` 가 중복되지 않는다 | 눈으로. 중복이면 `duplicate_in_bundle` |
| 10 | `secret_key` 가 전부 소문자·숫자·`_` 이고 2~100자 (**하이픈 불가**) | 정규식 `^[a-z0-9][a-z0-9_]{1,99}$` |
| 11 | `redirect_whitelist` 에 쿼리스트링·프래그먼트가 없다 | `grep -n 'redirect_whitelist' -A5 bundle.json` 에서 `?` `#` 를 찾습니다 |
| 12 | `allowed_origins` 에 경로가 없다 | 각 항목이 `scheme://host[:port]` 로 끝나는가 |
| 13 | `active` 서비스의 `redirect_whitelist` 가 비어 있지 않다 (서버-투-서버 전용이 아니라면) | 눈으로. 비었으면 경고가 납니다 |
| 14 | `maintenance_mode` · `allow_register` · `secret_access_log` 를 요청받지 않았는데 넣지 않았다 | `grep -n 'maintenance_mode\|secret_access_log' bundle.json` |
| 15 | `validate` 가 `ok: true` 를 돌려준다 | [2-3절](#2-3-curl-예시--손으로-먼저-확인하세요)의 curl 1) |
| 16 | `warnings` 를 읽고 사람에게 전달할 준비가 됐다 | `data.warnings` 배열의 각 `message` 를 그대로 옮깁니다 |
| 17 | "시크릿 값은 사람이 채운다"를 사람에게 알렸다 | [12-5절](#12-5-이-기능은-한-번에-바로-쓰기를-절반만-충족합니다)의 문장을 그대로 |
| 18 | `dry-run` 과 `apply` 를 당신이 호출하지 않았다 | 호출 기록을 확인하세요. 사람의 몫입니다 |
| 19 | `min_account_level` 게이트를 같은 서비스의 `default_account_level` 이 통과시키지 않는다 | 서비스마다 확인: `default_account_level` 의 랭크가 그 서비스 시크릿의 `min_account_level` 랭크 **이상**이고 그 시크릿에 다른 축 게이트가 없으면 거절됩니다. 랭크는 `guest` 0 · `basic` 10 · `verified` 20 · `vip` 30 · `staff` 90. `auto_join`·`allow_register` 를 적지 않았으면 **둘 다 `true` 로 계산**하세요 |
| 20 | 게이트를 `min_billing_tier` 축에 두었다 | `grep -n 'min_account_level' bundle.json` → 민감 키의 게이트로 쓰인 자리가 없는 것이 가장 안전합니다. 그 축만 서비스 필드로 자동 부여될 수 있습니다 |
| 21 | `environment` 블록을 번들에 넣지 않았다 (또는 export 파일에서 지웠다) | `grep -c environment bundle.json` → 0. 그 블록은 이 설치본의 방어 설정 요약입니다([10-4절](#10-4-environmentconstants-를-대화창티켓스크린샷에-붙여넣지-마세요)) |

---

## 14. 함정 목록 — 실제로 걸리는 것들

| # | 함정 | 왜 걸리는가 | 어떻게 피하나 |
|---|---|---|---|
| 1 | `error.errors` 를 읽는다 | 서버가 `error` 를 세 키로 고정하고 나머지를 `data` 로 옮깁니다 | **`data.errors`** 를 읽으세요 |
| 2 | 번들을 요청 본문 최상위로 보낸다 | 실행 옵션과 번들 내용을 분리하기 위해 한 겹 감쌉니다 | `{"bundle": { …번들… }}` |
| 3 | `secrets` 를 `{키: {…}}` 맵으로 만든다 | 키가 객체 안의 `secret_key` 필드입니다 | `[{ "secret_key": "…", … }]` 배열 |
| 4 | `secret_key` 에 하이픈을 쓴다 (`x-api-key`) | 시크릿 키 정규식은 `_` 만 허용합니다 (`service_id` 는 `-` 도 됩니다) | `x_api_key` |
| 5 | `"min_billing_tier": "free"` 로 게이트를 건다 | 전 회원의 기본값이라 게이트로 인정되지 않습니다 | `"lite"` 이상 |
| 6 | `gate_required` 를 `allow_public` 으로 없앤다 | 그것은 **민감 키를 전원에게 여는** 필드입니다 | `min_billing_tier` 를 걸거나 사람에게 물으세요 |
| 7 | 시크릿 값을 지어낸다 | 서버는 그 값을 그대로 저장하고, 연동 앱은 인증 실패를 원인 불명으로 겪습니다 | `null` 로 두고 사람에게 넘깁니다 |
| 8 | 다운로드한 파일을 봉투째 import한다 | `{"ok":…,"data":…}` 전체를 넣으면 `unknown_key` 로 거절됩니다 | **`data.bundle` 만** 파일로 저장합니다 |
| 9 | `?action=` 을 경로에 붙인다 | 쿼리 파라미터입니다 | `settings_bundle.php?action=validate` |
| 10 | 확장자 없는 URL을 쓴다 | `mod_rewrite` 가 없는 호스팅에서 404입니다 | `.php` 를 붙여 쓰세요 |
| 11 | `admin` 역할로 호출한다 | 네 동작 모두 `superadmin` 전용입니다 | 사람에게 최고관리자 계정을 요청합니다 |
| 12 | 429를 받고 즉시 재시도한다 | 시간당 한도이고, `validate` 는 60회입니다 | `data.retry_after` 만큼 기다리거나 사람에게 알립니다 |
| 13 | 경고를 오류로 알고 고친다 | 경고는 `ok: true` 이고 적용을 막지 않습니다 | 고치지 말고 사람에게 그대로 전달합니다 |
| 14 | 서비스를 지우려고 한다 | 삭제 경로가 없습니다 | `"status": "disabled"` |
| 15 | `service_id` 를 바꿔 이름을 고치려 한다 | 다른 테이블이 그 문자열로 참조합니다. 바꾸면 **새 서비스가 생깁니다** | `service_name` 을 바꾸세요 |
| 16 | 이전 `plan_hash` 로 두 번째 apply를 한다 | 첫 apply로 DB가 바뀌어 해시가 무효입니다 | dry-run을 다시 호출해 새 해시를 받습니다 |
| 17 | `settings` 에 값 하나만 적으면 나머지가 초기화될까 걱정한다 | **초기화되지 않습니다.** 적은 키만 적용됩니다 | 안심하고 필요한 키만 적으세요 |
| 18 | 큰 번들을 한 번에 보낸다 | 본문 1 MiB · 서비스 100 · 시크릿 500 상한이 있습니다 | 서비스를 몇 개씩 끊어 여러 번 적용합니다 (멱등이므로 안전합니다) |
| 19 | **`"min_account_level": "basic"` 으로 게이트를 건다** | 랭크가 10이라 0이 아닌데도 **전 회원의 기본값**입니다. v1.5.0까지는 통과했습니다 | `"min_billing_tier": "lite"`. `min_account_level` 을 쓰려면 `verified` 이상이어야 하지만 그러면 기본 설치본의 전 회원이 막힙니다 |
| 20 | **게이트를 `min_account_level` 축에 걸고 같은 서비스 `default_account_level` 을 그 이상으로 둔다** | 그 서비스가 그 등급을 가입자에게 자동으로 보장합니다. **두 필드가 서로 다른 자리에 있어 사람도 못 알아챕니다** | 게이트를 `min_billing_tier` 로 옮기세요. **`default_account_level` 을 낮추지 마세요** |
| 21 | **`auto_join: false` 만 적어 20을 피했다고 생각한다** | `allow_register`(스키마 기본값 `true`)가 여는 두 번째 가입 경로가 남아 있습니다 | 같음 — 게이트를 옮깁니다 |
| 22 | **`validate` 가 `ok: true` 니까 적용해도 된다고 보고한다** | `validate` 는 DB를 읽지 않아 서비스 필드 무력화와 `mutual_dependency` 를 **과소보고**합니다 | 「`dry-run` 에서 오류가 더 날 수 있다」를 함께 전달하세요([12-2절](#12-2-validate-와-dry-run-의-판정-차이-3건)) |
| 23 | **export 파일을 `environment` 블록째 대화창에 붙여넣는다** | 그 블록에 프록시 신뢰·쿠키 속성·락아웃 임계·감사 on/off 가 들어 있습니다 | 그 블록을 지우고 다루세요([10-4절](#10-4-environmentconstants-를-대화창티켓스크린샷에-붙여넣지-마세요)) |
| 24 | **export를 그대로 다시 넣었는데 `gate_required` 가 나서 번들이 깨진 줄 안다** | v1.5.0 이하에서 만든 낡은 게이트 행이 그 파일에 실려 있습니다. 파일 잘못이 아닙니다 | 그 시크릿의 게이트를 `min_billing_tier` 로 옮겨 적으세요([12-6절](#12-6-낡은-게이트-행이-있는-설치본은-자기-export를-다시-import할-수-없습니다)) |

---

## 15. 진단 절차 — 증상에서 원인까지

| 증상 | 원인 | 확인 방법 | 조치 |
|---|---|---|---|
| 모든 호출이 404 | URL이 틀렸거나 `mod_rewrite` 가 없다 | `curl -sS -o /dev/null -w '%{http_code}\n' '<<설치루트>>/server/api/health.php'` → 200이어야 합니다 | `.php` 확장자를 붙인 URL을 쓰세요. `health.php` 도 404면 `<<설치루트>>` 가 틀렸습니다 |
| 모든 호출이 401 | 토큰이 없거나 만료 | 응답의 `error.code` 가 `AUTH_REQUIRED` 인가 | 사람에게 다시 로그인을 요청하세요 |
| 모든 호출이 403 | `superadmin` 이 아니다 | `error.code` 가 `FORBIDDEN` 인가 | 최고관리자 계정의 토큰이 필요합니다 |
| 400인데 `data.errors` 가 비어 있다 | `expect_plan_hash` 누락·형식 오류 (이 경우 `data` 가 `null` 입니다) | `error.field` 가 `expect_plan_hash` 인가 | dry-run을 먼저 호출하세요. **이것은 사람의 몫입니다** |
| `bundle_not_recognized` 인데 번들에 판별자가 있다 | 요청 본문을 `bundle` 로 감싸지 않았다 | `error.message` 가 「요청 본문에 bundle 이 없습니다」인가 | `{"bundle": { …번들… }}` |
| `body_missing_or_dropped` | 서버의 `post_max_size` 또는 프록시 제한 | `data.errors[0].message` 가 어느 쪽인지 알려 줍니다 | **당신의 JSON 문제가 아닙니다.** 사람에게 그 메시지를 그대로 전달하세요 |
| 본문이 큰데 아무 응답도 없이 깨진 출력이 온다 | 서버의 `display_errors` 가 켜져 있어 PHP 자신의 경고가 우리 코드보다 먼저 출력됐다 | 응답 본문 앞에 PHP 경고 문구가 붙어 있는가 | 코드로 고칠 수 없는 자리입니다. 사람에게 `php.ini` 의 `display_errors=Off` 와 `post_max_size` 상향을 요청하세요 |
| 적용했는데 연동 앱이 키를 못 받는다 | 시크릿 값이 비어 `status` 가 `disabled` 다 | 적용 응답의 `secrets_pending_value` 에 그 키가 있는가 | 관리자 콘솔에서 값을 넣고 `status` 를 `active` 로 바꿉니다 |
| 적용했는데 SSO 로그인이 복귀 단계에서 실패한다 | `redirect_whitelist` 에 앱의 콜백 URL이 없다 | `empty_redirect_whitelist` 경고가 있었는가. 앱의 실제 콜백 URL과 **완전 일치**하는가 | 쿼리·프래그먼트 없이, 대소문자까지 정확히 일치시킵니다 |
| 두 번째 apply가 409 | 첫 apply로 DB가 바뀌어 이전 해시가 무효 | `error.field` 가 `expect_plan_hash` 인가 | **정상 동작입니다.** dry-run을 다시 호출하세요 |
| 같은 번들인데 매번 "변경"으로 잡힌다 | URL 배열의 표기가 정규화 결과와 다르다(호스트 대문자 등) | dry-run의 `changed_fields` 에 `allowed_origins` 가 계속 나오는가 | export한 값을 그대로 쓰거나, 호스트를 소문자로 적으세요 |
| **export한 파일을 그대로 넣었는데 `gate_required`** | v1.5.0 이하에서 만든 낡은 게이트 행(`basic` 게이트, 또는 `default_account_level` 이 통과시키는 조합)이 그 파일에 실려 있다 | 그 오류의 `path` 가 가리키는 시크릿의 `min_account_level` 값을 보세요. `basic`·`guest` 이거나, 같은 서비스 `default_account_level` 의 랭크가 그 값 이상인가 | **번들 파일 잘못이 아닙니다.** 그 시크릿의 게이트를 `min_billing_tier: "lite"` 로 옮겨 적으세요. [12-6절](#12-6-낡은-게이트-행이-있는-설치본은-자기-export를-다시-import할-수-없습니다) |
| **`validate` 는 통과했는데 `dry-run` 이 `gate_required`** | `validate` 는 DB를 읽지 않아 그 서비스의 `default_account_level` 을 볼 수 없다 | 번들에 그 서비스의 `default_account_level`·`auto_join`·`allow_register` 를 적었는가 | **정상 동작입니다.** 세 필드를 번들에 명시적으로 적으면 `validate` 도 같은 판정을 합니다. [12-2절](#12-2-validate-와-dry-run-의-판정-차이-3건) |
| `allow_public: true` 인 기존 행인데 `validate` 가 `gate_required` | `validate` 가 DB의 승인 표시를 읽지 못한다 | `dry-run` 은 통과하는가 | **`dry-run` 이 최종 판정입니다.** 이 경우 `acknowledge_public_secrets` 를 채우지 마세요 |
| 관리자 콘솔의 시크릿 수정이 400 (`field: min_billing_tier`) | 그 행이 v1.6.0 기준으로 실효 게이트가 없고, 이 요청이 게이트 필드를 건드렸다 | 응답 `error.message` 가 원인(기본값 이하 / `default_account_level` 무력화)을 알려 준다 | `min_billing_tier` 를 `lite` 이상으로 설정합니다. 값·설명·`status` 만 고치는 수정은 통과합니다. [12-6절](#12-6-낡은-게이트-행이-있는-설치본은-자기-export를-다시-import할-수-없습니다) |

---

## 16. 이 문서의 버전

| 항목 | 값 |
|---|---|
| 대상 서버 버전 | **v1.6.0** (`v1.6.0_20260903`) |
| 문서 작성일 | 2026-09-03 |
| 번들 스키마 버전 | `ulogin_settings_bundle` = **`"1.0"`** |
| 엔드포인트 | **4개** (`GET` 1 + `POST ?action=` 3). 실제 파일 1개 |
| 검증 오류 코드 | **18개** (닫힌 집합) |
| 경고 코드 | **7개** (닫힌 집합) |
| 관리 가능한 전역 설정 키 | **13개** |
| 서비스 필드 | **15개** (+ 받아서 무시하는 예약 키 1: `has_secret`) |
| 시크릿 필드 | **11개** (+ 받아서 무시하는 예약 키 1: `value_present`) |
| 최상위 예약 키 | **5개** (`generated_at` `generator` `environment` `excluded` `_notes`) |
| 새 API 에러 코드 | **0개** — 봉투 수준 에러 코드 31개는 이 릴리스에서 변경·추가되지 않았습니다 |
| 게이트 실효성 판정 기준 | **각 축의 모집단 기본값 초과** (`min_account_level > basic` · `min_billing_tier > free` · `min_role > user`), **그리고** 그 축이 같은 서비스의 `default_account_level` 로 자동 부여되지 않을 때 |
| `export` 의 `environment.constants` | **값이 실린 상수 29개** (+ 이름만 남기고 값을 뺀 8개, + `DB_PREFIX` 파생 테이블 상수 17개). **그 29개 중 9개는 방어 태세 정보입니다** — [10-4절](#10-4-environmentconstants-를-대화창티켓스크린샷에-붙여넣지-마세요) |

**이 표의 값은 `server/api/admin/settings_bundle.php` · `server/core/secretguard.php` ·
`server/core/settingsdef.php` 를 파싱해 얻은 것입니다. 문서를 갱신하려면 그 세 파일을
다시 세십시오** — 오류·경고 코드는 `sb_add()`·`sb_warn()` 호출의 3번째 인자와
`'code' => '…'` 인라인 조립 자리를 합친 값입니다(`grep -c` 는 매치가 아니라 줄 수를 셉니다).

**서버 버전을 확인하는 방법:**

```bash
# 1) 배포본 버전
curl -sS '<<설치루트>>/public/version.txt'
# 기대: 1.6.0_20260903
#   1.5.0_20260901 이하가 나오면 이 기능이 없는 서버입니다 — 아래 2)가 404입니다.

# 2) 이 엔드포인트의 존재 확인 (인증 없이 호출하면 401 이 정상입니다)
curl -sS -o /dev/null -w '%{http_code}\n' \
  '<<설치루트>>/server/api/admin/settings_bundle.php'
# 기대: 401  ← 파일이 있고 인증을 요구한다 = 이 문서가 적용됩니다
#       404  ← v1.5.0 이하 배포본입니다. 이 기능이 없습니다
```

**이 문서보다 서버가 새롭다면**(`version.txt` 가 `1.6.0_20260903` 이 아닌 더 큰 값이면),
그 서버를 배포한 폴더에 함께 들어 있는 같은 이름의 최신 문서를 사용하세요.

---

## 부록 A. 연동 앱을 만드는 것은 이 문서의 범위가 아닙니다

이 문서는 **설정 JSON을 만드는 법**만 다룹니다.
로그인 플로우·토큰·payload 수신·포인트 차감 같은 **연동 코드**를 써야 한다면
같은 폴더의 **`AI_INTEGRATION_GUIDE.md`** 를 읽으세요. 그 문서도 자립형이고,
엔드포인트 전체 레퍼런스와 복붙 가능한 클라이언트 코드를 담고 있습니다.

**두 문서의 경계:**

| 하려는 일 | 읽을 문서 |
|---|---|
| 서비스·시크릿·전역 설정을 JSON으로 만들어 넣는다 | **이 문서** |
| 앱에서 로그인시키고 시크릿을 받아 AI를 호출한다 | `AI_INTEGRATION_GUIDE.md` |
| 서버를 처음 설치한다 | `INSTALL.md` |
