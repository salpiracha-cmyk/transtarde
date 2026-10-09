"""Actual TG endpoint: permissions, CSRF, stale reviews, pending funds and grouped bank debit."""
import json,os,pathlib,shutil,socket,subprocess,tempfile,time,urllib.request,urllib.error
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tg-remittance-') as tmp:
 root=pathlib.Path(tmp);(root/'api').mkdir();(root/'accounts').mkdir();(root/'data').mkdir()
 for name in ['accounts_subaccounts_core.php','assets_registry_core.php','export_receipts.php','export_receipt_tg_mirror.php','accounts_receipt_amend_core.php','tg_remittances.php','tg_remittance_core.php','fi_credit_advice_link.php','receipt_invoice_links.php','accounts_reviews.php','accounts_reviews_core.php','accounts_dashboard.php','customer_receivables_core.php','bank_accounts.php','expense_reminders.php','accounts_ledger_browser.php','accounts_post_delete_core.php','accounts_reference.php']:
  shutil.copy(ROOT/'api'/name,root/'api'/name)
 for name in ['accounting_master_v1.json','settlement_policy_v1.json','export_realization_policy_v1.json','tg-remittances-ui.js']:
  shutil.copy(ROOT/'accounts'/name,root/'accounts'/name)
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
 function tt_list_masters(){return ['banks'=>[['id'=>'PKR','values'=>['Company Account','TTI','','TTI PKR','BANK','','','PKR','789','','','','','Active']],['id'=>'USD','values'=>['Company Account','TG','','TG USD','BANK','','','USD','123','','','','','Active']],['id'=>'AED','values'=>['Company Account','TG','','TG AED','BANK','','','AED','456','','','','','Active']]]];}
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
  # Browser uses the actual endpoint again from a fresh pending fixture.
  if os.environ.get('TT_QA_BROWSER')=='1':
   from playwright.sync_api import sync_playwright
   (root/'data/accounts.json').write_text(json.dumps(s))
   desk=(ROOT/'accounts/accounts-accounting-desk.js').read_text();start=desk.index('style.textContent = `')+len('style.textContent = `') if 'style.textContent = `' in desk else -1
   css=desk[start:desk.index('`;',start)] if start!=-1 else '.tt-layer{position:fixed;inset:0;padding:16px;background:#112233aa}.tt-window{background:#f7fafc;max-height:94vh;overflow:auto;padding:20px}.tt-form-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}label{display:grid}input,select{min-width:0;width:100%;box-sizing:border-box}'
   (root/'accounts/harness.html').write_text('<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>'+css+'</style><script>localStorage.setItem("tt_accounts_entity","TG");window.TT_ACCOUNT_ACCESS={csrf:"fixture"};window.TT_FORM_VIEWPORT={open:h=>h.scrollTop=0};</script><script src="tg-remittances-ui.js"></script><button onclick="TT_TG_REMITTANCES.open()">REVIEW</button>')
   with sync_playwright() as pw:
    browser=pw.chromium.launch();page=browser.new_page(viewport={'width':1280,'height':900});page.goto(f'http://127.0.0.1:{port}/accounts/harness.html');page.get_by_role('button',name='REVIEW',exact=True).click();page.locator('[data-id="DRAFT-1"]').check();page.locator('[name=chargeAmount]').fill('30');page.locator('[name=vatAmount]').fill('1.50');page.locator('[name=sameRemittanceConfirmed]').check();assert '140,000.00' in page.locator('#tgr-total').inner_text();assert '140,031.50' in page.locator('#tgr-debit').inner_text()
    evidence=pathlib.Path(os.environ.get('TT_QA_OUTPUT',str(root/'evidence')));evidence.mkdir(exist_ok=True,parents=True);page.screenshot(path=str(evidence/'tg-remittance-desktop.png'));page.set_viewport_size({'width':390,'height':844});page.screenshot(path=str(evidence/'tg-remittance-phone.png'));page.get_by_role('button',name='POST TG REMITTANCE').click();page.get_by_role('heading',name='TG REMITTANCE POSTED').wait_for();assert req()[1]['items']==[];browser.close()
  print('TG HTTP and optional browser: pending balance, charges/VAT, split grouping, permissions, stale prevention, USD ledger and idempotency passed')
 finally:
  server.terminate();server.wait(timeout=5);log.close()


