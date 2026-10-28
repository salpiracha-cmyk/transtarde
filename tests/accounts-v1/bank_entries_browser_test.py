"""Disposable real-HTTP browser journey for bank entries and named subaccounts."""
import json, os, pathlib, shutil, socket, subprocess, tempfile, time
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='bank-entries-browser-') as tmp:
 root=pathlib.Path(tmp)
 for name in ['api','accounts','data']:(root/name).mkdir()
 for name in ['bank_sales_receipts_core.php','local_sales_scope.php','operations_scope.php','mill_recovery_core.php','arrival_withdrawal_core.php','paltai_core.php','accounts_reference.php','accounts_payees.php','bank_reconciliation.php','bank_reconciliation_core.php','bank_entries.php','bank_entries_core.php','mill_cash_core.php','expense_locations.php','mill_cash_ledger.php','accounts_subaccounts.php','accounts_subaccounts_core.php','accounts_receipts_core.php','accounts_ledger_visibility.php','assets_registry_core.php','accounts_bank_payment.php', 'accounts_posting_preview.php', 'tg_native_currency.php']:shutil.copy(ROOT/'api'/name,root/'api'/name)
 for name in ['inventory_reconciliation.php','product_stage.php']:shutil.copy(ROOT/name,root/name)
 for name in ['bank-reconciliation-ui.js','accounting_master_v1.json','account-management-ui.js','cheque-date-ui.js','bank-payment-details.js','print-dialog.js','post-confirmation-ui.js']:shutil.copy(ROOT/'accounts'/name,root/'accounts'/name)
 (root/'auth_store.php').write_text('''<?php
 define('TT_DATA_DIR',__DIR__.'/data');function tt_ensure_data_dir(){}
 function tt_require_login(){return ['id'=>1,'username'=>'Fixture','role'=>'Super Admin','permissions'=>['Accounts'=>'all']];}
 function tt_current_user(){return tt_require_login();}
 function tt_active_location_masters(){return [['id'=>'mills-1','values'=>['TTI Rice Mills','','Own Mill','','','Active','','TTI']]];}
 function tt_normalize_location_type($type){return $type;}
 function tt_location_identity($name){return strtolower(preg_replace('/[^a-z0-9]+/i','',$name));}
 function tt_release_read_session(){}
 function tt_user_can_open_module($u,$m){return true;}
 function tt_user_can_module_action($u,$m,$i,$a){return true;}
 function tt_user_can_access_entity($u,$e,$a){return $e==='TTI';}
 function tt_verify_csrf($v){return $v==='fixture';}
 function tt_bank_can_transact($id){return true;}
 function tt_list_masters(){return ['banks'=>[['id'=>'BANK-1','depositType'=>'SAVING','values'=>['Company Account','TTI','','Company Bank','Meezan','','','PKR','123456789','PK01TEST','','','','Active']],['id'=>'BANK-2','depositType'=>'CURRENT','values'=>['Company Account','TTI','','Company Second Bank','SC','','','PKR','234567890','PK02TEST','','','','Active']]]];}
 function tt_next_post_id($rows,$module='Accounts',$kind='Journal',$date=null){$n=count($rows)+1;do{$id='2026-'.str_pad((string)$n++,5,'0',STR_PAD_LEFT);}while(isset($rows[$id]));return $id;}
 ''')
 from permission_fixture import append_permission_helpers
 append_permission_helpers(root)
 (root/'data/accounts.json').write_text(json.dumps({'revision':0,'journals':{},'bankAccountSettings':{'BANK-1':{'defaultPaymentAccount':True}}}))
 (root/'data/operations.json').write_text(json.dumps({'values':{'tt35localsales':json.dumps([{'soda':'LS-BROWSER','party':'Local Buyer','entity':'TTI','date':'2026-01-01','millName':'TTI Rice Mills','product':'White Rice'}])}}))
 (root/'accounts/index.html').write_text('''<!doctype html><meta name=viewport content="width=device-width, initial-scale=1"><style>body{font:14px Arial}input,select{box-sizing:border-box}.btn{padding:9px;margin:4px;border:1px solid #abc;border-radius:6px;background:white;cursor:pointer}</style><button id=bank onclick="TT_BANK_ENTRIES.open()">Bank Entry</button><button id=recon onclick="TT_BANK_RECONCILIATION.open()">Reconcile</button><button id=sub onclick="TT_SUBACCOUNTS.open()">Subaccounts</button><script>localStorage.setItem('tt_accounts_entity','TTI');window.TT_ACCOUNT_ACCESS={csrf:'fixture',super:true,permissions:'all'};window.TT_FORM_VIEWPORT={open:e=>{e.scrollTop=0}};</script><script src=print-dialog.js></script><script src=post-confirmation-ui.js></script><script src=cheque-date-ui.js></script><script src=bank-payment-details.js></script><script src=account-management-ui.js></script><script src=bank-reconciliation-ui.js></script><script>window.printEvents=0;const originalPrint=TT_PRINT.html;TT_PRINT.html=html=>{const w=originalPrint(html);w.__nativePrint=()=>printEvents++;return w};</script>''')
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')})
 try:
  time.sleep(.1)
  output=pathlib.Path(os.environ.get('TT_QA_OUTPUT',tempfile.gettempdir()));output.mkdir(parents=True,exist_ok=True)
  browser_script=ROOT/'tests/accounts-v1/bank_entries_browser_test.js'
  if os.environ.get('TT_QA_NODE_BROWSER'):
   subprocess.run(['node',str(browser_script),f'http://127.0.0.1:{port}'],check=True)
  else:
   from playwright.sync_api import sync_playwright
   with sync_playwright() as p:
    browser=p.chromium.launch();page=browser.new_page(viewport={'width':1280,'height':900});errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
    page.goto(f'http://127.0.0.1:{port}/accounts/index.html');page.locator('#sub').click();page.get_by_role('button',name='+ Add Subaccount',exact=True).click();page.locator('[name=name]').fill('Office stationery');page.locator('[name=parentCode]').select_option('6900');page.get_by_role('button',name='Save Subaccount',exact=True).click();page.get_by_role('button',name='Edit / Move',exact=True).first.wait_for();page.locator('[data-close]').click();page.locator('#bank').click()
    f=page.locator('#amBankForm');assert f.locator('[name=type]').input_value()=='PAYMENT'
    f.locator('[data-new-expense]').evaluate('(el)=>el.click()');setup=page.locator('#payeeSetup');setup.locator('[name=name]').fill('Caretaker');assert setup.locator('[name=category]').is_hidden();setup.get_by_role('button',name='Save Setup',exact=True).click();setup.wait_for(state='detached');page.wait_for_function("document.querySelector('[data-payee-choice]').value === 'EXP|caretaker'");f.locator('[data-expense-account]').evaluate("el=>el.value='6910'");f.locator('[data-debit]').fill('100');f.locator('[data-row-narration]').fill('Home expense payment');f.locator('[data-row-method]').select_option('ONLINE_BANKING')
    popups=[];page.on('popup',lambda popup:popups.append(popup));f.get_by_role('button',name='Post',exact=True).click();page.locator('[data-review-post]').click()
    page.locator('#amWindow').wait_for(state='detached');confirmation=page.locator('#tt-post-confirmation');confirmation.wait_for();assert confirmation.bounding_box()['height']<520;assert page.locator('iframe').count()==0 and not popups
    confirmation.screenshot(path=str(output/'compact-post-confirmation.png'));confirmation.locator('[data-print]').click();page.wait_for_function('printEvents===1');voucher=page.frame_locator('iframe[title="Print document"]');voucher.locator('.voucher').wait_for();assert voucher.locator('.voucher').bounding_box()['height']<520;assert voucher.locator('body').inner_text().count('123456789')==1 and not popups;confirmation.locator('[data-close]').click();page.evaluate("TT_BANK_ENTRIES.open('', 'FINANCE')");page.locator('[data-new]').click()
    f=page.locator('#amBankForm');f.locator('[name=type]').select_option('SAVING_PROFIT');f.locator('[name=amount]').fill('1000');f.locator('[name=withholdingTax]').fill('150');f.locator('[name=reference]').fill('BROWSER-PROFIT');f.locator('[name=narration]').fill('Saving profit');assert not page.locator('#amPayBankDetails').is_visible();assert '850.00' in page.locator('[data-total]').inner_text();f.get_by_role('button',name='Post',exact=True).click();page.locator('[data-review-post]').click();page.locator('#amWindow').wait_for(state='detached');page.locator('#tt-post-confirmation [data-close]').click();page.evaluate("TT_BANK_ENTRIES.open('', 'FINANCE')");page.locator('[data-facility]').click();page.locator('[name=name]').fill('Export refinance');page.get_by_role('button',name='Save Facility',exact=True).click();page.get_by_role('button',name='Finance Entry',exact=True).click()
    f=page.locator('#amBankForm');f.locator('[name=amount]').fill('5000');f.locator('[name=reference]').fill('BROWSER-DRAW');f.locator('[name=narration]').fill('Finance advance');f.get_by_role('button',name='Post',exact=True).click();page.locator('[data-review-post]').click();page.locator('#amWindow').wait_for(state='detached');page.locator('#tt-post-confirmation [data-close]').click();page.evaluate("TT_BANK_ENTRIES.open('', 'FINANCE')");assert '5,000.00' in page.locator('[data-body]').inner_text();page.get_by_role('button',name='Finance Entry',exact=True).click()
    f=page.locator('#amBankForm');f.locator('[name=type]').select_option('FINANCE_REPAY');f.locator('[name=amount]').fill('1000');f.locator('[name=reference]').fill('BROWSER-REPAY');f.locator('[name=narration]').fill('Principal repayment');page.locator('#amPayBankDetails [data-method]').select_option('ONLINE_BANKING');f.get_by_role('button',name='Post',exact=True).click();page.locator('[data-review-post]').click();page.locator('#amWindow').wait_for(state='detached');page.locator('#tt-post-confirmation [data-close]').click();page.evaluate("TT_BANK_ENTRIES.open('', 'FINANCE')");assert '4,000.00' in page.locator('[data-body]').inner_text()
    page.screenshot(path=str(output/'bank-entries-desktop.png'),full_page=True);page.set_viewport_size({'width':390,'height':844});page.screenshot(path=str(output/'bank-entries-mobile.png'),full_page=True);assert page.locator('.am-box').bounding_box()['width']<=390;
    table=page.locator('.am-finance-table');assert table.bounding_box()['width']>=680
    assert table.evaluate('el=>el.parentElement.scrollWidth>el.parentElement.clientWidth'), 'Finance table scrolls inside its phone container'
    actions=table.locator('tbody tr').first.locator('td').last.locator('button').all();assert len(actions)==3
    assert max(b.bounding_box()['y'] for b in actions)-min(b.bounding_box()['y'] for b in actions)<2, 'Finance actions stay in one row'

    page.locator('[data-close]').click();page.locator('#sub').click()
    stored=json.loads((root/'data/accounts.json').read_text());sid=next(id for id,row in stored['accountSubaccounts'].items() if row['name']=='OFFICE STATIONERY')
    page.locator(f'[data-child="{sid}"]').click();page.locator('[name=name]').fill('Printer supplies');assert page.locator('[name=parentCode]').input_value()=='SUB|'+sid
    page.get_by_role('button',name='Save Subaccount',exact=True).click();page.get_by_role('button',name='Edit / Move',exact=True).first.wait_for()
    stored=json.loads((root/'data/accounts.json').read_text());cid=next(id for id,row in stored['accountSubaccounts'].items() if row['name']=='PRINTER SUPPLIES')
    page.locator(f'[data-edit="{sid}"]').click();assert page.locator(f'[name=parentCode] option[value="SUB|{cid}"]').count()==0
    page.locator('[name=parentCode]').select_option('6400');page.get_by_role('button',name='Save Subaccount',exact=True).click();page.get_by_role('button',name='Edit / Move',exact=True).first.wait_for()
    stored=json.loads((root/'data/accounts.json').read_text());assert stored['accountSubaccounts'][cid]['parentCode']=='6400'
    page.locator('[data-close]').click();page.locator('#recon').click();page.locator('#ttBrWindow [name=bankId]').select_option('BANK-1');page.locator('#ttBrWindow [data-save]').wait_for()
    page.locator('#ttBrWindow [data-save]').click();assert 'Enter the statement closing balance' in page.locator('#ttBrWindow [data-message]').inner_text()
    balance=sum(float(j['lines'][i].get('bankDebit',l.get('debit',0)))-float(j['lines'][i].get('bankCredit',l.get('credit',0))) for j in stored['journals'].values() for i,l in enumerate(j['lines']) if l['account']=='1110' and l.get('bankAccountId')=='BANK-1')
    for box in page.locator('#ttBrWindow [data-key]').all():box.check()
    page.locator('#ttBrWindow [name=statementBalance]').fill(str(balance));assert '0.00' in page.locator('[data-value=difference]').inner_text()
    page.locator('#ttBrWindow [data-save]').click();page.get_by_text('Reconciliation saved. No unexplained difference.',exact=True).wait_for()
    assert json.loads((root/'data/accounts.json').read_text())['bankReconciliations']['TTI|BANK-1']['status']=='Reconciled'
    page.locator('#ttBrWindow [data-close]').click();books_before_report=(root/'data/accounts.json').read_bytes()
    page.evaluate("TT_SUBACCOUNTS.open('tax')");page.locator('[data-tax-results]').get_by_text('BROWSER-PROFIT',exact=False).wait_for()
    assert '150.00' in page.locator('[data-tax-results]').inner_text() and 'SAVINGS' in page.locator('[data-tax-results]').inner_text()
    page.locator('[data-tax-category]').select_option('SAVINGS');page.locator('[data-tax-print]').click();page.wait_for_function('printEvents===2')
    tax_print=page.frame_locator('iframe[title="Print document"]');assert 'BROWSER-PROFIT' in tax_print.locator('body').inner_text()
    with page.expect_download() as download_info:page.locator('[data-tax-csv]').click()
    csv_path=root/'withholding.csv';download_info.value.save_as(str(csv_path));csv_text=csv_path.read_text(encoding='utf-8-sig');assert 'BROWSER-PROFIT' in csv_text and 'SAVINGS' in csv_text and '150' in csv_text
    page.locator('[data-tax-filter] [name=from]').fill('2000-01-01');assert page.locator('[data-tax-print]').is_disabled() and page.locator('[data-tax-csv]').is_disabled()
    assert (root/'data/accounts.json').read_bytes()==books_before_report,'WHT view, print or downloaded CSV changed original books'
    # Actual receipt selectors, review cancellation and explicit linked postings.
    page.locator('[data-close]').click();page.set_viewport_size({'width':1280,'height':900})
    stored=json.loads((root/'data/accounts.json').read_text());stored['exporterDeals']={'HK':{'id':'HK','entity':'TTI','partyId':'HKM','party':'Haji Khushi Mohammed','reference':'HKM-BROWSER','product':'White Rice','direction':'SALE','date':'2026-01-01','version':1,'payments':[]}}
    (root/'data/accounts.json').write_text(json.dumps(stored));before=(root/'data/accounts.json').read_bytes()
    for kind,party,soda,reference in [('LOCAL_SALE','Local Buyer','LS-BROWSER','BROWSER-LOCAL-RECEIPT'),('FELLOW_EXPORTER','HKM','HK','BROWSER-HKM-RECEIPT')]:
     page.evaluate("TT_BANK_ENTRIES.open('BANK-1','RECEIPT')");f=page.locator('#amBankForm');f.locator('[name=receiptKind]').select_option(kind)
     f.locator(f'[name=receiptPartyId] option[value="{party}"]').wait_for(state='attached');f.locator('[name=receiptPartyId]').select_option(party);f.locator('[name=receiptSodaId]').select_option(soda)
     assert f.locator('[name=creditAccountCode]').is_hidden() and f.locator('[name=payee]').is_hidden()
     f.locator('[name=amount]').fill('5000');f.locator('[name=reference]').fill(reference)
     assert f.locator('[name=receiptSodaId]').input_value()==soda and (root/'data/accounts.json').read_bytes()==before
     page.screenshot(path=str(output/(kind.lower()+'-bank-receipt.png')),full_page=True)
     f.get_by_role('button',name='Post',exact=True).click();page.locator('[data-review-cancel]').click()
     page.get_by_text('Posting cancelled. Your entry remains available to review.',exact=True).wait_for();assert (root/'data/accounts.json').read_bytes()==before
     assert f.locator('[name=receiptSodaId]').input_value()==soda
     f.get_by_role('button',name='Post',exact=True).click();page.locator('[data-review-post]').click();page.locator('#amWindow').wait_for(state='detached');page.locator('#tt-post-confirmation').wait_for()
     stored=json.loads((root/'data/accounts.json').read_text());journals=[j for j in stored['journals'].values() if j['reference']==reference];assert len(journals)==1
     j=journals[0];assert sum(l.get('debit',0) for l in j['lines'] if l['account']=='1110')==5000
     assert not any(l['account'] in ['4100','4200'] for l in j['lines'])
     if kind=='FELLOW_EXPORTER':assert all(l.get('exporterDealId')=='HK' for l in j['lines']) and stored['exporterDeals']['HK']['payments'][0]['postId']==j['id']
     else:assert next(l for l in j['lines'] if l['account']=='2510')['soda']=='LS-BROWSER'
     page.locator('#tt-post-confirmation [data-close]').click();before=(root/'data/accounts.json').read_bytes()
    # Other Receipt type manager keeps the bank draft open and uses the shared master lifecycle.
    page.evaluate("TT_BANK_ENTRIES.open('BANK-1','RECEIPT')");f=page.locator('#amBankForm');f.locator('[name=amount]').fill('750');f.locator('[name=reference]').fill('BROWSER-OTHER-RECEIPT');f.locator('[name=payee]').fill('Other receipt payer');f.locator('[data-receipt-types]').click()
    manager=page.locator('#amReceiptTypesWindow');manager.get_by_role('button',name='+ Add Receipt Type',exact=True).click();manager.locator('[name=name]').fill('Browser other collections');manager.locator('[name=parentCode]').select_option('4400');manager.get_by_role('button',name='Save Receipt Type',exact=True).click();manager.locator('[data-type-edit]').wait_for()
    stored=json.loads((root/'data/accounts.json').read_text());type_id=next(id for id,a in stored['accountSubaccounts'].items() if a.get('receiptType'))
    assert f.locator('[name=amount]').input_value() in ['750','750.00'] and f.locator('[name=receiptTypeId]').input_value()==type_id
    manager.locator('[data-types-close]').click();assert f.locator('[name=payee]').is_visible() and f.locator('[name=creditAccountCode]').is_hidden()
    page.screenshot(path=str(output/'other-bank-receipt-type.png'),full_page=True);f.get_by_role('button',name='Post',exact=True).click();page.locator('[data-review-post]').click();page.locator('#amWindow').wait_for(state='detached');page.locator('#tt-post-confirmation [data-close]').click()
    stored=json.loads((root/'data/accounts.json').read_text());typed_j=next(j for j in stored['journals'].values() if j['reference']=='BROWSER-OTHER-RECEIPT');assert typed_j['meta']['receiptTypeId']==type_id and typed_j['lines'][0]['account']=='4400'
    page.evaluate("TT_BANK_ENTRIES.open('BANK-1','RECEIPT')");page.locator('[data-receipt-types]').click();manager=page.locator('#amReceiptTypesWindow');manager.locator('[data-type-edit]').click();manager.locator('[name=name]').fill('Browser other receipts renamed');manager.get_by_role('button',name='Save Receipt Type',exact=True).click();manager.locator('[data-type-delete]').wait_for();manager.locator('[data-type-delete]').click();manager.get_by_text('BROWSER OTHER RECEIPTS RENAMED (Deleted)',exact=False).wait_for()
    stored=json.loads((root/'data/accounts.json').read_text());assert not stored['accountSubaccounts'][type_id]['active'] and stored['journals'][typed_j['id']]==typed_j
    assert page.locator('#amBankForm [name=receiptTypeId] option').count()==1
    assert not errors,errors;browser.close()
   print('Bank Entry browser: subaccount creation, profit/tax, facility, drawdown, repayment, conditional fields, vouchers and mobile passed.')
 finally:server.terminate();server.wait()

