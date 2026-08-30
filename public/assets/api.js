/* ============================================================================
 * ulogin v1.0.0 — API 호출 헬퍼 (api.js)
 *
 * 이 파일은 로그인 서버 자체 UI(login/register/forgot/reset/mypage/admin)가
 * 쓰는 유일한 HTTP 진입점이다. 화면 코드에서 fetch를 직접 쓰지 않는다.
 *
 * 계약: 내부 API 계약서   (응답 봉투 {ok,data,error})
 * 판정: 리더 판정 D-0     (API_EXT / 쿼리파라미터 규칙)
 *
 * 의존성 없음. Vanilla JS. UTF-8.
 * ========================================================================== */
(function (global) {
  'use strict';

  /* ======================================================================
   * 0. 설정 블록 — 배포 환경에 맞춰 여기 두 값만 바꾼다
   * ==================================================================== */

  /**
   * server/ 디렉토리의 URL. (leader_decisions D-28 — 배포 레이아웃 확정)
   *
   * 배포 레이아웃:
   *   {설치루트}/server/api/...   ← API
   *   {설치루트}/public/*.html    ← 화면 (이 파일을 읽는 쪽)
   *   {설치루트}/examples/*       ← 데모
   *
   * 기본값 '../server' 는 **상대 경로**다. 화면이 `{설치루트}/public/` 직하에 있으므로
   * 브라우저가 항상 `{설치루트}/server/` 로 해소한다.
   * 설치 루트가 도메인 루트가 아니어도(공용 호스팅의 서브디렉토리 배치) 그대로 동작한다.
   *
   * ⚠ 화면(HTML)은 `public/` **직하에만** 둔다. `public/sub/foo.html` 처럼 하위 디렉토리에
   *   화면을 추가하면 이 상대 경로가 `{설치루트}/public/server/...` 로 어긋나 깨진다.
   *
   * 변형 배치: `public/` 의 내용을 도메인 루트에 직접 올렸다면(= 화면이 `/login.html`)
   *   이 상수 한 줄만 `server/` 의 실제 URL 로 바꾼다. 예: '/server' 또는 '/ulogin/server'.
   *   경로를 조립하는 곳은 `buildUrl()` 하나뿐이므로 다른 파일은 고치지 않아도 된다.
   *
   * 끝에 '/' 를 붙이지 않는다.
   */
  var ULOGIN_API_BASE = '../server';

  /**
   * API 파일 확장자. (leader_decisions D-0)
   *   '.php' — 기본값. mod_rewrite 없이도 동작한다.
   *   ''     — .htaccess rewrite가 동작하는 환경에서 확장자를 생략하고 싶을 때.
   * 이 상수는 이 파일 이 한 곳에만 둔다.
   */
  var API_EXT = '.php';

  /* ======================================================================
   * 1. 논리 경로 → 실제 파일 경로 별칭 (D-0)
   *
   * 계약서의 URL 표기는 "논리 경로"다. 실제 파일명이 다른 것만 여기서 바꾼다.
   * 동적 세그먼트(`/{uuid}`)는 전부 쿼리 파라미터로 대체되었으므로
   * 경로에 '{' 가 들어오면 즉시 예외를 던져 실수를 잡는다.
   * ==================================================================== */
  var LOGICAL_PATH_ALIASES = {
    '/api/me': '/api/me/index',                       // 파일은 api/me/index.php
    '/api/me/login-logs': '/api/me/login_logs',
    '/api/auth/password/forgot': '/api/auth/password_forgot',
    '/api/auth/password/reset': '/api/auth/password_reset',
    '/api/auth/email/verify': '/api/auth/email_verify',
    '/api/auth/email/resend': '/api/auth/email_resend'
  };

  /* ======================================================================
   * 2. 에러 코드 → 사용자 메시지 (계약서 2절 표 그대로)
   *
   * 서버가 error.message를 주면 **그쪽이 우선**이다. 이 표는 폴백이다.
   * ==================================================================== */
  var ERROR_MESSAGES = {
    VALIDATION_FAILED: '입력값을 다시 확인해 주세요.',
    AUTH_INVALID_CREDENTIALS: '아이디 또는 비밀번호가 올바르지 않습니다.',
    AUTH_REQUIRED: '로그인이 필요합니다.',
    AUTH_SESSION_EXPIRED: '세션이 만료되었습니다. 다시 로그인해 주세요.',
    AUTH_ACCOUNT_LOCKED: '로그인 시도 횟수를 초과했습니다. 잠시 후 다시 시도해 주세요.',
    AUTH_ACCOUNT_SUSPENDED: '정지된 계정입니다. 관리자에게 문의해 주세요.',
    AUTH_ACCOUNT_WITHDRAWN: '탈퇴한 계정입니다.',
    AUTH_ACCOUNT_DORMANT: '휴면 상태의 계정입니다. 관리자에게 문의해 주세요.',
    AUTH_ACCOUNT_PENDING: '가입 처리 대기 중인 계정입니다.',
    AUTH_EMAIL_UNVERIFIED: '이메일 인증이 필요합니다.',
    AUTH_PASSWORD_MISMATCH: '현재 비밀번호가 올바르지 않습니다.',
    FORBIDDEN: '권한이 없습니다.',
    TIER_INSUFFICIENT: '등급이 부족합니다.',
    NOT_FOUND: '대상을 찾을 수 없습니다.',
    DUPLICATE_USERNAME: '이미 사용 중인 아이디입니다.',
    DUPLICATE_EMAIL: '이미 사용 중인 이메일입니다.',
    DUPLICATE_SERVICE_ID: '이미 사용 중인 서비스 ID입니다.',
    DUPLICATE_SECRET_KEY: '이미 등록된 시크릿 키입니다.',
    POINT_INSUFFICIENT: '포인트가 부족합니다.',
    CONFLICT: '현재 상태에서는 처리할 수 없습니다.',
    METHOD_NOT_ALLOWED: '허용되지 않은 요청 방식입니다.',
    SSO_INVALID_SERVICE: '등록되지 않았거나 비활성화된 서비스입니다.',
    SSO_INVALID_REDIRECT: '허용되지 않은 복귀 주소입니다. 서비스 관리자에게 문의해 주세요.',
    SSO_CODE_INVALID: '인증 코드가 유효하지 않습니다. 다시 로그인해 주세요.',
    SSO_CLIENT_AUTH_FAILED: '서비스 인증에 실패했습니다.',
    TOKEN_INVALID: '링크가 만료되었거나 이미 사용되었습니다. 다시 요청해 주세요.',
    CSRF_FAILED: '보안 토큰이 만료되었습니다. 다시 시도해 주세요.',
    RATE_LIMITED: '요청이 너무 많습니다. 잠시 후 다시 시도해 주세요.',
    REGISTRATION_DISABLED: '현재 회원가입이 중지되어 있습니다.',
    MAINTENANCE_MODE: '점검 중입니다. 잠시 후 다시 이용해 주세요.',
    SERVER_ERROR: '일시적인 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.',
    /* 아래 1개는 서버가 응답하지 않는 **클라이언트 전용 의사 코드**다.
       fetch 자체가 실패(오프라인·CORS·서버 다운)했을 때만 사용한다. */
    NETWORK_ERROR: '서버에 연결할 수 없습니다. 네트워크 상태를 확인해 주세요.'
  };

  /* ======================================================================
   * 3. ApiError
   * ==================================================================== */
  class ApiError extends Error {
    /**
     * @param {string} code    계약서 2절의 표준 에러 코드
     * @param {string} message 사용자에게 보여줄 문구 (서버 message 우선)
     * @param {number} status  HTTP 상태 코드 (네트워크 실패면 0)
     * @param {string|null} field  입력값 오류일 때의 필드명
     * @param {*} data         부가정보 (retry_after / locked_until / required 등)
     */
    constructor(code, message, status, field, data) {
      var _code = code || 'SERVER_ERROR';
      super(message || ERROR_MESSAGES[_code] || '오류가 발생했습니다.');
      this.name = 'ApiError';
      this.code = _code;
      this.status = typeof status === 'number' ? status : 0;
      this.field = field || null;
      this.data = (data === undefined ? null : data);
    }
  }

  /** 코드에 해당하는 사용자 메시지. 서버 메시지가 있으면 그것이 우선. */
  function errorMessage(err) {
    if (err && err.message) { return err.message; }
    var code = (err && err.code) || 'SERVER_ERROR';
    return ERROR_MESSAGES[code] || '오류가 발생했습니다.';
  }

  /* ======================================================================
   * 4. URL 조립 — 경로 조립은 오직 이 함수에서만 한다
   * ==================================================================== */
  function buildQuery(query) {
    if (!query) { return ''; }
    if (typeof query === 'string') {
      return query ? (query.charAt(0) === '?' ? query : '?' + query) : '';
    }
    var usp = new URLSearchParams();
    Object.keys(query).forEach(function (k) {
      var v = query[k];
      if (v === null || v === undefined || v === '') { return; }
      if (Array.isArray(v)) {
        v.forEach(function (item) {
          if (item !== null && item !== undefined && item !== '') { usp.append(k, String(item)); }
        });
      } else if (typeof v === 'boolean') {
        usp.append(k, v ? '1' : '0');
      } else {
        usp.append(k, String(v));
      }
    });
    var s = usp.toString();
    return s ? '?' + s : '';
  }

  /**
   * buildUrl(path, query)
   *   ULOGIN_API_BASE + (별칭 적용된 path) + API_EXT + querystring
   *
   * @param {string} path  '/api/auth/login' 같은 논리/실제 경로 (확장자 없이)
   * @param {object|string} [query]
   * @returns {string}
   */
  function buildUrl(path, query) {
    if (typeof path !== 'string' || !path) {
      throw new ApiError('SERVER_ERROR', 'API 경로가 올바르지 않습니다.', 0, null, null);
    }
    if (path.indexOf('{') !== -1) {
      // D-0: 동적 세그먼트는 전부 쿼리 파라미터로 대체되었다.
      throw new ApiError(
        'SERVER_ERROR',
        'API 경로에 동적 세그먼트를 쓸 수 없습니다. 쿼리 파라미터를 사용하세요: ' + path,
        0, null, null
      );
    }
    var p = path.charAt(0) === '/' ? path : '/' + path;
    // 이미 확장자가 붙어 있으면 중복해서 붙이지 않는다.
    if (API_EXT && p.slice(-API_EXT.length) === API_EXT) {
      p = p.slice(0, -API_EXT.length);
    }
    if (Object.prototype.hasOwnProperty.call(LOGICAL_PATH_ALIASES, p)) {
      p = LOGICAL_PATH_ALIASES[p];
    }
    return ULOGIN_API_BASE + p + API_EXT + buildQuery(query);
  }

  /* ======================================================================
   * 5. 토큰 보관 (메모리 전용)
   *
   * 로그인 서버 자체 UI는 쿠키 세션(ULOGIN_SID)을 쓴다.
   * access_token을 localStorage에 저장하지 않는다 — 외부 앱용 보관 정책은
   * ulogin-client.js가 따로 갖는다.
   * ==================================================================== */
  var _token = null;

  function getToken() { return _token; }
  function setToken(t) { _token = t || null; }
  function clearToken() { _token = null; }

  /* ======================================================================
   * 6. CSRF 토큰 — 메모리 캐시 + 자동 발급 + 1회 자동 재시도
   * ==================================================================== */
  var _csrf = null;
  var _csrfPending = null;

  function getCsrf() { return _csrf; }
  function setCsrf(t) { _csrf = t || null; }
  function clearCsrf() { _csrf = null; _csrfPending = null; }

  /** GET /api/auth/csrf 로 토큰을 받아 메모리에 캐시한다. 동시 호출은 하나로 합친다. */
  function fetchCsrf() {
    if (_csrfPending) { return _csrfPending; }
    _csrfPending = fetch(buildUrl('/api/auth/csrf'), {
      method: 'GET',
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',
      cache: 'no-store'
    }).then(function (res) {
      return res.json().catch(function () { return null; });
    }).then(function (json) {
      _csrfPending = null;
      if (json && json.ok && json.data && json.data.csrf_token) {
        _csrf = json.data.csrf_token;
        return _csrf;
      }
      _csrf = null;
      return null;
    }).catch(function () {
      _csrfPending = null;
      _csrf = null;
      return null;
    });
    return _csrfPending;
  }

  /** 캐시가 있으면 그대로, 없으면 발급받아 반환. force=true면 무조건 재발급. */
  function ensureCsrf(force) {
    if (!force && _csrf) { return Promise.resolve(_csrf); }
    if (force) { _csrf = null; _csrfPending = null; }
    return fetchCsrf();
  }

  /* ======================================================================
   * 7. 세션 만료 처리 — 한 곳에서만 한다
   * ==================================================================== */
  // 미인증 방문이 정상인 페이지. 여기서는 AUTH_REQUIRED/SESSION_EXPIRED 를 받아도
  // login.html 로 튕기지 않는다 (안내 페이지·토큰 기반 페이지가 무한 루프에 빠지는 것을 막는다).
  var AUTH_PAGES = ['login.html', 'register.html', 'forgot.html', 'reset.html', 'verify.html', 'index.html', ''];

  function currentPageName() {
    var p = global.location && global.location.pathname ? global.location.pathname : '';
    var i = p.lastIndexOf('/');
    return (i >= 0 ? p.slice(i + 1) : p) || 'index.html';
  }

  /** 로그인/가입/찾기/재설정 화면인가? (무한 리다이렉트 루프 방지) */
  function isAuthPage() {
    var name = currentPageName();
    for (var i = 0; i < AUTH_PAGES.length; i++) {
      if (name === AUTH_PAGES[i]) { return true; }
    }
    return false;
  }

  var _redirecting = false;

  function handleAuthExpired() {
    clearToken();
    clearCsrf();
    if (isAuthPage() || _redirecting) { return; }
    _redirecting = true;
    try {
      global.location.replace('login.html');
    } catch (e) {
      global.location.href = 'login.html';
    }
  }

  /* ======================================================================
   * 8. api() — 유일한 호출 진입점
   * ==================================================================== */

  /**
   * @param {string} path   논리 경로 ('/api/auth/login')
   * @param {object} [opts]
   *   @param {string}  opts.method  기본 'GET'
   *   @param {object}  opts.body    JSON 직렬화할 본문
   *   @param {object}  opts.query   쿼리 파라미터 (null/undefined/'' 는 자동 제외)
   *   @param {boolean} opts.auth    기본 true. false면 Bearer 헤더를 붙이지 않는다
   * @returns {Promise<*>} 성공 시 json.data
   * @throws {ApiError}
   */
  function api(path, opts) {
    opts = opts || {};
    var method = (opts.method || 'GET').toUpperCase();
    var body = (opts.body === undefined ? null : opts.body);
    var query = opts.query || null;
    var useAuth = (opts.auth === undefined) ? true : !!opts.auth;
    var needsCsrf = (method !== 'GET' && method !== 'HEAD');

    var token = useAuth ? getToken() : null;
    // 계약 1절: Bearer 인증은 CSRF 면제. 쿠키 세션일 때만 헤더를 붙인다.
    var csrfNeeded = needsCsrf && !token;
    var csrfRetried = false;

    function send(csrfToken) {
      var headers = { 'Accept': 'application/json' };
      if (body !== null) { headers['Content-Type'] = 'application/json; charset=utf-8'; }
      if (token) { headers['Authorization'] = 'Bearer ' + token; }
      if (csrfNeeded && csrfToken) { headers['X-CSRF-Token'] = csrfToken; }

      return fetch(buildUrl(path, query), {
        method: method,
        headers: headers,
        body: body !== null ? JSON.stringify(body) : null,
        credentials: 'same-origin',
        cache: 'no-store'
      }).then(function (res) {
        return res.text().then(function (text) {
          return { res: res, text: text };
        });
      }, function () {
        throw new ApiError('NETWORK_ERROR', ERROR_MESSAGES.NETWORK_ERROR, 0, null, null);
      }).then(function (r) {
        var res = r.res;
        var text = r.text;

        if (!text) {
          if (res.ok) { return null; }
          throw new ApiError('SERVER_ERROR', ERROR_MESSAGES.SERVER_ERROR, res.status, null, null);
        }

        var json;
        try {
          json = JSON.parse(text);
        } catch (e) {
          throw new ApiError('SERVER_ERROR', '서버 응답을 해석할 수 없습니다.', res.status, null, null);
        }

        if (!json || typeof json !== 'object' || !('ok' in json)) {
          throw new ApiError('SERVER_ERROR', '서버 응답 형식이 올바르지 않습니다.', res.status, null, null);
        }

        if (json.ok) { return json.data; }

        var e = json.error || {};
        var code = e.code || 'SERVER_ERROR';
        // 서버 메시지가 있으면 그것이 우선. 없으면 코드 표의 문구로 채운다.
        var msg = e.message || ERROR_MESSAGES[code] || '오류가 발생했습니다.';
        throw new ApiError(code, msg, res.status, e.field || null, json.data);
      });
    }

    var chain = csrfNeeded
      ? ensureCsrf(false).then(send)
      : Promise.resolve().then(function () { return send(null); });

    return chain.catch(function (err) {
      if (!(err instanceof ApiError)) {
        throw new ApiError('SERVER_ERROR', ERROR_MESSAGES.SERVER_ERROR, 0, null, null);
      }
      // CSRF_FAILED → 1회만 재발급 후 재시도
      if (err.code === 'CSRF_FAILED' && csrfNeeded && !csrfRetried) {
        csrfRetried = true;
        return ensureCsrf(true).then(send).catch(function (err2) {
          if (!(err2 instanceof ApiError)) {
            throw new ApiError('SERVER_ERROR', ERROR_MESSAGES.SERVER_ERROR, 0, null, null);
          }
          if (err2.code === 'AUTH_SESSION_EXPIRED' || err2.code === 'AUTH_REQUIRED') {
            handleAuthExpired();
          }
          throw err2;
        });
      }
      if (err.code === 'AUTH_SESSION_EXPIRED' || err.code === 'AUTH_REQUIRED') {
        handleAuthExpired();
      }
      throw err;
    });
  }

  /* ======================================================================
   * 9. 편의 함수
   * ==================================================================== */
  function apiGet(path, query, opts) {
    return api(path, Object.assign({}, opts || {}, { method: 'GET', query: query || null }));
  }
  function apiPost(path, body, opts) {
    return api(path, Object.assign({}, opts || {}, { method: 'POST', body: (body === undefined ? {} : body) }));
  }
  function apiPut(path, body, opts) {
    return api(path, Object.assign({}, opts || {}, { method: 'PUT', body: (body === undefined ? {} : body) }));
  }
  function apiDelete(path, query, opts) {
    return api(path, Object.assign({}, opts || {}, { method: 'DELETE', query: query || null }));
  }

  /* ======================================================================
   * 10. 전역 노출
   * ==================================================================== */
  global.ULOGIN_API_BASE = ULOGIN_API_BASE;
  global.API_EXT = API_EXT;
  global.ULOGIN_ERROR_MESSAGES = ERROR_MESSAGES;
  global.ULOGIN_PATH_ALIASES = LOGICAL_PATH_ALIASES;

  global.ApiError = ApiError;
  global.errorMessage = errorMessage;
  global.buildUrl = buildUrl;
  global.buildQuery = buildQuery;

  global.api = api;
  global.apiGet = apiGet;
  global.apiPost = apiPost;
  global.apiPut = apiPut;
  global.apiDelete = apiDelete;

  global.getToken = getToken;
  global.setToken = setToken;
  global.clearToken = clearToken;

  global.getCsrf = getCsrf;
  global.setCsrf = setCsrf;
  global.clearCsrf = clearCsrf;
  global.ensureCsrf = ensureCsrf;

  global.isAuthPage = isAuthPage;
  global.handleAuthExpired = handleAuthExpired;

})(window);
