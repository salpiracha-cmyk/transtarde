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
    for name in ['accounts_subaccounts_core.php','assets_registry_core.php','expenses_v1.php','direct_expense_core.php','accounts_bank_payment.php','expense_locations.php','expense_reminders.php','expense_reversals.php','donations.php','rent_salary_v2.php','salary_month_workflow.php','salary_master_store.php','accounts_reports.php','accounts_ledger_browser.php','accounts_reference.php','tg_remittance_core.php','fi_credit_advice_link.php','receipt_invoice_links.php']:
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
    permissions = {'entity-tti':['View','Create','Edit','Approve'], 'expenses':['View','Create','Edit']}
    user = {'id':1,'username':'fixture','full_name':'Fixture','role':'Staff','active':True,'session_version':1,'permissions':{'Accounts':permissions}}
    def set_user():
        auth.write_text(json.dumps({'users':[user],'masters':{'banks':[{'id':'BANK-1','values':['Company Account','TTI','','Company','Fixture Bank','','','PKR','123456789','PK01TEST','','','','Active']}]},'audit':[]}))
    def request(endpoint, query='', body=None):
        uri = '/api/' + endpoint + '.php' + query
        req = {'uri':uri,'method':'GET' if body is None else 'POST','body':body}
        result = subprocess.run(['php',str(harness),str(app/'api'/f'{endpoint}.php'),json.dumps(req),str(root/'sessions')],capture_output=True,text=True)
        assert result.returncode==0,result.stdout+result.stderr
        payload, status = result.stdout.rsplit('\nHTTP_STATUS=',1)
        try: return int(status), json.loads(payload)
        except json.JSONDecodeError: raise AssertionError(result.stdout + result.stderr)
    set_user()
    initial = {'revision':0,'journals':{},'bankAccountSettings':{},'cashAccountSettings':{}}
    books.write_text(json.dumps(initial))
    body={'action':'pay_expense','csrf':'fixture','entity':'TTI','requestKey':'direct-fixture-0001','paymentDate':'2026-10-07','payee':'Graveyard caretaker','paymentAccountId':'CASH|TTI','reference':'EXP-1','amount':150.25,'expenseLines':[{'category':'HOME','purpose':'House maintenance','amount':100},{'category':'MEDICAL','medicalFor':'COMPANY_STAFF','purpose':'Staff medicine','amount':50.25}]}
    status,result=request('expenses_v1','?entity=TTI',body)
    assert status==200,(status,result)
    first=result['result'];store=json.loads(books.read_text());journal=store['journals'][first['journalId']]
    assert [x['account'] for x in journal['lines']]==['6910','6230','1120']
    assert journal['totalDebit']==journal['totalCredit']==150.25
    assert len([x for x in journal['lines'] if x['credit']>0])==1
    before=books.read_bytes();status,retry=request('expenses_v1','?entity=TTI',body)
    assert status==200 and retry['result']['journalId']==first['journalId']
    assert books.read_bytes()==before,'retry must not write again'
    changed={**body,'amount':151};assert request('expenses_v1','?entity=TTI',changed)[0]==409
    invalid={**body,'requestKey':'direct-invalid-0001','reference':'EXP-2','expenseLines':[{'category':'HOME','purpose':'Invalid','amount':-1}]}
    assert request('expenses_v1','?entity=TTI',invalid)[0]==422
    assert books.read_bytes()==before
    permissions['expenses']=['View'];set_user();assert request('expenses_v1','?entity=TTI',{**body,'requestKey':'direct-denied-0001'})[0]==403;assert books.read_bytes()==before
    permissions['expenses']=[];set_user();assert request('expenses_v1','?entity=TTI')[0]==403
    permissions['expenses']=['View','Create','Edit'];set_user()
    assert request('expenses_v1','?entity=BRM',{**body,'entity':'BRM','requestKey':'direct-denied-0002'})[0]==403
    correction={**body,'action':'amend_expense','id':first['generalExpenseId'],'reason':'Correct expense split','requestKey':'direct-amend-0001','amount':120,'expenseLines':[{'category':'HOME','purpose':'House maintenance corrected','amount':120}]}
    status,result=request('expenses_v1','?entity=TTI',correction);assert status==200,(status,result)
    replacement=result['result'];store=json.loads(books.read_text());assert store['generalExpenses'][first['generalExpenseId']]['status']=='Amended'
    assert len(store['journals'])==3
    balance=lambda:round(sum(float(l['debit'])-float(l['credit']) for j in json.loads(books.read_text())['journals'].values() for l in j['lines'] if l['account']=='1120'),2)
    assert balance()==-120
    stale={'action':'delete_expense','csrf':'fixture','entity':'TTI','collection':'generalExpenses','id':first['generalExpenseId'],'reason':'Remove stale expense'}
    assert request('expenses_v1','?entity=TTI',stale)[0]==403
    status,result=request('expenses_v1','?entity=TTI',{**stale,'id':replacement['generalExpenseId']});assert status==200,(status,result);assert balance()==0
    # Recipient is unrestricted; donation rows contribute to their category report.
    donation={**body,'requestKey':'direct-donation-0001','reference':'DON-1','payee':'Any charity','amount':75,'expenseLines':[{'category':'DONATION','donationType':'SADQA','purpose':'Food assistance','amount':75}]}
    assert request('expenses_v1','?entity=TTI',donation)[0]==200
    status,report=request('donations','?entity=TTI');assert status==200 and report['totals']['SADQA']==75
    bank={**body,'requestKey':'direct-cheque-0001','reference':'BANK-1','paymentAccountId':'BANK-1','bankPaymentMethod':'CHEQUE','chequeNo':'CH-123','chequeDate':body['paymentDate']}
    status,result=request('expenses_v1','?entity=TTI',bank);assert status==200,(status,result)
    bank_id=result['result']['generalExpenseId'];assert next(x for x in result['generalExpenses'] if x['id']==bank_id)['chequeNo']=='CH-123';before=books.read_bytes()
    assert request('expenses_v1','?entity=TTI',{**bank,'requestKey':'direct-cheque-0002','reference':'BANK-2'})[0]==422
    assert books.read_bytes()==before,'a duplicate cheque must not post'
    corrected={**bank,'action':'amend_expense','id':bank_id,'reason':'Correct cheque expense','requestKey':'direct-bank-amend-1'}
    assert request('expenses_v1','?entity=TTI',corrected)[0]==200,'correcting the original may retain its cheque'
    # One recipient, three purposes, one bank/cash credit, and vehicle dimensions.
    store=json.loads(books.read_text());store['managedAssets']={'CAR-TTI':{'id':'CAR-TTI','entity':'TTI','name':'Corolla','type':'VEHICLE','registrationNo':'ABC-123'},'CAR-BRM':{'id':'CAR-BRM','entity':'BRM','name':'Other car','type':'VEHICLE','registrationNo':'XYZ-987'}};books.write_text(json.dumps(store))
    mixed={**body,'requestKey':'purpose-mixed-0001','reference':'MIX-1','payee':'Talha','payeeId':'EXP|talha','amount':90,'expenseLines':[{'category':'DONATION','donationType':'ZAKAT','purpose':'Zakat distribution','amount':20},{'category':'CAR_REPAIRS','assetId':'CAR-TTI','purpose':'Brake pads','amount':30},{'category':'FUEL','assetId':'CAR-TTI','purpose':'Petrol','amount':40}]}
    status,result=request('expenses_v1','?entity=TTI',mixed);assert status==200,(status,result)
    journal=json.loads(books.read_text())['journals'][result['result']['journalId']]
    assert [l['account'] for l in journal['lines']]==['7210','6410','6610','1120']
    assert all(l['expenseRecipientId']=='EXP|talha' for l in journal['lines'][:-1])
    assert journal['lines'][1]['assetId']=='CAR-TTI' and journal['lines'][2]['registrationNo']=='ABC-123'
    status,person=request('accounts_ledger_browser','?entity=TTI&category=party&party=Talha&from=2026-10-01&to=2026-10-31');assert status==200,(status,person)
    assert len(person['rows'])==3 and person['expenseTotal']==90 and person['closing']==0
    status,report=request('accounts_reports','?entity=TTI&from=2026-10-01&asOf=2026-10-31');assert status==200,(status,report)
    assert sum(x['amount'] for x in report['expenseActivity'] if x['assetId']=='CAR-TTI')==70
    assert report['trialBalance']['balanced']
    before=books.read_bytes()
    invalid={**mixed,'requestKey':'purpose-invalid-0001','reference':'MIX-2','expenseLines':[{'category':'FUEL','assetId':'CAR-BRM','purpose':'Wrong company','amount':90}]}
    assert request('expenses_v1','?entity=TTI',invalid)[0]==422 and books.read_bytes()==before
    invalid['expenseLines'][0]['assetId']='';invalid['requestKey']='purpose-invalid-0002'
    assert request('expenses_v1','?entity=TTI',invalid)[0]==422 and books.read_bytes()==before
    # Expense purpose remains independent of the existing person's first category.
    office={**mixed,'requestKey':'purpose-office-0001','reference':'MIX-3','amount':10,'expenseLines':[{'category':'OFFICE','purpose':'Stationery','amount':10}]}
    assert request('expenses_v1','?entity=TTI',office)[0]==200
    status,person=request('accounts_ledger_browser','?entity=TTI&category=party&party=Talha&from=2026-10-01&to=2026-10-31')
    assert status==200 and len(person['rows'])==4 and person['expenseTotal']==100 and person['closing']==0
    print('Recipient purpose, vehicle costs, company boundaries, reporting and one-ledger activity passed.')
    # Corrupt nonempty books must not become empty books or get overwritten.

    for endpoint in ['expenses_v1','donations','rent_salary_v2']:
        books.write_text('{broken JSON');bad=books.read_bytes();assert request(endpoint,'?entity=TTI')[0]==500;assert books.read_bytes()==bad
    print('Direct expense endpoint, correction, deletion, retry and permissions passed.')

