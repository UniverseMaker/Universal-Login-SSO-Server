<?php
declare(strict_types=1);
/**
 * 전역 설정 조회 / 부분 수정 — /api/admin/settings.php
 * 담당 계약: §8-22(GET) · §8-23(PUT)
 * 최소 역할: admin
 *
 * 응답 최상위 키
 *   GET : items, total, map
 *   PUT : updated, unchanged, map, admin_log_id
 *
 * svalue 는 vtype 대로 캐스팅해 내보낸다(DB 는 문자열이지만 응답에서는 타입이 맞아야 한다).
 * 정의되지 않은 skey 는 NOT_FOUND — 오타로 만든 키가 조용히 쌓이면 어느 값이 실제로
 * 읽히는지 알 수 없게 된다.
 */
require_once __DIR__ . '/../../core/bootstrap.php';

only_method('GET', 'PUT');

if (!function_exists('admin_log_write')) {
    function admin_log_write(int $adminId, string $action, string $targetType, ?string $targetId,
                             ?array $before = null, ?array $after = null, ?string $memo = null): ?int
    {
        log_admin($adminId, $action, $targetType, $targetId, $before, $after, $memo);
        $id = (int) db()->lastInsertId();
        if ($id <= 0) {
            return null;
        }
        $chk = db_val('SELECT id FROM ' . T_ADMIN_LOGS . ' WHERE id = ? AND admin_id = ? AND action = ?',
            [$id, $adminId, substr($action, 0, 60)]);
        return $chk === null ? null : (int) $chk;
    }
}

/**
 * 관리 가능한 설정 키 정의.
 *   [vtype, label, 폴백 상수명, min, max]  — min/max 는 int 전용
 *
 * 앞의 11개는 계약 §8-22 의 목록이며 **순서까지 그대로**다.
 * 리더 판정 D-40 으로 `auth_code_ttl` 을 12번째로 **맨 뒤에 추가**했다 — 중간에 끼우면
 * 기존 11개의 `items` 인덱스가 밀려 관리자 화면의 표 순서가 바뀐다.
 * v1.4.0 후속으로 `secret_access_log` 를 **13번째로 맨 뒤에** 더했다. 같은 규칙이다.
 * **새 키는 언제나 맨 뒤다.** 지금 total 은 13 이다.
 */
$DEFS = [
    'allow_register'       => ['bool',   '신규 가입 허용',              'ALLOW_REGISTER',       null, null],
    'require_email_verify' => ['bool',   '가입 시 이메일 인증 필수',     'REQUIRE_EMAIL_VERIFY', null, null],
    'signup_bonus_points'  => ['int',    '가입 보너스 포인트',           'SIGNUP_BONUS_POINTS',  0,    1000000],
    'login_fail_threshold' => ['int',    '락아웃 임계 실패 횟수',        'LOCKOUT_THRESHOLD',    1,    100],
    'login_lock_minutes'   => ['int',    '락아웃 지속 시간(분)',         'LOCKOUT_MINUTES',      1,    1440],
    'password_min_length'  => ['int',    '비밀번호 최소 길이',           'PASSWORD_MIN_LENGTH',  8,    64],
    'session_ttl_default'  => ['int',    '기본 access 토큰 수명(초)',    'SESSION_TTL',          60,   604800],
    'refresh_ttl_default'  => ['int',    '기본 refresh 토큰 수명(초)',   'REFRESH_TTL',          300,  31536000],
    'maintenance_mode'     => ['bool',   '점검 모드',                   'MAINTENANCE_MODE',     null, null],
    'mail_driver'          => ['string', '메일 발송 방식 (mail|log)',    'MAIL_DRIVER',          null, null],
    'site_name'            => ['string', '사이트 표시명',                'SITE_NAME',            null, null],
    // D-40: core 가 setting_get('auth_code_ttl') 로 읽는데 여기 정의가 없어 조회·변경이 불가능했다.
    // 폴백 상수는 api/sso/authorize.php 의 `11) code 발급` 절이 쓰는 것과 같은 AUTH_CODE_TTL 이고,
    // 그 기본값(60)은 config.sample.php 의 `정책 기본값` 블록에 있다.
    // 행 번호는 적지 않는다 — 편집 한 번에 낡는다(D-92). 파일명 + 절 이름으로 가리킨다.
    //   다시 찾으려면: grep -rn 'AUTH_CODE_TTL' server/
    // 상한 600초: SSO code 는 1회용 단기 자격증명이다. 수명이 길어지면 탈취된 code 의
    // 유효 창이 그만큼 넓어진다(RFC 6749 §4.1.2 권고 = 10분 이내).
    // 하한 30초: 로그인 → 리다이렉트 → exchange 왕복이 끝나야 한다. 더 낮추면 느린 회선에서
    // 정상 로그인이 SSO_CODE_EXPIRED 로 실패한다. 위의 `11) code 발급` 절도 `$ttl <= 0` 이면
    // 설정값을 버리고 상수로 되돌리므로, 0 이하는 그쪽에서도 무효다.
    'auth_code_ttl'        => ['int',    'SSO code 수명(초)',            'AUTH_CODE_TTL',        30,   600],
    // v1.4.0 후속: core/logger.php 가 setting_get('secret_access_log') 로 읽는데 여기
    // 정의가 없어 **DB 에 직접 INSERT 하지 않으면 끌 수 없었다.** D-40 의 auth_code_ttl
    // 과 같은 형태의 결함이다(코드가 읽는데 정의가 없다). 그래서 같은 처리를 한다 —
    // **13번째로 맨 뒤에.** 중간에 끼우면 앞 12개의 items 인덱스가 밀려 관리자 화면의
    // 표 순서가 바뀐다(D-40 후속이 확인한 그 이유 그대로).
    // 폴백 상수 SECRET_ACCESS_LOG 의 정의와 기본값(true)의 근거는 core/bootstrap.php
    // 의 가드 블록에 있다. logger.php 도 같은 상수를 폴백으로 읽는다.
    'secret_access_log'    => ['bool',   '시크릿 전달 감사 기록',        'SECRET_ACCESS_LOG',    null, null],
];

/** DB 의 현재 행. settings_all() 은 정적 캐시라 PUT 직후 값이 낡는다 — 직접 읽는다. */
function admin_settings_rows(array $keys): array
{
    if ($keys === []) {
        return [];
    }
    $ph   = implode(',', array_fill(0, count($keys), '?'));
    $out  = [];
    foreach (db_all('SELECT skey, svalue, vtype, label, updated_at FROM ' . T_SETTINGS
        . ' WHERE skey IN (' . $ph . ')', $keys) as $r) {
        $out[(string) $r['skey']] = $r;
    }
    return $out;
}

/**
 * vtype 대로 캐스팅. DB 행이 없으면 config.php 상수 폴백.
 * @return mixed
 */
function admin_setting_value(string $key, array $def, array $rows)
{
    $vtype = $def[0];
    if (isset($rows[$key]) && $rows[$key]['svalue'] !== null) {
        $raw = (string) $rows[$key]['svalue'];
        if ($vtype === 'int') {
            return (int) $raw;
        }
        if ($vtype === 'bool') {
            return ($raw === '1' || $raw === 'true' || $raw === 'on');
        }
        return $raw;
    }
    $const = $def[2];
    if ($const !== null && defined($const)) {
        $v = constant($const);
        if ($vtype === 'int') {
            return (int) $v;
        }
        if ($vtype === 'bool') {
            return (bool) $v;
        }
        return (string) $v;
    }
    return $vtype === 'int' ? 0 : ($vtype === 'bool' ? false : '');
}

$ctx = require_auth();
require_global_role($ctx, 'admin');

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$keys   = array_keys($DEFS);

// -----------------------------------------------------------------
// GET — 계약 §8-22
// -----------------------------------------------------------------
if ($method === 'GET') {
    $rows  = admin_settings_rows($keys);
    $items = [];
    $map   = [];
    foreach ($DEFS as $k => $def) {
        $val     = admin_setting_value($k, $def, $rows);
        $map[$k] = $val;
        $items[] = [
            'skey'       => $k,
            'svalue'     => $val,
            'vtype'      => $def[0],
            'label'      => isset($rows[$k]) && $rows[$k]['label'] !== null ? (string) $rows[$k]['label'] : $def[1],
            'updated_at' => isset($rows[$k]) ? iso($rows[$k]['updated_at']) : null,
        ];
    }
    json_out(['items' => $items, 'total' => count($items), 'map' => $map]);
}

// -----------------------------------------------------------------
// PUT — 계약 §8-23 (부분 업데이트)
// -----------------------------------------------------------------
require_csrf();

$body = body_json();
if (!array_key_exists('settings', $body) || !is_array($body['settings'])) {
    v_fail('settings', 'settings 객체가 필요합니다.');
}
$reason = v_string($body, 'reason', 0, 255, false);
$input  = $body['settings'];

// -----------------------------------------------------------------
// 감사 스위치만 superadmin — 보안 재검증 V14b-M-4
//
// 실측 대조: `admin` 은 `GET secrets.php?action=reveal` 이 **403** 인데
// `PUT {secret_access_log:false}` 는 **200** 이었다. 시크릿 평문을 볼 자격이 없는
// 역할이 시크릿 전달 기록을 끌 자격은 있었다는 뜻이다. `admin` 은 시크릿 CRUD
// (게이트 설정 포함)를 할 수 있으므로 구체적 경로가 성립한다 —
//   ① secret_access_log=false  ② 게이트를 비우고 allow_public=true
//   ③ 자기가 만든 하위 등급 계정으로 로그인해 payload 로 키를 받는다
// **③이 남는 표가 바로 ①이 끈 표다.**
//
// reveal 을 superadmin 으로 묶은 것이 D-7 이므로, 그 기록을 끄는 것도 같은 등급이어야
// 한다. 나머지 12개 키는 종전 그대로 `admin` 이다 — 이 키 하나만 다르다.
//
// 값 검증·부분 업데이트 루프보다 **앞에** 둔다. 루프 안에서 던지면 앞선 키가
// 이미 반영된 채로 403 이 나가 부분 적용이 된다.
//
// 응답은 기존 FORBIDDEN(403)이다 — 새 에러 코드를 만들지 않는다.
// -----------------------------------------------------------------
if (array_key_exists('secret_access_log', $input) && !role_gte(ctx_global_role($ctx), 'superadmin')) {
    throw new AppError('FORBIDDEN', 403, [
        'field'   => 'secret_access_log',
        'message' => '시크릿 전달 감사 기록은 최고관리자만 변경할 수 있습니다.',
    ]);
}

$rows      = admin_settings_rows($keys);
$updated   = [];
$unchanged = [];
$before    = [];
$after     = [];

foreach ($input as $k => $v) {
    $key = (string) $k;
    if (!isset($DEFS[$key])) {
        // 임의의 키를 새로 만들 수 없다
        throw new AppError('NOT_FOUND', 404, [
            'field'   => $key,
            'message' => '정의되지 않은 설정 키입니다: ' . $key,
        ]);
    }
    $def   = $DEFS[$key];
    $vtype = $def[0];
    $cur   = admin_setting_value($key, $def, $rows);

    if ($vtype === 'int') {
        $new = (int) v_int($input, $key, $def[3], $def[4], true);
        if ($key === 'signup_bonus_points' && $new < 0) {
            v_fail($key, $key . ' 는 0 이상의 정수여야 합니다.');
        }
    } elseif ($vtype === 'bool') {
        $new = (bool) v_bool($input, $key, true);
    } elseif ($key === 'mail_driver') {
        $new = (string) v_enum($input, $key, ['mail', 'log'], true);
    } else {
        $new = (string) v_string($input, $key, 1, 100, true);
    }

    if ($cur === $new) {
        $unchanged[] = $key;
        continue;
    }
    setting_set($key, $new, $vtype, $def[1]);
    $updated[]      = $key;
    $before[$key]   = $cur;
    $after[$key]    = $new;
}

$logId = null;
if ($updated !== []) {
    $logId = admin_log_write(
        (int) $ctx['user']['id'], 'setting.update', 'setting',
        implode(',', array_slice($updated, 0, 5)),
        $before, $after, $reason
    );
}

// 갱신 후 값으로 map 을 다시 만든다
$rows = admin_settings_rows($keys);
$map  = [];
foreach ($DEFS as $k => $def) {
    $map[$k] = admin_setting_value($k, $def, $rows);
}

json_out([
    'updated'      => $updated,
    'unchanged'    => $unchanged,
    'map'          => $map,
    'admin_log_id' => $logId,
]);
