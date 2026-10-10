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
    for name in ['inventory_reconciliation.php','auth_store.php', 'master_store.php', 'product_stage.php', 'offline_idempotency.php','session_store.php']:
        shutil.copy(ROOT / name, app / name)
    for name in ['supplier_opening_core.php','stock_reconciliation_review.php','bank_entries.php','bank_entries_core.php','accounts_subaccounts.php','accounts_ledger_browser.php','customer_receivables_core.php','accounts_post_delete_core.php','accounts_reports.php','accounts_reference.php','tg_remittance_core.php','fi_credit_advice_link.php','receipt_invoice_links.php','journal_vouchers.php','opening_balance_core.php','accounts_subaccounts_core.php','assets_registry_core.php','expenses_v1.php','direct_expense_core.php','accounts_bank_payment.php','expense_locations.php','expense_reminders.php','expense_reversals.php','donations.php','rent_salary_v2.php','salary_month_workflow.php','salary_master_store.php']:
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
    permissions = {'entity-tti':['View','Create','Edit','Approve','Delete'], 'expenses':['View','Create','Edit'],'cashbank':['View','Create','Edit','Delete'],'masters':['View','Create','Edit','Delete'],'reports':['View'],'jv':['View','Create','Edit','Approve']}
    user = {'id':1,'username':'fixture','full_name':'Fixture','role':'Staff','active':True,'session_version':1,'permissions':{'Accounts':permissions}}
    def set_user():
        auth.write_text(json.dumps({'users':[user],'masters':{'banks':[{'id':'BANK-1','depositType':'SAVING','values':['Company Account','TTI','','Company','Fixture Bank','','','PKR','123456789','PK01TEST','','','','Active']},{'id':'BANK-2','values':['Company Account','TTI','','Company 2','Fixture Bank 2','','','PKR','234567890','PK02TEST','','','','Active']},{'id':'BANK-USD','values':['Company Account','TTI','','USD','FX Bank','','','USD','345678901','PK03TEST','','','','Active']},{'id':'BANK-BRM','values':['Company Account','BRM','','BRM','Other books','','','PKR','456789012','PK04TEST','','','','Active']}]},'audit':[]}))
        fixture=json.loads(auth.read_text());companies=[]
        for company_code in ['TTI','BRM']:
            banks=[]
            for bank in fixture['masters']['banks']:
                v=bank['values']
                if v[1]!=company_code:continue
                banks.append({'id':bank['id'],'accountType':'Company Account','depositType':bank.get('depositType','CURRENT'),'accountTitle':v[3],'bankName':v[4],'currency':v[7],'accountNumber':v[8],'iban':v[9],'status':'Active'})
            values=['']*21;values[0]=company_code;values[1]=company_code;values[2]='Pakistan';values[12]='Active';values[13]=json.dumps(banks);values[14]=json.dumps(['PKR','USD'])
            companies.append({'id':'company-'+company_code,'values':values})
        fixture['masters']['companies']=companies;auth.write_text(json.dumps(fixture))
    def request(endpoint, query='', body=None):
        uri = '/api/' + endpoint + '.php' + query
        req = {'uri':uri,'method':'GET' if body is None else 'POST','body':body}
        result = subprocess.run(['php',str(harness),str(app/'api'/f'{endpoint}.php'),json.dumps(req),str(root/'sessions')],capture_output=True,text=True)
        assert result.returncode==0,result.stdout+result.stderr
        payload, status = result.stdout.rsplit('\nHTTP_STATUS=',1)
        try: return int(status), json.loads(payload)
        except json.JSONDecodeError: raise AssertionError(result.stdout + result.stderr)
    for f in ROOT.joinpath('accounts').glob('*.json'): shutil.copy(f,app/'accounts'/f.name)
    set_user()
    books.write_text(json.dumps({'revision':0,'journals':{},'bankAccountSettings':{'BANK-1':{'defaultPaymentAccount':True}}}))

    import hashlib, os
    # This fixture deliberately exercises the file backend; MySQL remains covered separately.
    for key in ['TT_DB_HOST','TT_DB_NAME','TT_DB_USER','TT_DB_PASS']: os.environ.pop(key,None)
    operations=private/'operations.json'
    scope={'entity':'TTI','millId':2,'millName':'Other Mill'}
    name='IRRI-6 White Rice — ASAS'
    rows={
      'tt30prod':[{**scope,'id':10,'date':'2026-10-07','shift':'Day','baseVariety':'IRRI-6','riceType':'White','inputStage':'RAW','shiftEntriesComplete':True,'rows':[{'product':'Ready Rice — ASAS','bags':1,'bagWeight':50}]}],
      'tt39physicalconfirmations':[{**scope,'id':'pc1','stockName':name,'date':'2026-10-07','shiftDate':'2026-10-07','shift':'Day','physicalKg':0,'snapshotKg':-100,'baseline':{},'isRaw':False,'status':'Resolved','resolvedQuantities':{'10':0},'reviewRequired':'Production amended','adjustmentKg':100}],
      'tt32stockadj':[{**scope,'id':'RECON-pc1','ref':'STOCK-CONFIRMATION|pc1','date':'2026-10-07','stockName':name,'readyRiceKg':100,'rawRiceKg':0,'type':'Physical stock confirmation','managedReconciliation':True}],
      'tt34ghati':[{**scope,'id':'GHATI-pc1','ref':'STOCK-CONFIRMATION|pc1','date':'2026-10-07','kind':'gain','kg':100,'time':'2026-10-07T08:00:00Z','managementOnly':True}],
      'tt34nilqueue':[{**scope,'id':'ROW-pc1','ref':'STOCK-CONFIRMATION|pc1','date':'2026-10-07','product':name,'kg':100,'systemFixed':True,'noStockPost':True,'managementOnly':True,'status':'Applied to Production','productionId':99,'existingProductionIds':[10]}],
      'unrelated-storage':[{'retain':True}]}
    def compact(x): return json.dumps(x,ensure_ascii=False,separators=(',',':'))
    values={k:compact(v) for k,v in rows.items()}
    operations.write_text(compact({'revision':0,'values':values,'meta':{}}))
    keys=['tt30prod','tt30ship','tt30slips','tt32stockadj','tt35localsales','tt35exportersale','tt39physicalconfirmations','tt34ghati','tt34nilqueue','tt32processingrecon','tt34stockreviewaudit']
    revision=hashlib.sha256(compact({k:values.get(k,'') for k in sorted(keys)}).encode()).hexdigest()
    payload={'csrf':'fixture','entity':'TTI','revision':revision,'confirmationId':'pc1','reason':'Corrected completed shift production'}
    # View-only financial reports and operational permissions cannot accept a correction.
    permissions['reports']=['View'];set_user();before=operations.read_bytes()
    assert request('stock_reconciliation_review','',payload)[0]==403 and operations.read_bytes()==before
    permissions['reports']=['View','Edit'];set_user()
    assert request('stock_reconciliation_review','',{**payload,'csrf':'bad'})[0]==419
    assert request('stock_reconciliation_review','',{**payload,'revision':'old'})[0]==409
    assert request('stock_reconciliation_review','',{**payload,'reason':'x'})[0]==422
    assert request('stock_reconciliation_review','',{**payload,'entity':'BRM'})[0]==403
    assert operations.read_bytes()==before
    books_before=books.read_bytes()
    status,out=request('stock_reconciliation_review','',payload);assert status==200,(status,out)
    result=json.loads(operations.read_text())['values']
    assert result['tt30prod']==values['tt30prod'] and result['unrelated-storage']==values['unrelated-storage'] and books.read_bytes()==books_before
    assert json.loads(result['tt34ghati'])[0]['kg']==50 and len(json.loads(result['tt34ghati']))==1
    assert json.loads(result['tt32stockadj'])[0]['readyRiceKg']==50
    fixed=json.loads(result['tt34nilqueue']);assert len(fixed)==1 and fixed[0]['kg']==50 and fixed[0]['productionId']==99 and fixed[0]['status']=='Applied to Production'
    assert not json.loads(result['tt39physicalconfirmations'])[0].get('reviewRequired')
    audit=json.loads(result['tt34stockreviewaudit']);assert len(audit)==1 and audit[0]['by']=='fixture'
    before=operations.read_bytes();assert request('stock_reconciliation_review','',payload)[0]==409 and operations.read_bytes()==before
    operations.write_text('{broken JSON');before=operations.read_bytes();assert request('stock_reconciliation_review','',payload)[0]==500 and operations.read_bytes()==before
    print('Stock review HTTP: explicit correction, permissions, company scope, stale protection, no duplicate stock and immutable production/journals passed.')
