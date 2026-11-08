#!/usr/bin/env python3
"""Offline cached-report HTTP security tests; only temporary synthetic files are read.

The PHP server binds loopback and receives no installed Unraid paths, credentials,
services, device nodes or native WebUI code.
"""
import hashlib
import http.client
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.parse

SOURCE = Path(__file__).resolve().parents[1] / 'source/usr/local/emhttp/plugins/rackline.dashboard/include'


def require(ok, message):
    if not ok:
        raise AssertionError(message)


def main():
    php = os.environ.get('RACKLINE_PHP_BIN') or shutil.which('php')
    require(php, 'PHP CLI required; no cached report tests ran')
    checks = 0
    with tempfile.TemporaryDirectory(prefix='rackline-smart-offline-') as temporary:
        root = Path(temporary)
        native = root / 'native'
        cache = native / 'smart'
        cache.mkdir(parents=True)
        inventory = native / 'disks.ini'
        storage = (SOURCE / 'storage.php').read_text().replace('/var/local/emhttp/', str(native) + '/').replace('/boot/config/', str(root / 'config') + '/')
        (root / 'storage.php').write_text(storage)
        (root / 'smart-report.php').write_text((SOURCE / 'smart-report.php').read_text())
        raw = dict(name='cache', device='fixture-sda', id='MODEL_FIXTURE-A', type='Cache', model='MODEL', status='DISK_OK', size='100')
        valid = 'Serial Number: FIXTURE-A\nSMART overall-health self-assessment test result: PASSED\nPower On Hours: 100\n<script>fixture()</script>\n'
        report = cache / 'cache'

        def ini(disks):
            return ''.join('[' + section + ']\n' + ''.join(k + '=' + json.dumps(v) + '\n' for k, v in disk.items()) for section, disk in disks)

        def reset():
            inventory.write_text(ini([('cache', raw)]))
            if report.is_symlink() or report.exists() and not report.is_dir():
                report.unlink()
            elif report.is_dir():
                report.rmdir()
            report.write_text(valid)
            os.utime(report, (100, 100))

        def meta(disk=raw, name='cache'):
            command = "define('RACKLINE_STORAGE_NO_MAIN',true);require $argv[1];$v=json_decode(stream_get_contents(STDIN),true);echo json_encode(['identity'=>rackline_storage_smart_identity($v['name'],$v['disk']),'smart'=>rackline_storage_cached_smart($v['name'],$v['disk'],$argv[2])],JSON_THROW_ON_ERROR);"
            proc = subprocess.run([php, '-r', command, str(root / 'storage.php'), str(cache)], input=json.dumps({'disk': disk, 'name': name}), text=True, capture_output=True, timeout=5)
            require(proc.returncode == 0 and not proc.stderr, 'Metadata PHP warning/error: ' + proc.stderr)
            return json.loads(proc.stdout)

        reset()
        initial = meta()
        identity = initial['identity']
        query = urllib.parse.urlencode({'name': 'cache', 'identity': identity})
        require(initial['smart']['cachedAtMs'] == initial['smart']['sampledAtMs'] == 100000, 'File mtime, not test time')
        require(initial['smart']['reportUrl'] == '/plugins/rackline.dashboard/include/smart-report.php?' + query, 'Fixed report URL')
        require('FIXTURE-A' not in query and len(identity) == 64, 'Digest, not raw serial, in query')
        require('text' not in initial['smart'], 'Storage snapshot does not send full report')
        with socket.socket() as probe:
            probe.bind(('127.0.0.1', 0))
            port = probe.getsockname()[1]
        with (root / 'php.log').open('w+') as log:
            server = subprocess.Popen([php, '-S', '127.0.0.1:' + str(port), '-t', str(root)], stdout=log, stderr=log)
            try:
                for _ in range(60):
                    require(server.poll() is None, 'Fixture PHP server exited before tests')
                    try:
                        with socket.create_connection(('127.0.0.1', port), timeout=.1):
                            break
                    except OSError:
                        time.sleep(.05)
                else:
                    raise AssertionError('Fixture PHP server failed startup')

                def request(q=query, method='GET', status=200):
                    nonlocal checks
                    conn = http.client.HTTPConnection('127.0.0.1', port, timeout=3)
                    try:
                        conn.request(method, '/smart-report.php?' + q)
                        response = conn.getresponse()
                        body = response.read().decode('utf-8')
                        headers = dict((key.lower(), value) for key, value in response.getheaders())
                        require(response.status == status, f'{method} expected {status}, got {response.status}: {body}')
                        require(headers.get('content-type') == 'text/plain; charset=utf-8', 'Plain-text response')
                        require(headers.get('x-content-type-options') == 'nosniff', 'No content sniffing')
                        require('no-store' in headers.get('cache-control', ''), 'No report caching')
                        require("default-src 'none'; sandbox" == headers.get('content-security-policy'), 'Nonactive content policy')
                        require(headers.get('referrer-policy') == 'no-referrer', 'No query referrer leakage')
                        require(str(root) not in body and '/var/local/' not in body, 'No internal paths')
                        if status != 200:
                            require('FIXTURE-' not in body and '<script>' not in body, 'No report or identity in errors')
                        if status == 405:
                            require(headers.get('allow') == 'GET', 'Explicit method contract')
                        checks += 1
                        return body, headers
                    finally:
                        conn.close()

                before = (hashlib.sha256(report.read_bytes()).hexdigest(), report.stat().st_mtime_ns)
                body, headers = request()
                require(body.endswith(valid), 'Original cached report text returned, not synthesized attributes')
                require('1970-01-01 00:01:40 UTC' in body and 'not a test time' in body, 'Explicit cached time semantics')
                require(headers.get('content-disposition') == 'inline; filename="rackline-smart-cache.txt"', 'Safe filename without serial')
                require(before == (hashlib.sha256(report.read_bytes()).hexdigest(), report.stat().st_mtime_ns), 'Reads never refresh report')
                for method in ('POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'):
                    request(method=method, status=405)
                bad = ['', 'name=cache', 'identity=' + identity, query + '&extra=1', query + '&name=cache',
                       query + '&identity=' + identity, 'name[]=cache&identity=' + identity,
                       'name=cache&identity[]=' + identity, 'name=../cache&identity=' + identity,
                       'name=%2Fetc%2Fpasswd&identity=' + identity, 'name=cache%00&identity=' + identity,
                       'name=cache%0D%0AX-Foo%3Ayes&identity=' + identity, 'name=cache&identity=' + identity.upper(),
                       'name=' + 'a' * 129 + '&identity=' + identity, 'name=cache&identity=invalid',
                       query + '&%6eame=cache', query + ';file=/etc/passwd', query + '&', query + '&source=ignored']
                for q in bad:
                    request(q, status=400)
                request('name=unknown&identity=' + identity, status=404)
                request('name=cache&identity=' + '0' * 64, status=404)
                inventory.unlink()
                request('name[]=cache&identity=' + identity, status=400)
                request(status=503)
                reset()
                for content in ('', 'Drive is in STANDBY mode\n', 'Serial Number: FIXTURE-A\n',
                                valid.replace('FIXTURE-A', 'FIXTURE-B'), valid + 'Serial Number: FIXTURE-A\n',
                                valid + 'Serial Number: FIXTURE-B\n', valid + '\0', valid + 'x' * 262144):
                    report.write_text(content)
                    untrusted = meta()['smart']
                    require(untrusted['available'] is False and untrusted.get('cachePresent') is True, 'Existing regular cache is not absent')
                    require(not any(key in untrusted for key in ('reportUrl', 'health', 'attributes', 'text', 'sampledAtMs')), 'Unverified/invalid reports never project report data')
                    request(status=404)
                # Native logical-name caches can be attribute-only, without a
                # serial header. Their presence/age is not drive identity proof.
                for content in ('  5 Reallocated_Sector_Ct 0x0033 100 100 036 Pre-fail Always - 0\n  9 Power_On_Hours 0x0032 099 099 000 Old_age Always - 123\n',
                                'Elements in grown defect list: 0\n'):
                    report.write_text(content)
                    os.utime(report, (100, 100))
                    unverified = meta()['smart']
                    require(unverified == {'available': False, 'cacheStatus': 'identity_unverified', 'cachePresent': True,
                                           'cachedAtMs': 100000, 'unavailableReason': 'missing_report_identity'}, 'Attribute-only native cache is present with unverified identity and file age only')
                    request(status=404)
                    # A stale device-keyed full report is not a fallback/alias.
                    device_report = cache / raw['device']
                    device_report.write_text(valid)
                    require(meta()['smart'] == unverified, 'Never fall back to a device-keyed report')
                    request(status=404)
                    device_report.unlink()
                reset()
                for content, reason in ((valid.replace('FIXTURE-A', 'FIXTURE-B'), 'report_identity_mismatch'),
                                        (valid + 'Serial Number: FIXTURE-A\n', 'ambiguous_report_identity')):
                    report.write_text(content)
                    os.utime(report, (100, 100))
                    unverified = meta()['smart']
                    require(unverified.get('cacheStatus') == 'identity_unverified' and unverified.get('unavailableReason') == reason
                            and unverified.get('cachedAtMs') == 100000, 'Identity failure remains explicit without losing safe cache age')
                for content in ('', valid + 'x' * 262144):
                    report.write_text(content)
                    unavailable = meta()['smart']
                    require(unavailable.get('cachePresent') is True and unavailable.get('cacheStatus') == 'unavailable'
                            and 'cachedAtMs' not in unavailable, 'Empty/oversized report is present but has no claimed bounded-read timestamp')
                reset()
                report.unlink()
                require(meta()['smart'] == {'available': False, 'cacheStatus': 'missing', 'cachePresent': False}, 'Absent logical cache is explicitly missing')
                request(status=404)
                report.mkdir()
                request(status=404)
                reset()
                outside = root / 'outside.txt'
                outside.write_text(valid)
                report.unlink()
                report.symlink_to(outside)
                require(meta()['smart'] == {'available': False, 'cacheStatus': 'unavailable'}, 'Symlink not a report or verified cache metadata')
                request(status=404)
                report.unlink()
                os.mkfifo(report)
                request(status=404)
                reset()
                for changed in ({**raw, 'id': 'MODEL_FIXTURE-B'}, {**raw, 'device': 'fixture-sdb'},
                                {**raw, 'serial': 'FIXTURE-B'}):
                    inventory.write_text(ini([('cache', changed)]))
                    request(status=404)
                    if changed.get('serial'):
                        changed_query = urllib.parse.urlencode({'name': 'cache', 'identity': meta(changed)['identity']})
                        request(changed_query, status=404)
                reset()
                # Previously, replacement-character serialization made these
                # distinct malformed IDs share the same selection digest.
                former_collision = hashlib.sha256(b'rackline-smart-selection-v1\0' +
                    json.dumps(['cache', 'fixture-sda', 'MODEL_S\ufffd', '', 'MODEL'], separators=(',', ':')).encode()).hexdigest()
                bad_query = urllib.parse.urlencode({'name': 'cache', 'identity': former_collision})
                for invalid in (b'\x80', b'\x81', b'\xc0\xaf', b'\xed\xa0\x80', b'\xf4\x90\x80\x80'):
                    inventory.write_bytes(ini([('cache', {**raw, 'id': 'MODEL_PLACEHOLDER'})]).encode().replace(b'PLACEHOLDER', b'S' + invalid))
                    report.write_bytes(b'Serial Number: S' + invalid + b'\nSMART Health Status: OK\n')
                    request(bad_query, status=503)
                    request(status=503)
                reset()
                # INI parsing must not silently truncate at NUL or ignore binary
                # bytes in other records/comments. Preserve only tab/CR/LF.
                inventory_bytes = ini([('cache', raw)]).encode()
                for control in [value for value in range(32) if value not in (9, 10, 13)] + [127]:
                    for content in (bytes([control]) + inventory_bytes,
                                    inventory_bytes + b'; trailing comment ' + bytes([control]) + b'\n',
                                    inventory_bytes + bytes([control])):
                        inventory.write_bytes(content)
                        request(status=503)
                for invalid in (b'\x80', b'\x81', b'\xc0\xaf', b'\xed\xa0\x80', b'\xf4\x90\x80\x80'):
                    inventory.write_bytes(inventory_bytes + b'; malformed unrelated comment ' + invalid + b'\n')
                    request(status=503)
                reset()
                ambiguous = [ini([('cache', raw), ('other', {**raw, 'device': 'fixture-sdb', 'id': 'MODEL_FIXTURE-B'})]),
                             ini([('cache', raw), ('other', {**raw, 'name': 'other', 'id': 'MODEL_FIXTURE-B'})]),
                             ini([('cache', raw), ('other', {**raw, 'name': 'other', 'device': 'fixture-sdb'})]),
                             ini([('cache', raw), ('cache', raw)]),
                             ini([('cache', raw), ('cache', {**raw, 'id': 'MODEL_NEW', 'device': 'fixture-sdb'})]).replace('[cache]', '[cache] ; inline comment'),
                             '\r[cache]\nname=cache\ntype=Cache\nid=MODEL_OLD\ndevice=fixture-sda\n\r[cache]\nname=cache\ntype=Cache\nid=MODEL_NEW\ndevice=fixture-sdb\n',
                             ini([('cache', raw), ('cache', raw)]).replace('\n', '\r'),
                             ini([('cache', raw), ('cache', raw)]).replace('\n', '\r\n'),
                             '\ufeff' + ini([('cache', raw), ('cache', raw)]),
                             '\ufeff' + ini([('cache', raw), ('cache', raw)]).replace('[cache]', '[cache] ; comment').replace('\n', '\r'),
                             '\ufeff' + ini([('cache', raw), ('cache', raw)]).replace('[cache]', '[cache] ; comment').replace('\n', '\r\n'),
                             '\ufeff\ufeff' + ini([('cache', raw)]),
                             '\n\ufeff' + ini([('cache', raw)]),
                             '[broken\n', 'x' * 1048577,
                             ini([(str(i), {**raw, 'name': 'd' + str(i), 'device': 'dev' + str(i), 'id': 'id' + str(i)}) for i in range(1025)])]
                for content in ambiguous:
                    inventory.write_text(content)
                    request(status=503)
                reset()
                for content in ('Serial number: FIXTURE-A\r\nSMART Health Status: OK\r\n',
                                'Serial Number: FIXTURE-A\nPercentage Used: 4%\nMedia and Data Integrity Errors: 0\n',
                                'Serial Number: FIXTURE-A\n  5 Reallocated_Sector_Ct 0x0033 100 100 036 Pre-fail Always - 7\n'):
                    report.write_text(content)
                    body, _ = request()
                    require(body.endswith(content), 'ATA/NVMe/SAS and CRLF report text preserved')
                reset()
                inventory.write_text(ini([('cache', raw)]).replace('[cache]', '[cache] ; native comment'))
                request()
                inventory.write_bytes(('\ufeff' + ini([('cache', raw)])).encode())
                request()
                for ending in ('\r\n', '\r'):
                    inventory.write_bytes(ini([('cache', raw)]).replace('\n', ending).encode())
                    request()
                reset()
                inventory.write_text(ini([('cache', {**raw, 'serial': 'FIXTURE-A'})]))
                authoritative = meta({**raw, 'serial': 'FIXTURE-A'})['identity']
                request(urllib.parse.urlencode({'name': 'cache', 'identity': authoritative}))
                reset()
                # Cache directory symlinks must not turn the reader into arbitrary file access.
                real_cache = native / 'saved-smart'
                cache.rename(real_cache)
                cache.symlink_to(real_cache, target_is_directory=True)
                request(status=404)
                cache.unlink()
                real_cache.rename(cache)
                log.flush()
                log.seek(0)
                logs = log.read()
                require('PHP Warning' not in logs and 'PHP Fatal' not in logs, 'No PHP warnings or fatals')
                print(f'Cached SMART report: {checks} HTTP cases plus metadata/identity checks passed; synthetic files only.')
            finally:
                server.terminate()
                server.wait(timeout=5)


if __name__ == '__main__':
    main()
