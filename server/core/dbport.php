<?php
/**
 * core/dbport.php — DB 접속 **포트** 해석. 판정식이 있는 **유일한 곳**.
 * 담당 계약: `_workspace/75_architect_v17_ports.md` §3-3 / 리더 판정 D-240 · D-241
 *
 * =====================================================================
 * ▣ 이 파일이 존재하는 이유 — **3306 은 사용자의 살아 있는 DB 다**
 *
 * v1.6.0 까지 DSN 은 `mysql:host=…;dbname=…;charset=…` 이고 **포트 요소가 없었다.**
 * 포트가 없는 DSN 은 드라이버 기본값 **3306** 으로 간다. 이 PC 에서 3306 은
 * **사용자의 운영 MariaDB** 다. 즉 전용 인스턴스를 3307 에 띄워 놓아도
 * `DB_HOST='127.0.0.1'` 만으로는 **엉뚱한 DB 에 조용히 붙는다.**
 *
 * 그래서 이 파일의 규칙은 전부 같은 방향을 가리킨다:
 *   **폴백하지 않는다 · 재시도하지 않는다 · 포트를 탐색하지 않는다.**
 * 「안 붙으면 기본값도 해 본다」류의 친절은 이 PC 에서
 * **사용자의 살아 있는 DB 에 접속하는 코드**가 된다. 넣지 않는 것 자체가 방어다.
 *
 * ▣ 해석 순서 (프로세스 수명 동안 **1회**. 요청마다 다시 하지 않는다)
 *
 *   1) 환경변수 `ULOGIN_DB_PORT` 가 **설정돼 있으면**
 *        1~65535 정수로 파싱된다 → 그 값
 *        파싱되지 않는다        → **RuntimeException. 폴백하지 않는다**
 *   2) 없으면 `defined('DB_PORT')` → 같은 범위 검사, 같은 fail-closed
 *   3) 둘 다 없으면 **null** = DSN 에 port 요소를 넣지 않는다 (v1.6.0 과 완전히 동일)
 *
 * **1) 에서 폴백하지 않는 것이 이 파일의 핵심이다.** 환경변수가 **있다는 것** 자체가
 * 「서버 지킴이가 포트를 지정했다」는 선언이다. 그 값이 깨졌을 때 조용히 `config.php`
 * 로 내려가면 운영자는 등록부를 바꿨다고 믿는데 서버는 다른 포트에 붙는다.
 * 최악은 2)·3) 까지 흘러 **포트 미지정 → 3306 → 사용자의 살아 있는 DB** 다.
 * D-53(BASE_URL) · D-67(TRUST_PROXY) · D-75(쿠키 secure) 와 **같은 계열의 fail-closed** 다.
 *
 * ▣ 3) 이 남아 있는 이유 — **기존 설치본을 깨지 않는다**
 * 공용 호스팅에 올라간 v1.0.0~v1.6.0 설치본에는 `DB_PORT` 상수가 없다. 그 서버의
 * DSN 문자열이 **한 글자도 바뀌지 않아야** 한다. `defined()` 가드가 그것을 보장한다.
 * 이 배포(상시 서버)에서는 설치기가 포트를 받아 `DB_PORT` 를 쓰므로 3) 에 떨어지지 않고,
 * 떨어졌다면 `health.php` 의 `db_port_pinned` 가 **false** 로 보인다.
 *
 * =====================================================================
 * ▣ 읽는 쪽이 **셋**이다. 그래서 core/ 에 있지만 core 규약을 하나 비튼다
 *
 *   server/core/db.php   — 일반 요청 경로 (bootstrap 을 거친다)
 *   server/migrate.php   — 마이그레이션 러너. **bootstrap 도 core/ 도 로드하지 않는다**
 *   server/api/health.php — 불리언 하나만 읽는다 (bootstrap 경유)
 *
 * `migrate.php` 가 core/ 를 피하는 이유는 「core 의 SELECT 가 최신 스키마를 전제하므로
 * 스키마가 낡은 상태에서 러너 자신이 죽는다」였다. **이 파일에는 쿼리가 한 줄도 없다.**
 * 상수와 환경변수만 읽는 순수 함수뿐이라 그 이유에 해당하지 않는다.
 *
 * 그래서 직접 실행 가드가 **두 상수 중 하나**를 받는다 (D-34 는 그대로 지킨다):
 *   `ULOGIN_BOOTSTRAPPED` (core 경로) 또는 `ULOGIN_MIGRATE_CTX` (러너 경로)
 *
 * `declare(strict_types=1)` 을 **쓰지 않는다.** `migrate.php` 와 `install.php` 가
 * 그 선언 없이 도는 파일이고, 이 파일의 함수를 그쪽에서 부른다. 섞어서 생기는
 * 혼동을 피하고 방어적 캐스팅으로 대신한다 — `migrations/lib.php` 와 같은 규약이다.
 *
 * ▣ **판정식을 복사하지 마라.** DSN 두 곳(`core/db.php` · `migrate.php`)이 이 함수를
 * 부른다. 한쪽에 판정을 복사하면 그 즉시 두 곳이 갈리고, 갈리는 방향이
 * **「마이그레이션만 3306 에 붙는다」**다 — V14b-M-3(축약 사본이 마이그레이션 화면만
 * 403 으로 만든 사고)과 정확히 같은 형태다. 그래서 DSN 의 **포트 조각 문자열까지**
 * `ulogin_db_dsn_port()` 한 함수가 만든다.
 * =====================================================================
 */

// 직접 실행 차단 — 리더 판정 D-34.
if (!defined('ULOGIN_BOOTSTRAPPED') && !defined('ULOGIN_MIGRATE_CTX')) {
    header_remove('X-Powered-By');
    http_response_code(404);
    exit;
}

/** 환경변수 이름. `SK_PORT` 를 쓰지 않는다 — 규격 17.2 가 그 이름을 **자기 수신 포트**로
 *  못박았고, 뜻을 바꿔 쓰면 다른 시스템과 의미가 갈린다. */
if (!defined('ULOGIN_DB_PORT_ENV')) {
    define('ULOGIN_DB_PORT_ENV', 'ULOGIN_DB_PORT');
}

/**
 * 문자열·숫자를 TCP 포트로 엄격하게 파싱한다.
 * `(int)` 캐스팅을 쓰지 않는다 — `(int)'3307abc'` 는 3307 이고 `(int)'abc'` 는 0 이다.
 * **쓰레기 값이 그럴듯한 포트로 둔갑하는 경로**를 만들지 않는다.
 *
 * @return int|null 1~65535 의 정수, 아니면 null
 */
function ulogin_port_parse($raw)
{
    if ($raw === null || is_bool($raw) || is_array($raw) || is_object($raw)) {
        return null;
    }
    $s = trim((string) $raw);
    if ($s === '' || preg_match('/^[0-9]{1,5}$/', $s) !== 1) {
        return null;
    }
    $n = (int) $s;
    if ($n < 1 || $n > 65535) {
        return null;
    }
    return $n;
}

/**
 * 이 프로세스가 쓸 DB 포트. **프로세스 수명 동안 1회만 해석한다.**
 *
 * @return int|null null 이면 「지정되지 않음」 = DSN 에 port 요소를 넣지 않는다
 * @throws RuntimeException 환경변수나 DB_PORT 가 **있는데 값이 깨졌을 때**. 폴백하지 않는다
 */
function ulogin_db_port()
{
    static $resolved = false;
    static $port     = null;
    if ($resolved) {
        return $port;
    }

    // 1) 환경변수 — 지킴이가 기동 시 넣는다. 요청 경로에 HTTP 호출이 0회다.
    //    getenv() 가 false 면 "설정되지 않음", '' 도 "설정되지 않음"으로 본다
    //    (지킴이가 치환에 실패하면 설정 전체를 거부하므로 빈 값이 올 일이 없지만,
    //     손으로 띄우다 `set ULOGIN_DB_PORT=` 를 남기는 경우가 있다).
    $env = getenv(ULOGIN_DB_PORT_ENV);
    if ($env !== false && trim((string) $env) !== '') {
        $p = ulogin_port_parse($env);
        if ($p === null) {
            // **폴백하지 않는다.** 위 머리 주석의 핵심.
            error_log('[ulogin] ' . ULOGIN_DB_PORT_ENV . ' is set but not a valid TCP port (1-65535).'
                . ' refusing to fall back - a wrong port could reach another database.');
            throw new RuntimeException('invalid ' . ULOGIN_DB_PORT_ENV);
        }
        $resolved = true;
        $port     = $p;
        return $port;
    }

    // 2) config.php 의 DB_PORT. 지킴이 없이 도는 경우(수동 실행·호스팅 배포·tests/)의 정본.
    if (defined('DB_PORT')) {
        $p = ulogin_port_parse(DB_PORT);
        if ($p === null) {
            error_log('[ulogin] DB_PORT is defined in config.php but not a valid TCP port (1-65535).'
                . ' refusing to fall back - a wrong port could reach another database.');
            throw new RuntimeException('invalid DB_PORT');
        }
        $resolved = true;
        $port     = $p;
        return $port;
    }

    // 3) 지정되지 않음 — v1.6.0 과 완전히 같은 DSN 을 만든다.
    $resolved = true;
    $port     = null;
    return $port;
}

/**
 * DSN 에 끼울 포트 조각. `''` 또는 `';port=3307'`.
 *
 * **DSN 을 만드는 두 곳이 이 함수를 쓴다.** 조각 문자열까지 한 곳에서 만들어야
 * 「한쪽만 고쳐져 마이그레이션만 3306 에 붙는」 사고가 구조적으로 불가능해진다.
 */
function ulogin_db_dsn_port()
{
    $p = ulogin_db_port();
    return $p === null ? '' : ';port=' . $p;
}

/**
 * 포트가 **명시 설정돼 있는가** — `health.php` 의 `db_port_pinned` 용.
 *
 * **값은 돌려주지 않는다.** `health.php` 는 「DB 이름·계정·호스트를 담지 않는다 /
 * 키는 불리언으로만 답한다」를 규약으로 적고 있고 포트도 같은 계열이다.
 * false 는 「3) 경로 = 드라이버 기본값(3306)으로 갈 수 있다」는 신호다.
 *
 * 값이 깨져 있으면(예외) **false 를 돌려준다** — 헬스체크가 그 예외로 죽으면
 * 운영자가 볼 화면 자체가 사라진다. 진짜 신호는 그때 **접속 실패(503)** 쪽에서 난다.
 */
function ulogin_db_port_pinned()
{
    try {
        return ulogin_db_port() !== null;
    } catch (Throwable $e) {
        return false;
    }
}
