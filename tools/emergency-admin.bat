@echo off
rem ==========================================================================
rem  ulogin v1.10.1 - emergency admin account CLI wrapper (D-374, D-375 M-2)
rem  Runs server\tools\emergency_admin.php and passes every argument through.
rem
rem    tools\emergency-admin.bat open                     (one-time key link for /local-admin, 10 min)
rem    tools\emergency-admin.bat --status
rem    tools\emergency-admin.bat --init --apply
rem    tools\emergency-admin.bat --set-password --apply   (password read twice from stdin, hidden)
rem    tools\emergency-admin.bat --reset --apply
rem    tools\emergency-admin.bat --unlock --apply
rem    tools\emergency-admin.bat --unset --apply
rem
rem  "open" = --open-link: writes a one-time key (HMAC only) to runtime\secrets\local-admin.key
rem  and opens http://127.0.0.1:PORT/local-admin?k=... in the default browser.
rem  PORT: ULOGIN_PORT env, else LOCAL_ADMIN_PORT in config.php, else a loopback BASE_URL port, else 8080.
rem  Without the key the page shows only a hint (no form).
rem
rem  There is no way to pass the password as an argument (it would stay in the process list).
rem  PHP: ULOGIN_PHP env, else php on PATH. php.ini: ULOGIN_PHP_INI env, else
rem  runtime\web\php-ulogin.ini next to the install root (via runtime\current or a version folder).
rem  Keep this file ASCII only: cmd.exe parses it in the console code page.
rem ==========================================================================
setlocal
set "ROOT=%~dp0.."
set "PHPBIN=php"
if defined ULOGIN_PHP set "PHPBIN=%ULOGIN_PHP%"
set "INI="
if defined ULOGIN_PHP_INI set "INI=%ULOGIN_PHP_INI%"
if not defined INI if exist "%ROOT%\..\web\php-ulogin.ini" set "INI=%ROOT%\..\web\php-ulogin.ini"
if not defined INI if exist "%ROOT%\..\runtime\web\php-ulogin.ini" set "INI=%ROOT%\..\runtime\web\php-ulogin.ini"
rem No goto/labels: this file is LF-only (.gitattributes) and cmd label search is unreliable without CRLF.
set "EA_ARGS=%*"
if /i "%~1"=="open" set "EA_ARGS=--open-link"
if defined INI (
  "%PHPBIN%" -c "%INI%" "%ROOT%\server\tools\emergency_admin.php" %EA_ARGS%
) else (
  "%PHPBIN%" "%ROOT%\server\tools\emergency_admin.php" %EA_ARGS%
)
set "RC=%ERRORLEVEL%"
endlocal & exit /b %RC%
