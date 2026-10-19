"""Exercise durable salary drafts and one atomic final journal against the actual endpoint."""
import json, os, pathlib, re, shutil, socket, subprocess, tempfile, time, urllib.request, urllib.error
ROOT = pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='salary-month-qa-') as temp:
    root=pathlib.Path(temp)
    for folder in ['api','accounts','data']:(root/folder).mkdir()
    for name in ['rent_salary.php','rent_salary_v2.php','salary_month_workflow.php','salary_master_store.php','accounts_bank_payment.php', 'accounts_posting_preview.php']:
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
        # A saved July payment must not silently change when a B/F is added.
        assert draft('Bob',0,month='2026-07')[0]==200
        before=saved()
        opening={'action':'record_salary_opening_advance','masterId':'Bob','amount':79000,'months':79,'date':'2026-07-01','requestKey':'opening-bob-2026'}
        status,preview=request({**opening,'preview':True},'2026-07');assert status==200,preview
        assert saved()==before,'Green review is read-only'
        assert preview['previews'][0]['lines'][0]['account']=='1230'
        assert preview['previews'][0]['lines'][1]['account']=='3400'
        status,data=request(opening,'2026-07');assert status==200,data
        aid=data['result']['advanceId'];assert data['result']['monthlyDeduction']==1000
        assert len(saved()['journals'])==1
        assert {l['account'] for l in next(iter(saved()['journals'].values()))['lines']}=={'1230','3400'}
        assert request(opening,'2026-07')[1]['result']['duplicate']
        assert request({**opening,'amount':78000},'2026-07')[0]==422
        assert request({**opening,'requestKey':'second-bob-opening'},'2026-07')[0]==422
        assert request({**opening,'masterId':'Cara','requestKey':'invalid-source','paymentAccountId':'bank-a'},'2026-07')[0]==422
        assert request({**opening,'masterId':'Cara','requestKey':'invalid-months','months':27.5},'2026-07')[0]==422
        assert request({**opening,'masterId':'Cara','requestKey':'invalid-date','date':'2026-07-02'},'2026-07')[0]==422
        july=request(None,'2026-07')[1]['salarySheet'];assert july['masterChanged'] and july['rows']['Bob']['payment']['amount']==0
        assert request({'action':'complete_salary_month','version':july['version'],'date':'2026-07-31'},'2026-07')[0]==422
        assert request({'action':'refresh_salary_draft','version':july['version']},'2026-07')[0]==200
        cara={**opening,'masterId':'Cara','amount':27000,'months':27,'requestKey':'opening-cara-2026'}
        assert request(cara,'2026-07')[0]==200
        assert request(None,'2026-06')[1]['salarySheet']['rows']['Bob']['advanceApplied']==0
        month='2026-07'
        for n in range(80):
            sheet=request(None,month)[1]['salarySheet']
            if sheet.get('masterChanged'):
                status,data=request({'action':'refresh_salary_draft','version':sheet['version']},month);assert status==200,data;sheet=data['salarySheet']
            assert sheet['rows']['Bob']['advanceApplied']==(1000 if n<79 else 0),(n,sheet)
            assert sheet['rows']['Cara']['advanceApplied']==(1000 if n<27 else 0),(n,sheet)
            for mid in sheet['rows']:
                status,data=draft(mid,0,month=month);assert status==200,data
            version=data['salarySheet']['version']
            status,data=request({'action':'complete_salary_month','version':version,'date':month+'-28'},month);assert status==200,data
            assert saved()['salaryAdvances'][aid]['remaining']==max(0,79000-1000*(n+1))
            assert all(l['account'] not in ['1110','1120'] for l in saved()['journals'][data['result']['journalId']]['lines'])
            assert request(None,'2026-07')[1]['salarySheet']['status']=='Completed'
            year,mon=map(int,month.split('-'));mon+=1
            if mon==13:year+=1;mon=1
            month=f'{year:04d}-{mon:02d}'
        assert saved()['salaryAdvances'][aid]['remaining']==0
        assert request(cara,'2026-07')[1]['result']['duplicate'],'Retry after full recovery cannot recreate debt'
        assert len([j for j in saved()['journals'].values() if j['sourceType']=='SALARY_ADVANCE_OPENING'])==2
        print('PASS opening advances: 79/27 months, no cash payment, preview/replay safety, stale drafts, July cutoff and automatic stop at zero')
    finally:server.terminate();server.wait(timeout=5)
