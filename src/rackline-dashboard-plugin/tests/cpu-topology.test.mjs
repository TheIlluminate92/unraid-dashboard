// Synthetic OS topology contracts, not an installed-hardware acceptance test.
import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import fs from 'node:fs';
import {createRequire} from 'node:module';
const M=createRequire(import.meta.url)('../source/usr/local/emhttp/plugins/rackline.dashboard/assets/js/model.js');
const app=fs.readFileSync(new URL('../source/usr/local/emhttp/plugins/rackline.dashboard/assets/js/app.js',import.meta.url),'utf8');
const core=(packageId,coreId,ids)=>({packageId,coreId,tempC:75,threads:ids.map(cpuId=>({cpuId}))});
const groups=cpu=>M.cpuTopology(cpu,null,false);
const ids=sockets=>sockets.flatMap(s=>s.cores.flatMap(c=>c.threads.map(t=>t.id)));
test('adjacent SMT IDs stay in their reported physical core, never inferred offset pairs',()=>{
 const data={cores:Array.from({length:8},(_,i)=>core(0,i,[i*2,i*2+1]))};
 const sockets=groups(data);assert.equal(sockets.length,1);assert.deepEqual(sockets[0].cores.map(c=>c.threads.map(t=>t.id)),Array.from({length:8},(_,i)=>[i*2,i*2+1]));
});
test('dual socket 32 and 72 logical CPU fixtures retain socket-local physical core IDs and all siblings',()=>{
 for(const count of [32,72]){
  const physical=count/2,perSocket=physical/2,cores=Array.from({length:physical},(_,i)=>core(Math.floor(i/perSocket),i%perSocket,[i,i+physical]));
  const sockets=groups({cores:cores.reverse()});assert.deepEqual(sockets.map(s=>s.packageId),[0,1]);assert(sockets.every(s=>s.cores.length===perSocket));
  assert.deepEqual(ids(sockets).sort((a,b)=>a-b),Array.from({length:count},(_,i)=>i));
  for(const s of sockets)for(const c of s.cores)assert.equal(c.threads[1].id-c.threads[0].id,physical);
 }
});
test('sparse and single-thread topology keeps OS labels, and omitted offline CPUs are not fabricated from counters',()=>{
 const sockets=groups({threadCount:99,counters:{cpu3:{total:1,idle:0}},cores:[core(5,9,[20]),core(2,31,[8]),core(2,7,[2])]});
 assert.deepEqual(sockets.map(s=>[s.packageId,s.cores.map(c=>c.coreId)]),[[2,[7,31]],[5,[9]]]);assert.deepEqual(ids(sockets),[2,8,20]);assert(sockets.every(s=>s.cores.every(c=>c.threads.length===1)));
});
test('missing and conflicting identity stays unknown with every logical CPU retained once',()=>{
 const sockets=groups({cores:[core(0,2,[4,5]),core(1,2,[4]),core(null,3,[8,9]),core(2,null,[10]),core(-1,1,[11]),core(3,2,[12,12]),core(3,2,[12])]});
 assert.deepEqual(ids(sockets).sort((a,b)=>a-b),[4,5,8,9,10,11,12]);
 const unknown=sockets.find(s=>s.packageId===null);assert.deepEqual(unknown.cores.map(c=>c.threads.map(t=>t.id)),[[4],[8],[9],[10],[11]]);assert(unknown.cores.every(c=>c.coreId===null));
 assert.deepEqual(sockets.find(s=>s.packageId===0).cores[0].threads.map(t=>t.id),[5]);
});
test('grouping preserves per-logical CPU utilization and unknown samples',()=>{
 const cpu={cores:[core(7,9,[6,2])],counters:{cpu2:{total:200,idle:120},cpu6:{total:100,idle:80}}},previous={cpu2:{total:100,idle:50}};
 assert.deepEqual(M.cpuTopology(cpu,previous,true)[0].cores[0].threads.map(t=>[t.id,t.percent]),[[2,30],[6,null]]);
 assert(M.cpuTopology(cpu,previous,false)[0].cores[0].threads.every(t=>t.percent===null));assert.deepEqual(groups({cores:[]}),[]);
});
test('physical groups balance within capacity at normal and doubled text without losing siblings',()=>{
 for(const count of [1,4,8,9,16,18])for(const width of [242,284,500])for(const font of [14,28])for(const threads of [1,2,4]){
  const columns=M.coreColumns(count,width,font,4,threads),minimum=Math.max(30,font*2.15)*threads+3*(threads-1)+2;
  assert(columns<=Math.max(1,Math.floor((width+4)/(minimum+4))));assert.equal(count%columns===0||count%columns>=columns/2,true);
 }
});
test('CPU markup exposes real socket/core/SMT hierarchy without per-core temperature attribution',()=>{
 const context={M,esc:s=>String(s).replaceAll('<','&lt;'),pct:n=>n===null?'Unknown':n+'%'};vm.createContext(context);vm.runInContext(app.slice(app.indexOf('function cpuMarkup('),app.indexOf('function renderSystem(')),context);
 const markup=context.cpuMarkup({cores:[core(4,7,[19,3]),core(null,null,[21])]},null,false);
 assert.match(markup,/data-socket="4"/);assert.match(markup,/data-core="7" data-threads="2"/);assert.match(markup,/Socket 4.*Core 7/);assert.match(markup,/Topology unknown/);assert.match(markup,/CPU 3 · Socket 4 · core 7 · Unknown/);
 assert.doesNotMatch(markup,/75|°C/);assert(markup.indexOf('<span>3</span>')<markup.indexOf('<span>19</span>'));assert.match(context.cpuMarkup({cores:[]},null,false),/CPU topology unavailable/);
});

test('interleaved sockets with sparse raw core IDs do not become global core ordinals',()=>{
 const labels=[0,2,4,7,11,13,16,19],cores=[];
 for(let socket=0;socket<2;socket++)labels.forEach((id,index)=>cores.push(core(socket,id,[index*2+socket,index*2+socket+16])));
 const sockets=groups({cores:cores.reverse()});assert.deepEqual(sockets.map(s=>s.cores.map(c=>c.coreId)),[labels,labels]);
 for(const socket of sockets)socket.cores.forEach((c,index)=>assert.deepEqual(c.threads.map(t=>t.id),[index*2+socket.packageId,index*2+socket.packageId+16]));
});
test('high-SMT physical cores wrap internally at narrow and doubled text without splitting their identity',()=>{
 const applied=new Map();const group={dataset:{threads:'8'},getBoundingClientRect:()=>({width:242}),style:{setProperty:(k,v)=>applied.set(k,v)}};
 const grid={children:[group],getBoundingClientRect:()=>({width:242}),style:{setProperty:(k,v)=>applied.set(k,v)}};
 const context={M,layoutPulse(){},q:()=>[grid],window:{getComputedStyle:()=>({fontSize:'28px',columnGap:'4px'})}};vm.createContext(context);vm.runInContext(app.slice(app.indexOf('function layoutCores('),app.indexOf('function cpuMarkup(')),context);
 context.layoutCores();assert.equal(applied.get('--rl-core-columns'),1);assert.equal(applied.get('--rl-threads'),3);
 assert.deepEqual(groups({cores:[core(0,0,[0,4,8,12,16,20,24,28])]}).map(s=>s.cores.length),[1]);
});
