"""Exercise legacy cross-module writes against damaged disposable source books."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tti-source-integrity-') as directory:
    root = Path(directory)
    app = root / 'app'
    shutil.copytree(ROOT, app, ignore=shutil.ignore_patterns('.git', 'node_modules', 'tmp', '__pycache__'))
    private = root / 'transtrade_private'
    private.mkdir()
    books = private / 'accounts.json'
    (private / 'auth.json').write_text(json.dumps({'users': [{
        'id': 1, 'username': 'source-fixture', 'full_name': 'Source fixture',
        'role': 'Super Admin', 'active': True, 'session_version': 1, 'permissions': {}
    }], 'masters': {}, 'settings': {'qa_account_seeded': True}, 'audit': []}))
    (private / 'operations.json').write_text(json.dumps({'revision': 0, 'values': {}}))
    harness = root / 'request.php'
    harness.write_text(r'''<?php
$req=json_decode($argv[2],true);$_SERVER['REQUEST_URI']='/api/'.$req['endpoint'].'.php?entity=TTI';$_SERVER['REQUEST_METHOD']=$req['method'];$_SERVER['REMOTE_ADDR']='192.0.2.91';$_GET=['entity'=>'TTI'];
session_name('TRANSTRADE_SESSION');session_id('source-fixture');session_start();$_SESSION=['user_id'=>1,'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];session_write_close();
$GLOBALS['fixtureBody']=json_encode($req['body']);
class SourceInput{public $context;private $offset=0;function stream_open($p,$m,$o,&$opened){return $p==='php://input';}function stream_read($n){$s=substr($GLOBALS['fixtureBody'],$this->offset,$n);$this->offset+=strlen($s);return $s;}function stream_eof(){return $this->offset>=strlen($GLOBALS['fixtureBody']);}function stream_stat(){return [];}}
stream_wrapper_unregister('php');stream_wrapper_register('php',SourceInput::class);
register_shutdown_function(static function()use($req){file_put_contents($req['statusFile'],(string)(http_response_code()?:200));});require $argv[1];
''')
    env = {k: v for k, v in os.environ.items() if not k.startswith('TT_DB_')}

    def request(endpoint, body, method='POST'):
        status_file = root / 'status'
        req = {'endpoint': endpoint, 'method': method, 'statusFile': str(status_file),
               'body': {'entity': 'TTI', 'csrf': 'fixture', **body}}
        p = subprocess.run(['php', '-d', f'session.save_path={root}', str(harness),
                            str(app / 'api' / (endpoint + '.php')), json.dumps(req)],
                           env=env, capture_output=True, text=True, timeout=20)
        assert p.returncode == 0, p.stdout + p.stderr
        return int(status_file.read_text()), json.loads(p.stdout)

    routes = [
        ('management_costing_attach', {}, 'POST'),
        ('management_costing', {'action': 'refresh_sheets'}, 'POST'),
        ('management_costing', {}, 'GET'),
        ('local_sales_entity_rule', {'targetEntity': 'TTI', 'soda': 'FIXTURE-SODA'}, 'POST'),
    ]
    failures = []
    invalid = [None, b'', b' ', b'{"revision":7,"journals":', b'null', b'[]',
               b'[1,2]', b'{"revision":7,"journals":null}',
               b'{"revision":7,"journals":"damaged"}', b'{"revision":7,"legacy":1e309}']
    for endpoint, body, method in routes:
        for raw in invalid:
            if books.exists():
                books.unlink()
            if raw is not None:
                books.write_bytes(raw)
            status, result = request(endpoint, body, method)
            after = books.read_bytes() if books.exists() else None
            if status != 500 or after != raw:
                failures.append((endpoint, method, repr(raw), status, after != raw))
        # A valid store retains journals, foreign-company collections and nested unknown data.
        source = {'revision': 7, 'journals': {'KEEP': {'id': 'KEEP', 'entity': 'BRM',
                  'status': 'Posted', 'date': '2026-10-01', 'lines': []}},
                  'untouched': {'nested': ['keep', 0]}, 'localSalesCandidates': {}}
        books.write_text(json.dumps(source))
        before = books.read_bytes()
        status, result = request(endpoint, body, method)
        assert status == 200, (endpoint, status, result)
        saved = json.loads(books.read_text())
        assert saved['journals'] == source['journals'] and saved['untouched'] == source['untouched']
        assert saved['revision'] == (7 if method == 'GET' else 8)
        if method == 'GET':
            assert books.read_bytes() == before
    for failure in failures:
        print('FAILED source preservation:', failure, flush=True)
    assert not failures, f'{len(failures)} damaged/missing-source cases failed'
    print('PASS: 40 damaged/missing source cases and 4 valid source flows; actual auth, original bytes preserved')
