<?php
declare(strict_types=1);
/**
 * POST /api/auth/login — 직접 로그인 (쿠키 + Bearer 동시 발급)
 * 담당 계약: 01_architect_api_contract.md §4-2, 스펙 §4-1
 * 응답 최상위 키: user, session, access_token, refresh_token, token_type,
 *                 expires_in, refresh_expires_in, payload
 */
require_once __DIR__ . '/../../core/bootstrap.php';

// 1) 메서드
only_method('POST');

// 2) 레이트리밋 — 인증보다 먼저. 무차별 대입을 인증 전에 막는다.
//    D-153: 한도 도달을 login_logs 에 남긴다. **입력 파싱보다 앞에 둔 위치는 그대로다** —
//    뒤로 옮기면 깨진 본문을 보내 VALIDATION_FAILED 로 빠져나가는 우회가 생긴다.
//    그래서 이 버킷의 로그에는 username 이 없다(아직 읽지 않았다). ip + 시각은 남는다.
rate_limit_logged('login:ip:' . client_ip(), 10, 300);

// 3) 입력
$in       = body_json();
$loginId  = (string) v_string($in, 'username', 1, 190, true);
$password = (string) v_string($in, 'password', 1, 200, true);
$remember = (bool) v_bool($in, 'remember', false, false);
$devLabel = v_string($in, 'device_label', 1, 80);
$serviceId = v_service_id($in, 'service_id');   // v1.8.6 — 옛 id 는 여기서 새 id 로(설계 104 C-1)

rate_limit_logged('login:user:' . mb_strtolower($loginId, 'UTF-8'), 5, 300, null, $loginId, $serviceId);

$service = $serviceId !== null ? service_require($serviceId) : null;

// 4) 조회 — username 또는 email 둘 다 받는다
$norm = mb_strtolower(trim($loginId), 'UTF-8');
$user = db_one('SELECT * FROM ' . T_USERS . ' WHERE username = ? OR email = ? LIMIT 1', [$norm, $norm]);

// 계정이 없어도 더미 해시로 password_verify 를 한 번 돌린다.
// 즉시 반환하면 응답 시간 차이로 계정 존재 여부가 새어나간다.
if ($user === null) {
    // v1.10.1 (설계 130 §2-4) — 승인 대기 신청이 있으면 **그 해시로** 검증한다(더미 대신 — bcrypt 1회로 시간 동치).
    // 맞으면 403 AUTH_ACCOUNT_PENDING, 틀리면 종전 그대로. 비밀번호를 아는 사람에게만 신청 존재가 드러난다.
    // v1.10.1 보안 L-3 (D-375) — 신청 비밀번호 실패도 잠금 카운트(계정과 같은 임계·시간). 잠겼으면 검증 전에 423.
    $pend = register_pending_row($norm);
    if ($pend !== null) {
        try {
            pending_assert_not_locked($pend['id']);
        } catch (AppError $e) {
            log_login('fail_locked', null, $loginId, $serviceId);
            throw $e;
        }
        if (password_verify($password, $pend['password_hash'])) {
            pending_clear_failures($pend['id']);
            log_login('fail_status', null, $loginId, $serviceId);
            throw new AppError('AUTH_ACCOUNT_PENDING', 403, ['message' => '관리자 승인 후 로그인할 수 있습니다.']);
        }
        pending_register_failure($pend['id']);   // PENDING-FAIL-COUNT
    } else {
        dummy_password_verify($password);
    }
    log_login('fail_credential', null, $loginId, $serviceId);
    throw new AppError('AUTH_INVALID_CREDENTIALS', 401, ['field' => 'password']);
}

// 4-b) v1.10.0 비상 계정(설계 127 §6-4) — 미설정('!unset')이면 password_verify **앞에서** 거절한다.
//      시간 차가 나지 않게 더미 verify 를 한 번 돌리고, 응답은 보통 실패와 같다(미설정 여부를 밖에 알리지 않는다).
//      실패 횟수는 세지 않는다(잠금은 설정된 계정에만 걸린다).
$isEmergency = (string) ($user['account_kind'] ?? 'normal') === 'emergency';
if ($isEmergency && (string) $user['password_hash'] === EMERGENCY_UNSET_HASH) {
    dummy_password_verify($password);
    log_login('fail_emergency_unset', (int) $user['id'], $loginId, $serviceId);
    audit_emergency_event('emergency.login.failure', $user, ['reason' => 'unset']);
    throw new AppError('AUTH_INVALID_CREDENTIALS', 401, ['field' => 'password']);
}

// 5) 락아웃 — 비밀번호 검증보다 먼저
try {
    assert_not_locked($user);
} catch (AppError $e) {
    log_login('fail_locked', (int) $user['id'], $loginId, $serviceId);
    throw $e;
}

// 6) 비밀번호
if (!password_verify($password, (string) $user['password_hash'])) {
    register_login_failure($user, true);   // v1.10.0 L-1 — 비상 계정 감사는 아래에서 이 파일이 남긴다
    log_login('fail_credential', (int) $user['id'], $loginId, $serviceId);
    if ($isEmergency) {
        audit_emergency_event('emergency.login.failure', $user, ['reason' => 'credential']);
    }
    // 아이디 없음과 비밀번호 틀림을 구분하지 않는다 — 계정 열거 방지
    throw new AppError('AUTH_INVALID_CREDENTIALS', 401, ['field' => 'password']);
}

// 7) 점검 모드 — 관리자는 통과시킨다
if ((bool) setting_get('maintenance_mode', MAINTENANCE_MODE)
    && !role_gte((string) $user['role'], 'manager')) {
    log_login('fail_status', (int) $user['id'], $loginId, $serviceId);
    throw new AppError('MAINTENANCE_MODE', 503);
}

// 8) 계정 상태
$requireVerify = (bool) setting_get('require_email_verify', REQUIRE_EMAIL_VERIFY);
if ($requireVerify && empty($user['email_verified_at'])) {
    log_login('fail_status', (int) $user['id'], $loginId, $serviceId);
    throw new AppError('AUTH_EMAIL_UNVERIFIED', 403, ['email' => (string) $user['email']]);
}
try {
    assert_user_active($user, true);
} catch (AppError $e) {
    log_login('fail_status', (int) $user['id'], $loginId, $serviceId);
    throw $e;
}

// 8-b) 서비스 단위 차단 — 리더 판정 D-31 / 감사 H-1.
//      자격 검증과 전역 계정 상태는 통과했으나 이 서비스에 대해서만 이용이 막힌 상태다.
//      전역 계정은 정상이므로 AUTH_ACCOUNT_SUSPENDED 가 아니라 FORBIDDEN 이다 —
//      "정지된 계정입니다" 를 보여주면 사용자가 계정 자체가 죽은 줄 알고 문의한다.
//
//      **위치가 중요하다.** 9) 이후의 create_session() 보다 뒤에 두면 거부된 로그인이
//      세션 행을 남긴다. 그래서 이 파일의 다른 모든 거부(점검모드·미인증·정지)와 같이
//      "9) 로그인 성공 처리" **앞**에 둔다 — last_login_at / login_count 도 올리지 않는다.
//      판정은 core/auth.php 의 service_membership_blocked() 만 쓴다(재구현 금지).
//      service_id 가 없는 일반 로그인($service === null)은 이 판정을 거치지 않는다.
//      auto_join 신규 가입자는 아직 user_services 행이 없고(= 미가입은 차단이 아니다)
//      가입 후에도 status='active' 이므로 거부되지 않는다.
if ($service !== null
    && service_membership_blocked((int) $user['id'], (string) $service['service_id'])) {
    // 리더 판정 D-46: 서비스 차단 전용 result 값을 쓴다. 계정 정지(fail_status)와 같은
    // 라벨로 보이면 운영자가 거부 원인을 구분할 수 없다. result 는 VARCHAR(30) 이라
    // DDL 변경은 필요 없고, 조회 필터 화이트리스트와 프론트 라벨맵만 함께 넓혔다.
    log_login('fail_service_blocked', (int) $user['id'], $loginId, $serviceId);
    throw new AppError('FORBIDDEN', 403, ['message' => '이 서비스 이용이 제한되었습니다.']);
}
// 8-c) v1.9.0 서비스 접근 규칙(80 §5-3 집행 5번). 세션·성공 처리 **앞**. 거절 기록은 함수 안에서.
if ($service !== null) {
    require_service_access($user, service_access_us_row((int) $user['id'], (string) $service['service_id']), $service, 'login');
}

// 9) 로그인 성공 처리
$now = now_utc();
if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
    // password_changed_at 은 건드리지 않는다. 알고리즘 재해시일 뿐 비밀번호는 그대로다.
    db_exec('UPDATE ' . T_USERS . ' SET password_hash = ? WHERE id = ?',
        [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
}
clear_login_failures((int) $user['id']);
db_exec(
    'UPDATE ' . T_USERS . ' SET last_login_at = ?, last_login_ip = ?, login_count = login_count + 1, updated_at = ?'
    . ' WHERE id = ?',
    [$now, client_ip(), $now, $user['id']]
);
$user = db_one('SELECT * FROM ' . T_USERS . ' WHERE id = ?', [$user['id']]);

$us     = ensure_user_service((int) $user['id'], $service);
$grades = effective_grades($user, $us);
// v1.9.1 - 비밀번호를 직접 인증한 순간: 연결·접근 가능·허용된 서비스마다 비상 확인값 발급(emergency/1 1-1장). 실패해도 로그인은 계속.
em_issue_for_user($user, $password);

// 10) 세션 — 행은 1건. 같은 session_id 를 쿠키에도 심는다.
// v1.10.0 비상 계정(§6-2): remember 무시 · 세션 1시간 · refresh 없음(1시간을 넘겨 이어지지 않게)
$issued = create_session($user, $service, 'token', [
    'device_label' => $devLabel,
    'with_refresh' => !$isEmergency,
    'set_cookie'   => true,
    'cookie_ttl'   => ($remember && !$isEmergency) ? (int) REMEMBER_TTL : null,
    'ttl_cap'      => $isEmergency ? EMERGENCY_SESSION_TTL : 0,
    // v1.8.6 — 옛 id(별칭)로 들어왔으면 세션에 남겨 이후 응답 echo 에 쓴다(설계 104 C-2)
    'client_service_id' => $service !== null ? service_id_presented_alias((string) $service['service_id']) : null,
]);

// 11) 로깅
log_login('success', (int) $user['id'], $loginId, $serviceId);
if ($isEmergency) {
    audit_emergency_event('emergency.login.success', $user);
}

// 12) 응답 — v1.8.6: service_id 는 클라이언트가 보낸 값을 되돌린다(설계 104 C-2)
$userOut = serialize_user($user, $us);
$sessOut = serialize_session($issued['row'], true);
service_id_echo($userOut, $issued['row']);
service_id_echo($sessOut, $issued['row']);
// v1.9.0 — `features` 는 등록부가 있는 서비스에서만 붙는 **순수 추가** 키다(설계 116 §7, response_extra 경유).
features_emit($service['service_id'] ?? null, $us, $user);
// v1.10.0 — `access_level` · `access` 순수 추가(설계 127 §3-1). service_id 없는 로그인에는 붙지 않는다.
access_emit($service['service_id'] ?? null, $user, $us);
json_out([
    'user'               => $userOut,
    'session'            => $sessOut,
    'access_token'       => $issued['access_token'],
    'refresh_token'      => $issued['refresh_token'],
    'token_type'         => 'Bearer',
    'expires_in'         => $issued['expires_in'],
    'refresh_expires_in' => $issued['refresh_expires_in'],
    // 비어도 {} — service_id 컨텍스트가 없으면 빈 객체다 (계약 §3-5)
    // 4번째 인자는 D-153 시크릿 전달 감사의 문맥이다. 응답 shape 에는 영향이 없다.
    'payload'            => json_object(build_payload($service['service_id'] ?? null, $grades, $us,
                                ['source' => 'login', 'session' => $issued['row']])),
]);
