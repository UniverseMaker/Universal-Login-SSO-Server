// ============================================================================
// tests/ui/platform_console.mjs — 관리자 콘솔 「플랫폼」 6탭 점검 (v1.9.0, 설계 116 §8)
//
// **배포물이 아니다.** `php tests/run.php` 에 들어 있지 않다(브라우저가 필요하다). 릴리스 전에 사람이 돌린다.
// CDP 부분과 PROBE·SHAPE 는 kv_tables.mjs 와 같은 코드다 — 판정이 갈리지 않게 함께 고친다.
//
// 무엇을 하나
//   기능 F1~F7 — 메뉴 6개 · 전역 설정 게시(expected_version) · 운영 로그(XSS 문자열이 글자로만 보이는가) ·
//     감사 체인 검사 · 테이블(서비스→컬렉션→문서) · 사용량 타일 · 접근 권한(서비스·회원별, 서버 판정 사유 라벨)
//   칸 — 6탭 × 1440·375 × 밝게·어둡게: RUI.audit · 대비 · 넘침 · 콘솔 오류 · kv 줄 0 · 목록 표 세로줄 0 · 스크린샷
// API 는 **가로챈다**(Fetch). `/server/` 요청은 아래 고정 응답 — 운영 서버·DB 에 붙지 않는다. 키는 117 보고 표 그대로.
//
// 변이(D-186): `--mutate` 를 주면 admin-platform.js 를 가로채 게시 요청의 expected_version 을 0 으로 바꿔 낸다.
//   이때 F2b 가 실패(종료코드 1)해야 하네스가 눈뜬 것이다. 변이 없이 0, 변이로 1 — 둘 다 확인한다.
//
// 사용
//   node tests/ui/platform_console.mjs --base=http://127.0.0.1:<port> --out=<폴더> [--mutate] [--only=func|cells]
//   종료코드: 0 통과 · 1 실패 있음 · 2 인자/실행 오류
// ============================================================================
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';

const args = Object.fromEntries(process.argv.slice(2).map(a => { const m = /^--([^=]+)(?:=(.*))?$/.exec(a); return m ? [m[1], m[2] ?? true] : [a, true]; }));
const BASE = String(args.base || '').replace(/\/$/, '');
const OUT = args.out ? path.resolve(String(args.out)) : '';
const TAG = args.mutate ? 'mutate' : 'after';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(BASE) || !OUT) {
  console.error('usage: node platform_console.mjs --base=http://127.0.0.1:<port> --out=<dir> [--mutate] [--only=func|cells]');
  process.exit(2);
}
if (/:8080$/.test(BASE)) { console.error('8080 은 운영 포트다. 점검용 서버를 따로 띄워라.'); process.exit(2); }
const CHROME = [args.chrome, 'C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].filter(Boolean).find(p => fs.existsSync(p));
if (!CHROME) { console.error('Chrome/Edge 를 찾지 못했다'); process.exit(2); }
const PORT = 9650 + Math.floor(Math.random() * 200);
fs.mkdirSync(path.join(OUT, 'shots'), { recursive: true });

// ---------------------------------------------------------------- 고정 응답 (117 보고 「data 최상위 키」 표)
const now = '2026-11-11T09:30:00Z';
const UU = '7f3c2a9e-0000-4000-8000-00000000a002';
const USER = {
  uuid: '7f1c2a9e-0000-4000-8000-00000000a001', username: 'opsuser', email: 'admin@example.test', email_verified: true,
  name: '관리자', nickname: '', phone: null, timezone: 'Asia/Seoul', locale: 'ko-KR', marketing_agreed: false,
  role: 'superadmin', role_rank: 30, account_level: 'verified', account_level_rank: 20,
  billing_tier: 'free', billing_tier_rank: 0, billing_active: true, billing_expires_at: null,
  status: 'active', points: 0, total_points_earned: 0, total_points_spent: 0, created_at: '2026-08-25T01:00:00Z', last_login_at: now
};
const XSS = '<img src=x onerror="window.__xss=1">';
const COLL = { name: 'notes', read_rule: 'owner', write_rule: 'owner', indexes: [{ field: 'status', type: 'str' }, { field: 'due', type: 'int' }], max_docs: 5000, max_doc_bytes: 16384, doc_count: 2, reindex_pending: false };
const S = { calls: [], posts: [] };
function envelope(data) { return { ok: true, data, error: null }; }
function fixtureFor(url, postData) {
  const u = new URL(url);
  const i = u.pathname.indexOf('/server/');
  if (i < 0) { return null; }
  const p = u.pathname.slice(i + '/server'.length).replace(/\.php$/, '');
  const q = u.searchParams;
  let body = null; try { body = postData ? JSON.parse(postData) : null; } catch (e) { body = null; }
  S.calls.push(p); if (body) { S.posts.push({ p, body }); }
  switch (p) {
    case '/api/auth/csrf': return envelope({ csrf_token: 'test-csrf' });
    case '/api/me/index': return envelope({ user: USER, session: { session_id: 's1', created_at: now, expires_at: now } });
    case '/api/admin/services': return envelope({ items: [{ service_id: 'task', service_name: 'Demo App B', status: 'active' }, { service_id: 'learning', service_name: 'Demo App C', status: 'active' }], total: 2, page: 1, per_page: 100 });
    case '/api/admin/platform_config':
      if (body && body.action === 'publish') { return envelope({ published: { version: 8, etag: 'e8', published_at: now } }); }
      if (q.get('diff')) { return envelope({ added: ['notice.banner'], removed: [], changed: [{ key: 'export.max_rows', before: 3000, after: 5000 }] }); }
      return envelope({ scope: q.get('scope') || '*', dirty: true, published: { version: 7, etag: 'c1f0aa11bb22cc33dd44ee55ff660011', published_at: now },
        drafts: [{ key: 'notice.banner', value: XSS, conditions: [], description: '상단 안내', updated_at: now },
          { key: 'export.max_rows', value: 5000, conditions: [{ when: { role_min: 'admin' }, value: 50000 }], description: null, updated_at: now }],
        versions: [{ version: 7, source: 'publish', from_version: null, note: '정기 게시', published_by: 'opsuser', published_at: now },
          { version: 6, source: 'rollback', from_version: 4, note: null, published_by: 'opsuser', published_at: now }] });
    case '/api/admin/platform_logs':
      if (body && body.action === 'verify') { return envelope({ checked: 120, ok: true, first_bad_seq: null, last_seq: 10233 }); }
      if (q.get('kind') === 'audit') {
        return envelope({ next_cursor: null, items: [{ seq: 10233, service_id: 'task', event_id: 'e0000000-0000-4000-8000-000000000001', ts: now, received_at: now,
          actor: { type: 'admin', uuid: UU, local_user: 'opsuser' }, action: 'user.role.update', target: { type: 'user', id: 'hong' }, result: 'success',
          changes: { role: { before: 'user', after: 'admin' } }, ip: '203.0.113.7', user_agent: 'Mozilla/5.0', request_id: 'req-9a1d', reason: '권한 요청 승인', sender_ip: '127.0.0.1' }] });
      }
      return envelope({ next_cursor: 'eyJ0cyI6IngiLCJpZCI6MX0', items: [
        { id: 2, service_id: 'task', event_id: 'e0000000-0000-4000-8000-000000000002', ts: now, received_at: now, level: 'error', event: 'job.export.failed', message: XSS, tags: ['export', 'batch'], uuid: UU, local_user: 'hong', request_id: 'req-9a1c', source: 's2s', sender_ip: '127.0.0.1', data: { job_id: 42, ms: 5003 } },
        { id: 1, service_id: 'learning', event_id: 'e0000000-0000-4000-8000-000000000003', ts: now, received_at: now, level: 'info', event: 'login.ok', message: '로그인', tags: [], uuid: null, local_user: null, request_id: null, source: 'user', sender_ip: '127.0.0.1', data: null }] });
    case '/api/admin/platform_tables':
      if (q.get('doc_id')) { return envelope({ doc: { doc_id: 'a6e1', version: 2, owner_uuid: UU, data: { title: XSS }, created_at: now, updated_at: now } }); }
      if (q.get('collection')) { return envelope({ collection: COLL, next_cursor: null, items: [{ doc_id: 'a6e1', version: 2, owner_uuid: UU, preview: JSON.stringify({ title: XSS }), truncated: false, updated_at: now }] }); }
      return envelope({ items: [Object.assign({ status: 'active', updated_at: now }, COLL)] });
    case '/api/admin/platform_usage':
      return envelope({ items: [{ service_id: 'task', day: '2026-11-11', metric: 'log_events', value: 1200 }, { service_id: 'task', day: '2026-11-11', metric: 'doc_reads', value: 40 }],
        totals: { log_events: 1200, doc_reads: 40 } });
    case '/api/admin/service_access':
      if (q.get('uuid')) { return envelope({ uuid: UU, systems: [{ service_id: 'task', service_name: 'Demo App B', access_rule: 'granted', access_grant: 'deny', allowed: false, reason: 'denied_by_grant', features: ['export'] }] }); }
      return envelope({ service_id: q.get('service_id'), access_rule: 'granted', can_list_users: false, features: [{ id: 'export', name: '내보내기', default: true }],
        grants: { items: [{ uuid: UU, username: 'hong', access_grant: 'deny', features: null }], total: 1, page: 1, per_page: 50 } });
    case '/api/admin/stats': return envelope({ generated_at: now, users: { total: 2, active: 2, pending: 0, suspended: 0, dormant: 0, withdrawn: 0, new_today: 0, new_7d: 0, new_30d: 0 }, logins: { today: 1, success_7d: 1, fail_7d: 0, unique_users_7d: 1 }, points: { total_balance: 0, earned_7d: 0, spent_7d: 0 }, services: { total: 0, active: 0, disabled: 0 }, sessions: { active: 1 }, by_account_level: [], by_role: [], by_billing_tier: [] });
    default:
      return { ok: false, data: null, error: { code: 'NOT_FOUND', message: '점검용 고정 응답이 없습니다(' + p + ').', field: null } };
  }
}

// ---------------------------------------------------------------- CDP
function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }
async function waitJson(url, tries = 160) {
  for (let i = 0; i < tries; i++) { try { const r = await fetch(url); if (r.ok) { return await r.json(); } } catch (e) { /* 아직 */ } await sleep(250); }
  throw new Error('CDP 엔드포인트가 열리지 않았다: ' + url);
}
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'ul-g16-'));
const chrome = spawn(CHROME, ['--headless=new', '--remote-debugging-port=' + PORT, '--user-data-dir=' + profile, '--no-first-run',
  '--no-default-browser-check', '--disable-gpu', '--hide-scrollbars', '--window-size=1400,900', '--disable-extensions', 'about:blank'], { stdio: 'ignore' });
let exiting = false;
function killChrome() {
  if (exiting) { return; } exiting = true;
  try { spawn('taskkill', ['/PID', String(chrome.pid), '/T', '/F'], { stdio: 'ignore' }); } catch (e) { /* noop */ }
}
process.on('exit', killChrome);

await waitJson('http://127.0.0.1:' + PORT + '/json/version');
const tgt = await (await fetch('http://127.0.0.1:' + PORT + '/json/new?about:blank', { method: 'PUT' })).json();
const ws = new WebSocket(tgt.webSocketDebuggerUrl);
await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej; });
let seq = 0; const pending = new Map(); const listeners = [];
ws.onmessage = ev => {
  const m = JSON.parse(ev.data);
  if (m.id && pending.has(m.id)) { const p = pending.get(m.id); pending.delete(m.id); m.error ? p.rej(new Error(m.error.message)) : p.res(m.result); return; }
  listeners.forEach(f => f(m));
};
function send(method, params = {}) { const id = ++seq; ws.send(JSON.stringify({ id, method, params })); return new Promise((res, rej) => pending.set(id, { res, rej })); }

let errors = [];
listeners.push(m => {
  if (m.method === 'Fetch.requestPaused') {
    if (args.mutate && /\/assets\/admin-platform\.js/.test(m.params.request.url)) {   // 변이: 게시의 expected_version 을 0 으로
      fetch(m.params.request.url).then(r => r.text()).then(t => {
        const bad = t.replace('expected_version: d.published ? d.published.version : 0', 'expected_version: 0');
        if (bad === t) { console.error('변이를 심을 자리를 찾지 못했다 — 하네스를 고쳐라'); process.exit(2); }
        return send('Fetch.fulfillRequest', { requestId: m.params.requestId, responseCode: 200, responseHeaders: [{ name: 'Content-Type', value: 'text/javascript; charset=utf-8' }], body: Buffer.from(bad).toString('base64') });
      }).catch(() => {});
      return;
    }
    const body = fixtureFor(m.params.request.url, m.params.request.postData);
    if (!body) { send('Fetch.continueRequest', { requestId: m.params.requestId }).catch(() => {}); return; }
    if (body.__html) {
      send('Fetch.fulfillRequest', { requestId: m.params.requestId, responseCode: 200, responseHeaders: [{ name: 'Content-Type', value: 'text/html; charset=utf-8' }], body: Buffer.from(body.__html).toString('base64') }).catch(() => {});
      return;
    }
    send('Fetch.fulfillRequest', {
      requestId: m.params.requestId, responseCode: body.__code || (body.ok ? 200 : 404),
      responseHeaders: [{ name: 'Content-Type', value: 'application/json; charset=utf-8' }],
      body: Buffer.from(JSON.stringify(Object.assign({}, body, { __code: undefined }))).toString('base64')
    }).catch(() => {});
  } else if (m.method === 'Runtime.exceptionThrown') {
    errors.push('exception: ' + (m.params.exceptionDetails.exception?.description || m.params.exceptionDetails.text));
  } else if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error') {
    errors.push('console.error: ' + m.params.args.map(a => a.value ?? a.description).join(' '));
  } else if (m.method === 'Log.entryAdded' && (m.params.entry.level === 'error')) {
    const t = m.params.entry.text || '';
    if (m.params.entry.source === 'network' && /status of (40[0-9]|42[0-9]|500)/.test(t)) { return; }   // 가로챈 오류 응답은 의도
    errors.push('log(' + m.params.entry.source + '): ' + t);
  }
});
await send('Page.enable'); await send('Runtime.enable'); await send('Log.enable');
await send('Fetch.enable', { patterns: [{ urlPattern: '*/server/*', requestStage: 'Request' }].concat(args.mutate ? [{ urlPattern: '*/assets/admin-platform.js*', requestStage: 'Request' }] : []) });

async function evaluate(expr) {
  const r = await send('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true });
  if (r.exceptionDetails) { throw new Error('evaluate: ' + (r.exceptionDetails.exception?.description || r.exceptionDetails.text)); }
  return r.result.value;
}
async function viewport(w, h) { await send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: w < 768 }); }
async function waitFor(expr, ms = 4000) {
  const t0 = Date.now();
  while (Date.now() - t0 < ms) { try { if (await evaluate('(function(){try{return !!(' + expr + ');}catch(e){return false;}})()')) { return true; } } catch (e) { /* 이동 중 */ } await sleep(100); }
  return false;
}
async function load(url, prefs, readyExpr) {
  errors = []; S.posts = [];
  await send('Page.navigate', { url: BASE + '/public/version.txt' });
  await sleep(150);
  await evaluate('localStorage.clear(); sessionStorage.clear(); localStorage.setItem("rui_prefs", ' + JSON.stringify(JSON.stringify(prefs || { theme: '1' })) + '); true');
  const loaded = new Promise(res => { const f = m => { if (m.method === 'Page.loadEventFired') { listeners.splice(listeners.indexOf(f), 1); res(); } }; listeners.push(f); });
  await send('Page.navigate', { url: BASE + url });
  await Promise.race([loaded, sleep(8000)]);
  await waitFor(readyExpr || 'true');
  await evaluate('document.fonts ? document.fonts.ready.then(function(){return true;}) : true');
  await sleep(150);
}
async function shot(file) {
  const r = await send('Page.captureScreenshot', { format: 'jpeg', quality: 72 });
  fs.writeFileSync(file, Buffer.from(r.data, 'base64'));
}
/** 부품 입력칸에 값을 넣고 제출한다(실제 입력 이벤트 대신 값 대입 + requestSubmit). */
async function submitLogin(id, pw) {
  await evaluate('(function(){var f=document.querySelector(".rui-login-form");f.u.value=' + JSON.stringify(id) + ';f.p.value=' + JSON.stringify(pw) + ';f.requestSubmit();return true;})()');
}

// 페이지 안에서 도는 검사. RUI.audit + 대비 + 넘침 (theme_matrix.mjs 의 PROBE 와 같은 판정)
const PROBE = `(function () {
  function parse(c) { var m = /rgba?\\(([^)]+)\\)/.exec(c || ''); if (!m) return null; var p = m[1].split(/[ ,\\/]+/).filter(Boolean).map(Number); return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 }; }
  function lin(v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }
  function lum(c) { return 0.2126 * lin(c.r) + 0.7152 * lin(c.g) + 0.0722 * lin(c.b); }
  function over(f, b) { var a = f.a; return { r: f.r * a + b.r * (1 - a), g: f.g * a + b.g * (1 - a), b: f.b * a + b.b * (1 - a), a: 1 }; }
  function ratio(a, b) { var x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); }
  var probe = document.createElement('i'); document.body.appendChild(probe);
  function tok(name) { probe.style.color = 'var(' + name + ')'; return parse(getComputedStyle(probe).color); }
  var soft = tok('--rui-accent-soft');
  function bgOf(el) {
    var stack = [], grad = false;
    for (var e = el; e && e.nodeType === 1; e = e.parentElement) {
      var cs = getComputedStyle(e), c = parse(cs.backgroundColor);
      if (/gradient/.test(cs.backgroundImage)) grad = true;
      if (c && c.a > 0) { stack.push(c); if (c.a >= 1) break; }
    }
    var base = { r: 255, g: 255, b: 255, a: 1 };
    if (stack.length && stack[stack.length - 1].a < 1) { var root = parse(getComputedStyle(document.documentElement).backgroundColor); if (root && root.a) base = root; }
    for (var i = stack.length - 1; i >= 0; i--) base = over(stack[i], base);
    return { bg: base, grad: grad };
  }
  var fails = [], checked = 0, worst = 99;
  var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  var seen = new Set();
  while (walker.nextNode()) {
    var n = walker.currentNode; if (!n.nodeValue.trim()) continue;
    var el = n.parentElement; if (!el || seen.has(el)) continue; seen.add(el);
    if (el.closest('[aria-hidden="true"], script, style, noscript, option')) continue;
    var r = el.getBoundingClientRect(); if (!r.width || !r.height) continue;
    if (r.bottom < 0 || r.top > innerHeight * 3) continue;
    var cs = getComputedStyle(el); if (cs.visibility !== 'visible' || parseFloat(cs.fontSize) < 1) continue;
    if (el.closest('button:disabled, [disabled], [aria-disabled="true"], .is-busy')) continue;
    var op = 1; for (var e = el; e && e.nodeType === 1; e = e.parentElement) { op *= Number(getComputedStyle(e).opacity); }
    if (op < 0.05) continue;
    var fg = parse(cs.color); if (!fg) continue; fg.a *= op;
    var b = bgOf(el), f = over(fg, b.bg), cr = ratio(f, b.bg);
    if (b.grad && soft) { var bs = over({ r: soft.r, g: soft.g, b: soft.b, a: 1 }, b.bg); cr = Math.min(cr, ratio(over(fg, bs), bs)); }
    var px = parseFloat(cs.fontSize), bold = Number(cs.fontWeight) >= 700, large = px >= 24 || (bold && px >= 18.66);
    var need = large ? 3 : 4.5; checked++; worst = Math.min(worst, cr);
    if (cr < need) fails.push({ text: n.nodeValue.trim().slice(0, 24), sel: el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\\s+/).join('.') : ''), ratio: Math.round(cr * 100) / 100, need: need });
  }
  probe.remove();
  var audit = window.RUI && RUI.audit ? RUI.audit() : { ok: false, wrong: [{ item: 'RUI 없음' }] };
  return { audit: { ok: audit.ok, guideline: audit.guideline, checked: audit.checked, wrong: audit.wrong }, contrast: { checked: checked, worst: Math.round(worst * 100) / 100, fails: fails.slice(0, 12), failCount: fails.length },
    overflow: document.documentElement.scrollWidth > innerWidth + 1 ? document.documentElement.scrollWidth : 0 };
})()`;

// ---------------------------------------------------------------- 칸
const SHAPE = `(function () {
  function px(v) { return parseFloat(v) || 0; }
  var kv = [].slice.call(document.querySelectorAll('table.kv')).filter(function (t) { return t.offsetParent; });
  var kvLines = 0, kvWrap = 0;
  kv.forEach(function (t) {
    var w = t.closest('.table-wrap'); if (w) { var c = getComputedStyle(w); kvWrap += px(c.borderTopWidth) + px(c.borderLeftWidth) + px(c.borderRightWidth) + px(c.borderBottomWidth); }
    [].slice.call(t.querySelectorAll('th,td')).forEach(function (c) { var s = getComputedStyle(c); kvLines += px(s.borderBottomWidth) + px(s.borderLeftWidth) + px(s.borderRightWidth) + px(s.borderTopWidth); });
  });
  var vert = 0;
  [].slice.call(document.querySelectorAll('table.table')).filter(function (t) { return t.offsetParent; }).forEach(function (t) {
    [].slice.call(t.querySelectorAll('th,td')).forEach(function (c) { var s = getComputedStyle(c); vert += px(s.borderLeftWidth) + px(s.borderRightWidth); });
  });
  return { kvTables: kv.length, kvLines: kvLines, kvWrap: kvWrap, listVertical: vert };
})()`;

const T = (k) => '[data-tab-panel="' + k + '"]';
const Q = (s) => 'document.querySelector(' + JSON.stringify(s) + ')';
const APP = '!document.getElementById("app").hidden';
const KEYS = ['pconfig', 'plogs', 'paudit', 'ptables', 'pusage', 'access'];
const checks = [];
function check(id, ok, detail) { checks.push({ id, ok: !!ok, detail }); process.stdout.write(ok ? '.' : 'F'); }
function clickText(scope, label) {
  return evaluate('(function(){var b=[].slice.call(document.querySelectorAll(' + JSON.stringify(scope + ' button') + ')).filter(function(x){return x.textContent.trim()===' + JSON.stringify(label) + ' && !x.disabled;})[0]; if(!b) return false; b.click(); return true;})()');
}
function pick(k, value) {
  return evaluate('(function(){var s=' + Q(T(k) + ' select[name=service_id]') + '; if(!s) return false; s.value=' + JSON.stringify(value) + '; s.dispatchEvent(new Event("change")); return true;})()');
}
const rowsIn = (k, extra) => evaluate('document.querySelectorAll(' + JSON.stringify(T(k) + ' ' + (extra || '') + ' table.table tbody tr') + ').length');
const textOf = (k) => evaluate(Q(T(k)) + '.textContent');
const noImg = (k) => evaluate('!' + Q(T(k) + ' img') + ' && window.__xss === undefined');

if (args.only !== 'cells') {
  await viewport(1440, 900);
  // F1 메뉴 6개
  await load('/public/admin.html#pconfig', { theme: '1', palette: 'teal', mode: 'light' }, APP + ' && ' + Q(T('pconfig') + ' table.table tbody tr'));
  const menu = await evaluate('JSON.stringify(' + JSON.stringify(KEYS) + '.map(function(k){var a=document.querySelector(".nav a[data-key="+k+"]");return a?a.textContent.trim():null;}))');
  check('F1 메뉴 6개', JSON.parse(menu).every(Boolean), menu);
  // F2 전역 설정 — 초안 2행 · 게시 요청의 expected_version = 게시 버전(7)
  check('F2a 초안 2 + 게시 이력 2', (await rowsIn('pconfig')) === 4, await rowsIn('pconfig'));
  check('F2c XSS 문자열은 글자', await noImg('pconfig'), null);
  S.posts = [];
  await clickText(T('pconfig'), '게시');
  await waitFor(Q('.modal .modal-foot .btn-primary'));
  await evaluate(Q('.modal .modal-foot .btn-primary') + '.click(); true');
  await waitFor('false', 800);
  const pub = S.posts.filter(x => x.p === '/api/admin/platform_config' && x.body.action === 'publish')[0];
  check('F2b 게시 expected_version=7', pub && pub.body.expected_version === 7 && pub.body.scope === '*', pub && pub.body);
  // F3 운영 로그
  await load('/public/admin.html#plogs', { theme: '1' }, APP + ' && ' + Q(T('plogs') + ' table.table tbody tr'));
  check('F3a 로그 2행', (await rowsIn('plogs')) === 2, await rowsIn('plogs'));
  check('F3b XSS 문자열은 글자', (await noImg('plogs')) && (await textOf('plogs')).indexOf('<img src=x') >= 0, null);
  check('F3c 레벨 라벨 · 더 보기', /오류/.test(await textOf('plogs')) && await evaluate('!!' + Q(T('plogs') + ' .pf-more button')), null);
  // F4 감사 — 체인 검사
  await load('/public/admin.html#paudit', { theme: '1' }, APP + ' && ' + Q(T('paudit') + ' table.table tbody tr'));
  await clickText(T('paudit'), '검사');
  await waitFor(Q(T('paudit') + ' .pf-verify table.kv'));
  check('F4 체인 검사 결과', /정상/.test(await evaluate(Q(T('paudit') + ' .pf-verify') + '.textContent')), null);
  // F5 테이블 — 서비스 → 컬렉션 → 문서
  await load('/public/admin.html#ptables', { theme: '1' }, APP + ' && ' + Q(T('ptables') + ' select[name=service_id]'));
  await pick('ptables', 'task');
  await waitFor(Q(T('ptables') + ' table.table tbody tr'));
  await clickText(T('ptables'), '문서');
  await waitFor(Q(T('ptables') + ' .pf-docs table.table tbody tr'));
  check('F5 컬렉션·문서', (await rowsIn('ptables', '.pf-docs')) === 1 && await noImg('ptables'), null);
  // F6 사용량
  await load('/public/admin.html#pusage', { theme: '1' }, APP + ' && ' + Q(T('pusage') + ' .stat'));
  check('F6 사용량 타일 9', (await evaluate('document.querySelectorAll(' + JSON.stringify(T('pusage') + ' .stat') + ').length')) === 9, null);
  // F7 접근 권한 — 서비스별 + 회원별(서버 판정 사유 라벨)
  await load('/public/admin.html#access', { theme: '1' }, APP + ' && ' + Q(T('access') + ' select[name=service_id]'));
  await pick('access', 'task');
  await waitFor(Q(T('access') + ' table.table tbody tr'));
  const acc = await textOf('access');
  check('F7a 규칙·지정', /허용한 회원만/.test(acc) && /차단/.test(acc) && /내보내기/.test(acc), null);
  await evaluate('(function(){var f=' + Q(T('access') + ' form.toolbar') + '; f.uuid.value=' + JSON.stringify(UU) + '; f.requestSubmit(); return true;})()');
  await waitFor('/차단 지정/.test(' + Q(T('access')) + '.textContent)');
  check('F7b 회원별 사유', /차단 지정/.test(await textOf('access')), null);
  check('F0 콘솔 오류 0', errors.length === 0, errors.slice(0, 3));
}

const rows = [];
if (args.only !== 'func') {
  const prep = {
    ptables: async () => { await pick('ptables', 'task'); await waitFor(Q(T('ptables') + ' table.table tbody tr')); },
    access: async () => { await pick('access', 'task'); await waitFor(Q(T('access') + ' table.table tbody tr')); }
  };
  for (const k of KEYS) for (const w of [1440, 375]) for (const md of ['light', 'dark']) {
    await viewport(w, w < 768 ? 812 : 900);
    await load('/public/admin.html#' + k, { theme: '1', palette: 'teal', mode: md }, APP + ' && ' + Q(T(k) + ' .toolbar'));
    if (prep[k]) { await prep[k](); }
    await sleep(300);
    const r = await evaluate(PROBE); const sh = await evaluate(SHAPE);
    await shot(path.join(OUT, 'shots', [TAG, k, w, md].join('_') + '.jpg'));
    const shapeOk = sh.kvLines === 0 && sh.kvWrap === 0 && sh.listVertical === 0;
    const ok = r.audit.ok && r.contrast.failCount === 0 && !r.overflow && errors.length === 0 && shapeOk;
    rows.push({ name: k, w, md, ok, shape: sh, audit: r.audit.wrong, c: r.contrast.failCount, cf: r.contrast.fails.slice(0, 3), o: r.overflow, e: errors.slice(0, 3) });
    process.stdout.write(ok ? '.' : 'F');
  }
}
const cf = checks.filter(c => !c.ok);
const fails = rows.filter(r => !r.ok);
console.log('\nfunc ' + checks.length + ' fail ' + cf.length + ' · cells ' + rows.length + ' fail ' + fails.length + (args.mutate ? '  (변이 실행 — 실패가 나와야 정상)' : ''));
cf.forEach(c => console.log('  FAIL ' + c.id + ' ' + JSON.stringify(c.detail)));
fails.forEach(r => console.log('  FAIL ' + [r.name, r.w, r.md].join('_') + ' shape=' + JSON.stringify(r.shape) + ' audit=' + JSON.stringify((r.audit || []).map(x => x.item + ':' + x.got)) + ' c=' + r.c + JSON.stringify(r.cf) + ' o=' + r.o + ' e=' + JSON.stringify(r.e)));
fs.writeFileSync(path.join(OUT, TAG + '_platform.json'), JSON.stringify({ checks, rows }, null, 1));
ws.close(); killChrome();
try { fs.rmSync(profile, { recursive: true, force: true }); } catch (e) { /* noop */ }
process.exit(cf.length || fails.length ? 1 : 0);
