#!/usr/bin/env python3
"""Package the single PHP entry and runtime dependencies."""
import hashlib
import json
import argparse
import re
from pathlib import Path
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parents[1]
DIST = ROOT / 'dist'


def build(version=None):
    if not (ROOT / 'vendor/autoload.php').is_file():
        raise SystemExit('Run composer install --no-dev --optimize-autoloader first.')
    subprocess.run(['php', '-l', str(ROOT / 'index.php')], check=True)
    DIST.mkdir(exist_ok=True)
    archive = DIST / 'QinDav.zip'
    source = (ROOT / 'index.php').read_text()
    if version is not None:
        if not re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+', version):
            raise SystemExit('Version must be X.Y.Z')
        source, count = re.subn(r"const QINDAV_VERSION = '[0-9]+\.[0-9]+\.[0-9]+';",
                               f"const QINDAV_VERSION = '{version}';", source)
        if count != 1:
            raise SystemExit('Application version constant missing or duplicated')
    with zipfile.ZipFile(archive, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as bundle:
        bundle.writestr('index.php', source)
        for path in sorted((ROOT / 'vendor').rglob('*')):
            if path.is_file() and not path.is_symlink():
                bundle.write(path, path.relative_to(ROOT).as_posix())
        for entry in bundle.infolist():
            entry.create_system = 3
            entry.external_attr = 0o100644 << 16
    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    (DIST / 'SHA256SUMS').write_text(f'{digest}  {archive.name}\n')
    with zipfile.ZipFile(archive) as bundle:
        if any(name != 'index.php' and not name.startswith('vendor/') for name in bundle.namelist()):
            raise SystemExit('Package must contain only index.php and vendor/.')
        if bundle.testzip() is not None:
            raise SystemExit('Archive verification failed.')
        entries = len(bundle.namelist())
    print(json.dumps({'artifact': str(archive), 'bytes': archive.stat().st_size,
                      'sha256': digest, 'entries': entries}, ensure_ascii=False))


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--version', help='Stamp the deployment package version (X.Y.Z)')
    build(parser.parse_args().version)
