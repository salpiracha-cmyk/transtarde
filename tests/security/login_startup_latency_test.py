"""Authentication must not build backups; console reads release session locks."""
import json, pathlib, shutil, subprocess, tempfile
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tti-login-latency-') as directory:
 base=pathlib.Path(directory);app=base/'app';app.mkdir();(app/'api').mkdir()
 for name in ['auth_store.php','session_store.php','master_store.php','product_stage.php','offline_idempotency.php','qa_account.php','login.php']:
  shutil.copy(ROOT/name,app/name)
 for name in ['backup.php','users.php','director_approvals.php']:
  shutil.copy(ROOT/'api'/name,app/'api'/name)
 (app/'backup_lib.php').write_text('''<?php
function tt_maybe_auto_backup():void{$GLOBALS['backupCalls']=($GLOBALS['backupCalls']??0)+1;}
function tt_backup_status():array{if(session_status()===PHP_SESSION_ACTIVE)throw new RuntimeException('Backup held session');tt_maybe_auto_backup();return ['fixtureBackupAvailable'=>true];}
''')
 (app/'office_backup_auth.php').write_text('<?php function tt_office_backup_enabled():bool{return false;}')
 (app/'api/director_approvals_core.php').write_text('<?php function tt_pending_director_rate_approvals(array $s):array{return [];} function tt_pending_jv_approvals(array $s):array{return [];}')
 harness=base/'request.php';meta=base/'meta.json'
 harness.write_text('''<?php
$cfg=json_decode($argv[2],true);$_SERVER['REQUEST_METHOD']=$cfg['method']??'GET';$_SERVER['REQUEST_URI']=$cfg['path']??'/login.php';$_SERVER['REMOTE_ADDR']='192.0.2.82';$_COOKIE['TRANSTRADE_SESSION']=$cfg['sid']??'';$_POST=$cfg['form']??[];$_GET=$cfg['query']??[];
register_shutdown_function(static function()use($argv){file_put_contents($argv[3],json_encode(['status'=>http_response_code()?:200,'user'=>$_SESSION['user_id']??null,'sid'=>session_id(),'csrf'=>$_SESSION['csrf']??null,'backupCalls'=>$GLOBALS['backupCalls']??0,'sessionActive'=>session_status()===PHP_SESSION_ACTIVE]));});
$app=$argv[1];
if(($cfg['action']??'')==='seed'){
 require $app.'/auth_store.php';tt_mutate_store(static function(&$s){$s['users']=[];foreach([1=>'Super Admin',2=>'Accounts']as$id=>$role)$s['users'][]=['id'=>$id,'username'=>'fixture'.$id,'full_name'=>'Fixture '.$id,'role'=>$role,'active'=>true,'session_version'=>1,'password_hash'=>password_hash('FixturePass123!',PASSWORD_DEFAULT),'permissions'=>['Accounts'=>'all']];$s['settings']=['qa_account_seeded'=>true,'qa_account_provisioning_required'=>true];});tt_csrf();
}elseif(($cfg['action']??'')==='business'){
 require $app.'/auth_store.php';tt_mutate_store(static function(&$s){$s['fixtureSaved']=true;});
}else require $app.'/'.ltrim($_SERVER['REQUEST_URI'],'/');
''')
 def request(**cfg):
  result=subprocess.run(['php',str(harness),str(app),json.dumps(cfg),str(meta)],capture_output=True,text=True)
  assert result.returncode==0,result.stdout+result.stderr
  return json.loads(meta.read_text()),result.stdout
 sessions={}
 for user_id in [1,2]:
  seed,_=request(action='seed')
  signed,_=request(method='POST',sid=seed['sid'],form={'csrf':seed['csrf'],'username':f'fixture{user_id}','password':'FixturePass123!'})
  assert signed['user']==user_id and signed['backupCalls']==0,('Sign-in built a backup',signed)
  sessions[user_id]=signed['sid']
  store=json.loads((base/'transtrade_private/auth.json').read_text());assert store['audit'][0]['action']=='Signed in'
  assert next(u for u in store['users'] if u['id']==user_id)['last_login_at']
 # Both roles use the same authentication path; denied login still records its audit.
 seed,_=request(action='seed')
 failed,output=request(method='POST',sid=seed['sid'],form={'csrf':seed['csrf'],'username':'fixture2','password':'WrongFixturePassword!'})
 assert failed['user'] is None and failed['backupCalls']==0 and 'Incorrect username or password' in output
 assert json.loads((base/'transtrade_private/auth.json').read_text())['audit'][0]['action']=='Failed sign-in'
 assert request(action='business')[0]['backupCalls']==1,'Normal mutation lost automatic backup trigger'
 # Re-seeding preserves fixture identities/session versions, so the existing admin session remains valid.
 for endpoint in ['backup','users','director_approvals']:
  result,output=request(path=f'/api/{endpoint}.php',sid=sessions[1])
  assert result['status']==200 and json.loads(output)['ok'],(endpoint,result,output)
  assert not result['sessionActive'],(endpoint,'Console read held session lock')
  denied,_=request(path=f'/api/{endpoint}.php',sid=sessions[2]);assert denied['status']==403
 print('PASS both-role sign-in and failure audit without backup work; business backup retained; console GETs release sessions and preserve access denials')
