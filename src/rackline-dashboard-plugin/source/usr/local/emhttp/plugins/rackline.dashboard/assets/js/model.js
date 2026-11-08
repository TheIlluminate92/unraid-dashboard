/* Rackline data adapters. MIT; independent of native mutation APIs. */
(function (root, factory) {
  var api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.RacklineModel = api;
}(typeof window !== 'undefined' ? window : this, function () {
  'use strict';
  function number(value) { return value !== null && value !== undefined && value !== '' && typeof value !== 'boolean' && typeof value !== 'object' && !(typeof value === 'string' && value.trim() === '') && Number.isFinite(Number(value)) ? Number(value) : null; }
  function object(value){return value!==null&&typeof value==='object'&&!Array.isArray(value);}
  function list(value) { return Array.isArray(value) ? value : []; }
  function deltaRate(current, previous, seconds, maxGap) {
    current=number(current); previous=number(previous); seconds=number(seconds);
    if(current===null||previous===null||current<0||previous<0||seconds===null||seconds<=0||seconds>(maxGap||10)||current<previous)return null;
    return number((current-previous)/seconds);
  }
  function cpuPercent(current, previous) {
    if (!current || !previous) return null;
    var total=number(current.total), before=number(previous.total), idle=number(current.idle), priorIdle=number(previous.idle);
    if ([total,before,idle,priorIdle].some(function(v){return v===null||v<0;})||idle>total||priorIdle>before) return null;
    var span=total-before, free=idle-priorIdle;
    return Number.isFinite(span)&&span>0&&free>=0&&free<=span?number((span-free)/span*100):null;
  }
  function systemInterval(current, previous) {
    if(!current||!previous||!(number(current.sampledAtMs)>number(previous.sampledAtMs)))return null;
    if((current.bootId||previous.bootId)&&(!current.bootId||current.bootId!==previous.bootId))return null;
    var now=number(current.monotonicSeconds),prior=number(previous.monotonicSeconds);
    var elapsed=now===null&&prior===null?(current.sampledAtMs-previous.sampledAtMs)/1000:now!==null&&prior!==null?now-prior:null;
    return elapsed>0&&elapsed<=6?elapsed:null;
  }
  function networkRates(current, previous) {
    var n=current&&current.network||{},old=previous&&previous.network||{},span=systemInterval(current,previous);
    var same=n.interface&&n.interface===old.interface&&(!(n.identity||old.identity)||n.identity&&n.identity===old.identity);
    return {rx:same?deltaRate(n.counters&&n.counters.rxBytes,old.counters&&old.counters.rxBytes,span,6):null,tx:same?deltaRate(n.counters&&n.counters.txBytes,old.counters&&old.counters.txBytes,span,6):null};
  }
  function logicalCpus(cpu, previous, validInterval) {
    var seen=new Set(), rows=[];
    list(cpu && cpu.cores).forEach(function(core){list(core.threads).forEach(function(thread){
      var id=number(thread.cpuId);if(id===null||id<0||!Number.isInteger(id)||seen.has(id))return;seen.add(id);
      rows.push({id:id,packageId:core.packageId,coreId:core.coreId,tempC:number(core.tempC),percent:validInterval?cpuPercent((cpu.counters||{})['cpu'+id],(previous||{})['cpu'+id]):null});
    });});
    return rows.sort(function(a,b){return a.id-b.id;});
  }
  // Fill balanced rows rather than leaving one logical CPU stranded on a new row.
  function coreColumns(count,width,fontSize,gap){
    count=number(count);width=number(width);fontSize=number(fontSize);gap=number(gap);
    if(!Number.isInteger(count)||count<1)return 1;
    var minimum=Math.max(30,(fontSize>0?fontSize:14)*2.15),spacing=gap>=0?gap:3;
    var capacity=Math.max(1,Math.min(count,Math.floor(((width>0?width:minimum)+spacing)/(minimum+spacing))));
    var columns=Math.ceil(count/Math.ceil(count/capacity));
    while(columns>1&&count%columns>0&&count%columns<columns/2)columns--;
    return columns;
  }
  function validId(id){return typeof id==='string'&&/^(?:[a-f0-9]{12}|[a-f0-9]{64})$/i.test(id);}
  function inventoryIndex(inventory){
    var index={ids:new Map(),prefixes:new Map(),names:new Map()};
    var add=function(map,key,c){if(!map.has(key))map.set(key,[]);map.get(key).push(c);};
    list(inventory).forEach(function(c){if(!c)return;add(index.names,c.name,c);if(!validId(c.id))return;var id=c.id.toLowerCase();add(index.ids,id,c);add(index.prefixes,id.slice(0,12),c);});
    return index;
  }
  function identityMatches(index,id){return id.length===12?index.prefixes.get(id)||[]:(index.ids.get(id)||[]).concat(index.ids.get(id.slice(0,12))||[]);}
  function sameIdentity(expected,sample,inventory,index){
    if(!expected||!sample||!expected.name||sample.name!==expected.name||!validId(expected.id)||!validId(sample.id)||!Array.isArray(inventory))return false;
    var expectedId=expected.id.toLowerCase(),sampleId=sample.id.toLowerCase();
    if(expectedId!==sampleId&&!(expectedId.length!==sampleId.length&&(expectedId.length>sampleId.length?expectedId.startsWith(sampleId):sampleId.startsWith(expectedId))))return false;
    index=index||inventoryIndex(inventory);var matches=identityMatches(index,sampleId);
    return matches.length===1&&(index.names.get(expected.name)||[]).length===1&&matches[0].name===expected.name&&matches[0].id.toLowerCase()===expectedId;
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
      if(a.major!==b.major||a.minor!==b.minor||['major','minor'].some(function(key){var value=number(a[key]);return value===null||value<0||!Number.isInteger(value);}))return;
      var r=deltaRate(a.readBytes,b.readBytes,elapsed,10),w=deltaRate(a.writeBytes,b.writeBytes,elapsed,10);
      result.devices[name]={read:r,write:w};if(r===null||w===null)return;read+=r;write+=w;result.count++;
    });
    // Never label an incomplete set as a complete host total.
    if(result.expected>0&&result.count===result.expected){result.read=number(read);result.write=number(write);}
    return result;
  }
  function containerMetrics(sample,previous){
    var stats=sample&&sample.stats||{},prior=previous&&previous.stats||{},elapsed=(number(stats.sampledAtMs)-number(prior.sampledAtMs))/1000;
    if(!validStats(stats))return {cpu:null,memory:null,rx:null,tx:null,read:null,write:null};
    if(!validStats(prior))prior={};
    var a=stats.cpuCounters,b=prior.cpuCounters,cpu=null;
    if(a&&b&&validStats(prior)&&elapsed>0&&elapsed<=6){var usage=number(a.usage),system=number(a.system),oldUsage=number(b.usage),oldSystem=number(b.system),online=number(a.online);if([usage,system,oldUsage,oldSystem,online].every(function(v){return v!==null;})&&usage>=oldUsage&&system>oldSystem&&online>0)cpu=number((usage-oldUsage)/(system-oldSystem)*online*100);}
    // Legacy collector may coerce unsupported network/block counters to zero.
    // Until capability metadata exists, mark these as reported accounting, not complete host-network traffic.
    return {cpu:cpu,memory:number(stats.memoryBytes),rx:deltaRate(stats.networkRxBytes,prior.networkRxBytes,elapsed,6),tx:deltaRate(stats.networkTxBytes,prior.networkTxBytes,elapsed,6),read:deltaRate(stats.blockReadBytes,prior.blockReadBytes,elapsed,6),write:deltaRate(stats.blockWriteBytes,prior.blockWriteBytes,elapsed,6)};
  }
  function validStats(stats){
    // Legacy adapters may normalize a Docker error object into available=true/all-zero stats.
    // A running Linux Docker stats sample must have a positive system CPU counter.
    return object(stats)&&stats.available===true&&number(stats.sampledAtMs)>0&&object(stats.cpuCounters)&&number(stats.cpuCounters.system)>0&&number(stats.cpuCounters.usage)!==null&&number(stats.cpuCounters.usage)>=0&&number(stats.cpuCounters.online)>0&&number(stats.memoryBytes)!==null&&number(stats.memoryBytes)>=0;
  }
  function batchPlan(containers,cursor,selected,limit){
    limit=Math.max(1,Math.min(12,Math.floor(number(limit)||12)));
    var candidates=list(containers).filter(function(c){return c&&c.state==='running'&&typeof c.name==='string'&&c.name&&validId(c.id);}),index=inventoryIndex(candidates);
    var running=candidates.filter(function(c){return index.names.get(c.name).length===1&&identityMatches(index,c.id.toLowerCase()).length===1;});
    var priority=running.find(function(c){return c.name===selected;}),peers=running.filter(function(c){return c!==priority;}),start=peers.length?Math.max(0,Math.floor(number(cursor)||0))%peers.length:0,chosen=priority?[priority]:[],slots=limit-chosen.length;
    for(var i=0;i<Math.min(slots,peers.length);i++)chosen.push(peers[(start+i)%peers.length]);
    return {containers:chosen,next:peers.length?(start+Math.min(slots,peers.length))%peers.length:0,total:running.length};
  }
  function validBatchStats(stats){
    if(!object(stats)||typeof stats.available!=='boolean')return false;
    if(!stats.available)return true;
    var nullable=function(v){return v===null||number(v)!==null&&number(v)>=0;};
    return number(stats.sampledAtMs)>0&&number(stats.monotonicSeconds)!==null&&number(stats.monotonicSeconds)>=0&&
      (stats.cpuCounters===null||object(stats.cpuCounters)&&['usage','system','online'].every(function(k){return number(stats.cpuCounters[k])!==null&&number(stats.cpuCounters[k])>=0;}))&&
      ['memoryBytes','networkRxBytes','networkTxBytes','blockReadBytes','blockWriteBytes'].every(function(k){return nullable(stats[k]);});
  }
  function batchMetrics(sample,previous,maxGap){
    var unknown={cpu:null,memory:null,rx:null,tx:null,read:null,write:null},stats=sample&&sample.stats,prior=previous&&previous.stats;
    if(!validBatchStats(stats)||!stats.available)return unknown;
    var elapsed=prior&&number(stats.sampledAtMs)>number(prior.sampledAtMs)?number(stats.monotonicSeconds)-number(prior.monotonicSeconds):null,valid=validBatchStats(prior)&&prior.available&&typeof sample.generation==='string'&&sample.generation&&sample.generation===previous.generation&&stats.timestampSource===prior.timestampSource&&elapsed>0&&elapsed<=Math.min(120,maxGap||120),a=stats.cpuCounters,b=prior&&prior.cpuCounters,cpu=null;
    if(valid&&a&&b){var usage=number(a.usage),system=number(a.system),oldUsage=number(b.usage),oldSystem=number(b.system),online=number(a.online);if(usage>=oldUsage&&system>oldSystem&&online>0&&online===number(b.online))cpu=number((usage-oldUsage)/(system-oldSystem)*online*100);}
    var rate=function(key,identity){return valid&&typeof stats[identity]==='string'&&stats[identity]&&stats[identity]===prior[identity]?deltaRate(stats[key],prior[key],elapsed,120):null;};
    return {cpu:cpu,memory:number(stats.memoryBytes),rx:rate('networkRxBytes','networkIdentity'),tx:rate('networkTxBytes','networkIdentity'),read:rate('blockReadBytes','blockIdentity'),write:rate('blockWriteBytes','blockIdentity')};
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
    if(key==='docker')return typeof data.dockerAvailable==='boolean'&&records(data.containers,function(c){return typeof c.name==='string'&&c.name&&validId(c.id)&&(!c.menu||object(c.menu));})&&new Set(data.containers.map(function(c){return c.name;})).size===data.containers.length&&new Set(data.containers.map(function(c){return c.id.toLowerCase();})).size===data.containers.length&&records(data.folders,function(f){return typeof f.id==='string'&&Array.isArray(f.containers)&&f.containers.every(function(n){return typeof n==='string';});})&&object(data.summary)&&(!data.topProcesses||records(data.topProcesses));
    if(key==='batch')return data.status==='ok'&&records(data.containers,function(c){return (typeof c.id==='string'||c.id===null)&&(typeof c.name==='string'||c.name===null)&&typeof c.requestedId==='string'&&typeof c.state==='string'&&typeof c.status==='string'&&validBatchStats(c.stats);})&&data.containers.length<=12&&number(data.requestedCount)>=0&&number(data.requestedCount)<=12&&(!data.gpu||object(data.gpu));
    if(key==='io')return data.status==='ok'&&number(data.monotonicSeconds)!==null&&object(data.devices)&&Object.values(data.devices).every(object);
    return false;
  }
  function editUrl(template){
    // Installed Unraid explicitly urldecodes $_GET after PHP has decoded the query.
    // Protect literal percent/plus for that second pass; ordinary paths keep their route.
    var protectedPath=String(template).replace(/%/g,'%25').replace(/\+/g,'%2B');
    return '/Docker/UpdateContainer?xmlTemplate=edit:'+encodeURIComponent(protectedPath);
  }
  function nativeMenuReady(c){
    if(!c||!['running','stopped','paused'].includes(c.state)||!object(c.menu)||typeof c.menu.template!=='string'||!c.menu.template||typeof c.autostart!=='boolean'||!validId(c.id)||typeof c.name!=='string'||!/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/.test(c.name))return false;
    // Native docker.js owns markup construction. Never pass it strings that can escape
    // quoted HTML/JavaScript contexts; unsafe metadata falls back to the native page.
    var safe=function(value){return value===undefined||value===''||typeof value==='string'&&!/[\x00-\x1f\x7f<>"'`\\&]/.test(value);};
    if(!['template','webUi','tailscaleWebUi','shell','support','project','registry','donate','readme'].every(function(k){return safe(c.menu[k]);})||!safe(c.imageId))return false;
    if(c.menu.shell&&!['sh','bash','ash','zsh','/bin/sh','/bin/bash','/bin/ash','/bin/zsh'].includes(c.menu.shell))return false;
    return ['webUi','tailscaleWebUi','support','project','registry','donate','readme'].every(function(k){var value=c.menu[k];return !value||!/[\s\\]/.test(value)&&/^(?:https?:\/\/|\/(?!\/))/i.test(value);});
  }
  function nativeArgs(c){var m=c.menu||{};return [c.name,c.imageId,m.template||'',c.state==='stopped'?0:1,c.state==='paused'?1:0,number(c.updateStatus)===null?3:Number(c.updateStatus),c.autostart?1:0,m.webUi||'',m.tailscaleWebUi||'',m.shell||'',c.id,m.support||'',m.project||'',m.registry||'',m.donate||'',m.readme||''];}
  return {number:number,list:list,systemInterval:systemInterval,networkRates:networkRates,deltaRate:deltaRate,cpuPercent:cpuPercent,logicalCpus:logicalCpus,coreColumns:coreColumns,sameIdentity:sameIdentity,inventoryIndex:inventoryIndex,folders:folders,drives:drives,diskRates:diskRates,containerMetrics:containerMetrics,nativeArgs:nativeArgs,nativeMenuReady:nativeMenuReady,validPayload:validPayload,validStats:validStats,fanHealth:fanHealth,editUrl:editUrl,batchPlan:batchPlan,validBatchStats:validBatchStats,batchMetrics:batchMetrics};
}));
