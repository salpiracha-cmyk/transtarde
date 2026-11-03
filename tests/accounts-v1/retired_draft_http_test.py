"""The Recent Transactions view filters events without removing their original ledger records."""
import http.cookiejar,json,os,shutil,socket,subprocess,tempfile,time,urllib.request,urllib.error
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
        def candidate(id,ref,stage='Draft',posted=False):return dict(id=id,entity='TG',candidateType='CUSTOMER_EXPORT_SALE',transactionCurrency='AED',transactionAmount=100,journalId='REAL-POST' if posted else None,meta=dict(contractRef=ref,commercialInvoiceNo=id,invoiceStage=stage,customer='Same customer',commercialInvoiceDate='2026-09-21'))
        pakistan_old=candidate('TTI-OLD-TEST','QA-RETIRED-TTI');pakistan_old['entity']='TTI'
        old=candidate('OLD-TEST','QA-RETIRED');normal=candidate('REAL-DRAFT','ACTIVE-CONTRACT');posted=candidate('POSTED-REAL','QA-RETIRED','Final',True)
        books.write_text(json.dumps(dict(revision=1,journals={},exportCandidates={c['id']:c for c in [old,normal,posted,pakistan_old]})))
        root_source=dict(contracts=[],shipments=[],deletedShipments=[dict(id='OWNER-CLEANUP',contractRef='QA-RETIRED',deletedAt='2026-09-25T00:00:00Z',qaCleanup=True),dict(id='OWNER-CLEANUP-TTI',contractRef='QA-RETIRED-TTI',deletedAt='2026-09-25T00:00:00Z',qaCleanup=True)])
        ops=private/'operations.json';ops.write_text(json.dumps(dict(values=dict(transtrade_export_v3_operational=json.dumps(root_source)),meta={})))
        original=books.read_bytes();ops_original=ops.read_bytes()
        def read(path):
            with client.open(base+path,timeout=15) as response:return json.load(response)
        dashboard=read('/api/accounts_dashboard.php?entity=TG');assert dashboard['ok'] and [r['reference'] for r in dashboard['customerReceivables']['drafts']]==['REAL-DRAFT'],dashboard
        recognition=read('/api/export_accounting.php?entity=TG');assert {c['id'] for c in recognition['candidates']}=={'REAL-DRAFT','POSTED-REAL'} and [c['id'] for c in recognition['retiredDraftCandidates']]==['OLD-TEST'],recognition
        receipts=read('/api/export_receipts.php?entity=TTI');assert receipts['ok'] and not any(r['reference']=='TTI-OLD-TEST' for r in receipts.get('invoices',[])),receipts
        assert books.read_bytes()==original and ops.read_bytes()==ops_original,'Read-only archival projection changed journals, candidates or sources'
        # An old cached source cannot re-queue a retired draft: original saved contract/lot guard remains enforced.
        payload=dict(csrf='fixture',entity='TG',action='queue_candidate',sellerEntity='TG',sourceKey='EXPORT|RETIRED-LOT',stage='Draft',commercialInvoiceDate='2026-09-21',currency='AED',transactionAmount=100,actualQtyMT=1,contractRef='QA-RETIRED',commercialInvoiceNo='OLD-TEST')
        try:client.open(urllib.request.Request(base+'/api/export_accounting.php',data=json.dumps(payload).encode(),headers={'Content-Type':'application/json'}),timeout=15);raise AssertionError('Retired source was re-queued')
        except urllib.error.HTTPError as response:assert response.code==403,response.read()
        assert books.read_bytes()==original and ops.read_bytes()==ops_original
        root_source['contracts']=[dict(ref='QA-RETIRED',seller='TG',customer='Same customer',currency='AED')];ops.write_text(json.dumps(dict(values=dict(transtrade_export_v3_operational=json.dumps(root_source)),meta={})))
        restored=read('/api/export_accounting.php?entity=TG');assert {c['id'] for c in restored['candidates']}=={'OLD-TEST','REAL-DRAFT','POSTED-REAL'} and restored['retiredDraftCandidates']==[],'Recreated active source was hidden'
        assert books.read_bytes()==original
        print('PASS real dashboard/recognition/receipt projections: exact orphan test draft archived; original candidate recoverable; posted and genuine same-customer records retained; stale source cannot requeue; restored active source visible; zero financial/operational writes')
    finally:server.terminate();server.wait(timeout=5);log.close()
