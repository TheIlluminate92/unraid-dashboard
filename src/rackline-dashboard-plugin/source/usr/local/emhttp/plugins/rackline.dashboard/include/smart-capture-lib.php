<?php

declare(strict_types=1);

/** Explicit on-demand SMART capture. Inclusion and passive state reads never start a process. */
const RACKLINE_CAPTURE_BYTES = 262144;
const RACKLINE_CAPTURE_STDERR = 16384;
const RACKLINE_CAPTURE_SECONDS = 30.0;
const RACKLINE_CAPTURE_REQUEST_AGE = 600;
const RACKLINE_CAPTURE_FIELDS = ['smType', 'smDevice', 'smPort1', 'smPort2', 'smPort3', 'smGlue'];

function rackline_capture_paths(): array
{
    return ['disks' => '/var/local/emhttp/disks.ini', 'variables' => '/var/local/emhttp/var.ini',
        'all' => '/boot/config/smart-all.cfg', 'one' => '/boot/config/smart-one.cfg',
        'sys' => '/sys/class/block', 'sysDevices' => '/sys/devices', 'dev' => '/dev',
        'boot' => '/proc/sys/kernel/random/boot_id', 'executable' => '/usr/sbin/smartctl',
        'cache' => '/var/local/rackline.dashboard-smart'];
}

function rackline_capture_text(string $text): bool
{
    return preg_match('//u', $text) === 1 && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text);
}

function rackline_capture_read(string $path, int $limit = 1048576): string
{
    $text = @file_get_contents($path, false, null, 0, $limit + 1);
    if ($text === false || strlen($text) > $limit) throw new RuntimeException('source_unavailable');
    return $text;
}

/** No shell or arbitrary native option fragments. First qualification is default ATA/SAS only. */
function rackline_capture_default_options(array $raw, array $configuration): array
{
    if (!in_array($raw['transport'] ?? null, ['ata', 'scsi'], true)) throw new RuntimeException('unsupported_transport');
    $visit = static function (array $values) use (&$visit): void {
        foreach ($values as $key => $value) {
            if (in_array((string)$key, RACKLINE_CAPTURE_FIELDS, true)) throw new RuntimeException('unsupported_controller_options');
            if (is_array($value)) $visit($value);
        }
    };
    $visit($raw);
    foreach ($configuration as $text) {
        if (!rackline_capture_text($text)) throw new RuntimeException('invalid_configuration');
        $parsed = @parse_ini_string($text, true, INI_SCANNER_RAW);
        if (!is_array($parsed)) throw new RuntimeException('invalid_configuration');
        $visit($parsed);
    }
    return [];
}

function rackline_capture_binding_from_parts(string $name, array $raw, array $configuration,
    string $sysPath, string $devNumbers, string $diskseq, string $boot, array $node, bool $partition): array
{
    $identity = rackline_storage_smart_identity($name, $raw);
    $device = $raw['device'] ?? null;
    if ($identity === null || !is_string($device) || !preg_match('/^sd[a-z]{1,3}$/D', $device)
        || !is_string($raw['id'] ?? null) || $raw['id'] === '' || strlen($raw['id']) > 1024
        || ($raw['name'] ?? $name) !== $name || $partition) throw new RuntimeException('invalid_binding');
    // Kernel identifiers stay exact strings, including diskseq values above JS/PHP signed integer range.
    if (!preg_match('/^(0|[1-9][0-9]{0,9}):(0|[1-9][0-9]{0,9})$/D', $devNumbers)
        || !preg_match('/^[1-9][0-9]{0,19}$/D', $diskseq)
        || !preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/D', $boot)
        || !rackline_capture_text($sysPath) || strlen($sysPath) > 4096
        || ($node['block'] ?? false) !== true || ($node['numbers'] ?? null) !== $devNumbers) throw new RuntimeException('invalid_binding');
    $options = rackline_capture_default_options($raw, $configuration);
    // Hash effective controller semantics, not unrelated rapidly changing native counters.
    $controller = hash('sha256', json_encode([$raw['transport'], $options], JSON_THROW_ON_ERROR));
    $parts = [$name, $device, $raw['id'], $identity, $devNumbers, $diskseq, $boot, $sysPath, $controller];
    return ['name' => $name, 'device' => $device, 'identity' => $identity,
        'token' => hash('sha256', "rackline-capture-v1\0" . json_encode($parts, JSON_THROW_ON_ERROR)),
        'boot' => hash('sha256', $boot), 'controller' => $controller,
        'diskseq' => $diskseq, 'numbers' => $devNumbers, 'options' => $options];
}

/** Stat only: never open the block node to identify it. */
function rackline_capture_binding(string $name, array $paths): array
{
    if (PHP_INT_SIZE !== 8) throw new RuntimeException('unsupported_runtime');
    clearstatcache();
    $raw = rackline_smart_report_disk($name, $paths['disks']);
    if ($raw === null) throw new RuntimeException('missing_assignment');
    $device = $raw['device'] ?? '';
    if (!is_string($device) || !preg_match('/^sd[a-z]{1,3}$/D', $device)) throw new RuntimeException('unsupported_device');
    $configuration = [];
    foreach (['variables', 'all', 'one'] as $key) {
        clearstatcache(true, $paths[$key]);
        if ($key !== 'variables' && @lstat($paths[$key]) === false) {
            if (!is_readable(dirname($paths[$key])) || !is_executable(dirname($paths[$key]))) throw new RuntimeException('configuration_unavailable');
            $configuration[$key] = '';
        } else {
            if (is_link($paths[$key])) throw new RuntimeException('invalid_configuration');
            $configuration[$key] = rackline_capture_read($paths[$key]);
        }
    }
    $sys = realpath($paths['sys'] . '/' . $device);
    $base = realpath($paths['sysDevices']);
    if ($sys === false || $base === false || !str_starts_with($sys, $base . '/')) throw new RuntimeException('invalid_sysfs');
    $numbers = trim(rackline_capture_read($sys . '/dev', 64));
    $diskseq = trim(rackline_capture_read($sys . '/diskseq', 64));
    $boot = trim(rackline_capture_read($paths['boot'], 64));
    $nodePath = $paths['dev'] . '/' . $device;
    $stat = @lstat($nodePath);
    if ($stat === false || is_link($nodePath) || ($stat['mode'] & 0170000) !== 0060000) throw new RuntimeException('invalid_node');
    $rdev = $stat['rdev'];
    $major = (($rdev >> 8) & 0xfff) | (($rdev >> 32) & 0xfffff000);
    $minor = ($rdev & 0xff) | (($rdev >> 12) & 0xffffff00);
    $binding = rackline_capture_binding_from_parts($name, $raw, $configuration, $sys, $numbers, $diskseq, $boot,
        ['block' => true, 'numbers' => (string)$major . ':' . (string)$minor], file_exists($sys . '/partition'));
    // Close the metadata collection window before returning a selectable token.
    $again = rackline_smart_report_disk($name, $paths['disks']);
    clearstatcache();
    $nodeAgain = @lstat($nodePath);
    if ($again === null || rackline_storage_smart_identity($name, $again) !== $binding['identity']
        || ($again['transport'] ?? null) !== ($raw['transport'] ?? null)
        || rackline_capture_default_options($again, []) !== $binding['options'] || realpath($paths['sys'] . '/' . $device) !== $sys
        || trim(rackline_capture_read($sys . '/dev', 64)) !== $numbers
        || trim(rackline_capture_read($sys . '/diskseq', 64)) !== $diskseq
        || trim(rackline_capture_read($paths['boot'], 64)) !== $boot
        || $nodeAgain === false || $nodeAgain['rdev'] !== $stat['rdev']
        || $nodeAgain['ino'] !== $stat['ino'] || $nodeAgain['mode'] !== $stat['mode']) throw new RuntimeException('binding_changed');
    return $binding;
}

function rackline_capture_request(string $body): array
{
    if ($body === '' || strlen($body) > 4096) throw new InvalidArgumentException('invalid_request');
    $values = [];
    foreach (explode('&', $body) as $pair) {
        $parts = explode('=', $pair, 2);
        if (count($parts) !== 2 || preg_match('/%(?![0-9a-fA-F]{2})/', $pair)) throw new InvalidArgumentException('invalid_request');
        [$key, $value] = array_map('urldecode', $parts);
        if (isset($values[$key]) || !rackline_capture_text($value)) throw new InvalidArgumentException('invalid_request');
        $values[$key] = $value;
    }
    $keys = ['action', 'name', 'device', 'identity', 'csrf_token'];
    if (($values['action'] ?? '') === 'capture') $keys = array_merge($keys, ['binding', 'requestId', 'wake']);
    sort($keys); $actual = array_keys($values); sort($actual);
    if ($keys !== $actual || !in_array($values['action'], ['prepare', 'capture'], true)
        || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $values['name'])
        || !preg_match('/^sd[a-z]{1,3}$/D', $values['device'])
        || !preg_match('/^[a-f0-9]{64}$/D', $values['identity'])
        || strlen($values['csrf_token']) < 16 || strlen($values['csrf_token']) > 256) throw new InvalidArgumentException('invalid_request');
    if ($values['action'] === 'capture' && (!preg_match('/^[a-f0-9]{64}$/D', $values['binding'])
        || !preg_match('/^[1-9][0-9]{0,11}-[a-f0-9]{32}$/D', $values['requestId'])
        || $values['wake'] !== 'confirmed')) throw new InvalidArgumentException('invalid_request');
    return $values;
}

function rackline_capture_native_auth(): bool
{
    // Native nginx remains the primary authentication boundary. Fail closed if
    // accidentally served without the installed native PHP/session setup.
    if (PHP_SAPI === 'cli' || realpath((string)ini_get('auto_prepend_file')) !== realpath('/usr/local/emhttp/webGui/include/local_prepend.php')
        || !is_file('/usr/local/emhttp/webGui/include/local_prepend.php') || !function_exists('csrf_terminate')
        || !isset($_COOKIE[session_name()])) return false;
    if (session_status() !== PHP_SESSION_ACTIVE && !@session_start()) return false;
    $ok = isset($_SESSION['unraid_login']) && is_numeric($_SESSION['unraid_login']);
    session_write_close();
    return $ok;
}

/** Effective UID from process metadata, without a POSIX-extension dependency. */
function rackline_capture_uid(): int
{
    $status = rackline_capture_read('/proc/self/status', 16384);
    if (!preg_match('/^Uid:[ \t]+[0-9]+[ \t]+([0-9]{1,10})[ \t]/m', $status, $match)
        || (int)$match[1] > 4294967295) throw new RuntimeException('unsupported_runtime');
    return (int)$match[1];
}

function rackline_capture_private_dir(string $directory): void
{
    $parent = dirname($directory);
    clearstatcache();
    $p = @lstat($parent);
    if (!$p || is_link($parent) || ($p['mode'] & 0170000) !== 0040000 || ($p['mode'] & 0022) !== 0
        || $p['uid'] !== rackline_capture_uid() || realpath($parent) !== $parent) throw new RuntimeException('unsafe_cache_parent');
    if (@lstat($directory) === false) {
        $mask = umask(0077);
        try { if (!@mkdir($directory, 0700)) throw new RuntimeException('cache_unavailable'); }
        finally { umask($mask); }
    }
    clearstatcache(true, $directory);
    $s = @lstat($directory);
    if (!$s || is_link($directory) || ($s['mode'] & 0170000) !== 0040000 || ($s['mode'] & 0777) !== 0700
        || $s['uid'] !== rackline_capture_uid()) throw new RuntimeException('unsafe_cache');
}

function rackline_capture_private_file(string $path): ?array
{
    clearstatcache(true, $path);
    $s = @lstat($path);
    if (!$s) return null;
    if (is_link($path) || ($s['mode'] & 0170000) !== 0100000 || ($s['mode'] & 0777) !== 0600
        || $s['uid'] !== rackline_capture_uid() || $s['nlink'] !== 1) throw new RuntimeException('unsafe_cache_file');
    return $s;
}

function rackline_capture_lock(string $path)
{
    rackline_capture_private_file($path);
    $mask = umask(0077);
    try { $handle = @fopen($path, 'c+b'); } finally { umask($mask); }
    if (!$handle) throw new RuntimeException('lock_unavailable');
    $s = rackline_capture_private_file($path);
    $f = fstat($handle);
    if (!$s || !$f || $s['ino'] !== $f['ino'] || $s['dev'] !== $f['dev'] || !flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle); throw new RuntimeException('busy');
    }
    return $handle;
}

function rackline_capture_atomic(string $path, array $value): void
{
    rackline_capture_private_file($path);
    $text = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    if (strlen($text) > 1572864) throw new RuntimeException('cache_too_large');
    $temp = dirname($path) . '/.capture-' . bin2hex(random_bytes(16));
    $mask = umask(0077);
    try { $f = @fopen($temp, 'x+b'); } finally { umask($mask); }
    if (!$f) throw new RuntimeException('cache_unavailable');
    try {
        $offset = 0;
        while ($offset < strlen($text)) {
            $n = fwrite($f, substr($text, $offset));
            if (!$n) throw new RuntimeException('cache_write_failed');
            $offset += $n;
        }
        if (!fflush($f) || !fsync($f)) throw new RuntimeException('cache_write_failed');
        fclose($f); $f = null;
        rackline_capture_private_file($path);
        if (!@rename($temp, $path)) throw new RuntimeException('cache_write_failed');
    } finally { if (is_resource($f)) fclose($f); if (is_file($temp)) @unlink($temp); }
}

function rackline_capture_json(string $path): ?array
{
    if (rackline_capture_private_file($path) === null) return null;
    $text = rackline_capture_read($path, 1572864);
    $value = json_decode($text, true, 20, JSON_THROW_ON_ERROR);
    if (!is_array($value)) throw new RuntimeException('invalid_cache');
    return $value;
}

/** Production caller supplies only the fixed executable and server-derived device. */
function rackline_capture_run(array $argv, float $seconds = RACKLINE_CAPTURE_SECONDS): array
{
    $pipes = [];
    $process = @proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
        '/', ['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin', 'LC_ALL' => 'C', 'LANG' => 'C'], ['bypass_shell' => true]);
    if (!is_resource($process)) return ['kind' => 'failed', 'error' => 'launch_failed'];
    fclose($pipes[0]); stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    $output = $errors = ''; $end = hrtime(true) / 1e9 + $seconds; $exit = null; $reason = null;
    try {
        do {
            foreach ([1, 2] as $index) {
                $chunk = stream_get_contents($pipes[$index], 8192);
                if ($chunk === false) { $reason = 'read_failed'; break; }
                if ($index === 1) $output .= $chunk; else $errors .= $chunk;
            }
            if (strlen($output) > RACKLINE_CAPTURE_BYTES || strlen($errors) > RACKLINE_CAPTURE_STDERR) $reason = 'output_limit';
            $status = proc_get_status($process);
            if (!$status['running']) { $exit = $status['exitcode']; break; }
            if (hrtime(true) / 1e9 >= $end) $reason = 'timeout';
            if ($reason !== null) break;
            usleep(10000);
        } while (true);
        if ($reason !== null) {
            proc_terminate($process, 15);
            $grace = hrtime(true) / 1e9 + 0.5;
            do { $status = proc_get_status($process); if (!$status['running']) break; usleep(10000); }
            while (hrtime(true) / 1e9 < $grace);
            if ($status['running']) { proc_terminate($process, 9); usleep(50000); $status = proc_get_status($process); }
            // Persistent in-flight ledger blocks any further capture if termination is uncertain.
            return ['kind' => $status['running'] ? 'uncertain' : 'failed', 'error' => $reason];
        }
        foreach ([1, 2] as $index) {
            $tail = stream_get_contents($pipes[$index], ($index === 1 ? RACKLINE_CAPTURE_BYTES : RACKLINE_CAPTURE_STDERR) + 1);
            if ($tail === false) return ['kind' => 'failed', 'error' => 'read_failed'];
            if ($index === 1) $output .= $tail; else $errors .= $tail;
        }
        if (strlen($output) > RACKLINE_CAPTURE_BYTES || strlen($errors) > RACKLINE_CAPTURE_STDERR)
            return ['kind' => 'failed', 'error' => 'output_limit'];
        return ['kind' => 'finished', 'exit' => $exit, 'stdout' => $output, 'stderr' => $errors];
    } finally {
        fclose($pipes[1]); fclose($pipes[2]);
        // proc_close can wait for an unkillable kernel-I/O process. Leave its
        // started ledger intact; no subsequent request may launch another one.
        $status = proc_get_status($process);
        if (!$status['running']) proc_close($process);
    }
}

function rackline_capture_result(array $result): array
{
    if (($result['kind'] ?? '') !== 'finished') return ['ok' => false, 'error' => 'capture_' . (($result['kind'] ?? '') === 'uncertain' ? 'uncertain' : 'failed')];
    $exit = $result['exit'] ?? null; $text = $result['stdout'] ?? null;
    if (!is_int($exit) || $exit < 0 || $exit > 255 || ($exit & 7) !== 0) return ['ok' => false, 'error' => 'command_failed'];
    if (!is_string($text) || strlen($text) > RACKLINE_CAPTURE_BYTES || !rackline_capture_text($text)
        || !str_contains($text, 'START OF INFORMATION SECTION') || !preg_match('/START OF (?:READ SMART DATA|SMART DATA) SECTION/', $text))
        return ['ok' => false, 'error' => 'invalid_report'];
    return ['ok' => true, 'text' => $text, 'exitStatus' => $exit, 'hasWarnings' => ($exit & 248) !== 0];
}

function rackline_capture_cached(array $binding, string $directory): ?array
{
    // Reads never create the directory or refresh any cache.
    $parent = dirname($directory); $p = @lstat($parent);
    if (!$p || is_link($parent) || ($p['mode'] & 0170000) !== 0040000 || ($p['mode'] & 0022) !== 0
        || $p['uid'] !== rackline_capture_uid() || realpath($parent) !== $parent
        || !is_readable($parent) || !is_executable($parent)) throw new RuntimeException('unsafe_cache_parent');
    $s = @lstat($directory);
    if (!$s) return null;
    if (is_link($directory) || ($s['mode'] & 0170000) !== 0040000 || ($s['mode'] & 0777) !== 0700
        || $s['uid'] !== rackline_capture_uid() || !is_readable($directory) || !is_executable($directory))
        throw new RuntimeException('unsafe_cache');
    $record = rackline_capture_json($directory . '/' . $binding['token'] . '.json');
    if ($record === null) return null;
    if (($record['schema'] ?? null) !== 1 || ($record['binding'] ?? null) !== $binding['token']
        || ($record['boot'] ?? null) !== $binding['boot'] || !is_int($record['capturedAtMs'] ?? null)
        || $record['capturedAtMs'] <= 0 || !is_string($record['text'] ?? null)
        || ($record['sha256'] ?? null) !== hash('sha256', $record['text'])) throw new RuntimeException('invalid_cache');
    $valid = rackline_capture_result(['kind' => 'finished', 'exit' => $record['exitStatus'] ?? null, 'stdout' => $record['text']]);
    if (!$valid['ok']) throw new RuntimeException('invalid_cache');
    return $record;
}

function rackline_capture_perform(array $request, callable $bind, callable $run, string $directory,
    int $mono, int $wallMs): array
{
    $startedMono = hrtime(true) / 1e9;
    $binding = $bind($request['name']);
    if ($binding['identity'] !== $request['identity'] || $binding['device'] !== $request['device']) return ['status' => 409, 'error' => 'selection_changed'];
    if ($request['action'] === 'prepare') return ['status' => 200, 'binding' => $binding['token'],
        'requestId' => (string)$mono . '-' . bin2hex(random_bytes(16)), 'requiresWakeConfirmation' => true];
    $issued = (int)explode('-', $request['requestId'], 2)[0];
    if ($issued > $mono || $mono - $issued > RACKLINE_CAPTURE_REQUEST_AGE) return ['status' => 409, 'error' => 'request_expired'];
    if ($binding['token'] !== $request['binding']) return ['status' => 409, 'error' => 'selection_changed'];
    rackline_capture_private_dir($directory);
    $global = rackline_capture_lock($directory . '/global.lock'); $deviceLock = null;
    try {
        $deviceLock = rackline_capture_lock($directory . '/' . hash('sha256', $binding['name']) . '.lock');
        $ledgerPath = $directory . '/requests.json';
        $ledger = rackline_capture_json($ledgerPath) ?? ['schema' => 1, 'boot' => $binding['boot'], 'requests' => []];
        if (($ledger['schema'] ?? null) !== 1 || !is_array($ledger['requests'] ?? null)) throw new RuntimeException('invalid_ledger');
        if (($ledger['boot'] ?? null) !== $binding['boot']) $ledger = ['schema' => 1, 'boot' => $binding['boot'], 'requests' => []];
        foreach ($ledger['requests'] as $key => $entry) {
            if (!is_array($entry) || !is_string($key) || !preg_match('/^[1-9][0-9]{0,11}-[a-f0-9]{32}$/D', $key)
                || !is_int($entry['issued'] ?? null) || !is_int($entry['attempted'] ?? null)
                || $entry['issued'] <= 0 || $entry['attempted'] < $entry['issued']
                || !is_string($entry['binding'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $entry['binding'])
                || !in_array($entry['state'] ?? null, ['started', 'completed'], true)
                || $entry['state'] === 'completed' && (!is_array($entry['response'] ?? null)
                    || !is_int($entry['response']['status'] ?? null) || $entry['response']['status'] < 200 || $entry['response']['status'] > 599))
                throw new RuntimeException('invalid_ledger');
            if (($entry['state'] ?? '') === 'started') return ['status' => 409, 'error' => 'previous_capture_uncertain'];
            if ($mono - $entry['issued'] > RACKLINE_CAPTURE_REQUEST_AGE && $mono - $entry['attempted'] >= 60) unset($ledger['requests'][$key]);
        }
        if (isset($ledger['requests'][$request['requestId']])) {
            $previous = $ledger['requests'][$request['requestId']];
            if (($previous['binding'] ?? '') !== $binding['token']) return ['status' => 409, 'error' => 'request_conflict'];
            return $previous['response'];
        }
        if (count($ledger['requests']) >= 128) return ['status' => 429, 'error' => 'request_limit'];
        foreach ($ledger['requests'] as $entry) if (($entry['binding'] ?? '') === $binding['token'] && $mono - $entry['attempted'] < 60)
            return ['status' => 429, 'error' => 'capture_cooldown'];
        $entries = new DirectoryIterator($directory); $count = $bytes = 0;
        foreach ($entries as $entry) { if ($entry->isDot()) continue; $count++; if (!$entry->isLink() && $entry->isFile()) $bytes += $entry->getSize();
            if ($count > 2048 || $bytes > 33554432) return ['status' => 507, 'error' => 'cache_limit']; }
        $current = $bind($request['name']);
        if ($current !== $binding) return ['status' => 409, 'error' => 'selection_changed'];
        $ledger['requests'][$request['requestId']] = ['issued' => $issued, 'attempted' => $mono, 'binding' => $binding['token'], 'state' => 'started'];
        rackline_capture_atomic($ledgerPath, $ledger);
        // The only runner call. No automatic retry after failure or an uncertain outcome.
        $result = $run($binding);
        try { $after = $bind($request['name']); } catch (Throwable $error) { $after = null; }
        $valid = rackline_capture_result($result);
        if (($result['kind'] ?? '') === 'uncertain') return ['status' => 503, 'error' => 'capture_uncertain'];
        if ($after !== $binding) $response = ['status' => 409, 'error' => 'selection_changed'];
        elseif (!$valid['ok']) $response = ['status' => 502, 'error' => $valid['error']];
        else {
            $completedAtMs = $wallMs + (int)floor((hrtime(true) / 1e9 - $startedMono) * 1000);
            $record = ['schema' => 1, 'binding' => $binding['token'], 'boot' => $binding['boot'],
                'capturedAtMs' => $completedAtMs, 'requestId' => $request['requestId'], 'exitStatus' => $valid['exitStatus'],
                'sha256' => hash('sha256', $valid['text']), 'text' => $valid['text']];
            rackline_capture_atomic($directory . '/' . $binding['token'] . '.json', $record);
            $response = ['status' => 200, 'binding' => $binding['token'], 'capturedAtMs' => $completedAtMs,
                'source' => 'rackline_capture',
                'reportUrl' => '/plugins/rackline.dashboard/include/smart-capture-report.php?' . http_build_query(
                    ['name' => $binding['name'], 'device' => $binding['device'], 'identity' => $binding['identity'], 'binding' => $binding['token']], '', '&', PHP_QUERY_RFC3986),
                'exitStatus' => $valid['exitStatus'], 'hasWarnings' => $valid['hasWarnings']];
        }
        $ledger['requests'][$request['requestId']]['state'] = 'completed';
        $ledger['requests'][$request['requestId']]['response'] = $response;
        rackline_capture_atomic($ledgerPath, $ledger);
        return $response;
    } finally { if (is_resource($deviceLock)) { flock($deviceLock, LOCK_UN); fclose($deviceLock); }
        flock($global, LOCK_UN); fclose($global); }
}

function rackline_capture_response(string $method, string $query, string $contentType, string $body,
    bool $authenticated, string $csrf, callable $bind, callable $run, string $directory, int $mono, int $wallMs): array
{
    if ($method !== 'POST') return ['status' => 405, 'error' => 'post_required'];
    if (!$authenticated || strlen($csrf) < 16) return ['status' => 403, 'error' => 'authentication_required'];
    if ($query !== '' || strtolower(trim(explode(';', $contentType, 2)[0])) !== 'application/x-www-form-urlencoded')
        return ['status' => 400, 'error' => 'invalid_request'];
    try {
        $request = rackline_capture_request($body);
        // Read raw bounded form bytes: native prepend consumed $_POST's token.
        if (!hash_equals($csrf, $request['csrf_token'])) return ['status' => 403, 'error' => 'csrf_failed'];
        return rackline_capture_perform($request, $bind, $run, $directory, $mono, $wallMs);
    } catch (InvalidArgumentException $error) { return ['status' => 400, 'error' => 'invalid_request']; }
    catch (Throwable $error) { return ['status' => 503, 'error' => 'capture_unavailable']; }
}

/** Passive retrieval for captures only; never creates files, starts a process or falls back to native caches. */
function rackline_capture_download(string $method, array $query, string $rawQuery, bool $authenticated,
    callable $bind, string $directory): array
{
    if ($method !== 'GET') return ['status' => 405, 'body' => "GET required\n"];
    if (!$authenticated) return ['status' => 403, 'body' => "Authentication required\n"];
    $keys = ['name', 'device', 'identity', 'binding'];
    if (count($query) !== 4 || strlen($rawQuery) > 1024) return ['status' => 400, 'body' => "Invalid report request\n"];
    $seen = [];
    foreach (explode('&', $rawQuery) as $pair) {
        $parts = explode('=', $pair, 2);
        if (count($parts) !== 2 || preg_match('/%(?![0-9a-fA-F]{2})/', $pair)) return ['status' => 400, 'body' => "Invalid report request\n"];
        [$key, $value] = array_map('urldecode', $parts);
        if (!in_array($key, $keys, true) || isset($seen[$key]) || !is_string($query[$key] ?? null)
            || $query[$key] !== $value) return ['status' => 400, 'body' => "Invalid report request\n"];
        $seen[$key] = true;
    }
    if (count($seen) !== 4 || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $query['name'])
        || !preg_match('/^sd[a-z]{1,3}$/D', $query['device'])
        || !preg_match('/^[a-f0-9]{64}$/D', $query['identity']) || !preg_match('/^[a-f0-9]{64}$/D', $query['binding']))
        return ['status' => 400, 'body' => "Invalid report request\n"];
    $missing = ['status' => 404, 'body' => "No current verified capture is available.\n"];
    try {
        $binding = $bind($query['name']);
        if ($binding['device'] !== $query['device'] || $binding['identity'] !== $query['identity'] || $binding['token'] !== $query['binding']) return $missing;
        $record = rackline_capture_cached($binding, $directory);
        if ($record === null || $bind($query['name']) !== $binding) return $missing;
        return ['status' => 200, 'filename' => 'rackline-smart-' . $query['name'] . '.txt',
            'body' => 'SMART report captured for ' . $query['name'] . ' (' . $query['device'] . ")\n"
                . 'Capture completed: ' . gmdate('Y-m-d H:i:s', (int)floor($record['capturedAtMs'] / 1000)) . " UTC\n"
                . "This is a saved on-demand read, not a new query or a self-test.\n"
                . "Capture time is not self-test completion time.\n\n" . $record['text']];
    } catch (Throwable $error) { return ['status' => 503, 'body' => "Saved SMART report is unavailable.\n"]; }
}

function rackline_capture_state(string $method, array $query, string $rawQuery, bool $authenticated,
    callable $bind, string $directory): array
{
    if ($method !== 'GET') return ['status' => 405, 'error' => 'get_required'];
    if (!$authenticated) return ['status' => 403, 'error' => 'authentication_required'];
    $keys = ['name', 'device', 'identity'];
    if (count($query) !== 3 || strlen($rawQuery) > 1024) return ['status' => 400, 'error' => 'invalid_request'];
    $seen = [];
    foreach (explode('&', $rawQuery) as $pair) {
        $parts = explode('=', $pair, 2);
        if (count($parts) !== 2 || preg_match('/%(?![0-9a-fA-F]{2})/', $pair)) return ['status' => 400, 'error' => 'invalid_request'];
        [$key, $value] = array_map('urldecode', $parts);
        if (!in_array($key, $keys, true) || isset($seen[$key]) || !is_string($query[$key] ?? null)
            || $query[$key] !== $value) return ['status' => 400, 'error' => 'invalid_request'];
        $seen[$key] = true;
    }
    // A current NVMe/other locator may be syntactically valid but unsupported.
    if (count($seen) !== 3 || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $query['name'])
        || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $query['device'])
        || !preg_match('/^[a-f0-9]{64}$/D', $query['identity'])) return ['status' => 400, 'error' => 'invalid_request'];
    try {
        $binding = $bind($query['name']);
        if ($binding['device'] !== $query['device'] || $binding['identity'] !== $query['identity'])
            return ['status' => 409, 'error' => 'selection_changed'];
        $record = rackline_capture_cached($binding, $directory);
        if ($bind($query['name']) !== $binding) return ['status' => 409, 'error' => 'selection_changed'];
        $state = ['status' => 200, 'supported' => true, 'available' => $record !== null, 'binding' => $binding['token']];
        if ($record === null) return $state;
        return $state + ['source' => 'rackline_capture', 'capturedAtMs' => $record['capturedAtMs'],
            'exitStatus' => $record['exitStatus'], 'hasWarnings' => ($record['exitStatus'] & 248) !== 0,
            'reportUrl' => '/plugins/rackline.dashboard/include/smart-capture-report.php?' . http_build_query(
                ['name' => $binding['name'], 'device' => $binding['device'], 'identity' => $binding['identity'], 'binding' => $binding['token']], '', '&', PHP_QUERY_RFC3986)];
    } catch (Throwable $error) {
        if (in_array($error->getMessage(), ['unsupported_device', 'unsupported_transport', 'unsupported_controller_options'], true))
            return ['status' => 200, 'supported' => false, 'available' => false, 'reason' => $error->getMessage()];
        return ['status' => 503, 'error' => 'capture_unavailable'];
    }
}
