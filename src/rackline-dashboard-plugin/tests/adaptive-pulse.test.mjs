// Local source/algorithm contracts; not a substitute for browser geometry.
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {createRequire} from 'node:module';
const M=createRequire(import.meta.url)('../source/usr/local/emhttp/plugins/rackline.dashboard/assets/js/model.js');
const base=new URL('../source/usr/local/emhttp/plugins/rackline.dashboard/',import.meta.url);
const app=fs.readFileSync(new URL('assets/js/app.js',base),'utf8');
const css=fs.readFileSync(new URL('assets/css/rackline.css',base),'utf8');
const context={M};vm.createContext(context);
vm.runInContext(app.slice(app.indexOf('function expandedPulse('),app.indexOf('function renderSystem(')),context);
const core=(packageId,coreId,ids)=>({packageId,coreId,threads:ids.map(cpuId=>({cpuId}))});
const compact={cores:Array.from({length:8},(_,i)=>core(0,i,[2*i,2*i+1]))};
const dual={cores:[0,1].flatMap(s=>[0,2,3,4,8,10,11,12].map((c,i)=>core(s,c,[2*i+s,2*i+s+16])))};
test('compact eight-pair strip remains unchanged; interleaved dual sockets expand',()=>{
 assert.equal(context.expandedPulse(compact),false);assert.equal(context.expandedPulse(dual),true);
 assert.equal(context.expandedPulse({cores:dual.cores.slice().reverse()}),true);
 // CPU IDs, raw labels and names cannot select the layout.
 assert.equal(context.expandedPulse({model:'Arbitrary',cores:compact.cores.map((c,i)=>core(71,i*7,[1000+i*5,1001+i*5]))}),false);
});
test('large, asymmetric, unknown and high-SMT topology stays safe',()=>{
 const many={cores:Array.from({length:36},(_,i)=>core(i<18?2:9,i%18,[i,i+36]))};
 for(const cpu of [many,{cores:[core(2,8,[0,7]),core(9,3,[1])]},
  {cores:Array.from({length:32},(_,i)=>core(null,null,[i]))},
  {cores:[core(0,0,[0,4,8,12,16,20,24,28])]},
  {cores:Array.from({length:16},(_,i)=>core(0,i,[i]))}])assert.equal(context.expandedPulse(cpu),true);
 for(const cpu of [undefined,{}, {cores:[]}, {cores:[core(null,null,[7])]}])assert.equal(context.expandedPulse(cpu),false);
});
test('classification is stable across sample values, viewport widths and text sizes',()=>{
 for(const width of [320,390,540,700,768,900,1000,1024,1200,1440,1800,1920,2048])for(const font of [14,28]){
  // No measured expanded width enters the classifier. Core fit still uses it.
  for(const cpu of [compact,dual])for(let sample=0;sample<5;sample++){
   assert.equal(context.expandedPulse({...cpu,counters:{cpu0:{total:sample*500,idle:sample*123}}}),cpu===dual);
   for(const socket of M.cpuTopology(cpu,null,false)){
    const columns=M.coreColumns(socket.cores.length,Math.max(1,width-78),font,4,2);
    assert(columns>=1&&columns<=socket.cores.length);
   }
  }
 }
});
test('expanded styles are scoped and retain source order and intrinsic height',()=>{
 const start=css.indexOf('/* Larger/asymmetric topologies'),end=css.indexOf('/* A cached bay label',start);assert(start>=0&&end>start);const added=css.slice(start,end);
 for(const selectors of added.matchAll(/([^{}]+)\{/g)){
  const clean=selectors[1].replace(/\/\*[\s\S]*?\*\//g,'').trim();
  assert(clean.startsWith('#rackline-root.rl-pulse-expanded '),clean);
 }
 assert.match(added,/align-items:start/);assert.match(added,/article:first-child\{grid-column:1\/-1/);
 assert.match(added,/minmax\(min\(100%,max\(18em,30%\)\),1fr\)/);
 assert.match(added,/white-space:normal;overflow-wrap:anywhere/);
 assert.doesNotMatch(added,/grid-auto-flow:dense|order:|height:|overflow:hidden|position:absolute/);
 assert.match(app,/classList.toggle\('rl-pulse-expanded',expandedPulse\(d.cpu\)\)/);
 assert.match(app,/height\+Math.max\(0,top\)>window.innerHeight-240/);
});

test('sticky fit is remeasured synchronously after final secondary text, including I/O-only updates',()=>{
 let height=100,finalHeight=600,staticMode=false;const events=[];
 const pulse={getBoundingClientRect:()=>{events.push('measure');return {height};}};
 const elements=new Map();
 const ctx={M,state:{system:{cpu:{cores:[]},memory:{},network:{}},drive:''},
  root:{querySelector:()=>pulse,classList:{toggle:(name,value)=>{if(name==='rl-pulse-static')staticMode=value;}}},
  window:{innerWidth:1440,innerHeight:800,getComputedStyle:()=>({top:'0px'})},
  $:id=>{if(!elements.has(id))elements.set(id,{style:{}});return elements.get(id);},
  text:(id)=>{events.push(id);if(id==='rl-network-detail'||id==='rl-io-detail')height=finalHeight;},
  num:M.number,pct:()=>'',temp:()=>'',bytes:()=>'',rate:()=>'',expandedPulse:()=>true,cpuMarkup:()=>'',
  layoutCores:()=>events.push('cores'),ioRates:()=>({expected:1,count:1,read:0,write:0}),renderDriveInspector:()=>{}};
 vm.createContext(ctx);
 vm.runInContext(app.slice(app.indexOf('function layoutPulse('),app.indexOf('function layoutCores(')),ctx);
 vm.runInContext(app.slice(app.indexOf('function renderSystem('),app.indexOf('function allDrives(')),ctx);
 vm.runInContext(app.slice(app.indexOf('function renderIo('),app.indexOf('function renderAlerts(')),ctx);
 for(const render of ['renderSystem','renderIo']){
  for(const nextHeight of [600,600,100,100]){
   events.length=0;finalHeight=nextHeight;ctx[render]();
   assert.equal(events.at(-1),'measure');
   assert.equal(events.at(-2),render==='renderSystem'?'rl-network-detail':'rl-io-detail');
   assert.equal(staticMode,nextHeight>560,render+' must reflect final height in the same render');
  }
 }
});

test('empty/malformed inputs remain safe and large socket sets do not get flattened',()=>{
 for(const cpu of [{cores:null},{cores:[null,{}, {threads:null}]}, {cores:'invalid'}])assert.equal(context.expandedPulse(cpu),false);
 const many={cores:Array.from({length:4096},(_,i)=>core(i,i,[i]))};
 assert.equal(context.expandedPulse(many),true);
 const early={M:{cpuTopology:()=>Array.from({length:4096},()=>({get cores(){throw new Error('must return before reading or copying core arrays');}}))}};
 vm.createContext(early);vm.runInContext(app.slice(app.indexOf('function expandedPulse('),app.indexOf('function renderSystem(')),early);
 assert.equal(early.expandedPulse({}),true);
});
