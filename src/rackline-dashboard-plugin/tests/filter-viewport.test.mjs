// Source functions in a synthetic, scroll-clamping DOM. Not rendered browser QA.
import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import fs from 'node:fs';
const app=fs.readFileSync(new URL('../source/usr/local/emhttp/plugins/rackline.dashboard/assets/js/app.js',import.meta.url),'utf8');
const fn=(a,b)=>app.slice(app.indexOf('function '+a+'('),app.indexOf('function '+b+'('));
function harness({height=900,natural=3500,y=900,barTop=1000}={}){
 let serial=0,x=0,naturalHeight=natural,anchorTop=barTop,spacer=null,focuses=0;const frames=new Map(),moves=[],classes=new Set();
 const reserve=()=>spacer?parseFloat(spacer.style.height)||0:0;
 const fullHeight=()=>Math.max(context.window.innerHeight,naturalHeight+reserve());
 const clamp=()=>{y=Math.max(0,Math.min(y,fullHeight()-context.window.innerHeight));return y;};
 const panel={classList:{add:c=>classes.add(c),remove:c=>classes.delete(c)},appendChild(e){spacer=e;e.parentNode=this;},removeChild(e){assert.equal(e,spacer);e.parentNode=null;spacer=null;}};
 const bar={scrollLeft:61,getBoundingClientRect:()=>({top:anchorTop-clamp()}),focus(){focuses++;}};
 const context={filterFrame:null,filterReserve:null,stopped:false,$:id=>id==='panel'?panel:bar,document:{documentElement:{get scrollHeight(){return fullHeight();}},createElement(){return{style:{height:'0px'},setAttribute(){},getBoundingClientRect:()=>({height:reserve()})};}},window:{innerHeight:height,get scrollX(){return x;},get scrollY(){return clamp();},scrollTo(options){x=options.left;y=options.top;clamp();moves.push({...options});},requestAnimationFrame(callback){frames.set(++serial,callback);return serial;},cancelAnimationFrame:id=>frames.delete(id)}};
 vm.createContext(context);vm.runInContext(fn('cancelFilterRestore','onClick'),context);
 return{context,bar,frames,moves,classes,get reserve(){return reserve();},get y(){return clamp();},get focusCount(){return focuses;},change(value,top=anchorTop){context.changeFilter('panel','bar',()=>{naturalHeight=value;anchorTop=top;bar.scrollLeft=0;});},userScroll(value){y=value;context.onFilterScroll();},resize(value){context.window.innerHeight=value;context.clearFilterViewport();},flush(){for(const [id,callback]of [...frames]){frames.delete(id);callback();}},setNatural(value){naturalHeight=value;},setY(value){y=value;}};
}
test('tall-to-short filtering reserves only the minimum needed and keeps viewport/local filter position',()=>{
 const h=harness();h.change(1100);assert.equal(h.y,900);assert.equal(h.reserve,700);assert.equal(h.bar.scrollLeft,61);assert(h.classes.has('rl-filter-changing'));h.flush();assert.equal(h.y,900);assert.equal(h.reserve,700);assert.equal(h.frames.size,0);assert(!h.classes.size);assert.equal(h.focusCount,0);assert(h.moves.every(m=>m.behavior==='instant'&&m.left===0));
});
test('changed content above the filters preserves their viewport anchor',()=>{
 const h=harness();const before=h.bar.getBoundingClientRect().top;h.change(1150,1050);h.flush();assert.equal(h.y,950);assert.equal(h.bar.getBoundingClientRect().top,before);assert.equal(h.reserve,700);
});
test('reserve shrinks without clamping upward at the bottom and disappears once unnecessary',()=>{
 const h=harness();h.change(1100);h.flush();h.userScroll(600);assert.equal(h.reserve,400);assert.equal(h.y,600);h.userScroll(200);assert.equal(h.reserve,0);assert.equal(h.y,200);assert.equal(h.context.filterReserve,null);
});
test('repeated tall, empty and short switches never remember the high-water list height',()=>{
 const h=harness();for(let i=0;i<100;i++){h.change(1100);h.flush();assert.equal(h.reserve,700);h.change(9000);h.flush();assert.equal(h.reserve,0);assert.equal(h.context.filterReserve,null);assert.equal(h.y,900);}assert.equal(h.focusCount,0);
});
test('user input or a changed scroll position cancels the deferred restore without rubber-banding',()=>{
 for(const kind of ['input','scroll','focus-scroll']){const h=harness();h.change(1100);const moves=h.moves.length;
  if(kind==='input'){h.context.onFilterUserInput();h.setY(500);}else if(kind==='scroll')h.userScroll(500);else h.setY(500);
  h.flush();assert.equal(h.moves.length,moves,kind);assert.equal(h.y,500,kind);assert.equal(h.frames.size,0);assert(!h.classes.size);}
});
test('resize, manual workspace navigation, exception and teardown remove reservation/frames',()=>{
 for(const kind of ['resize','navigation','destroy','exception']){const h=harness();h.change(1100);
  if(kind==='resize')h.resize(500);else if(kind==='destroy'){h.context.stopped=true;h.context.clearFilterViewport();}else if(kind==='exception')assert.throws(()=>h.context.changeFilter('panel','bar',()=>{throw Error('fixture');}),/fixture/);else h.context.clearFilterViewport();
  const before=h.moves.length;h.flush();assert.equal(h.moves.length,before);assert.equal(h.frames.size,0);assert.equal(h.reserve,0);assert.equal(h.context.filterReserve,null);assert(!h.classes.size);}
 assert.match(fn('setTab','layoutPulse'),/clearFilterViewport\(\)/);assert.match(app,/removeEventListener\('resize',clearFilterViewport\)/);
});
test('small viewports and impossible offscreen anchors keep the reserve bounded and never steal focus',()=>{
 for(const height of [240,480,900]){const h=harness({height,y:3000});h.change(1100);h.flush();assert(h.reserve<=height);assert.equal(h.focusCount,0);}
});
test('filter changes do not require browser-only primitives in a missing capability environment',()=>{
 const h=harness();delete h.context.document.documentElement;let called=0;h.context.changeFilter('panel','bar',()=>called++);assert.equal(called,1);assert.equal(h.moves.length,0);assert.equal(h.frames.size,0);
});
test('telemetry focus restoration uses preventScroll on the same matched control only',()=>{
 const calls=[],old={id:'filter',dataset:{}},target={focus:options=>calls.push(options)};
 const element={contains:node=>node===old,innerHTML:'',querySelectorAll:()=>[]},context={document:{activeElement:old},$:()=>target};vm.createContext(context);vm.runInContext(fn('stableMarkup','text'),context);context.stableMarkup(element,'new filter markup');assert.equal(calls.length,1);assert.equal(calls[0].preventScroll,true);context.stableMarkup(element,'new filter markup');assert.equal(calls.length,1);
});

test('short content at document top never retains a viewport-filling spacer',()=>{
 const h=harness({height:900,natural:3500,y:0,barTop:100});h.change(500);h.flush();assert.equal(h.reserve,0);assert.equal(h.context.filterReserve,null);assert.equal(h.y,0);h.userScroll(0);assert.equal(h.reserve,0);
 const scrolled=harness();scrolled.change(1100);scrolled.flush();scrolled.userScroll(0);assert.equal(scrolled.reserve,0);assert.equal(scrolled.y,0);
});
