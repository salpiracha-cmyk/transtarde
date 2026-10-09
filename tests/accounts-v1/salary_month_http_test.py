"""Exercise durable salary drafts and one atomic final journal against the actual endpoint."""
import json, os, pathlib, re, shutil, socket, subprocess, tempfile, time, urllib.request, urllib.error
ROOT = pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='salary-month-qa-') as temp:
    root=pathlib.Path(temp)
    for folder in ['api','accounts','data']:(root/folder).mkdir()
    for name in ['rent_salary.php','rent_salary_v2.php','salary_month_workflow.php','salary_master_store.php','accounts_bank_payment.php']:
        shutil.copy(ROOT/'api'/name,root/'api'/name)
    for name in ['accounting_master_v1.json','rent-salary-ui.js','bank-payment-details.js']:
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
    (root/'accounts/index.html').write_text('<!doctype html><style>'+popup_css+'</style>'+'''<section class="workspace active tt-clean-modal tt-entry-only"><button data-expense="salary">Salaries</button><div id="expenseEditor"></div></section><script>window.TT_ACCOUNT_ACCESS={csrf:'fixture'};localStorage.setItem('tt_accounts_entity','TTI');</script><script src="bank-payment-details.js"></script><script src="rent-salary-ui.js"></script>''')
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
        assert any(l.get('party')=='Bob' and l['account']=='2140' for l in journal['lines'])
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
        assert all(l['account'] in ['2140','1120'] for l in saved()['journals'][data['result']['journalId']]['lines']),'Legacy accrual cannot duplicate expenses'
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
        assert prepared['masterChanged'] and not prepared['canRefreshFromMaster']
        assert prepared['rows']['Alice']['totalDue']==1350,'Prepared accounting retains its posted amount'
        assert request({'action':'refresh_salary_draft','version':prepared['version']},'2027-04')[0]==422
        assert saved()['journals']==prepared_journals
        print('Salary drafts, restart persistence, amount caps, payable and advance balances, mixed payments, cheque validation, overdraft and atomic final retry passed')
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
        if os.getenv('TT_QA_BROWSER')=='1':
            # Browser setup is independent of the master-edit scenarios above.
            state=saved();state['salaryMasters']=json.loads(json.dumps(initial['salaryMasters']))
            (root/'data/accounts.json').write_text(json.dumps(state))
            from playwright.sync_api import sync_playwright
            with sync_playwright() as pw:
                browser=pw.chromium.launch(headless=True);page=browser.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
                page.goto(f'http://127.0.0.1:{port}/accounts/index.html');page.locator('[data-expense="salary"]').click()
                page.locator('#rsAdvanceIcon').wait_for();assert page.locator('#rsPrepare').inner_text()=='▦ PREPARE SALARY'
                page.locator('#rsAdvanceIcon').click();assert page.locator('#rsAdvanceAccount').input_value()=='bank-b','Payment default wins over receipt default'
                assert page.locator('#rsAdvanceAccountDetails [data-method]').input_value()=='CHEQUE'
                assert page.locator('#rsAdvanceAccountDetails [data-method] option').count()==2
                page.locator('#rsSalaryBack').click();page.locator('#rsPrepare').click();page.locator('#rsMonth').fill('2027-01');page.locator('#rsMonth').dispatch_event('change')
                page.locator('[data-rs-draft-pay="Bob"]').click();assert page.locator('#rsPaySalAmt').input_value()=='1050'
                page.locator('[name="rsPaymentMode"][value="BANK"]').check();assert page.locator('#rsPaySalBank').input_value()=='bank-b'
                page.locator('#rsPaySalBankDetails [data-method]').select_option('ONLINE_BANKING');page.locator('#rsPaySalAmt').fill('500');page.locator('#rsSplitCash').fill('250');page.locator('#rsSplitBank').fill('250');page.locator('#rsPaySalBtn').click()
                page.get_by_role('button',name='Amend',exact=True).wait_for();page.locator('[data-rs-draft-pay="Bob"]').click();assert page.locator('#rsPaySalAmt').input_value()=='500'
                assert page.locator('[name=rsPaymentMode][value=CASH]').is_checked()
                assert page.locator('[name=rsPaymentMode][value=BANK]').is_checked()
                assert page.locator('#rsSplitCash').input_value()=='250'
                assert page.locator('#rsSplitBank').input_value()=='250'
                assert not errors,errors;browser.close()
                print('Actual salary browser: two icons, advance unchanged, payment default, two methods, saved draft and Amend passed')
    finally:server.terminate();server.wait(timeout=5)
