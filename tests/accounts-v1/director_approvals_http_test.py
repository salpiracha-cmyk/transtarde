"""Exercise owner queue access and the shared existing approval actions against isolated data."""
import json, pathlib, shutil, socket, subprocess, tempfile, time, urllib.request, urllib.error

ROOT = pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='director-approvals-') as temp:
    root = pathlib.Path(temp)
    (root / 'api').mkdir()
    (root / 'data').mkdir()
    (root / 'accounts').mkdir()
    for name in ['director_approvals.php', 'director_approvals_core.php', 'opening_balance_core.php','tg_native_currency.php','historical_bag_core.php','bag_acceptance_core.php','supplier_opening_core.php','bag_purchases.php', 'nonwoven_bag_bills.php', 'accounts_posting_preview.php']:
        shutil.copy(ROOT / 'api' / name, root / 'api' / name)
    shutil.copy(ROOT/'pending_uploads.php',root/'pending_uploads.php')
    for helper in ['bag_read_scope.php','operations_scope.php','export_permission_policy.php']:shutil.copy(ROOT/'api'/helper,root/'api'/helper)
    shutil.copy(ROOT / 'accounts/accounting_master_v1.json', root / 'accounts/accounting_master_v1.json')
    (root / 'auth_store.php').write_text('''<?php
define('TT_DATA_DIR',__DIR__.'/data'); function tt_ensure_data_dir(){}
function tt_release_read_session(){}
function tt_require_login(){return ['username'=>'Fixture','full_name'=>'Fixture Approver','role'=>$_GET['role']??'Super Admin','permissions'=>['Accounts'=>($_GET['role']??'Super Admin')==='Director'?['View']:'all']];}
function tt_user_can_open_module($u,$m){return $u['role']!=='Director'||$m==='Directors';}
function tt_user_can_director_approve($u){return ($u['role']??'')==='Super Admin'||(($u['role']??'')==='Director'&&tt_user_can_open_module($u,'Directors'));}
function tt_api_record_entity_allowed($u,$b,$e){return in_array($e,['TTI','BRM'],true)&&(!isset($b['entity'])||$b['entity']===$e);}
function tt_verify_csrf($t){return $t==='fixture';}
function tt_active_business_party_for_role($n,$r){return ['id'=>'supplier','values'=>[$n]];}
function tt_next_post_id(array $existing, string $module='Accounts', string $area='Journal', ?string $date=null): string {
 $year=substr($date ?: date('Y-m-d'),0,4);$n=count($existing)+1;
 do {$id='POST-'.$year.'-'.str_pad((string)$n,5,'0',STR_PAD_LEFT);$n++;} while (isset($existing[$id]));
 return $id;
}
''')
    bill = {'id':'B1','entity':'TTI','poNo':'PO1','lineKey':'L1','ratePerBag':12,'poRatePerBag':10,
            'status':'Hold - Director Approval Required','supplierSettlement':'Supplier contacted and settlement recorded','bags':5,'supplier':'Fixture Supplier','sellerInvoice':'INV1',
            'sellerInvoiceDate':'2026-10-01','baseAmount':60,'totalAmount':60,'gstAmount':0,'gstRate':0,
            'packingSize':'50kg','bagType':'Non Woven','brand':'Fixture'}
    initial = {'revision':0,'journals':{},'bagPurchaseOrders':{'PO1':{'shipmentRef':'FIXTURE-SHIPMENT','entity':'TTI','lines':[{'lineKey':'L1','ratePerBag':10,'orderedQty':100,'receivedQty':100,'acceptance':{'printing':True}}]}},
               'bagSupplierBills':{'B1':bill},'nonWovenBagSupplierBills':{'N1':{**bill,'id':'N1'}}}
    data_file = root / 'data/accounts.json'
    data_file.write_text(json.dumps(initial))
    with socket.socket() as sock:
        sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
    server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    def request(endpoint='director_approvals', role='Super Admin', body=None):
        req=urllib.request.Request(f'http://127.0.0.1:{port}/api/{endpoint}.php?role={role.replace(" ","%20")}',
            data=None if body is None else json.dumps(body).encode(),headers={'Content-Type':'application/json'})
        try:
            with urllib.request.urlopen(req,timeout=3) as response:return response.status,json.load(response)
        except urllib.error.HTTPError as error:return error.code,json.load(error)
    try:
        for _ in range(50):
            try: request();break
            except urllib.error.URLError:time.sleep(.1)
        for role in ['Staff','QA Tester']:
            assert request(role=role)[0]==403
        assert request(body={})[0]==405
        assert len(request()[1]['approvals'])==2
        assert len(request(role='Director')[1]['approvals'])==2, 'Director can read the same company-scoped queue without Accounts Create permission'
        assert json.loads(data_file.read_text())==initial, 'Reading queue must not mutate financial data'
        payload={'action':'approve_rate_exception','entity':'TTI','billId':'B1','csrf':'fixture','reason':'Approved documented printing difference','expectedAcceptanceFingerprint':next(x['fingerprint'] for x in request()[1]['approvals'] if x['billId']=='B1')}
        assert request('bag_purchases',body={**payload,'csrf':'wrong'})[0]==419
        assert request('bag_purchases',role='Staff',body=payload)[0]==403
        assert request('bag_purchases',body=payload)[0]==200, 'Super Admin uses the existing Director approval action'
        assert len(request()[1]['approvals'])==1
        assert request('nonwoven_bag_bills',role='Director',body={**payload,'billId':'N1','expectedAcceptanceFingerprint':request()[1]['approvals'][0]['fingerprint']})[0]==200
        assert request()[1]['approvals']==[], 'Director decisions disappear from the same owner queue'
        after=json.loads(data_file.read_text())
        assert len(after['journals'])==2
        assert after['bagSupplierBills']['B1']['acceptanceApprovedBy']=='Fixture Approver'
        assert after['nonWovenBagSupplierBills']['N1']['acceptanceApprovedBy']=='Fixture Approver'
        after['jvDrafts']={'JVD-1':{'id':'JVD-1','entity':'TTI','status':'Pending Approval','date':'2026-10-06','createdBy':'Tayyab','reference':'JV-TAYYAB','lines':[{'account':'1120','debit':25},{'account':'1110','credit':25}],'totalDebit':25,'totalCredit':25},'JVD-2':{'id':'JVD-2','entity':'TTI','status':'Posted'}}
        data_file.write_text(json.dumps(after));before=data_file.read_bytes();queue=request()[1]['approvals']
        assert len(queue)==1 and queue[0]['kind']=='jv' and queue[0]['id']=='JVD-1' and len(queue[0]['lines'])==2,queue
        assert data_file.read_bytes()==before,'Loading pending JVs must not post or alter them'
        # A pending decision must bind the settlement actually shown to its approver.
        for endpoint, collection, identifier in [('bag_purchases','bagSupplierBills','B1'),('nonwoven_bag_bills','nonWovenBagSupplierBills','N1')]:
            data_file.write_text(json.dumps(initial))
            old=next(x['fingerprint'] for x in request()[1]['approvals'] if x['billId']==identifier)
            code,response=request(endpoint,body={'action':'settle_bag_discrepancy','entity':'TTI','billId':identifier,'csrf':'fixture','reason':'Replacement settlement: supplier now offers a different resolution'})
            assert code==200,(endpoint,code,response)
            fresh=next(x['fingerprint'] for x in request()[1]['approvals'] if x['billId']==identifier)
            assert fresh!=old, endpoint+' settlement edit did not invalidate displayed token'
            for role in ['Director','Super Admin']:
                before=data_file.read_bytes()
                code,response=request(endpoint,role=role,body={**payload,'billId':identifier,'expectedAcceptanceFingerprint':old})
                assert code==422,(endpoint,role,code,response)
                assert data_file.read_bytes()==before,'Stale decision changed data, journal, audit or revision'
            assert request(endpoint,role='Director',body={**payload,'billId':identifier,'expectedAcceptanceFingerprint':fresh})[0]==200
            posted=json.loads(data_file.read_text())
            assert posted[collection][identifier]['journalId'] and len(posted['journals'])==1
        print('PASS both bag routes reject stale settlement decisions for Director and Super Admin; fresh decisions post once')
        data_file.write_text('{broken')
        assert request()[0]==500, 'Corrupt storage must report failure, not an empty queue'
        print('Director approvals HTTP: shared Director/owner feed, CSRF, staff denial, shared owner/Director actions, audit actor and failures passed')
    finally:
        server.terminate();server.wait(timeout=5)
