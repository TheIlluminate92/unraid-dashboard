// Verified absence versus detection/transport uncertainty; no native host operations.
import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import fs from 'node:fs';
const app=fs.readFileSync(new URL('../source/usr/local/emhttp/plugins/rackline.dashboard/assets/js/app.js',import.meta.url),'utf8');
const fn=(a,b)=>app.slice(app.indexOf('function '+a+'('),app.indexOf('function '+b+'('));
function tabs(absent,hash=''){
 const focused=[],nodes=new Map();for(const name of ['drives','docker','cooling']){nodes.set('rl-'+name,{hidden:false});nodes.set('rl-tab-'+name,{dataset:{tab:name},hidden:false,tabIndex:0,attrs:{},setAttribute(k,v){this.attrs[k]=v;},focus(){focused.push(name);}});}
 const context={fanAbsent:absent,invalidateSmartAction(){},dockerAction:{generation:0},state:{tab:'drives'},location:{hash},history:{replaceState(a,b,hash){context.location.hash=hash;},pushState(a,b,hash){context.location.hash=hash;}},$:id=>nodes.get(id),q:()=>[...nodes.values()].filter(n=>n.dataset),clearFilterViewport(){},wake(){}};
 vm.createContext(context);vm.runInContext(fn('availableTabs','layoutPulse')+fn('onKey','revealFocusedControl'),context);return{context,nodes,focused};
}
test('verified absent Cooling has no visible panel/tab/focus target and direct hash falls back',()=>{
 const h=tabs(true,'#cooling');h.context.setTab('cooling',false);assert.equal(h.context.state.tab,'drives');assert.equal(h.context.location.hash,'#drives');assert.equal(h.nodes.get('rl-cooling').hidden,true);assert.equal(h.nodes.get('rl-tab-cooling').hidden,true);assert.equal(h.nodes.get('rl-tab-cooling').tabIndex,-1);assert.equal(h.nodes.get('rl-tab-cooling').attrs['aria-selected'],'false');assert.deepEqual(h.focused,[]);
});
test('arrow navigation wraps only through available tabs',()=>{
 const h=tabs(true);h.context.setTab('docker',false);const event={key:'ArrowRight',preventDefault(){},target:{closest:selector=>selector==='[data-tab]'?{}:null}};h.context.onKey(event);assert.equal(h.context.state.tab,'drives');assert.deepEqual(h.focused,['drives']);event.key='ArrowLeft';h.context.onKey(event);assert.equal(h.context.state.tab,'docker');assert.deepEqual(h.focused,['drives','docker']);
});
test('unknown detection keeps Cooling reachable instead of claiming absence',()=>{
 const h=tabs(false,'#cooling');h.context.setTab('cooling',false);assert.equal(h.nodes.get('rl-tab-cooling').hidden,false);assert.equal(h.nodes.get('rl-cooling').hidden,false);assert.equal(h.context.state.tab,'cooling');assert.match(app,/fanAbsent=bootstrap.fanAvailable===false/);
});
test('controller 404/410 suppresses retry polling without rewriting known detection as absence',()=>{
 const loop=fn('makeLoop','wake');assert.match(loop,/fansAvailable=false/);assert.doesNotMatch(loop,/fanAbsent=true|fansDetected=false/);
 const render=fn('renderCooling','accept');assert.match(render,/if\(fanAbsent\)/);assert.match(render,/if\(!fansDetected\)/);assert.match(render,/Unavailable · cached/);assert.doesNotMatch(render,/if\(!fansAvailable\)/);
});
