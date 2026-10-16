"""Real ledger/report routes share chart identities; disposable CLI fixture, no socket or live writes."""
import json
import os
import pathlib
import shutil
import subprocess
import tempfile

ROOT = pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='ledger-catalog-') as directory:
    root = pathlib.Path(directory)
    for folder in ['api', 'data', 'accounts']:
        (root / folder).mkdir()
    dependencies = ['supplier_opening_core.php', 'accounts_subaccounts_core.php',
        'accounts_ledger_visibility.php', 'assets_registry_core.php', 'service_ledger_groups.php',
        'accounts_ledger_browser.php', 'accounts_post_delete_core.php', 'accounts_reference.php',
        'tg_remittance_core.php', 'fi_credit_advice_link.php', 'receipt_invoice_links.php',
        'customer_receivables_core.php', 'accounts_reports.php']
    for name in dependencies:
        shutil.copy(ROOT / 'api' / name, root / 'api' / name)
    for path in (ROOT / 'accounts').glob('*.json'):
        shutil.copy(path, root / 'accounts' / path.name)
    (root / 'auth_store.php').write_text('''<?php
    define('TT_DATA_DIR',__DIR__.'/data');
    function tt_require_login(){return ['id'=>1,'role'=>'Super Admin'];}
    function tt_user_can_open_module($u,$m){return true;}
    function tt_user_can_access_entity($u,$e,$a){return $e==='TTI';}
    function tt_list_masters(){return [];}
    ''')
    books = root / 'data/accounts.json'
    books.write_text(json.dumps({'revision': 1, 'journals': {
        'POST-2026-00001': {'id': 'POST-2026-00001', 'entity': 'TTI', 'status': 'Posted',
            'date': '2026-10-01', 'sourceType': 'JV', 'lines': [
                {'account': '7200', 'debit': 100, 'credit': 0},
                {'account': '1120', 'debit': 0, 'credit': 100}]}}}))
    original = books.read_bytes()
    harness = root / 'request.php'
    harness.write_text('''<?php
    $_SERVER['REQUEST_METHOD']='GET';
    $_GET=['entity'=>'TTI','category'=>'other','account'=>'7200','from'=>'2026-07-01','to'=>'2026-10-16','asOf'=>'2026-10-16'];
    require __DIR__.'/api/'.$argv[1].'.php';
    ''')
    def request(route):
        # This is a file-backed fixture; never inherit the runner's MySQL target.
        request_env = {key: value for key, value in os.environ.items() if not key.startswith('TT_DB_')}
        result = subprocess.run(['php', str(harness), route], capture_output=True, text=True, env=request_env)
        assert result.returncode == 0, result.stderr or result.stdout
        assert not result.stderr, result.stderr
        data = json.loads(result.stdout)
        assert data['ok'], data
        assert books.read_bytes() == original, 'Report reads must preserve original journals'
        return data
    ledger = request('accounts_ledger_browser')
    report = request('accounts_reports')
    name = next(row['name'] for row in report['profitAndLoss']['expenses'] if str(row['account']) == '7200')
    assert next(row['name'] for row in ledger['accounts'] if row['code'] == '7200') == name == 'Donations'
    assert ledger['closing'] == 100
    assert next(row['amount'] for row in report['profitAndLoss']['expenses'] if str(row['account']) == '7200') == 100
    print('PASS ledger and P&L keep the same canonical account identity and original amounts')
