"""Execute the real rate endpoint with disposable identity/input/books and actual action helpers."""
import json, os, shutil, subprocess, tempfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
auth=(ROOT/'auth_store.php').read_text();a=auth.index('function tt_user_can_open_module(');b=auth.index('function tt_user_can_access_masters(',a);helpers=auth[a:b]
with tempfile.TemporaryDirectory(prefix='brokerage-rights-') as directory:
    root=Path(directory);(root/'api').mkdir();(root/'data').mkdir();shutil.copy(ROOT/'api/brokerage_master.php',root/'api/brokerage_master.php')
    store=root/'data/accounts.json';store.write_text(json.dumps({'revision':0,'brokerageMaster':{'productRates':{},'whtRates':{}}}))
    (root/'auth_store.php').write_text('''<?php
    define('TT_DATA_DIR',__DIR__.'/data');
    function tt_require_login(){return json_decode(file_get_contents(__DIR__.'/user.json'),true);}
    function tt_accounts_input(){return file_get_contents(__DIR__.'/input.json');}
    function tt_verify_csrf($value){return $value==='fixture';}
    function tt_ensure_data_dir(){}
    '''+helpers)
    (root/'request.php').write_text("<?php $_SERVER['REQUEST_METHOD']=$argv[1];register_shutdown_function(function(){file_put_contents(__DIR__.'/status.json',json_encode(http_response_code()));});require __DIR__.'/api/brokerage_master.php';")
    base={'action':'save_product_rate','csrf':'fixture','product':'Fixture rice','ratePer100Kg':100,'effectiveFrom':'2026-10-01','active':True}
    def request(permission,body=base,status=200,method='POST',owner=False):
        (root/'user.json').write_text(json.dumps({'id':1,'role':'Super Admin' if owner else 'Director','username':'fixture','permissions':permission}))
        (root/'input.json').write_text(json.dumps(body))
        before=store.read_bytes();r=subprocess.run(['php',str(root/'request.php'),method],capture_output=True,text=True)
        assert r.returncode==0,r.stderr or r.stdout
        result=json.loads(r.stdout);assert json.loads((root/'status.json').read_text())==status,(permission,result)
        if status!=200 or method=='GET':assert store.read_bytes()==before,'Denied actions and reads cannot alter rate data'
        return result
    request({},owner=True)
    for permissions in [{'Directors':{'reports':['View']}},{'Accounts':{'reports':['View','Edit']}},{'Directors':{'brokerage':['View']}}]:
        request(permissions,{**base,'ratePer100Kg':999},403)
    create={'Directors':{'brokerage':['View','Create']}};edit={'Accounts':{'brokerage':['View','Edit']}}
    request(create,{**base,'ratePer100Kg':999},403)
    request(create,{**base,'effectiveFrom':'2026-10-02'})
    request(edit,{**base,'ratePer100Kg':110})
    request(edit,{**base,'effectiveFrom':'2026-10-03'},403)
    request({'Directors':'all'},{**base,'effectiveFrom':'2026-10-03'})
    request({'Accounts':['View','Edit']},{**base,'effectiveFrom':'2026-10-04'})
    data=request({'Directors':{'brokerage':['View']}},method='GET');assert not data['canCreate'] and not data['canEdit']
    data=request(edit,method='GET');assert data['canEdit'] and not data['canCreate']
    wht={'action':'save_wht_rate','csrf':'fixture','whtPercent':12,'effectiveFrom':'2026-10-01','active':True}
    request(create,wht);request(create,{**wht,'whtPercent':99},403);request(edit,{**wht,'whtPercent':10})
    request({'Directors':{'reports':['View']}},{**wht,'whtPercent':99},403)
    request({}, {**base,'effectiveFrom':'2026-02-30'},422,owner=True)
    request({}, {**base,'effectiveFrom':'2026-13-01'},422,owner=True)
    request({}, {**wht,'whtPercent':'not a rate'},422,owner=True)
    request({}, {**base,'ratePer100Kg':'1e309'},422,owner=True)
    final=json.loads(store.read_text());assert len(final['brokerageMaster']['productRates'])==4
    assert next(iter(final['brokerageMaster']['whtRates'].values()))['whtPercent']==10
print('PASS Brokerage exact-screen/action scopes, reports-only denials, create-versus-edit enforcement, legacy grants, GET capabilities and unchanged denied records')
