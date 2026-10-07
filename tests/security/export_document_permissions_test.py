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
    import copy
    key='transtrade_export_v3_operational'
    operations=private/'operations.json'
    original={'version':'clean-v3','customers':[],'suppliers':[],'fi':[],'contracts':[{'id':'C1','ref':'TTI/BUYER/01','seller':'TTI'}],
        'shipments':[{'id':'L1','kind':'lot','contractRef':'TTI/BUYER/01','lotId':'L01','completed':False,'customs':{'saved':False},'bl':{'draftSaved':False},'commercial':{'saved':False},'coo':{'saved':False},'covering':{'saved':False},'lc':{'saved':False},'tgdocs':{'saved':False},'certs':[],'bagOrders':[],'production':{'sentToMill':False},'loading':{'lots':[]}}],
        'accountsReceipts':[],'millSync':{'newExportBags':[],'productionInstructions':[],'exportLoading':[]},'audits':[],'alerts':[],'settings':{}}
    def put_base(root_state,grants):
        user['permissions']={'Exports':grants};seed()
        operations.write_text(json.dumps({'revision':1,'values':{key:json.dumps(root_state)},'meta':{key:{'version':1}}}))
    def save_root(root_state,status=200):
        before=operations.read_bytes();actual,data=request('operations.mysql',{'key':key,'value':json.dumps(root_state),'baseVersion':1,'sourceModule':'Exports'},'')
        assert actual==status,(actual,data,root_state)
        if status>=400:assert operations.read_bytes()==before,'Denied document changed shared storage'
        return data
    for field,icon in [('customs','customs'),('bl','bl'),('commercial','commercial'),('coo','coo'),('covering','cover'),('lc','lcdraft'),('tgdocs','tg'),('production','production')]:
        change=copy.deepcopy(original);change['shipments'][0][field]={'draftSaved':True,'description':'Saved first document'} if field=='bl' else {'sentToMill':True} if field=='production' else {'saved':True,'description':'Saved first document'}
        change['shipments'][0]['documentActivity']=[{'at':'2026-10-07','document':field,'action':'Saved'}]
        put_base(original,{icon:['View','Create']});save_root(change)
        amend=copy.deepcopy(change);amend['shipments'][0][field]['description']='Amended document'
        put_base(change,{icon:['View','Create']});save_root(amend,403)
        put_base(change,{icon:['View','Edit']});save_root(amend)
        put_base(original,{'bags':['View','Create','Edit']});save_root(change,403)
    # Upload record authority follows the actual document category, never an unrelated icon.
    change=copy.deepcopy(original);change['shipments'][0]['uploadedDocuments']=[{'id':'D1','name':'Final B/L','finalDocument':{'id':'FILE1'}}]
    change['shipments'][0]['documentActivity']=[{'document':'B/L','action':'Uploaded'}];put_base(original,{'bl':['View','Create']});save_root(change)
    put_base(original,{'customs':['View','Create']});save_root(change,403)
    replacing=copy.deepcopy(change);replacing['shipments'][0]['uploadedDocuments'][0]['id']='D2'
    put_base(change,{'bl':['View','Create']});save_root(replacing,403)
    # A document writer may refresh committed Customer Master identity without
    # receiving permission to amend the master or the sales contract.
    customerValues=['New Buyer','BUY','Export Buyer','New Address','Pakistan','','','','KG','[]','Active','','No','No','No','No']
    masters['export_customers']=[{'id':'MC1','values':customerValues}]
    identity={'id':'U1','masterId':'MC1','name':'Old Buyer','code':'BUY','address':'Old Address','country':'Pakistan','email':'','phone':'','tax':'','packingDefault':'KG','notifies':[],'showCountry':False,'showEmail':False,'showPhone':False,'showTax':False,'inactive':False,'nextSeq':1}
    before=copy.deepcopy(original);before['customers']=[identity];before['contracts'][0].update(customerId='U1',buyerDetails={'address':'Old Address','country':'Pakistan','email':'','phone':'','tax':'','showCountry':False,'showEmail':False,'showPhone':False,'showTax':False})
    before['shipments'][0].update(buyer='Old Buyer');before['shipments'][0]['bl']['notify']='Old Buyer'
    change=copy.deepcopy(before);change['customers'][0].update(name='New Buyer',address='New Address');change['contracts'][0]['buyerDetails']['address']='New Address';change['shipments'][0]['buyer']='New Buyer';change['shipments'][0]['bl']['notify']='New Buyer';change['shipments'][0]['commercial']={'saved':True,'invoiceNo':'INV1'}
    put_base(before,{'commercial':['View','Create']});save_root(change)
    change['customers'][0]['name']='Invented Buyer';put_base(before,{'commercial':['View','Create']});save_root(change,403)
    # Closed documents require owner reopening; broad staff Edit is not enough.
    closed=copy.deepcopy(original);closed['shipments'][0]['completed']=True
    change=copy.deepcopy(closed);change['shipments'][0]['commercial']={'saved':True,'invoiceNo':'BAD'}
    put_base(closed,{'commercial':['View','Edit'],'active':['View','Edit']});save_root(change,403)
    reopened=copy.deepcopy(closed);reopened['shipments'][0].update(completed=False,reopenedAt='2026-10-07T12:00:00Z',reopenReason='Correction needed')
    put_base(closed,{'commercial':['View','Edit'],'active':['View','Edit']});save_root(reopened,403)
    user['role']='Super Admin';put_base(closed,'all');save_root(change,403)
    put_base(closed,'all');save_root(reopened)
    user['role']='Staff'
    # Mill root updates still require Export Loading, despite another writable icon.
    change=copy.deepcopy(original);change['shipments'][0]['millActuals']=[{'id':'CON1','netKg':100}]
    put_base(original,{});user['permissions']={'Mill':{'production':['View','Create']}};auth.write_text(json.dumps({'users':[user],'masters':masters,'audit':[]}))
    before=operations.read_bytes();status,data=request('operations.mysql',{'key':key,'value':json.dumps(change),'baseVersion':1,'sourceModule':'Mill'},'')
    assert status==403 and operations.read_bytes()==before,(status,data)
print('PASS real Exports document Create/Edit matrix, unrelated-icon denial, upload category, completed-lot protection, owner reopening, and Mill source ownership')
