#!/usr/bin/env python3
"""Offline Chromium geometry/keyboard QA. Synthetic data; never contacts a host.

Requires Playwright and a permitted local Chromium. Unlike source tests, browser
startup failure is a failure, not a skip or a claim of rendered acceptance.
"""
import argparse
import json
import os
import shutil
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


def document(directory, threads, smt=2, fan=True, filter_stress=False, smart_test=False):
    subprocess.run([sys.executable, str(HERE / 'render-fixture.py'), str(directory), '--native-css', '--threads', str(threads), '--smt', str(smt)], check=True, capture_output=True)
    html = (directory / 'review.html').read_text().replace('"fanAvailable":true','"fanAvailable":'+json.dumps(fan))
    if filter_stress:
        html = html.replace('RacklineFixture={','RacklineFixture={filterStress:true,')
    if smart_test:
        html = html.replace('RacklineFixture={','RacklineFixture={smartTest:true,')
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
 const sockets=[...root.querySelectorAll('.rl-cpu-socket')].map(socket=>{
  const groups=[...socket.querySelectorAll('.rl-physical-core')],rows={};groups.forEach(e=>{const top=Math.round(box(e).y);rows[top]=(rows[top]||0)+1;});
  return {id:socket.dataset.socket,rows:Object.values(rows),...box(socket),groups:groups.map(group=>({id:group.dataset.core,...box(group),cpus:[...group.querySelectorAll('.rl-core')].map(e=>({id:Number(e.querySelector('span').textContent),...box(e)}))}))};
 });
 const allControls=[...root.querySelectorAll('.rl-tabs button,.rl-filters button,.rl-bay-grid button,td button,td a,.rl-button,.rl-footer a,.rl-docker-actions button,.rl-docker-actions a,.rl-smart-actions button')].filter(e=>e.getClientRects().length&&getComputedStyle(e).visibility!=='hidden');
 return {
  viewport:innerWidth,viewportHeight:innerHeight,documentWidth:document.documentElement.scrollWidth,root:box(root),
  cpu:cores.map(e=>({id:e.querySelector('span').textContent,...box(e),clipped:[...e.querySelectorAll('span,b')].some(n=>n.scrollWidth>n.clientWidth+1)})),
  sockets,overlap,
  bays:bays.map(e=>({label:e.querySelector('b').textContent,hidden:getComputedStyle(e).visibility==='hidden',...box(e)})),
  controls:allControls.map(e=>({label:e.textContent.trim(),...box(e)})),
  buttonStyle:visible.length?['minWidth','marginTop','marginRight','letterSpacing','textTransform'].map(k=>[k,getComputedStyle(visible[0])[k]]):[],
  tables:[...root.querySelectorAll('.rl-scroll')].filter(e=>e.getClientRects().length).map(e=>({client:e.clientWidth,scroll:e.scrollWidth,...box(e)})),
  enclosures:[...root.querySelectorAll('.rl-enclosure')].filter(e=>e.getClientRects().length).map(e=>({client:e.clientWidth,scroll:e.scrollWidth,...box(e)})),
  tableRows:[...root.querySelectorAll('tr[data-drive],tr[data-container]')].filter(e=>e.getClientRects().length).map(e=>({height:box(e).height,paddingTop:getComputedStyle(e.querySelector('td')).paddingTop,paddingBottom:getComputedStyle(e.querySelector('td')).paddingBottom,fontSize:getComputedStyle(e.querySelector('td')).fontSize})),
  pulsePosition:getComputedStyle(root.querySelector('.rl-pulse')).position,pulseHeight:box(root.querySelector('.rl-pulse')).height,pulseTop:parseFloat(getComputedStyle(root.querySelector('.rl-pulse')).top)||0,
  tabTop:root.querySelector('.rl-tabs').getBoundingClientRect().top+scrollY
 };
}'''


def verify(page, threads, phone=False, smt=2):
    data = page.evaluate(GEOMETRY)
    assert data['documentWidth'] <= data['viewport'] + 1, ('page overflow', data)
    assert len(data['cpu']) == threads
    assert sorted(int(c['id']) for c in data['cpu']) == list(range(threads))
    assert all(c['width'] > 0 and c['height'] > 0 and not c['clipped'] for c in data['cpu']), ('clipped CPU label', data)
    # Synthetic adjacent SMT pairs belong to the same physical core. Balance
    # physical groups within each socket, never split siblings across groups.
    socket_count = 2 if threads >= 32 else 1
    cores_per_socket = threads // smt // socket_count
    assert [s['id'] for s in data['sockets']] == [str(i) for i in range(socket_count)]
    for socket_index, socket in enumerate(data['sockets']):
        assert len(socket['groups']) == cores_per_socket
        assert min(socket['rows']) >= max(socket['rows']) / 2, ('stranded physical core group',socket)
        for core_index, group in enumerate(socket['groups']):
            assert group['id'] == str(core_index)
            first = (socket_index * cores_per_socket + core_index) * smt
            assert [c['id'] for c in group['cpus']] == list(range(first,first+smt))
            assert group['x'] >= socket['x'] - 1 and group['right'] <= socket['right'] + 1
            for cpu in group['cpus']:
                assert cpu['x'] >= group['x'] and cpu['right'] <= group['right'] + 1
                assert cpu['y'] >= group['y'] and cpu['bottom'] <= group['bottom'] + 1
            for i,a in enumerate(group['cpus']):
                for b in group['cpus'][i+1:]:
                    assert min(a['right'],b['right']) - max(a['x'],b['x']) <= .5 or min(a['bottom'],b['bottom']) - max(a['y'],b['y']) <= .5

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
    assert data['pulsePosition'] == ('sticky' if data['viewport']>1000 and data['pulseHeight']+max(0,data['pulseTop'])<=data['viewportHeight']-240 else 'static')
    assert not page.locator('#rl-feed-status').is_visible(), 'Healthy feeds must not show routine update timers'
    assert not page.locator('#rl-availability').is_visible(), 'Healthy availability banner must not leave boilerplate'
    assert page.locator('#rl-availability').evaluate('e=>e.getBoundingClientRect().height')==0
    if phone:
        assert all(r['height']>=44 and r['paddingTop']=='6px' and r['paddingBottom']=='6px' and float(r['fontSize'].removesuffix('px'))>=12 for r in data['tableRows']), ('mobile table density or readability',data['tableRows'])
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


def check_filter_switches(page):
    checks=[]
    for panel,bar_id,attribute,short_ids in [('drives','rl-pools','pool',['Cache','Parity','all','Cache']),('docker','rl-folders','folder',['short','empty','all','short'])]:
        page.locator('#rl-tab-'+panel).click()
        bar=page.locator('#'+bar_id)
        page.evaluate("""id => {const bar=document.getElementById(id),pulse=document.querySelector('.rl-pulse');const cover=getComputedStyle(pulse).position==='sticky'?pulse.getBoundingClientRect().height+20:140;window.scrollTo(0,bar.getBoundingClientRect().top+scrollY-cover);} """,bar_id)
        settle_layout(page)
        for selected in short_ids:
            button=bar.locator('[data-'+attribute+'="'+selected+'"]')
            button.evaluate("e=>{const p=e.parentElement;p.scrollLeft=e.offsetLeft-p.offsetLeft;}")
            before=bar.evaluate("e=>({top:e.getBoundingClientRect().top,left:e.scrollLeft,y:scrollY})")
            button.click();settle_layout(page)
            after=bar.evaluate("e=>({top:e.getBoundingClientRect().top,left:e.scrollLeft,y:scrollY,reserve:[...document.querySelectorAll('.rl-filter-reserve')].reduce((n,e)=>n+e.getBoundingClientRect().height,0),height:innerHeight})")
            assert abs(after['top']-before['top'])<=2, ('filter viewport moved',panel,selected,before,after)
            assert abs(after['left']-before['left'])<=1, ('filter horizontal position moved',panel,selected,before,after)
            assert after['reserve']<=after['height']+1, ('excessive filter reserve',after)
            assert button.get_attribute('aria-pressed')=='true'
            verify_focus_visible(page,button)
            checks.append(dict(panel=panel,filter=selected,before=before,after=after))
        # A new wheel/input intent between the click and RAF must win.
        all_button=bar.locator('[data-'+attribute+'="all"]');all_button.click();settle_layout(page)
        desired=bar.locator('[data-'+attribute+'="'+short_ids[0]+'"]')
        user_y=desired.evaluate("e=>{e.click();window.dispatchEvent(new WheelEvent('wheel',{deltaY:-60}));window.scrollBy(0,-60);return scrollY;}")
        settle_layout(page);assert abs(page.evaluate('scrollY')-user_y)<=1, 'deferred filter correction overrode user scroll'
        # Explicit resize clears the temporary reserve, without a delayed restore.
        size=page.viewport_size;page.set_viewport_size({'width':size['width'],'height':size['height']-40});settle_layout(page)
        assert page.locator('.rl-filter-reserve').count()==0
        page.set_viewport_size(size);settle_layout(page)
        assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1')
    page.locator('#rl-tab-drives').click();settle_layout(page)
    assert page.locator('.rl-filter-reserve').count()==0
    return checks


def check_cooling_visibility(page,mode):
    if mode=='absent':
        assert not page.locator('#rl-tab-cooling').is_visible()
        page.evaluate("location.hash='#cooling'");settle_layout(page)
        assert page.locator('#rl-tab-drives').get_attribute('aria-selected')=='true'
        assert page.evaluate('location.hash')=='#drives'
        assert page.locator('#rl-tab-cooling').get_attribute('tabindex')=='-1'
        page.locator('#rl-tab-drives').focus();page.keyboard.press('ArrowRight');page.keyboard.press('ArrowRight')
        assert page.locator('#rl-tab-drives').evaluate('e=>e===document.activeElement')
        assert not page.locator('#rl-cooling').is_visible() and not page.locator('#rl-fan-manage').is_visible()
        assert page.evaluate('window.RacklineFixture.fanRequests||0')==0
    else:
        page.locator('#rl-tab-cooling').click();settle_layout(page)
        assert page.locator('#rl-cooling').is_visible()
        if mode=='unknown':
            assert 'detection is unknown' in page.locator('#rl-cooling-status').inner_text()
            assert not page.locator('#rl-fan-manage').is_visible()
            assert page.evaluate('window.RacklineFixture.fanRequests||0')==0
        else:
            page.wait_for_function("document.querySelector('#rl-fan-age').textContent.includes('Controller status sample')")
            page.evaluate('window.RacklineFixture.fanStatus=404')
            page.wait_for_function("document.querySelector('#rl-fan-age').textContent.includes('unavailable')",timeout=7500)
            assert page.locator('#rl-tab-cooling').is_visible() and page.locator('#rl-fan-manage').is_visible()
            assert 'Unavailable' in page.locator('#rl-cooling-status').inner_text()
    return {'mode':mode,'tab_visible':page.locator('#rl-tab-cooling').is_visible(),'fan_requests':page.evaluate('window.RacklineFixture.fanRequests||0')}


# Read all labels from the DOM, including intentionally visibility:hidden slots.
# innerText omits hidden text; it is only a meaningful assertion for visible bays.
PHYSICAL_GRID = r'''e => {
 const rect=e.getBoundingClientRect();
 return {width:rect.width,height:rect.height,children:[...e.children].map(n=>{
  const r=n.getBoundingClientRect(),style=getComputedStyle(n),label=n.querySelector('b');
  return {label:label.textContent,renderedLabel:label.innerText,x:r.x,y:r.y,width:r.width,height:r.height,
   hidden:style.visibility==='hidden',bg:style.backgroundColor,color:style.color};
 })};
}'''


def color_contrast(foreground, background):
    def luminance(color):
        match=re.fullmatch(r'rgb\((\d+), (\d+), (\d+)\)',color)
        assert match, ('Expected opaque computed color',color)
        channels=[int(value)/255 for value in match.groups()]
        linear=[value/12.92 if value<=0.04045 else ((value+0.055)/1.055)**2.4 for value in channels]
        return sum(value*weight for value,weight in zip(linear,[0.2126,0.7152,0.0722]))
    lo,hi=sorted([luminance(foreground),luminance(background)])
    return (hi+0.05)/(lo+0.05)


def check_ember_status(page):
    page.wait_for_function("document.querySelector('#rl-availability').hidden")
    status=page.locator('#rl-availability')
    assert status.get_attribute('role')=='status' and status.inner_text()==''
    assert status.evaluate('e=>e.getBoundingClientRect().height')==0
    appearance=page.evaluate("""() => {
      const style=s=>getComputedStyle(document.querySelector(s)),root=style('#rackline-root'),card=style('.rl-pulse article'),core=style('.rl-core'),fill=style('.rl-core i'),text=style('.rl-core b'),input=style('#rl-drive-search');
      return {background:root.backgroundColor,panel:card.backgroundColor,cardOpacity:card.opacity,core:core.backgroundColor,fill:fill.backgroundColor,fillOpacity:fill.opacity,text:text.color,input:input.backgroundColor,border:input.borderTopColor};
    }""")
    assert appearance['background']=='rgb(12, 16, 20)' and appearance['panel']=='rgb(21, 27, 33)'
    assert appearance['fill']=='rgb(122, 73, 45)' and appearance['text']=='rgb(234, 240, 246)'
    assert appearance['cardOpacity']=='1' and appearance['fillOpacity']=='1'
    assert color_contrast(appearance['text'],appearance['fill'])>=4.5
    assert color_contrast(appearance['text'],appearance['core'])>=4.5
    assert color_contrast(appearance['border'],appearance['input'])>=3
    cycles=[]
    for _ in range(3):
        page.evaluate("document.querySelector('#qa-outage').click();document.dispatchEvent(new Event('visibilitychange'))")
        page.wait_for_function("!document.querySelector('#rl-availability').hidden && document.querySelector('#rl-availability').textContent.includes('unavailable')",timeout=10000)
        assert status.is_visible() and status.get_attribute('role')=='status'
        assert page.locator('.rl-pulse article').first.evaluate('e=>getComputedStyle(e).opacity')=='1'
        page.evaluate("document.querySelector('#qa-outage').click();document.dispatchEvent(new Event('visibilitychange'))")
        page.wait_for_function("document.querySelector('#rl-availability').hidden",timeout=10000)
        assert status.inner_text()=='' and status.evaluate('e=>e.getBoundingClientRect().height')==0
        cycles.append('outage and recovery')
    target=page.locator('#rl-tab-drives');target.focus()
    # Keyboard navigation establishes focus-visible independently of pointer history.
    page.keyboard.press('ArrowRight');target=page.locator('#rl-tab-docker')
    focus=verify_focus_visible(page,target)
    assert target.evaluate('e=>getComputedStyle(e).outlineColor')=='rgb(108, 185, 255)'
    assert target.evaluate('e=>getComputedStyle(e).outlineWidth')=='3px'
    assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1')
    return {'appearance':appearance,'cycles':cycles,'focus':focus}


def check_physical_locations(page):
    php=os.environ.get('RACKLINE_PHP_BIN') or shutil.which('php')
    assert php, 'PHP is required for actual defaults-derived browser fixtures'
    native=json.loads(subprocess.run([php,str(HERE / 'native-defaults-browser-fixture.php')],check=True,capture_output=True,text=True).stdout)
    details=[]
    for columns,rows,width,height,start,orientation,align in [(4,2,120,48,20,'h','center'),(3,2,48,140,101,'v','right'),(65,1,44,60,700,'h','left')]:
        page.evaluate("""([columns,rows,width,height,start,orientation,align,nativeDisplay])=>{
          const cells=Array.from({length:columns*rows},(_,i)=>({sourceTray:i+1,tray:start+i,hidden:i===0&&rows>1,present:false,display:{source:'disklocation',identityVerified:false,color:'#303030'},disk:null}));
          cells[1]={...cells[1],present:true,display:nativeDisplay,disk:{name:'synthetic-long-drive-label',device:'sdb',selectionToken:'a'.repeat(64),poolName:'actual-fixture-pool'}};
          cells[2]={...cells[2],present:true,display:{source:'disklocation',identityVerified:false,color:'#ffff00',model:'Unverified cached fixture'}};
          window.RacklineFixture.locationGroups=[{id:'dynamic-fixture',name:'Dynamic enclosure '+start,columns,rows,trayWidth:width,trayHeight:height,trayDirection:orientation,trayAlign:align,nameAlign:align,position:'none',cells}];
          document.dispatchEvent(new Event('visibilitychange'));
        }""",[columns,rows,width,height,start,orientation,align,native['display']])
        page.wait_for_function("name=>document.querySelector('#rl-bays h4')?.textContent===name",arg='Dynamic enclosure '+str(start))
        grid=page.locator('#rl-bays .rl-bay-grid');bays=grid.locator('button')
        geometry=grid.evaluate(PHYSICAL_GRID)
        expected=[str(start+i) for i in range(columns*rows)]
        assert len(geometry['children'])==columns*rows, ('bay count',geometry)
        assert [b['label'] for b in geometry['children']]==expected, ('DOM tray labels',expected,geometry)
        assert all(b['renderedLabel']==b['label'] for b in geometry['children'] if not b['hidden']), ('visible tray labels',geometry)
        assert [i for i,b in enumerate(geometry['children']) if b['hidden']]==([0] if rows>1 else []), ('reserved hidden positions',geometry)
        assert all(abs(b['width']-width)<1 and abs(b['height']-height)<1 for b in geometry['children'])
        assert geometry['children'][0]['hidden']==(rows>1)
        assert geometry['children'][0]['y']==geometry['children'][columns-1]['y']
        if rows>1: assert geometry['children'][columns]['y']>geometry['children'][0]['y']
        assert 'Boot fixture-boot-alias' in bays.nth(1).inner_text()
        assert bays.nth(1).get_attribute('data-drive')=='sdb'
        assert bays.nth(2).get_attribute('data-drive') is None and bays.nth(2).is_disabled()
        assert 'identity unverified' in bays.nth(2).inner_text()
        assert geometry['children'][1]['bg']==native['expectedBackground'], ('defaults-derived computed bay background',geometry)
        assert geometry['children'][1]['color']==native['expectedForeground'], ('defaults-derived computed bay foreground',geometry)
        assert color_contrast(geometry['children'][1]['color'],geometry['children'][1]['bg'])>=4.5
        assert geometry['children'][2]['color']=='rgb(0, 0, 0)'
        assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1')
        bays.nth(1).click()
        assert page.locator('#rl-drive-inspector h3').inner_text()=='synthetic-long-drive-label'
        assert 'fixture-boot-alias' not in page.locator('#rl-drive-inspector').inner_text()
        bays.nth(1).focus();verify_focus_visible(page,bays.nth(1))
        assert 'not discovery or SMART freshness' in page.locator('.rl-location-source').get_attribute('title')
        capture_overview(page,args.output / f'rackline-synthetic-physical-{page.viewport_size["width"]}-{start}.png')
        details.append({'columns':columns,'rows':rows,'orientation':orientation,'tray_width':width,'tray_height':height})
    page.evaluate('''() => {
      window.RacklineFixture.locationGroups[0].position='left';
      window.RacklineFixture.locationGroups.push({id:'second',name:'Second synthetic enclosure',columns:2,rows:1,trayWidth:64,trayHeight:48,nameAlign:'hide',trayAlign:'right',position:'right',cells:[{tray:1,hidden:false,present:false},{tray:2,hidden:false,present:false}]});
      document.dispatchEvent(new Event('visibilitychange'));
    }''')
    page.wait_for_function("document.querySelectorAll('#rl-bays .rl-enclosure').length===2")
    areas=page.locator('#rl-bays .rl-enclosure').evaluate_all('''nodes=>nodes.map(e=>{const r=e.getBoundingClientRect();return {x:r.x,y:r.y,right:r.right,bottom:r.bottom};})''')
    a,b=areas
    assert min(a['right'],b['right'])-max(a['x'],b['x'])<=.5 or min(a['bottom'],b['bottom'])-max(a['y'],b['y'])<=.5
    assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1')
    assert not page.locator('#rl-bays .rl-enclosure').nth(1).locator('h4').is_visible()
    assert page.locator('#rl-bays .rl-enclosure').nth(1).get_attribute('aria-label')=='Second synthetic enclosure'
    return details


def check_smart_flow(page, mode):
    page.evaluate("mode => {window.RacklineFixture.smartMode=mode;}",mode)
    drive=page.locator('tr[data-drive="sdb"]')
    drive.click()
    button=page.locator('#rl-smart-read')
    if mode=='unsupported':
        page.wait_for_function("document.querySelector('#rl-smart-actions').textContent.includes('not supported')")
        assert button.count()==0
        assert all(r['action']=='state' for r in page.evaluate('window.RacklineFixture.smartRequests'))
        return {'unsupported':True}
    page.wait_for_function("document.querySelector('#rl-smart-read')?.getAttribute('aria-disabled')==='false'")
    button.focus();focus=verify_focus_visible(page,button)
    if mode=='old':
        assert 'Old · 24 h ago' in page.locator('#rl-drive-inspector').inner_text()
        assert 'Saved read age' in page.locator('#rl-drive-inspector').inner_text()
        assert page.locator('#rl-smart-actions a').count()==1
        return {'old_report':True,'focus':focus}
    if mode=='prepareHold':
        button.click()
        page.wait_for_function('typeof window.RacklineFixture.smartRelease === "function"')
        page.locator('#rl-pools button[data-pool="Cache"]').click()
        page.evaluate('window.RacklineFixture.smartRelease()')
        page.wait_for_function("document.querySelector('#rl-smart-actions').textContent.includes('not submitted')")
        assert [r['action'] for r in page.evaluate('window.RacklineFixture.smartRequests')]==['state','prepare']
        return {'cancelled_before_confirmation':True,'focus':focus}
    prompts=[]
    def confirm(dialog):
        prompts.append(dialog.message)
        if mode=='cancel': dialog.dismiss()
        else: dialog.accept()
    page.on('dialog',confirm)
    button.click()
    if mode=='captureHold':
        page.wait_for_function('typeof window.RacklineFixture.smartRelease === "function"')
        button.evaluate('e=>e.click()')  # a repeated click must not queue another read
        page.locator('tr[data-drive="sda"]').click()
        page.evaluate('window.RacklineFixture.smartRelease()')
        page.wait_for_function("!document.querySelector('#rl-smart-actions').textContent.includes('already pending')")
        assert page.locator('#rl-smart-actions a').count()==0
        assert len([r for r in page.evaluate('window.RacklineFixture.smartRequests') if r['action']=='capture'])==1
        assert 'Reading' not in page.locator('#rl-smart-actions').inner_text()
    else:
        expected='cancelled' if mode=='cancel' else 'unknown or failed' if mode=='failure' else 'saved'
        page.wait_for_function("text => document.querySelector('#rl-smart-actions').textContent.includes(text)",arg=expected)
        if mode=='failure':
            button.evaluate('e=>e.click()')
            assert button.get_attribute('aria-disabled')=='true'
            assert page.locator('#rl-smart-actions a').count()==0
        elif mode!='cancel':
            link=page.locator('#rl-smart-actions a')
            assert link.count()==1 and 'smart-capture-report.php?' in link.get_attribute('href')
            assert '&device=sdb&identity=' in link.get_attribute('href')
            assert 'Saved read age' in page.locator('#rl-drive-inspector').inner_text()
            button.focus();verify_focus_visible(page,button)
            if mode=='warnings': assert 'warnings' in page.locator('#rl-smart-actions').inner_text()
    requests=page.evaluate('window.RacklineFixture.smartRequests')
    assert len(prompts)==1 and '(sdb)' in prompts[0] and 'may wake' in prompts[0] and 'Do not remove' in prompts[0]
    assert len([r for r in requests if r['action']=='prepare'])==1
    assert len([r for r in requests if r['action']=='capture'])==(0 if mode=='cancel' else 1)
    verify(page,16,page.viewport_size['width']<=700)
    return {'mode':mode,'actions':[r['action'] for r in requests],'focus':focus}


with tempfile.TemporaryDirectory(prefix='rackline-responsive-') as temp, sync_playwright() as p:
    fixture_dir = Path(temp)
    browser = p.chromium.launch(executable_path=args.browser, headless=True, args=['--no-sandbox'])
    cases = [(str(width), width, 16, 1, False, 2) for width in [320,360,390,430,768,1024,1440]]
    cases += [(f'{width}-text-200', width, 16, 1, True, 2) for width in [320,390]]
    # Browser zoom reflow emulation: halve CSS viewport and double device scale.
    cases += [('desktop-200-percent-reflow',720,16,2,False,2)]
    cases += [(f'{width}-{threads}-cpus',width,threads,1,False,2) for width in [390,1024,1440] for threads in [32,72]]
    cases += [('320-text-200-smt8',320,16,1,True,8),('1024-single-thread-cores',1024,16,1,False,1),('1024-short-viewport',1024,32,1,False,2)]
    try:
        for name,width,threads,scale,text_scale,smt in cases:
            height = 480 if name=='1024-short-viewport' else 900
            context = browser.new_context(viewport={'width':width,'height':height}, device_scale_factor=scale)
            page = context.new_page()
            errors=[]
            focus_checks=[]
            page.on('pageerror',lambda error: errors.append(str(error)))
            # Any image load receives a synthetic icon. All other network is denied.
            page.route('**/*',lambda route: route.fulfill(content_type='image/svg+xml',body='<svg xmlns="http://www.w3.org/2000/svg"/>') if route.request.resource_type=='image' else route.abort())
            try:
                page.set_content(document(fixture_dir,threads,smt))
                page.wait_for_function("document.querySelectorAll('.rl-core').length === " + str(threads))
                page.wait_for_function("document.querySelector('#rl-cpu').textContent !== '—'",timeout=5000)
                # Native declarations are genuinely active outside Rackline.
                page.evaluate("() => {const b=document.createElement('button');b.id='native-probe';b.textContent='Native';document.body.append(b);}")
                assert page.locator('#native-probe').evaluate("e=>getComputedStyle(e).minWidth") == '86px'
                page.locator('#native-probe').evaluate('e=>e.remove()')
                if text_scale:
                    page.evaluate("""() => {const nodes=[document.querySelector('#rackline-root'),...document.querySelectorAll('#rackline-root *')];const sizes=nodes.map(e=>parseFloat(getComputedStyle(e).fontSize));nodes.forEach((e,i)=>e.style.fontSize=(sizes[i]*2)+'px');} """)
                    page.set_viewport_size({'width':width+1,'height':height})
                    page.set_viewport_size({'width':width,'height':height})
                page.wait_for_timeout(100)
                data=verify(page,threads,width<=700,smt)
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
                assert page.locator('#rl-drive-inspector a[href^="/Main/Device"]').count()==0
                assert page.locator('#rl-drive-inspector a[href^="/plugins/rackline.dashboard/include/smart-report.php?"]').count()==1
                # Arrow-key navigation, repeated selection, and browser history.
                page.locator('#rl-tab-drives').focus();page.keyboard.press('ArrowRight')
                assert page.locator('#rl-tab-docker').get_attribute('aria-selected')=='true'
                assert page.locator('#rl-tab-docker').evaluate('e=>e===document.activeElement')
                focus_checks.append(verify_focus_visible(page,page.locator('#rl-tab-docker')))
                verify(page,threads,width<=700,smt)
                assert not page.locator('#rl-process-section').is_visible(), 'Unsupported process sampling must not leave an empty card'
                assert not page.locator('#rl-native-metadata').is_visible(), 'Healthy metadata must not show boilerplate'
                controls=page.locator('#rl-docker button[data-native-menu]').first
                controls.click();assert page.locator('#qa-menu').is_visible()
                page.locator('#qa-close').click();assert page.locator('#qa-menu').count()==0
                owner=page.locator('tr[data-container$="owner-managed-demo"]')
                owner.focus();page.keyboard.press('Enter')
                focus_checks.append(verify_focus_visible(page,owner))
                fallback=owner.locator('a[href="/Docker"]')
                assert fallback.count()==1 and 'rl-button' in fallback.get_attribute('class')
                fallback.focus();focus_checks.append(verify_focus_visible(page,fallback))
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
                verify(page,threads,width<=700,smt)
                capture_overview(page,args.output / f'rackline-synthetic-{name}-cooling.png')
                footer=page.locator('#rackline-root .rl-footer a[href="/Dashboard"]')
                footer.focus();focus_checks.append(verify_focus_visible(page,footer))
                page.go_back();page.wait_for_function("document.querySelector('#rl-tab-docker').getAttribute('aria-selected')==='true'")
                page.go_forward();page.wait_for_function("document.querySelector('#rl-tab-cooling').getAttribute('aria-selected')==='true'")
                assert not errors, errors
                results.append({'case':name,'width':width,'threads':threads,'smt':smt,'text_scale':2 if text_scale else 1,'geometry':data,'focus_checks':focus_checks,'passed':True})
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
        extra_cases=[('filters-'+str(w),w,'filters') for w in [390,1024,1440]]
        extra_cases += [('cooling-'+mode+'-'+str(w),w,mode) for w in [390,1024] for mode in ['absent','unknown','outage']]
        extra_cases += [('ember-status-'+str(w),w,'ember') for w in [390,1024,1440]]
        extra_cases += [('physical-'+str(w),w,'physical') for w in [390,1024,1440]]
        extra_cases += [('smart-'+mode+'-'+str(w),w,'smart:'+mode) for w in [390,1024] for mode in ['normal','cancel','failure','unsupported','old','warnings','prepareHold','captureHold']]
        for name,width,mode in extra_cases:
            context=browser.new_context(viewport={'width':width,'height':900})
            page=context.new_page();errors=[]
            page.on('pageerror',lambda error: errors.append(str(error)))
            page.route('**/*',lambda route: route.fulfill(content_type='image/svg+xml',body='<svg xmlns="http://www.w3.org/2000/svg"/>') if route.request.resource_type=='image' else route.abort())
            try:
                page.set_content(document(fixture_dir,16,fan=False if mode=='absent' else None if mode=='unknown' else True,filter_stress=mode=='filters',smart_test=mode.startswith('smart:')))
                page.wait_for_function("document.querySelector('#rl-cpu').textContent !== '—'",timeout=5000)
                details=check_ember_status(page) if mode=='ember' else check_physical_locations(page) if mode=='physical' else check_smart_flow(page,mode.split(':',1)[1]) if mode.startswith('smart:') else check_filter_switches(page) if mode=='filters' else check_cooling_visibility(page,mode)
                assert not errors,errors
                capture_overview(page,args.output / f'rackline-synthetic-{name}.png')
                results.append({'case':name,'width':width,'details':details,'passed':True});print('PASS',name)
            except Exception:
                # Keep the failing viewport first, then include the below-fold
                # workspace and an atomic geometry snapshot for diagnosis.
                page.screenshot(path=str(args.output / f'FAILED-viewport-{name}.png'),full_page=False)
                (args.output / f'FAILED-geometry-{name}.json').write_text(json.dumps(page.evaluate(GEOMETRY),indent=2))
                page.screenshot(path=str(args.output / f'FAILED-synthetic-{name}.png'),full_page=True)
                raise
            finally:
                (args.output / 'geometry-results.json').write_text(json.dumps(results,indent=2));context.close()
    finally:
        browser.close()
print(f'{len(results)} synthetic browser cases passed; no installed-host acceptance claimed.')
