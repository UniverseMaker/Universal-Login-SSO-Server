<?php
declare(strict_types=1);
/**
 * 회원 상세 / 수정 / 하드 삭제
 * 담당 계약: §8-3(GET) · §8-4(PUT) · §8-5(DELETE)
 * 실제 호출(리더 판정 D-0):
 *   GET    /api/admin/user_detail.php?uuid=...
 *   PUT    /api/admin/user_detail.php?uuid=...
 *   DELETE /api/admin/user_detail.php?uuid=...&confirm={username}
 * 최소 역할: GET manager / PUT admin / DELETE superadmin
 *
 * 응답 최상위 키
 *   GET    : user, recent_logins, recent_points, active_sessions
 *   PUT    : user, changed_fields, admin_log_id
 *   DELETE : deleted, uuid, username, purged, anonymized, admin_log_id
 *
 * 관리자 자기 방어 규칙(계약 §8 공통)은 아래 admin_guard_* 함수에 모여 있다.
 * 이 규칙이 없으면 마지막 최고관리자를 강등·삭제해 시스템이 잠금 상태에 빠진다.
 */
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/accountlink.php';

only_method('GET', 'PUT', 'DELETE');

// =====================================================================
// 공통 헬퍼 (관리자 API 전용. 다른 파일과 중복 정의되어도 충돌하지 않도록 가드)
// =====================================================================

if (!function_exists('admin_log_write')) {
    /**
     * 감사 로그를 남기고 그 id 를 돌려준다.
     * logger.php 의 log_admin() 은 void 라서 계약이 요구하는 admin_log_id 를 얻으려면
     * lastInsertId 를 되짚어야 한다. 잘못된 id 를 내보내지 않도록 실제 행으로 검증한다.
     */
    function admin_log_write(int $adminId, string $action, string $targetType, ?string $targetId,
                             ?array $before = null, ?array $after = null, ?string $memo = null): ?int
    {
        log_admin($adminId, $action, $targetType, $targetId, $before, $after, $memo);
        $id = (int) db()->lastInsertId();
        if ($id <= 0) {
            return null;
        }
        $chk = db_val('SELECT id FROM ' . T_ADMIN_LOGS . ' WHERE id = ? AND admin_id = ? AND action = ?',
            [$id, $adminId, substr($action, 0, 60)]);
        return $chk === null ? null : (int) $chk;
    }
}

if (!function_exists('admin_forbid')) {
    /** 자기 방어 규칙 위반. 사유를 message 로 명시한다(계약 §8 공통). */
    function admin_forbid(string $message, ?string $field = null): void
    {
        throw new AppError('FORBIDDEN', 403, ['message' => $message, 'field' => $field]);
    }
}

if (!function_exists('admin_user_by_uuid')) {
    /** @param mixed $raw */
    function admin_user_by_uuid($raw): array
    {
        $uuid = is_string($raw) ? trim($raw) : '';
        if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $uuid) !== 1) {
            throw new AppError('VALIDATION_FAILED', 400, [
                'field' => 'uuid', 'message' => '회원 식별자 형식이 올바르지 않습니다.',
            ]);
        }
        $u = db_one('SELECT * FROM ' . T_USERS . ' WHERE uuid = ?', [$uuid]);
        if ($u === null) {
            throw new AppError('NOT_FOUND', 404, ['message' => '회원을 찾을 수 없습니다.']);
        }
        return $u;
    }
}

if (!function_exists('admin_role_of')) {
    /**
     * 대상 계정의 랭크 판정 기준 — **전역 `users.role` 만** 본다 (리더 판정 D-50 / 감사 M-13).
     *
     * 종전에는 `effective_grades($user, null)` 을 거쳤다. `$us = null` 이라 결과는 같았지만,
     * 그 함수의 계약은 "오버라이드 반영"이므로 기준이 이름에 드러나지 않았다.
     * 행위자는 effective, 대상은 전역이라는 비대칭이 여기서 생겼다(M-13).
     * 이제 행위자(`ctx_global_role()`)와 대상(이 함수)이 같은 함수 계열을 쓴다.
     *
     * 문자열을 직접 비교하지 않는다 — 비교는 항상 `role_gte()` 정수 랭크로 한다.
     */
    function admin_role_of(array $user): string
    {
        return global_role_of($user);
    }
}

if (!function_exists('admin_is_self')) {
    function admin_is_self(array $ctx, array $target): bool
    {
        return (int) $ctx['user']['id'] === (int) $target['id'];
    }
}

if (!function_exists('admin_is_last_superadmin')) {
    /** 대상이 마지막(유일한 활성) superadmin 인가. 강등·정지·삭제를 막아야 한다. */
    function admin_is_last_superadmin(array $target): bool
    {
        if (!role_gte(admin_role_of($target), 'superadmin')) {
            return false;
        }
        $others = (int) db_val(
            'SELECT COUNT(*) FROM ' . T_USERS . ' WHERE role = ? AND status = ? AND id <> ?',
            ['superadmin', 'active', (int) $target['id']]
        );
        return $others === 0;
    }
}

if (!function_exists('admin_guard_destructive')) {
    /**
     * 정지 / 삭제 / 비밀번호 초기화 공통 방어 (계약 §8 공통 2·3·5).
     * @param string $verb '정지' | '삭제' | '비밀번호를 초기화'
     */
    function admin_guard_destructive(array $ctx, array $target, string $verb): void
    {
        if (admin_is_self($ctx, $target)) {
            admin_forbid('자신의 계정은 ' . $verb . '할 수 없습니다.');
        }
        if (admin_is_last_superadmin($target)) {
            admin_forbid('마지막 최고관리자 계정은 ' . $verb . '할 수 없습니다.');
        }
        if (role_gte(admin_role_of($target), ctx_global_role($ctx))) {
            admin_forbid('자신과 같거나 높은 권한의 계정은 ' . $verb . '할 수 없습니다.');
        }
    }
}

if (!function_exists('admin_guard_target_rank')) {
    /**
     * 리더 판정 D-30 (C-2 / H-3): 대상 계정을 변경하는 모든 관리자 경로의 **진입부** 가드.
     *
     * 규칙: 자신과 같거나 높은 role_rank 를 가진 '다른' 계정에는 어떤 변경도 허용하지 않는다.
     *       (superadmin 도 자신보다 낮은 계정만 변경할 수 있다.)
     *       자신에 대한 변경은 통과시키고, 뒤따르는 개별 자기방어 규칙
     *       (마지막 superadmin 보호 / 자기 강등 금지 / 자기 정지·삭제 금지)이 처리한다.
     *
     * 필드별로 가드를 흩뿌리면 필드가 추가될 때마다 구멍이 생긴다 — C-2 의 email 경로가 그랬다.
     * 진입부 1곳에서 판정하고, 필드별 규칙은 그 뒤에 둔다.
     * 거부는 조작이 아니므로 admin_logs 에 기록하지 않는다.
     *
     * @param string $what '변경' | '수정' | '삭제' | '포인트를 조정' | '세션을 종료'
     */
    function admin_guard_target_rank(array $ctx, array $target, string $what = '변경'): void
    {
        if (admin_is_self($ctx, $target)) {
            return;
        }
        if (role_gte(admin_role_of($target), ctx_global_role($ctx))) {
            admin_forbid('자신과 같거나 높은 권한의 계정은 ' . $what . '할 수 없습니다.');
        }
    }
}

if (!function_exists('admin_revoke_service_sessions')) {
    /**
     * 리더 판정 D-50 부수 항목 (감사 M-13 재현 B): 서비스 가입을 `blocked` 로 바꾸면
     * **그 서비스의 기존 세션을 즉시 회수**한다.
     *
     * 회수하지 않으면 차단이 access TTL 동안 무효다. `require_auth()` 는 매 요청
     * `user_services` 를 다시 읽지만 status 를 보지 않고 `$us` 를 싣기 때문에,
     * 남은 세션은 차단된 가입 행의 등급 오버라이드를 그대로 계속 적용받는다.
     * payload 는 비지만(D-31/D-48) `require_level`·`api/points/consume.php` 는 통과한다.
     *
     * **`service_id` 가 일치하는 세션만** 끊는다. 전역 세션(`service_id IS NULL`)과
     * 다른 서비스의 세션은 건드리지 않는다 — 한 서비스에서 차단됐다고 로그인 서버
     * 전체에서 로그아웃되면 그것은 계정 정지(`users.status`)와 구분되지 않는다.
     * SQL `service_id = ?` 는 NULL 행에 매치되지 않으므로 전역 세션은 자동으로 제외된다.
     *
     * `revoke_reason` 은 스키마 COMMENT 에 열거된 값 중 `'admin'` 을 쓴다.
     * 그 열거는 schema.sql 의 `app_ulogin_sessions` 테이블 `revoke_reason` 컬럼 COMMENT 다
     * (행 번호는 적지 않는다 — 편집 한 번에 낡는다(D-92). 파일명 + 컬럼명으로 가리킨다.
     *  다시 찾으려면: grep -n 'revoke_reason' server/schema.sql).
     * 새 사유 문자열은 schema 소유자의 COMMENT 갱신이 필요하다.
     *
     * @return int 회수된 세션 수
     */
    function admin_revoke_service_sessions(int $userId, string $serviceId): int
    {
        if ($userId <= 0 || $serviceId === '') {
            return 0;
        }
        return db_exec(
            'UPDATE ' . T_SESSIONS . ' SET revoked_at = ?, revoke_reason = ?'
            . ' WHERE user_id = ? AND service_id = ? AND revoked_at IS NULL',
            [now_utc(), 'admin', $userId, $serviceId]
        );
    }
}

if (!function_exists('admin_parse_dt')) {
    /**
     * ISO8601 / 'YYYY-MM-DD HH:MM:SS' / 'YYYY-MM-DD' → UTC DATETIME.
     * @param mixed $v
     * @return string|null
     */
    function admin_parse_dt($v, string $field, bool $endOfDay = false): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_string($v)) {
            v_fail($field, '날짜 형식이 올바르지 않습니다.');
        }
        $s = trim($v);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1) {
            return $s . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
        }
        $t = strtotime(str_replace('Z', ' UTC', $s));
        if ($t === false) {
            v_fail($field, '날짜 형식이 올바르지 않습니다.');
        }
        return gmdate('Y-m-d H:i:s', (int) $t);
    }
}

// =====================================================================

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$ctx    = require_auth();

// -----------------------------------------------------------------
// GET — 상세 (manager)
// -----------------------------------------------------------------
if ($method === 'GET') {
    require_global_role($ctx, 'manager');

    $target = admin_user_by_uuid(in_raw($_GET, 'uuid'));
    $uid    = (int) $target['id'];

    // 상세는 service_overrides 를 채운다(계약 §8-3). serialize_user 가 직접 조회한다 —
    // 단건이므로 N+1 이 아니다.
    $viewerRole = ctx_global_role($ctx);   // v1.10.0 (설계 127 §4)
    $user = mask_user_view(serialize_user($target, null, true), $viewerRole);
    $user['is_emergency'] = is_emergency_user($target);

    $recentLogins = [];
    foreach (db_all(
        'SELECT id, result, service_id, ip, created_at FROM ' . T_LOGIN_LOGS
        . ' WHERE user_id = ? ORDER BY id DESC LIMIT 10',
        [$uid]
    ) as $r) {
        $recentLogins[] = [
            'id'         => (int) $r['id'],
            'result'     => (string) $r['result'],
            'service_id' => $r['service_id'] !== null ? (string) $r['service_id'] : null,
            'ip'         => (string) mask_for_viewer('ip', (string) $r['ip'], $viewerRole),
            'created_at' => iso($r['created_at']),
        ];
    }

    // point_logs.admin_id 는 내부 users.id 다. 계약 §3-1 #1 "id 절대 노출 금지" 에 따라
    // 조작자는 uuid 로 바꿔 내보낸다 (계약 §8-3 예시는 null 이라 형이 충돌하지 않는다).
    $recentPoints = [];
    foreach (db_all(
        'SELECT p.id, p.delta, p.balance_after, p.reason, p.service_id, p.memo, a.uuid AS admin_uuid, p.created_at'
        . ' FROM ' . T_POINT_LOGS . ' p LEFT JOIN ' . T_USERS . ' a ON a.id = p.admin_id'
        . ' WHERE p.user_id = ? ORDER BY p.id DESC LIMIT 10',
        [$uid]
    ) as $r) {
        $recentPoints[] = [
            'id'            => (int) $r['id'],
            'delta'         => (int) $r['delta'],
            'balance_after' => (int) $r['balance_after'],
            'reason'        => (string) $r['reason'],
            'service_id'    => $r['service_id'] !== null ? (string) $r['service_id'] : null,
            'memo'          => $r['memo'] !== null ? (string) $r['memo'] : null,
            'admin_id'      => $r['admin_uuid'] !== null ? (string) $r['admin_uuid'] : null,
            'created_at'    => iso($r['created_at']),
        ];
    }

    $curSid          = (string) ($ctx['session']['session_id'] ?? '');
    $activeSessions  = [];
    foreach (db_all(
        'SELECT * FROM ' . T_SESSIONS
        . ' WHERE user_id = ? AND revoked_at IS NULL AND expires_at > ? ORDER BY last_seen_at DESC LIMIT 50',
        [$uid, now_utc()]
    ) as $s) {
        $activeSessions[] = mask_session_view(serialize_session($s, $curSid !== '' && (string) $s['session_id'] === $curSid), $viewerRole);
    }

    json_out([
        'user'            => $user,
        'recent_logins'   => $recentLogins,
        'recent_points'   => $recentPoints,
        'active_sessions' => $activeSessions,
    ]);
}

// -----------------------------------------------------------------
// PUT — 수정 (admin)
// -----------------------------------------------------------------
if ($method === 'PUT') {
    require_global_role($ctx, 'admin');
    require_csrf();

    $target = admin_user_by_uuid(in_raw($_GET, 'uuid'));
    emergency_target_guard($target);   // v1.10.0 §6-1 — 비상 계정 수정은 CLI 전용(403)
    $uid    = (int) $target['id'];

    // D-30: 대상 랭크 방어는 필드 처리 전에 한 번만 판정한다.
    // 이것이 없으면 email 을 바꿔 password_forgot → password_reset 으로 상급 계정을 탈취할 수 있다(C-2).
    admin_guard_target_rank($ctx, $target, '수정');

    $body   = body_json();

    // 이 엔드포인트에서 만질 수 없는 값 — 조용히 무시하지 않고 명시적으로 거부한다
    if (array_key_exists('points', $body)) {
        v_fail('points', '포인트는 포인트 조정 API 로만 변경할 수 있습니다.');
    }
    if (array_key_exists('password', $body)) {
        v_fail('password', '비밀번호는 비밀번호 초기화 API 로만 변경할 수 있습니다.');
    }

    $set     = [];      // 컬럼 => 값 (컬럼명은 코드 리터럴만)
    $before  = [];
    $after   = [];
    $changed = [];
    $ownRole = ctx_global_role($ctx);

    $mark = function (string $field, string $col, $newVal) use (&$set, &$before, &$after, &$changed, $target) {
        $old = array_key_exists($col, $target) ? $target[$col] : null;
        // null 여부를 먼저 가르고, 둘 다 값이 있을 때만 문자열로 비교한다
        $same = ($old === null && $newVal === null)
            || ($old !== null && $newVal !== null && (string) $old === (string) $newVal);
        if ($same) {
            return;
        }
        $set[$col]      = $newVal;
        $before[$field] = is_string($old) || $old === null ? $old : (string) $old;
        $after[$field]  = $newVal;
        $changed[]      = $field;
    };

    // --- 3축 등급 ------------------------------------------------
    if (array_key_exists('account_level', $body)) {
        $v = v_enum($body, 'account_level', array_keys(ACCOUNT_LEVEL_RANK), true);
        $mark('account_level', 'account_level', $v);
    }
    if (array_key_exists('role', $body)) {
        $newRole = v_enum($body, 'role', array_keys(ROLE_RANK), true);
        $curRole = admin_role_of($target);

        // 리더 판정 D-25 / Q-4: 값이 실제로 바뀔 때만 가드를 적용한다(status 와 동작을 맞춘다).
        // 폼 전체를 전송하는 클라이언트가 무변경 role 을 함께 보내는 것은 정상 요청이다 —
        // "변경 없음" 을 403 으로 거부하면 API 를 직접 쓰는 클라이언트가 전부 막힌다.
        // 진입부의 admin_guard_target_rank() 는 이미 통과한 상태다. 즉 여기 도달한 대상은
        // '자신' 이거나 '자신보다 낮은 랭크' 뿐이므로, no-op 허용이 D-30 을 무력화하지 않는다.
        if ($newRole !== $curRole) {
            // 규칙 4: 자신보다 높은 랭크의 역할을 부여할 수 없다
            if (!role_gte($ownRole, $newRole)) {
                admin_forbid('자신보다 높은 권한을 부여할 수 없습니다.', 'role');
            }
            if (admin_is_self($ctx, $target)) {
                // 규칙 1: 자신의 role 을 낮출 수 없다
                if (!role_gte($newRole, $curRole)) {
                    admin_forbid('자신의 권한을 낮출 수 없습니다.', 'role');
                }
            } elseif (role_gte($curRole, $ownRole)) {
                // 추가 방어: 자신과 같거나 높은 권한 계정의 역할은 바꿀 수 없다
                // (진입부 가드와 중복이지만 심층 방어로 남긴다 — 제거하지 않는다)
                admin_forbid('자신과 같거나 높은 권한의 계정은 역할을 변경할 수 없습니다.', 'role');
            }
            // 규칙 3: 마지막 superadmin 은 강등 불가
            if (!role_gte($newRole, $curRole) && admin_is_last_superadmin($target)) {
                admin_forbid('마지막 최고관리자 계정은 강등할 수 없습니다.', 'role');
            }
            $mark('role', 'role', $newRole);
        }
    }
    if (array_key_exists('billing_tier', $body)) {
        $v = v_enum($body, 'billing_tier', array_keys(BILLING_TIER_RANK), true);
        $mark('billing_tier', 'billing_tier', $v);
    }
    if (array_key_exists('billing_started_at', $body)) {
        $mark('billing_started_at', 'billing_started_at', admin_parse_dt($body['billing_started_at'], 'billing_started_at'));
    }
    if (array_key_exists('billing_expires_at', $body)) {
        $mark('billing_expires_at', 'billing_expires_at', admin_parse_dt($body['billing_expires_at'], 'billing_expires_at'));
    }

    // --- 상태 ----------------------------------------------------
    $revokeAfter = false;
    if (array_key_exists('status', $body)) {
        $newStatus = v_enum($body, 'status', ['active', 'pending', 'suspended', 'dormant', 'withdrawn'], true);
        $curStatus = (string) $target['status'];
        if ($newStatus !== $curStatus && in_array($newStatus, ['suspended', 'dormant', 'withdrawn'], true)) {
            // 로그인을 막는 상태 전이는 정지와 같은 방어를 적용한다
            admin_guard_destructive($ctx, $target, '정지');
            $revokeAfter = true;
        }
        $mark('status', 'status', $newStatus);
        if ($newStatus === 'withdrawn' && $target['withdrawn_at'] === null) {
            $set['withdrawn_at']      = now_utc();
            $after['withdrawn_at']    = $set['withdrawn_at'];
        }
        if ($newStatus === 'active' && $curStatus !== 'active') {
            $set['withdrawn_at']    = null;
            $set['withdraw_reason'] = null;
            $set['locked_until']    = null;
            $set['failed_login_count'] = 0;
        }
    }

    // --- 프로필 --------------------------------------------------
    if (array_key_exists('memo', $body)) {
        $mark('memo', 'memo', v_string($body, 'memo', 0, 2000, false));
    }
    if (array_key_exists('name', $body)) {
        $mark('name', 'name', v_string($body, 'name', 0, 80, false));
    }
    if (array_key_exists('nickname', $body)) {
        $mark('nickname', 'nickname', v_string($body, 'nickname', 0, 50, false));
    }
    if (array_key_exists('phone', $body)) {
        $mark('phone', 'phone', v_string($body, 'phone', 0, 30, false));
    }
    if (array_key_exists('email', $body)) {
        $email = v_email($body, 'email', true);
        if ($email !== (string) $target['email']) {
            $dup = db_val('SELECT id FROM ' . T_USERS . ' WHERE email = ? AND id <> ?', [$email, $uid]);
            if ($dup !== null) {
                throw new AppError('DUPLICATE_EMAIL', 409, ['field' => 'email']);
            }
            $mark('email', 'email', $email);
            // 관리자가 주소를 바꾸면 인증 상태는 승계되지 않는다
            if ($target['email_verified_at'] !== null) {
                $set['email_verified_at']   = null;
                $after['email_verified_at'] = null;
            }
        }
    }

    // --- 서비스 오버라이드 --------------------------------------
    $overrideResult = [];
    if (array_key_exists('service_overrides', $body)) {
        $ovs = $body['service_overrides'];
        if (!is_array($ovs)) {
            v_fail('service_overrides', '서비스 오버라이드는 배열이어야 합니다.');
        }
        foreach ($ovs as $ov) {
            if (!is_array($ov) || !isset($ov['service_id']) || !is_string($ov['service_id'])) {
                v_fail('service_overrides', '각 항목에 service_id가 필요합니다.');
            }
            $sid = trim($ov['service_id']);
            $svc = db_val('SELECT service_id FROM ' . T_SERVICES . ' WHERE service_id = ?', [$sid]);
            if ($svc === null) {
                v_fail('service_overrides', '등록되지 않은 서비스입니다: ' . $sid);
            }
            $usRow  = db_one('SELECT * FROM ' . T_USER_SERVICES . ' WHERE user_id = ? AND service_id = ?', [$uid, $sid]);
            $usSet  = [];
            if (array_key_exists('account_level', $ov)) {
                $usSet['account_level'] = $ov['account_level'] === null
                    ? null : v_enum($ov, 'account_level', array_keys(ACCOUNT_LEVEL_RANK), true);
            }
            if (array_key_exists('role', $ov)) {
                if ($ov['role'] === null) {
                    $usSet['role'] = null;
                } else {
                    $r = v_enum($ov, 'role', array_keys(ROLE_RANK), true);
                    if (!role_gte($ownRole, $r)) {
                        admin_forbid('자신보다 높은 권한을 부여할 수 없습니다.', 'service_overrides');
                    }
                    $usSet['role'] = $r;
                }
            }
            if (array_key_exists('billing_tier', $ov)) {
                $usSet['billing_tier'] = $ov['billing_tier'] === null
                    ? null : v_enum($ov, 'billing_tier', array_keys(BILLING_TIER_RANK), true);
            }
            if (array_key_exists('billing_expires_at', $ov)) {
                $usSet['billing_expires_at'] = admin_parse_dt($ov['billing_expires_at'], 'service_overrides');
            }
            if (array_key_exists('status', $ov)) {
                $usSet['status'] = v_enum($ov, 'status', ['active', 'blocked'], true);
            }
            if ($usSet === []) {
                continue;
            }
            $ovEntry = ['service_id' => $sid, 'fields' => array_keys($usSet)];
            // 차단 판정은 DB 쓰기 **전에** 확정한다 — 아래에서 $usSet 에 user_id·joined_at 이
            // 더 붙기 때문에 키 집합이 달라진다. status 값 자체는 그대로다.
            $blockNow = (isset($usSet['status']) && $usSet['status'] === 'blocked');
            if ($usRow === null) {
                $usSet['user_id']    = $uid;
                $usSet['service_id'] = $sid;
                $usSet['joined_at']  = now_utc();
                if (!isset($usSet['status'])) {
                    $usSet['status'] = 'active';
                }
                db_insert(T_USER_SERVICES, $usSet);
            } else {
                db_update(T_USER_SERVICES, $usSet, 'id = ?', [(int) $usRow['id']]);
            }
            // D-50 부수 항목: blocked 로 만든 직후 그 서비스의 세션을 회수한다.
            // 전이(active→blocked)만이 아니라 blocked 를 다시 넣을 때도 쓸어낸다 —
            // 멱등하고, 차단 이후 다른 경로로 생긴 세션까지 확실히 정리된다.
            if ($blockNow) {
                $ovEntry['revoked_sessions'] = admin_revoke_service_sessions($uid, $sid);
            }
            $overrideResult[] = $ovEntry;
        }
        if ($overrideResult !== []) {
            $changed[]                   = 'service_overrides';
            $after['service_overrides']  = $overrideResult;
        }
    }

    if ($set !== []) {
        $set['updated_at'] = now_utc();
        db_update(T_USERS, $set, 'id = ?', [$uid]);
    }
    if ($revokeAfter) {
        revoke_user_sessions($uid, 'admin');
    }
    // v1.9.1 - 역할이 바뀌면 저장된 비상 확인값의 role 이 낡는다 -> 폐기(다음 로그인에 새로 발급). 차단·접근 변화는 재판정.
    if (in_array('role', $changed, true)) {
        em_revoke_user($uid, 'role_changed');
    } else {
        foreach ($overrideResult as $ovE) {
            if (in_array('role', $ovE['fields'], true)) {
                em_revoke_user($uid, 'role_changed', (string) $ovE['service_id']);
            }
        }
    }
    em_reconcile_user($uid);

    $adminLogId = null;
    if ($changed !== []) {
        $adminLogId = admin_log_write(
            (int) $ctx['user']['id'], 'user.update', 'user', (string) $target['uuid'],
            $before === [] ? null : $before, $after === [] ? null : $after,
            v_string($body, 'reason', 0, 255, false)
        );
    }

    $fresh = db_one('SELECT * FROM ' . T_USERS . ' WHERE id = ?', [$uid]);
    json_out([
        'user'           => mask_user_view(serialize_user($fresh === null ? $target : $fresh, null, true), ctx_global_role($ctx)),
        'changed_fields' => $changed,
        'admin_log_id'   => $adminLogId,
    ]);
}

// -----------------------------------------------------------------
// DELETE — 하드 삭제 (superadmin)
// -----------------------------------------------------------------
require_global_role($ctx, 'superadmin');
require_csrf();

// DELETE 는 쿼리·본문 양쪽에서 파라미터를 받는다(쿼리가 우선).
$in     = array_merge(body_json(), $_GET);
$target = admin_user_by_uuid(in_raw($in, 'uuid'));
emergency_target_guard($target);   // v1.10.0 §6-1 — 비상 계정 삭제는 CLI 전용(403)
$uid    = (int) $target['id'];

// D-30: 진입부 랭크 가드 → 그 뒤에 기존 개별 가드(자기 삭제 금지·마지막 superadmin 보호)를 남긴다.
admin_guard_target_rank($ctx, $target, '삭제');
admin_guard_destructive($ctx, $target, '삭제');

$confirm = v_string($in, 'confirm', 1, 190, true);
if (!hash_equals((string) $target['username'], (string) $confirm)) {
    throw new AppError('VALIDATION_FAILED', 400, [
        'field'   => 'confirm',
        'message' => '확인 문구가 일치하지 않습니다. 대상 아이디를 정확히 입력해 주세요.',
    ]);
}
$reason = v_string($in, 'reason', 0, 255, false);

$uuid     = (string) $target['uuid'];
$username = (string) $target['username'];

$result = db_tx(function () use ($uid, $uuid, $username, $reason, $ctx) {
    // v1.8.4 — 시스템 계정 연결 자동 해제(설계 92 E절, D-172 소비자). **사용자 행을 지우기 전에** 한다 —
    // 사건 피드가 uuid 를 그 항목의 admin_logs 기록에서 되찾는다(core/accountlink.php 의 al_feed()).
    $unlinked = al_release_user($uid, 'user_delete');

    // 연관 행 정리
    $purged = [
        'account_links'        => $unlinked,
        'sessions'             => db_exec('DELETE FROM ' . T_SESSIONS . ' WHERE user_id = ?', [$uid]),
        'user_services'        => db_exec('DELETE FROM ' . T_USER_SERVICES . ' WHERE user_id = ?', [$uid]),
        // v1.10.0 (설계 127 §1) — 그룹 구성원 행. 같은 id 가 재사용되지는 않지만 고아 행을 남기지 않는다
        'access_group_members' => (defined('T_ACCESS_GROUP_MEMBERS') && db_table_exists(T_ACCESS_GROUP_MEMBERS))
            ? db_exec('DELETE FROM ' . T_ACCESS_GROUP_MEMBERS . ' WHERE user_id = ?', [$uid]) : 0,
        'user_consents'        => db_exec('DELETE FROM ' . T_CONSENTS . ' WHERE user_id = ?', [$uid]),
        'auth_codes'           => db_exec('DELETE FROM ' . T_AUTH_CODES . ' WHERE user_id = ?', [$uid]),
        'password_resets'      => db_exec('DELETE FROM ' . T_PW_RESETS . ' WHERE user_id = ?', [$uid]),
        'email_verifications'  => db_exec('DELETE FROM ' . T_EMAIL_VERIFY . ' WHERE user_id = ?', [$uid]),
    ];

    // 로그는 남긴다. login_logs.user_id 만 NULL 허용이라 그것만 익명화된다.
    // point_logs.user_id / admin_logs.admin_id 는 스키마상 NOT NULL 이라 행을 보존한다
    // (개수만 보고한다. 상세는 02c 리포트 참조)
    $anonLogin = db_exec('UPDATE ' . T_LOGIN_LOGS . ' SET user_id = NULL WHERE user_id = ?', [$uid]);
    $pointRows = (int) db_val('SELECT COUNT(*) FROM ' . T_POINT_LOGS . ' WHERE user_id = ?', [$uid]);
    db_exec('UPDATE ' . T_POINT_LOGS . ' SET admin_id = NULL WHERE admin_id = ?', [$uid]);
    $adminRows = (int) db_val('SELECT COUNT(*) FROM ' . T_ADMIN_LOGS . ' WHERE admin_id = ?', [$uid]);

    db_exec('DELETE FROM ' . T_USERS . ' WHERE id = ?', [$uid]);

    $logId = admin_log_write(
        (int) $ctx['user']['id'], 'user.delete', 'user', $uuid,
        ['username' => $username, 'uuid' => $uuid],
        ['deleted' => true],
        $reason
    );

    return [
        'purged'     => $purged,
        'anonymized' => [
            'login_logs' => $anonLogin,
            'point_logs' => $pointRows,
            'admin_logs' => $adminRows,
        ],
        'admin_log_id' => $logId,
    ];
});

json_out([
    'deleted'      => true,
    'uuid'         => $uuid,
    'username'     => $username,
    'purged'       => $result['purged'],
    'anonymized'   => $result['anonymized'],
    'admin_log_id' => $result['admin_log_id'],
]);
