#!/usr/bin/env python3
"""Package the management app, dedicated DAV entry and runtime dependencies."""
import hashlib
import json
from pathlib import Path
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parents[1]
DIST = ROOT / 'dist'


def build():
    if not (ROOT / 'vendor/autoload.php').is_file():
        raise SystemExit('Run composer install --no-dev --optimize-autoloader first.')
    subprocess.run(['php', '-l', str(ROOT / 'index.php')], check=True)
    subprocess.run(['php', '-l', str(ROOT / 'dav.php')], check=True)
    DIST.mkdir(exist_ok=True)
    archive = DIST / 'QinDav.zip'
    with zipfile.ZipFile(archive, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as bundle:
        bundle.write(ROOT / 'index.php', 'index.php')
        bundle.write(ROOT / 'dav.php', 'dav.php')
        for path in sorted((ROOT / 'vendor').rglob('*')):
            if path.is_file() and not path.is_symlink():
                bundle.write(path, path.relative_to(ROOT).as_posix())
        for entry in bundle.infolist():
            entry.create_system = 3
            entry.external_attr = 0o100644 << 16
    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    (DIST / 'SHA256SUMS').write_text(f'{digest}  {archive.name}\n')
    with zipfile.ZipFile(archive) as bundle:
        if bundle.testzip() is not None:
            raise SystemExit('Archive verification failed.')
        entries = len(bundle.namelist())
    print(json.dumps({'artifact': str(archive), 'bytes': archive.stat().st_size,
                      'sha256': digest, 'entries': entries}, ensure_ascii=False))


if __name__ == '__main__':
    build()
