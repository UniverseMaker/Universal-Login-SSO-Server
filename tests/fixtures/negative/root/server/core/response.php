<?php
declare(strict_types=1);

/**
 * 합법 파일. 검사기를 속이기 쉬운 것만 모았다.
 *
 * 주석 안에서 json_out() 과 json_err() 를 인용한다 - 둘 다 아래에 정의돼 있으므로
 * 팬텀이 아니다. 주석 안의 'quoted string' 과 "double quoted" 도 코드가 아니다.
 * v1.2.0 에서 캐시 헤더를 추가했다 - 과거 릴리스를 가리키는 정당한 서술이다.
 * 문서 URL: https://example.invalid/docs -- 문자열 밖의 // 도 여기 있다.
 */
const ERROR_MESSAGES = [
    'AUTH_REQUIRED'      => 'need login',
    'SERVER_ERROR'       => 'oops',
    'VALIDATION_FAILED'  => 'bad input',
    'NOT_FOUND'          => 'missing',
    'FORBIDDEN'          => 'no',
    'CONFLICT'           => 'no',
    'RATE_LIMITED'       => 'slow down',
    'CSRF_FAILED'        => 'retry',
    'TOKEN_INVALID'      => 'expired',
    'METHOD_NOT_ALLOWED' => 'nope',
];

function json_out($data = null, int $status = 200): void
{
    // 배열 리터럴의 trailing comma 는 5.x 부터 합법이다. 함수 호출의 것(7.3+)과 다르다.
    $env = [
        'ok'    => true,
        'data'  => $data,
        'error' => null,
    ];
    echo json_encode($env);
}

function json_err(string $code, int $status = 400, array $extra = []): void
{
    $msg = isset(ERROR_MESSAGES[$code]) ? ERROR_MESSAGES[$code] : 'unknown';
    echo json_encode(['ok' => false, 'error' => ['code' => $code, 'message' => $msg]]);
}
