import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
const source = fs.readFileSync(new URL('../source/usr/local/emhttp/plugins/waz.dashboard/assets/js/layout.js', import.meta.url), 'utf8');
class Element {
  constructor(data = {}) { this.dataset = data; this.children = []; this.style = {}; this.hidden = false; this.listeners = {}; this.attributes = {}; this.classList = { toggle() {}, add() {} }; }
  appendChild(item) { if(item.parentElement) item.parentElement.children.splice(item.parentElement.children.indexOf(item),1); this.children.push(item); item.parentElement=this; return item; }
  insertBefore(item, target) { if(item.parentElement) item.parentElement.children.splice(item.parentElement.children.indexOf(item),1); const i=this.children.indexOf(target); this.children.splice(i < 0 ? this.children.length : i,0,item); item.parentElement=this; }
  get firstChild() { return this.children[0]; }
  get nextSibling() { return this.parentElement?.children[this.parentElement.children.indexOf(this)+1] || null; }
  setAttribute(k,v) { this.attributes[k]=v; }
  addEventListener(k,fn) { this.listeners[k]=fn; }
  removeEventListener(k) { delete this.listeners[k]; }
  setPointerCapture() {}
  getBoundingClientRect() { return { width: this.dataset.layoutGroup ? 600 : 200, height: 100, top: 0 }; }
  querySelectorAll(selector) { if(selector==='[data-layout-group]') return this.children.filter(x=>x.dataset.layoutGroup); return this.children.filter(x=>['waz-layout-controls','waz-layout-grip'].includes(x.className)); }
  querySelector(selector) { return selector==='[data-layout-edit]' ? this.edit : selector==='[data-layout-reset]' ? this.reset : null; }
  click() { this.listeners.click({stopPropagation(){}}); }
}
function harness(storage = new Map(), blocked = false, definitions = {summary:['cpu','gpu','power','memory','coolant','hba0','network','flow','hba1'],sections:['cpu','memory','network']}, panel = 'status') {
  const root=new Element({layoutKey:'waz.dashboard.'+panel+'Layout.v1'}); root.edit=new Element(); root.reset=new Element();
  for(const [group,ids] of Object.entries(definitions)) {
    const container=new Element({layoutGroup:group,layoutColumns:group==='summary' && panel==='workloads' ? 3 : undefined}); root.appendChild(container);
    ids.forEach(id=>container.appendChild(new Element({layoutItem:id,layoutLabel:id})));
  }
  const window={localStorage:{ getItem:k=>{if(blocked)throw Error(); return storage.get(k)},setItem:(k,v)=>{if(blocked)throw Error();storage.set(k,v)}}};
  const document={readyState:'complete', querySelectorAll:()=>[root], createElement:()=>new Element()};
  vm.runInNewContext(source,{window,document});
  return {root,storage,window,summary:root.children[0],sections:root.children[1]};
}

test('Workloads and Storage save independent layouts without replacing existing Server Status choices',()=>{
  const storage=new Map();const status=harness(storage);status.root.edit.click();status.summary.children[0].children[0].children[3].click();
  const workDefs={summary:['running','vdisk','updates','gpu'],sections:['folders','containers','processes','inspector']};
  const work=harness(storage,false,workDefs,'workloads');work.root.edit.click();
  assert.equal(work.summary.children[0].style.gridColumn,'span 3');
  const inspector=work.sections.children[3];inspector.children[0].children[0].click();
  inspector.children[0].children[2].click();assert.equal(inspector.style.gridColumn,'span 11');
  const diskDefs={sections:['parity','pools','pooldetails','locations','array']};
  const disk=harness(storage,false,diskDefs,'storage');const group=disk.root.children[0];group.children.at(-1).hidden=true;
  disk.root.edit.click();group.children[0].children[0].children[1].click();disk.root.reset.click();
  assert.equal(group.children.find(x=>x.dataset.layoutItem==='array').hidden,true);
  assert.equal(harness(storage).summary.children[0].style.gridColumn,'span 5');
  const restored=harness(storage,false,workDefs,'workloads');assert.equal(restored.sections.children[2].dataset.layoutItem,'inspector');assert.equal(restored.sections.children[2].style.gridColumn,'span 11');
  assert.equal(storage.size,3);
});

test('mounting the shared script again does not duplicate editing controls',()=>{
  const h=harness();const before=h.summary.children[0].children.length;
  h.window.WAZDashboardLayout.start();h.window.WAZDashboardLayout.mount(h.root);
  assert.equal(h.summary.children[0].children.length,before);
});
test('all nine summary tiles and three sections get independent controls; edits persist and reset',()=>{
  const h=harness(); assert.equal(h.summary.children.length,9); assert.equal(h.sections.children.length,3);
  h.root.edit.click();
  const cpu=h.summary.children[0], controls=cpu.children[0];
  controls.children[1].click(); assert.equal(h.summary.children[1],cpu);
  controls.children[3].click(); assert.equal(cpu.style.gridColumn,'span 5');
  controls.children[5].click(); assert.equal(cpu.style.height,'124px');
  assert.equal(h.sections.children[0].style.gridColumn,'span 12');
  const restored=harness(h.storage); assert.equal(restored.summary.children[1].dataset.layoutItem,'cpu'); assert.equal(restored.summary.children[1].style.gridColumn,'span 5');
  restored.root.reset.click(); assert.equal(restored.summary.children[0].dataset.layoutItem,'cpu'); assert.equal(restored.summary.children[0].style.height,'');
});
test('malformed or blocked storage uses safe defaults; unknown tiles are discarded and sizes bounded',()=>{
  for(const blocked of [false,true]) {
    const h=harness(new Map([['waz.dashboard.statusLayout.v1','not json']]),blocked);
    h.root.edit.click(); h.root.reset.click(); assert.equal(h.summary.children[0].style.gridColumn,'span 4');
  }
  const n=harness().window.WAZStatusLayout.normalize({summary:{cpu:{columns:999,height:-5,order:Infinity},unknown:{columns:1}}});
  assert.equal(n.summary.cpu.columns,12);assert.equal(n.summary.cpu.height,56);assert.equal(n.summary.cpu.order,0);assert.equal(n.summary.unknown,undefined);
});
test('drag and pointer resizing stay inside their group and save the result',()=>{
  const h=harness(); h.root.edit.click();
  const a=h.summary.children[0], b=h.summary.children[1];
  const event={target:a,clientY:10,preventDefault(){},stopPropagation(){},dataTransfer:{setData(){}}};
  a.listeners.dragstart(event);
  h.sections.children[0].listeners.drop(event); assert.equal(h.sections.children.length,3);
  b.listeners.drop(event); assert.equal(h.summary.children[0],a); // above target
  const grip=a.children.at(-1);
  grip.listeners.pointerdown({...event,pointerId:1,clientX:0,clientY:0});
  grip.listeners.pointermove({clientX:100,clientY:50});grip.listeners.pointerup();
  assert.equal(a.style.gridColumn,'span 6');assert.equal(a.style.height,'150px');
  assert.ok(h.storage.get('waz.dashboard.statusLayout.v1').includes('150'));
});
