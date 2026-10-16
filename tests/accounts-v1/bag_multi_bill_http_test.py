"""Exercise cumulative bag invoices and short PO closure through real locked endpoints."""
import json,pathlib,shutil,socket,subprocess,tempfile,time,urllib.request,urllib.error
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='bag-multi-bill-') as tmp:
 root=pathlib.Path(tmp)
 for d in ['api','accounts','data']:(root/d).mkdir()
 for n in ['bag_purchases.php','nonwoven_bag_bills.php','bag_acceptance_core.php','opening_balance_core.php','historical_bag_core.php','supplier_opening_core.php','accounts_posting_preview.php']:shutil.copy(ROOT/'api'/n,root/'api'/n)
 shutil.copy(ROOT/'accounts/accounting_master_v1.json',root/'accounts/accounting_master_v1.json')
 (root/'auth_store.php').write_text('''<?php
 define('TT_DATA_DIR',__DIR__.'/data');function tt_ensure_data_dir(){}function tt_accounts_input(){return file_get_contents('php://input');}
 function tt_require_login(){return ['id'=>7,'username'=>'fixture','full_name'=>($_SERVER['HTTP_X_ROLE']??'Staff'),'role'=>($_SERVER['HTTP_X_ROLE']??'Staff'),'permissions'=>['Accounts'=>'all']];}
 function tt_user_can_open_module($u,$m){return true;}function tt_verify_csrf($v){return $v==='fixture';}
 function tt_list_masters(){return ['business_parties'=>[['id'=>'BAG','values'=>['BAG SUPPLIER']]]];}
 function tt_active_business_party_for_role($n,$r){return $n==='BAG SUPPLIER'?['id'=>'BAG','values'=>[$n]]:null;}
 function tt_next_post_id($a,$m,$area){return 'POST-'.(count($a)+1);}function tt_user_can_director_approve($u){return in_array($u['role'],['Director','Super Admin'],true);}
 function tt_api_record_entity_allowed($u,$b,$e){return $e==='TTI'&&($b['entity']??$e)===$e;}
 ''')
 store={'revision':0,'journals':{},'bagPurchaseOrders':{'PO1':{'poNo':'PO1','shipmentRef':'EXPORT-1','entity':'TTI','supplier':'BAG SUPPLIER','lines':[{'lineKey':'L1','bagType':'Non Woven','packingSize':'50kg','brand':'TEST','orderedQty':3000,'receivedQty':2985,'agreedTareGrams':75}]}}}
 datafile=root/'data/accounts.json';datafile.write_text(json.dumps(store))
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 def req(body=None,endpoint='bag_purchases',role='Staff'):
  r=urllib.request.Request(f'http://127.0.0.1:{port}/api/{endpoint}.php?entity=TTI',data=json.dumps({'csrf':'fixture','entity':'TTI',**body}).encode() if body else None,headers={'Content-Type':'application/json','X-Role':role})
  try:
   with urllib.request.urlopen(r,timeout=5) as response:return response.status,json.load(response)
  except urllib.error.HTTPError as e:return e.code,json.load(e)
 def bill(qty,invoice,endpoint='bag_purchases'):
  status,result=req({'action':'create_bill','poNo':'PO1','lineKey':'L1','bags':qty,'ratePerBag':10,'sellerInvoice':invoice,'sellerInvoiceDate':'2026-10-16'},endpoint)
  assert status==200,result
  return result['result']
 try:
  for _ in range(50):
   try:status,overview=req();break
   except urllib.error.URLError:time.sleep(.1)
  assert status==200 and overview['purchaseOrders'][0]['receiptClosure']=={'closed':True,'shortBags':15,'reason':'Closed short — within 20 bags'},overview
  for route in ['bag_purchases','nonwoven_bag_bills']:
   for qty in [1.5,0,-1,'NaN']:
    assert req({'action':'create_bill','poNo':'PO1','lineKey':'L1','bags':qty,'ratePerBag':10,'sellerInvoice':'INVALID','sellerInvoiceDate':'2026-10-16'},route)[0]==422
  a=bill(1000,'ONE');b=bill(1000,'TWO','nonwoven_bag_bills');c=bill(985,'THREE')
  assert all(x['status']=='Posted' for x in [a,b,c]),[a,b,c]
  before=json.loads(datafile.read_text());assert len(before['journals'])==3
  held=bill(25,'LAST-OVER-PO')
  assert held['status']=='Hold - Director Approval Required' and not held['journalId'],held
  assert any('3010 exceed PO quantity 3000' in x for x in held['acceptanceIssues']),held
  assert any('actual bags received 2985' in x for x in held['acceptanceIssues']),held
  assert len(json.loads(datafile.read_text())['journals'])==3,'Pending quantity exception must not create a journal'
  status,result=req({'action':'try_post_bill','billId':held['id']});assert status==409,result
  approval={'action':'approve_rate_exception','billId':held['id'],'reason':'Supplier confirmed extra bags; accepted excess'}
  assert req(approval)[0]==403,'Accounts cannot approve its exception'
  assert req(approval,role='Director')[0]==422,'Supplier settlement required first'
  assert req({'action':'settle_bag_discrepancy','billId':held['id'],'reason':'Called supplier; confirmed extra invoice bags'})[0]==200
  current=next(x for x in req()[1]['bills'] if x['id']==held['id']);approval['expectedAcceptanceFingerprint']=current['acceptanceFingerprint']
  assert req({**approval,'expectedAcceptanceFingerprint':'outdated'},role='Director')[0]==422,'Stale decisions cannot release a changed invoice'
  assert req({**approval,'entity':'BRM'},role='Director')[0]==403,'Approval cannot cross company books'
  status,approved=req(approval,role='Director');assert status==200 and approved['result']['status']=='Posted',approved
  jid=approved['result']['journalId'];assert req(approval,role='Super Admin')[1]['result']['journalId']==jid,'Approval replay cannot post twice'
  saved=json.loads(datafile.read_text());assert len(saved['journals'])==4 and saved['bagPurchaseOrders']['PO1']['lines'][0]['receivedQty']==2985
  assert saved['bagSupplierBills'][held['id']]['acceptanceApprovedBy']=='Director'
  assert saved['bagAcceptanceAudit'][-1]['issues'],'Approval retains the exact excess evidence'
  # A late genuine invoice may use an administratively closed PO, without fabricated receipts.
  datafile.write_text(json.dumps(before))
  ops={'values':{'tt30bags':json.dumps([{'id':11,'_ttBridge':'exports','_ttBridgeId':'PO1|1|CONTRACT|BRAND|50|75','poNo':'PO1','ordered':3000,'received':3000,'tare':75,'keepReceiptOpen':False}]),'tt33bagreceipts':json.dumps([{'id':1,'bagId':11,'qty':3000,'actualTareGrams':75}])}}
  (root/'data/operations.json').write_text(json.dumps(ops))
  late=bill(15,'LATE-15');assert late['status']=='Posted',late
  assert req()[1]['purchaseOrders'][0]['receiptClosure']['reason']=='Fully received'
  # Keep-open is a user choice, independently read from the committed Mill state.
  ops['values']['tt30bags']=json.dumps([{'id':11,'_ttBridge':'exports','_ttBridgeId':'PO1|1|CONTRACT|BRAND|50|75','poNo':'PO1','ordered':3000,'received':2985,'tare':75,'keepReceiptOpen':True}])
  (root/'data/operations.json').write_text(json.dumps(ops));assert not req()[1]['purchaseOrders'][0]['receiptClosure']['closed']
  # Bad evidence cannot be treated as a clean receipt or overwrite the Accounts file.
  (root/'data/operations.json').write_text('{broken');snapshot=datafile.read_bytes()
  assert req({'action':'create_bill','poNo':'PO1','lineKey':'L1','bags':1,'ratePerBag':10,'sellerInvoice':'BROKEN','sellerInvoiceDate':'2026-10-16'})[0]==500
  assert snapshot==datafile.read_bytes()
  print('PASS multi-route bills, cumulative excess hold, Director-only approval, replay, no invented receipts, short closure, keep-open, late bill and committed Mill evidence')
 finally:server.terminate();server.wait(timeout=5)
