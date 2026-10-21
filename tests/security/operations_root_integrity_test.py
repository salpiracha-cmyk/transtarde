"""Exercise root history ownership through the actual file/MySQL writer."""
import json, os, pathlib, shutil, subprocess, tempfile

ROOT = pathlib.Path(__file__).resolve().parents[2]
MYSQL = os.environ.get('TT_QA_MYSQL') == '1'
if MYSQL:
    assert os.environ.get('TT_DB_HOST') == '127.0.0.1'
    assert os.environ.get('TT_DB_NAME', '').startswith('transtrade_qa_')

with tempfile.TemporaryDirectory(prefix='tti-root-integrity-') as directory:
    root = pathlib.Path(directory)
    app = root / 'app'
    shutil.copytree(ROOT, app, ignore=shutil.ignore_patterns('.git', 'node_modules'))
    private = root / 'transtrade_private'
    private.mkdir()
    harness = root / 'request.php'
    harness.write_text(r'''<?php
$cfg=json_decode($argv[2],true);$_SERVER['REQUEST_URI']='/api/operations.mysql.php';
$_SERVER['REQUEST_METHOD']='POST';$_SERVER['REMOTE_ADDR']='192.0.2.40';
session_name('TRANSTRADE_SESSION');session_id('root-integrity-fixture');session_start();
$_SESSION=['user_id'=>1,'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];session_write_close();
$GLOBALS['fixtureBody']=json_encode($cfg);$GLOBALS['statusFile']=$argv[3];
class FixtureCashInput{public $context;private $offset=0;
 function stream_open($p,$m,$o,&$opened){return $p==='php://input';}
 function stream_read($n){$s=substr($GLOBALS['fixtureBody'],$this->offset,$n);$this->offset+=strlen($s);return $s;}
 function stream_eof(){return $this->offset>=strlen($GLOBALS['fixtureBody']);}
 function stream_stat(){return [];}}
stream_wrapper_unregister('php');stream_wrapper_register('php',FixtureCashInput::class);
register_shutdown_function(static function(){file_put_contents($GLOBALS['statusFile'],(string)(http_response_code()?:200));});
require $argv[1].'/api/operations.mysql.php';
''')
    env = dict(os.environ)
    if not MYSQL:
        for name in list(env):
            if name.startswith('TT_DB_'):
                del env[name]
    sql_script = root / 'database.php'
    sql_script.write_text(r'''<?php
$p=new PDO('mysql:host='.getenv('TT_DB_HOST').';dbname='.getenv('TT_DB_NAME').';charset=utf8mb4',getenv('TT_DB_USER'),getenv('TT_DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$p->exec('CREATE TABLE IF NOT EXISTS tt_operation_records(storage_key VARCHAR(96) PRIMARY KEY,payload LONGTEXT NOT NULL,version BIGINT UNSIGNED NOT NULL DEFAULT 1,updated_at DATETIME(6) NOT NULL,updated_by VARCHAR(160) NOT NULL,updated_by_user BIGINT NULL,updated_by_module VARCHAR(32) NOT NULL) ENGINE=InnoDB');
if($argv[1]==='seed'){
 $values=json_decode($argv[2],true);$p->exec('DELETE FROM tt_operation_records');
 $q=$p->prepare("INSERT INTO tt_operation_records VALUES(?,?,1,NOW(6),'Fixture',1,'Mill')");
 foreach($values as $key=>$value)$q->execute([$key,$value]);
}elseif($argv[1]==='cleanup'){
 $p->exec('DELETE FROM tt_operation_records');$p->exec('DELETE FROM tt_operation_history');
}else{echo json_encode($p->query('SELECT storage_key,payload,version FROM tt_operation_records ORDER BY storage_key')->fetchAll(PDO::FETCH_ASSOC));}
''')
    auth = private / 'auth.json'
    operations = private / 'operations.json'
    key='transtrade_export_v3_operational'
    canonical={'contracts':[{'id':'C1','ref':'KEEP'},{'id':'C2','ref':'OLD'}],
        'shipments':[{'id':'L1','kind':'lot','contractRef':'KEEP','millActuals':[]}],
        'accountsReceipts':[{'id':'R1','amount':100}],
        'alerts':[{'id':'AL1','area':'Exports','message':'Original','seen':False}],
        'audits':[{'id':'A1','area':'Sales Contract','action':'Created','detail':'KEEP','at':'2026-01-01'}],
        'deletedShipments':[{'id':'DEL1','contractRef':'OLD','deletedAt':'2026-10-01'}]}
    def clone(value): return json.loads(json.dumps(value))
    def seed(module, role='Staff'):
        auth.write_text(json.dumps({'users':[{'id':1,'username':'fixture','full_name':'Actual Operator','role':role,'active':True,'session_version':1,
            'permissions':{module:{'export':['View','Create','Edit']} if module=='Mill' else {'expenses':['View','Create','Edit']}}}], 'masters':{},'audit':[]}))
        values={key:json.dumps(canonical)}
        operations.write_text(json.dumps({'revision':1,'values':values,'meta':{key:{'version':1}}}))
        if MYSQL: subprocess.run(['php',str(sql_script),'seed',json.dumps(values)],env=env,check=True,capture_output=True)
    def stored():
        if MYSQL: return subprocess.run(['php',str(sql_script),'read'],env=env,check=True,capture_output=True).stdout
        return operations.read_bytes()
    def read_root():
        rows=json.loads(stored())
        return json.loads(next(r['payload'] for r in rows if r['storage_key']==key)) if MYSQL else json.loads(rows['values'][key])
    def write(module, rows):
        payload={'csrf':'fixture','sourceModule':module,'key':key,'value':json.dumps(rows),'baseVersion':1}
        status_file=root/'status.txt'
        result=subprocess.run(['php','-d',f'session.save_path={root}',str(harness),str(app),json.dumps(payload),str(status_file)],env=env,text=True,capture_output=True)
        assert result.returncode==0,result.stdout+result.stderr
        return int(status_file.read_text()),json.loads(result.stdout)
    for module in ['Accounts','Mill','Milling']:
        owner='Mill' if module=='Milling' else module
        for field in ['accountsReceipts','audits','alerts']:
            for mutation in ['replace','append']:
                seed(owner);candidate=clone(canonical)
                forged={'id':canonical[field][0]['id'] if mutation=='replace' else 'FORGED','area':'Sales Contract','action':'Created','detail':'OLD','at':'2099-01-01','message':'Forged','amount':9999}
                candidate[field]=[forged] if mutation=='replace' else candidate[field]+[forged]
                before=stored();status,response=write(module,candidate)
                assert status==403,(module,field,mutation,status,response)
                assert stored()==before,(module,field,mutation,'denial changed storage')
        # An unchanged root is harmless; history and old deletion marker survive.
        seed(owner);status,response=write(module,canonical)
        assert status==200,(module,status,response)
        assert read_root()==canonical,(module,read_root())
    # A legitimate Mill physical update produces a server-attributed event. A
    # caller observation cannot choose its trusted message, actor, ID, or time.
    for module,role in [('Mill','Staff'),('Milling','Staff'),('Mill','Director'),('Mill','Super Admin')]:
        seed('Mill',role);candidate=clone(canonical)
        candidate['shipments'][0]['millActuals']=[{'number':'ABCD1234567','seal':'SEAL','bags':500,'netKg':25000,'source':'Milling'}]
        candidate['alerts'].append({'id':'FORGED-OBSERVATION','area':'MILL ACTUALS','kind':'Mill Update','contractRef':'KEEP','message':'Caller narrative','createdBy':'Forged Owner','createdAt':'2099-01-01'})
        status,response=write(module,candidate);assert status==200,(module,role,status,response)
        saved=read_root()
        assert saved['accountsReceipts']==canonical['accountsReceipts']
        assert saved['audits'][0]==canonical['audits'][0] and saved['alerts'][0]==canonical['alerts'][0]
        assert len(saved['audits'])==2 and len(saved['alerts'])==3,saved
        assert saved['audits'][1]['by']=='Actual Operator' and saved['audits'][1]['userId']==1
        assert saved['alerts'][1]['createdBy']=='Actual Operator' and saved['alerts'][1]['source']=='Milling observation'
        assert 'FORGED-OBSERVATION' not in json.dumps(saved) and 'Forged Owner' not in json.dumps(saved)
        assert saved['alerts'][1]['message']=='Reported by Actual Operator: Caller narrative'
        assert saved['alerts'][2]['source']=='Server'
        assert 'restoredAt' not in saved['deletedShipments'][0],saved
        assert saved['shipments'][0]['millActuals'][0]['netKg']==25000
        # The actual shared-sync caller advances its version without replacing
        # localStorage with the server acknowledgement. Repeat that stale event
        # subset with a second physical fact at the new current version.
        candidate['shipments'][0]['millActuals'][0]['bags']=501
        payload={'csrf':'fixture','sourceModule':module,'key':key,'value':json.dumps(candidate),'baseVersion':2}
        status_file=root/'status.txt'
        result=subprocess.run(['php','-d',f'session.save_path={root}',str(harness),str(app),json.dumps(payload),str(status_file)],env=env,text=True,capture_output=True)
        assert result.returncode==0,result.stdout+result.stderr
        assert int(status_file.read_text())==200,result.stdout+result.stderr
        again=read_root();assert len(again['audits'])==3 and again['shipments'][0]['millActuals'][0]['bags']==501,again
    # Conflict/limit warnings can occur without a physical mutation. Retain
    # them as typed operator reports; retries cannot duplicate the same report.
    for kind in ['Mill Conflict','Container Limit','Migration Review']:
        seed('Mill');candidate=clone(canonical)
        candidate['alerts'].append({'id':'CLIENT-ID','area':'MILL ACTUALS','kind':kind,'contractRef':'KEEP','message':'Loading needs review','createdBy':'Forged','createdAt':'2099-01-01'})
        status,response=write('Mill',candidate);assert status==200,response
        saved=read_root();assert saved['audits']==canonical['audits'] and len(saved['alerts'])==2,saved
        report=saved['alerts'][1]
        assert report['source']=='Milling observation' and report['createdBy']=='Actual Operator' and report['id']!='CLIENT-ID' and report['createdAt']!='2099-01-01',report
    seed('Mill');candidate=clone(canonical)
    candidate['alerts'].append({'id':'CLIENT-ID','area':'MILL ACTUALS','kind':'Mill Conflict','contractRef':'OTHER','message':'Unknown contract'})
    before=stored();assert write('Mill',candidate)[0]==403;assert stored()==before
    if MYSQL: subprocess.run(['php',str(sql_script),'cleanup'],env=env,check=True,capture_output=True)
    print('PASS root integrity ('+('MySQL' if MYSQL else 'file')+'): receipt/audit/alert forgeries denied without writes; real Mill facts produce server events; deletion marker and canonical history preserved; Director/SA and Milling alias retained')
