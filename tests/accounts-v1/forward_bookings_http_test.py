"""Disposable actual endpoints: booking permissions/replay, linked receipts and net expiry settlement."""
import json,pathlib,shutil,socket,subprocess,tempfile,time,urllib.request,urllib.error,concurrent.futures
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='forward-booking-http-') as tmp:
 root=pathlib.Path(tmp);shutil.copytree(ROOT/'api',root/'api');(root/'accounts').mkdir();(root/'data').mkdir()
 for name in ['accounting_master_v1.json','export_realization_policy_v1.json','settlement_policy_v1.json']:shutil.copy(ROOT/'accounts'/name,root/'accounts'/name)
 (root/'auth_store.php').write_text('''<?php
 define('TT_DATA_DIR',__DIR__.'/data');function tt_ensure_data_dir(){}function tt_accounts_input(){return file_get_contents('php://input');}
 function tt_require_login(){return ['id'=>1,'username'=>'Fixture','role'=>'Staff','permissions'=>['Accounts'=>'all']];}function tt_user_can_open_module($u,$m){return true;}
 function tt_user_can_access_entity($u,$e,$a){return $e==='TTI'&&($a==='View'||empty($_SERVER['HTTP_X_READONLY']));}function tt_user_can_module_action($u,$m,$i,$a){return $a==='View'||empty($_SERVER['HTTP_X_READONLY']);}
 function tt_verify_csrf($v){return $v==='fixture';}function tt_bank_can_transact($id){return true;}
 function tt_list_masters(){return ['banks'=>[['id'=>'A','values'=>['Company Account','TTI','','Company','Bank A','','','PKR','111','','','','','Active']],['id'=>'B','values'=>['Company Account','TTI','','Company','Bank B','','','PKR','222','','','','','Active']]],'export_realization_charges'=>[]];}
 function tt_next_post_id($rows,$module='Accounts',$area='Journal',$date=null){$n=count($rows)+1;do{$id='POST-2026-'.str_pad((string)$n++,5,'0',STR_PAD_LEFT);}while(isset($rows[$id]));return $id;}
 ''')
 state={'revision':0,'journals':{},'exportCandidates':{'INV':{'id':'INV','entity':'TTI','candidateType':'CUSTOMER_EXPORT_SALE','journalId':'SALE','transactionCurrency':'USD','transactionAmount':200,'functionalAmount':54000,'reference':'INV-001','counterparty':'Fixture Buyer','meta':{'customer':'Fixture Buyer','commercialInvoiceNo':'INV-001','contractRef':'SHIP-1'}}}}
 books=root/'data/accounts.json';books.write_text(json.dumps(state));(root/'data/operations.json').write_text(json.dumps({'values':{'transtrade_export_v3_operational':json.dumps({})}}))
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 def req(path='forward_bookings.php?entity=TTI',body=None,readonly=False):
  try:
   with urllib.request.urlopen(urllib.request.Request(f'http://127.0.0.1:{port}/api/'+path,data=json.dumps(body).encode() if body is not None else None,headers={'Content-Type':'application/json',**({'X-Readonly':'1'} if readonly else {})}),timeout=15) as r:return r.status,json.load(r)
  except urllib.error.HTTPError as e:return e.code,json.load(e)
 try:
  for _ in range(50):
   try:status,data=req();break
   except urllib.error.URLError:time.sleep(.05)
  assert status==200,data
  payload={'entity':'TTI','csrf':'fixture','action':'create','requestKey':'fixture-create-1','revision':0,'bankAccountId':'A','bookingDate':'2026-07-01','waitingDays':30,'windowDays':60,'amountUsd':100,'rate':280.123456,'contractNumber':''}
  before=books.read_bytes();assert req(body=payload,readonly=True)[0]==403 and books.read_bytes()==before
  assert req(body={**payload,'csrf':'bad'})[0]==403 and books.read_bytes()==before
  assert req('forward_bookings.php?entity=TG')[0]==422
  status,d=req(body=payload);assert status==200,d;bid=d['result']['bookingId'];assert d['bookings'][0]['contractNumber']=='' and not json.loads(books.read_text())['journals']
  assert req(body=payload)[1]['result']['bookingId']==bid and len(json.loads(books.read_text())['forwardBookings'])==1
  assert req(body={**payload,'requestKey':'fixture-new-stale'})[0]==409
  receipt={'action':'post_receipt','csrf':'fixture','entity':'TTI','date':'2026-08-01','bankAdviceRef':'FWD-ADVICE','remitter':'Fixture Buyer','transactionCurrency':'USD','foreignAmount':60,'realizationRate':280.123456,'grossPkrEquivalent':16807.41,'bankAccountId':'B','pkrBankCredit':16807.41,'forwardBookingId':bid,'allocations':[{'targetType':'EXPORT_RECEIVABLE','targetId':'INV','foreignAmount':60}],'deductions':[]}
  before=books.read_bytes();assert req('export_receipts.php?entity=TTI',{**receipt,'date':'2026-07-30'})[0]==422 and books.read_bytes()==before
  status,r=req('export_receipts.php?entity=TTI',receipt);assert status==200,r;rid=r['receipt']['id'];assert r['receipt']['forwardUsdUsed']==60 and r['receipt']['bankAccountId']=='B';assert r['journal']['totalDebit']==r['journal']['totalCredit'];assert req()[1]['bookings'][0]['remainingUsd']==40
  before=books.read_bytes();assert req('export_receipts.php?entity=TTI',receipt)[0]==409 and books.read_bytes()==before
  assert req('export_receipts.php?entity=TTI',{**receipt,'bankAdviceRef':'EXCEEDS','foreignAmount':50})[0]==422 and books.read_bytes()==before
  # Amend to a separate manual-rate advice: reversal returns forward capacity without duplicating invoice receipts.
  status,r=req('export_receipts.php?entity=TTI',{**receipt,'amendmentOf':rid,'amendmentReason':'Correct to manual conversion','forwardBookingId':'','realizationRate':281,'grossPkrEquivalent':16860,'pkrBankCredit':16860});assert status==200,r;assert req()[1]['bookings'][0]['remainingUsd']==100
  # One of two racing requests succeeds under the Accounts lock; the second cannot over-use the contract.
  def part(n):return req('export_receipts.php?entity=TTI',{**receipt,'bankAdviceRef':'RACE-'+str(n)})
  with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:answers=list(pool.map(part,[1,2]))
  assert sorted(a[0] for a in answers)==[200,422],answers;assert req()[1]['bookings'][0]['remainingUsd']==40
  d=req()[1];close={'entity':'TTI','csrf':'fixture','action':'settle','id':bid,'revision':d['revision'],'requestKey':'fixture-close-1','date':'2026-09-29','closingRate':270.123456,'fees':10,'expectedRemainingUsd':40}
  status,d=req(body=close);assert status==200,d;jid=d['result']['journalId'];assert d['result']['settlement']['profitLoss']==400 and d['result']['settlement']['netBankAmount']==390
  saved=json.loads(books.read_text());j=saved['journals'][jid];assert j['lines'][0]['bankAccountId']=='A' and j['lines'][0]['debit']==390 and j['totalDebit']==j['totalCredit'];assert req(body=close)[1]['result']['journalId']==jid
  assert d['bookings'][0]['unsettledUsd']==0 and len(d['bookings'][0]['utilisations'])==2
  original=next(r for r in saved['exportReceipts'].values() if r.get('forwardBookingId')==bid and r['status']=='Accounts Approved / Posted')
  before=books.read_bytes();status,bad=req('export_receipts.php?entity=TTI',{**receipt,'amendmentOf':original['id'],'amendmentReason':'Blocked until expiry settlement is reversed'});assert status==409,bad;assert books.read_bytes()==before
  status,d=req(body={'entity':'TTI','csrf':'fixture','action':'reverse_settlement','id':bid,'reason':'Correct bank closing advice','revision':d['revision'],'requestKey':'fixture-close-reverse'});assert status==200,d;assert d['bookings'][0]['status']=='Open' and d['bookings'][0]['remainingUsd']==40
  print('PASS forward HTTP: permissions, optional number, replay/stale protection, cross-bank advice, amendments, competing utilisation, linked expiry and settlement reversal')
 finally:server.terminate();server.wait(timeout=10)
