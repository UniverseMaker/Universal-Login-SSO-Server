<?php
/**
 * tests/config.sample.php — 티어 3(실측) 접속 정보 템플릿
 *
 * **이 파일을 고치지 마라.** 복사해서 `tests/config.local.php` 를 만들어라.
 * `config.local.php` 는 `.gitignore` 대상이다 — 개발자마다 값이 다르고,
 * 저장소에 들어가면 다음 사람의 환경을 조용히 덮어쓴다.
 *
 *     cp tests/config.sample.php tests/config.local.php
 *     (값을 채우고)
 *     php tests/run.php --tier=3
 *
 * ─── 환경변수가 1순위다 ──────────────────────────────────────────────
 * CI 에서는 파일을 만들지 않고 환경변수만 준다. 둘 다 있으면 환경변수가 이긴다.
 *
 *     ULOGIN_TEST_DB_HOST=127.0.0.1
 *     ULOGIN_TEST_DB_PORT=3306
 *     ULOGIN_TEST_DB_USER=root
 *     ULOGIN_TEST_DB_PASS=
 *     ULOGIN_TEST_DB_NAME=ulogin_test
 *     ULOGIN_TEST_HTTP_PORT=8199
 *
 * ─── 무엇을 요구하는가 ───────────────────────────────────────────────
 * **실 MySQL / MariaDB.** SQLite 스텁은 쓰지 않는다 — `server/schema.sql` 이
 * MySQL 전용(`ENGINE=InnoDB`·`ROW_FORMAT=DYNAMIC`·`utf8mb4_unicode_ci`)이고
 * 코드가 `SUM(status = ?)` 같은 MySQL 관용구에 의존한다. 두 번째 스키마를
 * 만드는 순간 그 둘이 갈라지고, **그 드리프트가 정확히 이 프로젝트가 반복해
 * 맞은 경계면 버그의 종류다.**
 *
 * 계정에는 `CREATE DATABASE` · `DROP DATABASE` 권한이 필요하다.
 * 러너는 이름이 **`ulogin_test` 로 시작하는 DB 만** 만들고 지운다 —
 * 다른 이름을 주면 거부한다. 운영 DB 를 가리켜도 지워지지 않는다.
 *
 * ─── 없으면 어떻게 되는가 ────────────────────────────────────────────
 * 티어 3 이 **전부 SKIP** 되고, 티어 1·2 도 같이 돌지 않았다면 러너는
 * 종료코드 **3**(한 건도 실행되지 않음)으로 끝난다. **`0` 이 아니다.**
 * "통과"와 "아무것도 안 돌았다"를 같은 코드로 내보내면 회귀 게이트는
 * 조용히 항상 통과한다.
 *
 * ─── 남는 것 ─────────────────────────────────────────────────────────
 * 없다. 러너는 종료 시(실패·예외 포함) 테스트 DB 를 DROP 하고, 임시
 * 샌드박스 웹루트를 지우고, `php -S` 를 종료한다.
 * 저장소 트리에는 파일 하나 만들지 않는다 — 서버는 **복사본**에 대고 뜬다.
 */

return array(
    'db_host'   => '127.0.0.1',
    'db_port'   => 3306,
    'db_user'   => 'root',
    'db_pass'   => '',
    // 이름은 반드시 `ulogin_test` 로 시작해야 한다. 러너가 강제한다.
    'db_name'   => 'ulogin_test',
    // `php -S` 가 쓸 포트. 이미 쓰이고 있으면 티어 3 이 SKIP 된다.
    'http_port' => 8199,
);
