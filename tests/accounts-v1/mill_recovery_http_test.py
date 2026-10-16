"""Read-only reports: real PHP routing, company/location scope and malformed-source failures."""
import json,pathlib,shutil,socket,subprocess,tempfile,time,urllib.request,urllib.error
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='mill-recovery-http-') as tmp:
 root=pathlib.Path(tmp)
 for d in ['api','data']:(root/d).mkdir()
 for f in ['mill_recovery.php','mill_recovery_core.php','mill_recovery_cost.php','production_costing_core.php','management_costing_core.php']:shutil.copy(ROOT/'api'/f,root/'api'/f)
 for f in ['inventory_reconciliation.php','product_stage.php']:shutil.copy(ROOT/f,root/f)
 (root/'auth_store.php').write_text('''<?php
 define('TT_DATA_DIR',__DIR__.'/data');function tt_release_read_session(){}
 function tt_require_login(){$r=$_SERVER['HTTP_X_ROLE']??'owner';return ['role'=>$r==='owner'?'Super Admin':'Staff','location'=>$_SERVER['HTTP_X_LOCATION']??'All authorized locations','permissions'=>$r==='mill'?['Mill'=>['reports'=>['View']]]:($r==='accounts'?['Accounts'=>['reports'=>['View']]]:[])];}
 function tt_user_can_open_module($u,$m){return $u['role']==='Super Admin'||isset($u['permissions'][$m]);}
 function tt_user_can_access_entity($u,$e,$a='View'){return $u['role']==='Super Admin'||(isset($u['permissions']['Accounts'])&&$e==='TTI');}
 function tt_user_accounts_entities($u){return ['TTI'];}
 ''')
 run={'id':1,'entity':'TTI','date':'2026-09-21','shift':'Day','variety':'IRRI-6','millName':'TTI Rice Mills','rawInputs':[{'grossKg':100,'brand':'A','contractRef':'C1'}],'recoveryOutputContexts':{'1':{'brand':'A','contractRef':'C1'}},'rows':[{'id':1,'product':'Ready Rice — A','bags':1,'bagWeight':75}]}
 rows=[run,{**run,'id':2,'millName':'Other Mill','rawInputs':[{'grossKg':500,'brand':'PRIVATE','contractRef':'C2'}],'rows':[{'id':1,'product':'Ready Rice — PRIVATE','bags':5,'bagWeight':75}]},{**run,'id':3,'entity':'BRM'}]
 ops={'revision':7,'values':{'tt30prod':json.dumps(rows)}};source=root/'data/operations.json';source.write_text(json.dumps(ops));books=root/'data/accounts.json';books.write_text(json.dumps({'revision':4,'journals':{},'productionCostRuns':{}}))
 before=(source.read_bytes(),books.read_bytes())
 with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
 log=open(root/'php.log','w');server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root)],stdout=log,stderr=log)
 def req(query='entity=TTI',role='owner',location='All authorized locations',body=None):
  r=urllib.request.Request(f'http://127.0.0.1:{port}/api/mill_recovery.php?'+query,data=json.dumps(body).encode() if body is not None else None,headers={'Content-Type':'application/json','X-Role':role,'X-Location':location})
  try:
   with urllib.request.urlopen(r,timeout=5) as response:return response.status,json.load(response)
  except urllib.error.HTTPError as e:return e.code,json.load(e)
 try:
  for _ in range(50):
   try:status,data=req();break
   except urllib.error.URLError:time.sleep(.1)
  assert status==200,data;assert data['totals']['inputKg']==600 and data['totals']['outputKg']==450 and data['canViewCosts'];assert data['operationsRevision']==7
  status,data=req('entity=BRM',role='accounts');assert status==403,data
  assert req(role='denied')[0]==403
  status,data=req(role='mill');assert status==200 and not data['canViewCosts'] and 'costTotals' not in data,data
  status,data=req(role='accounts',location='TTI Rice Mills');assert status==200,data;assert data['totals']['inputKg']==100 and data['brands']==['A'] and data['contracts']==['C1'] and not data['canViewCosts'],data
  assert req('entity=TTI&from=2026-02-30')[0]==422
  assert req('entity=TTI&from=2026-10-10&to=2026-10-01')[0]==422
  assert req('entity=TTI&mode=wrong')[0]==422
  assert req('entity=TTI&shift=wrong')[0]==422
  assert req(body={})[0]==405
  assert before==(source.read_bytes(),books.read_bytes()),'Report opening/filtering/denials cannot write production or Accounts'
  source.write_text('{broken');assert req()[0]==500
  source.write_text(json.dumps({'values':{'tt30prod':'{broken'}}));assert req()[0]==500
  source.write_text(json.dumps({'values':{'tt30prod':'{"not":"a list"}'}}));assert req()[0]==500
  source.write_text(json.dumps(ops));books.write_text('{broken');assert req()[0]==500;assert req(role='mill')[0]==200,'Mill quantity report does not read or expose restricted cost books'
  print('PASS recovery HTTP: real source, company/location/financial scope, strict calendar filters, read-only operation and corrupt-source failures')
 finally:server.terminate();server.wait(timeout=5);log.close()
