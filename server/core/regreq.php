<?php
declare(strict_types=1);

/**
 * core/regreq.php — 가입 신청(approval 모드) 저장소 · 상태 전이 · 판단 재료 (v1.10.1)
 * 담당 계약: `_workspace/130_architect_v1101_design.md` §2 · 판정 D-371
 *
 * 상태 전이는 이 파일의 세 함수만 쓴다(설계 §2-3). 모든 UPDATE 는 `WHERE status = 'pending'` 조건부이고 rowCount 1 을 확인한다.
 *   pending → approved  regreq_approve()  (users 생성과 같은 트랜잭션)
 *   pending → rejected  regreq_reject()
 *   pending → expired   regreq_gc()       (gc.php 확률 청소)
 * 승인 전에는 users 행을 만들지 않는다(§2-1) — 메일 인증·상태 변경 API 로 승인을 건너뛸 길이 없다.
 * 로드: bootstrap 을 지난 뒤 require_once.
 */

if (!defined('ULOGIN_BOOTSTRAPPED')) {
    header_remove('X-Powered-By');
    http_response_code(404);
    exit;
}

const REGREQ_STATUSES       = ['pending', 'approved', 'rejected', 'expired'];
const REGREQ_TTL_DAYS       = 30;
const REGREQ_KEEP_DAYS      = 180;   // 결정된 행 보존(개인정보 최소화)
const REGREQ_IP_PENDING_CAP = 3;
const REGREQ_NET_PENDING_CAP = 10;  // v1.10.1 보안 L-4 — 같은 망(IPv4 /24 · IPv6 /48)의 유효 대기 신청 상한(대기열 채우기 방어)
const REGREQ_GENERIC_RETRY   = 3600; // 사유를 숨긴 거절의 retry_after 창 — register:ip 빈도 제한(5/3600)과 같은 창
const REGREQ_QUEUE_CAP      = 200;
const REGREQ_REJECT_COOL    = 7;     // 같은 이메일 거부 뒤 재신청 금지 일수
const REGREQ_ENTRIES        = ['register_page', 'sso'];

/** 유효한 pending(만료 전) 조건 조각. */
function regreq_live_where(): string
{
    return 'status = ? AND expires_at > ?';
}

/** users 또는 유효한 pending 신청과 겹치면 409(기존 코드). $excludeId 는 자기 자신. */
function regreq_assert_unique(string $username, string $email, bool $checkPending = true): void
{
    if (db_val('SELECT id FROM ' . T_USERS . ' WHERE username = ?', [$username]) !== null) {
        throw new AppError('DUPLICATE_USERNAME', 409, ['field' => 'username']);
    }
    if (db_val('SELECT id FROM ' . T_USERS . ' WHERE email = ?', [$email]) !== null) {
        throw new AppError('DUPLICATE_EMAIL', 409, ['field' => 'email']);
    }
    if (!$checkPending || !db_table_exists(T_REGISTER_REQUESTS)) {
        return;
    }
    $now = now_utc();
    if (db_val('SELECT id FROM ' . T_REGISTER_REQUESTS . ' WHERE username = ? AND ' . regreq_live_where(),
            [$username, 'pending', $now]) !== null) {
        throw new AppError('DUPLICATE_USERNAME', 409, ['field' => 'username']);
    }
    if (db_val('SELECT id FROM ' . T_REGISTER_REQUESTS . ' WHERE email = ? AND ' . regreq_live_where(),
            [$email, 'pending', $now]) !== null) {
        throw new AppError('DUPLICATE_EMAIL', 409, ['field' => 'email']);
    }
}

function regreq_email_domain(string $email): string
{
    $at = strrpos($email, '@');
    return $at === false ? '' : strtolower(substr($email, $at + 1));
}

/** v1.10.1 L-4 — IP 의 망 키(IPv4 /24 · IPv6 /48). 해석할 수 없으면 null(망 상한을 걸지 않는다 — IP 상한은 그대로 걸린다). */
function regreq_net_key(string $ip): ?string
{
    $bin = @inet_pton($ip);
    if (!is_string($bin)) {
        return null;
    }
    if (strlen($bin) === 16 && substr($bin, 0, 12) === str_repeat(chr(0), 10) . chr(255) . chr(255)) {
        $bin = substr($bin, 12);   // ::ffff:a.b.c.d → IPv4 로 본다
    }
    return strlen($bin) === 4 ? '4:' . bin2hex(substr($bin, 0, 3)) : '6:' . bin2hex(substr($bin, 0, 6));
}

/** 같은 망의 유효 대기 신청 수. 대기열 전체가 REGREQ_QUEUE_CAP(200) 이하라 행을 PHP 로 세도 비용이 작다. */
function regreq_net_pending_count(string $ip, string $now): int
{
    $key = regreq_net_key($ip);
    if ($key === null) {
        return 0;
    }
    $n = 0;
    foreach (db_all('SELECT ip FROM ' . T_REGISTER_REQUESTS . ' WHERE ' . regreq_live_where() . ' AND ip IS NOT NULL', ['pending', $now]) as $r) {
        if (regreq_net_key((string) $r['ip']) === $key) {
            $n++;
        }
    }
    return $n;
}

/**
 * v1.10.1 L-4 — 사유를 숨긴 접수 거절. 응답이 register:ip 빈도 제한(rate_limit)의 429 와 **같은 모양**이다
 * (RATE_LIMITED + retry_after 만). 같은 이메일이 최근 거부됐다는 사실을 밖에 알리지 않는다. 운영자는 서버 로그로 본다.
 */
function regreq_generic_refusal(string $why): AppError
{
    log_throttled('regreq_refused_' . $why, '[ulogin] register request refused (' . $why . ')');
    $win = REGREQ_GENERIC_RETRY;
    return new AppError('RATE_LIMITED', 429, ['retry_after' => max(1, intdiv(time(), $win) * $win + $win - time())]);
}

/**
 * 신청 접수. 남용 방지 3종은 **트랜잭션 안에서 다시 센다**(설계 §2-4).
 * @param array $f username, email, password(평문), name, nickname, phone, marketing, applicant_note, entry, redirect_uri
 * @return array ['receipt' => 64hex(원문, 1회), 'created_at' => DATETIME]
 */
function regreq_submit(array $f, ?array $service): array
{
    $now     = now_utc();
    $ip      = client_ip();
    $ua      = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255, 'UTF-8');
    $receipt = gen_token(32);
    $email   = (string) $f['email'];

    $redirect = null;
    $redirectValid = 0;
    $rawRedirect = $f['redirect_uri'] ?? null;
    if ($service !== null && is_string($rawRedirect) && $rawRedirect !== '' && redirect_uri_allowed($service, $rawRedirect)) {
        $redirect      = $rawRedirect;   // 화이트리스트와 일치할 때만 저장(불일치는 NULL · redirect_valid=0)
        $redirectValid = 1;
    }
    $entry = in_array($f['entry'] ?? null, REGREQ_ENTRIES, true) ? (string) $f['entry'] : 'register_page';

    $consents = register_consents_now(!empty($f['marketing']));
    $hash     = password_hash((string) $f['password'], PASSWORD_DEFAULT);   // bcrypt 는 tx 밖에서(잠금 시간 최소화)

    try {
        db_tx(function () use ($f, $service, $now, $ip, $ua, $receipt, $email, $redirect, $redirectValid, $entry, $consents, $hash) {
            $live = [$ip, 'pending', $now];
            if ((int) db_val('SELECT COUNT(*) FROM ' . T_REGISTER_REQUESTS . ' WHERE ip = ? AND ' . regreq_live_where(), $live)
                >= REGREQ_IP_PENDING_CAP) {
                throw new AppError('RATE_LIMITED', 429, ['reason' => 'ip_pending_cap']);
            }
            if (regreq_net_pending_count($ip, $now) >= REGREQ_NET_PENDING_CAP) {   // NET-CAP
                throw new AppError('RATE_LIMITED', 429, ['reason' => 'net_pending_cap']);
            }
            if (db_val('SELECT id FROM ' . T_REGISTER_REQUESTS . ' WHERE email = ? AND status = ? AND decided_at >= ?',
                    [$email, 'rejected', gmdate('Y-m-d H:i:s', time() - REGREQ_REJECT_COOL * 86400)]) !== null) {
                throw regreq_generic_refusal('recently_rejected');   // 사유 숨김(L-4)
            }
            if ((int) db_val('SELECT COUNT(*) FROM ' . T_REGISTER_REQUESTS . ' WHERE ' . regreq_live_where(), ['pending', $now])
                >= REGREQ_QUEUE_CAP) {
                throw new AppError('RATE_LIMITED', 429, ['reason' => 'queue_full']);
            }
            regreq_assert_unique((string) $f['username'], $email, true);
            db_insert(T_REGISTER_REQUESTS, [
                'receipt_hash'     => token_hash($receipt),
                'status'           => 'pending',
                'username'         => (string) $f['username'],
                'email'            => $email,
                'email_domain'     => regreq_email_domain($email),
                'password_hash'    => $hash,
                'name'             => $f['name'] ?? null,
                'nickname'         => $f['nickname'] ?? null,
                'phone'            => $f['phone'] ?? null,
                'marketing_agreed' => !empty($f['marketing']) ? 1 : 0,
                'consents_json'    => json_encode($consents, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'applicant_note'   => $f['applicant_note'] ?? null,
                'source'           => $service !== null ? 'service' : 'direct',
                'service_id'       => $service !== null ? (string) $service['service_id'] : null,
                'redirect_uri'     => $redirect,
                'redirect_valid'   => $redirectValid,
                'entry'            => $entry,
                'ip'               => $ip,
                'user_agent'       => $ua,
                'created_at'       => $now,
                'expires_at'       => gmdate('Y-m-d H:i:s', time() + REGREQ_TTL_DAYS * 86400),
            ]);
        });
    } catch (Throwable $e) {
        if (db_is_duplicate($e)) {
            // 동시 신청 경쟁(receipt 충돌은 사실상 없다) — 겹친 쪽을 다시 확인해 정확한 코드를 준다
            regreq_assert_unique((string) $f['username'], $email, true);
            throw new AppError('CONFLICT', 409, ['reason' => 'concurrent_request']);
        }
        throw $e;
    }
    return ['receipt' => $receipt, 'created_at' => $now];
}

/** 접수번호로 결과 조회(신청자용). 없으면 null. 만료 시각이 지난 pending 은 expired 로 보인다. */
function regreq_status_by_receipt(string $receipt): ?array
{
    $r = db_one('SELECT status, created_at, expires_at, decided_at, decision_notice FROM ' . T_REGISTER_REQUESTS
        . ' WHERE receipt_hash = ?', [token_hash($receipt)]);
    if ($r === null) {
        return null;
    }
    $st = (string) $r['status'];
    if ($st === 'pending' && (string) $r['expires_at'] <= now_utc()) {
        $st = 'expired';
    }
    return [
        'status'     => $st,
        'created_at' => iso($r['created_at']),
        'decided_at' => iso($r['decided_at']),
        'notice'     => $r['decision_notice'] !== null && (string) $r['decision_notice'] !== '' ? (string) $r['decision_notice'] : null,
    ];
}

/** 판단 재료. 집계는 **원문**으로 센다(가리는 것은 보여 줄 때뿐 — 설계 §2-5). $full=false 면 목록용 2종. */
function regreq_signals(array $r, bool $full): array
{
    $id  = (int) $r['id'];
    $ip  = (string) ($r['ip'] ?? '');
    $dom = (string) $r['email_domain'];
    $t   = function (int $days): string {
        return gmdate('Y-m-d H:i:s', time() - $days * 86400);
    };
    $cnt = function (string $where, array $p) use ($id): int {
        return (int) db_val('SELECT COUNT(*) FROM ' . T_REGISTER_REQUESTS . ' WHERE id <> ? AND ' . $where, array_merge([$id], $p));
    };
    $out = [
        'same_ip_24h'           => $ip === '' ? 0 : $cnt('ip = ? AND created_at >= ?', [$ip, $t(1)]),
        'same_email_domain_30d' => $dom === '' ? 0 : $cnt('email_domain = ? AND created_at >= ?', [$dom, $t(30)]),
    ];
    if (!$full) {
        return $out;
    }
    $likeDom = '%@' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $dom);
    return [
        'same_ip_24h'                => $out['same_ip_24h'],
        'same_ip_30d'                => $ip === '' ? 0 : $cnt('ip = ? AND created_at >= ?', [$ip, $t(30)]),
        'same_email_domain_30d'      => $out['same_email_domain_30d'],
        'users_same_email_domain'    => $dom === '' ? 0
            : (int) db_val('SELECT COUNT(*) FROM ' . T_USERS . ' WHERE email LIKE ?' . sql_like_escape(), [$likeDom]),
        'prior_rejected_same_email'  => $cnt('email = ? AND status = ?', [(string) $r['email'], 'rejected']),
        'prior_rejected_same_ip_30d' => $ip === '' ? 0 : $cnt('ip = ? AND status = ? AND created_at >= ?', [$ip, 'rejected', $t(30)]),
        'username_taken'             => db_val('SELECT id FROM ' . T_USERS . ' WHERE username = ?', [(string) $r['username']]) !== null,
        'email_taken'                => db_val('SELECT id FROM ' . T_USERS . ' WHERE email = ?', [(string) $r['email']]) !== null,
    ];
}

/** 목록·상세 항목. 가리기는 mask_for_viewer() 안에만 있다(T1-6). */
function regreq_item(array $r, string $viewerRole, bool $full, array $svcNames): array
{
    $st = (string) $r['status'];
    if ($st === 'pending' && (string) $r['expires_at'] <= now_utc()) {
        $st = 'expired';
    }
    $sid = $r['service_id'] !== null ? (string) $r['service_id'] : null;
    $ua  = (string) ($r['user_agent'] ?? '');
    $it = [
        'id'           => (int) $r['id'],
        'status'       => $st,
        'username'     => (string) $r['username'],
        'name'         => $r['name'] !== null ? (string) $r['name'] : null,
        'email'        => mask_for_viewer('email', (string) $r['email'], $viewerRole),
        'source'       => (string) $r['source'],
        'service_id'   => $sid,
        'service_name' => $sid !== null && isset($svcNames[$sid]) ? (string) $svcNames[$sid] : null,
        'ip'           => mask_for_viewer('ip', $r['ip'], $viewerRole),
        'ua_label'     => $ua === '' ? null : device_label_from_ua($ua),
        'created_at'   => iso($r['created_at']),
        'signals'      => regreq_signals($r, $full),
    ];
    if (!$full) {
        return $it;
    }
    $decider = null;
    if ($r['decided_by'] !== null) {
        $du = db_one('SELECT uuid, username FROM ' . T_USERS . ' WHERE id = ?', [(int) $r['decided_by']]);
        $decider = $du === null ? null : (string) $du['username'];
    }
    $userUuid = null;
    if ($r['user_id'] !== null) {
        $uu = db_val('SELECT uuid FROM ' . T_USERS . ' WHERE id = ?', [(int) $r['user_id']]);
        $userUuid = $uu === null ? null : (string) $uu;
    }
    $svc = null;
    if ($sid !== null) {
        $s = db_one('SELECT service_id, service_name, status, allow_register FROM ' . T_SERVICES . ' WHERE service_id = ?', [$sid]);
        if ($s !== null) {
            $svc = ['id' => (string) $s['service_id'], 'name' => (string) $s['service_name'],
                    'status' => (string) $s['status'], 'allow_register' => (bool) $s['allow_register']];
        }
    }
    return array_merge($it, [
        'nickname'         => $r['nickname'] !== null ? (string) $r['nickname'] : null,
        'phone'            => mask_for_viewer('phone', $r['phone'], $viewerRole),
        'marketing_agreed' => (bool) $r['marketing_agreed'],
        'applicant_note'   => $r['applicant_note'] !== null ? (string) $r['applicant_note'] : null,
        'entry'            => (string) $r['entry'],
        'redirect_uri'     => $r['redirect_uri'] !== null ? (string) $r['redirect_uri'] : null,
        'redirect_valid'   => (bool) $r['redirect_valid'],
        'user_agent'       => mask_for_viewer('ua', $ua === '' ? null : $ua, $viewerRole),
        'expires_at'       => iso($r['expires_at']),
        'decided_at'       => iso($r['decided_at']),
        'decided_by'       => $decider,
        'decision_reason'  => $r['decision_reason'] !== null ? (string) $r['decision_reason'] : null,
        'decision_notice'  => $r['decision_notice'] !== null ? (string) $r['decision_notice'] : null,
        'user_uuid'        => $userUuid,
        'service'          => $svc,
    ]);
}

/** 잠근 행을 읽고 결정 가능한지 본다(tx 안). 없는 id 404 · 끝난 신청 409 already_decided. */
function regreq_lock_pending(int $id): array
{
    $r = db_one('SELECT * FROM ' . T_REGISTER_REQUESTS . ' WHERE id = ?' . sql_for_update(), [$id]);
    if ($r === null) {
        throw new AppError('NOT_FOUND', 404, ['field' => 'id']);
    }
    $st = (string) $r['status'];
    if ($st === 'pending' && (string) $r['expires_at'] <= now_utc()) {
        $st = 'expired';
    }
    if ($st !== 'pending') {
        throw new AppError('CONFLICT', 409, ['field' => 'id', 'reason' => 'already_decided', 'status' => $st]);
    }
    return $r;
}

/** 조건부 전이. 1행이 아니면 잠금이 빠진 것 — 던져서 롤백한다(조용히 넘어가지 않는다). */
function regreq_transition(int $id, array $set): void
{
    $cols = [];
    $p    = [];
    foreach ($set as $k => $v) {
        $cols[] = $k . ' = ?';
        $p[]    = $v;
    }
    $p[] = $id;
    $p[] = 'pending';
    $n = db_exec('UPDATE ' . T_REGISTER_REQUESTS . ' SET ' . implode(', ', $cols) . ' WHERE id = ? AND status = ?', $p);
    if ($n !== 1) {
        throw new RuntimeException('register request ' . $id . ' transition affected ' . $n . ' rows');
    }
}

/**
 * 승인 — users 생성과 같은 tx(설계 §2-5). 그 사이 아이디·이메일이 생겼으면 409 DUPLICATE_* 이고 신청은 pending 으로 남는다.
 * @return array ['request' => {...}, 'warnings' => [...], 'user_id' => int, 'email' => string, 'require_verify' => bool, 'username' => string]
 */
function regreq_approve(int $id, int $adminId, ?string $memo): array
{
    $requireVerify = (bool) setting_get('require_email_verify', REQUIRE_EMAIL_VERIFY);
    $bonus         = max(0, (int) setting_get('signup_bonus_points', SIGNUP_BONUS_POINTS));
    $now           = now_utc();
    try {
        return db_tx(function () use ($id, $adminId, $memo, $requireVerify, $bonus, $now) {
            $r = regreq_lock_pending($id);
            regreq_assert_unique((string) $r['username'], (string) $r['email'], false);
            $warnings = [];
            $svc = null;
            if ($r['service_id'] !== null) {
                $svc = db_one('SELECT * FROM ' . T_SERVICES . ' WHERE service_id = ?', [(string) $r['service_id']]);
                if ($svc === null || (string) $svc['status'] !== 'active') {
                    $svc = null;
                    $warnings[] = 'service_inactive';
                }
            }
            if ((string) ($r['password_hash'] ?? '') === '') {
                throw new RuntimeException('register request ' . $id . ' is pending without a password hash');
            }
            $consents = json_decode((string) ($r['consents_json'] ?? '[]'), true);
            $uid = register_create_user([
                'username'       => (string) $r['username'],
                'email'          => (string) $r['email'],
                'password_hash'  => (string) $r['password_hash'],
                'name'           => $r['name'],
                'nickname'       => $r['nickname'],
                'phone'          => $r['phone'],
                'marketing'      => (int) $r['marketing_agreed'] === 1,
                'require_verify' => $requireVerify,
                'bonus'          => $bonus,
                'consents'       => is_array($consents) ? $consents : [],
            ], $svc, $now, (string) ($r['ip'] ?? ''), (string) $r['created_at']);
            regreq_transition($id, [
                'status'          => 'approved',
                'password_hash'   => null,
                'decided_at'      => $now,
                'decided_by'      => $adminId,
                'decision_reason' => $memo,
                'user_id'         => $uid,
            ]);
            return [
                'request'        => ['id' => $id, 'status' => 'approved',
                                     'user_uuid' => (string) db_val('SELECT uuid FROM ' . T_USERS . ' WHERE id = ?', [$uid]),
                                     'decided_at' => iso($now)],
                'warnings'       => $warnings,
                'user_id'        => $uid,
                'username'       => (string) $r['username'],
                'email'          => (string) $r['email'],
                'require_verify' => $requireVerify,
            ];
        });
    } catch (Throwable $e) {
        if (db_is_duplicate($e)) {
            $row = db_one('SELECT username, email FROM ' . T_REGISTER_REQUESTS . ' WHERE id = ?', [$id]);
            if ($row !== null) {
                regreq_assert_unique((string) $row['username'], (string) $row['email'], false);
            }
            throw new AppError('DUPLICATE_USERNAME', 409, ['field' => 'username']);
        }
        throw $e;
    }
}

/** 거부 — 사유 필수(1~255), 안내 선택(≤200). */
function regreq_reject(int $id, int $adminId, string $reason, ?string $notice): array
{
    $now = now_utc();
    return db_tx(function () use ($id, $adminId, $reason, $notice, $now) {
        $r = regreq_lock_pending($id);
        regreq_transition($id, [
            'status'          => 'rejected',
            'password_hash'   => null,
            'decided_at'      => $now,
            'decided_by'      => $adminId,
            'decision_reason' => $reason,
            'decision_notice' => $notice,
        ]);
        return ['request' => ['id' => $id, 'status' => 'rejected', 'decided_at' => iso($now)],
                'username' => (string) $r['username']];
    });
}

/** gc — 만료(pending → expired, 비밀번호 해시 삭제) · 결정 뒤 180일 지난 행 삭제. 표가 없으면 아무것도 안 한다. */
function regreq_gc(): void
{
    if (!defined('T_REGISTER_REQUESTS') || !db_table_exists(T_REGISTER_REQUESTS)) {
        return;
    }
    $now = now_utc();
    db_exec('UPDATE ' . T_REGISTER_REQUESTS . ' SET status = ?, password_hash = NULL, decided_at = ?'
        . ' WHERE status = ? AND expires_at <= ?', ['expired', $now, 'pending', $now]);
    db_delete_limited(T_REGISTER_REQUESTS, 'status <> ? AND decided_at IS NOT NULL AND decided_at < ?',
        ['pending', gmdate('Y-m-d H:i:s', time() - REGREQ_KEEP_DAYS * 86400)], 500);
}
