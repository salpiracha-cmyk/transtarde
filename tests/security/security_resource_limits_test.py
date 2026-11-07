"""Real multipart uploads and cross-process AI reservations in disposable storage."""
import concurrent.futures,json,os,pathlib,shutil,socket,subprocess,tempfile,time,urllib.request,urllib.error
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tti-resource-limits-') as temp:
    root=pathlib.Path(temp);app=root/'app';shutil.copytree(ROOT,app,ignore=shutil.ignore_patterns('.git','node_modules','tmp','__pycache__'));private=root/'transtrade_private';private.mkdir()
    auth={'users':[{'id':1,'username':'fixture','role':'Super Admin','active':True,'session_version':1}],'masters':{},'audit':[]};(private/'auth.json').write_text(json.dumps(auth));(private/'accounts.json').write_text('{}')
    router=root/'router.php';router.write_text('''<?php
$_SERVER['REMOTE_ADDR']='192.0.2.3';session_name('TRANSTRADE_SESSION');session_id('uploadfixture');session_start();$_SESSION=['user_id'=>1,'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];session_write_close();require __DIR__.'/app'.parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
''')
    env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
    with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    server=subprocess.Popen(['php','-d','upload_max_filesize=16M','-d','post_max_size=17M','-d',f'session.save_path={root}','-S',f'127.0.0.1:{port}',str(router)],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    def upload(kind='receipt',size=2*1024*1024):
        boundary='TTIFixtureBoundary';field='adviceFile' if kind=='receipt' else 'billFile';endpoint='accounts_receipt_file' if kind=='receipt' else 'bag_bill_file'
        body=b''.join((f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n').encode() for k,v in [('csrf','fixture'),('entity','TTI')])
        data=b'%PDF-1.4\n'+b' '*size+b'\n%%EOF\n';body+=(f'--{boundary}\r\nContent-Disposition: form-data; name="{field}"; filename="invoice.pdf"\r\nContent-Type: application/pdf\r\n\r\n').encode()+data+f'\r\n--{boundary}--\r\n'.encode()
        req=urllib.request.Request(f'http://127.0.0.1:{port}/api/{endpoint}.php',data=body,headers={'Content-Type':f'multipart/form-data; boundary={boundary}'})
        try:
            with urllib.request.urlopen(req,timeout=15) as resp:return resp.status,json.load(resp)
        except urllib.error.HTTPError as e:return e.code,json.load(e)
    try:
        for _ in range(50):
            try:
                with socket.create_connection(('127.0.0.1',port),timeout=.1):break
            except OSError:time.sleep(.05)
        st,first=upload();assert st==200,(st,first);token=first['token'];pending=private/'backups/.pending-uploads/state.json';state=json.loads(pending.read_text());relative='accounts-receipt-uploads/'+token;assert state['files'][relative]['entity']=='TTI'
        helper=root/'helper.php';helper.write_text("<?php define('TT_DATA_DIR',"+json.dumps(str(private))+");require "+json.dumps(str(app/'pending_uploads.php'))+";function tt_ensure_data_dir(){} $op=$argv[1];if($op==='pending')echo json_encode(array_keys(tt_pending_unclaimed()));else{try{tt_pending_claim(['id'=>(int)$argv[2]],$argv[3],'receipt',$argv[4],json_decode(file_get_contents(TT_DATA_DIR.'/accounts.json'),true));echo 'allowed';}catch(InvalidArgumentException $e){echo 'denied';}}")
        def helper_run(*args):return subprocess.check_output(['php',str(helper),*args],text=True,env=env).strip()
        assert relative in json.loads(helper_run('pending'))
        assert helper_run('claim','2','TTI',token)=='denied';assert helper_run('claim','1','BRM',token)=='denied';assert helper_run('claim','1','TTI',token)=='allowed'
        # Reserving by another worker must count before accepting another file.
        state['files'][relative]['size']=64*1024*1024;pending.write_text(json.dumps(state));st,data=upload();assert st==422,(st,data)
        snapshot=root/'snapshot.php';snapshot.write_text("<?php require "+json.dumps(str(app/'auth_store.php'))+";require "+json.dumps(str(app/'backup_lib.php'))+";$temps=[];$files=tt_backup_snapshot_private_files($temps);$before=file_get_contents($files['accounts.json']);file_put_contents(TT_DATA_DIR.'/accounts.json',json_encode(['exportReceipts'=>['R'=>['entity'=>'TTI','bankAdviceFileRef'=>$argv[1]]]]));echo json_encode(['stable'=>$before===file_get_contents($files['accounts.json']),'files'=>array_keys($files),'books'=>json_decode($before,true)]);foreach($temps as $temp)unlink($temp);")
        snap=json.loads(subprocess.check_output(['php',str(snapshot),token],env=env,text=True))
        assert snap['stable'] and relative not in snap['files'] and token not in json.dumps(snap['books']),snap
        (private/'accounts.json').write_text(json.dumps({'exportReceipts':{'R':{'entity':'TTI','bankAdviceFileRef':token}}}));assert helper_run('pending')=='[]'
        st,second=upload('bagbill');assert st==200,(st,second);assert (private/relative).is_file(),'Claimed receipt must survive cleanup'
        state=json.loads(pending.read_text());bag='bag-bill-uploads/'+second['token'];state['files'][bag]['expires']=1;pending.write_text(json.dumps(state));st,third=upload('bagbill');assert st==200 and not (private/bag).exists() and (private/relative).exists()
    finally:server.terminate();server.wait(timeout=5)
    limiter=root/'limit.php';limiter.write_text("<?php define('TT_DATA_DIR',"+json.dumps(str(private))+");require "+json.dumps(str(app/'api/document_ai_limits.php'))+";try{echo tt_ai_limit_acquire((int)$argv[1],(int)$argv[2]);}catch(TTDocumentAiLimit $e){echo 'limited';}")
    def acquire(uid,amount=100):return subprocess.check_output(['php',str(limiter),str(uid),str(amount)],env=env,text=True).strip()
    with concurrent.futures.ThreadPoolExecutor(max_workers=12) as pool:leases=list(pool.map(acquire,range(1,13)))
    assert sum(x!='limited' for x in leases)==4,leases
    statefile=private/'backups/.security-limits/ai.json';state=json.loads(statefile.read_text());assert state['count']==4;state['leases']={};state['calls']=[];state['bytes']=128*1024*1024;statefile.write_text(json.dumps(state));assert acquire(33)=='limited'
    state['bytes']=0;state['count']=300;statefile.write_text(json.dumps(state));assert acquire(33)=='limited'
    state['count']=0;state['users']={};statefile.write_text(json.dumps(state));assert acquire(44)!='limited' and acquire(44)!='limited' and acquire(44)=='limited'
    # Run the actual CLI entry point without a browser identity. Substitute only
    # its provider health operation so this regression never spends API credits.
    monitor=root/'monitor';(monitor/'scripts').mkdir(parents=True);(monitor/'api').mkdir()
    shutil.copy2(app/'scripts/diagnose_document_reader.php',monitor/'scripts/diagnose_document_reader.php')
    (monitor/'auth_store.php').write_text("<?php define('TT_DATA_DIR',"+json.dumps(str(private))+");function tt_current_user(){return null;}")
    (monitor/'api/document_ai_health.php').write_text("<?php require "+json.dumps(str(app/'api/document_ai_limits.php'))+";function tt_ai_check_connections(){return tt_ai_metered(['sample'=>'connection-check'],static fn()=>['fixture'=>['linked'=>true]]);}")
    state['count']=0;state['bytes']=0;state['users']={};state['calls']=[];state['leases']={};statefile.write_text(json.dumps(state))
    probe=subprocess.run(['php',str(monitor/'scripts/diagnose_document_reader.php')],env=env,text=True,capture_output=True)
    assert probe.returncode==0 and json.loads(probe.stdout)['providers']['fixture']['linked'],probe
    state=json.loads(statefile.read_text());assert state['count']==1 and len(state['users'])==1 and not state['leases'],state
    state['count']=300;statefile.write_text(json.dumps(state))
    probe=subprocess.run(['php',str(monitor/'scripts/diagnose_document_reader.php')],env=env,text=True,capture_output=True)
    assert probe.returncode!=0 and 'could not be completed' in probe.stderr,probe
    # Even a forged diagnostic flag must not authorize a sessionless HTTP call.
    anonymous=app/'anonymous_ai.php';anonymous.write_text("<?php define('TT_DATA_DIR',"+json.dumps(str(private))+");define('TT_DOCUMENT_AI_CONNECTION_DIAGNOSTIC',true);require __DIR__.'/api/document_ai_limits.php';try{tt_ai_metered([],static fn()=>print('provider-called'));}catch(RuntimeException $e){http_response_code(403);echo $e->getMessage();}")
    server=subprocess.Popen(['php','-d',f'session.save_path={root}','-S',f'127.0.0.1:{port}',str(router)],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    try:
        for _ in range(50):
            try:
                with socket.create_connection(('127.0.0.1',port),timeout=.1):break
            except OSError:time.sleep(.05)
        for route,expected in [('anonymous_ai.php',403),('scripts/diagnose_document_reader.php',404)]:
            try:urllib.request.urlopen(f'http://127.0.0.1:{port}/{route}',timeout=5);raise AssertionError('Anonymous diagnostic unexpectedly allowed')
            except urllib.error.HTTPError as e:
                body=e.read().decode();assert e.code==expected and 'provider-called' not in body,(e.code,body)
                if expected==403:assert 'Authenticated AI principal required' in body,body
    finally:server.terminate();server.wait(timeout=5)
    print('PASS actual uploads: bounded pending bytes, actor/company claims, claimed file preservation and expired orphan cleanup; concurrent AI leases, daily ceilings, metered CLI health check and anonymous HTTP denial')
