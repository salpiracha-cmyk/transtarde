"""Actual authenticated office delivery projection, scope and receipt acceptance."""
import base64, copy, hashlib, http.cookiejar, json, os, pathlib, shutil, socket, subprocess, tempfile, time, urllib.error, urllib.request
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='office-delivery-') as folder:
    root=pathlib.Path(folder);app=root/'app'
    shutil.copytree(ROOT,app,ignore=shutil.ignore_patterns('.git','node_modules','__pycache__','tmp'))
    private=root/'transtrade_private';private.mkdir()
    users=[{'id':1,'username':'owner','role':'Super Admin','active':True,'session_version':1},
           {'id':2,'username':'tti','role':'Accounts','active':True,'session_version':1,'permissions':{'Accounts':{'home':['View'],'entity-tti':['View'],'entity-brm':[],'entity-tg':[]}}},
           {'id':3,'username':'brm','role':'Accounts','active':True,'session_version':1,'permissions':{'Accounts':{'home':['View'],'entity-brm':['View'],'entity-tti':[],'entity-tg':[]}}}]
    (private/'auth.json').write_text(json.dumps({'users':users,'masters':{},'audit':[],'settings':{'qa_account_seeded':True,'master_permission_schema':2}}))
    original={'contracts':[{'id':'C','ref':'TTI/FIX/01','seller':'TTI'}],'shipments':[{'id':'L','contractRef':'TTI/FIX/01','lotId':'L01','completed':True}]}
    (private/'operations.json').write_text(json.dumps({'revision':1,'values':{'transtrade_export_v3_operational':json.dumps(original)},'meta':{}}))
    (private/'accounts.json').write_text('{"revision":1,"journals":{}}')
    token='a'*64;(private/'office-backup-token.sha256').write_text(hashlib.sha256(token.encode()).hexdigest())
    jobs=private/'office_agent_jobs';jobs.mkdir();file={'name':'Fixture.pdf','folder':'','storedName':'000-Fixture.pdf','size':12,'sha256':hashlib.sha256(b'fixture data').hexdigest()}
    def putjob(i,status,date,proof):
        job={'id':i,'type':'shipment_archive','status':status,'contract':'TTI/FIX/01','lot':'L01','createdAt':date,'files':[file],'verifiedFiles':proof}
        path=jobs/i;path.mkdir(exist_ok=True);(path/'job.json').write_text(json.dumps(job));return path/'job.json'
    receipt={k:v for k,v in file.items() if k!='storedName'}
    verified=putjob('b'*32,'VERIFIED','2026-10-19T10:00:00Z',[receipt]);pending=putjob('c'*32,'PENDING','2026-10-20T10:00:00Z',[])
    (app/'fixture-session.php').write_text("<?php require __DIR__.'/auth_store.php';$_SESSION=['user_id'=>(int)($_GET['id']??1),'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];echo '{}';")
    env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
    with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    log=open(root/'server.log','w');server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(app)],env=env,stdout=log,stderr=log)
    opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));base=f'http://127.0.0.1:{port}'
    def request(path,body=None,headers=None):
        data=None if body is None else json.dumps(body).encode()
        try:
            with opener.open(urllib.request.Request(base+path,data=data,headers={'Content-Type':'application/json',**(headers or {})}),timeout=15) as response:return response.status,json.loads(response.read())
        except urllib.error.HTTPError as response:return response.code,json.loads(response.read())
    def source():return tuple((private/name).read_bytes() for name in ['auth.json','accounts.json','operations.json'])
    try:
        for _ in range(60):
            try:request('/fixture-session.php?id=1');break
            except urllib.error.URLError:time.sleep(.05)
        before=source();query='/api/office_agent.php?action=shipment-status&contract=TTI%2FFIX%2F01&lot=L01'
        status,data=request(query);assert status==200 and data['job']['status']=='PENDING' and not data['job']['receiptVerified']
        status,data=request('/api/office_agent.php?action=shipment-status&id='+'b'*32);assert status==200 and data['job']['receiptVerified'] and data['job']['verifiedFileCount']==1
        assert 'sha256' not in json.dumps(data) and 'storedName' not in json.dumps(data),'Public status must not disclose storage metadata'
        assert source()==before,'Status GET must not mutate original stores'
        request('/fixture-session.php?id=2');assert request(query)[0]==200
        request('/fixture-session.php?id=3');assert request(query)[0]==403;assert request('/api/office_agent.php?action=shipment-status&id='+'b'*32)[0]==403
        request('/fixture-session.php?id=1')
        old=json.loads(verified.read_text());old['verifiedFiles']=[];verified.write_text(json.dumps(old))
        assert request('/api/office_agent.php?action=shipment-status&id='+'b'*32)[1]['job']['status']=='UNVERIFIED'
        assert request('/api/office_agent.php?action=shipment-status&id=../auth')[0]==422
        before=pending.read_bytes();headers={'X-Forwarded-Proto':'https','X-TT-Backup-Token':token}
        # Only authenticated Super Admin can independently enroll the existing PC password.
        password='fixture-existing-office-§-password'
        owner={'csrf':'fixture','action':'pin-office-recovery','password':password,'confirmationPassword':password}
        request('/fixture-session.php?id=2');assert request('/api/backup.php',owner)[0]==403
        request('/fixture-session.php?id=1');assert request('/api/backup.php',{**owner,'csrf':'bad'})[0]==419
        assert not (private/'office-backup-policy.json').exists()
        status,data=request('/api/backup.php',owner);assert status==200 and data['officeSecurity']['passwordPinned'],(status,data)
        assert password not in (private/'office-backup-policy.json').read_text()
        activate={'csrf':'fixture','action':'require-office-delivery-proof'}
        assert request('/api/backup.php',activate)[0]==422
        # Existing protocol2 requests already contain the configured password.
        for action in ['prepare','status','chunk','complete']:
            body={'password':'attacker-chosen-password','job':'d'*32,'offset':0,'length':1,'sha256':'0'*64}
            status,data=request('/api/office_backup.php?action='+action,body,headers);assert status==403,(action,status,data)
            good={**body,'password':password}
            expected=503 if action=='prepare' else 409
            status,data=request('/api/office_backup.php?action='+action,good,headers);assert status==expected,(action,status,data)
        # New Python agent sends ASCII-safe independent proof for Unicode passwords.
        proof={**headers,'X-TT-Office-Recovery':base64.b64encode(password.encode()).decode()}
        assert request('/api/office_agent.php?action=poll',headers=proof)[0]==200
        assert request('/api/backup.php',activate)[0]==200
        policy_before=(private/'office-backup-policy.json').read_bytes()
        for action in ['poll','file&id='+'c'*32+'&index=0','ack']:
            body={'id':'c'*32,'status':'COMPLETE','files':[receipt]} if action=='ack' else None
            status,data=request('/api/office_agent.php?action='+action,body,headers);assert status==403,(action,status,data)
            assert pending.read_bytes()==before
        assert request('/api/office_agent.php?action=poll',headers=proof)[0]==200
        assert (private/'office-backup-policy.json').read_bytes()==policy_before
        # Bearer requests cannot pin a password or change the enforcement policy.
        request('/fixture-session.php?id=0');assert request('/api/backup.php',owner,proof)[0]==401
        assert (private/'office-backup-policy.json').read_bytes()==policy_before
        request('/fixture-session.php?id=1');headers=proof
        for bad in [[],[dict(receipt,size=11)],[dict(receipt,sha256='0'*64)],[receipt,receipt]]:
            status,data=request('/api/office_agent.php?action=ack',{'id':'c'*32,'status':'COMPLETE','files':bad},headers)
            assert status==422 and not data['ok'] and pending.read_bytes()==before,(status,data)
        status,data=request('/api/office_agent.php?action=ack',{'id':'c'*32,'status':'COMPLETE','files':[receipt]},headers);assert status==200 and data['job']['status']=='VERIFIED',(status,data)
        assert (private/'operations.json').read_text()==json.dumps({'revision':1,'values':{'transtrade_export_v3_operational':json.dumps(original)},'meta':{}})
        managed='fixture-central-backup-password'
        setting={'action':'set-backup-password','csrf':'fixture','password':managed,'confirmationPassword':managed}
        request('/fixture-session.php?id=2');assert request('/api/backup.php',setting)[0]==403
        request('/fixture-session.php?id=1');assert request('/api/backup.php',{**setting,'csrf':'bad'})[0]==419
        assert request('/api/backup.php',{**setting,'confirmationPassword':'different'})[0]==422
        assert not (private/'backup-password.json').exists()
        assert request('/api/backup.php',setting)[0]==200
        status,data=request('/api/backup.php?action=status');assert status==200 and data['officeSecurity']['sharedPasswordConfigured']
        assert managed not in json.dumps(data) and managed not in (private/'backup-password.json').read_text() and managed not in (private/'auth.json').read_text()
        request('/fixture-session.php?id=0')
        token_only={'X-Forwarded-Proto':'https','X-TT-Backup-Token':token}
        assert request('/api/office_agent.php?action=poll',headers=token_only)[0]==200
        assert request('/api/office_agent.php?action=poll',headers={**token_only,'X-TT-Backup-Token':'0'*64})[0]==403
        body={'protocol':2,'password':'old-PC-password'}
        assert request('/api/office_backup.php?action=prepare',body,token_only)[0]==503
        assert request('/api/office_backup.php?action=prepare',body,{**token_only,'X-TT-Backup-Token':'0'*64})[0]==403
        assert request('/api/backup.php',setting,token_only)[0]==401
        print('PASS actual-auth office delivery: pending versus matching receipt, latest package, company denial, invalid claims unchanged, complete acknowledgement and zero financial/source writes')
    finally:server.terminate();server.wait(timeout=10);log.close()
