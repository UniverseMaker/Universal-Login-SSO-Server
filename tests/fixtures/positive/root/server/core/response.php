<?php
/**
 * PLANTED: cites a function that is defined nowhere -> chk_phantom_identifiers
 * the real entry point is fixture_no_such_function() -- there is no such thing.
 */
const ERROR_MESSAGES = [
    'AUTH_REQUIRED'     => 'need login',
    'SERVER_ERROR'      => 'oops',
    'VALIDATION_FAILED' => 'bad input',
    'NOT_FOUND'         => 'missing',
    'FORBIDDEN'         => 'no',
    'CONFLICT'          => 'no',
    'RATE_LIMITED'      => 'slow down',
    'CSRF_FAILED'       => 'retry',
    'TOKEN_INVALID'     => 'expired',
    'METHOD_NOT_ALLOWED'=> 'nope',
    'FIXTURE_DEAD_CODE' => 'PLANTED: no server file throws this -> chk_error_codes',
];
