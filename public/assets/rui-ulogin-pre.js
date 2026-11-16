/* ============================================================================
 * ulogin v1.8.3 — rui-theme.js 앞에 부르는 보정 (rui-ulogin-pre.js)
 *
 * 공용 파일(rui-theme.js · rui.css · rui-tokens.css)은 research-ui G14 원본을
 * **바이트 그대로** 복사했다(D-327: 공용 파일은 고치지 않는다, 보정은 별도 파일로).
 * v1.7.0~v1.8.2 사본은 원본을 두 군데 고쳐 썼는데, 이번 판부터 그 둘을 원본 밖으로 뺐다.
 *   [1] 화면 설정 패널의 인라인 style= → `theme-ui.js` 의 picker 보정(같은 판)
 *   [2] `?rui=` 인계 때 쿼리 전체를 지우는 동작 → **이 파일**
 *
 * ─ [2] 무엇을 막나 ──────────────────────────────────────────────────────
 *   원본 rui-theme.js 끝부분은 주소에 `?rui=<값>` 이 있으면 화면 설정을 이어받은 뒤
 *     history.replaceState(null, '', location.pathname + location.hash)
 *   로 **쿼리 전체를** 지운다. ulogin 화면은 쿼리로 동작한다 —
 *   `login.html?service_id&redirect_uri&state&code_challenge` · `reset.html?token=` ·
 *   `verify.html?token=`. 원본대로면 `?rui=` 가 섞여 들어온 순간 SSO 파라미터와
 *   일회용 토큰이 통째로 사라진다.
 *
 * ─ 어떻게 ────────────────────────────────────────────────────────────────
 *   rui-theme.js 보다 **먼저**(head, 동기) 불러야 한다. 원본과 같은 정규식으로
 *   `rui=` 를 찾았을 때만 `history.replaceState` 를 **한 번만** 감싼다.
 *   감싼 함수는 원본이 부르는 바로 그 호출(주소 = pathname + hash)일 때만
 *   `rui` 키 하나를 뺀 나머지 쿼리를 되살린 주소로 바꿔 넘기고, 곧바로 원래 함수로 되돌린다.
 *   다른 모양의 호출이 먼저 오면 손대지 않고 넘긴다.
 *   **예외가 나면 주소를 아예 건드리지 않는다**(QA Q17-4 — 고친 결함이 예외 경로에
 *   남지 않게). rui-theme.js 가 끝나면(이 스크립트 다음 태스크) 무조건 원래 함수로 돌린다.
 *
 * 의존성 없음. 인라인 스타일·네트워크 없음. CSP(script-src 'self') 안에서 동작한다.
 * ========================================================================== */
(function (global) {
  'use strict';
  var loc = global.location, hist = global.history;
  if (!loc || !hist || typeof hist.replaceState !== 'function') { return; }
  /* 원본 rui-theme.js 와 같은 식. 이 식이 맞지 않으면 원본도 주소를 건드리지 않는다. */
  if (!/[?&]rui=([A-Za-z0-9_-]{1,200})/.test(loc.search)) { return; }

  var orig = hist.replaceState;
  var search = loc.search;
  var done = false;

  function restore() {
    if (done) { return; }
    done = true;
    try { hist.replaceState = orig; } catch (e) { /* noop */ }
  }

  hist.replaceState = function (state, title, url) {
    var target = loc.pathname + loc.hash;
    /* L-1: rui-theme.js 가 실행 중일 때의 호출만 보정한다. rui-theme.js 를 못 읽으면(404·차단)
       화면 스크립트의 토큰 지우기 호출이 여기 걸려 토큰을 되살리므로 그대로 통과시킨다. */
    var cs = global.document && global.document.currentScript;
    if (done || !cs || !/\/rui-theme\.js(\?|$)/.test(cs.src || '') || String(url) !== target) {
      return orig.apply(hist, arguments);
    }
    restore();
    var keep;
    try {
      keep = search.replace(/^\?/, '').split('&').filter(function (kv) {
        if (!kv) { return false; }
        var k = kv.split('=')[0];
        try { k = decodeURIComponent(k); } catch (e) { /* 원문 그대로 비교 */ }
        return k !== 'rui';
      }).join('&');
    } catch (e) {
      return undefined;   // 주소를 건드리지 않는다
    }
    return orig.call(hist, state, title, loc.pathname + (keep ? '?' + keep : '') + loc.hash);
  };

  /* 안전망 — 감싼 채로 남지 않게. setTimeout(0) 은 쓰지 않는다: 파서가 rui-theme.js 를
     내려받는 동안 이벤트 루프가 돌아 **원본이 부르기 전에** 되돌려 버릴 수 있다.
     DOMContentLoaded 는 head 의 동기 스크립트가 전부 끝난 뒤에만 난다. 원본의 호출은
     조건이 맞으면 무조건 같은 스크립트 안에서 나므로, 화면 스크립트의 replaceState 보다
     항상 먼저 온다(그래서 화면이 일부러 쿼리를 지우는 호출은 이 보정에 걸리지 않는다). */
  if (global.document && global.document.addEventListener) {
    global.document.addEventListener('DOMContentLoaded', restore);
  }
})(window);
