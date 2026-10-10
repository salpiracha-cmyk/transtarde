"""Actual FI tag, credit-advice, search and ledger endpoints; disposable data only."""
import json, os, pathlib, shutil, socket, subprocess, tempfile, time, urllib.request, urllib.error
ROOT = pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='fi-advice-qa-') as temp:
    root = pathlib.Path(temp)
    for folder in ['api', 'data', 'accounts']:
        (root/folder).mkdir()
    for name in ['accounts_subaccounts_core.php','assets_registry_core.php','tg_remittance_core.php', 'receipt_invoice_links.php', 'fi_credit_advice_link.php', 'fi_credit_advice.php', 'fi_credit_advice_link.php', 'accounts_ledger_browser.php','customer_receivables_core.php','accounts_post_delete_core.php', 'accounts_reference.php', 'accounts_search.php', 'export_receipts.php', 'export_receipt_tg_mirror.php', 'accounts_receipt_amend_core.php']:
        shutil.copy(ROOT/'api'/name, root/'api'/name)
    for path in (ROOT/'accounts').glob('*.json'):
        shutil.copy(path, root/'accounts'/path.name)
    (root/'auth_store.php').write_text('''<?php
    define('TT_DATA_DIR',__DIR__.'/data');
    function tt_require_login(){return ['role'=>'Super Admin','id'=>1,'username'=>'Fixture'];}
    function tt_user_can_open_module($u,$m){return ($_GET['denied']??'')!=='1'&&!($m==='Accounts'&&($_GET['exports_only']??'')==='1');}
    function tt_user_can_access_entity($u,$e,$a){return $e==='TTI';}
    function tt_user_accounts_entities($u){return ['TTI'];}
    function tt_list_masters(){return [];}
    function tt_ensure_data_dir(){}
    function tt_verify_csrf($v){return $v==='fixture';}
    function tt_next_post_id(array $existing, string $module='Accounts', string $area='Journal', ?string $date=null): string {
        $year=substr($date ?: date('Y-m-d'),0,4);$n=count($existing)+1;
        do {$id='POST-'.$year.'-'.str_pad((string)$n,5,'0',STR_PAD_LEFT);$n++;} while (isset($existing[$id]));
        return $id;
    }
    ''')
    fi = {'id':'F1','number':'FI-QA-1','exporter':'TTI','customer':'TRANS GRAINS FOODSTUFF TRADING L.L.C','date':'2026-10-01','currency':'USD','value':100000,'allocations':[{'amount':40000}]}
    receipt = {'id':'ER1','entity':'TTI','remitter':'TG','date':'2026-10-03','transactionCurrency':'USD','foreignAmount':99990,'bankAdviceRef':'QA-ADVICE','journalId':'J1','status':'Accounts Approved / Posted','allocations':[]}
    journal = {'id':'J1','entity':'TTI','status':'Posted','date':'2026-10-03','sourceType':'EXPORT_BANK_RECEIPT','reference':'QA-ADVICE','meta':{'receiptId':'ER1'},'narration':'RECEIPT ORIGINAL','totalDebit':100,'totalCredit':100,'lines':[{'account':'1110','accountName':'BANK','debit':100,'credit':0},{'account':'2510','accountName':'TG ADVANCE','debit':0,'credit':100}]}
    store={'revision':2,'exportReceipts':{'ER1':receipt},'journals':{'J1':journal}}
    books=root/'data/accounts.json';books.write_text(json.dumps(store));original=books.read_bytes()
    ops=root/'data/operations.json'
    def save_fis(rows): ops.write_text(json.dumps({'values':{'transtrade_export_v3_operational':json.dumps({'fi':rows})}}))
    save_fis([fi])
    with socket.socket() as sock:
        sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    env=os.environ.copy()
    for key in ['TT_DB_HOST','TT_DB_NAME','TT_DB_USER','TT_DB_PASS']: env.pop(key,None)
    server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root)],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    def get(path, data=None):
        req=urllib.request.Request(f'http://127.0.0.1:{port}/api/{path}',data=data,headers={'Content-Type':'application/json'})
        try:
            with urllib.request.urlopen(req,timeout=5) as res:return res.status,json.load(res)
        except urllib.error.HTTPError as err:return err.code,json.load(err)
    try:
        for attempt in range(50):
            try: status,result=get('fi_credit_advice.php');break
            except urllib.error.URLError: time.sleep(.1)
        else: raise AssertionError('PHP fixture unavailable')
        assert status==200 and result['links']['F1']['bankAdviceRef']=='QA-ADVICE',result
        assert get('fi_credit_advice.php?exports_only=1')[1]['links']['F1']['status']=='Matched','Jazib needs no Accounts module access'
        assert get('fi_credit_advice.php?denied=1')[0]==403
        assert get('fi_credit_advice.php',b'{}')[0]==405
        status,result=get('export_receipts.php?entity=TTI')
        assert status==200 and result['receipts'][0]['fiTag']['fiNumber']=='FI-QA-1',result
        status,result=get('accounts_ledger_browser.php?entity=TTI&account=POSTS&from=2026-01-01&to=2026-10-04')
        assert status==200 and 'FI-QA-1' in result['rows'][0]['narration'],result
        status,result=get('accounts_ledger_browser.php?entity=TTI&account=POSTS&postId=J1&to=2026-10-04')
        assert status==200 and 'FI-QA-1' in result['post']['fiTagText'],result
        assert result['post']['narration']=='RECEIPT ORIGINAL'
        status,result=get('accounts_search.php?entity=TTI&q=FI-QA-1')
        assert status==200 and result['results'],result
        assert any(row['data'].get('fiTag',{}).get('fiNumber')=='FI-QA-1' for row in result['results'])
        save_fis([fi,dict(fi,id='F2',number='FI-QA-2')])
        assert get('fi_credit_advice.php')[1]['links']['F1']['status']=='Ambiguous'
        assert 'fiTag' not in get('export_receipts.php?entity=TTI')[1]['receipts'][0]
        save_fis([dict(fi,exporter='BRM')])
        assert get('fi_credit_advice.php')[1]['links']=={},'Entity-restricted Exports user sees no BRM tags'
        assert books.read_bytes()==original,'Read-only tagging must not rewrite Accounts, journal amounts or revisions'
        print('Automatic FI tags: actual Exports, credit advice, ledger, voucher and search endpoints passed')
    finally:
        server.terminate();server.wait(timeout=5)

