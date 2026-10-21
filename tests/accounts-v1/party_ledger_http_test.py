"""Party statements against the real endpoint, using disposable books only."""
import json, os, pathlib, shutil, socket, subprocess, tempfile, time
import urllib.request, urllib.error, urllib.parse

ROOT = pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='party-ledger-qa-') as temp:
    root = pathlib.Path(temp)
    for folder in ['api', 'data', 'accounts']:
        (root/folder).mkdir()
    for name in ['supplier_opening_core.php','accounts_subaccounts_core.php','accounts_ledger_visibility.php','assets_registry_core.php','service_ledger_groups.php','accounts_ledger_browser.php','accounts_read_policy.php','accounts_post_delete_core.php','accounts_post_delete_tg.php','tg_native_currency.php', 'accounts_reference.php', 'tg_remittance_core.php', 'fi_credit_advice_link.php', 'receipt_invoice_links.php','customer_receivables_core.php', 'tg_native_currency.php']:
        shutil.copy(ROOT/'api'/name, root/'api'/name)
    for path in (ROOT/'accounts').glob('*.json'):
        shutil.copy(path, root/'accounts'/path.name)
    shutil.copy(ROOT/'accounts/all-ledgers-ui.js',root/'accounts/all-ledgers-ui.js')
    (root/'accounts/index.html').write_text('''<!doctype html><html><head></head><body>
    <script src="all-ledgers-ui.js"></script><script>localStorage.setItem('tt_accounts_entity','TTI');
    window.TT_ALL_LEDGERS.open('','party');</script></body></html>''')
    (root/'auth_store.php').write_text('''<?php
    define('TT_DATA_DIR',__DIR__.'/data');
    function tt_require_login(){return ['id'=>1,'role'=>'Staff','permissions'=>['Accounts'=>'all']];}
    function tt_user_can_open_module($u,$m){return ($_GET['denyModule']??'')!=='1';}
    function tt_user_can_access_entity($u,$e,$a){return $e!=='BRM';}
    function tt_list_masters(){return ['business_parties'=>[['id'=>'P1','values'=>['ACME Rice','HKM']]]];}
    ''')
    # Exercise the actual production permission helper with this disposable identity.
    import re
    permission_helper=re.search(r'function tt_user_can_module_action\([^\n]*\n.*?\n}',(ROOT/'auth_store.php').read_text(),re.S).group(0)
    with (root/'auth_store.php').open('a') as auth_fixture: auth_fixture.write('\n'+permission_helper+'\n')
    def line(account, debit=0, credit=0, **extra):
        return dict(account=account, debit=debit, credit=credit, **extra)
    def journal(id, date, lines, entity='TTI', **extra):
        return dict(id=id, date=date, entity=entity, status='Posted', lines=lines,
                    reference=id, narration='TEST '+id, totalDebit=sum(x['debit'] for x in lines),totalCredit=sum(x['credit'] for x in lines),**extra)
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
            line('2140',credit=15),line('6800',debit=15)],meta={'payee':'','supplierBillId':'B1'}),
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
    books['creditCardMasters']={'C1':{'entity':'TTI','cardName':'Fixture card'}}
    books['creditCardStatements']={'S1':{'entity':'TTI','cardMasterId':'C1','cardName':'Fixture card'}}
    books['journals']['POST-2026-00012']=journal('POST-2026-00012','2026-07-10',[line('2400',credit=25,statementId='S1',cardMasterId='C1'),line('6900',debit=25)],sourceType='CREDIT_CARD_STATEMENT')
    books['journals']['POST-2026-00013']=journal('POST-2026-00013','2026-07-11',[line('2400',debit=25,statementId='S1',cardMasterId='C1'),line('1110',credit=25)],sourceType='CREDIT_CARD_PAYMENT')
    books['exportCandidates']={'CAND1':{'entity':'TTI','meta':{'vendor':'ACME Rice'}}}
    books['journals']['POST-2026-00014']=journal('POST-2026-00014','2026-07-12',[line('6500',debit=3),line('6900',credit=3)],meta={'candidateId':'CAND1'})
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
        assert set(result['parties'])=={'ACME Rice','Fixture card'}, result
        assert result['missingPartyLines']==1
        assert len(result['unmatchedPartyRows'])==1 and result['unmatchedPartyRows'][0]['account']=='2110',result
        status,card=get(party='Fixture card');assert status==200 and len(card['rows'])==2 and card['closing']==0,card
        status,result=get(party='acme rice')
        assert status==200, result
        assert result['opening']==-100 and result['closing']==-35, result
        assert sum(r['debit'] for r in result['rows'])==80 and sum(r['credit'] for r in result['rows'])==15, result
        assert {r['account'] for r in result['rows']}=={'2110','1210','1250','2140'}
        assert result['rows'][0]['voucher']=='POST-2026-00002'
        assert result['rows'][-1]['voucher']=='POST-2026-00006'
        assert result['rows'][-1]['balance']==-35
        assert get(party='ACME Rice',q='2')[1]['rows'][0]['voucher']=='POST-2026-00002'
        assert get(party='ACME Rice',postId='POST-2026-00002')[1]['post']['lines'][1]['account']=='1110'
        assert get(party='HKM')[1]['closing']==-35, 'Saved party codes resolve to the same canonical ledger'
        status,register=get(category='other',account='POSTS');assert status==200 and any(r['voucher']=='POST-2026-00003' for r in register['rows']), 'Non-cash journals must be in the Post ID Register'
        headings={row['voucher']:row['party'] for row in register['rows']}
        assert headings['POST-2026-00014']=='ACME Rice','Accepted source candidate must name its supplier in the register'
        assert headings['POST-2026-00006']=='ACME Rice','Source-linked supplier bill must name the party in the register'
        assert headings['POST-2026-00004']=='acme   rice','Explicit party survives without an invented ledger label'
        assert get(party='SECRET BRM')[0]==422
        assert get(entity='BRM')[0]==403
        assert get(entity='ALL')[0]==403
        status,all_posts=get(entity='ALL',category='other',account='POSTS');assert status==200 and all_posts['currency']=='MIXED',all_posts
        assert set(all_posts['registerEntities'])=={'TTI','TG'} and all(row['entity']!='BRM' for row in all_posts['rows'])
        assert any(row['entity']=='TG' and row['bookCurrency']=='USD' and row['credit']==100 for row in all_posts['rows'])
        assert all(row['bookCurrency']=='PKR' for row in all_posts['rows'] if row['entity']=='TTI')
        status,tg_posts=get(entity='ALL',category='other',account='POSTS',registerEntity='TG');assert status==200 and tg_posts['currency']=='AED' and all(row['entity']=='TG' for row in tg_posts['rows'])
        assert get(entity='ALL',category='other',account='POSTS',registerEntity='BRM')[0]==403
        status,post_csv=get(entity='ALL',category='other',account='POSTS',format='csv');assert status==200 and 'Book currency' in post_csv and 'USD' in post_csv and 'PKR' in post_csv and 'Subtotal' in post_csv and 'SECRET BRM' not in post_csv
        assert get(denyModule='1')[0]==403
        assert get(party='ACME Rice',account='2110')[0]==422
        assert get(from_='2026-08-01',to='2026-07-01')[0]==422
        assert get(category='other',account='1110')[1]['rows'], 'Existing account-head ledger remains available'
        status,csv=get(party='ACME Rice',format='csv')
        assert status==200 and 'ACME Rice' in csv and 'SECRET BRM' not in csv
        status,tg=get(entity='TG',party='TTI',currency='USD')
        assert status==200 and tg['balanceUnavailable'] and tg['closing'] is None, tg
        assert all(r['balance'] is None for r in tg['rows'])
        assert get(entity='TG',party='TTI',currency='AED')[1]['closing']==0,'AED native ledger must exclude USD postings'
        status,tg_books=get(entity='TG',category='other',account='2500')
        assert status==200 and abs(tg_books['closing']-(-330.3))<.005,'Existing no-currency integrations retain the historical AED account balance'
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
        # Existing cancelled journal pairs disappear without rewriting stored books.
        books['journals']['CANCELLED']=journal('CANCELLED','2026-07-11',[line('2110',credit=75,counterparty='ACME Rice'),line('1310',debit=75)])
        books['journals']['REVERSAL']=journal('REVERSAL','2026-07-12',[line('2110',debit=75,counterparty='ACME Rice'),line('1310',credit=75)],reversalOf='CANCELLED')
        path.write_text(json.dumps(books));cancelled_bytes=path.read_bytes()
        current=get(party='ACME Rice',to='2026-07-31')[1]
        assert not any(r['voucher'] in ['CANCELLED','REVERSAL'] for r in current['rows']),current
        for end in ['2026-07-11','2026-07-31']:
            _,register=get(category='other',account='POSTS',to=end);assert not any(r['voucher'] in ['CANCELLED','REVERSAL'] for r in register['rows']),register
        historical=get(party='ACME Rice',to='2026-07-11')[1]
        assert any(r['voucher']=='CANCELLED' for r in historical['rows']),historical
        _,statement=get(party='ACME Rice',to='2026-07-31',format='csv')
        import csv as csv_module,io
        dates=[r[0] for r in csv_module.reader(io.StringIO(statement)) if r and len(r[0])==10 and r[0][:4].isdigit()]
        assert dates==sorted(dates),'CSV is oldest first'
        assert path.read_bytes()==cancelled_bytes,'Cancellation display must preserve audit journals'
        path.write_bytes(original)
        # Company provisional clearing must never inherit the broker from a purchase header.
        audit_books=json.loads(path.read_text());audit_books['journals']['JJ-BILL']=journal('JJ-BILL','2026-07-15',[line('2210',debit=100),line('2110',credit=100),line('2120',credit=5),line('1310',debit=5)],sourceType='COMMODITY_BILL_VERIFIED',meta={'broker':'JJ Broker','relationshipName':'Real Supplier'})
        path.write_text(json.dumps(audit_books))
        status,jj=get(party='JJ Broker');assert status==200 and {(r['account'],r['debit'],r['credit']) for r in jj['rows']}=={('2120',0,5)},jj
        status,supplier=get(party='Real Supplier');assert status==200 and supplier['closing']==-100,supplier
        status,bank=get(category='bank',account='1110',party='ACME Rice');assert status==200,(status,bank)
        assert bank['rows'] and all(r['account']=='1110' and 'ACME Rice' in r['party'] for r in bank['rows'])
        assert len({r['voucher'] for r in bank['rows']})==len(bank['rows']),'A party filter must never duplicate bank movements'
        path.write_bytes(original)
        # Salary cost and individual payable views do not mix office, mill or home expenses.
        previous=path.read_bytes()
        books['salaryMasters']={key:{'id':key,'entity':'TTI','name':key,'category':category} for key,category in [('Office worker','OFFICE_STAFF'),('Mill worker','MILL_STAFF'),('Home worker','HOME_STAFF')]}
        for index,(person,category) in enumerate([('Office worker','OFFICE_STAFF'),('Mill worker','MILL_STAFF'),('Home worker','HOME_STAFF')]):
            books['journals']['SALARY-'+str(index)]=journal('SALARY-'+str(index),'2026-07-15',[line('6210',debit=100+index,category=category,salaryMasterId=person),line('2140',credit=100+index,category=category,salaryMasterId=person)],sourceType='SALARY_MONTH')
        path.write_text(json.dumps(books));salary_bytes=path.read_bytes()
        for index,(person,category) in enumerate([('Office worker','OFFICE_STAFF'),('Mill worker','MILL_STAFF'),('Home worker','HOME_STAFF')]):
            status,cost=get(category='other',account='SALARY_COST|'+category);assert status==200 and len(cost['rows'])==1 and cost['closing']==100+index,cost
            status,payable=get(category='other',account='PAYROLL|'+person);assert status==200 and len(payable['rows'])==1 and payable['closing']==-(100+index),payable
            assert any(a['code']=='PAYROLL|'+person for a in payable['accounts'])
        assert path.read_bytes()==salary_bytes,'Salary views must not rewrite historical journal accounts'
        path.write_bytes(previous)
        print('Separate salary cost groups and employee payable views passed without changing journals')
        print('Cancellation projection, historical date, ascending CSV, correct broker/supplier scope and party-wise bank movements passed')
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

