<?php
declare(strict_types=1);

// =====================================================================
// CLI SAPI 가 아니면 즉시 거부한다. **이 줄이 첫 번째 방어선이다.**
//
// 라우터(`router-ulogin.php`)가 `/tools` 를 막고 `tools/.htaccess` 도 막지만, 그 둘에
// 기대지 않는다 — 라우터 차단은 v1.7.0 에서 철자 한 글자로 두 번 뚫렸다(C17-1·C17-2).
// 이 파일은 인자 없이 실행되면 **서비스 8개(v1.8.4 에 hts 추가)를 등록하고 평문 시크릿 파일 8개를 쓴다.** 이미 옛 id 로 등록된 운영 DB 에는 등록이 아니라 `--rename`(v1.8.6)
// 웹으로 한 번 열리면 그것이 그대로 일어난다(변이 검증: 이 줄을 지우면 GET 한 번에 등록된다).
// =====================================================================
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * tools/register_services.php — 서비스 8개 등록 + handoff 파일 생성 (ulogin-link/1)
 *
 * 담당 판정: 리더 D-301(CLI 로 등록) · D-226·D-237(설정 번들 첫 실사용, 번들 파일 = 평문 키 파일)
 *            D-225·D-238(handoff 는 `{프로젝트 루트}/runtime/handoff/` 에만) · D-264(쓴 직후 ACL 재기)
 *            D-300·D-303(역할 오버라이드를 만들지 않는다)
 * 규격 정본: 연동 규격 문서 16장 (16.1 주소 · 16.2 서비스 표·handoff)
 * 설계:     `_workspace/70_architect_v17_spec.md` §D (allowed_origins 비움 · secrets 빈 배열 · hex 64자)
 *
 * ── 쓰는 법 ──────────────────────────────────────────────────────────
 *   php -c <웹 설정 폴더>/php-ulogin.ini <설치루트>/tools/register_services.php
 *
 *   --dry-run            검증 + 계획만. DB·파일을 하나도 쓰지 않는다
 *   --rotate             이미 등록된 서비스의 service_secret 을 **새로 발급**한다(그 서비스의 연동이 끊긴다)
 *   --only=admin,task    표의 일부만 대상으로 한다
 *   --handoff-dir=<경로> handoff 출력 폴더를 바꾼다(시험용). **이미 있는 폴더**여야 한다
 *   --admin=<아이디>     감사 로그에 남길 최고관리자. 활성 superadmin 이 하나뿐이면 생략한다
 *
 * ── 서비스 id 개명 (v1.8.6 — 설계 `_workspace/104_architect_v186_service_id_rename.md` E절 · D-341) ──
 *   --rename=mgm:admin[,mcc:task]  옛 id → 새 id. 쌍은 RS_RENAMES 표 안에서만. `--rename=all` = 표 전부
 *   --revert=<새id>                개명을 되돌린다(개명 뒤 생긴 행도 같이 돌아간다)
 *   --end-alias=<새id>             옛 id 해석을 멈춘다. 옛 id 재사용 금지는 계속된다
 *   --with-handoff                 --rename 과 함께: 개명 handoff(`<새id>.json`, **secret 없음**)도 쓴다
 *   --rename-handoff=<새id>[,…]    이미 개명된 서비스의 개명 handoff 만 쓴다(DB 무변경)
 *   --i-have-backup                MySQL 저장소에서 --rename/--revert 전제. 사본은 운영자가 먼저 뜬다
 *   --dry-run                      판정·건수만 찍는다. DB·파일을 쓰지 않는다
 * 개명 handoff 는 **기본으로 쓰지 않는다**(D-341 H-2 — 받는 시스템이 secret 없는 파일을 받아도 기존
 * secret 을 지키는지 확인되기 전에는 내보내지 않는다). 확인된 시스템만 `--rename-handoff=` 로 쓴다.
 * 개명 전제(어긋나면 종료코드 3, DB 무변경): 0005·0006 적용 · `public/version.txt` ≥ 1.8.6 ·
 * 활성 superadmin · SQLite 면 DB 와 같은 폴더에 `VACUUM INTO` 사본(ACL 확인) · MySQL 이면 --i-have-backup.
 * 시크릿·해시는 개명 경로의 어떤 출력·감사 행·handoff 에도 없다.
 *
 * ── 종료코드 — 각 코드는 해야 할 조치가 다르다 ───────────────────────
 *   0  성공. 등록 + handoff + ACL 확인까지 끝났다 (--dry-run 이면 계획 확인까지)
 *   1  번들이 거절했다(검증 오류 · 충돌 · 계획이 의도와 다름 · 낡은 계획). **DB 변경 없음**
 *   2  인자 오류
 *   3  실행 전제가 안 맞는다(설치 전 · DB 열기 실패 · 최고관리자 없음 · handoff 폴더 · ACL). **DB 변경 없음**
 *   4  이 도구가 고장났다(번들 파일에서 함수를 꺼내지 못함 = 그 파일 구조가 바뀌었다 · 예상 밖 예외). **DB 변경 없음**
 *   5  이미 등록된 서비스가 있다. 덮어쓰지 않았다. **DB 변경 없음** — `--only=` 로 빼거나 `--rotate`
 *   6  🔴 **DB 에는 반영됐는데** handoff 확정 또는 사후 대조가 실패했다. 사람이 봐야 한다.
 *      시크릿은 `<service_id>.json.pending` 에 남아 있다 — **지우지 마라.** 지우면 그 서비스는 `--rotate` 뿐이다
 *
 * ── 이 도구가 지키는 것 ──────────────────────────────────────────────
 *  · **service_secret 값을 화면·로그에 찍지 않는다.** 모든 출력은 `rs_say()` 를 지나며 그 함수가
 *    이번 실행에서 만든 시크릿 문자열을 기계적으로 가린다(내 코드가 실수해도 새지 않게).
 *    값이 존재하는 곳은 handoff 파일 하나뿐이다
 *  · **번들 파일을 디스크에 쓰지 않는다.** 번들은 메모리에서 조립해 함수에 바로 넘긴다(D-237 —
 *    번들 파일 자체가 평문 키 파일이다. 쓰지 않는 것이 `work/` 에 쓰고 지우는 것보다 강하다)
 *  · **역할 오버라이드를 만들지 않는다.** 번들은 `user_services` 를 건드리는 필드가 없다.
 *    적용 전후 `user_services.role IS NOT NULL` 행 수를 대조해 늘었으면 실패로 본다(D-303)
 *  · handoff 파일은 **ACL 을 먼저 좁힌 빈 파일에** 내용을 쓴다. 쓰고 나서 좁히면 그 사이에
 *    상속 ACL(프로젝트 트리의 `CodexSandboxUsers:(RX)` 등)로 읽힐 수 있다. 내용은 제자리 쓰기라
 *    ACL 이 바뀌지 않는다(D-264 의 「임시 파일에 쓰고 바꿔치기」가 ACL 을 되돌린 사고와 반대 순서)
 *  · DB 반영이 실패하면 handoff 를 남기지 않는다. DB 반영이 성공하면 시크릿을 **절대 잃지 않는다**
 *    — `.pending` 을 먼저 쓰고, 적용이 끝난 뒤에만 `.json` 으로 이름을 바꾼다
 *
 * ── 설정 번들을 「그대로」 쓰는 방식과 그 한계 (번들 첫 실사용의 산출물) ──
 * `server/api/admin/settings_bundle.php` 는 **함수 라이브러리가 아니라 엔드포인트 스크립트**다.
 * include 하는 순간 `only_method()` → `require_auth()`(superadmin 세션 필요) → `json_out()`(exit)
 * 가 돈다. dry-run 응답과 apply 의 쓰기 루프는 함수가 아니라 **요청 처리부의 인라인 코드와
 * 클로저(`$applyFn`)**다. 그래서 CLI 에서 「함수를 부를」 길이 없다.
 * → 이 도구는 그 파일을 **토큰 단위로 읽어** 최상위 `const` · 최상위 이름 있는 함수 ·
 *   `if (!function_exists(...))` 블록 · `$applyFn = function ...;` 문장 **원문 그대로**를 꺼내
 *   평가한다. 사본을 만들지 않는다 — 규칙이 두 곳에 생기는 것이 이 프로젝트가 가장 많이 당한 사고다.
 *   꺼낸 결과가 예상한 모양(필수 함수 · `use` 목록)과 다르면 **추측하지 않고 종료코드 4**로 멈춘다.
 * 근본 해결은 번들 로직을 `core/` 함수로 올리고 엔드포인트를 얇게 만드는 것이다. `server/` 는
 * 이번 라운드 검증 대상이라 고치지 않았다(D-173) — 리더에게 올린 항목이다.
 *
 * ── 하지 않는 것 ─────────────────────────────────────────────────────
 *  · 접근 규칙(16.9 `access_rule`)은 v1.9.0 이다. 이 판에 그 컬럼이 없다
 *  · 서비스를 지우지 않는다. 번들은 INSERT/UPDATE 만 한다
 *  · `config.php` · 설정(층 A)·약관을 건드리지 않는다
 *
 * PHP 7.2 문법만 쓴다(`tools/php72_scan.php` 대상). `glob()` 을 쓰지 않는다 — 경로의 `[예시]`.
 */

// ---------------------------------------------------------------------
// 등록 대상 — 연동 규격 16.2 표 그대로. 여기만 고치면 된다.
//
// v1.8.4 — 공개 주소를 새 도메인으로(리더 판정 D-318 · D-325). hts 를 더했다(D-324 H-5).
// v1.8.6 — **service_id 도 도메인 첫 마디로 바꾼다**(D-338). 이 표는 새 id 다. 이미 옛 id 로 등록된
// 운영 DB 는 `--rename` 으로 옮긴다(RS_RENAMES). 옛 id 가 아직 서비스로 있으면 새 id 등록을 거절한다 —
// 같은 시스템이 두 번 등록되면 개명이 막힌다. office 는 id 가 원래 도메인과 같다.
// ---------------------------------------------------------------------
const RS_SERVICES = [
    // service_id   이름              공개 주소                          내부 주소 (예시값 — 운영자가 자기 값으로 바꾼다)
    ['admin',    'Demo App A', 'https://admin.example.com',    'http://127.0.0.1:8101'],
    ['task',     'Demo App B', 'https://task.example.com',     'http://127.0.0.1:8102'],
    ['learning', 'Demo App C', 'https://learning.example.com', 'http://127.0.0.1:8103'],
    ['office',   'Demo AI App A',       'https://office.example.com',   'http://127.0.0.1:8104'],
    ['manager',  'Demo AI App B',       'https://manager.example.com',  'http://127.0.0.1:8105'],
    ['research', 'Demo Lab App A',     'https://research.example.com', 'http://127.0.0.1:8106'],
    ['sim',      'Demo Lab App B',     'https://sim.example.com',      'http://127.0.0.1:8107'],
    ['lab',      'Demo Lab App C',     'https://lab.example.com',      'http://127.0.0.1:8108'],
];
/** 개명 대응표 — 옛 id → 새 id (D-338). `--rename=` 은 이 표 안의 쌍만 받는다. */
const RS_RENAMES = [
    ['mgm', 'admin'], ['mcc', 'task'], ['alc', 'learning'], ['aim', 'manager'], ['rsa', 'research'], ['hts', 'lab'],
];
const RS_RENAME_FORMAT = 'ulogin-link/1 rename';
/** 복귀 경로 — 16.2 「복귀 주소는 모든 시스템이 같다」. */
const RS_CALLBACK_PATH = '/sso/callback';
/** ulogin 주소 — 16.1. 브라우저는 공개, 같은 PC 의 서버 간 호출은 내부. */
const RS_ULOGIN_PUBLIC   = 'https://login.example.com';
const RS_ULOGIN_INTERNAL = 'http://127.0.0.1:8080';
const RS_EXCHANGE_PATH   = '/server/api/auth/exchange.php';
const RS_HANDOFF_FORMAT  = 'ulogin-link/1 handoff';

const RS_EXIT_OK       = 0;
const RS_EXIT_REFUSED  = 1;
const RS_EXIT_USAGE    = 2;
const RS_EXIT_PRECOND  = 3;
const RS_EXIT_BROKEN   = 4;
const RS_EXIT_EXISTS   = 5;
const RS_EXIT_PARTIAL  = 6;

/** `$applyFn` 클로저의 `use` 목록. 이 도구가 같은 이름의 변수를 마련한다. 다르면 멈춘다. */
const RS_APPLY_USE = ['$norm', '$expect', '$adminId', '$reason', '&$planErrs', '&$applyWarn'];
/** 꺼낸 코드에 반드시 있어야 하는 함수. */
const RS_REQUIRED_FUNCS = ['sb_validate', 'sb_build_plan', 'sb_is_race_error', 'admin_log_write'];

// =====================================================================
// 출력 — 시크릿 기계적 가림
// =====================================================================

/** 이번 실행에서 만든 시크릿 값. 출력 직전에 가린다. */
$GLOBALS['RS_REDACT'] = [];

function rs_redact(string $s): string
{
    foreach ($GLOBALS['RS_REDACT'] as $v) {
        if ($v !== '') {
            $s = str_replace($v, '[REDACTED]', $s);
        }
    }
    return $s;
}

function rs_say(string $msg): void
{
    fwrite(STDOUT, rs_redact($msg) . "\n");
}

function rs_err(string $msg): void
{
    fwrite(STDERR, rs_redact($msg) . "\n");
}

/** 메시지를 찍고 종료한다. 종료코드가 곧 판정이다. */
function rs_die(int $code, string $msg): void
{
    rs_err('[register_services] ' . $msg);
    rs_err('[register_services] exit ' . (string) $code);
    exit($code);
}

// =====================================================================
// 인자
// =====================================================================

function rs_usage(): string
{
    return "usage: php [-c <ini>] tools/register_services.php [--dry-run] [--rotate] [--only=id,id]\n"
        . "                                                 [--handoff-dir=<dir>] [--admin=<username>]\n"
        . "       php [-c <ini>] tools/register_services.php --rename=old:new[,old:new]|all [--with-handoff]\n"
        . "                                                 [--dry-run] [--i-have-backup] [--admin=<username>]\n"
        . "       php [-c <ini>] tools/register_services.php --revert=<new> | --end-alias=<new> | --rename-handoff=<new>[,..]";
}

/** @return array{dry:bool,rotate:bool,only:?array,handoff:?string,admin:?string} */
function rs_parse_args(array $argv): array
{
    $o = ['dry' => false, 'rotate' => false, 'only' => null, 'handoff' => null, 'admin' => null,
          'mode' => 'register', 'pairs' => [], 'target' => null, 'with_handoff' => false, 'backup_ok' => false];
    $setMode = function (string $m) use (&$o) {
        if ($o['mode'] !== 'register') {
            rs_die(RS_EXIT_USAGE, '--rename · --revert · --end-alias · --rename-handoff 는 하나만 줄 수 있다.');
        }
        $o['mode'] = $m;
    };
    $newIds = [];
    foreach (RS_RENAMES as $p) {
        $newIds[] = $p[1];
    }
    $known = [];
    foreach (RS_SERVICES as $s) {
        $known[] = $s[0];
    }
    foreach (array_slice($argv, 1) as $a) {
        $a = (string) $a;
        if ($a === '--help' || $a === '-h') {
            fwrite(STDOUT, rs_usage() . "\n");
            exit(RS_EXIT_OK);
        }
        if ($a === '--dry-run') {
            $o['dry'] = true;
        } elseif ($a === '--rotate') {
            $o['rotate'] = true;
        } elseif (strpos($a, '--only=') === 0) {
            $ids = array_values(array_filter(array_map('trim', explode(',', substr($a, 7))), 'strlen'));
            if ($ids === []) {
                rs_die(RS_EXIT_USAGE, '--only= 가 비었다. ' . rs_usage());
            }
            foreach ($ids as $id) {
                if (!in_array($id, $known, true)) {
                    rs_die(RS_EXIT_USAGE, '--only= 에 표에 없는 service_id: ' . $id . ' (가능: ' . implode(',', $known) . ')');
                }
            }
            $o['only'] = array_values(array_unique($ids));
        } elseif (strpos($a, '--handoff-dir=') === 0) {
            $d = substr($a, 14);
            if ($d === '') {
                rs_die(RS_EXIT_USAGE, '--handoff-dir= 가 비었다.');
            }
            $o['handoff'] = $d;
        } elseif (strpos($a, '--rename=') === 0) {
            $setMode('rename');
            $v = substr($a, 9);
            if ($v === 'all') {
                $o['pairs'] = RS_RENAMES;
            } else {
                foreach (array_values(array_filter(array_map('trim', explode(',', $v)), 'strlen')) as $pp) {
                    $ab = explode(':', $pp);
                    if (count($ab) !== 2 || !in_array([$ab[0], $ab[1]], RS_RENAMES, true)) {
                        rs_die(RS_EXIT_USAGE, '--rename= 의 쌍이 대응표(RS_RENAMES)에 없다: ' . $pp);
                    }
                    if (!in_array([$ab[0], $ab[1]], $o['pairs'], true)) {
                        $o['pairs'][] = [$ab[0], $ab[1]];
                    }
                }
            }
            if ($o['pairs'] === []) {
                rs_die(RS_EXIT_USAGE, '--rename= 가 비었다. ' . rs_usage());
            }
        } elseif (strpos($a, '--revert=') === 0 || strpos($a, '--end-alias=') === 0 || strpos($a, '--rename-handoff=') === 0) {
            $eq = strpos($a, '=');
            $flag = substr($a, 2, $eq - 2);
            $setMode($flag === 'revert' ? 'revert' : ($flag === 'end-alias' ? 'end_alias' : 'rename_handoff'));
            $ids = array_values(array_filter(array_map('trim', explode(',', substr($a, $eq + 1))), 'strlen'));
            if ($ids === [] || ($flag !== 'rename-handoff' && count($ids) !== 1)) {
                rs_die(RS_EXIT_USAGE, '--' . $flag . '= 에는 새 id ' . ($flag === 'rename-handoff' ? '하나 이상' : '하나') . '가 필요하다.');
            }
            foreach ($ids as $id) {
                if (!in_array($id, $newIds, true)) {
                    rs_die(RS_EXIT_USAGE, '--' . $flag . '= 의 id 가 대응표의 새 id 가 아니다: ' . $id);
                }
            }
            $o['target'] = array_values(array_unique($ids));
        } elseif ($a === '--with-handoff') {
            $o['with_handoff'] = true;
        } elseif ($a === '--i-have-backup') {
            $o['backup_ok'] = true;
        } elseif (strpos($a, '--admin=') === 0) {
            $u = substr($a, 8);
            if ($u === '') {
                rs_die(RS_EXIT_USAGE, '--admin= 가 비었다.');
            }
            $o['admin'] = $u;
        } else {
            rs_die(RS_EXIT_USAGE, '알 수 없는 인자: ' . $a . "\n" . rs_usage());
        }
    }
    if ($o['mode'] !== 'register' && ($o['rotate'] || $o['only'] !== null)) {
        rs_die(RS_EXIT_USAGE, '--rotate · --only= 는 개명 계열 옵션과 함께 쓸 수 없다.');
    }
    if ($o['with_handoff'] && $o['mode'] !== 'rename') {
        rs_die(RS_EXIT_USAGE, '--with-handoff 는 --rename 과 함께만 쓴다(이미 개명된 서비스는 --rename-handoff=).');
    }
    return $o;
}

// =====================================================================
// 설정 번들 코드 꺼내기 — 사본 없이 원문을 평가한다
// =====================================================================

/** 토큰 하나의 원문. */
function rs_tok_text($t): string
{
    return is_array($t) ? (string) $t[1] : (string) $t;
}

/** 공백·주석이 아닌 다음 토큰의 인덱스. 없으면 -1. */
function rs_tok_next(array $toks, int $i): int
{
    $n = count($toks);
    for ($j = $i + 1; $j < $n; $j++) {
        $t = $toks[$j];
        if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        return $j;
    }
    return -1;
}

function rs_tok_opens($t): bool
{
    if ($t === '{') {
        return true;
    }
    return is_array($t) && ($t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES);
}

/**
 * `$i` 에서 시작하는 문장을 **중괄호 깊이가 다시 0 이 되는 `}`** 까지(블록형) 또는
 * **깊이 0 · 괄호 깊이 0 의 `;`** 까지(문장형) 잘라 원문과 끝 인덱스를 돌려준다.
 */
function rs_tok_take(array $toks, int $i, bool $block, int &$end): string
{
    $n = count($toks);
    $depth = 0;
    $paren = 0;
    $seenOpen = false;
    $out = '';
    for ($j = $i; $j < $n; $j++) {
        $t = $toks[$j];
        $out .= rs_tok_text($t);
        if (rs_tok_opens($t)) {
            $depth++;
            $seenOpen = true;
        } elseif ($t === '}') {
            $depth--;
            if ($block && $seenOpen && $depth === 0) {
                $end = $j;
                return $out;
            }
        } elseif ($t === '(' || $t === '[') {
            $paren++;
        } elseif ($t === ')' || $t === ']') {
            $paren--;
        } elseif ($t === ';' && !$block && $depth === 0 && $paren === 0) {
            $end = $j;
            return $out;
        }
    }
    $end = -1;
    return '';
}

/**
 * 번들 파일에서 정의부와 `$applyFn` 문장을 꺼낸다.
 * @return array{defs:string,apply:string}|null  null 이면 $why 에 사유
 */
function rs_extract_bundle(string $file, ?string &$why)
{
    $src = @file_get_contents($file);
    if (!is_string($src) || $src === '') {
        $why = '번들 파일을 읽을 수 없다: ' . $file;
        return null;
    }
    $toks  = token_get_all($src);
    $n     = count($toks);
    $depth = 0;
    $defs  = [];
    $apply = null;
    for ($i = 0; $i < $n; $i++) {
        $t = $toks[$i];
        if ($depth === 0 && is_array($t)) {
            $end   = -1;
            $tried = false;
            $chunk = '';
            if ($t[0] === T_CONST) {
                $tried = true;
                $chunk = rs_tok_take($toks, $i, false, $end);
                $defs[] = $chunk;
            } elseif ($t[0] === T_FUNCTION) {
                $nx = rs_tok_next($toks, $i);
                if ($nx >= 0 && is_array($toks[$nx]) && $toks[$nx][0] === T_STRING) {
                    $tried = true;
                    $chunk = rs_tok_take($toks, $i, true, $end);
                    $defs[] = $chunk;
                }
            } elseif ($t[0] === T_IF) {
                // `if (!function_exists('...')) { function ...}` — 정의를 감싼 가드만 꺼낸다.
                $probe = '';
                for ($k = $i, $c = 0; $k < $n && $c < 12; $k++) {
                    $tt = $toks[$k];
                    if (is_array($tt) && in_array($tt[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $probe .= rs_tok_text($tt);
                    $c++;
                }
                if (preg_match('/^if\(!function_exists\(\'[a-z0-9_]+\'\)\)\{/', $probe) === 1) {
                    $tried = true;
                    $chunk = rs_tok_take($toks, $i, true, $end);
                    $defs[] = $chunk;
                }
            } elseif ($t[0] === T_VARIABLE && $t[1] === '$applyFn') {
                $nx = rs_tok_next($toks, $i);
                if ($nx >= 0 && $toks[$nx] === '=') {
                    if ($apply !== null) {
                        $why = '$applyFn 대입이 두 번 나온다 — 어느 쪽이 실제 경로인지 알 수 없다.';
                        return null;
                    }
                    $tried = true;
                    $chunk = rs_tok_take($toks, $i, false, $end);
                    $apply = $chunk;
                }
            }
            if ($tried) {
                if ($end <= $i || $chunk === '') {
                    $why = '정의부를 끝까지 자르지 못했다(토큰 ' . (string) $i . ' 줄 ' . (string) $t[2] . ').';
                    return null;
                }
                $i = $end;
                continue;
            }
        }
        if (rs_tok_opens($t)) {
            $depth++;
        } elseif ($t === '}') {
            $depth--;
        }
    }
    if ($apply === null || $apply === '') {
        $why = '`$applyFn = function ...;` 문장을 찾지 못했다 — apply 경로가 바뀌었다.';
        return null;
    }
    $defsCode = implode("\n", $defs);
    // 원래 파일 위치에 기대는 코드는 옮겨 평가하면 뜻이 바뀐다. 있으면 멈춘다.
    foreach (token_get_all('<?php ' . $defsCode . "\n" . $apply) as $tt) {
        if (is_array($tt) && ($tt[0] === T_DIR || $tt[0] === T_FILE)) {
            $why = '꺼낸 코드에 __DIR__/__FILE__ 가 있다 — 옮겨 평가하면 뜻이 바뀐다.';
            return null;
        }
    }
    if (preg_match('/^\$applyFn\s*=\s*function\s*\(\s*\)\s*use\s*\(([^)]*)\)/', $apply, $m) !== 1) {
        $why = '$applyFn 의 머리 모양이 예상과 다르다.';
        return null;
    }
    $use = array_map('trim', explode(',', $m[1]));
    if ($use !== RS_APPLY_USE) {
        $why = '$applyFn 의 use 목록이 바뀌었다: (' . implode(', ', $use) . ') — 이 도구가 마련하는 변수와 다르다.';
        return null;
    }
    return ['defs' => $defsCode, 'apply' => $apply];
}

// =====================================================================
// 파일 · ACL
// =====================================================================

function rs_is_windows(): bool
{
    return stripos(PHP_OS, 'WIN') === 0;
}

/** System32 실행 파일 절대 경로. PATH 에 기대지 않는다(Git Bash 의 GNU whoami 가 먼저 잡힌다 — 실측). */
function rs_sys32(string $exe): string
{
    $root = getenv('SystemRoot');
    if (!is_string($root) || $root === '') {
        $root = 'C:/Windows';
    }
    return '"' . str_replace('\\', '/', $root) . '/System32/' . $exe . '"';
}

/** 현재 사용자 SID. */
function rs_current_sid(): ?string
{
    $out = [];
    $rc  = 1;
    @exec(rs_sys32('whoami.exe') . ' /user /fo csv /nh', $out, $rc);
    if ($rc !== 0 || !isset($out[0])) {
        return null;
    }
    $row = str_getcsv(trim((string) $out[0]));
    $sid = isset($row[1]) ? trim((string) $row[1]) : '';
    return preg_match('/^S-1-5-21-[0-9-]+$/', $sid) === 1 ? $sid : null;
}

/**
 * 파일 DACL 을 SDDL 로 읽는다(`icacls /save`). 언어 설정과 무관하게 SID 로 판정하려는 것이다 —
 * `icacls` 의 사람용 출력은 경로가 콘솔 코드페이지로 깨지고 계정 이름이 지역화된다.
 */
function rs_read_sddl(string $file): ?string
{
    $tmp = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/rs_acl_' . bin2hex(random_bytes(8)) . '.txt';
    $out = [];
    $rc  = 1;
    @exec(rs_sys32('icacls.exe') . ' ' . escapeshellarg($file) . ' /save ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
    $raw = is_file($tmp) ? (string) @file_get_contents($tmp) : '';
    @unlink($tmp);
    if ($rc !== 0 || $raw === '') {
        return null;
    }
    $txt = function_exists('mb_convert_encoding') ? mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE') : (string) @iconv('UTF-16LE', 'UTF-8', $raw);
    $sddl = null;
    foreach (preg_split('/\r\n|\n|\r/', (string) $txt) as $line) {
        $line = trim($line);
        if (strpos($line, 'D:') === 0) {
            $sddl = $line;
        }
    }
    return $sddl;
}

/**
 * ACL 판정. 통과 조건: 상속 차단(`D:P`) · 상속 ACE 0개 · 허용 ACE 의 주체가
 * {현재 사용자, SYSTEM, Administrators} 뿐 · 현재 사용자가 있다 · 거부 ACE 없음.
 * @return string|null null = 통과, 문자열 = 실패 사유
 */
function rs_acl_problem(string $file, string $sid): ?string
{
    if (!rs_is_windows()) {
        clearstatcache(true, $file);
        $perm = @fileperms($file);
        if (!is_int($perm)) {
            return '권한을 읽을 수 없다';
        }
        return ($perm & 0077) === 0 ? null : sprintf('mode %o — 소유자 밖에 권한이 있다', $perm & 0777);
    }
    $sddl = rs_read_sddl($file);
    if ($sddl === null) {
        return 'icacls /save 로 ACL 을 읽지 못했다';
    }
    if (preg_match('/^D:([A-Z]*)((?:\([^)]*\))*)$/', $sddl, $m) !== 1) {
        return 'SDDL 모양을 해석하지 못했다';
    }
    if (strpos($m[1], 'P') === false) {
        return '상속이 차단되지 않았다(D:' . $m[1] . ')';
    }
    preg_match_all('/\(([^)]*)\)/', $m[2], $aces);
    $allowed = ['SY', 'BA', 'S-1-5-18', 'S-1-5-32-544', $sid];
    $hasMe = false;
    foreach ($aces[1] as $ace) {
        $f = explode(';', $ace);
        if (count($f) < 6) {
            return 'ACE 모양을 해석하지 못했다';
        }
        if ($f[0] !== 'A') {
            return '허용이 아닌 ACE 가 있다(' . $f[0] . ')';
        }
        if (strpos($f[1], 'ID') !== false) {
            return '상속된 ACE 가 남아 있다';
        }
        if (!in_array($f[5], $allowed, true)) {
            return '허용 밖 주체가 있다: ' . $f[5];
        }
        if ($f[5] === $sid) {
            $hasMe = true;
        }
    }
    return $hasMe ? null : '현재 사용자 ACE 가 없다';
}

/** 빈 파일의 ACL 을 소유자 + SYSTEM + Administrators 로 좁힌다. */
function rs_acl_restrict(string $file, string $sid): bool
{
    if (!rs_is_windows()) {
        return @chmod($file, 0600);
    }
    $out = [];
    $rc  = 1;
    @exec(rs_sys32('icacls.exe') . ' ' . escapeshellarg($file)
        . ' /inheritance:r /grant:r "*' . $sid . ':(F)" "*S-1-5-18:(F)" "*S-1-5-32-544:(F)" 2>&1', $out, $rc);
    return $rc === 0;
}

/**
 * handoff 한 개를 `.pending` 으로 쓴다. 순서: 배타 생성(빈 파일) → ACL 좁힘 → ACL 판정 → 제자리 쓰기 → 재판정.
 * @return string|null null = 성공, 문자열 = 실패 사유 (만든 파일은 지운다)
 */
function rs_write_pending(string $path, string $json, string $sid): ?string
{
    $h = @fopen($path, 'x');
    if ($h === false) {
        return '배타 생성 실패(이미 있거나 쓸 수 없다)';
    }
    fclose($h);
    if (!rs_acl_restrict($path, $sid)) {
        @unlink($path);
        return 'ACL 을 좁히지 못했다';
    }
    $p = rs_acl_problem($path, $sid);
    if ($p !== null) {
        @unlink($path);
        return 'ACL 판정 실패(쓰기 전): ' . $p;
    }
    $h = @fopen($path, 'r+');
    if ($h === false) {
        @unlink($path);
        return '열기 실패';
    }
    $ok = fwrite($h, $json) === strlen($json) && fflush($h);
    fclose($h);
    if (!$ok) {
        @unlink($path);
        return '쓰기 실패';
    }
    $p = rs_acl_problem($path, $sid);
    if ($p !== null) {
        @unlink($path);
        return 'ACL 판정 실패(쓴 뒤): ' . $p;
    }
    return null;
}

// =====================================================================
// 본체
// =====================================================================

function rs_main(array $argv): int
{
    $opt = rs_parse_args($argv);

    // ---- 설치 루트 · 설치 완료 ------------------------------------------
    // tools/ 의 부모가 설치 루트다(tools/README.md). `__DIR__` 는 접합을 푼 실경로다.
    $root   = str_replace('\\', '/', dirname(__DIR__));
    $server = $root . '/server';
    foreach (['/core/bootstrap.php', '/api/admin/settings_bundle.php', '/core/settingsdef.php',
              '/core/secretguard.php', '/core/urlrules.php'] as $rel) {
        if (!is_file($server . $rel)) {
            rs_die(RS_EXIT_PRECOND, '설치 루트가 아니다 — 없음: server' . $rel);
        }
    }
    // **bootstrap 전에 본다.** bootstrap 은 config.php 가 없으면 json_err() 로 끝나고
    // 그 exit 는 종료코드 0 이다 — 「설치 전」이 성공으로 보이게 된다.
    if (!is_file($server . '/config.php')) {
        rs_die(RS_EXIT_PRECOND, '설치 전이다 — server/config.php 가 없다. 설치기(install.php)를 먼저 끝내라.');
    }

    require_once $server . '/core/bootstrap.php';
    // bootstrap 의 예외 처리기는 JSON 봉투를 찍고 `exit`(=0) 한다. CLI 판정과 맞지 않으므로 바꾼다.
    set_exception_handler(function (Throwable $e) {
        rs_err('[register_services] 예상 밖 예외: ' . get_class($e) . ' — ' . $e->getMessage());
        rs_err('[register_services] exit ' . (string) RS_EXIT_BROKEN);
        exit(RS_EXIT_BROKEN);
    });
    require_once $server . '/core/secretguard.php';
    require_once $server . '/core/settingsdef.php';
    require_once $server . '/core/urlrules.php';

    // ---- v1.8.6 개명 계열은 번들을 쓰지 않는다 — 따로 간다 ---------------------
    if ($opt['mode'] !== 'register') {
        require_once $server . '/core/servicerename.php';
        return rs_rename_main($opt, $root);
    }

    // ---- 번들 함수 -----------------------------------------------------
    $why = null;
    $ex  = rs_extract_bundle($server . '/api/admin/settings_bundle.php', $why);
    if ($ex === null) {
        rs_die(RS_EXIT_BROKEN, '설정 번들 코드를 꺼내지 못했다: ' . (string) $why);
    }
    eval("declare(strict_types=1);\n" . $ex['defs']);
    foreach (RS_REQUIRED_FUNCS as $fn) {
        if (!function_exists($fn)) {
            rs_die(RS_EXIT_BROKEN, '꺼낸 코드에 필수 함수가 없다: ' . $fn);
        }
    }
    if (!defined('SB_MAX_BODY')) {
        rs_die(RS_EXIT_BROKEN, '꺼낸 코드에 SB_MAX_BODY 상수가 없다.');
    }

    // ---- DB · 감사 로그 주체 -----------------------------------------------
    $adminId = rs_open_db_pick_admin($opt);

    // ---- 대상 · 기존 등록 ---------------------------------------------------
    $targets = [];
    foreach (RS_SERVICES as $s) {
        if ($opt['only'] === null || in_array($s[0], $opt['only'], true)) {
            $targets[$s[0]] = ['id' => $s[0], 'name' => $s[1], 'public' => $s[2], 'internal' => $s[3]];
        }
    }
    if ($targets === []) {
        rs_die(RS_EXIT_USAGE, '대상 서비스가 0개다.');
    }
    $ids = array_keys($targets);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $existing = [];
    foreach (db_all('SELECT * FROM ' . T_SERVICES . ' WHERE service_id IN (' . $ph . ')', $ids) as $r) {
        $existing[(string) $r['service_id']] = $r;
    }
    // v1.8.6 — 옛 id 를 새로 등록하지 않는다 / 아직 옛 id 로 있는 시스템을 새 id 로 또 등록하지 않는다(설계 104 C-4).
    //          둘 다 막지 않으면 같은 시스템이 두 서비스가 되고 `--rename` 이 「두 id 가 모두 있다」로 멈춘다.
    foreach ($ids as $id) {
        if (!isset($existing[$id]) && service_id_taken($id) === 'reserved') {   // v1.9.0 L190-1
            rs_die(RS_EXIT_REFUSED, $id . ' 는 예약 id(ulogin·*)라 등록할 수 없다. 아무것도 바꾸지 않았다.');
        }
        if (!isset($existing[$id]) && service_id_taken($id) === 'former') {
            rs_die(RS_EXIT_REFUSED, $id . ' 는 다른 서비스의 개명 전 id 라 새로 등록할 수 없다. 아무것도 바꾸지 않았다.');
        }
        foreach (RS_RENAMES as $pr) {
            if ($pr[1] === $id && !isset($existing[$id])
                && db_val('SELECT id FROM ' . T_SERVICES . ' WHERE service_id = ?', [$pr[0]]) !== null) {
                rs_die(RS_EXIT_REFUSED, $id . ' 의 옛 id ' . $pr[0] . ' 가 아직 서비스로 있다 — 등록이 아니라 --rename=' . $pr[0] . ':' . $id
                    . ' 로 옮겨야 한다. 아무것도 바꾸지 않았다.');
            }
        }
    }
    if ($existing !== [] && !$opt['rotate']) {
        rs_say('이미 등록됨: ' . implode(', ', array_keys($existing)));
        rs_die(RS_EXIT_EXISTS, '이미 등록된 서비스가 있어 멈췄다. 아무것도 바꾸지 않았다. '
            . 'secret 을 새로 만들면 이미 연동한 시스템이 끊긴다 — 빼려면 --only=, 새로 발급하려면 --rotate.');
    }
    $roleOvBefore = (int) db_val('SELECT COUNT(*) FROM ' . T_USER_SERVICES
        . ' WHERE service_id IN (' . $ph . ') AND role IS NOT NULL', $ids);

    // BASE_URL 은 거절 사유가 아니다(정본 대조만). 규격 16.1 과 다르면 알린다.
    $base = defined('BASE_URL') ? rtrim((string) BASE_URL, '/') : '';
    if ($base !== RS_ULOGIN_PUBLIC) {
        rs_say('경고: config.php 의 BASE_URL 이 규격 16.1 공개 주소(' . RS_ULOGIN_PUBLIC . ')와 다르다: ' . $base);
    }

    // ---- handoff 폴더 ------------------------------------------------------
    // 기본값 = 설치 루트의 부모 = 프로젝트 루트(D-238). SQLite 기본 경로(`../runtime/data`)와 같은 기준이다.
    $sid = null;
    $handoffDir = null;
    if (!$opt['dry']) {
        if ($opt['handoff'] !== null) {
            $handoffDir = rtrim(str_replace('\\', '/', $opt['handoff']), '/');
            if (!is_dir($handoffDir)) {
                rs_die(RS_EXIT_PRECOND, '--handoff-dir 이 없는 폴더다(오타로 엉뚱한 곳에 만들지 않는다): ' . $handoffDir);
            }
        } else {
            $handoffDir = dirname(ulogin_install_root()) . '/runtime/handoff';
            if (!is_dir($handoffDir) && !@mkdir($handoffDir, 0700, true)) {
                rs_die(RS_EXIT_PRECOND, 'handoff 폴더를 만들 수 없다: ' . $handoffDir);
            }
        }
        if (!is_writable($handoffDir)) {
            rs_die(RS_EXIT_PRECOND, 'handoff 폴더에 쓸 수 없다: ' . $handoffDir);
        }
        foreach ($ids as $id) {
            foreach (['.json.pending', '.json'] as $ext) {
                $f = $handoffDir . '/' . $id . $ext;
                if (file_exists($f) && !($ext === '.json' && $opt['rotate'] && isset($existing[$id]))) {
                    rs_die(RS_EXIT_PRECOND, '이미 있는 파일을 덮어쓰지 않는다: ' . $f
                        . ($ext === '.json.pending' ? ' — 이전 실행이 종료코드 6 으로 남긴 것일 수 있다. 그 안의 시크릿이 DB 에 반영돼 있을 수 있다.'
                                                   : ' — 소비되지 않은 handoff 다. 받는 시스템이 읽었는지 확인하라.'));
                }
            }
        }
        if (rs_is_windows()) {
            $sid = rs_current_sid();
            if ($sid === null) {
                rs_die(RS_EXIT_PRECOND, '현재 사용자 SID 를 읽지 못했다(whoami). ACL 을 좁힐 수 없으므로 쓰지 않는다.');
            }
        } else {
            $sid = 'posix';
        }
    }

    // ---- 시크릿 · 번들 ---------------------------------------------------------
    $secrets = [];
    foreach ($ids as $id) {
        $secrets[$id] = bin2hex(random_bytes(32));        // hex 64자 (설계 §D)
        $GLOBALS['RS_REDACT'][] = $secrets[$id];
    }
    $svcs = [];
    $expectOp = [];
    foreach ($targets as $id => $t) {
        if (isset($existing[$id])) {
            // --rotate: secret 만 바꾼다. 이름은 현재 값 그대로 보내 변경 0 으로 만든다.
            $svcs[] = ['service_id' => $id, 'service_name' => (string) $existing[$id]['service_name'],
                       'service_secret' => $secrets[$id]];
            $expectOp[$id] = 'update';
        } else {
            $svcs[] = [
                'service_id'         => $id,
                'service_name'       => $t['name'],
                'is_confidential'    => true,
                'service_secret'     => $secrets[$id],
                'redirect_whitelist' => [$t['public'] . RS_CALLBACK_PATH, $t['internal'] . RS_CALLBACK_PATH],
                'allowed_origins'    => [],
                'status'             => 'active',
                'secrets'            => [],
            ];
            $expectOp[$id] = 'create';
        }
    }
    $bundle = [
        'ulogin_settings_bundle' => SB_VERSION,
        'on_conflict'            => $opt['rotate'] ? 'merge' : 'fail',
        'services'               => $svcs,
        'generator'              => 'tools/register_services.php',
    ];
    $bytes = strlen((string) json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if ($bytes > SB_MAX_BODY) {
        rs_die(RS_EXIT_REFUSED, '번들이 상한을 넘는다: ' . (string) $bytes . ' B');
    }

    // ---- 1) validate (DB 를 읽지 않는다) ------------------------------------
    $errs = [];
    $warn = [];
    sb_validate($bundle, $errs, $warn, true);
    rs_report_errs('validate', $errs, $warn);
    if ($errs !== []) {
        rs_die(RS_EXIT_REFUSED, 'validate 가 거절했다. 아무것도 바꾸지 않았다.');
    }
    rs_say('validate: 통과 (서비스 ' . (string) count($svcs) . ', 시크릿 0, ' . (string) $bytes . ' B)');

    // ---- 2) dry-run (DB 대조 · 계획 · plan_hash) ------------------------------
    $errs = [];
    $warn = [];
    $norm = sb_validate($bundle, $errs, $warn, false);
    $built = sb_build_plan($norm, false, false, $errs, $warn);
    rs_report_errs('dry-run', $errs, $warn);
    if ($errs !== []) {
        rs_die(RS_EXIT_REFUSED, 'dry-run 이 거절했다. 아무것도 바꾸지 않았다.');
    }
    if ($built['conflicts'] !== []) {
        foreach ($built['conflicts'] as $c) {
            rs_say('  충돌 ' . (string) ($c['service_id'] ?? '?') . ': ' . (string) ($c['message'] ?? ''));
        }
        rs_die(RS_EXIT_REFUSED, 'dry-run 이 충돌을 보고했다. 아무것도 바꾸지 않았다.');
    }
    $bad = rs_plan_mismatch($built, $expectOp);
    foreach ($built['plan']['services'] as $p) {
        rs_say(sprintf('  %-8s %-9s %s', (string) $p['service_id'], (string) $p['op'],
            $p['changed_fields'] === [] ? '' : implode(',', $p['changed_fields'])));
    }
    if ($bad !== null) {
        rs_die(RS_EXIT_REFUSED, '계획이 의도와 다르다: ' . $bad . ' — 아무것도 바꾸지 않았다.');
    }
    $hash = (string) $built['hash'];
    rs_say('dry-run: 통과. plan_hash ' . substr($hash, 0, 16) . '…');
    foreach ($existing as $id => $r) {
        $drift = rs_spec_drift($r, $targets[$id]);
        if ($drift !== []) {
            rs_say('경고: ' . $id . ' 의 현재 값이 규격 16.2 와 다르다(--rotate 는 secret 만 바꾼다): ' . implode(', ', $drift));
        }
    }
    if ($opt['dry']) {
        rs_say('--dry-run: DB·파일을 쓰지 않았다.');
        return RS_EXIT_OK;
    }

    // ---- 3) handoff 를 .pending 으로 먼저 쓴다 --------------------------------
    $now = gmdate('Y-m-d\TH:i:s\Z');
    $pending = [];
    foreach ($ids as $id) {
        $t = $targets[$id];
        $isConf = isset($existing[$id]) ? ((int) $existing[$id]['is_confidential'] === 1) : true;
        $doc = [
            'format'             => RS_HANDOFF_FORMAT,
            'service_id'         => $id,
            'service_secret'     => $secrets[$id],
            'is_confidential'    => $isConf,
            'public_url'         => $t['public'],
            'internal_url'       => $t['internal'],
            'redirect_whitelist' => [$t['public'] . RS_CALLBACK_PATH, $t['internal'] . RS_CALLBACK_PATH],
            'ulogin'             => [
                'public_url'            => RS_ULOGIN_PUBLIC,
                'internal_url'          => RS_ULOGIN_INTERNAL,
                'exchange_url_internal' => RS_ULOGIN_INTERNAL . RS_EXCHANGE_PATH,
                'exchange_url_public'   => RS_ULOGIN_PUBLIC . RS_EXCHANGE_PATH,
            ],
            'issued_at'          => $now,
            'rotated'            => isset($existing[$id]),
            'instructions'       => '이 파일을 읽어 자기 시스템의 로컬 설정(서버만 읽는 곳)에 넣은 뒤 즉시 지운다(연동 규격 16.2). '
                . 'service_secret 을 대화·문서·로그·커밋에 적지 않는다. 브라우저로 내려보내지 않는다.',
        ];
        $json = json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $path = $handoffDir . '/' . $id . '.json.pending';
        $p = rs_write_pending($path, $json, (string) $sid);
        if ($p !== null) {
            rs_cleanup($pending);
            rs_die(RS_EXIT_PRECOND, 'handoff 준비 실패 ' . $id . ': ' . $p . ' — 등록하지 않았다.');
        }
        $pending[$id] = $path;
    }
    rs_say('handoff 준비: ' . (string) count($pending) . '개 (.pending, ACL 확인됨)');

    // ---- 4) apply (트랜잭션 · 잠근 읽기 위 재계획 · expect_plan_hash) ----------
    // 아래 6개 변수는 번들 파일의 `$applyFn` 이 `use` 로 받는 이름이다(RS_APPLY_USE).
    $errs = [];
    $warn = [];
    $norm      = sb_validate($bundle, $errs, $warn, false);
    if ($errs !== []) {
        rs_cleanup($pending);
        rs_report_errs('apply', $errs, $warn);
        rs_die(RS_EXIT_REFUSED, 'apply 직전 검증이 거절했다. 아무것도 바꾸지 않았다.');
    }
    $expect    = $hash;
    $reason    = 'cli tools/register_services.php';
    $planErrs  = [];
    $applyWarn = $warn;
    $applyFn   = null;
    eval("declare(strict_types=1);\n" . $ex['apply']);
    if (!($applyFn instanceof Closure)) {
        rs_cleanup($pending);
        rs_die(RS_EXIT_BROKEN, '$applyFn 을 만들지 못했다.');
    }
    try {
        $applied = db_tx($applyFn);
    } catch (AppError $e) {
        rs_cleanup($pending);
        $x = is_array($e->extra) ? $e->extra : [];
        if (isset($x['errors']) && is_array($x['errors'])) {
            rs_report_errs('apply', $x['errors'], []);
        }
        rs_die(RS_EXIT_REFUSED, 'apply 가 거절했다: ' . (string) $e->code . ' — ' . (string) ($x['message'] ?? '')
            . ' (트랜잭션이 되돌렸다. 아무것도 바꾸지 않았다)');
    } catch (Throwable $e) {
        rs_cleanup($pending);
        if (sb_is_race_error($e)) {
            rs_die(RS_EXIT_REFUSED, '동시에 적용된 변경과 충돌했다. 아무것도 바꾸지 않았다. 다시 실행하라.');
        }
        rs_die(RS_EXIT_BROKEN, 'apply 중 예외: ' . get_class($e) . ' — ' . $e->getMessage() . ' (트랜잭션이 되돌렸다)');
    }
    $log = isset($applied['admin_log_id']) ? (string) $applied['admin_log_id'] : 'null';
    rs_say('apply: 반영됨. 감사 로그 #' . $log);

    // ---- 5) 사후 대조 — 실패하면 .pending 을 남기고 6 ----------------------------
    $problems = rs_verify_db($targets, $existing, $secrets, $applied, $ph, $ids, $roleOvBefore);
    if ($problems !== []) {
        foreach ($problems as $p) {
            rs_err('  대조 실패: ' . $p);
        }
        rs_die(RS_EXIT_PARTIAL, 'DB 에는 반영됐는데 사후 대조가 실패했다. 시크릿은 ' . $handoffDir
            . '/<service_id>.json.pending 에 있다 — 지우지 마라.');
    }
    rs_say('사후 대조: 통과 (secret 해시 검증 · 역할 오버라이드 증가 0'
        . (count($existing) < count($ids) ? ' · 신규분 confidential · 복귀 주소 2개 완전 일치 · origins 0 · 시크릿 0' : '') . ')');

    // ---- 6) 확정 — .pending → .json, 이름을 바꾼 뒤 ACL 다시 재기 -----------------
    $finalFail = [];
    foreach ($pending as $id => $path) {
        $final = $handoffDir . '/' . $id . '.json';
        if (!@rename($path, $final)) {
            $finalFail[] = $id . ': 이름 바꾸기 실패';
            continue;
        }
        $p = rs_acl_problem($final, (string) $sid);
        if ($p !== null) {
            $finalFail[] = $id . ': 확정 뒤 ACL 판정 실패 — ' . $p;
        }
    }
    foreach ($ids as $id) {
        $final = $handoffDir . '/' . $id . '.json';
        $ok = is_file($final) && !in_array($id, array_map(function ($s) { return explode(':', $s)[0]; }, $finalFail), true);
        rs_say(sprintf('  %-8s %s  %s', $id, $ok ? 'OK  ' : 'FAIL', $ok ? $final : ''));
    }
    if ($finalFail !== []) {
        foreach ($finalFail as $f) {
            rs_err('  ' . $f);
        }
        rs_die(RS_EXIT_PARTIAL, 'DB 에는 반영됐는데 handoff 확정이 실패했다. 위 파일을 지우지 말고 확인하라.');
    }
    rs_say('완료: 서비스 ' . (string) count($ids) . '개 ' . ($opt['rotate'] ? '등록/재발급' : '등록') . ' · handoff ' . (string) count($ids)
        . '개 → ' . $handoffDir);
    return RS_EXIT_OK;
}

/** 검증 오류·경고를 찍는다. 시크릿 자리는 번들 쪽이 이미 `*** (N chars)` 로 가렸고 rs_say 가 한 번 더 가린다. */
function rs_report_errs(string $stage, array $errs, array $warn): void
{
    foreach ($errs as $e) {
        rs_say('  [' . $stage . '] 오류 ' . (string) ($e['path'] ?? '?') . ' ' . (string) ($e['code'] ?? '?')
            . ' — ' . (string) ($e['message'] ?? ''));
    }
    foreach ($warn as $w) {
        rs_say('  [' . $stage . '] 경고 ' . (string) ($w['path'] ?? '?') . ' ' . (string) ($w['code'] ?? '?')
            . ' — ' . (string) ($w['message'] ?? ''));
    }
}

/** 계획이 이 도구의 의도와 정확히 같은가. 다르면 사유. */
function rs_plan_mismatch(array $built, array $expectOp): ?string
{
    $c = $built['counts'];
    foreach (['settings_update', 'secrets_create', 'secrets_update', 'deletes', 'services_skipped'] as $k) {
        if ((int) ($c[$k] ?? -1) !== 0) {
            return $k . ' = ' . (string) ($c[$k] ?? 'null') . ' (0 이어야 한다)';
        }
    }
    if (!empty($built['plan']['deletes_possible'])) {
        return 'deletes_possible = true';
    }
    $seen = [];
    foreach ($built['plan']['services'] as $p) {
        $id = (string) $p['service_id'];
        $seen[] = $id;
        if (!isset($expectOp[$id])) {
            return '계획에 대상 밖 서비스: ' . $id;
        }
        if ((string) $p['op'] !== $expectOp[$id]) {
            return $id . ' 의 op 가 ' . (string) $p['op'] . ' (기대 ' . $expectOp[$id] . ')';
        }
        if ($expectOp[$id] === 'update' && $p['changed_fields'] !== ['service_secret']) {
            return $id . ' 의 변경 필드가 service_secret 하나가 아니다: ' . implode(',', $p['changed_fields']);
        }
        if ($p['secrets'] !== []) {
            return $id . ' 에 시크릿 계획이 있다';
        }
    }
    sort($seen);
    $want = array_keys($expectOp);
    sort($want);
    return $seen === $want ? null : '계획의 서비스 목록이 대상과 다르다';
}

/** 기존 행이 규격 16.2 와 다른 필드 이름(값은 찍지 않는다 — 비밀은 아니지만 짧게). */
function rs_spec_drift(array $row, array $t): array
{
    $d = [];
    if ((int) $row['is_confidential'] !== 1) {
        $d[] = 'is_confidential';
    }
    if ((string) $row['status'] !== 'active') {
        $d[] = 'status';
    }
    $wl = json_decode((string) $row['redirect_whitelist'], true);
    if ($wl !== [$t['public'] . RS_CALLBACK_PATH, $t['internal'] . RS_CALLBACK_PATH]) {
        $d[] = 'redirect_whitelist';
    }
    $ao = $row['allowed_origins'] === null ? [] : json_decode((string) $row['allowed_origins'], true);
    if ($ao !== []) {
        $d[] = 'allowed_origins';
    }
    return $d;
}

/** 적용 결과를 DB 에서 다시 읽어 대조한다. @return string[] 문제 목록 */
function rs_verify_db(array $targets, array $existing, array $secrets, $applied, string $ph, array $ids, int $roleOvBefore): array
{
    $out = [];
    $res = is_array($applied) && isset($applied['result']['services']) ? $applied['result']['services'] : null;
    if ($res === null) {
        return ['apply 결과 모양이 예상과 다르다'];
    }
    $wantCreated = [];
    $wantUpdated = [];
    foreach ($targets as $id => $t) {
        if (isset($existing[$id])) {
            $wantUpdated[] = $id;
        } else {
            $wantCreated[] = $id;
        }
    }
    $gotC = $res['created'];
    $gotU = $res['updated'];
    sort($gotC);
    sort($gotU);
    sort($wantCreated);
    sort($wantUpdated);
    if ($gotC !== $wantCreated || $gotU !== $wantUpdated) {
        $out[] = 'apply 결과의 created/updated 목록이 대상과 다르다';
    }
    foreach ($targets as $id => $t) {
        $r = db_one('SELECT * FROM ' . T_SERVICES . ' WHERE service_id = ?', [$id]);
        if ($r === null) {
            $out[] = $id . ': 행이 없다';
            continue;
        }
        $h = (string) ($r['service_secret_hash'] ?? '');
        if ($h === '' || !password_verify($secrets[$id], $h)) {
            $out[] = $id . ': 저장된 secret 해시가 handoff 값과 맞지 않는다';
        }
        if (isset($existing[$id])) {
            continue;                       // --rotate 는 secret 만 바꿨다
        }
        if ((int) $r['is_confidential'] !== 1) {
            $out[] = $id . ': is_confidential 이 1 이 아니다';
        }
        if ((string) $r['status'] !== 'active') {
            $out[] = $id . ': status 가 active 가 아니다';
        }
        if (json_decode((string) $r['redirect_whitelist'], true) !== [$t['public'] . RS_CALLBACK_PATH, $t['internal'] . RS_CALLBACK_PATH]) {
            $out[] = $id . ': redirect_whitelist 가 두 주소와 완전히 같지 않다';
        }
        if (($r['allowed_origins'] === null ? [] : json_decode((string) $r['allowed_origins'], true)) !== []) {
            $out[] = $id . ': allowed_origins 가 비어 있지 않다';
        }
        if ((int) db_val('SELECT COUNT(*) FROM ' . T_SECRETS . ' WHERE service_id = ?', [$id]) !== 0) {
            $out[] = $id . ': service_secrets 행이 있다';
        }
    }
    $after = (int) db_val('SELECT COUNT(*) FROM ' . T_USER_SERVICES
        . ' WHERE service_id IN (' . $ph . ') AND role IS NOT NULL', $ids);
    if ($after !== $roleOvBefore) {
        $out[] = '역할 오버라이드 행 수가 바뀌었다(' . (string) $roleOvBefore . ' → ' . (string) $after . ')';
    }
    if ($after > 0) {
        rs_say('경고: 대상 서비스에 이 도구가 만들지 않은 역할 오버라이드가 ' . (string) $after
            . '행 있다(D-303 — office·manager 의 admin 오버라이드는 v1.9.0 전까지 접근 우회로다).');
    }
    return $out;
}

/** 준비한 .pending 을 지운다 — DB 에 반영되지 않은 시크릿이다. */
function rs_cleanup(array $pending): void
{
    foreach ($pending as $path) {
        @unlink($path);
    }
}

/** DB 를 열고 감사 주체(활성 superadmin)를 정한다. 실패는 종료코드 3. */
function rs_open_db_pick_admin(array $opt): int
{
    try {
        $drv = db_driver();
        db();
        $superRows = db_all('SELECT id, username FROM ' . T_USERS
            . " WHERE role = 'superadmin' AND status = 'active' ORDER BY id ASC");
    } catch (Throwable $e) {
        rs_die(RS_EXIT_PRECOND, 'DB 를 열거나 읽지 못했다(' . get_class($e) . '). 설치가 끝났는지, '
            . 'php -c 로 pdo_sqlite 가 켜진 ini 를 줬는지 확인하라.');
    }
    rs_say('저장소: ' . $drv);

    // admin_logs 에는 「시스템/CLI」 주체가 없다. 번들 apply 는 adminId 를 요구한다 —
    // 사람(설치한 최고관리자)의 id 로 남기고 memo 에 CLI 경로를 적는다(리포트 「막힌 지점」).
    $adminId = 0;
    if ($opt['admin'] !== null) {
        foreach ($superRows as $r) {
            if (strtolower((string) $r['username']) === strtolower($opt['admin'])) {
                $adminId = (int) $r['id'];
            }
        }
        if ($adminId === 0) {
            rs_die(RS_EXIT_PRECOND, '--admin= 에 해당하는 활성 superadmin 이 없다.');
        }
    } elseif (count($superRows) === 1) {
        $adminId = (int) $superRows[0]['id'];
    } elseif ($superRows === []) {
        rs_die(RS_EXIT_PRECOND, '활성 superadmin 이 없다 — 설치기에서 관리자 계정을 만든 뒤 실행하라.');
    } else {
        rs_die(RS_EXIT_PRECOND, '활성 superadmin 이 ' . (string) count($superRows) . '명이다 — --admin=<아이디> 로 정하라.');
    }
    rs_say('감사 주체: superadmin #' . (string) $adminId);
    return $adminId;
}

// =====================================================================
// v1.8.6 — 서비스 id 개명 (설계 104 E절 · C-3 · D-341)
// =====================================================================

/** handoff 폴더 — 등록 경로와 같은 규칙(기본 = 프로젝트 루트의 runtime/handoff, --handoff-dir 은 이미 있는 폴더). */
function rs_handoff_dir(array $opt): string
{
    if ($opt['handoff'] !== null) {
        $d = rtrim(str_replace('\\', '/', $opt['handoff']), '/');
        if (!is_dir($d)) {
            rs_die(RS_EXIT_PRECOND, '--handoff-dir 이 없는 폴더다(오타로 엉뚱한 곳에 만들지 않는다): ' . $d);
        }
    } else {
        $d = dirname(ulogin_install_root()) . '/runtime/handoff';
        if (!is_dir($d) && !@mkdir($d, 0700, true)) {
            rs_die(RS_EXIT_PRECOND, 'handoff 폴더를 만들 수 없다: ' . $d);
        }
    }
    if (!is_writable($d)) {
        rs_die(RS_EXIT_PRECOND, 'handoff 폴더에 쓸 수 없다: ' . $d);
    }
    return $d;
}

/** 현재 사용자 SID(Windows) 또는 'posix'. ACL 을 좁힐 수 없으면 쓰지 않는다. */
function rs_owner_sid(): string
{
    if (!rs_is_windows()) {
        return 'posix';
    }
    $sid = rs_current_sid();
    if ($sid === null) {
        rs_die(RS_EXIT_PRECOND, '현재 사용자 SID 를 읽지 못했다(whoami). ACL 을 좁힐 수 없으므로 쓰지 않는다.');
    }
    return $sid;
}

/** 설치 루트 `public/version.txt` 의 앞머리 판 번호가 1.8.6 이상인가. 옛 코드 위에서 개명하면 옛 id 시스템이 전부 멈춘다. */
function rs_rename_version_ok(string $root, ?string &$seen): bool
{
    $raw = @file_get_contents($root . '/public/version.txt');
    $seen = is_string($raw) ? trim($raw) : '(없음)';
    if (!is_string($raw) || preg_match('/^(\d+)\.(\d+)\.(\d+)/', trim($raw), $m) !== 1) {
        return false;
    }
    return version_compare($m[1] . '.' . $m[2] . '.' . $m[3], '1.8.6', '>=');
}

/**
 * SQLite 사본 — DB 와 같은 폴더에 `VACUUM INTO`(D-264). 순서: 빈 파일 배타 생성 → ACL 좁힘 → 판정 →
 * VACUUM INTO(빈 파일은 받는다) → 재판정. 쓰고 나서 좁히면 그 사이 상속 ACL 로 읽힐 수 있다.
 */
function rs_rename_backup_sqlite(string $sid): string
{
    $db  = ulogin_sqlite_path_resolve(defined('DB_SQLITE_PATH') ? DB_SQLITE_PATH : '');
    $out = str_replace('\\', '/', dirname($db)) . '/ulogin_pre_rename_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.sqlite';
    $h = @fopen($out, 'x');
    if ($h === false) {
        rs_die(RS_EXIT_PRECOND, 'DB 사본 파일을 만들 수 없다: ' . $out);
    }
    fclose($h);
    if (!rs_acl_restrict($out, $sid) || ($p = rs_acl_problem($out, $sid)) !== null) {
        @unlink($out);
        rs_die(RS_EXIT_PRECOND, 'DB 사본의 ACL 을 좁히지 못했다. 개명하지 않았다.');
    }
    try {
        db()->exec('VACUUM INTO ' . db()->quote($out));
    } catch (Throwable $e) {
        @unlink($out);
        rs_die(RS_EXIT_PRECOND, 'VACUUM INTO 실패(' . get_class($e) . '). 개명하지 않았다.');
    }
    clearstatcache(true, $out);
    $p = rs_acl_problem($out, $sid);
    if ($p !== null || (int) @filesize($out) <= 0) {
        rs_die(RS_EXIT_PRECOND, 'DB 사본 확인 실패(' . (string) $p . '): ' . $out . ' — 개명하지 않았다.');
    }
    return $out;
}

/** 개명 handoff 본문 (C-3). **service_secret 키가 없다** — ulogin 은 해시만 가져 같은 secret 을 다시 낼 수 없다. */
function rs_rename_handoff_doc(array $row): array
{
    $to   = (string) $row['service_id'];
    $from = (string) $row['former_service_id'];
    $pub = '';
    $int = '';
    foreach (RS_SERVICES as $s) {
        if ($s[0] === $to) {
            $pub = $s[2];
            $int = $s[3];
        }
    }
    $wl = json_decode((string) $row['redirect_whitelist'], true);
    return [
        'format'             => RS_RENAME_FORMAT,
        'service_id'         => $to,
        'former_service_id'  => $from,
        'secret_unchanged'   => true,
        'public_url'         => $pub,
        'internal_url'       => $int,
        'redirect_whitelist' => is_array($wl) ? array_values($wl) : [],
        'ulogin'             => [
            'public_url'            => RS_ULOGIN_PUBLIC,
            'internal_url'          => RS_ULOGIN_INTERNAL,
            'exchange_url_internal' => RS_ULOGIN_INTERNAL . RS_EXCHANGE_PATH,
            'exchange_url_public'   => RS_ULOGIN_PUBLIC . RS_EXCHANGE_PATH,
        ],
        'issued_at'          => gmdate('Y-m-d\TH:i:s\Z'),
        'instructions'       => '서비스 id 가 ' . $from . ' 에서 ' . $to . ' 로 바뀌었습니다. 자기 시스템 SSO 설정의 serviceId 만 '
            . $to . ' 로 바꾸고 이 파일을 지웁니다. **secret 은 그대로입니다 — secret 칸을 비우거나 덮지 않습니다.** '
            . '바꾸기 전에도 ulogin 은 옛 id 를 받습니다(전환 기간). 설정을 시작 때만 읽는 시스템은 재기동합니다.',
    ];
}

/** 개명 handoff 1개를 쓴다(.pending → ACL → .json). 기존 쓰기 경로(rs_write_pending)를 그대로 쓴다. */
function rs_rename_handoff_write(string $dir, string $sid, array $row): ?string
{
    $to   = (string) $row['service_id'];
    $json = json_encode(rs_rename_handoff_doc($row), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    $pend = $dir . '/' . $to . '.json.pending';
    $p = rs_write_pending($pend, $json, $sid);
    if ($p !== null) {
        return $p;
    }
    $final = $dir . '/' . $to . '.json';
    if (!@rename($pend, $final)) {
        return '이름 바꾸기 실패';
    }
    $p = rs_acl_problem($final, $sid);
    return $p === null ? null : '확정 뒤 ACL 판정 실패 — ' . $p;
}

/** 이미 있는 handoff 를 덮어쓰지 않는다 — 소비되지 않은 handoff 다. */
function rs_rename_handoff_free(string $dir, array $ids): void
{
    foreach ($ids as $id) {
        foreach (['.json.pending', '.json'] as $ext) {
            if (file_exists($dir . '/' . $id . $ext)) {
                rs_die(RS_EXIT_PRECOND, '이미 있는 파일을 덮어쓰지 않는다: ' . $dir . '/' . $id . $ext
                    . ' — 받는 시스템이 읽었는지 확인하라. DB 는 바꾸지 않았다.');
            }
        }
    }
}

/** 개명 쌍 하나의 사전 판정(쓰기 없음). 진행할 수 있으면 null, 이미 개명됐으면 'already', 아니면 사유. */
function rs_rename_precheck(string $from, string $to): ?string
{
    $f = db_one('SELECT * FROM ' . T_SERVICES . ' WHERE service_id = ?', [$from]);
    $t = db_one('SELECT * FROM ' . T_SERVICES . ' WHERE service_id = ?', [$to]);
    if ($f === null && $t !== null && (string) ($t['former_service_id'] ?? '') === $from) {
        return 'already';
    }
    if ($f === null) {
        return '개명할 서비스가 없다: ' . $from;
    }
    if ($t !== null) {
        return '두 id 가 모두 서비스로 있다: ' . $from . ' · ' . $to;
    }
    if (service_id_taken($to) !== null) {
        return $to . ' 는 다른 서비스의 옛 id 다';
    }
    if ((string) ($f['former_service_id'] ?? '') !== '') {
        return $from . ' 은 이미 개명된 서비스다(연쇄 개명 금지)';
    }
    $orph = [];
    foreach (service_rename_counts($to) as $tb => $n) {
        if ($n > 0) {
            $orph[] = substr($tb, strlen(DB_PREFIX)) . '=' . $n;
        }
    }
    if ($orph !== []) {
        return $to . ' 를 이미 쓰는 고아 행: ' . implode(', ', $orph) . ' (합치지 않는다)';
    }
    return null;
}

function rs_rename_main(array $opt, string $root): int
{
    $adminId = rs_open_db_pick_admin($opt);
    try {
        service_rename_preflight();
    } catch (ServiceRenameError $e) {
        rs_die(RS_EXIT_PRECOND, $e->getMessage());
    }

    // ---- 이미 개명된 서비스의 handoff 만 (DB 무변경) --------------------------
    if ($opt['mode'] === 'rename_handoff') {
        $rows = [];
        foreach ($opt['target'] as $id) {
            $r = db_one('SELECT * FROM ' . T_SERVICES . ' WHERE service_id = ?', [$id]);
            if ($r === null || (string) ($r['former_service_id'] ?? '') === '') {
                rs_die(RS_EXIT_REFUSED, $id . ' 는 개명된 서비스가 아니다. 아무것도 쓰지 않았다.');
            }
            $rows[$id] = $r;
        }
        if ($opt['dry']) {
            rs_say('--dry-run: handoff ' . implode(', ', array_keys($rows)) . ' 를 쓸 수 있다. 쓰지 않았다.');
            return RS_EXIT_OK;
        }
        $dir = rs_handoff_dir($opt);
        rs_rename_handoff_free($dir, array_keys($rows));
        $sid = rs_owner_sid();
        foreach ($rows as $id => $r) {
            $p = rs_rename_handoff_write($dir, $sid, $r);
            if ($p !== null) {
                rs_die(RS_EXIT_PARTIAL, 'handoff 쓰기 실패 ' . $id . ': ' . $p);
            }
            rs_say(sprintf('  %-9s handoff OK  %s', $id, $dir . '/' . $id . '.json'));
        }
        return RS_EXIT_OK;
    }

    // ---- 별칭 종료 -----------------------------------------------------------
    if ($opt['mode'] === 'end_alias') {
        $id = $opt['target'][0];
        $r = db_one('SELECT * FROM ' . T_SERVICES . ' WHERE service_id = ?', [$id]);
        if ($r === null || (string) ($r['former_service_id'] ?? '') === '') {
            rs_die(RS_EXIT_REFUSED, $id . ' 는 별칭이 있는 서비스가 아니다. 아무것도 바꾸지 않았다.');
        }
        rs_say($id . ' 의 옛 id ' . (string) $r['former_service_id'] . ' · 마지막 인증 사용 '
            . (string) ($r['former_last_used_at'] ?? '(없음)') . ' · 종료 ' . (string) ($r['former_until'] ?? '(아직)'));
        if ($opt['dry']) {
            rs_say('--dry-run: 바꾸지 않았다.');
            return RS_EXIT_OK;
        }
        try {
            $res = service_alias_end($id, $adminId);
        } catch (ServiceRenameError $e) {
            rs_die(RS_EXIT_REFUSED, $e->getMessage());
        }
        rs_say($res['status'] === 'already' ? '이미 별칭이 끝났다. 바꾸지 않았다.'
            : '별칭 종료: ' . (string) $res['former_service_id'] . ' 는 이제 해석되지 않는다(재사용 금지는 계속). 감사 #' . (string) $res['audit_id']);
        return RS_EXIT_OK;
    }

    // ---- 개명 · 되돌리기 공통 전제 ------------------------------------------------
    $seen = null;
    if (!rs_rename_version_ok($root, $seen)) {
        rs_die(RS_EXIT_PRECOND, '설치 루트 판이 1.8.6 이상이 아니다(public/version.txt = ' . (string) $seen
            . '). 옛 코드는 별칭을 풀지 못해 옛 id 시스템이 전부 멈춘다. 접합 전환·재기동 뒤에 실행하라.');
    }

    if ($opt['mode'] === 'revert') {
        $id = $opt['target'][0];
        $r = db_one('SELECT * FROM ' . T_SERVICES . ' WHERE service_id = ?', [$id]);
        if ($r === null || (string) ($r['former_service_id'] ?? '') === '') {
            rs_die(RS_EXIT_REFUSED, '되돌릴 개명 기록이 없다: ' . $id . '. 아무것도 바꾸지 않았다.');
        }
        $from = (string) $r['former_service_id'];
        $orph = [];
        foreach (service_rename_counts($from) as $tb => $n) {
            if ($n > 0) {
                $orph[] = substr($tb, strlen(DB_PREFIX)) . '=' . $n;
            }
        }
        if ($orph !== []) {
            rs_die(RS_EXIT_REFUSED, $from . ' 를 쓰는 행이 이미 있다: ' . implode(', ', $orph) . ' — 되돌리지 않았다.');
        }
        rs_say('되돌리기 계획: ' . $id . ' → ' . $from);
        if ($opt['dry']) {
            rs_say('--dry-run: 바꾸지 않았다.');
            return RS_EXIT_OK;
        }
        rs_rename_backup_guard($opt);
        try {
            $res = service_rename_revert($id, $adminId);
        } catch (ServiceRenameError $e) {
            rs_die(RS_EXIT_REFUSED, $e->getMessage() . ' (트랜잭션이 되돌렸다)');
        }
        rs_say('되돌림: ' . $id . ' → ' . $from . ' · 감사 #' . (string) $res['audit_id'] . ' · ' . rs_counts_line($res['counts']));
        return RS_EXIT_OK;
    }

    // ---- 개명 — 쌍 전부를 먼저 판정하고, 하나라도 막히면 아무것도 하지 않는다 --------------
    $todo = [];
    $bad  = [];
    foreach ($opt['pairs'] as $pr) {
        $why = rs_rename_precheck($pr[0], $pr[1]);
        if ($why === 'already') {
            rs_say(sprintf('  %-5s → %-9s 이미 개명됨 — 건너뛴다', $pr[0], $pr[1]));
        } elseif ($why === null) {
            $todo[] = $pr;
            rs_say(sprintf('  %-5s → %-9s 진행 · %s', $pr[0], $pr[1], rs_counts_line(rs_short_counts(service_rename_counts($pr[0])))));
        } else {
            $bad[] = $pr[0] . ':' . $pr[1] . ' — ' . $why;
        }
    }
    if ($bad !== []) {
        foreach ($bad as $b) {
            rs_err('  거절 ' . $b);
        }
        rs_die(RS_EXIT_REFUSED, '개명 판정이 거절됐다. 아무것도 바꾸지 않았다.');
    }
    if ($todo === []) {
        rs_say('할 일이 없다(전부 이미 개명됨). 아무것도 바꾸지 않았다.');
        return RS_EXIT_OK;
    }
    if ($opt['dry']) {
        rs_say('--dry-run: DB·파일을 쓰지 않았다. handoff ' . ($opt['with_handoff'] ? '함께 씀' : '쓰지 않음(D-341 H-2)'));
        return RS_EXIT_OK;
    }
    $dir = null;
    $sid = null;
    if ($opt['with_handoff']) {
        $dir = rs_handoff_dir($opt);
        $ids = [];
        foreach ($todo as $pr) {
            $ids[] = $pr[1];
        }
        rs_rename_handoff_free($dir, $ids);
        $sid = rs_owner_sid();
    }
    rs_rename_backup_guard($opt);

    $done = [];
    foreach ($todo as $pr) {
        try {
            $res = service_rename($pr[0], $pr[1], $adminId);
        } catch (ServiceRenameError $e) {
            if (isset($e->detail['orphans'])) {
                rs_err('  고아 행: ' . rs_counts_line(rs_short_counts($e->detail['orphans'])));
            }
            rs_die($done === [] ? RS_EXIT_REFUSED : RS_EXIT_PARTIAL, $pr[0] . ':' . $pr[1] . ' 거절 — ' . $e->getMessage()
                . ' (이 쌍은 트랜잭션이 되돌렸다' . ($done === [] ? ')' : '. 먼저 끝난 개명: ' . implode(', ', $done) . ')'));
        } catch (Throwable $e) {
            rs_die($done === [] ? RS_EXIT_BROKEN : RS_EXIT_PARTIAL, $pr[0] . ':' . $pr[1] . ' 중 예외 ' . get_class($e) . ' — ' . $e->getMessage()
                . ' (이 쌍은 트랜잭션이 되돌렸다' . ($done === [] ? ')' : '. 먼저 끝난 개명: ' . implode(', ', $done) . ')'));
        }
        $done[] = $pr[0] . ':' . $pr[1];
        rs_say(sprintf('  %-5s → %-9s %s · 감사 #%s · %s', $pr[0], $pr[1], $res['status'], (string) $res['audit_id'],
            rs_counts_line($res['counts'])));
        if ($dir !== null) {
            $row = db_one('SELECT * FROM ' . T_SERVICES . ' WHERE service_id = ?', [$pr[1]]);
            $p = $row === null ? '행이 없다' : rs_rename_handoff_write($dir, (string) $sid, $row);
            if ($p !== null) {
                rs_die(RS_EXIT_PARTIAL, 'DB 는 개명됐는데 handoff 가 실패했다 ' . $pr[1] . ': ' . $p
                    . ' — 나중에 --rename-handoff=' . $pr[1] . ' 로 다시 쓴다.');
            }
        }
    }
    rs_say('완료: 개명 ' . (string) count($done) . '건' . ($dir !== null ? ' · handoff → ' . $dir : ' · handoff 는 쓰지 않았다(필요하면 --rename-handoff=)'));
    return RS_EXIT_OK;
}

/** 쓰기 전 사본 — SQLite 는 도구가 뜨고, MySQL 은 운영자가 먼저 뜬다(--i-have-backup). */
function rs_rename_backup_guard(array $opt): void
{
    if (db_driver() === 'sqlite') {
        $b = rs_rename_backup_sqlite(rs_owner_sid());
        rs_say('DB 사본: ' . $b . ' (ACL 확인됨)');
        return;
    }
    if (!$opt['backup_ok']) {
        rs_die(RS_EXIT_PRECOND, 'MySQL 저장소다. mysqldump 로 사본을 먼저 뜬 뒤 --i-have-backup 을 붙여 다시 실행하라. 바꾸지 않았다.');
    }
    rs_say('MySQL: 운영자 사본 확인(--i-have-backup)');
}

function rs_short_counts(array $c): array
{
    $o = [];
    foreach ($c as $k => $v) {
        $o[strpos((string) $k, DB_PREFIX) === 0 ? substr((string) $k, strlen(DB_PREFIX)) : (string) $k] = $v;
    }
    return $o;
}

function rs_counts_line(array $c): string
{
    $o = [];
    foreach ($c as $k => $v) {
        if ((int) $v > 0) {
            $o[] = $k . '=' . (string) $v;
        }
    }
    return $o === [] ? '행 0' : implode(' ', $o);
}

exit(rs_main(isset($argv) && is_array($argv) ? $argv : []));
