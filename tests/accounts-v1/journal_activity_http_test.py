"""The Recent Transactions view filters events without removing their original ledger records."""
import http.cookiejar,json,os,shutil,socket,subprocess,tempfile,time,urllib.request
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='journal-activity-') as tmp:
    root=Path(tmp);app=root/'app';shutil.copytree(ROOT,app,ignore=shutil.ignore_patterns('.git','node_modules','__pycache__','tmp'))
    private=root/'transtrade_private';private.mkdir()
    user=dict(id=1,username='fixture',full_name='Fixture Owner',role='Super Admin',active=True,session_version=1)
    (private/'auth.json').write_text(json.dumps(dict(users=[user],masters={},audit=[],settings=dict(qa_account_seeded=True,master_permission_schema=2))))
    def line(account,debit=0,credit=0):return dict(account=account,debit=debit,credit=credit)
    def journal(id,source,lines,narration):return dict(id=id,entity='TTI',status='Posted',date='2026-10-31',sourceType=source,lines=lines,narration=narration,totalDebit=sum(l['debit'] for l in lines),totalCredit=sum(l['credit'] for l in lines))
    journals={j['id']:j for j in [journal('PAYROLL-INTERNAL','SALARY_MONTH_COMPLETED',[line('2230',100),line('1230',credit=100)],'Salary completed — 2026-10'),journal('RENT-RELEASE','JV',[line('6300',500),line('1400',credit=500)],'Monthly release prepaid rent'),journal('REAL-PAYMENT','SALARY_ALLOCATED_PAYMENT',[line('2230',300),line('1120',credit=300)],'Actual salary payment'),journal('OWNER-JV','JV',[line('1230',50),line('3200',credit=50)],'Owner journal')]}
    mill_line={**line('1120',credit=40),'cashAccountId':'CASH|TTI|MILL|M1'}
    journals['MILL-CASH']=journal('MILL-CASH','DIRECT_EXPENSE_PAYMENT',[line('6300',40),mill_line],'Paid repair at mill')
    books=private/'accounts.json';books.write_text(json.dumps(dict(revision=1,journals=journals)))
    (private/'operations.json').write_text(json.dumps(dict(values={},meta={})))
    (app/'fixture-session.php').write_text("<?php require __DIR__.'/auth_store.php';$_SESSION=['user_id'=>1,'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];echo 'ready';")
    with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
    log=(root/'server.log').open('w');server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(app)],env=env,stdout=log,stderr=log)
    client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));base=f'http://127.0.0.1:{port}'
    try:
        for _ in range(60):
            try:client.open(base+'/fixture-session.php',timeout=3).read();break
            except OSError:time.sleep(.05)
        original=books.read_bytes()
        def read(query):
            with client.open(base+'/api/accounts_ledger_browser.php?entity=TTI&account=POSTS&from=2026-10-01&to=2026-10-31'+query,timeout=15) as response:return json.load(response)
        all_posts=read('');recent=read('&activity=vouchers')
        assert {r['voucher'] for r in all_posts['rows']}==set(journals),'Full Post ID Register lost original journals'
        assert {r['voucher'] for r in recent['rows']}=={'REAL-PAYMENT','OWNER-JV'},recent
        payroll_row=next(r for r in all_posts['rows'] if r['voucher']=='PAYROLL-INTERNAL');assert 'no cash payment' in payroll_row['narration'] and payroll_row['originalNarration']=='Salary completed — 2026-10'
        detail=read('&postId=PAYROLL-INTERNAL')['post'];assert detail['id']=='PAYROLL-INTERNAL' and detail['narration']=='Salary completed — 2026-10'
        assert detail['accountingEvent']['cashMovement'] is False and detail['accountingEvent']['documentTitle']=='Accounting Journal'
        rent_data=json.load(client.open(base+'/api/rent_salary_v2.php?entity=TTI&month=2026-10'));assert len(rent_data['rentJournalReleases'])==1 and rent_data['rentJournalReleases'][0]['postId']=='RENT-RELEASE' and rent_data['rentMasters']==[],'Manual release must be visible without creating a new rent master'
        assert sum(r['debit'] for r in all_posts['rows'])==990 and books.read_bytes()==original,'Classification changed financial records'
        print('PASS actual activity API: Recent filters noncash payroll/prepaid release; full register, original Post IDs, detail narration and financial totals remain intact; zero writes')
    finally:server.terminate();server.wait(timeout=5);log.close()
