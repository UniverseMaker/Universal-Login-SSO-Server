# `fixtures/positive/root/` — **일부러 깨뜨린 미니 설치 루트**

**이것은 산출물이 아니다. 고치지 마라.** 여기 있는 결함은 전부 의도된 것이다.

## 왜 있는가

이 저장소에서 검사기 거짓 결과가 **21번** 났고, 그중 여럿이 **「0건」을 「결함 없음」으로**
읽히게 했다. `tools/php72_scan.php` 에서는 규칙이 **영구히 발화하지 않는 버그**가 실제로 잡혔다.
`grep -iF` 가 SIGABRT 로 죽고 `| wc -l` 이 그것을 삼켜 `0` 만 남긴 일도 있었다.

그래서 **검사기를 먼저 검증한다.** `tests/selfcheck/s_*.php` 가 티어 1 검사기를
이 루트에 대고 돌린다. 검사기가 여기 심어 둔 결함을 **잡아내야 정상**이다.
하나라도 통과하면(= 못 봤으면) 러너는 종료코드 **4** 로 즉시 멈추고 본 실행을 하지 않는다.
검사기를 검증하지 않은 "0건"은 증거가 아니기 때문이다.

## 심어 둔 결함 (검사기별)

| 검사기 | 심은 것 | 어디 |
|---|---|---|
| `chk_php_syntax` | 닫히지 않은 괄호 | `server/api/ghost/broken.php` |
| `chk_endpoints` | 화면이 없는 엔드포인트를 부른다 / 아무도 안 부르는 엔드포인트 | `public/index.html` · `server/api/ghost/orphan.php` |
| `chk_error_codes` | 서버가 던지는데 두 표에 없는 코드 / 표에만 있는 죽은 코드 | `server/api/auth/login.php` · `server/core/response.php` · `public/assets/api.js` |
| `chk_page_links` | 존재하지 않는 `.html` 로 가는 링크 | `public/index.html` |
| `chk_phantom_identifiers` | 정의되지 않은 함수를 인용하는 주석 | `server/core/response.php` |
| `chk_line_citations` | 주석의 줄 번호 인용 | `server/api/auth/login.php` |
| `chk_login_result_values` | 서버가 쓰는 값이 스키마 COMMENT·관리자 필터·화면에 없다 | `server/api/auth/login.php` vs `server/schema.sql` |
| `chk_migration_guards` | `@skip-if` 가드 없는 DDL | `server/migrations/0001_unguarded.sql` |
| `chk_htaccess_rules` | 규칙이 빠진 `.htaccess` | `public/.htaccess` |
| `chk_unconsumed_response_fields` | 아무 화면도 안 읽는 응답 필드 | `server/api/ghost/orphan.php` |
| `chk_table_constants` | 스키마에 없는 테이블 상수 / 상수 없는 테이블 / 가드 값 불일치 | `server/config.sample.php` · `server/core/bootstrap.php` · `server/schema.sql` |

## 왜 `.php` 확장자를 그대로 두는가

`chk_php_syntax` 를 검증하려면 **진짜 PHP 파일**이 필요하다. 확장자를 바꾸면
그 검사기만 자기검증 밖에 남는다.

대신 `tests/lib/harness.php` 의 `h_files()` 가 **`fixtures` 를 기본 제외**하고,
`php72_scan.php` 호출에는 `--exclude=fixtures` 를 준다. 그래서 티어 1 본 실행은
이 폴더를 보지 않는다. 자기검증은 이 루트를 `$root` 로 **직접 지목**해 들어오므로
제외에 걸리지 않는다 — 제외는 이름이 일치하는 디렉토리를 *통과*할 때만 작동한다.

**이 폴더를 서버에 올리지 마라.** `tests/` 전체가 `tools/` 와 같이 업로드 대상이 아니다.
