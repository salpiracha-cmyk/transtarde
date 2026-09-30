"""Real bill endpoints and optional browser flow, always in disposable private storage."""
import http.cookiejar, json, os, re, shutil, socket, subprocess, tempfile, time, urllib.request, urllib.parse
from pathlib import Path
SOURCE=Path(__file__).resolve().parents[2]
SEED=r'''<?php
require __DIR__.'/repo/auth_store.php';
$pw=bin2hex(random_bytes(20));$rw=['View','Create','Edit'];
$users=[['id'=>501,'username'=>'billqa','full_name'=>'Bill QA','role'=>'Accounts Operator','permissions'=>['Accounts'=>['purchases'=>$rw,'entity-tti'=>$rw]],'active'=>true,'must_change_password'=>false,'master_access'=>true,'master_permissions'=>['business_parties'=>$rw],'password_hash'=>password_hash($pw,PASSWORD_DEFAULT)],['id'=>502,'username'=>'billview','full_name'=>'Bill Viewer','role'=>'Accounts Viewer','permissions'=>['Accounts'=>['purchases'=>['View'],'entity-tti'=>['View']]],'active'=>true,'must_change_password'=>false,'master_access'=>false,'password_hash'=>password_hash($pw,PASSWORD_DEFAULT)]];
$users[]=['id'=>503,'username'=>'fixtureowner','full_name'=>'Fixture Owner','role'=>'Super Admin','permissions'=>['Accounts'=>'all'],'active'=>true,'must_change_password'=>false,'master_access'=>true,'password_hash'=>password_hash($pw,PASSWORD_DEFAULT)];
$masters=tt_default_masters();$masters['business_parties'][]=['id'=>'transport-fixture','values'=>['Fixture Transport','FT','Transporter','','','','','','','','Active']];$masters['business_parties'][]=['id'=>'broker-jj-fixture','values'=>['JJ','JJ','Broker','','','','','','','','Active','','{"buying":[{"amount":5,"basis":"PER_100_KG","effectiveFrom":"2026-01-01","status":"Active"}],"selling":[{"amount":9,"basis":"PER_TON","effectiveFrom":"2026-01-01","status":"Active"}]}']];
tt_ensure_data_dir();file_put_contents(TT_STORE_FILE,json_encode(['users'=>$users,'masters'=>$masters,'settings'=>['qa_account_seeded'=>true],'audit'=>[]]));
$s=['revision'=>0,'journals'=>[],'events'=>[],'commodityBills'=>[],'purchaseSodas'=>[],'loadingProgrammes'=>['TTI|FIXTURE-LP'=>['entity'=>'TTI','loadingProgrammeNo'=>'FIXTURE-LP','loadedContainers'=>10]],'transportMaster'=>[['from'=>'Karachi','to'=>'Jeddah','rate'=>38000]]];
foreach([['26001','READY','Indus Rice','JJ','CREDIT',30],['26002','RAW','Indus Rice','JJ','CASH',0],['26003','READY','','JJ','CASH',0],['26004','READY','Indus Rice','','CREDIT',60]] as [$no,$stage,$party,$broker,$term,$days]){
 $s['purchaseSodas'][$no]=['id'=>'PS-'.$no,'entity'=>'TTI','commodity'=>'RICE','sodaNo'=>$no,'sodaDate'=>'2026-09-01','party'=>$party,'broker'=>$broker,'productStage'=>$stage,'rate'=>100,'paymentTermType'=>$term,'creditDays'=>$days];
 foreach([1,2] as $n){$key=($stage==='READY'?'EXMILL|':'POHANCH|').$no.'|'.$n;$jid='J-'.$no.'-'.$n;$eid='TTI|COMMODITY_RECEIPT_ACCEPTED|'.$key;$date='2026-09-'.(10+$n);
 $meta=['soda'=>$no,'sourceSodaId'=>'PS-'.$no,'broker'=>$broker,'party'=>$party,'commodity'=>'RICE','productStage'=>$stage,'baseVariety'=>'IRRI-6','variety'=>'IRRI-6','displayName'=>$stage.' IRRI-6','bags'=>480,'payableWeightKg'=>24000,'weighbridgeWeightKg'=>24000,'grossRatePerKg'=>100,'katPaisaPerKg'=>0,'truck'=>'TRUCK-'.$n,'pohanch'=>'P-'.$no.'-'.$n,'container'=>'ABCD12345'.$n.'0','emptyBagWeightGrams'=>50];
 $s['journals'][$jid]=['id'=>$jid,'entity'=>'TTI','date'=>$date,'reference'=>$meta['pohanch'],'totalDebit'=>2400000,'totalCredit'=>2400000,'meta'=>$meta,'lines'=>[]];
 $s['events'][$eid]=['id'=>$eid,'eventType'=>'COMMODITY_RECEIPT_ACCEPTED','entity'=>'TTI','sourceKey'=>$key,'journalId'=>$jid,'status'=>'Accepted'];
 }
}
$s['localSalesPaymentCandidates']['foreign-payment']=['id'=>'foreign-payment','entity'=>'BRM','soda'=>'FOREIGN','party'=>'Foreign party','amount'=>100,'paymentDate'=>'2026-09-29','status'=>'Pending Accounts Approval'];$s['localSalesCandidates']['foreign-sale']=['entity'=>'BRM','journalId'=>'foreign-journal','loadedKg'=>100,'saleDate'=>'2026-09-29','product'=>'Ready Rice'];$s['inventoryCostRates']['foreign-rate']=['entity'=>'BRM','product'=>'Ready Rice','effectiveFrom'=>'2026-01-01','status'=>'Active','costPerKg'=>100,'inventoryAccount'=>'1320'];file_put_contents(TT_DATA_DIR.'/accounts.json',json_encode($s));file_put_contents(TT_DATA_DIR.'/operations.json',json_encode(['values'=>[]]));file_put_contents(__DIR__.'/credentials.json',json_encode(['password'=>$pw]));
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
  for username in ['billqa','billview']:
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
  original=(root/'transtrade_private/operations.json').read_bytes()
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
  p=payload('26003');p['relationshipType']='BROKER';p['relationshipName']='JJ'
  assert request('/api/commodity_bills.php',p)[0]==200,'Broker-only commodity payee'
  p=payload('26004');p['broker']='';p['brokerageRate']=0
  status,result=request('/api/commodity_bills.php',p);assert status==200 and result['bill']['brokerageGross']==0
  p=payload('26002',['POHANCH|26002|1','POHANCH|26002|2'],4800000);p.pop('readyRiceCalculation')
  assert request('/api/commodity_bills.php',p)[0]==422,'Raw cannot combine Pohanch'
  p['sourceKeys']=['POHANCH|26002|1'];p['finalCommodityValue']=2400000
  status,result=request('/api/commodity_bills.php',p);assert status==200 and result['bill']['brokerageGross']==1200 and result['bill']['dueDateFrom']=='2026-09-13'
  assert (root/'transtrade_private/operations.json').read_bytes()==original,'Milling must remain unchanged'
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
    page.locator('#ws-purchases .tt-clean-close').click()
    page.locator('#ttDeskWork .tt-back-areas').click()
    page.locator('[data-tt-area="exports"]').click()
    fixture={'id':'FIXTURE-SHIP','customer':'Fixture Customer','contract':'FIXTURE-CONTRACT','lot':'FIXTURE-LOT','commercialInvoice':'FIXTURE-CI','customsInvoice':'','bl':'FIXTURE-BL','loadingProgramme':'FIXTURE-LP','shippingLine':'Fixture Line','portOfLoading':'Karachi','portOfDischarge':'Jeddah','containers':['FIXTURE1','FIXTURE2','FIXTURE3'],'seller':'TTI','pakistanExporter':'TTI'}
    page.route('**/api/accounts_shipment_lookup.php?*',lambda route:route.fulfill(json={'ok':True,'rows':[fixture]}))
    page.get_by_role('button',name=re.compile('Transport Bill')).click()
    page.locator('#ttBillShipmentQuery').fill('FIXTURE');page.locator('#ttBillShipmentGo').click();page.locator('[data-tt-pick-shipment]').click()
    page.locator('#ttShipmentBillVendor').wait_for();assert page.locator('#ttShipmentBillVendor').get_attribute('list')=='tt-master-transporter'
    assert page.locator('[name=invoiceNo]').get_attribute('list') is None,'Bill number was treated as supplier'
    page.locator('#ttShipmentBillVendor').fill('New Fixture Transport')
    page.locator('#ttShipmentBillVendor + .tt-master-inline').click();assert page.locator('#ttPartyInlineEditor h3').inner_text()=='Add Transporter'
    page.locator('#ttPartyInlineEditor [name=partyName]').fill('New Fixture Transport');page.locator('#ttPartyInlineEditor [type=submit]').click();page.locator('#ttPartyInlineEditor').wait_for(state='hidden')
    page.locator('[name=invoiceNo]').fill('BROWSER-TRANSPORT');page.locator('[name=rate]').fill('38000')
    page.locator('#ttShipmentBillAdd').click();page.locator('[data-description]').fill('Fixture commission');page.locator('[data-amount]').fill('1500')
    page.locator('#ttShipmentBillDeduct').click();page.locator('[data-description]').nth(1).fill('Fixture deduction');page.locator('[data-amount]').nth(1).fill('300')
    assert page.locator('[data-type]').nth(1).input_value()=='DEDUCT' and page.locator('#ttShipmentBillTotal').inner_text()=='115,200.00'
    assert page.locator('.tt-shipment-charge').first.evaluate('el=>el.firstElementChild.hasAttribute("data-remove")')
    ends=[page.locator(sel).first.bounding_box()['x']+page.locator(sel).first.bounding_box()['width'] for sel in ['[data-amount]','#ttShipmentBillBase','#ttShipmentBillTotal']]
    assert max(ends)-min(ends)<16,ends
    page.locator('#ttShipmentBillEntry [type=submit]').click();page.get_by_role('heading',name='Supplier bill posted successfully').wait_for()
    assert 'TRB' in page.locator('.tt-bill-confirmation').inner_text()
    page.screenshot(path=str(evidence/'transporter-bill-confirmation.png'))
    status,registered=request('/api/accounts_workflows_v1.php?entity=TTI&section=transport');assert status==200 and registered['bills'][0]['total']==115200
    assert (root/'transtrade_private/operations.json').read_bytes()==original,'Transport posting changed Mill records'
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

