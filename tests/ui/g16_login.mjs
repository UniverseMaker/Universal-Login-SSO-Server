// ============================================================================
// tests/ui/g16_login.mjs — G16/G17 공통 로그인 화면 점검 (v1.8.6, D-337·D-342)
//
// **배포물이 아니다.** `php tests/run.php` 에 들어 있지 않다(브라우저가 필요하다). 릴리스 전에 사람이 돌린다.
//
// 무엇을 하나 (헤드리스 Chrome/Edge 를 CDP 로 몬다 — theme_matrix.mjs 와 같은 방식)
//   A) 동작 확인 — 로그인 성공·실패·빈 칸·잠금(남은 시간)·SSO 복귀(authorize 본문에 복귀 파라미터 전부,
//      서버가 준 redirect_to 로 이동)·무음 SSO·복귀 주소 거절·가입 차단 숨김(login 링크·register 폼,
//      차단·500·허용)·reset/verify 토큰 주소 정리(L-1)·별칭 /login·화면 설정 CSP·
//      G17 세 가지(테마 2 입력칸·버튼 42px · 로그인 화면 audit 가 앱 틀 항목을 건너뜀 · 다른 곳에서 바꾼
//      밝기를 로그인 밝기 아이콘이 따라감)·860px 이하 한 단
//   B) 칸 점검 — 화면 6개 × 375·768·1400px × 공통 테마 1·2 × 밝게·어둡게(녹청) +
//      login·register 1400px × 팔레트 전부 × 테마 2 × 밝기 2. 칸마다 RUI.audit() wrong 0 · guideline 기대값 ·
//      글자 대비 4.5:1(큰 글자 3:1) · 가로 넘침 0 · 콘솔/CSP 오류 0 · 스크린샷
//
// API 는 **가로챈다**(Fetch). `/server/` 요청은 아래 고정 응답 — 운영 서버·DB 에 붙지 않는다.
// authorize 고정 응답은 서버처럼 굴어 **service_id·redirect_uri·state 가 쿼리와 다르면 거절**한다.
// 복귀 주소(https://app.example.test/…)도 가로채 빈 페이지를 준다 — 실제 외부로 나가지 않는다.
//
// 사용
//   node tests/ui/g16_login.mjs --base=http://127.0.0.1:<port> --out=<폴더> [--only=func|cells] [--expect=G23]
//   종료코드: 0 통과 · 1 실패 있음 · 2 인자/실행 오류
//   설치 루트·경로를 이 파일에 적지 않는다. 대상은 --base 의 서버가 정한다(`php -S … -t <설치루트> tools/router-ulogin.php`).
// ============================================================================
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';

const args = Object.fromEntries(process.argv.slice(2).map(a => { const m = /^--([^=]+)(?:=(.*))?$/.exec(a); return m ? [m[1], m[2] ?? true] : [a, true]; }));
const BASE = String(args.base || '').replace(/\/$/, '');
const OUT = args.out ? path.resolve(String(args.out)) : '';
const EXPECT = String(args.expect || 'G23');
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(BASE) || !OUT) {
  console.error('usage: node g16_login.mjs --base=http://127.0.0.1:<port> --out=<dir> [--only=func|cells] [--expect=G23]');
  process.exit(2);
}
if (/:8080$/.test(BASE)) { console.error('8080 은 운영 포트다. 점검용 서버를 따로 띄워라.'); process.exit(2); }
const CHROME = [args.chrome, 'C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].filter(Boolean).find(p => fs.existsSync(p));
if (!CHROME) { console.error('Chrome/Edge 를 찾지 못했다'); process.exit(2); }
const PORT = 9700 + Math.floor(Math.random() * 250);
fs.mkdirSync(path.join(OUT, 'shots'), { recursive: true });

// ---------------------------------------------------------------- 고정 응답
const now = '2026-11-11T09:30:00Z';
const USER = {
  uuid: '7f1c2a9e-0000-4000-8000-00000000a001', username: 'tester', email: 'tester@example.test', email_verified: true,
  name: '시험', nickname: '', phone: null, timezone: 'Asia/Seoul', locale: 'ko', marketing_opt_in: false,
  role: 'user', role_rank: 0, account_level: 'verified', account_level_rank: 20,
  billing_tier: 'free', billing_tier_rank: 0, billing_active: true, billing_expires_at: null,
  status: 'active', points: 1200, created_at: '2026-08-25T01:00:00Z', last_login_at: now
};
const APP = 'https://app.example.test/cb';
// 칸·확인마다 바꾸는 상태
const S = { reg: 'block', login: 'ok', silent: 'none', posts: [] };
function envelope(data) { return { ok: true, data, error: null }; }
function failEnv(code, message, status, data) { return { ok: false, data: data || null, error: { code, message, field: null }, __code: status }; }
function fixtureFor(url, postData) {
  const u = new URL(url);
  if (u.host === 'app.example.test') { return { __html: '<!doctype html><title>app</title><p>앱 복귀</p>' }; }
  const i = u.pathname.indexOf('/server/');
  if (i < 0) { return null; }
  const p = u.pathname.slice(i + '/server'.length).replace(/\.php$/, '');
  let body = null; try { body = postData ? JSON.parse(postData) : null; } catch (e) { body = null; }
  if (body) { S.posts.push({ path: p, body }); }
  switch (p) {
    case '/api/auth/register_status':
      return S.reg === 'error' ? failEnv('INTERNAL', '점검용 500', 500) : envelope({ allow_register: S.reg === 'allow' });
    case '/api/auth/csrf': return envelope({ csrf_token: 'test-csrf' });
    case '/api/me/index': return envelope({ user: USER });
    case '/api/me/services': return envelope({ items: [], total: 0, page: 1, per_page: 20, pages: 0 });
    case '/api/sso/validate': {
      const ok = u.searchParams.get('redirect_uri') === APP;
      return ok ? envelope({ redirect_uri_valid: true, service_name: '데모 앱', site_name: 'ulogin', allow_register: S.reg === 'allow', requires_pkce: false })
        : failEnv('SSO_INVALID_REDIRECT', '등록되지 않은 복귀 주소입니다.', 400);
    }
    case '/api/sso/authorize_session':
      return S.silent === 'ok' ? envelope({ redirect_to: APP + '?code=silent1&state=' + encodeURIComponent(body.state), code: 'silent1', state: body.state, expires_in: 60 })
        : failEnv('AUTH_REQUIRED', '로그인이 필요합니다.', 401);
    case '/api/sso/authorize': {
      // 서버처럼: 복귀 파라미터가 하나라도 빠지거나 다르면 거절한다
      if (!body || body.service_id !== 'demo' || body.redirect_uri !== APP || body.state !== 'st123' || body.code_challenge !== 'cc_abc') {
        return failEnv('VALIDATION_FAILED', '요청 값이 올바르지 않습니다.', 400);
      }
      if (S.login === 'bad') { return failEnv('AUTH_INVALID_CREDENTIALS', '아이디 또는 비밀번호가 올바르지 않습니다.', 401); }
      return envelope({ redirect_to: APP + '?code=abc123&state=st123', code: 'abc123', state: 'st123', expires_in: 60 });
    }
    case '/api/auth/login':
      if (S.login === 'bad') { return failEnv('AUTH_INVALID_CREDENTIALS', '아이디 또는 비밀번호가 올바르지 않습니다.', 401); }
      if (S.login === 'locked') { return failEnv('AUTH_ACCOUNT_LOCKED', '로그인 시도가 너무 많아 계정이 잠겼습니다.', 423, { retry_after: 90 }); }
      return envelope({ user: USER, access_token: 'x', expires_in: 900 });
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
const LOGIN_READY = 'document.querySelector(".rui-login-form") && !document.querySelector(".rui-login-form").hidden';
const extra = [];
async function check(name, fn) {
  try { const v = await fn(); extra.push({ name, ok: v === true, got: v }); } catch (e) { extra.push({ name, ok: false, got: String(e.message || e) }); }
  process.stdout.write(extra[extra.length - 1].ok ? '+' : 'X');
}
const SSO_Q = '?service_id=demo&redirect_uri=' + encodeURIComponent(APP) + '&state=st123&code_challenge=cc_abc';

if (args.only !== 'cells') {
  await viewport(1400, 900);
  await check('부품으로 그림: .rui-login · 제목 「로그인」 · 아이디 라벨 「아이디 또는 이메일」 · autocomplete=username · SSO 버튼 없음', async () => {
    S.reg = 'block'; await load('/public/login.html', { theme: '1' }, LOGIN_READY);
    const v = await evaluate('(function(){var f=document.querySelector(".rui-login-form");return {h1:f.querySelector("h1").textContent,lab:f.querySelector("label").firstChild.textContent,auto:f.u.getAttribute("autocomplete"),sso:document.querySelector("[data-sso]").hidden,hint:f.querySelector("[data-hint]").textContent,ver:(document.querySelector(".rui-login-id .ver")||{}).textContent,self:document.querySelector(".rui-login-id b").textContent,sys:document.querySelectorAll(".rui-login-sys li").length};})()');
    return v.h1 === '로그인' && v.lab === '아이디 또는 이메일' && v.auto === 'username' && v.sso === true && v.hint === '이 시스템 계정으로 로그인' && /^v\d/.test(v.ver || '') && v.self === '통합로그인시스템' && v.sys > 0 ? true : JSON.stringify(v);
  });
  await check('빈 칸 — 고정 문구 「아이디를 입력하세요.」 · 요청을 보내지 않음', async () => {
    await load('/public/login.html', { theme: '1' }, LOGIN_READY);
    await submitLogin('', ''); await sleep(200);
    const t = await evaluate('document.querySelector(".rui-login-err").textContent');
    return t === '아이디를 입력하세요.' && S.posts.length === 0 ? true : t + ' posts=' + S.posts.length;
  });
  await check('로그인 실패 — 서버 문구가 오류 줄에 · 버튼 「로그인」으로 돌아옴 · 비밀번호 칸 선택', async () => {
    S.login = 'bad'; await load('/public/login.html', { theme: '1' }, LOGIN_READY);
    await submitLogin('tester', 'wrong-pw'); await sleep(500);
    const v = await evaluate('({err:document.querySelector(".rui-login-err").textContent,btn:document.querySelector(".rui-login-go").textContent,dis:document.querySelector(".rui-login-go").disabled,focus:document.activeElement&&document.activeElement.name})');
    S.login = 'ok';
    await shot(path.join(OUT, 'shots', 'func_login_fail.jpg'));
    return v.err === '아이디 또는 비밀번호가 올바르지 않습니다.' && v.btn === '로그인' && v.dis === false && v.focus === 'p' ? true : JSON.stringify(v);
  });
  await check('잠금 — 오류 줄 + 「잠금 해제까지 … 남았습니다」 + 버튼 비활성 유지', async () => {
    S.login = 'locked'; await load('/public/login.html', { theme: '1' }, LOGIN_READY);
    await submitLogin('tester', 'pw'); await sleep(600);
    const v = await evaluate('({err:document.querySelector(".rui-login-err").textContent,note:document.getElementById("cooldown-note").textContent,hid:document.getElementById("cooldown-note").hidden,dis:document.querySelector(".rui-login-go").disabled})');
    await shot(path.join(OUT, 'shots', 'func_login_locked.jpg'));
    S.login = 'ok';
    return /잠겼습니다/.test(v.err) && /^잠금 해제까지 .+ 남았습니다\.$/.test(v.note) && v.hid === false && v.dis === true ? true : JSON.stringify(v);
  });
  await check('일반 로그인 성공 — POST /api/auth/login(username·password) → mypage.html', async () => {
    await load('/public/login.html', { theme: '1' }, LOGIN_READY);
    await submitLogin('tester', 'right-pw');
    await waitFor('/mypage\\.html/.test(location.pathname)', 4000);
    const href = await evaluate('location.pathname');
    const post = S.posts.find(p => p.path === '/api/auth/login');
    return /mypage\.html$/.test(href) && post && post.body.username === 'tester' && post.body.password === 'right-pw' ? true : href + ' ' + JSON.stringify(post);
  });
  await check('SSO 복귀 — 무음 401 → 폼·서비스 이름 표시 → authorize 본문에 복귀 파라미터 4개 → 서버가 준 redirect_to 로 이동', async () => {
    S.silent = 'none'; await load('/public/login.html' + SSO_Q, { theme: '1' }, LOGIN_READY);
    const note = await evaluate('document.getElementById("sso-note").textContent');
    await shot(path.join(OUT, 'shots', 'func_login_sso.jpg'));
    await submitLogin('tester', 'right-pw');
    await waitFor('location.host === "app.example.test"', 4000);
    const href = await evaluate('location.href');
    const post = S.posts.find(p => p.path === '/api/sso/authorize');
    const b = post ? post.body : {};
    const okBody = b.service_id === 'demo' && b.redirect_uri === APP && b.state === 'st123' && b.code_challenge === 'cc_abc' && b.code_challenge_method === 'S256';
    return /데모 앱/.test(note) && okBody && href === APP + '?code=abc123&state=st123' ? true : 'note=' + note + ' body=' + JSON.stringify(b) + ' href=' + href;
  });
  await check('SSO 복귀 실패(비밀번호 틀림) — 이동하지 않고 오류 줄', async () => {
    S.login = 'bad'; await load('/public/login.html' + SSO_Q, { theme: '1' }, LOGIN_READY);
    await submitLogin('tester', 'wrong'); await sleep(500);
    const v = await evaluate('({host:location.host,err:document.querySelector(".rui-login-err").textContent})');
    S.login = 'ok';
    return v.host !== 'app.example.test' && v.err === '아이디 또는 비밀번호가 올바르지 않습니다.' ? true : JSON.stringify(v);
  });
  await check('무음 SSO — 세션이 있으면 폼 없이 redirect_to 로 이동', async () => {
    S.silent = 'ok'; await load('/public/login.html' + SSO_Q, { theme: '1' }, 'location.host === "app.example.test"');
    const href = await evaluate('location.href'); S.silent = 'none';
    return /^https:\/\/app\.example\.test\/cb\?code=silent1&state=st123$/.test(href) ? true : href;
  });
  await check('prompt=login — 무음을 건너뛰고 폼', async () => {
    S.silent = 'ok'; await load('/public/login.html' + SSO_Q + '&prompt=login', { theme: '1' }, LOGIN_READY);
    const v = await evaluate('location.host + " silentPosts=" + 0');
    const silent = S.posts.filter(p => p.path === '/api/sso/authorize_session').length; S.silent = 'none';
    return /127\.0\.0\.1/.test(v) && silent === 0 ? true : v + ' silent=' + silent;
  });
  await check('등록 안 된 복귀 주소 — 폼을 숨기고 오류 화면 · 이동 없음', async () => {
    await load('/public/login.html?service_id=demo&redirect_uri=' + encodeURIComponent('https://evil.example.test/cb'), { theme: '1' }, '!document.getElementById("sso-error-card").hidden');
    const v = await evaluate('({form:document.querySelector(".rui-login-form").hidden,card:!document.getElementById("sso-error-card").hidden,title:document.getElementById("sso-error-title").textContent,host:location.host})');
    await shot(path.join(OUT, 'shots', 'func_login_sso_bad.jpg'));
    const audit = await evaluate('RUI.audit().ok');
    return v.form && v.card && /허용되지 않은/.test(v.title) && /127\.0\.0\.1/.test(v.host) && audit ? true : JSON.stringify(v) + ' audit=' + audit;
  });
  await check('가입 링크 — 마크업 hidden · 차단·500 이면 숨김 · 허용일 때만 보임', async () => {
    const html = await (await fetch(BASE + '/public/login.html')).text();
    const res = {};
    for (const mode of ['block', 'error', 'allow']) {
      S.reg = mode; await load('/public/login.html', { theme: '1' }, LOGIN_READY); await sleep(300);
      res[mode] = await evaluate('!document.getElementById("link-register").hidden');
    }
    S.reg = 'block';
    return /<a [^>]*id="link-register"[^>]*\shidden>/.test(html) && res.block === false && res.error === false && res.allow === true ? true : JSON.stringify(res);
  });
  await check('register.html — 차단·500 이면 폼 숨김, 허용이면 보임 · 틀은 .rui-login', async () => {
    const res = {};
    for (const mode of ['block', 'error', 'allow']) {
      S.reg = mode; await load('/public/register.html', { theme: '1' }, 'document.readyState === "complete"'); await sleep(500);
      res[mode] = await evaluate('!document.getElementById("register-card").hidden && !!document.getElementById("register-card").closest(".rui-login-main")');
    }
    S.reg = 'block';
    return res.block === false && res.error === false && res.allow === true ? true : JSON.stringify(res);
  });
  await check('reset.html?token= — 토큰이 주소에서 지워짐(L-1) · ?rui= 가 섞여도', async () => {
    await load('/public/reset.html?token=tok_test_0001', { theme: '1' }, '!/token=/.test(location.search)');
    const a = await evaluate('location.search');
    await load('/public/reset.html?rui=eyJtb2RlIjoiZGFyayJ9&token=tok_test_0002', { theme: '1' }, '!/token=/.test(location.search)');
    const b = await evaluate('location.search');
    return !/token=/.test(a) && !/token=/.test(b) ? true : a + ' | ' + b;
  });
  await check('verify.html?token= — 토큰이 주소에서 지워짐', async () => {
    await load('/public/verify.html?token=tok_test_0003', { theme: '1' }, '!/token=/.test(location.search)');
    const a = await evaluate('location.search');
    return !/token=/.test(a) ? true : a;
  });
  await check('별칭 /login 도 같은 G16 화면', async () => {
    await load('/login', { theme: '1' }, LOGIN_READY);
    return evaluate('!!document.querySelector(".rui-login .rui-login-brand") && RUI.audit().ok');
  });
  await check('G17 ① 테마 2 로그인 입력칸·버튼 42px (테마 1 도 42px)', async () => {
    const res = {};
    for (const th of ['1', '2']) {
      await load('/public/login.html', { theme: th }, LOGIN_READY);
      res[th] = await evaluate('(function(){var f=document.querySelector(".rui-login-form");return [Math.round(f.u.getBoundingClientRect().height),Math.round(f.p.getBoundingClientRect().height),Math.round(f.querySelector(".rui-login-go").getBoundingClientRect().height)].join(",");})()');
    }
    return res['1'] === '42,42,42' && res['2'] === '42,42,42' ? true : JSON.stringify(res);
  });
  await check('G17 ② 로그인 화면 audit — 앱 틀 항목(바로 가기·새 버전 알림·접기) 없음 · guideline ' + EXPECT, async () => {
    await load('/public/login.html', { theme: '1' }, LOGIN_READY);
    const v = await evaluate('(function(){var a=RUI.audit();return {g:a.guideline,ok:a.ok,items:a.wrong.map(function(w){return w.item;}),hasSrc:/onLogin/.test(String(RUI.audit))};})()');
    return v.g === EXPECT && v.ok && v.hasSrc ? true : JSON.stringify(v);
  });
  await check('G17 ③ 화면 설정에서 밝기를 바꾸면 로그인 밝기 아이콘이 따라감(해↔달)', async () => {
    await load('/public/login.html', { theme: '1', mode: 'light' }, LOGIN_READY);
    const pathOf = 'document.querySelector("[data-rui-login-mode] path").getAttribute("d")';
    const moon = await evaluate('RUI.ICONS.moon'), sun = await evaluate('RUI.ICONS.sun');
    const before = await evaluate(pathOf);
    await evaluate('document.getElementById("rui-prefs-btn").click(); true'); await sleep(250);
    await evaluate('document.querySelector(\'.modal [data-k="mode"][data-v="dark"]\').click(); true'); await sleep(250);
    const after = await evaluate(pathOf), mode = await evaluate('document.documentElement.getAttribute("data-rui-mode")');
    await shot(path.join(OUT, 'shots', 'func_login_picker_dark.jpg'));
    const csp = errors.filter(e => /Content Security Policy|style-src/i.test(e));
    return before === moon && after === sun && mode === 'dark' && csp.length === 0 ? true : JSON.stringify({ before: before === moon, after: after === sun, mode, csp });
  });
  await check('860px 이하 한 단(768) · 넓은 화면 두 단(1400) · 375 에서 소개 숨김', async () => {
    const cols = 'getComputedStyle(document.querySelector(".rui-login")).gridTemplateColumns.split(" ").length';
    const res = {};
    for (const w of [1400, 768, 375]) {
      await viewport(w, 900); await load('/public/login.html', { theme: '1' }, LOGIN_READY);
      res[w] = await evaluate('[' + cols + ', getComputedStyle(document.querySelector(".rui-login-pitch")).display].join("/")');
    }
    await viewport(1400, 900);
    return res[1400] === '2/grid' && res[768] === '1/none' && res[375] === '1/none' ? true : JSON.stringify(res);
  });
  // v1.8.11 — mypage 는 G16 틀이 아니라 앱 틀이다. 점검은 tests/ui/mypage_portal.mjs
}

// ---------------------------------------------------------------- B) 칸 점검
const rows = [];
const PAGES = [
  ['login', '/public/login.html', LOGIN_READY],
  ['register', '/public/register.html', '!document.getElementById("register-card").hidden'],
  ['forgot', '/public/forgot.html', 'document.getElementById("rui-prefs-btn")'],
  ['reset', '/public/reset.html?token=tok_test_0001', 'document.getElementById("rui-prefs-btn") && !/token=/.test(location.search)'],
  ['verify', '/public/verify.html', 'document.getElementById("rui-prefs-btn")']
  // mypage 는 v1.8.11 부터 앱 틀 — tests/ui/mypage_portal.mjs
];
async function cell(page, url, ready, prefs, w, tag) {
  S.reg = page === 'register' ? 'allow' : 'block';
  await viewport(w, w < 768 ? 812 : 900);
  await load(url, prefs, ready);
  const r = await evaluate(PROBE);
  const file = path.join(OUT, 'shots', tag + '.jpg');
  await shot(file);
  const ok = r.audit.ok && r.audit.guideline === EXPECT && r.contrast.failCount === 0 && !r.overflow && errors.length === 0;
  rows.push({ tag, page, w, prefs, ok, audit: r.audit, contrast: r.contrast, overflow: r.overflow, errors: errors.slice(0, 6), shot: path.relative(OUT, file) });
  process.stdout.write(ok ? '.' : 'F');
}
let palettes = [];
if (args.only !== 'func') {
  await viewport(1400, 900); await load('/public/login.html', { theme: '1' }, LOGIN_READY);
  palettes = await evaluate('RUI.PALETTES.map(function(p){return p[0];})');
  for (const [name, url, ready] of PAGES) for (const w of [375, 768, 1400]) for (const th of ['1', '2']) for (const md of ['light', 'dark']) {
    await cell(name, url, ready, { theme: th, palette: 'teal', mode: md }, w, [name, w, 't' + th, 'teal', md].join('_'));
  }
  for (const [name, url, ready] of PAGES.slice(0, 2)) for (const pal of palettes) for (const th of ['1', '2']) for (const md of ['light', 'dark']) {
    if (pal === 'teal') { continue; }
    await cell(name, url, ready, { theme: th, palette: pal, mode: md }, 1400, [name, 1400, 't' + th, pal, md].join('_'));
  }
  await viewport(1400, 900);
}

// ---------------------------------------------------------------- 보고
const fails = rows.filter(r => !r.ok), xfails = extra.filter(e => !e.ok);
const worst = rows.length ? Math.min.apply(null, rows.map(r => r.contrast.worst)) : null;
fs.writeFileSync(path.join(OUT, 'g16.json'), JSON.stringify({ base: BASE, expect: EXPECT, palettes, rows, extra }, null, 1));
const byPage = {};
rows.forEach(r => { const k = r.page; byPage[k] = byPage[k] || { n: 0, ok: 0, worst: 99 }; byPage[k].n++; if (r.ok) byPage[k].ok++; byPage[k].worst = Math.min(byPage[k].worst, r.contrast.worst); });
let md = '# G16/G17 공통 로그인 화면 점검표 (g16_login.mjs)\n\n';
md += '- 기대 판: ' + EXPECT + ' · 대상 ' + BASE + ' · 팔레트 ' + palettes.length + '종\n';
md += '- 칸 ' + rows.length + ' · 통과 ' + (rows.length - fails.length) + ' · 실패 ' + fails.length + ' · 대비 최저 ' + worst + ' · 동작 확인 ' + extra.length + '건 중 실패 ' + xfails.length + '\n';
md += '- 판정: RUI.audit() wrong 0 + guideline ' + EXPECT + ' · 글자 대비 4.5:1(큰 글자 3:1) · 가로 넘침 0 · 콘솔/CSP 오류 0\n\n';
md += '| 화면 | 칸 | 통과 | 대비 최저 |\n|---|---|---|---|\n' + Object.keys(byPage).map(k => '| ' + k + ' | ' + byPage[k].n + ' | ' + byPage[k].ok + ' | ' + byPage[k].worst + ' |').join('\n') + '\n\n';
md += '## 동작 확인\n\n| 항목 | 결과 |\n|---|---|\n' + extra.map(e => '| ' + e.name.replace(/\|/g, '/') + ' | ' + (e.ok ? '통과' : '실패: ' + String(e.got).replace(/\|/g, '/')) + ' |').join('\n') + '\n\n';
if (fails.length) {
  md += '## 실패 칸(앞 40)\n\n';
  fails.slice(0, 40).forEach(r => { md += '- **' + r.tag + '** audit=' + JSON.stringify(r.audit.wrong.map(w => w.item + ':' + w.got)) + ' g=' + r.audit.guideline + ' contrast=' + JSON.stringify(r.contrast.fails.slice(0, 4)) + ' overflow=' + r.overflow + ' errors=' + JSON.stringify(r.errors.slice(0, 3)) + '\n'; });
}
fs.writeFileSync(path.join(OUT, 'g16.md'), md);
console.log('\ncells ' + rows.length + ' pass ' + (rows.length - fails.length) + ' fail ' + fails.length + ' worst ' + worst + ' · func ' + extra.length + ' fail ' + xfails.length);
xfails.forEach(e => console.log('  func FAIL: ' + e.name + ' -> ' + e.got));
fails.slice(0, 10).forEach(r => console.log('  cell FAIL: ' + r.tag + ' ' + JSON.stringify(r.audit.wrong.map(w => w.item + ':' + w.got)) + ' c=' + r.contrast.failCount + JSON.stringify(r.contrast.fails.slice(0, 2)) + ' o=' + r.overflow + ' e=' + JSON.stringify(r.errors.slice(0, 2))));
ws.close(); killChrome();
try { fs.rmSync(profile, { recursive: true, force: true }); } catch (e) { /* 크롬이 아직 쥐고 있으면 남는다 */ }
process.exit(fails.length || xfails.length ? 1 : 0);
