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
function harness(storage = new Map(), blocked = false) {
  const root=new Element(); root.edit=new Element(); root.reset=new Element();
  for(const [group,ids] of Object.entries({summary:['cpu','gpu','power','memory','coolant','hba0','network','flow','hba1'],sections:['cpu','memory','network']})) {
    const container=new Element({layoutGroup:group}); root.appendChild(container);
    ids.forEach(id=>container.appendChild(new Element({layoutItem:id,layoutLabel:id})));
  }
  const window={localStorage:{ getItem:k=>{if(blocked)throw Error(); return storage.get(k)},setItem:(k,v)=>{if(blocked)throw Error();storage.set(k,v)}}};
  const document={readyState:'complete', querySelector:()=>root, createElement:()=>new Element()};
  vm.runInNewContext(source,{window,document});
  return {root,storage,window,summary:root.children[0],sections:root.children[1]};
}
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
