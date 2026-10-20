"""Exercise durable salary drafts and one atomic final journal against the actual endpoint."""
import json, os, pathlib, re, shutil, socket, subprocess, tempfile, time, urllib.request, urllib.error
ROOT = pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='salary-month-qa-') as temp:
    root=pathlib.Path(temp)
    for folder in ['api','accounts','data']:(root/folder).mkdir()
    for name in ['company_investments_core.php','assets_registry_core.php','rent_salary.php','rent_salary_v2.php','salary_month_workflow.php','salary_flexible_workflow.php','salary_extra_allocations.php','accounts_subaccounts_core.php','accounts_reference.php','accounts_ledger_visibility.php','salary_master_store.php','accounts_bank_payment.php', 'accounts_posting_preview.php']:
        shutil.copy(ROOT/'api'/name,root/'api'/name)
    for name in ['accounting_master_v1.json','rent-salary-ui.js','cheque-date-ui.js','bank-payment-details.js']:
        shutil.copy(ROOT/'accounts'/name,root/'accounts'/name)
    masters={'banks':[{'id':'bank-a','values':['Company Account','TTI','','TTI','Fixture Bank','','','PKR','12345','','','','','Active']},{'id':'bank-b','values':['Company Account','TTI','','TTI','Other Bank','','','PKR','67890','','','','','Active']}]}
    (root/'masters.json').write_text(json.dumps(masters))
    def staff(id,amount=1000):return {'id':id,'entity':'TTI','name':id,'category':'OFFICE_STAFF','monthlyAmount':amount,'zakatAmount':20,'otherAllowance':30,'accountingTreatment':'STAFF_COST','effectiveFrom':'2026-01-01','status':'Active','productionCostEligible':False}
    initial={'salaryMasters':{id:staff(id) for id in ['Alice','Bob','Cara']},'salaryAdvances':{'ADV':{'id':'ADV','entity':'TTI','masterId':'Alice','remaining':300,'monthsRemaining':1,'date':'2026-09-01','adjustments':[]}},'journals':{},'bankAccountSettings':{'bank-a':{'defaultReceiptAccount':True},'bank-b':{'defaultPaymentAccount':True}}}
    (root/'data/accounts.json').write_text(json.dumps(initial))
    (root/'auth_store.php').write_text('''<?php
    define('TT_DATA_DIR',__DIR__.'/data');
    function tt_ensure_data_dir(){}
    function tt_list_masters(){return json_decode(file_get_contents(__DIR__.'/masters.json'),true);}
    function tt_read_store(){return [];}
    function tt_require_login(){return is_file(__DIR__.'/user.json')?json_decode(file_get_contents(__DIR__.'/user.json'),true):['role'=>'Super Admin','id'=>1,'username'=>'Fixture'];}
    function tt_user_can_open_module($user,$module){return true;}
    function tt_user_can_access_entity($user,$entity,$action){return $entity==='TTI';}
    function tt_verify_csrf($csrf){return $csrf==='fixture';}
    function tt_bank_can_transact($id){return in_array($id,['bank-a','bank-b']);}
    function tt_next_post_id(array $existing, string $module='Accounts', string $area='Journal', ?string $date=null): string {
        $year=substr($date ?: date('Y-m-d'),0,4);$n=count($existing)+1;
        do {$id='POST-'.$year.'-'.str_pad((string)$n,5,'0',STR_PAD_LEFT);$n++;} while (isset($existing[$id]));
        return $id;
    }
    ''')
    popup_css=re.search(r'style.textContent = `([\s\S]*?)`;', (ROOT/'accounts/accounts-clean-ui.js').read_text()).group(1)
    (root/'accounts/index.html').write_text('<!doctype html><style>'+popup_css+'</style>'+'''<section class="workspace active tt-clean-modal tt-entry-only"><button data-expense="salary">Salaries</button><div id="expenseEditor"></div></section><script>window.TT_ACCOUNT_ACCESS={csrf:'fixture'};localStorage.setItem('tt_accounts_entity','TTI');</script><script src="cheque-date-ui.js"></script><script src="bank-payment-details.js"></script><script src="rent-salary-ui.js"></script>''')
    with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    def start():return subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    server=start()
    def request(payload=None,month='2026-10'):
        req=urllib.request.Request(f'http://127.0.0.1:{port}/api/rent_salary.php?entity=TTI&month={month}',data=json.dumps({'csrf':'fixture','entity':'TTI','month':month,**payload}).encode() if payload else None,headers={'Content-Type':'application/json'})
        try:
            with urllib.request.urlopen(req,timeout=3) as res:return res.status,json.load(res)
        except urllib.error.HTTPError as err:return err.code,json.load(err)
    def wait():
        for attempt in range(50):
            try:return request()
            except urllib.error.URLError:time.sleep(.1)
        raise AssertionError('PHP fixture did not start')
    def saved():return json.loads((root/'data/accounts.json').read_text())
    def draft(mid,amount,source='CASH|TTI',method=None,version=None,month='2026-10',**extra):
        if version is None:version=request(month=month)[1]['salarySheet']['version']
        body={'action':'save_salary_draft_payment','masterId':mid,'amount':amount,'date':'2026-10-01','paymentAccountId':source,'version':version,**extra}
        if method:body['bankPaymentMethod']=method
        return request(body,month)
    try:
        wait()
        # Use real salary masters and canonical investment fixtures, never live books.
        state=initial.copy();state['salaryMasters']={}
        for id,amount,category in [('Salman',200000,'DIRECTOR_REMUNERATION'),('Mrs',300000,'HOME_MONTHLY_GIVE'),('Worker',50000,'OFFICE_STAFF')]:
            state['salaryMasters'][id]={**staff(id,amount),'zakatAmount':0,'otherAllowance':0,'category':category,'accountingTreatment':'FAMILY_ALLOCATION' if id=='Mrs' else 'STAFF_COST'}
        state['salaryAdvances']={'P':{'id':'P','entity':'TTI','masterId':'Salman','date':'2026-09-28','remaining':10000,'amount':10000,'monthsRemaining':1,'personalLink':'EXP|fixture','adjustments':[]}}
        state['investmentBrokers']={'BMA':{'id':'BMA','entity':'TTI','name':'BMA','active':True}}
        state['investmentRoutes']={'R':{'id':'R','entity':'TTI','name':'Mrs Salman','active':True}}
        state['investmentTransactions']={}
        (root/'data/accounts.json').write_text(json.dumps(state))
        assert request(month='2026-06')[1]['salarySheet']['rows']['Salman']['advanceSuggested']==0
        assert request(month='2026-09')[1]['salarySheet']['rows']['Salman']['advanceSuggested']==0
        assert request(month='2026-10')[1]['salarySheet']['rows']['Salman']['advanceSuggested']==10000
        def action(name,id,month='2026-10',**fields):
            version=request(month=month)[1]['salarySheet']['version']
            return request({'action':name,'masterId':id,'version':version,**fields},month)
        # One-off additions / reductions; deferred recovery and unaffected next-month master.
        status,result=action('adjust_salary_month','Worker',netSalary=52000,zakatAmount=0,otherAllowance=500,monthlyAddition=1000,monthlyReduction=200,adjustmentReason='Overtime less absence',advanceApplied=0)
        assert status==200,result
        assert result['salarySheet']['rows']['Worker']['totalDue']==53300
        assert saved()['salaryMasters']['Worker']['monthlyAmount']==50000
        assert request(month='2026-11')[1]['salarySheet']['rows']['Worker']['totalDue']==50000
        def alloc(id,amount):return {'type':'SALARY','masterId':id,'amount':amount}
        def payment(amount,date,source,allocations):return {'amount':amount,'date':date,'paymentAccountId':source,'recipient':'Mrs Salman','allocations':allocations,'bankPaymentMethod':'ONLINE_BANKING','bankPaymentDate':date,'bankReference':'fixture-split-transfer'}
        cash=payment(200000,'2026-10-03','CASH|TTI',[alloc('Mrs',200000)])
        broker={'type':'BROKER_RECEIVED','amount':10000,'routeId':'R','brokerId':'BMA','reference':'BMA-RECEIPT-1'}
        bank=payment(120000,'2026-10-10','bank-b',[alloc('Mrs',100000),alloc('Salman',10000),broker])
        before=saved();status,rejected=action('save_salary_transactions','Mrs',transactions=[cash,{**bank,'amount':119999}],advanceApplied=0)
        assert status==422,rejected;assert saved()==before,'Mismatch must roll back draft'
        status,result=action('save_salary_transactions','Mrs',transactions=[cash,bank],advanceApplied=0);assert status==200,result
        assert result['salarySheet']['rows']['Mrs']['payment']['amount']==300000
        assert result['salarySheet']['rows']['Salman']['payment']['amount']==10000
        assert not saved()['journals'] and not saved()['investmentTransactions'],'Draft never posts assets or cash'
        before=saved();status,rejected=action('save_salary_draft_payment','Mrs',amount=100,date='2026-10-10',paymentAccountId='CASH|TTI')
        assert status==422 and saved()==before,'A cached older salary editor cannot overwrite transaction allocations'

        assert action('save_salary_transactions','Salman',transactions=[],advanceApplied=5000)[0]==200
        assert action('save_salary_transactions','Worker',transactions=[],advanceApplied=0)[0]==200
        # Posting preview must leave draft, investments, journals and request keys unchanged.
        sheet=request()[1]['salarySheet'];before=saved()
        status,preview=request({'action':'complete_salary_month','version':sheet['version'],'date':'2026-10-31','preview':True})
        assert status==200,preview;assert saved()==before
        status,result=request({'action':'complete_salary_month','version':sheet['version'],'date':'2026-10-31'});assert status==200,result
        state=saved();assert len(state['journals'])==3,'One accrual plus two actual dated payments'
        assert len(state['investmentTransactions'])==2
        assert sorted(j['date'] for j in state['journals'].values())==['2026-10-03','2026-10-10','2026-10-31']
        for j in state['journals'].values():assert j['totalDebit']==j['totalCredit']
        def balance(code):return round(sum(l['debit']-l['credit'] for j in state['journals'].values() for l in j['lines'] if l['account']==code),2)
        assert balance('1110')==-120000 and balance('1120')==-200000
        assert balance('1430')==10000 and balance('1440')==0
        assert state['salaryPeriods']['TTI|2026-10|Mrs']['outstanding']==0
        assert state['salaryPeriods']['TTI|2026-10|Salman']['outstanding']==185000
        assert state['salaryAdvances']['P']['remaining']==5000
        before=saved();assert request({'action':'complete_salary_month','version':sheet['version'],'date':'2026-10-31'})[0]==200;assert saved()==before,'Completion retry is idempotent'
        # Held funds are a company routing asset until a confirmed onward receipt.
        held={'type':'COMPANY_HELD','amount':10000,'routeId':'R','reference':'HELD-2'}
        tx={**payment(10000,'2026-11-03','bank-b',[held]),'bankReference':'fixture-held-transfer'}
        assert action('save_salary_transactions','Mrs',month='2026-11',transactions=[tx],advanceApplied=0)[0]==200
        assert action('save_salary_transactions','Salman',month='2026-11',transactions=[],advanceApplied=0)[0]==200
        assert action('save_salary_transactions','Worker',month='2026-11',transactions=[],advanceApplied=0)[0]==200
        sheet=request(month='2026-11')[1]['salarySheet'];status,result=request({'action':'complete_salary_month','version':sheet['version'],'date':'2026-11-30'},'2026-11');assert status==200,result
        state=saved();assert balance('1440')==10000 and balance('1430')==10000
        # Reverse every leg together, including the BMA asset and all beneficiaries.
        bank_id=next(j['id'] for j in state['journals'].values() if j['sourceType']=='SALARY_ALLOCATED_PAYMENT' and j['date']=='2026-10-10')
        (root/'user.json').write_text(json.dumps({'role':'Accounts Assistant','id':2,'permissions':{'Accounts':{'expenses':['View','Create','Edit'],'assets':['View','Create']}}}))
        before=saved();status,rejected=request({'action':'reverse_salary_allocated_payment','journalId':bank_id,'date':'2026-10-10','reason':'Permission fixture'})
        assert status==422 and saved()==before,'Creating investment allocations does not grant permission to reverse them'
        (root/'user.json').unlink()
        status,result=request({'action':'reverse_salary_allocated_payment','journalId':bank_id,'date':'2026-10-10','reason':'Correct allocation fixture'})
        assert status==200,result
        state=saved();assert balance('1430')==0 and balance('1110')==-10000 and balance('1440')==10000
        assert state['salaryPeriods']['TTI|2026-10|Mrs']['outstanding']==100000
        assert state['salaryPeriods']['TTI|2026-10|Salman']['outstanding']==195000
        before=saved();assert request({'action':'reverse_salary_allocated_payment','journalId':bank_id,'date':'2026-10-10','reason':'Correct allocation fixture'})[0]==200;assert saved()==before
        # Explicit source refresh retains transactions and monthly overrides.
        assert action('adjust_salary_month','Worker',month='2027-02',netSalary=51000,advanceApplied=0)[0]==200
        tx=payment(100,'2027-02-03','CASH|TTI',[alloc('Worker',100)])
        assert action('save_salary_transactions','Worker',month='2027-02',transactions=[tx],advanceApplied=0)[0]==200
        state=saved();state['salaryAdvances']['NEW']={'id':'NEW','entity':'TTI','masterId':'Worker','date':'2027-01-10','amount':500,'remaining':500,'monthsRemaining':1,'personalLink':'EXP|new','adjustments':[]};(root/'data/accounts.json').write_text(json.dumps(state))
        fresh=request(month='2027-02')[1]['salarySheet'];assert fresh['sourceInputsChanged'] and fresh['rows']['Worker']['payment']['amount']==100
        status,result=request({'action':'refresh_salary_draft','version':fresh['version']},'2027-02');assert status==200,result
        assert result['salarySheet']['rows']['Worker']['netSalary']==51000 and result['salarySheet']['rows']['Worker']['payment']['amount']==100
        assert not result['salarySheet']['rows']['Worker']['reviewed']
        # Monthly Mrs amount plus extra expense, direct BMA capital and property payment:
        # one cash movement, canonical subsidiary records, atomic reversal and no entitlement inflation.
        original=saved()
        state=saved();state['managedAssets']={'PROP':{'id':'PROP','entity':'TTI','version':1,'type':'PROPERTY','name':'Private fixture property','ownerType':'COMPANY','currency':'PKR','purchaseDate':'2026-07-01','costCents':10000000,'payments':{},'schedule':[]}}
        state['managedAssets']['CAR']={'id':'CAR','entity':'TTI','type':'VEHICLE','name':'Fixture car','registrationNo':'QA-1','trackingOnly':True,'payments':{}}
        state['accountSubaccounts']={'SRP':{'id':'SRP','entity':'TTI','name':'SRP Home Expense','parentCode':'6910','active':True,'taxCategory':'NONE'}}
        (root/'data/accounts.json').write_text(json.dumps(state))
        choices=request()[1];assert any(x['id']=='SRP' for x in choices['allocationExpenses']);assert any(x['id']=='BROKER_FUND|BMA' for x in choices['allocationCapital'])
        expense={'type':'EXPENSE','amount':20000,'subaccountId':'SRP','purpose':'Extra household payment'}
        funding={'type':'BROKER_FUND','amount':10000,'brokerId':'BMA','reference':'DIRECT-BMA-APRIL'}
        asset={'type':'ASSET_PAYMENT','amount':30000,'assetId':'PROP','assetVersion':1,'instalmentNo':'APR-1','purpose':'Private fixture property instalment'}
        fuel={'type':'EXPENSE','amount':5000,'subaccountId':'TTI-EXPENSE-TYPE-FUEL','assetId':'CAR','purpose':'Car fuel'}
        tx=payment(365000,'2027-04-03','bank-b',[alloc('Mrs',300000),expense,funding,asset,fuel]);tx['bankReference']='Private fixture property transfer';tx['paymentNarration']='Private fixture property bank narration'
        for bad in [{**fuel,'assetId':''},{**expense,'subaccountId':'BRM-HOME-FAM-SALMAN'},{**expense,'subaccountId':'TTI-DEFAULT-TAX-EXPORT-RECOVERABLE'},{**asset,'assetVersion':0},{**asset,'amount':100001}]:
            before=saved();badtx=payment(bad['amount'],'2027-04-03','CASH|TTI',[bad]);assert action('save_salary_transactions','Mrs',month='2027-04',transactions=[badtx],advanceApplied=0)[0]==422;assert saved()==before
        (root/'user.json').write_text(json.dumps({'role':'Accounts Assistant','permissions':{'Accounts':{'expenses':['View','Create','Edit']}}}))
        assert request()[1]['allocationCapital']==[]
        before=saved();assert action('save_salary_transactions','Mrs',month='2027-04',transactions=[tx],advanceApplied=0)[0]==422;assert saved()==before
        (root/'user.json').unlink()
        status,result=action('save_salary_transactions','Mrs',month='2027-04',transactions=[tx],advanceApplied=0);assert status==200,result
        assert result['salarySheet']['rows']['Mrs']['payment']['amount']==300000
        assert result['salarySheet']['rows']['Mrs']['totalDue']==300000,'Extra Home Expense must be additional to the monthly Mrs entitlement'
        assert not saved()['managedAssets']['PROP']['payments'],'Saving a draft creates no property payment'
        for who in ['Salman','Worker']:assert action('save_salary_transactions',who,month='2027-04',transactions=[],advanceApplied=0)[0]==200
        sheet=request(month='2027-04')[1]['salarySheet'];body={'action':'complete_salary_month','version':sheet['version'],'date':'2027-04-30'}
        before=saved();status,preview=request({**body,'preview':True},'2027-04');assert status==200,preview;assert saved()==before
        # Concurrent asset change is detected at final posting, with all accounting rolled back.
        changed=saved();changed['managedAssets']['PROP']['version']=2;(root/'data/accounts.json').write_text(json.dumps(changed))
        status,rejected=request(body,'2027-04');assert status==422,rejected;assert saved()==changed
        (root/'data/accounts.json').write_text(json.dumps(before))
        status,result=request(body,'2027-04');assert status==200,result
        posted=saved();journal=next(j for j in posted['journals'].values() if j['sourceType']=='SALARY_ALLOCATED_PAYMENT' and j['date']=='2027-04-03')
        cashlines=[l for l in journal['lines'] if l['account']=='1110'];assert len(cashlines)==1 and cashlines[0]['credit']==365000
        assert any(l['account']=='6610' and l['debit']==5000 and l['assetId']=='CAR' and l['registrationNo']=='QA-1' for l in journal['lines'])
        assert any(l['account']=='6910' and l['debit']==20000 and l['subaccountId']=='SRP' for l in journal['lines'])
        assert any(l['account']=='1430' and l['debit']==10000 and l['brokerId']=='BMA' for l in journal['lines'])
        assert 'Private fixture property' not in json.dumps(journal),'Property details stay out of the public journal'
        link=next(iter(posted['managedAssets']['PROP']['payments'].values()));assert link['amountCents']==3000000 and link['journalId']==journal['id']
        assert any(x['type']=='FUND_DIRECT' and x['journalId']==journal['id'] and x['brokerDeltas']=={'BMA':1000000} for x in posted['investmentTransactions'].values())
        before_retry=saved();assert request(body,'2027-04')[0]==200;assert saved()==before_retry
        status,result=request({'action':'reverse_salary_allocated_payment','journalId':journal['id'],'date':'2027-04-03','reason':'Reverse complete extra payment'},'2027-04');assert status==200,result
        reversed_state=saved();assert not reversed_state['managedAssets']['PROP']['payments'];assert reversed_state['managedAssets']['PROP']['paymentCorrections'][0]['payment']==link
        assert reversed_state['salaryPeriods']['TTI|2027-04|Mrs']['outstanding']==300000
        assert all(x['status']=='Reversed' for x in reversed_state['investmentTransactions'].values() if x['journalId']==journal['id'])
        (root/'data/accounts.json').write_text(json.dumps(original))
        print('PASS extra expense + direct BMA funding + property instalment: one payment, independent monthly entitlement, permissions, stale-asset rollback, preview, retry and full reversal')
        # Owner's exact planning example, company-borne Home and director balance.
        original_plan=saved()
        state={key:original_plan[key] for key in ['salaryMasters','investmentBrokers','investmentRoutes','bankAccountSettings'] if key in original_plan}
        state['journals']={'CARD':{'id':'CARD','entity':'TTI','date':'2026-07-10','status':'Posted','sourceType':'PERSONAL_EXPENSE_ADJUSTMENT','meta':{},'lines':[{'account':'1230','debit':12000,'credit':0,'salaryMasterId':'Salman'},{'account':'6910','debit':0,'credit':12000}]}}
        state['salaryAdvances']={'CARD-ADV':{'id':'CARD-ADV','entity':'TTI','masterId':'Salman','person':'Salman','date':'2026-07-10','amount':12000,'remaining':12000,'monthsRemaining':1,'personalLink':'CARD','recoveryFromMonth':'2026-08','adjustments':[]}}
        (root/'data/accounts.json').write_text(json.dumps(state))
        assert action('adjust_salary_month','Mrs',month='2026-08',netSalary=310000,advanceApplied=0)[0]==200
        cheque={**payment(100000,'2026-08-03','bank-b',[alloc('Mrs',100000)]),'bankPaymentMethod':'CHEQUE','chequeNo':'MRS-100','chequeDate':'2026-08-03'}
        plan=[payment(210000,'2026-08-03','CASH|TTI',[alloc('Mrs',210000)]),cheque,payment(10000,'2026-08-03','CASH|TTI',[{'type':'BROKER_FUND','amount':10000,'brokerId':'BMA','reference':'PLAN-BMA-1'}]),payment(10000,'2026-08-03','CASH|TTI',[alloc('Salman',10000)])]
        before=saved();status,result=action('save_salary_transactions','Mrs',month='2026-08',transactions=plan,advanceApplied=0,companyHomeExpense=True);assert status==200,result
        assert result['salarySheet']['rows']['Mrs']['payment']['amount']==310000
        assert result['salarySheet']['rows']['Salman']['payment']['amount']==10000
        assert saved()['journals']==before['journals']
        assert action('save_salary_transactions','Salman',month='2026-08',transactions=[],advanceApplied=12000)[0]==200
        assert action('save_salary_transactions','Worker',month='2026-08',transactions=[],advanceApplied=0)[0]==200
        body={'action':'complete_salary_month','version':request(month='2026-08')[1]['salarySheet']['version'],'date':'2026-08-31'}
        before=saved();assert request({**body,'preview':True},'2026-08')[0]==200;assert saved()==before
        status,result=request(body,'2026-08');assert status==200,result
        state=saved();period=state['salaryPeriods']['TTI|2026-08|Salman'];assert period['outstanding']==178000
        assert state['salaryPeriods']['TTI|2026-08|Mrs']['outstanding']==0
        payments=[j for j in state['journals'].values() if j['sourceType']=='SALARY_ALLOCATED_PAYMENT'];assert sum(l['credit'] for j in payments for l in j['lines'] if l['account'] in ['1110','1120'])==330000
        assert sum(l['credit'] for j in payments for l in j['lines'] if l['account']=='1120')==230000
        assert sum(l['credit'] for j in payments for l in j['lines'] if l['account']=='1110')==100000
        accrual=state['journals'][state['salaryPeriods']['TTI|2026-08|Mrs']['journalId']];assert any(l['account']=='6910' and l['debit']==310000 and l['salaryMasterId']=='Mrs' for l in accrual['lines'])
        balance_row=next(r for r in request(month='2026-08')[1]['remunerationBalances'] if r['masterId']=='Salman');assert balance_row['receivableByDirector']==178000 and balance_row['recoverableFromDirector']==0
        # Partial deductions on a monthly Home amount and an additional expense debit
        # the director's payable without creating another bank/cash payment.
        home={**alloc('Mrs',100000),'remunerationMasterId':'Salman','remunerationAmount':10000}
        extra={'type':'EXPENSE','subaccountId':'TTI-HOME-FAM-SALMAN','amount':50000,'remunerationMasterId':'Salman','remunerationAmount':20000,'purpose':'Home with partial remuneration deduction'}
        for bad in [{**extra,'remunerationAmount':50001},{**extra,'remunerationMasterId':'Worker'}]:
            before=saved();assert action('save_salary_transactions','Mrs',month='2026-09',transactions=[payment(50000,'2026-09-03','CASH|TTI',[bad])],advanceApplied=0,companyHomeExpense=True)[0]==422;assert saved()==before
        tx=payment(160000,'2026-09-03','CASH|TTI',[home,extra,alloc('Salman',10000)])
        status,result=action('save_salary_transactions','Mrs',month='2026-09',transactions=[tx],advanceApplied=0,companyHomeExpense=True);assert status==200,result
        assert result['salarySheet']['rows']['Mrs']['payment']['amount']==100000
        assert result['salarySheet']['rows']['Salman']['payment']['amount']==40000
        for who in ['Salman','Worker']:assert action('save_salary_transactions',who,month='2026-09',transactions=[],advanceApplied=0)[0]==200
        status,result=request({'action':'complete_salary_month','version':request(month='2026-09')[1]['salarySheet']['version'],'date':'2026-09-30'},'2026-09');assert status==200,result
        state=saved();j=next(j for j in state['journals'].values() if j['sourceType']=='SALARY_ALLOCATED_PAYMENT' and j['date']=='2026-09-03')
        assert sum(l['credit'] for l in j['lines'] if l['account']=='1120')==160000
        assert sum(l['credit'] for l in j['lines'] if l['account']=='6910')==30000
        assert state['salaryPeriods']['TTI|2026-09|Mrs']['outstanding']==200000 and state['salaryPeriods']['TTI|2026-09|Salman']['outstanding']==160000
        status,result=request({'action':'reverse_salary_allocated_payment','journalId':j['id'],'date':'2026-09-03','reason':'Correct complete household plan'},'2026-09');assert status==200,result
        assert saved()['salaryPeriods']['TTI|2026-09|Mrs']['outstanding']==300000 and saved()['salaryPeriods']['TTI|2026-09|Salman']['outstanding']==200000
        # An unfinished, already accrued Home month gets an explicit reclassification,
        # preserving its original journal instead of silently rewriting history.
        status,result=request({'action':'prepare_month','date':'2026-07-31'},'2026-07');assert status==200,result
        prior=saved();prior_journals=prior['journals'].copy()
        for who in ['Mrs','Salman','Worker']:
            status,result=action('save_salary_transactions',who,month='2026-07',transactions=[],advanceApplied=0,companyHomeExpense=who=='Mrs');assert status==200,result
        status,result=request({'action':'complete_salary_month','version':request(month='2026-07')[1]['salarySheet']['version'],'date':'2026-07-31'},'2026-07');assert status==200,result
        after=saved();assert all(after['journals'][id]==j for id,j in prior_journals.items())
        new=[j for id,j in after['journals'].items() if id not in prior_journals];assert len(new)==1
        assert any(l['account']=='6910' and l['debit']==300000 for l in new[0]['lines'])
        assert any(l['account']=='3200' and l['credit']==300000 for l in new[0]['lines'])
        assert not any(l['account'] in ['1110','1120','2140'] for l in new[0]['lines'])
        (root/'data/accounts.json').write_text(json.dumps(original_plan))
        print('PASS exact Rs330,000 plan; company Home expense; partial remuneration/home deductions; credit-card debit and unpaid director receivable; no duplicate cash and atomic reversal')
        if os.getenv('TT_QA_BROWSER')=='1':
            from playwright.sync_api import sync_playwright
            with sync_playwright() as pw:
                browser=pw.chromium.launch(headless=True);page=browser.new_page(viewport={'width':1280,'height':900});errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
                page.goto(f'http://127.0.0.1:{port}/accounts/index.html');page.locator('[data-expense="salary"]').click();page.locator('#rsPrepare').click()
                page.locator('#rsMonth').fill('2027-03');page.locator('#rsMonth').dispatch_event('change')
                assert action('adjust_salary_month','Mrs',month='2027-03',netSalary=310000,advanceApplied=0)[0]==200
                page.locator('#rsRefresh').click()
                page.locator('[data-rs-draft-pay="Mrs"]').click()
                assert page.locator('.rs-plan-row').count()==0
                assert page.locator('#rsFamilyMonthly').input_value()=='310000'
                assert page.locator('#rsFamilyCash').input_value()=='310000'
                assert not page.locator('#rsFamilyBankFields').is_visible()
                assert page.locator('[data-tx-recipient]').count()==0
                page.locator('#rsAddTransaction').click();tx=page.locator('.rs-plan-row').last
                tx.locator('[data-plan-ledger]').select_option('BROKER_FUND|BMA');tx.locator('[data-allocation-amount]').fill('10000');tx.locator('[data-allocation-reference]').fill('MARCH-BMA-1')
                page.locator('#rsAddTransaction').click();tx=page.locator('.rs-plan-row').last
                tx.locator('[data-plan-ledger]').select_option('SALARY|Salman');tx.locator('[data-allocation-amount]').fill('10000')
                assert page.locator('#rsFamilyCash').input_value()=='330000'
                before_ui=saved();page.locator('#rsAddTransaction').click();tx=page.locator('.rs-plan-row').last
                tx.locator('[data-plan-ledger]').select_option('EXPENSE|TTI-HOME-FAM-SALMAN');tx.locator('[data-allocation-amount]').fill('20000');tx.locator('[data-plan-deduct]').check();tx.locator('[data-plan-director]').select_option('Salman')
                assert tx.locator('[data-plan-deduction-amount]').input_value()=='20000'
                tx.locator('[data-plan-deduction-amount]').fill('-1');tx.locator('[data-plan-deduct]').uncheck()
                assert not tx.locator('[data-plan-deduction-fields]').is_visible()
                assert saved()==before_ui,'Editing and adding/removing rows never saves automatically'
                tx.locator('[data-remove-transaction]').click();assert page.locator('#rsFamilyCash').input_value()=='330000'
                page.locator('#rsFamilyCash').fill('210000');assert page.locator('#rsFamilyUseBank').is_checked()
                assert '120,000' in page.locator('#rsFamilyBankAmount').inner_text()
                page.locator('#rsFamilyDate').fill('2027-03-10');page.locator('#rsFamilyBank').select_option('bank-b');page.locator('[data-reference]').fill('MRS-100');page.locator('[data-cheque-date]').fill('2027-03-10')
                page.locator('#rsPaySalBtn').click();page.locator('[data-rs-draft-pay="Mrs"]').filter(has_text='Amend').wait_for()
                assert request(month='2027-03')[1]['salarySheet']['rows']['Mrs']['payment']['amount']==310000
                assert request(month='2027-03')[1]['salarySheet']['rows']['Salman']['payment']['amount']==10000
                page.locator('[data-rs-draft-pay="Mrs"]').click();assert page.locator('.rs-plan-row').count()==2
                assert page.locator('.rs-plan-row').nth(0).locator('[data-plan-ledger]').input_value()=='BROKER_FUND|BMA'
                assert page.locator('.rs-plan-row').nth(1).locator('[data-plan-ledger]').input_value()=='SALARY|Salman'
                assert page.locator('#rsFamilyCash').input_value()=='210000' and '120,000' in page.locator('#rsFamilyBankAmount').inner_text()
                assert page.locator('[data-reference]').input_value()=='MRS-100'
                page.locator('#rsFamilyUseBank').uncheck();assert page.locator('#rsFamilyCash').input_value()=='330000' and not page.locator('#rsFamilyBankFields').is_visible()
                page.locator('#rsFamilyCash').fill('210000');assert page.locator('#rsFamilyUseBank').is_checked() and page.locator('[data-reference]').input_value()=='MRS-100'
                assert page.locator('#rsDraftForm').evaluate('(el)=>el.getBoundingClientRect().right<=innerWidth && el.getBoundingClientRect().left>=0')
                with page.expect_response(lambda response: 'rent_salary.php' in response.url and response.request.method=='POST') as saved_response:
                    page.locator('#rsPaySalBtn').click()
                assert saved_response.value.json()['ok']
                groups=request(month='2027-03')[1]['salarySheet']['paymentGroups']['Mrs'];assert len(groups)==2
                bank_group=next(t for t in groups if t['paymentAccountId']=='bank-b');assert bank_group['amount']==120000 and len(bank_group['allocations'])==3
                page.locator('[data-rs-draft-pay="Mrs"]').click();assert page.locator('.rs-plan-row').count()==2
                page.locator('#rsDraftCancel').click()
                for width in [1280,1024,700,390]:
                    page.set_viewport_size({'width':width,'height':900})
                    assert page.locator('.ttrs-salary-table').evaluate('(el)=>el.scrollWidth<=el.clientWidth+2')
                    for selector in ['[data-rs-draft-pay="Mrs"]','[data-rs-month-adjust="Mrs"]','[data-rs-sheet-edit="Mrs"]','[data-rs-sheet-remove="Mrs"]']:
                        assert page.locator(selector).evaluate('(el)=>{const r=el.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth}')
                page.set_viewport_size({'width':1280,'height':900})
                page.locator('[data-rs-draft-pay="Salman"]').click();assert 'Already allocated through another recipient' in page.locator('#rsDraftForm').inner_text()
                page.locator('[data-rs-month-adjust="Worker"]').click();page.locator('[name=monthlyAddition]').fill('5000');page.locator('[name=adjustmentReason]').fill('One-off bonus');assert page.locator('[data-month-total]').input_value()=='55000';page.locator('[data-month-total]').fill('56000');
                if os.getenv('TT_QA_OUTPUT'):page.locator('dialog').screenshot(path=str(pathlib.Path(os.environ['TT_QA_OUTPUT'])/'salary-month-adjustment.png'))
                page.get_by_role('button',name='Save month adjustment',exact=True).click()
                assert request(month='2027-03')[1]['salarySheet']['rows']['Worker']['totalDue']==56000
                assert saved()['salaryMasters']['Worker']['monthlyAmount']==50000
                assert not errors,errors
                browser.close()
                print('PASS native salary UI: actual transactions, per-row bank method, conditional company controls, allocation totals, saved restoration, incoming allocations and monthly adjustment')
        print('PASS monthly overrides, split beneficiaries, exact bank/cash totals, canonical BMA assets, held funds, actual dates, September→October recovery, preview and retries')
    finally:
        server.terminate();server.wait(timeout=5)
