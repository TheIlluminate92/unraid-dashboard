#!/usr/bin/env python3
"""Standalone inventory endpoint contract against a synthetic socket and temporary native metadata.

No installed Unraid/WAZ code, Docker daemon, hardware or persistent cache is contacted.
"""
import json
import pathlib
import shutil
import socketserver
import subprocess
import tempfile
import threading

SOURCE = pathlib.Path(__file__).resolve().parents[1] / 'source/usr/local/emhttp/plugins/rackline.dashboard/include'


def require(condition, message):
    if not condition:
        raise AssertionError(message)


def main():
    php = shutil.which('php')
    require(php, 'PHP with cURL is required; no endpoint tests ran')
    probe = subprocess.run([php, '-r', 'exit(function_exists("curl_multi_init") && defined("CURLOPT_UNIX_SOCKET_PATH") ? 0 : 1);'], capture_output=True)
    require(probe.returncode == 0, 'PHP cURL with Unix socket support is required')
    with tempfile.TemporaryDirectory(prefix='rl-workload-') as tmp:
        root = pathlib.Path(tmp)
        sock = str(root / 'socket')
        container_source = (SOURCE / 'containers.php').read_text()
        declaration = "const RACKLINE_CONTAINER_SOCKET = '/var/run/docker.sock';"
        require(container_source.count(declaration) == 1, 'Socket declaration changed; update fixture explicitly')
        (root / 'containers.php').write_text(container_source.replace(declaration, 'const RACKLINE_CONTAINER_SOCKET = ' + json.dumps(sock) + ';'))
        workloads = (SOURCE / 'workloads.php').read_text()
        # Keep this temporary endpoint entirely off installed boot and Docker metadata paths.
        workloads = workloads.replace("'/boot/", "'" + tmp + "/boot/").replace("'/var/lib/docker/", "'" + tmp + "/docker/")
        (root / 'workloads.php').write_text(workloads)
        (root / 'run.php').write_text('''<?php
        $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
        $_SERVER['REQUEST_METHOD'] = $input['method'];
        $_SERVER['DOCUMENT_ROOT'] = __DIR__.'/no-native-library';
        $_GET = $input['query'];
        ob_start(); require __DIR__.'/workloads.php'; $body = ob_get_clean();
        echo json_encode(['code' => http_response_code() ?: 200, 'body' => json_decode($body, true, 512, JSON_THROW_ON_ERROR)], JSON_THROW_ON_ERROR);
        ''')
        identity = 'a' * 64
        inventory = [{'Id': identity, 'Names': ['/alpha'], 'Image': 'example/alpha:latest', 'State': 'running', 'Status': 'Up', 'Labels': {'net.unraid.docker.managed': 'dockerman'}}]
        details = {'Id': identity, 'Name': '/alpha', 'State': {'Running': True, 'Paused': False, 'Restarting': False, 'StartedAt': '2026-11-01T00:00:00Z'}, 'RestartCount': 0}
        routes = {}
        requests = []

        class Handler(socketserver.StreamRequestHandler):
            def handle(self):
                method, path, _ = self.rfile.readline().decode().strip().split(' ', 2)
                while self.rfile.readline() not in (b'\r\n', b'\n', b''):
                    pass
                requests.append((method, path))
                status, body = routes.get(path, (404, {'message': 'unexpected path'}))
                encoded = json.dumps(body).encode()
                self.wfile.write(f'HTTP/1.1 {status} Fixture\r\nContent-Type: application/json\r\nContent-Length: {len(encoded)}\r\nConnection: close\r\n\r\n'.encode() + encoded)

        class Server(socketserver.ThreadingMixIn, socketserver.UnixStreamServer):
            daemon_threads = True

        server = Server(sock, Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()

        def run(method='GET', query=None):
            requests.clear()
            proc = subprocess.run([php, str(root / 'run.php')], input=json.dumps({'method': method, 'query': query or {}}), text=True, capture_output=True, timeout=8)
            require(proc.returncode == 0 and not proc.stderr.strip(), f'Endpoint PHP warning/error: {proc.stderr}')
            result = json.loads(proc.stdout)
            require(all(method == 'GET' and path in routes for method, path in requests), 'Only known local GET routes permitted')
            return result

        try:
            routes['/containers/json?all=true'] = (200, inventory)
            routes[f'/containers/{identity}/json'] = (200, details)
            result = run()
            require(result['code'] == 200 and result['body']['dockerAvailable'], 'Inventory works without native PHP libraries or WAZ files')
            require(requests == [('GET', '/containers/json?all=true')], 'Unselected endpoint is one Docker GET')
            require(result['body']['containers'][0]['menu']['template'] == '' and result['body']['containers'][0]['autostart'] is None, 'Missing metadata remains unknown and never grants ownership')
            require(result['body']['nativeMetadata']['cacheAvailable'] is False, 'Missing native cache is explicit')
            require(result['body']['gpu']['status'] == 'unsupported', 'GPU absence explicit')
            result = run(query={'selected': 'alpha'})
            require(result['code'] == 200 and result['body']['selected']['id'] == identity and not result['body']['selected']['stats']['available'], 'Selected metadata shares identity but never samples stats')
            require(len(requests) == 2, 'Only list and selected inspect')
            result = run(query={'selected': 'Alpha'})
            require(result['body']['selectedStatus'] == 'not_found' and len(requests) == 1, 'Exact selected-name matching')
            details['State']['Running'] = False
            result = run(query={'selected': 'alpha'})
            require(result['body']['selected']['state'] == 'stopped' and not result['body']['selected']['stats']['available'], 'Stopped inspect cannot produce running stats')
            routes[f'/containers/{identity}/json'] = (404, {'message': 'gone'})
            result = run(query={'selected': 'alpha'})
            require(result['code'] == 200 and result['body']['selected'] is None and result['body']['selectedStatus'] == 'unavailable', 'Inspect failure preserves good inventory but no selected details')
            for query in ({'selected': ['alpha']}, {'selected': '../bad'}):
                result = run(query=query)
                require(result['code'] == 400 and not requests, 'Reject malformed query before Docker reads')
            result = run('POST')
            require(result['code'] == 405 and not requests, 'GET-only endpoint')
            routes['/containers/json?all=true'] = (200, {'message': 'daemon error'})
            result = run()
            require(result['code'] == 503 and not result['body']['dockerAvailable'], 'Daemon error cannot become a successful empty list')
            routes['/containers/json?all=true'] = (200, [])
            result = run()
            require(result['code'] == 200 and result['body']['containers'] == [] and result['body']['summary']['total'] == 0, 'An actual empty list remains successful')
            print('Rackline standalone workload endpoint tests passed. No installed services contacted.')
        finally:
            server.shutdown()
            server.server_close()
            thread.join(timeout=3)


if __name__ == '__main__':
    main()
