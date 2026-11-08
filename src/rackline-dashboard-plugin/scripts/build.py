#!/usr/bin/env python3
"""Build a self-contained review PLG; never installs or publishes it."""
import argparse
import base64
import gzip
import hashlib
import io
from pathlib import Path
import re
import tarfile
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
RUNTIME = ROOT / 'source/usr/local/emhttp/plugins/rackline.dashboard'
REQUIRED = ('Rackline.page', 'RacklineDashboard.page', 'include/view.php', 'include/metrics.php', 'include/storage.php', 'include/smart-report.php', 'include/smart-capture-lib.php', 'include/smart-capture.php', 'include/smart-capture-report.php', 'include/smart-capture-state.php', 'include/workloads.php', 'include/io.php', 'include/containers.php',
            'images/rackline.dashboard.svg', 'assets/js/model.js', 'assets/js/app.js', 'assets/css/rackline.css')


def build(output):
    version = (ROOT / 'VERSION').read_text().strip()
    release = (ROOT / 'RELEASE').read_text().strip()
    if not re.fullmatch(r'\d+\.\d+\.\d+', version) or not re.fullmatch(r'\d{4}\.\d{2}\.\d{2}\.\d{2}', release):
        raise ValueError('Invalid version metadata')
    for path in REQUIRED:
        if not (RUNTIME / path).is_file() or not (RUNTIME / path).stat().st_size:
            raise ValueError(f'Missing runtime file: {path}')
    files = []
    for path in sorted(RUNTIME.rglob('*')):
        if path.is_symlink() or (not path.is_dir() and not path.is_file()):
            raise ValueError(f'Links/special files forbidden: {path}')
        if path.is_file():
            name = 'rackline.dashboard/' + path.relative_to(RUNTIME).as_posix()
            if not re.fullmatch(r'rackline\.dashboard/[A-Za-z0-9_./-]+', name) or '..' in name.split('/'):
                raise ValueError('Unsafe archive path')
            files.append((name, path))
    raw = io.BytesIO()
    with tarfile.open(fileobj=raw, mode='w', format=tarfile.USTAR_FORMAT) as archive:
        for name, path in files:
            data = path.read_bytes()
            if path.suffix.lower() in {'.php', '.page', '.js', '.css', '.html', '.svg', '.json', '.txt'}:
                text = data.decode('utf-8')
                text = text.replace('@@APP_VERSION@@', version).replace('@@RELEASE_VERSION@@', release)
                data = text.encode('utf-8')
            info = tarfile.TarInfo(name)
            info.size = len(data)
            info.mode = 0o644
            archive.addfile(info, io.BytesIO(data))
    # GzipFile pins the OS header byte across Python 3.11/3.12/3.13+.
    compressed = io.BytesIO()
    with gzip.GzipFile(fileobj=compressed, mode='wb', filename='', mtime=0) as stream:
        stream.write(raw.getvalue())
    payload = compressed.getvalue()
    installer = (ROOT / 'plugin/install.sh.in').read_text()
    replacements = {'@VERSION@': version, '@RELEASE@': release,
                    '@SHA256@': hashlib.sha256(payload).hexdigest(),
                    '@MANIFEST@': '\n'.join(name for name, _ in files),
                    '@PAYLOAD@': base64.encodebytes(payload).decode().strip()}
    for key, value in replacements.items():
        installer = installer.replace(key, value)
    plg = (ROOT / 'plugin/rackline.dashboard.plg.in').read_text().replace('@INSTALLER@', installer)
    for key, value in replacements.items():
        plg = plg.replace(key, value)
    ET.fromstring(plg)
    output.mkdir(parents=True, exist_ok=True)
    (output / 'rackline.dashboard.plg').write_text(plg)
    (output / 'rackline.dashboard.tgz').write_bytes(payload)
    (output / 'SHA256SUMS').write_text(''.join(
        hashlib.sha256((output / name).read_bytes()).hexdigest() + '  ' + name + '\n'
        for name in ('rackline.dashboard.plg', 'rackline.dashboard.tgz')))
    print(f'Built review artifact: {output / "rackline.dashboard.plg"}')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, default=ROOT / 'dist')
    build(parser.parse_args().output)
