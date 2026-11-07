#!/usr/bin/env python3
"""Render actual Rackline UI with generic fixtures for local QA; not packaged or deployed."""
import argparse,re,shutil
from pathlib import Path
p=argparse.ArgumentParser();p.add_argument('output',type=Path);a=p.parse_args();a.output.mkdir(parents=True,exist_ok=True)
root=Path(__file__).resolve().parents[1]/'source/usr/local/emhttp/plugins/rackline.dashboard'
view=(root/'include/view.php').read_text();view=view[view.index('<link'):view.index('<?php if (!isset')];view=view.replace('<?=$racklineWeb?>','/plugins/rackline.dashboard').replace('@@APP_VERSION@@','0.1.0 QA').replace('@@RELEASE_VERSION@@','qa')
assets=a.output/'plugins/rackline.dashboard';shutil.copytree(root/'assets',assets/'assets',dirs_exist_ok=True);shutil.copytree(root/'images',assets/'images',dirs_exist_ok=True)
html='''<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Rackline implementation QA · sample data</title><style>body{margin:0;background:#111820;color:#e9eff5;font:14px system-ui}.qa{padding:12px 20px;background:#453421}.qa button{margin:4px;padding:6px}.qa-frame{width:390px;height:844px;border:0}</style><div class="qa"><b>IMPLEMENTATION QA · SYNTHETIC DATA · native operations are stubs</b><br><button id="qa-stop">Stop fixture container</button><button id="qa-recreate">Recreate fixture identity</button><button id="qa-outage">Toggle feed outage</button><button id="qa-fan">Toggle watchdog fault</button><button id="qa-bad">Toggle malformed feed</button><span id="qa-status">Healthy fixtures</span><span id="qa-registers"></span></div>'''+view+'''<script id="rackline-bootstrap" type="application/json">{"hostName":"Example server","available":true,"fanAvailable":true,"version":"QA"}</script><script src="/fixture.js"></script><script src="/plugins/rackline.dashboard/assets/js/model.js"></script><script src="/plugins/rackline.dashboard/assets/js/app.js"></script></html>'''
(a.output/'index.html').write_text(html);(a.output/'mobile.html').write_text('<html><head><title>390px mobile viewport QA</title></head><body style="margin:0;background:#111820"><iframe title="Mobile Rackline" src="/" style="width:390px;height:844px;border:0"></iframe></body></html>')
shutil.copy2(Path(__file__).with_name('fixture.js'),a.output/'fixture.js')
print(a.output)
