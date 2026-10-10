"""General/local bag bills: real endpoint, supplier validation, tax and posting boundaries."""
import json,pathlib,shutil,socket,subprocess,tempfile,time,urllib.request,urllib.error
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='general-bags-') as tmp:
 root=pathlib.Path(tmp);(root/'api').mkdir();(root/'accounts').mkdir();(root/'data').mkdir()
 shutil.copy(ROOT/'api/bag_purchases.php',root/'api/bag_purchases.php');shutil.copy(ROOT/'accounts/accounting_master_v1.json',root/'accounts/accounting_master_v1.json')
 (root/'auth_store.php').write_text('''<?php
 define('TT_DATA_DIR',__DIR__.'/data');function tt_ensure_data_dir(){}function tt_accounts_input(){return file_get_contents('php://input');}
 function tt_require_login(){return ['username'=>'FIXTURE','permissions'=>['Accounts'=>($_SERVER['HTTP_X_FIXTURE_ROLE']??'')==='readonly'?['View']:['View','Edit']]];}
 function tt_user_can_open_module($u,$m){return $m==='Accounts';}function tt_verify_csrf($v){return $v==='fixture';}
 function tt_list_masters(){return ['business_parties'=>[['id'=>'BAG','values'=>['BAG SUPPLIER']],['id'=>'OTHER','values'=>['OTHER VENDOR']]]];}
 function tt_active_business_party_for_role($name,$role){return $name==='BAG SUPPLIER'&&$role==='Bag Supplier'?['id'=>'BAG','values'=>['BAG SUPPLIER']]:null;}
 function tt_next_post_id($a,$m,$area){return 'POST-'.(count($a)+1);}function tt_user_can_director_approve($u){return false;}
 function tt_api_record_entity_allowed($u,$b,$e){return ($b['entity']??$e)===$e;}
 ''')
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 log=open(root/'php.log','w');server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root)],stdout=log,stderr=log)
 def req(body=None,role=''):
  request=urllib.request.Request(f'http://127.0.0.1:{port}/api/bag_purchases.php?entity=TTI',data=json.dumps(body).encode() if body else None,headers={'Content-Type':'application/json','X-Fixture-Role':role})
  try:
   with urllib.request.urlopen(request,timeout=5) as r:return r.status,json.load(r)
  except urllib.error.HTTPError as e:return e.code,json.load(e)
 try:
  for _ in range(50):
   try:status,data=req();break
   except urllib.error.URLError:time.sleep(.1)
  assert status==200 and data['bagSuppliers']==[{'id':'BAG','name':'BAG SUPPLIER'}],data
  payload={'action':'create_general_bill','csrf':'fixture','entity':'TTI','supplier':'BAG SUPPLIER','sellerInvoice':'LOCAL-1','sellerInvoiceDate':'2026-10-10','bagType':'PP','packingSize':'50 KG','bags':100,'ratePerBag':20,'salesTaxInvoice':True,'stockTreatment':'STOCK'}
  assert req({**payload,'csrf':'bad'})[0]==419
  assert req(payload,'readonly')[0]==403
  assert req({**payload,'supplier':'OTHER VENDOR'})[0]==422
  assert req({**payload,'bags':1.5})[0]==422
  status,posted=req(payload);assert status==200,posted
  bill=posted['result'];assert bill['status']=='Posted' and bill['poNo']=='' and bill['gstAmount']==360 and bill['totalAmount']==2360
  stored=json.loads((root/'data/accounts.json').read_text());journal=stored['journals'][bill['journalId']];assert journal['totalDebit']==journal['totalCredit']==2360
  assert [(l['account'],l['debit'],l['credit']) for l in journal['lines']]==[('1340',2000,0),('1230',360,0),('2140',0,2360)]
  before=(root/'data/accounts.json').read_bytes();assert req(payload)[0]==409 and (root/'data/accounts.json').read_bytes()==before
  status,posted=req({**payload,'sellerInvoice':'LOCAL-2','salesTaxInvoice':False,'stockTreatment':'CONSUMED'});assert status==200,posted
  stored=json.loads((root/'data/accounts.json').read_text());journal=stored['journals'][posted['result']['journalId']];assert [(l['account'],l['debit'],l['credit']) for l in journal['lines']]==[('5550',2000,0),('2140',0,2000)]
  data=req()[1];assert data['totals']['inputBags']==data['totals']['gstAmount']==0 and data['generalTotals']['stockBags']==data['generalTotals']['consumedBags']==100 and len(data['generalStock'])==1
  print('General bag bills: supplier-only selection, stock/consumption journals, GST, duplicate/read-only/CSRF and separate export report passed')
 finally:server.terminate();server.wait(timeout=5);log.close()
