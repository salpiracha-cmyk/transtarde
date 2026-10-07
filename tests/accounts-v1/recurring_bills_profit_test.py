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
    user['permissions']={'Accounts':{'expenses':['View','Create','Edit'],'cashbank':['View','Create','Edit'],'masters':['View','Create','Edit'],'entity-tti':['View','Create','Edit'],'entity-tg':['View','Create','Edit']}}
    def company(code,currency):
        values=[code+' Company',code,'Pakistan' if code!='TG' else 'UAE']+['']*11
        values[13]=json.dumps([{'id':code+'-S','bankName':'Saving Bank','accountTitle':code+' Company','accountNumber':'123','currency':currency,'status':'Active','accountType':'Company Account','depositType':'SAVING'}, {'id':code+'-C','bankName':'Current Bank','accountTitle':code+' Company','accountNumber':'456','currency':currency,'status':'Active','accountType':'Company Account','depositType':'CURRENT'}])
        return {'id':'company-'+code,'values':values}
    masters={'companies':[company('TTI','PKR'),company('TG','USD')], 'mills':[
        {'id':'OFFICE','values':['Karachi Office','KHI','Office','','','Active','','TTI']},
        {'id':'MILL','values':['Own Rice Mill','SITE','Own Mill','','','Active','','TTI']},
        {'id':'OTHER','values':['Other Company Office','OTHER','Office','','','Active','','BRM']},
        {'id':'OUTSIDE','values':['External Rice Mill','EXT','External Mill','','','Active','','TTI']}]}
    user['master_access']=True;user['master_permissions']={'mills':['View','Use','Create','Edit'],'companies':['View','Use','Create','Edit']}
    seed()
    def call(body,expected=200,endpoint='expenses_v1',query='?entity=TTI&month=2026-10'):
        before=books.read_bytes();status,data=request(endpoint,body,query)
        assert status==expected,(status,data,body)
        if expected>=400: assert books.read_bytes()==before,'Rejected request changed books'
        return data
    data=call({'action':'update','type':'mills','id':'OFFICE','values':masters['mills'][0]['values']},endpoint='masters',query='')
    assert next(row for row in data['masters']['mills'] if row['id']=='OFFICE')['values'][7]=='TTI',data
    reminder={'action':'save_utility_master','entity':'TTI','utilityType':'ELECTRICITY','location':'OFFICE','locationId':'OFFICE','payee':'Electricity Provider','accountReference':'OWN-1','dueDay':31,'remindDays':7}
    call({**reminder,'locationId':'OTHER'},422);call({**reminder,'locationId':'OUTSIDE'},422)
    data=call(reminder);uid=data['result']['utilityMasterId']
    assert {'OFFICE','MILL'}.issubset({x['id'] for x in data['locations']}) and not {'OTHER','OUTSIDE'} & {x['id'] for x in data['locations']},data
    call(reminder,409)
    user['location']='Karachi Office';auth.write_text(json.dumps({'users':[user],'masters':masters,'audit':[]}))
    scoped=call({**reminder,'id':uid});assert len(scoped['locations'])==1 and scoped['locations'][0]['id']=='OFFICE',scoped
    call({**reminder,'id':uid,'locationId':'MILL'},422)
    user['location']='All authorized locations';auth.write_text(json.dumps({'users':[user],'masters':masters,'audit':[]}))

    # No end date: a future leap February reminder clamps day 31 to day 29.
    data=call({**reminder,'id':uid,'month':'2028-02'})
    assert any(r['dueDate']=='2028-02-29' for r in data['reminders']),data
    data=call({'action':'save_card_master','entity':'TTI','cardName':'Office Card','holder':'Accounts','issuerBank':'Bank','last4':'1234','dueDay':20})
    assert any(r['type']=='CREDIT_CARD' and r['status']=='Statement needed' for r in data['reminders']),data
    payment={'action':'pay_utility','entity':'TTI','month':'2026-10','utilityMasterId':'','utilityType':'ELECTRICITY','location':'MILL','locationId':'MILL','accountingTreatment':'BUSINESS_EXPENSE','payee':'Electricity Provider','paymentDate':'2026-10-07','billMonth':'2026-10','amount':100,'paymentAccountId':'CASH|TTI','reference':'MILL-BILL','requestKey':'bill-fixture-001'}
    call(payment,422)
    data=call({**payment,'readFrom':'2026-09-01','readTo':'2026-09-30','previousReading':100,'currentReading':150})
    pid=data['result']['utilityPaymentId'];journal=data['result']['journalId']
    saved=json.loads(books.read_text());row=saved['utilityPayments'][pid]
    assert row['productionCostEligible'] and row['units']==50,row
    assert saved['journals'][journal]['lines'][0]['account']=='5200',saved['journals'][journal]
    correction={**payment,'action':'amend_utility','id':pid,'reason':'Correct meter bill amount','requestKey':'bill-correction-001','amount':150,'readFrom':'2026-09-01','readTo':'2026-09-30','previousReading':100,'currentReading':155}
    call(correction)
    count=len(json.loads(books.read_text())['journals']);data=call(correction);assert data['result']['duplicate'] and len(json.loads(books.read_text())['journals'])==count
    call({'action':'delete_expense','entity':'TTI','collection':'utilityPayments','id':pid,'reason':'Correct wrong meter bill'})
    saved=json.loads(books.read_text());assert saved['utilityPayments'][pid]['status']=='Deleted'
    assert len(saved['expenseAudit'])==2 and saved['journals'][journal]['reversedByPostId']
    totals={}
    for j in saved['journals'].values():
        for line in j['lines']:totals[line['account']]=totals.get(line['account'],0)+line['debit']-line['credit']
    assert all(abs(amount)<.001 for amount in totals.values()),totals
    # Authorized reminder deletion hides the whole row; Create-only staff cannot delete.
    user['permissions']['Accounts']['expenses']=['View','Create'];auth.write_text(json.dumps({'users':[user],'masters':masters,'audit':[]}))
    call({'action':'deactivate_utility_master','entity':'TTI','id':uid},403)
    user['permissions']['Accounts']['expenses']=['View','Create','Edit'];auth.write_text(json.dumps({'users':[user],'masters':masters,'audit':[]}))
    data=call({'action':'deactivate_utility_master','entity':'TTI','id':uid});assert not data['utilityMasters']
    call({'action':'save_deposit_type','entity':'TTI','accountId':'TTI-S','depositType':'CURRENT'},endpoint='bank_accounts')
    call({'action':'save_deposit_type','entity':'TTI','accountId':'TTI-S','depositType':'SAVING'},endpoint='bank_accounts')
    profit={'entity':'TTI','type':'SAVING_PROFIT','bankId':'TTI-S','date':'2026-10-07','amount':1000,'withholdingTax':150,'reference':'PROFIT-1'}
    call({**profit,'bankId':'TTI-C'},422,'bank_direct_entries')
    data=call(profit,endpoint='bank_direct_entries');saved=json.loads(books.read_text());j=saved['journals'][data['journalId']]
    lines={line['account']:line for line in j['lines']}
    assert lines['1110']['debit']==850 and lines['1260']['debit']==150 and lines['4400']['credit']==1000,j
    call(profit,409,'bank_direct_entries')
    call({**profit,'reference':'BAD-DATE','date':'2026-02-31'},422,'bank_direct_entries')
    fx={**profit,'entity':'TG','bankId':'TG-S','reference':'USD-PROFIT','amount':100,'withholdingTax':10}
    call(fx,422,'bank_direct_entries','?entity=TG')
    data=call({**fx,'exchangeRate':3.6725},endpoint='bank_direct_entries',query='?entity=TG')
    j=json.loads(books.read_text())['journals'][data['journalId']];lines={line['account']:line for line in j['lines']}
    assert lines['1110']['debit']==330.52 and lines['1110']['bankDebit']==90 and lines['4400']['credit']==367.25,j
    user['permissions']['Accounts']['cashbank']=['View'];auth.write_text(json.dumps({'users':[user],'masters':masters,'audit':[]}))
    call({**profit,'reference':'DENIED'},403,'bank_direct_entries')
print('PASS owned locations, permanent monthly reminders, meter validation, audited expense deletion, exact grants, gross/net/WHT and foreign saving profit')
