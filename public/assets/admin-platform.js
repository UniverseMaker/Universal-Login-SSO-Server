/* ============================================================================
 * ulogin v1.9.0 — 관리자 콘솔 「플랫폼」 묶음 (admin-platform.js)
 *
 * 전역 설정 · 운영 로그 · 감사 기록 · 테이블 · 사용량 · 접근 권한(v1.10.0 행렬·그룹·서비스) 6탭과
 * 회원 상세의 「시스템별 허용」 표를 그린다.
 *   설계 : _workspace/116_architect_v190_platform.md §4·§5·§6·§7·§8
 *   키   : _workspace/117_backend_v190.md 「엔드포인트별 실제 응답 최상위 키」 표 — 이 파일은 그 이름만 읽는다
 *
 * 호출 (전부 api.js 의 apiGet / apiPost — fetch 직접 호출 없음)
 *   GET/POST /api/admin/platform_config   scope · diff / save_draft · delete_draft · publish · rollback
 *   GET/POST /api/admin/platform_logs     kind=logs|audit · format=jsonl / verify · purge_now · set_retention
 *   GET/POST /api/admin/platform_tables   service_id · collection · doc_id / upsert_collection · drop_collection · reindex · doc_set · doc_delete
 *   GET      /api/admin/platform_usage    days · service_id
 *   GET/POST /api/admin/service_access    service_id | uuid / set_rule · set_grant · set_features_registry · set_user_features
 *   GET      /api/admin/services          서비스 고르기 목록(기존 API)
 *
 * ▣ 로그·감사·문서 값은 다른 시스템이 보낸 문자열이다. innerHTML 을 쓰지 않는다 — 전부 ui.js el()/text().
 * ▣ 읽기 admin · 쓰기 superadmin(설계 §8). 화면 판정은 편의이고 실제 방어는 서버다.
 * ========================================================================== */
(function (global) {
  'use strict';

  var P_CONFIG = '/api/admin/platform_config';
  var P_LOGS = '/api/admin/platform_logs';
  var P_TABLES = '/api/admin/platform_tables';
  var P_USAGE = '/api/admin/platform_usage';
  var P_ACCESS = '/api/admin/service_access';
  var P_SERVICES = '/api/admin/services';

  /* ---------------------------------------------------------------- 라벨 */
  var LEVELS = ['debug', 'info', 'warn', 'error', 'fatal'];
  var LEVEL_LABEL = { debug: '디버그', info: '정보', warn: '경고', error: '오류', fatal: '치명' };
  var LEVEL_TONE = { warn: 'pending', error: 'suspended', fatal: 'suspended' };
  var RESULT_LABEL = { success: '성공', failure: '실패', denied: '거부' };
  var RESULT_TONE = { success: 'active', failure: 'suspended', denied: 'pending' };
  var ACTOR_LABEL = { user: '회원', admin: '관리자', system: '시스템', service: '서비스' };
  var SOURCE_LABEL = { s2s: '서버', user: '브라우저' };
  var RULE_LABEL = { all: '모든 회원', all_view: '모든 회원(보기만)', granted: '허용한 회원만', admin: '관리자만' };
  var GRANT_LABEL = { inherit: '규칙 따름', allow: '허용', deny: '차단' };
  var READ_RULE_LABEL = { service: '시스템 서버만', owner: '작성자', auth: '로그인 회원', 'public': '누구나' };
  var WRITE_RULE_LABEL = { service: '시스템 서버만', owner: '작성자' };
  var REASON_LABEL = {
    rule_all: '모든 회원 허용', granted_allow: '허용 지정', not_granted: '허용 지정 없음',
    admin_role: '관리자 역할', not_admin: '관리자 아님', denied_by_grant: '차단 지정',
    unknown_rule: '알 수 없는 규칙', service_disabled: '시스템 꺼짐', account_inactive: '계정 비활성',
    membership_blocked: '이용 제한', emergency_account: '비상 계정'
  };
  var METRIC_LABEL = {
    log_events: '로그 건수', log_bytes: '로그 용량(B)', audit_events: '감사 건수', cfg_reads: '설정 읽기',
    doc_reads: '문서 읽기', doc_writes: '문서 쓰기', denied: '접근 거절', sso_userinfo: '회원 조회',
    sso_users_subjects: '회원 목록 인원'
  };
  var METRICS = Object.keys(METRIC_LABEL);
  var ROLE_MIN = ['user', 'manager', 'admin', 'superadmin'];

  /* ---------------------------------------------------------------- 상태 */
  var C = null;               // init 컨텍스트 { me, canWrite, showError }
  var services = null;        // [{service_id, service_name, status}]
  var _uid = 0;

  /* ---------------------------------------------------------------- 공통 */
  function uid(p) { _uid += 1; return 'pf-' + (p || 'f') + '-' + _uid; }
  function has(o, k) { return o && Object.prototype.hasOwnProperty.call(o, k); }
  function lbl(map, v) { return has(map, v) ? map[v] : nz(v); }
  function nz(v) { return (v === null || v === undefined || v === '') ? '-' : String(v); }
  function cut(s, n) { var v = nz(s); return v.length > n ? v.slice(0, n) + '…' : v; }
  function canWrite() { return !!(C && C.canWrite); }
  var WRITE_REASON = '최고관리자만 바꿀 수 있습니다.';

  function chip(label, tone, title) {
    return el('span', { class: 'badge badge-status', dataset: { value: tone || '' }, title: title || null }, String(label));
  }
  function json(v, pretty) {
    try { return JSON.stringify(v, null, pretty ? 2 : 0); } catch (e) { return String(v); }
  }
  function pre(v) {
    return el('pre', { class: 'mono text-sm pf-pre' }, typeof v === 'string' ? v : json(v, true));
  }
  function showErr(host, err, title) {
    if (C && typeof C.showError === 'function') { C.showError(host, err, title); return; }
    clear(host);
    host.appendChild(alertNode(errorMessage(err), 'danger', title || '불러올 수 없습니다'));
  }
  function loading(host) { clear(host); host.appendChild(loadingNode()); }

  /** 카드. actions: 머리 오른쪽 노드 배열 */
  function card(title, actions) {
    var head = el('div', { class: 'card-head' }, [el('h2', null, title), el('span', { class: 'spacer' })]);
    (actions || []).forEach(function (a) { if (a) { head.appendChild(a); } });
    var body = el('div', { class: 'card-body' });
    return { box: el('div', { class: 'card' }, [head, body]), body: body, head: head };
  }
  /** 줄 없는 2열 키-값(v1.8.11 규칙 — table.kv) */
  function kv(rows) {
    var tb = el('tbody');
    rows.forEach(function (r) {
      if (!r) { return; }
      tb.appendChild(el('tr', null, [el('th', { scope: 'row' }, String(r[0])),
        el('td', null, (r[1] === null || r[1] === undefined) ? '-' : r[1])]));
    });
    return el('div', { class: 'table-wrap' }, el('table', { class: 'kv' }, tb));
  }
  function btn(label, cls, onClick, disabledReason) {
    var b = el('button', { type: 'button', class: 'btn btn-sm ' + (cls || 'btn-secondary') }, label);
    if (disabledReason) { b.disabled = true; b.title = disabledReason; }
    else { b.addEventListener('click', onClick); }
    return b;
  }
  function writeBtn(label, cls, onClick) { return btn(label, cls, onClick, canWrite() ? null : WRITE_REASON); }
  function field(label, input, hint) {
    var w = el('div', { class: 'field' }, [el('label', { for: input.id }, label), input, el('span', { class: 'field-error' })]);
    if (hint) { w.appendChild(el('span', { class: 'hint' }, hint)); }
    return w;
  }
  function input(name, value, attrs) {
    var a = { type: 'text', id: uid(name), name: name, value: value === null || value === undefined ? '' : String(value) };
    Object.keys(attrs || {}).forEach(function (k) { a[k] = attrs[k]; });
    return el('input', a);
  }
  function area(name, value, rows) {
    return el('textarea', { id: uid(name), name: name, rows: rows || 6, spellcheck: 'false', class: 'mono' },
      value === null || value === undefined ? '' : String(value));
  }
  function select(name, opts, value) {
    var s = el('select', { id: uid(name), name: name });
    opts.forEach(function (o) {
      var op = el('option', { value: o[0] }, o[1]);
      if (String(o[0]) === String(value === null || value === undefined ? '' : value)) { op.selected = true; }
      s.appendChild(op);
    });
    return s;
  }
  function fail(form, fieldName, message) {
    var e = new Error(message);
    e.field = fieldName;
    formErrors(form, { field: fieldName, message: message });
    throw e;
  }
  function parseJson(form, name, text, allowEmpty) {
    var t = String(text || '').trim();
    if (t === '' && allowEmpty) { return undefined; }
    try { return JSON.parse(t); } catch (e) { return fail(form, name, 'JSON 형식이 올바르지 않습니다.'); }
  }
  /** API 오류를 폼 옆에 표시하고 모달 오류로 다시 던진다 */
  function rethrow(form) {
    return function (err) { if (form) { formErrors(form, err); } throw err; };
  }
  function moreButton(onClick) { return el('div', { class: 'row end pf-more' }, btn('더 보기', 'btn-secondary', onClick)); }
  function dateQ(v, endOfDay) { return v ? (endOfDay ? v + 'T23:59:59Z' : v) : null; }

  /** 서비스 목록(1회 조회 · 캐시) */
  function loadServices() {
    if (services) { return Promise.resolve(services); }
    return apiGet(P_SERVICES, { page: 1, per_page: 100 }).then(function (d) {
      services = (d.items || []).map(function (s) { return { service_id: s.service_id, service_name: s.service_name, status: s.status }; });
      return services;
    });
  }
  function serviceSelect(name, allLabel, value) {
    var opts = allLabel !== null ? [['', allLabel]] : [];
    (services || []).forEach(function (s) {
      opts.push([s.service_id, s.service_name + ' (' + s.service_id + ')' + (s.status === 'disabled' ? ' · 꺼짐' : '')]);
    });
    return select(name, opts, value);
  }
  function serviceName(id) {
    var s = (services || []).filter(function (x) { return x.service_id === id; })[0];
    return s ? s.service_name : id;
  }

  /* ======================================================================
   * 1. 전역 설정 — platform_config (scope · drafts · published · versions · dirty)
   * ==================================================================== */
  function tabConfig(root) {
    var st = { scope: '*' };
    var bar = el('form', { class: 'toolbar', novalidate: true });
    var host = el('div');
    root.appendChild(bar);
    root.appendChild(host);
    loading(host);
    loadServices().then(function () {
      var opts = [['*', '전역(모든 시스템)']];
      services.forEach(function (s) { opts.push([s.service_id, s.service_name + ' (' + s.service_id + ')']); });
      var sel = select('scope', opts, st.scope);
      sel.addEventListener('change', function () { st.scope = sel.value; load(); });
      mount(bar, [field('범위', sel)]);
      load();
    }).catch(function (err) { showErr(host, err); });

    function load() {
      loading(host);
      apiGet(P_CONFIG, { scope: st.scope }).then(render).catch(function (err) { showErr(host, err); });
    }

    function render(d) {
      clear(host);
      var pub = d.published;
      var head = card('게시 상태', [
        writeBtn('게시', 'btn-primary', function () { publish(d); })
      ]);
      head.body.appendChild(kv([
        ['범위', d.scope === '*' ? '전역' : serviceName(d.scope) + ' (' + d.scope + ')'],
        ['게시 버전', pub ? 'v' + pub.version : '게시 전'],
        ['게시 시각', pub ? fmtDate(pub.published_at) : '-'],
        ['초안 변경', d.dirty ? chip('게시 안 됨', 'pending') : '없음'],
        ['ETag', pub ? el('span', { class: 'mono' }, cut(pub.etag, 16)) : '-']
      ]));
      host.appendChild(head.box);

      var dc = card('초안', [writeBtn('새 키', 'btn-primary', function () { editDraft(null); })]);
      var drafts = d.drafts || [];
      if (!drafts.length) {
        dc.body.appendChild(emptyNode('초안이 없습니다.'));
      } else {
        var t = tableNode(['키', '값', '조건', '설명', '수정', '']);
        drafts.forEach(function (r) {
          t.tbody.appendChild(el('tr', null, [
            el('td', { class: 'mono nowrap' }, r.key),
            el('td', { class: 'mono', title: json(r.value) }, cut(json(r.value), 60)),
            el('td', { class: 'num' }, String((r.conditions || []).length)),
            el('td', null, nz(r.description)),
            el('td', { class: 'nowrap' }, fmtDate(r.updated_at)),
            el('td', { class: 'nowrap' }, [
              writeBtn('편집', 'btn-secondary', function () { editDraft(r); }),
              text(' '),
              writeBtn('삭제', 'btn-danger', function () { deleteDraft(r); })
            ])
          ]));
        });
        dc.body.appendChild(t.wrap);
      }
      host.appendChild(dc.box);

      var vc = card('게시 이력');
      var vers = d.versions || [];
      if (!vers.length) {
        vc.body.appendChild(emptyNode('게시 이력이 없습니다.'));
      } else {
        var vt = tableNode([{ label: '버전', className: 'num' }, '종류', '메모', '게시자', '시각', '']);
        vers.forEach(function (v) {
          var kind = v.source === 'rollback' ? '되돌리기 (v' + nz(v.from_version) + ')' : '게시';
          vt.tbody.appendChild(el('tr', null, [
            el('td', { class: 'num' }, 'v' + v.version),
            el('td', { class: 'nowrap' }, kind),
            el('td', null, nz(v.note)),
            el('td', { class: 'mono' }, nz(v.published_by)),
            el('td', { class: 'nowrap' }, fmtDate(v.published_at)),
            el('td', { class: 'nowrap' }, [
              btn('차이', 'btn-secondary', function () { showDiff(v.version); }),
              text(' '),
              writeBtn('되돌리기', 'btn-secondary', function () { rollback(v.version); })
            ])
          ]));
        });
        vc.body.appendChild(vt.wrap);
      }
      host.appendChild(vc.box);
    }

    function editDraft(r) {
      var form = el('form', { novalidate: true });
      var k = input('key', r ? r.key : '', { maxlength: 64, spellcheck: 'false', class: 'mono' });
      if (r) { k.readOnly = true; }
      var v = area('value', r ? json(r.value, true) : '', 6);
      var c = area('conditions', r && r.conditions && r.conditions.length ? json(r.conditions, true) : '', 5);
      var ds = input('description', r ? r.description : '', { maxlength: 255 });
      mount(form, [
        field('키', k, '영문 소문자·숫자·밑줄·점'),
        field('값 (JSON)', v, '예: 5000 · "안내 문구" · {"on":true}. 비밀번호·키 같은 비밀값은 넣지 않습니다.'),
        field('조건 (JSON, 선택)', c, '예: [{"when":{"role_min":"admin"},"value":50000}] · 위에서부터 첫 일치'),
        field('설명', ds)
      ]);
      modal({
        title: r ? '초안 편집' : '새 키', large: true, confirmText: '저장', body: form,
        onConfirm: function () {
          clearFormErrors(form);
          var val = parseJson(form, 'value', v.value, false);
          var cond = parseJson(form, 'conditions', c.value, true);
          return apiPost(P_CONFIG, {
            action: 'save_draft', scope: st.scope, key: k.value.trim(), value: val,
            conditions: cond === undefined ? [] : cond, description: ds.value
          }).then(function (res) {
            toast('초안을 저장했습니다: ' + res.draft.key, 'success');
            load();
          }, rethrow(form));
        }
      });
    }

    function deleteDraft(r) {
      confirmModal('초안 삭제', '「' + r.key + '」 초안을 지웁니다. 게시된 값은 다음 게시 때 사라집니다.', {
        danger: true, confirmText: '삭제',
        onConfirm: function () {
          return apiPost(P_CONFIG, { action: 'delete_draft', scope: st.scope, key: r.key }).then(function () {
            toast('초안을 지웠습니다.', 'success');
            load();
          });
        }
      });
    }

    function publish(d) {
      var form = el('form', { novalidate: true });
      var note = input('note', '', { maxlength: 255 });
      mount(form, [el('p', null, '지금 초안 전체를 새 버전으로 게시합니다. 각 시스템은 다음 조회 때 새 값을 받습니다.'), field('메모', note)]);
      modal({
        title: '게시', confirmText: '게시', body: form,
        onConfirm: function () {
          return apiPost(P_CONFIG, {
            action: 'publish', scope: st.scope, expected_version: d.published ? d.published.version : 0, note: note.value
          }).then(function (res) {
            toast('v' + res.published.version + ' 게시를 마쳤습니다.', 'success');
            load();
          }, rethrow(form));
        }
      });
    }

    function rollback(ver) {
      var form = el('form', { novalidate: true });
      var note = input('note', '', { maxlength: 255 });
      mount(form, [el('p', null, 'v' + ver + ' 내용을 새 버전으로 다시 게시하고 초안도 그 내용으로 바꿉니다.'), field('메모', note)]);
      modal({
        title: 'v' + ver + '로 되돌리기', confirmText: '되돌리기', danger: true, body: form,
        onConfirm: function () {
          return apiPost(P_CONFIG, { action: 'rollback', scope: st.scope, to_version: ver, note: note.value }).then(function (res) {
            toast('v' + res.published.version + '로 게시했습니다.', 'success');
            load();
          }, rethrow(form));
        }
      });
    }

    function showDiff(ver) {
      var box = el('div');
      box.appendChild(loadingNode());
      modal({ title: 'v' + ver + ' → 지금 초안', large: true, hideCancel: true, confirmText: '닫기', body: box });
      apiGet(P_CONFIG, { scope: st.scope, diff: ver }).then(function (d) {
        clear(box);
        var t = tableNode(['구분', '키', '이전', '지금']);
        (d.added || []).forEach(function (k) {
          t.tbody.appendChild(el('tr', null, [el('td', null, chip('추가', 'active')), el('td', { class: 'mono' }, typeof k === 'string' ? k : json(k)), el('td', null, '-'), el('td', null, '-')]));
        });
        (d.removed || []).forEach(function (k) {
          t.tbody.appendChild(el('tr', null, [el('td', null, chip('삭제', 'suspended')), el('td', { class: 'mono' }, typeof k === 'string' ? k : json(k)), el('td', null, '-'), el('td', null, '-')]));
        });
        (d.changed || []).forEach(function (c) {
          t.tbody.appendChild(el('tr', null, [el('td', null, chip('변경', 'pending')), el('td', { class: 'mono' }, c.key),
            el('td', { class: 'mono' }, cut(json(c.before), 80)), el('td', { class: 'mono' }, cut(json(c.after), 80))]));
        });
        if (!t.tbody.firstChild) { box.appendChild(emptyNode('차이가 없습니다.')); } else { box.appendChild(t.wrap); }
      }).catch(function (err) { showErr(box, err); });
    }
  }

  /* ======================================================================
   * 2·3. 운영 로그 · 감사 기록 — platform_logs (items · next_cursor)
   * ==================================================================== */
  function tabLogs(root, kind) {
    var isAudit = kind === 'audit';
    var st = { cursor: null, query: null };
    var form = el('form', { class: 'toolbar', novalidate: true });
    var listHost = el('div');
    var moreHost = el('div');
    var res = card(isAudit ? '감사 기록' : '운영 로그');
    mount(res.body, [form, listHost, moreHost]);
    root.appendChild(res.box);
    var side = el('div');
    root.appendChild(side);
    loading(listHost);

    loadServices().then(function () {
      var f = {
        service_id: serviceSelect('service_id', '전체', ''),
        event: input('event', '', { maxlength: 64, spellcheck: 'false' }),
        uuid: input('uuid', '', { maxlength: 36, spellcheck: 'false', placeholder: '8-4-4-4-12' }),
        request_id: input('request_id', '', { maxlength: 64, spellcheck: 'false' }),
        q: input('q', '', { maxlength: 100, type: 'search' }),
        from: input('from', '', { type: 'date' }),
        to: input('to', '', { type: 'date' })
      };
      var nodes = [field('서비스', f.service_id)];
      if (!isAudit) {
        f.level_min = select('level_min', [['', '전체']].concat(LEVELS.map(function (l) { return [l, LEVEL_LABEL[l] + ' 이상']; })), '');
        f.tag = input('tag', '', { maxlength: 32, spellcheck: 'false' });
        nodes.push(field('레벨', f.level_min));
      }
      nodes.push(field(isAudit ? '조작' : '이벤트', f.event));
      if (!isAudit) { nodes.push(field('태그', f.tag)); }
      nodes.push(field(isAudit ? '행위자 UUID' : '회원 UUID', f.uuid), field('요청 ID', f.request_id),
        field('검색어', f.q, '서비스를 고르거나 7일 이내일 때'), field('시작일 (UTC)', f.from), field('종료일 (UTC)', f.to));
      var submit = el('button', { type: 'submit', class: 'btn btn-primary btn-sm' }, '조회');
      nodes.push(el('div', { class: 'field pf-actions' }, submit));
      mount(form, nodes);
      form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        clearFormErrors(form);
        var q = { kind: kind, limit: 50 };
        Object.keys(f).forEach(function (k) { if (f[k].value) { q[k] = f[k].value; } });
        q.from = dateQ(f.from.value, false);
        q.to = dateQ(f.to.value, true);
        st.query = q;
        st.cursor = null;
        clear(listHost);
        fetchPage(false);
      });
      form.dispatchEvent(new Event('submit', { cancelable: true }));
      renderSide();
    }).catch(function (err) { showErr(listHost, err); });

    function fetchPage(append) {
      var q = Object.assign({}, st.query);
      if (st.cursor) { q.cursor = st.cursor; }
      if (!append) { loading(listHost); }
      clear(moreHost);
      apiGet(P_LOGS, q).then(function (d) {
        if (!append) { clear(listHost); }
        var items = d.items || [];
        if (!items.length && !append) { listHost.appendChild(emptyNode('조건에 맞는 기록이 없습니다.')); return; }
        listHost.appendChild(isAudit ? auditTable(items) : logTable(items));
        st.cursor = d.next_cursor || null;
        if (st.cursor) { moreHost.appendChild(moreButton(function () { fetchPage(true); })); }
      }).catch(function (err) {
        if (err && err.field) { formErrors(form, err); }
        showErr(append ? moreHost : listHost, err);
      });
    }

    function logTable(items) {
      var t = tableNode(['시각', '서비스', '레벨', '이벤트', '메시지', '태그', '회원', '출처']);
      items.forEach(function (r) {
        var tr = el('tr', { class: 'pf-click', tabindex: '0', title: '자세히' }, [
          el('td', { class: 'nowrap' }, fmtDate(r.ts)),
          el('td', { class: 'mono nowrap' }, r.service_id),
          el('td', null, chip(lbl(LEVEL_LABEL, r.level), LEVEL_TONE[r.level])),
          el('td', { class: 'mono nowrap' }, r.event),
          el('td', null, cut(r.message, 80)),
          el('td', { class: 'mono' }, (r.tags || []).join(', ') || '-'),
          el('td', { class: 'mono nowrap', title: nz(r.uuid) }, r.local_user ? r.local_user : cut(r.uuid, 10)),
          el('td', { class: 'nowrap' }, lbl(SOURCE_LABEL, r.source))
        ]);
        bindOpen(tr, function () { logDetail(r); });
        t.tbody.appendChild(tr);
      });
      return t.wrap;
    }

    function auditTable(items) {
      var t = tableNode([{ label: '순번', className: 'num' }, '시각', '서비스', '행위자', '조작', '대상', '결과', 'IP']);
      items.forEach(function (r) {
        var a = r.actor || {};
        var tg = r.target || {};
        var tr = el('tr', { class: 'pf-click', tabindex: '0', title: '자세히' }, [
          el('td', { class: 'num' }, String(r.seq)),
          el('td', { class: 'nowrap' }, fmtDate(r.ts)),
          el('td', { class: 'mono nowrap' }, r.service_id),
          el('td', { class: 'nowrap' }, lbl(ACTOR_LABEL, a.type) + ' · ' + (a.local_user || cut(a.uuid, 10))),
          el('td', { class: 'mono nowrap' }, r.action),
          el('td', { class: 'mono' }, nz(tg.type) + (tg.id ? ' · ' + cut(tg.id, 24) : '')),
          el('td', null, chip(lbl(RESULT_LABEL, r.result), RESULT_TONE[r.result])),
          el('td', { class: 'mono nowrap' }, nz(r.ip))
        ]);
        bindOpen(tr, function () { auditDetail(r); });
        t.tbody.appendChild(tr);
      });
      return t.wrap;
    }

    function renderSide() {
      clear(side);
      if (isAudit) {
        var ch = card('체인 검사', [btn('검사', 'btn-primary', function (ev) { verify(ev.target, 1); }),
          writeBtn('내보내기', 'btn-secondary', exportAudit)]);
        ch.body.appendChild(el('p', { class: 'hint' }, '기록이 바뀌거나 빠졌는지 해시로 확인합니다. 한 번에 5,000건씩 봅니다.'));
        var out = el('div', { class: 'pf-verify' });
        ch.body.appendChild(out);
        side.appendChild(ch.box);
        side._verifyOut = out;
        return;
      }
      var rc = card('보존·정리');
      var f2 = el('form', { class: 'toolbar', novalidate: true });
      var ld = input('logs_days', '', { type: 'number', min: 1, max: 365, inputmode: 'numeric' });
      var ad = input('audit_days', '', { type: 'number', min: 90, max: 3650, inputmode: 'numeric' });
      var sv = writeBtn('보존 기간 저장', 'btn-primary', function () {
        clearFormErrors(f2);
        apiPost(P_LOGS, { action: 'set_retention', logs_days: Number(ld.value), audit_days: Number(ad.value) }).then(function (d) {
          ld.value = d.logs_days; ad.value = d.audit_days;
          toast('보존 기간: 로그 ' + d.logs_days + '일 · 감사 ' + d.audit_days + '일', 'success');
        }, function (err) { formErrors(f2, err); toast(errorMessage(err), 'danger'); });
      });
      mount(f2, [field('로그 보존(일)', ld, '1~365'), field('감사 보존(일)', ad, '90~3650'), el('div', { class: 'field pf-actions' }, sv)]);
      var f3 = el('form', { class: 'toolbar', novalidate: true });
      var before = input('before', '', { type: 'date' });
      var pg = writeBtn('지금 정리', 'btn-danger', function () {
        clearFormErrors(f3);
        if (!before.value) { formErrors(f3, { field: 'before', message: '날짜를 고르세요.' }); return; }
        confirmModal('운영 로그 정리', before.value + ' 이전에 받은 운영 로그를 지웁니다(한 번에 2,000건). 되돌릴 수 없습니다.', {
          danger: true, confirmText: '정리',
          onConfirm: function () {
            return apiPost(P_LOGS, { action: 'purge_now', kind: 'logs', before: before.value }).then(function (d) {
              toast(fmtNum(d.deleted) + '건을 지웠습니다.', 'success');
            });
          }
        });
      });
      mount(f3, [field('이전 날짜 (UTC)', before), el('div', { class: 'field pf-actions' }, pg)]);
      mount(rc.body, [f2, f3]);
      side.appendChild(rc.box);
    }

    function verify(b, fromSeq) {
      var out = side._verifyOut;
      setBusy(b, true);
      apiPost(P_LOGS, { action: 'verify', from_seq: fromSeq }).then(function (d) {
        setBusy(b, false);
        clear(out);
        out.appendChild(kv([
          ['판정', d.ok ? chip('정상', 'active') : chip('불일치', 'suspended')],
          ['검사 건수', fmtNum(d.checked)],
          ['처음 어긋난 순번', d.first_bad_seq === null ? '-' : String(d.first_bad_seq)],
          ['마지막 순번', nz(d.last_seq)]
        ]));
        if (d.ok && d.checked >= 5000 && d.last_seq) {
          out.appendChild(el('div', { class: 'row' }, btn('이어서 검사', 'btn-secondary', function (ev) { verify(ev.target, Number(d.last_seq) + 1); })));
        }
      }).catch(function (err) { setBusy(b, false); showErr(out, err); });
    }

    function exportAudit() {
      var q = Object.assign({}, st.query || { kind: 'audit' });
      delete q.limit;
      q.format = 'jsonl';
      var a = el('a', { href: buildUrl(P_LOGS, q), download: '' });
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      toast('지금 조건으로 최대 20,000건을 내려받습니다.', 'info');
    }
  }

  function bindOpen(tr, fn) {
    tr.addEventListener('click', fn);
    tr.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); fn(); } });
  }

  function logDetail(r) {
    modal({
      title: '운영 로그', large: true, hideCancel: true, confirmText: '닫기',
      body: [kv([
        ['시각', fmtDate(r.ts)], ['받은 시각', fmtDate(r.received_at)], ['서비스', el('span', { class: 'mono' }, r.service_id)],
        ['레벨', chip(lbl(LEVEL_LABEL, r.level), LEVEL_TONE[r.level])], ['이벤트', el('span', { class: 'mono' }, r.event)],
        ['메시지', el('span', { class: 'pf-wrap' }, nz(r.message))], ['태그', (r.tags || []).join(', ') || '-'],
        ['회원 UUID', el('span', { class: 'mono' }, nz(r.uuid))], ['시스템 아이디', nz(r.local_user)],
        ['요청 ID', el('span', { class: 'mono' }, nz(r.request_id))], ['출처', lbl(SOURCE_LABEL, r.source)],
        ['보낸 IP', el('span', { class: 'mono' }, nz(r.sender_ip))], ['사건 ID', el('span', { class: 'mono' }, r.event_id)]
      ]), el('h4', null, '데이터'), r.data === null || r.data === undefined ? el('p', { class: 'text-muted' }, '-') : pre(r.data)]
    });
  }

  function auditDetail(r) {
    var a = r.actor || {};
    var tg = r.target || {};
    modal({
      title: '감사 기록 #' + r.seq, large: true, hideCancel: true, confirmText: '닫기',
      body: [kv([
        ['시각', fmtDate(r.ts)], ['받은 시각', fmtDate(r.received_at)], ['서비스', el('span', { class: 'mono' }, r.service_id)],
        ['행위자', lbl(ACTOR_LABEL, a.type)], ['행위자 UUID', el('span', { class: 'mono' }, nz(a.uuid))], ['행위자 아이디', nz(a.local_user)],
        ['조작', el('span', { class: 'mono' }, r.action)], ['대상', el('span', { class: 'mono' }, nz(tg.type) + ' · ' + nz(tg.id))],
        ['결과', chip(lbl(RESULT_LABEL, r.result), RESULT_TONE[r.result])], ['사유', nz(r.reason)],
        ['IP', el('span', { class: 'mono' }, nz(r.ip))], ['브라우저', el('span', { class: 'pf-wrap' }, nz(r.user_agent))],
        ['요청 ID', el('span', { class: 'mono' }, nz(r.request_id))], ['보낸 IP', el('span', { class: 'mono' }, nz(r.sender_ip))],
        ['사건 ID', el('span', { class: 'mono' }, r.event_id)]
      ]), el('h4', null, '바뀐 내용'), r.changes === null || r.changes === undefined ? el('p', { class: 'text-muted' }, '-') : pre(r.changes)]
    });
  }

  /* ======================================================================
   * 4. 테이블 — platform_tables (items · collection · doc · next_cursor · dropped …)
   * ==================================================================== */
  function tabTables(root) {
    var st = { sid: '', coll: null, cursor: null };
    var bar = el('form', { class: 'toolbar', novalidate: true });
    var collHost = el('div');
    var docHost = el('div');
    mount(root, [bar, collHost, docHost]);
    loading(collHost);
    loadServices().then(function () {
      var sel = serviceSelect('service_id', '서비스를 고르세요', '');
      sel.addEventListener('change', function () { st.sid = sel.value; st.coll = null; clear(docHost); loadColls(); });
      mount(bar, [field('서비스', sel)]);
      clear(collHost);
      collHost.appendChild(emptyNode('서비스를 고르면 컬렉션이 보입니다.'));
    }).catch(function (err) { showErr(collHost, err); });

    function loadColls() {
      if (!st.sid) { clear(collHost); return; }
      loading(collHost);
      apiGet(P_TABLES, { service_id: st.sid }).then(renderColls).catch(function (err) { showErr(collHost, err); });
    }

    function renderColls(d) {
      clear(collHost);
      var c = card('컬렉션', [writeBtn('새 컬렉션', 'btn-primary', function () { editColl(null); })]);
      var items = d.items || [];
      if (!items.length) {
        c.body.appendChild(emptyNode('컬렉션이 없습니다.'));
      } else {
        var t = tableNode(['이름', '읽기', '쓰기', '색인', { label: '문서', className: 'num' }, '상태', '수정', '']);
        items.forEach(function (x) {
          var idx = (x.indexes || []).map(function (i) { return i.field + ':' + i.type; }).join(', ') || '-';
          var stt = x.status === 'disabled' ? chip('삭제 중', 'withdrawn') : (x.reindex_pending ? chip('색인 대기', 'pending') : chip('사용 중', 'active'));
          t.tbody.appendChild(el('tr', null, [
            el('td', { class: 'mono nowrap' }, x.name),
            el('td', { class: 'nowrap' }, lbl(READ_RULE_LABEL, x.read_rule)),
            el('td', { class: 'nowrap' }, lbl(WRITE_RULE_LABEL, x.write_rule)),
            el('td', { class: 'mono' }, idx),
            el('td', { class: 'num nowrap' }, fmtNum(x.doc_count) + ' / ' + fmtNum(x.max_docs)),
            el('td', null, stt),
            el('td', { class: 'nowrap' }, fmtDate(x.updated_at)),
            el('td', { class: 'nowrap' }, [
              btn('문서', 'btn-secondary', function () { st.coll = x.name; st.cursor = null; loadDocs(false); }),
              text(' '), writeBtn('규칙', 'btn-secondary', function () { editColl(x); }),
              x.reindex_pending ? text(' ') : null,
              x.reindex_pending ? writeBtn('색인 만들기', 'btn-secondary', function (ev) { reindex(ev.target, x.name, 0, 0); }) : null,
              text(' '), writeBtn('삭제', 'btn-danger', function () { dropColl(x); })
            ])
          ]));
        });
        c.body.appendChild(t.wrap);
      }
      collHost.appendChild(c.box);
    }

    function editColl(x) {
      var form = el('form', { novalidate: true });
      var nm = input('name', x ? x.name : '', { maxlength: 64, spellcheck: 'false', class: 'mono' });
      if (x) { nm.readOnly = true; }
      var rr = select('read_rule', Object.keys(READ_RULE_LABEL).map(function (k) { return [k, READ_RULE_LABEL[k]]; }), x ? x.read_rule : 'owner');
      var wr = select('write_rule', Object.keys(WRITE_RULE_LABEL).map(function (k) { return [k, WRITE_RULE_LABEL[k]]; }), x ? x.write_rule : 'owner');
      var ix = area('indexes', x && x.indexes && x.indexes.length ? json(x.indexes, true) : '', 4);
      var md = input('max_docs', x ? x.max_docs : 5000, { type: 'number', min: 1, max: 50000 });
      var mb = input('max_doc_bytes', x ? x.max_doc_bytes : 16384, { type: 'number', min: 256, max: 262144 });
      mount(form, [
        field('이름', nm, '영문 소문자로 시작 · 소문자·숫자·밑줄'),
        el('div', { class: 'grid-2' }, [field('읽기', rr), field('쓰기', wr)]),
        field('색인 (JSON, 최대 3개)', ix, '예: [{"field":"status","type":"str"},{"field":"due","type":"int"}]'),
        el('div', { class: 'grid-2' }, [field('문서 수 상한', md), field('문서 크기 상한(B)', mb)])
      ]);
      modal({
        title: x ? '규칙 · ' + x.name : '새 컬렉션', large: true, confirmText: '저장', body: form,
        onConfirm: function () {
          clearFormErrors(form);
          var idx = parseJson(form, 'indexes', ix.value, true);
          return apiPost(P_TABLES, {
            action: 'upsert_collection', service_id: st.sid, name: nm.value.trim(), read_rule: rr.value, write_rule: wr.value,
            indexes: idx === undefined ? [] : idx, max_docs: Number(md.value), max_doc_bytes: Number(mb.value)
          }).then(function (d) {
            toast('컬렉션 「' + d.collection.name + '」을 저장했습니다.' + (d.collection.reindex_pending ? ' 색인을 다시 만드세요.' : ''), 'success');
            loadColls();
          }, rethrow(form));
        }
      });
    }

    function reindex(b, name, after, total) {
      setBusy(b, true);
      apiPost(P_TABLES, { action: 'reindex', service_id: st.sid, collection: name, after: after }).then(function (d) {
        var sum = total + (d.processed || 0);
        if (!d.done) { setBusy(b, false); reindex(b, name, d.next_after, sum); return; }
        setBusy(b, false);
        toast('색인을 다시 만들었습니다(' + fmtNum(sum) + '건).', 'success');
        loadColls();
      }).catch(function (err) { setBusy(b, false); toast(errorMessage(err), 'danger'); });
    }

    function dropColl(x) {
      confirmModal('컬렉션 삭제', '「' + x.name + '」과 문서 ' + fmtNum(x.doc_count) + '건을 지웁니다. 되돌릴 수 없습니다.', {
        danger: true, confirmText: '삭제', confirmPhrase: x.name,
        onConfirm: function () {
          return apiPost(P_TABLES, { action: 'drop_collection', service_id: st.sid, collection: x.name, confirm: x.name }).then(function (d) {
            toast(d.dropped ? '컬렉션을 지웠습니다.' : fmtNum(d.deleted) + '건을 지웠습니다. 남은 ' + fmtNum(d.remaining) + '건은 삭제를 다시 누르세요.',
              d.dropped ? 'success' : 'warn');
            if (st.coll === x.name) { st.coll = null; clear(docHost); }
            loadColls();
          });
        }
      });
    }

    function loadDocs(append) {
      if (!append) { clear(docHost); docHost.appendChild(loadingNode()); }
      var q = { service_id: st.sid, collection: st.coll };
      if (append && st.cursor) { q.cursor = st.cursor; }
      apiGet(P_TABLES, q).then(function (d) {
        var c;
        if (!append) {
          clear(docHost);
          c = card('문서 · ' + d.collection.name);
          c.body.appendChild(el('div', { class: 'pf-docs' }));
          c.body.appendChild(el('div', { class: 'pf-docs-more' }));
          docHost.appendChild(c.box);
        }
        var list = docHost.querySelector('.pf-docs');
        var more = docHost.querySelector('.pf-docs-more');
        clear(more);
        var items = d.items || [];
        if (!items.length && !append) { list.appendChild(emptyNode('문서가 없습니다.')); return; }
        var t = tableNode(['문서 ID', { label: '버전', className: 'num' }, '작성자 UUID', '내용', '수정']);
        items.forEach(function (r) {
          var tr = el('tr', { class: 'pf-click', tabindex: '0', title: '자세히' }, [
            el('td', { class: 'mono nowrap' }, cut(r.doc_id, 40)),
            el('td', { class: 'num' }, String(r.version)),
            el('td', { class: 'mono nowrap', title: nz(r.owner_uuid) }, cut(r.owner_uuid, 10)),
            el('td', { class: 'mono' }, cut(r.preview, 100) + (r.truncated ? ' (일부)' : '')),
            el('td', { class: 'nowrap' }, fmtDate(r.updated_at))
          ]);
          bindOpen(tr, function () { openDoc(r.doc_id); });
          t.tbody.appendChild(tr);
        });
        list.appendChild(t.wrap);
        st.cursor = d.next_cursor || null;
        if (st.cursor) { more.appendChild(moreButton(function () { loadDocs(true); })); }
      }).catch(function (err) { showErr(docHost, err); });
    }

    function openDoc(docId) {
      var box = el('div');
      box.appendChild(loadingNode());
      var cur = null;
      var dataArea = null;
      var form = el('form', { novalidate: true });
      modal({
        title: '문서', large: true, confirmText: canWrite() ? '저장' : '닫기', hideCancel: !canWrite(), body: [box, form],
        onConfirm: function () {
          if (!canWrite() || !cur) { return true; }
          clearFormErrors(form);
          var data = parseJson(form, 'data', dataArea ? dataArea.value : '', false);
          return apiPost(P_TABLES, {
            action: 'doc_set', service_id: st.sid, collection: st.coll, doc_id: cur.doc_id, data: data, expected_version: cur.version
          }).then(function (d) {
            toast('v' + d.doc.version + '로 저장했습니다.', 'success');
            loadDocs(false);
          }, rethrow(form));
        }
      });
      apiGet(P_TABLES, { service_id: st.sid, collection: st.coll, doc_id: docId }).then(function (d) {
        cur = d.doc;
        clear(box);
        box.appendChild(kv([
          ['문서 ID', el('span', { class: 'mono' }, cur.doc_id)], ['버전', String(cur.version)],
          ['작성자 UUID', el('span', { class: 'mono' }, nz(cur.owner_uuid))],
          ['만든 시각', fmtDate(cur.created_at)], ['수정 시각', fmtDate(cur.updated_at)]
        ]));
        if (canWrite()) {
          dataArea = area('data', json(cur.data, true), 14);
          mount(form, [field('내용 (JSON)', dataArea), el('div', { class: 'row' },
            btn('문서 삭제', 'btn-danger', function () { deleteDoc(cur); }))]);
        } else {
          form.appendChild(pre(cur.data));
        }
      }).catch(function (err) { showErr(box, err); });
    }

    function deleteDoc(doc) {
      confirmModal('문서 삭제', '문서 「' + doc.doc_id + '」를 지웁니다. 되돌릴 수 없습니다.', {
        danger: true, confirmText: '삭제',
        onConfirm: function () {
          return apiPost(P_TABLES, { action: 'doc_delete', service_id: st.sid, collection: st.coll, doc_id: doc.doc_id, expected_version: doc.version })
            .then(function () { toast('문서를 지웠습니다.', 'success'); loadDocs(false); loadColls(); });
        }
      });
    }
  }

  /* ======================================================================
   * 5. 사용량 — platform_usage (items · totals)
   * ==================================================================== */
  function tabUsage(root) {
    var bar = el('form', { class: 'toolbar', novalidate: true });
    var host = el('div');
    mount(root, [bar, host]);
    loading(host);
    loadServices().then(function () {
      var days = select('days', [['7', '7일'], ['30', '30일'], ['90', '90일']], '30');
      var sid = serviceSelect('service_id', '전체', '');
      mount(bar, [field('기간', days), field('서비스', sid)]);
      function load() {
        loading(host);
        apiGet(P_USAGE, { days: days.value, service_id: sid.value || null }).then(render).catch(function (err) { showErr(host, err); });
      }
      days.addEventListener('change', load);
      sid.addEventListener('change', load);
      load();
    }).catch(function (err) { showErr(host, err); });

    function render(d) {
      clear(host);
      var tot = d.totals || {};
      var grid = el('div', { class: 'stat-grid' });
      METRICS.forEach(function (m) { grid.appendChild(statNode(METRIC_LABEL[m], fmtNum(tot[m] || 0))); });
      var c1 = card('합계');
      c1.body.appendChild(grid);
      host.appendChild(c1.box);
      var rows = {};
      var order = [];
      (d.items || []).forEach(function (r) {
        var k = r.day + '|' + r.service_id;
        if (!rows[k]) { rows[k] = { day: r.day, service_id: r.service_id, m: {} }; order.push(k); }
        rows[k].m[r.metric] = r.value;
      });
      var c2 = card('일별');
      if (!order.length) {
        c2.body.appendChild(emptyNode('기록이 없습니다.'));
      } else {
        var t = tableNode(['날짜 (UTC)', '서비스'].concat(METRICS.map(function (m) { return { label: METRIC_LABEL[m], className: 'num' }; })));
        order.forEach(function (k) {
          var r = rows[k];
          var tds = [el('td', { class: 'nowrap' }, r.day), el('td', { class: 'mono nowrap' }, r.service_id)];
          METRICS.forEach(function (m) { tds.push(el('td', { class: 'num' }, has(r.m, m) ? fmtNum(r.m[m]) : '-')); });
          t.tbody.appendChild(el('tr', null, tds));
        });
        c2.body.appendChild(t.wrap);
      }
      host.appendChild(c2.box);
    }
  }

  /* ======================================================================
   * 6. 접근 권한 (v1.10.0) — 행렬 · 그룹 · 서비스 세 보기
   *   GET  /api/admin/access_matrix   page per_page q group_key service_id
   *   GET/POST /api/admin/access_groups   group_key page / create update delete add_members remove_members set_service
   *   GET/POST /api/admin/service_access  service_id | uuid / set_rule(level_aware) set_override revoke set_features_registry set_user_features set_grant
   *   셀 값(level·source·until·override)은 서버가 판정한 값이다. 화면은 다시 계산하지 않는다.
   * ==================================================================== */
  var P_MATRIX = '/api/admin/access_matrix';
  var P_GROUPS = '/api/admin/access_groups';
  var LEVEL_LABEL = { none: '없음', view: '보기', use: '사용', admin: '관리' };
  var LEVEL_PICK = [['view', '보기'], ['use', '사용'], ['admin', '관리']];
  var SOURCE_LABEL = { rule: '규칙', group: '그룹', override: '덮어쓰기' };
  var GROUP_STATUS_LABEL = { active: '사용', disabled: '꺼짐' };

  /** ISO(UTC) → datetime-local 값(브라우저 시간대). 빈 값은 '' */
  function toLocalInput(iso) {
    if (!iso) { return ''; }
    var d = new Date(iso);
    if (isNaN(d.getTime())) { return ''; }
    function p(n) { return (n < 10 ? '0' : '') + n; }
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T' + p(d.getHours()) + ':' + p(d.getMinutes());
  }
  /** datetime-local 값 → ISO UTC(초 단위). 빈 값은 null */
  function fromLocalInput(v) {
    if (!v) { return null; }
    var d = new Date(v);
    if (isNaN(d.getTime())) { return null; }
    return d.toISOString().replace(/\.\d{3}Z$/, 'Z');
  }
  function periodText(s, e) {
    if (!s && !e) { return '무기한'; }
    return (s ? fmtDate(s) : '') + ' ~ ' + (e ? fmtDate(e) : '');
  }
  function srcKind(src) {
    var v = String(src === null || src === undefined ? '' : src);
    if (v.indexOf('group:') === 0) { return { kind: 'group', key: v.slice(6) }; }
    if (v === 'override') { return { kind: 'override', key: '' }; }
    if (v === 'rule') { return { kind: 'rule', key: '' }; }
    return { kind: '', key: v };
  }
  function srcText(src) {
    var k = srcKind(src);
    if (k.kind === 'group') { return '그룹 ' + k.key; }
    if (k.kind) { return SOURCE_LABEL[k.kind]; }
    return lbl(REASON_LABEL, k.key);
  }
  function levelChip(level) {
    var tone = level === 'none' ? 'dormant' : (level === 'view' ? 'pending' : 'active');
    return chip(lbl(LEVEL_LABEL, level), tone);
  }
  function groupSelect(name, groups, value, allLabel) {
    var opts = [['', allLabel]];
    (groups || []).forEach(function (g) { opts.push([g.group_key, g.name + ' (' + g.group_key + ')']); });
    return select(name, opts, value);
  }
  function checkField(name, label, checked, hint) {
    var id = uid(name);
    var cb = el('input', { type: 'checkbox', id: id, name: name });
    cb.checked = !!checked;
    var w = el('div', { class: 'field' }, el('label', { class: 'check', for: id }, [cb, el('span', null, label)]));
    if (hint) { w.appendChild(el('span', { class: 'hint' }, hint)); }
    return { box: w, input: cb };
  }
  function periodFields(s, e) {
    var a = input('starts_at', toLocalInput(s), { type: 'datetime-local' });
    var b = input('ends_at', toLocalInput(e), { type: 'datetime-local' });
    return { start: a, end: b, nodes: [field('시작', a, '비우면 바로'), field('끝', b, '비우면 무기한')] };
  }
  function checkPeriod(form, pf) {
    var s = fromLocalInput(pf.start.value), e = fromLocalInput(pf.end.value);
    if (s && e && e <= s) { fail(form, 'ends_at', '끝은 시작보다 뒤여야 합니다.'); }
    return { starts_at: s, ends_at: e };
  }
  function affectedText(a) {
    if (!a) { return ''; }
    return ' · 영향 ' + (a.users | 0) + '명' + (a.revoked_sessions ? ' · 세션 ' + a.revoked_sessions + '개 종료' : '');
  }

  /* ---- 서비스 규칙·scopes 등록부 편집(행렬 머리글과 서비스 보기가 함께 쓴다) ---- */
  function editService(sid, after) {
    apiGet(P_ACCESS, { service_id: sid, page: 1, per_page: 1 }).then(function (d) {
      if (!canWrite()) {
        alertModal('접근 규칙 · ' + serviceName(sid), kv([
          ['규칙', lbl(RULE_LABEL, d.access_rule)], ['보기 단계 지원', d.level_aware ? '예' : '아니오'],
          ['기능(scopes) 등록부', d.features === null || d.features === undefined ? '관리 안 함' : d.features.length + '개']
        ]));
        return;
      }
      var form = el('form', { novalidate: true });
      var rule = select('access_rule', Object.keys(RULE_LABEL).map(function (k) { return [k, RULE_LABEL[k]]; }), d.access_rule);
      var clu = checkField('can_list_users', '이 시스템 서버의 회원 목록 조회 허용', d.can_list_users);
      var csu = checkField('can_search_users', '이 시스템 서버의 회원 찾기(공유 대상 검색) 허용', d.can_search_users);
      var la = checkField('level_aware', '보기 단계를 구현한 시스템', d.level_aware,
        '이 시스템이 보기 단계를 구현했을 때만 켜세요. 꺼져 있으면 「보기」 회원은 들어갈 수 없습니다.');
      var ta = area('features', d.features === null || d.features === undefined ? '' : json(d.features, true), 7);
      mount(form, [field('쓸 수 있는 회원', rule), clu.box, csu.box, la.box,
        field('기능(scopes) 등록부 (JSON)', ta, '예: [{"id":"export","name":"내보내기","default":true}] · 비우면 관리 안 함')]);
      var before = ta.value;
      modal({
        title: '접근 규칙 · ' + serviceName(sid), large: true, confirmText: '저장', body: form,
        onConfirm: function () {
          clearFormErrors(form);
          var feat = ta.value !== before ? parseJson(form, 'features', ta.value, true) : null;
          return apiPost(P_ACCESS, { action: 'set_rule', service_id: sid, access_rule: rule.value, can_list_users: clu.input.checked, can_search_users: csu.input.checked, level_aware: la.input.checked })
            .then(function (r) {
              toast('규칙: ' + lbl(RULE_LABEL, r.access_rule) + (r.level_aware ? ' · 보기 단계 지원' : ''), 'success');
              if (ta.value === before) { return null; }
              return apiPost(P_ACCESS, { action: 'set_features_registry', service_id: sid, features: feat === undefined ? null : feat });
            }).then(function () { if (after) { after(); } }, rethrow(form));
        }
      });
    }).catch(function (err) { toast(errorMessage(err), 'danger'); });
  }

  /* ---- 셀 덮어쓰기 ---- */
  function editOverride(row, svc, cell, after) {
    var title = '접근 · ' + row.username + ' × ' + svc.service_name;
    apiGet(P_ACCESS, { uuid: row.uuid }).then(function (d) {
      var cur = (d.systems || []).filter(function (s) { return s.service_id === svc.service_id; })[0] || {};
      var now = kv([
        ['지금 단계', levelChip(cell.level)],
        ['출처', srcText(cell.source)],
        ['기간', cell.until ? '~ ' + fmtDate(cell.until) : '무기한']
      ]);
      if (!canWrite()) { alertModal(title, now); return; }
      var form = el('form', { novalidate: true });
      var gr = select('grant', Object.keys(GRANT_LABEL).map(function (k) { return [k, k === 'deny' ? '거부' : GRANT_LABEL[k]]; }), cur.access_grant || 'inherit');
      var lv = select('level', LEVEL_PICK, cur.access_level || 'use');
      var lvF = field('단계', lv);
      var pf = periodFields(cur.starts_at, cur.ends_at);
      var note = input('note', cur.access_note || '', { maxlength: 255 });
      var pfBox = el('div', null, pf.nodes);
      function sync() { lvF.hidden = gr.value !== 'allow'; pfBox.hidden = gr.value === 'inherit'; }
      gr.addEventListener('change', sync);
      mount(form, [now, field('덮어쓰기', gr, '허용은 규칙·그룹보다 우선하고, 거부는 모든 것보다 우선합니다.'), lvF, pfBox, field('메모', note)]);
      sync();
      var rv = btn('회수', 'btn-danger', function () {
        var outer = form.closest ? form.closest('.modal') : null;
        confirmModal('접근 회수', '이 계정의 이 시스템 세션이 바로 끊깁니다. 거부로 바꾸고 기간은 무기한이 됩니다.', {
          confirmText: '회수', danger: true,
          onConfirm: function () {
            return apiPost(P_ACCESS, { action: 'revoke', uuid: row.uuid, service_id: svc.service_id, note: note.value.trim() || null }).then(function (r) {
              toast('회수했습니다' + (r.revoked_sessions ? ' · 세션 ' + r.revoked_sessions + '개 종료' : ''), 'success');
              after();
              return true;
            });
          }
        }).then(function (ok) {
          var x = ok && outer ? outer.querySelector('.modal-close') : null;
          if (x) { x.click(); }
        });
      });
      form.appendChild(el('div', { class: 'row end' }, rv));
      modal({
        title: title, confirmText: '저장', body: form,
        onConfirm: function () {
          clearFormErrors(form);
          var body = { action: 'set_override', uuid: row.uuid, service_id: svc.service_id, grant: gr.value, note: note.value.trim() || null };
          if (gr.value === 'allow') { body.level = lv.value; }
          if (gr.value !== 'inherit') { var p = checkPeriod(form, pf); body.starts_at = p.starts_at; body.ends_at = p.ends_at; }
          return apiPost(P_ACCESS, body).then(function (r) {
            var e = r.effective || {};
            toast('지금 ' + lbl(LEVEL_LABEL, e.level) + ' · ' + srcText(e.source) + (r.revoked_sessions ? ' · 세션 ' + r.revoked_sessions + '개 종료' : ''), 'success');
            after();
          }, rethrow(form));
        }
      });
    }).catch(function (err) { toast(errorMessage(err), 'danger'); });
  }

  function tabAccess(root) {
    var views = [['matrix', '계정 × 서비스'], ['groups', '그룹'], ['services', '서비스']];
    var cur = 'matrix';
    var nav = el('div', { class: 'row pf-seg', role: 'tablist' });
    var body = el('div', { class: 'pf-root' });
    mount(root, [nav, body]);
    var btns = {};
    views.forEach(function (v) {
      var b = el('button', { type: 'button', class: 'btn btn-sm btn-secondary', role: 'tab', 'aria-selected': 'false' }, v[1]);
      b.addEventListener('click', function () { show(v[0]); });
      btns[v[0]] = b;
      nav.appendChild(b);
    });
    function show(k) {
      cur = k;
      views.forEach(function (v) {
        var on = v[0] === k;
        btns[v[0]].setAttribute('aria-selected', on ? 'true' : 'false');
        btns[v[0]].className = 'btn btn-sm ' + (on ? 'btn-primary' : 'btn-secondary');
      });
      clear(body);
      var host = el('div');
      body.appendChild(host);
      loading(host);
      loadServices().then(function () {
        clear(host);
        if (k === 'matrix') { viewMatrix(host); } else if (k === 'groups') { viewGroups(host); } else { viewServices(host); }
      }).catch(function (err) { showErr(host, err); });
    }
    show(cur);
  }

  /* ---- 보기 1: 계정 × 서비스 행렬 ---- */
  function viewMatrix(root) {
    var st = { page: 1, q: '', group_key: '', service_id: '' };
    var groups = [];
    var bar = el('form', { class: 'toolbar', novalidate: true });
    var host = el('div');
    mount(root, [bar, host]);
    var q = input('q', '', { type: 'search', placeholder: '아이디·이름', maxlength: 50 });
    var gsel = groupSelect('group_key', [], '', '모든 그룹');
    var ssel = serviceSelect('service_id', '모든 서비스', '');
    var go = el('button', { type: 'submit', class: 'btn btn-secondary btn-sm' }, '찾기');
    mount(bar, [field('계정', q), field('그룹', gsel), field('서비스', ssel), el('div', { class: 'field pf-actions' }, go)]);
    bar.addEventListener('submit', function (ev) {
      ev.preventDefault();
      st.q = q.value.trim(); st.group_key = gsel.value; st.service_id = ssel.value; st.page = 1; load();
    });
    apiGet(P_GROUPS, { page: 1, per_page: 100 }).then(function (d) {
      groups = d.items || [];
      var ng = groupSelect('group_key', groups, st.group_key, '모든 그룹');
      ng.id = gsel.id;
      gsel.parentNode.replaceChild(ng, gsel);
      gsel = ng;
    }).catch(function () { /* 그룹 거름만 빠진다 */ });
    load();

    function load() {
      loading(host);
      var qs = { page: st.page, per_page: 50 };
      if (st.q) { qs.q = st.q; }
      if (st.group_key) { qs.group_key = st.group_key; }
      if (st.service_id) { qs.service_id = st.service_id; }
      apiGet(P_MATRIX, qs).then(render).catch(function (err) { showErr(host, err); });
    }

    function render(d) {
      clear(host);
      var svcs = d.services || [], items = d.items || [];
      var ov = 0, timed = 0;
      items.forEach(function (r) {
        svcs.forEach(function (s) {
          var c = (r.cells || {})[s.service_id];
          if (!c) { return; }
          if (c.override) { ov += 1; }
          if (c.until) { timed += 1; }
        });
      });
      host.appendChild(statStrip('접근 현황', '이 페이지 기준', [
        { label: '계정', value: fmtNum(d.total | 0) },
        { label: '서비스', value: fmtNum(svcs.length) },
        { label: '덮어쓰기', value: fmtNum(ov), tip: '규칙·그룹과 달리 계정에 직접 지정한 칸' },
        { label: '기한 있음', value: fmtNum(timed), tip: '끝나는 시각이 정해진 칸' }
      ]));
      if (!items.length) { host.appendChild(emptyNode('조건에 맞는 계정이 없습니다.')); return; }
      host.appendChild(el('p', { class: 'text-sm text-muted pf-legend' },
        '칸을 누르면 덮어쓰기를 바꿀 수 있습니다. 출처: 규칙 · 그룹 · 덮어쓰기. 서비스 이름을 누르면 규칙과 기능을 편집합니다.'));
      var head = el('tr', null, [el('th', { scope: 'col', class: 'pf-mx-first' }, '계정')]);
      svcs.forEach(function (s) {
        var h = el('button', { type: 'button', class: 'btn-link pf-mx-svc', title: lbl(RULE_LABEL, s.access_rule) + (s.level_aware ? ' · 보기 단계 지원' : '') }, s.service_name || s.service_id);
        h.addEventListener('click', function () { editService(s.service_id, load); });
        head.appendChild(el('th', { scope: 'col' }, [h, el('div', { class: 'mono text-xs text-muted' }, s.service_id)]));
      });
      var tb = el('tbody');
      items.forEach(function (r) {
        var tr = el('tr', null, [el('th', { scope: 'row', class: 'pf-mx-first' }, [
          el('div', { class: 'mono' }, r.username), el('div', { class: 'text-xs text-muted' }, nz(r.name)),
          r.status && r.status !== 'active' ? badge('status', r.status) : null,
          (r.is_emergency || r.account_kind === 'emergency') ? el('span', { class: 'badge badge-emergency' }, '비상') : null
        ])]);
        svcs.forEach(function (s) {
          var c = (r.cells || {})[s.service_id] || { level: 'none', source: 'rule', until: null, override: null };
          var sk = srcKind(c.source);
          var sub = srcText(c.source) + (c.override === 'deny' ? ' · 거부' : '');
          var b = el('button', { type: 'button', class: 'pf-cell', dataset: { level: c.level, kind: sk.kind },
            title: r.username + ' × ' + (s.service_name || s.service_id) + ' · ' + lbl(LEVEL_LABEL, c.level) + ' · ' + sub + (c.until ? ' · ~' + fmtDate(c.until) : '') }, [
            el('span', { class: 'pf-cell-lv' }, lbl(LEVEL_LABEL, c.level)),
            el('span', { class: 'pf-cell-src' }, sub),
            c.until ? el('span', { class: 'pf-cell-until' }, '~' + fmtDate(c.until, true)) : null
          ]);
          b.addEventListener('click', function () { editOverride(r, s, c, load); });
          tr.appendChild(el('td', { class: 'pf-mx-td' }, b));
        });
        tb.appendChild(tr);
      });
      var table = el('table', { class: 'table pf-mx' }, [el('thead', null, head), tb]);
      host.appendChild(el('div', { class: 'table-wrap pf-mx-wrap' }, table));
      var pg = el('div');
      host.appendChild(pg);
      pager(pg, d, function (p) { st.page = p; load(); });
    }
  }

  /* ---- 보기 2: 그룹 ---- */
  function viewGroups(root) {
    var host = el('div');
    root.appendChild(host);
    list(1);

    function list(page) {
      loading(host);
      apiGet(P_GROUPS, { page: page, per_page: 50 }).then(function (d) {
        clear(host);
        var items = d.items || [];
        var mem = 0, act = 0;
        items.forEach(function (g) { mem += g.member_count | 0; if (g.status === 'active') { act += 1; } });
        host.appendChild(statStrip('그룹', null, [
          { label: '그룹', value: fmtNum(d.total | 0) }, { label: '사용 중', value: fmtNum(act) }, { label: '구성원 합', value: fmtNum(mem), tip: '이 페이지 그룹의 구성원 수 합계(중복 포함)' }
        ]));
        var c = card('그룹 목록', [writeBtn('그룹 만들기', 'btn-primary', function () { editGroup(null); })]);
        if (!items.length) {
          c.body.appendChild(emptyNode('그룹이 없습니다.'));
        } else {
          var t = tableNode(['그룹', '상태', { label: '구성원', className: 'num' }, '서비스 단계', '']);
          items.forEach(function (g) {
            var sv = (g.services || []).map(function (x) { return x.service_id + ' ' + lbl(LEVEL_LABEL, x.level); }).join(', ');
            var tr = el('tr', { class: 'pf-click', tabindex: '0' }, [
              el('td', null, [el('div', null, g.name), el('div', { class: 'mono text-xs text-muted' }, g.group_key)]),
              el('td', null, chip(lbl(GROUP_STATUS_LABEL, g.status), g.status === 'active' ? 'active' : 'dormant')),
              el('td', { class: 'num' }, fmtNum(g.member_count | 0)),
              el('td', { class: 'text-sm' }, sv || '-'),
              el('td', { class: 'nowrap' }, btn('열기', 'btn-secondary', function () { detail(g.group_key, 1); }))
            ]);
            tr.addEventListener('click', function () { detail(g.group_key, 1); });
            tr.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); detail(g.group_key, 1); } });
            t.tbody.appendChild(tr);
          });
          c.body.appendChild(t.wrap);
          var pg = el('div');
          c.body.appendChild(pg);
          pager(pg, d, function (p) { list(p); });
        }
        host.appendChild(c.box);
      }).catch(function (err) { showErr(host, err); });
    }

    function editGroup(g, after) {
      var form = el('form', { novalidate: true });
      var key = input('group_key', g ? g.group_key : '', { maxlength: 64, spellcheck: 'false', class: 'mono' });
      if (g) { key.readOnly = true; }
      var nm = input('name', g ? g.name : '', { maxlength: 100 });
      var ds = input('description', g && g.description ? g.description : '', { maxlength: 255 });
      var nodes = [field('그룹 키', key, g ? null : '영문 소문자로 시작, 소문자·숫자·_ . - 만'), field('이름', nm), field('설명', ds)];
      var stSel = null;
      if (g) { stSel = select('status', [['active', '사용'], ['disabled', '꺼짐']], g.status); nodes.push(field('상태', stSel, '끄면 이 그룹으로 받던 단계가 사라지고 세션이 끊길 수 있습니다.')); }
      mount(form, nodes);
      modal({
        title: g ? '그룹 수정' : '그룹 만들기', confirmText: '저장', body: form,
        onConfirm: function () {
          clearFormErrors(form);
          var b = { action: g ? 'update' : 'create', group_key: key.value.trim(), name: nm.value.trim(), description: ds.value.trim() || null };
          if (stSel) { b.status = stSel.value; }
          return apiPost(P_GROUPS, b).then(function (r) {
            toast('저장했습니다' + affectedText(r.affected), 'success');
            if (after) { after(); } else { list(1); }
          }, rethrow(form));
        }
      });
    }

    function detail(gk, mpage) {
      loading(host);
      apiGet(P_GROUPS, { group_key: gk, page: mpage }).then(function (d) {
        clear(host);
        var g = d.group, m = d.members || {};
        var back = btn('← 목록', 'btn-secondary', function () { list(1); });
        host.appendChild(el('div', { class: 'row pf-back' }, back));
        var again = function () { detail(gk, mpage); };

        var gc = card(g.name, [
          writeBtn('수정', 'btn-secondary', function () { editGroup(g, again); }),
          writeBtn('삭제', 'btn-danger', function () {
            confirmModal('그룹 삭제', '「' + g.name + '」 그룹을 지웁니다. 이 그룹으로 받던 단계가 모두 사라지고 해당 세션이 끊길 수 있습니다.', {
              confirmText: '삭제', danger: true, confirmPhrase: g.group_key, confirmPhraseLabel: '그룹 키를 그대로 입력하세요',
              onConfirm: function () {
                return apiPost(P_GROUPS, { action: 'delete', group_key: g.group_key, confirm: g.group_key }).then(function (r) {
                  toast('삭제했습니다' + affectedText(r.affected), 'success'); list(1);
                });
              }
            });
          })
        ]);
        gc.body.appendChild(kv([
          ['그룹 키', el('span', { class: 'mono' }, g.group_key)], ['상태', lbl(GROUP_STATUS_LABEL, g.status)],
          ['설명', nz(g.description)], ['구성원', fmtNum(m.total | 0) + '명']
        ]));
        host.appendChild(gc.box);

        var sc = card('서비스 단계', [writeBtn('서비스 단계 추가', 'btn-primary', function () { editGroupService(g, null, again); })]);
        var sv = g.services || [];
        if (!sv.length) { sc.body.appendChild(emptyNode('이 그룹에 지정한 서비스가 없습니다.')); }
        else {
          var st = tableNode(['서비스', '단계', '기능(scopes)', '기간', '']);
          sv.forEach(function (x) {
            st.tbody.appendChild(el('tr', null, [
              el('td', null, [text(serviceName(x.service_id)), el('div', { class: 'mono text-xs text-muted' }, x.service_id)]),
              el('td', null, levelChip(x.level)),
              el('td', { class: 'mono text-sm' }, x.scopes === null || x.scopes === undefined ? '기본값' : (x.scopes.join(', ') || '없음')),
              el('td', { class: 'nowrap text-sm' }, periodText(x.starts_at, x.ends_at)),
              el('td', { class: 'nowrap' }, [
                writeBtn('변경', 'btn-secondary', function () { editGroupService(g, x, again); }), text(' '),
                writeBtn('해제', 'btn-danger', function () {
                  confirmModal('단계 해제', serviceName(x.service_id) + ' 단계를 이 그룹에서 뺍니다.', {
                    confirmText: '해제', danger: true,
                    onConfirm: function () {
                      return apiPost(P_GROUPS, { action: 'set_service', group_key: g.group_key, service_id: x.service_id, level: 'none' })
                        .then(function (r) { toast('해제했습니다' + affectedText(r.affected), 'success'); again(); });
                    }
                  });
                })
              ])
            ]));
          });
          sc.body.appendChild(st.wrap);
        }
        host.appendChild(sc.box);

        var mc = card('구성원', [writeBtn('구성원 추가', 'btn-primary', function () { addMembers(g, again); })]);
        var mi = m.items || [];
        if (!mi.length) { mc.body.appendChild(emptyNode('구성원이 없습니다.')); }
        else {
          var mt = tableNode(['아이디', '이름', '기간', '']);
          mi.forEach(function (u) {
            mt.tbody.appendChild(el('tr', null, [
              el('td', { class: 'mono' }, u.username), el('td', null, nz(u.name)),
              el('td', { class: 'nowrap text-sm' }, periodText(u.starts_at, u.ends_at)),
              el('td', { class: 'nowrap' }, writeBtn('제외', 'btn-secondary', function () {
                confirmModal('구성원 제외', u.username + ' 계정을 그룹에서 뺍니다.', {
                  confirmText: '제외', danger: true,
                  onConfirm: function () {
                    return apiPost(P_GROUPS, { action: 'remove_members', group_key: g.group_key, uuids: [u.uuid] })
                      .then(function (r) { toast('제외했습니다' + affectedText(r.affected), 'success'); again(); });
                  }
                });
              }))
            ]));
          });
          mc.body.appendChild(mt.wrap);
          var pg = el('div');
          mc.body.appendChild(pg);
          pager(pg, m, function (p) { detail(gk, p); });
        }
        host.appendChild(mc.box);
      }).catch(function (err) { showErr(host, err); });
    }

    function editGroupService(g, x, after) {
      var form = el('form', { novalidate: true });
      var sel = serviceSelect('service_id', null, x ? x.service_id : '');
      if (x) { sel.disabled = true; }
      var lv = select('level', LEVEL_PICK, x ? x.level : 'use');
      var sc = input('scopes', x && x.scopes ? x.scopes.join(', ') : '', { spellcheck: 'false', class: 'mono' });
      var pf = periodFields(x && x.starts_at, x && x.ends_at);
      mount(form, [field('서비스', sel), field('단계', lv),
        field('기능(scopes) (쉼표로 구분)', sc, '비우면 서비스 기본값. 등록부에 없는 ID는 서버가 거절합니다.')].concat(pf.nodes));
      modal({
        title: '서비스 단계 · ' + g.name, confirmText: '저장', body: form,
        onConfirm: function () {
          clearFormErrors(form);
          var p = checkPeriod(form, pf);
          var raw = sc.value.trim();
          return apiPost(P_GROUPS, { action: 'set_service', group_key: g.group_key, service_id: sel.value, level: lv.value,
            scopes: raw === '' ? null : raw.split(/[\s,]+/).filter(Boolean), starts_at: p.starts_at, ends_at: p.ends_at })
            .then(function (r) { toast('저장했습니다' + affectedText(r.affected), 'success'); after(); }, rethrow(form));
        }
      });
    }

    function addMembers(g, after) {
      var form = el('form', { novalidate: true });
      var ta = area('uuids', '', 5);
      var pf = periodFields(null, null);
      mount(form, [field('회원 UUID', ta, '공백이나 줄바꿈으로 구분 · 한 번에 100명까지')].concat(pf.nodes));
      modal({
        title: '구성원 추가 · ' + g.name, confirmText: '추가', body: form,
        onConfirm: function () {
          clearFormErrors(form);
          var ids = ta.value.split(/[\s,]+/).filter(Boolean);
          if (!ids.length) { fail(form, 'uuids', '회원 UUID 를 넣으세요.'); }
          if (ids.length > 100) { fail(form, 'uuids', '한 번에 100명까지입니다.'); }
          var p = checkPeriod(form, pf);
          return apiPost(P_GROUPS, { action: 'add_members', group_key: g.group_key, uuids: ids, starts_at: p.starts_at, ends_at: p.ends_at }).then(function (r) {
            var sk = r.skipped || [];
            toast('추가 ' + (r.added | 0) + '명' + (sk.length ? ' · 건너뜀 ' + sk.length + '명(' + sk.slice(0, 3).map(function (z) { return z.reason; }).join(', ') + ')' : '') + affectedText(r.affected), sk.length ? 'warn' : 'success');
            after();
          }, rethrow(form));
        }
      });
    }
  }

  /* ---- 보기 3: 서비스(규칙 · 기능 등록부 · 계정 지정) ---- */
  function viewServices(root) {
    var st = { sid: '', page: 1 };
    var bar = el('form', { class: 'toolbar', novalidate: true });
    var host = el('div');
    var userHost = el('div');
    mount(root, [bar, host, userHost]);
    var sel = serviceSelect('service_id', '서비스를 고르세요', '');
    sel.addEventListener('change', function () { st.sid = sel.value; st.page = 1; load(); });
    var who = input('uuid', '', { maxlength: 36, spellcheck: 'false', placeholder: '8-4-4-4-12' });
    var go = el('button', { type: 'submit', class: 'btn btn-secondary btn-sm' }, '회원별 보기');
    mount(bar, [field('서비스', sel), field('회원 UUID', who), el('div', { class: 'field pf-actions' }, go)]);
    bar.addEventListener('submit', function (ev) {
      ev.preventDefault();
      clearFormErrors(bar);
      if (!who.value.trim()) { formErrors(bar, { field: 'uuid', message: '회원 UUID 를 넣으세요.' }); return; }
      clear(userHost);
      var c = card('시스템별 허용');
      userHost.appendChild(c.box);
      userAccess(c.body, who.value.trim());
    });
    host.appendChild(emptyNode('서비스를 고르면 접근 규칙과 기능이 보입니다.'));

    function load() {
      if (!st.sid) { clear(host); return; }
      loading(host);
      apiGet(P_ACCESS, { service_id: st.sid, page: st.page, per_page: 50 }).then(render).catch(function (err) { showErr(host, err); });
    }

    function render(d) {
      clear(host);
      var rc = card('접근 규칙', [writeBtn('규칙·기능 편집', 'btn-primary', function () { editService(d.service_id, load); })]);
      rc.body.appendChild(kv([
        ['서비스', serviceName(d.service_id) + ' (' + d.service_id + ')'],
        ['쓸 수 있는 회원', lbl(RULE_LABEL, d.access_rule)],
        ['보기 단계 지원', d.level_aware ? '예' : '아니오'],
        ['회원 목록 조회', d.can_list_users ? '허용' : '막음'],
        ['회원 찾기', d.can_search_users ? '허용' : '막음']
      ]));
      host.appendChild(rc.box);

      var fc = card('기능(scopes) 등록부');
      if (d.features === null || d.features === undefined) {
        fc.body.appendChild(emptyNode('통합로그인이 기능을 관리하지 않습니다.'));
      } else if (!d.features.length) {
        fc.body.appendChild(emptyNode('등록된 기능이 없습니다.'));
      } else {
        var ft = tableNode(['기능 ID', '이름', '기본 허용']);
        d.features.forEach(function (f) {
          ft.tbody.appendChild(el('tr', null, [el('td', { class: 'mono' }, f.id), el('td', null, nz(f.name)), el('td', null, f['default'] ? '예' : '아니오')]));
        });
        fc.body.appendChild(ft.wrap);
      }
      host.appendChild(fc.box);

      var g = d.grants || {};
      var gc = card('회원 지정', [writeBtn('회원 추가', 'btn-primary', function () { editGrant(d, null); })]);
      var items = g.items || [];
      if (!items.length) {
        gc.body.appendChild(emptyNode('따로 지정한 회원이 없습니다.'));
      } else {
        var t = tableNode(['아이디', '회원 UUID', '지정', '단계', '기간', '기능', '']);
        items.forEach(function (it) {
          t.tbody.appendChild(el('tr', null, [
            el('td', { class: 'mono' }, it.username),
            el('td', { class: 'mono nowrap', title: it.uuid }, cut(it.uuid, 10)),
            el('td', null, chip(lbl(GRANT_LABEL, it.access_grant), it.access_grant === 'deny' ? 'suspended' : (it.access_grant === 'allow' ? 'active' : ''))),
            el('td', { class: 'nowrap' }, it.access_grant === 'allow' ? lbl(LEVEL_LABEL, it.access_level || 'use') : '-'),
            el('td', { class: 'nowrap text-sm' }, periodText(it.access_starts_at || it.starts_at, it.access_ends_at || it.ends_at)),
            el('td', { class: 'mono' }, it.features === null ? '기본값' : (it.features.join(', ') || '없음')),
            el('td', { class: 'nowrap' }, writeBtn('변경', 'btn-secondary', function () { editGrant(d, it); }))
          ]));
        });
        gc.body.appendChild(t.wrap);
        var pg = el('div');
        gc.body.appendChild(pg);
        pager(pg, g, function (p) { st.page = p; load(); });
      }
      host.appendChild(gc.box);
    }

    /* 기존 set_grant(허용·차단·규칙 따름) + 계정별 기능. 단계·기간은 행렬 셀에서 바꾼다. */
    function editGrant(d, it) {
      var form = el('form', { novalidate: true });
      var who2 = input('uuid', it ? it.uuid : '', { maxlength: 36, spellcheck: 'false', class: 'mono' });
      if (it) { who2.readOnly = true; }
      var gr = select('grant', Object.keys(GRANT_LABEL).map(function (k) { return [k, GRANT_LABEL[k]]; }), it ? it.access_grant : 'allow');
      var ft = input('features', it && it.features ? it.features.join(', ') : '', { spellcheck: 'false', class: 'mono' });
      var nodes = [field('회원 UUID', who2), field('허용', gr, '차단하면 그 시스템의 로그인 세션도 끊깁니다. 단계·기간은 「계정 × 서비스」에서 바꿉니다.')];
      if (d.features !== null && d.features !== undefined) {
        nodes.push(field('기능 (쉼표로 구분)', ft, '비우면 기본값 · 등록부 ID: ' + (d.features.map(function (f) { return f.id; }).join(', ') || '없음')));
      }
      mount(form, nodes);
      modal({
        title: it ? '회원 지정 · ' + it.username : '회원 추가', confirmText: '저장', body: form,
        onConfirm: function () {
          clearFormErrors(form);
          var u = who2.value.trim();
          return apiPost(P_ACCESS, { action: 'set_grant', service_id: d.service_id, uuid: u, grant: gr.value }).then(function (r) {
            var msg = (r.allowed ? '허용' : '거절') + ' · ' + lbl(REASON_LABEL, r.reason) + ' · ' + lbl(GRANT_LABEL, r.access_grant);
            if (r.revoked_sessions) { msg += ' · 세션 ' + r.revoked_sessions + '개 종료'; }
            toast(msg, 'success');
            if (d.features === null || d.features === undefined) { return null; }
            var raw = ft.value.trim();
            var list = raw === '' ? null : raw.split(/[\s,]+/).filter(Boolean);
            return apiPost(P_ACCESS, { action: 'set_user_features', service_id: d.service_id, uuid: u, features: list });
          }).then(function () { load(); }, rethrow(form));
        }
      });
    }
  }

  /** 회원 한 명의 시스템별 허용 표(서버 판정값 그대로). 회원 상세 모달도 이 함수를 쓴다 */
  function userAccess(host, uuid) {
    loading(host);
    apiGet(P_ACCESS, { uuid: uuid }).then(function (d) {
      clear(host);
      var sys = d.systems || [];
      if (!sys.length) { host.appendChild(emptyNode('등록된 시스템이 없습니다.')); return; }
      var t = tableNode(['시스템', '규칙', '지정', '단계', '출처', '기간', '기능']);
      sys.forEach(function (s) {
        var lv = s.level || (s.allowed ? 'use' : 'none');
        t.tbody.appendChild(el('tr', null, [
          el('td', null, [text(nz(s.service_name)), el('span', { class: 'text-sm text-muted mono' }, ' ' + s.service_id)]),
          el('td', { class: 'nowrap' }, lbl(RULE_LABEL, s.access_rule)),
          el('td', { class: 'nowrap' }, lbl(GRANT_LABEL, s.access_grant)),
          el('td', null, levelChip(lv)),
          el('td', { class: 'nowrap' }, s.source ? srcText(s.source) : lbl(REASON_LABEL, s.reason)),
          el('td', { class: 'nowrap text-sm' }, s.until ? '~ ' + fmtDate(s.until) : '무기한'),
          el('td', { class: 'mono' }, s.features === null || s.features === undefined ? '-' : (s.features.join(', ') || '없음'))
        ]));
      });
      host.appendChild(t.wrap);
    }).catch(function (err) { showErr(host, err, '시스템별 허용을 불러올 수 없습니다'); });
  }

  /* ---------------------------------------------------------------- 진입 */
  var TABS = { pconfig: tabConfig, plogs: function (r) { tabLogs(r, 'logs'); }, paudit: function (r) { tabLogs(r, 'audit'); },
    ptables: tabTables, pusage: tabUsage, access: tabAccess };

  /**
   * @param {string} key   탭 키(pconfig·plogs·paudit·ptables·pusage·access)
   * @param {Element} root 그 탭의 뿌리
   * @param {object} ctx   { me, canWrite, showError } — canWrite 는 표시용(서버가 superadmin 을 다시 판정한다)
   */
  function init(key, root, ctx) {
    C = ctx || C || {};
    if (!root || !TABS[key]) { return; }
    clear(root);
    root.classList.add('pf-root');
    TABS[key](root);
  }
  function setContext(ctx) { C = ctx || C; }

  global.UloginAdminPlatform = { init: init, userAccess: userAccess, setContext: setContext, TAB_KEYS: Object.keys(TABS) };
})(window);
