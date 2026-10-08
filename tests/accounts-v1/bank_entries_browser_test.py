"""Disposable real-HTTP browser journey for bank entries and named subaccounts."""
import json, os, pathlib, shutil, socket, subprocess, tempfile, time
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='bank-entries-browser-') as tmp:
 root=pathlib.Path(tmp)
 for name in ['api','accounts','data']:(root/name).mkdir()
 for name in ['accounts_payees.php','bank_reconciliation.php','bank_reconciliation_core.php','bank_entries.php','bank_entries_core.php','accounts_subaccounts.php','accounts_subaccounts_core.php','assets_registry_core.php','accounts_bank_payment.php']:shutil.copy(ROOT/'api'/name,root/'api'/name)
 for name in ['bank-reconciliation-ui.js','accounting_master_v1.json','account-management-ui.js','bank-payment-details.js']:shutil.copy(ROOT/'accounts'/name,root/'accounts'/name)
 (root/'auth_store.php').write_text('''<?php
 define('TT_DATA_DIR',__DIR__.'/data');function tt_ensure_data_dir(){}
 function tt_require_login(){return ['id'=>1,'username'=>'Fixture','role'=>'Super Admin','permissions'=>['Accounts'=>'all']];}
 function tt_user_can_open_module($u,$m){return true;}
 function tt_user_can_module_action($u,$m,$i,$a){return true;}
 function tt_user_can_access_entity($u,$e,$a){return $e==='TTI';}
 function tt_verify_csrf($v){return $v==='fixture';}
 function tt_bank_can_transact($id){return true;}
 function tt_list_masters(){return ['banks'=>[['id'=>'BANK-1','depositType'=>'SAVING','values'=>['Company Account','TTI','','Company Bank','Meezan','','','PKR','123456789','PK01TEST','','','','Active']],['id'=>'BANK-2','depositType'=>'CURRENT','values'=>['Company Account','TTI','','Company Second Bank','SC','','','PKR','234567890','PK02TEST','','','','Active']]]];}
 function tt_next_post_id($rows,$module='Accounts',$kind='Journal',$date=null){$n=count($rows)+1;do{$id='2026-'.str_pad((string)$n++,5,'0',STR_PAD_LEFT);}while(isset($rows[$id]));return $id;}
 ''')
 (root/'data/accounts.json').write_text(json.dumps({'revision':0,'journals':{},'bankAccountSettings':{'BANK-1':{'defaultPaymentAccount':True}}}))
 (root/'accounts/index.html').write_text('''<!doctype html><meta name=viewport content="width=device-width, initial-scale=1"><style>body{font:14px Arial}input,select{box-sizing:border-box}.btn{padding:9px;margin:4px;border:1px solid #abc;border-radius:6px;background:white;cursor:pointer}</style><button id=bank onclick="TT_BANK_ENTRIES.open()">Bank Entry</button><button id=recon onclick="TT_BANK_RECONCILIATION.open()">Reconcile</button><button id=sub onclick="TT_SUBACCOUNTS.open()">Subaccounts</button><script>localStorage.setItem('tt_accounts_entity','TTI');window.TT_ACCOUNT_ACCESS={csrf:'fixture',super:true,permissions:'all'};window.TT_FORM_VIEWPORT={open:e=>{e.scrollTop=0}};</script><script src=bank-payment-details.js></script><script src=account-management-ui.js></script><script src=bank-reconciliation-ui.js></script>''')
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
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
    f.locator('[data-new-expense]').click();setup=page.locator('#payeeSetup');setup.locator('[name=name]').fill('Caretaker');setup.locator('[name=category]').select_option('HOME');setup.get_by_role('button',name='Save Setup',exact=True).click();setup.wait_for(state='detached');f.locator('[data-debit]').fill('100');f.locator('[name=narration]').fill('Home expense payment');page.locator('#amPayBankDetails [data-method]').select_option('ONLINE_BANKING')
    with page.expect_popup() as printed:f.get_by_role('button',name='Post & Print Voucher',exact=True).click()
    voucher=printed.value;page.get_by_text('Bank entry posted',exact=True).wait_for();voucher.locator('.voucher').wait_for();assert voucher.locator('.voucher').bounding_box()['height']<520;assert voucher.locator('body').inner_text().count('123456789')==1;voucher.screenshot(path=str(output/'compact-payment-voucher.png'));voucher.close();page.locator('[data-next]').click();page.locator('[data-new]').click()
    f=page.locator('#amBankForm');f.locator('[name=type]').select_option('SAVING_PROFIT');f.locator('[name=amount]').fill('1000');f.locator('[name=withholdingTax]').fill('150');f.locator('[name=reference]').fill('BROWSER-PROFIT');f.locator('[name=narration]').fill('Saving profit');assert not page.locator('#amPayBankDetails').is_visible();assert '850.00' in page.locator('[data-total]').inner_text();f.get_by_role('button',name='Post & Print Voucher',exact=True).click();page.get_by_text('Bank entry posted',exact=True).wait_for();page.locator('[data-next]').click();page.locator('[data-facility]').click();page.locator('[name=name]').fill('Export refinance');page.get_by_role('button',name='Save Facility',exact=True).click();page.get_by_role('button',name='Finance Entry',exact=True).click()
    f=page.locator('#amBankForm');f.locator('[name=amount]').fill('5000');f.locator('[name=reference]').fill('BROWSER-DRAW');f.locator('[name=narration]').fill('Finance advance');f.get_by_role('button',name='Post & Print Voucher',exact=True).click();page.get_by_text('Bank entry posted',exact=True).wait_for();page.locator('[data-next]').click();assert '5,000.00' in page.locator('[data-body]').inner_text();page.get_by_role('button',name='Finance Entry',exact=True).click()
    f=page.locator('#amBankForm');f.locator('[name=type]').select_option('FINANCE_REPAY');f.locator('[name=amount]').fill('1000');f.locator('[name=reference]').fill('BROWSER-REPAY');f.locator('[name=narration]').fill('Principal repayment');page.locator('#amPayBankDetails [data-method]').select_option('ONLINE_BANKING');f.get_by_role('button',name='Post & Print Voucher',exact=True).click();page.get_by_text('Bank entry posted',exact=True).wait_for();page.locator('[data-next]').click();assert '4,000.00' in page.locator('[data-body]').inner_text()
    page.screenshot(path=str(output/'bank-entries-desktop.png'),full_page=True);page.set_viewport_size({'width':390,'height':844});page.screenshot(path=str(output/'bank-entries-mobile.png'),full_page=True);assert page.locator('.am-box').bounding_box()['width']<=390;
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
    assert not errors,errors;browser.close()
   print('Bank Entry browser: subaccount creation, profit/tax, facility, drawdown, repayment, conditional fields, vouchers and mobile passed.')
 finally:server.terminate();server.wait()

