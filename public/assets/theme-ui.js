/* ============================================================================
 * ulogin v1.8.3 — 화면 설정 진입점 (theme-ui.js) · v1.8.6 G16 로그인 틀 도구 줄
 *
 * v1.8.3 에서 공용 테마를 research-ui **G14** 로 바꿨다(D-327). 공용 파일 셋
 * (rui-theme.js · rui.css · rui-tokens.css)은 원본을 **바이트 그대로** 복사했고,
 * v1.7.0~v1.8.2 사본에 직접 넣었던 보정 둘은 원본 밖으로 옮겼다.
 *   [1] 화면 설정 패널의 인라인 style=   → 이 파일의 `pickerInto()`
 *   [2] `?rui=` 인계가 쿼리 전체를 지움   → `rui-ulogin-pre.js`
 *
 * 하는 일
 *   1) 공개 화면의 `<header class="site-header">`(index) 에 「밝기」 아이콘과 「화면 설정」 버튼을 꽂는다.
 *      v1.8.6: G16 로그인 틀 화면(login·register·forgot·reset·verify·mypage)은 머리줄이 없다 —
 *      RUI.login 의 오른쪽 위 도구 줄에 「화면 설정」만 더한다(밝기 버튼은 부품 것, mountLoginTools).
 *      화면 설정은 `RUI.picker()` 를 담은 대화상자다(공통 테마 1·2 · 밝기 · 색상 12종).
 *      메뉴 형태는 뺀다 — 이 화면들에는 메뉴가 없다(layout:false).
 *      관리자 콘솔(admin.html)은 `admin-shell.js` 가 같은 `pickerInto()` 로 메뉴 형태까지 연다.
 *   2) `<html data-ulogin-sysnav>` 화면(mypage)에만 시스템 바로 가기 버튼(`RUI.sysButton()`).
 *   3) `[data-ul-ver]` 에 버전 뱃지 — 이 스크립트 주소의 `?v=` 에서 읽는다
 *      (GUIDELINE 4장: 손으로 고치지 않는다. HTML 에 버전을 박으면 bump 도구가 실패한다, D-99).
 *   4) `[data-ul-systems]` 에 「범용 로그인으로 들어가는 시스템」 목록 — `RUI.SYSTEMS` 에서
 *      `sso` 가 있는 것만. **링크가 아니다**: 로그인 도중 다른 시스템으로 나가는 길을
 *      띄우지 않는다(v1.8.1 판정 — SSO 복귀 도중일 수 있다).
 *   5) 별칭 주소의 상위 링크(`data-ulogin-up`) 보정 (v1.8.1 / D-313).
 *
 * ─ [1] picker 보정 ─────────────────────────────────────────────────────
 *   원본 `RUI.picker()` 는 `el.innerHTML = '…<i style="background:#색">…'` 로 그린다
 *   (팔레트 견본 12개 + 위쪽 메뉴 하위 제목 2곳). `public/.htaccess` 의 CSP 가
 *   `style-src 'self'` 라 **인라인 style= 속성이 막히고 콘솔에 위반이 찍힌다.**
 *   CSP 를 넓히지 않는다. 대신 그 요소 **하나에만** `innerHTML` 설정자를 덮어
 *   `style="…"` 를 `data-ul-style="…"` 로 바꿔 넣은 뒤 CSSOM(`el.style.cssText`)으로
 *   옮긴다. CSSOM 대입은 CSP 대상이 아니다. 원본은 다시 그릴 때마다(클릭마다)
 *   innerHTML 을 새로 쓰므로 설정자에서 처리해야 매번 맞는다.
 *   바꾸는 값은 RUI 가 자기 상수(RUI.PALETTES)로 만든 문자열뿐이다 — 사용자 입력이 아니다.
 *
 * 의존성 없음. Vanilla JS. 인라인 style 속성·<style> 요소를 만들지 않는다.
 * ========================================================================== */
(function (global) {
  'use strict';

  var doc = global.document;

  /* rui-theme.js 가 막혔거나 못 들어온 경우 — 아무것도 하지 않는다.
     그 상태에서도 화면은 style.css 의 폴백 값으로 읽힌다(var(--rui-*, 기본값)). */
  if (!global.RUI || typeof global.RUI.picker !== 'function') { return; }
  var RUI = global.RUI;

  var BTN_ID = 'rui-prefs-btn';
  var MODE_ID = 'rui-mode-btn';
  var SYS_ID = 'rui-sys-btn';
  var backdrop = null;
  var lastFocus = null;

  /* 원본에 없는 아이콘 둘(밝기). RUI.ICONS 와 같은 24px 선 아이콘 형식이다. */
  var ICON_PATH = {
    sun: 'M12 16a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4',
    moon: 'M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z',
    x: 'M6 6l12 12M18 6L6 18'
  };
  function iconSvg(name) {
    if (ICON_PATH[name]) {
      return '<svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="' + ICON_PATH[name] + '"/></svg>';
    }
    return RUI.icon(name);
  }

  /* ─ [1] picker 보정 ──────────────────────────────────────────────── */
  var nativeHTML = (function () {
    try { return Object.getOwnPropertyDescriptor(global.Element.prototype, 'innerHTML'); }
    catch (e) { return null; }
  })();

  function moveInlineStyles(root) {
    var list = root.querySelectorAll('[data-ul-style]');
    for (var i = 0; i < list.length; i++) {
      try { list[i].style.cssText = list[i].getAttribute('data-ul-style') || ''; } catch (e) { /* noop */ }
      list[i].removeAttribute('data-ul-style');
    }
  }

  function guardInnerHTML(el) {
    if (!nativeHTML || !nativeHTML.set || el.__ulStyleGuard) { return; }
    try {
      Object.defineProperty(el, 'innerHTML', {
        configurable: true,
        get: function () { return nativeHTML.get.call(this); },
        set: function (v) {
          var s = String(v).replace(/\sstyle="([^"<>]*)"/g, ' data-ul-style="$1"');
          nativeHTML.set.call(this, s);
          moveInlineStyles(this);
        }
      });
      el.__ulStyleGuard = true;
    } catch (e) { /* 덮지 못하면 원본대로 그린다(견본 색만 빠진다) */ }
  }

  /** RUI.picker 를 CSP 안전하게 부른다. 관리자 콘솔(admin-shell.js)도 이것을 쓴다. */
  function pickerInto(el, opts) {
    guardInnerHTML(el);
    RUI.picker(el, opts || {});
  }

  /* ─ 버전 뱃지 ────────────────────────────────────────────────────── */
  function assetVersion() {
    var s = doc.querySelector('script[src*="theme-ui.js"]');
    var m = s && /[?&]v=([A-Za-z0-9._-]+)/.exec(s.getAttribute('src') || '');
    return m ? m[1] : '';
  }
  function fillVersion() {
    var v = assetVersion();
    var list = doc.querySelectorAll('[data-ul-ver]');
    for (var i = 0; i < list.length; i++) {
      if (!v) { list[i].hidden = true; continue; }
      list[i].textContent = 'v' + v;
      list[i].hidden = false;
    }
  }

  /* ─ 범용 로그인으로 들어가는 시스템 (소개 면) ─────────────────────── */
  function fillSystems() {
    var hosts = doc.querySelectorAll('[data-ul-systems]');
    if (!hosts.length || !Array.isArray(RUI.SYSTEMS)) { return; }
    var list = RUI.SYSTEMS.filter(function (s) { return s && s.sso && s.id !== 'ulogin'; });
    for (var h = 0; h < hosts.length; h++) {
      var host = hosts[h];
      while (host.firstChild) { host.removeChild(host.firstChild); }
      var ul = doc.createElement('ul');
      ul.className = 'ul-sys-list';
      list.forEach(function (s) {
        var li = doc.createElement('li');
        li.className = 'ul-sys-item';
        var ic = doc.createElement('span');
        ic.className = 'ul-sys-ic';
        ic.setAttribute('aria-hidden', 'true');
        ic.innerHTML = RUI.icon(s.icon);          // RUI 상수 아이콘. style 속성 없음
        var tx = doc.createElement('span');
        tx.className = 'ul-sys-tx';
        var b = doc.createElement('b');
        b.textContent = String(s.name || '');
        var sm = doc.createElement('small');
        sm.textContent = String(s.desc || '');
        tx.appendChild(b);
        tx.appendChild(sm);
        li.appendChild(ic);
        li.appendChild(tx);
        ul.appendChild(li);
      });
      host.appendChild(ul);
      var cnt = host.parentNode && host.parentNode.querySelector('[data-ul-systems-count]');
      if (cnt) { cnt.textContent = list.length + '곳'; }
    }
  }

  /* ─ 화면 설정 대화상자 (공개 화면) ───────────────────────────────── */
  function closePanel() {
    if (!backdrop) { return; }
    backdrop.remove();
    backdrop = null;
    doc.removeEventListener('keydown', onKeydown, true);
    if (lastFocus && typeof lastFocus.focus === 'function') {
      try { lastFocus.focus(); } catch (e) { /* noop */ }
    }
    lastFocus = null;
  }

  function onKeydown(ev) {
    if (ev.key === 'Escape' || ev.key === 'Esc') {
      ev.stopPropagation();
      closePanel();
    }
  }

  function openPanel() {
    if (backdrop) { return; }
    lastFocus = doc.activeElement;

    backdrop = doc.createElement('div');
    backdrop.className = 'modal-backdrop';

    var box = doc.createElement('div');
    box.className = 'modal ul-pick-modal';
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-label', '화면 설정');

    var head = doc.createElement('div');
    head.className = 'modal-head';
    var h = doc.createElement('h3');
    h.textContent = '화면 설정';
    var x = doc.createElement('button');
    x.type = 'button';
    x.className = 'modal-close';
    x.setAttribute('aria-label', '닫기');
    x.textContent = '×';
    x.addEventListener('click', closePanel);
    head.appendChild(h);
    head.appendChild(x);

    var body = doc.createElement('div');
    body.className = 'modal-body';
    var note = doc.createElement('p');
    note.className = 'text-xs text-muted ul-pick-note';
    note.textContent = '이 기기에만 저장됩니다.';
    var pick = doc.createElement('div');
    body.appendChild(note);
    body.appendChild(pick);

    var foot = doc.createElement('div');
    foot.className = 'modal-foot';
    var done = doc.createElement('button');
    done.type = 'button';
    done.className = 'btn btn-secondary';
    done.textContent = '닫기';
    done.addEventListener('click', closePanel);
    foot.appendChild(done);

    box.appendChild(head);
    box.appendChild(body);
    box.appendChild(foot);
    backdrop.appendChild(box);

    /* 바깥을 누르면 닫는다. 안쪽 클릭은 picker 가 받아야 하므로 대상 검사를 한다. */
    backdrop.addEventListener('click', function (ev) {
      if (ev.target === backdrop) { closePanel(); }
    });

    doc.body.appendChild(backdrop);

    /* 메뉴 형태(왼쪽/위쪽 메뉴)는 이 화면들에 해당 사항이 없다 — 사이드 메뉴가 없다.
       남는 것: 공통 테마 1·2 / 밝기(시스템·밝게·어둡게) / 색상 12종. */
    pickerInto(pick, { layout: false });

    doc.addEventListener('keydown', onKeydown, true);
    try { x.focus(); } catch (e) { /* noop */ }
  }

  /* ─ 밝기 한 번에 바꾸기 ──────────────────────────────────────────── */
  function paintModeButton(btn) {
    var dark = RUI.mode(RUI.get()) === 'dark';
    btn.innerHTML = iconSvg(dark ? 'sun' : 'moon');
    var t = dark ? '밝게 보기' : '어둡게 보기';
    btn.setAttribute('aria-label', t);
    btn.title = t;
  }
  function makeModeButton(id) {
    var btn = doc.createElement('button');
    btn.type = 'button';
    btn.className = 'icon-btn ul-mode-btn';
    if (id) { btn.id = id; }
    btn.addEventListener('click', function () {
      RUI.set({ mode: RUI.mode(RUI.get()) === 'dark' ? 'light' : 'dark' });
    });
    paintModeButton(btn);
    doc.addEventListener('rui:change', function () { paintModeButton(btn); });
    return btn;
  }

  /* ─ 시스템 바로 가기 (research-ui G9 · GUIDELINE 10-1장) ────────────────
   * 붙이는 화면은 `<html data-ulogin-sysnav>` 가 정한다 — 로그인한 사람이 머무는 화면만
   * (mypage · admin). 로그인 전 화면에는 띄우지 않는다(인증 흐름에서 사람을 끌어낸다).
   * admin 은 admin-shell.js 가 위쪽 띠·왼쪽 메뉴 아래에 따로 붙인다. */
  function mountSysButton(nav) {
    if (doc.getElementById(SYS_ID)) { return; }
    if (!doc.documentElement.hasAttribute('data-ulogin-sysnav')) { return; }
    if (typeof RUI.sysButton !== 'function' || typeof RUI.sysInit !== 'function') { return; }
    var holder = doc.createElement('span');
    holder.innerHTML = RUI.sysButton();        // style 속성 없는 RUI 상수 마크업
    var sysBtn = holder.firstChild;
    if (!sysBtn) { return; }
    sysBtn.id = SYS_ID;
    nav.appendChild(sysBtn);
    /* status/url 은 주지 않는다 — ulogin 은 다른 시스템의 상태를 모른다(GUIDELINE 7장). */
    RUI.sysInit({ self: 'ulogin' });
  }

  /* ─ G16 공통 로그인 틀의 오른쪽 위 도구 줄 (v1.8.6) ─────────────────────
   * RUI.login 이 밝기 버튼([data-rui-login-mode])을 이미 그린다 — 밝기 버튼을 하나 더 만들지 않는다.
   * 그 앞에 「화면 설정」(톱니 아이콘), mypage 면 뒤에 시스템 바로 가기를 붙인다.
   * 화면 설정에서 밝기를 바꿨을 때 부품의 밝기 아이콘을 맞추는 것은 G17 부품이 직접 한다(rui:change). */
  function mountLoginTools(tools) {
    if (doc.getElementById(BTN_ID)) { return; }
    var mb = tools.querySelector('[data-rui-login-mode]');
    var btn = doc.createElement('button');
    btn.type = 'button';
    btn.id = BTN_ID;
    btn.className = 'icon-btn';
    btn.setAttribute('aria-haspopup', 'dialog');
    btn.setAttribute('aria-label', '화면 설정');
    btn.title = '화면 설정';
    btn.innerHTML = RUI.icon('gear');          // RUI 상수 아이콘. style 속성 없음
    btn.addEventListener('click', openPanel);
    tools.insertBefore(btn, mb || null);
    if (mb) { mb.id = MODE_ID; }
    mountSysButton(tools);
  }

  function mountButton() {
    if (doc.querySelector('[data-ul-shell]')) { return; }   // 관리자 콘솔은 admin-shell.js
    if (doc.getElementById(BTN_ID)) { return; }
    var tools = doc.querySelector('.rui-login .rui-login-tools');
    if (tools) { mountLoginTools(tools); return; }
    var header = doc.querySelector('.site-header');
    if (!header) { return; }

    var nav = header.querySelector('nav');
    if (!nav) {
      nav = doc.createElement('nav');
      header.appendChild(nav);
    }

    var btn = doc.createElement('button');
    btn.type = 'button';
    btn.id = BTN_ID;
    btn.className = 'btn btn-ghost btn-sm';
    btn.textContent = '화면 설정';
    btn.setAttribute('aria-haspopup', 'dialog');
    btn.title = '공통 테마 · 밝기 · 색상을 고릅니다';
    btn.addEventListener('click', openPanel);

    nav.insertBefore(btn, nav.firstChild);
    nav.insertBefore(makeModeButton(MODE_ID), btn);

    mountSysButton(nav);
  }

  /* ─ 별칭 주소의 상위 링크 (v1.8.1 / D-313) ─────────────────────────────
   * `index.html` 의 연동 예제 링크는 `../examples/…` 다. `/public` 없는 별칭 주소에서는
   * 설치 루트의 부모로 풀린다. `data-ulogin-up` 링크만 별칭 주소일 때 앞의 `../` 를 뗀다.
   * 판정식은 `api.js` 의 ULOGIN_API_BASE 와 같다 — 함께 고쳐라. */
  function inPublicDir() {
    var p = (global.location && global.location.pathname) || '';
    return /\/public\/[^\/]*$/.test(p);
  }

  function fixUpLinks() {
    if (inPublicDir()) { return; }
    var links = doc.querySelectorAll('a[data-ulogin-up]');
    for (var i = 0; i < links.length; i++) {
      var h = links[i].getAttribute('href') || '';
      if (h.indexOf('../') === 0) { links[i].setAttribute('href', h.slice(3)); }
    }
  }

  /* ─ 공용 새 버전 알림 (G18, v1.8.7) ───────────────────────────────
     8개 화면이 모두 이 파일을 부르므로(로그인·가입·찾기·재설정·인증·마이페이지·관리자·SSO 거절 포함)
     여기서 한 번만 켠다. 값은 이 스크립트 주소의 ?v= — bump_asset_version.php 가 version.txt·version.json 과
     함께 바꾸는 같은 출처다. 버전 주소는 기본값 `/version.json`. 자동 새로고침은 없다(공용 부품이 안 한다). */
  function startUpdates() {
    var v = assetVersion();
    if (v && typeof RUI.updates === 'function') { RUI.updates({ version: v }); }
  }

  function onReady() {
    fixUpLinks();
    startUpdates();
    fillVersion();
    fillSystems();
    mountButton();
  }

  /* admin-shell.js 가 쓰는 공용 부품. 화면 코드가 부를 일은 없다. */
  global.ULOGIN_THEME_UI = {
    open: openPanel,
    close: closePanel,
    pickerInto: pickerInto,
    makeModeButton: makeModeButton,
    iconSvg: iconSvg,
    version: assetVersion
  };

  if (doc.readyState === 'loading') {
    doc.addEventListener('DOMContentLoaded', onReady);
  } else {
    onReady();
  }

})(window);
