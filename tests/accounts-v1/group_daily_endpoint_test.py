"""Exercise real Daily endpoint snapshots and conflicts against isolated source files."""
import json, pathlib, shutil, subprocess, tempfile
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='daily-endpoint-') as tmp:
 root=pathlib.Path(tmp);shutil.copytree(ROOT/'api',root/'api');shutil.copytree(ROOT/'accounts',root/'accounts');(root/'data').mkdir()
 for name in ['inventory_reconciliation.php','product_stage.php']:shutil.copyfile(ROOT/name,root/name)
 (root/'auth_store.php').write_text('''<?php
define('TT_DATA_DIR',__DIR__.'/data');define('TT_DB_HOST','');
function tt_require_login(){return ['id'=>1,'username'=>'Fixture','role'=>($_GET['mode']??'')==='super'?'Super Admin':'Staff'];}
function tt_user_can_module_action($u,$m,$i,$a){return ($_GET['mode']??'')!=='read'||$a==='View';}
function tt_user_can_access_entity($u,$e,$a){return ($_GET['mode']??'')!=='limited'||$e!=='TG';}
function tt_release_read_session(){}function tt_verify_csrf($v){return $v==='fixture';}function tt_accounts_input(){return $GLOBALS['fixtureBody'];}
''')
 (root/'runner.php').write_text("""<?php
$r=json_decode(stream_get_contents(STDIN),true);$_GET=$r['query'];$_SERVER['REQUEST_METHOD']=$r['body']===null?'GET':'POST';$GLOBALS['fixtureBody']=json_encode($r['body']);register_shutdown_function(function(){fwrite(STDERR,'STATUS:'.(http_response_code()?:200));});require __DIR__.'/api/group_management.php';
""")
 def req(body=None,mode='all'):
  data=None if body is None else {'csrf':'fixture','kind':'DAILY','date':'2026-10-17',**body}
  r=subprocess.run(['php',str(root/'runner.php')],input=json.dumps({'query':{'kind':'DAILY','date':'2026-10-17','mode':mode},'body':data}),text=True,capture_output=True)
  assert r.returncode==0,(r.stdout,r.stderr)
  return int(r.stderr.rsplit('STATUS:',1)[1]),json.loads(r.stdout)
 def save(section,version=0,key=None,**extra):return {'action':'save-section','section':section,'version':version,'requestKey':key or 'fixture-save-'+section,**extra}
 books={'revision':7,'journals':{'BOOK1':{'id':'BOOK1','entity':'TTI','status':'Posted','date':'2026-10-01','lines':[{'account':'1110','debit':100,'credit':0}]}}}
 ops={'revision':4,'values':{'transtrade_export_v3_operational':json.dumps({'contracts':[{'ref':'TG/C1','seller':'TG','date':'2026-09-01','product':'Test Rice','commodity':'RICE','qty':100,'received':True}],'shipments':[]})}}
 bp=root/'data/accounts.json';op=root/'data/operations.json';bp.write_text(json.dumps(books));op.write_text(json.dumps(ops));before=(bp.read_bytes(),op.read_bytes())
 status,d=req();assert status==200,d;assert d['dailySource']['accountsRevision']==7 and d['dailySource']['operationsRevision']==4
 assert not (root/'data/group-management.json').exists() and not (root/'data/group-management.json.lock').exists(),'GET wrote report storage'
 assert req(mode='limited')[0]==403
 assert req(save('header',data={'rates':{}}))[0]==200
 fingerprint=d['dailySource']['fingerprint'];sid=d['dailySource']['salesContracts'][0]['id']
 config={'buckets':[{'id':'RICE1','label':'Reviewed Test Rice','commodity':'RICE','sales':[{'id':sid,'recoveryPct':60,'finishedAllocations':[]}]}]}
 body=save('daily',sourceFingerprint=fingerprint,data={'config':config,'source':{'fingerprint':'forged','financials':{'rows':[]}}})
 status,d=req(body);assert status==200,d;part=d['record']['sections']['daily'];assert part['data']['source']['fingerprint']==fingerprint and part['data']['source']['financials']['rows'][0]['amount']==100
 assert part['calculation']['positions'][0]['positionMinMT']==round(-100/.6,6),part
 assert d['summary']['totalPKR']==100 and d['summary']['complete'] is False
 report=root/'data/group-management.json';saved=report.read_bytes()
 assert req(body)[1]['result']['duplicate'] and report.read_bytes()==saved,'Retry changed snapshot'
 assert req(save('daily',1,'fixture-stale-001',sourceFingerprint='old',data={'config':config}))[0]==409 and report.read_bytes()==saved
 assert req(save('daily',0,'fixture-version-001',sourceFingerprint=fingerprint,data={'config':config}))[0]==409
 assert req(save('daily',1,'fixture-review-001',sourceFingerprint=fingerprint,data={'config':config},complete=True))[0]==422
 status,d=req(save('daily',1,'fixture-complete-001',sourceFingerprint=fingerprint,data={'config':config},complete=True,reviewConfirmed=True));assert status==200,d;assert d['summary']['complete']
 assert req(save('daily',2,'fixture-reason-001',sourceFingerprint=fingerprint,data={'config':config}))[0]==422
 assert req(save('daily',2,'fixture-permission-001',sourceFingerprint=fingerprint,data={'config':config},reason='Review'),mode='read')[0]==403
 assert req(save('financials',key='fixture-invalid-001',rows=[]))[0]==422
 row={'id':d['source']['rows'][0]['id'],'label':'Repeated bank','amount':100,'currency':'PKR'}
 assert req(save('adjustments',key='fixture-duplicate-001',rows=[row]))[0]==422,'Bank counted twice'
 assert (bp.read_bytes(),op.read_bytes())==before,'Daily report changed canonical books or stock'
 status,d=req(save('adjustments',key='fixture-adjustment-001',rows=[{'id':'MANUAL-REVIEW','label':'Reviewed missing cost','amount':-10,'currency':'PKR'}]));assert status==200,d;assert d['summary']['totalPKR']==90 and not d['summary']['complete'],'Draft adjustment was labelled complete'
 status,d=req(save('adjustments',1,'fixture-adjustment-002',rows=[{'id':'MANUAL-REVIEW','label':'Reviewed missing cost','amount':-10,'currency':'PKR'}],complete=True,reviewConfirmed=True));assert status==200,d;assert d['summary']['complete']
 # Later source changes are visible for a deliberate new save but never rewrite the retained snapshot.
 books['revision']=8;books['journals']['BOOK1']['lines'][0]['debit']=999;bp.write_text(json.dumps(books))
 status,d=req();assert status==200,d;assert d['dailySource']['fingerprint']!=fingerprint and d['summary']['totalPKR']==90
 assert d['record']['sections']['daily']['data']['source']['accountsRevision']==7
 assert req(save('daily',2,'fixture-moved-001',sourceFingerprint=fingerprint,data={'config':config},reason='Review'))[0]==409
 # Completion also requires every financial currency's saved exchange rate.
 books['journals']['TG1']={'id':'TG1','entity':'TG','status':'Posted','date':'2026-10-01','lines':[{'account':'1110','debit':10,'credit':0}]};books['revision']=9;bp.write_text(json.dumps(books));status,d=req();assert status==200,d
 latest=d['dailySource']['fingerprint'];saved=report.read_bytes();status,d=req(save('daily',2,'fixture-fx-001',sourceFingerprint=latest,data={'config':config},complete=True,reviewConfirmed=True,reason='Review new source'));assert status==422,d;assert report.read_bytes()==saved,'Missing FX completed a Daily report'
 # Conflicting native loading routes cannot count a container twice or replace a saved report.
 load={'id':'LOAD1','container':'ABCD1234567','date':'2026-10-01','weight':20000}
 ops['values']['tt30ship']=json.dumps([{'id':'SHIP1','entity':'TTI','millName':'Fixture mill','contractRef':'TG/C1','containers':[load]}])
 ops['values']['tt35exload']=json.dumps([{'id':'OUT1','container':'ABCD123456-8','contractRef':'TG/C1','date':'2026-10-01','kg':20000}]);op.write_text(json.dumps(ops))
 canonical=(bp.read_bytes(),op.read_bytes());saved=report.read_bytes()
 status,d=req();assert status==422 and 'same physical container' in d['error'],d
 status,d=req(save('daily',2,'fixture-duplicate-load',sourceFingerprint=latest,data={'config':config},reason='Review new source'));assert status==422,d
 assert report.read_bytes()==saved and (bp.read_bytes(),op.read_bytes())==canonical,'Rejected loading conflict changed original sources or saved report'
 # Missing operational source cannot become a zero position.
 op.unlink();assert req()[0]==500
 print('PASS Daily endpoint: real source snapshots, forged-source rejection, version/source conflicts, idempotent retries, explicit review, permission checks, retained historical balances and zero stock/book writes')
