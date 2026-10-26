"""Real endpoint approval: stale reviews, company access and immutable approved snapshots."""
import json,pathlib,shutil,socket,subprocess,tempfile,time,urllib.request,urllib.error
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='actual-cost-http-') as tmp:
 root=pathlib.Path(tmp);(root/'api').mkdir();(root/'data').mkdir()
 for f in ['production_fixed_overhead.php','production_costing.php','production_costing_core.php','management_costing.php','management_costing_core.php','accounts_source_store.php','mill_recovery_core.php']:shutil.copy(ROOT/'api'/f,root/'api'/f)
 (root/'auth_store.php').write_text('''<?php
define('TT_DATA_DIR',__DIR__.'/data');function tt_ensure_data_dir(){}function tt_release_read_session(){}
function tt_require_login(){return ['role'=>($_SERVER['HTTP_X_ROLE']??'owner')==='owner'?'Super Admin':'Staff','permissions'=>['Accounts'=>['View']],'full_name'=>'Disposable QA'];}
function tt_user_can_open_module($u,$m){return $m==='Accounts';}
function tt_user_can_access_entity($u,$e,$a='View'){return $u['role']==='Super Admin'||($e==='TTI'&&$a==='View');}
function tt_verify_csrf($v){return $v==='fixture';}
''')
 p={'id':1,'entity':'TTI','millName':'TTI Rice Mills','date':'2026-10-03','variety':'IRRI-6','sourceVariety':'IRRI-6 White Rice','recoveryVersion':1,'rawInputs':[{'grossKg':1000}],'rows':[{'id':1,'product':'Ready Rice — A','bags':14,'bagWeight':50},{'id':2,'product':'Stone','bags':6,'bagWeight':50}]}
 store={'revision':1,'events':[{'entity':'TTI','eventType':'COMMODITY_RECEIPT_ACCEPTED','sourceKey':'R1','journalId':'J1'}],'journals':{'J1':{'id':'J1','entity':'TTI','date':'2026-10-01','totalDebit':60000,'meta':{'variety':'IRRI-6 White Rice','payableWeightKg':2000}}},'commodityBills':{'B1':{'id':'B1','status':'Verified / Posted','finalCommodityValue':60000,'receiptAllocations':[{'sourceKey':'R1','provisionalAmount':60000}]}}}
 ops={'values':{'tt30prod':json.dumps([p]),'tt38labourbills':json.dumps([{'id':1,'entity':'TTI','millName':'TTI Rice Mills','date':'2026-10-03','amount':2000}])}}
 books=root/'data/accounts.json';source=root/'data/operations.json';books.write_text(json.dumps(store));source.write_text(json.dumps(ops))
 with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
 log=open(root/'php.log','w');server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root)],stdout=log,stderr=log)
 def req(body=None,role='owner',path='production_costing.php',entity='TTI'):
  r=urllib.request.Request(f'http://127.0.0.1:{port}/api/{path}?entity={entity}',data=json.dumps(body).encode() if body is not None else None,headers={'Content-Type':'application/json','X-Role':role})
  try:
   with urllib.request.urlopen(r,timeout=5) as response:return response.status,json.load(response)
  except urllib.error.HTTPError as e:return e.code,json.load(e)
 try:
  for _ in range(50):
   try:status,d=req();break
   except urllib.error.URLError:time.sleep(.1)
  assert status==200,d;g=d['groups'][0];assert g['readyToApprove'],g
  initial=(books.read_bytes(),source.read_bytes())
  assert req(role='staff',entity='BRM')[0]==403
  assert req(role='staff',entity='BRM',path='management_costing.php')[0]==403
  action={'csrf':'fixture','action':'approve_rate','entity':'TTI','groupId':g['id'],'costsReviewed':True,'expectedCostFingerprint':g['reviewFingerprint']}
  assert req(action,role='staff')[0]==403
  assert req({**action,'csrf':'wrong'})[0]==419
  assert req({**action,'expectedCostFingerprint':'stale'})[0]==409
  assert initial==(books.read_bytes(),source.read_bytes()),'Denied and stale reviews never write'
  store['commodityBills']['B1']['finalCommodityValue']=80000;books.write_text(json.dumps(store));assert req(action)[0]==409,'Changed final bill invalidates old review'
  status,d=req();action['expectedCostFingerprint']=d['groups'][0]['reviewFingerprint'];status,approved=req(action);assert status==200,approved
  snapshot=json.loads(books.read_text());saved=snapshot['productionCostRuns'][g['id']];assert saved['calculation']['quantityBasis']=='ACTUAL_INPUT' and saved['approvedBy']=='Disposable QA'
  assert snapshot['journals']==store['journals'],'Cost approval must not invent a journal'
  old=books.read_bytes();assert req(action)[0] in [409,422];assert books.read_bytes()==old,'Replay cannot duplicate the approved rate'
  snapshot['salaryMasters']={'SM':{'entity':'TTI','status':'Active','productionCostEligible':True,'monthlyAmount':7000}}
  snapshot['salaryPeriods']={'TTI|2026-10|SM':{'netSalary':7000}}
  books.write_text(json.dumps(snapshot));status,fixed=req({'csrf':'fixture','action':'apply_to_run','entity':'TTI','groupId':g['id']},path='production_fixed_overhead.php');assert status==200,fixed
  assert abs(fixed['finalCostPerKg']-(saved['costPerKg']+10))<.000001,fixed
  # Posted monthly additions/reductions affect eligible mill salary cost; recoveries do not.
  adjusted=json.loads(books.read_text());adjusted['salaryPeriods']['TTI|2026-10|SM'].update({'monthlyAddition':1000,'monthlyReduction':300,'advanceApplied':500,'paidAfterPrepare':2000})
  books.write_text(json.dumps(adjusted));before=books.read_bytes();status,month_cost=req(path='production_fixed_overhead.php')
  assert status==200 and month_cost['months']['2026-10']['millSalary']==7700,month_cost
  assert books.read_bytes()==before,'Viewing changed salary costs never rewrites approved stock rates'

  new_snapshot=json.loads(books.read_text());assert new_snapshot['journals']==store['journals'] and new_snapshot['productionCostRuns'][g['id']]['calculation']==saved['calculation'],'Fixed allocation retains base cost snapshot and creates no duplicate journal'
  saved=new_snapshot['productionCostRuns'][g['id']];old=books.read_bytes()
  p['rawInputs'][0]['grossKg']=1100;ops['values']['tt30prod']=json.dumps([p]);source.write_text(json.dumps(ops));status,d=req();assert status==200 and 'production changed' in d['groups'][0]['status'];assert d['groups'][0]['suggestedReadyRiceCostPerKg']==saved['costPerKg'];assert books.read_bytes()==old
  status,fixed=req(path='production_fixed_overhead.php');assert status==200 and fixed['months']['2026-10']['readyRiceKg']==700,fixed
  status,d=req(path='management_costing.php');assert status==200 and d['sheets'][0]['inputKg']==1100,d
  books.write_text('{broken');assert req()[0]==500;assert req(action)[0]==500;assert books.read_text()=='{broken','Malformed store cannot be overwritten'
  print('PASS costing HTTP: approval, stale bill fingerprint, CSRF/entity/write denial, snapshot preservation, duplicate protection, no journal and corrupt-store protection')
 finally:server.terminate();server.wait(timeout=5);log.close()
