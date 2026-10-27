"""Gate pass issuance exercises the real route, CSRF/scopes, and concurrent register writes."""
import concurrent.futures,json,pathlib,shutil,socket,subprocess,tempfile,time,urllib.request,urllib.error
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='mill-gate-http-') as tmp:
 root=pathlib.Path(tmp);(root/'api').mkdir();(root/'data').mkdir()
 for f in ['mill_gate_pass.php','mill_gate_pass_core.php','mill_recovery_core.php','operations_scope.php']:shutil.copy(ROOT/'api'/f,root/'api'/f)
 (root/'auth_store.php').write_text('''<?php
 // This file-backed fixture must not inherit the CI MySQL service.
 define('TT_DB_HOST','');define('TT_DB_NAME','');define('TT_DB_USER','');
 define('TT_DATA_DIR',__DIR__.'/data');function tt_release_read_session(){}function tt_ensure_data_dir(){}
 function tt_require_login(){return ['role'=>'Staff','username'=>'qa','location'=>$_SERVER['HTTP_X_LOCATION']??'TTI Rice Mills','entity'=>$_SERVER['HTTP_X_ENTITY']??'TTI'];}
 function tt_user_can_open_module($u,$m){return true;}function tt_user_can_module_action($u,$m,$i,$a){return ($_SERVER['HTTP_X_PRINT']??'yes')==='yes';}
 function tt_user_business_entity($u,$e,$a){return $u['entity']===$e;}function tt_verify_csrf($s){return $s==='test';}
 ''')
 ship={'id':1,'entity':'TTI','millName':'TTI Rice Mills','containers':[{'id':2,'container':'FBIU012323-5','truck':'TR-1','seal':'S1','weight':25000,'loadingComplete':True}]}
 source=root/'data/operations.json';source.write_text(json.dumps({'values':{'tt30ship':json.dumps([ship])}}));original=source.read_bytes()
 with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
 log=open(root/'server.log','w');server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root)],stdout=log,stderr=log)
 base={'csrf':'test','key':'tt30ship','recordId':'1','loadId':'2'}
 def req(body=base,headers={}):
  request=urllib.request.Request(f'http://127.0.0.1:{port}/api/mill_gate_pass.php',data=json.dumps(body).encode() if body is not None else None,headers={'Content-Type':'application/json',**headers})
  try:
   with urllib.request.urlopen(request,timeout=5) as r:return r.status,json.load(r)
  except urllib.error.HTTPError as e:return e.code,json.load(e)
 try:
  for _ in range(40):
   try:status,data=req(None);break
   except urllib.error.URLError:time.sleep(.1)
  assert status==405
  assert req({**base,'csrf':'bad'})[0]==419
  assert req(headers={'X-Print':'no'})[0]==403
  assert req(headers={'X-Entity':'BRM'})[0]==403
  assert req(headers={'X-Location':'Another mill'})[0]==403
  assert not (root/'data/gate-pass-register.json').exists()
  status,data=req({**base,'netKg':1,'party':'Forged client value'});assert status==200,data
  assert data['document']['serial']=='GP-000001' and data['document']['netKg']==25000 and data['document']['party']=='Transtrade International'
  assert req()[1]['document']['serial']=='GP-000001'
  assert req({**base,'loadId':'999'})[0]==403
  assert source.read_bytes()==original,'Printing cannot change loading, stock, or accounting sources'
  register=root/'data/concurrent.json'
  code="require $argv[1]; $d=mgp_issue($argv[2],['identity'=>$argv[3]],['username'=>'test']);echo $d['serial'];"
  def issue(i):return subprocess.check_output(['php','-r',code,str(root/'api/mill_gate_pass_core.php'),str(register),str(i%6)],text=True)
  with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:serials=list(pool.map(issue,range(24)))
  assert len(set(serials))==6 and set(serials)=={f'GP-{i:06d}' for i in range(1,7)}
  for i in range(6):assert len({serials[k] for k in range(i,24,6)})==1
  print('PASS gate HTTP: CSRF, module Print/company/location scope, saved-source-only output, stable reprints, no operational mutations, and 24 concurrent requests / 6 unique serials')
 finally:server.terminate();server.wait(timeout=5);log.close()
