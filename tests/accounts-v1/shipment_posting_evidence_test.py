"""Shipment contribution reads disposable original books; never posts or repairs them."""
import json, pathlib, shutil, subprocess, tempfile
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='shipment-evidence-') as tmp:
 root=pathlib.Path(tmp);shutil.copytree(ROOT/'api',root/'api');(root/'data').mkdir()
 (root/'auth_store.php').write_text("""<?php
define('TT_DATA_DIR',__DIR__.'/data');function tt_require_login(){return ['role'=>'Fixture'];}
function tt_user_can_open_module($u,$m){return true;}function tt_user_can_access_entity($u,$e,$a){return true;}
function tt_release_read_session(){}
""")
 (root/'runner.php').write_text("<?php $_GET=['entity'=>'TTI'];$_SERVER['REQUEST_METHOD']='GET';require __DIR__.'/api/shipment_profitability.php';")
 path=root/'data/accounts.json'
 def report(state):
  path.write_text(json.dumps(state));before=path.read_bytes()
  p=subprocess.run(['php',str(root/'runner.php')],capture_output=True,text=True,check=True)
  assert path.read_bytes()==before,'Report rewrote original source'
  d=json.loads(p.stdout);assert d['ok'],(d,p.stderr);return d
 def bill(amount=80,status='Outstanding',ids=None):
  return {'id':'B1','entity':'TTI','category':'TRANSPORT','status':status,'expenseAccount':'5510','supplierPayableTotal':amount,'postingJournalIds':ids or ['P1','P2'],'sourceRecord':{'lines':[{'contractRef':'C1','lotRef':'L1','amount':amount}]}}
 def post(id,amount,entity='TTI',status='Posted'):
  return {'id':id,'entity':entity,'status':status,'date':'2026-10-01','meta':{'supplierBillId':'B1'},'lines':[{'account':'5510','debit':max(0,amount),'credit':max(0,-amount)},{'account':'2130','debit':max(0,-amount),'credit':max(0,amount)}]}
 # A saved source row is not evidence that its liability has been posted.
 d=report({'supplierBills':{'B1':bill(500,'Draft',[])},'journals':{}})
 assert sum(x['transport'] for x in d['rows'])==0,('Unposted bill became posted transport cost',d)
 assert d['sourceHolds'] and all(x['grossContribution'] is None for x in d['rows']),d
 state={'supplierBills':{'B1':bill()},'journals':{'P1':post('P1',100),'P2':post('P2',-20)}}
 d=report(state);assert not d['sourceHolds'] and d['rows'][0]['transport']==80,d
 for mutation in ['missing','foreign','draft','mismatch','duplicate','wrong-owner']:
  changed=json.loads(json.dumps(state))
  if mutation=='missing':del changed['journals']['P2']
  if mutation=='foreign':changed['journals']['P2']['entity']='BRM'
  if mutation=='draft':changed['journals']['P2']['status']='Draft'
  if mutation=='mismatch':changed['supplierBills']['B1']['supplierPayableTotal']=99
  if mutation=='duplicate':changed['supplierBills']['B1']['postingJournalIds']=['P1','P1','P2']
  if mutation=='wrong-owner':changed['journals']['P2']['meta']['supplierBillId']='OTHER'
  d=report(changed);assert d['sourceHolds'] and sum(x['transport'] for x in d['rows'])==0,(mutation,d)
 # Valid postings cannot be turned into another amount by stale allocation rows.
 changed=json.loads(json.dumps(state));changed['supplierBills']['B1']['sourceRecord']['lines'][0]['amount']=500
 d=report(changed);assert d['sourceHolds'] and sum(x['transport'] for x in d['rows'])==0,d
 changed=json.loads(json.dumps(state));changed['supplierBills']['ALIAS']=changed['supplierBills']['B1'];d=report(changed);assert d['sourceHolds'] and sum(x['transport'] for x in d['rows'])==80,'Repeated bill cost counted twice'
 # An authorized fully reversed source stays excluded, just like other canonical reports.
 changed=json.loads(json.dumps(state));changed['supplierBills']['B1']['status']='Reversed';d=report(changed);assert not d['rows'] and not d['sourceHolds'],d
 # Input errors must not look like a successful empty report.
 path.write_text('{broken');p=subprocess.run(['php',str(root/'runner.php')],capture_output=True,text=True,check=True)
 assert not json.loads(p.stdout)['ok'],'Corrupt original books reported as empty success'
 print('PASS shipment posting evidence: draft/missing/foreign/unposted/duplicate/wrong-owner and amount/allocation holds; net amendment costs; immutable original bytes; corrupt source failure')
