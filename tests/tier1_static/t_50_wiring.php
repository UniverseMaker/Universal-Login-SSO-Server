<?php
declare(strict_types=1);

/**
 * 티어 1 — 생산자 ↔ 소비자 배선 (D-172 / D-181 규약 1)
 *
 * v1.4.0 에서 백엔드가 새 필드를 만들었는데 화면 담당을 같은 표에 배정하지 않았고,
 * 그 결과 화면이 **「서버가 그 값을 주지 않는다」는 거짓 문구**를 띄웠다.
 * 서버도 정상, 화면도 정상, 붙인 결과만 거짓말. 이 티어가 그 유형을 노린다.
 *
 * ── 이 검사가 못 잡는 것 (먼저 적는다) ────────────────────────────────
 *  · 이름은 맞는데 **의미가 다른** 배선. `status` 를 양쪽이 다르게 해석하는 경우
 *  · 런타임에 조립되는 응답. `json_out($out)` 처럼 변수를 넘기면 shape 을 정적으로
 *    알 수 없다 — 아래 세 번째 케이스가 그 수를 **명시적으로 세고 예산을 건다.**
 *    조용히 "키 0개"로 접히지 않게 하는 것이 목적이다
 *  · JS 가 런타임에 만드는 DOM 속성. 이 조사에서 실제로 겪은 함정이다 —
 *    HTML 만 grep 하면 `ui.js` 의 `tabs()` 가 설정하는 ARIA 역할이 0건으로 보인다.
 *    **없는 결함을 만드는 방향의 거짓이라 더 나쁘다.** 정적 검사는 런타임 설정
 *    속성을 판정하지 않는다
 * ─────────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/../lib/checks.php';

$ROOT = h_root();

t('every response field a server endpoint produces has a consumer (D-181 rule 1)', function () use ($ROOT) {
    /*
     * 소비자가 없어도 되는 필드. **한 줄에 하나, 사유를 반드시 적는다.**
     *
     * 이 목록을 늘리는 것은 "소비자를 배정하지 않기로 결정했다" 는 선언이다.
     * 새 필드를 만들면서 화면 담당을 같은 라운드에 배정하지 않았다면
     * 여기에 줄을 추가하는 대신 **담당을 배정하라** — 그것이 D-172 의 결론이다.
     */
    $allowUnconsumed = array(
        // --- api/health.php : 운영용 엔드포인트. 화면이 아니라 배포 절차·모니터링이 읽는다 ---
        'checks',     // 헬스체크 항목별 판정
        'config',     // 설정 점검 결과
        'counts',     // 테이블 행 수
        'db',         // DB 접속 판정
        'installer',  // install.php 잔존 여부
        'schema',     // 스키마 버전
        'time',       // 서버 시각

        // --- 관리자 조작의 감사 에코. 화면은 성공/실패만 쓰고 본문은 admin_logs 탭에서 본다 ---
        'admin_log_id',     // 방금 남긴 admin_logs 행 id
        'deleted',          // 삭제 건수 에코
        'previous_status',  // 상태 변경 전 값 에코
        'restored_at',      // 복구 시각 에코

        // --- 확인 응답. 화면은 200 여부로 분기하고 본문을 읽지 않는다 ---
        'all_devices',      // logout: 전 기기 로그아웃이었는가
        'changed',          // me/password: 실제로 바뀌었는가
        'log_id',           // points/consume: 남긴 point_logs 행 id
        'revoked',          // me/sessions: 회수한 세션 수

        // --- OAuth 관례 필드. 클라이언트가 이름으로 읽지 않는다 ---
        'token_type',          // 항상 "Bearer". ulogin-client.js 가 상수로 취급한다
        'refresh_expires_in',  // refresh_token 수명. 클라이언트는 401 을 보고 갱신한다

        // --- **연동 앱이 읽는 필드.** ulogin 의 화면에는 소비자가 없는 것이 맞다 ---
        // `issued_via` (api/auth/exchange.php, v1.7.0 수정 라운드 / 감사 M17-4):
        //   'password' | 'session'. 이 code 가 비밀번호를 받고 나왔는가, 기존 쿠키
        //   세션에서 조용히 나왔는가. **ulogin 자신의 화면은 exchange 를 부르지 않는다** —
        //   부르는 것은 연동 앱이고, 이 값의 용도가 바로 그 앱의 재인증(step-up) 검증이다.
        //   `prompt=login` 처리는 `login.html` 의 JS 한 줄이라 URL 조작으로 사라지므로,
        //   **보증은 서버가 말하는 사실로만 성립한다.**
        //   >> **소비자는 배정됐다. 화면이 아닐 뿐이다**(D-172):
        //      `AI_INTEGRATION_GUIDE.md` 의 「재인증이 필요하면 `prompt=login` 을 보내고
        //      `issued_via === 'password'` 를 확인하라」 — **integrator 담당, 같은 라운드.**
        //   >> 같은 범주의 선례가 바로 위 두 줄(`token_type`·`refresh_expires_in`)이다.
        'issued_via',

        // --- v1.8.4 계정 연결 — **다른 시스템의 서버가 읽는 필드.** ulogin 화면에는 소비자가 없는 것이 맞다 ---
        // `account_link` (exchange·session, 설계 92 S-5): 연결된 로컬 계정 | null | 키 없음(옛 서버).
        //   소비자: 각 시스템의 SSO 콜백(office·manager·learning·research 선구현, 개명 전 aim·alc·rsa, D-331) + AI_INTEGRATION_GUIDE.md S절(integrator).
        'account_link',
        // `link_events.php` 피드 3키(S-4). 부르는 쪽은 시스템 서버의 주기 작업이다.
        'events',
        'next_after',
        'has_more',

        // --- v1.9.0 플랫폼 — **다른 시스템의 서버가 읽는 필드**(설계 116 §0-7·§0-8, 계약 116s0 발송, D-354) ---
        //   소비자: 각 시스템의 스풀 flush 작업(§0-5 표) + examples/platform/* · AI_INTEGRATION_GUIDE.md 플랫폼 절(integrator).
        'receipt',    // audit: 체인 위치·해시 — 시스템이 로컬 감사 저장소에 같이 적는다
        'redacted',   // logs·audit: 0 이 아니면 그쪽 마스킹을 고쳐라
        'rejected',   // logs·audit: 항목별 거절 사유
        // health 의 감사 체인 판정 — 모니터링·배포 절차가 읽는다(위 health 묶음과 같은 성격)
        'audit_chain_ok',
    );
    assert_clean(
        chk_unconsumed_response_fields($ROOT, $allowUnconsumed),
        'server produces a response field that no screen reads (assign the consumer, do not just allow it)'
    );
});

t('response-key collector is not blind', function () use ($ROOT) {
    $keys = ck_response_keys($ROOT);
    if (count($keys) < 40) {
        fail('collected only ' . count($keys) . ' distinct json_out() keys - the collector is blind, not the API empty');
    }
});

t('the number of statically unanalyzable json_out() call sites stays within budget', function () use ($ROOT) {
    /*
     * `json_out($someVariable)` 은 shape 을 정적으로 알 수 없다. 그런 호출부가
     * 늘어나면 위 배선 검사의 사각지대가 조용히 넓어진다 — 검사는 계속 "0건"을 낸다.
     *
     * 그래서 **사각지대의 크기를 직접 잰다.** 예산이다. 넘으면 실패하고,
     * 그때 할 일은 예산을 늘리는 것이 아니라 그 호출부를 리터럴로 되돌리거나
     * 배선을 사람이 확인하고 여기에 사유를 적는 것이다.
     */
    $budget = 1;   // 현재: api/admin/user_points.php 의 `json_out($out)` 하나
    $sites = chk_unanalyzable_responses($ROOT);
    if (count($sites) > $budget) {
        fail('unanalyzable json_out() sites grew from ' . $budget . ' to ' . count($sites)
            . " - the wiring check is going blind there:\n     - " . implode("\n     - ", $sites));
    }
    if ($sites !== array()) {
        // **NOTE 는 안내가 부실하면 없는 것과 같다.** 종료코드에 들어가지 않으므로
        // 읽는 사람이 그 자리에서 손으로 확인할 수 있어야 한다. 그래서 파일명만
        // 던지지 않고 조립 지점과 최선 추정 shape 을 함께 적는다.
        $lines = array();
        foreach ($sites as $s) {
            $rel  = trim(explode('::', $s)[0]);
            $hint = ck_opaque_shape_hint($ROOT, $rel);
            $lines[] = $s
                . "\n       assembled at: the `return [ … ];` inside that file (db_tx() closure)"
                . "\n       keys seen there (best-effort, NOT diffed by this checker): "
                . ($hint === array() ? '(none found - resolve by hand)' : implode(', ', $hint));
        }
        note('wiring check cannot see the shape of ' . count($sites) . ' json_out() call site(s); '
            . "they are excluded from the producer/consumer diff.\n     - " . implode("\n     - ", $lines)
            . "\n     the next case pins those keys, so a silent shape change fails instead of widening the blind spot.");
    }
});

t('the one opaque json_out() site has its keys pinned by hand (D-172 hole)', function () use ($ROOT) {
    /*
     * 앞 케이스는 사각지대의 **크기**만 잰다. 크기가 그대로여도 **그 안의 shape 이
     * 바뀌면** 배선 검사는 여전히 아무 말을 하지 않는다. D-172(생산자↔소비자)를
     * 게이트화한 검사인데 한 곳이 사각이면 그 게이트에 구멍이 있는 것이고,
     * 구멍의 크기가 아니라 **구멍 안이 변했는지**를 봐야 그 구멍이 닫힌다.
     *
     * 그래서 그 한 곳의 키를 **손으로 못박는다.** shape 이 바뀌면 이 케이스가 실패하고,
     * 그때 할 일은 목록을 갱신하는 것이 아니라 **새 키의 소비자를 배정하는 것**이다.
     *
     * **이 케이스가 증명하지 않는 것:** shape 이 *옳다*는 것. 스냅샷 게이트다 —
     * 조용히 바뀌는 것만 막는다. 의미의 정합은 사람이 본다.
     *
     * 오늘 이 키 8개가 배선 검사를 통과하는 이유는 **우연히 전부 다른 엔드포인트에서도
     * 생산되기 때문**이다(`log_id`·`admin_log_id` 는 위 `$allowUnconsumed` 에 사유와 함께
     * 올라 있다). 이 파일에만 있는 키가 새로 생기면 그 우연이 깨지고, 양방향 diff 가
     * 둘 다 조용해진다 — 그것을 여기서 막는다.
     */
    $rel = 'server/api/admin/user_points.php';
    $expected = array(
        'admin_log_id',        // 감사 에코. $allowUnconsumed 에 사유 등재
        'balance_after',       // mypage/admin 이 읽는다
        'created_at',          // mypage/admin 이 읽는다
        'delta',               // mypage/admin 이 읽는다
        'log_id',              // 확인 응답. $allowUnconsumed 에 사유 등재
        'points',              // mypage/admin/ulogin-client.js 가 읽는다
        'total_points_earned', // mypage/admin 이 읽는다
        'total_points_spent',  // mypage/admin/ulogin-client.js 가 읽는다
    );
    $actual = ck_opaque_shape_hint($ROOT, $rel);
    if ($actual === array()) {
        fail('resolved 0 keys from the return literal in ' . $rel
            . ' - the hint resolver is blind, not the response empty');
    }
    $added   = array_values(array_diff($actual, $expected));
    $removed = array_values(array_diff($expected, $actual));
    if ($added !== array() || $removed !== array()) {
        fail('the opaque response shape in ' . $rel . " changed while the wiring diff cannot see it.\n"
            . '     new key(s): ' . ($added === array() ? '(none)' : implode(', ', $added)) . "\n"
            . '     gone key(s): ' . ($removed === array() ? '(none)' : implode(', ', $removed)) . "\n"
            . '     assign a consumer for each new key (D-172), then update the pinned list here with the reason.');
    }
    // 못박은 키마다 소비자가 있는지 직접 본다 — 위 diff 가 이 파일을 건너뛰므로
    // 여기가 그 8개에 대한 유일한 소비자 검사다.
    $blob = ck_front_blob($ROOT);
    $exemptHere = array(
        'admin_log_id',  // 관리자 조작의 감사 에코. 화면은 admin_logs 탭에서 본다
        'log_id',        // 확인 응답. 화면은 200 여부로 분기한다
    );
    $orphan = array();
    foreach ($expected as $k) {
        if (in_array($k, $exemptHere, true)) {
            continue;
        }
        if (preg_match('~[\'"`.\[]' . preg_quote($k, '~') . '\b~', $blob) !== 1) {
            $orphan[] = $k;
        }
    }
    if ($orphan !== array()) {
        fail('the opaque site produces ' . implode(', ', $orphan)
            . ' but no screen reads it, and it is not exempted here (D-172: assign the consumer)');
    }
});

t('every .data.X the front end reads is a field some server response carries', function () use ($ROOT) {
    // 반대 방향. 화면이 없는 필드를 읽으면 JS 는 예외 없이 `undefined` 를 준다 —
    // 서버도 화면도 에러를 내지 않고 값만 비어 있다. 이 프로젝트에서 가장 흔한 실패 유형.
    $produced = array_keys(ck_response_keys($ROOT));
    // json_err 의 extra 키(계약 §1-1 대로 data 에 실린다)도 생산자다
    foreach (h_files($ROOT . '/server', array('php')) as $f) {
        $s = h_php_code_only_keep_strings(h_read($f));
        foreach (array('new AppError', 'json_err') as $fn) {
            foreach (h_call_args($s, $fn) as $a) {
                $parts = h_split_args($a);
                if (count($parts) < 3) {
                    continue;
                }
                $isLit = false;
                foreach (h_array_top_keys($parts[2], $isLit) as $k) {
                    $produced[] = $k;
                }
            }
        }
    }
    $produced = array_values(array_unique($produced));
    if (count($produced) < 40) {
        fail('producer set has only ' . count($produced) . ' fields - the collector is blind');
    }

    $bad = array();
    foreach (ck_front_files($ROOT) as $f) {
        // 공용 research-ui 원본(바이트 고정, t_60)은 이 서버의 API 가 아니라 **다른 시스템의 봉투**도 읽는다.
        // G18 의 RUI.updates 가 `/version.json` 응답에서 `j.version || j.data.version` 을 본다(v1.8.7). 우리 version.json 은 앞쪽 형태다.
        if (substr(h_rel($f, $ROOT), -strlen('assets/rui-theme.js')) === 'assets/rui-theme.js') {
            continue;
        }
        $s = h_js_strip_comments(h_read($f));
        if (!preg_match_all('~\.data\.([a-zA-Z_][a-zA-Z0-9_]*)~', $s, $m)) {
            continue;
        }
        foreach (array_unique($m[1]) as $k) {
            if (!in_array($k, $produced, true)) {
                $bad[] = h_rel($f, $ROOT) . ' reads .data.' . $k . ' but no server response carries that field';
            }
        }
    }
    assert_clean(array_values(array_unique($bad)), 'front end reads a field nobody produces');
});
