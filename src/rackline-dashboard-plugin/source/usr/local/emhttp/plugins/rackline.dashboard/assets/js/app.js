(function(start){
 // The deferred asset waits for native DOM-ready initialization and its task channel.
 if(document.readyState==='loading'||document.readyState==='interactive'){document.addEventListener('DOMContentLoaded',function(){setTimeout(start,0);},{once:true});}else{start();}
}(function () {
'use strict';
var root=document.getElementById('rackline-root'),M=window.RacklineModel;if(!root||!M)return;
if(window.Rackline&&window.Rackline.destroy)window.Rackline.destroy();
var bootstrap={};try{bootstrap=JSON.parse(document.getElementById('rackline-bootstrap').textContent);}catch(e){root.textContent='Rackline configuration unavailable. Return to the original Dashboard.';return;}
var $=function(id){return document.getElementById(id);},q=function(selector){return Array.from(root.querySelectorAll(selector));};
var esc=function(v){return String(v===null||v===undefined?'':v).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});};
var state={tab:'drives',pool:'all',folder:'all',drive:'',container:'',driveQuery:'',query:'',system:null,previousSystem:null,storage:null,docker:null,io:null,previousIo:null,fan:null,selected:null,previousSelected:null,metrics:null,history:[],samples:new Map(),batchCursor:0,gpu:null,gpuBlocked:new Map(),inventoryVersions:new Map(),inventorySequence:0,dockerIndex:null};
var status={},timers=[],controllers=new Set(),requestTimers=new Set(),stopped=false,visibilityEpoch=0,epoch=0,nativeLoadlist=window.loadlist,nativeEditContainer=window.editContainer,rowSignature='',nativeMenus=new Map(),driveRowsKey='';
var endpoints={system:'/plugins/rackline.dashboard/include/metrics.php',storage:'/plugins/rackline.dashboard/include/storage.php',docker:'/plugins/rackline.dashboard/include/workloads.php',io:'/plugins/rackline.dashboard/include/io.php',batch:'/plugins/rackline.dashboard/include/containers.php',fan:'/plugins/md12xx.fancontrol/include/api.php'};
var dockerAction={busy:false,message:'',generation:0};
var smartAction={busy:false,submitted:false,locked:false,message:'',target:'',displayed:'',generation:0,states:new Map(),controllers:new Set()};
var fansAvailable=bootstrap.fanAvailable===true,loops={},coreObserver=null,focusFrame=null,filterFrame=null,filterReserve=null,fanAbsent=bootstrap.fanAvailable===false,fansDetected=bootstrap.fanAvailable===true;
function stableMarkup(element,html){if(element._rlMarkup===html)return;var active=document.activeElement,focus=active&&element.contains(active)?{id:active.id,pool:active.dataset&&active.dataset.pool,folder:active.dataset&&active.dataset.folder,drive:active.dataset&&active.dataset.drive,container:active.dataset&&active.dataset.container}:null;element.innerHTML=html;element._rlMarkup=html;if(focus){var target=focus.id?$(focus.id):Array.from(element.querySelectorAll('button,tr')).find(function(node){return focus.pool&&node.dataset.pool===focus.pool||focus.folder&&node.dataset.folder===focus.folder||focus.drive&&node.dataset.drive===focus.drive||focus.container&&node.dataset.container===focus.container;});if(target)target.focus({preventScroll:true});}}
function text(id,v){var e=$(id);if(e)e.textContent=v;}
function num(v){return M.number(v);}
function bytes(v,binary){v=num(v);if(v===null)return '—';var unit=0,base=binary?1024:1000,units=binary?['B','KiB','MiB','GiB','TiB']:['B','kB','MB','GB','TB','PB'];while(v>=base&&unit<units.length-1){v/=base;unit++;}return v.toFixed(v>=100||unit===0?0:1)+' '+units[unit];}
function rate(v){return num(v)===null?'—':bytes(v,false)+'/s';}
function pairedRate(a,b){return '<span>'+rate(a)+'</span><small>'+rate(b)+'</small>';}
function pct(v){return num(v)===null?'—':Math.max(0,Number(v)).toFixed(1)+'%';}
function when(ms){return num(ms)===null?'Unknown':new Date(Number(ms)).toLocaleString();}
function temp(v){return num(v)===null?'—':Math.round(v)+'°C';}
function stateBadge(value){var safe=['normal','attention','fault','running','stopped','paused','unhealthy'].includes(value)?value:'unknown';return '<span class="rl-badge rl-'+safe+'">'+esc(value||'Unknown')+'</span>';}
function sameOriginPath(value,fallback){if(typeof value!=='string'||!value.startsWith('/')||value.startsWith('//')||/[\x00-\x20\\]/.test(value))return fallback;return value;}
function refreshAge(){
 renderDockerActions();
 var feedProblem=false;
 ['system','storage','docker','io'].forEach(function(key){var s=status[key],limit=key==='system'||key==='io'?7000:16000,label=key==='io'?'I/O':key[0].toUpperCase()+key.slice(1),el=$('rl-'+key+'-age');if(!el)return;
 var stale=s&&(Date.now()-s.received>limit||Date.now()-(s.advancedAt||s.received)>limit||s.sample&&Date.now()-(s.clockOffset||0)-s.sample>limit),problem=!!(s&&(s.error||stale));feedProblem=feedProblem||problem;el.hidden=!problem;el.classList.toggle('rl-stale',problem);var message=problem?label+': '+(s.error?'unavailable':'stale'):'';if(el.textContent!==message)el.textContent=message;
 });
 var feedStatus=$('rl-feed-status');if(feedStatus)feedStatus.hidden=!feedProblem;
 var disconnected=Object.entries(status).some(function(pair){if(pair[0]==='batch'&&state.tab!=='docker')return false;var s=pair[1];return s.error||Date.now()-s.received>16000||Date.now()-(s.advancedAt||s.received)>16000;});
 var connecting=['system','storage','docker','io'].some(function(key){return !status[key];}),availability=$('rl-availability');
 availability.hidden=!disconnected&&!connecting;
 text('rl-availability',disconnected?'Some telemetry is unavailable. Cached readings may remain visible; native pages are still available.':connecting?'Connecting to this server…':'');
 availability.classList.toggle('rl-stale',disconnected);
 // Old values are visibly stale, not a convincing live zero.
 var systemStatus=status.system;
 if(systemStatus&&(systemStatus.error||Date.now()-systemStatus.received>7000||Date.now()-(systemStatus.advancedAt||systemStatus.received)>7000||Date.now()-(systemStatus.clockOffset||0)-systemStatus.sample>7000))root.classList.add('rl-system-stale');else root.classList.remove('rl-system-stale');
 if(typeof fansDetected!=='undefined'&&fansDetected)renderCooling();
 if(typeof state!=='undefined'&&state.tab==='docker'){var selected=currentContainer();if(selected&&!sampleFresh(state.samples.get(selected.id)))historyGap();updateRowMetrics();renderBatchStatus();renderContainerInspector();}
}
function currentContainer(){return M.list(state.docker&&state.docker.containers).find(function(c){return c.name===state.container;});}
function clearSelection(){state.selected=null;state.previousSelected=null;state.metrics=null;state.history=[];delete status.selected;epoch++;}
function availableTabs(){return fanAbsent?['drives','docker']:['drives','docker','cooling'];}
function setTab(tab,write){invalidateSmartAction();dockerAction.generation++;clearFilterViewport();if(!availableTabs().includes(tab)){if(tab==='cooling'&&location.hash==='#cooling'&&typeof history.replaceState==='function')history.replaceState(null,'','#drives');tab='drives';}state.tab=tab;q('[data-tab]').forEach(function(b){var active=b.dataset.tab===tab;b.hidden=b.dataset.tab==='cooling'&&fanAbsent;b.setAttribute('aria-selected',String(active));b.tabIndex=active?0:-1;});$('rl-tab-cooling').hidden=fanAbsent;['drives','docker','cooling'].forEach(function(name){$('rl-'+name).hidden=name!==tab;});if(write&&location.hash!=='#'+tab)history.pushState(null,'','#'+tab);if(tab==='docker')wake('batch');}
function layoutPulse(){
 var pulse=root.querySelector('.rl-pulse');if(!pulse||typeof window.getComputedStyle!=='function'||!(window.innerHeight>0))return;
 var height=pulse.getBoundingClientRect().height,top=parseFloat(window.getComputedStyle(pulse).top)||0;
 // Keep all CPU groups visible. A tall strip flows normally instead of leaving
 // less than 240px for the workspace and its focused controls below it.
 root.classList.toggle('rl-pulse-static',window.innerWidth>1000&&height+Math.max(0,top)>window.innerHeight-240);
}
function layoutCores(){
 if(typeof window.getComputedStyle!=='function')return;
 q('.rl-socket-cores').forEach(function(grid){
  var groups=Array.from(grid.children),style=window.getComputedStyle(grid),threads=Math.max.apply(null,groups.map(function(group){return Number(group.dataset.threads)||1;}));
  grid.style.setProperty('--rl-core-columns',M.coreColumns(groups.length,grid.getBoundingClientRect().width,parseFloat(style.fontSize),parseFloat(style.columnGap),threads));
  groups.forEach(function(group){group.style.setProperty('--rl-threads',M.coreColumns(Number(group.dataset.threads),group.getBoundingClientRect().width-2,parseFloat(style.fontSize),3));});
 });
 layoutPulse();
}
function cpuMarkup(cpu,previous,valid){
 var sockets=M.cpuTopology(cpu,previous,valid);
 return sockets.length?sockets.map(function(socket){var label=socket.packageId===null?'Topology unknown':'Socket '+socket.packageId;
  return '<section class="rl-cpu-socket" aria-label="'+esc(label)+'" data-socket="'+(socket.packageId===null?'unknown':socket.packageId)+'"><small class="rl-socket-label">'+esc(label)+'</small><div class="rl-socket-cores">'+socket.cores.map(function(core){
   return '<div class="rl-physical-core" data-core="'+(core.coreId===null?'unknown':core.coreId)+'" data-threads="'+core.threads.length+'">'+(core.coreId===null?'':'<small class="rl-core-label">Core '+core.coreId+'</small>')+'<div class="rl-core-threads">'+core.threads.map(function(c){var detail='CPU '+c.id+' · '+(core.coreId===null?'topology unknown':label+' · core '+core.coreId)+' · '+pct(c.percent);
    return '<div class="rl-core" title="'+esc(detail)+'" aria-label="'+esc(detail)+'"><span>'+c.id+'</span><i style="height:'+Math.max(0,Math.min(100,c.percent||0))+'%"></i><b>'+(c.percent===null?'—':Math.round(c.percent)+'%')+'</b></div>';
   }).join('')+'</div></div>';
  }).join('')+'</div></section>';
 }).join(''):'<span>CPU topology unavailable</span>';
}
// A compact strip has room for eight two-thread physical groups. Decide from
// topology, never the expanded card's measured width or fluctuating utilization:
// repeated samples and ResizeObserver passes cannot toggle the layout back/forth.
function expandedPulse(cpu){
 var sockets=M.cpuTopology(cpu,null,false);
 if(sockets.length>1)return true;
 var cores=sockets.length?sockets[0].cores:[];
 return cores.length>8||cores.some(function(core){return core.threads.length>2;});
}
function renderSystem(){var d=state.system;if(!d)return;var p=state.previousSystem,span=M.systemInterval(d,p),valid=span!==null;
 text('rl-cpu',pct(valid?M.cpuPercent(d.cpu&&d.cpu.counters&&d.cpu.counters.cpu,p.cpu&&p.cpu.counters&&p.cpu.counters.cpu):null));
 text('rl-topology',(num(d.cpu&&d.cpu.physicalCoreCount)===null?'Physical core count unknown':d.cpu.physicalCoreCount+' physical cores')+' · '+(num(d.cpu&&d.cpu.threadCount)===null?'unknown':d.cpu.threadCount)+' logical CPUs');text('rl-model',d.cpu&&d.cpu.model||'Processor unavailable');text('rl-temperature',temp(d.cooling&&d.cooling.cpuTempC));
 root.classList.toggle('rl-pulse-expanded',expandedPulse(d.cpu));
 $('rl-cores').innerHTML=cpuMarkup(d.cpu,p&&p.cpu&&p.cpu.counters,valid);layoutCores();
 var mem=d.memory||{},total=num(mem.totalBytes),available=num(mem.availableBytes),used=total!==null&&available!==null&&available>=0&&available<=total?total-available:null;
 text('rl-ram',bytes(used,true)+' / '+bytes(total,true));$('rl-ram-bar').style.width=total>0&&used!==null?Math.min(100,used/total*100)+'%':'0%';text('rl-ram-detail',bytes(available,true)+' available · '+bytes(mem.cachedBytes,true)+' cache (overlaps available)');
 var n=d.network||{},network=M.networkRates(d,p),rx=network.rx,tx=network.tx;
 text('rl-rx',rate(rx));text('rl-tx',rate(tx));text('rl-interface',n.interface||'Unavailable');text('rl-network-detail',(n.up===true?'Link up':n.up===false?'Link down':'Link unknown')+' · '+(num(n.speedMbps)!==null?n.speedMbps+' Mb/s link':'Speed unknown'));
 // Secondary cards can reflow independently of the observed CPU grid.
 layoutPulse();
}
function allDrives(){return M.drives(state.storage);}
function ioRates(){return M.diskRates(state.io,state.previousIo,allDrives());}
function renderIo(){var rates=ioRates();text('rl-read',rate(rates.read));text('rl-write',rate(rates.write));text('rl-io-detail',rates.expected?rates.count+' / '+rates.expected+' known devices · kernel counters':'Waiting for device inventory / two samples');layoutPulse();if(state.drive)renderDriveInspector();}
function renderAlerts(){var messages=[];[state.storage,state.docker].forEach(function(d){M.list(d&&d.attention&&d.attention.messages).forEach(function(m){messages.push(String(m));});});messages=[...new Set(messages)];$('rl-alerts').hidden=!messages.length;$('rl-alerts').innerHTML=messages.map(function(m){return '<span>'+esc(m)+'</span>';}).join('');}
function renderDrives(){var rows=allDrives(),groups=[...new Set(rows.map(function(d){return d.group;}))];if(state.pool!=='all'&&!groups.includes(state.pool))state.pool='all';if(state.drive&&!rows.some(function(d){return d.key===state.drive;}))state.drive='';
 stableMarkup($('rl-pools'),[{id:'all',label:'All drives',count:rows.length}].concat(groups.map(function(g){return{id:g,label:g,count:rows.filter(function(d){return d.group===g;}).length};})).map(function(g){return '<button data-pool="'+esc(g.id)+'" aria-pressed="'+(state.pool===g.id)+'">'+esc(g.label)+'<small>'+g.count+'</small></button>';}).join(''));
 var parity=state.storage&&state.storage.parity||{};text('rl-parity',parity.configured===false?'No configured parity on this host.':parity.active?'Parity operation in progress · '+pct(parity.progressPercent):'Parity '+(parity.valid===true?'valid':parity.valid===false?'not valid':'unknown')+'. Review Main for native operations.');
 renderDriveRows();renderDriveInspector();renderBays();renderIo();renderAlerts();
}
function visibleDrives(){return allDrives().filter(function(d){return (state.pool==='all'||d.group===state.pool)&&(d.name+' '+d.device+' '+d.group+' '+d.model).toLowerCase().includes(state.driveQuery.toLowerCase());});}
function driveCells(d,r){return '<td><b>'+esc(d.name)+'</b><small>'+esc(d.device)+' · '+bytes(d.capacityBytes,false)+'</small></td><td>'+esc(d.group)+'</td><td>'+pct(d.usagePercent)+'</td><td>'+temp(d.temperatureC)+'</td><td>'+rate(r.read)+' / '+rate(r.write)+'</td><td>'+stateBadge(d.state)+'<small>'+esc(d.statusLabel)+'</small></td>';}
function renderDriveRows(){var rates=ioRates().devices,rows=visibleDrives(),key=JSON.stringify(rows.map(function(d){return d.key;})),body=$('rl-drive-rows');
 if(key!==driveRowsKey||!rows.length){driveRowsKey=key;stableMarkup(body,rows.length?rows.map(function(d){return '<tr data-drive="'+esc(d.key)+'" tabindex="0" aria-selected="'+(state.drive===d.key)+'">'+driveCells(d,rates[(d.device||'').replace(/^\/dev\//,'')]||{})+'</tr>';}).join(''):'<tr><td colspan="6" class="rl-empty">'+(state.storage?'No matching assigned drives.':'Waiting for cached storage inventory…')+'</td></tr>');return;}
 var nodes=Array.from(body.querySelectorAll('tr[data-drive]'));rows.forEach(function(d,index){var row=nodes[index];if(row){row.setAttribute('aria-selected',String(state.drive===d.key));row.innerHTML=driveCells(d,rates[(d.device||'').replace(/^\/dev\//,'')]||{});}});
}
function keyValues(items){return '<dl>'+items.map(function(pair){return '<div><dt'+(pair[2]?' title="'+esc(pair[2])+'" aria-label="'+esc(pair[0]+', '+pair[2])+'"':'')+'>'+esc(pair[0])+'</dt><dd>'+esc(pair[1])+'</dd></div>';}).join('')+'</dl>';}
function invalidateSmartAction(){
 smartAction.generation++;
 // A user navigation may allow a later passive state read, never a queued capture.
 smartAction.states.forEach(function(info,key){if(info.pending)smartAction.states.delete(key);});
}
function observeSmartSelection(drive){var target=M.smartTarget(drive),key=target?target.key:'';if(smartAction.displayed!==key){invalidateSmartAction();smartAction.displayed=key;}}
function smartInventoryFresh(){var s=status.storage;return !!s&&!s.error&&Date.now()-s.received<=16000&&Date.now()-(s.advancedAt||s.received)<=16000&&Date.now()-(s.clockOffset||0)-s.sample>=-1000&&Date.now()-(s.clockOffset||0)-s.sample<=16000;}
function currentSmartTarget(){
 if(stopped||document.hidden||state.tab!=='drives'||!smartInventoryFresh())return null;
 var rows=allDrives(),drive=rows.find(function(d){return d.key===state.drive;}),target=M.smartTarget(drive);
 if(!target||rows.filter(function(d){return d.name===target.name||d.device===target.device;}).length!==1||!visibleDrives().some(function(d){return d.key===drive.key;}))return null;
 return target;
}
function smartSelectionMatches(target,generation,visibility){var current=currentSmartTarget();return !!current&&current.key===target.key&&smartAction.generation===generation&&visibilityEpoch===visibility;}
function ensureSmartState(drive){
 var target=M.smartTarget(drive),current=currentSmartTarget();if(!target||!current||target.key!==current.key||smartAction.states.has(target.key))return;
 var info={pending:true,supported:false,report:null,message:''},generation=smartAction.generation,visibility=visibilityEpoch;if(smartAction.states.size>=64){var oldest=Array.from(smartAction.states.keys()).find(function(key){return key!==smartAction.target;});if(oldest)smartAction.states.delete(oldest);}
 smartAction.states.set(target.key,info);
 var url='/plugins/rackline.dashboard/include/smart-capture-state.php?name='+encodeURIComponent(target.name)+'&device='+encodeURIComponent(target.device)+'&identity='+target.identity;
 request(url).then(function(data){
  if(stopped||smartAction.states.get(target.key)!==info)return;
  if(!smartSelectionMatches(target,generation,visibility)){smartAction.states.delete(target.key);return;}
  if(data.status!==200||typeof data.supported!=='boolean'||typeof data.available!=='boolean'||!data.supported&&data.available)throw new Error('Invalid SMART state');
  info.pending=false;info.supported=data.supported;info.clockOffset=data._racklineClockOffset||0;
  if(data.available){info.report=M.capturedReport(data,target);if(!info.report)throw new Error('Invalid saved report');}
  info.message=data.supported?'':(['unsupported_device','unsupported_transport','unsupported_controller_options'].includes(data.reason)?'On-demand reads are not supported for this device/controller.':'SMART read capability unavailable.');
  renderDriveInspector();
 }).catch(function(){if(stopped||smartAction.states.get(target.key)!==info)return;info.pending=false;info.supported=false;info.message='SMART read capability unavailable.';if(smartSelectionMatches(target,generation,visibility))renderDriveInspector();});
}
function renderSmartActions(drive){
 var element=$('rl-smart-actions');if(!element)return;
 var target=M.smartTarget(drive),info=target&&smartAction.states.get(target.key),report=info&&info.report,token=typeof window.csrf_token==='string'&&window.csrf_token.length>=16;
 var current=currentSmartTarget(),eligible=!!(target&&current&&target.key===current.key&&info&&!info.pending&&info.supported&&token),disabled=smartAction.busy||smartAction.locked||!eligible;
 var message=smartAction.target=== (target&&target.key)&&smartAction.message?smartAction.message:smartAction.locked?'A SMART read outcome is unknown. Verify it before reloading or trying another read.':smartAction.busy?'A SMART read is already pending.':!target?'SMART read selection unavailable.':!current?'Refresh drive inventory before reading SMART.':!info||info.pending?'Checking SMART read capability…':info.message||(!token?'SMART read authentication unavailable.':'');
 var link=report?'<a class="rl-button" href="'+esc(report.reportUrl)+'">View saved SMART report ↗</a>':'';
 var button=info&&info.supported&&token?'<button id="rl-smart-read" class="rl-button" data-smart-action="read" aria-disabled="'+disabled+'">'+(report?'Read SMART again':'Read SMART report')+'</button>':'';
 stableMarkup(element,link+button+'<p class="rl-note" role="status"'+(message?'':' hidden')+'>'+esc(message)+'</p>');
}
async function smartPost(fields,csrf){
 var controller=new AbortController(),owned=fields.action==='capture'?smartAction.controllers:controllers;owned.add(controller);var timeout=setTimeout(function(){controller.abort();},45000);requestTimers.add(timeout);
 try{
  // Native middleware consumes this one form token. Do not use the jQuery
  // prefilter, which appends a second token. Never send to a metadata URL.
  var body=new URLSearchParams(Object.assign({},fields,{csrf_token:csrf}));
  var response=await fetch('/plugins/rackline.dashboard/include/smart-capture.php',{method:'POST',credentials:'same-origin',cache:'no-store',redirect:'error',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body.toString(),signal:controller.signal});
  if(!response.ok)throw new Error('SMART request failed');
  var data=await response.json(),date=response.headers&&response.headers.get('Date'),serverTime=date?Date.parse(date):NaN;
  if(!data||data.status!==200)throw new Error('Invalid SMART response');
  Object.defineProperty(data,'_racklineClockOffset',{value:Number.isFinite(serverTime)?Date.now()-serverTime:status.storage&&status.storage.clockOffset||0});return data;
 }finally{clearTimeout(timeout);requestTimers.delete(timeout);owned.delete(controller);}
}
async function runSmartRead(){
 var target=currentSmartTarget(),info=target&&smartAction.states.get(target.key),csrf=window.csrf_token;
 if(!target||!info||info.pending||!info.supported||smartAction.busy||smartAction.locked||typeof csrf!=='string'||csrf.length<16||typeof window.confirm!=='function')return;
 var generation=smartAction.generation,visibility=visibilityEpoch,fields={name:target.name,device:target.device,identity:target.identity};
 smartAction.busy=true;smartAction.submitted=false;smartAction.target=target.key;smartAction.message='Preparing SMART read…';renderDriveInspector();
 try{
  var prepared=await smartPost(Object.assign({action:'prepare'},fields),csrf);
  if(!smartSelectionMatches(target,generation,visibility)||window.csrf_token!==csrf)throw new Error('Selection changed');
  if(prepared.requiresWakeConfirmation!==true||typeof prepared.binding!=='string'||!/^[a-f0-9]{64}$/.test(prepared.binding)||typeof prepared.requestId!=='string'||!/^[1-9][0-9]{0,11}-[a-f0-9]{32}$/.test(prepared.requestId))throw new Error('Invalid preparation');
  var confirmed=window.confirm('Read a SMART report for '+target.name+' ('+target.device+')? This may wake the disk. Do not remove, replace or reassign this drive during the read. This reads information, not a self-test.');
  if(!confirmed){smartAction.message='SMART read cancelled. No disk read was submitted.';return;}
  if(!smartSelectionMatches(target,generation,visibility)||window.csrf_token!==csrf)throw new Error('Selection changed');
  smartAction.submitted=true;smartAction.message='Reading '+target.name+' ('+target.device+')…';renderDriveInspector();
  var data=await smartPost(Object.assign({action:'capture',binding:prepared.binding,requestId:prepared.requestId,wake:'confirmed'},fields),csrf),report=M.capturedReport(data,target);
  if(!report||report.binding!==prepared.binding)throw new Error('Invalid capture response');
  if(stopped)return;
  // A response can complete after navigation. Never attach it to a new drive
  // or open a report automatically; the passive state endpoint can recover it.
  if(smartSelectionMatches(target,generation,visibility)){info.report=report;info.clockOffset=data._racklineClockOffset;smartAction.message=report.hasWarnings?'Report saved with SMART warnings. Open it for details.':'SMART report saved.';}
  else{smartAction.states.delete(target.key);smartAction.message='Read completed for '+target.name+' ('+target.device+'). Select that drive to view its saved report.';}
 }catch(error){
  if(smartAction.submitted){smartAction.locked=true;smartAction.message='SMART read outcome is unknown or failed. The previously displayed report is retained. Verify the result before reloading or trying another read.';}
  else smartAction.message='SMART read was not submitted. Selection or capability changed; review the drive before trying again.';
 }finally{smartAction.busy=false;if(!stopped)renderDriveInspector();}
}
function renderDriveInspector(){var d=allDrives().find(function(row){return row.key===state.drive;});if(!d){observeSmartSelection(null);$('rl-drive-inspector').dataset.identity='';$('rl-drive-inspector').innerHTML='<h3>Select a drive</h3><p>View cached health, capacity and bay location.</p>';return;}
 observeSmartSelection(d);ensureSmartState(d);
 var target=M.smartTarget(d),captureInfo=target&&smartAction.states.get(target.key),capture=captureInfo&&captureInfo.report,captureAge=capture&&M.capturedReportAge(capture,Date.now()-(captureInfo.clockOffset||0));
 var r=ioRates().devices[(d.device||'').replace(/^\/dev\//,'')]||{},smart=d.smart||{},report=M.cachedReportState(smart),cache=M.cachedReportAge(smart,Date.now()-(status.storage&&status.storage.clockOffset||0));
 var reportPrefix='/plugins/rackline.dashboard/include/smart-report.php?name='+encodeURIComponent(d.name)+'&identity=',reportUrl=report.verified&&typeof smart.reportUrl==='string'&&smart.reportUrl.startsWith(reportPrefix)&&/^[a-f0-9]{64}$/.test(smart.reportUrl.slice(reportPrefix.length))?smart.reportUrl:'';
 var content='<h3>'+esc(d.name)+'</h3><p>'+esc(d.model||'Model unavailable')+'</p>'+stateBadge(d.state)+keyValues([['Device',d.device||'Unknown'],['Location',d.location&&d.location.label||'Not mapped'],['Pool / role',d.group],['Temperature',temp(d.temperatureC)],['State',d.statusLabel||'Unknown'],['Filesystem',d.fileSystem||'Not available'],['Used / total',bytes(d.usedBytes,false)+' / '+bytes(d.sizeBytes,false)],['Read / write',rate(r.read)+' / '+rate(r.write)],['Errors',num(d.errors)===null?'Unknown':d.errors],['Native SMART cache',report.label]].concat(report.present?[['Cache age',cache.label,'Cache file time: '+when(cache.atMs)+'. Not a SMART/self-test time']]:[],[['SMART health',report.verified?smart.health||'Not in cached report':'Unavailable']],capture?[['Saved read age',captureAge.label,'Read completed: '+when(captureAge.atMs)+'. Not a self-test time'],['Saved read warnings',capture.hasWarnings?'Present; see report':'No warning flags; see report']]:[]))+(report.verified?keyValues(Object.entries(smart.attributes||{})):'');

 var actions=reportUrl?'<a class="rl-button" href="'+esc(reportUrl)+'">View cached SMART report ↗</a>':'';
 var inspector=$('rl-drive-inspector'),identity=JSON.stringify([d.key,d.name,d.selectionToken,reportUrl]);if(inspector.dataset.identity!==identity){inspector.innerHTML='<div class="rl-drive-inspector-data">'+content+'</div>'+actions+'<div id="rl-smart-actions" class="rl-smart-actions"></div>';inspector.dataset.identity=identity;}else{var body=inspector.querySelector('.rl-drive-inspector-data');if(body)body.innerHTML=content;}renderSmartActions(d);
}
function renderBays(){
 var locations=state.storage&&state.storage.locations||{},groups=M.list(locations.groups);
 var source=locations.source==='disklocation_cache'?'<p class="rl-note rl-location-source" title="'+esc('Disk Location cache file writes, not discovery or SMART freshness: '+Object.entries(locations.cacheWrittenAtMs||{}).map(function(pair){return pair[0]+': '+when(pair[1]);}).join('; '))+'">Disk Location layout · cached metadata</p>':'';
 stableMarkup($('rl-bays'),source+(groups.length?groups.map(function(group){
  var columns=M.number(group&&group.columns),cells=M.list(group&&group.cells),rows=M.number(group&&group.rows);
  if(!group||!Number.isInteger(columns)||columns<1||columns>100||cells.length>4096||cells.some(function(cell){return !cell||typeof cell!=='object'||Array.isArray(cell);})||group.rows!==undefined&&(!Number.isInteger(rows)||rows<1||rows>100||rows*columns!==cells.length))return '<section class="rl-enclosure"><h4>'+esc(group&&group.name||'Enclosure')+'</h4><p class="rl-note">Cached tray layout unavailable.</p></section>';
  var width=M.number(group.trayWidth),height=M.number(group.trayHeight),sized=Number.isInteger(width)&&width>0&&width<=4096&&Number.isInteger(height)&&height>0&&height<=4096;
  var align=['left','center','right','start','end'].includes(group.trayAlign)?group.trayAlign:'left',nameAlign=['left','center','right','hide','vertical'].includes(group.nameAlign)?group.nameAlign:'left',position=['left','right','none'].includes(group.position)?group.position:'';
  return '<section class="rl-enclosure'+(sized?' rl-native-layout':'')+'" aria-label="'+esc(group.name||'Enclosure')+'" data-position="'+position+'" data-name-align="'+nameAlign+'"><h4>'+esc(group.name||'Enclosure')+'</h4><div class="rl-bay-grid" data-tray-direction="'+(group.trayDirection==='v'?'v':'h')+'" data-tray-align="'+align+'" style="--rl-columns:'+columns+(sized?';--rl-tray-width:'+Math.max(44,width)+'px;--rl-tray-height:'+Math.max(44,height)+'px':'')+'">'+M.list(group.cells).map(function(cell){
   var d=cell.disk,key=d&&(d.device||d.name),label=cell.tray===undefined||cell.tray===null?'—':cell.tray,display=cell.display&&cell.display.source==='disklocation'?cell.display:null;
   if(display&&display.identityVerified!==true)key=null;
   // Native display aliases are presentation only. Only the separately verified
   // assigned disk supplies the selection target and its pool/SMART identity.
   // Disk Location's saved comment is the owner's presentation label, not a
   // serial or a current-device proof. Cached labels remain useful even when
   // live identity is unknown; keep that status visible and actions disabled.
   var savedLabel=display&&typeof display.comment==='string'?display.comment.trim():'',cachedModel=display&&typeof display.model==='string'?display.model.trim():'';
   var unverified=!!(display&&cell.present&&display.identityVerified!==true);
   var role=display&&cell.present?savedLabel||(display.identityVerified===true?[display.type,display.name].filter(Boolean).join(' '):cachedModel)||'Cached device':d?d.name:cell.present?'Unmapped':'Empty';
   var identityNote=unverified?'Cached · identity unverified':'';
   var nativeColor=display&&typeof display.color==='string'&&/^#[0-9a-f]{6}$/i.test(display.color)?display.color:'',color=nativeColor||!display&&d&&/^#[0-9a-f]{6}$/i.test(d.identityColor||'')&&d.identityColor||'#647d8e';
   var detail=display?[display.device,display.model,display.comment].filter(Boolean).join(' · '):'',current=d&&display?' · Selected drive: '+d.name+' ('+d.device+')':'';
   return '<button '+(key&&!cell.hidden?'data-drive="'+esc(key)+'"':'disabled')+' title="'+esc('Bay '+label+' · '+role+(savedLabel?' · Disk Location comment':'')+(identityNote?' · '+identityNote:'')+(detail?' · '+detail:'')+current)+'" aria-label="'+esc(group.name+', bay '+label+', '+role+(identityNote?', '+identityNote:''))+'" style="--rl-identity:'+color+(nativeColor?';--rl-bay-color:'+nativeColor+';--rl-bay-text:'+M.bayTextColor(nativeColor):'')+'" class="'+(cell.hidden?'rl-hidden ':'')+(unverified?'rl-unverified ':'')+(nativeColor?'rl-native-color ':'')+(key===state.drive?'rl-selected':'')+'"><b>'+esc(label)+'</b><small>'+esc(role)+'</small>'+(identityNote?'<small class="rl-bay-status">Unverified</small>':'')+'</button>';
  }).join('')+'</div></section>';
 }).join(''):'<p class="rl-note">No cached bay map available from Disk Location.</p>'));
}
function renderDocker(){var data=state.docker||{},containers=M.list(data.containers),folders=M.folders(data),index=M.inventoryIndex(containers),versions=new Map();state.dockerIndex=index;containers.forEach(function(c){var old=state.inventoryVersions.get(c.id);versions.set(c.id,old&&old.name===c.name&&old.state===c.state?old:{name:c.name,state:c.state,token:++state.inventorySequence});});state.inventoryVersions=versions;state.gpuBlocked.forEach(function(time,name){if(!index.names.has(name)||Date.now()-time>=10000)state.gpuBlocked.delete(name);});state.samples.forEach(function(entry,id){var matches=index.ids.get(id.toLowerCase())||[],current=matches.length===1&&matches[0].name===entry.sample.name?matches[0]:null;if(!current||current.state!=='running'){state.samples.delete(id);state.gpuBlocked.set(entry.sample.name,Date.now());}});if(!folders.some(function(f){return f.id===state.folder;}))state.folder='all';var selected=currentContainer();if(state.container&&!selected){state.container='';clearSelection();}else if(selected&&(selected.state!=='running'||state.selected&&!M.sameIdentity(selected,state.selected,containers,index))){clearSelection();}
 stableMarkup($('rl-folders'),folders.map(function(f){return '<button data-folder="'+esc(f.id)+'" aria-pressed="'+(state.folder===f.id)+'">'+esc(f.name||f.id)+'<small>'+f.containers.length+'</small></button>';}).join(''));
 text('rl-folder-source','Folders: '+(data.folderSource||'All containers / label grouping'));var native=data.nativeMetadata||{};var nativeWarning=native.foldersPartial?'Folder metadata was partial. Review the native Docker page for complete grouping.':native.templatesPartial?'Native template lookup was partial. Missing controls stay on the native Docker page.':native.autostartAvailable===false?'Native autostart status unavailable. Context menus stay on the native Docker page; verified template editing remains available.':native.cacheAvailable===false?'Native icon/update cache unavailable. Verified templates still support configuration editing.':'';text('rl-native-metadata',nativeWarning);$('rl-native-metadata').hidden=!nativeWarning;
 var summary=data.summary||{};text('rl-docker-summary',data.dockerAvailable===false?'Docker is not available.':(num(summary.running)===null?'—':summary.running)+' / '+(num(summary.total)===null?'—':summary.total)+' running · '+(num(summary.updates)===null?'—':summary.updates)+' cached update notices');
 renderContainerRows();renderContainerInspector();renderDockerActions();renderAlerts();var processes=M.list(data.topProcesses);$('rl-process-section').hidden=!processes.length;$('rl-processes').innerHTML=processes.length?processes.map(function(p){return '<div class="rl-process"><span>'+esc(p.name||p.command||'Process')+'<small>'+esc(p.container||'Host')+'</small></span><span>'+pct(p.cpuPercent)+' · '+bytes(p.memoryBytes,true)+'</span></div>';}).join(''):'';
}
// Native contracts reviewed against unraid/webgui b135287c706a30da44f71cc7820615209e83e9a0.
// DockerUpdate.php returns an empty body, not per-registry success. No automatic checks/retries.
function dockerInventoryFresh(){var s=status.docker;return !!(state.docker&&state.docker.dockerAvailable===true&&s&&!s.error&&Date.now()-s.received<16000&&Date.now()-(s.advancedAt||s.received)<16000&&Date.now()-(s.clockOffset||0)-s.sample<16000);}
function dockerShellReady(){return !!(window.jQuery&&typeof window.jQuery.ajax==='function'&&typeof window.csrf_token==='string'&&window.csrf_token&&typeof window.checkAll==='function');}
function dockerUpdateTarget(){var c=currentContainer(),all=M.list(state.docker&&state.docker.containers);return dockerInventoryFresh()&&c&&M.nativeMenuReady(c)&&M.number(c.updateStatus)===1&&all.filter(function(x){return x.name===c.name||String(x.id).toLowerCase()===String(c.id).toLowerCase();}).length===1&&visibleContainers().some(function(x){return x===c;})?c:null;}
function dockerTargetIdentity(c){return JSON.stringify([c.id,c.name,c.imageId,c.image,c.state,c.menu.template]);}
function renderDockerActions(){var check=$('rl-docker-check'),update=$('rl-docker-update');if(!check||!update)return;var ready=dockerShellReady(),target=dockerUpdateTarget();check.disabled=dockerAction.busy||!ready||!dockerInventoryFresh();update.disabled=dockerAction.busy||!ready||!target||typeof window.openDocker!=='function';text('rl-docker-update-target',target?'Selected update: '+target.name:currentContainer()?'Selected container has no eligible update.':'Select a container with an update notice.');text('rl-docker-action-status',dockerAction.message||(!ready?'Native update controls unavailable here. Use Native Docker.':''));$('rl-docker-action-status').hidden=!dockerAction.message&&ready;}
async function runDockerAction(action){
 if(stopped||document.hidden||state.tab!=='docker'||dockerAction.busy)return;
 if(!dockerShellReady()||!dockerInventoryFresh()){dockerAction.message='Fresh inventory and native Docker controls are required. Use Native Docker.';renderDockerActions();return;}
 var target=action==='update'?dockerUpdateTarget():null,identity=target&&dockerTargetIdentity(target);
 if(action==='update'&&(!target||typeof window.openDocker!=='function'))return;
 if(action!=='check'&&action!=='update')return;
 var actionGeneration=dockerAction.generation,actionVisibility=visibilityEpoch;
 dockerAction.busy=true;renderDockerActions();
 var prompt=action==='check'?'Check all containers for updates? Unraid will contact registries and template sources and refresh cached metadata. This does not update or restart containers.':'Update only '+target.name+'? Unraid may download an image, recreate this container and restart its service. This can interrupt running work.';
 if(!window.confirm(prompt)){dockerAction.busy=false;renderDockerActions();return;}
 // Confirmation never follows a new selection or a replaced container/template.
 if(stopped)return;
 if(document.hidden||state.tab!=='docker'||dockerAction.generation!==actionGeneration||visibilityEpoch!==actionVisibility){dockerAction.busy=false;dockerAction.message='Navigation changed. Nothing was submitted; review the selection before trying again.';renderDockerActions();return;}
 var current=action==='update'?dockerUpdateTarget():null;
 if(!dockerShellReady()||!dockerInventoryFresh()||action==='update'&&(!current||dockerTargetIdentity(current)!==identity)){
  dockerAction.busy=false;dockerAction.message='Inventory or selection changed. Review it before trying again.';renderDockerActions();return;
 }
 if(action==='update'){
  // Re-read after the blocking prompt: polling cannot run while confirm is open.
  // Native updates are name-based; this reduces, but cannot atomically eliminate,
  // replacement by another client between this read and the native task.
  try{
   var fresh=await request(endpoints.docker),age=Date.now()-(fresh._racklineClockOffset||0)-fresh.sampledAtMs;
   var matches=M.list(fresh.containers).filter(function(c){return c.name===target.name||String(c.id).toLowerCase()===String(target.id).toLowerCase();});
   current=dockerUpdateTarget();
   if(stopped)return;
 if(document.hidden||state.tab!=='docker'||dockerAction.generation!==actionGeneration||visibilityEpoch!==actionVisibility){dockerAction.busy=false;dockerAction.message='Navigation changed. Nothing was submitted; review the selection before trying again.';renderDockerActions();return;}
   if(!M.validPayload('docker',fresh)||fresh.dockerAvailable!==true||!Number.isFinite(age)||age< -1000||age>16000||matches.length!==1||!M.nativeMenuReady(matches[0])||M.number(matches[0].updateStatus)!==1||dockerTargetIdentity(matches[0])!==identity||!current||dockerTargetIdentity(current)!==identity||!dockerShellReady()||typeof window.openDocker!=='function')throw new Error('Changed inventory');
  }catch(e){if(stopped)return;dockerAction.busy=false;dockerAction.message='Could not verify the selected container after confirmation. Nothing was submitted; refresh inventory and review Native Docker.';renderDockerActions();return;}
  dockerAction.message='Update handed to native Docker for '+target.name+'. Review its progress/result there. Reload Rackline only after verifying the native result to enable another action.';renderDockerActions();
  try{window.openDocker('update_container '+encodeURIComponent(target.name),'Update container: '+target.name,'','loadlist');}catch(e){dockerAction.message='Native update outcome is unknown. Check Native Docker before retrying.';renderDockerActions();}
  return;
 }
 dockerAction.message='Checking all containers through native Docker…';renderDockerActions();
 try{
  // The native shell supplies the same-origin CSRF prefilter. A header also keeps
  // the token explicit on supported hosts; never send it to a configurable URL.
  window.jQuery.ajax({url:'/plugins/dynamix.docker.manager/include/DockerUpdate.php',type:'POST',data:{},dataType:'text',timeout:120000,headers:{'X-CSRF-Token':window.csrf_token}}).done(function(body){
   if(stopped)return;
   if(typeof body!=='string'||body.trim()!==''){dockerAction.message='Unexpected check response. Outcome is unknown; review Native Docker before retrying.';renderDockerActions();return;}
   dockerAction.busy=false;dockerAction.message='Native check returned. Refreshing cached notices; individual registry checks may still be unavailable.';wake('docker');renderDockerActions();
  }).fail(function(){if(stopped)return;dockerAction.message='Check failed or timed out; outcome is unknown. Review Native Docker before retrying.';renderDockerActions();});
 }catch(e){dockerAction.message='Check outcome is unknown. Review Native Docker before retrying.';renderDockerActions();}
}
function visibleContainers(){var folder=M.folders(state.docker).find(function(f){return f.id===state.folder;}),names=new Set(folder?folder.containers:[]);return M.list(state.docker&&state.docker.containers).filter(function(c){return names.has(c.name)&&(c.name+' '+c.state+' '+c.health).toLowerCase().includes(state.query.toLowerCase());});}
function renderContainerRows(){var rows=visibleContainers(),signature=JSON.stringify([rows.map(function(c){return [c.name,c.id,c.state,c.health,c.image,c.imageId,c.icon,c.autostart,c.updateStatus,c.menu];}),state.container]);if(signature===rowSignature){updateRowMetrics();return;}rowSignature=signature;stableMarkup($('rl-container-rows'),rows.length?rows.map(function(c){var selected=c.name===state.container,m=metricsFor(c),template=c.menu&&c.menu.template,id=/^[a-f0-9]{12}(?:[a-f0-9]{52})?$/i.test(c.id||'')?c.id:'';return '<tr data-container="'+esc(c.name)+'" tabindex="0" aria-selected="'+selected+'"><td><div class="rl-app"><img src="'+esc(sameOriginPath(c.icon,'/plugins/dynamix.docker.manager/images/question.png'))+'" alt=""><span><b>'+esc(c.name)+'</b><small>'+esc(c.image||'')+'</small></span></div></td><td>'+stateBadge(c.state)+'<small>'+esc(c.health&&c.health!=='none'?c.health:c.status||'')+'</small></td><td data-metric="cpu">'+pct(m.cpu)+'</td><td data-metric="memory">'+bytes(m.memory,true)+'</td><td data-metric="network">'+pairedRate(m.rx,m.tx)+'</td><td data-metric="block">'+pairedRate(m.read,m.write)+'</td><td data-metric="gpu">'+esc(gpuActivity(c))+'</td><td data-metric="age">'+esc(rowAge(c))+'</td><td>'+(M.nativeMenuReady(c)&&id?'<button id="'+esc(id)+'" data-native-menu="'+esc(c.name)+'" title="Native Unraid menu for '+esc(c.name)+'">Controls ▾</button>':'<a class="rl-button" href="/Docker">Native Docker ↗</a>')+'</td></tr>';}).join(''):'<tr><td colspan="9" class="rl-empty">'+(state.docker?'No containers in this folder / search.':'Waiting for Docker inventory…')+'</td></tr>');
 syncNativeMenus(rows);
}
function removeNativeMenu(id){var owned=nativeMenus.get(id);if(!owned)return;var jq=window.jQuery;if(jq){owned.actions.forEach(function(action){jq(document).off('click','#'+action);});jq(document).off('click','#'+id).off('contextmenu','#'+id);}if(owned.node&&owned.node.parentNode)owned.node.parentNode.removeChild(owned.node);nativeMenus.delete(id);}
function syncNativeMenus(rows){
 var ids=new Set(rows.filter(function(c){return M.nativeMenuReady(c)&&$(c.id);}).map(function(c){return c.id;}));Array.from(nativeMenus.keys()).forEach(function(id){if(!ids.has(id))removeNativeMenu(id);});
 rows.forEach(function(c){var button=$(c.id);if(!button)return;if(!M.nativeMenuReady(c)){button.dataset.nativeFallback='true';return;}
  var args=M.nativeArgs(c),signature=JSON.stringify(args),owned=nativeMenus.get(c.id);
  if(owned&&owned.signature===signature){delete button.dataset.nativeFallback;return;}
  if(owned)removeNativeMenu(c.id);
  // Never take ownership of a menu already created by another native module.
  if(typeof window.addDockerContainerContext!=='function'||!window.jQuery||$('dropdown-'+c.id)){button.dataset.nativeFallback='true';return;}
  try{window.addDockerContainerContext.apply(window,args);var menu=$('dropdown-'+c.id);if(!menu)throw new Error('Unsupported native menu structure');var actions=Array.from(menu.querySelectorAll('a[id]')).map(function(a){return a.id;});nativeMenus.set(c.id,{signature:signature,node:menu,actions:actions});delete button.dataset.nativeFallback;}
  catch(e){var partial=$('dropdown-'+c.id);if(partial){nativeMenus.set(c.id,{node:partial,actions:Array.from(partial.querySelectorAll('a[id]')).map(function(a){return a.id;})});removeNativeMenu(c.id);}else window.jQuery(document).off('click','#'+c.id).off('contextmenu','#'+c.id);button.dataset.nativeFallback='true';}
 });
}
function sampleFresh(entry){return !!entry&&!entry.error&&Date.now()-entry.received<=20000&&Date.now()-entry.advancedAt<=20000&&Date.now()-(entry.clockOffset||0)-entry.sample.stats.sampledAtMs<=20000&&Date.now()-(entry.clockOffset||0)-entry.sample.stats.sampledAtMs>=-1000;}
function metricsFor(c){var entry=c&&state.samples.get(c.id);return c&&c.state==='running'&&entry&&M.sameIdentity(c,entry.sample,M.list(state.docker&&state.docker.containers),state.dockerIndex)&&sampleFresh(entry)?entry.metrics:{};}
function rowAge(c){if(c.state!=='running')return 'Not running';var entry=state.samples.get(c.id);if(!entry)return 'Not sampled';if(entry.error||!entry.sample.stats.available)return 'Unavailable';var seconds=Math.max(0,Math.round(Math.max(Date.now()-entry.received,Date.now()-entry.advancedAt,Date.now()-(entry.clockOffset||0)-entry.sample.stats.sampledAtMs)/1000));return (sampleFresh(entry)?'':'Stale · ')+seconds+' s ago';}
function gpuFresh(gpu){if(!gpu||gpu.available!==true||M.number(gpu.sampledAtMs)===null||gpu.sampledAtMs<=0||!Array.isArray(gpu.attributedNames)||!Array.isArray(gpu.videoAttributedNames))return false;var age=Date.now()-(gpu._clockOffset||0)-gpu.sampledAtMs;return age>=-1000&&age<=5000;}
function gpuActivity(c){var gpu=state.gpu,blocked=state.gpuBlocked.get(c.name)||0;if(gpu&&gpu.available===false&&gpu.status==='unsupported')return 'Unavailable';if(c.state!=='running'||!gpuFresh(gpu)||Date.now()-blocked<10000)return 'Unknown';if(M.list(gpu.videoAttributedNames).includes(c.name))return 'Recent video*';if(M.list(gpu.attributedNames).includes(c.name))return 'Recent GPU*';return 'No recent match*';}
function renderBatchStatus(){
 var running=M.list(state.docker&&state.docker.containers).filter(function(c){return c.state==='running';}),fresh=running.filter(function(c){return sampleFresh(state.samples.get(c.id));}).length,s=status.batch;
 var message=s&&s.error?'Container telemetry unavailable.':s&&fresh<running.length?'Some container samples are unavailable or stale.':'';
 text('rl-batch-status',message);$('rl-batch-status').hidden=!message;
 var gpu=state.gpu,gpuMessage=gpuFresh(gpu)?'GPU '+pct(gpu.loadPercent)+' · video '+pct(gpu.videoLoadPercent)+' (whole device)':'';
 text('rl-gpu-summary',gpuMessage);$('rl-gpu-summary').hidden=!gpuMessage;
}
function updateRowMetrics(){var index=state.dockerIndex||M.inventoryIndex(M.list(state.docker&&state.docker.containers));q('tr[data-container]').forEach(function(row){var matches=index.names.get(row.dataset.container)||[],c=matches.length===1?matches[0]:null;if(!c)return;var m=metricsFor(c),values={cpu:pct(m.cpu),memory:bytes(m.memory,true),network:rate(m.rx)+' / '+rate(m.tx),block:rate(m.read)+' / '+rate(m.write),gpu:gpuActivity(c),age:rowAge(c)};Object.keys(values).forEach(function(key){var cell=row.querySelector('[data-metric="'+key+'"]');if(cell){if(key==='network'||key==='block')cell.innerHTML=key==='network'?pairedRate(m.rx,m.tx):pairedRate(m.read,m.write);else cell.textContent=values[key];}});});}
function historyGap(){state.history=state.history.filter(function(p){return Date.now()-p.time<=300000;}).slice(-299);if(state.history.length&&state.history[state.history.length-1].cpu!==null)state.history.push({time:Date.now(),cpu:null});}
function trend(){if(state.history.length<2)return '<p class="rl-note">Collecting CPU history…</p>';var max=Math.max(100,...state.history.map(function(s){return s.cpu||0;})),start=state.history[0].time,end=state.history[state.history.length-1].time,segments=[],segment=[];state.history.forEach(function(s){if(s.cpu===null){if(segment.length)segments.push(segment);segment=[];return;}segment.push(((s.time-start)/Math.max(1,end-start)*240).toFixed(1)+','+(48-s.cpu/max*44).toFixed(1));});if(segment.length)segments.push(segment);return '<svg class="rl-trend" viewBox="0 0 240 52" preserveAspectRatio="none" role="img" aria-label="Selected container CPU history">'+segments.map(function(p){return '<polyline points="'+p.join(' ')+'"/>';}).join('')+'</svg><p class="rl-note">'+Math.round((end-start)/1000)+' s of CPU history · scale '+Math.round(max)+'% of one CPU</p>';}
function renderContainerInspector(){var c=currentContainer();if(!c){$('rl-container-inspector').dataset.identity='';$('rl-container-inspector').innerHTML='<h3>Select a container</h3><p>View usage and details. Use native controls to make changes.</p>';return;}
 var entry=state.samples.get(c.id),selected=entry&&M.sameIdentity(c,entry.sample,M.list(state.docker&&state.docker.containers),state.dockerIndex)?entry.sample:null,m=metricsFor(c),menu=c.menu||{},stats=selected&&selected.stats||{},details=state.docker&&state.docker.selected&&M.sameIdentity(c,state.docker.selected,state.docker.containers,state.dockerIndex)?state.docker.selected:{};
 var content='<h3>'+esc(c.name)+'</h3>'+stateBadge(c.state)+'<p>'+esc(c.image||'')+'</p>'+keyValues([['CPU',pct(m.cpu),'100% = one logical CPU'],['Memory · '+(stats.memoryMode==='working_set'?'working set':'reported usage'),bytes(m.memory,true)],['Network in / out',rate(m.rx)+' / '+rate(m.tx),'Docker-reported network traffic'],['Disk read / write',rate(m.read)+' / '+rate(m.write),'Docker-reported block I/O, not physical disk throughput'],['GPU activity',gpuActivity(c)],['Last sample',stats.sampledAtMs?when(stats.sampledAtMs):'Waiting / unavailable'],['Sample age',rowAge(c)]])+trend()+keyValues([['Pools',M.list(details.storage&&details.storage.pools).join(', ')||'No data'],['Addresses',M.list(details.addresses).join(', ')||'No published address'],['Ports',M.list(details.ports).join(', ')||'No published ports'],['Restarts',num(details.restartCount)===null?'Unknown':details.restartCount]]);
 var actions=(menu.template?'<a class="rl-button rl-primary" href="'+esc(M.editUrl(menu.template))+'">Edit in Unraid ↗</a><p class="rl-note">Applying changes may restart this container.</p>':'<p class="rl-notice">No verified Unraid template. Manage this container in Docker or its owning app.</p>')+'<a class="rl-button" href="/Docker">Open Docker ↗</a>';
 var inspector=$('rl-container-inspector'),identity=JSON.stringify([c.id,c.name,menu.template||'']);if(inspector.dataset.identity!==identity){inspector.innerHTML='<div class="rl-inspector-data">'+content+'</div><div class="rl-inspector-actions">'+actions+'</div>';inspector.dataset.identity=identity;}else{var body=inspector.querySelector('.rl-inspector-data');if(body)body.innerHTML=content;}
}
function renderCooling(){var d=state.fan||{},shelves=M.list(d.shelves),ipmi=d.ipmi||{},controller=d.controller||{},watchdog=d.watchdog||{},fanHealth=M.fanHealth(d),feed=status.fan,transportStale=feed&&(feed.error||Date.now()-feed.received>16000||Date.now()-(feed.advancedAt||feed.received)>16000);
 $('rl-fan-manage').hidden=!fansDetected;
 if(fanAbsent){$('rl-tab-cooling').hidden=true;$('rl-cooling').hidden=true;return;}
 if(!fansDetected){$('rl-cooling-status').innerHTML='<div class="rl-box"><h3>Fan controller status unavailable</h3><p>Controller detection is unknown.</p></div>';return;}
 $('rl-cooling-status').innerHTML=(d.md12xxAvailable===false?'':'<article class="rl-box"><h3>Shelf controller</h3>'+keyValues([['Mode',d.mode||'Unknown'],['State',transportStale?'Unavailable · cached':fanHealth.state],['Enabled',d.enabled===true?'Yes':d.enabled===false?'No':'Unknown'],['Version',d.version||'Unknown'],['Watchdog',watchdog.message==='Controller is running under supervision'?'Running':watchdog.message||watchdog.state||'Unknown'],['Last watchdog change',fanHealth.watchdogChangedAt?when(fanHealth.watchdogChangedAt):'Timestamp unavailable']])+'</article>')+shelves.map(function(s){return '<article class="rl-box"><h3>'+esc(s.name||s.model||'Shelf')+'</h3>'+keyValues([['Average RPM',num(s.averageRpm)===null?'—':Math.round(s.averageRpm)],['Temperature',temp(s.temperatureC)],['Target',pct(s.targetPercent)]].concat(transportStale||s.telemetryMessage!=null&&(typeof s.telemetryMessage!=='string'||s.telemetryMessage.trim())?[['Telemetry',transportStale?'Unavailable · cached values':s.telemetryMessage]]:[]))+'</article>';}).join('')+(ipmi.available?'<article class="rl-box"><h3>Chassis / IPMI</h3>'+keyValues([['Mode',ipmi.mode||'Unknown'],['State',transportStale?'Unavailable · cached':ipmi.stale?'Stale':ipmi.state||'Unknown'],['Average RPM',num(ipmi.averageRpm)===null?'—':Math.round(ipmi.averageRpm)],['Target',pct(ipmi.targetPercent)],['Message',ipmi.message==='Command acknowledged; RPM telemetry is shown separately'?'Command acknowledged':ipmi.message||'—']])+'</article>':'');
 text('rl-fan-age',status.fan?(status.fan.error?'Fan status unavailable; use native controller.':transportStale?'Fan status stale; use native controller.':'Controller status sample '+when(status.fan.sample)):'Reading controller status…');
}
function accept(key,data){if(stopped)return;if(!M.validPayload(key,data))throw new Error('Invalid '+key+' payload');var previous=status[key],sample=M.number(data.sampledAtMs)||(key==='fan'&&num(data.generatedAt)>0?Number(data.generatedAt)*1000:Date.now());if(Date.now()-(data._racklineClockOffset||0)-sample < -1000)throw new Error('Future sample time');if(previous&&sample<previous.sample)throw new Error('Regressing sample time');status[key]={received:Date.now(),advancedAt:previous&&sample===previous.sample?previous.advancedAt||previous.received:Date.now(),sample:sample,clockOffset:data._racklineClockOffset||0,error:false};
 if(key==='system'){state.previousSystem=state.system;state.system=data;renderSystem();}
 if(key==='storage'){state.storage=data;renderDrives();}
 if(key==='docker'){state.docker=data;renderDocker();}
 if(key==='io'){state.previousIo=state.io;state.io=data;renderIo();if(state.tab==='drives')renderDriveRows();}
 if(key==='fan'){state.fan=data;renderCooling();}
 refreshAge();
}
async function request(url){var controller=new AbortController(),requestEpoch=visibilityEpoch;controllers.add(controller);var timeout=setTimeout(function(){controller.abort();},8000);requestTimers.add(timeout);try{var response=await fetch(url,{cache:'no-store',credentials:'same-origin',signal:controller.signal});if(!response.ok){var error=new Error('HTTP '+response.status);error.status=response.status;throw error;}var data=await response.json(),serverDate=response.headers&&response.headers.get('Date'),serverTime=serverDate?Date.parse(serverDate):NaN;if(stopped||document.hidden||requestEpoch!==visibilityEpoch){var cancelled=new Error('Discarded interrupted request');cancelled.cancelled=true;throw cancelled;}if(data&&typeof data==='object')Object.defineProperty(data,'_racklineClockOffset',{value:Number.isFinite(serverTime)?Date.now()-serverTime:0});return data;}catch(error){if(stopped||document.hidden||requestEpoch!==visibilityEpoch)error.cancelled=true;throw error;}finally{clearTimeout(timeout);requestTimers.delete(timeout);controllers.delete(controller);}}
function makeLoop(key,period,run,allowed){var loop={busy:false,timer:null};loops[key]=loop;async function turn(){if(stopped)return;if(loop.busy)return;if(document.hidden||(allowed&&!allowed())){loop.timer=setTimeout(turn,period);return;}loop.busy=true;try{await run();}catch(e){if(stopped||e.cancelled)return;var prior=status[key]||{};status[key]=Object.assign({},prior,{received:prior.received||0,error:true});if(key==='fan'&&(e.status===404||e.status===410)){fansAvailable=false;}if(key==='fan')renderCooling();if(key==='batch'){state.metrics=null;updateRowMetrics();renderBatchStatus();renderContainerInspector();}refreshAge();}finally{loop.busy=false;if(!stopped)loop.timer=setTimeout(turn,period);}}loop.turn=turn;turn();}
function wake(key){var loop=loops[key];if(!loop||loop.busy||stopped)return;clearTimeout(loop.timer);loop.turn();}
async function batchLoop(){var inventory=M.list(state.docker&&state.docker.containers),plan=M.batchPlan(inventory,state.batchCursor,state.container,12);if(!plan.containers.length){delete status.batch;renderBatchStatus();return;}state.batchCursor=plan.next;
 var plannedVersions=state.inventoryVersions;var data;try{data=await request(endpoints.batch+'?ids='+plan.containers.map(function(c){return encodeURIComponent(c.id);}).join(','));if(!M.validPayload('batch',data))throw new Error('Invalid container batch');if(Date.now()-(data._racklineClockOffset||0)-data.sampledAtMs < -1000)throw new Error('Future batch time');if(status.batch&&M.number(data.sampledAtMs)<status.batch.sample)throw new Error('Regressing batch time');}catch(error){if(error.cancelled)throw error;plan.containers.forEach(function(c){state.samples.delete(c.id);if(c.name===state.container)historyGap();});throw error;}
 if(stopped||document.hidden)return;var oldStatus=status.batch;status.batch={received:Date.now(),advancedAt:oldStatus&&M.number(data.sampledAtMs)===oldStatus.sample?oldStatus.advancedAt:Date.now(),sample:M.number(data.sampledAtMs),clockOffset:data._racklineClockOffset||0,error:false};
 var current=M.list(state.docker&&state.docker.containers),currentIndex=state.dockerIndex||M.inventoryIndex(current);plan.containers.forEach(function(expected){if(plannedVersions&&plannedVersions.get(expected.id)!==state.inventoryVersions.get(expected.id))return;var c=current.find(function(c){return c.id===expected.id&&c.name===expected.name&&c.state==='running';});if(!c)return;var matches=data.containers.filter(function(row){return row.requestedId.toLowerCase()===expected.id.toLowerCase();});var sample=matches.length===1?matches[0]:null,prior=state.samples.get(c.id);if(!sample||sample.status!=='ok'||sample.state!=='running'||!M.sameIdentity(c,sample,current,currentIndex)||!sample.stats.available){state.samples.delete(c.id);if(c.name===state.container)historyGap();return;}if(Date.now()-(data._racklineClockOffset||0)-sample.stats.sampledAtMs < -1000){state.samples.delete(c.id);if(c.name===state.container)historyGap();return;}if(prior&&M.number(sample.stats.sampledAtMs)<=M.number(prior.sample.stats.sampledAtMs))return;
 if(prior&&prior.sample.generation!==sample.generation){state.gpuBlocked.set(c.name,Date.now());if(c.name===state.container)state.history=[];}
 var entry={sample:sample,metrics:M.batchMetrics(sample,prior&&prior.sample,120),received:Date.now(),advancedAt:Date.now(),clockOffset:data._racklineClockOffset||0,error:false};state.samples.set(c.id,entry);
 if(c.name===state.container){state.previousSelected=state.selected;state.selected=sample;state.metrics=entry.metrics;state.history.push({time:Date.now(),cpu:entry.metrics.cpu});state.history=state.history.filter(function(p){return Date.now()-p.time<=300000;}).slice(-300);}
 });
 state.gpu=data.gpu||null;if(state.gpu)state.gpu._clockOffset=data._racklineClockOffset||0;renderContainerRows();renderContainerInspector();renderBatchStatus();refreshAge();
}
function cancelFilterRestore(){
 if(filterFrame!==null){window.cancelAnimationFrame(filterFrame);filterFrame=null;}
 if(filterReserve)filterReserve.panel.classList.remove('rl-filter-changing');
}
function clearFilterViewport(){
 cancelFilterRestore();if(!filterReserve)return;
 var spacer=filterReserve.spacer;if(spacer.parentNode)spacer.parentNode.removeChild(spacer);filterReserve=null;
}
function trimFilterReserve(){
 if(!filterReserve||filterFrame!==null)return;
 var spacer=filterReserve.spacer,height=spacer.getBoundingClientRect().height,natural=document.documentElement.scrollHeight-height,needed=window.scrollY<=0?0:Math.max(0,window.scrollY+window.innerHeight-natural);
 if(needed<height)spacer.style.height=Math.ceil(needed)+'px';
 if(needed<=0)clearFilterViewport();
}
function onFilterUserInput(){cancelFilterRestore();trimFilterReserve();}
function onFilterScroll(){
 if(filterFrame!==null&&filterReserve&&(Math.abs(window.scrollY-filterReserve.expectedY)>1||Math.abs(window.scrollX-filterReserve.expectedX)>1))cancelFilterRestore();
 trimFilterReserve();
}
function restoreFilterViewport(){
 var held=filterReserve;if(!held||stopped)return;
 var targetY=Math.max(0,window.scrollY+held.bar.getBoundingClientRect().top-held.top),height=held.spacer.getBoundingClientRect().height,natural=document.documentElement.scrollHeight-height;
 // Reserve only the space needed to avoid bottom-of-document clamping, never
 // a remembered full list height. It is bounded to one viewport and shrinks
 // safely as the user scrolls up. No whole-page overflow/scroll lock is used.
 held.spacer.style.height=(targetY<=0?0:Math.min(window.innerHeight,Math.ceil(Math.max(0,targetY+window.innerHeight-natural))))+'px';
 held.bar.scrollLeft=held.left;window.scrollTo({left:held.x,top:targetY,behavior:'instant'});
 held.expectedX=window.scrollX;held.expectedY=window.scrollY;
}
function changeFilter(panelId,barId,change){
 var panel=$(panelId),bar=$(barId);
 if(!panel||!bar||!document.documentElement||typeof window.scrollTo!=='function'||!Number.isFinite(window.scrollY)||!(window.innerHeight>0)){change();return;}
 cancelFilterRestore();if(filterReserve&&filterReserve.panel!==panel)clearFilterViewport();
 var top=bar.getBoundingClientRect().top,left=bar.scrollLeft,x=window.scrollX;
 if(!filterReserve){var spacer=document.createElement('div');spacer.className='rl-filter-reserve';spacer.setAttribute('aria-hidden','true');panel.appendChild(spacer);filterReserve={panel:panel,bar:bar,spacer:spacer};}
 Object.assign(filterReserve,{top:top,left:left,x:x});panel.classList.add('rl-filter-changing');
 // This temporary guard is reduced synchronously, before paint, after render.
 filterReserve.spacer.style.height=window.innerHeight+'px';
 try{change();restoreFilterViewport();}catch(error){clearFilterViewport();throw error;}
 if(typeof window.requestAnimationFrame==='function')filterFrame=window.requestAnimationFrame(function(){
  filterFrame=null;if(!filterReserve||stopped)return;
  if(Math.abs(window.scrollY-filterReserve.expectedY)<=1&&Math.abs(window.scrollX-filterReserve.expectedX)<=1)restoreFilterViewport();
  cancelFilterRestore();trimFilterReserve();
 });else{cancelFilterRestore();trimFilterReserve();}
}
function onClick(event){var smart=event.target.closest('[data-smart-action]');if(smart&&root.contains(smart)){runSmartRead();return;}var action=event.target.closest('[data-docker-action]');if(action&&root.contains(action)){runDockerAction(action.dataset.dockerAction);return;}var b=event.target.closest('[data-tab],[data-pool],[data-drive],[data-folder],[data-container],[data-native-menu]');if(!b||!root.contains(b))return;
 if(b.dataset.nativeMenu){if(b.dataset.nativeFallback==='true'||typeof window.addDockerContainerContext!=='function')location.href='/Docker';return;}
 if(b.dataset.tab){setTab(b.dataset.tab,true);return;}if(b.dataset.pool){invalidateSmartAction();changeFilter('rl-drives','rl-pools',function(){state.pool=b.dataset.pool;renderDrives();});return;}if(b.dataset.folder){dockerAction.generation++;changeFilter('rl-docker','rl-folders',function(){state.folder=b.dataset.folder;renderDocker();});return;}if(b.dataset.drive){invalidateSmartAction();state.drive=b.dataset.drive;renderDrives();return;}if(b.dataset.container){dockerAction.generation++;state.container=b.dataset.container;clearSelection();renderDocker();wake('docker');wake('batch');}
}
function onKey(event){var row=event.target.closest('tr[data-drive],tr[data-container]');if(row&&event.target===row&&(event.key==='Enter'||event.key===' ')){event.preventDefault();row.click();}var tab=event.target.closest('[data-tab]');if(tab&&(event.key==='ArrowLeft'||event.key==='ArrowRight')){event.preventDefault();var names=availableTabs(),index=names.indexOf(state.tab),next=names[(index+(event.key==='ArrowRight'?1:names.length-1))%names.length];setTab(next,true);$('rl-tab-'+next).focus();}}
function revealFocusedControl(){
 focusFrame=null;if(stopped)return;
 var target=document.activeElement,pulse=root.querySelector('.rl-pulse');
 if(!target||!root.contains(target)||!target.matches||!target.matches(':focus-visible')||!pulse||pulse.contains(target)||typeof window.getComputedStyle!=='function'||typeof window.scrollBy!=='function')return;
 if(window.getComputedStyle(pulse).position!=='sticky')return;
 var bar=pulse.getBoundingClientRect(),rect=target.getBoundingClientRect();
 if(rect.height>window.innerHeight-Math.max(0,bar.bottom-bar.top)-16)return;
 if(rect.width>0&&rect.height>0&&bar.bottom>0&&bar.top<window.innerHeight&&rect.right>bar.left&&rect.left<bar.right&&rect.top<bar.bottom&&rect.bottom>bar.top){
  // Reveal keyboard focus below our own sticky strip without changing focus,
  // horizontal table position, native menus, or any native shell styles.
  window.scrollBy({left:0,top:rect.top-bar.bottom-8,behavior:'instant'});
 }
}
function onFocusIn(){
 // Let the browser's normal focus scrolling finish before checking occlusion.
 if(stopped||typeof window.requestAnimationFrame!=='function')return;
 if(focusFrame!==null)window.cancelAnimationFrame(focusFrame);
 focusFrame=window.requestAnimationFrame(revealFocusedControl);
}
function onVisibility(){invalidateSmartAction();visibilityEpoch++;if(document.hidden){clearFilterViewport();controllers.forEach(function(c){c.abort();});return;}if(!document.hidden){historyGap();state.previousSystem=null;state.previousIo=null;state.previousSelected=null;state.samples.clear();state.gpu=null;Object.keys(loops).forEach(wake);}}
function onHash(){setTab(location.hash.slice(1),false);}
function onDriveSearch(e){invalidateSmartAction();state.driveQuery=e.target.value;renderDriveRows();}
function onContainerSearch(e){dockerAction.generation++;state.query=e.target.value;renderContainerRows();}
function onPageShow(event){if(event.persisted)location.reload();}
function onPageHide(){window.Rackline.destroy();window.addEventListener('pageshow',onPageShow,{once:true});}
window.addEventListener('scroll',onFilterScroll,{passive:true});window.addEventListener('resize',clearFilterViewport);['wheel','touchstart','pointerdown','keydown'].forEach(function(type){window.addEventListener(type,onFilterUserInput,{passive:true});});
text('rl-host',bootstrap.hostName||'This server');root.addEventListener('click',onClick);root.addEventListener('keydown',onKey);root.addEventListener('focusin',onFocusIn);$('rl-drive-search').addEventListener('input',onDriveSearch);$('rl-container-search').addEventListener('input',onContainerSearch);window.addEventListener('popstate',onHash);window.addEventListener('hashchange',onHash);document.addEventListener('visibilitychange',onVisibility);setTab(location.hash.slice(1),false);renderCooling();
if(!document.getElementById('iframe-popup')){var popup=document.createElement('div');popup.id='iframe-popup';popup.style.display='none';document.body.appendChild(popup);}
var editContainer=function(container,template){location.href=M.editUrl(template);};window.editContainer=editContainer;
var loadlist=function(){if(stopped)return;if(dockerAction.busy){dockerAction.message='Native Docker refreshed. Verify its result, then reload Rackline to enable another action.';renderDockerActions();}if(typeof nativeLoadlist==='function')nativeLoadlist.apply(window,arguments);clearSelection();wake('docker');wake('batch');};window.loadlist=loadlist;
window.Rackline={destroy:function(){stopped=true;invalidateSmartAction();smartAction.controllers.forEach(function(c){c.abort();});clearFilterViewport();window.removeEventListener('scroll',onFilterScroll);window.removeEventListener('resize',clearFilterViewport);['wheel','touchstart','pointerdown','keydown'].forEach(function(type){window.removeEventListener(type,onFilterUserInput);});if(focusFrame!==null){window.cancelAnimationFrame(focusFrame);focusFrame=null;}if(coreObserver)coreObserver.disconnect();window.removeEventListener('resize',layoutCores);Array.from(nativeMenus.keys()).forEach(removeNativeMenu);Object.values(loops).forEach(function(l){clearTimeout(l.timer);});timers.forEach(clearInterval);requestTimers.forEach(clearTimeout);requestTimers.clear();controllers.forEach(function(c){c.abort();});root.removeEventListener('click',onClick);root.removeEventListener('keydown',onKey);root.removeEventListener('focusin',onFocusIn);$('rl-drive-search').removeEventListener('input',onDriveSearch);$('rl-container-search').removeEventListener('input',onContainerSearch);window.removeEventListener('pagehide',onPageHide);window.removeEventListener('pageshow',onPageShow);document.removeEventListener('visibilitychange',onVisibility);window.removeEventListener('popstate',onHash);window.removeEventListener('hashchange',onHash);if(window.editContainer===editContainer){if(nativeEditContainer)window.editContainer=nativeEditContainer;else delete window.editContainer;}if(window.loadlist===loadlist){if(nativeLoadlist)window.loadlist=nativeLoadlist;else delete window.loadlist;}}};
window.addEventListener('pagehide',onPageHide,{once:true});window.addEventListener('pageshow',onPageShow);
if(typeof ResizeObserver==='function'){coreObserver=new ResizeObserver(layoutCores);coreObserver.observe($('rl-cores'));}window.addEventListener('resize',layoutCores);
if(bootstrap.available!==true){text('rl-availability','Rackline collectors are not installed completely. Return to the native dashboard and reinstall the reviewed Rackline package.');return;}
makeLoop('system',1000,async function(){accept('system',await request(endpoints.system));});makeLoop('storage',5000,async function(){accept('storage',await request(endpoints.storage));});makeLoop('io',2000,async function(){accept('io',await request(endpoints.io));});makeLoop('docker',5000,async function(){var selected=state.tab==='docker'&&state.container?'?selected='+encodeURIComponent(state.container):'';accept('docker',await request(endpoints.docker+selected));});makeLoop('batch',2000,batchLoop,function(){return state.tab==='docker'&&M.list(state.docker&&state.docker.containers).some(function(c){return c.state==='running';});});makeLoop('fan',5000,async function(){accept('fan',await request(endpoints.fan));},function(){return fansAvailable;});timers.push(setInterval(refreshAge,1000));
}));
