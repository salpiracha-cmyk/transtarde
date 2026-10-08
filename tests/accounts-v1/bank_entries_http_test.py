"""Execute real PHP endpoints against disposable sessions and company books."""
import json, pathlib, shutil, subprocess, tempfile

ROOT = pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tti-authorization-') as directory:
    root = pathlib.Path(directory)
    app = root / 'app'
    (app / 'api').mkdir(parents=True)
    (app / 'accounts').mkdir()
    (root / 'sessions').mkdir()
    private = root / 'transtrade_private'
    private.mkdir()
    for name in ['auth_store.php', 'master_store.php', 'product_stage.php', 'offline_idempotency.php','session_store.php']:
        shutil.copy(ROOT / name, app / name)
    for name in ['accounts_payees.php','bank_entries.php','bank_entries_core.php','accounts_subaccounts.php','accounts_ledger_browser.php','accounts_reports.php','accounts_reference.php','tg_remittance_core.php','fi_credit_advice_link.php','receipt_invoice_links.php','journal_vouchers.php','opening_balance_core.php','accounts_subaccounts_core.php','assets_registry_core.php','expenses_v1.php','direct_expense_core.php','accounts_bank_payment.php','expense_locations.php','expense_reminders.php','expense_reversals.php','donations.php','rent_salary_v2.php','salary_month_workflow.php','salary_master_store.php']:
        shutil.copy(ROOT / 'api' / name, app / 'api' / name)
    shutil.copy(ROOT / 'accounts/accounting_master_v1.json', app / 'accounts/accounting_master_v1.json')
    harness = root / 'request.php'
    harness.write_text('''<?php
$request=json_decode($argv[2],true);
ini_set('session.save_path',$argv[3]); session_name('TRANSTRADE_SESSION'); session_id('fixture-session'); session_start();
$_SESSION=['user_id'=>1,'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];session_write_close();
$_SERVER['REQUEST_URI']=$request['uri'];$_SERVER['REQUEST_METHOD']=$request['method'];$_SERVER['REMOTE_ADDR']='127.0.0.1';
parse_str((string)(parse_url($request['uri'],PHP_URL_QUERY)??''),$_GET);$_POST=[];
$GLOBALS['fixture_body']=json_encode($request['body']??[]);
class FixtureInput {public $context;private $offset=0;function stream_open($p,$m,$o,&$opened){return $p==='php://input';}function stream_read($n){$v=substr($GLOBALS['fixture_body'],$this->offset,$n);$this->offset+=strlen($v);return $v;}function stream_eof(){return $this->offset>=strlen($GLOBALS['fixture_body']);}function stream_stat(){return [];}}
stream_wrapper_unregister('php');stream_wrapper_register('php',FixtureInput::class);
register_shutdown_function(function(){echo "\\nHTTP_STATUS=".(http_response_code()?:200);});
require $argv[1];
''')
    auth = private / 'auth.json'
    books = private / 'accounts.json'
    permissions = {'entity-tti':['View','Create','Edit','Approve','Delete'], 'expenses':['View','Create','Edit'],'cashbank':['View','Create','Edit','Delete'],'masters':['View','Create','Edit','Delete'],'reports':['View'],'jv':['View','Create','Edit','Approve']}
    user = {'id':1,'username':'fixture','full_name':'Fixture','role':'Staff','active':True,'session_version':1,'permissions':{'Accounts':permissions}}
    def set_user():
        auth.write_text(json.dumps({'users':[user],'masters':{'banks':[{'id':'BANK-1','depositType':'SAVING','values':['Company Account','TTI','','Company','Fixture Bank','','','PKR','123456789','PK01TEST','','','','Active']},{'id':'BANK-2','values':['Company Account','TTI','','Company 2','Fixture Bank 2','','','PKR','234567890','PK02TEST','','','','Active']},{'id':'BANK-USD','values':['Company Account','TTI','','USD','FX Bank','','','USD','345678901','PK03TEST','','','','Active']},{'id':'BANK-BRM','values':['Company Account','BRM','','BRM','Other books','','','PKR','456789012','PK04TEST','','','','Active']}]},'audit':[]}))
        fixture=json.loads(auth.read_text());companies=[]
        for company_code in ['TTI','BRM']:
            banks=[]
            for bank in fixture['masters']['banks']:
                v=bank['values']
                if v[1]!=company_code:continue
                banks.append({'id':bank['id'],'accountType':'Company Account','depositType':bank.get('depositType','CURRENT'),'accountTitle':v[3],'bankName':v[4],'currency':v[7],'accountNumber':v[8],'iban':v[9],'status':'Active'})
            values=['']*21;values[0]=company_code;values[1]=company_code;values[2]='Pakistan';values[12]='Active';values[13]=json.dumps(banks);values[14]=json.dumps(['PKR','USD'])
            companies.append({'id':'company-'+company_code,'values':values})
        fixture['masters']['companies']=companies;auth.write_text(json.dumps(fixture))
    def request(endpoint, query='', body=None):
        uri = '/api/' + endpoint + '.php' + query
        req = {'uri':uri,'method':'GET' if body is None else 'POST','body':body}
        result = subprocess.run(['php',str(harness),str(app/'api'/f'{endpoint}.php'),json.dumps(req),str(root/'sessions')],capture_output=True,text=True)
        assert result.returncode==0,result.stdout+result.stderr
        payload, status = result.stdout.rsplit('\nHTTP_STATUS=',1)
        try: return int(status), json.loads(payload)
        except json.JSONDecodeError: raise AssertionError(result.stdout + result.stderr)
    for f in ROOT.joinpath('accounts').glob('*.json'): shutil.copy(f,app/'accounts'/f.name)
    set_user()
    books.write_text(json.dumps({'revision':0,'journals':{},'bankAccountSettings':{'BANK-1':{'defaultPaymentAccount':True}}}))
    counter=0
    def mutate(endpoint,**kwargs):
        global counter
        counter+=1
        _,current=request(endpoint,'?entity=TTI')
        body={'csrf':'fixture','entity':'TTI','requestKey':f'bank-fixture-{counter:05d}','revision':current['revision'],**kwargs}
        status,result=request(endpoint,'?entity=TTI',body)
        return status,result,body
    def good(endpoint='bank_entries',**kwargs):
        status,result,body=mutate(endpoint,**kwargs)
        assert status==200,(status,result,body)
        return result,body
    sub,b=good('accounts_subaccounts',operation='add',name='Office stationery',parentCode='6900',taxCategory='NONE');sid=sub['result']['subaccountId']
    assert mutate('accounts_subaccounts',operation='add',name='Office.  Stationery',parentCode='6900')[0]==422
    setup,_=good('accounts_payees',kind='EXPENSE',name='Caretaker',accountCode='6910',expenseCategory='HOME')
    pid=setup['result']['payee']['id']
    assert 'Caretaker' not in [x['values'][0] for x in json.loads(auth.read_text())['masters'].get('business_parties',[])], 'expense recipient must not become a Business Party'
    assert setup['result']['payee']['configured']
    bankpay={'action':'post','date':'2026-10-07','bankId':'BANK-1','reference':'MULTI-DEBIT','narration':'Home payments','type':'PAYMENT','amount':30,'bankPaymentMethod':'ONLINE_BANKING','debitRows':[{'payeeId':pid,'amount':10},{'payeeId':pid,'amount':20}]}
    multi,_=good(**bankpay);mj=multi['result']['journal']
    assert [(x['account'],x['debit'],x['credit']) for x in mj['lines']]==[('6910',10,0),('6910',20,0),('1110',0,30)]
    assert mj['lines'][-1]['bankName']=='Fixture Bank' and mj['lines'][-1]['accountNumber']=='123456789'
    before=books.read_bytes();assert mutate('bank_entries',**{**bankpay,'reference':'BAD-SPLIT','amount':31})[0]==422 and books.read_bytes()==before
    assert mutate('bank_entries',**{**bankpay,'reference':'UNCONFIGURED','debitRows':[{'payeeId':'EXP|talha','amount':30}]})[0]==422
    permissions['cashbank']=['View'];set_user();assert mutate('accounts_payees',kind='BUSINESS',name='Denied',accountCode='2190')[0]==403;permissions['cashbank']=['View','Create','Edit','Delete'];set_user()
    args={'action':'post','date':'2026-10-07','bankId':'BANK-1','reference':'PROFIT-1','narration':'Monthly gross saving profit','type':'SAVING_PROFIT','amount':1000,'withholdingTax':150}
    result,b=good(**args);j=result['result']['journal'];assert [(x['account'],x['debit'],x['credit']) for x in j['lines']]==[('1110',850,0),('1260',150,0),('4400',0,1000)]
    before=books.read_bytes();status,retry=request('bank_entries','?entity=TTI',b);assert status==200 and retry['result']==result['result'] and books.read_bytes()==before
    assert request('bank_entries','?entity=TTI',{**b,'amount':5})[0]==409 and books.read_bytes()==before
    assert request('bank_entries','?entity=TTI',{**b,'requestKey':'stale-fixture-key','revision':0})[0]==409
    assert mutate('bank_entries',**args)[0]==422
    assert mutate('bank_entries',**{**args,'reference':'WRONG-BANK','bankId':'BANK-BRM'})[0]==422
    assert mutate('bank_entries',**{**args,'reference':'CURRENT-PROFIT','bankId':'BANK-2'})[0]==422
    assert mutate('bank_entries',**{**args,'reference':'EXCESS-TAX','withholdingTax':1001})[0]==422
    charge,_=good(action='post',type='BANK_CHARGE',bankId='BANK-1',date='2026-10-07',reference='CARD-FEE',narration='Annual card charge',amount=20,subaccountId='TTI-DEFAULT-BANK_CARD')
    assert charge['result']['journal']['lines'][0]['subaccountId']=='TTI-DEFAULT-BANK_CARD'
    payment={'action':'post','type':'PAYMENT','bankId':'BANK-1','date':'2026-10-07','reference':'PAYMENT-1','narration':'Stationery purchase','amount':100,'subaccountId':sid,'payee':'Any recipient','bankPaymentMethod':'CHEQUE','chequeNo':'CH-1','chequeDate':'2026-10-07'}
    payment_result,_=good(**payment);j=payment_result['result']['journal'];assert j['lines'][0]['account']=='6900' and j['lines'][0]['subaccountId']==sid and j['lines'][1]['credit']==100
    before=books.read_bytes();assert mutate('bank_entries',**{**payment,'reference':'DUPLICATE-CHEQUE'})[0]==422 and books.read_bytes()==before
    assert mutate('bank_entries',**{**payment,'reference':'WRONG-COMPANY','bankId':'BANK-BRM'})[0]==422
    moved,_=good('accounts_subaccounts',operation='edit',id=sid,name='Stationery and maintenance',parentCode='6400',taxCategory='NONE')
    old=json.loads(books.read_text())['journals'][j['id']]['lines'][0];assert old['account']=='6900' and old['subaccountName']=='OFFICE STATIONERY'
    good(**{**payment,'reference':'PAYMENT-2','bankPaymentMethod':'ONLINE_BANKING','chequeNo':''})
    _,ledger=request('accounts_ledger_browser','?entity=TTI&account=SUB|'+sid+'&from=2026-01-01&to=2026-10-07');assert ledger['closing']==200 and len(ledger['rows'])==2
    assert {l['account'] for l in ledger['rows']}=={'6900','6400'}
    status,report=request('accounts_reports','?entity=TTI&asOf=2026-10-07&from=2026-01-01');assert status==200 and report['trialBalance']['totalDebit']==report['trialBalance']['totalCredit']
    tax=request('accounts_subaccounts','?entity=TTI&from=2026-01-01&to=2026-10-07')[1]['taxReport'];assert next(r for r in tax if r['category']=='SAVINGS' and r['treatment']=='Recoverable')['debit']==150
    transfer,_=good(action='post',type='TRANSFER',bankId='BANK-1',destinationBankId='BANK-2',date='2026-10-07',reference='TRANSFER-1',narration='Company bank transfer',amount=50,bankPaymentMethod='ONLINE_BANKING')
    assert [l['bankAccountId'] for l in transfer['result']['journal']['lines']]==['BANK-2','BANK-1']
    facility,_=good(action='facility',operation='add',name='Export refinance',bankId='BANK-1',reference='FAC-1');fid=facility['result']['facilityId']
    finance={'action':'post','type':'FINANCE_DRAW','bankId':'BANK-1','facilityId':fid,'date':'2026-10-06','reference':'DRAW-1','narration':'Export finance advance','amount':10000,'financeReference':'INST-1','exportReference':'FI-10'}
    draw,_=good(**finance);assert [l['account'] for l in draw['result']['journal']['lines']]==['1110','2610']
    repay,_=good(**{**finance,'type':'FINANCE_REPAY','date':'2026-10-07','reference':'REPAY-1','amount':3000,'bankPaymentMethod':'ONLINE_BANKING'})
    assert repay['facilities'][0]['outstanding']==7000
    assert mutate('bank_entries',**{**finance,'type':'FINANCE_REPAY','date':'2026-10-07','reference':'EXCESS-REPAY','amount':7001,'bankPaymentMethod':'ONLINE_BANKING'})[0]==422
    assert mutate('bank_entries',action='reverse',journalId=draw['result']['journalId'],date='2026-10-07',reason='Cannot strand principal repayment')[0]==422
    markup,_=good(**{**finance,'type':'FINANCE_MARKUP','date':'2026-10-07','reference':'MARKUP-1','amount':100,'subaccountId':'TTI-DEFAULT-FINANCE_MARKUP','bankPaymentMethod':'ONLINE_BANKING'})
    assert markup['facilities'][0]['outstanding']==7000 and markup['result']['journal']['lines'][0]['account']=='6800'
    good(action='reverse',journalId=repay['result']['journalId'],date='2026-10-07',reason='Correct principal repayment')
    good(action='reverse',journalId=draw['result']['journalId'],date='2026-10-07',reason='Correct finance drawdown')
    assert request('bank_entries','?entity=TTI')[1]['facilities'][0]['outstanding']==0
    fx,_=good(action='facility',operation='add',name='USD bank facility',bankId='BANK-USD');fxid=fx['result']['facilityId']
    good(**{**finance,'facilityId':fxid,'bankId':'BANK-USD','date':'2026-10-06','reference':'USD-DRAW','amount':100,'exchangeRate':280})
    fxrepay,_=good(**{**finance,'facilityId':fxid,'bankId':'BANK-USD','type':'FINANCE_REPAY','date':'2026-10-07','reference':'USD-REPAY','amount':100,'exchangeRate':281,'bankPaymentMethod':'ONLINE_BANKING'})
    assert fxrepay['result']['journal']['totalDebit']==fxrepay['result']['journal']['totalCredit']==28100
    assert next(l for l in fxrepay['result']['journal']['lines'] if l['account']=='7100')['debit']==100
    # Finance principal is a liability in financial reports, never export sales.
    good(**{**finance,'date':'2026-10-07','reference':'DRAW-NEW','amount':500})
    _,report=request('accounts_reports','?entity=TTI&asOf=2026-10-07&from=2026-01-01');assert any(str(r['account'])=='2610' and r['amount']==500 for r in report['balanceSheet']['liabilities']),report['balanceSheet']['liabilities']
    _,heads=request('accounts_subaccounts','?entity=TTI');assert next(h for h in heads['heads'] if h['code']=='2600')['balance']==-500
    _,finance_ledger=request('accounts_ledger_browser','?entity=TTI&account=HEAD|2600&from=2026-01-01&to=2026-10-07');assert finance_ledger['closing']==-500
    # One expense payment uses a named subaccount and keeps its single bank credit.
    ex={'action':'pay_expense','csrf':'fixture','entity':'TTI','requestKey':'expense-subaccount-01','paymentDate':'2026-10-07','payee':'Stationery shop','paymentAccountId':'BANK-1','reference':'EXP-SUB','amount':25,'bankPaymentMethod':'ONLINE_BANKING','expenseLines':[{'category':'OFFICE','purpose':'Repairs materials','amount':25,'subaccountId':sid}]}
    status,expense=request('expenses_v1','?entity=TTI',ex);assert status==200,(status,expense);j=json.loads(books.read_text())['journals'][expense['result']['journalId']];assert j['lines'][0]['account']=='6400' and j['lines'][0]['subaccountId']==sid and len(j['lines'])==2
    # Named accounts also work in the controlled JV, carrying their head snapshot.
    draft={'action':'submit_jv','csrf':'fixture','entity':'TTI','date':'2026-10-07','reference':'JV-SUB','narration':'Accrued repairs','lines':[{'account':'SUB|'+sid,'debit':10,'credit':0},{'account':'2140','subledger':'Supplier','debit':0,'credit':10}]}
    status,jv=request('journal_vouchers','?entity=TTI',draft);assert status==200,(status,jv);assert next(v for v in jv['jvs'] if v['id']==jv['result']['jvId'])['lines'][0]['subaccountId']==sid
    # Archive with balance preserves every posted ledger and report.
    good('accounts_subaccounts',operation='delete',id=sid)
    assert mutate('bank_entries',**{**payment,'reference':'ARCHIVED-SUB','chequeNo':'CH-99'})[0]==422
    _,ledger=request('accounts_ledger_browser','?entity=TTI&account=SUB|'+sid+'&from=2026-01-01&to=2026-10-07');assert ledger['closing']==225
    good('accounts_subaccounts',operation='edit',id=sid,name='Stationery and maintenance',parentCode='6400',taxCategory='NONE',active=True)
    child,_=good('accounts_subaccounts',operation='add',name='Printer supplies',parentCode='6400',parentId=sid,taxCategory='NONE');cid=child['result']['subaccountId']
    grand,_=good('accounts_subaccounts',operation='add',name='Toner supplies',parentCode='6400',parentId=cid,taxCategory='NONE');gid=grand['result']['subaccountId']
    before=books.read_bytes();assert mutate('accounts_subaccounts',operation='edit',id=sid,name='Stationery and maintenance',parentCode='6400',parentId=gid,taxCategory='NONE')[0]==422 and books.read_bytes()==before
    historical=json.loads(books.read_text())['journals']
    good('accounts_subaccounts',operation='edit',id=sid,name='Stationery and maintenance',parentCode='6900',taxCategory='NONE')
    _,tree=request('accounts_subaccounts','?entity=TTI');desc=next(x for x in tree['subaccounts'] if x['id']==gid);assert desc['parentCode']=='6900' and desc['parentId']==cid and 'TONER SUPPLIES' in desc['path']
    assert json.loads(books.read_text())['journals']==historical
    nested={**ex,'requestKey':'nested-expense-01','reference':'NESTED-EXP','expenseLines':[{'category':'OFFICE','purpose':'Printer toner','amount':25,'accountCode':'6900','subaccountId':gid}]}
    status,res=request('expenses_v1','?entity=TTI',nested);assert status==200,(status,res)
    _,parent_ledger=request('accounts_ledger_browser','?entity=TTI&account=SUB|'+sid+'&from=2026-01-01&to=2026-10-07');assert parent_ledger['closing']==250
    bad={**nested,'requestKey':'nested-expense-bad','reference':'BAD-NESTED','expenseLines':[{**nested['expenseLines'][0],'accountCode':'6400'}]};assert request('expenses_v1','?entity=TTI',bad)[0]==422
    good('accounts_subaccounts',operation='move_head',code='6400',parentCode='5200')
    _,heads=request('accounts_subaccounts','?entity=TTI');assert next(h for h in heads['heads'] if h['code']=='6400')['parent']=='5200'
    assert mutate('accounts_subaccounts',operation='move_head',code='5200',parentCode='6400')[0]==422
    assert mutate('accounts_subaccounts',operation='move_head',code='6400',parentCode='1000')[0]==422
    before=books.read_bytes();permissions['cashbank']=['View'];set_user();assert request('bank_entries','?entity=TTI',{**b,'requestKey':'readonly-bank-key'})[0]==403 and books.read_bytes()==before
    permissions['cashbank']=[];set_user();assert request('bank_entries','?entity=TTI')[0]==403
    permissions['cashbank']=['View','Create','Edit'];permissions['masters']=['View'];set_user();assert mutate('accounts_subaccounts',operation='add',name='Denied master',parentCode='6900')[0]==403
    assert request('bank_entries','?entity=BRM')[0]==403
    permissions['masters']=['View','Create','Edit','Delete'];set_user();assert request('bank_entries','?entity=TTI',{**b,'csrf':'bad'})[0]==419
    for endpoint in ['bank_entries','accounts_subaccounts']:
        books.write_text('{broken JSON');before=books.read_bytes();assert request(endpoint,'?entity=TTI')[0]==500 and books.read_bytes()==before
    print('Bank entries, finance, subaccounts, expense/JV/ledger/report links, retries, corrections, permissions and corrupt-store protection passed.')

