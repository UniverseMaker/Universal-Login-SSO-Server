// ============================================================================
// tests/ui/theme_matrix.mjs — 화면 × 테마 조합 실제 화면 점검 (v1.8.3, D-327)
// v1.8.6: 로그인 계열은 G16 RUI.login 틀로 바뀌어 준비 조건·서비스 이름 위치만 고쳤다. 로그인 동작·G17 점검은 g16_login.mjs
//
// **배포물이 아니다.** tests/ 의 다른 파일과 같이 서버에 올리지 않는다.
// `php tests/run.php` 에 들어 있지 않다(브라우저가 필요하다). 사람이 릴리스 전에 돌린다.
//
// 무엇을 하나
//   헤드리스 Chrome/Edge 를 CDP 로 몰아, 화면마다 공통 테마(1·2) × 팔레트(RUI.PALETTES 전부)
//   × 밝기(밝게·어둡게) [× 관리자 콘솔은 메뉴 형태 3] 조합을 하나씩 연다. 조합마다
//     · RUI.audit()   — 공용 테마 점검(G13). wrong 0 이어야 통과
//     · 글자 대비      — 보이는 글자 전부, 바탕을 조상에서 찾아 합성해 4.5:1(큰 글자 3:1)
//     · 가로 넘침      — documentElement.scrollWidth > innerWidth
//     · 콘솔 오류      — console.error · 예외 · CSP 위반(Log 도메인)
//     · 스크린샷       — JPEG
//   을 남기고 matrix.json / matrix.md(점검표)를 쓴다.
//
// API 는 **가로챈다**(Fetch 도메인). 이 하네스는 운영 서버·운영 DB 에 붙지 않는다 —
// `/server/` 로 가는 요청은 전부 아래 FIXTURES 의 고정 응답을 받는다(없으면 404 봉투).
// 정적 파일만 대상 서버(`php -S 127.0.0.1:<port> -t <설치루트> tools/router-ulogin.php`)에서 받는다.
//
// 사용
//   node tests/ui/theme_matrix.mjs --base=http://127.0.0.1:8393 --out=<폴더> [--only=admin|public] [--quick]
//   종료코드: 0 통과 · 1 남은 실패 있음 · 2 인자/실행 오류
//
// 경로에 대괄호([예시])가 있다 — glob 을 쓰지 않는다. 파일은 전부 절대 경로로 쓴다.
// ============================================================================
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';

const args = Object.fromEntries(process.argv.slice(2).map(a => { const m = /^--([^=]+)(?:=(.*))?$/.exec(a); return m ? [m[1], m[2] ?? true] : [a, true]; }));
const BASE = String(args.base || '').replace(/\/$/, '');
const OUT = args.out ? path.resolve(String(args.out)) : '';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(BASE) || !OUT) {
  console.error('usage: node theme_matrix.mjs --base=http://127.0.0.1:<port> --out=<dir> [--only=admin|public] [--quick]');
  process.exit(2);
}
if (/:8080$/.test(BASE)) { console.error('8080 은 운영 포트다. 점검용 서버를 따로 띄워라.'); process.exit(2); }
const CHROME = [args.chrome, 'C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].filter(Boolean).find(p => fs.existsSync(p));
if (!CHROME) { console.error('Chrome/Edge 를 찾지 못했다'); process.exit(2); }
const PORT = 9300 + Math.floor(Math.random() * 400);
fs.mkdirSync(path.join(OUT, 'shots'), { recursive: true });

// ---------------------------------------------------------------- 고정 응답
const now = '2026-11-11T09:30:00Z';
const USER = {
  uuid: '7f1c2a9e-0000-4000-8000-00000000a001', username: 'admin', email: 'admin@example.test', email_verified: true,
  name: '관리자', nickname: '운영', phone: null, timezone: 'Asia/Seoul', locale: 'ko', marketing_opt_in: false,
  role: 'superadmin', role_rank: 100, account_level: 'verified', account_level_rank: 20,
  billing_tier: 'pro', billing_tier_rank: 20, billing_active: true, billing_expires_at: '2027-01-01T00:00:00Z',
  status: 'active', points: 12840, created_at: '2026-08-25T01:00:00Z', last_login_at: now
};
// 가입 허용 여부(운영 기본은 차단). 'block' = 기본 · 'allow' · 'error' = 500. 칸마다 바꾼다.
const REG = { mode: 'block' };
const FIXTURES = {
  '/api/auth/register_status': { allow_register: false },
  '/api/auth/csrf': { csrf_token: 'test-csrf' },
  '/api/me/index': { user: USER },
  '/api/sso/validate': { redirect_uri_valid: true, service_name: '데모 앱', allow_register: true },
  '/api/admin/stats': {
    generated_at: now,
    users: { total: 1284, active: 1190, pending: 22, suspended: 9, dormant: 51, withdrawn: 12, new_today: 4, new_7d: 31, new_30d: 118 },
    logins: { today: 342, success_7d: 2210, fail_7d: 87, unique_users_7d: 640 },
    points: { total_balance: 1823400, earned_7d: 48200, spent_7d: 39150 },
    services: { total: 9, active: 8, disabled: 1 }, sessions: { active: 412 },
    by_account_level: [{ value: 'guest', count: 40 }, { value: 'basic', count: 880 }, { value: 'verified', count: 364 }],
    by_role: [{ value: 'user', count: 1270 }, { value: 'manager', count: 9 }, { value: 'admin', count: 4 }, { value: 'superadmin', count: 1 }],
    by_billing_tier: [{ value: 'free', count: 1100 }, { value: 'pro', count: 170 }, { value: 'enterprise', count: 14 }]
  },
  '/api/health': {
    status: 'ok', time: now,
    checks: [
      { key: 'php_version', ok: true, note: null }, { key: 'db', ok: true, note: null },
      { key: 'db_port_pinned', ok: true, pinned: true, note: null }, { key: 'schema', ok: true, note: null },
      { key: 'storage', ok: true, note: null }, { key: 'installer_removed', ok: true, note: null },
      { key: 'secret_enc_key', ok: true, note: null }, { key: 'debug_off', ok: true, note: null },
      { key: 'cookie_secure', ok: null, note: 'HTTP 접속' }, { key: 'proxy_trust_sane', ok: true, note: null }
    ]
  },
  '/api/admin/users': {
    total: 3, page: 1, per_page: 20, pages: 1, filters_applied: {},
    items: [
      Object.assign({}, USER, { session_count: 2, login_count: 211 }),
      Object.assign({}, USER, { uuid: 'u2', username: 'kim.research', email: 'kim@example.test', name: '김연구', nickname: '', role: 'user', role_rank: 0, billing_tier: 'free', points: 320, status: 'active', session_count: 1, login_count: 34 }),
      Object.assign({}, USER, { uuid: 'u3', username: 'lee.lab', email: 'lee@example.test', name: '이실험', nickname: '랩장', role: 'manager', role_rank: 10, billing_tier: 'pro', points: 0, status: 'suspended', session_count: 0, login_count: 8 })
    ]
  },
  '/api/me/services': { items: [], total: 0, page: 1, per_page: 20, pages: 0 }
};
function fixtureFor(url) {
  const u = new URL(url);
  const i = u.pathname.indexOf('/server/');
  if (i < 0) { return null; }
  const p = u.pathname.slice(i + '/server'.length).replace(/\.php$/, '');
  if (p === '/api/auth/register_status') {
    if (REG.mode === 'error') { return { ok: false, data: null, error: { code: 'INTERNAL', message: '점검용 500', field: null }, __code: 500 }; }
    return { ok: true, data: { allow_register: REG.mode === 'allow' }, error: null };
  }
  if (Object.prototype.hasOwnProperty.call(FIXTURES, p)) { return { ok: true, data: FIXTURES[p], error: null }; }
  return { ok: false, data: null, error: { code: 'NOT_FOUND', message: '점검용 고정 응답이 없습니다(' + p + ').', field: null } };
}

// ---------------------------------------------------------------- CDP
function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }
async function waitJson(url, tries = 60) {
  for (let i = 0; i < tries; i++) { try { const r = await fetch(url); if (r.ok) { return await r.json(); } } catch (e) { /* 아직 */ } await sleep(250); }
  throw new Error('CDP 엔드포인트가 열리지 않았다: ' + url);
}
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'ul-theme-'));
const chrome = spawn(CHROME, ['--headless=new', '--remote-debugging-port=' + PORT, '--user-data-dir=' + profile, '--no-first-run',
  '--no-default-browser-check', '--disable-gpu', '--hide-scrollbars', '--window-size=1280,860', '--disable-extensions', 'about:blank'], { stdio: 'ignore' });
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
    const body = fixtureFor(m.params.request.url);
    if (!body) { send('Fetch.continueRequest', { requestId: m.params.requestId }).catch(() => {}); return; }
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
    if (/점검용 고정 응답|404 \(Not Found\)/.test(t) && m.params.entry.source === 'network') { return; }   // 가로챈 404 는 의도
    errors.push('log(' + m.params.entry.source + '): ' + t);
  }
});
await send('Page.enable'); await send('Runtime.enable'); await send('Log.enable');
await send('Fetch.enable', { patterns: [{ urlPattern: '*/server/*', requestStage: 'Request' }] });

async function evaluate(expr) {
  const r = await send('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true });
  if (r.exceptionDetails) { throw new Error('evaluate: ' + (r.exceptionDetails.exception?.description || r.exceptionDetails.text)); }
  return r.result.value;
}
async function viewport(w, h, mobile) {
  await send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: !!mobile });
}
async function load(url, prefs, readyExpr) {
  errors = [];
  await send('Page.navigate', { url: BASE + '/public/version.txt' });
  await sleep(150);
  await evaluate('localStorage.clear(); localStorage.setItem("rui_prefs", ' + JSON.stringify(JSON.stringify(prefs)) + '); true');
  const loaded = new Promise(res => { const f = m => { if (m.method === 'Page.loadEventFired') { listeners.splice(listeners.indexOf(f), 1); res(); } }; listeners.push(f); });
  await send('Page.navigate', { url: BASE + url });
  await Promise.race([loaded, sleep(8000)]);
  for (let i = 0; i < 40; i++) { if (await evaluate('(function(){try{return !!(' + (readyExpr || 'true') + ');}catch(e){return false;}})()')) { break; } await sleep(100); }
  await evaluate('document.fonts ? document.fonts.ready.then(function(){return true;}) : true');
  await sleep(180);
}

// 페이지 안에서 도는 검사. RUI.audit + 대비 + 넘침.
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
      if (/gradient/.test(cs.backgroundImage) && /accent|ul-auth|ul-hero/.test(e.className + '')) grad = true;
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
    if (cr < need) fails.push({ text: n.nodeValue.trim().slice(0, 24), sel: el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\\s+/).join('.') : ''), ratio: Math.round(cr * 100) / 100, need: need, fg: cs.color, bg: 'rgb(' + [b.bg.r, b.bg.g, b.bg.b].map(Math.round).join(',') + ')' });
  }
  probe.remove();
  var audit = window.RUI && RUI.audit ? RUI.audit() : { ok: false, wrong: [{ item: 'RUI 없음' }] };
  return { audit: { ok: audit.ok, guideline: audit.guideline, checked: audit.checked, wrong: audit.wrong }, contrast: { checked: checked, worst: Math.round(worst * 100) / 100, fails: fails.slice(0, 12), failCount: fails.length },
    overflow: document.documentElement.scrollWidth > innerWidth + 1 ? document.documentElement.scrollWidth : 0,
    attrs: ['mode', 'palette', 'theme', 'layout', 'toprow', 'topnav', 'side'].map(function (k) { return k + '=' + document.documentElement.getAttribute('data-rui-' + k); }).join(' ') };
})()`;

async function shot(file) {
  const r = await send('Page.captureScreenshot', { format: 'jpeg', quality: 72 });
  fs.writeFileSync(file, Buffer.from(r.data, 'base64'));
}

// ---------------------------------------------------------------- 조합
const palettes = (await (async () => { await send('Page.navigate', { url: BASE + '/public/login.html' }); await sleep(800); return evaluate('RUI.PALETTES.map(function(p){return p[0];})'); })());
const guideline = await evaluate('RUI.GUIDELINE');
const quick = !!args.quick;
// 변경분만 재점검할 때: --palettes=white --themes=2 (쉼표로 여럿). --no-extra 는 기능 확인·모바일을 뺀다.
const PALS = args.palettes ? String(args.palettes).split(',') : (quick ? ['teal', 'lab', 'white'] : palettes);
const THEMES = args.themes ? String(args.themes).split(',') : ['1', '2'], MODES = ['light', 'dark'];
const PUBLIC = [
  ['index', '/public/index.html', 'document.querySelector("[data-ul-systems] li")'],
  ['login', '/public/login.html', '!document.querySelector(".rui-login-form").hidden'],
  ['register', '/public/register.html', '!document.getElementById("register-card").hidden'],
  ['forgot', '/public/forgot.html', 'document.getElementById("rui-prefs-btn")'],
  ['reset', '/public/reset.html?token=tok_test_0001', 'document.getElementById("rui-prefs-btn")'],
  ['verify', '/public/verify.html', 'document.getElementById("rui-prefs-btn")'],
  ['mypage', '/public/mypage.html', '!document.getElementById("app").hidden']
];
const MENUS = [
  ['side', { layout: 'side' }],
  ['toprow', { layout: 'top', toprow: 'inline', topnav: 'smart' }],
  ['topnav', { layout: 'top', toprow: 'two', topnav: 'full' }]
];
const ADMIN_READY = 'document.querySelectorAll(".nav a[data-key]").length >= 8 && document.querySelector("#d-host .stat")';
const rows = [];
async function cell(page, url, ready, prefs, tag) {
  REG.mode = page === 'register' ? 'allow' : 'block';
  await load(url, prefs, ready);
  const r = await evaluate(PROBE);
  const file = path.join(OUT, 'shots', tag + '.jpg');
  await shot(file);
  const ok = r.audit.ok && r.contrast.failCount === 0 && !r.overflow && errors.length === 0;
  rows.push({ tag, page, prefs, ok, audit: r.audit, contrast: r.contrast, overflow: r.overflow, errors: errors.slice(0, 8), attrs: r.attrs, shot: path.relative(OUT, file) });
  process.stdout.write((ok ? '.' : 'F'));
  return r;
}

await viewport(1280, 860, false);
if (args.only !== 'admin') {
  for (const [name, url, ready] of PUBLIC) for (const th of THEMES) for (const pal of PALS) for (const md of MODES) {
    await cell(name, url, ready, { theme: th, palette: pal, mode: md }, [name, 't' + th, pal, md].join('_'));
  }
}
if (args.only !== 'public') {
  for (const [mn, mp] of MENUS) for (const th of THEMES) for (const pal of PALS) for (const md of MODES) {
    await cell('admin', '/public/admin.html', ADMIN_READY, Object.assign({ theme: th, palette: pal, mode: md }, mp), ['admin', mn, 't' + th, pal, md].join('_'));
  }
  // 위쪽 메뉴 줄 × 묶음 전부(팔레트 기본값), 접힌 왼쪽 메뉴
  for (const tr of ['inline', 'two']) for (const tn of ['smart', 'dropdown', 'full']) for (const th of THEMES) for (const md of MODES) {
    if (args.palettes) { break; }
    await cell('admin', '/public/admin.html', ADMIN_READY, { theme: th, palette: th === '2' ? 'lab' : 'teal', mode: md, layout: 'top', toprow: tr, topnav: tn }, ['adminx', tr, tn, 't' + th, md].join('_'));
  }
  for (const th of THEMES) for (const md of MODES) {
    if (args.palettes) { break; }
    await cell('admin', '/public/admin.html', ADMIN_READY, { theme: th, palette: 'teal', mode: md, layout: 'side', side: 'mini' }, ['adminx', 'mini', 't' + th, md].join('_'));
  }
}

// ---------------------------------------------------------------- 기능 확인 (회귀)
const extra = [];
async function check(name, fn) {
  if (args['no-extra']) { return; } try { const v = await fn(); extra.push({ name, ok: v === true, got: v }); } catch (e) { extra.push({ name, ok: false, got: String(e.message || e) }); } }
await check('?rui= 인계 후 SSO 쿼리 보존 · rui 키만 제거 · 서비스명 표시', async () => {
  const rui = Buffer.from(JSON.stringify({ palette: 'rose', mode: 'dark' })).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  await load('/public/login.html?service_id=demo&rui=' + rui + '&redirect_uri=' + encodeURIComponent('https://app.example.test/cb') + '&prompt=login', { theme: '1', palette: 'teal', mode: 'light' }, '!document.querySelector(".rui-login-form").hidden');
  await shot(path.join(OUT, 'shots', 'func_login_sso_rui.jpg'));
  return evaluate('(function(){var q=new URLSearchParams(location.search);return q.get("service_id")==="demo"&&q.get("redirect_uri")==="https://app.example.test/cb"&&q.get("prompt")==="login"&&!q.has("rui")&&document.documentElement.getAttribute("data-rui-palette")==="rose"&&document.documentElement.getAttribute("data-rui-mode")==="dark"&&/데모 앱/.test(document.getElementById("sso-note").textContent)&&history.replaceState.toString().indexOf("native")>=0 ? true : location.search+" | "+document.getElementById("sso-note").textContent;})()');
});
await check('가입 링크 — 허용 응답일 때만 보인다(마크업 기본 hidden · 차단 · 오류 · 허용 3경로)', async () => {
  const html = await (await fetch(BASE + '/public/login.html')).text();
  const hiddenInMarkup = /id="link-register"[^>]*\shidden/.test(html);
  const ready = '!document.querySelector(".rui-login-form").hidden';
  const probe = '!document.getElementById("link-register").hidden';
  const res = {};
  for (const mode of ['block', 'error', 'allow']) {
    REG.mode = mode;
    await load('/public/login.html', { theme: '1' }, ready);
    await sleep(300);
    res[mode] = await evaluate(probe);
  }
  REG.mode = 'block';
  return hiddenInMarkup && res.block === false && res.error === false && res.allow === true ? true : 'markupHidden=' + hiddenInMarkup + ' ' + JSON.stringify(res);
});
await check('register.html — 차단·500 이면 폼이 숨겨진 채, 허용이면 보인다', async () => {
  const ready = 'document.readyState === "complete"';
  const res = {};
  for (const mode of ['block', 'error', 'allow']) {
    REG.mode = mode;
    await load('/public/register.html', { theme: '1' }, ready);
    await sleep(500);
    res[mode] = await evaluate('!document.getElementById("register-card").hidden');
  }
  REG.mode = 'block';
  return res.block === false && res.error === false && res.allow === true ? true : JSON.stringify(res);
});
await check('register.html 폼 마크업 기본 hidden(fail-closed)', async () => {
  const html = await (await fetch(BASE + '/public/register.html')).text();
  return /id="register-card"[^>]*\shidden/.test(html) || 'register-card 가 hidden 으로 시작하지 않는다';
});
await check('별칭 주소 /login 도 같은 화면', async () => {
  await load('/login', { theme: '1' }, '!document.querySelector(".rui-login-form").hidden');
  return evaluate('!!document.querySelector(".rui-login-brand") && getComputedStyle(document.querySelector(".rui-login")).display === "grid"');
});
await check('별칭 주소 /admin 메뉴 그려짐', async () => {
  await load('/admin', { theme: '1', layout: 'side' }, ADMIN_READY);
  return evaluate('document.querySelectorAll(".nav a[data-key]").length >= 8');
});
await check('관리자 메뉴 클릭 → 탭 전환 · 위쪽 띠 위치 표시', async () => {
  await load('/public/admin.html', { theme: '1', layout: 'side' }, ADMIN_READY);
  await evaluate('document.querySelector(\'.nav a[data-key="users"]\').click(); true');
  await sleep(500);
  await shot(path.join(OUT, 'shots', 'func_admin_users.jpg'));
  return evaluate('(function(){var on=document.querySelector(".nav a.on");return !!on&&on.dataset.key==="users"&&!document.querySelector(\'[data-tab-panel="users"]\').hidden&&/회원/.test(document.getElementById("ul-bar-title").textContent)?true:(on&&on.dataset.key)+"|"+document.getElementById("ul-bar-title").textContent;})()');
});
await check('관리자 화면 설정 서랍 — 메뉴 형태 포함 · 견본 색 CSSOM · CSP 위반 0', async () => {
  await load('/public/admin.html', { theme: '1', layout: 'side' }, ADMIN_READY);
  await evaluate('document.querySelector(".main-bar [data-appearance]").click(); true');
  await sleep(300);
  await evaluate('document.querySelector(\'.drawer [data-k="layout"][data-v="top"]\').click(); true');
  await sleep(300);
  await shot(path.join(OUT, 'shots', 'func_admin_picker.jpg'));
  const v = await evaluate('(function(){var d=document.querySelector(".drawer");var sw=d.querySelectorAll(".swatches i");var colored=Array.prototype.every.call(sw,function(i){return i.style.background!=="";});return {sw:sw.length,colored:colored,toprow:!!d.querySelector(\'[data-k="toprow"]\'),topnav:!!d.querySelector(\'[data-k="topnav"]\'),styleAttr:d.querySelectorAll("[data-ul-style]").length,layout:document.documentElement.getAttribute("data-rui-layout")};})()');
  const csp = errors.filter(e => /Content Security Policy|style-src/i.test(e));
  return (v.sw === 12 && v.colored && v.toprow && v.topnav && v.styleAttr === 0 && v.layout === 'top' && csp.length === 0) ? true : JSON.stringify(v) + ' csp=' + csp.length;
});
await check('위쪽 메뉴(세부 카테고리) 드롭다운 열림', async () => {
  await load('/public/admin.html', { theme: '1', layout: 'top', toprow: 'inline', topnav: 'smart' }, ADMIN_READY);
  await evaluate('document.querySelector(".nav .nav-gh").click(); true');
  await sleep(200);
  await shot(path.join(OUT, 'shots', 'func_admin_top_dropdown.jpg'));
  return evaluate('!!document.querySelector(".nav-m.open .nav-gi a")');
});
await check('공개 화면 화면 설정 — 견본 색 · CSP 위반 0', async () => {
  await load('/public/login.html', { theme: '1' }, 'document.getElementById("rui-prefs-btn")');
  await evaluate('document.getElementById("rui-prefs-btn").click(); true');
  await sleep(300);
  await shot(path.join(OUT, 'shots', 'func_login_picker.jpg'));
  const v = await evaluate('(function(){var sw=document.querySelectorAll(".modal .swatches i");return sw.length===12&&Array.prototype.every.call(sw,function(i){return i.style.background!=="";})&&!document.querySelector(\'.modal [data-k="layout"]\');})()');
  const csp = errors.filter(e => /Content Security Policy|style-src/i.test(e));
  return v === true && csp.length === 0 ? true : 'v=' + v + ' csp=' + csp.join(' / ');
});
for (const [w, h, nm] of (args['no-extra'] ? [] : [[375, 812, 'm375']])) {
  await viewport(w, h, true);
  for (const md of MODES) {
    await cell('login', '/public/login.html', '!document.querySelector(".rui-login-form").hidden', { theme: '1', palette: 'teal', mode: md }, ['mobile', 'login', nm, md].join('_'));
    await cell('admin', '/public/admin.html', ADMIN_READY, { theme: '1', palette: 'teal', mode: md, layout: 'side' }, ['mobile', 'admin', nm, md].join('_'));
    await cell('register', '/public/register.html', '!document.getElementById("register-card").hidden', { theme: '2', palette: 'lab', mode: md }, ['mobile', 'register', nm, md].join('_'));
    await cell('mypage', '/public/mypage.html', '!document.getElementById("app").hidden', { theme: '1', palette: 'teal', mode: md }, ['mobile', 'mypage', nm, md].join('_'));
  }
  await check('375px 관리자 서랍 메뉴 열림', async () => {
    await load('/public/admin.html', { theme: '1', layout: 'side' }, ADMIN_READY);
    await evaluate('document.querySelector(".mobile-bar [data-nav]").click(); true'); await sleep(250);
    await shot(path.join(OUT, 'shots', 'func_mobile_admin_drawer.jpg'));
    return evaluate('document.querySelector(".shell").classList.contains("nav-open") && getComputedStyle(document.querySelector(".side")).visibility === "visible"');
  });
}
await viewport(1280, 860, false);

// ---------------------------------------------------------------- 보고
const fails = rows.filter(r => !r.ok), xfails = extra.filter(e => !e.ok);
fs.writeFileSync(path.join(OUT, 'matrix.json'), JSON.stringify({ base: BASE, palettes, rows, extra }, null, 1));
const byPage = {};
rows.forEach(r => { const k = r.page; byPage[k] = byPage[k] || { n: 0, ok: 0 }; byPage[k].n++; if (r.ok) byPage[k].ok++; });
let md = '# 화면 × 테마 조합 점검표 (theme_matrix.mjs)\n\n';
md += '- RUI.GUIDELINE: ' + guideline + ' · 팔레트 필터 ' + (args.palettes || '전부') + ' · 테마 필터 ' + (args.themes || '전부') + '\n';
md += '- 대상: ' + BASE + ' · 팔레트 ' + palettes.length + '종(' + palettes.join('·') + ')\n';
md += '- 칸: ' + rows.length + ' · 통과 ' + (rows.length - fails.length) + ' · 실패 ' + fails.length + ' · 기능 확인 ' + extra.length + '건 중 실패 ' + xfails.length + '\n';
md += '- 판정: RUI.audit() wrong 0 · 글자 대비 4.5:1(큰 글자 3:1) · 가로 넘침 0 · 콘솔 오류/CSP 위반 0\n\n';
md += '| 화면 | 칸 | 통과 |\n|---|---|---|\n' + Object.keys(byPage).map(k => '| ' + k + ' | ' + byPage[k].n + ' | ' + byPage[k].ok + ' |').join('\n') + '\n\n';
md += '## 기능 확인\n\n| 항목 | 결과 |\n|---|---|\n' + extra.map(e => '| ' + e.name + ' | ' + (e.ok ? '통과' : '실패: ' + String(e.got).replace(/\|/g, '/')) + ' |').join('\n') + '\n\n';
md += '## 칸별\n\n| 칸 | audit | 대비 최저 | 대비 실패 | 넘침 | 오류 | 결과 |\n|---|---|---|---|---|---|---|\n';
md += rows.map(r => '| ' + r.tag + ' | ' + (r.audit.ok ? 'ok' : 'wrong ' + r.audit.wrong.length) + ' (' + r.audit.checked + ') | ' + r.contrast.worst + ' | ' + r.contrast.failCount + ' | ' + (r.overflow || 0) + ' | ' + r.errors.length + ' | ' + (r.ok ? '통과' : '실패') + ' |').join('\n') + '\n';
if (fails.length) {
  md += '\n## 실패 상세(앞 40칸)\n\n';
  fails.slice(0, 40).forEach(r => { md += '- **' + r.tag + '** audit=' + JSON.stringify(r.audit.wrong.map(w => w.item + ':' + w.got)) + ' contrast=' + JSON.stringify(r.contrast.fails.slice(0, 4)) + ' errors=' + JSON.stringify(r.errors.slice(0, 3)) + '\n'; });
}
fs.writeFileSync(path.join(OUT, 'matrix.md'), md);
console.log('\ncells ' + rows.length + ' pass ' + (rows.length - fails.length) + ' fail ' + fails.length + ' · extra ' + extra.length + ' fail ' + xfails.length);
xfails.forEach(e => console.log('  extra FAIL: ' + e.name + ' -> ' + e.got));
ws.close(); killChrome();
try { fs.rmSync(profile, { recursive: true, force: true }); } catch (e) { /* 크롬이 아직 쥐고 있으면 남는다 */ }
process.exit(fails.length || xfails.length ? 1 : 0);
