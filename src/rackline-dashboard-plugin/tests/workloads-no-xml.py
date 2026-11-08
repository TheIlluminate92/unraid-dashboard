#!/usr/bin/env python3
"""Exercise actual XML-extension absence using an existing valid native template, without sockets."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

source = Path(__file__).resolve().parents[1] / 'source/usr/local/emhttp/plugins/rackline.dashboard/include/workloads.php'
php = os.environ.get('PHP_NO_XML_BINARY') or shutil.which('php')
if not php:
    raise SystemExit('PHP CLI required; no no-XML checks ran')
with tempfile.TemporaryDirectory(prefix='rackline-no-xml-') as temporary:
    root = Path(temporary)
    templates = root / 'templates'
    templates.mkdir()
    template = templates / 'my-alpha.xml'
    template.write_text('<Container><Name>alpha</Name><Repository>example/alpha:latest</Repository></Container>')
    code = '''
if (class_exists('DOMDocument', false)) { echo 'SKIP: DOM remains loaded under this interpreter; no absence claim'; exit(77); }
define('RACKLINE_WORKLOADS_NO_MAIN', true);
require SOURCE;
$items = rackline_workloads_inventory([['Id'=>str_repeat('a',64),'Names'=>['/alpha'],'State'=>'running','Labels'=>['net.unraid.docker.managed'=>'dockerman']]]);
$result = rackline_workloads_metadata($items, ROOT.'/docroot', ROOT.'/templates', ROOT.'/autostart');
if (!is_file(ROOT.'/templates/my-alpha.xml')) throw new RuntimeException('Missing fixture template');
if (($result['status']['templatesAvailable'] ?? null) !== false || ($result['info']['alpha']['template'] ?? null) !== '') throw new RuntimeException('Missing XML support must suppress an existing unchecked template');
echo 'Actual DOM absence with an existing owned template: conservative fallback passed';
'''.replace('SOURCE', json.dumps(str(source))).replace('ROOT', json.dumps(str(root)))
    proc = subprocess.run([php, '-n', '-r', code], capture_output=True, text=True, timeout=10)
    if proc.returncode == 77:
        print(proc.stdout)
    elif proc.returncode != 0 or proc.stderr.strip():
        raise SystemExit(f'No-XML test failed ({proc.returncode}): {proc.stdout}\n{proc.stderr}')
    else:
        assert template.read_text().startswith('<Container>')
        print(proc.stdout)
