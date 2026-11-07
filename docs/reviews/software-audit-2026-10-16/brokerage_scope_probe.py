"""Reproduce the brokerage scope finding against a disposable endpoint copy.
This is diagnostic evidence of a defect, not a passing permission acceptance test.
No network, live accounts, sessions, or financial records are used.
"""
import json
from pathlib import Path
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[3]
auth = (ROOT / 'auth_store.php').read_text()
start = auth.index('function tt_user_can_open_module(')
end = auth.index('/** Preserve legacy module grants', start)
open_module = auth[start:end]

for label, permissions, expected in [
    ('Director with reports View only', {'Directors': {'reports': ['View']}}, True),
    ('Accounts with reports View/Edit only', {'Accounts': {'reports': ['View', 'Edit']}}, True),
    ('User without any module access', {}, False),
]:
    with tempfile.TemporaryDirectory(prefix='brokerage-scope-') as directory:
        root = Path(directory)
        (root / 'api').mkdir()
        (root / 'data').mkdir()
        shutil.copy(ROOT / 'api/brokerage_master.php', root / 'api/brokerage_master.php')
        (root / 'fixture-user.json').write_text(json.dumps({'id': 42, 'role': 'Director', 'permissions': permissions}))
        (root / 'input.json').write_text(json.dumps({'action': 'save_wht_rate', 'csrf': 'fixture', 'whtPercent': 12, 'effectiveFrom': '2026-10-01'}))
        (root / 'auth_store.php').write_text('''<?php
        define('TT_DATA_DIR',__DIR__.'/data');
        function tt_require_login(){return json_decode(file_get_contents(__DIR__.'/fixture-user.json'),true);}
        function tt_accounts_input(){return file_get_contents(__DIR__.'/input.json');}
        function tt_verify_csrf($value){return $value==='fixture';}
        function tt_ensure_data_dir(){}
        ''' + open_module)
        (root / 'request.php').write_text("<?php $_SERVER['REQUEST_METHOD']='POST';require __DIR__.'/api/brokerage_master.php';")
        result = subprocess.run(['php', str(root / 'request.php')], capture_output=True, text=True)
        assert result.returncode == 0, result.stderr or result.stdout
        response = json.loads(result.stdout)
        assert bool(response['ok']) == expected, response
        stored = root / 'data/accounts.json'
        if expected:
            data = json.loads(stored.read_text())
            assert data['revision'] == 1 and len(data['brokerageMaster']['whtRates']) == 1
            print('DEFECT REPRODUCED:', label, 'can persist a brokerage WHT rate')
        else:
            assert not stored.exists()
            print('CONTROL PASSED:', label, 'cannot write')
