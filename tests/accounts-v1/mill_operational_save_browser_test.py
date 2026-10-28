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
file_put_contents(TT_DATA_DIR.'/operations.json',json_encode(['revision'=>0,'values'=>['tt32stockadj'=>json_encode([['id'=>'fixture-opening','entity'=>'TTI','millId'=>1,'millName'=>'TTI Rice Mills','openingStock'=>true,'readyRiceKg'=>1000,'brand'=>'IRRI-6 White Rice — SOURCE','stockName'=>'IRRI-6 White Rice — SOURCE'],['id'=>'fixture-exporter-opening','entity'=>'TTI','millId'=>1,'millName'=>'TTI Rice Mills','openingStock'=>true,'readyRiceKg'=>1000,'brand'=>'QA BRAND','stockName'=>'QA BRAND']]),'tt30mills'=>json_encode([['id'=>1,'name'=>'TTI Rice Mills','entity'=>'TTI','type'=>'Own / Default'],['id'=>2,'name'=>'QA Receiving Mill','entity'=>'TTI','type'=>'Own']]),'tt30bags'=>json_encode([['id'=>100,'entity'=>'TTI','mill'=>'TTI Rice Mills','brand'=>'QA PRODUCTION','size'=>'50 kg','bagType'=>'PP','ordered'=>100,'received'=>100,'poNo'=>'QA-PO','poLineKey'=>'L1']]),'tt33bagreceipts'=>json_encode([['id'=>101,'bagId'=>100,'date'=>'2026-10-01','qty'=>100]])],'meta'=>[]]));
file_put_contents(TT_DATA_DIR.'/accounts.json',json_encode(['revision'=>0,'journals'=>new stdClass(),'supplierBills'=>new stdClass()]));
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
            mill.evaluate("openPanel('petty');togglePettyInline('Office Expense')");mill.locator('#petAmount').fill('25');mill.locator('#petRef').fill('Fixture office expense');mill.locator('#pettyInline').get_by_role('button',name='Save',exact=True).click();mill.wait_for_function("document.querySelector('#petAmount').value === ''")
            op=context.request.get(base+'/api/operations.mysql.php').json();assert len(json.loads(op['values']['tt33pettyexp']))==1;assert books.read_bytes()==original_books
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
            mill.locator('#directorExporterCompany').select_option('TTI');mill.locator('#directorExporterRefresh').click()
            choice=mill.locator('#directorExporterDeal option').filter(has_text='Fixture fellow exporter');choice.wait_for(state='attached');soda_id=choice.get_attribute('value');mill.locator('#directorExporterDeal').select_option(soda_id)
            mill.locator('#directorExporterStock').select_option('QA BRAND');mill.locator('#directorExporterBagTare').fill('100');mill.locator('#directorExporterUse').click()
            try:mill.locator('#savedExporterSales').filter(has_text='Fixture fellow exporter').wait_for(timeout=10000)
            except Exception:raise AssertionError({'handoff':mill.locator('#directorExporterStatus').inner_text(),'errors':errors,'dialogs':dialogs})
            director.close()
            mill.locator('#exTruck').fill('QA-303');mill.locator('#exBags').fill('10');mill.locator('#exGross').fill('501');mill.locator('[onclick="addExporterContainer()"]').click();mill.wait_for_function("document.querySelector('#exTruck').value === ''")
            op=context.request.get(base+'/api/operations.mysql.php').json();exporter=json.loads(op['values']['tt35exportersale']);assert len(exporter)==1 and len(exporter[0]['containers'])==1 and exporter[0]['containers'][0]['net']==500 and exporter[0]['exporterDealId']==soda_id
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
            assert not json.loads(books.read_text()).get('journals'),'Operational saves must not post a financial journal';assert not errors,errors
            # Native Pohanch uses the approved purchase feed; failure retains the entry, retry keeps one identity.
            identity=mill.evaluate("selectedPurchaseProduct()")
            ledger=json.loads(books.read_text());ledger['purchaseSodas']={'PS-FIXTURE':dict(identity,id='PS-FIXTURE',sodaNo='26001',entity='TTI',commodity='RICE',status='Open',readyRoute='DELIVER_TO_STOCK',locationName='TTI Rice Mills',locationId='mills-1',broker='',party='Fixture supplier',qtyFromKg=10000,qtyToKg=10000,ratePerKg=10,rate=10,rateUnit='KG',sodaDate='2026-10-01')};books.write_text(json.dumps(ledger))
            mill.reload();mill.locator('.mill-card[onclick="chooseMill(1)"]').click();mill.evaluate("openPanel('arrival')")
            mill.locator('#arrivalQueue').select_option(str(queue[0]['id']));mill.locator('#arrivalSoda option[value="26001"]').wait_for(state='attached');mill.locator('#arrivalSoda').select_option('26001');mill.locator('#karachiWeight').fill('990')
            context.unroute('**/api/operations.mysql.php?asset_refs=1',lose_ack);blocked[0]=True;context.route('**/api/operations.mysql.php?asset_refs=1',intercept)
            mill.locator('#savePohanchButton').click();mill.locator('[data-mill-action-recovery]').wait_for();assert mill.locator('#karachiWeight').input_value()=='990'
            mill.locator('#savePohanchButton').click();mill.locator('#pohanchFeedback').filter(has_text='Pohanch saved').wait_for()
            op=context.request.get(base+'/api/operations.mysql.php').json();slips=json.loads(op['values']['tt30slips']);assert len(slips)==1 and slips[0]['payableWeight']==990
            with context.expect_page() as printed:mill.get_by_role('button',name='Print Pohanch',exact=True).first.click()
            popup=printed.value;popup.locator('.pohanch').wait_for();assert popup.locator('.pohanch').evaluate('e=>e.getBoundingClientRect().height*25.4/96')<140;body=popup.locator('body').inner_text();assert '990 kg' in body and re.search(r'\d{2}-\d{2}-\d{4}',body) and not re.search(r'\d{4}-\d{2}-\d{2}',body);popup.close()
            # The authenticated native print uses committed loading and the shared half-A4 template.
            container={'id':'QA-GATE-LOAD','container':'MSCU123456-6','seal':'QA-SEAL','truck':'QA-404','date':'2026-10-27','weight':26950,'weighbridgeKg':27000,'packingKg':50,'bags':1000,'gate':'FIXTURE-GP','loadingComplete':True}
            shipment={'id':'QA-GATE-SHIP','entity':'TTI','millName':'TTI Rice Mills','brand':'Fixture','contractRef':'QA-CONTRACT','lotRef':'QA-LOT','brokenPct':5,'containers':[container]}
            operations=json.loads((data/'operations.json').read_text());operations['values']['tt30ship']=json.dumps([shipment]);(data/'operations.json').write_text(json.dumps(operations))
            with context.expect_page() as printed:mill.evaluate("s=>printGate(s.containers[0],s)",shipment)
            popup=printed.value;popup.locator('.pass').wait_for();body=popup.locator('body').inner_text();assert 'Weighbridge weight (kg)' in body and '27,000' in body and '26,950' in body and 'Packing deduction (kg)' in body and '50' in body;assert '5% BROKEN' in body and 'QA-LOT' in body and 'GP-000003' in body;assert popup.title();assert popup.locator('.pass').evaluate('e=>e.getBoundingClientRect().height*25.4/96')<140;popup.close()
            with context.expect_page() as printed:mill.evaluate("s=>printGate(s.containers[0],s)",shipment)
            popup=printed.value;popup.locator('.serial').filter(has_text='GP-000003').wait_for();popup.close()
            assert not errors,errors
            browser.close()
        print('PASS native Used Bags failure/retry/reload, operational petty expense, arrival and duplicate-request persistence, Local Sales save/payment/load/printed gate/close/reload, Directors local SODA -> Mill selection/truck/weights -> gate pass -> Directors quantities -> close/reload, atomic PALTAI lost-ack replay, native Pohanch retry and print weights/dates; fixture only')
    finally:
        if server:server.terminate();server.wait(timeout=5)
        if log:log.close()
        shutil.rmtree(root,ignore_errors=True)

if __name__=='__main__':run()
