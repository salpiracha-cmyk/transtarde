"""Retired carry-forward entry, preserved historical settlements and read access in disposable books."""
import copy, json, os, pathlib, shutil, socket, subprocess, tempfile, time
import urllib.request, urllib.error, urllib.parse

ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='carry-forward-qa-') as tmp:
 root=pathlib.Path(tmp)
 shutil.copy(ROOT/'document_filenames.php',root/'document_filenames.php')
 shutil.copytree(ROOT/'api',root/'api');shutil.copytree(ROOT/'accounts',root/'accounts');shutil.copytree(ROOT/'exports',root/'exports')
 (root/'data').mkdir()
 (root/'runtime_html.php').write_text('<?php function tt_version_local_assets($s,$p){return $s;}')
 shutil.copy(ROOT/'brand-theme.js',root/'brand-theme.js');shutil.copy(ROOT/'brand-theme.css',root/'brand-theme.css')
 (root/'auth_store.php').write_text('''<?php
 define('TT_DATA_DIR',__DIR__.'/data');foreach(['HOST','NAME','USER','PASS'] as $k)define('TT_DB_'.$k,'');
 function tt_ensure_data_dir(){}function tt_release_read_session(){}function tt_accounts_input(){return getenv('CF_FIXTURE_BODY')?:file_get_contents('php://input');}
 function tt_require_login(){ $role=$_GET['user']??'admin';$p=$role==='director'?['Directors'=>['View','Approve','entity-tti'=>['View','Approve']]]:($role==='services'?['Accounts'=>['services'=>['View','Create'],'supplier'=>['View','Create']]]:($role==='readonly'?['Accounts'=>['View']]:($role==='salary'?['Accounts'=>['expenses'=>['View']]]:['Accounts'=>'all'])));return ['role'=>$role==='admin'?'Super Admin':'Staff','permissions'=>$p,'id'=>$role==='admin'?1:2,'username'=>$role,'full_name'=>$role];}
 function tt_user_can_open_module($u,$m){if($u['role']==='Super Admin')return true;$p=$u['permissions'][$m]??[];if($p==='all'||in_array('View',(array)$p,true))return true;foreach((array)$p as $a)if(is_array($a)&&in_array('View',$a,true))return true;return false;}
 function tt_user_can_module_action($u,$m,$i,$a){$p=$u['permissions'][$m]??[];return $u['role']==='Super Admin'||$p==='all'||in_array($a,(array)$p,true)||in_array($a,(array)($p[$i]??[]),true);}
 function tt_user_can_access_entity($u,$e,$a){return ($u['role']==='Super Admin'||$e==='TTI')&&($a==='View'||($_GET['user']??'')!=='readonly');}
 function tt_user_accounts_entities($u){return $u['role']==='Super Admin'?['TTI','BRM','TG']:['TTI'];}
 function tt_verify_csrf($v){return $v==='fixture';}function tt_csrf(){return 'fixture';}
 function tt_next_post_id($s,$m='Accounts',$area='Journal',$date=null){$i=count($s)+1;do{$id='2026-'.str_pad((string)$i++,5,'0',STR_PAD_LEFT);}while(isset($s[$id]));return $id;}
 function tt_master_options(){return ['currencies'=>['USD','AED','PKR']];}function tt_company_fx_rate($e,$f,$t){return $f==='USD'?3.6725:1;}
 function tt_business_party_categories($v){return explode('|',(string)$v);}function tt_read_store(){return ['masters'=>tt_list_masters()];}
 function tt_bank_can_transact($id){return true;}function tt_bank_is_retention($id,$s){return false;}
 function tt_user_can_access_masters($u){return true;}function tt_user_visible_masters($u){return tt_list_masters();}
 function tt_active_business_party_for_role($name,$role){foreach(tt_list_masters()['business_parties'] as $r)if($r['values'][0]===$name&&in_array($role,tt_business_party_categories($r['values'][2]),true))return $r;return null;}
 function tt_list_masters(){return ['export_customers'=>[['id'=>'BUYER','values'=>['TEST BUYER']]],'business_parties'=>[['id'=>'VENDOR','values'=>['TEST SERVICES','','Freight Forwarder|Shipping Line / Carrier|Transporter|Clearing Agent|Fumigation|Inspection|Bag Supplier|Service Provider|Other']]],'banks'=>[
 ['id'=>'PKR','values'=>['Company Account','TTI','','TTI PKR','BANK','','','PKR','123','','','','','Active']],
 ['id'=>'USD','values'=>['Company Account','TG','','TG USD','BANK','','','USD','456','','','','','Active']],
 ['id'=>'AED','values'=>['Company Account','TG','','TG AED','BANK','','','AED','789','','','','','Active']]]];}
 ''')
 from permission_fixture import append_permission_helpers
 append_permission_helpers(root)
 operations={'values':{'transtrade_export_v3_operational':json.dumps({'shipments':[],'contracts':[],'fi':[],'inventorySentinel':{'riceKg':12345}})}}
 ops=root/'data/operations.json';ops.write_text(json.dumps(operations))
 initial={'revision':1,'journals':{'BANK':{'id':'BANK','entity':'TTI','status':'Posted','date':'2026-07-01','lines':[{'account':'1110','debit':1000000,'credit':0,'bankAccountId':'PKR','bankDebit':1000000,'bankCredit':0}]}},'inventorySentinel':{'riceKg':12345},'bankAccountSettings':{'PKR':{'active':True,'allowPayments':True},'USD':{'active':True,'allowPayments':True},'AED':{'active':True,'allowPayments':True}}}
 books=root/'data/accounts.json';books.write_text(json.dumps(initial))
 try:
  with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 except PermissionError:port=None
 env=os.environ.copy()
 for key in ['TT_DB_HOST','TT_DB_NAME','TT_DB_USER','TT_DB_PASS']:env.pop(key,None)
 log=open(root/'php.log','w');server=None if port is None else subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root)],env=env,stdout=log,stderr=log)
 (root/'cli.php').write_text('''<?php $_GET=json_decode(getenv('CF_FIXTURE_QUERY'),true);$_SERVER['REQUEST_METHOD']=getenv('CF_FIXTURE_METHOD');http_response_code(200);register_shutdown_function(function(){echo "\\n__STATUS__".http_response_code();});require __DIR__.'/api/'.getenv('CF_FIXTURE_ENDPOINT');''')
 serial=0
 def request(endpoint='carry_forward_shipments.php',body=None,user='admin',entity='TTI',**params):
  query=urllib.parse.urlencode({'user':user,'entity':entity,**params});payload=None if body is None else json.dumps({'csrf':'fixture',**body}).encode()
  if port is None:
   result=subprocess.run(['php',str(root/'cli.php')],env={**env,'CF_FIXTURE_QUERY':json.dumps({'user':user,'entity':entity,**params}),'CF_FIXTURE_METHOD':'GET' if body is None else 'POST','CF_FIXTURE_BODY':payload.decode() if payload else '', 'CF_FIXTURE_ENDPOINT':endpoint},capture_output=True,text=True)
   content,_,code=result.stdout.rpartition('\n__STATUS__')
   try:return int(code),json.loads(content)
   except Exception:raise AssertionError((endpoint,result.stdout,result.stderr))
  r=urllib.request.Request(f'http://127.0.0.1:{port}/api/{endpoint}?{query}',data=payload,headers={'Content-Type':'application/json'})
  try:
   with urllib.request.urlopen(r,timeout=10) as res:return res.status,json.load(res)
  except urllib.error.HTTPError as e:return e.code,json.load(e)
 def action(action,id='',**extra):
  global serial
  serial+=1
  return {'action':action,'id':id,'requestKey':f'carry-forward-fixture-{serial:04}',**extra}
 def balance(number='CI-1',value=1000,received=0,currency='USD',rate=280,**extra):return {'invoiceNo':number,'invoiceDate':'2026-06-25','invoiceAmount':value,'receivedBefore':received,'currency':currency,'rate':rate,'mode':'NEW',**extra}
 def shipment(**extra):return {'entity':'TTI','customerId':'BUYER','contractRef':'OLD-SHIPMENT','lotRef':'LOT-1','shipmentDate':'2026-06-28','blNo':'BL-OLD','containerCount':2,'containers':['ABC1','ABC2'],'loadingProgrammeNo':'LP-OLD','goods':'RICE','quantityMT':50,'portOfDischarge':'MANILA','buyerBalance':balance(),'fiRefs':[{'number':'FI-OLD','date':'2026-07-03','amount':1000}],'gdRefs':[{'number':'GD-OLD','date':'2026-06-28'}],'ricePaymentReferences':'BANK RICE PAYMENT ALREADY POSTED 2026-12345','bills':[],**extra}
 def bill(kind='FREIGHT',entity='TTI',number='BILL-1',currency='PKR',**extra):return {'entity':entity,'kind':kind,'partyId':'VENDOR','invoiceNo':number,'billDate':'2026-07-03','dueDate':'2026-07-10','currency':currency,'amount':100,'rate':1 if currency in ['PKR','AED'] else 3.6725,'treatment':'CURRENT',**extra}
 (root/'seed.php').write_text("""<?php require __DIR__.'/auth_store.php';require __DIR__.'/api/carry_forward_core.php';$p=TT_DATA_DIR.'/accounts.json';$s=json_decode(file_get_contents($p),true);try{$r=cf_action($s,json_decode(getenv('CF_SEED'),true),tt_require_login());file_put_contents($p,json_encode($s));echo json_encode(['ok'=>true,'result'=>$r]);}catch(Throwable $e){fwrite(STDERR,$e->getMessage());exit(1);}""")
 def seed(body):
  r=subprocess.run(['php',str(root/'seed.php')],env={**env,'CF_SEED':json.dumps(body)},capture_output=True,text=True)
  assert r.returncode==0,(r.stdout,r.stderr,body)
  return json.loads(r.stdout)
 def ok(body,**kw):
  if not kw:return seed(body)
  status,d=request(body=body,**kw);assert status==200,(status,d,body);return d
 def publish(id):
  r=request(id=id)[1]['rows'][0];return seed(action('publish',id,version=r['version']))
 try:
  for _ in range(50):
   try:status,d=request();break
   except urllib.error.URLError:time.sleep(.1)
  assert status==200,d
  assert request(user='readonly')[1]['canManage'] is False
  assert request(entity='BRM',user='services')[0]==403
  before=books.read_bytes();before_ops=ops.read_bytes()
  for verb in ['save','post','publish','add_bill','post_bill','upload']:
   for who in ['admin','services','readonly']:
    assert request(body=action(verb,**shipment()),user=who)[0]==410
  assert books.read_bytes()==before and ops.read_bytes()==before_ops,'Retired actions must not write any source'
  assert d['retired'] and not d['canManage'] and not d['canAddBills'] and not d['openingLines']
  saved=ok(action('save',**shipment()));id=saved['result']['id'];assert books.exists()
  assert len(json.loads(books.read_text())['journals'])==1,'Information save must not post money'
  assert not any(r['id']==id for r in request(user='services')[1]['rows']),'Staff must not see management drafts'
  before=books.read_bytes();assert request(body=action('publish',id,version=999))[0]==410;assert books.read_bytes()==before
  body=action('publish',id,version=1);ok(body);assert ok(body)['result']['replayed']
  state=json.loads(books.read_text());c=state['exportCandidates'][id+'|BUYER'];assert c['transactionAmount']==1000 and c['functionalAmount']==0 and c['pendingValuation']
  assert not c['journalId'] and len(state['journals'])==1,'Invoice registration must not invent a conversion or monetary journal'
  assert request('accounts_shipment_lookup.php',q='GD-OLD')[1]['rows'][0]['id']==id
  # Each non-rice category becomes an existing normal Supplier Payment payable.
  kinds=['FREIGHT','TRANSPORT','CLEARING','FUMIGATION','INSPECTION','BAGS','OTHER']
  for i,kind in enumerate(kinds):
   ok(action('add_bill',id,bill=bill(kind,number=f'BILL-{i}')))
   b=request(id=id)[1]['rows'][0]['bills'][-1]
   if kind=='FREIGHT':assert request(body=action('post_bill',id,billId=b['id']),user='services')[0]==410
   body=action('post_bill',id,billId=b['id']);ok(body);snapshot=books.read_bytes();ok(body);assert books.read_bytes()==snapshot,'Retries must not post twice'
   payable=id+'-'+b['id'];rows=request('supplier_settlements.php')[1]['payables'];assert any(x['billId']==payable for x in rows)
   payment={'action':'post_bill_payment','entity':'TTI','date':'2026-07-05','supplier':'TEST SERVICES','amount':100,'selectedBills':[payable+'|CARRY|'+payable],'paymentMode':'BANK','bankAccountId':'PKR','bankPaymentMethod':'ONLINE_BANKING','reference':f'PAY-{i}','requestKey':f'carry-payment-fixture-{i:04}'}
   paid=ok(payment,endpoint='supplier_settlements.php');postid=paid['result']['journalId'];state=json.loads(books.read_text());journal=state['journals'][postid];assert journal['totalDebit']==journal['totalCredit']==100
   assert any(l['account']=='1110' and l['credit']==100 for l in journal['lines'])
   assert ok(payment,endpoint='supplier_settlements.php')['result']['journalId']==postid
   assert request('supplier_settlements.php',body={**payment,'amount':1,'requestKey':f'carry-overpay-fixture-{i:04}'})[0] in [409,422]
   b=request(id=id)[1]['rows'][0]['bills'][-1];assert b['paymentOutstanding']==0
  state=json.loads(books.read_text());assert state['inventorySentinel']==initial['inventorySentinel'];assert ops.read_text()==json.dumps(operations),'Bills/payments must not alter Exports or stock'
  assert all(x.get('category')!='RICE' for x in state['supplierBills'].values())
  assert seed(action('add_bill',id,bill=bill(number='BILL-0')))['ok']
  dupe=request(id=id)[1]['rows'][0]['bills'][-1];before=books.read_bytes();assert request(body=action('post_bill',id,billId=dupe['id']))[0]==410;assert books.read_bytes()==before
  # TG opening pack has matched 1240 / 2500 sides and a separate external buyer balance.
  tg=shipment(contractRef='OLD-TG',lotRef='TG-LOT',tgPack=True,fiRefs=[{'number':'FI-TG','date':'2026-07-03','amount':900}],buyerBalance=balance('TG-BUYER',1200,0,rate=3.6725),pakistanBalance=balance('PK-TG',1000,100),tgPayableBalance=balance('PK-TG',1000,100,rate=3.6725))
  tid=ok(action('save',**tg))['result']['id'];publish(tid);state=json.loads(books.read_text());p=state['exportCandidates'][tid+'|PAKISTAN'];t=state['exportCandidates'][tid+'|TG'];assert p['transactionAmount']==t['transactionAmount']==900;assert p['mirrorCandidateId']==t['id'];assert t['linkedCandidateId']==p['id'];assert t['pendingValuation'] and t['functionalRate']==0
  assert not t['journalId']
  assert request(entity='TG',user='services')[0]==403
  visible=request(id=tid,user='services')[1]['rows'][0];assert 'tgPayableBalance' not in visible and 'buyerBalance' not in visible
  for i,kind in enumerate(kinds):
   currency='USD' if i%2==0 else 'AED';ok(action('add_bill',tid,bill=bill(kind,'TG',f'TG-BILL-{i}',currency)))
   b=next(x for x in request(entity='TG',id=tid)[1]['rows'][0]['bills'] if x['invoiceNo']==f'TG-BILL-{i}')
   ok(action('post_bill',tid,billId=b['id']));payable=tid+'-'+b['id'];liabilities=request('tg_bank_transactions.php',entity='TG')[1]['openLiabilities'];assert any(x['id']==payable for x in liabilities)
   payment={'action':'post_payment','date':'2026-07-05','paymentType':'LIABILITY','guidedPayment':True,'selectedBills':[payable],'paymentMode':'BANK','requestKey':f'cf-tg-payment-fixture-{i:04}','sourceLiabilityId':payable,'bankAccountId':currency,'counterparty':'TEST SERVICES','amountNative':100,'currency':currency,'bankChargeNative':0,'rate':3.6725 if currency=='USD' else 1,'bankReference':f'TG-PAY-{i}','bankPaymentMethod':'ONLINE_BANKING'}
   posted=ok(payment,endpoint='tg_bank_transactions.php',entity='TG');assert posted['journal']['totalDebit']==posted['journal']['totalCredit']
   assert request('tg_bank_transactions.php',body=payment,entity='TG')[0]==200
   assert request('tg_bank_transactions.php',body={**payment,'amountNative':1,'requestKey':f'cf-tg-overpay-fixture-{i:04}'},entity='TG')[0] in [409,422]
   assert next(x for x in request(entity='TG',id=tid)[1]['rows'][0]['bills'] if x['id']==b['id'])['paymentOutstanding']==0
  tg_customer_receipt={'action':'post_receipt','date':'2026-07-05','receiptType':'EXPORT_RECEIVABLE','sourceCandidateId':tid+'|BUYER','bankAccountId':'USD','counterparty':'TEST BUYER','settlementAmountNative':1200,'rate':3.6725,'bankReference':'CF-TG-CUSTOMER','bankChargeNative':0}
  posted=ok(tg_customer_receipt,endpoint='tg_bank_transactions.php',entity='TG');assert posted['journal']['totalDebit']==posted['journal']['totalCredit']
  state=json.loads(books.read_text());assert not state['exportCandidates'][tid+'|BUYER']['pendingValuation']
  response=request('export_receipts.php');assert response[0]==200,response;pack=response[1]['sources']['tgPackInvoices'];assert any(x['candidateId']==tid+'|PAKISTAN' and x['invoiceRef']=='PK-TG' for x in pack)
  # Saved drafts remain in the books, but cannot be selected/published as new receipt sources.
  draft_tg=shipment(contractRef='SAVED-TG',lotRef='SAVED-TG-LOT',fiRefs=[],gdRefs=[],tgPack=True,buyerBalance=balance('SAVED-BUYER',1500),pakistanBalance=balance('SAVED-PACK',1100),tgPayableBalance=balance('SAVED-PACK',1100,rate=3.6725))
  did=seed(action('save',**draft_tg))['result']['id'];before=books.read_bytes()
  assert not any(x.get('carryForwardDraftId')==did for x in request('export_receipts.php')[1]['sources']['tgPackInvoices'])
  assert request(id=did)[1]['rows'][0]['status']=='Draft'
  assert request(body=action('publish',did,version=1))[0]==410 and books.read_bytes()==before
  # Existing opening assignment links only the exact native capacity, without a second journal.
  state=json.loads(books.read_text());state['journals']['OPEN-EXISTING']={'id':'OPEN-EXISTING','entity':'TTI','date':'2026-07-01','sourceType':'OPENING_BALANCE_BF','status':'Posted','lines':[{'account':'1210','subledger':'TEST BUYER','nativeCurrency':'USD','nativeDebit':1000,'nativeCredit':0,'debit':280000,'credit':0}]};books.write_text(json.dumps(state))
  linked=shipment(contractRef='LINKED-OLD',lotRef='LINK-1',buyerBalance=balance('CI-LINK',500,0,mode='LINK',journalId='OPEN-EXISTING',lineIndex=0))
  lid=ok(action('save',**linked))['result']['id'];before_count=len(json.loads(books.read_text())['journals']);publish(lid);state=json.loads(books.read_text());assert len(state['journals'])==before_count;assert state['exportCandidates'][lid+'|BUYER']['journalId']=='OPEN-EXISTING'
  too_much=shipment(contractRef='LINK-OVER',lotRef='LINK-2',buyerBalance=balance('CI-OVER',600,0,mode='LINK',journalId='OPEN-EXISTING',lineIndex=0));oid=ok(action('save',**too_much))['result']['id'];before=books.read_bytes();assert request(body=action('publish',oid,version=1))[0]==410;assert books.read_bytes()==before
  # A 100% advance has no opening receivable, and no recreated rice transaction.
  advance=shipment(contractRef='ADV-OLD',lotRef='ADV-1',fiRefs=[{'number':'FI-ADV','date':'2026-06-15','amount':100}],advance100=True,advanceReceiptDate='2026-06-15',buyerBalance=balance('ADV-CI',100,100));aid=ok(action('save',**advance))['result']['id'];before_count=len(json.loads(books.read_text())['journals']);publish(aid);assert len(json.loads(books.read_text())['journals'])==before_count
  assert ops.read_text()==json.dumps(operations)
  # Existing credit-advice routes can settle the opening direct and Pakistan TG receivables.
  receipt={'action':'post_receipt','entity':'TTI','date':'2026-07-03','bankAdviceRef':'CF-DIRECT-ADVICE','remitter':'TEST BUYER','transactionCurrency':'USD','foreignAmount':1000,'realizationRate':280,'grossPkrEquivalent':280000,'pkrBankCredit':280000,'bankAccountId':'PKR','deductions':[],'allocations':[{'targetType':'EXPORT_RECEIVABLE','targetId':id+'|BUYER','foreignAmount':1000}]}
  posted=ok(receipt,endpoint='export_receipts.php');assert posted['journal']['totalDebit']==posted['journal']['totalCredit']
  assert request(id=id)[1]['rows'][0]['realizations'][0]['date']=='2026-07-03'
  state=json.loads(books.read_text());assert state['exportCandidates'][id+'|BUYER']['functionalAmount']==280000 and not state['exportCandidates'][id+'|BUYER']['pendingValuation']
  assert not any(l['account']=='7100' for l in posted['journal']['lines']),'Deferred valuation must not treat the whole receipt as FX gain'
  tagged=request('export_receipts.php')[1]['receipts'];assert next(x for x in tagged if x['bankAdviceRef']=='CF-DIRECT-ADVICE')['fiTag']['fiNumber']=='FI-OLD'
  tg_receipt={**receipt,'bankAdviceRef':'CF-TG-ADVICE','remitter':'TG','tgBankAccountId':'USD','foreignAmount':900,'grossPkrEquivalent':252000,'pkrBankCredit':252000,'allocations':[{'targetType':'INTERCOMPANY_RECEIVABLE','targetId':tid+'|PAKISTAN','foreignAmount':900,'invoiceRef':'PK-TG','contractRef':'OLD-TG'}]}
  posted=ok(tg_receipt,endpoint='export_receipts.php');assert posted['journal']['totalDebit']==posted['journal']['totalCredit']
  state=json.loads(books.read_text());assert any(x['allocations'][0]['sourceLiabilityId']==tid+'|TG' for x in state['tgRemittanceDrafts'].values()),'Pakistan advice must link its TG counterpart'
  # The actual registered/settled opening sources are not new sale recognition failures.
  original_bytes=books.read_bytes();summary=request('shipment_profitability.php')[1]
  for candidate_id,contract_ref in [(id+'|BUYER','OLD-SHIPMENT'),(tid+'|PAKISTAN','OLD-TG')]:
   assert not any(candidate_id+':' in hold for hold in summary['sourceHolds']),summary['sourceHolds']
   historical_row=next(r for r in summary['rows'] if r['contractRef']==contract_ref)
   assert historical_row['historicalOpeningCount']==1 and historical_row['revenue'] is None and historical_row['stockCost'] is None and historical_row['grossContribution'] is None,historical_row
  assert books.read_bytes()==original_bytes,'Reporting posted carry-forward sources changed their original books'
  print('PASS actual carry-forward registration and settlement: historical opening report labels, no false sale holds, no reposted revenue/cost and unchanged source bytes')

  assert json.loads(ops.read_text())['values']['transtrade_export_v3_operational']
  root_ops=json.loads(json.loads(ops.read_text())['values']['transtrade_export_v3_operational']);assert root_ops['shipments']==[] and root_ops['inventorySentinel']=={'riceKg':12345}
  # An existing unposted lot/invoice is reused, with one source in normal shipment search.
  state=json.loads(books.read_text());existing_id='CUSTOMER_EXPORT_SALE|LIVE-LOT';state['exportCandidates'][existing_id]={'id':existing_id,'entity':'TTI','candidateType':'CUSTOMER_EXPORT_SALE','sourceKey':'LIVE-LOT','journalId':None,'meta':{'commercialInvoiceNo':'CI-LIVE','contractRef':'LIVE-OLD'}};books.write_text(json.dumps(state))
  root_ops['contracts']=[{'ref':'LIVE-OLD','seller':'TTI','customer':'TEST BUYER'}];root_ops['shipments']=[{'id':'LIVE-LOT','kind':'lot','seller':'TTI','contractRef':'LIVE-OLD','lotId':'LIVELOT','commercial':{'invoiceNo':'CI-LIVE'},'bl':{'blNo':'LIVE-BL'}}];operations_with_lot={'values':{'transtrade_export_v3_operational':json.dumps(root_ops)}};ops.write_text(json.dumps(operations_with_lot))
  existing=shipment(contractRef='LIVE-OLD',lotRef='LIVELOT',blNo='LIVE-BL',buyerBalance=balance('CI-LIVE',200,0,mode='LINK',journalId='OPEN-EXISTING',lineIndex=0));eid=ok(action('save',**existing))['result']['id'];publish(eid)
  state=json.loads(books.read_text());assert state['carryForwardShipments'][eid]['buyerCandidateId']==existing_id and state['exportCandidates'][existing_id]['meta']['historicalCostExcluded'];assert eid+'|BUYER' not in state['exportCandidates']
  sources=request('accounts_shipment_lookup.php',q='LIVE-BL')[1]['rows'];assert len(sources)==1 and sources[0]['id']=='LIVE-LOT' and sources[0]['carryForwardShipmentId']==eid
  assert ops.read_text()==json.dumps(operations_with_lot),'Linking existing operational lots must not edit them'
  if os.getenv('TT_QA_BROWSER')=='1' and port is not None:
   # Minimal Accounts host mounts the production Supplier Payment UI and deep link.
   (root/'accounts/index.php').write_text('''<?php require __DIR__.'/../auth_store.php';?><!doctype html><html><head><meta charset="utf-8"></head><body><div class="shell"></div><button class="entityBtn" data-entity="TTI">TTI</button><button class="entityBtn" data-entity="TG">TG</button><script>window.TT_ACCOUNT_ACCESS={csrf:'fixture',super:true,entities:['TTI','TG']};document.querySelectorAll('.entityBtn').forEach(b=>b.onclick=()=>localStorage.setItem('tt_accounts_entity',b.dataset.entity));</script><script src="cheque-date-ui.js"></script><script src="post-confirmation-ui.js"></script><script src="supplier-settlement-ui.js"></script><script src="carry-forward-entry-ui.js"></script><script src="/brand-theme.js"></script></body></html>''')
   ok(action('add_bill',id,bill=bill('FREIGHT',number='BROWSER-PAY',amount=66)));ui_bill=request(id=id)[1]['rows'][0]['bills'][-1];ok(action('post_bill',id,billId=ui_bill['id']));ui_payable=id+'-'+ui_bill['id']
   from playwright.sync_api import sync_playwright
   with sync_playwright() as p:
    browser=p.chromium.launch();page=browser.new_page(viewport={'width':1440,'height':1000})
    for path in ['accounts/carry-forward.php','exports/carry-forward.php']:
     response=page.goto(f'http://127.0.0.1:{port}/{path}?entity=TTI')
     assert response.status==410 and page.locator('#cf-form').count()==0
     assert 'retired' in page.locator('body').inner_text()
    page.goto(f'http://127.0.0.1:{port}/accounts/?entity=TTI&cfBill={urllib.parse.quote(ui_payable)}');page.locator('#ttSimpleBills [data-payee]').wait_for();assert page.locator('#ttSimpleBills [data-payee]').input_value()=='TEST SERVICES'
    page.locator('#ttSimpleBills [data-bank-method]').select_option('ONLINE_BANKING');page.locator('#ttSimpleBills [data-reference]').fill('BROWSER-CARRY-PAY');page.locator('#ttSimpleBills [type=submit]').click();page.locator('[data-review-post]').click();page.locator('#tt-post-confirmation').wait_for();assert page.locator('#ttSimpleBills').count()==0;assert page.locator('#tt-post-confirmation').count()==1
    assert next(b for b in request(id=id)[1]['rows'][0]['bills'] if b['id']==ui_bill['id'])['paymentOutstanding']==0
    browser.close();print('Retired carry-forward pages and preserved existing bill payment deep link passed')
  print('Retirement: blocked entry, preserved historical payments/receipts in Pakistan and TG, report reads, company scopes and unchanged stock passed')
 finally:
  if server is not None:server.terminate();server.wait(timeout=5)
  log.close()
  if os.getenv('TT_QA_DEBUG'):print((root/'php.log').read_text()[-8000:])
