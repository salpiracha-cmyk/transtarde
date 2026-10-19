"""Exercise durable salary drafts and one atomic final journal against the actual endpoint."""
import json, os, pathlib, re, shutil, socket, subprocess, tempfile, time, urllib.request, urllib.error
ROOT = pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='salary-month-qa-') as temp:
    root=pathlib.Path(temp)
    for folder in ['api','accounts','data']:(root/folder).mkdir()
    for name in ['company_investments_core.php','assets_registry_core.php','rent_salary.php','rent_salary_v2.php','salary_month_workflow.php','salary_flexible_workflow.php','salary_master_store.php','accounts_bank_payment.php', 'accounts_posting_preview.php']:
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
    function tt_require_login(){return ['role'=>'Super Admin','id'=>1,'username'=>'Fixture'];}
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
        if os.getenv('TT_QA_BROWSER')=='1':
            from playwright.sync_api import sync_playwright
            with sync_playwright() as pw:
                browser=pw.chromium.launch(headless=True);page=browser.new_page(viewport={'width':1280,'height':900});errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
                page.goto(f'http://127.0.0.1:{port}/accounts/index.html');page.locator('[data-expense="salary"]').click();page.locator('#rsPrepare').click()
                page.locator('#rsMonth').fill('2027-03');page.locator('#rsMonth').dispatch_event('change')
                page.locator('[data-rs-draft-pay="Mrs"]').click();page.locator('#rsAddTransaction').click()
                tx=page.locator('.rs-transaction').last;tx.locator('[data-tx-amount]').fill('200000');tx.locator('[data-allocation-amount]').fill('200000');tx.locator('[data-tx-date]').fill('2027-03-03')
                assert tx.locator('[data-tx-source]').input_value()=='CASH|TTI'
                page.locator('#rsAddTransaction').click();tx=page.locator('.rs-transaction').last
                tx.locator('[data-tx-amount]').fill('120000');tx.locator('[data-tx-date]').fill('2027-03-10');tx.locator('[data-tx-source]').select_option('bank-b');tx.locator('[data-method]').select_option('ONLINE_BANKING');tx.locator('[data-reference]').fill('MARCH-BANK-1');tx.locator('[data-cheque-date]').fill('2027-03-10')
                tx.locator('[data-allocation-amount]').fill('100000');tx.locator('[data-add-allocation]').click()
                allocation=tx.locator('.rs-allocation').last;allocation.locator('[data-allocation-target]').select_option('SALARY|Salman');allocation.locator('[data-allocation-amount]').fill('10000')
                tx.locator('[data-add-allocation]').click();allocation=tx.locator('.rs-allocation').last
                assert not allocation.locator('[data-allocation-route]').is_visible()
                allocation.locator('[data-allocation-target]').select_option('COMPANY_HELD');assert allocation.locator('[data-allocation-route]').is_visible();assert not allocation.locator('[data-allocation-broker]').is_visible()
                allocation.locator('[data-allocation-target]').select_option('BROKER_RECEIVED');allocation.locator('[data-allocation-amount]').fill('10000');allocation.locator('[data-allocation-route]').select_option('R');allocation.locator('[data-allocation-broker]').select_option('BMA');allocation.locator('[data-allocation-reference]').fill('BMA-MARCH-1')
                assert 'Difference Rs 0' in tx.locator('[data-transaction-balance]').inner_text()
                page.locator('#rsPaySalBtn').click();page.locator('[data-rs-draft-pay="Mrs"]').filter(has_text='Amend').wait_for()
                assert request(month='2027-03')[1]['salarySheet']['rows']['Mrs']['payment']['amount']==300000
                assert request(month='2027-03')[1]['salarySheet']['rows']['Salman']['payment']['amount']==10000
                page.locator('[data-rs-draft-pay="Mrs"]').click();assert page.locator('.rs-transaction').count()==2
                assert page.locator('.rs-allocation').count()==4
                page.locator('[data-rs-draft-pay="Salman"]').click();assert 'Already allocated through another recipient' in page.locator('#rsDraftForm').inner_text()
                assert page.locator('.rs-transaction').count()==0
                page.locator('[data-rs-month-adjust="Worker"]').click();page.locator('[name=monthlyAddition]').fill('5000');page.locator('[name=adjustmentReason]').fill('One-off bonus');assert page.locator('[data-month-total]').input_value()=='55000';page.locator('[data-month-total]').fill('56000');page.get_by_role('button',name='Save month adjustment',exact=True).click()
                assert request(month='2027-03')[1]['salarySheet']['rows']['Worker']['totalDue']==56000
                assert saved()['salaryMasters']['Worker']['monthlyAmount']==50000
                assert not errors,errors
                browser.close()
                print('PASS native salary UI: actual transactions, per-row bank method, conditional company controls, allocation totals, saved restoration, incoming allocations and monthly adjustment')
        print('PASS monthly overrides, split beneficiaries, exact bank/cash totals, canonical BMA assets, held funds, actual dates, September→October recovery, preview and retries')
    finally:
        server.terminate();server.wait(timeout=5)
