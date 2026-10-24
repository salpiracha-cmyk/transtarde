"""Dated shared quotes/defaults: explicit writes, optimistic versions, retries, and no ledger changes."""
import json,pathlib,shutil,subprocess,tempfile
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='director-defaults-') as temp:
 root=pathlib.Path(temp);shutil.copytree(ROOT/'api',root/'api');shutil.copytree(ROOT/'directors',root/'directors');(root/'data').mkdir()
 (root/'auth_store.php').write_text('''<?php
define('TT_DATA_DIR',__DIR__.'/data');define('TT_DB_HOST','');
function tt_require_login(){return ['id'=>1,'username'=>'Fixture'];}
function tt_user_can_open_module($u,$m){return ($_GET['mode']??'')!=='deny';}
function tt_user_can_module_action($u,$m,$i,$a){return ($_GET['mode']??'')!=='read';}
function tt_verify_csrf($v){return $v==='fixture';}function tt_csrf(){return 'fixture';}
function tt_release_read_session(){if(($_SERVER['REQUEST_METHOD']??'')!=='GET')throw new LogicException('Only reads release the session');}function tt_ensure_data_dir(){}function tt_accounts_input(){return $GLOBALS['body'];}
''')
 books={'revision':7,'journals':{'KEEP':{'amount':123}},'directorCostings':{'OLD':{'input':{'packingAdjustment':3,'fx':270}}}};path=root/'data/accounts.json';path.write_text(json.dumps(books));before=path.read_bytes()
 (root/'run.php').write_text('''<?php
$r=json_decode(stream_get_contents(STDIN),true);$_GET=$r['query'];$_SERVER['REQUEST_METHOD']=$r['body']===null?'GET':'POST';$GLOBALS['body']=json_encode($r['body']);register_shutdown_function(function(){fwrite(STDERR,'STATUS:'.(http_response_code()?:200));});require __DIR__.'/api/directors_cost_defaults.php';
''')
 def call(body=None,date='2026-10-24',mode=''):
  run=subprocess.run(['php',str(root/'run.php')],input=json.dumps({'query':{'date':date,'mode':mode},'body':body}),text=True,capture_output=True,check=True)
  return int(run.stderr.rsplit('STATUS:',1)[1]),json.loads(run.stdout)
 assert call()[0]==200 and path.read_bytes()==before
 assert call(mode='deny')[0]==403
 rates={'action':'save_rates','date':'2026-10-24','version':0,'fx':280,'cashPrice':3800,'creditPrice':3920,'purchaseUnit':'MAUND','brokenPrice':84,'brokenUnit':'KG','csrf':'fixture','requestKey':'daily-save-0001'}
 assert call(rates,mode='read')[0]==403
 assert call({**rates,'csrf':'bad'})[0]==419
 code,result=call(rates);assert code==200,result;assert result['rates']['version']==1
 saved=path.read_bytes();assert call(rates)[0]==200 and path.read_bytes()==saved
 assert call({**rates,'fx':281})[0]==409
 assert call({**rates,'requestKey':'stale-version-0002'})[0]==409
 assert call(date='2026-10-23')[1]['rates'] is None,'Future daily rate leaked backwards'
 pack={'action':'save_packing','name':'25 kg PP','amount':4,'masterBagAmount':1.5,'date':'2026-10-24','version':0,'csrf':'fixture','requestKey':'packing-save-0001'}
 code,result=call(pack);assert code==200,result
 assert next(p for p in result['packings'] if p['name']=='25 kg PP')['amount']==4
 assert next(p for p in call(date='2026-10-23')[1]['packings'] if p['name']=='25 kg PP')['amount']==3
 assert call({**pack,'requestKey':'packing-stale-0002'})[0]==409
 assert call({**pack,'name':'50 kg PP','requestKey':'packing-base-0003'})[0]==422
 assert call({**pack,'name':'10 kg printed PP','requestKey':'packing-new-0004'})[0]==200
 assert call({**rates,'date':'2099-01-01','requestKey':'future-rate-0005'})[0]==422
 after=json.loads(path.read_text());assert after['journals']==books['journals'];assert after['directorCostings']==books['directorCostings']
 print('PASS shared defaults: read-only GET, permissions, CSRF, exact retries, stale versions, dated packing defaults and immutable saved costings/journals')
