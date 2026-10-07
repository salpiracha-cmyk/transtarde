"""Disposable real-HTTP and browser tests for company-owned investment routing."""
import json, os, pathlib, shutil, socket, subprocess, tempfile, time, urllib.request, urllib.error
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='company-investments-') as tmp:
 root=pathlib.Path(tmp)
 for name in ['api','accounts','data']:(root/name).mkdir()
 for name in ['company_investments.php','company_investments_core.php','assets_registry_core.php','accounts_bank_payment.php','accounts_post_amend_core.php']:shutil.copy(ROOT/'api'/name,root/'api'/name)
 for name in ['accounting_master_v1.json','company-investments-ui.js']:shutil.copy(ROOT/'accounts'/name,root/'accounts'/name)
 (root/'auth_store.php').write_text('''<?php
 define('TT_DATA_DIR',__DIR__.'/data');function tt_ensure_data_dir(){}
 function tt_require_login(){ $role=$_GET['role']??'staff';return ['id'=>1,'username'=>'Fixture','role'=>$role==='super'?'Super Admin':'Staff','permissions'=>['Accounts'=>['assets'=>$role==='readonly'?['View']:($role==='unrelated'?[]:['View','Create','Edit','Delete'])],'Directors'=>['assets'=>['View','Create','Edit','Delete']]]]; }
 function tt_user_can_access_entity($u,$e,$a){return $e==='TTI'||($u['role']??'')==='Super Admin';}
 function tt_verify_csrf($v){return $v==='fixture';}
 function tt_list_masters(){return ['banks'=>[['id'=>'BANK-1','values'=>['Company Account','TTI','','Company Bank','Meezan','','','PKR','123456789','PK01TEST','','','','Active']],['id'=>'OTHER-BANK','values'=>['Company Account','BRM','','Other Company','Other Bank','','','PKR','987654321','PK02TEST','','','','Active']]]];}
 function tt_bank_can_transact($id){return true;}
 function tt_next_post_id($rows,$module='Accounts',$kind='Journal',$date=null){$n=count($rows)+1;do{$id='2026-'.str_pad((string)$n++,5,'0',STR_PAD_LEFT);}while(isset($rows[$id]));return $id;}
 ''')
 books=root/'data/accounts.json';initial={'revision':0,'journals':{},'bankAccountSettings':{'BANK-1':{'defaultPaymentAccount':True}}};books.write_text(json.dumps(initial))
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 base=f'http://127.0.0.1:{port}'
 def req(body=None,query=''):
  url=base+'/api/company_investments.php?entity=TTI'+query
  data=None if body is None else json.dumps(body).encode()
  try:
   with urllib.request.urlopen(urllib.request.Request(url,data=data,headers={'Content-Type':'application/json'})) as r:return r.status,json.load(r)
  except urllib.error.HTTPError as e:return e.code,json.load(e)
 def post(**kw):
  status,current=req();assert status==200,current
  body={'csrf':'fixture','entity':'TTI','revision':current['revision'],'requestKey':f'fixture-key-{time.time_ns()}',**kw}
  status,payload=req(body);return status,payload,body
 def good(**kw):
  status,payload,body=post(**kw);assert status==200,(status,payload,body);return payload['result'],body
 try:
  for _ in range(60):
   try:req();break
   except OSError:time.sleep(.05)
  broker,_=good(action='master',kind='broker',operation='add',name='BMA Capital',accountReference='BMA-100');bid=broker['id']
  route,_=good(action='master',kind='route',operation='add',name='Salman',accountReference='Personal bank routing only');rid=route['id']
  assert post(action='master',kind='broker',operation='add',name=' B.M.A.   Capital ')[0]==422
  args={'action':'post','date':'2026-10-07','reference':'BANK-TRANSFER-1','type':'FUND_ROUTE','routeId':rid,'amount':1000,'remuneration':200,'remunerationTreatment':'NEW_EXPENSE','paymentAccountId':'BANK-1','bankPaymentMethod':'ONLINE_BANKING'}
  transfer,body=good(**args);before=books.read_bytes();status,retry=req(body);assert status==200 and retry['result']==transfer and books.read_bytes()==before
  assert req({**body,'amount':999})[0]==409 and books.read_bytes()==before
  j=transfer['voucher'];assert 'SALMAN' in j['narration'];assert [(l['account'],l['debit'],l['credit']) for l in j['lines']]==[('1440',1000,0),('6210',200,0),('1110',0,1200)]
  assert post(**args)[0]==422
  assert req({**body,'requestKey':'stale-test-key','revision':0})[0]==409
  assert req(query='&role=readonly')[0]==200
  assert req({**body,'requestKey':'readonly-test-key'},'&role=readonly')[0]==403
  assert req(query='&role=unrelated')[0]==403
  assert req({**body,'csrf':'wrong','requestKey':'csrf-test-key'})[0]==419
  assert req({**body,'entity':'BRM','requestKey':'entity-test-key'})[0]==403
  assert post(**{**args,'reference':'WRONG-BANK','paymentAccountId':'OTHER-BANK'})[0]==422
  assert post(action='reverse',transactionId=transfer['transactionId'],date='2026-10-07',reason='Remove before onward routing')[0]==200
  transfer,_=good(**{**args,'reference':'BANK-TRANSFER-2'})
  onward,_=good(action='post',date='2026-10-07',reference='PERSONAL-TO-BMA',type='ROUTE_TO_BROKER',routeId=rid,brokerId=bid,amount=1000)
  status,d=req();assert d['routes'][0]['balanceCents']==0 and d['brokers'][0]['balanceCents']==100000
  assert post(action='reverse',transactionId=transfer['transactionId'],date='2026-10-07',reason='Must not strand onward funds')[0]==422
  assert post(action='post',date='2026-10-07',reference='EXCESS-ROUTE',type='ROUTE_TO_BROKER',routeId=rid,brokerId=bid,amount=1)[0]==422
  buy,_=good(action='post',date='2026-10-07',reference='BUY-1',type='BUY',brokerId=bid,symbol='ABC',quantity=500,price=1,fees=10)
  sell,_=good(action='post',date='2026-10-07',reference='SELL-1',type='SELL',brokerId=bid,symbol='ABC',quantity=200,price=2,fees=5)
  status,d=req();assert d['brokers'][0]['balanceCents']==88500 and d['holdings'][0]['quantity']==300 and d['holdings'][0]['costCents']==30000
  assert sell['voucher']['totalDebit']==sell['voucher']['totalCredit']==400
  assert post(action='post',date='2026-10-07',reference='OVERSELL',type='SELL',brokerId=bid,symbol='ABC',quantity=301,price=1,fees=0)[0]==422
  assert post(action='post',date='2026-10-06',reference='BACKDATED',type='SELL',brokerId=bid,symbol='ABC',quantity=1,price=1,fees=0)[0]==422
  assert post(action='reverse',transactionId=buy['transactionId'],date='2026-10-07',reason='Must not strand later sales')[0]==422
  assert post(action='master',kind='broker',operation='delete',id=bid)[0]==422
  status,current=req();status,archived=req({'csrf':'fixture','entity':'TTI','revision':current['revision'],'requestKey':'super-archive-in-use','action':'master','kind':'broker','operation':'delete','id':bid},'&role=super');assert status==200 and archived['brokers'][0]['balanceCents']==88500
  good(action='master',kind='broker',operation='edit',id=bid,name='BMA Capital',accountReference='BMA-100')
  assert post(action='post',date='2026-10-07',reference='OVERDRAW',type='WITHDRAW_BANK',brokerId=bid,amount=1000,paymentAccountId='BANK-1')[0]==422
  good(action='reverse',transactionId=sell['transactionId'],date='2026-10-07',reason='Correct share sale')
  good(action='reverse',transactionId=buy['transactionId'],date='2026-10-07',reason='Correct share purchase')
  withdraw,_=good(action='post',date='2026-10-07',reference='RETURN-TO-PERSON',type='WITHDRAW_ROUTE',brokerId=bid,routeId=rid,amount=1000)
  good(action='post',date='2026-10-07',reference='RETURN-TO-COMPANY',type='ROUTE_RETURN',routeId=rid,amount=1000,paymentAccountId='BANK-1')
  status,d=req();assert d['brokers'][0]['balanceCents']==0 and d['routes'][0]['balanceCents']==0
  store=json.loads(books.read_text());balance=sum(l['debit']-l['credit'] for j in store['journals'].values() for l in j['lines'] if l['account']=='1110');assert balance==-200
  good(action='master',kind='broker',operation='delete',id=bid)
  assert post(action='post',date='2026-10-07',reference='ARCHIVED',type='FUND_DIRECT',brokerId=bid,amount=1,paymentAccountId='BANK-1',bankPaymentMethod='ONLINE_BANKING')[0]==422
  assert post(action='master',kind='broker',operation='add',name='BMA CAPITAL')[0]==422
  good(action='master',kind='broker',operation='edit',id=bid,name='BMA Capital Restored',accountReference='BMA-100')
  good(action='post',date='2026-10-07',reference='CHEQUE-1',type='FUND_DIRECT',brokerId=bid,amount=100,paymentAccountId='BANK-1',bankPaymentMethod='CHEQUE',chequeNo='CH-1',chequeDate='2026-10-07')
  assert post(action='post',date='2026-10-07',reference='CHEQUE-2',type='FUND_DIRECT',brokerId=bid,amount=100,paymentAccountId='BANK-1',bankPaymentMethod='CHEQUE',chequeNo='CH-1',chequeDate='2026-10-07')[0]==422
  assert post(action='post',date='2026-10-07',reference='NO-PAYABLE',type='FUND_ROUTE',routeId=rid,amount=100,remuneration=200,remunerationTreatment='PAYABLE',paymentAccountId='BANK-1',bankPaymentMethod='ONLINE_BANKING')[0]==422
  store=json.loads(books.read_text());store['salaryPeriods']={'PAY-SALMAN':{'id':'PAY-SALMAN','entity':'TTI','month':'2026-10','salaryMasterId':'SALMAN','name':'Salman','outstanding':200,'paidAfterPrepare':0,'status':'Payable'}};store['journals']['PAYROLL']={'id':'PAYROLL','entity':'TTI','status':'Posted','date':'2026-10-07','lines':[{'account':'6210','debit':200,'credit':0},{'account':'2140','debit':0,'credit':200,'person':'Salman'}]};books.write_text(json.dumps(store))
  payroll,_=good(action='post',date='2026-10-07',reference='PAYROLL-ROUTED',type='FUND_ROUTE',routeId=rid,amount=100,remuneration=200,remunerationTreatment='PAYABLE',salaryPeriodId='PAY-SALMAN',paymentAccountId='BANK-1',bankPaymentMethod='ONLINE_BANKING')
  store=json.loads(books.read_text());assert store['salaryPeriods']['PAY-SALMAN']['outstanding']==0 and store['salaryPeriods']['PAY-SALMAN']['paidAfterPrepare']==200;assert [l['account'] for l in payroll['voucher']['lines']]==['1440','2140','1110']
  good(action='reverse',transactionId=payroll['transactionId'],date='2026-10-07',reason='Correct combined salary transfer')
  store=json.loads(books.read_text());assert store['salaryPeriods']['PAY-SALMAN']['outstanding']==200 and store['salaryPeriods']['PAY-SALMAN']['paidAfterPrepare']==0
  before=books.read_bytes();books.write_text('{broken-json');assert req()[0]==500;assert req(body)[0]==500 and books.read_text()=='{broken-json';books.write_bytes(before)
  print('Investment HTTP: routing, remuneration split, shares, return transfers, corrections, replay protection, archived masters, cheque validation and permissions passed.')
  if os.environ.get('TT_QA_NODE_BROWSER')=='1':
   books.write_text(json.dumps(initial))
   (root/'accounts/index.html').write_text('<!doctype html><meta name="viewport" content="width=device-width"><button id="open">Assets & Investments</button><script>window.TT_ACCOUNT_ACCESS={csrf:"fixture"};</script><script src="company-investments-ui.js"></script><script>document.querySelector("#open").onclick=()=>TT_INVESTMENTS_UI.open("TTI");</script>')
   pathlib.Path(os.environ['TT_QA_OUTPUT']).mkdir(parents=True,exist_ok=True)
   subprocess.run(['node',str(ROOT/'tests/accounts-v1/company_investments_browser_test.js'),base],check=True)
  if os.environ.get('TT_QA_BROWSER')=='1':
   from playwright.sync_api import sync_playwright
   books.write_text(json.dumps(initial))
   (root/'accounts/index.html').write_text('''<!doctype html><meta name="viewport" content="width=device-width"><button id="open">Assets & Investments</button><script>window.TT_ACCOUNT_ACCESS={csrf:'fixture'};window.print=()=>{};</script><script src="company-investments-ui.js"></script><script>document.querySelector('#open').onclick=()=>TT_INVESTMENTS_UI.open('TTI');</script>''')
   with sync_playwright() as pw:
    browser=pw.chromium.launch();page=browser.new_page(viewport={'width':1280,'height':900});errors=[];page.on('pageerror',lambda e:errors.append(str(e)));page.add_init_script("window.print=()=>{}")
    page.goto(base+'/accounts/index.html');page.locator('#open').click();page.locator('#ivNewBroker').click();page.locator('[name=name]').fill('BMA Capital');page.locator('[name=accountReference]').fill('BMA-100');page.get_by_role('button',name='ADD',exact=True).click();page.locator('#ivNewRoute').click();page.locator('[name=name]').fill('Salman');page.get_by_role('button',name='ADD',exact=True).click();page.locator('#ivNewPost').click()
    f=page.locator('#ivEntry');f.locator('[name=routeId]').select_option(index=1);f.locator('[name=reference]').fill('BANK-UI-1');f.locator('[name=amount]').fill('1000');f.locator('[name=remuneration]').fill('200');f.locator('[name=remunerationTreatment]').select_option('NEW_EXPENSE');f.locator('[name=bankPaymentMethod]').select_option('ONLINE_BANKING');assert f.locator('[name=chequeNo]').is_hidden();assert '1,200.00' in page.locator('#ivTotal').inner_text()
    f.get_by_role('button',name='POST & PRINT VOUCHER',exact=True).click();page.locator('#ivNewPost').wait_for();assert '1,000.00' in page.locator('main').inner_text();page.locator('#ivNewPost').click();f=page.locator('#ivEntry');f.locator('[name=type]').select_option('ROUTE_TO_BROKER');f.locator('[name=routeId]').select_option(index=1);f.locator('[name=brokerId]').select_option(index=1);f.locator('[name=reference]').fill('ROUTE-UI-1');f.locator('[name=amount]').fill('1000');assert f.locator('[name=paymentAccountId]').is_hidden();f.get_by_role('button',name='POST & PRINT VOUCHER',exact=True).click();page.locator('#ivNewPost').wait_for();page.locator('#ivNewPost').click();f=page.locator('#ivEntry');f.locator('[name=type]').select_option('BUY');f.locator('[name=brokerId]').select_option(index=1);f.locator('[name=reference]').fill('BUY-UI-1');f.locator('[name=symbol]').fill('ABC');f.locator('[name=quantity]').fill('100');f.locator('[name=price]').fill('5');f.locator('[name=fees]').fill('10');f.get_by_role('button',name='POST & PRINT VOUCHER',exact=True).click();page.locator('#ivNewPost').wait_for();assert '490.00' in page.locator('main').inner_text();page.locator('summary').click();page.locator('[data-iv-reverse]').first.click();page.locator('[name=reason]').fill('Correct purchase price');page.get_by_role('button',name='REVERSE & PRINT VOUCHER',exact=True).click();page.locator('#ivNewPost').wait_for();page.locator('#ivRefresh').click();page.locator('#ivNewPost').wait_for();assert '1,000.00' in page.locator('main').inner_text();page.set_viewport_size({'width':390,'height':844});assert page.locator('.iv-window').bounding_box()['width']<=390;assert not errors,errors
    output=os.environ.get('TT_QA_OUTPUT');
    if output:pathlib.Path(output).mkdir(parents=True,exist_ok=True);page.screenshot(path=str(pathlib.Path(output)/'investments-mobile.png'),full_page=True)
    browser.close();print('Investment browser: masters, split transfer, onward transfer, share purchase, voucher, reversal, reload and mobile layout passed.')
 finally:server.terminate();server.wait(timeout=10)
