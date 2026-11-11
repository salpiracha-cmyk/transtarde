"""Real receipt projection endpoint with isolated books; never connects to production."""
import json, os, pathlib, shutil, subprocess, tempfile
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tti-receipt-scope-') as tmp:
 root=pathlib.Path(tmp);(root/'api').mkdir();(root/'data').mkdir()
 shutil.copy(ROOT/'api/tg_export_accounts_receipts.php',root/'api')
 (root/'auth_store.php').write_text("""<?php
 define('TT_DATA_DIR',__DIR__.'/data');foreach(['HOST','NAME','USER','PASS'] as $n)define('TT_DB_'.$n,'');
 function tt_require_login(){return ['role'=>'Staff'];}
 function tt_user_business_entity($u,$e){return $e==='TG';}
 function tt_user_any_icon($u,$m,$i){return true;}
 """)
 (root/'request.php').write_text("""<?php
 parse_str($argv[1],$_GET);$_SERVER['REQUEST_METHOD']='GET';
 register_shutdown_function(function(){file_put_contents(__DIR__.'/status',(string)(http_response_code()?:200));});
 require __DIR__.'/api/tg_export_accounts_receipts.php';
 """)
 contracts=[{'ref':ref,'seller':seller} for ref,seller in [('TG/A','TG'),('TG/B','TG'),('TTI/PRIVATE','TTI')]]
 shipments=[{'id':'LOT-A','contractRef':'TG/A','commercial':{'invoiceNo':'INV-A'}}]
 ops={'values':{'transtrade_export_v3_operational':json.dumps({'contracts':contracts,'shipments':shipments})}}
 books={'tgBankTransactions':{}}
 for id,ref,kind,invoice,status in [('ADV','TG/A','CUSTOMER_ADVANCE','','Posted'),('INV','TG/A','EXPORT_RECEIVABLE','INV-A','Posted'),('B','TG/B','CUSTOMER_ADVANCE','','Posted'),('PRIVATE','TTI/PRIVATE','CUSTOMER_ADVANCE','','Posted'),('OLD','TG/A','CUSTOMER_ADVANCE','','Reversed for Amendment')]:
  books['tgBankTransactions'][id]={'id':id,'contractRef':ref,'kind':'Receipt','receiptType':kind,'invoiceRef':invoice,'status':status,'settlementAmountNative':123.45,'currency':'USD','date':'2026-11-11'}
 opfile=root/'data/operations.json';bookfile=root/'data/accounts.json';opfile.write_text(json.dumps(ops));bookfile.write_text(json.dumps(books));original=(opfile.read_bytes(),bookfile.read_bytes())
 def get(query=''):
  p=subprocess.run(['php',str(root/'request.php'),query],capture_output=True,text=True,check=True);return int((root/'status').read_text()),json.loads(p.stdout)
 status,legacy=get();assert status==200 and {r['id'] for r in legacy['receipts']}=={'ADV','INV','B'}
 status,a=get('contractRefs[]=TG%2FA');assert status==200 and {r['id'] for r in a['receipts']}=={'ADV','INV'};assert next(r for r in a['receipts'] if r['id']=='INV')['lotId']=='LOT-A';assert all(r['amount']==123.45 for r in a['receipts'])
 assert get('contractRefs[]=TG%2FB')[1]['receipts'][0]['id']=='B'
 assert get('contractRefs[]=TTI%2FPRIVATE')[0]==403
 assert get('contractRefs[]=MISSING')[0]==403
 assert get('contractRefs=TG%2FA')[0]==422
 assert len(get('contractRefs[]=TG%2FA&contractRefs[]=TG%2FB')[1]['receipts'])==3
 assert original==(opfile.read_bytes(),bookfile.read_bytes()),'Receipt reads must not write books or shipment data'
 books['tgBankTransactions']['ADV']['settlementAmountNative']=222;bookfile.write_text(json.dumps(books));assert next(r for r in get('contractRefs[]=TG%2FA')[1]['receipts'] if r['id']=='ADV')['amount']==222,'Reopening reads fresh saved receipts'
 print('PASS real receipt endpoint: contract scope, advance/invoice linking, original currency, reversals, permissions, freshness and no writes')
