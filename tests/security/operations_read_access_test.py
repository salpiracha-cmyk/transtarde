"""Real authenticated operational GETs must require an application module grant."""
import json, os, pathlib, shutil, subprocess, tempfile

ROOT = pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tti-operations-read-') as directory:
    root = pathlib.Path(directory)
    app = root / 'app'
    shutil.copytree(ROOT, app, ignore=shutil.ignore_patterns('.git', 'node_modules', '__pycache__'))
    private = root / 'transtrade_private'
    private.mkdir()
    harness = root / 'request.php'
    harness.write_text(r'''<?php
$_SERVER['REQUEST_URI']='/api/'.basename($argv[1]);$_SERVER['REQUEST_METHOD']='GET';$_SERVER['REMOTE_ADDR']='192.0.2.42';
session_name('TRANSTRADE_SESSION');session_id('operations-read-'.bin2hex(random_bytes(12)));session_start();
$_SESSION=['user_id'=>(int)$argv[3],'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];session_write_close();
register_shutdown_function(static function()use($argv){file_put_contents($argv[2],(string)(http_response_code()?:200));});
require $argv[1];
''')
    auth = private / 'auth.json'
    operations = private / 'operations.json'
    books = private / 'accounts.json'
    marker = 'fixture-restricted-business-record'
    operations.write_text(json.dumps({'revision':7, 'values':{'transtrade_export_v3_operational':json.dumps({'contracts':[{'ref':'FIXTURE-REF','price':marker}], 'shipments':[]})}, 'meta':{}}))
    books.write_text(json.dumps({'revision':4, 'journals':{}}))
    original = [operations.read_bytes(), books.read_bytes()]
    env = {k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
    user = {'id':1, 'username':'fixture', 'role':'Staff', 'active':True, 'session_version':1, 'master_access':True, 'permissions':{}}

    def request(endpoint, permissions, user_id=1, role='Staff'):
        user['permissions'] = permissions
        user['role'] = role
        auth.write_text(json.dumps({'users':[user], 'masters':{}, 'audit':[]}))
        status_file = root / 'status.txt'
        result = subprocess.run(['php', '-d', f'session.save_path={root}', str(harness), str(app/'api'/endpoint), str(status_file), str(user_id)], env=env, text=True, capture_output=True)
        assert result.returncode == 0, result.stderr + result.stdout
        assert [operations.read_bytes(), books.read_bytes()] == original, 'A read or denial changed canonical data'
        return int(status_file.read_text()), json.loads(result.stdout)

    for endpoint in ['operations.php', 'operations.mysql.php']:
        for permissions in [{}, {'Unrelated module':['View']}]:
            status, data = request(endpoint, permissions)
            assert status == 403 and 'values' not in data and marker not in json.dumps(data), (endpoint, status, data)
        assert request(endpoint, {}, user_id=0)[0] == 401
        for module in ['Mill', 'Exports', 'Accounts', 'Directors']:
            status, data = request(endpoint, {module:['View']})
            assert status == 200,(endpoint,module,status,data)
            if module == 'Exports':assert marker in data['values']['transtrade_export_v3_operational']
            else:assert marker not in json.dumps(data),(endpoint,module,data)
        status, data = request(endpoint, {}, role='Director')
        assert status == 200 and marker in data['values']['transtrade_export_v3_operational']
        status, data = request(endpoint, {}, role='Super Admin')
        assert status == 200 and data['revision'] == 7
    print('PASS operational read access: real auth on both routes; no-module and unrelated grants denied without data; existing application and Super Admin reads retained; canonical bytes unchanged')
