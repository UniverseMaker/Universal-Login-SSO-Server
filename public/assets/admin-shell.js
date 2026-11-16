/* ============================================================================
 * ulogin v1.8.3 — 관리자 콘솔의 틀 (admin-shell.js)
 *
 * research-ui 대시보드와 같은 틀: 왼쪽 메뉴 + 위쪽 띠(research-ui G14, GUIDELINE 4-1·5장).
 * 마크업 뼈대는 admin.html 에 있고(`[data-ul-shell]`), 이 파일은 그 안을 채우고 움직인다.
 *
 * ▣ 화면 코드(admin.html 의 인라인 스크립트)를 고치지 않는다
 *   탭은 지금처럼 `ui.js` 의 tabs() 가 `#tabbar` 에 만든다(권한별 탭·지연 로딩·#해시).
 *   이 파일은 그 **버튼을 읽어** 왼쪽 메뉴를 그리고, 메뉴를 누르면 그 버튼을 누른다.
 *   `#tabbar` 는 화면에서 숨긴다(ulogin-theme.css). 메뉴 표시는 버튼의 aria-selected 를 지켜본다.
 *   → 탭 구성·권한(D-36)·로딩 규칙은 한 곳(admin.html)에 그대로 남는다.
 *
 * ▣ 메뉴 형태 — `RUI.picker` 의 「메뉴 형태」가 전부 바뀐다
 *   왼쪽 메뉴(side) · 위쪽 메뉴(top) × 줄(toprow: inline·two) × 묶음(topnav: smart·dropdown·full).
 *   모양은 공용 rui.css 가 `<html data-rui-*>` 로 바꾼다. 여기서는 `rui:change` 때 메뉴를 다시
 *   그린다(`RUI.navHtml` 결과가 형태마다 다르다). 860px 이하는 서랍 메뉴다.
 *
 * ▣ CSP — 인라인 style 속성을 만들지 않는다. RUI 가 주는 마크업(sideButton·sysButton·sysRow·
 *   navHtml)에는 style 속성이 없다. 메뉴 이름은 이 파일의 상수이고 escape 한다.
 * ========================================================================== */
(function (global) {
  'use strict';
  var doc = global.document;
  var RUI = global.RUI;
  var root = doc.querySelector('[data-ul-shell]');
  if (!root || !RUI || typeof RUI.navHtml !== 'function') { return; }
  var TUI = global.ULOGIN_THEME_UI || {};
  var icon = typeof TUI.iconSvg === 'function' ? TUI.iconSvg : RUI.icon;

  /* 탭 키 → 메뉴 정보. 아이콘은 한 화면 안에서 겹치지 않게 RUI.ICONS 에서 골랐다(GUIDELINE 5장). */
  var META = {
    dash: { icon: 'chart' },
    users: { icon: 'users' },
    services: { icon: 'plug' },
    secrets: { icon: 'shield' },
    links: { icon: 'graph' },        /* v1.8.4 계정 연결 — 점과 선(연결) 모양. 다른 메뉴와 겹치지 않는다 */
    logs: { icon: 'history' },
    settings: { icon: 'gear' },
    bundle: { icon: 'archive' },
    /* v1.9.0 플랫폼 — 위 아이콘과 겹치지 않게 골랐다 */
    pconfig: { icon: 'layers' },
    plogs: { icon: 'report' },
    paudit: { icon: 'lock' },
    ptables: { icon: 'database' },
    pusage: { icon: 'trend' },
    access: { icon: 'target' }
  };
  /* 묶음. subs 는 위쪽 메뉴 「세부 카테고리」(topnav=smart) 에서 쓰인다. */
  var GROUPS = [
    { title: '', keys: ['dash'] },
    { title: '회원·서비스', keys: ['users', 'links', 'services', 'secrets'],
      subs: [{ title: '회원', keys: ['users', 'links'] }, { title: '서비스', keys: ['services', 'secrets'] }] },
    { title: '관리', keys: ['logs', 'settings', 'bundle'],
      subs: [{ title: '운영', keys: ['logs', 'settings', 'bundle'] }] },
    { title: '플랫폼', keys: ['pconfig', 'plogs', 'paudit', 'ptables', 'pusage', 'access'],
      subs: [{ title: '데이터', keys: ['pconfig', 'ptables', 'pusage'] }, { title: '기록·권한', keys: ['plogs', 'paudit', 'access'] }] },
    { title: '내 계정', keys: ['mypage'] }
  ];
  var EXTRA = { mypage: { label: '내 시스템', icon: 'card', href: 'mypage.html' } };

  var nav = root.querySelector('.nav');
  var shell = root.querySelector('.shell');
  var tabbar = doc.getElementById('tabbar');
  var barTitle = doc.getElementById('ul-bar-title');

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (m) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m];
    });
  }

  /* 지금 보이는 탭 버튼(권한으로 걸러진 것)만 메뉴가 된다. */
  function tabItems() {
    var out = {};
    if (!tabbar) { return out; }
    var btns = tabbar.querySelectorAll('[data-tab-key]');
    for (var i = 0; i < btns.length; i++) {
      var k = btns[i].getAttribute('data-tab-key');
      out[k] = { key: k, label: btns[i].textContent.trim(), icon: (META[k] || {}).icon || 'file' };
    }
    return out;
  }

  function currentKey() {
    if (!tabbar) { return ''; }
    var on = tabbar.querySelector('[aria-selected="true"]');
    return on ? on.getAttribute('data-tab-key') : '';
  }

  function link(m) {
    var href = m.href || ('#' + m.key);
    return '<a href="' + esc(href) + '" data-key="' + esc(m.key) + '">' + icon(m.icon) + '<span>' + esc(m.label) + '</span></a>';
  }

  function groups() {
    var items = tabItems();
    var hasTabs = Object.keys(items).length > 0;
    return GROUPS.map(function (g) {
      var list = g.keys.map(function (k) {
        if (items[k]) { return items[k]; }
        if (EXTRA[k] && hasTabs) { return { key: k, label: EXTRA[k].label, icon: EXTRA[k].icon, href: EXTRA[k].href }; }
        return null;
      }).filter(Boolean);
      return { title: g.title, items: list, subs: g.subs };
    });
  }

  function paintTitle(key) {
    if (!barTitle) { return; }
    while (barTitle.firstChild) { barTitle.removeChild(barTitle.firstChild); }
    var items = tabItems();
    if (!items[key]) { return; }
    var g = GROUPS.filter(function (x) { return x.keys.indexOf(key) >= 0; })[0];
    if (g && g.title) {
      var sm = doc.createElement('small');
      sm.textContent = g.title;
      barTitle.appendChild(sm);
    }
    barTitle.appendChild(doc.createTextNode(items[key].label));
  }

  function mark() {
    var key = currentKey();
    RUI.navMark(nav, key);
    paintTitle(key);
  }

  function paintNav() {
    nav.innerHTML = RUI.navHtml(groups(), link, { down: downIcon() });
    mark();
    if (typeof RUI.navFit === 'function') { RUI.navFit(nav); }
    if (typeof RUI.sideMark === 'function') { RUI.sideMark(); }
  }
  function downIcon() {
    return '<svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';
  }

  /* ─ 클릭: 메뉴 · 묶음 드롭다운 · 서랍 · 화면 설정 · 밝기 ─────────────── */
  root.addEventListener('click', function (e) {
    var t = e.target;
    var a = t.closest && t.closest('.nav a[data-key]');
    if (a && !EXTRA[a.getAttribute('data-key')]) {
      e.preventDefault();
      var b = doc.getElementById('tab-' + a.getAttribute('data-key'));
      if (b) { b.click(); }
    }
    var gh = t.closest && t.closest('.nav-gh');
    Array.prototype.forEach.call(root.querySelectorAll('.nav-m.open'), function (m) {
      if (!gh || m !== gh.parentNode) {
        m.classList.remove('open');
        var h = m.querySelector('.nav-gh');
        if (h) { h.setAttribute('aria-expanded', 'false'); }
      }
    });
    if (gh) {
      var on = gh.parentNode.classList.toggle('open');
      gh.setAttribute('aria-expanded', String(on));
    }
    if (t.closest && t.closest('[data-nav]')) { shell.classList.add('nav-open'); return; }
    if (t === shell || a) { shell.classList.remove('nav-open'); }
    if (t.closest && t.closest('[data-appearance]')) { openDrawer(); }
  });
  doc.addEventListener('click', function (e) {
    if (!root.contains(e.target)) {
      Array.prototype.forEach.call(root.querySelectorAll('.nav-m.open'), function (m) { m.classList.remove('open'); });
    }
  });
  doc.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { shell.classList.remove('nav-open'); closeDrawer(); }
  });

  /* ─ 화면 설정 서랍 (research-ui 와 같은 오른쪽 서랍) ──────────────────────── */
  var drawerHost = null, drawerFocus = null;
  function closeDrawer() {
    if (!drawerHost) { return; }
    drawerHost.remove();
    drawerHost = null;
    if (drawerFocus && drawerFocus.focus) { try { drawerFocus.focus(); } catch (e) { /* noop */ } }
  }
  function openDrawer() {
    if (drawerHost) { return; }
    drawerFocus = doc.activeElement;
    drawerHost = doc.createElement('div');
    drawerHost.className = 'ul-drawer-host';
    var scrim = doc.createElement('div');
    scrim.className = 'scrim';
    scrim.addEventListener('click', closeDrawer);
    var d = doc.createElement('aside');
    d.className = 'drawer';
    d.setAttribute('role', 'dialog');
    d.setAttribute('aria-modal', 'true');
    d.setAttribute('aria-label', '화면 설정');
    var head = doc.createElement('div');
    head.className = 'drawer-head';
    var hw = doc.createElement('div');
    var h = doc.createElement('h2');
    h.textContent = '화면 설정';
    var p = doc.createElement('p');
    p.className = 'muted small';
    p.textContent = '이 기기에만 저장됩니다';
    hw.appendChild(h);
    hw.appendChild(p);
    var x = doc.createElement('button');
    x.type = 'button';
    x.className = 'icon-btn';
    x.setAttribute('aria-label', '닫기');
    x.title = '닫기';
    x.innerHTML = icon('x');
    x.addEventListener('click', closeDrawer);
    head.appendChild(hw);
    head.appendChild(x);
    var body = doc.createElement('div');
    body.className = 'drawer-body';
    var pick = doc.createElement('div');
    body.appendChild(pick);
    d.appendChild(head);
    d.appendChild(body);
    drawerHost.appendChild(scrim);
    drawerHost.appendChild(d);
    root.appendChild(drawerHost);
    if (typeof TUI.pickerInto === 'function') { TUI.pickerInto(pick, {}); } else { RUI.picker(pick, {}); }
    try { x.focus(); } catch (e) { /* noop */ }
  }

  /* ─ 위쪽 띠 · 모바일 띠 · 왼쪽 아래 ───────────────────────────────── */
  function mountBars() {
    var sideSlot = root.querySelector('[data-ul-side-btn]');
    if (sideSlot && typeof RUI.sideButton === 'function') { sideSlot.outerHTML = RUI.sideButton(); }

    var acts = doc.getElementById('ul-bar-acts');
    if (acts) {
      if (typeof RUI.sysButton === 'function') { acts.insertAdjacentHTML('beforeend', RUI.sysButton()); }
      if (typeof TUI.makeModeButton === 'function') { acts.appendChild(TUI.makeModeButton('rui-mode-btn')); }
      var ap = doc.createElement('button');
      ap.type = 'button';
      ap.className = 'icon-btn';
      ap.setAttribute('data-appearance', '');
      ap.setAttribute('aria-label', '화면 설정');
      ap.title = '화면 설정';
      ap.innerHTML = RUI.icon('gear');
      acts.appendChild(ap);
    }
    var mob = root.querySelector('[data-ul-sys-mobile]');
    if (mob && typeof RUI.sysButton === 'function') { mob.outerHTML = RUI.sysButton(); }

    var links = doc.getElementById('ul-side-links');
    if (links && typeof RUI.sysRow === 'function') { links.innerHTML = RUI.sysRow(); }
    if (typeof RUI.sysInit === 'function') { RUI.sysInit({ self: 'ulogin' }); }
  }

  /* 왼쪽 아래 아바타 — #who("아이디 · 역할") 첫 글자. */
  var who = doc.getElementById('who');
  var avatar = doc.getElementById('ul-avatar');
  function paintAvatar() {
    if (!who || !avatar) { return; }
    var s = (who.textContent || '').trim();
    avatar.textContent = s ? s.charAt(0).toUpperCase() : '';
  }

  /* 로그아웃 버튼은 아이콘 버튼이다. ui.js setBusy() 가 실패 후 되돌릴 때 글자만 넣으므로
     (아이콘 svg 가 사라진다) 비면 아이콘을 다시 그린다. */
  var out = doc.getElementById('btn-logout');
  var outIcon = out ? out.innerHTML : '';
  function keepLogoutIcon() {
    if (out && !out.dataset.busy && !out.querySelector('svg')) { out.innerHTML = outIcon; }
  }

  function start() {
    mountBars();
    paintNav();
    paintAvatar();
    if (global.MutationObserver) {
      if (tabbar) {
        new MutationObserver(function (list) {
          var structural = list.some(function (r) { return r.type === 'childList'; });
          if (structural) { paintNav(); } else { mark(); }
        }).observe(tabbar, { childList: true, subtree: true, attributes: true, attributeFilter: ['aria-selected'] });
      }
      if (who) { new MutationObserver(paintAvatar).observe(who, { childList: true, characterData: true, subtree: true }); }
      if (out) { new MutationObserver(keepLogoutIcon).observe(out, { childList: true, attributes: true, attributeFilter: ['data-busy'] }); }
    }
    doc.addEventListener('rui:change', paintNav);
    /* 새 버전 알림(G18)은 theme-ui.js 의 startUpdates() 가 8개 화면 공통으로 켠다(v1.8.7). 여기서 따로 부르지 않는다. */
  }

  if (doc.readyState === 'loading') { doc.addEventListener('DOMContentLoaded', start); } else { start(); }
})(window);
