"""Actual authenticated expense invoice capture, settlement, correction and source linkage."""
import json,os,pathlib,shutil,subprocess,tempfile
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='staff-payment-amend-') as temp:
 root=pathlib.Path(temp);app=root/'app';shutil.copytree(ROOT,app,ignore=shutil.ignore_patterns('.git','node_modules','__pycache__'))
 private=root/'transtrade_private';private.mkdir();auth=private/'auth.json';books=private/'accounts.json';env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
 user={'id':1,'username':'fixture','full_name':'Fixture Accountant','role':'Staff','active':True,'session_version':1,'permissions':{'Accounts':{'entity-tti':['View','Create','Edit'],'supplier':['View','Create','Edit'],'expenses':['View','Create','Edit'],'reports':['View'],'purchases':['View']}}}
 bank={'id':'B1','values':['Company Account','TTI','','Fixture company','Fixture Bank','','Pakistan','PKR','123456','','','','Accounts','Active']}
 def set_user():auth.write_text(json.dumps({'users':[user],'masters':{'banks':[bank],'business_parties':[{'id':'V1','values':['Fixture Shipping Services','','Freight Forwarder','','','','','','','','Active']}]},'settings':{'qa_account_seeded':True,'master_permission_schema':2},'audit':[]}))
 def journal(id,date,source,lines,meta=None):return {'id':id,'entity':'TTI','date':date,'sourceType':source,'status':'Posted','reference':'FIXTURE-123','narration':'Original payment','totalDebit':sum(l.get('debit',0) for l in lines),'totalCredit':sum(l.get('credit',0) for l in lines),'lines':lines,'meta':meta or []}
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
 def request(body=None,query='?entity=TTI',endpoint='expenses_v1'):
  req={'uri':'/api/'+endpoint+'.php'+query,'method':'GET' if body is None else 'POST','body':{} if body is None else {'csrf':'fixture',**body}}
  r=subprocess.run(['php','-d',f'session.save_path={root}',str(harness),str(app/'api'/f'{endpoint}.php'),json.dumps(req)],text=True,capture_output=True,env=env)
  assert r.returncode==0,r.stdout+r.stderr
  payload,status=r.stdout.rsplit('\n__STATUS__',1);return int(status),json.loads(payload)

 # Saved estimates do not create invoices, taxes, stock, payments or journals.
 books.write_text(json.dumps(initial));set_user()
 estimate={'entity':'TTI','action':'save_estimated_bill','requestKey':'estimate-create-0001','supplier':'Fixture Shipping Services','reference':'SHIP-1 / bags 40 kg','purpose':'Missing packaging invoice','date':'2026-10-01','currency':'PKR','quantity':1000,'unitRate':50,'packingSize':'40 kg','specification':'Woven','taxRate':18,'whtRate':5,'whtBase':'TOTAL','rateBasis':'GROSS'}
 st,res=request(estimate);assert st==200,(st,res)
 eid=res['result']['estimateId'];state=json.loads(books.read_text());saved=state['estimatedExpenseBills'][eid]
 assert saved['grossAmount']==59000 and saved['whtAmount']==2950 and saved['supplierNet']==56050
 assert state['journals']==initial['journals'] and not state.get('supplierBills') and not state.get('bagSupplierBills'), [(k, v, initial['journals'].get(k)) for k,v in state['journals'].items() if v!=initial['journals'].get(k)]
 stable=books.read_bytes();st,res=request(estimate);assert st==200 and res['result']['duplicate'] and books.read_bytes()==stable,(st,res)
 assert request({**estimate,'unitRate':60})[0]==409 and books.read_bytes()==stable
 assert request({**estimate,'requestKey':'estimate-duplicate-01'})[0]==422 and books.read_bytes()==stable
 for change in [{'whtRate':''},{'whtRate':None},{'whtBase':''},{'quantity':-1},{'unitRate':1e99},{'currency':'EUR'}]:
  assert request({**estimate,'reference':'OTHER','requestKey':'estimate-invalid-01',**change})[0]==422 and books.read_bytes()==stable,change
 # Gross includes withholding already; changing the deduction cannot inflate cost.
 edit={**estimate,'estimateId':eid,'version':1,'requestKey':'estimate-edit-0001','whtRate':10,'whtBase':'BASE'}
 st,res=request(edit);assert st==200,(st,res);saved=res['estimatedBills'][0];assert saved['grossAmount']==59000 and saved['whtAmount']==5000 and saved['supplierNet']==54000
 stable=books.read_bytes();assert request({**edit,'requestKey':'estimate-stale-01'})[0]==422 and books.read_bytes()==stable
 net={**estimate,'reference':'SHIP-2','requestKey':'estimate-net-0001','quantity':1,'unitRate':56050,'rateBasis':'NET'}
 st,res=request(net);assert st==200,(st,res);netid=res['result']['estimateId'];assert next(r for r in res['estimatedBills'] if r['id']==netid)['grossAmount']==59000
 # Comparables use full original bills, their actual quantities, packing and currency.
 state=json.loads(books.read_text());state['bagSupplierBills']={}
 for id,packing,qty,base,currency in [('B40','40 kg',200,10000,'PKR'),('B50','50 kg',100,7000,'PKR'),('USD40','40 kg',200,10000,'USD')]:
  state['journals']['J-'+id]=journal('J-'+id,'2026-09-01','EXPORT_BAG_SUPPLIER_BILL',[{'account':'1340','debit':base,'credit':0},{'account':'2140','debit':0,'credit':base}])
  state['bagSupplierBills'][id]={'entity':'TTI','status':'Posted','journalId':'J-'+id,'supplier':'Fixture Shipping Services','sellerInvoice':id,'sellerInvoiceDate':'2026-09-01','packingSize':packing,'bagType':'Woven','currency':currency,'bags':qty,'baseAmount':base,'gstRate':18,'totalAmount':base*1.18}
 state['bagSupplierBills']['DRAFT']={**state['bagSupplierBills']['B40'],'status':'Draft'};state['bagSupplierBills']['MISSING']={**state['bagSupplierBills']['B40'],'journalId':'NONE'}
 books.write_text(json.dumps(state));st,res=request();rates={r['key']:r for r in res['estimateRateHistory']};assert rates['bagSupplierBills|B40']['unitRate']==50 and rates['bagSupplierBills|B50']['unitRate']==70
 assert 'bagSupplierBills|DRAFT' not in rates and 'bagSupplierBills|MISSING' not in rates
 based={**estimate,'reference':'SHIP-3','requestKey':'estimate-based-0001','rateSource':'bagSupplierBills|B40'}
 st,res=request(based);assert st==200,(st,res);bid=res['result']['estimateId'];assert next(r for r in res['estimatedBills'] if r['id']==bid)['rateSourceSnapshot']['quantity']==200
 stable=books.read_bytes()
 for change in [{'packingSize':'50kg'},{'currency':'USD'},{'supplier':'Other Supplier'},{'specification':'Other'},{'date':'2026-08-01'},{'rateBasis':'NET'},{'unitRate':51},{'taxRate':19}]:
  st,res=request({**based,'reference':'NO-MATCH','requestKey':'estimate-source-invalid',**change});assert st==422 and books.read_bytes()==stable,(change,st,res)
 # An actual bill can arrive later with an earlier invoice date. Its original date is retained.
 resolve={'entity':'TTI','action':'resolve_estimated_bill','estimateId':bid,'version':1,'actualBillKey':'bagSupplierBills|B40','resolutionNote':'This invoice covers the estimated shipment packaging','requestKey':'estimate-replace-01'}
 st,res=request({**resolve,'actualBillKey':'bagSupplierBills|USD40'});assert st==422 and books.read_bytes()==stable,(st,res)
 st,res=request(resolve);assert st==200,(st,res);replaced=next(r for r in res['estimatedBills'] if r['id']==bid);assert replaced['status']=='Replaced' and replaced['variance']==11800-59000
 stable=books.read_bytes();assert request(resolve)[1]['result']['duplicate'] and books.read_bytes()==stable
 assert request({**resolve,'estimateId':netid,'requestKey':'estimate-double-replace'})[0]==422 and books.read_bytes()==stable
 # Cancelling the linked actual restores review status, without changing the estimate audit.
 state=json.loads(books.read_text());state['bagSupplierBills']['B40']['status']='Cancelled';books.write_text(json.dumps(state));st,res=request();r=next(r for r in res['estimatedBills'] if r['id']==bid);assert r['replacementValid'] is False and r['variance'] is None
 assert request({**resolve,'version':2,'actualBillKey':'bagSupplierBills|B50','requestKey':'estimate-relink-01'})[0]==200
 cancel={'entity':'TTI','action':'cancel_estimated_bill','estimateId':eid,'version':2,'cancelDate':'2026-10-02','resolutionNote':'Supplier confirmed no charge for this service','requestKey':'estimate-cancel-0001'}
 st,res=request(cancel);assert st==200,(st,res);assert next(r for r in res['estimatedBills'] if r['id']==eid)['status']=='Cancelled'
 # Source invoice visibility remains under the original Purchases permission.
 user['permissions']['Accounts']['purchases']=[];set_user();st,res=request();assert res['estimateRateHistory']==[]
 hidden=next(r for r in res['estimatedBills'] if r['id']==bid);assert hidden['rateSourceRestricted'] and hidden['replacementRestricted'] and not any(k in hidden for k in ['rateSourceSnapshot','actualBillSnapshot']) and hidden['actualBillAmount'] is None and hidden['variance'] is None
 stable=books.read_bytes();assert request({**resolve,'version':3,'actualBillKey':'bagSupplierBills|B50','requestKey':'estimate-hidden-source'})[0]==422 and books.read_bytes()==stable
 # Read-only and Create-only staff cannot edit, replace, cancel, or cross entities.
 user['permissions']['Accounts']['expenses']=['View','Create'];user['permissions']['Accounts']['entity-tti']=['View','Create'];set_user();stable=books.read_bytes()
 assert request({**resolve,'version':3,'requestKey':'estimate-forbidden-01'})[0]==403 and books.read_bytes()==stable
 assert request({**edit,'version':3,'requestKey':'estimate-forbidden-02'})[0]==403 and books.read_bytes()==stable
 assert request({**estimate,'entity':'BRM','reference':'BRM','requestKey':'estimate-forbidden-03'})[0]==403 and books.read_bytes()==stable
 user['permissions']['Accounts']['expenses']=['View'];set_user();assert request({**estimate,'reference':'READONLY','requestKey':'estimate-forbidden-04'})[0]==403 and books.read_bytes()==stable
 final=json.loads(books.read_text());assert len(final['journals'])==len(initial['journals'])+3 and all(j in final['journals'] for j in initial['journals'])
 assert len(final['expenseAudit'])>=6
 print('PASS estimated bill endpoint: explicit WHT, actual quantities, comparable evidence, no journal/payment/tax writes, replay, versions, lifecycle, currency and staff scope')
