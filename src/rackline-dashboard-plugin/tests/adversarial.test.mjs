import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {createRequire} from 'node:module';
const M=createRequire(import.meta.url)('../source/usr/local/emhttp/plugins/rackline.dashboard/assets/js/model.js');
const app=fs.readFileSync(new URL('../source/usr/local/emhttp/plugins/rackline.dashboard/assets/js/app.js',import.meta.url),'utf8');
const fn=(start,end)=>app.slice(app.indexOf('function '+start+'('),app.indexOf('function '+end+'('));

test('adversarial: validPayload rejects nested null CPU core instead of poisoning render',()=>{
 const sample={schemaVersion:1,sampledAtMs:1000,cpu:{cores:[null],counters:{}},memory:{},network:{}};
 assert.equal(M.validPayload('system',sample),false);
});
test('adversarial: validPayload rejects null pool instead of poisoning drive rendering',()=>{
 const sample={schemaVersion:1,sampledAtMs:1000,array:{disks:[]},parity:{disks:[]},pools:[null]};
 assert.equal(M.validPayload('storage',sample),false);
});
test('adversarial: old successful sample is stale despite a recent HTTP response',()=>{
 const elements=new Map();const $=id=>{if(!elements.has(id))elements.set(id,{textContent:'',classList:{toggle(){}}});return elements.get(id);};
 const context={renderDockerActions(){},status:{system:{received:100000,sample:1000,error:false}},$: $,Date:{now:()=>100000},text:(id,v)=>$(id).textContent=v,when:String,root:{classList:{add(){},remove(){}}}};
 vm.createContext(context);vm.runInContext(fn('refreshAge','currentContainer'),context);context.refreshAge();
 assert.match($('rl-system-age').textContent,/stale/);
});
test('adversarial: I/O refresh preserves the selected drive detail wrapper',()=>{
 let replacements=0;const dataNode={innerHTML:''};const inspector={dataset:{},querySelector:()=>dataNode,set innerHTML(v){replacements++;},get innerHTML(){return '';}};
 const drive={key:'sda',device:'sda',name:'disk1',smart:{}};
 const context={observeSmartSelection(){},ensureSmartState(){},renderSmartActions(){},smartAction:{states:new Map()},M,status:{},state:{drive:'sda'},$:()=>inspector,allDrives:()=>[drive],ioRates:()=>({devices:{}}),esc:String,stateBadge:()=>'',keyValues:()=>'',bytes:()=>'',rate:()=>'',temp:()=>'',num:M.number,when:String,encodeURIComponent};
 vm.createContext(context);vm.runInContext(fn('renderDriveInspector','renderBays'),context);
 context.renderDriveInspector(); const before=replacements;context.renderDriveInspector();
 assert.equal(replacements,before,'Stable selected drive must not replace its wrapper every I/O poll');
});
test('adversarial: native menu is released when template disappears without ID changing',()=>{
 const id='a'.repeat(12),button={dataset:{}},nodes=new Map([[id,button]]),menus=new Map();
 const jq=()=>({off(){return this;}});
 const window={jQuery:jq,addDockerContainerContext(){const menu={querySelectorAll:()=>[],parentNode:{removeChild(){nodes.delete('dropdown-'+id);}}};nodes.set('dropdown-'+id,menu);}};
 const context={window,document:{},$:key=>nodes.get(key),M,nativeMenus:menus};vm.createContext(context);vm.runInContext(fn('removeNativeMenu','updateRowMetrics'),context);
 const c={id,name:'demo',state:'running',autostart:false,menu:{template:'example.xml'}};
 context.syncNativeMenus([c]);assert.equal(menus.size,1);
 nodes.delete(id);context.syncNativeMenus([{...c,menu:{}}]);
 assert.equal(menus.size,0,'Unavailable native controls must not leave old native menu/action delegates owned');
});

test('adversarial: normalized legacy Docker error cannot become live zero metrics',()=>{
 const sample={stats:{available:true,sampledAtMs:2000,cpuCounters:{usage:0,system:0,online:1},memoryBytes:0,networkRxBytes:0,networkTxBytes:0,blockReadBytes:0,blockWriteBytes:0}};
 assert.equal(M.validStats(sample.stats),false);
 assert.deepEqual(M.containerMetrics(sample),{cpu:null,memory:null,rx:null,tx:null,read:null,write:null});
 assert.equal(M.validPayload('selected',{sampledAtMs:2000,selected:sample}),false);
});
test('adversarial: replay does not advance freshness and regression preserves accepted state',()=>{
 let now=100000;
 const context={stopped:false,M,status:{},state:{},Date:{now:()=>now},renderSystem(){},renderDrives(){},renderDocker(){},renderIo(){},renderDriveRows(){},renderCooling(){},refreshAge(){}};
 vm.createContext(context);vm.runInContext(fn('accept','request').replace(/async\s*$/,''),context);
 const payload={schemaVersion:1,sampledAtMs:50000,cpu:{cores:[],counters:{}},memory:{},network:{},_racklineClockOffset:50000};
 context.accept('system',payload);assert.equal(context.status.system.advancedAt,100000);assert.equal(context.status.system.clockOffset,50000);
 now=110000;context.accept('system',{...payload});assert.equal(context.status.system.advancedAt,100000);assert.equal(context.status.system.received,110000);
 const accepted=context.state.system;assert.throws(()=>context.accept('system',{...payload,sampledAtMs:49000}),/Regressing/);assert.equal(context.state.system,accepted);
});
test('adversarial: HTTP clock offset avoids false stale flags but frozen feeds still age',()=>{
 const elements=new Map();const $=id=>{if(!elements.has(id))elements.set(id,{textContent:'',classList:{toggle(){}}});return elements.get(id);};
 const context={renderDockerActions(){},status:{system:{received:100000,advancedAt:100000,sample:1000,clockOffset:99000,error:false}},$: $,Date:{now:()=>100000},text:(id,v)=>$(id).textContent=v,when:String,root:{classList:{add(){},remove(){}}}};
 vm.createContext(context);vm.runInContext(fn('refreshAge','currentContainer'),context);context.refreshAge();assert.equal($('rl-system-age').textContent,'');assert.equal($('rl-system-age').hidden,true);
 context.status.system.advancedAt=90000;context.refreshAge();assert.match($('rl-system-age').textContent,/stale/);
});
