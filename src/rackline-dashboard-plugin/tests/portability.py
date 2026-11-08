#!/usr/bin/env python3
"""Offline portability matrix. All host data is synthetic; no socket/probe/server."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
INCLUDE = ROOT / 'source/usr/local/emhttp/plugins/rackline.dashboard/include'
PHP = shutil.which('php')
if not PHP:
    raise SystemExit('PHP CLI required; portability checks did not run')


class Portability(unittest.TestCase):
    def php(self, code, minimal=False):
        command, environment = [PHP], dict(os.environ)
        if minimal:
            # Resolve wrappers without assuming their loader/library setup. Run
            # the same interpreter without its ini/optional extension arguments.
            native = subprocess.run([PHP, '-r', "echo json_encode([PHP_BINARY,getenv('LD_LIBRARY_PATH')]);"],
                                    check=True, capture_output=True, text=True, timeout=10)
            binary, libraries = json.loads(native.stdout)
            command = [binary, '-n']
            if libraries:
                environment['LD_LIBRARY_PATH'] = libraries
        result = subprocess.run(command + ['-r', code], env=environment,
                                capture_output=True, text=True, timeout=10)
        if result.returncode == 77:
            self.skipTest(result.stdout.strip())
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(result.stderr, '')
        return json.loads(result.stdout)

    def snapshot(self, root):
        return self.php("define('RACKLINE_METRICS_NO_MAIN',true);require " + json.dumps(str(INCLUDE / 'metrics.php'))
                        + ';echo json_encode(rackline_metrics_snapshot(' + json.dumps(str(root)) + ',123456));')

    def put(self, root, path, value):
        target = root / path.lstrip('/')
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(str(value))

    def test_sparse_multisocket_and_die_collision(self):
        with tempfile.TemporaryDirectory(prefix='rackline-portability-') as temp:
            root = Path(temp)
            # Four logical CPUs, repeated core 0 on two noncontiguous sockets.
            cpus = [(0, 1, 0, 0), (8, 1, 0, 0), (2, 7, 0, 2), (10, 7, 0, 2)]
            self.put(root, '/proc/stat', 'cpu 1 0 0 9\n' + '\n'.join(f'cpu{cpu} 1 0 0 9' for cpu, *_ in cpus))
            for cpu, package, core, die in cpus:
                base = f'/sys/devices/system/cpu/cpu{cpu}/topology'
                for field, value in [('physical_package_id', package), ('core_id', core), ('die_id', die)]:
                    self.put(root, base + '/' + field, value)
            result = self.snapshot(root)['cpu']
            self.assertEqual(result['physicalCoreCount'], 2)
            self.assertEqual([[t['cpuId'] for t in c['threads']] for c in result['cores']], [[0, 8], [2, 10]])
            # Collision, then partially missing die identity: neither can prove SMT.
            for value in ('1', '-1'):
                self.put(root, '/sys/devices/system/cpu/cpu8/topology/die_id', value)
                result = self.snapshot(root)['cpu']
                self.assertIsNone(result['physicalCoreCount'])
                unknown = [c for c in result['cores'] if c['packageId'] == 1]
                self.assertEqual(len(unknown), 2)
                self.assertTrue(all(c['coreId'] is None and len(c['threads']) == 1 for c in unknown))
                self.assertEqual(sorted(t['cpuId'] for c in result['cores'] for t in c['threads']), [0, 2, 8, 10])
            # Older kernel with no die IDs still has its package/core grouping.
            for cpu, *_ in cpus:
                (root / f'sys/devices/system/cpu/cpu{cpu}/topology/die_id').unlink()
            self.assertEqual(self.snapshot(root)['cpu']['physicalCoreCount'], 2)

    def test_renamed_nic_vlan_ipv6_and_no_default_route(self):
        with tempfile.TemporaryDirectory(prefix='rackline-portability-') as temp:
            root = Path(temp)
            for name in ('enp65s0f1', 'bond9', 'br22', 'br22.104'):
                with self.subTest(interface=name):
                    self.put(root, '/proc/net/route', f'{name} 00000000 01000000 0003 0 0 4 00000000')
                    self.put(root, f'/sys/class/net/{name}/ifindex', '12')
                    self.put(root, f'/sys/class/net/{name}/statistics/rx_bytes', '17')
                    self.put(root, f'/sys/class/net/{name}/statistics/tx_bytes', '23')
                    network = self.snapshot(root)['network']
                    self.assertEqual(network['interface'], name)
                    self.assertEqual(network['identity'], name + ':12')
                    self.assertEqual(network['counters']['rxBytes'], 17)
            (root / 'proc/net/route').unlink()
            self.assertIsNone(self.snapshot(root)['network']['interface'])
            self.put(root, '/proc/net/ipv6_route', '0' * 32 + ' 00 ' + '0' * 32 + ' 00 ' + '0' * 32 + ' 00000004 00000000 00000000 00000001 enp65s0f1')
            self.assertEqual(self.snapshot(root)['network']['selection'], 'ipv6-default-route')
            self.put(root, '/boot/config/plugins/rackline.dashboard/rackline.dashboard.cfg', 'PRIMARY_INTERFACE="missing0"')
            network = self.snapshot(root)['network']
            self.assertEqual(network['interface'], 'missing0')
            self.assertIsNone(network['identity'])
            self.assertIsNone(network['counters']['rxBytes'])

    def test_actual_missing_curl_returns_unavailable_not_empty_inventory(self):
        for endpoint in ('workloads.php', 'containers.php'):
            with self.subTest(endpoint=endpoint):
                code = "if(extension_loaded('curl') || function_exists('curl_multi_init')){echo 'cURL remains built in; no absence claim';exit(77);}"
                code += "$_SERVER['REQUEST_METHOD']='GET';$_GET=[];ob_start();require " + json.dumps(str(INCLUDE / endpoint))
                code += ";$data=json_decode(ob_get_clean(),true);echo json_encode(['http'=>http_response_code(),'data'=>$data]);"
                result = self.php(code, minimal=True)
                self.assertEqual(result['http'], 503)
                if endpoint == 'workloads.php':
                    self.assertFalse(result['data']['dockerAvailable'])
                    self.assertNotIn('containers', result['data'])
                else:
                    self.assertEqual(result['data']['status'], 'unavailable')

    def test_empty_daemon_is_distinct_from_unavailable_daemon(self):
        # Injected bounded transport only: no real Docker socket or native paths.
        code = "define('RACKLINE_WORKLOADS_NO_MAIN',true);require " + json.dumps(str(INCLUDE / 'workloads.php')) + ';'
        code += """
$metadata=static fn($items)=>['info'=>[],'status'=>[]];
$empty=rackline_workloads_collect('',static fn($paths,$deadline)=>['inventory'=>['status'=>'ok','data'=>[]]],$metadata,[]);
$failed=false;try{rackline_workloads_collect('',static fn($paths,$deadline)=>['inventory'=>['status'=>'unavailable','data'=>null]],$metadata,[]);}catch(RuntimeException $e){$failed=true;}
echo json_encode(['empty'=>$empty,'failed'=>$failed]);
"""
        result = self.php(code)
        self.assertTrue(result['empty']['dockerAvailable'])
        self.assertEqual(result['empty']['containers'], [])
        self.assertTrue(result['failed'])


if __name__ == '__main__':
    unittest.main()
