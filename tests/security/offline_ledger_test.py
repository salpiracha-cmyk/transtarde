"""Replay and capacity boundaries against the production guard; disposable storage."""
import json, pathlib, subprocess, tempfile, hashlib, time, os
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='offline-ledger-') as tmp:
 root=pathlib.Path(tmp);runner=root/'request.php';store=root/'offline_transactions.json';counter=root/'counter'
 runner.write_text('''<?php
$cfg=json_decode($argv[1],true);define('TT_DATA_DIR',$argv[2]);function tt_ensure_data_dir(){};
$_SERVER['REQUEST_URI']=$cfg['uri']??'/api/post.php';$_SERVER['REQUEST_METHOD']=$cfg['method']??'POST';$_SERVER['CONTENT_TYPE']='application/json';$_GET=$cfg['query']??[];
foreach(['tx'=>'TRANSACTION_ID','base'=>'BASE_RESOURCE_VERSION'] as $k=>$v)if(isset($cfg[$k]))$_SERVER['HTTP_X_TT_'.$v]=$cfg[$k];
$GLOBALS['input']=json_encode($cfg['body']??[]);class Input{public $context;private $n=0;function stream_open($p,$m,$o,&$opened){return $p==='php://input';}function stream_read($n){$s=substr($GLOBALS['input'],$this->n,$n);$this->n+=strlen($s);return $s;}function stream_eof(){return $this->n>=strlen($GLOBALS['input']);}function stream_stat(){return [];}}stream_wrapper_unregister('php');stream_wrapper_register('php',Input::class);
register_shutdown_function(function()use($argv){file_put_contents($argv[3],(string)(http_response_code()?:200));});
require $argv[4];tt_offline_request_guard(['id'=>$cfg['user']??1]);
if(($cfg['method']??'POST')==='GET'){echo json_encode(['ok'=>true]);exit;}
$count=(int)@file_get_contents(TT_DATA_DIR.'/counter')+1;file_put_contents(TT_DATA_DIR.'/counter',(string)$count);
header('Content-Type: application/json');http_response_code($cfg['status']??200);
if(!empty($cfg['pause'])){touch(TT_DATA_DIR.'/ready');while(!is_file(TT_DATA_DIR.'/continue'))usleep(1000);}
echo json_encode(['ok'=>true,'postId'=>'POST-'.$count,'value'=>str_repeat('x',$cfg['size']??3)]);
''')
 def start(cfg):
  status=root/('status-'+os.urandom(4).hex());p=subprocess.Popen(['php',str(runner),json.dumps(cfg),str(root),str(status),str(ROOT/'offline_idempotency.php')],stdout=subprocess.PIPE,stderr=subprocess.PIPE);return p,status
 def finish(job):
  p,status=job;out,err=p.communicate(timeout=15);assert p.returncode==0,(out,err);return int(status.read_text()),out.decode()
 def req(**cfg):return finish(start(cfg))
 def count():return int(counter.read_text()) if counter.exists() else 0
 tx='TRANSACTION-000001'
 a=req(tx=tx);assert a[0]==200 and count()==1,a
 assert req(tx=tx)==a and count()==1,'Exact acknowledgement replay must not repost'
 assert req(tx=tx,body={'changed':True})[0]==409 and count()==1
 assert req(tx='TRANSACTION-000002',base='0')[0]==409 and count()==1
 assert req(tx=tx,user=2)[0]==200 and count()==2,'User identity isolates transaction keys'
 big=req(tx='TRANSACTION-BIG001',size=250000);assert big[0]==200 and len(big[1])>250000 and count()==3,big[0]
 data=json.loads(store.read_text());assert data['transactions']['1|TRANSACTION-BIG001']['state']=='review_required' and len(store.read_bytes())<20000
 assert req(tx='TRANSACTION-BIG001',size=250000)[0]==409 and count()==3,'Response overflow retains no-repeat metadata'
 job=start({'tx':'TRANSACTION-PENDING','pause':True})
 deadline=time.time()+5
 while not (root/'ready').exists() and time.time()<deadline:time.sleep(.01)
 assert (root/'ready').exists();assert req(tx='TRANSACTION-PENDING',pause=True)[0]==409 and count()==4
 (root/'continue').touch();assert finish(job)[0]==200
 data=json.loads(store.read_text());original=json.loads(store.read_text());before=count()
 # Full per-user count rejects before controller; existing completed replay still works.
 for i in range(512):data['transactions']['1|PAD-'+str(i)]={'state':'review_required','requestHash':'a'*64,'resource':'/api/post.php|','time':int(time.time())}
 store.write_text(json.dumps(data));assert req(tx='TRANSACTION-NEW001')[0]==503 and count()==before
 assert req(tx=tx)==a and count()==before
 # Pending reservations consume budget before their eventual response is known.
 data=original.copy();data['transactions']={str(i)+'|PENDING':{'state':'pending','requestHash':'b'*64,'time':int(time.time()),'resource':'/api/post.php|'} for i in range(80)}
 store.write_text(json.dumps(data));assert req(tx='TRANSACTION-NEW002')[0]==503 and count()==before
 assert req(uri='/api/new-resource.php')[0]==503 and count()==before,'Ordinary writes may not consume pending completion reservations'
 # Serialized completed response bytes are capped per user, even below the row-count limit.
 data={'transactions':{'1|COMPLETE-'+str(i):{'state':'complete','requestHash':'a'*64,'time':int(time.time()),'resource':'/api/post.php|','response':'x'*16384,'status':200} for i in range(130)},'resources':{'/api/post.php|':0}}
 store.write_text(json.dumps(data));assert req(tx='TRANSACTION-USERBYTES')[0]==503 and count()==before
 # Global count cannot be bypassed by spreading requests across identities.
 data={'transactions':{str(i)+'|TERMINAL':{'state':'review_required','requestHash':'d'*64,'resource':'/api/post.php|','time':int(time.time())} for i in range(4096)},'resources':{}}
 store.write_text(json.dumps(data));assert req(tx='TRANSACTION-GLOBALCOUNT')[0]==503 and count()==before
 # Resource cardinality applies to ordinary writes too, before controller execution.
 data={'transactions':{},'resources':{'/api/r'+str(i)+'.php|':0 for i in range(512)}};store.write_text(json.dumps(data));assert req()[0]==503 and count()==before
 # Active records are never evicted; only those older than the existing 14-day window expire.
 data={'transactions':{'1|PAD-'+str(i):{'state':'pending','requestHash':'c'*64,'resource':'/api/post.php|','time':int(time.time())-1209602} for i in range(512)},'resources':{}}
 store.write_text(json.dumps(data));assert req(tx='TRANSACTION-EXPIRED')[0]==200 and count()==before+1
 app=root/'app';app.mkdir();private=root/'transtrade_private';private.mkdir()
 for invalid in ['{broken','null','', 'x'*8388609]:
  store.write_text(invalid);raw=store.read_bytes();n=count();(private/'offline_transactions.json').write_text(invalid)
  gate=subprocess.run(['php',str(ROOT/'scripts/offline-ledger-readiness.php'),str(app)],capture_output=True);assert gate.returncode and (private/'offline_transactions.json').read_bytes()==raw
  assert req(tx='TRANSACTION-CORRUPT')[0]==503 and count()==n;assert store.read_bytes()==raw
  assert req(method='GET')[0]==503 and store.read_bytes()==raw
 store.unlink();assert req(method='GET')[0]==200 and not store.exists(),'Ordinary fresh reads do not allocate replay storage'
 assert req(method='GET',uri='/api/operations.mysql.php',query={'entity':'TTI'})[0]==200
 assert req(method='GET',uri='/api/accounts_ledger_browser.php',query={'entity':'ALL'})[0]==200
 assert req()[0]==200;assert json.loads(store.read_text())['resources']['/api/post.php|']==1
 n=count();assert req(tx='TRANSACTION-ENTITY',query={'entity':'X'*65})[0]==422 and count()==n
 print('PASS exact replay/conflicts/user isolation, bounded overflow, concurrent pending reservation, user/global/resource quotas before side effects, TTL and corrupt/oversize fail-closed behavior')
