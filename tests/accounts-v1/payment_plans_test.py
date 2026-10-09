"""Accounts recurring bills, meter periods, audited reversal, and saving profit using real PHP APIs."""
import json, os, pathlib, shutil, subprocess, tempfile

ROOT = pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tti-api-permissions-') as directory:
    root = pathlib.Path(directory)
    app = root / 'app'
    shutil.copytree(ROOT, app, ignore=shutil.ignore_patterns('.git', 'node_modules'))
    private = root / 'transtrade_private'
    private.mkdir()
    harness = root / 'request.php'
    harness.write_text(r'''<?php
$req=json_decode($argv[2],true);$_SERVER['REQUEST_URI']=$req['uri'];$_SERVER['REQUEST_METHOD']=$req['method']??'POST';$_SERVER['REMOTE_ADDR']='192.0.2.42';
parse_str((string)(parse_url($req['uri'],PHP_URL_QUERY)??''),$_GET);$_POST=$req['form']??[];
session_name('TRANSTRADE_SESSION');session_id('workflow-fixture');session_start();
$_SESSION=['user_id'=>1,'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];session_write_close();
$GLOBALS['fixtureBody']=json_encode($req['body']);$GLOBALS['statusFile']=$argv[3];
class FixtureWorkflowInput{public $context;private $offset=0;
 function stream_open($p,$m,$o,&$opened){return $p==='php://input';}
 function stream_read($n){$s=substr($GLOBALS['fixtureBody'],$this->offset,$n);$this->offset+=strlen($s);return $s;}
 function stream_eof(){return $this->offset>=strlen($GLOBALS['fixtureBody']);}function stream_stat(){return [];}}
stream_wrapper_unregister('php');stream_wrapper_register('php',FixtureWorkflowInput::class);
register_shutdown_function(static function(){file_put_contents($GLOBALS['statusFile'],(string)(http_response_code()?:200));});
require $argv[1];
''')
    auth = private / 'auth.json'
    books = private / 'accounts.json'
    env = {k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
    user = {'id':1,'username':'fixture','full_name':'Fixture','role':'Staff','active':True,'session_version':1,
            'permissions':{'Accounts':{'entity-tti':['View','Create','Edit','Approve'],'assets':['View','Edit']}}}
    def bank(identity, entity, currency='PKR'):
        return {'id':identity,'values':['Company Account',entity,'','Fixture Account','Fixture Bank','','Pakistan',currency,'123456','','','','Accounts','Active']}
    masters = {'banks':[bank('TTI-B','TTI'),bank('BRM-B','BRM'),bank('TG-B','TG','USD')]}
    def seed(store=None):
        auth.write_text(json.dumps({'users':[user],'masters':masters,'audit':[]}))
        books.write_text(json.dumps(store or {'revision':0,'journals':{}}))
    def request(endpoint, body, query='?entity=TTI', form=None, method='POST'):
        req={'uri':'/api/'+endpoint+'.php'+query,'body':{'csrf':'fixture',**body},'form':form or {},'method':method}
        status_file=root/'status.txt'
        r=subprocess.run(['php','-d',f'session.save_path={root}',str(harness),str(app/'api'/f'{endpoint}.php'),json.dumps(req),str(status_file)],env=env,text=True,capture_output=True)
        assert r.returncode==0, r.stdout+r.stderr
        try: return int(status_file.read_text()),json.loads(r.stdout)
        except Exception: raise AssertionError(r.stdout+r.stderr)
    user['permissions']={'Accounts':{'supplier':['View','Create','Edit'],'entity-tti':['View','Create','Edit']}}
    masters={'banks':[bank('TTI-B','TTI'),bank('TTI-P','TTI'),bank('BRM-B','BRM')]}
    masters['banks'][1]['values'][0]='Personal Account'
    masters['banks'][1]['values'][2]='Fixture Owner'
    masters['banks'][1]['values'][8]='234567'
    def bill(identity, broker, supplier, amount, due, truck, brokerage=0):
        allocations=[{'sourceKey':identity+'-truck','supplierPayableShare':amount,'component':'COMMODITY','payee':supplier or broker,'truck':truck,'dueDate':due,'receiptDate':'2026-09-01'}]
        if brokerage:allocations.append({'sourceKey':identity+'-truck|BROKERAGE','supplierPayableShare':brokerage,'component':'BROKERAGE','payee':broker,'truck':truck,'dueDate':due,'receiptDate':'2026-09-01'})
        return {'id':identity,'entity':'TTI','status':'Posted','commodity':'RICE','billDate':'2026-09-01','broker':broker,'relationshipName':supplier,'billNo':identity,'journalId':'POST-2026-'+identity,'supplierPayableTotal':amount+brokerage,'finalCommodityValue':amount,'brokerageGross':brokerage,'brokerageWithholding':0,'receiptAllocations':allocations}
    store={'revision':0,'journals':{'OPEN':{'id':'OPEN','entity':'TTI','status':'Posted','date':'2026-09-01','sourceType':'OPENING_BALANCE_BF','lines':[{'account':'1110','bankAccountId':'TTI-B','debit':400,'credit':0},{'account':'1110','bankAccountId':'TTI-P','debit':500,'credit':0}]}},'commodityBills':{'A':bill('A','Broker A','Supplier A',100,'2026-09-05','TRUCK-A',20),'B':bill('B','Broker A','Supplier A',200,'2026-09-06','TRUCK-B'),'C':bill('C','','Supplier C',300,'2026-09-07','TRUCK-C')},'supplierAdvances':{},'supplierSettlements':{}}
    seed(store)
    counter=0
    def call(body, expected=200):
        global counter
        counter+=1
        status,data=request('payment_plans',{'entity':'TTI','requestKey':f'payment-plan-fixture-{counter:04}',**body})
        assert status==expected,(status,data,body)
        return data
    def read():
        status,data=request('payment_plans',{},method='GET');assert status==200,(status,data);return data
    payload=read();assert len(payload['banks'])==3 and any(b['id']=='CASH|TTI' for b in payload['banks']),payload
    assert any(x['accountType'] in ['Personal Account','Proprietor / Owner Account'] for x in payload['banks'])
    assert {r['group'] for r in payload['rows']}=={'Broker A','Supplier C'},payload
    keys=[r['rowKey'] for r in payload['rows']]
    call({'action':'create','mode':'PARTY','bankIds':['BRM-B'],'rowKeys':keys,'date':'2026-10-07'},409)
    call({'action':'create','mode':'PARTY','bankIds':['TTI-B'],'rowKeys':keys,'date':'2026-10-07'},409)
    plan=call({'action':'create','mode':'PARTY','bankIds':['TTI-B','TTI-P'],'rowKeys':keys,'date':'2026-10-07'})['result']['plan']
    post={'action':'post','planId':plan['id'],'version':plan['version'],'group':'Broker A','date':'2026-10-07','rowKeys':plan['groups']['Broker A']['rowKeys'],'sources':[{'type':'BANK','bankAccountId':'TTI-B','method':'CHEQUE','reference':'1001','amount':120},{'type':'BANK','bankAccountId':'TTI-P','method':'ONLINE_BANKING','reference':'REF-1','amount':100},{'type':'THIRD_PARTY','relationship':'OTHER_THIRD_PARTY','payer':'ABC','reference':'ABC-CHEQUE','amount':100}],'drafts':{'Supplier C':{'date':'2026-10-07','rowKeys':plan['groups']['Supplier C']['rowKeys'],'sources':[{'type':'BANK','bankAccountId':'TTI-P','method':'CHEQUE','reference':'1002','amount':300}]}},'requestKey':'post-party-replay-fixture-0001'}
    result=call(post)['result'];plan=result['plan'];sett=result['settlement'];assert plan['drafts']['Supplier C']['sources'][0]['reference']=='1002';assert sett['netPayment']==320 and len(sett['allocations'])==3,sett
    saved=json.loads(books.read_text());j=saved['journals'][sett['journalId']];assert j['totalDebit']==j['totalCredit']==320,j
    assert {l['account'] for l in j['lines']}=={'2110','2120','1110','2170'},j
    assert {l['counterparty'] for l in j['lines'] if l['account']=='2110'}=={'Supplier A'},j
    assert {l['counterparty'] for l in j['lines'] if l['account']=='2120'}=={'Broker A'},j
    count=len(saved['journals']);assert call(post)['result']['settlement']['id']==sett['id'];assert len(json.loads(books.read_text())['journals'])==count
    call({**post,'sources':[{'type':'BANK','bankAccountId':'TTI-B','amount':320,'method':'CHEQUE','reference':'2001'}]},409)
    call({'action':'close','planId':plan['id'],'version':plan['version']},409)
    result=call({'action':'correct','planId':plan['id'],'version':plan['version'],'group':'Broker A','date':'2026-10-07','reason':'Correct cheque split'})['result'];plan=result['plan'];saved=json.loads(books.read_text());assert saved['supplierSettlements'][sett['id']]['status']=='Reversed'
    restored=read();assert sum(r['outstanding'] for r in restored['rows'] if r['group']=='Broker A')==320,restored
    assert any(a['action']=='PAYMENT_PLAN_CORRECTION' for a in saved['workflowAudit'])
    post.update(version=plan['version'],sources=[{'type':'BANK','bankAccountId':'TTI-B','method':'CHEQUE','reference':'1001','amount':320}],requestKey='post-party-corrected-fixture-0001')
    plan=call(post)['result']['plan']
    # The supplier with no broker uses supplier grouping; whole plan closes only after all groups.
    plan=call({'action':'post','planId':plan['id'],'version':plan['version'],'group':'Supplier C','date':'2026-10-07','rowKeys':plan['groups']['Supplier C']['rowKeys'],'sources':[{'type':'BANK','bankAccountId':'TTI-P','method':'CHEQUE','reference':'1002','amount':300}]})['result']['plan']
    user['permissions']['Accounts']['supplier']=['View','Create'];auth.write_text(json.dumps({'users':[user],'masters':masters,'audit':[]}))
    closed=call({'action':'close','planId':plan['id'],'version':plan['version']})['result']['plan'];assert closed['status']=='Closed'
    assert read()['plans']==[] and read()['history'][0]['id']==closed['id']
    call({'action':'reopen','planId':closed['id'],'version':closed['version'],'reason':'Review corrected cheque'},403)
    user['permissions']['Accounts']['supplier']=['View','Create','Edit'];auth.write_text(json.dumps({'users':[user],'masters':masters,'audit':[]}))
    reopened=call({'action':'reopen','planId':closed['id'],'version':closed['version'],'reason':'Review corrected cheque'})['result']['plan'];assert reopened['status']=='Open'
    # A posted JV settles old trucks, then partially pays the next; the view makes no new journal.
    store['journals']['ROUND-JV']={'id':'ROUND-JV','entity':'TTI','status':'Posted','sourceType':'JV','date':'2026-09-10','lines':[{'account':'2110','debit':250,'credit':0,'subledger':'Broker A'},{'account':'2170','credit':250,'debit':0,'subledger':'ABC'}]}
    seed(store);payload=read();round_set=payload['adjustments'][0];assert [a['amount'] for a in round_set['allocations']]==[100,150],round_set
    assert round_set['allocations'][-1]['balanceAfter']==50 and round_set['payer']=='ABC',round_set
    assert len(json.loads(books.read_text())['journals'])==2
    # Supplier advances are previewed and applied on explicit Confirm, never on GET.
    store['supplierAdvances']={'ADV':{'id':'ADV','entity':'TTI','supplier':'Supplier A','amount':50,'allocatedAmount':0,'date':'2026-09-11','status':'Available / Unallocated','payer':'ABC','payerRelationship':'OTHER_THIRD_PARTY'}}
    seed(store);payload=read();assert any(r['advanceApplied']==50 for r in payload['rows']),payload
    assert json.loads(books.read_text())['supplierAdvances']['ADV']['allocatedAmount']==0
    keys=[r['rowKey'] for r in payload['rows'] if r['group']=='Broker A']
    plan=call({'action':'create','mode':'PARTY','bankIds':['TTI-B'],'rowKeys':keys,'date':'2026-10-07'})['result']['plan'];saved=json.loads(books.read_text());assert saved['supplierAdvances']['ADV']['allocatedAmount']==50
    assert sum(r['outstanding'] for r in read()['rows'] if r['group']=='Broker A')==20
    # Reversing the JV restores only its own allocated balances.
    saved['journals']['ROUND-REV']={'id':'ROUND-REV','entity':'TTI','status':'Posted','sourceType':'JV_REVERSAL','reversalOf':'ROUND-JV','lines':[]};books.write_text(json.dumps(saved));payload=read();assert sum(r['outstanding'] for r in payload['rows'] if r['group']=='Broker A')==270,payload
    # View-only and unrelated-icon users cannot create or correct plans.
    user['permissions']['Accounts']['supplier']=['View'];auth.write_text(json.dumps({'users':[user],'masters':masters,'audit':[]}));call({'action':'create','mode':'PARTY','date':'2026-10-07'},403)
    user['permissions']['Accounts'].pop('supplier');user['permissions']['Accounts']['expenses']=['View','Create','Edit'];auth.write_text(json.dumps({'users':[user],'masters':masters,'audit':[]}));assert request('payment_plans',{},method='GET')[0]==403
    user['permissions']['Accounts']['supplier']=['View','Create','Edit'];seed(store)
    # Independent brokerage stays out of party payments and uses its own plan / liability.
    store['brokerageBills']={'BKG':{'id':'BKG','entity':'TTI','broker':'Independent Broker','acceptedBrokerage':100,'whtAmount':15,'billDate':'2026-09-01','dueDate':'2026-09-05','status':'Posted'}}
    seed(store);payload=read();assert payload['brokerRows'][0]['outstanding']==85 and all(r['billId']!='BKG' for r in payload['rows'])
    plan=call({'action':'create','mode':'BROKER','bankIds':['TTI-B'],'rowKeys':[payload['brokerRows'][0]['rowKey']],'date':'2026-10-07'})['result']['plan']
    result=call({'action':'post','planId':plan['id'],'version':plan['version'],'group':'Independent Broker','date':'2026-10-07','sources':[{'type':'BANK','bankAccountId':'TTI-B','amount':85,'method':'CHEQUE','reference':'3001'}]})['result'];saved=json.loads(books.read_text());assert saved['journals'][result['settlement']['journalId']]['totalDebit']==85
    assert not read()['brokerRows'] and next(iter(saved['brokeragePayments'].values()))['amount']==85
    # Export service, bag and other purchase liabilities never enter commodity planning.
    store['supplierBills']={'SERVICE':{'id':'SERVICE','entity':'TTI','vendor':'Service Vendor','billNo':'SERVICE','billDate':'2026-09-01','dueDate':'2026-09-02','payableAccount':'2130','supplierPayableTotal':25,'receiptAllocations':[{'sourceKey':'SERVICE-KEY','supplierPayableShare':25}]}}
    store['bagSupplierBills']={'BAG':{'id':'BAG','entity':'TTI','supplier':'Bag Vendor','sellerInvoiceDate':'2026-09-01','dueDate':'2026-09-04','totalAmount':30,'status':'Posted'}}
    store['otherPurchases']={'OTHER':{'id':'OTHER','entity':'TTI','supplier':'Other Vendor','invoiceDate':'2026-09-01','dueDate':'2026-09-03','amount':40,'settlement':'CREDIT'}}
    store['journals'].pop('ROUND-JV');store['supplierAdvances']={}
    seed(store);payload=read();assert {r['billId'] for r in payload['rows']}=={'A','B','C'},payload
    call({'action':'create','mode':'PARTY','bankIds':['TTI-B'],'rowKeys':['SERVICE|SERVICE-KEY'],'date':'2026-10-07'},409)
    # Dedicated on-account third-party payment settles oldest truck then part of the next.
    body={'action':'on_account','party':'Broker A','date':'2026-10-07','sources':[{'type':'THIRD_PARTY','relationship':'OTHER_THIRD_PARTY','payer':'ABC','method':'DIRECT','amount':250}],'requestKey':'on-account-payment-fixture-0001'}
    result=call(body)['result']['settlement'];assert result['type']=='On Account Payment' and result['netPayment']==250 and result['onAccountAmount']==0
    assert [a['amount'] for a in result['allocations']]==[100,20,130],result
    assert result['allocations'][-1]['balanceAfter']==70
    saved=json.loads(books.read_text());journal=saved['journals'][result['journalId']];assert journal['sourceType']=='SUPPLIER_PAYMENT' and journal['totalDebit']==journal['totalCredit']==250
    assert all(l['account']!='1110' for l in journal['lines'])
    count=len(saved['journals']);assert call(body)['result']['settlement']['id']==result['id'];assert len(json.loads(books.read_text())['journals'])==count
    call({**body,'party':'Service Vendor','requestKey':'on-account-unknown-party-0001'},409)
    call({'action':'correct_on_account','settlementId':result['id'],'reason':'Correct the payer','date':'2026-10-07'})
    assert sum(r['outstanding'] for r in read()['rows'] if r['group']=='Broker A')==320
    # Excess is an advance for this group and is consumed by future commodity dues only.
    result=call({'action':'on_account','party':'Broker A','date':'2026-10-07','sources':[{'type':'BANK','bankAccountId':'TTI-B','method':'CHEQUE','reference':'5001','amount':350}]})['result']['settlement']
    assert result['onAccountAmount']==30 and result['netPayment']==350,result
    saved=json.loads(books.read_text());assert saved['supplierAdvances'][result['unallocatedAdvanceId']]['availableAmount']==30
    saved['commodityBills']['D']=bill('D','Broker A','Supplier A',50,'2026-10-08','TRUCK-D');books.write_text(json.dumps(saved))
    payload=read();assert next(r for r in payload['rows'] if r['billId']=='D')['outstanding']==20,payload
    assert json.loads(books.read_text())['supplierAdvances'][result['unallocatedAdvanceId']]['allocatedAmount']==0,'GET may only preview'
    call({'action':'correct_on_account','settlementId':result['id'],'reason':'Correct advance payment','date':'2026-10-07'})
    assert json.loads(books.read_text())['supplierAdvances'][result['unallocatedAdvanceId']]['status']=='Cancelled'
    # Supplier without broker works, wrong-company bank and missing cheque fail without mutation.
    seed(store)
    call({'action':'on_account','party':'Supplier C','date':'2026-10-07','sources':[{'type':'BANK','bankAccountId':'BRM-B','method':'CHEQUE','reference':'6001','amount':100}]},409)
    call({'action':'on_account','party':'Supplier C','date':'2026-10-07','sources':[{'type':'BANK','bankAccountId':'TTI-B','method':'CHEQUE','amount':100}]},409)
    result=call({'action':'on_account','party':'Supplier C','date':'2026-10-07','sources':[{'type':'BANK','bankAccountId':'TTI-B','method':'ONLINE_BANKING','reference':'ONLINE','amount':100}]})['result']['settlement']
    assert result['allocations'][0]['broker']=='Supplier C' and result['allocations'][0]['balanceAfter']==200
    user['permissions']['Accounts']['supplier']=['View'];seed(store)
    call({'action':'on_account','party':'Supplier C','date':'2026-10-07','sources':[]},403)
    call({'action':'correct_on_account','settlementId':result['id'],'reason':'Unauthorized','date':'2026-10-07'},403)
    # Home totals identify only the default bank while retaining every bank for hover detail.
    user['permissions']['Accounts']['supplier']=['View','Create','Edit']
    default_store={**store,'bankAccountSettings':{'TTI-P':{'defaultPaymentAccount':True}}};seed(default_store)
    status,data=request('accounts_dashboard',{},method='GET');assert status==200,(status,data)
    assert len(data['summaries']['bank'])==2 and [r['bankAccountId'] for r in data['summaries']['bank'] if r.get('isDefault')]==['TTI-P'],data
    assert next(r for r in data['summaries']['bank'] if r['isDefault'])['amount']==500
    # Excess cannot be reversed after it has funded a later bill without correcting that application.
    seed(store)
    result=call({'action':'on_account','party':'Broker A','date':'2026-10-07','sources':[{'type':'BANK','bankAccountId':'TTI-B','method':'CHEQUE','reference':'7001','amount':350}]})['result']['settlement']
    saved=json.loads(books.read_text());saved['commodityBills']['D']=bill('D','Broker A','Supplier A',50,'2026-10-08','TRUCK-D');books.write_text(json.dumps(saved));payload=read();keys=[r['rowKey'] for r in payload['rows'] if r['billId']=='D']
    plan=call({'action':'create','mode':'PARTY','bankIds':['TTI-B'],'rowKeys':keys,'date':'2026-10-07'})['result']['plan']
    call({'action':'correct_on_account','settlementId':result['id'],'reason':'Linked advance must be corrected first','date':'2026-10-07'},409)
    print('Payment plans: bank scope, personal banks, grouping, source splits, replay, correction, oldest JV allocations, advances, denied access and independent brokerage passed.')
