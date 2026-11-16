/* ============================================================================
 * ulogin v1.8.4 — 관리자 콘솔 「계정 연결」 탭 (admin-links.js)
 *
 * 각 시스템이 보내온 로컬 계정(외부 계정)을 통합로그인 계정에 잇는다.
 *   설계  : _workspace/92_architect_v182_account_link_spec.md F·G절
 *   키    : _workspace/101_backend_v184_report.md §1 (실측 응답 키 — 이 파일은 그 이름만 읽는다)
 *   판정  : D-324(읽기 admin · 쓰기 superadmin) · D-328(시스템 관리자 계정 → rank<20 경고) · D-334
 *
 * 호출 (전부 api.js 의 apiGet / apiPost — fetch 직접 호출 없음)
 *   GET  /api/admin/account_links?view=systems|accounts|person|groups|job|jobs      (admin)
 *   POST /api/admin/account_link_ops  action=sync_request                          (admin)
 *   POST /api/admin/account_link_ops  action=plan|commit|cancel|setup_link|import  (superadmin)
 *   GET  /api/admin/users?q=   — 사람 묶음의 통합로그인 계정 찾기(기존 회원 목록 API)
 *
 * ▣ 외부 계정 필드는 신뢰할 수 없는 입력이다
 *   username · name · email · extra 는 **다른 시스템이 보낸 문자열**이다. 관리자가 이 화면을
 *   여는 순간이 XSS 경로가 된다. 그래서 이 파일은 innerHTML 을 한 번도 쓰지 않는다 —
 *   전부 ui.js 의 el()/text() (textContent) 로 그린다. style 속성도 CSSOM 으로만 바꾼다(CSP style-src 'self').
 *
 * ▣ 제안만 한다 — 자동 선택 금지
 *   후보(같은 이메일·아이디·시스템 묶음)는 표시만 한다. 어떤 체크·라디오도 미리 선택해 두지 않는다.
 *   「후보가 하나뿐인 항목 채우기」 같은 단축은 관리자가 누를 때만 채운다.
 *
 * ▣ 흐름: 선택 → 동작 → 미리보기(plan, 상태 불변) → 확정(commit 25건씩 반복) → 결과
 *   commit 은 remaining=0 이 될 때까지 **같은 seqs** 를 보낸다(서버가 아직 planned 인 것을 25건씩 집는다).
 *   새 계정의 첫 비밀번호 설정 링크(setup_url)는 commit 응답에 **한 번만** 온다. 작업 기록(view=job)에도
 *   없다. 그래서 결과 카드는 탭을 옮겨도 남고, 닫거나 화면을 떠나기 전에 한 번 더 묻는다.
 *
 * ▣ 권한 표시는 편의다. 실제 방어는 서버(require_global_role)다. 403 이 오면 안내만 한다.
 * ========================================================================== */
(function (global) {
  'use strict';

  var API_Q = '/api/admin/account_links';
  var API_OPS = '/api/admin/account_link_ops';
  var COMMIT_CHUNK = 25;                   // 서버 AL_COMMIT_CHUNK 와 같은 값(표시용 — 실제 끊기는 서버가 한다)

  /* ---------------------------------------------------------------- 라벨 */
  var STATE_LABEL = { 'new': '미연결', ignored: '무시함', linked: '연결됨', unlinked: '해제됨' };
  var STATE_TONE = { 'new': 'pending', ignored: 'dormant', linked: 'active', unlinked: 'withdrawn' };
  var EXT_STATUS_LABEL = { active: '사용 중', disabled: '비활성', deleted: '삭제됨' };
  var MATCH_LABEL = { email: '이메일 같음', username: '아이디 같음', bound: '시스템이 짝지어 둠' };
  var OP_LABEL = {
    create_link: '새 계정 만들어 연결', link: '기존 계정에 연결', unlink: '연결 해제',
    ignore: '무시', unignore: '무시 취소'
  };
  var VERDICT_LABEL = { ok: '가능', warn: '확인 필요', invalid: '불가' };
  var VERDICT_TONE = { ok: 'active', warn: 'pending', invalid: 'suspended' };
  var RESULT_LABEL = { done: '완료', failed: '실패', skipped: '건너뜀', planned: '대기' };
  var RESULT_TONE = { done: 'active', failed: 'suspended', skipped: 'dormant', planned: 'pending' };
  var ORIGIN_LABEL = { created: '새로 만들어 연결', attached: '기존 계정과 연결' };
  var JOB_KIND_LABEL = { plan: '연결 작업', push: '시스템 수신', sync_request: '재수집 요청', system: '자동 해제' };
  var JOB_STATUS_LABEL = {
    planned: '미리보기', running: '진행 중', done: '완료', partial: '일부 실패', expired: '만료',
    cancelled: '취소', receiving: '수신 중', requested: '요청함'
  };
  /* 항목 판정 코드(plan items[].codes · commit results[].error_code · job items[].error_code).
     봉투 에러 코드가 아니다 — api.js ERROR_MESSAGES 에 넣지 않는다(그 표는 봉투 코드 전용이고
     tests 가 「서버가 던지는 코드」와 1:1 로 대조한다). */
  var CODE_LABEL = {
    EXT_NOT_FOUND: '외부 계정이 더 이상 없습니다',
    EXT_CHANGED: '미리보기 뒤에 이 계정이 바뀌었습니다. 다시 미리보기 하세요',
    EXT_ALREADY_LINKED: '이 외부 계정은 이미 다른 통합로그인 계정에 연결돼 있습니다(먼저 해제)',
    USER_ALREADY_LINKED_SERVICE: '대상 통합로그인 계정이 이 시스템의 다른 외부 계정과 이미 연결돼 있습니다',
    USER_NOT_ACTIVE: '대상 통합로그인 계정이 활성 상태가 아닙니다',
    BAD_TRANSITION: '지금 상태에서는 할 수 없는 동작입니다',
    DUPLICATE_USERNAME: '이미 쓰이는 아이디입니다',
    DUPLICATE_EMAIL: '이미 쓰이는 이메일입니다',
    USERNAME_INVALID: '아이디 규칙(영문 소문자·숫자·_ 4~20자)에 맞지 않습니다',
    EMAIL_REQUIRED: '이메일이 없습니다. 새 계정에는 이메일이 꼭 필요합니다',
    DEPENDENCY_FAILED: '함께 만드는 새 계정이 만들어지지 않아 건너뜁니다',
    SYSTEM_BOUND_OTHER: '시스템이 이 계정을 다른 통합로그인 계정에 묶어 두었습니다',
    EXT_GONE: '시스템에서 사라졌거나 삭제된 계정입니다',
    EXT_DISABLED: '시스템에서 비활성인 계정입니다',
    EXT_ROLE_ADMIN_LOW_RANK: '시스템 관리자 계정을 통합로그인 관리자(admin) 미만 계정에 연결합니다. 그 시스템이 로그인을 막을 수 있습니다',
    EMERGENCY_ACCOUNT: '비상 계정이라 연결하지 않습니다',
    NOT_SELECTED: '선택하지 않아 처리하지 않았습니다',
    CANCELLED: '계획을 취소했습니다',
    SERVER_ERROR: '서버 오류로 처리하지 못했습니다'
  };
  var WARN_CODES = ['SYSTEM_BOUND_OTHER', 'EXT_GONE', 'EXT_DISABLED', 'EXT_ROLE_ADMIN_LOW_RANK'];
  /* 서버 AL_TRANSITIONS 의 from 집합. 미리보기 전에 할 수 없는 항목을 빼고 알리는 데만 쓴다. */
  var OP_FROM = {
    create_link: ['new', 'ignored', 'unlinked'], link: ['new', 'ignored', 'unlinked'],
    ignore: ['new', 'unlinked'], unignore: ['ignored'], unlink: ['linked']
  };
  /* 서버 AL_ADMIN_EXTRA_ROLES 와 같다. 표시(D-328 경고 미리 알림)에만 쓴다 — 판정은 plan 이 한다. */
  var ADMIN_EXTRA_ROLES = ['admin', 'superadmin', 'administrator'];
  var RANK_ADMIN = 20;
  var STALE_DAYS = 3;

  /* ---------------------------------------------------------------- 상태 */
  var C = null;                         // init(ctx)
  var S = {
    systems: [], sysById: {},
    acc: { service_id: '', q: '', state: 'not_linked', ext: 'any', candidate: 'any', sort: 'updated_at', order: 'desc', per_page: 50, page: 1 },
    accItems: [],
    sel: {},                            // "service_id\nexternal_id" → 항목 스냅숏(row_version 포함)
    person: null,                       // 열린 사람 uuid
    groups: { page: 1, without_user: false },   /* v1.8.11 — 기본은 전부 보인다. true 면 이메일이 같은 통합로그인 계정이 있는 외부 계정(예: 관리자 본인)이 목록에서 빠졌다 */
    jobs: { page: 1, kind: '' },
    pendingLinks: 0,                    // 아직 확인하지 않은 설정 링크 수(떠날 때 경고)
    sub: null
  };
  var _n = 0;
  function nid(p) { _n += 1; return 'al-' + p + '-' + _n; }

  /* ---------------------------------------------------------------- 공용 */
  function nz(v) { return (v === null || v === undefined || v === '') ? '-' : String(v); }
  function has(o, k) { return Object.prototype.hasOwnProperty.call(o, k); }
  function lbl(map, v) { return has(map, v) ? map[v] : nz(v); }
  function keyOf(it) { return it.service_id + '\n' + it.external_id; }
  function sysName(id) { var s = S.sysById[id]; return s ? s.service_name : id; }
  function canWrite() { return !!(C && C.canManage); }
  function chip(label, tone, title) {
    return el('span', { class: 'badge badge-status', dataset: { value: tone || '' }, title: title || null }, String(label));
  }
  /** 비상 계정 판정 — 서버가 준 is_emergency / account_kind 만 본다(아이디로 추측하지 않는다) */
  function isEmerg(o) { return !!o && (o.is_emergency === true || o.is_emergency === 1 || o.account_kind === 'emergency'); }
  function emergChip() { return el('span', { class: 'badge', title: '비상 계정은 연결 대상이 아닙니다' }, '비상'); }
  function plainChip(label, title) { return el('span', { class: 'badge', title: title || null }, String(label)); }
  function isAdminExtra(extra) {
    var r = extra && typeof extra.role === 'string' ? extra.role.trim().toLowerCase() : '';
    return ADMIN_EXTRA_ROLES.indexOf(r) >= 0;
  }
  function daysSince(iso) {
    if (!iso) { return null; }
    var t = new Date(iso).getTime();
    if (isNaN(t)) { return null; }
    return Math.floor((Date.now() - t) / 86400000);
  }
  function short(id) { var s = String(id || ''); return s.length > 8 ? s.slice(0, 8) + '…' : s; }

  /** 쓰기 버튼. superadmin 이 아니면 비활성 + 이유. */
  function writeBtn(label, cls, onClick, extraTitle) {
    var b = el('button', { type: 'button', class: 'btn ' + (cls || 'btn-secondary') + ' btn-sm' }, label);
    if (!canWrite()) {
      b.disabled = true;
      b.title = '최고관리자(superadmin)만 할 수 있습니다';
      b.setAttribute('data-al-write', 'locked');
    } else {
      b.setAttribute('data-al-write', 'open');
      if (extraTitle) { b.title = extraTitle; }
      b.addEventListener('click', onClick);
    }
    return b;
  }

  function errBox(host, err, title) {
    if (C && typeof C.showError === 'function') { C.showError(host, err, title); return; }
    clear(host);
    host.appendChild(alertNode(errorMessage(err), 'danger', title || '불러올 수 없습니다'));
  }

  /** 판정 코드 목록. 경고(warn) 코드는 강조, D-328 은 굵게. */
  function codesNode(codes) {
    if (!codes || !codes.length) { return el('span', { class: 'text-muted' }, '-'); }
    var ul = el('ul', { class: 'al-codes' });
    codes.forEach(function (c) {
      var warn = WARN_CODES.indexOf(c) >= 0;
      var li = el('li', { class: warn ? 'is-warn' : 'is-bad', title: c });
      var label = lbl(CODE_LABEL, c);
      li.appendChild(c === 'EXT_ROLE_ADMIN_LOW_RANK' ? el('strong', null, label) : text(label));
      li.appendChild(el('code', { class: 'al-code' }, c));
      ul.appendChild(li);
    });
    return ul;
  }

  function q(params) { return apiGet(API_Q, params); }
  function ops(body) { return apiPost(API_OPS, body); }

  /* ======================================================================
   * 0. 뼈대
   * ==================================================================== */
  function init(ctx) {
    if (C) { return; }
    C = ctx || {};
    var root = C.root;
    clear(root);

    if (!canWrite()) {
      root.appendChild(alertNode(
        '목록, 사람 묶음, 작업 기록은 볼 수 있습니다. 연결, 해제, 무시, 파일 가져오기, 설정 링크 발급은 '
        + '최고관리자(superadmin)만 할 수 있어 그 버튼은 꺼져 있습니다. 「다시 가져오기 요청」은 쓸 수 있습니다.',
        'info', '읽기 전용 (관리자)'));
    }
    S.resultHost = el('div', { id: 'al-result' });
    root.appendChild(S.resultHost);

    var bar = el('div', { id: 'al-subbar', class: 'tabs al-subbar' });
    root.appendChild(bar);
    var panes = {
      'al-sys': el('div', { class: 'tab-panel', dataset: { tabPanel: 'al-sys' }, hidden: true }),
      'al-acc': el('div', { class: 'tab-panel', dataset: { tabPanel: 'al-acc' }, hidden: true }),
      'al-person': el('div', { class: 'tab-panel', dataset: { tabPanel: 'al-person' }, hidden: true }),
      'al-jobs': el('div', { class: 'tab-panel', dataset: { tabPanel: 'al-jobs' }, hidden: true })
    };
    Object.keys(panes).forEach(function (k) { root.appendChild(panes[k]); });
    buildSystemsPane(panes['al-sys']);
    buildAccountsPane(panes['al-acc']);
    buildPersonPane(panes['al-person']);
    buildJobsPane(panes['al-jobs']);

    global.addEventListener('beforeunload', function (ev) {
      if (S.pendingLinks > 0) { ev.preventDefault(); ev.returnValue = ''; return ''; }
      return undefined;
    });

    loadSystems().then(function () {
      S.sub = tabs(bar, [
        { key: 'al-sys', label: '시스템' },
        { key: 'al-acc', label: '계정 목록' },
        { key: 'al-person', label: '사람 묶음' },
        { key: 'al-jobs', label: '작업 기록' }
      ], function (key, panel, first) {
        if (!first) { return; }
        if (key === 'al-acc') { loadAccounts(); }
        if (key === 'al-person') { loadGroups(); }
        if (key === 'al-jobs') { loadJobs(); }
      }, { hash: false, panelOf: function (k) { return panes[k]; } });
    });
  }

  /* ======================================================================
   * 1. 시스템 — view=systems · sync_request · import
   * ==================================================================== */
  function buildSystemsPane(p) {
    var card = el('div', { class: 'card' });
    var reload = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', id: 'al-sys-reload' }, '새로 고침');
    reload.addEventListener('click', function () { loadSystems(); });
    card.appendChild(el('div', { class: 'card-head' }, [el('h2', null, '시스템별 수집 상태'), el('span', { class: 'spacer' }), reload]));
    S.sysHost = el('div', { class: 'card-body', id: 'al-sys-host' });
    card.appendChild(S.sysHost);
    p.appendChild(card);
    p.appendChild(buildImportCard());
  }

  function loadSystems() {
    showLoadingIn(S.sysHost, '시스템 목록을 불러오는 중…');
    return q({ view: 'systems' }).then(function (d) {
      S.systems = d.systems || [];
      S.sysById = {};
      S.systems.forEach(function (s) { S.sysById[s.service_id] = s; });
      renderSystems();
      fillSystemSelects();
    }).catch(function (err) { errBox(S.sysHost, err, '시스템 목록을 불러올 수 없습니다'); });
  }

  function showLoadingIn(host, msg) { if (!host) { return; } clear(host); host.appendChild(loadingNode(msg)); }

  function supportCell(s) {
    var box = el('div', { class: 'al-stack' });
    if (!s.supported) {
      box.appendChild(chip('미지원', 'dormant', '이 시스템에서 계정 목록을 한 번도 받지 못했습니다(push 0회)'));
      box.appendChild(el('span', { class: 'text-xs text-muted' }, '수신 0회(파일 가져오기로 넣을 수 있습니다)'));
    } else {
      var days = daysSince(s.last_push_at);
      if (days !== null && days >= STALE_DAYS) {
        box.appendChild(chip('마지막 수신 ' + days + '일 전', 'pending', '시스템이 한동안 목록을 보내지 않았습니다'));
      } else {
        box.appendChild(chip('정상', 'active'));
      }
    }
    if (s.service_status && s.service_status !== 'active') {
      box.appendChild(plainChip('서비스 ' + (s.service_status === 'disabled' ? '비활성' : s.service_status)));
    }
    return box;
  }

  function syncCell(s) {
    if (!s.sync_requested_at) { return el('span', { class: 'text-muted' }, '-'); }
    var waiting = !s.last_push_at || new Date(s.last_push_at).getTime() < new Date(s.sync_requested_at).getTime();
    return el('div', { class: 'al-stack' }, [
      el('span', null, fmtDate(s.sync_requested_at)),
      waiting ? chip('요청 후 미수신', 'pending') : chip('요청 후 받음', 'active')
    ]);
  }

  function renderSystems() {
    var host = S.sysHost;
    clear(host);
    if (!S.systems.length) { host.appendChild(emptyNode('등록된 서비스가 없습니다.')); return; }
    var t = tableNode(['시스템', '지원', '마지막 수신', '마지막 전체 동기화', '재수집 요청',
      { label: '전체', className: 'num' }, { label: '미연결', className: 'num' }, { label: '연결', className: 'num' },
      { label: '해제됨', className: 'num' }, { label: '무시', className: 'num' }, { label: '사라짐', className: 'num' }, '동작']);
    S.systems.forEach(function (s) {
      var c = s.counts || {};
      var view = el('button', { type: 'button', class: 'btn btn-secondary btn-sm' }, '계정 보기');
      view.addEventListener('click', function () { openAccountsFor(s.service_id); });
      var req = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', dataset: { alSync: s.service_id } }, '다시 가져오기 요청');
      req.addEventListener('click', function () { requestSync(s, req); });
      t.tbody.appendChild(el('tr', null, [
        el('td', null, [el('strong', null, s.service_name), el('div', { class: 'mono text-xs text-muted' }, s.service_id)]),
        el('td', null, supportCell(s)),
        el('td', { class: 'nowrap' }, fmtDate(s.last_push_at)),
        el('td', { class: 'nowrap' }, fmtDate(s.last_full_sync_at)),
        el('td', null, syncCell(s)),
        el('td', { class: 'num' }, fmtNum(c.total)),
        el('td', { class: 'num' }, fmtNum(c['new'])),
        el('td', { class: 'num' }, fmtNum(c.linked)),
        el('td', { class: 'num' }, fmtNum(c.unlinked)),
        el('td', { class: 'num' }, fmtNum(c.ignored)),
        el('td', { class: 'num' }, fmtNum(c.gone)),
        el('td', null, el('div', { class: 'btn-group' }, [view, req]))
      ]));
    });
    host.appendChild(t.wrap);
    host.appendChild(el('p', { class: 'text-xs text-muted' },
      '「다시 가져오기 요청」은 요청만 남깁니다. 시스템이 다음에 확인할 때 계정 목록을 다시 보냅니다.'));
  }

  function requestSync(s, btn) {
    confirmModal('다시 가져오기 요청', s.service_name + ' 에 계정 목록을 다시 보내 달라고 요청합니다. 시스템이 다음에 확인할 때 보냅니다.',
      { confirmText: '요청' }).then(function (ok) {
      if (!ok) { return; }
      setBusy(btn, true);
      ops({ action: 'sync_request', service_id: s.service_id }).then(function (d) {
        toast(sysName(d.service_id) + ' 재수집 요청 · ' + fmtDate(d.sync_requested_at), 'success');
        return loadSystems();
      }).catch(function (err) { toast(errorMessage(err), 'danger'); })
        .then(function () { setBusy(btn, false); });
    });
  }

  /* ---------- 파일 가져오기 (superadmin) ---------- */
  function buildImportCard() {
    var card = el('div', { class: 'card', id: 'al-import' });
    card.appendChild(el('div', { class: 'card-head' }, el('h2', null, '파일로 가져오기')));
    var body = el('div', { class: 'card-body' });
    card.appendChild(body);
    body.appendChild(el('p', { class: 'text-sm text-muted' },
      '아직 목록을 보내지 못하는 시스템용입니다. 그 시스템에서 내보낸 JSON(계정 목록 보내기와 같은 형식: accounts 배열)을 올립니다. '
      + '브라우저가 파일을 읽어 본문으로 보냅니다. 파일에 service_secret이 들어 있으면 보내기 전에 뺍니다.'));
    var selId = nid('imp-sys');
    var fileId = nid('imp-file');
    S.impSys = el('select', { id: selId, name: 'service_id' });
    S.impFile = el('input', { type: 'file', id: fileId, accept: 'application/json,.json' });
    S.impInfo = el('div', { class: 'al-imp-info', role: 'status', 'aria-live': 'polite' });
    S.impOut = el('div');
    var go = writeBtn('가져오기', 'btn-primary', function () { doImport(go); });
    go.id = 'al-imp-go';
    S.impGo = go;
    if (canWrite()) { go.disabled = true; }
    body.appendChild(el('div', { class: 'grid-2' }, [
      el('div', { class: 'field' }, [el('label', { for: selId }, '시스템'), S.impSys, el('span', { class: 'field-error' })]),
      el('div', { class: 'field' }, [el('label', { for: fileId }, 'JSON 파일 (1MB 이하)'), S.impFile, el('span', { class: 'field-error' })])
    ]));
    body.appendChild(S.impInfo);
    body.appendChild(el('div', { class: 'row' }, go));
    body.appendChild(S.impOut);
    if (!canWrite()) {
      S.impFile.disabled = true;
      S.impSys.disabled = true;
    }
    S.impFile.addEventListener('change', readImportFile);
    S.impSys.addEventListener('change', checkImportReady);
    return card;
  }

  function readImportFile() {
    S.impParsed = null;
    clear(S.impInfo); clear(S.impOut);
    var f = S.impFile.files && S.impFile.files[0];
    if (!f) { checkImportReady(); return; }
    if (f.size > 1048576) {
      S.impInfo.appendChild(alertNode('파일이 1MB를 넘습니다. 시스템에서 나눠 내보내세요.', 'danger'));
      checkImportReady();
      return;
    }
    var rd = new FileReader();
    rd.onload = function () {
      var obj;
      try { obj = JSON.parse(String(rd.result || '')); } catch (e) {
        S.impInfo.appendChild(alertNode('JSON으로 읽을 수 없습니다: ' + e.message, 'danger'));
        checkImportReady();
        return;
      }
      if (!obj || typeof obj !== 'object' || Array.isArray(obj) || !Array.isArray(obj.accounts)) {
        S.impInfo.appendChild(alertNode('최상위가 객체이고 accounts 배열이 있어야 합니다.', 'danger'));
        checkImportReady();
        return;
      }
      var stripped = false;
      if (has(obj, 'service_secret')) { delete obj.service_secret; stripped = true; }
      S.impParsed = obj;
      var rows = [
        ['계정 수', fmtNum(obj.accounts.length)],
        ['파일의 service_id', nz(obj.service_id)],
        ['sync_id', obj.sync_id ? String(obj.sync_id) : '없음(서버가 새로 만듭니다)'],
        ['page · final', nz(obj.page === undefined ? 1 : obj.page) + ' · ' + (obj.final ? '마지막(빠진 계정은 「사라짐」 표시)' : '아님')]
      ];
      var t = el('table', { class: 'kv' }, el('tbody', null, rows.map(function (r) {
        return el('tr', null, [el('th', { scope: 'row' }, r[0]), el('td', null, r[1])]);
      })));
      S.impInfo.appendChild(el('div', { class: 'table-wrap' }, t));
      if (stripped) { S.impInfo.appendChild(alertNode('파일에 있던 service_secret은 보내지 않습니다.', 'info')); }
      checkImportReady();
    };
    rd.onerror = function () { S.impInfo.appendChild(alertNode('파일을 읽지 못했습니다.', 'danger')); };
    rd.readAsText(f);
  }

  function checkImportReady() {
    if (!canWrite()) { return; }
    var sid = S.impSys.value;
    var p = S.impParsed;
    var mism = p && p.service_id && sid && p.service_id !== sid;
    var warn = S.impInfo.querySelector('[data-al-mismatch]');
    if (warn) { warn.parentNode.removeChild(warn); }
    if (mism) {
      var a = alertNode('파일의 service_id(' + p.service_id + ')가 고른 시스템(' + sid + ')과 다릅니다. 다른 시스템 파일인지 확인하세요.', 'warn');
      a.setAttribute('data-al-mismatch', '1');
      S.impInfo.appendChild(a);
    }
    S.impGo.disabled = !(sid && p && !mism);
  }

  function doImport(btn) {
    var sid = S.impSys.value;
    if (!sid || !S.impParsed) { return; }
    setBusy(btn, true);
    clear(S.impOut);
    ops({ action: 'import', service_id: sid, file: S.impParsed }).then(function (d) {
      var rows = [
        ['sync_id · page', d.sync_id + ' · ' + d.page],
        ['받은 계정', fmtNum(d.accepted)],
        ['새로 생김', fmtNum(d.created)],
        ['바뀜', fmtNum(d.updated)],
        ['그대로', fmtNum(d.unchanged)],
        ['같은 페이지 재전송', d.duplicate ? '예(바뀐 것 없음)' : '아니오'],
        ['재수집 요청 걸려 있음', d.sync_requested ? '예' : '아니오']
      ];
      S.impOut.appendChild(alertNode(sysName(sid) + ' 계정 ' + fmtNum(d.accepted) + '개를 받았습니다.', 'success', '가져오기 완료'));
      S.impOut.appendChild(el('div', { class: 'table-wrap' }, el('table', { class: 'kv' }, el('tbody', null, rows.map(function (r) {
        return el('tr', null, [el('th', { scope: 'row' }, r[0]), el('td', null, r[1])]);
      })))));
      loadSystems();
    }).catch(function (err) {
      S.impOut.appendChild(alertNode(errorMessage(err) + (err.field ? ' (항목: ' + err.field + ')' : ''), 'danger', '가져오지 못했습니다'));
    }).then(function () { setBusy(btn, false); checkImportReady(); });
  }

  function fillSystemSelects() {
    [S.impSys, S.accSys].forEach(function (sel, i) {
      if (!sel) { return; }
      var cur = sel.value;
      clear(sel);
      sel.appendChild(el('option', { value: '' }, i === 0 ? '— 시스템 고르기 —' : '전체 시스템'));
      S.systems.forEach(function (s) {
        var o = el('option', { value: s.service_id }, s.service_name + ' (' + s.service_id + ')' + (s.supported ? '' : ' · 미지원'));
        sel.appendChild(o);
      });
      sel.value = cur;
    });
    if (S.accSys) { S.accSys.value = S.acc.service_id; }
  }

  /* ======================================================================
   * 2. 계정 목록 — view=accounts + 선택 → 동작
   * ==================================================================== */
  function buildAccountsPane(p) {
    var card = el('div', { class: 'card' });
    card.appendChild(el('div', { class: 'card-head' }, el('h2', null, '외부 계정 찾기')));
    var form = el('form', { class: 'toolbar', id: 'al-acc-filter', novalidate: true });
    function f(label, node, grow) {
      return el('div', { class: grow ? 'field grow' : 'field' }, [el('label', { for: node.id }, label), node]);
    }
    S.accSys = el('select', { id: nid('acc-sys'), name: 'service_id' });
    S.accQ = el('input', { type: 'search', id: nid('acc-q'), name: 'q', maxlength: '190', placeholder: '외부 ID · 아이디 · 이메일 · 이름' });
    /* 연결 상태·시스템 상태 둘 다 서버 질의다(D-335, account_links.php view=accounts 의 state·ext).
       not_linked = new·ignored·unlinked. 받은 페이지를 화면에서 다시 거르지 않는다 — 개수·페이지가 서버 값 그대로다. */
    S.accState = sel(nid('acc-state'), 'state', [['not_linked', '연결 안 됨(미연결·해제·무시)'], ['new', '미연결(새로 받음)'], ['unlinked', '해제됨'], ['ignored', '무시함'], ['linked', '연결됨'], ['all', '전체']], S.acc.state);
    S.accExt = sel(nid('acc-ext'), 'ext', [['any', '모두'], ['present', '정상(사라짐·비활성 제외)'], ['gone', '사라짐·삭제됨'], ['disabled', '비활성']], S.acc.ext);
    S.accCand = sel(nid('acc-cand'), 'candidate', [['any', '상관없음'], ['has', '후보 있음'], ['none', '후보 없음']], 'any');
    S.accSort = sel(nid('acc-sort'), 'sort', [['updated_at', '받은 순'], ['username', '아이디'], ['email', '이메일'], ['ext_last_login_at', '최근 로그인']], 'updated_at');
    S.accOrder = sel(nid('acc-order'), 'order', [['desc', '내림차순'], ['asc', '오름차순']], 'desc');
    S.accPer = sel(nid('acc-per'), 'per_page', [['20', '20'], ['50', '50'], ['100', '100']], '50');
    var reset = el('button', { type: 'button', class: 'btn btn-ghost' }, '초기화');
    mount(form, [
      f('시스템', S.accSys), f('검색어', S.accQ, true), f('연결 상태', S.accState),
      f('시스템 상태', S.accExt), f('후보', S.accCand), f('정렬', S.accSort), f('방향', S.accOrder), f('표시 수', S.accPer),
      el('div', { class: 'row' }, [el('button', { type: 'submit', class: 'btn btn-primary' }, '검색'), reset])
    ]);
    form.addEventListener('submit', function (ev) { ev.preventDefault(); readAccFilter(); S.acc.page = 1; loadAccounts(); });
    reset.addEventListener('click', function () {
      S.accQ.value = ''; S.accState.value = 'not_linked'; S.accExt.value = 'any'; S.accCand.value = 'any';
      S.accSort.value = 'updated_at'; S.accOrder.value = 'desc'; S.accPer.value = '50'; S.accSys.value = '';
      readAccFilter(); S.acc.page = 1; loadAccounts();
    });
    /* 고르는 칸을 바꾸면 바로 첫 페이지부터 다시 받는다(검색어는 「검색」으로). */
    [S.accSys, S.accState, S.accExt, S.accCand, S.accSort, S.accOrder, S.accPer].forEach(function (n) {
      n.addEventListener('change', function () { readAccFilter(); S.acc.page = 1; loadAccounts(); });
    });
    card.appendChild(el('div', { class: 'card-body' }, form));
    p.appendChild(card);

    var list = el('div', { class: 'card' });
    S.accMeta = el('span', { class: 'text-sm text-muted', id: 'al-acc-meta' });
    list.appendChild(el('div', { class: 'card-head' }, [el('h2', null, '외부 계정'), el('span', { class: 'spacer' }), S.accMeta]));
    var lb = el('div', { class: 'card-body' });
    S.selBar = el('div', { class: 'al-sel-bar', id: 'al-sel-bar', role: 'region', 'aria-label': '선택한 계정 동작' });
    S.accHost = el('div', { id: 'al-acc-host' });
    S.accPager = el('div', { id: 'al-acc-pager' });
    mount(lb, [S.selBar, S.accHost, S.accPager]);
    list.appendChild(lb);
    p.appendChild(list);
    renderSelBar();
  }

  function sel(id, name, opts, value) {
    var s = el('select', { id: id, name: name });
    opts.forEach(function (o) { s.appendChild(el('option', { value: o[0] }, o[1])); });
    s.value = value;
    return s;
  }

  function readAccFilter() {
    S.acc.service_id = S.accSys.value;
    S.acc.q = S.accQ.value.trim();
    S.acc.state = S.accState.value;
    S.acc.ext = S.accExt.value;
    S.acc.candidate = S.accCand.value;
    S.acc.sort = S.accSort.value;
    S.acc.order = S.accOrder.value;
    S.acc.per_page = parseInt(S.accPer.value, 10) || 50;
  }

  function openAccountsFor(sid) {
    S.acc.service_id = sid;
    if (S.accSys) { S.accSys.value = sid; }
    S.acc.page = 1;
    if (S.sub) { S.sub.show('al-acc'); }
    loadAccounts();
  }

  function loadAccounts() {
    showLoadingIn(S.accHost, '외부 계정을 불러오는 중…');
    clear(S.accPager);
    return q({
      view: 'accounts', service_id: S.acc.service_id, q: S.acc.q, state: S.acc.state, ext: S.acc.ext, candidate: S.acc.candidate,
      sort: S.acc.sort, order: S.acc.order, page: S.acc.page, per_page: S.acc.per_page
    }).then(function (d) { S.lastAcc = d; renderAccounts(d); })
      .catch(function (err) { errBox(S.accHost, err, '외부 계정을 불러올 수 없습니다'); });
  }

  function renderAccounts(d) {
    var host = S.accHost;
    if (!d) { return; }
    clear(host);
    var items = d.items || [];
    S.accItems = items;
    S.accMeta.textContent = '전체 ' + fmtNum(d.total) + '건';
    pager(S.accPager, d, function (pg) { S.acc.page = pg; loadAccounts(); });
    if (!items.length) {
      host.appendChild(emptyNode('조건에 맞는 외부 계정이 없습니다.',
        S.acc.state !== 'all' ? '연결 상태를 「전체」로 바꾸면 다른 상태의 계정도 보입니다.' : null));
      return;
    }
    var head = el('input', { type: 'checkbox', id: nid('acc-all'), 'aria-label': '이 페이지 전부 선택' });
    head.checked = items.every(function (it) { return has(S.sel, keyOf(it)); });
    head.addEventListener('change', function () {
      items.forEach(function (it) { if (head.checked) { S.sel[keyOf(it)] = it; } else { delete S.sel[keyOf(it)]; } });
      renderAccounts(S.lastAcc);
      renderSelBar();
    });
    var table = el('table', { class: 'table al-acc-table' });
    var trh = el('tr', null, [el('th', { scope: 'col', class: 'al-ck' }, head)]);
    ['시스템', '외부 계정', '이메일', '이름 · 역할', '시스템 상태', '최종 로그인', '연결', '후보 (제안)', '충돌 · 경고']
      .forEach(function (h) { trh.appendChild(el('th', { scope: 'col' }, h)); });
    table.appendChild(el('thead', null, trh));
    var tb = el('tbody');
    items.forEach(function (it) { tb.appendChild(accountRow(it)); });
    table.appendChild(tb);
    host.appendChild(el('div', { class: 'table-wrap' }, table));
  }

  function accountRow(it) {
    var k = keyOf(it);
    var ck = el('input', { type: 'checkbox', 'aria-label': '선택: ' + sysName(it.service_id) + ' ' + it.external_id });
    ck.checked = has(S.sel, k) && !isEmerg(it);
    if (isEmerg(it)) { ck.disabled = true; ck.title = '비상 계정 — 연결하지 않음'; delete S.sel[k]; }
    ck.addEventListener('change', function () {
      if (ck.checked) { S.sel[k] = it; } else { delete S.sel[k]; }
      renderSelBar();
    });
    var extra = it.extra || {};
    var nameCell = el('td', null, [el('div', null, nz(it.name))]);
    var meta = [];
    if (extra.role) { meta.push('역할 ' + extra.role); }
    if (extra.department) { meta.push(extra.department); }
    if (meta.length) { nameCell.appendChild(el('div', { class: 'text-xs text-muted' }, meta.join(' · '))); }
    if (extra.note) { nameCell.appendChild(el('div', { class: 'text-xs text-muted', title: extra.note }, '메모: ' + extra.note)); }
    if (isAdminExtra(extra)) { nameCell.appendChild(chip('시스템 관리자', 'pending', 'extra.role=' + extra.role)); }

    var stCell = el('td', null, el('div', { class: 'al-stack' }, [
      el('span', null, lbl(EXT_STATUS_LABEL, it.ext_status)),
      it.gone ? chip('사라짐', 'suspended', '마지막 전체 동기화에 없었습니다. 연결은 그대로입니다') : null
    ]));

    var linkCell = el('td', null, el('div', { class: 'al-stack' }, [
      chip(lbl(STATE_LABEL, it.state), STATE_TONE[it.state]),
      isEmerg(it) ? emergChip() : null,
      isEmerg(it) ? el('span', { class: 'text-xs text-muted' }, '비상 계정 — 연결하지 않음') : null
    ]));
    if (it.linked_user) {
      var u = it.linked_user;
      linkCell.firstChild.appendChild(personLink(u.uuid, u.username, u.email));
      var sub = [];
      if (it.link_origin) { sub.push(lbl(ORIGIN_LABEL, it.link_origin)); }
      if (it.linked_at) { sub.push(fmtDate(it.linked_at)); }
      if (sub.length) { linkCell.firstChild.appendChild(el('span', { class: 'text-xs text-muted' }, sub.join(' · '))); }
      if ((u.role_rank | 0) >= RANK_ADMIN) { linkCell.firstChild.appendChild(plainChip('통합로그인 관리자')); }
    }

    var candCell = el('td', null, candidatesNode(it));
    var conf = visibleConflicts(it).map(function (c) { return c.code; });
    return el('tr', { class: has(S.sel, k) ? 'is-selected' : null }, [
      el('td', { class: 'al-ck' }, ck),
      el('td', null, [el('div', null, sysName(it.service_id)), el('div', { class: 'mono text-xs text-muted' }, it.service_id)]),
      el('td', null, [el('strong', null, nz(it.username)), isEmerg(it) ? emergChip() : null, el('div', { class: 'mono text-xs text-muted', title: '외부 ID · row_version ' + it.row_version }, it.external_id)]),
      el('td', { class: 'al-break' }, nz(it.email)),
      nameCell,
      stCell,
      el('td', { class: 'nowrap', title: it.ext_created_at ? '시스템 가입 ' + fmtDate(it.ext_created_at) : null }, fmtDate(it.ext_last_login_at)),
      linkCell,
      candCell,
      el('td', null, codesNode(conf))
    ]);
  }

  /* v1.8.6 Q-5(D-347) — 서버는 연결된 행마다 그 주인 uuid 로 EXT_ALREADY_LINKED 를 붙인다(101 계약 키라 서버는 그대로).
     문구가 「이미 **다른** 통합로그인 계정에 연결」이므로, 가리키는 uuid 가 이 행의 연결 주인이면 사실과 달라 숨긴다.
     같은 주인 uuid 를 단 USER_NOT_ACTIVE·EXT_ROLE_ADMIN_LOW_RANK 는 그 주인에 대한 진짜 경고라 남긴다. */
  function visibleConflicts(it) {
    var owner = it.linked_user && it.linked_user.uuid ? String(it.linked_user.uuid).toLowerCase() : '';
    return (it.conflicts || []).filter(function (c) {
      return !(c && c.code === 'EXT_ALREADY_LINKED' && owner !== '' && String(c.uuid || '').toLowerCase() === owner);
    });
  }

  function personLink(uuid, username, email) {
    var b = el('button', { type: 'button', class: 'btn-link al-person-link', title: (email || '') + ' · ' + uuid }, username);
    b.addEventListener('click', function () { openPerson(uuid); });
    return b;
  }

  function matchChips(m) {
    return (m || []).map(function (x) { return el('span', { class: 'badge al-match' }, lbl(MATCH_LABEL, x)); });
  }

  function candidatesNode(it) {
    var list = (it.candidates || []).filter(function (c) { return !isEmerg(c); });
    var box = el('div', { class: 'al-stack' });
    if (!list.length) {
      box.appendChild(el('span', { class: 'text-muted' }, '-'));
    }
    list.forEach(function (c) {
      var row = el('div', { class: 'al-cand' }, [personLink(c.uuid, c.username, c.email)].concat(matchChips(c.match)));
      if ((c.role_rank | 0) >= RANK_ADMIN) { row.appendChild(plainChip('통합로그인 관리자')); }
      if (c.status && c.status !== 'active') { row.appendChild(chip(labelOf('status', c.status), c.status)); }
      if (c.already_linked_here) { row.appendChild(chip('이 시스템에 이미 연결', 'suspended')); }
      if (isAdminExtra(it.extra) && (c.role_rank | 0) < RANK_ADMIN) {
        row.appendChild(chip('관리자 계정 주의', 'pending', lbl(CODE_LABEL, 'EXT_ROLE_ADMIN_LOW_RANK')));
      }
      box.appendChild(row);
    });
    if (it.bound_uuid && !list.some(function (c) { return String(c.uuid).toLowerCase() === String(it.bound_uuid).toLowerCase(); })) {
      box.appendChild(el('span', { class: 'text-xs text-muted', title: it.bound_uuid }, '시스템이 정한 짝 ID ' + short(it.bound_uuid)));
    }
    return box;
  }

  function selected() { return Object.keys(S.sel).map(function (k) { return S.sel[k]; }); }

  function renderSelBar() {
    var bar = S.selBar;
    if (!bar) { return; }
    clear(bar);
    var items = selected();
    bar.appendChild(el('strong', { id: 'al-sel-count' }, '선택 ' + items.length + '건'));
    if (items.length) { bar.appendChild(el('span', { class: 'text-xs text-muted' }, '(다른 페이지 포함)')); }
    var none = items.length === 0;
    var acts = [
      writeBtn('기존 통합로그인 계정으로 이관', 'btn-primary', function () { openAttach(); }),
      writeBtn('새 통합로그인 계정 만들어 이관', 'btn-secondary', function () { openCreate(); }),
      writeBtn('무시', 'btn-secondary', function () { simpleOp('ignore'); }),
      writeBtn('무시 취소', 'btn-secondary', function () { simpleOp('unignore'); }),
      writeBtn('연결 해제', 'btn-danger', function () { simpleOp('unlink'); })
    ];
    acts.forEach(function (b) { if (none && canWrite()) { b.disabled = true; } bar.appendChild(b); });
    var bound = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', title: '이 페이지에서 시스템이 짝을 정해 둔 미연결 계정을 모두 고릅니다. 어느 계정과 연결할지는 다음 단계에서 정합니다.' }, '시스템이 짝지은 계정 모두 선택');
    bound.addEventListener('click', function () {
      var n = 0;
      S.accItems.forEach(function (it) {
        if (it.state !== 'linked' && (it.candidates || []).some(function (c) { return (c.match || []).indexOf('bound') >= 0; })) {
          S.sel[keyOf(it)] = it; n++;
        }
      });
      toast(n ? n + '건을 골랐습니다.' : '이 페이지에는 시스템이 짝지어 둔 미연결 계정이 없습니다.', n ? 'info' : 'warn');
      renderAccounts(S.lastAcc); renderSelBar();
    });
    var clr = el('button', { type: 'button', class: 'btn btn-ghost btn-sm' }, '선택 비우기');
    clr.disabled = none;
    clr.addEventListener('click', function () { S.sel = {}; renderAccounts(S.lastAcc); renderSelBar(); });
    bar.appendChild(el('span', { class: 'spacer' }));
    bar.appendChild(bound);
    bar.appendChild(clr);
  }

  /** 지금 상태에서 그 동작을 할 수 있는 항목만. 뺀 것은 이유와 함께 돌려준다. */
  function eligible(op, items) {
    var ok = [], out = [];
    items.forEach(function (it) { (OP_FROM[op].indexOf(it.state) >= 0 ? ok : out).push(it); });
    return { ok: ok, out: out };
  }
  function excludedNote(op, out) {
    if (!out.length) { return null; }
    return alertNode(out.length + '건은 지금 상태(' + out.map(function (x) { return lbl(STATE_LABEL, x.state); })
      .filter(function (v, i, a) { return a.indexOf(v) === i; }).join(', ') + ')에서 「' + OP_LABEL[op] + '」을 할 수 없어 뺐습니다.', 'info');
  }

  function itemOf(op, it, target) {
    var o = { op: op, service_id: it.service_id, external_id: it.external_id, expect_version: it.row_version };
    if (target) { o.target = target; }
    return o;
  }

  function simpleOp(op) {
    var e = eligible(op, selected());
    if (!e.ok.length) { alertModal(OP_LABEL[op], '선택한 계정 중 지금 「' + OP_LABEL[op] + '」을 할 수 있는 것이 없습니다.'); return; }
    var meta = {};
    var items = e.ok.map(function (it, i) { meta[i + 1] = { label: it.username || it.external_id, target: null }; return itemOf(op, it); });
    var go = function () { preview({ new_users: [], items: items }, meta); };
    if (op === 'unlink') {
      confirmModal('연결 해제', function (b) {
        b.appendChild(alertNode('연결을 해제하면 그 사람은 이 시스템에서 다시 로그인해야 합니다. '
          + '외부 계정은 지워지지 않습니다. 해제해도 시스템 비밀번호는 자동으로 돌아오지 않습니다. 다음 단계에서 미리보기를 확인한 뒤 확정합니다.', 'warn'));
        var n = excludedNote(op, e.out); if (n) { b.appendChild(n); }
      }, { confirmText: '미리보기', danger: true }).then(function (ok) { if (ok) { go(); } });
      return;
    }
    if (e.out.length) { toast(e.out.length + '건은 지금 상태에서 할 수 없어 뺐습니다.', 'info'); }
    go();
  }

  /* ---------- 기존 계정에 붙이기 ---------- */
  function openAttach() {
    var e = eligible('link', selected());
    if (!e.ok.length) { alertModal('기존 통합로그인 계정으로 이관', '선택한 계정이 모두 이미 연결돼 있습니다. 먼저 연결을 해제하세요.'); return; }
    var rows = [];
    var global_ = { target: null };
    modal({
      title: '기존 통합로그인 계정으로 이관 · ' + e.ok.length + '건',
      large: true,
      confirmText: '미리보기',
      body: function (b) {
        var n = excludedNote('link', e.out); if (n) { b.appendChild(n); }
        b.appendChild(el('p', { class: 'text-sm text-muted' },
          '계정마다 연결할 통합로그인 계정을 고르세요. 추천 후보도 직접 골라야 하며, 고르지 않은 계정은 건너뜁니다.'));
        var finder = userFinder('모든 계정을 같은 통합로그인 계정에 연결', function (u) {
          global_.target = u;
          rows.forEach(function (r) { r.setGlobal(u); });
        });
        b.appendChild(finder);
        var fill1 = el('button', { type: 'button', class: 'btn btn-ghost btn-sm' }, '후보가 하나뿐인 항목 채우기');
        fill1.addEventListener('click', function () { rows.forEach(function (r) { r.fillSingle(); }); });
        var fillB = el('button', { type: 'button', class: 'btn btn-ghost btn-sm' }, '시스템이 정한 짝으로 채우기');
        fillB.addEventListener('click', function () { rows.forEach(function (r) { r.fillBound(); }); });
        b.appendChild(el('div', { class: 'btn-group' }, [fill1, fillB]));
        var t = tableNode(['시스템', '외부 계정', '연결할 통합로그인 계정', '알림']);
        e.ok.forEach(function (it) { var r = attachRow(it); rows.push(r); t.tbody.appendChild(r.tr); });
        b.appendChild(t.wrap);
      },
      onConfirm: function () {
        var chosen = rows.filter(function (r) { return r.value(); });
        if (!chosen.length) { return Promise.reject(new Error('연결할 계정을 하나 이상 고르세요.')); }
        return { chosen: chosen };
      }
    }).then(function (r) {
      if (!r || !r.chosen) { return; }
      var meta = {};
      var items = r.chosen.map(function (row, i) {
        var u = row.value();
        meta[i + 1] = { label: row.it.username || row.it.external_id, target: u.username };
        return itemOf('link', row.it, { uuid: u.uuid });
      });
      preview({ new_users: [], items: items }, meta);
    });
  }

  function attachRow(it) {
    var id = nid('att');
    var cands = (it.candidates || []).filter(function (c) { return !isEmerg(c); });
    var byUuid = {};
    var s = el('select', { id: id, 'aria-label': '연결할 계정: ' + (it.username || it.external_id) });
    function rebuild(extraU) {
      var cur = s.value;
      clear(s);
      s.appendChild(el('option', { value: '' }, '— 고르세요 —'));
      cands.forEach(function (c) {
        byUuid[c.uuid] = c;
        s.appendChild(el('option', { value: c.uuid },
          c.username + ' · ' + (c.match || []).map(function (m) { return lbl(MATCH_LABEL, m); }).join(', ')
          + ((c.role_rank | 0) >= RANK_ADMIN ? ' · 관리자' : '') + (c.already_linked_here ? ' · 이미 이 시스템 연결' : '')));
      });
      if (extraU && !has(byUuid, extraU.uuid)) {
        byUuid[extraU.uuid] = extraU;
        s.appendChild(el('option', { value: extraU.uuid }, extraU.username + ' · 직접 고름'));
      }
      s.value = cur;
    }
    rebuild(null);
    var note = el('td');
    function sync() {
      clear(note);
      var u = byUuid[s.value];
      if (!u) { return; }
      var codes = [];
      if (isAdminExtra(it.extra) && (u.role_rank | 0) < RANK_ADMIN) { codes.push('EXT_ROLE_ADMIN_LOW_RANK'); }
      if (u.already_linked_here) { codes.push('USER_ALREADY_LINKED_SERVICE'); }
      if (u.status && u.status !== 'active') { codes.push('USER_NOT_ACTIVE'); }
      if (it.gone || it.ext_status === 'deleted') { codes.push('EXT_GONE'); } else if (it.ext_status === 'disabled') { codes.push('EXT_DISABLED'); }
      note.appendChild(codesNode(codes));
    }
    s.addEventListener('change', sync);
    var tr = el('tr', null, [
      el('td', null, sysName(it.service_id)),
      el('td', null, [el('strong', null, nz(it.username)), el('div', { class: 'text-xs text-muted' }, nz(it.email)),
        isAdminExtra(it.extra) ? chip('시스템 관리자', 'pending') : null]),
      el('td', null, s),
      note
    ]);
    return {
      it: it, tr: tr,
      value: function () { return byUuid[s.value] || null; },
      setGlobal: function (u) { rebuild(u); s.value = u.uuid; sync(); },
      fillSingle: function () { if (!s.value && cands.length === 1) { s.value = cands[0].uuid; sync(); } },
      fillBound: function () {
        if (s.value) { return; }
        var b = cands.filter(function (c) { return (c.match || []).indexOf('bound') >= 0; })[0];
        if (b) { s.value = b.uuid; sync(); }
      }
    };
  }

  /** 통합로그인 계정 찾기 — GET /api/admin/users?q= (회원 목록 API). onPick(u{uuid,username,email,role_rank,status}). */
  function userFinder(title, onPick) {
    var id = nid('uf');
    var inp = el('input', { type: 'search', id: id, maxlength: '190', placeholder: '아이디 · 이메일 · 이름' });
    var btn = el('button', { type: 'button', class: 'btn btn-secondary btn-sm' }, '찾기');
    var out = el('div', { class: 'al-finder-out' });
    var picked = el('div', { class: 'text-sm', role: 'status', 'aria-live': 'polite' });
    function run() {
      var qq = inp.value.trim();
      if (!qq) { return; }
      setBusy(btn, true);
      clear(out);
      apiGet('/api/admin/users', { q: qq, per_page: 10, page: 1 }).then(function (d) {
        var list = d.items || [];
        if (!list.length) { out.appendChild(el('span', { class: 'text-muted text-sm' }, '찾은 계정이 없습니다.')); return; }
        list.forEach(function (u) {
          var b = el('button', { type: 'button', class: 'btn btn-ghost btn-sm al-pick' },
            u.username + ' · ' + nz(u.email) + (u.name ? ' · ' + u.name : '') + ((u.role_rank | 0) >= RANK_ADMIN ? ' · 관리자' : ''));
          if (u.status !== 'active') { b.appendChild(text(' · ' + labelOf('status', u.status))); }
          b.addEventListener('click', function () {
            clear(picked);
            picked.appendChild(text('고름: ' + u.username + ' (' + nz(u.email) + ')'));
            onPick({ uuid: u.uuid, username: u.username, email: u.email, role_rank: u.role_rank, status: u.status });
          });
          out.appendChild(b);
        });
      }).catch(function (err) { out.appendChild(el('span', { class: 'text-danger text-sm' }, errorMessage(err))); })
        .then(function () { setBusy(btn, false); });
    }
    btn.addEventListener('click', run);
    inp.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); run(); } });
    return el('div', { class: 'al-finder' }, [
      el('div', { class: 'field' }, [el('label', { for: id }, title), el('div', { class: 'row' }, [inp, btn])]),
      out, picked
    ]);
  }

  /* ---------- 새 계정으로 이관 ---------- */
  function suggestUsername(it) {
    var base = it.username || (it.email ? String(it.email).split('@')[0] : '');
    return String(base || '').toLowerCase();
  }

  function openCreate() {
    var e = eligible('create_link', selected());
    if (!e.ok.length) { alertModal('새 통합로그인 계정 만들어 이관', '선택한 계정이 모두 이미 연결돼 있습니다. 먼저 연결을 해제하세요.'); return; }
    var groupBox, tbodyHost;
    var groups = [];
    function build() {
      clear(tbodyHost);
      groups = [];
      var byEmail = {};
      e.ok.forEach(function (it) {
        var k = (groupBox.checked && it.email) ? 'e:' + String(it.email).toLowerCase() : 'x:' + keyOf(it);
        if (!byEmail[k]) { byEmail[k] = []; groups.push(byEmail[k]); }
        byEmail[k].push(it);
      });
      var t = tableNode(['연결할 외부 계정', '새 아이디', '이메일', '이름']);
      t.table.classList.add('al-nu');
      groups.forEach(function (g, i) {
        var first = g[0];
        var uid = nid('nu-u'), eid = nid('nu-e'), mid = nid('nu-n');
        g.inputs = {
          username: el('input', { type: 'text', id: uid, maxlength: '50', value: suggestUsername(first), spellcheck: 'false', 'aria-label': '새 아이디 ' + (i + 1) }),
          email: el('input', { type: 'email', id: eid, maxlength: '190', value: first.email || '', 'aria-label': '이메일 ' + (i + 1) }),
          name: el('input', { type: 'text', id: mid, maxlength: '80', value: first.name || '', 'aria-label': '이름 ' + (i + 1) })
        };
        var who = el('div', { class: 'al-stack' });
        var seenSvc = {};
        g.forEach(function (it) {
          var dup = seenSvc[it.service_id];
          seenSvc[it.service_id] = true;
          who.appendChild(el('div', null, [
            el('strong', null, sysName(it.service_id)), text(' · ' + nz(it.username)),
            isAdminExtra(it.extra) ? chip('시스템 관리자', 'pending', '새 계정은 일반 사용자로 만들어집니다. 그 시스템이 관리자 로그인을 막을 수 있습니다') : null,
            dup ? chip('같은 시스템 두 번', 'suspended', '한 사람은 시스템마다 외부 계정 하나만 연결할 수 있습니다') : null
          ]));
        });
        t.tbody.appendChild(el('tr', null, [el('td', null, who), el('td', null, g.inputs.username), el('td', null, g.inputs.email), el('td', null, g.inputs.name)]));
      });
      tbodyHost.appendChild(t.wrap);
    }
    modal({
      title: '새 통합로그인 계정 만들어 이관 · ' + e.ok.length + '건',
      large: true,
      confirmText: '미리보기',
      body: function (b) {
        var n = excludedNote('create_link', e.out); if (n) { b.appendChild(n); }
        b.appendChild(alertNode('기존 비밀번호는 가져오지 않습니다. 확정하면 계정마다 「첫 비밀번호 설정 링크」(72시간)를 한 번만 보여 줍니다. 그 링크를 본인에게 전달하세요. '
          + '새 계정의 등급과 역할은 일반 사용자로 시작합니다.', 'info'));
        var gid = nid('grp');
        groupBox = el('input', { type: 'checkbox', id: gid });
        groupBox.addEventListener('change', build);
        b.appendChild(el('label', { class: 'check', for: gid }, [groupBox, el('span', null, '이메일이 같은 계정은 새 계정 하나로 합치기 (시스템이 서로 다른 계정만 합쳐집니다)')]));
        tbodyHost = el('div');
        b.appendChild(tbodyHost);
        build();
      },
      onConfirm: function () { return { ok: true }; }
    }).then(function (r) {
      if (!r || !r.ok) { return; }
      var newUsers = [], items = [], meta = {};
      groups.forEach(function (g, gi) {
        var ref = 'n' + (gi + 1);
        var uname = g.inputs.username.value.trim();
        newUsers.push({ ref: ref, username: uname, email: g.inputs.email.value.trim(), name: g.inputs.name.value.trim() });
        g.forEach(function (it, j) {
          items.push(itemOf(j === 0 ? 'create_link' : 'link', it, { ref: ref }));
          meta[items.length] = { label: it.username || it.external_id, target: '새 계정 ' + (uname || ref), ref: ref };
        });
      });
      preview({ new_users: newUsers, items: items }, meta);
    });
  }

  /* ======================================================================
   * 3. 미리보기(plan) → 확정(commit) → 결과
   * ==================================================================== */

  /**
   * req = { new_users:[...], items:[...] } 또는 { retry_job_id }.
   * meta[seq] = { label, target, ref } — 화면 표시용(서버가 돌려주지 않는 외부 아이디·대상 이름).
   * seq 는 서버가 items 배열 순서대로 1부터 매긴다(account_link_ops.php alo_parse_plan_body).
   */
  function preview(req, meta) {
    var body = { action: 'plan', new_users: req.new_users || [], items: req.items || [] };
    if (req.retry_job_id) { body = { action: 'plan', retry_job_id: req.retry_job_id }; }
    ops(body).then(function (plan) {
      showPreview(plan, req, meta || {});
    }).catch(function (err) {
      alertModal('미리보기를 만들 수 없습니다', errorMessage(err) + (err.field ? ' (항목: ' + err.field + ')' : ''));
    });
  }

  function cancelPlan(planId) {
    if (!planId) { return; }
    ops({ action: 'cancel', plan_id: planId }).catch(function () { /* 만료·확정이면 그대로 둔다 */ });
  }

  function showPreview(plan, req, meta) {
    var checks = {};                    // seq → checkbox
    var nuInputs = {};                  // ref → {username,email,name}
    var ack = null;
    var replanned = false;
    var edited = false;
    /* 재시도 계획(retry_job_id)은 새 계정 값을 서버가 옛 계획에서 가져온다 — 화면에 원래 값이 없으므로 고치지 않는다. */
    var editable = !req.retry_job_id;
    function markEdited() {
      edited = true;
      var n = global.document.getElementById('al-edited');
      if (n) { n.textContent = '고친 내용은 다시 미리보기해야 반영됩니다.'; }
    }
    var depOf = {};                     // ref → [seq…] (create_link 에 기대는 link 항목)
    (plan.items || []).forEach(function (it) {
      var m = meta[it.seq] || {};
      if (m.ref && it.op === 'link') { (depOf[m.ref] = depOf[m.ref] || []).push(it.seq); }
    });

    function syncDeps() {
      (plan.items || []).forEach(function (it) {
        var m = meta[it.seq] || {};
        if (it.op !== 'create_link' || !m.ref) { return; }
        var on = checks[it.seq] && checks[it.seq].checked;
        (depOf[m.ref] || []).forEach(function (s) {
          var c = checks[s];
          if (!c || c.dataset.invalid === '1') { return; }
          if (!on) { c.checked = false; }
          c.disabled = !on;
          c.title = on ? '' : '함께 만드는 새 계정을 고르지 않아 이 항목도 처리할 수 없습니다';
        });
      });
      syncAck();
    }
    function warnChecked() {
      return (plan.items || []).filter(function (it) { return it.verdict === 'warn' && checks[it.seq] && checks[it.seq].checked; }).length;
    }
    function syncAck() {
      if (!ack) { return; }
      var n = warnChecked();
      ack.wrap.hidden = n === 0;
      ack.label.textContent = '경고가 있는 ' + n + '건을 확인했고 그대로 진행합니다';
    }

    modal({
      title: '미리보기: 아직 아무것도 바뀌지 않았습니다',
      large: true,
      confirmText: '확정',
      body: function (b) {
        var sm = plan.summary || {};
        if ((plan.items || []).some(function (x) { return x.op === 'link' || x.op === 'create_link'; })) {
          b.appendChild(alertNode('이관하면 이 시스템의 기존 로그인은 사라지고, 통합로그인 계정으로만 들어오게 됩니다.', 'warn'));
        }
        b.appendChild(el('div', { class: 'stat-grid al-sum' }, [
          statNode('가능', fmtNum(sm.ok), null, 'success'),
          statNode('확인 필요', fmtNum(sm.warn), null, 'warn'),
          statNode('불가', fmtNum(sm.invalid), '체크할 수 없습니다', 'danger')
        ]));
        b.appendChild(el('p', { class: 'text-xs text-muted', id: 'al-plan-id' },
          '계획 ' + short(plan.plan_id) + ' · ' + fmtDate(plan.expires_at) + ' 까지 유효(30분). 다른 관리자는 이 계획을 확정할 수 없습니다.'));

        if ((plan.new_users || []).length) {
          b.appendChild(el('h4', { class: 'al-h' }, '새로 만들 통합로그인 계정'));
          var nt = tableNode(['', '아이디', '이메일', '이름', '판정', '사유 · 제안']);
          nt.table.classList.add('al-nu');
          var src = {};
          (req.new_users || []).forEach(function (n) { src[n.ref] = n; });
          plan.new_users.forEach(function (nu) {
            var s0 = src[nu.ref] || {};
            if (!editable) {
              nt.tbody.appendChild(el('tr', null, [
                el('td', { class: 'mono text-xs' }, nu.ref), el('td', { class: 'text-muted' }, '옛 계획 값'), el('td', null, '-'), el('td', null, '-'),
                el('td', null, chip(lbl(VERDICT_LABEL, nu.verdict), VERDICT_TONE[nu.verdict])), el('td', null, codesNode(nu.codes))
              ]));
              return;
            }
            var inp = {
              username: el('input', { type: 'text', maxlength: '50', value: s0.username || '', 'aria-label': '아이디 ' + nu.ref, spellcheck: 'false' }),
              email: el('input', { type: 'email', maxlength: '190', value: s0.email || '', 'aria-label': '이메일 ' + nu.ref }),
              name: el('input', { type: 'text', maxlength: '80', value: s0.name || '', 'aria-label': '이름 ' + nu.ref })
            };
            nuInputs[nu.ref] = inp;
            var why = el('td', null, codesNode(nu.codes));
            if (nu.suggested_username) {
              var ap = el('button', { type: 'button', class: 'btn btn-ghost btn-sm' }, '제안 「' + nu.suggested_username + '」 넣기');
              ap.addEventListener('click', function () { inp.username.value = nu.suggested_username; markEdited(); });
              why.appendChild(ap);
            }
            if (nu.attach_candidate) {
              var ac = nu.attach_candidate;
              var sw = el('button', { type: 'button', class: 'btn btn-ghost btn-sm' }, '같은 이메일의 기존 계정 「' + ac.username + '」과 연결하기로 바꾸기');
              sw.addEventListener('click', function () { switchToAttach(nu.ref, ac); });
              why.appendChild(sw);
            }
            Object.keys(inp).forEach(function (k) { inp[k].addEventListener('input', markEdited); });
            nt.tbody.appendChild(el('tr', null, [
              el('td', { class: 'mono text-xs' }, nu.ref), el('td', null, inp.username), el('td', null, inp.email), el('td', null, inp.name),
              el('td', null, chip(lbl(VERDICT_LABEL, nu.verdict), VERDICT_TONE[nu.verdict])), why
            ]));
          });
          b.appendChild(nt.wrap);
          if (!editable) {
            b.appendChild(el('p', { class: 'text-xs text-muted' }, '다시 계획은 옛 계획의 새 계정 값을 그대로 씁니다. 값을 바꾸려면 계정 목록에서 새로 고르세요.'));
          }
          var re = el('button', { type: 'button', class: 'btn btn-secondary btn-sm', id: 'al-replan' }, '고친 내용으로 다시 미리보기');
          re.addEventListener('click', function () { replan(); });
          if (editable) { b.appendChild(el('div', { class: 'row' }, [re, el('span', { class: 'text-xs text-muted', id: 'al-edited' }, '')])); }
        }

        b.appendChild(el('h4', { class: 'al-h' }, '항목'));
        var t = tableNode(['확정', '#', '동작', '시스템', '외부 계정', '대상', '판정', '사유']);
        (plan.items || []).forEach(function (it) {
          var m = meta[it.seq] || {};
          var ck = el('input', { type: 'checkbox', 'aria-label': '확정에 포함: ' + it.seq });
          ck.checked = it.verdict !== 'invalid';
          if (it.verdict === 'invalid') { ck.disabled = true; ck.dataset.invalid = '1'; ck.title = '불가 항목은 확정할 수 없습니다'; }
          ck.addEventListener('change', syncDeps);
          checks[it.seq] = ck;
          t.tbody.appendChild(el('tr', { class: 'al-v-' + it.verdict }, [
            el('td', { class: 'al-ck' }, ck),
            el('td', { class: 'num' }, String(it.seq)),
            el('td', null, lbl(OP_LABEL, it.op)),
            el('td', null, sysName(it.service_id)),
            el('td', null, [el('div', null, nz(m.label)), el('div', { class: 'mono text-xs text-muted' }, it.external_id)]),
            el('td', null, nz(m.target)),
            el('td', null, chip(lbl(VERDICT_LABEL, it.verdict), VERDICT_TONE[it.verdict])),
            el('td', null, codesNode(it.codes))
          ]));
        });
        b.appendChild(t.wrap);

        var aid = nid('ack');
        var ackIn = el('input', { type: 'checkbox', id: aid });
        var ackLabel = el('span');
        var ackWrap = el('div', { class: 'field al-ack' }, el('label', { class: 'check', for: aid }, [ackIn, ackLabel]));
        ack = { input: ackIn, label: ackLabel, wrap: ackWrap };
        b.appendChild(ackWrap);
        b.appendChild(el('p', { class: 'text-xs text-muted' }, '확정하면 ' + COMMIT_CHUNK + '건씩 나눠 처리합니다. 한 건이 실패해도 나머지는 계속합니다.'));
        syncDeps();
      },
      onConfirm: function () {
        if (replanned) { return Promise.reject(new Error('다시 미리보기를 만들었습니다. 새 창에서 확정하세요.')); }
        if (edited) { return Promise.reject(new Error('새 계정 칸을 고쳤습니다. 「고친 내용으로 다시 미리보기」를 먼저 누르세요.')); }
        var seqs = Object.keys(checks).map(Number).filter(function (s) { return checks[s].checked; }).sort(function (a, c) { return a - c; });
        if (!seqs.length) { return Promise.reject(new Error('확정할 항목을 하나 이상 체크하세요.')); }
        if (warnChecked() && !ack.input.checked) { return Promise.reject(new Error('경고가 있는 항목을 확인했다는 칸을 체크하세요.')); }
        return { seqs: seqs };
      }
    }).then(function (r) {
      if (replanned) { return; }
      if (!r || !r.seqs) { cancelPlan(plan.plan_id); return; }
      runCommit(plan, r.seqs, meta);
    });

    function collectNewUsers() {
      return (req.new_users || []).map(function (n) {
        var inp = nuInputs[n.ref];
        if (!inp) { return n; }
        return { ref: n.ref, username: inp.username.value.trim(), email: inp.email.value.trim(), name: inp.name.value.trim() };
      });
    }
    function closeAndReplan(nextReq, nextMeta) {
      replanned = true;
      cancelPlan(plan.plan_id);
      closeTop();
      preview(nextReq, nextMeta);
    }
    function replan() {
      closeAndReplan({ new_users: collectNewUsers(), items: req.items }, meta);
    }
    /** 새 계정 ref 를 같은 이메일의 기존 계정(attach_candidate)으로 바꾼다 — 관리자가 누를 때만. */
    function switchToAttach(ref, ac) {
      var items = [], nm = {};
      (req.items || []).forEach(function (it, i) {
        var m = meta[i + 1] || {};
        if (it.target && it.target.ref === ref) {
          items.push({ op: 'link', service_id: it.service_id, external_id: it.external_id, expect_version: it.expect_version, target: { uuid: ac.uuid } });
          nm[items.length] = { label: m.label, target: ac.username };
        } else {
          items.push(it);
          nm[items.length] = m;
        }
      });
      var nus = collectNewUsers().filter(function (n) { return n.ref !== ref; });
      closeAndReplan({ new_users: nus, items: items }, nm);
    }
  }

  /* ---------- 확정 루프 · 결과 ---------- */
  function runCommit(plan, seqs, meta) {
    var host = S.resultHost;
    clear(host);
    var card = el('div', { class: 'card al-result', id: 'al-result-card' });
    var bodyEl = el('div', { class: 'card-body' });
    card.appendChild(el('div', { class: 'card-head' }, el('h2', null, '확정 진행')));
    card.appendChild(bodyEl);
    host.appendChild(card);
    var total = seqs.length;
    var bar = el('i');
    var prog = el('div', { class: 'al-progress', role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': String(total), 'aria-valuenow': '0', 'aria-label': '확정 진행' }, bar);
    var status = el('p', { class: 'text-sm', role: 'status', 'aria-live': 'polite', id: 'al-progress-text' }, '0 / ' + total + ' 처리');
    var errHost = el('div');
    var tableHost = el('div');
    mount(bodyEl, [prog, status, errHost, tableHost]);
    var results = [];
    var done = 0;
    try { card.scrollIntoView({ block: 'start' }); } catch (e) { /* noop */ }

    function step() {
      return ops({ action: 'commit', plan_id: plan.plan_id, seqs: seqs }).then(function (d) {
        results = results.concat(d.results || []);
        done += d.processed | 0;
        var shown = Math.min(done, total);
        bar.style.width = (total ? Math.round(shown / total * 100) : 100) + '%';
        prog.setAttribute('aria-valuenow', String(shown));
        status.textContent = shown + ' / ' + total + ' 처리 · 완료 ' + fmtNum((d.totals || {}).done) + ' · 실패 ' + fmtNum((d.totals || {}).failed)
          + (d.remaining > 0 ? ' · 남은 ' + d.remaining + '건 계속 처리 중…' : '');
        if (d.remaining > 0) { return step(); }
        return d;
      });
    }
    step().then(function (last) {
      bar.style.width = '100%';
      prog.setAttribute('aria-valuenow', String(total));
      renderResults(card, tableHost, last, results, plan, meta);
    }).catch(function (err) {
      clear(errHost);
      errHost.appendChild(alertNode(errorMessage(err), 'danger', err.code === 'LINK_PLAN_INVALID' ? '계획을 더 쓸 수 없습니다' : '확정이 중간에 멈췄습니다'));
      if (err.code !== 'LINK_PLAN_INVALID') {
        var again = el('button', { type: 'button', class: 'btn btn-primary btn-sm' }, '이어서 진행');
        again.addEventListener('click', function () { clear(errHost); step().then(function (last) { renderResults(card, tableHost, last, results, plan, meta); }).catch(function (e2) { errHost.appendChild(alertNode(errorMessage(e2), 'danger')); }); });
        errHost.appendChild(again);
      }
      if (results.length) { renderResults(card, tableHost, null, results, plan, meta); }
    });
  }

  function renderResults(card, host, last, results, plan, meta) {
    clear(host);
    var head = card.querySelector('.card-head h2');
    if (head && last) { head.textContent = '결과 · ' + lbl(JOB_STATUS_LABEL, last.status); }
    var pitem = {};
    (plan.items || []).forEach(function (it) { pitem[it.seq] = it; });
    var links = results.filter(function (r) { return r.setup_url; });
    S.pendingLinks = links.length;
    if (last) {
      var tt = last.totals || {};
      host.appendChild(el('div', { class: 'stat-grid al-sum' }, [
        statNode('완료', fmtNum(tt.done), null, 'success'),
        statNode('실패', fmtNum(tt.failed), null, 'danger'),
        statNode('건너뜀', fmtNum(tt.skipped), '고르지 않은 항목 포함', null)
      ]));
    }
    if (links.length) {
      host.appendChild(alertNode('새 계정 ' + links.length + '개의 첫 비밀번호 설정 링크입니다(72시간 유효). 지금 한 번만 표시됩니다. 이 화면을 떠나면 다시 볼 수 없습니다. '
        + '잃어버리면 「사람 묶음」에서 그 계정의 설정 링크를 다시 만들 수 있습니다(이전 링크는 쓸 수 없게 됩니다).', 'warn', '설정 링크: 다시 볼 수 없습니다'));
      var all = el('button', { type: 'button', class: 'btn btn-secondary btn-sm', id: 'al-copy-all' }, '링크 전체 복사 (아이디 · 주소)');
      all.addEventListener('click', function () {
        var txt = links.map(function (r) { return (r.user ? r.user.username : '') + '\t' + r.setup_url; }).join('\n');
        copyText(txt);
      });
      host.appendChild(el('div', { class: 'row' }, all));
    }
    var t = tableNode(['#', '동작', '시스템', '외부 계정', '결과', '사유', '통합로그인 계정', '첫 비밀번호 설정 링크']);
    results.slice().sort(function (a, b) { return a.seq - b.seq; }).forEach(function (r) {
      var it = pitem[r.seq] || {};
      var m = meta[r.seq] || {};
      var linkCell = el('td', { class: 'al-linkcell' });
      if (r.setup_url) {
        linkCell.appendChild(C.copyBox ? C.copyBox(r.setup_url) : el('code', null, r.setup_url));
        linkCell.appendChild(el('div', { class: 'text-xs text-muted' }, fmtDate(r.setup_expires_at) + ' 까지'));
      } else { linkCell.appendChild(text('-')); }
      t.tbody.appendChild(el('tr', null, [
        el('td', { class: 'num' }, String(r.seq)),
        el('td', null, lbl(OP_LABEL, it.op)),
        el('td', null, sysName(it.service_id)),
        el('td', null, [el('div', null, nz(m.label)), el('div', { class: 'mono text-xs text-muted' }, nz(it.external_id))]),
        el('td', null, chip(lbl(RESULT_LABEL, r.status), RESULT_TONE[r.status])),
        el('td', null, r.error_code ? codesNode([r.error_code]) : '-'),
        el('td', null, r.user ? personLink(r.user.uuid, r.user.username, '') : '-'),
        linkCell
      ]));
    });
    host.appendChild(t.wrap);
    var row = el('div', { class: 'row' });
    var failed = last ? ((last.totals || {}).failed | 0) + ((last.totals || {}).skipped | 0) : 0;
    if (last && failed > 0) {
      var retry = writeBtn('실패·건너뜀 다시 계획', 'btn-primary', function () { preview({ retry_job_id: last.job_id }, {}); });
      retry.id = 'al-retry';
      row.appendChild(retry);
    }
    if (last) {
      var jb = el('button', { type: 'button', class: 'btn btn-ghost btn-sm' }, '작업 기록에서 보기');
      jb.addEventListener('click', function () { openJob(last.job_id); });
      row.appendChild(jb);
    }
    var close = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', id: 'al-result-close' }, links.length ? '링크 전달 완료: 결과 닫기' : '결과 닫기');
    close.addEventListener('click', function () {
      var go = function () { S.pendingLinks = 0; clear(S.resultHost); };
      if (!links.length) { go(); return; }
      confirmModal('결과 닫기', '설정 링크는 다시 볼 수 없습니다. 모두 전달했나요?', { confirmText: '닫기', danger: true })
        .then(function (ok) { if (ok) { go(); } });
    });
    row.appendChild(close);
    host.appendChild(row);
    if (last) { afterChange(); }
  }

  function copyText(txt) {
    var ok = function () { toast('복사했습니다.', 'success'); };
    var bad = function () { toast('복사에 실패했습니다. 칸을 직접 선택해 복사해 주세요.', 'warn'); };
    if (global.navigator.clipboard && global.navigator.clipboard.writeText) { global.navigator.clipboard.writeText(txt).then(ok, bad); return; }
    bad();
  }

  /** 확정·해제 뒤 화면의 낡은 값(행 버전·개수)을 새로 받는다. */
  function afterChange() {
    S.sel = {};
    renderSelBar();
    loadSystems();
    if (S.lastAcc) { loadAccounts(); }
    if (S.person) { loadPerson(S.person); }
    if (S.groupsLoaded) { loadGroups(); }
    if (S.jobsLoaded) { loadJobs(); }
  }

  /* ======================================================================
   * 4. 사람 묶음 — view=person · view=groups
   * ==================================================================== */
  function buildPersonPane(p) {
    var card = el('div', { class: 'card' });
    card.appendChild(el('div', { class: 'card-head' }, el('h2', null, '통합로그인 계정 하나와 시스템별 연결 칸')));
    var body = el('div', { class: 'card-body' });
    body.appendChild(userFinder('통합로그인 계정 찾기', function (u) { openPerson(u.uuid); }));
    S.personHost = el('div', { id: 'al-person-host' });
    body.appendChild(S.personHost);
    card.appendChild(body);
    p.appendChild(card);

    var gc = el('div', { class: 'card' });
    var gid = nid('gw');
    S.grWithout = el('input', { type: 'checkbox', id: gid });
    S.grWithout.checked = false;
    S.grWithout.addEventListener('change', function () { S.groups.without_user = S.grWithout.checked; S.groups.page = 1; loadGroups(); });
    gc.appendChild(el('div', { class: 'card-head' }, [el('h2', null, '같은 이메일로 묶은 미연결 외부 계정'), el('span', { class: 'spacer' }),
      el('label', { class: 'check', for: gid }, [S.grWithout, el('span', null, '통합로그인 계정이 아직 없는 사람만 보기')])]));
    var gb = el('div', { class: 'card-body' });
    gb.appendChild(el('p', { class: 'text-sm text-muted' },
      '여러 시스템에 같은 이메일로 있는 계정을 한 사람으로 모아 보여 줍니다. 연결할 칸을 체크하면 새 통합로그인 계정 하나로 한꺼번에 연결합니다. 일부 시스템만 체크해도 됩니다.'));
    S.grBar = el('div', { class: 'al-sel-bar' });
    S.grHost = el('div', { id: 'al-groups-host' });
    S.grPager = el('div');
    mount(gb, [S.grBar, S.grHost, S.grPager]);
    gc.appendChild(gb);
    p.appendChild(gc);
  }

  function openPerson(uuid) {
    S.person = uuid;
    if (S.sub && S.sub.current() !== 'al-person') { S.sub.show('al-person'); }
    loadPerson(uuid);
    try { S.personHost.scrollIntoView({ block: 'start' }); } catch (e) { /* noop */ }
  }

  function loadPerson(uuid) {
    showLoadingIn(S.personHost, '사람 묶음을 불러오는 중…');
    return q({ view: 'person', uuid: uuid }).then(function (d) { renderPerson(d); })
      .catch(function (err) { errBox(S.personHost, err, '이 계정을 불러올 수 없습니다'); });
  }

  function renderPerson(d) {
    var host = S.personHost;
    clear(host);
    var u = d.user || {};
    var head = el('div', { class: 'al-person-head' }, [
      el('div', null, [el('strong', { class: 'al-person-name' }, u.username), text(' · ' + nz(u.email) + (u.name ? ' · ' + u.name : ''))]),
      el('div', { class: 'badge-row' }, [
        chip(labelOf('status', u.status), u.status),
        (u.role_rank | 0) >= RANK_ADMIN ? plainChip('통합로그인 관리자 (rank ' + u.role_rank + ')') : plainChip('rank ' + nz(u.role_rank)),
        u.setup_pending ? chip('첫 비밀번호 미설정', 'pending', '다른 시스템 계정으로 만든 계정이며, 아직 비밀번호를 정하지 않았습니다') : null
      ])
    ]);
    host.appendChild(head);
    if (u.setup_pending) {
      var sl = writeBtn('설정 링크 다시 만들기', 'btn-secondary', function () { reissueSetup(u, sl); });
      sl.id = 'al-setup-again';
      host.appendChild(el('div', { class: 'row' }, [sl, el('span', { class: 'text-xs text-muted' }, '이전 링크는 무효가 됩니다.')]));
    }
    var plan = {};                      // service_id → {op, ext}
    var grid = el('div', { class: 'al-cells' });
    (d.systems || []).forEach(function (s) { grid.appendChild(personCell(u, s, plan)); });
    host.appendChild(grid);
    var go = writeBtn('고른 칸 미리보기', 'btn-primary', function () {
      var items = [], meta = {};
      Object.keys(plan).forEach(function (sid) {
        var c = plan[sid];
        if (!c) { return; }
        if (c.op === 'link') {
          items.push({ op: 'link', service_id: sid, external_id: c.ext.external_id, expect_version: c.ext.row_version, target: { uuid: u.uuid } });
          meta[items.length] = { label: c.ext.username || c.ext.external_id, target: u.username };
        } else {
          items.push({ op: 'unlink', service_id: sid, external_id: c.ext.external_id, expect_version: c.ext.row_version });
          meta[items.length] = { label: c.ext.username || c.ext.external_id, target: u.username };
        }
      });
      if (!items.length) { toast('연결하거나 해제할 칸을 고르세요.', 'warn'); return; }
      preview({ new_users: [], items: items }, meta);
    });
    go.id = 'al-person-go';
    host.appendChild(el('div', { class: 'row al-person-foot' }, [go, el('span', { class: 'text-xs text-muted' }, '시스템마다 하나씩만 연결됩니다. 아무 칸도 미리 골라 두지 않습니다.')]));
  }

  function personCell(u, s, plan) {
    var cell = el('div', { class: 'al-cell' + (s.link ? ' is-linked' : ((s.candidates || []).length ? '' : ' is-empty')), dataset: { alCell: s.service_id } });
    cell.appendChild(el('div', { class: 'al-cell-head' }, [el('strong', null, s.service_name || sysName(s.service_id)), el('span', { class: 'mono text-xs text-muted' }, s.service_id)]));
    if (s.link) {
      var L = s.link;
      cell.appendChild(el('div', { class: 'al-stack' }, [
        chip('연결됨', 'active'),
        el('div', null, [el('strong', null, nz(L.username)), el('span', { class: 'mono text-xs text-muted' }, ' ' + L.external_id)]),
        el('span', { class: 'text-xs text-muted' }, lbl(ORIGIN_LABEL, L.link_origin) + ' · ' + fmtDate(L.linked_at))
      ]));
      var uid = nid('ul');
      var uck = el('input', { type: 'checkbox', id: uid, disabled: !canWrite() });
      uck.addEventListener('change', function () { plan[s.service_id] = uck.checked ? { op: 'unlink', ext: L } : null; });
      cell.appendChild(el('label', { class: 'check', for: uid }, [uck, el('span', null, '이 연결 해제')]));
      return cell;
    }
    var name = nid('pc');
    var opts = el('div', { class: 'al-stack', role: 'radiogroup', 'aria-label': s.service_id + ' 에 연결할 외부 계정' });
    function radio(value, label, ext, extraNodes) {
      var id = nid('r');
      var r = el('input', { type: 'radio', name: name, id: id, value: value, disabled: !canWrite() && value !== '' });
      if (value === '') { r.checked = true; }
      r.addEventListener('change', function () { if (r.checked) { plan[s.service_id] = ext ? { op: 'link', ext: ext } : null; } });
      opts.appendChild(el('label', { class: 'check al-radio', for: id }, [r, el('span', null, [text(label)].concat(extraNodes || []))]));
      return r;
    }
    radio('', '연결 안 함', null);
    (s.candidates || []).forEach(function (c) {
      if (isEmerg(c)) { opts.appendChild(el('span', { class: 'text-sm text-muted' }, [emergChip(), text(' ' + nz(c.username) + ' — 연결하지 않음')])); return; }
      var extra = matchChips(c.match);
      if (c.admin_flag) {
        extra.push(chip('시스템 관리자', 'pending'));
        if ((u.role_rank | 0) < RANK_ADMIN) { extra.push(el('div', { class: 'text-xs al-warn-note' }, lbl(CODE_LABEL, 'EXT_ROLE_ADMIN_LOW_RANK'))); }
      }
      if (c.state === 'unlinked') { extra.push(plainChip('해제됨')); }
      radio(c.external_id, nz(c.username) + ' (' + c.external_id + ')' + (c.email ? ' · ' + c.email : ''), c, extra);
    });
    if (!(s.candidates || []).length) {
      opts.appendChild(el('span', { class: 'text-sm text-muted' }, '후보 없음'));
    }
    cell.appendChild(opts);
    var add = writeBtn('추가 연결…', 'btn-ghost', function () {
      pickExternal(s.service_id, function (ext) {
        var r = radio(ext.external_id, nz(ext.username) + ' (' + ext.external_id + ') · 직접 고름', ext,
          ext.admin_flag && (u.role_rank | 0) < RANK_ADMIN ? [el('div', { class: 'text-xs al-warn-note' }, lbl(CODE_LABEL, 'EXT_ROLE_ADMIN_LOW_RANK'))] : []);
        r.checked = true;
        plan[s.service_id] = { op: 'link', ext: ext };
        cell.classList.remove('is-empty');
      });
    }, '이 시스템의 외부 계정을 검색해 직접 고릅니다');
    cell.appendChild(add);
    return cell;
  }

  /** 빈 칸 「추가 연결」 — 그 시스템의 미연결 외부 계정 검색 후 하나 고르기. */
  function pickExternal(sid, onPick) {
    var picked = null;
    modal({
      title: sysName(sid) + ' 외부 계정 고르기',
      large: true,
      confirmText: '이 계정 고르기',
      body: function (b) {
        var id = nid('px');
        var inp = el('input', { type: 'search', id: id, maxlength: '190', placeholder: '외부 ID · 아이디 · 이메일 · 이름' });
        var btn = el('button', { type: 'button', class: 'btn btn-secondary btn-sm' }, '찾기');
        var out = el('div');
        b.appendChild(el('div', { class: 'field' }, [el('label', { for: id }, '검색어'), el('div', { class: 'row' }, [inp, btn])]));
        b.appendChild(out);
        var grp = nid('pxg');
        function run() {
          setBusy(btn, true);
          clear(out);
          q({ view: 'accounts', service_id: sid, q: inp.value.trim(), state: 'not_linked', per_page: 20, page: 1 }).then(function (d) {
            var list = (d.items || []).filter(function (it) { return it.state !== 'linked'; });
            if (!list.length) { out.appendChild(emptyNode('연결할 수 있는 외부 계정이 없습니다.')); return; }
            list.forEach(function (it) {
              var rid = nid('pxr');
              var r = el('input', { type: 'radio', name: grp, id: rid });
              r.addEventListener('change', function () {
                picked = { external_id: it.external_id, username: it.username, email: it.email, row_version: it.row_version, admin_flag: isAdminExtra(it.extra) };
              });
              out.appendChild(el('label', { class: 'check al-radio', for: rid }, [r, el('span', null, [
                el('strong', null, nz(it.username)), text(' (' + it.external_id + ') · ' + nz(it.email) + ' · ' + nz(it.name) + ' · ' + lbl(STATE_LABEL, it.state)),
                isAdminExtra(it.extra) ? chip('시스템 관리자', 'pending') : null
              ])]));
            });
          }).catch(function (err) { out.appendChild(alertNode(errorMessage(err), 'danger')); })
            .then(function () { setBusy(btn, false); });
        }
        btn.addEventListener('click', run);
        inp.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); run(); } });
        run();
      },
      onConfirm: function () {
        if (!picked) { return Promise.reject(new Error('외부 계정을 하나 고르세요.')); }
        return { ext: picked };
      }
    }).then(function (r) { if (r && r.ext) { onPick(r.ext); } });
  }

  function reissueSetup(u, btn) {
    confirmModal('설정 링크 다시 만들기', u.username + ' 의 첫 비밀번호 설정 링크를 새로 만듭니다. 이전에 준 링크는 더 이상 쓸 수 없습니다.',
      { confirmText: '새로 만들기' }).then(function (ok) {
      if (!ok) { return; }
      setBusy(btn, true);
      ops({ action: 'setup_link', uuid: u.uuid }).then(function (d) {
        var note = '이 링크는 다시 볼 수 없습니다. ' + fmtDate(d.setup_expires_at) + ' 까지 유효합니다.'
          + (d.invalidated ? ' 이전 링크 ' + d.invalidated + '개를 무효로 했습니다.' : '');
        if (C.showOnceModal) { return C.showOnceModal('첫 비밀번호 설정 링크 · ' + u.username, '설정 링크', d.setup_url, note); }
        return alertModal('설정 링크', d.setup_url);
      }).catch(function (err) { toast(errorMessage(err), 'danger'); })
        .then(function () { setBusy(btn, false); });
    });
  }

  /* ---------- 이메일 무리 ---------- */
  function loadGroups() {
    S.groupsLoaded = true;
    showLoadingIn(S.grHost, '같은 이메일 묶음을 불러오는 중…');
    clear(S.grPager);
    return q({ view: 'groups', without_user: S.groups.without_user ? 1 : 0, page: S.groups.page, per_page: 20 })
      .then(function (d) { renderGroups(d); })
      .catch(function (err) { errBox(S.grHost, err, '같은 이메일 묶음을 불러올 수 없습니다'); });
  }

  function renderGroups(d) {
    var host = S.grHost;
    clear(host);
    pager(S.grPager, d, function (pg) { S.groups.page = pg; loadGroups(); });
    var groups = d.groups || [];
    S.grState = [];
    clear(S.grBar);
    if (!groups.length) { host.appendChild(emptyNode('묶을 미연결 외부 계정이 없습니다.', '이메일이 없는 외부 계정은 묶지 않습니다.')); return; }
    groups.forEach(function (g, gi) { host.appendChild(groupCard(g, gi)); });
    var all = writeBtn('체크한 묶음 모두 미리보기', 'btn-primary', function () { groupsPreview(S.grState.filter(function (x) { return x.picked().length; })); });
    all.id = 'al-groups-go';
    S.grBar.appendChild(all);
    S.grBar.appendChild(el('span', { class: 'text-xs text-muted' }, '묶음마다 새 계정 하나를 만듭니다. 같은 이메일의 통합로그인 계정이 이미 있으면 그 계정과 연결합니다.'));
  }

  function groupCard(g, gi) {
    var card = el('div', { class: 'al-group', dataset: { alGroup: String(gi) } });
    var head = el('div', { class: 'al-cell-head' }, [el('strong', { class: 'al-break' }, g.email)]);
    if (g.ulogin_user) {
      head.appendChild(el('span', { class: 'text-sm' }, [text('같은 이메일의 통합로그인 계정: '), personLink(g.ulogin_user.uuid, g.ulogin_user.username, g.email)]));
    } else {
      head.appendChild(plainChip('통합로그인 계정 없음'));
    }
    card.appendChild(head);
    var boxes = [];
    var bySvc = {};
    (g.accounts || []).forEach(function (a) {
      var id = nid('ga');
      var ck = el('input', { type: 'checkbox', id: id, disabled: !canWrite() });
      ck.addEventListener('change', function () {
        /* 한 사람 = 시스템마다 외부 계정 하나. 같은 시스템의 다른 칸은 끈다. */
        if (ck.checked) { (bySvc[a.service_id] || []).forEach(function (o) { if (o.ck !== ck) { o.ck.checked = false; } }); }
      });
      var rec = { a: a, ck: ck };
      boxes.push(rec);
      (bySvc[a.service_id] = bySvc[a.service_id] || []).push(rec);
      card.appendChild(el('label', { class: 'check al-radio', for: id }, [ck, el('span', null, [
        el('strong', null, sysName(a.service_id)), text(' · ' + nz(a.username) + ' (' + a.external_id + ')' + (a.name ? ' · ' + a.name : '')),
        a.state === 'unlinked' ? plainChip('해제됨') : null,
        a.admin_flag ? chip('시스템 관리자', 'pending', '새 계정은 일반 사용자로 만들어집니다. ' + lbl(CODE_LABEL, 'EXT_ROLE_ADMIN_LOW_RANK')) : null
      ])]));
    });
    var allBtn = el('button', { type: 'button', class: 'btn btn-ghost btn-sm', disabled: !canWrite() }, '시스템마다 하나씩 모두 체크');
    allBtn.addEventListener('click', function () { Object.keys(bySvc).forEach(function (k) { bySvc[k].forEach(function (o, i) { o.ck.checked = i === 0; }); }); });
    var one = writeBtn(g.ulogin_user ? '「' + g.ulogin_user.username + '」과 연결 미리보기' : '이 묶음으로 새 계정 미리보기', 'btn-secondary', function () {
      groupsPreview([st]);
    });
    card.appendChild(el('div', { class: 'btn-group' }, [allBtn, one]));
    var st = { g: g, picked: function () { return boxes.filter(function (b) { return b.ck.checked; }).map(function (b) { return b.a; }); } };
    S.grState.push(st);
    return card;
  }

  function groupsPreview(list) {
    var newUsers = [], items = [], meta = {};
    list.forEach(function (st, i) {
      var picked = st.picked();
      if (!picked.length) { return; }
      if (st.g.ulogin_user) {
        picked.forEach(function (a) {
          items.push({ op: 'link', service_id: a.service_id, external_id: a.external_id, expect_version: a.row_version, target: { uuid: st.g.ulogin_user.uuid } });
          meta[items.length] = { label: a.username || a.external_id, target: st.g.ulogin_user.username };
        });
        return;
      }
      var ref = 'g' + (i + 1);
      var first = picked[0];
      var uname = String(first.username || st.g.email.split('@')[0]).toLowerCase();
      newUsers.push({ ref: ref, username: uname, email: st.g.email, name: first.name || '' });
      picked.forEach(function (a, j) {
        items.push({ op: j === 0 ? 'create_link' : 'link', service_id: a.service_id, external_id: a.external_id, expect_version: a.row_version, target: { ref: ref } });
        meta[items.length] = { label: a.username || a.external_id, target: '새 계정 ' + uname, ref: ref };
      });
    });
    if (!items.length) { toast('묶음 안에서 연결할 칸을 체크하세요.', 'warn'); return; }
    preview({ new_users: newUsers, items: items }, meta);
  }

  /* ======================================================================
   * 5. 작업 기록 — view=jobs · view=job
   * ==================================================================== */
  function buildJobsPane(p) {
    var card = el('div', { class: 'card' });
    S.jobKind = sel(nid('jk'), 'kind', [['', '전체'], ['plan', '연결 작업'], ['push', '시스템 수신'], ['sync_request', '재수집 요청'], ['system', '자동 해제']], '');
    S.jobKind.addEventListener('change', function () { S.jobs.kind = S.jobKind.value; S.jobs.page = 1; loadJobs(); });
    card.appendChild(el('div', { class: 'card-head' }, [el('h2', null, '작업 기록'), el('span', { class: 'spacer' }),
      el('label', { for: S.jobKind.id, class: 'text-sm' }, '종류 '), S.jobKind]));
    var body = el('div', { class: 'card-body' });
    body.appendChild(el('p', { class: 'text-xs text-muted' }, '설정 링크는 작업 기록에 남지 않습니다. 감사 기록은 「로그 > 관리자 조작 로그」의 account_link.* 입니다.'));
    S.jobHost = el('div', { id: 'al-jobs-host' });
    S.jobPager = el('div');
    mount(body, [S.jobHost, S.jobPager]);
    card.appendChild(body);
    p.appendChild(card);
  }

  function loadJobs() {
    S.jobsLoaded = true;
    showLoadingIn(S.jobHost, '작업 기록을 불러오는 중…');
    clear(S.jobPager);
    return q({ view: 'jobs', kind: S.jobs.kind, page: S.jobs.page, per_page: 20 }).then(function (d) {
      clear(S.jobHost);
      pager(S.jobPager, d, function (pg) { S.jobs.page = pg; loadJobs(); });
      var items = d.items || [];
      if (!items.length) { S.jobHost.appendChild(emptyNode('작업 기록이 없습니다.')); return; }
      var t = tableNode(['작업', '종류', '상태', '시스템', '만든 사람', '만든 시각', '끝난 시각', { label: '항목', className: 'num' }, '완료 · 실패 · 건너뜀 · 대기', '']);
      items.forEach(function (j) {
        var tt = j.totals || {};
        var b = el('button', { type: 'button', class: 'btn btn-ghost btn-sm' }, '상세');
        b.addEventListener('click', function () { openJob(j.job_id); });
        t.tbody.appendChild(el('tr', null, [
          el('td', { class: 'mono', title: j.job_id }, short(j.job_id)),
          el('td', null, lbl(JOB_KIND_LABEL, j.kind)),
          el('td', null, chip(lbl(JOB_STATUS_LABEL, j.status), jobTone(j.status))),
          el('td', null, j.service_id ? sysName(j.service_id) : '-'),
          el('td', null, j.created_by === 'system' ? '시스템(자동)' : (j.created_by === null ? '시스템 수신' : nz(j.created_by))),
          el('td', { class: 'nowrap' }, fmtDate(j.created_at)),
          el('td', { class: 'nowrap', title: j.expires_at ? '계획 만료 ' + fmtDate(j.expires_at) : null }, fmtDate(j.finished_at)),
          el('td', { class: 'num' }, fmtNum(j.item_count)),
          el('td', { class: 'nowrap' }, fmtNum(tt.done) + ' · ' + fmtNum(tt.failed) + ' · ' + fmtNum(tt.skipped) + ' · ' + fmtNum(tt.planned)),
          el('td', null, b)
        ]));
      });
      S.jobHost.appendChild(t.wrap);
    }).catch(function (err) { errBox(S.jobHost, err, '작업 기록을 불러올 수 없습니다'); });
  }

  function jobTone(st) {
    return { done: 'active', partial: 'pending', running: 'pending', planned: 'pending', requested: 'pending', receiving: 'pending', expired: 'dormant', cancelled: 'dormant' }[st] || '';
  }

  function openJob(jobId) {
    q({ view: 'job', job_id: jobId }).then(function (d) {
      var j = d.job || {};
      var tt = j.totals || {};
      modal({
        title: '작업 ' + short(j.job_id) + ' · ' + lbl(JOB_KIND_LABEL, j.kind),
        large: true, hideCancel: true, confirmText: '닫기',
        body: function (b) {
          b.appendChild(el('div', { class: 'table-wrap' }, el('table', { class: 'kv' }, el('tbody', null, [
            ['상태', lbl(JOB_STATUS_LABEL, j.status)], ['시스템', j.service_id ? sysName(j.service_id) : '-'],
            ['만든 사람', j.created_by === 'system' ? '시스템(자동)' : nz(j.created_by)], ['만든 시각', fmtDate(j.created_at)],
            ['끝난 시각', fmtDate(j.finished_at)], ['계획 만료', fmtDate(j.expires_at)], ['항목 수', fmtNum(j.item_count)],
            ['완료 · 실패 · 건너뜀 · 대기', fmtNum(tt.done) + ' · ' + fmtNum(tt.failed) + ' · ' + fmtNum(tt.skipped) + ' · ' + fmtNum(tt.planned)]
          ].map(function (r) { return el('tr', null, [el('th', { scope: 'row' }, r[0]), el('td', null, r[1])]); })))));
          b.appendChild(alertNode('첫 비밀번호 설정 링크는 여기에 다시 나오지 않습니다. 필요하면 「사람 묶음」에서 다시 만드세요.', 'info'));
          var t = tableNode(['#', '동작', '시스템', '외부 ID', '판정', '결과', '사유', '새 계정 ref', '통합로그인 계정', '처리 시각']);
          (d.items || []).forEach(function (it) {
            t.tbody.appendChild(el('tr', null, [
              el('td', { class: 'num' }, String(it.seq)), el('td', null, lbl(OP_LABEL, it.op)), el('td', null, sysName(it.service_id)),
              el('td', { class: 'mono' }, it.external_id), el('td', null, chip(lbl(VERDICT_LABEL, it.verdict), VERDICT_TONE[it.verdict])),
              el('td', null, chip(lbl(RESULT_LABEL, it.status), RESULT_TONE[it.status])),
              el('td', null, it.error_code ? codesNode([it.error_code]) : '-'),
              el('td', { class: 'mono' }, nz(it.target_ref)),
              el('td', null, it.user ? it.user.username : '-'),
              el('td', { class: 'nowrap' }, fmtDate(it.done_at))
            ]));
          });
          b.appendChild(t.wrap);
          var row = el('div', { class: 'row' });
          if (j.kind === 'plan' && ((tt.failed | 0) + (tt.skipped | 0)) > 0 && ['done', 'partial'].indexOf(j.status) >= 0) {
            row.appendChild(writeBtn('실패·건너뜀 다시 계획', 'btn-primary', function () { closeTop(); preview({ retry_job_id: j.job_id }, {}); }));
          }
          if (j.kind === 'plan' && ['planned', 'running'].indexOf(j.status) >= 0) {
            row.appendChild(writeBtn('이 계획 취소', 'btn-danger', function () {
              ops({ action: 'cancel', plan_id: j.job_id }).then(function (r) {
                toast('계획을 ' + lbl(JOB_STATUS_LABEL, r.status) + '했습니다.', 'success'); closeTop(); loadJobs();
              }).catch(function (err) { toast(errorMessage(err), 'danger'); });
            }, '본인이 만든 계획만 취소할 수 있습니다'));
          }
          b.appendChild(row);
        }
      });
    }).catch(function (err) { alertModal('작업을 불러올 수 없습니다', errorMessage(err)); });
  }

  function closeTop() {
    var all = global.document.querySelectorAll('.modal-backdrop .modal-close');
    if (all.length) { all[all.length - 1].click(); }
  }

  global.UloginAdminLinks = { init: init };
})(window);
