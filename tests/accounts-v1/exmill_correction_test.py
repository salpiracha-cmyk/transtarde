"""Real authenticated correction/report routes; all financial records are disposable."""
import json, os, pathlib, shutil, subprocess, tempfile, urllib.parse
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tti-lifting-correction-') as directory:
 root=pathlib.Path(directory);app=root/'app';shutil.copytree(ROOT,app,ignore=shutil.ignore_patterns('.git','node_modules','__pycache__'))
 private=root/'transtrade_private';private.mkdir();books=private/'accounts.json';auth=private/'auth.json'
 owner={'id':1,'username':'fixture','full_name':'Fixture','role':'Super Admin','active':True,'session_version':1}
 auth.write_text(json.dumps({'users':[owner],'masters':{},'settings':{'qa_account_seeded':True},'audit':[]}))
 soda={'id':'S1','entity':'TTI','sodaNo':'26001','commodity':'RICE','productStage':'READY','readyRoute':'EX_MILL','locationName':'Outside fixture mill','rate':100,'ratePerKg':100,'qtyToKg':1000,'party':'Fixture supplier','paymentTermType':'CASH'}
 books.write_text(json.dumps({'revision':0,'purchaseSodas':{'S1':soda},'purchaseSodaLiftings':{},'journals':{},'events':{}}))
 env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
 runner=root/'request.php';runner.write_text(r'''<?php
 $_SERVER['REQUEST_URI']='/api/'.$argv[4];$_SERVER['REQUEST_METHOD']=$argv[5];$_SERVER['REMOTE_ADDR']='192.0.2.46';parse_str($argv[6],$_GET);
 session_name('TRANSTRADE_SESSION');session_id('lifting-correction-'.bin2hex(random_bytes(12)));session_start();$_SESSION=['user_id'=>1,'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];session_write_close();
 $GLOBALS['fixtureBody']=$argv[3];$GLOBALS['statusFile']=$argv[2];class LiftingInput{public $context;private $offset=0;function stream_open($p,$m,$o,&$opened){return $p==='php://input';}function stream_read($n){$v=substr($GLOBALS['fixtureBody'],$this->offset,$n);$this->offset+=strlen($v);return $v;}function stream_eof(){return $this->offset>=strlen($GLOBALS['fixtureBody']);}function stream_stat(){return [];}}
 stream_wrapper_unregister('php');stream_wrapper_register('php',LiftingInput::class);register_shutdown_function(static function(){file_put_contents($GLOBALS['statusFile'],(string)(http_response_code()?:200));});require $argv[1];
 ''')
 def request(api,body=None,query=None):
  sf=root/'status.txt';out=subprocess.run(['php','-d',f'session.save_path={root}',str(runner),str(app/'api'/api),str(sf),json.dumps({'csrf':'fixture',**body} if body is not None else {}),api,'POST' if body is not None else 'GET',urllib.parse.urlencode(query or {})],env=env,text=True,capture_output=True)
  assert out.returncode==0,out.stderr+out.stdout
  return int(sf.read_text()),json.loads(out.stdout)
 def post(body):return request('milling_purchase_sodas.php',body)
 def assert_books(inventory,grni,supplier=0):
  before=books.read_bytes();status,reports=request('accounts_reports.php',query={'entity':'TTI','from':'2026-10-01','asOf':'2026-10-18'});assert status==200,(status,reports)
  net={str(r['account']):r['debit']-r['credit'] for r in reports['trialBalance']['rows']}
  assert net.get('1310',0)==inventory and net.get('2210',0)==grni and net.get('2110',0)==supplier,('wrong trial balance',net)
  assert reports['trialBalance']['balanced']
  status,ledger=request('accounts_ledger_browser.php',query={'entity':'TTI','from':'2026-10-01','to':'2026-10-18','account':'1310'});assert status==200 and ledger['closing']==inventory,(status,ledger)
  assert books.read_bytes()==before,'Reports changed original Accounts bytes'
 lifting={'action':'sync_lifting','sourceSodaId':'S1','liftingId':'CN-MSCU1234567','entity':'TTI','date':'2026-10-18','container':'MSCU1234567','truck':'KHI-123','seal':'SEAL1','kg':100,'bags':2,'qualityStatus':'OK','shipmentId':'SHIP1','contractRef':'C1','lotRef':'L1','instructionKey':'SHIP1|C1|L1|0'}
 before=books.read_bytes();status,preview=post({**lifting,'preview':True});assert status==200 and len(preview.get('previews',[]))==1 and books.read_bytes()==before,('initial preview mutated storage',status,preview)
 reviewed=preview['reviewFingerprint'];stored=json.loads(books.read_text());stored['purchaseSodas']['S1']['ratePerKg']=101;books.write_text(json.dumps(stored));before=books.read_bytes()
 status,data=post({**lifting,'reviewFingerprint':reviewed});assert status==409 and books.read_bytes()==before,('stale reviewed amount was posted',status,data)
 stored['purchaseSodas']['S1']['ratePerKg']=100;books.write_text(json.dumps(stored))
 status,first=post({**lifting,'reviewFingerprint':reviewed});assert status==200,(status,first)
 assert first['journal']['status']=='Posted','Bill pending is a receipt dimension, not an unposted GL status'
 # Original legacy status must be understood without rewriting any historical journal.
 stored=json.loads(books.read_text());jid=first['journal']['id'];stored['journals'][jid]['status']='Posted / Bill Pending';books.write_text(json.dumps(stored));original=stored['journals'][jid]
 assert_books(10000,-10000)
 # A changed current Soda does not reprice the accepted receipt during a physical correction.
 stored=json.loads(books.read_text());stored['purchaseSodas']['S1']['ratePerKg']=999;books.write_text(json.dumps(stored))
 corrected={**lifting,'kg':90,'expectedJournalId':jid,'correctionReason':'Correct original weighbridge reading'}
 before=books.read_bytes();status,preview=post({**corrected,'preview':True});assert status==200 and len(preview['previews'])==2 and books.read_bytes()==before,(status,preview)
 workers=[{**owner,'role':'Staff','location':'Outside fixture mill','permissions':{'Mill':{'export':['View','Create']}}}, {**owner,'role':'Staff','location':'Outside fixture mill','permissions':{'Mill':{'export':['View','Edit']},'Accounts':{'purchases':['View','Create'],'entity-tti':['View','Create']}}}, {**owner,'role':'Staff','location':'Another mill','permissions':{'Mill':{'export':['View','Edit']},'Accounts':{'purchases':['View','Create'],'entity-tti':['View','Create','Edit']}}}]
 for worker in workers:
  auth.write_text(json.dumps({'users':[worker],'masters':{},'audit':[]}));status,data=post(corrected);assert status==403 and books.read_bytes()==before,(worker,status,data)
 worker={**owner,'role':'Staff','location':'Outside fixture mill','permissions':{'Mill':{'export':['View','Edit']}}};auth.write_text(json.dumps({'users':[worker],'masters':{},'audit':[]}));status,data=post({**corrected,'preview':True});assert status==200 and len(data['previews'])==2 and books.read_bytes()==before,(status,data)
 auth.write_text(json.dumps({'users':[owner],'masters':{},'audit':[]}));status,changed=post({**corrected,'reviewFingerprint':preview['reviewFingerprint']});assert status==200,(status,changed)
 current=changed['journal'];assert current['id']!=jid and current['totalDebit']==9000 and current['meta']['publicPostId']==jid,current
 stored=json.loads(books.read_text());assert stored['journals'][jid]==original,'Original journal was changed';assert len(stored['journals'])==3
 reversal=next(j for j in stored['journals'].values() if j.get('reversalOf')==jid);assert reversal['totalDebit']==10000 and reversal['lines'][0]['credit']==10000
 event=next(iter(stored['events'].values()));assert event['journalId']==current['id'] and stored['purchaseSodaLiftings'][changed['lifting']['key']]['journalId']==current['id']
 assert_books(9000,-9000)
 before=books.read_bytes();status,replay=post(corrected);assert status==200 and replay['replayed'] and books.read_bytes()==before,(status,replay)
 status,data=post({**corrected,'kg':85});assert status==409 and books.read_bytes()==before,('stale correction overwrote current source',status,data)
 status,detail=request('accounts_ledger_browser.php',query={'entity':'TTI','account':'POSTS','postId':jid,'from':'2026-10-01','to':'2026-10-18'});assert status==200 and detail['post']['id']==current['id'] and detail['post']['canEdit'] is False,(status,detail)
 status,data=request('accounts_post_amend.php',{'postId':current['id'],'date':'2026-10-18','reason':'Must use the actual native source','lines':current['lines']});assert status==422 and books.read_bytes()==before,(status,data)
 # A supplier bill consumes the current replacement, not the reversed original amount.
 stored=json.loads(books.read_text());stored['purchaseSodas']['S1']['ratePerKg']=100;books.write_text(json.dumps(stored))
 bill={'action':'verify_bill','entity':'TTI','sourceKeys':[changed['lifting']['sourceKey']],'billDate':'2026-10-18','billNo':'CORRECTION-FIXTURE','relationshipType':'SUPPLIER','relationshipName':'Fixture supplier','finalCommodityValue':9000,'brokerageGross':0,'brokerageWithholding':0,'readyRiceCalculation':{'bags':2,'emptyBagWeightGrams':0,'kantaRate':0}}
 status,billed=request('commodity_bills.php',bill);assert status==200,(status,billed)
 assert_books(9000,0,-9000)
 before=books.read_bytes();status,data=post({**corrected,'kg':90,'truck':'KHI-999','expectedJournalId':current['id']});assert status==409 and books.read_bytes()==before,('billed metadata correction was not held',status,data)
 print('PASS actual Ex-Mill preview/immutable correction, Edit scope, accepted rate, original/reversal/replacement, stale/retry protection, legacy pending GL visibility, current Post ID lookup, source-owned amendment guard and final supplier/GRNI reconciliation')
