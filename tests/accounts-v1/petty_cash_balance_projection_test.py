"""Actual read routes agree on cash custody, without writing disposable books."""
import copy, csv, io, http.cookiejar, json, os, pathlib, shutil, socket, subprocess, tempfile, time, urllib.error, urllib.request
ROOT = pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='cash-projection-') as directory:
    root=pathlib.Path(directory); app=root/'app'
    shutil.copytree(ROOT,app,ignore=shutil.ignore_patterns('.git','node_modules','__pycache__','tmp'))
    private=root/'transtrade_private';private.mkdir()
    auth=private/'auth.json';auth.write_text(json.dumps({'users':[{'id':1,'username':'fixture','full_name':'Fixture Owner','role':'Super Admin','active':True,'session_version':1}], 'masters':{},'audit':[],'settings':{'qa_account_seeded':True,'master_permission_schema':2}}))
    (app/'fixture-session.php').write_text("<?php require __DIR__.'/auth_store.php';$_SESSION=['user_id'=>1,'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];echo 'ready';")
    journals={}
    def cash(amount, **extra): return dict(account='1120',debit=max(amount,0),credit=max(-amount,0),**extra)
    def post(key,lines,date='2026-07-01',entity='TTI',**extra):
        journals[key]=dict(id=key,entity=entity,status='Posted',date=date,sourceType='JV',lines=lines,**extra)
    # Unlabelled legacy office opening; transfers retain both control-account legs.
    post('OPEN',[cash(100000),dict(account='3100',credit=100000,debit=0)])
    post('STAFF',[cash(-5000,cashAccountId='CASH|TTI'),cash(2000,cashAccountId='CASH|TTI|STAFF|H'),cash(3000,paymentAccountId='CASH|TTI|STAFF|B')])
    post('MILL',[cash(-10000),cash(10000,millLocationId='M1',millName='Fixture Mill')])
    post('SPEND',[cash(-27820),dict(account='6900',debit=27820,credit=0)],date='2026-10-01',meta={'payee':'Household spending','expenseFor':'SALMAN'})
    post('MILL-OPEN',[cash(81100,millLocationId='M1'),dict(account='3100',credit=81100,debit=0)])
    # Proven amendment pair cancels on the original date in report copies.
    post('OLD',[cash(-100),dict(account='6900',debit=100,credit=0)],date='2026-09-01',amendedByPostId='NEW',reversedByPostId='REV')
    post('REV',[cash(100),dict(account='6900',debit=0,credit=100)],date='2026-10-15',reversalOf='OLD')
    post('NEW',[cash(0)],date='2026-09-01',meta={'amendmentOfPostId':'OLD'})
    post('FUTURE',[cash(-99)],date='2099-12-31')
    post('OTHER',[cash(777)],entity='BRM')
    post('TG',[cash(367,currency='AED')],entity='TG')
    post('DRAFT',[cash(888)]);journals['DRAFT']['status']='Draft'
    holder=lambda name: dict(entity='TTI',name=name,staffId=name,active=True)
    store=dict(revision=7,journals=journals,pettyCashHolders={'CASH|TTI|STAFF|H':holder('Hassan'),'CASH|TTI|STAFF|B':{**holder('Balouch'),'active':False}})
    books=private/'accounts.json';books.write_text(json.dumps(store))
    env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
    with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    log=open(root/'server.log','w');server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(app)],env=env,stdout=log,stderr=log)
    opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));base=f'http://127.0.0.1:{port}'
    def read(route,**query):
        url=base+route+('?' + urllib.parse.urlencode(query) if query else '')
        try:
            with opener.open(url,timeout=20) as response:raw=response.read();status=response.status
        except urllib.error.HTTPError as response:raw=response.read();status=response.code
        return status,json.loads(raw) if raw.lstrip().startswith(b'{') else raw.decode()
    def ledger(account,**extra):
        status,data=read('/api/accounts_ledger_browser.php',**{**dict(entity='TTI',category='bank',account=account,**{'from':'0001-01-01','to':'2026-10-27'}),**extra})
        assert status==200 and data['ok'],(status,data)
        return data
    try:
        for _ in range(60):
            try:read('/fixture-session.php');break
            except urllib.error.URLError:time.sleep(.05)
        before=books.read_bytes()
        status,petty=read('/api/petty_cash.php',entity='TTI');assert status==200 and isinstance(petty,dict),(status,petty[:500] if isinstance(petty,str) else petty)
        status,bank=read('/api/bank_accounts.php',entity='TTI');assert status==200,(status,bank)
        status,home=read('/api/accounts_dashboard.php',entity='TTI');assert status==200,(status,home)
        office=ledger('CASH|TTI')
        assert petty['officeBalance']==bank['cash']['bookBalance']==office['closing']==57180,(petty['officeBalance'],bank['cash'],office)
        assert next(x['amount'] for x in home['summaries']['bank'] if x.get('isCash'))==57180,home
        assert all(r['account']=='CASH|TTI' and r['accountName']=='Office Petty Cash' for r in office['rows'])
        assert ledger('CASH|TTI|STAFF|H')['closing']==2000
        assert ledger('CASH|TTI|STAFF|B')['closing']==3000 # inactive holders retain custody
        mill=ledger('CASH|TTI|MILL|M1');assert mill['closing']==91100
        assert next(r['name'] for r in mill['accounts'] if r['code']=='CASH|TTI|MILL|M1')=='Mill Petty Cash · Fixture Mill'
        assert ledger('6900',category='other')['closing']==27820 # household spending stays expense
        assert not any('HOME' in r['code'] for r in office['accounts'] if r['code'].startswith('CASH|'))
        control=ledger('1120');assert control['closing']==153280,control
        assert next(r['name'] for r in control['accounts'] if r['code']=='1120')=='All Cash · Office, Staff and Mills'
        historical=ledger('CASH|TTI',**{'from':'2026-10-01','to':'2026-10-27'})
        assert historical['opening']==85000 and historical['closing']==57180,historical
        status,raw=read('/api/accounts_ledger_browser.php',entity='TTI',category='bank',account='CASH|TTI',format='csv',**{'from':'0001-01-01','to':'2026-10-27'})
        rows=list(csv.reader(io.StringIO(raw)));assert status==200 and 'Office Petty Cash' in rows[0][3] and float(rows[-1][9])==57180,raw
        assert ledger('CASH|TG',entity='TG',currency='USD')['currency']=='AED'
        assert ledger('CASH|TG',entity='TG',currency='USD')['closing']==367
        status,bad=read('/api/accounts_ledger_browser.php',entity='TTI',account='CASH|BRM');assert status==422,(status,bad)
        assert books.read_bytes()==before,'Read routes changed financial books'
        # Malformed dates do not leak into a custody statement (same rule as its icon).
        for key,date in [('UNDATED',''),('INVALID','2026-02-30'),('WRONG-YEAR','20260-01-01')]:post(key,[cash(900)],date=date)
        books.write_text(json.dumps(store));malformed=books.read_bytes()
        assert ledger('CASH|TTI')['closing']==57180
        assert books.read_bytes()==malformed
        books.write_bytes(before)
        assert books.read_bytes()==before
        print('PASS actual Petty Cash, Home, bank card, office/staff/mill ledgers and CSV agree; combined control retained; amendments/openings/dates/TG/company scopes; no financial writes')
    finally:server.terminate();server.wait(timeout=10);log.close()
