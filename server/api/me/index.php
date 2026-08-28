<?php
declare(strict_types=1);
/**
 * GET  /api/me — 내 정보 조회        (응답 최상위 키: user, session)
 * PUT  /api/me — 내 정보 수정        (응답 최상위 키: user, updated_fields)
 * 담당 계약: 01_architect_api_contract.md §6-1, §6-2
 * 실제 호출 경로: api/me/index.php  (리더 판정 D-0)
 *
 * payload 는 여기서 반환하지 않는다. 시크릿 반환 경로는
 * login / exchange / refresh / session 4곳뿐이다 (계약 §3-5).
 *
 * PUT 은 화이트리스트 7개 필드만 취한다. account_level / role / billing_tier /
 * points / status / username / email 은 요청에 있어도 **조용히 무시**한다.
 * 에러로 처리하면 프론트가 user 객체를 그대로 PUT 하는 흔한 패턴에서 매번 실패한다.
 */
require_once __DIR__ . '/../../core/bootstrap.php';

only_method('GET', 'PUT');

$ctx  = require_auth();
$user = $ctx['user'];
$us   = $ctx['user_service'];

// ---------------------------------------------------------------------
// GET
// ---------------------------------------------------------------------
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET') {
    json_out([
        'user'    => serialize_user($user, $us),
        'session' => serialize_session($ctx['session'], true),
    ]);
}

// ---------------------------------------------------------------------
// PUT
// ---------------------------------------------------------------------
require_csrf();     // 쿠키 세션일 때만 실제로 검사한다. Bearer 는 면제.

$in = body_json();

/**
 * 수정 허용 필드 화이트리스트. 이 배열에 없는 키는 존재 자체를 보지 않는다.
 * (계약 §6-2 — meta 를 포함해 7개)
 */
$ALLOWED = ['name', 'nickname', 'phone', 'avatar_url', 'timezone', 'locale', 'marketing_agreed', 'meta'];

$set     = [];     // DB 컬럼 => 값
$changed = [];     // 실제로 값이 바뀐 필드명 (응답 updated_fields)

/**
 * "키가 없음"과 "키가 있고 빈 값"은 다르다.
 * 전자는 손대지 않고, 후자는 NULL 로 지운다.
 * v_string() 은 둘 다 default(null) 를 돌려주므로 array_key_exists 로 먼저 가른다.
 */
$hasKey = function (string $k) use ($in): bool {
    return array_key_exists($k, $in);
};

// --- name / nickname / phone / avatar_url : 문자열 또는 NULL ------------
if ($hasKey('name')) {
    $v = v_string($in, 'name', 1, 80);
    $set['name'] = $v;
}
if ($hasKey('nickname')) {
    $v = v_string($in, 'nickname', 1, 50);
    $set['nickname'] = $v;
}
if ($hasKey('phone')) {
    $v = v_string($in, 'phone', 1, 30);
    if ($v !== null && preg_match('/^[0-9+\-() .]{4,30}$/', $v) !== 1) {
        v_fail('phone', '전화번호 형식이 올바르지 않습니다.');
    }
    $set['phone'] = $v;
}
if ($hasKey('avatar_url')) {
    $v = v_string($in, 'avatar_url', 1, 255);
    if ($v !== null) {
        // http/https 만 허용한다. javascript: / data: 를 그대로 저장하면
        // 이 값을 <img src> 나 <a href> 에 넣는 화면에서 XSS 가 된다.
        $p = parse_url($v);
        $scheme = is_array($p) && isset($p['scheme']) ? strtolower((string) $p['scheme']) : '';
        if (!in_array($scheme, ['http', 'https'], true) || empty($p['host'])) {
            v_fail('avatar_url', '이미지 주소는 http(s) URL 이어야 합니다.');
        }
    }
    $set['avatar_url'] = $v;
}

// --- timezone / locale : NOT NULL 컬럼이므로 빈 값이면 기본값으로 ---------
if ($hasKey('timezone')) {
    $v = v_string($in, 'timezone', 1, 40);
    if ($v === null) {
        $v = 'Asia/Seoul';
    }
    // 실제 존재하는 타임존만 허용한다. 임의 문자열이 들어가면 프론트의
    // 시각 표시가 조용히 깨진다.
    if (!in_array($v, timezone_identifiers_list(), true) && $v !== 'UTC') {
        v_fail('timezone', '알 수 없는 타임존입니다.');
    }
    $set['timezone'] = $v;
}
if ($hasKey('locale')) {
    $v = v_string($in, 'locale', 1, 10);
    if ($v === null) {
        $v = 'ko-KR';
    }
    if (preg_match('/^[a-zA-Z]{2,3}(-[A-Za-z0-9]{2,8})?$/', $v) !== 1) {
        v_fail('locale', '로케일 형식이 올바르지 않습니다. (예: ko-KR)');
    }
    $set['locale'] = $v;
}

// --- marketing_agreed : bool ------------------------------------------
if ($hasKey('marketing_agreed')) {
    $v = v_bool($in, 'marketing_agreed', false, false);
    $set['marketing_agreed'] = $v ? 1 : 0;
}

// --- meta : JSON 객체 --------------------------------------------------
if ($hasKey('meta')) {
    $raw = $in['meta'];
    if ($raw === null) {
        $set['meta_json'] = null;
    } else {
        if (!is_array($raw)) {
            v_fail('meta', 'meta 는 객체여야 합니다.');
        }
        $enc = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($enc === false) {
            v_fail('meta', 'meta 를 저장할 수 없습니다.');
        }
        // meta_json 은 TEXT(64KB). 여유를 두고 8KB 로 제한한다.
        if (strlen($enc) > 8192) {
            v_fail('meta', 'meta 가 너무 큽니다. (8KB 이내)');
        }
        $set['meta_json'] = $enc;
    }
}

// --- 실제로 값이 바뀐 것만 골라낸다 (계약 §6-2 의 updated_fields) --------
$COLUMN_TO_FIELD = [
    'name'             => 'name',
    'nickname'         => 'nickname',
    'phone'            => 'phone',
    'avatar_url'       => 'avatar_url',
    'timezone'         => 'timezone',
    'locale'           => 'locale',
    'marketing_agreed' => 'marketing_agreed',
    'meta_json'        => 'meta',
];
$dirty = [];
foreach ($set as $col => $new) {
    $old = array_key_exists($col, $user) ? $user[$col] : null;
    if ($col === 'marketing_agreed') {
        if ((int) $old === (int) $new) {
            continue;
        }
    } elseif ($col === 'meta_json') {
        // 문자열 비교가 아니라 파싱된 구조로 비교한다. 키 순서가 달라도 같은 값이다.
        if (json_col(is_string($old) ? $old : null) === json_col(is_string($new) ? $new : null)) {
            continue;
        }
    } elseif ((string) $old === (string) $new && ($old === null) === ($new === null)) {
        continue;
    }
    $dirty[$col]  = $new;
    $changed[]    = $COLUMN_TO_FIELD[$col];
}

if ($dirty !== []) {
    $dirty['updated_at'] = now_utc();
    db_update(T_USERS, $dirty, 'id = ?', [(int) $user['id']]);
    $user = db_one('SELECT * FROM ' . T_USERS . ' WHERE id = ?', [(int) $user['id']]);
}

json_out([
    'user'           => serialize_user($user, $us),
    'updated_fields' => $changed,   // 목록이므로 json_object() 를 쓰지 않는다
]);
