"""Exercise durable salary drafts and one atomic final journal against the actual endpoint."""
import json, os, pathlib, re, shutil, socket, subprocess, tempfile, time, urllib.request, urllib.error
ROOT = pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='salary-month-qa-') as temp:
    root=pathlib.Path(temp)
    for folder in ['api','accounts','data']:(root/folder).mkdir()
    for name in ['rent_salary.php','rent_salary_v2.php','salary_month_workflow.php','salary_flexible_workflow.php','salary_record_corrections.php','salary_extra_allocations.php','accounts_subaccounts_core.php','accounts_reference.php','accounts_ledger_visibility.php','assets_registry_core.php','company_investments_core.php','salary_master_store.php','accounts_bank_payment.php', 'accounts_posting_preview.php']:
        shutil.copy(ROOT/'api'/name,root/'api'/name)
    for name in ['accounting_master_v1.json','rent-salary-ui.js','cheque-date-ui.js','bank-payment-details.js','post-confirmation-ui.js']:
        shutil.copy(ROOT/'accounts'/name,root/'accounts'/name)
    masters={'banks':[{'id':'bank-a','values':['Company Account','TTI','','TTI','Fixture Bank','','','PKR','12345','','','','','Active']},{'id':'bank-b','values':['Company Account','TTI','','TTI','Other Bank','','','PKR','67890','','','','','Active']}]}
    (root/'masters.json').write_text(json.dumps(masters))
    for extra in ['production_fixed_overhead.php','mill_recovery_core.php']:shutil.copy(ROOT/'api'/extra,root/'api'/extra)
    (root/'data/operations.json').write_text(json.dumps({'values':{}}))
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
    function tt_api_upsert_allowed($user,$endpoint,$body,$exists,$entity=null){return tt_user_can_access_entity($user,$entity,$exists?'Edit':'Create');}
    function tt_verify_csrf($csrf){return $csrf==='fixture';}
    function tt_bank_can_transact($id){return in_array($id,['bank-a','bank-b']);}
    function tt_next_post_id(array $existing, string $module='Accounts', string $area='Journal', ?string $date=null): string {
        $year=substr($date ?: date('Y-m-d'),0,4);$n=count($existing)+1;
        do {$id='POST-'.$year.'-'.str_pad((string)$n,5,'0',STR_PAD_LEFT);$n++;} while (isset($existing[$id]));
        return $id;
    }
    ''')
    popup_css=re.search(r'style.textContent = `([\s\S]*?)`;', (ROOT/'accounts/accounts-clean-ui.js').read_text()).group(1)
    (root/'accounts/index.html').write_text('<!doctype html><style>'+popup_css+'</style>'+'''<section class="workspace active tt-clean-modal tt-entry-only"><button data-expense="salary">Salaries</button><div id="expenseEditor"></div></section><script>window.TT_ACCOUNT_ACCESS={csrf:'fixture'};localStorage.setItem('tt_accounts_entity','TTI');</script><script src="cheque-date-ui.js"></script><script src="bank-payment-details.js"></script><script src="post-confirmation-ui.js"></script><script src="rent-salary-ui.js"></script>''')
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
        body={'action':'save_salary_transactions','masterId':mid,'transactions':([{'amount':amount,'date':month+'-01','paymentAccountId':source,'allocations':[{'type':'SALARY','masterId':mid,'amount':amount}]}] if amount else []),'version':version,**extra}
        if method:body['bankPaymentMethod']=method
        return request(body,month)
    try:
        assert wait()[0]==200
        store=saved();store['salaryMasters']['Bob']['category']='MILL_STAFF';store['salaryMasters']['Bob']['productionCostEligible']=True
        store['salaryMasters']['Cara']['category']='HOME_STAFF';(root/'data/accounts.json').write_text(json.dumps(store))
        status,result=draft('Alice',750);assert status==200,result
        def group(key,**extra):return request({'action':'post_salary_group','group':key,'version':request()[1]['salarySheet']['version'],**extra})
        before=saved();status,denied=group('MILL_STAFF');assert status==422,denied;assert saved()==before
        assert request({'action':'close_salary_month','version':request()[1]['salarySheet']['version']})[0]==422
        before=saved();status,preview=group('OFFICE_STAFF',preview=True);assert status==200,preview;assert preview['previews'];assert saved()==before
        status,posted=group('OFFICE_STAFF');assert status==200,posted
        sheet=posted['salarySheet'];assert sheet['status']=='Draft';assert sheet['rows']['Alice']['groupPosted'];assert not sheet['rows']['Bob'].get('groupPosted')
        # A posted group is searchable/amendable while other groups remain untouched drafts.
        isolated_before=saved();records=request()[1]['salaryPayments'];assert len(records)==1,records
        entry=records[0];changed=json.loads(json.dumps(entry['transaction']));changed['amount']=650;changed['allocations'][0]['amount']=650
        amendment={'action':'amend_salary_allocated_payment','journalId':entry['journalId'],'transaction':changed,'reason':'Correct posted group fixture payment','snapshot':request()[1]['salaryCorrectionSnapshot'],'requestKey':'group-edit-fixture'}
        before=saved();assert request({**amendment,'preview':True})[0]==200 and saved()==before
        status,edited=request(amendment);assert status==200,edited
        current=saved();assert current['salaryPeriods']['TTI|2026-10|Alice']['outstanding']==100
        assert current['salarySheets']['TTI|2026-10']['rows']['Alice']['outstanding']==100
        for who in ['Bob','Cara']:assert current['salarySheets']['TTI|2026-10']['rows'][who]==before['salarySheets']['TTI|2026-10']['rows'][who]
        assert request(amendment)[1]['result']['duplicate'] and saved()==current
        bank_balance={'action':'salary_payment','masterId':'Alice','amount':40,'date':'2026-10-03','paymentAccountId':'bank-a','bankPaymentMethod':'CHEQUE','chequeNo':'GROUP-REF','chequeDate':'2026-10-03','requestKey':'group-bank-balance'}
        status,bank_paid=request(bank_balance);assert status==200,bank_paid
        bank_before=saved();status,duplicate_bank=request({**bank_balance,'amount':20,'requestKey':'group-bank-duplicate'});assert status==422 and 'reference is already posted' in duplicate_bank['error'],duplicate_bank
        assert saved()==bank_before,'Duplicate bank reference before month closure must leave books unchanged'
        balance={'action':'salary_payment','masterId':'Alice','amount':60,'date':'2026-10-03','paymentAccountId':'CASH|TTI','requestKey':'group-balance-fixture'}
        status,paid=request(balance);assert status==200,paid
        assert saved()['salarySheets']['TTI|2026-10']['rows']['Alice']['outstanding']==0
        assert len(request()[1]['salaryPayments'])==3
        assert request(balance)[1]['result']['duplicate']
        (root/'data/accounts.json').write_text(json.dumps(isolated_before))
        after=saved();assert after['salaryAdvances']['ADV']['remaining']==0
        periods=after['salaryPeriods'];assert len(periods)==1;assert next(iter(periods.values()))['paidAfterPrepare']==750
        journal_count=len(after['journals']);status,retry=group('OFFICE_STAFF');assert status==200,retry;assert saved()==after
        assert draft('Alice',1)[0]==422,'Posted salary must use audited amendment'
        status,result=draft('Bob',500);assert status==200,result
        sheet=request()[1]['salarySheet'];assert sheet['rows']['Alice']['outstanding']==0;assert sheet['rows']['Alice']['paidAfterPrepare']==750
        status,posted=group('MILL_STAFF');assert status==200,posted
        assert len(saved()['journals'])>journal_count
        cash=sum(float(line.get('credit',0)) for j in saved()['journals'].values() for line in j['lines'] if line['account']=='1120')
        assert cash==1250,cash
        assert draft('Cara',0)[0]==200
        assert group('HOME_STAFF')[0]==200
        before=saved();status,closed=request({'action':'close_salary_month','version':request()[1]['salarySheet']['version']});assert status==200,closed
        after=saved();assert after['journals']==before['journals'];assert after['salaryAdvances']==before['salaryAdvances'];assert closed['salarySheet']['status']=='Completed'
        assert request({'action':'close_salary_month','version':0})[0]==200;assert saved()==after
        first_payment=closed['salarySheet']['postedGroups']['OFFICE_STAFF']['paymentPostIds'][0]
        status,reversed=request({'action':'reverse_salary_allocated_payment','journalId':first_payment,'date':'2026-10-02','reason':'Correct the original salary payment'});assert status==200,reversed
        row=reversed['salarySheet']['rows']['Alice'];assert row['outstanding']==750,row;assert row['payment']['amount']==0,row
        assert saved()['salaryPeriods'][row['periodId']]['paidAfterPrepare']==0
        # Legacy accrued group can defer its full balance with no new journal.
        assert request({'action':'prepare_month'},'2026-11')[0]==200
        assert draft('Alice',0,month='2026-11')[0]==200
        before=saved();status,d=request({'action':'post_salary_group','group':'OFFICE_STAFF','version':request(month='2026-11')[1]['salarySheet']['version']},'2026-11');assert status==200,d
        assert saved()['journals']==before['journals'];assert d['salarySheet']['rows']['Alice']['groupPosted']
        if os.getenv('TT_QA_BROWSER')=='1':
            from playwright.sync_api import sync_playwright
            initial['salaryMasters']['Bob']['category']='MILL_STAFF';initial['salaryMasters']['Cara']['category']='HOME_STAFF'
            (root/'data/accounts.json').write_text(json.dumps(initial))
            assert request({'action':'prepare_month'})[0]==200
            status,result=draft('Alice',750);assert status==200,result
            store=saved();store['salaryMasters']['Alice']['monthlyAmount']=1100;(root/'data/accounts.json').write_text(json.dumps(store))
            with sync_playwright() as pw:
                browser=pw.chromium.launch(headless=True);page=browser.new_page(viewport={'width':1280,'height':900})
                page.goto(f'http://127.0.0.1:{port}/accounts/index.html');page.locator('[data-expense="salary"]').click()
                page.locator('#rsMasterSetup').click();page.locator('#rsMasterPopup').wait_for();assert page.locator('#rsApplyMaster').is_visible()
                assert page.locator('#rsMasterEntry').get_attribute('open') is None
                assert page.locator('[data-rs-group^="MASTER_"]').count()==3
                page.locator('[data-rs-edit="Alice"]').click();assert page.locator('#rsMasterEntry').get_attribute('open') is not None
                assert page.locator('#rsSalName').input_value()=='Alice'
                page.locator('#rsCancelSal').click();assert page.locator('#rsMasterEntry').get_attribute('open') is None
                before=saved();page.locator('#rsApplyMaster').click();confirmation=page.get_by_role('dialog',name='Review salary change');confirmation.wait_for();confirmation.get_by_role('button',name='Cancel',exact=True).click();assert saved()==before
                page.locator('#rsApplyMaster').click();page.get_by_role('dialog',name='Review salary change').get_by_role('button',name='Continue',exact=True).click()
                review=page.get_by_role('dialog',name='Review accounting entries');review.wait_for();assert not page.locator('#rsMasterPopup').is_visible()
                review.get_by_role('button',name='Back to entry',exact=True).click();page.locator('#rsMasterPopup').wait_for();assert saved()==before
                page.locator('#rsMasterClose').click();page.locator('#rsPrepare').click()
                page.locator('#rsMonth').fill('2026-10');page.locator('#rsMonth').dispatch_event('change')
                page.locator('[data-rs-post-group="OFFICE_STAFF"]').wait_for()
                assert page.locator('#rsRefresh,#rsApplyMaster,#rsCollapseGroups,#rsExpandGroups,#rsResetDraft').count()==0
                assert page.locator('[data-rs-post-group]').count()==3
                assert page.locator('#rsSalaryComplete').is_disabled()
                office=page.locator('[data-rs-group="OFFICE_STAFF"]');assert office.get_attribute('open') is None;office.locator('summary').click();assert office.get_attribute('open') is not None
                office.locator('[data-rs-post-group]').click()
                page.get_by_role('dialog',name='Review accounting entries').get_by_role('button',name='Confirm and Post',exact=True).click()
                page.locator('[data-rs-post-group="OFFICE_STAFF"]:disabled').wait_for()
                assert office.get_attribute('open') is not None,'Post all must not toggle the expand bar'
                assert page.locator('[data-rs-post-group="MILL_STAFF"]').is_enabled()
                assert page.locator('#rsSalaryComplete').is_disabled()
                page.locator('[data-rs-group="MILL_STAFF"] > summary').click();assert page.locator('[data-rs-group="MILL_STAFF"]').get_attribute('open') is not None
                assert saved()['salarySheets']['TTI|2026-10']['status']=='Draft'
                browser.close()
        print('PASS independent group posting, read-only preview, advance/cash exactly once, retained balances, no-journal deferral, stale/invalid and incomplete holds, close without reposting')
    finally:
        server.terminate();server.wait()
