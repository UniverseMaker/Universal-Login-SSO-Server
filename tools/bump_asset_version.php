<?php
/**
 * bump_asset_version.php — 자산 URL 버전 쿼리(`?v=`) 일괄 치환
 * ---------------------------------------------------------------------
 * 리더 판정 D-84: "버전을 손으로 넣으면 다음 릴리스에서 반드시 일부가 빠진다.
 *                 일괄 치환 스크립트를 두고 릴리스 절차에 넣는다. 스크립트는
 *                 치환 전후 개수를 출력해 누락을 즉시 드러내야 한다."
 *
 * 참조가 몇 곳인지 이 주석에 적지 않는다 — 실행할 때마다 이 스크립트가 직접 센다.
 * 주석에 박아 둔 숫자는 다음 릴리스에서 조용히 거짓이 된다.
 *
 * **배포물이 아니다.** 저장소의 `tools/` 에 들어 있지만 **서버에 올리지 마라.**
 * 릴리스를 준비하는 사람이 자기 PC 에서 돌리는 도구다. 업로드 대상은
 * `public/` · `server/` · `examples/` 이고 `tools/` 는 아니다.
 *
 * ─ 무엇을 고치는가 ───────────────────────────────────────────────────
 *   {설치루트}/public/*.html      의 assets/*.{css,js} 참조
 *   {설치루트}/examples/*.html    의 ../public/assets/*.js 참조
 *   → `href="assets/style.css?v=1.2.0"` 의 `?v=` 값만 바꾼다.
 *   대상 파일이 몇 개인지도 여기 적지 않는다 — 실행할 때마다 직접 센다.
 *
 * ─ 무엇을 고치지 않는가 (중요) ───────────────────────────────────────
 *   - `public/assets/ulogin-client.js` 안의 `version: '1.0.0'` 은 **건드리지
 *     않는다.** 그것은 연동 클라이언트의 공개 API 버전이고(리더 판정 D-72),
 *     자산 URL 의 `?v=` 와는 완전히 별개다. 이 스크립트는 *.html 만 연다.
 *   - `public/version.txt` — 형식이 다르다(`1.2.0_20260828`). `--version-txt=`
 *     로 명시할 때만 쓴다. 안 주면 끝에 갱신하라고 알려준다.
 *   - server/**, README/INSTALL/CHANGELOG 등 문서는 대상이 아니다.
 *
 * ─ 사용법 ────────────────────────────────────────────────────────────
 *   php tools/bump_asset_version.php <새버전> [--dir=<설치루트>] [옵션]
 *
 *   옵션:
 *     --dir=<경로>            대상 설치 루트 = `public/` 을 가진 폴더.
 *                             생략하면 **이 스크립트의 상위 폴더**를 쓴다.
 *                             저장소를 그대로 받은 표준 배치에서는 그것이
 *                             설치 루트다(`tools/` 의 부모).
 *                             상위에 `public/` 이 없으면, 상위를 여러 릴리스
 *                             폴더를 담은 작업 루트로 보고 그 안의 가장 최근
 *                             `v{major}.{minor}.{patch}_{YYYYMMDD}` 폴더를 고른다.
 *     --from=<현재버전>       치환 대상 값을 이것으로 한정한다. 생략하면
 *                             파일에서 발견된 모든 `?v=` 값을 대상으로 한다.
 *     --version-txt=<문자열>  public/version.txt 를 이 내용으로 덮어쓴다.
 *                             (예: --version-txt=1.3.0_20260901)
 *     --dry-run               파일을 쓰지 않고 결과만 출력한다.
 *                             (판정·종료코드는 --dry-run 에서도 같다)
 *
 *   예 (저장소 루트에서):
 *     php tools/bump_asset_version.php 1.3.0 --dry-run
 *     php tools/bump_asset_version.php 1.3.0 --version-txt=1.3.0_20260901
 *     php tools/bump_asset_version.php 1.3.0 --from=1.2.0 --dir=/path/to/ulogin
 *
 * ─ 종료코드 ──────────────────────────────────────────────────────────
 *   0 = 정상. 갱신되지 않은 버전 표기가 없다
 *   1 = **산출물에 갱신되지 않은 버전 표기가 남아 있다.** 두 가지다 —
 *       (a) 자산 참조에 `?v=` 가 없다
 *       (b) 화면 푸터에 버전 문자열이 손으로 박혀 있다 (리더 판정 D-99)
 *       → 출력이 파일·문자열을 지목한다. 고친 뒤 다시 실행하라
 *   2 = 사용법 오류 / 파일을 읽거나 쓸 수 없음
 *   3 = **치환할 자리를 하나도 갱신하지 못했다.** `--from` 값이 파일의 현재
 *       값과 달라 전부 건너뛴 경우다(QA Q12-3). 산출물은 깨지지 않았다 —
 *       틀린 것은 명령이다. `--from` 값을 확인하고 다시 실행하라
 *
 *   1 과 3 의 구분이 중요하다. 1 은 **파일**을 고쳐야 하고, 3 은 **명령**을
 *   고쳐야 한다. 조치가 다르므로 같은 코드로 합치지 않는다.
 *   1 과 3 이 동시에 성립하면 1 을 낸다(산출물 결함이 더 급하다).
 *
 * PHP 7.2 호환. 같은 폴더의 `php72_scan.php` 로 검사한다.
 */

// ---------------------------------------------------------------------
// 0. 인자 파싱
// ---------------------------------------------------------------------

$newVersion = null;
$dir        = null;
$from       = null;
$versionTxt = null;
$dryRun     = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') { $dryRun = true; continue; }
    if (strpos($arg, '--dir=') === 0)         { $dir        = substr($arg, 6);  continue; }
    if (strpos($arg, '--from=') === 0)        { $from       = substr($arg, 7);  continue; }
    if (strpos($arg, '--version-txt=') === 0) { $versionTxt = substr($arg, 14); continue; }
    if (strpos($arg, '--') === 0) {
        fwrite(STDERR, "알 수 없는 옵션: $arg\n");
        exit(2);
    }
    if ($newVersion !== null) {
        fwrite(STDERR, "새 버전은 하나만 지정한다 (이미 '$newVersion' 을 받았다): $arg\n");
        exit(2);
    }
    $newVersion = $arg;
}

function usage_exit($msg)
{
    fwrite(STDERR, $msg . "\n\n");
    fwrite(STDERR, "사용법: php tools/bump_asset_version.php <새버전> [--dir=<설치루트>] [--from=<현재버전>] [--version-txt=<문자열>] [--dry-run]\n");
    fwrite(STDERR, "  --dir 을 생략하면 이 스크립트의 상위 폴더(= 설치 루트)를 대상으로 한다.\n");
    exit(2);
}

if ($newVersion === null) {
    usage_exit('새 버전 문자열이 없다.');
}

// URL 쿼리에 그대로 들어가는 값이다. 이상한 문자가 섞이면 참조가 깨진다.
if (!preg_match('/^[A-Za-z0-9._-]+$/', $newVersion)) {
    usage_exit("새 버전 문자열에 허용되지 않는 문자가 있다: '$newVersion' (허용: 영숫자 . _ -)");
}
if ($from !== null && !preg_match('/^[A-Za-z0-9._-]+$/', $from)) {
    usage_exit("--from 값에 허용되지 않는 문자가 있다: '$from'");
}
if ($versionTxt !== null && !preg_match('/^[A-Za-z0-9._-]+$/', $versionTxt)) {
    usage_exit("--version-txt 값에 허용되지 않는 문자가 있다: '$versionTxt'");
}

// ---------------------------------------------------------------------
// 1. 대상 폴더 결정
// ---------------------------------------------------------------------

$selfDir = str_replace('\\', '/', __DIR__);

if ($dir === null) {
    // 이 스크립트는 설치 루트의 `tools/` 안에 있다. 따라서 기본 대상은
    // **상위 폴더 자신**이다 — 저장소를 그대로 받은 표준 배치에서 저장소
    // 루트가 곧 설치 페이로드다(별도의 버전 폴더가 없다).
    $root = dirname($selfDir);

    if (is_dir($root . '/public')) {
        $dir = $root;
        echo "대상 폴더 자동 선택: $dir  (이 스크립트의 상위 = 설치 루트)\n";
    } else {
        // 상위에 public/ 이 없다 = 여기가 설치 루트가 아니다. 그러면 상위를
        // "여러 릴리스 폴더를 담은 작업 루트"로 보고 최신 릴리스 폴더를 고른다.
        // (릴리스마다 폴더를 새로 만들어 보관하는 작업 방식에서의 배치다)
        $candidates = array();
        $entries    = scandir($root);
        if ($entries === false) {
            fwrite(STDERR, "상위 폴더를 읽을 수 없다: $root\n");
            exit(2);
        }
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') { continue; }
            if (!is_dir($root . '/' . $e)) { continue; }
            if (preg_match('/^v\d+\.\d+\.\d+_\d{8}$/', $e)) { $candidates[] = $e; }
        }
        if (count($candidates) === 0) {
            usage_exit(
                "대상 폴더를 자동으로 찾지 못했다. --dir= 로 설치 루트(`public/` 가 있는 폴더)를 명시하라.\n"
                . "  (상위 폴더에 public/ 도, v{x}.{y}.{z}_{YYYYMMDD} 폴더도 없다. 탐색 위치: $root)"
            );
        }
        // `v{x}.{y}.{z}_{YYYYMMDD}` 는 뒤쪽 날짜가 고정폭이라 문자열 정렬이
        // 대체로 날짜순이 된다. 자릿수가 다른 마이너(v1.9 vs v1.10)에서는
        // 어긋날 수 있으므로, 애매하면 `--dir=` 로 명시하라.
        sort($candidates);
        $dir = $root . '/' . $candidates[count($candidates) - 1];
        echo "대상 폴더 자동 선택: " . $candidates[count($candidates) - 1] . "  (최신 릴리스 폴더)\n";
    }
}

$dir = rtrim(str_replace('\\', '/', $dir), '/');
if (!is_dir($dir)) {
    usage_exit("대상 폴더가 없다: $dir");
}
if (!is_dir($dir . '/public')) {
    usage_exit("대상 폴더에 public/ 이 없다. 버전 폴더가 맞는지 확인하라: $dir");
}

// ---------------------------------------------------------------------
// 2. 대상 파일 수집 — public/*.html + examples/*.html
// ---------------------------------------------------------------------

// glob() 을 쓰지 않는다. glob 은 패턴의 `[...]` 를 문자 클래스로 해석하므로
// 경로에 대괄호가 들어간 설치 위치(예: `.../[archive] my-project/...`)에서
// **조용히 0건을 반환한다.** scandir + 확장자 검사로 대체한다.
$files = array();
foreach (array('public', 'examples') as $sub) {
    $subDir = $dir . '/' . $sub;
    if (!is_dir($subDir)) { continue; }
    $entries = scandir($subDir);
    if ($entries === false) {
        fwrite(STDERR, "디렉토리를 읽을 수 없다: $subDir\n");
        exit(2);
    }
    $found = array();
    foreach ($entries as $e) {
        if (substr($e, -5) !== '.html') { continue; }
        if (!is_file($subDir . '/' . $e)) { continue; }
        $found[] = $subDir . '/' . $e;
    }
    sort($found);
    foreach ($found as $f) { $files[] = $f; }
}

if (count($files) === 0) {
    fwrite(STDERR, "대상 HTML 이 하나도 없다: $dir/{public,examples}/*.html\n");
    exit(2);
}

// ---------------------------------------------------------------------
// 3. 치환
//
//    자산 참조 형태:  src="...js?v=X"  href="...css?v=X"
//    `?v=` 가 없는 참조는 치환하지 않고 **누락으로 보고**한다. 조용히 붙여주면
//    "왜 어떤 파일에는 버전이 없었는지"가 묻히고, 다음 릴리스에 또 반복된다.
// ---------------------------------------------------------------------

$rxVersioned = '/(\b(?:src|href)=")([^"]*\.(?:css|js))\?v=([A-Za-z0-9._-]*)(")/';
$rxBare      = '/\b(?:src|href)="[^"]*\.(?:css|js)"/';

// 화면 푸터에 손으로 박아 둔 버전 문자열 탐지 (리더 판정 D-99).
//   `<footer class="site-footer">ulogin v1.0.0 · …` 가 v1.1.0·v1.2.0 을 지나
//   그대로 남아 있었다. 이 스크립트가 손대지 않는 자리라 조용히 낡는다.
//   해결은 그 값을 지우는 것이었다(버전의 출처는 version.txt 와 자산 URL 의
//   `?v=` 뿐이다). 다시 들어오면 여기서 잡는다.
//   범위를 <footer> 안으로 좁힌 이유 — HTML 본문·주석에는 "v1.0.0 부터
//   payload_fields 는 …" 처럼 **과거 릴리스를 가리키는 정당한 언급**이 여러 곳
//   있다. 그것까지 잡으면 경고가 소음이 되어 아무도 읽지 않는다.
$rxFooterVer = '/<footer\b[^>]*>[^<]*\bv\d+\.\d+\.\d+/i';

$totalRefs    = 0;   // 자산 참조 총 개수 (버전 유무 무관)
$totalChanged = 0;   // 실제로 값이 바뀐 개수
$totalAlready = 0;   // 이미 새 버전이던 개수
$totalSkipped = 0;   // --from 과 달라 건너뛴 개수
$bare         = array(); // 버전 쿼리가 없는 참조 (누락)
$footerVer    = array(); // 푸터에 손으로 박힌 버전 문자열
$rows         = array();
$foundValues  = array();

foreach ($files as $path) {
    $src = file_get_contents($path);
    if ($src === false) {
        fwrite(STDERR, "읽기 실패: $path\n");
        exit(2);
    }

    $fileRefs = 0; $fileChanged = 0; $fileAlready = 0; $fileSkipped = 0;

    // 3-1. 버전 쿼리가 붙은 참조를 치환
    $out = preg_replace_callback(
        $rxVersioned,
        function ($m) use ($newVersion, $from, &$fileRefs, &$fileChanged, &$fileAlready, &$fileSkipped, &$foundValues) {
            $fileRefs++;
            $cur = $m[3];
            $foundValues[$cur] = true;

            if ($from !== null && $cur !== $from) {
                $fileSkipped++;
                return $m[0];
            }
            if ($cur === $newVersion) {
                $fileAlready++;
                return $m[0];
            }
            $fileChanged++;
            return $m[1] . $m[2] . '?v=' . $newVersion . $m[4];
        },
        $src
    );

    if ($out === null) {
        fwrite(STDERR, "정규식 치환 실패: $path\n");
        exit(2);
    }

    // 3-2. 버전 쿼리가 없는 참조 = 누락. 파일별로 기록한다.
    $bareHits = array();
    if (preg_match_all($rxBare, $out, $mm)) {
        $bareHits = $mm[0];
    }
    foreach ($bareHits as $h) {
        $bare[] = rel_path($dir, $path) . '  ' . $h;
    }
    $fileRefs += count($bareHits);

    // 3-3. 푸터에 손으로 박힌 버전 문자열 = 다음 릴리스에서 조용히 낡는 자리.
    //      이 스크립트는 그 값을 **고치지 않는다.** 고쳐 주면 손으로 유지해야
    //      하는 자리를 스크립트가 정당화해 주는 셈이 된다. 지우는 것이 맞다.
    if (preg_match_all($rxFooterVer, $out, $fm)) {
        foreach ($fm[0] as $h) {
            // 여러 줄에 걸칠 수 있으므로 한 줄로 눌러서 보여준다.
            $flat = trim(preg_replace('/\s+/', ' ', $h));
            $footerVer[] = rel_path($dir, $path) . '  ' . $flat;
        }
    }

    if (!$dryRun && $out !== $src) {
        if (file_put_contents($path, $out) === false) {
            fwrite(STDERR, "쓰기 실패: $path\n");
            exit(2);
        }
    }

    $rows[] = array(
        'file'    => rel_path($dir, $path),
        'refs'    => $fileRefs,
        'changed' => $fileChanged,
        'already' => $fileAlready,
        'skipped' => $fileSkipped,
        'bare'    => count($bareHits),
    );

    $totalRefs    += $fileRefs;
    $totalChanged += $fileChanged;
    $totalAlready += $fileAlready;
    $totalSkipped += $fileSkipped;
}

function rel_path($base, $path)
{
    $p = str_replace('\\', '/', $path);
    if (strpos($p, $base . '/') === 0) {
        return substr($p, strlen($base) + 1);
    }
    return $p;
}

// ---------------------------------------------------------------------
// 4. 출력 — 전후 개수를 반드시 보여준다
// ---------------------------------------------------------------------

echo "\n대상 폴더 : $dir\n";
echo "새 버전   : ?v=$newVersion";
if ($from !== null) { echo "   (치환 대상 한정: ?v=$from)"; }
if ($dryRun)        { echo "   [DRY-RUN — 파일을 쓰지 않았다]"; }
echo "\n";

if (count($foundValues) > 0) {
    $vals = array_keys($foundValues);
    sort($vals);
    echo "치환 전 값 : ?v=" . implode(', ?v=', $vals) . "\n";
}

echo "\n";
printf("%-28s %6s %8s %8s %8s %8s\n", '파일', '참조', '변경', '이미동일', '건너뜀', '버전없음');
echo str_repeat('-', 72) . "\n";
foreach ($rows as $r) {
    printf(
        "%-28s %6d %8d %8d %8d %8d\n",
        $r['file'], $r['refs'], $r['changed'], $r['already'], $r['skipped'], $r['bare']
    );
}
echo str_repeat('-', 72) . "\n";
printf(
    "%-28s %6d %8d %8d %8d %8d\n",
    '합계 (' . count($rows) . '개 파일)',
    $totalRefs, $totalChanged, $totalAlready, $totalSkipped, count($bare)
);

echo "\n자산 참조 " . $totalRefs . "곳 중 " . $totalChanged . "곳 변경";
if ($totalAlready > 0) { echo ", " . $totalAlready . "곳은 이미 ?v=" . $newVersion; }
if ($totalSkipped > 0) { echo ", " . $totalSkipped . "곳은 --from 불일치로 건너뜀"; }
echo ".\n";

// ---------------------------------------------------------------------
// 5. 누락 보고 — 이것이 이 스크립트의 존재 이유다
// ---------------------------------------------------------------------

$exit = 0;

if (count($bare) > 0) {
    echo "\n";
    echo "!! 버전 쿼리가 없는 자산 참조 " . count($bare) . "곳을 발견했다.\n";
    echo "   이 참조들은 브라우저가 무기한 캐시한다 — 새 버전을 올려도 반영되지 않는다.\n";
    echo "   `?v=" . $newVersion . "` 을 붙인 뒤 이 스크립트를 다시 실행하라.\n\n";
    foreach ($bare as $b) {
        echo "     $b\n";
    }
    $exit = 1;
}

if (count($footerVer) > 0) {
    echo "\n";
    echo "!! 화면 푸터에 버전 문자열이 손으로 박혀 있다 (리더 판정 D-99).\n";
    echo "   이 스크립트는 그 값을 고치지 않는다 — 다음 릴리스에서 조용히 낡는다.\n";
    echo "   푸터에서 버전을 **지워라.** 버전의 출처는 두 곳뿐이고 둘 다 자동 갱신된다:\n";
    echo "     · public/version.txt        (--version-txt= 로 이 스크립트가 갱신)\n";
    echo "     · 자산 URL 의 ?v= 값        (이 스크립트가 일괄 치환)\n\n";
    foreach ($footerVer as $b) {
        echo "     $b\n";
    }
    $exit = 1;
}

// --from 오타로 아무것도 치환되지 않은 경우 (QA Q12-3).
//   이전에는 이 상태에서 종료코드 0 이 나갔다. INSTALL.md 의 절차가 `$?` 만
//   확인하도록 못박아 두었으므로, 치환이 전혀 안 됐는데 다음 단계로 넘어갔다.
//   위의 1(산출물 결함)이 이미 성립하면 그것을 우선한다 — 파일을 고치는 것이
//   명령을 고치는 것보다 급하다.
if ($exit === 0 && $totalChanged === 0 && $totalSkipped > 0) {
    echo "\n";
    echo "!! 치환 0곳, 건너뜀 " . $totalSkipped . "곳: `--from` 값이 현재 버전과 다릅니다.\n";
    echo "   --from=" . $from . " 을 줬지만 파일에서 그 값을 가진 자산 참조가 하나도 없다.\n";
    if (count($foundValues) > 0) {
        $vals = array_keys($foundValues);
        sort($vals);
        echo "   파일에 실제로 들어 있는 값: ?v=" . implode(', ?v=', $vals) . "\n";
    }
    echo "   --from 값을 고치거나, 발견된 모든 값을 대상으로 하려면 --from 을 빼고 실행하라.\n";
    echo "   (아무 파일도 바뀌지 않았다. 산출물은 그대로다)\n";
    $exit = 3;
}

// ---------------------------------------------------------------------
// 6. version.txt
// ---------------------------------------------------------------------

$vtPath = $dir . '/public/version.txt';

if ($versionTxt !== null) {
    if ($dryRun) {
        echo "\n[DRY-RUN] public/version.txt <- $versionTxt\n";
    } else {
        if (file_put_contents($vtPath, $versionTxt . "\n") === false) {
            fwrite(STDERR, "쓰기 실패: $vtPath\n");
            exit(2);
        }
        echo "\npublic/version.txt 갱신: $versionTxt\n";
    }
} else {
    $cur = is_file($vtPath) ? trim((string) file_get_contents($vtPath)) : '(없음)';
    echo "\n※ public/version.txt 는 건드리지 않았다 (현재: $cur).\n";
    echo "  형식이 `{버전}_{YYYYMMDD}` 로 달라 자동 추론하지 않는다.\n";
    echo "  --version-txt=" . $newVersion . "_YYYYMMDD 로 함께 갱신하거나 직접 고쳐라.\n";
    echo "  갱신하지 않으면 배포 후 브라우저로 버전을 확인할 때 옛 값이 보인다.\n";
}

// ---------------------------------------------------------------------
// 6-b. version.json (G18 / v1.8.7) — version.txt 에서 **생성**한다. 손으로 고치지 않는다.
//      `{"version":"<version.txt 의 `_` 앞 부분>"}` 한 줄. 서버가 `GET /version.json` 으로 내고
//      RUI.updates() 가 열린 탭의 버전과 견준다. 값이 ?v= 와 다르면 알림이 거짓이 되므로 어긋나면 종료코드 3.
// ---------------------------------------------------------------------
$vjPath = $dir . '/public/version.json';
$vtNow  = is_file($vtPath) ? trim((string) file_get_contents($vtPath)) : '';
if ($versionTxt !== null && !$dryRun) { $vtNow = $versionTxt; }
$vjVer  = strpos($vtNow, '_') !== false ? substr($vtNow, 0, strpos($vtNow, '_')) : $vtNow;
if ($vjVer === '' || !preg_match('/^[0-9A-Za-z.-]+$/', $vjVer)) {
    fwrite(STDERR, "version.txt 에서 버전을 읽지 못했다: '$vtNow' -> version.json 을 쓰지 않았다.\n");
    $exit = 3;
} elseif ($dryRun) {
    echo "[DRY-RUN] public/version.json <- {\"version\":\"$vjVer\"}\n";
} else {
    if (file_put_contents($vjPath, '{"version":"' . $vjVer . '"}' . "\n") === false) {
        fwrite(STDERR, "쓰기 실패: $vjPath\n");
        exit(2);
    }
    echo "public/version.json 갱신: $vjVer\n";
    if ($vjVer !== $newVersion) {
        fwrite(STDERR, "경고: version.json($vjVer) 과 ?v= ($newVersion) 이 다르다. --version-txt= 를 함께 줘라.\n");
        $exit = 3;
    }
}

// 실패했을 때 "다음 단계로 가라"고 말하지 않는다. 종료코드만 보는 절차가
// 통과해 버리는 것이 Q12-3 의 본질이었으므로, 사람이 읽는 출력도 같이 막는다.
if ($exit === 0) {
    echo "\n다음 할 일: 업로드 후 `{설치루트}/public/version.txt` 를 열어 값을 확인하고,\n";
    echo "개발자도구 Network 에서 자산 요청 URL 에 ?v=$newVersion 이 붙었는지 본다.\n";
} else {
    echo "\n실패했다 (종료코드 $exit). 위에 지목된 것을 고치고 다시 실행하라.\n";
    echo "고치기 전에는 업로드하지 마라 — 배포해도 반영되지 않는 상태다.\n";
}

exit($exit);
