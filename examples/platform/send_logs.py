# 운영 로그 1회 전송(서버끼리, 설계 116 §0-7). Python 3.8+ 표준 라이브러리만. 실행: ULOGIN_SERVICE_SECRET=… python send_logs.py
import json, os, uuid, datetime, urllib.request, urllib.error
base = os.environ.get('ULOGIN_BASE', 'http://127.0.0.1:8080')
ev = {'event_id': str(uuid.uuid4()), 'ts': datetime.datetime.utcnow().strftime('%Y-%m-%dT%H:%M:%SZ'), 'level': 'error', 'event': 'job.export.failed', 'message': '내보내기 실패: 시간 초과'}
body = json.dumps({'service_id': 'task', 'service_secret': os.environ.get('ULOGIN_SERVICE_SECRET'), 'events': [ev]}).encode('utf-8')
req = urllib.request.Request(base + '/server/api/platform/logs.php', data=body, headers={'Content-Type': 'application/json'}, method='POST')
try:
    with urllib.request.urlopen(req, timeout=5) as r: code, j = r.status, json.loads(r.read() or b'null')
except urllib.error.HTTPError as e: code, j = e.code, json.loads(e.read() or b'null')
if code == 200 and j and j.get('ok'): print('accepted', j['data']['accepted'], 'duplicate', j['data']['duplicate'], 'rejected', len(j['data']['rejected']))  # 스풀에서 지운다
elif code == 429: print('retry_after', ((j or {}).get('data') or {}).get('retry_after', 60), 's')  # 그만큼 기다렸다가 다시
elif code in (404, 405): print('미지원(v1.9.0 전) — 6시간 뒤 재확인')  # 스풀 유지
else: print('실패', code, ((j or {}).get('error') or {}).get('code'), '— 스풀 유지, 백오프')
