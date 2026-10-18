"""Shipment contribution reads disposable original books; never posts or repairs them."""
import json, os, pathlib, shutil, subprocess, tempfile
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='shipment-evidence-') as tmp:
 root=pathlib.Path(tmp);app=root/'app';shutil.copytree(ROOT,app,ignore=shutil.ignore_patterns('.git','node_modules','__pycache__'))
 private=root/'transtrade_private';private.mkdir();auth=private/'auth.json';path=private/'accounts.json'
 owner={'id':1,'username':'fixture','full_name':'Fixture','role':'Super Admin','active':True,'session_version':1}
 def set_user(user):auth.write_text(json.dumps({'users':[user],'masters':{},'settings':{'qa_account_seeded':True},'audit':[]}))
 set_user(owner);env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
 (root/'runner.php').write_text("""<?php
 $_GET=['entity'=>$argv[1]??'TTI'];$_SERVER['REQUEST_METHOD']='GET';$_SERVER['REQUEST_URI']='/api/shipment_profitability.php';$_SERVER['REMOTE_ADDR']='192.0.2.47';
 session_name('TRANSTRADE_SESSION');session_id('shipment-evidence-'.bin2hex(random_bytes(12)));session_start();$_SESSION=['user_id'=>1,'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];session_write_close();
 register_shutdown_function(static function(){file_put_contents(__DIR__.'/status',(string)(http_response_code()?:200));});require __DIR__.'/app/api/shipment_profitability.php';
 """)
 def request(entity='TTI'):
  p=subprocess.run(['php','-d',f'session.save_path={root}',str(root/'runner.php'),entity],env=env,capture_output=True,text=True,check=True)
  return int((root/'status').read_text()),json.loads(p.stdout)
 def report(state,entity='TTI'):
  path.write_text(json.dumps(state));before=path.read_bytes();status,d=request(entity)
  assert path.read_bytes()==before,'Report rewrote original source'
  assert status==200 and d['ok'],(status,d);return d
 def bill(amount=80,status='Outstanding',ids=None):
  return {'id':'B1','entity':'TTI','category':'TRANSPORT','status':status,'expenseAccount':'5510','supplierPayableTotal':amount,'postingJournalIds':ids or ['P1','P2'],'sourceRecord':{'lines':[{'contractRef':'C1','lotRef':'L1','amount':amount}]}}
 def post(id,amount,entity='TTI',status='Posted'):
  return {'id':id,'entity':entity,'status':status,'date':'2026-10-01','meta':{'supplierBillId':'B1'},'lines':[{'account':'5510','debit':max(0,amount),'credit':max(0,-amount)},{'account':'2130','debit':max(0,-amount),'credit':max(0,amount)}]}
 # A saved source row is not evidence that its liability has been posted.
 d=report({'supplierBills':{'B1':bill(500,'Draft',[])},'journals':{}})
 assert sum(x['transport'] for x in d['rows'])==0,('Unposted bill became posted transport cost',d)
 assert d['sourceHolds'] and all(x['grossContribution'] is None for x in d['rows']),d
 state={'supplierBills':{'B1':bill()},'journals':{'P1':post('P1',100),'P2':post('P2',-20)}}
 d=report(state);assert not d['sourceHolds'] and d['rows'][0]['transport']==80,d
 for mutation in ['missing','foreign','draft','mismatch','duplicate','wrong-owner']:
  changed=json.loads(json.dumps(state))
  if mutation=='missing':del changed['journals']['P2']
  if mutation=='foreign':changed['journals']['P2']['entity']='BRM'
  if mutation=='draft':changed['journals']['P2']['status']='Draft'
  if mutation=='mismatch':changed['supplierBills']['B1']['supplierPayableTotal']=99
  if mutation=='duplicate':changed['supplierBills']['B1']['postingJournalIds']=['P1','P1','P2']
  if mutation=='wrong-owner':changed['journals']['P2']['meta']['supplierBillId']='OTHER'
  d=report(changed);assert d['sourceHolds'] and sum(x['transport'] for x in d['rows'])==0,(mutation,d)
 # Valid postings cannot be turned into another amount by stale allocation rows.
 changed=json.loads(json.dumps(state));changed['supplierBills']['B1']['sourceRecord']['lines'][0]['amount']=500
 d=report(changed);assert d['sourceHolds'] and sum(x['transport'] for x in d['rows'])==0,d
 changed=json.loads(json.dumps(state));changed['supplierBills']['ALIAS']=changed['supplierBills']['B1'];d=report(changed);assert d['sourceHolds'] and sum(x['transport'] for x in d['rows'])==80,'Repeated bill cost counted twice'
 # An authorized fully reversed source stays excluded, just like other canonical reports.
 changed=json.loads(json.dumps(state));changed['supplierBills']['B1']['status']='Reversed';d=report(changed);assert not d['rows'] and not d['sourceHolds'],d
 # Revenue and stock cost also require the actual linked company postings.
 sale={'id':'SALE1','entity':'TTI','candidateType':'CUSTOMER_EXPORT_SALE','status':'Recognized / Posted','journalId':'SALEPOST','functionalAmount':1000,'costJournalId':'COSTPOST','costAmount':400,'meta':{'contractRef':'C1','lotRef':'L1'},'sourceKey':'C1|L1'}
 d=report({'exportCandidates':{'SALE1':sale},'journals':{}})
 assert d['sourceHolds'] and all(not r.get('revenue') and not r.get('stockCost') for r in d['rows']),('Unverified sale/cost became shipment contribution',d)
 sale_post={'id':'SALEPOST','entity':'TTI','status':'Posted','sourceType':'EXPORT_SALE_RECOGNIZED','date':'2026-10-01','meta':{'candidateId':'SALE1','functionalAmount':1000},'lines':[{'account':'1210','debit':1000,'credit':0},{'account':'4200','debit':0,'credit':1000}]}
 cost_post={'id':'COSTPOST','entity':'TTI','status':'Posted','sourceType':'EXPORT_COGS','date':'2026-10-01','meta':{'exportCandidateId':'SALE1','saleJournalId':'SALEPOST','costAmount':400},'lines':[{'account':'5100','debit':400,'credit':0},{'account':'1310','debit':0,'credit':400}]}
 sale_state={'exportCandidates':{'SALE1':sale},'journals':{'SALEPOST':sale_post,'COSTPOST':cost_post}}
 d=report(sale_state);assert not d['sourceHolds'] and d['rows'][0]['revenue']==1000 and d['rows'][0]['stockCost']==400 and d['rows'][0]['grossContribution']==600,d
 for mutation in ['missing-sale','missing-cost','foreign-sale','draft-cost','wrong-owner','wrong-cost-owner','changed-source','unbalanced','negative-line','duplicate-source','duplicate-post','partial-reversal','amended','reversal-flag']:
  changed=json.loads(json.dumps(sale_state))
  if mutation=='missing-sale':del changed['journals']['SALEPOST']
  if mutation=='missing-cost':del changed['journals']['COSTPOST']
  if mutation=='foreign-sale':changed['journals']['SALEPOST']['entity']='BRM'
  if mutation=='draft-cost':changed['journals']['COSTPOST']['status']='Draft'
  if mutation=='wrong-owner':changed['journals']['SALEPOST']['meta']['candidateId']='OTHER'
  if mutation=='wrong-cost-owner':changed['journals']['COSTPOST']['meta']['exportCandidateId']='OTHER'
  if mutation=='changed-source':changed['exportCandidates']['SALE1']['functionalAmount']=9999
  if mutation=='unbalanced':changed['journals']['SALEPOST']['lines'][0]['debit']=999
  if mutation=='negative-line':changed['journals']['COSTPOST']['lines'][0]['credit']=-1
  if mutation=='duplicate-source':changed['exportCandidates']['ALIAS']=changed['exportCandidates']['SALE1']
  if mutation=='duplicate-post':changed['exportCandidates']['SALE2']={**changed['exportCandidates']['SALE1'],'id':'SALE2'}
  if mutation=='partial-reversal':changed['journals']['PARTIAL']={**sale_post,'id':'PARTIAL','reversalOf':'SALEPOST','lines':[{'account':'4200','debit':100,'credit':0},{'account':'1210','debit':0,'credit':100}]}
  if mutation=='amended':changed['journals']['SALEPOST']['amendedByPostId']='REPLACEMENT'
  if mutation=='reversal-flag':changed['journals']['SALEPOST']['reversedByPostId']='MISSING'
  d=report(changed);assert d['sourceHolds'] and all(x['grossContribution'] is None for x in d['rows']),(mutation,d)
 # Waiting cost is unknown, not zero cost and an apparent Rs1,000 profit.
 waiting=json.loads(json.dumps(sale_state));waiting['exportCandidates']['SALE1'].pop('costJournalId');waiting['exportCandidates']['SALE1'].pop('costAmount');waiting['journals'].pop('COSTPOST')
 d=report(waiting);r=d['rows'][0];assert r['revenue']==1000 and r['stockCost'] is None and r['grossContribution'] is None and r['totalCost'] is None,d
 # An unchanged source snapshot cannot revive a fully reversed sale and cost.
 reversed_state=json.loads(json.dumps(sale_state))
 for key in ['SALEPOST','COSTPOST']:
  j=json.loads(json.dumps(reversed_state['journals'][key]));j['id']=key+'R';j['reversalOf']=key
  for line in j['lines']:line['debit'],line['credit']=line['credit'],line['debit']
  reversed_state['journals'][j['id']]=j
 d=report(reversed_state);assert not d['rows'] and not d['sourceHolds'],d
 # TG can reuse its explicit linked direct cost posting, once, for the same source.
 tg_sale={**sale,'entity':'TG','tgPayableCandidateId':'TGP'};tg_sale_post={**sale_post,'entity':'TG'}
 tg_payable={'id':'TGP','entity':'TG','candidateType':'TG_INTERCOMPANY_PAYABLE','status':'Recognized / Posted','sourceKey':'C1|L1','journalId':'COSTPOST','costAccount':'5100','functionalAmount':400}
 tg_cost={**cost_post,'entity':'TG','sourceType':'TG_INTERCOMPANY_PAYABLE_RECOGNIZED','meta':{'candidateId':'TGP','functionalAmount':400},'lines':[{'account':'5100','debit':400,'credit':0},{'account':'2410','debit':0,'credit':400}]}
 d=report({'exportCandidates':{'SALE1':tg_sale,'TGP':tg_payable},'journals':{'SALEPOST':tg_sale_post,'COSTPOST':tg_cost}},'TG');assert not d['sourceHolds'] and d['rows'][0]['grossContribution']==600,d
 tg_payable['sourceKey']='OTHER';d=report({'exportCandidates':{'SALE1':tg_sale,'TGP':tg_payable},'journals':{'SALEPOST':tg_sale_post,'COSTPOST':tg_cost}},'TG');assert d['sourceHolds'],d
 # The actual authentication boundary still enforces module and company View access.
 path.write_text(json.dumps(sale_state));before=path.read_bytes()
 for permissions in [{'Mill':['View']},{'Accounts':{'reports':['View'],'entity-brm':['View']}}]:
  set_user({**owner,'role':'Staff','permissions':permissions});status,d=request();assert status==403 and not d['ok'] and path.read_bytes()==before,(status,d)
 set_user(owner)
 # Input errors must not look like a successful empty report.
 path.write_text('{broken');status,d=request();assert status==500 and not d['ok'],'Corrupt original books reported as empty success'
 print('PASS shipment contribution: sale/COGS/liability identity and amount evidence, TG direct cost, missing-cost nulls, full reversals, duplicates, immutable reads and corrupt-source failure')
