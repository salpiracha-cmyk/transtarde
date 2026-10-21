"""Real authenticated operational GETs must require an application module grant."""
import json, os, pathlib, shutil, subprocess, tempfile

ROOT = pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tti-accounts-read-') as directory:
    root = pathlib.Path(directory)
    app = root / 'app'
    shutil.copytree(ROOT, app, ignore=shutil.ignore_patterns('.git', 'node_modules', '__pycache__'))
    private = root / 'transtrade_private'
    private.mkdir()
    harness = root / 'request.php'
    harness.write_text(r'''<?php
$_SERVER['REQUEST_URI']='/api/'.basename($argv[1]).'?'.$argv[4];parse_str($argv[4],$_GET);$_SERVER['REQUEST_METHOD']='GET';$_SERVER['REMOTE_ADDR']='192.0.2.42';
session_name('TRANSTRADE_SESSION');session_id('operations-read-'.bin2hex(random_bytes(12)));session_start();
$_SESSION=['user_id'=>(int)$argv[3],'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];session_write_close();
register_shutdown_function(static function()use($argv){file_put_contents($argv[2],(string)(http_response_code()?:200));});
require $argv[1];
''')
    auth=private/'auth.json';books=private/'accounts.json'
    env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
    def journal(id,source,marker,entity='TTI'):
        return {'id':id,'entity':entity,'date':'2026-09-12','sourceType':source,'reference':marker,'narration':marker,'status':'Posted',
            'totalDebit':100,'totalCredit':100,'lines':[{'account':'6700','accountName':'Expense','debit':100,'credit':0},{'account':'2140','accountName':'Payable','debit':0,'credit':100}],'meta':{}}
    journals={j['id']:j for j in [journal('E1','GENERAL_EXPENSE_PAYMENT','EXPENSE_SECRET'),journal('F1','FREIGHT_BILL','FREIGHT_SECRET'),journal('U1','HISTORICAL_UNKNOWN','UNKNOWN_SECRET'),journal('B1','GENERAL_EXPENSE_PAYMENT','OTHER_ENTITY_SECRET','BRM')]}
    journals['F1']['meta']['supplierBillId']='FB1'
    for id,source in [('S1','SUPPLIER_PAYMENT'),('T1','TRANSPORT_BILL'),('V1','CLEARING_BILL'),('P1','SALARY_PAYMENT'),('K1','BANK_RECON_DIRECT_ENTRY')]:
        journals[id]=journal(id,source,id+'_SECRET')
    journals['K1']['meta']['bankDirectType']='SAVING_PROFIT'
    native_sources={'DX':'DIRECT_EXPENSE_PAYMENT','SAL':'SALARY_ALLOCATED_PAYMENT','ADV':'SALARY_ADVANCE_OPENING','MILL':'MILL_BILL_LIABILITY','TGREM':'TG_REMITTANCE_CONFIRMED'}
    for id,source in native_sources.items():journals[id]=journal(id,source,id+'_NATIVE_SECRET')


    initial={'revision':8,'journals':journals,'events':{'EV':{'entity':'TTI','value':'RAW_EVENT_SECRET'}},
        'masters':{'bankAccounts':[{'name':'STORED_MASTER_SECRET'}]},
        'reminders':{'REM':{'entity':'TTI','status':'Open','text':'REMINDER_SECRET','amount':999}},
        'localSalesPaymentCandidates':{'PAY-C':{'id':'PAY-C','entity':'TTI','amount':50,'reference':'PENDING_PAYMENT_SECRET'}},
        'generalExpenses':{'E2':{'id':'E2','entity':'TTI','date':'2026-09-12','amount':50,'payee':'EXPENSE_UNPOSTED'}},
        'freightBillsV1':{'FB1':{'id':'FB1','entity':'TTI','shipmentId':'LOT1','invoiceNo':'FREIGHT_INVOICE','vendor':'Freight Party','billDate':'2026-09-12','acceptedLiability':100}},
        'supplierBills':{'FB1':{'id':'FB1','entity':'TTI','category':'FREIGHT','vendor':'Freight Party','postingJournalIds':['F1'],'sourceRecord':{'invoice':'FREIGHT_INVOICE'}}},
        'salaryMasters':{'PRIVATE':{'id':'PRIVATE','entity':'TTI','name':'SALARY_MASTER_SECRET','monthlySalary':999}},
        'arbitraryPrivateCollection':{'X':{'entity':'TTI','secret':'UNKNOWN_COLLECTION_SECRET'}},
        'expenses':{'ENTITYLESS':{'id':'ENTITYLESS','secret':'ENTITYLESS_SECRET'}}}
    books.write_text(json.dumps(initial));before=books.read_bytes()
    user={'id':1,'username':'fixture','full_name':'Fixture','role':'Staff','active':True,'session_version':1,'permissions':{}}
    def request(endpoint,permissions,query='entity=TTI',role='Staff'):
        user['permissions']=permissions;user['role']=role
        auth.write_text(json.dumps({'users':[user],'masters':{'banks':[{'id':'BANK-CHOICE','values':['Company Account','TTI','','Company','CHOICE BANK','','Pakistan','PKR','123456789','','','','','Active']}]},'audit':[]}))
        status_file=root/'status.txt'
        r=subprocess.run(['php','-d',f'session.save_path={root}',str(harness),str(app/'api'/endpoint),str(status_file),'1',query],env=env,text=True,capture_output=True)
        assert r.returncode==0,r.stdout+r.stderr
        assert books.read_bytes()==before,'Read or denial changed canonical books'
        try:return int(status_file.read_text()),json.loads(r.stdout)
        except Exception:raise AssertionError(r.stdout+r.stderr)
    def grant(icon=None):
        return {'Accounts':{'entity-tti':['View'],**({icon:['View']} if icon else {})}}
    for permissions in [grant(),grant('transport'),grant('expenses')]:
        st,data=request('accounts.php',permissions)
        assert st==200 and 'accountingMaster' in data,(st,data)
        assert data['reminders']=={'REM':{'entity':'TTI','status':'Open'}},data
        assert not any(secret in json.dumps(data) for secret in ['EXPENSE_SECRET','RAW_EVENT_SECRET','STORED_MASTER_SECRET','REMINDER_SECRET']),data
    for permissions in [grant(),grant('transport'),grant('expenses')]:
        assert request('accounts_reports.php',permissions)[0]==403
    for permissions,marker in [(grant(),'EXPENSE_SECRET'),(grant('transport'),'EXPENSE_SECRET'),(grant('expenses'),'FREIGHT_SECRET'),(grant('freight'),'EXPENSE_SECRET'),(grant('expenses'),'UNKNOWN_SECRET')]:
        st,data=request('accounts_search.php',permissions,'entity=TTI&q='+marker)
        assert st==200 and data['results']==[],(permissions,st,data)
    for permissions in [grant('expenses'),grant('reports')]:
        for marker in ['ENTITYLESS_SECRET','UNKNOWN_COLLECTION_SECRET','SALARY_MASTER_SECRET','OTHER_ENTITY_SECRET']:
            st,data=request('accounts_search.php',permissions,'entity=TTI&q='+marker);assert st==200 and data['results']==[],(marker,st,data)
    for icon,marker in [('expenses','EXPENSE_SECRET'),('expenses','EXPENSE_UNPOSTED'),('freight','FREIGHT_SECRET'),('customer','PENDING_PAYMENT_SECRET')]:
        st,data=request('accounts_search.php',grant(icon),'entity=TTI&q='+marker)
        assert st==200 and len(data['results'])==1,(icon,st,data)
        if icon=='freight':assert data['results'][0]['amendRecord']['invoiceNo']=='FREIGHT_INVOICE',data
    for icon,wanted,hidden in [('expenses','E1','F1'),('freight','F1','E1')]:
        query='entity=ALL&account=POSTS&from=2026-01-01&to=2026-12-31'
        st,data=request('accounts_ledger_browser.php',grant(icon),query)
        assert st==200 and {r['voucher'] for r in data['rows']}==({wanted,'P1','DX','SAL','ADV','MILL'} if icon=='expenses' else {wanted}),(icon,st,data)
        assert data['visibilityScope']=='Permitted workflows' and data['accounts']==[{'code':'POSTS','name':'Post ID Register'}],data
        for post,expected in [(wanted,200),(hidden,404),('B1',404),('U1',404)]:
            st,data=request('accounts_ledger_browser.php',grant(icon),query+'&postId='+post);assert st==expected,(icon,post,st,data)
        st,limited=request('accounts_ledger_browser.php',grant(icon),'entity=TTI&account=6700&from=2026-01-01&to=2026-12-31')
        assert st==200 and limited['visibilityScope']=='Permitted workflows' and not any(hidden==row['voucher'] for row in limited['rows']),(st,limited)
    for icon,post in [('supplier','S1'),('transport','T1'),('services','V1'),('expenses','P1'),('cashbank','K1'),('reconciliation','K1'),('expenses','DX'),('expenses','SAL'),('expenses','ADV'),('expenses','MILL'),('tg','TGREM')]:
        st,data=request('accounts_ledger_browser.php',grant(icon),'entity=TTI&account=POSTS&postId='+post)
        assert st==200 and data['post']['id']==post,(icon,st,data)
    # Reversal ownership resolves the authoritative parent in the same company.
    reversal=journal('REV','RECEIPT_AMENDMENT_REVERSAL','REVERSAL_SECRET');reversal['reversalOf']='E1'
    initial['journals']['REV']=reversal;books.write_text(json.dumps(initial));before=books.read_bytes()
    st,data=request('accounts_search.php',grant('expenses'),'entity=TTI&q=REVERSAL_SECRET');assert st==200 and len(data['results'])==1,(st,data)
    st,data=request('accounts_search.php',grant('freight'),'entity=TTI&q=REVERSAL_SECRET');assert st==200 and not data['results'],(st,data)
    del initial['journals']['REV'];books.write_text(json.dumps(initial));before=books.read_bytes()
    for permissions,role in [(grant('reports'),'Staff'),({'Accounts':['View']},'Staff'),({'Accounts':'all'},'Staff'),({},'Director'),({},'Super Admin')]:
        st,data=request('accounts_reports.php',permissions,'entity=TTI&from=2026-01-01&asOf=2026-12-31',role)
        assert st==200 and data['trialBalance']['balanced'] and data['trialBalance']['totalDebit']==1300,(role,st,data)
        st,data=request('accounts_ledger_browser.php',permissions,'entity=TTI&account=POSTS&from=2026-01-01&to=2026-12-31',role)
        assert st==200 and {r['voucher'] for r in data['rows']}==set(journals)-{'B1'},(role,st,data)
    st,data=request('bank_accounts.php',grant('supplier'))
    assert st==200 and any(row['id']=='BANK-CHOICE' for row in data['accounts']),(st,data)
    st,data=request('accounts_dashboard.php',grant())
    assert st==200 and all(not rows for rows in data['summaries'].values()) and not data['attention'],(st,data)
    print('PASS Accounts read boundaries: safe bootstrap, exact Reports View, own-workflow search and register/detail, private/unknown/entityless/other-company isolation; full financial totals and legacy/Director/SA preserved; books unchanged')
