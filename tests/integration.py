#!/usr/bin/env python3
"""End-to-end protocol tests; uses disposable state, real HTTP and actual sabre/dav."""
import argparse
import base64
import concurrent.futures
import hashlib
import http.client
import http.cookiejar
import io
import json
import os
from pathlib import Path
import re
import signal
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
PASSWORD = 'integration-password-123'
NEW_PASSWORD = 'changed-password-456'
TOKEN = 'integration-setup-token'
USER = 'admin'
CHECKS = 0


def check(condition, message):
    global CHECKS
    if not condition:
        raise AssertionError(message)
    CHECKS += 1


class Client:
    def __init__(self, base):
        self.base = base.rstrip('/')
        self.parsed = urllib.parse.urlsplit(self.base)
        self.password = PASSWORD
        self.jar = http.cookiejar.CookieJar()
        self.browser = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.csrf = None

    def connection(self):
        cls = http.client.HTTPSConnection if self.parsed.scheme == 'https' else http.client.HTTPConnection
        return cls(self.parsed.hostname, self.parsed.port, timeout=60)

    def auth(self, password=None):
        return 'Basic ' + base64.b64encode(f'{USER}:{password or self.password}'.encode()).decode()

    def call(self, method, path, body=None, headers=None, auth=True, password=None):
        connection = self.connection()
        h = dict(headers or {})
        if auth:
            h['Authorization'] = self.auth(password)
        connection.request(method, path, body=body, headers=h)
        response = connection.getresponse()
        result = response.status, dict(response.getheaders()), response.read()
        connection.close()
        return result

    def page(self):
        page = self.browser.open(self.base + '/').read().decode()
        match = re.search(r'name="csrf" value="([^"]+)"', page)
        if not match:
            match = re.search(r'const csrf = "([^"]+)"', page)
        check(match is not None, 'page has CSRF token')
        self.csrf = match.group(1)
        return page

    def form(self, fields):
        body = urllib.parse.urlencode({'csrf': self.csrf, **fields}).encode()
        try:
            response = self.browser.open(self.base + '/', body)
        except urllib.error.HTTPError as error:
            response = error
        return response.code, response.read().decode()

    def cookie(self):
        return '; '.join(f'{c.name}={c.value}' for c in self.jar)

    def api(self, action, data=None, token=True):
        headers = {'Content-Type': 'application/json'}
        if token:
            headers['X-CSRF-Token'] = self.csrf
        method = 'GET' if action.startswith('list') or action == 'storage' else 'POST'
        body = None if method == 'GET' else json.dumps(data or {}).encode()
        request = urllib.request.Request(self.base + '/?api=' + action, body, headers, method=method)
        try:
            response = self.browser.open(request)
        except urllib.error.HTTPError as error:
            response = error
        return response.code, json.loads(response.read())

    def dav(self, method, name='', body=None, headers=None, **kwargs):
        path = '/dav.php/' + urllib.parse.quote(name, safe='/')
        return self.call(method, path, body, headers, **kwargs)


def suite(client, state):
    page = client.page()
    check('创建你的文件空间' in page, 'instance must be uninitialized')
    check(client.form(dict(action='setup', username=USER, password=PASSWORD, confirm=PASSWORD,
                           setup_token='incorrect'))[1].find('初始化令牌错误') >= 0, 'setup token enforced')
    check(not (state / 'config.json').exists(), 'invalid setup does not create config')
    code, page = client.form(dict(action='setup', username=USER, password=PASSWORD, confirm=PASSWORD,
                                 setup_token=TOKEN))
    check(code == 200 and 'class="file-browser"' in page, 'setup creates and logs in administrator')
    client.page()
    cfg = json.loads((state / 'config.json').read_text())
    check(cfg['username'] == USER and PASSWORD not in json.dumps(cfg), 'config contains hash, no plaintext')
    check(client.api('list')[0] == 200, 'session-authenticated file listing')
    check(not (state / 'usage.json').exists(), 'first listing does not wait for initial usage scan')
    check(client.api('app-password', token=False)[0] == 403, 'API requires CSRF')
    check(client.dav('GET', auth=False)[0] == 401, 'anonymous DAV denied')
    check(client.call('GET', '/composer.json', auth=False)[0] == 404, 'dependency metadata not exposed')
    check(client.call('GET', '/vendor/autoload.php', auth=False)[0] == 404, 'vendor not exposed')
    check(client.dav('MKCOL', 'cookie-denied', headers={'Cookie': client.cookie()}, auth=False)[0] == 403,
          'session DAV mutations require CSRF')
    check(client.dav('MKCOL', 'cookie-ok', headers={'Cookie': client.cookie(), 'X-CSRF-Token': client.csrf},
                     auth=False)[0] == 201, 'session DAV mutation with CSRF')
    check(client.dav('OPTIONS')[0] == 200, 'DAV OPTIONS')
    check(client.call('PROPFIND', '/index.php/dav/', headers={'Depth': '0'})[0] == 404,
          'management PHP path is not a DAV alias')
    check(client.call('PROPFIND', '/dav/', headers={'Depth': '0'})[0] == 404,
          'extensionless path is not a DAV alias')

    for name in ['source', 'target']:
        check(client.dav('MKCOL', name)[0] == 201, f'MKCOL {name}')
    content = b'0123456789abcdefghij'
    code, headers, _ = client.dav('PUT', 'source/sample.bin', content)
    check(code == 201, 'new PUT')
    etag = headers.get('ETag')
    check(bool(etag), 'PUT provides ETag')
    code, get_headers, body = client.dav('GET', 'source/sample.bin')
    check(code == 200 and body == content, 'GET contents')
    check(get_headers.get('ETag') == etag, 'GET preserves the same ETag as PUT, including Nginx')
    check(get_headers.get('X-Content-Type-Options') == 'nosniff', 'downloads disable content sniffing')
    code, headers, body = client.dav('HEAD', 'source/sample.bin')
    check(code == 200 and not body and int(headers['Content-Length']) == len(content), 'HEAD size')
    check(client.dav('GET', 'source/sample.bin', headers={'Range': 'bytes=0-4'})[2] == b'01234', 'prefix range')
    check(client.dav('GET', 'source/sample.bin', headers={'Range': 'bytes=0-0'})[2] == b'0', 'single first-byte range')
    check(client.dav('GET', 'source/sample.bin', headers={'Range': 'bytes=0-0', 'If-Range': etag})[2] == b'0',
          'single first-byte range with If-Range')
    check(client.dav('GET', 'source/sample.bin', headers={'Range': 'bytes=5-9'})[2] == b'56789', 'middle range')
    check(client.dav('GET', 'source/sample.bin', headers={'Range': 'bytes=-4'})[2] == b'ghij', 'suffix range')
    check(client.dav('GET', 'source/sample.bin', headers={'Range': 'bytes=100-200'})[0] == 416, 'invalid range')
    check(client.dav('GET', 'source/sample.bin', headers={'If-None-Match': etag})[0] == 304, 'conditional GET')
    code, _, body = client.dav('GET', 'source/sample.bin', headers={'Range': 'bytes=0-4', 'If-Range': etag})
    check(code == 206 and body == b'01234', 'matching If-Range')
    code, _, body = client.dav('GET', 'source/sample.bin', headers={'Range': 'bytes=0-4', 'If-Range': '"stale"'})
    check(code == 200 and body == content, 'stale If-Range returns full contents')
    check(client.dav('PUT', 'source/sample.bin', b'bad', {'If-Match': '"stale"'})[0] == 412,
          'conditional overwrite rejected')
    check(client.dav('PUT', 'source/sample.bin', b'bad', {'If-None-Match': '*'})[0] == 412,
          'create-only overwrite rejected')
    check(client.dav('GET', 'source/sample.bin')[2] == content, 'conditional failure preserves original')
    check(client.dav('PUT', 'source/sample.bin', content[::-1], {'If-Match': etag})[0] == 204,
          'conditional overwrite succeeds')
    new_etag = client.dav('HEAD', 'source/sample.bin')[1]['ETag']
    check(new_etag != etag, 'same-size immediate overwrite changes ETag')
    check(client.dav('PUT', 'empty', b'')[0] == 201 and client.dav('GET', 'empty')[2] == b'', 'empty file')
    check(client.dav('GET', 'empty', headers={'Range': 'bytes=0-0'})[0] == 416, 'empty-file range unsatisfiable')
    for name in ['中文 空格 #%.txt', '<script>.txt']:
        check(client.dav('PUT', name, b'utf8')[0] == 201, 'special filename upload')
        check(client.dav('GET', name)[2] == b'utf8', 'special filename download')
    code, _, xml = client.dav('PROPFIND', 'source', headers={'Depth': '1'})
    tree = ET.fromstring(xml)
    check(code == 207 and len(tree.findall('{DAV:}response')) == 2, 'Depth 1 PROPFIND')
    check(client.dav('COPY', 'source/sample.bin', headers={'Destination': client.base + '/dav.php/target/copy.bin'})[0] == 201,
          'COPY')
    check(client.dav('GET', 'target/copy.bin')[2] == content[::-1], 'COPY preserves content')
    inode = (state / 'files/source/sample.bin').stat().st_ino
    check(client.dav('MOVE', 'source/sample.bin', headers={'Destination': client.base + '/dav.php/target/moved.bin'})[0] == 201,
          'cross-directory MOVE')
    check((state / 'files/target/moved.bin').stat().st_ino == inode, 'MOVE uses native rename')
    check(client.dav('GET', 'source/sample.bin')[0] == 404, 'MOVE removes source')
    check(client.dav('MOVE', 'target/moved.bin', headers={'Destination': client.base + '/dav.php/target/renamed.bin'})[0] == 201,
          'same-directory rename')
    check(client.dav('PUT', 'source/nested', b'x')[0] == 201, 'create nested file')
    inode = (state / 'files/source/nested').stat().st_ino
    check(client.dav('MOVE', 'source', headers={'Destination': client.base + '/dav.php/target/subtree'})[0] == 201,
          'directory MOVE')
    check((state / 'files/target/subtree/nested').stat().st_ino == inode, 'directory MOVE avoids recursive copy')
    check(client.dav('DELETE', 'target/subtree')[0] == 204, 'recursive DELETE')
    check(client.dav('DELETE')[0] == 403, 'root cannot be deleted')
    check(client.dav('PUT', '.dav-upload-reserved', b'x')[0] == 403, 'reserved name rejected')
    check(client.dav('PUT', 'bad\\name', b'x')[0] == 403, 'backslash path rejected')
    check(client.call('GET', '/dav.php/%2e%2e/config.json')[0] in (403, 404), 'traversal rejected')
    outside = state / 'secret.txt'
    outside.write_text('outside-secret')
    (state / 'files/escape').symlink_to(outside)
    (state / 'files/escape-dir').symlink_to(state, target_is_directory=True)
    check(client.dav('GET', 'escape')[0] == 403, 'symlink file rejected')
    check(client.dav('GET', 'escape-dir/config.json')[0] == 403, 'symlink directory rejected')
    code, listing = client.api('list')
    check(code == 200 and not any(i['name'].startswith('escape') for i in listing['items']), 'symlinks omitted from listing')

    lock_body = b'''<?xml version="1.0"?><d:lockinfo xmlns:d="DAV:"><d:lockscope><d:exclusive/></d:lockscope><d:locktype><d:write/></d:locktype><d:owner>integration</d:owner></d:lockinfo>'''
    client.dav('PUT', 'lock-test', b'initial')
    code, headers, _ = client.dav('LOCK', 'lock-test', lock_body, {'Content-Type': 'application/xml', 'Timeout': 'Second-300'})
    check(code == 200, 'LOCK existing file')
    token = headers.get('Lock-Token')
    check(bool(token), 'LOCK token returned')
    check(client.dav('PUT', 'lock-test', b'blocked')[0] == 423, 'locked write blocked')
    check(client.dav('PUT', 'lock-test', b'allowed', {'If': '(' + token + ')'})[0] == 204, 'lock-token write allowed')
    check(client.dav('UNLOCK', 'lock-test', headers={'Lock-Token': token})[0] == 204, 'UNLOCK')
    check(client.dav('PUT', 'lock-test', b'unlocked')[0] == 204, 'write after unlock')
    client.dav('MKCOL', 'lock-parent')
    client.dav('PUT', 'lock-parent/child', b'initial')
    code, headers, _ = client.dav('LOCK', 'lock-parent', lock_body, {'Content-Type': 'application/xml', 'Depth': 'infinity'})
    check(code == 200, 'directory depth-infinity lock')
    check(client.dav('PUT', 'lock-parent/child', b'blocked')[0] == 423, 'parent lock covers child')
    client.dav('UNLOCK', 'lock-parent', headers={'Lock-Token': headers['Lock-Token']})
    chunk_upload_check(client, state, lock_body)
    quota_check(client, state)
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        results = list(pool.map(lambda _: client.dav('LOCK', 'lock-test', lock_body, {'Content-Type': 'application/xml'}), range(2)))
    check(sorted(r[0] for r in results) == [200, 423], 'concurrent exclusive LOCK has one winner')
    for code, headers, _ in results:
        if code == 200:
            client.dav('UNLOCK', 'lock-test', headers={'Lock-Token': headers['Lock-Token']})

    # Abruptly disconnect after declaring a larger body: old committed data must survive.
    client.dav('PUT', 'interrupted', b'original')
    if client.parsed.scheme == 'http':
        connection = socket.create_connection((client.parsed.hostname, client.parsed.port), timeout=5)
        message = (f'PUT /dav.php/interrupted HTTP/1.1\r\nHost: {client.parsed.netloc}\r\n'
                   f'Authorization: {client.auth()}\r\nContent-Length: 1048576\r\nConnection: close\r\n\r\n').encode()
        connection.sendall(message + b'partial')
        connection.shutdown(socket.SHUT_WR)
        connection.close()
        time.sleep(0.15)
        check(client.dav('GET', 'interrupted')[2] == b'original', 'interrupted upload preserves original')
    check(not list((state / 'files').rglob('.dav-upload-*')), 'normal failures leave no upload temp files')

    check(client.api('view-app-password', token=False)[0] == 403, 'viewing application password requires CSRF')
    check(client.call('POST', '/?api=view-app-password', b'{}',
                      {'Content-Type': 'application/json'})[0] == 401, 'DAV credentials cannot view application password')
    check(client.api('view-app-password')[1] == {'ok': True, 'password': None, 'configured': False},
          'no application password configured')
    code, data = client.api('app-password')
    check(code == 200 and bool(data['password']), 'application password generated')
    app_password = data['password']
    check(client.dav('GET', 'lock-test', password=app_password)[0] == 200, 'application password authenticates')
    check(client.api('view-app-password')[1]['password'] == app_password, 'application password can be viewed again')
    check(app_password not in client.page(), 'application password is not embedded in management HTML')
    check(client.api('view-app-password')[1]['password'] == app_password, 'application password survives page reload')
    check((state / 'config.json').stat().st_mode & 0o777 == 0o600, 'recoverable password configuration is private')
    legacy = json.loads((state / 'config.json').read_text())
    legacy.pop('app_password')
    (state / 'config.json').write_text(json.dumps(legacy))
    check(client.api('view-app-password')[1] == {'ok': True, 'password': None, 'configured': True},
          'legacy application password cannot be recovered')
    check(client.dav('GET', 'lock-test', password=app_password)[0] == 200, 'legacy application password remains valid')
    replacement = client.api('app-password')[1]['password']
    check(client.api('view-app-password')[1]['password'] == replacement, 'replacement password is retrievable')
    check(client.dav('GET', 'lock-test', password=app_password)[0] == 401, 'replacement invalidates old cached password')
    app_password = replacement
    client.api('revoke-app-password')
    check(client.dav('GET', 'lock-test', password=app_password)[0] == 401, 'revocation invalidates cached credentials')
    check(client.api('view-app-password')[1] == {'ok': True, 'password': None, 'configured': False},
          'revocation removes retrievable password')
    check(client.api('password', {'current': 'wrong', 'password': NEW_PASSWORD})[0] == 403, 'password change verifies current')
    check(client.api('password', {'current': PASSWORD, 'password': NEW_PASSWORD})[0] == 200, 'password changed')
    check(client.dav('GET', 'lock-test')[0] == 401, 'password change invalidates cached old password')
    client.password = NEW_PASSWORD
    check(client.dav('GET', 'lock-test')[0] == 200, 'new password authenticates')
    check(client.api('list')[0] == 200, 'current session survives own password change')
    client.page()
    client.form({'action': 'logout'})
    check(client.api('list')[0] == 401, 'logout revokes session')
    client.page()
    code, _ = client.form({'action': 'login', 'username': USER, 'password': NEW_PASSWORD})
    check(code == 200, 'web login')
    client.page()
    return client


def chunk_upload_check(client, state, lock_body):
    check(client.api('upload-start', {'path': 'chunks.bin', 'size': 1}, token=False)[0] == 403,
          'chunk upload creation requires CSRF')
    check(client.api('upload-start', {'path': '../bad', 'size': 1})[0] == 403, 'chunk traversal rejected')
    check(client.api('upload-start', {'path': 'escape-dir/bad', 'size': 1})[0] == 403, 'chunk symlink traversal rejected')
    check(client.api('upload-start', {'path': 'chunks.bin', 'size': -1})[0] == 400, 'chunk invalid size rejected')
    data = os.urandom(32 * 1024 * 1024 + 17)
    code, job = client.api('upload-start', {'path': 'chunks.bin', 'size': len(data)})
    check(code == 200 and job['chunks'] == 3, 'chunked upload created')
    staging = state / 'files' / ('.dav-upload-' + job['id'])
    inode = staging.stat().st_ino
    check(staging.stat().st_size == len(data), 'sparse upload has declared logical size')
    check(client.api('upload-finish', {'id': job['id']})[0] == 409, 'incomplete upload cannot publish')
    check(client.dav('GET', 'chunks.bin')[0] == 404, 'incomplete upload not visible')
    check(not any(i['name'].startswith('.dav-upload-') for i in client.api('list')[1]['items']),
          'staging files omitted from management listing')

    def part(index, body=None, token=True):
        block = data[index * job['chunk_size']:(index + 1) * job['chunk_size']] if body is None else body
        headers = {'Cookie': client.cookie()}
        if token:
            headers['X-CSRF-Token'] = client.csrf
        return client.call('PUT', '/?api=upload-part&id=' + job['id'] + '&part=' + str(index), block, headers, auth=False)

    check(part(2, token=False)[0] == 403, 'chunk writes require CSRF')
    check(client.call('PUT', '/?api=upload-part&id=' + job['id'] + '&part=2', b'x', auth=False)[0] == 401,
          'chunk writes require authenticated session')
    check(part(3, b'x')[0] == 400, 'invalid chunk index rejected')
    check(part(0, b'x')[0] == 400, 'wrong chunk length rejected')
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        codes = list(pool.map(lambda index: part(index)[0], [2, 1, 0, 1]))
    check(codes == [200] * 4, 'out-of-order concurrent parts and duplicate part succeed')
    check(client.api('upload-finish', {'id': job['id']})[0] == 200, 'chunk completion succeeds')
    check(client.api('upload-finish', {'id': job['id']})[0] == 200, 'completion acknowledgement can be retried')
    check(client.dav('GET', 'chunks.bin')[2] == data, 'concurrent chunks preserve exact content')
    check((state / 'files/chunks.bin').stat().st_ino == inode, 'chunk publication avoids full-file copy')
    check(not staging.exists(), 'published upload removes staging file')
    check(client.api('upload-start', {'path': 'chunks.bin', 'size': 1})[0] == 412, 'chunk upload never overwrites existing file')
    check(client.api('upload-cancel', {'id': job['id']})[0] == 200, 'completed receipt can be cleaned')
    check(client.dav('GET', 'chunks.bin')[2] == data, 'receipt cleanup preserves published data')
    client.dav('DELETE', 'chunks.bin')

    for scenario in ['cancel', 'conflict', 'lock', 'expire', 'recover']:
        name = 'lock-parent/chunk-' + scenario
        code, job = client.api('upload-start', {'path': name, 'size': 3})
        check(code == 200, scenario + ' upload starts')
        stage = state / 'files' / ('.dav-upload-' + job['id'])
        code, _, _ = client.call('PUT', '/?api=upload-part&id=' + job['id'] + '&part=0', b'abc',
                                {'Cookie': client.cookie(), 'X-CSRF-Token': client.csrf}, auth=False)
        check(code == 200, scenario + ' part accepted')
        if scenario == 'conflict':
            client.dav('PUT', name, b'keep-me')
            check(client.api('upload-finish', {'id': job['id']})[0] == 412, 'late destination creation blocks completion')
            check(client.dav('GET', name)[2] == b'keep-me', 'completion conflict preserves existing content')
        elif scenario == 'lock':
            code, headers, _ = client.dav('LOCK', 'lock-parent', lock_body, {'Content-Type': 'application/xml', 'Depth': 'infinity'})
            check(code == 200, 'parent lock acquired during chunk upload')
            check(client.api('upload-finish', {'id': job['id']})[0] == 423, 'completion respects parent DAV lock')
            check(client.api('upload-start', {'path': 'lock-parent/another', 'size': 3})[0] == 423,
                  'chunk creation respects parent DAV lock')
            client.dav('UNLOCK', 'lock-parent', headers={'Lock-Token': headers['Lock-Token']})
        elif scenario == 'expire':
            metadata = state / 'uploads' / job['id'] / 'job.json'
            cfg = json.loads(metadata.read_text()); cfg['created'] = int(time.time()) - 86401
            metadata.write_text(json.dumps(cfg))
            check(client.api('upload-finish', {'id': job['id']})[0] == 404, 'expired upload cannot publish')
            _, extra = client.api('upload-start', {'path': 'gc-trigger', 'size': 3})
            check(not stage.exists() and not metadata.parent.exists(), 'new uploads reclaim expired staging and metadata')
            client.api('upload-cancel', {'id': extra['id']})
            continue
        elif scenario == 'recover':
            os.link(stage, state / 'files' / name)
            accounting = state / 'usage.json'
            usage = json.loads(accounting.read_text()); usage['dirty'] = True
            accounting.write_text(json.dumps(usage))
            check(client.api('upload-finish', {'id': job['id']})[0] == 200, 'publication interrupted before receipt can recover')
            check(client.dav('GET', name)[2] == b'abc', 'recovered publication preserves content')
        check(client.api('upload-cancel', {'id': job['id']})[0] == 200, scenario + ' task cleanup succeeds')
        check(not stage.exists(), scenario + ' staging cleaned')
        if scenario in ['cancel', 'lock']:
            check(client.dav('GET', name)[0] == 404, scenario + ' never publishes a file')
        else:
            client.dav('DELETE', name)


def quota_check(client, state):
    check(client.api('storage-limit', {'limit_bytes': 1}, token=False)[0] == 403, 'quota settings require CSRF')
    check(client.api('storage-limit', {'limit_bytes': -1})[0] == 400, 'negative quota rejected')
    check(client.api('storage-limit', {'limit_bytes': 1.5})[0] == 400, 'fractional byte quota rejected')
    code, baseline = client.api('storage-rescan')
    check(code == 200 and baseline['reserved_bytes'] == 0, 'initial usage rescan completes without reservations')
    used = baseline['used_bytes']; files = baseline['files']
    limit = used + 8
    check(client.api('storage-limit', {'limit_bytes': limit})[0] == 200, 'capacity limit saved')
    check(client.dav('PUT', 'quota-one', b'12345678')[0] == 201, 'upload exactly reaching quota allowed')
    info = client.api('storage')[1]
    check(info['used_bytes'] == limit and info['files'] == files + 1 and info['available_bytes'] == 0,
          'committed file bytes and count tracked')
    check(client.dav('PUT', 'quota-over', b'x')[0] == 507, 'PUT above quota rejected')
    check(client.dav('GET', 'quota-over')[0] == 404, 'rejected PUT never publishes')
    check(client.dav('PUT', 'quota-one', b'123456')[0] == 204, 'smaller replacement allowed at full quota')
    check(client.dav('PUT', 'quota-one', b'123456789')[0] == 507, 'oversized replacement rejected')
    check(client.dav('GET', 'quota-one')[2] == b'123456', 'failed replacement preserves old data')
    check(client.dav('PUT', 'quota-two', b'ab')[0] == 201, 'replacement releases net capacity')
    check(client.dav('COPY', 'quota-one', headers={'Destination': client.base + '/dav.php/quota-copy'})[0] == 507,
          'COPY obeys capacity limit')
    check(client.dav('COPY', 'quota-one', headers={'Destination': client.base + '/dav.php/quota-two'})[0] == 507,
          'COPY replacement checks net growth before removing old target')
    check(client.dav('GET', 'quota-two')[2] == b'ab', 'over-quota COPY preserves old target')
    client.dav('MKCOL', 'quota-dir')
    check(client.dav('MOVE', 'quota-one', headers={'Destination': client.base + '/dav.php/quota-dir/moved'})[0] == 201,
          'MOVE works at full quota')
    check(client.api('storage')[1]['used_bytes'] == limit, 'MOVE does not increase accounted bytes')
    client.dav('DELETE', 'quota-two')
    check(client.dav('PUT', 'quota-dir/moved', b'1234567')[0] == 204, 'DELETE releases quota for subsequent upload')
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        codes = list(pool.map(lambda name: client.dav('PUT', name, b'x')[0], ['quota-race-a', 'quota-race-b']))
    check(sorted(codes) == [201, 507], 'concurrent PUT cannot oversubscribe remaining quota')
    check(client.api('storage')[1]['used_bytes'] == limit, 'concurrent accounting remains exact')
    for name in ['quota-race-a', 'quota-race-b', 'quota-dir']:
        client.dav('DELETE', name)
    check(client.api('storage')[1]['used_bytes'] == used, 'recursive DELETE returns usage to baseline')
    client.dav('PUT', 'quota-copy-source', b'abc');client.dav('PUT', 'quota-copy-target', b'x')
    check(client.dav('COPY', 'quota-copy-source', headers={'Destination': client.base + '/dav.php/quota-copy-target'})[0] == 204,
          'COPY replacement within quota succeeds')
    check(client.dav('GET', 'quota-copy-target')[2] == b'abc' and client.api('storage')[1]['used_bytes'] == used + 6,
          'COPY replacement accounts for new and old sizes')
    client.dav('DELETE', 'quota-copy-source');client.dav('DELETE', 'quota-copy-target')

    code, job = client.api('upload-start', {'path': 'quota-chunks', 'size': 6})
    check(code == 200, 'chunk upload reserves capacity')
    info = client.api('storage')[1]
    check(info['used_bytes'] == used and info['reserved_bytes'] == 6 and info['available_bytes'] == 2,
          'pending chunks are reserved, not committed usage')
    check(client.api('upload-start', {'path': 'quota-chunks-over', 'size': 3})[0] == 507,
          'concurrent chunk reservation cannot oversubscribe')
    check(client.dav('PUT', 'quota-reserved-over', b'abc')[0] == 507, 'PUT respects chunk reservations')
    check(client.api('storage-limit', {'limit_bytes': used + 5})[0] == 409, 'limit cannot exclude active reservations')
    check(client.api('upload-cancel', {'id': job['id']})[0] == 200, 'chunk cancellation succeeds')
    check(client.api('storage')[1]['reserved_bytes'] == 0, 'cancellation releases reservation')
    _, job = client.api('upload-start', {'path': 'quota-chunks', 'size': 3})
    check(client.call('PUT', '/?api=upload-part&id=' + job['id'] + '&part=0', b'abc',
                      {'Cookie': client.cookie(), 'X-CSRF-Token': client.csrf}, auth=False)[0] == 200,
          'reserved chunk write succeeds')
    check(client.api('upload-finish', {'id': job['id']})[0] == 200, 'chunk commit updates usage')
    check(client.api('upload-finish', {'id': job['id']})[0] == 200, 'repeat chunk commit remains idempotent')
    info = client.api('storage')[1]
    check(info['used_bytes'] == used + 3 and info['reserved_bytes'] == 0 and info['files'] == files + 1,
          'chunk commit converts reservation exactly once')
    body = b'<d:propfind xmlns:d="DAV:"><d:prop><d:quota-used-bytes/><d:quota-available-bytes/></d:prop></d:propfind>'
    code, _, xml = client.dav('PROPFIND', body=body, headers={'Depth': '0', 'Content-Type': 'application/xml'})
    root = ET.fromstring(xml)
    check(code == 207 and int(root.find('.//{DAV:}quota-used-bytes').text) == used + 3
          and int(root.find('.//{DAV:}quota-available-bytes').text) == 5, 'DAV reports used and available quota')
    client.api('upload-cancel', {'id': job['id']});client.dav('DELETE', 'quota-chunks')

    connection = client.connection()
    connection.request('PUT', '/dav.php/quota-unknown', body=iter([b'ab', b'cd']),
                       headers={'Authorization': client.auth()}, encode_chunked=True)
    response = connection.getresponse(); status = response.status; response.read();connection.close()
    check(status == 201 and client.dav('GET', 'quota-unknown')[2] == b'abcd', 'unknown-length PUT works within quota')
    connection = client.connection()
    connection.request('PUT', '/dav.php/quota-unknown-over', body=iter([b'abc', b'def']),
                       headers={'Authorization': client.auth()}, encode_chunked=True)
    response = connection.getresponse(); status = response.status; response.read();connection.close()
    check(status == 507 and client.dav('GET', 'quota-unknown-over')[0] == 404, 'unknown-length PUT bounded by remaining capacity')
    client.dav('DELETE', 'quota-unknown')
    check(client.api('storage')[1]['reserved_bytes'] == 0, 'failed and completed streams release reservations')
    accounting = state / 'usage.json'
    usage = json.loads(accounting.read_text()); usage.update(used_bytes=999999, files=999, dirty=True)
    accounting.write_text(json.dumps(usage))
    info = client.api('storage')[1]
    check(info['used_bytes'] == used and info['files'] == files, 'dirty accounting recovers exact usage after crash')
    (state / 'files/manual-quota-file').write_bytes(b'12345')
    info = client.api('storage-rescan')[1]
    check(info['used_bytes'] == used + 5 and info['files'] == files + 1, 'explicit rescan includes files added externally')
    client.dav('DELETE', 'manual-quota-file')
    check(client.api('storage-limit', {'limit_bytes': 0})[0] == 200, 'zero restores unlimited capacity')
    info = client.api('storage')[1]
    check(info['used_bytes'] == used and info['files'] == files and info['available_bytes'] is None,
          'usage remains correct with unlimited capacity')
    before = accounting.stat()
    content = accounting.read_bytes()
    client.api('storage'); client.api('list'); client.api('storage')
    after = accounting.stat()
    check(before.st_ino == after.st_ino and before.st_mtime_ns == after.st_mtime_ns
          and accounting.read_bytes() == content, 'read-only usage and listing do not rewrite accounting')
    usage = json.loads(content)
    usage['reservations'] = dict(usage['reservations'])
    usage['reservations']['expired-view'] = {'bytes': 999, 'expires': int(time.time()) - 1}
    accounting.write_text(json.dumps(usage)); content = accounting.read_bytes()
    check(client.api('storage')[1]['reserved_bytes'] == 0, 'read-only usage ignores expired reservations')
    check(accounting.read_bytes() == content, 'view does not persist expired reservation cleanup')
    client.api('storage-limit', {'limit_bytes': 0})



def benchmark(client, state):
    block = bytes(range(256)) * 4096
    mib = 128
    with tempfile.TemporaryFile() as payload:
        digest = hashlib.sha256()
        for _ in range(mib):
            payload.write(block)
            digest.update(block)
        payload.seek(0)
        start = time.perf_counter()
        code, _, _ = client.dav('PUT', 'large.bin', payload, {'Content-Length': str(mib * len(block))})
        upload = time.perf_counter() - start
        check(code == 201, 'large upload')
    start = time.perf_counter()
    connection = client.connection()
    connection.request('GET', '/dav.php/large.bin', headers={'Authorization': client.auth()})
    response = connection.getresponse()
    result = hashlib.sha256()
    length = 0
    while chunk := response.read(1024 * 1024):
        result.update(chunk)
        length += len(chunk)
    download = time.perf_counter() - start
    connection.close()
    check(response.status == 200 and length == mib * len(block) and result.digest() == digest.digest(),
          'large download size and SHA-256 verified')
    check(client.dav('GET', 'large.bin', headers={'Range': f'bytes={100*1024*1024}-{100*1024*1024+4095}'})[2] == block[:4096],
          'large file range at 100 MiB')

    count = 400
    client.dav('MKCOL', 'small-files')
    payload = b'x' * 4096
    start = time.perf_counter()
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
        results = list(pool.map(lambda i: client.dav('PUT', f'small-files/{i:04}.txt', payload)[0], range(count)))
    small = time.perf_counter() - start
    check(all(code == 201 for code in results), '400 concurrent small-file uploads')
    start = time.perf_counter()
    code, _, xml = client.dav('PROPFIND', 'small-files', headers={'Depth': '1'})
    listing = time.perf_counter() - start
    check(code == 207 and len(ET.fromstring(xml).findall('{DAV:}response')) == count + 1, 'complete 400-file PROPFIND')
    first = client.api('list&path=small-files')[1]
    second = client.api('list&path=small-files&offset=200')[1]
    check(len(first['items']) == 200 and first['more'] and len(second['items']) == 200 and not second['more'], '200-item pagination')
    check(len({item['name'] for item in first['items'] + second['items']}) == count, 'pagination does not duplicate static entries')
    print(f'Local benchmark: 128 MiB upload {mib/upload:.1f} MiB/s; verified download {mib/download:.1f} MiB/s')
    print(f'Local benchmark: 400 × 4 KiB upload {count/small:.0f} files/s (8 clients); Depth:1 listing {listing*1000:.1f} ms')
    print('These numbers include loopback transport and OS cache; they are not production throughput guarantees.')


def rclone_check(client):
    check(shutil.which('rclone') is not None, '--rclone requires an installed rclone executable')
    with tempfile.TemporaryDirectory(prefix='webdav-rclone-') as directory:
        root = Path(directory)
        source, output = root / 'source', root / 'download'
        source.mkdir()
        output.mkdir()
        for i in range(100):
            (source / f'{i:03}.txt').write_bytes(b'rclone-integration' * 200)
        block = bytes(range(256)) * 4096
        with (source / 'large.bin').open('wb') as handle:
            for _ in range(64):
                handle.write(block)
        obscured = subprocess.check_output(['rclone', 'obscure', '-'], input=(client.password + '\n').encode()).decode().strip()
        conf = root / 'rclone.conf'
        conf.write_text(f'[private]\ntype = webdav\nurl = {client.base}/dav.php/\nvendor = other\nuser = {USER}\npass = {obscured}\n')
        conf.chmod(0o600)
        prefix = ['rclone', '--config', str(conf), '--log-level', 'ERROR']
        commands = [
            ['copy', str(source), 'private:rclone-test', '--transfers', '8', '--checkers', '16'],
            ['check', str(source), 'private:rclone-test', '--download'],
            ['copy', 'private:rclone-test/large.bin', str(output), '--multi-thread-cutoff', '1M', '--multi-thread-streams', '4'],
        ]
        for command in commands:
            result = subprocess.run(prefix + command, capture_output=True, text=True, timeout=120)
            check(result.returncode == 0, f'rclone {command[0]}: {result.stderr}')
        def digest(path):
            h = hashlib.sha256()
            with path.open('rb') as handle:
                while chunk := handle.read(1024 * 1024):
                    h.update(chunk)
            return h.digest()
        check(digest(source / 'large.bin') == digest(output / 'large.bin'), 'rclone multithread download SHA-256')
        print('PASS: rclone upload, 101-file downloaded content check, 64 MiB multithreaded download')


def run(args, state):
    process = None
    log = None
    if args.url:
        check(args.state is not None, '--url requires --state of an EMPTY test instance')
        url = args.url
    else:
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        url = f'http://127.0.0.1:{port}'
        env = {**os.environ, 'WEBDAV_STATE_DIR': str(state), 'WEBDAV_SETUP_TOKEN': TOKEN,
               'PHP_CLI_SERVER_WORKERS': '4'}
        env.pop('WEBDAV_ACCEL_PREFIX', None)
        log = tempfile.TemporaryFile()
        process = subprocess.Popen(['php', '-d', 'memory_limit=128M', '-d', 'output_buffering=0',
                                    '-S', f'127.0.0.1:{port}', 'tools/router.php'], cwd=ROOT, env=env,
                                   stdout=log, stderr=log, start_new_session=True)
        for _ in range(100):
            try:
                urllib.request.urlopen(url, timeout=0.5).close()
                break
            except (OSError, urllib.error.URLError):
                if process.poll() is not None:
                    raise RuntimeError('PHP test server exited')
                time.sleep(0.05)
    try:
        client = suite(Client(url), state)
        if args.benchmark:
            benchmark(client, state)
        if args.rclone:
            rclone_check(client)
        print(f'PASS: {CHECKS} integration assertions on {url}')
    except Exception:
        if log:
            log.seek(0)
            print(log.read().decode(errors='replace')[-6000:])
        raise
    finally:
        if process:
            os.killpg(process.pid, signal.SIGTERM)
            process.wait(timeout=5)
        if log:
            log.close()


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--benchmark', action='store_true')
    parser.add_argument('--rclone', action='store_true', help='also test rclone upload, content check and multi-thread download')
    parser.add_argument('--url', help='EMPTY disposable test instance, configured with integration-setup-token')
    parser.add_argument('--state', type=Path, help='state directory of that disposable instance')
    args = parser.parse_args()
    if args.state:
        run(args, args.state.resolve())
    else:
        with tempfile.TemporaryDirectory(prefix='webdav-test-') as directory:
            run(args, Path(directory))
