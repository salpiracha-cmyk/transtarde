"""Exercise durable salary drafts and one atomic final journal against the actual endpoint."""
import json, os, pathlib, re, shutil, socket, subprocess, tempfile, time, urllib.request, urllib.error
ROOT = pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='salary-month-qa-') as temp:
    root=pathlib.Path(temp)
    for folder in ['api','accounts','data']:(root/folder).mkdir()
    for name in ['rent_salary.php','rent_salary_v2.php','salary_month_workflow.php','salary_flexible_workflow.php','salary_record_corrections.php','salary_extra_allocations.php','accounts_subaccounts_core.php','accounts_receipts_core.php','accounts_reference.php','accounts_ledger_visibility.php','assets_registry_core.php','company_investments_core.php','salary_master_store.php','accounts_bank_payment.php', 'accounts_posting_preview.php']:
        shutil.copy(ROOT/'api'/name,root/'api'/name)
    for name in ['accounting_master_v1.json','rent-salary-ui.js','cheque-date-ui.js','bank-payment-details.js','post-confirmation-ui.js']:
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
    from permission_fixture import append_permission_helpers
    append_permission_helpers(root)
    popup_css=re.search(r'style.textContent = `([\s\S]*?)`;', (ROOT/'accounts/accounts-clean-ui.js').read_text()).group(1)
    (root/'accounts/index.html').write_text('<!doctype html><style>'+popup_css+'</style>'+'''<section class="workspace active tt-clean-modal tt-entry-only"><button data-expense="salary">Salaries</button><div id="expenseEditor"></div></section><script>window.TT_ACCOUNT_ACCESS={csrf:'fixture'};localStorage.setItem('tt_accounts_entity','TTI');</script><script src="cheque-date-ui.js"></script><script src="bank-payment-details.js"></script><script src="post-confirmation-ui.js"></script><script>TT_POST_CONFIRMATION.show=ids=>{window.lastAdvancePost=ids[0]};</script><script src="rent-salary-ui.js"></script>''')
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
        status,data=wait();assert status==200,data
        assert data['salarySheet']['rows']['Alice']['outstanding']==750
        assert saved()==initial,'Opening the sheet must not write or accrue'
        assert draft('Alice',1051)[0]==422,'Cannot exceed entitlement'
        assert draft('Alice',1050)[0]==200,'Can defer all advance adjustment'
        assert draft('Bob',600,'bank-b','CHEQUE')[0]==422,'Cheque number is mandatory'
        assert draft('Bob',600,'bank-b','ONLINE_BANKING',cashAmount=300,bankAmount=301)[0]==422,'Split must equal total'
        assert draft('Bob',600,'bank-b','CHEQUE',cashAmount=300,bankAmount=300)[0]==422,'Split bank cheque still requires number'
        assert draft('Bob',600,'bank-b','ONLINE_BANKING',cashAmount=300,bankAmount=300)[0]==200,'Online reference optional; overdraft allowed'
        assert draft('Cara',0)[0]==200,'Zero-payment deferral'
        assert not saved().get('journals'),'Individual POST is a draft only'
        assert draft('Bob',500,version=0)[0]==422,'Stale draft overwrite rejected'
        server.terminate();server.wait(timeout=5);server=start();wait()
        sheet=request()[1]['salarySheet'];assert sheet['rows']['Bob']['payment']['amount']==600,'Draft survives restart'
        status,data=request({'action':'complete_salary_month','version':sheet['version'],'date':'2026-10-01'});assert status==200,data
        journalId=data['result']['journalId'];store=saved();assert len(store['journals'])==1
        journal=store['journals'][journalId];assert journal['totalDebit']==journal['totalCredit']
        assert store['salaryAdvances']['ADV']['remaining']==300,'Deferred advance carried forward'
        assert store['salaryPeriods']['TTI|2026-10|Bob']['outstanding']==450
        assert store['salaryPeriods']['TTI|2026-10|Cara']['outstanding']==1050
        assert any(l.get('party')=='Bob' and l['account']=='2230' for l in journal['lines'])
        bob=[l for l in journal['lines'] if l.get('party')=='Bob']
        assert any(l['account']=='1120' and l['credit']==300 for l in bob),bob
        assert any(l['account']=='1110' and l['credit']==300 for l in bob),bob
        assert any(l.get('bankPaymentMethod')=='ONLINE_BANKING' and not l['bankReference'] for l in journal['lines'])
        assert request({'action':'complete_salary_month','version':sheet['version'],'date':'2026-10-01'})[1]['result']['journalId']==journalId
        assert len(saved()['journals'])==1,'Completion retry cannot double-post'
        assert draft('Bob',10)[0]==422,'Completed month cannot reopen as a draft'
        # New month: standard advance deduction, partial cash, cheque tracking.
        assert draft('Alice',500,month='2026-11')[0]==200
        assert draft('Bob',1050,'bank-a','CHEQUE',month='2026-11',chequeNo='001234',chequeDate='2026-10-01')[0]==200
        assert draft('Cara',1050,'bank-b','ONLINE_BANKING',month='2026-11')[0]==200
        version=request(month='2026-11')[1]['salarySheet']['version']
        status,data=request({'action':'complete_salary_month','version':version,'date':'2026-11-01'},'2026-11');assert status==200,data
        store=saved();assert store['salaryAdvances']['ADV']['remaining']==0
        assert store['salaryPeriods']['TTI|2026-11|Alice']['outstanding']==250
        assert any(l.get('chequeNo')=='001234' for l in store['journals'][data['result']['journalId']]['lines'])
        # Legacy accrued month: final posting settles, never accrues twice, restores advance.
        before=len(store['journals']);status,data=request({'action':'prepare_month'},'2026-12');assert status==200,data
        accrued=len(saved()['journals']);assert accrued==before+3
        for mid in ['Alice','Bob','Cara']:assert draft(mid,1050,month='2026-12')[0]==200
        version=request(month='2026-12')[1]['salarySheet']['version']
        status,data=request({'action':'complete_salary_month','version':version,'date':'2026-12-01'},'2026-12');assert status==200,data
        assert all(l['account'] in ['2230','1120'] for l in saved()['journals'][data['result']['journalId']]['lines']),'Legacy accrual cannot duplicate expenses'
        # Master Records edits must invalidate a reviewed but unposted salary draft.
        assert draft('Alice',100,month='2027-02')[0]==200
        prior_journals=saved()['journals'].copy()
        master_edit='''require "auth_store.php"; require "api/salary_master_store.php";
        sm_save_master(["Alice Updated","TTI","OFFICE_STAFF","1200","20","30","2026-01-01","","STAFF_COST","No","Active",""],["username"=>"Fixture"],"Alice");
        sm_deactivate_master("Bob",["username"=>"Fixture"]);'''
        subprocess.run(['php','-r',master_edit],cwd=root,check=True)
        status,data=request(month='2027-02');assert status==200,data
        sheet=data['salarySheet'];assert sheet['masterChanged'] and sheet['canRefreshFromMaster']
        assert sheet['rows']['Alice']['name']=='Alice' and sheet['rows']['Alice']['payment']['amount']==100
        assert all(m['status']=='Inactive' for m in data['salaryMasters'] if m['id']=='Bob')
        assert request({'action':'complete_salary_month','version':sheet['version'],'date':'2027-02-01'},'2027-02')[0]==422
        status,data=request({'action':'refresh_salary_draft','version':sheet['version']},'2027-02');assert status==200,data
        sheet=data['salarySheet'];assert not sheet.get('masterChanged') and 'Bob' not in sheet['rows']
        assert sheet['rows']['Alice']['name']=='Alice Updated' and sheet['rows']['Alice']['totalDue']==1250
        assert not sheet['rows']['Alice']['reviewed'] and sheet['rows']['Alice']['payment'] is None
        assert saved()['journals']==prior_journals
        assert request(month='2026-10')[1]['salarySheet']['status']=='Completed'
        assert draft('Alice',100,version=1,month='2027-02')[0]==422,'The prior tab cannot overwrite the refreshed draft'
        # With no saved payment choices, the refreshed draft can pick up another edit automatically.
        subprocess.run(['php','-r','require "auth_store.php"; require "api/salary_master_store.php"; sm_save_master(["Alice Updated","TTI","OFFICE_STAFF","1300","20","30","2026-01-01","","STAFF_COST","No","Active",""],["username"=>"Fixture"],"Alice");'],cwd=root,check=True)
        assert request(month='2027-02')[1]['salarySheet']['rows']['Alice']['totalDue']==1350
        assert request({'action':'prepare_month'},'2027-04')[0]==200
        prepared_journals=saved()['journals'].copy()
        subprocess.run(['php','-r','require "auth_store.php"; require "api/salary_master_store.php"; sm_save_master(["Alice Updated","TTI","OFFICE_STAFF","1400","20","30","2026-01-01","","STAFF_COST","No","Active",""],["username"=>"Fixture"],"Alice");'],cwd=root,check=True)
        prepared=request(month='2027-04')[1]['salarySheet']
        assert not prepared.get('masterChanged'),'Later master defaults cannot block an accrued salary payment'
        assert prepared['rows']['Alice']['totalDue']==1350,'Prepared accounting retains its posted amount'
        assert request({'action':'refresh_salary_draft','version':prepared['version']},'2027-04')[0]==422
        assert saved()['journals']==prepared_journals
        # The actual flexible payment endpoint must accept an accrued month whose
        # master was renamed/repriced, retain saved plans, and reject stale tabs.
        tx={'amount':100,'date':'2027-04-03','paymentAccountId':'CASH|TTI','recipient':'Alice Updated','allocations':[{'type':'SALARY','masterId':'Alice','amount':100}]}
        status,data=request({'action':'save_salary_transactions','masterId':'Alice','version':prepared['version'],'transactions':[tx],'advanceApplied':0},'2027-04');assert status==200,data
        old_version=data['salarySheet']['version']
        subprocess.run(['php','-r','require "auth_store.php"; require "api/salary_master_store.php"; sm_save_master(["Alice New Name","TTI","OFFICE_STAFF","1500","20","30","2026-01-01","","STAFF_COST","No","Active",""],["username"=>"Fixture"],"Alice");'],cwd=root,check=True)
        after=request(month='2027-04')[1]['salarySheet']
        assert not after.get('masterChanged') and after['rows']['Alice']['payment']['amount']==100
        assert after['rows']['Alice']['totalDue']==1350 and after['rows']['Alice']['name']=='Alice Updated'
        snapshot=saved();changed=json.loads(json.dumps(snapshot));changed['salaryPeriods']['TTI|2027-04|Alice']['outstanding']-=1;(root/'data/accounts.json').write_text(json.dumps(changed))
        status,rejected=request({'action':'save_salary_transactions','masterId':'Alice','version':after['version'],'transactions':[tx],'advanceApplied':0},'2027-04')
        assert status==422 and 'balances changed' in rejected['error'],'The original period baseline remains protected'
        (root/'data/accounts.json').write_text(json.dumps(snapshot))
        changed=json.loads(json.dumps(snapshot));changed['salaryMasters']['Added']=staff('Added');(root/'data/accounts.json').write_text(json.dumps(changed))
        assert request(month='2027-04')[1]['salarySheet'].get('masterChanged'),'An added unprepared person still requires review'
        (root/'data/accounts.json').write_text(json.dumps(snapshot))
        for mid in after['rows']:
            status,data=request({'action':'save_salary_transactions','masterId':mid,'version':data['salarySheet']['version'],'transactions':[tx] if mid=='Alice' else [],'advanceApplied':0},'2027-04');assert status==200,data
        assert request({'action':'save_salary_transactions','masterId':'Alice','version':old_version,'transactions':[tx],'advanceApplied':0},'2027-04')[0]==422
        assert saved()['journals']==prepared_journals,'Draft changes cannot post or rewrite accruals'
        status,data=request({'action':'complete_salary_month','version':data['salarySheet']['version'],'date':'2027-04-03'},'2027-04');assert status==200,data
        assert all(saved()['journals'][jid]==j for jid,j in prepared_journals.items())
        new_journals=[j for jid,j in saved()['journals'].items() if jid not in prepared_journals]
        assert all(l['account'] not in ['6210','6220','7210'] for j in new_journals for l in j['lines']),'No duplicate salary expense'
        assert saved()['salaryPeriods']['TTI|2027-04|Alice']['outstanding']==1250
        print('Salary drafts, restart persistence, amount caps, payable and advance balances, mixed payments, cheque validation, overdraft and atomic final retry passed')
        # Explicit reset clears only unfinished plans, retaining the accrued books.
        assert request({'action':'prepare_month'},'2028-06')[0]==200
        current=request(month='2028-06')[1]['salarySheet']
        tx['date']='2028-06-03'
        status,data=request({'action':'save_salary_transactions','masterId':'Alice','version':current['version'],'transactions':[tx],'advanceApplied':0},'2028-06');assert status==200,data
        reset_before=saved();version=data['salarySheet']['version']
        status,data=request({'action':'reset_salary_draft','version':version,'reason':'Owner requests clean payment inputs'},'2028-06');assert status==200,data
        assert data['result']['draftReset'] and all(not r['reviewed'] and r['payment'] is None for r in data['salarySheet']['rows'].values())
        assert 'paymentGroups' not in data['salarySheet']
        reset_after=saved()
        for key in ['journals','salaryPeriods','salaryAdvances','salaryMasters']:assert reset_before.get(key)==reset_after.get(key),key
        assert len(reset_after['salaryDraftResets'])==1 and reset_after['salaryDraftResets'][0]['before']['paymentGroups']
        assert request({'action':'reset_salary_draft','version':version,'reason':'Stale tab'},'2028-06')[0]==422
        assert saved()==reset_after,'Stale reset cannot clear newer work'
        assert request({'action':'reset_salary_draft','version':0,'reason':'Completed month'},'2026-10')[0]==422
        assert saved()==reset_after,'Completed salary cannot be reset'
        # Recurring changes are durable drafts, then commit with the final journal.
        state=saved();state['salaryMasters']['Bob']['status']='Active';state['salaryMasters']['Bob']['effectiveTo']='';(root/'data/accounts.json').write_text(json.dumps(state))
        month='2027-05';sheet=request(None,month)[1]['salarySheet'];old_master=json.loads(json.dumps(saved()['salaryMasters']['Alice']));old_periods=json.loads(json.dumps(saved().get('salaryPeriods',{})))
        edit={'action':'edit_salary_sheet_row','masterId':'Alice','version':sheet['version'],'name':'Alice','category':'OFFICE_STAFF','netSalary':2200,'zakatAmount':0,'otherAllowance':75}
        status,data=request(edit,month);assert status==200,data
        assert saved()['salaryMasters']['Alice']==old_master,'Drafts cannot overwrite master defaults'
        assert request(edit,month)[0]==422,'Stale edits are rejected'
        status,data=request({'action':'edit_salary_sheet_row','version':data['salarySheet']['version'],'name':'New Staff','category':'OFFICE_STAFF','netSalary':900,'zakatAmount':0,'otherAllowance':0},month);assert status==200,data
        new_id=next(row['masterId'] for row in data['salarySheet']['rows'].values() if row['name']=='New Staff');assert new_id not in saved()['salaryMasters']
        status,data=request({'action':'edit_salary_sheet_row','operation':'remove','masterId':'Bob','version':data['salarySheet']['version']},month);assert status==200,data
        assert saved()['salaryMasters']['Bob']['status']=='Active'
        for row in list(data['salarySheet']['rows'].values()):
            status,data=request({'action':'save_salary_draft_payment','masterId':row['masterId'],'version':data['salarySheet']['version'],'amount':0,'date':'2027-05-31','paymentAccountId':'CASH|TTI'},month);assert status==200,data
        before=saved();status,rejected=request({'action':'complete_salary_month','version':data['salarySheet']['version'],'date':'bad'},month);assert status==422,rejected;assert saved()==before,'Failed completion rolls back all changes'
        status,data=request({'action':'complete_salary_month','version':data['salarySheet']['version'],'date':'2027-05-31'},month);assert status==200,data
        assert saved()['salaryMasters']['Alice']['monthlyAmount']==2200 and saved()['salaryMasters']['Alice']['zakatAmount']==0
        assert saved()['salaryMasters']['Bob']['status']=='Inactive' and saved()['salaryMasters'][new_id]['monthlyAmount']==900
        for key,value in old_periods.items():assert saved()['salaryPeriods'][key]==value,'Past posted salaries remain unchanged'
        future=request(None,'2027-06')[1]['salarySheet']['rows'];assert future['Alice']['netSalary']==2200 and new_id in future and 'Bob' not in future
        # New routed advances keep their explicit starting month and fixed instalments.
        (root/'data/accounts.json').write_text(json.dumps(initial))
        advance={'action':'record_salary_advance','masterId':'Bob','amount':1000,'months':3,'date':'2026-10-20','recoveryFromMonth':'2026-11','paymentAccountId':'CASH|TTI','requestKey':'scheduled-advance-1'}
        before=saved();status,preview=request({**advance,'preview':True});assert status==200 and preview['previews'] and saved()==before
        status,posted=request(advance);assert status==200,posted;aid=posted['result']['advanceId']
        assert request()[1]['salarySheet']['rows']['Bob']['advanceSuggested']==0
        assert request(None,'2026-11')[1]['salarySheet']['rows']['Bob']['advanceSuggested']==333.34
        before=saved();assert request(advance)[1]['result']['duplicate'] and saved()==before
        assert request({**advance,'amount':2000})[0]==422 and saved()==before
        status,second=request({**advance,'amount':100,'months':2,'requestKey':'scheduled-advance-2'});assert status==200,second;second_id=second['result']['advanceId']
        for mo,remaining in [('2026-11',666.66),('2026-12',333.32),('2027-01',0)]:
            status,prepared=request({'action':'prepare_month'},mo);assert status==200,prepared
            assert abs(saved()['salaryAdvances'][aid]['remaining']-remaining)<.005
            assert saved()['salaryAdvances'][second_id]['remaining']==(50 if mo=='2026-11' else 0)
            before=saved();assert request({'action':'prepare_month'},mo)[0]==200 and saved()['salaryAdvances'][aid]==before['salaryAdvances'][aid]
        assert all(j['sourceType']!='EXPENSE_PAYMENT' for j in saved()['journals'].values())
        # A routed holder payment reduces the exact cash register once and cannot overspend it.
        state=saved();cash_id='CASH|TTI|STAFF|BOB';state['pettyCashHolders']={cash_id:{'id':cash_id,'entity':'TTI','staffId':'Bob','name':'Bob','active':True}}
        state['journals']['CASH-FUND']={'id':'CASH-FUND','entity':'TTI','status':'Posted','date':'2026-10-01','sourceType':'PETTY_CASH_TRANSFER','lines':[{'account':'1120','debit':200,'credit':0,'cashAccountId':cash_id},{'account':'1120','debit':0,'credit':200,'cashAccountId':'CASH|TTI'}]}
        (root/'data/accounts.json').write_text(json.dumps(state));assert any(a['id']==cash_id for a in request()[1]['salaryAdvanceAccounts'])
        holder_advance={**advance,'amount':50,'months':2,'paymentAccountId':cash_id,'recoveryFromMonth':'2027-02','requestKey':'holder-advance-1'}
        before=saved();status,pre=request({**holder_advance,'preview':True});assert status==200,pre;assert saved()==before
        status,paid=request(holder_advance);assert status==200,paid
        journal=saved()['journals'][paid['result']['journalId']];assert journal['lines'][1]['cashAccountId']==cash_id
        assert next(a['bookBalance'] for a in request()[1]['salaryAdvanceAccounts'] if a['id']==cash_id)==150
        before=saved();assert request({**holder_advance,'amount':151,'requestKey':'holder-overspend'})[0]==422 and saved()==before
        # Save staff from the open sheet into the same master used by Master Records.
        mo='2027-02';sheet=request(None,mo)[1]['salarySheet'];old_posts=saved()['journals']
        status,added=request({'action':'edit_salary_sheet_row','syncMaster':True,'version':sheet['version'],'name':'New recurring staff','category':'OFFICE_STAFF','netSalary':800,'zakatAmount':0,'otherAllowance':0},mo);assert status==200,added;new_id=added['result']['masterId']
        assert saved()['salaryMasters'][new_id]['monthlyAmount']==800 and saved()['journals']==old_posts
        status,edited=request({'action':'edit_salary_sheet_row','syncMaster':True,'masterId':new_id,'version':added['salarySheet']['version'],'name':'New recurring staff','category':'OFFICE_STAFF','netSalary':850,'zakatAmount':0,'otherAllowance':0},mo);assert status==200,edited
        assert saved()['salaryMasters'][new_id]['monthlyAmount']==850
        status,deleted=request({'action':'edit_salary_sheet_row','syncMaster':True,'operation':'remove','masterId':new_id,'version':edited['salarySheet']['version']},mo);assert status==200,deleted
        assert saved()['salaryMasters'][new_id]['status']=='Inactive' and saved()['journals']==old_posts
        if os.getenv('TT_QA_BROWSER')=='1':
            # Browser setup is independent of the master-edit scenarios above.
            state=saved();state['salaryMasters']=json.loads(json.dumps(initial['salaryMasters']))
            (root/'data/accounts.json').write_text(json.dumps(state))
            from playwright.sync_api import sync_playwright
            with sync_playwright() as pw:
                browser=pw.chromium.launch(headless=True);page=browser.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
                page.goto(f'http://127.0.0.1:{port}/accounts/index.html');page.locator('[data-expense="salary"]').click()
                page.locator('#rsPrepare').wait_for();assert page.locator('#rsAdvanceIcon,#rsMasterSetup').count()==0
                page.evaluate("TT_RENT_SALARY_UI.openAdvance({paymentAccountId:'bank-b',date:'2026-10-20'})")
                advance_form=page.locator('#rsAdvancePopup form');advance_form.locator('[name=masterId]').select_option('Bob');advance_form.locator('[name=amount]').fill('300');advance_form.locator('[name=recovery]').select_option('INSTALMENTS');advance_form.locator('[name=months]').fill('3')
                assert advance_form.locator('[name=recoveryFromMonth]').input_value()=='2026-11'
                page.locator('#rsStandaloneAdvanceSourceDetails [data-method]').select_option('ONLINE_BANKING')
                before=saved();advance_form.get_by_role('button',name='Post Advance',exact=True).click();page.locator('[data-review-cancel]').click();page.locator('#rsAdvancePopup').wait_for(state='visible');assert saved()==before and advance_form.locator('[name=amount]').input_value()=='300'
                advance_form.get_by_role('button',name='Post Advance',exact=True).click();page.locator('[data-review-post]').click();page.locator('#rsAdvancePopup').wait_for(state='detached')
                assert len(saved()['journals'])==len(before['journals'])+1
                page.evaluate("TT_RENT_SALARY_UI.open('salary')");page.locator('#rsPrepare').click();page.locator('#rsMonth').fill('2027-01');page.locator('#rsMonth').dispatch_event('change')
                page.locator('[data-rs-group="OFFICE_STAFF"] > summary').click()
                page.locator('[data-rs-person="Bob"]').click();page.locator('#rsPaymentPopup').wait_for()
                net=request(month='2027-01')[1]['salarySheet']['rows']['Bob']['outstanding'];assert float(page.locator('#rsFamilyCash').input_value())==net
                assert page.locator('[data-tx-recipient],[data-allocation-amount],#rsFamilyUseRetain').count()==0
                page.locator('#rsFamilyCash').fill('250');page.locator('#rsFamilyUseBank').check()
                assert float(page.locator('#rsFamilyBankAmount').input_value())==net-250
                page.locator('#rsFamilyBank').select_option('bank-b');page.locator('[data-method]').select_option('ONLINE_BANKING')
                page.locator('#rsPaySalBtn').click();page.locator('#rsPaymentPopup').wait_for(state='detached')
                page.locator('[data-rs-person="Bob"]').click()
                assert page.locator('#rsFamilyCash').input_value()=='250' and float(page.locator('#rsFamilyBankAmount').input_value())==net-250
                assert page.locator('#rsFamilyBank').input_value()=='bank-b'
                page.locator('#rsDraftCancel').click()
                # A completed current month moves the opening month forward, while history stays read-only.
                state=saved();state['salarySheets']={k:v for k,v in state.get('salarySheets',{}).items() if v.get('status')!='Completed'}
                state['salarySheets']['TTI|2026-10']={'entity':'TTI','month':'2026-10','status':'Completed','version':1,'rows':{}}
                (root/'data/accounts.json').write_text(json.dumps(state));page.evaluate("TT_RENT_SALARY_UI.open('salary')");page.locator('#rsPrepare').wait_for();assert 'November 2026' in page.locator('#rsPrepare').inner_text()
                page.locator('#rsPrepare').click();page.get_by_role('dialog',name='Review salary change').get_by_role('button',name='Continue').click();page.locator('#rsMonth').wait_for();assert page.locator('#rsMonth').input_value()=='2026-11'
                page.locator('#rsMonth').fill('2026-10');page.locator('#rsMonth').dispatch_event('change');page.get_by_text('SALARY COMPLETED',exact=True).wait_for()
                assert page.locator('#rsMasterSetup,#rsSalaryDates,#rsSalaryReview').count()==0
                assert not errors,errors;browser.close()
                print('PASS salary browser: one entry, advance instalments with cancelled/retried accounting review, next month confirmation and completed statement controls')
    finally:server.terminate();server.wait(timeout=5)
