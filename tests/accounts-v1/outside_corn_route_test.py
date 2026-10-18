"""Native outside-Corn route through real authenticated APIs in disposable storage."""
import json, os, pathlib, shutil, subprocess, tempfile
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tti-outside-corn-') as directory:
 root=pathlib.Path(directory);app=root/'app';shutil.copytree(ROOT,app,ignore=shutil.ignore_patterns('.git','node_modules','__pycache__'))
 private=root/'transtrade_private';private.mkdir();books=private/'accounts.json';ops=private/'operations.json'
 env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
 seed=root/'seed.php';seed.write_text(r'''<?php
 require $argv[1].'/auth_store.php';$masters=tt_default_masters();
 $masters['purchase_products'][]=['id'=>'fixture-corn','values'=>['CORN','Yellow Corn','','RAW','KG','CORN','','','Active']];
 $masters['business_parties'][]=['id'=>'fixture-supplier','values'=>['Corn supplier fixture','','Supplier','','','','','','','','Active']];
 $masters['mills'][]=['id'=>'fixture-mill','values'=>['Outside Corn Fixture','OCF','External Mill','','','Active','']];
 tt_ensure_data_dir();file_put_contents(TT_STORE_FILE,json_encode(['users'=>[['id'=>1,'username'=>'fixture','full_name'=>'Fixture','role'=>'Super Admin','active'=>true,'session_version'=>1]],'masters'=>$masters,'settings'=>['qa_account_seeded'=>true],'audit'=>[]]));
 ''');subprocess.run(['php',str(seed),str(app)],check=True,env=env,capture_output=True)
 harness=root/'request.php';harness.write_text(r'''<?php
 $_SERVER['REQUEST_URI']='/api/'.$argv[4];$_SERVER['REQUEST_METHOD']=$argv[5];$_SERVER['REMOTE_ADDR']='192.0.2.45';
 session_name('TRANSTRADE_SESSION');session_id('outside-corn-'.bin2hex(random_bytes(12)));session_start();$_SESSION=['user_id'=>1,'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];session_write_close();
 $GLOBALS['fixtureBody']=$argv[3];$GLOBALS['statusFile']=$argv[2];
 class CornInput{public $context;private $offset=0;function stream_open($p,$m,$o,&$opened){return $p==='php://input';}function stream_read($n){$v=substr($GLOBALS['fixtureBody'],$this->offset,$n);$this->offset+=strlen($v);return $v;}function stream_eof(){return $this->offset>=strlen($GLOBALS['fixtureBody']);}function stream_stat(){return [];}}
 stream_wrapper_unregister('php');stream_wrapper_register('php',CornInput::class);register_shutdown_function(static function(){file_put_contents($GLOBALS['statusFile'],(string)(http_response_code()?:200));});require $argv[1];
 ''')
 def request(api,body=None):
  status=root/'status.txt';body={} if body is None else {'csrf':'fixture',**body};method='GET' if not body or body=={'csrf':'fixture'} else 'POST'
  out=subprocess.run(['php','-d',f'session.save_path={root}',str(harness),str(app/'api'/api),str(status),json.dumps(body),api,method],env=env,text=True,capture_output=True)
  assert out.returncode==0,out.stderr+out.stdout
  return int(status.read_text()),json.loads(out.stdout)
 create={'action':'create','requestKey':'outside-corn-request-00001','entity':'TTI','purchaseProductId':'fixture-corn','sodaDate':'2026-10-18','supplierId':'fixture-supplier','broker':'','readyRoute':'EX_MILL','locationId':'fixture-mill','qtyFromMT':'50','qtyToMT':'50','rate':4000,'rateUnit':'MAUND','paymentTermType':'CASH','arrivalDueDate':'2026-10-24'}
 status,data=request('purchase_sodas.php',create);assert status==200,(status,data)
 soda=data['created'];sid=soda['id'];assert soda['commodity']=='CORN' and soda['productStage']=='RAW' and soda['readyRoute']=='EX_MILL' and soda['ratePerKg']==100 and soda['katProfile']=='CORN',soda
 assert request('purchase_sodas.php',create)[1]['duplicate']
 status,feed=request('milling_purchase_sodas.php');assert status==200
 saved=next(x for x in feed['sodas'] if x['id']==sid);assert saved['readyRoute']=='EX_MILL' and saved['commodity']=='CORN' and saved['productStage']=='RAW' and saved['katProfile']=='CORN',saved
 contract={'ref':'CORN-C','seller':'TTI','commodity':'MAIZE','variety':'Yellow Corn','product':'Yellow Corn','packings':[{'type':'Loose bulk in containers','looseBulk':True}]}
 shipment={'id':'CORN-S','kind':'lot','contractRef':'CORN-C','lotId':'LOT-1','status':'Open'}
 allocation={'type':'Ex-Mill','name':'Outside Corn Fixture','packIndex':0,'containers':2,'weightPer':25}
 export={'contracts':[contract],'shipments':[shipment],'millSync':{'exportLoading':[{'shipmentId':'CORN-S','contractRef':'CORN-C','lotId':'LOT-1','plan':{'allocations':[allocation]}}]}}
 def save_export(value):ops.write_text(json.dumps({'revision':0,'values':{'transtrade_export_v3_operational':json.dumps(value)}}))
 save_export(export)
 lifting={'action':'sync_lifting','sourceSodaId':sid,'liftingId':'CN-MSCU1234567','entity':'TTI','date':'2026-10-18','container':'MSCU1234567','truck':'KHI-123','seal':'SEAL1','kg':24970,'bags':0,'qualityStatus':'OK','shipmentId':'CORN-S','contractRef':'CORN-C','lotRef':'LOT-1','instructionKey':'CORN-S|CORN-C|LOT-1|0','weightBasis':'PACKED_CARGO','weighbridgeKg':25000,'silicaKg':10,'kraftKg':15,'bagTareKg':5,'containerTareKg':0}
 for patch in [{'instructionKey':'invented'},{'shipmentId':'other'},{'contractRef':'other'},{'kg':25000},{'bags':5},{'weightBasis':''},{'qualityStatus':'HOLD'},{'seal':''}]:
  before=books.read_bytes();status,data=request('milling_purchase_sodas.php',{**lifting,**patch});assert status==422 and books.read_bytes()==before,(patch,status,data)
 foreign=json.loads(json.dumps(export));foreign['contracts'][0]['seller']='BRM';save_export(foreign);before=books.read_bytes();status,data=request('milling_purchase_sodas.php',lifting);assert status==422 and books.read_bytes()==before,(status,data);save_export(export)
 # Packed Corn uses the same native path but cannot omit its actual bags.
 packed_export=json.loads(json.dumps(export));packed_export['contracts'][0]['packings'][0]={'type':'50 kg bags','looseBulk':False};save_export(packed_export)
 packed_before=books.read_bytes();status,data=request('milling_purchase_sodas.php',lifting);assert status==422 and books.read_bytes()==packed_before,(status,data)
 status,packed_post=request('milling_purchase_sodas.php',{**lifting,'bags':500});assert status==200 and packed_post['lifting']['bags']==500 and not packed_post['lifting']['looseBulk'],(status,packed_post)
 books.write_bytes(packed_before);save_export(export)
 status,posted=request('milling_purchase_sodas.php',lifting);assert status==200,(status,posted)
 assert posted['lifting']['kg']==24970 and posted['lifting']['bags']==0
 assert posted['journal']['totalDebit']==2497000 and posted['journal']['meta']['commodity']=='CORN' and posted['journal']['meta']['weighbridgeWeightKg']==25000,posted
 first_journal=posted['journal'];before=books.read_bytes();status,replay=request('milling_purchase_sodas.php',lifting);assert status==200 and replay['replayed'] and books.read_bytes()==before,(status,replay)
 status,alias=request('milling_purchase_sodas.php',{**lifting,'sourceSodaId':soda['sodaNo']});assert status==200 and alias['replayed'] and books.read_bytes()==before,(status,alias)
 changed={**lifting,'kg':24960,'weighbridgeKg':24990};status,data=request('milling_purchase_sodas.php',changed);assert status==409 and books.read_bytes()==before,(status,data)
 # Explicit Corn correction uses the same issued allocation and retains the original journal.
 corn_before=books.read_bytes();corn_correction={**lifting,'kg':24960,'weighbridgeKg':24990,'expectedJournalId':first_journal['id'],'correctionReason':'Correct the original Corn weighbridge reading'}
 status,preview=request('milling_purchase_sodas.php',{**corn_correction,'preview':True});assert status==200 and len(preview['previews'])==2 and books.read_bytes()==corn_before,(status,preview)
 status,corn_fixed=request('milling_purchase_sodas.php',corn_correction);assert status==200 and corn_fixed['journal']['totalDebit']==2496000,(status,corn_fixed)
 assert json.loads(books.read_text())['journals'][first_journal['id']]==first_journal
 corrected_bytes=books.read_bytes();status,replay=request('milling_purchase_sodas.php',corn_correction);assert status==200 and replay['replayed'] and books.read_bytes()==corrected_bytes,(status,replay)
 books.write_bytes(corn_before)
 second={**lifting,'liftingId':'CN-TGHU6543210','container':'TGHU6543210','truck':'KHI-456'};status,posted2=request('milling_purchase_sodas.php',second);assert status==200,(status,posted2)
 third={**lifting,'liftingId':'CN-CMAU5555555','container':'CMAU5555555'};before=books.read_bytes();status,data=request('milling_purchase_sodas.php',third);assert status==422 and books.read_bytes()==before,(status,data)
 # The real shared-save endpoint accepts complete loose bulk and rejects inconsistent weights.
 instruction={'id':91,'_ttBridge':'exports','_ttBridgeId':lifting['instructionKey'],'entity':'TTI','commodity':'CORN','productStage':'RAW','looseBulk':True,'packingType':'Loose bulk in containers','mill':'Outside Corn Fixture','contractRef':'CORN-C','lotRef':'LOT-1','shipmentId':'CORN-S','totalContainers':2,'sourceSodaId':sid,'localSodaId':71,'allocationStatus':'LINKED'}
 physical=[{**entry['lifting'],'id':i,'sodaId':71,'instructionId':91,'serverPosted':True,'issue':'OK'} for i,entry in enumerate([posted,posted2],1)]
 operations={'revision':0,'values':{'transtrade_export_v3_operational':json.dumps(export)}}
 ops.write_text(json.dumps(operations))
 issued={k:v for k,v in instruction.items() if k not in ['localSodaId','allocationStatus']};issued['sourceSodaId']=''
 status,data=request('operations.mysql.php',{'sourceModule':'Exports','key':'tt40exinstructions','baseVersion':0,'value':json.dumps([issued])});assert status==200,(status,data)
 status,data=request('operations.mysql.php',{'sourceModule':'Mill','key':'tt40exinstructions','baseVersion':1,'value':json.dumps([instruction])});assert status==200,(status,data)
 before_ops=ops.read_bytes()
 bad=json.loads(json.dumps(physical));bad[0]['netKg']+=5
 update={'sourceModule':'Mill','key':'tt35exload','baseVersion':0,'value':json.dumps(bad)}
 status,data=request('operations.mysql.php',update);assert status==422 and ops.read_bytes()==before_ops,(status,data)
 update['value']=json.dumps(physical);status,data=request('operations.mysql.php',update);assert status==200,(status,data)
 complete={**instruction,'loadingComplete':True};status,data=request('operations.mysql.php',{'sourceModule':'Mill','key':'tt40exinstructions','baseVersion':2,'value':json.dumps([complete])});assert status==200,(status,data)
 before_ops=ops.read_bytes();packed={**complete,'looseBulk':False,'packingType':'50 kg bags'}
 status,data=request('operations.mysql.php',{'sourceModule':'Mill','key':'tt40exinstructions','baseVersion':3,'value':json.dumps([packed])});assert status==422 and ops.read_bytes()==before_ops,(status,data)
 # Final bill combines only these source containers and cannot replace their saved net weights.
 body={'action':'verify_bill','entity':'TTI','sourceKeys':[posted['lifting']['sourceKey'],posted2['lifting']['sourceKey']],'billDate':'2026-10-18','billNo':'OUTSIDE-CORN','relationshipType':'SUPPLIER','relationshipName':'Corn supplier fixture','finalCommodityValue':1,'brokerageGross':0,'brokerageWithholding':0,'inspection':{'karachiWeightKg':50000,'moisturePct':14,'damageFungusPct':2}}
 status,data=request('commodity_bills.php',body);assert status==422 and books.read_bytes()==before,(status,data)
 body['inspection']['karachiWeightKg']=49940
 kat_before=books.read_bytes();kat_body={**body,'billNo':'OUTSIDE-CORN-KAT','inspection':{**body['inspection'],'moisturePct':16}}
 status,kat_bill=request('commodity_bills.php',kat_body);assert status==200 and kat_bill['bill']['finalCommodityValue']==4894120,(status,kat_bill)
 books.write_bytes(kat_before)
 body['inspection']['karachiWeightKg']=49940;status,bill=request('commodity_bills.php',body);assert status==200,(status,bill)
 assert bill['bill']['commodity']=='CORN' and bill['bill']['finalCommodityValue']==4994000 and bill['journal']['totalDebit']==bill['journal']['totalCredit'],bill
 stored=json.loads(books.read_text());assert stored['journals'][first_journal['id']]==first_journal,'Original loading journal rewritten on billing'
 before=books.read_bytes();status,data=request('commodity_bills.php',body);assert status==409 and books.read_bytes()==before,(status,data)
 print('PASS native RAW Corn Ex-Mill Soda and feed; issued-instruction, entity, weight and packing checks; two zero-bag containers; immutable retry/correction; quantity/container cap; source-backed combined bill; original journals preserved')
