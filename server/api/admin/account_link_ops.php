<?php
declare(strict_types=1);
/**
 * POST /server/api/admin/account_link_ops.php — 시스템 계정 연결 쓰기 (v1.8.4)
 * 담당 계약: 설계 92 D · E · G-4 · G-5 · G-6 / 리더 판정 D-324 H-4(파일 가져오기) · D-328
 * 본문 `action` 으로 갈린다:
 *   plan        (superadmin) 계획 — 상태를 바꾸지 않는다(작업·항목 행만 기록)
 *   commit      (superadmin) 확정 — 한 요청 25건, 항목마다 작은 트랜잭션. 반복 호출한다
 *   cancel      (superadmin) 계획 취소
 *   setup_link  (superadmin) 설정 대기 계정의 첫 비밀번호 설정 링크 재발급(이전 링크 무효)
 *   import      (superadmin) 파일 가져오기 — S-2 형식 JSON 을 push 와 **같은 검증·저장 함수**로
 *   sync_request(admin)      재수집 요청 표식 — 시스템이 다음 확인 때 전체 재전송
 * 권한은 **전역 users.role 만**(require_global_role, D-50). 서비스 오버라이드는 보지 않는다.
 *
 * 응답 최상위 키
 *   plan        : plan_id, expires_at, summary, items, new_users
 *   commit      : job_id, status, processed, remaining, results, totals
 *   cancel      : plan_id, status
 *   setup_link  : uuid, setup_url, setup_expires_at, invalidated
 *   import      : sync_id, page, accepted, created, updated, unchanged, duplicate, sync_requested
 *   sync_request: service_id, sync_requested_at
 *
 * **계정을 만드는 경로는 이 파일의 commit(create_link) 하나다** — register.php · allow_register(D-315)를
 * 거치지 않고, 공개 엔드포인트는 계정을 만들지 않는다. 새 계정의 등급·역할은 기본값 고정
 * (basic/user/free) — 요청 본문의 어떤 값도 등급으로 쓰지 않는다(이관이 권한 부여 경로가 되지 않게).
 * 설정 링크 원문은 commit·setup_link **응답에서 한 번만** 나간다. admin_logs·error_log 에 남기지 않는다.
 */
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/accountlink.php';

// CLI(tools/unify_admin.php)가 함수만 쓰려고 include 할 때는 인증·디스패치를 건너뛴다.
if (!defined('ULOGIN_OPS_LIB')) {
only_method('POST');

$ctx = require_auth();
require_global_role($ctx, CAN_MAP['account_link.view']);
require_csrf();

$adminId = (int) $ctx['user']['id'];

// 파일 가져오기는 본문이 크다 — 헤더로 먼저 끊는다(진짜 상한은 import 분기의 실측).
$clen = isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] !== '' ? (int) $_SERVER['CONTENT_LENGTH'] : -1;
if ($clen > AL_FILE_MAX_BYTES + 4096) {
    throw new AppError('VALIDATION_FAILED', 400, ['field' => 'body', 'message' => '본문이 너무 큽니다.']);
}
$in     = body_json();
$action = v_enum($in, 'action', ['plan', 'commit', 'cancel', 'setup_link', 'import', 'sync_request'], true);

if ($action !== 'sync_request') {
    require_global_role($ctx, CAN_MAP['account_link.manage']);
}
}

// =====================================================================
// 공용
// =====================================================================

function alo_codes_verdict(array $codes): string
{
    $v = 'ok';
    foreach ($codes as $c) {
        if (!in_array($c, AL_WARN_CODES, true)) {
            return 'invalid';
        }
        $v = 'warn';
    }
    return $v;
}

/** 유효 코드가 먼저, 경고가 뒤에 오게. 중복 제거. */
function alo_sort_codes(array $codes): array
{
    $bad = [];
    $warn = [];
    foreach (array_values(array_unique($codes)) as $c) {
        if (in_array($c, AL_WARN_CODES, true)) {
            $warn[] = $c;
        } else {
            $bad[] = $c;
        }
    }
    return array_merge($bad, $warn);
}

/** ulogin 아이디 규칙(auth.php)에 맞는 제안. 쓰인 이름(DB + 이번 계획)은 피한다. */
function alo_suggest_username(string $base, array $taken): string
{
    $b = (string) preg_replace('/[^a-z0-9_]/', '_', mb_strtolower($base, 'UTF-8'));
    $b = trim((string) preg_replace('/_+/', '_', $b), '_');
    if (strlen($b) < 4) {
        $b = ($b === '' ? 'user' : $b . '_user');
    }
    $b = substr($b, 0, 20);
    $free = function (string $u) use ($taken): bool {
        return !isset($taken[$u]) && db_val('SELECT id FROM ' . T_USERS . ' WHERE username = ?', [$u]) === null;
    };
    if (preg_match('/^[a-z0-9_]{4,20}$/', $b) === 1 && $free($b)) {
        return $b;
    }
    for ($i = 2; $i < 1000; $i++) {
        $sfx  = '_' . $i;
        $cand = substr($b, 0, 20 - strlen($sfx)) . $sfx;
        if ($free($cand)) {
            return $cand;
        }
    }
    return substr('user_' . bin2hex(random_bytes(4)), 0, 20);
}

/** commit·cancel 이 받을 수 있는 계획인가. 아니면 LINK_PLAN_INVALID 하나로(만료·남의 것·취소·확정). */
function alo_load_plan($planId, int $adminId): array
{
    if (!al_is_uuid($planId)) {
        throw new AppError('LINK_PLAN_INVALID', 409, ['field' => 'plan_id']);
    }
    $j = db_one('SELECT * FROM ' . T_LINK_JOBS . ' WHERE job_uuid = ? AND kind = ?', [strtolower((string) $planId), 'plan']);
    if ($j === null || (int) $j['created_by'] !== $adminId
        || !in_array((string) $j['status'], ['planned', 'running'], true)) {
        throw new AppError('LINK_PLAN_INVALID', 409, ['field' => 'plan_id']);
    }
    if ((int) ts($j['expires_at']) <= time()) {
        if ((string) $j['status'] === 'planned') {
            db_exec('UPDATE ' . T_LINK_JOBS . ' SET status = ?, updated_at = ? WHERE id = ? AND status = ?',
                ['expired', now_utc(), $j['id'], 'planned']);
        }
        throw new AppError('LINK_PLAN_INVALID', 409, ['field' => 'plan_id']);
    }
    return $j;
}

// =====================================================================
// plan
// =====================================================================

/**
 * 요청 본문 → 정규화된 new_users·items. 구조 오류는 400(field 지정). 판정(ok/warn/invalid)은 여기서 하지 않는다.
 * 새 계정에서 받는 필드는 ref·username·email·name **넷뿐**이다 — role·account_level 등은 읽지도 않는다.
 */
function alo_parse_plan_body(array $in): array
{
    $nuRaw = in_raw($in, 'new_users');
    $nuRaw = $nuRaw === null ? [] : $nuRaw;
    if (!is_array($nuRaw) || count($nuRaw) > AL_PLAN_MAX_ITEMS) {
        v_fail('new_users', 'new_users 는 ' . AL_PLAN_MAX_ITEMS . '개 이하의 배열이어야 합니다.');
    }
    $newUsers = [];
    foreach (array_values($nuRaw) as $i => $n) {
        $f = 'new_users[' . $i . ']';
        if (!is_array($n)) {
            v_fail($f, '객체여야 합니다.');
        }
        $ref = isset($n['ref']) && is_string($n['ref']) ? $n['ref'] : '';
        if (preg_match('/^[A-Za-z0-9_\-]{1,16}$/', $ref) !== 1 || isset($newUsers[$ref])) {
            v_fail($f . '.ref', 'ref는 영문·숫자·_- 1~16자이고 계획 안에서 유일해야 합니다.');
        }
        $email = al_opt_str($n['email'] ?? null, 190);
        if ($email !== null) {
            $email = mb_strtolower($email, 'UTF-8');
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                v_fail($f . '.email', '이메일 형식이 올바르지 않습니다.');
            }
        }
        $newUsers[$ref] = [
            'ref'      => $ref,
            'username' => al_opt_str($n['username'] ?? null, 50),
            'email'    => $email,
            'name'     => al_opt_str($n['name'] ?? null, 80),
        ];
    }

    $itRaw = in_raw($in, 'items');
    if (!is_array($itRaw) || count($itRaw) < 1 || count($itRaw) > AL_PLAN_MAX_ITEMS) {
        v_fail('items', 'items 는 1~' . AL_PLAN_MAX_ITEMS . '개여야 합니다.');
    }
    $items   = [];
    $seenExt = [];
    $creates = [];
    foreach (array_values($itRaw) as $i => $it) {
        $f = 'items[' . $i . ']';
        if (!is_array($it)) {
            v_fail($f, '객체여야 합니다.');
        }
        $op = isset($it['op']) && is_string($it['op']) ? $it['op'] : '';
        if (!isset(AL_TRANSITIONS[$op])) {
            v_fail($f . '.op', 'op는 create_link|link|unlink|ignore|unignore 중 하나여야 합니다.');
        }
        $sid = isset($it['service_id']) && is_string($it['service_id']) ? $it['service_id'] : '';
        if ($sid === '' || strlen($sid) > 64) {
            v_fail($f . '.service_id', 'service_id가 필요합니다.');
        }
        $eid = $it['external_id'] ?? null;
        if (is_int($eid)) {
            $eid = (string) $eid;
        }
        if (!is_string($eid) || $eid === '' || mb_strlen($eid, 'UTF-8') > 128) {
            v_fail($f . '.external_id', 'external_id가 필요합니다.');
        }
        $key = $sid . "\n" . $eid;
        if (isset($seenExt[$key])) {
            // 같은 외부 계정 두 항목(예: 해제+재연결) — 미리보기에서 주인이 바뀌는 걸 숨기지 않으려고 금지(설계 C절)
            v_fail('items', '같은 외부 계정(' . $sid . ')이 계획에 두 번 있습니다. 계획을 나누세요.');
        }
        $seenExt[$key] = true;
        $ref  = null;
        $uuid = null;
        $tg   = $it['target'] ?? null;
        if ($op === 'create_link' || $op === 'link') {
            if (!is_array($tg)) {
                v_fail($f . '.target', '대상(target.ref 또는 target.uuid)이 필요합니다.');
            }
            if (isset($tg['ref']) && is_string($tg['ref']) && $tg['ref'] !== '') {
                $ref = $tg['ref'];
                if (!isset($newUsers[$ref])) {
                    v_fail($f . '.target', 'new_users에 없는 ref 입니다: ' . substr($ref, 0, 16));
                }
            } elseif ($op === 'link' && isset($tg['uuid']) && al_is_uuid($tg['uuid'])) {
                $uuid = strtolower((string) $tg['uuid']);
            } else {
                v_fail($f . '.target', $op === 'create_link' ? 'create_link는 target.ref가 필요합니다.'
                    : 'link는 target.uuid 또는 target.ref가 필요합니다.');
            }
            if ($op === 'create_link') {
                if (isset($creates[$ref])) {
                    v_fail('items', 'ref ' . $ref . ' 에 create_link가 두 개입니다.');
                }
                $creates[$ref] = true;
            }
        }
        $ev = $it['expect_version'] ?? null;
        if ($ev !== null && (!is_int($ev) || $ev < 0)) {
            v_fail($f . '.expect_version', 'expect_version은 0 이상의 정수여야 합니다.');
        }
        $items[] = ['seq' => $i + 1, 'op' => $op, 'service_id' => $sid, 'external_id' => $eid,
            'target_ref' => $ref, 'target_uuid' => $uuid, 'expect_version' => $ev];
    }
    foreach ($items as $it) {
        if ($it['op'] === 'link' && $it['target_ref'] !== null && !isset($creates[$it['target_ref']])) {
            v_fail('items', 'ref ' . $it['target_ref'] . ' 를 만드는 create_link 항목이 없습니다.');
        }
    }
    foreach ($newUsers as $ref => $nu) {
        if (!isset($creates[$ref])) {
            v_fail('new_users', 'ref ' . $ref . ' 를 쓰는 create_link 항목이 없습니다.');
        }
    }
    return [$newUsers, $items];
}

/** retry_job_id → 그 작업의 failed·skipped 항목으로 같은 모양의 new_users·items. 버전은 현재 값. */
function alo_retry_body($retryId): array
{
    if (!al_is_uuid($retryId)) {
        v_fail('retry_job_id', '작업 식별자 형식이 올바르지 않습니다.');
    }
    $j = db_one('SELECT * FROM ' . T_LINK_JOBS . ' WHERE job_uuid = ? AND kind = ?', [strtolower((string) $retryId), 'plan']);
    if ($j === null) {
        throw new AppError('NOT_FOUND', 404, ['field' => 'retry_job_id']);
    }
    $all  = db_all('SELECT * FROM ' . T_LINK_ITEMS . ' WHERE job_id = ? ORDER BY seq ASC', [$j['id']]);
    $done = [];   // ref => 만들어진 user id
    foreach ($all as $r) {
        if ((string) $r['op'] === 'create_link' && (string) $r['status'] === 'done' && $r['target_ref'] !== null) {
            $done[(string) $r['target_ref']] = (int) $r['result_user_id'];
        }
    }
    $pj = json_col($j['plan_json']);
    $nuSrc = [];
    foreach ((array) ($pj['new_users'] ?? []) as $nu) {
        if (is_array($nu) && isset($nu['ref'])) {
            $nuSrc[(string) $nu['ref']] = $nu;
        }
    }
    $newUsers = [];
    $items    = [];
    foreach ($all as $r) {
        if (!in_array((string) $r['status'], ['failed', 'skipped'], true)) {
            continue;
        }
        $ref  = $r['target_ref'] === null ? null : (string) $r['target_ref'];
        $uuid = null;
        if ($r['target_user_id'] !== null) {
            $uuid = (string) db_val('SELECT uuid FROM ' . T_USERS . ' WHERE id = ?', [$r['target_user_id']]);
        } elseif ($ref !== null && isset($done[$ref])) {
            $uuid = (string) db_val('SELECT uuid FROM ' . T_USERS . ' WHERE id = ?', [$done[$ref]]);
            $ref  = null;
        }
        $op = (string) $r['op'];
        if ($op === 'create_link' && $ref === null) {
            continue;   // 이미 만들어졌다 — 다시 만들 것이 없다
        }
        if ($ref !== null && isset($nuSrc[$ref])) {
            $newUsers[] = ['ref' => $ref, 'username' => $nuSrc[$ref]['username'] ?? null,
                'email' => $nuSrc[$ref]['email'] ?? null, 'name' => $nuSrc[$ref]['name'] ?? null];
        }
        $cur = db_val('SELECT row_version FROM ' . T_EXT_ACCOUNTS . ' WHERE service_id = ? AND external_id = ?',
            [(string) $r['service_id'], (string) $r['external_id']]);
        $items[] = ['op' => $op, 'service_id' => (string) $r['service_id'], 'external_id' => (string) $r['external_id'],
            'target' => $ref !== null ? ['ref' => $ref] : ($uuid !== null && $uuid !== '' ? ['uuid' => $uuid] : null),
            'expect_version' => $cur === null ? null : (int) $cur];
    }
    if ($items === []) {
        v_fail('retry_job_id', '다시 계획할 실패·건너뜀 항목이 없습니다.');
    }
    $uniq = [];
    foreach ($newUsers as $nu) {
        $uniq[$nu['ref']] = $nu;
    }
    return [array_values($uniq), $items, (int) $j['id']];
}

/** 계획 판정. **상태를 바꾸지 않는다** — 읽기만 하고 작업·항목 행만 기록한다(T9). */
function alo_plan(array $in, int $adminId): array
{
    $retryOf = null;
    $retryRaw = in_raw($in, 'retry_job_id');
    if ($retryRaw !== null && $retryRaw !== '') {
        list($nu, $it, $retryOf) = alo_retry_body($retryRaw);
        $in = ['new_users' => $nu, 'items' => $it];
    }
    list($newUsers, $items) = alo_parse_plan_body($in);

    // --- 새 계정 판정 ---
    $takenU = [];
    $takenE = [];
    $nuOut  = [];
    foreach ($newUsers as $ref => $nu) {
        $codes = [];
        $sugg  = null;
        $attach = null;
        $un = $nu['username'] === null ? '' : normalize_username($nu['username']);
        $base = $un !== '' ? $un : ($nu['email'] !== null ? (string) strstr($nu['email'], '@', true) : 'user');
        if ($un === '' || preg_match('/^[a-z0-9_]{4,20}$/', $un) !== 1) {
            $codes[] = 'USERNAME_INVALID';
        } elseif (isset($takenU[$un]) || db_val('SELECT id FROM ' . T_USERS . ' WHERE username = ?', [$un]) !== null) {
            $codes[] = 'DUPLICATE_USERNAME';
        }
        if ($codes !== []) {
            $sugg = alo_suggest_username($base, $takenU);
        }
        if ($nu['email'] === null) {
            $codes[] = 'EMAIL_REQUIRED';
        } else {
            $owner = db_one('SELECT uuid, username FROM ' . T_USERS . ' WHERE email = ?', [$nu['email']]);
            if ($owner !== null) {
                $codes[]  = 'DUPLICATE_EMAIL';
                $attach = ['uuid' => (string) $owner['uuid'], 'username' => (string) $owner['username']];
            } elseif (isset($takenE[$nu['email']])) {
                $codes[] = 'DUPLICATE_EMAIL';
            }
            $takenE[$nu['email']] = true;
        }
        if ($un !== '') {
            $takenU[$un] = true;
        }
        $newUsers[$ref]['username'] = $un;
        $newUsers[$ref]['codes']    = $codes;
        $nuOut[] = ['ref' => $ref, 'verdict' => $codes === [] ? 'ok' : 'invalid', 'codes' => $codes,
            'suggested_username' => $sugg, 'attach_candidate' => $attach];
    }

    // --- 대상 사용자 ---
    $uuids = [];
    foreach ($items as $it) {
        if ($it['target_uuid'] !== null) {
            $uuids[$it['target_uuid']] = true;
        }
    }
    $usersByUuid = [];
    if ($uuids !== []) {
        $k = array_keys($uuids);
        foreach (db_all('SELECT id, uuid, username, status, role, account_kind FROM ' . T_USERS . ' WHERE uuid IN ('
            . implode(',', array_fill(0, count($k), '?')) . ')', $k) as $u) {
            $usersByUuid[strtolower((string) $u['uuid'])] = $u;
        }
    }

    // --- 항목 판정 ---
    $claims = [];   // "sid|u:id" / "sid|r:ref" — 같은 계획 안에서 한 사람 = 서비스당 하나
    $out    = [];
    $summary = ['ok' => 0, 'warn' => 0, 'invalid' => 0];
    foreach ($items as $k => $it) {
        $codes = [];
        $ext = db_one('SELECT * FROM ' . T_EXT_ACCOUNTS . ' WHERE service_id = ? AND external_id = ?',
            [$it['service_id'], $it['external_id']]);
        $targetId = null;
        if ($ext === null) {
            $codes[] = 'EXT_NOT_FOUND';
        } else {
            $state = (string) $ext['state'];
            if ($it['expect_version'] !== null && (int) $it['expect_version'] !== (int) $ext['row_version']) {
                $codes[] = 'EXT_CHANGED';
            }
            $items[$k]['expect_version'] = $it['expect_version'] !== null ? (int) $it['expect_version'] : (int) $ext['row_version'];
            if (!in_array($state, AL_TRANSITIONS[$it['op']]['from'], true)) {
                $codes[] = ($state === 'linked' && in_array($it['op'], ['link', 'create_link'], true))
                    ? 'EXT_ALREADY_LINKED' : 'BAD_TRANSITION';
            }
            if (in_array($it['op'], ['link', 'create_link'], true)) {
                // v1.10.0 §6-3 — 비상 표지 외부 계정 · 통합로그인 비상 계정은 연결 대상이 아니다(차단)
                if (!empty($ext['is_emergency'])) {
                    $codes[] = 'EMERGENCY_ACCOUNT';
                }
                if ($it['target_uuid'] !== null && is_emergency_user($usersByUuid[$it['target_uuid']] ?? null)) {
                    $codes[] = 'EMERGENCY_ACCOUNT';
                }
                $targetRank = 0;
                $targetUuid = null;
                if ($it['target_uuid'] !== null) {
                    $u = $usersByUuid[$it['target_uuid']] ?? null;
                    if ($u === null || (string) $u['status'] !== 'active') {
                        $codes[] = 'USER_NOT_ACTIVE';
                    }
                    if ($u !== null) {
                        $targetId   = (int) $u['id'];
                        $targetRank = rank_of(ROLE_RANK, global_role_of($u));
                        $targetUuid = strtolower((string) $u['uuid']);
                        $claim = $it['service_id'] . '|u:' . $targetId;
                        $taken = db_val('SELECT id FROM ' . T_EXT_ACCOUNTS . ' WHERE service_id = ? AND user_id = ? AND state = ?',
                            [$it['service_id'], $targetId, 'linked']);
                        if ($taken !== null || isset($claims[$claim])) {
                            $codes[] = 'USER_ALREADY_LINKED_SERVICE';
                        }
                        $claims[$claim] = true;
                    }
                } else {
                    $claim = $it['service_id'] . '|r:' . $it['target_ref'];
                    if (isset($claims[$claim])) {
                        $codes[] = 'USER_ALREADY_LINKED_SERVICE';
                    }
                    $claims[$claim] = true;
                    $nuCodes = $newUsers[$it['target_ref']]['codes'];
                    if ($it['op'] === 'create_link') {
                        $codes = array_merge($codes, $nuCodes);
                    } elseif ($nuCodes !== []) {
                        $codes[] = 'DEPENDENCY_FAILED';
                    }
                }
                if ($ext['bound_uuid'] !== null && strtolower((string) $ext['bound_uuid']) !== $targetUuid) {
                    $codes[] = 'SYSTEM_BOUND_OTHER';
                }
                // D-328 — 시스템 관리자 계정을 ulogin 관리자(rank ≥ 20)가 아닌 계정에 붙인다. 차단은 시스템 몫(S-5)
                if (al_ext_is_admin($ext['extra_json']) && $targetRank < 20) {
                    $codes[] = 'EXT_ROLE_ADMIN_LOW_RANK';
                }
            }
            if ($ext['gone_at'] !== null || (string) $ext['ext_status'] === 'deleted') {
                $codes[] = 'EXT_GONE';
            } elseif ((string) $ext['ext_status'] === 'disabled') {
                $codes[] = 'EXT_DISABLED';
            }
        }
        $codes   = alo_sort_codes($codes);
        $verdict = alo_codes_verdict($codes);
        $summary[$verdict]++;
        $items[$k]['target_user_id'] = $targetId;
        $items[$k]['codes']   = $codes;
        $items[$k]['verdict'] = $verdict;
        $out[] = ['seq' => $it['seq'], 'op' => $it['op'], 'service_id' => $it['service_id'],
            'external_id' => $it['external_id'], 'verdict' => $verdict, 'codes' => $codes];
    }

    // --- 기록(작업·항목 행만) ---
    $now  = now_utc();
    $exp  = at_utc(AL_PLAN_TTL);
    $uuid = gen_uuid4();
    $pj   = [];
    foreach ($newUsers as $nu) {
        $pj[] = ['ref' => $nu['ref'], 'username' => $nu['username'], 'email' => $nu['email'], 'name' => $nu['name']];
    }
    db_tx(function () use ($uuid, $adminId, $items, $pj, $retryOf, $now, $exp) {
        $jobId = db_insert(T_LINK_JOBS, [
            'job_uuid' => $uuid, 'kind' => 'plan', 'service_id' => null, 'sync_id' => null, 'status' => 'planned',
            'created_by' => $adminId, 'item_count' => count($items),
            'plan_json' => json_encode(['new_users' => $pj], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'retry_of' => $retryOf, 'expires_at' => $exp, 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ($items as $it) {
            db_insert(T_LINK_ITEMS, [
                'job_id' => $jobId, 'seq' => $it['seq'], 'op' => $it['op'], 'service_id' => $it['service_id'],
                'external_id' => $it['external_id'], 'target_user_id' => $it['target_user_id'],
                'target_ref' => $it['target_ref'], 'expect_version' => $it['expect_version'],
                'verdict' => $it['verdict'], 'status' => 'planned',
                'error_code' => $it['codes'] === [] ? null : substr($it['codes'][0], 0, 40),
                'created_at' => $now,
            ]);
        }
    });
    return ['plan_id' => $uuid, 'expires_at' => iso($exp), 'summary' => $summary, 'items' => $out, 'new_users' => $nuOut];
}

// =====================================================================
// commit
// =====================================================================

/**
 * 항목 하나 = 트랜잭션 하나. 전이는 조건부 UPDATE(`row_version = ? AND state IN (…)`) 한 문장이 판정하고,
 * 「한 사람 = 서비스당 외부 계정 하나」는 UNIQUE(uq_ext_accounts_user)가 판정한다 — 코드로 다시 보지 않는다.
 * @return array 결과 원소 {seq,status,error_code,user,setup_url,setup_expires_at}
 */
function alo_exec_item(array $job, array $item, int $adminId, array $planUsers): array
{
    $op  = (string) $item['op'];
    $sid = (string) $item['service_id'];
    $res = ['seq' => (int) $item['seq'], 'status' => 'done', 'error_code' => null, 'user' => null,
        'setup_url' => null, 'setup_expires_at' => null];
    try {
        $r = db_tx(function () use ($job, $item, $op, $sid, $adminId, $planUsers) {
            $now = now_utc();
            $ext = db_one('SELECT * FROM ' . T_EXT_ACCOUNTS . ' WHERE service_id = ? AND external_id = ?' . sql_for_update(),
                [$sid, (string) $item['external_id']]);
            if ($ext === null) {
                throw new AlItemFail('EXT_NOT_FOUND');
            }
            $before = ['state' => (string) $ext['state'], 'service_id' => $sid, 'external_id' => (string) $ext['external_id'],
                'uuid' => null, 'row_version' => (int) $ext['row_version']];
            if ($ext['user_id'] !== null) {
                $before['uuid'] = (string) db_val('SELECT uuid FROM ' . T_USERS . ' WHERE id = ?', [$ext['user_id']]);
            }
            $tr     = AL_TRANSITIONS[$op];
            $fromPh = implode(', ', array_fill(0, count($tr['from']), '?'));
            $user   = null;
            $setup  = null;
            $action = $op === 'create_link' ? 'create' : $op;

            if ($op === 'create_link') {
                $nu = $planUsers[(string) $item['target_ref']] ?? null;
                if ($nu === null || $nu['username'] === null || $nu['username'] === ''
                    || preg_match('/^[a-z0-9_]{4,20}$/', (string) $nu['username']) !== 1) {
                    throw new AlItemFail('USERNAME_INVALID');
                }
                if ($nu['email'] === null || $nu['email'] === '') {
                    throw new AlItemFail('EMAIL_REQUIRED');
                }
                try {
                    // 이관 계정 — 비밀번호 비이관(아무도 모르는 값) · 등급·역할 기본값 고정 · active(pending 은
                    // 이메일 인증으로만 풀려 설정 뒤에도 로그인이 막힌다 — 설계 D절)
                    $newId = db_insert(T_USERS, [
                        'uuid'                => gen_uuid4(),
                        'username'            => (string) $nu['username'],
                        'email'               => (string) $nu['email'],
                        'password_hash'       => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                        'password_changed_at' => $now,
                        'name'                => $nu['name'],
                        'status'              => 'active',
                        'account_level'       => 'basic',
                        'role'                => 'user',
                        'billing_tier'        => 'free',
                        'meta_json'           => json_encode(['setup_pending' => true,
                            'imported_from' => ['service_id' => $sid, 'external_id' => (string) $ext['external_id']]],
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'created_at'          => $now,
                        'updated_at'          => $now,
                    ]);
                } catch (Throwable $e) {
                    if (db_is_duplicate($e)) {
                        $dupU = db_val('SELECT id FROM ' . T_USERS . ' WHERE username = ?', [(string) $nu['username']]) !== null;
                        throw new AlItemFail($dupU ? 'DUPLICATE_USERNAME' : 'DUPLICATE_EMAIL');
                    }
                    throw $e;
                }
                $user  = db_one('SELECT id, uuid, username, status FROM ' . T_USERS . ' WHERE id = ?', [$newId]);
                $setup = al_issue_setup($newId);
            } elseif ($op === 'link') {
                $uid = $item['target_user_id'] === null ? null : (int) $item['target_user_id'];
                if ($item['target_ref'] !== null) {
                    $dep = db_one('SELECT status, result_user_id FROM ' . T_LINK_ITEMS . ' WHERE job_id = ? AND op = ? AND target_ref = ?',
                        [(int) $job['id'], 'create_link', (string) $item['target_ref']]);
                    if ($dep === null || (string) $dep['status'] !== 'done' || $dep['result_user_id'] === null) {
                        throw new AlItemFail('DEPENDENCY_FAILED', 'skipped');
                    }
                    $uid = (int) $dep['result_user_id'];
                }
                $user = $uid === null ? null : db_one('SELECT id, uuid, username, status FROM ' . T_USERS . ' WHERE id = ?', [$uid]);
                if ($user === null || (string) $user['status'] !== 'active') {
                    throw new AlItemFail('USER_NOT_ACTIVE');
                }
            }

            $params = [];
            if ($op === 'create_link' || $op === 'link') {
                $sql = 'UPDATE ' . T_EXT_ACCOUNTS . ' SET state = ?, user_id = ?, link_origin = ?, linked_at = ?, linked_by = ?,'
                    . ' row_version = row_version + 1, updated_at = ?';
                $params = ['linked', (int) $user['id'], $op === 'create_link' ? 'created' : 'attached', $now, $adminId, $now];
            } elseif ($op === 'unlink') {
                // last_user_id 는 값으로 묶는다 — MySQL 은 SET 을 왼쪽부터 평가해 `user_id = NULL` 뒤의
                // `last_user_id = user_id` 가 NULL 을 읽는다(SQLite 는 옛 값). 방언 차이를 문장에 두지 않는다.
                $sql = 'UPDATE ' . T_EXT_ACCOUNTS . ' SET state = ?, last_user_id = ?, user_id = NULL,'
                    . ' row_version = row_version + 1, updated_at = ?';
                $params = ['unlinked', $ext['user_id'] === null ? null : (int) $ext['user_id'], $now];
            } else {
                $sql = 'UPDATE ' . T_EXT_ACCOUNTS . ' SET state = ?, row_version = row_version + 1, updated_at = ?';
                $params = [$tr['to'], $now];
            }
            // 🔴 `row_version = ?` 와 `state IN (…)` 을 지우지 마라 — 낡은 미리보기로 주인이 바뀐다(T1 변이 지점)
            $sql .= ' WHERE id = ? AND row_version = ? AND state IN (' . $fromPh . ')';
            $params = array_merge($params, [(int) $ext['id'], (int) $item['expect_version']], $tr['from']);
            try {
                $n = db_exec($sql, $params);
            } catch (Throwable $e) {
                if (db_is_duplicate($e)) {
                    throw new AlItemFail('USER_ALREADY_LINKED_SERVICE');
                }
                throw $e;
            }
            if ($n !== 1) {
                $now2 = db_val('SELECT state FROM ' . T_EXT_ACCOUNTS . ' WHERE id = ?', [(int) $ext['id']]);
                throw new AlItemFail(((string) $now2 === 'linked' && $op !== 'unlink') ? 'EXT_ALREADY_LINKED' : 'EXT_CHANGED');
            }
            if ($op === 'unlink' && $ext['user_id'] !== null) {
                // 해제 뒤에도 기존 Bearer 로 그 로컬 계정을 계속 쓰는 것을 막는다(설계 E절 · T8 변이 지점)
                al_revoke_link_sessions((int) $ext['user_id'], $sid);
                em_revoke_user((int) $ext['user_id'], 'unlinked', $sid);   // v1.9.1 - 비상 확인값 폐기
                $user = db_one('SELECT id, uuid, username, status FROM ' . T_USERS . ' WHERE id = ?', [(int) $ext['user_id']]);
            }
            db_exec('UPDATE ' . T_LINK_ITEMS . ' SET status = ?, error_code = NULL, result_user_id = ?, done_at = ? WHERE id = ?',
                ['done', $user === null ? null : (int) $user['id'], $now, (int) $item['id']]);
            $after = ['state' => $tr['to'], 'service_id' => $sid, 'external_id' => (string) $ext['external_id'],
                'uuid' => ($op === 'unlink' || $user === null) ? null : (string) $user['uuid']];
            if ($op === 'create_link') {
                $after['created_username'] = (string) $user['username'];
                $after['setup_link_issued'] = true;     // 링크 자체는 남기지 않는다(D-324)
            }
            log_admin($adminId, 'account_link.' . $action, 'link_item', (string) $item['id'], $before, $after, null);
            return ['user' => $user, 'setup' => $setup];
        });
        if ($r['user'] !== null) {
            $res['user'] = ['uuid' => (string) $r['user']['uuid'], 'username' => (string) $r['user']['username']];
        }
        if ($r['setup'] !== null) {
            $res['setup_url']        = $r['setup']['url'];
            $res['setup_expires_at'] = $r['setup']['expires_at'];
        }
        return $res;
    } catch (AlItemFail $e) {
        $code   = $e->itemCode;
        $status = $e->itemStatus;
    } catch (Throwable $e) {
        if (db_is_lock_conflict($e)) {
            $code = 'EXT_CHANGED';
        } else {
            error_log('[ulogin] account_link commit item ' . (int) $item['id'] . ': ' . get_class($e) . ': ' . $e->getMessage());
            $code = 'SERVER_ERROR';
        }
        $status = 'failed';
    }
    db_exec('UPDATE ' . T_LINK_ITEMS . ' SET status = ?, error_code = ?, done_at = ? WHERE id = ? AND status = ?',
        [$status, substr($code, 0, 40), now_utc(), (int) $item['id'], 'planned']);
    $res['status']     = $status;
    $res['error_code'] = $code;
    return $res;
}

function alo_totals(int $jobId): array
{
    $t = ['done' => 0, 'failed' => 0, 'skipped' => 0];
    foreach (db_all('SELECT status, COUNT(*) AS n FROM ' . T_LINK_ITEMS . ' WHERE job_id = ? GROUP BY status', [$jobId]) as $x) {
        if (isset($t[(string) $x['status']])) {
            $t[(string) $x['status']] = (int) $x['n'];
        }
    }
    return $t;
}

/**
 * 확정. 한 요청에 최대 25건(create_link 먼저, 그다음 seq 순). **같은 seqs 를 매 호출 그대로 보내라**
 * (또는 생략) — 서버가 아직 planned 인 것 25건을 집는다. remaining=0 이 되면 선택되지 않은 항목은
 * skipped(NOT_SELECTED)로 닫고 작업이 done(전부 성공) / partial 로 끝난다.
 */
function alo_commit(array $in, int $adminId): array
{
    $job = alo_load_plan(in_raw($in, 'plan_id'), $adminId);
    $jobId = (int) $job['id'];
    $seqRaw = in_raw($in, 'seqs');
    $seqs = null;
    if ($seqRaw !== null) {
        if (!is_array($seqRaw) || count($seqRaw) > AL_PLAN_MAX_ITEMS) {
            v_fail('seqs', 'seqs는 정수 배열이어야 합니다.');
        }
        $seqs = [];
        foreach ($seqRaw as $s) {
            if (!is_int($s) && !(is_string($s) && ctype_digit($s))) {
                v_fail('seqs', 'seqs는 정수 배열이어야 합니다.');
            }
            $seqs[(int) $s] = true;
        }
    }
    $all = db_all('SELECT * FROM ' . T_LINK_ITEMS . ' WHERE job_id = ? ORDER BY seq ASC', [$jobId]);
    $bySeq = [];
    foreach ($all as $r) {
        $bySeq[(int) $r['seq']] = $r;
    }
    if ($seqs !== null) {
        foreach (array_keys($seqs) as $s) {
            if (!isset($bySeq[$s])) {
                v_fail('seqs', '계획에 없는 seq 입니다: ' . $s);
            }
        }
    }
    $now = now_utc();
    // 미리보기 invalid 를 명시로 고른 경우 — 실행하지 않고 그 코드로 실패 처리한다(미리보기와 같은 판정).
    $selected = [];
    foreach ($all as $r) {
        if ((string) $r['status'] !== 'planned') {
            continue;
        }
        $picked = $seqs === null ? ((string) $r['verdict'] !== 'invalid') : isset($seqs[(int) $r['seq']]);
        if (!$picked) {
            continue;
        }
        if ((string) $r['verdict'] === 'invalid') {
            db_exec('UPDATE ' . T_LINK_ITEMS . ' SET status = ?, done_at = ? WHERE id = ? AND status = ?',
                ['failed', $now, (int) $r['id'], 'planned']);
            continue;
        }
        $selected[] = $r;
    }
    usort($selected, function ($a, $b) {
        $pa = (string) $a['op'] === 'create_link' ? 0 : 1;
        $pb = (string) $b['op'] === 'create_link' ? 0 : 1;
        if ($pa !== $pb) {
            return $pa - $pb;
        }
        return (int) $a['seq'] - (int) $b['seq'];
    });
    db_exec('UPDATE ' . T_LINK_JOBS . ' SET status = ?, expires_at = ?, updated_at = ? WHERE id = ?',
        ['running', at_utc(AL_PLAN_TTL), $now, $jobId]);

    $planUsers = [];
    foreach ((array) (json_col($job['plan_json'])['new_users'] ?? []) as $nu) {
        if (is_array($nu) && isset($nu['ref'])) {
            $planUsers[(string) $nu['ref']] = $nu;
        }
    }
    $batch   = array_slice($selected, 0, AL_COMMIT_CHUNK);
    $results = [];
    foreach ($batch as $item) {
        $results[] = alo_exec_item($job, $item, $adminId, $planUsers);
    }
    $remaining = count($selected) - count($batch);

    $totals = alo_totals($jobId);
    $status = 'running';
    if ($remaining === 0) {
        db_exec('UPDATE ' . T_LINK_ITEMS . ' SET status = ?, error_code = ?, done_at = ? WHERE job_id = ? AND status = ?',
            ['skipped', 'NOT_SELECTED', now_utc(), $jobId, 'planned']);
        $totals = alo_totals($jobId);
        $real = (int) db_val('SELECT COUNT(*) FROM ' . T_LINK_ITEMS . ' WHERE job_id = ? AND (status = ? OR (status = ?'
            . ' AND (error_code IS NULL OR error_code <> ?)))', [$jobId, 'failed', 'skipped', 'NOT_SELECTED']);
        $status = $real === 0 ? 'done' : 'partial';
    }
    db_exec('UPDATE ' . T_LINK_JOBS . ' SET status = ?, ok_count = ?, fail_count = ?, updated_at = ?, finished_at = ? WHERE id = ?',
        [$status, $totals['done'], $totals['failed'], now_utc(), $remaining === 0 ? now_utc() : null, $jobId]);

    return ['job_id' => (string) $job['job_uuid'], 'status' => $status, 'processed' => count($results),
        'remaining' => $remaining, 'results' => $results, 'totals' => $totals];
}

if (defined('ULOGIN_OPS_LIB')) {
    return;
}

// =====================================================================
// 분기
// =====================================================================

if ($action === 'plan') {
    $r = alo_plan($in, $adminId);
    json_out([
        'plan_id'    => $r['plan_id'],
        'expires_at' => $r['expires_at'],
        'summary'    => $r['summary'],
        'items'      => $r['items'],
        'new_users'  => $r['new_users'],
    ]);
}

if ($action === 'commit') {
    $r = alo_commit($in, $adminId);
    json_out([
        'job_id'    => $r['job_id'],
        'status'    => $r['status'],
        'processed' => $r['processed'],
        'remaining' => $r['remaining'],
        'results'   => $r['results'],
        'totals'    => $r['totals'],
    ]);
}

if ($action === 'cancel') {
    $job = alo_load_plan(in_raw($in, 'plan_id'), $adminId);
    $now = now_utc();
    db_tx(function () use ($job, $now) {
        db_exec('UPDATE ' . T_LINK_ITEMS . ' SET status = ?, error_code = ?, done_at = ? WHERE job_id = ? AND status = ?',
            ['skipped', 'CANCELLED', $now, (int) $job['id'], 'planned']);
        db_exec('UPDATE ' . T_LINK_JOBS . ' SET status = ?, updated_at = ?, finished_at = ? WHERE id = ?',
            ['cancelled', $now, $now, (int) $job['id']]);
    });
    log_admin($adminId, 'account_link.cancel', 'link_job', (string) $job['job_uuid'], ['status' => (string) $job['status']],
        ['status' => 'cancelled'], null);
    json_out(['plan_id' => (string) $job['job_uuid'], 'status' => 'cancelled']);
}

if ($action === 'setup_link') {
    $uuid = in_raw($in, 'uuid');
    if (!al_is_uuid($uuid)) {
        throw new AppError('VALIDATION_FAILED', 400, ['field' => 'uuid', 'message' => '회원 식별자 형식이 올바르지 않습니다.']);
    }
    $u = db_one('SELECT * FROM ' . T_USERS . ' WHERE uuid = ?', [strtolower((string) $uuid)]);
    if ($u === null) {
        throw new AppError('NOT_FOUND', 404, ['field' => 'uuid']);
    }
    // 이미 비밀번호를 정한 계정에 관리자가 링크를 만드는 것은 user_status.php reset-password 의 몫이다(G-6).
    if (!al_setup_pending($u) || (string) $u['status'] !== 'active') {
        throw new AppError('CONFLICT', 409, ['field' => 'uuid']);
    }
    $r = db_tx(function () use ($u) {
        $inv = db_exec('UPDATE ' . T_PW_RESETS . ' SET used_at = ? WHERE user_id = ? AND purpose = ? AND used_at IS NULL',
            [now_utc(), (int) $u['id'], 'setup']);
        return ['invalidated' => $inv, 'setup' => al_issue_setup((int) $u['id'])];
    });
    log_admin($adminId, 'account_link.setup_link', 'user', (string) $u['uuid'], null,
        ['setup_link_issued' => true, 'invalidated' => $r['invalidated']], null);
    json_out([
        'uuid'             => (string) $u['uuid'],
        'setup_url'        => $r['setup']['url'],
        'setup_expires_at' => $r['setup']['expires_at'],
        'invalidated'      => $r['invalidated'],
    ]);
}

if ($action === 'sync_request') {
    $sid = (string) v_string($in, 'service_id', 1, 64, true);
    admin_service_id_reject_former($sid);   // v1.8.6 — 관리자 API 는 옛 id 를 받지 않는다(설계 104 B-2)
    if (al_service_row($sid) === null) {
        throw new AppError('VALIDATION_FAILED', 400, ['field' => 'service_id', 'message' => '없는 서비스입니다.']);
    }
    $now = now_utc();
    $at  = db_tx(function () use ($sid, $adminId, $now) {
        $cur = db_val('SELECT created_at FROM ' . T_LINK_JOBS . ' WHERE kind = ? AND service_id = ? AND status = ?'
            . sql_for_update(), ['sync_request', $sid, 'requested']);
        if ($cur !== null) {
            return (string) $cur;   // 이미 요청이 걸려 있다 — 하나만 둔다
        }
        db_insert(T_LINK_JOBS, [
            'job_uuid' => gen_uuid4(), 'kind' => 'sync_request', 'service_id' => $sid, 'sync_id' => null,
            'status' => 'requested', 'created_by' => $adminId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return $now;
    });
    log_admin($adminId, 'account_link.sync_request', 'service', $sid, null, ['sync_requested_at' => iso($at)], null);
    json_out(['service_id' => $sid, 'sync_requested_at' => iso($at)]);
}

// import — 파일 가져오기(H-4). S-2 본문을 `file` 에 그대로 싣는다. service_id 는 관리자가 고른 값이 정본이고,
// 파일 안의 service_id 가 다르면 거절한다(다른 시스템 파일을 잘못 올리는 사고). 파일의 service_secret 은 읽지 않는다.
$rawLen = body_raw_bytes();
if ($rawLen === null) {
    $rawLen = strlen((string) json_encode($in, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
if ($rawLen > AL_FILE_MAX_BYTES + 4096) {
    throw new AppError('VALIDATION_FAILED', 400, ['field' => 'file', 'message' => '파일이 너무 큽니다(1MB 이하).']);
}
$sid = (string) v_string($in, 'service_id', 1, 64, true);
admin_service_id_reject_former($sid);   // v1.8.6 — 관리자 API 는 옛 id 를 받지 않는다(설계 104 B-2)
$svc = al_service_row($sid);
if ($svc === null) {
    throw new AppError('VALIDATION_FAILED', 400, ['field' => 'service_id', 'message' => '없는 서비스입니다.']);
}
$file = in_raw($in, 'file');
if (!is_array($file)) {
    throw new AppError('VALIDATION_FAILED', 400, ['field' => 'file', 'message' => '파일 내용(S-2 형식 JSON 객체)이 필요합니다.']);
}
// v1.8.6 — 파일은 시스템이 만든 내보내기라 개명 전 id 일 수 있다. 비교 전에 푼다(설계 104 B-2).
if (isset($file['service_id']) && is_string($file['service_id'])) {
    $fileSid = service_id_resolve($file['service_id']);
    if ($fileSid !== null) {
        $file['service_id'] = $fileSid['canonical'];
    }
}
if (isset($file['service_id']) && $file['service_id'] !== $sid) {
    throw new AppError('VALIDATION_FAILED', 400, ['field' => 'service_id', 'message' => '파일의 service_id가 고른 시스템과 다릅니다.']);
}
$p = al_validate_payload($file, true);
$r = al_ingest($svc, $p, $adminId);
log_admin($adminId, 'account_link.import', 'service', $sid, null, [
    'sync_id' => $r['sync_id'], 'page' => $r['page'], 'accepted' => $r['accepted'], 'created' => $r['created'],
    'updated' => $r['updated'], 'unchanged' => $r['unchanged'], 'duplicate' => $r['duplicate'],
], null);
json_out([
    'sync_id'        => $r['sync_id'],
    'page'           => $r['page'],
    'accepted'       => $r['accepted'],
    'created'        => $r['created'],
    'updated'        => $r['updated'],
    'unchanged'      => $r['unchanged'],
    'duplicate'      => $r['duplicate'],
    'sync_requested' => $r['sync_requested'],
]);
