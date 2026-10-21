"""Exercise durable salary drafts and one atomic final journal against the actual endpoint."""
import json, os, pathlib, re, shutil, socket, subprocess, tempfile, time, urllib.request, urllib.error
ROOT = pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='salary-month-qa-') as temp:
    root=pathlib.Path(temp)
    for folder in ['api','accounts','data']:(root/folder).mkdir()
    for name in ['rent_salary.php','rent_salary_v2.php','salary_month_workflow.php','salary_flexible_workflow.php','salary_record_corrections.php','salary_extra_allocations.php','accounts_subaccounts_core.php','accounts_reference.php','accounts_ledger_visibility.php','assets_registry_core.php','company_investments_core.php','salary_master_store.php','accounts_bank_payment.php', 'accounts_posting_preview.php']:
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
        assert wait()[0]==200
        assert request({'action':'prepare_month'})[0]==200
        assert request()[1]['salarySheet']['rows']['Alice']['advanceApplied']==300,'Already applied advance must not repeat'
        # Save another person's plan: Bob's already accrued October must survive.
        assert draft('Cara',100)[0]==200
        before=saved()
        opening={'action':'record_salary_opening_advance','masterId':'Bob','amount':79000,'months':79,'date':'2026-07-01','requestKey':'prepared-opening-bob'}
        assert request({**opening,'preview':True})[0]==200
        assert saved()==before
        status,data=request(opening);assert status==200,data
        row=data['salarySheet']['rows']['Bob'];aid=data['result']['advanceId']
        assert row['advanceApplied']==1000 and row['outstanding']==50,row
        assert row['originalAdvanceApplied']==0 and row['recoveryMaximum']==1050,row
        assert saved()['salaryPeriods'][row['periodId']]['advanceApplied']==0,'No deduction posted by opening/GET'
        assert data['salarySheet']['rows']['Cara']['payment']['amount']==100
        # A legacy unfinished sheet predating the fix is projected correctly on
        # read without migrating financial records or clearing another plan.
        legacy=saved();legacy_row=legacy['salarySheets']['TTI|2026-10']['rows']['Bob']
        legacy_row.pop('requestedRecovery',None);legacy_row.pop('recoveryMaximum',None)
        legacy_row['advanceApplied']=0;legacy_row['outstanding']=1050
        (root/'data/accounts.json').write_text(json.dumps(legacy))
        assert request()[1]['salarySheet']['rows']['Bob']['advanceApplied']==1000
        assert saved()==legacy,'Opening legacy draft must remain read-only'
        # Saving another person's transaction must not reset Bob's recovery to zero.
        assert draft('Alice',0)[0]==200
        row=request()[1]['salarySheet']['rows']['Bob'];assert row['advanceApplied']==1000,row
        sheet=request()[1]['salarySheet']
        payload={'action':'save_salary_transactions','masterId':'Bob','version':sheet['version'],'advanceApplied':1000,'transactions':[{'amount':50,'date':'2026-10-03','paymentAccountId':'CASH|TTI','recipient':'Bob','allocations':[{'type':'SALARY','masterId':'Bob','amount':50}]}]}
        assert request(payload)[0]==200
        sheet=request()[1]['salarySheet'];before=saved()
        complete={'action':'complete_salary_month','version':sheet['version'],'date':'2026-10-03'}
        status,preview=request({**complete,'preview':True});assert status==200,preview
        assert saved()==before,'Completion preview does not recover or pay'
        status,data=request(complete);assert status==200,data
        store=saved();period=store['salaryPeriods'][row['periodId']]
        assert period['advanceApplied']==1000 and period['outstanding']==0,period
        assert store['salaryAdvances'][aid]['remaining']==78000
        journals=[j for key,j in store['journals'].items() if key not in before['journals']]
        recovery=[line for j in journals for line in j['lines'] if line.get('salaryMasterId')=='Bob' and line['account']=='1230']
        assert sum(x['credit']-x['debit'] for x in recovery)==1000,recovery
        assert not any(line['account'] in ['6210','6220','2140'] for j in journals for line in j['lines']),'No second salary expense or supplier liability'
        assert request(complete)[1]['result']['duplicate']
        assert saved()==store,'Retry cannot deduct or pay twice'
        assert request()[1]['salarySheet']['rows']['Bob']['advanceApplied']==1000
        print('PASS prepared salary: automatic advance, preserved plans, no duplicate accrual/recovery, read-only preview, retry safety')
    finally:server.terminate();server.wait(timeout=5)
