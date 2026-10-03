"""Actual review API: durable dismissal, no financial mutations, stale/version and rights checks."""
import json,pathlib,shutil,socket,subprocess,tempfile,time,urllib.request,urllib.error
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='accounts-reviews-') as temp:
 root=pathlib.Path(temp);(root/'api').mkdir();(root/'data').mkdir()
 for name in ['tg_remittance_core.php', 'receipt_invoice_links.php', 'fi_credit_advice_link.php', 'accounts_reviews.php','accounts_reviews_core.php']:shutil.copy(ROOT/'api'/name,root/'api'/name)
 (root/'auth_store.php').write_text('''<?php
define('TT_DATA_DIR',__DIR__.'/data');function tt_ensure_data_dir(){}
function tt_require_login(){return ['username'=>'Fixture','role'=>'Staff','permissions'=>['Accounts'=>($_GET['role']??'')==='readonly'?['View']:['View','Edit']]];}
function tt_user_can_open_module($u,$m){return true;}
function tt_user_can_access_entity($u,$e,$a){return $e==='TTI'&&($a==='View'||($_GET['role']??'')!=='readonly');}
function tt_verify_csrf($t){return $t==='fixture';}
''')
 initial={'journals':{'J1':{'status':'Posted','entity':'TTI','totalDebit':100}},'payableHolds':{'H1':{'entity':'TTI','active':True,'sourceKey':'P1','reason':'Missing document'}}}
 (root/'data/accounts.json').write_text(json.dumps(initial))
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 def request(body,role=''):
  req=urllib.request.Request(f'http://127.0.0.1:{port}/api/accounts_reviews.php?role={role}',data=json.dumps(body).encode(),headers={'Content-Type':'application/json'})
  try:
   with urllib.request.urlopen(req,timeout=3)as r:return r.status,json.load(r)
  except urllib.error.HTTPError as e:return e.code,json.load(e)
 try:
  for _ in range(50):
   try:request({});break
   except urllib.error.URLError:time.sleep(.1)
  script="require $argv[1];$s=json_decode(file_get_contents($argv[2]),true);echo json_encode(ar_items($s,'TTI'));"
  item=json.loads(subprocess.check_output(['php','-r',script,str(root/'api/accounts_reviews_core.php'),str(root/'data/accounts.json')]))[0]
  payload={'action':'discard','entity':'TTI','csrf':'fixture','id':item['id'],'fingerprint':item['fingerprint']}
  assert request({**payload,'csrf':'wrong'})[0]==419
  assert request(payload,'readonly')[0]==403
  assert request({**payload,'entity':'TG'})[0]==403
  assert request({**payload,'fingerprint':'old'})[0]==409
  assert json.loads((root/'data/accounts.json').read_text())==initial
  assert request(payload)[0]==200
  after=json.loads((root/'data/accounts.json').read_text());assert after['journals']==initial['journals'];assert after['payableHolds']==initial['payableHolds']
  assert request(payload)[0]==409,'Discard retry must not write twice'
  items=json.loads(subprocess.check_output(['php','-r',script,str(root/'api/accounts_reviews_core.php'),str(root/'data/accounts.json')]))
  assert items==[],'Dismissal must survive a new request'
  print('Actual review HTTP: CSRF, read-only, company rights, stale fingerprint, persistence and financial source preservation passed')
 finally:server.terminate();server.wait(timeout=5)
