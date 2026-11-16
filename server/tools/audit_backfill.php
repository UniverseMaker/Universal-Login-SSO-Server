<?php
declare(strict_types=1);

// CLI 전용. 웹으로 열리면 아무것도 하지 않고 404. (server/.htaccess 도 tools/ 를 막는다)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * server/tools/audit_backfill.php — 기존 admin_logs 를 감사 체인(service_id 'ulogin')에 소급 적재 (v1.9.0, H-4)
 *
 *   php server/tools/audit_backfill.php            DRY-RUN — 몇 행이 들어갈지만 센다
 *   php server/tools/audit_backfill.php --apply    적재(admin_logs.id 순, 500행씩 커밋)
 *
 * · event_id 는 admin_logs.id 에서 결정적으로 만든다(log_admin 이중 기록과 같은 함수). 그래서 **다시 돌려도**
 *   이미 들어간 행은 UNIQUE 가 거절해 건너뛴다(멱등). v1.9.0 이 이중 기록한 행도 건너뛴다.
 * · reason='backfill', ts = 원래 created_at, ip = 원래 ip. 체인 순서는 적재 순서다 — 운영 배포 절차가
 *   0007 apply 직후·접합 전환 전에 돌리면 체인 맨 앞에 온다.
 * · 0007 미적용이면 멈춘다(exit 1). SECRET_ENC_KEY 가 없으면 체인 키가 없어 멈춘다(D-53).
 * 종료코드: 0 정상 · 1 실패 · 2 인자
 */

require_once __DIR__ . '/../core/bootstrap.php';

$apply = false;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--apply') {
        $apply = true;
    } else {
        fwrite(STDERR, "알 수 없는 인자: " . substr($a, 0, 40) . "\n");
        exit(2);
    }
}
if (!db_table_exists(T_PLAT_AUDIT)) {
    fwrite(STDERR, "plat_audit 표가 없습니다. 먼저 php server/migrate.php apply 로 0007 을 적용하세요.\n");
    exit(1);
}
audit_chain_key();   // 키가 없으면 여기서 예외 → exit 255

$have = [];
foreach (db_all('SELECT event_id FROM ' . T_PLAT_AUDIT . ' WHERE service_id = ?', ['ulogin']) as $r) {
    $have[(string) $r['event_id']] = true;
}
$todo = 0;
$done = 0;
$skip = 0;
$last = 0;
fwrite(STDOUT, $apply ? "[APPLY] 감사 체인에 적재합니다.\n" : "[DRY-RUN] 바꾸지 않습니다. 실행은 --apply.\n");
while (true) {
    $rows = db_all('SELECT * FROM ' . T_ADMIN_LOGS . ' WHERE id > ? ORDER BY id ASC LIMIT 500', [$last]);
    if ($rows === []) {
        break;
    }
    foreach ($rows as $r) {
        $last = (int) $r['id'];
        $h = hash('sha256', 'ulogin-admin-log:' . $last);
        $eid = substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-a' . substr($h, 17, 3) . '-' . substr($h, 20, 12);
        if (isset($have[$eid])) {
            $skip++;
            continue;
        }
        $todo++;
        if (!$apply) {
            continue;
        }
        $before = $r['before_json'] === null ? null : json_decode((string) $r['before_json'], true);
        $after  = $r['after_json'] === null ? null : json_decode((string) $r['after_json'], true);
        audit_log_admin_row($last, (int) $r['admin_id'], (string) $r['action'], (string) $r['target_type'],
            $r['target_id'] === null ? null : (string) $r['target_id'],
            is_array($before) ? $before : null, is_array($after) ? $after : null, null,
            (string) $r['created_at'], (string) $r['ip'], 'backfill');
        $done++;
    }
}
$v = audit_verify(1, AUDIT_VERIFY_MAX);
fwrite(STDOUT, 'admin_logs 대상 ' . $todo . '행 · 이미 있음 ' . $skip . '행 · 적재 ' . $done . "행\n");
fwrite(STDOUT, '체인 검증(앞 5,000행): ' . ($v['ok'] ? 'ok' : 'FAIL at seq ' . (int) $v['first_bad_seq']) . ' (checked ' . $v['checked'] . ")\n");
exit($v['ok'] ? 0 : 1);
