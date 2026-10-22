import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const source = readFileSync(new URL('../source/usr/local/emhttp/plugins/waz.dashboard/assets/js/system.js', import.meta.url), 'utf8');
const key = 'waz.dashboard.cpuExpansion.v1';

class Element {
  constructor(tag) { this.tagName = tag; this.children = []; this.style = {}; this.attributes = {}; this.listeners = {}; this.open = false; }
  set textContent(value) { this.children = []; this.value = String(value); }
  get textContent() { return (this.value || '') + this.children.map(c => c.textContent).join(''); }
  appendChild(child) { child.parent = this; this.children.push(child); return child; }
  setAttribute(name, value) { this.attributes[name] = value; }
  getAttribute(name) { return this.attributes[name]; }
  addEventListener(name, listener) { this.listeners[name] = listener; }
  toggle(open) { this.open = open; this.listeners.toggle(); }
}

const topology = () => [
  { packageId: 0, coreId: 0, tempC: 35, threads: [{ cpuId: 0 }, { cpuId: 16 }] },
  { packageId: 1, coreId: 0, tempC: 40, threads: [{ cpuId: 1 }, { cpuId: 17 }] }
];
const counters = () => ({ cpu: { total: 400, idle: 200 }, cpu0: { total: 100, idle: 50 }, cpu16: { total: 100, idle: 50 }, cpu1: { total: 100, idle: 50 }, cpu17: { total: 100, idle: 50 } });
function snapshot(cores = topology(), ticks = counters()) { return { cpu: { model: 'Test CPU', cores, counters: ticks, threadCount: cores.reduce((n, c) => n + c.threads.length, 0) }, sampledAtMs: Date.now() }; }

async function harness(initial = snapshot(), storage = new Map()) {
  const list = new Element('div');
  const chart = new Element('polyline');
  let current = initial;
  const window = { localStorage: { getItem: k => storage.get(k), setItem: (k, v) => storage.set(k, v) }, setTimeout: () => 1, clearTimeout() {} };
  const root = new Element('section');
  const document = { querySelector: () => root, getElementById: id => ({ 'waz-core-list': list, 'waz-cpu-chart-line': chart })[id], createElement: tag => new Element(tag), createTextNode: value => { const e = new Element('#text'); e.textContent = value; return e; } };
  const fetch = async url => ({ ok: !url.includes('hbaviewer'), status: url.includes('hbaviewer') ? 404 : 200, json: async () => current });
  vm.runInNewContext(source, { window, document, fetch, console });
  const flush = () => new Promise(resolve => setImmediate(resolve));
  await flush();
  return { list, chart, storage, window, async sample(next) { current = next; window.WAZSystem.refresh(); await flush(); } };
}

function descendants(element, tag) { return element.children.flatMap(c => [ ...(c.tagName === tag ? [c] : []), ...descendants(c, tag) ]); }
function visibleThreads(element, visible = true) {
  return element.children.flatMap(c => {
    const shown = visible && (element.tagName !== 'details' || element.open || c.tagName === 'summary');
    return [...(shown && c.className === 'waz-thread' ? [c] : []), ...visibleThreads(c, shown)];
  });
}
const core = socket => socket.children[1].children[0];
const load = details => details.children[0].children[1].textContent;

test('three stages keep repeated core IDs separate and preserve independent expansion choices', async () => {
  const h = await harness();
  assert.equal(h.list.children.length, 2);
  const [a, b] = h.list.children;
  assert.equal(a.children[0].children[0].children[0].textContent, 'SOCKET 0');
  assert.equal(b.children[0].children[0].children[0].textContent, 'SOCKET 1');
  assert.equal(a.open, false);
  assert.equal(visibleThreads(h.list).length, 0);
  a.toggle(true);
  assert.equal(core(a).open, false);
  assert.equal(visibleThreads(h.list).length, 0);
  core(a).toggle(true);
  assert.equal(visibleThreads(h.list).length, 2);
  a.toggle(false);
  assert.equal(visibleThreads(h.list).length, 0);
  const restored = await harness(snapshot(), h.storage);
  assert.equal(restored.list.children[0].open, false);
  assert.equal(core(restored.list.children[0]).open, true);
  assert.equal(core(restored.list.children[1]).open, false);
});

test('core and socket usage is weighted by valid counter deltas, not averaged percentages', async () => {
  const h = await harness();
  assert.equal(load(h.list.children[0]), '--');
  const next = counters();
  next.cpu0 = { total: 110, idle: 50 }; // 100% over 10 ticks
  next.cpu16 = { total: 190, idle: 140 }; // 0% over 90 ticks
  next.cpu1 = { total: 110, idle: 55 };
  next.cpu17 = { total: 110, idle: 55 };
  next.cpu = { total: 520, idle: 300 };
  await h.sample(snapshot(topology(), next));
  assert.equal(load(h.list.children[0]), '10%');
  assert.equal(load(core(h.list.children[0])), '10%');
  assert.equal(load(h.list.children[1]), '50%');
  const threads = descendants(core(h.list.children[0]), 'div').filter(e => e.className === 'waz-thread');
  assert.deepEqual(threads.map(e => e.children[2].textContent), ['100%', '0%']);
  assert.match(h.chart.attributes.points, /,/);
});

test('missing and reset counters stay unavailable and do not become idle samples', async () => {
  const h = await harness();
  const next = { cpu0: { total: 120, idle: 50 }, cpu1: { total: 1, idle: 1 }, cpu17: { total: 120, idle: 1 } };
  await h.sample(snapshot(topology(), next));
  assert.equal(load(h.list.children[0]), '100%');
  assert.equal(load(h.list.children[1]), '--');
  assert.equal(load(core(h.list.children[1])), '--');
  await h.sample(snapshot(topology(), {}));
  assert.equal(load(h.list.children[0]), '--');
});

test('topology changes prune removed socket choices and work with sparse IDs and no SMT', async () => {
  const h = await harness();
  h.list.children[1].toggle(true);
  core(h.list.children[1]).toggle(true);
  await h.sample(snapshot([{ packageId: 7, coreId: 42, tempC: null, threads: [{ cpuId: 9 }] }], {}));
  assert.equal(h.list.children.length, 1);
  assert.equal(h.list.children[0].open, false);
  assert.equal(h.list.children[0].children[0].children[0].children[1].textContent, '1 core · 1 thread');
  assert.equal(h.list.children[0].children[0].children[2].textContent, '--');
  assert.equal(JSON.parse(h.storage.get(key))['socket:1'], undefined);
});

test('blocked or malformed browser storage does not break native disclosures', async () => {
  const storage = new Map([[key, '{bad json']]);
  const h = await harness(snapshot(), storage);
  h.window.localStorage.setItem = () => { throw new Error('Storage blocked'); };
  assert.doesNotThrow(() => h.list.children[0].toggle(true));
  const blocked = { get() { throw new Error('Storage blocked'); }, set() { throw new Error('Storage blocked'); } };
  const other = await harness(snapshot(), blocked);
  assert.equal(other.list.children.length, 2);
  assert.doesNotThrow(() => core(other.list.children[0]).toggle(true));
});

test('empty topology is unavailable and sockets with unequal core counts aggregate all valid threads', async () => {
  const empty = await harness(snapshot([], {}));
  assert.equal(empty.list.textContent, 'CPU topology unavailable');
  const cores = topology();
  cores.push({ packageId: 0, coreId: 5, tempC: 45, threads: [{ cpuId: 7 }] });
  const start = counters();
  start.cpu7 = { total: 100, idle: 50 };
  const h = await harness(snapshot(cores, start));
  const next = counters();
  next.cpu0 = { total: 110, idle: 50 };
  next.cpu16 = { total: 190, idle: 140 };
  next.cpu7 = { total: 200, idle: 50 };
  await h.sample(snapshot(cores, next));
  assert.equal(load(h.list.children[0]), '55%');
  assert.equal(h.list.children[0].children[0].children[2].textContent, '45°C');
  assert.equal(h.list.children[0].children[1].children.length, 2);
});
