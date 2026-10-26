"""Actual website role/CSRF approval and public HTTPS polling boundaries."""
import http.cookiejar,json,os,pathlib,shutil,socket,subprocess,tempfile,time,urllib.request,urllib.error
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tti-pc-http-') as folder:
    root=pathlib.Path(folder);app=root/'app';app.mkdir();(app/'api').mkdir()
    for name in ['auth_store.php','session_store.php','master_store.php','product_stage.php','offline_idempotency.php','office_backup_auth.php','office_pc_pairing.php','connect-backup-pc.php']:shutil.copy(ROOT/name,app/name)
    shutil.copy(ROOT/'api/office_pc_pair.php',app/'api/office_pc_pair.php')
    private=root/'transtrade_private';private.mkdir();(private/'auth.json').write_text(json.dumps({'users':[{'id':1,'username':'fixture-owner','role':'Super Admin','active':True,'session_version':1},{'id':2,'username':'fixture-staff','role':'Accounts','active':True,'session_version':1}],'masters':{},'audit':[],'settings':{'qa_account_seeded':True,'master_permission_schema':2}}))
    (app/'seed.php').write_text("<?php require __DIR__.'/auth_store.php';require __DIR__.'/office_backup_auth.php';tt_backup_password_set('fixture-server-backup-password');")
    env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
    subprocess.run(['php',str(app/'seed.php')],check=True,env=env,capture_output=True)
    (app/'fixture-session.php').write_text("<?php require __DIR__.'/auth_store.php';$_SESSION=['user_id'=>(int)($_GET['id']??1),'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];echo '{}';")
    with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));base=f'http://127.0.0.1:{port}'
    def call(path,body=None,secure=False,form=False):
        data=None if body is None else (urllib.parse.urlencode(body).encode() if form else json.dumps(body).encode())
        headers={'Content-Type':'application/x-www-form-urlencoded' if form else 'application/json'}
        if secure:headers['X-Forwarded-Proto']='https'
        try:
            with opener.open(urllib.request.Request(base+path,data=data,headers=headers),timeout=10) as res:return res.status,res.read()
        except urllib.error.HTTPError as res:return res.code,res.read()
    with open(root/'log','w') as log:
        process=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(app)],env=env,stdout=log,stderr=log)
        try:
            for _ in range(60):
                try:call('/fixture-session.php?id=2');break
                except urllib.error.URLError:time.sleep(.05)
            device={'id':'a'*32,'secret':'b'*64,'computer':'Fixture <script> PC'}
            assert call('/api/office_pc_pair.php',{'action':'start',**device})[0]==403
            code,data=call('/api/office_pc_pair.php',{'action':'start',**device},True);assert code==200;started=json.loads(data)
            page='/connect-backup-pc.php?request='+device['id']
            assert call(page)[0]==403
            call('/fixture-session.php?id=1');code,data=call(page);assert code==200 and started['code'].encode() in data and b'&lt;script&gt;' in data
            assert device['secret'].encode() not in data and b'secretHash' not in data
            assert call(page,{'csrf':'wrong'},form=True)[0]==419
            assert not (private/'office-backup-token.sha256').exists()
            assert call(page,{'csrf':'fixture'},form=True)[0]==200
            code,data=call('/api/office_pc_pair.php',{'action':'poll',**device},True);assert code==200 and len(json.loads(data)['token'])==64
            assert call('/api/office_pc_pair.php',{'action':'poll',**device},True)[0]==403
            print('PASS actual PC authorization website role/CSRF/HTML escaping and one-use HTTPS polling')
        finally:process.terminate();process.wait(timeout=5)
