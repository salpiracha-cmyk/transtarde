"""Real PHP endpoints, real authentication, disposable books; no live writes."""
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
        req={'method':method,'uri':'/api/'+endpoint+'.php'+query,'body':{'csrf':'fixture',**body},'form':form or {}}
        status_file=root/'status.txt'
        r=subprocess.run(['php','-d',f'session.save_path={root}',str(harness),str(app/'api'/f'{endpoint}.php'),json.dumps(req),str(status_file)],env=env,text=True,capture_output=True)
        assert r.returncode==0, r.stdout+r.stderr
        try: return int(status_file.read_text()),json.loads(r.stdout)
        except Exception: raise AssertionError(r.stdout+r.stderr)

    ops=private/'operations.json'
    sale_source={'id':1001,'entity':'TTI','millName':'TTI Rice Mills','soda':'QA','date':'2026-11-12','party':'Fixture buyer','product':'Powder','baseVariety':'IRRI-6','riceType':'White','byProductSale':True,'billingWeightBasis':'GROSS','stockSourceName':'IRRI-6 White Rice — Powder','rate':62,'tons':3.592,'loads':[{'bridgeId':'LOAD','kg':3592,'date':'2026-11-12','truck':'ABC-123','printed':False}],'pay':[]}
    ops.write_text(json.dumps({'values':{'tt35localsales':json.dumps([sale_source])}}))
    user['role']='Super Admin'
    user['permissions']={'Accounts':'all','Mill':'all'}
    def initial():
        return {'revision':0,'journals':{},'localSalesCandidates':{},'localSalesPaymentCandidates':{'PAY':{'id':'PAY','entity':'TTI','sourceKey':'LOCALPAY|QA|PAY','soda':'QA','paymentDate':'2026-11-13','party':'Fixture buyer','product':'Powder','amount':222704,'paymentType':'Transtrade Bank','bankAccountId':'TTI-B','stockLocation':'TTI Rice Mills','reference':'QA','status':'Pending Accounts Approval'}}}
    def approve_sale():
        before=books.read_bytes();source_before=ops.read_bytes()
        status,view=request('local_sales_control',{},method='GET');assert status==200,(status,view)
        assert books.read_bytes()==before and ops.read_bytes()==source_before,'GET wrote data'
        c=next(c for c in view['pending'] if c['soda']=='QA')
        status,d=request('local_sales_control',{'entity':'TTI','action':'approve_candidate','candidateId':c['id'],'sourceFingerprint':c['sourceFingerprint'],'exceptionNote':'Fixture only'});assert status==200,(status,d)
        assert ops.read_bytes()==source_before,'Accounting changed physical stock'
        return d
    def approve_pay():
        status,d=request('local_sales_payments',{'entity':'TTI','action':'approve_payment','paymentId':'PAY','bankAccountId':'TTI-B'});assert status==200,(status,d);return d
    def balance(code):
        return round(sum(l.get('debit',0)-l.get('credit',0) for j in json.loads(books.read_text())['journals'].values() for l in j['lines'] if l['account']==code),2)
    seed(initial());p=approve_pay();assert balance('2510')==-222704 and balance('1220')==0
    sale=approve_sale();assert balance('2510')==0 and balance('1220')==0 and balance('1110')==222704
    assert balance('4200')==0 and balance('4300')==-222704
    revenue=next(l for l in sale['journal']['lines'] if l['account']=='4300');assert revenue['subaccountName']=='Powder Sales'
    assert sale['advanceJournal']['date']=='2026-11-13','Do not apply receipt before its date'
    status,ledger=request('local_customer_ledger',{},method='GET');assert status==200 and ledger['closingBalance']==0 and len(ledger['rows'])==2,(status,ledger)
    print('PASS advance first then approved sale: bank once, advance cleared, buyer balance zero. Revenue uses 4300 / Powder Sales; application retains receipt date.')
    before=books.read_bytes();assert request('local_sales_payments',{'entity':'TTI','action':'approve_payment','paymentId':'PAY'})[0]==409;assert books.read_bytes()==before
    assert request('local_sales_control',{'entity':'TTI','action':'approve_candidate','candidateId':'TTI|LOCAL_SALE|LOCAL|QA|LOAD'})[0]==409;assert books.read_bytes()==before
    print('PASS repeated payment and sale approval rejected without duplicate journals.')
    # Existing generic advance receipt (Sherbaz's historical shape) is preserved.
    seed(initial());approve_pay();legacy=json.loads(books.read_text())
    for j in legacy['journals'].values():
        for line in j['lines']:
            for key in ['subaccountId','subaccountName','parentAccount','buyerType']:
                line.pop(key,None)
    books.write_text(json.dumps(legacy));original_journals=legacy['journals']
    repaired=approve_sale();after=json.loads(books.read_text())
    assert all(after['journals'][key]==row for key,row in original_journals.items())
    cleared=next(l for l in repaired['advanceJournal']['lines'] if l['account']=='2510')
    assert 'subaccountId' not in cleared and balance('2510')==0 and balance('1220')==0
    assert balance('1110')==222704 and balance('4300')==-222704
    print('PASS historical unclassified receipt preserved exactly; separate sale/application clears its original advance.')
    seed(initial());approve_sale();p=approve_pay();assert balance('2510')==0 and balance('1220')==0 and balance('1110')==222704
    print('PASS sale first then full receipt: buyer balance cleared, no advance balance.')
    x=initial();x['localSalesPaymentCandidates']['PAY']['amount']=250000;seed(x);approve_sale();approve_pay();assert balance('1220')==0 and balance('2510')==-27296
    print('PASS excess payment: only excess 27296 remains in advances.')
    x=initial();x['localSalesPaymentCandidates']['PAY']['amount']=100000;seed(x);approve_sale();approve_pay();assert balance('1220')==122704 and balance('2510')==0
    print('PASS partial payment: 122704 buyer balance, no advance.')

    # A historical handoff with an unsaved transient ID cannot become a second sale.
    seed(initial());posted=approve_sale();historical=json.loads(books.read_text())
    old_key=next(iter(historical['localSalesCandidates']));row=historical['localSalesCandidates'].pop(old_key);row['sourceKey']='LOCAL|QA|UNSAVED-OLD-ID';row['id']='OLD-ID';historical['localSalesCandidates']['OLD-ID']=row
    historical['journals'][row['journalId']]['meta']['localSaleCandidateId']='OLD-ID'
    books.write_text(json.dumps(historical));before=books.read_bytes()
    status,view=request('local_sales_control',{},method='GET');c=view['pending'][0];assert c['reviewHold']
    status,d=request('local_sales_control',{'entity':'TTI','action':'approve_candidate','candidateId':c['id'],'sourceFingerprint':c['sourceFingerprint']});assert status==409,(status,d)
    assert books.read_bytes()==before
    print('PASS unmatched historical loading blocks a duplicate sale pending reconciliation.')

    # Stale review cannot approve a changed rate; a reopened review uses current facts.
    seed(initial());status,view=request('local_sales_control',{},method='GET');c=view['pending'][0]
    sale_source['rate']=63;ops.write_text(json.dumps({'values':{'tt35localsales':json.dumps([sale_source])}}))
    before=books.read_bytes();status,d=request('local_sales_control',{'entity':'TTI','action':'approve_candidate','candidateId':c['id'],'sourceFingerprint':c['sourceFingerprint']});assert status==409,(status,d);assert books.read_bytes()==before
    posted=approve_sale();assert posted['journal']['totalCredit']==226296
    # Print flag is not a financial amendment, nor a second sale.
    sale_source['loads'][0]['printed']=True;ops.write_text(json.dumps({'values':{'tt35localsales':json.dumps([sale_source])}}))
    status,view=request('local_sales_control',{},method='GET');assert status==200 and view['pending']==[]
    print('PASS stale review, print independence, no duplicate dispatch candidate, no physical writes.')
    # Source edits cannot race/overwrite already posted financial facts.
    guard=root/'guard.php'
    guard.write_text('''<?php
    require $argv[1].'/auth_store.php';require $argv[1].'/api/local_sales_source_core.php';
    $old=$argv[2];$new=$argv[3];$lock=null;
    try{$lock=lsc_lock_source_amendment($old,$new);echo 'ALLOWED';}
    catch(DomainException $e){echo 'BLOCKED: '.$e->getMessage();}
    finally{lsc_unlock($lock);}
    ''')
    def guard_check(before,after):
        p=subprocess.run(['php',str(guard),str(app),json.dumps([before]),json.dumps([after])],env=env,text=True,capture_output=True)
        assert p.returncode==0,p.stderr
        return p.stdout
    original=json.loads(json.dumps(sale_source));changed=json.loads(json.dumps(original));changed['loads'][0]['kg']=3500
    assert guard_check(original,changed).startswith('BLOCKED:')
    changed=json.loads(json.dumps(original));changed['rate']=64;assert guard_check(original,changed).startswith('BLOCKED:')
    changed=json.loads(json.dumps(original));changed['loads'][0]['printed']=False;assert guard_check(original,changed)=='ALLOWED'
    changed=json.loads(json.dumps(original));changed['loads'].append({'bridgeId':'LOAD2','kg':10,'date':'2026-11-14','truck':'ABC-456'});assert guard_check(original,changed)=='ALLOWED'
    print('PASS posted dispatch quantity/rate protected; printing and additional loading remain permitted.')
    # A saved payment also reaches Accounts without a browser bridge, including advances.
    sale_source['rate']=62;sale_source['loads']=[]
    sale_source['pay']=[{'bridgeId':'AUTO-PAY','date':'2026-11-13','amount':222704,'type':'Transtrade Bank','bankAccountId':'TTI-B','bankEntity':'TTI','where':'Fixture bank'}]
    ops.write_text(json.dumps({'values':{'tt35localsales':json.dumps([sale_source])}}));seed({'revision':0,'journals':{}})
    before=books.read_bytes();status,view=request('local_sales_payments',{},method='GET');assert status==200 and books.read_bytes()==before
    p=view['pending'][0];assert p['amount']==222704
    status,d=request('local_sales_payments',{'action':'approve_payment','paymentId':p['id'],'sourceFingerprint':p['sourceFingerprint'],'bankAccountId':'TTI-B'});assert status==200,(status,d)
    credit=next(l for l in d['journal']['lines'] if l['account']=='2510');assert credit['subaccountName']=='By-product Buyer Advances'
    sale_source['loads']=[{'bridgeId':'AUTO-LOAD','kg':3592,'date':'2026-11-14','truck':'ABC-123','printed':False}]
    ops.write_text(json.dumps({'values':{'tt35localsales':json.dumps([sale_source])}}));d=approve_sale()
    assert balance('2510')==0 and balance('1220')==0
    debit=next(l for l in d['advanceJournal']['lines'] if l['account']=='2510');assert debit['subaccountId']==credit['subaccountId']
    assert d['advanceJournal']['date']=='2026-11-14'
    changed=json.loads(json.dumps(sale_source));changed['pay'][0]['amount']=1;assert guard_check(sale_source,changed).startswith('BLOCKED:')
    print('PASS source-derived advance, buyer subsidiary cleared on dispatch, posted payment amendment blocked.')
