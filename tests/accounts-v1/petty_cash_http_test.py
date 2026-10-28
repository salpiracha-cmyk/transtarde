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
        fixture['salaryMasters']={'HASSAN':{'id':'HASSAN','entity':'TTI','name':'Hassan','category':'OFFICE_STAFF','status':'Active','effectiveTo':'2026-07-31'},'BALOCH':{'id':'BALOCH','entity':'TTI','name':'Baloch','category':'OFFICE_STAFF','status':'Active'},'AHMED':{'id':'AHMED','entity':'TTI','name':'Ahmed','category':'OFFICE_STAFF','status':'Active'},'OTHER':{'id':'OTHER','entity':'BRM','name':'Other','category':'OFFICE_STAFF','status':'Active'}}
        fixture['journals']['CASH']={'id':'CASH','entity':'TTI','status':'Posted','date':'2026-07-01','lines':[{'account':'1120','debit':10000,'credit':0},{'account':'3400','debit':0,'credit':10000}]}
        fixture['journals']['MILL']={'id':'MILL','entity':'TTI','status':'Posted','date':'2026-07-02','lines':[{'account':'1120','millLocationId':'M1','debit':2000,'credit':0},{'account':'1120','debit':0,'credit':2000}]}
        fixture['journals']['UNDATED']={'id':'UNDATED','entity':'TTI','status':'Posted','date':'','lines':[{'account':'1120','debit':900,'credit':0}]}
        fixture['journals']['BAD-DATE']={'id':'BAD-DATE','entity':'TTI','status':'Posted','date':'20260-01-01','lines':[{'account':'1120','debit':900,'credit':0}]}
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
        original_expense=res['result']['generalExpenseId'];original_post=res['result']['journalId']
        amendment={**b,'action':'amend_expense','id':original_expense,'reason':'Correct narration only','paymentNarration':'Amended stationery narration','requestKey':'staff-narration-amend'}
        st,res=request('/api/expenses_v1.php?entity=TTI',amendment);assert st==200,(st,res)
        amended=json.loads(books.read_text());assert amended['generalExpenses'][original_expense]['status']=='Amended'
        replacement=amended['generalExpenses'][res['result']['generalExpenseId']];assert replacement['paymentAccountId']==ah and replacement['paymentDate']=='2026-08-03'
        assert res['result']['publicPostId']==original_post
        stable=books.read_bytes();st,replay=request('/api/expenses_v1.php?entity=TTI',amendment);assert st==200 and books.read_bytes()==stable,(st,replay)
        d=get();assert next(x for x in d['holders'] if x['id']==ah)['balance']==200
        b={**b,'requestKey':'overdraw-ahmed','amount':201,'expenseLines':[{**b['expenseLines'][0],'amount':201}]}
        stable=books.read_bytes();st,res=request('/api/expenses_v1.php?entity=TTI',b);assert st in (403,422) and books.read_bytes()==stable,(st,res)
        st,res=request('/api/expenses_v1.php?entity=TTI');assert st==200 and any(x['id']==ah for x in res['paymentAccounts']) and not any(x['id']==h for x in res['paymentAccounts']) and any(x['id']==h for x in res['historicalCashSources'])
        assert next(x for x in res['paymentAccounts'] if x['id']=='CASH|TTI')['bookBalance']==3000
        stale={'entity':'TTI','revision':0,'action':'manage','requestKey':'stale-holder-request','operation':'add','staffId':'AHMED'};st,res=request('/api/petty_cash.php',stale);assert st==409
        st,res=request('/api/petty_cash.php?entity=BRM');assert st==200 and not res['holders'] and not res['rows']
        # Bank identities distinguish same-name banks without exposing balances in receipt labels.
        a=json.loads(auth.read_text());a['masters']={'banks':[{'id':'ASKARI-TTI','values':['Company Account','TTI','','Transtrade International','Askari Bank','','Pakistan','PKR','001122334455','','','','','Active']},{'id':'ASKARI-PERSONAL','values':['Personal Account','TTI','','Salman Piracha','Askari Bank','','Pakistan','PKR','009988776655','','','','','Active']}]};auth.write_text(json.dumps(a))
        d=get();label=next(x['name'] for x in d['receiptBanks'] if x['id']=='ASKARI-TTI');assert 'Transtrade International' in label and '001122334455' in label and 'balance' not in label.lower(),label
        assert not any(x['class']=='Expense' for x in d['receiptHeads'])
        r,b=post('post',type='RECEIPT',date='2026-08-04',to='CASH|TTI',receiptSource='OTHER',amount=500,payee='Owner',creditAccountCode='3100',receiptKind='OTHER',narration='Cash introduced by owner')
        assert [(x['account'],x['debit'],x['credit']) for x in r['journal']['lines']]==[('1120',500,0),('3100',0,500)]
        r,b=post('post',expected=422,type='RECEIPT',date='2026-08-04',to='CASH|TTI',receiptSource='OTHER',amount=5,payee='Shop refund',creditAccountCode='6900',receiptKind='OTHER',narration='Expense is not an ordinary receipt')
        r,b=post('post',type='RECEIPT',date='2026-08-04',to='CASH|TTI',receiptSource='OTHER',amount=5,payee='Shop refund',creditAccountCode='6900',receiptKind='EXPENSE_REFUND',narration='Explicit expense refund')
        assert r['journal']['lines'][1]['account']=='6900' and r['journal']['lines'][1]['credit']==5
        r,b=post('post',type='RECEIPT',date='2026-08-04',to='CASH|TTI',receiptSource='BANK',bankId='ASKARI-TTI',amount=50,payee='Askari Transtrade',narration='Bank withdrawal to petty cash')
        assert r['journal']['lines'][1]['bankAccountId']=='ASKARI-TTI' and r['journal']['lines'][1]['credit']==50
        # The same receipt-credit validation applies to Bank Entry, without expense dropdown defaults.
        st,bank=request('/api/bank_entries.php?entity=TTI');assert st==200 and not any(x['class']=='Expense' for x in bank['receiptHeads']),(st,bank)
        bankbody={'entity':'TTI','revision':bank['revision'],'action':'post','type':'RECEIPT','requestKey':'bank-other-receipt','date':'2026-08-05','bankId':'ASKARI-TTI','amount':100,'payee':'Owner','creditAccountCode':'3100','receiptKind':'OTHER','reference':'OWNER-RECEIPT','narration':'Owner funds'}
        st,res=request('/api/bank_entries.php',bankbody);assert st==200,(st,res)
        assert any(l['account']=='3100' and l['credit']==100 for l in res['result']['journal']['lines'])
        # Sales tax preview is zero-write; final receipt/retry preserves one public Post ID.
        st,tax=request('/api/sales_tax_refunds.php?entity=TTI');assert st==200,(st,tax)
        taxbody={'entity':'TTI','revision':tax['revision'],'requestKey':'government-refund-once','receiptDate':'2026-08-06','bankAccountId':'ASKARI-TTI','amount':1250,'reference':'FBR-REFUND','note':'Historical receipt'}
        stable=books.read_bytes();seq=json.loads(auth.read_text()).get('post_sequence');st,pre=request('/api/sales_tax_refunds.php',{**taxbody,'preview':True});assert st==200 and books.read_bytes()==stable and json.loads(auth.read_text()).get('post_sequence')==seq,(st,pre)
        assert [(l['account'],l['debit'],l['credit']) for l in pre['previews'][0]['lines']]==[('1110',1250,0),('1230',0,1250)]
        st,refund=request('/api/sales_tax_refunds.php',taxbody);assert st==200,(st,refund);stable=books.read_bytes();st,replay=request('/api/sales_tax_refunds.php',taxbody);assert st==200 and replay['journalId']==refund['journalId'] and books.read_bytes()==stable
        st,replay=request('/api/sales_tax_refunds.php',{**taxbody,'amount':1300});assert st==409
        # Petty household payments have exactly the same accounting as Pay Expense.
        saved_books=json.loads(books.read_text());saved_books['salaryMasters']['SALMAN-DIRECTOR']={'id':'SALMAN-DIRECTOR','entity':'TTI','name':'Salman','category':'DIRECTOR_REMUNERATION','status':'Active'};books.write_text(json.dumps(saved_books))
        d=get();assert {x['id'] for x in d['homeLedgers']}=={'SHARED','FAM-SALMAN','FAM-TALHA','FAM-TAYYAB'}
        assert any(x['id']=='TTI-EXPENSE-TYPE-ARP' for x in d['arpSubaccounts'])
        def amounts(preview):
            return [[{k:l.get(k) for k in ['account','debit','credit','expenseFor','personalTreatment','subaccountId','cashAccountId','paymentAccountId']} for l in j['lines']] for j in preview['previews']]
        for n,(person,treatment) in enumerate([('SHARED','COMPANY'),('FAM-SALMAN','COMPANY'),('FAM-TALHA','CASH'),('FAM-SALMAN','REMUNERATION')]):
            d=get();body={'entity':'TTI','revision':d['revision'],'action':'post','type':'EXPENSE','requestKey':f'petty-household-{n}','date':'2026-08-08','from':'CASH|TTI','amount':20,'payee':'Grocery fixture','narration':'Household groceries','expenseCategory':'HOME','expenseFor':person,'personalTreatment':treatment}
            stable=books.read_bytes();sequence=json.loads(auth.read_text()).get('post_sequence');st,pre=request('/api/petty_cash.php',{**body,'preview':True});assert st==200,(st,pre)
            standard={'entity':'TTI','action':'pay_expense','requestKey':f'standard-household-{n}','paymentDate':body['date'],'paymentAccountId':body['from'],'payee':body['payee'],'paymentNarration':body['narration'],'expenseLines':[{'category':'HOME','accountCode':'6910','subaccountId':'TTI-HOME-'+person,'amount':20,'purpose':body['narration'],'expenseArea':'HOME','expenseFor':person,'personalTreatment':treatment}],'preview':True}
            st,regular=request('/api/expenses_v1.php?entity=TTI',standard);assert st==200 and amounts(pre)==amounts(regular),(st,pre,regular)
            assert books.read_bytes()==stable and json.loads(auth.read_text()).get('post_sequence')==sequence
            old_balance=d['officeBalance'];st,paid=request('/api/petty_cash.php',body);assert st==200 and paid['officeBalance']==old_balance-20,(st,paid)
            stable=books.read_bytes();st,retry=request('/api/petty_cash.php',body);assert st==200 and books.read_bytes()==stable and retry['result']==paid['result']
            state=json.loads(books.read_text());record=state['generalExpenses'][paid['result']['generalExpenseId']];row=record['expenseLines'][0]
            assert row['account']=='6910' and row['subaccountId']=='TTI-HOME-'+person and row['expenseFor']==person and row['personalTreatment']==treatment
            assert len(record['personalJournalIds'])==(0 if treatment=='COMPANY' else 1)
            if treatment=='REMUNERATION':assert any(a.get('personalLink')==record['personalLink'] and a['recoveryFromMonth']=='2026-09' and a['remaining']==20 for a in state['salaryAdvances'].values())
        for patch in [{'expenseFor':'OTHER'},{'expenseFor':'SHARED','personalTreatment':'CASH'},{'expenseCategory':'ARP','arpSubaccountId':'BRM-EXPENSE-TYPE-ARP'},{'expenseCategory':'UNKNOWN'}]:
            bad={**body,**patch,'revision':get()['revision'],'requestKey':'invalid-household-'+str(time.time_ns())};stable=books.read_bytes();st,rejected=request('/api/petty_cash.php',bad);assert st==422 and books.read_bytes()==stable,(st,rejected)
        r,b=post('post',type='EXPENSE',date='2026-08-08',**{'from':ah},amount=20,payee='Home shop',expenseCategory='HOME',expenseFor='FAM-TAYYAB',personalTreatment='COMPANY',narration='Staff home errand',confirmed=True)
        assert next(x for x in r['holders'] if x['id']==ah)['balance']==180 and r['journal']['lines'][-1]['cashAccountId']==ah
        r,b=post('post',type='EXPENSE',date='2026-08-08',**{'from':'CASH|TTI'},amount=10,payee='ARP shop',expenseCategory='ARP',personalTreatment='COMPANY',narration='ARP household expense')
        assert r['journal']['lines'][0]['expenseFor']=='FAM-ABU' and r['journal']['lines'][0]['subaccountId']=='TTI-EXPENSE-TYPE-ARP'
        # Staff permissions and company scope are enforced on the real endpoint.
        limited={**user,'role':'Staff','permissions':{'Accounts':{'expenses':['View']}},'entities':['TTI']}
        a=json.loads(auth.read_text());a['users']=[limited];auth.write_text(json.dumps(a))
        st,res=request('/api/petty_cash.php',{'entity':'TTI','action':'manage','csrf':'fixture','requestKey':'forbidden-change','operation':'add','staffId':'AHMED'});assert st==403,(st,res)
        print('Petty cash HTTP lifecycle, expense integration, scope, retries and zero-write preview passed')
    finally:
        server.terminate();server.wait(timeout=10);log.close()
