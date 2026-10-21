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
    def permissions(icon,rights,entity_rights):
        user['permissions']={'Accounts':{icon:['View',*rights],'entity-tti':['View',*entity_rights]}}
    certificate={'entity':'TTI','action':'save_certificate','id':'CERT1','financialYear':'2026-27','bankName':'Fixture Bank','certificateNo':'CERT','taxCode':'EXP-AWT-NTR','certificateDate':'2026-10-07','certificateAmount':100}
    existing={'revision':4,'journals':{},'exportTaxCertificates':{'CERT1':{'id':'CERT1','entity':'TTI','financialYear':'2026-27','bankName':'Original Bank','certificateNo':'OLD','taxCode':'EXP-AWT-NTR','certificateDate':'2026-10-01','certificateAmount':50}}}
    for rights,entity_rights,target in [(['Create'],['Edit'],''),(['Create'],['Create','Edit'],'CERT1'),(['Edit'],['Edit'],''),(['Edit'],['Edit'],'MISSING'),(['Approve'],['Approve'],'')]:
        permissions('reports',rights,entity_rights);seed(existing);before=books.read_bytes()
        status,data=request('export_tax_certificates',{**certificate,'id':target})
        assert status==403,(rights,entity_rights,target,status,data)
        assert books.read_bytes()==before,'Rejected certificate action changed storage'
    for rights,target in [(['Create'],''),(['Create'],'MISSING'),(['Edit'],'CERT1')]:
        permissions('reports',rights,rights);seed(existing)
        status,data=request('export_tax_certificates',{**certificate,'id':target});assert status==200,(rights,target,status,data)
    # Explicit, missing and natural/computed target identities are resolved from
    # the locked canonical store, never from whether the client supplied an ID.
    route={'entity':'TTI','action':'save_transport_route','from':'Karachi','to':'Lahore','rate':100}
    programme={'entity':'TTI','action':'register_loading_program','loadingProgrammeNo':'LP-1','loadedContainers':1}
    existing={'revision':4,'journals':{},'transportMaster':{'TR1':{'id':'TR1','from':'Original','to':'Lahore','rate':50}},'loadingProgrammes':{'TTI|LP-1':{'id':'TTI|LP-1','entity':'TTI','loadingProgrammeNo':'LP-1','loadedContainers':0}}}
    for body,rights,expected in [({**route,'id':'TR1'},['Create'],403),({**route,'id':'TR1'},['Edit'],200),({**route,'id':'MISSING'},['Edit'],403),(route,['Create'],200),(programme,['Create'],403),(programme,['Edit'],200),({**programme,'loadingProgrammeNo':'LP-NEW'},['Create'],200),({**programme,'loadingProgrammeNo':'LP-NEW'},['Edit'],403)]:
        permissions('transport',rights,['Create','Edit']);seed(existing);before=books.read_bytes()
        status,data=request('accounts_workflows_v1',body);assert status==expected,(body,rights,status,data)
        if expected==403:assert books.read_bytes()==before,'Rejected workflow action changed storage'
    # Pending Mill payments resolve natural-key existence under the same lock.
    payment={'entity':'TTI','action':'queue_payment','sourceKey':'PAY1','soda':'SODA1','party':'Buyer','paymentDate':'2026-10-21','amount':200,'paymentType':'Cash'}
    pending={'revision':4,'journals':{},'localSalesPaymentCandidates':{'TTI|LOCAL_PAYMENT|PAY1':{**payment,'id':'TTI|LOCAL_PAYMENT|PAY1','journalId':None,'amount':100}}}
    for rights,initial,expected in [(['Create'],pending,403),(['Edit'],pending,200),(['Create'],None,200),(['Edit'],None,403)]:
        user['permissions']={'Mill':{'local':['View',*rights]}};seed(initial);before=books.read_bytes()
        status,data=request('local_sales_payments',payment);assert status==expected,(rights,initial,status,data)
        if expected==403:assert books.read_bytes()==before
    permissions('customer',['Edit'],['Edit']);seed(pending)
    status,data=request('local_sales_payments',{'entity':'TTI','action':'reject_payment','paymentId':'TTI|LOCAL_PAYMENT|PAY1','note':'Check details'})
    assert status==200 and data['payment']['status']=='Returned to Mill',(status,data)
    # Explicit Edit workflows must remain available; they are not upserts.
    soda={'id':'SODA1','entity':'TTI','commodity':'CORN','sodaDate':'2026-10-01','arrivalDueDate':'2026-10-25','qtyFromKg':1000,'qtyToKg':1000,'rate':100,'rateUnit':'MAUND','party':'Supplier'}
    initial={'revision':4,'journals':{},'purchaseSodas':{'SODA1':soda},'freightBillsV1':{'F1':{'id':'F1','entity':'TTI','vendor':'Forwarder','invoiceNo':'F1','billDate':'2026-10-01','acceptedLiability':100,'disputedTotal':50,'disputeSettledTotal':0}}}
    for icon,body in [('purchases',{'action':'extend_soda','id':'SODA1','arrivalDueDate':'2026-11-01','reason':'Supplier extension'}),('purchases',{'action':'resolve_soda','id':'SODA1','resolution':'MANUAL_CLOSE','reason':'Owner closure'}),('freight',{'action':'settle_freight_dispute','id':'F1','date':'2026-10-21','amount':10,'reason':'Settlement'})]:
        permissions(icon,['Edit'],['Edit']);seed(initial)
        status,data=request('accounts_workflows_v1',{'entity':'TTI',**body});assert status==200,(body,status,data)
    # Native CORN and SESAME Create must not become Edit after an in-memory insert.
    masters['mills']=[{'id':'M1','values':['Fixture Mill','FM','External Mill','','','Active','']}]
    masters['purchase_products']=[{'id':commodity,'values':[commodity,'Fixture '+commodity,'','RAW','KG',commodity,'','','Active']} for commodity in ['CORN','SESAME']]
    for commodity in ['CORN','SESAME']:
        permissions('purchases',['Create'],['Create']);seed()
        status,data=request('accounts_workflows_v1',{'entity':'TTI','action':'save_soda','commodity':commodity,'purchaseProductId':commodity,'locationId':'M1','sodaDate':'2026-10-21','arrivalDueDate':'2026-10-25','party':'Supplier','qtyFromMT':1,'qtyToMT':1,'rate':100,'paymentTermType':'CASH'})
        assert status==200,(commodity,status,data)
        assert next(iter(json.loads(books.read_text())['purchaseSodas'].values()))['rateUnit']=='MAUND'
    salary_master={'action':'save_salary_master','entity':'TTI','name':'Fixture Version Person','category':'OFFICE_STAFF','netSalary':100,'effectiveFrom':'2026-10-01','accountingTreatment':'STAFF_COST'}
    initial_salary={'revision':4,'journals':{},'salaryMasters':{'S1':{'id':'S1','entity':'TTI','name':'Fixture Version Person','category':'OFFICE_STAFF','monthlyAmount':50,'effectiveFrom':'2026-01-01','effectiveTo':'','status':'Active'}}}
    permissions('expenses',['Create','Edit'],['Create','Edit']);user['master_access']=True;user['master_permissions']={'salary_staff':['Use','View','Create']}
    seed(initial_salary);before=books.read_bytes();status,data=request('rent_salary_v2',salary_master)
    assert status==403 and books.read_bytes()==before,(status,data)
    user['master_permissions']['salary_staff'].append('Edit');seed(initial_salary)
    status,data=request('rent_salary_v2',salary_master);assert status==200,(status,data)
    assert json.loads(books.read_text())['salaryMasters']['S1']['effectiveTo']=='2026-09-30'
    reconciliation={'entity':'TTI','bankId':'TTI-B','statementDate':'2026-10-07','statementBalance':0,'clearedKeys':[],'revision':4,'requestKey':'reconciliation-fixture-0001'}
    recon_base={'revision':4,'journals':{},'bankReconciliations':{}}
    recon_saved={'revision':4,'journals':{},'bankReconciliations':{'TTI|TTI-B':{'entity':'TTI','bankId':'TTI-B','statementDate':'2026-10-07','statementBalance':0,'clearedKeys':[]}}}
    for rights,initial,expected in [(['Create'],recon_base,200),(['Edit'],recon_saved,200),(['Create'],recon_saved,403),(['Edit'],recon_base,403),(['Approve'],recon_base,403)]:
        permissions('reconciliation',rights,rights);seed(initial);before=books.read_bytes()
        status,data=request('bank_reconciliation',reconciliation);assert status==expected,(rights,initial,status,data)
        if expected==403:assert books.read_bytes()==before
    # Brokerage rates are owned by the explicit Brokerage icon, including legacy
    # flat grants whose Create/Edit distinction must survive deterministic IDs.
    rate={'entity':'TTI','action':'save_product_rate','product':'Rice','ratePer100Kg':10,'effectiveFrom':'2026-10-07'}
    permissions('brokerage',['Create'],['Create']);seed();status,data=request('brokerage_master',rate,query='')
    assert status==200,(status,data)
    previous=json.loads(books.read_text());before=books.read_bytes()
    status,data=request('brokerage_master',{**rate,'ratePer100Kg':20},query='');assert status==403 and books.read_bytes()==before,(status,data)
    permissions('brokerage',['Edit'],['Edit']);auth.write_text(json.dumps({'users':[user],'masters':masters,'audit':[]}))
    status,data=request('brokerage_master',{**rate,'ratePer100Kg':20},query='');assert status==200,(status,data)
    # Append-only financial routes cannot borrow Edit or Approve. Invalid
    # business fields must never be reached by a caller lacking Create.
    for endpoint,icon in [('donations','expenses'),('other_purchases','purchases'),('internal_bank_transfers','cashbank'),('supplier_settlements','supplier')]:
        for rights in [['Edit'],['Approve']]:
            permissions(icon,rights,rights);seed();before=books.read_bytes()
            status,data=request(endpoint,{'entity':'TTI','action':'post_supplier_payment'})
            assert status==403,(endpoint,rights,status,data)
            assert books.read_bytes()==before
    print('PASS exact action boundaries: icon/company same action; certificate and explicit/computed workflow upserts resolve locked existence; Edit/Approve cannot borrow Create; denials preserve books')
