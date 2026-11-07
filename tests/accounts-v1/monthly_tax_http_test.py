"""Real authenticated staff HTTP: private documents, explicit uploads and read-only accounting."""
import json,os,pathlib,shutil,socket,subprocess,tempfile,time,urllib.request,urllib.error,http.cookiejar
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='monthly-tax-') as temp:
 root=pathlib.Path(temp);app=root/'app';shutil.copytree(ROOT,app,ignore=shutil.ignore_patterns('.git','node_modules','__pycache__'));private=root/'transtrade_private';private.mkdir()
 user={'id':1,'username':'fixture','full_name':'Fixture Accountant','role':'Staff','active':True,'session_version':1,'permissions':{'Accounts':{'entity-tti':['View','Edit'],'reports':['View'],'purchases':['View','Edit'],'expenses':['View','Edit']}}}
 auth=private/'auth.json';auth.write_text(json.dumps({'users':[user],'masters':{},'settings':{'qa_account_seeded':True,'master_permission_schema':2},'audit':[]}))
 books=private/'accounts.json';books.write_text(json.dumps({'revision':1,'journals':{},'bagSupplierBills':{'B1':{'entity':'TTI','status':'Posted','salesTaxInvoice':True,'sellerInvoice':'FIXTURE-1','sellerInvoiceDate':'2026-10-01','bagType':'PP','baseAmount':100,'gstAmount':18,'totalAmount':118,'supplier':'Fixture Supplier'}},'utilityMasters':{'U1':{'entity':'TTI','utilityType':'ELECTRICITY','location':'MILL','payee':'K-Electric','status':'Active'}}}))
 (private/'operations.json').write_text(json.dumps({'values':{'transtrade_export_v3_operational':json.dumps({'contracts':[{'ref':'C1','seller':'TTI'}],'shipments':[{'id':'LOT1','contractRef':'C1','seller':'TTI','commercial':{'saved':True,'status':'Final','invoiceNo':'INV1'}}]})}}))
 (app/'fixture-session.php').write_text("<?php require __DIR__.'/auth_store.php';$_SESSION=['user_id'=>1,'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];echo 'ready';")
 env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
 with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
 server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(app)],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));base=f'http://127.0.0.1:{port}'
 def req(path,fields=None,file=None):
  data=None;headers={}
  if fields is not None:
   boundary='fixture-boundary';parts=[]
   for k,v in fields.items():parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode())
   if file:parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="invoice.pdf"\r\nContent-Type: application/pdf\r\n\r\n'.encode()+file+b'\r\n')
   data=b''.join(parts)+f'--{boundary}--\r\n'.encode();headers['Content-Type']='multipart/form-data; boundary='+boundary
  try:
   with opener.open(urllib.request.Request(base+path,data=data,headers=headers),timeout=10) as r:return r.status,r.read(),r.headers
  except urllib.error.HTTPError as r:return r.code,r.read(),r.headers
 try:
  for _ in range(60):
   try:req('/fixture-session.php');break
   except urllib.error.URLError:time.sleep(.1)
  original=books.read_bytes();url='/api/monthly_tax.php?entity=TTI&month=2026-10';status,body,_=req(url);d=json.loads(body);assert status==200,(status,d);assert len(d['bags'])==1 and d['bags'][0]['file'] is None;csrf=d['csrf']
  assert req('/api/monthly_tax.php?entity=BRM&month=2026-10')[0]==403
  assert req('/api/monthly_tax.php?entity=TTI&month=2026-13')[0]==422
  pdf=b'%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF'
  fields={'entity':'TTI','month':'2026-10','csrf':csrf,'source':'bag:B1'}
  assert req(url,{**fields,'csrf':'bad'},pdf)[0]==419
  assert req(url,{**fields,'source':'bag:OTHER'},pdf)[0]==422
  status,body,_=req(url,fields,pdf);assert status==200,(status,body)
  d=json.loads(req(url)[1]);f=d['bags'][0]['file'];assert f and req(f['url'])[1]==pdf
  assert req(f['url'].replace('entity=TTI','entity=BRM'))[0]==403
  assert req(f['url'].replace('month=2026-10','month=2026-09'))[0]==404
  assert req(url,{**fields,'source':'utility:U1'},pdf)[0]==200,'Unpaid K-Electric bill attaches to its own billing month/account'
  for asset in ['app.js','app.css','pdf-lib','TTI_header.png']:
   assert req('/api/monthly_tax_assets.php?entity=TTI&asset='+asset)[0]==200
  assert req('/api/monthly_tax_assets.php?entity=TTI&asset=../../auth.json')[0]==404
  assert req('/accounts/sales-tax-invoice.php?entity=TTI&shipment=LOT1')[0]==200
  assert req('/accounts/sales-tax-invoice.php?entity=BRM&shipment=LOT1')[0]==403
  assert req('/accounts/sales-tax-invoice.php?entity=TTI&shipment=LOT1&version=stale')[0]==422
  assert req(url,{'entity':'TTI','month':'2026-10','csrf':csrf,'action':'status','stage':'Return filed','date':'2026-11-01'})[0]==200
  assert json.loads(req(url)[1])['status']['date']=='2026-11-01'
  user['permissions']['Accounts']['purchases']=['View'];auth.write_text(json.dumps({'users':[user],'masters':{},'settings':{'qa_account_seeded':True,'master_permission_schema':2},'audit':[]}))
  assert req(url,fields,pdf)[0]==403
  user['permissions']['Accounts']['reports']=[];auth.write_text(json.dumps({'users':[user],'masters':{},'settings':{'qa_account_seeded':True,'master_permission_schema':2},'audit':[]}));assert req(url)[0]==403
  assert books.read_bytes()==original,'Document assembly/uploads/statuses never post or rewrite financial or stock data'
  print('PASS actual staff monthly filing: report/company scope, missing uploads, private file isolation, original bill links, unpaid K-Electric upload, status dates, asset allowlist, denied edits and unchanged accounting')
 finally:server.terminate();server.wait(timeout=10)
