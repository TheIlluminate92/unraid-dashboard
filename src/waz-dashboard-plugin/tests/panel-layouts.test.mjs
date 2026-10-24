import fs from 'node:fs';
import assert from 'node:assert/strict';
import test from 'node:test';
const root=new URL('../source/usr/local/emhttp/plugins/waz.dashboard/',import.meta.url);
function tree(file) {
  const source=fs.readFileSync(new URL(file,root),'utf8');
  const html=source.slice(source.indexOf('<tbody'),source.indexOf('</tbody>')+8);
  const stack=[],nodes=[];
  for(const match of html.matchAll(/<\/?([a-z][a-z0-9-]*)\b([^>]*)>/gi)) {
    const tag=match[1].toLowerCase();
    if(match[0].startsWith('</')) {assert.equal(stack.pop()?.tag,tag,'Unbalanced markup in '+file+': '+match[0]);continue;}
    const attrs=Object.fromEntries([...match[2].matchAll(/([\w-]+)="([^"]*)"/g)].map(m=>[m[1],m[2]]));
    const node={tag,attrs,parent:stack.at(-1)};nodes.push(node);
    if(!['input','img','br','hr','meta','link'].includes(tag)&&!match[0].endsWith('/>'))stack.push(node);
  }
  assert.equal(stack.length,0,'All layout wrappers close');return {source,nodes};
}
for(const [file,expected] of [
 ['WazDashboard.page',{summary:['cpu','gpu','power','memory','coolant','hba0','network','flow','hba1'],sections:['cpu','memory','network']}],
 ['WazWorkloads.page',{summary:['running','vdisk','updates','gpu'],sections:['folders','containers','processes','inspector']}],
 ['WazStorage.page',{sections:['locations','parity','pools','pooldetails','array']}]
])test(file+' declares independent layout blocks without breaking its markup',()=>{
 const {source,nodes}=tree(file);
 for(const [group,ids] of Object.entries(expected)) {
  const container=nodes.find(n=>n.attrs['data-layout-group']===group);assert.ok(container);
  assert.deepEqual(nodes.filter(n=>n.parent===container&&n.attrs['data-layout-item']).map(n=>n.attrs['data-layout-item']),ids);
 }
 assert.ok(source.includes('assets/css/layout.css'));assert.equal((source.match(/assets\/js\/layout\.js/g)||[]).length,1);
 const allIds=nodes.map(n=>n.attrs.id).filter(Boolean);assert.equal(new Set(allIds).size,allIds.length,'Native control IDs stay unique');
 const alert=nodes.find(n=>n.attrs.id==='waz-workloads-alert'||n.attrs.id==='waz-storage-alert');
 for(let parent=alert?.parent;parent;parent=parent.parent)assert.notEqual(parent.tag,'details','Health alerts must stay outside collapsed details');
});
