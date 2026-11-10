import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import fs from 'node:fs';
import {createRequire} from 'node:module';
const M=createRequire(import.meta.url)('../source/usr/local/emhttp/plugins/rackline.dashboard/assets/js/model.js');
const src=fs.readFileSync(new URL('../source/usr/local/emhttp/plugins/rackline.dashboard/assets/js/app.js',import.meta.url),'utf8');
const code=src.slice(src.indexOf('function removeNativeMenu('),src.indexOf('function updateRowMetrics('));
function harness(){const nodes=new Map(),removed=[],registrations=[];let serial=0;const document={};const $=id=>nodes.get(id);const jq=()=>({off(type,selector){removed.push([type,selector]);return this;}});const window={jQuery:jq,addDockerContainerContext(...args){registrations.push(args);const id=args[10],action='event-'+(++serial),node={querySelectorAll:()=>[{id:action}],parentNode:{removeChild(){nodes.delete('dropdown-'+id);}}};nodes.set('dropdown-'+id,node);}};const ctx={window,document,$,M,nativeMenus:new Map()};vm.createContext(ctx);vm.runInContext(code,ctx);return{ctx,nodes,removed,registrations,container(state='running'){const c={name:'demo',id:'a'.repeat(12),state,autostart:false,menu:{template:'example.xml'}};nodes.set(c.id,{dataset:{}});return c;}};}
test('unchanged native menu registers once through row replacement',()=>{const h=harness(),c=h.container();h.ctx.syncNativeMenus([c]);h.nodes.set(c.id,{dataset:{}});h.ctx.syncNativeMenus([c]);assert.equal(h.registrations.length,1);assert.equal(h.ctx.nativeMenus.size,1);});
test('changed native state cleans only recorded action/trigger selectors',()=>{const h=harness(),c=h.container();h.ctx.syncNativeMenus([c]);h.ctx.syncNativeMenus([{...c,state:'stopped'}]);assert.equal(h.registrations.length,2);assert.deepEqual(h.removed,[['click','#event-1'],['click','#'+c.id],['contextmenu','#'+c.id]]);});
test('filtered/disappearing IDs release owned menu and delegates',()=>{const h=harness(),c=h.container();h.ctx.syncNativeMenus([c]);h.ctx.syncNativeMenus([]);assert.equal(h.ctx.nativeMenus.size,0);assert.equal(h.nodes.has('dropdown-'+c.id),false);assert.equal(h.removed.length,3);});
test('preexisting native menu is never adopted or destroyed',()=>{const h=harness(),c=h.container();const foreign={};h.nodes.set('dropdown-'+c.id,foreign);h.ctx.syncNativeMenus([c]);assert.equal(h.registrations.length,0);assert.equal(h.removed.length,0);assert.equal(h.nodes.get('dropdown-'+c.id),foreign);assert.equal(h.nodes.get(c.id).dataset.nativeFallback,'true');});

test('unknown autostart never becomes a native off state and releases prior menu',()=>{const h=harness(),c=h.container();h.ctx.syncNativeMenus([c]);h.ctx.syncNativeMenus([{...c,autostart:null}]);assert.equal(h.registrations.length,1);assert.equal(h.ctx.nativeMenus.size,0);assert.equal(h.nodes.get(c.id).dataset.nativeFallback,'true');});
