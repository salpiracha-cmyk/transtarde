"""Link original Mill references to canonical payments without posting or rewriting money."""
import json,pathlib,shutil,subprocess,tempfile
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='mill-reference-') as tmp:
 root=pathlib.Path(tmp);shutil.copytree(ROOT/'api',root/'api');(root/'data').mkdir()
 masters={'mills':[{'id':'M1','values':['Selected Mill','','Own Mill','','','Active','','TTI']}]}
 (root/'masters.json').write_text(json.dumps(masters))
 (root/'auth_store.php').write_text('''<?php
define('TT_DATA_DIR',__DIR__.'/data');function tt_require_login(){return ['id'=>1,'username'=>'Fixture','role'=>'Staff','location'=>($_GET['mode']??'')==='location'?'Another Mill':'Selected Mill'];}
function tt_user_can_open_module($u,$m){return $m==='Accounts'&&($_GET['mode']??'')!=='module';}
function tt_user_can_module_action($u,$m,$i,$a){return $m==='Accounts'&&$i==='expenses'&&($_GET['mode']??'')!=='icon'&&(($a==='View')||($_GET['mode']??'')!=='read');}
function tt_user_can_access_entity($u,$e,$a){return $e==='TTI';}function tt_release_read_session(){}function tt_verify_csrf($v){return $v==='fixture';}function tt_accounts_input(){return $GLOBALS['fixtureBody'];}function tt_list_masters(){return json_decode(file_get_contents(__DIR__.'/masters.json'),true);}
''')
 (root/'runner.php').write_text("<?php $r=json_decode(stream_get_contents(STDIN),true);$_GET=$r['query'];$_SERVER['REQUEST_METHOD']=$r['body']===null?'GET':'POST';$GLOBALS['fixtureBody']=json_encode($r['body']);register_shutdown_function(function(){fwrite(STDERR,'STATUS:'.(http_response_code()?:200));});require __DIR__.'/api/mill_reference_reconciliation.php';")
 def req(body=None,mode='',entity='TTI'):
  r=subprocess.run(['php',str(root/'runner.php')],input=json.dumps({'query':{'entity':entity,'mode':mode},'body':body}),text=True,capture_output=True)
  assert r.returncode==0,(r.stdout,r.stderr)
  return int(r.stderr.rsplit('STATUS:',1)[1]),json.loads(r.stdout)
 def line(code,dr=0,cr=0):return {'account':code,'debit':dr,'credit':cr}
 def journal(id,kind,meta,lines):return {'id':id,'entity':'TTI','status':'Posted','date':'2026-10-01','sourceType':kind,'meta':meta,'lines':lines}
 allocations=[{'month':'2026-09','amount':100,'payable':100,'prepaid':0}]
 books={'revision':7,'salaryMasters':{'SM1':{'id':'SM1','entity':'TTI','name':'Alice','effectiveFrom':'2026-07-01'}},'rentMasters':{'RM1':{'id':'RM1','entity':'TTI','mill':'Selected Mill','payee':'Landlord','effectiveFrom':'2026-07-01'}},'salaryAdvances':{'A1':{'id':'A1','entity':'TTI','masterId':'SM1','person':'Alice','date':'2026-10-01','amount':50,'remaining':20,'journalId':'J1','adjustments':[{'month':'2026-10','amount':30}]}},'rentSalaryPayments':{'R1':{'id':'R1','entity':'TTI','type':'RENT','masterId':'RM1','date':'2026-10-01','amount':100,'journalId':'J2','allocations':allocations}},'journals':{'J1':journal('J1','SALARY_ADVANCE',{'salaryMasterId':'SM1','salaryAdvanceId':'A1'},[line('1230',50),line('1120',cr=50)]),'J2':journal('J2','RENT_PAYMENT',{'rentMasterId':'RM1','allocations':allocations},[line('2140',100),line('1110',cr=100)])},'salaryPeriods':{'HISTORY':{'amount':999}},'historicalExportOpenings':{'BF':{'weightKg':999999}}}
 source={'id':1,'entity':'TTI','millId':1,'millName':'Selected Mill','locationId':'M1','date':'2026-10-01','month':'2026-10','person':'Alice','amount':50,'ref':'Advance receipt'}
 rent={'id':2,'entity':'TTI','millId':1,'millName':'Selected Mill','locationId':'M1','date':'2026-10-01','amount':100,'fromMonth':'2026-09','tillMonth':'2026-09','ref':'Rent receipt'}
 ops={'values':{'tt39salaryadvances':json.dumps([source]),'tt39rentpayments':json.dumps([rent])}}
 bp=root/'data/accounts.json';op=root/'data/operations.json';bp.write_text(json.dumps(books));op.write_text(json.dumps(ops));before=(bp.read_bytes(),op.read_bytes())
 status,d=req();assert status==200,d;assert len(d['rows'])==2 and all(len(x['choices'])==1 for x in d['rows']);assert (bp.read_bytes(),op.read_bytes())==before
 for mode in ['module','icon']:assert req(mode=mode)[0]==403
 assert req(entity='BRM')[0]==403 and req(mode='location')[1]['rows']==[]
 def body_for(row,action='link',key='fixture-reference-link-001'):
  t=row['choices'][0] if row['choices'] else {}
  return {'csrf':'fixture','action':action,'entity':'TTI','kind':row['kind'],'sourceId':row['sourceId'],'sourceFingerprint':row['fingerprint'],'linkFingerprint':row['linkFingerprint'],'targetId':t.get('id',''),'targetFingerprint':t.get('fingerprint',''),'requestKey':key,'reason':'Original receipt and existing Post reviewed'}
 salary=next(x for x in d['rows'] if x['kind']=='SALARY_ADVANCE');rentrow=next(x for x in d['rows'] if x['kind']=='RENT');body=body_for(salary)
 assert req(body,mode='read')[0]==403 and req(body,mode='location')[0]==422
 assert req({**body,'csrf':'bad'})[0]==419 and req({**body,'targetId':'RENT|R1'})[0]==422
 status,d=req(body);assert status==200,d;assert next(x for x in d['rows'] if x['kind']=='SALARY_ADVANCE')['status']=='Verified existing payment'
 after=bp.read_bytes();assert req(body)[1]['result']['duplicate'] and bp.read_bytes()==after
 assert req({**body,'reason':'Changed'})[0]==422 and bp.read_bytes()==after
 assert req({**body,'requestKey':'fixture-stale-link-002'})[0]==422,'Concurrent link change was overwritten'
 persisted=json.loads(after);assert {k:v for k,v in persisted.items() if k not in ['revision','millReferenceLinks','millReferenceRequests']}=={k:v for k,v in books.items() if k!='revision'} and op.read_bytes()==before[1],'Link created or changed financial/stock data'
 assert req(body_for(rentrow,key='fixture-rent-link-001'))[0]==200
 # Another reported receipt cannot reuse one original bank/cash payment.
 ops['values']['tt39salaryadvances']=json.dumps([source,{**source,'id':3}]);op.write_text(json.dumps(ops));status,d=req();other=next(x for x in d['rows'] if x['sourceId']=='3');assert other['choices']==[]
 forged=body_for(other,key='fixture-duplicate-002');forged.update(targetId=body['targetId'],targetFingerprint=body['targetFingerprint']);assert req(forged)[0]==422
 # Monthly salary settlement changes the remaining advance, not the original payment identity.
 current=json.loads(bp.read_text());current['salaryAdvances']['A1']['remaining']=0;current['salaryAdvances']['A1']['adjustments'].append({'month':'2026-11','amount':20});bp.write_text(json.dumps(current));status,d=req();linked=next(x for x in d['rows'] if x['sourceId']=='1');assert linked['status']=='Verified existing payment' and linked['target']['remaining']==0
 # Reversals and changed sources never retain a verified tick.
 current['journals']['REV']={**current['journals']['J1'],'id':'REV','reversalOf':'J1'};bp.write_text(json.dumps(current));status,d=req();assert next(x for x in d['rows'] if x['sourceId']=='1')['status']=='Needs reconciliation'
 current['journals'].pop('REV');bp.write_text(json.dumps(current));ops['values']['tt39salaryadvances']=json.dumps([{**source,'amount':51}]);op.write_text(json.dumps(ops));status,d=req();changed=next(x for x in d['rows'] if x['sourceId']=='1');assert changed['status']=='Needs reconciliation'
 unlink=body_for(changed,'unlink','fixture-unlink-003');assert req(unlink)[0]==200
 final=json.loads(bp.read_text());assert final['journals']==books['journals'] and final['millReferenceLinks'][changed['key']]['history'],'Unlink lost original journal/link history'
 print('PASS Mill reference endpoint: exact original master/payment/month identity, role/company/location/CSRF, no guessed or duplicate posting, locked saves, replay/conflicts, source drift/reversal holds, canonical settlement carry and retained link history')
