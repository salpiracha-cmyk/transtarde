"""Actual authenticated archive and Sales Tax endpoints; disposable sources only."""
import hashlib, json, os, pathlib, shutil, subprocess, tempfile
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='shipment-archive-read-') as tmp:
    root=pathlib.Path(tmp); app=root/'app'
    shutil.copytree(ROOT,app,ignore=shutil.ignore_patterns('.git','node_modules','tmp','__pycache__'))
    private=root/'transtrade_private'; private.mkdir()
    auth=private/'auth.json'; operations=private/'operations.json'
    owner={'id':1,'username':'fixture','full_name':'Fixture','role':'Super Admin','active':True,'session_version':1}
    def user(value):auth.write_text(json.dumps({'users':[value],'masters':{},'settings':{'qa_account_seeded':True},'audit':[]}))
    user(owner)
    source={'contracts':[{'id':'C1','ref':'C1','seller':'TTI'},{'id':'C2','ref':'C2','seller':'BRM'}],
        'shipments':[{'id':'S1','kind':'lot','contractRef':'C1','lotId':'L1','seller':'TTI','commercial':{'invoiceNo':'INV1','date':'2026-10-18'}}]}
    operations.write_text(json.dumps({'values':{'transtrade_export_v3_operational':json.dumps(source)}}))
    (private/'accounts.json').write_text(json.dumps({'revision':1}))
    (private/'export_documents').mkdir();(private/'export_documents/index.json').write_text('[]')
    jobs=private/'office_agent_jobs'; jobs.mkdir()
    payload=b'%PDF-1.4\nfixture native archived pack\n%%EOF\n'
    def job(id,contract='C1',lot='L1',date='2026-10-18T12:00:00Z',name='Master Shipment Documents.pdf'):
        folder=jobs/id; (folder/'files').mkdir(parents=True)
        (folder/'files/000-Master.pdf').write_bytes(payload)
        row={'id':id,'type':'shipment_archive','contract':contract,'lot':lot,'createdAt':date,'status':'ERROR',
            'files':[{'name':name,'folder':'','storedName':'000-Master.pdf','size':len(payload),'sha256':hashlib.sha256(payload).hexdigest()}]}
        (folder/'job.json').write_text(json.dumps(row));return folder
    first=job('a'*32); job('b'*32,date='2026-10-17T12:00:00Z');job('c'*32,contract='C2',lot='OTHER');job('d'*32,name='DOCUMENTS FOR BANK.pdf')
    (root/'request.php').write_text('''<?php
    $r=json_decode($argv[1],true);$_GET=$r['query'];$_SERVER['REQUEST_METHOD']=$r['method'];$_SERVER['REQUEST_URI']='/api/'.$r['endpoint'];$_SERVER['REMOTE_ADDR']='192.0.2.83';
    session_name('TRANSTRADE_SESSION');session_id('archive-fixture-'.bin2hex(random_bytes(12)));session_start();
    $_SESSION=['user_id'=>1,'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];session_write_close();
    register_shutdown_function(static function(){file_put_contents(__DIR__.'/status',(string)(http_response_code()?:200));});require __DIR__.'/app/api/'.$r['endpoint'];
    ''')
    env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
    def request(id='a'*32,endpoint='shipment_archive_file.php',method='GET',**query):
        result=subprocess.run(['php','-d',f'session.save_path={root}',str(root/'request.php'),json.dumps({'endpoint':endpoint,'method':method,'query':dict(id=id,**query)})],env=env,capture_output=True,check=True)
        return int((root/'status').read_text()),result.stdout
    def snapshot():return {str(p.relative_to(private)):p.read_bytes() for item in ['accounts.json','operations.json','export_documents','office_agent_jobs'] for p in ([private/item] if (private/item).is_file() else (private/item).rglob('*')) if p.is_file()}
    before=snapshot()
    status,data=request();assert status==200 and data==payload,(status,data)
    assert request('d'*32)[0]==404, 'Bank/other job files must not become archive downloads'
    assert request('../auth')[0]==422
    assert request('e'*32)[0]==404
    assert request(method='POST')[0]==405
    status,data=request(endpoint='accounts_sales_tax.php',entity='TTI');rows=json.loads(data)['rows']
    assert status==200 and len(rows)==1 and [d['id'] for d in rows[0]['documents']]==['archive:'+'a'*32,'archive:'+'b'*32],(status,rows)
    # The existing document policy controls company and Exports Print access.
    for permissions,expected in [({'Mill':['View']},403),({'Accounts':{'entity-brm':['View'],'reports':['View']}},403),({'Accounts':{'entity-tti':['View'],'reports':['View']}},200),({'Exports':{'commercial':['View']}},403),({'Exports':{'print':['View']}},200)]:
        user({**owner,'role':'Staff','permissions':permissions});status,data=request();assert status==expected,(permissions,status,data)
    user(owner)
    path=first/'files/000-Master.pdf';path.write_bytes(payload+b'tampered');assert request()[0]==409;path.write_bytes(payload)
    path.unlink();assert request()[0]==404
    path.symlink_to(jobs/('b'*32)/'files/000-Master.pdf');assert request()[0]==404;path.unlink();path.write_bytes(payload)
    manifest=first/'job.json';old=manifest.read_bytes();value=json.loads(old);value['files'][0]['storedName']='../auth.json';manifest.write_text(json.dumps(value));assert request()[0]==500;manifest.write_bytes(old)
    manifest.write_text('{broken');assert request()[0]==500;manifest.write_bytes(old)
    assert snapshot()==before,('Changed source paths',[k for k in set(snapshot())|set(before) if snapshot().get(k)!=before.get(k)])
print('PASS real-auth archive read: source bytes, revision labels, lot/company/module permissions, GET-only, missing/tampered files, path/symlink rejection and no source writes')
