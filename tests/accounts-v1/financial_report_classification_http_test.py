"""Actual reporting endpoint: income aliases and signed rounding affect P&L/equity, never journals."""
import json,pathlib,shutil,socket,subprocess,tempfile,time,urllib.request,urllib.error,fcntl,concurrent.futures
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='report-class-') as tmp:
 root=pathlib.Path(tmp);shutil.copytree(ROOT/'api',root/'api');shutil.copytree(ROOT/'accounts',root/'accounts');(root/'data').mkdir()
 (root/'auth_store.php').write_text("""<?php
define('TT_DATA_DIR',__DIR__.'/data');function tt_require_login(){return ['role'=>'Super Admin','permissions'=>['Accounts'=>'all']];}function tt_user_can_open_module($u,$m){return $m==='Accounts';}function tt_user_can_access_entity($u,$e,$a){return in_array($e,['TTI','TG'],true);}function tt_user_can_module_action($u,$m,$i,$a){return true;}function tt_release_read_session(){}function tt_list_masters(){return [];}function tt_master_options(){return ['currencies'=>['AED','USD']];}function tt_company_fx_rate($e,$from,$to){return $from==='USD'&&$to==='AED'?3.67:null;}
""")
 def line(account,dr=0,cr=0):return {'account':account,'debit':dr,'credit':cr}
 def journal(id,lines,date='2026-09-12'):return {'id':id,'entity':'TTI','status':'Posted','date':date,'sourceType':'FIXTURE','lines':lines,'totalDebit':sum(x['debit'] for x in lines),'totalCredit':sum(x['credit'] for x in lines)}
 books={'revision':7,'journals':{j['id']:j for j in [journal('OPEN',[line('1110',1000000),line('3100',cr=1000000)]),journal('GAIN',[line('1110',296258.91),line('4450',cr=296258.91)]),journal('ROUND',[line('7195',.09),line('1120',cr=.09)]),journal('FX',[line('1110',10),line('7100',cr=10)])]}}
 path=root/'data/accounts.json';path.write_text(json.dumps(books));before=path.read_bytes()
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 log=open(root/'server.log','w');server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root)],stdout=log,stderr=log)
 def req(from_date='2026-09-01',entity='TTI',currency='AED'):
  try:
   with urllib.request.urlopen(f'http://127.0.0.1:{port}/api/accounts_reports.php?entity={entity}'+(f'&currency={currency}' if currency is not None else '')+f'&from={from_date}&asOf=2026-10-15') as r:return r.status,json.load(r)
  except urllib.error.HTTPError as e:return e.code,json.load(e)
 try:
  for _ in range(60):
   try:status,d=req();break
   except urllib.error.URLError:time.sleep(.05)
  assert status==200,d;assert d['revision']==7 and d['trialBalance']['balanced']
  pl=d['profitAndLoss'];assert next(r for r in pl['income'] if str(r['account'])=='4450')['amount']==296258.91,pl
  assert next(r for r in pl['expenses'] if str(r['account'])=='7195')['amount']==.09
  assert next(r for r in pl['expenses'] if str(r['account'])=='7100')['amount']==-10
  assert abs(pl['profit']-296268.82)<.005 and abs(d['balanceSheet']['totalAssets']-d['balanceSheet']['totalLiabilitiesAndEquity'])<.005,d
  assert d['dataQuality']['unclassifiedBalances']==[]
  status,later=req('2026-10-01');assert status==200 and later['profitAndLoss']['profit']==0 and later['balanceSheet']==d['balanceSheet'],'Period profit must not replace cumulative balance-sheet earnings'
  assert d['generalLedger'] and later['generalLedger']==[],'Period GL and commodity activity must exclude prior-period postings without changing cumulative balances'
  assert path.read_bytes()==before,'Financial report must never write a balancing entry'
  # Opening allocation is not a negative expense. Only original payouts/refunds count.
  base=json.loads(json.dumps(books))
  opening=journal('Z-OPEN',[line('3400',852747),line('7210',cr=852747)],'2026-07-01');opening['sourceType']='OPENING_BALANCE_BF'
  payment=journal('Z-PAID',[{**line('7210',100000),'counterparty':'Recipient A'},{**line('7210',3350),'counterparty':'Recipient B'},line('1120',cr=103350)])
  refund=journal('Z-REFUND',[line('1120',100),line('7210',cr=100)])
  unpaid=journal('Z-UNPAID',[line('7210',50),line('2140',cr=50)])
  foreign=journal('Z-FOREIGN',[line('7210',999999),line('1120',cr=999999)]);foreign['entity']='BRM'
  advance=journal('STAFF-ADVANCE',[line('1230',100),line('1110',cr=100)]);advance['sourceType']='SALARY_ADVANCE'
  books['journals'].update({j['id']:j for j in [opening,payment,refund,unpaid,foreign,advance]});path.write_text(json.dumps(books));zbefore=path.read_bytes()
  status,z=req('2026-07-01');assert status==200,z
  assert next(x for x in z['profitAndLoss']['expenses'] if str(x['account'])=='7210')['amount']==103250
  assert abs(z['profitAndLoss']['profit']-(296268.82-103250))<.005,z
  assert not z['balanceSheet']['classificationComplete'] and z['balanceSheet']['warnings']
  assert z['zakatReporting']['paidExpense']==103250 and z['zakatReporting']['controlBookNet']==-749447
  assert {x['journalId'] for x in z['expenseActivity'] if str(x['account'])=='7210'}=={'Z-PAID','Z-REFUND'}
  assert sum(x['amount'] for x in z['expenseActivity'] if str(x['account'])=='7210')==103250
  assert [(x['recipient'],x['amount']) for x in z['expenseActivity'] if x['journalId']=='Z-PAID']==[('Recipient A',100000),('Recipient B',3350)],'Paid expense must preserve separate original recipients and purpose lines'
  assert path.read_bytes()==zbefore,'Paid-basis presentation must never reclassify or duplicate journals'
  mixed=journal('Z-MIXED',[line('7210',100),line('1120',cr=50),line('2140',cr=50)])
  books['journals']['Z-MIXED']=mixed;path.write_text(json.dumps(books));status,z=req();assert z['profitAndLoss']['profit'] is None and z['zakatReporting']['paidExpense'] is None and 'Z-MIXED' in ' '.join(z['profitAndLoss']['warnings'])
  bad=journal('Z-BAD',[line('7210',100),line('1120',cr=90)]);books['journals']['Z-BAD']=bad;path.write_text(json.dumps(books));assert 'Z-BAD' in ' '.join(req()[1]['profitAndLoss']['warnings'])
  # A later cancellation/refund remains in its actual reporting period even
  # when the Post ID register hides the complete pair.
  paid=journal('Z-PERIOD-PAID',[line('7210',5),line('1120',cr=5)],'2026-09-01')
  returned=journal('Z-PERIOD-RETURN',[line('1120',5),line('7210',cr=5)],'2026-10-01');returned['reversalOf']='Z-PERIOD-PAID'
  path.write_text(json.dumps({'revision':7,'journals':{x['id']:x for x in [paid,returned]}}));status,z=req('2026-10-01');assert status==200 and z['profitAndLoss']['profit']==5,z
  assert [(r['journalId'],r['credit']) for r in z['generalLedger'] if str(r['account'])=='7210']==[('Z-PERIOD-RETURN',5)],'Period GL must show the reversal of an earlier-period original'
  assert [(r['journalId'],r['amount']) for r in z['expenseActivity']]==[('Z-PERIOD-RETURN',-5)],'Register hiding must not erase a dated Zakat refund'
  status,z=req('2026-09-01');assert z['profitAndLoss']['profit']==0 and sum(r['amount'] for r in z['expenseActivity'])==0
  books=base;path.write_text(json.dumps(books))
  # Payroll control remains a named liability after entitlement and partial payment.
  books['journals']['PAYROLL']=journal('PAYROLL',[line('6210',1000),line('2230',cr=1000)])
  books['journals']['PAYROLL-PART']=journal('PAYROLL-PART',[line('2230',250),line('1110',cr=250)])
  path.write_text(json.dumps(books));payroll_before=path.read_bytes();status,payroll=req()
  assert status==200 and payroll['trialBalance']['balanced']
  control=next(r for r in payroll['trialBalance']['rows'] if str(r['account'])=='2230')
  assert control['class']=='Liability' and control['credit']==750 and 'Payables' in control['name'],control
  assert payroll['dataQuality']['unclassifiedBalances']==[]
  assert abs(payroll['balanceSheet']['totalAssets']-payroll['balanceSheet']['totalLiabilitiesAndEquity'])<.005
  assert path.read_bytes()==payroll_before,'Payroll reporting does not rewrite entitlement or payments'
  # Unrecognised accounts remain visible as a concrete review, never hidden by a balancing figure.
  books['journals']['UNKNOWN']=journal('UNKNOWN',[line('1110',5),line('9998',cr=5)]);path.write_text(json.dumps(books));unknown_before=path.read_bytes();status,unknown=req();assert status==200 and unknown['dataQuality']['unclassifiedBalances']==[{'account':'9998','name':'9998','class':'Other','net':-5}]
  assert path.read_bytes()==unknown_before
  # A concurrent writer must finish before the report reads the file.
  with path.open('rb') as locked,concurrent.futures.ThreadPoolExecutor() as executor:
   fcntl.flock(locked,fcntl.LOCK_EX);future=executor.submit(req)
   try:future.result(timeout=.15);raise AssertionError('Report ignored the source write lock')
   except concurrent.futures.TimeoutError:pass
   finally:fcntl.flock(locked,fcntl.LOCK_UN)
   assert future.result(timeout=5)[0]==200
  # TG reports translate copies of native amounts; their stored journals stay untouched.
  tg=journal('TG-FEE',[{**line('6800',367),'currency':'USD','nativeDebit':100,'nativeCredit':0},{**line('1110',cr=367),'currency':'USD','bankDebit':0,'bankCredit':100}]);tg['entity']='TG'
  path.write_text(json.dumps({'revision':8,'journals':{'TG-FEE':tg,'TTI-ONLY':journal('TTI-ONLY',[line('1110',999),line('3100',cr=999)])}}));native_before=path.read_bytes()
  for ccy,expected in [('USD',100),('AED',367)]:
   status,translated=req(entity='TG',currency=ccy)
   assert status==200 and translated['currency']==ccy,translated
   assert translated['trialBalance']['balanced'] and translated['trialBalance']['totalDebit']==expected,translated
   assert {r['journalId'] for r in translated['generalLedger']}=={'TG-FEE'}
   assert path.read_bytes()==native_before,'Report dropdown must never revalue or persist the journals'
  # Historical no-currency callers keep their original AED ledger basis, while
  # the explicit dropdown uses native amounts translated at the report master.
  tg['lines'][0]['debit']=tg['lines'][1]['credit']=360
  tg['totalDebit']=tg['totalCredit']=360
  path.write_text(json.dumps({'revision':9,'journals':{'TG-FEE':tg}}));historical_before=path.read_bytes()
  status,book_report=req(entity='TG',currency=None)
  assert status==200 and book_report['currencyBasis']=='Company books' and book_report['trialBalance']['totalDebit']==360,book_report
  status,explicit=req(entity='TG',currency='AED')
  assert status==200 and explicit['trialBalance']['totalDebit']==367,explicit
  # A known legacy mirrored payment and its consolidation reversal remain
  # readable, including a report period containing only the dated reversal.
  legacy=journal('OLD-MIRROR',[line('1250',365.91),{**line('1110',cr=365.91),'currency':'USD','bankDebit':0,'bankCredit':100}],date='2026-08-31')
  legacy.update(entity='TG',sourceType='TG_BANK_PAYMENT',meta={'currency':'USD','amountNative':100})
  reversal=json.loads(json.dumps(legacy));reversal.update(id='OLD-REVERSE',date='2026-09-01',sourceType='TG_REMITTANCE_CONSOLIDATION_REVERSAL',reversalOf='OLD-MIRROR')
  for l in reversal['lines']:
   l['debit'],l['credit']=l['credit'],l['debit']
   if 'bankDebit' in l:l['bankDebit'],l['bankCredit']=l['bankCredit'],l['bankDebit']
  path.write_text(json.dumps({'revision':10,'journals':{j['id']:j for j in [legacy,reversal]}}));legacy_before=path.read_bytes()
  status,legacy_report=req(entity='TG',currency='USD')
  assert status==200 and legacy_report['trialBalance']['balanced'],legacy_report
  assert any(r['journalId']=='OLD-REVERSE' and r['credit']==100 for r in legacy_report['generalLedger']),legacy_report
  assert path.read_bytes()==legacy_before
  # Unrecoverable records still fail explicit translation rather than invent FX.
  legacy['lines'][1]['bankCredit']=1
  path.write_text(json.dumps({'journals':{'OLD-MIRROR':legacy}}))
  status,held=req(entity='TG',currency='USD');assert status==422 and 'Native currency detail is missing' in held['error'],held
  path.write_bytes(unknown_before)
  # Damaged or unavailable books must never look like successful zero-balance reports.
  for invalid in ['', '{"journals":', 'null', '[{"journals":[]}]', '{"journals":"invalid"}']:
   path.write_text(invalid);saved=path.read_bytes();status,failed=req();assert status==500 and not failed['ok'] and 'trialBalance' not in failed,(invalid,failed);assert path.read_bytes()==saved
  path.unlink();assert req()[0]==500 and not path.exists(),'A report must not initialize missing books'
  path.write_bytes(unknown_before)
  print('PASS financial reports: Income/Revenue aliases, investment gain, signed rounding/FX, cumulative versus period profit, explicit unclassified balances, revision and zero writes')
 finally:server.terminate();server.wait();log.close()
