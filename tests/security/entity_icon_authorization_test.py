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
    for name in ['supplier_opening_core.php','accounts_subaccounts_core.php','accounts_receipts_core.php','accounts_ledger_visibility.php','assets_registry_core.php','bank_accounts.php', 'tg_remittance_core.php', 'accounts_reference.php','jv_decisions_core.php','journal_vouchers.php', 'opening_balance_core.php','tg_native_currency.php', 'tg_bank_transfer.php','accounts_posting_preview.php', 'accounts_bank_payment.php']:
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
    permissions = {'entity-tti':['View','Create','Edit','Approve'], 'jv':['View'], 'supplier':['View','Approve']}
    user = {'id':1,'username':'fixture','full_name':'Fixture','role':'Staff','active':True,'session_version':1,'permissions':{'Accounts':permissions}}
    def set_user():
        auth.write_text(json.dumps({'users':[user],'masters':{},'audit':[]}))
    def request(endpoint, query='', body=None):
        uri = '/api/' + endpoint + '.php' + query
        req = {'uri':uri,'method':'GET' if body is None else 'POST','body':body}
        result = subprocess.run(['php',str(harness),str(app/'api'/f'{endpoint}.php'),json.dumps(req),str(root/'sessions')],capture_output=True,text=True,check=True)
        payload, status = result.stdout.rsplit('\nHTTP_STATUS=',1)
        try: return int(status), json.loads(payload)
        except json.JSONDecodeError: raise AssertionError(result.stdout + result.stderr)
    set_user()
    initial = {'revision':0,'journals':{},'bankAccountSettings':{},'jvDrafts':{'JVD-1':{'id':'JVD-1','entity':'TTI','status':'Pending Approval','createdByUserId':2,'createdBy':'Other Preparer','supportingReference':'','date':'2026-10-01','narration':'Fixture','reference':'FIXTURE','lines':[{'account':'6900','subledger':'Fixture','debit':100,'credit':0},{'account':'2140','subledger':'Fixture','debit':0,'credit':100}]}}}
    books.write_text(json.dumps(initial))
    assert request('bank_accounts','?entity=TTI')[0] == 200
    before = books.read_bytes()
    assert request('bank_accounts','?entity=TTI',{'entity':'BRM','action':'save_settings','accountId':'CASH|BRM','csrf':'fixture'})[0] == 403
    assert request('tg_bank_transfer','?entity=TTI')[0] == 403
    assert request('bank_accounts','',{'entity':'BRM','action':'save_settings','accountId':'CASH|BRM','csrf':'fixture'})[0] == 403
    assert books.read_bytes() == before, 'Rejected cross-company requests must not change books'
    approval = {'entity':'TTI','action':'approve_jv','id':'JVD-1','csrf':'fixture'}
    assert request('journal_vouchers','?entity=TTI',approval)[0] == 403
    assert books.read_bytes() == before, 'Entity/unrelated icon approval must not post a JV'
    permissions['jv'].append('Approve')
    permissions['entity-tti'] = ['View']
    set_user()
    assert request('journal_vouchers','?entity=TTI',approval)[0] == 403
    assert books.read_bytes() == before, 'JV icon grant without company approval must not post'
    permissions['entity-tti'].append('Approve')
    set_user()
    status, data = request('journal_vouchers','?entity=TTI',approval)
    assert status == 200, data
    posted = json.loads(books.read_text())
    assert posted['jvDrafts']['JVD-1']['status'] == 'Posted'
    assert len(posted['journals']) == 1
    # Legacy module-wide actions can coexist with explicit company restrictions.
    user['permissions']['Accounts'] = {'0':'View','1':'Create','entity-tti':['View','Create']}
    set_user()
    status, data = request('journal_vouchers','?entity=TTI')
    assert status == 200 and data['canWrite'], data
    assert request('journal_vouchers','?entity=BRM')[0] == 403
    # Real auth permits a read-only recipient to acknowledge only their own notice.
    saved=json.loads(books.read_text());saved['jvNoticeSequence']=7;saved['jvNotices']=[{'id':7,'recipientIds':[1],'entity':'TTI','jvId':'JVD-1','status':'Posted','postId':'POST-TEST'}];books.write_text(json.dumps(saved))
    user['permissions']['Accounts']={'jv':['View'],'entity-tti':['View']};set_user();original_journals=saved['journals']
    status,data=request('journal_vouchers','?entity=TTI',{'entity':'TTI','action':'dismiss_jv_notice','noticeId':7,'csrf':'fixture'});assert status==200 and data['result']['dismissed'],data
    assert json.loads(books.read_text())['journals']==original_journals
    assert request('journal_vouchers','?entity=TTI&notices=1&pending=1')[1]['notices']==[]
    before=books.read_bytes();assert request('journal_vouchers','?entity=TTI',{'entity':'BRM','action':'dismiss_jv_notice','noticeId':7,'csrf':'fixture'})[0]==403;assert books.read_bytes()==before
    # Director role alone supplies all business actions/company books; no saved boxes.
    user['role']='Director';user['permissions']={};user['location']='Karachi Office';set_user()
    for entity in ['TTI','BRM','TG']:
        status,data=request('journal_vouchers','?entity='+entity);assert status==200 and data['canPostDirectly'] and data['canWrite'],data
    before=books.read_bytes();assert request('journal_vouchers','?entity=UNKNOWN')[0]==403;assert books.read_bytes()==before
    body={'entity':'BRM','action':'submit_jv','requestKey':'director-no-boxes','date':'2026-10-01','narration':'Director role fixture','csrf':'fixture','lines':[{'account':'6900','subledger':'Fixture expense','debit':25,'credit':0},{'account':'2140','subledger':'Fixture payable','debit':0,'credit':25}]}
    original=json.loads(books.read_text())['journals'].copy();status,data=request('journal_vouchers','?entity=BRM',body);assert status==200 and data['result']['status']=='Posted',data
    posted=json.loads(books.read_text())['journals'];assert len(posted)==len(original)+1 and all(posted[k]==v for k,v in original.items())
    assert request('journal_vouchers','?entity=BRM',body)[1]['replayed'];assert len(json.loads(books.read_text())['journals'])==len(posted)
    body['requestKey']='director-unbalanced';body['lines'][0]['debit']=24;before=books.read_bytes();assert request('journal_vouchers','?entity=BRM',body)[0]==422;assert books.read_bytes()==before,'Director must still balance journals'

    # A role downgrade immediately restores granular staff restrictions.
    user['role']='Accounts';user['permissions']={'Accounts':{'jv':['View'],'entity-tti':['View']}};set_user()
    assert not request('journal_vouchers','?entity=TTI')[1]['canPostDirectly']
    assert request('journal_vouchers','?entity=BRM')[0]==403
    user['role'] = 'Super Admin'
    user['permissions'] = {}
    set_user()
    assert request('bank_accounts','?entity=BRM')[0] == 200, 'Super Admin remains unrestricted'
    print('PASS real endpoint company binding, fixed TG scope, exact JV/company approval, data integrity and owner access')


