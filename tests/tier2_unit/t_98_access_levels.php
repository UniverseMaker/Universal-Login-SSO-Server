<?php
declare(strict_types=1);

/**
 * 티어 2 — v1.10.0 접근 단계 판정 (설계 127 §2 · §4 · §7 T2-1 ~ T2-6)
 *
 * access_level_decide() 는 순수 함수다. 이 파일은 DB 없이 그 함수를 **독립 재구현(오라클)** 과 전수 대조한다.
 * 오라클은 설계 §2 의 1~9 번을 문장 그대로 옮긴 것이고 제품 코드를 부르지 않는다(같은 결함을 공유하지 않게).
 *   T2-1 판정 행렬   T2-2 0010 직후 동치(그룹·level 없음 → bool 이 옛 service_access_decide 와 전수 일치)
 *   T2-3 until 최솟값   T2-4 scopes 우선순위·교집합·깨진 JSON   T2-5 enter_ok × level_aware   T2-6 마스킹 역할별 표
 */

require_once __DIR__ . '/../lib/loader.php';

load_core();
require_once h_root() . '/server/core/serializer.php';
require_once h_root() . '/server/core/access.php';

const AL_NOW = 1800000000;   // 2027-01-15 08:00:00 UTC — 고정 시각(시험이 시계에 따라 달라지지 않게)

function al_dt(int $off): string
{
    return gmdate('Y-m-d H:i:s', AL_NOW + $off);
}

function al_rank(string $l): int
{
    $m = array('none' => 0, 'view' => 10, 'use' => 20, 'admin' => 30);
    return isset($m[$l]) ? $m[$l] : 0;
}

function al_in($s, $e): bool
{
    $okS = $s === null || strtotime($s . ' UTC') <= AL_NOW;
    $okE = $e === null || AL_NOW < strtotime($e . ' UTC');
    return $okS && $okE;
}

/** 설계 §2 의 독립 오라클(level 만). */
function al_oracle(array $user, ?array $svc, ?array $us, array $grp, string $role): string
{
    if ($svc === null || $svc['status'] !== 'active') {
        return 'none';
    }
    if ($user['status'] !== 'active') {
        return 'none';
    }
    if (($user['account_kind'] ?? 'normal') === 'emergency') {
        return 'none';
    }
    if ($us !== null && $us['status'] !== 'active') {
        return 'none';
    }
    $g = $us === null ? 'inherit' : $us['access_grant'];
    $gw = $us === null ? true : al_in($us['access_starts_at'], $us['access_ends_at']);
    if ($g === 'deny' && $gw) {
        return 'none';
    }
    $rule = $svc['access_rule'];
    if (!in_array($rule, array('all', 'all_view', 'granted', 'admin'), true)) {
        return 'none';
    }
    if ($rule === 'admin' && !in_array($role, array('admin', 'superadmin'), true)) {
        return 'none';
    }
    if ($g === 'allow' && $gw) {
        return $us['access_level'] === null ? 'use' : $us['access_level'];
    }
    $base = array('all' => 'use', 'all_view' => 'view', 'granted' => 'none', 'admin' => 'use');
    $best = $base[$rule];
    foreach ($grp as $r) {
        if ($r['group_status'] === 'active' && al_in($r['m_starts_at'], $r['m_ends_at']) && al_in($r['gs_starts_at'], $r['gs_ends_at'])
            && al_rank($r['level']) > al_rank($best)) {
            $best = $r['level'];
        }
    }
    return $best;
}

function al_svc(string $rule, int $aware = 0, ?string $features = null, string $status = 'active'): array
{
    return array('service_id' => 'svc', 'status' => $status, 'access_rule' => $rule, 'level_aware' => $aware, 'features_json' => $features);
}

function al_us(string $grant, ?string $level = null, ?string $s = null, ?string $e = null, string $status = 'active', ?string $f = null): array
{
    return array('access_grant' => $grant, 'access_level' => $level, 'access_starts_at' => $s, 'access_ends_at' => $e,
        'status' => $status, 'features_json' => $f);
}

function al_grp(string $key, string $level, ?string $ms = null, ?string $me = null, ?string $gs = null, ?string $ge = null,
                string $status = 'active', ?string $scopes = null): array
{
    return array('group_key' => $key, 'group_status' => $status, 'm_starts_at' => $ms, 'm_ends_at' => $me, 'level' => $level,
        'scopes_json' => $scopes, 'gs_starts_at' => $gs, 'gs_ends_at' => $ge);
}

t('T2-1 decide matrix agrees with the independent oracle (rule 4+1 x grant 3 x period x role x groups x status)', function () {
    $periods = array('none' => array(null, null), 'before' => array(al_dt(3600), null), 'inside' => array(al_dt(-3600), al_dt(3600)),
                     'after' => array(null, al_dt(-1)));
    $groupSets = array(
        array(),
        array(al_grp('g1', 'view')),
        array(al_grp('g1', 'use', null, al_dt(-5)), al_grp('g2', 'admin', al_dt(-60), al_dt(60))),
        array(al_grp('g3', 'admin', null, null, null, null, 'disabled'), al_grp('g4', 'view', al_dt(10))),
    );
    $n = 0;
    $bad = array();
    foreach (array('all', 'all_view', 'granted', 'admin', 'bogus') as $rule) {
        foreach (array('inherit', 'allow', 'deny') as $grant) {
            foreach ($periods as $pn => $p) {
                foreach (array(null, 'view', 'admin') as $lv) {
                    foreach (array('user', 'admin') as $role) {
                        foreach ($groupSets as $gi => $grp) {
                            foreach (array('active', 'suspended') as $ust) {
                                foreach (array('active', 'blocked', 'none') as $ms) {
                                    $user = array('id' => 1, 'status' => $ust, 'role' => $role, 'account_kind' => 'normal');
                                    $svc = al_svc($rule);
                                    $us = $ms === 'none' ? null : al_us($grant, $grant === 'allow' ? $lv : null, $p[0], $p[1], $ms);
                                    $want = al_oracle($user, $svc, $us, $grp, $role);
                                    $got = access_level_decide($user, $svc, $us, $grp, AL_NOW, $role)['level'];
                                    $n++;
                                    if ($want !== $got && count($bad) < 8) {
                                        $bad[] = "$rule/$grant/$pn/lv=" . var_export($lv, true) . "/$role/g$gi/$ust/$ms want $want got $got";
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    assert_eq(array(), $bad, 'decide != oracle');
    assert_true($n > 5000, 'HARNESS-FAIL matrix too small: ' . $n);
    note('T2-1 matrix: ' . $n . ' cases');
    // 고정 단언 몇 개(오라클 자체가 틀렸을 때를 대비한 앵커, 규약 7)
    $u = array('id' => 1, 'status' => 'active', 'role' => 'superadmin', 'account_kind' => 'normal');
    assert_eq('none', access_level_decide($u, al_svc('all'), al_us('deny'), array(al_grp('g', 'admin')), AL_NOW, 'superadmin')['level'],
        'S2 deny beats every source, superadmin included');
    $uu = array('id' => 1, 'status' => 'active', 'role' => 'user', 'account_kind' => 'normal');
    assert_eq('none', access_level_decide($uu, al_svc('admin'), al_us('allow', 'admin'), array(al_grp('g', 'admin')), AL_NOW, 'user')['level'],
        'S3 admin rule: neither group nor allow admits a non-admin');
    assert_eq('view', access_level_decide($uu, al_svc('all'), al_us('allow', 'view'), array(al_grp('g', 'admin')), AL_NOW, 'user')['level'],
        'override is exact (can lower below a group)');
    assert_eq('none', access_level_decide(array('id' => 1, 'status' => 'active', 'role' => 'superadmin', 'account_kind' => 'emergency'),
        al_svc('all'), null, array(), AL_NOW, 'superadmin')['level'], 'S9 emergency account never gets a service level');
    $d = access_level_decide($uu, al_svc('granted'), null, array(al_grp('team', 'use')), AL_NOW, 'user');
    assert_eq(array('use', 'group:team'), array($d['level'], $d['source']), 'group source');
    assert_eq('none', access_level_decide($uu, al_svc('all'), al_us('allow', 'root'), array(), AL_NOW, 'user')['level'], 'S11 unknown level -> none');
});

t('T2-2 after 0010 (no groups, no level, no periods) the bool map equals the v1.9.3 decision for every combination', function () {
    $n = 0;
    $bad = array();
    foreach (array('all', 'granted', 'admin', 'bogus') as $rule) {
        foreach (array(null, 'inherit', 'allow', 'deny') as $grant) {
            foreach (array('user', 'manager', 'admin', 'superadmin', '') as $role) {
                foreach (array('active', 'suspended', 'withdrawn') as $ust) {
                    foreach (array('active', 'blocked') as $mst) {
                        foreach (array('active', 'disabled') as $sst) {
                            $user = array('id' => 1, 'status' => $ust, 'role' => $role, 'account_kind' => 'normal');
                            $svc = al_svc($rule, 0, null, $sst);
                            $us = $grant === null ? null : al_us($grant, null, null, null, $mst);
                            // v1.9.3 의 bool(service_access_map + 서비스 활성): 비활성 계정·차단 → false, 그 밖 service_access_decide
                            if ($sst !== 'active' || $ust !== 'active' || ($us !== null && $mst !== 'active')) {
                                $old = false;
                            } else {
                                $old = service_access_decide($rule, $us === null ? 'inherit' : $grant, $role === '' ? 'user' : $role)['allowed'];
                            }
                            $d = access_level_decide($user, $svc, $us, array(), AL_NOW, $role === '' ? 'user' : $role);
                            $new = access_rank($d['level']) >= ACCESS_RANK['use'];
                            $n++;
                            if ($old !== $new && count($bad) < 8) {
                                $bad[] = "$rule/" . var_export($grant, true) . "/$role/$ust/$mst/$sst old " . var_export($old, true);
                            }
                            // 입장 판정도 같다(view 가 생기지 않으므로)
                            if ($old !== access_enter_ok($d, $svc) && count($bad) < 8) {
                                $bad[] = "enter $rule/" . var_export($grant, true) . "/$role/$ust/$mst/$sst";
                            }
                        }
                    }
                }
            }
        }
    }
    assert_eq(array(), $bad, 'v1.10.0 bool differs from v1.9.3');
    note('T2-2 equivalence: ' . $n . ' combinations');
});

t('T2-3 until is the earliest future boundary across override, membership and group-service rows', function () {
    $u = array('id' => 1, 'status' => 'active', 'role' => 'user', 'account_kind' => 'normal');
    $d = access_level_decide($u, al_svc('granted'), al_us('allow', 'use', al_dt(-100), al_dt(7200)),
        array(al_grp('a', 'view', null, al_dt(3600)), al_grp('b', 'use', al_dt(-50), null, null, al_dt(600))), AL_NOW, 'user');
    assert_eq(gmdate('Y-m-d\TH:i:s\Z', AL_NOW + 600), $d['until'], 'earliest future boundary');
    $d = access_level_decide($u, al_svc('all'), null, array(), AL_NOW, 'user');
    assert_eq(null, $d['until'], 'no rows -> null');
    $d = access_level_decide($u, al_svc('granted'), al_us('allow', null, al_dt(300), null), array(), AL_NOW, 'user');
    assert_eq(array('none', gmdate('Y-m-d\TH:i:s\Z', AL_NOW + 300)), array($d['level'], $d['until']), 'future start reported even while none');
});

t('T2-4 scopes: override list wins, else union of in-window groups, else default:true; always ∩ registry; broken JSON -> []', function () {
    $reg = json_encode(array(array('id' => 'export', 'name' => 'E', 'default' => true), array('id' => 'edit', 'name' => 'Ed', 'default' => false),
        array('id' => 'share', 'name' => 'S', 'default' => false)));
    $u = array('id' => 1, 'status' => 'active', 'role' => 'user', 'account_kind' => 'normal');
    $svc = al_svc('all', 0, $reg);
    assert_eq(array('export'), access_level_decide($u, $svc, null, array(), AL_NOW, 'user')['scopes'], 'default:true');
    $g = array(al_grp('a', 'use', null, null, null, null, 'active', '["edit"]'), al_grp('b', 'view', null, null, null, null, 'active', '["share","ghost"]'),
        al_grp('c', 'use', null, al_dt(-1), null, null, 'active', '["export"]'));
    assert_eq(array('edit', 'share'), access_level_decide($u, $svc, null, $g, AL_NOW, 'user')['scopes'], 'group union ∩ registry, expired group ignored');
    assert_eq(array('share'), access_level_decide($u, $svc, al_us('inherit', null, null, null, 'active', '["share"]'), $g, AL_NOW, 'user')['scopes'],
        'account list beats groups');
    assert_eq(array(), access_level_decide($u, $svc, al_us('inherit', null, null, null, 'active', '{bad'), array(), AL_NOW, 'user')['scopes'],
        'broken account JSON -> []');
    assert_eq(array(), access_level_decide($u, $svc, null, array(al_grp('a', 'use', null, null, null, null, 'active', 'nope')), AL_NOW, 'user')['scopes'],
        'broken group JSON contributes nothing (S11)');
    assert_eq(null, access_level_decide($u, al_svc('all'), null, $g, AL_NOW, 'user')['scopes'], 'no registry -> null');
    assert_eq(array(), access_level_decide($u, al_svc('granted', 0, $reg), null, array(), AL_NOW, 'user')['scopes'], 'level none -> []');
});

t('T2-5 enter_ok: use/admin always, view only when the service declares level_aware=1, none never', function () {
    foreach (array('none' => array(false, false), 'view' => array(false, true), 'use' => array(true, true), 'admin' => array(true, true)) as $lv => $want) {
        assert_eq($want[0], access_enter_ok(array('level' => $lv), al_svc('all', 0)), $lv . ' unaware');
        assert_eq($want[1], access_enter_ok(array('level' => $lv), al_svc('all', 1)), $lv . ' aware');
    }
    assert_false(access_enter_ok(array('level' => 'view'), null), 'no service row -> closed');
});

t('T2-6 masking by viewer role (manager / admin / superadmin)', function () {
    $tab = array(
        array('email', 'opsuser@example.com', array('manager' => 'o***@example.com', 'admin' => 'opsuser@example.com', 'superadmin' => 'opsuser@example.com')),
        array('phone', '010-1234-5678', array('manager' => '***5678', 'admin' => '010-1234-5678', 'superadmin' => '010-1234-5678')),
        array('ip', '203.0.113.77', array('manager' => '203.0.113.x', 'admin' => '203.0.113.x', 'superadmin' => '203.0.113.77')),
        array('ip', '2001:db8:abcd:12::1', array('manager' => '2001:db8:abcd::/48', 'admin' => '2001:db8:abcd::/48', 'superadmin' => '2001:db8:abcd:12::1')),
        array('username_input', array('Secr3t!pw', false), array('manager' => '(없는 아이디 9자)', 'admin' => '(없는 아이디 9자)', 'superadmin' => '(없는 아이디 9자)')),
        array('username_input', array('hong', true), array('manager' => 'hong', 'admin' => 'hong', 'superadmin' => 'hong')),
    );
    foreach ($tab as $row) {
        foreach ($row[2] as $role => $want) {
            assert_eq($want, mask_for_viewer($row[0], $row[1], $role), $row[0] . ' as ' . $role);
        }
    }
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36';
    assert_true(mask_for_viewer('ua', $ua, 'admin') !== $ua, 'UA hidden from admin');
    assert_eq($ua, mask_for_viewer('ua', $ua, 'superadmin'), 'UA raw for superadmin');
    assert_eq(null, mask_for_viewer('ip', null, 'manager'), 'null stays null');
    $v = mask_user_view(array('email' => 'a@b.co', 'phone' => '0101234', 'last_login_ip' => '198.51.100.9', 'meta' => array('x' => 1)), 'manager');
    assert_eq(array('a***@b.co', '***1234', '198.51.100.x'), array($v['email'], $v['phone'], $v['last_login_ip']), 'user view for manager');
    assert_true($v['meta'] instanceof stdClass, 'meta {} for manager');
});
