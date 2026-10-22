import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
const source = readFileSync(new URL('../../waz-health-plugin/source/usr/local/emhttp/plugins/waz.health/assets/js/banner.js', import.meta.url), 'utf8');
function harness(available, responses) {
  const calls = [];
  const window = { WAZHealthBootstrap: { fanAvailable: available } };
  const document = { readyState: 'loading', addEventListener() {} };
  const fetch = async url => {
    calls.push(url);
    const fan = url.includes('md12xx');
    const response = fan ? responses.shift() : { status: 200, body: {} };
    return { ok: response.status === 200, status: response.status, json: async () => response.body };
  };
  vm.runInNewContext(source, { window, document, fetch, console });
  return { window, calls, refresh: () => window.WAZHealth.refresh() };
}
test('absent fan plugin never receives requests while health keeps refreshing', async () => {
  const h = harness(false, []);
  await h.refresh(); await h.refresh();
  assert.equal(h.calls.length, 2);
  assert.ok(h.calls.every(url => !url.includes('md12xx')));
  assert.equal(h.window.WAZHealth.getState().fans.available, false);
});
test('removed plugin is probed once, then stops polling until reload', async () => {
  for (const status of [404, 410]) {
    const h = harness(true, [{ status }]);
    await h.refresh(); await h.refresh();
    assert.equal(h.calls.filter(url => url.includes('md12xx')).length, 1);
  }
});
test('transient failure retries and installed disabled plugin remains available', async () => {
  const h = harness(true, [{ status: 503 }, { status: 200, body: { available: true, enabled: false, mode: 'auto' } }]);
  await h.refresh(); await h.refresh();
  assert.equal(h.calls.filter(url => url.includes('md12xx')).length, 2);
  assert.equal(h.window.WAZHealth.getState().fans.available, true);
  assert.equal(h.window.WAZHealth.getState().fans.enabled, false);
});
test('installed enabled plugin still supplies fan state', async () => {
  const h = harness(true, [{ status: 200, body: { available: true, enabled: true, mode: 'manual', manualSpeed: 40 } }]);
  await h.refresh();
  assert.equal(h.window.WAZHealth.getState().fans.enabled, true);
  assert.equal(h.window.WAZHealth.getState().fans.manualSpeed, 40);
});
