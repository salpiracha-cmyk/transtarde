"""Real bag transfer controller against disposable file or explicitly guarded QA MySQL."""
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
with tempfile.TemporaryDirectory(prefix='bag-transfer-endpoint-') as tmp:
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
$r=json_decode(stream_get_contents(STDIN),true);$_GET=$r['query'];$_SERVER['REQUEST_METHOD']=$r['body']===null?'GET':'POST';$GLOBALS['fixtureBody']=json_encode($r['body']);register_shutdown_function(function(){fwrite(STDERR,'STATUS:'.(http_response_code()?:200));});require __DIR__.'/api/bag_transfers.php';
''')
    sql=root/'database.php';sql.write_text(r'''<?php
$p=new PDO('mysql:host='.getenv('TT_DB_HOST').';dbname='.getenv('TT_DB_NAME').';charset=utf8mb4',getenv('TT_DB_USER'),getenv('TT_DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if($argv[1]==='seed'){$p->exec('DELETE FROM tt_operation_records');$p->exec('DELETE FROM tt_operation_history');$q=$p->prepare("INSERT INTO tt_operation_records VALUES(?,?,1,NOW(6),'Fixture',7,'Mill')");foreach(json_decode($argv[2],true)as$k=>$v)$q->execute([$k,$v]);}
elseif($argv[1]==='cleanup'){$p->exec('DELETE FROM tt_operation_records');$p->exec('DELETE FROM tt_operation_history');}
else{$out=['values'=>[],'meta'=>[]];foreach($p->query('SELECT storage_key,payload,version FROM tt_operation_records ORDER BY storage_key')->fetchAll(PDO::FETCH_ASSOC)as$r){$out['values'][$r['storage_key']]=$r['payload'];$out['meta'][$r['storage_key']]=['version'=>(int)$r['version']];}$out['history']=$p->query('SELECT storage_key,version,payload_sha256 FROM tt_operation_history ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);echo json_encode($out);}
''')
    def sql_run(mode,*args):return subprocess.run(['php',str(sql),mode,*args],env=env,text=True,capture_output=True,check=True).stdout
    order=dict(id=123,entity='TTI',mill='TTI Rice Mills',brand='ASAS',size='50 kg',bagType='PP',received=100,poNo='PO-1',poLineKey='L1')
    source=root/'data/operations.json';books=root/'data/accounts.json'
    initial={'revision':3,'values':{'tt30bags':json.dumps([order]),'tt33bagreceipts':json.dumps([{'id':'R1','bagId':123,'date':'2026-10-01','qty':100}]),'tt99keep':'{"untouched":true}'},'meta':{}}
    source.write_text(json.dumps(initial));books.write_text('{"journals":{"KEEP":{"amount":1000}}}');financial=books.read_bytes()
    if MYSQL:sql_run('seed',json.dumps(initial['values']))
    def snapshot():return sql_run('read').encode() if MYSQL else source.read_bytes()
    def stored():return json.loads(snapshot())
    def req(body=None,mode='source',location='mills-1',entity='TTI'):
        request={'query':dict(entity=entity,locationId=location,mode=mode),'body':body}
        out=subprocess.run(['php',str(root/'runner.php')],input=json.dumps(request),text=True,capture_output=True,env=env,timeout=20,check=True)
        return int(out.stderr.rsplit('STATUS:',1)[1]),json.loads(out.stdout)
    before=snapshot();status,view=req();assert status==200,view;inventory=view['inventory'];lot='PO|123|mills-1';assert inventory['lots'][lot]['availableQty']==100
    assert snapshot()==before and books.read_bytes()==financial,'GET changed operational or financial stores'
    assert req(mode='read')[1]['canCreate'] is False
    assert req(mode='other')[0]==403 and req(mode='company')[0]==403 and req(mode='no-mill')[0]==403
    body=dict(csrf='fixture',action='dispatch',entity='TTI',sourceLocationId='mills-1',destinationId='mills-2',date=inventory['asOfDate'],sourceFingerprint=inventory['fingerprint'],requestKey='actual-dispatch-0001',allocations=[dict(lotId=lot,qty=10)])
    assert req(body,mode='read')[0]==403 and req(dict(body,csrf='wrong'))[0]==419 and req(body,mode='company')[0]==403
    assert snapshot()==before,'Denied movement changed sources'
    status,saved=req(body);assert status==200,saved;record=saved['movement']['record'];tid=record['id'];line=record['lines'][0]['lineId'];after=snapshot()
    assert saved['stateVersion']==1 and json.loads(stored()['values']['tt30bags'])==[order]
    assert stored()['values']['tt99keep']==initial['values']['tt99keep'] and books.read_bytes()==financial
    status,replay=req(body);assert status==200 and replay['movement']['replayed'] and snapshot()==after,'Dispatch retry changed stock/version'
    assert req(dict(body,requestKey='actual-dispatch-stale-0001'))[0]==422 and snapshot()==after
    assert req()[1]['inventory']['lots'][lot]['availableQty']==90
    target=req(mode='target',location='mills-2')[1];target_id='BT|'+tid+'|'+line;assert target['inventory']['lots'][target_id]['availableQty']==0,'Destination stock appeared before receipt'
    receipt=dict(csrf='fixture',action='receive',entity='TTI',transferId=tid,date=inventory['asOfDate'],requestKey='actual-receipt-0001',allocations=[dict(lineId=line,qty=6)])
    assert req(receipt)[0]==403 and snapshot()==after,'Sender impersonated destination receiver'
    status,received=req(receipt,mode='target',location='mills-2');assert status==200,received;assert received['movement']['record']['receipts'][0]['userId']==8
    partial=snapshot();assert req(receipt,mode='target',location='mills-2')[1]['movement']['replayed'] and snapshot()==partial
    assert req(mode='target',location='mills-2')[1]['inventory']['lots'][target_id]['availableQty']==6
    returned=dict(csrf='fixture',action='return_to_source',entity='TTI',transferId=tid,date=inventory['asOfDate'],reason='Actually received back at original source',requestKey='actual-source-return-0001',allocations=[dict(lineId=line,qty=2)])
    assert req(returned,mode='target',location='mills-2')[0]==403
    status,returned_response=req(returned);assert status==200,returned_response
    assert req()[1]['inventory']['lots'][lot]['availableQty']==92
    wrong=dict(receipt,requestKey='actual-receipt-over-0001',allocations=[dict(lineId=line,qty=3)]);unchanged=snapshot();assert req(wrong,mode='target',location='mills-2')[0]==422 and snapshot()==unchanged
    last=dict(receipt,requestKey='actual-receipt-final-0001',allocations=[dict(lineId=line,qty=2)]);status,closed=req(last,mode='target',location='mills-2');assert status==200 and closed['movement']['status']=='Closed — received and returned',closed
    assert req(mode='target',location='mills-2')[1]['inventory']['lots'][target_id]['availableQty']==8 and req()[1]['inventory']['lots'][lot]['availableQty']==92
    assert json.loads(stored()['values']['tt30bags'])==[order] and books.read_bytes()==financial,'Acknowledgement fabricated a PO or a financial write'
    # Rejected delivery stays unavailable at both locations until explicit resolution.
    fresh=req()[1]['inventory'];held_dispatch=dict(body,requestKey='held-dispatch-0001',sourceFingerprint=fresh['fingerprint'])
    status,ack=req(held_dispatch);assert status==200,ack
    held_record=ack['movement']['record'];held_id=held_record['id'];held_line=held_record['lines'][0]['lineId'];held_lot='BT|'+held_id+'|'+held_line
    quarantine=dict(receipt,action='quarantine',transferId=held_id,requestKey='held-quarantine-001',reason='Damaged bags inspected and held',allocations=[dict(lineId=held_line,qty=10)])
    before=snapshot();assert req(quarantine)[0]==403 and snapshot()==before
    status,ack=req(quarantine,mode='target',location='mills-2');assert status==200,ack
    before=snapshot();assert req(quarantine,mode='target',location='mills-2')[1]['movement']['replayed'] and snapshot()==before
    held_view=req(mode='target',location='mills-2')[1]['inventory']['lots'][held_lot]
    assert held_view['availableQty']==0 and held_view['quarantinedQty']==10
    assert req()[1]['inventory']['lots'][lot]['availableQty']==82
    release=dict(quarantine,action='accept_quarantine',requestKey='held-acceptance-001',reason='Reinspected and accepted',allocations=[dict(lineId=held_line,qty=6)])
    before=snapshot();assert req(release)[0]==403 and snapshot()==before
    status,ack=req(release,mode='target',location='mills-2');assert status==200,ack
    held_view=req(mode='target',location='mills-2')[1]['inventory']['lots'][held_lot]
    assert held_view['availableQty']==6 and held_view['quarantinedQty']==4
    back=dict(quarantine,action='return_quarantine',requestKey='held-source-return-01',reason='Actually unloaded back at source',allocations=[dict(lineId=held_line,qty=4)])
    before=snapshot();assert req(back,mode='target',location='mills-2')[0]==403 and snapshot()==before
    status,ack=req(back);assert status==200,ack
    before=snapshot();assert req(back)[1]['movement']['replayed'] and snapshot()==before
    assert req()[1]['inventory']['lots'][lot]['availableQty']==86
    held_view=req(mode='target',location='mills-2')[1]['inventory']['lots'][held_lot]
    assert held_view['availableQty']==6 and held_view['quarantinedQty']==0
    assert ack['movement']['status']=='Closed — received and returned'
    before=snapshot();assert req(dict(release,requestKey='held-acceptance-overflow'),mode='target',location='mills-2')[0]==422 and snapshot()==before
    assert json.loads(stored()['values']['tt30bags'])==[order] and books.read_bytes()==financial
    # The generic operational cache cannot expose or overwrite the server-owned request/acknowledgement ledger.
    probe=root/'privacy.php';probe.write_text('''<?php require __DIR__.'/auth_store.php';require __DIR__.'/inventory_reconciliation.php';$v=json_decode(stream_get_contents(STDIN),true);$public=tt_inv_public_values($v['values'],$v['meta']);try{tt_inv_prepare_write($v['values'],'tt41bagtransfers','{}',['role'=>'Super Admin'],gmdate('c'));$public['bypass']=true;}catch(DomainException){$public['bypass']=false;}echo json_encode($public);''')
    p=subprocess.run(['php',str(probe)],input=json.dumps(stored()),env=env,text=True,capture_output=True,check=True);public=json.loads(p.stdout)
    assert 'tt41bagtransfers' not in public['values'] and 'tt41bagtransfers' not in public['meta'] and 'tt41bagtransfers' in public['restrictedKeys'] and not public['bypass']
    if MYSQL:sql_run('cleanup')
    print(('MYSQL ' if MYSQL else 'FILE ')+'PASS actual transfer endpoint: zero-write scoped review, company/location/role/CSRF, atomic source deduction and destination partial receipt, source return, exact retry/version, unchanged PO/books and protected server ledger')
