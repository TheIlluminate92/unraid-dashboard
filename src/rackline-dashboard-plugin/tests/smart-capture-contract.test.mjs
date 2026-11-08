import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const base = new URL('../source/usr/local/emhttp/plugins/rackline.dashboard/include/', import.meta.url);
const read = name => readFileSync(new URL(name, base), 'utf8');
const library = read('smart-capture-lib.php'), action = read('smart-capture.php');
test('active SMART reader is separate, explicit, fixed-target and shell-free', () => {
  assert.match(action, /rackline_capture_response/);
  assert.match(action, /\['executable'\], '-x'/);
  assert.match(library, /'executable' => '\/usr\/sbin\/smartctl'/);
  assert.match(library, /proc_open\(\$argv/);
  assert.match(library, /'bypass_shell' => true/);
  assert.doesNotMatch(library + action, /\b(?:shell_exec|exec|system|passthru|popen)\s*\(|SmartInfo\.php|Download\.php/);
  assert.doesNotMatch(action, /\$_(?:GET|POST)\[/);
  assert.match(library, /\$values\['wake'\] !== 'confirmed'/);
  assert.match(library, /hash_equals\(\$csrf, \$request\['csrf_token'\]\)/);
  assert.match(library, /\$entry\['attempted'\]/);
  assert.match(library, /previous_capture_uncertain/);
});
test('state and report adapters are authenticated passive reads', () => {
  for (const name of ['smart-capture-state.php','smart-capture-report.php']) {
    const source = read(name);
    assert.match(source, /rackline_capture_native_auth\(\)/);
    assert.doesNotMatch(source, /rackline_capture_(?:run|perform|atomic|private_dir|lock)\s*\(/);
    assert.match(source, /Cache-Control: private, no-store/);
    assert.match(source, /X-Content-Type-Options: nosniff/);
    assert.match(source, /Content-Security-Policy: default-src 'none'; sandbox/);
  }
  const storage = read('storage.php');
  assert.match(storage, /'selectionToken' => rackline_storage_smart_identity\(\$name, \$raw\)/);
  assert.doesNotMatch(storage, /smart-capture|proc_open|file_put_contents|fopen|mkdir/);
});
