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
    shutil.copy(ROOT/'document_filenames.php',root/'document_filenames.php')
    for folder in ['api', 'data', 'accounts']:
        (root / folder).mkdir()
    dependencies = ['supplier_opening_core.php', 'accounts_subaccounts_core.php','accounts_receipts_core.php',
        'accounts_ledger_visibility.php', 'assets_registry_core.php', 'service_ledger_groups.php',
        'accounts_ledger_browser.php','accounts_read_policy.php', 'accounts_post_delete_core.php','accounts_post_delete_tg.php','tg_native_currency.php', 'accounts_reference.php',
        'tg_remittance_core.php', 'fi_credit_advice_link.php', 'receipt_invoice_links.php',
        'customer_receivables_core.php', 'accounts_reports.php', 'tg_native_currency.php']
    for name in dependencies:
        shutil.copy(ROOT / 'api' / name, root / 'api' / name)
    for path in (ROOT / 'accounts').glob('*.json'):
        shutil.copy(path, root / 'accounts' / path.name)
    (root / 'auth_store.php').write_text('''<?php
    define('TT_DATA_DIR',__DIR__.'/data');
    function tt_require_login(){return ['id'=>1,'role'=>'Super Admin'];}
    function tt_user_can_open_module($u,$m){return true;}
    function tt_user_can_access_entity($u,$e,$a){return $e==='TTI';}
    function tt_list_masters(){return ['banks'=>[
      ['id'=>'M1','values'=>['Company Account','Transtrade International','','Current account','Meezan Bank','','','PKR','001122']],
      ['id'=>'M2','values'=>['Company Account','TTI','','Savings account','Meezan Bank','','','PKR','003344']],
      ['id'=>'ZERO','values'=>['Company Account','TTI','','Dollar account','Meezan Bank','','','USD','005566']],
      ['id'=>'PRIVATE','values'=>['Company Account','BRM','','Other company','Meezan Bank','','','PKR','778899']]
    ]];}
    ''')
    # Exercise the actual production permission helper with this disposable identity.
    import re
    permission_helper=re.search(r'function tt_user_can_module_action\([^\n]*\n.*?\n}',(ROOT/'auth_store.php').read_text(),re.S).group(0)
    with (root/'auth_store.php').open('a') as auth_fixture: auth_fixture.write('\n'+permission_helper+'\n')
    books = root / 'data/accounts.json'
    books.write_text(json.dumps({'revision': 1, 'journals': {
        'POST-2026-00001': {'id': 'POST-2026-00001', 'entity': 'TTI', 'status': 'Posted',
            'date': '2026-10-01', 'sourceType': 'JV', 'lines': [
                {'account': '7200', 'debit': 100, 'credit': 0},
                {'account': '1120', 'debit': 0, 'credit': 100}]}}}))
    seeded=json.loads(books.read_text())
    for number, date, entries in [
        (2,'2026-06-30',[('M1',50,0),('',0,50)]),
        (3,'2026-10-01',[('M1',1000,0),('',0,1000)]),
        (4,'2026-10-02',[('M2',300,0),('',0,300)]),
        (5,'2026-10-03',[('M1',0,100),('M2',100,0)])]:
        key=f'POST-2026-{number:05d}';seeded['journals'][key]={'id':key,'entity':'TTI','status':'Posted','date':date,'sourceType':'JV','lines':[{'account':'1110' if bank else '1120','bankAccountId':bank,'debit':debit,'credit':credit} for bank,debit,credit in entries]}
    books.write_text(json.dumps(seeded))
    original = books.read_bytes()
    harness = root / 'request.php'
    harness.write_text('''<?php
    $_SERVER['REQUEST_METHOD']='GET';
    $_GET=['entity'=>'TTI','category'=>'other','account'=>'7200','from'=>'2026-07-01','to'=>'2026-10-16','asOf'=>'2026-10-16'];
    $_GET=array_replace($_GET,json_decode($argv[2]??'{}',true));
    require __DIR__.'/api/'.$argv[1].'.php';
    ''')
    def request(route, **query):
        # This is a file-backed fixture; never inherit the runner's MySQL target.
        request_env = {key: value for key, value in os.environ.items() if not key.startswith('TT_DB_')}
        result = subprocess.run(['php', str(harness), route, json.dumps(query)], capture_output=True, text=True, env=request_env)
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
    names=request('accounts_ledger_browser',category='party',account='')
    available={r['code']:r['name'] for r in names['accounts'] if r['code'].startswith('BANK|')}
    assert set(available)=={'BANK|M1','BANK|M2','BANK|ZERO'} and '001122' in available['BANK|M1'] and '003344' in available['BANK|M2']
    first=request('accounts_ledger_browser',category='bank',account='BANK|M1');second=request('accounts_ledger_browser',category='bank',account='BANK|M2')
    assert first['opening']==50 and first['closing']==950 and len(first['rows'])==2
    assert second['opening']==0 and second['closing']==400 and len(second['rows'])==2
    assert {r['account'] for r in first['rows']}=={'BANK|M1'} and {r['account'] for r in second['rows']}=={'BANK|M2'}
    zero=request('accounts_ledger_browser',category='bank',account='BANK|ZERO');assert zero['rows']==[] and zero['currency']=='USD' and zero['closing']==0
    assert books.read_bytes()==original,'Bank search and individual ledger reads changed journals'
    print('PASS ledger and P&L keep the same canonical account identity, separate same-bank accounts/openings/transfers, company-scoped unused banks, native currency and byte-unchanged journals')
