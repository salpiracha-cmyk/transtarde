"""Exercise real report GET and CSV with disposable dated operational receipts."""
import csv,io,json,pathlib,shutil,socket,subprocess,tempfile,time,urllib.request,urllib.error
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='bag-report-') as tmp:
 root=pathlib.Path(tmp);(root/'api').mkdir();(root/'data').mkdir()
 shutil.copy(ROOT/'document_filenames.php',root/'document_filenames.php')
 for name in ['bag_order_report.php','bag_order_report_core.php','accounts_shipment_source.php','bag_receipt_quantities.php','bag_transfer_core.php']:shutil.copy(ROOT/'api'/name,root/'api'/name)
 (root/'auth_store.php').write_text("""<?php
define('TT_DATA_DIR',__DIR__.'/data');function tt_require_login(){return [];}function tt_user_can_open_module($u,$m){return $m==='Accounts';}function tt_user_can_access_entity($u,$e,$a){return $e==='TTI';}function tt_user_can_module_action($u,$m,$i,$a){return empty($_SERVER['HTTP_X_DENY']);}function tt_release_read_session(){}
""")
 books={'journals':{'kept':{'amount':321}},'bagPurchaseOrders':{'PO':{'entity':'TTI','poNo':'PO','issuedAt':'2026-10-10','supplier':'=Unsafe Formula','contractRef':'CONTRACT','lines':[{'lineKey':'L1','brand':'XYZ','orderedQty':1000,'receivedQty':450,'bagType':'PP','packingSize':'25 KG'}]}}}
 operations={'values':{'transtrade_export_v3_operational':json.dumps({'shipments':[{'seller':'TTI','bagOrders':[{'poNo':'PO','supplier':'=Unsafe Formula','issuedAt':'2026-10-10','versions':[{'snapshot':{'issuedAt':'2026-09-10'}}]}]}]}),'tt30bags':json.dumps([{'id':1,'poNo':'PO','_ttBridge':'exports','_ttBridgeId':'x|1','received':450}]),'tt33bagreceipts':json.dumps([{'id':1,'bagId':1,'date':'2026-09-12','qty':400,'bagReceiptVersion':1,'acceptedQty':250,'quarantinedQty':150,'sourceSnapshot':{'entity':'TTI','locationId':'TTI'}},{'id':2,'bagId':1,'date':'2026-10-12','qty':50}])}}
 operations['values']['tt41bagtransfers']=json.dumps({'receiptDispositions':[{'id':'D1','receiptId':1,'date':'2026-09-20','qty':100,'action':'return_supplier','entity':'TTI','locationId':'TTI'}]})
 book=root/'data/accounts.json';op=root/'data/operations.json';book.write_text(json.dumps(books));op.write_text(json.dumps(operations));before=[book.read_bytes(),op.read_bytes()]
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 log=open(root/'server.log','w');server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root)],stdout=log,stderr=log)
 def req(query='',method='GET',deny=False):
  url=f'http://127.0.0.1:{port}/api/bag_order_report.php?entity=TTI&from=2026-09-01&to=2026-09-30'+query
  try:
   with urllib.request.urlopen(urllib.request.Request(url,method=method,headers={'X-Deny':'1'} if deny else {})) as r:return r.status,r.read().decode('utf-8-sig'),r.headers
  except urllib.error.HTTPError as e:return e.code,e.read().decode(),e.headers
 try:
  for _ in range(60):
   try:status,text,_=req();break
   except urllib.error.URLError:time.sleep(.05)
  assert status==200,text;r=json.loads(text);assert r['totals']['orderedInPeriod']==1000 and r['totals']['receivedInPeriod']==400 and r['totals']['receivedThroughEnd']==400 and r['totals']['receivedCurrent']==350 and r['totals']['returnedInPeriod']==100 and r['totals']['netReceivedThroughEnd']==300 and r['totals']['quarantinedCurrent']==50
  status,text,headers=req('&brand=XYZ&format=csv');assert status==200 and 'text/csv' in headers['Content-Type'] and "'=Unsafe Formula" in text and '10-09-2026' in text and 'Filtered totals' in text and 'Supplier returns in period' in text and 'Net received current' in text
  csv_rows=list(csv.reader(io.StringIO(text)));header=next(i for i,row in enumerate(csv_rows) if row and row[0]=='Order date');csv_row=dict(zip(csv_rows[header],csv_rows[header+1]));assert csv_row['Gross received in period']=='400' and csv_row['Supplier returns in period']=='100' and csv_row['Net dated receipts through end']=='300' and csv_row['Net received current']=='350' and csv_row['Quarantined current']=='50'
  assert req('&brand=Other')[0]==200 and json.loads(req('&brand=Other')[1])['rows']==[]
  assert req('&entity=BRM')[0]==403 and req(deny=True)[0]==403 and req(method='POST')[0]==405
  for query in ['&from=2026-02-30','&from=2026-10-01','&basis=unknown']:assert req(query)[0]==422
  assert [book.read_bytes(),op.read_bytes()]==before,'Read/CSV must not persist anything'
  broken=json.loads(op.read_text());dispositions=json.loads(broken['values']['tt41bagtransfers']);dispositions['receiptDispositions'][0]['qty']=999;broken['values']['tt41bagtransfers']=json.dumps(dispositions);op.write_text(json.dumps(broken));invalid_before=[book.read_bytes(),op.read_bytes()];assert req()[0]==500 and [book.read_bytes(),op.read_bytes()]==invalid_before,'Invalid return history must refuse without writes'
  op.write_text('{invalid');assert req()[0]==500,'Malformed source must not become an empty successful report'
  print('PASS bag report HTTP: dated sources, filtered CSV, formula-safe text, access/method/date checks, no writes and explicit source failures')
 finally:server.terminate();server.wait();log.close()
