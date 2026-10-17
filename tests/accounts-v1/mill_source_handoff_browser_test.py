"""Native Mill source -> actual Accounts review/correction/payment in a disposable app."""
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
$masters['business_parties'][]=['id'=>'QA-KE','values'=>['K-Electric','','Service Provider','','','','','','','','Active']];
$user=['id'=>1000,'username'=>'qaowner','full_name'=>'QA Mill Handoff','role'=>'Super Admin','permissions'=>['Mill'=>'all','Accounts'=>'all','Directors'=>'all','Exports'=>'all'],'active'=>true,'must_change_password'=>false,'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'master_access'=>true];
tt_ensure_data_dir();file_put_contents(TT_STORE_FILE,json_encode(['users'=>[$user],'masters'=>$masters,'settings'=>['qa_account_seeded'=>true],'master_options'=>tt_default_master_options(),'audit'=>[]]));
file_put_contents(TT_DATA_DIR.'/operations.json',json_encode(['revision'=>0,'values'=>['tt30mills'=>json_encode([['id'=>1,'name'=>'TTI Rice Mills','entity'=>'TTI','type'=>'Own / Default'],['id'=>2,'name'=>'QA Receiving Mill','entity'=>'TTI','type'=>'Own']]),'tt30bags'=>json_encode([['id'=>100,'entity'=>'TTI','mill'=>'TTI Rice Mills','brand'=>'QA PRODUCTION','size'=>'50 kg','bagType'=>'PP','ordered'=>100,'received'=>100,'poNo'=>'QA-PO','poLineKey'=>'L1']]),'tt33bagreceipts'=>json_encode([['id'=>101,'bagId'=>100,'date'=>'2026-10-01','qty'=>100]])],'meta'=>[]]));
file_put_contents(TT_DATA_DIR.'/accounts.json',json_encode(['revision'=>0,'journals'=>new stdClass(),'supplierBills'=>new stdClass()]));
file_put_contents(__DIR__.'/credentials.json',json_encode(['password'=>$password,'dataDir'=>TT_DATA_DIR]));
'''

def run():
    root=Path(tempfile.mkdtemp(prefix='tti-mill-handoff-'));server=None;log=None
    env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
    output=Path(os.environ['TT_QA_OUTPUT']) if os.environ.get('TT_QA_OUTPUT') else None
    try:
        app=root/'repo';shutil.copytree(ROOT,app,ignore=shutil.ignore_patterns('.git','node_modules','test-results','playwright-report','__pycache__'))
        sessions=root/'sessions';sessions.mkdir();(root/'seed.php').write_text(SEED)
        subprocess.run(['php','-d',f'session.save_path={sessions}',str(root/'seed.php')],env=env,capture_output=True,text=True,check=True)
        credentials=json.loads((root/'credentials.json').read_text());data=Path(credentials['dataDir']);books=data/'accounts.json'
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        base=f'http://127.0.0.1:{port}';log=(root/'server.log').open('w+')
        server=subprocess.Popen(['php','-d',f'session.save_path={sessions}','-S',f'127.0.0.1:{port}','-t',str(app)],env=env,stdout=log,stderr=log)
        time.sleep(.3)
        with sync_playwright() as pw:
            browser=pw.chromium.launch(headless=True);context=browser.new_context(accept_downloads=True);mill=context.new_page();errors=[]
            mill.on('pageerror',lambda e:errors.append(str(e)));mill.on('dialog',lambda d:d.accept())
            mill.goto(base+'/login.php');mill.locator('[name="username"]').fill('qaowner');mill.locator('[name="password"]').fill(credentials['password']);mill.locator('button[type="submit"]').click();mill.wait_for_load_state('domcontentloaded')
            mill.goto(base+'/module.php?id=milling');mill.locator('.mill-card[onclick="chooseMill(1)"]').click();mill.locator('[onclick="openPanel(\'labour\')"]').click();mill.locator('[onclick="processingTab(\'ke\')"]').click()
            for selector,value in {'#keBillDate':'2026-10-17','#keReadFrom':'2026-09-01','#keReadTo':'2026-09-30','#keRef':'QA-KE-HANDOFF','#keAmount':'1000','#keUnits':'20'}.items():mill.locator(selector).fill(value)
            original_books=books.read_bytes();blocked=[True]
            def intercept(route):
                if route.request.method=='POST' and blocked[0]:blocked[0]=False;route.abort()
                else:route.continue_()
            context.route('**/api/operations.mysql.php?asset_refs=1',intercept)
            mill.get_by_role('button',name='SAVE KE BILL',exact=True).click();mill.locator('[data-mill-save-recovery]').wait_for();assert mill.locator('#keRef').input_value()=='QA-KE-HANDOFF';assert books.read_bytes()==original_books
            with mill.expect_response(lambda r:'/api/operations.mysql.php?asset_refs=1' in r.url and r.request.method=='POST') as saved_response:mill.get_by_role('button',name='SAVE KE BILL',exact=True).click()
            assert saved_response.value.status==200;mill.wait_for_function("document.querySelector('#keRef').value === ''")
            op=context.request.get(base+'/api/operations.mysql.php').json();rows=json.loads(op['values']['tt38kebills']);assert len(rows)==1 and rows[0]['amount']==1000;source_id=str(rows[0]['id']);assert books.read_bytes()==original_books
            accounts=context.new_page();accounts.on('pageerror',lambda e:errors.append(str(e)));accounts.on('dialog',lambda d:d.accept());accounts.goto(base+'/accounts/');accounts.wait_for_function('!!window.TT_MILL_LIABILITIES')
            accounts.evaluate('window.TT_MILL_LIABILITIES.open()');accounts.locator('[data-review-bill]').click()
            with accounts.expect_response(lambda r:'/api/mill_liabilities.php' in r.url and r.request.method=='POST') as post_response:accounts.locator('[data-post]').click()
            posted=post_response.value.json();assert post_response.value.status==200,posted;first=posted['result']['record'];accounts.locator('[data-pay]').wait_for()
            initial=json.loads(books.read_text());journal=initial['journals'][first['journalId']];assert journal['totalDebit']==1000 and {l['account'] for l in journal['lines']}=={'5200','2140'}
            # Amend the actual native history row, then replace its liability through Accounts.
            mill.get_by_role('button',name='Amend saved bill',exact=True).click();mill.locator('[data-field="amount"]').fill('1200');mill.locator('[data-reason]').fill('Correct actual received electricity bill')
            canonical_before=books.read_bytes()
            with mill.expect_response(lambda r:'/api/mill_source_amend.php' in r.url and r.request.method=='POST') as amend_response:mill.locator('[data-save]').click()
            amended=amend_response.value.json();assert amend_response.value.status==200,amended;assert amended['result']['record']['_sourceAmendments'][0]['before']['amount']==1000;assert books.read_bytes()==canonical_before
            mill.locator('[data-mill-source-amend] [data-close]').click()
            accounts.locator('[data-refresh]').click();accounts.locator('[data-replace]').wait_for();assert accounts.locator('[data-pay]').count()==0
            accounts.locator('[data-reason]').fill('Reconcile corrected source bill');accounts.locator('[data-correction-date]').fill('2026-10-17')
            with accounts.expect_response(lambda r:'/api/mill_liabilities.php' in r.url and r.request.method=='POST') as replacement_response:accounts.locator('[data-replace]').click()
            replaced=replacement_response.value.json();assert replacement_response.value.status==200,replaced;current=replaced['result']['record'];accounts.locator('[data-pay]').wait_for()
            source_key=current['key'];bill_key=current['billId']+'|'+source_key;csrf=accounts.evaluate('window.TT_ACCOUNT_ACCESS.csrf')
            payment=dict(csrf=csrf,action='post_bill_payment',entity='TTI',date='2026-10-17',supplier='K-Electric',category='',selectedBills=[bill_key],explicitBillSelection=True,amount=400,paymentMode='CASH',requestKey='browser-mill-payment-0001',applyPaymentDifferenceAsDiscount=False,selectedOutstanding=1200)
            response=context.request.post(base+'/api/supplier_settlements.php',data=payment);assert response.status==200,response.text()
            paid_once=books.read_bytes();response=context.request.post(base+'/api/supplier_settlements.php',data=payment);assert response.status==200 and books.read_bytes()==paid_once,'Payment replay changed books'
            mill.get_by_role('button',name='Amend saved bill',exact=True).click();mill.locator('[data-fields][disabled]').wait_for();assert 'payment allocations' in mill.locator('[data-mill-source-amend] [data-message]').inner_text();mill.locator('[data-mill-source-amend] [data-close]').click()
            payment.update(amount=800,requestKey='browser-mill-payment-0002',selectedOutstanding=800);response=context.request.post(base+'/api/supplier_settlements.php',data=payment);assert response.status==200,response.text()
            final=json.loads(books.read_text());expense=sum(l.get('debit',0)-l.get('credit',0) for j in final['journals'].values() for l in j['lines'] if l['account']=='5200');assert expense==1200,'Settlement duplicated expense';assert final['journals'][first['journalId']]==journal,'Original journal changed'
            with mill.expect_response(lambda r:'/api/mill_liability_status.php' in r.url):mill.locator('#labour [data-refresh-mill-bill-status]').click()
            mill.wait_for_function("document.querySelector('#processingExpenseBody').textContent.includes('outstanding Rs 0')")
            accounts.locator('[data-refresh]').click();accounts.wait_for_function("document.querySelector('[data-review]').textContent.includes('Outstanding PKR 0.00')")
            with accounts.expect_download() as download_info:accounts.locator('[data-csv]').click()
            report=Path(download_info.value.path()).read_text();assert 'Outstanding PKR' in report and 'QA-KE-HANDOFF' in report and '17-10-2026' in report
            # Real authenticated production form: a failed source Save retains its
            # raw inputs; its later acknowledged amendment survives a lost response.
            financial_before=books.read_bytes();mill.evaluate("openPanel('production')")
            mill.locator('#prodDate').fill('2026-10-17')
            mill.locator('#prodTable tr').first.locator('input').first.fill('10')
            raw=mill.locator('#productionActualInput [data-raw-index="0"]')
            raw.locator('[data-field="lotRef"]').fill('QA-RAW-LOT');raw.locator('[data-field="grossKg"]').fill('600');mill.locator('#capacityReason').fill('Actual fixture production downtime')
            mill.locator('[data-review-source-bags]').click();mill.locator('[data-source-lot]').wait_for();mill.locator('[data-source-lot]').fill('10')
            blocked[0]=True;mill.get_by_role('button',name='Save Production',exact=True).click();mill.locator('[data-production-save-recovery]').wait_for()
            assert raw.locator('[data-field="grossKg"]').input_value()=='600' and books.read_bytes()==financial_before
            with mill.expect_response(lambda r:'/api/operations.mysql.php?asset_refs=1' in r.url and r.request.method=='POST') as production_response:mill.get_by_role('button',name='Save Production',exact=True).click()
            assert production_response.value.status==200,production_response.value.text()
            mill.wait_for_function("document.querySelector('#productionActualInput [data-field=grossKg]').value === ''")
            saved_ops=context.request.get(base+'/api/operations.mysql.php').json();productions=json.loads(saved_ops['values']['tt30prod']);assert len(productions)==1
            run=productions[0];assert run['rawInputs'][0]['grossKg']==600 and run['rows'][0]['bags']==10
            assert run['bagAllocationVersion']==1 and run['bagAllocations'][0]['qty']==10 and run['bagAllocations'][0]['sourceSnapshot']['poNo']=='QA-PO'
            inventory=context.request.get(base+'/api/bag_transfers.php?purpose=production&entity=TTI&locationId=mills-1&asOf=2026-10-17').json();assert inventory['inventory']['lots']['PO|100|mills-1']['availableQty']==90
            issues=json.loads(saved_ops['values']['tt36bagissues']);assert len(issues)==1 and issues[0]['usage']['QA PRODUCTION']==10 and issues[0]['userId']==1000
            mill.evaluate('(id)=>loadProductionForEdit(id)',run['id']);assert mill.locator('#capacityReason').input_value()=='Actual fixture production downtime';raw=mill.locator('#productionActualInput [data-raw-index="0"]');raw.locator('[data-field="grossKg"]').fill('700');mill.locator('#prodTable tr').first.locator('input').first.fill('12')
            mill.locator('[data-review-source-bags]').click();mill.locator('[data-source-lot]').wait_for();mill.locator('[data-source-lot]').fill('12')
            context.unroute('**/api/operations.mysql.php?asset_refs=1',intercept);lost=[True];source_posts=[]
            def lost_ack(route):
                if route.request.method=='POST':
                    source_posts.append(route.request.post_data_json)
                    if lost[0]:
                        lost[0]=False;response=route.fetch();assert response.status==200,response.text();route.abort();return
                route.continue_()
            context.route('**/api/operations.mysql.php?asset_refs=1',lost_ack)
            mill.get_by_role('button',name='Save Production',exact=True).click();mill.locator('[data-production-save-recovery]').wait_for();assert raw.locator('[data-field="grossKg"]').input_value()=='700'
            mill.get_by_role('button',name='Save Production',exact=True).click();mill.wait_for_function("document.querySelector('#productionActualInput [data-field=grossKg]').value === ''")
            assert len(source_posts)==1,'Confirming the real lost response sent another production mutation'
            saved_ops=context.request.get(base+'/api/operations.mysql.php').json();productions=json.loads(saved_ops['values']['tt30prod']);issues=json.loads(saved_ops['values']['tt36bagissues']);audits=json.loads(saved_ops['values']['tt30prodaudit'])
            assert len(productions)==1 and productions[0]['id']==run['id'] and productions[0]['rows'][0]['bags']==12 and productions[0]['rawInputs'][0]['grossKg']==700
            assert len(issues)==1 and issues[0]['usage']['QA PRODUCTION']==12 and len(audits)==1 and audits[0]['userId']==1000 and audits[0]['before']['rows'][0]['bags']==10 and audits[0]['after']['rows'][0]['bags']==12
            assert books.read_bytes()==financial_before,'Production Save or retry wrote financial journals'
            # Native transfer UI -> actual acknowledged controller. No PO or books rewrite.
            original_orders=saved_ops['values']['tt30bags'];mill.evaluate("openPanel('newbags')")
            mill.locator('[data-source-lot]').wait_for();mill.locator('[data-transfer-date]').fill('2026-10-17')
            with mill.expect_response(lambda r:'/api/bag_transfers.php?' in r.url):mill.locator('[data-transfer-refresh]').click()
            mill.locator('[data-source-lot]').fill('10');mill.locator('[data-transfer-destination]').select_option('mills-2')
            transfer_blocked=[True];transfer_posts=[]
            def transfer_failure(route):
                if route.request.method=='POST':
                    transfer_posts.append(route.request.post_data_json)
                    if transfer_blocked[0]:transfer_blocked[0]=False;route.abort();return
                route.continue_()
            context.route('**/api/bag_transfers.php',transfer_failure)
            mill.locator('[data-dispatch-bags]').click();mill.locator('[data-confirm-pending]').wait_for();assert mill.locator('[data-source-lot]').input_value()=='10'
            with mill.expect_response(lambda r:'/api/bag_transfers.php' in r.url and r.request.method=='POST') as dispatch_response:mill.locator('[data-dispatch-bags]').click()
            dispatch=dispatch_response.value.json();assert dispatch_response.value.status==200,dispatch
            mill.wait_for_function("document.querySelector('[data-source-lot]').value === ''")
            assert len(transfer_posts)==2 and transfer_posts[0]==transfer_posts[1],'Failed dispatch changed its retry identity'
            transfer=dispatch['movement']['record'];transfer_id=transfer['id'];line_id=transfer['lines'][0]['lineId'];target_lot='BT|'+transfer_id+'|'+line_id
            stock=context.request.get(base+'/api/bag_transfers.php?entity=TTI&locationId=mills-1&asOf=2026-10-17').json()
            target=context.request.get(base+'/api/bag_transfers.php?entity=TTI&locationId=mills-2&asOf=2026-10-17').json()
            assert stock['inventory']['lots']['PO|100|mills-1']['availableQty']==78 and target['inventory']['lots'][target_lot]['availableQty']==0
            context.unroute('**/api/bag_transfers.php',transfer_failure)
            mill.evaluate("chooseMill(2);openPanel('newbags')");mill.locator('[data-receive-transfer]').wait_for();mill.locator('[data-receive-transfer]').click();mill.locator('[data-ack-line]').fill('6')
            mill.locator('[data-ack-date]').fill('2026-10-17');mill.locator('[data-accepted-inspection]').check()
            receipt_lost=[True];receipt_posts=[]
            def receipt_lost_ack(route):
                if route.request.method=='POST':
                    receipt_posts.append(route.request.post_data_json)
                    if receipt_lost[0]:
                        receipt_lost[0]=False;response=route.fetch();assert response.status==200,response.text();route.abort();return
                route.continue_()
            context.route('**/api/bag_transfers.php',receipt_lost_ack)
            mill.locator('[data-save-ack]').click();mill.locator('[data-confirm-pending]').wait_for();assert mill.locator('[data-ack-line]').input_value()=='6'
            state_after_receipt=(data/'operations.json').read_bytes()
            with mill.expect_response(lambda r:'/api/bag_transfers.php' in r.url and r.request.method=='POST') as receipt_response:mill.locator('[data-confirm-pending]').click()
            assert receipt_response.value.status==200 and receipt_response.value.json()['movement']['replayed']
            mill.wait_for_function("!document.querySelector('[data-confirm-pending]')")
            assert len(receipt_posts)==2 and receipt_posts[0]==receipt_posts[1] and (data/'operations.json').read_bytes()==state_after_receipt,'Lost acknowledgement changed receipt stock/version'
            target=context.request.get(base+'/api/bag_transfers.php?entity=TTI&locationId=mills-2&asOf=2026-10-17').json()
            assert target['inventory']['lots'][target_lot]['availableQty']==6
            context.unroute('**/api/bag_transfers.php',receipt_lost_ack)
            mill.evaluate("chooseMill(1);openPanel('newbags')");mill.locator('[data-return-transfer]').wait_for();mill.locator('[data-return-transfer]').click()
            mill.locator('[data-ack-date]').fill('2026-10-17');mill.locator('[data-ack-line]').fill('2');mill.locator('[data-ack-reason]').fill('Actually returned and counted at source')
            with mill.expect_response(lambda r:'/api/bag_transfers.php' in r.url and r.request.method=='POST') as return_response:mill.locator('[data-save-ack]').click()
            assert return_response.value.status==200,return_response.value.text()
            mill.wait_for_function("!document.querySelector('[data-ack-line]')")
            stock=context.request.get(base+'/api/bag_transfers.php?entity=TTI&locationId=mills-1&asOf=2026-10-17').json()
            assert stock['inventory']['lots']['PO|100|mills-1']['availableQty']==80
            saved_ops=context.request.get(base+'/api/operations.mysql.php').json()
            assert saved_ops['values']['tt30bags']==original_orders and books.read_bytes()==financial_before,'Native transfer UI changed supplier PO or financial records'
            # Actual native supplier receipt: failed acknowledgement retains entry; accepted/quarantined counters commit together.
            mill.locator('#bagOrdersBody tr.clickrow').first.click();mill.locator('#inlineBagDate').fill('2026-10-17');mill.locator('#inlineBagQty').fill('10');mill.locator('#inlineBagInspected').check()
            receipt_failed=[True];supplier_posts=[]
            def supplier_receipt_failure(route):
                if route.request.method=='POST':
                    supplier_posts.append(route.request.post_data_json)
                    if receipt_failed[0]:receipt_failed[0]=False;route.abort();return
                route.continue_()
            context.route('**/api/bag_receipts.php',supplier_receipt_failure)
            mill.locator('[onclick="receiveBagsInline(event)"]').click();mill.locator('[data-bag-receipt-recovery]').wait_for();assert mill.locator('#inlineBagQty').input_value()=='10'
            with mill.expect_response(lambda r:'/api/bag_receipts.php' in r.url and r.request.method=='POST') as supplier_response:mill.locator('[onclick="receiveBagsInline(event)"]').click()
            assert supplier_response.value.status==200,supplier_response.value.text();mill.wait_for_function("!document.querySelector('#inlineBagQty')")
            assert len(supplier_posts)==2 and supplier_posts[0]==supplier_posts[1],'Supplier retry changed the original receipt facts'
            assert supplier_response.value.json()['receipt']['acceptedQty']==10 and supplier_response.value.json()['order']['received']==110
            context.unroute('**/api/bag_receipts.php',supplier_receipt_failure)
            mill.locator('#bagOrdersBody tr.clickrow').first.click();mill.locator('#inlineBagDate').fill('2026-10-17');mill.locator('#inlineBagQty').fill('4');mill.locator('#inlineBagInspected').check();mill.locator('#inlineMaterialMismatch').check()
            with mill.expect_response(lambda r:'/api/bag_receipts.php' in r.url and r.request.method=='POST') as held_response:mill.locator('[onclick="receiveBagsInline(event)"]').click()
            assert held_response.value.status==200,held_response.value.text();mill.wait_for_function("!document.querySelector('#inlineBagQty')")
            assert held_response.value.json()['receipt']['quarantinedQty']==4 and held_response.value.json()['order']['received']==114
            stock=context.request.get(base+'/api/bag_transfers.php?entity=TTI&locationId=mills-1&asOf=2026-10-17').json();source_lot=stock['inventory']['lots']['PO|100|mills-1']
            assert source_lot['availableQty']==90 and source_lot['quarantinedQty']==4,source_lot
            assert books.read_bytes()==financial_before,'Supplier receipt created a financial posting'
            assert not errors,errors
            if output:
                output.mkdir(parents=True,exist_ok=True);accounts.screenshot(path=str(output/'mill-source-handoff-accounts.png'),full_page=True);mill.screenshot(path=str(output/'mill-source-handoff-mill.png'),full_page=True)
            browser.close()
        print('PASS real native Mill save/retry -> Accounts liability -> audited source amendment -> immutable replacement -> partial/full/replayed payment -> verified Mill balance and saved review CSV; actual production source/packing/audit transaction with failed Save retention and real lost-response replay; acknowledged native bag dispatch, partial receiving with actual lost-response replay and source physical return; no duplicate expense')
    except Exception:
        if output:
            output.mkdir(parents=True,exist_ok=True);log.flush();tail=(root/'server.log').read_text(errors='replace').splitlines()[-35:];(output/'mill-source-handoff-failure.txt').write_text('\n'.join(tail))
        raise
    finally:
        if server:server.terminate();server.wait(timeout=10)
        if log:log.close()
        shutil.rmtree(root,ignore_errors=True)

if __name__=='__main__':run()
