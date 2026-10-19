"""Real bank balance functions and GET controller against disposable books; no listener."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='bank-asof-') as directory:
    root = Path(directory)
    shutil.copytree(ROOT / 'api', root / 'api')
    (root / 'data').mkdir()
    (root / 'auth_store.php').write_text('''<?php
define('TT_DATA_DIR',__DIR__.'/data');
function tt_require_login(){return ['id'=>1,'role'=>'Super Admin'];}
function tt_user_can_open_module($u,$m){return true;}
function tt_release_read_session(){}
function tt_ensure_data_dir(){}
function tt_read_store(){return [];}
function tt_list_masters(){return ['banks'=>[['id'=>'B1','values'=>['Company Account','TTI','','Fixture','Fixture Bank','','Pakistan','PKR','123','','','','','Active']]]];}
''')
    runner = root / 'check.php'
    runner.write_text('''<?php
define('TT_BANK_FUNCTIONS_ONLY',true);require __DIR__.'/api/bank_accounts.php';require __DIR__.'/api/bank_reconciliation_core.php';
$s=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$before=serialize($s);$d='2026-10-18';
echo json_encode(['bank'=>ba_balance($s,'TTI','B1','PKR',$d),'next'=>ba_balance($s,'TTI','B1','PKR','2026-10-19'),'cash'=>ba_cash_balance($s,'TTI',$d),'unassigned'=>ba_unassigned_bank_balance($s,'TTI',$d),'usd'=>ba_balance($s,'TTI','USD1','USD',$d),'reconciled'=>br_txns($s,'TTI','B1',$d,'PKR')['bookBalance'],'unchanged'=>$before===serialize($s)],JSON_THROW_ON_ERROR);
''')
    def journal(date, account, debit=0, credit=0, bank='B1', **extra):
        return dict(date=date, entity='TTI', status='Posted', lines=[dict(account=account, debit=debit, credit=credit, bankAccountId=bank, **extra)])
    journals = {
        'OPEN': journal('2026-10-01', '1110', debit=1000),
        'PAY': journal('2026-10-18', '1110', credit=50),
        'FUTURE': journal('2026-10-19', '1110', credit=10727),
        'WRONG_YEAR': journal('20260-10-01', '1110', credit=300),
        'NO_DATE': journal('', '1110', credit=400),
        'CASH': journal('2026-10-18', '1120', debit=200, bank=''),
        'CASH_FUTURE': journal('2026-10-19', '1120', credit=80, bank=''),
        'UNASSIGNED': journal('2026-10-18', '1110', debit=30, bank=''),
        'UNASSIGNED_FUTURE': journal('2026-10-19', '1110', debit=60, bank=''),
        'USD': journal('2026-10-18', '1110', debit=28000, bank='USD1', bankDebit=100, bankCredit=0),
        'USD_FUTURE': journal('2026-10-19', '1110', credit=5600, bank='USD1', bankDebit=0, bankCredit=20),
        'FOREIGN': dict(journal('2026-10-18', '1110', debit=999), entity='BRM'),
        'DRAFT': dict(journal('2026-10-18', '1110', debit=888), status='Draft'),
    }
    store = dict(revision=23, journals=journals)
    env = {k: v for k, v in os.environ.items() if not k.startswith('TT_DB_')}
    result = subprocess.run(['php', str(runner)], input=json.dumps(store), text=True, capture_output=True, check=True, env=env)
    data = json.loads(result.stdout)
    assert data == dict(bank=950, next=-9777, cash=200, unassigned=30, usd=100, reconciled=950, unchanged=True), data
    # Execute the actual expense/salary balance helpers against identical dated books.
    # Extract declarations only, avoiding their production controller/auth side effects.
    declarations = []
    for name, functions in [('expenses_v1.php', ['ev1_balance']), ('rent_salary_v2.php', ['rsv2_bank_bal', 'rsv2_cash_bal'])]:
        lines = (ROOT / 'api' / name).read_text().splitlines()
        for function in functions:
            matches = [line for line in lines if line.startswith('function ' + function + '(')]
            assert len(matches) == 1 and matches[0].endswith('}'), function
            declarations.append(matches[0])
    picker = root / 'pickers.php'
    picker.write_text("<?php require __DIR__.'/api/accounts_bank_payment.php';\n" + '\n'.join(declarations) + "\n" + r'''$s=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$before=serialize($s);$d=$s['_asOf']??null;
echo json_encode(['expense'=>ev1_balance($s,'TTI','1110','B1',$d),'expenseCash'=>ev1_balance($s,'TTI','1120','',$d),'salary'=>rsv2_bank_bal($s,'TTI','B1',$d),'salaryCash'=>rsv2_cash_bal($s,'TTI',$d),'unchanged'=>$before===serialize($s)],JSON_THROW_ON_ERROR);
''')
    def check_pickers(as_of):
        result = subprocess.run(['php', str(picker)], input=json.dumps({**store, '_asOf': as_of}), text=True, capture_output=True, check=True, env=env)
        actual = json.loads(result.stdout)
        assert actual == dict(expense=950, expenseCash=200, salary=950, salaryCash=200, unchanged=True), actual
    check_pickers('2026-10-18')
    # Exercise today's actual GET, not a duplicated implementation of its balance rules.
    for row in journals.values():
        if row['date'] == '2026-10-18': row['date'] = '2000-01-01'
        elif row['date'] == '2026-10-19': row['date'] = '2099-12-31'
    check_pickers(None)  # Today's Karachi cutoff agrees with Home, not all future postings.
    books = root / 'data/accounts.json'
    books.write_text(json.dumps(store)); before = books.read_bytes()
    endpoint = root / 'get.php'
    endpoint.write_text("<?php $_SERVER['REQUEST_METHOD']='GET';$_GET=['entity'=>'TTI'];require __DIR__.'/api/bank_accounts.php';")
    result = subprocess.run(['php', str(endpoint)], text=True, capture_output=True, check=True, env=env)
    data = json.loads(result.stdout)
    assert data['ok'] and data['revision'] == 23 and data['accounts'][0]['bookBalance'] == 950, data
    assert data['cash']['bookBalance'] == 200 and data['unassignedBankBalance'] == 30, data
    assert len(data['balanceAsOfDate']) == 10 and books.read_bytes() == before
    print('PASS bank as-of balances: future/invalid dates excluded, due-date inclusion, company/status/native currency, cash/unassigned and expense/salary parity, actual GET and unchanged books')
