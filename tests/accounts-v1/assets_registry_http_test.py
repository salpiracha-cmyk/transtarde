"""Actual asset endpoint and optional browser: cutoff, privacy, durable posting and UI."""
import json, os, pathlib, shutil, socket, subprocess, tempfile, time, urllib.request, urllib.error
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='assets-registry-qa-') as temp:
    root=pathlib.Path(temp)
    for folder in ['api','accounts','data']:(root/folder).mkdir()
    for name in ['assets_registry.php','assets_registry_core.php','accounts_bank_payment.php']:shutil.copy(ROOT/'api'/name,root/'api'/name)
    for name in ['accounting_master_v1.json','assets-registry-ui.js']:shutil.copy(ROOT/'accounts'/name,root/'accounts'/name)
    (root/'auth_store.php').write_text('''<?php
define('TT_DATA_DIR',__DIR__.'/data');function tt_ensure_data_dir(){}
function tt_require_login(){
 $role=$_GET['role']??'accounts';
 $p=['Accounts'=>['assets'=>['View','Create','Edit']],'Directors'=>[]];
 if($role==='readonly')$p['Accounts']['assets']=['View'];
 if($role==='director')$p=['Accounts'=>[],'Directors'=>['assets'=>['View','Create','Edit']]];
 if($role==='unrelated')$p=['Directors'=>['tgmaster'=>['View']]];
 return ['id'=>1,'role'=>'Staff','username'=>'Fixture','permissions'=>$p];
}
function tt_user_can_access_entity($u,$e,$a){return $e==='TTI'&&($a==='View'||($_GET['role']??'')!=='readonly');}
function tt_verify_csrf($csrf){return $csrf==='fixture';}
function tt_list_masters(){return ['banks'=>[['id'=>'B1','values'=>['Company Account','TTI','','TTI','Fixture Bank','','','PKR','12345']]]];}
function tt_bank_can_transact($id){return $id==='B1';}
''')
    initial={'journals':{},'bankAccountSettings':{'B1':{'defaultPaymentAccount':True}}}
    (root/'data/accounts.json').write_text(json.dumps(initial))
    (root/'accounts/index.html').write_text('''<!doctype html><button id="openAssets">Assets</button><script>window.TT_ACCOUNT_ACCESS={csrf:'fixture'};localStorage.setItem('tt_accounts_entity','TTI');</script><script src="assets-registry-ui.js"></script><script>document.querySelector('#openAssets').onclick=()=>TT_ASSETS_UI.open();</script>''')
    with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    def call(body=None,role='accounts',entity='TTI',asset='',scope=''):
        url=f'http://127.0.0.1:{port}/api/assets_registry.php?entity={entity}&role={role}&assetId={asset}&scope={scope}'
        if body is not None:body={'csrf':'fixture','entity':entity,**body}
        req=urllib.request.Request(url,data=json.dumps(body).encode() if body is not None else None,headers={'Content-Type':'application/json'})
        try:
            with urllib.request.urlopen(req,timeout=4)as res:return res.status,json.load(res)
        except urllib.error.HTTPError as err:return err.code,json.load(err)
    def saved():return json.loads((root/'data/accounts.json').read_text())
    def register(tag='P-001',**changes):return {'action':'register','requestKey':'register-'+tag,'name':'Private Villa','assetTag':tag,'type':'PROPERTY','ownerType':'PERSONAL','ownerName':'Owner Name','countryId':'AE','cityId':'DXB','address':'Secret Villa Address','unitNo':'Secret Plot 555','tenure':'FREEHOLD','purpose':'PERSONAL_USE','purchaseDate':'2025-01-01','cost':1000,'currency':'PKR','accountingMode':'REGISTER_ONLY','paymentPattern':'FULLY_PAID','privateNotes':'Confidential note','documentReferences':'Private deed','payments':[{'instalmentNo':'1','date':'2026-06-30','amount':1000}],**changes}
    try:
        for _ in range(50):
            try:call();break
            except urllib.error.URLError:time.sleep(.1)
        payload=register()
        assert call({**payload,'csrf':'wrong'})[0]==419
        assert call(payload,'readonly')[0]==403
        assert call(payload,entity='TG')[0]==403
        status,d=call(payload);assert status==200,d;id=d['result']['postId']
        assert d['assets']==[] and not saved()['journals']
        assert call(payload)[1]['result']['postId']==id and len(saved()['managedAssets'])==1,'Registration retries are idempotent'
        assert call({**payload,'name':'Changed'})[0]==422
        assert call(asset=id)[0]==404,'Accounts direct lookup cannot read fully paid property'
        assert call(asset=id,scope='directors')[0]==403,'Accounts cannot elevate by changing scope'
        assert call(role='unrelated',scope='directors')[0]==403,'Other director permissions cannot read assets'
        status,d=call(role='director',asset=id,scope='directors');assert status==200 and d['asset']['privateNotes']=='CONFIDENTIAL NOTE'
        public=json.dumps(call()[1]);assert all(x not in public for x in ['SECRET VILLA','PRIVATE DEED','OWNER NAME','Secret Plot'])
        bad=register('BAD',countryId='PK',cityId='DXB');assert call(bad)[0]==422
        monthly=register('P-002',paymentPattern='MONTHLY',payments=[{'instalmentNo':'1','date':'2026-06-30','amount':400}],firstDueDate='2026-07-31',monthlyAmount=250,instalmentCount=3)
        status,d=call(monthly);assert status==200,d;mid=d['result']['postId'];a=next(x for x in d['assets']if x['id']==mid)
        assert 'privateNotes' not in a and 'documentReferences' not in a
        assert a['schedule'][0]['balanceCents']==25000
        base={'action':'payment','assetId':mid,'version':a['version'],'requestKey':'payment-missing','instalmentNo':'2','date':'2026-07-01','amount':250,'cashAmount':0,'bankAmount':250,'paymentAccountId':'B1','bankPaymentMethod':'CHEQUE'}
        assert call(base)[0]==422 and not saved()['journals'],'Missing cheque must not mutate anything'
        online={**base,'requestKey':'payment-online','cashAmount':125,'bankAmount':125,'bankPaymentMethod':'ONLINE_BANKING'}
        status,d=call(online);assert status==200,d;jid=d['result']['postId'];assert len(saved()['journals'])==1
        assert call(online)[1]['result']['postId']==jid and len(saved()['journals'])==1,'Payment retry never duplicates cash/bank movement'
        j=saved()['journals'][jid];assert j['totalDebit']==j['totalCredit']==250
        assert [x['credit']for x in j['lines'][1:]]==[125,125]
        assert not any(s in json.dumps(j)for s in ['VILLA','OWNER NAME','Secret Plot'])
        assert call({**online,'requestKey':'stale-payment'})[0]==422
        a=next(x for x in call()[1]['assets']if x['id']==mid)
        assert call({'action':'payment','assetId':mid,'version':a['version'],'requestKey':'overpayment','instalmentNo':'3','date':'2026-07-01','amount':351,'cashAmount':351,'bankAmount':0})[0]==422
        assert call({'action':'payment','assetId':mid,'version':a['version'],'requestKey':'pay-final','instalmentNo':'3','date':'2026-07-01','amount':350,'cashAmount':350,'bankAmount':0})[0]==200
        assert not call()[1]['assets'] and call(asset=mid)[0]==404,'Final payment restricts access immediately'
        assert len(saved()['journals'])==2
        a=call(role='director',asset=mid,scope='directors')[1]['asset']
        status,d=call({'action':'reopen','assetId':mid,'version':a['version'],'requestKey':'director-reopen','reason':'Correct property reference'},role='director',scope='directors');assert status==200,d
        reopened=call(asset=mid)[1]['asset'];assert 'privateNotes' not in reopened
        status,d=call({'action':'amend','assetId':mid,'version':reopened['version'],'requestKey':'amend-reference','reason':'Correct reference','unitNo':'555A'});assert status==200,d
        assert call(asset=mid)[0]==404 and len(saved()['journals'])==2
        # Exact existing payment linking creates no second ledger movement.
        store=saved();store['journals']['OLD-PAY']={'id':'OLD-PAY','entity':'TTI','date':'2026-07-02','status':'Posted','lines':[{'account':'3200','debit':100,'credit':0},{'account':'1120','debit':0,'credit':100}]};(root/'data/accounts.json').write_text(json.dumps(store))
        linked=register('LINKED',cost=100,payments=[{'instalmentNo':'1','date':'2026-07-02','amount':100,'mode':'EXISTING_POST','existingPostId':'OLD-PAY'}]);before=len(saved()['journals']);status,d=call(linked);assert status==200,d;assert len(saved()['journals'])==before
        assert call(register('DUP-LINK',cost=100,payments=linked['payments']))[0]==422
        # Countries/cities add and removal retain the property snapshot.
        for body in [{'kind':'countries','operation':'add','name':'Oman'},{'kind':'cities','operation':'delete','id':'DXB'}]:
            assert call({'action':'location','requestKey':'location-'+body['kind'],**body})[0]==200
        assert call(role='director',asset=id,scope='directors')[1]['asset']['city']=='DUBAI'
        if os.environ.get('TT_QA_BROWSER')=='1':
            from playwright.sync_api import sync_playwright
            with sync_playwright() as p:
                browser=p.chromium.launch();page=browser.new_page(viewport={'width':1440,'height':1000});errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
                page.goto(f'http://127.0.0.1:{port}/accounts/index.html');page.click('#openAssets');page.wait_for_selector('#faNew');page.click('#faNew')
                page.fill('[name=name]','Karachi Property');page.fill('[name=assetTag]','BROWSER-001');page.fill('[name=ownerName]','Director One');page.select_option('[name=countryId]','PK');page.select_option('[name=cityId]','KHI');page.fill('[name=unitNo]','Plot 17');page.fill('[name=address]','Karachi project');page.fill('[name=purchaseDate]','2025-01-01');page.fill('[name=cost]','1000');page.select_option('[name=paymentPattern]','MONTHLY');page.fill('[name=firstDueDate]','2026-10-31');page.fill('[name=monthlyAmount]','300');page.fill('[name=instalmentCount]','2')
                page.click('#faAddHistory');page.fill('[data-number]','1');page.fill('[data-date]','2026-06-30');page.fill('[data-amount]','400');page.fill('[data-source]','Previous bank payment');page.click('#faRegister [type=submit]');page.wait_for_selector('#faConfirmHome');page.click('#faConfirmHome');page.click('#faPay');page.select_option('[name=assetId]',label='BROWSER-001 · KARACHI PROPERTY · PKR 600.00 remaining')
                assert page.input_value('#faBankId')=='B1' and page.input_value('#faMethod')=='CHEQUE'
                page.check('#faCash');page.fill('#faCashAmount','150');page.fill('#faBankAmount','150');page.select_option('#faMethod','ONLINE_BANKING');page.click('#faPayment [type=submit]');page.wait_for_selector('#faConfirmHome');assert not errors,errors
                page.click('#faConfirmHome');page.click('#faPay');page.select_option('[name=assetId]',label='BROWSER-001 · KARACHI PROPERTY · PKR 300.00 remaining');page.select_option('#faMethod','ONLINE_BANKING');page.click('#faPayment [type=submit]');page.wait_for_selector('#faConfirmHome');page.click('#faConfirmHome');assert 'KARACHI PROPERTY' not in page.locator('#ttAssetsWindow').inner_text()
                browser.close()
        print('Assets HTTP/browser: durable history, 1 July boundary, full-payment privacy, direct access, reasoned amendments, existing Post IDs and split payment passed')
    finally:server.terminate();server.wait(timeout=5)
