"""Original-device recovery retains evidence without posting arrivals or financial records."""
import http.cookiejar,json,os,shutil,socket,subprocess,tempfile,time,urllib.request,urllib.error
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='arrival-recovery-') as tmp:
    root=Path(tmp);app=root/'app';shutil.copytree(ROOT,app,ignore=shutil.ignore_patterns('.git','node_modules','__pycache__','tmp'))
    private=root/'transtrade_private';private.mkdir()
    user=dict(id=1,username='fixture',full_name='Fixture Owner',role='Super Admin',active=True,session_version=1)
    (private/'auth.json').write_text(json.dumps(dict(users=[user],masters={},audit=[],settings=dict(qa_account_seeded=True,master_permission_schema=2))))
    def line(account,debit=0,credit=0):return dict(account=account,debit=debit,credit=credit)
    def journal(id,source,lines,narration):return dict(id=id,entity='TTI',status='Posted',date='2026-10-31',sourceType=source,lines=lines,narration=narration,totalDebit=sum(l['debit'] for l in lines),totalCredit=sum(l['credit'] for l in lines))
    journals={j['id']:j for j in [journal('PAYROLL-INTERNAL','SALARY_MONTH_COMPLETED',[line('2230',100),line('1230',credit=100)],'Salary completed — 2026-10'),journal('RENT-RELEASE','JV',[line('6300',500),line('1400',credit=500)],'Monthly release prepaid rent'),journal('REAL-PAYMENT','SALARY_ALLOCATED_PAYMENT',[line('2230',300),line('1120',credit=300)],'Actual salary payment'),journal('OWNER-JV','JV',[line('1230',50),line('3200',credit=50)],'Owner journal')]}
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
        staff={**user,'full_name':'Fixture Mill Operator','role':'Mill Manager','location':'TTI Rice Mills','permissions':{'Mill':{'queue':['View','Create','Edit'],'arrival':['View']}}}
        auth=private/'auth.json';state=json.loads(auth.read_text());state['users']=[staff,{**staff,'id':2,'username':'other'}];auth.write_text(json.dumps(state))
        operations=private/'operations.json';operations.write_text(json.dumps(dict(values={'tt30queue':'[]','tt30slips':'[]','transtrade_export_v3_operational':'{}'},meta={'tt30queue':{'version':1}},revision=1)))
        record=dict(id=101,date='2026-10-31',entity='TTI',millId=1,millName='TTI Rice Mills',locationName='TTI Rice Mills',vehicle='FIX-123',bags='100',weight='5000',party='Fixture supplier')
        def request(path='/api/arrival_recovery.php',body=None):
            try:
                with client.open(urllib.request.Request(base+path,data=None if body is None else json.dumps({'csrf':'fixture',**body}).encode(),headers={'Content-Type':'application/json'}),timeout=15) as response:return response.status,json.load(response)
            except urllib.error.HTTPError as response:return response.code,json.load(response)
        original_books=books.read_bytes();original_ops=operations.read_bytes();status,first=request();assert status==200 and first['canCreate'] and first['recoveries']==[],(status,first)
        assert request('/api/arrival_recovery.php?version_only=1')[1]=={'ok':True,'version':1}
        assert not (private/'arrival_recovery.json').exists(),'GET created a recovery store'
        b={'action':'capture','entries':[{'record':record,'source':'pending-save'}]};status,saved=request(body=b);assert status==200 and saved['recoveries'][0]['serverStatus']=='NOT_ON_SERVER',(status,saved)
        archive=private/'arrival_recovery.json';stable=archive.read_bytes();assert request(body=b)[0]==200 and archive.read_bytes()==stable,'Duplicate capture created another recovery record'
        assert books.read_bytes()==original_books and operations.read_bytes()==original_ops,'Recovery capture posted stock or money'
        assert request(body={**b,'csrf':'bad'})[0]==419
        bad={**b,'entries':[{'record':{**record,'millName':'Other mill','locationName':'Other mill'},'source':'pending-save'}]};assert request(body=bad)[0]==403 and archive.read_bytes()==stable
        # An unrelated operator cannot see this original device record.
        (app/'fixture-session.php').write_text("<?php require __DIR__.'/auth_store.php';$_SESSION=['user_id'=>2,'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];echo 'ready';")
        client.open(base+'/fixture-session.php').read();assert request()[1]['recoveries']==[]
        (app/'fixture-session.php').write_text("<?php require __DIR__.'/auth_store.php';$_SESSION=['user_id'=>1,'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];echo 'ready';")
        client.open(base+'/fixture-session.php').read()
        # The original endpoint posts the original arrival, not the recovery endpoint.
        status,posted=request('/api/operations.mysql.php',dict(key='tt30queue',value=json.dumps([record]),baseVersion=1,sourceModule='Mill'));assert status==200,(status,posted)
        status,confirmed=request();assert status==200 and confirmed['recoveries'][0]['serverStatus']=='SAVED' and len(confirmed['serverArrivals'])==1,(status,confirmed)
        assert books.read_bytes()==original_books,'Recovered arrival changed financial journals'
        changed={**record,'weight':'5100'};op=json.loads(operations.read_text());op['values']['tt30queue']=json.dumps([changed]);operations.write_text(json.dumps(op));assert request()[1]['recoveries'][0]['serverStatus']=='CHANGED_ON_SERVER','Recovery suggested overwriting a changed original'
        assert archive.read_bytes()==stable,'Read/confirmation rewrote the preserved original'
        state=json.loads(auth.read_text());state['users'][0]['permissions']['Mill']['queue']=['View'];auth.write_text(json.dumps(state));assert request()[1]['canCreate'] is False
        assert request(body=b)[0]==200,'View user could not preserve their own recovery copy'
        status,forbidden=request('/api/operations.mysql.php',dict(key='tt30queue',value=json.dumps([changed,{**record,'id':102,'vehicle':'FIX-456'}]),baseVersion=json.loads(operations.read_text())['meta']['tt30queue']['version'],sourceModule='Mill'));assert status==403,(status,forbidden)
        print('PASS original-device recovery: read-only source/permissions, private idempotent copies, CSRF/user/mill scope, no automatic arrival or financial posting, original-ID save through existing policy, confirmed-versus-changed status and View cannot post')
    finally:server.terminate();server.wait(timeout=5);log.close()
