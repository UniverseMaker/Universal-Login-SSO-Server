// ============================================================================
// tests/ui/kv_tables.mjs — 카드 안 키-값 표·목록 표 모양 점검 (v1.8.11)
//
// **배포물이 아니다.** `php tests/run.php` 에 들어 있지 않다(브라우저가 필요하다). 릴리스 전에 사람이 돌린다.
// CDP 부분과 PROBE(audit·대비·넘침)는 g16_login.mjs 와 같은 코드다 — 판정이 갈리지 않게 함께 고친다.
//
// 무엇을 하나 — 키-값 표(table.kv: 마이페이지 내 정보 · 관리자 회원 상세)와 목록 표(table.table: 로그인 기기 ·
//   관리자 회원 목록)를 1440·375 × 밝게·어둡게로 연다. 칸마다 RUI.audit · 대비 · 넘침 · 콘솔 오류 · 스크린샷과
//   모양 판정: kv 는 표 바깥 테두리·행 줄·세로줄 0, 목록 표는 세로줄 0(가로줄만).
// API 는 **가로챈다**(Fetch). `/server/` 요청은 아래 고정 응답 — 운영 서버·DB 에 붙지 않는다.
//
// 사용
//   node tests/ui/kv_tables.mjs --base=http://127.0.0.1:<port> --out=<폴더> [--only=func|cells] [--expect=G21] [--tag=after]
//   종료코드: 0 통과 · 1 실패 있음 · 2 인자/실행 오류
// ============================================================================
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';

const args = Object.fromEntries(process.argv.slice(2).map(a => { const m = /^--([^=]+)(?:=(.*))?$/.exec(a); return m ? [m[1], m[2] ?? true] : [a, true]; }));
const BASE = String(args.base || '').replace(/\/$/, '');
const OUT = args.out ? path.resolve(String(args.out)) : '';
const EXPECT = String(args.expect || 'G21');
const TAG = String(args.tag || 'after');
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(BASE) || !OUT) {
  console.error('usage: node kv_tables.mjs --base=http://127.0.0.1:<port> --out=<dir> [--only=func|cells] [--expect=G21] [--tag=after]');
  process.exit(2);
}
if (/:8080$/.test(BASE)) { console.error('8080 은 운영 포트다. 점검용 서버를 따로 띄워라.'); process.exit(2); }
const CHROME = [args.chrome, 'C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].filter(Boolean).find(p => fs.existsSync(p));
if (!CHROME) { console.error('Chrome/Edge 를 찾지 못했다'); process.exit(2); }
const PORT = 9450 + Math.floor(Math.random() * 200);
fs.mkdirSync(path.join(OUT, 'shots'), { recursive: true });

// ---------------------------------------------------------------- 고정 응답
const now = '2026-11-11T09:30:00Z';
const USER = {
  uuid: '7f1c2a9e-0000-4000-8000-00000000a001', username: 'tester', email: 'tester@example.test', email_verified: true,
  name: '시험 사용자', nickname: '', phone: null, timezone: 'Asia/Seoul', locale: 'ko-KR', marketing_agreed: false,
  role: 'user', role_rank: 0, account_level: 'verified', account_level_rank: 20,
  billing_tier: 'free', billing_tier_rank: 0, billing_active: true, billing_expires_at: null,
  status: 'active', points: 1200, total_points_earned: 1500, total_points_spent: 300,
  created_at: '2026-08-25T01:00:00Z', last_login_at: now
};
const ADMIN = Object.assign({}, USER, { username: 'admin_with_a_very_long_identifier_for_ellipsis', name: '관리자 이름이 아주 길어서 한 줄에 다 들어가지 않는 경우', role: 'admin', role_rank: 20 });
const S = { admin: false, calls: [] };
function envelope(data) { return { ok: true, data, error: null }; }
function page(items) { return { items, total: items.length, page: 1, per_page: 20, pages: items.length ? 1 : 0 }; }
function fixtureFor(url, postData) {
  const u = new URL(url);
  const i = u.pathname.indexOf('/server/');
  if (i < 0) { return null; }
  const p = u.pathname.slice(i + '/server'.length).replace(/\.php$/, '');
  S.calls.push(p);
  const user = S.admin ? ADMIN : USER;
  switch (p) {
    case '/api/auth/csrf': return envelope({ csrf_token: 'test-csrf' });
    case '/api/me/index': return envelope({ user, session: { session_id: 's1', created_at: now, expires_at: now } });
    case '/api/me/points': return envelope(Object.assign(page([
      { id: 2, delta: -100, balance_after: 1200, reason: 'usage', ref_type: 'chat', ref_id: 'msg_1', service_id: 'demo', memo: null, created_at: now },
      { id: 1, delta: 1300, balance_after: 1300, reason: 'admin_adjust', ref_type: null, ref_id: null, service_id: null, memo: '가입 축하', created_at: '2026-08-25T01:00:00Z' }
    ]), { points: 1200, total_points_earned: 1500, total_points_spent: 300 }));
    case '/api/me/sessions': return envelope(page([
      { session_id: 's1', is_current: true, ip: '127.0.0.1', user_agent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130', created_at: now, last_seen_at: now, expires_at: now }
    ]));
    case '/api/me/login_logs': case '/api/me/login-logs': return envelope(page([
      { id: 1, result: 'success', ip: '127.0.0.1', user_agent: 'Chrome/130', service_id: null, created_at: now }
    ]));
    case '/api/me/services': return envelope(page([]));
    case '/api/admin/users': return envelope(Object.assign(page([Object.assign({}, USER, { session_count: 1, login_count: 34 }), Object.assign({}, ADMIN, { uuid: 'u2', session_count: 2, login_count: 211 })]), { filters_applied: {} }));
    case '/api/admin/user_detail': return envelope({ user: Object.assign({}, USER, { email_verified_at: now, memo: null }), recent_logins: [{ id: 1, result: 'success', ip: '127.0.0.1', user_agent: 'Chrome/130', service_id: null, created_at: now }] });
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
await send('Fetch.enable', { patterns: [{ urlPattern: '*/server/*', requestStage: 'Request' }, { urlPattern: 'https://app.example.test/*', requestStage: 'Request' }] });

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
const rows = [];
const VIEWS = [
  ['mypage-info', '/public/mypage.html#info', '!document.getElementById("app").hidden && document.querySelector("#info-readonly table.kv")', false],
  ['mypage-sessions', '/public/mypage.html#sessions', '!document.getElementById("app").hidden && document.querySelector("#sessions-list table")', false],
  ['admin-users', '/public/admin.html#users', '!document.getElementById("app").hidden && document.querySelector("table.table tbody tr")', true],
  ['admin-user-detail', '/public/admin.html#users', '!document.getElementById("app").hidden && document.querySelector("table.table tbody tr")', true]
];
for (const [name, url, ready, admin] of VIEWS) for (const w of [1440, 375]) for (const md of ['light', 'dark']) {
  S.admin = admin;
  await viewport(w, w < 768 ? 812 : 900);
  await load(url, { theme: '1', palette: 'teal', mode: md }, ready);
  if (name === 'admin-user-detail') { await evaluate('document.querySelector("table.table tbody tr").click(); true'); await waitFor('document.querySelector(".modal table.kv, [role=dialog] table.kv")', 4000); await sleep(200); }
  else if (name.indexOf('mypage-') === 0) { await evaluate('(function(){var a=document.querySelector(".nav a[data-key=' + name.slice(7) + ']"); if (a) a.click(); return true;})()'); await sleep(300); }
  const r = await evaluate(PROBE); const sh = await evaluate(SHAPE);
  const file = path.join(OUT, 'shots', [TAG, name, w, md].join('_') + '.jpg');
  await shot(file);
  const shapeOk = sh.kvLines === 0 && sh.kvWrap === 0 && sh.listVertical === 0;
  const ok = r.audit.ok && r.contrast.failCount === 0 && !r.overflow && errors.length === 0 && (args.noshape || shapeOk);
  rows.push({ name, w, md, ok, shape: sh, audit: r.audit.wrong, c: r.contrast.failCount, cf: r.contrast.fails.slice(0, 3), o: r.overflow, e: errors.slice(0, 3) });
  process.stdout.write(ok ? '.' : 'F');
}
const fails = rows.filter(r => !r.ok);
console.log('\ncells ' + rows.length + ' fail ' + fails.length);
fails.forEach(r => console.log('  FAIL ' + [r.name, r.w, r.md].join('_') + ' shape=' + JSON.stringify(r.shape) + ' audit=' + JSON.stringify(r.audit.map(x => x.item + ':' + x.got)) + ' c=' + r.c + JSON.stringify(r.cf) + ' o=' + r.o + ' e=' + JSON.stringify(r.e)));
ws.close(); killChrome();
try { fs.rmSync(profile, { recursive: true, force: true }); } catch (e) { /* noop */ }
process.exit(fails.length ? 1 : 0);
