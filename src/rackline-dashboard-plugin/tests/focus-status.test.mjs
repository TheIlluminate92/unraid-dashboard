// Source/DOM-contract tests. Actual focus hit-testing remains in responsive-browser.py.
import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
import {createRequire} from 'node:module';
const M=createRequire(import.meta.url)('../source/usr/local/emhttp/plugins/rackline.dashboard/assets/js/model.js');
const base=new URL('../source/usr/local/emhttp/plugins/rackline.dashboard/',import.meta.url);
const app=readFileSync(new URL('assets/js/app.js',base),'utf8');
const view=readFileSync(new URL('include/view.php',base),'utf8');
const css=readFileSync(new URL('assets/css/rackline.css',base),'utf8');
const fn=(a,b)=>app.slice(app.indexOf('function '+a+'('),app.indexOf('function '+b+'('));
function focusHarness(){
 let next=0;const frames=new Map(),moves=[];
 const rect={top:143.875,bottom:187.765625,height:43.890625,width:308,left:358,right:666};
 const bar={top:0,bottom:237.875,left:40,right:984};
 const target={keyboard:true,owned:true,inPulse:false,matches(){return this.keyboard;},getBoundingClientRect:()=>rect};
 const pulse={contains:e=>e.inPulse,getBoundingClientRect:()=>bar};
 const context={focusFrame:null,stopped:false,document:{activeElement:target},root:{contains:e=>e.owned,querySelector:()=>pulse},window:{innerHeight:900,getComputedStyle:()=>({position:'sticky'}),requestAnimationFrame:callback=>{frames.set(++next,callback);return next;},cancelAnimationFrame:id=>frames.delete(id),scrollBy:options=>{moves.push(options);rect.top-=options.top;rect.bottom-=options.top;}}};
 vm.createContext(context);vm.runInContext(fn('revealFocusedControl','onVisibility'),context);
 return {context,target,rect,bar,frames,moves,flush(){for(const [id,callback]of [...frames]){frames.delete(id);callback();}}};
}
test('1024px CI occlusion is revealed after browser focus scrolling without stealing focus',()=>{
 const h=focusHarness();h.context.onFocusIn();assert.equal(h.moves.length,0);assert.equal(h.frames.size,1);h.flush();
 assert.equal(h.moves.length,1);assert.equal(h.moves[0].top,-102);assert.equal(h.moves[0].left,0);assert.equal(h.moves[0].behavior,'instant');assert.equal(h.context.document.activeElement,h.target);
 assert.equal(h.rect.top,h.bar.bottom+8);h.context.onFocusIn();h.flush();assert.equal(h.moves.length,1,'Already-visible focus must not trigger another scroll');
});
test('focus reveal coalesces frames and uses final focus/geometry rather than a stale event',()=>{
 const h=focusHarness();h.context.onFocusIn();h.context.onFocusIn();assert.equal(h.frames.size,1);h.rect.top=120;h.flush();assert.equal(h.moves[0].top,120-237.875-8);
 const changed=focusHarness();changed.context.onFocusIn();changed.context.document.activeElement={owned:false};changed.flush();assert.equal(changed.moves.length,0);
});
test('mobile, pointer focus, native elements, visible targets and oversized targets do not scroll',()=>{
 for(const scenario of ['mobile','pointer','native','pulse','visible','oversized','zero','offscreen','destroyed']){
  const h=focusHarness();
  if(scenario==='mobile')h.context.window.getComputedStyle=()=>({position:'static'});
  if(scenario==='pointer')h.target.keyboard=false;
  if(scenario==='native')h.target.owned=false;
  if(scenario==='pulse')h.target.inPulse=true;
  if(scenario==='visible'){h.rect.top=300;h.rect.bottom=344;}
  if(scenario==='oversized')h.rect.height=900;
  if(scenario==='zero')h.rect.width=0;
  if(scenario==='offscreen'){h.bar.top=1000;h.bar.bottom=1238;}
  if(scenario==='destroyed')h.context.stopped=true;
  h.context.onFocusIn();h.flush();assert.equal(h.moves.length,0,scenario);
 }
});
function statusHarness(){
 let now=100000;const elements=new Map(),classes=new Set();
 const $=id=>{if(!elements.has(id))elements.set(id,{textContent:'',hidden:false,classList:{toggle(){}}});return elements.get(id);};
 const status=Object.fromEntries(['system','storage','docker','io'].map(key=>[key,{received:now,advancedAt:now,sample:now,error:false}]));
 const context={renderDockerActions(){},status,$,Date:{now:()=>now},text:(id,value)=>$(id).textContent=value,root:{classList:{add:c=>classes.add(c),remove:c=>classes.delete(c)}}};
 vm.createContext(context);vm.runInContext(fn('refreshAge','currentContainer'),context);
 return{context,$,classes,setClock:value=>now=value};
}
test('healthy and waiting feeds show no routine timestamps or empty spacer',()=>{
 const h=statusHarness();h.context.refreshAge();assert.equal(h.$('rl-feed-status').hidden,true);
 for(const key of ['system','storage','docker','io']){assert.equal(h.$('rl-'+key+'-age').hidden,true);assert.equal(h.$('rl-'+key+'-age').textContent,'');}
 h.context.status={};h.context.refreshAge();assert.equal(h.$('rl-feed-status').hidden,true);
 assert.match(view,/id="rl-feed-status" class="rl-meta" role="status" hidden/);
});
test('stale/error warnings remain visible at their existing thresholds and clear on recovery',()=>{
 const h=statusHarness();h.setClock(108001);h.context.refreshAge();assert.equal(h.$('rl-feed-status').hidden,false);
 assert.equal(h.$('rl-system-age').textContent,'System: stale');assert.equal(h.$('rl-io-age').textContent,'I/O: stale');assert.equal(h.$('rl-storage-age').hidden,true);assert(h.classes.has('rl-system-stale'));
 h.context.status.storage.error=true;h.context.refreshAge();assert.equal(h.$('rl-storage-age').textContent,'Storage: unavailable');
 for(const entry of Object.values(h.context.status))Object.assign(entry,{received:108001,advancedAt:108001,sample:108001,error:false});
 h.context.refreshAge();assert.equal(h.$('rl-feed-status').hidden,true);assert(!h.classes.has('rl-system-stale'));
});
test('timer cleanup does not change polling cadence or per-container sample ages',()=>{
 for(const [key,delay]of [['system',1000],['storage',5000],['io',2000],['docker',5000],['batch',2000],['fan',5000]])assert(app.includes("makeLoop('"+key+"',"+delay+','));
 assert.match(app,/setInterval\(refreshAge,1000\)/);assert.match(app,/\['Sample age',rowAge\(c\)\]/);assert.match(view,/<th[^>]*>Sample age<\/th>/);
});
test('removed device action has no active native-page fallback',()=>{
 const inspector=fn('renderDriveInspector','renderBays');assert.doesNotMatch(inspector,/Main\/Device|Native device details|Tests and changes are available/);
 assert.match(inspector,/Cache age/);assert.match(inspector,/SMART health/);
});
test('mobile table density retains fonts, local scrolling and 44px controls',()=>{
 const start=css.indexOf('/* A small mobile density adjustment;'),end=css.indexOf('/* Measured fit:',start);assert(start>=0&&end>start);const density=css.slice(start,end);
 assert.match(density,/@media\(max-width:700px\)/);assert.match(density,/padding-top:6px;padding-bottom:6px/);assert.match(density,/\.rl-layout\{gap:8px\}/);assert.doesNotMatch(density,/font-size|overflow:hidden/);
 assert.match(css,/\.rl-filters button,#rackline-root td button,#rackline-root \.rl-button\{min-height:44px\}/);
});

test('Docker copy cleanup preserves data, compact CPU meaning and edit/ownership cautions',()=>{
 assert.doesNotMatch(view,/Running containers update in batches|Waiting for container data/);
 assert.doesNotMatch(app,/recently updated\.|Last batch |Docker details use the existing|Process sampling unavailable|Network and disk rates are reported/);
 assert.match(view,/id="rl-process-section" class="rl-box" hidden/);assert.match(app,/\$\('rl-process-section'\).hidden=!processes.length/);
 assert.match(view,/title="100% = one logical CPU"/);assert.match(app,/\['CPU',pct\(m.cpu\),'100% = one logical CPU'\]/);
 assert.match(app,/Applying changes may restart this container/);assert.match(app,/No verified Unraid template/);
 for(const label of ['Pools','Addresses','Ports','Restarts'])assert(app.includes("['"+label+"',"));
});

function reportHarness(smart){
 let markup='',writes=0;const body={innerHTML:''},inspector={dataset:{},querySelector:()=>body,set innerHTML(value){markup=value;writes++;}};
 const drive={key:'sda',device:'sda',name:'disk1',smart};
 const context={observeSmartSelection(){},ensureSmartState(){},renderSmartActions(){},smartAction:{states:new Map()},M,status:{storage:{clockOffset:9000}},Date:{now:()=>10000},state:{drive:'sda'},$:()=>inspector,allDrives:()=>[drive],ioRates:()=>({devices:{}}),esc:value=>String(value).replace(/&/g,'&amp;').replace(/"/g,'&quot;'),stateBadge:()=>'',keyValues:items=>JSON.stringify(items),bytes:()=>'',rate:()=>'',temp:()=>'',num:value=>typeof value==='number'?value:null,when:String,encodeURIComponent};
 vm.createContext(context);vm.runInContext(fn('renderDriveInspector','renderBays'),context);
 return{context,drive,get markup(){return markup;},get writes(){return writes;}};
}
test('cached SMART link requires the exact passive route, selected name and full identity digest',()=>{
 const valid='/plugins/rackline.dashboard/include/smart-report.php?name=disk1&identity='+'a'.repeat(64);
 for(const url of [undefined,'/Main/Device?name=disk1','https://example.invalid/report',valid.replace('disk1','disk2'),valid+'0',valid+'&run=1',valid.replace('include/','')]){
  const h=reportHarness({available:true,reportUrl:url});h.context.renderDriveInspector();assert.doesNotMatch(h.markup,/href=/);
 }
 const h=reportHarness({available:true,reportUrl:valid,cachedAtMs:123,sampledAtMs:999});h.context.renderDriveInspector();assert.match(h.markup,/View cached SMART report/);assert.match(h.markup,/Cache age","Under 1 min/);assert.match(h.markup,/Cache file time: 123/);assert.doesNotMatch(h.markup,/Main\/Device/);
 const before=h.writes;h.context.renderDriveInspector();assert.equal(h.writes,before);
 delete h.drive.smart.reportUrl;h.context.renderDriveInspector();assert.equal(h.writes,before+1);assert.doesNotMatch(h.markup,/href=/);
});
test('missing cached SMART report preserves unavailable state without a link',()=>{
 const h=reportHarness({available:false,cachePresent:false,cacheStatus:'missing',reportUrl:'/plugins/rackline.dashboard/include/smart-report.php?name=disk1&identity='+'b'.repeat(64)});h.context.renderDriveInspector();assert.match(h.markup,/No cached report/);assert.doesNotMatch(h.markup,/href=/);
});

test('SMART cache age uses only cache mtime, marks over 24 hours old, and rejects unknown/future time',()=>{
 const now=200000000,age=ms=>M.cachedReportAge({available:true,cachedAtMs:now-ms,sampledAtMs:now},now);
 assert.equal(age(60000).label,'1 min ago');assert.equal(age(3600000).label,'1 h ago');assert.equal(age(86400000).old,false);assert.equal(age(86400001).label,'Old · 24 h ago');assert.equal(age(172800000).label,'Old · 2 d ago');
 for(const value of [undefined,null,0,-1,NaN,Infinity,now+1])assert.equal(M.cachedReportAge({available:true,cachedAtMs:value,sampledAtMs:now},now).label,'Age unknown');
 assert.equal(M.cachedReportAge({available:false,cachedAtMs:now},now).label,'Unavailable');
 assert.match(fn('renderDriveInspector','renderBays'),/Date.now\(\)-\(status.storage&&status.storage.clockOffset\|\|0\)/);
 assert.doesNotMatch(fn('renderDriveInspector','renderBays'),/smart.sampledAtMs/);
});

test('sticky strip uses measured viewport fit, retaining normal desktop behavior and all CPU groups',()=>{
 let height=350,top='0px';const toggles=[];
 const pulse={getBoundingClientRect:()=>({height})};const context={root:{querySelector:()=>pulse,classList:{toggle:(name,on)=>toggles.push([name,on])}},window:{innerWidth:1024,innerHeight:900,getComputedStyle:()=>({top})}};
 vm.createContext(context);vm.runInContext(fn('layoutPulse','layoutCores'),context);
 const check=(expected)=>{context.layoutPulse();assert.deepEqual(toggles.at(-1),['rl-pulse-static',expected]);};
 check(false);height=661;check(true);height=350;context.window.innerHeight=480;check(true);context.window.innerHeight=900;check(false);top='320px';check(true);context.window.innerWidth=768;check(false);
 assert.match(css,/#rackline-root\.rl-pulse-static \.rl-pulse\{position:static\}/);
});

test('present unverified SMART cache retains safe age without trusted report link, health or attributes',()=>{
 const url='/plugins/rackline.dashboard/include/smart-report.php?name=disk1&identity='+'a'.repeat(64);
 for(const reason of ['missing_report_identity','ambiguous_report_identity','report_identity_mismatch']){
  const smart={available:false,cachePresent:true,cacheStatus:'identity_unverified',unavailableReason:reason,cachedAtMs:123,reportUrl:url,health:'PASSED',attributes:{unsafeAttribute:77}};
  const h=reportHarness(smart);h.context.renderDriveInspector();assert.match(h.markup,/Cache present; identity unverified/);assert.match(h.markup,/Cache age","Under 1 min/);assert.match(h.markup,/SMART health","Unavailable/);assert.doesNotMatch(h.markup,/href=|PASSED|unsafeAttribute|No cached report/);
  smart.available=true;h.context.renderDriveInspector();assert.doesNotMatch(h.markup,/href=|PASSED|unsafeAttribute/,'Contradictory availability must not bypass unverified status');
 }
 const old=M.cachedReportAge({available:false,cacheStatus:'identity_unverified',cachePresent:true,cachedAtMs:1000},86401001);assert.equal(old.label,'Old · 24 h ago');
 const unknown=M.cachedReportAge({available:false,cacheStatus:'identity_unverified',cachePresent:true,cachedAtMs:1001},1000);assert.equal(unknown.label,'Age unknown');
});
test('SMART missing, unsafe-present and unestablished states remain distinct',()=>{
 assert.equal(M.cachedReportState({available:false,cachePresent:false,cacheStatus:'missing'}).label,'No cached report');
 assert.equal(M.cachedReportState({available:false,cachePresent:true,cacheStatus:'unavailable'}).label,'Cache present; unavailable');
 assert.equal(M.cachedReportState({available:false,cacheStatus:'unavailable'}).label,'Unavailable');
 assert.equal(M.cachedReportState({available:false}).label,'Unavailable');
 assert.equal(M.cachedReportState({available:true,cacheStatus:'verified',cachePresent:true}).verified,true);
 assert.equal(M.cachedReportState({available:true,cacheStatus:'verified',cachePresent:false}).verified,false);
});

test('native Docker fallback and Rackline footer keep mobile touch targets with no extra table margins',()=>{
 assert.match(fn('renderContainerRows','removeNativeMenu'),/<a class="rl-button" href="\/Docker">Native Docker ↗<\/a>/);
 assert.match(css,/#rackline-root td \.rl-button\{margin:0\}/);
 assert.match(css,/@media\(max-width:700px\)\{\s*#rackline-root \.rl-footer a\{display:inline-flex;align-items:center;min-height:44px;min-width:44px\}/);
 const geometry=readFileSync(new URL('responsive-browser.py',import.meta.url),'utf8');
 assert.match(geometry,/td button,td a,\.rl-button,\.rl-footer a/);
 assert.match(geometry,/verify_focus_visible\(page,fallback\)/);assert.match(geometry,/verify_focus_visible\(page,footer\)/);
});

// Next batch: remove successful boilerplate, retain meaningful live status.
test('availability hides healthy boilerplate and restores outage announcements repeatedly',()=>{
 const h=statusHarness();
 for(let cycle=0;cycle<100;cycle++){
  h.context.refreshAge();assert.equal(h.$('rl-availability').hidden,true);assert.equal(h.$('rl-availability').textContent,'');
  h.context.status.storage.error=true;h.context.refreshAge();assert.equal(h.$('rl-availability').hidden,false);assert.match(h.$('rl-availability').textContent,/Some telemetry is unavailable/);
  h.context.status.storage.error=false;
 }
 h.context.refreshAge();assert.equal(h.$('rl-availability').hidden,true);
 assert.doesNotMatch(app,/Local telemetry · Native controls unchanged/);
 assert.match(css,/#rackline-root \[hidden\]\{display:none!important\}/);
});
test('availability preserves initial and partial connection feedback with outage precedence',()=>{
 const h=statusHarness();h.context.status={};h.context.refreshAge();assert.equal(h.$('rl-availability').hidden,false);assert.equal(h.$('rl-availability').textContent,'Connecting to this server…');
 for(const key of ['system','storage','docker']){h.context.status[key]={received:100000,advancedAt:100000,error:false};h.context.refreshAge();assert.equal(h.$('rl-availability').hidden,false);assert.match(h.$('rl-availability').textContent,/Connecting/);}
 h.context.status.storage.error=true;h.context.refreshAge();assert.match(h.$('rl-availability').textContent,/unavailable/);
 h.context.status.storage.error=false;h.context.status.io={received:100000,advancedAt:100000,error:false};h.context.refreshAge();assert.equal(h.$('rl-availability').hidden,true);
 assert.match(view,/id="rl-availability" class="rl-notice" role="status">Connecting to this server…/);
 assert.match(app,/if\(bootstrap.available!==true\)\{text\('rl-availability','Rackline collectors are not installed completely\.[^']*'\);return;\}/);
 assert(app.indexOf('if(bootstrap.available!==true)')<app.indexOf("makeLoop('system',1000"));
});
test('availability retains stale threshold, inactive-batch exclusion, and recovery',()=>{
 const h=statusHarness();h.context.state={tab:'drives'};h.context.status.batch={received:0,error:true};
 h.context.refreshAge();assert.equal(h.$('rl-availability').hidden,true);
 h.setClock(116000);h.context.refreshAge();assert.equal(h.$('rl-availability').hidden,true);
 h.setClock(116001);h.context.refreshAge();assert.equal(h.$('rl-availability').hidden,false);assert.match(h.$('rl-availability').textContent,/unavailable/);
 for(const key of ['system','storage','docker','io'])Object.assign(h.context.status[key],{received:116001,advancedAt:116001,sample:116001});
 h.context.refreshAge();assert.equal(h.$('rl-availability').hidden,true);
});
