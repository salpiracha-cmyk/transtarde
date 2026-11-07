"""TG carry-forward buyer invoices remain payable without a current operational contract."""
import json,pathlib,shutil,socket,subprocess,tempfile,time,urllib.request,urllib.error
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tg-customer-receipts-') as tmp:
 root=pathlib.Path(tmp);shutil.copytree(ROOT/'api',root/'api');shutil.copytree(ROOT/'accounts',root/'accounts');(root/'data').mkdir()
 (root/'auth_store.php').write_text('''<?php
 define('TT_DATA_DIR',__DIR__.'/data');foreach(['HOST','NAME','USER','PASS'] as $key)define('TT_DB_'.$key,'');
 function tt_ensure_data_dir(){}function tt_require_login(){return ['id'=>1,'role'=>'Super Admin','username'=>'fixture','permissions'=>['Accounts'=>'all']];}
 function tt_user_can_open_module($u,$m){return true;}function tt_user_can_access_entity($u,$e,$a){return $e==='TG';}function tt_verify_csrf($s){return $s==='fixture';}
 function tt_bank_can_transact($id){return $id==='TGUSD';}function tt_company_fx_rate($e,$from,$to){return $from==='USD'&&$to==='AED'?3.67:null;}function tt_company_vat_rate($e,$default=5){return 7.5;}
 function tt_master_options(){return ['currencies'=>['USD','AED','PKR']];}function tt_read_store(){return ['masters'=>tt_list_masters()];}
 function tt_list_masters(){return ['banks'=>[['id'=>'TGUSD','values'=>['Company Account','TG','','TG USD','TG BANK','','','USD','123','','','','','Active']]]];}
 function tt_next_post_id(array $rows,string $module='Accounts',string $area='Journal',?string $date=null):string{$n=count($rows)+1;do{$id='POST-2026-'.str_pad((string)$n++,5,'0',STR_PAD_LEFT);}while(isset($rows[$id]));return $id;}
 ''')
 (root/'data/operations.json').write_text(json.dumps({'values':{'transtrade_export_v3_operational':json.dumps({'contracts':[],'customers':[],'shipments':[]})}}))
 books={'revision':1,'journals':{},'exportCandidates':{},'tgBankTransactions':{}}
 for n,(customer,ref,amount) in enumerate([('AMT ENTERPRISE','TG/AMT/12',108111),('EASTBOURNE MARITIME TRADING LTD.','TG/EMT/17',120528),('GLOBAL TRANSIT','TG/GT/24',174346)]):
  cid='CF-'+str(n)+'|BUYER';jid='OPEN-'+str(n)
  books['exportCandidates'][cid]={'id':cid,'entity':'TG','candidateType':'CUSTOMER_EXPORT_SALE','reference':ref,'counterparty':customer,'transactionCurrency':'USD','transactionAmount':amount,'functionalRate':3.67 if n<2 else 0,'currentCarryingRate':3.67 if n<2 else 0,'journalId':jid if n<2 else '', 'pendingValuation':n==2,'meta':{'contractRef':ref,'commercialInvoiceNo':ref,'commercialInvoiceDate':'2026-06-15','customer':customer,'carryForwardShipmentId':'CF-'+str(n)}}
  if n<2:books['journals'][jid]={'id':jid,'entity':'TG','date':'2026-07-01','sourceType':'CARRY_FORWARD_OPENING','status':'Posted','lines':[]}
 books['exportCandidates']['TTI-ONLY']={**books['exportCandidates']['CF-0|BUYER'],'id':'TTI-ONLY','entity':'TTI','counterparty':'PRIVATE TTI CUSTOMER'}
 path=root/'data/accounts.json';path.write_text(json.dumps(books));before=path.read_bytes()
 with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
 log=open(root/'server.log','w');server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root)],stdout=log,stderr=log)
 def req(body=None):
  r=urllib.request.Request(f'http://127.0.0.1:{port}/api/tg_bank_transactions.php?date=2026-10-02',data=json.dumps({'csrf':'fixture',**body}).encode() if body else None,headers={'Content-Type':'application/json'})
  try:
   with urllib.request.urlopen(r,timeout=5) as x:return x.status,json.load(x)
  except urllib.error.HTTPError as e:return e.code,json.load(e)
 try:
  for _ in range(50):
   try:status,data=req();break
   except urllib.error.URLError:time.sleep(.05)
  assert status==200,(data,(root/'server.log').read_text())
  assert {x['customer'] for x in data['contracts']}=={'AMT ENTERPRISE','EASTBOURNE MARITIME TRADING LTD.','GLOBAL TRANSIT'}
  assert len(data['invoices'])==len(data['openExportReceivables'])==3 and all(x['recognized'] for x in data['invoices'])
  assert path.read_bytes()==before,'Listing registered invoices must not write accounts'
  for invoice in data['invoices']:
   assert invoice['outstandingNative']>0
   body={'action':'post_receipt','guidedReceipt':True,'date':'2026-10-02','receiptType':'EXPORT_RECEIVABLE','sourceCandidateId':invoice['id'],'contractRef':invoice['contractRef'],'invoiceRef':invoice['reference'],'counterparty':invoice['customer'],'bankAccountId':'TGUSD','settlementAmountNative':100,'bankChargeNative':10,'bankVatPct':99,'differenceChoice':'LESS_ADVANCE'}
   saved=path.read_bytes();status,bad=req({**body,'settlementAmountNative':invoice['outstandingNative']+1});assert status==422 and path.read_bytes()==saved
   status,posted=req(body);assert status==200,(posted,(root/'server.log').read_text())
   assert posted['transaction']['receiptType']=='EXPORT_RECEIVABLE' and posted['transaction']['sourceCandidateId']==invoice['id']
   assert posted['transaction']['bankVatNative']==.75 and posted['transaction']['rate']==3.67
   assert next(x for x in posted['invoices'] if x['id']==invoice['id'])['outstandingNative']==invoice['outstandingNative']-100
   assert posted['journal']['totalDebit']==posted['journal']['totalCredit']
  invoice=posted['invoices'][0];remaining=invoice['outstandingNative']
  full={'action':'post_receipt','guidedReceipt':True,'date':'2026-10-02','receiptType':'EXPORT_RECEIVABLE','sourceCandidateId':invoice['id'],'contractRef':invoice['contractRef'],'invoiceRef':invoice['reference'],'counterparty':invoice['customer'],'bankAccountId':'TGUSD','settlementAmountNative':remaining,'bankCreditNative':remaining-25,'bankChargeNative':10,'differenceChoice':'CORRESPONDENT_BANK_CHARGES'}
  snapshot=path.read_bytes();status,rejected=req({**full,'differenceChoice':'LESS_ADVANCE'});assert status==422 and path.read_bytes()==snapshot
  status,cleared=req(full);assert status==200,(status,cleared)
  assert cleared['transaction']['correspondentChargeNative']==25 and cleared['transaction']['bankCreditNative']==remaining-25
  assert next(i for i in cleared['invoices'] if i['id']==invoice['id'])['outstandingNative']==0
  fee=next(l for l in cleared['journal']['lines'] if l['account']=='6810');assert fee['chargeNative']==25
  assert cleared['journal']['totalDebit']==cleared['journal']['totalCredit']
  assert sum(l.get('bankCredit',0) for l in cleared['journal']['lines'] if l['account']=='1110')==10.75,'Correspondent fee must not debit the bank twice'
  after=json.loads(path.read_text());assert len(after['tgBankTransactions'])==4
  assert after['exportCandidates']['CF-2|BUYER']['pendingValuation'] is False,'Registered native balance is valued once on actual settlement'
  assert sum(j['sourceType']=='CARRY_FORWARD_OPENING' for j in after['journals'].values())==3,'No duplicate opening invoice posting'
  print('PASS TG registered/carry-forward customer selection, missing operational contracts, native partial receipts, master VAT/rate, overpayment rollback and company isolation')
 finally:server.terminate();server.wait();log.close()
