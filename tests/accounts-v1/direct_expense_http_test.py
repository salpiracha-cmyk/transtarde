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
    assert replacement['publicPostId']==first['journalId']
    assert store['journals'][replacement['journalId']]['meta']['publicPostId']==first['journalId']
    assert 'Purpose:' in store['journals'][replacement['journalId']]['meta']['amendmentNote']
    status,register=request('accounts_ledger_browser','?entity=TTI&account=POSTS&from=2026-07-01&to=2026-12-31')
    assert status==200,(status,register)
    current=next(r for r in register['rows'] if r['publicPostId']==first['journalId'])
    assert current['voucher']==replacement['journalId'] and current['amendmentNote'] and current['party']
    assert len([r for r in register['rows'] if r['publicPostId']==first['journalId']])==1
    status,details=request('accounts_ledger_browser','?entity=TTI&account=POSTS&postId='+first['journalId']+'&to=2026-12-31')
    assert status==200 and details['post']['id']==replacement['journalId'] and details['post']['publicPostId']==first['journalId']

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
    assert len(person['rows'])==3 and person['expenseTotal']==90 and person['expenseCurrency']=='PKR' and person['closing']==0
    status,report=request('accounts_reports','?entity=TTI&from=2026-10-01&asOf=2026-10-31');assert status==200,(status,report)
    assert sum(x['amount'] for x in report['expenseActivity'] if x['assetId']=='CAR-TTI')==70
    assert report['trialBalance']['balanced']
    before=books.read_bytes()
    invalid={**mixed,'requestKey':'purpose-invalid-0001','reference':'MIX-2','expenseLines':[{'category':'FUEL','assetId':'CAR-BRM','purpose':'Wrong company','amount':90}]}
    assert request('expenses_v1','?entity=TTI',invalid)[0]==422 and books.read_bytes()==before
    invalid['expenseLines'][0]['assetId']='';invalid['requestKey']='purpose-invalid-0002'
    assert request('expenses_v1','?entity=TTI',invalid)[0]==422 and books.read_bytes()==before
    invalid['expenseLines'][0].update(category='OTHER',accountCode='6610');invalid['requestKey']='purpose-invalid-0003'
    assert request('expenses_v1','?entity=TTI',invalid)[0]==422 and books.read_bytes()==before
    # Expense purpose remains independent of the existing person's first category.
    office={**mixed,'requestKey':'purpose-office-0001','reference':'MIX-3','amount':10,'expenseLines':[{'category':'OFFICE','purpose':'Stationery','amount':10}]}
    assert request('expenses_v1','?entity=TTI',office)[0]==200
    status,person=request('accounts_ledger_browser','?entity=TTI&category=party&party=Talha&from=2026-10-01&to=2026-10-31')
    assert status==200 and len(person['rows'])==4 and person['expenseTotal']==100 and person['closing']==0
    tax={**body,'requestKey':'vehicle-tax-fixture','reference':'TAX-1','payee':'Excise Department','amount':1200,'expenseLines':[{'category':'VEHICLE_TAX','assetId':'CAR-TTI','purpose':'Annual vehicle token tax','periodFrom':'2026-07-01','periodTo':'2027-06-30','challanReference':'CHALLAN-1','amount':1200}]}
    # Reuse the vehicle seeded by the earlier company-boundary fixtures.
    vehicles=json.loads(books.read_text()).get('managedAssets',{})
    car=next((a['id'] for a in vehicles.values() if a['entity']=='TTI' and a['type']=='VEHICLE'),None)
    assert car
    tax['expenseLines'][0]['assetId']=car
    status,result=request('expenses_v1','?entity=TTI',tax);assert status==200,(status,result)
    j=json.loads(books.read_text())['journals'][result['result']['journalId']]
    assert j['lines'][0]['account']=='6620' and j['lines'][0]['assetId']==car
    assert j['lines'][0]['periodTo']=='2027-06-30' and j['totalDebit']==j['totalCredit']==1200
    before=books.read_bytes();bad={**tax,'requestKey':'vehicle-tax-bad-period','reference':'TAX-BAD','expenseLines':[{**tax['expenseLines'][0],'periodTo':'2026-06-30'}]}
    assert request('expenses_v1','?entity=TTI',bad)[0]==422 and books.read_bytes()==before
    print('Recipient purpose, vehicle costs, company boundaries, reporting and one-ledger activity passed.')
    # One payee, separate beneficiary, no duplicate financial debit for reporting.
    school={**body,'requestKey':'beneficiary-school-001','reference':'CAS-OCT','payee':'CAS','amount':10000,'expenseLines':[{'category':'HOME','purpose':'Daughter school fee October','amount':10000,'expenseArea':'HOME','expenseFor':'FAM-TALHA','personalTreatment':'COMPANY'}]}
    status,result=request('expenses_v1','?entity=TTI',school);assert status==200,(status,result)
    school_id=result['result']['generalExpenseId']
    status,person=request('accounts_ledger_browser','?entity=TTI&category=party&party=Talha&from=2026-10-01&to=2026-10-31');assert status==200,(status,person)
    assert any('CAS' in r['narration'] and r.get('expenseAmount')==10000 for r in person['rows'])
    status,report=request('accounts_reports','?entity=TTI&from=2026-10-01&asOf=2026-10-31');assert status==200
    assert any(r['recipient']=='CAS' and r['expenseForName']=='Talha' and r['expenseArea']=='HOME' for r in report['expenseActivity'])
    # Director remuneration masters and an already accrued payable.
    store=json.loads(books.read_text())
    store['salaryMasters']={name:{'id':name,'name':name,'entity':'TTI','category':'DIRECTOR_REMUNERATION','monthlyAmount':75000,'zakatAmount':0,'otherAllowance':0,'effectiveFrom':'2026-07-01','status':'Active','accountingTreatment':'STAFF_COST'} for name in ['SALMAN','TALHA','TAYYAB','ARP']}
    period='TTI|2026-10|TALHA'
    store['salaryPeriods']={period:{'id':period,'entity':'TTI','month':'2026-10','salaryMasterId':'TALHA','name':'TALHA','category':'DIRECTOR_REMUNERATION','accountingTreatment':'STAFF_COST','netSalary':75000,'zakatAmount':0,'otherAllowance':0,'gross':75000,'advanceApplied':0,'paidAfterPrepare':0,'outstanding':75000,'status':'Payable'}}
    books.write_text(json.dumps(store))
    def bal(code):return round(sum(l['debit']-l['credit'] for j in json.loads(books.read_text())['journals'].values() for l in j['lines'] if l['account']==code),2)
    master={'action':'save_card_master','csrf':'fixture','entity':'TTI','cardName':'Company Visa','holder':'Salman','issuerBank':'Fixture Bank','last4':'4321','dueDay':20}
    status,result=request('expenses_v1','?entity=TTI',master);assert status==200,(status,result);card=result['result']['cardMasterId']
    statement={'action':'save_card_statement','csrf':'fixture','entity':'TTI','requestKey':'simple-card-statement-1','simpleStatement':True,'cardMasterId':card,'statementMonth':'2026-10','statementDate':'2026-10-07','dueDate':'2026-10-20','total':100000,'expenseAccount':'6900','personalAmounts':[{'expenseFor':'FAM-TALHA','personalTreatment':'REMUNERATION','amount':15000},{'expenseFor':'FAM-SALMAN','personalTreatment':'CASH','amount':10000}]}
    expense_before=bal('6900');cash_before=bal('1120');payable_before=bal('2400');receivable_before=bal('1230')
    status,result=request('expenses_v1','?entity=TTI',statement);assert status==200,(status,result);st_id=result['result']['statementId'];post_id=result['result']['journalId']
    assert bal('2400')==payable_before-100000 and bal('6900')==expense_before+75000
    store=json.loads(books.read_text());assert store['salaryPeriods'][period]['outstanding']==60000
    before=books.read_bytes();assert request('expenses_v1','?entity=TTI',statement)[1]['result']['duplicate'];assert books.read_bytes()==before
    payment={'action':'pay_card_statement','csrf':'fixture','entity':'TTI','requestKey':'simple-card-payment-1','statementId':st_id,'paymentDate':'2026-10-08','paymentAccountId':'CASH|TTI','personalAmounts':statement['personalAmounts']}
    status,result=request('expenses_v1','?entity=TTI',payment);assert status==200,(status,result);payment_id=result['result']['journalId']
    assert bal('1120')==cash_before-100000 and bal('2400')==payable_before and bal('6900')==expense_before+75000
    assert json.loads(books.read_text())['salaryPeriods'][period]['outstanding']==60000
    payment_snapshot=json.loads(books.read_text())['journals'][payment_id]
    edit={'action':'save_card_adjustments','csrf':'fixture','entity':'TTI','requestKey':'simple-card-adjust-1','statementId':st_id,'adjustmentDate':'2026-10-08','reason':'Correct Talha personal amount','personalAmounts':[{'expenseFor':'FAM-TALHA','personalTreatment':'REMUNERATION','amount':20000},{'expenseFor':'FAM-SALMAN','personalTreatment':'CASH','amount':10000}]}
    status,result=request('expenses_v1','?entity=TTI',edit);assert status==200,(status,result)
    assert result['result']['publicPostId']==post_id
    store=json.loads(books.read_text());assert store['journals'][payment_id]==payment_snapshot and store['salaryPeriods'][period]['outstanding']==55000
    assert bal('6900')==expense_before+70000 and bal('2400')==payable_before and bal('1120')==cash_before-100000
    before=books.read_bytes();assert request('expenses_v1','?entity=TTI',{**edit,'requestKey':'simple-card-over-total','personalAmounts':[{'expenseFor':'FAM-TALHA','personalTreatment':'REMUNERATION','amount':100001}]})[0]==422;assert books.read_bytes()==before
    # Cash recovery reduces only the selected person's receivable; no card posting.
    recovery={'action':'recover_personal_expense','csrf':'fixture','entity':'TTI','requestKey':'simple-card-cash-receipt','personalLink':'CARD|'+st_id,'index':1,'amount':10000,'paymentDate':'2026-10-08','paymentAccountId':'CASH|TTI'}
    status,result=request('expenses_v1','?entity=TTI',recovery);assert status==200,(status,result)
    assert bal('1120')==cash_before-90000 and bal('2400')==payable_before and bal('1230')==receivable_before
    before=books.read_bytes();assert request('expenses_v1','?entity=TTI',recovery)[1]['result']['duplicate'];assert books.read_bytes()==before
    assert request('expenses_v1','?entity=TTI',{**recovery,'requestKey':'simple-card-cash-over'})[0]==422
    # Excess personal amounts carry into future remuneration instead of inventing a payable.
    november={**statement,'requestKey':'simple-card-november','statementMonth':'2026-11','statementDate':'2026-11-01','dueDate':'2026-11-20','personalAmounts':[{'expenseFor':'FAM-TAYYAB','personalTreatment':'REMUNERATION','amount':80000}]}
    status,result=request('expenses_v1','?entity=TTI',november);assert status==200,(status,result);nov_id=result['result']['statementId']
    store=json.loads(books.read_text());adv=next(a for a in store['salaryAdvances'].values() if a.get('personalLink')=='CARD|'+nov_id)
    assert adv['remaining']==80000 and adv['masterId']=='TAYYAB'
    # The real remuneration preparation consumes only the available entitlement.
    status,salary=request('rent_salary_v2','?entity=TTI&month=2026-11',{'action':'prepare_month','csrf':'fixture','entity':'TTI','month':'2026-11'})
    assert status==200,(status,salary)
    store=json.loads(books.read_text());paid_period='TTI|2026-11|TAYYAB'
    assert store['salaryPeriods'][paid_period]['advanceApplied']==75000 and store['salaryAdvances'][adv['id']]['remaining']==5000
    status,result=request('expenses_v1','?entity=TTI',{**edit,'requestKey':'simple-card-consumed-edit','statementId':nov_id,'adjustmentDate':'2026-11-02','personalAmounts':[{'expenseFor':'FAM-TAYYAB','personalTreatment':'REMUNERATION','amount':10000}]});assert status==200,(status,result)
    store=json.loads(books.read_text());assert store['salaryPeriods'][paid_period]['outstanding']==65000 and store['salaryPeriods'][paid_period]['advanceApplied']==0
    assert not any(a.get('personalLink')=='CARD|'+nov_id for a in (store['salaryAdvances'].values() if isinstance(store['salaryAdvances'],dict) else store['salaryAdvances']))
    print('Beneficiaries, full card payments, internal corrections, cash recovery and remuneration carry-forward passed.')

    # Corrupt nonempty books must not become empty books or get overwritten.

    for endpoint in ['expenses_v1','donations','rent_salary_v2']:
        books.write_text('{broken JSON');bad=books.read_bytes();assert request(endpoint,'?entity=TTI')[0]==500;assert books.read_bytes()==bad
    print('Direct expense endpoint, correction, deletion, retry and permissions passed.')

