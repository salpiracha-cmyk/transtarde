"""Real authenticated master endpoints: shortened names, legacy history and safe restoration."""
import json, os, pathlib, shutil, subprocess, tempfile
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='party-history-') as temp:
 root=pathlib.Path(temp);app=root/'app';shutil.copytree(ROOT,app,ignore=shutil.ignore_patterns('.git','node_modules','__pycache__'))
 private=root/'transtrade_private';private.mkdir();auth=private/'auth.json';books=private/'accounts.json'
 user={'id':1,'username':'fixture','full_name':'Fixture','role':'Super Admin','active':True,'session_version':1}
 def seed(rows):
  auth.write_text(json.dumps({'users':[user],'masters':{'business_parties':rows},'settings':{'qa_account_seeded':True,'master_permission_schema':2},'audit':[]}))
 def party(name,id='P1'):return {'id':id,'values':[name,'','Freight Forwarder','','','','','','','','Active','','']}
 original={'id':'OLD','entity':'TTI','status':'Posted','sourceType':'OPENING_BALANCE_BF','date':'2026-07-01','lines':[{'account':'2130','credit':123,'debit':0,'subledger':'Ocean Test Shipping Services'},{'account':'3400','debit':123,'credit':0}]}
 books.write_text(json.dumps({'journals':{'OLD':original},'supplierBills':{'B1':{'entity':'TTI','vendor':'Ocean Test Shipping Services'}}}))
 before=books.read_bytes()
 runner=root/'runner.php';runner.write_text('''<?php
 $_GET=['entity'=>'TTI','scope'=>'opening'];$_SERVER['REQUEST_METHOD']='POST';$_SERVER['REQUEST_URI']='/api/'.($argv[2]??'masters.php');$_SERVER['REMOTE_ADDR']='192.0.2.47';
 session_name('TRANSTRADE_SESSION');session_id('party-history-'.bin2hex(random_bytes(12)));session_start();$_SESSION=['user_id'=>1,'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];session_write_close();
 $GLOBALS['TT_AUTHORIZED_API_BODY']=json_decode(stream_get_contents(STDIN),true);
 register_shutdown_function(static function(){echo "\\n__STATUS__".(http_response_code()?:200);});
 require $argv[1].'/api/'.($argv[2]??'masters.php');
 ''')
 env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
 def request(action,name='',id=''):
  body={'csrf':'fixture','type':'business_parties','action':action,'id':id,'values':party(name)['values']}
  proc=subprocess.run(['php','-d',f'session.save_path={root}',str(runner),str(app)],input=json.dumps(body),text=True,capture_output=True,env=env)
  assert proc.returncode==0,proc.stderr
  payload,status=proc.stdout.rsplit('\n__STATUS__',1)
  return int(status),json.loads(payload)
 seed([party('Ocean Test Shipping Services')])
 status,result=request('create','Ocean Test');assert status==422 and not result['ok'],(status,result)
 seed([])
 status,result=request('create','Ocean Test');assert status==422 and 'historical' in result['error'],(status,result)
 status,result=request('create','Ocean Test Shipping Services');assert status==200 and result['ok'],(status,result)
 restored=result['id'];assert books.read_bytes()==before,'Restoring a master must never rewrite financial history'
 status,result=request('purge',id=restored);assert status==422 and 'history' in result['error'],(status,result)
 status,result=request('create','Separate Ocean Company');assert status==200 and result['ok'],(status,result)
 status,result=request('update','Ocean Test',result['id']);assert status==422,(status,result)
 assert books.read_bytes()==before
 # A distinct spelling cannot introduce the same creditor opening a second time.
 body={'csrf':'fixture','entity':'TTI','action':'post_opening_balance','requestKey':'fixture-duplicate-opening','account':'2130','party':'Ocean Test','side':'Credit','amount':123,'date':'2026-07-01'}
 proc=subprocess.run(['php','-d',f'session.save_path={root}',str(runner),str(app),'journal_vouchers.php'],input=json.dumps(body),text=True,capture_output=True,env=env)
 payload,status=proc.stdout.rsplit('\n__STATUS__',1);result=json.loads(payload);assert int(status)==409 and 'already' in result['error'],(status,result,proc.stderr)
 assert books.read_bytes()==before,'Rejected duplicate opening must preserve all original books'
 # Exact missing full names can be restored even if a cancelled/legacy abbreviation also survives in history.
 harness=root/'core.php';harness.write_text("<?php require $argv[1].'/master_store.php';$s=['supplierBills'=>[['vendor'=>'Ocean Test'],['vendor'=>'Ocean Test Shipping Services']]];tt_assert_party_history_name('Ocean Test Shipping Services',$s);try{tt_assert_party_history_name('Ocean Test',$s);exit(2);}catch(InvalidArgumentException $e){} foreach([['Paklink','Paklink Shipping Services',true],['Ocean Test Ltd','Ocean Test Shipping Services',true],['Ocean Test','Ocean Cargo',false],['Shipping Alpha','Shipping Beta',false],['Blue','Blue Logistics',false]] as [$a,$b,$want])if(tt_master_names_conflict($a,$b,'business_parties')!==$want)throw new Exception($a.' / '.$b);")
 proc=subprocess.run(['php',str(harness),str(app)],capture_output=True,text=True,env=env);assert proc.returncode==0,proc.stderr
 print('PASS actual authenticated master API: shortened duplicate rejection, legacy-name protection, exact restoration, name-only history purge block, unrelated party and unchanged financial bytes')
