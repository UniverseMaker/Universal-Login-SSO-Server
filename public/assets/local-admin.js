/* ============================================================================
 * ulogin v1.10.1 — 비상 관리자 계정 로컬 설정 화면 보조 (local-admin.js) · D-374
 *
 * 화면 본체는 server/local_admin.php 가 서버에서 그린다(폼은 일반 POST). 이 파일은 보조만 한다:
 *   · 비밀번호 보기 토글(버튼 [data-la-eye])
 *   · 강도 표시(.strength data-level 0~4 — register.html 과 같은 클래스) · 두 칸 일치 안내
 *   · 10자 미만·불일치면 제출을 막는다(서버가 다시 검사한다. 이것은 편의다)
 * 값은 어디에도 저장하지 않는다(localStorage·콘솔 출력 없음). 인라인 스크립트 없음(CSP script-src 'self').
 * ========================================================================== */
(function () {
  'use strict';

  var MIN = 10;   // D-373 — 관리자·비상 계정 최소 길이(서버 EM_STRONG_MIN 과 같다)
  var doc = document;
  var pw = doc.getElementById('la-pw');
  var pw2 = doc.getElementById('la-pw2');
  var meter = doc.getElementById('la-strength');
  var meterText = doc.getElementById('la-strength-text');
  var match = doc.getElementById('la-match');
  var form = doc.getElementById('la-set');

  Array.prototype.forEach.call(doc.querySelectorAll('[data-la-eye]'), function (btn) {
    btn.addEventListener('click', function () {
      var input = doc.getElementById(btn.getAttribute('data-la-eye'));
      if (!input) { return; }
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.textContent = show ? '숨기기' : '보기';
      btn.setAttribute('aria-pressed', show ? 'true' : 'false');
      input.focus();
    });
  });

  function kinds(v) {
    var n = 0;
    if (/[A-Za-z]/.test(v)) { n += 1; }
    if (/[0-9]/.test(v)) { n += 1; }
    if (/[^A-Za-z0-9]/.test(v)) { n += 1; }
    return n;
  }

  function level(v) {
    if (!v) { return 0; }
    var k = kinds(v);
    if (v.length < MIN || k < 2) { return 1; }
    if (v.length >= 14 && k >= 3) { return 4; }
    if (v.length >= 12 || k >= 3) { return 3; }
    return 2;
  }

  var TEXT = {
    0: '',
    1: '사용할 수 없습니다. ' + MIN + '자 이상, 영문·숫자·특수문자 중 2종류 이상이 필요합니다.',
    2: '보통: 길이를 늘리거나 문자 종류를 더하면 더 안전합니다.',
    3: '양호',
    4: '강함'
  };

  function render() {
    if (!pw) { return; }
    var lv = level(pw.value);
    if (meter) { meter.setAttribute('data-level', String(lv)); }
    if (meterText) { meterText.textContent = TEXT[lv] || ''; }
    if (match) {
      match.textContent = !pw2 || !pw2.value ? '' : (pw.value === pw2.value ? '두 입력이 같습니다.' : '두 입력이 다릅니다.');
    }
  }

  if (pw) { pw.addEventListener('input', render); }
  if (pw2) { pw2.addEventListener('input', render); }
  if (form && pw && pw2) {
    form.addEventListener('submit', function (ev) {
      if (pw.value.length < MIN || pw.value !== pw2.value) {
        ev.preventDefault();
        render();
        (pw.value.length < MIN ? pw : pw2).focus();
      }
    });
  }
})();
