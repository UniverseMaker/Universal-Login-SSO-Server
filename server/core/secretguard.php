<?php
declare(strict_types=1);

/**
 * core/secretguard.php — 시크릿 키의 민감성·게이트·값 형식 판정 (단일 정의)
 * 담당 계약: §8-16 · §8-17 (secrets CRUD) · §8-26 ~ §8-28 (설정 번들 검증)
 *
 * ── 왜 이 파일이 생겼나 ───────────────────────────────────────────────
 * 민감 키 정규식과 실효 게이트 판정은 `api/admin/secrets.php` 안에 있었다.
 * v1.6.0 의 설정 번들 검증기가 **같은 판정을 해야** 한다 — 번들 import 가
 * `POST /api/admin/secrets` 와 같은 행을 만들기 때문이다. 그 판정을 번들 쪽에
 * 다시 적으면 규칙이 **세 곳**(설치기 · 시크릿 CRUD · 번들)에 있게 되고,
 * v1.4.0 은 이미 두 곳이 갈라진 상태를 48키 대조로 찾아내야 했다.
 * 그래서 판정을 이 파일 하나로 올렸다. **사본을 만들지 말고 이 파일을 고쳐라.**
 *
 * ── 여기 없는 네 번째 자리 ────────────────────────────────────────────
 * `server/install.php` 의 `ins_is_sensitive_key()` 는 여전히 자기 사본을 갖는다.
 * 설치기는 `core/` 를 로드하지 않는다(설치 전에는 `config.php` 가 없어서
 * `bootstrap.php` 가 500 으로 죽는다). 그 사본은 **정규식 문자열이 아래
 * `admin_secret_key_is_sensitive()` 와 글자 단위로 같아야 한다** — 한쪽만
 * 고치면 한쪽 경로만 막힌다. 설치기를 고칠 때 이 파일도 함께 열어라.
 *
 * 로드 방법: `core/bootstrap.php` 를 지난 뒤 `require_once` 한다.
 *   api/admin/secrets.php · api/admin/settings_bundle.php 가 그렇게 한다.
 *   bootstrap 의 공통 include 목록에 넣지 않은 이유는 이 판정을 쓰는 곳이
 *   관리자 시크릿 경로뿐이고, 전 요청에 얹을 이유가 없기 때문이다.
 */


// 직접 실행 차단 — 리더 판정 D-34. bootstrap.php 를 거치지 않은 요청은 존재하지 않는 파일이다.
if (!defined('ULOGIN_BOOTSTRAPPED')) {
    // X-Powered-By 제거 — D-154(2). 이 경로는 bootstrap.php 를 거치지 않는다.
    header_remove('X-Powered-By');
    http_response_code(404);
    exit;
}

if (!function_exists('admin_secret_key_is_sensitive')) {
    /**
     * 민감 키 판정. **정규식은 install.php 의 `ins_is_sensitive_key()` 와 글자 단위로 같다**
     * (출처: `_workspace/08a_fix2_install_report.md` §2 「민감 키 판정 규칙」).
     *
     * v1.4.0 개정(D-154(4)). 이름을 `_` `-` `.` 로 나눈 **낱말** 중 하나라도
     * key / keys / apikey / apikeys / secret(s) / token(s) / password(s) / passwd /
     * credential(s) / sk 이면 민감하다.
     *
     * 민감: `ai_api_key` `openai_key` `claude_key` `anthropic_key` `private_key`
     *       `ai_api_key_prod` `api_key_v2` `sk_live` `x-api-key` `secret_key`
     *       `api_key` `apikey` `x_secret` `client-secret` `refresh_token`
     *       `db_password` `passwd` `aws_credentials` (대소문자 무시, 복수형 허용)
     * 비민감: `api_base` `model` `endpoint` `org_id` `openai_org` `keyring`
     *
     * 종전 규칙은 대안 목록에 맨 `key` 가 없고 끝이 `$` 로 고정돼 있어
     * `openai_key` 계열과 글자 그대로의 `secret_key` 까지 통째로 빠져나갔다.
     * `api_key_hint` 는 이제 민감으로 잡힌다 — `api_key_v2` 와 이름 모양이 같아
     * 규칙으로 가를 수 없고, 틀린다면 닫는 쪽으로 틀리는 것이 맞다(fail-closed).
     */
    function admin_secret_key_is_sensitive(string $key): bool
    {
        return preg_match(
            '/(?:^|[_\-.])(?:apikeys?|keys?|secrets?|tokens?|passwords?|passwd|credentials?|sk)(?:$|[_\-.])/',
            strtolower(trim($key))
        ) === 1;
    }
}

if (!defined('SECRET_GATE_FLOOR')) {
    /**
     * 각 축의 **모집단 기본값**. 이 값 이하로 건 게이트는 게이트가 아니다.
     *
     * 랭크 0 이 아니어도 **전원이 이미 그 값을 갖고 있으면** 결과가 "제한 없음"과 같다.
     * 세 값은 추측이 아니라 코드·스키마에서 확인한 것이다:
     *   account_level 'basic' — `schema.sql` users.account_level DEFAULT 'basic'
     *                           + `api/auth/register.php` 가 신규 가입자에게 박는 리터럴
     *                           + `effective_grades()` 의 폴백
     *   billing_tier  'free'  — `schema.sql` users.billing_tier DEFAULT 'free'
     *                           + `register.php` 리터럴 + `effective_grades()` 의 폴백
     *                           이자 만료 시 강등 목표
     *   role          'user'  — `schema.sql` users.role DEFAULT 'user'
     *                           + `register.php` 리터럴 + `global_role_of()` 의 fail-closed 기본값
     * **그 네 자리를 고칠 때는 이 상수도 함께 열어라.** 한쪽만 바뀌면 판정이 조용히 헐거워진다.
     *
     * `define()` 을 쓴 이유: 이 파일은 두 곳에서 require 되고 조건부 블록 안이라
     * `const` 문을 쓸 수 없다.
     */
    define('SECRET_GATE_FLOOR', [
        'account_level' => 'basic',
        'billing_tier'  => 'free',
        'role'          => 'user',
    ]);
}

if (!function_exists('secret_gate_axis_over_floor')) {
    /**
     * 한 축의 게이트 값이 그 축의 기본값을 **넘는가**.
     *
     * 미지 열거값은 `rank_of()` 가 0 을 돌려주므로 어떤 floor 도 넘지 못한다 — fail-closed.
     * `basic`(10) 보다 낮은 `guest`(0) 도 같은 이유로 게이트가 아니다.
     */
    function secret_gate_axis_over_floor(array $rankMap, ?string $v, string $floor): bool
    {
        if ($v === null || $v === '') {
            return false;
        }
        return rank_of($rankMap, $v) > rank_of($rankMap, $floor);
    }
}

if (!function_exists('admin_secret_gate_is_effective')) {
    /**
     * 실효 게이트가 하나라도 걸려 있는가.
     *
     * **판정 기준은 「각 축의 기본값 초과」다.** 종전 기준은 「랭크 0 초과」였는데,
     * `basic`(랭크 10)이 `users.account_level` 의 스키마 기본값이자 `register.php` 가
     * 신규 가입자에게 박는 값이어서 **게이트를 걸어 놓은 것처럼 보이면서 아무도 막지 않았다**
     * (v1.6.0 보안 감사 H-1 / 리더 판정 D-201 로 Critical 상향).
     * 실측: `min_account_level: "basic"` 으로 만든 `ai_api_key` 가 신규 일반 회원의
     * 로그인 payload 에 평문으로 실렸고, `allow_public=0` 이라 게이트 점검 화면에도
     * 뜨지 않았다.
     *
     * 「랭크 0 은 게이트가 아니다」는 그 특수 사례로 남는다 — `guest`·`free`·`user` 는
     * 각 축의 기본값 이하이므로 여전히 게이트로 세지 않는다(08a §2 예외·한계 1번).
     */
    function admin_secret_gate_is_effective(?string $minLevel, ?string $minTier, ?string $minRole): bool
    {
        $floor = SECRET_GATE_FLOOR;
        return secret_gate_axis_over_floor(ACCOUNT_LEVEL_RANK, $minLevel, $floor['account_level'])
            || secret_gate_axis_over_floor(BILLING_TIER_RANK, $minTier, $floor['billing_tier'])
            || secret_gate_axis_over_floor(ROLE_RANK, $minRole, $floor['role']);
    }
}

if (!function_exists('secret_gate_level_defeated_by_auto')) {
    /**
     * `min_account_level` 게이트가 **그 서비스가 자동으로 부여하는 등급**으로 무력화되는가.
     *
     * `services.default_account_level` 은 grant-only 오버라이드다(D-35). `core/auth.php` 의
     * `service_grade_overrides()` 가 그 값의 랭크를 전역보다 높을 때 `user_services.account_level`
     * 에 기록하고, `effective_grades()` 는 그 오버라이드를 **전역보다 먼저** 읽는다.
     * 따라서 `default_account_level = 'vip'` 인 서비스에 `min_account_level = 'vip'` 게이트를
     * 걸면 **가입하는 순간 전원이 vip** 가 되어 게이트가 자동으로 열린다.
     *
     * **H-1(기본값 하한)과 별건이다.** `vip` 는 floor `basic` 을 넘으므로 H-1 기준으로는
     * 정상 게이트다. 무력화는 값의 랭크가 아니라 **같은 서비스의 다른 필드와의 조합**에서 온다.
     * H-1 을 고쳐도 이 조합은 그대로 통과했다(v1.6.0 보안 감사 H-2 / 리더 판정 D-202·D-206).
     *
     * **세 축 중 이 축만 그렇다.** `services` 표에 `default_%` 컬럼은 `default_account_level`
     * 하나뿐이고(`schema.sql` + 마이그레이션 0001~0003 전수 확인), `service_grade_overrides()`
     * 는 `role`·`billing_tier` 오버라이드를 **어떤 가입 경로에서도 항상 NULL** 로 넣는다(D-35).
     * 그래서 `min_billing_tier`·`min_role` 은 서비스 필드로 자동 부여될 수 없다 —
     * 게이트 권유 문구가 `min_billing_tier` 를 가리키는 근거가 이것이다.
     *
     * `$minLevel` 이 애초에 floor 이하면 **false 다.** 그 자리는 이 조합 문제가 아니라
     * H-1(`admin_secret_gate_is_effective()`)이 다룬다 — 두 진단이 겹치면 문구가 원인을
     * 잘못 가리킨다.
     *
     * 미지 열거값인 `$autoLevel` 은 `rank_of()` 가 0 을 돌려주므로 무력화로 세지 않는다.
     * 그것이 옳다 — `service_grade_overrides()` 도 그 값으로는 오버라이드를 만들지 않는다
     * (`level_gte('basic', <미지값>)` 이 참이라 grant-only 판정이 「기록하지 않음」으로 떨어진다).
     *
     * @param ?string $autoLevel 이 서비스가 가입자에게 자동으로 보장하는 등급.
     *                           자동 부여 경로가 없으면 null 을 넘긴다
     */
    function secret_gate_level_defeated_by_auto(?string $minLevel, ?string $autoLevel): bool
    {
        if (!secret_gate_axis_over_floor(ACCOUNT_LEVEL_RANK, $minLevel, SECRET_GATE_FLOOR['account_level'])) {
            return false;
        }
        if ($autoLevel === null || $autoLevel === '') {
            return false;
        }
        return rank_of(ACCOUNT_LEVEL_RANK, $autoLevel) >= rank_of(ACCOUNT_LEVEL_RANK, (string) $minLevel);
    }
}

if (!function_exists('secret_service_auto_level')) {
    /**
     * 이 서비스가 **가입자에게 자동으로 보장하는 계정등급.** 없으면 null.
     *
     * `services.default_account_level` 을 부여하는 호출부는 **둘**이고 게이트가 서로 다르다:
     *   1) `core/auth.php` `ensure_user_service()` — 최초 로그인 자동 가입. **`auto_join=1`**
     *   2) `api/auth/register.php` — `service_id` 동반 회원가입. **`allow_register=1`**
     *      (그 파일이 「auto_join=0 이어도 명시적 가입 경로이므로 만든다」로 명시한다)
     *
     * **그래서 조건은 `auto_join` 하나가 아니라 `auto_join OR allow_register` 다.**
     * v1.6.0 의 첫 H-2 수정은 `auto_join` 만 보았고, 그 결과 `auto_join=0` +
     * `allow_register=1`(스키마 기본값) 조합이 판정을 빠져나갔다 — 그 서비스로 가입한
     * 신규 회원이 게이트된 키를 받는 것을 실측으로 재현했다.
     *
     * `status`(disabled) 는 보지 않는다. disabled 서비스는 지금 가입을 받지 않지만
     * 다음 수정이 되살릴 수 있고, 그때 게이트가 조용히 열려 있으면 안 된다 — fail-closed.
     *
     * **규칙은 이 함수 하나뿐이다.** 번들 검증기(`sb_service_auto_level()`)는 번들 값과
     * DB 값을 합쳐 실효값을 만든 뒤 이 함수를 부르고, 관리자 콘솔(`api/admin/secrets.php`)
     * 은 `services` 행을 그대로 넘긴다. 사본을 만들지 마라 — 「규칙이 두 곳에 있다」로
     * 이 프로젝트가 세 번 다쳤다(D-207).
     */
    function secret_service_auto_level(?string $defaultLevel, bool $autoJoin, bool $allowRegister): ?string
    {
        if (!$autoJoin && !$allowRegister) {
            return null;
        }
        if ($defaultLevel === null || $defaultLevel === '') {
            return null;
        }
        return $defaultLevel;
    }
}

if (!function_exists('secret_service_auto_level_of_row')) {
    /**
     * `services` 행 하나에서 자동 보장 등급을 뽑는다. 행이 없으면 **스키마 기본값**을 쓴다 —
     * `auto_join`·`allow_register` 는 둘 다 `DEFAULT 1` 이고 `default_account_level` 은 NULL 이다.
     *
     * 관리자 콘솔 경로가 쓴다. 번들 경로는 번들 값이 행을 덮을 수 있으므로
     * `sb_service_auto_level()` 이 실효값을 만든 뒤 `secret_service_auto_level()` 을 직접 부른다.
     *
     * @param array|null $svc `SELECT * FROM services` 행
     */
    function secret_service_auto_level_of_row(?array $svc): ?string
    {
        if ($svc === null) {
            return null;                        // default_account_level 이 NULL 이므로 부여할 것이 없다
        }
        return secret_service_auto_level(
            (isset($svc['default_account_level']) && $svc['default_account_level'] !== null)
                ? (string) $svc['default_account_level'] : null,
            !array_key_exists('auto_join', $svc) || (int) $svc['auto_join'] === 1,
            !array_key_exists('allow_register', $svc) || (int) $svc['allow_register'] === 1
        );
    }
}

if (!function_exists('admin_secret_gate_is_effective_in_service')) {
    /**
     * 서비스 컨텍스트를 아는 실효 게이트 판정 — **리더 판정 D-206.**
     *
     * `admin_secret_gate_is_effective()` 는 시크릿 한 건의 `min_*` 만 본다. 그 판정만으로는
     * 「게이트를 걸어 놓은 것처럼 보이면서 아무도 막지 않는다」의 **두 번째 경로**가 열린다
     * (H-1 이 첫 번째였다). 이 함수는 그 축을 서비스가 자동으로 부여하면 **없는 것으로 세고**
     * 나머지 판정은 `admin_secret_gate_is_effective()` 에 그대로 넘긴다 —
     * 랭크 규칙을 여기 복제하지 않는다.
     *
     * 그래서 **두 경로가 하나의 오류(`gate_required`)와 하나의 선언
     * (`allow_public: true` + 최상위 `acknowledge_public_secrets`)으로 모인다.**
     * 선언 형식을 새로 만들지 않은 이유는 그 선언이 DB 에 `allow_public=1` 을 남기고,
     * 그 표시가 **게이트 점검 화면이 실효 공개 시크릿을 찾는 유일한 근거**이기 때문이다.
     * 별도 키를 만들면 그 행이 `allow_public=0` 으로 남아 점검 화면에 뜨지 않는다 —
     * 그 「탐지 화면이 잡지 못한다」가 H-1 을 Critical 로 올린 이유였다(D-201).
     *
     * 기존 `admin_secret_gate_is_effective()` 의 동작은 바꾸지 않았다. 다른 4개 호출부
     * (`api/admin/secrets.php` · `install.php` · `public/admin.html` 사본)는 서비스 필드를
     * 보지 않으므로 그 판정이 그대로 맞다 — 그쪽으로 확장하는 것은 이 라운드의 범위가 아니다.
     */
    function admin_secret_gate_is_effective_in_service(?string $minLevel, ?string $minTier,
                                                       ?string $minRole, ?string $autoLevel): bool
    {
        if (secret_gate_level_defeated_by_auto($minLevel, $autoLevel)) {
            $minLevel = null;
        }
        return admin_secret_gate_is_effective($minLevel, $minTier, $minRole);
    }
}

if (!function_exists('admin_secrets_has_allow_public')) {
    /**
     * `service_secrets.allow_public` 컬럼이 이 DB 에 있는가 (마이그레이션 0002).
     *
     * **코드를 올리고 아직 migrate.php 를 돌리지 않은 설치본이 존재한다.** 그 상태에서
     * 이 컬럼을 무조건 INSERT/UPDATE 에 넣으면 시크릿 생성·수정이 전부 500 이 된다.
     * 컬럼이 없으면 승인 표시를 쓰지 않고 넘어간다 — 그러면 게이트 점검 화면에서
     * 그 행이 '미승인' 으로 보이는데, **그것이 안전한 쪽의 오차**다(승인된 것을
     * 미승인으로 보는 것은 확인을 한 번 더 하게 만들 뿐이고, 반대는 놓치게 만든다).
     * 마이그레이션을 적용하면 그다음 수정부터 정상 기록된다.
     *
     * 요청당 1회만 조회한다. 이 함수를 부르는 곳은 관리자 시크릿 쓰기 경로와
     * 설정 번들 경로뿐이다.
     */
    function admin_secrets_has_allow_public(): bool
    {
        static $has = null;
        if ($has === null) {
            $has = db_val('SELECT COUNT(*) FROM information_schema.COLUMNS'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [T_SECRETS, 'allow_public']) > 0;
        }
        return $has;
    }
}

if (!function_exists('secret_value_rule_check')) {
    /**
     * `value_type` 에 따른 시크릿 값 형식 검사. **판정만 하고 던지지 않는다.**
     *
     * 두 호출자가 실패를 다르게 내보내야 해서 판정과 보고를 분리했다 —
     *   - `api/admin/secrets.php` 의 `admin_secret_value()` 는 `v_fail()` 로 **첫 오류에서 던진다**
     *   - `api/admin/settings_bundle.php` 의 검증기는 오류를 **배열로 모은다**
     * 규칙 자체는 한 곳(여기)에만 둔다. 규칙을 고치면 두 경로가 함께 바뀐다.
     *
     * @param  string      $reason 실패 사유(호출자가 그대로 메시지에 쓴다). 성공이면 건드리지 않는다
     * @return bool        형식이 맞는가
     */
    function secret_value_rule_check(string $value, string $valueType, ?string &$reason = null): bool
    {
        if ($valueType === 'json') {
            $d = json_decode($value, true);
            if ($d === null && strtolower(trim($value)) !== 'null') {
                $reason = 'value_type 이 json 이면 유효한 JSON 문자열이어야 합니다.';
                return false;
            }
            return true;
        }
        if ($valueType === 'url') {
            $p = parse_url(trim($value));
            if ($p === false || empty($p['scheme']) || empty($p['host'])
                || !in_array(strtolower((string) $p['scheme']), ['http', 'https'], true)) {
                $reason = 'value_type 이 url 이면 http(s) 절대 URL 이어야 합니다.';
                return false;
            }
            return true;
        }
        return true;
    }
}
