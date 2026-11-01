import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import assert from 'node:assert/strict';
const source=fs.readFileSync(new URL('../source/usr/local/emhttp/plugins/waz.dashboard/assets/js/workloads.js',import.meta.url),'utf8');
const container=()=>({id:'a'.repeat(12),name:'alpha',state:'running',health:'healthy'});
const flush=()=>new Promise(resolve=>setImmediate(resolve));
function harness(){
 const calls=[],timers=[],panel={open:true},fields={'waz-selected-state':{}};
 const root={querySelector:()=>panel,hasAttribute:()=>true,style:{}};
 const document={hidden:false,querySelector:()=>root,getElementById:id=>fields[id]};
 const window={localStorage:{getItem(){return null},setItem(){}},setTimeout(fn,delay){timers.push({fn,delay});return timers.length},clearTimeout(){}};
 let response=()=>({selected:{...container(),stats:{available:true,sampledAtMs:1000,cpuCounters:{usage:100,system:1000,online:4}}}});
 const fetch=async url=>{calls.push(url);return {ok:true,json:async()=>response(url)}};
 const cut=source.lastIndexOf('\n  removeNativeTiles();\n');assert.ok(cut>0);
 class Clock extends Date { static now(){return 50000;} }
 vm.runInNewContext(source.slice(0,cut)+`window.fixture={stats:statsLoop,overview:workloadsLoop,activity:handleActivity,identity:sameContainerStatsIdentity,seed:function(c,rows){selectedContainer=c.name;lastData={containers:rows||[c],selected:c};},live:function(){return liveStats;},stop:function(){stopped=true;}};})();`,{window,document,fetch,console,Date:Clock});
 window.fixture.seed(container());
 return {window,document,panel,calls,timers,fields,seed:(c,rows)=>window.fixture.seed(c,rows),respond:fn=>response=fn};
}
test('stats query targets only the selected running container at a one-second cadence',async()=>{
 const h=harness();h.window.fixture.stats();await flush();
 assert.equal(h.calls.length,1);assert.match(h.calls[0],/section=stats&selected=alpha/);assert.ok(h.timers.at(-1).delay>800&&h.timers.at(-1).delay<=1000);
 assert.equal(h.window.fixture.live().stats.cpuPercent,null,'First one-shot sample has no CPU delta');
 h.respond(()=>({selected:{...container(),stats:{available:true,sampledAtMs:2000,cpuCounters:{usage:150,system:2000,online:4}}}}));
 h.window.fixture.stats();await flush();assert.equal(h.window.fixture.live().stats.cpuPercent,20);
 h.respond(()=>({selected:{...container(),stats:{available:true,sampledAtMs:3000,cpuCounters:{usage:150,system:3000,online:4}}}}));
 h.window.fixture.stats();await flush();assert.equal(h.window.fixture.live().stats.cpuPercent,0,'Idle CPU remains zero');
});
test('collapsed, hidden, paused and stopped selections generate no stats requests',async()=>{
 const h=harness();h.panel.open=false;h.window.fixture.stats();await flush();assert.equal(h.calls.length,0);
 h.panel.open=true;h.document.hidden=true;h.window.fixture.stats();await flush();assert.equal(h.calls.length,0);
 h.document.hidden=false;
 for(const state of ['stopped','paused','restarting']){h.seed({...container(),state});h.window.fixture.stats();await flush();assert.equal(h.calls.length,0);}
});
test('full overview stays at five seconds and omits selected inspection when collapsed',async()=>{
 const h=harness();h.panel.open=false;h.respond(()=>({containers:[container()],summary:{},pools:[],folders:[],state:'normal'}));
 h.window.fixture.overview();await flush();assert.equal(h.calls.length,1);assert.ok(!h.calls[0].includes('selected='));assert.ok(!h.calls[0].includes('section=stats'));
 assert.ok(h.timers.at(-1).delay>4500&&h.timers.at(-1).delay<=5000);
});
test('in-flight stats never overlap and late results cannot cross container selections',async()=>{
 const h=harness();let done;h.respond(()=>new Promise(resolve=>done=resolve));
 h.window.fixture.stats();h.window.fixture.stats();await flush();assert.equal(h.calls.length,1);
 h.seed({id:'b'.repeat(12),name:'beta',state:'running'});done({selected:{...container(),stats:{available:true,sampledAtMs:1000}}});await flush();
 assert.equal(h.window.fixture.live(),null);
});
test('counter resets and recreated containers cannot produce misleading CPU samples',async()=>{
 const h=harness();h.window.fixture.stats();await flush();
 h.respond(()=>({selected:{...container(),stats:{available:true,sampledAtMs:2000,cpuCounters:{usage:1,system:10,online:4}}}}));h.window.fixture.stats();await flush();assert.equal(h.window.fixture.live().stats.cpuPercent,null);
 h.respond(()=>({selected:{...container(),id:'new-id',stats:{available:true}}}));h.window.fixture.stats();await flush();assert.equal(h.window.fixture.live(),null);
});

test('full Docker stats IDs match unique native short IDs without losing samples or rate keys',async()=>{
 const h=harness();
 h.respond(()=>({selected:{...container(),id:'a'.repeat(64),stats:{available:true,memoryBytes:512,sampledAtMs:1000,cpuCounters:{usage:100,system:1000,online:4}}}}));
 h.window.fixture.stats();await flush();assert.equal(h.window.fixture.live().id,container().id);assert.equal(h.window.fixture.live().stats.memoryBytes,512);
 h.respond(()=>({selected:{...container(),id:'a'.repeat(64),stats:{available:true,sampledAtMs:2000,cpuCounters:{usage:150,system:2000,online:4}}}}));
 h.window.fixture.stats();await flush();assert.equal(h.window.fixture.live().stats.cpuPercent,20);
});

test('identity matching accepts exact/full/short IDs only with the same unique container name',()=>{
 const h=harness(),match=h.window.fixture.identity,short=container(),full={...short,id:'a'.repeat(64)};
 assert.equal(match(short,short,[short]),true);assert.equal(match(full,full,[full]),true);
 assert.equal(match(short,full,[short]),true);assert.equal(match(full,short,[full]),true);
 for(const sample of [null,{name:'alpha'},{...full,id:''},{...full,id:'a'.repeat(8)},{...full,name:'beta'},{...full,id:'b'.repeat(64)}])assert.equal(match(short,sample,[short]),false);
 assert.equal(match(short,full,[]),false);assert.equal(match(short,full,null),false);
 assert.equal(match(short,full,[short,{id:short.id,name:'beta'}]),false,'Duplicate short ID is ambiguous');
 const collision={id:'a'.repeat(12)+'b'.repeat(52),name:'beta'};
 assert.equal(match(full,short,[full,collision]),false,'Short sample cannot disambiguate full IDs');
});

test('ambiguous inventory and wrong-name samples are never rendered',async()=>{
 const h=harness();h.seed(container(),[container(),{...container(),name:'other'}]);
 h.respond(()=>({selected:{...container(),id:'a'.repeat(64),stats:{available:true,memoryBytes:123}}}));
 h.window.fixture.stats();await flush();assert.equal(h.window.fixture.live(),null);
 h.seed(container());h.respond(()=>({selected:{...container(),name:'other',id:'a'.repeat(64),stats:{available:true}}}));
 h.window.fixture.stats();await flush();assert.equal(h.window.fixture.live(),null);
});
