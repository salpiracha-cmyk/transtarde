"""Exercise the actual read-only report endpoint against disposable stored records."""
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
ROOT=Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='sales-tax-source-') as temp:
    root=Path(temp);(root/'api').mkdir();(root/'data/export_documents').mkdir(parents=True)
    for name in ['accounts_sales_tax.php','accounts_sales_tax_core.php','shipment_archive_core.php']:shutil.copy(ROOT/'api'/name,root/'api'/name)
    (root/'auth_store.php').write_text('''<?php
define('TT_DATA_DIR',__DIR__.'/data');define('TT_DB_HOST','');
function tt_require_login(){return ['role'=>'Staff'];}
function tt_user_can_open_module($u,$m){return ($_GET['mode']??'')!=='no-module';}
function tt_user_can_access_entity($u,$e,$a){return $e==='TTI';}
''')
    (root/'runner.php').write_text('''<?php
$r=json_decode(stream_get_contents(STDIN),true);$_GET=$r['query'];$_SERVER['REQUEST_METHOD']=$r['method'];
register_shutdown_function(function(){fwrite(STDERR,'STATUS:'.(http_response_code()?:200));});require __DIR__.'/api/accounts_sales_tax.php';
''')
    process=dict(id='P',kind='process',contractRef='C',seller='TTI',commercial=dict(date='2026-09-01',invoiceNo='PREP'))
    lot=dict(id='L',lotId='LOT1',parentProcessId='P',kind='lot',contractRef='C',seller='TTI',commercial=dict(date='2026-09-02',invoiceNo='INV1'))
    (root/'data/operations.json').write_text(json.dumps({'values':{'transtrade_export_v3_operational':json.dumps({'contracts':[{'ref':'C'}],'shipments':[process,lot]})}}))
    (root/'data/accounts.json').write_text(json.dumps({'revision':12,'journals':{},'exportReceipts':{}}))
    (root/'data/export_documents/index.json').write_text(json.dumps([{'id':'F1','contract_ref':'C','lot_ref':'P','original_name':'Original.pdf'}]))
    archive=root/'data/office_agent_jobs'/('a'*32);archive.mkdir(parents=True)
    (archive/'job.json').write_text(json.dumps({'id':'a'*32,'type':'shipment_archive','contract':'C','lot':'LOT1','createdAt':'2026-10-18T12:00:00Z','files':[{'name':'Master Shipment Documents.pdf','storedName':'000-Master.pdf','sha256':'b'*64,'size':99}]}))
    def snapshot():return {str(p.relative_to(root/'data')):p.read_bytes() for p in (root/'data').rglob('*') if p.is_file()}
    before=snapshot()
    def req(method='GET',**query):
        result=subprocess.run(['php',str(root/'runner.php')],input=json.dumps({'query':dict(entity='TTI',**query),'method':method}),capture_output=True,text=True,check=True)
        return int(result.stderr.rsplit('STATUS:',1)[1]),json.loads(result.stdout)
    status,data=req();assert status==200 and len(data['rows'])==1,data
    assert data['rows'][0]['documents'][0]['id']=='F1'
    assert data['rows'][0]['documents'][1]['id']=='archive:'+'a'*32
    assert 'Archived shipment document pack' in data['rows'][0]['documents'][1]['name']
    assert req(mode='no-module')[0]==403
    result=subprocess.run(['php',str(root/'runner.php')],input=json.dumps({'query':{'entity':'BRM'},'method':'GET'}),capture_output=True,text=True,check=True);assert 'STATUS:403' in result.stderr
    for dates in [{'from':'2026-02-29'},{'from':'2026-10-01','to':'2026-09-01'}]:
        result=subprocess.run(['php',str(root/'runner.php')],input=json.dumps({'query':dict(entity='TTI',**dates),'method':'GET'}),capture_output=True,text=True,check=True);assert 'STATUS:422' in result.stderr
    assert req(method='POST')[0]==405
    assert snapshot()==before,'Read-only report changed financial, operational or document bytes'
    for name in ['accounts.json','operations.json','export_documents/index.json','office_agent_jobs/'+('a'*32)+'/job.json']:
        target=root/'data'/name;original=target.read_bytes();target.write_text('{broken')
        assert req()[0]==500,'Unreadable source was reported as empty: '+name
        assert target.read_text()=='{broken','Report rewrote unreadable source'
        target.write_bytes(original)
    assert snapshot()==before,'Source integrity probes changed original stores'
print('PASS actual Sales Tax endpoint: canonical lots, parent uploads, module/company permissions, date validation, GET-only and unchanged stores')
