import os,signal,tempfile,subprocess,socket,time,urllib.request
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='qingdav-browser-') as tmp:
 tmp=Path(tmp);state=tmp/'state';data=tmp/'data';data.mkdir()
 for n in ['big-one.bin','big-two.bin']:
  with (data/n).open('wb') as f:
   for _ in range(64):f.write(os.urandom(1024*1024))
   f.write(os.urandom(117))
 (data/'small.txt').write_text('browser small file verification')
 with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
 env={**os.environ,'WEBDAV_STATE_DIR':str(state),'PHP_CLI_SERVER_WORKERS':'4'}
 env.pop('WEBDAV_SETUP_TOKEN',None);env.pop('WEBDAV_ACCEL_PREFIX',None)
 with (tmp/'server.log').open('w+') as log:
  p=subprocess.Popen(['php','-d','memory_limit=128M','-S',f'127.0.0.1:{port}','tools/router.php'],cwd=ROOT,env=env,stdout=log,stderr=log,start_new_session=True)
  try:
   for _ in range(100):
    try:urllib.request.urlopen(f'http://127.0.0.1:{port}',timeout=.5).close();break
    except OSError:time.sleep(.05)
   r=subprocess.run(['node',str(ROOT / 'tests/browser_upload.cjs')],env={**env,'BENCH_URL':f'http://127.0.0.1:{port}','STATE_DIR':str(state),'FIXTURE_DIR':str(data)},timeout=90)
   if r.returncode:log.seek(0);print(log.read()[-5000:]);raise SystemExit(r.returncode)
  finally:os.killpg(p.pid,signal.SIGTERM);p.wait()
