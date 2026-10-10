"""Supplier creditor openings settle with bills, without creating another expense."""
import json,pathlib,shutil,socket,subprocess,tempfile,time,urllib.request,urllib.error,os
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='supplier-opening-') as tmp:
 root=pathlib.Path(tmp)
 for d in ['api','accounts','data']:(root/d).mkdir()
 for name in ['supplier_settlements.php','supplier_opening_core.php','accounts_bank_payment.php']:
  shutil.copy(ROOT/'api'/name,root/'api'/name)
 for name in ['accounting_master_v1.json','settlement_policy_v1.json']:
  shutil.copy(ROOT/'accounts'/name,root/'accounts'/name)
 (root/'auth_store.php').write_text('''<?php
 define('TT_DATA_DIR',__DIR__.'/data');function tt_ensure_data_dir(){}function tt_accounts_input(){return file_get_contents('php://input');}
 function tt_require_login(){return ['role'=>($_SERVER['HTTP_X_ROLE']??'')==='viewer'?'Viewer':'Super Admin','id'=>1,'username'=>'FIXTURE','permissions'=>['Accounts'=>'all']];}
 function tt_user_can_open_module($u,$m){return true;}function tt_user_can_access_entity($u,$e,$action){return $e==='TTI'&&($u['role']==='Super Admin'||$action==='View');}
 function tt_verify_csrf($v){return $v==='fixture';}function tt_next_post_id($a,$m,$area){return 'POST-'.(count($a)+1);}
 ''')
 def opening(id='OPEN',entity='TTI',credit=308345,account='2130',party='MAPCO ENTERPRISES',**extra):
  return {'id':id,'entity':entity,'status':'Posted','sourceType':'OPENING_BALANCE_BF','date':'2026-07-01','lines':[{'account':account,'subledger':party,'debit':0,'credit':credit},{'account':'3400','debit':credit,'credit':0}],**extra}
 bill={'id':'BILL','entity':'TTI','category':'CLEARING','vendor':'MAPCO ENTERPRISES','broker':'MAPCO ENTERPRISES','billNo':'4233/4232/4236','billDate':'2026-07-11','payableAccount':'2130','supplierPayableTotal':382155,'receiptAllocations':[{'sourceKey':'SERVICE|BILL','supplierPayableShare':382155}]}
 initial={'journals':{'OPEN':opening(),'BRM':opening('BRM','BRM'),'DEBIT':opening('DEBIT',credit=-20),'REVERSED':opening('REVERSED',openingReversalId='REV'),'OTHER':opening('OTHER',credit=100,account='2140',party='LOCAL SUPPLIER')},'supplierBills':{'BILL':bill}}
 books=root/'data/accounts.json';books.write_text(json.dumps(initial))
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 def req(body=None,role='admin'):
  request=urllib.request.Request(f'http://127.0.0.1:{port}/api/supplier_settlements.php?entity=TTI',data=None if body is None else json.dumps({'csrf':'fixture','entity':'TTI',**body}).encode(),headers={'Content-Type':'application/json','X-Role':role})
  try:
   with urllib.request.urlopen(request,timeout=5) as response:return response.status,json.load(response)
  except urllib.error.HTTPError as error:return error.code,json.load(error)
 payment={'action':'post_bill_payment','requestKey':'mapco-payment-fixture-1','supplier':'MAPCO ENTERPRISES','category':'CLEARING','selectedBills':['BILL|SERVICE|BILL','BF:OPEN:0|OPENING|0'],'amount':690500,'paymentMode':'CASH','date':'2026-07-21','includeOpeningBalance':True}
 try:
  for _ in range(50):
   try:status,data=req();break
   except urllib.error.URLError:time.sleep(.1)
  assert status==200,data
  assert {r['billId'] for r in data['payables']}=={'BILL','BF:OPEN:0','BF:OTHER:0'},data
  before=books.read_bytes()
  assert req({**payment,'includeOpeningBalance':False})[0]==409
  assert req({**payment,'date':'2026-06-30'})[0]==422
  assert req(payment,'viewer')[0]==403
  assert books.read_bytes()==before,'Rejected payments must not change any opening or bill'
  status,posted=req(payment);assert status==200,posted
  journal=json.loads(books.read_text())['journals'][posted['result']['journalId']]
  assert [(l['account'],l['debit'],l['credit']) for l in journal['lines']]==[('2130',690500,0),('1120',0,690500)],journal
  assert journal['totalDebit']==journal['totalCredit']==690500
  assert sum(a['amount'] for a in posted['result']['allocations'])==690500
  assert [a['billNo'] for a in posted['result']['allocations']]==['Balance B/F','4233/4232/4236']
  assert req(payment)[1]['result']['journalId']==journal['id'],'Retry must reuse the same payment'
  assert all(r['supplier']!='MAPCO ENTERPRISES' for r in req()[1]['payables'])
  # Simulate source cancellation status, which the authoritative reversal workflow records.
  state=json.loads(books.read_text());state['supplierSettlements'][posted['result']['id']]['status']='Cancelled';books.write_text(json.dumps(state))
  status,part=req({**payment,'requestKey':'mapco-payment-fixture-2','amount':100000});assert status==200,part
  bf=next(r for r in req()[1]['payables'] if r['billId']=='BF:OPEN:0');assert bf['outstanding']==208345 and bf['paid']==100000
  assert json.loads(books.read_text())['journals']['OPEN']==initial['journals']['OPEN'],'Payments must preserve the opening journal'
  print('Supplier B/F HTTP: full and partial settlement, no duplicate expense, selected bills, retries and cancellation passed.')
 finally:server.terminate();server.wait()
