import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
const source = readFileSync(new URL('../source/usr/local/emhttp/plugins/waz.dashboard/assets/js/system.js', import.meta.url), 'utf8');
async function harness(available, responses) {
  const calls = [], timers = [];
  const fields = Object.fromEntries([0, 1].flatMap(n => ['label', 'temp', 'detail'].map(part => ['waz-hba-' + n + '-' + part, { textContent: '', className: '' }])));
  const root = { getAttribute: key => key === 'data-hba-available' ? available : null };
  const window = { setTimeout: (fn, ms) => { timers.push({ fn, ms }); return timers.length; }, clearTimeout() {} };
  const document = { querySelector: () => root, getElementById: id => fields[id] };
  const fetch = async url => {
    calls.push(url);
    const response = url.includes('hbaviewer') ? responses.shift() : { status: 200, body: {} };
    return { ok: response.status === 200, status: response.status, json: async () => response.body };
  };
  vm.runInNewContext(source, { window, document, fetch, console });
  const flush = () => new Promise(resolve => setImmediate(resolve));
  await flush();
  return { fields, timers, calls, flush, window };
}
test('server-rendered absence skips HBA requests and retry timers', async () => {
  const h = await harness('no', []);
  assert.equal(h.calls.filter(url => url.includes('hbaviewer')).length, 0);
  assert.equal(h.timers.filter(t => t.fn.name === 'hbaLoop').length, 0);
  assert.equal(h.fields['waz-hba-0-detail'].textContent, 'HBA Viewer not installed');
});
test('404 and 410 stop polling; missing availability metadata remains compatible', async () => {
  for (const status of [404, 410]) {
    const h = await harness(null, [{ status }]);
    assert.equal(h.calls.filter(url => url.includes('hbaviewer')).length, 1);
    assert.equal(h.timers.filter(t => t.fn.name === 'hbaLoop').length, 0);
    assert.equal(h.fields['waz-hba-0-detail'].textContent, 'HBA Viewer not installed');
  }
});
test('transient failures retry and installed HBA temperatures refresh normally', async () => {
  const h = await harness('yes', [{ status: 503 }, { status: 200, body: { controllers: [{ controller: 0, temp_c: 42, model: 'Test HBA', status: 'normal' }] } }]);
  const retry = h.timers.find(t => t.fn.name === 'hbaLoop');
  assert.equal(retry.ms, 5000);
  retry.fn(); await h.flush();
  assert.equal(h.fields['waz-hba-0-temp'].textContent, '42°C');
  assert.equal(h.timers.filter(t => t.fn.name === 'hbaLoop').at(-1).ms, 60000);
});
test('removed plugin clears prior temperatures before stopping', async () => {
  const h = await harness('yes', [{ status: 200, body: { controllers: [{ temp_c: 42 }] } }, { status: 404 }]);
  h.timers.find(t => t.fn.name === 'hbaLoop').fn(); await h.flush();
  assert.equal(h.fields['waz-hba-0-temp'].textContent, 'N/A');
  assert.equal(h.fields['waz-hba-0-detail'].textContent, 'HBA Viewer not installed');
  assert.equal(h.timers.filter(t => t.fn.name === 'hbaLoop').length, 1);
});
