import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import test from 'node:test';
const source=fs.readFileSync(new URL('../source/usr/local/emhttp/plugins/waz.dashboard/assets/js/storage.js',import.meta.url),'utf8');
class Element {
 constructor(tag='div'){this.tagName=tag.toUpperCase();this.children=[];this.dataset={};this.attributes={};this.listeners={};this.style={setProperty:(k,v)=>{this.style[k]=v}};}
 set textContent(value){this.text=String(value);this.children=[];}
 get textContent(){return (this.text||'')+this.children.map(x=>x.textContent).join('');}
 appendChild(child){child.parentElement=this;this.children.push(child);return child;}
 setAttribute(k,v){this.attributes[k]=v;}
 addEventListener(k,fn){this.listeners[k]=fn;}
 click(){this.listeners.click?.({target:this});}
 focus(){}
}
function harness(initialStorage=null,blocked=false){
 const fields={'waz-location-groups':new Element(),'waz-location-count':new Element()},saved=new Map([['waz.dashboard.storage.enclosures.v1',initialStorage]]);
 const document={querySelector:()=>({}),getElementById:id=>fields[id],createElement:tag=>new Element(tag),activeElement:null};
 const window={localStorage:{getItem:k=>{if(blocked)throw Error();return saved.get(k)},setItem:(k,v)=>{if(blocked)throw Error();saved.set(k,v)}}};
 const cut=source.indexOf("  root.addEventListener('click', handleClick);");assert.ok(cut>0);
 vm.runInNewContext(source.slice(0,cut)+"window.fixture={render:renderSnapshot};})();",{window,document,console});
 const render=locations=>window.fixture.render({locations,pools:[],array:{configured:false},state:'normal',sampledAtMs:1});
 return {fields,saved,render};
}
function fixture(){return {groups:[{id:'front',name:'Front',columns:2,trayDirection:'v',cells:[
 {sourceTray:1,tray:0,present:true,disk:{name:'cache2',poolName:'cache',state:'normal',temperatureC:31,device:'sda'},inventory:{model:'Example model',serial:'SERIAL-A',capacityBytes:1e12}},
 {sourceTray:2,tray:1,present:false,disk:null}
 ]},{id:'rear',name:'Rear',columns:1,cells:[{sourceTray:1,tray:24,present:true,disk:null,inventory:{model:'Unused drive',serial:'SERIAL-B',capacityBytes:2e12}}]}]};}
test('bay selection opens one drawer; labels preserve zero and pool names without member suffixes',()=>{
 const h=harness();h.render(fixture());let groups=h.fields['waz-location-groups'].children;
 let bays=groups[0].children[1].children[0].children;
 assert.equal(bays[0].children[0].textContent,'0');assert.equal(bays[0].children[1].textContent,'Cache');
 assert.equal(bays[0].tagName,'BUTTON');assert.equal(bays[0].attributes['aria-pressed'],'false');
 bays[0].click();groups=h.fields['waz-location-groups'].children;
 assert.equal(groups[0].children[2].className,'waz-bay-details');assert.ok(groups[0].textContent.includes('SERIAL-A'));
 assert.equal(groups[1].children.length,2);
 groups[1].children[1].children[0].children[0].click();groups=h.fields['waz-location-groups'].children;
 assert.equal(groups[0].children.length,2);assert.ok(groups[1].textContent.includes('SERIAL-B'));
 groups[1].children[2].children[1].click();assert.equal(h.fields['waz-location-groups'].children[1].children.length,2);
});
test('enclosure collapse survives refresh and unavailable browser storage',()=>{
 for(const blocked of [false,true]){
  const h=harness(null,blocked);h.render(fixture());let front=h.fields['waz-location-groups'].children[0];
  front.open=false;front.listeners.toggle();h.render(fixture());assert.equal(h.fields['waz-location-groups'].children[0].open,false);
  h.fields['waz-location-groups'].children[0].children[1].scrollLeft=120;
  h.render(fixture());assert.equal(h.fields['waz-location-groups'].children[0].children[1].scrollLeft,120);
 }
 for(const malformed of ['"bad"','[true]','broken']){const h=harness(malformed);assert.doesNotThrow(()=>h.render(fixture()));}
});
test('empty bays show honest details, hidden bays do not count, and text is not interpreted as HTML',()=>{
 const h=harness(),data=fixture();data.groups[0].name='<img src=x>';data.groups[0].cells.push({sourceTray:3,tray:null,hidden:true});
 h.render(data);let front=h.fields['waz-location-groups'].children[0];
 assert.equal(h.fields['waz-location-count'].textContent,'2 / 3 OCCUPIED');assert.equal(front.children[0].textContent,'<img src=x> · 1/2 occupied · No reported warnings');
 front.children[1].children[0].children[1].click();front=h.fields['waz-location-groups'].children[0];
 assert.ok(front.children[2].textContent.includes('Empty bay'));assert.ok(!front.children[2].textContent.includes('SERIAL-A'));
});

test('bay click displays cached SMART fields without a network or drive request',()=>{
 const h=harness(),data=fixture();
 data.groups[0].cells[0].disk.smart={available:true,sampledAtMs:100000,health:null,attributes:{'Pending sectors':'2'}};
 h.render(data);h.fields['waz-location-groups'].children[0].children[1].children[0].children[0].click();
 const drawer=h.fields['waz-location-groups'].children[0].children[2];
 assert.ok(drawer.textContent.includes('Pending sectors2'));
 assert.ok(drawer.textContent.includes('Not included in cached report'));
 assert.ok(!drawer.textContent.includes('PASSED'));
});
