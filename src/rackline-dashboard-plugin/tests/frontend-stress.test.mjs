// Synthetic, bounded Node stress only: no Docker, network, browser or host controls.
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {createRequire} from 'node:module';
import {performance} from 'node:perf_hooks';
const M=createRequire(import.meta.url)('../source/usr/local/emhttp/plugins/rackline.dashboard/assets/js/model.js');
const app=fs.readFileSync(new URL('../source/usr/local/emhttp/plugins/rackline.dashboard/assets/js/app.js',import.meta.url),'utf8');
const fn=(a,b)=>app.slice(app.indexOf('function '+a+'('),app.indexOf('function '+b+'('));
const container=n=>({id:n.toString(16).padStart(64,'0'),name:'synthetic-'+n,state:'running'});
const inventory=n=>Array.from({length:n},(_,i)=>container(i+1));
const stats=t=>({available:true,sampledAtMs:t*1000,monotonicSeconds:t,cpuCounters:{usage:t*300,system:t*1000,online:8},memoryBytes:4096,networkRxBytes:t*10,networkTxBytes:t*20,networkIdentity:'eth0',blockReadBytes:t*30,blockWriteBytes:t*40,blockIdentity:'8:0'});
const sample=(c,t)=>({...c,requestedId:c.id,status:'ok',generation:'synthetic-generation',stats:stats(t)});

test('stress: 3,000 containers receive fair bounded batches with constant selected priority',t=>{
 const all=inventory(3000),seen=new Set();let cursor=0;
 const start=performance.now();
 for(let turn=0;turn<273;turn++){
  const plan=M.batchPlan(all,cursor,all[1729].name,12);cursor=plan.next;
  assert.equal(plan.containers[0],all[1729]);assert.equal(plan.containers.length,12);assert.equal(plan.total,3000);
  assert.equal(new Set(plan.containers.map(c=>c.id)).size,12);plan.containers.forEach(c=>seen.add(c.id));
 }
 assert.equal(seen.size,3000);t.diagnostic(`Synthetic planner: 3,000 rows × 273 batches in ${(performance.now()-start).toFixed(1)} ms; no network requests.`);
});

test('stress: scheduling and indexed identity verification use linear inventory reads',()=>{
 let reads=0;const all=inventory(5000).map(c=>({...c,get name(){reads++;return c.name;}}));
 M.batchPlan(all,0,'synthetic-1',12);assert(reads<all.length*10,`planner name reads: ${reads}`);
 reads=0;const index=M.inventoryIndex(all);
 for(let n=0;n<all.length;n++)assert(M.sameIdentity(all[n],{id:all[n].id,name:'synthetic-'+(n+1)},all,index));
 assert(reads<all.length*10,`identity name reads: ${reads}`);
});

test('stress: 250 inventory churn cycles remove ambiguous identities and preserve valid peers',()=>{
 for(let turn=0;turn<250;turn++){
  const all=inventory(200),victim=turn%all.length;all[victim]={...all[victim],state:'stopped'};
  const duplicate={...all[199],id:'f'.repeat(64)};all.push(duplicate);
  const short={...all[198],id:all[198].id.slice(0,12),name:'short-conflict'};all.push(short);
  const plan=M.batchPlan(all,turn*11,'synthetic-200',12);
  assert(plan.containers.every(c=>c.state==='running'&&c.name!=='synthetic-200'&&c!==short));
  assert(plan.containers.length<=12);
 }
});

test('indexed identity matches reject duplicate names, exact IDs and ambiguous short IDs',()=>{
 const a=container(1),b=container(2),short={...a,id:a.id.slice(0,12)};
 for(const all of [[a,{...b,name:a.name}],[a,{...a,name:'other'}],[a,{...short,name:'other'}]])assert.equal(M.sameIdentity(a,a,all,M.inventoryIndex(all)),false);
 assert.equal(M.sameIdentity(a,{...a,id:short.id},[a,b]),false);
 assert.equal(M.sameIdentity(a,a,[a,b]),true,'distinct full IDs sharing a 12-digit prefix remain usable');
});

test('malformed numeric and extreme rate inputs never display negative or nonfinite rates',()=>{
 for(const value of [' ','\t','\n',null,false,{},[],NaN,Infinity])assert.equal(M.number(value),null);
 for(const input of [[1,-1,1],[-1,-2,1],[1e308,0,1e-308]])assert.equal(M.deltaRate(...input),null);
 assert.equal(M.cpuPercent({total:2,idle:5},{total:1,idle:4}),null);
 const prior={generation:'g',stats:stats(9)},current={generation:'g',stats:stats(10)};
 for(const row of [prior,current])for(const k of ['usage','system','online'])row.stats.cpuCounters[k]=String(row.stats.cpuCounters[k]);
 assert.equal(M.batchMetrics(current,prior).cpu,240,'numeric strings cannot compare lexicographically across a digit boundary');
 current.stats.cpuCounters={usage:1e308,system:9001,online:1e308};prior.stats.cpuCounters={usage:0,system:9000,online:1e308};
 assert.equal(M.batchMetrics(current,prior).cpu,null);
});

test('rates reject missing device identity, reset, interface, boot and 120-second boundary changes',()=>{
 const dev={readBytes:100,writeBytes:100};assert.equal(M.diskRates({monotonicSeconds:2,devices:{sda:{...dev,readBytes:200}}},{monotonicSeconds:1,devices:{sda:dev}},[{device:'sda'}]).read,null);
 const prior=sample(container(1),1),next=sample(container(1),121);assert.equal(M.batchMetrics(next,prior).rx,10);next.stats.monotonicSeconds=121.001;assert.equal(M.batchMetrics(next,prior).rx,null);
 const system={sampledAtMs:'9000',monotonicSeconds:9,bootId:'one',network:{interface:'eth0',identity:'2',counters:{rxBytes:10,txBytes:20}}};
 const current={...system,sampledAtMs:'10000',monotonicSeconds:10,network:{...system.network,counters:{rxBytes:20,txBytes:40}}};assert.deepEqual(M.networkRates(current,system),{rx:10,tx:20});
 for(const changed of [{...current,bootId:'two'},{...current,monotonicSeconds:8},{...current,network:{...current.network,identity:'3'}},{...current,network:{...current.network,counters:{rxBytes:0,txBytes:0}}}])assert.deepEqual(M.networkRates(changed,system),{rx:null,tx:null});
});

test('duplicate inventory names or IDs are rejected before rendering ambiguous native controls',()=>{
 const a=container(1),base={schemaVersion:1,sampledAtMs:1000,dockerAvailable:true,folders:[],summary:{}};
 for(const containers of [[a,{...container(2),name:a.name}],[a,{...a,name:'second'}],[{...a,id:'broken'}]])assert.equal(M.validPayload('docker',{...base,containers}),false);
 assert.equal(M.validPayload('docker',{...base,containers:[a]}),true);
});

test('native-menu metadata rejects injection while preserving encoded native editor paths',()=>{
 const c={...container(1),autostart:false,menu:{template:'/boot/config/my +100%.xml',webUi:'https://example.invalid/ui',shell:'/bin/bash'}};
 assert.equal(M.nativeMenuReady(c),true);
 for(const key of ['template','webUi','tailscaleWebUi','shell','support','project','registry','donate','readme'])for(const value of ['x\" onerror=alert(1)','x\' onclick=alert(1)','<img src=x>','x\\y','x\nalert(1)','x&quote;'])assert.equal(M.nativeMenuReady({...c,menu:{...c.menu,[key]:value}}),false,`${key}: ${value}`);
 for(const value of ['javascript:alert(1)','data:text/html,test','//example.invalid','https://example.invalid/a b'])assert.equal(M.nativeMenuReady({...c,menu:{...c.menu,webUi:value}}),false);
 assert.equal(M.nativeMenuReady({...c,autostart:null}),false);assert.equal(M.nativeMenuReady({...c,name:'x\" onclick=alert(1)'}),false);
 for(const path of ['/boot/config/my +100%.xml',"/boot/config/my'quoted.xml",'/boot/config/my\"quoted.xml']){
  const url=new URL(M.editUrl(path),'http://fixture.invalid');assert.equal(url.pathname,'/Docker/UpdateContainer');assert.equal(decodeURIComponent(url.searchParams.get('xmlTemplate').replace(/\+/g,' ')),'edit:'+path);
 }
});

function batchHarness(){
 const c=container(1),state={docker:{containers:[c]},samples:new Map(),batchCursor:0,container:c.name,history:[],gpuBlocked:new Map(),inventoryVersions:new Map([[c.id,{token:1}]])};
 const ctx={state,M,status:{},stopped:false,document:{hidden:false},Date:{now:()=>100000},endpoints:{batch:'/synthetic'},renderContainerRows(){},renderContainerInspector(){},renderBatchStatus(){},refreshAge(){}};
 ctx.payload={schemaVersion:1,status:'ok',sampledAtMs:100000,requestedCount:1,containers:[sample(c,100)]};ctx.request=async()=>ctx.payload;
 vm.createContext(ctx);vm.runInContext(fn('historyGap','trend'),ctx);vm.runInContext('async '+fn('batchLoop','onClick'),ctx);return ctx;
}

test('in-flight batch cannot cross an observed stop/start of the same immutable ID',async()=>{
 const h=batchHarness(),c=h.state.docker.containers[0];h.request=async()=>{h.state.inventoryVersions=new Map([[c.id,{token:3}]]);return h.payload;};await h.batchLoop();assert.equal(h.state.samples.size,0);
});

test('future batch envelope cannot poison the last accepted timestamp or block recovery',async()=>{
 const h=batchHarness();h.payload.sampledAtMs=200000;await assert.rejects(h.batchLoop(),/Future/);assert.equal(h.status.batch,undefined);
 h.payload.sampledAtMs=100000;await h.batchLoop();assert.equal(h.state.samples.size,1);
});

test('general feeds reject future samples and recover without discarding last valid data',()=>{
 let now=100000;const h={M,status:{},state:{},stopped:false,Date:{now:()=>now},renderSystem(){},refreshAge(){}};
 vm.createContext(h);vm.runInContext(fn('accept','request').replace(/async\s*$/,''),h);
 const feed={schemaVersion:1,sampledAtMs:100000,cpu:{cores:[],counters:{}},memory:{},network:{}};h.accept('system',feed);
 assert.throws(()=>h.accept('system',{...feed,sampledAtMs:102000}),/Future/);assert.equal(h.state.system,feed);
 now=101000;h.accept('system',{...feed,sampledAtMs:101000});assert.equal(h.status.system.sample,101000);
 h.accept('system',{...feed,sampledAtMs:1001000,_racklineClockOffset:-900000});assert.equal(h.status.system.sample,1001000,'HTTP clock offset remains supported');
});

test('outage history is bounded to five minutes and does not extend a stale continuous line',()=>{
 const h={state:{history:Array.from({length:500},(_,n)=>({time:n*1000,cpu:50}))},Date:{now:()=>600000}};vm.createContext(h);vm.runInContext(fn('historyGap','trend'),h);
 h.historyGap();assert(h.state.history.length<=300);assert(h.state.history.every(p=>p.time>=300000));assert.equal(h.state.history.at(-1).cpu,null);
 const size=h.state.history.length;h.historyGap();assert.equal(h.state.history.length,size);h.Date.now=()=>1000000;h.historyGap();assert.equal(h.state.history.length,0);
});

test('daemon and receipt timestamp source transitions invalidate rate baselines',()=>{
 const prior=sample(container(1),100),next=sample(container(1),102);prior.stats.timestampSource='daemon';next.stats.timestampSource='received';assert.equal(M.batchMetrics(next,prior).cpu,null);assert.equal(M.batchMetrics(next,prior).rx,null);next.stats.timestampSource='daemon';assert.equal(M.batchMetrics(next,prior).rx,10);
});

test('rendered inventory tokens observe stop/start and recreation while churn maps stay bounded',()=>{
 const c=container(1),state={docker:{containers:[c],folders:[],summary:{}},samples:new Map(),gpuBlocked:new Map(),inventoryVersions:new Map(),inventorySequence:0,container:'',folder:'all'};
 const ctx={state,M,Date:{now:()=>100000},stableMarkup(){},$:()=>({}),text(){},renderContainerRows(){},renderContainerInspector(){},renderAlerts(){},esc:String,num:M.number,pct:String,bytes:String,currentContainer:()=>null};
 vm.createContext(ctx);vm.runInContext(fn('renderDocker','visibleContainers'),ctx);ctx.renderDocker();const first=state.inventoryVersions.get(c.id);ctx.renderDocker();assert.equal(state.inventoryVersions.get(c.id),first);
 state.docker.containers=[{...c,state:'stopped'}];ctx.renderDocker();state.docker.containers=[c];ctx.renderDocker();assert.notEqual(state.inventoryVersions.get(c.id),first);
 for(let n=0;n<500;n++){const next=container(n+2);state.gpuBlocked.set('gone-'+n,90000);state.docker.containers=[next];ctx.renderDocker();assert.equal(state.inventoryVersions.size,1);assert.equal(state.gpuBlocked.size,0);}
});

test('row age includes actual daemon sample age rather than reporting only HTTP receipt time',()=>{
 const c=container(1),entry={sample:sample(c,81),received:100000,advancedAt:100000,clockOffset:0};const h={state:{samples:new Map([[c.id,entry]])},Date:{now:()=>100000}};vm.createContext(h);vm.runInContext(fn('sampleFresh','gpuFresh'),h);
 assert.equal(h.rowAge(c),'19 s ago');h.Date.now=()=>102000;assert.equal(h.rowAge(c),'Stale · 21 s ago');
 entry.sample.stats.sampledAtMs=9000;entry.clockOffset=91000;h.Date.now=()=>100000;assert.equal(h.rowAge(c),'0 s ago');
});

test('unknown or transient container state never becomes an assumed running native control',()=>{
 const c={...container(1),autostart:false,menu:{template:'synthetic.xml'}};for(const state of [undefined,null,'unknown','restarting',{}])assert.equal(M.nativeMenuReady({...c,state}),false);assert.equal(M.nativeMenuReady(c),true);
});
