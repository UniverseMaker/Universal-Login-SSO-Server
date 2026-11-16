<?php // 운영 로그 1회 전송(서버끼리, 설계 116 §0-7). 실행: ULOGIN_SERVICE_SECRET=… php send_logs.php
$base = getenv('ULOGIN_BASE') ?: 'http://127.0.0.1:8080';
$ev = ['event_id' => sprintf('%04x%04x-%04x-4%03x-%04x-%04x%04x%04x', ...array_map('random_int', [0,0,0,0,0x8000,0,0,0], [0xffff,0xffff,0xffff,0xfff,0xbfff,0xffff,0xffff,0xffff])),
       'ts' => gmdate('Y-m-d\TH:i:s\Z'), 'level' => 'error', 'event' => 'job.export.failed', 'message' => '내보내기 실패: 시간 초과'];
$body = json_encode(['service_id' => 'task', 'service_secret' => getenv('ULOGIN_SERVICE_SECRET'), 'events' => [$ev]], JSON_UNESCAPED_UNICODE);
$ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $body, 'timeout' => 5, 'ignore_errors' => true]]);
$raw = @file_get_contents($base . '/server/api/platform/logs.php', false, $ctx);
$code = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int) $m[1] : 0;
$j = json_decode((string) $raw, true);
if ($code === 200 && !empty($j['ok'])) { echo "accepted={$j['data']['accepted']} duplicate={$j['data']['duplicate']} rejected=" . count($j['data']['rejected']) . "\n"; } // 스풀에서 지운다
elseif ($code === 429) { echo 'retry_after=' . ($j['data']['retry_after'] ?? 60) . "s\n"; } // 그만큼 기다렸다가 다시
elseif ($code === 404 || $code === 405) { echo "미지원(v1.9.0 전) — 6시간 뒤 재확인\n"; } // 스풀 유지
else { echo "실패 {$code} " . ($j['error']['code'] ?? '') . " — 스풀 유지, 백오프\n"; }
