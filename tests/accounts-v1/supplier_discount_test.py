"""Isolated real API: supplier concessions clear bills without increasing bank/cash payments."""
import json,pathlib,shutil,subprocess,tempfile
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='supplier-discount-') as tmp:
 root=pathlib.Path(tmp)
 for d in ['api','accounts','data']:(root/d).mkdir()
 for name in ['supplier_settlements.php','supplier_opening_core.php','accounts_bank_payment.php']:shutil.copy(ROOT/'api'/name,root/'api'/name)
 for name in ['accounting_master_v1.json','settlement_policy_v1.json']:shutil.copy(ROOT/'accounts'/name,root/'accounts'/name)
 (root/'auth_store.php').write_text('''<?php
 define('TT_DATA_DIR',__DIR__.'/data');function tt_ensure_data_dir(){}function tt_accounts_input(){return $GLOBALS['input'];}
 function tt_require_login(){return ['role'=>'Super Admin','id'=>1,'username'=>'FIXTURE'];}
 function tt_user_can_open_module($u,$m){return true;}function tt_user_can_access_entity($u,$e,$a){return $e==='TTI';}
 function tt_verify_csrf($v){return $v==='fixture';}function tt_next_post_id($a,$m,$area){return 'POST-'.(count($a)+1);}
 ''')
 harness=root/'request.php';harness.write_text('''<?php
 $req=json_decode($argv[1],true);$_SERVER['REQUEST_METHOD']=$req['method']??'POST';$_GET=['entity'=>'TTI'];$GLOBALS['input']=json_encode(array_replace(['entity'=>'TTI','csrf'=>'fixture'],$req['body']??[]));require __DIR__.'/api/supplier_settlements.php';
 ''')
 books=root/'data/accounts.json'
 def bill(id,head,total):return {'id':id,'entity':'TTI','category':'CLEARING','vendor':'MAPCO','broker':'MAPCO','billNo':id,'billDate':'2026-07-11','payableAccount':head,'supplierPayableTotal':total,'receiptAllocations':[{'sourceKey':'SERVICE|'+id,'supplierPayableShare':total}]}
 initial={'supplierBills':{'A':bill('A','2130',600),'B':bill('B','2140',400)}}
 books.write_text(json.dumps(initial))
 def req(body=None):
  r=subprocess.run(['php',str(harness),json.dumps({'method':'GET'} if body is None else {'body':body})],capture_output=True,text=True,check=True)
  assert not r.stderr,r.stderr
  return json.loads(r.stdout)
 p={'action':'post_bill_payment','requestKey':'discount-fixture-0001','supplier':'MAPCO','amount':995,'discountAmount':5,'discountReason':'Agreed rounding','paymentMode':'CASH','date':'2026-07-21'}
 before=books.read_bytes()
 for invalid in [{'discountAmount':6},{'discountReason':''},{'discountAmount':-1},{'allowAdvance':True},{'entity':'BRM'}]:
  response=req({**p,**invalid});assert not response['ok'],response;assert books.read_bytes()==before
 posted=req(p);assert posted['ok'],posted
 st=posted['result'];state=json.loads(books.read_text());j=state['journals'][st['journalId']]
 assert [(l['account'],l['debit'],l['credit']) for l in j['lines']]==[('2130',600,0),('2140',400,0),('1120',0,995),('4400',0,5)],j
 assert st['discountAmount']==5 and st['netPayment']==995 and st['amount']==1000
 assert [a['discountAmount'] for a in st['allocations']]==[0,5]
 assert j['totalDebit']==j['totalCredit']==1000
 assert req()['payables']==[],req()
 count=len(state['journals']);assert req(p)['result']['journalId']==j['id'];assert len(json.loads(books.read_text())['journals'])==count
 assert not req({**p,'discountReason':'Changed reason'})['ok'],'Replay must reject changed discounts/reasons'
 # Cancellation status is authoritative: all allocated cash + discount capacity reopens.
 state['supplierSettlements'][st['id']]['status']='Cancelled';books.write_text(json.dumps(state))
 assert sum(r['outstanding'] for r in req()['payables'])==1000
 # Partial payment leaves exactly the genuinely unpaid balance open.
 part=req({**p,'requestKey':'discount-fixture-0002','amount':490});assert part['ok'],part
 assert sum(r['outstanding'] for r in req()['payables'])==505
 assert json.loads(books.read_text())['supplierBills']==initial['supplierBills'],'Never rewrite original bills'
 # New difference tick: server computes the concession, not an arbitrary typed discount.
 for tick in [False,True]:
  books.write_text(json.dumps(initial))
  body={**p,'requestKey':'difference-fixture-'+str(tick),'discountAmount':999,'discountReason':'Ignored old UI','applyPaymentDifferenceAsDiscount':tick,'selectedOutstanding':1000}
  posted=req(body);assert posted['ok'],posted
  st=posted['result'];assert st['netPayment']==995,st
  assert st['discountAmount']==(5 if tick else 0),st
  assert [a['amount'] for a in st['allocations']]==([600,400] if tick else [600,395]),st
  assert sum(r['outstanding'] for r in req()['payables'])==(0 if tick else 5)
  before=books.read_bytes();assert req(body)['result']['journalId']==st['journalId'];assert books.read_bytes()==before
  assert not req({**body,'applyPaymentDifferenceAsDiscount':not tick})['ok']
 books.write_text(json.dumps(initial));before=books.read_bytes()
 assert not req({**p,'requestKey':'stale-difference-fixture','applyPaymentDifferenceAsDiscount':True,'selectedOutstanding':900})['ok'];assert books.read_bytes()==before
 # An explicit empty selection must never fall back to all unpaid invoices.
 books.write_text(json.dumps(initial));before=books.read_bytes()
 assert not req({**p,'explicitBillSelection':True,'selectedBills':[]})['ok'];assert books.read_bytes()==before
 opening={'id':'OPEN-1','entity':'TTI','date':'2026-07-01','status':'Posted','sourceType':'OPENING_BALANCE_BF','lines':[{'account':'2130','credit':300,'debit':0,'counterparty':'MAPCO'},{'account':'3100','debit':300,'credit':0}]}
 books.write_text(json.dumps({**initial,'journals':{'OPEN-1':opening}}))
 body={**p,'requestKey':'opening-only-fixture-1','amount':200,'discountAmount':0,'discountReason':'','explicitBillSelection':True,'includeOpeningBalance':True,'selectedBills':['BF:OPEN-1:0|OPENING|0'],'applyPaymentDifferenceAsDiscount':False,'selectedOutstanding':300}
 saved=req(body);assert saved['ok'],saved
 assert [a['billId'] for a in saved['result']['allocations']]==['BF:OPEN-1:0'],saved
 unpaid=req()['payables'];assert {r['billId']:r['outstanding'] for r in unpaid}=={'A':600,'B':400,'BF:OPEN-1:0':100},unpaid
 assert req(body)['result']['journalId']==saved['result']['journalId']
 assert not req({**body,'explicitBillSelection':False})['ok'],'A posted draft cannot change selection semantics'
 # Legacy explicitly flagged openings and stable party identifiers stay payable.
 for source in ['JV','CARRY_FORWARD_OPENING']:
  legacy={**opening,'sourceType':source,'meta':{'openingBalance':True,'openingParty':'MAPCO'}}
  legacy['lines']=[{'account':'2130','credit':300,'debit':0,'subledger':'','counterparty':'MAPCO'},{'account':'3100','debit':300,'credit':0}]
  books.write_text(json.dumps({**initial,'journals':{'OPEN-1':legacy}}));assert any(r['openingBalance'] for r in req()['payables'] if 'openingBalance' in r)
 legacy={**opening,'sourceType':'JV','lines':[opening['lines'][0],{'account':'3400','debit':300,'credit':0}]}
 books.write_text(json.dumps({**initial,'journals':{'OPEN-1':legacy}}));assert any(r.get('openingBalance') for r in req()['payables'])
 # Commodity settlement uses exact selected allocations and cash-only brokerage WHT.
 books.write_text(json.dumps({'commodityBills':{'C':{'id':'C','entity':'TTI','broker':'BROKER','brokerageWhtPercent':15,'receiptAllocations':[{'sourceKey':'C|BROKERAGE','supplierPayableShare':100,'component':'BROKERAGE'}]}}}))
 response=req({'action':'post_supplier_payment','allocations':[{'billId':'C','sourceKey':'C|BROKERAGE','amount':100}],'discountAmount':5,'discountReason':'Agreed settlement','paymentMode':'CASH','date':'2026-07-21'})
 assert response['ok'],response
 assert response['result']['withholding']==14.25 and response['result']['netPayment']==80.75,response
 print('Supplier discounts: correct heads, full/partial balances, cash-only WHT, atomic rejection, replay, cancellation capacity, original bills passed.')
