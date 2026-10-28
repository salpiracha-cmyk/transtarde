"""Standalone register HTTP: permissions, persistence and untouched financial files."""
import json,pathlib,shutil,socket,subprocess,tempfile,time,urllib.request,urllib.error
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='third-party-register-') as directory:
 root=pathlib.Path(directory);(root/'api').mkdir();(root/'data').mkdir()
 for name in ['third_party_register.php','third_party_register_core.php']:shutil.copy(ROOT/'api'/name,root/'api'/name)
 (root/'auth_store.php').write_text('''<?php
define('TT_DATA_DIR',__DIR__.'/data');
function tt_require_login(){return ['id'=>1,'full_name'=>'Register fixture','role'=>$_SERVER['HTTP_X_ROLE']??'owner'];}
function tt_user_can_open_module($u,$m){return $u['role']!=='denied';}
function tt_user_can_module_action($u,$m,$icon,$action){return $u['role']==='owner'||($u['role']==='viewer'&&$action==='View')||($u['role']==='editor'&&in_array($action,['View','Edit'],true));}
function tt_user_can_access_entity($u,$e,$a){return $u['role']==='owner'||($u['role']==='viewer'&&$e==='TTI'&&$a==='View')||($u['role']==='editor'&&$e==='TTI'&&in_array($a,['View','Edit'],true));}
function tt_verify_csrf($v){return $v==='fixture';}function tt_release_read_session(){}function tt_ensure_data_dir(){}
''')
 originals={}
 for name in ['accounts.json','operations.json','auth.json','gate-pass-register.json']:
  p=root/'data'/name;p.write_text(json.dumps({'sentinel':name,'journals':{'ORIGINAL':{'amount':500}}}));originals[name]=p.read_bytes()
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 log=open(root/'php.log','w');server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root)],stdout=log,stderr=log)
 def request(body=None,entity='TTI',role='owner',method=None):
  req=urllib.request.Request(f'http://127.0.0.1:{port}/api/third_party_register.php?entity={entity}',data=json.dumps(body).encode() if body is not None else None,headers={'Content-Type':'application/json','X-Role':role},method=method)
  try:
   with urllib.request.urlopen(req,timeout=5) as response:return response.status,json.load(response)
  except urllib.error.HTTPError as e:return e.code,json.load(e)
 try:
  for _ in range(50):
   try:status,data=request();break
   except urllib.error.URLError:time.sleep(.1)
  assert status==200 and data['rows']==[] and not (root/'data/third-party-payments-register.json').exists(),data
  assert request(role='denied')[0]==403 and request(entity='BRM',role='viewer')[0]==403 and request(method='DELETE')[0]==405
  create={'action':'create','entity':'TTI','csrf':'fixture','requestKey':'register-http-fixture-001','date':'2026-10-28','amount':750,'currency':'PKR','receivedFrom':'Exporter fixture','paidTo':'Broker fixture','category':'Broker','reference':'CHQ-11','notes':'Manual information record'}
  assert request(create,role='viewer')[0]==403 and request({**create,'csrf':'wrong'})[0]==419
  status,data=request(create);assert status==200 and len(data['rows'])==1,(status,data);record=data['record']
  before=(root/'data/third-party-payments-register.json').read_bytes();status,data=request(create);assert status==200 and data['duplicate'] and (root/'data/third-party-payments-register.json').read_bytes()==before
  assert request({**create,'amount':800})[0]==422 and request({**create,'journalId':'FAKE'})[0]==422
  assert request(entity='BRM')[1]['rows']==[] and request(role='viewer')[1]['canEdit'] is False
  edit={**create,'action':'edit','id':record['id'],'version':record['version'],'reason':'Correct reference','reference':'CHQ-12'}
  status,data=request(edit);assert status==200 and data['record']['reference']=='CHQ-12',(status,data)
  assert request(edit)[0]==422 and request({**edit,'entity':'BRM'},entity='BRM')[0]==422
  delete={'action':'delete','entity':'TTI','csrf':'fixture','id':record['id'],'version':2,'reason':'Entered twice'}
  assert request(delete,role='editor')[0]==403
  status,data=request(delete);assert status==200 and data['rows']==[],(status,data)
  saved=json.loads((root/'data/third-party-payments-register.json').read_text());assert saved['rows'][record['id']]['status']=='Deleted' and len(saved['rows'][record['id']]['history'])==2
  for name,content in originals.items():assert (root/'data'/name).read_bytes()==content,'Financial/source file changed: '+name
  print('PASS register HTTP: explicit-only saves, permission/CSRF/company checks, retries, stale edits, retained deletion history and byte-unchanged Accounts/operations/masters/gate passes')
 finally:server.terminate();server.wait(timeout=5);log.close()
