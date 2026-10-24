import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import assert from 'node:assert/strict';
const bannerSource = fs.readFileSync(new URL('../../waz-health-plugin/source/usr/local/emhttp/plugins/waz.health/assets/js/banner.js', import.meta.url), 'utf8');
class Node {
  constructor(){this.children=[];this.dataset={};this.classList={toggle(){}};}
  appendChild(n){this.children.push(n);}
  replaceChildren(){this.children=[];}
  setAttribute(){}
}
function harness(){
  const fields={issues:new Node(),overall:new Node(),dot:new Node()};
  const banner={querySelector:s=>s.includes('issues')?fields.issues:s.includes('data-field="overall"')?fields.overall:s.includes('__overall')?fields.dot:null,classList:{toggle(){}}};
  const window={};
  const document={readyState:'loading',addEventListener(){},createElement:()=>new Node()};
  const code=bannerSource.replace('  window.WAZHealth = {',`  window.fixture = {
    set: function (s) { state.subsystems=s; state.healthSubsystems=s; state.overall={state:'normal'}; },
    issues: dashboardIssues,
    draw: function () { banner=window.testBanner; renderIssues(); banner=null; }
  };
  window.WAZHealth = {`);
  vm.runInNewContext(code,{window,document,console});window.testBanner=banner;
  return {window,fields,update:(key,payload)=>window.WAZHealth.dashboardUpdate(key,{receivedAt:Date.now(),...payload}),draw:()=>window.fixture.draw()};
}
test('one attention list replaces generic storage flags and clears after recovery',()=>{
  const h=harness();h.window.fixture.set({storage:{state:'attention',message:'Storage needs attention'},array:{state:'normal'}});
  h.update('storage',{state:'attention',messages:['Cache 96% full','Cache 96% full']});h.draw();
  assert.equal(h.fields.issues.children.length,1);assert.equal(h.fields.overall.textContent,'NEEDS ATTENTION · 1');
  h.update('storage',{state:'normal',messages:[]});h.window.fixture.set({storage:{state:'normal'}});h.draw();
  assert.equal(h.fields.issues.children.length,0);assert.equal(h.fields.overall.textContent,'ALL CLEAR');
});
test('stale snapshots become visible faults and unrelated UPS faults remain visible',()=>{
  const h=harness();h.window.fixture.set({ups:{state:'fault',message:'UPS on battery'}});
  h.update('workloads',{state:'normal',messages:[],receivedAt:Date.now()-25000});h.draw();
  assert.equal(h.fields.issues.children.length,2);assert.equal(h.fields.dot.dataset.state,'fault');
  assert.match(h.fields.issues.children[0].children[2].textContent,/telemetry unavailable/);
});
test('unknown data never becomes all clear and dashboard failures are not suppressed by normal health',()=>{
  const h=harness();h.window.fixture.set({storage:{state:'unknown'}});h.draw();
  assert.notEqual(h.fields.overall.textContent,'ALL CLEAR');
  h.update('workloads',{state:'attention',messages:['Example unhealthy']});h.draw();
  assert.equal(h.fields.overall.textContent,'NEEDS ATTENTION · 1');
});
