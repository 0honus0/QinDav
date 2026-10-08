#!/usr/bin/env python3
"""Disposable HTTP update tests; only the download transport is replaced with local fixtures."""
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import signal
import socket
import subprocess
import tempfile
import time
import urllib.request
import zipfile

from integration import Client, PASSWORD, USER

ROOT = Path(__file__).resolve().parents[1]
CHECKS = 0
OPCACHE = os.environ.get("QINDAV_TEST_OPCACHE") == "1"


def check(ok, message):
    global CHECKS
    if not ok:
        raise AssertionError(message)
    CHECKS += 1


with tempfile.TemporaryDirectory(prefix='qindav-update-') as tmp:
    tmp = Path(tmp)
    app, state, fixtures = tmp / 'app', tmp / 'state', tmp / 'fixtures'
    app.mkdir(); fixtures.mkdir(); (app / 'tools').mkdir()
    source = re.sub(r"const QINDAV_VERSION = '[0-9]+\.[0-9]+\.[0-9]+';",
                    "const QINDAV_VERSION = '1.1.0';", (ROOT / 'index.php').read_text())
    start, end = source.index('function updateDownload('), source.index('function updateRelease(')
    stub = '''function updateDownload(string $url, string $destination, int $limit): void
{
    if (!updateAllowedUrl($url)) throw new InvalidArgumentException('Test: forbidden URL');
    $name = str_ends_with($url, '/latest') ? 'release.json' : basename(parse_url($url, PHP_URL_PATH));
    $path = getenv('QINDAV_TEST_FIXTURE_DIR') . '/' . $name;
    if (filesize($path) > $limit || !copy($path, $destination)) throw new RuntimeException('Fixture download failed');
}

'''
    source = source[:start] + stub + source[end:]
    (app / 'index.php').write_text(source)
    # A legacy entry exercises snapshot migration and rollback to an older layout.
    (app / 'dav.php').write_text('<?php // legacy entry fixture')
    shutil.copy(ROOT / 'tools/router.php', app / 'tools/router.php')
    shutil.copytree(ROOT / 'vendor', app / 'vendor')
    original_files = {p.relative_to(app).as_posix(): p.read_bytes() for p in app.rglob('*') if p.is_file()}

    def fixture(version, extra=None, invalid_version=False):
        tag = 'v' + version
        release = {'tag_name': tag, 'draft': False, 'prerelease': False,
                   'assets': [{'name': name, 'browser_download_url':
                               f'https://github.com/0honus0/QinDav/releases/download/{tag}/{name}'}
                              for name in ['QinDav.zip', 'SHA256SUMS']]}
        (fixtures / 'release.json').write_text(json.dumps(release))
        with zipfile.ZipFile(fixtures / 'QinDav.zip', 'w', zipfile.ZIP_DEFLATED) as z:
            for name, data in original_files.items():
                if name.startswith('tools/') or name == 'dav.php':
                    continue
                if name == 'index.php':
                    data = re.sub(r"const QINDAV_VERSION = '[0-9]+\.[0-9]+\.[0-9]+';",
                                  f"const QINDAV_VERSION = '{'9.9.9' if invalid_version else version}';",
                                  data.decode()).encode()
                z.writestr(name, data)
            if extra:
                for name, data in extra.items():
                    z.writestr(name, data)
        digest = hashlib.sha256((fixtures / 'QinDav.zip').read_bytes()).hexdigest()
        (fixtures / 'SHA256SUMS').write_text(f'{digest}  QinDav.zip\n')

    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
    env = {**os.environ, 'WEBDAV_STATE_DIR': str(state), 'QINDAV_TEST_FIXTURE_DIR': str(fixtures),
           'PHP_CLI_SERVER_WORKERS': '4'}
    env.pop('WEBDAV_SETUP_TOKEN', None); env.pop('WEBDAV_ACCEL_PREFIX', None)
    with (tmp / 'server.log').open('w+') as log:
        process = subprocess.Popen(['php', '-d', 'memory_limit=128M', '-d', f'opcache.enable={int(OPCACHE)}', '-d', f'opcache.enable_cli={int(OPCACHE)}',
                                    '-d', 'opcache.validate_timestamps=0', '-d', 'opcache.file_update_protection=0',
                                    '-S', f'127.0.0.1:{port}', 'tools/router.php'],
                                   cwd=app, env=env, stdout=log, stderr=log, start_new_session=True)
        try:
            base = f'http://127.0.0.1:{port}'
            for _ in range(100):
                try:
                    urllib.request.urlopen(base, timeout=.5).close(); break
                except OSError:
                    time.sleep(.05)
            c = Client(base); c.page()
            c.form({'action': 'setup', 'username': USER, 'password': PASSWORD, 'confirm': PASSWORD}); c.page()
            check(c.call('POST', '/?api=update-install', b'{}', {'Content-Type': 'application/json'})[0] == 401,
                  'DAV credentials do not authorize update')
            for action in ['update-check', 'update-install', 'update-restore', 'update-backup-limit']:
                check(c.api(action, token=False)[0] == 403, action + ' requires CSRF')
            info = c.api('update-info')[1]
            check(info['current'] == '1.1.0' and info['keep'] == 2 and info['backups'] == [], 'initial version and backup policy')
            check(info['can_update'], 'update prerequisites detected')
            with (state / 'application.lock').open('r') as gate:
                fcntl.flock(gate, fcntl.LOCK_EX)
                check(c.dav('GET', 'preserved.txt')[0] == 503, 'maintenance gate rejects new DAV requests')
                check(c.api('update-info')[0] == 503, 'maintenance gate rejects new UI requests')
                fcntl.flock(gate, fcntl.LOCK_UN)
            with (state / 'update.lock').open('w') as task:
                fcntl.flock(task, fcntl.LOCK_EX)
                check(c.api('update-backup-limit', {'keep': 3})[0] == 423, 'update task excludes retention edits')
                fcntl.flock(task, fcntl.LOCK_UN)

            check(c.api('update-backup-limit', {'keep': 0})[0] == 400, 'backup count minimum')
            check(c.api('update-backup-limit', {'keep': 11})[0] == 400, 'backup count maximum')
            check(c.api('update-restore', {'id': '../files'})[0] == 404, 'restore ID restricted to existing backups')
            check(c.dav('PUT', 'preserved.txt', b'keep this data')[0] == 201, 'user data fixture')
            c.api('storage-limit', {'limit_bytes': 100})
            app_password = c.api('app-password')[1]['password']
            account = (state / 'config.json').read_bytes()
            fixture('1.2.0')
            check(c.api('update-check')[1]['available'], 'new release detected')
            with (state / 'application.lock').open('r') as gate:
                fcntl.flock(gate, fcntl.LOCK_SH)
                check(c.api('update-install', {'version': '1.2.0'})[0] == 423, 'active request prevents swapping')
                fcntl.flock(gate, fcntl.LOCK_UN)
            check(c.api('update-info')[1]['backups'] == [], 'busy failure does not create backup')
            (fixtures / 'SHA256SUMS').write_text('0' * 64 + '  QinDav.zip\n')
            check(c.api('update-install', {'version': '1.2.0'})[0] == 400, 'checksum mismatch rejected')
            fixture('1.2.0', {'../outside.php': b'bad'})
            check(c.api('update-install', {'version': '1.2.0'})[0] == 400, 'path traversal rejected')
            check(not (tmp / 'outside.php').exists(), 'no traversal file written')
            fixture('1.2.0', {'config.json': b'bad'})
            check(c.api('update-install', {'version': '1.2.0'})[0] == 400, 'unexpected root file rejected')
            fixture('1.2.0', {'dav.php': b'<?php // unwanted second entry'})
            check(c.api('update-install', {'version': '1.2.0'})[0] == 400, 'second PHP entry rejected in new package')
            fixture('1.2.0', invalid_version=True)
            check(c.api('update-install', {'version': '1.2.0'})[0] == 400, 'archive version mismatch rejected')
            check(c.api('update-info')[1]['current'] == '1.1.0', 'validation failures preserve old code')
            if not OPCACHE:
                # Direct fixture edits bypass the updater's OPcache invalidation; the fault-injection server disables OPcache.
                # Inject a publication failure in the disposable copy, after vendor replacement.
                before_fault = (app / 'index.php').read_text()
                broken = before_fault.replace("if (!rename($job . '/new/' . $name, $live))", "if ($name === 'index.php' || !rename($job . '/new/' . $name, $live))")
                check(broken != before_fault, 'publication failure hook injected in test copy only')
                (app / 'index.php').write_text(broken)
                fixture('1.2.0')
                check(c.api('update-install', {'version': '1.2.0'})[0] == 400, 'partial publication failure rolls back')
                check((app / 'index.php').read_text() == broken and (app / 'vendor/autoload.php').is_file(), 'all old entries restored')
                check(c.api('update-info')[1]['backups'] == [], 'failed swap not recorded as backup')
                (app / 'index.php').write_text(before_fault)
            fixture('1.2.0')
            check(c.api('update-install', {'version': '1.3.0'})[0] == 409, 'changed release requires confirmation again')
            check(c.api('update-install', {'version': '1.2.0'})[0] == 200, 'update installed')
            check(not (app / 'dav.php').exists(), 'single-entry update removes legacy entry')
            info = c.api('update-info')[1]
            check(info['current'] == '1.2.0' and len(info['backups']) == 1 and info['backups'][0]['version'] == '1.1.0',
                  'new code active and previous code backed up')
            a = info['backups'][0]['id']
            check((state / 'config.json').read_bytes() == account, 'account and application secret preserved')
            check(c.api('storage')[1]['limit_bytes'] == 100, 'quota preserved')
            check(c.dav('GET', 'preserved.txt', password=app_password)[2] == b'keep this data', 'user data and DAV auth preserved')
            check(c.api('update-check')[1]['available'] is False, 'current release not reinstallable')
            check(c.api('update-install', {'version': '1.2.0'})[0] == 409, 'same version update rejected')
            check(c.api('update-restore', {'id': a})[0] == 200, 'rollback installed')
            check((app / 'dav.php').is_file(), 'rollback restores legacy snapshot file')
            info = c.api('update-info')[1]
            check(info['current'] == '1.1.0' and len(info['backups']) == 2, 'rollback backs up newer code')
            b = next(item['id'] for item in info['backups'] if item['version'] == '1.2.0')
            check(c.api('update-restore', {'id': a})[0] == 409, 'identical backup is a no-op')
            for target, expected in [(b, '1.2.0'), (a, '1.1.0'), (b, '1.2.0'), (a, '1.1.0')]:
                check(c.api('update-restore', {'id': target})[0] == 200, 'repeated rollback works')
                info = c.api('update-info')[1]
                check(info['current'] == expected, 'repeated rollback activates target')
                check((app / 'dav.php').exists() == (expected == '1.1.0'), 'entry layout matches restored snapshot')
                check(set(item['id'] for item in info['backups']) == {a, b}, 'exact backups reused instead of duplicated')
            check(len(list((state / 'updates').glob('job-*'))) == 2, 'no duplicate snapshot directories left behind')
            bad = state / 'updates' / b / 'old' / 'index.php'; old = bad.read_bytes(); bad.write_bytes(b'tampered')
            check(c.api('update-restore', {'id': b})[0] == 400, 'corrupt backup cannot be restored'); bad.write_bytes(old)
            pruned = c.api('update-info')[1]['backups'][1]['id']
            check(c.api('update-backup-limit', {'keep': 1})[0] == 200, 'retention policy saved')
            check(len(c.api('update-info')[1]['backups']) == 1 and len(list((state / 'updates').glob('job-*'))) == 1,
                  'reducing retention prunes metadata and physical backup')
            check(c.api('update-restore', {'id': pruned})[0] == 404, 'pruned backup cannot be restored')

            # Simulate interruption after vendor has been moved out but before replacement.
            job_id = 'job-' + 'a' * 24; job = state / 'updates' / job_id
            (job / 'old').mkdir(parents=True); (job / 'new').mkdir()
            os.rename(app / 'vendor', job / 'old' / 'vendor')
            journal = {'job': job_id, 'committed': False, 'existed': {'vendor': True, 'dav.php': True, 'index.php': True}}
            (state / 'update-journal.json').write_text(json.dumps(journal))
            code, _, _ = c.call('GET', '/', auth=False)
            check(code == 503 and (app / 'vendor/autoload.php').is_file(), 'bootstrap restores vendor before autoload')
            check(not (state / 'update-journal.json').exists(), 'recovered journal cleared')
            check(not job.exists(), 'interrupted job cleaned after recovery')
            check(c.api('update-info')[1]['current'] == '1.1.0', 'recovered instance works')
            check(c.dav('GET', 'preserved.txt')[2] == b'keep this data', 'crash recovery preserves user data')

            if not OPCACHE:
                # A committed swap must complete backup bookkeeping, rather than roll back.
                job_id = 'job-' + 'b' * 24; job = state / 'updates' / job_id
                (job / 'old').mkdir(parents=True); (job / 'new').mkdir()
                snapshot_files = {}
                for name in ['index.php', 'dav.php', 'vendor']:
                    src = app / name; dst = job / 'old' / name
                    if src.is_dir():
                        shutil.copytree(src, dst)
                        for path in src.rglob('*'):
                            if path.is_file(): snapshot_files[path.relative_to(app).as_posix()] = path
                    else:
                        shutil.copy(src, dst); snapshot_files[name] = src
                fingerprint = hashlib.sha256()
                for name, path in sorted(snapshot_files.items()):
                    fingerprint.update((name + '\0' + hashlib.sha256(path.read_bytes()).hexdigest() + '\n').encode())
                active = (app / 'index.php').read_text().replace("const QINDAV_VERSION = '1.1.0';", "const QINDAV_VERSION = '1.3.0';")
                (app / 'index.php').write_text(active)
                journal = {'job': job_id, 'committed': True, 'existed': {'vendor': True, 'dav.php': True, 'index.php': True},
                           'previous_version': '1.1.0', 'created_at': int(time.time()), 'fingerprint': fingerprint.hexdigest()}
                (state / 'update-journal.json').write_text(json.dumps(journal))
                check(c.call('GET', '/', auth=False)[0] == 503, 'committed journal housekeeping completed on bootstrap')
                info = c.api('update-info')[1]
                check(info['current'] == '1.3.0', 'committed new version not rolled back')
                check(len(info['backups']) == 1 and info['backups'][0]['version'] == '1.1.0', 'committed backup indexed and retention enforced')
                check(c.dav('GET', 'preserved.txt')[2] == b'keep this data', 'committed recovery preserves data')

            print(f'PASS: {CHECKS} update/rollback assertions; OPcache={OPCACHE}, transport mocked, real archive and filesystem swaps')
        except Exception:
            log.seek(0); print(log.read()[-6000:]); raise
        finally:
            os.killpg(process.pid, signal.SIGTERM); process.wait(timeout=5)
