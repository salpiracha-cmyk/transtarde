"""Actual TG endpoint: permissions, CSRF, stale reviews, pending funds and grouped bank debit."""
import json,os,pathlib,shutil,socket,subprocess,tempfile,time,urllib.request,urllib.error
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tg-remittance-') as tmp:
 root=pathlib.Path(tmp);(root/'api').mkdir();(root/'accounts').mkdir();(root/'data').mkdir()
 for name in ['supplier_opening_core.php','accounts_subaccounts_core.php','assets_registry_core.php','export_receipts.php','export_receipt_tg_mirror.php','accounts_receipt_amend_core.php','tg_remittances.php','tg_remittance_core.php','fi_credit_advice_link.php','receipt_invoice_links.php','accounts_reviews.php','accounts_reviews_core.php','accounts_dashboard.php','customer_receivables_core.php','bank_accounts.php','expense_reminders.php','accounts_ledger_browser.php','accounts_post_delete_core.php','accounts_reference.php']:
  shutil.copy(ROOT/'api'/name,root/'api'/name)
 for name in ['accounting_master_v1.json','settlement_policy_v1.json','export_realization_policy_v1.json','tg-remittances-ui.js','export-receipts-ui.js','post-confirmation-ui.js']:
  shutil.copy(ROOT/'accounts'/name,root/'accounts'/name)
 shutil.copy(ROOT/'brand-theme.js',root/'brand-theme.js');shutil.copy(ROOT/'brand-theme.css',root/'brand-theme.css')
 (root/'auth_store.php').write_text('''<?php
 define('TT_DATA_DIR',__DIR__.'/data');foreach(['HOST','NAME','USER','PASS'] as $k)define('TT_DB_'.$k,'');
 function tt_ensure_data_dir(){} function tt_accounts_input(){return file_get_contents('php://input');}
 function tt_require_login(){return ['username'=>'FIXTURE','role'=>'Staff','permissions'=>['Accounts'=>($_SERVER['HTTP_X_FIXTURE_ROLE']??'')==='readonly'?['View']:['View','Edit']]];}
    // This isolated auth fixture has no PHP session; real lock behavior is tested separately.
    function tt_release_read_session(){}
 function tt_user_can_open_module($u,$m){return true;}
 function tt_user_can_access_entity($u,$e,$a){return in_array($e,['TTI','TG'],true)&&(($a==='View')||($_SERVER['HTTP_X_FIXTURE_ROLE']??'')!=='readonly');}
 function tt_verify_csrf($v){return $v==='fixture';}function tt_csrf_token(){return 'fixture';}
 function tt_master_options(){return ['currencies'=>['USD','AED','PKR']];}function tt_user_accounts_entities($u){return ['TTI','TG'];}
 function tt_company_fx_rate($e,$f,$t){return $f==='USD'?3.67:1;}
 function tt_read_store(){return ['masters'=>tt_list_masters()];}
 function tt_list_masters(){return ['export_realization_charges'=>[['id'=>'WHT','values'=>['Withholding Tax','EXP-AWT-NTR','Income Tax','NTR','PKR_PAYMENT','','','','Tax','1260','Yes','TTI; BRM','Active']],['id'=>'COMM','values'=>['Bank Commission','EXP-BANK-COMM','Bank Fee','Any','PKR_PAYMENT','','','','Fee','6810','No','TTI; BRM','Active']],['id'=>'FED','values'=>['FED Tax','EXP-FED-BANK','FED','Any','CHARGE:EXP-BANK-COMM','','','','Tax','6820','No','TTI; BRM','Active']]],'banks'=>[['id'=>'PKR','values'=>['Company Account','TTI','','TTI PKR','BANK','','','PKR','789','','','','','Active']],['id'=>'USD','values'=>['Company Account','TG','','TG USD','BANK','','','USD','123','','','','','Active']],['id'=>'AED','values'=>['Company Account','TG','','TG AED','BANK','','','AED','456','','','','','Active']]]];}
 function tt_next_post_id(array $existing, string $module='Accounts', string $area='Journal', ?string $date=null): string {
  $year=substr($date ?: date('Y-m-d'),0,4);$n=count($existing)+1;
  do {$id='POST-'.$year.'-'.str_pad((string)$n,5,'0',STR_PAD_LEFT);$n++;} while (isset($existing[$id]));
  return $id;
 }
 ''')
 s={'journals':{},'exportReceipts':{},'tgRemittanceDrafts':{}}
 for i,amount in enumerate([108000,32000]):
  rid=f'ER-{i}';jid=f'PK-{i}';did=f'DRAFT-{i}'
  s['journals'][jid]={'id':jid,'entity':'TTI','date':'2026-10-01','status':'Posted','meta':{'receiptId':rid},'narration':'CREDIT ADVICE','lines':[]}
  s['exportReceipts'][rid]={'id':rid,'entity':'TTI','remitter':'TG','date':'2026-10-01','transactionCurrency':'USD','foreignAmount':amount,'journalId':jid,'bankAdviceRef':f'ADVICE-{i}','status':'Accounts Approved / Posted','tgRemittanceDraftId':did,'allocations':[{'targetType':'UNAPPLIED_TG','foreignAmount':amount}]}
  s['tgRemittanceDrafts'][did]={'id':did,'status':'Pending','legacy':False,'receiptIds':[rid],'pakJournalIds':[jid],'counterparty':'TTI','date':'2026-10-01','currency':'USD','bankAccountId':'USD','bank':'BANK','amountNative':amount,'bankAdviceRefs':[f'ADVICE-{i}'],'invoiceRefs':[],'allocations':[{'targetAccount':'1250','sourceLiabilityId':'','payableRate':3.67,'foreignAmount':amount}],'version':1}
 (root/'data/accounts.json').write_text(json.dumps(s));(root/'data/operations.json').write_text(json.dumps({'values':{}}))
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 log=open(root/'php.log','w');server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root)],stdout=log,stderr=log)
 def req(path='tg_remittances.php?entity=TG',body=None,role=''):
  r=urllib.request.Request(f'http://127.0.0.1:{port}/api/{path}',data=json.dumps(body).encode() if body else None,headers={'Content-Type':'application/json','X-Fixture-Role':role})
  try:
   with urllib.request.urlopen(r,timeout=5) as x:return x.status,json.load(x)
  except urllib.error.HTTPError as e:return e.code,json.load(e)
 try:
  for _ in range(50):
   try:status,data=req();break
   except urllib.error.URLError:time.sleep(.1)
  assert status==200,data
  usd=next(x for x in data['banks'] if x['id']=='USD');assert usd['balance']=={'posted':0,'pending':140000,'available':-140000,'carryingRate':0}
  review=req('accounts_dashboard.php?entity=TG')[1];assert len(review['attention'])==2
  export_root={'contracts':[{'ref':'C-TG','seller':'TG','currency':'USD'},{'ref':'C-TTI','seller':'TTI','currency':'USD'},{'ref':'C-BRM','seller':'BRM','currency':'USD'}],'shipments':[{'id':'LOT-'+entity,'seller':entity,'contractRef':'C-'+entity,'commercial':{'saved':True,'status':'Final','invoiceNo':'CI-'+entity,'lastInvoiceValue':value},'tgdocs':{'saved':True,'invoiceValue':999999}} for entity,value in [('TG',1000),('TTI',500),('BRM',800)]]}
  (root/'data/operations.json').write_text(json.dumps({'values':{'transtrade_export_v3_operational':json.dumps(export_root)}}))
  accounts_before=(root/'data/accounts.json').read_bytes()
  group=req('accounts_dashboard.php?entity=TG')[1]
  assert group['customerReceivables']['entities']==['TTI','TG']
  assert sum(row['amount'] for row in group['summaries']['export'])==1500
  assert group['summaries']['export']==req('accounts_dashboard.php?entity=TTI')[1]['summaries']['export']
  assert req('accounts_dashboard.php?entity=BRM')[0]==403
  assert (root/'data/accounts.json').read_bytes()==accounts_before
  payload={'action':'confirm','csrf':'fixture','requestKey':'http-fixture-request-12345','ids':[x['id'] for x in data['items']],'fingerprints':{x['id']:x['fingerprint'] for x in data['items']},'date':'2026-10-01','bankReference':'','chargeBankAccountId':'USD','chargeAmount':30,'vatAmount':1.5,'sameRemittanceConfirmed':True}
  assert req(body={**payload,'csrf':'wrong'})[0]==419
  assert req(body=payload,role='readonly')[0]==403
  assert req(body={**payload,'fingerprints':{'DRAFT-0':'old'}})[0]==422
  assert json.loads((root/'data/accounts.json').read_text())==s,'Failed validation cannot mutate money'
  status,posted=req(body=payload);assert status==200,posted
  usd=next(x for x in posted['banks'] if x['id']=='USD');assert usd['balance']['posted']==-140031.5 and usd['balance']['pending']==0
  assert req(body=payload)[1]['posted']['id']==posted['posted']['id']
  assert req('accounts_dashboard.php?entity=TG')[1]['attention']==[]
  ledger=req('accounts_ledger_browser.php?entity=TG&account=BANK%7CUSD&to=2026-10-03')[1];assert ledger['currency']=='USD' and sum(x['credit'] for x in ledger['rows'])==140031.5,ledger
  # Actual Pakistan POST must atomically create its receipt and a pending TG deduction only.
  (root/'data/accounts.json').write_text(json.dumps({'journals':{},'exportReceipts':{}}))
  (root/'data/operations.json').write_text(json.dumps({'values':{'transtrade_export_v3_operational':json.dumps({'fi':[],'contracts':[],'shipments':[]})}}))
  receipt_payload={'action':'post_receipt','csrf':'fixture','entity':'TTI','date':'2026-10-01','bankAdviceRef':'ACTUAL-CREDIT-ADVICE','remitter':'TG','transactionCurrency':'USD','foreignAmount':100,'realizationRate':280,'grossPkrEquivalent':28000,'pkrBankCredit':28000,'bankAccountId':'PKR','tgBankAccountId':'USD','allocations':[{'targetType':'UNAPPLIED_TG','foreignAmount':100,'customer':'TG'}],'deductions':[]}
  status,actual=req('export_receipts.php?entity=TTI',receipt_payload);assert status==200,(actual,(root/'php.log').read_text()[-1500:])
  saved=json.loads((root/'data/accounts.json').read_text());assert actual['receipt']['tgRemittanceDraftId'] and len(saved['journals'])==1 and saved['journals'][actual['receipt']['journalId']]['entity']=='TTI'
  assert req()[1]['banks'][0]['balance']['pending']==100
  # Two invoices plus an advance settle gross, while Pakistan receives net of correspondent charges.
  basket_state={'journals':{},'exportCandidates':{},'exportReceipts':{}}
  basket_root={'fi':[],'contracts':[],'shipments':[]}
  for i,amount in enumerate([100,200],1):
   pkid=f'EXP|BASKET-{i}';tgid=f'TG-PAY-{i}';ref=f'TG/BASKET/{i}';invoice=f'PACK-{i}'
   basket_state['exportCandidates'][pkid]={'id':pkid,'entity':'TTI','candidateType':'TG_PAKISTAN_INTERCOMPANY','transactionCurrency':'USD','transactionAmount':amount,'functionalAmount':amount*280,'journalId':f'PK-OPEN-{i}','mirrorCandidateId':tgid,'meta':{'contractRef':ref,'commercialInvoiceNo':invoice,'customer':'TG'}}
   basket_state['exportCandidates'][tgid]={'id':tgid,'entity':'TG','candidateType':'TG_INTERCOMPANY_PAYABLE','counterparty':'TTI','transactionCurrency':'USD','transactionAmount':amount,'functionalRate':3.67,'functionalAmount':amount*3.67,'journalId':f'TG-OPEN-{i}','meta':{'contractRef':ref}}
   for entity,jid in [('TTI',f'PK-OPEN-{i}'),('TG',f'TG-OPEN-{i}')]:basket_state['journals'][jid]={'id':jid,'entity':entity,'date':'2026-07-01','status':'Posted','lines':[]}
   basket_root['contracts'].append({'ref':ref,'seller':'TG','currency':'USD','customer':'BUYER'})
   basket_root['shipments'].append({'id':f'BASKET-{i}','kind':'lot','contractRef':ref,'lotId':f'LOT-{i}','tgdocs':{'saved':True,'customsInvoiceNo':invoice,'exporter':'TTI','currency':'USD','invoiceValue':amount},'customs':{'saved':True}})
  (root/'data/accounts.json').write_text(json.dumps(basket_state));(root/'data/operations.json').write_text(json.dumps({'values':{'transtrade_export_v3_operational':json.dumps(basket_root)}}))
  basket_payload={**receipt_payload,'bankAdviceRef':'BASKET-ADVICE','foreignAmount':340,'tgPaymentTotal':350,'grossPkrEquivalent':95200,'pkrBankCredit':94107.3,'deductions':[{'masterCode':code,'amount':1,'percentage':pct,'autoCalculate':True} for code,pct in [('EXP-FED-BANK',15),('EXP-BANK-COMM',.1),('EXP-AWT-NTR',1)]],'allocations':[{'targetType':'INTERCOMPANY_RECEIVABLE','targetId':f'EXP|BASKET-{i}','invoiceRef':f'PACK-{i}','contractRef':f'TG/BASKET/{i}','foreignAmount':amount,'customer':'TG'} for i,amount in enumerate([100,200],1)]+[{'targetType':'UNAPPLIED_TG','foreignAmount':50,'customer':'TG'}]}
  before=(root/'data/accounts.json').read_bytes()
  assert req('export_receipts.php?entity=TTI',{**basket_payload,'tgPaymentTotal':339})[0]==422
  assert req('export_receipts.php?entity=TTI',{**basket_payload,'tgPaymentTotal':360})[0]==422
  assert (root/'data/accounts.json').read_bytes()==before
  status,basket=req('export_receipts.php?entity=TTI',basket_payload);assert status==200,basket
  journal=basket['journal'];assert journal['totalDebit']==journal['totalCredit']==98000
  assert sum(x['debit'] for x in journal['lines'] if x['account']=='6810')==2898
  charges={x['masterCode']:x for x in basket['receipt']['deductions']};assert charges['EXP-AWT-NTR']['amount']==980 and charges['EXP-BANK-COMM']['amount']==98 and charges['EXP-FED-BANK']['amount']==14.7
  assert charges['EXP-AWT-NTR']['calculationBasePkr']==98000 and charges['EXP-FED-BANK']['calculationBasePkr']==98
  assert sum(x['credit'] for x in journal['lines'] if x['account']=='1240')==84000
  assert sum(x['credit'] for x in journal['lines'] if x['account']=='2510')==14000
  assert basket['receipt']['foreignAmount']==340 and basket['receipt']['correspondentForeignAmount']==10 and basket['receipt']['tgPaymentTotal']==350
  status,review=req();draft=review['items'][0];assert status==200 and draft['amountNative']==350 and len(draft['allocations'])==3 and draft['receivedNative']==340
  assert next(x for x in review['banks'] if x['id']=='USD')['balance']['posted']==0,'TG bank cannot be deducted before its review'
  before=(root/'data/accounts.json').read_bytes();assert req('export_receipts.php?entity=TTI',basket_payload)[0]==409;assert (root/'data/accounts.json').read_bytes()==before
  confirm={**payload,'ids':[draft['id']],'fingerprints':{draft['id']:draft['fingerprint']},'requestKey':'basket-confirm-request-12345','chargeAmount':0,'vatAmount':0}
  assert req(body=confirm,role='readonly')[0]==403
  status,confirmed=req(body=confirm);assert status==200,confirmed
  assert next(x for x in confirmed['banks'] if x['id']=='USD')['balance']['posted']==-350
  before=json.loads((root/'data/accounts.json').read_text());assert req(body=confirm)[1]['posted']['id']==confirmed['posted']['id'];after=json.loads((root/'data/accounts.json').read_text());assert set(after['journals'])==set(before['journals']) and all(after['journals'][k]['lines']==v['lines'] for k,v in before['journals'].items())
  # Two parts of one advice use separate rates; blank fees never write off the balance.
  (root/'data/accounts.json').write_text(json.dumps(basket_state))
  parts=[]
  for part,net,fee,rate,allocation,bank in [('1',58,2,280,60,16052.68),('2',40,0,282,40,11154.23)]:
   split={**basket_payload,'bankAdviceRef':'SPLIT-ADVICE','partialReceipt':True,'paymentPartRef':part,'foreignAmount':net,'tgPaymentTotal':allocation,'grossPkrEquivalent':net*rate,'realizationRate':rate,'pkrBankCredit':bank,'allocations':[{**basket_payload['allocations'][0],'foreignAmount':allocation}]}
   if fee:split['correspondentForeignAmount']=fee
   status,result=req('export_receipts.php?entity=TTI',split);assert status==200,result;parts.append(result)
   assert result['journal']['totalDebit']==result['journal']['totalCredit']
   before=(root/'data/accounts.json').read_bytes();assert req('export_receipts.php?entity=TTI',split)[0]==409;assert (root/'data/accounts.json').read_bytes()==before
   invoice=next(x for x in req('export_receipts.php?entity=TTI')[1]['sources']['invoices'] if x['id']=='EXP|BASKET-1');assert invoice['outstandingForeign']==(40 if part=='1' else 0)
  assert parts[0]['receipt']['deductions'][2]['calculationBasePkr']==16800
  assert parts[1]['receipt']['correspondentForeignAmount']==0
  assert sum(x['debit'] for x in parts[1]['journal']['lines'] if x.get('masterCode')=='EXP-BANK-SHORTFALL')==0
  assert next(x for x in req()[1]['banks'] if x['id']=='USD')['balance']['pending']==100
  assert next(x for x in req()[1]['banks'] if x['id']=='USD')['balance']['posted']==0
  projection=json.loads(json.loads((root/'data/operations.json').read_text())['values']['transtrade_export_v3_operational'])['accountsReceipts']
  assert len(projection)==2 and {x['receiptType'] for x in projection}=={'TG_PACK_PAYMENT'} and [x['realizationRate'] for x in projection]==[280,282]
  before=(root/'data/accounts.json').read_bytes();assert req('export_receipts.php?entity=TTI',{**split,'paymentPartRef':'3','remitter':'OTHER'})[0]==409;assert (root/'data/accounts.json').read_bytes()==before
  # Direct customer receipt reduces only its own commercial invoice.
  direct=json.loads(json.dumps(basket_state));direct['exportCandidates']['DIRECT']={**direct['exportCandidates']['EXP|BASKET-1'],'id':'DIRECT','candidateType':'CUSTOMER_EXPORT_SALE','mirrorCandidateId':'','meta':{'commercialInvoiceNo':'CUSTOMER-CI','contractRef':'DIRECT-CONTRACT','customer':'BUYER'}}
  (root/'data/accounts.json').write_text(json.dumps(direct))
  for part,amount,rate in [('1',60,280),('2',40,282)]:
   payload_direct={**receipt_payload,'bankAdviceRef':'CUSTOMER-SPLIT','remitter':'BUYER','partialReceipt':True,'paymentPartRef':part,'foreignAmount':amount,'realizationRate':rate,'grossPkrEquivalent':amount*rate,'pkrBankCredit':amount*rate,'allocations':[{'targetType':'EXPORT_RECEIVABLE','targetId':'DIRECT','foreignAmount':amount,'customer':'BUYER'}]}
   status,result=req('export_receipts.php?entity=TTI',payload_direct);assert status==200,result
   assert sum(x['credit'] for x in result['journal']['lines'] if x['account']=='1210')==amount*280
  sources=req('export_receipts.php?entity=TTI')[1]['sources']['invoices'];assert next(x for x in sources if x['id']=='DIRECT')['outstandingForeign']==0 and next(x for x in sources if x['id']=='EXP|BASKET-1')['outstandingForeign']==100
  saved=json.loads((root/'data/accounts.json').read_text());assert saved['exportCandidates']['DIRECT']['transactionAmount']==100
  # Browser uses the actual endpoint again from a fresh pending fixture.
  # Large advice at a fractional rate must balance net receipt plus rounded correspondent fee.
  fractional_state=json.loads(json.dumps(basket_state));fractional_root=json.loads(json.dumps(basket_root))
  for i,amount in enumerate([32160,312200],1):
   fractional_state['exportCandidates'][f'EXP|BASKET-{i}'].update(transactionAmount=amount,functionalAmount=amount*280)
   fractional_state['exportCandidates'][f'TG-PAY-{i}'].update(transactionAmount=amount,functionalAmount=amount*3.67)
   fractional_root['shipments'][i-1]['tgdocs']['invoiceValue']=amount
  (root/'data/accounts.json').write_text(json.dumps(fractional_state));(root/'data/operations.json').write_text(json.dumps({'values':{'transtrade_export_v3_operational':json.dumps(fractional_root)}}))
  fractional_payload={**basket_payload,'bankAdviceRef':'FRACTIONAL-ADVICE','foreignAmount':344317.5,'tgPaymentTotal':344360,'realizationRate':280.95,'grossPkrEquivalent':96736001.63,'pkrBankCredit':95493274.63,'allocations':[{**basket_payload['allocations'][i-1],'foreignAmount':amount} for i,amount in enumerate([32160,312200],1)],'deductions':[{'masterCode':code,'amount':1,'percentage':pct,'autoCalculate':True} for code,pct in [('EXP-FED-BANK',15),('EXP-BANK-COMM',.03),('EXP-AWT-NTR',1.25)]]}
  status,fractional=req('export_receipts.php?entity=TTI',fractional_payload);assert status==200,fractional
  assert fractional['journal']['totalDebit']==fractional['journal']['totalCredit']
  (root/'data/operations.json').write_text(json.dumps({'values':{'transtrade_export_v3_operational':json.dumps(basket_root)}}))
  if os.environ.get('TT_QA_BROWSER')=='1':
   from playwright.sync_api import sync_playwright
   (root/'data/accounts.json').write_text(json.dumps(s))
   desk=(ROOT/'accounts/accounts-accounting-desk.js').read_text();start=desk.index('style.textContent = `')+len('style.textContent = `') if 'style.textContent = `' in desk else -1
   css=desk[start:desk.index('`;',start)] if start!=-1 else '.tt-layer{position:fixed;inset:0;padding:16px;background:#112233aa}.tt-window{background:#f7fafc;max-height:94vh;overflow:auto;padding:20px}.tt-form-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}label{display:grid}input,select{min-width:0;width:100%;box-sizing:border-box}'
   (root/'accounts/harness.html').write_text('<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>'+css+'</style><script>localStorage.setItem("tt_accounts_entity","TG");window.TT_ACCOUNT_ACCESS={csrf:"fixture"};window.TT_FORM_VIEWPORT={open:h=>h.scrollTop=0};</script><script src="tg-remittances-ui.js"></script><button onclick="TT_TG_REMITTANCES.open()">REVIEW</button>')
   with sync_playwright() as pw:
    browser=pw.chromium.launch();page=browser.new_page(viewport={'width':1280,'height':900});page.goto(f'http://127.0.0.1:{port}/accounts/harness.html');page.get_by_role('button',name='REVIEW',exact=True).click();page.locator('[data-id="DRAFT-1"]').check();page.locator('[name=chargeAmount]').fill('30');page.locator('[name=vatAmount]').fill('1.50');page.locator('[name=sameRemittanceConfirmed]').check();assert '140,000.00' in page.locator('#tgr-total').inner_text();assert '140,031.50' in page.locator('#tgr-debit').inner_text()
    evidence=pathlib.Path(os.environ.get('TT_QA_OUTPUT',str(root/'evidence')));evidence.mkdir(exist_ok=True,parents=True);page.screenshot(path=str(evidence/'tg-remittance-desktop.png'));page.set_viewport_size({'width':390,'height':844});page.screenshot(path=str(evidence/'tg-remittance-phone.png'));page.get_by_role('button',name='POST TG REMITTANCE').click();page.get_by_role('heading',name='TG REMITTANCE POSTED').wait_for();assert req()[1]['items']==[]
    # The production receipt UI selects multiple invoices and an advance without losing typed advice.
    (root/'data/accounts.json').write_text(json.dumps(basket_state))
    (root/'accounts/receipt-harness.html').write_text('<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><script>localStorage.setItem("tt_accounts_entity","TTI");window.TT_ACCOUNT_ACCESS={csrf:"fixture"};</script><link rel="stylesheet" href="/brand-theme.css"><script src="/brand-theme.js"></script><script src="export-receipts-ui.js"></script><button onclick="TT_EXPORT_RECEIPTS_UI.openForm()">RECEIPT</button>')
    page.route('**/api/bank_accounts.php?*',lambda route:route.fulfill(json={'ok':True,'accounts':[{'id':'PKR','bankName':'BANK','accountNumber':'789','currency':'PKR','settings':{'defaultReceiptAccount':True}}]}))
    page.route('**/api/export_realization_master.php',lambda route:route.fulfill(json={'ok':True,'rows':[]}))
    page.route('**/api/tg_bank_transactions.php?*',lambda route:route.fulfill(json={'ok':True,'banks':[{'id':'USD','bank':'TG BANK','title':'USD SENDER','currency':'USD','balance':{'native':1000}}],'openLiabilities':[{'id':'TG-PAY-1'},{'id':'TG-PAY-2'}]}))
    page.set_viewport_size({'width':1280,'height':900});page.goto(f'http://127.0.0.1:{port}/accounts/receipt-harness.html');page.get_by_role('button',name='RECEIPT',exact=True).click();page.locator('[data-payer-type=TG]').click()
    page.locator('#erTgItem').select_option('TGPACK|BASKET-1');page.locator('#erBankRef').fill('BASKET-UI');page.locator('#erForeign').fill('340');page.locator('#erRate').fill('280');page.locator('#erBankCredit').fill('95200')
    page.locator('#erTgItem').select_option('TGPACK|BASKET-2');assert page.locator('#erBankRef').input_value()=='BASKET-UI' and page.locator('#erForeign').input_value()=='340'
    assert page.locator('#erTgItem option[value="TGPACK|BASKET-1"]').count()==0
    page.locator('#erTgItem').select_option('TGADV');page.locator('#erTgAdvance').fill('50');page.locator('#erAddTgAdvance').click()
    assert page.locator('#erTgBasket .tter-item').count()==3 and 'USD 350' in page.locator('#erTgTotal').inner_text()
    assert 'USD 10' in page.locator('#erTgCorrespondent').inner_text() and '2,800' in page.locator('#erAccountingRows').inner_text()
    labels=page.locator('#erTgItem').evaluate('el=>[...el.closest(".tter-grid").querySelectorAll(":scope > label")].map(x=>x.childNodes[0].textContent.trim())');assert labels[:3]==['Receipt Currency','Sender account','Invoice / Advance']
    page.locator('[data-tg-remove="TGPACK|BASKET-2"]').click();assert page.locator('#erTgItem option[value="TGPACK|BASKET-2"]').count()==1
    page.locator('#erTgItem').select_option('TGPACK|BASKET-2');assert 'USD 350' in page.locator('#erTgTotal').inner_text() and page.locator('#erBankRef').input_value()=='BASKET-UI'
    for index,pct,amount in [(0,'1','980.00'),(1,'0.1','98.00'),(2,'15','14.70')]:
     page.locator('[data-ded-percent]').nth(index).fill(pct);assert page.locator('[data-ded-amount]').nth(index).input_value()==amount
    page.locator('#erBankCredit + input[data-tt-numeric-proxy]').fill('94107.30');assert page.locator('#erBankCredit + input').input_value()=='94,107.30';assert page.locator('#erPost').is_enabled()
    page.screenshot(path=str(evidence/'tg-multiple-invoices-advance-desktop.png'));page.set_viewport_size({'width':390,'height':844});page.screenshot(path=str(evidence/'tg-multiple-invoices-advance-phone.png'))
    page.locator('#erPost').click();page.locator('#ttExportReceiptToast').filter(has_text='posted').wait_for()
    saved_ui=json.loads((root/'data/accounts.json').read_text());receipt_ui=next(x for x in saved_ui['exportReceipts'].values() if x['bankAdviceRef']=='BASKET-UI')
    assert receipt_ui['tgPaymentTotal']==350 and receipt_ui['foreignAmount']==340 and receipt_ui['correspondentPkrAmount']==2800
    assert req()[1]['items'][0]['amountNative']==350 and next(x for x in req()[1]['banks'] if x['id']=='USD')['balance']['posted']==0
    # Partial form posts one split at its rate and closes beneath the shared confirmation.
    (root/'data/accounts.json').write_text(json.dumps(basket_state))
    original=(root/'accounts/receipt-harness.html').read_text();(root/'accounts/partial-harness.html').write_text(original.replace('<script src="export-receipts-ui.js">','<script src="post-confirmation-ui.js"></script><script src="export-receipts-ui.js">'))
    page.set_viewport_size({'width':1280,'height':900});page.goto(f'http://127.0.0.1:{port}/accounts/partial-harness.html')
    page.evaluate("window.realFetch=window.fetch;window.fetch=(url,options)=>String(url).includes('export_receipts.php')&&!options?.body?new Promise(resolve=>window.releaseReceipt=()=>resolve(realFetch(url,options))):realFetch(url,options);window.openingReceipt=TT_EXPORT_RECEIPTS_UI.openForm();void 0")
    page.locator('[data-er-close]').click();page.evaluate('releaseReceipt();window.fetch=realFetch');page.evaluate('async()=>await openingReceipt');assert page.locator('#ttExportReceiptDialog').evaluate('el=>el.hidden')
    page.get_by_role('button',name='RECEIPT',exact=True).click();page.locator('[data-payer-type=TG]').click();page.locator('#erTgItem').select_option('TGPACK|BASKET-1');page.locator('#erPartialReceipt').check();page.locator('#erPaymentPart').fill('1');page.locator('#erForeign').fill('58');assert page.locator('[data-tg-amount]').input_value()=='58';page.locator('#erCorrespondentAmount').fill('2');page.locator('#erRate').fill('280');page.locator('#erBankRef').fill('PARTIAL-UI')
    for index,pct in [(0,'1'),(1,'0.1'),(2,'15')]:page.locator('[data-ded-percent]').nth(index).fill(pct)
    assert page.locator('[data-tg-amount]').input_value()=='60'
    assert page.locator('[data-ded-amount]').nth(0).input_value()=='168.00'
    page.locator('[data-ded-amount]').nth(1).fill('20');page.locator('#erRate').fill('281');assert page.locator('[data-ded-amount]').nth(1).input_value()=='20' and page.locator('[data-ded-amount]').nth(2).input_value()=='3.00'
    page.locator('[data-ded-percent]').nth(1).fill('0.1');page.locator('#erRate').fill('280')
    page.locator('#erBankCredit + input[data-tt-numeric-proxy]').fill('16052.68');assert page.locator('#erPost').is_enabled();page.locator('#erPost').click();page.locator('#tt-post-confirmation').wait_for();page.wait_for_function('document.querySelector("#ttExportReceiptDialog").hidden');assert page.locator('#tt-post-confirmation td').filter(has_text='Correspondent').count()==1
    source=next(x for x in req('export_receipts.php?entity=TTI')[1]['sources']['invoices'] if x['id']=='EXP|BASKET-1');assert source['outstandingForeign']==40
    # Large debit/credit figures remain inside distinct table columns; mobile scrolls horizontally.
    page.evaluate("TT_POST_CONFIRMATION.showJournal({id:'LAYOUT ONLY',entity:'TTI',date:'2026-10-10',lines:[{accountName:'Long bank and customer account name',debit:96747942,credit:96747942}]})")
    cells=page.locator('#tt-post-confirmation td');bounds=[cells.nth(i).bounding_box() for i in range(3)];assert bounds[0]['x']+bounds[0]['width']<=bounds[1]['x']+.1 and bounds[1]['x']+bounds[1]['width']<=bounds[2]['x']+.1
    page.set_viewport_size({'width':390,'height':844});assert page.locator('#tt-post-confirmation table').evaluate('el=>el.parentElement.scrollWidth>el.parentElement.clientWidth')
    browser.close()
  print('TG HTTP and optional browser: pending balance, charges/VAT, split grouping, permissions, stale prevention, USD ledger and idempotency passed')
 finally:
  server.terminate();server.wait(timeout=5);log.close()


