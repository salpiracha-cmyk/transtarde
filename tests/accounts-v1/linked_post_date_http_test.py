"""Real auth/controllers, disposable source records, no production writes."""
import copy,json,os,pathlib,shutil,subprocess,tempfile
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tti-linked-date-') as directory:
    root=pathlib.Path(directory);app=root/'app';shutil.copytree(ROOT,app,ignore=shutil.ignore_patterns('.git','node_modules','tmp','__pycache__'))
    private=root/'transtrade_private';private.mkdir();books=private/'accounts.json';ops=private/'operations.json';auth=private/'auth.json'
    harness=root/'request.php';harness.write_text(r'''<?php
$r=json_decode($argv[2],true);$_SERVER['REQUEST_URI']=$r['uri'];$_SERVER['REQUEST_METHOD']=$r['method']??'POST';$_SERVER['REMOTE_ADDR']='192.0.2.42';parse_str(parse_url($r['uri'],PHP_URL_QUERY)??'',$_GET);$_POST=[];
session_name('TRANSTRADE_SESSION');session_id('linked-date-fixture');session_start();$_SESSION=['user_id'=>1,'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];session_write_close();
$GLOBALS['fixtureBody']=json_encode($r['body']);$GLOBALS['statusFile']=$argv[3];
class LinkedDateInput {public $context;private $position=0;function stream_open($a,$b,$c,&$d){return true;}function stream_read($n){$s=substr($GLOBALS['fixtureBody'],$this->position,$n);$this->position+=strlen($s);return $s;}function stream_eof(){return $this->position>=strlen($GLOBALS['fixtureBody']);}function stream_stat(){return [];}}
stream_wrapper_unregister('php');stream_wrapper_register('php',LinkedDateInput::class);register_shutdown_function(static function(){file_put_contents($GLOBALS['statusFile'],(string)(http_response_code()?:200));});require $argv[1];
''')
    user={'id':1,'username':'fixture','full_name':'Fixture','role':'Staff','active':True,'session_version':1,'permissions':{'Accounts':{'entity-tti':['View','Edit'],'customer':['View','Edit']}}}
    def journal(identity,entity,kind,date,amount,meta):
        return {'id':identity,'entity':entity,'date':date,'sourceType':kind,'reference':'FIXTURE','narration':'Saved source','status':'Posted','totalDebit':amount,'totalCredit':amount,'meta':meta,'lines':[{'account':'1110','bankAccountId':entity+'-BANK','currency':'USD' if entity=='TG' else 'PKR','debit':amount,'credit':0,'bankDebit':100 if entity=='TG' else amount,'bankCredit':0},{'account':'2510','debit':0,'credit':amount}]}
    initial={'revision':1,'journals':{'PK':journal('PK','TTI','EXPORT_FOREIGN_RECEIPT','2026-10-01',28000,{'receiptId':'R','remitter':'TG','tgRemittanceId':'REMIT','tgPostIds':['TG']}),'TG':journal('TG','TG','TG_REMITTANCE_CONFIRMED','2026-09-21',367,{'tgRemittanceId':'REMIT','currency':'USD','date':'2026-09-21'})},'exportReceipts':{'R':{'id':'R','entity':'TTI','remitter':'TG','status':'Accounts Approved / Posted','date':'2026-10-01','journalId':'PK','tgRemittanceId':'REMIT','tgRemittancePostId':'TG','transactionCurrency':'USD','bankAdviceRef':'SAVED','realizationRate':280,'allocations':[{'targetType':'INTERCOMPANY_RECEIVABLE','targetId':'INVOICE','contractRef':'C1','invoiceRef':'CI1','foreignAmount':100}]}},'tgRemittances':{'REMIT':{'id':'REMIT','status':'Posted','journalId':'TG','date':'2026-09-21','receiptIds':['R']}},'tgBankTransactions':{'TX':{'journalId':'TG','date':'2026-09-21','status':'Posted'}},'intercompanyAdvanceApplications':{'APP':{'status':'Posted','receiptId':'R','tgRemittanceId':'REMIT','date':'2026-10-02'}}}
    initial['journals']['PK']['meta']['exportTaxDeductions']=[{'taxCode':'EXP-AWT-NTR','amount':100}]
    initial['exportTaxCertificates']={'CERT':{'entity':'TTI','financialYear':'2026-27','certificateAmount':100,'taxCode':'EXP-AWT-NTR'}}
    initial['exportTaxCertificateMatches']={'PK|M|0':'CERT'}
    operations={'values':{'transtrade_export_v3_operational':json.dumps({'contracts':[],'shipments':[],'accountsReceipts':[{'id':'R-1','_accountsReceiptId':'R','receiptType':'TG_PACK_PAYMENT','date':'2026-10-01','amount':100,'untouched':'retain'}]})}}
    env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
    def seed():
        auth.write_text(json.dumps({'users':[user],'masters':{},'audit':[]}));books.write_text(json.dumps(initial));ops.write_text(json.dumps(operations))
    def request(endpoint,body,method='POST'):
        status_file=root/'status';r=subprocess.run(['php','-d',f'session.save_path={root}',str(harness),str(app/'api'/endpoint),json.dumps({'uri':'/api/'+endpoint+('?entity=TTI&fy=2026-27' if method=='GET' else ''),'method':method,'body':{'csrf':'fixture',**body}}),str(status_file)],env=env,text=True,capture_output=True)
        assert r.returncode==0,r.stderr+r.stdout
        try:return int(status_file.read_text()),json.loads(r.stdout)
        except Exception:raise AssertionError(r.stdout+r.stderr)
    body={'action':'correct_date','datesOnly':True,'entity':'TTI','postId':'PK','expectedDate':'2026-10-01','date':'2026-09-24','reason':'Correct receipt date'}
    seed();before=(books.read_bytes(),ops.read_bytes())
    status,d=request('export_receipts.php',{**body,'preview':True});assert status==200 and len(d['previews'])==2,d
    assert (books.read_bytes(),ops.read_bytes())==before,'Preview must not change books or Exports'
    for patch,expected in [({'csrf':'invalid'},419),({'entity':'TG'},403),({'date':'2026-10-03'},422),({'expectedDate':'2026-09-01'},422)]:
        status,d=request('export_receipts.php',{**body,**patch});assert status==expected,(patch,status,d);assert (books.read_bytes(),ops.read_bytes())==before
    user['permissions']['Accounts']['customer']=['View'];seed();before=books.read_bytes();assert request('export_receipts.php',body)[0]==403;assert books.read_bytes()==before
    user['permissions']['Accounts']['customer']=['View','Edit'];seed();status,d=request('export_receipts.php',body);assert status==200,d;assert d['sourceSynced'] is True
    saved=json.loads(books.read_text());assert saved['exportReceipts']['R']['date']=='2026-09-24';assert saved['journals']['TG']==initial['journals']['TG'];assert saved['intercompanyAdvanceApplications']==initial['intercompanyAdvanceApplications']
    projected=json.loads(json.loads(ops.read_text())['values']['transtrade_export_v3_operational']);assert projected['accountsReceipts'][0]['date']=='2026-09-24';assert projected['accountsReceipts'][0]['amount']==100
    user['permissions']['Accounts']['reports']=['View'];auth.write_text(json.dumps({'users':[user],'masters':{},'audit':[]}))
    status,tax=request('export_tax_certificates.php',{},method='GET');assert status==200,tax;assert len(tax['deductions'])==1 and tax['summary']['receiptTax']==100 and tax['summary']['matchedTax']==100,tax
    before=books.read_bytes();assert request('export_receipts.php',body)[0]==422;assert books.read_bytes()==before,'Stale retry cannot duplicate correction'
    # TG rights cannot be borrowed from Pakistan or an unrelated icon.
    tg={**body,'entity':'TG','postId':'TG','expectedDate':'2026-09-21','date':'2026-09-20'}
    seed();before=books.read_bytes();assert request('accounts_post_amend.php',tg)[0]==403;assert books.read_bytes()==before
    user['permissions']['Accounts']={'entity-tg':['View','Edit'],'tg':['View','Edit']};seed();before=books.read_bytes();status,d=request('accounts_post_amend.php',{**tg,'preview':True});assert status==200,d;assert books.read_bytes()==before
    status,d=request('accounts_post_amend.php',tg);assert status==200,d;after=json.loads(books.read_text());assert after['tgRemittances']['REMIT']['date']=='2026-09-20';assert after['exportReceipts']['R']['date']=='2026-10-01';assert after['journals'][d['result']['replacementPostId']]['lines']==initial['journals']['TG']['lines']
    print('PASS real linked-date HTTP: workflow/company Edit, CSRF, preview isolation, stale retry, application chronology, native source and Exports date sync')
