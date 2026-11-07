"""Real auth/controllers, disposable source records, no production writes."""
import copy,json,os,pathlib,shutil,subprocess,tempfile
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tti-bank-date-links-') as directory:
    root=pathlib.Path(directory);app=root/'app';shutil.copytree(ROOT,app,ignore=shutil.ignore_patterns('.git','node_modules','tmp','__pycache__'))
    private=root/'transtrade_private';private.mkdir();books=private/'accounts.json';ops=private/'operations.json';auth=private/'auth.json'
    harness=root/'request.php';harness.write_text(r'''<?php
$r=json_decode($argv[2],true);$_SERVER['REQUEST_URI']=$r['uri'];$_SERVER['REQUEST_METHOD']=$r['method']??'POST';$_SERVER['REMOTE_ADDR']='192.0.2.42';parse_str(parse_url($r['uri'],PHP_URL_QUERY)??'',$_GET);$_POST=[];
session_name('TRANSTRADE_SESSION');session_id('linked-date-fixture');session_start();$_SESSION=['user_id'=>1,'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];session_write_close();
$GLOBALS['fixtureBody']=json_encode($r['body']);$GLOBALS['statusFile']=$argv[3];
class LinkedDateInput {public $context;private $position=0;function stream_open($a,$b,$c,&$d){return true;}function stream_read($n){$s=substr($GLOBALS['fixtureBody'],$this->position,$n);$this->position+=strlen($s);return $s;}function stream_eof(){return $this->position>=strlen($GLOBALS['fixtureBody']);}function stream_stat(){return [];}}
stream_wrapper_unregister('php');stream_wrapper_register('php',LinkedDateInput::class);register_shutdown_function(static function(){file_put_contents($GLOBALS['statusFile'],(string)(http_response_code()?:200));});require $argv[1];
''')
    user={'id':1,'username':'fixture','full_name':'Fixture','role':'Super Admin','active':True,'session_version':1,'permissions':{}}
    masters={'banks':[{'id':'PKR','values':['Company Account','TTI','','Fixture PKR','Bank','','','PKR','123','','','','','Active']},{'id':'USD','values':['Company Account','TG','','Fixture USD','Bank','','','USD','456','','','','','Active']} ]}
    auth.write_text(json.dumps({'users':[user],'masters':masters,'audit':[]}))
    books.write_text(json.dumps({'revision':1,'journals':{},'exportReceipts':{}}))
    ops.write_text(json.dumps({'values':{'transtrade_export_v3_operational':json.dumps({'contracts':[],'shipments':[]})}}))
    env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
    def request(endpoint,body,method='POST'):
        status_file=root/'status';r=subprocess.run(['php','-d',f'session.save_path={root}',str(harness),str(app/'api'/endpoint),json.dumps({'uri':'/api/'+endpoint+('?entity=TTI'+('&'+body.pop('_query') if '_query' in body else '') if method=='GET' else ''),'method':method,'body':{'csrf':'fixture',**body}}),str(status_file)],env=env,text=True,capture_output=True)
        assert r.returncode==0,r.stderr+r.stdout
        try:return int(status_file.read_text()),json.loads(r.stdout)
        except Exception:raise AssertionError(r.stdout+r.stderr)
    state={'revision':1,'journals':{},'exporterDeals':{'HK':{'id':'HK','entity':'TTI','partyId':'HKM','party':'Haji Khushi Mohammed','reference':'HKM-3500','product':'White Rice','direction':'SALE','date':'2026-10-01','version':1,'payments':[]}}}
    books.write_text(json.dumps(state))
    ops.write_text(json.dumps({'values':{'tt35localsales':json.dumps([{'soda':'LS-1','party':'Local Buyer','entity':'TTI','date':'2026-10-01','millName':'TTI Rice Mills','product':'Rice'}])}}))
    for kind,party,soda in [('LOCAL_SALE','Local Buyer','LS-1'),('FELLOW_EXPORTER','HKM','HK')]:
        current=json.loads(books.read_text())
        payload={'entity':'TTI','action':'post','type':'RECEIPT','bankId':'PKR','date':'2026-10-14','amount':1200,'receiptKind':kind,'receiptPartyId':party,'receiptSodaId':soda,'reference':'DATE-'+kind,'revision':current['revision'],'requestKey':'receipt-'+kind}
        status,posted=request('bank_entries.php',payload);assert status==200,posted
        old=posted['result']['journal'];original=json.loads(books.read_text());before=books.read_bytes()
        correction={'entity':'TTI','postId':old['id'],'datesOnly':True,'date':'2026-10-13','reason':'Correct receipt date in source','lines':old['lines']}
        status,preview=request('accounts_post_amend.php',{**correction,'preview':True});assert status==200,preview
        assert books.read_bytes()==before,'Preview must leave bank and SODA unchanged'
        status,amended=request('accounts_post_amend.php',correction);assert status==200,amended
        current=json.loads(books.read_text());new=current['journals'][amended['result']['replacementPostId']]
        assert new['date']=='2026-10-13' and new['meta']['publicPostId']==old['id']
        assert current['journals'][old['id']]['lines']==old['lines'],'Original receipt lines are immutable'
        status,opened=request('bank_entries.php',{'_query':'editPostId='+new['id']},'GET');assert status==200,opened
        assert opened['editEntry']['input']['date']=='2026-10-13'
        if kind=='FELLOW_EXPORTER':
            payments=current['exporterDeals']['HK']['payments']
            assert sum(p['amountPKR'] for p in payments)==1200
            assert sum(p['amountPKR'] for p in payments if p['date']<='2026-10-13')==1200
            assert payments[-1]['postId']==new['id'] and payments[-1]['date']=='2026-10-13'
            assert current['exporterDeals']['HK']['version']>original['exporterDeals']['HK']['version']
        frozen=books.read_bytes();assert request('accounts_post_amend.php',correction)[0]==422 and books.read_bytes()==frozen,'Stale correction cannot post twice'
        frozen=books.read_bytes()
        assert request('accounts_post_amend.php',{**correction,'postId':new['id'],'csrf':'wrong'})[0]==419 and books.read_bytes()==frozen
        if kind=='FELLOW_EXPORTER':
            later={**payload,'date':'2026-10-15','reference':'LATER-ACTIVE-RECEIPT','requestKey':'later-active-receipt','revision':current['revision']}
            status,posted=request('bank_entries.php',later);assert status==200,posted
            frozen=books.read_bytes()
            status,rejected=request('accounts_post_amend.php',{**correction,'postId':new['id'],'date':'2026-10-12'})
            assert status==422 and 'later SODA' in rejected['error'] and books.read_bytes()==frozen,'Active later SODA entries must still protect chronology'
    print('PASS native local/fellow receipt date correction: read-only preview, SODA payment dates, advances, audit, native reopen and stale retry')
