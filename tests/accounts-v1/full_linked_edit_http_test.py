"""Real auth/controllers, disposable source records, no production writes."""
import copy,json,os,pathlib,shutil,subprocess,tempfile
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tti-full-edit-') as directory:
    root=pathlib.Path(directory);app=root/'app';shutil.copytree(ROOT,app,ignore=shutil.ignore_patterns('.git','node_modules','tmp','__pycache__'))
    private=root/'transtrade_private';private.mkdir();books=private/'accounts.json';ops=private/'operations.json';auth=private/'auth.json'
    harness=root/'request.php';harness.write_text(r'''<?php
$r=json_decode($argv[2],true);$_SERVER['REQUEST_URI']=$r['uri'];$_SERVER['REQUEST_METHOD']=$r['method']??'POST';$_SERVER['REMOTE_ADDR']='192.0.2.42';parse_str(parse_url($r['uri'],PHP_URL_QUERY)??'',$_GET);$_POST=[];
session_name('TRANSTRADE_SESSION');session_id('linked-date-fixture');session_start();$_SESSION=['user_id'=>1,'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];session_write_close();
$GLOBALS['fixtureBody']=json_encode($r['body']);$GLOBALS['statusFile']=$argv[3];
class LinkedDateInput {public $context;private $position=0;function stream_open($a,$b,$c,&$d){return true;}function stream_read($n){$s=substr($GLOBALS['fixtureBody'],$this->position,$n);$this->position+=strlen($s);return $s;}function stream_eof(){return $this->position>=strlen($GLOBALS['fixtureBody']);}function stream_stat(){return [];}}
stream_wrapper_unregister('php');stream_wrapper_register('php',LinkedDateInput::class);register_shutdown_function(static function(){file_put_contents($GLOBALS['statusFile'],(string)(http_response_code()?:200));});require $argv[1];
''')
    user={'id':1,'username':'fixture','full_name':'Fixture','role':'Super Admin','active':True,'session_version':1,'permissions':{}}
    masters={'banks':[{'id':'PKR','values':['Company Account','TTI','','Fixture PKR','Bank','','','PKR','123','','','','','Active']},{'id':'USD','values':['Company Account','TG','','Fixture USD','Bank','','','USD','456','','','','','Active']} ]}
    auth.write_text(json.dumps({'users':[user],'masters':masters,'audit':[]}))
    books.write_text(json.dumps({'revision':1,'journals':{},'exportReceipts':{}}))
    ops.write_text(json.dumps({'values':{'transtrade_export_v3_operational':json.dumps({'contracts':[],'shipments':[]})}}))
    env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
    def request(endpoint,body,method='POST'):
        status_file=root/'status';r=subprocess.run(['php','-d',f'session.save_path={root}',str(harness),str(app/'api'/endpoint),json.dumps({'uri':'/api/'+endpoint+('?entity=TG' if method=='GET' else ''),'method':method,'body':{'csrf':'fixture',**body}}),str(status_file)],env=env,text=True,capture_output=True)
        assert r.returncode==0,r.stderr+r.stdout
        try:return int(status_file.read_text()),json.loads(r.stdout)
        except Exception:raise AssertionError(r.stdout+r.stderr)
    # Use the real receipt engine, with a Company FX setting for native TG valuation.
    auth_data=json.loads(auth.read_text());auth_data['masters']['companies']=[{'id':'TG','values':['Trans Grains','TG','','','','','','','','','']}];auth.write_text(json.dumps(auth_data))
    # Source a posted historical TG bank carrying rate instead of a fixture master guess.
    state=json.loads(books.read_text());state['journals']['OPEN']={'id':'OPEN','entity':'TG','date':'2026-07-01','sourceType':'JV','status':'Posted','totalDebit':3670,'totalCredit':3670,'lines':[{'account':'1110','bankAccountId':'USD','currency':'USD','debit':3670,'credit':0,'bankDebit':1000,'bankCredit':0},{'account':'3100','debit':0,'credit':3670}],'meta':{}};books.write_text(json.dumps(state))
    payload={'action':'post_receipt','entity':'TTI','date':'2026-10-01','bankAdviceRef':'SYNTHETIC-ADVICE','remitter':'TG','transactionCurrency':'USD','foreignAmount':100,'realizationRate':280,'grossPkrEquivalent':28000,'pkrBankCredit':28000,'bankAccountId':'PKR','tgBankAccountId':'USD','allocations':[{'targetType':'UNAPPLIED_TG','foreignAmount':100,'customer':'TG'}],'deductions':[]}
    status,d=request('export_receipts.php',payload);assert status==200,d
    original=d['receipt'];pkid=original['journalId']
    status,second=request('export_receipts.php',{**payload,'bankAdviceRef':'SECOND-SYNTHETIC','foreignAmount':50,'grossPkrEquivalent':14000,'pkrBankCredit':14000,'allocations':[{'targetType':'UNAPPLIED_TG','foreignAmount':50,'customer':'TG'}]});assert status==200,second
    status,review=request('tg_remittances.php',{},'GET');assert status==200,review
    confirm={'action':'confirm','entity':'TG','requestKey':'synthetic-confirm-001','ids':[i['id'] for i in review['items']],'fingerprints':{i['id']:i['fingerprint'] for i in review['items']},'sameRemittanceConfirmed':True,'date':'2026-09-21','bankReference':'SYNTHETIC-TG','chargeBankAccountId':'USD','chargeAmount':2}
    status,d=request('tg_remittances.php',confirm);assert status==200,d
    remit=d['posted'];tgid=remit['journalId'];state=json.loads(books.read_text())
    # A real posted non-cash shipment application must survive a full correction.
    state['exportCandidates']={}
    for cid,entity,kind,value,mirror in [('SHIP','TTI','TG_PAKISTAN_INTERCOMPANY',28000,'MIRROR'),('MIRROR','TG','TG_INTERCOMPANY_PAYABLE',367,'SHIP')]:
        jid='RECOGNIZE-'+cid
        state['journals'][jid]={'id':jid,'entity':entity,'date':'2026-10-01','status':'Posted','lines':[],'meta':{}}
        state['exportCandidates'][cid]={'id':cid,'entity':entity,'candidateType':kind,'counterparty':'TG' if entity=='TTI' else 'TTI','transactionCurrency':'USD','transactionAmount':100,'functionalAmount':value,'journalId':jid,'recognitionDate':'2026-10-01','reference':'SYNTHETIC-SHIPMENT','mirrorCandidateId':mirror,'linkedCandidateId':mirror,'meta':{}}
    books.write_text(json.dumps(state))
    status,application=request('export_accounting.php',{'action':'apply_intercompany_advance','entity':'TTI','receiptId':original['id'],'candidateId':'SHIP','date':'2026-10-02','note':'Apply the synthetic advance shipment','expectedRevision':state['revision']});assert status==200,application
    before=books.read_bytes();state=json.loads(before)
    amend={**payload,'amendmentOf':original['id'],'amendmentReason':'Correct all saved receipt fields','requestKey':'synthetic-receipt-edit-001','expectedRevision':state['revision'],'date':'2026-09-25','bankAdviceRef':'SYNTHETIC-CORRECTED','foreignAmount':120,'tgPaymentTotal':120,'realizationRate':281,'grossPkrEquivalent':33720,'pkrBankCredit':33720,'allocations':[{'targetType':'UNAPPLIED_TG','foreignAmount':120,'customer':'TG'}]}
    for patch in [{'foreignAmount':90,'tgPaymentTotal':90,'grossPkrEquivalent':25290,'pkrBankCredit':25290,'allocations':[{'targetType':'UNAPPLIED_TG','foreignAmount':90,'customer':'TG'}]},{'expectedRevision':-1},{'date':'2026-10-03'},{'csrf':'bad'}]:
        status,rejected=request('export_receipts.php',{**amend,**patch});assert status in (419,422),rejected;assert books.read_bytes()==before
    saved_user=copy.deepcopy(user);user['role']='Staff';user['permissions']={'Accounts':{'entity-tti':['View','Edit'],'customer':['View','Edit']}};auth.write_text(json.dumps({'users':[user],'masters':masters,'audit':[]}))
    status,rejected=request('export_receipts.php',amend);assert status in (403,422),rejected;assert books.read_bytes()==before
    user=saved_user;auth.write_text(json.dumps({'users':[user],'masters':masters,'audit':[]}))
    status,d=request('export_receipts.php',{**amend,'preview':True});assert status==200,d;assert d['previews'];assert books.read_bytes()==before
    status,d=request('export_receipts.php',amend);assert status==200,d
    state=json.loads(books.read_text());receipt=d['receipt'];assert receipt['foreignAmount']==120 and receipt['realizationRate']==281 and receipt['date']=='2026-09-25'
    assert state['tgRemittances'][remit['id']]['amountNative']==170 and state['tgRemittances'][remit['id']]['date']=='2026-09-21'
    assert state['journals'][receipt['journalId']]['meta']['publicPostId']==pkid
    assert state['exportReceipts'][second['receipt']['id']]['foreignAmount']==50 and state['exportReceipts'][second['receipt']['id']]['date']=='2026-10-01'
    assert state['journals'][state['tgRemittances'][remit['id']]['journalId']]['meta']['publicPostId']==tgid
    assert not [x for x in state['tgRemittanceDrafts'].values() if x['status']=='Pending']
    assert sum(a['foreignAmount'] for a in state['exportReceipts'][receipt['id']]['allocations'])==120
    assert len(state['intercompanyAdvanceApplications'])==1 and next(iter(state['intercompanyAdvanceApplications'].values()))['receiptId']==receipt['id']
    before=books.read_bytes();status,retry=request('export_receipts.php',amend);assert status==200 and retry['receipt']['id']==receipt['id'];assert books.read_bytes()==before
    # Editing TG restores the original remittance rather than reversing it on open.
    edit={'action':'amend','entity':'TG','remittanceId':remit['id'],'requestKey':'synthetic-remit-edit-001','expectedRevision':state['revision'],'date':'2026-09-20','bankReference':'SYNTHETIC-TG-EDIT','bankAccountId':'USD','chargeBankAccountId':'USD','chargeAmount':4,'reason':'Correct bank charge and reference'}
    status,d=request('tg_remittances.php',{**edit,'preview':True});assert status==200,d;assert books.read_bytes()==before
    status,d=request('tg_remittances.php',edit);assert status==200,d
    state=json.loads(books.read_text());assert d['posted']['bankChargeNative']==4 and d['posted']['bankVatNative']==.2 and d['posted']['date']=='2026-09-20'
    assert state['exportReceipts'][receipt['id']]['date']=='2026-09-25'
    assert not [x for x in state['tgRemittanceDrafts'].values() if x['status']=='Pending']
    assert sum(a['foreignAmount'] for a in state['exportReceipts'][receipt['id']]['allocations'])==120
    assert len(state['intercompanyAdvanceApplications'])==1 and next(iter(state['intercompanyAdvanceApplications'].values()))['receiptId']==receipt['id']
    before=books.read_bytes();assert request('tg_remittances.php',edit)[0]==200 and books.read_bytes()==before
    print('PASS full linked edits through real auth/controllers: amount, rate, date, reference, charge, stable public IDs, no pending reversal, server preview and retries')
