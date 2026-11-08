#!/usr/bin/env python3
"""Offline Chromium geometry/keyboard QA. Synthetic data; never contacts a host.

Requires Playwright and a permitted local Chromium. Unlike source tests, browser
startup failure is a failure, not a skip or a claim of rendered acceptance.
"""
import argparse
import json
from pathlib import Path
import re
import subprocess
import sys
import tempfile
from playwright.sync_api import sync_playwright

HERE = Path(__file__).resolve().parent
parser = argparse.ArgumentParser()
parser.add_argument('--browser', required=True, help='Installed Chrome/Chromium executable')
parser.add_argument('--output', type=Path, required=True)
args = parser.parse_args()
args.output.mkdir(parents=True, exist_ok=True)
results = []


def document(directory, threads):
    subprocess.run([sys.executable, str(HERE / 'render-fixture.py'), str(directory), '--native-css', '--threads', str(threads)], check=True, capture_output=True)
    html = (directory / 'review.html').read_text()
    # Inline only our generated local fixture assets. No HTTP server or live URL.
    html = re.sub(r'<link rel="stylesheet" href="([^"]+)">', lambda m: '<style>' + (directory / m[1].split('?')[0].lstrip('/')).read_text() + '</style>', html)
    html = re.sub(r'<script src="([^"]+)"></script>', lambda m: '<script>' + (directory / m[1].split('?')[0].lstrip('/')).read_text() + '</script>', html)
    return html.replace('Example server','Synthetic-server-with-a-long-hostname-for-layout-testing').replace('owner-managed-demo','12345678-1234-4000-8000-123456789abc-owner-managed-demo').replace("disk('disk1'","disk('synthetic-long-drive-label'")


GEOMETRY = r'''() => {
 const root=document.querySelector('#rackline-root');
 const box=e=>{const r=e.getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height,right:r.right,bottom:r.bottom};};
 const cores=[...root.querySelectorAll('.rl-core')];
 const bays=[...root.querySelectorAll('.rl-bay-grid button')].filter(e=>e.getClientRects().length);
 const visible=bays.filter(e=>getComputedStyle(e).visibility!=='hidden');
 const overlap=[];
 visible.forEach((a,i)=>visible.slice(i+1).forEach(b=>{
   const x=box(a),y=box(b);if(Math.min(x.right,y.right)-Math.max(x.x,y.x)>.5&&Math.min(x.bottom,y.bottom)-Math.max(x.y,y.y)>.5)overlap.push([a.textContent,b.textContent]);
 }));
 const rows={};cores.forEach(e=>{const top=Math.round(box(e).y);rows[top]=(rows[top]||0)+1;});
 const allControls=[...root.querySelectorAll('.rl-tabs button,.rl-filters button,.rl-bay-grid button,td button,.rl-button')].filter(e=>e.getClientRects().length&&getComputedStyle(e).visibility!=='hidden');
 return {
  viewport:innerWidth,documentWidth:document.documentElement.scrollWidth,root:box(root),
  cpu:cores.map(e=>({id:e.querySelector('span').textContent,...box(e),clipped:[...e.querySelectorAll('span,b')].some(n=>n.scrollWidth>n.clientWidth+1)})),
  rows:Object.values(rows),overlap,
  bays:bays.map(e=>({label:e.querySelector('b').textContent,hidden:getComputedStyle(e).visibility==='hidden',...box(e)})),
  controls:allControls.map(e=>({label:e.textContent.trim(),...box(e)})),
  buttonStyle:visible.length?['minWidth','marginTop','marginRight','letterSpacing','textTransform'].map(k=>[k,getComputedStyle(visible[0])[k]]):[],
  tables:[...root.querySelectorAll('.rl-scroll')].filter(e=>e.getClientRects().length).map(e=>({client:e.clientWidth,scroll:e.scrollWidth,...box(e)})),
  enclosures:[...root.querySelectorAll('.rl-enclosure')].filter(e=>e.getClientRects().length).map(e=>({client:e.clientWidth,scroll:e.scrollWidth,...box(e)})),
  pulsePosition:getComputedStyle(root.querySelector('.rl-pulse')).position,
  tabTop:root.querySelector('.rl-tabs').getBoundingClientRect().top+scrollY
 };
}'''


def verify(page, threads, phone=False):
    data = page.evaluate(GEOMETRY)
    assert data['documentWidth'] <= data['viewport'] + 1, ('page overflow', data)
    assert len(data['cpu']) == threads
    assert [int(c['id']) for c in data['cpu']] == list(range(threads))
    assert all(c['width'] > 0 and c['height'] > 0 and not c['clipped'] for c in data['cpu']), ('clipped CPU label', data)
    # Balanced rows must not strand a short final row, including doubled text.
    assert min(data['rows']) >= max(data['rows']) / 2, ('stranded CPU row', data['rows'])
    if data['bays']:
        assert not data['overlap'], data['overlap']
        assert [b['label'] for b in data['bays']] == ['0','2','4','8','9','1','3','5','6','7']
        assert [i for i,b in enumerate(data['bays']) if b['hidden']] == [3,4]
        assert data['bays'][0]['y'] == data['bays'][4]['y']
        assert data['bays'][5]['y'] > data['bays'][0]['y']
        style = dict(data['buttonStyle'])
        assert style == dict(minWidth='0px', marginTop='0px', marginRight='0px', letterSpacing='normal', textTransform='none'), style
    for area in data['tables'] + data['enclosures']:
        assert area['right'] <= data['root']['right'] + 1
    assert data['pulsePosition'] == ('sticky' if data['viewport']>1000 else 'static')
    if phone:
        assert all(c['height'] >= 44 and c['width'] >= 44 for c in data['controls']), ('small touch target', data['controls'])
    return data


def settle_layout(page):
    page.evaluate("() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))")


def verify_focus_visible(page, target):
    """Check the interactive viewport before presentation scroll normalization.

    Wide table rows may be clipped horizontally by their local scroller. Test
    their visible segment, but require the whole focus target's height to be
    visible and hit-test its top, middle and bottom against sticky overlays.
    """
    settle_layout(page)
    state = target.evaluate(r'''e => {
      const r=e.getBoundingClientRect();
      let left=Math.max(0,r.left),right=Math.min(innerWidth,r.right);
      let top=Math.max(0,r.top),bottom=Math.min(innerHeight,r.bottom);
      for(let parent=e.parentElement;parent;parent=parent.parentElement){
        const style=getComputedStyle(parent),box=parent.getBoundingClientRect();
        if(/^(auto|scroll|hidden|clip)$/.test(style.overflowX)){
          left=Math.max(left,box.left+parent.clientLeft);
          right=Math.min(right,box.left+parent.clientLeft+parent.clientWidth);
        }
        if(/^(auto|scroll|hidden|clip)$/.test(style.overflowY)){
          top=Math.max(top,box.top+parent.clientTop);
          bottom=Math.min(bottom,box.top+parent.clientTop+parent.clientHeight);
        }
      }
      const width=right-left,height=bottom-top,x=(left+right)/2;
      const points=width>0&&height>0?[top+Math.min(4,height/2),(top+bottom)/2,bottom-Math.min(4,height/2)]:[];
      const hits=points.map(y=>{const hit=document.elementFromPoint(x,y);return {
        x,y,inside:!!hit&&(hit===e||e.contains(hit)),hit:hit?hit.tagName+'#'+hit.id+'.'+hit.className:null
      };});
      return {active:e===document.activeElement,id:e.id,label:e.textContent.trim(),
        targetHeight:r.height,visibleWidth:width,visibleHeight:height,viewportHeight:innerHeight,
        visible:getComputedStyle(e).visibility==='visible',scrollY,hits};
    }''')
    assert state['active'] and state['visible'], ('focus lost or hidden',state)
    assert state['visibleWidth']>0 and state['visibleHeight']+1>=min(state['targetHeight'],state['viewportHeight']), ('focus clipped by viewport or scroller',state)
    assert state['hits'] and all(hit['inside'] for hit in state['hits']), ('focused control obscured',state)
    return state


def capture_overview(page, path):
    """Normalize only presentation captures, after interaction assertions pass."""
    page.evaluate("""() => {
      document.querySelectorAll('#rackline-root .rl-scroll,#rackline-root .rl-enclosure,#rackline-root .rl-filters,#rackline-root .rl-tabs').forEach(e=>e.scrollLeft=0);
      window.scrollTo(0,0);
    }""")
    settle_layout(page)
    assert page.evaluate("() => scrollX===0&&scrollY===0&&[...document.querySelectorAll('#rackline-root .rl-scroll,#rackline-root .rl-enclosure,#rackline-root .rl-filters,#rackline-root .rl-tabs')].every(e=>e.scrollLeft===0)")
    page.screenshot(path=str(path),full_page=True)


with tempfile.TemporaryDirectory(prefix='rackline-responsive-') as temp, sync_playwright() as p:
    fixture_dir = Path(temp)
    browser = p.chromium.launch(executable_path=args.browser, headless=True, args=['--no-sandbox'])
    cases = [(str(width), width, 16, 1, False) for width in [320,360,390,430,768,1024,1440]]
    cases += [(f'{width}-text-200', width, 16, 1, True) for width in [320,390]]
    # Browser zoom reflow emulation: halve CSS viewport and double device scale.
    cases += [('desktop-200-percent-reflow',720,16,2,False)]
    cases += [(f'{width}-{threads}-cpus',width,threads,1,False) for width in [390,1440] for threads in [32,72]]
    try:
        for name,width,threads,scale,text_scale in cases:
            context = browser.new_context(viewport={'width':width,'height':900}, device_scale_factor=scale)
            page = context.new_page()
            errors=[]
            focus_checks=[]
            page.on('pageerror',lambda error: errors.append(str(error)))
            # Any image load receives a synthetic icon. All other network is denied.
            page.route('**/*',lambda route: route.fulfill(content_type='image/svg+xml',body='<svg xmlns="http://www.w3.org/2000/svg"/>') if route.request.resource_type=='image' else route.abort())
            try:
                page.set_content(document(fixture_dir,threads))
                page.wait_for_function("document.querySelectorAll('.rl-core').length === " + str(threads))
                page.wait_for_function("document.querySelector('#rl-cpu').textContent !== '—'",timeout=5000)
                # Native declarations are genuinely active outside Rackline.
                page.evaluate("() => {const b=document.createElement('button');b.id='native-probe';b.textContent='Native';document.body.append(b);}")
                assert page.locator('#native-probe').evaluate("e=>getComputedStyle(e).minWidth") == '86px'
                page.locator('#native-probe').evaluate('e=>e.remove()')
                if text_scale:
                    page.evaluate("""() => {const nodes=[document.querySelector('#rackline-root'),...document.querySelectorAll('#rackline-root *')];const sizes=nodes.map(e=>parseFloat(getComputedStyle(e).fontSize));nodes.forEach((e,i)=>e.style.fontSize=(sizes[i]*2)+'px');} """)
                    page.set_viewport_size({'width':width+1,'height':900})
                    page.set_viewport_size({'width':width,'height':900})
                page.wait_for_timeout(100)
                data=verify(page,threads,width<=700)
                if not text_scale and width<=700 and threads==16:
                    assert data['tabTop'] < 900, ('mobile strip too tall', data['tabTop'])
                capture_overview(page,args.output / f'rackline-synthetic-{name}-drives.png')
                # Focus and select a mapped bay, retaining position and identity.
                bay=page.locator('.rl-bay-grid button[data-drive]').first
                bay.focus();page.keyboard.press('Enter')
                assert 'rl-selected' in bay.get_attribute('class')
                assert bay.evaluate('e=>e===document.activeElement')
                assert bay.evaluate("e=>getComputedStyle(e).outlineWidth") == '3px'
                focus_checks.append(verify_focus_visible(page,bay))
                # Arrow-key navigation, repeated selection, and browser history.
                page.locator('#rl-tab-drives').focus();page.keyboard.press('ArrowRight')
                assert page.locator('#rl-tab-docker').get_attribute('aria-selected')=='true'
                assert page.locator('#rl-tab-docker').evaluate('e=>e===document.activeElement')
                focus_checks.append(verify_focus_visible(page,page.locator('#rl-tab-docker')))
                verify(page,threads,width<=700)
                controls=page.locator('#rl-docker button[data-native-menu]').first
                controls.click();assert page.locator('#qa-menu').is_visible()
                page.locator('#qa-close').click();assert page.locator('#qa-menu').count()==0
                owner=page.locator('tr[data-container$="owner-managed-demo"]')
                owner.focus();page.keyboard.press('Enter')
                focus_checks.append(verify_focus_visible(page,owner))
                assert page.locator('#rl-container-inspector').inner_text().find('No verified Unraid template')>=0
                assert 'Collecting CPU history' in page.locator('#rl-container-inspector').inner_text() or page.locator('#rl-container-inspector .rl-trend').count()==1
                if width>1000:
                    # Put the tabs beneath the sticky strip, then request focus.
                    # Do not normalize scrolling before checking the real result.
                    page.evaluate("() => {const tabs=document.querySelector('.rl-tabs');window.scrollTo(0,tabs.getBoundingClientRect().top+scrollY-80);}")
                    settle_layout(page)
                    page.locator('#rl-tab-docker').focus()
                    focus_checks.append(verify_focus_visible(page,page.locator('#rl-tab-docker')))
                capture_overview(page,args.output / f'rackline-synthetic-{name}-docker.png')
                page.locator('#rl-tab-docker').focus();page.keyboard.press('ArrowRight')
                assert page.locator('#rl-tab-cooling').get_attribute('aria-selected')=='true'
                focus_checks.append(verify_focus_visible(page,page.locator('#rl-tab-cooling')))
                verify(page,threads,width<=700)
                capture_overview(page,args.output / f'rackline-synthetic-{name}-cooling.png')
                page.go_back();page.wait_for_function("document.querySelector('#rl-tab-docker').getAttribute('aria-selected')==='true'")
                page.go_forward();page.wait_for_function("document.querySelector('#rl-tab-cooling').getAttribute('aria-selected')==='true'")
                assert not errors, errors
                results.append({'case':name,'width':width,'threads':threads,'text_scale':2 if text_scale else 1,'geometry':data,'focus_checks':focus_checks,'passed':True})
                print('PASS',name)
            except Exception:
                # Preserve the failing viewport before a full-page capture can
                # make a sticky overlay look like a permanent layout gap.
                page.screenshot(path=str(args.output / f'FAILED-viewport-{name}.png'),full_page=False)
                page.screenshot(path=str(args.output / f'FAILED-synthetic-{name}.png'),full_page=True)
                raise
            finally:
                (args.output / 'geometry-results.json').write_text(json.dumps(results,indent=2))
                context.close()
    finally:
        browser.close()
print(f'{len(results)} synthetic browser cases passed; no installed-host acceptance claimed.')
