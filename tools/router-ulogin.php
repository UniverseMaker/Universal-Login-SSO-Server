<?php
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
/**
 * router-ulogin.php — `php -S` 전용 라우터
 *
 * ★ **첫 줄의 SAPI 가드 (v1.8.2 · 검증 89 L-2).** 이 파일이 `php -S` 밖에서 실행되면
 *   즉시 404 로 끝낸다. 서브디렉터리 설치에 `tools/` 가 올라가고 `.htaccess` 가 무시되는
 *   호스팅이면 `GET /ulogin/tools/router-ulogin.php` 가 이 파일을 웹 스크립트로 실행했고,
 *   요청 경로를 자기 자신으로 해석해 `require` → `Cannot redeclare r_deny()` →
 *   display_errors=On 이면 **절대 경로가 200 본문에 찍혔다**(실측 406B). 가드를 아래로 내리지 마라.
 *
 * ★ **공용 호스팅 배포물이 아니다.** 공용 호스팅은 Apache + `.htaccess` 를 쓴다
 *   (같은 별칭은 설치 루트 `.htaccess` 의 재작성이 맡는다).
 *
 * ★ **v1.8.1 (리더 판정 D-316) — 버전 폴더 안 `tools/` 로 옮겼다.**
 *   v1.8.0 까지는 `<웹 설정 폴더>/router-ulogin.php` 한 벌이 모든 버전을 받았다.
 *   그러면 접합 `current` 를 새 판으로 돌려도 **라우터만 옛 판**에 남는다(별칭 같은
 *   라우터 변경이 판과 함께 움직이지 않는다). 지금은 `current/tools/router-ulogin.php` 를
 *   기동 명령에 주므로 **접합 전환 한 번에 코드와 라우터가 함께 바뀐다.**
 *   `tools/` 를 고른 이유: 이 라우터 자신이 `/tools` 를 전면 차단(403)하고,
 *   Apache 쪽은 `tools/.htaccess` 가 `Require all denied` 다 — **웹에서 자기 소스가 내려가지 않는다.**
 *   파일 **이름**(`router-ulogin.php`)은 그대로다 — 아래 D-242 의 식별 토큰이다.
 *
 * ★ **파일명에 포트를 넣지 않는다** (리더 판정 D-242).
 *   이 파일명은 서버 지킴이의 **프로세스 식별 토큰**이다. 포트를 이름에 박으면
 *   등록부에서 포트를 바꾸는 순간 토큰이 틀리고 **지킴이가 자기 프로세스를 못 찾는다** —
 *   살아 있는데 아무도 자기 것으로 알아보지 못하는 상태이고, 이 프로젝트에서 실제로
 *   사고를 낸 좀비의 모양이다. `php-ulogin-8080.ini` → `php-ulogin.ini`,
 *   `ulogin_db_3307.err` → `ulogin_db.err` 도 같은 이유로 바뀌었다.
 *   **이름에 포트를 되돌려 넣지 마라.**
 *
 * 기동 (`{port}` 는 지킴이가 포트 등록부 값으로 치환한다. 운영 기본값 8080):
 *   C:/xampp/php/php.exe -c <웹 설정 폴더>/php-ulogin.ini \
 *     -S 127.0.0.1:{port} -t <설치루트> \
 *     <설치루트>/tools/router-ulogin.php
 *   (`php-ulogin.ini` 는 runtime 트리에 남는다 — 판마다 바뀌는 값이 아니다)
 *   ★ 이 명령은 **접합이 v1.8.1 이상을 가리킬 때만** 성립한다. v1.8.0 에는 이 파일이 없다.
 *     접합 전환과 명령 교체를 **같은 재기동**에서 한다.
 *
 *   수신 포트는 **이 명령줄이 정한다. 코드에 기본값이 없다**(D-243) —
 *   코드에 숫자를 박으면 명령줄과 코드 두 곳에 포트가 생겨 어긋날 자리가 하나 는다.
 *
 * =====================================================================
 * 왜 이 파일이 필요한가 — **`php -S` 에는 `.htaccess` 가 없다**
 *
 * 설치 트리에 `.htaccess` 가 9개 있고, 그 전부가 내장 서버에서 **아무 효력이 없다.**
 * 그래서 Apache 에서는 막히는 것이 여기서는 열린다. 가장 위험한 것 하나만 적으면:
 *
 *   `server/storage/mail/` 에는 `MAIL_DRIVER=log` 가 쓴 메일 파일이 쌓이고,
 *   그 안에 **유효한 비밀번호 재설정 링크·이메일 인증 토큰·임시 비밀번호가 평문**으로
 *   들어 있다. 이 서버는 Cloudflare 터널로 **공개 도메인에 상시 노출**된다.
 *   라우터가 막지 않으면 그 파일들이 인터넷에서 그대로 읽힌다 (리더 판정 D-230 가드 3).
 *
 * 그래서 이 라우터는 **정적 파일도 직접 내보낸다.** `return false;` 로 내장 서버에
 * 넘기면 편하지만, 그 경로로 나간 응답에는 **우리가 세운 헤더가 하나도 붙지 않는다**
 * (내장 서버가 라우터의 헤더를 버리고 자기가 응답을 만든다). 보안 헤더·CSP·no-store 가
 * 전부 사라지므로 그 길을 쓰지 않는다. 유일한 예외가 없다 — 전부 여기서 만든다.
 *
 * **CSP 를 재현하지 않으면 생기는 사고(D-236):** 로컬에서는 CSP 가 아예 없으므로
 * 테마·화면 검증이 **전수 통과**하고, 같은 코드가 호스팅(Apache + CSP)에서 깨진다.
 * 그 divergence 를 막는 것이 이 파일의 두 번째 임무다.
 *
 * =====================================================================
 * 규칙의 출처 — 값을 추측하지 않았다
 *
 * 아래 헤더 문자열과 차단 규칙은 **배포물의 `.htaccess` 10개를 읽어서** 옮긴 것이다.
 * 한 글자라도 다르면 「로컬 통과 → 호스팅 실패」가 되돌아온다.
 *   {설치루트}/.htaccess                 설치 루트 — -Indexes + 점파일 + X-Powered-By + **별칭 재작성**
 *                                             (v1.8.1 신규. v1.7.0·v1.8.0 폴더에는 이 파일이 **없었다** —
 *                                              종전 머리말의 「M17-6 으로 v1.7.0 에 추가」는 실물과 달랐다.
 *                                              **이 파일이 무시되는 배포가 있어도 라우터는 같은
 *                                              두 가지를 이미 막는다** — 아래 「엄격한 곳」 1번 참조)
 *   {설치루트}/public/.htaccess          헤더 6종 + CSP + .html no-store + 자산 캐시
 *   {설치루트}/server/.htaccess          헤더 2종 + 차단 3그룹 + 확장자 생략 리라이트
 *   {설치루트}/server/core/.htaccess     전면 차단
 *   {설치루트}/server/migrations/.htaccess 전면 차단
 *   {설치루트}/server/storage/.htaccess  전면 차단 (+ PHP 엔진 off)
 *   {설치루트}/server/storage/mail/.htaccess 전면 차단
 *   {설치루트}/tools/.htaccess           전면 차단
 *   {설치루트}/tests/.htaccess           전면 차단
 *   {설치루트}/examples/.htaccess        -Indexes + 점파일 + .html no-store
 *
 * **Apache 보다 엄격하게 둔 곳 4개** (리포트 표에 적었다):
 *   1) 설치 루트와 `docs/` — Apache 쪽은 M17-6 으로 루트 `.htaccess` 가 생겼지만
 *      `AllowOverride None` 호스팅에서는 무시된다. 라우터는 **어디서든 목록을 내지 않는다.**
 *   2) 점(.)으로 시작하는 파일을 **트리 전체**에서 막는다. Apache 는 루트·public·server·
 *      examples 에서만 막는다. `docs/.foo` 가 그 차이다.
 *   3) 디렉터리에 index 파일이 없으면 Apache(-Indexes)는 403 인데 라우터는 **404** 다.
 *      둘 다 목록을 내지 않는다. 존재 여부를 덜 알리는 쪽을 골랐다.
 *   4) **확장자 생략 리라이트의 차단 재판정을 파일 존재 확인보다 앞에 둔다**(아래 Q17-1).
 *      Apache 는 `config.php` 가 **있을 때만** 리라이트가 발화해 403 을 내고, 없으면 404 다.
 *      라우터는 `/server/config` 를 **존재 여부와 무관하게 403** 으로 낸다 — 「설치됐는가」를
 *      응답 코드로 알려주지 않기 위해서다. 안전한 방향의 divergence 다.
 *
 * **라우터가 Apache 보다 느슨했던 곳** (v1.7.0 에서 찾은 순서대로):
 *   · C17-1 경로 조각의 **후행 점·공백** 한 글자로 전 차단 규칙 우회 (보안 1회차 실측)
 *   · C17-1 확장 **8.3 단축 이름**(`CONFIG~1.PHP` · `HTACCE~1` · `MIGRAT~1/`) — 보안 감사가
 *     §10-3 에서 「재지 않았다」고 적은 자리다. **재 봤더니 열려 있었다**(`.htaccess` 5,507B 유출)
 *   · Q17-1 확장자 생략 리라이트 뒤 basename·확장자 **재판정 없음** (QA 1회차 실측)
 *   · Q17-5 public/ 의 DirectoryIndex 가 `index.php` 까지 허용 (`public/.htaccess` 는 index.html 뿐)
 *   · **C17-2 NTFS 디렉터리 스트림 접미**(`/server::$INDEX_ALLOCATION/schema.sql` → 200 · 37,588B)
 *     와 NT 와일드카드(`/server/schema.sq<`). 보안 2회차 실측. **1차 수정 뒤에 찾았다.**
 *
 * ── 이 목록의 머리말은 한 번 거짓이었다 (교훈. 지우지 마라) ──────────────
 * 1차 수정 직후 이 머리말은 「전부 이번 라운드에 닫혔다」였다. **그 문장은 측정한 철자에
 * 대해서만 참이었다.** C17-1 은 후행 점 → 8.3 → 스트림 접미로 **같은 결함의 세 번째 철자**가
 * 나왔고, 1차 수정이 기댄 「`realpath()` 가 정규화해 준다」는 전제가 **스트림 접미에는
 * 성립하지 않았다** — realpath 는 `server::$INDEX_ALLOCATION` 을 **그대로 돌려준다.**
 * 그래서 2차는 철자를 하나 더 열거하지 않고 **경로 조각에 올 수 있는 글자를 허용 목록으로
 * 좁혔다**(`r_deny_rules()` (a-0)). 「닫혔다」는 이제 **「허용 목록 밖의 글자는 들어오지
 * 못한다」** 는 뜻이고, 그 밖의 주장(「모든 우회가 없다」)은 하지 않는다.
 *
 * =====================================================================
 * PHP 7.2 호환으로 적었다. 이 서버는 8.1 에서 돌지만, 이 파일을 읽는 사람이
 * 배포물 규약(7.2)과 다른 문법을 보고 혼동하지 않게 한다.
 */

/**
 * 거부 응답. **본문에 경로도 사유도 싣지 않는다** — 사유는 서버 로그로만 간다.
 * 공개 도메인에 노출되는 서버이므로 「무엇이 왜 막혔나」가 정찰 재료가 되면 안 된다.
 *
 * 파일 **맨 앞**에 둔다. 아래 본문이 이 함수를 첫 줄부터 부르기 때문이다.
 * (최상위 함수 선언은 컴파일 시점에 바인딩되지만, 읽는 사람이 확인해야 하는
 *  언어 규칙에 기대지 않는다 — 순서로 명백하게 둔다.)
 */
function r_deny($status, $why)
{
    header_remove('X-Powered-By');
    http_response_code((int) $status);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    error_log('[ulogin-router] ' . $status . ' ' . $why . ' ' . $_SERVER['REQUEST_URI']);
    echo $status === 404 ? "404 Not Found\n" : "403 Forbidden\n";
    exit;
}

/**
 * 차단 규칙 한 벌. **같은 규칙을 두 번 돌리기 위해** 함수로 뺐다 (감사 C17-1).
 *
 * ── 왜 두 번인가 ──────────────────────────────────────────────────────
 * 1회차는 **문자열 경로** 위에서, 2회차는 **`realpath()` 가 돌려준 실제 경로** 위에서.
 *
 * 여기 있던 코드는 전부 문자열 비교였고 `is_file()`·`realpath()` 는 **OS 규칙**으로
 * 같은 문자열을 다르게 해석했다. Windows 는 경로 조각의 **후행 점·공백을 버리고**,
 * 모든 파일에 **8.3 단축 이름**(`config.sample.php` → `CONFIG~1.PHP`)을 함께 준다.
 * 그래서 `판정 ≠ 해석` 이 되고 차단이 통째로 비켜 갔다 — 보안 1회차 실측:
 *
 *     GET /server./schema.sql              → 200 · 36,630B   (정규 경로는 403)
 *     GET /server/storage./mail/index.html → 200             (평문 재설정 토큰이 사는 트리)
 *     GET /server/core./bootstrap.php      → 500 = **실행됐다**
 *     GET /server/HTACCE~1                 → 200 · 5,507B    (Apache 설정 전문 유출)
 *     GET /server/CONFIG~1.PHP             → 200             (config.sample.php 실행)
 *
 * 이것은 D-51·D-67 이 겨눈 **「같은 것을 두 기준으로 본다」의 파일 경로판**이다.
 * 고치는 방법은 규칙을 늘리는 것이 아니라 **판정과 해석이 같은 문자열을 보게** 하는 것이다.
 *
 * ── 왜 한 번(뒤에서만) 이 아닌가 ─────────────────────────────────────
 * 뒤에서만 부르면 차단 판정이 `is_file()` **뒤로** 밀린다. 그러면 차단 대상 경로가
 * 「없으면 404 · 있으면 403」이 되어 **존재 오라클**이 생긴다. 지금 라우터가 일부러
 * 피하고 있는 것이 그것이다(2번 블록 주석). 그래서 앞뒤로 두 번 부른다.
 *
 * ── 1차 변이 검증이 왜 이것을 못 잡았는가 (교훈. 지우지 마라) ────────
 * v1.7.0 1차에서 `denyTrees` 의 `'/server/storage',` 한 줄을 지웠더니 403→200 이 됐고,
 * 그것으로 「막고 있다」를 증명했다고 적었다. **증명된 것은 「그 줄이 동작한다」뿐이다.**
 * **변이 검증은 「있는 규칙이 산다」를 보지, 「규칙을 비껴가는 길」을 보지 않는다.**
 * 비껴가는 길은 규칙을 지우지 않고도 열려 있었고, 같은 라운드의 보안 감사가 찾았다.
 * 우회를 재려면 **규칙을 건드리지 않은 채 다른 철자로 같은 파일을 요청**해야 한다.
 *
 * @param string $clean        선행 슬래시로 시작하고 후행 슬래시가 없는 경로
 * @param bool   $isDirRequest 디렉터리 요청인가 (basename·확장자 규칙을 건너뛴다)
 * @return bool  이 경로가 `server/` 아래인가
 */
function r_deny_rules($clean, $isDirRequest)
{
    $segs = array();
    foreach (explode('/', $clean) as $s) {
        if ($s !== '') {
            $segs[] = $s;
        }
    }

    foreach ($segs as $s) {
        // (a-0) **허용 글자 목록** — 감사 C17-2 (v1.7.0 2차). 경로 조각은 영숫자·`.`·`_`·`-` 만.
        //
        //       왜 금지 목록이 아니라 허용 목록인가: 이 결함은 철자를 바꿔 세 번 돌아왔다.
        //         1) 후행 점·공백        `/server./schema.sql`          (realpath 가 펴 준다)
        //         2) 8.3 단축 이름       `/server/HTACCE~1`             (realpath 가 펴 준다)
        //         3) NTFS 스트림 접미    `/server::$INDEX_ALLOCATION/…` (**realpath 가 안 펴 준다**)
        //       1차 수정은 「realpath 결과 위에서 다시 본다」였고, 그것은 **realpath 가 정규화하는
        //       철자만** 닫는다. 3번은 그 전제 밖이었다. `:`·`$`·`<`·`~` 를 하나씩 막으면
        //       **다음 철자가 네 번째**가 된다. 그래서 「무엇이 위험한가」를 묻지 않고
        //       「이 트리의 정상 이름이 무엇으로 이루어지는가」만 묻는다.
        //
        //       [실측] 설치 트리의 고유 조각 이름 189개가 **전부** `^[A-Za-z0-9._-]+$` 다.
        //       이 한 줄이 함께 닫는 것: `:`(스트림) · `~`(8.3 — 1회차에서 끊겨 3회차 존재
        //       오라클도 사라진다) · `<>"?*`(NT 와일드카드) · 공백·탭·제어문자 · `%`·`$`·`#` ·
        //       비 ASCII 전부(유니코드 정규화·베스트핏 류를 판단할 필요가 없어진다).
        //
        //       ★ **새 파일·폴더 이름에 이 밖의 글자를 쓰면 그 파일은 403 이 된다.** 그것이 맞다 —
        //         배포물 이름을 이 규칙에 맞춰라. 규칙을 넓혀야 한다면 **넓히는 글자가
        //         Windows 경로 파서에서 무엇을 뜻하는지 먼저 재라.**
        //       쿼리스트링은 1번 블록에서 이미 잘렸으므로 `?token=a:b` 는 영향이 없다.
        //       ★ 끝 앵커는 `$` 가 아니라 **`\z`** 다 (v1.8.2 · 검증 89 L-1). PCRE 의 `$` 는
        //         문자열 끝의 `\n` **앞**에서도 맞아 `core%0a` 가 이 줄을 통과했다. 아래 (d) 와
        //         r_alias() 의 정규식도 같은 이유로 `\z` 다. **`$` 로 되돌리지 마라.**
        if (preg_match('/^[A-Za-z0-9._-]+\z/', $s) !== 1) {
            r_deny(403, 'bad segment');
        }
        // (a) 점(.)으로 시작하는 경로 조각 — .htaccess · .env · .git · .install.lock
        if ($s[0] === '.') {
            r_deny(403, 'dotfile');
        }
        // (a-2) 후행 점·공백 — Windows 가 **버리는** 글자다. `storage.` 로 연 핸들은
        //       `storage` 를 연다. **정규화하지 않고 거절한다** — 정규화하면
        //       「무엇이 정본인가」가 하나 더 늘고, 그 자리가 다음 우회가 된다.
        //       Linux + Apache 배포에서는 이런 이름이 애초에 다른 파일이라 무해하다.
        $tail = substr($s, -1);
        if ($tail === '.' || $tail === ' ' || $tail === "\t") {
            r_deny(403, 'bad segment');
        }
    }

    $lower = strtolower($clean);

    // (b) 전면 차단 트리 — tools/.htaccess · tests/.htaccess ·
    //     server/.htaccess 의 RewriteRule ^(core|storage|migrations)(/|$) - [F,L] ·
    //     server/core|storage|migrations 각각의 Require all denied
    //     **storage 가 이 목록의 존재 이유다**(D-230 가드 3).
    $denyTrees = array(
        '/tools',
        '/tests',
        '/server/core',
        '/server/storage',
        '/server/migrations',
    );
    foreach ($denyTrees as $t) {
        if ($lower === $t || strpos($lower, $t . '/') === 0) {
            r_deny(403, 'denied tree');
        }
    }

    // (c) server/ 아래의 파일명·확장자 차단 — server/.htaccess 의 FilesMatch 2개.
    //     Apache 의 <FilesMatch> 는 **하위 디렉터리까지** 기본명(basename)으로 걸린다.
    $inServer = ($lower === '/server' || strpos($lower, '/server/') === 0);
    $base     = $segs === array() ? '' : strtolower($segs[count($segs) - 1]);
    if ($inServer && !$isDirRequest && $base !== '') {
        if ($base === 'config.php' || $base === 'config.sample.php' || $base === 'schema.sql') {
            r_deny(403, 'server config/schema');
        }
        $dot = strrpos($base, '.');
        $ext = $dot === false ? '' : substr($base, $dot + 1);
        // `eml`·`mbox`·`env` 는 감사 L17-4 로 추가했다. 정상 경로에서는 `/server/storage`
        // 트리 차단이 먼저 걸려 도달하지 않지만, **트리 차단 하나에만 기댄 구조가
        // C17-1 이 성립한 이유**다. 심층 방어로 둔다.
        // **`server/.htaccess` 의 FilesMatch 와 같은 목록이어야 한다 — 함께 고쳐라.**
        //
        // v1.7.0 2차 (감사 H17-4) — 편집기 백업 `save`·`swo`·`tmp`·`part`.
        //   `config.php` 는 손으로 편집되는 파일이다(D-264). 편집되는 파일 옆에는 백업이 남고,
        //   `config.php.save`(nano) 는 확장자가 `save` 라 **정적 파일로 본문이 내려갔다**(합성 루트 실측).
        //   `config.php~`(vim 등) 는 이 목록이 아니라 위 (a-0) 허용 글자 목록이 닫는다 — `~` 가 밖이다.
        //   Apache 쪽은 `server/.htaccess` 에 `(~|#)$` 접미 규칙을 따로 둔다.
        $denyExt = array('sql', 'log', 'txt', 'md', 'ini', 'conf', 'bak', 'old', 'orig',
                         'swp', 'dist', 'sample', 'json', 'lock', 'yml', 'yaml',
                         'eml', 'mbox', 'env',
                         'save', 'swo', 'tmp', 'part');
        if (in_array($ext, $denyExt, true)) {
            r_deny(403, 'server ext');
        }
    }

    // (d) **데이터베이스 파일 — 트리 전체에서** (감사 H17-4 / D-279). `server/` 안으로 한정하지 않는다.
    //     D-279 가 v1.8.0 기본 저장소를 SQLite 로 정하면서 「라우터가 그 파일을 **절대** 내주지
    //     않아야 한다」고 적었다. 그 파일이 어느 폴더에 떨어지든 **한 번의 GET 으로 전 회원의
    //     password_hash · 암호화된 시크릿 · 세션 토큰 해시**가 나간다.
    //     동반 파일은 확장자가 아니라 **접미**다: `ulogin.sqlite-wal` 의 마지막 점 뒤는
    //     `sqlite-wal` 이므로 `wal` 을 확장자 목록에 넣어서는 잡히지 않는다 — 그래서 정규식이다.
    //     ★ **이것은 두 번째 겹이다.** 본 방어는 v1.8.0 설계가 DB 파일을 **문서 루트 밖**에 두고
    //       아래 3-b) 의 realpath 루트 밖 거절에 기대게 하는 것이다(architect 몫).
    //     `server/.htaccess` 의 같은 패턴과 함께 고쳐라.
    $leaf = $segs === array() ? '' : strtolower($segs[count($segs) - 1]);
    if (!$isDirRequest && $leaf !== ''
        && preg_match('/\.(sqlite|sqlite3|db|db3|s3db|sl3)(-(wal|shm|journal))?\z/', $leaf) === 1) {
        r_deny(403, 'database file');
    }

    return $inServer;
}

/**
 * 허용 목록 (v1.8.9 / G20 라운드). **차단 목록만으로는 「화면이 안 쓰는 파일」이 나간다** —
 * 운영 실측(v1.8.8): `/README.md`·`/INSTALL.md`·`/CHANGELOG.md`·`/AI_INTEGRATION_GUIDE.md` 가 200,
 * `/server/migrate.php` 가 웹 화면(200). 그래서 이 함수는 「나가도 되는 것」만 적는다.
 *
 *   /public/**        정적 화면 자원: html css js svg png ico webp gif jpg jpeg woff woff2 map,
 *                     그리고 version.txt · version.json (public 바로 아래만). 그 밖(.txt·.json·.md 등)은 404
 *   /examples/*.html  연동 예제 화면
 *   /server/api/**    입구 .php 만
 *   /server/install.php  설치 전 화면 — 설치 뒤에는 install.php 자신의 잠금 판정이 막는다(지금처럼)
 * 그 밖의 전부(설치 루트 문서, docs/, server/migrate.php, 그 외 .php)는 404.
 * **server/migrate.php 를 막는 것은 이 `php -S` 운영 서버뿐이다.** 마이그레이션은 이 서버에서
 * 셸(CLI)로 한다. Apache 공용 호스팅 배포는 셸이 없을 수 있어 웹 화면이 정본이므로
 * `server/.htaccess` 가 그 파일을 허용 목록에 남긴다(INSTALL.md 8-2절). 두 목록의 차이는 이것 하나다.
 * 404 인 이유: 존재 여부를 응답 코드로 알리지 않는다(없는 파일도 404).
 *
 * @param string $canon 선행 슬래시 경로
 * @return bool
 */
function r_allowed($canon)
{
    $lower = strtolower($canon);
    $segs  = explode('/', trim($lower, '/'));
    $leaf  = $segs[count($segs) - 1];
    $dot   = strrpos($leaf, '.');
    $ext   = $dot === false ? '' : substr($leaf, $dot + 1);
    if (strpos($lower, '/public/') === 0) {
        if ($leaf === 'version.txt' || $leaf === 'version.json') {
            return count($segs) === 2;
        }
        // OFL 고지(v1.8.3 M-1) — 이 파일 **하나만** 이름으로. 다른 .txt 는 여전히 404.
        if ($leaf === 'ofl-pretendard.txt') {
            return $lower === '/public/assets/ofl-pretendard.txt';
        }
        return in_array($ext, array('html', 'css', 'js', 'svg', 'png', 'ico', 'webp', 'gif',
                                    'jpg', 'jpeg', 'woff', 'woff2', 'map'), true);
    }
    if (strpos($lower, '/examples/') === 0) {
        return $ext === 'html' && count($segs) === 2;
    }
    if (strpos($lower, '/server/api/') === 0) {
        return $ext === 'php';
    }
    return $lower === '/server/install.php';
}

/**
 * `/public` 없는 주소 — **내부 매핑**(리다이렉트가 아니다). v1.8.1 / 리더 판정 D-313·D-316.
 * 주소창에는 `/login` 이 남고, 내보내는 파일은 `public/login.html` 이다.
 * 설치 루트 `.htaccess` 의 RewriteRule 4줄과 **같은 표**여야 한다 — 함께 고쳐라.
 *
 *   /                      → /public/index.html      (`/public/` 의 DirectoryIndex 와 같은 화면)
 *   /{page} · /{page}.html → /public/{page}.html     (page 는 아래 8개 **정확히 소문자**)
 *   /assets/{file}         → /public/assets/{file}   (한 조각 · 정적 확장자만)
 *   /version.txt           → /public/version.txt
 *   /version.json          → /public/version.json    (G18, v1.8.7)
 *
 * 왜 `assets/`·`version.txt` 까지 잇는가: 화면은 `assets/style.css?v=` 를 **상대 경로**로
 * 부른다. `/login` 에서 그 상대 경로는 `/assets/style.css` 가 된다. 이것을 잇지 않으면
 * 별칭 주소의 화면이 CSS·JS 없이 뜬다. 페이지 간 링크(`login.html`)는 `/login.html` 이 되어
 * 위 표의 둘째 줄이 받는다. API(`../server`)는 `/server` 로 풀려 손댈 것이 없다.
 *
 * **무엇을 하지 않는가 — 이 함수가 「안전」을 지지 않는다.** 입력은 이미 (a-0) 허용 글자와
 * `..` 거절을 통과한 정규 문자열이고, 출력은 **고정 접두 `/public/` + 허용 목록 이름**이다.
 * 그래도 디스크 위의 접합·심볼릭 링크가 public 밖을 가리킬 수 있으므로, 최종 판정은
 * 3) 의 **realpath 접두 검사**(별칭 대상은 반드시 realpath(public) 아래)가 한다.
 *
 * - 대소문자: `/LOGIN` 은 별칭이 **아니다**(404). Windows 파일시스템은 대소문자를 무시해
 *   `public/LOGIN.html` 도 열리지만, Linux + Apache 는 404 다. 그 divergence 를 만들지 않는다.
 * - `.php` 는 별칭 대상이 아니다. public/ 에 PHP 가 생겨도 별칭으로는 실행되지 않는다.
 * - 후행 슬래시(`/login/`)는 별칭이 아니다 — 상대 경로 해석이 `/login/assets/…` 로 어긋난다.
 *
 * @param string $clean        정규화된 요청 경로
 * @param bool   $isDirRequest
 * @return array|null  [매핑된 경로, realpath 가 머물러야 할 public 하위 디렉터리('' 또는 '/assets')] 또는 null
 */
function r_alias($clean, $isDirRequest)
{
    if ($clean === '/') {
        return array('/public/index.html', '');
    }
    if ($isDirRequest) {
        return null;
    }
    $pages = array('admin', 'forgot', 'index', 'login', 'mypage', 'register', 'reset', 'verify');
    if (preg_match('#^/([a-z]+)(\.html)?\z#', $clean, $m) === 1 && in_array($m[1], $pages, true)) {
        return array('/public/' . $m[1] . '.html', '');
    }
    if (preg_match('#^/assets/([A-Za-z0-9_-][A-Za-z0-9._-]*\.(css|js|svg|png|ico|webp|gif|jpg|jpeg|woff|woff2|map))\z#',
            $clean, $m) === 1) {
        return array('/public/assets/' . $m[1], '/assets');
    }
    if ($clean === '/version.txt') {
        return array('/public/version.txt', '');
    }
    if ($clean === '/version.json') {
        return array('/public/version.json', '');   // G18 (v1.8.7) — 공용 새 버전 알림이 보는 주소
    }
    return null;
}

// ---------------------------------------------------------------------
// 0) 설치 루트 (= -t 로 준 문서 루트). 접합 `current` 를 거치므로
//    명령줄에는 `[예시]` 이 등장하지 않지만, PHP 가 realpath 로 풀면
//    **대괄호가 그대로 돌아온다.** 그래서 이 파일은 glob() 을 쓰지 않는다
//    (PHP glob() 은 `[예시]` 을 문자 클래스로 읽어 오류 없이 0건을 낸다).
// ---------------------------------------------------------------------
$ROOT = $_SERVER['DOCUMENT_ROOT'];
if (!is_string($ROOT) || $ROOT === '') {
    // v1.8.1 — 이 파일은 `{설치루트}/tools/` 에 있다. 부모가 설치 루트다.
    $ROOT = dirname(__DIR__);
}
$ROOT = rtrim(str_replace('\\', '/', (string) $ROOT), '/');

// ---------------------------------------------------------------------
// 1) 요청 경로 정규화
// ---------------------------------------------------------------------
$uri = (string) $_SERVER['REQUEST_URI'];
$qpos = strpos($uri, '?');
if ($qpos !== false) {
    $uri = substr($uri, 0, $qpos);
}
$path = rawurldecode($uri);
$path = str_replace('\\', '/', $path);
if ($path === '' || $path[0] !== '/') {
    $path = '/' . $path;
}
// NUL 바이트 · 경로 탈출. 정규화 **전에** 거른다.
if (strpos($path, "\0") !== false) {
    r_deny(400, 'bad request');
}
$segs = array();
foreach (explode('/', $path) as $s) {
    if ($s === '' || $s === '.') {
        continue;
    }
    if ($s === '..') {
        // `/server/../x` 를 허용하면 차단 규칙이 접두사 비교라 통째로 우회된다.
        r_deny(403, 'traversal');
    }
    $segs[] = $s;
}
$clean = '/' . implode('/', $segs);          // 항상 선행 슬래시, 후행 슬래시 없음
$isDirRequest = (substr($path, -1) === '/') || $clean === '/';

// ---------------------------------------------------------------------
// 2) 차단 규칙 **1회차** — 문자열 경로 위에서.
//    **파일 존재 여부를 보기 전에** 판정한다. 존재할 때만 막으면
//    「없는 파일은 404, 있는 파일은 403」이 되어 응답 코드만으로 파일 존재를
//    알려주는 오라클이 된다. 규칙 본문은 r_deny_rules() 에 있다 (C17-1).
// ---------------------------------------------------------------------
$inServer = r_deny_rules($clean, $isDirRequest);
$lower    = strtolower($clean);

// ---------------------------------------------------------------------
// 2-b) 별칭 (v1.8.1 / D-313). **차단 1회차 뒤에** 둔다 — 요청 문자열 자체가 먼저
//      허용 글자·`..`·점파일·전면 차단 트리 판정을 통과해야 별칭을 볼 자격이 생긴다.
//      매핑된 경로에도 차단 규칙을 한 번 더 돌린다(지금은 /public/ 이라 걸릴 것이 없지만,
//      표를 넓히는 사람이 그 사실에 기대지 않게).
// ---------------------------------------------------------------------
$aliasSub = null;                    // null = 별칭 아님 / '' 또는 '/assets' = 머물러야 할 자리
$aliasMap = r_alias($clean, $isDirRequest);
if ($aliasMap !== null) {
    $clean        = $aliasMap[0];
    $aliasSub     = $aliasMap[1];
    $isDirRequest = false;           // `/` 도 이제 파일 하나를 가리킨다
    $inServer     = r_deny_rules($clean, false);
    $lower        = strtolower($clean);
}

// ---------------------------------------------------------------------
// 3) 대상 파일 결정
// ---------------------------------------------------------------------
$fs = $ROOT . $clean;

if (is_dir($fs) || $isDirRequest) {
    // DirectoryIndex — 영역별로 **`.htaccess` 와 같은 목록**을 쓴다 (감사 Q17-5).
    //   public/.htaccess : DirectoryIndex index.html           ← index.php 는 없다
    //   server/.htaccess : DirectoryIndex index.html index.php
    //   그 밖(루트·docs·examples) : 지시가 없으므로 Apache 기본값 index.html
    // 종전 라우터는 **어디서나 index.php 까지** 받아들였다. 현재 트리에
    // `public/index.php` 가 없어 증상은 없었지만, 생기는 순간 Apache 는 목록 없음(403)
    // 인데 라우터만 그 파일을 **실행**하는 divergence 가 된다.
    $dir   = rtrim($ROOT . $clean, '/');
    $cands = (strpos($lower, '/server/') === 0 || $lower === '/server')
        ? array('index.html', 'index.php')
        : array('index.html');
    $found = null;
    foreach ($cands as $cand) {
        if (is_file($dir . '/' . $cand)) {
            $found = $dir . '/' . $cand;
            $clean = rtrim($clean, '/') . '/' . $cand;
            break;
        }
    }
    if ($found === null) {
        // Options -Indexes. 목록은 어떤 경우에도 내지 않는다.
        r_deny(404, 'no directory index');
    }
    $fs = $found;
}

if (!is_file($fs)) {
    // server/.htaccess 의 확장자 생략 리라이트:
    //   RewriteCond %{REQUEST_FILENAME} !-f / !-d
    //   RewriteCond %{REQUEST_FILENAME}.php -f
    //   RewriteRule ^(.*)$ $1.php [L]
    // Apache 에서는 server/ 안에서만 유효하다. 같은 범위로 둔다.
    //
    // **감사 Q17-1 — 리라이트 뒤에 차단 규칙을 다시 돌린다.**
    // Apache 는 리라이트가 끝난 파일명으로 `<FilesMatch>` 를 **재평가**하므로
    // `/server/config` 가 403 이다. 라우터는 재평가가 없어 `config.php` 를 **실행**했다
    // (QA 1회차 실측 200). 피해는 본문 유출이 아니라 「설치됐는가」의 존재 오라클이다.
    //
    // 재판정을 `is_file()` **앞**에 둔다 — 뒤에 두면 `/server/config` 가
    // 「config.php 가 있으면 403 · 없으면 404」가 되어 오라클이 되살아난다.
    // 그 결과 Apache 보다 엄격해지지만(파일이 없어도 403) **안전한 방향**이다.
    if ($inServer && !$isDirRequest) {
        r_deny_rules($clean . '.php', false);
    }
    if ($inServer && is_file($fs . '.php')) {
        $fs    = $fs . '.php';
        $clean = $clean . '.php';
        $lower = strtolower($clean);
    } else {
        r_deny(404, 'not found');
    }
}

// 허용 목록 1회차 — 문자열 경로 위에서 (v1.8.9). 아래 2회차가 realpath 위에서 다시 본다.
if (!r_allowed($clean)) {
    r_deny(404, 'not in allow list');
}

// realpath 로 한 번 더 — 심볼릭 링크·접합을 지나 루트 밖으로 나가지 못하게.
// `current` 자체가 접합이므로 **루트도 같이 resolve** 해서 비교한다.
$realRoot = realpath($ROOT);
$realFile = realpath($fs);
if ($realRoot === false || $realFile === false) {
    r_deny(404, 'not found');
}
$realRoot = rtrim(str_replace('\\', '/', $realRoot), '/');
$realFile = str_replace('\\', '/', $realFile);
if (strpos($realFile, $realRoot . '/') !== 0) {
    r_deny(403, 'outside document root');
}

// 별칭 대상은 **public 디렉터리 밖으로 절대 나가지 않는다** (v1.8.1 / D-316 요구 3).
// 문자열 매핑은 고정 접두 `/public/` 이지만, 디스크 위의 접합·링크는 그 문자열을 따르지 않는다.
// 그래서 판정은 OS 가 실제로 연 경로(realpath) 위에서 한다. 루트 검사만으로는 부족하다 —
// `public/assets` 가 같은 설치 루트 안 `docs/` 를 가리키는 접합이면 루트 검사는 통과한다.
// 응답은 404 다(별칭이 아닌 것처럼 보이게. 이 자리의 존재를 알리지 않는다).
//
// 기준 경로는 **realpath(설치 루트) + 글자 그대로의 `/public`(+`/assets`)** 이다.
// `realpath(public)` 을 기준으로 삼으면 `public` 이나 `public/assets` **자체가** 접합일 때
// 기준도 함께 밖으로 끌려가 검사가 늘 참이 된다(구현 중 걸렀다. 시험 t_70 의 접합 케이스가 그 모양이다).
// 설치 루트의 접합(`current`)만 풀고, 그 아래 `public` 은 진짜 디렉터리여야 한다.
if ($aliasSub !== null) {
    $realPub = $realRoot . '/public' . $aliasSub;
    if (strpos($realFile, $realPub . '/') !== 0) {
        r_deny(404, 'alias outside public');
    }
    // 대소문자까지 같은 이름이어야 한다 — Windows 가 `API.JS` 로 `api.js` 를 여는 것을
    // 별칭이 물려받지 않게(Linux + Apache 는 404). 정상 요청에서는 언제나 같다.
    if (basename($realFile) !== basename($clean)) {
        r_deny(404, 'alias case mismatch');
    }
}

// ---------------------------------------------------------------------
// 3-b) 차단 규칙 **2회차** — OS 가 실제로 연 경로 위에서 (감사 C17-1 수정안 A).
//
//      `realpath()` 는 Windows 에서 경로 조각마다 **디스크에 적힌 진짜 이름**으로
//      바꿔 준다. 후행 점·공백은 사라지고 8.3 단축 이름은 긴 이름으로 펴진다.
//      그 위에서 같은 규칙을 다시 돌리면 **「문자열은 다른데 같은 파일」 류를
//      철자마다 열거하지 않고 구조적으로 닫는다.** 열거로 막으면 다음 철자에 또 뚫린다.
//
//      **반드시 realpath 검사보다 뒤에 와야 한다** — 앞에 두면 $realFile 이 아직 없다.
//      그리고 1회차(2번 블록)를 **지우지 마라.** 이것만 남기면 차단 판정이
//      is_file() 뒤로 밀려 존재 오라클이 생긴다.
//
//      남는 오라클에 대한 정직한 기록: 문자열 형태로는 통과하지만 **정규 형태로는
//      차단되는** 경로(예: `/server/CONFIG~1.PHP`)는 파일이 있으면 403, 없으면 404 다.
//      이것은 「그 단축 이름의 파일이 있다」까지만 알려 주고 내용은 주지 않는다.
//      차단되지 않는 모든 경로가 이미 404 ↔ 200 으로 같은 것을 알려주므로
//      **새로 생긴 오라클이 아니다.** 종전에는 같은 요청이 **파일을 내줬다.**
//      비용은 요청당 문자열 비교 몇 번이다.
// ---------------------------------------------------------------------
//
//      ── v1.7.0 2차 (감사 C17-2 수정안 B): **파생값을 정규 경로로 다시 세운다** ──
//      1차는 판정만 다시 돌리고 `$inServer`·`$lower`·`$clean` 은 요청 문자열에서 만든 값을
//      그대로 썼다. 판정·헤더(`$area`)·실행(`SCRIPT_NAME`)이 **같은 문자열을 보게** 한다.
//
//      ★ **이 블록은 C17-2 를 막지 않는다 — 측정으로 확인했다.** 감사 수정안은 「(A) 가 차단을,
//        (B) 가 헤더를 담당한다」고 [판단]으로 적었는데, (A) 를 뺀 변이에서
//        `/public::$INDEX_ALLOCATION/login.html` 은 이 블록이 있어도 **CSP·X-Frame-Options 0개로
//        200** 이었다. realpath 가 스트림 접미를 **그대로 돌려주므로** 정규 문자열도 `/public::…`
//        이고 `$area` 판정(`/public/` 접두)이 여전히 빗나간다. C17-2 의 차단과 헤더 둘 다
//        (a-0) 허용 글자 목록 **한 겹이 홀로** 지고 있다. 이 블록은 「정규화되는 철자」에서
//        세 값이 어긋나지 않게 하는 정합성 장치일 뿐이다. 「겹이 둘」이라고 읽지 마라.
$canon    = substr($realFile, strlen($realRoot));
if (!r_allowed($canon)) {
    r_deny(404, 'not in allow list (canonical)');
}
$inServer = r_deny_rules($canon, false);
$clean    = $canon;
$lower    = strtolower($canon);

// ---------------------------------------------------------------------
// 4) 헤더 — `.htaccess` 와 **같은 값**이어야 한다
// ---------------------------------------------------------------------
header_remove('X-Powered-By');          // php.ini 의 expose_php=Off 와 이중으로

$area = 'root';
if (strpos($lower, '/public/') === 0 || $lower === '/public') {
    $area = 'public';
} elseif ($inServer) {
    $area = 'server';
} elseif (strpos($lower, '/examples/') === 0 || $lower === '/examples') {
    $area = 'examples';
}

if ($area === 'public') {
    // 출처: public/.htaccess
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: accelerometer=(), autoplay=(), camera=(), display-capture=(), '
        . 'encrypted-media=(), fullscreen=(), geolocation=(), gyroscope=(), magnetometer=(), '
        . 'microphone=(), midi=(), payment=(), picture-in-picture=(), usb=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; "
        . "style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; "
        . "object-src 'none'; frame-src 'none'; worker-src 'none'; manifest-src 'none'; "
        . "base-uri 'self'; form-action 'none'; frame-ancestors 'none'");
} elseif ($area === 'server') {
    // 출처: server/.htaccess — **CSP 도 X-Frame-Options 도 없다.** 추가하지 않는다.
    // 호스팅에 없는 헤더를 로컬에만 붙이면 그것도 divergence 다.
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
}
// examples/ · 설치 루트는 X-Powered-By 제거만. (examples/.htaccess 가 그렇다)

// 캐시 — 확장자별. 출처: public/.htaccess · examples/.htaccess
$fbase = strtolower(basename($realFile));
$fdot  = strrpos($fbase, '.');
$fext  = $fdot === false ? '' : substr($fbase, $fdot + 1);

if ($fext === 'html' && ($area === 'public' || $area === 'examples')) {
    // reset.html · verify.html 이 **일회용 토큰을 쿼리로** 받는다. 캐시되면 안 된다.
    header('Cache-Control: no-store');
    header('CDN-Cache-Control: no-store'); // G19 — 중간 캐시도 HTML 을 저장하지 않는다. 출처: public/.htaccess
} elseif ($area === 'public' && $fbase === 'version.txt') {
    header('Cache-Control: no-store');
} elseif ($area === 'public' && $fbase === 'version.json') {
    // G18 — 중간 캐시(Cloudflare 등)가 저장하면 새 버전 알림이 영영 안 뜬다. 출처: public/.htaccess
    header('Cache-Control: no-store');
    header('CDN-Cache-Control: no-store');
} elseif ($area === 'public' && ($fext === 'css' || $fext === 'js')) {
    // ?v= 로 무효화한다는 전제의 1년 immutable (v1.2.0 / D-85·D-86)
    header('Cache-Control: public, max-age=31536000, immutable');
}

// ---------------------------------------------------------------------
// 5) 실행 또는 전송
// ---------------------------------------------------------------------
if ($fext === 'php') {
    // install.php 가 `$_SERVER['SCRIPT_NAME']` 으로 BASE_URL 과 폼 action 을 만든다.
    // 라우터를 거치면 그 값이 라우터 자신을 가리키므로 **대상 스크립트로 바꿔 준다.**
    $_SERVER['SCRIPT_NAME']     = $clean;
    $_SERVER['PHP_SELF']        = $clean;
    $_SERVER['SCRIPT_FILENAME'] = $realFile;
    require $realFile;
    return;
}

// 정적 파일 — Content-Type 을 우리가 정한다(내장 서버에 넘기지 않으므로).
$types = array(
    'html' => 'text/html; charset=UTF-8',
    'css'  => 'text/css; charset=UTF-8',
    'js'   => 'application/javascript; charset=UTF-8',
    'json' => 'application/json; charset=UTF-8',
    'txt'  => 'text/plain; charset=UTF-8',
    'svg'  => 'image/svg+xml',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'ico'  => 'image/x-icon',
    'woff' => 'font/woff',
    'woff2' => 'font/woff2',
    'map'  => 'application/json; charset=UTF-8',
);
header('Content-Type: ' . (isset($types[$fext]) ? $types[$fext] : 'application/octet-stream'));
header('Content-Length: ' . (string) filesize($realFile));
if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    readfile($realFile);
}
return;
