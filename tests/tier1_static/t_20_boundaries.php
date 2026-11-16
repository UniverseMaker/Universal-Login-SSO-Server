<?php
declare(strict_types=1);

/**
 * 티어 1 — 경계면 대조 (엔드포인트 · 에러 코드 · 링크)
 *
 * QA 가 매 라운드 손으로 하던 교차 대조를 그대로 코드로 옮긴 것이다.
 * 새 검증을 발명한 게 아니라 **이미 하고 있던 일을 저장**한다.
 */

require_once __DIR__ . '/../lib/checks.php';

$ROOT = h_root();

t('every endpoint file has a caller and every call has a file', function () use ($ROOT) {
    // 호출부가 없어도 되는 엔드포인트. **사유를 여기에 적는다. 목록을 조용히 늘리지 마라.**
    $allowUncalled = array(
        // 운영용 헬스체크. 화면이 아니라 배포 절차·모니터링이 부른다
        // (INSTALL.md 8절이 배포 직후 curl 로 확인하라고 적는다).
        '/api/health',
        // v1.8.4 서버 대 서버 — 부르는 쪽은 다른 시스템의 서버다(설계 92 S-2·S-4, 브라우저 호출 금지).
        // 소비자는 배정됐다: office·manager·learning·research(개명 전 aim·alc·rsa) 가 이미 이 계약으로 운영 중이다(D-331).
        '/api/sso/accounts_push',
        '/api/sso/link_events',
        // v1.9.0 서버 대 서버(설계 116 §0 · §7, service_secret 본문 인증 — 브라우저 호출 금지).
        // 소비자: 각 시스템 서버(§0 계약 116s0 발송, D-354) · AI 관리(userinfo·users, D-350) · AI_INTEGRATION_GUIDE.md 플랫폼 절(integrator).
        '/api/platform/audit',
        '/api/platform/collections',
        '/api/platform/features',
        '/api/sso/userinfo',
        '/api/sso/users',
        // v1.10.0 — 공유 대상 계정 찾기(s2s, 설계 127 §3-3). 소비자: 각 시스템 서버(주관 세션 경유 통지)
        '/api/sso/user_search',
    );
    assert_clean(chk_endpoints($ROOT, $allowUncalled), 'endpoint <-> caller mismatch');
});

t('endpoint inventory is non-empty (the collector is not blind)', function () use ($ROOT) {
    // "0건 = 결함 없음" 오독을 막는 이중 확인. 수집기가 침묵하면 위 케이스가
    // 항상 통과한다 — 이 저장소에서 실제로 그 유형의 거짓 결과가 반복해서 났다.
    $files = ck_endpoint_files($ROOT);
    $calls = ck_endpoint_calls($ROOT);
    if (count($files) < 10) {
        fail('collected only ' . count($files) . ' endpoint files - the collector is blind, not the tree empty');
    }
    if (count($calls) < 10) {
        fail('collected only ' . count($calls) . ' front-end call paths - the collector is blind');
    }
});

t('error codes line up across server throws, response.php and api.js', function () use ($ROOT) {
    // 서버가 응답하지 않는 클라이언트 전용 의사 코드.
    // fetch 자체가 실패(오프라인·CORS·서버 다운)했을 때만 쓰인다 — api.js 가 그렇게 적어 두었다.
    $clientOnly = array('NETWORK_ERROR');
    assert_clean(chk_error_codes($ROOT, $clientOnly), 'error code table mismatch');
});

t('error code inventory is non-empty (the collector is not blind)', function () use ($ROOT) {
    $thrown = ck_codes_thrown($ROOT);
    $srv    = ck_codes_server_table($ROOT);
    $cli    = ck_codes_client_table($ROOT);
    if (count($thrown) < 10 || count($srv) < 10 || count($cli) < 10) {
        fail('thrown=' . count($thrown) . ' server_table=' . count($srv) . ' client_table=' . count($cli)
            . ' - at least one collector returned almost nothing, which means it is blind');
    }
});

t('every .html link target exists on disk', function () use ($ROOT) {
    assert_clean(chk_page_links($ROOT), 'dead page link');
});

t('.htaccess still carries the canonical rules', function () use ($ROOT) {
    // **못 잡는 것을 먼저 적는다:** 이 검사는 파일에 규칙이 남아 있는지만 본다.
    // Apache 가 실제로 그 헤더를 내보내는지는 정적으로 알 수 없고,
    // `AllowOverride None` 이나 nginx 에서는 파일이 통째로 무시된다.
    // 배포 후 `curl -I` 확인이 계속 필요하다.
    assert_clean(chk_htaccess_rules($ROOT), 'htaccess rule missing');
});
