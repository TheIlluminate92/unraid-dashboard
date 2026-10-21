<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

set_time_limit(0);

const WAZ_GPU_CONFIG = '/boot/config/plugins/waz.dashboard/waz.dashboard.cfg';
const WAZ_GPU_STATE_DIR = '/var/run/waz.dashboard';
const WAZ_GPU_STATE_FILE = '/var/run/waz.dashboard/gpu.json';

@mkdir(WAZ_GPU_STATE_DIR, 0755, true);
$instanceLock = @fopen(WAZ_GPU_STATE_DIR . '/gpu-sampler.lock', 'c+');
if ($instanceLock === false || !flock($instanceLock, LOCK_EX | LOCK_NB)) exit(1);

function waz_gpu_write(array $data): void
{
    @mkdir(WAZ_GPU_STATE_DIR, 0755, true);
    $data['sampledAtMs'] = $data['sampledAtMs'] ?? (int) round(microtime(true) * 1000);
    $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($encoded === false) {
        return;
    }
    $temporary = WAZ_GPU_STATE_FILE . '.tmp.' . getmypid();
    if (@file_put_contents($temporary, $encoded, LOCK_EX) !== false) {
        @rename($temporary, WAZ_GPU_STATE_FILE);
    }
    @unlink($temporary);
}

function waz_gpu_config(): array
{
    $config = is_file(WAZ_GPU_CONFIG) ? @parse_ini_file(WAZ_GPU_CONFIG, false, INI_SCANNER_RAW) : [];
    return is_array($config) ? $config : [];
}

function waz_gpu_read(string $path): ?string
{
    $value = @file_get_contents($path);
    return $value === false ? null : trim($value);
}

function waz_gpu_executable(array $paths): ?string
{
    foreach ($paths as $path) {
        if (is_file($path) && is_executable($path)) {
            return $path;
        }
    }
    return null;
}

function waz_gpu_card(array $config): ?string
{
    $configured = trim((string) ($config['GPU_CARD'] ?? 'auto'));
    if ($configured !== '' && strtolower($configured) !== 'auto') {
        $name = basename($configured);
        if (preg_match('/^card\d+$/', $name) && is_dir('/sys/class/drm/' . $name)) {
            return $name;
        }
    }
    foreach (glob('/sys/class/drm/card[0-9]*') ?: [] as $path) {
        if (waz_gpu_read($path . '/device/vendor') !== '0x8086') {
            continue;
        }
        $driver = @realpath($path . '/device/driver');
        if ($driver !== false && basename($driver) === 'i915') {
            return basename($path);
        }
    }
    return null;
}

function waz_gpu_model(string $card): string
{
    $devicePath = @realpath('/sys/class/drm/' . $card . '/device');
    $slot = $devicePath !== false ? basename($devicePath) : '';
    $lspci = waz_gpu_executable(['/usr/bin/lspci', '/sbin/lspci']);
    if ($lspci !== null && preg_match('/^[0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7]$/i', $slot)) {
        $lines = [];
        @exec(escapeshellarg($lspci) . ' -s ' . escapeshellarg($slot) . ' 2>/dev/null', $lines);
        $description = trim(implode(' ', $lines));
        if ($description !== '') {
            $description = preg_replace('/^[0-9a-f:.]+\s+(VGA compatible controller|Display controller):\s*/i', '', $description);
            $description = preg_replace('/\s+\(rev\s+[0-9a-f]+\)$/i', '', (string) $description);
            return trim((string) $description);
        }
    }
    return 'Intel GPU (' . $card . ')';
}

function waz_gpu_extract_objects(string &$buffer): array
{
    $objects = [];
    $start = null;
    $depth = 0;
    $inString = false;
    $escaped = false;
    $lastConsumed = 0;
    $length = strlen($buffer);

    for ($index = 0; $index < $length; $index++) {
        $character = $buffer[$index];
        if ($start === null) {
            if ($character === '{') {
                $start = $index;
                $depth = 1;
                $inString = false;
                $escaped = false;
            }
            continue;
        }
        if ($inString) {
            if ($escaped) {
                $escaped = false;
            } elseif ($character === '\\') {
                $escaped = true;
            } elseif ($character === '"') {
                $inString = false;
            }
            continue;
        }
        if ($character === '"') {
            $inString = true;
        } elseif ($character === '{') {
            $depth++;
        } elseif ($character === '}') {
            $depth--;
            if ($depth === 0) {
                $decoded = json_decode(substr($buffer, $start, $index - $start + 1), true);
                if (is_array($decoded)) {
                    $objects[] = $decoded;
                }
                $lastConsumed = $index + 1;
                $start = null;
            }
        }
    }

    if ($start !== null) {
        $buffer = substr($buffer, $start);
    } elseif ($lastConsumed > 0) {
        $buffer = substr($buffer, $lastConsumed);
    } elseif (strlen($buffer) > 1048576) {
        $buffer = substr($buffer, -65536);
    }
    return $objects;
}

function waz_gpu_busy(array $engine): ?float
{
    $busy = $engine['busy'] ?? null;
    return is_numeric($busy) ? max(0.0, min(100.0, (float) $busy)) : null;
}

function waz_gpu_engine_loads(array $sample): array
{
    $overall = null;
    $video = null;
    foreach (($sample['engines'] ?? []) as $name => $engine) {
        if (!is_array($engine)) {
            continue;
        }
        $busy = waz_gpu_busy($engine);
        if ($busy === null) {
            continue;
        }
        $overall = $overall === null ? $busy : max($overall, $busy);
        if (stripos((string) $name, 'video') !== false) {
            $video = $video === null ? $busy : max($video, $busy);
        }
    }
    return ['overall' => $overall ?? 0.0, 'video' => $video ?? 0.0];
}

function waz_gpu_container(int $pid): ?string
{
    $cgroup = @file_get_contents('/proc/' . $pid . '/cgroup');
    if ($cgroup === false) {
        return null;
    }
    $containerId = null;
    if (preg_match('/(?:docker[-\/]|\/)([a-f0-9]{64})(?:\.scope|\/|$)/i', $cgroup, $match)) {
        $containerId = strtolower($match[1]);
    }
    if ($containerId === null) {
        return null;
    }
    $config = '/var/lib/docker/containers/' . $containerId . '/config.v2.json';
    $decoded = is_file($config) ? json_decode((string) @file_get_contents($config), true) : null;
    $name = is_array($decoded) ? ltrim((string) ($decoded['Name'] ?? ''), '/') : '';
    return $name !== '' ? $name : substr($containerId, 0, 12);
}

function waz_gpu_process_name(int $pid, array $client): string
{
    $command = @file_get_contents('/proc/' . $pid . '/cmdline');
    if ($command !== false && $command !== '') {
        $parts = array_values(array_filter(explode("\0", $command), static fn(string $part): bool => $part !== ''));
        if ($parts) {
            $name = basename($parts[0]);
            if ($name !== '') {
                return $name;
            }
        }
    }
    $name = trim((string) ($client['name'] ?? ''));
    return $name !== '' ? $name : 'PID ' . $pid;
}

function waz_gpu_client_video_busy(array $client): bool
{
    $stack = [[$client, false]];
    while ($stack) {
        [$value, $insideVideo] = array_pop($stack);
        foreach ($value as $key => $item) {
            $isVideo = $insideVideo || stripos((string) $key, 'video') !== false || stripos((string) $key, 'vcs') !== false;
            if (is_array($item)) {
                $stack[] = [$item, $isVideo];
                continue;
            }
            if ($isVideo && is_numeric($item) && (float) $item > 0) {
                return true;
            }
        }
    }
    return false;
}

function waz_gpu_fdinfo_video(int $pid, array &$previous): bool
{
    $active = false;
    $currentKeys = [];
    foreach (glob('/proc/' . $pid . '/fdinfo/*') ?: [] as $path) {
        foreach (@file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (!preg_match('/^drm-engine-([^:]+):\s*(\d+)/i', $line, $match)) {
                continue;
            }
            $engine = strtolower($match[1]);
            $key = $pid . ':' . basename($path) . ':' . $engine;
            $value = (float) $match[2];
            $currentKeys[$key] = true;
            if ((strpos($engine, 'video') !== false || strpos($engine, 'vcs') !== false)
                && isset($previous[$key]) && $value > $previous[$key]) {
                $active = true;
            }
            $previous[$key] = $value;
        }
    }
    foreach (array_keys($previous) as $key) {
        if (strpos($key, $pid . ':') === 0 && !isset($currentKeys[$key])) {
            unset($previous[$key]);
        }
    }
    return $active;
}

function waz_gpu_processes(array $sample, array &$recent, array &$fdinfo, int $recentMilliseconds): array
{
    $now = (int) round(microtime(true) * 1000);
    $clients = $sample['clients'] ?? [];
    if (!is_array($clients)) {
        $clients = [];
    }
    foreach ($clients as $clientId => $client) {
        if (!is_array($client) || !is_numeric($client['pid'] ?? null)) {
            continue;
        }
        $pid = (int) $client['pid'];
        if ($pid <= 0) {
            continue;
        }
        $key = (string) $clientId . ':' . $pid;
        $video = waz_gpu_client_video_busy($client) || waz_gpu_fdinfo_video($pid, $fdinfo);
        $record = [
            'pid' => $pid,
            'name' => waz_gpu_process_name($pid, $client),
            'container' => waz_gpu_container($pid),
            'lastSeenMs' => $now,
            'videoLastSeenMs' => $video ? $now : ($recent[$key]['videoLastSeenMs'] ?? null),
        ];
        $recent[$key] = $record;
    }

    foreach ($recent as $key => $record) {
        if ($now - (int) $record['lastSeenMs'] > $recentMilliseconds) {
            unset($recent[$key]);
        }
    }

    $processes = [];
    $videoProcesses = [];
    foreach ($recent as $record) {
        $public = [
            'pid' => $record['pid'],
            'name' => $record['name'],
            'container' => $record['container'],
            'recent' => $record['lastSeenMs'] < $now,
        ];
        $processes[] = $public;
        if ($record['videoLastSeenMs'] !== null && $now - (int) $record['videoLastSeenMs'] <= $recentMilliseconds) {
            $videoProcesses[] = $public;
        }
    }
    usort($processes, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
    usort($videoProcesses, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
    return ['all' => $processes, 'video' => $videoProcesses];
}

$stop = false;
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$stop): void { $stop = true; });
    pcntl_signal(SIGINT, static function () use (&$stop): void { $stop = true; });
}

$config = waz_gpu_config();
$recentMilliseconds = max(1000, (int) ($config['GPU_RECENT_SECONDS'] ?? 5) * 1000);
$executable = waz_gpu_executable(['/usr/bin/intel_gpu_top', '/usr/local/bin/intel_gpu_top']);
$card = waz_gpu_card($config);

if ($executable === null || $card === null) {
    waz_gpu_write([
        'available' => false,
        'reason' => $executable === null ? 'intel_gpu_top is not installed' : 'Intel i915 GPU not found',
    ]);
    exit(0);
}

$model = waz_gpu_model($card);
$recent = [];
$fdinfo = [];

while (!$stop) {
    // An argv array starts the child directly, so termination targets the
    // sampler rather than an intermediate shell that could orphan it.
    $command = [$executable, '-d', 'drm:/dev/dri/' . $card, '-J', '-s', '1000'];
    $pipes = [];
    $process = @proc_open($command, [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['file', '/dev/null', 'a'],
    ], $pipes);
    if (!is_resource($process)) {
        waz_gpu_write(['available' => false, 'model' => $model, 'reason' => 'Unable to start GPU watcher']);
        sleep(2);
        continue;
    }

    stream_set_blocking($pipes[1], false);
    $buffer = '';
    while (!$stop) {
        $read = [$pipes[1]];
        $write = null;
        $except = null;
        @stream_select($read, $write, $except, 2, 0);
        $chunk = stream_get_contents($pipes[1]);
        if ($chunk !== false && $chunk !== '') {
            $buffer .= $chunk;
            foreach (waz_gpu_extract_objects($buffer) as $sample) {
                if (!isset($sample['engines'])) {
                    continue;
                }
                $loads = waz_gpu_engine_loads($sample);
                $clients = waz_gpu_processes($sample, $recent, $fdinfo, $recentMilliseconds);
                waz_gpu_write([
                    'available' => true,
                    'model' => $model,
                    'device' => $card,
                    'loadPercent' => round($loads['overall'], 1),
                    'videoLoadPercent' => round($loads['video'], 1),
                    'processes' => $clients['all'],
                    'videoProcesses' => $clients['video'],
                ]);
            }
        }
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
    }
    @fclose($pipes[1]);
    @proc_terminate($process);
    $deadline = microtime(true) + 2;
    do { $childStatus = proc_get_status($process); if (!$childStatus['running']) break; usleep(50000); } while (microtime(true) < $deadline);
    if ($childStatus['running']) @proc_terminate($process, 9);
    @proc_close($process);
    if (!$stop) {
        sleep(2);
    }
}
