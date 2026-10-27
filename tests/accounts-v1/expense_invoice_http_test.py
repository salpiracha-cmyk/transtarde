"""Actual authenticated expense invoice capture, settlement, correction and source linkage."""
import json,os,pathlib,shutil,subprocess,tempfile
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='staff-payment-amend-') as temp:
 root=pathlib.Path(temp);app=root/'app';shutil.copytree(ROOT,app,ignore=shutil.ignore_patterns('.git','node_modules','__pycache__'))
 private=root/'transtrade_private';private.mkdir();auth=private/'auth.json';books=private/'accounts.json';env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
 user={'id':1,'username':'fixture','full_name':'Fixture Accountant','role':'Staff','active':True,'session_version':1,'permissions':{'Accounts':{'entity-tti':['View','Create','Edit'],'supplier':['View','Create','Edit'],'expenses':['View','Create','Edit'],'reports':['View']}}}
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
 def request(body=None,query='?entity=TTI',endpoint='expenses_v1'):
  req={'uri':'/api/'+endpoint+'.php'+query,'method':'GET' if body is None else 'POST','body':{} if body is None else {'csrf':'fixture',**body}}
  r=subprocess.run(['php','-d',f'session.save_path={root}',str(harness),str(app/'api'/f'{endpoint}.php'),json.dumps(req)],text=True,capture_output=True,env=env)
  assert r.returncode==0,r.stdout+r.stderr
  payload,status=r.stdout.rsplit('\n__STATUS__',1);return int(status),json.loads(payload)

 # Canonical service providers are offered with their expense mapping, without a second Expense Recipient.
 fixture=json.loads(auth.read_text());fixture['masters']['business_parties'].append({'id':'NAVED','values':['Naveed — Sales Tax Consultant','','Service Provider','','','','','','','','Active','',json.dumps({'service':{'expenseAccountCode':'6700','type':'Tax consultant'}})]});auth.write_text(json.dumps(fixture))
 st,payload=request();provider=next(p for p in payload['recipientProfiles'] if p['id']=='PARTY|NAVED');assert provider['accountCode']=='6700' and provider['expenseCategory']=='PROFESSIONAL' and provider['kind']=='BUSINESS'
 service={'entity':'TTI','action':'capture_expense_invoice','requestKey':'provider-invoice-01','payee':provider['name'],'payeeId':provider['id'],'invoiceNo':'NAVED-001','invoiceDate':'2026-09-01','dueDate':'2026-09-15','billMonth':'2026-09','amount':2000,'expenseLines':[{'category':'PROFESSIONAL','purpose':'Monthly return filing','amount':2000,'expenseArea':'OFFICE','expenseFor':'SHARED','personalTreatment':'COMPANY'}]}
 stable=books.read_bytes();st,preview=request({**service,'preview':True});assert st==200,(st,preview);assert books.read_bytes()==stable
 assert [(l['account'],l['debit'],l['credit']) for l in preview['previews'][0]['lines']]==[('6700',2000,0),('2130',0,2000)]
 st,res=request(service);assert st==200,(st,res);state=json.loads(books.read_text());assert state['supplierBills'][res['result']['invoiceId']]['payeeId']=='PARTY|NAVED'
 assert not any(p['id']=='PARTY|NAVED' for p in state.get('paymentPayees',{}).values())
 books.write_text(json.dumps(initial))
 # Start from original balanced books with a separate B/F and original payment.
 fixture=json.loads(auth.read_text());fixture['masters']['business_parties'].append({'id':'KE','values':['K-Electric','','Service Provider','','','','','','','','Active']});fixture['masters']['mills']=[{'id':'MILL','values':['Fixture Mill','','Own Mill','','','Active','','TTI']},{'id':'OFFICE','values':['Fixture Office','','Office','','','Active','','TTI']}];auth.write_text(json.dumps(fixture))
 invoice={'entity':'TTI','action':'capture_expense_invoice','requestKey':'expense-invoice-capture-01','payee':'Fixture Shipping Services','invoiceNo':'EXP-001','invoiceDate':'2026-09-01','dueDate':'2026-09-15','billMonth':'2026-09','amount':1000.08,'expenseLines':[{'category':'PROFESSIONAL','purpose':'Original service','amount':1000.08,'expenseArea':'OFFICE','expenseFor':'SHARED','personalTreatment':'COMPANY'}]}
 before=books.read_bytes();st,preview=request({**invoice,'preview':True});assert st==200,(st,preview);assert books.read_bytes()==before
 assert len(preview['previews'])==1 and [(l['account'],l['debit'],l['credit']) for l in preview['previews'][0]['lines']]==[('6700',1000.08,0),('2130',0,1000.08)],preview
 for change in [{'entity':'BRM'},{'entity':'TG'},{'invoiceDate':'2026-02-30'},{'dueDate':'2026-08-31'},{'invoiceNo':''},{'paymentAccountId':'B1'},{'amount':1000},{'amount':'1e309'}]:
  status,res=request({**invoice,**change});assert status in [403,422],(status,res);assert books.read_bytes()==before
 st,saved=request(invoice);assert st==200,(st,saved);bid=saved['result']['invoiceId'];jid=saved['result']['journalId'];state=json.loads(books.read_text());assert state['supplierBills'][bid]['supplierPayableTotal']==1000.08
 assert state['journals'][jid]['sourceType']=='EXPENSE_INVOICE' and not state['journals'][jid]['lines'][-1].get('bankAccountId')
 committed=books.read_bytes();assert request(invoice)[1]['result']['invoiceId']==bid and books.read_bytes()==committed
 duplicate=request({**invoice,'requestKey':'expense-invoice-duplicate'});assert duplicate[0]==422 and books.read_bytes()==committed,duplicate
 assert request({**invoice,'invoiceNo':'Different number'})[0]==409 and books.read_bytes()==committed
 # Unpaid correction keeps source ID / public Post ID and rejects stale updates.
 corrected={**invoice,'invoiceId':bid,'expectedJournalId':jid,'requestKey':'expense-invoice-amend-01','reason':'Correct original supplier amount','amount':123456.78,'expenseLines':[{**invoice['expenseLines'][0],'amount':123456.78}]}
 stable=books.read_bytes();assert request({**corrected,'reason':''})[0]==422 and books.read_bytes()==stable
 st,preview=request({**corrected,'preview':True});assert st==200,(st,preview);assert books.read_bytes()==committed
 st,amended=request(corrected);assert st==200,(st,amended);new=amended['result']['journalId'];assert amended['result']['invoiceId']==bid and new!=jid
 state=json.loads(books.read_text());assert state['journals'][jid]['amendedByPostId']==new and state['journals'][new]['meta']['publicPostId']==jid
 stable=books.read_bytes();assert request({**corrected,'requestKey':'expense-invoice-stale-01'})[0]==422 and books.read_bytes()==stable
 # Explicit partial payments reduce only the original liability, not the expense again.
 payment={'entity':'TTI','action':'post_bill_payment','requestKey':'invoice-partial-payment-01','supplier':invoice['payee'],'category':'SERVICE','selectedBills':[bid+'|EXPENSE|'+bid],'explicitBillSelection':True,'paymentIntent':'PARTIAL','selectedOutstanding':123456.78,'amount':100000,'paymentMode':'BANK','bankAccountId':'B1','bankPaymentMethod':'ONLINE_BANKING','bankPaymentDate':'2026-09-03','date':'2026-09-03'}
 st,res=request({**payment,'date':'2026-08-31'},endpoint='supplier_settlements');assert st==422,(st,res);assert books.read_bytes()==stable
 st,paid=request(payment,endpoint='supplier_settlements');assert st==200,(st,paid);state=json.loads(books.read_text());p=state['journals'][paid['result']['journalId']]
 assert [(x['account'],x['debit'],x['credit']) for x in p['lines']]==[('2130',100000,0),('1110',0,100000)],p
 st,data=request(endpoint='expenses_v1');row=next(r for r in data['expenseInvoices'] if r['id']==bid);assert row['outstanding']==23456.78 and row['paymentStatus']=='Part-paid' and row['cashPaid']==100000,row
 stable=books.read_bytes();assert request({**corrected,'expectedJournalId':new,'requestKey':'paid-invoice-amend-hold'})[0]==422 and books.read_bytes()==stable
 assert request({'entity':'TTI','action':'cancel_expense_invoice','invoiceId':bid,'expectedJournalId':new,'reason':'Cannot cancel paid invoice','requestKey':'paid-invoice-cancel-hold'})[0]==422 and books.read_bytes()==stable
 assert request({'entity':'TTI','postId':new,'date':'2026-09-01','reason':'Raw edit must be blocked','lines':state['journals'][new]['lines']},query='',endpoint='accounts_post_amend')[0]==422 and books.read_bytes()==stable
 st,final=request({**payment,'requestKey':'invoice-final-payment-01','amount':23456.78,'selectedOutstanding':23456.78,'date':'2026-09-10','bankPaymentDate':'2026-09-10'},endpoint='supplier_settlements');assert st==200,(st,final)
 data=request(endpoint='expenses_v1')[1];row=next(r for r in data['expenseInvoices'] if r['id']==bid);assert row['outstanding']==0 and row['paymentStatus']=='Paid',row
 state=json.loads(books.read_text());assert round(sum(l['debit']-l['credit'] for j in state['journals'].values() for l in j['lines'] if l['account']=='6700'),2)==123456.78
 # Cancellation removes the unpaid invoice from settlement while keeping its immutable source.
 second={**invoice,'requestKey':'expense-second-invoice-01','invoiceNo':'EXP-002'};st,saved=request(second);assert st==200,(st,saved);cancelId=saved['result']['invoiceId'];cancelJ=saved['result']['journalId']
 cancel={'entity':'TTI','action':'cancel_expense_invoice','invoiceId':cancelId,'expectedJournalId':cancelJ,'reason':'Supplier cancelled this invoice','requestKey':'invoice-cancel-request-01'}
 stable=books.read_bytes();assert request({**cancel,'reason':'x'})[0]==422 and books.read_bytes()==stable
 assert request({**cancel,'preview':True})[0]==200 and books.read_bytes()==stable
 st,cancelled=request(cancel);assert st==200,(st,cancelled);assert not any(r['billId']==cancelId for r in request(endpoint='supplier_settlements')[1]['payables'])
 stable=books.read_bytes();assert request(cancel)[0]==200 and books.read_bytes()==stable
 # Mill K-Electric invoice is a liability and production cost, not a payment.
 utility={'entity':'TTI','action':'capture_utility_invoice','requestKey':'utility-invoice-capture-01','payee':'K-Electric','utilityType':'ELECTRICITY','locationId':'MILL','utilityMasterId':'','billMonth':'2026-09','amount':1000.08,'accountReference':'MANUAL-CONSUMER-001','invoiceNo':'KE-001','invoiceDate':'2026-10-01','dueDate':'2026-10-15','accountingTreatment':'BUSINESS_EXPENSE','readFrom':'2026-09-01','readTo':'2026-09-30','previousReading':100,'currentReading':200}
 st,saved=request(utility);assert st==200,(st,saved);ubid=saved['result']['invoiceId'];uj=saved['result']['journalId'];state=json.loads(books.read_text());assert state['supplierBills'][ubid]['productionCostEligible'] and state['journals'][uj]['lines'][0]['account']=='5200'
 assert state['supplierBills'][ubid]['accountReference']=='MANUAL-CONSUMER-001'
 assert not state.get('utilityPayments'),state.get('utilityPayments')
 # Projection uses bill month, original invoice and exact later allocation/bank date.
 projection=root/'projection.php';projection.write_text("<?php require $argv[1].'/api/accounts_sales_tax_core.php';require $argv[1].'/api/monthly_tax_core.php';$s=json_decode(file_get_contents($argv[2]),true);echo json_encode(mt_projection($s,[],[],['B1'=>['name'=>'Fixture Bank','number'=>'123456']],'TTI','2026-09'));")
 def tax():return json.loads(subprocess.check_output(['php',str(projection),str(app),str(books)],env=env,text=True))
 r=tax();assert r['utilities'][0]['account']=='MANUAL-CONSUMER-001',r
 assert len(r['utilities'])==1 and r['utilities'][0]['payments']==[] and r['utilities'][0]['invoices'][0]['number']=='KE-001',r
 upay={**payment,'requestKey':'utility-partial-payment-01','supplier':'K-Electric','category':'UTILITIES','selectedBills':[ubid+'|EXPENSE|'+ubid],'amount':1000,'selectedOutstanding':1000.08,'date':'2026-10-03','bankPaymentDate':'2026-10-02'}
 st,res=request(upay,endpoint='supplier_settlements');assert st==200,(st,res);r=tax();assert r['utilities'][0]['payments'][0]['invoicePaid']==1000 and r['utilities'][0]['payments'][0]['sources'][0]['paymentDate']=='2026-10-02',r
 assert request({**utility,'requestKey':'utility-duplicate-invoice'})[0]==422
 # A second direct expense cannot charge the already captured supplier invoice again.
 stable=books.read_bytes();direct={**invoice,'action':'pay_expense','reference':'EXP-001','paymentDate':'2026-09-03','paymentAccountId':'B1','bankPaymentMethod':'ONLINE_BANKING','bankPaymentDate':'2026-09-03','requestKey':'duplicate-paid-source-01'}
 assert request(direct)[0]==422 and books.read_bytes()==stable
 # Permission and company boundaries remain the original Expense authority.
 good_auth=auth.read_bytes();changed=json.loads(auth.read_text());changed['users'][0]['permissions']['Accounts']['expenses']=['View'];auth.write_text(json.dumps(changed));stable=books.read_bytes()
 assert request({**invoice,'invoiceNo':'DENIED','requestKey':'denied-invoice-test-01'})[0]==403 and books.read_bytes()==stable
 assert request({**corrected,'expectedJournalId':new,'requestKey':'denied-amend-test-01'})[0]==403 and books.read_bytes()==stable
 auth.write_bytes(good_auth)
 # Manual consumer identity also survives the original paid-utility route.
 direct_utility={**utility,'action':'pay_utility','location':'MILL','locationName':'Fixture Mill','requestKey':'manual-paid-utility-01','reference':'KE-DIRECT-001','billMonth':'2026-10','paymentDate':'2026-10-03','paymentAccountId':'B1','bankPaymentMethod':'ONLINE_BANKING','bankPaymentDate':'2026-10-03','accountReference':'MANUAL-PAID-ACCOUNT'}
 st,res=request(direct_utility);assert st==200,(st,res);uid=res['result']['utilityPaymentId'];state=json.loads(books.read_text());assert state['utilityPayments'][uid]['accountReference']=='MANUAL-PAID-ACCOUNT'
 assert state['journals'][res['result']['journalId']]['meta']['accountReference']=='MANUAL-PAID-ACCOUNT'
 # Post ID deletion previews and cancels the invoice plus its two payments atomically.
 deletion={'entity':'TTI','action':'delete_post','postId':new,'reason':'Supplier reversed the entire invoice'}
 stable=books.read_bytes();original_state=json.loads(stable);original_invoice_lines=original_state['journals'][new]['lines']
 st,impact=request({**deletion,'preview':True},query='',endpoint='accounts_post_amend');assert st==200,(st,impact)
 assert books.read_bytes()==stable,'Deletion review must be read-only'
 expected={new,paid['result']['journalId'],final['result']['journalId']}
 assert {r['postId'] for r in impact['deletionImpact']}=={jid,paid['result']['journalId'],final['result']['journalId']},impact
 assert any(r['register']=='supplierBills' and r['id']==bid and r['afterStatus']=='Cancelled' for r in impact['affectedSources'])
 # Expense access alone must not grant cancellation of linked supplier payments.
 fixture=json.loads(auth.read_text());fixture['users'][0]['permissions']['Accounts']['supplier']=['View'];auth.write_text(json.dumps(fixture))
 st,denied=request({**deletion,'preview':True},query='',endpoint='accounts_post_amend');assert st==422,(st,denied);assert books.read_bytes()==stable
 auth.write_bytes(good_auth)
 # Stale confirmation and failed dependencies leave every source untouched.
 st,res=request({**deletion,'deletionFingerprint':'stale'},query='',endpoint='accounts_post_amend');assert st==422 and books.read_bytes()==stable,(st,res)
 st,res=request({**deletion,'deletionFingerprint':impact['deletionFingerprint']},query='',endpoint='accounts_post_amend');assert st==200,(st,res)
 state=json.loads(books.read_text());assert set(res['result']['cancelledPostIds'])==expected
 assert state['supplierBills'][bid]['status']=='Cancelled' and state['journals'][new]['lines']==original_invoice_lines
 assert all(state['supplierSettlements'][sid]['status']=='Reversed' for sid in [paid['result']['id'],final['result']['id']])
 # Management can also cancel a B/F and its linked supplier payment in one review.
 fixture=json.loads(auth.read_text());fixture['users'][0]['role']='Super Admin';auth.write_text(json.dumps(fixture))
 deletion={'entity':'TTI','action':'delete_post','postId':opening,'reason':'Incorrect supplier opening balance'}
 stable=books.read_bytes();st,impact=request({**deletion,'preview':True},query='',endpoint='accounts_post_amend');assert st==200,(st,impact)
 assert books.read_bytes()==stable and {r['postId'] for r in impact['deletionImpact']}=={opening,pay}
 st,res=request({**deletion,'deletionFingerprint':impact['deletionFingerprint']},query='',endpoint='accounts_post_amend');assert st==200,(st,res)
 state=json.loads(books.read_text());assert state['supplierSettlements']['S1']['status']=='Reversed' and state['journals'][opening]['openingReversalId']
 # An on-account payment owns its unused advance; cancel later applications first.
 source_post='POST-2026-00090';apply_post='POST-2026-00091'
 on_account=journal(source_post,'2026-10-01','SUPPLIER_PAYMENT',[{'account':'2130','debit':100,'credit':0},{'account':'1250','debit':50,'credit':0},{'account':'1110','bankAccountId':'B1','currency':'PKR','bankDebit':0,'bankCredit':150,'debit':0,'credit':150}],{'settlementId':'ON'})
 applied=journal(apply_post,'2026-10-02','SUPPLIER_ADVANCE_APPLIED',[{'account':'2130','debit':30,'credit':0},{'account':'1250','debit':0,'credit':30}],{'settlementId':'APPLY','advanceId':'A1'})
 state={**initial,'journals':{source_post:on_account,apply_post:applied},'supplierSettlements':{'ON':{'id':'ON','entity':'TTI','status':'Posted','type':'Bank / Cash Supplier Payment','date':'2026-10-01','journalId':source_post,'amount':150,'advanceId':'A1','advanceAmount':50,'allocations':[]},'APPLY':{'id':'APPLY','entity':'TTI','status':'Posted','type':'Supplier Advance Applied','date':'2026-10-02','journalId':apply_post,'amount':30,'advanceId':'A1','allocations':[]}},'supplierAdvances':{'A1':{'id':'A1','entity':'TTI','journalId':source_post,'amount':50,'allocatedAmount':30,'availableAmount':20,'status':'Partly Applied'}},'paymentPlans':{'P1':{'status':'Posted','groups':{'Vendor':{'settlementId':'ON'}}}}}
 books.write_text(json.dumps(state));stable=books.read_bytes();deletion={'entity':'TTI','action':'delete_post','postId':source_post,'reason':'Wrong on account payment'}
 st,impact=request({**deletion,'preview':True},query='',endpoint='accounts_post_amend');assert st==200,(st,impact)
 assert books.read_bytes()==stable and {r['postId'] for r in impact['deletionImpact']}=={source_post,apply_post}
 st,res=request({**deletion,'deletionFingerprint':impact['deletionFingerprint']},query='',endpoint='accounts_post_amend');assert st==200,(st,res)
 after=json.loads(books.read_text());assert after['supplierAdvances']['A1']['status']=='Cancelled' and after['supplierAdvances']['A1']['availableAmount']==after['supplierAdvances']['A1']['allocatedAmount']==0
 assert all(p['status']=='Reversed' for p in after['supplierSettlements'].values()) and after['paymentPlans']['P1']['status']=='Open' and 'settlementId' not in after['paymentPlans']['P1']['groups']['Vendor']
 assert after['journals'][source_post]['lines']==on_account['lines'] and after['journals'][apply_post]['lines']==applied['lines']
 # Broken or cyclic linked journals provide a repair route and roll back the whole cascade.
 state['supplierSettlements']['APPLY']['journalId']='MISSING';books.write_text(json.dumps(state));stable=books.read_bytes()
 st,res=request({**deletion,'preview':True},query='',endpoint='accounts_post_amend');assert st==422 and 'Party Payments' in res['error'] and books.read_bytes()==stable,(st,res)
 state['supplierSettlements']['APPLY']['journalId']=source_post;books.write_text(json.dumps(state));stable=books.read_bytes()
 st,res=request({**deletion,'preview':True},query='',endpoint='accounts_post_amend');assert st==422 and 'cycle' in res['error'] and books.read_bytes()==stable,(st,res)
 print('PASS actual expense invoices: full recognition, precise partial/final payments, no second expense, unpaid corrections, stable public IDs, cancellation, replay, stale/paid-source holds, utility tax month/bank linkage, preview, staff scope, linked Post ID deletion and advance cancellation')

subprocess.run([os.sys.executable,str(ROOT/"tests/accounts-v1/expense_estimates_http_test.py")],check=True)
