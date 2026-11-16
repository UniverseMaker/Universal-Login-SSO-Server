<?php
declare(strict_types=1);

// CLI 전용. 웹으로 열리면 아무것도 하지 않고 404.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * server/tools/unify_admin.php — 관리자 계정 통합 (v1.8.11, D-352)
 *
 *   php server/tools/unify_admin.php --rename-user=admin:opsuser [--apply]
 *   php server/tools/unify_admin.php --link-username=opsuser --to=opsuser [--apply]
 *
 * 기본은 dry-run(DB 무변경). `--apply` 로만 쓴다. 두 옵션을 함께 줄 수도 있다(개명 -> 연결 순서).
 *  --rename-user=옛:새  아이디 변경. 형식·충돌 검사, 감사 로그 user.rename. 세션·비밀번호는 건드리지 않는다.
 *  --link-username=X --to=Y  모든 서비스의 외부 계정 중 username 이 X 이고 state 가 new/ignored/unlinked 인 것을
 *                       통합로그인 사용자 Y 에 연결한다. 웹 관리자 화면과 같은 함수(api/admin/account_link_ops.php 의
 *                       alo_plan·alo_commit)를 쓰므로 link_events 사건이 그대로 나간다(시스템이 받아 이관).
 *                       --service=<id> 를 주면 그 서비스(정규 id·별칭 모두)의 계정만 대상. 없으면 전 서비스.
 *                       판정이 ok 가 아닌 항목(삭제·비활성·다른 사용자에 묶임 등)은 건너뛴다. 이미 연결된 것도 건너뛴다 -> 재실행 안전.
 * 감사 로그의 행위자는 대상 사용자(Y) 본인 id 다(admin_logs.admin_id 는 사용자 FK). CLI 실행은 memo 로 구분한다.
 * 비밀값(비밀번호·시크릿·링크)은 읽지도 출력하지도 않는다.
 * 종료코드 0 정상 / 1 일부 실패 / 2 인자 / 3 전제 불충족(대상 사용자 없음·충돌)
 */

define('ULOGIN_OPS_LIB', 1);
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/accountlink.php';
require_once __DIR__ . '/../api/admin/account_link_ops.php';

function ua_say(string $s): void
{
    fwrite(STDOUT, $s . "\n");
}

function ua_die(int $code, string $s): void
{
    fwrite(STDERR, $s . "\n");
    exit($code);
}

$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/', $a, $m) !== 1) {
        ua_die(2, '알 수 없는 인자: ' . substr($a, 0, 40));
    }
    $opt[$m[1]] = isset($m[2]) ? $m[2] : '1';
}
foreach (array_keys($opt) as $k) {
    if (!in_array($k, ['rename-user', 'link-username', 'to', 'service', 'apply'], true)) {
        ua_die(2, '알 수 없는 옵션: --' . $k);
    }
}
$apply = isset($opt['apply']);
if (!isset($opt['rename-user']) && !isset($opt['link-username'])) {
    ua_die(2, '--rename-user=옛:새 또는 --link-username=X --to=Y 가 필요합니다.');
}
ua_say($apply ? '[APPLY] DB 를 바꿉니다.' : '[DRY-RUN] DB 를 바꾸지 않습니다. 실행은 --apply.');

$exit = 0;

// ---- 1) 아이디 변경 ------------------------------------------------
if (isset($opt['rename-user'])) {
    $pair = explode(':', (string) $opt['rename-user']);
    if (count($pair) !== 2) {
        ua_die(2, '--rename-user 는 옛:새 형식입니다.');
    }
    $old = normalize_username($pair[0]);
    $new = normalize_username($pair[1]);
    if (preg_match('/^[a-z0-9_]{4,20}$/', $new) !== 1) {
        ua_die(2, '새 아이디 형식이 올바르지 않습니다(소문자·숫자·_ 4~20자).');
    }
    $u = db_one('SELECT id, uuid, username FROM ' . T_USERS . ' WHERE username = ?', [$old]);
    if ($u === null) {
        if (db_val('SELECT id FROM ' . T_USERS . ' WHERE username = ?', [$new]) !== null) {
            ua_say('rename: 건너뜀 - 이미 ' . $new . ' 입니다(' . $old . ' 없음).');
        } else {
            ua_die(3, 'rename: 사용자 ' . $old . ' 가 없습니다.');
        }
    } elseif ($old === $new) {
        ua_say('rename: 건너뜀 - 같은 아이디입니다.');
    } elseif (db_val('SELECT id FROM ' . T_USERS . ' WHERE username = ?', [$new]) !== null) {
        ua_die(3, 'rename: 충돌 - ' . $new . ' 가 이미 다른 사용자에게 있습니다. 아무것도 바꾸지 않았습니다.');
    } elseif (!$apply) {
        ua_say('rename: 대상 #' . (int) $u['id'] . ' ' . $old . ' -> ' . $new . ' (dry-run)');
    } else {
        db_tx(function () use ($u, $old, $new) {
            $n = db_exec('UPDATE ' . T_USERS . ' SET username = ?, updated_at = ? WHERE id = ? AND username = ?',
                [$new, now_utc(), (int) $u['id'], $old]);
            if ($n !== 1) {
                throw new RuntimeException('rename: 갱신 행이 1이 아닙니다.');
            }
            log_admin((int) $u['id'], 'user.rename', 'user', (string) $u['uuid'],
                ['username' => $old], ['username' => $new], 'cli unify_admin');
        });
        ua_say('rename: 완료 #' . (int) $u['id'] . ' ' . $old . ' -> ' . $new);
    }
}

// ---- 2) 외부 계정 연결 ---------------------------------------------
if (isset($opt['link-username'])) {
    $from = normalize_username((string) $opt['link-username']);
    $to   = normalize_username((string) ($opt['to'] ?? ''));
    if ($from === '' || $to === '') {
        ua_die(2, '--link-username 은 --to 와 함께 씁니다.');
    }
    $tu = db_one('SELECT id, uuid, username, status, account_kind FROM ' . T_USERS . ' WHERE username = ?', [$to]);
    if ($tu === null || (string) $tu['status'] !== 'active') {
        ua_die(3, 'link: 대상 사용자 ' . $to . ' 가 없거나 활성이 아닙니다(--rename-user 를 먼저 --apply 했는지 확인).');
    }
    if (is_emergency_user($tu)) {
        // v1.10.0 (설계 127 §6-3 ②) — 통합로그인 비상 계정은 어떤 연결의 대상도 되지 않는다
        ua_die(3, 'link: 대상 ' . $to . ' 는 비상 관리자 계정입니다. 연결하지 않습니다. 아무것도 바꾸지 않았습니다.');
    }
    $tid  = (int) $tu['id'];
    $svcSql = '';
    $svcArg = [$from];
    if (isset($opt['service'])) {
        $res = service_id_resolve((string) $opt['service']);
        if ($res === null) {
            ua_die(3, 'link: 서비스 ' . substr((string) $opt['service'], 0, 40) . ' 가 없습니다. 아무것도 바꾸지 않았습니다.');
        }
        $svcSql = ' AND service_id = ?';
        $svcArg[] = $res['canonical'];
    }
    $rows = db_all('SELECT service_id, external_id, state, user_id, row_version, ext_status, gone_at, is_emergency FROM ' . T_EXT_ACCOUNTS
        . ' WHERE LOWER(username) = ?' . $svcSql . ' ORDER BY service_id ASC, id ASC', $svcArg);
    if ($rows === []) {
        ua_say('link: ' . $from . ' 인 외부 계정이 없습니다.');
    }
    $bySvc = [];
    foreach ($rows as $r) {
        $bySvc[(string) $r['service_id']][] = $r;
    }
    $items  = [];
    $report = [];   // service_id => 한 줄 결과
    foreach ($bySvc as $sid => $list) {
        $linked = null;
        foreach ($list as $r) {
            if ((string) $r['state'] === 'linked') {
                $linked = $r;
            }
        }
        if ($linked !== null) {
            $report[$sid] = ((int) $linked['user_id'] === $tid)
                ? '건너뜀(이미 ' . $to . ' 에 연결됨)' : '건너뜀(이미 다른 계정에 연결됨)';
            continue;
        }
        $cands = [];
        $gone  = false;
        $emer  = false;
        foreach ($list as $r) {
            if (!in_array((string) $r['state'], ['new', 'ignored', 'unlinked'], true)) {
                continue;
            }
            if (!empty($r['is_emergency'])) {
                $emer = true;   // v1.10.0 §6-3 — 시스템이 비상 계정(extra.kind=emergency)으로 표시한 계정은 묶지 않는다
                continue;
            }
            if ((string) $r['ext_status'] === 'deleted' || (string) $r['ext_status'] === 'disabled' || $r['gone_at'] !== null) {
                $gone = true;   // 삭제·비활성·목록에서 빠진 계정은 이관하지 않는다
                continue;
            }
            $cands[] = $r;
        }
        if ($cands === [] && $emer) {
            $report[$sid] = 'skipped: emergency';
            continue;
        }
        if ($cands === [] && $gone) {
            $report[$sid] = '건너뜀(삭제·비활성 계정)';
            continue;
        }
        if ($cands === []) {
            $report[$sid] = '건너뜀(연결 가능한 상태 없음)';
            continue;
        }
        if (count($cands) > 1) {
            $report[$sid] = '건너뜀(같은 아이디의 외부 계정이 ' . count($cands) . '개 - 화면에서 직접 고르세요)';
            continue;
        }
        $r = $cands[0];
        $items[] = ['op' => 'link', 'service_id' => $sid, 'external_id' => (string) $r['external_id'],
            'target' => ['uuid' => (string) $tu['uuid']], 'expect_version' => (int) $r['row_version']];
    }

    if ($items !== []) {
        if (!$apply) {
            foreach ($items as $it) {
                $report[$it['service_id']] = '연결 예정(dry-run)';
            }
        } else {
            $plan    = alo_plan(['items' => $items], $tid);
            $okSeqs  = [];
            $seqSvc  = [];
            foreach ($plan['items'] as $pi) {
                $seqSvc[(int) $pi['seq']] = (string) $pi['service_id'];
                if ($pi['verdict'] === 'ok') {
                    $okSeqs[] = (int) $pi['seq'];
                } else {
                    $report[(string) $pi['service_id']] = '건너뜀(' . implode(',', $pi['codes']) . ')';
                }
            }
            for ($guard = 0; $guard < 40; $guard++) {
                $res = alo_commit(['plan_id' => $plan['plan_id'], 'seqs' => $okSeqs], $tid);
                foreach ($res['results'] as $x) {
                    $sid = $seqSvc[(int) $x['seq']] ?? '?';
                    if ($x['status'] === 'done') {
                        $report[$sid] = '연결';
                    } else {
                        $report[$sid] = '실패(' . (string) $x['error_code'] . ')';
                        $exit = 1;
                    }
                }
                if ((int) $res['remaining'] === 0) {
                    break;
                }
            }
        }
    }
    ksort($report);
    foreach ($report as $sid => $line) {
        ua_say('link: ' . $sid . ' - ' . $line);
    }
}

exit($exit);
