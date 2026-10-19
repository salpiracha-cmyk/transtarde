"""A damaged session expires only its own identity; business-store failures stay visible."""
import hashlib, http.cookies, json, os, pathlib, shutil, socket, subprocess, tempfile, time, urllib.error, urllib.request
ROOT=pathlib.Path(os.environ.get('TT_SESSION_TEST_SOURCE',pathlib.Path(__file__).resolve().parents[2]))
with tempfile.TemporaryDirectory(prefix='tti-corrupt-session-') as directory:
    root=pathlib.Path(directory);app=root/'app';app.mkdir();private=root/'transtrade_private';private.mkdir();records=private/'sessions'/'records';records.mkdir(parents=True)
    for name in ['auth_store.php','master_store.php','product_stage.php','offline_idempotency.php','session_store.php']:
        shutil.copy(ROOT/name,app/name)
    user={'id':1,'username':'fixture','full_name':'Fixture','role':'Super Admin','active':True,'session_version':1,'permissions':{}}
    auth=json.dumps({'users':[user],'masters':{},'audit':[]}).encode();(private/'auth.json').write_bytes(auth)
    sentinels={'accounts.json':b'{"journals":{"preserved":true}}','operations.json':b'{"preserved":true}'}
    for name,data in sentinels.items():(private/name).write_bytes(data)
    (app/'probe.php').write_text('''<?php
$_SERVER['REQUEST_URI']=isset($_GET['protected'])?'/api/protected.php':'/login.php';
require __DIR__.'/auth_store.php';
if(isset($_GET['protected'])){$user=tt_require_login();echo json_encode(['authorized'=>true]);exit;}
echo json_encode(['anonymous'=>empty($_SESSION['user_id']),'csrfLength'=>strlen(tt_csrf()),'csrfHash'=>hash('sha256',tt_csrf())]);
session_write_close();
''')
    with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    with (root/'php.log').open('w') as log:
        server=subprocess.Popen(['php','-d','display_errors=0','-S',f'127.0.0.1:{port}','-t',str(app)],stdout=log,stderr=log)
        def request(cookie=None,protected=False):
            headers={'Cookie':'TRANSTRADE_SESSION='+cookie} if cookie else {}
            req=urllib.request.Request(f'http://127.0.0.1:{port}/probe.php'+('?protected=1' if protected else ''),headers=headers)
            try:
                with urllib.request.urlopen(req,timeout=5) as response:return response.status,response.headers,response.read()
            except urllib.error.HTTPError as response:return response.code,response.headers,response.read()
        try:
            for _ in range(50):
                try:request();break
                except urllib.error.URLError:time.sleep(.05)
            valid='valid-session-fixture';valid_path=records/(hashlib.sha256(valid.encode()).hexdigest()+'.json')
            valid_bytes=json.dumps({'user_id':1,'auth_version':1,'last_activity_at':int(time.time()),'csrf':'f'*64}).encode();valid_path.write_bytes(valid_bytes)
            assert request(valid,True)[0]==200,'Control session must have its authorized access'
            valid_before=valid_path.read_bytes()
            for index,payload in enumerate([b'{',b'{"user_id":1,"auth_version":1',b'null',b'false',b'"wrong-shape"',b'{"csrf":"\xff"}']):
                identity='damaged-session-'+str(index);path=records/(hashlib.sha256(identity.encode()).hexdigest()+'.json');path.write_bytes(payload)
                status,headers,body=request(identity)
                assert status==200,('Damaged session must show usable sign-in, not HTTP 500',index,status)
                data=json.loads(body);assert data['anonymous'] and data['csrfLength']==64
                assert headers.get('X-TT-Session-Recovery')=='invalid-record-replaced'
                cookies=http.cookies.SimpleCookie();cookies.load(headers.get('Set-Cookie',''));replacement=cookies['TRANSTRADE_SESSION'].value
                assert replacement!=identity,'Damaged identity must be replaced'
                assert path.read_bytes()==payload,'Preserve the original damaged bytes for diagnosis'
                assert request(identity,True)[0]==401,'Do not recover authentication from a truncated user_id prefix'
                status,headers,body=request(replacement);assert status==200
                assert json.loads(body)['csrfHash']==data['csrfHash'],'Replacement sign-in must retain its CSRF'
                assert request(replacement,True)[0]==401
                assert request(valid,True)[0]==200,'Another valid user must stay authenticated'
                assert valid_path.read_bytes()==valid_before,'Do not expire or alter unrelated sessions'
                assert (private/'auth.json').read_bytes()==auth
                for name,value in sentinels.items():assert (private/name).read_bytes()==value
            (private/'auth.json').write_bytes(b'{broken-auth')
            assert request(valid,True)[0]==500,'A damaged business/authentication store must not be disguised as a session recovery'
            assert (private/'auth.json').read_bytes()==b'{broken-auth'
            print('PASS damaged/truncated/scalar/invalid-UTF8 sessions: sign-in 200, identity rotated, protected 401, stable CSRF, original bytes/other users/books preserved; actual store errors remain visible')
        finally:
            server.terminate();server.wait(timeout=5)
