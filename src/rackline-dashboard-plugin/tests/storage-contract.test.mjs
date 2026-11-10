import test from 'node:test';
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {readFileSync, mkdtempSync, writeFileSync, rmSync} from 'node:fs';
import {spawnSync} from 'node:child_process';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';
const require = createRequire(import.meta.url);
const M = require('../source/usr/local/emhttp/plugins/rackline.dashboard/assets/js/model.js');
const endpoint = fileURLToPath(new URL('../source/usr/local/emhttp/plugins/rackline.dashboard/include/storage.php', import.meta.url));
const source = readFileSync(endpoint, 'utf8');
const php = process.env.RACKLINE_PHP_BIN || 'php';
const hasPhp = spawnSync(php, ['-v'], {encoding: 'utf8'}).status === 0;

test('standalone storage uses only native cached sources and has no command or write path', () => {
  assert.doesNotMatch(source, /waz[._/]|WAZ_/i);
  assert.doesNotMatch(source, /\b(?:require|include)(?:_once)?\s*(?:\(|['"$])|\$_(?:GET|POST|REQUEST|COOKIE)\b/);
  assert.doesNotMatch(source, /\b(?:shell_exec|exec|system|passthru|popen|proc_open|file_put_contents|fwrite|fopen|mkdir|unlink|rename|copy|touch|chmod|disk_free_space|disk_total_space|readlink)\s*\(/i);
  assert.doesNotMatch(source, /\b(?:smartctl|hdparm|sdparm|lsblk|udevadm|curl_init)\b/);
  assert.doesNotMatch(source, /(?:https?|ftp):\/\/|\/mnt\/|\/dev\//);
  for (const path of ['/var/local/emhttp/disks.ini', '/var/local/emhttp/var.ini', '/var/local/emhttp/smart', '/boot/config/smart-one.cfg', '/boot/config/pools', '/boot/config/plugins/disklocation', '/boot/config/plugins/dynamix/dynamix.cfg', '/boot/config/plugins/dynamix/parity-check.cron'])
    assert(source.includes(path), `Expected native source ${path}`);
  assert(source.includes("const RACKLINE_STORAGE_PLUGIN_VERSION = '@@RELEASE_VERSION@@'"));
  assert.match(source, /rackline_storage_serial_matches\(\$[A-Za-z_][A-Za-z0-9_]*(?:\[[^\]]+\])*, \$raw\)/);
  assert(source.includes("header('Allow: GET')"));
});

test('storage fixture suite and serialized snapshot satisfy the frontend contract', {skip: !hasPhp && 'PHP CLI required'}, () => {
  const fixture = fileURLToPath(new URL('./storage-parser.php', import.meta.url));
  const result = spawnSync(php, [fixture, '--json'], {encoding: 'utf8'});
  assert.equal(result.status, 0, result.stderr + result.stdout);
  const snapshot = JSON.parse(result.stdout);
  assert(M.validPayload('storage', snapshot));
  const root = snapshot.array.disks[0];
  const cells = snapshot.locations.groups[0].cells;
  assert.equal(root.smart.available, true);
  assert.equal(root.smart.health, null);
  assert.equal(cells[0].disk.location, undefined);
  assert.notEqual(cells[0].sourceTray, cells[0].tray);
  assert.equal(cells[1].tray, null);
  assert.equal(snapshot.array.disks[2].location, null);
  assert.equal(cells[2].present, true);
  assert.equal(cells[2].disk, null);
  assert(M.drives(snapshot).some(d => d.name === root.name && d.location.tray === 0));
  // Current and future partial cached layouts are valid without invented inventory.
  cells.push({tray: 99, sourceTray: 100, present: true, hidden: false, inventory: null, disk: null});
  assert(M.validPayload('storage', snapshot));
});

test('endpoint rejects mutations and reports missing native metadata instead of fabricated health', {skip: !hasPhp && 'PHP CLI required'}, () => {
  const directory = mkdtempSync(join(tmpdir(), 'rackline-storage-http-'));
  try {
    // Run the actual endpoint with every native path redirected into an empty,
    // isolated fixture tree. This cannot read installed-host state.
    const isolated = source.replaceAll('/var/local/emhttp/', directory + '/native/')
      .replaceAll('/boot/config/', directory + '/config/');
    const path = join(directory, 'storage.php');
    writeFileSync(path, isolated);
    for (const method of ['POST', 'PUT', 'DELETE', 'GET']) {
      const code = `register_shutdown_function(function () { echo "\\nHTTP:" . http_response_code(); }); $_SERVER['REQUEST_METHOD']='${method}'; require ${JSON.stringify(path)};`;
      const result = spawnSync(php, ['-r', code], {encoding: 'utf8'});
      assert.equal(result.status, 0, result.stderr);
      const [json, status] = result.stdout.trim().split('\nHTTP:');
      const data = JSON.parse(json);
      assert.equal(Number(status), method === 'GET' ? 503 : 405);
      assert.equal(data.schemaVersion, 1);
      assert.equal(data.error, method === 'GET' ? 'Unable to read Rackline Storage telemetry' : 'GET required');
      assert(!M.validPayload('storage', data));
    }
  } finally {
    rmSync(directory, {recursive: true, force: true});
  }
});
