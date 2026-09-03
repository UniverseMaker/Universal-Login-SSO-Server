/* fixture api.js */
var LOGICAL_PATH_ALIASES = {
  '/api/me': '/api/me/index'
};
var ERROR_MESSAGES = {
  AUTH_REQUIRED: 'need login',
  SERVER_ERROR: 'oops',
  VALIDATION_FAILED: 'bad input',
  NOT_FOUND: 'missing',
  FORBIDDEN: 'no',
  CONFLICT: 'no',
  RATE_LIMITED: 'slow down',
  CSRF_FAILED: 'retry',
  TOKEN_INVALID: 'expired',
  METHOD_NOT_ALLOWED: 'nope',
  FIXTURE_CLIENT_ONLY: 'PLANTED: no server file throws this -> chk_error_codes'
};
