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
$req=json_decode($argv[2],true);$_SERVER['REQUEST_URI']=$req['uri'];$_SERVER['REQUEST_METHOD']='POST';$_SERVER['REMOTE_ADDR']='192.0.2.42';
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
    def request(endpoint, body, query='?entity=TTI', form=None):
        req={'uri':'/api/'+endpoint+'.php'+query,'body':{'csrf':'fixture',**body},'form':form or {}}
        status_file=root/'status.txt'
        r=subprocess.run(['php','-d',f'session.save_path={root}',str(harness),str(app/'api'/f'{endpoint}.php'),json.dumps(req),str(status_file)],env=env,text=True,capture_output=True)
        assert r.returncode==0, r.stdout+r.stderr
        try: return int(status_file.read_text()),json.loads(r.stdout)
        except Exception: raise AssertionError(r.stdout+r.stderr)
    # Every listed real route rejects an unrelated icon even with company rights.
    routes=['rent_salary','rent_salary_v2','expenses_v1','donations','sales_tax_refunds','production_costing',
            'production_fixed_overhead','production_inventory_transfer','commodity_bills','other_purchases',
            'management_costing','management_costing_attach','bank_direct_entries','bank_reconciliation',
            'internal_bank_transfers','retention_remittances','export_bank_shortfall','bag_supplier_payments',
            'other_supplier_settlements','supplier_settlements','payables_planning','local_customer_receipts',
            'export_tax_certificates','export_costing','local_sales_costing','bank_accounts','bag_bill_file',
            'export_receipts','accounts_receipt_file','brokerage_transactions']
    seed(); before=books.read_bytes()
    for endpoint in routes:
        status,data=request(endpoint,{'entity':'TTI','action':'fixture'})
        assert status==403,(endpoint,status,data)
        assert books.read_bytes()==before,endpoint+' changed rejected books'
    # Exact local-sale icon grants cannot select another company's saved IDs.
    user['permissions']['Accounts']['customer']=['View','Edit']
    user['permissions']['Accounts']['purchases']=['View','Edit']
    user['permissions']['Accounts']['reports']=['View','Edit']
    initial={'revision':0,'journals':{},'localSalesCandidates':{'BRM-S':{'id':'BRM-S','entity':'BRM','status':'Pending Accounts Approval'}},
             'bagSupplierBills':{'BRM-BILL':{'id':'BRM-BILL','entity':'BRM'}},
             'nonWovenBagSupplierBills':{'BRM-BILL':{'id':'BRM-BILL','entity':'BRM'}},
             'exportTaxCertificates':{'BRM-CERT':{'id':'BRM-CERT','entity':'BRM','financialYear':'2026-27'}},
             'exportTaxCertificateMatches':{'BRM-J|M|0':'BRM-CERT'}}
    seed(initial);before=books.read_bytes()
    for endpoint,body in [
        ('bag_purchases',{'action':'try_post_bill','billId':'BRM-BILL'}),
        ('nonwoven_bag_bills',{'action':'try_post_bill','billId':'BRM-BILL'}),
        ('local_sales_control',{'action':'reject_candidate','candidateId':'BRM-S'}),
        ('export_tax_certificates',{'action':'save_certificate','id':'BRM-CERT','financialYear':'2026-27','bankName':'Bank','certificateNo':'CERT','taxCode':'EXP-AWT-NTR'}),
        ('export_tax_certificates',{'action':'unmatch_deductions','deductionKeys':['BRM-J|M|0'],'financialYear':'2026-27'}),
        ('local_sales_entity_rule',{'targetEntity':'BRM','soda':'SODA-1'}),
    ]:
        status,data=request(endpoint,{'entity':'TTI',**body})
        assert status==403,(endpoint,status,data)
        assert books.read_bytes()==before,endpoint+' changed another company'
    # Query-only company is also the company seen by batch handlers.
    initial={'revision':0,'journals':{},'localSalesCandidates':{'BRM-S':{'id':'BRM-S','entity':'BRM','journalId':'SALE-J','saleDate':'2026-10-07','product':'Rice','loadedKg':100}},
             'inventoryCostRates':{'RATE':{'id':'RATE','entity':'BRM','product':'Rice','effectiveFrom':'2026-01-01','costPerKg':10,'inventoryAccount':'1310'}}}
    seed(initial);status,data=request('local_sales_costing',{'action':'post_waiting'})
    assert status==200,data
    after=json.loads(books.read_text());assert not after['journals'] and not after['localSalesCandidates']['BRM-S'].get('costJournalId'),data
    # A purchase bill can explicitly post waiting derived stock costs without
    # granting access to change the Customer workspace's cost-rate form.
    customer_grant=user['permissions']['Accounts'].pop('customer')
    seed(initial);status,data=request('local_sales_costing',{'action':'post_waiting'})
    assert status==200,data
    assert request('local_sales_costing',{'action':'save_rate','entity':'TTI'})[0]==403
    user['permissions']['Accounts']['customer']=customer_grant
    # Legitimate same-company certificate and customer-review saves still work.
    seed();status,data=request('export_tax_certificates',{'entity':'TTI','action':'save_certificate','financialYear':'2026-27','bankName':'Bank','certificateNo':'CERT','taxCode':'EXP-AWT-NTR','certificateDate':'2026-10-07','certificateAmount':100})
    assert status==200,data
    seed({'revision':0,'journals':{},'localSalesCandidates':{'TTI-S':{'id':'TTI-S','entity':'TTI','status':'Pending Accounts Approval'}}})
    status,data=request('local_sales_control',{'entity':'TTI','action':'reject_candidate','candidateId':'TTI-S','note':'Return for review'})
    assert status==200,data
    # View-only cross-module callers cannot write; each real sync keeps its owner.
    user['permissions']={'Mill':{'export':['View'],'newbags':['View']},'Exports':{'bags':['View'],'commercial':['View']},'Directors':['View']}
    seed();before=books.read_bytes()
    for endpoint,action in [('milling_purchase_sodas','sync_lifting'),('bag_purchases','sync_po'),('bag_purchases','sync_receipt_snapshot'),('bag_purchases','sync_export_usage'),('accounts_workflows_v1','save_freight_agreement'),('brokerage_master','save_product_rate')]:
        assert request(endpoint,{'action':action},'')[0]==403,(endpoint,action)
        assert books.read_bytes()==before
    user['permissions']={'Mill':{'newbags':['View','Edit']}}
    seed({'revision':0,'journals':{},'bagPurchaseOrders':{'PO1':{'entity':'TTI','lines':[{'lineKey':'L1','receivedQty':0}]}}})
    status,data=request('bag_purchases',{'action':'sync_receipt_snapshot','poNo':'PO1','lineKey':'L1','receivedQty':5},'')
    assert status==200,data
    assert json.loads(books.read_text())['bagPurchaseOrders']['PO1']['lines'][0]['receivedQty']==5
    # Explicit Master denials survive otherwise unrestricted module grants.
    user['permissions']={'Accounts':'all','Exports':'all','Directors':'all'}
    user['master_access']=True;user['master_permissions']={'banks':['View','Use'],'mills':['View','Use'],'export_customers':['View','Use'],'export_realization_charges':['View','Use']}
    seed();before=auth.read_bytes()
    for endpoint,body,query in [
        ('location-master',{'action':'add','name':'New Mill','type':'Own Mill'},''),
        ('export_customers',{'action':'upsert','customer':{'name':'New Customer','address':'Address'}},''),
        ('export_realization_master',{'action':'delete','id':'CHARGE'},''),
        ('tg_currency_master',{'action':'save_bank','id':'TTI-B','bank':{}},'?entity=TG'),
    ]:
        status,data=request(endpoint,body,query);assert status==403,(endpoint,status,data)
        assert auth.read_bytes()==before,endpoint+' bypassed Master denial'
    user['master_permissions']['banks'].append('Edit');seed();before=auth.read_bytes()
    assert request('tg_currency_master',{'action':'save_bank','id':'TTI-B','bank':{}},'?entity=TG')[0]==403
    assert auth.read_bytes()==before,'TG editor overwrote a TTI bank'
    # Valid tax-refund permission cannot select a foreign/nonexistent bank.
    user['permissions']={'Accounts':{'entity-tti':['View','Create','Edit'],'purchases':['View','Create']}}
    seed();before=books.read_bytes()
    refund={'entity':'TTI','receiptDate':'2026-10-07','amount':100,'bankAccountId':'BRM-B'}
    assert request('sales_tax_refunds',refund)[0]==422
    assert books.read_bytes()==before
    status,data=request('sales_tax_refunds',{**refund,'bankAccountId':'TTI-B'})
    assert status==200,data
    # Posted JV corrections require JV and company approval, not supplier Edit.
    original={'id':'JV1','entity':'TTI','status':'Posted','sourceType':'JV','date':'2026-10-01','reference':'JV1','narration':'Fixture','totalDebit':100,'totalCredit':100,
              'lines':[{'account':'6900','debit':100,'credit':0},{'account':'2140','debit':0,'credit':100}]}
    user['permissions']={'Accounts':{'entity-tti':['View','Edit','Approve'],'supplier':['View','Edit'],'jv':['View']}}
    seed({'revision':0,'journals':{'JV1':original}});before=books.read_bytes()
    correction={'entity':'TTI','postId':'JV1','date':'2026-10-07','reference':'JV1','narration':'Correction','reason':'Correct posting','lines':original['lines']}
    assert request('accounts_post_amend',correction)[0]==403
    assert request('accounts',{'entity':'TTI','action':'reverse_journal','journalId':'JV1','reason':'Correction'})[0]==403
    assert books.read_bytes()==before
    user['permissions']['Accounts']['jv'].append('Approve');seed({'revision':0,'journals':{'JV1':original}})
    status,data=request('accounts_post_amend',correction);assert status==200,data
    # Existing module-wide grants remain usable, including query-only company.
    user['permissions']={'Accounts':['View','Create','Edit','Approve']};seed()
    status,data=request('export_tax_certificates',{'action':'save_certificate','financialYear':'2026-27','bankName':'Bank','certificateNo':'LEGACY','taxCode':'EXP-AWT-NTR','certificateDate':'2026-10-07','certificateAmount':100})
    assert status==200,data
    print('PASS real API owning icons, company IDs/batches, linked sync, Master denials, TG bank targets, refund banks, JV corrections and legacy rights')
