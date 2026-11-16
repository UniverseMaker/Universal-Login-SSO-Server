/* ============================================================================
 * ulogin-sdk.js — 통합로그인 플랫폼 브라우저 SDK (v1.9.0, 설계 116 §9)
 *
 * 전역 설정 읽기 · 운영 로그 보내기 · 문서 테이블 읽고 쓰기. 의존성 0, 빌드 없음.
 * 토큰은 이 파일이 갖지 않는다 — ulogin-client.js(또는 앱)의 getter 로 받는다.
 *
 *   <script src="https://login.example.com/public/assets/ulogin-client.js"></script>
 *   <script src="https://login.example.com/public/assets/ulogin-sdk.js"></script>
 *   const sdk = ULoginSDK.init({ base: 'https://login.example.com', serviceId: 'task',
 *                                getToken: () => ulogin.getAccessToken() });
 *   const cfg = await sdk.config.get();                    // { 'notice.banner': '…', … }
 *   sdk.log.warn('export.slow', '내보내기 지연', { ms: 5003 });
 *   const notes = sdk.db.collection('notes');
 *   const { doc } = await notes.add({ title: '회의록', status: 'open' });
 *   await notes.doc(doc.doc_id).merge({ status: 'done' }, { expectedVersion: doc.version });
 *   const { items, next } = await notes.where('status', '==', 'open').orderBy('due', 'asc').limit(20).get();
 *
 * ▣ 서비스 시크릿은 브라우저에 두지 않는다. 이 SDK 는 로그인 사용자 토큰(Bearer)만 쓴다.
 *   감사 기록(platform/audit)·컬렉션 정의(platform/collections)는 서버끼리만 부른다 — 여기 없다.
 * ▣ 오류는 봉투 error 를 그대로 담은 ULoginError{code, message, field, status, data} 로 던진다.
 * ▣ 로그는 버퍼(20건 또는 10초)로 모아 보내고, 실패하면 조용히 버린다(브라우저는 스풀이 없다).
 *   서비스에서 브라우저 로그 수집을 켜 두지 않았으면 서버가 403 을 주고, 그것도 조용히 버린다.
 * ========================================================================== */
(function (global) {
  'use strict';

  var API_SUBDIR = '/server';
  var API_EXT = '.php';
  var P_CONFIG = '/api/platform/remote_config';
  var P_LOGS = '/api/platform/logs';
  var P_DOCS = '/api/platform/docs';
  var LOG_BATCH = 20;            // user 갈래 배치 상한(설계 §5)
  var LOG_INTERVAL_MS = 10000;
  var LEVELS = ['debug', 'info', 'warn', 'error', 'fatal'];

  function ULoginError(code, message, status, field, data) {
    var e = new Error(message || code || '오류가 발생했습니다.');
    e.name = 'ULoginError';
    e.code = code || 'SERVER_ERROR';
    e.status = status || 0;
    e.field = field || null;
    e.data = data === undefined ? null : data;
    return e;
  }

  function uuid4() {
    var c = global.crypto;
    if (c && typeof c.randomUUID === 'function') { return c.randomUUID(); }
    var b = new Uint8Array(16);
    if (c && c.getRandomValues) { c.getRandomValues(b); } else { for (var i = 0; i < 16; i++) { b[i] = Math.floor(Math.random() * 256); } }
    b[6] = (b[6] & 15) | 64;
    b[8] = (b[8] & 63) | 128;
    var h = Array.prototype.map.call(b, function (x) { return (x < 16 ? '0' : '') + x.toString(16); }).join('');
    return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
  }

  function storeGet(k) { try { var v = global.localStorage.getItem(k); return v ? JSON.parse(v) : null; } catch (e) { return null; } }
  function storeSet(k, v) { try { global.localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* 저장 불가 — 메모리만 쓴다 */ } }

  function init(opts) {
    var o = opts || {};
    if (!o.base || !o.serviceId) { throw new Error('ULoginSDK.init: base 와 serviceId 가 필요합니다.'); }
    var apiBase = o.apiBase ? String(o.apiBase).replace(/\/+$/, '') : String(o.base).replace(/\/+$/, '') + API_SUBDIR;
    var serviceId = String(o.serviceId);
    var getToken = typeof o.getToken === 'function' ? o.getToken : function () { return null; };
    var cacheKey = 'ulogin_sdk_' + serviceId + '_config';

    function url(path, query) {
      var q = '';
      if (query) {
        q = Object.keys(query).filter(function (k) { return query[k] !== null && query[k] !== undefined && query[k] !== ''; })
          .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(query[k]); }).join('&');
      }
      return apiBase + path + API_EXT + (q ? '?' + q : '');
    }

    /** 봉투 해석. 304 는 { notModified: true } */
    function request(path, o2) {
      var headers = { 'Accept': 'application/json' };
      var token = getToken();
      if (token) { headers['Authorization'] = 'Bearer ' + token; }
      if (o2.body) { headers['Content-Type'] = 'application/json'; }
      Object.keys(o2.headers || {}).forEach(function (k) { headers[k] = o2.headers[k]; });
      return global.fetch(url(path, o2.query), {
        method: o2.method || 'GET', headers: headers, body: o2.body ? JSON.stringify(o2.body) : null,
        mode: 'cors', credentials: 'omit', cache: 'no-store', keepalive: !!o2.keepalive
      }).catch(function (e) {
        throw ULoginError('NETWORK_ERROR', '서버에 연결할 수 없습니다. (' + (e && e.message ? e.message : 'fetch failed') + ')', 0);
      }).then(function (res) {
        if (res.status === 304) { return { notModified: true }; }
        return res.json().catch(function () {
          throw ULoginError('SERVER_ERROR', '서버 응답을 해석할 수 없습니다.', res.status);
        }).then(function (j) {
          if (!j || j.ok !== true) {
            var e = (j && j.error) || {};
            throw ULoginError(e.code, e.message, res.status, e.field, j ? j.data : null);
          }
          return j.data;
        });
      });
    }
    function post(path, body) { return request(path, { method: 'POST', body: body }); }

    /* ------------------------------------------------ 전역 설정 (§4) */
    var cfg = { values: null, etag: null, versions: null };
    var cached = storeGet(cacheKey);
    if (cached && cached.etag) { cfg = cached; }

    var config = {
      /** 병합·평가된 값. ETag 가 같으면 304 → 저장해 둔 값 */
      get: function () {
        var h = cfg.etag ? { 'If-None-Match': '"' + cfg.etag + '"' } : {};
        return request(P_CONFIG, { method: 'GET', query: { service_id: serviceId }, headers: h }).then(function (d) {
          if (d && d.notModified) { return cfg.values || {}; }
          cfg = { etag: d.etag, versions: d.versions, values: d.values || {} };
          storeSet(cacheKey, cfg);
          return cfg.values;
        });
      },
      /** 메모리 값 하나(get() 이후). 없으면 fallback */
      value: function (key, fallback) {
        return cfg.values && Object.prototype.hasOwnProperty.call(cfg.values, key) ? cfg.values[key] : fallback;
      },
      versions: function () { return cfg.versions; },
      clear: function () { cfg = { values: null, etag: null, versions: null }; try { global.localStorage.removeItem(cacheKey); } catch (e) { /* noop */ } }
    };

    /* ------------------------------------------------ 운영 로그 (§5, user 갈래) */
    var buf = [];
    var timer = null;
    function flush() {
      if (timer) { global.clearTimeout(timer); timer = null; }
      if (!buf.length || !getToken()) { buf = []; return Promise.resolve(null); }
      var batch = buf.splice(0, LOG_BATCH);
      return request(P_LOGS, { method: 'POST', body: { service_id: serviceId, events: batch }, keepalive: true })
        .then(function (d) {
          if (d && d.rejected && d.rejected.length && global.console) { global.console.warn('[ulogin-sdk] 거절된 로그', d.rejected); }
          if (buf.length) { return flush(); }
          return d;
        })
        .catch(function () { buf = []; return null; });   // 실패는 조용히 버린다
    }
    function push(level, event, message, data) {
      var ev = { event_id: uuid4(), ts: new Date().toISOString(), level: level, event: String(event),
        message: message === undefined || message === null ? null : String(message).slice(0, 1000) };
      if (data && typeof data === 'object') { ev.data = data; }
      buf.push(ev);
      if (buf.length >= LOG_BATCH) { flush(); }
      else if (!timer) { timer = global.setTimeout(flush, LOG_INTERVAL_MS); }
    }
    var log = { flush: flush };
    LEVELS.forEach(function (l) { log[l] = function (event, message, data) { push(l, event, message, data); }; });
    if (global.addEventListener) {
      global.addEventListener('pagehide', function () { flush(); });
    }

    /* ------------------------------------------------ 문서 테이블 (§6) */
    function docs(op, collection, extra) {
      var b = { service_id: serviceId, op: op, collection: collection };
      Object.keys(extra || {}).forEach(function (k) { b[k] = extra[k]; });
      return post(P_DOCS, b);
    }
    function needVersion(o3) {
      var v = o3 && o3.expectedVersion;
      if (typeof v !== 'number') { throw ULoginError('VALIDATION_FAILED', 'expectedVersion(문서 version)이 필요합니다.', 0, 'expected_version'); }
      return v;
    }
    function query(name, state) {
      var q = {
        where: function (field, op, value) { return query(name, Object.assign({}, state, { where: state.where.concat([{ field: field, op: op, value: value }]) })); },
        orderBy: function (field, dir) { return query(name, Object.assign({}, state, { order: { field: field, dir: dir || 'asc' } })); },
        limit: function (n) { return query(name, Object.assign({}, state, { limit: n })); },
        startAfter: function (cursor) { return query(name, Object.assign({}, state, { cursor: cursor || null })); },
        /** → { items:[doc], next: 다음 커서|null } */
        get: function () {
          return docs('query', name, { where: state.where, order: state.order, limit: state.limit, cursor: state.cursor })
            .then(function (d) { return { items: d.items || [], next: d.next_cursor || null }; });
        }
      };
      return q;
    }
    var db = {
      collection: function (name) {
        var c = query(name, { where: [], order: null, limit: 20, cursor: null });
        /** 새 문서. 주인은 서버가 로그인 사용자로 정한다 → { doc } */
        c.add = function (data, o3) { return docs('create', name, { doc_id: (o3 && o3.id) || null, data: data }); };
        c.doc = function (id) {
          return {
            get: function () { return docs('get', name, { doc_id: id }).then(function (d) { return d.doc; }); },
            set: function (data, o3) { return docs('set', name, { doc_id: id, data: data, expected_version: needVersion(o3) }).then(function (d) { return d.doc; }); },
            merge: function (data, o3) { return docs('merge', name, { doc_id: id, data: data, expected_version: needVersion(o3) }).then(function (d) { return d.doc; }); },
            'delete': function (o3) { return docs('delete', name, { doc_id: id, expected_version: needVersion(o3) }).then(function (d) { return !!d.deleted; }); }
          };
        };
        return c;
      }
    };

    return { serviceId: serviceId, config: config, log: log, db: db, ULoginError: ULoginError };
  }

  global.ULoginSDK = { init: init, version: '1.9.0' };
})(typeof window !== 'undefined' ? window : this);
