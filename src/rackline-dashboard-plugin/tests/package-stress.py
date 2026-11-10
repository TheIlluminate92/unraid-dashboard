#!/usr/bin/env python3
"""Isolated package fault injection. Never installs into host paths."""
import base64
import contextlib
import fcntl
import hashlib
import importlib.util
import io
import os
from pathlib import Path
import re
import subprocess
import tarfile
import tempfile
import time
import unittest
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('rackline_build_stress', ROOT / 'scripts/build.py')
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)

class PackageStress(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='rackline-package-stress-')
        self.base = Path(self.temp.name)
        self.plugins, self.locks, self.bin = (self.base / name for name in ('plugins', 'locks', 'bin'))
        for path in (self.plugins, self.locks, self.bin): path.mkdir()
        self.runtime = self.plugins / 'rackline.dashboard'
        self.runtime.mkdir()
        (self.runtime / 'marker').write_text('active predecessor')
        with contextlib.redirect_stdout(io.StringIO()): builder.build(self.base / 'out')
        xml = ET.parse(self.base / 'out/rackline.dashboard.plg').getroot()
        self.install = self.redirect(xml.find('FILE/INLINE').text)
        self.remove = self.redirect(xml.findall('FILE')[1].find('INLINE').text)
        self.archive = (self.base / 'out/rackline.dashboard.tgz').read_bytes()
        (self.bin / 'php').write_text('#!/bin/sh\nexit 0\n')
        (self.bin / 'php').chmod(0o755)
        self.env = dict(os.environ, PATH=str(self.bin) + os.pathsep + os.environ['PATH'])

    def tearDown(self): self.temp.cleanup()
    def redirect(self, script):
        return script.replace('/usr/local/emhttp/plugins', str(self.plugins)).replace('/var/lock', str(self.locks))
    def run_install(self, script=None, name='inline1-rackline.dashboard.sh'):
        target = self.base / name
        target.write_text(self.install if script is None else script)
        return subprocess.run(['bash', str(target)], capture_output=True, text=True, env=self.env, timeout=10)
    def assert_retained(self): self.assertEqual((self.runtime / 'marker').read_text(), 'active predecessor')
    def rebuild_archive_script(self, change):
        result = io.BytesIO()
        with tarfile.open(fileobj=io.BytesIO(self.archive), mode='r:gz') as old, tarfile.open(fileobj=result, mode='w:gz') as new:
            for member in old.getmembers():
                item = change(member)
                if item is not None: new.addfile(item, old.extractfile(member) if item.isfile() else None)
        payload = result.getvalue()
        with tarfile.open(fileobj=io.BytesIO(payload), mode='r:gz') as archive:
            names = '\n'.join(archive.getnames())
        script = self.install.replace(hashlib.sha256(self.archive).hexdigest(), hashlib.sha256(payload).hexdigest())
        script = re.sub(r"(?<=<<'RACKLINE_PAYLOAD'\n).*?(?=\nRACKLINE_PAYLOAD)", base64.encodebytes(payload).decode().strip(), script, flags=re.S)
        return re.sub(r"(?<=<<'RACKLINE_MANIFEST'\n).*?(?=\nRACKLINE_MANIFEST)", names, script, flags=re.S)

    def test_wrong_filename_and_runtime_types_rejected(self):
        result = self.run_install(name='wrong-name.sh')
        self.assertNotEqual(result.returncode, 0); self.assert_retained()
        moved = self.plugins / 'retained'
        self.runtime.rename(moved)
        self.runtime.symlink_to(moved, target_is_directory=True)
        result = self.run_install()
        self.assertNotEqual(result.returncode, 0); self.assertIn('symlink', result.stdout)
        self.assertEqual((moved / 'marker').read_text(), 'active predecessor')
        self.runtime.unlink(); self.runtime.write_text('ordinary file')
        result = self.run_install()
        self.assertNotEqual(result.returncode, 0); self.assertEqual(self.runtime.read_text(), 'ordinary file')

    def test_operation_lock_excludes_install_and_removal(self):
        with (self.locks / 'rackline.dashboard.lock').open('w') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            result = self.run_install()
            self.assertNotEqual(result.returncode, 0); self.assertIn('already in progress', result.stdout)
            result = subprocess.run(['bash'], input=self.remove, capture_output=True, text=True, env=self.env, timeout=5)
            self.assertNotEqual(result.returncode, 0); self.assertIn('already in progress', result.stdout)
            self.assert_retained(); self.assertFalse(list(self.plugins.glob('.rackline.dashboard.stage.*')))

    def test_unsupported_php_stops_before_staging(self):
        (self.bin / 'php').write_text('#!/bin/sh\nexit 1\n')
        result = self.run_install()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('requires PHP 8.1 or newer', result.stdout)
        self.assert_retained()
        self.assertFalse(list(self.plugins.glob('.rackline.dashboard.stage.*')))

    def test_checksum_and_manifest_corruption_leave_runtime_intact(self):
        wrong_digest = self.install.replace(hashlib.sha256(self.archive).hexdigest(), '0' * 64)
        result = self.run_install(wrong_digest)
        self.assertNotEqual(result.returncode, 0); self.assert_retained()
        wrong_manifest = self.install.replace("<<'RACKLINE_MANIFEST'\n", "<<'RACKLINE_MANIFEST'\nwrong-entry\n")
        result = self.run_install(wrong_manifest)
        self.assertNotEqual(result.returncode, 0); self.assert_retained()

    def test_matching_digest_does_not_allow_special_files_or_missing_collectors(self):
        def symlink(member):
            if member.name.endswith('/Rackline.page'):
                replacement = tarfile.TarInfo(member.name); replacement.type = tarfile.SYMTYPE
                replacement.linkname = str(self.base / 'untouched')
                return replacement
            return member
        sentinel = self.base / 'untouched'; sentinel.write_text('sentinel')
        result = self.run_install(self.rebuild_archive_script(symlink))
        self.assertNotEqual(result.returncode, 0); self.assert_retained(); self.assertEqual(sentinel.read_text(), 'sentinel')
        for missing in ('storage.php', 'smart-report.php'):
            result = self.run_install(self.rebuild_archive_script(lambda member: None if member.name.endswith('/include/' + missing) else member))
            self.assertNotEqual(result.returncode, 0); self.assertIn('Incomplete Rackline runtime', result.stdout); self.assert_retained()

    def test_interrupt_after_predecessor_move_restores_active_runtime(self):
        mover = self.bin / 'mv'
        # Signal only the isolated installer's Bash parent, after its predecessor
        # has actually moved. The restoration invocation does not match this path.
        mover.write_text('#!/bin/sh\nif [ "$2" = ' + repr(str(self.runtime)) + ' ]; then\n/bin/mv "$@" || exit $?\nkill -TERM "$PPID"\nexit 0\nfi\nexec /bin/mv "$@"\n')
        mover.chmod(0o755)
        result = self.run_install()
        self.assertEqual(result.returncode, 143, result.stdout + result.stderr)
        self.assert_retained()
        self.assertFalse(list(self.plugins.glob('.rackline.dashboard.stage.*/previous-runtime')))

    def test_repeated_install_and_remove_never_overwrite_predecessors(self):
        for index in range(12):
            marker = f'predecessor-{index}'
            (self.runtime / marker).write_text(str(index))
            result = self.run_install()
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            retained = list(self.plugins.glob(f'.rackline.dashboard.stage.*/previous-runtime/{marker}'))
            self.assertEqual(len(retained), 1); self.assertEqual(retained[0].read_text(), str(index))
        self.assertEqual(len(list(self.plugins.glob('.rackline.dashboard.stage.*/previous-runtime'))), 12)
        for _ in range(2):
            result = subprocess.run(['bash'], input=self.remove, capture_output=True, text=True, env=self.env, timeout=5)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(len(list(self.plugins.glob('.rackline.dashboard.removed.*/runtime'))), 1)
        self.assertFalse(self.runtime.exists())

if __name__ == '__main__':
    started = time.monotonic()
    program = unittest.main(exit=False)
    print(f'Isolated package fault-injection elapsed: {time.monotonic() - started:.3f}s')
    raise SystemExit(0 if program.result.wasSuccessful() else 1)
