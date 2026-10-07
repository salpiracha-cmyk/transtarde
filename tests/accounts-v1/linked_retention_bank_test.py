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
        req={'uri':'/api/'+endpoint+'.php'+query,'body':{'csrf':'fixture',**body},'form':form or {},'method':method}
        status_file=root/'status.txt'
        r=subprocess.run(['php','-d',f'session.save_path={root}',str(harness),str(app/'api'/f'{endpoint}.php'),json.dumps(req),str(status_file)],env=env,text=True,capture_output=True)
        assert r.returncode==0, r.stdout+r.stderr
        try: return int(status_file.read_text()),json.loads(r.stdout)
        except Exception: raise AssertionError(r.stdout+r.stderr)

    user.update(role='Super Admin',masterAccess=True)
    def company(code, identity):
        values=['']*19
        values[0]='Fixture '+code; values[1]=code; values[2]='Pakistan' if code!='TG' else 'United Arab Emirates'
        values[7]='[]';values[13]=json.dumps([{'id':identity,'bankName':'Meezan Bank Limited',
            'accountTitle':'Fixture '+code,'accountNumber':'fixture-'+code,'iban':'','currency':'PKR',
            'accountType':'Company Account','depositType':'CURRENT','isDefault':True,'status':'Active','retentionEnabled':True}])
        for i in [14,15,16]: values[i]='[]'
        values[17]='{}'
        return {'id':'fixture-'+code,'values':values}
    masters={'companies':[company('TTI','parent-tti'),company('BRM','parent-brm'),company('TG','parent-tg')],'banks':[]}
    original={'revision':1,'journals':{'HIST':{'id':'HIST','entity':'TTI','status':'Posted','date':'2026-07-01',
        'lines':[{'account':'1110','bankAccountId':'parent-tti','debit':1000,'credit':0}]}}}
    seed(original)
    def save(code, values):
        return request('masters',{'action':'update','type':'companies','id':'fixture-'+code,'values':values})
    def current(code):
        data=json.loads(auth.read_text())
        return next(row for row in data['masters']['companies'] if row['id']=='fixture-'+code)['values']
    def rows(code):
        return json.loads(current(code)[13])
    for code in ['TTI','BRM']:
        status,data=save(code,current(code));assert status==200,(status,data)
        banks=rows(code);assert len(banks)==2,banks
        p=next(row for row in banks if not row.get('retentionParentBankId'))
        child=next(row for row in banks if row.get('retentionParentBankId'))
        assert p['currency']=='PKR' and p['retentionEnabled'] and not p['retentionAccount'] and p['isDefault']
        assert child['currency']=='USD' and child['bankName']=='Meezan Bank Retention Account'
        assert child['accountNumber']=='' and child['iban']=='' and child['retentionAccount'] and not child['isDefault']
        child_id=child['id']
        status,data=save(code,current(code));assert status==200,(status,data)
        assert len(rows(code))==2 and any(row['id']==child_id for row in rows(code))
        status,data=request('bank_accounts',{},query='?entity='+code,method='GET');assert status==200,(status,data)
        accounts={row['id']:row for row in data['accounts']}
        assert accounts[p['id']]['settings']['active'] and not accounts[p['id']]['settings']['retentionAccount']
        a=accounts[child_id];assert a['settings']['active'] and a['settings']['allowPayments'] and a['settings']['allowReceipts']
        assert a['settings']['retentionAccount'] and not a['needsCompletion'] and a['displayLabel']=='Meezan Bank Retention Account'
        status,data=request('journal_vouchers',{},query='?entity='+code+'&scope=opening',method='GET')
        assert status==200,(status,data)
        assert {p['id'],child_id}.issubset({row['id'] for row in data['opening']['banks']}),data
        status,data=request('retention_remittances',{},query='?entity='+code,method='GET')
        assert status==200 and any(row['id']==child_id for row in data['banks']),(status,data)
    assert json.loads(books.read_text())['journals']==original['journals'],'Master saves changed financial history'
    status,data=save('TG',current('TG'));assert status==422,(status,data)
    # Proprietor banks cannot create company retention ledgers.
    bad=current('TTI');badrows=json.loads(bad[13]);badrows[0]['accountType']='Proprietor / Owner Account';bad[13]=json.dumps(badrows)
    status,data=save('TTI',bad);assert status==422,(status,data)
    # Malformed links and duplicate children never open incomplete bank accounts.
    bad=current('TTI');badrows=json.loads(bad[13]);badrows[1]['retentionParentBankId']='missing';bad[13]=json.dumps(badrows)
    status,data=save('TTI',bad);assert status==422,(status,data)
    bad=current('TTI');badrows=json.loads(bad[13]);extra=dict(badrows[1],id='duplicate-child');badrows.append(extra);bad[13]=json.dumps(badrows)
    status,data=save('TTI',bad);assert status==422,(status,data)
    # Unticking retains the USD ledger identity/history; reticking reuses it.
    values=current('BRM');banks=json.loads(values[13]);parent=banks[0];child=banks[1];parent['retentionEnabled']=False;values[13]=json.dumps(banks)
    status,data=save('BRM',values);assert status==200,(status,data)
    assert len(rows('BRM'))==2 and rows('BRM')[1]['id']==child['id'] and not rows('BRM')[1]['retentionAccount']
    values=current('BRM');banks=json.loads(values[13]);banks[0]['retentionEnabled']=True;values[13]=json.dumps(banks)
    status,data=save('BRM',values);assert status==200,(status,data)
    assert len(rows('BRM'))==2 and rows('BRM')[1]['id']==child['id'] and rows('BRM')[1]['retentionAccount']
    # Post native USD opening balance separately from existing PKR balance.
    child=rows('TTI')[1]
    status,data=request('journal_vouchers',{'action':'post_opening_balance','entity':'TTI','date':'2026-07-01',
        'targetKey':'bank:'+child['id'],'side':'Debit','amount':50,'rate':280,'requestKey':'retention-opening-fixture',
        'reference':'FIXTURE-USD-OPENING'},query='?entity=TTI&scope=opening')
    assert status==200,(status,data)
    status,data=request('bank_accounts',{},method='GET')
    assert status==200,(status,data)
    accounts={row['id']:row for row in data['accounts']}
    assert accounts['parent-tti']['bookBalance']==1000 and accounts[child['id']]['bookBalance']==50
    assert json.loads(books.read_text())['journals']['HIST']==original['journals']['HIST']
    # A real receipt splits PKR credit from USD retention on the same advice.
    status,data=request('export_receipts',{'action':'post_receipt','entity':'TTI','date':'2026-10-07',
        'transactionCurrency':'USD','foreignAmount':100,'realizationRate':280,'grossPkrEquivalent':28000,
        'bankAccountId':'parent-tti','pkrBankCredit':22400,'bankAdviceRef':'FIXTURE-SPLIT-RETENTION',
        'retentionForeignAmount':20,'retentionBankAccountId':child['id'],'remitter':'TG',
        'allocations':[{'targetType':'UNAPPLIED_TG','foreignAmount':100,'customer':'TG'}],'deductions':[]})
    assert status==200,(status,data)
    status,data=request('bank_accounts',{},method='GET');assert status==200,(status,data)
    accounts={row['id']:row for row in data['accounts']}
    assert accounts['parent-tti']['bookBalance']==23400 and accounts[child['id']]['bookBalance']==70
    print('PASS linked USD retention creation, naming, tick persistence, no duplicates, TTI/BRM Accounts access, opening balances, invalid links and unchanged PKR history')
