"""Actual PHP endpoint requests with disposable sources and books; no sockets."""
import json
import pathlib
import shutil
import subprocess
import tempfile

ROOT = pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='mill-liability-endpoint-') as tmp:
    root=pathlib.Path(tmp);(root/'api').mkdir();(root/'data').mkdir()
    for name in ['mill_liabilities.php','mill_liabilities_core.php']:shutil.copy(ROOT/'api'/name,root/'api'/name)
    (root/'auth_store.php').write_text('''<?php
define('TT_DATA_DIR',__DIR__.'/data');
function tt_require_login(){return ['id'=>7,'full_name'=>'Reviewer'];}
function tt_user_can_open_module($u,$m){return true;}
function tt_user_can_module_action($u,$m,$i,$a){return ($_GET['mode']??'')!=='read'||$a==='View';}
function tt_user_can_access_entity($u,$e,$a){return $e==='TTI';}
function tt_verify_csrf($v){return $v==='fixture';}
function tt_release_read_session(){}function tt_ensure_data_dir(){}
function tt_accounts_input(){return $GLOBALS['fixtureBody'];}
function tt_list_masters(){return json_decode(file_get_contents(__DIR__.'/masters.json'),true);}
function tt_next_post_id($journals,...$a){return 'POST-'.(count($journals)+1);}
''')
    (root/'runner.php').write_text('''<?php
$r=json_decode(stream_get_contents(STDIN),true);$_GET=$r['query'];$_SERVER['REQUEST_METHOD']=$r['body']===null?'GET':'POST';$GLOBALS['fixtureBody']=json_encode($r['body']);
register_shutdown_function(function(){fwrite(STDERR,'STATUS:'.(http_response_code()?:200));});require __DIR__.'/api/mill_liabilities.php';
''')
    masters={'mills':[{'id':'mills-1','values':['TTI Rice Mills','','Own Mill','','','Active','','TTI']}], 'business_parties':[{'id':'KE','values':['K-Electric','','Service Provider','','','','','','','','Active']}]}
    (root/'masters.json').write_text(json.dumps(masters))
    bill=dict(id='KE-1',entity='TTI',millId=1,millName='TTI Rice Mills',date='2026-10-17',readFrom='2026-09-01',readTo='2026-09-30',ref='KE-SEP',party='K-Electric',amount=1000)
    source=root/'data/operations.json';books=root/'data/accounts.json'
    def sources():source.write_text(json.dumps({'values':{'tt38kebills':json.dumps([bill])}}))
    sources();books.write_text(json.dumps({'revision':7,'journals':{},'supplierBills':{}}));before=books.read_bytes();source_before=source.read_bytes()
    def req(body=None,mode='all',entity='TTI'):
        r=subprocess.run(['php',str(root/'runner.php')],input=json.dumps({'query':{'entity':entity,'mode':mode},'body':body}),capture_output=True,text=True,check=True)
        return int(r.stderr.rsplit('STATUS:',1)[1]),json.loads(r.stdout)
    status,d=req();assert status==200 and len(d['rows'])==1,d;row=d['rows'][0]
    assert books.read_bytes()==before and source.read_bytes()==source_before
    assert req(entity='BRM')[0]==403
    post=dict(csrf='fixture',action='post',entity='TTI',kind='ELECTRICITY',sourceId='KE-1',fingerprint=row['fingerprint'],requestKey='fixture-post-00001',amount=99999)
    assert req(post,mode='read')[0]==403
    assert req(dict(post,csrf='wrong'))[0]==419
    assert req(dict(post,fingerprint='stale'))[0]==422
    assert books.read_bytes()==before
    status,d=req(post);assert status==200,d;record=d['result']['record'];saved=books.read_bytes();s=json.loads(saved)
    assert s['journals'][record['journalId']]['totalDebit']==1000,'Client amount changed source amount'
    assert source.read_bytes()==source_before
    status,d=req(post);assert status==200 and d['result']['replayed'] and books.read_bytes()==saved,d
    assert req(dict(post,amount=4))[0]==422,'Save identity accepted different body'
    # A changed source must be explicitly reversed/replaced, with old journal retained.
    bill['amount']=1200;sources();status,d=req();row=d['rows'][0]
    replace=dict(csrf='fixture',action='replace',entity='TTI',kind='ELECTRICITY',sourceId='KE-1',fingerprint=row['fingerprint'],postedFingerprint=record['fingerprint'],requestKey='fixture-amend-0001',correctionDate='2026-10-17',reason='Correct received bill')
    status,d=req(replace);assert status==200,d;after=books.read_bytes();s=json.loads(after)
    assert len(s['journals'])==3 and s['journals'][record['journalId']]['totalDebit']==1000
    assert req(replace)[1]['result']['replayed'] and books.read_bytes()==after,'Replacement retry duplicated journals'
    assert source.read_bytes()!=source_before and json.loads(source.read_text())['values']['tt38kebills']==json.dumps([bill]),'Endpoint changed original source'
    books.unlink();assert req()[0]==200 and not books.exists(),'GET created canonical books'
    print('PASS real Mill liability GET zero writes, company/permission/CSRF checks, source-owned amount, stale/reforged requests, canonical post, immutable amendment and lost-acknowledgement replay')
