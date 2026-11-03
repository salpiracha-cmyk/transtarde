"""Office-to-mill cash custody through the real endpoint, canonical ledgers and reversal guards."""
import copy, http.cookiejar, json, os, pathlib, shutil, socket, subprocess, tempfile, time, urllib.error, urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='petty-cash-') as directory:
    root=pathlib.Path(directory); app=root/'app'
    shutil.copytree(ROOT,app,ignore=shutil.ignore_patterns('.git','node_modules','__pycache__'))
    private=root/'transtrade_private'; private.mkdir()
    user={'id':1,'username':'fixture','full_name':'Fixture Owner','role':'Super Admin','active':True,'session_version':1}
    auth=private/'auth.json'; auth.write_text(json.dumps({'users':[user],'masters':{},'audit':[],'settings':{'qa_account_seeded':True,'master_permission_schema':2}}))
    books=private/'accounts.json'; books.write_text(json.dumps({'revision':1,'journals':{'ORIGINAL':{'id':'ORIGINAL','entity':'TTI','status':'Posted','date':'2026-07-01','lines':[{'account':'1110','debit':100,'credit':0},{'account':'3100','debit':0,'credit':100}]}}}))
    (app/'fixture-session.php').write_text("<?php require __DIR__.'/auth_store.php';$_SESSION=['user_id'=>1,'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];echo 'ready';")
    env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
    with socket.socket() as sock: sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
    log=open(root/'server.log','w'); server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(app)],env=env,stdout=log,stderr=log)
    opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())); base=f'http://127.0.0.1:{port}'
    def request(path='/api/masters.php',body=None):
        data=None if body is None else json.dumps({'csrf':'fixture',**body}).encode()
        try:
            with opener.open(urllib.request.Request(base+path,data=data,headers={'Content-Type':'application/json'}),timeout=15) as response:
                raw=response.read(); return response.status,json.loads(raw) if raw.startswith(b'{') else raw
        except urllib.error.HTTPError as response: return response.code,json.loads(response.read())
    try:
        for _ in range(60):
            try: request('/fixture-session.php'); break
            except urllib.error.URLError: time.sleep(.05)
        a=json.loads(auth.read_text());a['masters']['mills']=[{'id':'M1','values':['Fixture Mill','','Own Mill','','','Active','','TTI']},{'id':'M2','values':['Foreign Mill','','Own Mill','','','Active','','BRM']},{'id':'M3','values':['Closed Mill','','Own Mill','','','Inactive','','TTI']},{'id':'M4','values':['Outsource Mill','','External Mill','','','Active','','TTI']}];auth.write_text(json.dumps(a))
        state=json.loads(books.read_text());state['journals']['FUND']={'id':'FUND','entity':'TTI','status':'Posted','date':'2026-07-01','lines':[{'account':'1120','debit':1000,'credit':0},{'account':'3400','debit':0,'credit':1000}]};books.write_text(json.dumps(state))
        def get():
            st,d=request('/api/petty_cash.php?entity=TTI');assert st==200,(st,d);return d
        def post(b,expected=200):
            stable=books.read_bytes();st,d=request('/api/petty_cash.php',b);assert st==expected,(st,d,b)
            if expected!=200 or b.get('preview'):assert books.read_bytes()==stable,'Rejected or preview operation wrote books'
            return d
        initial=get();assert 'M1' in [m['locationId'] for m in initial['mills']] and not any(m['locationId'] in ['M2','M3','M4'] for m in initial['mills']),initial['mills']
        b={'entity':'TTI','revision':initial['revision'],'action':'post','requestKey':'mill-transfer-exact-retry','type':'MILL_TRANSFER','date':'2026-08-01','from':'CASH|TTI','millLocationId':'M1','amount':400,'receivedBy':'Fixture Receiver','narration':'Cash delivered to mill'}
        seq=json.loads(auth.read_text()).get('post_sequence');preview=post({**b,'preview':True});assert json.loads(auth.read_text()).get('post_sequence')==seq
        lines=preview['previews'][0]['lines'];assert len(lines)==2 and all(l['account']=='1120' for l in lines);assert lines[0]['millLocationId']=='M1' and lines[0]['debit']==400 and lines[1]['credit']==400
        for patch in [{'millLocationId':'M2'},{'millLocationId':'M3'},{'millLocationId':'M4'},{'receivedBy':''},{'amount':1001},{'date':'2026-06-30'},{'from':'CASH|BRM'}]:post({**b,**patch,'requestKey':'invalid-mill-'+str(time.time_ns())},422)
        result=post(b);jid=result['result']['journalId'];assert result['officeBalance']==600 and result['mills'][0]['balance']==400
        j=result['journal'];assert j['reference']==jid and j['meta']['receivedBy']=='Fixture Receiver' and j['sourceType']=='PETTY_CASH_MILL_TRANSFER'
        stable=books.read_bytes();again=post(b);assert again['result']['journalId']==jid and books.read_bytes()==stable
        post({**b,'amount':300},409)
        st,mill=request('/api/mill_cash_ledger.php?entity=TTI&locationId=M1');assert st==200,(st,mill);assert any(r['postId']==jid and r['debit']==400 for r in mill.get('rows',[])),mill
        st,ledger=request('/api/accounts_ledger_browser.php?entity=TTI&account=CASH%7CTTI%7CMILL%7CM1&to=2026-12-31');assert st==200 and ledger['closing']==400,(st,ledger)
        # Mill spending remains on the original 1120 custody identity. Removing its funding must fail.
        state=json.loads(books.read_text());state['journals']['MILL-SPEND']={'id':'MILL-SPEND','entity':'TTI','status':'Posted','date':'2026-08-02','lines':[{'account':'6900','debit':100,'credit':0},{'account':'1120','millLocationId':'M1','debit':0,'credit':100}]};books.write_text(json.dumps(state))
        reverse={'entity':'TTI','revision':get()['revision'],'action':'reverse','requestKey':'mill-reverse-spent','journalId':jid,'reason':'Correct transfer entered to wrong mill'};post(reverse,422)
        state=json.loads(books.read_text());del state['journals']['MILL-SPEND'];books.write_text(json.dumps(state));reverse['requestKey']='mill-reverse-unspent';reverse['revision']=get()['revision'];rev=post(reverse);assert rev['officeBalance']==1000 and rev['mills'][0]['balance']==0
        state=json.loads(books.read_text());assert len([x for x in state['pettyCashAudit'] if x['action']=='MILL_TRANSFER'])==1 and any(x['action']=='MILL_TRANSFER_REVERSAL' for x in state['pettyCashAudit'])
        # Future office spending cannot be silently unfunded by a backdated transfer.
        state['journals']['FUTURE-OFFICE']={'id':'FUTURE-OFFICE','entity':'TTI','status':'Posted','date':'2026-08-10','lines':[{'account':'6900','debit':900,'credit':0},{'account':'1120','debit':0,'credit':900}]};books.write_text(json.dumps(state));post({**b,'requestKey':'mill-backdated-unfund','revision':get()['revision']},422)
        a=json.loads(auth.read_text());a['users']=[{**user,'role':'Staff','permissions':{'Accounts':{'expenses':['View']}},'entities':['TTI']}];auth.write_text(json.dumps(a));post({**b,'requestKey':'mill-no-create','revision':get()['revision']},403)
        print('PASS Office→Mill transfer: active company mills, receiver, two 1120 legs, shared mill and Accounts ledgers, zero-write preview, retry/conflict, overdrafts/date/future funding, authorized audited reversal and permission boundaries')
    finally:
        server.terminate();server.wait(timeout=10);log.close()
