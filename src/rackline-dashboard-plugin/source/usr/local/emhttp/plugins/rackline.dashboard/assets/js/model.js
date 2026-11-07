/* Rackline data adapters. MIT; independent of native mutation APIs. */
(function (root, factory) {
  var api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.RacklineModel = api;
}(typeof window !== 'undefined' ? window : this, function () {
  'use strict';
  function number(value) { return value !== null && value !== undefined && value !== '' && typeof value !== 'boolean' && typeof value !== 'object' && Number.isFinite(Number(value)) ? Number(value) : null; }
  function object(value){return value!==null&&typeof value==='object'&&!Array.isArray(value);}
  function list(value) { return Array.isArray(value) ? value : []; }
  function deltaRate(current, previous, seconds, maxGap) {
    current=number(current); previous=number(previous); seconds=number(seconds);
    return current===null || previous===null || seconds===null || seconds<=0 || seconds>(maxGap||10) || current<previous ? null : (current-previous)/seconds;
  }
  function cpuPercent(current, previous) {
    if (!current || !previous) return null;
    var total=number(current.total), before=number(previous.total), idle=number(current.idle), priorIdle=number(previous.idle);
    if ([total,before,idle,priorIdle].some(function(v){return v===null;})) return null;
    var span=total-before, free=idle-priorIdle;
    return span>0 && free>=0 && free<=span ? 100*(span-free)/span : null;
  }
  function logicalCpus(cpu, previous, validInterval) {
    var seen=new Set(), rows=[];
    list(cpu && cpu.cores).forEach(function(core){list(core.threads).forEach(function(thread){
      var id=number(thread.cpuId);if(id===null||id<0||!Number.isInteger(id)||seen.has(id))return;seen.add(id);
      rows.push({id:id,packageId:core.packageId,coreId:core.coreId,tempC:number(core.tempC),percent:validInterval?cpuPercent((cpu.counters||{})['cpu'+id],(previous||{})['cpu'+id]):null});
    });});
    return rows.sort(function(a,b){return a.id-b.id;});
  }
  function sameIdentity(expected,sample,inventory){
    var valid=function(id){return typeof id==='string'&&/^(?:[a-f0-9]{12}|[a-f0-9]{64})$/i.test(id);};
    var compatible=function(a,b){return a===b||(a.length!==b.length&&(a.length>b.length?a.indexOf(b)===0:b.indexOf(a)===0));};
    if(!expected||!sample||!expected.name||sample.name!==expected.name||!valid(expected.id)||!valid(sample.id)||!Array.isArray(inventory))return false;
    var expectedId=expected.id.toLowerCase(),sampleId=sample.id.toLowerCase();if(!compatible(expectedId,sampleId))return false;
    var matches=inventory.filter(function(c){return c&&valid(c.id)&&compatible(c.id.toLowerCase(),sampleId);});
    return matches.length===1&&matches[0].name===expected.name&&matches[0].id.toLowerCase()===expectedId;
  }
  function folders(data){
    var containers=list(data&&data.containers), names=new Set(containers.map(function(c){return c.name;}));
    var result=[],ids=new Set();
    list(data&&data.folders).forEach(function(f){if(!f||typeof f.id!=='string'||ids.has(f.id))return;ids.add(f.id);result.push(Object.assign({},f,{containers:[...new Set(list(f.containers).filter(function(n){return names.has(n);}))]}));});
    if(!ids.has('all'))result.unshift({id:'all',name:'All containers',containers:[...names]});
    else result.find(function(f){return f.id==='all';}).containers=[...names];
    return result;
  }
  function drives(data){
    var rows=[],seen=new Set();
    function add(d,group){if(!d||typeof d.name!=='string'||!d.name)return;var key=d.device||d.name;if(seen.has(key))return;seen.add(key);rows.push(Object.assign({},d,{key:key,group:group}));}
    list(data&&data.parity&&data.parity.disks).forEach(function(d){add(d,'Parity');});
    list(data&&data.array&&data.array.disks).forEach(function(d){add(d,'Array');});
    list(data&&data.pools).forEach(function(pool){list(pool.members).forEach(function(d){add(d,pool.label||pool.name||'Pool');});});
    return rows;
  }
  function diskRates(current,previous,devices){
    var result={devices:{},read:null,write:null,count:0,expected:0};
    if(!current||!previous)return result;
    var elapsed=number(current.monotonicSeconds)!==null&&number(previous.monotonicSeconds)!==null?current.monotonicSeconds-previous.monotonicSeconds:null;
    var unique=new Set();list(devices).forEach(function(d){if(typeof d.device==='string'&&d.device)unique.add(d.device.replace(/^\/dev\//,''));});
    result.expected=unique.size;
    var read=0,write=0;
    unique.forEach(function(name){
      var a=(current.devices||{})[name],b=(previous.devices||{})[name];if(!a||!b)return;
      // Device major/minor identity prevents reusing samples after device replacement.
      if(a.major!==b.major||a.minor!==b.minor)return;
      var r=deltaRate(a.readBytes,b.readBytes,elapsed,10),w=deltaRate(a.writeBytes,b.writeBytes,elapsed,10);
      result.devices[name]={read:r,write:w};if(r===null||w===null)return;read+=r;write+=w;result.count++;
    });
    // Never label an incomplete set as a complete host total.
    if(result.expected>0&&result.count===result.expected){result.read=read;result.write=write;}
    return result;
  }
  function containerMetrics(sample,previous){
    var stats=sample&&sample.stats||{},prior=previous&&previous.stats||{},elapsed=(number(stats.sampledAtMs)-number(prior.sampledAtMs))/1000;
    if(!validStats(stats))return {cpu:null,memory:null,rx:null,tx:null,read:null,write:null};
    var a=stats.cpuCounters,b=prior.cpuCounters,cpu=null;
    if(a&&b&&elapsed>0&&elapsed<=6){var usage=number(a.usage),system=number(a.system),oldUsage=number(b.usage),oldSystem=number(b.system),online=number(a.online);if([usage,system,oldUsage,oldSystem,online].every(function(v){return v!==null;})&&usage>=oldUsage&&system>oldSystem&&online>0)cpu=(usage-oldUsage)/(system-oldSystem)*online*100;}
    // Legacy collector may coerce unsupported network/block counters to zero.
    // Until capability metadata exists, mark these as reported accounting, not complete host-network traffic.
    return {cpu:cpu,memory:number(stats.memoryBytes),rx:deltaRate(stats.networkRxBytes,prior.networkRxBytes,elapsed,6),tx:deltaRate(stats.networkTxBytes,prior.networkTxBytes,elapsed,6),read:deltaRate(stats.blockReadBytes,prior.blockReadBytes,elapsed,6),write:deltaRate(stats.blockWriteBytes,prior.blockWriteBytes,elapsed,6)};
  }
  function validStats(stats){
    // Legacy WAZ normalizes a Docker error object into available=true/all-zero stats.
    // A running Linux Docker stats sample must have a positive system CPU counter.
    return object(stats)&&stats.available===true&&number(stats.sampledAtMs)>0&&object(stats.cpuCounters)&&number(stats.cpuCounters.system)>0&&number(stats.cpuCounters.usage)!==null&&number(stats.cpuCounters.usage)>=0&&number(stats.cpuCounters.online)>0&&number(stats.memoryBytes)!==null&&number(stats.memoryBytes)>=0;
  }
  function emptyStatus(value){return object(value)||Array.isArray(value)&&value.length===0;}
  function fanHealth(payload){
    var controller=object(payload.controller)?payload.controller:{},watchdog=object(payload.watchdog)?payload.watchdog:{},changed=number(watchdog.generatedAt),state='Unknown';
    // Watchdog generatedAt is the last transition, not a periodic heartbeat.
    // Freshness belongs to the controller-derived root stale flag and IPMI's own flag.
    if(payload.enabled===false)state='Disabled';
    else if(payload.stale===true)state='Stale';
    else if(payload.stale===false&&typeof controller.state==='string')state=['restarting','recovering','fault'].includes(watchdog.state)?watchdog.state:controller.state;
    return {state:state,watchdogChangedAt:changed>0?changed*1000:null};
  }
  function validPayload(key,data){
    var records=function(v,check){return Array.isArray(v)&&v.every(function(x){return object(x)&&(!check||check(x));});};
    var drive=function(d){return typeof d.name==='string'&&typeof d.device==='string';};
    if(!object(data)||data.error)return false;
    if(key==='fan')return data.md12xxAvailable===false&&object(data.ipmi)||typeof data.enabled==='boolean'&&typeof data.mode==='string'&&emptyStatus(data.controller)&&records(data.shelves);
    if(key==='selected')return number(data.sampledAtMs)>0&&object(data.selected)&&object(data.selected.stats)&&(data.selected.stats.available===false||validStats(data.selected.stats));
    if(data.schemaVersion!==1||!(number(data.sampledAtMs)>0))return false;
    if(key==='system')return object(data.cpu)&&object(data.cpu.counters)&&records(data.cpu.cores,function(c){return records(c.threads,function(t){return Number.isInteger(number(t.cpuId))&&number(t.cpuId)>=0;});})&&object(data.memory)&&object(data.network);
    if(key==='storage')return object(data.array)&&records(data.array.disks,drive)&&object(data.parity)&&records(data.parity.disks,drive)&&records(data.pools,function(p){return records(p.members,drive);})&&(!data.locations||object(data.locations)&&records(data.locations.groups,function(g){return records(g.cells,function(c){return !c.disk||object(c.disk)&&drive(c.disk);});}));
    if(key==='docker')return typeof data.dockerAvailable==='boolean'&&records(data.containers,function(c){return typeof c.name==='string'&&typeof c.id==='string'&&(!c.menu||object(c.menu));})&&records(data.folders,function(f){return typeof f.id==='string'&&Array.isArray(f.containers)&&f.containers.every(function(n){return typeof n==='string';});})&&object(data.summary)&&(!data.topProcesses||records(data.topProcesses));
    if(key==='io')return data.status==='ok'&&number(data.monotonicSeconds)!==null&&object(data.devices)&&Object.values(data.devices).every(object);
    return false;
  }
  function editUrl(template){
    // Installed Unraid explicitly urldecodes $_GET after PHP has decoded the query.
    // Protect literal percent/plus for that second pass; ordinary paths keep their route.
    var protectedPath=String(template).replace(/%/g,'%25').replace(/\+/g,'%2B');
    return '/Docker/UpdateContainer?xmlTemplate=edit:'+encodeURIComponent(protectedPath);
  }
  function nativeArgs(c){var m=c.menu||{};return [c.name,c.imageId,m.template||'',c.state==='stopped'?0:1,c.state==='paused'?1:0,number(c.updateStatus)===null?3:Number(c.updateStatus),c.autostart?1:0,m.webUi||'',m.tailscaleWebUi||'',m.shell||'',c.id,m.support||'',m.project||'',m.registry||'',m.donate||'',m.readme||''];}
  return {number:number,list:list,deltaRate:deltaRate,cpuPercent:cpuPercent,logicalCpus:logicalCpus,sameIdentity:sameIdentity,folders:folders,drives:drives,diskRates:diskRates,containerMetrics:containerMetrics,nativeArgs:nativeArgs,validPayload:validPayload,validStats:validStats,fanHealth:fanHealth,editUrl:editUrl};
}));
