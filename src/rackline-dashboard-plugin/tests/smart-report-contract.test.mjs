import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
const path = '../source/usr/local/emhttp/plugins/rackline.dashboard/include/';
const storage = readFileSync(fileURLToPath(new URL(path + 'storage.php', import.meta.url)), 'utf8');
const report = readFileSync(fileURLToPath(new URL(path + 'smart-report.php', import.meta.url)), 'utf8');

test('cached report code has no device command, cache generation, or native page dependency', () => {
  for (const source of [storage, report]) {
    assert.doesNotMatch(source, /\b(?:shell_exec|exec|system|passthru|popen|proc_open|file_put_contents|fwrite|fopen|mkdir|unlink|rename|copy|touch|chmod|disk_free_space|disk_total_space)\s*\(/i);
    assert.doesNotMatch(source, /\b(?:smartctl|hdparm|sdparm|lsblk|udevadm|curl_init)\b|\/Main\/Device|SmartInfo\.php|Download\.php|\/dev\//);
  }
  assert.match(report, /require_once __DIR__ \. '\/storage\.php'/);
  assert.match(report, /Content-Type: text\/plain; charset=utf-8/);
  assert.match(report, /X-Content-Type-Options: nosniff/);
  assert.match(report, /Cache-Control: private, no-store/);
  assert.match(report, /rackline_storage_sources\(\)/);
  assert.match(storage, /'cachedAtMs' => \$before\['mtime'\] \* 1000/);
  assert.match(storage, /smart-report\.php\?name=/);
});
