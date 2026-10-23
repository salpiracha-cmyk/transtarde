"""Exercise authorized requests against disposable canonical Accounts and Exports fixtures."""
import json, pathlib, shutil, subprocess, tempfile
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='directors-trading-') as tmp:
 root=pathlib.Path(tmp)
 for name in ['api','accounts','directors']:shutil.copytree(ROOT/name,root/name)
 (root/'data').mkdir()
 (root/'auth_store.php').write_text('''<?php
define('TT_DATA_DIR',__DIR__.'/data');define('TT_DB_HOST','');
function tt_require_login(){return ['id'=>1,'username'=>'Fixture','role'=>'Staff'];}
function tt_user_can_module_action($u,$m,$i,$a){$mode=$_GET['mode']??'all';if($mode==='deny')return false;if($mode==='read')return $a==='View';if($mode==='cost')return $m==='Directors'&&$i==='costing';if($mode==='exports')return $m==='Exports'&&$i==='loading';return true;}
function tt_user_can_access_entity($u,$e,$a){return $e==='TTI';}
function tt_ensure_data_dir(){}function tt_release_read_session(){}function tt_verify_csrf($v){return $v==='fixture';}function tt_accounts_input(){return $GLOBALS['fixtureBody'];}
function tt_list_masters(){return ['banks'=>[],'business_parties'=>[['id'=>'KAS','values'=>['KAS Commodities','','Supplier']]]];}
function tt_next_post_id($rows,...$args){return 'POST-'.(count($rows)+1);}
''')
 contract={'ref':'TTI/AMT/1','seller':'TTI','date':'2026-10-01','product':'IRRI-6','qty':100,'currency':'USD','incoterm':'FOB','packings':[{'price':500}]}
 lot={'id':'L1','kind':'lot','contractRef':'TTI/AMT/1','lotId':'TTI/AMT/1/L01','seller':'TTI','commercial':{'invoiceNo':'INV1','date':'2026-10-02','saved':True},'millActuals':[{'netKg':100000}]}
 operations={'values':{'transtrade_export_v3_operational':json.dumps({'contracts':[contract],'shipments':[lot]})}}
 (root/'data/operations.json').write_text(json.dumps(operations));ops_before=(root/'data/operations.json').read_bytes()
 path=root/'data/accounts.json';path.write_text(json.dumps({'revision':0,'journals':{}}));before=path.read_bytes()
 (root/'runner.php').write_text('''<?php
$r=json_decode(stream_get_contents(STDIN),true);$_GET=$r['query'];$_SERVER['REQUEST_METHOD']=$r['body']===null?'GET':'POST';$GLOBALS['fixtureBody']=json_encode($r['body']);register_shutdown_function(function(){fwrite(STDERR,'STATUS:'.(http_response_code()?:200));});require __DIR__.'/api/directors_trading.php';
''')
 seq=0
 def req(body=None,**query):
  global seq
  seq+=1;q={'entity':'TTI','asOf':'2026-10-23',**query}
  if body is not None:body={'csrf':'fixture','entity':'TTI','asOf':'2026-10-23','requestKey':'fixture-request-'+str(seq),**body}
  r=subprocess.run(['php',str(root/'runner.php')],input=json.dumps({'query':q,'body':body}),text=True,capture_output=True)
  if 'STATUS:500' in r.stderr:print(r.stderr)
  try:return int(r.stderr.rsplit('STATUS:',1)[1]),json.loads(r.stdout)
  except Exception:raise AssertionError((r.returncode,r.stdout,r.stderr))
 status,d=req();assert status==200,d;assert path.read_bytes()==before,'GET wrote Accounts'
 assert req(mode='deny')[0]==403
 assert req(entity='BRM')[0]==403
 status,d=req(area='costing',mode='cost');assert status==200 and 'deals' not in d and 'cashflow' not in d and 'banks' not in d,d
 status,d=req(origin='exports',mode='exports');assert status==200 and 'costings' not in d and 'banks' not in d,d
 deal={'action':'save_deal','date':'2026-10-01','partyId':'KAS','direction':'PURCHASE','arrangement':'FIXED','price':400,'quantityMT':100,'incoterm':'FOB','fx':280,'coverageMT':100,'contractRef':'TTI/AMT/1','reference':'KAS-1','requestKey':'create-contract-fixed'}
 assert req(deal,mode='read')[0]==403
 assert req({**deal,'csrf':'bad'})[0]==419
 status,d=req(deal);assert status==200,d;deal_id=d['result']['id'];saved=path.read_bytes()
 status,d=req(deal);assert status==200 and d['result']['id']==deal_id and path.read_bytes()==saved,'Replay changed records'
 assert req({**deal,'price':401})[0]==409
 assert req(deal,origin='exports',mode='exports')[0]==403
 load={'action':'loading','id':deal_id,'version':1,'shipmentId':'L1','quantityMT':40,'date':'2026-10-02'}
 status,d=req(load,origin='exports',mode='exports');assert status==200,d
 assert 'price' not in d['deals'][0] and 'working' not in d['deals'][0],d
 assert req({**load,'requestKey':'stale-version-request'})[0]==409
 status,d=req({'action':'post_settlement','id':deal_id,'version':2});assert status==200,d
 assert d['deals'][0]['bookBalancePKR']==-4480000,d
 status,d=req({'action':'payment','id':deal_id,'version':3,'amountPKR':79000,'bankId':'CASH|TTI','reference':'CASH-1'});assert status==200,d
 assert d['deals'][0]['bookBalancePKR']==-4401000,d
 assert req({'action':'payment','id':deal_id,'version':4,'amountPKR':5000000,'bankId':'CASH|TTI','reference':'CASH-2'})[0]==422
 profile=json.loads((ROOT/'directors/costing-profiles.json').read_text())[0]
 costing={**profile,'date':'2026-10-23','fx':280,'whtPct':1.25,'bankPct':.1,'quantityMT':100,'incoterm':'FOB','packingAdjustment':0,'millVariety':'IRRI-6'}
 status,d=req({'action':'save_costing','input':costing},area='costing',mode='cost');assert status==200,d
 assert d['costings'][0]['calculation']['costs']['udhaar'] is None
 assert req({'action':'save_deal',**deal},area='costing',mode='cost')[0]==403
 store=json.loads(path.read_text());assert len(store['journals'])==2 and store['managementCostingInputs'],store
 for journal in store['journals'].values():assert sum(l['debit'] for l in journal['lines'])==sum(l['credit'] for l in journal['lines'])
 assert (root/'data/operations.json').read_bytes()==ops_before,'Exporter entry changed original mill/exports stocks'
 print('PASS Directors trading endpoint: scoped reads, original stores, CSRF, permissions, company restrictions, duplicate keys, stale versions, posted settlement, partial payment and cost linkage')
