"""Real bill endpoints and optional browser flow, always in disposable private storage."""
import http.cookiejar, json, os, re, shutil, socket, subprocess, tempfile, time, urllib.request, urllib.parse
from pathlib import Path
SOURCE=Path(__file__).resolve().parents[2]
SEED=r'''<?php
require __DIR__.'/repo/auth_store.php';
$pw=bin2hex(random_bytes(20));$rw=['View','Create','Edit'];
$users=[['id'=>501,'username'=>'billqa','full_name'=>'Bill QA','role'=>'Accounts Operator','permissions'=>['Accounts'=>['purchases'=>$rw,'transport'=>$rw,'freight'=>$rw,'services'=>$rw,'supplier'=>$rw,'expenses'=>$rw,'customer'=>$rw,'cashbank'=>$rw,'entity-tti'=>$rw]],'active'=>true,'must_change_password'=>false,'master_access'=>true,'master_permissions'=>['business_parties'=>$rw],'password_hash'=>password_hash($pw,PASSWORD_DEFAULT)],['id'=>502,'username'=>'billview','full_name'=>'Bill Viewer','role'=>'Accounts Viewer','permissions'=>['Accounts'=>['purchases'=>['View'],'entity-tti'=>['View']]],'active'=>true,'must_change_password'=>false,'master_access'=>false,'password_hash'=>password_hash($pw,PASSWORD_DEFAULT)]];
$users[]=['id'=>503,'username'=>'fixtureowner','full_name'=>'Fixture Owner','role'=>'Super Admin','permissions'=>['Accounts'=>'all'],'active'=>true,'must_change_password'=>false,'master_access'=>true,'password_hash'=>password_hash($pw,PASSWORD_DEFAULT)];
$masters=tt_default_masters();$masters['business_parties'][]=['id'=>'transport-fixture','values'=>['Fixture Transport','FT','Transporter','','','','','','','','Active']];$masters['business_parties'][]=['id'=>'broker-jj-fixture','values'=>['JJ','JJ','Broker','','','','','','','','Active','','{"buying":[{"amount":5,"basis":"PER_100_KG","effectiveFrom":"2026-01-01","status":"Active"}],"selling":[{"amount":9,"basis":"PER_TON","effectiveFrom":"2026-01-01","status":"Active"}]}']];
$masters['banks'][]=['id'=>'qa-bank-a','values'=>['Company Account','TTI','','Fixture A','Fixture Bank A','','Pakistan','PKR','12345','','','','','Active']];$masters['banks'][]=['id'=>'qa-bank-default','values'=>['Company Account','TTI','','Fixture B','Fixture Bank B','','Pakistan','PKR','67890','','','','','Active']];
tt_ensure_data_dir();file_put_contents(TT_STORE_FILE,json_encode(['users'=>$users,'masters'=>$masters,'settings'=>['qa_account_seeded'=>true],'audit'=>[]]));
$s=['bankAccountSettings'=>['qa-bank-default'=>['active'=>true,'allowPayments'=>true,'allowReceipts'=>true,'defaultReceiptAccount'=>true]],'revision'=>0,'journals'=>[],'events'=>[],'commodityBills'=>[],'purchaseSodas'=>[],'loadingProgrammes'=>[],'transportMaster'=>[['from'=>'Karachi','to'=>'Jeddah','rate'=>38000]]];
foreach([['26001','READY','Indus Rice','JJ','CREDIT',30],['26002','RAW','Indus Rice','JJ','CASH',0],['26003','READY','','JJ','CASH',0],['26004','READY','Indus Rice','','CREDIT',60]] as [$no,$stage,$party,$broker,$term,$days]){
 $s['purchaseSodas'][$no]=['id'=>'PS-'.$no,'entity'=>'TTI','commodity'=>'RICE','sodaNo'=>$no,'sodaDate'=>'2026-09-01','party'=>$party,'broker'=>$broker,'productStage'=>$stage,'rate'=>100,'paymentTermType'=>$term,'creditDays'=>$days];
 foreach([1,2] as $n){$key=($stage==='READY'?'EXMILL|':'POHANCH|').$no.'|'.$n;$jid='J-'.$no.'-'.$n;$eid='TTI|COMMODITY_RECEIPT_ACCEPTED|'.$key;$date='2026-09-'.(10+$n);
 $meta=['soda'=>$no,'sourceSodaId'=>'PS-'.$no,'broker'=>$broker,'party'=>$party,'commodity'=>'RICE','productStage'=>$stage,'baseVariety'=>'IRRI-6','variety'=>'IRRI-6','displayName'=>$stage.' IRRI-6','bags'=>480,'payableWeightKg'=>24000,'weighbridgeWeightKg'=>24000,'grossRatePerKg'=>100,'katPaisaPerKg'=>0,'truck'=>'TRUCK-'.$n,'pohanch'=>'P-'.$no.'-'.$n,'container'=>'ABCD12345'.$n.'0','emptyBagWeightGrams'=>50];
 $s['journals'][$jid]=['id'=>$jid,'entity'=>'TTI','date'=>$date,'reference'=>$meta['pohanch'],'totalDebit'=>2400000,'totalCredit'=>2400000,'meta'=>$meta,'lines'=>[]];
 $s['events'][$eid]=['id'=>$eid,'eventType'=>'COMMODITY_RECEIPT_ACCEPTED','entity'=>'TTI','sourceKey'=>$key,'journalId'=>$jid,'status'=>'Accepted'];
 }
}
$s['localSalesPaymentCandidates']['foreign-payment']=['id'=>'foreign-payment','entity'=>'BRM','soda'=>'FOREIGN','party'=>'Foreign party','amount'=>100,'paymentDate'=>'2026-09-29','status'=>'Pending Accounts Approval'];$s['localSalesCandidates']['foreign-sale']=['entity'=>'BRM','journalId'=>'foreign-journal','loadedKg'=>100,'saleDate'=>'2026-09-29','product'=>'Ready Rice'];$s['inventoryCostRates']['foreign-rate']=['entity'=>'BRM','product'=>'Ready Rice','effectiveFrom'=>'2026-01-01','status'=>'Active','costPerKg'=>100,'inventoryAccount'=>'1320'];file_put_contents(TT_DATA_DIR.'/accounts.json',json_encode($s));$export=['customers'=>[['id'=>'fixture-customer','name'=>'Fixture Customer']],'contracts'=>[['ref'=>'FIXTURE-CONTRACT','seller'=>'TTI','customerId'=>'fixture-customer','pol'=>'Karachi','podPort'=>'Jeddah']],'shipments'=>[]];
foreach([['FIXTURE-SHIP','FIXTURE-LP',10],['FIXTURE-SHIP-2','FIXTURE-LP',5],['FIXTURE-EMPTY','EMPTY-LP',0]] as [$sid,$lp,$count]){$actual=[];for($i=1;$i<=$count;$i++)$actual[]=['number'=>$sid.'-CONTAINER-'.$i];$export['shipments'][]=['id'=>$sid,'kind'=>'lot','seller'=>'TTI','contractRef'=>'FIXTURE-CONTRACT','lotId'=>$sid.'-LOT','bl'=>['bookingNumber'=>$lp,'blNo'=>'FIXTURE-BL'],'commercial'=>['invoiceNo'=>'FIXTURE-CI'],'loadingPlan'=>['portOfLoading'=>'Karachi','shippingLine'=>'Fixture Line'],'millActuals'=>$actual];}
$export['contracts'][]=['ref'=>'FIXTURE-CONTRACT-2','seller'=>'TTI','customerId'=>'fixture-customer','pol'=>'Karachi','podPort'=>'Jeddah'];$export['shipments'][1]['contractRef']='FIXTURE-CONTRACT-2';
$export['contracts'][]=['ref'=>'PORT-A','seller'=>'TTI','podPort'=>'Jeddah'];$export['contracts'][]=['ref'=>'PORT-B','seller'=>'TTI','podPort'=>'Dubai'];foreach(['A','B']as$port)$export['shipments'][]=['id'=>'PORT-SHIP-'.$port,'kind'=>'lot','seller'=>'TTI','contractRef'=>'PORT-'.$port,'loadingProgrammeNo'=>'PORT-CONFLICT','millActuals'=>[['number'=>'PORT-'.$port]]];
$export['contracts'][]=['ref'=>'FOREIGN-CONTRACT','seller'=>'BRM'];$export['shipments'][]=['id'=>'FOREIGN-SHIP','kind'=>'lot','seller'=>'BRM','contractRef'=>'FOREIGN-CONTRACT','loadingProgrammeNo'=>'FOREIGN-LP','millActuals'=>[['number'=>'FOREIGN1']]];
$export['contracts'][]=['ref'=>'TG-CONTRACT','seller'=>'TG'];$export['shipments'][]=['id'=>'TG-SHIP','kind'=>'lot','seller'=>'TG','contractRef'=>'TG-CONTRACT','loadingProgrammeNo'=>'TG-LP','millActuals'=>[['number'=>'TG1']]];
file_put_contents(TT_DATA_DIR.'/operations.json',json_encode(['values'=>['transtrade_export_v3_operational'=>json_encode($export)]]));file_put_contents(__DIR__.'/credentials.json',json_encode(['password'=>$pw]));
'''
def run():
 root=Path(tempfile.mkdtemp(prefix='tti-ready-bill-'));server=None;log=None
 try:
  app=root/'repo';shutil.copytree(SOURCE,app,ignore=shutil.ignore_patterns('.git','node_modules','test-results','playwright-report'))
  sessions=root/'sessions';sessions.mkdir();(root/'seed.php').write_text(SEED)
  subprocess.run(['php','-d',f'session.save_path={sessions}',str(root/'seed.php')],check=True,capture_output=True)
  with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
  base=f'http://127.0.0.1:{port}';log=open(root/'server.log','w+')
  env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
  server=subprocess.Popen(['php','-d',f'session.save_path={sessions}','-S',f'127.0.0.1:{port}','-t',str(app)],stdout=log,stderr=log,env=env);time.sleep(.3)
  # Exact identity and gram/kg bag-order matching, including a mismatched shipment.
  calc=r'''require $argv[1];$m=['container'=>'ABCD1234560','shipmentId'=>'L1','sourceSodaId'=>'PS1'];$v=['tt35exload'=>json_encode([['container'=>'ABCD1234560','shipmentId'=>'L1','instructionId'=>1]]),'tt40exinstructions'=>json_encode([['id'=>1,'sourceSodaId'=>'PS1','bagTare'=>'0.12 kg']])];if(tt_bill_bag_defaults($m,$v)['emptyBagWeightGrams']!==120.0)throw new Exception('Bag kg conversion');$m['shipmentId']='L2';if(tt_bill_bag_defaults($m,$v)['emptyBagWeightGrams']!==0)throw new Exception('Wrong-shipment bag tare');'''
  subprocess.run(['php','-r',calc,str(app/'api/commodity_bill_calculation.php')],check=True,capture_output=True)
  password=json.loads((root/'credentials.json').read_text())['password'];clients={};tokens={}
  for username in ['billqa','billview','fixtureowner']:
   client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));clients[username]=client
   html=client.open(base+'/login.php').read().decode();tokens[username]=re.search(r'name="csrf" value="([^"]+)"',html).group(1)
   client.open(urllib.request.Request(base+'/login.php',data=urllib.parse.urlencode({'csrf':tokens[username],'username':username,'password':password}).encode())).read()
  def request(path,body=None,user='billqa'):
   if body is not None:body={**body,'csrf':tokens[user]}
   req=urllib.request.Request(base+path,data=json.dumps(body).encode() if body is not None else None,headers={'Content-Type':'application/json'} if body is not None else {})
   try:r=clients[user].open(req);return r.status,json.loads(r.read())
   except urllib.error.HTTPError as e:return e.code,json.loads(e.read())
  status,lookup=request('/api/commodity_lookup.php?entity=TTI');assert status==200 and len(lookup['receipts'])==8
  assert lookup['receipts'][0]['emptyBagWeightGrams']==50 and len(lookup['sodas'])==4
  assert request('/api/commodity_lookup.php?entity=BRM')[0]==403
  assert request('/api/commodity_lookup.php')[0]==403
  status,local=request('/api/local_sales_payments.php?entity=TTI');assert status==200 and local['pending']==[]
  assert request('/api/local_sales_payments.php',{'entity':'TTI','action':'reject_payment','paymentId':'foreign-payment','note':'fixture'})[0]==403
  status,cost=request('/api/local_sales_costing.php',{'entity':'TTI','action':'post_waiting'});assert status==200,(status,cost)
  assert not json.loads((root/'transtrade_private/accounts.json').read_text())['localSalesCandidates']['foreign-sale'].get('costJournalId'),'Cross-company cost posting'
  transport={'entity':'TTI','action':'save_transport_bill','vendor':'JJ','invoiceNo':'TRANSPORT-FIXTURE','billDate':'2026-09-30','lines':[{'loadingProgrammeNo':'FIXTURE-LP','containers':3,'from':'Karachi','to':'Jeddah','rate':38000,'extras':0}], 'adjustments':[{'description':'Fixture addition','type':'ADD','amount':1500},{'description':'Fixture deduction','type':'DEDUCT','amount':300}]}
  assert request('/api/accounts_workflows_v1.php',transport)[0]==422,'Broker must not be accepted as transporter'
  transport['vendor']='Fixture Transport';status,saved=request('/api/accounts_workflows_v1.php',transport);assert status==200,(status,saved)
  status,register=request('/api/accounts_workflows_v1.php?entity=TTI&section=transport');assert status==200 and register['bills'][0]['total']==115200
  assert saved['bill']['id']==register['bills'][0]['id'] and saved['bill']['postingJournalIds']
  assert request('/api/accounts_workflows_v1.php',transport)[0]==409,'Transport hard duplicate protection'
  status,shipments=request('/api/accounts_shipment_lookup.php?entity=TTI&q=FIXTURE');assert status==200 and any(x['id']=='FIXTURE-SHIP' and x['loadingProgramme']=='FIXTURE-LP' for x in shipments['rows'])
  # Source-backed posting succeeds without Accounts programme registration, including legacy booking alias.
  assert register['loadingProgrammes'][0]['source']=='EXPORTS_MILLING'
  for sid,lp,count in [('FOREIGN-SHIP','FOREIGN-LP',1),('TG-SHIP','TG-LP',1),('FIXTURE-EMPTY','EMPTY-LP',1),('FIXTURE-SHIP','WRONG-LP',1),('FIXTURE-SHIP','FIXTURE-LP',16),('PORT-SHIP-A','PORT-CONFLICT',1),('FIXTURE-SHIP','FIXTURE-LP',1.5)]:
   bad={**transport,'invoiceNo':'REJECT-'+sid+lp+str(count),'shipmentId':sid,'lines':[{**transport['lines'][0],'shipmentId':sid,'loadingProgrammeNo':lp,'containers':count}]}
   assert request('/api/accounts_workflows_v1.php',bad)[0]==422,(sid,lp,count)
  multi={**transport,'invoiceNo':'MULTI-FIXTURE','shipmentId':'FIXTURE-SHIP','adjustments':[],'lines':[{'shipmentId':'FIXTURE-SHIP','loadingProgrammeNo':'FIXTURE-LP','containers':1,'from':'Karachi','to':'Jeddah','rate':38000,'adjustments':[{'type':'ADD','description':'Kanta','amount':1000}]},{'shipmentId':'FIXTURE-SHIP-2','loadingProgrammeNo':'FIXTURE-LP','containers':1,'from':'Karachi','to':'Jeddah','rate':38000,'adjustments':[{'type':'DEDUCT','description':'Discount','amount':500}]}]}
  status,multi_saved=request('/api/accounts_workflows_v1.php',multi);assert status==200,(status,multi_saved)
  status,multi_register=request('/api/accounts_workflows_v1.php?entity=TTI&section=transport');multi_row=next(x for x in multi_register['bills'] if x['invoiceNo']=='MULTI-FIXTURE');assert multi_row['total']==76500 and [x['amount'] for x in multi_row['lines']]==[39000,37500] and len(multi_row['shipmentIds'])==2 and [x['contractRef'] for x in multi_row['lines']]==['FIXTURE-CONTRACT','FIXTURE-CONTRACT-2']
  assert request('/api/accounts_workflows_v1.php',multi)[0]==409
  excess={**multi,'invoiceNo':'SHARED-PROGRAMME-EXCESS','lines':[{**line,'containers':6} for line in multi['lines']]};assert request('/api/accounts_workflows_v1.php',excess)[0]==422,'Shared programme must enforce aggregate availability across both contracts'
  status,amended=request('/api/accounts_workflows_v1.php',{**multi,'id':multi_saved['bill']['id'],'reason':'Fixture unchanged amendment'});assert status==200,(status,amended)
  assert request('/api/accounts_workflows_v1.php',multi,user='billview')[0]==403
  status,profit=request('/api/shipment_profitability.php?entity=TTI');assert status==200 and sum(x['transport'] for x in profit['rows'])==191700,(status,profit)
  stored=json.loads((root/'transtrade_private/accounts.json').read_text());assert stored['supplierBills'][multi_saved['bill']['id']]['supplierPayableTotal']==76500;assert len(stored['supplierBills'][multi_saved['bill']['id']]['postingJournalIds'])==1,'Unchanged amendment must not duplicate journal'
  original=(root/'transtrade_private/operations.json').read_bytes()
  # A suggested route rate may be changed without the removed reason field.
  changed_rate={**multi,'invoiceNo':'RATE-WITHOUT-REASON','lines':[{**multi['lines'][0],'rate':39000,'remarks':'','adjustments':[]}],'remarks':''}
  status,changed=request('/api/accounts_workflows_v1.php',changed_rate);assert status==200,(status,changed)
  status,rates=request('/api/accounts_workflows_v1.php?entity=TTI&section=transport');assert status==200
  assert next(row for row in rates['bills'] if row['id']==changed['bill']['id'])['total']==39000 and (root/'transtrade_private/operations.json').read_bytes()==original
  def payload(no,keys=None,final=2398200):
   return {'action':'verify_bill','entity':'TTI','relationshipType':'SUPPLIER','relationshipName':'Indus Rice','billDate':'2026-09-29','sourceKeys':keys or [f'EXMILL|{no}|1'],'broker':'JJ','billNo':'FIXTURE-'+no,'finalCommodityValue':final,'brokerageRate':5,'brokerageBasis':'PER_100_KG','brokerageWhtPercent':15,'readyRiceCalculation':{'bags':480,'emptyBagWeightGrams':50,'kantaRate':600},'adjustmentLines':[]}
  p=payload('26001');p['finalCommodityValue']=1
  assert request('/api/commodity_bills.php',p)[0]==409,'Tampered total must not post'
  p=payload('26001');p['readyRiceCalculation']['emptyBagWeightGrams']=50000
  assert request('/api/commodity_bills.php',p)[0]==422
  assert request('/api/commodity_bills.php',payload('26001'),user='billview')[0]==403
  p=payload('26001',['EXMILL|26001|1','EXMILL|26001|2'],4796400);p['readyRiceCalculation']['bags']=960;p['readyRiceCalculation']['loadingRatePerBag']=8;p['readyRiceCalculation']['loadingBags']=1;p['finalCommodityValue']=4804080
  status,result=request('/api/commodity_bills.php',p);assert status==200,(status,result)
  bill=result['bill'];assert bill['calculation']['loadingAmount']==7680 and bill['calculation']['loadingBags']==960 and bill['finalCommodityValue']==4804080 and bill['brokerageGross']==2397.6 and bill['brokerageWithholding']==359.64
  assert bill['supplierPayableTotal']==4806117.96 and not result['warning']
  assert result['journal']['totalDebit']==result['journal']['totalCredit']
  commodity=[a for a in bill['receiptAllocations'] if a['component']=='COMMODITY'];broker=[a for a in bill['receiptAllocations'] if a['component']=='BROKERAGE']
  assert len(commodity)==2 and all(a['payee']=='Indus Rice' for a in commodity) and all(a['payee']=='JJ' for a in broker)
  assert [a['dueDate'] for a in commodity]==['2026-10-11','2026-10-12']
  assert request('/api/commodity_bills.php',p)[0]==409,'Duplicate posting must be blocked'
  profile=json.loads((root/'transtrade_private/auth.json').read_text())['masters']['business_parties'][-1]['values'][12];profile=json.loads(profile)
  assert profile['buying'][-1]['effectiveFrom']=='2026-09-29' and profile['selling'][0]['amount']==9
  values=['HTTP Fixture Transport','','Transporter','','','','','','','','Active','','']
  status,party=request('/api/masters.php',{'action':'create','type':'business_parties','values':values});assert status==200,(status,party)
  created=[m for m in party['masters']['business_parties'] if m['id']==party['id']][0];assert created['values'][2]=='Transporter';profile=json.loads(created['values'][12]);assert not profile.get('buying') and not profile.get('selling')
  values[0]='Malformed Profile Fixture';values[12]='invalid-json';assert request('/api/masters.php',{'action':'create','type':'business_parties','values':values})[0]==422,'Do not weaken malformed brokerage validation'
  p=payload('26003');p['relationshipType']='BROKER';p['relationshipName']='JJ'
  assert request('/api/commodity_bills.php',p)[0]==200,'Broker-only commodity payee'
  p=payload('26004');p['broker']='';p['brokerageRate']=0
  status,result=request('/api/commodity_bills.php',p);assert status==200 and result['bill']['brokerageGross']==0
  p=payload('26002',['POHANCH|26002|1','POHANCH|26002|2'],4800000);p.pop('readyRiceCalculation')
  assert request('/api/commodity_bills.php',p)[0]==422,'Raw cannot combine Pohanch'
  p['sourceKeys']=['POHANCH|26002|1'];p['finalCommodityValue']=2400000
  status,result=request('/api/commodity_bills.php',p);assert status==200 and result['bill']['brokerageGross']==1200 and result['bill']['dueDateFrom']=='2026-09-13'
  assert (root/'transtrade_private/operations.json').read_bytes()==original,'Milling must remain unchanged'
  # Shared non-commodity payments preserve native registers and allocate oldest bill first.
  storefile=root/'transtrade_private/accounts.json';fixture=json.loads(storefile.read_text())
  fixture['bagSupplierBills']={'BATCH-BAG':{'id':'BATCH-BAG','entity':'TTI','status':'Posted','supplier':'Fixture Vendor','sellerInvoice':'BAG-1','sellerInvoiceDate':'2026-07-01','totalAmount':100,'poNo':'PO-1'}}
  fixture['otherPurchases']={'BATCH-OTHER':{'id':'BATCH-OTHER','entity':'TTI','settlement':'CREDIT','supplier':'Fixture Vendor','invoiceNo':'OTHER-1','invoiceDate':'2026-07-02','amount':200}}
  fixture['journals']['BATCH-PAYABLE']={'id':'BATCH-PAYABLE','entity':'TTI','date':'2026-07-01','status':'Posted','reference':'BATCH-BILLS','narration':'Fixture vendor bills','meta':{},'lines':[{'account':'2140','debit':0,'credit':300,'supplier':'Fixture Vendor'}]}
  fixture['journals']['OPEN-CASH']={'id':'OPEN-CASH','entity':'TG','date':'2026-07-01','status':'Posted','lines':[{'account':'1120','debit':1000,'credit':0},{'account':'3010','debit':0,'credit':1000}],'reference':'Opening','narration':'QA opening cash','totalDebit':1000,'totalCredit':1000}
  fixture['tgLiabilities']={k:{'id':k,'counterparty':'TG Fixture Vendor','currency':'AED','nativeAmount':amount,'date':date,'recognitionRate':1,'payableAccount':'2140','reference':k} for k,amount,date in [('TG-BILL-1',100,'2026-07-01'),('TG-BILL-2',200,'2026-07-02')]}
  storefile.write_text(json.dumps(fixture))
  pay={'action':'post_bill_payment','entity':'TTI','date':'2026-09-30','supplier':'Fixture Vendor','amount':150,'paymentMode':'CASH','requestKey':'payment-fixture-0001','selectedBills':[]}
  status,result=request('/api/supplier_settlements.php',pay);assert status==200,(status,result)
  assert [a['amount'] for a in result['result']['allocations']]==[100,50]
  jid=result['result']['journalId'];snapshot=json.loads(storefile.read_text());assert snapshot['bagSupplierPayments'] and snapshot['otherSupplierPayments']
  assert request('/api/supplier_settlements.php',pay)[1]['result']['journalId']==jid,'Retry must not duplicate payment'
  assert len(json.loads(storefile.read_text())['journals'])==len(snapshot['journals'])
  assert request('/api/supplier_settlements.php',{**pay,'amount':151})[0]==409,'Changed posted draft must be rejected'
  assert request('/api/supplier_settlements.php',{**pay,'requestKey':'payment-fixture-0002','amount':151})[0]==422,'Excess must not silently over-settle'
  status,out=request('/api/supplier_settlements.php?entity=TTI');assert status==200;remaining=[r for r in out['payables'] if r['supplier']=='Fixture Vendor'];assert len(remaining)==1 and remaining[0]['outstanding']==150
  assert request('/api/supplier_settlements.php',{**pay,'requestKey':'payment-fixture-view','amount':1},user='billview')[0]==403
  tgpay={'action':'post_payment','guidedPayment':True,'paymentType':'LIABILITY','counterparty':'TG Fixture Vendor','currency':'AED','amountNative':150,'paymentMode':'CASH','date':'2026-09-30','requestKey':'tg-payment-fixture-001','selectedBills':[]}
  assert request('/api/tg_bank_transactions.php')[0]==403,'TTI operator must not read TG books'
  status,tg=request('/api/tg_bank_transactions.php',tgpay,user='fixtureowner');assert status==200,(status,tg)
  assert [r['amountNative'] for r in tg['transaction']['liabilityAllocations']]==[100,50]
  assert any(l['account']=='1120' and l['credit']==150 for l in tg['journal']['lines'])
  assert request('/api/tg_bank_transactions.php',tgpay,user='fixtureowner')[1]['transaction']['id']==tg['transaction']['id']
  status,tg=request('/api/tg_bank_transactions.php',{**tgpay,'requestKey':'tg-payment-fixture-002','amountNative':25,'paymentMode':'THIRD_PARTY','payer':'External payer'},user='fixtureowner');assert status==200,(status,tg)
  assert any(l['account']=='2520' and l['credit']==25 for l in tg['journal']['lines']) and not any(l['account'] in ['1110','1120'] for l in tg['journal']['lines'])
  status,ledger=request('/api/accounts_ledger_browser.php?entity=TTI&category=supplier&party=Fixture%20Vendor&from=2026-09-01&to=2026-09-30');assert status==200 and ledger['opening']==-300 and ledger['closing']==-150,(status,ledger)
  assert ledger['rows'][0]['party']=='Fixture Vendor'
  status,extra=request('/api/supplier_settlements.php',{**pay,'requestKey':'payment-fixture-advance','amount':200,'allowAdvance':True});assert status==200,(status,extra)
  assert extra['result']['advanceAmount']==50 and extra['result']['netPayment']==200
  state=json.loads(storefile.read_text());assert state['supplierAdvances'][extra['result']['advanceId']]['availableAmount']==50
  # Cheque number/date use the existing issue/clear lifecycle and remain searchable.
  fixture=json.loads(storefile.read_text());fixture['otherPurchases']['BANK-CHEQUE-BILL']={'id':'BANK-CHEQUE-BILL','entity':'TTI','settlement':'CREDIT','supplier':'Cheque Fixture','invoiceNo':'CHEQUE-BILL','invoiceDate':'2026-07-03','amount':300};storefile.write_text(json.dumps(fixture))
  cheque={'action':'post_bill_payment','entity':'TTI','date':'2026-09-30','supplier':'Cheque Fixture','amount':100,'paymentMode':'BANK','bankAccountId':'qa-bank-default','bankPaymentMethod':'CHEQUE','chequeNo':'CQ-123','reference':'CQ-123','chequeDate':'2026-10-05','requestKey':'payment-cheque-fixture-0001','selectedBills':[],'narration':'Cheque · Test services'}
  status,cq=request('/api/supplier_settlements.php',cheque);assert status==200,(status,cq)
  assert cq['result']['chequeStatus']=='Issued' and cq['result']['chequeNo']=='CQ-123'
  cqid=cq['result']['journalId'];state=json.loads(storefile.read_text());journal=state['journals'][cqid]
  assert journal['meta']['chequeNo']=='CQ-123' and journal['meta']['chequeDate']=='2026-10-05'
  assert any(l['account']=='2180' and l['credit']==100 for l in journal['lines']) and not any(l['account']=='1110' for l in journal['lines'])
  assert request('/api/supplier_settlements.php',cheque)[1]['result']['journalId']==cqid
  assert request('/api/supplier_settlements.php',{**cheque,'chequeNo':'CQ-124'})[0]==409
  assert request('/api/supplier_settlements.php',{**cheque,'requestKey':'payment-cheque-fixture-invalid','chequeNo':''})[0]==422
  status,cqsearch=request('/api/accounts_search.php?entity=TTI&q=CQ-123');assert status==200 and any(r['data'].get('id')==cqid for r in cqsearch['results'])
  number=cqid.rsplit('-',1)[-1].lstrip('0');status,short=request('/api/accounts_search.php?entity=TTI&q='+number);assert status==200 and any(r['data'].get('id')==cqid for r in short['results'])
  status,shortledger=request('/api/accounts_ledger_browser.php?entity=TTI&from=2026-07-01&to=2026-10-05&q='+number);assert status==200 and any(r['voucher']==cqid for r in shortledger['rows'])
  online={**cheque,'amount':50,'requestKey':'payment-online-fixture-0001','bankPaymentMethod':'ONLINE_BANKING','chequeNo':'','chequeDate':'','reference':'ONLINE-123','narration':'Online Banking · Services settlement'}
  status,paid=request('/api/supplier_settlements.php',online);assert status==200,(status,paid)
  assert paid['result']['chequeNo']=='' and paid['result']['bankPaymentMethod']=='ONLINE_BANKING'
  state=json.loads(storefile.read_text());assert 'ONLINE BANKING' in state['journals'][paid['result']['journalId']]['narration']
  assert request('/api/supplier_settlements.php',online)[1]['result']['journalId']==paid['result']['journalId']
  status,cleared=request('/api/supplier_settlements.php',{'action':'clear_supplier_cheque','entity':'TTI','date':'2026-10-05','settlementId':cq['result']['id'],'bankReference':'CLEARED-123'});assert status==200,(status,cleared)
  expense={'action':'pay_general_expense','entity':'TTI','paymentDate':'2026-09-30','paymentAccountId':'qa-bank-default','expenseAccount':'6900','location':'OFFICE','payee':'Expense QA','amount':25,'description':'Fixture expense','reference':'ORIGINAL-INVOICE-99','requestKey':'expense-bank-tracking-fixture','bankPaymentMethod':'CHEQUE','chequeNo':'EXP-CQ-777','chequeDate':'2026-09-30','paymentNarration':'User cheque narration'}
  status,posted=request('/api/expenses_v1.php',expense);assert status==200,(status,posted)
  state=json.loads(storefile.read_text());journal=state['journals'][posted['result']['journalId']];assert journal['meta']['chequeNo']=='EXP-CQ-777' and journal['reference']=='ORIGINAL-INVOICE-99' and 'USER CHEQUE NARRATION' in journal['narration']
  status,tracked=request('/api/accounts_search.php?entity=TTI&q=777');assert status==200 and any(r['data'].get('id')==posted['result']['journalId'] for r in tracked['results'])
  status,tracked=request('/api/accounts_ledger_browser.php?entity=TTI&from=2026-09-01&to=2026-09-30&q=777');assert status==200 and any(r['voucher']==posted['result']['journalId'] for r in tracked['rows'])
  assert request('/api/expenses_v1.php',expense)[1]['result']['journalId']==posted['result']['journalId']
  assert request('/api/expenses_v1.php',{**expense,'chequeNo':'EXP-CQ-778'})[0]==409
  print('Cheque lifecycle, bank method metadata, idempotent retries and short reference search passed')
  print('Non-commodity payment FIFO, partial, registers, retry, TG cash/third-party and entity isolation passed')
  for name,role in [('Fixture Fumigation','Fumigation'),('Fixture Forwarder','Freight Forwarder')]:
   status,created=request('/api/masters.php',{'action':'create','type':'business_parties','values':[name,'',role,'','','','','','','','Active','','']});assert status==200,(status,created)
  sections=[{'shipmentId':sid,'billLines':[{'description':'Service','type':'ADD','amount':amount},{'description':'Discount','type':'DEDUCT','amount':10}]} for sid,amount in [('FIXTURE-SHIP',100),('FIXTURE-SHIP-2',200)]]
  service={'action':'save_service_bill','entity':'TTI','kind':'FUMIGATION','vendor':'Fixture Fumigation','invoiceNo':'MULTI-SERVICE','billDate':'2026-09-30','shipmentSections':sections}
  status,recorded=request('/api/accounts_workflows_v1.php',service);assert status==200,(status,recorded)
  state=json.loads(storefile.read_text());source=state['supplierBills'][recorded['bill']['id']]['sourceRecord'];assert len(source['shipmentSections'])==2 and source['amount']==280
  freight={'action':'save_freight_bill','entity':'TTI','vendor':'Fixture Forwarder','invoiceNo':'MULTI-FREIGHT','billDate':'2026-09-30','exchangeRate':280,'shipmentSections':[{'shipmentId':sid,'containerCount':1,'charges':[{'charge':'Freight','currency':'USD','basis':'PER_CONTAINER','billedRate':100,'acceptedRate':90}]} for sid in ['FIXTURE-SHIP','FIXTURE-SHIP-2']]}
  status,recorded=request('/api/accounts_workflows_v1.php',freight);assert status==200,(status,recorded)
  state=json.loads(storefile.read_text());source=state['supplierBills'][recorded['bill']['id']]['sourceRecord'];assert len(source['shipmentSections'])==2 and source['acceptedLiability']==50400 and source['disputedTotal']==5600
  assert request('/api/accounts_workflows_v1.php',{**freight,'invoiceNo':'BAD-DUP-SHIP','shipmentSections':[freight['shipmentSections'][0]]*2})[0]==422
  assert request('/api/accounts_workflows_v1.php',{**service,'invoiceNo':'BAD-FOREIGN-SHIP','shipmentSections':[{'shipmentId':'FOREIGN-SHIP','billLines':sections[0]['billLines']}]})[0]==422
  status,profit=request('/api/shipment_profitability.php?entity=TTI');assert status==200
  rows={r['key']:r for r in profit['rows']};assert rows['FIXTURE-CONTRACT|FIXTURE-SHIP-LOT']['fumigation']==90 and rows['FIXTURE-CONTRACT-2|FIXTURE-SHIP-2-LOT']['fumigation']==190
  assert rows['FIXTURE-CONTRACT|FIXTURE-SHIP-LOT']['freight']==25200 and rows['FIXTURE-CONTRACT-2|FIXTURE-SHIP-2-LOT']['freight']==25200
  # Freight invoice arithmetic: B/L once, container additions by count, signed deductions.
  detailed={**freight,'invoiceNo':'FREIGHT-USD-BASIS','shipmentSections':[{'shipmentId':'FIXTURE-SHIP','containerCount':3,'charges':[{'charge':name,'currency':'USD','basis':basis,'billedRate':rate,'acceptedRate':rate} for name,basis,rate in [('Freight','PER_CONTAINER',1000),('B/L charges','PER_BL',50),('Handling','PER_CONTAINER',25),('Documentation','PER_BL',10),('Discount','PER_BL',-5)]]}]}
  status,recorded=request('/api/accounts_workflows_v1.php',detailed);assert status==200,(status,recorded)
  state=json.loads(storefile.read_text());source=state['supplierBills'][recorded['bill']['id']]['sourceRecord'];assert source['billedTotal']==876400 and source['acceptedLiability']==876400 and source['freightAcceptedUsd']==3000
  invalid={**detailed,'invoiceNo':'BAD-BASIS','shipmentSections':[{'shipmentId':'FIXTURE-SHIP','containerCount':3,'charges':[{'charge':'Wrong','currency':'USD','basis':'UNKNOWN','billedRate':1,'acceptedRate':1}]}]};assert request('/api/accounts_workflows_v1.php',invalid)[0]==422
  assert request('/api/accounts_workflows_v1.php',{**detailed,'invoiceNo':'NO-FX','exchangeRate':0})[0]==422
  status,filtered=request('/api/accounts_shipment_lookup.php?scope=freight&entity=TTI&q=FIXTURE&customer=Fixture%20Customer');assert status==200 and all(row['customer']=='Fixture Customer' for row in filtered['rows'])
  assert request('/api/accounts_shipment_lookup.php?scope=freight&entity=TTI&q=FIXTURE&customer=Someone%20Else')[1]['rows']==[]
  agreement={'action':'save_freight_agreement','entity':'TTI','shipmentId':'FIXTURE-SHIP','contractRef':'FIXTURE-CONTRACT','customerName':'Fixture Customer','shippingLine':'Fixture Line','forwarder':'','destinationPort':'Jeddah','fromPort':'Karachi Port, Pakistan','loadingProgrammeNo':'NEW-PROGRAMME','containerCount':3,'ratePerContainer':1000,'dateAgreed':'2026-09-30'}
  status,agreed=request('/api/accounts_workflows_v1.php',agreement);assert status==200 and agreed['exportsSync']=='Complete',(status,agreed)
  operations=json.loads((root/'transtrade_private/operations.json').read_text());export=json.loads(operations['values']['transtrade_export_v3_operational']);lot=next(x for x in export['shipments'] if x['id']=='FIXTURE-SHIP');assert lot['loadingProgrammeNo']=='NEW-PROGRAMME' and len(lot['millActuals'])==10 and lot['bl']['blNo']=='FIXTURE-BL'
  retry=request('/api/accounts_workflows_v1.php',agreement);assert retry[0]==200 and retry[1]['agreement']['id']==agreed['agreement']['id']
  assert request('/api/accounts_workflows_v1.php',{**agreement,'loadingProgrammeNo':'PORT-CONFLICT'})[0]==422
  assert request('/api/accounts_workflows_v1.php',{**agreement,'fromPort':'Other port'})[0]==422
  assert request('/api/accounts_workflows_v1.php',{**agreement,'shippingLine':''})[0]==422
  # New-entry rules consume only their own expense category, never erase history.
  status,available=request('/api/accounts_shipment_lookup.php?entity=TTI&billKind=FREIGHT&q=FIXTURE-SHIP');assert status==200 and available['rows']==[]
  status,original_lookup=request('/api/accounts_shipment_lookup.php?entity=TTI&q=FIXTURE-SHIP');assert status==200 and any(r['id']=='FIXTURE-SHIP' for r in original_lookup['rows'])
  status,other_kind=request('/api/accounts_shipment_lookup.php?entity=TTI&billKind=INSPECTION&q=FIXTURE-SHIP');assert status==200 and any(r['id']=='FIXTURE-SHIP' for r in other_kind['rows'])
  before_stale=storefile.read_bytes();assert request('/api/accounts_workflows_v1.php',{**detailed,'invoiceNo':'STALE-NEW-FREIGHT','newEntry':True})[0]==409;assert storefile.read_bytes()==before_stale
  status,search=request('/api/accounts_search.php?entity=TTI&q='+recorded['bill']['postingJournalIds'][0]);assert status==200 and any(r.get('amendRecord',{}).get('id')==recorded['bill']['id'] for r in search['results'] if r.get('amendRecord'))
  edit=next(r['amendRecord'] for r in search['results'] if r.get('amendRecord') and r['amendRecord'].get('id')==recorded['bill']['id'])
  amended_payload={**detailed,'id':edit['id'],'amendment':True,'updatedAt':edit['updatedAt'],'editVersion':edit['editVersion'],'reason':'Fixture controlled amendment','remarks':'A correction with the same original shipment link'}
  assert request('/api/accounts_workflows_v1.php',amended_payload)[0]==200
  # Hash protects even two edits within the same timestamp second.
  assert request('/api/accounts_workflows_v1.php',amended_payload)[0]==409
  status,eligible=request('/api/accounts_shipment_lookup.php?entity=TTI&scope=freight_agreed&customer=Fixture%20Customer');assert status==200 and all(r['kind']=='contract' for r in eligible['rows'])
  contract=next(r for r in eligible['rows'] if r['contract']=='FIXTURE-CONTRACT');assert contract['plannedContainers']==10 and contract['remainingContainers']==7
  tranche={**agreement,'shipmentId':'','loadingProgrammeNo':'','containerCount':2,'contractSelection':True,'requestKey':'fixture-partial-unique'}
  status,partial=request('/api/accounts_workflows_v1.php',tranche);assert status==200,(status,partial)
  status,retry=request('/api/accounts_workflows_v1.php',tranche);assert status==200 and retry['agreement']['id']==partial['agreement']['id']
  status,eligible=request('/api/accounts_shipment_lookup.php?entity=TTI&scope=freight_agreed&customer=Fixture%20Customer');assert next(r for r in eligible['rows'] if r['contract']=='FIXTURE-CONTRACT')['remainingContainers']==5
  assert request('/api/accounts_workflows_v1.php',{**tranche,'containerCount':6,'requestKey':'fixture-too-many'})[0]==409
  assert request('/api/accounts_workflows_v1.php',{**tranche,'containerCount':5,'requestKey':'fixture-complete'})[0]==200
  status,eligible=request('/api/accounts_shipment_lookup.php?entity=TTI&scope=freight_agreed&customer=Fixture%20Customer');assert all(r['contract']!='FIXTURE-CONTRACT' for r in eligible['rows'])
  assert request('/api/accounts_workflows_v1.php',{**tranche,'contractRef':'FIXTURE-CONTRACT-2','containerCount':5,'requestKey':'fixture-complete-2'})[0]==200
  status,eligible=request('/api/accounts_shipment_lookup.php?entity=TTI&scope=freight_agreed');assert 'Fixture Customer' not in eligible['customers']
  print('Multi-shipment service/freight invoice, dispute, company and per-shipment profitability tests passed')
  if os.environ.get('TT_QA_BROWSER')=='1':
   # Reset only this disposable fixture for browser entry, never production.
   subprocess.run(['php','-d',f'session.save_path={sessions}',str(root/'seed.php')],check=True,capture_output=True)
   password=json.loads((root/'credentials.json').read_text())['password']
   from playwright.sync_api import sync_playwright
   with sync_playwright() as pw:
    browser=pw.chromium.launch();page=browser.new_page(viewport={'width':1280,'height':1000})
    page.goto(base+'/login.php');page.locator('[name=username]').fill('billqa');page.locator('[name=password]').fill(password);page.get_by_role('button',name='Sign in',exact=True).click();page.wait_for_url('**/accounts/index.php')
    entity_errors=[]
    def check_response(response):
     if '/api/' in response.url and response.status>=400:
      try:
       data=response.json()
       if data.get('error')=='An authorized legal entity is required.':entity_errors.append(response.url)
      except Exception:pass
    page.on('response',check_response)
    page.locator('#ttChangeCompanyDesk').click();page.locator('.tt-company-choice[data-entity="TTI"]').click();page.wait_for_timeout(250)
    page.locator('[data-tt-area="commodity"]').click()
    page.get_by_role('button',name=re.compile('Bill Posting')).click()
    page.locator('#ttsbSupplier').wait_for()
    page.locator('#ttsbSupplier').fill('Indus Rice');page.locator('#ttsbSupplier').press('Tab')
    page.locator('#ttsbSoda').select_option('26001',force=True);page.locator('#ttsbTruck').select_option('EXMILL|26001|1',force=True)
    page.locator('#ttsbBillNo').fill('FULL-SCREEN-PRESERVED')
    page.locator('#ttsbLoadingRate').fill('8')
    page.wait_for_timeout(2000)
    assert page.locator('#ttsbBillNo').input_value()=='FULL-SCREEN-PRESERVED','Background mount reset bill entry'
    assert page.locator('#ttsbLoadingTotal').input_value()=='3840.00'
    assert page.locator('#ws-purchases').evaluate("el=>el.classList.contains('active')"),'Form navigated away during entry'
    assert not entity_errors,entity_errors
    evidence=Path(os.environ.get('RUNNER_TEMP',str(root)))/'inventory-evidence';evidence.mkdir(exist_ok=True)
    page.locator('#ttsbLoadingRate').scroll_into_view_if_needed();page.screenshot(path=str(evidence/'ready-rice-loading-charges.png'))
    # Exercise shipment bills through the actual Accounts desk in disposable storage.
    page.goto(base+'/accounts/index.php')
    page.locator('#ttChangeCompanyDesk').click();page.locator('.tt-company-choice[data-entity="TTI"]').click()
    page.locator('[data-tt-area="exports"]').click()
    page.get_by_role('button',name=re.compile('Transport Bill')).click();page.locator('#ttBillDesk [data-post]').click()
    page.locator('#ttBillShipmentQuery').fill('FIXTURE');page.locator('#ttBillShipmentGo').click();page.locator('[data-tt-pick-shipment]').first.click()
    page.locator('#ttShipmentBillVendor').wait_for();page.wait_for_function("document.querySelector('#ttShipmentBillVendor').getAttribute('list')==='tt-master-transporter'")
    # Browser suggestions must survive unrelated totals/master refreshes.
    page.locator('#ttShipmentBillVendor').click()
    page.evaluate("""() => { window.selectorMutations=[]; const input=document.querySelector('#ttShipmentBillVendor'); new MutationObserver(records=>window.selectorMutations.push(...records.map(r=>r.attributeName))).observe(input,{attributes:true,attributeFilter:['list','autocomplete']}); window.TT_ACCOUNTS_MASTER_CHOICES.refresh(); const marker=document.createElement('span'); marker.id='dropdownBackgroundUpdate'; document.body.append(marker); marker.textContent='Unrelated background total'; }""")
    page.wait_for_timeout(500)
    assert page.evaluate('window.selectorMutations')==[], 'Background refresh reassigned an open suggestion list'
    assert page.locator('#ttShipmentBillVendor').evaluate('el=>document.activeElement===el'), 'Background refresh moved input focus'
    assert page.locator('[data-remarks]').count()==0 and 'rate override reason' not in page.locator('#ttShipmentBillEntry').inner_text()
    positions=[page.locator('.tt-transport-grid [name='+name+']').bounding_box()['y'] for name in ['loadingProgrammeNo','containers','rate']]
    assert max(positions)-min(positions)<2,positions
    assert page.locator('[name=invoiceNo]').get_attribute('list') is None,'Bill number was treated as supplier'
    page.locator('#ttShipmentBillVendor').fill('Cedar Horizon Haulage')
    page.locator('#ttShipmentBillVendor + .tt-master-inline').click();assert page.locator('#ttPartyInlineEditor h3').inner_text()=='ADD TRANSPORTER'
    page.locator('#ttPartyInlineEditor [name=partyName]').fill('Cedar Horizon Haulage');page.locator('#ttPartyInlineEditor [type=submit]').click();page.wait_for_function("!document.querySelector('#ttPartyInlineEditor').open || document.querySelector('.tt-party-editor-error').textContent.length>0");assert not page.locator('#ttPartyInlineEditor').is_visible(),page.locator('.tt-party-editor-error').inner_text()
    page.locator('[name=containers]').fill('3');page.locator('[name=invoiceNo]').fill('BROWSER-TRANSPORT');page.locator('[name=rate]').fill('38000');page.locator('[name=remarks]').fill('Fixture transport narration')
    page.locator('#ttShipmentBillAdd').click();page.locator('[data-description]').fill('Fixture commission');page.locator('[data-amount]').fill('1500')
    # Use the visible selector, not force-selecting the hidden native control.
    choice=page.locator('[data-type]').first.locator('..').locator('input')
    choice.click();menu=page.locator('.tt-select-menu:not([hidden])');menu.wait_for(state='visible')
    page.wait_for_timeout(300);assert menu.is_visible(), 'Dropdown closed before choosing'
    menu.get_by_role('button',name='Deduction',exact=True).click();assert page.locator('[data-type]').first.input_value()=='DEDUCT'
    choice.click();menu.wait_for(state='visible');choice.press('Escape');assert not menu.is_visible()
    choice.click();menu.wait_for(state='visible');menu.get_by_role('button',name='Addition',exact=True).click()
    assert page.locator('[data-type]').first.input_value()=='ADD', 'Focused selector did not reopen'
    page.locator('#ttShipmentBillDeduct').click();page.locator('[data-description]').nth(1).fill('Fixture deduction');page.locator('[data-amount]').nth(1).fill('300')
    assert page.locator('[data-type]').nth(1).input_value()=='DEDUCT' and page.locator('#ttShipmentBillTotal').inner_text()=='115,200.00'
    assert page.locator('.tt-shipment-charge').first.evaluate('el=>el.firstElementChild.hasAttribute("data-remove")')
    ends=[page.locator(sel).first.bounding_box()['x']+page.locator(sel).first.bounding_box()['width'] for sel in ['[data-amount]','#ttShipmentBillBase','#ttShipmentBillTotal']]
    assert max(ends)-min(ends)<16,ends
    assert page.locator('[name=billDate]').locator('..').inner_text()=='BILL DATE'
    page.locator('#ttTransportAddShipment').click();assert page.locator('.tt-transport-shipment').first.evaluate("el=>getComputedStyle(el).backgroundColor")=='rgb(234, 242, 255)'
    page.locator('#ttTransportQuery').fill('FIXTURE');page.locator('#ttTransportFind').click();page.wait_for_function("!document.querySelector('#ttTransportHits').textContent.includes('Searching Exports')");assert not page.locator('#ttTransportHits').get_by_text('FIXTURE-SHIP-LOT',exact=False).count(),'Selected shipment remains searchable'
    page.locator('#ttTransportQuery').fill('FIXTURE-SHIP-2');page.locator('#ttTransportFind').click();page.locator('#ttTransportHits [data-pick]').click()
    second=page.locator('.tt-transport-shipment').nth(1);assert second.locator('[data-remove-shipment]').evaluate("el=>getComputedStyle(el).backgroundColor")=='rgb(180, 35, 24)';second.locator('[name=containers]').fill('1');second.locator('[name=rate]').fill('38000');assert page.locator('#ttShipmentBillTotal').inner_text()=='153,200.00'
    page.locator('#ttShipmentBillVendor').fill('JJ');page.locator('#ttShipmentBillEntry [type=submit]').click();page.wait_for_function("document.querySelector('#ttShipmentBillError').textContent.includes('Bill not posted')");assert 'TRANSPORTER' in page.locator('#ttShipmentBillError').inner_text()
    page.locator('#ttShipmentBillVendor').fill('Cedar Horizon Haulage');page.locator('#ttShipmentBillEntry [type=submit]').click();page.get_by_role('heading',name='BILL POSTED').wait_for()
    assert re.search(r'2026-\d+',page.locator('.tt-bill-confirmation').inner_text())
    page.screenshot(path=str(evidence/'transporter-bill-confirmation.png'))
    status,registered=request('/api/accounts_workflows_v1.php?entity=TTI&section=transport');assert status==200 and next(x for x in registered['bills'] if x['invoiceNo']=='BROWSER-TRANSPORT')['total']==153200 and len(next(x for x in registered['bills'] if x['invoiceNo']=='BROWSER-TRANSPORT')['lines'])==2
    assert (root/'transtrade_private/operations.json').read_bytes()==original,'Transport posting changed Mill records'
    page.locator('#ttShipmentBillPay').click();assert page.locator('#ttSimpleBills [data-bank]').input_value()=='qa-bank-default';page.locator('#ttSimpleBills [data-bank-method]').select_option('CHEQUE');assert page.locator('#ttSimpleBills [data-reference]').locator('..').inner_text()=='CHEQUE NUMBER';assert page.locator('#ttSimpleBills [data-cheque-date]').count()==1;page.locator('#ttSimpleBills [data-bank-method]').select_option('ONLINE_BANKING');page.locator('#ttSimpleBills [data-narration]').fill('Fixture user narration');page.locator('#ttSimpleBills [data-amount]').fill('50000');page.locator('#ttSimpleBills [data-source]').select_option('CASH');page.locator('#ttSimpleBills [type=submit]').click();page.wait_for_function("document.querySelector('#ttSimpleBills [data-error]')?.textContent || document.querySelector('#ttSimpleBills .tt-window-body h2')?.textContent==='PAYMENT POSTED'");assert not page.locator('#ttSimpleBills [data-error]').count() or not page.locator('#ttSimpleBills [data-error]').inner_text(),page.locator('#ttSimpleBills').inner_text();page.locator('#ttSimpleBills').get_by_role('heading',name='PAYMENT POSTED',exact=True).wait_for()
    assert 'POST ID' in page.locator('#ttSimpleBills').inner_text()
    page.locator('#ttSimpleBills [data-close]').click()
    page.evaluate("TT_ALL_LEDGERS.open('2130','supplier')");page.locator('#tal-party').fill('Cedar Horizon Haulage');page.locator('#tal-go').click();page.wait_for_function("document.querySelector('.tal-total')?.textContent.includes('103,200.00')")
    assert '50000' not in page.locator('.tal-total').inner_text() and '50,000.00' in page.locator('.tal-total').inner_text()
    assert page.locator('.tal-posting-row').count()>=2;page.locator('.tal-posting-row').last.click();page.locator('#tal-back').wait_for();assert page.locator('.tal-table tbody tr').count()>=2;page.locator('#tal-back').click();page.locator('#tt-all-ledgers').dispatch_event('click');page.evaluate("window.dispatchEvent(new Event('blur'));document.dispatchEvent(new Event('visibilitychange'))");assert page.locator('#tal-party').input_value()=='Cedar Horizon Haulage';assert page.locator('#tt-all-ledgers').is_visible();
    with page.expect_download() as dl:page.locator('#tal-export').click()
    assert dl.value.suggested_filename.endswith('.xlsx');page.locator('#tal-close').click()
    harness=app/'accounts/__bank_tracking_fixture.html';harness.write_text('<html><body><div><label>Pay from<select id="bankSource"><option value="qa-bank-a">Bank A</option><option value="qa-bank-default">Default bank</option><option value="CASH|TTI">Cash</option></select></label></div><script src="bank-payment-details.js"></script><script>TT_BANK_PAYMENT_DETAILS.mount("bankSource",[{id:"qa-bank-default",isDefault:true}]);</script></body></html>')
    page.goto(base+'/accounts/__bank_tracking_fixture.html');assert page.locator('#bankSource').input_value()=='qa-bank-default';page.locator('[data-method]').select_option('CHEQUE');assert page.locator('[data-reference-label]').inner_text()=='Cheque number';page.locator('[data-reference]').fill('777');page.locator('[data-narration]').fill('User narration');details=page.evaluate('TT_BANK_PAYMENT_DETAILS.read("bankSource","2026-09-30")');assert details['chequeNo']=='777' and details['paymentNarration']=='User narration';page.locator('#bankSource').select_option('CASH|TTI');assert not page.locator('#bankSourceDetails').is_visible();page.locator('#bankSource').select_option('qa-bank-default');page.locator('[data-method]').select_option('ONLINE_BANKING');assert page.evaluate('TT_BANK_PAYMENT_DETAILS.read("bankSource","2026-09-30")')['chequeNo']==''
    page.goto(base+'/accounts/index.php')

    # Actual freight UI: separate parties, customer-first lots, aligned USD/PKR rows.
    status,created=request('/api/masters.php',{'action':'create','type':'business_parties','values':['Fixture Forwarder','','Freight Forwarder','','','','','','','','Active','','']});assert status==200,(status,created)
    page.goto(base+'/accounts/index.php');page.locator('#ttChangeCompanyDesk').click();page.locator('.tt-company-choice[data-entity="TTI"]').click();page.locator('[data-tt-area="exports"]').click()
    page.get_by_role('button',name=re.compile('Freight Forwarder')).click();page.locator('#ttFreightAgreementOpen').click()
    page.locator('#ttFreightCustomer').fill('Fixture Customer');page.locator('#ttFreightCustomer').press('Tab');page.wait_for_selector('#ttFreightShipment option[value="1"]',state='attached')
    assert all('Fixture Customer' not in text for text in page.locator('#ttFreightShipment option').all_text_contents())
    page.locator('#ttFreightShipment').select_option('0',force=True);assert page.locator('[name=shippingLine]').input_value()=='Fixture Line' and page.locator('[name=forwarder]').input_value()==''
    assert page.locator('[name=fromPort] option').all_text_contents()==['Choose loading port','Karachi Port, Pakistan','Port Qasim, Pakistan']
    page.locator('#ttFreightAgreement .tt-window-close').click();page.get_by_role('button',name=re.compile('Freight Forwarder')).click();page.locator('#ttFreightInvoice').click()
    page.locator('#ttBillShipmentQuery').fill('FIXTURE-SHIP');page.locator('#ttBillShipmentGo').click();page.locator('[data-tt-pick-shipment="0"]').click()
    page.locator('#ttShipmentBillVendor').fill('Fixture Forwarder');page.locator('[name=invoiceNo]').fill('BROWSER-FREIGHT-USD');page.locator('[name=exchangeRate]').fill('280');page.locator('[data-containers]').fill('3')
    charges=page.locator('.tt-bill-freight');charges.nth(0).locator('[data-amount]').fill('1000');charges.nth(1).locator('[data-amount]').fill('50')
    page.locator('[data-add]').click();charges.nth(2).locator('[data-description]').fill('Handling');charges.nth(2).locator('[data-amount]').fill('25');charges.nth(2).locator('[data-basis]').select_option('PER_CONTAINER',force=True)
    page.locator('[data-add]').click();charges.nth(3).locator('[data-description]').fill('Documentation');charges.nth(3).locator('[data-amount]').fill('10');charges.nth(3).locator('[data-basis]').select_option('PER_BL',force=True)
    page.locator('[data-deduct]').click();charges.nth(4).locator('[data-description]').fill('Discount');charges.nth(4).locator('[data-amount]').fill('5');charges.nth(4).locator('[data-basis]').select_option('PER_BL',force=True)
    assert page.locator('#ttShipmentBillTotal').inner_text()=='876,400.00'
    assert page.locator('[data-pkr]').evaluate_all('els=>els.map(el=>el.value)')==['840,000.00','14,000.00','21,000.00','2,800.00','-1,400.00']
    ends=[row.locator('[data-pkr]').bounding_box()['x']+row.locator('[data-pkr]').bounding_box()['width'] for row in charges.all()];assert max(ends)-min(ends)<2,ends
    for row in charges.all():
     bottoms=row.evaluate("row=>['[data-description]','[data-amount]','[data-basis]','[data-pkr]'].map(selector=>{const el=row.querySelector(selector),visible=el.classList.contains('tt-number-source')?el.parentElement.querySelector('.tt-number-display'):el.classList.contains('tt-native-select')?el.parentElement.querySelector('input'):el,r=visible.getBoundingClientRect();return r.bottom})");assert max(bottoms)-min(bottoms)<2,bottoms
    page.screenshot(path=str(evidence/'freight-usd-charge-bases.png'))
    page.locator('#ttShipmentBillEntry [type=submit]').click();page.get_by_role('heading',name='BILL POSTED',exact=True).wait_for()
    stored=json.loads((root/'transtrade_private/accounts.json').read_text());bill=next(x for x in stored['freightBillsV1'].values() if x['invoiceNo']=='BROWSER-FREIGHT-USD');assert bill['billedTotal']==876400 and stored['supplierBills'][bill['id']]['supplierPayableTotal']==876400
    assert page.locator('#ttServiceAddShipment').count()==0
    page.locator('#ttShipmentBillForm [data-another]').click();assert not page.locator('#ttShipmentBillForm').is_visible();page.locator('#ttBillShipmentQuery').wait_for(state='visible')
    page.locator('#ttBillShipmentQuery').fill('FIXTURE-SHIP');page.locator('#ttBillShipmentGo').click();page.wait_for_function("!document.querySelector('#ttBillShipmentHits').textContent.includes('Searching')");assert 'FIXTURE-SHIP-LOT' not in page.locator('#ttBillShipmentHits').inner_text()
    page.evaluate('id=>TT_ACCOUNTING_DESK.openSearch(id)',bill['id']);page.locator('[data-amend-record]').first.click();page.locator('#ttShipmentBillEntry [name=reason]').wait_for(state='visible')
    assert page.locator('[data-amount]').evaluate_all('els=>els.map(el=>el.value)')==['1000','50','25','10','5'];assert page.locator('[name=exchangeRate]').input_value()=='280'
    page.locator('[data-amount]').first.fill('1010');page.locator('[name=reason]').fill('Fixture freight rate correction');page.locator('#ttShipmentBillEntry [type=submit]').click();page.get_by_role('heading',name='BILL AMENDED',exact=True).wait_for()
    amended=json.loads((root/'transtrade_private/accounts.json').read_text());assert amended['freightBillsV1'][bill['id']]['billedTotal']==884800 and len(amended['freightBillsV1'])==len(stored['freightBillsV1'])
    assert amended['supplierBills'][bill['id']]['postingJournalIds'][:-1]==stored['supplierBills'][bill['id']]['postingJournalIds'] and amended['journals'][amended['supplierBills'][bill['id']]['postingJournalIds'][-1]]['totalDebit']==8400

    page.remove_listener('response',check_response)
    # Exercise the actual form against the actual endpoints in a minimal harness.
    harness='<html><body><div id="purchaseEditor" data-tt-purchase-mode="arrival"></div><script>window.TT_ACCOUNT_ACCESS={csrf:'+json.dumps(page.locator('body').evaluate('()=>window.TT_ACCOUNT_ACCESS.csrf'))+'};localStorage.setItem("tt_accounts_entity","TTI");</script><script src="accounts/bill-smart-ui-v2.js"></script><script>TT_SMART_COMMODITY_BILLS_V2.mount();</script></body></html>'
    # Relative api URLs require an Accounts directory harness.
    harness=harness.replace('src="accounts/','src="');(app/'accounts/__bill_fixture.html').write_text(harness)
    page.goto(base+'/accounts/__bill_fixture.html');page.locator('#ttsbBroker').fill('JJ');page.locator('#ttsbBroker').press('Tab');page.wait_for_selector('#ttsbSupplierChoices option',state='attached');assert page.locator('#ttsbSupplier').input_value()==''
    page.locator('#ttsbBroker').fill('');page.locator('#ttsbBroker').press('Tab')
    page.locator('#ttsbSupplier').fill('Indus Rice');page.locator('#ttsbSupplier').press('Tab');page.wait_for_function("document.querySelector('#ttsbBroker').value==='JJ'")
    assert page.locator('#ttsbSupplierChoices option').count()==1
    page.locator('#ttsbSoda').select_option('26001',force=True);page.locator('#ttsbTruck').select_option('EXMILL|26001|1',force=True);assert not page.locator('#ttsbMultiple').is_checked()
    assert page.locator('#ttsbFilling').count()==0 and page.locator('#ttsbBags').input_value()=='480'
    assert page.locator('#ttsbBrokerage').input_value()=='1198.80'
    assert 'Credit' in page.locator('.ttsb-summary').inner_text() and '11-10-2026' in page.locator('.ttsb-summary').inner_text()
    page.locator('#ttsbKanta').fill('600');page.locator('#ttsbBillNo').fill('BROWSER-FIXTURE');page.locator('#ttsbMultiple').check()
    assert page.locator('[data-bill-source]:checked').count()==2 and page.locator('#ttsbBillNo').input_value()=='BROWSER-FIXTURE'
    assert page.locator('#ttsbBags').input_value()=='960' and page.locator('#ttsbBrokerage').input_value()=='2397.60'
    page.locator('#ttsbLoadingRate').fill('8');assert page.locator('#ttsbLoadingBags').input_value()=='960';assert page.locator('#ttsbLoadingTotal').input_value()=='7680.00'
    assert '4,806,117.96' in page.locator('#ttsbGrandTotal').inner_text()
    rows=page.locator('.ttsb-truck').all();assert all(row.bounding_box()['height']<65 for row in rows)
    page.locator('#ttsbAddAddition').click();page.locator('[data-line-description]').fill('Fixture packing');page.locator('[data-line-amount]').fill('100')
    ends=[page.locator(sel).bounding_box()['x']+page.locator(sel).bounding_box()['width'] for sel in ['#ttsbBrokerage','#ttsbLoadingTotal','[data-line-amount]','#ttsbWithAmt']]
    assert max(ends)-min(ends)<2,ends
    page.locator('[data-line-remove]').click()
    page.locator('#ttsbVerify').click();page.get_by_role('heading',name='Bill Posted',exact=True).wait_for();assert not page.locator('#ttSmartBillToast').is_visible()
    browser.close()
  print('Ready rice bill HTTP, payee, WHT, due-date, master and duplicate-posting checks passed'+('; browser flow passed' if os.environ.get('TT_QA_BROWSER')=='1' else ''))
 finally:
  if server:server.terminate();server.wait(timeout=5)
  if log:log.close()
  shutil.rmtree(root)
if __name__=='__main__':run()
