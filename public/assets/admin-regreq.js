/* ============================================================================
 * ulogin v1.10.1 — 관리자 콘솔 「가입 신청」 탭 (admin-regreq.js)
 *
 * 설계 : _workspace/130_architect_v1101_design.md 2-5절
 * 호출 : GET  /api/admin/register_requests?status=&page=&per_page=   목록
 *        GET  /api/admin/register_requests?id=                       상세
 *        POST /api/admin/register_requests  {action:'approve', id, memo}
 *        POST /api/admin/register_requests  {action:'reject', id, reason, notice}
 *        (api.js 의 apiGet/apiPost 만 쓴다. fetch 직접 호출 없음)
 *
 * 신청자가 입력한 값(아이디·이름·메모 등)은 신뢰할 수 없는 입력이다.
 * innerHTML 을 쓰지 않고 el()/text() 로만 그린다. ip·ua 는 서버가 이미 가려서 준다.
 * 권한 표시는 편의다. 실제 방어는 서버(register.view / register.decide)다.
 * ========================================================================== */
(function (global) {
  'use strict';

  var API = '/api/admin/register_requests';
  var PER_PAGE = 20;

  var STATUS_LABEL = { pending: '대기', approved: '승인', rejected: '거부', expired: '만료' };
  var STATUS_TONE = { pending: 'pending', approved: 'active', rejected: 'suspended', expired: 'dormant' };
  var SOURCE_LABEL = { direct: '직접 가입', service: '서비스 경유' };
  /* 판단 재료 8종 — signals 키와 라벨. 숫자형 6 + 불리언형 2 */
  var SIGNALS = [
    ['same_ip_24h', '같은 IP 신청, 24시간'],
    ['same_ip_30d', '같은 IP 신청, 30일'],
    ['same_email_domain_30d', '같은 이메일 도메인 신청, 30일'],
    ['users_same_email_domain', '같은 이메일 도메인 기존 회원'],
    ['prior_rejected_same_email', '같은 이메일 거부 이력'],
    ['prior_rejected_same_ip_30d', '같은 IP 거부 이력, 30일'],
    ['username_taken', '아이디 이미 사용 중'],
    ['email_taken', '이메일 이미 사용 중']
  ];

  function dash(v) { return (v === null || v === undefined || v === '') ? '-' : String(v); }
  function sourceText(r) {
    if (r.source === 'service') { return dash(r.service_name || r.service_id); }
    return SOURCE_LABEL[r.source] || dash(r.source);
  }
  function statusBadge(st) {
    return el('span', { class: 'badge badge-status', dataset: { value: String(st) } }, STATUS_LABEL[st] || dash(st));
  }
  function fieldRow(label, node, hint) {
    var w = el('div', { class: 'field' }, [node.id ? el('label', { for: node.id }, label) : el('span', { class: 'field-label' }, label), node,
      el('span', { class: 'field-error' })]);
    if (hint) { w.appendChild(el('span', { class: 'hint' }, hint)); }
    return w;
  }
  function kv(rows) {
    var tbody = el('tbody');
    rows.forEach(function (r) {
      tbody.appendChild(el('tr', null, [el('th', { scope: 'row' }, String(r[0])), el('td', null, r[1] === null || r[1] === undefined ? '-' : r[1])]));
    });
    return el('div', { class: 'table-wrap' }, el('table', { class: 'kv' }, tbody));
  }

  var ctx = null;
  var st = { status: 'pending', page: 1 };
  var uidN = 0;
  function uid(p) { uidN += 1; return 'rr-' + p + '-' + uidN; }

  function init(c) {
    ctx = c;
    clear(c.root);
    var sel = el('select', { id: 'rr-status', name: 'status' }, [
      el('option', { value: 'pending' }, '대기'),
      el('option', { value: 'approved' }, '승인'),
      el('option', { value: 'rejected' }, '거부'),
      el('option', { value: 'expired' }, '만료'),
      el('option', { value: 'all' }, '전체')
    ]);
    sel.addEventListener('change', function () { st.status = sel.value; st.page = 1; load(); });
    c.root.appendChild(el('div', { class: 'toolbar' }, [
      el('label', { for: 'rr-status' }, '상태'), sel,
      el('button', { type: 'button', class: 'btn btn-secondary btn-sm', on: { click: function () { load(); } } }, '새로 고침')
    ]));
    c.root.appendChild(el('div', { id: 'rr-list' }));
    c.root.appendChild(el('div', { id: 'rr-pager' }));
    load();
  }

  function load() {
    var host = document.getElementById('rr-list');
    clear(host);
    host.appendChild(loadingNode('가입 신청을 불러오는 중…'));
    var q = { status: st.status === 'all' ? '' : st.status, page: st.page, per_page: PER_PAGE };
    apiGet(API, q).then(function (d) {
      renderList(host, d);
      if (typeof ctx.onCount === 'function' && st.status === 'pending') { ctx.onCount(d.total || 0); }
    }).catch(function (err) { ctx.showError(host, err, '가입 신청을 불러올 수 없습니다'); });
  }

  function renderList(host, d) {
    clear(host);
    var items = d.items || [];
    if (!items.length) { host.appendChild(emptyNode('해당하는 신청이 없습니다.')); clear(document.getElementById('rr-pager')); return; }
    var t = tableNode(['번호', '아이디', '신청 경로', '상태', '신청 시각', 'IP', '']);
    items.forEach(function (r) {
      var open = el('button', { type: 'button', class: 'btn btn-secondary btn-sm', on: { click: function () { openDetail(r.id); } } }, '보고서');
      t.tbody.appendChild(el('tr', null, [
        el('td', { class: 'mono' }, dash(r.id)),
        el('td', null, dash(r.username)),
        el('td', null, sourceText(r)),
        el('td', null, statusBadge(r.status)),
        el('td', null, fmtDate(r.created_at)),
        el('td', { class: 'mono' }, dash(r.ip)),
        el('td', null, open)
      ]));
    });
    host.appendChild(t.wrap);
    pager(document.getElementById('rr-pager'), { page: d.page, per_page: d.per_page, total: d.total }, function (p) { st.page = p; load(); });
  }

  function openDetail(id) {
    apiGet(API, { id: id }).then(function (r) { showDetail(r); })
      .catch(function (err) { toast(errorMessage(err), 'danger'); });
  }

  function signalNode(v) {
    if (typeof v === 'boolean') { return el('span', { class: v ? 'is-bad' : '' }, v ? '예' : '아니오'); }
    return text(dash(v));
  }

  function showDetail(r) {
    var sg = r.signals || {};
    var svc = r.service;
    var info = kv([
      ['번호', dash(r.id)],
      ['상태', statusBadge(r.status)],
      ['아이디', dash(r.username)],
      ['이름 · 닉네임', dash(r.name) + ' · ' + dash(r.nickname)],
      ['이메일', dash(r.email)],
      ['연락처', dash(r.phone)],
      ['신청 경로', sourceText(r) + (r.entry ? ' (' + r.entry + ')' : '')],
      ['서비스', svc ? dash(svc.name) + ' · ' + dash(svc.id) + (svc.status ? ' · ' + svc.status : '') : '-'],
      ['복귀 주소', r.redirect_uri ? (r.redirect_uri + (r.redirect_valid ? '' : ' (허용 목록 불일치)')) : '-'],
      ['신청 메모', dash(r.applicant_note)],
      ['신청 시각', fmtDate(r.created_at)],
      ['만료 시각', fmtDate(r.expires_at)],
      ['IP', dash(r.ip)],
      ['브라우저', dash(r.ua_label)]
    ]);
    var sigRows = SIGNALS.map(function (s) { return [s[1], signalNode(sg[s[0]])]; });
    var body = function (b) {
      b.appendChild(el('h4', null, '신청 내용'));
      b.appendChild(info);
      b.appendChild(el('h4', null, '판단 재료'));
      b.appendChild(kv(sigRows));
      if (r.status === 'pending') {
        var rb = el('button', { type: 'button', class: 'btn btn-danger btn-sm' }, '거부…');
        rb.addEventListener('click', function () { rejectDialog(r, rb); });
        b.appendChild(el('div', { class: 'toolbar' }, [rb, el('span', { class: 'hint' }, '아래 「승인」은 계정을 만듭니다.')]));
      }
      if (r.status !== 'pending') {
        b.appendChild(el('h4', null, '처리 결과'));
        b.appendChild(kv([
          ['처리 시각', fmtDate(r.decided_at)],
          ['사유(내부)', dash(r.decision_reason)],
          ['안내(신청자)', dash(r.decision_notice)]
        ]));
      }
    };
    if (r.status !== 'pending') {
      alertModal('가입 신청 ' + r.id, body, { large: true });
      return;
    }
    modal({
      title: '가입 신청 ' + r.id, body: body, large: true,
      confirmText: '승인', cancelText: '닫기',
      onConfirm: function () { return decide(r, 'approve'); }
    }).then(function () {});
  }

  function decide(r, action, extra) {
    var body = { action: action, id: r.id };
    Object.keys(extra || {}).forEach(function (k) { body[k] = extra[k]; });
    return apiPost(API, body).then(function (res) {
      var w = res && res.warnings && res.warnings.length ? ' (경고: ' + res.warnings.join(', ') + ')' : '';
      toast((action === 'approve' ? '승인했습니다.' : '거부했습니다.') + w, 'success');
      load();
      if (typeof ctx.onDecided === 'function') { ctx.onDecided(); }
      return true;
    });
  }

  function rejectDialog(r, opener) {
    var reason = el('textarea', { id: uid('reason'), name: 'reason', rows: 2, maxlength: 255, required: true });
    var notice = el('textarea', { id: uid('notice'), name: 'notice', rows: 2, maxlength: 200 });
    modal({
      title: '가입 신청 ' + r.id + ' 거부', danger: true, confirmText: '거부',
      body: function (b) {
        b.appendChild(fieldRow('사유 (필수, 내부 기록)', reason, '관리자만 볼 수 있습니다.'));
        b.appendChild(fieldRow('신청자 안내 (선택)', notice, '접수번호로 결과를 조회하는 신청자에게 보입니다.'));
      },
      onConfirm: function () {
        var rs = reason.value.trim();
        if (!rs) { reason.focus(); return Promise.reject(new Error('사유를 입력해 주세요.')); }
        var extra = { reason: rs };
        var nt = notice.value.trim();
        if (nt) { extra.notice = nt; }
        return decide(r, 'reject', extra).then(function () {
          var m = opener && opener.closest ? opener.closest('.modal') : null;
          var x = m ? m.querySelector('.modal-close') : null;
          if (x) { x.click(); }
          return true;
        });
      }
    });
  }

  global.UloginAdminRegreq = { init: init };
})(window);
