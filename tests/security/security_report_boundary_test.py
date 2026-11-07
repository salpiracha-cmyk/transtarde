"""Real PHP endpoints, real authentication, disposable books; no live writes."""
import json, os, pathlib, shutil, subprocess, tempfile

ROOT = pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tti-security-report-') as directory:
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
    # Read access must belong to the actual workflow, not another module icon.
    for endpoint in ['rent_salary_v2','bag_purchases','bank_accounts','local_sales_payments','milling_purchase_sodas','tg_export_accounts_receipts']:
        seed();before=books.read_bytes()
        st,data=request(endpoint,{},method='GET')
        assert st==403,(endpoint,st,data)
        assert books.read_bytes()==before
    user['permissions']={'Accounts':{'supplier':['View'],'entity-tti':['View']}}
    seed();st,data=request('bank_accounts',{},method='GET')
    assert st==200 and data['accounts'] and '123456' not in json.dumps(data),(st,data)
    assert all('deletionRequest' not in r for r in data['accounts'])
    user['permissions']={'Accounts':{'reports':['View'],'entity-tti':['View']}}
    seed();st,data=request('bank_accounts',{},method='GET')
    assert st==200 and data['accounts'] and 'bookBalance' not in json.dumps(data) and '123456' not in json.dumps(data),(st,data)
    user['permissions']={'Accounts':{'purchases':['View'],'entity-tti':['View']}}
    seed();assert request('bag_purchases',{},method='GET')[0]==200
    user['permissions']={'Exports':{'contracts':['View']}};user['master_access']=True;user['master_permissions']={'export_customers':[],'mills':[]}
    seed()
    for endpoint in ['export_customers','location-master']:
        assert request(endpoint,{},query='',method='GET')[0]==403,endpoint
    user['master_permissions']={'export_customers':['Use'],'mills':['Use']};user['location']='North Mill'
    masters['export_customers']=[{'id':'C1','values':['Buyer','BUY','Export Buyer','Address','Country','mail','phone','tax','KG','[]','Active','PRIVATE CUSTOMER NOTE']}]
    masters['mills']=[{'id':'N','values':['North Mill','Own Mill','Active','PRIVATE ADDRESS','PRIVATE CONTACT']},{'id':'S','values':['South Mill','Own Mill','Active','OTHER ADDRESS','OTHER CONTACT']}]
    seed();st,data=request('export_customers',{},query='',method='GET');assert st==200 and data['customers'][0]['name']=='Buyer' and 'PRIVATE CUSTOMER NOTE' not in json.dumps(data),(st,data)
    st,data=request('location-master',{},query='',method='GET');assert st==200 and len(data['locations'])==1 and 'PRIVATE ADDRESS' not in json.dumps(data),(st,data)
    user.pop('master_access');user['permissions']={'Mill':{'local':['View','Edit']},'Accounts':{'entity-tti':['View','Edit'],'entity-brm':['View','Edit']}}
    def sale(id,entity,location):return {'id':id,'entity':entity,'soda':'SHARED','sourceKey':id,'stockLocation':location,'status':'Pending','journalId':None}
    initial={'revision':1,'journals':{},'localSalesCandidates':{'N':sale('N','TTI','North Mill'),'S':sale('S','TTI','South Mill')},'localSalesPaymentCandidates':{'PN':dict(sale('PN','TTI','North Mill'),amount=25,paymentDate='2026-10-26'),'PS':dict(sale('PS','TTI','South Mill'),amount=900,paymentDate='2026-10-26')}}
    seed(initial);st,data=request('local_sales_payments',{},query='',method='GET');assert st==200 and data['pending']==[] and data['banks']==[] and {r['sourceKey'] for r in data['paymentStatuses']}=={'PN'},(st,data)
    before=books.read_bytes();st,data=request('local_sales_entity_rule',{'targetEntity':'BRM','soda':'SHARED'},query='');assert st==403 and books.read_bytes()==before,(st,data)
    initial['localSalesCandidates'].pop('S');initial['localSalesPaymentCandidates'].pop('PS');seed(initial)
    st,data=request('local_sales_entity_rule',{'targetEntity':'BRM','soda':'SHARED'},query='');assert st==200 and data['moved']=={'sales':1,'payments':1},(st,data)
    user['permissions']={'Exports':{'loading':['View']},'Accounts':{'entity-tti':['View']}};seed({'purchaseSodas':{'N':dict(sale('N','TTI','North Mill'),sodaNo='N',locationName='North Mill',party='SUPPLIER SECRET',ratePerKg=55),'S':dict(sale('S','TTI','South Mill'),sodaNo='S',locationName='South Mill')}})
    st,data=request('milling_purchase_sodas',{},query='',method='GET');assert st==200 and [r['id'] for r in data['sodas']]==['N'] and 'SUPPLIER SECRET' not in json.dumps(data) and 'ratePerKg' not in json.dumps(data),(st,data)
    # Company document metadata/mutations bind to the selected company.
    user['permissions']={'Accounts':{'masters':['View','Edit'],'entity-tti':['View','Edit'],'entity-brm':[]}}
    user['master_access']=True;user['master_permissions']={'companies':['View Documents','Edit']}
    values=['BRM Company','BRM']+['']*12+[json.dumps([{'id':'D','label':'Private','status':'Active','storageName':'private.pdf'}])]
    masters['companies']=[{'id':'CO-B','values':values}];seed();before=auth.read_bytes()
    assert request('master_documents',{},query='?companyId=CO-B',method='GET')[0]==403
    form={'csrf':'fixture','companyId':'CO-B','action':'deactivate','documentId':'D'}
    assert request('master_documents',{},query='',form=form)[0]==403 and auth.read_bytes()==before
    user['permissions']['Accounts']['entity-brm']=['View','Edit'];seed()
    assert request('master_documents',{},query='?companyId=CO-B',method='GET')[0]==200
    assert request('master_documents',{},query='',form=form)[0]==200
    # Bulk physical deletion needs both the saved tombstone and company scope.
    docdir=private/'export_documents';docdir.mkdir(exist_ok=True);document=docdir/'fixture.pdf';document.write_bytes(b'%PDF-fixture')
    index=docdir/'index.json';index.write_text(json.dumps({'D':{'id':'D','contract_ref':'BRM-REF','stored_name':'fixture.pdf','deleted_at':None}}))
    deletion_root={'contracts':[{'id':'CB','ref':'BRM-REF','seller':'BRM'}],'deletedShipments':[{'contractRef':'BRM-REF','deletedAt':'2026-10-26'}]}
    (private/'operations.json').write_text(json.dumps({'values':{'transtrade_export_v3_operational':json.dumps(deletion_root)}}))
    user['permissions']={'Exports':{'active':['View','Edit']},'Accounts':{'entity-tti':['View','Edit'],'entity-brm':[]}};seed();before=index.read_bytes()
    form={'csrf':'fixture','action':'delete-shipment','contractRef':'BRM-REF'}
    st,data=request('export_documents',{},query='',form=form);assert st==403 and document.is_file() and index.read_bytes()==before,(st,data)
    user['permissions']['Accounts']['entity-brm']=['View','Edit'];seed();st,data=request('export_documents',{},query='',form=form)
    assert st==200 and not document.exists(),(st,data)
    rootdata={'contracts':[{'id':'CT','ref':'REF','seller':'TTI'}],'shipments':[{'id':'LOT','contractRef':'REF'}]}
    (private/'operations.json').write_text(json.dumps({'values':{'transtrade_export_v3_operational':json.dumps(rootdata)}}))
    body={'action':'queue_candidate','sellerEntity':'TTI','sourceKey':'EXPORT|LOT','contractRef':'REF','stage':'Draft','currency':'USD','transactionAmount':100,'actualQtyMT':1}
    user['permissions']={'Exports':{'bags':['Create']}};seed();before=books.read_bytes();st,data=request('export_accounting',body,query='');assert st==403 and books.read_bytes()==before,(st,data)
    user['permissions']={'Exports':{'commercial':['Create']}};seed();st,data=request('export_accounting',body,query='');assert st==200 and data['candidate']['entity']=='TTI',(st,data)
    before=books.read_bytes();st,data=request('export_accounting',dict(body,sellerEntity='BRM'),query='');assert st==403 and books.read_bytes()==before,(st,data)
    user['permissions']={'Accounts':{'expenses':['Create'],'entity-tti':['Create','View']}};seed({'exportCandidates':{'C':{'entity':'TTI','candidateType':'CUSTOMER_EXPORT_SALE','transactionAmount':100,'transactionCurrency':'USD'}}})
    recognize={'action':'recognize_candidate','entity':'TTI','candidateId':'C','recognitionDate':'2026-10-26','functionalCurrency':'PKR','functionalRate':280,'controlConfirmed':True}
    before=books.read_bytes();st,data=request('export_accounting',recognize);assert st==403 and books.read_bytes()==before,(st,data)
    user['permissions']['Accounts']['customer']=['Create'];seed({'exportCandidates':{'C':{'entity':'TTI','candidateType':'CUSTOMER_EXPORT_SALE','transactionAmount':100,'transactionCurrency':'USD'}}});st,data=request('export_accounting',recognize);assert st==200 and data['journal']['status']=='Posted',(st,data)
    print('PASS Security report read projections, canonical entity/location guards, atomic reclassification, export source and exact posting workflow; legitimate controls retained')
