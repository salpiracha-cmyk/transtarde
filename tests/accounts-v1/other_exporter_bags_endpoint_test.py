"""Exercise the actual controller with disposable books; never touch live stock."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='other-exporter-bags-') as tmp:
    root = Path(tmp)
    shutil.copytree(ROOT/'api', root/'api')
    (root/'data').mkdir()
    for f in ['inventory_reconciliation.php','product_stage.php']: shutil.copy(ROOT/f,root/f)
    (root/'auth_store.php').write_text('''<?php
define('TT_DATA_DIR',__DIR__.'/data');
function tt_require_login(){return ['id'=>7,'full_name'=>'Mill receiver','role'=>'Staff','location'=>($_GET['mode']??'')==='location'?'Other Mill':'Own Mill','permissions'=>($_GET['mode']??'')==='company'?['Accounts'=>['entity-brm'=>['View','Create']]]:[]];}
function tt_user_can_open_module($u,$m){return ($_GET['mode']??'')!=='no-mill';}
function tt_user_can_module_action($u,$m,$i,$a){return $m==='Mill'&&(($_GET['mode']??'')!=='read'||$a==='View');}
function tt_user_business_entity($u,$e,$a){return tt_user_can_access_entity($u,$e,$a);}
function tt_user_can_access_entity($u,$e,$a){return ($_GET['mode']??'')!=='company'||$e==='BRM';}
function tt_verify_csrf($v){return $v==='fixture';}
function tt_release_read_session(){if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')throw new RuntimeException('Only read requests may release the session here.');}
function tt_accounts_input(){return $GLOBALS['fixtureBody'];}
function tt_list_masters(){return ['mills'=>[['id'=>'M1','values'=>['Own Mill','','Own Mill','','','Active']]]];}
''')
    (root/'runner.php').write_text('''<?php
$r=json_decode(stream_get_contents(STDIN),true);$_GET=$r['query'];$_SERVER['REQUEST_METHOD']=$r['body']===null?'GET':'POST';$GLOBALS['fixtureBody']=json_encode($r['body']);register_shutdown_function(function(){fwrite(STDERR,'STATUS:'.(http_response_code()?:200));});require __DIR__.'/api/other_exporter_bags.php';
''')
    books = {'revision':1,'journals':[{'immutable':'original money'}], 'exporterDeals':{'HK':{'id':'HK','entity':'TTI','direction':'SALE','status':'Open','partyId':'HKM','party':'HKM','date':'2026-10-01','brand':'','packing':'50 kg PP bags','product':'White rice 5% broken','quantityMT':75,'productionStockName':'Planned rice output','productionNotes':'Fixture production instruction','version':1,'productionReady':True,'productionMillName':'Own Mill','productionBagTareGrams':80,'price':987654,'payments':[],'history':[]}}}
    book_path=root/'data/accounts.json'
    book_path.write_text(json.dumps(books))
    ops_path=root/'data/operations.json'
    ops_path.write_text(json.dumps({'revision':1,'values':{'tt30bags':json.dumps([{'id':999,'entity':'TTI','mill':'Another Mill','brand':'Private marking','received':0}]),'tt33bagreceipts':'[]','tt30prod':'[]','tt36bagissues':'[]','tt35exportersale':'[]','transtrade_export_v3_operational':'{}'},'meta':{}}))
    env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
    def req(body=None, mode=''):
        query={'entity':'TTI','locationId':'M1','mode':mode}
        out=subprocess.run(['php', str(root/'runner.php')],input=json.dumps({'query':query,'body':body}),text=True,capture_output=True,env=env,check=True)
        assert 'STATUS:' in out.stderr,out.stderr
        return int(out.stderr.rsplit('STATUS:',1)[1]),json.loads(out.stdout)
    def snapshot():return (book_path.read_bytes(),ops_path.read_bytes())
    before=snapshot();status,data=req();assert status==200 and len(data['deals'])==1 and 'price' not in data['deals'][0],data
    assert not data['choices'] and data['deals'][0]['product']=='White rice 5% broken' and data['deals'][0]['productionStockName']=='Planned rice output','Unreceived instructed SODA disappeared with its bag choices'
    assert not set(data['deals'][0]).intersection({'price','payments','history','settlements','bankId','currency'}),'Mill planning feed exposed commercial data'
    assert snapshot()==before,'Read created stock or edited books'
    base={'csrf':'fixture','entity':'TTI','locationId':'M1','sodaId':'HK','version':1}
    order=data['productionOrders'][0];assert order['sourceId']=='HK' and order['entity']=='TTI' and not order['brand']
    registered=dict(action='register_production_brand',purpose='production',csrf='fixture',entity='TTI',locationId='M1',orderId=order['orderId'],version=order['version'],brand='Actual marking',requestKey='register-actual-brand-0001')
    for mode in ['no-mill','location','company','read']:
        assert req(registered,mode)[0]==403 and snapshot()==before,'Brand registration bypassed production/location/company Create rights'
    assert req(dict(registered,csrf='bad'))[0]==419 and snapshot()==before
    assert req(dict(registered,orderId='FELLOW|missing'))[0]==422 and snapshot()==before
    status,brand_saved=req(registered);assert status==200 and brand_saved['brand']['brand']=='Actual marking',brand_saved
    branded=snapshot();assert branded[0]==before[0],'Brand registration changed Accounts books or commercial terms'
    assert req(registered)[1]['replayed'] and snapshot()==branded,'Lost brand response created a duplicate registration'
    visible=req()[1]['productionOrders'];assert any(r['brand']=='Actual marking' and r['sourceId']=='HK' for r in visible)
    assert req(dict(registered,requestKey='register-same-brand-0002'))[0]==200 and snapshot()==branded,'Same marking duplicated its source'
    before=branded
    brand=dict(base,action='fill_brand',brand='Amros',requestKey='actual-missing-brand-0001')
    for mode in ['no-mill','location','company','read']:
        status,error=req(brand,mode);assert status==403,error;assert snapshot()==before
    assert req(dict(brand,csrf='bad'))[0]==419 and snapshot()==before
    status,saved=req(brand);assert status==200 and saved['deal']['brand']=='Amros' and saved['deal']['version']==2,saved
    after=snapshot();assert req(brand)[1]['replayed'] and snapshot()==after,'Brand retry duplicated history'
    assert 'price' not in req(brand)[1]['deal'],'Brand replay disclosed financial terms'
    assert req(dict(brand,brand='Other'))[0]==422 and snapshot()==after
    assert req(dict(brand,version=2,brand='Other',requestKey='overwrite-saved-brand-0002'))[0]==422 and snapshot()==after
    current=json.loads(book_path.read_text());assert current['journals']==books['journals'] and current['exporterDeals']['HK']['price']==987654
    receipt=dict(base,version=2,action='receive',requestKey='actual-other-bag-receipt-0001',date='2026-11-03',qty=100,packingKg=50,bagType='PP Woven',inspected=True)
    for changes in [{'qty':0},{'packingKg':25},{'date':'2026-09-01'},{'version':1},{'inspected':False},{'entity':'BRM'},{'sodaId':'wrong'}]:
        status,error=req(dict(receipt,**changes));assert status==422,error;assert snapshot()==after,'Refused receipt changed records'
    status,saved=req(receipt);assert status==200,saved;assert saved['order']['received']==100 and saved['receipt']['sourceSnapshot']['externalOwnerId']=='HKM'
    assert saved['sourceValues']['tt30bags']['version']==1
    assert all(r.get('brand')!='Private marking' for r in json.loads(saved['sourceValues']['tt30bags']['value'])), 'Receipt acknowledgement exposed another assigned mill'
    assert all('visibleSources' not in r for r in [saved]), 'Unfiltered stock source escaped projection'
    receipt_snapshot=snapshot();assert req(receipt)[1]['replayed'] and snapshot()==receipt_snapshot,'Retry duplicated received bags'
    assert req(dict(receipt,qty=101))[0]==422 and snapshot()==receipt_snapshot
    status,data=req();assert status==200 and data['choices'][0]['brand']=='Amros' and data['choices'][0]['available']==100,data
    assert snapshot()==receipt_snapshot,'Production choices changed stock'
    assert book_path.read_bytes()==after[0],'Receiving bags changed financial books or SODA'
    status,saved=req(dict(receipt,requestKey='actual-other-bag-receipt-0002',qty=25));assert status==200 and saved['order']['received']==125,saved
    new_receipt=dict(receipt,recordActual=True,brand='Actual Emrooz Marking',packingKg=40,actualTareGrams=115,qty=15,requestKey='actual-40kg-bag-receipt-0003')
    status,saved=req(new_receipt);assert status==200,saved
    assert saved['receipt']['acceptedQty']==15 and saved['receipt']['quarantinedQty']==0 and saved['receipt']['actualTareGrams']==115
    actual=snapshot();assert req(new_receipt)[1]['replayed'] and snapshot()==actual
    status,data=req();assert status==200 and any(c['brand']=='Actual Emrooz Marking' and c['packingKg']==40 and c['available']==15 for c in data['choices']),data
    assert book_path.read_bytes()==after[0]
    current=json.loads(book_path.read_text());current['exporterDeals']['HK']['status']='Closed';book_path.write_text(json.dumps(current));closed=snapshot()
    status,data=req();assert status==200 and not data['deals'] and not data['choices'] and data['diagnostics'][0]['remaining']==125,data
    assert req(dict(receipt,requestKey='closed-other-bag-receipt-0003'))[0]==422 and snapshot()==closed
    print('PASS actual other exporter bag controller: permissions, CSRF, read privacy, blank-only audited SODA brand save, replay, physical receipts, canonical source versions, unchanged journals, closed-stock history and production choices')
