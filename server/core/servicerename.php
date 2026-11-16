<?php
declare(strict_types=1);

/**
 * core/servicerename.php — 서비스 id 개명 · 되돌리기 · 별칭 종료 (v1.8.6)
 * 담당 설계: `_workspace/104_architect_v186_service_id_rename.md` B-1 · C-4 · E절 · 리더 판정 D-341
 *
 * 부르는 곳: `tools/register_services.php --rename= / --revert= / --end-alias=` 하나.
 * 시험(`tests/tier3_live/t_85_service_rename.php`)은 CLI 없이 이 함수를 직접 부른다.
 * **웹 엔드포인트에서 부르지 않는다** — 9개 시스템이 쓰는 계약 키를 바꾸는 일이라 사람이 CLI 로만 한다.
 *
 * 원자성: **서비스마다 한 트랜잭션**(db_tx — SQLite BEGIN IMMEDIATE · InnoDB DML). DDL 이 없어
 * MySQL 에서도 원자적이다. 중간에 실패하면 11개 표 전부 개명 전 그대로다.
 * 과거 감사 로그(admin_logs)는 고치지 않는다(불변 기록). 개명 사실 1행을 더한다.
 * rate_limits 버킷 문자열은 고치지 않는다 — 창이 15분 이하라 새 버킷으로 자연히 옮겨 간다.
 */

if (!defined('ULOGIN_BOOTSTRAPPED')) {
    header_remove('X-Powered-By');
    http_response_code(404);
    exit;
}

/** 개명 도구가 멈춘 사유. kind = precond(전제, DB 무변경) | refused(판정 거절, DB 무변경) */
class ServiceRenameError extends RuntimeException
{
    /** @var string */
    public $kind;
    /** @var array */
    public $detail;

    public function __construct(string $kind, string $message, array $detail = [])
    {
        parent::__construct($message);
        $this->kind   = $kind;
        $this->detail = $detail;
    }
}

/** service_id 를 가진 표 11개(설계 B-1). **services 가 첫째다** — 실패 주입 순번이 이 순서를 따른다. */
function service_rename_tables(): array
{
    return [T_SERVICES, T_USER_SERVICES, T_SECRETS, T_SESSIONS, T_AUTH_CODES, T_LOGIN_LOGS,
            T_POINT_LOGS, T_SECRET_ACCESS, T_EXT_ACCOUNTS, T_LINK_JOBS, T_LINK_ITEMS,
            // v1.10.0 (설계 127 §1 연쇄 처리) — 빠뜨리면 개명 뒤 그룹 권한이 조용히 사라진다
            T_ACCESS_GROUP_SERVICES];
}

/** 전제: 0006 컬럼 · 0005 표. 하나라도 없으면 precond. **건너뛰지 않는다** — 조용한 0건 금지. */
function service_rename_preflight(): void
{
    $miss = [];
    foreach ([[T_SERVICES, 'former_service_id'], [T_SERVICES, 'former_until'], [T_SERVICES, 'former_last_used_at'],
              [T_SESSIONS, 'client_service_id']] as $c) {
        if (!db_column_exists($c[0], $c[1])) {
            $miss[] = $c[0] . '.' . $c[1];
        }
    }
    foreach (service_rename_tables() as $t) {
        if (!db_table_exists($t)) {
            $miss[] = $t;
        }
    }
    if ($miss !== []) {
        throw new ServiceRenameError('precond', '마이그레이션이 덜 적용됐습니다(0005·0006 필요). 없음: ' . implode(', ', $miss)
            . ' — php server/migrate.php apply 를 먼저 실행해야 합니다.');
    }
}

/** 시험 전용 실패 주입 — CLI 에서 환경변수가 있을 때만 읽는다. 웹 요청은 이 값을 볼 수 없다. */
function service_rename_fail_after(): int
{
    if (PHP_SAPI !== 'cli') {
        return 0;
    }
    $v = getenv('ULOGIN_TEST_RENAME_FAIL_AFTER');
    return (is_string($v) && ctype_digit($v)) ? (int) $v : 0;
}

/** 표마다 `service_id = ?` 건수. */
function service_rename_counts(string $id): array
{
    $out = [];
    foreach (service_rename_tables() as $t) {
        $out[$t] = (int) db_val('SELECT COUNT(*) FROM ' . $t . ' WHERE service_id = ?', [$id]);
    }
    return $out;
}

/**
 * 표 11개 + users.meta_json 의 service_id 를 $from → $to 로 옮긴다. 트랜잭션 **안에서만** 부른다.
 * @return array{counts:array,meta_users:int}
 */
function service_rename_move(string $from, string $to, string $mode): array
{
    // 고아 행 — 지운 서비스의 로그 등. 자동으로 합치지 않는다(설계 E-2)
    $orphans = [];
    foreach (service_rename_counts($to) as $t => $n) {
        if ($n > 0) {
            $orphans[$t] = $n;
        }
    }
    if ($orphans !== []) {
        throw new ServiceRenameError('refused', '대상 id ' . $to . ' 를 이미 쓰는 행이 있어 멈췄습니다(합치지 않습니다).',
            ['orphans' => $orphans]);
    }

    $before = service_rename_counts($from);
    $failAt = service_rename_fail_after();
    $now    = now_utc();
    $i      = 0;
    foreach (service_rename_tables() as $t) {
        if ($t === T_SERVICES) {
            if ($mode === 'rename') {
                db_exec('UPDATE ' . $t . ' SET service_id = ?, former_service_id = ?, former_until = NULL,'
                    . ' former_last_used_at = NULL, updated_at = ? WHERE service_id = ?', [$to, $from, $now, $from]);
            } else {
                db_exec('UPDATE ' . $t . ' SET service_id = ?, former_service_id = NULL, former_until = NULL,'
                    . ' former_last_used_at = NULL, updated_at = ? WHERE service_id = ?', [$to, $now, $from]);
            }
        } elseif ($t === T_SESSIONS) {
            if ($mode === 'rename') {
                // 살아 있는 세션은 옛 id 로 발급된 것이다 — 응답 echo 가 계속 옛 id 를 되돌리게(설계 C-2)
                db_exec('UPDATE ' . $t . ' SET client_service_id = ? WHERE service_id = ? AND revoked_at IS NULL'
                    . ' AND client_service_id IS NULL', [$from, $from]);
                db_exec('UPDATE ' . $t . ' SET service_id = ? WHERE service_id = ?', [$to, $from]);
            } else {
                db_exec('UPDATE ' . $t . ' SET service_id = ?, client_service_id = NULL WHERE service_id = ?', [$to, $from]);
            }
        } else {
            db_exec('UPDATE ' . $t . ' SET service_id = ? WHERE service_id = ?', [$to, $from]);
        }
        $i++;
        if ($failAt > 0 && $i === $failAt) {
            throw new RuntimeException('test-injected failure after table #' . $i . ' (' . $t . ')');
        }
    }

    // users.meta_json.imported_from.service_id — DB JSON 함수는 방언이 갈려 PHP 로 고친다
    $meta = 0;
    $rows = db_all('SELECT id, meta_json FROM ' . T_USERS . ' WHERE meta_json LIKE ?', ['%imported_from%']);
    foreach ($rows as $r) {
        $j = json_decode((string) $r['meta_json'], true);
        if (!is_array($j) || !isset($j['imported_from']) || !is_array($j['imported_from'])
            || !isset($j['imported_from']['service_id']) || $j['imported_from']['service_id'] !== $from) {
            continue;
        }
        $j['imported_from']['service_id'] = $to;
        db_exec('UPDATE ' . T_USERS . ' SET meta_json = ? WHERE id = ?',
            [json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (int) $r['id']]);
        $meta++;
    }

    // 사후 대조 — 표별 건수가 그대로 옮겨 갔고 옛 id 가 0건인가. 다르면 롤백
    $after = service_rename_counts($to);
    $left  = service_rename_counts($from);
    foreach (service_rename_tables() as $t) {
        if ($after[$t] !== $before[$t] || $left[$t] !== 0) {
            throw new ServiceRenameError('refused', '사후 대조 실패 ' . $t . ': 전 ' . $before[$t] . ' · 후 ' . $after[$t]
                . ' · 남은 옛 id ' . $left[$t] . ' — 되돌렸습니다.');
        }
    }
    $counts = [];
    foreach ($before as $t => $n) {
        $counts[substr($t, strlen(DB_PREFIX))] = $n;
    }
    return ['counts' => $counts, 'meta_users' => $meta];
}

/** 감사 1행 — log_admin() 은 실패를 삼킨다. 개명은 감사 행까지 한 트랜잭션이어야 하므로 직접 넣는다. */
function service_rename_audit(int $adminId, string $action, string $to, array $before, array $after): int
{
    return db_insert(T_ADMIN_LOGS, [
        'admin_id'    => $adminId,
        'action'      => $action,
        'target_type' => 'service',
        'target_id'   => $to,
        'before_json' => json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'after_json'  => json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'memo'        => 'cli tools/register_services.php',
        'ip'          => client_ip(),
        'created_at'  => now_utc(),
    ]);
}

/**
 * 개명 $from → $to. 재실행 안전(이미 개명됐으면 무변경 'already').
 * @return array{status:string,from:string,to:string,counts:array,meta_users:int,audit_id:?int}
 * @throws ServiceRenameError
 */
function service_rename(string $from, string $to, int $adminId): array
{
    service_rename_preflight();
    if (preg_match('/^[a-z0-9][a-z0-9\-_]{2,63}$/', $to) !== 1 || $from === '' || $from === $to) {
        throw new ServiceRenameError('refused', '개명 쌍이 올바르지 않습니다: ' . $from . ' → ' . $to);
    }
    return db_tx(function () use ($from, $to, $adminId) {
        $f = db_one('SELECT * FROM ' . T_SERVICES . ' WHERE service_id = ?' . sql_for_update(), [$from]);
        $t = db_one('SELECT * FROM ' . T_SERVICES . ' WHERE service_id = ?' . sql_for_update(), [$to]);
        if ($f === null && $t !== null && (string) ($t['former_service_id'] ?? '') === $from) {
            return ['status' => 'already', 'from' => $from, 'to' => $to, 'counts' => [], 'meta_users' => 0, 'audit_id' => null];
        }
        if ($f === null) {
            throw new ServiceRenameError('refused', '개명할 서비스가 없습니다: ' . $from);
        }
        if ($t !== null) {
            throw new ServiceRenameError('refused', '두 id 가 모두 서비스로 있습니다: ' . $from . ' · ' . $to);
        }
        if (service_id_taken($to) !== null) {
            throw new ServiceRenameError('refused', $to . ' 는 다른 서비스의 옛 id 라 쓸 수 없습니다.');
        }
        if ((string) ($f['former_service_id'] ?? '') !== '') {
            throw new ServiceRenameError('refused', $from . ' 은 이미 ' . (string) $f['former_service_id']
                . ' 에서 개명된 서비스입니다. 연쇄 개명은 하지 않습니다(--revert 로 먼저 되돌려야 합니다).');
        }
        $m = service_rename_move($from, $to, 'rename');
        $aid = service_rename_audit($adminId, 'service.rename', $to, ['service_id' => $from],
            ['service_id' => $to, 'former_service_id' => $from, 'counts' => $m['counts'], 'meta_users' => $m['meta_users']]);
        return ['status' => 'renamed', 'from' => $from, 'to' => $to, 'counts' => $m['counts'],
                'meta_users' => $m['meta_users'], 'audit_id' => $aid];
    });
}

/**
 * 되돌리기 — 새 id $to 를 옛 id 로 돌린다. 개명 뒤 새로 생긴 행도 같이 돌아간다
 * (DB 사본 복원은 그 사이의 로그인·push 를 잃는다 — 사본 복원은 이 도구가 고장 났을 때의 최후 수단).
 */
function service_rename_revert(string $to, int $adminId): array
{
    service_rename_preflight();
    return db_tx(function () use ($to, $adminId) {
        $t = db_one('SELECT * FROM ' . T_SERVICES . ' WHERE service_id = ?' . sql_for_update(), [$to]);
        if ($t === null || (string) ($t['former_service_id'] ?? '') === '') {
            throw new ServiceRenameError('refused', '되돌릴 개명 기록이 없습니다: ' . $to);
        }
        $from = (string) $t['former_service_id'];
        $m = service_rename_move($to, $from, 'revert');
        $aid = service_rename_audit($adminId, 'service.rename_revert', $from, ['service_id' => $to, 'former_service_id' => $from],
            ['service_id' => $from, 'counts' => $m['counts'], 'meta_users' => $m['meta_users']]);
        return ['status' => 'reverted', 'from' => $to, 'to' => $from, 'counts' => $m['counts'],
                'meta_users' => $m['meta_users'], 'audit_id' => $aid];
    });
}

/** 별칭 종료 — 해석만 멈춘다. former_service_id 는 남긴다(재사용 금지는 계속, 설계 C-4). */
function service_alias_end(string $to, int $adminId): array
{
    service_rename_preflight();
    return db_tx(function () use ($to, $adminId) {
        $t = db_one('SELECT * FROM ' . T_SERVICES . ' WHERE service_id = ?' . sql_for_update(), [$to]);
        if ($t === null || (string) ($t['former_service_id'] ?? '') === '') {
            throw new ServiceRenameError('refused', '별칭이 있는 서비스가 아닙니다: ' . $to);
        }
        if ($t['former_until'] !== null && (string) $t['former_until'] !== '') {
            return ['status' => 'already', 'to' => $to, 'former_service_id' => (string) $t['former_service_id'], 'audit_id' => null];
        }
        $now = now_utc();
        db_exec('UPDATE ' . T_SERVICES . ' SET former_until = ?, updated_at = ? WHERE service_id = ?', [$now, $now, $to]);
        $aid = service_rename_audit($adminId, 'service.alias_end', $to, ['former_until' => null],
            ['former_service_id' => (string) $t['former_service_id'], 'former_until' => iso($now),
             'former_last_used_at' => iso($t['former_last_used_at'] ?? null)]);
        return ['status' => 'ended', 'to' => $to, 'former_service_id' => (string) $t['former_service_id'], 'audit_id' => $aid];
    });
}
