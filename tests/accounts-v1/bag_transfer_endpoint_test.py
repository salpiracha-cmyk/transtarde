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
function tt_user_can_master($u,$type,$action){return ($_GET['mode']??'')==='master'&&$type==='mills'&&$action==='Create';}
function tt_verify_csrf($v){return $v==='fixture';}
function tt_release_read_session(){}
function tt_accounts_input(){return $GLOBALS['fixtureBody'];}
function tt_list_masters(){return ['mills'=>[['id'=>'mills-1','values'=>['TTI Rice Mills','','Own Mill','','','Active','','TTI']],['id'=>'mills-2','values'=>['Indus Rice Mill','','Transfer Location','','','Active']],['id'=>'office','values'=>['Office','','Office','','','Active']]]];}
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
    fellow=dict(id=456,entity='TTI',mill='TTI Rice Mills',brand='Fellow Brand',size='25 kg',bagType='PP Woven',
               received=600,sourceKind='OTHER_EXPORTER',exporterDealId='SODA-1',externalOwnerId='OWNER-1',
               externalOwnerName='Fellow Exporter',stockOwnerType='OTHER_EXPORTER',sodaReference='SODA-1')
    source=root/'data/operations.json';books=root/'data/accounts.json'
    initial={'revision':3,'values':{'tt30bags':json.dumps([order,fellow]),'tt33bagreceipts':json.dumps([{'id':'R1','bagId':123,'date':'2026-10-01','qty':100},{'id':'R2','bagId':456,'date':'2026-10-01','qty':600}]),'tt99keep':'{"untouched":true}'},'meta':{}}
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
    assert req()[1]['canAddDestination'] is False
    assert req(mode='master')[1]['canAddDestination'] is True
    add=dict(csrf='fixture',action='add_destination',entity='TTI',sourceLocationId='mills-1',name='HKM Warehouse',type='Warehouse')
    assert req(add)[0]==403 and req(add,mode='other')[0]==403 and req(add,mode='read')[0]==403
    assert req(dict(add,csrf='wrong'),mode='master')[0]==419
    assert req(dict(add,type='Office'),mode='master')[0]==422

    assert req(mode='other')[0]==403 and req(mode='company')[0]==403 and req(mode='no-mill')[0]==403
    body=dict(csrf='fixture',action='dispatch',entity='TTI',sourceLocationId='mills-1',destinationId='mills-2',date=inventory['asOfDate'],sourceFingerprint=inventory['fingerprint'],requestKey='actual-dispatch-0001',allocations=[dict(lotId=lot,qty=10)])
    assert req(body,mode='read')[0]==403 and req(dict(body,csrf='wrong'))[0]==419 and req(body,mode='company')[0]==403
    assert req(dict(body,destinationId='office'))[0]==422
    assert req(dict(body,ownership='other'))[0]==422
    assert snapshot()==before,'Denied movement changed sources'
    status,saved=req(body);assert status==200,saved;record=saved['movement']['record'];tid=record['id'];line=record['lines'][0]['lineId'];after=snapshot()
    assert saved['stateVersion']==1 and json.loads(stored()['values']['tt30bags'])==[order,fellow]
    assert stored()['values']['tt99keep']==initial['values']['tt99keep'] and books.read_bytes()==financial
    status,replay=req(body);assert status==200 and replay['movement']['replayed'] and snapshot()==after,'Dispatch retry changed stock/version'
    assert req(dict(body,requestKey='actual-dispatch-stale-0001'))[0]==422 and snapshot()==after
    assert req()[1]['inventory']['lots'][lot]['availableQty']==90
    assert saved['movement']['status']=='Completed'
    target=req(mode='target',location='mills-2')[1];target_id='BT|'+tid+'|'+line
    assert target['inventory']['lots'][target_id]['availableQty']==10
    assert target['inventory']['lots'][target_id]['poNo']=='PO-1'
    assert record['receipts'][0]['userId']==7 and record['receipts'][0]['date']==body['date']
    receipt=dict(csrf='fixture',action='receive',entity='TTI',transferId=tid,date=body['date'],
                 requestKey='second-receipt-0001',allocations=[dict(lineId=line,qty=1)])
    assert req(receipt)[0]==403 and req(receipt,mode='target',location='mills-2')[0]==422
    assert snapshot()==after,'A second receipt altered a completed transfer'
    fresh=req()[1]['inventory'];fellow_lot='PO|456|mills-1'
    partner=dict(body,ownership='other',requestKey='fellow-transfer-0001',sourceFingerprint=fresh['fingerprint'],
                 allocations=[dict(lotId=fellow_lot,qty=150)])
    status,saved_partner=req(partner);assert status==200 and saved_partner['movement']['status']=='Completed',saved_partner
    fellow_record=saved_partner['movement']['record'];partner_lot='BT|'+fellow_record['id']+'|'+fellow_record['lines'][0]['lineId']
    assert req()[1]['inventory']['lots'][fellow_lot]['availableQty']==450
    destination=req(mode='target',location='mills-2')[1]['inventory']
    received=destination['lots'][partner_lot]
    assert (received['availableQty'],received['exporterDealId'],received['externalOwnerId'],received['packingKg'])==(150,'SODA-1','OWNER-1',25)
    assert received['stockOwnerType']=='OTHER_EXPORTER' and received['poNo']==''
    after_partner=snapshot()
    assert req(partner)[1]['movement']['replayed'] and snapshot()==after_partner
    assert req(dict(partner,requestKey='stale-double-submit-01'))[0]==422 and snapshot()==after_partner
    # Moving the same bags again must retain the owner and original SODA.
    back=dict(partner,sourceLocationId='mills-2',destinationId='mills-1',sourceFingerprint=destination['fingerprint'],
              requestKey='third-location-chain-01',allocations=[dict(lotId=partner_lot,qty=25)])
    status,returned=req(back,mode='target',location='mills-2');assert status==200,returned
    assert returned['movement']['record']['lines'][0]['sourceSnapshot']['exporterDealId']=='SODA-1'
    assert req(mode='target',location='mills-2')[1]['inventory']['lots'][partner_lot]['availableQty']==125
    assert sum(r['availableQty'] for r in req()[1]['inventory']['lots'].values() if r.get('exporterDealId')=='SODA-1')==475
    assert json.loads(stored()['values']['tt30bags'])==[order,fellow] and books.read_bytes()==financial
    assert stored()['values']['tt33bagreceipts']==initial['values']['tt33bagreceipts']
    # The generic operational cache cannot expose or overwrite the server-owned request/acknowledgement ledger.
    probe=root/'privacy.php';probe.write_text('''<?php require __DIR__.'/auth_store.php';require __DIR__.'/inventory_reconciliation.php';$v=json_decode(stream_get_contents(STDIN),true);$public=tt_inv_public_values($v['values'],$v['meta']);try{tt_inv_prepare_write($v['values'],'tt41bagtransfers','{}',['role'=>'Super Admin'],gmdate('c'));$public['bypass']=true;}catch(DomainException){$public['bypass']=false;}echo json_encode($public);''')
    p=subprocess.run(['php',str(probe)],input=json.dumps(stored()),env=env,text=True,capture_output=True,check=True);public=json.loads(p.stdout)
    assert 'tt41bagtransfers' not in public['values'] and 'tt41bagtransfers' not in public['meta'] and 'tt41bagtransfers' in public['restrictedKeys'] and not public['bypass']
    if MYSQL:sql_run('cleanup')
    print(('MYSQL ' if MYSQL else 'FILE ')+'PASS actual transfer endpoint: zero-write scoped review, company/location/role/CSRF, atomic immediate PO and SODA stock transfers and chained custody, exact retry/version, unchanged PO/books and protected server ledger')
