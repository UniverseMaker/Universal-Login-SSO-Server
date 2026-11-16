// ============================================================================
// tests/ui/account_link_ui.mjs — v1.8.4 관리자 「계정 연결」 화면 실제 브라우저 점검
//
// **배포물이 아니다.** theme_matrix.mjs 와 같이 서버에 올리지 않고, `php tests/run.php` 에 들어 있지 않다.
// 방식은 theme_matrix.mjs 와 같다: 헤드리스 Chrome/Edge 를 CDP 로 몰고, `/server/` 요청은 전부 아래
// **상태를 가진 고정 응답**으로 가로챈다(운영 서버·DB 무접촉). 정적 파일만 대상 서버에서 받는다:
//   php -S 127.0.0.1:<빈 포트> -t <설치루트> tools/router-ulogin.php      (8080 은 운영 — 쓰지 마라)
// 사용
//   node tests/ui/account_link_ui.mjs --base=http://127.0.0.1:<port> --out=<폴더> [--chrome=<경로>]
//   종료코드: 0 통과 · 1 실패 있음 · 2 인자/실행 오류
// 덮는 것: 필터 → 서버 파라미터(state·ext, 바꾸면 page=1) · plan→commit(25건씩)→결과·설정 링크 1회 노출 ·
//   D-328 경고 · 권한(superadmin/admin/manager) · 외부 필드 XSS 0 · 테마 2×팔레트 3×밝기 2 · 375px.
// 판정식(RUI.audit·대비·넘침)은 theme_matrix.mjs 의 PROBE 를 그대로 꺼내 쓴다 — 두 하네스가 같은 기준이다.
// 경로에 대괄호([예시])가 있다 — glob 을 쓰지 않는다. 설치 루트는 이 파일 위치에서 구한다(하드코딩 없음).
// ============================================================================
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import { fileURLToPath } from 'node:url';

const args = Object.fromEntries(process.argv.slice(2).map(a => { const m = /^--([^=]+)(?:=(.*))?$/.exec(a); return m ? [m[1], m[2] ?? true] : [a, true]; }));
const BASE = String(args.base || '').replace(/\/$/, '');
const OUT = args.out ? path.resolve(String(args.out)) : '';
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(BASE) || !OUT) {
  console.error('usage: node tests/ui/account_link_ui.mjs --base=http://127.0.0.1:<port> --out=<dir> [--chrome=<path>]');
  process.exit(2);
}
if (/:8080$/.test(BASE)) { console.error('8080 은 운영 포트다. 점검용 서버를 따로 띄워라.'); process.exit(2); }
fs.mkdirSync(path.join(OUT, 'shots'), { recursive: true });
const CHROME = [args.chrome, 'C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe']
  .filter(p => typeof p === 'string').find(p => fs.existsSync(p));
if (!CHROME) { console.error('Chrome/Edge 를 찾지 못했다 (--chrome=)'); process.exit(2); }
const PORT = 9300 + Math.floor(Math.random() * 400);

// PROBE 는 tests/ui/theme_matrix.mjs 의 것을 그대로 꺼내 쓴다(같은 판정).
const tm = fs.readFileSync(path.join(ROOT, 'tests/ui/theme_matrix.mjs'), 'utf8');
const pm = /const PROBE = `([\s\S]*?)`;\n/.exec(tm);
if (!pm) { console.error('PROBE 를 찾지 못했다 — 검사기가 눈멀었다'); process.exit(2); }
const PROBE = new Function('return `' + pm[1] + '`;')();

// ------------------------------------------------------------ 고정 응답
const now = new Date().toISOString().replace(/\.\d+Z$/, 'Z');
const ago = d => new Date(Date.now() - d * 86400000).toISOString().replace(/\.\d+Z$/, 'Z');
const ROLE = { mode: 'superadmin' };
function me() {
  const r = { superadmin: 30, admin: 20, manager: 10 }[ROLE.mode];
  return { uuid: '7f1c2a9e-0000-4000-8000-00000000a001', username: 'admin', email: 'admin@example.test', email_verified: true, name: '관리자', nickname: '운영',
    phone: null, timezone: 'Asia/Seoul', locale: 'ko', marketing_opt_in: false, role: ROLE.mode, role_rank: r, account_level: 'verified', account_level_rank: 20,
    billing_tier: 'pro', billing_tier_rank: 20, billing_active: true, billing_expires_at: '2027-01-01T00:00:00Z', status: 'active', points: 0, created_at: '2026-08-25T01:00:00Z', last_login_at: now };
}
const XSS_U = '<img src=x onerror="window.__xss=1">';
const XSS_N = '"><svg onload="window.__xss=2"></svg>';
const XSS_E = '<script>window.__xss=3</script>';
const U1 = '11111111-1111-4111-8111-111111111111', U2 = '22222222-2222-4222-8222-222222222222';
const SYSTEMS = [
  { service_id: 'admin', service_name: 'Demo App A', service_status: 'active', supported: true, last_push_at: ago(0.1), last_full_sync_at: ago(0.1), sync_requested_at: null, counts: { total: 41, new: 12, ignored: 2, linked: 27, unlinked: 0, gone: 1 } },
  { service_id: 'manager', service_name: XSS_N, service_status: 'active', supported: true, last_push_at: ago(5), last_full_sync_at: ago(6), sync_requested_at: ago(1), counts: { total: 9, new: 9, ignored: 0, linked: 0, unlinked: 0, gone: 0 } },
  { service_id: 'research', service_name: 'Demo Lab App A', service_status: 'disabled', supported: false, last_push_at: null, last_full_sync_at: null, sync_requested_at: null, counts: { total: 0, new: 0, ignored: 0, linked: 0, unlinked: 0, gone: 0 } }
];
function acct(i) {
  const base = { service_id: i % 2 ? 'admin' : 'manager', external_id: 'e' + i, username: 'user' + String(i).padStart(2, '0'), email: 'u' + i + '@example.test', name: '사용자' + i,
    ext_status: 'active', ext_created_at: ago(300), ext_last_login_at: ago(i), state: 'new', row_version: i % 3, gone: false, extra: {}, linked_user: null,
    link_origin: null, linked_at: null, bound_uuid: null, candidates: [], conflicts: [] };
  if (i === 1) Object.assign(base, { username: XSS_U, name: XSS_N, email: 'x1@example.test', extra: { role: 'admin', department: XSS_E, note: XSS_E },
    candidates: [{ uuid: U1, username: 'kim', email: 'x1@example.test', match: ['email'], already_linked_here: false, role_rank: 0, status: 'active' }] });
  if (i === 2) Object.assign(base, { username: 'hong', email: 'hong@example.test', bound_uuid: U2,
    candidates: [{ uuid: U2, username: 'hong', email: 'hong@example.test', match: ['email', 'username', 'bound'], already_linked_here: false, role_rank: 30, status: 'active' }] });
  if (i === 3) Object.assign(base, { state: 'linked', extra: { role: 'admin' }, linked_user: { uuid: U1, username: 'kim', email: 'kim@example.test', name: '김', status: 'active', role_rank: 0 },
    link_origin: 'attached', linked_at: ago(2), conflicts: [{ code: 'EXT_ALREADY_LINKED', uuid: U1 }, { code: 'EXT_ROLE_ADMIN_LOW_RANK', uuid: U1 }] });
  if (i === 4) Object.assign(base, { gone: true, ext_status: 'deleted', conflicts: [{ code: 'EXT_GONE', uuid: null }] });
  if (i === 5) Object.assign(base, { ext_status: 'disabled', conflicts: [{ code: 'EXT_DISABLED', uuid: null }] });
  return base;
}
const ACCOUNTS = Array.from({ length: 32 }, (_, k) => acct(k + 1));
// v1.8.6 Q-5 — 연결된 행 하나 더: 주인 U2 인데 EXT_ALREADY_LINKED 가 **다른** uuid(U1)를 가리킨다 → 경고가 남아야 한다.
// (e3 은 주인 U1 = 경고 uuid U1 → EXT_ALREADY_LINKED 는 숨기고 EXT_ROLE_ADMIN_LOW_RANK 는 남긴다.)
ACCOUNTS.push(Object.assign(acct(33), { state: 'linked', linked_user: { uuid: U2.toUpperCase(), username: 'park', email: 'park@example.test', name: '박', status: 'active', role_rank: 20 },
  link_origin: 'attached', linked_at: ago(1), conflicts: [{ code: 'EXT_ALREADY_LINKED', uuid: U1 }] }));
const PERSON = {
  user: { uuid: U1, username: 'kim', email: 'kim@example.test', name: XSS_N, status: 'active', setup_pending: true, role_rank: 0 },
  systems: [
    { service_id: 'admin', service_name: 'Demo App A', link: { external_id: 'e3', username: 'kim', linked_at: ago(2), link_origin: 'created', row_version: 2 }, candidates: [] },
    { service_id: 'manager', service_name: 'MCC', link: null, candidates: [{ external_id: 'e1', username: XSS_U, email: 'x1@example.test', state: 'new', row_version: 1, match: ['email'], admin_flag: true }] },
    { service_id: 'research', service_name: 'Demo Lab App A', link: null, candidates: [] }
  ]
};
const GROUPS = { groups: [
  { email: 'lee@example.test', ulogin_user: null, accounts: [
    { service_id: 'admin', external_id: 'g1', username: 'lee', name: XSS_N, state: 'new', row_version: 0, admin_flag: false },
    { service_id: 'manager', external_id: 'g2', username: 'lee01', name: '이', state: 'unlinked', row_version: 3, admin_flag: true }] },
  { email: 'park@example.test', ulogin_user: { uuid: U2, username: 'park' }, accounts: [{ service_id: 'admin', external_id: 'g3', username: 'park', name: '박', state: 'new', row_version: 0, admin_flag: false }] }
], page: 1, per_page: 20, total: 2 };
const JOB_ID = '0c7f0000-0000-4000-8000-0000000000aa';
const JOBS = { items: [{ job_id: JOB_ID, kind: 'plan', status: 'partial', service_id: null, created_by: 'admin', created_at: ago(0.2), finished_at: ago(0.1), expires_at: ago(-0.02), item_count: 3, totals: { done: 2, failed: 1, skipped: 0, planned: 0 } },
  { job_id: '0c7f0000-0000-4000-8000-0000000000bb', kind: 'push', status: 'done', service_id: 'admin', created_by: null, created_at: ago(0.3), finished_at: ago(0.3), expires_at: null, item_count: 0, totals: { done: 0, failed: 0, skipped: 0, planned: 0 } }],
  page: 1, per_page: 20, total: 2 };
const JOB = { job: JOBS.items[0], items: [
  { seq: 1, op: 'create_link', service_id: 'admin', external_id: 'e5', verdict: 'ok', status: 'done', error_code: null, target_ref: 'n1', user: { uuid: U1, username: 'kim' }, done_at: ago(0.1) },
  { seq: 2, op: 'link', service_id: 'manager', external_id: 'e6', verdict: 'invalid', status: 'failed', error_code: 'USER_ALREADY_LINKED_SERVICE', target_ref: null, user: null, done_at: ago(0.1) }] };

const LOG = { calls: [], accQ: [] };
const PLANS = {};
let planN = 0;
function planOf(body) {
  planN++;
  const id = '0c7f0000-0000-4000-8000-' + String(planN).padStart(12, '0');
  const nu = body.new_users || [];
  const nuOut = nu.map(n => {
    const codes = []; let sugg = null, attach = null;
    if (n.username === 'hong') { codes.push('DUPLICATE_USERNAME'); sugg = 'hong_2'; }
    if (n.email === 'hong@example.test') { codes.push('DUPLICATE_EMAIL'); attach = { uuid: U2, username: 'hong' }; }
    return { ref: n.ref, verdict: codes.length ? 'invalid' : 'ok', codes, suggested_username: sugg, attach_candidate: attach };
  });
  const bad = {}; nuOut.forEach(n => { if (n.codes.length) bad[n.ref] = n.codes; });
  const items = (body.retry_job_id ? [{ op: 'link', service_id: 'manager', external_id: 'e6', target: { uuid: U2 } }] : body.items).map((it, i) => {
    let codes = [];
    if (it.target && it.target.ref && bad[it.target.ref]) codes = it.op === 'create_link' ? bad[it.target.ref].slice() : ['DEPENDENCY_FAILED'];
    if (it.external_id === 'e1') codes.push('EXT_ROLE_ADMIN_LOW_RANK');
    if (it.external_id === 'e4') codes.push('EXT_GONE');
    const warnOnly = codes.every(c => ['EXT_ROLE_ADMIN_LOW_RANK', 'EXT_GONE', 'EXT_DISABLED', 'SYSTEM_BOUND_OTHER'].includes(c));
    return { seq: i + 1, op: it.op, service_id: it.service_id, external_id: it.external_id, verdict: !codes.length ? 'ok' : (warnOnly ? 'warn' : 'invalid'), codes };
  });
  const summary = { ok: 0, warn: 0, invalid: 0 }; items.forEach(x => summary[x.verdict]++);
  PLANS[id] = { items, done: new Set(), cancelled: false };
  return { plan_id: id, expires_at: ago(-0.02), summary, items, new_users: nuOut };
}
function commitOf(body) {
  const p = PLANS[body.plan_id];
  if (!p || p.cancelled) return { err: { code: 'LINK_PLAN_INVALID', message: '계획이 만료되었거나 이미 확정되었습니다. 다시 미리보기 하세요.', field: 'plan_id' }, status: 409 };
  const want = (body.seqs || p.items.filter(x => x.verdict !== 'invalid').map(x => x.seq)).filter(s => !p.done.has(s));
  const batch = want.slice(0, 25);
  const results = batch.map(s => {
    p.done.add(s);
    const it = p.items[s - 1];
    const r = { seq: s, status: 'done', error_code: null, user: { uuid: U1, username: 'new' + s }, setup_url: null, setup_expires_at: null };
    if (it.op === 'create_link') { r.setup_url = BASE + '/public/reset.html?token=tok_TEST_' + s + '&setup=1'; r.setup_expires_at = ago(-3); }
    if (it.external_id === 'e10') { r.status = 'failed'; r.error_code = 'EXT_CHANGED'; r.user = null; r.setup_url = null; r.setup_expires_at = null; }
    return r;
  });
  p.results = (p.results || []).concat(results);
  const remaining = want.length - batch.length;
  const failed = p.results.filter(r => r.status === 'failed').length;
  const totals = { done: p.results.length - failed, failed, skipped: remaining ? 0 : p.items.length - p.done.size };
  return { data: { job_id: body.plan_id, status: remaining ? 'running' : (failed || totals.skipped ? 'partial' : 'done'), processed: batch.length, remaining, results, totals } };
}
function fixtureFor(req) {
  const u = new URL(req.url);
  const i = u.pathname.indexOf('/server/');
  if (i < 0) return null;
  const p = u.pathname.slice(i + '/server'.length).replace(/\.php$/, '');
  const ok = d => ({ ok: true, data: d, error: null });
  const sq = u.searchParams;
  if (p === '/api/auth/csrf') return ok({ csrf_token: 'test-csrf' });
  if (p === '/api/me/index') return ok({ user: me() });
  if (p === '/api/admin/stats') return ok({ generated_at: now, users: { total: 1 }, logins: {}, points: {}, services: {}, sessions: {}, by_account_level: [], by_role: [], by_billing_tier: [] });
  if (p === '/api/health') return ok({ status: 'ok', time: now, checks: [] });
  if (p === '/api/admin/users') return ok({ total: 1, page: 1, per_page: 10, pages: 1, filters_applied: {}, items: [{ uuid: U2, username: 'park', email: 'park@example.test', name: XSS_N, role: 'admin', role_rank: 20, status: 'active' }] });
  if (p === '/api/admin/account_links') {
    LOG.calls.push('GET ' + sq.get('view'));
    const v = sq.get('view');
    if (v === 'systems') return ok({ systems: SYSTEMS });
    if (v === 'accounts') {
      LOG.accQ.push(u.search);
      // 서버(account_links.php)와 같은 뜻: state 기본 all · not_linked = new·ignored·unlinked / ext 기본 any
      const st = sq.get('state') || 'all', ex = sq.get('ext') || 'any';
      const gone = a => a.gone || a.ext_status === 'deleted';
      let items = ACCOUNTS.filter(a => (!sq.get('service_id') || a.service_id === sq.get('service_id'))
        && (st === 'all' || (st === 'not_linked' ? a.state !== 'linked' : a.state === st))
        && (ex === 'any' || (ex === 'gone' ? gone(a) : ex === 'disabled' ? (!gone(a) && a.ext_status === 'disabled') : (!gone(a) && a.ext_status !== 'disabled'))));
      // 총계를 부풀려 페이지 버튼이 생기게 한다(페이지를 바꾼 뒤 필터를 바꾸면 page=1 로 돌아오는지 본다)
      return ok({ items, page: Number(sq.get('page') || 1), per_page: Number(sq.get('per_page') || 50), total: items.length + 100 });
    }
    if (v === 'person') return ok(PERSON);
    if (v === 'groups') return ok(GROUPS);
    if (v === 'jobs') return ok(JOBS);
    if (v === 'job') return ok(JOB);
  }
  if (p === '/api/admin/account_link_ops') {
    let body = {}; try { body = JSON.parse(req.postData || '{}'); } catch (e) { /* */ }
    LOG.calls.push('POST ' + body.action + (body.seqs ? ' seqs=' + body.seqs.length : ''));
    LOG.last = body;
    const superOnly = body.action !== 'sync_request';
    if (superOnly && ROLE.mode !== 'superadmin') return { ok: false, data: null, error: { code: 'FORBIDDEN', message: '권한이 없습니다.', field: null }, __code: 403 };
    if (body.action === 'plan') return ok(planOf(body));
    if (body.action === 'commit') { const r = commitOf(body); return r.err ? { ok: false, data: null, error: r.err, __code: r.status } : ok(r.data); }
    if (body.action === 'cancel') { if (PLANS[body.plan_id]) PLANS[body.plan_id].cancelled = true; return ok({ plan_id: body.plan_id, status: 'cancelled' }); }
    if (body.action === 'sync_request') return ok({ service_id: body.service_id, sync_requested_at: now });
    if (body.action === 'setup_link') return ok({ uuid: body.uuid, setup_url: BASE + '/public/reset.html?token=tok_AGAIN&setup=1', setup_expires_at: ago(-3), invalidated: 1 });
    if (body.action === 'import') return ok({ sync_id: 'f-1', page: 1, accepted: 2, created: 2, updated: 0, unchanged: 0, duplicate: false, sync_requested: false });
  }
  return { ok: false, data: null, error: { code: 'NOT_FOUND', message: '점검용 고정 응답이 없습니다(' + p + ').', field: null }, __code: 404 };
}

// ------------------------------------------------------------ CDP (theme_matrix 와 같은 배선)
const sleep = ms => new Promise(r => setTimeout(r, ms));
async function waitJson(url) { for (let i = 0; i < 160; i++) { try { const r = await fetch(url); if (r.ok) return r.json(); } catch (e) { /* */ } await sleep(250); } throw new Error('CDP'); }
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'ul-al-'));
const chrome = spawn(CHROME, ['--headless=new', '--remote-debugging-port=' + PORT, '--user-data-dir=' + profile, '--no-first-run', '--no-default-browser-check',
  '--disable-gpu', '--hide-scrollbars', '--window-size=1280,900', '--disable-extensions', 'about:blank'], { stdio: 'ignore' });
let killed = false;
function killChrome() { if (killed) return; killed = true; try { spawn('taskkill', ['/PID', String(chrome.pid), '/T', '/F'], { stdio: 'ignore' }); } catch (e) { /* */ } }
process.on('exit', killChrome);
await waitJson('http://127.0.0.1:' + PORT + '/json/version');
const tgt = await (await fetch('http://127.0.0.1:' + PORT + '/json/new?about:blank', { method: 'PUT' })).json();
const ws = new WebSocket(tgt.webSocketDebuggerUrl);
await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej; });
let seq = 0; const pending = new Map(); const listeners = [];
ws.onmessage = ev => { const m = JSON.parse(ev.data); if (m.id && pending.has(m.id)) { const p = pending.get(m.id); pending.delete(m.id); m.error ? p.rej(new Error(m.error.message)) : p.res(m.result); return; } listeners.forEach(f => f(m)); };
const send = (method, params = {}) => { const id = ++seq; ws.send(JSON.stringify({ id, method, params })); return new Promise((res, rej) => pending.set(id, { res, rej })); };
let errors = [];
listeners.push(m => {
  if (m.method === 'Fetch.requestPaused') {
    const body = fixtureFor(m.params.request);
    if (!body) { send('Fetch.continueRequest', { requestId: m.params.requestId }).catch(() => {}); return; }
    const code = body.__code || 200; delete body.__code;
    send('Fetch.fulfillRequest', { requestId: m.params.requestId, responseCode: code, responseHeaders: [{ name: 'Content-Type', value: 'application/json; charset=utf-8' }],
      body: Buffer.from(JSON.stringify(body)).toString('base64') }).catch(() => {});
  } else if (m.method === 'Runtime.exceptionThrown') { errors.push('exception: ' + (m.params.exceptionDetails.exception?.description || m.params.exceptionDetails.text)); }
  else if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error') { errors.push('console.error: ' + m.params.args.map(a => a.value ?? a.description).join(' ')); }
  else if (m.method === 'Log.entryAdded' && m.params.entry.level === 'error') {
    const t = m.params.entry.text || '';
    if (m.params.entry.source === 'network' && /40[34]|409/.test(t)) return;   // 의도한 403·404·409
    errors.push('log(' + m.params.entry.source + '): ' + t);
  }
});
await send('Page.enable'); await send('Runtime.enable'); await send('Log.enable');
await send('Fetch.enable', { patterns: [{ urlPattern: '*/server/*', requestStage: 'Request' }] });
async function ev(expr) { const r = await send('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true }); if (r.exceptionDetails) throw new Error('evaluate: ' + (r.exceptionDetails.exception?.description || r.exceptionDetails.text) + ' :: ' + expr.slice(0, 80)); return r.result.value; }
async function until(expr, ms = 6000) { const t0 = Date.now(); while (Date.now() - t0 < ms) { try { if (await ev('!!(' + expr + ')')) return true; } catch (e) { /* */ } await sleep(80); } throw new Error('timeout: ' + expr); }
async function viewport(w, h, mobile) { await send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: !!mobile }); }
async function load(prefs) {
  errors = [];
  await send('Page.navigate', { url: BASE + '/public/version.txt' }); await sleep(150);
  await ev('localStorage.clear(); localStorage.setItem("rui_prefs", ' + JSON.stringify(JSON.stringify(prefs)) + '); true');
  await send('Page.navigate', { url: BASE + '/public/admin.html#links' });
  await until('document.querySelector("#al-sys-host table") || (document.getElementById("app") && !document.getElementById("app").hidden && !document.querySelector(\'[data-tab-panel="links"]\'))', 10000);
  await ev('document.fonts ? document.fonts.ready.then(function(){return true;}) : true');
  await sleep(150);
}
async function shot(name) { const r = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false }); const f = path.join(OUT, 'shots', name + '.png'); fs.writeFileSync(f, Buffer.from(r.data, 'base64')); return f; }
const click = sel => ev('(function(){var n=document.querySelector(' + JSON.stringify(sel) + ');if(!n)throw new Error("no "+' + JSON.stringify(sel) + ');n.click();return true;})()');
const clickText = (sel, txt) => ev('(function(){var a=[].slice.call(document.querySelectorAll(' + JSON.stringify(sel) + ')).filter(function(n){return n.textContent.indexOf(' + JSON.stringify(txt) + ')>=0;});if(!a.length)throw new Error("no text "+' + JSON.stringify(txt) + ');a[a.length-1].click();return true;})()');
const XSS_CHECK = '(function(){return {xss: window.__xss===undefined ? 0 : window.__xss, imgs: document.querySelectorAll("#al-root img, .modal img").length, svgOnload: document.querySelectorAll("[onload],[onerror]").length, scripts: document.querySelectorAll("#al-root script, .modal script").length};})()';

const checks = [];
async function check(name, fn) { try { const v = await fn(); checks.push({ name, ok: v === true, got: v }); process.stdout.write(v === true ? '.' : 'F'); } catch (e) { checks.push({ name, ok: false, got: String(e.message || e) }); process.stdout.write('E'); } }
const shots = {};

// ============================================================ 1. superadmin 흐름
await viewport(1440, 1000, false);
ROLE.mode = 'superadmin';
await check('superadmin: 시스템 표 — 미지원·마지막 수신 n일 전·요청 후 미수신·재수집 버튼', async () => {
  await load({ theme: '1', palette: 'teal', mode: 'light', layout: 'side' });
  return ev('(function(){var t=document.getElementById("al-sys-host").textContent;return /미지원/.test(t)&&/마지막 수신 5일 전/.test(t)&&/요청 후 미수신/.test(t)&&document.querySelectorAll("[data-al-sync]").length===3&&!!document.querySelector(\'.nav a[data-key="links"]\')?true:t.slice(0,200);})()');
});
// v1.8.6 G9 — service_id 가 역할 이름과 같은 철자(admin·manager)여도 선택자·셀이 섞이지 않는다(설계 104 E-2)
await check('G9 service_id admin·manager: 시스템 행 선택자가 각각 정확히 1개 · 역할 라벨로 바뀌지 않음', async () => {
  return ev('(function(){var a=document.querySelectorAll(\'[data-al-sync="admin"]\').length,m=document.querySelectorAll(\'[data-al-sync="manager"]\').length;var t=document.getElementById("al-sys-host").textContent;return a===1&&m===1&&/Demo App A/.test(t)?true:("admin="+a+" manager="+m+" "+t.slice(0,200));})()');
});
await check('재수집 요청(admin 이상) → POST sync_request', async () => {
  await click('[data-al-sync="admin"]'); await until('document.querySelector(".modal")'); await clickText('.modal-foot .btn', '요청');
  await sleep(400); return LOG.calls.includes('POST sync_request') ? true : LOG.calls.join(',');
});
await check('계정 목록 — 행 31(연결 안 됨) · XSS 0(외부 username·name·extra·service_name)', async () => {
  await clickText('#al-subbar .tab', '계정 목록'); await until('document.querySelectorAll("#al-acc-host tbody tr").length>=31');
  const x = await ev(XSS_CHECK);
  const txt = await ev('document.getElementById("al-acc-host").textContent.indexOf("<img src=x onerror")>=0 && document.getElementById("al-sys-host").textContent.indexOf("<svg onload")>=0');
  return x.xss === 0 && x.imgs === 0 && x.svgOnload === 0 && x.scripts === 0 && txt ? true : JSON.stringify(x) + ' txtShown=' + txt;
});
await check('필터 = 서버 파라미터(D-335) — 기본 state=not_linked&ext=any · 2쪽 뒤 ext=gone → page=1 · 행은 서버가 거른 것 그대로 · 「(이 페이지)」 없음', async () => {
  const first = new URLSearchParams(LOG.accQ[LOG.accQ.length - 1]);
  const dflt = first.get('state') === 'not_linked' && first.get('ext') === 'any';
  await clickText('#al-acc-pager button', '2');
  await until('true'); await sleep(300);
  const p2 = new URLSearchParams(LOG.accQ[LOG.accQ.length - 1]).get('page');
  const n0 = LOG.accQ.length;
  await ev('(function(){var s=document.querySelector("#al-acc-filter select[name=ext]");s.value="gone";s.dispatchEvent(new Event("change"));return true;})()');
  await until('document.querySelectorAll("#al-acc-host tbody tr").length===1');
  const gq = new URLSearchParams(LOG.accQ[LOG.accQ.length - 1]);
  const rows = await ev('[].map.call(document.querySelectorAll("#al-acc-host tbody tr .mono.text-xs.text-muted[title]"),function(n){return n.textContent;}).join(",")');
  const noPageNote = await ev('document.getElementById("al-root").textContent.indexOf("(이 페이지)")<0 && document.getElementById("al-root").textContent.indexOf("걸러 보임")<0');
  await ev('(function(){var s=document.querySelector("#al-acc-filter select[name=ext]");s.value="any";s.dispatchEvent(new Event("change"));return true;})()');
  await until('document.querySelectorAll("#al-acc-host tbody tr").length>=31');
  const ok = dflt && p2 === '2' && LOG.accQ.length > n0 && gq.get('ext') === 'gone' && gq.get('page') === '1' && gq.get('state') === 'not_linked' && rows === 'e4' && noPageNote;
  return ok ? true : JSON.stringify({ dflt, first: LOG.accQ[0], p2, gq: gq.toString(), rows, noPageNote });
});
await check('후보는 제안만 — 미리 체크된 행 0 · D-328 경고(시스템 관리자 → rank<20 후보) 표시', async () => {
  return ev('(function(){var c=document.querySelectorAll("#al-acc-host tbody input[type=checkbox]:checked").length;var t=document.getElementById("al-acc-host").textContent;return c===0&&/관리자 계정 주의/.test(t)&&!!document.querySelector(\'#al-acc-host [title*="미만 계정에 연결"]\')?true:"checked="+c;})()');
});
await check('Q-5 연결된 행 — 경고 uuid 가 그 행 주인이면 「이미 다른 계정에 연결」 숨김(관리자 계정 주의는 남김) · 다른 uuid 면 표시', async () => {
  const setState = v => ev('(function(){var s=document.querySelector("#al-acc-filter select[name=state]");s.value=' + JSON.stringify(v) + ';s.dispatchEvent(new Event("change"));return true;})()');
  await setState('linked');
  await until('document.querySelectorAll("#al-acc-host tbody tr").length===2');
  const v = await ev('(function(){var o={};[].forEach.call(document.querySelectorAll("#al-acc-host tbody tr"),function(tr){var id=tr.querySelector(".mono.text-xs.text-muted[title]").textContent;o[id]=[].map.call(tr.querySelectorAll(".al-codes li"),function(li){return li.title;}).join(",");});return o;})()');
  await setState('not_linked');
  await until('document.querySelectorAll("#al-acc-host tbody tr").length>=31');
  return v.e3 === 'EXT_ROLE_ADMIN_LOW_RANK' && v.e33 === 'EXT_ALREADY_LINKED' ? true : JSON.stringify(v);
});
await ev('(function(){document.getElementById("al-sel-bar").scrollIntoView();return true;})()');
shots.list = await shot('al_accounts_list');
await check('전체 선택 → 「새 통합로그인 계정 만들어 이관」 → 미리보기(plan) — 경고·불가 표시, 불가는 체크 불가', async () => {
  await click('#al-acc-host thead input[type=checkbox]');
  await until('/선택 31건/.test(document.getElementById("al-sel-count").textContent)');
  await clickText('#al-sel-bar .btn', '새 통합로그인 계정 만들어 이관');
  await until('document.querySelector(".modal") && /새 통합로그인 계정 만들어 이관/.test(document.querySelector(".modal").textContent)');
  await clickText('.modal-foot .btn', '미리보기');
  await until('document.querySelector(".modal") && /미리보기: 아직 아무것도/.test(document.querySelector(".modal h3").textContent)', 8000);
  const r = await ev('(function(){var m=document.querySelector(".modal");var t=m.textContent;var inv=m.querySelectorAll("tr.al-v-invalid input[type=checkbox]");var invDis=[].every.call(inv,function(c){return c.disabled&&!c.checked;});var warn=m.querySelectorAll("tr.al-v-warn input[type=checkbox]:checked").length;return {inv:inv.length,invDis:invDis,warn:warn,d328:/시스템 관리자 계정을 통합로그인 관리자\\(admin\\) 미만/.test(t),sugg:/제안 「hong_2」/.test(t),attach:/기존 계정 「hong」과 연결하기로/.test(t),ackHidden:m.querySelector(".al-ack").hidden};})()');
  const planCall = LOG.last && LOG.last.action === 'plan' ? LOG.last.items.length : -1;
  return r.inv === 1 && r.invDis && r.warn === 2 && r.d328 && r.sugg && r.attach && r.ackHidden === false && planCall === 31 ? true : JSON.stringify(r) + ' planItems=' + planCall;
});
shots.preview = await shot('al_preview');
await check('경고 확인 칸 없이 확정 → 거절 문구, 모달 유지', async () => {
  await clickText('.modal-foot .btn', '확정'); await sleep(300);
  return ev('(function(){var e=document.querySelector(".modal .alert-danger");return !!e&&!e.hidden&&/경고가 있는 항목을 확인/.test(e.textContent);})()');
});
await check('확인 후 확정 → commit 25건 단위 2회(같은 seqs 30개) → 결과 · 링크 1회 노출 경고 · 복사칸', async () => {
  await ev('(function(){var a=document.querySelector(".modal .al-ack input");a.click();return true;})()');
  await clickText('.modal-foot .btn', '확정');
  await until('document.querySelector("#al-result-card") && /결과 ·/.test(document.querySelector("#al-result-card h2").textContent)', 8000);
  const commits = LOG.calls.filter(c => /^POST commit/.test(c));
  const r = await ev('(function(){var c=document.getElementById("al-result-card");var t=c.textContent;return {links:c.querySelectorAll(".al-linkcell input").length,warn:/이 화면을 떠나면 다시 볼 수 없습니다/.test(t),copyAll:!!document.getElementById("al-copy-all"),retry:!!document.getElementById("al-retry"),prog:document.getElementById("al-progress-text").textContent,bar:c.querySelector(".al-progress > i").style.width};})()');
  const leave = await ev('(function(){var e=new Event("beforeunload",{cancelable:true});window.dispatchEvent(e);return e.defaultPrevented;})()');
  return commits.length === 2 && commits.every(c => c === 'POST commit seqs=30') && r.links === 29 && r.warn && r.copyAll && r.retry && r.bar === '100%' && leave ? true : JSON.stringify({ commits, r, leave });
});
shots.result = await shot('al_result');
await check('결과 닫기 → 링크 확인 모달 → 닫은 뒤 다시 볼 곳 없음(작업 상세에도 setup_url 없음)', async () => {
  await click('#al-result-close'); await until('document.querySelector(".modal")'); await clickText('.modal-foot .btn', '닫기'); await sleep(200);
  const gone = await ev('!document.getElementById("al-result-card")');
  await clickText('#al-subbar .tab', '작업 기록'); await until('document.querySelector("#al-jobs-host tbody tr")');
  await clickText('#al-jobs-host .btn', '상세'); await until('document.querySelector(".modal") && /다시 나오지 않습니다/.test(document.querySelector(".modal").textContent)');
  const noUrl = await ev('document.querySelector(".modal").textContent.indexOf("token=")<0');
  await clickText('.modal-foot .btn', '닫기');
  return gone && noUrl ? true : 'gone=' + gone + ' noUrl=' + noUrl;
});
await check('사람 묶음 — 칸 3(연결·후보·빈칸) · 라디오 기본 「연결 안 함」 · D-328 · 설정 링크 재발급(1회 모달)', async () => {
  await clickText('#al-subbar .tab', '사람 묶음');
  await ev('(function(){document.querySelector("#al-person-host").scrollIntoView();return true;})()');
  // 계정 목록의 사람 링크로 연다
  await clickText('#al-subbar .tab', '계정 목록'); await until('document.querySelector("#al-acc-host .al-person-link")');
  await clickText('#al-acc-host .al-person-link', 'kim');
  await until('document.querySelectorAll("#al-person-host .al-cell").length===3');
  const r = await ev('(function(){var h=document.getElementById("al-person-host");var picked=[].slice.call(h.querySelectorAll("input[type=radio]:checked")).map(function(x){return x.value;});var x=' + XSS_CHECK + ';return {picked:picked,empty:h.querySelectorAll(".al-cell.is-empty").length,linked:h.querySelectorAll(".al-cell.is-linked").length,d328:/미만 계정에 연결/.test(h.textContent),setup:!!document.getElementById("al-setup-again"),xss:x};})()');
  await click('#al-setup-again'); await until('document.querySelector(".modal")'); await clickText('.modal-foot .btn', '새로 만들기');
  await until('document.querySelector(".modal") && /1회만 표시됩니다/.test(document.querySelector(".modal").textContent)');
  await clickText('.modal-foot .btn', '확인했습니다');
  return r.picked.join(',') === ',' && r.empty === 1 && r.linked === 1 && r.d328 && r.setup && r.xss.xss === 0 && r.xss.imgs === 0 ? true : JSON.stringify(r);
});
await check('사람 묶음 — 후보 고르기 + 해제 체크 → plan(link·unlink)', async () => {
  await ev('(function(){var r=document.querySelector(\'#al-person-host [data-al-cell="manager"] input[value="e1"]\');r.click();var u=document.querySelector(\'#al-person-host [data-al-cell="admin"] input[type=checkbox]\');u.click();return true;})()');
  await click('#al-person-go');
  await until('document.querySelector(".modal") && /미리보기: 아직/.test(document.querySelector(".modal h3").textContent)', 8000);
  const ops = (LOG.last.items || []).map(i => i.op + ':' + i.external_id + ':' + (i.target ? i.target.uuid || i.target.ref : '-')).sort().join(',');
  await clickText('.modal-foot .btn', '취소'); await sleep(300);
  return ops === 'link:e1:' + U1 + ',unlink:e3:-' && LOG.calls[LOG.calls.length - 1] === 'POST cancel' ? true : ops + ' last=' + LOG.calls[LOG.calls.length - 1];
});
await check('이메일 무리 — 기본 체크 0 · 같은 시스템 하나만 · 새 계정 미리보기 = create_link + link(ref)', async () => {
  await until('document.querySelectorAll("#al-groups-host .al-group").length===2');
  const c0 = await ev('document.querySelectorAll("#al-groups-host input[type=checkbox]:checked").length');
  await ev('(function(){var g=document.querySelector(\'[data-al-group="0"]\');[].forEach.call(g.querySelectorAll("input[type=checkbox]"),function(c){c.click();});return true;})()');
  await clickText('[data-al-group="0"] .btn', '이 묶음으로 새 계정');
  await until('document.querySelector(".modal") && /미리보기: 아직/.test(document.querySelector(".modal h3").textContent)', 8000);
  const b = LOG.last;
  await clickText('.modal-foot .btn', '취소'); await sleep(200);
  const shape = (b.items || []).map(i => i.op + ':' + (i.target && i.target.ref)).join(',') + '|' + (b.new_users || []).map(n => n.ref + ':' + n.email).join(',');
  return c0 === 0 && shape === 'create_link:g1,link:g1|g1:lee@example.test' ? true : 'c0=' + c0 + ' ' + shape;
});
await check('파일 가져오기 — service_secret 은 보내지 않는다 · service_id 다르면 막힘', async () => {
  await clickText('#al-subbar .tab', '시스템');
  const r = await ev(`(async function(){
    var sel=document.querySelector('#al-import select'); sel.value='admin'; sel.dispatchEvent(new Event('change'));
    var inp=document.querySelector('#al-import input[type=file]');
    var dt=new DataTransfer(); dt.items.add(new File([JSON.stringify({service_id:'manager',service_secret:'SHOULD_NOT_SEND',accounts:[{external_id:'a',status:'active'}]})],'x.json',{type:'application/json'}));
    inp.files=dt.files; inp.dispatchEvent(new Event('change'));
    await new Promise(function(r){setTimeout(r,300);});
    var blocked=document.getElementById('al-imp-go').disabled;
    sel.value='manager'; sel.dispatchEvent(new Event('change'));
    var open=!document.getElementById('al-imp-go').disabled;
    document.getElementById('al-imp-go').click();
    await new Promise(function(r){setTimeout(r,500);});
    return {blocked:blocked,open:open,done:/가져오기 완료/.test(document.getElementById('al-import').textContent)};
  })()`);
  const sent = LOG.last && LOG.last.action === 'import' ? LOG.last : null;
  return r.blocked && r.open && r.done && sent && !('service_secret' in sent.file) && sent.service_id === 'manager' ? true : JSON.stringify(r) + ' sent=' + JSON.stringify(sent);
});
await check('superadmin 쓰기 버튼 열림 · 콘솔 오류 0', async () => {
  const n = await ev('({open:document.querySelectorAll(\'[data-al-write="open"]\').length,locked:document.querySelectorAll(\'[data-al-write="locked"]\').length})');
  return n.open > 5 && n.locked === 0 && errors.length === 0 ? true : JSON.stringify(n) + ' errors=' + JSON.stringify(errors.slice(0, 4));
});

// ============================================================ 2. admin(읽기) · manager
ROLE.mode = 'admin';
await check('admin: 탭 보임 · 쓰기 버튼 전부 비활성(이유 title) · 재수집 요청은 활성 · 파일 입력 비활성', async () => {
  await load({ theme: '1', palette: 'teal', mode: 'light', layout: 'side' });
  await clickText('#al-subbar .tab', '계정 목록'); await until('document.querySelectorAll("#al-acc-host tbody tr").length>=31');
  return ev('(function(){var open=document.querySelectorAll(\'[data-al-write="open"]\').length;var locked=[].slice.call(document.querySelectorAll(\'[data-al-write="locked"]\'));var allDis=locked.every(function(b){return b.disabled&&/superadmin/.test(b.title);});var sync=[].every.call(document.querySelectorAll("[data-al-sync]"),function(b){return !b.disabled;});var fileDis=document.querySelector("#al-import input[type=file]").disabled;var notice=/읽기 전용 \\(관리자\\)/.test(document.getElementById("al-root").textContent);return open===0&&locked.length>=6&&allDis&&sync&&fileDis&&notice?true:JSON.stringify({open:open,locked:locked.length,allDis:allDis,sync:sync,fileDis:fileDis,notice:notice});})()');
});
shots.admin = await shot('al_admin_readonly');
ROLE.mode = 'manager';
await check('manager: 계정 연결 메뉴·패널 없음(D-36 노드 제거)', async () => {
  await load({ theme: '1', palette: 'teal', mode: 'light', layout: 'side' });
  await until('document.querySelectorAll(".nav a[data-key]").length>=3');
  return ev('!document.querySelector(\'.nav a[data-key="links"]\') && !document.querySelector(\'[data-tab-panel="links"]\') && !document.getElementById("al-root")');
});

// ============================================================ 3. 테마 표본 (2 × 3 × 2) + 375px
ROLE.mode = 'superadmin';
const rows = [];
async function cell(tag, prefs, sub) {
  await load(prefs);
  if (sub) { await clickText('#al-subbar .tab', sub); await until(sub === '계정 목록' ? 'document.querySelectorAll("#al-acc-host tbody tr").length>=31' : 'document.querySelectorAll("#al-person-host .al-cell").length===3 || document.querySelector("#al-groups-host .al-group")'); await sleep(150); }
  const r = await ev(PROBE);
  const ok = r.audit.ok && r.contrast.failCount === 0 && !r.overflow && errors.length === 0;
  rows.push({ tag, ok, audit: r.audit.wrong.length, contrast: r.contrast.failCount, worst: r.contrast.worst, fails: r.contrast.fails.slice(0, 4), overflow: r.overflow, errors: errors.slice(0, 4) });
  process.stdout.write(ok ? '.' : 'F');
  return r;
}
for (const th of ['1', '2']) for (const pal of ['teal', 'lab', 'white']) for (const md of ['light', 'dark']) {
  await cell(['acc', 't' + th, pal, md].join('_'), { theme: th, palette: pal, mode: md, layout: 'side' }, '계정 목록');
}
for (const md of ['light', 'dark']) { await cell('sys_t2_lab_' + md, { theme: '2', palette: 'lab', mode: md, layout: 'top', toprow: 'inline', topnav: 'smart' }, null); }
// 미리보기 모달 대비(테마 2 어둡게)
await check('미리보기 모달 — 대비·audit (t1 teal light · t2 lab dark)', async () => {
  const out = [];
  for (const pr of [{ theme: '1', palette: 'teal', mode: 'light' }, { theme: '2', palette: 'lab', mode: 'dark' }]) {
    await load(Object.assign({ layout: 'side' }, pr));
    await clickText('#al-subbar .tab', '계정 목록'); await until('document.querySelectorAll("#al-acc-host tbody tr").length>=31');
    await click('#al-acc-host thead input[type=checkbox]'); await clickText('#al-sel-bar .btn', '새 통합로그인 계정 만들어 이관');
    await until('document.querySelector(".modal")'); await clickText('.modal-foot .btn', '미리보기');
    await until('document.querySelector(".modal") && /미리보기: 아직/.test(document.querySelector(".modal h3").textContent)', 8000);
    const r = await ev(PROBE); out.push({ pr: pr.palette + pr.mode, audit: r.audit.ok, cf: r.contrast.failCount, fails: r.contrast.fails.slice(0, 3), err: errors.length });
    if (pr.mode === 'dark') { shots.previewDark = await shot('al_preview_t2_lab_dark'); }
  }
  return out.every(o => o.audit && o.cf === 0 && o.err === 0) ? true : JSON.stringify(out);
});
await viewport(375, 812, true);
for (const md of ['light', 'dark']) {
  await cell('m375_acc_' + md, { theme: '1', palette: 'teal', mode: md, layout: 'side' }, '계정 목록');
  await cell('m375_person_' + md, { theme: '1', palette: 'teal', mode: md, layout: 'side' }, '사람 묶음');
}
shots.mobile = await shot('al_m375_person_dark');
await viewport(1440, 1000, false);

const report = { checks, rows, shots, calls: LOG.calls.length };
fs.writeFileSync(path.join(OUT, 'al_ui.json'), JSON.stringify(report, null, 1));
const cf = checks.filter(c => !c.ok), rf = rows.filter(r => !r.ok);
console.log('\nchecks ' + checks.length + ' fail ' + cf.length + ' · theme cells ' + rows.length + ' fail ' + rf.length);
cf.forEach(c => console.log('  CHECK FAIL: ' + c.name + ' -> ' + c.got));
rf.forEach(r => console.log('  CELL FAIL: ' + r.tag + ' ' + JSON.stringify(r)));
ws.close(); killChrome();
process.exit(cf.length || rf.length ? 1 : 0);
