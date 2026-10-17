"""Real report endpoint executed in a disposable PHP CLI request harness; no production writes."""
import json,shutil,subprocess,tempfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
auth=(ROOT/'auth_store.php').read_text();a=auth.index('function tt_user_can_open_module(');b=auth.index('function tt_user_can_access_masters(',a);helpers=auth[a:b]
a=auth.index('function tt_user_can_access_entity(');b=auth.index('function tt_user_landing_url(',a);helpers+=auth[a:b]
with tempfile.TemporaryDirectory(prefix='rice-ledger-endpoint-') as directory:
 root=Path(directory);(root/'api').mkdir();(root/'data').mkdir()
 for file in ['arrival_withdrawal_core.php','rice_ledger.php','rice_ledger_core.php','mill_recovery_core.php']:shutil.copy(ROOT/'api'/file,root/'api'/file)
 for file in ['inventory_reconciliation.php','product_stage.php']:shutil.copy(ROOT/file,root/file)
 (root/'auth_store.php').write_text("<?php define('TT_DATA_DIR',__DIR__.'/data');function tt_release_read_session(){} function tt_require_login(){return json_decode(file_get_contents(__DIR__.'/user.json'),true);}"+helpers)
 (root/'request.php').write_text("<?php $_SERVER['REQUEST_METHOD']=$argv[1];$_GET=json_decode(file_get_contents(__DIR__.'/query.json'),true);register_shutdown_function(function(){file_put_contents(__DIR__.'/status.json',json_encode(http_response_code()?:200));});require __DIR__.'/api/rice_ledger.php';")
 source=root/'data/operations.json';source.write_text(json.dumps({'values':{'tt30slips':json.dumps([{'id':'A','entity':'TTI','millName':'TTI Rice Mills','arrivalDate':'2026-09-01','displayName':'Raw','payableWeight':100},{'id':'B','entity':'TTI','millName':'Other Mill','arrivalDate':'2026-09-01','displayName':'PRIVATE','payableWeight':900}])}}))
 owner={'role':'Super Admin'};mill={'role':'Mill Manager','location':'TTI Rice Mills','permissions':{'Mill':{'reports':['View']}}};accounts={'role':'Accounts','permissions':{'Accounts':{'reports':['View'],'entity-tti':['View']}}}
 query={'entity':'TTI','mill':'TTI Rice Mills','movement':'All','from':'2026-09-01','to':'2026-09-30'}
 def request(user=owner,q=query,method='GET',expected=200):
  before=source.read_bytes();(root/'user.json').write_text(json.dumps(user));(root/'query.json').write_text(json.dumps(q));r=subprocess.run(['php',str(root/'request.php'),method],capture_output=True,text=True);assert r.returncode==0,r.stderr;rj=json.loads(r.stdout);assert json.loads((root/'status.json').read_text())==expected,rj;assert source.read_bytes()==before,'Read-only report must never save';return rj
 assert request()['summaries'][0]['closingKg']==100
 assert request(mill)['products']==['Raw']
 request(mill,{**query,'mill':'Other Mill'},expected=403)
 request(accounts,{**query,'entity':'BRM'},expected=403)
 request({'role':'Staff','permissions':{}},expected=403)
 request(q={**query,'from':'2026-02-30'},expected=422)
 request(q={**query,'from':'2026-10-01'},expected=422)
 request(q={**query,'mill':['bad']},expected=422)
 request(q={**query,'movement':'wrong'},expected=422)
 request(method='POST',expected=405)
 source.write_text('{broken');request(expected=500)
 source.write_text(json.dumps({'values':{'tt30ship':'{broken'}}));request(expected=500)
 print('PASS Rice Ledger endpoint: real GET handler, company/location/permission denials, calendar/filter validation, corrupt-source errors and byte-unchanged operational storage.')
