<?php
declare(strict_types=1);

/**
 * core/payload.php — 서비스 시크릿 payload 조립
 * 담당 계약: §3-5(payload), 스펙 §6(시크릿 반환 정책)
 *
 * 반환 경로는 login / exchange / refresh / session 네 곳뿐이다.
 * 다른 어떤 엔드포인트도 이 함수를 호출하지 않는다.
 */

// 직접 실행 차단 — 리더 판정 D-34. bootstrap.php 를 거치지 않은 요청은 존재하지 않는 파일이다.
if (!defined('ULOGIN_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

/**
 * 서비스 가입이 차단된 회원인가. 리더 판정 D-31 / 감사 H-1.
 *
 * 두 경로로 판정한다 (둘 중 하나만 있어도 막힌다):
 *   1) $grades['membership_status'] — effective_grades() 가 넣어 준다. 호출부가
 *      아무것도 하지 않아도 login/exchange/refresh/session 네 경로 전부에서 동작한다
 *   2) $us — 호출부가 user_services 행을 직접 넘긴 경우의 명시적 방어
 *
 * 'active' 가 아닌 모든 값을 차단으로 본다 (열거값이 늘어나도 기본 거부가 되게).
 *
 * **미가입(행 없음)은 차단이 아니다** — 여기서는 false 다. 그래서 D-48 이후
 * build_payload() 는 이 함수가 아니라 `membership_active()` 를 쓴다.
 * 이 함수는 "가입했는데 차단됐는가"만 답한다(공개 API 유지 — 07a §2-2).
 */
function membership_blocked(array $grades, ?array $us = null): bool
{
    if ($us !== null && (string) ($us['status'] ?? 'active') !== 'active') {
        return true;
    }
    $ms = $grades['membership_status'] ?? null;
    return $ms !== null && (string) $ms !== 'active';
}

/**
 * 이 서비스의 **활성 가입자**인가. 리더 판정 D-48 / 감사 M-14.
 *
 * `membership_blocked()` 의 부정이 아니다. 세 상태를 구분한다:
 *   - 가입 행 없음(미가입)  → false  (D-48 로 payload 미포함. 단 로그인은 성공한다)
 *   - 가입 행 있고 blocked  → false  (D-31. 로그인 자체가 403 으로 거부된다)
 *   - 가입 행 있고 active   → true
 *
 * 판정 근거:
 *   1) `$grades['membership_status']` — effective_grades() 가 항상 넣어 준다.
 *      키가 있으면 **이쪽이 가입 여부의 유일한 근거**다. `null` = 행 없음(미가입),
 *      `'active'` = 활성 가입, 그 외 = 차단. 호출부가 아무것도 하지 않아도
 *      login/exchange/refresh/session 네 경로 전부에서 동작한다 — 네 곳 모두
 *      effective_grades($user, $us) 의 결과를 그대로 넘기기 때문이다.
 *   2) `$us` — **좁히기만 한다.** 넘어온 행이 'active' 가 아니면 false.
 *      절대 넓히지 않는다: `$grades` 가 '미가입' 이라고 말하는데 `$us` 로 활성 행을
 *      들이밀어 통과시키는 경로를 만들면 그 자체가 우회로가 된다.
 *      `$grades` 에 키가 아예 없을 때(= effective_grades() 산출물이 아닐 때)만
 *      `$us` 가 단독 근거가 될 수 있다.
 *
 * `$grades` 에 키가 없고 `$us` 도 없으면 '미가입' 이다 — 새 호출부가 판정 배열을
 * 손으로 조립해 넘기더라도 시크릿이 새지 않는 쪽으로 떨어져야 한다(fail-closed).
 */
function membership_active(array $grades, ?array $us = null): bool
{
    // (2) 좁히기 — 넘어온 행이 활성이 아니면 여기서 끝난다.
    if ($us !== null && (string) ($us['status'] ?? 'active') !== 'active') {
        return false;
    }
    // (1) grades 가 effective_grades() 산출물이면 그것이 가입 여부의 근거다.
    //     'membership_status' => null 은 "행이 없다"는 **적극적인 답**이므로
    //     ?? 로 뭉개지 말고 키 존재 여부로 구분해야 한다.
    if (array_key_exists('membership_status', $grades)) {
        $ms = $grades['membership_status'];
        return $ms !== null && (string) $ms === 'active';
    }
    // grades 에 가입 정보가 없다. 이때만 $us 가 단독 근거다.
    return $us !== null && (string) ($us['status'] ?? 'active') === 'active';
}

/**
 * 등급 미달 키는 에러가 아니라 '미포함' 으로 처리한다.
 * 어떤 키가 존재하는지 자체가 정보이므로 알려주지 않는다.
 * 프론트는 payload.ai_api_key 가 없을 때의 분기만 처리하면 된다.
 *
 * service_id 컨텍스트가 없으면 {} — null 이 아니다.
 *
 * @param array      $grades effective_grades() 결과
 * @param array|null $us     user_services 행. 넘기지 않아도 $grades 로 가입 여부가 판정된다
 */
function build_payload(?string $serviceId, array $grades, ?array $us = null): array
{
    if ($serviceId === null || $serviceId === '') {
        return [];
    }

    // D-48(감사 M-14) + D-31(감사 H-1): **활성 가입자에게만** 시크릿을 담는다.
    //
    //  - 미가입: auto_join=0 서비스는 "가입 절차를 따로 거친다"는 뜻이다. service_id 만
    //    알면 시크릿이 나오면 그 의도가 무력화된다. auto_join=1 서비스는 로그인 시
    //    ensure_user_service() 가 행을 만들므로 이 판정에 걸리지 않는다(회귀 없음).
    //    **로그인 자체는 거부하지 않는다** — 가입 심사 중인 사용자도 로그인은 되어야 한다.
    //    거부는 payload 에 국한되고, 응답은 빈 객체 {} 다.
    //  - blocked: D-31. 로그인 자체의 403 거부는 api/auth/login.php · exchange.php ·
    //    sso/authorize.php 가 service_membership_blocked() 로 처리한다 (core/auth.php).
    //
    // 두 경우 모두 등급 미달과 같은 규약으로 '조용히 빈 객체' 다 — 전용 에러를 내면
    // "이 서비스에 이 키가 있다"는 오라클이 된다.
    //
    // 판정은 반드시 이 함수 안에서 한다. 호출부 4곳(login/exchange/refresh/session)에
    // 조건을 흩어 놓으면 하나만 빠져도 구멍이 되고, session.php 는 소유자가 없다.
    if (!membership_active($grades, $us)) {
        return [];
    }

    $rows = db_all(
        'SELECT secret_key, secret_value_enc, value_type, min_account_level, min_billing_tier, min_role'
        . ' FROM ' . T_SECRETS . ' WHERE service_id = ? AND status = ? ORDER BY sort_order ASC, id ASC',
        [$serviceId, 'active']
    );

    $out = [];
    foreach ($rows as $r) {
        if (!empty($r['min_account_level']) && !level_gte($grades['account_level'] ?? null, (string) $r['min_account_level'])) {
            continue;
        }
        if (!empty($r['min_billing_tier']) && !tier_gte($grades['billing_tier'] ?? null, (string) $r['min_billing_tier'])) {
            continue;
        }
        if (!empty($r['min_role']) && !role_gte($grades['role'] ?? null, (string) $r['min_role'])) {
            continue;
        }

        $v = dec((string) $r['secret_value_enc']);
        if ($v === '') {
            // 복호화 실패(키 교체 등). 깨진 값을 앱에 내려보내면 원인 추적이 어려워진다.
            error_log('[ulogin] secret decrypt failed. service=' . $serviceId . ' key=' . $r['secret_key']);
            continue;
        }
        if ($r['value_type'] === 'json') {
            $decoded = json_decode($v, true);
            $out[(string) $r['secret_key']] = $decoded === null ? $v : $decoded;
        } else {
            $out[(string) $r['secret_key']] = $v;
        }
    }
    return $out;
}
