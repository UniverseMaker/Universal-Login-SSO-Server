// ============================================================================
// tests/ui/mypage_portal.mjs — 마이페이지(로그인 후 첫 화면) 점검 (v1.8.11)
//
// **배포물이 아니다.** `php tests/run.php` 에 들어 있지 않다(브라우저가 필요하다). 릴리스 전에 사람이 돌린다.
// CDP 부분과 PROBE(audit·대비·넘침)는 g16_login.mjs 와 같은 코드다 — 판정이 갈리지 않게 함께 고친다.
//
// 무엇을 하나 (헤드리스 Chrome/Edge 를 CDP 로 몬다)
//   A) 동작 확인 — 앱 틀(왼쪽 메뉴·위쪽 띠, 로그인 분할 틀 아님) · 첫 화면 「내 시스템」 카드 = RUI.SYSTEMS 중 sso 지원
//      · 카드 주소 = 그 시스템 주소(새 탭 아님) · 관리자에게만 「관리 콘솔」 · 메뉴 8개를 차례로 열어 해당 API 호출 ·
//      860px 이하 서랍 메뉴
//   B) 칸 점검 — mypage × 1440·1024·375px × 공통 테마 1·2 × 밝게·어둡게. 칸마다 RUI.audit() wrong 0 ·
//      guideline 기대값 · 글자 대비 4.5:1(큰 글자 3:1) · 가로 넘침 0 · 콘솔/CSP 오류 0 · 스크린샷
//
// API 는 **가로챈다**(Fetch). `/server/` 요청은 아래 고정 응답 — 운영 서버·DB 에 붙지 않는다.
//
// 사용
//   node tests/ui/mypage_portal.mjs --base=http://127.0.0.1:<port> --out=<폴더> [--only=func|cells] [--expect=G23] [--tag=after]
//   종료코드: 0 통과 · 1 실패 있음 · 2 인자/실행 오류
// ============================================================================
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';

const args = Object.fromEntries(process.argv.slice(2).map(a => { const m = /^--([^=]+)(?:=(.*))?$/.exec(a); return m ? [m[1], m[2] ?? true] : [a, true]; }));
const BASE = String(args.base || '').replace(/\/$/, '');
const OUT = args.out ? path.resolve(String(args.out)) : '';
const EXPECT = String(args.expect || 'G26');
const TAG = String(args.tag || 'after');
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(BASE) || !OUT) {
  console.error('usage: node mypage_portal.mjs --base=http://127.0.0.1:<port> --out=<dir> [--only=func|cells] [--expect=G23] [--tag=after]');
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
const ADMIN = Object.assign({}, USER, { username: 'admin_with_a_very_long_identifier_for_ellipsis', name: '관리자 이름이 아주 길어서 한 줄에 다 들어가지 않는 경우 '.repeat(4), role: 'admin', role_rank: 20 });
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

// ---------------------------------------------------------------- A) 동작 확인
const READY = '!document.getElementById("app").hidden';
const extra = [];
async function check(name, fn) {
  try { const v = await fn(); extra.push({ name, ok: v === true, got: v }); } catch (e) { extra.push({ name, ok: false, got: String(e.message || e) }); }
  process.stdout.write(extra[extra.length - 1].ok ? '+' : 'X');
}
if (args.only !== 'cells') {
  await viewport(1440, 900);
  await check('앱 틀 — 왼쪽 메뉴·위쪽 띠가 있고 로그인 분할 틀(.rui-login)은 없다', async () => {
    S.admin = false; await load('/public/mypage.html', { theme: '1' }, READY);
    const v = await evaluate('({side:!!document.querySelector(".shell > .side .nav a"),bar:!!document.querySelector(".main-bar"),login:!!document.querySelector(".rui-login"),h1:(document.querySelector(".ul-view h1")||{}).textContent})');
    return v.side && v.bar && !v.login && v.h1 === '내 시스템' ? true : JSON.stringify(v);
  });
  await check('내 시스템 — 카드 = RUI.SYSTEMS 중 sso 지원(통합로그인 자신 제외), 주소 = 그 시스템 주소, 새 탭 아님', async () => {
    const v = await evaluate('(function(){var want=RUI.SYSTEMS.filter(function(s){return s.sso&&s.id!=="ulogin";});var a=[].slice.call(document.querySelectorAll("[data-ul-portal] a[data-sys]"));return {n:a.length,want:want.length,urls:a.every(function(x,i){return x.getAttribute("href")===want[i].url&&!x.target;}),names:a.every(function(x,i){return x.textContent.indexOf(want[i].name)>=0&&x.textContent.indexOf(want[i].desc)>=0&&x.querySelector("svg");})};})()');
    return v.n === v.want && v.n > 0 && v.urls && v.names ? true : JSON.stringify(v);
  });
  await check('일반 회원 — 관리 콘솔 버튼·메뉴 없음, 요약에 이름·아이디·역할 배지', async () => {
    const v = await evaluate('({btn:!document.getElementById("nav-admin").hidden,menu:!!document.querySelector(".nav a[data-key=admin]"),name:document.getElementById("sum-title").textContent,id:document.getElementById("sum-ident").textContent,badge:!!document.querySelector("#sum-badges .badge")})');
    return !v.btn && !v.menu && v.name === '시험 사용자' && /tester/.test(v.id) && v.badge ? true : JSON.stringify(v);
  });
  const MENUS = [['info', null], ['security', null], ['points', '/api/me/points'], ['services', '/api/me/services'], ['sessions', '/api/me/sessions'], ['loginlogs', '/api/me/login_logs'], ['withdraw', null]];
  for (const [key, api] of MENUS) {
    await check('메뉴 ' + key + ' — 누르면 그 화면이 보이고 위쪽 띠 제목이 바뀐다' + (api ? ' · ' + api + ' 호출' : ''), async () => {
      S.calls = [];
      await evaluate('document.querySelector(".nav a[data-key=' + key + ']").click(); true');
      await sleep(400);
      const v = await evaluate('({shown:!document.querySelector("[data-tab-panel=' + key + ']").hidden,home:!document.querySelector("[data-tab-panel=home]").hidden,on:(document.querySelector(".nav a.on")||{}).getAttribute&&document.querySelector(".nav a.on").getAttribute("data-key"),bar:document.getElementById("ul-bar-title").textContent,h1:document.getElementById("ul-page-title").textContent})');
      const called = !api || S.calls.some(c => c === api || c.replace('-', '_') === api);
      const shot = path.join(OUT, 'shots', TAG + '_menu_' + key + '.jpg'); await shot2(shot);
      return v.shown && !v.home && v.on === key && v.bar.length > 0 && v.h1.length > 0 && called && errors.length === 0 ? true : JSON.stringify(v) + ' calls=' + S.calls.join(',') + ' err=' + errors.slice(0, 2).join(' / ');
    });
  }
  await check('관리자 — 관리 콘솔 버튼(admin.html)과 메뉴, 긴 이름 말줄임(가로 넘침 없음)', async () => {
    S.admin = true; await load('/public/mypage.html', { theme: '1' }, READY);
    const v = await evaluate('(function(){var t=document.getElementById("sum-title"),cs=getComputedStyle(t);return {btn:!document.getElementById("nav-admin").hidden&&document.getElementById("nav-admin").getAttribute("href"),menu:!!document.querySelector(".nav a[data-key=admin][href=\'admin.html\']"),ell:cs.textOverflow==="ellipsis"&&t.scrollWidth>t.clientWidth,over:document.documentElement.scrollWidth>innerWidth+1};})()');
    await shot2(path.join(OUT, 'shots', TAG + '_admin_1440.jpg'));
    S.admin = false;
    return v.btn === 'admin.html' && v.menu && v.ell && !v.over ? true : JSON.stringify(v);
  });
  await check('375px — 서랍 메뉴(메뉴 열기 → .shell.nav-open), 카드 한 줄 한 개', async () => {
    await viewport(375, 812); await load('/public/mypage.html', { theme: '1' }, READY);
    await evaluate('document.querySelector(".mobile-bar [data-nav]").click(); true'); await sleep(200);
    const v = await evaluate('(function(){var a=[].slice.call(document.querySelectorAll("[data-ul-portal] a[data-sys]"));return {open:document.querySelector(".shell").classList.contains("nav-open"),cols:a.length>1?(a[0].getBoundingClientRect().top===a[1].getBoundingClientRect().top?2:1):0};})()');
    await shot2(path.join(OUT, 'shots', TAG + '_drawer_375.jpg'));
    await viewport(1440, 900);
    return v.open && v.cols === 1 ? true : JSON.stringify(v);
  });
}
async function shot2(file) { await shot(file); }

// ---------------------------------------------------------------- B) 칸 점검
const rows = [];
async function cell(w, prefs, tag) {
  await viewport(w, w < 768 ? 812 : 900);
  await load('/public/mypage.html', prefs, READY);
  const r = await evaluate(PROBE);
  const file = path.join(OUT, 'shots', tag + '.jpg');
  await shot(file);
  const ok = r.audit.ok && r.audit.guideline === EXPECT && r.contrast.failCount === 0 && !r.overflow && errors.length === 0;
  rows.push({ tag, w, prefs, ok, audit: r.audit, contrast: r.contrast, overflow: r.overflow, errors: errors.slice(0, 6), shot: path.relative(OUT, file) });
  process.stdout.write(ok ? '.' : 'F');
}
if (args.only !== 'func') {
  S.admin = false;
  for (const w of [1440, 1024, 375]) for (const th of ['1', '2']) for (const md of ['light', 'dark']) {
    await cell(w, { theme: th, palette: th === '2' ? 'lab' : 'teal', mode: md }, [TAG, 'mypage', w, 't' + th, md].join('_'));
  }
}

// ---------------------------------------------------------------- 보고
const fails = rows.filter(r => !r.ok), xfails = extra.filter(e => !e.ok);
const worst = rows.length ? Math.min.apply(null, rows.map(r => r.contrast.worst)) : null;
fs.writeFileSync(path.join(OUT, TAG + '_mypage.json'), JSON.stringify({ base: BASE, expect: EXPECT, rows, extra }, null, 1));
console.log('\ncells ' + rows.length + ' pass ' + (rows.length - fails.length) + ' fail ' + fails.length + ' worst ' + worst + ' · func ' + extra.length + ' fail ' + xfails.length);
xfails.forEach(e => console.log('  func FAIL: ' + e.name + ' -> ' + e.got));
fails.slice(0, 12).forEach(r => console.log('  cell FAIL: ' + r.tag + ' ' + JSON.stringify(r.audit.wrong.map(w => w.item + ':' + w.got)) + ' g=' + r.audit.guideline + ' c=' + r.contrast.failCount + JSON.stringify(r.contrast.fails.slice(0, 3)) + ' o=' + r.overflow + ' e=' + JSON.stringify(r.errors.slice(0, 2))));
ws.close(); killChrome();
try { fs.rmSync(profile, { recursive: true, force: true }); } catch (e) { /* 크롬이 아직 쥐고 있으면 남는다 */ }
process.exit(fails.length || xfails.length ? 1 : 0);
