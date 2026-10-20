"""Actual staff authentication, bill-payment correction and unchanged rejected/preview books."""
import json,os,pathlib,shutil,subprocess,tempfile
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='staff-payment-amend-') as temp:
 root=pathlib.Path(temp);app=root/'app';shutil.copytree(ROOT,app,ignore=shutil.ignore_patterns('.git','node_modules','__pycache__'))
 private=root/'transtrade_private';private.mkdir();auth=private/'auth.json';books=private/'accounts.json';env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
 user={'id':1,'username':'fixture','full_name':'Fixture Accountant','role':'Staff','active':True,'session_version':1,'permissions':{'Accounts':{'entity-tti':['View','Create','Edit'],'supplier':['View','Create','Edit'],'reports':['View']}}}
 bank={'id':'B1','values':['Company Account','TTI','','Fixture company','Fixture Bank','','Pakistan','PKR','123456','','','','Accounts','Active']}
 def set_user():auth.write_text(json.dumps({'users':[user],'masters':{'banks':[bank],'business_parties':[{'id':'V1','values':['Fixture Shipping Services','','Freight Forwarder','','','','','','','','Active']}]},'settings':{'qa_account_seeded':True,'master_permission_schema':2},'audit':[]}))
 def journal(id,date,source,lines,meta=None):return {'id':id,'entity':'TTI','date':date,'sourceType':source,'status':'Posted','reference':'FIXTURE-123','narration':'Original payment','totalDebit':sum(l.get('debit',0) for l in lines),'totalCredit':sum(l.get('credit',0) for l in lines),'lines':lines,'meta':meta or {}}
 opening='POST-2026-00001';pay='POST-2026-00002';bf='BF:'+opening+':0'
 allocation={'billId':bf,'sourceKey':'OPENING|0','billNo':'Balance B/F','amount':200,'paidAmount':200,'category':'OPENING','broker':'Fixture Shipping Services','payableAccount':'2130','balanceAfter':800}
 initial={'revision':1,'journals':{opening:journal(opening,'2026-07-01','OPENING_BALANCE_BF',[{'account':'2130','credit':1000,'debit':0,'subledger':'Fixture Shipping Services'},{'account':'3400','debit':1000,'credit':0}]),'FUNDS':journal('FUNDS','2026-07-01','JV',[{'account':'1110','bankAccountId':'B1','debit':5000,'credit':0},{'account':'3400','credit':5000,'debit':0}]),pay:journal(pay,'2026-10-20','SUPPLIER_PAYMENT',[{'account':'2130','accountName':'Service payables','counterparty':'Fixture Shipping Services','debit':200,'credit':0},{'account':'1110','bankAccountId':'B1','bankName':'Fixture Bank','currency':'PKR','bankDebit':0,'bankCredit':200,'debit':0,'credit':200}],{'settlementId':'S1','broker':'Fixture Shipping Services','bankAccountId':'B1','paymentMode':'BANK','bankPaymentMethod':'CHEQUE','chequeNo':'FIXTURE-123','chequeDate':'2026-10-20','allocations':[allocation]})},'supplierSettlements':{'S1':{'id':'S1','entity':'TTI','status':'Posted','type':'Bank / Cash Supplier Payment','date':'2026-10-20','broker':'Fixture Shipping Services','journalId':pay,'amount':200,'netPayment':200,'withholding':0,'discountAmount':0,'paymentMode':'BANK','bankAccountId':'B1','bankPaymentMethod':'CHEQUE','chequeNo':'FIXTURE-123','chequeDate':'2026-10-20','allocations':[allocation]}},'bankAccountSettings':{'B1':{'active':True,'allowPayments':True,'defaultPaymentAccount':True}}}
 books.write_text(json.dumps(initial));set_user()
 harness=root/'request.php';harness.write_text(r'''<?php
 $req=json_decode($argv[2],true);$_SERVER['REQUEST_URI']=$req['uri'];$_SERVER['REQUEST_METHOD']=$req['method'];$_SERVER['REMOTE_ADDR']='192.0.2.42';parse_str((string)(parse_url($req['uri'],PHP_URL_QUERY)??''),$_GET);
 session_name('TRANSTRADE_SESSION');session_id('staff-payment-fixture');session_start();$_SESSION=['user_id'=>1,'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];session_write_close();
 $GLOBALS['fixtureBody']=json_encode($req['body']);class FixtureInput{public $context;private $offset=0;function stream_open($p,$m,$o,&$opened){return $p==='php://input';}function stream_read($n){$s=substr($GLOBALS['fixtureBody'],$this->offset,$n);$this->offset+=strlen($s);return $s;}function stream_eof(){return $this->offset>=strlen($GLOBALS['fixtureBody']);}function stream_stat(){return [];}}
 stream_wrapper_unregister('php');stream_wrapper_register('php',FixtureInput::class);register_shutdown_function(static function(){echo "\n__STATUS__".(http_response_code()?:200);});require $argv[1];
 ''')
 def request(body=None,query='?entity=TTI',endpoint='supplier_settlements'):
  req={'uri':'/api/'+endpoint+'.php'+query,'method':'GET' if body is None else 'POST','body':{} if body is None else {'csrf':'fixture',**body}}
  r=subprocess.run(['php','-d',f'session.save_path={root}',str(harness),str(app/'api'/f'{endpoint}.php'),json.dumps(req)],text=True,capture_output=True,env=env)
  assert r.returncode==0,r.stdout+r.stderr
  payload,status=r.stdout.rsplit('\n__STATUS__',1);return int(status),json.loads(payload)
 before=books.read_bytes();status,data=request(query='?entity=TTI&editPayment=S1');assert status==200,(status,data)
 assert data['editingPayment']['netPayment']==200 and data['payables'][0]['outstanding']==1000 and books.read_bytes()==before
 body={'entity':'TTI','action':'amend_bill_payment','settlementId':'S1','expectedJournalId':pay,'reason':'Correct actual cheque date and amount','requestKey':'fixture-amend-request-0001','date':'2026-07-06','supplier':'Fixture Shipping Services','selectedBills':[bf+'|OPENING|0'],'explicitBillSelection':True,'includeOpeningBalance':True,'amount':300,'paymentMode':'BANK','bankAccountId':'B1','bankPaymentMethod':'CHEQUE','chequeNo':'FIXTURE-124','reference':'FIXTURE-124','chequeDate':'2026-07-06','applyPaymentDifferenceAsDiscount':False,'selectedOutstanding':1000}
 status,preview=request({**body,'preview':True});assert status==200,(status,preview);assert len(preview['previews'])==2 and books.read_bytes()==before
 for delta in [{'entity':'BRM'},{'supplier':'Other provider'},{'expectedJournalId':'STALE'},{'amount':2000}]:
  st,res=request({**body,**delta});assert st in [403,409,422],(st,res);assert books.read_bytes()==before
 perms=json.loads(json.dumps(user['permissions']));user['permissions']['Accounts']['supplier']=['View'];set_user();assert request(body)[0]==403;assert books.read_bytes()==before;user['permissions']=perms;set_user()
 status,saved=request(body,query='');assert status==200,(status,saved);assert saved['result']['publicPostId']==pay
 state=json.loads(books.read_text());assert state['supplierSettlements']['S1']['status']=='Reversed';new=saved['result']['journalId'];assert state['journals'][pay]['amendedByPostId']==new and state['journals'][new]['date']=='2026-07-06'
 balance=sum(l.get('debit',0)-l.get('credit',0) for j in state['journals'].values() for l in j['lines'] if l['account']=='1110');assert balance==4700,balance
 assert request()[1]['payables'][0]['outstanding']==700
 committed=books.read_bytes();assert request(body,query='')[1]['result']['journalId']==new and books.read_bytes()==committed
 assert request({**body,'amount':301},query='')[0]==422 and books.read_bytes()==committed
 status,post=request(query='?entity=TTI&account=POSTS&postId='+pay,endpoint='accounts_ledger_browser');assert status==200 and post['post']['id']==new and post['post']['meta']['publicPostId']==pay,(status,post)
 assert request({'entity':'TTI','postId':new,'reason':'Raw edit must be blocked','date':'2026-07-06','lines':state['journals'][new]['lines']},query='',endpoint='accounts_post_amend')[0]==422
 assert books.read_bytes()==committed
 # Repeat with an actual service invoice, excluding the separate B/F.
 invoice=json.loads(json.dumps(initial));invoice['supplierBills']={'BILL1':{'id':'BILL1','entity':'TTI','category':'FREIGHT','vendor':'Fixture Shipping Services','broker':'Fixture Shipping Services','billNo':'FIXTURE-INVOICE','billDate':'2026-07-01','payableAccount':'2130','supplierPayableTotal':1000,'receiptAllocations':[{'sourceKey':'SERVICE|BILL1','supplierPayableShare':1000}]}}
 inv_alloc={**allocation,'billId':'BILL1','sourceKey':'SERVICE|BILL1','billNo':'FIXTURE-INVOICE','category':'FREIGHT'}
 invoice['supplierSettlements']['S1']['allocations']=[inv_alloc];invoice['journals'][pay]['meta']['allocations']=[inv_alloc]
 books.write_text(json.dumps(invoice));invbody={**body,'requestKey':'fixture-invoice-amend-0002','selectedBills':['BILL1|SERVICE|BILL1'],'includeOpeningBalance':False}
 status,res=request(invbody);assert status==200,(status,res)
 dues={p['billId']:p['outstanding'] for p in request()[1]['payables']};assert dues['BILL1']==700 and dues[bf]==1000,dues
 # Explicit partial amendments retain even eight paisa and the stable public Post ID.
 books.write_text(json.dumps(invoice));small={**invbody,'requestKey':'fixture-partial-amend-0003','amount':999.92,'paymentIntent':'PARTIAL','discountAmount':0}
 before=books.read_bytes();status,preview=request({**small,'preview':True});assert status==200,(status,preview);assert books.read_bytes()==before
 status,res=request(small);assert status==200,(status,res);assert res['result']['publicPostId']==pay and res['result']['paymentIntent']=='PARTIAL' and res['result']['discountAmount']==0
 state=json.loads(books.read_text());assert all(l['account']!='4400' for j in state['journals'].values() for l in j['lines'])
 dues={p['billId']:p['outstanding'] for p in request()[1]['payables']};assert dues['BILL1']==.08 and dues[bf]==1000,dues
 before=books.read_bytes();assert request(small)[1]['result']['journalId']==res['result']['journalId'] and books.read_bytes()==before
 assert request({**small,'paymentIntent':'SETTLE'})[0]==422 and books.read_bytes()==before
 # A commodity source cannot be converted into an unrelated simple-bill payment.
 invoice['supplierSettlements']['S1']['allocations']=[{**inv_alloc,'billId':'CB1'}];books.write_text(json.dumps(invoice));before=books.read_bytes()
 assert request(query='?entity=TTI&editPayment=S1')[0]==422 and books.read_bytes()==before
 print('PASS actual staff authority: payment/BF correction, earlier valid date, preview rollback, source allocations, stable Post ID, replay, stale/company/role/amount rejection and raw linked-journal hold')
