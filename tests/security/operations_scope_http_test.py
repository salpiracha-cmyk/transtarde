"""Actual-auth shared reads, partial saves and hidden identity/field preservation."""
import json, os, pathlib, shutil, subprocess, tempfile
ROOT=pathlib.Path(__file__).resolve().parents[2]
MYSQL=os.environ.get('TT_QA_MYSQL')=='1'
if MYSQL:
 assert os.environ.get('TT_DB_HOST')=='127.0.0.1' and os.environ.get('TT_DB_NAME','').startswith('transtrade_qa_')
with tempfile.TemporaryDirectory(prefix='tti-operation-scope-') as folder:
 root=pathlib.Path(folder);app=root/'app';shutil.copytree(ROOT,app,ignore=shutil.ignore_patterns('.git','node_modules','__pycache__'))
 private=root/'transtrade_private';private.mkdir();auth=private/'auth.json';book=private/'operations.json';harness=root/'request.php'
 harness.write_text(r'''<?php
 $r=json_decode($argv[2],true);$_SERVER['REQUEST_URI']='/api/'.$r['endpoint'];$_SERVER['REQUEST_METHOD']=$r['method'];$_SERVER['REMOTE_ADDR']='192.0.2.49';
 session_name('TRANSTRADE_SESSION');session_id('scope-fixture');session_start();$_SESSION=['user_id'=>1,'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];session_write_close();
 $GLOBALS['body']=json_encode($r['body']);$GLOBALS['status']=$argv[3];
 class ScopeInput{public $context;private $offset=0;function stream_open($p,$m,$o,&$opened){return $p==='php://input';}function stream_read($n){$v=substr($GLOBALS['body'],$this->offset,$n);$this->offset+=strlen($v);return $v;}function stream_eof(){return $this->offset>=strlen($GLOBALS['body']);}function stream_stat(){return [];}}
 stream_wrapper_unregister('php');stream_wrapper_register('php',ScopeInput::class);register_shutdown_function(static function(){file_put_contents($GLOBALS['status'],(string)(http_response_code()?:200));});require $argv[1].'/api/'.$r['endpoint'];
 ''')
 sql=root/'db.php';sql.write_text(r'''<?php
 $p=new PDO('mysql:host='.getenv('TT_DB_HOST').';dbname='.getenv('TT_DB_NAME').';charset=utf8mb4',getenv('TT_DB_USER'),getenv('TT_DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $p->exec('CREATE TABLE IF NOT EXISTS tt_operation_records(storage_key VARCHAR(96) PRIMARY KEY,payload LONGTEXT NOT NULL,version BIGINT UNSIGNED NOT NULL DEFAULT 1,updated_at DATETIME(6) NOT NULL,updated_by VARCHAR(160) NOT NULL,updated_by_user BIGINT NULL,updated_by_module VARCHAR(32) NOT NULL) ENGINE=InnoDB');
 if($argv[1]==='seed'){$p->exec('DELETE FROM tt_operation_records');$q=$p->prepare("INSERT INTO tt_operation_records VALUES(?,?,1,NOW(6),'Fixture',1,'Mill')");foreach(json_decode($argv[2],true)as$k=>$v)$q->execute([$k,$v]);}
 else echo json_encode($p->query('SELECT storage_key,payload,version FROM tt_operation_records ORDER BY storage_key')->fetchAll(PDO::FETCH_ASSOC));
 ''')
 env=dict(os.environ)
 if not MYSQL:env={k:v for k,v in env.items() if not k.startswith('TT_DB_')}
 k='transtrade_export_v3_operational'
 export={'contracts':[{'id':'C1','ref':'TTI-C','seller':'TTI','product':'Rice','price':'PRIVATE_EXPORT_PRICE','packings':[{'brand':'BRAND','size':50,'price':'PRIVATE_PACKING_RATE','contractRate':999}]},{'id':'C2','ref':'FOREIGN_CONTRACT','seller':'BRM','price':'FOREIGN_PRICE'}],
 'shipments':[{'id':'L1','kind':'lot','contractRef':'TTI-C','lotId':'L01','loadingProgrammeNo':'LP-OLD','commercial':{'saved':True,'invoiceNo':'PRIVATE_COMMERCIAL'},'customs':{'saved':True,'gdNo':'PRIVATE_GD'},'millActuals':[]}],
 'millSync':{'productionInstructions':[{'contractRef':'TTI-C','packings':[{'brand':'BRAND','size':50,'price':'PRIVATE_SYNC_RATE'}],'production':{'dryOn':'Yes','craftPaper':'Yes','inspection':'SGS','dpp':'Yes'}}], 'newExportBags':[{'contractRef':'TTI-C','poNo':'PO-1','line':i,'deliverTo':'TTI Rice Mills','brand':'BRAND','totalOrdered':100} for i in [1,2]], 'exportLoading':[{'contractRef':'TTI-C','shipmentId':'L1','lotId':'L01','production':{'dryOn':'Yes','craftPaper':'Yes','inspection':'SGS','dpp':'Yes'},'plan':{'allocations':[{'type':'Mill','name':'Other Mill','packIndex':0,'containers':1},{'type':'Mill','name':'TTI Rice Mills','packIndex':0,'containers':1}]}}]},
 'accountsReceipts':[{'id':'R','amount':999,'bank':'PRIVATE_RECEIPT'}],'settings':{'private':'PRIVATE_SETTINGS'},'alerts':[],'audits':[]}
 queue=[{'id':'A','entity':'TTI','millName':'TTI Rice Mills','truck':'OWN_TRUCK'},{'id':'B','entity':'BRM','millName':'TTI Rice Mills','truck':'FOREIGN_TRUCK'},{'id':'C','entity':'TTI','millName':'Other Mill','truck':'OTHER_LOCATION_TRUCK'}]
 initial={k:json.dumps(export),'tt30queue':json.dumps(queue),'tt39salarymaster':json.dumps([{'id':'SAL','person':'PRIVATE_SALARY','amount':999}]),'tt99private':json.dumps({'secret':'PRIVATE_UNKNOWN'})}
 user={'id':1,'username':'fixture','full_name':'Scope Fixture','role':'Staff','location':'TTI Rice Mills','active':True,'session_version':1,'master_access':True,'master_permissions':{}}
 def grant(module,icon,rights=('View','Create','Edit')):
  user['permissions']={module:{icon:list(rights)},'Accounts':{'entity-tti':list(rights),'entity-brm':[],'entity-tg':[]}}
  auth.write_text(json.dumps({'users':[user],'masters':{},'audit':[],'settings':{'master_permission_schema':2,'qa_account_seeded':True}}))
 def seed():
  book.write_text(json.dumps({'revision':1,'values':initial,'meta':{key:{'version':1,'updatedBy':'Fixture'} for key in initial}}))
  if MYSQL:subprocess.run(['php',str(sql),'seed',json.dumps(initial)],env=env,check=True,capture_output=True)
 def request(endpoint='operations.mysql.php',body=None):
  req={'endpoint':endpoint,'method':'GET' if body is None else 'POST','body':body or {}}
  status=root/'status';r=subprocess.run(['php','-d',f'session.save_path={root}',str(harness),str(app),json.dumps(req),str(status)],env=env,text=True,capture_output=True)
  assert r.returncode==0,r.stdout+r.stderr
  try:return int(status.read_text()),json.loads(r.stdout)
  except Exception:raise AssertionError(r.stdout+r.stderr)
 def stored():
  if MYSQL:return {r['storage_key']:r['payload'] for r in json.loads(subprocess.run(['php',str(sql),'read'],env=env,check=True,capture_output=True,text=True).stdout)}
  return json.loads(book.read_text())['values']
 def write(key,value,version=1,module='Mill'):
  return request(body={'csrf':'fixture','sourceModule':module,'key':key,'value':json.dumps(value),'baseVersion':version})
 seed();grant('Mill','queue')
 for endpoint in ['operations.php','operations.mysql.php']:
  before=stored();status,data=request(endpoint);assert status==200,(endpoint,status,data)
  assert json.loads(data['values']['tt30queue'])==[queue[0]],data
  assert set(data['meta'])=={'tt30queue'} and k in data['restrictedKeys']
  assert not any(secret in json.dumps(data) for secret in ['PRIVATE_','FOREIGN_TRUCK','OTHER_LOCATION_TRUCK','FOREIGN_CONTRACT'])
  assert stored()==before
 status,data=write('tt30queue',[{**queue[0],'truck':'AMENDED'}]);assert status==200,(status,data)
 assert json.loads(data['value'])==[{**queue[0],'truck':'AMENDED'}]
 saved=json.loads(stored()['tt30queue']);assert saved[1:]==queue[1:] and saved[0]['truck']=='AMENDED'
 for bad in [[{**queue[1],'truck':'FORGED'}],[{**queue[0],'millName':'Other Mill'}],[{**queue[0],'entity':'BRM'}]]:
  before=stored();status,data=write('tt30queue',bad,2);assert status==403 and stored()==before,(bad,status,data)
 # Exact workflow View plus company/location restriction applies to root and its ACK.
 seed();grant('Exports','loading')
 status,data=request();assert status==200;visible=json.loads(data['values'][k])
 assert len(visible['contracts'])==1 and 'price' not in visible['contracts'][0]
 assert 'PRIVATE_PACKING_RATE' not in json.dumps(visible)
 assert 'commercial' not in visible['shipments'][0] and 'customs' not in visible['shipments'][0]
 for field in ['customers','suppliers','fi','accountsReceipts','audits','alerts']:visible.setdefault(field,[])
 visible.setdefault('settings',{})
 visible['shipments'][0]['loadingProgrammeNo']='LP-NEW'
 status,data=write(k,visible,module='Exports');assert status==200,(status,data)
 saved=json.loads(stored()[k]);assert saved['shipments'][0]['loadingProgrammeNo']=='LP-NEW'
 assert saved['contracts']==export['contracts'] and saved['shipments'][0]['commercial']==export['shipments'][0]['commercial'] and saved['accountsReceipts']==export['accountsReceipts']
 assert 'PRIVATE_' not in data['value'] and 'FOREIGN_CONTRACT' not in data['value']
 forged=json.loads(data['value']);forged['shipments'][0]['commercial']={'saved':True,'invoiceNo':'FORGED'};before=stored()
 status,data=write(k,forged,2,module='Exports');assert status==403 and stored()==before,(status,data)
 grant('Exports','commercial');status,data=request();assert 'PRIVATE_EXPORT_PRICE' in data['values'][k] and 'PRIVATE_COMMERCIAL' in data['values'][k] and 'PRIVATE_RECEIPT' not in data['values'][k]

 # Copied production instructions remain physical, including inherited loading options.
 seed();grant('Exports','production');status,data=request();assert status==200
 projected=json.loads(data['values'][k]);assert 'PRIVATE_' not in json.dumps(projected),projected
 assert projected['millSync']['productionInstructions'][0]['packings'][0]=={'brand':'BRAND','size':50}
 seed();grant('Mill','newbags');status,data=request();assert status==200
 projected=json.loads(data['values'][k]);assert len(projected['millSync']['newExportBags'])==2
 status,data=write(k,projected);assert status==200,('two distinct lines on one purchase order must save',status,data)
 assert json.loads(stored()[k])['millSync']['newExportBags']==export['millSync']['newExportBags']
 seed();grant('Mill','export');status,data=request();assert status==200
 projected=json.loads(data['values'][k]);loading=projected['millSync']['exportLoading'][0]
 assert len(loading['plan']['allocations'])==1 and loading['plan']['allocations'][0]['_ttAllocationIndex']==1,loading
 assert loading['production']=={'dryOn':'Yes','craftPaper':'Yes','inspection':'SGS','dpp':'Yes'},loading
 assert 'PRIVATE_' not in json.dumps(projected)

 # Co-loading preserves the other mill's contribution while permitting this mill to amend its own.
 import copy
 joint=copy.deepcopy(export);lot=joint['shipments'][0]
 lot['loadingPlan']={'allocations':[{'name':'Other Mill','type':'Mill','packIndex':0},{'name':'TTI Rice Mills','type':'Mill','packIndex':0}]}
 def part(identity,location,bags,kg):return {'id':identity,'location':location,'brand':'BRAND','packing':'50 KG','bags':bags,'netKg':kg,'millNetKg':kg,'tareKg':bags*.001,'grossKg':kg+bags*.001,'truck':identity,'gatePass':identity,'loadedDate':'2026-10-21'}
 other=part('OTHER_CONTRIBUTION','Other Mill',20,1000);own=part('OWN_CONTRIBUTION','TTI Rice Mills',10,500)
 lot['millActuals']=[{'number':'ABCD123456-7','seal':'SEAL','shipmentId':'L1','contractRef':'TTI-C','lotRef':'L01','source':'Milling','bags':30,'netKg':1500,'documentNetKg':1500,'millNetKg':1500,'tareKg':.03,'grossKg':1500.03,'location':'Other Mill + TTI Rice Mills','contributions':[other,own]}]
 original=initial[k];initial[k]=json.dumps(joint);seed();grant('Mill','export');status,data=request();assert status==200
 projected=json.loads(data['values'][k]);actual=projected['shipments'][0]['millActuals'][0]
 assert actual['contributions']==[own] and actual['bags']==10 and actual['location']=='TTI Rice Mills' and 'OTHER_CONTRIBUTION' not in json.dumps(projected)
 actual['contributions'][0]['bags']=12;actual['bags']=12
 actual['contributions'][0]['tareKg']=.012;actual['tareKg']=.012;actual['grossKg']=1500.012
 status,data=write(k,projected);assert status==200,('joint loading partial amendment',status,data)
 saved=json.loads(stored()[k])['shipments'][0]['millActuals'][0]
 assert saved['contributions'][0]==other and saved['contributions'][1]['bags']==12 and saved['bags']==32 and abs(saved['tareKg']-.032)<.00001,saved
 assert '_ttPartialActual' not in saved and 'OTHER_CONTRIBUTION' not in data['value']
 forged=json.loads(data['value']);forged['shipments'][0]['millActuals'][0]['contributions'].append({**other,'bags':999});before=stored()
 status,data=write(k,forged,2);assert status==403 and stored()==before,(status,data)
 # A second mill can add its first contribution without creating a second physical container.
 lot['millActuals'][0]['contributions']=[other];lot['millActuals'][0].update({'bags':20,'millNetKg':1000,'location':'Other Mill'})
 initial[k]=json.dumps(joint);seed();status,data=request();assert status==200
 projected=json.loads(data['values'][k]);actual=projected['shipments'][0]['millActuals'][0]
 assert actual['contributions']==[] and actual['bags']==0 and 'OTHER_CONTRIBUTION' not in json.dumps(projected)
 actual.update({'contributions':[own],'bags':10,'millNetKg':500,'tareKg':.01,'grossKg':1500.01,'netKg':1500,'documentNetKg':1500,'location':'TTI Rice Mills','brand':'BRAND','packing':'50 KG','truck':own['truck'],'gatePass':own['gatePass'],'loadedDate':own['loadedDate']})
 status,data=write(k,projected);assert status==200,('first contribution to shared container',status,data)
 saved=json.loads(stored()[k])['shipments'][0]['millActuals'];assert len(saved)==1 and saved[0]['contributions']==[other,own] and saved[0]['bags']==30,saved
 initial[k]=original;seed()
 # The bootstrap uses actual Master grants; selector Use hides salary and bank details.
 (app/'api'/'scope-masters-fixture.php').write_text("<?php $_GET['entity']='TTI';require_once __DIR__.'/../auth_store.php';require_once __DIR__.'/operations_scope.php';$u=tt_require_login();header('Content-Type: application/json');echo json_encode(os_bootstrap_masters($u));")
 grant('Mill','labour');credentials=json.loads(auth.read_text());cv=['COMPANY','TTI','Pakistan']+['']*11;cv[13]=json.dumps([{'id':'B','bankName':'BANK','accountTitle':'COMPANY','accountNumber':'PRIVATE_ACCOUNT','iban':'PRIVATE_IBAN','currency':'PKR','status':'Active'}]);credentials['masters']={'companies':[{'id':'C','values':cv}]};auth.write_text(json.dumps(credentials))
 status,data=request('scope-masters-fixture.php');assert status==200 and 'PRIVATE_' not in json.dumps(data) and 'banks' not in data,(status,data)
 credentials['users'][0]['master_permissions']={'companies':['Use'],'banks':['Use']};auth.write_text(json.dumps(credentials))
 status,data=request('scope-masters-fixture.php');assert status==200 and 'PRIVATE_' not in json.dumps(data) and data['banks'][0]['values'][4]=='BANK',(status,data)
 credentials['users'][0]['master_permissions']={'banks':['View']};auth.write_text(json.dumps(credentials))
 status,data=request('scope-masters-fixture.php');assert status==200 and 'PRIVATE_ACCOUNT' in json.dumps(data),(status,data)
 grant('Directors','home');status,data=request();assert k not in data['values'] and 'PRIVATE_' not in json.dumps(data)
 for role in ['Director','Super Admin']:
  user['role']=role;grant('Mill','queue');status,data=request();assert status==200 and 'PRIVATE_EXPORT_PRICE' in data['values'][k]
 print('PASS shared scope ('+('MySQL' if MYSQL else 'file')+'): exact workflow/company/location projection, filtered metadata and ACKs, hidden records/fields preserved, forged hidden writes denied, explicit commercial and owner reads retained')
