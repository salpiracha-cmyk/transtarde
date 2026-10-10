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
    for name in ['supplier_opening_core.php','bank_reconciliation.php','bank_reconciliation_core.php','bank_entries.php','bank_entries_core.php','accounts_subaccounts.php','accounts_ledger_browser.php','customer_receivables_core.php','accounts_post_delete_core.php','accounts_reports.php','accounts_reference.php','tg_remittance_core.php','fi_credit_advice_link.php','receipt_invoice_links.php','journal_vouchers.php','opening_balance_core.php','accounts_subaccounts_core.php','assets_registry_core.php','expenses_v1.php','direct_expense_core.php','accounts_bank_payment.php','expense_locations.php','expense_reminders.php','expense_reversals.php','donations.php','rent_salary_v2.php','salary_month_workflow.php','salary_master_store.php']:
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
    permissions['reconciliation']=['View','Create','Edit'];set_user()
    books.write_text(json.dumps({'revision':0,'journals':{},'bankAccountSettings':{'BANK-1':{'defaultPaymentAccount':True}}}))

    def journal(id, bank, debit=0, credit=0, **extra):
        return {'id':id,'status':'Posted','entity':'TTI','date':'2026-10-07','reference':id,'lines':[{'account':'1110','bankAccountId':bank,'debit':debit,'credit':credit,**extra}]}
    seed={'revision':0,'sentinel':{'unchanged':True},'journals':{
        'OPEN':journal('OPEN','BANK-1',1000),'DEP':journal('DEP','BANK-1',300),
        'CHQ':journal('CHQ','BANK-1',0,200),'OTHER':journal('OTHER','BANK-2',999),
        'FX':journal('FX','BANK-USD',28000,bankDebit=100,bankCredit=0)}}
    books.write_text(json.dumps(seed))
    query='?entity=TTI&bankId=BANK-1&statementDate=2026-10-07'
    status,data=request('bank_reconciliation',query);assert status==200,(status,data)
    assert data['bookBalance']==1100 and len(data['transactions'])==3 and data['statementBalance'] is None
    base={'entity':'TTI','csrf':'fixture','revision':0,'requestKey':'reconcile-fixture-01','bankId':'BANK-1','statementDate':'2026-10-07','statementBalance':1000,'clearedKeys':['OPEN|0']}
    for change,code in [({'csrf':'bad'},419),({'statementDate':'2026-02-31'},422),({'statementBalance':''},422),({'bankId':'BANK-BRM'},422),({'clearedKeys':['OTHER|0']},422),({'revision':99},409)]:
        before=books.read_bytes();status,out=request('bank_reconciliation',query,{**base,**change});assert status==code and books.read_bytes()==before,(change,status,out)
    status,rec=request('bank_reconciliation',query,base);assert status==200,(status,rec)
    assert rec['saved']['status']=='Reconciled' and rec['difference']==0 and rec['outstandingDeposits']==300 and rec['outstandingPayments']==200 and rec['adjustedStatementBalance']==1100
    saved=json.loads(books.read_text());assert saved['journals']==seed['journals'] and saved['sentinel']==seed['sentinel']
    before=books.read_bytes();assert request('bank_reconciliation',query,base)[0]==200 and books.read_bytes()==before
    assert request('bank_reconciliation',query,{**base,'statementBalance':5})[0]==409 and books.read_bytes()==before
    next_date='?entity=TTI&bankId=BANK-1&statementDate=2026-10-08'
    _,later=request('bank_reconciliation',next_date);assert later['statementBalance'] is None and next(x for x in later['transactions'] if x['key']=='OPEN|0')['cleared']
    status,later=request('bank_reconciliation',next_date,{**base,'revision':1,'requestKey':'reconcile-fixture-02','statementDate':'2026-10-08','statementBalance':1005})
    assert status==200 and later['difference']==5 and later['saved']['status']=='Difference to Review'
    _,prior=request('bank_reconciliation',query);assert prior['statementBalance']==1000 and len(prior['history'])==2
    _,fx=request('bank_reconciliation','?entity=TTI&bankId=BANK-USD&statementDate=2026-10-07');assert fx['bookBalance']==100
    status,fx=request('bank_reconciliation',query,{**base,'revision':2,'requestKey':'reconcile-fx-01','bankId':'BANK-USD','statementBalance':100,'clearedKeys':['FX|0']});assert status==200 and fx['difference']==0
    bad=json.loads(books.read_text());bad['journals']['FX']['lines'][0].pop('bankDebit');bad['journals']['FX']['lines'][0].pop('bankCredit');books.write_text(json.dumps(bad))
    _,fx=request('bank_reconciliation','?entity=TTI&bankId=BANK-USD&statementDate=2026-10-07');assert fx['balanceUnavailable'] and fx['bookBalance'] is None
    assert request('bank_reconciliation',query,{**base,'revision':3,'requestKey':'reconcile-fx-bad','bankId':'BANK-USD','statementBalance':100,'clearedKeys':['FX|0']})[0]==422
    for balance in [0,-100]:
        revision=json.loads(books.read_text())['revision']
        status,out=request('bank_reconciliation',query,{**base,'revision':revision,'requestKey':f'reconcile-signed-{revision}','bankId':'BANK-2','statementBalance':balance,'clearedKeys':[]});assert status==200 and out['saved']['statementBalance']==balance
    before=books.read_bytes();permissions['cashbank']=['View'];permissions['reconciliation']=['View'];set_user()
    assert request('bank_reconciliation',query)[1]['canEdit'] is False
    assert request('bank_reconciliation',query,{**base,'requestKey':'reconcile-denied'})[0]==403 and books.read_bytes()==before
    assert request('bank_reconciliation','?entity=BRM')[0]==403
    permissions['cashbank']=['View','Edit'];permissions['reconciliation']=['View','Edit'];permissions['entity-tti']=['View'];set_user()
    assert request('bank_reconciliation',query,{**base,'requestKey':'reconcile-entity-denied'})[0]==403
    permissions['entity-tti']=['View','Edit'];set_user()
    books.write_text('{broken JSON');before=books.read_bytes();assert request('bank_reconciliation',query)[0]==500 and books.read_bytes()==before
    print('Bank reconciliation: outstanding items, dated history, native currencies, signed balances, retries, permissions and storage protection passed.')
