<?php
declare(strict_types=1);

/**
 * 티어 2 — 3축 등급 판정과 만료 강등
 *
 * `perm.php` 머리에 이렇게 적혀 있다: **"문자열 비교 금지. `'vip' > 'staff'` 는
 * PHP 에서 참이다."** 그 한 줄이 이 파일의 존재 이유다. 등급 판정은 권한 우회로
 * 곧장 이어지고, 우회는 **에러 없이** 일어난다.
 */

require_once __DIR__ . '/../lib/loader.php';

load_core();

t('rank comparison never falls back to string comparison', function () {
    // 문자열 비교였다면 'vip' > 'staff' 가 참이 된다 ('v' > 's').
    // 랭크로는 staff(90) > vip(30) 이다. 이 한 쌍이 그 사고를 직접 겨눈다.
    assert_false(level_gte('vip', 'staff'), 'vip must NOT satisfy a staff gate');
    assert_true(level_gte('staff', 'vip'), 'staff must satisfy a vip gate');
    // role 축에서도 같은 함정: 'user' > 'superadmin' ('u' > 's')
    assert_false(role_gte('user', 'superadmin'), 'user must NOT satisfy a superadmin gate');
    assert_true(role_gte('superadmin', 'user'));
    // billing 축: 'free' > 'enterprise' ('f' > 'e')
    assert_false(tier_gte('free', 'enterprise'), 'free must NOT satisfy an enterprise gate');
    assert_true(tier_gte('enterprise', 'free'));
});

t('unknown and null grade values rank 0 (fail closed)', function () {
    // 오타나 신규 값이 들어오면 **최하위**로 떨어져야 한다. 반대로 떨어지면
    // 값 하나 잘못 넣는 것이 권한 상승이 된다.
    assert_eq(0, rank_of(ROLE_RANK, 'ADMIN'), 'case matters - uppercase is not a known role');
    assert_eq(0, rank_of(ROLE_RANK, 'root'));
    assert_eq(0, rank_of(ROLE_RANK, null));
    assert_eq(0, rank_of(ROLE_RANK, ''));
    assert_false(role_gte('root', 'manager'), 'an unknown role must not pass a manager gate');
    assert_false(role_gte(null, 'manager'));
    // 다만 최하위 게이트는 통과한다 (0 >= 0). 그것이 fail-closed 의 바닥이다
    assert_true(role_gte(null, 'user'));
});

t('every rank ladder is strictly ordered', function () {
    foreach (array(
        'ACCOUNT_LEVEL_RANK' => ACCOUNT_LEVEL_RANK,
        'ROLE_RANK'          => ROLE_RANK,
        'BILLING_TIER_RANK'  => BILLING_TIER_RANK,
    ) as $nm => $map) {
        $prev = null;
        foreach ($map as $k => $r) {
            if ($prev !== null && $r <= $prev) {
                fail($nm . ' is not strictly increasing at "' . $k . '" (' . $r . ' after ' . $prev . ')');
            }
            $prev = $r;
        }
        // 최하위가 0 이어야 rank_of() 의 미지값 0 과 같은 바닥을 공유한다
        assert_eq(0, min(array_values($map)), $nm . ' bottom rung must be 0');
    }
});

t('CAN_MAP only names roles that exist in ROLE_RANK', function () {
    // 존재하지 않는 역할을 요구하는 조작 키는 rank 0 으로 떨어져 **누구나 통과**한다.
    // 오타 하나가 게이트를 통째로 여는 경로다.
    $bad = array();
    foreach (CAN_MAP as $action => $minRole) {
        if (!array_key_exists($minRole, ROLE_RANK)) {
            $bad[] = $action . ' requires "' . $minRole . '" which is not a role in ROLE_RANK'
                . ' - rank_of() returns 0 for it, so the gate would let everyone through';
        }
    }
    assert_clean($bad, 'CAN_MAP references an unknown role');
});

t('effective_grades applies the service override and reports both the value and its rank', function () {
    $u  = array('account_level' => 'basic', 'role' => 'user', 'billing_tier' => 'free');
    $us = array('account_level' => 'vip',   'role' => 'manager', 'billing_tier' => 'pro', 'status' => 'active');

    $g0 = effective_grades($u, null);
    assert_eq('basic', $g0['account_level']);
    assert_eq('user', $g0['role']);
    assert_eq(null, $g0['membership_status'], 'no service context means null, not "active"');

    $g1 = effective_grades($u, $us);
    assert_eq('vip', $g1['account_level'], 'the service override wins');
    assert_eq('manager', $g1['role']);
    assert_eq('pro', $g1['billing_tier']);
    assert_eq('active', $g1['membership_status']);
    // 값과 랭크가 같이 나가야 한다 — 프론트가 값을 문자열 비교하지 않게 하는 장치다
    assert_eq(rank_of(ACCOUNT_LEVEL_RANK, 'vip'), $g1['account_level_rank']);
    assert_eq(rank_of(ROLE_RANK, 'manager'), $g1['role_rank']);
    assert_eq(rank_of(BILLING_TIER_RANK, 'pro'), $g1['billing_tier_rank']);
});

t('an expired billing tier is demoted to free at judgement time', function () {
    // cron 이 없어 배치 강등이 불가능하다. 그래서 **읽을 때마다** 판정한다.
    // 이 경계가 틀리면 만료된 유료 등급이 계속 통한다.
    $past   = gmdate('Y-m-d H:i:s', time() - 60);
    $future = gmdate('Y-m-d H:i:s', time() + 3600);

    $expired = effective_grades(array('billing_tier' => 'pro', 'billing_expires_at' => $past));
    assert_eq('free', $expired['billing_tier'], 'an expired tier must read as free');
    assert_eq(0, $expired['billing_tier_rank']);
    assert_false($expired['billing_active']);
    // 원본 만료 시각은 그대로 실려야 한다 (화면이 "언제 만료됐는지"를 보여준다)
    assert_eq($past, $expired['billing_expires_at'], 'the raw expiry must survive the demotion');

    $live = effective_grades(array('billing_tier' => 'pro', 'billing_expires_at' => $future));
    assert_eq('pro', $live['billing_tier']);
    assert_true($live['billing_active']);

    // NULL 만료 = 무기한. 계약 §3-1 #17
    $forever = effective_grades(array('billing_tier' => 'pro', 'billing_expires_at' => null));
    assert_eq('pro', $forever['billing_tier']);
    assert_true($forever['billing_active']);

    // free 는 만료가 있어도 billing_active=false
    $free = effective_grades(array('billing_tier' => 'free', 'billing_expires_at' => $future));
    assert_false($free['billing_active'], 'free is never billing_active');
});

t('the service override also decides which expiry applies', function () {
    $past   = gmdate('Y-m-d H:i:s', time() - 60);
    $future = gmdate('Y-m-d H:i:s', time() + 3600);
    // 오버라이드 tier 가 있으면 만료도 오버라이드 쪽을 본다.
    // 전역이 만료됐어도 서비스 오버라이드가 살아 있으면 살아 있어야 한다.
    $g = effective_grades(
        array('billing_tier' => 'pro', 'billing_expires_at' => $past),
        array('billing_tier' => 'enterprise', 'billing_expires_at' => $future, 'status' => 'active')
    );
    assert_eq('enterprise', $g['billing_tier'], 'the override tier and its own expiry win');
    assert_true($g['billing_active']);

    // 반대 방향 — 오버라이드가 만료됐으면 전역이 살아 있어도 강등된다
    $g2 = effective_grades(
        array('billing_tier' => 'pro', 'billing_expires_at' => $future),
        array('billing_tier' => 'enterprise', 'billing_expires_at' => $past, 'status' => 'active')
    );
    assert_eq('free', $g2['billing_tier'], 'an expired override must not fall back to the global tier');
});

t('global_role_of ignores the service override entirely (D-50)', function () {
    // 특정 서비스에서 admin 오버라이드를 받은 사용자가 **로그인 서버 전체의
    // 관리자**가 되어서는 안 된다. 그 승격 경로 자체가 권한 상승이다.
    assert_eq('user', global_role_of(array('role' => 'user')));
    assert_eq('admin', global_role_of(array('role' => 'admin')));
    // 값이 없으면 최하위 — fail closed
    assert_eq('user', global_role_of(array()));
    assert_eq('user', global_role_of(null));
    // 알 수 없는 값은 그대로 돌려주되 랭크가 0 이라 어떤 게이트도 못 넘는다
    assert_eq(0, rank_of(ROLE_RANK, global_role_of(array('role' => 'root'))));
});

t('require_global_role throws FORBIDDEN below the bar and passes at or above it', function () {
    $ctx = array('user' => array('role' => 'manager'));
    assert_no_throw(function () use ($ctx) {
        require_global_role($ctx, 'manager');
    }, 'equal rank must pass');
    assert_no_throw(function () use ($ctx) {
        require_global_role($ctx, 'user');
    }, 'higher rank must pass');
    $e = assert_throws('AppError', function () use ($ctx) {
        require_global_role($ctx, 'admin');
    }, 'lower rank must be refused');
    assert_eq('FORBIDDEN', $e->code);
    assert_eq(403, $e->status);

    // 컨텍스트에 user 가 아예 없으면 — fail closed
    assert_throws('AppError', function () {
        require_global_role(array(), 'manager');
    }, 'a context with no user must be refused');
});
