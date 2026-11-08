import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {createRequire} from 'node:module';
const M=createRequire(import.meta.url)('../source/usr/local/emhttp/plugins/rackline.dashboard/assets/js/model.js');
const app=fs.readFileSync(new URL('../source/usr/local/emhttp/plugins/rackline.dashboard/assets/js/app.js',import.meta.url),'utf8');
const fn=(a,b)=>app.slice(app.indexOf('function '+a+'('),app.indexOf('function '+b+'('));
const container=(n)=>({id:n.toString(16).padStart(64,'0'),name:'fixture-'+n,state:'running'});
const stats=(t=100000)=>({available:true,sampledAtMs:t,monotonicSeconds:t/1000,cpuCounters:{usage:t,system:t*10,online:2},memoryBytes:1024,networkRxBytes:t,networkTxBytes:t,networkIdentity:'eth0',blockReadBytes:t,blockWriteBytes:t,blockIdentity:'8:0'});
const row=(c,t)=>({...c,requestedId:c.id,status:'ok',generation:'2026-11-01T00:00:00Z',stats:stats(t)});
function harness(containers=[container(1)]){
 const state={docker:{containers},samples:new Map(),batchCursor:0,container:containers[0].name,history:[],gpuBlocked:new Map()};
 const context={state,M,status:{},stopped:false,document:{hidden:false},Date:{now:()=>100000},endpoints:{batch:'/local/containers.php'},request:async()=>context.payload,renderContainerRows(){},renderContainerInspector(){},renderBatchStatus(){},refreshAge(){},text:(k,v)=>context.texts[k]=v,texts:{},when:String,pct:String};
 context.payload={schemaVersion:1,status:'ok',sampledAtMs:100000,requestedCount:containers.length,containers:containers.map(c=>row(c,100000))};
 vm.createContext(context);vm.runInContext(fn('historyGap','trend'),context);vm.runInContext('async '+fn('batchLoop','onClick'),context);vm.runInContext(fn('sampleFresh','updateRowMetrics'),context);return context;
}
test('batch round robin gives every peer coverage while preserving selected priority',()=>{
 const inventory=Array.from({length:100},(_,n)=>container(n+1)),seen=new Set();let cursor=0;
 for(let i=0;i<9;i++){const plan=M.batchPlan(inventory,cursor,inventory[0].name,12);assert.equal(plan.containers[0],inventory[0]);assert.equal(plan.containers.length,12);plan.containers.forEach(c=>seen.add(c.id));cursor=plan.next;}
 assert.equal(seen.size,100);
});
test('ambiguous duplicate names and ID prefixes are never scheduled',()=>{
 const a=container(1),b={...container(2),name:a.name};assert.equal(M.batchPlan([a,b],0,'',12).containers.length,0);
 assert.equal(M.batchPlan([a,{...a,name:'other',id:a.id.slice(0,12)}],0,'',12).containers.length,0);
});
test('nullable accounting stays unknown while legitimate zero memory remains zero',()=>{
 const s={stats:{...stats(),cpuCounters:null,networkRxBytes:null,networkTxBytes:null,blockReadBytes:null,blockWriteBytes:null,memoryBytes:0}};
 assert.equal(M.validBatchStats(s.stats),true);assert.deepEqual(M.batchMetrics(s),{cpu:null,memory:0,rx:null,tx:null,read:null,write:null});
});
test('batch rejects absent monotonic clock rather than coercing it to zero',()=>{
 for(const value of [null,undefined,'',false])assert.equal(M.validBatchStats({...stats(),monotonicSeconds:value}),false);
});
test('interface changes and counter resets invalidate rates',()=>{
 const prior=row(container(1),98000),current=row(container(1),100000);
 assert.equal(M.batchMetrics(current,prior).rx,1000);
 current.stats.networkIdentity='eth1';assert.equal(M.batchMetrics(current,prior).rx,null);
 current.stats.blockReadBytes=0;assert.equal(M.batchMetrics(current,prior).read,null);
});
test('duplicate response rows erase requested telemetry instead of choosing one',async()=>{
 const h=harness(),c=h.state.docker.containers[0];await h.batchLoop();assert.equal(h.state.samples.size,1);
 h.payload.containers.push(row(c,100000));await h.batchLoop();assert.equal(h.state.samples.size,0);
});
test('partial batch never invents metrics for missing requested IDs',async()=>{
 const h=harness([container(1),container(2)]);h.payload.containers.pop();await h.batchLoop();assert.equal(h.state.samples.size,1);h.renderBatchStatus();assert.match(h.texts['rl-batch-status'],/1 \/ 2/);
});
test('replay cannot refresh row age; regression cannot replace accepted sample',async()=>{
 const h=harness();await h.batchLoop();const entry=h.state.samples.values().next().value;
 h.Date.now=()=>125000;await h.batchLoop();assert.equal(h.sampleFresh(entry),false);assert.equal(h.state.samples.values().next().value,entry);
 h.payload.sampledAtMs=99999;await assert.rejects(h.batchLoop(),/Regressing/);assert.equal(h.state.samples.size,0);
});
test('failure status is not overridden by plausible available stats',async()=>{
 const h=harness();h.payload.containers[0].status='timeout';await h.batchLoop();assert.equal(h.state.samples.size,0);
});
test('hidden in-flight batch does not commit responses',async()=>{
 const h=harness();h.document.hidden=true;await h.batchLoop();assert.equal(h.state.samples.size,0);assert.equal(h.status.batch,undefined);
});
test('same-name replacement during fetch does not inherit old sample',async()=>{
 const h=harness();h.request=async()=>{h.state.docker.containers=[{...container(2),name:container(1).name}];return h.payload;};await h.batchLoop();assert.equal(h.state.samples.size,0);
});
test('GPU rows reject malformed or future timestamps, without inventing idle state',()=>{
 const h=harness(),c=h.state.docker.containers[0];
 for(const sampledAtMs of [undefined,null,200000]){h.state.gpu={available:true,sampledAtMs,attributedNames:[],videoAttributedNames:[]};assert.equal(h.gpuActivity(c),'Unknown');}
});
test('GPU device summary rejects future cache timestamps like row attribution',()=>{
 const h=harness();h.state.gpu={available:true,sampledAtMs:200000,loadPercent:50,videoLoadPercent:40};h.renderBatchStatus();assert.match(h.texts['rl-gpu-summary'],/unavailable/);
});
test('GPU device percentage never becomes a per-container percentage',()=>{
 const h=harness(),c=h.state.docker.containers[0];h.state.gpu={available:true,sampledAtMs:100000,loadPercent:90,attributedNames:[c.name],videoAttributedNames:[]};assert.equal(h.gpuActivity(c),'Recent GPU*');
 h.state.gpuBlocked.set(c.name,99000);assert.equal(h.gpuActivity(c),'Unknown');
});
test('future-dated row sample is not presented as fresh',async()=>{
 const h=harness();h.payload.containers[0].stats.sampledAtMs=200000;await h.batchLoop();const entry=h.state.samples.values().next().value;assert.equal(h.sampleFresh(entry),false);
});
test('telemetry-only row refresh leaves existing controls and row DOM untouched',()=>{
 const h=harness(),c=h.state.docker.containers[0];let writes=0,updates=0;
 h.state.folder='all';h.rowSignature=JSON.stringify([[[c.name,c.id,c.state,c.health,c.image,c.imageId,c.icon,c.autostart,c.updateStatus,c.menu]],h.state.container]);
 h.visibleContainers=()=>[c];h.updateRowMetrics=()=>updates++;h.$=()=>({set innerHTML(v){writes++;}});
 vm.runInContext(fn('renderContainerRows','removeNativeMenu'),h);h.renderContainerRows();assert.equal(writes,0);assert.equal(updates,1);
});
test('missing or changed restart generation preserves memory but invalidates every rate',()=>{
 const prior=row(container(1),98000);
 for(const generation of [undefined,null,'','2026-11-02T00:00:00Z']){
  const current={...row(container(1),100000),generation};
  assert.deepEqual(M.batchMetrics(current,prior),{cpu:null,memory:1024,rx:null,tx:null,read:null,write:null});
 }
});
test('restart generation clears selected history and temporarily blocks GPU name attribution',async()=>{
 const h=harness();await h.batchLoop();h.state.history=[{time:98000,cpu:50},{time:100000,cpu:60}];
 h.payload={...h.payload,sampledAtMs:102000,containers:[{...row(container(1),102000),generation:'2026-11-02T00:00:00Z'}]};h.Date.now=()=>102000;
 await h.batchLoop();assert.equal(h.state.history.length,1);assert.equal(h.state.history[0].cpu,null);assert.equal(h.state.gpuBlocked.get(container(1).name),102000);
 assert.equal(h.state.samples.get(container(1).id).metrics.memory,1024);assert.equal(h.state.samples.get(container(1).id).metrics.cpu,null);
});
test('failed, missing, duplicate and unavailable selected batches break CPU history exactly once',async()=>{
 for(const failure of ['network','missing','duplicate','unavailable','malformed']){
  const h=harness();await h.batchLoop();h.state.history=[{time:100000,cpu:50}];
  if(failure==='network')h.request=async()=>{throw new Error('Fixture outage');};
  if(failure==='missing')h.payload.containers=[];
  if(failure==='duplicate')h.payload.containers.push(row(container(1),100000));
  if(failure==='unavailable')h.payload.containers[0].stats.available=false;
  if(failure==='malformed')h.payload.status='invalid';
  for(let i=0;i<2;i++){if(failure==='network'||failure==='malformed')await assert.rejects(h.batchLoop());else await h.batchLoop();}
  assert.equal(h.state.history.length,2,failure);assert.equal(h.state.history[1].cpu,null,failure);
 }
});

test('unsupported GPU source is explicitly unavailable rather than a missing sample',()=>{
 const h=harness(),c=h.state.docker.containers[0];h.state.gpu={available:false,status:'unsupported'};assert.equal(h.gpuActivity(c),'Unavailable');
 h.state.gpu=null;assert.equal(h.gpuActivity(c),'Unknown');
});
