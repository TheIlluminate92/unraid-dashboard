#!/usr/bin/env python3
"""Offline package checks; does not execute the installer or touch an Unraid host."""
import base64
import hashlib
import importlib.util
import os
from pathlib import Path
import re
import subprocess
import tarfile
import tempfile
import unittest
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('rackline_build', ROOT / 'scripts/build.py')
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)


class PackageSafety(unittest.TestCase):
    def test_build(self):
        with tempfile.TemporaryDirectory() as temporary:
            output = Path(temporary)
            builder.build(output)
            first = (output / 'rackline.dashboard.plg').read_bytes()
            builder.build(output)
            self.assertEqual(first, (output / 'rackline.dashboard.plg').read_bytes())
            root = ET.fromstring(first)
            self.assertEqual(root.attrib['name'], 'rackline.dashboard')
            self.assertEqual(root.attrib['launch'], 'Rackline')
            self.assertNotIn('pluginURL', root.attrib)
            scripts = [node.find('INLINE').text for node in root.findall('FILE')]
            for script in scripts:
                subprocess.run(['bash', '-n'], input=script, text=True, check=True)
                self.assertNotRegex(script, r'\brm\b|\breboot\b|\bsystemctl\b|/etc/rc\.')
                self.assertNotIn('/boot/config', script)
            installer = scripts[0]
            self.assertLess(installer.index('for endpoint'), installer.index('mv -- "$runtime"'))
            self.assertLess(installer.index('php -l'), installer.index('mv -- "$runtime"'))
            self.assertIn('previous-runtime', installer)
            self.assertIn('sha256sum -c', installer)
            self.assertIn('cmp "$stage/expected" "$stage/actual"', installer)
            payload = re.search(r"<<'RACKLINE_PAYLOAD'\n(.*?)\nRACKLINE_PAYLOAD", installer, re.S)[1]
            archive_bytes = base64.b64decode(payload)
            self.assertEqual(archive_bytes, (output / 'rackline.dashboard.tgz').read_bytes())
            self.assertIn(hashlib.sha256(archive_bytes).hexdigest(), installer)
            with tarfile.open(output / 'rackline.dashboard.tgz') as archive:
                members = archive.getmembers()
                for member in members:
                    self.assertTrue(member.isfile())
                    self.assertTrue(member.name.startswith('rackline.dashboard/'))
                    self.assertNotIn('..', member.name.split('/'))
                    self.assertEqual(member.mode, 0o644)
                    data = archive.extractfile(member).read()
                    self.assertNotIn(b'@@APP_VERSION@@', data)
                    self.assertNotIn(b'@@RELEASE_VERSION@@', data)
                view = archive.extractfile('rackline.dashboard/include/view.php').read()
                self.assertIn((ROOT / 'VERSION').read_text().strip().encode(), view)
                self.assertIn((ROOT / 'RELEASE').read_text().strip().encode(), view)
                self.assertTrue(set('rackline.dashboard/' + p for p in builder.REQUIRED).issubset(
                    {member.name for member in members}))

    def test_rejects_symlinks_and_missing_required_files(self):
        original = builder.RUNTIME
        with tempfile.TemporaryDirectory() as temporary:
            fixture = Path(temporary) / 'source'
            fixture.mkdir()
            builder.RUNTIME = fixture
            try:
                with self.assertRaises(ValueError):
                    builder.build(Path(temporary) / 'out')
                for filename in builder.REQUIRED:
                    target = fixture / filename
                    target.parent.mkdir(parents=True, exist_ok=True)
                    target.write_text('fixture')
                (fixture / 'escape').symlink_to('/etc/passwd')
                with self.assertRaises(ValueError):
                    builder.build(Path(temporary) / 'out')
            finally:
                builder.RUNTIME = original

    def test_isolated_install_dependency_failure_activation_and_retention(self):
        # Rewrite both absolute host prefixes into a temporary fixture. Never run on /usr or /var.
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            output = base / 'out'
            builder.build(output)
            xml = ET.parse(output / 'rackline.dashboard.plg').getroot()
            script = xml.find('FILE/INLINE').text
            plugins = base / 'plugins'
            locks = base / 'locks'
            plugins.mkdir()
            locks.mkdir()
            runtime = plugins / 'rackline.dashboard'
            runtime.mkdir()
            (runtime / 'predecessor-marker').write_text('retain me')
            script = script.replace('/usr/local/emhttp/plugins', str(plugins)).replace('/var/lock', str(locks))
            target = base / 'inline1-rackline.dashboard.sh'
            target.write_text(script)
            result = subprocess.run(['bash', str(target)], capture_output=True, text=True)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('requires installed WAZ telemetry', result.stdout)
            self.assertEqual((runtime / 'predecessor-marker').read_text(), 'retain me')
            self.assertEqual(list(plugins.glob('.rackline.dashboard.stage.*')), [])
            dependencies = plugins / 'waz.dashboard/include'
            dependencies.mkdir(parents=True)
            for endpoint in ('metrics', 'storage', 'workloads'):
                (dependencies / (endpoint + '.php')).write_text('dependency fixture')
            binaries = base / 'bin'
            binaries.mkdir()
            php = binaries / 'php'
            # Installation mechanics only. Actual PHP syntax/parsing is a separate CI check.
            php.write_text('#!/bin/sh\nexit 0\n')
            php.chmod(0o755)
            env = dict(os.environ, PATH=str(binaries) + os.pathsep + os.environ['PATH'])
            result = subprocess.run(['bash', str(target)], capture_output=True, text=True, env=env)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            retained = list(plugins.glob('.rackline.dashboard.stage.*/previous-runtime/predecessor-marker'))
            self.assertEqual(len(retained), 1)
            self.assertEqual(retained[0].read_text(), 'retain me')
            self.assertTrue((runtime / 'Rackline.page').is_file())
            for endpoint in dependencies.iterdir():
                self.assertEqual(endpoint.read_text(), 'dependency fixture')
            # A failed PHP lint must leave the activated runtime intact.
            php.write_text('#!/bin/sh\nexit 1\n')
            before = (runtime / 'Rackline.page').read_bytes()
            result = subprocess.run(['bash', str(target)], capture_output=True, text=True, env=env)
            self.assertNotEqual(result.returncode, 0)
            self.assertEqual((runtime / 'Rackline.page').read_bytes(), before)
            # Simulate a failure only during activation, after the old runtime was moved.
            php.write_text('#!/bin/sh\nexit 0\n')
            mover = binaries / 'mv'
            mover.write_text('#!/bin/sh\ncase "$2" in */extracted/rackline.dashboard) exit 1;; esac\nexec /bin/mv "$@"\n')
            mover.chmod(0o755)
            (runtime / 'rollback-marker').write_text('restore me')
            result = subprocess.run(['bash', str(target)], capture_output=True, text=True, env=env)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('activation failed', result.stdout)
            self.assertEqual((runtime / 'rollback-marker').read_text(), 'restore me')
            self.assertEqual((runtime / 'Rackline.page').read_bytes(), before)
            # Removal retains Rackline itself and leaves all WAZ fixtures intact.
            removal = xml.findall('FILE')[1].find('INLINE').text
            removal = removal.replace('/usr/local/emhttp/plugins', str(plugins)).replace('/var/lock', str(locks))
            result = subprocess.run(['bash'], input=removal, capture_output=True, text=True, env=env)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            self.assertFalse(runtime.exists())
            removed = list(plugins.glob('.rackline.dashboard.removed.*/runtime/rollback-marker'))
            self.assertEqual(len(removed), 1)
            self.assertEqual(removed[0].read_text(), 'restore me')
            for endpoint in dependencies.iterdir():
                self.assertEqual(endpoint.read_text(), 'dependency fixture')

    def test_collector_read_only(self):
        source = (builder.RUNTIME / 'include/io.php').read_text()
        self.assertEqual(re.findall(r"file_get_contents\('([^']+)'\)", source),
                         ['/proc/diskstats', '/proc/uptime'])
        self.assertNotRegex(source, r'\$_(?:GET|POST|REQUEST)|shell_exec|\bexec\s*\(|\bsystem\s*\(|file_put_contents|fopen|popen')
        self.assertIn("!== 'GET'", source)
        self.assertIn('RACKLINE_IO_NO_MAIN', source)


if __name__ == '__main__':
    unittest.main()
