"""Actual-auth, disposable Accounts persistence failures and deterministic two-session races."""
import concurrent.futures
import json
import os
import pathlib
import re
import shutil
import subprocess
import tempfile

ROOT = pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='accounts-integrity-') as temp:
    root = pathlib.Path(temp)
    app = root / 'app'
    shutil.copytree(ROOT, app, ignore=shutil.ignore_patterns('.git', 'node_modules', '__pycache__'))
    private = root / 'transtrade_private'
    private.mkdir()
    books = private / 'accounts.json'
    env = {k: v for k, v in os.environ.items() if not k.startswith('TT_DB_')}
    user = {'id': 1, 'username': 'integrity-fixture', 'full_name': 'Integrity fixture',
            'role': 'Super Admin', 'active': True, 'session_version': 1, 'permissions': {}}
    bank = {'id': 'B1', 'values': ['Company Account', 'TTI', '', 'Fixture Company', 'Fixture Bank', '', 'Pakistan', 'PKR', '123456', '', '', '', 'Accounts', 'Active']}
    (private / 'auth.json').write_text(json.dumps({'users': [user], 'masters': {'banks': [bank]},
        'settings': {'qa_account_seeded': True, 'master_permission_schema': 2}, 'audit': []}))
    harness = root / 'request.php'
    harness.write_text(r'''<?php
$req=json_decode($argv[2],true);$_SERVER['REQUEST_URI']='/api/'.$req['endpoint'].'.php?entity=TTI';$_SERVER['REQUEST_METHOD']='POST';$_SERVER['REMOTE_ADDR']='192.0.2.81';$_GET=['entity'=>'TTI'];
if(isset($req['transaction']))$_SERVER['HTTP_X_TT_TRANSACTION_ID']=$req['transaction'];
session_name('TRANSTRADE_SESSION');session_id('integrity-'.$req['session']);session_start();$_SESSION=['user_id'=>1,'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];session_write_close();
$GLOBALS['fixtureBody']=json_encode($req['body']);
class FixtureInput{public $context;private $offset=0;function stream_open($p,$m,$o,&$opened){return $p==='php://input';}function stream_read($n){$s=substr($GLOBALS['fixtureBody'],$this->offset,$n);$this->offset+=strlen($s);return $s;}function stream_eof(){return $this->offset>=strlen($GLOBALS['fixtureBody']);}function stream_stat(){return [];}}
stream_wrapper_unregister('php');stream_wrapper_register('php',FixtureInput::class);
function fixture_barrier():void{$dir=getenv('INTEGRITY_BARRIER');if(!$dir)return;file_put_contents($dir.'/ready-'.getmypid(),'1');$until=microtime(true)+8;while(count(glob($dir.'/ready-*'))<2){if(microtime(true)>$until)throw new RuntimeException('Fixture barrier timeout');usleep(10000);}}
register_shutdown_function(static function()use($req){file_put_contents($req['statusFile'],(string)(http_response_code()?:200));});require $argv[1];
''')
    # The test copy adds only a rendezvous immediately before the endpoint's
    # first exclusive Accounts lock. On the old code, both stale calculations
    # have finished there; on the fixed code, both calculations are still ahead.
    for endpoint, constant in [('bag_supplier_payments', 'TT_BSP_FILE'),
                               ('other_supplier_settlements', 'TT_OSP_FILE'),
                               ('local_customer_receipts', 'TT_LCR_FILE')]:
        path = app / 'api' / (endpoint + '.php')
        source = path.read_text()
        lock_open = r"(tt_ensure_data_dir\(\);\$h=@?fopen\(" + re.escape(constant) + r",'(?:c\+|r\+b)'\);)"
        source, count = re.subn(lock_open, r'fixture_barrier();\1', source)
        assert count > 0, endpoint
        path.write_text(source)

    def request(endpoint, body, session='one', barrier=None, transaction=None):
        req = {'endpoint': endpoint, 'session': session,
               'body': {'entity': 'TTI', 'csrf': 'fixture', **body}}
        status_file=root/('status-'+os.urandom(8).hex());req['statusFile']=str(status_file)
        if transaction is not None:req['transaction']=transaction
        current_env = {**env, **({'INTEGRITY_BARRIER': str(barrier)} if barrier else {})}
        run = subprocess.run(['php', '-d', f'session.save_path={root}', str(harness),
            str(app / 'api' / (endpoint + '.php')), json.dumps(req)],
            text=True, capture_output=True, env=current_env, timeout=20)
        assert run.returncode == 0, run.stdout + run.stderr
        return int(status_file.read_text()), json.loads(run.stdout)

    def race(endpoint, initial, bodies):
        books.write_text(json.dumps(initial))
        with tempfile.TemporaryDirectory(prefix='barrier-', dir=root) as barrier:
            with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
                results = list(pool.map(lambda item: request(endpoint, item[1], str(item[0]), barrier), enumerate(bodies)))
        return results, json.loads(books.read_text())

    def journal(key, account, amount, soda='S1'):
        return {'id': key, 'entity': 'TTI', 'status': 'Posted', 'date': '2026-10-01',
                'sourceType': 'FIXTURE', 'meta': {'soda': soda, 'party': 'Fixture buyer'},
                'lines': [{'account': account, 'debit': amount, 'credit': 0,
                           'soda': soda, 'party': 'Fixture buyer'}]}

    reminder = {'action': 'save_reminder', 'label': 'Fixture', 'dueDate': '2026-10-25', 'expectedAmount': 25}
    original = {'revision': 7, 'journals': {}, 'untouched': {'value': 'original'}}
    for bad in ['1e309', '-1e309']:
        books.write_text(json.dumps(original)); before = books.read_bytes()
        status, _ = request('accounts', {**reminder, 'expectedAmount': bad})
        assert status == 422 and books.read_bytes() == before, status
    # JSON numeric overflow and invalid UTF-8 are alternate encoder-failure
    # classes in an existing store. Neither may erase the original file.
    for raw in [b'{"revision":7,"legacyMetric":1e309}', b'{"revision":7,"legacyText":"\xff"}']:
        books.write_bytes(raw); before = books.read_bytes()
        status, _ = request('accounts', reminder)
        assert status == 500 and books.read_bytes() == before, status
    books.write_text(json.dumps(original))
    assert request('accounts', reminder)[0] == 200
    saved = json.loads(books.read_text())
    assert saved['revision'] == 8 and saved['untouched'] == original['untouched']

    bag = {'id': 'A', 'entity': 'TTI', 'status': 'Posted', 'supplier': 'Fixture supplier',
           'sellerInvoice': 'INV-A', 'sellerInvoiceDate': '2026-10-01', 'poNo': 'PO-A', 'totalAmount': 100}
    bag_body = {'action': 'pay_bill', 'billId': 'A', 'date': '2026-10-21', 'amount': 100, 'method': 'Bank', 'bankId': 'B1'}
    results, saved = race('bag_supplier_payments', {'revision': 0, 'bagSupplierBills': {'A': bag}}, [bag_body, bag_body])
    assert sorted(x[0] for x in results) == [200, 409], results
    assert len(saved['journals']) == len(saved['bagSupplierPayments']) == saved['revision'] == 1
    initial = {'revision': 0, 'bagSupplierBills': {'A': bag, 'B': {**bag, 'id': 'B', 'sellerInvoice': 'INV-B'}}, 'foreignCollection': {'keep': 1}}
    results, saved = race('bag_supplier_payments', initial, [bag_body, {**bag_body, 'billId': 'B'}])
    assert [x[0] for x in results] == [200, 200], results
    assert len(saved['journals']) == len(saved['bagSupplierPayments']) == saved['revision'] == 2
    assert saved['foreignCollection'] == initial['foreignCollection']

    purchase = {'id': 'P1', 'entity': 'TTI', 'settlement': 'CREDIT', 'amount': 100,
                'supplier': 'Fixture supplier', 'invoiceNo': 'INV1', 'description': 'Fixture'}
    payment = {'action': 'pay', 'purchaseId': 'P1', 'date': '2026-10-21', 'amount': 60, 'method': 'CASH'}
    initial = {'revision': 0, 'journals': {}, 'otherPurchases': {'P1': purchase}}
    results, saved = race('other_supplier_settlements', initial, [payment, payment])
    assert sorted(x[0] for x in results) == [200, 422], results
    assert len(saved['journals']) == len(saved['otherSupplierPayments']) == 1
    results, saved = race('other_supplier_settlements', initial, [{**payment, 'amount': 40}, payment])
    assert [x[0] for x in results] == [200, 200], results
    assert len(saved['journals']) == len(saved['otherSupplierPayments']) == saved['revision'] == 2
    assert sum(p['amount'] for p in saved['otherSupplierPayments'].values()) == 100

    receipt = {'soda': 'S1', 'party': 'Fixture buyer', 'date': '2026-10-21', 'amount': 100, 'receiptType': 'CASH'}
    initial = {'revision': 0, 'journals': {'SALE': journal('SALE', '1220', 100)}}
    for kind in ['CASH', 'BANK']:
        results, saved = race('local_customer_receipts', initial,
            [{**receipt, 'amount': 80, 'soda': spelling, 'receiptType': kind, 'bankAccountId': 'B1'} for spelling in ['s1', 'S1']])
        assert [x[0] for x in results] == [200, 200], results
        posts = [j for j in saved['journals'].values() if j.get('sourceType') == 'LOCAL_CUSTOMER_RECEIPT']
        assert len(posts) == saved['revision'] == 2
        assert sum(l.get('credit', 0) for j in posts for l in j['lines'] if l['account'] == '1220') == 100
        assert sum(l.get('credit', 0) for j in posts for l in j['lines'] if l['account'] == '2510') == 60
    advance = journal('ADVANCE', '2510', 0, 'ADV')
    advance['lines'][0]['credit'] = 100
    initial['journals']['ADVANCE'] = advance
    initial['journals']['SALE2'] = journal('SALE2', '1220', 100, 'S2')
    adjustment = {**receipt, 'receiptType': 'ADJUSTMENT', 'sourceSoda': 'adv'}
    results, saved = race('local_customer_receipts', initial, [adjustment, {**adjustment, 'soda': 'S2'}])
    assert sorted(x[0] for x in results) == [200, 422], results
    assert saved['revision'] == 1
    # Cheques retain no-overpayment and duplicate-number controls, including
    # case aliases of the same saved Soda reference.
    books.write_text(json.dumps({'revision': 0, 'journals': {'SALE': journal('SALE', '1220', 100)}}))
    cheque = {**receipt, 'soda': 's1', 'receiptType': 'CHEQUE', 'bankAccountId': 'B1',
              'chequeNo': 'CHECK-1', 'chequeDate': '2026-10-21', 'amount': 80}
    assert request('local_customer_receipts', cheque)[0] == 200
    before = books.read_bytes()
    assert request('local_customer_receipts', {**cheque, 'soda': 'S1', 'chequeNo': 'CHECK-2'})[0] == 422
    assert request('local_customer_receipts', {**cheque, 'soda': 'S1', 'amount': 10})[0] == 409
    assert books.read_bytes() == before
    historical = journal('OLD-RECEIPT', '1220', 0, 's1')
    historical['lines'][0]['credit'] = 80
    books.write_text(json.dumps({'revision': 0, 'journals': {'SALE': journal('SALE', '1220', 100), 'OLD-RECEIPT': historical}}))
    status, result = request('local_customer_receipts', {**receipt, 'amount': 80})
    assert status == 200 and result['receivableApplied'] == 20 and result['customerAdvance'] == 60, result
    assert json.loads(books.read_text())['journals']['OLD-RECEIPT'] == historical
    # Every changed transaction rejects new nonfinite amounts and leaves an
    # existing nonfinite extension field untouched when serialization fails.
    for endpoint, state, body in [
        ('bag_supplier_payments', {'revision': 0, 'bagSupplierBills': {'A': bag}}, bag_body),
        ('other_supplier_settlements', {'revision': 0, 'otherPurchases': {'P1': purchase}}, payment),
        ('local_customer_receipts', initial, receipt),
    ]:
        books.write_text(json.dumps(state)); before = books.read_bytes()
        assert request(endpoint, {**body, 'amount': '1e309'})[0] == 422
        assert books.read_bytes() == before
        books.write_text(json.dumps(state)[:-1] + ',"legacyMetric":1e309}')
        before = books.read_bytes()
        assert request(endpoint, body)[0] == 500
        assert books.read_bytes() == before
    # Identical access/validation boundaries remain active.
    books.write_text(json.dumps(initial)); before = books.read_bytes()
    for body in [{**receipt, 'amount': '1e309'}, {**receipt, 'csrf': 'bad'}, {**receipt, 'entity': 'BRM'}]:
        status, _ = request('local_customer_receipts', body)
        assert status in [403, 419, 422] and books.read_bytes() == before, status
    # Use real authentication and the real universal sequence, rather than an
    # ID stub: an accounting review must not reserve IDs or change replay state.
    books.write_text(json.dumps({'revision':0,'journals':{}}))
    status, broker=request('company_investments',{'action':'master','kind':'broker','operation':'add','name':'Preview fixture broker','requestKey':'preview-fixture-broker','revision':0})
    assert status==200,broker
    funding={'action':'post','type':'FUND_DIRECT','brokerId':broker['result']['id'],'date':'2026-10-21','reference':'PREVIEW-ONLY','amount':100,'paymentAccountId':'B1','bankPaymentMethod':'ONLINE_BANKING','requestKey':'preview-funding-request','revision':broker['revision']}
    before=books.read_bytes();sequence=json.loads((private/'auth.json').read_text()).get('post_sequence');replay=private/'offline_transactions.json';replay_before=replay.read_bytes()
    transaction='PREVIEW-CONFIRM-TRANSACTION-001'
    status, preview=request('company_investments',{**funding,'preview':True},transaction=transaction)
    assert status==200 and len(preview['previews'])==1,preview
    assert books.read_bytes()==before and json.loads((private/'auth.json').read_text()).get('post_sequence')==sequence,'Preview changed financial books or reserved a universal Post ID'
    assert replay.read_bytes()==replay_before,'Preview changed the resource/retry acknowledgement state'
    status, actual=request('company_investments',funding,transaction=transaction);assert status==200 and len(json.loads(books.read_text())['journals'])==1,actual
    before=books.read_bytes();status, retry=request('company_investments',funding,transaction=transaction)
    assert status==200 and retry.get('result',{}).get('journalId')==actual['result']['journalId'] and books.read_bytes()==before,(status,retry,json.loads(replay.read_text())['transactions'].get('1|'+transaction))
    print('PASS actual-auth Accounts nonfinite/encoding byte preservation, positive saves, deterministic races, cheque/advance controls, zero-write preview/sequence/retry state and one confirmed posting')
