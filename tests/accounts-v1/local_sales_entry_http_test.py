"""Execute the real Accounts entry with isolated identities; no operational writes."""
import json, os, pathlib, shutil, subprocess, tempfile
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='local-entry-') as temp:
    root=pathlib.Path(temp);(root/'accounts').mkdir();(root/'milling').mkdir()
    for path in ['accounts/local-sales.php','module.php','runtime_html.php']:
        shutil.copy(ROOT/path,root/path)
    (root/'milling/Transtrade_Master_Milling_V3_3_2_AUDITED.html').write_text('fixture')
    (root/'auth_store.php').write_text('''<?php
    function tt_require_login(){return ['id'=>1,'full_name'=>'Fixture','role'=>getenv('ACTOR')];}
    function tt_user_can_open_module($u,$m){return $u['role']==='Both'||($u['role']==='Accounts'&&$m==='Accounts')||($u['role']==='Mill'&&$m==='Mill');}
    function tt_user_can_module_action($u,$m,$i,$a){return tt_user_can_open_module($u,$m);}
    function tt_user_can_access_entity($u,$e,$a){return $e==='TTI';}
    function tt_csrf(){return 'fixture';} function tt_release_read_session(){}
    register_shutdown_function(function(){file_put_contents(__DIR__.'/status.json',json_encode(http_response_code()?:200));});
    ''')
    (root/'run.php').write_text("<?php $_SERVER['REQUEST_METHOD']='GET';$_GET=json_decode(getenv('QUERY'),true);require __DIR__.getenv('ENTRY');")
    def run(actor,query,entry='/accounts/local-sales.php'):
        p=subprocess.run(['php',str(root/'run.php')],env={**os.environ,'ACTOR':actor,'QUERY':json.dumps(query),'ENTRY':entry},capture_output=True,text=True)
        assert p.returncode==0,p.stderr
        return json.loads((root/'status.json').read_text()),p.stdout
    status,body=run('Both',{'entity':'TTI'});assert status==200 and 'view=local-sales' in body and 'Shared Mill Local Sales' in body
    status,body=run('Accounts',{'entity':'TTI'});assert status==200 and 'id="millLocalFrame"' not in body and 'Accounts approvals' in body
    for role,query in [('None',{'entity':'TTI'}),('Mill',{'entity':'TTI'}),('Both',{'entity':'BRM'}),('Both',{'entity':'TG'})]:
        status,body=run(role,query);assert status==403,(role,query,status);assert 'Shared Mill Local Sales' not in body
    for role in ['Accounts','Mill','None']:
        status,body=run(role,{'id':'milling','view':'local-sales'},'/module.php');assert status==403,(role,status)
print('PASS actual Local Sales entry: authorized shared workflow, existing Mill rights, Accounts-only review fallback, company denials and zero operational writes')
