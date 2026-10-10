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
    for name in ['accounts_subaccounts.php','accounts_subaccounts_core.php','assets_registry_core.php','expenses_v1.php','direct_expense_core.php','accounts_bank_payment.php','expense_locations.php','expense_reminders.php','expense_reversals.php','donations.php','rent_salary_v2.php','salary_month_workflow.php','salary_master_store.php','accounts_reports.php','accounts_ledger_browser.php','customer_receivables_core.php','accounts_post_delete_core.php','accounts_post_amend.php','accounts_post_amend_core.php','opening_balance_core.php','accounts_reference.php','tg_remittance_core.php','fi_credit_advice_link.php','receipt_invoice_links.php']:
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
    # Stable type editing and ARP subsidiaries are available alongside every standard type.
    permissions['masters']=['View','Create','Edit'];set_user()
    status,setup=request('accounts_subaccounts','?entity=TTI');assert status==200,(status,setup)
    types={x.get('builtinCategory'):x for x in setup['subaccounts'] if x.get('builtinCategory')}
    assert len(types)==14 and all(x in types for x in ['HOME','OFFICE','MEDICAL','RENT','DONATION','ARP'])
    rename={'csrf':'fixture','entity':'TTI','operation':'edit','id':types['OFFICE']['id'],'name':'Office Running Costs','expenseType':True,'expenseClassification':'OFFICE','parentCode':'6900','parentId':'','revision':setup['revision'],'requestKey':'rename-office-type-001'}
    status,setup=request('accounts_subaccounts','?entity=TTI',rename);assert status==200,(status,setup)
    assert next(x for x in setup['subaccounts'] if x['id']==rename['id'])['builtinCategory']=='OFFICE'
    add={'csrf':'fixture','entity':'TTI','operation':'add','name':'ARP medical costs','expenseType':True,'expenseClassification':'HOME','parentCode':'6910','parentId':types['ARP']['id'],'revision':setup['revision'],'requestKey':'arp-add-subsidiary-001'}
    status,setup=request('accounts_subaccounts','?entity=TTI',add);assert status==200,(status,setup);arp_sub=setup['result']['subaccountId']
    arp={**school,'requestKey':'arp-expense-post-001','reference':'ARP-1','amount':50,'expenseLines':[{'category':'ARP','subaccountId':arp_sub,'accountCode':'6910','purpose':'Medical costs','amount':50}]}
    status,result=request('expenses_v1','?entity=TTI',arp);assert status==200,(status,result)
    assert json.loads(books.read_text())['journals'][result['result']['journalId']]['lines'][0]['expenseFor']=='FAM-ABU'
    # Existing monthly Home allocations and direct school fees consolidate without rewriting books.
    store=json.loads(books.read_text())
    for name,amount in [('MRS TRP',140000),('MRS SRP',310000),('MRS TAYYAB',160000)]:
        jid='HOME-'+name.replace(' ','-');store['journals'][jid]={'id':jid,'entity':'TTI','date':'2026-10-31','status':'Posted','sourceType':'SALARY_MONTHLY_ACCRUAL','narration':name+' monthly home','meta':{},'lines':[{'account':'3200','debit':amount,'credit':0,'person':name,'category':'HOME_MONTHLY_GIVE'},{'account':'2140','debit':0,'credit':amount,'person':name}]}
    books.write_text(json.dumps(store));before=books.read_bytes()
    for person,amount in [('FAM-TALHA',150000),('FAM-SALMAN',310000),('FAM-TAYYAB',160000)]:
        status,home=request('accounts_ledger_browser','?entity=TTI&account=SUB|TTI-HOME-'+person+'&from=2026-10-01&to=2026-10-31');assert status==200,(status,home)
        assert home['homeLedger'] and home['expenseTotal']==amount,(person,home)
        assert all(x['activityOnly'] and x['debit']==x['credit']==0 for x in home['rows'])
    assert books.read_bytes()==before,'Reporting must preserve all original journals and amounts'
    status,home=request('accounts_ledger_browser','?entity=TTI&category=party&party=Talha&from=2026-10-01&to=2026-10-31');assert status==200
    assert any('MRS TRP' in x['narration'] and x.get('expenseAmount')==140000 for x in home['rows'])
    print('All standard expense types editable, ARP subsidiaries and three consolidated Home ledgers passed.')
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

    # Optional purpose/narration and general export costs use the same single payment.
    blank={**body,'requestKey':'blank-purpose-payment','reference':'OPTIONAL-1','paymentNarration':'','expenseLines':[{'category':'OFFICE','purpose':'','amount':10}],'amount':10}
    status,result=request('expenses_v1','?entity=TTI',blank);assert status==200,(status,result)
    narrated={**blank,'requestKey':'cash-narration-independent','reference':'OPTIONAL-2','paymentNarration':'Payment details'}
    status,result=request('expenses_v1','?entity=TTI',narrated);assert status==200,(status,result)
    assert json.loads(books.read_text())['journals'][result['result']['journalId']]['meta']['paymentNarration']=='PAYMENT DETAILS'
    export={**blank,'requestKey':'general-export-payment','reference':'EXPORT-GENERAL-1','expenseLines':[{'category':'EXPORT','expenseArea':'EXPORT','purpose':'General export courier','amount':25}],'amount':25}
    status,result=request('expenses_v1','?entity=TTI',export);assert status==200,(status,result)
    journal=json.loads(books.read_text())['journals'][result['result']['journalId']]
    assert journal['lines'][0]['account']=='5550' and journal['lines'][0]['expenseArea']=='EXPORT'
    assert len(journal['lines'])==2 and journal['totalDebit']==25==journal['totalCredit']
    status,setup=request('accounts_subaccounts','?entity=TTI');assert status==200
    custom={'csrf':'fixture','entity':'TTI','requestKey':'general-export-type','revision':setup['revision'],'operation':'add','name':'Export courier','expenseType':True,'expenseClassification':'EXPORT','parentCode':'5550','parentId':'','taxCategory':'NONE','active':True}
    status,setup=request('accounts_subaccounts','?entity=TTI',custom);assert status==200,(status,setup)
    assert next(x for x in setup['subaccounts'] if x['name'].casefold()=='export courier')['expenseClassification']=='EXPORT'
    # Atomic card posting: an invalid payment cannot leave a bill or internal journal behind.
    combined={**statement,'action':'post_and_pay_card_statement','requestKey':'combined-card-december','statementMonth':'2026-12','statementDate':'2026-12-01','dueDate':'2026-12-20','paymentDate':'2026-12-08','paymentAccountId':'CASH|TTI','total':1000,'personalAmounts':[{'expenseFor':'FAM-SALMAN','personalTreatment':'CASH','amount':100}]}
    for changed in [{'paymentAccountId':'BANK-OTHER'},{'paymentDate':'2026-11-30'},{'paymentAccountId':'BANK-1','bankPaymentMethod':'CHEQUE','chequeNo':''}]:
        before=books.read_bytes();status,result=request('expenses_v1','?entity=TTI',{**combined,**changed});assert status==422,(status,result);assert books.read_bytes()==before
    expense_before=bal('6900');cash_before=bal('1120');payable_before=bal('2400')
    status,result=request('expenses_v1','?entity=TTI',combined);assert status==200,(status,result)
    assert result['result']['status']=='Paid' and result['result']['statementJournalId']!=result['result']['journalId']
    assert bal('6900')==expense_before+900 and bal('1120')==cash_before-1000 and bal('2400')==payable_before
    before=books.read_bytes();assert request('expenses_v1','?entity=TTI',combined)[1]['result']['duplicate'];assert books.read_bytes()==before
    assert request('expenses_v1','?entity=TTI',{**combined,'requestKey':'combined-card-duplicate'})[0]==409;assert books.read_bytes()==before
    # Paying an already recorded bill reuses its expense and payable; stale amount is rejected.
    pending={**statement,'requestKey':'existing-card-january','statementMonth':'2027-01','statementDate':'2027-01-01','dueDate':'2027-01-20','total':500,'personalAmounts':[]}
    status,result=request('expenses_v1','?entity=TTI',pending);assert status==200,(status,result);pending_id=result['result']['statementId'];count=len(json.loads(books.read_text())['journals'])
    pay_existing={**pending,'action':'post_and_pay_card_statement','requestKey':'pay-existing-january','statementId':pending_id,'paymentDate':'2027-01-08','paymentAccountId':'CASH|TTI'}
    before=books.read_bytes();assert request('expenses_v1','?entity=TTI',{**pay_existing,'total':501})[0]==422;assert books.read_bytes()==before
    status,result=request('expenses_v1','?entity=TTI',pay_existing);assert status==200,(status,result)
    assert len(json.loads(books.read_text())['journals'])==count+1
    print('Optional descriptions, shipment-free export types, atomic card posting, rollback, reuse and replay passed.')

    # Purpose-specific providers reject known rice parties without posting anything.
    fixture_auth=json.loads(auth.read_text());fixture_auth['masters']['business_parties']=[{'id':'rice-broker','values':['Rice Broker','','Broker','','','','','','','','Active']},{'id':'electric-provider','values':['Electric Provider','','Service Provider;Broker','','','','','','','','Active']}];fixture_auth['masters']['mills']=[{'id':'office-fixture','values':['Office','','Office','','','Active','','TTI']}];auth.write_text(json.dumps(fixture_auth))
    utility={'action':'save_utility_master','csrf':'fixture','entity':'TTI','utilityType':'ELECTRICITY','location':'OFFICE','locationName':'Office','payee':'Rice Broker','dueDay':10,'remindDays':7}
    before=books.read_bytes();status,result=request('expenses_v1','?entity=TTI',utility);assert status in (403,422) and 'Service Provider' in result['error'],(status,result);assert books.read_bytes()==before
    status,result=request('expenses_v1','?entity=TTI',{**utility,'payee':'Electric Provider'});assert status==200,(status,result)
    assert json.loads(books.read_text())['journals']==json.loads(before)['journals'],'Master creation must not create an expense posting'

    # Recorded utilities belong to closing-date cycles and are not recognised twice.
    fixture_auth['users'][0]['role']='Super Admin';auth.write_text(json.dumps(fixture_auth))
    card_id=next(iter(json.loads(books.read_text())['creditCardMasters']))
    utility_payment={'action':'pay_utility','csrf':'fixture','entity':'TTI','utilityType':'ELECTRICITY','location':'OFFICE','locationId':'office-fixture','accountingTreatment':'BUSINESS_EXPENSE','payee':'Electric Provider','billMonth':'2027-01','amount':80,'paymentDate':'2027-02-10','paymentAccountId':'CARD|'+card_id,'requestKey':'utility-card-current'}
    expense_before=bal('6110');cash_before=bal('1120');card_before=bal('2400');other_before=bal('6900')
    status,result=request('expenses_v1','?entity=TTI',utility_payment);assert status==200,(status,result)
    utility_id=result['result']['utilityPaymentId'];assert bal('6110')==expense_before+80 and bal('1120')==cash_before and bal('2400')==card_before-80
    status,result=request('expenses_v1','?entity=TTI',{**utility_payment,'requestKey':'utility-card-next','paymentDate':'2027-02-16','amount':30});assert status==200,(status,result)
    february={**combined,'cardMasterId':card_id,'statementMonth':'2027-02','statementDate':'2027-02-15','dueDate':'2027-02-20','paymentDate':'2027-02-19','total':200,'personalAmounts':[],'requestKey':'card-utilities-february'}
    status,result=request('expenses_v1','?entity=TTI',february);assert status==200,(status,result)
    bill_id=result['result']['statementId'];bill=json.loads(books.read_text())['creditCardStatements'][bill_id]
    assert bill['includedUtilityTotal']==80 and len(bill['includedUtilities'])==1 and bill['includedUtilities'][0]['id']==utility_id,bill
    assert bal('6900')==other_before+120 and bal('6110')==expense_before+110 and bal('1120')==cash_before-200 and bal('2400')==card_before-30
    before=books.read_bytes();assert request('expenses_v1','?entity=TTI',february)[1]['result']['duplicate'];assert books.read_bytes()==before
    status,late=request('expenses_v1','?entity=TTI',{**utility_payment,'requestKey':'late-utility-cycle'});assert status==200,(status,late)
    assert bal('2400')==card_before-30 and bal('1120')==cash_before-200
    assert json.loads(books.read_text())['creditCardStatements'][bill_id]['total']==200
    before=books.read_bytes()
    assert request('expenses_v1','?entity=TTI',{'csrf':'fixture','action':'delete_expense','entity':'TTI','collection':'utilityPayments','id':utility_id,'reason':'Remove wrong utility'})[0] in (403,422) and books.read_bytes()==before
    # Post-payment breakdowns reclassify the bill, leave bank/payable/full bill unchanged,
    # reject overlaps and replay safely; removal frees capacity with an audit reversal.
    breakdown={'action':'add_card_breakdown','csrf':'fixture','entity':'TTI','statementId':bill_id,'kind':'OTHER','expenseAccount':'6500','amount':30,'adjustmentDate':'2027-02-20','requestKey':'card-breakdown-other'}
    card_before=bal('2400');cash_before=bal('1120');travel_before=bal('6500');admin_before=bal('6900')
    status,posted=request('expenses_v1','?entity=TTI',breakdown);assert status==200,(status,posted)
    breakdown_id=posted['result']['breakdownId']
    assert bal('6500')==travel_before+30 and bal('6900')==admin_before-30 and bal('2400')==card_before and bal('1120')==cash_before
    before=books.read_bytes();assert request('expenses_v1','?entity=TTI',breakdown)[1]['result']['duplicate'];assert books.read_bytes()==before
    assert request('expenses_v1','?entity=TTI',{**breakdown,'requestKey':'card-breakdown-overlap','amount':11})[0]==422;assert books.read_bytes()==before
    remove={'action':'remove_card_breakdown','csrf':'fixture','entity':'TTI','id':breakdown_id,'reason':'Correct expense category','requestKey':'card-breakdown-remove'}
    assert request('expenses_v1','?entity=TTI',remove)[0]==200
    assert bal('6500')==travel_before and bal('6900')==admin_before and bal('2400')==card_before
    # Saved utilities selected directly from the bill cannot add to its payable.
    utility_master=next(iter(json.loads(books.read_text())['utilityMasters']))
    status,posted=request('expenses_v1','?entity=TTI',{**breakdown,'kind':'UTILITY','utilityMasterId':utility_master,'amount':10,'requestKey':'card-breakdown-utility'});assert status==200,(status,posted)
    assert bal('2400')==card_before and bal('1120')==cash_before
    utility_breakdown_id=posted['result']['breakdownId']
    # Other Expense supports cards before a future bill; the eventual bill excludes
    # that expense from the residual recognition, and cycle metadata is automatic.
    future={**body,'requestKey':'future-card-other-expense','paymentAccountId':'CARD|'+card_id,'paymentDate':'2027-03-10','reference':'CARD-MARCH','amount':40,'expenseLines':[{'category':'OFFICE','purpose':'Card office supplies','amount':40}]}
    status,posted=request('expenses_v1','?entity=TTI',future);assert status==200,(status,posted)
    expense_id=posted['result']['generalExpenseId'];journal=json.loads(books.read_text())['journals'][posted['result']['journalId']]
    assert journal['lines'][-1]['account']=='2400' and journal['lines'][-1]['cardStatementMonth']=='2027-03'
    future_bill={**february,'statementMonth':'2027-03','statementDate':'2027-03-15','dueDate':'2027-03-20','paymentDate':'2027-03-19','requestKey':'future-card-march-bill'}
    status,posted=request('expenses_v1','?entity=TTI',future_bill);assert status==200,(status,posted)
    future_st=json.loads(books.read_text())['creditCardStatements'][posted['result']['statementId']]
    assert future_st['includedExpenseTotal']==40 and future_st['includedUtilityTotal']==30 and future_st['total']==200
    assert any(x['id']==expense_id for x in future_st['includedExpenses'])
    print('Card classification within paid bill, overlap rejection, reversal, replay, other-expense card choice and automatic cycle inclusion passed.')

    # A card bill with active classifications cannot disappear under those links.
    before=books.read_bytes();assert request('accounts_post_amend','',{'csrf':'fixture','action':'delete_post','postId':bill['journalId'],'reason':'Wrong credit card bill'})[0]==422;assert books.read_bytes()==before
    assert request('expenses_v1','?entity=TTI',{**remove,'id':utility_breakdown_id,'requestKey':'utility-breakdown-remove'})[0]==200
    assert request('expenses_v1','?entity=TTI',{'action':'delete_expense','csrf':'fixture','entity':'TTI','collection':'utilityPayments','id':late['result']['utilityPaymentId'],'reason':'Correct late classification'})[0]==200
    admin=bal('6900');cash=bal('1120');card=bal('2400')
    status,result=request('accounts_post_amend','',{'csrf':'fixture','action':'delete_post','postId':bill['journalId'],'reason':'Wrong credit card bill'});assert status==200,(status,result)
    saved=json.loads(books.read_text());assert saved['creditCardStatements'][bill_id]['status']=='Deleted' and not saved['utilityPayments'][utility_id].get('cardStatementId')
    assert bal('6900')==admin-120 and bal('1120')==cash+200 and bal('2400')==card-80
    assert request('accounts_post_amend','',{'csrf':'fixture','action':'delete_post','postId':bill['journalId'],'reason':'Repeated deletion'})[0]==422
    for endpoint in ['expenses_v1','donations','rent_salary_v2']:
        books.write_text('{broken JSON');bad=books.read_bytes();assert request(endpoint,'?entity=TTI')[0]==500;assert books.read_bytes()==bad
    print('Direct expense endpoint, correction, deletion, retry and permissions passed.')
