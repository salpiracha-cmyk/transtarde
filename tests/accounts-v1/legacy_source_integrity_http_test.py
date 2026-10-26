"""Exercise legacy cross-module writes against damaged disposable source books."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tti-source-integrity-') as directory:
    root = Path(directory)
    app = root / 'app'
    shutil.copytree(ROOT, app, ignore=shutil.ignore_patterns('.git', 'node_modules', 'tmp', '__pycache__'))
    private = root / 'transtrade_private'
    private.mkdir()
    books = private / 'accounts.json'
    (private / 'auth.json').write_text(json.dumps({'users': [{
        'id': 1, 'username': 'source-fixture', 'full_name': 'Source fixture',
        'role': 'Super Admin', 'active': True, 'session_version': 1, 'permissions': {}
    }], 'masters': {}, 'settings': {'qa_account_seeded': True}, 'audit': []}))
    (private / 'operations.json').write_text(json.dumps({'revision': 0, 'values': {}}))
    harness = root / 'request.php'
    harness.write_text(r'''<?php
$req=json_decode($argv[2],true);$_SERVER['REQUEST_URI']='/api/'.$req['endpoint'].'.php?entity='.$req['body']['entity'];$_SERVER['REQUEST_METHOD']=$req['method'];$_SERVER['REMOTE_ADDR']='192.0.2.91';$_GET=['entity'=>$req['body']['entity']];
session_name('TRANSTRADE_SESSION');session_id('source-fixture');session_start();$_SESSION=['user_id'=>1,'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];session_write_close();
$GLOBALS['fixtureBody']=json_encode($req['body']);
class SourceInput{public $context;private $offset=0;function stream_open($p,$m,$o,&$opened){return $p==='php://input';}function stream_read($n){$s=substr($GLOBALS['fixtureBody'],$this->offset,$n);$this->offset+=strlen($s);return $s;}function stream_eof(){return $this->offset>=strlen($GLOBALS['fixtureBody']);}function stream_stat(){return [];}}
stream_wrapper_unregister('php');stream_wrapper_register('php',SourceInput::class);
register_shutdown_function(static function()use($req){file_put_contents($req['statusFile'],(string)(http_response_code()?:200));});require $argv[1];
''')
    env = {k: v for k, v in os.environ.items() if not k.startswith('TT_DB_')}

    def request(endpoint, body, method='POST'):
        status_file = root / 'status'
        req = {'endpoint': endpoint, 'method': method, 'statusFile': str(status_file),
               'body': {'entity': 'TTI', 'csrf': 'fixture', **body}}
        p = subprocess.run(['php', '-d', f'session.save_path={root}', str(harness),
                            str(app / 'api' / (endpoint + '.php')), json.dumps(req)],
                           env=env, capture_output=True, text=True, timeout=20)
        assert p.returncode == 0, p.stdout + p.stderr
        return int(status_file.read_text()), json.loads(p.stdout)

    routes = [
        ('management_costing_attach', {}, 'POST'),
        ('management_costing', {'action': 'refresh_sheets'}, 'POST'),
        ('management_costing', {}, 'GET'),
        ('local_sales_entity_rule', {'targetEntity': 'TTI', 'soda': 'FIXTURE-SODA'}, 'POST'),
        ('local_sales_costing', {'action': 'post_waiting'}, 'POST'),
        ('local_sales_costing', {}, 'GET'),
        ('export_costing', {'action': 'post_waiting'}, 'POST'),
        ('export_costing', {}, 'GET'),
        ('payables_planning', {'action': 'set_hold', 'sourceKey': 'FIXTURE',
                               'active': True, 'reason': 'Fixture'}, 'POST'),
        ('payables_planning', {}, 'GET'),
        ('brokerage_master', {'action': 'save_wht_rate', 'whtPercent': 5, 'effectiveFrom': '2026-10-01'}, 'POST'),
        ('brokerage_master', {}, 'GET'),
        ('local_sales_control', {'action': 'queue_candidate', 'saleDate': '2026-10-01',
                                 'sourceKey': 'FIXTURE', 'product': 'Fixture', 'amount': 10}, 'POST'),
        ('local_sales_control', {'action': 'approve_candidate', 'candidateId': 'FIXTURE'}, 'POST', 404),
        ('local_sales_control', {}, 'GET'),
        ('local_sales_payments', {'action': 'queue_payment', 'paymentDate': '2026-10-01',
                                  'sourceKey': 'FIXTURE', 'soda': 'FIXTURE', 'party': 'Fixture',
                                  'paymentType': 'Cash', 'amount': 10}, 'POST'),
        ('local_sales_payments', {'action': 'approve_payment', 'paymentId': 'FIXTURE'}, 'POST', 404),
        ('local_sales_payments', {}, 'GET'),
        ('production_inventory_transfer', {'action': 'post_transfer', 'groupId': 'FIXTURE'}, 'POST', 404),
        ('production_inventory_transfer', {}, 'GET'),
        ('bank_direct_entries', {'date': '2026-10-01', 'bankId': 'FIXTURE', 'type': 'BANK_CHARGE', 'amount': 10}, 'POST', 404),
        ('brokerage_transactions', {'action': 'deposit_wht', 'date': '2026-10-01',
                                    'broker': 'Fixture', 'amount': 10, 'sourceType': 'CASH'}, 'POST', 422),
        ('brokerage_transactions', {}, 'GET'),
        ('other_purchases', {'action': 'post_purchase', 'date': '2026-10-01',
                             'type': 'OPERATING', 'subtype': 'OFFICE', 'supplier': 'Fixture',
                             'description': 'Fixture', 'amount': 10, 'location': 'OFFICE',
                             'settlement': 'CREDIT'}, 'POST'),
        ('other_purchases', {}, 'GET'),
        ('tg_liabilities', {'action': 'post_supplier_liability', 'entity': 'TG',
                            'date': '2026-10-01', 'counterparty': 'Fixture', 'reference': 'FIXTURE',
                            'currency': 'AED', 'nativeAmount': 10, 'payableAccount': '2130',
                            'debitAccount': '6100'}, 'POST'),
        ('tg_liabilities', {'entity': 'TG'}, 'GET'),
        ('tg_year_end_revaluation', {'entity': 'TG', 'action': 'post_revaluation', 'date': '2025-12-31'}, 'POST', 422),
        ('tg_year_end_revaluation', {'entity': 'TG'}, 'GET', 422),
        ('tg_bank_transactions', {'entity': 'TG', 'action': 'apply_advance', 'date': '2026-10-01',
                                  'amountNative': 10, 'receiptId': 'FIXTURE', 'candidateId': 'FIXTURE'}, 'POST', 422),
        ('tg_bank_transactions', {'entity': 'TG'}, 'GET'),
        ('export_bank_shortfall', {'action': 'post_shortfall', 'candidateId': 'FIXTURE',
                                   'linkedReceiptId': 'FIXTURE', 'foreignShortfall': 1,
                                   'confirmedBankCharge': True}, 'POST', 404),
        ('export_bank_shortfall', {}, 'GET'),
        ('export_tax_certificates', {'action': 'unmatch_deductions', 'financialYear': '2026-27', 'deductionKeys': []}, 'POST'),
        ('export_tax_certificates', {}, 'GET'),
        ('export_accounting', {'action': 'recognize_candidate', 'candidateId': 'FIXTURE',
                               'recognitionDate': '2026-10-01', 'functionalCurrency': 'PKR',
                               'functionalRate': 280}, 'POST', 404),
        ('export_accounting', {}, 'GET'),
        ('export_receipts', {'action': 'post_receipt', 'date': '2026-10-01', 'transactionCurrency': 'USD',
                             'foreignAmount': 10, 'realizationRate': 280, 'bankAccountId': 'FIXTURE',
                             'bankAdviceRef': 'FIXTURE'}, 'POST', 404),
        ('export_receipts', {}, 'GET'),
        ('bank_accounts', {'action': 'save_settings', 'accountId': 'CASH|TTI'}, 'POST'),
        ('bank_accounts', {}, 'GET'),
        ('tg_bank_transfer', {'entity': 'TG', 'action': 'post_transfer', 'date': '2026-10-01',
                              'sourceAmount': 10, 'sourceBankId': 'FIXTURE-A', 'destinationBankId': 'FIXTURE-B',
                              'notes': 'Fixture'}, 'POST', 422),
        ('tg_bank_transfer', {'entity': 'TG'}, 'GET', 422),
        ('retention_remittances', {'action': 'post_remittance', 'date': '2026-10-01', 'bankAccountId': 'FIXTURE',
                                   'payee': 'Fixture', 'purpose': 'Fixture', 'foreignAmount': 10,
                                   'transactionRate': 280, 'settlementType': 'EXPENSE',
                                   'bankPaymentMethod': 'ONLINE_BANKING'}, 'POST', 422),
        ('retention_remittances', {}, 'GET'),
        ('local_customer_receipts', {'action': 'clear_customer_cheque', 'date': '2026-10-01', 'chequeId': 'FIXTURE'}, 'POST', 404),
        ('local_customer_receipts', {}, 'GET'),
        ('purchase_sodas', {'action': 'set_status', 'id': 'FIXTURE', 'status': 'Cancelled', 'reason': 'Fixture'}, 'POST', 404),
        ('purchase_sodas', {}, 'GET'),
        ('commodity_bills', {'action': 'verify_bill', 'billDate': '2026-10-01', 'sourceKeys': ['FIXTURE'],
                             'finalCommodityValue': 10, 'relationshipType': 'SUPPLIER',
                             'relationshipName': 'Fixture'}, 'POST', 422),
        ('commodity_bills', {}, 'GET'),
    ]
    failures = []
    invalid = [None, b'', b' ', b'{"revision":7,"journals":', b'null', b'[]',
               b'[1,2]', b'{"revision":7,"journals":null}',
               b'{"revision":7,"journals":"damaged"}', b'{"revision":7,"legacy":1e309}']
    for endpoint, body, method, *expected in routes:
        for raw in invalid:
            if books.exists():
                books.unlink()
            if raw is not None:
                books.write_bytes(raw)
            status, result = request(endpoint, body, method)
            after = books.read_bytes() if books.exists() else None
            if status != 500 or after != raw:
                failures.append((endpoint, method, repr(raw), status, after != raw))
        # A valid store retains journals, foreign-company collections and nested unknown data.
        source = {'revision': 7, 'journals': {'KEEP': {'id': 'KEEP', 'entity': 'BRM',
                  'status': 'Posted', 'date': '2026-10-01', 'lines': []}},
                  'untouched': {'nested': ['keep', 0]}, 'localSalesCandidates': {}}
        books.write_text(json.dumps(source))
        before = books.read_bytes()
        status, result = request(endpoint, body, method)
        expected_status = expected[0] if expected else 200
        assert status == expected_status, (endpoint, status, result)
        saved = json.loads(books.read_text())
        assert saved['journals']['KEEP'] == source['journals']['KEEP'] and saved['untouched'] == source['untouched']
        no_write = method == 'GET' or expected_status != 200
        assert saved['revision'] == (7 if no_write else 8)
        if no_write:
            assert books.read_bytes() == before
    for failure in failures:
        print('FAILED source preservation:', failure, flush=True)
    assert not failures, f'{len(failures)} damaged/missing-source cases failed'
    print(f'PASS: {len(routes) * len(invalid)} damaged/missing source cases and {len(routes)} valid source flows; actual auth, original bytes preserved')
