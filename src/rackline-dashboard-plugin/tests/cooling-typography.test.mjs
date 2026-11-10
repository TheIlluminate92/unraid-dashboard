// Cooling readability contracts. Rendered geometry belongs to browser QA.
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
const css=fs.readFileSync(new URL('../source/usr/local/emhttp/plugins/rackline.dashboard/assets/css/rackline.css',import.meta.url),'utf8');
const section=css.slice(css.indexOf('/* Cooling cards have room'));
test('larger cooling typography is isolated from other workspaces and native controls',()=>{
 assert(section.length>0);
 for(const [,selectors] of section.replace(/\/\*[\s\S]*?\*\//g,'').matchAll(/([^{}]+)\{/g)){
  for(const selector of selectors.split(','))assert(selector.trim().startsWith('#rackline-root #rl-cooling '),selector);
 }
 assert.match(section,/h3\{font-size:20px;line-height:1\.35/);
 assert.match(section,/dl>div\{[^}]*font-size:15px;line-height:1\.5/);
 assert.match(section,/dd\{font-size:17px/);
 assert.match(section,/\.rl-note,[^}]*font-size:14px/);
 assert.match(section,/\.rl-button\{font-size:14px;min-height:44px/);
});
test('cooling cards and two-column readings can reflow without smaller phone text',()=>{
 assert.match(section,/minmax\(min\(100%,20em\),1fr\)/);
 assert.match(section,/grid-template-columns:minmax\(0,1fr\) minmax\(0,1\.25fr\)/);
 assert.match(section,/dt,[^{]+dd\{min-width:0;overflow-wrap:anywhere;white-space:normal/);
 assert.doesNotMatch(section,/white-space:nowrap|overflow:hidden|[;{]height:/);
 assert.doesNotMatch(section,/@media/,'Phone typography must not be reduced below the base readable sizes');
});
