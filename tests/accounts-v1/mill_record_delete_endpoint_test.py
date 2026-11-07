"""Controlled Mill deletion through file and QA MySQL transactions, including retries and source guards."""
import copy
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[2]
MYSQL = os.environ.get('TT_MRD_TEST_MYSQL') == '1'
if MYSQL:
    assert os.environ.get('TT_QA_MYSQL') == '1' and os.environ.get('TT_DB_HOST') == '127.0.0.1' and os.environ.get('TT_DB_NAME', '').startswith('transtrade_qa_')
env = dict(os.environ) if MYSQL else {k: v for k, v in os.environ.items() if not k.startswith('TT_DB_')}
with tempfile.TemporaryDirectory(prefix='mill-delete-') as tmp:
    root = Path(tmp)
    shutil.copytree(ROOT / 'api', root / 'api')
    (root / 'data').mkdir()
    for name in ['inventory_reconciliation.php', 'product_stage.php']:
        shutil.copy(ROOT / name, root / name)
    (root / 'auth_store.php').write_text("""<?php
define('TT_DATA_DIR',__DIR__.'/data');
function tt_require_login(){return ['id'=>7,'full_name'=>'Mill operator','role'=>'Staff','permissions'=>['Mill'=>['production'=>['View','Create','Edit']]],'location'=>($_GET['mode']??'')==='location'?'Other Mill':'TTI Rice Mills'];}
function tt_user_can_open_module($u,$m){return true;}
function tt_user_business_entity($u,$e,$a){return $e==='TTI';}
function tt_user_can_module_action($u,$m,$i,$a){return ($_GET['mode']??'')!=='read'||$a==='View';}
function tt_user_can_access_entity($u,$e,$a){return $e==='TTI';}
function tt_verify_csrf($v){return $v==='fixture';}
function tt_release_read_session(){}
function tt_accounts_input(){return $GLOBALS['fixtureBody'];}
function tt_list_masters(){return ['mills'=>[['id'=>'mills-1','values'=>['TTI Rice Mills','','Own Mill','','','Active','','TTI']]]];}
""")
    (root / 'runner.php').write_text("""<?php
$r=json_decode(stream_get_contents(STDIN),true);$_GET=$r['query'];$_SERVER['REQUEST_METHOD']='POST';$GLOBALS['fixtureBody']=json_encode($r['body']);
register_shutdown_function(function(){fwrite(STDERR,'STATUS:'.(http_response_code()?:200));});require __DIR__.'/api/mill_record_delete.php';
""")
    sql = root / 'database.php'
    sql.write_text("""<?php
$p=new PDO('mysql:host='.getenv('TT_DB_HOST').';dbname='.getenv('TT_DB_NAME').';charset=utf8mb4',getenv('TT_DB_USER'),getenv('TT_DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if($argv[1]==='seed'){
 $p->exec('DELETE FROM tt_operation_records');$p->exec('DELETE FROM tt_operation_history');
 $q=$p->prepare("INSERT INTO tt_operation_records VALUES(?,?,1,NOW(6),'Fixture',7,'Mill')");
 foreach(json_decode($argv[2],true)as$key=>$value)$q->execute([$key,$value]);
}else{
 $out=['values'=>[],'meta'=>[]];foreach($p->query('SELECT storage_key,payload,version FROM tt_operation_records ORDER BY storage_key')->fetchAll(PDO::FETCH_ASSOC)as$r){$out['values'][$r['storage_key']]=$r['payload'];$out['meta'][$r['storage_key']]=['version'=>(int)$r['version']];}
 $out['history']=$p->query('SELECT storage_key,version,payload_sha256 FROM tt_operation_history ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);echo json_encode($out);
}
""")
    source = root / 'data/operations.json'
    books = root / 'data/accounts.json'
    books.write_text('{"revision":9,"journals":{},"productionCostRuns":{}}')
    financial = books.read_bytes()
    arrival = dict(id=10101, entity='TTI', millId=1, millName='TTI Rice Mills', date='2026-10-17', vehicle='FIX-101', bags=100)
    other = dict(arrival, id=10102, vehicle='FIX-102')
    production = dict(id=20101, entity='TTI', millId=1, millName='TTI Rice Mills', date='2026-10-17', shift='Day', variety='IRRI-6', rows=[dict(product='B3', bags=10, bagWeight=50)])
    order = dict(id=123, entity='TTI', mill='TTI Rice Mills', brand='ASAS', size='50 kg', bagType='PP', received=100, poNo='PO-1', poLineKey='L1')
    receipt = dict(id='R1', bagId=123, date='2026-10-01', qty=100, entity='TTI', millName='TTI Rice Mills')
    initial = {'tt30queue': json.dumps([arrival, other]), 'tt30slips': '[]', 'tt30prod': json.dumps([production]), 'tt36bagissues': json.dumps([{'productionId': 20101, 'usage': {'ASAS': 10}}]), 'tt30bags': json.dumps([order]), 'tt33bagreceipts': json.dumps([receipt]), 'tt35localsales': json.dumps([dict(id='LS1', soda='LS-00001', entity='TTI', millName='TTI Rice Mills', date='2026-10-17', product='Ready Rice', loads=[], pay=[])]), 'tt99keep': '{"untouched":true}'}
    def sql_run(mode, *args):
        return subprocess.run(['php', str(sql), mode, *args], env=env, text=True, capture_output=True, check=True).stdout
    def seed(values):
        source.write_text(json.dumps({'revision': 1, 'values': values, 'meta': {k: {'version': 1} for k in values}}))
        if MYSQL:
            sql_run('seed', json.dumps(values))
    def stored():
        return json.loads(sql_run('read')) if MYSQL else json.loads(source.read_text())
    def snapshot():
        return json.dumps(stored(), sort_keys=True)
    def req(key, row, mode='all', **changes):
        body = dict(csrf='fixture', collection=key, sourceId=str(row.get('id', row.get('soda'))), expected=row, requestKey='same-delete-request-1234', reason='Duplicate operational entry')
        body.update(changes)
        out = subprocess.run(['php', str(root / 'runner.php')], input=json.dumps(dict(query=dict(mode=mode), body=body)), text=True, capture_output=True, env=env, timeout=20, check=True)
        assert 'STATUS:' in out.stderr, out.stderr
        return int(out.stderr.rsplit('STATUS:', 1)[1]), json.loads(out.stdout)
    seed(initial)
    for mode in ['read', 'location']:
        before = snapshot()
        status, result = req('tt30queue', arrival, mode)
        assert status == 422 and snapshot() == before, (mode, status, result)
    before = snapshot()
    status, result = req('tt30queue', dict(arrival, bags=101))
    assert status == 422 and snapshot() == before, result
    blocked = {**initial, 'tt30slips': json.dumps([dict(queueId=10101)])}
    seed(blocked)
    before = snapshot()
    status, result = req('tt30queue', arrival)
    assert status == 422 and 'Pohanch' in result['error'] and snapshot() == before, result
    valued = copy.deepcopy(initial)
    books.write_text(json.dumps({'journals':{},'productionCostRuns':{'COST1':{'entity':'TTI','date':'2026-10-17'}}}))
    seed(valued);before=snapshot()
    status,result=req('tt30prod',production)
    assert status==422 and 'cost' in result['error'] and snapshot()==before,result
    books.write_bytes(financial)
    books.write_text(json.dumps({'journals':{},'productionCostRuns':{'OTHER-COST':{'entity':'TTI','date':'2026-10-17','calculation':{'runIds':[99999]}}}}))
    seed(initial)
    status,result=req('tt30prod',production,requestKey='unrelated-approved-cost')
    assert status==200,(status,result)
    books.write_bytes(financial)
    dispatched = copy.deepcopy(json.loads(initial['tt35localsales'])[0])
    dispatched['loads']=[{'id':'LOAD-1','kg':500,'printed':True}]
    seed({**initial,'tt35localsales':json.dumps([dispatched])});before=snapshot()
    status,result=req('tt35localsales',dispatched)
    assert status==422 and 'gate pass' in result['error'] and snapshot()==before,result
    seed(initial)
    status, result = req('tt30queue', arrival)
    assert status == 200, (status, result)
    assert json.loads(result['values']['tt30queue']) == [other]
    assert 'tt40milldeletions' not in result['values']
    assert json.loads(stored()['values']['tt99keep']) == {'untouched': True}
    before = snapshot()
    status, retry = req('tt30queue', arrival)
    assert status == 200 and retry['receipt'] == result['receipt'] and snapshot() == before
    # The private receipt retains the original, actor and reason.
    archived = json.loads(stored()['values']['tt40milldeletions'])
    assert archived[0]['before'] == arrival and archived[0]['userId'] == 7
    status, result = req('tt30prod', production, requestKey='production-delete-123456')
    assert status == 200, (status, result)
    assert json.loads(result['values']['tt30prod']) == [] and json.loads(result['values']['tt36bagissues']) == []
    assert books.read_bytes() == financial
    deleted_state=stored()['values']
    # Removing unconsumed receipts adjusts the actual received counter.
    state = stored()['values']
    state['tt36bagissues'] = '[]'
    seed(state)
    status, result = req('tt33bagreceipts', receipt, requestKey='receipt-delete-12345678')
    assert status == 200, (status, result)
    assert json.loads(result['values']['tt30bags'])[0]['received'] == 0 and json.loads(result['values']['tt33bagreceipts']) == []
    consumed=copy.deepcopy(initial)
    consumed['tt35localsales']=json.dumps([{'id':'SALE-USED','entity':'TTI','millName':'TTI Rice Mills','product':'B3','loads':[{'kg':100}]}])
    seed(consumed);before=snapshot()
    status,result=req('tt30prod',production,requestKey='consumed-production-delete')
    assert status==422 and 'insufficient' in result['error'] and snapshot()==before,result
    billed=copy.deepcopy(initial);billed['tt30prod']='[]';billed['tt36bagissues']='[]'
    books.write_text(json.dumps({'journals':{},'bagSupplierBills':{'B1':{'entity':'TTI','poNo':'PO-1','lineKey':'L1','journalId':'J1','status':'Posted'}}}))
    seed(billed);before=snapshot()
    status,result=req('tt33bagreceipts',receipt,requestKey='posted-bag-bill-delete')
    assert status==422 and 'supplier bill' in result['error'] and snapshot()==before,result
    books.write_bytes(financial)
    unprinted=copy.deepcopy(json.loads(initial['tt35localsales'])[0])
    unprinted['product']='B3';unprinted['loads']=[{'kg':100,'bridgeId':'LSL-UNPRINTED-12345','gatePassRef':'LGP-LS-00001-1'}]
    seed({**initial,'tt35localsales':json.dumps([unprinted])})
    status,result=req('tt35localsales',unprinted,requestKey='unprinted-local-sale-delete')
    assert status==200,(status,result)
    seed({**initial,'tt35localsales':json.dumps([unprinted]),'tt39accountsoutbox':json.dumps([{'entity':'TTI','sourceKey':'LOCAL|LS-00001|LSL-UNPRINTED-12345','status':'Pending'}])});before=snapshot()
    status,result=req('tt35localsales',unprinted,requestKey='queued-local-sale-delete')
    assert status==422 and 'handoff' in result['error'] and snapshot()==before,result
    # Return to the earlier successful source deletion state.
    seed(deleted_state)
    # A local sale without downstream handoffs can be removed.
    sale = json.loads(initial['tt35localsales'])[0]
    status, result = req('tt35localsales', sale, requestKey='local-sale-delete-12345')
    assert status == 200 and json.loads(result['values']['tt35localsales']) == [], (status, result)
    assert books.read_bytes() == financial
    # A deleted identity cannot be reintroduced by a stale collection save.
    (root / 'guard.php').write_text("<?php require __DIR__.'/inventory_reconciliation.php';$s=json_decode(file_get_contents(__DIR__.'/data/operations.json'),true);try{tt_inv_guard_deleted_source($s['values'],'tt30queue',"+json.dumps(json.dumps([arrival]))+");exit(1);}catch(DomainException $e){echo 'BLOCKED';}")
    if MYSQL:
        source.write_text(json.dumps(stored()))
    guard = subprocess.run(['php', str(root / 'guard.php')], env=env, text=True, capture_output=True, check=True)
    assert guard.stdout == 'BLOCKED', guard
    print('PASS Mill deletion permissions, stale-entry protection, Pohanch dependency, original audit, source-only effects, stock/cash separation, receipts, local sales and retry on '+('MySQL' if MYSQL else 'file storage'))
