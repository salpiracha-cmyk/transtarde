"""KAT links must match the actual product for every purchase commodity."""
import json, os, pathlib, shutil, subprocess, tempfile

ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tti-master-kat-match-') as directory:
    root=pathlib.Path(directory);app=root/'app'
    shutil.copytree(ROOT,app,ignore=shutil.ignore_patterns('.git','node_modules','__pycache__','tmp'))
    private=root/'transtrade_private';private.mkdir();books=private/'accounts.json'
    user={'id':1,'username':'fixture','full_name':'Fixture','role':'Super Admin','active':True,'session_version':1}
    (private/'auth.json').write_text(json.dumps({'users':[user],'masters':{},'audit':[]}))
    runner=root/'request.php'
    runner.write_text(r'''<?php
$_SERVER['REQUEST_URI']='/api/masters.php';$_SERVER['REQUEST_METHOD']='POST';$_SERVER['REMOTE_ADDR']='192.0.2.44';
session_name('TRANSTRADE_SESSION');session_id('unit-fixture-'.bin2hex(random_bytes(12)));session_start();$_SESSION=['user_id'=>1,'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];session_write_close();
$GLOBALS['fixtureBody']=$argv[3];$GLOBALS['statusFile']=$argv[2];
class UnitFixtureInput{public $context;private $offset=0;function stream_open($p,$m,$o,&$opened){return $p==='php://input';}function stream_read($n){$v=substr($GLOBALS['fixtureBody'],$this->offset,$n);$this->offset+=strlen($v);return $v;}function stream_eof(){return $this->offset>=strlen($GLOBALS['fixtureBody']);}function stream_stat(){return [];}}
stream_wrapper_unregister('php');stream_wrapper_register('php',UnitFixtureInput::class);
register_shutdown_function(static function(){file_put_contents($GLOBALS['statusFile'],(string)(http_response_code()?:200));});require $argv[1];
''')
    env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
    profiles = [
        {'id': 'kat-rice', 'values': ['RICE','Fixture Grain','White','RAW','Rice rules','','','Active','','[]']},
        {'id': 'kat-corn', 'values': ['CORN','Fixture Grain','','RAW','Corn rules','','','Active','','[]']},
        {'id': 'kat-sesame', 'values': ['SESAME','Fixture Grain','','RAW','Sesame rules','','','Active','','[]']},
        {'id': 'kat-other', 'values': ['CORN','Different Grain','','RAW','Other Corn rules','','','Active','','[]']},
        {'id': 'kat-ready', 'values': ['CORN','Fixture Grain','','READY','Ready Corn rules','','','Active','','[]']},
    ]
    def request(commodity, kat, stage='RAW'):
        values=[commodity,'Fixture Grain','White' if commodity=='RICE' else '',stage,'KG',kat,'','','Active','Fixture only','']
        initial={'users':[user],'masters':{'purchase_products':[{'id':'fixture-product','values':values}], 'purchase_kat':profiles},'audit':[]}
        (private/'auth.json').write_text(json.dumps(initial))
        original_books={'revision':1,'journals':{'original':{'id':'original','status':'Posted','totalDebit':100,'totalCredit':100,'lines':[]}}}
        books.write_text(json.dumps(original_books));before=books.read_bytes()
        payload={'csrf':'fixture','action':'update','type':'purchase_products','id':'fixture-product','values':values}
        status_file=root/'status.txt'
        result=subprocess.run(['php','-d',f'session.save_path={root}',str(runner),str(app/'api/masters.php'),str(status_file),json.dumps(payload)],env=env,text=True,capture_output=True)
        assert result.returncode==0, result.stderr+result.stdout
        assert books.read_bytes()==before, 'Master review changed existing Accounts books'
        status=int(status_file.read_text())
        if status==422:
            saved=json.loads((private/'auth.json').read_text())
            product=next(x for x in saved['masters']['purchase_products'] if x['id']=='fixture-product')
            assert product['values']==values, 'Refused KAT change rewrote the original master'
        return status,json.loads(result.stdout)
    for commodity, kat in [('CORN','kat-rice'),('SESAME','kat-rice'),('CORN','kat-sesame'),('CORN','kat-ready'),('CORN','kat-other'),('RICE','kat-corn'),('CORN','missing')]:
        status,data=request(commodity,kat)
        assert status==422 and not data['ok'],(commodity,kat,status,data.get('error'))
    for commodity, kat in [('RICE','kat-rice'),('CORN','kat-corn'),('SESAME','kat-sesame')]:
        status,data=request(commodity,kat)
        assert status==200 and data['ok'],(commodity,kat,status,data.get('error'))
        saved=next(x for x in data['masters']['purchase_products'] if x['id']=='fixture-product')
        assert saved['values'][5]==kat and saved['values'][0]==commodity
    print('PASS authenticated native Master endpoint: Rice/Corn/Sesame exact KAT links accepted; cross-commodity, wrong-stage and missing profiles refused; Accounts originals unchanged')
