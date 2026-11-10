// Presentation only: preserve upstream states, freshness, and real messages.
import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import fs from 'node:fs';
import {createRequire} from 'node:module';
const M=createRequire(import.meta.url)('../source/usr/local/emhttp/plugins/rackline.dashboard/assets/js/model.js');
const app=fs.readFileSync(new URL('../source/usr/local/emhttp/plugins/rackline.dashboard/assets/js/app.js',import.meta.url),'utf8');
const fn=(a,b)=>app.slice(app.indexOf('function '+a+'('),app.indexOf('function '+b+'('));
function render(overrides={},feed={received:100000,advancedAt:100000,sample:100000},detection={}){
 const nodes=new Map(),rows=[];
 const data={enabled:true,stale:false,controller:{state:'normal'},watchdog:{state:'normal'},shelves:[{name:'Top'},{name:'Bottom',telemetryMessage:''}],ipmi:{available:true,state:'normal',mode:'manual'},...overrides};
 const context={M,state:{fan:data},status:{fan:feed},fansDetected:true,fanAbsent:false,...detection,
  Date:{now:()=>100000},$:id=>{if(!nodes.has(id))nodes.set(id,{});return nodes.get(id);},
  esc:v=>String(v),num:M.number,temp:()=>'',pct:()=>'',when:()=>'',text(){},
  keyValues:items=>{rows.push(items);return JSON.stringify(items);}};
 vm.createContext(context);vm.runInContext(fn('renderCooling','accept'),context);context.renderCooling();
 return {nodes,rows:JSON.parse(JSON.stringify(rows)),data};
}
test('only exact shelf supervision message shortens to Running; state is unchanged',()=>{
 const r=render({watchdog:{state:'normal',message:'Controller is running under supervision'}});assert.equal(r.rows[0].find(x=>x[0]==='Watchdog')[1],'Running');assert.equal(r.rows[0].find(x=>x[0]==='State')[1],'normal');assert.equal(r.data.controller.state,'normal');
});
test('shelf fault, warning, unknown, disabled and stale remain distinguishable',()=>{
 for(const state of ['fault','warning','unknown','recovering','restarting']){
  const r=render({controller:{state}});assert.equal(r.rows[0].find(x=>x[0]==='State')[1],state);
 }
 for(const [overrides,expected] of [[{stale:true},'Stale'],[{enabled:false},'Disabled'],[{controller:{}},'Unknown'],[{watchdog:{state:'fault'}},'fault']])assert.equal(render(overrides).rows[0].find(x=>x[0]==='State')[1],expected);
});
test('Top and Bottom omit empty telemetry rows but retain nonempty messages unchanged',()=>{
 for(const message of [undefined,null,'','  \n ']){
  const r=render({shelves:[{name:'Top',telemetryMessage:message},{name:'Bottom',telemetryMessage:message}]});
  assert(r.rows.slice(1,3).every(items=>!items.some(x=>x[0]==='Telemetry')));
 }
 for(const message of ['Sensor unavailable','normal','No message','  Real warning  ','<warning>',0,false,123,['Sensor fault']]){
  const r=render({shelves:[{name:'Top',telemetryMessage:message}]});assert.deepEqual(r.rows[1].find(x=>x[0]==='Telemetry')[1],message);
 }
});
test('transport error and stale samples retain cached warnings even with blank messages',()=>{
 for(const feed of [{error:true,received:100000},{received:1},{received:100000,advancedAt:1}]){
  const r=render({},feed);assert.equal(r.rows[0].find(x=>x[0]==='State')[1],'Unavailable · cached');
  assert.equal(r.rows[1].find(x=>x[0]==='Telemetry')[1],'Unavailable · cached values');
  assert.equal(r.rows.at(-1).find(x=>x[0]==='State')[1],'Unavailable · cached');
 }
});
test('unavailable and absent controllers preserve existing visibility and uncertainty',()=>{
 assert.match(render({},undefined,{fansDetected:false}).nodes.get('rl-cooling-status').innerHTML,/status unavailable/);
 assert.equal(render({},undefined,{fanAbsent:true}).nodes.get('rl-cooling').hidden,true);
 assert.equal(render({md12xxAvailable:false,ipmi:{available:false},shelves:[]}).rows.length,0);
});

test('only exact acknowledged IPMI message shortens; state and firmware reasons are unchanged',()=>{
 const message='Command acknowledged; RPM telemetry is shown separately';
 const r=render({ipmi:{available:true,state:'normal',mode:'auto',message}});
 assert.equal(r.rows.at(-1).find(x=>x[0]==='Message')[1],'Command acknowledged');assert.equal(r.rows.at(-1).find(x=>x[0]==='State')[1],'normal');assert.equal(r.data.ipmi.message,message);
 const firmware=render({ipmi:{available:true,state:'normal',mode:'firmware',message:'iDRAC automatic control'}});assert.equal(firmware.rows.at(-1).find(x=>x[0]==='Message')[1],'iDRAC automatic control');
});
test('arbitrary warning, fault, recovery and near-match messages are retained exactly',()=>{
 for(const message of ['Unknown status','Controller recovering','Controller fault','Controller is running under supervision but degraded','Command acknowledged; RPM telemetry is shown separately; warning',' command acknowledged ', '<fault>']){
  const r=render({watchdog:{state:'recovering',message},ipmi:{available:true,state:'fault',message}});
  assert.equal(r.rows[0].find(x=>x[0]==='Watchdog')[1],message);assert.equal(r.rows[0].find(x=>x[0]==='State')[1],'recovering');assert.equal(r.rows.at(-1).find(x=>x[0]==='Message')[1],message);assert.equal(r.rows.at(-1).find(x=>x[0]==='State')[1],'fault');
 }
});
test('IPMI warning, unknown, own stale flag and empty-message fallback remain truthful',()=>{
 for(const state of ['warning','fault','unknown','normal'])assert.equal(render({ipmi:{available:true,state,message:''}}).rows.at(-1).find(x=>x[0]==='State')[1],state);
 assert.equal(render({ipmi:{available:true,state:'normal',stale:true}}).rows.at(-1).find(x=>x[0]==='State')[1],'Stale');
 assert.equal(render({ipmi:{available:true}}).rows.at(-1).find(x=>x[0]==='State')[1],'Unknown');
 assert.equal(render({ipmi:{available:true,message:''}}).rows.at(-1).find(x=>x[0]==='Message')[1],'—');
});
