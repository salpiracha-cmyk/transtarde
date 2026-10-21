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
    for source in (ROOT/'api').glob('*.php'):
        shutil.copy(source,app/'api'/source.name)
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
    permissions = {'entity-tti':['View','Create','Edit','Approve'], 'expenses':['View','Create','Edit']}
    user = {'id':1,'username':'fixture','full_name':'Fixture','role':'Super Admin','active':True,'session_version':1,'permissions':{'Accounts':permissions}}
    def set_user():
        auth.write_text(json.dumps({'users':[user],'masters':{'banks':[{'id':'BANK-1','values':['Company Account','TG','','Company','Fixture Bank','','','AED','123456789','PK01TEST','','','','Active']}]},'audit':[]}))
    def request(endpoint, query='', body=None):
        uri = '/api/' + endpoint + '.php' + query
        req = {'uri':uri,'method':'GET' if body is None else 'POST','body':body}
        result = subprocess.run(['php',str(harness),str(app/'api'/f'{endpoint}.php'),json.dumps(req),str(root/'sessions')],capture_output=True,text=True)
        assert result.returncode==0,result.stdout+result.stderr
        payload, status = result.stdout.rsplit('\nHTTP_STATUS=',1)
        try: return int(status), json.loads(payload)
        except json.JSONDecodeError: raise AssertionError(result.stdout + result.stderr)

    set_user();books.write_text(json.dumps({'revision':0,'journals':{},'bankAccountSettings':{}}))
    body={'action':'pay_expense','csrf':'fixture','entity':'TG','requestKey':'tg-npss-original-0001','paymentDate':'2026-07-13','payee':'Fixture vendor','paymentAccountId':'BANK-1','bankPaymentMethod':'ONLINE_BANKING','bankPaymentDate':'2026-07-13','reference':'NPSS-1','amount':16030,'expenseLines':[{'category':'PROFESSIONAL','purpose':'Principal visa cost','amount':16000},{'category':'PROFESSIONAL','purpose':'NPSS','amount':30}]}
    status,result=request('expenses_v1','?entity=TG',body);assert status==200,(status,result)
    original=result['result'];saved=json.loads(books.read_text());old_lines=saved['journals'][original['journalId']]['lines']
    saved['journals']['USD-OPEN']={'id':'USD-OPEN','entity':'TG','date':'2026-07-01','sourceType':'CARRY_FORWARD_OPENING','status':'Posted','narration':'Opening','totalDebit':367,'totalCredit':367,'lines':[{'account':'1210','currency':'USD','nativeDebit':100,'nativeCredit':0,'debit':367,'credit':0},{'account':'3400','debit':0,'credit':367}]}
    saved['journals']['FX']={'id':'FX','entity':'TG','date':'2026-07-22','sourceType':'TG_USD_AED_TRANSFER','status':'Posted','narration':'Actual conversion','lines':[{'account':'1110','bankAccountId':'USD','currency':'USD','bankDebit':0,'bankCredit':10,'debit':0,'credit':36.7},{'account':'1110','bankAccountId':'AED','currency':'AED','bankDebit':36.7,'bankCredit':0,'debit':36.7,'credit':0}]}
    saved['journals']['MRS-TAYYAB']={'id':'MRS-TAYYAB','entity':'TTI','date':'2026-10-03','sourceType':'SALARY_ALLOCATED_PAYMENT','status':'Posted','lines':[],'meta':{'person':'Mrs Tayyab'}}
    books.write_text(json.dumps(saved));before=books.read_bytes()
    status,review=request('tg_ledger_repair');assert status==200,(status,review)
    assert books.read_bytes()==before,'Correction review must not write'
    plan=review['plan'];assert len(plan['npss'])==1 and plan['npss'][0]['charge']==30 and plan['npss'][0]['vat']==1.5
    opening=next(p for p in plan['native'] if p['journalId']=='USD-OPEN');assert opening['changes'][0]['fields']=={'currency':'USD','nativeDebit':0,'nativeCredit':100}
    bad={'csrf':'fixture','action':'apply','fingerprint':'stale'};status,denied=request('tg_ledger_repair',body=bad);assert status==422 and books.read_bytes()==before
    status,result=request('tg_ledger_repair',body={**bad,'fingerprint':plan['fingerprint']});assert status==200,(status,result)
    audit=result['result'];assert len(audit['npss'])==1 and audit['npss'][0]['bankDebit']==16031.5
    after=json.loads(books.read_text());assert after['journals']['MRS-TAYYAB']==saved['journals']['MRS-TAYYAB']
    assert after['journals'][original['journalId']]['lines']==old_lines,'Original financial lines remain in immutable correction history'
    corrected=after['journals'][audit['npss'][0]['replacement']['journalId']]
    assert corrected['meta']['publicPostId']==original['journalId']
    assert next(l for l in corrected['lines'] if l['account']=='6800')['debit']==30
    assert next(l for l in corrected['lines'] if l['account']=='1140')['debit']==1.5
    assert sum(l.get('bankCredit',0) for l in corrected['lines'] if l['account']=='1110')==16031.5
    assert corrected['totalDebit']==corrected['totalCredit']==16031.5
    assert after['journals']['USD-OPEN']['lines'][1]['nativeCredit']==100 and after['journals']['USD-OPEN']['lines'][1]['credit']==367
    assert [l['currency'] for l in after['journals']['FX']['lines']]==['USD','AED'],'Preserve actual conversion currencies'
    status,again=request('tg_ledger_repair');assert status==200 and len(again['plan']['npss'])==0
    print('PASS real TG repair endpoints: preview read-only, stale rejection, NPSS principal preserved, fee/VAT separate, stable Post ID, opening native contra, genuine conversion and Mrs Tayyab unchanged')
