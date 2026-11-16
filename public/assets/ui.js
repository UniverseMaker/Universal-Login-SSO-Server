/* ============================================================================
 * ulogin v1.0.0 — DOM 유틸 / 토스트 / 모달 / 탭 / 페이징 / 배지 (ui.js)
 *
 * ★ 이 파일 어디에서도 innerHTML 을 쓰지 않는다.
 *   관리자 콘솔은 공격자가 심은 닉네임을 관리자가 열어보는 XSS 경로다.
 *   모든 텍스트는 textContent 로만 넣는다.
 *
 * 의존성: 없음 (api.js 와 독립적으로 동작. formErrors만 ApiError 형태를 읽는다)
 * ========================================================================== */
(function (global) {
  'use strict';

  var doc = global.document;

  /* ======================================================================
   * 1. 셀렉터 / DOM 생성
   * ==================================================================== */

  /** querySelector 단축. */
  function $(sel, root) { return (root || doc).querySelector(sel); }

  /** querySelectorAll → 실제 배열. */
  function $$(sel, root) {
    return Array.prototype.slice.call((root || doc).querySelectorAll(sel));
  }

  /** 자식 노드를 전부 제거한다. (innerHTML = '' 대신) */
  function clear(node) {
    if (!node) { return node; }
    while (node.firstChild) { node.removeChild(node.firstChild); }
    return node;
  }

  function appendChild(node, child) {
    if (child === null || child === undefined || child === false || child === true) { return; }
    if (Array.isArray(child)) {
      child.forEach(function (c) { appendChild(node, c); });
      return;
    }
    if (child && child.nodeType) {
      node.appendChild(child);
      return;
    }
    node.appendChild(doc.createTextNode(String(child)));
  }

  /**
   * el(tag, attrs, children)
   *
   * attrs 특수 키:
   *   class / className : string 또는 배열
   *   text              : textContent (문자열로 강제)
   *   dataset           : { key: value }
   *   style             : { prop: value } 또는 문자열
   *   on                : { click: fn, ... }  또는 onClick / onclick 형태도 허용
   *   그 외             : setAttribute. boolean true면 빈 속성, false/null이면 생략
   *
   * children: 문자열(→ 텍스트 노드) / Node / 배열 / null
   *
   * 'html', 'innerHTML' 키는 **의도적으로 지원하지 않는다.** 넘기면 예외를 던진다.
   */
  function el(tag, attrs, children) {
    var node = doc.createElement(tag);

    if (attrs && typeof attrs === 'object' && !attrs.nodeType && !Array.isArray(attrs)) {
      Object.keys(attrs).forEach(function (key) {
        var val = attrs[key];
        if (val === null || val === undefined || val === false) { return; }

        if (key === 'html' || key === 'innerHTML' || key === 'outerHTML') {
          throw new Error('ui.el(): HTML 문자열 주입은 금지되어 있습니다. text 또는 children을 쓰세요.');
        }
        if (key === 'class' || key === 'className') {
          node.className = Array.isArray(val) ? val.filter(Boolean).join(' ') : String(val);
          return;
        }
        if (key === 'text') {
          node.textContent = String(val);
          return;
        }
        if (key === 'dataset') {
          Object.keys(val).forEach(function (dk) {
            if (val[dk] === null || val[dk] === undefined) { return; }
            node.dataset[dk] = String(val[dk]);
          });
          return;
        }
        if (key === 'style') {
          if (typeof val === 'string') { node.setAttribute('style', val); }
          else { Object.keys(val).forEach(function (sk) { node.style[sk] = val[sk]; }); }
          return;
        }
        if (key === 'on' && typeof val === 'object') {
          Object.keys(val).forEach(function (evt) {
            if (typeof val[evt] === 'function') { node.addEventListener(evt, val[evt]); }
          });
          return;
        }
        if (typeof val === 'function' && key.slice(0, 2).toLowerCase() === 'on') {
          node.addEventListener(key.slice(2).toLowerCase(), val);
          return;
        }
        if (val === true) { node.setAttribute(key, ''); return; }
        node.setAttribute(key, String(val));
      });
    } else if (attrs !== null && attrs !== undefined) {
      // el('p', '텍스트') 형태 허용
      appendChild(node, attrs);
    }

    if (children !== null && children !== undefined) { appendChild(node, children); }
    return node;
  }

  /** 텍스트 노드 단축. */
  function text(value) { return doc.createTextNode(value === null || value === undefined ? '' : String(value)); }

  /** 여러 노드를 한 번에 붙인다. */
  function mount(parent, children) {
    appendChild(parent, children);
    return parent;
  }

  /* ======================================================================
   * 2. 포맷터
   * ==================================================================== */

  function pad2(n) { return (n < 10 ? '0' : '') + n; }

  /**
   * fmtDate(iso) — ISO8601 UTC 문자열을 사용자 로컬 시각으로 표시.
   * null/undefined/'' 이면 '-' 를 반환한다. 파싱 실패 시 원문을 그대로 돌려준다.
   * @param {string|null} iso
   * @param {boolean} [dateOnly] true면 시:분을 생략
   */
  function fmtDate(iso, dateOnly) {
    if (iso === null || iso === undefined || iso === '') { return '-'; }
    var d = new Date(iso);
    if (isNaN(d.getTime())) { return String(iso); }
    var s = d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
    if (dateOnly) { return s; }
    return s + ' ' + pad2(d.getHours()) + ':' + pad2(d.getMinutes());
  }

  /** 날짜만. fmtDate(iso, true) 와 동일. */
  function fmtDateOnly(iso) { return fmtDate(iso, true); }

  /** 천 단위 구분. 숫자가 아니면 '-'. */
  function fmtNum(n) {
    if (n === null || n === undefined || n === '') { return '-'; }
    var v = typeof n === 'number' ? n : Number(n);
    if (!isFinite(v)) { return '-'; }
    return v.toLocaleString('ko-KR');
  }

  /** 초 → "1시간 2분 3초" 형태. 카운트다운/retry_after 표시용. */
  function fmtDuration(seconds) {
    var s = Math.max(0, Math.floor(Number(seconds) || 0));
    var h = Math.floor(s / 3600);
    var m = Math.floor((s % 3600) / 60);
    var sec = s % 60;
    var parts = [];
    if (h) { parts.push(h + '시간'); }
    if (m) { parts.push(m + '분'); }
    if (sec || !parts.length) { parts.push(sec + '초'); }
    return parts.join(' ');
  }

  /* ======================================================================
   * 3. 배지 — 계약서의 값 집합 그대로
   * ==================================================================== */

  var LABELS = {
    account_level: {
      guest: '게스트',
      basic: '일반',
      verified: '인증',
      vip: 'VIP',
      staff: '스태프'
    },
    role: {
      user: '사용자',
      manager: '매니저',
      admin: '관리자',
      superadmin: '최고관리자'
    },
    billing_tier: {
      free: '무료',
      lite: '라이트',
      pro: '프로',
      enterprise: '엔터프라이즈'
    },
    status: {
      active: '활성',
      pending: '대기',
      suspended: '정지',
      dormant: '휴면',
      withdrawn: '탈퇴'
    }
  };

  /** 배지 앞에 붙는 축 이름. 색맹 사용자도 세 축을 구분할 수 있게 한다. */
  var AXIS_LABELS = {
    account_level: '계정',
    role: '역할',
    billing_tier: '결제',
    status: ''
  };

  /** 값 → 한글 라벨. 모르는 값이면 값 자체를 돌려준다. */
  function labelOf(kind, value) {
    if (value === null || value === undefined || value === '') { return '-'; }
    var map = LABELS[kind];
    if (map && Object.prototype.hasOwnProperty.call(map, value)) { return map[value]; }
    return String(value);
  }

  /**
   * badge(kind, value, opts) → <span class="badge badge-{kind}">
   *
   * @param {'account_level'|'role'|'billing_tier'|'status'} kind
   * @param {string} value 계약서의 값 (예: 'verified', 'admin', 'pro', 'suspended')
   * @param {object} [opts]
   *   @param {string}  opts.note    배지 뒤에 붙일 부가 문구 (예: '만료됨')
   *   @param {boolean} opts.expired true면 만료 스타일 적용 (결제등급 billing_active=false)
   *   @param {boolean} opts.noAxis  true면 축 이름을 생략
   */
  function badge(kind, value, opts) {
    opts = opts || {};
    var classes = ['badge', 'badge-' + kind];
    if (opts.expired) { classes.push('is-expired'); }

    var node = el('span', {
      class: classes,
      dataset: { value: (value === null || value === undefined) ? '' : String(value) }
    });

    var axis = AXIS_LABELS[kind];
    if (axis && !opts.noAxis) {
      node.appendChild(el('span', { class: 'badge-axis', text: axis }));
    }
    node.appendChild(text(labelOf(kind, value)));
    if (opts.note) {
      node.appendChild(el('span', { class: 'badge-note', text: ' ' + opts.note }));
    }
    return node;
  }

  /**
   * userBadges(user) — 계정등급/역할/결제등급 3축을 한 줄로. 하나로 합치지 않는다.
   * billing_active가 false면 결제등급 배지에 '만료됨'을 붙인다.
   */
  function userBadges(user) {
    var row = el('span', { class: 'badge-row' });
    if (!user) { return row; }
    row.appendChild(badge('account_level', user.account_level));
    row.appendChild(badge('role', user.role));
    var expired = (user.billing_tier !== 'free' && user.billing_active === false);
    row.appendChild(badge('billing_tier', user.billing_tier, {
      expired: expired,
      note: expired ? '만료됨' : null
    }));
    /* v1.10.0 — 비상 계정 표지(서버의 is_emergency / account_kind 만 믿는다) */
    if (user.is_emergency === true || user.is_emergency === 1 || user.account_kind === 'emergency') {
      row.appendChild(el('span', { class: 'badge badge-emergency', title: '비상 계정 — 서비스 로그인과 계정 연결 대상이 아닙니다' }, '비상'));
    }
    return row;
  }

  /* ======================================================================
   * 4. 토스트
   * ==================================================================== */

  var TOAST_TYPES = { info: 1, success: 1, warn: 1, danger: 1 };

  function toastHost() {
    var host = $('#ulogin-toast-host');
    if (!host) {
      host = el('div', { id: 'ulogin-toast-host', class: 'toast-host', role: 'status', 'aria-live': 'polite' });
      doc.body.appendChild(host);
    }
    return host;
  }

  /**
   * toast(message, type, duration)
   * @param {string} message
   * @param {'info'|'success'|'warn'|'danger'} [type='info']
   * @param {number} [duration] ms. 기본 info/success 3200, warn/danger 5000
   */
  function toast(message, type, duration) {
    var kind = TOAST_TYPES[type] ? type : 'info';
    var ms = typeof duration === 'number'
      ? duration
      : ((kind === 'warn' || kind === 'danger') ? 5000 : 3200);

    var node = el('div', { class: ['toast', 'toast-' + kind], role: 'alert' }, String(message === null || message === undefined ? '' : message));
    toastHost().appendChild(node);

    var removed = false;
    function remove() {
      if (removed) { return; }
      removed = true;
      node.classList.add('is-leaving');
      global.setTimeout(function () {
        if (node.parentNode) { node.parentNode.removeChild(node); }
      }, 200);
    }
    node.addEventListener('click', remove);
    global.setTimeout(remove, ms);
    return node;
  }

  /* ======================================================================
   * 5. 모달
   * ==================================================================== */

  var FOCUSABLE = 'a[href], button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex="-1"])';

  /**
   * 열려 있는 모달 스택 (leader_decisions D-26 / QA Q-12)
   *
   * 모달마다 document 에 캡처 단계 keydown 을 걸기 때문에, 중첩 모달에서는 두 핸들러가
   * 동시에 호출된다. 스택 최상위 모달만 키 입력에 반응하게 해서
   *   - ESC 한 번에 두 개가 함께 닫히는 문제
   *   - Tab 포커스 트랩이 서로 싸우는 문제
   * 를 같이 막는다. 공개 시그니처(modal/confirmModal/alertModal)는 바뀌지 않는다.
   */
  var _modalStack = [];

  /**
   * modal(options) → Promise
   *
   * @param {object} o
   *   @param {string}   o.title
   *   @param {string|Node|Node[]|function} o.body  함수면 (bodyEl) => void 로 채운다
   *   @param {string}   [o.confirmText='확인']
   *   @param {string}   [o.cancelText='취소']
   *   @param {boolean}  [o.danger=false]      확인 버튼을 위험(빨강) 스타일로
   *   @param {boolean}  [o.hideCancel=false]  알림용 모달
   *   @param {boolean}  [o.large=false]
   *   @param {string}   [o.confirmPhrase]     정확히 이 문구를 입력해야 확인 버튼 활성화
   *   @param {string}   [o.confirmPhraseLabel]
   *   @param {function} [o.onConfirm]  Promise 반환 가능. reject하면 모달을 닫지 않고 오류를 보여준다
   *
   * @returns {Promise<*>} 확인 → onConfirm의 반환값(없으면 true) / 취소·닫기 → false
   */
  function modal(o) {
    o = o || {};
    return new Promise(function (resolve) {
      var prevFocus = doc.activeElement;
      var settled = false;

      var backdrop = el('div', { class: 'modal-backdrop', role: 'presentation' });
      var box = el('div', {
        class: o.large ? 'modal modal-lg' : 'modal',
        role: 'dialog',
        'aria-modal': 'true',
        'aria-label': String(o.title || '알림')
      });

      /* --- head --- */
      var closeBtn = el('button', {
        type: 'button', class: 'modal-close', 'aria-label': '닫기'
      }, '×');
      box.appendChild(el('div', { class: 'modal-head' }, [
        el('h3', { text: String(o.title || '') }),
        closeBtn
      ]));

      /* --- body --- */
      var bodyEl = el('div', { class: 'modal-body' });
      if (typeof o.body === 'function') {
        o.body(bodyEl);
      } else if (o.body !== null && o.body !== undefined) {
        appendChild(bodyEl, o.body);
      }

      var errBox = el('div', { class: 'alert alert-danger', hidden: true }, [
        el('div', { class: 'alert-body' }, '')
      ]);
      bodyEl.appendChild(errBox);

      var phraseInput = null;
      if (o.confirmPhrase) {
        var phraseId = 'ulogin-confirm-phrase-' + Date.now();
        phraseInput = el('input', { type: 'text', id: phraseId, autocomplete: 'off', spellcheck: 'false' });
        var wrap = el('div', { class: 'field modal-confirm-phrase' }, [
          el('label', { for: phraseId }, [
            text(o.confirmPhraseLabel || '계속하려면 아래 문구를 그대로 입력하세요: '),
            el('code', { text: String(o.confirmPhrase) })
          ]),
          phraseInput
        ]);
        bodyEl.appendChild(wrap);
      }
      box.appendChild(bodyEl);

      /* --- foot --- */
      var confirmBtn = el('button', {
        type: 'button',
        class: o.danger ? 'btn btn-danger' : 'btn btn-primary'
      }, String(o.confirmText || '확인'));
      var cancelBtn = el('button', { type: 'button', class: 'btn btn-secondary' },
        String(o.cancelText || '취소'));

      var foot = el('div', { class: 'modal-foot' });
      if (!o.hideCancel) { foot.appendChild(cancelBtn); }
      foot.appendChild(confirmBtn);
      box.appendChild(foot);

      backdrop.appendChild(box);
      doc.body.appendChild(backdrop);

      // 이 모달의 스택 식별자. 최상위일 때만 키 입력을 처리한다 (D-26)
      var stackEntry = { box: box };
      _modalStack.push(stackEntry);

      function isTopModal() {
        return _modalStack.length > 0 && _modalStack[_modalStack.length - 1] === stackEntry;
      }

      /* --- confirmPhrase 게이트 --- */
      function syncPhrase() {
        if (!phraseInput) { return; }
        confirmBtn.disabled = (phraseInput.value !== o.confirmPhrase);
      }
      if (phraseInput) {
        confirmBtn.disabled = true;
        phraseInput.addEventListener('input', syncPhrase);
      }

      function showError(msg) {
        clear(errBox);
        errBox.appendChild(el('div', { class: 'alert-body' }, String(msg)));
        errBox.hidden = false;
      }

      function close(result) {
        if (settled) { return; }
        settled = true;
        doc.removeEventListener('keydown', onKey, true);
        var si = _modalStack.indexOf(stackEntry);
        if (si >= 0) { _modalStack.splice(si, 1); }
        if (backdrop.parentNode) { backdrop.parentNode.removeChild(backdrop); }
        if (prevFocus && prevFocus.focus) { try { prevFocus.focus(); } catch (e) { /* noop */ } }
        resolve(result);
      }

      function onKey(ev) {
        // 중첩 모달: 최상위가 아니면 아무것도 하지 않는다 (ESC 동시 닫힘 / 트랩 충돌 방지)
        if (!isTopModal()) { return; }
        if (ev.key === 'Escape') {
          ev.preventDefault();
          close(false);
          return;
        }
        if (ev.key === 'Tab') {
          var items = $$(FOCUSABLE, box).filter(function (n) { return n.offsetParent !== null; });
          if (!items.length) { return; }
          var first = items[0];
          var last = items[items.length - 1];
          if (ev.shiftKey && doc.activeElement === first) { ev.preventDefault(); last.focus(); }
          else if (!ev.shiftKey && doc.activeElement === last) { ev.preventDefault(); first.focus(); }
        }
      }

      cancelBtn.addEventListener('click', function () { close(false); });
      closeBtn.addEventListener('click', function () { close(false); });
      backdrop.addEventListener('mousedown', function (ev) {
        if (ev.target === backdrop) { close(false); }
      });
      doc.addEventListener('keydown', onKey, true);

      confirmBtn.addEventListener('click', function () {
        if (confirmBtn.disabled) { return; }
        errBox.hidden = true;
        if (typeof o.onConfirm !== 'function') { close(true); return; }

        setBusy(confirmBtn, true);
        Promise.resolve()
          .then(function () { return o.onConfirm(); })
          .then(function (result) {
            setBusy(confirmBtn, false);
            close(result === undefined ? true : result);
          })
          .catch(function (err) {
            setBusy(confirmBtn, false);
            showError((err && err.message) ? err.message : '처리 중 오류가 발생했습니다.');
            syncPhrase();
          });
      });

      /* 최초 포커스 */
      global.setTimeout(function () {
        if (phraseInput) { phraseInput.focus(); }
        else if (!o.hideCancel) { cancelBtn.focus(); }
        else { confirmBtn.focus(); }
      }, 0);
    });
  }

  /** 확인/취소만 필요한 축약형. */
  function confirmModal(title, message, opts) {
    return modal(Object.assign({ title: title, body: message }, opts || {}));
  }

  /** 알림 전용(취소 버튼 없음). */
  function alertModal(title, message, opts) {
    return modal(Object.assign({ title: title, body: message, hideCancel: true, confirmText: '확인' }, opts || {}));
  }

  /* ======================================================================
   * 6. 탭
   * ==================================================================== */

  /**
   * tabs(container, items, onChange, opts)
   *
   * @param {Element} container  탭 버튼이 들어갈 컨테이너 (비워지고 다시 그려진다)
   * @param {Array<{key:string,label:string}>} items
   * @param {function} onChange  (key, panelEl, isFirstLoad) => void
   *                             데이터 로드는 반드시 isFirstLoad일 때만 한다
   * @param {object} [opts]
   *   @param {string}  opts.initial     최초 선택 탭 key
   *   @param {boolean} opts.hash=true   location.hash 와 동기화
   *   @param {function} opts.panelOf    (key) => Element. 기본은 [data-tab-panel="{key}"]
   *
   * @returns {{show:function, current:function, invalidate:function, keys:Array}}
   */
  function tabs(container, items, onChange, opts) {
    opts = opts || {};
    var useHash = opts.hash !== false;
    var loaded = {};            // key → true (최초 진입 여부)
    var current = null;
    var buttons = {};

    function panelOf(key) {
      if (typeof opts.panelOf === 'function') { return opts.panelOf(key); }
      return $('[data-tab-panel="' + key + '"]');
    }

    clear(container);
    container.className = container.className || 'tabs';
    if (container.className.indexOf('tabs') === -1) { container.className += ' tabs'; }
    container.setAttribute('role', 'tablist');

    items.forEach(function (item) {
      var btn = el('button', {
        type: 'button',
        class: 'tab',
        role: 'tab',
        id: 'tab-' + item.key,
        'aria-selected': 'false',
        dataset: { tabKey: item.key }
      }, String(item.label));
      btn.addEventListener('click', function () { show(item.key); });
      buttons[item.key] = btn;
      container.appendChild(btn);

      var panel = panelOf(item.key);
      if (panel) {
        panel.setAttribute('role', 'tabpanel');
        panel.setAttribute('aria-labelledby', 'tab-' + item.key);
        panel.hidden = true;
      }
    });

    function show(key, force) {
      if (!buttons[key]) { return; }
      if (current === key && !force) { return; }
      current = key;

      items.forEach(function (item) {
        var selected = (item.key === key);
        var btn = buttons[item.key];
        btn.setAttribute('aria-selected', selected ? 'true' : 'false');
        var panel = panelOf(item.key);
        if (panel) { panel.hidden = !selected; }
      });

      if (useHash) {
        try { global.history.replaceState(null, '', '#' + key); } catch (e) { /* noop */ }
      }

      var isFirst = !loaded[key];
      loaded[key] = true;
      if (typeof onChange === 'function') { onChange(key, panelOf(key), isFirst); }
    }

    /** 해당 탭을 '미로드' 상태로 되돌린다. 다음 진입 시 isFirstLoad=true 로 다시 호출된다. */
    function invalidate(key) {
      if (key === undefined) { loaded = {}; return; }
      delete loaded[key];
    }

    var initial = opts.initial;
    if (!initial && useHash && global.location.hash) {
      var h = global.location.hash.slice(1);
      if (buttons[h]) { initial = h; }
    }
    if (!initial && items.length) { initial = items[0].key; }
    if (initial) { show(initial); }

    return {
      show: show,
      current: function () { return current; },
      invalidate: invalidate,
      reload: function (key) { invalidate(key || current); show(key || current, true); },
      keys: items.map(function (i) { return i.key; })
    };
  }

  /* ======================================================================
   * 7. 페이지네이션
   * ==================================================================== */

  /**
   * pager(container, meta, onPage)
   * @param {Element} container 비워지고 다시 그려진다
   * @param {{page:number, per_page:number, perPage:number, total:number}} meta
   *        계약서 목록 응답의 { page, per_page, total } 을 그대로 넘겨도 되고
   *        perPage 카멜케이스도 허용한다
   * @param {function} onPage (nextPage:number) => void
   */
  function pager(container, meta, onPage) {
    clear(container);
    if (!meta) { return; }

    var page = Math.max(1, parseInt(meta.page, 10) || 1);
    var perPage = Math.max(1, parseInt(meta.per_page !== undefined ? meta.per_page : meta.perPage, 10) || 20);
    var total = Math.max(0, parseInt(meta.total, 10) || 0);
    var last = Math.max(1, Math.ceil(total / perPage));

    if (container.className.indexOf('pager') === -1) {
      container.className = (container.className ? container.className + ' ' : '') + 'pager';
    }

    if (total === 0) { return; }

    function go(p) {
      if (p < 1 || p > last || p === page) { return; }
      if (typeof onPage === 'function') { onPage(p); }
    }

    function btn(label, targetPage, disabled, isCurrent) {
      var b = el('button', {
        type: 'button',
        disabled: !!disabled || null,
        'aria-current': isCurrent ? 'page' : null,
        'aria-label': isCurrent ? (label + ' 페이지, 현재 페이지') : null
      }, String(label));
      if (!disabled && !isCurrent) {
        b.addEventListener('click', function () { go(targetPage); });
      }
      return b;
    }

    container.appendChild(btn('«', 1, page === 1, false));
    container.appendChild(btn('‹', page - 1, page === 1, false));

    var window_ = 2;
    var from = Math.max(1, page - window_);
    var to = Math.min(last, page + window_);
    if (from > 1) {
      container.appendChild(btn(1, 1, false, page === 1));
      if (from > 2) { container.appendChild(el('span', { class: 'pager-gap' }, '…')); }
    }
    for (var p = from; p <= to; p++) {
      container.appendChild(btn(p, p, false, p === page));
    }
    if (to < last) {
      if (to < last - 1) { container.appendChild(el('span', { class: 'pager-gap' }, '…')); }
      container.appendChild(btn(last, last, false, page === last));
    }

    container.appendChild(btn('›', page + 1, page === last, false));
    container.appendChild(btn('»', last, page === last, false));

    container.appendChild(el('span', { class: 'pager-info' },
      '전체 ' + fmtNum(total) + '건 · ' + page + ' / ' + last + ' 페이지'));
  }

  /* ======================================================================
   * 8. 폼 오류 표시
   * ==================================================================== */

  function fieldWrapOf(input) {
    var n = input;
    while (n && n !== doc.body) {
      if (n.classList && n.classList.contains('field')) { return n; }
      n = n.parentNode;
    }
    return null;
  }

  /** 폼의 모든 오류 표시를 지운다. */
  function clearFormErrors(formEl) {
    if (!formEl) { return; }
    $$('.field.has-error', formEl).forEach(function (f) { f.classList.remove('has-error'); });
    $$('.field-error', formEl).forEach(function (n) { n.textContent = ''; });
    $$('.is-invalid', formEl).forEach(function (n) { n.classList.remove('is-invalid'); });
    var banner = $('.form-alert', formEl);
    if (banner) { banner.hidden = true; clear(banner); }
  }

  /** 폼 상단 배너에 메시지를 띄운다. .form-alert 요소가 없으면 만들어 맨 앞에 넣는다. */
  function formBanner(formEl, message, type) {
    if (!formEl) { return null; }
    var banner = $('.form-alert', formEl);
    if (!banner) {
      banner = el('div', { class: 'alert form-alert', role: 'alert' });
      formEl.insertBefore(banner, formEl.firstChild);
    }
    banner.className = 'alert form-alert alert-' + (type || 'danger');
    banner.setAttribute('role', 'alert');
    clear(banner);
    banner.appendChild(el('div', { class: 'alert-body' }, String(message)));
    banner.hidden = false;
    return banner;
  }

  /**
   * formErrors(formEl, apiError)
   *
   * apiError.field 가 있으면 해당 input 옆에 오류를 붙이고,
   * 없으면 폼 상단 배너로 보여준다. (색만으로 표시하지 않는다 — 텍스트 필수)
   *
   * @param {HTMLFormElement} formEl
   * @param {{code:string,message:string,field:?string}} apiError
   * @returns {boolean} 필드에 붙였으면 true
   */
  function formErrors(formEl, apiError) {
    clearFormErrors(formEl);
    if (!apiError) { return false; }

    var msg = apiError.message ||
      (global.ULOGIN_ERROR_MESSAGES && global.ULOGIN_ERROR_MESSAGES[apiError.code]) ||
      '오류가 발생했습니다.';

    var field = apiError.field;
    if (formEl && field) {
      var input = formEl.querySelector('[name="' + String(field).replace(/"/g, '') + '"]');
      if (input) {
        var wrap = fieldWrapOf(input);
        if (wrap) {
          wrap.classList.add('has-error');
          var errNode = $('.field-error', wrap);
          if (!errNode) {
            errNode = el('span', { class: 'field-error' });
            wrap.appendChild(errNode);
          }
          errNode.textContent = msg;
        } else {
          input.classList.add('is-invalid');
          formBanner(formEl, msg, 'danger');
        }
        input.setAttribute('aria-invalid', 'true');
        try { input.focus(); } catch (e) { /* noop */ }
        return true;
      }
    }

    formBanner(formEl, msg, 'danger');
    return false;
  }

  /* ======================================================================
   * 9. 버튼 busy 상태
   * ==================================================================== */

  /**
   * setBusy(btn, on) — 제출 중 버튼 비활성 + 스피너.
   * 원래 라벨은 dataset에 보관했다가 복원한다.
   */
  function setBusy(btn, on) {
    if (!btn) { return; }
    if (on) {
      if (btn.dataset.busy === '1') { return; }
      btn.dataset.busy = '1';
      btn.dataset.busyLabel = btn.textContent;
      btn.disabled = true;
      btn.setAttribute('aria-busy', 'true');
      btn.classList.add('is-busy');
      clear(btn);
      btn.appendChild(el('span', { class: 'spinner', 'aria-hidden': 'true' }));
      btn.appendChild(text(btn.dataset.busyLabel || ''));
    } else {
      if (btn.dataset.busy !== '1') { return; }
      var label = btn.dataset.busyLabel || '';
      delete btn.dataset.busy;
      delete btn.dataset.busyLabel;
      btn.disabled = false;
      btn.removeAttribute('aria-busy');
      btn.classList.remove('is-busy');
      clear(btn);
      btn.appendChild(text(label));
    }
  }

  /* ======================================================================
   * 10. 안전한 내부 이동
   * ==================================================================== */

  /**
   * guardRedirect(path) — 앱 **내부 경로만** 허용한다.
   * references/redirect-flow.md 의 return_to 규칙과 동일:
   *   'http://' / 'https://' / '//' 로 시작하거나 스킴이 붙어 있으면 버리고 '/' 로.
   *
   * @param {string|null} path
   * @param {string} [fallback='/']
   * @returns {string} 이동해도 안전한 내부 경로
   */
  function guardRedirect(path, fallback) {
    var safe = fallback || '/';
    if (typeof path !== 'string' || !path) { return safe; }

    var p = path.trim();
    if (!p) { return safe; }
    // 제어문자·개행 제거 (헤더/URL 분리 시도 차단)
    p = p.replace(/[\x00-\x1f\x7f]/g, '');
    if (!p) { return safe; }

    // 절대 URL / 프로토콜 상대 URL 차단
    if (p.indexOf('//') === 0 || p.indexOf('\\\\') === 0) { return safe; }
    if (/^[a-zA-Z][a-zA-Z0-9+.-]*:/.test(p)) { return safe; }   // http:, https:, javascript:, data: ...
    // 백슬래시를 슬래시로 해석하는 브라우저 대비
    if (p.indexOf('\\') !== -1) { return safe; }

    // 여기까지 오면 '/path', 'page.html', '?q=1', '#tab' 형태다
    return p;
  }

  /** 안전 검증 후 실제로 이동. */
  function navigate(path, replace) {
    var target = guardRedirect(path);
    if (replace) { global.location.replace(target); }
    else { global.location.assign(target); }
  }

  /* ======================================================================
   * 11. 자잘한 렌더 헬퍼 (전부 textContent 기반)
   * ==================================================================== */

  /** 로딩 표시 노드. */
  function loadingNode(message) {
    return el('div', { class: 'loading' }, [
      el('span', { class: 'spinner', 'aria-hidden': 'true' }),
      text(message || '불러오는 중…')
    ]);
  }

  /** 빈 상태 노드. */
  function emptyNode(title, desc, actionNode) {
    var box = el('div', { class: 'empty' }, [
      el('div', { class: 'empty-title', text: String(title || '표시할 내용이 없습니다.') })
    ]);
    if (desc) { box.appendChild(el('div', { class: 'empty-desc', text: String(desc) })); }
    if (actionNode) { box.appendChild(actionNode); }
    return box;
  }

  /** 알림 배너 노드. type: info|success|warn|danger */
  function alertNode(message, type, title) {
    var body = el('div', { class: 'alert-body' });
    if (title) { body.appendChild(el('strong', { class: 'alert-title', text: String(title) })); }
    body.appendChild(text(String(message === null || message === undefined ? '' : message)));
    return el('div', { class: ['alert', 'alert-' + (type || 'info')], role: 'alert' }, body);
  }

  /** 통계 타일 노드. */
  function statNode(label, value, sub, accent) {
    var box = el('div', { class: accent ? 'stat accent-' + accent : 'stat' }, [
      el('span', { class: 'stat-label', text: String(label) }),
      el('span', { class: 'stat-value', text: String(value) })
    ]);
    if (sub) { box.appendChild(el('span', { class: 'stat-sub', text: String(sub) })); }
    return box;
  }

  /**
   * 지표 줄(v1.9.3). 섹션 하나 = 패널 하나, 칸은 세로 구분선으로만 나눈다.
   * cells: [{label, value, tip?, bad?}]  — bad 는 「1 이상이면 빨강」 이 필요한 칸에만 true.
   * 0 은 흐린 회색, 0 이 아닌 값만 진하게. tip 은 라벨 옆 ⓘ 의 title 툴팁(키보드 포커스 가능).
   */
  function statStrip(title, hint, cells) {
    var head = el('div', { class: 'ss-head' }, [
      el('h3', { class: 'ss-title', text: String(title) }),
      hint ? el('span', { class: 'ss-hint', text: String(hint) }) : null
    ]);
    var row = el('div', { class: 'ss-row' });
    (cells || []).forEach(function (c) {
      var n = Number(String(c.value).replace(/,/g, ''));
      var zero = (n === 0);
      var cls = 'ss-cell' + (zero ? ' is-zero' : '') + (c.bad && !zero && n > 0 ? ' is-bad' : '');
      var lab = el('span', { class: 'ss-label' }, text(String(c.label)));
      if (c.tip) {
        lab.appendChild(el('span', {
          class: 'ss-i', tabindex: '0', role: 'img', title: String(c.tip),
          'aria-label': String(c.label) + ' 설명: ' + String(c.tip), text: 'ⓘ'
        }));
      }
      row.appendChild(el('div', { class: cls }, [
        lab, el('span', { class: 'ss-value', text: String(c.value) })
      ]));
    });
    return el('section', { class: 'ss-panel' }, [head, el('div', { class: 'ss-clip' }, row)]);
  }

  /** 표 래퍼 + <table class="table"> 생성. thead는 헤더 문자열 배열로 만든다. */
  function tableNode(headers) {
    var table = el('table', { class: 'table' });
    var thead = el('thead');
    var tr = el('tr');
    (headers || []).forEach(function (h) {
      if (h && typeof h === 'object') {
        tr.appendChild(el('th', { scope: 'col', class: h.className || null, text: String(h.label) }));
      } else {
        tr.appendChild(el('th', { scope: 'col', text: String(h) }));
      }
    });
    thead.appendChild(tr);
    table.appendChild(thead);
    var tbody = el('tbody');
    table.appendChild(tbody);
    var wrap = el('div', { class: 'table-wrap' }, table);
    return { wrap: wrap, table: table, tbody: tbody };
  }

  /* ======================================================================
   * 12. 전역 노출
   * ==================================================================== */
  var UI = {
    $: $, $$: $$, el: el, text: text, clear: clear, mount: mount,
    fmtDate: fmtDate, fmtDateOnly: fmtDateOnly, fmtNum: fmtNum, fmtDuration: fmtDuration,
    badge: badge, userBadges: userBadges, labelOf: labelOf, LABELS: LABELS,
    toast: toast, modal: modal, confirmModal: confirmModal, alertModal: alertModal,
    tabs: tabs, pager: pager,
    formErrors: formErrors, clearFormErrors: clearFormErrors, formBanner: formBanner,
    setBusy: setBusy,
    guardRedirect: guardRedirect, navigate: navigate,
    loadingNode: loadingNode, emptyNode: emptyNode, alertNode: alertNode,
    statNode: statNode, statStrip: statStrip, tableNode: tableNode
  };

  global.UI = UI;
  Object.keys(UI).forEach(function (k) {
    if (k === 'LABELS') { global.ULOGIN_LABELS = UI[k]; return; }
    global[k] = UI[k];
  });

})(window);
