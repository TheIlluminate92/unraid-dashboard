// Deterministic CSS/token contracts, not browser paint or assistive-technology acceptance.
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {createRequire} from 'node:module';
const base=new URL('../source/usr/local/emhttp/plugins/rackline.dashboard/',import.meta.url);
const css=fs.readFileSync(new URL('assets/css/rackline.css',base),'utf8');
const app=fs.readFileSync(new URL('assets/js/app.js',base),'utf8');
const M=createRequire(import.meta.url)('../source/usr/local/emhttp/plugins/rackline.dashboard/assets/js/model.js');
const tokens=Object.fromEntries([...css.matchAll(/(--rl-[a-z-]+):(#[0-9a-f]{6})(?=[;}])/gi)].map(m=>[m[1],m[2].toUpperCase()]));
function luminance(hex){const rgb=hex.slice(1).match(/../g).map(x=>parseInt(x,16)/255).map(x=>x<=.04045?x/12.92:((x+.055)/1.055)**2.4);return rgb[0]*.2126+rgb[1]*.7152+rgb[2]*.0722;}
function contrast(a,b){const [lo,hi]=[luminance(a),luminance(b)].sort((a,b)=>a-b);return (hi+.05)/(lo+.05);}
function atLeast(fg,bg,minimum){const ratio=contrast(tokens[fg]||fg,tokens[bg]||bg);assert(ratio>=minimum,`${fg} on ${bg}: ${ratio.toFixed(3)} < ${minimum}`);return ratio;}
test('Ember tokens have one root-scoped authoritative definition',()=>{
 const expected={'--rl-bg':'#0C1014','--rl-panel':'#151B21','--rl-raised':'#1D2630','--rl-text':'#EAF0F6','--rl-muted':'#9AAABA','--rl-accent':'#FF8A3D','--rl-on-accent':'#11151A','--rl-border':'#33404E','--rl-control-border':'#647486','--rl-healthy':'#5DD6A2','--rl-warning':'#F6C85F','--rl-fault':'#FF6E7C','--rl-info':'#6CB9FF','--rl-cpu-fill':'#7A492D'};
 assert.deepEqual(tokens,expected);
 const root=css.match(/#rackline-root\{([^}]+)\}/)[1];
 for(const key of Object.keys(expected)){assert(root.includes(key+':'));assert.equal(css.split(key+':').length-1,1,key);}
 assert.doesNotMatch(css,/:root\s*\{/);
});
test('text, state and focus colors meet contrast on every Ember surface',()=>{
 for(const bg of ['--rl-bg','--rl-panel','--rl-raised']){
  for(const fg of ['--rl-text','--rl-muted','--rl-accent','--rl-healthy','--rl-warning','--rl-fault','--rl-info'])atLeast(fg,bg,4.5);
  atLeast('--rl-control-border',bg,3);
 }
 atLeast('--rl-on-accent','--rl-accent',4.5);
 assert.match(css,/\.rl-primary,#rackline-root \.rl-primary:hover\{background:var\(--rl-accent\);border-color:var\(--rl-accent\);color:var\(--rl-on-accent\)\}/);
 assert.match(css,/:focus-visible\{outline:3px solid var\(--rl-info\)/);
});
test('CPU overlay remains readable at every fill percentage without composited opacity',()=>{
 const ratio=atLeast('--rl-text','--rl-cpu-fill',4.5);assert(ratio>6.4);
 atLeast('--rl-text','--rl-raised',4.5);
 const fill=css.match(/#rackline-root \.rl-core i\{([^}]+)\}/)[1];assert.match(fill,/background:var\(--rl-cpu-fill\)/);assert.doesNotMatch(fill,/opacity|filter/);
 assert.match(css,/\.rl-core span,#rackline-root \.rl-core b\{[^}]*color:var\(--rl-text\)/);
});
test('stale states preserve card readability and semantic text rather than whole-card dimming',()=>{
 const stale=css.match(/#rackline-root\.rl-system-stale \.rl-pulse article:nth-child\(-n\+3\)\{([^}]+)\}/)[1];assert.equal(stale,'border-color:var(--rl-warning)');
 for(const rule of css.matchAll(/([^{}]+)\{([^{}]*opacity[^{}]*)\}/g))assert.doesNotMatch(rule[1],/article|rl-pulse|rl-inspector|rl-box|rl-scroll/);
 for(const [selector,token]of [['rl-normal,#rackline-root .rl-running','healthy'],['rl-attention,#rackline-root .rl-paused','warning'],['rl-fault,#rackline-root .rl-unhealthy','fault']])assert(css.includes(`.${selector}{color:var(--rl-${token});border-color:var(--rl-${token});background:var(--rl-panel)}`));
 assert.match(app,/function stateBadge\(value\)[\s\S]*?esc\(value\|\|'Unknown'\)/);
});
test('native bay backgrounds and readable foregrounds stay independent from Ember',()=>{
 assert.match(css,/\.rl-bay-grid button\.rl-native-color,#rackline-root \.rl-bay-grid button\.rl-native-color:hover\{background:var\(--rl-bay-color\);color:var\(--rl-bay-text\)\}/);
 assert.match(css,/\.rl-bay-grid button:disabled\{opacity:1\}/);
 for(const rule of css.matchAll(/([^{}]*\.rl-bay-grid[^{}]*)\{([^{}]*)\}/g))assert.doesNotMatch(rule[2],/background:var\(--rl-(?:accent|healthy|warning|fault|bg|panel|raised)\)/);
 for(let rgb=0;rgb<=0xffffff;rgb+=4093){const bg='#'+rgb.toString(16).padStart(6,'0');atLeast(M.bayTextColor(bg),bg,4.5);}
});
test('theme keeps responsive geometry, reduced motion and touch/focus affordances',()=>{
 for(const width of [700,1000,1800])assert(css.includes(`${width}px`));
 assert.match(css,/@media\(prefers-reduced-motion:reduce\)/);
 assert.match(css,/\.rl-filters button,#rackline-root td button,#rackline-root \.rl-button\{min-height:44px\}/);
 assert.match(css,/\.rl-native-layout \.rl-bay-grid button\{height:var\(--rl-tray-height\);min-height:44px\}/);
 assert.match(css,/\.rl-bay-grid button:focus-visible\{[^}]*outline:3px/);
 assert.match(css,/\.rl-bay-grid \.rl-selected\{outline:2px solid var\(--rl-accent\)/);
});
