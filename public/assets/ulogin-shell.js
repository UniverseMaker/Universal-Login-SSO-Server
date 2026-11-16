/* ============================================================================
 * ulogin v1.8.6 — G16 공통 로그인 틀 (ulogin-shell.js)
 *
 * research-ui G16(GUIDELINE 4-2장): 모든 시스템의 로그인 화면은 `RUI.login(요소, 옵션)` 하나로 그린다.
 * 범용 로그인 자신도 포함한다. 자체 로그인 화면(비밀번호 칸이 있는데 `.rui-login` 이 아님)은
 * `RUI.audit()` 위반이다. 그래서 비밀번호 칸이 있는 공개 화면 전부(login·register·reset·mypage)와
 * 같은 묶음의 forgot·verify 가 이 틀을 쓴다.
 *
 * 하는 일
 *   1) `base(extra)` — 화면마다 같은 RUI.login 옵션(self·version·tagline·desc·systems·idLabel·idAuto).
 *      login.html 은 이 값으로 `RUI.login()` 을 직접 부르고 onSubmit 만 넣는다.
 *   2) `#ul-pane` 이 있는 화면(register·forgot·reset·verify·mypage)은 불러오는 즉시 틀을 세운다:
 *      RUI.login 이 그린 왼쪽 시스템 면·오른쪽 위 밝기 버튼은 그대로 두고, 로그인 폼 자리에
 *      그 화면의 `#ul-pane`(기존 카드들 — 노드를 **옮긴다**, 다시 만들지 않는다)을 넣는다.
 *      폼의 도움말 줄(`.rui-login-help`)은 pane 에 자기 것이 없으면 테마 기본 문구를 그대로 옮긴다.
 *   3) `[data-ul-tools]` 의 자식(옛 머리줄의 「로그인」 링크 등)을 오른쪽 위 도구 줄로 옮긴다.
 *
 * 노드를 옮기므로 화면 스크립트가 잡은 id·이벤트는 그대로다. 이 파일은 화면 스크립트(api.js 앞)보다
 * **먼저** 불러야 한다 — 포커스를 잡은 뒤에 옮기면 포커스가 풀린다.
 * rui-theme.js 를 못 읽으면(404·차단) 아무것도 하지 않는다. pane 은 원래 자리에 그대로 보인다.
 *
 * 의존성 없음. 인라인 style·<style> 을 만들지 않는다(CSP style-src 'self').
 * ========================================================================== */
(function (global) {
  'use strict';

  var doc = global.document;
  var RUI = global.RUI;

  /* 왼쪽 시스템 면의 소개 — v1.8.3 소개 면 문구 그대로(윤문은 다음 단계). */
  var TAGLINE = '하나의 계정으로 모든 시스템을 연결';
  var DESC = '';

  /** 버전 뱃지 — 이 스크립트 주소의 ?v= 에서 읽는다(GUIDELINE 4장: 손으로 고치지 않는다). */
  function version() {
    var s = doc.querySelector('script[src*="ulogin-shell.js"]');
    var m = s && /[?&]v=([A-Za-z0-9._-]+)/.exec(s.getAttribute('src') || '');
    return m ? 'v' + m[1] : '';
  }

  function base(extra) {
    var o = {
      self: 'ulogin',
      version: version(),
      tagline: TAGLINE,
      systems: true,
      idLabel: '아이디 또는 이메일',
      idAuto: 'username'
    };
    extra = extra || {};
    for (var k in extra) { if (Object.prototype.hasOwnProperty.call(extra, k)) { o[k] = extra[k]; } }
    return o;
  }

  /** `[data-ul-tools]` 의 자식을 오른쪽 위 도구 줄 맨 앞으로 옮긴다. */
  function moveTools(root) {
    var tools = root.querySelector('.rui-login-tools');
    var src = doc.querySelector('[data-ul-tools]');
    if (!tools || !src) { return; }
    var first = tools.firstChild;
    while (src.firstChild) { tools.insertBefore(src.firstChild, first); }
    src.parentNode.removeChild(src);
  }

  /**
   * 로그인 폼 자리에 그 화면의 #ul-pane 을 넣는다.
   * @returns {{root:Element, main:Element}|null}
   */
  function mountPage() {
    var host = doc.getElementById('ul-shell');
    var pane = doc.getElementById('ul-pane');
    if (!host || !pane || !RUI || typeof RUI.login !== 'function') { return null; }

    RUI.login(host, base());
    var main = host.querySelector('.rui-login-main');
    var form = main && main.querySelector('.rui-login-form');
    if (!main || !form) { return null; }

    /* 도움말 줄 — pane 이 자기 것을 두지 않았으면 테마 기본 문구를 옮긴다. */
    var help = form.querySelector('.rui-login-help');
    if (help && !pane.querySelector('.rui-login-help')) { pane.appendChild(help); }
    form.parentNode.removeChild(form);
    main.appendChild(pane);
    if (pane.hasAttribute('data-ul-wide')) { host.classList.add('ul-wide'); }
    moveTools(host);
    return { root: host, main: main };
  }

  global.ULOGIN_SHELL = {
    TAGLINE: TAGLINE,
    DESC: DESC,
    version: version,
    base: base,
    moveTools: moveTools,
    mountPage: mountPage
  };

  if (doc.getElementById('ul-pane')) { mountPage(); }

})(window);
