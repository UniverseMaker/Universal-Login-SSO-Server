<?php
declare(strict_types=1);

// 이 파일은 표에 있는 코드를 전부 한 번씩 던진다. 표와 코드가 정확히 일치하는 상태 =
// chk_error_codes 가 조용해야 하는 상태다.
if (!$ok)        { throw new AppError('AUTH_REQUIRED', 401); }
if (!$valid)     { throw new AppError('VALIDATION_FAILED', 400); }
if (!$found)     { throw new AppError('NOT_FOUND', 404); }
if (!$allowed)   { throw new AppError('FORBIDDEN', 403); }
if ($clash)      { throw new AppError('CONFLICT', 409); }
if ($tooMany)    { throw new AppError('RATE_LIMITED', 429); }
if ($badCsrf)    { throw new AppError('CSRF_FAILED', 403); }
if ($badToken)   { throw new AppError('TOKEN_INVALID', 400); }
if ($badMethod)  { json_err('METHOD_NOT_ALLOWED', 405); }
if ($boom)       { json_err('SERVER_ERROR', 500); }

// 값 세 개를 전부 쓴다. 스키마 COMMENT, 관리자 필터, 화면 라벨과 일치한다.
log_login('fail_credential', null, $loginId, null);
log_login($ok ? 'success' : 'fail_credential', 1, 'u', null);
log_login('logout', 1, 'u', null);
json_out(['user' => [], 'access_token' => 'x']);
