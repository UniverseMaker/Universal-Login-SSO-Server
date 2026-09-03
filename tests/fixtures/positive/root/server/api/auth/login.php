<?php
// PLANTED: cites a line number -> chk_line_citations  (see server/core/response.php:9999)
// PLANTED: FIXTURE_ONLY_CODE is in neither ERROR_MESSAGES table -> chk_error_codes
if (!$ok) {
    throw new AppError('FIXTURE_ONLY_CODE', 400);
}
// PLANTED: 'fail_planted' is not in the schema COMMENT, not in $results, not on any screen
log_login('fail_planted', null, $loginId, $serviceId);
log_login($isReuse ? 'success' : 'fail_credential', 1, 'u', null);
json_out(['user' => [], 'access_token' => 'x']);
