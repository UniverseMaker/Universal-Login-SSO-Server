/* rui-theme.js — 화면 설정: 밝기, 팔레트, 메뉴 형태, 이전 테마. 다른 파일에 의존하지 않는다.
   저장은 기기별 localStorage('rui_prefs'). 서버가 없어도 동작한다.
   사용: RUI.apply() · RUI.get() · RUI.set({palette:'blue'}) · RUI.picker(요소, {legacy:함수, unified:{linked:개수}}) · RUI.navHtml · RUI.navSystem · RUI.navFit · RUI.navMark
   값이 바뀌면 document 에 'rui:change' 이벤트가 난다. */
(function (global) {
  'use strict';
  var KEY = 'rui_prefs', DEF = { mode: 'light', palette: 'teal', layout: 'side', topnav: 'smart', toprow: 'inline', theme: '1', side: 'open', legacy: false, unified: false, navv: 2 };
  var RUI = global.RUI = {};
  RUI.GUIDELINE = 'G31';   // GUIDELINE.md 의 판 번호와 같아야 한다
  /* 메뉴 아이콘. 한 시스템 안에서 서로 다르게 고른다. 이름으로 쓴다: RUI.icon('database') */
  RUI.ICONS = {
    sun: 'M12 17a5 5 0 1 0 0-10 5 5 0 0 0 0 10zM12 1v2M12 21v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M1 12h2M21 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4', moon: 'M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z', lock: 'M5 11h14v10H5zM8 11V7a4 4 0 0 1 8 0v4',
    home: 'M3 11l9-8 9 8M5 10v10h14V10M10 20v-6h4v6', folder: 'M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z',
    file: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h6', report: 'M6 2h9l5 5v15H6zM15 2v5h5M9 18v-3M12 18v-5M15 18v-2',
    archive: 'M3 4h18v4H3zM5 8v12h14V8M10 12h4', history: 'M3 12a9 9 0 1 0 3-6.7L3 8M3 3v5h5M12 7v5l4 2', book: 'M4 5a2 2 0 0 1 2-2h14v16H6a2 2 0 0 0-2 2zM4 19a2 2 0 0 1 2-2h14',
    calendar: 'M4 5h16v16H4zM4 10h16M8 3v4M16 3v4', caltask: 'M4 5h16v16H4zM4 10h16M8 3v4M16 3v4M9 15l2 2 4-4', clock: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 6v6l4 2',
    users: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8', idcard: 'M3 5h18v14H3zM7 15a2 2 0 0 1 4 0M9 11a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zM14 9h4M14 13h4',
    chart: 'M4 20V10M10 20V4M16 20v-7M22 20H2', trend: 'M3 17l6-6 4 4 8-8M15 7h6v6', database: 'M12 3c5 0 8 1.300 8 3s-3 3-8 3-8-1.300-8-3 3-3 8-3zM4 6v12c0 1.700 3 3 8 3s8-1.300 8-3V6M4 12c0 1.700 3 3 8 3s8-1.300 8-3',
    gear: 'M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6', link: 'M10 14a4 4 0 0 0 6 .400l3-3a4 4 0 0 0-5.700-5.700l-1.300 1.300M14 10a4 4 0 0 0-6-.400l-3 3a4 4 0 0 0 5.700 5.700l1.300-1.300',
    plug: 'M9 2v6M15 2v6M6 8h12v4a6 6 0 0 1-12 0zM12 18v4', spark: 'M12 3l1.900 5.100L19 10l-5.100 1.900L12 17l-1.900-5.100L5 10l5.100-1.900z', shield: 'M12 3l8 3v6c0 5-3.500 8-8 9-4.500-1-8-4-8-9V6z',
    search: 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM21 21l-4.300-4.300', send: 'M21 3L3 10l7 3 3 7zM21 3L10 13', card: 'M3 6h18v12H3zM3 10h18M7 15h4', box: 'M21 8l-9-5-9 5v8l9 5 9-5zM3 8l9 5 9-5M12 13v8',
    award: 'M12 15a6 6 0 1 0 0-12 6 6 0 0 0 0 12zM8.500 14L7 22l5-3 5 3-1.500-8', check: 'M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM8 12l3 3 5-6', chat: 'M21 15a2 2 0 0 1-2 2H8l-5 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z',
    flask: 'M9 3h6M10 3v6l-5 9a2 2 0 0 0 2 3h10a2 2 0 0 0 2-3l-5-9V3M7.500 15h9', bulb: 'M9 18h6M10 22h4M12 2a7 7 0 0 0-4 12.700V16h8v-1.300A7 7 0 0 0 12 2z', target: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 17a5 5 0 1 0 0-10 5 5 0 0 0 0 10zM12 13a1 1 0 1 0 0-2 1 1 0 0 0 0 2z',
    layers: 'M12 3l9 5-9 5-9-5zM3 13l9 5 9-5M3 17l9 5 9-5', map: 'M9 4L3 6v14l6-2 6 2 6-2V4l-6 2zM9 4v14M15 6v14', pencil: 'M4 20l1-4L16 5l3 3L8 19zM14 7l3 3', cap: 'M2 9l10-5 10 5-10 5zM6 11v5c3 2 9 2 12 0v-5', grid: 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z',
    headphones: 'M3 18v-6a9 9 0 0 1 18 0v6M3 18a2 2 0 0 0 2 2h1v-6H5a2 2 0 0 0-2 2zM21 18a2 2 0 0 1-2 2h-1v-6h1a2 2 0 0 1 2 2z', chats: 'M14 9a2 2 0 0 1-2 2H6l-4 3V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2zM18 9h2a2 2 0 0 1 2 2v11l-4-3h-6a2 2 0 0 1-2-2v-1',
    graph: 'M6 7a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM18 9a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM8 21a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM18 20a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM7.5 6.5l9 1M6.5 7l1.3 10M17.8 9l.2 7M10 19h6', help: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3M12 17h.01'
  };
  RUI.icon = function (n) { return '<svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="' + (RUI.ICONS[n] || RUI.ICONS.file) + '"/></svg>'; };
  RUI.PALETTES = [
    ['teal', '녹청', '#0f6b62', '#4dbcab'], ['slate', '청회', '#3a5a6e', '#9cc0d4'], ['blue', '청', '#1f5fbf', '#7fb0f5'],
    ['indigo', '남', '#3f4bb8', '#a3abf5'], ['plum', '자두', '#7b3f98', '#cda0e6'], ['rose', '장미', '#b4325d', '#f29bb6'],
    ['clay', '황토', '#a24a25', '#f0a883'], ['amber', '호박', '#8f5606', '#eab35c'], ['moss', '이끼', '#4d6b1e', '#b4d277'], ['ink', '먹', '#2b2f2d', '#d6dad7'], ['white', '흰 메뉴', '#0f6b62', '#4dbcab'], ['lab', '연구소', '#2f6fe4', '#4d8dff']
  ];
  RUI.get = function () {
    var p = {}; try { p = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { p = {}; }
    if (!p.navv && p.topnav === 'full') delete p.topnav;   // 이전 기본값은 새 기본값(적당히)으로 옮긴다
    if (!p.modev && p.mode === 'system') delete p.mode;   // 기본 밝기는 밝게다. 예전에 저장된 기본값(시스템)만 옮기고, 사람이 고른 어둡게는 그대로 둔다
    return Object.assign({}, DEF, p, { navv: 2, modev: 1 });
  };
  RUI.mode = function (p) {   // 실제 적용되는 밝기
    p = p || RUI.get();
    if (p.mode !== 'system') return p.mode;
    return global.matchMedia && global.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  };
  // 기본 글꼴 Pretendard. 화면마다 따로 넣지 않아도 되게 여기서 읽는다. 네트워크가 막히면 시스템 글꼴로 돌아간다
  RUI.font = function () {
    if (document.documentElement.hasAttribute('data-rui-font') || document.querySelector('link[href*="pretendard" i],style[data-rui-font]')) return;   // 자체 글꼴 파일을 쓰는 시스템은 <html data-rui-font> 를 둔다
    var l = document.createElement('link'); l.rel = 'stylesheet'; l.setAttribute('data-rui-font', '1');
    l.href = 'https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css';
    (document.head || document.documentElement).appendChild(l);
  };
  /* 일관성 점검. 콘솔에서 RUI.audit() 를 부르면 공용 값과 다른 곳을 돌려준다. 화면에 없는 부품은 건너뛴다 */
  RUI.audit = function () {
    var root = document.documentElement, tok = function (k) { return getComputedStyle(root).getPropertyValue(k).trim(); }, rows = [];
    var probe = document.createElement('div'); probe.style.cssText = 'position:absolute;visibility:hidden'; document.body.appendChild(probe);
    if (root.getAttribute('data-rui-emergency') === '1') { var eb = document.getElementById('ruiEmgBar'), ev = !!(eb && eb.getClientRects().length); rows.push({ item: '비상복구모드 띠(G31)', sel: '#ruiEmgBar', want: '보임', got: ev ? '보임' : '없음', ok: ev }); }
    var rgb = function (v) { probe.style.color = ''; probe.style.color = v; return getComputedStyle(probe).color; };
    var font = function (v) { return String(v).split(',')[0].replace(/["']/g, '').trim(); };
    var check = function (name, sel, prop, want, fn) {
      var e = document.querySelector(sel); if (!e || !e.getBoundingClientRect().width) return;
      var c = getComputedStyle(e), got = prop === 'width' ? Math.round(e.getBoundingClientRect().width) + 'px' : c[prop];
      got = fn ? fn(got) : got; rows.push({ item: name, sel: sel, want: want, got: got, ok: String(got) === String(want) });
    };
    var ink = rgb('var(--rui-ink)'), ink2 = rgb('var(--rui-ink-2)');
    check('본문 글꼴', 'body', 'fontFamily', font(tok('--rui-font')), font);
    check('본문 글자색', 'body', 'color', ink);
    check('본문 크기', 'body', 'fontSize', tok('--rui-fs'));
    check('로고 크기', '.logo', 'width', tok('--rui-logo'));
    check('타이틀 크기', '.side-title', 'fontSize', tok('--rui-title-fs'));
    check('타이틀 글자색', '.side-title', 'color', ink);
    check('화면 제목 크기', '.page-head h1', 'fontSize', tok('--rui-h1-fs'));
    check('화면 제목 굵기', '.page-head h1', 'fontWeight', tok('--rui-h1-w'));
    check('화면 제목 글자색', '.page-head h1', 'color', ink);
    check('설명 글자색', '.page-head p', 'color', ink2);
    if (RUI.get().side !== 'mini') check('메뉴 크기', '.nav a:not(.on)', 'fontSize', tok('--rui-nav-fs'));   // 접힌 메뉴는 글자를 숨긴다
    check('메뉴 글자색', '.nav a:not(.on)', 'color', ink2);
    check('패널 제목 크기', '.panel-head h2', 'fontSize', tok('--rui-h2-fs'));
    check('패널 제목 글자색', '.panel-head h2', 'color', ink);
    check('표 머리 크기', 'th', 'fontSize', tok('--rui-th-fs'));
    var lg = document.querySelector('.logo'); if (lg) { var lc = getComputedStyle(lg), mid = /center/.test(lc.justifyItems) || /center/.test(lc.justifyContent); rows.push({ item: '로고 가로 가운데', sel: '.logo', want: true, got: mid, ok: mid }); }
    check('로고 세로 가운데', '.logo', 'alignItems', 'center', function (v) { return /center/.test(v) ? 'center' : v; });
    check('버전 뱃지 크기', '.ver', 'fontSize', '11px');
    check('버전 뱃지 한 줄', '.ver', 'whiteSpace', 'nowrap');
    var onLogin = (function () { var l = document.querySelector('.rui-login'); return !!(l && l.getClientRects().length); })();   // G20: 숨은 창(폭 0)에서도 보이는 상자가 있으면 로그인 화면   // G17: 로그인 화면에서는 앱 틀 검사를 건너뛴다
    if (!onLogin && document.querySelector('.side') && innerWidth > 860 && RUI.get().layout !== 'top') {
      var vb = document.querySelector('.side-head .ver'), tg = document.querySelector('.main-bar [data-rui-side-toggle]');
      rows.push({ item: '타이틀 버전 뱃지', sel: '.side-head .ver', want: true, got: !!vb, ok: !!vb });
      var sh = document.querySelector('.side-head'), mb = document.querySelector('.main-bar');
      if (sh && mb) { var h1 = Math.round(sh.getBoundingClientRect().height), h2 = Math.round(mb.getBoundingClientRect().height); rows.push({ item: '위쪽 띠 한 줄(타이틀 칸과 메인 위쪽 줄 높이)', sel: '.side-head / .main-bar', want: h1, got: h2, ok: h1 === h2 }); }
      rows.push({ item: '메인 위쪽 줄 접기 버튼', sel: '.main-bar [data-rui-side-toggle]', want: true, got: !!tg, ok: !!tg });
    }
    probe.remove();
    if (!onLogin && document.querySelector('.side, .main-bar')) { var sb = document.querySelector('[data-rui-sys]'); rows.push({ item: '시스템 바로 가기(G9)', sel: '[data-rui-sys]', want: true, got: !!sb, ok: !!sb }); }
    // G18: 새 버전 알림은 로그인 화면을 포함해 맨 위 창의 모든 화면에서 켜져 있어야 한다(틀 안에 끼운 화면만 예외)
    if (global.top === global && !document.getElementById('app-no-updates')) rows.push({ item: '공용 새 버전 알림(G18)', sel: 'RUI.updates', want: true, got: !!RUI.updatesOn, ok: !!RUI.updatesOn });
    // G19: 같은 출처의 스크립트·스타일은 판마다 바뀌는 주소(?v=<버전> 또는 해시 이름)로 읽는다. Cloudflare 가 정적 파일을 4시간 캐시해 새로고침해도 옛 파일이 남는다
    var nov = [];
    Array.prototype.forEach.call(document.querySelectorAll('script[src], link[rel~="stylesheet"][href]'), function (el) { var u; try { u = new URL(el.getAttribute('src') || el.getAttribute('href'), location.href); } catch (e) { return; } if (u.origin !== location.origin) return; if (/[?&](v|r)=/.test(u.search) || /[.-][A-Za-z0-9_]{8,}\.(js|css)$/.test(u.pathname)) return; nov.push(u.pathname); });
    rows.push({ item: '자원 주소 버전 표시(G19)', sel: 'script[src], link[rel=stylesheet]', want: 0, got: nov.length ? nov.join(', ') : 0, ok: !nov.length });
    var seenIcon = {}, dup = [];
    Array.prototype.forEach.call(document.querySelectorAll('.nav a'), function (a) { var own = a.closest('[data-sys]'), s = own ? own.getAttribute('data-sys') + ':' : '', p = a.querySelector('svg'), n = a.textContent.trim(); if (!p) { if (own) dup.push(n + '(아이콘 없음)'); return; } var k = s + p.innerHTML.replace(/\s+/g, ''); if (seenIcon[k] && seenIcon[k] !== n) dup.push(seenIcon[k] + ' = ' + n); else seenIcon[k] = n; });
    if (Object.keys(seenIcon).length) rows.push({ item: '메뉴 아이콘 중복', sel: '.nav a svg', want: 0, got: dup.length ? dup.join(', ') : 0, ok: !dup.length });
    // 가운데 정렬: 아이콘 버튼·로고·뱃지·키 모양 안의 그림이나 글자가 1px 넘게 치우치면 잡는다
    var off = [];
    Array.prototype.forEach.call(document.querySelectorAll('.icon-btn, .logo, .ver, .find kbd, .avatar, .tag, .btn, .count, [data-rui-center]'), function (el) {
      var b = el.getBoundingClientRect(); if (!b.width || !b.height || el.closest('[hidden]')) return;
      var c = el.querySelector('svg'), r;
      if (c && el.children.length === 1 && !el.textContent.trim()) r = c.getBoundingClientRect();
      else if (!c && el.children.length === 0 && el.textContent.trim()) { var rg = document.createRange(); rg.selectNodeContents(el); r = rg.getBoundingClientRect(); }
      else return;
      if (!r.width) return;
      var dx = (r.left + r.width / 2) - (b.left + b.width / 2), dy = (r.top + r.height / 2) - (b.top + b.height / 2);
      if (Math.abs(dx) > 1.5 || Math.abs(dy) > 1.5) off.push((el.className || el.tagName).toString().split(' ')[0] + ' "' + el.textContent.trim().slice(0, 8) + '" ' + Math.round(dx * 10) / 10 + ',' + Math.round(dy * 10) / 10);
    });
    rows.push({ item: '가운데 정렬', sel: '아이콘 버튼·로고·뱃지·태그', want: 0, got: off.length ? off.slice(0, 8).join(' | ') : 0, ok: !off.length });
    // G13(다른 시스템 제안): 위쪽 띠가 넘침을 감춰 잘린 것, 눌려 작아진 버튼, 채워진 선 아이콘
    var cut = [];
    Array.prototype.forEach.call(document.querySelectorAll('.main-bar, .mobile-bar, .side-head'), function (el) { var b = el.getBoundingClientRect(); if (b.width && el.scrollWidth > el.clientWidth + 1) cut.push((el.className || el.tagName).toString().split(' ')[0] + ' ' + el.scrollWidth + '>' + el.clientWidth); });
    // G23: 위쪽 메뉴 모드 — 세로 구분선 없음, 띠 안 요소(브랜드·뱃지·메뉴·찾기·아이콘)의 세로 가운데가 띠 가운데와 1.5px 안
    // G24: 자체 위쪽 띠(.topbar 등)를 쓰는 시스템도 검사한다. 띠에 data-rui-topbar, 브랜드 칸에 data-rui-brand 를 단다. 띠를 못 찾으면 통과가 아니라 위반이다
    var topBars = Array.prototype.filter.call(document.querySelectorAll('[data-rui-topbar], .topbar, .ws-bar'), function (e) { var q = e.getBoundingClientRect(); return e.getClientRects().length && q.top < 4 && q.width > q.height * 2; });   // G28: 가로로 긴 띠만(왼쪽 세로 메뉴가 .topbar 클래스를 써도 위쪽 띠로 보지 않는다, Demo App B 보고)
    var topMode = document.documentElement.getAttribute('data-rui-layout') === 'top' || topBars.length > 0;
    if (topMode && innerWidth > 860 && !onLogin) {
      var ruiSide = document.querySelector('.side'); var bar = (ruiSide && ruiSide.getClientRects().length && document.documentElement.getAttribute('data-rui-layout') === 'top') ? ruiSide : (topBars[0] || null), lines = [], off2 = [];
      if (!bar) rows.push({ item: '위쪽 띠 찾기(G24)', sel: '[data-rui-topbar]', want: true, got: false, ok: false });
      if (bar) {
        Array.prototype.forEach.call(bar.querySelectorAll('.nav-g, .nav-cl, .nav-sys, .nav-sysd, [data-rui-brand], .brand'), function (el) { var bs = getComputedStyle(el); if (el.getClientRects().length && /^(solid|dashed|dotted|double)$/.test(bs.borderLeftStyle) && parseFloat(bs.borderLeftWidth) > 0) lines.push(String(el.className || '').split(' ')[0] || (el.hasAttribute('data-rui-brand') ? '[data-rui-brand]' : el.tagName)); });
        var row1 = document.documentElement.getAttribute('data-rui-toprow') !== 'two';
        var ref = Array.prototype.filter.call(bar.querySelectorAll('.side-head, [data-rui-brand], .brand'), function (e) { var q = e.getBoundingClientRect(); return e.getClientRects().length && q.height > 0 && q.width > 0; })[0] || bar; /* G26: 숨은 브랜드 칸은 기준으로 쓰지 않는다(Demo App C 보고) */ var rr = ref.getBoundingClientRect(); if (ref === bar || rr.height > bar.getBoundingClientRect().height + 1) rr = bar.getBoundingClientRect(); var mid = rr.top + rr.height / 2;
        if (rr) Array.prototype.forEach.call(bar.querySelectorAll('.side-head .logo, .side-head .side-title, .side-head .ver, [data-rui-brand] .logo, [data-rui-brand] .ver, .brand .logo, .brand .ver, .side-me, [data-rui-me], [data-rui-me] > *' + (row1 ? ', .nav > a, .nav-gh, .nav-cl > a, .find, .side-links > a, .side-links > button, .side-me > button, .side-me .avatar, [data-rui-topbar] > a, [data-rui-topbar] > button, [data-rui-topbar] > * > a, [data-rui-topbar] > * > button, .topbar > a, .topbar > button, .topbar > * > a, .topbar > * > button' : '')), function (el) {
          if (!el.getClientRects().length) return; var b2 = el.getBoundingClientRect(); if (!b2.height) return;
          var d = Math.abs(b2.top + b2.height / 2 - mid); if (d > 1.5) off2.push((el.className || el.tagName).toString().split(' ')[0] + ' ' + d.toFixed(1) + 'px');
        });
        rows.push({ item: '위쪽 메뉴 세로 구분선 없음(G23)', sel: '.nav-g·.nav-cl·.nav-sys·.nav-sysd', want: 0, got: lines.length ? lines.join(', ') : 0, ok: !lines.length });
        var nv = bar.querySelector('.nav'); if (nv && nv.getClientRects().length && row1) { var ns = getComputedStyle(nv); var pad = parseFloat(ns.paddingTop) + parseFloat(ns.paddingBottom); var sb = nv.offsetHeight - nv.clientHeight - parseFloat(ns.borderTopWidth) - parseFloat(ns.borderBottomWidth); rows.push({ item: '위쪽 메뉴 줄 여백·가로 막대 없음(G25)', sel: '.nav', want: 0, got: (pad || sb) ? ('padding ' + pad + 'px, 막대 ' + sb + 'px') : 0, ok: !pad && !sb }); }
        if (rr && row1 && bar !== document.querySelector('.side')) {   // G29: 보이는 마지막 칸과 그 직계 자식(오른쪽 계정 상자 묶음, Demo App C 보고)
          var kids = Array.prototype.filter.call(bar.children, function (k) { var q = k.getBoundingClientRect(); return k.getClientRects().length && q.width > 0 && q.height > 0; });
          var last = kids[kids.length - 1];
          if (last) [last].concat(Array.prototype.slice.call(last.children)).forEach(function (el) { if (!el.getClientRects().length) return; var b3 = el.getBoundingClientRect(); if (!b3.height || !b3.width) return; var d3 = Math.abs(b3.top + b3.height / 2 - mid); if (d3 > 1.5) off2.push((String(el.className || '') || el.tagName).split(' ')[0] + ' ' + d3.toFixed(1) + 'px'); });
        }
        rows.push({ item: '위쪽 띠 세로 가운데 정렬(G23)', sel: '.side > *', want: 0, got: off2.length ? off2.slice(0, 6).join(', ') : 0, ok: !off2.length });
        var hbs = ref && ref !== bar ? getComputedStyle(ref) : null; var hb = hbs && /^(solid|dashed|dotted|double)$/.test(hbs.borderBottomStyle) ? parseFloat(hbs.borderBottomWidth) : 0;   // G26: 버튼 기본 테두리(outset 등)는 밑줄로 보지 않는다
        rows.push({ item: '위쪽 띠 브랜드 칸 밑줄 없음(G23)', sel: '.side-head', want: 0, got: hb, ok: !hb });
      }
    }
    rows.push({ item: '위쪽 띠 넘침', sel: '.main-bar·.mobile-bar·.side-head', want: 0, got: cut.length ? cut.join(' | ') : 0, ok: !cut.length });
    var squash = [];
    Array.prototype.forEach.call(document.querySelectorAll('.icon-btn, [data-rui-sys]:not(.rui-sysrow)'), function (el) { var b = el.getBoundingClientRect(); if (!b.width || el.closest('[hidden]')) return; if (b.width < 26 || b.height < 26) squash.push((el.getAttribute('aria-label') || el.title || el.className || '').toString().slice(0, 12) + ' ' + Math.round(b.width) + 'x' + Math.round(b.height)); });
    rows.push({ item: '눌린 버튼(26px 미만)', sel: '.icon-btn·[data-rui-sys]', want: 0, got: squash.length ? squash.slice(0, 8).join(' | ') : 0, ok: !squash.length });
    var filled = 0; Array.prototype.forEach.call(document.querySelectorAll('svg.i'), function (sv) { var b = sv.getBoundingClientRect(); if (b.width && getComputedStyle(sv).fill !== 'none') filled++; });
    rows.push({ item: '채워진 선 아이콘', sel: 'svg.i', want: 0, got: filled, ok: !filled });
    if (document.querySelector('.rui-login')) {   // G16: 공통 로그인 화면의 필수 요소
      var lg2 = document.querySelector('.rui-login'), need = ['.rui-login-brand .logo', '.rui-login-main h1', '.rui-login-help', '[data-rui-login-mode]'];
      var miss = need.filter(function (q) { return !lg2.querySelector(q); });
      rows.push({ item: '공통 로그인 화면(G16)', sel: '.rui-login', want: 0, got: miss.length ? miss.join(', ') : 0, ok: !miss.length });
    } else if (document.querySelector('.login, form [type=password]') && !document.querySelector('.side, .main-bar')) rows.push({ item: '공통 로그인 화면(G16)', sel: 'RUI.login', want: 'RUI.login', got: '자체 로그인 화면', ok: false });
    var font_ok = !!(document.fonts && Array.prototype.some.call(Array.from(document.fonts), function (f) { return /pretendard/i.test(f.family); }));
    rows.push({ item: 'Pretendard 읽음', sel: 'document.fonts', want: true, got: font_ok, ok: font_ok });
    return { ok: rows.every(function (r) { return r.ok; }), guideline: RUI.GUIDELINE, checked: rows.length, wrong: rows.filter(function (r) { return !r.ok; }) };
  };
  RUI.apply = function () {
    var p = RUI.get(), r = document.documentElement; RUI.font();
    r.setAttribute('data-rui-mode', RUI.mode(p));
    r.setAttribute('data-rui-palette', p.palette);
    r.setAttribute('data-rui-theme', p.theme);
    r.setAttribute('data-rui-layout', p.layout);
    r.setAttribute('data-rui-side', p.side === 'mini' ? 'mini' : 'open');
    r.setAttribute('data-rui-topnav', p.topnav);
    r.setAttribute('data-rui-toprow', p.toprow);
    return p;
  };
  /* 왼쪽 메뉴 접기. 버튼은 어느 시스템이든 RUI.sideButton() 을 메인 위쪽 줄 맨 왼쪽에 넣기만 하면 된다. 단축키 Ctrl+B */
  RUI.sideToggle = function () { var mini = RUI.get().side !== 'mini'; RUI.set({ side: mini ? 'mini' : 'open' }); RUI.sideMark(); return mini; };
  RUI.sideButton = function () {
    return '<button type="button" class="icon-btn side-toggle" data-rui-side-toggle aria-label="왼쪽 메뉴 접기" title="왼쪽 메뉴 접기 (Ctrl+B)"><svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v14H4zM9 5v14M16 9l-3 3 3 3"/></svg></button>';
  };
  RUI.sideMark = function () {   // 접힌 메뉴는 글자가 보이지 않으니 마우스 설명으로 이름을 보여 준다
    var mini = RUI.get().side === 'mini';
    Array.prototype.forEach.call(document.querySelectorAll('.nav a'), function (a) { if (!a.getAttribute('title') || a.hasAttribute('data-rui-t')) { if (mini) { a.setAttribute('title', a.textContent.trim()); a.setAttribute('data-rui-t', ''); } else if (a.hasAttribute('data-rui-t')) { a.removeAttribute('title'); a.removeAttribute('data-rui-t'); } } });
    Array.prototype.forEach.call(document.querySelectorAll('[data-rui-side-toggle]'), function (b) { var t = mini ? '왼쪽 메뉴 펼치기' : '왼쪽 메뉴 접기'; b.setAttribute('aria-label', t); b.title = t + ' (Ctrl+B)'; b.setAttribute('aria-expanded', String(!mini)); });
  };
  document.addEventListener('click', function (e) { var b = e.target.closest && e.target.closest('[data-rui-side-toggle]'); if (b) RUI.sideToggle(); });
  document.addEventListener('keydown', function (e) { if ((e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey && e.key.toLowerCase() === 'b' && document.querySelector('[data-rui-side-toggle]') && !(e.target.closest && e.target.closest('input,textarea,select,[contenteditable],[contenteditable="true"]'))) {   // 글 입력 중의 Ctrl+B 는 굵게다
    e.preventDefault(); RUI.sideToggle(); } });
  RUI.set = function (patch) {
    var p = Object.assign(RUI.get(), patch);
    try { localStorage.setItem(KEY, JSON.stringify(p)); } catch (e) { /* 이번 화면에만 적용된다 */ }
    RUI.apply();
    document.dispatchEvent(new CustomEvent('rui:change', { detail: p }));
    return p;
  };
  if (global.matchMedia) {
    var nq = global.matchMedia('(max-width: 860px)'); if (nq.addEventListener) nq.addEventListener('change', function () { RUI.set({}); });
    var mq = global.matchMedia('(prefers-color-scheme: dark)');
    if (mq.addEventListener) mq.addEventListener('change', function () { if (RUI.get().mode === 'system') RUI.set({}); });
  }
  /* 메뉴 그리기. 세 시스템이 같은 구조를 쓴다.
     groups = [{title, items:[{key,label,icon}], subs:[{title, keys:[key…]}]}]. link(항목) 은 <a> 문자열을 돌려준다.
     왼쪽 메뉴는 옵션과 무관하게 언제나 묶음 제목 아래 전부 펼친다.
     위쪽 메뉴: full 은 전부 펼침, dropdown 은 묶음마다 드롭다운 하나, smart 는 묶음을 세부 카테고리(subs)로 나눠 세부 카테고리마다 드롭다운 하나. */
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (m) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]; }); }
  function isTop() { return RUI.get().layout === 'top' && global.innerWidth > 860; }
  function drop(title, inner, down, cls) { return '<div class="nav-g ' + (cls || '') + '"><div class="nav-m"><button type="button" class="nav-gh" aria-expanded="false">' + esc(title) + (down || '') + '</button><div class="nav-gi">' + inner + '</div></div></div>'; }
  RUI.navHtml = function (groups, link, o) {
    o = o || {}; var down = o.down || '', p = RUI.get(), smart = isTop() && p.topnav === 'smart';
    return groups.map(function (g) {
      var items = g.items || []; if (!items.length) return '';
      var all = items.map(function (m) { return link(m); }).join('');
      if (!g.title) return all;
      var whole = drop(g.title, all, down, smart ? 'nav-whole' : '');
      if (!smart) return whole;
      var by = {}; items.forEach(function (m) { by[m.key] = m; });
      var used = {}, subs = (g.subs || []).map(function (c) {
        var list = (c.keys || []).map(function (k) { return by[k]; }).filter(Boolean); list.forEach(function (m) { used[m.key] = 1; });
        return { title: c.title, list: list };
      }).filter(function (c) { return c.list.length; });
      var rest = items.filter(function (m) { return !used[m.key]; });
      if (rest.length) subs.push({ title: subs.length ? g.title + ' 기타' : g.title, list: rest });
      return '<div class="nav-cl">' + subs.map(function (c) { return drop(c.title, c.list.map(function (m) { return link(m); }).join(''), down); }).join('') + '</div>' + whole;
    }).join('');
  };
  // 다른 시스템 메뉴. 왼쪽 메뉴와 다 펼치기는 그 시스템의 묶음대로 펼치고, 위쪽의 묶음 보기는 시스템 하나가 드롭다운 하나이고 안에 묶음 제목이 나온다
  RUI.navSystem = function (s, link, o) {
    o = o || {}; var p = RUI.get(), groups = (s.menu && s.menu.groups) || [], label = esc(s.label) + (s.reachable === false ? ' · 응답 없음' : ''), down = o.down || '';
    if (!groups.some(function (g) { return (g.items || []).length; })) return '';
    if (isTop() && p.topnav !== 'full') {
      // direct 묶음은 드롭다운에 넣지 않고 메뉴에 바로 둔다(다른 시스템)
      var dir = groups.filter(function (g) { return g.direct; }), rest = groups.filter(function (g) { return !g.direct && (g.items || []).length; });
      var head = dir.map(function (g) { return (g.items || []).map(function (m) { return link(m); }).join(''); }).join('');
      if (head) head = '<div class="nav-sysd" data-sys="' + esc(s.system) + '">' + head + '</div>';
      if (!rest.length) return head;
      return head + '<div class="nav-g nav-sys" data-sys="' + esc(s.system) + '"><div class="nav-m"><button type="button" class="nav-gh" aria-expanded="false">' + label + down + '</button><div class="nav-gi wide">' +
        rest.map(function (g) { return (g.title ? '<div class="nav-sub">' + esc(g.title) + '</div>' : '') + (g.items || []).map(function (m) { return link(m); }).join(''); }).join('') + '</div></div></div>';
    }
    var sub = (o.side || p.layout !== 'top' || global.innerWidth <= 860) ? groups.map(function (g) { return Object.assign({}, g, { subs: null }); }) : groups;
    return '<div class="nav-sys" data-sys="' + esc(s.system) + '"><div class="nav-sysh">' + label + '</div>' + RUI.navHtml(sub, link, o) + '</div>';
  };
  // 세부 카테고리가 한 줄에 안 들어가면 큰 묶음 드롭다운으로 줄인다
  RUI.navFit = function (nav) {
    var run = function () {
      nav.classList.remove('fit');
      var p = RUI.get(); if (p.layout !== 'top' || p.topnav !== 'smart' || global.innerWidth <= 860) return;
      if (nav.scrollWidth > nav.clientWidth + 1) nav.classList.add('fit');
    };
    if (!nav._ruiFit && global.ResizeObserver) { nav._ruiFit = new ResizeObserver(run); nav._ruiFit.observe(nav); }
    run();
  };
  // 활성 메뉴 표시. key 가 같은 링크와 묶음에 표시를 단다
  RUI.navMark = function (nav, key) {
    Array.prototype.forEach.call(nav.querySelectorAll('a[data-key]'), function (a) { var on = a.dataset.key === key; a.classList.toggle('on', on); if (on) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current'); });
    Array.prototype.forEach.call(nav.querySelectorAll('.nav-g'), function (g) { g.classList.toggle('has-on', !!g.querySelector('a.on')); });
    Array.prototype.forEach.call(nav.querySelectorAll('.nav-m.open'), function (m) { m.classList.remove('open'); });
  };
  // 화면 설정 패널. el 안에 그린다. o.layout=false 면 메뉴 형태를 뺀다. o.legacy 함수가 있으면 이전 테마 버튼을 보인다.
  // G9: 시스템 바로 가기. 모든 시스템이 같은 목록을 쓴다. 시스템이 늘면 여기 한 줄만 더하고 다시 배포한다.
  // 위쪽 띠의 버튼 하나(RUI.sysButton)와 펼쳐지는 목록(RUI.sysOpen)이다. 왼쪽 메뉴 아래에는 버튼 한 줄만 둔다(RUI.sysRow).
  RUI.SYSTEMS = [
    { id: 'admin', name: 'Demo App A', group: '업무', icon: 'layers', url: 'https://app-a.example.com', sso: '/sso/start', desc: '예시 시스템' },
    { id: 'kanban', name: 'Demo App B', group: '업무', icon: 'grid', url: 'https://app-b.example.com', sso: '/sso/start', desc: '예시 시스템' },
    { id: 'slc', name: 'Demo App C', group: '업무', icon: 'cap', url: 'https://app-c.example.com', sso: '/sso/start', desc: '예시 시스템' },
    { id: 'aimgmt', name: 'Demo AI App A', group: 'AI', icon: 'chats', url: 'https://ai-a.example.com', sso: '/sso/start', desc: 'AI 키를 서버에서 받는 예시 앱' },
    { id: 'aioffice', name: 'Demo AI App B', group: 'AI', icon: 'spark', url: 'https://ai-b.example.com', sso: '/sso/start', desc: 'AI 키를 서버에서 받는 예시 앱' },
    { id: 'lab1', name: 'Demo Lab App A', group: '연구', icon: 'shield', url: 'https://lab-a.example.com', sso: '/sso/start', desc: '예시 시스템' },
    { id: 'lab2', name: 'Demo Lab App B', group: '연구', icon: 'map', url: 'https://lab-b.example.com', sso: '/sso/start', desc: '예시 시스템' },
    { id: 'portal', name: 'Demo Portal', group: '공통', icon: 'home', url: 'https://portal.example.com', desc: '전체 시스템 상태' },
    { id: 'ulogin', name: '통합로그인시스템', group: '공통', icon: 'idcard', url: 'https://login.example.com', desc: '계정·로그인' }
  ];
  RUI.HUB_LINK = ['admin', 'kanban', 'slc'];   // G11: 계정 연결(hub-link) 자동 로그인은 이 세 곳만. 나머지는 통합로그인
  var sysOpt = {};
  RUI.sysButton = function () { return '<button type="button" class="icon-btn rui-sysbtn" data-rui-sys aria-haspopup="dialog" aria-label="다른 시스템" title="다른 시스템">' + RUI.icon('grid') + '</button>'; };
  RUI.sysRow = function () { return '<button type="button" class="rui-sysrow" data-rui-sys aria-haspopup="dialog">' + RUI.icon('grid') + '<span>다른 시스템</span><small>' + (RUI.SYSTEMS.length - 1) + '</small></button>'; };
  // o: { self:'admin', status:{kanban:'on'|'off'}, url:{kanban:'https://…'} } 상태·주소는 없어도 된다
  RUI.sysInit = function (o) {
    sysOpt = o || {};
    if (RUI._sysBound) return; RUI._sysBound = 1;
    document.addEventListener('click', function (e) {
      var b = e.target.closest && e.target.closest('[data-rui-sys]'); if (b) { e.preventDefault(); RUI.sysOpen(b); return; }
      var pop = document.querySelector('.rui-sys-pop'); if (pop && !pop.contains(e.target)) RUI.sysClose();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') RUI.sysClose(); });
  };
  RUI.sysSet = function (patch) { Object.keys(patch || {}).forEach(function (k) { sysOpt[k] = patch[k]; }); var p = document.querySelector('.rui-sys-pop'); if (p) RUI.sysOpen(p._anchor, true); };
  RUI.sysClose = function () { var p = document.querySelector('.rui-sys-pop'); if (p) { var a = p._anchor; p.remove(); if (a) a.setAttribute('aria-expanded', 'false'); } };
  RUI.sysOpen = function (anchor, keep) {
    var old = document.querySelector('.rui-sys-pop'); if (old) { var same = old._anchor === anchor; RUI.sysClose(); if (same && !keep) return; }
    var st = sysOpt.status || {}, urls = sysOpt.url || {}, groups = [];
    RUI.SYSTEMS.forEach(function (s) { var g = groups.filter(function (x) { return x.t === s.group; })[0]; if (!g) groups.push(g = { t: s.group, l: [] }); g.l.push(s); });
    var item = function (s) {
      var me = s.id === sysOpt.self || (sysOpt.self === 'aioffice' && s.id === 'aimgmt' && sysOpt.selfBoth), dot = st[s.id] ? '<span class="dot ' + esc(st[s.id]) + '"></span>' : '';
      // G11: Demo App A·B·C 사이는 계정 연결(hub-link)로 자동 로그인한다(sysOpt.jump·linked).
      // 그 밖의 시스템은 통합로그인(SSO)으로 들어간다. sysOpt.sso 가 켜져 있으면 상대의 /sso/start 로 열어 같은 통합로그인 계정으로 바로 들어간다.
      var linked = !me && RUI.HUB_LINK.indexOf(s.id) >= 0 && !!(sysOpt.linked && sysOpt.linked[s.id]) && typeof sysOpt.jump === 'function';
      var sso = !me && !linked && !!sysOpt.sso && !!s.sso;
      var href = (urls[s.id] || s.url) + (sso ? s.sso : '');
      var sub = me ? '지금 여기' : linked ? '연결된 계정으로 자동 로그인' : sso ? '통합로그인으로 바로 들어가기' : esc(s.desc);
      var inner = '<span class="rui-sys-ic">' + RUI.icon(s.icon) + '</span><span class="rui-sys-tx"><b>' + esc(s.name) + '</b><small' + (linked || sso ? ' class="rui-sys-auto"' : '') + '>' + sub + '</small></span>' + dot;
      return me ? '<div class="rui-sys-it me" aria-current="true">' + inner + '</div>' : '<a class="rui-sys-it" href="' + esc(href) + '" target="_blank" rel="noopener"' + (linked ? ' data-rui-jump="' + esc(s.id) + '"' : '') + '>' + inner + '</a>';
    };
    var note = sysOpt.note || (sysOpt.sso ? '통합로그인 계정으로 다른 시스템에 바로 들어갑니다. Demo App A·B·C은 연결된 계정으로 자동 로그인합니다.'
      : 'Demo App A·B·C은 연결된 계정으로 자동 로그인합니다. 그 밖의 시스템은 통합로그인을 켜기 전까지 따로 로그인하며, 이 브라우저에 남은 그 시스템 로그인이 그대로 보일 수 있습니다.');
    var pop = document.createElement('div'); pop.className = 'rui-sys-pop'; pop.setAttribute('role', 'dialog'); pop.setAttribute('aria-label', '다른 시스템');
    pop.innerHTML = '<div class="rui-sys-head"><b>시스템 바로 가기</b><small>' + RUI.SYSTEMS.length + '곳 · 새 탭</small></div>' + groups.map(function (g) { return '<div class="rui-sys-g"><div class="rui-sys-gt">' + esc(g.t) + '</div><div class="rui-sys-gl">' + g.l.map(item).join('') + '</div></div>'; }).join('') + '<p class="rui-sys-note">' + esc(note) + '</p>';
    var host = anchor && anchor.closest('.rui') || document.querySelector('.rui') || document.body; host.appendChild(pop); pop._anchor = anchor;
    if (anchor) anchor.setAttribute('aria-expanded', 'true');
    var r = anchor ? anchor.getBoundingClientRect() : { left: 16, bottom: 56, right: 16, top: 56 }, w = pop.offsetWidth, h = pop.offsetHeight, vw = global.innerWidth, vh = global.innerHeight;
    var left = Math.min(Math.max(8, r.right - w), vw - w - 8), top = r.bottom + 6;
    if (top + h > vh - 8) top = Math.max(8, r.top - h - 6);   // 왼쪽 메뉴 아래 줄에서 열면 위로 연다
    if (r.left < 300 && r.top > vh / 2) left = Math.max(8, Math.min(r.left, vw - w - 8));   // G11: 숨은(화면 밖) 메뉴 줄에서 열려도 화면 안에 둔다
    pop.style.left = left + 'px'; pop.style.top = top + 'px';
    pop.addEventListener('click', function (e) {
      var a = e.target.closest('a'); if (!a) return;
      // 자동 로그인: 새 창은 누른 순간 열어야 팝업 차단에 걸리지 않는다. jump(id, 창 이름, 주소)가 그 창으로 표를 보낸다
      if (a.dataset.ruiJump && typeof sysOpt.jump === 'function') {
        e.preventDefault(); var name = 'rui_sys_' + Date.now(), w = global.open('about:blank', name);
        try { sysOpt.jump(a.dataset.ruiJump, w ? name : '', a.href); } catch (er) { if (w) w.location.href = a.href; }
      }
      setTimeout(RUI.sysClose, 0);
    });
    if (!keep) { var f = pop.querySelector('a'); if (f) f.focus(); }
  };
  // G16: 공통 로그인 화면. 모든 시스템(통합로그인 자신 포함)이 이 부품 하나로 그린다. 문구·순서·크기는 GUIDELINE 4-3장.
  // o: { self, name, version, tagline, desc, points[], systems(bool), sso:{mode:'off'|'choice'|'only', ready, href}, local(bool, 기본 true),
  //      onSubmit: async function(id, pw) — 실패면 Error(사람이 읽을 문장)를 던진다, idLabel, idAuto, help, hideSso }
  // G25: 위쪽 메뉴 「다 펼치기」는 가로 막대를 숨기므로 세로 휠을 가로 이동으로 바꾼다
  document.addEventListener('wheel', function (e) {
    var nav = e.target && e.target.closest && e.target.closest('[data-rui-topbar] .nav');   // G27: 자체 위쪽 띠(G24 표식)도(Demo App C 보고)
    if (!nav) { if (document.documentElement.getAttribute('data-rui-layout') !== 'top' || document.documentElement.getAttribute('data-rui-topnav') !== 'full') return; nav = e.target && e.target.closest && e.target.closest('.side .nav'); } if (!nav || nav.scrollWidth <= nav.clientWidth || Math.abs(e.deltaY) <= Math.abs(e.deltaX)) return;
    nav.scrollLeft += e.deltaY; e.preventDefault();
  }, { passive: false });
  RUI.login = function (el, o) {
    o = o || {}; var sys = null; RUI.SYSTEMS.forEach(function (x) { if (x.id === o.self) sys = x; });
    var name = o.name || (sys && sys.name) || '', icon = (sys && sys.icon) || 'lock';
    var brand = '<div class="rui-login-id"><span class="logo" aria-hidden="true">' + RUI.icon(icon) + '</span><b>' + esc(name) + '</b>' + (o.version ? '<span class="ver">' + esc(o.version) + '</span>' : '') + '</div>';
    var pts = (o.points || []).slice(0, 3).map(function (t) { return '<li>' + RUI.icon('check') + '<span>' + esc(t) + '</span></li>'; }).join('');
    var list = o.systems ? '<div class="rui-login-sys"><small>연결시스템</small><ul>' +
      RUI.SYSTEMS.filter(function (x) { return x.sso; }).map(function (x) { return '<li>' + RUI.icon(x.icon) + '<span><b>' + esc(x.name) + '</b><em>' + esc(x.desc || '') + '</em></span></li>'; }).join('') + '</ul></div>' : '';
    el.innerHTML = '<div class="rui-login">' +
      '<aside class="rui-login-brand">' + brand +
        '<div class="rui-login-pitch">' + (o.tagline ? '<h2>' + esc(o.tagline) + '</h2>' : '') + (o.desc ? '<p>' + esc(o.desc) + '</p>' : '') + (pts ? '<ul class="rui-login-pts">' + pts + '</ul>' : '') + '</div>' + list +
        '<p class="rui-login-foot">시스템 허브 · ' + esc(name) + '</p></aside>' +
      '<main class="rui-login-main"><div class="rui-login-tools"><button type="button" class="icon-btn" data-rui-login-mode aria-label="밝기 바꾸기" title="밝기 바꾸기"></button></div>' +
        '<form class="rui-login-form" novalidate><h1>로그인</h1><p class="rui-login-hint" data-hint></p>' +
          '<div class="rui-login-sso" data-sso hidden></div>' +
          '<div class="rui-login-or" data-or hidden><span>또는 이 시스템 계정</span></div>' +
          '<div class="rui-login-local" data-local>' +
            '<label>' + esc(o.idLabel || '아이디') + '<input class="input" name="u" autocomplete="' + esc(o.idAuto || 'username') + '" required></label>' +
            '<label>비밀번호<input class="input" name="p" type="password" autocomplete="current-password" required></label>' +
            '<div class="rui-login-err" role="alert" aria-live="polite"></div>' +
            '<button class="btn primary rui-login-go" type="submit">로그인</button></div>' +
          '<p class="rui-login-help">' + esc(o.help || '계정 없음·비밀번호 분실 시 관리자 문의') + '</p>' +
        '</form>' +
        // G31(비상복구모드, emergency/1): 통합로그인 장애를 서버가 확인했을 때만 연다
        '<form class="rui-login-form rui-login-emg" data-emg-form novalidate hidden><h1>비상복구모드</h1><p class="rui-login-hint">통합로그인 장애 중에만 사용 · 마지막 통합로그인 비밀번호로 이 시스템에만 로그인 · 최대 8시간</p>' +
          '<label>통합로그인 아이디<input class="input" name="eu" autocomplete="username" required></label>' +
          '<label>통합로그인 비밀번호<input class="input" name="ep" type="password" autocomplete="current-password" required></label>' +
          '<div class="rui-login-err" data-emg-err role="alert" aria-live="polite"></div>' +
          '<button class="btn primary rui-login-go" data-emg-go type="submit">비상 로그인</button>' +
          '<button class="btn rui-login-back" type="button" data-emg-back>일반 로그인으로 돌아가기</button></form></main></div>';
    var f = el.querySelector('form'), err = f.querySelector('.rui-login-err'), go = f.querySelector('.rui-login-go'), hint = f.querySelector('[data-hint]');
    var mb = el.querySelector('[data-rui-login-mode]');
    var paintMode = function () { mb.innerHTML = RUI.icon(RUI.mode() === 'dark' ? 'sun' : 'moon'); };
    mb.onclick = function () { RUI.set({ mode: RUI.mode() === 'dark' ? 'light' : 'dark' }); paintMode(); }; paintMode();
    document.addEventListener('rui:change', function () { if (mb.isConnected) paintMode(); });   // G17: 설정 등 다른 곳에서 밝기를 바꿔도 아이콘을 맞춘다
    var api = {
      error: function (m) { err.textContent = m || ''; },
      setSso: function (s) {
        s = s || { mode: 'off' }; var box = f.querySelector('[data-sso]'), or = f.querySelector('[data-or]'), local = f.querySelector('[data-local]');
        var on = !o.hideSso && s.mode && s.mode !== 'off', only = on && s.mode === 'only', useLocal = o.local !== false && !only;
        box.hidden = !on; or.hidden = !(on && useLocal); local.hidden = !useLocal;
        Array.prototype.forEach.call(local.querySelectorAll('input'), function (i) { i.disabled = !useLocal; i.required = useLocal; });
        go.classList.toggle('primary', !on);
        if (on) box.innerHTML = s.ready === false ? '<p class="rui-login-note">통합로그인 연결 준비 중, 잠시 후 재시도</p>'
          : '<a class="btn primary rui-login-sso-go" href="' + esc(s.href || '/sso/start') + '">' + RUI.icon('idcard') + '<span>통합로그인으로 계속</span></a>';
        if (on && s.emergency && o.onEmergency) box.innerHTML += '<p class="rui-login-note rui-login-emg-note">통합로그인 응답 없음</p><button type="button" class="btn rui-login-emg-open" data-emg-open>' + RUI.icon('lock') + '<span>통합로그인 접속 불가 · 비상복구모드</span></button>';
        hint.textContent = only ? '통합로그인 계정으로만 로그인' : on ? '통합로그인 계정 또는 이 시스템 계정으로 로그인' : '이 시스템 계정으로 로그인';
        if (useLocal && !on) { var u = f.querySelector('[name=u]'); if (u && document.activeElement === document.body) u.focus(); }
      }
    };
    var ef = el.querySelector('[data-emg-form]'), eerr = ef.querySelector('[data-emg-err]'), ego = ef.querySelector('[data-emg-go]');
    var showEmg = function (v) { ef.hidden = !v; f.hidden = v; if (v) ef.eu.focus(); };
    f.addEventListener('click', function (e) { if (e.target.closest('[data-emg-open]')) showEmg(true); });
    ef.querySelector('[data-emg-back]').onclick = function () { showEmg(false); };
    ef.onsubmit = async function (e) {
      e.preventDefault(); var id = ef.eu.value.trim(), pw = ef.ep.value;
      if (!id || !pw) { eerr.textContent = !id ? '아이디를 입력하세요.' : '비밀번호를 입력하세요.'; (!id ? ef.eu : ef.ep).focus(); return; }
      eerr.textContent = ''; ego.disabled = true; ego.textContent = '확인하는 중…';
      try { await o.onEmergency(id, pw); } catch (er) { eerr.textContent = (er && er.message) || '비상 로그인을 할 수 없습니다.'; ef.ep.select(); }
      ego.disabled = false; ego.textContent = '비상 로그인';
    };
    f.onsubmit = async function (e) {
      e.preventDefault(); if (f.querySelector('[data-local]').hidden) return;
      var id = f.u.value.trim(), pw = f.p.value;
      if (!id || !pw) { api.error(!id ? '아이디를 입력하세요.' : '비밀번호를 입력하세요.'); (!id ? f.u : f.p).focus(); return; }
      api.error(''); go.disabled = true; go.textContent = '확인하는 중…';
      try { await o.onSubmit(id, pw); } catch (er) { api.error((er && er.message) || '로그인하지 못했습니다. 다시 시도하세요.'); f.p.select(); }
      go.disabled = false; go.textContent = '로그인';
    };
    api.setSso(o.sso || { mode: 'off' });
    return api;
  };
  /* G31: 비상복구모드 띠. 비상 세션이면 모든 화면 맨 위에 둔다. 없애려면 RUI.emergencyBar(null) */
  RUI.emergencyBar = function (text) {
    var b = document.getElementById('ruiEmgBar');
    if (text === null || text === false) { if (b) b.remove(); document.documentElement.removeAttribute('data-rui-emergency'); return; }
    if (!b) { b = document.createElement('div'); b.id = 'ruiEmgBar'; b.className = 'rui-emg-bar'; b.setAttribute('role', 'status'); document.body.insertBefore(b, document.body.firstChild); }
    b.innerHTML = RUI.icon('lock') + '<span>' + esc(text || '비상복구모드 · 통합로그인 접속 불가 중 · 복구되면 다시 로그인') + '</span>';
    document.documentElement.setAttribute('data-rui-emergency', '1');
  };
  RUI.picker = function (el, o) {
    o = o || {};
    var seg = function (key, rows, cur) {
      return '<div class="seg" role="group">' + rows.map(function (r) { return '<button type="button" data-k="' + key + '" data-v="' + r[0] + '" aria-pressed="' + (r[0] === cur) + '" class="' + (r[0] === cur ? 'on' : '') + '">' + r[1] + '</button>'; }).join('') + '</div>';
    };
    var paint = function () {
      var p = RUI.get(), dark = RUI.mode(p) === 'dark';
      el.innerHTML = '<div class="pick"><section><h3>공통 테마</h3>' + seg('theme', [['1', '공통 범용 테마 1'], ['2', '공통 범용 테마 2']], p.theme) + '</section><section><h3>밝기</h3>' + seg('mode', [['system', '시스템'], ['light', '밝게'], ['dark', '어둡게']], p.mode) + '</section>' +
        (o.layout === false ? '' : '<section><h3>메뉴 형태</h3>' + seg('layout', [['side', '왼쪽 메뉴'], ['top', '위쪽 메뉴']], p.layout) + (p.layout === 'top' ? '<h3 style="margin-top:12px">위쪽 메뉴 줄</h3>' + seg('toprow', [['inline', '한 줄'], ['two', '두 줄']], p.toprow) + '<h3 style="margin-top:12px">위쪽 메뉴 묶음</h3>' + seg('topnav', [['full', '다 펼치기'], ['smart', '세부 카테고리'], ['dropdown', '큰 묶음']], p.topnav) : '') + '</section>') +
        (o.unified ? '<section><h3>통합 메뉴</h3>' + seg('unified', [['off', '끄기'], ['on', '켜기']], p.unified ? 'on' : 'off') + (o.unified.linked ? '' : '<p>연동된 시스템이 있어야 메뉴가 나타납니다. 시스템 연동 화면에서 연결하세요.</p>') + '</section>' : '') +
        '<section><h3>색상</h3><div class="swatches">' + RUI.PALETTES.map(function (x) {
          return '<button type="button" data-k="palette" data-v="' + x[0] + '" aria-pressed="' + (x[0] === p.palette) + '" class="' + (x[0] === p.palette ? 'on' : '') + '"><i style="background:' + (dark ? x[3] : x[2]) + '"></i>' + x[1] + '</button>';
        }).join('') + '</div></section>' +
        (o.legacy ? '<section><h3>이전 테마</h3><p>예전 화면으로 돌아갑니다. 예전 화면의 설정에서 다시 이 테마로 올 수 있습니다.</p><button type="button" class="btn" data-legacy="1">이전 테마로 돌아가기</button></section>' : '') + '</div>';
    };
    el.onclick = function (e) {
      if (e.target.closest('[data-legacy]')) { o.legacy(); return; }
      var b = e.target.closest('[data-k]'); if (!b) return;
      var patch = {}; patch[b.dataset.k] = b.dataset.k === 'unified' ? b.dataset.v === 'on' : b.dataset.v;
      if (b.dataset.k === 'theme') { var cur = RUI.get().palette; if (b.dataset.v === '2' && cur === 'teal') patch.palette = 'lab'; if (b.dataset.v === '1' && cur === 'lab') patch.palette = 'teal'; }   // 각 테마의 기본 색으로
      RUI.set(patch); paint();
    };
    paint();
  };
  // 새 버전 알림. 첫 화면 파일의 ?v= 값이 지금 탭과 달라지면 알린다. 입력 중일 수 있어 자동으로 새로고침하지 않는다.
  // 다른 시스템 의 방식을 공용으로 옮겼다. o.page 는 첫 화면 파일(기본 index.html).
  // G18(GUIDELINE 6장): 모든 시스템·모든 화면(로그인 화면 포함)이 이것 하나로 새 버전을 알린다.
  //  1순위 버전 주소 o.url(기본 /version.json → {"version":"<버전>"}, 캐시 금지) 와 이 탭의 버전 o.version 을 견준다.
  //  o.version 이 없으면 <meta name="app-version">, 그것도 없으면 스크립트 주소의 ?v= 값. 버전 주소가 없으면(404) 예전 방식(o.page 의 ?v=)으로 돌아간다.
  RUI.updates = function (o) {
    o = o || {}; if (RUI.updatesOn || location.protocol === 'file:') return RUI.updatesCheck;
    var page = o.page || 'index.html', url = o.url || '/version.json', seen = null, useUrl = o.url !== false;
    var ver = function (t) { var m = /\?v=([A-Za-z0-9._-]+)/.exec(String(t || '')); return m ? m[1] : null; };
    var meta = document.querySelector('meta[name="app-version"]');
    var cur = String(o.version || (meta && meta.content) || ver(Array.prototype.map.call(document.scripts, function (x) { return x.getAttribute('src') || ''; }).filter(function (x) { return x.indexOf('?v=') >= 0; })[0]) || '');
    if (!cur) return;
    var check = async function () {
      if (document.hidden) return;
      var nb = null;
      if (useUrl) {
        try {
          var r = await fetch(url + (url.indexOf('?') < 0 ? '?' : '&') + '_=' + Date.now(), { cache: 'no-store', credentials: 'same-origin' });
          if (r.ok) { var j = await r.json(); nb = j && (j.version || (j.data && j.data.version)) ? String(j.version || j.data.version) : null; }
          else if (r.status === 404) useUrl = false;
        } catch (e) { return; }
      }
      if (!useUrl && !nb) { try { var r2 = await fetch(page + '?_=' + Date.now(), { cache: 'no-store' }); if (!r2.ok) return; nb = ver(await r2.text()); } catch (e) { return; } }
      if (!nb || nb === cur || nb === seen) return; seen = nb; RUI.updateShow(nb);
    };
    RUI.updatesOn = true; RUI.updatesCheck = check; RUI.updatesVersion = cur;
    setInterval(check, 60000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) check(); });
    window.addEventListener('focus', check);
    window.addEventListener('online', check);
    setTimeout(check, 3000);
    return check;
  };
  /* 전 시스템 같은 모양. 값은 ?v= 의 버전이다. 시험할 때 RUI.updateShow('v99') 로 띄워 볼 수 있다 */
  RUI.updateShow = function (nb) {
    var short = String(nb).replace(/^[a-z]*(v\d+.*)$/i, '$1');
    if (document.getElementById('ruiUpdate')) return;
      {
        var bar = document.createElement('div'); bar.id = 'ruiUpdate'; bar.setAttribute('role', 'status');
        bar.style.cssText = 'position:fixed;left:50%;bottom:16px;transform:translateX(-50%);z-index:9999;display:flex;flex-wrap:wrap;align-items:center;gap:10px;max-width:calc(100vw - 24px);padding:10px 14px;border:1px solid var(--rui-line-strong,#cdcdc5);border-radius:12px;background:var(--rui-surface,#fff);color:var(--rui-ink,#1b1d1c);font:13.5px/1.5 var(--rui-font,sans-serif);box-shadow:0 10px 28px rgb(0 0 0 / .18)';
        var txt = document.createElement('span'); txt.textContent = '새 버전이 나왔습니다(' + short + '). 새로고침하면 적용됩니다.';
        var btn = function (label, main, fn) { var b = document.createElement('button'); b.type = 'button'; b.textContent = label; b.style.cssText = 'height:32px;padding:0 12px;border-radius:8px;cursor:pointer;font:inherit;font-weight:600;border:1px solid ' + (main ? 'var(--rui-accent,#0f6b62);background:var(--rui-accent,#0f6b62);color:var(--rui-accent-ink,#fff)' : 'var(--rui-line-strong,#cdcdc5);background:none;color:inherit'); b.onclick = fn; return b; };
        bar.appendChild(txt); bar.appendChild(btn('새로고침', true, function () { location.reload(); })); bar.appendChild(btn('나중에', false, function () { bar.remove(); }));
        document.body.appendChild(bar);
      }
  };
  // 연동된 시스템에서 넘어올 때 ?rui=<값> 으로 화면 설정을 이어받는다(주소에서는 지운다)
  (function () {
    var m = /[?&]rui=([A-Za-z0-9_-]{1,200})/.exec(location.search); if (!m) return;
    try {
      var v = JSON.parse(atob(m[1].replace(/-/g, '+').replace(/_/g, '/'))), patch = {};
      ['theme', 'mode', 'palette', 'layout', 'topnav', 'toprow', 'unified'].forEach(function (k) { if (v[k] !== undefined) patch[k] = v[k]; });
      RUI.set(patch);
    } catch (e) { /* 값이 깨졌으면 무시한다 */ }
    if (history.replaceState) history.replaceState(null, '', location.pathname + location.hash);
  })();
  RUI.apply();
})(window);
