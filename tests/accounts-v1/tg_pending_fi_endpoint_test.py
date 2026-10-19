"""Execute the pending-TG endpoint against temporary Accounts records and actual permission helpers."""
import json,subprocess,shutil,tempfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2];auth=(ROOT/'auth_store.php').read_text();a=auth.index('function tt_business_modules(');b=auth.index('function tt_user_can_access_masters(',a)
with tempfile.TemporaryDirectory(prefix='tg-pending-fi-') as directory:
 root=Path(directory);(root/'api').mkdir();(root/'data').mkdir();shutil.copy(ROOT/'api/export_tg_unallocated.php',root/'api/export_tg_unallocated.php')
 (root/'auth_store.php').write_text("<?php define('TT_DATA_DIR',__DIR__.'/data');function tt_release_read_session(){}function tt_require_login(){return json_decode(file_get_contents(__DIR__.'/user.json'),true);}"+auth[a:b])
 (root/'request.php').write_text("<?php $_SERVER['REQUEST_METHOD']=$argv[1];register_shutdown_function(function(){file_put_contents(__DIR__.'/status.json',json_encode(http_response_code()?:200));});require __DIR__.'/api/export_tg_unallocated.php';")
 tx={'id':'TG1','kind':'Payment','paymentType':'LIABILITY','referenceType':'UNALLOCATED','pakistanCandidateId':'PK1','amountNative':100,'currency':'USD','date':'2026-09-01','status':'Accounts Approved / Posted','bankReference':'BANK1','invoiceRef':'INV1'}
 receipt={'id':'R1','entity':'TTI','tgPaymentId':'TG1','foreignAmount':35,'correspondentForeignAmount':5,'transactionCurrency':'USD','date':'2026-09-02','bankAdviceRef':'AD1','allocations':[{'targetType':'UNAPPLIED_TG','foreignAmount':35}]}
 store={'exportCandidates':{'PK1':{'entity':'TTI'}},'tgBankTransactions':{'TG1':tx,'TG2':{**tx,'id':'TG2','status':'Reversed for Amendment'}},'exportReceipts':{'R1':receipt,'OLD':{**receipt,'id':'OLD','status':'Reversed for Amendment','foreignAmount':1000}}};path=root/'data/accounts.json';path.write_text(json.dumps(store))
 allowed={'role':'Exports','permissions':{'Exports':{'fi':['View']}}}
 def request(user=allowed,method='GET',expected=200):
  (root/'user.json').write_text(json.dumps(user));before=path.read_bytes();r=subprocess.run(['php',str(root/'request.php'),method],capture_output=True,text=True);assert r.returncode==0,r.stderr;d=json.loads(r.stdout);assert json.loads((root/'status.json').read_text())==expected,d;assert before==path.read_bytes();return d
 rows=request()['payments'];assert len(rows)==2,rows;assert next(r for r in rows if r['id']=='TG1')['amount']==60;assert next(r for r in rows if r['id']=='R1-1')['amount']==35;assert {r['pakistanExporter'] for r in rows}=={'TTI'}
 request({'role':'Exports','permissions':{'Exports':{'reports':['View']}}},expected=403);request(method='POST',expected=405)
 path.write_text(json.dumps({**store,'tgBankTransactions':'bad'}));request(expected=500)
 path.write_text(json.dumps({**store,'tgBankTransactions':{'TG1':{**tx,'amountNative':'bad'}}}));request(expected=500)
 path.write_text('{broken');request(expected=500)
 path.write_text(json.dumps({**store,'exportCandidates':{}}));request(expected=500)
 print('PASS TG pending endpoint: partial receipts/correspondent deductions, reversed source exclusion, source exporter, FI permission, corrupt-source error and unchanged books.')
