// Real frontend functions with synthetic transports only. No disk or browser runs.
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {createRequire} from 'node:module';
const M=createRequire(import.meta.url)('../source/usr/local/emhttp/plugins/rackline.dashboard/assets/js/model.js');
const app=fs.readFileSync(new URL('../source/usr/local/emhttp/plugins/rackline.dashboard/assets/js/app.js',import.meta.url),'utf8');
const code=app.slice(app.indexOf('function invalidateSmartAction('),app.indexOf('function renderDriveInspector('));
const token='a'.repeat(64),binding='b'.repeat(64),requestId='123456-'+'c'.repeat(32);
function harness(){
 let now=100000,renders=0;const calls=[],prompts=[],reads=[],timers=new Map(),nodes=new Map();let serial=0;
 const drive={key:'sde',name:'disk1',device:'sde',selectionToken:token};
 const target=M.smartTarget(drive);
 const ctx={M,Date:{now:()=>now,parse:Date.parse},AbortController,URLSearchParams,encodeURIComponent,stopped:false,visibilityEpoch:0,document:{hidden:false},window:{csrf_token:'synthetic-csrf-token',confirm(message){prompts.push(message);return true;}},smartAction:{busy:false,submitted:false,locked:false,message:'',target:'',displayed:target.key,generation:0,states:new Map(),controllers:new Set()},status:{storage:{received:now,advancedAt:now,sample:now,clockOffset:0}},state:{tab:'drives',drive:drive.key},controllers:new Set(),requestTimers:new Set(),allDrives:()=>[drive],visibleDrives:()=>[drive],renderDriveInspector(){renders++;},esc:s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])),stableMarkup(e,s){e.innerHTML=s;},$(id){if(!nodes.has(id))nodes.set(id,{});return nodes.get(id);},setTimeout(fn,ms){timers.set(++serial,{fn,ms});return serial;},clearTimeout(id){timers.delete(id);},request:async url=>{reads.push(url);return {status:200,supported:true,available:false};},fetch:async(url,init)=>{calls.push({url,init});const fields=new URLSearchParams(init.body);return {ok:true,status:200,headers:{get:()=>null},json:async()=>fields.get('action')==='prepare'?{status:200,binding,requestId,requiresWakeConfirmation:true}:capture(target)};}};
 const info={pending:false,supported:true,report:null,clockOffset:0,message:''};ctx.smartAction.states.set(target.key,info);
 vm.createContext(ctx);vm.runInContext(code,ctx);
 return{ctx,drive,target,info,calls,prompts,reads,nodes,timers,get renders(){return renders;},setNow(value){now=value;},async flush(){for(let i=0;i<20;i++)await Promise.resolve();}};
}
function capture(t,extra={}){return {status:200,binding,source:'rackline_capture',capturedAtMs:100000,exitStatus:0,hasWarnings:false,reportUrl:'/plugins/rackline.dashboard/include/smart-capture-report.php?name='+encodeURIComponent(t.name)+'&device='+encodeURIComponent(t.device)+'&identity='+t.identity+'&binding='+binding,...extra};}
test('SMART target/report validation never trusts device-only identities or other URLs',()=>{
 const h=harness();assert.equal(M.smartTarget({...h.drive,selectionToken:null}),null);
 for(const value of ['SDa','/dev/sde','sde?x=1','../sde','sde\n']){const d={...h.drive,device:value};if(value!=='SDa')assert.equal(M.smartTarget(d),null);}
 for(const mutation of [{source:'native'},{binding:'c'.repeat(64)},{reportUrl:'https://example.invalid'},{reportUrl:capture(h.target).reportUrl+'&run=1'},{capturedAtMs:true},{exitStatus:7},{hasWarnings:true},{exitStatus:256}])assert.equal(M.capturedReport(capture(h.target,mutation),h.target),null);
 assert(M.capturedReport(capture(h.target),h.target));assert(M.capturedReport(capture(h.target,{exitStatus:8,hasWarnings:true}),h.target));
 assert.equal(M.capturedReportAge({capturedAtMs:1000},86401001).label,'Old · 24 h ago');assert.equal(M.capturedReportAge({capturedAtMs:1001},1000).label,'Age unknown');
});
test('SMART state is one passive selection-bound read, never a disk action or poll',async()=>{
 const h=harness();h.ctx.smartAction.states.clear();h.ctx.ensureSmartState(h.drive);h.ctx.ensureSmartState(h.drive);await h.flush();
 assert.equal(h.reads.length,1);assert.equal(h.calls.length,0);assert.equal(h.prompts.length,0);assert.equal(h.ctx.smartAction.states.get(h.target.key).supported,true);
 h.ctx.ensureSmartState(h.drive);assert.equal(h.reads.length,1);assert.equal(h.reads[0],'/plugins/rackline.dashboard/include/smart-capture-state.php?name=disk1&device=sde&identity='+token);
});
test('state errors and unsupported controllers never display a fake working button',async()=>{
 for(const data of [{status:200,supported:false,available:false,reason:'unsupported_controller_options'},{status:503},{status:200,supported:true,available:true,...capture(M.smartTarget(harness().drive),{reportUrl:'/Main/Device'})}]){
  const h=harness();h.ctx.smartAction.states.clear();h.ctx.request=async()=>data;h.ctx.ensureSmartState(h.drive);await h.flush();h.ctx.renderSmartActions(h.drive);assert.doesNotMatch(h.nodes.get('rl-smart-actions').innerHTML,/data-smart-action/);await h.ctx.runSmartRead();assert.equal(h.calls.length,0);
 }
});
test('prepare then explicit named may-wake confirmation sends exactly one capture form',async()=>{
 const h=harness();await h.ctx.runSmartRead();assert.equal(h.calls.length,2);assert.match(h.prompts[0],/disk1 \(sde\).*may wake.*Do not remove, replace or reassign/);assert.match(h.prompts[0],/not a self-test/);
 for(const [i,call]of h.calls.entries()){
  assert.equal(call.url,'/plugins/rackline.dashboard/include/smart-capture.php');assert.equal(call.init.method,'POST');assert.equal(call.init.credentials,'same-origin');assert.equal(call.init.redirect,'error');
  const body=new URLSearchParams(call.init.body);assert.deepEqual(body.getAll('csrf_token'),['synthetic-csrf-token']);assert.equal(body.get('identity'),token);assert.equal(body.get('device'),'sde');assert.equal(body.get('action'),i?'capture':'prepare');assert.equal(body.get('wake'),i?'confirmed':null);
 }
 assert.equal(h.info.report.capturedAtMs,100000);assert.equal(h.ctx.smartAction.busy,false);assert.equal(h.ctx.smartAction.locked,false);assert.equal(h.ctx.controllers.size,0);assert.equal(h.ctx.smartAction.controllers.size,0);assert.equal(h.timers.size,0);
 h.ctx.renderSmartActions(h.drive);assert.match(h.nodes.get('rl-smart-actions').innerHTML,/View saved SMART report/);assert.match(h.nodes.get('rl-smart-actions').innerHTML,/Read SMART again/);
});
test('cancel after prepare does not read, loses no cached report and allows a later explicit attempt',async()=>{
 const h=harness();h.info.report=M.capturedReport(capture(h.target),h.target);h.ctx.window.confirm=()=>false;await h.ctx.runSmartRead();assert.equal(h.calls.length,1);assert.match(h.ctx.smartAction.message,/No disk read was submitted/);assert(h.info.report);assert.equal(h.ctx.smartAction.busy,false);assert.equal(h.ctx.smartAction.locked,false);
});
test('duplicate clicks during preparation or capture never produce duplicate posts',async()=>{
 for(const phase of ['prepare','capture']){const h=harness();const fetch=h.ctx.fetch;let release;h.ctx.fetch=async(url,init)=>{const response=await fetch(url,init);if(new URLSearchParams(init.body).get('action')===phase)await new Promise(r=>release=r);return response;};const pending=h.ctx.runSmartRead();await h.flush();await h.ctx.runSmartRead();assert.equal(h.calls.length,phase==='prepare'?1:2);release();await pending;assert.equal(h.calls.length,2);}
});
test('navigation, hide, filter, replacement, CSRF changes or away-and-back invalidate preparation',async()=>{
 for(const stage of ['prepare','confirm'])for(const change of [h=>h.ctx.invalidateSmartAction(),h=>h.ctx.document.hidden=true,h=>h.ctx.state.tab='docker',h=>h.ctx.visibleDrives=()=>[],h=>h.drive.selectionToken='d'.repeat(64),h=>h.ctx.window.csrf_token='changed-csrf-token',h=>{h.ctx.visibilityEpoch+=2;},h=>h.ctx.stopped=true]){
  const h=harness();if(stage==='confirm')h.ctx.window.confirm=()=>{change(h);return true;};else{const fetch=h.ctx.fetch;h.ctx.fetch=async(...args)=>{const response=await fetch(...args);change(h);return response;};}
  await h.ctx.runSmartRead();assert.equal(h.calls.length,1);assert.equal(h.ctx.smartAction.locked,false);assert.equal(h.ctx.smartAction.busy,false);
 }
});
test('observed hotplug and return never reuse pending authorization, even on same device locator',()=>{
 const h=harness();h.drive.selectionToken='d'.repeat(64);h.ctx.observeSmartSelection(h.drive);h.drive.selectionToken=token;h.ctx.observeSmartSelection(h.drive);assert.equal(h.ctx.smartAction.generation,2);
});
test('stale, duplicate, filtered or unauthenticated selection cannot prepare',async()=>{
 for(const change of [h=>h.ctx.status.storage.error=true,h=>h.ctx.status.storage.received=0,h=>h.ctx.allDrives=()=>[h.drive,{...h.drive,key:'other'}],h=>h.ctx.visibleDrives=()=>[],h=>delete h.ctx.window.csrf_token,h=>h.info.supported=false,h=>h.ctx.document.hidden=true,h=>h.ctx.state.tab='docker']){const h=harness();change(h);await h.ctx.runSmartRead();assert.equal(h.calls.length,0);}
});
test('malformed prepare results never prompt or submit capture',async()=>{
 for(const extra of [{binding:'unsafe'},{requestId:'0-'+'a'.repeat(32)},{requiresWakeConfirmation:false},{requestId:requestId+'\n'},{status:500}]){const h=harness();h.ctx.fetch=async()=>({ok:true,json:async()=>({status:200,binding,requestId,requiresWakeConfirmation:true,...extra})});await h.ctx.runSmartRead();assert.equal(h.prompts.length,0);assert.equal(h.ctx.smartAction.submitted,false);assert.equal(h.ctx.smartAction.locked,false);}
});
test('capture errors, malformed reports and lost connections preserve prior report and lock out retries',async()=>{
 for(const status of [400,403,409,429,502,503,507,'bad','network']){const h=harness(),previous=M.capturedReport(capture(h.target,{capturedAtMs:1000}),h.target);h.info.report=previous;const fetch=h.ctx.fetch;h.ctx.fetch=async(url,init)=>{const response=await fetch(url,init);if(new URLSearchParams(init.body).get('action')==='capture'){if(status==='network')throw Error('connection lost');if(status==='bad')return {ok:true,json:async()=>capture(h.target,{binding:'d'.repeat(64)})};return {ok:false,status};}return response;};await h.ctx.runSmartRead();assert.equal(h.info.report,previous);assert.equal(h.ctx.smartAction.locked,true);assert.match(h.ctx.smartAction.message,/unknown or failed/);await h.ctx.runSmartRead();assert.equal(h.calls.length,2);}
});
test('submitted captures use separate controllers: hide does not abort them or retarget result',async()=>{
 const h=harness();const fetch=h.ctx.fetch;let release;h.ctx.fetch=async(url,init)=>{const response=await fetch(url,init);if(new URLSearchParams(init.body).get('action')==='capture')await new Promise(r=>release=r);return response;};const pending=h.ctx.runSmartRead();await h.flush();assert.equal(h.ctx.controllers.size,0);assert.equal(h.ctx.smartAction.controllers.size,1);
 h.ctx.document.hidden=true;h.ctx.invalidateSmartAction();h.ctx.visibilityEpoch++;h.ctx.controllers.forEach(c=>c.abort());assert.equal(h.calls[1].init.signal.aborted,false);h.drive.device='sdf';release();await pending;assert.equal(h.info.report,null);assert.equal(h.ctx.smartAction.locked,false);assert.match(h.ctx.smartAction.message,/completed for disk1 \(sde\)/);
});
test('capture timeout is uncertainty, not cancellation, and releases timers/controllers',async()=>{
 const h=harness(),fetch=h.ctx.fetch;h.ctx.fetch=async(url,init)=>{const response=await fetch(url,init);if(new URLSearchParams(init.body).get('action')==='capture')return new Promise((resolve,reject)=>init.signal.addEventListener('abort',()=>reject(Error('timeout'))));return response;};const pending=h.ctx.runSmartRead();await h.flush();for(const entry of h.timers.values())entry.fn();await pending;assert.equal(h.ctx.smartAction.locked,true);assert.equal(h.ctx.smartAction.controllers.size,0);assert.equal(h.timers.size,0);
});
test('late passive state cannot attach after selection change and cache stays bounded',async()=>{
 const h=harness();h.ctx.smartAction.states.clear();let release;h.ctx.request=()=>new Promise(r=>release=r);h.ctx.ensureSmartState(h.drive);h.ctx.invalidateSmartAction();release({status:200,supported:true,available:true,...capture(h.target)});await h.flush();assert.equal(h.ctx.smartAction.states.size,0);
 h.ctx.request=async()=>({status:200,supported:true,available:false});for(let i=0;i<200;i++){h.drive.selectionToken=i.toString(16).padStart(64,'0');h.ctx.observeSmartSelection(h.drive);h.ctx.ensureSmartState(h.drive);await h.flush();}assert(h.ctx.smartAction.states.size<=64);assert.equal(h.calls.length,0);
});

test('lost response after server save retains only the displayed old report without claiming server state',async()=>{
 const h=harness();const old=M.capturedReport(capture(h.target,{capturedAtMs:1000}),h.target);h.info.report=old;let serverSaved=false;const fetch=h.ctx.fetch;
 h.ctx.fetch=async(url,init)=>{const response=await fetch(url,init);if(new URLSearchParams(init.body).get('action')==='capture'){serverSaved=true;throw Error('Response lost after atomic save');}return response;};
 await h.ctx.runSmartRead();assert.equal(serverSaved,true);assert.equal(h.info.report,old);assert.equal(h.ctx.smartAction.locked,true);assert.match(h.ctx.smartAction.message,/previously displayed report is retained/);assert.doesNotMatch(h.ctx.smartAction.message,/previous report is unchanged/);await h.ctx.runSmartRead();assert.equal(h.calls.length,2);
});
