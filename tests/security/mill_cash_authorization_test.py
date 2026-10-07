"""Exercise the actual operational writer with isolated cash stores and real auth."""
import json, os, pathlib, shutil, subprocess, tempfile

ROOT = pathlib.Path(__file__).resolve().parents[2]
MYSQL = os.environ.get('TT_QA_MYSQL') == '1'
if MYSQL:
    assert os.environ.get('TT_DB_HOST') == '127.0.0.1'
    assert os.environ.get('TT_DB_NAME', '').startswith('transtrade_qa_')

with tempfile.TemporaryDirectory(prefix='tti-cash-authorization-') as directory:
    root = pathlib.Path(directory)
    app = root / 'app'
    shutil.copytree(ROOT, app, ignore=shutil.ignore_patterns('.git', 'node_modules'))
    private = root / 'transtrade_private'
    private.mkdir()
    harness = root / 'request.php'
    harness.write_text(r'''<?php
$cfg=json_decode($argv[2],true);$_SERVER['REQUEST_URI']='/api/operations.mysql.php';
$_SERVER['REQUEST_METHOD']='POST';$_SERVER['REMOTE_ADDR']='192.0.2.40';
session_name('TRANSTRADE_SESSION');session_id('cash-fixture');session_start();
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
    seed_values = {'tt30petty':'[{"id":1,"date":"2026-10-07","credit":20,"ref":"Original","voucher":"PV-1"}]',
                   'tt33pettyexp':'[{"id":2,"date":"2026-10-07","amount":10,"type":"Other","ref":"Original","voucher":"PV-2"}]',
                   'tt37usedbags':'[{"id":1001,"date":"2026-10-07","qty":5,"rate":2,"source":"arrival","movement":"Outward","type":"Sale"}]',
                   'tt37processingexpenses':'[{"id":1002,"date":"2026-10-07","amount":30,"type":"Plant Expense","party":"Supplier","ref":"INV1","payment":"Petty Cash"}]'}
    def seed(permissions, role='Staff'):
        auth.write_text(json.dumps({'users':[{'id':1,'username':'fixture','full_name':'Fixture','role':role,'active':True,'session_version':1,'permissions':{'Mill':permissions}}], 'masters':{},'audit':[]}))
        operations.write_text(json.dumps({'revision':1,'values':seed_values,'meta':{k:{'version':1} for k in seed_values}}))
        if MYSQL:
            subprocess.run(['php',str(sql_script),'seed',json.dumps(seed_values)],env=env,check=True,capture_output=True)
    def stored():
        if MYSQL:
            return subprocess.run(['php',str(sql_script),'read'],env=env,check=True,capture_output=True).stdout
        return operations.read_bytes()
    def write(key, rows, csrf='fixture', module='Mill', base=1):
        payload={'csrf':csrf,'sourceModule':module,'key':key,'value':json.dumps(rows),'baseVersion':base}
        status_file = root / 'status.txt'
        result=subprocess.run(['php','-d',f'session.save_path={root}',str(harness),str(app),json.dumps(payload),str(status_file)],env=env,text=True,capture_output=True)
        assert result.returncode==0,result.stdout+result.stderr
        return int(status_file.read_text()),json.loads(result.stdout)
    originals = {k:json.loads(v) for k,v in seed_values.items()}
    receipt={'id':3,'date':'2026-10-07','credit':10,'ref':'Cash Received from Sale of Used Bags — Arrival / Pohanch Stock','voucher':'UB-1001'}
    expense={'id':4,'date':'2026-10-07','amount':30,'type':'Plant Expense','ref':'INV1 — Supplier','voucher':'PE-1002'}
    for permission in [{'arrival':['View','Create']},{'petty':['View']},{'oldbags':['View','Edit']}]:
        seed(permission); before=stored()
        for key in ['tt30petty','tt33pettyexp']:
            assert write(key, originals[key]+[{'id':99,'amount':999}])[0]==403
            assert stored()==before,'Rejected cash write changed operational data'
    for key,icon,row in [('tt30petty','oldbags',receipt),('tt33pettyexp','labour',expense)]:
        seed({icon:['View','Create']});before=stored()
        assert write(key, originals[key]+[{**row,'ref':'Forged'}])[0]==403
        assert write(key, [row])[0]==403,'Linked writer removed old cash rows'
        assert write(key, [{**originals[key][0],'amount':100},row])[0]==403
        assert stored()==before
        assert write(key,originals[key]+[row])[0]==200,'Legitimate linked cash entry rejected'
        committed=stored()
        assert write(key, originals[key]+[row,{**row,'id':9}],base=2)[0]==403,'Duplicate derived cash entry accepted'
        assert stored()==committed
    seed({'petty':['View','Create']});before=stored()
    assert write('tt30petty',[{**originals['tt30petty'][0],'credit':999}])[0]==403
    assert stored()==before
    assert write('tt30petty',originals['tt30petty']+[{'id':9,'credit':25}])[0]==200
    seed({'petty':['View','Edit']})
    assert write('tt30petty',[{**originals['tt30petty'][0],'credit':25}])[0]==200
    for permission,role in [('all','Staff'),(['View','Create','Edit'],'Staff'),({},'Super Admin')]:
        seed(permission,role)
        assert write('tt33pettyexp',[{'id':9,'amount':50}])[0]==200,'Owner/legacy grant regressed'
    seed({'petty':['View','Create']});before=stored()
    assert write('tt30petty', originals['tt30petty'],csrf='bad')[0]==419
    assert write('tt30petty', originals['tt30petty'],base=0)[0]==409
    assert write('tt30petty', originals['tt30petty'],module='Exports')[0]==403
    assert stored()==before
    if MYSQL:
        subprocess.run(['php',str(sql_script),'cleanup'],env=env,check=True,capture_output=True)
    print('PASS '+('MySQL' if MYSQL else 'file')+' cash target permissions, unchanged rejected writes, linked sales/expenses, duplicates, exact actions, legacy/owner rights, CSRF and versions')
