"""Real Ex-Mill liability requests respect the physical worker's assigned scope."""
import json, os, pathlib, shutil, subprocess, tempfile

ROOT = pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tti-exmill-scope-') as directory:
    root = pathlib.Path(directory)
    app = root / 'app'
    shutil.copytree(ROOT, app, ignore=shutil.ignore_patterns('.git', 'node_modules', '__pycache__'))
    private = root / 'transtrade_private'
    private.mkdir()
    harness = root / 'request.php'
    harness.write_text(r'''<?php
$_SERVER['REQUEST_URI']='/api/milling_purchase_sodas.php';$_SERVER['REQUEST_METHOD']='POST';$_SERVER['REMOTE_ADDR']='192.0.2.42';
session_name('TRANSTRADE_SESSION');session_id('exmill-scope-'.bin2hex(random_bytes(12)));session_start();
$_SESSION=['user_id'=>1,'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];session_write_close();
$GLOBALS['fixtureBody']=$argv[3];$GLOBALS['statusFile']=$argv[2];
class FixtureExMillInput{public $context;private $offset=0;function stream_open($p,$m,$o,&$opened){return $p==='php://input';}function stream_read($n){$v=substr($GLOBALS['fixtureBody'],$this->offset,$n);$this->offset+=strlen($v);return $v;}function stream_eof(){return $this->offset>=strlen($GLOBALS['fixtureBody']);}function stream_stat(){return [];}}
stream_wrapper_unregister('php');stream_wrapper_register('php',FixtureExMillInput::class);
register_shutdown_function(static function(){file_put_contents($GLOBALS['statusFile'],(string)(http_response_code()?:200));});
require $argv[1];
''')
    auth = private / 'auth.json'
    books = private / 'accounts.json'
    env = {k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
    soda = {'id':'S1','entity':'TTI','sodaNo':'26001','readyRoute':'EX_MILL','locationId':'INDUS','locationName':'Indus Rice Mill','locationType':'External Mill','qtyFromKg':1000,'qtyToKg':1000,'ratePerKg':100,'party':'Fixture supplier'}
    worker = {'id':1,'username':'fixture','full_name':'Fixture worker','role':'Staff','active':True,'session_version':1,'location':'TTI Rice Mills','permissions':{'Mill':{'export':['View','Create','Edit']}}}
    body = {'action':'sync_lifting','csrf':'fixture','sourceSodaId':'S1','liftingId':'CN-ABCD1234567','entity':'TTI','date':'2026-10-18','container':'ABCD1234567','truck':'QA123','kg':100,'bags':2,'qualityStatus':'OK'}

    def request(user, source=soda, payload=body):
        auth.write_text(json.dumps({'users':[user],'masters':{},'audit':[]}))
        books.write_text(json.dumps({'revision':0,'purchaseSodas':{'S1':source},'journals':{},'events':{},'purchaseSodaLiftings':{}}))
        before = books.read_bytes()
        status_file = root / 'status.txt'
        result = subprocess.run(['php','-d',f'session.save_path={root}',str(harness),str(app/'api/milling_purchase_sodas.php'),str(status_file),json.dumps(payload)],env=env,text=True,capture_output=True)
        assert result.returncode == 0, result.stderr + result.stdout
        return int(status_file.read_text()),json.loads(result.stdout),before,books.read_bytes()

    status,data,before,after = request(worker)
    assert status == 403 and before == after,(status,data)
    assigned = {**worker,'location':'  INDUS   RICE MILL  '}
    status,data,_,after = request(assigned)
    assert status == 200 and data['lifting']['kg'] == 100 and len(json.loads(after)['journals']) == 1,(status,data)
    for location in ['', 'All locations', 'All authorized locations']:
        assert request({**worker,'location':location})[0] == 200
    view_only = {**assigned,'permissions':{'Mill':{'export':['View']}}}
    status,_,before,after = request(view_only)
    assert status == 403 and before == after
    accounts = {**worker,'permissions':{'Accounts':{'purchases':['View','Create'],'entity-tti':['View','Create']}}}
    assert request(accounts)[0] == 200,'Authorized Accounts purchase handoff must remain available'
    denied_company = {**assigned,'permissions':{'Mill':{'export':['View','Create']},'Accounts':{'entity-tti':['View','Create']}}}
    status,_,before,after = request(denied_company,{**soda,'entity':'BRM'},{**body,'entity':'BRM'})
    assert status == 403 and before == after
    assert request({**worker,'role':'Super Admin'})[0] == 200
    print('PASS actual Ex-Mill handoff scope: unrelated physical location denied without writes; exact/broad assigned locations and authorized Accounts/Super Admin retained; view-only and company denials preserve books')
