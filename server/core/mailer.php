<?php
declare(strict_types=1);

/**
 * core/mailer.php — 메일 발송 어댑터 (mail | log)
 * 담당: 리더 판정 D-1, 계약 §4-7(비밀번호 찾기), §6-4(이메일 변경)
 *
 * v1.0.0 기본 드라이버는 'log' 다. 발송 대신 server/storage/mail/ 에 파일로 남긴다.
 * storage/ 는 .htaccess 로 웹 접근을 차단한다 — 이 파일에는 재설정 링크가 들어 있다.
 *
 * **이 함수는 어떤 경우에도 예외를 밖으로 던지지 않는다.**
 * 비밀번호 찾기가 발송 실패로 500 을 뱉으면, 그 자체가
 * "이 이메일은 계정이 있다"는 신호가 되어 계정 열거를 허용한다.
 * base_url() 도 같은 이유로 예외를 던지지 않는다 — register.php 와
 * me/email.php 의 호출부는 try/catch 로 감싸여 있지 않다.
 */

// 직접 실행 차단 — 리더 판정 D-34. bootstrap.php 를 거치지 않은 요청은 존재하지 않는 파일이다.
if (!defined('ULOGIN_BOOTSTRAPPED')) {
    // X-Powered-By 제거 — D-154(2). 이 경로는 bootstrap.php 를 거치지 않는다.
    header_remove('X-Powered-By');
    http_response_code(404);
    exit;
}

/** 현재 드라이버. DB settings 가 config 상수보다 우선한다. */
function mail_driver(): string
{
    $d = (string) setting_get('mail_driver', defined('MAIL_DRIVER') ? MAIL_DRIVER : 'log');
    return in_array($d, ['mail', 'log'], true) ? $d : 'log';
}

/**
 * log 드라이버 출력 디렉토리. 없으면 만든다.
 *
 * 이 디렉토리에는 비밀번호 재설정 링크와 임시 비밀번호가 평문으로 쌓인다.
 * 방어를 .htaccess 하나에 걸지 않는다 (리더 판정 D-34 / 감사 H-5) —
 * nginx 나 `AllowOverride None` 환경에서는 .htaccess 가 아예 읽히지 않아
 * 디렉토리 목록까지 열린다. 그래서 생성 시점에 함께 심는다:
 *   (1) index.html  — 목록 노출 차단 (DirectoryIndex 가 이걸 먼저 준다)
 *   (2) .htaccess   — Apache 2.2/2.4 양쪽 문법 + PHP 실행 차단
 *   (3) 파일 자체는 0600, 확장자는 .php + 첫 줄 `<?php exit; ?>` (send_mail 참고)
 */
function mail_log_dir(): string
{
    $dir = __DIR__ . '/../storage/mail';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    // storage/ 자체의 목록도 막는다. 정적 파일로도 배포되지만 삭제·누락에 대비한다.
    $parent = __DIR__ . '/../storage';
    if (is_dir($parent) && !is_file($parent . '/index.html')) {
        @file_put_contents($parent . '/index.html', '');
    }
    if (is_dir($dir)) {
        if (!is_file($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', '');
        }
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', mail_dir_htaccess());
        }
    }
    return $dir;
}

/** storage/mail/.htaccess 내용. storage/.htaccess 와 같은 규칙을 유지한다. */
function mail_dir_htaccess(): string
{
    return "# 자동 생성 (core/mailer.php). 이 디렉토리는 웹에서 절대 열려서는 안 된다.\n"
         . "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
         . "<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n"
         . "Options -Indexes -ExecCGI\n"
         . "DirectoryIndex index.html\n"
         . "<IfModule mod_php.c>\n  php_flag engine off\n</IfModule>\n"
         . "<IfModule mod_php7.c>\n  php_flag engine off\n</IfModule>\n"
         . "<IfModule mod_php5.c>\n  php_flag engine off\n</IfModule>\n";
}

/**
 * @return bool 발송(또는 파일 저장) 성공 여부. 호출부는 이 값을 클라이언트 응답에
 *              그대로 반영하지 않는다 (계약 §4-7: sent 는 항상 true).
 */
function send_mail(string $to, string $subject, string $body): bool
{
    try {
        // ---- D-33 / 감사 H-4: BASE_URL 이 없으면 발송하지 않는다 -------------
        // 이 시스템이 보내는 메일은 전부 계정 회수·인증 메일이고 본문에 링크가 있다.
        // BASE_URL 이 비면 base_url() 이 빈 문자열을 주므로 링크가 깨진 채
        // 살아 있는 토큰이 메일함에(log 드라이버면 파일에) 남는다.
        // 예전 구현처럼 Host 헤더로 폴백하면 공격자가 링크 호스트를 정할 수 있다.
        // 그래서 설정 누락은 '발송 중단 + 서버 로그' 로 처리한다. fail-closed.
        // 호출부는 이 false 를 클라이언트 응답에 반영하지 않는다(계정 열거 방지).
        if (!mail_links_configured()) {
            error_log('[ulogin] BASE_URL is not configured. mail sending is disabled.'
                . ' set BASE_URL in config.php (see INSTALL.md). to=' . $to);
            return false;
        }

        $from     = defined('MAIL_FROM') ? MAIL_FROM : 'no-reply@localhost';
        $fromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'ulogin';

        if (mail_driver() === 'mail') {
            $headers = implode("\r\n", [
                'From: ' . mail_encode_name($fromName) . ' <' . $from . '>',
                'Reply-To: ' . $from,
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
                'X-Mailer: ulogin',
            ]);
            $ok = @mail($to, mail_encode_name($subject), $body, $headers);
            if (!$ok) {
                error_log('[ulogin] mail() returned false. to=' . $to);
            }
            return (bool) $ok;
        }

        // ---- log 드라이버 ------------------------------------------------
        // 파일 이름·확장자·첫 줄이 전부 방어 장치다 (리더 판정 D-34 / 감사 H-5):
        //   .php       — 정적 서빙되는 환경(nginx 의 .txt)에서 본문이 그대로 보이는 것을 막는다
        //   <?php exit — PHP 로 실행되는 환경에서는 즉시 종료돼 빈 응답이 나간다
        //   8바이트 난수 — 초 단위 타임스탬프만으로는 파일명을 추측할 수 있다(기존 4바이트)
        //   0600       — 같은 호스트의 다른 계정이 읽지 못하게
        $dir  = mail_log_dir();
        $file = $dir . '/' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.php';
        $out  = "<?php exit; ?>\r\n"
              . "To: {$to}\r\n"
              . "From: {$fromName} <{$from}>\r\n"
              . "Subject: {$subject}\r\n"
              . "Date: " . gmdate('D, d M Y H:i:s') . " +0000\r\n"
              . "\r\n"
              . $body . "\r\n";
        $r = @file_put_contents($file, $out);
        if ($r === false) {
            error_log('[ulogin] mail log write failed: ' . $file);
            return false;
        }
        @chmod($file, 0600);
        return true;
    } catch (Throwable $e) {
        error_log('[ulogin] send_mail failed: ' . $e->getMessage());
        return false;
    }
}

/** 비ASCII 제목·이름을 MIME 인코딩한다. 헤더 개행 주입도 여기서 막는다. */
function mail_encode_name(string $s): string
{
    $s = str_replace(["\r", "\n"], '', $s);
    if (preg_match('/^[\x20-\x7E]*$/', $s) === 1) {
        return $s;
    }
    return '=?UTF-8?B?' . base64_encode($s) . '?=';
}

/**
 * 재설정·인증 링크의 기준 주소. **config.php 의 BASE_URL 만 신뢰한다.**
 *
 * 리더 판정 D-33 / 감사 H-4 — Host 헤더 폴백을 제거했다.
 * `own_origin()` 은 `$_SERVER['HTTP_HOST']` 를 그대로 쓴다. 그 값은 요청을 보낸
 * 쪽이 정하므로, `Host: evil.example.net` 한 줄로 비밀번호 재설정 링크가
 * 공격자 호스트로 만들어진다 → 피해자가 클릭하면 토큰이 공격자 로그로 넘어간다.
 * 설정이 없으면 **주소를 추측하지 않는다.**
 *
 * 예외를 던지지 않는 이유: api/auth/register.php 의 `6) 이메일 인증 메일` 절과
 * api/me/email.php 의 `5) 메일` 절에 있는 호출부는 — 둘 다 db_tx() 트랜잭션의
 * try/catch **바깥**에 있으므로 — try/catch 로 감싸여 있지 않다. 여기서 던지면
 * 계정은 이미 만들어진 뒤에
 * 500 이 나가고, password_forgot 에서는 응답 차이가 계정 열거 오라클이 된다.
 * 대신 send_mail() 이 mail_links_configured() 로 발송 자체를 막는다(fail-closed).
 *
 * 행 번호는 적지 않는다 — 편집 한 번에 낡는다(D-92). 파일명 + 절 이름으로 가리킨다.
 * 호출부를 다시 세려면: grep -rn 'send_mail(' server/api
 *
 * @return string 설정된 BASE_URL(뒤쪽 / 제거) 또는 빈 문자열
 */
function base_url(): string
{
    $u = defined('BASE_URL') ? rtrim((string) BASE_URL, '/') : '';
    if ($u === '') {
        error_log('[ulogin] BASE_URL is not configured. mail links cannot be built.');
    }
    return $u;
}

/** BASE_URL 이 설정돼 링크를 만들 수 있는가. send_mail() 의 사전 조건. */
function mail_links_configured(): bool
{
    return defined('BASE_URL') && rtrim((string) BASE_URL, '/') !== '';
}
