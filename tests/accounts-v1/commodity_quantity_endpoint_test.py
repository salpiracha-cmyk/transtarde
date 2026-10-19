"""Read-only endpoint checks: original quantities, report/company grants and source failures."""
import json,pathlib,shutil,subprocess,tempfile
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='commodity-qty-') as tmp:
 root=pathlib.Path(tmp);shutil.copytree(ROOT/'api',root/'api');shutil.copytree(ROOT/'accounts',root/'accounts');(root/'data').mkdir()
 for name in ['inventory_reconciliation.php','product_stage.php']:shutil.copyfile(ROOT/name,root/name)
 (root/'auth_store.php').write_text('''<?php
define('TT_DATA_DIR',__DIR__.'/data');function tt_require_login(){return ['role'=>'Staff'];}
function tt_user_can_open_module($u,$m){return ($_GET['mode']??'')!=='no-module';}
function tt_user_can_module_action($u,$m,$i,$a){return $m==='Accounts'&&$i==='reports'&&$a==='View'&&($_GET['mode']??'')!=='no-icon';}
function tt_user_can_access_entity($u,$e,$a){return $e==='TTI';}function tt_release_read_session(){}
''')
 (root/'runner.php').write_text("<?php $r=json_decode(stream_get_contents(STDIN),true);$_GET=$r['query'];$_SERVER['REQUEST_METHOD']=$r['method'];register_shutdown_function(function(){fwrite(STDERR,'STATUS:'.(http_response_code()?:200));});require __DIR__.'/api/commodity_quantity_report.php';")
 def req(mode='',entity='TTI',method='GET'):
  r=subprocess.run(['php',str(root/'runner.php')],input=json.dumps({'query':{'entity':entity,'from':'2026-10-01','asOf':'2026-10-15','mode':mode},'method':method}),text=True,capture_output=True)
  assert r.returncode==0,(r.stdout,r.stderr)
  return int(r.stderr.rsplit('STATUS:',1)[1]),json.loads(r.stdout)
 books={'revision':7,'journals':{},'historicalExportOpenings':{'BF':{'entity':'TTI','weightKg':999999}}}
 ops={'values':{'transtrade_export_v3_operational':json.dumps({'contracts':[],'shipments':[]})}}
 bp=root/'data/accounts.json';op=root/'data/operations.json';bp.write_text(json.dumps(books));op.write_text(json.dumps(ops));before=(bp.read_bytes(),op.read_bytes())
 status,d=req();assert status==200 and d['rows']==[] and d['accountsRevision']==7,d
 for mode in ['no-module','no-icon']:assert req(mode=mode)[0]==403
 assert req(entity='BRM')[0]==403 and req(method='POST')[0]==405
 assert (bp.read_bytes(),op.read_bytes())==before
 op.write_text('{"values":');assert req()[0]==422
 op.unlink();assert req()[0]==500 and not op.exists()
 print('PASS commodity endpoint: report/company authority, GET only, source failure without false empty report, BF exclusion and zero writes')
