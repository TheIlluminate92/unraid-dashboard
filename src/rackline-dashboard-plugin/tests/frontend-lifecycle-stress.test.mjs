// Actual app source in a synthetic DOM/event-loop harness. This is not browser QA.
import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import fs from 'node:fs';
import {createRequire} from 'node:module';
import {performance} from 'node:perf_hooks';
const root=new URL('../source/usr/local/emhttp/plugins/rackline.dashboard/',import.meta.url);
const model=fs.readFileSync(new URL('assets/js/model.js',root),'utf8'),app=fs.readFileSync(new URL('assets/js/app.js',root),'utf8');
const M=createRequire(import.meta.url)('../source/usr/local/emhttp/plugins/rackline.dashboard/assets/js/model.js');
const fn=(a,b)=>app.slice(app.indexOf('function '+a+'('),app.indexOf('function '+b+'('));
function target(){
 const listeners=new Map();return {listeners,addEventListener(type,fn,options){if(!listeners.has(type))listeners.set(type,new Map());listeners.get(type).set(fn,options);},removeEventListener(type,fn){listeners.get(type)?.delete(fn);},emit(type,event={}){for(const [fn,options]of [...listeners.get(type)||[]]){if(options?.once)this.removeEventListener(type,fn);fn(event);}},listenerCount(){return [...listeners.values()].reduce((n,m)=>n+m.size,0);}};
}
function harness(options={}){
 let clock=100000,serial=0,reloads=0;const elements=new Map(),timeouts=new Map(),intervals=new Map(),requests=[],handlers=options.handlers||{};
 function element(id){if(elements.has(id))return elements.get(id);const classes=new Set();const e={...target(),id,textContent:'',innerHTML:'',dataset:{},style:{},hidden:false,setAttribute(){},focus(){},contains(){return false;},querySelectorAll(){return[];},querySelector(){return null;},appendChild(){},classList:{add:c=>classes.add(c),remove:c=>classes.delete(c),toggle:(c,on)=>on?classes.add(c):classes.delete(c),contains:c=>classes.has(c)}};elements.set(id,e);return e;}
 for(const match of fs.readFileSync(new URL('include/view.php',root),'utf8').matchAll(/id="([^"]+)"/g))element(match[1]);element('rackline-bootstrap').textContent=JSON.stringify({hostName:'Synthetic',available:options.available!==false,fanAvailable:options.fan===true});
 const document={...target(),readyState:'complete',hidden:false,activeElement:null,getElementById:id=>elements.get(id)||null,createElement:()=>element('created'),body:element('body')};
 const window={...target()};
 const base={schemaVersion:1,sampledAtMs:clock};const feeds={system:{...base,cpu:{cores:[],counters:{}},memory:{},network:{}},storage:{...base,array:{disks:[]},parity:{disks:[],configured:false},pools:[]},io:{...base,status:'ok',monotonicSeconds:100,devices:{}},docker:{...base,dockerAvailable:true,containers:[],folders:[],summary:{}},fan:{enabled:true,mode:'auto',controller:[],shelves:[]}};
 const ctx={window,document,location:{hash:options.hash||'',href:'',reload(){reloads++;}},history:{pushState(){}},AbortController,Date:class extends Date{static now(){return clock;}},console,
  setTimeout(fn,ms){const id=++serial;timeouts.set(id,{fn,at:clock+ms});return id;},clearTimeout:id=>timeouts.delete(id),setInterval(fn,ms){const id=++serial;intervals.set(id,{fn,ms,at:clock+ms});return id;},clearInterval:id=>intervals.delete(id),
  fetch(url,init){const key=url.includes('metrics.php')?'system':url.includes('storage.php')?'storage':url.includes('io.php')?'io':url.includes('workloads.php')?'docker':url.includes('containers.php')?'batch':'fan';const req={url,init,key};requests.push(req);return handlers[key]?handlers[key](req):Promise.resolve({ok:true,json:async()=>structuredClone(feeds[key])});}};
 if(options.ResizeObserver)ctx.ResizeObserver=options.ResizeObserver;
 vm.createContext(ctx);vm.runInContext(model,ctx);
 const h={ctx,window,document,feeds,handlers,requests,timeouts,intervals,e:element,get clock(){return clock;},get reloads(){return reloads;},load(){vm.runInContext(app,ctx);},async flush(){for(let i=0;i<24;i++)await Promise.resolve();},async tick(ms){clock+=ms;for(const [id,t]of [...timeouts])if(t.at<=clock){timeouts.delete(id);t.fn();}for(const t of intervals.values())if(t.at<=clock){t.at=clock+t.ms;t.fn();}await this.flush();},listeners(){return window.listenerCount()+document.listenerCount()+[...elements.values()].reduce((n,e)=>n+e.listenerCount(),0);}};
 h.load();return h;
}

test('lifecycle stress: 100 reinitializations retain exactly one set of timers and listeners',async t=>{
 const h=harness();await h.flush();const listeners=h.listeners(),timers=h.timeouts.size;assert.equal(listeners,10);assert.equal(timers,6);
 const start=performance.now();for(let n=0;n<100;n++){const old=h.window.Rackline;h.load();await h.flush();assert.equal(h.listeners(),listeners);assert.equal(h.timeouts.size,timers);assert.equal(h.intervals.size,1);const currentEdit=h.window.editContainer;old.destroy();assert.equal(h.window.editContainer,currentEdit,'stale teardown must not restore a native wrapper over the new app');}
 h.window.Rackline.destroy();assert.equal(h.listeners(),0);assert.equal(h.timeouts.size,0);assert.equal(h.intervals.size,0);t.diagnostic(`100 synthetic reinitializations in ${(performance.now()-start).toFixed(1)} ms.`);
});

test('teardown removes only owned wrappers and leaves later native replacements intact',async()=>{
 const h=harness();await h.flush();const edit=()=>{},load=()=>{};h.window.editContainer=edit;h.window.loadlist=load;h.window.Rackline.destroy();assert.equal(h.window.editContainer,edit);assert.equal(h.window.loadlist,load);
});

test('destroy clears request timeout handles even when a synthetic transport ignores abort',async()=>{
 let resolve;const h=harness({handlers:{system:()=>new Promise(r=>resolve=r)}});await h.flush();h.window.Rackline.destroy();assert.equal(h.timeouts.size,0);assert.equal(h.intervals.size,0);assert.equal(h.listeners(),0);
 const before=h.e('rl-system-age').textContent;resolve({ok:true,json:async()=>h.feeds.system});await h.flush();assert.equal(h.e('rl-system-age').textContent,before);assert.equal(h.timeouts.size,0);
});

test('hide/reveal race discards earlier responses even if the transport ignores abort',async()=>{
 let resolve;const h=harness({handlers:{system:()=>new Promise(r=>resolve=r)}});await h.flush();const original=h.requests.find(r=>r.key==='system');
 h.document.hidden=true;h.document.emit('visibilitychange');assert.equal(original.init.signal.aborted,true);h.document.hidden=false;h.document.emit('visibilitychange');
 resolve({ok:true,json:async()=>({...h.feeds.system,cpu:{cores:[],counters:{},model:'INTERRUPTED SAMPLE'}})});delete h.handlers.system;await h.flush();assert.notEqual(h.e('rl-model').textContent,'INTERRUPTED SAMPLE');assert.doesNotMatch(h.e('rl-system-age').textContent,/unavailable/);
 h.feeds.system={...h.feeds.system,sampledAtMs:101000,cpu:{cores:[],counters:{},model:'RECOVERED'}};await h.tick(1000);assert.equal(h.e('rl-model').textContent,'RECOVERED');h.window.Rackline.destroy();
});

test('abort during hidden transition is cancellation, not a fabricated feed failure',async()=>{
 const h=harness({handlers:{system:req=>new Promise((resolve,reject)=>req.init.signal.addEventListener('abort',()=>reject(new Error('Synthetic abort'))))}});await h.flush();h.document.hidden=true;h.document.emit('visibilitychange');await h.flush();assert.doesNotMatch(h.e('rl-system-age').textContent,/unavailable/);h.window.Rackline.destroy();
});

test('polling remains bounded through 8-second timeouts and resumes without overlapping a feed',async()=>{
 let active=0,maxActive=0;const h=harness({handlers:{system:req=>{active++;maxActive=Math.max(maxActive,active);return new Promise((resolve,reject)=>req.init.signal.addEventListener('abort',()=>{active--;reject(new Error('Synthetic timeout'));}));}}});await h.flush();
 await h.tick(1000);await h.tick(1000);assert.equal(h.requests.filter(r=>r.key==='system').length,1);
 await h.tick(6000);assert.equal(active,0);assert.match(h.e('rl-system-age').textContent,/unavailable/);
 delete h.handlers.system;h.feeds.system={...h.feeds.system,sampledAtMs:109000};await h.tick(1000);assert.match(h.e('rl-system-age').textContent,/updated/);assert.equal(maxActive,1);h.window.Rackline.destroy();assert.equal(h.timeouts.size,0);
});

test('100 hidden ticks issue no extra requests and optional fan 410 remains disabled',async()=>{
 const h=harness({fan:true});await h.flush();h.handlers.fan=async()=>({ok:false,status:410});await h.tick(5000);const fans=h.requests.filter(r=>r.key==='fan').length;
 h.document.hidden=true;h.document.emit('visibilitychange');const before=h.requests.length;for(let i=0;i<100;i++)await h.tick(5000);assert.equal(h.requests.length,before);
 h.document.hidden=false;h.document.emit('visibilitychange');await h.flush();await h.tick(5000);assert.equal(h.requests.filter(r=>r.key==='fan').length,fans);h.window.Rackline.destroy();
});

test('bfcache lifecycle keeps one recovery handler after pagehide and removes all work',async()=>{
 const h=harness();await h.flush();h.window.emit('pageshow',{persisted:false});assert.equal(h.reloads,0);h.window.emit('pagehide',{});assert.equal(h.timeouts.size,0);assert.equal(h.intervals.size,0);assert.equal(h.listeners(),1);h.window.emit('pageshow',{persisted:true});assert.equal(h.reloads,1);assert.equal(h.listeners(),0);
});

function menuHarness({fail=false}={}){
 const nodes=new Map(),delegates=new Set(),document={};let serial=0;
 const jq=()=>({off(type,selector){delegates.delete(type+' '+selector);return this;}});
 const window={jQuery:jq,addDockerContainerContext(...args){const id=args[10],actions=['action-'+(++serial),'action-'+(++serial)];for(const action of actions)delegates.add('click #'+action);delegates.add('click #'+id);delegates.add('contextmenu #'+id);nodes.set('dropdown-'+id,{querySelectorAll:()=>actions.map(id=>({id})),parentNode:{removeChild(){nodes.delete('dropdown-'+id);}}});if(fail)throw new Error('Synthetic partial native menu');}};
 const ctx={window,document,$:id=>nodes.get(id),M,nativeMenus:new Map()};vm.createContext(ctx);vm.runInContext(fn('removeNativeMenu','sampleFresh'),ctx);return{ctx,nodes,delegates};
}
const nativeContainer={id:'a'.repeat(64),name:'synthetic-menu',autostart:false,state:'running',menu:{template:'/boot/config/synthetic +100%.xml'}};

test('native-menu stress: 1,000 state changes leave no owned menus or delegated handlers',()=>{
 const h=menuHarness();for(let n=0;n<1000;n++){h.nodes.set(nativeContainer.id,{dataset:{}});h.ctx.syncNativeMenus([{...nativeContainer,state:n%2?'running':'stopped'}]);assert.equal(h.ctx.nativeMenus.size,1);assert.equal(h.delegates.size,4);}
 h.ctx.syncNativeMenus([]);assert.equal(h.ctx.nativeMenus.size,0);assert.equal(h.delegates.size,0);assert.equal(h.nodes.has('dropdown-'+nativeContainer.id),false);
});

test('partially failed native registration releases created menu and action delegates',()=>{
 const h=menuHarness({fail:true});const button={dataset:{}};h.nodes.set(nativeContainer.id,button);h.ctx.syncNativeMenus([nativeContainer]);assert.equal(h.ctx.nativeMenus.size,0);assert.equal(h.delegates.size,0);assert.equal(h.nodes.has('dropdown-'+nativeContainer.id),false);assert.equal(button.dataset.nativeFallback,'true');
});

test('native fallback recovers after a foreign menu disappears without retaining fallback routing',()=>{
 const h=menuHarness(),button={dataset:{}};h.nodes.set(nativeContainer.id,button);h.nodes.set('dropdown-'+nativeContainer.id,{});h.ctx.syncNativeMenus([nativeContainer]);assert.equal(button.dataset.nativeFallback,'true');assert.equal(h.delegates.size,0);
 h.nodes.delete('dropdown-'+nativeContainer.id);h.ctx.syncNativeMenus([nativeContainer]);assert.equal(button.dataset.nativeFallback,undefined);assert.equal(h.ctx.nativeMenus.size,1);h.ctx.syncNativeMenus([]);
});

test('row metric stress: 3,000 rendered rows share the inventory index instead of quadratic scans',t=>{
 let reads=0;const all=Array.from({length:3000},(_,n)=>({id:n.toString(16).padStart(64,'0'),get name(){reads++;return 'synthetic-'+n;},state:'running'}));
 const rows=all.map(c=>({dataset:{container:c.name},querySelector:()=>({})}));const state={docker:{containers:all},dockerIndex:M.inventoryIndex(all),samples:new Map(),gpu:null,gpuBlocked:new Map()};
 for(const c of all)state.samples.set(c.id,{sample:{id:c.id,name:c.name,stats:{sampledAtMs:100000,available:true}},metrics:{cpu:1,memory:1024},received:100000,advancedAt:100000});
 const ctx={state,M,q:()=>rows,Date:{now:()=>100000},pct:String,bytes:String,rate:String,pairedRate:()=>'',text(){},when:String};vm.createContext(ctx);vm.runInContext(fn('sampleFresh','historyGap'),ctx);
 reads=0;const start=performance.now();ctx.updateRowMetrics();assert(reads<all.length*10,`name reads: ${reads}`);t.diagnostic(`Synthetic 3,000-row metric update: ${(performance.now()-start).toFixed(1)} ms, ${reads} inventory name reads.`);
});

test('escaped malicious inventory text never becomes raw table markup or a native control',async()=>{
 const h=harness();await h.flush();h.feeds.docker={...h.feeds.docker,sampledAtMs:105000,containers:[{id:'a'.repeat(64),name:'<img src=x onerror=alert(1)>',image:'<svg/onload=alert(1)>',state:'running',autostart:true,menu:{template:'/boot/config/a.xml'},icon:'//example.invalid/track'}]};await h.tick(5000);
 const html=h.e('rl-container-rows').innerHTML;assert.match(html,/&lt;img/);assert.doesNotMatch(html,/<svg\/onload|<img src=x|src="\/\/example|data-native-menu/);assert.match(html,/Native Docker/);h.window.Rackline.destroy();
});

test('cooling cards cannot keep claiming normal health after transport failure or frozen responses',async()=>{
 const h=harness({fan:true});h.feeds.fan={enabled:true,mode:'auto',generatedAt:100,stale:false,controller:{state:'normal'},shelves:[{name:'Synthetic shelf',telemetryMessage:'Fresh'}],ipmi:{available:true,state:'normal',stale:false}};await h.flush();await h.tick(5000);
 h.handlers.fan=async()=>{throw new Error('Synthetic outage');};await h.tick(5000);let html=h.e('rl-cooling-status').innerHTML;assert.match(html,/Unavailable · cached/);assert.doesNotMatch(html,/<dd>normal<\/dd>/);assert.match(h.e('rl-fan-age').textContent,/unavailable/);
 delete h.handlers.fan;await h.tick(5000);await h.tick(5000);await h.tick(5000);html=h.e('rl-cooling-status').innerHTML;assert.match(html,/Unavailable · cached/);assert.match(h.e('rl-fan-age').textContent,/stale/);h.window.Rackline.destroy();
});

test('JSON cannot forge the private HTTP clock offset to make future samples fresh',async()=>{
 const h=harness();await h.flush();h.feeds.system={...h.feeds.system,sampledAtMs:200000,_racklineClockOffset:-99000,cpu:{cores:[],counters:{},model:'FORGED'}};await h.tick(1000);assert.match(h.e('rl-system-age').textContent,/unavailable/);assert.notEqual(h.e('rl-model').textContent,'FORGED');h.window.Rackline.destroy();
});

test('container row selection and structural refresh restore focus to the matching row',()=>{
 let focused=0;const oldRow={dataset:{container:'synthetic'}};const newRow={dataset:{container:'synthetic'},focus(){focused++;}};
 const element={contains:node=>node===oldRow,querySelectorAll:()=>[newRow],innerHTML:''};const ctx={document:{activeElement:oldRow},$:()=>null};vm.createContext(ctx);vm.runInContext(fn('stableMarkup','text'),ctx);ctx.stableMarkup(element,'new synthetic markup');assert.equal(focused,1);ctx.stableMarkup(element,'new synthetic markup');assert.equal(focused,1);
});

test('CPU ResizeObserver observes the grid and disconnects across repeated lifecycle changes',async()=>{
 const observers=[];
 class ResizeObserver{constructor(callback){this.callback=callback;this.connected=false;observers.push(this);}observe(element){this.element=element;this.connected=true;}disconnect(){this.connected=false;}}
 const h=harness({ResizeObserver});await h.flush();
 for(let n=0;n<20;n++){
  assert.equal(observers.filter(o=>o.connected).length,1);assert.equal(observers.at(-1).element,h.e('rl-cores'));
  assert.equal(h.window.listeners.get('resize')?.size||0,0);
  h.load();await h.flush();
 }
 h.window.Rackline.destroy();assert.equal(observers.filter(o=>o.connected).length,0);assert.equal(h.listeners(),0);
});
