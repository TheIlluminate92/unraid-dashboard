// Source and algorithm contracts only. responsive-browser.py checks real geometry.
import test from 'node:test';
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
const M=createRequire(import.meta.url)('../source/usr/local/emhttp/plugins/rackline.dashboard/assets/js/model.js');
const base=new URL('../source/usr/local/emhttp/plugins/rackline.dashboard/',import.meta.url);
const css=readFileSync(new URL('assets/css/rackline.css',base),'utf8');
const app=readFileSync(new URL('assets/js/app.js',base),'utf8');
const native=readFileSync(new URL('native-theme-fixture.css',import.meta.url),'utf8');
test('Rackline resets native button minimum, margin and tracking inside its root only',()=>{
 assert.match(native,/min-width:86px/);assert.match(native,/margin:10px 12px 10px 0/);
 assert.match(native,/letter-spacing:1\.8px;text-transform:uppercase/);
 assert.match(css,/#rackline-root button,#rackline-root input\{[^}]*min-width:0;[^}]*margin:0;letter-spacing:normal;text-transform:none/);
 for(const [,selectors] of css.replace(/\/\*[\s\S]*?\*\//g,'').matchAll(/([^{}]+)\{/g)){
  if(selectors.trim().startsWith('@media'))continue;
  for(const selector of selectors.split(','))assert(selector.trim().startsWith('#rackline-root'),selector);
 }
});
test('CPU columns use balanced rows at phone, tablet and desktop widths',()=>{
 assert.equal(M.coreColumns(16,284,14,3),8);
 assert.equal(M.coreColumns(16,500,14,3),8); // Never the old 15 + 1 layout.
 assert.equal(M.coreColumns(32,284,14,3),8);
 assert.equal(M.coreColumns(16,284,28,3),4);
 assert.equal(M.coreColumns(16,242,28,3),2); // Doubled text remains legible.
 for(const count of [1,2,4,7,16,17,24,32,48,72,128,4096])for(const width of [160,244,284,314,354,500,800]){
  const columns=M.coreColumns(count,width,14,3),capacity=Math.floor((width+3)/(30.1+3));
  assert(columns>=1&&columns<=Math.max(1,capacity)&&columns<=count);
  assert(count%columns===0||count%columns>=columns/2);
 }
 for(const count of [undefined,null,0,-1,NaN,Infinity,2.5])assert.equal(M.coreColumns(count,300,14,3),1);
});
test('adaptive CPU layout derives width/font size without losing core data',()=>{
 let applied,width=284,fontSize='28px';const grid={getBoundingClientRect:()=>({width}),style:{setProperty:(key,value)=>applied=[key,value]}};
 const context={M,coreCount:16,$:()=>grid,window:{getComputedStyle:()=>({fontSize,columnGap:'3px'})}};
 vm.createContext(context);vm.runInContext(app.slice(app.indexOf('function layoutCores('),app.indexOf('function renderSystem(')),context);
 context.layoutCores();assert.deepEqual(applied,['--rl-core-columns',4]);
 width=500;fontSize='14px';context.layoutCores();assert.deepEqual(applied,['--rl-core-columns',8]);
 assert.match(app,/coreCount=cores\.length;layoutCores\(\)/);
 assert.match(app,/coreObserver\.disconnect\(\)/);
 assert.match(app,/removeEventListener\('resize',layoutCores\)/);
});
test('physical bays keep their source order, hidden cells and full accessible labels',()=>{
 assert.match(app,/M\.list\(group\.cells\)\.map/);
 assert.match(app,/cell\.hidden\?'rl-hidden '/);
 assert.match(app,/aria-label="'\+esc\(group\.name\+', bay '\+label\+', '\+role\)/);
 assert.match(css,/#rackline-root \.rl-bay-grid \.rl-hidden\{visibility:hidden\}/);
 assert.match(css,/\.rl-enclosure\{max-width:100%;overflow:auto;padding:6px\}/);
 assert.match(css,/\.rl-bay-grid button:focus-visible\{[^}]*outline:3px/);
});
test('copy preserves CPU basis, stale samples and unavailable unsupported GPU readings',()=>{
 const view=readFileSync(new URL('include/view.php',base),'utf8');
 assert.match(view,/100% = one logical CPU/);assert.match(view,/samples over 20 seconds are stale/);
 assert.match(app,/GPU telemetry unavailable/);
 assert.doesNotMatch(app,/GPU activity is a recent name-based process match|in this standalone build/);
 assert.match(app,/Network and disk rates are reported by Docker, not measured at physical disks/);
});

test('empty CPU topology spans its grid and duplicate header navigation is removed',()=>{
 const view=readFileSync(new URL('include/view.php',base),'utf8');
 assert.match(css,/\.rl-cores>span\{grid-column:1\/-1/);
 assert.doesNotMatch(view,/<nav aria-label="Native server pages"|id="rl-fan-link"/);
 assert.doesNotMatch(app,/\$\('rl-fan-link'\)/);
 assert.match(view,/id="rl-fan-manage"/);assert.match(view,/Return to native Unraid dashboard/);
});
