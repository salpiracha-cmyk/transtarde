"""Exercise the real approval endpoints in isolated PHP processes, without a web server."""
import json, os, pathlib, shutil, subprocess, tempfile, time
ROOT = pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix="approval-integrity-") as tmp:
    root = pathlib.Path(tmp)
    (root/"api").mkdir(); (root/"data").mkdir(); (root/"accounts").mkdir()
    for name in ("bag_purchases.php", "nonwoven_bag_bills.php"):
        shutil.copy(ROOT/"api"/name, root/"api"/name)
    shutil.copy(ROOT/"accounts/accounting_master_v1.json", root/"accounts/accounting_master_v1.json")
    (root/"auth_store.php").write_text("""<?php
define('TT_DATA_DIR',__DIR__.'/data');
function tt_ensure_data_dir(){}
function tt_require_login(){return ['username'=>getenv('QA_ACTOR'),'full_name'=>getenv('QA_ACTOR'),'role'=>getenv('QA_ROLE')?:'Super Admin','permissions'=>['Accounts'=>'all']];}
function tt_user_can_open_module($u,$m){return true;}
function tt_verify_csrf($t){return $t==='fixture';}
function tt_accounts_input(){return getenv('QA_PAYLOAD');}
function tt_active_business_party_for_role($n,$r){return ['id'=>'supplier','values'=>[$n]];}
function tt_next_post_id(array $existing,string $module='Accounts',string $area='Journal',?string $date=null):string{
 if(getenv('QA_CONCURRENT')){
  touch(__DIR__.'/data/ready-'.getenv('QA_ACTOR'));
  $deadline=microtime(true)+1;
  while(count(glob(__DIR__.'/data/ready-*'))<2&&microtime(true)<$deadline)usleep(1000);
 }
 $n=count($existing)+1;
 do{$id='POST-2026-'.str_pad((string)$n,5,'0',STR_PAD_LEFT);$n++;}while(isset($existing[$id]));
 return $id;
}
""")
    (root/"runner.php").write_text("""<?php
$_SERVER['REQUEST_METHOD']='POST';
register_shutdown_function(function(){file_put_contents(getenv('QA_STATUS'),(string)(http_response_code()?:200));});
require __DIR__.'/api/'.getenv('QA_ENDPOINT').'.php';
""")
    data_file=root/"data/accounts.json"
    def bill(identifier):
        return {'id':identifier,'entity':'TTI','poNo':'PO1','lineKey':'L1','ratePerBag':12,
                'status':'Hold - Rate Approval Required','bags':5,'supplier':'Fixture Supplier',
                'sellerInvoice':identifier,'sellerInvoiceDate':'2026-10-01','baseAmount':60,
                'totalAmount':60,'gstAmount':0,'gstRate':0,'packingSize':'50kg',
                'bagType':'Non Woven','brand':'Fixture','rateExceptionApprovedAt':None}
    def initial():
        return {'revision':0,'journals':{},'bagPurchaseOrders':{'PO1':{'entity':'TTI','supplier':'Fixture Supplier','lines':[
            {'lineKey':'L1','ratePerBag':10,'orderedQty':100,'receivedQty':100,'bagType':'Non Woven','packingSize':'50kg','brand':'Fixture'}]}},
            'bagSupplierBills':{'B1':bill('B1')},
            'nonWovenBagSupplierBills':{'N1':bill('N1'),'N2':bill('N2')}}
    def launch(endpoint, identifier, actor, concurrent=False, role='Super Admin', csrf='fixture', body=None):
        status=root/(actor+'-'+identifier+'.status')
        payload=body or {'action':'approve_rate_exception','entity':'TTI','billId':identifier,
                 'csrf':csrf,'reason':'Reason from '+actor}
        env={**os.environ,'QA_ENDPOINT':endpoint,'QA_PAYLOAD':json.dumps(payload),
             'QA_ACTOR':actor,'QA_ROLE':role,'QA_STATUS':str(status),
             'QA_CONCURRENT':'1' if concurrent else ''}
        return subprocess.Popen(['php',str(root/'runner.php')],env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE),status
    def finish(job):
        process,status=job
        stdout,stderr=process.communicate(timeout=10)
        assert process.returncode==0, stderr.decode()
        return int(status.read_text()),json.loads(stdout)
    failures=[]
    for endpoint, collection, identifier in [('bag_purchases','bagSupplierBills','B1'),('nonwoven_bag_bills','nonWovenBagSupplierBills','N1')]:
        data_file.write_text(json.dumps(initial()))
        assert finish(launch(endpoint,identifier,'Unauthorized',role='Staff'))[0]==403
        assert finish(launch(endpoint,identifier,'BadCsrf',csrf='bad'))[0]==419
        assert finish(launch(endpoint,identifier,'FirstDirector',role='Director'))[0]==200
        first=json.loads(data_file.read_text())
        assert finish(launch(endpoint,identifier,'LaterOwner'))[0]==200
        after=json.loads(data_file.read_text())
        try:
            assert after[collection][identifier]==first[collection][identifier], 'Repeated approval replaced the original decision'
            assert after['journals']==first['journals'], 'Repeated approval changed posted journals'
            print('PASS '+endpoint+' preserves the first approver and posting on replay')
        except AssertionError as error:
            failures.append(endpoint+': '+str(error))
    data_file.write_text(json.dumps(initial()))
    jobs=[launch('nonwoven_bag_bills','N1','ConcurrentDirector',True,role='Director'),
          launch('nonwoven_bag_bills','N2','ConcurrentOwner',True)]
    assert all(finish(job)[0]==200 for job in jobs)
    after=json.loads(data_file.read_text())
    try:
        assert len(after['journals'])==2, 'Concurrent approvals lost a journal'
        assert all(after['nonWovenBagSupplierBills'][k]['status']=='Posted' for k in ('N1','N2')), 'Concurrent approval lost a bill decision'
        assert after['revision']==2, 'Concurrent commits lost a revision'
        assert {j['meta']['bagBillId'] for j in after['journals'].values()}=={'N1','N2'}
        print('PASS concurrent Director and Super Admin approvals retain both bills and journals')
    except AssertionError as error:
        failures.append('nonwoven concurrency: '+str(error))

    # A Non-Woven save must also serialize with ordinary bag bills in the same store.
    for marker in (root/"data").glob("ready-*"):
        marker.unlink()
    data_file.write_text(json.dumps(initial()))
    jobs=[launch('bag_purchases','B1','BagDirector',True,role='Director'),
          launch('nonwoven_bag_bills','N1','NonwovenOwner',True)]
    assert all(finish(job)[0]==200 for job in jobs)
    after=json.loads(data_file.read_text())
    assert len(after['journals'])==2, 'Concurrent bag and Non-Woven saves lost a journal'
    assert after['bagSupplierBills']['B1']['status']=='Posted'
    assert after['nonWovenBagSupplierBills']['N1']['status']=='Posted'
    assert after['revision']==2
    print('PASS bag and Non-Woven endpoints share the Accounts write lock')
    # An invalid store or held bill cannot replace existing financial records.
    for damaged in ('{broken', 'null'):
        data_file.write_text(damaged)
        assert finish(launch('nonwoven_bag_bills','N1','CorruptStore'))[0]==500
        assert data_file.read_text()==damaged
    data=initial()
    data['bagPurchaseOrders']['PO1']['lines'][0]['receivedQty']=0
    data_file.write_text(json.dumps(data))
    before=data_file.read_text()
    assert finish(launch('nonwoven_bag_bills','N1','NotReceived'))[0]==409
    assert data_file.read_text()==before
    data_file.write_text(json.dumps(initial()))
    assert finish(launch('nonwoven_bag_bills','N1','AfterFailure'))[0]==200
    print('PASS damaged storage and held quantities leave data intact; next save succeeds')


    for endpoint in ('bag_purchases', 'nonwoven_bag_bills'):
        data_file.write_text(json.dumps(initial()))
        before=data_file.read_text()
        body={'action':'create_bill','entity':'TTI','poNo':'PO1','lineKey':'L1',
              'supplier':'Fixture Supplier','sellerInvoice':'OVERFLOW',
              'sellerInvoiceDate':'2026-10-01','bags':5,'ratePerBag':1e308,'csrf':'fixture'}
        response=finish(launch(endpoint,'Overflow','OverflowActor',body=body))
        assert response[0]==500, (endpoint,response)
        assert data_file.read_text()==before, endpoint+' truncated the store before rejecting an unencodable amount'
    print('PASS serialization failures preserve the existing Accounts store')

    assert not failures, '\n'.join(failures)
