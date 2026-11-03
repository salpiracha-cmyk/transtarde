"""Real disposable Mill expense posting, balances, retry, preview and role boundaries."""
import http.cookiejar,json,os,pathlib,shutil,socket,subprocess,tempfile,time,urllib.error,urllib.request
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='mill-cash-entry-') as directory:
 root=pathlib.Path(directory);app=root/'app';shutil.copytree(ROOT,app,ignore=shutil.ignore_patterns('.git','node_modules','__pycache__'));private=root/'transtrade_private';private.mkdir()
 user={'id':1,'username':'fixture','full_name':'Mill Cash Fixture','role':'Staff','active':True,'session_version':1,'location':'Fixture Mill','permissions':{'Mill':{'petty':['View','Create']}}}
 auth=private/'auth.json';auth.write_text(json.dumps({'users':[user],'masters':{'mills':[{'id':'M1','values':['Fixture Mill','','Own Mill','','','Active','','TTI']},{'id':'M2','values':['Other Mill','','Own Mill','','','Active','','TTI']}]},'audit':[],'settings':{'qa_account_seeded':True,'master_permission_schema':2}}))
 cash=lambda dr,cr,**extra:dict(account='1120',debit=dr,credit=cr,millLocationId='M1',**extra)
 j=lambda id,date,lines,**extra:dict(id=id,date=date,entity='TTI',status='Posted',sourceType='JV',lines=lines,**extra)
 journals={'FUND':j('FUND','2026-07-01',[cash(1000,0),dict(account='3400',debit=0,credit=1000)]),'FUTURE':j('FUTURE','2099-01-01',[cash(99999,0)]),'OLD':j('OLD','2026-07-02',[cash(0,100)],reversedByPostId='REV'),'REV':j('REV','2026-07-02',[cash(100,0)],reversalOf='OLD')}
 books=private/'accounts.json';books.write_text(json.dumps({'revision':1,'journals':journals}))
 (app/'fixture-session.php').write_text("<?php require __DIR__.'/auth_store.php';$_SESSION=['user_id'=>1,'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];echo 'ready';")
 env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 log=open(root/'server.log','w');server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(app)],env=env,stdout=log,stderr=log)
 opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));base=f'http://127.0.0.1:{port}'
 def req(path,body=None):
  data=None if body is None else json.dumps({'csrf':'fixture',**body}).encode()
  try:
   with opener.open(urllib.request.Request(base+path,data=data,headers={'Content-Type':'application/json'}),timeout=15) as r:return r.status,json.loads(r.read())
  except urllib.error.HTTPError as r:return r.code,json.loads(r.read())
 def read():
  code,d=req('/api/mill_cash_ledger.php?entity=TTI&locationId=M1');assert code==200,(code,d);return d
 def post(b,expected=200):
  before=books.read_bytes();code,d=req('/api/mill_cash_ledger.php',b);assert code==expected,(code,d)
  if expected!=200 or b.get('preview'):assert books.read_bytes()==before
  return d
 try:
  for _ in range(60):
   try:opener.open(base+'/fixture-session.php',timeout=1).close();break
   except urllib.error.URLError:time.sleep(.05)
  before=books.read_bytes();d=read();assert d['balance']==1000 and len(d['rows'])==1 and books.read_bytes()==before,d
  b=dict(entity='TTI',locationId='M1',revision=d['revision'],requestKey='mill-payment-first-exact',type='Milling Expense',date='2026-08-01',amount=125.25,payee='Fixture Worker',expenseType='Packing labour',narration='Actual shift labour paid')
  seq=json.loads(auth.read_text()).get('post_sequence');p=post({**b,'preview':True});assert len(p['previews'])==1 and json.loads(auth.read_text()).get('post_sequence')==seq
  lines=p['previews'][0]['lines'];assert lines[0]['account']=='5200' and lines[-1]['millLocationId']=='M1' and lines[-1]['credit']==125.25
  for patch in [{'amount':1001},{'amount':-5},{'narration':''},{'expenseType':''},{'payee':''},{'type':'RECEIPT'},{'date':'2099-01-01'},{'date':'2026-06-30'},{'locationId':'M2'},{'entity':'BRM'}]:post({**b,**patch,'requestKey':'bad-'+str(time.time_ns())},422)
  post({**b,'csrf':'wrong'},419);post({**b,'revision':0},409)
  done=post(b);jid=done['result']['journalId'];assert done['balance']==874.75 and done['rows'][-1]['balance']==874.75 and done['journal']['meta']['millPettyType']=='Milling Expense',done
  state=json.loads(books.read_text());assert state['generalExpenses'][done['result']['generalExpenseId']]['journalId']==jid
  before=books.read_bytes();assert post(b)['result']['journalId']==jid and books.read_bytes()==before
  post({**b,'amount':100},409)
  for category,code in [('Office Expense','6900'),('Plant Expense','6400')]:
   b2={**b,'revision':read()['revision'],'requestKey':'mill-'+category.replace(' ','-'),'type':category,'amount':10};r=post(b2);assert r['journal']['lines'][0]['account']==code
  # Changing the Accounts source leaves the pending original revision stale; nothing posts.
  post({**b,'requestKey':'mill-stale-new-request'},409)
  a=json.loads(auth.read_text());a['users'][0]['permissions']['Mill']['petty']=['View'];auth.write_text(json.dumps(a));post({**b,'requestKey':'no-create'},403)
  a['users'][0]['permissions']['Mill']['petty']=['View','Create'];a['users'][0]['permissions']['Accounts']={'entity-tti':['View']};auth.write_text(json.dumps(a));post({**b,'requestKey':'company-denied'},403)
  a['users'][0]=dict(user,location='Other Mill');auth.write_text(json.dumps(a));code,d=req('/api/mill_cash_ledger.php?entity=TTI&locationId=M1');assert code==422
  a['users'][0]=dict(user,role='Super Admin');auth.write_text(json.dumps(a));d=read();code,ledger=req('/api/accounts_ledger_browser.php?entity=TTI&account=CASH%7CTTI%7CMILL%7CM1&to=2026-12-31');assert code==200 and ledger['closing']==d['balance'],(code,ledger,d)
  # The original Accounts form can correct this native expense; no second unlinked ledger.
  code,payload=req('/api/expenses_v1.php?entity=TTI');assert code==200 and any(x['id']=='CASH|TTI|MILL|M1' for x in payload['millCashSources'])
  amendment=dict(entity='TTI',action='amend_expense',revision=read()['revision'],requestKey='mill-native-expense-correction',id=done['result']['generalExpenseId'],reason='Correct original mill expense amount',paymentDate='2026-08-01',paymentAccountId='CASH|TTI|MILL|M1',payee='Fixture Worker',paymentNarration='Corrected actual labour payment',expenseLines=[dict(category='MILL',amount=100,purpose='Packing labour',expenseArea='MILL')])
  code,amended=req('/api/expenses_v1.php',amendment);assert code==200,(code,amended)
  d=read();assert d['balance']==880 and len(d['rows'])==4,d
  code,ledger=req('/api/accounts_ledger_browser.php?entity=TTI&account=CASH%7CTTI%7CMILL%7CM1&to=2026-12-31');assert code==200 and ledger['closing']==880,(code,ledger)
  print('PASS Mill cash: staff canonical expense, owner ledger agreement, receipt-payment arithmetic, cents, future/reversed exclusion, category heads, zero-write preview, retry/conflict, dated funds, permission/company/location/CSRF guards')
 finally:server.terminate();server.wait(timeout=10);log.close()
