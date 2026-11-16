// 전역 설정 당겨 오기(서버끼리, 설계 116 §4 POST). Node 18+ · 설치 없음. 실행: ULOGIN_SERVICE_SECRET=… node remote_config.js
// if_etag 가 같으면 changed:false 만 온다. 바뀌면 params{키:{value,conditions}} 전체 — 조건 평가는 그 시스템이 한다.
const base = process.env.ULOGIN_BASE || 'http://127.0.0.1:8080';
let etag = null, params = {};
async function pull() {
  const res = await fetch(base + '/server/api/platform/remote_config.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, signal: AbortSignal.timeout(5000),
    body: JSON.stringify({ service_id: 'task', service_secret: process.env.ULOGIN_SERVICE_SECRET, if_etag: etag }) });
  const j = await res.json();
  if (!j.ok) throw new Error(res.status + ' ' + j.error.code);
  if (!j.data.changed) return console.log('바뀐 것 없음', j.data.etag);
  etag = j.data.etag;
  params = j.data.params;
  console.log('버전', j.data.versions, '키', Object.keys(params).length, params);
}
pull().catch(e => console.log('실패 — 전에 받은 값을 계속 쓴다:', e.message));
