/*!
 * ulogin-client.js — 로컬/외부 HTML 앱용 ulogin 연동 클라이언트 (의존성 0)
 *
 * 사용법 — 최소 구성
 *   <!-- ?v= 는 캐시 무효화용이다. 지우면 브라우저가 옛 ulogin-client.js 를 최대 1년간
 *        계속 쓴다(자산에 immutable 이 걸려 있어 일반 새로고침으로도 갱신되지 않는다).
 *        값은 {설치루트}/public/version.txt 의 앞부분과 맞춘다. -->
 *   <script src="https://your-host.com/ulogin/public/assets/ulogin-client.js?v=1.3.0"></script>
 *   const ulogin = new UloginClient({
 *     server: 'https://your-host.com/ulogin',        // ulogin 설치 루트 (끝 슬래시 없이)
 *     serviceId: 'local-ai-chat',                    // 관리자 콘솔에 등록한 service_id
 *     redirectUri: location.origin + '/callback.html'// 화이트리스트에 등록한 콜백 주소와 완전 일치
 *   });
 *
 *   ▸ server 는 **설치 루트 한 칸**이다 (버전 폴더를 그대로 올린 위치). 내부에서 파생한다:
 *       API      = {server}/server/api/...        (예: {server}/server/api/auth/exchange.php)
 *       로그인화면 = {server}/public/login.html
 *     레이아웃을 바꿨다면 apiBase / loginUrl 옵션으로 각각 덮어쓴다.
 *   if (!ulogin.isLoggedIn()) ulogin.login();        // → 로그인 서버로 이동
 *   await ulogin.handleCallback();                   // callback.html 에서 1회 호출
 *   const s = await ulogin.getSession();             // 토큰 검증 + payload 재수신
 *   s.payload.ai_api_key                             // 이 파일 어디에도 키가 없다. 서버가 준다
 *   await ulogin.consumePoints(10, { reason: 'usage' });
 *   await ulogin.logout();
 *
 * 주의: crypto.subtle(PKCE)은 HTTPS 또는 localhost에서만 동작한다.
 *       file:// 로 열면 PKCE 없이 진행되고 CORS도 막힌다. `python -m http.server 5173` 으로 열 것.
 */
(function (global) {
  'use strict';

  /* ------------------------------------------------------------------ *
   * 상수
   * ------------------------------------------------------------------ */

  var DEFAULT_API_EXT = '.php';        // leader_decisions D-0. rewrite 환경이면 '' 로 바꾼다
  var DEFAULT_REFRESH_SKEW = 60;       // 만료 N초 전에 미리 refresh

  /* 배포 레이아웃 (leader_decisions D-28) — `server` 는 **설치 루트** 하나다.
   *   {설치루트}/server/api/...   ← API      → apiBase  = server + '/server'
   *   {설치루트}/public/*.html    ← 화면      → loginUrl = server + '/public/login.html'
   *   {설치루트}/examples/*       ← 데모
   * 변형 배치는 apiBase / loginUrl 을 개별 옵션으로 덮어쓴다. */
  var API_SUBDIR = '/server';
  var LOGIN_SUBPATH = '/public/login.html';
  var MAX_TIMEOUT = 2147483000;        // setTimeout 상한

  // 앱 내부 임시값 — 항상 sessionStorage (탭 단위, redirect-flow.md 규정 이름)
  var SS_STATE = 'ulogin_state';
  var SS_VERIFIER = 'ulogin_verifier';
  var SS_RETURN_TO = 'ulogin_return_to';

  // 클라이언트 전용 의사 코드 — 서버는 이 코드를 응답하지 않는다
  var CLIENT_CODES = {
    NETWORK_ERROR: '서버에 연결할 수 없습니다. 네트워크 상태를 확인해 주세요.',
    STATE_MISMATCH: '보안상 요청을 중단했습니다. 다시 로그인해 주세요.',
    CALLBACK_NO_CODE: '인증 코드가 없습니다. 다시 로그인해 주세요.',
    CONFIG_ERROR: '클라이언트 설정이 올바르지 않습니다.',
    NOT_LOGGED_IN: '로그인이 필요합니다.'
  };

  var SERVER_MESSAGES = {
    AUTH_REQUIRED: '로그인이 필요합니다.',
    AUTH_SESSION_EXPIRED: '세션이 만료되었습니다. 다시 로그인해 주세요.',
    SSO_CODE_INVALID: '인증 코드가 유효하지 않습니다. 다시 로그인해 주세요.',
    SSO_INVALID_SERVICE: '등록되지 않았거나 비활성화된 서비스입니다.',
    SSO_INVALID_REDIRECT: '허용되지 않은 복귀 주소입니다.',
    POINT_INSUFFICIENT: '포인트가 부족합니다.',
    RATE_LIMITED: '요청이 너무 많습니다. 잠시 후 다시 시도해 주세요.',
    SERVER_ERROR: '일시적인 오류가 발생했습니다.'
  };

  /* ------------------------------------------------------------------ *
   * UloginError
   * ------------------------------------------------------------------ */

  function UloginError(code, message, status, data, field) {
    var msg = message || CLIENT_CODES[code] || SERVER_MESSAGES[code] || '오류가 발생했습니다.';
    var err = Error.call(this, msg);
    this.name = 'UloginError';
    this.message = msg;
    this.code = code || 'SERVER_ERROR';
    this.status = typeof status === 'number' ? status : 0;
    this.data = data === undefined ? null : data;
    this.field = field === undefined ? null : field;
    if (err.stack) this.stack = err.stack;
    else if (Error.captureStackTrace) Error.captureStackTrace(this, UloginError);
  }
  UloginError.prototype = Object.create(Error.prototype);
  UloginError.prototype.constructor = UloginError;

  /* ------------------------------------------------------------------ *
   * 저장소 (localStorage / sessionStorage / memory)
   * ------------------------------------------------------------------ */

  function memoryStore() {
    var bag = {};
    return {
      kind: 'memory',
      getItem: function (k) { return Object.prototype.hasOwnProperty.call(bag, k) ? bag[k] : null; },
      setItem: function (k, v) { bag[k] = String(v); },
      removeItem: function (k) { delete bag[k]; }
    };
  }

  function pickStore(kind) {
    if (kind === 'memory') return memoryStore();
    var name = kind === 'sessionStorage' ? 'sessionStorage' : 'localStorage';
    try {
      var s = global[name];
      var probe = '__ulogin_probe__';
      s.setItem(probe, '1');
      s.removeItem(probe);
      return {
        kind: name,
        getItem: function (k) { return s.getItem(k); },
        setItem: function (k, v) { s.setItem(k, String(v)); },
        removeItem: function (k) { s.removeItem(k); }
      };
    } catch (e) {
      warn('저장소(' + name + ')를 쓸 수 없어 메모리 저장으로 전환합니다. 새로고침하면 로그아웃됩니다.');
      return memoryStore();
    }
  }

  function sessionScratch() {
    try {
      var s = global.sessionStorage;
      s.setItem('__ulogin_probe__', '1');
      s.removeItem('__ulogin_probe__');
      return s;
    } catch (e) {
      warn('sessionStorage를 쓸 수 없습니다. state/PKCE 검증이 불가능해 로그인 복귀가 실패합니다.');
      return memoryStore();
    }
  }

  /* ------------------------------------------------------------------ *
   * 유틸
   * ------------------------------------------------------------------ */

  function warn(msg) {
    if (global.console && global.console.warn) global.console.warn('[ulogin] ' + msg);
  }

  function hasWebCrypto() {
    return !!(global.crypto && typeof global.crypto.getRandomValues === 'function');
  }

  function hasSubtle() {
    return !!(global.crypto && global.crypto.subtle && typeof global.crypto.subtle.digest === 'function');
  }

  function randomBytes(n) {
    var out = new Uint8Array(n);
    if (hasWebCrypto()) {
      global.crypto.getRandomValues(out);
      return out;
    }
    warn('crypto.getRandomValues가 없어 예측 가능한 난수로 대체합니다. 보안 컨텍스트(HTTPS/localhost)에서 실행하세요.');
    for (var i = 0; i < n; i++) out[i] = Math.floor(Math.random() * 256);
    return out;
  }

  function b64url(bytes) {
    var arr = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);
    var bin = '';
    for (var i = 0; i < arr.length; i++) bin += String.fromCharCode(arr[i]);
    return global.btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }

  function randomToken(byteLen) {
    return b64url(randomBytes(byteLen));
  }

  function uuidv4() {
    var b = randomBytes(16);
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    var hex = [];
    for (var i = 0; i < 16; i++) hex.push((b[i] + 0x100).toString(16).slice(1));
    return hex.slice(0, 4).join('') + '-' + hex.slice(4, 6).join('') + '-' +
           hex.slice(6, 8).join('') + '-' + hex.slice(8, 10).join('') + '-' + hex.slice(10, 16).join('');
  }

  function makePkce() {
    var verifier = randomToken(48);
    if (!hasSubtle()) {
      // 보안 컨텍스트(HTTPS/localhost)가 아니면 crypto.subtle이 없다 → PKCE 생략
      warn('crypto.subtle을 쓸 수 없어 PKCE 없이 진행합니다 (file:// 또는 비보안 컨텍스트). ' +
           'HTTPS 또는 http://localhost 로 열면 PKCE가 적용됩니다.');
      return Promise.resolve({ verifier: null, challenge: null });
    }
    return global.crypto.subtle
      .digest('SHA-256', new global.TextEncoder().encode(verifier))
      .then(function (digest) { return { verifier: verifier, challenge: b64url(digest) }; })
      .catch(function () {
        warn('PKCE 챌린지 생성에 실패해 PKCE 없이 진행합니다.');
        return { verifier: null, challenge: null };
      });
  }

  function buildQuery(obj) {
    var parts = [];
    for (var k in obj) {
      if (!Object.prototype.hasOwnProperty.call(obj, k)) continue;
      var v = obj[k];
      if (v === null || v === undefined || v === '') continue;
      if (typeof v === 'boolean') v = v ? '1' : '0';
      parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
    }
    return parts.length ? '?' + parts.join('&') : '';
  }

  function compact(obj) {
    var out = {};
    for (var k in obj) {
      if (!Object.prototype.hasOwnProperty.call(obj, k)) continue;
      if (obj[k] === undefined || obj[k] === null) continue;
      out[k] = obj[k];
    }
    return out;
  }

  // 앱 내부 경로만 허용. 절대 URL·프로토콜 상대 URL·스킴은 전부 버린다
  function guardReturnTo(value, fallback) {
    var fb = fallback || '/';
    if (typeof value !== 'string' || value === '') return fb;
    if (/[\x00-\x1F\x7F]/.test(value)) return fb;    // 제어문자(개행 삽입 등) 차단
    var v = value.replace(/\\/g, '/');
    if (v.charAt(0) !== '/') return fb;
    if (v.charAt(1) === '/') return fb;              // //evil.com
    return v;
  }

  function defaultDeviceLabel() {
    try {
      var ua = global.navigator && global.navigator.userAgent ? global.navigator.userAgent : '';
      var browser = /Edg\//.test(ua) ? 'Edge'
        : /OPR\//.test(ua) ? 'Opera'
        : /Chrome\//.test(ua) ? 'Chrome'
        : /Safari\//.test(ua) ? 'Safari'
        : /Firefox\//.test(ua) ? 'Firefox' : 'Browser';
      var os = /Windows/.test(ua) ? 'Windows'
        : /Macintosh|Mac OS/.test(ua) ? 'macOS'
        : /Android/.test(ua) ? 'Android'
        : /iPhone|iPad/.test(ua) ? 'iOS'
        : /Linux/.test(ua) ? 'Linux' : 'Unknown';
      return browser + ' / ' + os;
    } catch (e) {
      return null;
    }
  }

  /* ------------------------------------------------------------------ *
   * UloginClient
   * ------------------------------------------------------------------ */

  function UloginClient(options) {
    if (!(this instanceof UloginClient)) return new UloginClient(options);
    var o = options || {};

    if (!o.server) throw new UloginError('CONFIG_ERROR', 'server(ulogin 설치 루트 URL)는 필수입니다.');
    if (!o.serviceId) throw new UloginError('CONFIG_ERROR', 'serviceId는 필수입니다.');
    if (!o.redirectUri) throw new UloginError('CONFIG_ERROR', 'redirectUri는 필수입니다.');

    /* server = ulogin **설치 루트** (버전 폴더를 그대로 올린 그 위치).
     * 예: 'https://example.com/ulogin'  → API  https://example.com/ulogin/server/api/...
     *                                    → 로그인 https://example.com/ulogin/public/login.html
     * 여기서 두 값을 파생시킨다. 변형 배치는 apiBase / loginUrl 로 개별 덮어쓰기. (D-28) */
    this.server = String(o.server).replace(/\/+$/, '');
    this.apiBase = o.apiBase ? String(o.apiBase).replace(/\/+$/, '') : (this.server + API_SUBDIR);

    if (o.loginUrl) {
      this.loginUrl = String(o.loginUrl);
    } else if (o.loginPath) {
      // 하위 호환: 예전 loginPath 옵션. 기본값은 없어졌고 명시했을 때만 쓴다.
      warn("loginPath 옵션은 폐기되었습니다. loginUrl(전체 URL) 을 쓰세요. 지금은 server + loginPath 로 조립합니다.");
      this.loginUrl = this.server + String(o.loginPath);
    } else {
      this.loginUrl = this.server + LOGIN_SUBPATH;
    }

    this.serviceId = String(o.serviceId);
    this.redirectUri = String(o.redirectUri);
    this.apiExt = o.apiExt === undefined ? DEFAULT_API_EXT : String(o.apiExt);
    this.refreshSkew = typeof o.refreshSkew === 'number' ? o.refreshSkew : DEFAULT_REFRESH_SKEW;
    this.deviceLabel = o.deviceLabel === undefined ? defaultDeviceLabel() : o.deviceLabel;

    this._storeKind = o.storage || 'localStorage';
    this._store = pickStore(this._storeKind);
    this._scratch = sessionScratch();

    var prefix = o.storagePrefix === undefined ? ('ulogin_' + this.serviceId + '_') : String(o.storagePrefix);
    this.keys = {
      accessToken: prefix + 'access_token',
      refreshToken: prefix + 'refresh_token',
      expiresAt: prefix + 'expires_at',
      sessionId: prefix + 'session_id'
    };

    // 메모리 전용 상태 — 저장소에 절대 쓰지 않는다
    this._payload = null;
    this._user = null;
    this._session = null;

    this._refreshPromise = null;
    this._pending = null;          // state 검증을 통과한 code (교환 대기). 메모리 전용
    this._timer = null;
    this._expireHandlers = [];
    this._expiredNotified = false;

    this._scheduleRefresh();
  }

  /* ---------- 저장소 접근 ---------- */

  UloginClient.prototype._get = function (key) {
    try { return this._store.getItem(key); } catch (e) { return null; }
  };

  UloginClient.prototype._set = function (key, value) {
    try {
      if (value === null || value === undefined || value === '') this._store.removeItem(key);
      else this._store.setItem(key, String(value));
    } catch (e) { /* quota/private mode */ }
  };

  UloginClient.prototype.getAccessToken = function () {
    return this._get(this.keys.accessToken);
  };

  UloginClient.prototype.getExpiresAt = function () {
    var v = parseInt(this._get(this.keys.expiresAt), 10);
    return isNaN(v) ? 0 : v;
  };

  UloginClient.prototype.getSessionId = function () {
    return this._get(this.keys.sessionId);
  };

  /* ---------- HTTP ---------- */

  // apiBase(= {설치루트}/server) + 논리경로 + apiExt + querystring   (D-28)
  UloginClient.prototype._url = function (logicalPath, query) {
    var p = String(logicalPath).replace(/\.php$/, '');
    return this.apiBase + p + this.apiExt + buildQuery(query || null);
  };

  UloginClient.prototype._request = function (logicalPath, opts) {
    var o = opts || {};
    var method = o.method || 'GET';
    var url = this._url(logicalPath, o.query);
    var headers = { 'Accept': 'application/json' };
    if (o.body) headers['Content-Type'] = 'application/json';
    if (o.token) headers['Authorization'] = 'Bearer ' + o.token;

    return global.fetch(url, {
      method: method,
      headers: headers,
      body: o.body ? JSON.stringify(o.body) : null,
      mode: 'cors',
      cache: 'no-store',
      credentials: 'omit'   // Bearer 전용. 쿠키를 보내지 않으므로 CSRF 대상이 아니다
    }).catch(function (e) {
      throw new UloginError('NETWORK_ERROR',
        '서버에 연결할 수 없습니다. (' + (e && e.message ? e.message : 'fetch failed') + ')', 0);
    }).then(function (res) {
      return res.json().catch(function () {
        throw new UloginError('SERVER_ERROR', '서버 응답을 해석할 수 없습니다.', res.status);
      }).then(function (json) {
        if (!json || json.ok !== true) {
          var e = (json && json.error) || {};
          throw new UloginError(e.code || 'SERVER_ERROR', e.message, res.status,
            (json && json.data) || null, e.field || null);
        }
        return json.data;
      });
    });
  };

  /* ---------- 상태 적용/삭제 ---------- */

  UloginClient.prototype._applyAuth = function (data) {
    if (!data) return data;

    if (data.access_token) this._set(this.keys.accessToken, data.access_token);
    if (data.refresh_token) this._set(this.keys.refreshToken, data.refresh_token);

    var expiresAt = 0;
    if (typeof data.expires_in === 'number') {
      // login/exchange/refresh 는 TTL, GET /api/auth/session 은 "남은 초".
      // 어느 쪽이든 지금부터 그 초 뒤가 만료 시각이다.
      expiresAt = Date.now() + data.expires_in * 1000;
    } else if (data.session && data.session.expires_at) {
      var t = Date.parse(data.session.expires_at);
      if (!isNaN(t)) expiresAt = t;
    }
    if (expiresAt) this._set(this.keys.expiresAt, expiresAt);

    if (data.session) {
      this._session = data.session;
      if (data.session.session_id) this._set(this.keys.sessionId, data.session.session_id);
    }
    if (data.user) this._user = data.user;
    if (Object.prototype.hasOwnProperty.call(data, 'payload')) this._payload = data.payload || {};

    this._expiredNotified = false;
    this._scheduleRefresh();
    return data;
  };

  UloginClient.prototype._clearState = function () {
    this._set(this.keys.accessToken, null);
    this._set(this.keys.refreshToken, null);
    this._set(this.keys.expiresAt, null);
    this._set(this.keys.sessionId, null);
    this._payload = null;
    this._user = null;
    this._session = null;
    this._refreshPromise = null;
    this._pending = null;
    if (this._timer) { global.clearTimeout(this._timer); this._timer = null; }
  };

  UloginClient.prototype._notifyExpire = function (err) {
    if (this._expiredNotified) return;
    this._expiredNotified = true;
    var list = this._expireHandlers.slice();
    for (var i = 0; i < list.length; i++) {
      try { list[i](err || new UloginError('AUTH_SESSION_EXPIRED')); }
      catch (e) { warn('onExpire 콜백에서 예외가 발생했습니다: ' + (e && e.message)); }
    }
  };

  UloginClient.prototype._scheduleRefresh = function () {
    if (this._timer) { global.clearTimeout(this._timer); this._timer = null; }
    var self = this;
    if (!this._get(this.keys.refreshToken)) return;
    var expiresAt = this.getExpiresAt();
    if (!expiresAt) return;
    var delay = expiresAt - Date.now() - this.refreshSkew * 1000;
    if (delay < 0) delay = 0;
    if (delay > MAX_TIMEOUT) delay = MAX_TIMEOUT;
    this._timer = global.setTimeout(function () {
      self._timer = null;
      self._refresh().catch(function () { /* _refresh 안에서 onExpire 처리됨 */ });
    }, delay);
  };

  /* ---------- refresh (동시 다중 호출 방지) ---------- */

  UloginClient.prototype._refresh = function () {
    var self = this;
    if (this._refreshPromise) return this._refreshPromise;   // 진행 중인 것을 재사용

    var refreshToken = this._get(this.keys.refreshToken);
    if (!refreshToken) {
      var err = new UloginError('AUTH_SESSION_EXPIRED');
      this._clearState();
      this._notifyExpire(err);
      return Promise.reject(err);
    }

    this._refreshPromise = this._request('/api/auth/refresh', {
      method: 'POST',
      body: { refresh_token: refreshToken }
    }).then(function (data) {
      self._refreshPromise = null;
      return self._applyAuth(data);
    }).catch(function (e) {
      self._refreshPromise = null;
      if (e instanceof UloginError && e.code === 'NETWORK_ERROR') throw e;  // 일시적 실패는 상태를 비우지 않는다
      self._clearState();
      self._notifyExpire(e);
      throw e;
    });

    return this._refreshPromise;
  };

  // 유효한 access_token을 보장한다. 만료 60초 전이면 미리 갱신
  UloginClient.prototype._ensureToken = function () {
    var token = this.getAccessToken();
    if (!token) return Promise.reject(new UloginError('NOT_LOGGED_IN', null, 401));
    var expiresAt = this.getExpiresAt();
    if (expiresAt && expiresAt - Date.now() <= this.refreshSkew * 1000) {
      var self = this;
      return this._refresh().then(function () { return self.getAccessToken(); });
    }
    return Promise.resolve(token);
  };

  // 인증 필요 요청 + 401 1회 자동 복구
  UloginClient.prototype._authed = function (logicalPath, opts) {
    var self = this;
    var o = opts || {};
    return this._ensureToken().then(function (token) {
      o.token = token;
      return self._request(logicalPath, o);
    }).catch(function (e) {
      var expired = e instanceof UloginError &&
        (e.code === 'AUTH_SESSION_EXPIRED' || e.code === 'AUTH_REQUIRED');
      if (!expired || o._retried) throw e;
      o._retried = true;
      return self._refresh().then(function () {
        o.token = self.getAccessToken();
        return self._request(logicalPath, o);
      });
    });
  };

  /* ================================================================== *
   * 공개 API
   * ================================================================== */

  /**
   * login(opts?) — state·PKCE 생성 후 로그인 서버로 이동한다.
   * opts: { returnTo?: '/앱내부경로', prompt?: 'login' }
   * 반환: Promise<void> (이동하므로 사실상 resolve되지 않는다)
   */
  UloginClient.prototype.login = function (opts) {
    var self = this;
    var o = opts || {};
    return makePkce().then(function (pkce) {
      var state = randomToken(32);
      var returnTo = o.returnTo !== undefined
        ? o.returnTo
        : (global.location.pathname + global.location.search + global.location.hash);

      try {
        self._scratch.setItem(SS_STATE, state);
        if (pkce.verifier) self._scratch.setItem(SS_VERIFIER, pkce.verifier);
        else self._scratch.removeItem(SS_VERIFIER);
        self._scratch.setItem(SS_RETURN_TO, guardReturnTo(returnTo, '/'));
      } catch (e) {
        warn('sessionStorage 저장 실패 — 복귀 시 state 검증이 불가능합니다.');
      }

      var url = self.loginUrl + buildQuery({
        service_id: self.serviceId,
        redirect_uri: self.redirectUri,
        state: state,
        code_challenge: pkce.challenge,
        prompt: o.prompt || null
      });
      global.location.assign(url);
    });
  };

  /**
   * handleCallback(opts?) — 콜백 페이지에서 1회 호출.
   * state 대조 → POST /api/auth/exchange → URL 정리 → return_to 복귀.
   * opts: { redirect?: boolean(기본 true) }
   * 반환: Promise<{ user, session, payload, state, access_token, ... }>
   */
  UloginClient.prototype.handleCallback = function (opts) {
    var self = this;
    var o = opts || {};

    return Promise.resolve().then(function () {
      var params = new global.URLSearchParams(global.location.search);
      var code = params.get('code');
      var state = params.get('state');

      var savedState = null, verifier = null;
      try {
        savedState = self._scratch.getItem(SS_STATE);
        verifier = self._scratch.getItem(SS_VERIFIER);
        self._scratch.removeItem(SS_STATE);
        self._scratch.removeItem(SS_VERIFIER);
      } catch (e) { /* ignore */ }

      if (!code) throw new UloginError('CALLBACK_NO_CODE', null, 400);
      // ★ 여기서 막지 못하면 세션 주입이 가능하다. 불일치면 교환하지 않는다
      if (!savedState || !state || state !== savedState) {
        throw new UloginError('STATE_MISMATCH', null, 400);
      }

      // state 대조를 통과한 code/verifier만 메모리에 남긴다 (네트워크 실패 시 retryCallback용)
      self._pending = { code: code, verifier: verifier };
      return self._exchange(o);
    });
  };

  /**
   * retryCallback(opts?) — exchange가 네트워크 오류로 실패했을 때만 쓴다.
   * state 대조를 이미 통과한 code를 메모리에서 재사용한다. code 수명은 60초.
   * 교환할 것이 없으면 UloginError('CALLBACK_NO_CODE')를 던진다.
   */
  UloginClient.prototype.retryCallback = function (opts) {
    if (!this._pending) return Promise.reject(new UloginError('CALLBACK_NO_CODE', null, 400));
    return this._exchange(opts || {});
  };

  /** 교환 가능한 code가 메모리에 남아 있는가 (재시도 버튼 표시 판단용) */
  UloginClient.prototype.canRetryCallback = function () {
    return !!this._pending;
  };

  UloginClient.prototype._exchange = function (o) {
    var self = this;
    var doRedirect = o.redirect !== false;
    var pending = this._pending || {};

    return this._request('/api/auth/exchange', {
      method: 'POST',
      body: compact({
        code: pending.code,
        service_id: self.serviceId,
        redirect_uri: self.redirectUri,
        code_verifier: pending.verifier || undefined,
        device_label: self.deviceLabel || undefined
      })
    }).catch(function (e) {
      // 네트워크 실패는 code를 소모하지 않았을 가능성이 있으므로 재시도 여지를 남긴다.
      // 그 외(SSO_CODE_INVALID 등)는 재시도해도 같은 결과이므로 즉시 버린다.
      if (!(e instanceof UloginError) || e.code !== 'NETWORK_ERROR') self._pending = null;
      throw e;
    }).then(function (data) {
      self._pending = null;
      self._applyAuth(data);

      // 히스토리에 code/state가 남지 않게 URL을 정리한다
      try {
        var p = new global.URLSearchParams(global.location.search);
        p.delete('code');
        p.delete('state');
        var q = p.toString();
        global.history.replaceState(null, '',
          global.location.pathname + (q ? '?' + q : '') + global.location.hash);
      } catch (e) { /* file:// 등에서 실패할 수 있다 */ }

      if (doRedirect) {
        var back = '/';
        try {
          back = guardReturnTo(self._scratch.getItem(SS_RETURN_TO), '/');
          self._scratch.removeItem(SS_RETURN_TO);
        } catch (e) { /* ignore */ }
        if (self._store.kind === 'memory') {
          warn("storage:'memory'는 페이지 이동 시 토큰이 사라집니다. redirect:false 로 쓰거나 storage를 바꾸세요.");
        }
        global.location.replace(back);
      }
      return data;
    });
  };

  /**
   * getSession() — GET /api/auth/session 으로 재검증하고 payload를 갱신한다.
   * 응답의 expires_in은 TTL이 아니라 "남은 초"다.
   * 반환: Promise<{ user, session, expires_in, payload }>
   */
  UloginClient.prototype.getSession = function () {
    var self = this;
    return this._authed('/api/auth/session', { method: 'GET' }).then(function (data) {
      return self._applyAuth(data);
    }).catch(function (e) {
      if (e instanceof UloginError &&
          (e.code === 'AUTH_SESSION_EXPIRED' || e.code === 'AUTH_REQUIRED' || e.code === 'NOT_LOGGED_IN')) {
        self._clearState();
        self._notifyExpire(e);
      }
      throw e;
    });
  };

  /** getPayload() — 메모리에 있는 시크릿 payload. 미수신이면 null */
  UloginClient.prototype.getPayload = function () {
    return this._payload;
  };

  /** getUser() — 메모리에 있는 user 객체. 미수신이면 null */
  UloginClient.prototype.getUser = function () {
    return this._user;
  };

  /** getSessionInfo() — 메모리에 있는 session 객체. 미수신이면 null */
  UloginClient.prototype.getSessionInfo = function () {
    return this._session;
  };

  /**
   * consumePoints(amount, opts?) — POST /api/points/consume
   * opts: { reason, serviceId, refType, refId, memo, idempotencyKey }
   * 반환: Promise<{ points, delta, balance_after, total_points_spent, log_id, idempotent_replay, created_at }>
   * 부족하면 UloginError(code='POINT_INSUFFICIENT', data={points, required, shortfall})
   */
  UloginClient.prototype.consumePoints = function (amount, opts) {
    var self = this;
    var o = opts || {};
    var amt = Number(amount);
    if (!isFinite(amt) || amt <= 0 || Math.floor(amt) !== amt) {
      return Promise.reject(new UloginError('VALIDATION_FAILED', 'amount는 1 이상의 정수여야 합니다.', 400, null, 'amount'));
    }
    return this._authed('/api/points/consume', {
      method: 'POST',
      body: compact({
        amount: amt,
        reason: o.reason || 'usage',
        service_id: o.serviceId || self.serviceId,
        ref_type: o.refType || undefined,
        ref_id: o.refId || undefined,
        memo: o.memo || undefined,
        idempotency_key: o.idempotencyKey || uuidv4()
      })
    }).then(function (data) {
      if (self._user && data && typeof data.points === 'number') {
        self._user.points = data.points;
        if (typeof data.total_points_spent === 'number') self._user.total_points_spent = data.total_points_spent;
      }
      return data;
    });
  };

  /**
   * logout(opts?) — POST /api/auth/logout 후 로컬 상태를 비운다.
   * opts: { allDevices?: boolean }
   * 세션이 이미 만료돼 401이 와도 로그아웃 성공으로 처리한다(계약 4-3).
   */
  UloginClient.prototype.logout = function (opts) {
    var self = this;
    var o = opts || {};
    var token = this.getAccessToken();
    var done = function (data) {
      self._clearState();
      self._expiredNotified = true;   // 의도적 로그아웃은 onExpire를 부르지 않는다
      return data;
    };
    if (!token) return Promise.resolve(done({ revoked_count: 0, all_devices: !!o.allDevices }));

    return this._request('/api/auth/logout', {
      method: 'POST',
      token: token,
      body: { all_devices: !!o.allDevices }
    }).then(done).catch(function (e) {
      if (e instanceof UloginError &&
          (e.code === 'AUTH_REQUIRED' || e.code === 'AUTH_SESSION_EXPIRED')) {
        return done({ revoked_count: 0, all_devices: !!o.allDevices });
      }
      self._clearState();
      self._expiredNotified = true;
      throw e;
    });
  };

  /** isLoggedIn() — 유효한 access_token(또는 refresh 가능 상태)을 갖고 있는가 */
  UloginClient.prototype.isLoggedIn = function () {
    if (!this.getAccessToken()) return false;
    var expiresAt = this.getExpiresAt();
    if (!expiresAt) return true;
    if (Date.now() < expiresAt) return true;
    return !!this._get(this.keys.refreshToken);   // 만료됐어도 refresh가 있으면 복구 가능
  };

  /** onExpire(cb) — 세션 만료 시 호출. 해제 함수를 반환한다 */
  UloginClient.prototype.onExpire = function (cb) {
    if (typeof cb !== 'function') return function () {};
    var self = this;
    this._expireHandlers.push(cb);
    return function () {
      var i = self._expireHandlers.indexOf(cb);
      if (i >= 0) self._expireHandlers.splice(i, 1);
    };
  };

  /** destroy() — 타이머·핸들러 정리 (SPA 언마운트용). 토큰은 지우지 않는다 */
  UloginClient.prototype.destroy = function () {
    if (this._timer) { global.clearTimeout(this._timer); this._timer = null; }
    this._expireHandlers.length = 0;
  };

  /* ------------------------------------------------------------------ *
   * export
   * ------------------------------------------------------------------ */

  UloginClient.Error = UloginError;

  /* version — **이 스크립트 파일 자체의 버전**이다. 서버 배포본(버전 폴더)의 버전이 아니다.
   * 두 숫자는 일부러 따로 움직인다. 아래가 그 규칙이다.
   *
   *   1) 이 파일의 코드가 실제로 바뀔 때만 올린다.
   *      배포본 버전이 올라갔다고 따라 올리지 않는다. 코드가 같은데 숫자만 다르면
   *      "이 값이 다르다 = 파일이 다르다"는 판별력이 사라진다.
   *   2) 서버 배포본의 버전은 이 파일로 알 수 없다. 이 파일은 로컬 앱으로 복사돼
   *      원래 배포본과 무관하게 오래 남을 수 있고, **정상 설정에서는** v1.0.0 과 v1.1.0 의
   *      API·응답 shape 이 완전히 동일해서 HTTP 로도 구분되지 않는다.
   *      이 한정은 문자 그대로 필요하다 — 구분되는 경로가 아예 없다는 뜻이 아니다.
   *      잘못된 설정·경계 입력·PHP 7.2 배포에서는 상태코드나 `Set-Cookie` 가 갈린다.
   *      그 경로의 목록은 CHANGELOG.md 의 `## v1.1.0_20260826` 절과 README.md 의
   *      `## 잘못된 설정은 조용히 넘어가지 않는다 (fail-closed)` 절에 있다.
   *      **그래도 배포본 판별에는 쓸 수 없다** — 정상 배포에서는 나타나지 않기 때문이다.
   *      같은 문장이 CHANGELOG.md 의 `### Changed — 클라이언트 파일` 절에 같은 한정으로
   *      적혀 있다. 한쪽만 고치지 마라.
   *      → 배포본 버전 확인 방법은 AI_INTEGRATION_GUIDE.md `## 이 문서의 버전` 3) 참조
   *        (`server/core/request.php` 의 `send_cookie` 존재 여부).
   *   3) 그래서 이 값을 위한 별도의 "대상 서버 버전" 속성은 두지 않는다.
   *      클라이언트가 알 수 없는 값을 공개 API 로 내놓으면 거짓말이 된다.
   *
   * 현재값 '1.0.0' 의 근거: v1.1.0 라운드는 서버의 PHP 7.2 호환 작업이었고,
   * 이 파일의 **실행 코드가 v1.0.0_20260825 의 같은 파일과 동일하다 — 다른 것은 주석뿐이다.**
   * 그래서 규칙 1) 에 따라 올리지 않았다. 파일 크기와 해시는 이 주석 때문에 서로 다르다.
   *
   *   검증 방법(재실행 가능): 두 파일에서 주석과 공백을 제거하고 남은 실행 라인만 비교하면
   *   diff 가 0 이다. 2026-08-26 실측 — 양쪽 578 실행 라인, 차이 0 행.
   *   ※ 크기·바이트 수·해시로 판단하지 마라. 규칙 1) 이 묻는 것은 "코드가 바뀌었는가" 이고,
   *      주석 한 줄만 고쳐도 크기와 해시는 달라진다.
   *
   * 주석 작성 경고 — **이 파일 자신의 상태를 단언하는 문장을 주석에 새로 넣지 마라.**
   * ("이 파일은 v1.0.0 과 …" 류의 대조 단언, "N 번째 줄에 있다" 같은 줄 번호 단언,
   *  "크기 N 바이트" / "해시는 … 이다" 같은 크기·해시 단언.)
   * 그런 문장은 그것을 써 넣는 편집 자체가 거짓으로 만든다. 실제로 그렇게 됐다 —
   * 위 문단의 앞 판본이 두 파일이 바이트까지 같다고 단언했는데,
   * 그 문장을 추가한 편집이 그 순간 두 파일의 크기를 2,171 바이트 벌려 놓았다(D-73).
   * 상태를 남겨야 한다면 위처럼 **재실행 가능한 검증 방법과 측정 날짜를 함께** 적으라.
   * 그러면 값이 낡아도 다음 사람이 다시 재어 확인할 수 있고, 단언이 아니라 기록이 된다.
   *
   * AI_INTEGRATION_GUIDE.md 가 이 기대값을 문서화하고 버전 감지 명령의 기준으로 쓴다.
   *
   * 다음 담당자에게: 이 파일의 코드를 실제로 고쳤다면 아래 값을 올리고,
   * AI_INTEGRATION_GUIDE.md 의 기대값 4곳(11-1절 서술 / `## 이 문서의 버전` 표 행 /
   * 그 아래 주의 블록 / 확인 명령 1) 의 기대 출력)과 CHANGELOG.md 를 같은 라운드에서 함께 고쳐라.
   * 아래 대입문은 원격에서 grep 한 줄로 읽히는 계약이다(AI 가이드 확인 명령 1). 한 줄에
   * 홑따옴표 리터럴 하나로 대입하는 형식을 유지하고, 같은 형태를 주석에 중복시키지 마라
   * — 주석이 함께 매칭되면 그 명령의 출력이 두 줄이 된다. */
  UloginClient.version = '1.0.0';

  global.UloginClient = UloginClient;
  global.UloginError = UloginError;

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = { UloginClient: UloginClient, UloginError: UloginError };
  }
})(typeof window !== 'undefined' ? window : this);
