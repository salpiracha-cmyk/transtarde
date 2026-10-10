"""Party statements against the real endpoint, using disposable books only."""
import json, os, pathlib, shutil, socket, subprocess, tempfile, time
import urllib.request, urllib.error, urllib.parse

ROOT = pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='party-ledger-qa-') as temp:
    root = pathlib.Path(temp)
    for folder in ['api', 'data', 'accounts']:
        (root/folder).mkdir()
    for name in ['accounts_subaccounts_core.php','assets_registry_core.php','accounts_ledger_browser.php','accounts_post_delete_core.php', 'accounts_reference.php', 'tg_remittance_core.php', 'fi_credit_advice_link.php', 'receipt_invoice_links.php','customer_receivables_core.php']:
        shutil.copy(ROOT/'api'/name, root/'api'/name)
    for path in (ROOT/'accounts').glob('*.json'):
        shutil.copy(path, root/'accounts'/path.name)
    shutil.copy(ROOT/'accounts/all-ledgers-ui.js',root/'accounts/all-ledgers-ui.js')
    (root/'accounts/index.html').write_text('''<!doctype html><html><head></head><body>
    <script src="all-ledgers-ui.js"></script><script>localStorage.setItem('tt_accounts_entity','TTI');
    window.TT_ALL_LEDGERS.open('','party');</script></body></html>''')
    (root/'auth_store.php').write_text('''<?php
    define('TT_DATA_DIR',__DIR__.'/data');
    function tt_require_login(){return ['id'=>1,'role'=>'Accounts'];}
    function tt_user_can_open_module($u,$m){return ($_GET['denyModule']??'')!=='1';}
    function tt_user_can_access_entity($u,$e,$a){return $e!=='BRM';}
    function tt_list_masters(){return ['business_parties'=>[['id'=>'P1','values'=>['ACME Rice','HKM']]]];}
    ''')
    def line(account, debit=0, credit=0, **extra):
        return dict(account=account, debit=debit, credit=credit, **extra)
    def journal(id, date, lines, entity='TTI', **extra):
        return dict(id=id, date=date, entity=entity, status='Posted', lines=lines,
                    reference=id, narration='TEST '+id, **extra)
    books = {'journals': {
        'POST-2026-00001': journal('POST-2026-00001','2026-06-30',[
            line('2110',credit=100,counterparty='ACME Rice'),line('1310',debit=100)],meta={'supplier':'ACME Rice'}),
        'POST-2026-00002': journal('POST-2026-00002','2026-07-01',[
            line('2110',debit=40,counterparty='P1'),line('1110',credit=40)],meta={'supplier':'ACME Rice'}),
        'POST-2026-00003': journal('POST-2026-00003','2026-07-02',[
            line('1210',debit=30,subledger='ACME Rice'),line('4100',credit=30)]),
        'POST-2026-00004': journal('POST-2026-00004','2026-07-03',[
            line('1250',debit=10,party=' acme   rice '),line('1110',credit=10)]),
        'POST-2026-00005': journal('POST-2026-00005','2026-07-04',[
            line('2110',credit=7),line('1310',debit=7)]),
        'POST-2026-00006': journal('POST-2026-00006','2026-07-04',[
            line('2140',credit=15),line('6800',debit=15)],meta={'supplierBillId':'B1'}),
        'POST-2026-00007': journal('POST-2026-00007','2026-07-05',[
            line('2110',credit=900,counterparty='SECRET BRM'),line('1310',debit=900)],entity='BRM'),
        'POST-2026-00008': journal('POST-2026-00008','2026-08-01',[
            line('2110',credit=50,counterparty='ACME Rice'),line('1310',debit=50)]),
        'POST-2026-00009': journal('POST-2026-00009','2026-07-05',[
            line('1210',debit=500,customer='Draft customer'),line('4100',credit=500)]),
        'POST-2026-00010': journal('POST-2026-00010','2026-07-06',[
            line('2500',credit=367,counterparty='TTI',currency='USD',nativeDebit=0,nativeCredit=100),line('1310',debit=367)],entity='TG',meta={'currency':'USD'}),
        'POST-2026-00011': journal('POST-2026-00011','2026-07-07',[
            line('2500',debit=36.7,counterparty='TTI',currency='USD'),line('1110',credit=36.7)],entity='TG',meta={'currency':'USD'}),
    }, 'supplierBills': {'B1': {'vendor':'ACME Rice'}}}
    books['journals']['POST-2026-00009']['status']='Draft'
    path=root/'data/accounts.json';path.write_text(json.dumps(books));original=path.read_bytes()
    with socket.socket() as sock:
        sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    env=os.environ.copy()
    for key in ['TT_DB_HOST','TT_DB_NAME','TT_DB_USER','TT_DB_PASS']:env.pop(key,None)
    server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root)],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    def get(**extra):
        params=dict(entity='TTI',category='party',from_='2026-07-01',to='2026-07-31')
        params.update(extra);params['from']=params.pop('from_')
        url=f'http://127.0.0.1:{port}/api/accounts_ledger_browser.php?'+urllib.parse.urlencode(params)
        try:
            with urllib.request.urlopen(url,timeout=5) as response:
                raw=response.read();return response.status, raw.decode() if params.get('format')=='csv' else json.loads(raw)
        except urllib.error.HTTPError as err:return err.code,json.load(err)
    try:
        for _ in range(40):
            try:status,result=get();break
            except urllib.error.URLError:time.sleep(.1)
        assert status==200 and result['rows']==[], result
        assert set(result['parties'])=={'ACME Rice'}, result
        assert result['missingPartyLines']==1
        status,result=get(party='acme rice')
        assert status==200, result
        assert result['opening']==-100 and result['closing']==-35, result
        assert sum(r['debit'] for r in result['rows'])==80 and sum(r['credit'] for r in result['rows'])==15, result
        assert {r['account'] for r in result['rows']}=={'2110','1210','1250','2140'}
        assert result['rows'][0]['voucher']=='POST-2026-00006'
        assert result['rows'][0]['balance']==-35
        assert get(party='ACME Rice',q='2')[1]['rows'][0]['voucher']=='POST-2026-00002'
        assert get(party='ACME Rice',postId='POST-2026-00002')[1]['post']['lines'][1]['account']=='1110'
        assert get(party='HKM')[1]['closing']==-35, 'Saved party codes resolve to the same canonical ledger'
        status,register=get(category='other',account='POSTS');assert status==200 and any(r['voucher']=='POST-2026-00003' for r in register['rows']), 'Non-cash journals must be in the Post ID Register'
        assert get(party='SECRET BRM')[0]==422
        assert get(entity='BRM')[0]==403
        assert get(entity='ALL')[0]==403
        assert get(denyModule='1')[0]==403
        assert get(party='ACME Rice',account='2110')[0]==422
        assert get(from_='2026-08-01',to='2026-07-01')[0]==422
        assert get(category='other',account='1110')[1]['rows'], 'Existing account-head ledger remains available'
        status,csv=get(party='ACME Rice',format='csv')
        assert status==200 and 'ACME Rice' in csv and 'SECRET BRM' not in csv
        status,tg=get(entity='TG',party='TTI',currency='USD')
        assert status==200 and tg['balanceUnavailable'] and tg['closing'] is None, tg
        assert all(r['balance'] is None for r in tg['rows'])
        assert get(entity='TG',party='TTI',currency='AED')[1]['closing']==-330.3
        assert path.read_bytes()==original,'Party ledger reads must not change journals or book balances'
        books['exportCandidates']={'CF-BUYER':{'id':'CF-BUYER','entity':'TTI','candidateType':'CUSTOMER_EXPORT_SALE','transactionCurrency':'USD','transactionAmount':600,'pendingValuation':True,'journalId':'','counterparty':'Ali Sulaiman','meta':{'customer':'Ali Sulaiman','contractRef':'CF-1','commercialInvoiceNo':'CF-INV-1','commercialInvoiceDate':'2026-06-30','invoiceOriginalAmount':1000,'receivedBeforeCutoff':400,'carryForwardShipmentId':'CF-1','invoiceStage':'Final'}}}
        books['exportReceipts']={'R1':{'id':'R1','entity':'TTI','status':'Accounts Approved / Posted','receiptDate':'2026-07-10','transactionCurrency':'USD','allocations':[{'targetType':'EXPORT_RECEIVABLE','targetId':'CF-BUYER','foreignAmount':100}]}}
        path.write_text(json.dumps(books));unchanged=path.read_bytes()
        status,result=get(party='Ali Sulaiman',from_='0001-01-01')
        assert status==200 and result['rows']==[] and result['invoiceTotals']=={'USD':500},result
        assert result['invoiceRows'][0]['received']==500 and result['invoiceRows'][0]['invoiceAmount']==1000
        assert 'Carry-forward' in result['invoiceRows'][0]['status'] and result['closing']==0
        assert get(party='Ali Sulaiman',from_='0001-01-01',to='2026-07-09')[1]['invoiceTotals']=={'USD':600}
        assert 'CF-INV-1' in get(party='Ali Sulaiman',from_='0001-01-01',format='csv')[1]
        assert path.read_bytes()==unchanged,'Invoice linkage must not create journals or mutate balances'
        books['journals']['POST-2026-00012']=journal('POST-2026-00012','2026-07-10',[line('1210',credit=25,candidateId='CF-BUYER'),line('1110',debit=25)],meta={'customer':'Different remitter'})
        path.write_text(json.dumps(books));status,result=get(party='Ali Sulaiman',from_='0001-01-01')
        assert status==200 and result['rows'][0]['party']=='Ali Sulaiman' and result['closing']==-25,result
        path.write_bytes(original)
        print('Party ledgers: opening/running balances, cross-head links, master IDs, native-currency gaps, scope, search, CSV and read-only books passed')
        try:
            from playwright.sync_api import sync_playwright
        except ImportError:
            print('Browser checks run in the CI browser runtime; Playwright is not installed locally.')
        else:
            with sync_playwright() as pw:
                browser=pw.chromium.launch(headless=True)
                page=browser.new_page(viewport={'width':1000,'height':740})
                errors=[];page.on('pageerror',lambda error:errors.append(str(error)))
                page.goto(f'http://127.0.0.1:{port}/accounts/index.html')
                page.wait_for_selector('#tal-party')
                assert page.locator('#tal-account').count()==0
                assert page.locator('#tal-export').is_disabled()
                page.locator('#tal-party').fill('ACME Rice');page.locator('#tal-party').press('Enter')
                page.wait_for_selector('[data-post-id="POST-2026-00002"]')
                page.locator('#tal-period').select_option('range');page.wait_for_selector('#tal-from')
                page.locator('#tal-from').fill('2026-07-01');page.locator('#tal-to').fill('2026-07-31')
                page.locator('#tal-go').click()
                page.wait_for_function("!document.querySelector('#tal-export').disabled")
                content=page.locator('#tal-result').inner_text()
                assert 'Opening -100.00' in content and 'Closing -35.00' in content and 'need matching' in content
                with page.expect_download() as download:
                    page.locator('#tal-export').click()
                assert download.value.suggested_filename.endswith('.xlsx')
                with page.expect_popup() as popup:
                    page.locator('#tal-print').click()
                popup.value.wait_for_load_state()
                assert 'ACME Rice' in popup.value.locator('body').inner_text()
                popup.value.close()
                page.locator('[data-post-id="POST-2026-00002"]').click();page.wait_for_selector('#tal-back')
                assert '1110' in page.locator('.tal-table').inner_text()
                page.locator('#tal-back').click();page.wait_for_selector('#tal-party')
                page.locator('#tal-party').fill('Unknown');page.locator('#tal-go').click()
                page.wait_for_function("document.querySelector('#tal-result').textContent.includes('No posted party')")
                assert page.locator('#tal-export').is_disabled()
                page.locator('[data-category="other"]').first.click();page.wait_for_selector('#tal-account')
                assert not errors,errors
                browser.close()
                print('Party Ledger browser: name search, separate tab, date filters, balances, details, Print, Excel and stale-result protection passed')
    finally:
        server.terminate();server.wait(timeout=5)

