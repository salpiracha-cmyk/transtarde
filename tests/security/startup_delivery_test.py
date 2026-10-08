"""Real PHP startup responses, using disposable authentication and master data."""
import json, pathlib, shutil, subprocess, tempfile

ROOT = pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tti-startup-') as directory:
    root = pathlib.Path(directory)
    app = root / 'app'
    (app / 'accounts').mkdir(parents=True)
    (app / 'api').mkdir()
    private = root / 'transtrade_private'
    private.mkdir()
    for name in ['auth_store.php','master_store.php','product_stage.php','offline_idempotency.php','session_store.php','inventory_reconciliation.php','runtime_html.php']:
        shutil.copy(ROOT / name, app / name)
    for name in ['index.php','Transtrade_Accounts_Master_V1.html','accounting_master_v1.json','export_realization_policy_v1.json','settlement_policy_v1.json']:
        shutil.copy(ROOT / 'accounts' / name, app / 'accounts' / name)
    shutil.copy(ROOT / 'api/export_realization_master.php', app / 'api/export_realization_master.php')
    user = {'id':1,'username':'fixture','full_name':'Fixture','role':'Super Admin','active':True,'session_version':1,'permissions':{'Accounts':'all'}}
    masters = {key:[{'id':key+'-fixture','values':['UNUSED_STARTUP_PAYLOAD_'+'x'*400000]}] for key in ['export_documents','export_terms','export_realization_charges']}
    auth = private / 'auth.json'
    auth.write_text(json.dumps({'users':[user],'masters':masters,'audit':[]}))
    harness = root / 'request.php'
    harness.write_text('''<?php
$_SERVER['REQUEST_METHOD']='GET';$_SERVER['REQUEST_URI']=$argv[2];
session_name('TRANSTRADE_SESSION');session_id('startup-fixture');session_start();
$_SESSION=['user_id'=>1,'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];session_write_close();
if($argv[3]==='login'){
 require $argv[1].'/auth_store.php';
 tt_record_sign_in(1,'fixture');
 $store=tt_read_store();echo json_encode(['writes'=>$GLOBALS['ttAuthStoreGeneration'],'user'=>$store['users'][0],'audit'=>$store['audit']]);exit;
}
if($argv[3]==='bootstrap')$_GET=['bootstrap'=>'1'];
require $argv[1].$argv[2];
''')
    def run(path, mode):
        result = subprocess.run(['php','-d',f'session.save_path={root}',str(harness),str(app),path,mode],capture_output=True,text=True)
        assert result.returncode == 0, result.stdout + result.stderr
        return result.stdout
    login = json.loads(run('/login.php','login'))
    assert login['writes'] == 1, 'Sign-in must commit timestamp and audit together'
    assert login['user']['last_login_at'] and login['audit'][0]['action'] == 'Signed in'
    assert len(login['audit']) == 1 and login['audit'][0]['user_id'] == 1
    html = run('/accounts/index.php','page')
    assert 'UNUSED_STARTUP_PAYLOAD_' not in html, 'Unused export masters leaked into Accounts startup'
    assert len(html.encode()) < 150000, 'Accounts startup contains unnecessary document payloads'
    assert 'TT_ACCOUNT_ACCESS' in html and 'Transtrade International' in html
    assert 'UNUSED_STARTUP_PAYLOAD_' in auth.read_text(), 'Delivery optimization changed canonical records'
    compact = json.loads(run('/api/export_realization_master.php','bootstrap'))
    assert compact == {'ok':True}, 'Seed-only bootstrap must not transmit charge history'
    saved = json.loads(auth.read_text())
    assert any(len(r['values'])>1 and r['values'][1]=='EXP-BANK-COMM' for r in saved['masters']['export_realization_charges']), 'Compact bootstrap must retain canonical default seeding'
print('PASS atomic sign-in audit/timestamp, small Accounts bootstrap, preserved masters and compact canonical charge seeding')
