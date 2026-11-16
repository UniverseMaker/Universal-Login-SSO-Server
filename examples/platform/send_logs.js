// 운영 로그 1회 전송(서버끼리, 설계 116 §0-7). Node 18+ · 설치 없음. 실행: ULOGIN_SERVICE_SECRET=… node send_logs.js
const base = process.env.ULOGIN_BASE || 'http://127.0.0.1:8080';
const ev = { event_id: require('crypto').randomUUID(), ts: new Date().toISOString(), level: 'error', event: 'job.export.failed', message: '내보내기 실패: 시간 초과' };
(async () => {
  const res = await fetch(base + '/server/api/platform/logs.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, signal: AbortSignal.timeout(5000),
    body: JSON.stringify({ service_id: 'task', service_secret: process.env.ULOGIN_SERVICE_SECRET, events: [ev] }) });
  const j = await res.json().catch(() => null);
  if (res.status === 200 && j && j.ok) console.log('accepted', j.data.accepted, 'duplicate', j.data.duplicate, 'rejected', j.data.rejected.length); // 스풀에서 지운다
  else if (res.status === 429) console.log('retry_after', (j && j.data && j.data.retry_after) || res.headers.get('Retry-After'), 's'); // 그만큼 기다렸다가 다시
  else if (res.status === 404 || res.status === 405) console.log('미지원(v1.9.0 전) — 6시간 뒤 재확인'); // 스풀 유지
  else console.log('실패', res.status, j && j.error && j.error.code, '— 스풀 유지, 백오프');
})().catch(e => console.log('연결 실패 — 스풀 유지, 백오프:', e.message));
