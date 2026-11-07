"""Native operational saves and failure/retry verification; disposable app only."""
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import tempfile
import time

from playwright.sync_api import sync_playwright

ROOT=Path(__file__).resolve().parents[2]
SEED=r'''<?php
if(!str_starts_with(basename(__DIR__),'tti-mill-handoff-'))throw new RuntimeException('Unsafe fixture root');
require __DIR__.'/repo/auth_store.php';$password=bin2hex(random_bytes(20));$masters=tt_default_masters();
$masters['mills']=[['id'=>'mills-1','values'=>['TTI Rice Mills','','Own Mill','','','Active','','TTI']],['id'=>'mills-2','values'=>['QA Receiving Mill','','Own Mill','','','Active','','TTI']]];
$masters['business_parties']=array_values(array_filter($masters['business_parties']??[],static fn($r)=>strtolower(trim((string)($r['values'][0]??'')))!=='k-electric'));
$masters['business_parties'][]=['id'=>'QA-EXPORTER','values'=>['Fixture fellow exporter','','Fellow Exporter','','','','','','','','Active']];
$masters['business_parties'][]=['id'=>'QA-KE','values'=>['K-Electric','','Service Provider','','','','','','','','Active']];
$user=['id'=>1000,'username'=>'qaowner','full_name'=>'QA Mill Handoff','role'=>'Super Admin','permissions'=>['Mill'=>'all','Accounts'=>'all','Directors'=>'all','Exports'=>'all'],'active'=>true,'must_change_password'=>false,'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'master_access'=>true];
tt_ensure_data_dir();file_put_contents(TT_STORE_FILE,json_encode(['users'=>[$user],'masters'=>$masters,'settings'=>['qa_account_seeded'=>true],'master_options'=>tt_default_master_options(),'audit'=>[]]));
file_put_contents(TT_DATA_DIR.'/operations.json',json_encode(['revision'=>0,'values'=>['tt32stockadj'=>json_encode([['id'=>'fixture-raw-opening','entity'=>'TTI','millId'=>1,'millName'=>'TTI Rice Mills','date'=>'2026-07-01','openingStock'=>true,'readyRiceKg'=>10000,'brand'=>'IRRI-6 White Raw Rice','stockName'=>'IRRI-6 White Raw Rice'],['id'=>'fixture-opening','entity'=>'TTI','millId'=>1,'millName'=>'TTI Rice Mills','openingStock'=>true,'readyRiceKg'=>1000,'brand'=>'IRRI-6 White Rice — SOURCE','stockName'=>'IRRI-6 White Rice — SOURCE'],['id'=>'fixture-exporter-opening','entity'=>'TTI','millId'=>1,'millName'=>'TTI Rice Mills','openingStock'=>true,'readyRiceKg'=>1000,'brand'=>'QA BRAND','stockName'=>'QA BRAND']]),'tt30mills'=>json_encode([['id'=>1,'name'=>'TTI Rice Mills','entity'=>'TTI','type'=>'Own / Default'],['id'=>2,'name'=>'QA Receiving Mill','entity'=>'TTI','type'=>'Own']]),'tt30bags'=>json_encode([['id'=>100,'entity'=>'TTI','mill'=>'TTI Rice Mills','brand'=>'QA PRODUCTION','size'=>'50 kg','bagType'=>'PP','ordered'=>100,'received'=>100,'poNo'=>'QA-PO','poLineKey'=>'L1']]),'tt33bagreceipts'=>json_encode([['id'=>101,'bagId'=>100,'date'=>'2026-10-01','qty'=>100]])],'meta'=>[]]));
file_put_contents(TT_DATA_DIR.'/accounts.json',json_encode(['revision'=>0,'journals'=>['FIXTURE-MILL-FUND'=>['id'=>'FIXTURE-MILL-FUND','entity'=>'TTI','date'=>'2026-07-01','status'=>'Posted','sourceType'=>'JV','lines'=>[['account'=>'1120','millLocationId'=>'mills-1','debit'=>1000,'credit'=>0],['account'=>'3400','debit'=>0,'credit'=>1000]]]],'supplierBills'=>new stdClass()]));
file_put_contents(__DIR__.'/credentials.json',json_encode(['password'=>$password,'dataDir'=>TT_DATA_DIR]));
'''

def run():
    root=Path(tempfile.mkdtemp(prefix='tti-mill-handoff-'));server=None;log=None
    env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
    output=Path(os.environ['TT_QA_OUTPUT']) if os.environ.get('TT_QA_OUTPUT') else None
    try:
        app=root/'repo';shutil.copytree(ROOT,app,ignore=shutil.ignore_patterns('.git','node_modules','test-results','playwright-report','__pycache__','tmp'))
        sessions=root/'sessions';sessions.mkdir();(root/'seed.php').write_text(SEED)
        subprocess.run(['php','-d',f'session.save_path={sessions}',str(root/'seed.php')],env=env,capture_output=True,text=True,check=True)
        credentials=json.loads((root/'credentials.json').read_text());data=Path(credentials['dataDir']);books=data/'accounts.json'
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        base=f'http://127.0.0.1:{port}';log=(root/'server.log').open('w+')
        server=subprocess.Popen(['php','-d',f'session.save_path={sessions}','-S',f'127.0.0.1:{port}','-t',str(app)],env=env,stdout=log,stderr=log)
        time.sleep(.3)
        with sync_playwright() as pw:
            browser=pw.chromium.launch(headless=True);context=browser.new_context(accept_downloads=True);mill=context.new_page();errors=[];dialogs=[]
            mill.on('pageerror',lambda e:errors.append(str(e)));mill.on('dialog',lambda d:(dialogs.append(d.message),d.accept()))
            mill.goto(base+'/login.php');mill.locator('[name="username"]').fill('qaowner');mill.locator('[name="password"]').fill(credentials['password']);mill.locator('button[type="submit"]').click();mill.wait_for_load_state('domcontentloaded')
            mill.goto(base+'/module.php?id=milling');mill.locator('.mill-card[onclick="chooseMill(1)"]').click()
            original_books=books.read_bytes();posts=[];blocked=[True]
            def intercept(route):
                if route.request.method=='POST':
                    posts.append(route.request.post_data_json)
                    if blocked[0]:blocked[0]=False;route.abort();return
                route.continue_()
            context.route('**/api/operations.mysql.php?asset_refs=1',intercept)
            mill.evaluate("openPanel('oldbags')")
            mill.locator('#usedInSource').fill('Fixture supplier');mill.locator('#usedInQty').fill('20')
            mill.get_by_role('button',name='Save Outside Inward',exact=True).click();mill.locator('[data-mill-action-recovery]').wait_for()
            assert mill.locator('#usedInQty').input_value()=='20';assert books.read_bytes()==original_books
            mill.get_by_role('button',name='Save Outside Inward',exact=True).click();mill.wait_for_function("document.querySelector('#usedInQty').value === ''")
            op=context.request.get(base+'/api/operations.mysql.php').json();used=json.loads(op['values']['tt37usedbags']);assert len(used)==1 and used[0]['qty']==20
            assert len(posts)==2 and posts[0]['key']==posts[1]['key']=='tt37usedbags'
            mill.locator('#usedOutSource').select_option('outside');mill.locator('#usedOutQty').fill('5');mill.locator('#usedOutType').select_option('Sale');mill.locator('#usedOutRate').fill('3');mill.locator('#usedOutParty').fill('Fixture buyer')
            mill.get_by_role('button',name='Save Outward',exact=True).click();mill.wait_for_function("document.querySelector('#usedOutQty').value === ''")
            mill.reload();mill.locator('.mill-card[onclick="chooseMill(1)"]').click();mill.evaluate("openPanel('oldbags')");assert mill.locator('#usedOutsideBalance').inner_text()=='15'
            mill.evaluate("openPanel('petty');togglePettyInline('Office Expense')");mill.locator('#pettyBalance').filter(has_text='1,000.00').wait_for()
            mill.locator('#millCashForm [name=amount]').fill('25');mill.locator('#millCashForm [name=payee]').fill('Fixture mill vendor');mill.locator('#millCashForm [name=expenseType]').fill('Office supplies');mill.locator('#millCashForm [name=narration]').fill('Fixture office expense');mill.locator('#millCashForm [name=remember]').check()
            assert books.read_bytes()==original_books,'Typing and text memory changed books'
            mill.get_by_role('button',name='Review and Post Payment',exact=True).click();mill.get_by_role('button',name='Back to entry',exact=True).click();assert books.read_bytes()==original_books,'Cancelled review posted payment'
            cash_lost=[True]
            def cash_ack(route):
                payload=route.request.post_data_json if route.request.method=='POST' else {}
                if route.request.method=='POST' and not payload.get('preview') and cash_lost[0]:
                    cash_lost[0]=False;response=route.fetch();assert response.status==200,response.text();route.abort()
                else:route.continue_()
            context.route('**/api/mill_cash_ledger.php',cash_ack)
            mill.get_by_role('button',name='Review and Post Payment',exact=True).click();mill.get_by_role('button',name='Confirm and Post',exact=True).click();mill.get_by_role('button',name='Confirm Previous Payment',exact=True).wait_for();mill.wait_for_function("!document.querySelector('#millCashForm button[type=submit]').disabled")
            assert mill.locator('#millCashForm [name=amount]').input_value()=='25';mill.get_by_role('button',name='Confirm Previous Payment',exact=True).click();mill.locator('#pettyInline').filter(has_text='Payment confirmed').wait_for();mill.locator('#pettyBalance').filter(has_text='975.00').wait_for()
            op=context.request.get(base+'/api/operations.mysql.php').json();assert not json.loads(op['values'].get('tt33pettyexp','[]')),'Native cash payment was duplicated into operational history';assert len(json.loads(books.read_text())['generalExpenses'])==1
            original_books=books.read_bytes();context.unroute('**/api/mill_cash_ledger.php',cash_ack)
            # Native arrival Save persists before clearing and duplicate request gets server attribution.
            mill.evaluate("openPanel('queue')");mill.locator('#qVehicle').fill('QA-101');mill.locator('#qBags').fill('10');mill.locator('#qWeight').fill('1000');mill.locator('#qParty').fill('Fixture supplier')
            mill.locator('#queueSaveBtn').click();mill.evaluate('window.TT_SHARED_SYNC.saveNow()');mill.wait_for_function("document.querySelector('#qVehicle').value === ''")
            op=context.request.get(base+'/api/operations.mysql.php').json();queue=json.loads(op['values']['tt30queue']);assert len(queue)==1 and queue[0]['vehicle']=='QA-101'
            mill.locator('#qVehicle').fill('QA-101');mill.locator('#qBags').fill('10');mill.locator('#qWeight').fill('1000');mill.locator('#queueSaveBtn').click();mill.wait_for_function("document.querySelector('#duplicateAlert').textContent.includes('Directors alert created')")
            op=context.request.get(base+'/api/operations.mysql.php').json();alerts=json.loads(op['values']['tt30directoralerts']);assert len(alerts)==1 and alerts[0]['requestedByUser']==1000
            # The embedded Local Sales form saves only final actions and retains failed drafts.
            mill.reload();mill.locator('.mill-card[onclick="chooseMill(1)"]').click();mill.evaluate("openPanel('local')")
            local=mill.frame_locator('#partySaleWrap iframe');local.get_by_role('button',name='➕ New Local Soda',exact=True).click()
            local.locator('#party').fill('Fixture local buyer');local.locator('#qtyTons').fill('1');local.locator('#rate').fill('10');local.locator('#doneBy').fill('Fixture operator')
            identity=local.locator('#localProductIdentities option').first.get_attribute('value');assert identity;local.locator('#productIdentity').fill(identity)
            before=len(posts);mill.wait_for_timeout(300);assert len(posts)==before
            blocked[0]=True;local.get_by_role('button',name='Save Local Soda',exact=True).click();mill.locator('[data-mill-action-recovery]').wait_for();assert local.locator('#party').input_value()=='Fixture local buyer'
            local.get_by_role('button',name='Save Local Soda',exact=True).click();local.locator('#workTitle').filter(has_text='Fixture local buyer').wait_for()
            op=context.request.get(base+'/api/operations.mysql.php').json();sales=json.loads(op['values']['tt35localsales']);assert len(sales)==1 and sales[0]['millName']=='TTI Rice Mills'
            local.locator('#payAmount').fill('10000');local.locator('[onclick="savePay()"]').click();mill.wait_for_function("document.querySelector('#partySaleWrap iframe').contentDocument.querySelector('#payAmount').value === ''")
            op=context.request.get(base+'/api/operations.mysql.php').json();sales=json.loads(op['values']['tt35localsales']);assert len(sales[0]['pay'])==1 and sales[0]['pay'][0]['bridgeId']
            local.locator('[onclick="tab(\'l\')"]').click();local.locator('#truck').fill('QA-202');local.locator('#loadKg').fill('1000');local.locator('#loadBags').fill('20');local.locator('[onclick="saveLoad()"]').click();mill.wait_for_function("document.querySelector('#partySaleWrap iframe').contentDocument.querySelector('#loadKg').value === ''")
            op=context.request.get(base+'/api/operations.mysql.php').json();sales=json.loads(op['values']['tt35localsales']);assert len(sales[0]['loads'])==1 and sales[0]['loads'][0]['bridgeId']
            with context.expect_page() as printed:local.get_by_role('button',name='Print Gate Pass',exact=True).click()
            popup=printed.value;
            try:popup.locator('body').filter(has_text='Fixture local buyer').wait_for()
            except Exception:raise AssertionError({'dialogs':dialogs,'errors':errors})
            assert 'Fixture local buyer' in popup.locator('body').inner_text();assert '1,000' in popup.locator('body').inner_text();assert 'Fixture local buyer' in popup.title() and not re.search(r'\d{4}-\d{2}-\d{2}',popup.locator('body').inner_text());popup.close()
            local.locator('[onclick="closeMill()"]').click();local.locator('#activeBody').filter(has_text='Closed by Mill').wait_for()
            mill.reload();mill.locator('.mill-card[onclick="chooseMill(1)"]').click();mill.evaluate("openPanel('local')");local=mill.frame_locator('#partySaleWrap iframe');local.locator('#activeBody').filter(has_text='Closed by Mill').wait_for()
            mill.evaluate("openPanel('local');saleType('exporter')")
            # The commercial SODA is created in Directors, never in the Mill rate form.
            assert not mill.locator('#exBuyer').is_visible()
            director=context.new_page();director.goto(base+'/directors/exporter-trading.php');director.get_by_role('button',name='+ New SODA',exact=True).click()
            director.locator('#dtDealForm [name=partyId]').select_option('QA-EXPORTER')
            for field,value in {'price':'10','quantityMT':'0.5','product':'QA BRAND','terms':'Exporter handles bags and collection; Mill records physical dispatch only.'}.items():director.locator('#dtDealForm [name='+field+']').fill(value)
            director.get_by_role('button',name='Save SODA',exact=True).click();director.locator('#dtMessage').filter(has_text='Saved.').wait_for()
            director.get_by_role('button',name='View',exact=True).click();director.get_by_role('button',name='Production / mill specification',exact=True).click();director.locator('#dtProductionForm').wait_for()
            director.locator('[name=productionMillId]').select_option(label='TTI Rice Mills');director.locator('#dtProductionForm [name=brand]').fill('QA BRAND');director.locator('[name=productionStockName]').fill('QA BRAND');director.locator('[name=specificationMode]').select_option('FULL');director.locator('[name=fullSpecification]').fill('Fixture full rice specification: broken 5%, moisture 14% maximum.');director.locator('[name=productionNotes]').fill('Produce and dispatch the assigned QA BRAND output.');director.get_by_role('button',name='Send instruction to mill',exact=True).click();director.locator('#dtMessage').filter(has_text='Saved.').wait_for()
            mill.locator('#directorExporterRefresh').click()
            choice=mill.locator('#directorExporterDeal option').filter(has_text='Fixture fellow exporter');choice.wait_for(state='attached');soda_id=choice.get_attribute('value');mill.locator('#directorExporterDeal').select_option(soda_id)
            mill.locator('#directorExporterInstruction').filter(has_text='Fixture full rice specification').wait_for();assert not json.loads(context.request.get(base+'/api/operations.mysql.php').json()['values'].get('tt35exportersale','[]')),'Selecting a SODA must not save or move stock'
            try:mill.locator('#savedExporterSales').filter(has_text='Fixture fellow exporter').wait_for(timeout=10000)
            except Exception:raise AssertionError({'handoff':mill.locator('#directorExporterStatus').inner_text(),'errors':errors,'dialogs':dialogs})
            director.close()
            mill.locator('#exTruck').fill('QA-303');mill.locator('#exBags').fill('10');mill.locator('#exGross').fill('501');mill.locator('[onclick="addExporterContainer()"]').click();mill.wait_for_function("document.querySelector('#exTruck').value === ''")
            op=context.request.get(base+'/api/operations.mysql.php').json();exporter=json.loads(op['values']['tt35exportersale']);assert len(exporter)==1 and len(exporter[0]['containers'])==1 and exporter[0]['containers'][0]['net']==500 and exporter[0]['exporterDealId']==soda_id
            mill.locator('#exTruck').fill('QA-304');mill.locator('#exBags').fill('1');mill.locator('#exGross').fill('1.1');mill.locator('[onclick="addExporterContainer()"]').click();mill.get_by_text('Loading exceeds the SODA quantity.',exact=False).first.wait_for();assert len(json.loads(context.request.get(base+'/api/operations.mysql.php').json()['values']['tt35exportersale'])[0]['containers'])==1,'Excess loading was saved'
            for selector in ['#exTruck','#exBags','#exGross']:mill.locator(selector).fill('')
            saved=context.request.get(base+'/api/directors_trading.php?entity=TTI').json();linked=next(d for d in saved['deals'] if d['id']==soda_id);assert linked['working']['loadedMT']==.5 and linked['working']['remainingMT']==0 and linked['bookBalancePKR']==0,linked
            with context.expect_page() as printed:mill.locator('#exContainerTable').get_by_role('button',name='Print Gate Pass',exact=True).click()
            popup=printed.value;popup.locator('.serial').filter(has_text='GP-000002').wait_for();body=popup.locator('body').inner_text().lower();assert 'local sale to exporter gate pass' in body and 'fixture fellow exporter' in body,body;assert popup.locator('.pass').evaluate('e=>e.getBoundingClientRect().height*25.4/96')<140;popup.close()
            mill.locator('[onclick="closeExporterSale()"]').click();mill.locator('#savedExporterSales').filter(has_text='Closed').wait_for()
            mill.reload();mill.locator('.mill-card[onclick="chooseMill(1)"]').click();mill.evaluate("openPanel('local');saleType('exporter')");mill.locator('#savedExporterSales').filter(has_text='Closed').wait_for()
            # PALTAI commits the physical source and labour together, then a lost acknowledgement replays once.
            mill.reload();mill.locator('.mill-card[onclick="chooseMill(1)"]').click();mill.evaluate("openPanel('production');togglePaltai()")
            mill.locator('#paltaiFrom').select_option('IRRI-6 White Rice — SOURCE');mill.locator('#paltaiOutputBags').fill('10');mill.locator('#paltaiOutputSize').fill('50');mill.locator('#paltaiSourceSize').fill('100');mill.locator('#paltaiSweepingKg').fill('10');mill.locator('#paltaiStandaloneRate').fill('8')
            context.unroute('**/api/operations.mysql.php?asset_refs=1',intercept);lost=[True]
            def lose_ack(route):
                if route.request.method=='POST' and route.request.post_data_json.get('key')=='tt32stockadj' and lost[0]:
                    lost[0]=False;response=route.fetch();assert response.status==200,response.text();route.abort()
                else:route.continue_()
            context.route('**/api/operations.mysql.php?asset_refs=1',lose_ack)
            mill.get_by_role('button',name='OK — Confirm Paltai',exact=True).click();mill.locator('[data-mill-action-recovery]').wait_for();assert mill.locator('#paltaiOutputBags').input_value()=='10'
            mill.get_by_role('button',name='OK — Confirm Paltai',exact=True).click();mill.wait_for_function("document.querySelector('#paltaiPanel').classList.contains('hidden')")
            op=context.request.get(base+'/api/operations.mysql.php').json();transfers=[r for r in json.loads(op['values']['tt32stockadj']) if r.get('paltaiVersion')];labour=[r for r in json.loads(op['values']['tt32labourfeed']) if r.get('paltaiSourceId')];assert len(transfers)==len(labour)==1 and transfers[0]['sourceDeductionKg']==510 and labour[0]['quantity']==10
            mill.reload();mill.locator('.mill-card[onclick="chooseMill(1)"]').click();stock=mill.evaluate("computedStockRows()");assert next(r['kg'] for r in stock if r['name']=='IRRI-6 White Rice — SOURCE')==490
            # Directors SODA preparation legitimately updates nonfinancial Accounts registers.
            current_books=json.loads(books.read_text());cash_snapshot=json.loads(original_books)
            for cash_key in ['journals','generalExpenses','millPettyRequests']:
                assert current_books.get(cash_key)==cash_snapshot.get(cash_key),'Nonfinancial operational saves changed '+cash_key
            assert not errors,errors
            # Native Pohanch uses the approved purchase feed; failure retains the entry, retry keeps one identity.
            identity=mill.evaluate("selectedPurchaseProduct()")
            ledger=json.loads(books.read_text());ledger['purchaseSodas']={'PS-FIXTURE':dict(identity,id='PS-FIXTURE',sodaNo='26001',entity='TTI',commodity='RICE',status='Open',readyRoute='DELIVER_TO_STOCK',locationName='TTI Rice Mills',locationId='mills-1',broker='',party='Fixture supplier',qtyFromKg=10000,qtyToKg=10000,ratePerKg=10,rate=10,rateUnit='KG',sodaDate='2026-10-01')};books.write_text(json.dumps(ledger))
            mill.reload();mill.locator('.mill-card[onclick="chooseMill(1)"]').click();mill.evaluate("openPanel('arrival')")
            mill.locator('#arrivalQueue').select_option(str(queue[0]['id']));mill.locator('#arrivalSoda option[value="26001"]').wait_for(state='attached');mill.locator('#arrivalSoda').select_option('26001');mill.locator('#karachiWeight').fill('990');mill.evaluate("qVariety.selectedIndex=qVariety.options.length-1")
            context.unroute('**/api/operations.mysql.php?asset_refs=1',lose_ack);blocked[0]=True;context.route('**/api/operations.mysql.php?asset_refs=1',intercept)
            mill.locator('#savePohanchButton').click();mill.locator('[data-mill-action-recovery]').wait_for();assert mill.locator('#karachiWeight').input_value()=='990'
            mill.locator('#savePohanchButton').click();mill.locator('#pohanchFeedback').filter(has_text='Pohanch saved').wait_for()
            op=context.request.get(base+'/api/operations.mysql.php').json();slips=json.loads(op['values']['tt30slips']);assert len(slips)==1 and slips[0]['payableWeight']==990
            mill.locator('#unprintedSlips').get_by_role('button',name='Edit',exact=True).click();assert mill.locator('#arrivalQueue').input_value()==str(queue[0]['id']);assert mill.locator('#karachiWeight').input_value()=='990'
            mill.locator('#karachiWeight').fill('985');mill.locator('#savePohanchButton').click();mill.locator('#pohanchFeedback').filter(has_text='Pohanch amended').wait_for()
            amended=json.loads(context.request.get(base+'/api/operations.mysql.php').json()['values']['tt30slips']);assert len(amended)==1 and amended[0]['id']==slips[0]['id'] and amended[0]['payableWeight']==985
            with context.expect_page() as printed:mill.get_by_role('button',name='Print Pohanch',exact=True).first.click()
            popup=printed.value;popup.locator('.pohanch').wait_for();assert popup.locator('.pohanch').evaluate('e=>e.getBoundingClientRect().height*25.4/96')<140;body=popup.locator('body').inner_text();assert '985 kg' in body and re.search(r'\d{2}-\d{2}-\d{4}',body) and not re.search(r'\d{4}-\d{2}-\d{2}',body);popup.close()
            # Output-accounted shift: actual native Save, lost acknowledgement,
            # progress replacement and completion all retain one stock source.
            production_books=books.read_bytes();production=context.new_page();production.on('pageerror',lambda e:errors.append(str(e)));production.on('dialog',lambda d:d.accept())
            production.goto(base+'/module.php?id=milling');production.get_by_role('button',name='🏭 TTI Rice Mills Own / Default',exact=True).click();production.get_by_role('button',name='⚙️ Production',exact=True).click()
            assert production.locator('#prodDate').input_value()==production.evaluate('today()'),'New production must default to the current business date'
            production.get_by_role('combobox',name='Ready Rice brand / SODA').first.select_option('LOCAL');production.locator('.tt-number-display[aria-label="Ready Rice — Local Bags quantity"]').fill('10')
            production.locator('.tt-number-display[aria-label="B2 — LOCAL total kg"]').fill('100')
            assert not production.locator('#productionActualInput').is_visible()
            assert production.locator('#prodOperatingHours').input_value()=='10.00'
            production.locator('.tt-number-display[aria-label="Actual production hours"]').fill('8.5')
            assert '1.50 hours' in production.locator('#prodDowntimeLabel').inner_text()
            production.locator('#prodDowntimeReason').fill('Power breakdown')
            lose_production=[True]
            def production_lost_ack(route):
                if route.request.method=='POST' and route.request.post_data_json.get('key')=='tt30prod' and lose_production[0]:
                    lose_production[0]=False;route.fetch();route.abort()
                else:route.continue_()
            production.route('**/api/operations.mysql.php?asset_refs=1',production_lost_ack)
            production.get_by_role('button',name='Save Production',exact=True).click();production.locator('[data-production-save-recovery]').wait_for();assert production.locator('.tt-number-display[aria-label="Ready Rice — Local Bags quantity"]').input_value()=='10'
            saved_runs=json.loads(context.request.get(base+'/api/operations.mysql.php').json()['values']['tt30prod']);assert len(saved_runs)==1 and saved_runs[0]['calculatedInputKg']==600 and saved_runs[0]['inputBasis']=='OUTPUT_DERIVED';production_id=saved_runs[0]['id']
            production.get_by_role('button',name='Save Production',exact=True).click();production.wait_for_function("editingProdId===null && productionRows.every(row=>!row.bags)")
            assert production.locator('#prodDowntimeReason').input_value()==''
            production.get_by_role('button',name='Change',exact=True).first.click()
            assert production.evaluate('editingProdId')==production_id
            assert float(production.locator('#prodOperatingHours').input_value())==8.5
            assert production.locator('#prodDowntimeReason').input_value()=='Power breakdown'
            production.locator('.tt-number-display[aria-label="Ready Rice — Local Bags quantity"]').fill('12');production.get_by_role('button',name='Save Production',exact=True).click()
            production.wait_for_function("get(STORE_PROD,[]).length===1&&get(STORE_PROD,[])[0].calculatedInputKg===700")
            production.get_by_role('button',name='Change',exact=True).first.click()
            production.locator('#prodShiftComplete').check();production.locator('#capacityReason').fill('Fixture low-volume completed shift');production.get_by_role('button',name='Save Production',exact=True).click()
            production.wait_for_function("get(STORE_PROD,[]).length===1&&get(STORE_PROD,[])[0].shiftEntriesComplete===true")
            final_runs=json.loads(context.request.get(base+'/api/operations.mysql.php').json()['values']['tt30prod']);assert len(final_runs)==1 and final_runs[0]['id']==production_id and final_runs[0]['calculatedInputKg']==700 and final_runs[0]['rawInputs']==[] and final_runs[0]['operatingHours']==8.5 and final_runs[0]['downtimeReason']=='Power breakdown'
            after_production_books=books.read_bytes()
            if after_production_books!=production_books:
                original=json.loads(production_books);changed=json.loads(after_production_books)
                differences={key: {'before':original.get(key),'after':changed.get(key)} for key in original.keys()|changed.keys() if original.get(key)!=changed.get(key)}
                raise AssertionError('Output-accounted production changed financial books: '+json.dumps({key:str(value)[:1600] for key,value in differences.items()},sort_keys=True))
            production.close();print('PASS native output-accounted production lost-ack retry, retained Ready Rice input, same-record progress replacement and completion, direct-kg by-product, raw-stock validation and unchanged books')
            # The authenticated native print uses committed loading and the shared half-A4 template.
            container={'id':'QA-GATE-LOAD','container':'MSCU123456-6','seal':'QA-SEAL','truck':'QA-404','date':'2026-10-27','weight':26950,'weighbridgeKg':27000,'packingKg':50,'bags':1000,'gate':'FIXTURE-GP','loadingComplete':True}
            shipment={'id':'QA-GATE-SHIP','entity':'TTI','millName':'TTI Rice Mills','brand':'Fixture','contractRef':'QA-CONTRACT','lotRef':'QA-LOT','brokenPct':5,'containers':[container]}
            operations=json.loads((data/'operations.json').read_text());operations['values']['tt30ship']=json.dumps([shipment]);(data/'operations.json').write_text(json.dumps(operations))
            with context.expect_page() as printed:mill.evaluate("s=>printGate(s.containers[0],s)",shipment)
            popup=printed.value;popup.locator('.pass').wait_for();body=popup.locator('body').inner_text();assert 'Weighbridge weight (kg)' in body and '27,000' in body and '26,950' in body and 'Packing deduction (kg)' in body and '50' in body;assert '5% BROKEN' in body and 'QA-LOT' in body and 'GP-000003' in body;assert popup.title();assert popup.locator('.pass').evaluate('e=>e.getBoundingClientRect().height*25.4/96')<140;popup.close()
            with context.expect_page() as printed:mill.evaluate("s=>printGate(s.containers[0],s)",shipment)
            popup=printed.value;popup.locator('.serial').filter(has_text='GP-000003').wait_for();popup.close()
            # Separate phone and PC storage contexts: a saved phone arrival becomes live while PC keeps its draft.
            mill.evaluate("openPanel('queue')");mill.locator('#qVehicle').fill('DRAFT-901')
            phone_context=browser.new_context(storage_state=context.storage_state(),viewport={'width':390,'height':844},is_mobile=True,has_touch=True)
            phone=phone_context.new_page();phone.on('pageerror',lambda e:errors.append(str(e)));phone.on('dialog',lambda d:d.accept());phone.goto(base+'/module.php?id=milling');phone.locator('.mill-card[onclick="chooseMill(1)"]').click();phone.evaluate("openPanel('petty')");phone.locator('#pettyBalance').filter(has_text='975.00').wait_for();phone.evaluate("openPanel('queue')")
            lose_phone=[True]
            def phone_ack(route):
                if route.request.method=='POST' and route.request.post_data_json.get('key')=='tt30queue' and lose_phone[0]:
                    lose_phone[0]=False;response=route.fetch();assert response.status==200,response.text();route.abort()
                else:route.continue_()
            phone_context.route('**/api/operations.mysql.php?asset_refs=1',phone_ack)
            # A separate icon may have staged display bridge data without a save.
            # The other phone's arrival must arrive live before any manual report and leave that draft untouched.
            assert mill.evaluate("""async()=>{const key='tt30ship',before=localStorage.getItem(key),attempted=JSON.stringify([...JSON.parse(before||'[]'),{id:'fixture-staged-bridge-only'}]);window.__arrivalOtherDraft={key,before,attempted};localStorage.setItem(key,attempted);try{await window.TT_SHARED_SYNC.refresh();return false}catch(error){return error.message==='Finish the current save before refreshing.'}}""")
            phone.locator('#qVehicle').fill('QA-909');phone.locator('#qBags').fill('12');phone.locator('#qWeight').fill('1200');phone.locator('#queueSaveBtn').click();phone.locator('[data-mill-action-recovery]').wait_for();assert phone.locator('#qVehicle').input_value()=='QA-909'
            phone.locator('#queueSaveBtn').click();phone.wait_for_function("document.querySelector('#qVehicle').value === ''");phone.locator('#queueSaveStatus').filter(has_text='Saved to server').wait_for()
            mill.locator('#queueTable').filter(has_text='QA-909').wait_for(timeout=25000);assert mill.locator('#qVehicle').input_value()=='DRAFT-901'
            current=json.loads(context.request.get(base+'/api/operations.mysql.php').json()['values']['tt30queue']);assert sum(r['vehicle']=='QA-909' for r in current)==1
            assert phone.locator('[data-arrival-recovery]').is_visible();phone.get_by_role('button',name='Recover phone entries',exact=True).click();phone.locator('#arrivalRecoveryReview').get_by_text('Confirmed on server',exact=True).first.wait_for()
            # Report mode hides entry controls and retains the PC's unfinished truck/date.
            pc_date=mill.locator('#queueViewDate').input_value();mill.locator('#queueRangeMode').check();assert not mill.locator('#queueEntryControls').is_visible();assert not mill.locator('#queueResults').is_visible()
            mill.locator('#queueViewFrom').fill(mill.evaluate('dateMinus(4)'));mill.locator('#queueViewTo').fill(mill.evaluate('today()'));mill.locator('#queueViewTo').dispatch_event('change');mill.locator('#queueTable').filter(has_text='QA-909').wait_for();assert mill.locator('#queueTable tr[onclick]').count()==0
            mill.locator('#queueRangeMode').uncheck();assert mill.locator('#qVehicle').input_value()=='DRAFT-901',mill.locator('#qVehicle').input_value();assert mill.locator('#queueViewDate').input_value()==pc_date,(mill.locator('#queueViewDate').input_value(),pc_date)
            assert mill.evaluate("""()=>{const d=window.__arrivalOtherDraft;if(localStorage.getItem(d.key)!==d.attempted)return false;return window.TT_SHARED_SYNC.restoreStaged(d.key,d.before,d.attempted)}""")
            # The Accounts replica uses a nested authenticated Mill frame. Real
            # clicks must wait for its initial session check and still open the
            # same Local Sales screen without posting anything.
            replica_books=books.read_bytes();replica_ops=(data/'operations.json').read_bytes()
            replica=context.new_page();replica.on('pageerror',lambda e:errors.append(str(e)));replica.goto(base+'/accounts/local-sales.php?entity=TTI')
            shared_mill=replica.frame_locator('#millLocalFrame')
            try:
                shared_mill.locator('body[data-tt-local-sales-ready="true"]').wait_for()
                chooser=shared_mill.get_by_role('button',name='🏭 TTI Rice Mills Own / Default',exact=True)
                if chooser.count() and chooser.is_visible():chooser.click()
                shared_mill.frame_locator('iframe[title="Locked Local Party Sale"]').get_by_role('heading',name='Active Local Sales',exact=True).wait_for()
            except Exception as error:
                raise AssertionError({'replicaUrl':replica.url,'replicaText':replica.locator('body').inner_text()[:1000],'frames':[(frame.url,frame.locator('body').inner_text()[:1400]) for frame in replica.frames],'errors':errors}) from error
            assert books.read_bytes()==replica_books and (data/'operations.json').read_bytes()==replica_ops,'Replica navigation changed stock or books'
            replica.close()
            print('PASS native Accounts replica iframe navigation waits for Mill session verification and keeps books/stock unchanged')
            # The phone deliberately shares the cloned server session; test the replica before expiring that session.
            # Actual expired session: original device fields survive redirect, re-login and explicit restore.
            phone.get_by_role('button',name='Close recovery',exact=True).click();phone.locator('#qVehicle').fill('QA-910');phone.locator('#qBags').fill('8');phone.locator('#qWeight').fill('800');phone.locator('#qParty').fill('Unsubmitted before expired sign-in')
            # Failed resume checks lock all entry without posting or losing the original.
            before_locked=(data/'operations.json').read_bytes()
            def fail_session_read(route):
                if route.request.method=='GET':route.abort()
                else:route.continue_()
            phone_context.route('**/api/session_activity.php',fail_session_read)
            phone.evaluate('void window.TT_MILL_SESSION.verify()');phone.locator('#ttMillSessionGate').filter(has_text='Unable to confirm').wait_for()
            phone.locator('#queueSaveBtn').evaluate('(button)=>button.click()');assert (data/'operations.json').read_bytes()==before_locked
            assert phone.locator('#qVehicle').input_value()=='QA-910' and phone.locator('#qBags').input_value()=='8'
            phone_context.unroute('**/api/session_activity.php',fail_session_read);phone.get_by_role('button',name='Retry session check',exact=True).click();phone.wait_for_function('window.TT_MILL_SESSION.ready()')
            (app/'fixture-expire.php').write_text("<?php require __DIR__.'/auth_store.php';$_SESSION['last_activity_at']=time()-901;echo 'expired fixture';")
            assert phone_context.request.get(base+'/fixture-expire.php').status==200
            # Phone timers are suspended: the very first key after fifteen minutes must
            # lock entry and show the expiry popup BEFORE inserting that extra digit.
            phone.evaluate('window.__fixtureClock=Date.now;Date.now=()=>window.__fixtureClock()+15*60*1000+1')
            phone.locator('#qBags').press('9')
            idle_popup=phone.get_by_role('dialog',name='Session expired')
            idle_popup.wait_for()
            assert phone.locator('#qBags').input_value()=='8'
            idle_popup.get_by_role('button',name='OK',exact=True).click()
            phone.wait_for_url('**/login.php*')
            device_draft=phone.evaluate("Object.keys(localStorage).filter(k=>k.startsWith('tt_ui_arrival_form_')).map(k=>JSON.parse(localStorage.getItem(k)))");assert any(d['fields']['qVehicle']=='QA-910' and d['fields']['qBags']=='8' for d in device_draft),'Expired first key altered the preserved original'
            phone.locator('[name="username"]').fill('qaowner');phone.locator('[name="password"]').fill(credentials['password']);phone.locator('button[type="submit"]').click();phone.wait_for_load_state('domcontentloaded');phone.goto(base+'/module.php?id=milling');phone.locator('.mill-card[onclick="chooseMill(1)"]').click();phone.evaluate("openPanel('queue')")
            assert phone.locator('#qVehicle').input_value()=='';phone.get_by_role('button',name='Restore arrival draft',exact=True).click();assert phone.locator('#qVehicle').input_value()=='QA-910' and phone.locator('#qWeight').input_value()=='800'
            before_submit=json.loads(phone_context.request.get(base+'/api/operations.mysql.php').json()['values']['tt30queue']);assert not any(r['vehicle']=='QA-910' for r in before_submit),'Device recovery posted an unsubmitted arrival'
            phone.locator('#queueSaveBtn').click();phone.locator('#queueSaveStatus').filter(has_text='Saved to server').wait_for();after_submit=json.loads(phone_context.request.get(base+'/api/operations.mysql.php').json()['values']['tt30queue']);assert sum(r['vehicle']=='QA-910' for r in after_submit)==1
            phone_context.close()
            assert not errors,errors
            browser.close()
        print('PASS native Used Bags failure/retry/reload, reviewed canonical Mill cash expense/cancel/lost-ack retry and shared phone balance, arrival and duplicate-request persistence, Local Sales save/payment/load/printed gate/close/reload, Directors local SODA -> Mill selection/truck/weights -> gate pass -> Directors quantities -> close/reload, atomic PALTAI lost-ack replay, native Pohanch retry/amendment and print weights/dates, independent mobile/PC lost-ack retry, automatic live list refresh, isolated date-range report and expired-session original draft restore/re-login with one explicit save; fixture only')
    finally:
        if server:server.terminate();server.wait(timeout=5)
        if log:log.close()
        shutil.rmtree(root,ignore_errors=True)

if __name__=='__main__':run()
