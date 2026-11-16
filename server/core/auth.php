<?php
declare(strict_types=1);

/**
 * core/auth.php — 세션·토큰 발급/검증/회수, SSO 보조
 * 담당 계약: §4-2~4-5, §4-9, 스펙 §5(세션·토큰 정책)
 *
 * access_token / refresh_token 은 64자 hex 난수다. JWT 가 아니다.
 * DB 에는 SHA-256 해시만 저장한다. session_id 는 토큰과 별개의 공개 식별자다.
 *
 * **인증자는 access_token 원문 하나뿐이다** (리더 판정 D-29 / 감사 C-1).
 * Bearer 헤더로 오든 ULOGIN_SID 쿠키로 오든 조회는 `access_token_hash` 로만 한다.
 * session_id 는 어떤 경로에서도 인증자가 아니다 — 응답에 실려 나가는 값이기 때문이다.
 */

// 직접 실행 차단 — 리더 판정 D-34. bootstrap.php 를 거치지 않은 요청은 존재하지 않는 파일이다.
if (!defined('ULOGIN_BOOTSTRAPPED')) {
    // X-Powered-By 제거 — D-154(2). 이 경로는 bootstrap.php 를 거치지 않는다.
    header_remove('X-Powered-By');
    http_response_code(404);
    exit;
}

// ---------------------------------------------------------------------
// 서비스 · redirect_uri
// ---------------------------------------------------------------------

/** 활성 서비스 조회. 없거나 비활성이면 null. */
function service_load(?string $serviceId): ?array
{
    if ($serviceId === null || $serviceId === '') {
        return null;
    }
    return db_one('SELECT * FROM ' . T_SERVICES . ' WHERE service_id = ? AND status = ?', [$serviceId, 'active']);
}

/** 활성 서비스를 요구한다. 없으면 SSO_INVALID_SERVICE. */
function service_require(?string $serviceId): array
{
    $s = service_load($serviceId);
    if ($s === null) {
        throw new AppError('SSO_INVALID_SERVICE', 400, ['field' => 'service_id']);
    }
    return $s;
}

// ---------------------------------------------------------------------
// 서비스 id 별칭 — v1.8.6 (설계 104 C-1 · C-2 · C-4, 마이그레이션 0006)
//
// **DB 의 진실은 새 id 하나다.** 옛 id 는 `services.former_service_id` 한 칸에만 있고,
// 입력에서 service_id 를 읽는 **그 줄**(`v_service_id()`)에서만 새 id 로 바꾼다. 그 뒤의
// 코드(로그·버킷·code 대조·payload·account_link)는 새 id 만 본다.
// `service_load()` 안에 숨기지 않는다 — exchange 가 code 행의 id 와 **입력 변수**를 직접
// 비교하므로(hash_equals), 조회 함수만 고치면 옛 id 로 받은 code 가 SSO_CODE_INVALID 가 된다.
//
// 시스템에 돌려주는 응답의 service_id 는 클라이언트가 보낸 값을 되돌린다(echo, C-2).
// 응답 값을 자기 설정과 비교하는 시스템이 있으면 새 id 응답이 곧 그 시스템의 로그인 정지다.
// 관리자 API·me/*·로그·감사는 언제나 새 id 다.
// ---------------------------------------------------------------------

/** 이번 요청에서 별칭으로 들어온 id. [새 id => 클라이언트가 보낸 옛 id]. 요청 단위. */
$GLOBALS['ULOGIN_SID_ALIAS'] = [];

/** 별칭 사용 기록 상한(초). 이 안의 두 번째 사용은 갱신하지 않는다 — 매 요청 쓰기를 막는다. */
const SERVICE_ALIAS_TOUCH_SEC = 600;

/**
 * service_id 문자열을 정식 id 로 푼다.
 * 순서: ① `services.service_id` 일치(상태 무관) ② `former_service_id` 일치 + 별칭 유효
 * ①이 먼저다 — 같은 문자열이 두 칸에 있을 수 없게 service_id_taken() 이 막지만,
 * 그 막힘이 뚫려도 별칭이 진짜 서비스를 가리지 못하게 한다.
 * @return array{canonical:string,presented:string,via_alias:bool}|null  없으면 null
 */
function service_id_resolve(string $p): ?array
{
    if ($p === '') {
        return null;
    }
    $hit = db_val('SELECT service_id FROM ' . T_SERVICES . ' WHERE service_id = ?', [$p]);
    if ($hit !== null && $hit !== false) {
        return ['canonical' => (string) $hit, 'presented' => $p, 'via_alias' => false];
    }
    $row = db_one('SELECT service_id FROM ' . T_SERVICES
        . ' WHERE former_service_id = ? AND (former_until IS NULL OR former_until > ?)', [$p, now_utc()]);
    if ($row === null) {
        return null;
    }
    return ['canonical' => (string) $row['service_id'], 'presented' => $p, 'via_alias' => true];
}

/**
 * 시스템·브라우저가 보내는 service_id 입력. `v_string()` + 별칭 해석.
 * 정식 id 를 돌려준다. 해석에 실패하면 **입력 원문**을 돌려준다 — 뒤의 service_require()·
 * secret 검증이 지금과 같은 오류(SSO_INVALID_SERVICE · SSO_CLIENT_AUTH_FAILED)를 낸다.
 * 오류 경로를 새로 만들지 않는다.
 */
function v_service_id(array $in, string $key, bool $required = false): ?string
{
    $raw = v_string($in, $key, 1, 64, $required);
    if ($raw === null) {
        return null;
    }
    $r = service_id_resolve($raw);
    if ($r === null) {
        return $raw;
    }
    if ($r['via_alias']) {
        $GLOBALS['ULOGIN_SID_ALIAS'][$r['canonical']] = $r['presented'];
    }
    return $r['canonical'];
}

/** 이번 요청이 이 서비스에 별칭으로 들어왔으면 그 옛 id, 아니면 null. */
function service_id_presented_alias(string $canonical): ?string
{
    return isset($GLOBALS['ULOGIN_SID_ALIAS'][$canonical]) ? (string) $GLOBALS['ULOGIN_SID_ALIAS'][$canonical] : null;
}

/** $alias 가 지금도 $canonical 의 유효한 별칭인가. */
function service_alias_active(string $canonical, string $alias): bool
{
    if ($canonical === '' || $alias === '' || $canonical === $alias) {
        return false;
    }
    return db_val('SELECT id FROM ' . T_SERVICES . ' WHERE service_id = ? AND former_service_id = ?'
        . ' AND (former_until IS NULL OR former_until > ?)', [$canonical, $alias, now_utc()]) !== null;
}

/**
 * 시스템에 돌려줄 service_id (C-2).
 *   이번 요청이 별칭으로 들어왔다 → 그 옛 id
 *   아니면 세션 행의 client_service_id 가 지금도 유효한 별칭이다 → 그 값
 *   아니면 → 정식 id
 * 별칭이 끝나면(former_until 경과) echo 도 멈춘다 — 그때는 시스템이 이미 새 id 를 쓴다.
 */
function service_id_out(string $canonical, ?array $sessionRow = null): string
{
    $p = service_id_presented_alias($canonical);
    if ($p !== null) {
        return $p;
    }
    $c = ($sessionRow !== null && isset($sessionRow['client_service_id'])) ? (string) $sessionRow['client_service_id'] : '';
    if ($c !== '' && service_alias_active($canonical, $c)) {
        return $c;
    }
    return $canonical;
}

/** 직렬화된 user/session 객체의 service_id 를 echo 규칙으로 바꾼다. 시스템 응답 전용. */
function service_id_echo(array &$out, ?array $sessionRow = null): void
{
    if (isset($out['service_id']) && is_string($out['service_id']) && $out['service_id'] !== '') {
        $out['service_id'] = service_id_out($out['service_id'], $sessionRow);
    }
}

/**
 * 별칭 사용 기록 — **secret 을 검증한 요청에서만** 부른다(exchange 성공 · al_s2s_auth 성공).
 * validate·authorize 는 인증 없는 공개 요청이라 세지 않는다 — 누구나 두드려
 * 「아직 쓰는 중」으로 보이게 해 별칭 종료를 막을 수 있다.
 * 10분에 1회 상한은 조건부 UPDATE 가 진다(읽고 쓰기 경합 없음).
 */
function service_alias_touch(string $canonical): void
{
    if (service_id_presented_alias($canonical) === null) {
        return;
    }
    try {
        db_exec('UPDATE ' . T_SERVICES . ' SET former_last_used_at = ? WHERE service_id = ?'
            . ' AND (former_last_used_at IS NULL OR former_last_used_at < ?)',
            [now_utc(), $canonical, gmdate('Y-m-d H:i:s', time() - SERVICE_ALIAS_TOUCH_SEC)]);
    } catch (Throwable $e) {
        // 기록 실패가 로그인을 막지 않는다. 종료 판단이 늦어질 뿐이다.
        error_log('[ulogin] alias last-used update failed: ' . $e->getMessage());
    }
}

/**
 * 새 서비스 id 로 쓸 수 없는가 (C-4). 쓸 수 없으면 사유, 쓸 수 있으면 null.
 * `services.service_id` 또는 `former_service_id`(별칭 종료 뒤 포함)에 있으면 taken.
 * 두 열에 걸친 UNIQUE 는 DB 가 못 한다 — 그래서 판정을 이 함수 하나에 둔다.
 * 부르는 곳: admin/services 생성 · settings_bundle 신규 서비스 · register_services 등록 · 개명 대상.
 */
const SERVICE_ID_RESERVED = ['ulogin', '*'];

function service_id_taken(string $id): ?string
{
    // v1.9.0 보안 L190-1 — 예약 id. `ulogin` 은 통합로그인 자기 감사의 service_id, `*` 는 전역 설정 범위다.
    // 그 id 로 서비스를 등록하면 s2s 감사가 ulogin 자기 감사 행처럼 체인에 들어간다.
    if (in_array(strtolower($id), SERVICE_ID_RESERVED, true)) {
        return 'reserved';
    }
    if (db_val('SELECT id FROM ' . T_SERVICES . ' WHERE service_id = ?', [$id]) !== null) {
        return 'exists';
    }
    if (db_val('SELECT id FROM ' . T_SERVICES . ' WHERE former_service_id = ?', [$id]) !== null) {
        return 'former';
    }
    return null;
}

/**
 * 관리자 API 의 service_id 입력 — **별칭을 받지 않는다** (B-2 · E-1).
 * 개명 전 id 면 400 VALIDATION_FAILED(field=service_id). 관리 화면은 목록에서 고르므로
 * 옛 id 가 들어오면 오타·낡은 북마크다. 조용히 0건을 보여 주면 안 된다.
 */
function admin_service_id_reject_former(?string $sid): void
{
    if ($sid === null || $sid === '') {
        return;
    }
    if (db_val('SELECT id FROM ' . T_SERVICES . ' WHERE service_id = ?', [$sid]) !== null) {
        return;
    }
    $now = db_val('SELECT service_id FROM ' . T_SERVICES . ' WHERE former_service_id = ?', [$sid]);
    if ($now !== null) {
        throw new AppError('VALIDATION_FAILED', 400, ['field' => 'service_id',
            'message' => '개명 전 서비스 ID입니다. 현재 ID는 ' . (string) $now . '입니다.']);
    }
}

// =====================================================================
// v1.10.1 가입 방식 closed|approval|open (설계 130 §1 · D-371)
//   v1.8.2 의 registration_open() 은 아래 register_mode_effective() 의 얇은 포장으로 남는다.
//   부르는 곳 3개가 같은 값을 내야 한다(v1.8.1 의 「링크는 보이는데 가입은 막힘」 재발 방지):
//     api/auth/register.php · api/auth/register_status.php($service = null) · api/sso/validate.php(그 서비스)
// =====================================================================

const REGISTER_MODES     = ['closed', 'approval', 'open'];
const REGISTER_MODE_RANK = ['closed' => 0, 'approval' => 1, 'open' => 2];

/**
 * 전역 가입 방식. **결정 순서는 이 함수 한 곳**(설계 130 §1-1).
 *   1. settings.register_mode 행이 있고 enum 안 → 그 값
 *   2. 행이 있는데 enum 밖·빈 값·NULL → closed + log_throttled (fail-closed)
 *   3. 행 없음 → 옛 allow_register(DB 행 → ALLOW_REGISTER 상수) 참이면 open, 거짓이면 closed
 * 3 이 있는 이유: 0011 직후 모든 설치본의 동작이 v1.10.0 과 같아야 한다(D-371 Q-1).
 *
 * @param array $rows skey => ['svalue' => …] (settings_all() 또는 admin_settings_rows() 모양)
 */
function register_mode_global_from_rows(array $rows): string
{
    if (isset($rows['register_mode'])) {
        $v = $rows['register_mode']['svalue'] ?? null;
        $v = $v === null ? '' : (string) $v;
        if (in_array($v, REGISTER_MODES, true)) {
            return $v;
        }
        log_throttled('register_mode_invalid', '[ulogin] settings.register_mode is not closed|approval|open - treated as closed');
        return 'closed';
    }
    if (isset($rows['allow_register']) && $rows['allow_register']['svalue'] !== null) {
        $raw   = (string) $rows['allow_register']['svalue'];
        $allow = ($raw === '1' || $raw === 'true' || $raw === 'on');
    } else {
        $allow = defined('ALLOW_REGISTER') ? (bool) ALLOW_REGISTER : false;
    }
    return $allow ? 'open' : 'closed';
}

/** 서비스 상한. allow_register=1 → open, 0 → closed. 서비스가 없으면(직접 가입) 상한 없음. */
function register_mode_service_cap(?array $service): string
{
    if ($service === null) {
        return 'open';
    }
    return empty($service['allow_register']) ? 'closed' : 'open';
}

/** 둘 중 더 엄격한 쪽. 모르는 값은 closed. */
function register_mode_stricter(string $a, string $b): string
{
    if (!isset(REGISTER_MODE_RANK[$a]) || !isset(REGISTER_MODE_RANK[$b])) {
        return 'closed';
    }
    return REGISTER_MODE_RANK[$a] <= REGISTER_MODE_RANK[$b] ? $a : $b;
}

/**
 * 가입 방식 유효값 — **판정은 이 함수 한 곳에만 있다.**
 * 유효값 = min(전역, 서비스 상한). 서비스 플래그로 전역보다 느슨해질 수 없다(설계 130 §1-2 9칸 표).
 */
function register_mode_effective(?array $service = null): string
{
    return register_mode_stricter(register_mode_global_from_rows(settings_all()), register_mode_service_cap($service));
}

/** 신규 가입(또는 신청)을 받는가. approval 도 true 다(링크를 보여 「가입 신청」으로 표기 — §1-3). */
function registration_open(?array $service = null): bool
{
    return register_mode_effective($service) !== 'closed';
}

/**
 * 쓰기 목표 [mode, allow_register]. $mode(새 키)가 있으면 그것, 없으면 옛 키 규칙(설계 130 §1-1):
 *   allow_register=false → closed · true → 현재 closed 면 open, approval·open 이면 그대로.
 * allow_register 는 언제나 (mode === 'open') — v1.10.0 으로 되돌려도 approval 은 「닫힘」으로 떨어진다.
 */
function register_mode_write_target(?string $mode, ?bool $legacyAllow, string $current): array
{
    if ($mode === null) {
        if ($legacyAllow === null) {
            $mode = $current;
        } elseif ($legacyAllow === false) {
            $mode = 'closed';
        } else {
            $mode = $current === 'closed' ? 'open' : $current;
        }
    }
    if (!in_array($mode, REGISTER_MODES, true)) {
        $mode = 'closed';
    }
    return [$mode, $mode === 'open'];
}

/** register_mode 와 allow_register 를 **같은 트랜잭션**에서 쓴다(롤백 안전). */
function register_mode_write(string $mode): void
{
    list($m, $allow) = register_mode_write_target($mode, null, 'closed');
    db_tx(function () use ($m, $allow) {
        setting_set('register_mode', $m, 'string', '가입 방식');
        setting_set('allow_register', $allow, 'bool', '신규 가입 허용');
    });
}

/**
 * 계정 생성 본문(users · user_services · 가입 보너스 · 약관 동의). **반드시 db_tx() 안에서 부른다.**
 * open 경로(register.php)와 승인 경로(admin/register_requests.php approve)가 공유한다(설계 130 §2-1).
 *
 * @param array       $f  username, email, password_hash, name, nickname, phone, marketing(bool),
 *                        require_verify(bool), bonus(int), consents(null = 지금 발행본 | [{code, terms_id}])
 * @param string|null $consentAt 동의 시각(null 이면 $now). 승인 경로는 신청 시각
 */
function register_create_user(array $f, ?array $svc, string $now, string $ip, ?string $consentAt): int
{
    // users 행을 변수로 잡아 둔다 — 아래 user_services 의 grant-only 판정이
    // **같은 전역 등급**을 보아야 한다(D-49). 리터럴을 두 번 쓰면 어긋난다.
    $bonus   = max(0, (int) $f['bonus']);
    $userRow = [
        'uuid'                => gen_uuid4(),
        'username'            => (string) $f['username'],
        'email'               => (string) $f['email'],
        'password_hash'       => (string) $f['password_hash'],
        'password_changed_at' => $now,
        'name'                => $f['name'] ?? null,
        'nickname'            => $f['nickname'] ?? null,
        'phone'               => $f['phone'] ?? null,
        'status'              => !empty($f['require_verify']) ? 'pending' : 'active',
        'account_level'       => 'basic',
        'role'                => 'user',
        'billing_tier'        => 'free',
        'points'              => $bonus,
        'total_points_earned' => $bonus,
        'total_points_spent'  => 0,
        'marketing_agreed'    => !empty($f['marketing']) ? 1 : 0,
        'timezone'            => 'Asia/Seoul',
        'locale'              => 'ko-KR',
        'created_at'          => $now,
        'updated_at'          => $now,
    ];
    $uid = db_insert(T_USERS, $userRow);

    // 서비스 매핑 (auto_join=0 이어도 명시적 가입 경로이므로 만든다)
    // 등급 오버라이드는 **grant-only** 다 — D-49 / M-16 / Q-3. 판정은 service_grade_overrides() 한 곳.
    if ($svc !== null) {
        $ov = service_grade_overrides($svc, $userRow);
        db_insert(T_USER_SERVICES, [
            'user_id'       => $uid,
            'service_id'    => (string) $svc['service_id'],
            'account_level' => $ov['account_level'],   // NULL = 전역 상속
            'role'          => $ov['role'],            // 항상 NULL
            'billing_tier'  => $ov['billing_tier'],    // 항상 NULL
            'status'        => 'active',
            'joined_at'     => $now,
            'last_used_at'  => $now,
        ]);
    }

    if ($bonus > 0) {
        log_point($uid, [
            'delta'         => $bonus,
            'balance_after' => $bonus,
            'reason'        => 'signup_bonus',
            'service_id'    => $svc['service_id'] ?? null,
            'memo'          => '가입 보너스',
        ]);
    }

    $consents = $f['consents'] ?? null;
    if ($consents === null) {
        $consents = register_consents_now(!empty($f['marketing']));
    }
    foreach ($consents as $c) {
        $tid = is_array($c) ? (int) ($c['terms_id'] ?? 0) : 0;
        if ($tid <= 0) {
            continue;
        }
        db_insert(T_CONSENTS, [
            'user_id'   => $uid,
            'terms_id'  => $tid,
            'agreed'    => 1,
            'agreed_at' => $consentAt !== null ? $consentAt : $now,
            'ip'        => $ip,
        ]);
    }
    return $uid;
}

/** 지금 발행된 최신 약관 [{code, terms_id}]. 약관이 아직 없는 설치본은 빈 배열(가입은 되어야 한다). */
function register_consents_now(bool $marketing): array
{
    $codes = ['tos', 'privacy'];
    if ($marketing) {
        $codes[] = 'marketing';
    }
    $out = [];
    foreach ($codes as $code) {
        $t = db_one(
            'SELECT id FROM ' . T_TERMS . ' WHERE code = ? AND published_at IS NOT NULL'
            . ' ORDER BY published_at DESC, id DESC LIMIT 1',
            [$code]
        );
        if ($t !== null) {
            $out[] = ['code' => $code, 'terms_id' => (int) $t['id']];
        }
    }
    return $out;
}

/** 이메일 인증 메일(require_email_verify=1 일 때). register.php 와 승인 경로가 같이 쓴다. */
function register_send_verify_mail(int $userId, string $email, string $ip): void
{
    $token = gen_token(32);
    db_insert(T_EMAIL_VERIFY, [
        'token_hash' => token_hash($token),
        'user_id'    => $userId,
        'email'      => $email,
        'expires_at' => at_utc((int) EMAIL_VERIFY_TTL),
        'created_ip' => $ip,
        'created_at' => now_utc(),
    ]);
    $link = base_url() . '/public/verify.html?token=' . rawurlencode($token);
    send_mail(
        $email,
        '[' . setting_get('site_name', SITE_NAME) . '] 이메일 인증',
        "아래 링크를 눌러 이메일 인증을 완료해 주세요. (24시간 유효)\n\n" . $link . "\n"
    );
}

/**
 * 승인 대기 신청의 비밀번호 해시(로그인 「계정 없음」 분기 전용, 설계 130 §2-4).
 * 같은 username·email 의 **유효한 pending** 신청이 있으면 그 해시, 없으면 null. 표가 없으면(0011 전) null.
 */
function register_pending_hash(string $norm): ?string
{
    $r = register_pending_row($norm);
    return $r === null ? null : (string) $r['password_hash'];
}

/** v1.10.1 L-3 — 위와 같은 조회에 신청 id 를 함께(잠금 카운트 키). 없으면 null. */
function register_pending_row(string $norm): ?array
{
    if (!defined('T_REGISTER_REQUESTS')) {
        return null;
    }
    try {
        $r = db_one('SELECT id, password_hash FROM ' . T_REGISTER_REQUESTS
            . ' WHERE (username = ? OR email = ?) AND status = ? AND expires_at > ? AND password_hash IS NOT NULL'
            . ' ORDER BY id DESC LIMIT 1', [$norm, $norm, 'pending', now_utc()]);
    } catch (Throwable $e) {
        log_throttled('regreq_pending_lookup', '[ulogin] register_requests lookup failed (migration 0011 pending?): ' . get_class($e));
        return null;
    }
    return $r === null ? null : ['id' => (int) $r['id'], 'password_hash' => (string) $r['password_hash']];
}

/**
 * redirect_uri 를 origin+path 로 정규화한다.
 * 쿼리스트링·프래그먼트는 비교에서 제외한다 (스펙 §4-2).
 * 실패하면 빈 문자열 — 빈 문자열끼리는 매칭시키지 않는다.
 */
function normalize_uri(?string $uri): string
{
    if ($uri === null || trim($uri) === '') {
        return '';
    }
    $p = parse_url(trim($uri));
    if ($p !== false && !empty($p['scheme']) && empty($p['host']) && !empty($p['path'])) {
        // v1.10.0 D-369 — RFC 8252 사용자 지정 scheme 의 경로형(com.example.app:/cb). 화이트리스트에는
        // 공개 클라이언트만 이런 항목을 둘 수 있다(core/urlrules.php). 대조는 여전히 완전 일치다.
        $cs = strtolower((string) $p['scheme']);
        if ($cs !== 'http' && $cs !== 'https' && preg_match('/^[a-z][a-z0-9+.\-]{2,63}$/', $cs) === 1
            && !in_array($cs, ['javascript', 'data', 'file', 'vbscript', 'blob', 'about', 'ftp', 'ws', 'wss'], true)) {
            return $cs . ':' . (string) $p['path'];
        }
        return '';
    }
    if ($p === false || empty($p['scheme']) || empty($p['host'])) {
        return '';
    }
    $scheme = strtolower((string) $p['scheme']);
    $host   = strtolower((string) $p['host']);
    $port   = isset($p['port']) ? (int) $p['port'] : null;
    if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
        $port = null;   // 기본 포트는 표기 차이일 뿐이다
    }
    $path = isset($p['path']) ? (string) $p['path'] : '';
    if ($path === '/') {
        $path = '';
    }
    return $scheme . '://' . $host . ($port !== null ? ':' . $port : '') . $path;
}

/**
 * 화이트리스트 대조. origin+path 완전 일치만 허용한다.
 * 접두사 매칭은 금지 — `http://localhost:5173/callback.html.evil.com` 류 우회를 허용한다.
 */
function redirect_uri_allowed(array $service, ?string $uri): bool
{
    $target = normalize_uri($uri);
    if ($target === '') {
        return false;
    }
    foreach (json_col($service['redirect_whitelist'] ?? null) as $w) {
        if (!is_string($w)) {
            continue;
        }
        $n = normalize_uri($w);
        if ($n !== '' && hash_equals($n, $target)) {
            return true;
        }
    }
    return false;
}

/**
 * SSO 복귀 주소 조립 — `redirect_to` 를 **서버가 만든다**.
 * 담당 계약: 01_architect_api_contract.md §5-2 / 설계 §2-3(12)
 *
 * 조립 로직이 서버와 프론트 두 곳에 있으면 반드시 어긋난다.
 * 프래그먼트(#)가 있으면 쿼리는 그 앞에 붙어야 한다.
 *
 * ── 왜 이 함수가 새로 생겼나, 그리고 왜 authorize.php 는 안 쓰나 ──────
 * v1.7.0 이 `api/sso/authorize_session.php`(무음 인가)를 추가하면서
 * 같은 조립이 두 번째로 필요해졌다. 설계(§9 backend 2)는 「공용 헬퍼로 빼라」고
 * 했고 그 판단은 옳다 — 두 곳에 복사하면 어긋난다.
 *
 * **그러나 리더 판정 D-234 가 `api/sso/authorize.php` 를 한 줄도 고치지 못하게 한다.**
 * 그래서 이 함수는 지금 호출자가 **authorize_session.php 하나뿐**이고,
 * `authorize.php` 는 자기 안의 인라인 사본(12번 블록)을 그대로 쓴다.
 * **두 구현은 글자 그대로 같다. 한쪽을 고치면 반드시 다른 쪽도 고쳐라.**
 * authorize.php 를 이 함수로 돌리는 것은 그 파일의 동결이 풀리는 라운드의 일이다
 * (`_workspace/73_backend_v17_report.md` 에 미해소 항목으로 올렸다).
 *
 * @param string  $redirectUri 화이트리스트를 이미 통과한 복귀 주소
 * @param string  $code        발급된 auth code 원문
 * @param ?string $state       클라이언트가 준 값. null 이면 붙이지 않는다
 */
function sso_build_redirect_to(string $redirectUri, string $code, ?string $state): string
{
    $frag = '';
    $base = $redirectUri;
    $hash = strpos($base, '#');
    if ($hash !== false) {
        $frag = substr($base, $hash);
        $base = substr($base, 0, $hash);
    }
    $sep        = strpos($base, '?') === false ? '?' : '&';
    $redirectTo = $base . $sep . 'code=' . rawurlencode($code);
    if ($state !== null) {
        $redirectTo .= '&state=' . rawurlencode($state);
    }
    return $redirectTo . $frag;
}

// ---------------------------------------------------------------------
// 아이디 · 비밀번호 정책
// ---------------------------------------------------------------------

/** 아이디는 소문자로 정규화해 저장·조회한다. */
function normalize_username(string $u): string
{
    return mb_strtolower(trim($u), 'UTF-8');
}

/** 계약 §4-1: username `^[a-z0-9_]{4,20}$` (소문자 정규화 후) */
function validate_username_format(string $u): void
{
    if (preg_match('/^[a-z0-9_]{4,20}$/', $u) !== 1) {
        v_fail('username', '아이디는 영문 소문자·숫자·밑줄 4~20자여야 합니다.');
    }
}

/**
 * 비밀번호 정책. 스펙 §8-1.
 *   8자 이상(설정값) + 영문/숫자/특수 중 2종 이상 + username 과 동일 문자열 금지
 */
function validate_password(string $pw, ?string $username = null, string $field = 'password', int $roleRank = 0): void
{
    // v1.9.1 D-362 - role_rank 20 이상은 10자 이상(v1.10.1 D-373, 종전 12) + 흔한 비밀번호 거절(호출자가 대상 계정의 rank 를 넘긴다)
    em_enforce_strong_password($pw, $username, $roleRank, $field);
    $min = (int) setting_get('password_min_length', PASSWORD_MIN_LENGTH);
    if ($min < 8) {
        $min = 8;
    }
    if (mb_strlen($pw, 'UTF-8') < $min) {
        v_fail($field, '비밀번호는 ' . $min . '자 이상이어야 합니다.');
    }
    if (mb_strlen($pw, 'UTF-8') > 200) {
        v_fail($field, '비밀번호는 200자 이내여야 합니다.');
    }
    // 감사 V11-L-5 / 리더 판정 D-66.
    // password_hash() 는 bcrypt = C 문자열이므로 **첫 NUL 에서 절단**된다.
    // 막지 않으면 "8자 이상 · 2종 이상" 을 통과한 비밀번호가 실제로는 앞 3자 해시로
    // 저장되고 그 3자만으로 로그인이 된다(감사 실측: "Abc\0defgh1" → "Abc" 로 로그인 성공).
    // 즉 서버가 선언한 정책이 **저장된 해시에는 적용되지 않는** 상태가 된다.
    // 또 선행 NUL 은 CVE-2024-3096(`password_verify('', $hash)` 가 true, 7.2 미패치)의 전제다.
    //
    // 현재 유일한 방어는 v_string() 의 trim() 이 PHP 기본 문자셋(" \t\n\r\0\x0B")에
    // NUL 을 포함해 **선행·후행** NUL 을 우연히 지워주는 것이다. 중간 NUL 은 남고,
    // trim() 을 거치지 않는 새 입력 경로가 생기면 즉시 성립한다. 우연에 의존하지 않는다.
    // 이 함수는 register / password_reset / me/password 세 저장 경로 전부를 지난다.
    if (strpos($pw, "\0") !== false) {
        v_fail($field, '비밀번호에 사용할 수 없는 문자가 포함되어 있습니다.');
    }
    $kinds = 0;
    $kinds += preg_match('/[A-Za-z]/', $pw) === 1 ? 1 : 0;
    $kinds += preg_match('/[0-9]/', $pw) === 1 ? 1 : 0;
    $kinds += preg_match('/[^A-Za-z0-9]/', $pw) === 1 ? 1 : 0;
    if ($kinds < 2) {
        v_fail($field, '비밀번호는 영문·숫자·특수문자 중 2종 이상을 포함해야 합니다.');
    }
    if ($username !== null && $username !== '' && mb_strtolower($pw, 'UTF-8') === mb_strtolower($username, 'UTF-8')) {
        v_fail($field, '아이디와 동일한 비밀번호는 사용할 수 없습니다.');
    }
}

/**
 * 계정이 없어도 반드시 한 번 돌리는 더미 해시.
 * 즉시 반환하면 응답 시간 차이로 계정 존재 여부가 새어나간다.
 */
function dummy_password_verify(string $plain): void
{
    static $dummy = null;
    if ($dummy === null) {
        // 고정 해시. 어떤 비밀번호와도 일치하지 않는다.
        $dummy = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
    }
    password_verify($plain, $dummy);
}

// ---------------------------------------------------------------------
// 쿠키
// ---------------------------------------------------------------------

/**
 * ULOGIN_SID 쿠키를 심는다.
 *
 * **쿠키에 들어가는 값은 access_token 원문이다. session_id 가 아니다.**
 * (리더 판정 D-29 / 감사 C-1) session_id 는 관리자 API 응답과 기기 목록에
 * 실려 나가므로, 그 값을 인증자로 쓰면 목록을 볼 수 있는 권한이 곧
 * 계정 탈취 권한이 된다. access_token 은 DB 에 SHA-256 만 있고 발급
 * 시점의 응답에만 원문이 나간다.
 *
 * @param string $accessToken create_session() 이 발급한 access_token 원문 (64자 hex)
 * @param int    $ttl         쿠키 수명(초). 0 이면 브라우저 세션 쿠키
 */
function set_session_cookie(string $accessToken, int $ttl): void
{
    if (headers_sent()) {
        return;
    }
    // PHP 7.2 호환 — 옵션 배열을 setcookie() 에 직접 넘기면 7.2 에서
    // SameSite·HttpOnly·Secure·Path 가 통째로 사라진다 (D-59). send_cookie() 가 분기한다.
    send_cookie(COOKIE_NAME, $accessToken, cookie_options($ttl));
    $_COOKIE[COOKIE_NAME] = $accessToken;
}

/**
 * refresh 회전 후 쿠키를 새 access_token 으로 다시 심는다.
 * rotate_refresh() 가 쿠키 세션을 스스로 감지해 호출하므로 보통은 필요 없지만,
 * api/ 쪽에서 명시적으로 갱신해야 할 때(예: 쿠키 TTL 을 바꿀 때) 쓰는 공개 진입점이다.
 * set_session_cookie() 의 의미를 이름으로 드러내기 위한 얇은 래퍼다.
 */
function refresh_session_cookie(string $accessToken, int $ttl): void
{
    set_session_cookie($accessToken, $ttl);
}

/**
 * 현재 요청의 ULOGIN_SID 쿠키가 이 세션 행을 가리키는가.
 * 쿠키에는 access_token 원문이 있고 DB 에는 SHA-256 만 있으므로 해시로 비교한다.
 * 비교는 hash_equals — === 는 타이밍 차이를 남긴다.
 */
function cookie_binds_session(?array $sessionRow): bool
{
    if ($sessionRow === null || empty($sessionRow['access_token_hash'])) {
        return false;
    }
    $ck = (string) ($_COOKIE[COOKIE_NAME] ?? '');
    if (!is_token_format($ck)) {
        return false;
    }
    return hash_equals((string) $sessionRow['access_token_hash'], token_hash($ck));
}

function clear_session_cookie(): void
{
    if (headers_sent()) {
        return;
    }
    $opt = cookie_options(0);
    $opt['expires'] = time() - 3600;
    // PHP 7.2 호환 (D-59). 값이 빈 문자열이므로 두 분기 모두 PHP 본체와 같은
    // `name=deleted; expires=Thu, 01-Jan-1970 00:00:01 GMT; Max-Age=0` 형태를 낸다.
    // 하이픈이 맞다 (리더 판정 D-68) — PHP 본체는 RFC 7231 의 공백 형식(`D, d M Y`)이
    // 아니라 **구 Netscape 형식(`D, d-M-Y`)** 을 쓴다. 이 주석을 공백 형식으로
    // "고치고" 코드를 맞추면 7.2 배포에서만 다른 만료 문자열이 나가는 결함이
    // 되살아난다(12a 라운드가 두 분기 헤더 대조로 실측해 잡은 결함이 그것이다).
    // 형식의 정본은 core/request.php 의 build_set_cookie_header() 독스트링이다.
    // header(..., false) 로 보내므로 두 쿠키가 서로를 덮어쓰지 않는다.
    send_cookie(COOKIE_NAME, '', $opt);
    send_cookie(COOKIE_CSRF_NAME, '', $opt);
    unset($_COOKIE[COOKIE_NAME], $_COOKIE[COOKIE_CSRF_NAME]);
}

// ---------------------------------------------------------------------
// 세션 생성
// ---------------------------------------------------------------------

/**
 * 세션 1건 + 토큰 발급.
 *
 * v1.0.0 은 로그인 1회당 세션 행을 하나만 만든다.
 * auth_type='token' 으로 저장하고, 필요하면 **같은 access_token 원문**을 쿠키에도 심는다.
 * (두 행으로 나누면 Bearer 로그아웃이 쿠키 세션을 살려두는 구멍이 생긴다)
 *
 * 쿠키에 session_id 를 넣지 않는다 — 리더 판정 D-29. set_session_cookie() 주석 참고.
 *
 * $opt:
 *   device_label  string|null  클라이언트가 준 기기명
 *   with_refresh  bool         refresh 토큰 발급 여부 (기본 true)
 *   set_cookie    bool         ULOGIN_SID 쿠키 동시 발급 (기본 false)
 *   cookie_ttl    int          쿠키 수명. 미지정/null 이면 access TTL,
 *                              0 이면 브라우저 세션 쿠키
 *
 * @return array{row:array,session_id:string,access_token:string,refresh_token:?string,expires_in:int,refresh_expires_in:?int}
 */
function create_session(array $user, ?array $service, string $authType, array $opt = []): array
{
    $withRefresh = array_key_exists('with_refresh', $opt) ? (bool) $opt['with_refresh'] : true;

    $sid     = gen_token(32);
    $access  = gen_token(32);
    $refresh = $withRefresh ? gen_token(32) : null;

    $ttl  = (int) ($service['session_ttl'] ?? setting_get('session_ttl_default', SESSION_TTL));
    $rttl = (int) ($service['refresh_ttl'] ?? setting_get('refresh_ttl_default', REFRESH_TTL));
    if ($ttl <= 0) {
        $ttl = (int) SESSION_TTL;
    }
    if ($rttl <= 0) {
        $rttl = (int) REFRESH_TTL;
    }
    // v1.10.0 (설계 127 §6-2) — 비상 계정 세션 상한(1시간). 호출자가 ttl_cap 을 넘긴다.
    if (isset($opt['ttl_cap']) && (int) $opt['ttl_cap'] > 0 && $ttl > (int) $opt['ttl_cap']) {
        $ttl = (int) $opt['ttl_cap'];
    }

    // service_id 는 서비스 행에서 온다. 행이 없을 때만 $opt['service_id_fallback'] 을 쓴다 —
    // 리더 판정 D-162. 지금 이것을 쓰는 곳은 rotate_refresh() 하나이고, 이유는 그 함수의
    // 주석에 있다. **넓히는 용도로 쓰지 마라**: 서비스가 존재하지 않는데 세션에
    // service_id 를 심는 경로를 만들면 payload 판정이 아니라 세션의 뜻이 흐려진다.
    $now = now_utc();
    $row = [
        'session_id'         => $sid,
        'user_id'            => (int) $user['id'],
        'service_id'         => isset($service['service_id'])
            ? (string) $service['service_id']
            : (isset($opt['service_id_fallback']) && $opt['service_id_fallback'] !== ''
                ? (string) $opt['service_id_fallback'] : null),
        'auth_type'          => $authType,
        'access_token_hash'  => token_hash($access),
        'refresh_token_hash' => $refresh !== null ? token_hash($refresh) : null,
        'csrf_token_hash'    => null,
        'issued_at'          => $now,
        'expires_at'         => at_utc($ttl),
        'refresh_expires_at' => $refresh !== null ? at_utc($rttl) : null,
        'last_seen_at'       => $now,
        'ip'                 => client_ip(),
        'user_agent'         => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        'device_label'       => $opt['device_label'] ?? device_label_from_ua(),
    ];
    // v1.8.6 — 클라이언트가 옛 id(별칭)로 들어온 세션이면 그 값을 남긴다(설계 104 C-2).
    // 이후 Bearer 요청(session·refresh)은 입력에 service_id 가 없어 이 칸이 유일한 기억이다.
    // **값이 있을 때만 넣는다** — 0006 이전 DB 에서도 보통 경로의 INSERT 가 그대로 돈다.
    if (isset($opt['client_service_id']) && is_string($opt['client_service_id']) && $opt['client_service_id'] !== '') {
        $row['client_service_id'] = $opt['client_service_id'];
    }
    $row['id'] = db_insert(T_SESSIONS, $row);

    if (!empty($opt['set_cookie'])) {
        // 쿠키에 심는 값은 access_token 원문이다 (D-29). session_id 를 심으면
        // 관리자 API 로 세션 목록을 볼 수 있는 권한이 곧 계정 탈취 권한이 된다.
        // cookie_ttl 이 키로 존재하되 null 이면(로그인 remember=false) 0 → 세션 쿠키.
        $cttl = array_key_exists('cookie_ttl', $opt) ? (int) $opt['cookie_ttl'] : $ttl;
        set_session_cookie($access, $cttl);
    }

    return [
        'row'                => $row,
        'session_id'         => $sid,
        'access_token'       => $access,
        'refresh_token'      => $refresh,
        'expires_in'         => $ttl,
        'refresh_expires_in' => $refresh !== null ? $rttl : null,
    ];
}

// ---------------------------------------------------------------------
// 인증 컨텍스트
// ---------------------------------------------------------------------

/**
 * Authorization: Bearer {token}. 일부 공용 호스팅(CGI 모드)은 이 헤더를 PHP 에 전달하지
 * 않는다. .htaccess 의 RewriteRule 로 보정하고, 그래도 안 되면 X-Auth-Token 을 쓴다.
 */
function bearer_token(): ?string
{
    $h = req_header('Authorization');
    if ($h !== null && preg_match('/^Bearer\s+(\S+)$/i', $h, $m)) {
        return $m[1];
    }
    $alt = req_header('X-Auth-Token');
    if ($alt !== null && trim($alt) !== '') {
        return trim($alt);
    }
    return null;
}

/**
 * 계정 상태 판정. 스펙 §2-5. 로그인·세션 검증 양쪽에서 같은 규칙을 쓴다.
 * $strict=false 면 (선택적 인증) 예외 대신 false 를 돌려준다.
 */
function assert_user_active(array $user, bool $strict = true): bool
{
    $st = (string) ($user['status'] ?? '');
    if ($st === 'active') {
        return true;
    }
    if (!$strict) {
        return false;
    }
    if ($st === 'suspended') {
        throw new AppError('AUTH_ACCOUNT_SUSPENDED', 403);
    }
    if ($st === 'withdrawn') {
        throw new AppError('AUTH_ACCOUNT_WITHDRAWN', 403);
    }
    if ($st === 'dormant') {
        throw new AppError('AUTH_ACCOUNT_DORMANT', 403);
    }
    if ($st === 'pending') {
        throw new AppError('AUTH_ACCOUNT_PENDING', 403);
    }
    throw new AppError('AUTH_SESSION_EXPIRED', 401);
}

/**
 * 현재 요청의 인증 컨텍스트.
 * 인증 우선순위: Authorization: Bearer 헤더 → 쿠키 ULOGIN_SID.
 * 헤더가 있으면 쿠키는 보지 않는다 (세션 혼동 방지).
 *
 * **인증자는 두 경로 모두 access_token 원문이다** (리더 판정 D-29 / 감사 C-1).
 * 값을 어디서 읽었는지만 다르고 조회는 한 문장 — `access_token_hash` 다.
 * 경로마다 SELECT 를 따로 쓰면 한쪽만 고쳐지는 사고가 난다.
 * 이 함수에서 `WHERE session_id = ?` 로 세션을 찾는 코드가 다시 생기면
 * 그 즉시 C-1 이 되살아난다.
 *
 * 세션 유효성 4조건 (스펙 §5-4)
 *   revoked_at IS NULL / expires_at > now / users.status='active' /
 *   sessions.issued_at >= users.password_changed_at
 *
 * @return array{user:array,session:array,user_service:?array,grades:array,auth_via:string}|null
 */
function require_auth(bool $required = true): ?array
{
    $row = null;

    // 자격증명 수집 — 우선순위만 여기서 정하고 검증은 아래 한 곳에서 한다.
    $token = bearer_token();
    $via   = 'bearer';
    if ($token === null && !empty($_COOKIE[COOKIE_NAME])) {
        $token = (string) $_COOKIE[COOKIE_NAME];
        $via   = 'cookie';
    }

    if ($token !== null) {
        if (!is_token_format($token)) {
            if ($required) {
                throw new AppError('AUTH_REQUIRED', 401);
            }
            return null;
        }
        // 유일한 조회 조건. 64자 hex 라도 access_token 이 아니면(예: session_id) 적중하지 않는다.
        $row = db_one('SELECT * FROM ' . T_SESSIONS . ' WHERE access_token_hash = ?', [token_hash($token)]);
    }

    if ($row === null) {
        if ($required) {
            throw new AppError('AUTH_REQUIRED', 401);
        }
        return null;
    }

    // 1) 회수 / 2) 만료
    if ($row['revoked_at'] !== null || (int) ts($row['expires_at']) <= time()) {
        if ($required) {
            throw new AppError('AUTH_SESSION_EXPIRED', 401);
        }
        return null;
    }

    $user = db_one('SELECT * FROM ' . T_USERS . ' WHERE id = ?', [$row['user_id']]);
    if ($user === null) {
        if ($required) {
            throw new AppError('AUTH_SESSION_EXPIRED', 401);
        }
        return null;
    }

    // 3) 계정 상태
    if (!assert_user_active($user, $required)) {
        return null;
    }

    // 4) 비밀번호 변경 이후 발급된 세션만 유효 — 회수 UPDATE 가 누락돼도 잡히는 이중 안전장치
    $pwChanged = ts($user['password_changed_at'] ?? null);
    if ($pwChanged !== null && (int) ts($row['issued_at']) < $pwChanged) {
        revoke_session((int) $row['id'], 'password_changed');
        if ($required) {
            throw new AppError('AUTH_SESSION_EXPIRED', 401);
        }
        return null;
    }

    $now = now_utc();
    db_exec('UPDATE ' . T_SESSIONS . ' SET last_seen_at = ? WHERE id = ?', [$now, $row['id']]);
    $row['last_seen_at'] = $now;

    $us = null;
    if (!empty($row['service_id'])) {
        $us = db_one('SELECT * FROM ' . T_USER_SERVICES . ' WHERE user_id = ? AND service_id = ?',
            [$user['id'], $row['service_id']]);
        if ($us !== null) {
            db_exec('UPDATE ' . T_USER_SERVICES . ' SET last_used_at = ? WHERE id = ?', [$now, $us['id']]);
        }
    }

    return [
        'user'         => $user,
        'session'      => $row,
        'user_service' => $us,
        'grades'       => effective_grades($user, $us),
        'auth_via'     => $via,
    ];
}

// ---------------------------------------------------------------------
// 회수
// ---------------------------------------------------------------------

/** 세션 1건 회수. 이미 회수된 건은 건드리지 않는다(사유가 덮어써지면 감사 추적이 흐려진다). */
function revoke_session(int $sessionId, string $reason): int
{
    if ($sessionId <= 0) {
        return 0;
    }
    return db_exec(
        'UPDATE ' . T_SESSIONS . ' SET revoked_at = ?, revoke_reason = ? WHERE id = ? AND revoked_at IS NULL',
        [now_utc(), substr($reason, 0, 50), $sessionId]
    );
}

/** 사용자 전 세션 회수. $exceptSessionId 를 주면 그 세션만 남긴다. */
function revoke_user_sessions(int $userId, string $reason, ?int $exceptSessionId = null): int
{
    if ($userId <= 0) {
        return 0;
    }
    $sql    = 'UPDATE ' . T_SESSIONS . ' SET revoked_at = ?, revoke_reason = ? WHERE user_id = ? AND revoked_at IS NULL';
    $params = [now_utc(), substr($reason, 0, 50), $userId];
    if ($exceptSessionId !== null) {
        $sql .= ' AND id <> ?';
        $params[] = $exceptSessionId;
    }
    return db_exec($sql, $params);
}

// ---------------------------------------------------------------------
// refresh 회전 — 계약 §4-4 / 스펙 §5-2
// ---------------------------------------------------------------------

/**
 * refresh 토큰을 새 세션·새 토큰 쌍으로 회전한다.
 * 사용된 refresh 는 즉시 폐기(revoke_reason='refresh_rotated')되고 session_id 도 새 값이 된다.
 *
 * 이미 회수된 refresh 가 다시 오면 토큰 탈취 신호로 보고 해당 사용자의 전 세션을 회수한다.
 *
 * **쿠키 재설정** (리더 판정 D-29): 회전하면 이전 access_token 이 폐기되므로,
 * 쿠키에 그 값을 들고 있던 세션은 갱신 직후 죽는다. 그래서 이 함수가
 * 요청의 ULOGIN_SID 쿠키가 회전 대상 세션을 가리키는지 직접 확인하고,
 * 그렇다면 새 access_token 으로 쿠키를 다시 심는다.
 * api/auth/refresh.php 는 아무것도 하지 않아도 된다 — 호출부가 잊어도 안전하게
 * 만들어야 하는 종류의 처리이기 때문이다.
 * 쿠키를 반드시 심어야 하는 경우에는 `$opt['set_cookie'] = true` 로 강제할 수 있고,
 * `$opt['cookie_ttl']` 로 수명을 지정할 수 있다(remember-me 유지).
 *
 * @return array{user:array,user_service:?array,service:?array,grades:array,session:array,
 *               access_token:string,refresh_token:?string,expires_in:int,refresh_expires_in:?int}
 */
function rotate_refresh(string $refreshToken, array $opt = []): array
{
    if (!is_token_format($refreshToken)) {
        throw new AppError('AUTH_SESSION_EXPIRED', 401);
    }
    $old = db_one('SELECT * FROM ' . T_SESSIONS . ' WHERE refresh_token_hash = ?', [token_hash($refreshToken)]);
    if ($old === null) {
        throw new AppError('AUTH_SESSION_EXPIRED', 401);
    }

    // 회전된(이미 회수된) 토큰의 재사용 = 탈취 신호. 전면 회수한다.
    //
    // 리더 판정 D-164 (보안 조사 A-6) — **관리자가 볼 곳을 만든다.**
    // 이것은 이 시스템의 유일한 **능동** 침해 탐지다(나머지는 전부 시도 기록이다).
    // 그런데 v1.4.0 후속까지 남는 것이 error_log 한 줄뿐이었다. 같은 라운드가
    // 레이트리밋 429 라는 수동 신호는 콘솔에 올렸으니(D-153) 비대칭이 뒤집힌 채였다 —
    // 콘솔은 "레이트리밋에 걸렸다"는 보여 주고 "토큰이 탈취됐다"는 안 보여줬다.
    // error_log 는 남긴다. 공용 호스팅에서 DB 가 죽어 있을 때 남는 유일한 흔적이다.
    //
    // ── 로그를 회수 **사유별로** 가른다 — 보안 재검증 A-6 오탐 ─────────────
    // 이 분기는 `revoked_at` 만 보고 사유를 가리지 않는다. 그런데 회수 사유는
    // 저장소 전수로 7가지(`refresh_rotated` `refresh_reuse` `logout` `logout_all`
    // `password_changed` `admin` `withdraw`)이고 **진짜 재사용 신호는 앞의 둘뿐**이다.
    // 뒤의 다섯은 시스템이 스스로 회수한 것이고, 그 뒤에 옛 토큰이 오는 것은
    // 정상 클라이언트의 갱신 타이머다. 실측으로 네 경로가 확인됐다 —
    // 로그아웃 · 마이페이지 세션정리 · 비밀번호 변경 · 관리자 회수. 넷 다
    // `fail_refresh_reuse` 로 기록됐고 `GET /api/me/login-logs` 로 **사용자 본인에게도**
    // 보인다. 정상 사용 빈도가 침해 빈도보다 높으므로, 그대로 두면 이 시스템의
    // 유일한 능동 침해 탐지가 **대부분 오탐**이 된다.
    //
    // **회수 동작은 한 줄도 바꾸지 않았다.** 사유를 가려 회수 범위를 좁히는 것은
    // 정상 경로의 동작 변경이라 별도 회귀 확인이 필요하다 — v1.5.0.
    // 지금 가르는 것은 **기록되는 이름**뿐이다. `fail_refresh_revoked` 는
    // 「회수된 토큰이 제시됐다」는 사실 그대로이고, 전 세션을 회수해 놓고
    // 로그에 아무것도 남기지 않는 상태(그게 더 나쁘다)도 만들지 않는다.
    if ($old['revoked_at'] !== null) {
        $priorReason = (string) ($old['revoke_reason'] === null ? '' : $old['revoke_reason']);
        $isReuse     = ($priorReason === 'refresh_rotated' || $priorReason === 'refresh_reuse');
        revoke_user_sessions((int) $old['user_id'], 'refresh_reuse');
        error_log('[ulogin] revoked refresh token presented. user_id=' . (int) $old['user_id']
            . ' prior_reason=' . ($priorReason === '' ? '(none)' : $priorReason)
            . ' reuse=' . ($isReuse ? '1' : '0'));
        // 회수보다 뒤에 둔다 — 기록이 실패해도 회수는 이미 끝나 있어야 한다.
        // (log_login() 은 내부에서 try/catch 하지만 순서 자체를 그렇게 못박는다.)
        $reuseName = (string) db_val('SELECT username FROM ' . T_USERS . ' WHERE id = ?', [(int) $old['user_id']]);
        log_login($isReuse ? 'fail_refresh_reuse' : 'fail_refresh_revoked',
            (int) $old['user_id'], $reuseName,
            !empty($old['service_id']) ? (string) $old['service_id'] : null);
        throw new AppError('AUTH_SESSION_EXPIRED', 401);
    }
    $rexp = ts($old['refresh_expires_at']);
    if ($rexp === null || $rexp <= time()) {
        throw new AppError('AUTH_SESSION_EXPIRED', 401);
    }

    $user = db_one('SELECT * FROM ' . T_USERS . ' WHERE id = ?', [$old['user_id']]);
    if ($user === null) {
        throw new AppError('AUTH_SESSION_EXPIRED', 401);
    }
    assert_user_active($user, true);

    $pwChanged = ts($user['password_changed_at'] ?? null);
    if ($pwChanged !== null && (int) ts($old['issued_at']) < $pwChanged) {
        revoke_session((int) $old['id'], 'password_changed');
        throw new AppError('AUTH_SESSION_EXPIRED', 401);
    }

    // ------------------------------------------------------------------
    // 사용자 단위 회전 상한 — 보안 재검증 V14b-H-1
    //
    // 회전은 매번 새 `session_id` 를 만들고, `secret_access_logs` 의 시간 버킷은
    // 그 값을 UNIQUE 키에 쓴다. 그래서 **회전 경로만 유일하게 접히지 않고** 요청과
    // 1:1 로 행이 늘었다(실측 150회 → 151행). `sessions` 도 같은 비율로 늘어난다.
    // 행은 세션 단위, 세션은 사용자 단위이므로 **사용자 버킷이 증가율을 실제로 묶는
    // 자리**다. IP 버킷(api/auth/refresh.php)은 폭주 억제용이고, NAT·CDN 뒤에서는
    // 같은 IP 에 사용자가 수천 명일 수 있어 그것만으로는 좁게 걸 수 없다.
    //
    // **한도를 넉넉하게 잡았다 — 좁게 거는 것이 이 라운드가 고친 결함의 형태다.**
    // 한 사용자의 정상 회전율은 `기기 수 × (3600 / session_ttl)` 회/시간이다.
    // 기본값(`SESSION_TTL` 3600, 기기 1대)이면 1회/시간이므로 **60회/시간은 60배 여유**고,
    // 기기 10대 + 10분 토큰(60회/시간)까지 그대로 통과한다. 사용자당 하루 1,440행 상한 —
    // 상한 없는 432,000행/일/IP 에서 300배 줄어들고, 보안이 감당 가능하다고 판정한
    // `login_logs` 의 3,456행/일/IP 보다도 작다.
    // **`session_ttl` 을 아주 짧게 설정한 배포는 이 값을 다시 봐야 한다** —
    // 넘으면 클라이언트가 429 를 받고 갱신이 지연된다.
    //
    // **회전 트랜잭션보다 앞에 둔다.** 뒤에 두면 429 를 받은 클라이언트가 이미
    // 폐기된 refresh 토큰을 들고 있게 되어 재로그인 말고는 복구 경로가 없다.
    // 여기서 던지면 옛 토큰이 그대로 살아 있어 잠시 뒤 다시 시도할 수 있다(실측 확인).
    //
    // 재사용 탐지 분기보다 **뒤에** 있는 것도 의도한 것이다 — 레이트리밋이
    // 침해 탐지를 가려서는 안 된다.
    // ------------------------------------------------------------------
    rate_limit('refresh:user:' . (int) $old['user_id'], 60, 3600);

    // ------------------------------------------------------------------
    // 리더 판정 D-162 — **회전이 service_id 를 잃으면 안 된다.**
    //
    // service_load() 는 `status='active'` 필터다. 서비스가 비활성인 동안에는 null 을
    // 돌려주고, 종전 코드는 그 null 을 그대로 create_session() 에 넘겼다. 그래서 새
    // 세션 행의 service_id 가 **NULL 로 굳었다.** 결과는 의도의 정반대였다(보안 실측):
    //
    //   [1] 서비스 disabled → 정상 사용자가 refresh    → 새 세션 service_id = NULL
    //   [2] 운영자가 점검을 끝내고 active 로 되살림
    //       회전하지 않은 **탈취 토큰** → payload 복구 (service_id 가 살아 있으므로)
    //       회전한 **정상 사용자**     → payload 영원히 {} (재로그인 전까지)
    //
    // D-152 가 "payload 만 비우고 세션은 살린다"를 고른 이유가 연동 앱을 깨지 않는
    // 것이었는데, 실제로는 정상 사용자만 영구히 깨지고 공격자가 복구됐다.
    //
    // 그래서 **service_id 는 그대로 두고, 시크릿 차단은 build_payload() 에 맡긴다.**
    // 비활성 동안 payload 가 {} 인 것은 그 함수의 payload_service_active() 가 이미
    // 보장한다(D-152) — 세션 행에 id 를 남긴다고 시크릿이 나가지 않는다. 회전 자체가
    // 정책 판단을 하지 않는 것이 옳다: 서비스를 통째로 끊는 것은 A-13(서비스 단위
    // 일괄 세션 회수, v1.9.0)의 일이고, 그것은 **양쪽 토큰에 똑같이** 적용돼야 한다.
    //
    // $service 가 null 이면 세션 TTL 은 서비스별 값이 아니라 전역 기본값이 된다 —
    // 비활성 서비스의 TTL 을 권위로 쓸 수 없으므로 이것이 맞다. 재활성 뒤의 회전부터
    // 다시 서비스 값을 쓴다.
    // ------------------------------------------------------------------
    $service = !empty($old['service_id']) ? service_load((string) $old['service_id']) : null;

    // 회전 '전' 에 판정해야 한다. revoke 후에는 쿠키가 가리키던 세션이 이미 죽어 있다.
    $setCookie = !empty($opt['set_cookie']) || cookie_binds_session($old);

    $issued = db_tx(function () use ($old, $user, $service, $opt, $setCookie) {
        revoke_session((int) $old['id'], 'refresh_rotated');
        $copt = [
            'device_label' => $opt['device_label'] ?? ($old['device_label'] ?? device_label_from_ua()),
            'with_refresh' => true,
            'set_cookie'   => $setCookie,
            // 리더 판정 D-162 — 회전이 세션의 service_id 를 **잃지 않는다.**
            'service_id_fallback' => !empty($old['service_id']) ? (string) $old['service_id'] : null,
            // v1.8.6 — 회전이 echo 기억(별칭)을 잃지 않는다. 회전 뒤 응답도 옛 id 를 되돌린다.
            'client_service_id'   => isset($old['client_service_id']) ? (string) $old['client_service_id'] : null,
        ];
        if (array_key_exists('cookie_ttl', $opt)) {
            $copt['cookie_ttl'] = $opt['cookie_ttl'];
        }
        return create_session($user, $service, (string) $old['auth_type'], $copt);
    });

    $us = null;
    if (!empty($old['service_id'])) {
        $us = db_one('SELECT * FROM ' . T_USER_SERVICES . ' WHERE user_id = ? AND service_id = ?',
            [$user['id'], $old['service_id']]);
    }

    return [
        'user'               => $user,
        'user_service'       => $us,
        'service'            => $service,
        'grades'             => effective_grades($user, $us),
        'session'            => $issued['row'],
        'access_token'       => $issued['access_token'],
        'refresh_token'      => $issued['refresh_token'],
        'expires_in'         => $issued['expires_in'],
        'refresh_expires_in' => $issued['refresh_expires_in'],
    ];
}

// ---------------------------------------------------------------------
// user_services
// ---------------------------------------------------------------------

/**
 * 이 사용자가 해당 서비스에서 차단됐는가. 리더 판정 D-31 / 감사 H-1.
 *
 * `user_services.status` 가 'active' 가 아니면 true. 가입 행이 아직 없으면
 * **false** 다 — 차단이 아니라 미가입이며, auto_join 으로 곧 만들어질 수 있다.
 * ('active' 외 전부를 차단으로 보는 것은 열거값이 늘어나도 기본 거부가 되게 하기 위함이다)
 *
 * 호출측(api/auth/login.php, api/auth/exchange.php, api/sso/authorize.php)은
 * true 면 `FORBIDDEN`(403) 으로 거부한다. 전역 계정은 정상이므로
 * AUTH_ACCOUNT_SUSPENDED 가 아니다.
 *
 * @param int         $userId    users.id (내부 ID)
 * @param string|null $serviceId services.service_id. null/빈문자면 false
 */
function service_membership_blocked(int $userId, ?string $serviceId): bool
{
    if ($userId <= 0 || $serviceId === null || $serviceId === '') {
        return false;
    }
    $st = db_val('SELECT status FROM ' . T_USER_SERVICES . ' WHERE user_id = ? AND service_id = ?',
        [$userId, $serviceId]);
    if ($st === null) {
        return false;   // 미가입 — 차단이 아니다
    }
    return (string) $st !== 'active';
}

/**
 * 새 `user_services` 행에 심을 등급 오버라이드를 계산한다.
 * **가입 경로가 몇 개든 이 함수 하나만 쓴다** (리더 판정 D-49 / 감사 M-16).
 *
 * 호출부 2곳:
 *   1) `ensure_user_service()` (바로 아래)          — 자동 가입 (`services.auto_join=1`)
 *   2) `api/auth/register.php`                      — 명시적 가입 (회원가입 시 service_id 동반)
 *
 * D-35 는 (1) 만 grant-only 로 고쳤고 (2) 는 `default_account_level` 을 무조건
 * 오버라이드로 박아 넣은 채 남았다(감사 M-16). 같은 규칙을 두 곳에 복제했기 때문에
 * 한 곳만 고쳐진 것이다 — 그래서 규칙 자체를 함수로 뽑았다. 세 번째 가입 경로가
 * 생기더라도 이 함수를 부르면 규칙이 자동으로 따라온다.
 *
 * **`default_account_level` 은 grant-only 다** (D-35 / QA Q-3).
 * 컬럼의 의미는 "가입 시 최소 보장 등급" 이며, 전역 등급을 절대 강등하지 않는다.
 * 예전 구현은 이 값을 무조건 오버라이드로 넣어, 전역 `verified` 사용자가
 * 서비스 기본값 `basic` 때문에 강등되고 payload 에서 `ai_api_key` 가 조용히 사라졌다.
 *   - 기본은 NULL(전역 상속)
 *   - `default_account_level` 의 랭크가 전역보다 **높을 때만** 오버라이드로 기록
 *   - `role`·`billing_tier` 오버라이드는 어떤 가입 경로에서도 항상 NULL (권한 승격 금지)
 *
 * 반환 배열은 그대로 `db_insert(T_USER_SERVICES, …)` 에 펼쳐 쓸 수 있게 컬럼명으로 맞췄다.
 *
 * @param array|null $service services 행. null 이면 전부 NULL
 * @param array|null $user    users 행(최소 `account_level` 키). 아직 커밋 전인 행도 그대로 넘길 수 있다
 * @param int        $userId  $user 가 없을 때 전역 등급을 읽어 올 users.id. 0 이면 읽지 않는다
 * @return array{account_level:?string, role:null, billing_tier:null}
 */
function service_grade_overrides(?array $service, ?array $user = null, int $userId = 0): array
{
    $none = ['account_level' => null, 'role' => null, 'billing_tier' => null];
    if ($service === null) {
        return $none;
    }
    $def = isset($service['default_account_level']) && (string) $service['default_account_level'] !== ''
        ? (string) $service['default_account_level']
        : null;
    if ($def === null) {
        return $none;
    }
    if (($user === null || !isset($user['account_level'])) && $userId > 0) {
        $user = db_one('SELECT account_level FROM ' . T_USERS . ' WHERE id = ?', [$userId]);
    }
    $global = (string) ($user['account_level'] ?? 'basic');

    // 문자열 비교 금지 — 랭크로만 비교한다 (perm.php 규약. 'vip' > 'staff' 는 문자열로는 참이다).
    // 전역이 default 이상이면 오버라이드를 만들지 않는다(= 강등 불가).
    // 알 수 없는 열거값은 rank 0 이 되어 자동으로 '기록하지 않음' 으로 떨어진다.
    if (level_gte($global, $def)) {
        return $none;
    }
    return ['account_level' => $def, 'role' => null, 'billing_tier' => null];
}

/**
 * 서비스 자동 가입(services.auto_join=1). 이미 있으면 last_used_at 만 갱신한다.
 *
 * 등급 오버라이드 규칙은 `service_grade_overrides()` 에 있다 — D-49 로 명시적 가입
 * 경로(`api/auth/register.php`)와 규칙을 하나로 합쳤다. 여기서 다시 판정하지 마라.
 *
 * @param int        $userId
 * @param array|null $service services 행
 * @param array|null $user    users 행. 없으면 전역 등급만 다시 읽는다(선택 인자 — 기존 호출부 무영향)
 * @return array|null user_services 행
 */
function ensure_user_service(int $userId, ?array $service, ?array $user = null)
{
    if ($service === null) {
        return null;
    }
    $sid = (string) $service['service_id'];
    $us  = db_one('SELECT * FROM ' . T_USER_SERVICES . ' WHERE user_id = ? AND service_id = ?', [$userId, $sid]);
    if ($us !== null) {
        db_exec('UPDATE ' . T_USER_SERVICES . ' SET last_used_at = ? WHERE id = ?', [now_utc(), $us['id']]);
        $us['last_used_at'] = now_utc();
        return $us;
    }
    if (empty($service['auto_join'])) {
        return null;
    }
    // v1.10.0 (설계 127 §6-2) — 비상 계정은 서비스에 가입하지 않는다(행을 만들지 않는다).
    $kind = $user !== null ? ($user['account_kind'] ?? null) : db_val('SELECT account_kind FROM ' . T_USERS . ' WHERE id = ?', [$userId]);
    if ((string) $kind === 'emergency') {
        return null;
    }

    // D-35 / D-49 grant-only 판정 — 규칙은 한 곳에만 있다.
    $ov = service_grade_overrides($service, $user, $userId);

    $now = now_utc();
    $id  = db_insert(T_USER_SERVICES, [
        'user_id'       => $userId,
        'service_id'    => $sid,
        'account_level' => $ov['account_level'],   // NULL = 전역 상속
        'role'          => $ov['role'],
        'billing_tier'  => $ov['billing_tier'],
        'status'        => 'active',
        'joined_at'     => $now,
        'last_used_at'  => $now,
    ]);
    return db_one('SELECT * FROM ' . T_USER_SERVICES . ' WHERE id = ?', [$id]);
}
