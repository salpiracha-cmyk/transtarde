"""Dashboard commodity dues use the same authoritative allocations as Payment Planning."""
import copy, http.cookiejar, json, os, pathlib, shutil, socket, subprocess, tempfile, time, urllib.error, urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='dashboard-dues-') as directory:
    root=pathlib.Path(directory); app=root/'app'
    shutil.copytree(ROOT,app,ignore=shutil.ignore_patterns('.git','node_modules','__pycache__'))
    private=root/'transtrade_private'; private.mkdir()
    user={'id':1,'username':'fixture','full_name':'Fixture Owner','role':'Super Admin','active':True,'session_version':1}
    auth=private/'auth.json'; auth.write_text(json.dumps({'users':[user],'masters':{},'audit':[],'settings':{'qa_account_seeded':True,'master_permission_schema':2}}))
    books=private/'accounts.json'; books.write_text(json.dumps({'revision':1,'journals':{'ORIGINAL':{'id':'ORIGINAL','entity':'TTI','status':'Posted','date':'2026-07-01','lines':[{'account':'1110','debit':100,'credit':0},{'account':'3100','debit':0,'credit':100}]}}}))
    (app/'fixture-session.php').write_text("<?php require __DIR__.'/auth_store.php';$_SESSION=['user_id'=>1,'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];echo 'ready';")
    env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
    with socket.socket() as sock: sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
    log=open(root/'server.log','w'); server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(app)],env=env,stdout=log,stderr=log)
    opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())); base=f'http://127.0.0.1:{port}'
    def request(path='/api/masters.php',body=None):
        data=None if body is None else json.dumps({'csrf':'fixture',**body}).encode()
        try:
            with opener.open(urllib.request.Request(base+path,data=data,headers={'Content-Type':'application/json'}),timeout=15) as response:
                raw=response.read(); return response.status,json.loads(raw) if raw.startswith(b'{') else raw
        except urllib.error.HTTPError as response: return response.code,json.loads(response.read())
    try:
        for _ in range(60):
            try: request('/fixture-session.php'); break
            except urllib.error.URLError: time.sleep(.05)
        fixture=json.loads(books.read_text())
        def bill(id,entity,share,status='Posted',source=None):
            return {'id':id,'entity':entity,'status':status,'broker':'JJ Broker','billNo':id,'commodity':'RICE','supplierPayableTotal':share,'finalCommodityValue':share,'brokerageGross':0,'brokerageWithholding':0,'billDate':'2026-09-14','receiptAllocations':[{'sourceKey':source or id+'-TRUCK','supplierPayableShare':share,'component':'COMMODITY','dueDate':'2026-09-20','payee':'Indus Rice Mill'}]}
        fixture['commodityBills']={'CLOSED':bill('CLOSED','TTI',6800000),'PART':bill('PART','TTI',1000),'HELD':bill('HELD','TTI',25),'OTHER':bill('OTHER','BRM',90),'CANCELLED':bill('CANCELLED','TTI',700,'Cancelled'),'DRAFT':bill('DRAFT','TTI',800,'Draft')}
        # Historical allocation IDs differ from current bill IDs, but retain their source/truck links.
        fixture['supplierSettlements']={'HIST':{'entity':'TTI','status':'Posted','allocations':[{'billId':'HISTORICAL-ID','sourceKey':'CLOSED-TRUCK','amount':6800000},{'billId':'PART','sourceKey':'PART-TRUCK','amount':400}]},'REVERSED':{'entity':'TTI','status':'Reversed','allocations':[{'billId':'PART','sourceKey':'PART-TRUCK','amount':600}]},'CROSS':{'entity':'BRM','status':'Posted','allocations':[{'sourceKey':'PART-TRUCK','amount':600}]}}
        fixture['payableHolds']={'TTI|HELD-TRUCK':{'active':True,'reason':'Review'}}
        books.write_text(json.dumps(fixture));before=books.read_bytes()
        status,data=request('/api/accounts_dashboard.php?entity=TTI');assert status==200,(status,data)
        rows=data['summaries']['commodity'];assert sum(r['amount'] for r in rows)==625,(rows,data)
        assert {r['reference'] for r in rows}=={'PART','HELD'} and all(r['label']=='Indus Rice Mill' and r['date']=='2026-09-20' for r in rows),rows
        status,data=request('/api/accounts_dashboard.php?entity=BRM');assert status==200 and sum(r['amount'] for r in data['summaries']['commodity'])==90,(status,data)
        assert books.read_bytes()==before,'Dashboard reads must never write or allocate financial records'
        fixture['supplierSettlements']['FINAL']={'entity':'TTI','status':'Posted','allocations':[{'billId':'PART','sourceKey':'PART-TRUCK','amount':600},{'billId':'HELD','sourceKey':'HELD-TRUCK','amount':25}]};books.write_text(json.dumps(fixture));before=books.read_bytes()
        status,data=request('/api/accounts_dashboard.php?entity=TTI');assert status==200 and data['summaries']['commodity']==[],(status,data)
        assert books.read_bytes()==before
        # Manual JV settlement and an on-account advance are projected exactly as in Payment Planning.
        fixture['commodityBills']['ROUND']=bill('ROUND','TTI',500);fixture['commodityBills']['ADVANCE']=bill('ADVANCE','TTI',700)
        fixture['journals']['JV-PAID']={'id':'JV-PAID','entity':'TTI','status':'Posted','sourceType':'JV','date':'2026-09-21','lines':[{'account':'2110','debit':500,'credit':0,'counterparty':'Indus Rice Mill'},{'account':'1110','debit':0,'credit':500}]}
        fixture['supplierAdvances']={'ADV':{'id':'ADV','entity':'TTI','supplier':'Indus Rice Mill','amount':300,'allocatedAmount':0,'availableAmount':300,'allocationScope':'COMMODITY_GROUP','status':'Available / Unallocated'}}
        books.write_text(json.dumps(fixture));before=books.read_bytes();sequence=json.loads(auth.read_text()).get('post_sequence');status,data=request('/api/accounts_dashboard.php?entity=TTI');assert status==200,(status,data)
        assert sum(r['amount'] for r in data['summaries']['commodity'])==400,data['summaries']['commodity']
        assert books.read_bytes()==before,'JV/advance display projection must not persist allocations or journals'
        assert json.loads(auth.read_text()).get('post_sequence')==sequence,'Read-only projections must not reserve Post IDs'
        # SODA Centre shares that exact read-only projection with the dashboard.
        fixture['commodityBills']['ROUND']['billDate']='2026-09-13'
        fixture['commodityBills']['ROUND']['receiptAllocations'][0]['dueDate']='2026-09-19'
        fixture['purchaseSodas']={};fixture['purchaseSodaLiftings']={};fixture['events']={}
        for index,bill_id in enumerate(['ROUND','ADVANCE'],1):
            soda_no='2699'+str(index);sid='S'+str(index);lifting_id='L'+str(index)
            fixture['commodityBills'][bill_id]['sodas']=[soda_no]
            fixture['purchaseSodas'][sid]={'id':sid,'entity':'TTI','sodaNo':soda_no,'sodaDate':'2026-09-14','commodity':'RICE','productStage':'READY','qtyFromKg':27000,'qtyToKg':27000,'expectedTrucks':1,'readyRoute':'EX_MILL','status':'Open'}
            fixture['purchaseSodaLiftings'][lifting_id]={'entity':'TTI','sourceSodaId':sid,'soda':soda_no,'liftingId':lifting_id,'kg':27000,'date':'2026-09-20'}
            fixture['events'][lifting_id]={'entity':'TTI','sourceKey':'EXMILL|'+sid+'|'+lifting_id,'billId':bill_id,'status':'Accepted / Liability Posted'}
        books.write_text(json.dumps(fixture));before=books.read_bytes();sequence=json.loads(auth.read_text()).get('post_sequence')
        status,data=request('/api/purchase_sodas.php?entity=TTI');assert status==200,(status,data)
        sodas={r['sodaNo']:r for r in data['sodas']}
        assert sodas['26991']['calculatedStatus']=='Completed' and sodas['26991']['payableOutstanding']==0,sodas
        assert sodas['26992']['calculatedStatus']=='Payment Pending' and sodas['26992']['payableOutstanding']==400,sodas
        assert books.read_bytes()==before and json.loads(auth.read_text()).get('post_sequence')==sequence,'SODA read wrote records or reserved Post IDs'
        fixture['supplierAdvances']['ADV']['amount']=700;books.write_text(json.dumps(fixture));before=books.read_bytes()
        status,data=request('/api/purchase_sodas.php?entity=TTI');assert status==200 and all(r['calculatedStatus']=='Completed' for r in data['sodas']),(status,data)
        assert books.read_bytes()==before
        print('PASS dashboard source-linked settlements, paid/cancelled/draft exclusion, partial/held balances, company separation and zero financial writes')
    finally:
        server.terminate();server.wait(timeout=10);log.close()
