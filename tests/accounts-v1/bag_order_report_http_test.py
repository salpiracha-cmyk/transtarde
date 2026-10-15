"""Exercise real report GET and CSV with disposable dated operational receipts."""
import json,pathlib,shutil,socket,subprocess,tempfile,time,urllib.request,urllib.error
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='bag-report-') as tmp:
 root=pathlib.Path(tmp);(root/'api').mkdir();(root/'data').mkdir()
 for name in ['bag_order_report.php','bag_order_report_core.php','accounts_shipment_source.php']:shutil.copy(ROOT/'api'/name,root/'api'/name)
 (root/'auth_store.php').write_text("""<?php
define('TT_DATA_DIR',__DIR__.'/data');function tt_require_login(){return [];}function tt_user_can_open_module($u,$m){return $m==='Accounts';}function tt_user_can_access_entity($u,$e,$a){return $e==='TTI';}function tt_user_can_module_action($u,$m,$i,$a){return empty($_SERVER['HTTP_X_DENY']);}function tt_release_read_session(){}
""")
 books={'journals':{'kept':{'amount':321}},'bagPurchaseOrders':{'PO':{'entity':'TTI','poNo':'PO','issuedAt':'2026-10-10','supplier':'=Unsafe Formula','contractRef':'CONTRACT','lines':[{'lineKey':'L1','brand':'XYZ','orderedQty':1000,'receivedQty':450,'bagType':'PP','packingSize':'25 KG'}]}}}
 operations={'values':{'transtrade_export_v3_operational':json.dumps({'shipments':[{'seller':'TTI','bagOrders':[{'poNo':'PO','supplier':'=Unsafe Formula','issuedAt':'2026-10-10','versions':[{'snapshot':{'issuedAt':'2026-09-10'}}]}]}]}),'tt30bags':json.dumps([{'id':1,'poNo':'PO','_ttBridge':'exports','_ttBridgeId':'x|1','received':450}]),'tt33bagreceipts':json.dumps([{'id':1,'bagId':1,'date':'2026-09-12','qty':400},{'id':2,'bagId':1,'date':'2026-10-12','qty':50}])}}
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
  assert status==200,text;r=json.loads(text);assert r['totals']['orderedInPeriod']==1000 and r['totals']['receivedInPeriod']==400 and r['totals']['receivedThroughEnd']==400 and r['totals']['receivedCurrent']==450
  status,text,headers=req('&brand=XYZ&format=csv');assert status==200 and 'text/csv' in headers['Content-Type'] and "'=Unsafe Formula" in text and '10-09-2026' in text and 'Filtered totals' in text
  assert req('&brand=Other')[0]==200 and json.loads(req('&brand=Other')[1])['rows']==[]
  assert req('&entity=BRM')[0]==403 and req(deny=True)[0]==403 and req(method='POST')[0]==405
  for query in ['&from=2026-02-30','&from=2026-10-01','&basis=unknown']:assert req(query)[0]==422
  assert [book.read_bytes(),op.read_bytes()]==before,'Read/CSV must not persist anything'
  op.write_text('{invalid');assert req()[0]==500,'Malformed source must not become an empty successful report'
  print('PASS bag report HTTP: dated sources, filtered CSV, formula-safe text, access/method/date checks, no writes and explicit source failures')
 finally:server.terminate();server.wait();log.close()
