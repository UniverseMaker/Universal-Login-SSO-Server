<?php
declare(strict_types=1);

/**
 * core/response.php — 응답 봉투 단일 출구
 * 담당 계약: 01_architect_api_contract.md §1-1(응답 봉투), §2(표준 에러 코드)
 *
 * 이 파일의 json_out() / json_err() 외의 방법으로 JSON 을 출력하지 않는다.
 * 봉투가 한 곳에서만 만들어져야 프론트가 `ok` 하나로 분기할 수 있다.
 */


// 직접 실행 차단 — 리더 판정 D-34. bootstrap.php 를 거치지 않은 요청은 존재하지 않는 파일이다.
if (!defined('ULOGIN_BOOTSTRAPPED')) {
    // X-Powered-By 제거 — D-154(2). 이 경로는 bootstrap.php 를 거치지 않는다.
    header_remove('X-Powered-By');
    http_response_code(404);
    exit;
}
/**
 * 계약 §2 의 에러 코드를 그대로 던지기 위한 예외.
 *
 * 주의: Exception::$code 는 부모에서 타입 없이 선언돼 있다.
 *       자식에서 `public string $code` 로 타입을 붙이면 PHP 7.4+ 에서 치명적 오류가 난다
 *       (부모의 타입 없는 프로퍼티에 타입을 붙일 수 없다). 그리고 타입 프로퍼티 자체가
 *       **PHP 7.4 문법이라 7.2 에서는 파싱조차 되지 않는다.**
 *       그래서 타입 없이 재선언하고(가시성 확대는 허용된다) 문자열을 담는다.
 *       — PHP 7.2 호환 (D-57). 금지 문법 전체 목록은 core/bootstrap.php 상단 참고.
 */
class AppError extends Exception
{
    /** @var string 계약 §2 의 에러 코드 */
    public $code;
    /** @var int HTTP 상태 코드 */
    public $status;
    /** @var array 응답 data 에 실을 부가정보 (+ 'field', 'message' 특수 키) */
    public $extra;

    public function __construct(string $code, int $status = 400, array $extra = [])
    {
        parent::__construct($code);
        $this->code   = $code;
        $this->status = $status;
        $this->extra  = $extra;
    }
}

/**
 * 계약 §2 전체. 표에 없는 코드는 응답하지 않는다.
 * 메시지가 상황마다 달라야 하면 json_err(..., ['message' => '...']) 로 덮어쓴다.
 */
const ERROR_MESSAGES = [
    'VALIDATION_FAILED'         => '입력값을 확인해 주세요.',
    'AUTH_INVALID_CREDENTIALS'  => '아이디 또는 비밀번호가 올바르지 않습니다.',
    'AUTH_REQUIRED'             => '로그인이 필요합니다.',
    'AUTH_SESSION_EXPIRED'      => '세션이 만료되었습니다. 다시 로그인해 주세요.',
    'AUTH_ACCOUNT_LOCKED'       => '로그인 시도 횟수를 초과했습니다. 잠시 후 다시 시도해 주세요.',
    'AUTH_ACCOUNT_SUSPENDED'    => '정지된 계정입니다. 관리자에게 문의해 주세요.',
    'AUTH_ACCOUNT_WITHDRAWN'    => '탈퇴한 계정입니다.',
    'AUTH_ACCOUNT_DORMANT'      => '휴면 상태인 계정입니다. 관리자에게 문의해 주세요.',
    'AUTH_ACCOUNT_PENDING'      => '가입 처리가 완료되지 않은 계정입니다.',
    'AUTH_EMAIL_UNVERIFIED'     => '이메일 인증이 필요합니다. 받은 메일의 링크를 확인해 주세요.',
    'AUTH_PASSWORD_MISMATCH'    => '현재 비밀번호가 올바르지 않습니다.',
    'FORBIDDEN'                 => '권한이 없습니다.',
    'TIER_INSUFFICIENT'         => '이용 등급이 부족합니다.',
    'NOT_FOUND'                 => '대상을 찾을 수 없습니다.',
    'DUPLICATE_USERNAME'        => '이미 사용 중인 아이디입니다.',
    'DUPLICATE_EMAIL'           => '이미 사용 중인 이메일입니다.',
    'DUPLICATE_SERVICE_ID'      => '이미 사용 중인 서비스 ID 입니다.',
    'DUPLICATE_SECRET_KEY'      => '이미 등록된 시크릿 키입니다.',
    'POINT_INSUFFICIENT'        => '포인트가 부족합니다.',
    'CONFLICT'                  => '현재 상태에서는 처리할 수 없습니다.',
    // v1.8.4 계정 연결 — 만료·다른 관리자의 plan·취소됨·이미 확정됨을 **하나로** 묶는다(설계 G-5).
    'LINK_PLAN_INVALID'         => '계획이 만료되었거나 이미 확정되었습니다. 다시 미리보기 하세요.',
    'SSO_INVALID_SERVICE'       => '알 수 없는 서비스입니다.',
    'SSO_INVALID_REDIRECT'      => '허용되지 않은 복귀 주소입니다. 서비스 관리자에게 문의해 주세요.',
    'SSO_CODE_INVALID'          => '인증 코드가 유효하지 않습니다. 다시 로그인해 주세요.',
    'SSO_CLIENT_AUTH_FAILED'    => '서비스 인증에 실패했습니다.',
    'TOKEN_INVALID'             => '링크가 만료되었거나 이미 사용되었습니다. 다시 요청해 주세요.',
    'CSRF_FAILED'               => '요청이 유효하지 않습니다. 새로고침 후 다시 시도해 주세요.',
    'RATE_LIMITED'              => '요청이 너무 많습니다. 잠시 후 다시 시도해 주세요.',
    'REGISTRATION_DISABLED'     => '현재 신규 가입을 받지 않습니다.',
    'MAINTENANCE_MODE'          => '서비스 점검 중입니다. 잠시 후 다시 시도해 주세요.',
    'METHOD_NOT_ALLOWED'        => '허용되지 않은 요청 방식입니다.',
    'SERVER_ERROR'              => '일시적인 오류가 발생했습니다.',
    // v1.9.0 (설계 116 §11) — 새 코드 2개. 나머지 무변경.
    'SERVICE_ACCESS_DENIED'     => '이 시스템을 쓸 권한이 없습니다.',
    'QUOTA_EXCEEDED'            => '사용 한도를 넘었습니다.',
];

/**
 * 응답 공통 헤더. json_out / json_err 양쪽에서 같은 것을 내보낸다.
 *
 * ── 캐시 무효화 (리더 판정 D-87) ─────────────────────────────────────
 * 이 함수가 이 파일의 **모든** JSON 응답이 지나는 단일 지점이다
 * (json_out → 여기, json_err → 여기). 캐시 헤더를 두 함수에 각각 쓰면
 * 다음 수정에서 한쪽만 고쳐진다. **반드시 이 한 곳에만 둔다.**
 *
 * 왜 `no-store` 하나로는 부족한가 — 이 응답들은 세션 쿠키와 서비스 시크릿
 * (계약 §3-5 `payload`, AI API 키)을 싣는다. 중간 캐시가 한 사용자의 응답을
 * 다른 사용자에게 재사용하면 그대로 계정·키 유출이다. 그래서 캐시 계층별로
 * 각각 유효한 지시자를 모두 보낸다:
 *   - `no-store`        : HTTP/1.1 캐시에 저장 자체를 금지. **주 방어선.**
 *   - `no-cache`        : 그럼에도 보관하는 구현(브라우저 뒤로가기 캐시,
 *                         일부 리버스 프록시)에 매 요청 재검증을 강제.
 *   - `must-revalidate` : 보관된 응답을 stale 상태로 내주지 못하게 한다.
 *   - `max-age=0`       : 신선도 휴리스틱(Last-Modified 기반 추정 캐싱)을 차단.
 *   - `Pragma: no-cache`: HTTP/1.0 전용 관행. HTTP/1.1 캐시는 이 헤더를
 *                         무시하므로 위 지시자를 약화시키지 않는다.
 *   - `Expires: 0`      : HTTP/1.0 에서 만료를 표현하는 **규격상의** 수단.
 *                         '0' 은 잘못된 날짜 형식이지만 규격이 "이미 만료된
 *                         시각으로 해석하라"고 못박고 있다(RFC 9111 §5.3).
 *
 * 공용 호스팅은 제공자 단의 리버스 프록시 뒤에 있을 수 있고 그 설정을 우리가
 * 볼 수 없다. Pragma/Expires 의 실제 이득은 2026 년 기준 거의 0 에 가깝지만
 * 비용도 응답당 30 바이트뿐이다. 시크릿을 싣는 응답에서는 이 쪽으로 기운다.
 *
 * ── 한계 ─────────────────────────────────────────────────────────────
 * `headers_sent()` 면 아무 헤더도 붙일 수 없다(이미 본문이 나갔다). PHP 경고가
 * 먼저 출력된 경우가 여기 해당한다. 헤더로는 복구할 수 없는 상황이므로
 * bootstrap.php 의 `display_errors=0` 이 이 경로의 유일한 방어다.
 *
 * 이 파일 **밖의** 응답 경로(bootstrap.php 의 OPTIONS 204·FORCE_HTTPS 301,
 * 각 core 파일 상단의 직접 실행 가드 404, install.php)는 이 함수를 거치지
 * 않는다. 소유자가 다르므로 여기서 건드리지 않는다 — D-89, 22b 리포트 참고.
 */
function response_headers(?bool $privateRevalidate = null): void
{
    // v1.9.0 (설계 116 §4) — **캐시 허용 인자** 하나. 브라우저 설정 조회(GET platform/remote_config.php) 만
    // `private, no-cache`(저장은 하되 매번 ETag 로 재검증)를 쓴다. 그 경로는 시크릿을 싣지 않는다(A7).
    // 인자를 받지 않은 호출은 종전 그대로다. 인자는 요청 단위로 기억된다(json_err 도 같은 값을 쓴다).
    static $priv = false;
    if ($privateRevalidate !== null) {
        $priv = $privateRevalidate;
        return;
    }
    if (headers_sent()) {
        return;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    if ($priv) {
        header('Cache-Control: private, no-cache');
        return;
    }
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

/**
 * 성공 응답. {"ok":true,"data":{...},"error":null}
 * @param mixed $data
 */
/**
 * v1.9.0 — 응답 data 에 **조건부로** 붙는 순수 추가 키(지금은 `features` 하나, 설계 116 §7).
 * 등록부가 없으면 키 자체가 없어야 해서 리터럴에 넣을 수 없다. 리터럴을 변수 조립으로 바꾸면 tests 의 배선 검사가
 * 그 엔드포인트의 **모든 키**를 못 보게 된다 — 그래서 리터럴은 그대로 두고 여기 등록한 키만 json_out() 이 덧붙인다.
 * 이미 있는 키는 덮지 않는다. json_err() 에는 붙지 않는다.
 * @param mixed $v
 */
function response_extra(?string $key = null, $v = null): array
{
    static $extra = [];
    if ($key !== null) {
        $extra[$key] = $v;
    }
    return $extra;
}

function json_out($data = null, int $status = 200): void
{
    if (is_array($data) && $data !== [] && array_keys($data) !== range(0, count($data) - 1)) {
        foreach (response_extra() as $k => $v) {
            if (!array_key_exists($k, $data)) {
                $data[$k] = $v;
            }
        }
    }
    http_response_code($status);
    response_headers();
    echo json_encode(
        ['ok' => true, 'data' => $data, 'error' => null],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

/**
 * 실패 응답. {"ok":false,"data":null|{...},"error":{"code","message","field"}}
 *
 * $extra 의 특수 키
 *   - 'field'   : error.field 로 이동 (입력값 오류일 때만 채운다)
 *   - 'message' : error.message 를 덮어쓴다
 *   나머지 키는 계약 §1-1 대로 data 에 실린다 (retry_after / locked_until / required 등).
 */
function json_err(string $code, int $status = 400, array $extra = []): void
{
    http_response_code($status);

    $field = null;
    if (array_key_exists('field', $extra)) {
        $field = $extra['field'] === null ? null : (string) $extra['field'];
        unset($extra['field']);
    }
    $message = ERROR_MESSAGES[$code] ?? '오류가 발생했습니다.';
    if (array_key_exists('message', $extra)) {
        if ($extra['message'] !== null && $extra['message'] !== '') {
            $message = (string) $extra['message'];
        }
        unset($extra['message']);
    }

    response_headers();
    // 계약 §8-3: 429 에는 Retry-After 헤더도 함께 보낸다.
    if (isset($extra['retry_after']) && !headers_sent()) {
        header('Retry-After: ' . (int) $extra['retry_after']);
    }

    // error.field 는 값이 없어도 키를 유지한다 — 프론트가 존재 여부를 검사하지 않아도 되게.
    $err = ['code' => $code, 'message' => $message, 'field' => $field];

    echo json_encode(
        ['ok' => false, 'data' => $extra === [] ? null : $extra, 'error' => $err],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

/**
 * 비어 있어도 JSON 객체 `{}` 로 나가야 하는 맵을 감싼다.
 *
 * PHP 의 빈 배열은 json_encode 에서 `[]`(배열)가 된다. 계약 §3-1 #33(meta)과
 * §3-5(payload)는 비어도 `{}`(객체)를 요구한다 — 프론트가 Object.keys() 를
 * 바로 쓸 수 있어야 하기 때문이다. 목록(items 등)에는 쓰지 않는다.
 *
 * @param  array $a
 * @return array|stdClass
 */
function json_object(array $a)
{
    return $a === [] ? new stdClass() : $a;
}

/** 현재 시각(UTC) DATETIME 문자열. DB 저장용. */
function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

/** $offsetSec 초 뒤 시각(UTC) DATETIME 문자열. */
function at_utc(int $offsetSec): string
{
    return gmdate('Y-m-d H:i:s', time() + $offsetSec);
}

/** DB DATETIME(UTC) → epoch. NULL 이면 null. */
function ts(?string $dt): ?int
{
    if ($dt === null || $dt === '' || $dt === '0000-00-00 00:00:00') {
        return null;
    }
    $t = strtotime($dt . ' UTC');
    return $t === false ? null : $t;
}

/** DB DATETIME(UTC) → ISO8601 "2026-08-25T04:12:00Z". 계약 §1 시각 규약. */
function iso(?string $dt): ?string
{
    $t = ts($dt);
    return $t === null ? null : gmdate('Y-m-d\TH:i:s\Z', $t);
}
