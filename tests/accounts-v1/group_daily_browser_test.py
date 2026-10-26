"""Native browser -> real PHP -> saved Daily snapshot, using disposable source files only."""
import json, os, pathlib, shutil, socket, subprocess, tempfile, time, urllib.request
from playwright.sync_api import sync_playwright
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='daily-browser-') as tmp:
 root=pathlib.Path(tmp);shutil.copytree(ROOT/'api',root/'api');shutil.copytree(ROOT/'accounts',root/'accounts');(root/'data').mkdir()
 for name in ['inventory_reconciliation.php','product_stage.php','runtime_html.php','brand-theme.css']:shutil.copyfile(ROOT/name,root/name)
 # Unrelated shell scripts are outside this isolated report flow.
 for name in ['session-activity.js','brand-theme.js']:(root/name).write_text('')
 (root/'auth_store.php').write_text('''<?php
define('TT_DATA_DIR',__DIR__.'/data');define('TT_DB_HOST','');
function tt_require_login(){return ['id'=>1,'username'=>'Fixture','role'=>'Staff'];}
function tt_user_can_module_action($u,$m,$i,$a){return true;}function tt_user_can_access_entity($u,$e,$a){return true;}
function tt_release_read_session(){}function tt_verify_csrf($v){return $v==='fixture';}function tt_csrf(){return 'fixture';}
''')
 books={'revision':7,'journals':{'BOOK1':{'id':'BOOK1','entity':'TTI','status':'Posted','date':'2026-10-01','lines':[{'account':'1110','bankAccountId':'TEST-BANK','debit':100,'credit':0}]}}}
 ops={'revision':4,'values':{'transtrade_export_v3_operational':json.dumps({'contracts':[{'ref':'TG/C1','seller':'TG','date':'2026-09-01','product':'Test Rice','commodity':'RICE','qty':100,'received':True}],'shipments':[]})}}
 bp=root/'data/accounts.json';op=root/'data/operations.json';bp.write_text(json.dumps(books));op.write_text(json.dumps(ops));before=(bp.read_bytes(),op.read_bytes())
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 with open(root/'server.log','wb') as log:
  server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root)],stdout=log,stderr=log,env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')})
  try:
   url=f'http://127.0.0.1:{port}/accounts/group-management.php?kind=DAILY'
   for _ in range(60):
    try:urllib.request.urlopen(url);break
    except OSError:time.sleep(.05)
   with sync_playwright() as pw:
    browser=pw.chromium.launch(headless=True,**({'executable_path':os.environ['PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH']} if os.environ.get('PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH') else {}));page=browser.new_page(viewport={'width':1440,'height':950},accept_downloads=True);errors=[];posts=[]
    page.on('pageerror',lambda e:errors.append(str(e)));page.on('request',lambda r:posts.append(r.url) if r.method=='POST' else None)
    page.goto(url);page.locator('#gmDate').fill('2026-10-17');page.locator('#gmOpen').click();page.get_by_text('Saved report opened.',exact=False).wait_for();page.locator('[data-field="rates.USD"]').fill('280');page.locator('[data-field="rates.AED"]').fill('76');page.locator('#gmSave').click();page.get_by_text('Cash Flow saved.',exact=False).wait_for()
    page.get_by_text('Stock and commitment working · excluded from this cash-flow total',exact=True).click();assert page.locator('#gmStockWorking').evaluate('el=>el.open')
    page.locator('#gmDailyAdd').click();assert page.locator('#gmStockWorking').evaluate('el=>el.open'),'Adding a product group collapsed its working inputs';page.locator('[data-daily-label]').fill('Test Rice reviewed');page.locator('[data-daily-selected]').check();page.locator('[data-daily-recovery]').fill('60');assert len(posts)==1,'Entry caused a background write'
    page.locator('#gmReviewed').check();page.locator('#gmSave').click();page.get_by_text('Cash Flow saved.',exact=False).wait_for();page.locator('#gmEditor').get_by_text('SHORT',exact=False).wait_for();assert len(posts)==2
    saved=json.loads((root/'data/group-management.json').read_text());assert saved['groupManagementRecords']['DAILY|2026-10-17']['sections']['daily']['data']['cashOnly'];part=saved['groupManagementRecords']['DAILY|2026-10-17']['sections']['daily'];assert part['complete'] and part['calculation']['positions'][0]['positionMinMT']==round(-100/.6,6);assert all(saved['groupManagementRecords']['DAILY|2026-10-17']['sections'][k]['complete'] for k in ['header','daily','adjustments'])
    with page.expect_download() as downloaded:page.locator('#gmCSV').click()
    csv=pathlib.Path(downloaded.value.path()).read_text(encoding='utf-8-sig');assert 'Test Rice reviewed' in csv and 'SHORT' in csv and 'Book balance' in csv and 'BOOK1' in csv,csv
    # Capture the actual print DOM without invoking the OS dialog.
    page.add_init_script('window.print=()=>{}')
    with page.expect_popup() as popup:page.locator('#gmPrint').click()
    printed=popup.value;printed.wait_for_load_state();assert 'SHORT' not in printed.locator('body').inner_text() and 'BOOK1' in printed.locator('body').inner_text(),'Cash-flow print must include its bank evidence and keep stock working separate';assert printed.locator('details:not([open])').count()==0;printed.close()
    # Real endpoint source conflict leaves the native input and its draft untouched.
    page.locator('[data-daily-recovery]').fill('65');page.locator('#gmReason').fill('Review changed recovery');ops['revision']=5;op.write_text(json.dumps(ops));page.locator('#gmSave').click();page.get_by_text('Daily sources changed.',exact=False).wait_for();assert page.locator('[data-daily-recovery]').input_value()=='65';assert page.locator('#gmReason').input_value()=='Review changed recovery'
    assert (bp.read_bytes(),before[1])==before,'Report flow changed canonical books'
    assert op.read_text()==json.dumps(ops),'Report flow changed physical stock source'
    # Pending TG evidence remains in the detailed CSV/source review, not a second cash-flow amount.
    # A relevant unidentified payable must still block completion in party cash-flow mode.
    books['journals']['HOLD1']={'id':'HOLD1','entity':'TTI','status':'Posted','date':'2026-10-01','lines':[{'account':'2110','debit':0,'credit':50}]}
    books['tgRemittanceDrafts']={'TGRD1':{'id':'TGRD1','status':'Pending','date':'2026-10-01','currency':'USD','amountNative':100,'pakJournalIds':['BOOK1']}};books['revision']=8;bp.write_text(json.dumps(books));pending_before=(bp.read_bytes(),op.read_bytes())
    page.reload();page.locator('#gmDate').fill('2026-10-17');page.locator('#gmOpen').click();page.get_by_text('Saved report opened.',exact=False).wait_for();page.get_by_text('Stock and commitment working · excluded from this cash-flow total',exact=True).click();assert 'Pending TG remittance confirmations' in page.locator('#gmEditor').inner_text();page.locator('#gmReason').fill('Review pending original TG confirmation');page.locator('#gmReviewed').uncheck();page.locator('#gmSave').click();page.get_by_text('Cash Flow saved.',exact=False).wait_for();page.locator('#gmEditor').get_by_text('Pending TG remittance confirmations (1)',exact=True).first.click();page.locator('#gmEditor').get_by_text('TGRD1',exact=True).first.wait_for()
    page.locator('#gmReviewed').check();page.locator('#gmSave').click();page.get_by_text('Review the party source warnings',exact=False).wait_for();assert not json.loads((root/'data/group-management.json').read_text())['groupManagementRecords']['DAILY|2026-10-17']['sections']['daily']['complete']
    with page.expect_download() as downloaded:page.locator('#gmCSV').click()
    pending_csv=pathlib.Path(downloaded.value.path()).read_text(encoding='utf-8-sig');assert 'Pending TG remittance' in pending_csv and 'TGRD1' in pending_csv and 'Awaiting TG bank confirmation' in pending_csv and 'BOOK1' in pending_csv and 'pending only' in pending_csv
    with page.expect_popup() as popup:page.locator('#gmPrint').click()
    printed=popup.value;printed.wait_for_load_state();text=printed.locator('body').inner_text();assert 'TGRD1' not in text and 'BOOK1' in text and 'Original posting references' in text,'Cash-flow print must not add pending evidence as a second bank movement';printed.close();assert (bp.read_bytes(),op.read_bytes())==pending_before,'Pending report flow changed original books or stock'
    page.set_viewport_size({'width':390,'height':844});assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'), 'Daily editor overflows the mobile viewport'
    assert not errors,errors;browser.close()
    print('PASS native Daily browser -> PHP -> snapshot, explicit completion, original bank/commitment facts, actual CSV/print, source conflict draft retention and mobile fit; no original book/stock writes')
  finally:server.terminate();server.wait(timeout=5)
