"""Exercise the actual bank settings endpoint against disposable master/auth fixtures."""
import json, pathlib, shutil, socket, subprocess, tempfile, time, urllib.request, urllib.error
ROOT = pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='bank-defaults-qa-') as temp:
    root = pathlib.Path(temp)
    (root/'api').mkdir(); (root/'data').mkdir()
    for name in ['bank_accounts.php','tg_remittance_core.php']:shutil.copy(ROOT/'api'/name,root/'api'/name)
    def bank(id, company='TTI', currency='PKR', number='12345', status='Active'):
        return {'id':id,'values':['Company Account',company,'',company,'Fixture Bank','','',currency,number,'','','','',''+status]}
    masters = {'banks':[bank('a'),bank('b'),bank('usd',currency='USD'),bank('brm',company='BRM'),bank('inactive',status='Inactive'),bank('incomplete',number='')]}
    (root/'masters.json').write_text(json.dumps(masters))
    journals=[{'id':'EXISTING','status':'Posted','entity':'TTI','lines':[{'account':'1110','credit':10,'bankAccountId':'a'}]}]
    settings={id:{'defaultPaymentAccount':True,'defaultReceiptAccount':id=='a'} for id in ['a','usd','brm','inactive','incomplete','CASH|TTI']}
    (root/'data/accounts.json').write_text(json.dumps({'revision':1,'journals':journals,'bankAccountSettings':settings}))
    (root/'auth_store.php').write_text('''<?php
    define('TT_DATA_DIR',__DIR__.'/data');
    function tt_ensure_data_dir(){}
    function tt_list_masters(){return json_decode(file_get_contents(__DIR__.'/masters.json'),true);}
    function tt_read_store(){return [];}
    function tt_require_login(){return ['role'=>'Super Admin','id'=>1,'username'=>'Fixture'];}
    function tt_user_can_open_module($user,$module){return true;}
    function tt_verify_csrf($csrf){return $csrf==='fixture';}
    ''')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
    server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    def request(payload=None):
        req=urllib.request.Request(f'http://127.0.0.1:{port}/api/bank_accounts.php?entity=TTI',data=json.dumps(payload).encode() if payload else None,headers={'Content-Type':'application/json'})
        try:
            with urllib.request.urlopen(req,timeout=3) as res:return res.status,json.load(res)
        except urllib.error.HTTPError as err:return err.code,json.load(err)
    def save(id,**extra):
        return request({'action':'save_settings','entity':'TTI','accountId':id,'csrf':'fixture',**extra})
    try:
        for attempt in range(50):
            try:status,data=request();break
            except urllib.error.URLError:time.sleep(.1)
        else:raise AssertionError('PHP fixture did not start')
        accounts={row['id']:row for row in data['accounts']}
        for id in ['inactive','incomplete']:
            assert not accounts[id]['settings']['defaultPaymentAccount']
        assert not data['cash']['settings']['defaultPaymentAccount']
        assert save('CASH|TTI',defaultPaymentAccount=True)[0]==422
        assert save('inactive',defaultPaymentAccount=True)[0]==422
        assert save('incomplete',defaultPaymentAccount=True)[0]==422
        status,data=save('b',defaultPaymentAccount=True)
        assert status==200, data
        persisted=json.loads((root/'data/accounts.json').read_text())
        flags=persisted['bankAccountSettings']
        assert flags['b']['defaultPaymentAccount'] and not flags['a']['defaultPaymentAccount']
        assert flags['a']['defaultReceiptAccount'], 'Payment default cannot clear receipt default'
        assert flags['usd']['defaultPaymentAccount'] and flags['brm']['defaultPaymentAccount'], 'Scope must remain company and currency'
        assert persisted['journals']==journals, 'Settings must not alter postings'
        assert save('b',defaultPaymentAccount=False)[0]==200
        assert not json.loads((root/'data/accounts.json').read_text())['bankAccountSettings']['b']['defaultPaymentAccount']
        print('Bank default persistence, uniqueness, receipt independence, eligibility and unchanged journals passed')
    finally:
        server.terminate();server.wait(timeout=5)
