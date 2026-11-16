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
// 13키 정의와 그것을 읽는 두 함수는 core 로 올렸다 — 설정 번들(api/admin/settings_bundle.php)이
// 같은 표를 써야 하고, 표가 두 곳에 있으면 키를 더할 때 한쪽만 고쳐진다.
require_once __DIR__ . '/../../core/settingsdef.php';

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

// 13키 정의는 core/settingsdef.php 의 settings_defs() 가 소유한다.
// **여기에 표를 다시 적지 마라** — 번들 검증기가 같은 표를 읽는다.
$DEFS = settings_defs();

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
        $val     = admin_setting_value_eff($k, $def, $rows);   // v1.10.1 — register_mode 는 실제 판정값
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

// -----------------------------------------------------------------
// v1.10.1 가입 방식(설계 130 §1-1) — register_mode 와 옛 키 allow_register 는 **한 쌍으로** 쓴다.
//   새 키가 있으면 그것, 없으면 옛 키 규칙(false → closed · true → closed 면 open, approval 이면 그대로).
//   allow_register 는 언제나 (mode === 'open') 로 같은 트랜잭션에서 맞춘다(register_mode_write).
// 값 검증은 아래 루프보다 **먼저** 한다(부분 적용 방지). 쓰기는 루프 뒤.
// -----------------------------------------------------------------
$regWrite = null;
if (array_key_exists('register_mode', $input) || array_key_exists('allow_register', $input)) {
    $modeIn  = array_key_exists('register_mode', $input) ? (string) v_enum($input, 'register_mode', REGISTER_MODES, true) : null;
    $allowIn = array_key_exists('allow_register', $input) ? (bool) v_bool($input, 'allow_register', true) : null;
    $curMode  = register_mode_global_from_rows($rows);
    $curAllow = admin_setting_value('allow_register', $DEFS['allow_register'], $rows);
    list($newMode, $newAllow) = register_mode_write_target($modeIn, $allowIn, $curMode);
    $regRowMissing = !isset($rows['register_mode']);
    foreach (['register_mode' => [$curMode, $newMode], 'allow_register' => [$curAllow, $newAllow]] as $rk => $pair) {
        if ($pair[0] === $pair[1]) {
            if (array_key_exists($rk, $input)) {
                $unchanged[] = $rk;
            }
            continue;
        }
        $updated[]     = $rk;
        $before[$rk]   = $pair[0];
        $after[$rk]    = $pair[1];
    }
    if ($curMode !== $newMode || $curAllow !== $newAllow || ($regRowMissing && $modeIn !== null)) {
        $regWrite = $newMode;
    }
    unset($input['register_mode'], $input['allow_register']);
}

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
    } elseif (is_array($def[5])) {
        // 열거값·길이 제약도 settings_defs() 가 소유한다. 리터럴을 여기 두면
        // 번들 검증기가 같은 값을 다시 적어야 한다 — 그것이 사본이다.
        $new = (string) v_enum($input, $key, $def[5], true);
    } else {
        $len = is_array($def[6]) ? $def[6] : [1, 100];
        $new = (string) v_string($input, $key, (int) $len[0], (int) $len[1], true);
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

if ($regWrite !== null) {
    register_mode_write($regWrite);
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
    $map[$k] = admin_setting_value_eff($k, $def, $rows);
}

json_out([
    'updated'      => $updated,
    'unchanged'    => $unchanged,
    'map'          => $map,
    'admin_log_id' => $logId,
]);
