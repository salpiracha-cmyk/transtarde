"""Exercise authorized requests against disposable canonical Accounts and Exports fixtures."""
import json, pathlib, shutil, subprocess, tempfile
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='directors-trading-') as tmp:
 root=pathlib.Path(tmp)
 for name in ['api','accounts','directors']:shutil.copytree(ROOT/name,root/name)
 fixture_local=root/'api/local_sales_control.php';fixture_local.write_text(fixture_local.read_text().replace('catch(Throwable $e){ls_respond',"catch(Throwable $e){fwrite(STDERR,'FIXTURE ERROR: '.$e->getMessage());ls_respond"))
 for name in ['inventory_reconciliation.php','product_stage.php']:shutil.copy(ROOT/name,root/name)
 (root/'data').mkdir()
 (root/'auth_store.php').write_text('''<?php
define('TT_DATA_DIR',__DIR__.'/data');define('TT_DB_HOST','');
function tt_require_login(){if(($_GET['mode']??'')==='mill')return ['id'=>1,'username'=>'Mill','role'=>'Staff','permissions'=>['Mill'=>['local'=>['View','Edit']]]];return ['id'=>1,'username'=>'Fixture','role'=>'Staff','permissions'=>(($_GET['mode']??'all')==='all'?['Accounts'=>'all','Exports'=>['loading'=>['View','Edit']]]:(($_GET['mode']??'')==='exports-restricted'?['Exports'=>['loading'=>['View','Edit']],'Accounts'=>['entity-tti'=>['View','Edit']]]:['Exports'=>['loading'=>['View','Edit']]]))];}
function tt_user_can_open_module($u,$m){return !empty($u['permissions'][$m]);}
function tt_user_can_module_action($u,$m,$i,$a){$mode=$_GET['mode']??'all';if($mode==='mill')return $m==='Mill'&&$i==='local';if($mode==='deny')return false;if($mode==='read')return $a==='View';if($mode==='cost')return $m==='Directors'&&$i==='costing';if(in_array($mode,['exports','exports-restricted']))return $m==='Exports'&&$i==='loading';return true;}
function tt_user_can_access_entity($u,$e,$a){return $e==='TTI';}
function tt_user_can_master($u,$t,$a){return $t==='business_parties'&&!in_array($_GET['mode']??'', ['deny','no-master'])&&((($_GET['mode']??'')!=='read')||$a==='View');}
function tt_ensure_data_dir(){}function tt_release_read_session(){}function tt_verify_csrf($v){return $v==='fixture';}function tt_accounts_input(){return $GLOBALS['fixtureBody'];}
function tt_list_masters(){return ['banks'=>[['id'=>'USD1','values'=>['Company Account','TTI','','TTI','Own bank','','','USD','123','']]],'business_parties'=>[['id'=>'KAS','values'=>['KAS Commodities','','Fellow Exporter']]]];}
function tt_api_upsert_allowed($u,$endpoint,$body,$exists,$entity){return $entity==='TTI';}function tt_api_record_entity_allowed($u,$body,$entity){return $entity==='TTI';}
function tt_bank_can_transact($id){return $id==='USD1';}
function tt_brokery_amount($rate,$kg,$bags){if($rate!==null)throw new RuntimeException('Fixture has no broker');return 0;}
function tt_next_post_id($rows,...$args){return 'POST-'.(count($rows)+1);}
''')
 from permission_fixture import append_permission_helpers
 append_permission_helpers(root)
 contract={'ref':'TTI/AMT/1','seller':'TTI','date':'2026-10-01','product':'IRRI-6','qty':100,'currency':'USD','incoterm':'FOB','packings':[{'price':500}]}
 lot={'id':'L1','kind':'lot','contractRef':'TTI/AMT/1','lotId':'TTI/AMT/1/L01','seller':'TTI','commercial':{'invoiceNo':'INV1','date':'2026-10-02','saved':True},'millActuals':[{'netKg':100000}]}
 operations={'values':{'transtrade_export_v3_operational':json.dumps({'contracts':[contract],'shipments':[lot]})}}
 (root/'data/operations.json').write_text(json.dumps(operations));ops_before=(root/'data/operations.json').read_bytes()
 path=root/'data/accounts.json';path.write_text(json.dumps({'revision':0,'journals':{}}));before=path.read_bytes()
 (root/'runner.php').write_text('''<?php
$r=json_decode(stream_get_contents(STDIN),true);$_GET=$r['query'];$_SERVER['REQUEST_METHOD']=$r['body']===null?'GET':'POST';$GLOBALS['fixtureBody']=json_encode($r['body']);register_shutdown_function(function(){fwrite(STDERR,'STATUS:'.(http_response_code()?:200));});require __DIR__.'/api/'.(($_GET['_endpoint']??'')==='local'?'local_sales_control.php':(($_GET['_endpoint']??'')==='fellow'?'fellow_exporters.php':'directors_trading.php'));
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
 assert req(origin='exports',mode='exports-restricted',entity='BRM')[0]==403,'Explicit company scope was bypassed'
 status,d=req(origin='exports',mode='exports');assert status==200 and 'costings' not in d and 'banks' not in d,d
 assert 'contractPrice' not in d['lots'][0] and set(d['lots'][0])=={'id','contract','lot','invoice','invoiceDate','quantityMT','invoiceSaved'},'Loading-only scope exposed financial/unsupported source fields'
 deal={'action':'save_deal','date':'2026-10-01','partyId':'KAS','direction':'PURCHASE','arrangement':'FIXED','price':400,'quantityMT':100,'incoterm':'FOB','fx':280,'coverageMT':100,'contractRef':'TTI/AMT/1','reference':'KAS-1','requestKey':'create-contract-fixed'}
 assert req(deal,mode='read')[0]==403
 assert req({**deal,'csrf':'bad'})[0]==419
 status,d=req(deal);assert status==200,d;deal_id=d['result']['id'];saved=path.read_bytes()
 status,d=req(deal);assert status==200 and d['result']['id']==deal_id and path.read_bytes()==saved,'Replay changed records'
 assert req({**deal,'price':401})[0]==409
 assert req(deal,origin='exports',mode='exports')[0]==403
 load={'action':'loading','id':deal_id,'version':1,'shipmentId':'L1','quantityMT':40,'date':'2026-10-02'}
 assert req({**load,'adjustments':[{'reason':'forged charge','amountPKR':1000}]},origin='exports',mode='exports')[0]==403,'Loading scope changed financial charges'
 status,d=req(load,origin='exports',mode='exports');assert status==200,d
 assert 'price' not in d['deals'][0] and 'working' not in d['deals'][0],d
 assert req({**load,'requestKey':'stale-version-request'})[0]==409
 status,d=req({'action':'post_settlement','id':deal_id,'version':2});assert status==200,d
 assert d['deals'][0]['bookBalancePKR']==-4480000,d
 status,d=req({'action':'payment','id':deal_id,'version':3,'amountPKR':79000,'bankId':'CASH|TTI','reference':'CASH-1'});assert status==200,d
 assert d['deals'][0]['bookBalancePKR']==-4401000,d
 assert req({'action':'payment','id':deal_id,'version':4,'amountPKR':5000000,'bankId':'CASH|TTI','reference':'CASH-2'})[0]==422
 status,d=req({'action':'payment','id':deal_id,'version':4,'amountPKR':28000,'bankId':'USD1','nativeAmount':100,'exchangeRate':281,'reference':'USD-PART'});assert status==200,d
 assert d['deals'][0]['bookBalancePKR']==-4373000,d
 foreign_post=json.loads(path.read_text())['journals'][d['result']['postId']] if 'result' in d else list(json.loads(path.read_text())['journals'].values())[-1]
 assert foreign_post['lines'][1]['bankCurrency']=='USD' and foreign_post['lines'][1]['bankCredit']==100,foreign_post
 assert foreign_post['lines'][2]['account']=='7100' and foreign_post['lines'][2]['debit']==100,foreign_post
 profile=json.loads((ROOT/'directors/costing-profiles.json').read_text())[0]
 costing={**profile,'date':'2026-10-23','fx':280,'whtPct':1.25,'bankPct':.1,'quantityMT':100,'incoterm':'FOB','packingAdjustment':0,'millVariety':'IRRI-6'}
 preview_before=path.read_bytes()
 status,preview=req({'action':'preview_costing','input':costing},area='costing',mode='read');assert status==200,preview
 assert set(preview)=={'ok','calculation'} and path.read_bytes()==preview_before,'Automatic preview persisted or exposed records'
 assert req({'action':'preview_costing','input':costing,'csrf':'bad'},area='costing',mode='read')[0]==419
 assert req({'action':'preview_costing','input':costing},origin='exports',mode='exports')[0]==403
 status,preview2=req({'action':'preview_costing','input':{**costing,'cashPrice':101}},area='costing',mode='read');assert status==200 and preview2['calculation']['costs']['cash']['costUSDPerMT']>preview['calculation']['costs']['cash']['costUSDPerMT'],preview2
 assert path.read_bytes()==preview_before,'Preview changed revision, records, or journals'
 status,d=req({'action':'save_costing','input':costing},area='costing',mode='cost');assert status==200,d
 assert d['costings'][0]['calculation']['costs']['udhaar'] is None
 assert req({'action':'save_deal',**deal},area='costing',mode='cost')[0]==403
 store=json.loads(path.read_text());assert len(store['journals'])==3 and store['managementCostingInputs'],store
 for journal in store['journals'].values():assert sum(l['debit'] for l in journal['lines'])==sum(l['credit'] for l in journal['lines'])
 assert (root/'data/operations.json').read_bytes()==ops_before,'Exporter entry changed original mill/exports stocks'
 # New operational Mill feed is read-only, finance-free, company scoped.
 sale={**deal,'direction':'SALE','contractRef':'','coverageMT':0,'reference':'OUR-SALE','requestKey':'our-sale-fixture','fobEstimateUSDPerMT':0}
 status,d=req(sale);assert status==200,d;sale_id=d['result']['id']
 status,feed=req(origin='mill',mode='mill');assert status==200 and feed['deals'][0]['id']==sale_id,feed
 assert not any(k in feed['deals'][0] for k in ['price','fx','payments','working','settlementPosts']),'Mill feed leaked financial workings'
 feed_before=path.read_bytes();assert req({'action':'save_deal'},origin='mill',mode='mill')[0]==403 and path.read_bytes()==feed_before
 assert req(origin='mill',mode='mill',entity='TG')[0]==422
 own={'ref':sale_id+'|MILL|M1','id':sale_id+'|MILL|M1','exporterDealId':sale_id,'entity':'TTI','millId':'M1','millName':'Own Mill','date':'2026-10-01','qty':100000,'displayName':'IRRI READY','stockSourceName':'IRRI READY','product':'Ready Rice','containers':[{'id':'OWN1','date':'2026-10-02','net':40000}]}
 operations['values']['tt35exportersale']=json.dumps([own]);(root/'data/operations.json').write_text(json.dumps(operations));ops_before=(root/'data/operations.json').read_bytes()
 status,feed=req(origin='mill',mode='mill');assert feed['deals'][0]['millPlannedMT']==100,feed
 assert req({'action':'loading','id':sale_id,'version':1,'date':'2026-10-02','invoiceRef':'OTHER-INV','quantityMT':40,'totalInvoiceMT':100,'contractPrice':500},origin='exports',mode='exports')[0]==403,'Exports injected financial contract price'
 status,d=req({'action':'loading','id':sale_id,'version':1,'date':'2026-10-02','invoiceRef':'OTHER-INV','quantityMT':40,'totalInvoiceMT':100},origin='exports',mode='exports');assert status==200,d
 assert req({'action':'post_settlement','id':sale_id,'version':2})[0]==422,'Missing approved own-goods cost was treated as zero'
 stored=json.loads(path.read_text());stored['inventoryCostRates']={'R':{'id':'R','entity':'TTI','product':'IRRI READY','effectiveFrom':'2026-10-01','costPerKg':100,'inventoryAccount':'1320'}};path.write_text(json.dumps(stored))
 status,d=req({'action':'review_loading','id':sale_id,'version':2,'loadingId':json.loads(path.read_text())['exporterDeals'][sale_id]['loadings'][0]['id'],'reviewConfirmed':True});assert status==200,d
 status,d=req({'action':'post_settlement','id':sale_id,'version':3});assert status==200,d
 lines=json.loads(path.read_text())['journals'][d['result']['postId']]['lines'];assert any(x['account']=='1320' and x['credit']==4000000 for x in lines),lines
 before_duplicate=path.read_bytes()
 status,error=req({'action':'queue_candidate','sourceKey':'EXPORTER|'+own['ref'],'saleDate':'2026-10-02','entity':'TTI','product':'IRRI READY','loadedKg':40000,'ratePerKg':100,'amount':4000000},_endpoint='local');assert status==422 and 'Directors' in error['error'],(status,error)
 assert path.read_bytes()==before_duplicate and (root/'data/operations.json').read_bytes()==ops_before,'Duplicate protection or settlement changed original Mill stock'
 print('PASS Directors trading endpoint: scoped reads, original stores, CSRF, permissions, company restrictions, duplicate keys, stale versions, posted settlement, partial payment and cost linkage')
 # Local ex-mill SODA: shared Accounts route, bank advance, real Mill loading and no export invoice.
 local={'entity':'TTI','date':'2026-10-01','partyId':'KAS','direction':'SALE','arrangement':'EX_MILL','currency':'PKR','incoterm':'EX_MILL','price':109000,'priceUnit':'KG','quantityMT':3500,'fobEstimateUSDPerMT':0,'bankEstimatePct':0,'fx':1,'reference':'HKM-LOCAL','product':'IRRI READY','bagRatePKRPerKg':1,'recoveryPct':60,'terms':'HKM handles bags and collections; buyer Chaudry Shafiq narration only.'}
 status,d=req({'action':'save_deal',**local},origin='accounts');assert status==200,d
 local_id=d['result']['id'];local_advance={'action':'advance','id':local_id,'version':1,'amountPKR':5000000,'bankId':'CASH|TTI','reference':'HKM-BANK-ADV','requestKey':'local-advance-stable-key'}
 status,d=req(local_advance,origin='accounts');assert status==200,d;advance_post=d['result']['postId']
 status,retry=req(local_advance,origin='accounts');assert status==200 and retry['result']['postId']==advance_post,retry
 deal=next(x for x in d['deals'] if x['id']==local_id);assert deal['bookBalancePKR']==-5000000 and deal['working']['loadedMT']==0,deal
 assert req({'action':'advance','id':local_id,'version':2,'amountPKR':10,'bankId':'CASH|TTI','reference':'STAFF'},origin='mill',mode='mill')[0]==403
 status,millfeed=req(origin='mill',mode='mill');visible=next(x for x in millfeed['deals'] if x['id']==local_id);assert visible['arrangement']=='EX_MILL' and 'price' not in visible and 'terms' not in visible,visible
 assert local_id not in [x['id'] for x in req(origin='exports',mode='exports')[1]['deals']],'Local ex-mill offered as an export invoice contribution'
 local_native={'ref':local_id+'|MILL|M1','exporterDealId':local_id,'entity':'TTI','millId':'M1','millName':'Own Mill','date':'2026-10-01','qty':3500000,'displayName':'IRRI READY','containers':[{'id':'HK-TRUCK1','date':'2026-10-02','net':40000}]}
 operations['values']['tt35exportersale']=json.dumps([own,local_native]);(root/'data/operations.json').write_text(json.dumps(operations));local_ops_before=(root/'data/operations.json').read_bytes()
 status,d=req({'action':'review_local','id':local_id,'version':2,'bagDeductionPKR':40000,'reviewConfirmed':True},origin='accounts');assert status==200,d
 status,d=req({'action':'post_settlement','id':local_id,'version':3,'exceptionNote':'Director-reviewed local SODA'},origin='accounts');assert status==200,d
 deal=next(x for x in d['deals'] if x['id']==local_id);assert deal['bookBalancePKR']==-680000,deal
 assert len(deal['account'])==2 and deal['loadings']==[],deal
 assert (root/'data/operations.json').read_bytes()==local_ops_before,'Advance/settlement changed Mill stock'
 print('PASS local SODA endpoint: shared Accounts, hidden Mill prices, stable advance retry, original trucks, reviewed bags, customer credit and no export invoice requirement')

 status,choices=req(view='production',origin='accounts');assert status==200 and choices['mills'],choices
 instruction={'action':'production_instruction','id':local_id,'version':4,'productionMillId':choices['mills'][0]['id'],'productionStockName':'IRRI READY','brand':'','specificationMode':'FULL','fullSpecification':'White rice 5% broken, packing as agreed.','productionNotes':'HKM provides bags.','productionBagTareGrams':100}
 assert req(instruction,origin='mill',mode='mill')[0]==403
 journal_count=len(json.loads(path.read_text())['journals'])
 status,d=req(instruction,origin='accounts');assert status==200,d
 sent=next(x for x in d['deals'] if x['id']==local_id);assert sent['productionReady'] and sent['fullSpecification']==instruction['fullSpecification'],sent
 assert len(json.loads(path.read_text())['journals'])==journal_count,'Issuing a production instruction posted money'
 feed=req(origin='mill',mode='mill')[1];sent=next(x for x in feed['deals'] if x['id']==local_id);assert sent['productionReady'] and sent['productionStockName']=='IRRI READY' and 'price' not in sent and 'terms' not in sent,sent
 assert req({**instruction,'version':5,'productionStockName':'OTHER'},origin='accounts')[0]==422,'Dispatched stock was reassigned'
 print('PASS Accounts/Directors production instruction -> read-only Mill feed; full specification, permission boundaries, no financial write and historical route protection')
 # Directors-only Fellow Exporter master; selection stays separate from Business Parties.
 assert req({'action':'save_exporter','name':'Fixture Fellow'},origin='accounts')[0]==403
 stable=path.read_bytes();assert req({'action':'save_exporter','name':'Fixture Fellow'},mode='read')[0]==403 and path.read_bytes()==stable
 status,d=req({'action':'save_exporter','name':'Fixture Fellow','requestKey':'new-fellow-master'});assert status==200,d;party=d['result']['partyId'];assert party in [p['id'] for p in d['parties']]
 st,replay=req({'action':'save_exporter','name':'Fixture Fellow','requestKey':'new-fellow-master'});assert st==200 and replay['result']['partyId']==party
 assert req({'action':'save_exporter','partyId':party,'version':7,'name':'Changed','reason':'Correct master'})[0]==422
 status,d=req({'action':'save_exporter','partyId':party,'version':1,'name':'Fixture Fellow Updated','reason':'Correct name'});assert status==200,d
 fresh={'action':'save_deal',**local,'partyId':party,'reference':'UNUSED-LIFECYCLE','quantityMT':10,'requestKey':'unused-soda-lifecycle'}
 status,d=req(fresh);assert status==200,d;unused=d['result']['id'];assert not next(x for x in d['deals'] if x['id']==unused)['deletionBlocker']
 assert req({'action':'delete_exporter','partyId':party,'version':2,'reason':'Retire unused party'})[0]==422
 assert req({'action':'delete_deal','id':unused,'version':9,'reason':'Duplicate unused SODA'})[0]==409
 stable_journals=json.loads(path.read_text())['journals'];delete={'action':'delete_deal','id':unused,'version':1,'reason':'Duplicate unused SODA','requestKey':'delete-unused-soda'}
 status,d=req(delete);assert status==200 and d['result']['deleted'] and unused not in [x['id'] for x in d['deals']],d
 after=json.loads(path.read_text());assert after['journals']==stable_journals and after['exporterDeals'][unused]['history'][-1]['action']=='delete_deal'
 stable=path.read_bytes();assert req(delete)[0]==200 and path.read_bytes()==stable
 assert unused not in [x['id'] for x in req(origin='mill',mode='mill')[1]['deals']]
 assert req({'action':'delete_deal','id':local_id,'version':5,'reason':'Cannot remove dispatched source'})[0]==422
 status,d=req({'action':'delete_exporter','partyId':party,'version':2,'reason':'Retire unused party'});assert status==200 and party not in [p['id'] for p in d['parties']],d
 assert (root/'data/operations.json').read_bytes()==local_ops_before,'Master and unused SODA lifecycle changed actual Mill operations'
 print('PASS Directors-only exporter Add/Edit/Delete, active-source guards, stale versions, CSRF/rights, audited SODA deletion, retries and no financial/physical changes')

 # Shared Parties master endpoint: no access to SODA money, costing or bank payloads.
 stable=path.read_bytes();status,master=req(_endpoint='fellow');assert status==200 and set(master)=={'ok','entity','parties','access','result'},master
 assert path.read_bytes()==stable and req(_endpoint='fellow',mode='no-master')[0]==403
 assert req(_endpoint='fellow',entity='BRM')[0]==403
 assert req({'action':'save_exporter','name':'Shared Master'},_endpoint='fellow',mode='read')[0]==403
 assert req({'action':'save_exporter','name':'Shared Master','csrf':'bad'},_endpoint='fellow')[0]==419
 request={'action':'save_exporter','name':'Shared Master','code':'SHARED','requestKey':'shared-master-001'}
 status,master=req(request,_endpoint='fellow');assert status==200,master;pid=master['result']['partyId']
 stable=path.read_bytes();assert req(request,_endpoint='fellow')[0]==200 and path.read_bytes()==stable
 assert next(p for p in req()[1]['parties']if p['id']==pid)['code']=='SHARED','Master not shared with SODA selector'
 assert req({'action':'save_exporter','name':'Other exporter','code':'SHARED'},_endpoint='fellow')[0]==422
 assert req({'action':'save_exporter','partyId':pid,'version':0,'name':'Shared Master','code':'SHARED','reason':'Stale request'},_endpoint='fellow')[0]==422
 status,master=req({'action':'save_exporter','partyId':pid,'version':1,'name':'Shared Master','code':'NEWCODE','reason':'Correct short code'},_endpoint='fellow');assert status==200,master
 status,master=req({'action':'delete_exporter','partyId':pid,'version':2,'reason':'Unused test master'},_endpoint='fellow');assert status==200 and pid not in [p['id']for p in master['parties']],master
 assert json.loads(path.read_text())['journals']==stable_journals,'Master changed financial records'
 print('PASS shared Parties master: matching rights and company/CSRF, code uniqueness, stale/retry guards, canonical SODA selection and no financial write')
