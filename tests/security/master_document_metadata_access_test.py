"""Exercise both public Master serializers with real auth and disposable records."""
import json, os, pathlib, shutil, subprocess, tempfile
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='master-document-boundary-') as temp:
    root=pathlib.Path(temp);app=root/'app';shutil.copytree(ROOT,app,ignore=shutil.ignore_patterns('.git','node_modules','__pycache__'));private=root/'transtrade_private';private.mkdir()
    values=['Fixture Company','BRM','Pakistan','Pakistan']+['']*15
    values[13]='[]';values[14]=json.dumps([{'id':'DOC','label':'Confidential letterhead','storageName':'private.pdf','type':'Letterhead','status':'Active'}])
    store={'users':[],'masters':{'companies':[{'id':'COMPANY','values':values}]},'audit':[],'settings':{'qa_account_seeded':True,'master_permission_schema':2}}
    (private/'auth.json').write_text(json.dumps(store));before=(private/'auth.json').read_bytes()
    harness=root/'projection.php';harness.write_text('''<?php
require $argv[1].'/auth_store.php';require $argv[1].'/api/operations_scope.php';
$user=json_decode($argv[2],true);echo json_encode([tt_user_visible_masters($user),os_bootstrap_masters($user)]);
''')
    base={'role':'Accounts','master_access':True,'master_permissions':{'companies':['View']},'permissions':{'Accounts':{'entity-tti':['View'],'entity-brm':[]}}}
    def project(user):
        r=subprocess.run(['php',str(harness),str(app),json.dumps(user)],capture_output=True,text=True);assert r.returncode==0,r.stderr;return json.loads(r.stdout)
    for actions in [['View'],['View','View Documents'],['Use']]:
        user=json.loads(json.dumps(base));user['master_permissions']['companies']=actions
        snapshots=project(user)
        for snapshot in snapshots:
            for row in (snapshot.get('companies',[]) if isinstance(snapshot,dict) else []):assert json.loads(row['values'][14])==[],snapshot
        assert 'private.pdf' not in json.dumps(snapshots) and 'Confidential' not in json.dumps(snapshots)
    authorized=json.loads(json.dumps(base));authorized['master_permissions']['companies']=['View','View Documents'];authorized['permissions']['Accounts']['entity-brm']=['View']
    for user in [authorized,{'role':'Director'},{'role':'Super Admin'}]:
        snapshots=project(user);assert 'Confidential letterhead' in json.dumps(snapshots) and 'private.pdf' not in json.dumps(snapshots)
        for snapshot in snapshots:
            assert snapshot['companies'][0]['values'][0]=='Fixture Company'
    assert (private/'auth.json').read_bytes()==before,'Public projections must not rewrite stored document identities'
    print('PASS document metadata requires document AND company permission in both Master and operational bootstrap; authorized labels retained; private storage identities never serialized')
