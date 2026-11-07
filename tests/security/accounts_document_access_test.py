"""Real authentication and canonical file ownership in disposable PHP requests."""
import copy, json, pathlib, shutil, subprocess, tempfile, urllib.parse
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='document-access-') as tmp:
 root=pathlib.Path(tmp);app=root/'app';app.mkdir();private=root/'transtrade_private';private.mkdir();(root/'sessions').mkdir()
 for source in ROOT.glob('*.php'):shutil.copy(source,app/source.name)
 shutil.copytree(ROOT/'api',app/'api');(app/'accounts').mkdir()
 for source in (ROOT/'accounts').glob('*.json'):shutil.copy(source,app/'accounts'/source.name)
 runner=root/'request.php';runner.write_text('''<?php
$r=json_decode($argv[2],true);ini_set('session.save_path',$argv[3]);session_name('TRANSTRADE_SESSION');session_id('document-fixture');session_start();$_SESSION=['user_id'=>1,'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];session_write_close();
$_SERVER['REQUEST_URI']=$r['uri'];$_SERVER['REQUEST_METHOD']=$r['method']??'GET';$_SERVER['REMOTE_ADDR']='192.0.2.7';parse_str(parse_url($r['uri'],PHP_URL_QUERY)??'',$_GET);$_POST=[];
if(isset($r['body'])){$GLOBALS['fixture_body']=json_encode($r['body']);class DocInput{public $context;private $offset=0;function stream_open($p,$m,$o,&$opened){return $p==='php://input';}function stream_read($n){$s=substr($GLOBALS['fixture_body'],$this->offset,$n);$this->offset+=strlen($s);return $s;}function stream_eof(){return $this->offset>=strlen($GLOBALS['fixture_body']);}function stream_stat(){return [];}}stream_wrapper_unregister('php');stream_wrapper_register('php',DocInput::class);}
register_shutdown_function(function(){echo "\\nHTTP_STATUS=".(http_response_code()?:200);});require $argv[1];
''')
 user={'id':1,'username':'fixture','full_name':'Fixture','role':'Staff','active':True,'session_version':1,'master_access':True,'master_permissions':{},'permissions':{'Accounts':{'purchases':['View'],'entity-tti':['View']},'Directors':{'tg':['View']}}}
 doc='SENSITIVE-DOCUMENT-CONTENT';(private/'bag-bill-uploads').mkdir();(private/'accounts-receipt-uploads').mkdir();(private/'tg_documents').mkdir()
 for folder,name in [('bag-bill-uploads','bagbill-tti.pdf'),('bag-bill-uploads','bagbill-brm.pdf'),('bag-bill-uploads','unreferenced.pdf'),('accounts-receipt-uploads','receipt-legacy.pdf'),('accounts-receipt-uploads','receipt-BRM-test.pdf'),('tg_documents','tg.pdf')]: (private/folder/name).write_text(doc)
 initial={'revision':3,'journals':{},'bagSupplierBills':{'B1':{'id':'B1','entity':'TTI','attachmentToken':'bagbill-tti.pdf','historical':True,'status':'Cancelled'},'B2':{'entity':'BRM','attachmentToken':'bagbill-brm.pdf'}},'exportReceipts':{'R1':{'id':'R1','entity':'TTI','bankAdviceFileRef':'receipt-legacy.pdf','status':'Reversed for Amendment','date':'2026-10-01'},'R2':{'entity':'BRM','bankAdviceFileRef':'receipt-BRM-test.pdf'}}}
 books=private/'accounts.json';books.write_text(json.dumps(initial));(private/'operations.json').write_text(json.dumps({'values':{},'meta':{}}))
 masters={'tg_currency_rates':[{'id':'rate','values':['Rate','TG-USD-AED-CURRENT','USD/AED','3.67','3.675','3.67','2026-01-01','','12-31','AED','Active','']}], 'tg_compliance_documents':[{'id':'D1','values':['Passport','SENSITIVE-HOLDER','NUMBER','2026-01-01','2026-10-20','Active','tg.pdf','passport.pdf','application/pdf','sensitive notes','2026-01-01','Fixture','']}]}
 def seed(): (private/'auth.json').write_text(json.dumps({'users':[user],'masters':masters,'audit':[]}))
 def get(endpoint,body=None,**query):
  uri='/api/'+endpoint+'.php?'+urllib.parse.urlencode(query)
  r=subprocess.run(['php',str(runner),str(app/'api'/(endpoint+'.php')),json.dumps({'uri':uri,**({'method':'POST','body':body} if body is not None else {})}),str(root/'sessions')],text=True,capture_output=True)
  assert r.returncode==0,r.stdout+r.stderr
  body,status=r.stdout.rsplit('\nHTTP_STATUS=',1)
  try:payload=json.loads(body)
  except json.JSONDecodeError:payload=body
  return int(status),payload
 def denied(endpoint,**query):
  status,body=get(endpoint,**query);assert status in [403,404,422],(endpoint,status,body);assert doc not in str(body)
 seed();before=books.read_bytes()
 assert get('bag_bill_file',file='bagbill-tti.pdf')==(200,doc)
 denied('bag_bill_file',file='bagbill-brm.pdf',entity='TTI');denied('bag_bill_file',file='unreferenced.pdf');denied('bag_bill_file',file='../bagbill-tti.pdf')
 user['permissions']['Accounts']={'expenses':['View'],'entity-tti':['View']};seed()
 denied('bag_bill_file',file='bagbill-tti.pdf');denied('accounts_receipt_file',entity='TTI',file='receipt-legacy.pdf');denied('export_receipts',entity='TTI')
 for icon in ['customer','cashbank']:
  user['permissions']['Accounts']={icon:['View'],'entity-tti':['View']};seed()
  assert get('accounts_receipt_file',entity='TTI',file='receipt-legacy.pdf')==(200,doc)
  denied('accounts_receipt_file',entity='TTI',file='receipt-BRM-test.pdf')
  assert get('export_receipts',entity='TTI')[0]==200
 assert books.read_bytes()==before,'Read authorization must not alter financial records'
 # Same-company amendments may reuse a file; conflicting-company ownership is quarantined.
 data=copy.deepcopy(initial);data['bagSupplierBills']['COPY']={**data['bagSupplierBills']['B1'],'id':'COPY'};books.write_text(json.dumps(data));user['permissions']['Accounts']={'purchases':['View'],'entity-tti':['View']};seed();assert get('bag_bill_file',file='bagbill-tti.pdf')[0]==200
 data['bagSupplierBills']['COPY']['entity']='BRM';books.write_text(json.dumps(data));denied('bag_bill_file',file='bagbill-tti.pdf');books.write_text(json.dumps(initial))
 user['permissions']['Accounts']['entity-tg']=['View']
 for grant in [[],['View'],['Download Documents']]:
  user['master_permissions']={'tg_compliance_documents':grant};seed();code,payload=get('tg_currency_master',entity='TG')
  assert code==200 and payload['documents']==[] and 'SENSITIVE-HOLDER' not in json.dumps(payload),(grant,code,payload)
  denied('tg_currency_master',entity='TG',download='D1')
  code,payload=get('masters');assert code==200 and 'SENSITIVE-HOLDER' not in json.dumps(payload),'Generic Master route leaked TG document metadata'
 user['permissions']['Accounts']['entity-tg']=['View','Edit'];user['permissions']['Directors']={'tg':['View','Edit']};user['master_permissions']={'tg_compliance_documents':['Deactivate']};seed()
 code,payload=get('tg_currency_master',entity='TG',body={'action':'archive_document','id':'D1','csrf':'fixture'});assert code==200 and payload['documents']==[] and 'SENSITIVE-HOLDER' not in json.dumps(payload),(code,payload)
 user['permissions']['Accounts']['entity-tg']=['View'];user['master_permissions']={'tg_compliance_documents':['View Documents']};seed()
 assert get('tg_currency_master',entity='TG')[1]['documents'][0]['holder']=='SENSITIVE-HOLDER'
 assert get('tg_currency_master',entity='TG',download='D1')==(200,doc)
 for role in ['Director','Super Admin']:
  user['role']=role;user['permissions']={};user['master_permissions']={};seed()
  assert get('bag_bill_file',file='bagbill-brm.pdf')==(200,doc)
  assert get('accounts_receipt_file',entity='BRM',file='receipt-BRM-test.pdf')==(200,doc)
  assert get('tg_currency_master',entity='TG',download='D1')==(200,doc)
 user['role']='Staff';user['permissions']={'Directors':{'tg':['View']}};seed();denied('tg_currency_master',download='D1')
 print('PASS canonical bill/receipt ownership, exact icon/entity access, amendment aliases, TG document matrix and derived-role/demotion controls; no live data')
