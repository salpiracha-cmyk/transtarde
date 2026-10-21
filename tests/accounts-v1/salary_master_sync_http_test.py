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
        store=saved();store['salaryMasters']['Bob']['category']='MILL_STAFF';store['salaryMasters']['Bob']['productionCostEligible']=True;(root/'data/accounts.json').write_text(json.dumps(store))
        assert request({'action':'prepare_month'},month='2026-09')[0]==200
        assert request({'action':'prepare_month'})[0]==200
        assert draft('Cara',100)[0]==200
        before=saved();store=saved();store['salaryMasters']['Bob']['monthlyAmount']=1200;store['salaryMasters']['Bob']['name']='Bobby';store['salaryMasters']['David']=staff('David')
        (root/'data/accounts.json').write_text(json.dumps(store))
        sheet=request()[1]['salarySheet'];assert sheet['masterChanged']
        body={'action':'apply_salary_master','version':sheet['version'],'reason':'October Master confirmed by owner'}
        status,preview=request({**body,'preview':True});assert status==200,preview
        assert saved()==store,'Master preview is read-only'
        status,data=request(body);assert status==200,data
        result=saved();sheet=data['salarySheet'];bob=sheet['rows']['Bob']
        assert bob['name']=='Bobby' and bob['totalDue']==1250 and not sheet.get('masterChanged'),sheet
        assert 'David' in sheet['rows'] and not sheet['rows']['David']['prepared']
        assert sheet['rows']['Cara']['payment']['amount']==100 and sheet['rows']['Cara']['reviewed'],'Unchanged saved plan retained'
        original=before['salaryPeriods'][bob['periodId']]['journalId'];assert result['journals'][original]==before['journals'][original],'Original accrual immutable'
        adjustment=result['journals'][data['result']['journalId']]
        assert sum(l['debit']-l['credit'] for l in adjustment['lines'] if l['account']=='6210')==200
        assert sum(l['credit']-l['debit'] for l in adjustment['lines'] if l['account']=='2230')==200
        # Existing inactive-person payments can be retained explicitly for this
        # month only without silently dropping money or reactivating the Master.
        inactive=saved();inactive['salaryMasters']['Cara']['status']='Inactive';inactive['salaryMasters']['Cara']['effectiveTo']='2026-09-30';(root/'data/accounts.json').write_text(json.dumps(inactive))
        current=request()[1]['salarySheet'];status,held=request({'action':'apply_salary_master','version':current['version'],'reason':'Keep already recorded one-off payment','keepInactivePlans':['Cara']});assert status==200,held
        assert held['salarySheet']['rows']['Cara']['oneOffInactive'] and held['salarySheet']['rows']['Cara']['payment']['amount']==100
        assert saved()['salaryMasters']['Cara']['status']=='Inactive'
        restored=saved();restored['salaryMasters']['Cara']['status']='Active';restored['salaryMasters']['Cara']['effectiveTo']='';(root/'data/accounts.json').write_text(json.dumps(restored))
        current=request()[1]['salarySheet'];status,held=request({'action':'apply_salary_master','version':current['version'],'reason':'Restore fixture Master status'});assert status==200,held
        sheet=held['salarySheet']
        # A paid/planned person cannot be removed without source correction.
        removal={'action':'reverse_salary_staff','masterId':'Cara','version':sheet['version'],'reason':'Left before October'}
        prior=saved();status,error=request(removal);assert status==422,error
        assert 'Payments / Amend' in error['error'] and saved()==prior
        assert request({'action':'prepare_month'},month='2026-11')[0]==200
        prior=saved()
        removal={'action':'reverse_salary_staff','masterId':'Bob','version':sheet['version'],'reason':'Left before October'}
        status,preview=request({**removal,'preview':True});assert status==200,preview
        assert saved()==prior
        status,data=request(removal);assert status==200,data
        result=saved();assert result['salaryMasters']['Bob']['status']=='Inactive'
        assert 'Bob' not in data['salarySheet']['rows']
        period=result['salaryPeriods'][bob['periodId']];assert period['status']=='Cancelled' and period['outstanding']==0 and period['gross']==1250,'Retain cancelled entitlement history'
        assert result['journals'][original]==before['journals'][original]
        assert 'Bob' not in request(month='2026-11')[1]['salarySheet']['rows']
        assert result['salaryPeriods']['TTI|2026-11|Bob']['status']=='Cancelled'
        assert data['salarySheet']['rows']['Cara']['payment']['amount']==100
        # A replacement person entered by renaming Master gets a new identity;
        # the departed person's prior advance/period links cannot move to them.
        store=saved();store['salaryMasters']['Alice']['name']='New Alice';store['salaryMasters']['Alice']['monthlyAmount']=1300;(root/'data/accounts.json').write_text(json.dumps(store))
        current=request()[1]['salarySheet'];status,replaced=request({'action':'reverse_salary_staff','masterId':'Alice','version':current['version'],'reason':'Original Alice left before October','preserveReplacement':True});assert status==200,replaced
        new_id=replaced['result']['replacementMasterId'];assert new_id and new_id!='Alice'
        state=saved();assert state['salaryMasters']['Alice']['name']=='Alice' and state['salaryMasters'][new_id]['name']=='New Alice'
        assert state['salaryPeriods']['TTI|2026-09|Alice']['advanceApplied']==300
        current=request()[1]['salarySheet'];status,updated=request({'action':'apply_salary_master','version':current['version'],'reason':'Apply confirmed replacement person'});assert status==200,updated
        assert updated['salarySheet']['rows'][new_id]['totalDue']==1350 and not updated['salarySheet']['rows'][new_id]['prepared']
        with urllib.request.urlopen(f'http://127.0.0.1:{port}/api/production_fixed_overhead.php?entity=TTI') as response:costs=json.load(response)
        months=costs['months'] if isinstance(costs['months'],dict) else {r['month']:r for r in costs['months']};assert months['2026-09']['millSalary']==1030 and months['2026-10']['millSalary']==0,months
        print('PASS explicit Master sync: mixed prepared drafts, exact expense/payable delta, preserved plans/history, preview safety, guarded leaving and future exclusion')
    finally:server.terminate();server.wait(timeout=5)
