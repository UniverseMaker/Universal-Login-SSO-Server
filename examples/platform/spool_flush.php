<?php
// 스풀 flush 전체 예(설계 116 §0-3·§0-5). PHP 7.2+ · 확장 없음. cron·작업 스케줄러로 1분마다: php spool_flush.php logs|audit
// 쓰는 쪽: 자기 저장소에 먼저 쓰고, 같은 사건을 spool/ulogin-{logs|audit}.jsonl 에 한 줄 추가(event_id 는 만들 때 1회 부여).
// 보존 상한 정리(로그 50MB·7일)는 쓰는 쪽에서 한다. 감사 스풀은 버리지 않는다.
$kind  = ($argv[1] ?? 'logs') === 'audit' ? 'audit' : 'logs';
$base  = getenv('ULOGIN_BASE') ?: 'http://127.0.0.1:8080';
$sid   = getenv('ULOGIN_SERVICE_ID') ?: 'task';
$dir   = __DIR__ . '/spool';
$spool = "$dir/ulogin-$kind.jsonl";
$state = "$dir/ulogin-$kind.state.json";   // {"next":유닉스초, "fail":연속 실패 수}
$st = is_file($state) ? (json_decode((string) file_get_contents($state), true) ?: []) : [];
$st += ['next' => 0, 'fail' => 0];
if (time() < $st['next'] || !is_file($spool)) { exit(0); }

$fp = fopen($spool, 'c+');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) { exit(0); }             // 다른 flush 가 도는 중
$lines = array_values(array_filter(explode("\n", stream_get_contents($fp)), 'strlen'));
$sent = 0;
$cap = 100;                                                            // 배치 ≤ 100건, 400 이면 반으로
while ($sent < count($lines)) {
    $batch = array_slice($lines, $sent, $cap);
    while (strlen(implode(',', $batch)) > 240 * 1024 && count($batch) > 1) { $batch = array_slice($batch, 0, intdiv(count($batch), 2)); }
    $events = array_map(function ($l) { return json_decode($l, true); }, $batch);
    $body = json_encode(['service_id' => $sid, 'service_secret' => getenv('ULOGIN_SERVICE_SECRET'), 'events' => $events], JSON_UNESCAPED_UNICODE);
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n",
        'content' => $body, 'timeout' => 5, 'ignore_errors' => true]]);
    $raw = @file_get_contents("$base/server/api/platform/$kind.php", false, $ctx);
    $code = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int) $m[1] : 0;
    $j = json_decode((string) $raw, true);
    if ($code === 200 && !empty($j['ok'])) {
        foreach ($j['data']['rejected'] as $r) { error_log("[ulogin $kind] rejected {$r['event_id']}: {$r['reason']}"); }
        if ($kind === 'audit' && isset($j['data']['receipt'])) {          // 감사 영수증은 로컬 감사 저장소 옆에 보관
            file_put_contents("$dir/ulogin-audit.receipts.jsonl", json_encode($j['data']['receipt']) . "\n", FILE_APPEND);
        }
        if (!empty($j['data']['redacted'])) { error_log("[ulogin $kind] 서버가 비밀값 {$j['data']['redacted']}건을 가렸다 — 마스킹을 고쳐라"); }
        $sent += count($batch);
        $st['fail'] = 0;
        continue;
    }
    if ($code === 400 && count($batch) === 1) {                        // 1건도 400 → 격리하고 계속
        file_put_contents("$dir/ulogin-$kind.quarantine.jsonl", $batch[0] . "\n", FILE_APPEND);
        $sent += 1;
        continue;
    }
    $wait = 0;
    if ($code === 400) { $cap = max(1, intdiv(count($batch), 2)); continue; }   // 크기·건수 초과 → 반으로 나눠 다시
    elseif ($code === 401 || ($code === 403 && ($j['error']['code'] ?? '') !== 'QUOTA_EXCEEDED')) { $wait = 3600; }
    elseif ($code === 403) { $wait = strtotime('tomorrow 00:00:00 UTC') - time(); }   // QUOTA_EXCEEDED
    elseif ($code === 404 || $code === 405) { $wait = 6 * 3600; }      // v1.9.0 전 서버 — 「미지원」
    elseif ($code === 429) { $wait = (int) ($j['data']['retry_after'] ?? 60); }
    else { $st['fail']++; $wait = (int) min(900, 5 * (2 ** min(8, $st['fail'] - 1))) * (0.8 + mt_rand(0, 40) / 100); }   // 5xx·연결 실패
    $st['next'] = time() + max(5, $wait);
    break;
}
// 보낸 줄만 지우고 나머지는 남긴다(accepted·duplicate·rejected 모두 「처리됨」)
ftruncate($fp, 0);
rewind($fp);
fwrite($fp, implode("\n", array_slice($lines, $sent)) . (count($lines) > $sent ? "\n" : ''));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);
file_put_contents($state, json_encode($st));
