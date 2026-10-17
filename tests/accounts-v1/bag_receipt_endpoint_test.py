"""Real atomic bag receipt controller against disposable file or explicitly guarded QA MySQL."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

ROOT=Path(__file__).resolve().parents[2]
MYSQL=os.environ.get('TT_BAG_TEST_MYSQL')=='1'
if MYSQL:
    assert os.environ.get('TT_QA_MYSQL')=='1' and os.environ.get('TT_DB_HOST')=='127.0.0.1' and os.environ.get('TT_DB_NAME','').startswith('transtrade_qa_')
env=dict(os.environ) if MYSQL else {k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
with tempfile.TemporaryDirectory(prefix='bag-receipt-endpoint-') as tmp:
    root=Path(tmp);shutil.copytree(ROOT/'api',root/'api');(root/'data').mkdir()
    for f in ['inventory_reconciliation.php','product_stage.php']:shutil.copy(ROOT/f,root/f)
    (root/'auth_store.php').write_text('''<?php
define('TT_DATA_DIR',__DIR__.'/data');
function tt_require_login(){return ['id'=>($_GET['mode']??'')==='target'?8:7,'full_name'=>($_GET['mode']??'')==='target'?'Actual receiver':'Actual sender','role'=>'Staff','location'=>($_GET['mode']??'')==='target'?'Indus Rice Mill':(($_GET['mode']??'')==='other'?'Other Mill':'TTI Rice Mills'),'permissions'=>($_GET['mode']??'')==='company'?['Accounts'=>['entity-brm'=>['View','Create']]]:[]];}
function tt_user_can_open_module($u,$m){return ($_GET['mode']??'')!=='no-mill';}
function tt_user_can_module_action($u,$m,$i,$a){return ($_GET['mode']??'')!=='read'||$a==='View';}
function tt_user_can_access_entity($u,$entity,$action){return ($_GET['mode']??'')!=='company'||$entity==='BRM';}
function tt_verify_csrf($v){return $v==='fixture';}
function tt_release_read_session(){}
function tt_accounts_input(){return $GLOBALS['fixtureBody'];}
function tt_list_masters(){return ['mills'=>[['id'=>'mills-1','values'=>['TTI Rice Mills','','Own Mill','','','Active','','TTI']],['id'=>'mills-2','values'=>['Indus Rice Mill','','External Mill','','','Active']]]];}
''')
    (root/'runner.php').write_text('''<?php
$r=json_decode(stream_get_contents(STDIN),true);$_GET=$r['query'];$_SERVER['REQUEST_METHOD']=$r['body']===null?'GET':'POST';$GLOBALS['fixtureBody']=json_encode($r['body']);register_shutdown_function(function(){fwrite(STDERR,'STATUS:'.(http_response_code()?:200));});require __DIR__.'/api/bag_receipts.php';
''')
    sql=root/'database.php';sql.write_text(r'''<?php
$p=new PDO('mysql:host='.getenv('TT_DB_HOST').';dbname='.getenv('TT_DB_NAME').';charset=utf8mb4',getenv('TT_DB_USER'),getenv('TT_DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if($argv[1]==='seed'){$p->exec('DELETE FROM tt_operation_records');$p->exec('DELETE FROM tt_operation_history');$q=$p->prepare("INSERT INTO tt_operation_records VALUES(?,?,1,NOW(6),'Fixture',7,'Mill')");foreach(json_decode($argv[2],true)as$k=>$v)$q->execute([$k,$v]);}
elseif($argv[1]==='cleanup'){$p->exec('DELETE FROM tt_operation_records');$p->exec('DELETE FROM tt_operation_history');}
else{$out=['values'=>[],'meta'=>[]];foreach($p->query('SELECT storage_key,payload,version FROM tt_operation_records ORDER BY storage_key')->fetchAll(PDO::FETCH_ASSOC)as$r){$out['values'][$r['storage_key']]=$r['payload'];$out['meta'][$r['storage_key']]=['version'=>(int)$r['version']];}$out['history']=$p->query('SELECT storage_key,version,payload_sha256 FROM tt_operation_history ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);echo json_encode($out);}
''')
    def sql_run(mode,*args):return subprocess.run(['php',str(sql),mode,*args],env=env,text=True,capture_output=True,check=True).stdout
    order=dict(_ttBridge='exports',id=123,entity='TTI',mill='TTI Rice Mills',brand='ASAS',size='50 kg',bagType='PP',received=100,poNo='PO-1',poLineKey='L1')
    source=root/'data/operations.json';books=root/'data/accounts.json'
    initial={'revision':3,'values':{'tt30bags':json.dumps([order,dict(order,id=124,received=0,poLineKey='L2',size='25 kg')]),'tt33bagreceipts':json.dumps([{'id':'R1','bagId':123,'date':'2026-10-01','qty':100}]),'tt99keep':'{"untouched":true}'},'meta':{}}
    source.write_text(json.dumps(initial));books.write_text('{"journals":{"KEEP":{"amount":1000}}}');financial=books.read_bytes()
    if MYSQL:sql_run('seed',json.dumps(initial['values']))
    def snapshot():return sql_run('read').encode() if MYSQL else source.read_bytes()
    def stored():return json.loads(snapshot())
    def req(body=None,mode='source',location='mills-1',entity='TTI'):
        request={'query':dict(entity=entity,locationId=location,mode=mode,bagId='123'),'body':body}
        out=subprocess.run(['php',str(root/'runner.php')],input=json.dumps(request),text=True,capture_output=True,env=env,timeout=20,check=True)
        return int(out.stderr.rsplit('STATUS:',1)[1]),json.loads(out.stdout)
    before=snapshot();status,view=req();assert status==200,view;review=view['source'];assert review['order']['received']==100
    assert snapshot()==before and books.read_bytes()==financial,'Receipt review wrote data'
    assert req(mode='other')[0]==403 and req(mode='company')[0]==403 and req(mode='no-mill')[0]==403
    body=dict(csrf='fixture',action='receive_supplier',entity='TTI',locationId='mills-1',bagId='123',date='2026-10-17',qty=10,sourceFingerprint=review['fingerprint'],requestKey='actual-supplier-receipt-0001',keepReceiptOpen=False,inspected=True,printingMismatch=False,materialMismatch=False,qualityMismatch=False,tareMismatch=False,actualTareGrams=None)
    for denied,mode in [(body,'read'),(dict(body,csrf='bad'),'source'),(body,'company'),(dict(body,entity='BRM'),'source'),(dict(body,locationId='mills-2'),'source')]:
        status,result=req(denied,mode=mode);assert status in (403,419,422),result;assert snapshot()==before,'Denied receipt changed data'
    for changes in [dict(qty=1.5),dict(qty=0),dict(date='2099-01-01'),dict(inspected=False),dict(tareMismatch=True),dict(sourceFingerprint='a'*64)]:
        status,result=req(dict(body,**changes));assert status==422,result;assert snapshot()==before,'Invalid receipt changed a counter or audit'
    status,saved=req(body);assert status==200,saved;receipt=saved['receipt'];assert receipt['qty']==10 and receipt['acceptedQty']==10 and receipt['quarantinedQty']==0 and receipt['userId']==7 and receipt['sourceSnapshot']['locationId']=='mills-1'
    assert saved['order']['received']==110 and saved['stateVersion']==1
    physical=stored();assert len(json.loads(physical['values']['tt33bagreceipts']))==2 and json.loads(physical['values']['tt30bags'])[0]['received']==110
    assert saved['sourceValues']['tt30bags']['version']>0 and saved['sourceValues']['tt33bagreceipts']['version']>0
    after=snapshot();status,replayed=req(body);assert status==200 and replayed['replayed'] and snapshot()==after,'Lost acknowledgement replay duplicated receipt/counter/version'
    assert req(dict(body,qty=11))[0]==422 and snapshot()==after,'A committed request accepted different receipt facts'
    review=req()[1]['source'];held=dict(body,qty=4,requestKey='actual-quarantine-receipt-0002',sourceFingerprint=review['fingerprint'],materialMismatch=True)
    status,quarantined=req(held);assert status==200,quarantined;assert quarantined['receipt']['acceptedQty']==0 and quarantined['receipt']['quarantinedQty']==4 and quarantined['order']['received']==114
    # Project actual committed stock: quarantine is visible, never usable or dispatchable.
    probe=root/'inventory.php';probe.write_text("<?php require __DIR__.'/auth_store.php';require __DIR__.'/api/bag_inventory_core.php';$v=json_decode(stream_get_contents(STDIN),true);echo json_encode(bti_project($v['values'],tt_list_masters(),['entity'=>'TTI','locationId'=>'mills-1','millName'=>'TTI Rice Mills','status'=>'Active'],'2026-10-17','2026-10-17',json_decode($v['values']['tt41bagtransfers']??'{}',true))); ")
    out=subprocess.run(['php',str(probe)],input=json.dumps(stored()),env=env,text=True,capture_output=True,check=True);inventory=json.loads(out.stdout);lot=inventory['lots']['PO|123|mills-1'];assert lot['availableQty']==110 and lot['quarantinedQty']==4,lot
    review=req()[1]['source'];status_body=dict(csrf='fixture',action='receipt_status',entity='TTI',locationId='mills-1',bagId='123',sourceFingerprint=review['fingerprint'],requestKey='actual-receipt-status-0003',keepReceiptOpen=True)
    status,status_saved=req(status_body);assert status==200,status_saved;assert all(row['keepReceiptOpen'] for row in json.loads(stored()['values']['tt30bags']));assert status_saved['receipt'] is None and status_saved['order']['received']==114 and status_saved['order']['keepReceiptOpen']
    status_snapshot=snapshot();assert req(status_body)[1]['replayed'] and snapshot()==status_snapshot
    immutable_receipts=stored()['values']['tt33bagreceipts'];original_counter=stored()['values']['tt30bags'];receipt_id=quarantined['receipt']['id']
    def inventory_now():
        output=subprocess.run(['php',str(probe)],input=json.dumps(stored()),env=env,text=True,capture_output=True,check=True);return json.loads(output.stdout)['lots']['PO|123|mills-1']
    evidence=root/'acceptance.php';evidence.write_text("<?php require __DIR__.'/auth_store.php';require __DIR__.'/api/bag_acceptance_core.php';echo json_encode(bqa_current_line(['lineKey'=>'L1','orderedQty'=>200],['entity'=>'TTI','poNo'=>'PO-1','lineKey'=>'L1']));")
    def acceptance_now():return json.loads(subprocess.run(['php',str(evidence)],env=env,text=True,capture_output=True,check=True).stdout)
    actual=acceptance_now();assert actual['receivedQty']==114 and actual['acceptance']['material'],actual
    review=req()[1]['source'];disposition=dict(csrf='fixture',action='return_supplier',entity='TTI',locationId='mills-1',bagId='123',receiptId=receipt_id,date='2026-10-17',qty=1,reason='Actually returned damaged bags to original supplier',physicallyReturned=True,sourceFingerprint=review['fingerprint'],requestKey='actual-quarantine-return-0004')
    before=snapshot();assert req(dict(disposition,physicallyReturned=False))[0]==422 and snapshot()==before
    assert req(dict(disposition,date='2026-10-16'))[0]==422 and snapshot()==before
    assert req(disposition,mode='read')[0]==403 and snapshot()==before
    status,returned=req(disposition);assert status==200,returned;assert returned['disposition']['qty']==1 and returned['disposition']['userId']==7
    after=snapshot();assert req(disposition)[1]['replayed'] and snapshot()==after
    actual=acceptance_now();assert actual['receivedQty']==113 and actual['acceptance']['material'],actual
    review=req()[1]['source'];correction=dict(csrf='fixture',action='accept_corrected',entity='TTI',locationId='mills-1',bagId='123',receiptId=receipt_id,date='2026-10-17',qty=2,reason='Material physically replaced and reinspected against original PO',inspected=True,matchesOriginalSpecification=True,sourceFingerprint=review['fingerprint'],requestKey='actual-quarantine-correct-0005')
    before=snapshot();assert req(dict(correction,matchesOriginalSpecification=False))[0]==422 and snapshot()==before;assert req(dict(correction,qty=4))[0]==422 and snapshot()==before
    status,corrected=req(correction);assert status==200,corrected;after=snapshot();assert req(correction)[1]['replayed'] and snapshot()==after
    projected=inventory_now();assert projected['availableQty']==112 and projected['quarantinedQty']==1,projected
    review=req()[1]['source'];last=dict(disposition,requestKey='actual-quarantine-return-0006',sourceFingerprint=review['fingerprint']);status,closed=req(last);assert status==200,closed
    projected=inventory_now();assert projected['availableQty']==112 and projected['quarantinedQty']==0,projected;actual=acceptance_now();assert actual['receivedQty']==112 and not actual['acceptance']['material'],actual
    assert stored()['values']['tt33bagreceipts']==immutable_receipts and stored()['values']['tt30bags']==original_counter,'Disposition rewrote original receipt facts or gross counters'
    status_snapshot=snapshot()
    # Generic writers cannot change an acknowledged counter, relabel its physical source or erase receipt evidence.
    guard=root/'guard.php';guard.write_text("<?php require __DIR__.'/auth_store.php';require __DIR__.'/inventory_reconciliation.php';$r=json_decode(stream_get_contents(STDIN),true);try{tt_inv_prepare_write($r['values'],$r['key'],json_encode($r['rows']),['role'=>'Super Admin'],gmdate('c'));echo 'allowed';}catch(DomainException){echo 'denied';}")
    current=stored();orders=json.loads(current['values']['tt30bags']);receipts=json.loads(current['values']['tt33bagreceipts'])
    def denied(key,rows):
        output=subprocess.run(['php',str(guard)],input=json.dumps(dict(values=current['values'],key=key,rows=rows)),env=env,text=True,capture_output=True,check=True);assert output.stdout=='denied',output.stdout
    for field,value in [('received',999),('mill','Indus Rice Mill'),('entity','BRM'),('brand','OTHER'),('size','25 kg'),('bagReceiptScope',{})]:
        changed=json.loads(json.dumps(orders));changed[0][field]=value;denied('tt30bags',changed)
    denied('tt33bagreceipts',receipts[:-1]);changed=json.loads(json.dumps(receipts));changed[-1]['qty']=999;denied('tt33bagreceipts',changed);denied('tt33bagreceipts',receipts+[dict(id='invented',qty=5,bagId=123)])
    assert snapshot()==status_snapshot and books.read_bytes()==financial and stored()['values']['tt99keep']==initial['values']['tt99keep']
    if MYSQL:sql_run('cleanup')
    print(('MYSQL ' if MYSQL else 'FILE ')+'PASS atomic actual supplier receipts: scoped zero-write review, permissions and CSRF, actual dates/whole counts, inspection, counter/receipt/audit commit, quarantine unavailable, stable replay/version, keep-open without new receipt, protected source identity and unchanged books')
