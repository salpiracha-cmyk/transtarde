"""Actual source amendment controller, zero financial writes, lock contention and replay."""
import fcntl
import json
import os
import pathlib
import shutil
import subprocess
import tempfile

ROOT=pathlib.Path(__file__).resolve().parents[2]
MYSQL=os.environ.get('TT_MSA_TEST_MYSQL')=='1'
if MYSQL:
    assert os.environ.get('TT_QA_MYSQL')=='1' and os.environ.get('TT_DB_HOST')=='127.0.0.1' and os.environ.get('TT_DB_NAME','').startswith('transtrade_qa_')
env=dict(os.environ) if MYSQL else {k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
with tempfile.TemporaryDirectory(prefix='mill-source-amend-') as tmp:
    root=pathlib.Path(tmp);shutil.copytree(ROOT/'api',root/'api');(root/'data').mkdir()
    (root/'auth_store.php').write_text('''<?php
define('TT_DATA_DIR',__DIR__.'/data');
function tt_require_login(){return ['id'=>7,'full_name'=>'Actual user','role'=>'Staff','location'=>($_GET['mode']??'')==='location'?'Other Mill':'TTI Rice Mills','actions'=>($_GET['mode']??'')==='read'?['View']:['View','Create','Edit']];}
function tt_user_can_open_module($u,$m){return true;}
function tt_user_can_module_action($u,$m,$i,$a){return in_array($a,$u['actions'],true);}
function tt_user_can_access_entity($u,$e,$a){return $e==='TTI';}
function tt_verify_csrf($v){return $v==='fixture';}
function tt_release_read_session(){}
function tt_accounts_input(){return $GLOBALS['fixtureBody'];}
function tt_list_masters(){return ['mills'=>[['id'=>'mills-1','values'=>['TTI Rice Mills','','Own Mill','','','Active','','TTI']]],'business_parties'=>[['id'=>'KE','values'=>['K-Electric','','Service Provider','','','','','','','','Active']]]];}
''')
    (root/'runner.php').write_text('''<?php
$r=json_decode(stream_get_contents(STDIN),true);$_GET=$r['query'];$_SERVER['REQUEST_METHOD']=$r['body']===null?'GET':'POST';$GLOBALS['fixtureBody']=json_encode($r['body']);
register_shutdown_function(function(){fwrite(STDERR,'STATUS:'.(http_response_code()?:200));});require __DIR__.'/api/mill_source_amend.php';
''')
    bill=dict(id='KE1',entity='TTI',millId=1,millName='TTI Rice Mills',party='K-Electric',date='2026-10-17',readFrom='2026-09-01',readTo='2026-09-30',ref='KE-SEP',amount=1000)
    source=root/'data/operations.json';books=root/'data/accounts.json'
    original={'revision':4,'values':{'tt38kebills':json.dumps([bill]),'tt30prod':'[{"id":"UNRELATED"}]'},'meta':{'tt38kebills':{'version':3}}}
    sql=root/'database.php'
    sql.write_text(r'''<?php
$p=new PDO('mysql:host='.getenv('TT_DB_HOST').';dbname='.getenv('TT_DB_NAME').';charset=utf8mb4',getenv('TT_DB_USER'),getenv('TT_DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if($argv[1]==='seed'){
 $p->exec('DELETE FROM tt_operation_records');$p->exec('DELETE FROM tt_operation_history');
 $q=$p->prepare("INSERT INTO tt_operation_records VALUES(?,?,?,NOW(6),'Fixture',7,'Mill')");
 foreach(json_decode($argv[2],true)as$key=>$value)$q->execute([$key,$value,$key==='tt38kebills'?3:1]);
}elseif($argv[1]==='cleanup'){$p->exec('DELETE FROM tt_operation_records');$p->exec('DELETE FROM tt_operation_history');}
elseif($argv[1]==='cost'){
 $p->exec("INSERT INTO tt_operation_records VALUES('tt38reprocessbills','[{\"id\":\"MYSQL-RP1\",\"amount\":25}]',1,NOW(6),'Fixture',7,'Mill') ON DUPLICATE KEY UPDATE payload=VALUES(payload)");
 require __DIR__.'/api/mill_recovery_core.php';echo json_encode(mr_read_operations_source(__DIR__.'/data/operations.json'));
}else{
 $out=['values'=>[],'meta'=>[]];foreach($p->query('SELECT storage_key,payload,version FROM tt_operation_records ORDER BY storage_key')->fetchAll(PDO::FETCH_ASSOC)as$r){$out['values'][$r['storage_key']]=$r['payload'];$out['meta'][$r['storage_key']]=['version'=>(int)$r['version']];}
 $out['history']=$p->query('SELECT storage_key,version,payload_sha256 FROM tt_operation_history ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);echo json_encode($out);
}
''')
    def sql_run(mode,*args):return subprocess.run(['php',str(sql),mode,*args],env=env,text=True,capture_output=True,check=True).stdout
    if MYSQL:sql_run('seed',json.dumps(original['values']))
    def source_snapshot():return sql_run('read').encode() if MYSQL else source.read_bytes()
    def source_store():return json.loads(source_snapshot())
    source.write_text(json.dumps(original));books.write_text('{"revision":9,"journals":{},"supplierBills":{}}');financial=books.read_bytes();before=source_snapshot()
    def req(body=None,mode='all',entity='TTI'):
        request={'query':dict(collection='tt38kebills',sourceId='KE1',entity=entity,mode=mode),'body':body}
        out=subprocess.run(['php',str(root/'runner.php')],input=json.dumps(request),text=True,capture_output=True,env=env,timeout=8,check=True)
        return int(out.stderr.rsplit('STATUS:',1)[1]),json.loads(out.stdout)
    status,d=req();assert status==200 and d['canEdit'],d
    assert req(mode='read')[1]['canEdit'] is False
    assert req(mode='location')[0]==422
    assert req(entity='BRM')[0]==422
    assert source_snapshot()==before and books.read_bytes()==financial,'Read changed source or financial books'
    body=dict(csrf='fixture',collection='tt38kebills',entity='TTI',sourceId='KE1',fingerprint=d['fingerprint'],requestKey='actual-source-amend-0001',reason='Correct received bill',changes={'amount':1200})
    assert req(dict(body,csrf='wrong'))[0]==419
    assert req(body,mode='read')[0]==422
    assert req(dict(body,entity='BRM'))[0]==422
    assert source_snapshot()==before
    # Existing cost approval takes Accounts first. Source amendment must release
    # its operation lock immediately rather than deadlock in the opposite order.
    with books.open('r') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX)
        status,failed=req(body);assert status==422 and 'another action' in failed['error'],failed
        assert source_snapshot()==before and books.read_bytes()==financial
    status,saved=req(body);assert status==200,saved
    stored=source_store();record=saved['result']['record']
    assert stored['values']['tt30prod']==original['values']['tt30prod'],'Amendment rewrote unrelated draft/source'
    assert record['amount']==1200 and record['_sourceAmendments'][0]['before']==bill
    assert stored['meta']['tt38kebills']['version']==4
    assert len(stored['history'])==1 if MYSQL else stored['revision']==5
    after=source_snapshot();status,replay=req(body);assert status==200 and replay['result']['replayed'] and source_snapshot()==after
    assert books.read_bytes()==financial,'Source correction changed canonical books'
    assert req(dict(body,requestKey='actual-source-amend-0002'))[0]==422 and source_snapshot()==after,'Stale source overwrote a correction'
    # A subsequent paid-source edit is held, while a lost acknowledgement still replays.
    key='MILL|TTI|1|tt38kebills|KE1'
    books.write_text(json.dumps({'millLiabilities':{key:{'billId':'MB1','journalId':'POST1'}},'supplierSettlements':[{'entity':'TTI','status':'Posted','allocations':[{'billId':'MB1','amount':400}]}]}));paid=books.read_bytes()
    status,current=req();assert status==200 and current['canEdit'] is False and 'payment allocations' in current['hold'],current
    body2=dict(body,fingerprint=current['fingerprint'],requestKey='actual-source-amend-0002',changes={'amount':1400})
    assert req(body2)[0]==422 and source_snapshot()==after and books.read_bytes()==paid
    assert req(body)[1]['result']['replayed'] and source_snapshot()==after
    if MYSQL:
        assert 'MYSQL-RP1' in json.loads(sql_run('cost'))['values']['tt38reprocessbills'],'MySQL costing omitted reprocessing bills'
        sql_run('cleanup')
    print(('MYSQL ' if MYSQL else 'FILE ')+'PASS actual amendment controller: zero-write review, scope/Edit/CSRF, nonblocking Accounts dependency lock, source-only amendment, original/actor/version preservation, lost-ack byte-identical replay, stale and paid holds')
