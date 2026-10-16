"""Real owner-only read endpoint; isolated auth/audit store, no network."""
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
ROOT=Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='admin-audit-') as directory:
    root=Path(directory);(root/'api').mkdir()
    shutil.copy(ROOT/'api/admin_audit.php',root/'api/admin_audit.php')
    store=root/'store.json'
    store.write_text(json.dumps({'users':[{'password_hash':'DO-NOT-RETURN','username':'owner'}], 'masters':{'secret':'DO-NOT-RETURN'},'audit':[
        {'created_at':'2026-10-16T19:00:00Z','username':'actor','action':'Updated master POST-2026-00042','ip_address':'PRIVATE-ADDRESS'},
        {'created_at':'2026-10-16T18:00:00Z','username':'reviewer','action':'Changed user permissions','private':'DO-NOT-RETURN'}]}))
    original=store.read_bytes()
    (root/'auth_store.php').write_text('''<?php
    function tt_require_login(){return ['role'=>getenv('FIXTURE_ROLE')];}
    function tt_release_read_session(){}
    function tt_read_store(){return json_decode(file_get_contents(__DIR__.'/store.json'),true);}
    ''')
    (root/'request.php').write_text("<?php $_SERVER['REQUEST_METHOD']=$argv[1];register_shutdown_function(function(){file_put_contents(__DIR__.'/status.json',json_encode(http_response_code()));});require __DIR__.'/api/admin_audit.php';")
    import os
    for role,method,status in [('Super Admin','GET',200),('Accounts','GET',403),('Director','GET',403),('Mill Staff','GET',403),('Super Admin','POST',405)]:
        result=subprocess.run(['php',str(root/'request.php'),method],env={**os.environ,'FIXTURE_ROLE':role},capture_output=True,text=True)
        assert result.returncode==0,result.stderr
        data=json.loads(result.stdout)
        assert json.loads((root/'status.json').read_text())==status,(role,method,data)
        assert store.read_bytes()==original,'Audit reads must not write or create audit events'
        assert 'DO-NOT-RETURN' not in result.stdout and 'PRIVATE-ADDRESS' not in result.stdout
        if status==200:
            assert data['retainedCount']==2 and data['entries'][0]['ref']=='POST-2026-00042'
            assert data['entries'][0]['area']=='Master' and data['entries'][1]['area']=='Permission'
        else:assert not data['ok'] and 'entries' not in data
print('PASS owner-only audit GET, denied roles/mutations, whitelisted fields and zero source writes')
