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
    payload=read();assert len(payload['banks'])==2,payload
    assert any(x['accountType'] in ['Personal Account','Proprietor / Owner Account'] for x in payload['banks'])
    assert {r['group'] for r in payload['rows']}=={'Broker A','Supplier C'},payload
    keys=[r['rowKey'] for r in payload['rows']]
    call({'action':'create','mode':'PARTY','bankIds':['BRM-B'],'rowKeys':keys,'date':'2026-10-07'},409)
    call({'action':'create','mode':'PARTY','bankIds':['TTI-B'],'rowKeys':keys,'date':'2026-10-07'},409)
    plan=call({'action':'create','mode':'PARTY','bankIds':['TTI-B','TTI-P'],'rowKeys':keys,'date':'2026-10-07'})['result']['plan']
    post={'action':'post','planId':plan['id'],'version':plan['version'],'group':'Broker A','date':'2026-10-07','rowKeys':plan['groups']['Broker A']['rowKeys'],'sources':[{'type':'BANK','bankAccountId':'TTI-B','method':'CHEQUE','reference':'1001','amount':120},{'type':'BANK','bankAccountId':'TTI-P','method':'ONLINE_BANKING','reference':'REF-1','amount':100},{'type':'THIRD_PARTY','relationship':'OTHER_THIRD_PARTY','payer':'ABC','reference':'ABC-CHEQUE','amount':100}],'requestKey':'post-party-replay-fixture-0001'}
    result=call(post)['result'];plan=result['plan'];sett=result['settlement'];assert sett['netPayment']==320 and len(sett['allocations'])==3,sett
    saved=json.loads(books.read_text());j=saved['journals'][sett['journalId']];assert j['totalDebit']==j['totalCredit']==320,j
    assert {l['account'] for l in j['lines']}=={'2110','2120','1110','2170'},j
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
    assert read()['plans']==[]
    user['permissions']['Accounts']['supplier']=['View','Create','Edit']
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
    print('Payment plans: bank scope, personal banks, grouping, source splits, replay, correction, oldest JV allocations, advances, denied access and independent brokerage passed.')
