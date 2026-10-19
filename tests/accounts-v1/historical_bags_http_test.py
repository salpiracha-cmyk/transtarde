"""Real historical invoices, opening allocation, payments and service-ledger routes; disposable books."""
import json,pathlib,shutil,socket,subprocess,tempfile,time,urllib.request,urllib.error
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='historical-bags-') as tmp:
 root=pathlib.Path(tmp);shutil.copytree(ROOT/'api',root/'api');shutil.copytree(ROOT/'accounts',root/'accounts');(root/'data').mkdir()
 (root/'auth_store.php').write_text('''<?php
 define('TT_DATA_DIR',__DIR__.'/data');function tt_ensure_data_dir(){}function tt_accounts_input(){return file_get_contents('php://input');}
 function tt_require_login(){return ['id'=>1,'username'=>'Fixture','role'=>empty($_SERVER['HTTP_X_READONLY'])?'Super Admin':'Staff','permissions'=>['Accounts'=>empty($_SERVER['HTTP_X_READONLY'])?'all':['View']]];}
 function tt_post_correction_allowed($u,$j){return empty($_SERVER['HTTP_X_READONLY'])&&($j['entity']??'')==='TTI';}function tt_user_can_open_module($u,$m){return $m==='Accounts';}function tt_user_can_access_entity($u,$e,$a){return $e==='TTI'&&($a==='View'||empty($_SERVER['HTTP_X_READONLY']));}function tt_user_can_module_action($u,$m,$i,$a){return $a==='View'||empty($_SERVER['HTTP_X_READONLY']);}function tt_user_accounts_entities($u){return ['TTI'];}
 function tt_verify_csrf($v){return $v==='fixture';}function tt_bank_can_transact($id){return true;}function tt_user_can_director_approve($u){return false;}function tt_api_record_entity_allowed($u,$b,$e){return $e==='TTI';}
 function tt_business_party_categories($v){return explode('|',$v);}function tt_active_business_party_for_role($n,$r){return $n==='BAG SUPPLIER'&&$r==='Bag Supplier'?['id'=>'BAG','values'=>[$n]]:null;}
 function tt_list_masters(){return ['business_parties'=>[['id'=>'BAG','values'=>['BAG SUPPLIER','BAG','Bag Supplier']],['id'=>'M','values'=>['MAPCO','M','Clearing Agent']],['id'=>'D','values'=>['DITTA','D','Transporter']],['id'=>'P','values'=>['PAKLINK','P','Freight Forwarder']]],'banks'=>[['id'=>'BANK','values'=>['Company Account','TTI','','Company','Fixture Bank','','','PKR','111','','','','','Active']]]];}
 function tt_next_post_id($rows,$module='Accounts',$area='Journal',$date=null){$n=count($rows)+1;do{$id='POST-2026-'.str_pad((string)$n++,5,'0',STR_PAD_LEFT);}while(isset($rows[$id]));return $id;}
 ''')
 books=root/'data/accounts.json';books.write_text(json.dumps({'revision':0,'journals':{}}));(root/'data/operations.json').write_text('{}')
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 log=open(root/'php.log','w');server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root)],stdout=log,stderr=log)
 def req(path='bag_purchases.php?entity=TTI',body=None,readonly=False):
  try:
   with urllib.request.urlopen(urllib.request.Request(f'http://127.0.0.1:{port}/api/'+path,data=json.dumps(body).encode() if body is not None else None,headers={'Content-Type':'application/json',**({'X-Readonly':'1'} if readonly else {})}),timeout=10) as r:return r.status,json.load(r)
  except urllib.error.HTTPError as e:return e.code,json.load(e)
 def ok(body,path='bag_purchases.php?entity=TTI'):
  status,d=req(path,body);assert status==200,(status,d,log.read() if False else '');return d
 def state():return json.loads(books.read_text())
 payload={'action':'create_historical_bill','requestKey':'historic-fixture-1','csrf':'fixture','entity':'TTI','supplier':'BAG SUPPLIER','sellerInvoice':'FEB-01','sellerInvoiceDate':'2026-02-15','bagType':'PP','packingSize':'50 KG','brand':'FIXTURE','bags':100,'ratePerBag':20,'salesTaxInvoice':True,'gstRate':18,'gstAmount':360,'openingPayableAmount':2360,'openingPayableMode':'NEW','openingGstRemaining':360,'openingStockBags':0,'openingGstMode':'NEW','openingStockMode':'NEW','stockTreatment':'CONSUMED'}
 try:
  for _ in range(60):
   try:assert req()[0]==200;break
   except urllib.error.URLError:time.sleep(.05)
  before=books.read_bytes();assert req(body=payload,readonly=True)[0]==403 and books.read_bytes()==before
  assert req(body={**payload,'csrf':'bad'})[0]==419 and books.read_bytes()==before
  for change in [{'sellerInvoiceDate':'2026-10-01'},{'sellerInvoiceDate':'2026-02-30'},{'openingGstRemaining':361},{'bags':1.001},{'openingPayableAmount':2361},{'openingStockBags':101},{'openingStockBags':1},{'openingStockBags':-1},{'stockTreatment':'STOCK'},{'sellerInvoiceDate':'2026-08-01','stockTreatment':'STOCK'},{'sellerInvoiceDate':'2026-08-01','openingStockBags':1},{'entity':'BRM'}]:
   assert req(body={**payload,**change})[0] in [403,422] and books.read_bytes()==before,change
  result=ok(payload);b=result['result'];bid=b['id'];j=state()['journals'][b['journalId']];assert j['date']=='2026-07-01' and b['sellerInvoiceDate']=='2026-02-15';assert j['totalDebit']==j['totalCredit']==2360
  assert [(l['account'],l['debit'],l['credit']) for l in j['lines']]==[('2140',0,2360),('1230',360,0),('3400',2000,0)];assert not any(l['account']=='5550' for l in j['lines'])
  saved=books.read_bytes();assert ok(payload)['result']['id']==bid and books.read_bytes()==saved
  assert req(body={**payload,'requestKey':'different-draft'})[0]==422 and books.read_bytes()==saved
  view=req()[1];assert view['historicalTotals']['invoiceGst']==view['historicalTotals']['newOpeningGst']==360
  status,payables=req('supplier_settlements.php?entity=TTI');assert status==200,payables;assert len(payables['payables'])==1 and payables['payables'][0]['outstanding']==2360 and not payables['payables'][0].get('openingBalance')
  payment={'action':'post_bill_payment','csrf':'fixture','entity':'TTI','date':'2026-09-15','requestKey':'historical-payment-001','supplier':'BAG SUPPLIER','category':'BAGS','selectedBills':[bid+'|BAGS|'+bid],'explicitBillSelection':True,'amount':2360,'paymentMode':'BANK','bankAccountId':'BANK','bankPaymentMethod':'ONLINE_BANKING','bankPaymentDate':'2026-09-15','reference':'SEPT-PAYMENT','narration':'Cleared February bill'}
  assert req('supplier_settlements.php?entity=TTI',{**payment,'date':'2026-06-30'})[0]==422
  paid=ok(payment,'supplier_settlements.php?entity=TTI');pj=state()['journals'][paid['result']['journalId']];assert pj['date']=='2026-09-15' and pj['totalDebit']==pj['totalCredit']==2360
  assert req('supplier_settlements.php?entity=TTI')[1]['payables']==[]
  history=req()[1]['bills'][0];assert history['paymentStatus']=='Paid' and history['outstanding']==0 and history['paidAmount']==2360
  assert req(body={'action':'cancel_historical_bill','entity':'TTI','csrf':'fixture','billId':bid,'reason':'Correct invoice date'})[0]==422
  current=ok({**payload,'requestKey':'historic-july-1','sellerInvoice':'JULY-01','sellerInvoiceDate':'2026-07-15'});cj=state()['journals'][current['result']['journalId']];assert cj['date']=='2026-07-15' and cj['lines'][0]['account']=='5550'
  amended=ok({**payload,'requestKey':'historic-amend-1','sellerInvoice':'JULY-01','sellerInvoiceDate':'2026-08-15','amendBillId':current['result']['id'],'amendReason':'Correct original invoice date'});assert state()['journals'][amended['result']['journalId']]['date']=='2026-08-15' and state()['bagSupplierBills'][current['result']['id']]['status']=='Cancelled'
  assert ok({'action':'cancel_historical_bill','entity':'TTI','csrf':'fixture','billId':amended['result']['id'],'reason':'Remove duplicate source'})['result']['journalId']
  before=books.read_bytes();assert req(body={**payload,'requestKey':'historic-stock-1','sellerInvoice':'APRIL-STOCK','sellerInvoiceDate':'2026-04-10','openingPayableAmount':0,'openingGstRemaining':0,'openingStockBags':20})[0]==422 and books.read_bytes()==before
  generic=ok({**payload,'requestKey':'historic-generic-1','sellerInvoice':'SEPT-DELETE','sellerInvoiceDate':'2026-09-10'});gid=generic['result']['journalId']
  status,d=req('accounts_post_amend.php',{'csrf':'fixture','postId':gid,'action':'delete_post','reason':'Correct historical invoice'});assert status==200,d;assert state()['bagSupplierBills'][generic['result']['id']]['status']=='Cancelled'
  status,d=req('accounts_ledger_browser.php?entity=TTI&account=POSTS&from=2026-07-01&to=2026-10-14');assert status==200,d;assert not any(r['voucher']==gid for r in d['rows'])
  # Link invoice detail to existing creditor/GST openings while leaving independent physical stock untouched without a second opening journal.
  reset={'revision':0,'journals':{'BF':{'id':'BF','entity':'TTI','status':'Posted','date':'2026-07-01','sourceType':'OPENING_BALANCE_BF','lines':[{'account':'2140','supplier':'BAG SUPPLIER','credit':2360,'debit':0},{'account':'1230','debit':360,'credit':0},{'account':'1340','debit':400,'credit':0},{'account':'3400','debit':1600,'credit':0}],'totalDebit':2360,'totalCredit':2360}}};books.write_text(json.dumps(reset))
  linked=ok({**payload,'requestKey':'historic-link-1','openingPayableMode':'LINK','openingJournalId':'BF','openingLineIndex':0,'openingGstMode':'INCLUDED'});assert len(state()['journals'])==1 and linked['result']['journalId'] is None and state()['journals']['BF']==reset['journals']['BF'];assert linked['result']['openingStockBags']==0
  assert len(req('supplier_settlements.php?entity=TTI')[1]['payables'])==1
  before=books.read_bytes();assert req(body={**payload,'requestKey':'historic-link-over','sellerInvoice':'FEB-02','openingPayableMode':'LINK','openingJournalId':'BF','openingLineIndex':0})[0]==422 and books.read_bytes()==before
  # Same consumed invoice billed per kg or per bag; no stock/purchase expense at opening.
  books.write_text(json.dumps({'revision':0,'journals':{}}))
  kg={**payload,'requestKey':'historical-kg-bill','sellerInvoice':'KG-225','bags':3000,'billingBasis':'KG','billingRate':400,'bagWeightGrams':75,'ratePerBag':999999,'gstAmount':16200,'openingPayableAmount':106200,'openingGstRemaining':16200,'openingStockBags':0}
  kb=ok(kg)['result'];assert kb['baseAmount']==90000 and kb['totalWeightKg']==225 and kb['ratePerBag']==30 and kb['totalAmount']==106200 and kb['billingBasis']=='KG'
  kj=state()['journals'][kb['journalId']];assert not any(l['account'] in ['1340','5550'] for l in kj['lines']) and kj['date']=='2026-07-01'
  before=books.read_bytes();assert ok(kg)['result']['id']==kb['id'] and books.read_bytes()==before
  for index,change in enumerate([{'bagWeightGrams':0},{'billingRate':-1},{'billingBasis':'TONNE'}]):
   assert req(body={**kg,**change,'requestKey':'historical-bad-kg-'+str(index)})[0]==422 and books.read_bytes()==before
  books.write_text(json.dumps({'revision':0,'journals':{}}))
  perbag=ok({**kg,'requestKey':'historical-per-bag','sellerInvoice':'BAG-3000','billingBasis':'BAG','billingRate':30})['result'];assert perbag['baseAmount']==kb['baseAmount'] and perbag['gstAmount']==kb['gstAmount'] and perbag['totalAmount']==kb['totalAmount']
  # All shared-control service groups reconcile back to the same original monetary lines.
  reset={'revision':0,'journals':{},'supplierBills':{}}
  for i,(party,kind,expense) in enumerate([('MAPCO','CLEARING','5530'),('DITTA','TRANSPORT','5510'),('PAKLINK','FREIGHT','5520')]):
   jid='S'+str(i);reset['supplierBills'][jid]={'id':jid,'entity':'TTI','category':kind,'vendor':party,'billNo':jid,'expenseAccount':expense};reset['journals'][jid]={'id':jid,'entity':'TTI','date':'2026-10-02','status':'Posted','sourceType':'SUPPLIER_BILL','meta':{'supplierBillId':jid,'kind':kind},'reference':jid,'narration':party,'totalDebit':100,'totalCredit':100,'lines':[{'account':expense,'debit':100,'credit':0},{'account':'2130','debit':0,'credit':100,'supplier':party}]}
  books.write_text(json.dumps(reset));total=0
  for party,kind in [('MAPCO','CLEARING'),('DITTA','TRANSPORT'),('PAKLINK','FREIGHT')]:
   status,d=req('accounts_ledger_browser.php?entity=TTI&account=SERVICE%7C'+kind+'&from=2026-07-01&to=2026-10-14');assert status==200,d;assert len(d['rows'])==1 and d['rows'][0]['party']==party and d['closing']==-100;total+=d['closing']
  d=req('accounts_ledger_browser.php?entity=TTI&account=2130&from=2026-07-01&to=2026-10-14')[1];assert d['closing']==total==-300 and d['serviceClassificationReviews']==[]
  # Cutoff enforced even if a caller uses the ordinary linked route with an unlinked PO.
  reset['bagPurchaseOrders']={'PO':{'poNo':'PO','entity':'TTI','supplier':'BAG SUPPLIER','lines':[{'lineKey':'L1','bagType':'PP','packingSize':'50 KG','brand':'FIXTURE','orderedQty':100,'receivedQty':100,'ratePerBag':20}]}};books.write_text(json.dumps(reset))
  ordinary={**payload,'action':'create_bill','poNo':'PO','lineKey':'L1','sellerInvoice':'OCT-01','sellerInvoiceDate':'2026-10-01'};before=books.read_bytes();assert req(body=ordinary)[0]==422 and books.read_bytes()==before
  reset['bagPurchaseOrders']['PO']['shipmentRef']='SHIPMENT-1';books.write_text(json.dumps(reset));assert ok(ordinary)['result']['status']=='Posted'
  # PO checks compare the equivalent per-bag rate while preserving invoice kg units.
  reset['bagPurchaseOrders']['PO']['lines'][0].update({'orderedQty':3000,'receivedQty':3000,'ratePerBag':30});books.write_text(json.dumps(reset))
  pk=ok({**ordinary,'sellerInvoice':'OCT-KG','bags':3000,'billingBasis':'KG','billingRate':400,'bagWeightGrams':75,'ratePerBag':999999})['result'];assert pk['status']=='Posted' and pk['baseAmount']==90000 and pk['ratePerBag']==30 and pk['totalAmount']==106200
  # Saved GST changes continue from their effective date; posted invoices never recalculate.
  old_bill=state()['bagSupplierBills'][pk['id']].copy()
  ok({'action':'save_tax_rate','csrf':'fixture','entity':'TTI','effectiveDate':'2026-10-01','rate':19,'note':'Owner rate change'})
  general=ok({'action':'create_general_bill','csrf':'fixture','entity':'TTI','supplier':'BAG SUPPLIER','sellerInvoice':'RATE-19','sellerInvoiceDate':'2026-10-13','bagType':'PP','packingSize':'50 KG','bags':100,'ratePerBag':20,'stockTreatment':'CONSUMED','salesTaxInvoice':True})['result']
  assert general['gstRate']==19 and general['totalAmount']==2380 and state()['bagSupplierBills'][pk['id']]==old_bill
  before=books.read_bytes()
  assert req(body={'action':'save_tax_rate','csrf':'fixture','entity':'TTI','effectiveDate':'2026-10-01','rate':'invalid'})[0]==422 and books.read_bytes()==before
  print('PASS historical bag HTTP: calendar cutoff, original invoice GST, July openings, linked capacities, September payment, no duplicate B/F, replay, amendments, cancellations and reconciled service groups')
 finally:server.terminate();server.wait(timeout=5);log.close()
