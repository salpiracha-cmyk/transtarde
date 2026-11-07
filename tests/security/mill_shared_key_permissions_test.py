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

 for key in ['tt30arrivaldefaults','tt30mills','tt38costmaster','tt38labourrates','tt39rentmaster','tt39salarymaster','tt38accountsfeed','tt39accountsoutbox','tt30directoralerts','tt32exportsync','tt35exmill']:
  seed();grant('Mill','production');before=stored();status,data=write(key,[]);assert status==403 and stored()==before,(key,status,data)
 # A read grant does not borrow another workflow's write grant, or upgrade Create into Edit.
 seed();grant('Mill','queue',('View','Create'));before=stored();status,data=write('tt30queue',[{**queue[0],'truck':'CHANGED'}]);assert status==403 and stored()==before,(status,data)
 new={**queue[0],'id':'NEW','truck':'DIFFERENT','date':'2026-10-20'};status,data=write('tt30queue',[queue[0],new]);assert status==200,(status,data)
 # Reviewed regression: New Export Bags Create cannot remove/amend an existing order.
 initial['tt30bags']=json.dumps([{'id':'BAG','brand':'OLD','mill':'TTI Rice Mills','entity':'TTI','ordered':0,'received':0}]);seed();grant('Mill','newbags',('View','Create'));before=stored()
 for bad in [[],[{'id':'BAG','brand':'CHANGED','mill':'TTI Rice Mills','entity':'TTI','ordered':0,'received':0}]]:
  status,data=write('tt30bags',bad);assert status==403 and stored()==before,(status,data)
 newbag={'id':'NEW-BAG','brand':'NEW','mill':'TTI Rice Mills','entity':'TTI','ordered':0,'received':0};status,data=write('tt30bags',json.loads(initial['tt30bags'])+[newbag]);assert status==200,(status,data)
 initial.pop('tt30bags');seed()
 # Canonical stored setup, not a payload action, determines the required Master action.
 initial['tt38labourrates']=json.dumps({'rates':[{'key':'R','rate':1}],'entity':'TTI'})
 seed();grant('Mill','labour');credentials=json.loads(auth.read_text());credentials['users'][0]['master_permissions']={'reference_lists':['Use','View','Create']};auth.write_text(json.dumps(credentials));before=stored()
 status,data=write('tt38labourrates',{'rates':[{'key':'R','rate':9}],'entity':'TTI'});assert status==403 and stored()==before,(status,data)
 credentials['users'][0]['master_permissions']['reference_lists'].append('Edit');auth.write_text(json.dumps(credentials))
 status,data=write('tt38labourrates',{'rates':[{'key':'R','rate':9}],'entity':'TTI'});assert status==200,(status,data)
 # A viewer can acknowledge only its own currently visible Export instruction.
 import datetime
 initial['tt30prodinst']=json.dumps([{'id':'I','_ttBridge':'exports','_ttBridgeId':'TTI-C|0','contractRef':'TTI-C','millName':'TTI Rice Mills'}]);seed();grant('Mill','instructions',('View',))
 stamp=datetime.datetime.now(datetime.timezone.utc).isoformat()
 status,data=write('tt40instructionseen',{'scope fixture':{'P|TTI-C|0':stamp}},0);assert status==200,(status,data)
 before=stored();status,data=write('tt40instructionseen',{'someone else':{'P|TTI-C|0':stamp}},1);assert status==403 and stored()==before,(status,data)
 before=stored();status,data=write('tt40instructionseen',{'scope fixture':{'P|FORGED':stamp}},1);assert status==403 and stored()==before,(status,data)

 # Duplicate decisions and one-time consumption use the same actual locked writer for both owners.
 import copy
 user['role']='Staff';initial['tt30queue']=json.dumps([{**queue[0],'date':'2026-10-21','vehicle':'OWN_TRUCK'}]);initial.pop('tt40instructionseen',None);seed();grant('Mill','queue')
 alert={'id':'AR-1','entity':'TTI','millName':'TTI Rice Mills','date':'2026-10-21','truck':'OWN_TRUCK','status':'Pending Director Approval','reason':'Duplicate second local trip'}
 before=stored();status,data=write('tt30directoralerts',[{**alert,'status':'Approved Once'}],0);assert status==403 and stored()==before,(status,data)
 status,data=write('tt30directoralerts',[alert],0);assert status==200,(status,data)
 pending=json.loads(data['value']);assert pending[0]['requestedByUser']==1 and pending[0]['status']=='Pending Director Approval'
 decided=copy.deepcopy(pending);decided[0].update(status='Approved Once',decisionReason='Checked actual second trip')
 before=stored();status,data=write('tt30directoralerts',decided,1);assert status==403 and stored()==before,(status,data)
 second={**json.loads(initial['tt30queue'])[0],'id':'SECOND'}
 before=stored();status,data=write('tt30queue',[json.loads(initial['tt30queue'])[0],second]);assert status==403 and stored()==before,(status,data)
 user['role']='Director';grant('Mill','queue');status,data=write('tt30directoralerts',decided,1);assert status==200,(status,data)
 approved=json.loads(data['value']);assert approved[0]['approvedBy']=='Scope Fixture' and approved[0]['approvedByUser']==1
 user['role']='Super Admin';grant('Mill','queue');before=stored();status,data=write('tt30directoralerts',decided,1);assert status==409 and stored()==before,('stale second approver',status,data)
 user['role']='Staff';grant('Mill','queue');status,data=write('tt30queue',[json.loads(initial['tt30queue'])[0],second]);assert status==200,(status,data)
 used=json.loads(stored()['tt30directoralerts']);assert used[0]['status']=='Approval Used' and used[0]['usedQueueId']=='SECOND' and used[0]['approvedByUser']==1,used
 before=stored();status,data=write('tt30queue',[json.loads(initial['tt30queue'])[0],second,{**second,'id':'THIRD'}],2);assert status==403 and stored()==before,('one-time reuse',status,data)
 # Super Admin can make a fresh decision through the identical source/version rules.
 user['role']='Super Admin';grant('Mill','queue');new={**alert,'id':'AR-2'};status,data=write('tt30directoralerts',used+[new],3);assert status==200,(status,data)
 reject=json.loads(data['value']);reject[-1].update(status='Rejected',decisionReason='Duplicate request is not required');status,data=write('tt30directoralerts',reject,4);assert status==200 and json.loads(data['value'])[-1]['approvedByUser']==1,(status,data)
 print('PASS duplicate lifecycle: Staff cannot approve; actual Director/SA decisions, stale-owner denial and atomic one-time consumption; explicit shared-key authority: unrelated writes leave canonical data unchanged; stored setup needs exact Master action; read-only instruction acknowledgements stay principal/source-bound')
