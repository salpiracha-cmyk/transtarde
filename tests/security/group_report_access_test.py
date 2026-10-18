"""Real authentication protects shared group sources and their Accounts/Directors links."""
import json, os, pathlib, shutil, subprocess, tempfile
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tti-group-report-access-') as directory:
 root=pathlib.Path(directory);app=root/'app';shutil.copytree(ROOT,app,ignore=shutil.ignore_patterns('.git','node_modules','__pycache__','tmp','playwright-report','test-results'));private=root/'transtrade_private';private.mkdir()
 harness=root/'request.php';harness.write_text(r'''<?php
$_GET=json_decode($argv[3],true);$_SERVER['REQUEST_URI']=substr($argv[1],strlen(__DIR__.'/app'));$_SERVER['REQUEST_METHOD']=$argv[4]==='null'?'GET':'POST';$_SERVER['REMOTE_ADDR']='192.0.2.46';
session_name('TRANSTRADE_SESSION');session_id('group-access-'.bin2hex(random_bytes(12)));session_start();$_SESSION=['user_id'=>1,'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];session_write_close();$GLOBALS['fixtureBody']=$argv[4];$GLOBALS['statusFile']=$argv[2];
class FixtureGroupInput{public $context;private $offset=0;function stream_open($p,$m,$o,&$opened){return $p==='php://input';}function stream_read($n){$v=substr($GLOBALS['fixtureBody'],$this->offset,$n);$this->offset+=strlen($v);return $v;}function stream_eof(){return $this->offset>=strlen($GLOBALS['fixtureBody']);}function stream_stat(){return [];}}
stream_wrapper_unregister('php');stream_wrapper_register('php',FixtureGroupInput::class);register_shutdown_function(static function(){file_put_contents($GLOBALS['statusFile'],(string)(http_response_code()?:200));});require $argv[1];
''')
 books=private/'accounts.json';ops=private/'operations.json';books.write_text(json.dumps({'revision':7,'journals':{'SOURCE-POST':{'id':'SOURCE-POST','entity':'TTI','date':'2026-10-01','status':'Posted','lines':[{'account':'1110','debit':123,'credit':0,'accountName':'GROUP_PRIVATE_SENTINEL'}]}}}));ops.write_text(json.dumps({'revision':4,'values':{'transtrade_export_v3_operational':'{"contracts":[],"shipments":[]}'}}));before=(books.read_bytes(),ops.read_bytes())
 base={'id':1,'username':'fixture','full_name':'Fixture','role':'Staff','active':True,'session_version':1,'must_change_password':False};entities={f'entity-{e}':['View'] for e in ['tti','brm','tg']};env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
 def user(accounts=None,directors=None,role='Staff'):
  return {**base,'role':role,'permissions':{'Accounts':accounts or {},'Directors':directors or {}}}
 def request(u,path='api/group_management.php',origin='accounts',kind='DAILY',body=None):
  (private/'auth.json').write_text(json.dumps({'users':[u],'masters':{},'audit':[]}));status_file=root/'status.txt';query={'kind':kind,'date':'2026-10-17','origin':origin};payload=None if body is None else {'csrf':'fixture','kind':kind,'date':'2026-10-17',**body}
  result=subprocess.run(['php','-d',f'session.save_path={root}',str(harness),str(app/path),str(status_file),json.dumps(query),json.dumps(payload)],env=env,text=True,capture_output=True)
  assert result.returncode==0,result.stderr+result.stdout
  return int(status_file.read_text()),result.stdout
 viewer=user({**entities,'group-reports':['View']});limited=user({'entity-tti':['View'],'group-reports':['View']});wrong=user({**entities,'reports':['View']});director=user(entities,{'group-reports':['View']})
 for u,origin in [(limited,'accounts'),(wrong,'accounts'),(user(),'accounts'),(user(entities,{'reports':['View']}),'directors')]:
  status,text=request(u,origin=origin);assert status==403 and 'GROUP_PRIVATE_SENTINEL' not in text,(status,text)
 assert not (private/'group-management.json').exists() and not (private/'group-management.json.lock').exists()
 status,text=request(viewer);assert status==200 and 'GROUP_PRIVATE_SENTINEL' in text,(status,text)
 assert request(director,origin='directors')[0]==200
 assert request(user(role='Super Admin'))[0]==200
 status,html=request(user(role='Super Admin'),'accounts/group-management.php');assert status==200 and '"allowed":["DAILY"]' in html and '<h1>Group Daily Position</h1>' in html,'Daily navigation exposed unrelated report drafts'
 assert request(viewer,kind='ZAKAT')[0]==403
 assert request(viewer,kind='COSTING')[0]==403,'Group report icon granted the separate costing action'
 assert request({**viewer,'system_qa':True},body={'action':'save-section','section':'header','version':0,'requestKey':'fixture-managed','data':{'rates':{}}})[0]==403
 assert request(viewer,body={'action':'save-section','section':'header','version':0,'requestKey':'fixture-read-only','data':{'rates':{}}})[0]==403
 for u,visible in [(viewer,True),(limited,False),(wrong,False)]:
  status,html=request(u,'accounts/index.php');assert status==200,(status,html);assert ('"canGroupDaily":true' in html)==visible
 for u,visible in [(director,True),(user(entities,{'reports':['View']}),False)]:
  status,html=request(u,'directors/index.php',origin='directors');assert status==200,(status,html);assert ('Group Daily Position' in html)==visible
 writer=user({**entities,'group-reports':['View','Create']});status,text=request(writer,body={'action':'save-section','section':'header','version':0,'requestKey':'fixture-authorized','data':{'rates':{}}});assert status==200,(status,text);assert (private/'group-management.json').exists()
 director_writer=user(entities,{'group-reports':['View','Create','Edit']});status,text=request(director_writer,origin='directors',body={'action':'save-section','section':'header','version':1,'requestKey':'fixture-director','data':{'rates':{'AED':76}}});assert status==200,(status,text)
 assert (books.read_bytes(),ops.read_bytes())==before,'Report permissions or header save changed canonical sources'
 print('PASS real-auth group report permissions and native links: exact icon/all-company scope, separate costing access, read-only write denial, Accounts/Directors/Super Admin positives and unchanged original books/stock')
