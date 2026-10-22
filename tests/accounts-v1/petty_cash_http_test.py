"""Petty cash lifecycle, original expense posting and zero-write review."""
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
        fixture=json.loads(books.read_text())
        fixture['salaryMasters']={'HASSAN':{'id':'HASSAN','entity':'TTI','name':'Hassan','category':'OFFICE_STAFF','status':'Active'},'BALOCH':{'id':'BALOCH','entity':'TTI','name':'Baloch','category':'OFFICE_STAFF','status':'Active'},'AHMED':{'id':'AHMED','entity':'TTI','name':'Ahmed','category':'OFFICE_STAFF','status':'Active'},'OTHER':{'id':'OTHER','entity':'BRM','name':'Other','category':'OFFICE_STAFF','status':'Active'}}
        fixture['journals']['CASH']={'id':'CASH','entity':'TTI','status':'Posted','date':'2026-07-01','lines':[{'account':'1120','debit':10000,'credit':0},{'account':'3400','debit':0,'credit':10000}]}
        fixture['journals']['MILL']={'id':'MILL','entity':'TTI','status':'Posted','date':'2026-07-02','lines':[{'account':'1120','millLocationId':'M1','debit':2000,'credit':0},{'account':'1120','debit':0,'credit':2000}]}
        books.write_text(json.dumps(fixture))
        def get():
            st,d=request('/api/petty_cash.php?entity=TTI');assert st==200,(st,d);return d
        def post(action,expected=200,**values):
            d=get();b={'entity':'TTI','revision':d['revision'],'action':action,'requestKey':'fixture-'+str(time.time_ns()),**values}
            st,res=request('/api/petty_cash.php',b);assert st==expected,(st,res,b);return res,b
        stable=books.read_bytes();d=get();assert d['officeBalance']==8000 and not d['holders'];assert stable==books.read_bytes()
        r,b=post('manage',operation='add',staffId='HASSAN');h=r['result']['holderId'];assert r['holders'][0]['balance']==0
        r,b=post('manage',expected=422,operation='add',staffId='OTHER')
        r,b=post('manage',operation='add',staffId='BALOCH');bal=r['result']['holderId']
        d=get();b={'entity':'TTI','revision':d['revision'],'action':'post','requestKey':'transfer-to-hassan','type':'TRANSFER','date':'2026-08-01','from':'CASH|TTI','to':h,'amount':5000,'narration':'Cash given on account'}
        stable=books.read_bytes();sequence=json.loads(auth.read_text()).get('post_sequence');st,res=request('/api/petty_cash.php',{**b,'preview':True});assert st==200 and len(res['previews'])==1,(st,res)
        assert books.read_bytes()==stable and json.loads(auth.read_text()).get('post_sequence')==sequence
        assert all(l['account']=='1120' for l in res['previews'][0]['lines'])
        st,res=request('/api/petty_cash.php',b);assert st==200,(st,res);transfer=res['result']['journalId'];assert res['officeBalance']==3000 and next(x for x in res['holders'] if x['id']==h)['balance']==5000
        stable=books.read_bytes();st,replay=request('/api/petty_cash.php',b);assert st==200 and replay['result']['journalId']==transfer and books.read_bytes()==stable
        st,replay=request('/api/petty_cash.php',{**b,'amount':4000});assert st==409
        r,b=post('post',expected=422,type='EXPENSE',date='2026-08-01',**{'from':h},amount=4700,payee='Hassan',accountCode='6900',narration='Verbally confirmed',confirmed=False)
        r,b=post('post',type='EXPENSE',date='2026-08-01',**{'from':h},amount=4700,payee='Hassan',accountCode='6900',narration='Verbally confirmed',confirmed=True)
        assert next(x for x in r['holders'] if x['id']==h)['balance']==300
        j=r['journal'];assert j['sourceType']=='DIRECT_EXPENSE_PAYMENT' and j['lines'][0]['account']=='6900' and j['lines'][-1]['cashAccountId']==h
        assert len(json.loads(books.read_text())['generalExpenses'])==1
        r,b=post('post',expected=422,type='TRANSFER',date='2026-07-15',**{'from':h},to=bal,amount=50,narration='Cannot spend future cash')
        r,b=post('post',expected=422,type='TRANSFER',date='2026-08-02',**{'from':h},to=bal,amount=301,narration='Too much')
        r,b=post('reverse',expected=422,journalId=transfer,reason='Cannot remove funding already spent')
        r,b=post('manage',operation='inactive',holderId=h);assert not next(x for x in r['holders'] if x['id']==h)['active'] and next(x for x in r['holders'] if x['id']==h)['balance']==300
        r,b=post('post',expected=422,type='TRANSFER',date='2026-08-02',**{'from':'CASH|TTI'},to=h,amount=100,narration='No new cash for inactive staff')
        r,b=post('manage',operation='add',staffId='AHMED');ah=r['result']['holderId'];assert ah!=h
        r,b=post('post',type='TRANSFER',date='2026-08-02',**{'from':h},to=ah,amount=300,narration='Hassan hands remaining cash to Ahmed')
        assert next(x for x in r['holders'] if x['id']==h)['balance']==0 and next(x for x in r['holders'] if x['id']==ah)['balance']==300
        assert next(x for x in r['holders'] if x['id']==h)['name']=='Hassan'
        # Large expenses use the same holder and register through the original Expenses API.
        b={'entity':'TTI','action':'pay_expense','requestKey':'large-expense-ahmed','paymentDate':'2026-08-03','paymentAccountId':ah,'payee':'Stationery shop','amount':100,'expenseLines':[{'category':'OFFICE','amount':100,'purpose':'Stationery','expenseArea':'OFFICE','expenseFor':'SHARED','personalTreatment':'COMPANY'}]}
        stable=books.read_bytes();seq=json.loads(auth.read_text()).get('post_sequence');st,pre=request('/api/expenses_v1.php?entity=TTI',{**b,'preview':True});assert st==200 and books.read_bytes()==stable and json.loads(auth.read_text()).get('post_sequence')==seq,(st,pre)
        st,res=request('/api/expenses_v1.php?entity=TTI',b);assert st==200,(st,res)
        d=get();assert next(x for x in d['holders'] if x['id']==ah)['balance']==200
        b={**b,'requestKey':'overdraw-ahmed','amount':201,'expenseLines':[{**b['expenseLines'][0],'amount':201}]}
        stable=books.read_bytes();st,res=request('/api/expenses_v1.php?entity=TTI',b);assert st in (403,422) and books.read_bytes()==stable,(st,res)
        st,res=request('/api/expenses_v1.php?entity=TTI');assert st==200 and any(x['id']==ah for x in res['paymentAccounts']) and not any(x['id']==h for x in res['paymentAccounts'])
        assert next(x for x in res['paymentAccounts'] if x['id']=='CASH|TTI')['bookBalance']==3000
        stale={'entity':'TTI','revision':0,'action':'manage','requestKey':'stale-holder-request','operation':'add','staffId':'AHMED'};st,res=request('/api/petty_cash.php',stale);assert st==409
        st,res=request('/api/petty_cash.php?entity=BRM');assert st==200 and not res['holders'] and not res['rows']
        # Staff permissions and company scope are enforced on the real endpoint.
        limited={**user,'role':'Staff','permissions':{'Accounts':{'expenses':['View']}},'entities':['TTI']}
        a=json.loads(auth.read_text());a['users']=[limited];auth.write_text(json.dumps(a))
        st,res=request('/api/petty_cash.php',{'entity':'TTI','action':'manage','csrf':'fixture','requestKey':'forbidden-change','operation':'add','staffId':'AHMED'});assert st==403,(st,res)
        print('Petty cash HTTP lifecycle, expense integration, scope, retries and zero-write preview passed')
    finally:
        server.terminate();server.wait(timeout=10);log.close()
