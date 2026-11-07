#!/usr/bin/env python3
"""Exercise PHP cURL against a disposable synthetic Unix HTTP socket, never Docker.

Requires PHP with cURL. Missing dependencies fail explicitly (CI must install them).
"""
import json
import pathlib
import shutil
import socketserver
import subprocess
import tempfile
import threading
import time

SOURCE = pathlib.Path(__file__).resolve().parents[1] / 'source/usr/local/emhttp/plugins/rackline.dashboard/include/containers.php'


def require(condition, message):
    if not condition:
        raise AssertionError(message)


def main():
    php = shutil.which('php')
    if not php:
        raise SystemExit('FAIL: PHP is required for synthetic container transport tests; no tests ran.')
    probe = subprocess.run([php, '-r', 'exit(function_exists("curl_multi_init") && defined("CURLOPT_UNIX_SOCKET_PATH") ? 0 : 1);'], capture_output=True, text=True)
    if probe.returncode:
        raise SystemExit('FAIL: PHP cURL with Unix socket support is required; no transport tests ran.')

    # Short directory name stays below Unix socket path limits.
    with tempfile.TemporaryDirectory(prefix='rl-transport-') as tmp:
        root = pathlib.Path(tmp)
        socket_path = str(root / 'http.sock')
        source = SOURCE.read_text()
        old = "const RACKLINE_CONTAINER_SOCKET = '/var/run/docker.sock';"
        require(source.count(old) == 1, 'Production socket declaration changed; update fixture explicitly')
        fixture = root / 'containers.php'
        fixture.write_text(source.replace(old, 'const RACKLINE_CONTAINER_SOCKET = ' + json.dumps(socket_path) + ';'))
        runner = root / 'run.php'
        runner.write_text('''<?php
        declare(strict_types=1);
        define('RACKLINE_CONTAINERS_NO_MAIN', true);
        require __DIR__.'/containers.php';
        $input=json_decode(stream_get_contents(STDIN),true);
        $result=rackline_container_transport($input['paths'],hrtime(true)/1e9+$input['budget']);
        echo json_encode($result,JSON_THROW_ON_ERROR);
        ''')
        lock = threading.Lock()
        observed = {'active': 0, 'peak': 0, 'requests': []}
        modes = {}
        allowed = set()

        class Handler(socketserver.StreamRequestHandler):
            def handle(self):
                line = self.rfile.readline(8192).decode('ascii', errors='replace').strip()
                parts = line.split(' ')
                method = parts[0] if parts else ''
                path = parts[1] if len(parts) > 1 else ''
                while True:
                    header = self.rfile.readline(8192)
                    if header in (b'\r\n', b'\n', b''):
                        break
                with lock:
                    observed['active'] += 1
                    observed['peak'] = max(observed['peak'], observed['active'])
                    observed['requests'].append((method, path))
                    mode = modes.get(path, 'unexpected')
                try:
                    status = 200
                    if mode == 'slow':
                        time.sleep(1.5)
                    else:
                        # Enough overlap to prove multi transport actually runs concurrently.
                        time.sleep(0.04)
                    if mode == 'error':
                        status, body = 500, b'{"message":"fixture error"}'
                    elif mode == 'malformed':
                        body = b'{broken'
                    elif mode == 'oversized':
                        body = b'{"padding":"' + b'x' * 1048576 + b'"}'
                    elif mode == 'unexpected':
                        status, body = 404, b'{}'
                    else:
                        body = b'{"memory_stats":{"usage":0}}'
                    head = f'HTTP/1.1 {status} Fixture\r\nContent-Type: application/json\r\nContent-Length: {len(body)}\r\nConnection: close\r\n\r\n'.encode()
                    self.wfile.write(head + body)
                    self.wfile.flush()
                except (BrokenPipeError, ConnectionResetError):
                    pass  # Expected when the client cancels a slow or oversized response.
                finally:
                    with lock:
                        observed['active'] -= 1

        class Server(socketserver.ThreadingMixIn, socketserver.UnixStreamServer):
            daemon_threads = False
            block_on_close = True

        server = Server(socket_path, Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()

        def path(index):
            return '/containers/' + f'{index:064x}' + '/stats?stream=false&one-shot=true'

        def run_case(name, mode_list, budget=3.5):
            # Keep each case independent, including timed-out server handlers.
            end = time.monotonic() + 4
            while True:
                with lock:
                    active = observed['active']
                if not active:
                    break
                require(time.monotonic() < end, 'Synthetic handlers did not drain')
                time.sleep(0.01)
            paths = {str(i): path(i + 1) for i in range(len(mode_list))}
            with lock:
                modes.clear()
                modes.update({paths[str(i)]: mode for i, mode in enumerate(mode_list)})
                allowed.clear()
                allowed.update(paths.values())
                observed.update(active=0, peak=0, requests=[])
            start = time.monotonic()
            result = subprocess.run([php, str(runner)], input=json.dumps({'paths': paths, 'budget': budget}), text=True, capture_output=True, timeout=8)
            elapsed = time.monotonic() - start
            require(result.returncode == 0, f'{name}: PHP failed: {result.stderr}')
            require(not result.stderr.strip(), f'{name}: unexpected PHP warning: {result.stderr}')
            decoded = json.loads(result.stdout)
            # PHP encodes numeric-key arrays as a list only when contiguous.
            data = {str(i): value for i, value in enumerate(decoded)} if isinstance(decoded, list) else decoded
            with lock:
                requests = list(observed['requests'])
                peak = observed['peak']
            require(len(data) == len(paths), f'{name}: missing per-request outcome')
            require(peak <= 4, f'{name}: concurrency exceeded four: {peak}')
            require(len(requests) <= len(paths), f'{name}: extra HTTP calls')
            require(len(set(requests)) == len(requests), f'{name}: unexpected repeated HTTP calls')
            require(all(method == 'GET' and url in allowed for method, url in requests), f'{name}: non-GET or unexpected route')
            print(f'{name}: calls={len(requests)}, peak={peak}, elapsed={elapsed:.3f}s')
            return data, elapsed, requests, peak

        try:
            for count in (0, 1, 12):
                data, _, requests, peak = run_case(f'fast-{count}', ['ok'] * count)
                require(all(value['status'] == 'ok' for value in data.values()), 'Fast samples failed')
                require(len(requests) == count, 'Fast case skipped a path')
                if count == 12:
                    require(peak >= 2, 'Multi transport did not execute concurrently')
            data, _, _, _ = run_case('partial-errors', ['ok', 'error', 'malformed', 'ok'])
            require([data[str(i)]['status'] for i in range(4)] == ['ok', 'unavailable', 'unavailable', 'ok'], 'HTTP/JSON errors contaminated successful peers')
            data, _, _, _ = run_case('oversized', ['oversized', 'ok'])
            require(data['0']['status'] == 'oversized' and data['1']['status'] == 'ok', 'Body limit not enforced independently')
            data, elapsed, _, _ = run_case('request-timeout', ['slow'], budget=3.5)
            require(data['0']['status'] == 'timeout', 'Per-request timeout missing')
            require(elapsed < 2.5, 'Per-request timeout exceeded conservative fixture tolerance')
            data, elapsed, requests, _ = run_case('deadline-queue', ['slow'] * 12, budget=0.25)
            require(elapsed < 1.25, 'Shared deadline exceeded conservative fixture tolerance')
            statuses = [value['status'] for value in data.values()]
            require('timeout' in statuses and 'deferred' in statuses, 'Deadline must distinguish in-flight timeout and queued deferral')
            require(len(requests) <= 4, 'Queued requests started after total deadline')
            data, _, requests, _ = run_case('expired-deadline', ['ok'] * 12, budget=-1)
            require(not requests and all(value['status'] == 'deferred' for value in data.values()), 'Expired deadline made HTTP calls')
            print('Synthetic Unix socket transport tests passed. No Docker or live server was contacted.')
        finally:
            server.shutdown()
            server.server_close()
            thread.join(timeout=3)


if __name__ == '__main__':
    main()
