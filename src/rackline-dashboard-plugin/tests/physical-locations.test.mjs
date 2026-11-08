// Backend owns mapping. Rendering must not renumber, infer, compress or join it.
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {createRequire} from 'node:module';
const M=createRequire(import.meta.url)('../source/usr/local/emhttp/plugins/rackline.dashboard/assets/js/model.js');
const app=fs.readFileSync(new URL('../source/usr/local/emhttp/plugins/rackline.dashboard/assets/js/app.js',import.meta.url),'utf8');
const code=app.slice(app.indexOf('function renderBays('),app.indexOf('function renderDocker('));
function render(groups){const node={};const ctx={M,when:v=>v===null?'Unknown':String(v),state:{storage:{locations:{groups}},drive:''},$:()=>node,stableMarkup:(e,s)=>e.innerHTML=s,esc:v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))};vm.createContext(ctx);vm.runInContext(code,ctx);return {ctx,draw(){ctx.renderBays();return node.innerHTML;}};}
function group(columns,rows,start=0){return {name:'Synthetic enclosure',columns,rows,trayDirection:'h',cells:Array.from({length:columns*rows},(_,i)=>({tray:start+i,sourceTray:i+1,present:false,hidden:false,disk:null}))};}
test('current cached source order and reserved holes are rendered without guessed numbering',()=>{
 const g=group(4,2,20);g.cells=[0,2,4,6,1,3,5,7].map(i=>g.cells[i]);g.cells[0].hidden=true;g.cells[4].hidden=true;
 const h=render([g]);let html=h.draw();assert.match(html,/--rl-columns:4/);assert.deepEqual([...html.matchAll(/<b>(.*?)<\/b>/g)].map(m=>m[1]),['20','22','24','26','21','23','25','27']);assert.equal((html.match(/rl-hidden/g)||[]).length,2);assert.equal((html.match(/<button/g)||[]).length,8);
 // A later payload may change size/start/order/orientation. Nothing is remembered.
 const next=group(3,3,101);next.trayDirection='v';next.cells.reverse();h.ctx.state.storage.locations.groups=[next];html=h.draw();assert.match(html,/--rl-columns:3/);assert.equal((html.match(/<button/g)||[]).length,9);assert.deepEqual([...html.matchAll(/<b>(.*?)<\/b>/g)].map(m=>m[1]),['109','108','107','106','105','104','103','102','101']);assert.doesNotMatch(html,/rl-hidden/);
});
test('large enclosure geometry respects valid backend columns up to 100, never clamps to 64',()=>{
 for(const columns of [1,8,12,24,48,64,65,80,100]){const h=render([group(columns,2)]),html=h.draw();assert.match(html,new RegExp('--rl-columns:'+columns+'(?:;|\")'));assert.equal((html.match(/<button/g)||[]).length,columns*2);}
});
test('new assigned devices become clickable only from backend verified disk metadata',()=>{
 const g=group(2,1,10);g.cells[0].present=true;g.cells[0].inventory={name:'cached name',device:'sde',model:'cached model'};
 const h=render([g]);let html=h.draw();assert.doesNotMatch(html,/data-drive=/);assert.match(html,/Unmapped/);
 g.cells[0].disk={name:'new-pool-member',device:'sdzz',identityColor:'#12abef'};html=h.draw();assert.match(html,/data-drive="sdzz"/);assert.match(html,/new-pool-member/);assert.match(html,/--rl-identity:#12abef/);
 g.cells[0].disk=null;g.cells[0].hidden=true;html=h.draw();assert.doesNotMatch(html,/data-drive=/);assert.match(html,/rl-hidden/);
});
test('malformed dimensions or cells stay unknown rather than inventing physical placement',()=>{
 for(const mutate of [g=>g.columns=101,g=>g.columns=0,g=>g.columns=2.5,g=>g.rows=3,g=>g.cells[0]=null,g=>g.cells=Array(4097).fill({})]){const g=group(2,2);mutate(g);const html=render([g]).draw();assert.match(html,/Cached tray layout unavailable/);assert.doesNotMatch(html,/rl-bay-grid|<button/);}
});
test('cached labels/colors never inject markup or create unverified actions',()=>{
 const g=group(1,1);g.name='<svg/onload=bad>';g.cells[0].disk={name:'<img src=x>',device:'sde" onclick="bad',identityColor:'red;position:fixed'};
 const html=render([g]).draw();assert.doesNotMatch(html,/<svg|<img|style="[^"]*position:fixed/);assert.match(html,/&lt;svg/);assert.match(html,/--rl-identity:#647d8e/);assert.match(html,/sde&quot; onclick=&quot;bad/);
});

test('native display alias never changes the assigned drive/SMART selection target',()=>{
 const g=group(1,1,27);g.cells[0]={tray:27,present:true,hidden:false,display:{source:'disklocation',identityVerified:true,name:'fixture-boot-alias',device:'sdx',type:'Boot',color:'#224488',model:'Fixture model',comment:'Boot alias display'},disk:{name:'assigned-pool-member',device:'sdx',selectionToken:'a'.repeat(64),poolName:'actual-pool',identityColor:'#123456'}};
 const html=render([g]).draw();assert.match(html,/<small>Boot fixture-boot-alias<\/small>/);assert.match(html,/data-drive="sdx"/);assert.match(html,/Selected drive: assigned-pool-member \(sdx\)/);assert.doesNotMatch(html,/data-drive="fixture-boot-alias"/);assert.match(html,/--rl-bay-color:#224488;--rl-bay-text:#ffffff/);
 g.cells[0].display.identityVerified=false;const unknown=render([g]).draw();assert.match(unknown,/Cached device · identity unverified/);assert.doesNotMatch(unknown,/data-drive=|Boot fixture-boot-alias/);
});
test('native dimensions are already oriented and alignment enum changes follow each payload',()=>{
 const g={...group(2,2),trayDirection:'v',trayWidth:60,trayHeight:180,trayAlign:'right',nameAlign:'vertical',position:'right'};const h=render([g]);let html=h.draw();assert.match(html,/--rl-tray-width:60px;--rl-tray-height:180px/);assert.match(html,/data-tray-direction="v"/);assert.match(html,/data-tray-align="right"/);assert.match(html,/data-name-align="vertical"/);assert.match(html,/data-position="right"/);
 Object.assign(g,{trayWidth:180,trayHeight:60,trayDirection:'h',trayAlign:'center',nameAlign:'hide',position:'none'});html=h.draw();assert.match(html,/--rl-tray-width:180px;--rl-tray-height:60px/);assert.match(html,/data-tray-direction="h"/);assert.match(html,/data-name-align="hide"/);assert.match(html,/aria-label="Synthetic enclosure"/);
 Object.assign(g,{trayWidth:1,trayHeight:1,trayAlign:'red;position:fixed',nameAlign:'<bad>',position:'top'});html=h.draw();assert.match(html,/--rl-tray-width:44px;--rl-tray-height:44px/);assert.doesNotMatch(html,/red;position|<bad>|data-position="top"/);
});
test('native cache file writes are source context, never a routine freshness timer',()=>{
 const h=render([group(1,1)]);h.ctx.state.storage.locations.source='disklocation_cache';h.ctx.state.storage.locations.cacheWrittenAtMs={groups:123,locations:456,devices:null,settings:789};const html=h.draw();assert.match(html,/Disk Location layout · cached metadata/);assert.match(html,/not discovery or SMART freshness/);assert.match(html,/groups: 123/);assert.doesNotMatch(html,/>[^<]*(?:updated|old configuration)/i);
});
test('native colors keep readable foregrounds and cannot inject CSS',()=>{
 assert.equal(M.bayTextColor('#ffffff'),'#000000');assert.equal(M.bayTextColor('#000000'),'#ffffff');assert.equal(M.bayTextColor('#ffFF00'),'#000000');assert.equal(M.bayTextColor('invalid'),'#ffffff');
 const g=group(1,1);g.cells[0].display={source:'disklocation',identityVerified:false,color:'red;position:fixed',name:'not trusted'};assert.doesNotMatch(render([g]).draw(),/rl-native-color|not trusted|position:fixed/);
 g.cells[0].disk={name:'assigned',device:'sdx',identityColor:'#ff0000'};g.cells[0].display={source:'disklocation',identityVerified:true,color:null,name:'native',type:'Cache'};const unknown=render([g]).draw();assert.doesNotMatch(unknown,/#ff0000|rl-native-color/);assert.match(unknown,/--rl-identity:#647d8e/,'Unknown native color mode must stay neutral, not fall back to a device override');
});
