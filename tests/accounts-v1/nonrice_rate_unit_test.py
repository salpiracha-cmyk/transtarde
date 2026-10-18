"""Real authenticated bill posting must honor the saved Soda price unit."""
import json, os, pathlib, shutil, subprocess, tempfile

ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tti-nonrice-price-unit-') as directory:
    root=pathlib.Path(directory);app=root/'app'
    shutil.copytree(ROOT,app,ignore=shutil.ignore_patterns('.git','node_modules','__pycache__'))
    private=root/'transtrade_private';private.mkdir();books=private/'accounts.json'
    user={'id':1,'username':'fixture','full_name':'Fixture','role':'Super Admin','active':True,'session_version':1}
    (private/'auth.json').write_text(json.dumps({'users':[user],'masters':{},'audit':[]}))
    runner=root/'request.php'
    runner.write_text(r'''<?php
$_SERVER['REQUEST_URI']='/api/commodity_bills.php';$_SERVER['REQUEST_METHOD']='POST';$_SERVER['REMOTE_ADDR']='192.0.2.44';
session_name('TRANSTRADE_SESSION');session_id('unit-fixture-'.bin2hex(random_bytes(12)));session_start();$_SESSION=['user_id'=>1,'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];session_write_close();
$GLOBALS['fixtureBody']=$argv[3];$GLOBALS['statusFile']=$argv[2];
class UnitFixtureInput{public $context;private $offset=0;function stream_open($p,$m,$o,&$opened){return $p==='php://input';}function stream_read($n){$v=substr($GLOBALS['fixtureBody'],$this->offset,$n);$this->offset+=strlen($v);return $v;}function stream_eof(){return $this->offset>=strlen($GLOBALS['fixtureBody']);}function stream_stat(){return [];}}
stream_wrapper_unregister('php');stream_wrapper_register('php',UnitFixtureInput::class);
register_shutdown_function(static function(){file_put_contents($GLOBALS['statusFile'],(string)(http_response_code()?:200));});require $argv[1];
''')
    env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
    def request(commodity,unit,rate,moisture=14):
        soda={'id':'S1','entity':'TTI','sodaNo':'UNIT1','commodity':commodity,'productStage':'RAW','rate':rate,'ratePerKg':rate if unit=='KG' else rate/40,'paymentTermType':'CASH','party':'Fixture supplier','broker':''}
        if unit is not None:soda['rateUnit']=unit
        source='POHANCH|UNIT1';eid='TTI|COMMODITY_RECEIPT_ACCEPTED|'+source
        meta={'soda':'UNIT1','commodity':commodity,'party':'Fixture supplier','broker':'','payableWeightKg':1000,'weighbridgeWeightKg':1000,'bags':20,'productStage':'RAW'}
        store={'revision':7,'purchaseSodas':{'S1':soda},'journals':{'J1':{'id':'J1','entity':'TTI','date':'2026-10-01','totalDebit':100000,'totalCredit':100000,'status':'Posted','meta':meta,'lines':[]}},'events':{eid:{'id':eid,'eventType':'COMMODITY_RECEIPT_ACCEPTED','entity':'TTI','sourceKey':source,'journalId':'J1','status':'Accepted'}}}
        books.write_text(json.dumps(store));before=books.read_bytes()
        body={'csrf':'fixture','action':'verify_bill','entity':'TTI','sourceKeys':[source],'billDate':'2026-10-18','billNo':'UNIT-FIXTURE','relationshipType':'SUPPLIER','relationshipName':'Fixture supplier','finalCommodityValue':1,'brokerageGross':0,'brokerageWithholding':0,'inspection':{'karachiWeightKg':1000,'moisturePct':moisture,'damageFungusPct':2,'admixturePct':1}}
        status_file=root/'status.txt';out=subprocess.run(['php','-d',f'session.save_path={root}',str(runner),str(app/'api/commodity_bills.php'),str(status_file),json.dumps(body)],env=env,text=True,capture_output=True)
        assert out.returncode==0,out.stderr+out.stdout
        return int(status_file.read_text()),json.loads(out.stdout),before,books.read_bytes()
    for commodity in ['CORN','SESAME']:
        for unit,rate in [('KG',100),('MAUND',4000),(None,4000)]:
            status,data,_,after=request(commodity,unit,rate)
            assert status==200,(commodity,unit,status,data)
            assert data['bill']['finalCommodityValue']==100000,(commodity,unit,'wrong approved price-unit value',data['bill']['finalCommodityValue'])
            assert data['bill']['supplierPayableTotal']==100000,(commodity,unit,'wrong payable')
            saved=json.loads(after);assert saved['journals']['J1']['totalDebit']==100000,'Original provisional source was changed'
    status,data,_,_=request('CORN','KG',100,16)
    assert status==200 and data['bill']['calculation']['netPayableKg']==980 and data['bill']['finalCommodityValue']==98000,('KAT or unit application',status,data)
    status,data,before,after=request('CORN','TON',100)
    assert status==422 and before==after,('Unknown unit must fail without a posting',status,data)
    print('PASS actual non-Rice bill endpoint: KG and MAUND agree for equivalent prices, legacy MAUND retained, Corn KAT applies before price, original provisional sources preserved and invalid units refuse without writes')
