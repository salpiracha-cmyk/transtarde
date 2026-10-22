"""Actual-auth master create/amend/read matrix; financial originals remain unchanged."""
import copy, http.cookiejar, json, os, pathlib, shutil, socket, subprocess, tempfile, time, urllib.error, urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='master-family-') as directory:
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
    def save(kind,values,record_id=None):
        return request(body={'action':'update' if record_id else 'create','type':kind,'values':values,**({'id':record_id} if record_id else {})})
    def unchanged_failure(kind,values,record_id=None):
        before=(auth.read_bytes(),books.read_bytes()); status,data=save(kind,values,record_id)
        assert status==422 and not data['ok'],(kind,status,data.get('error'))
        assert (auth.read_bytes(),books.read_bytes())==before, 'Rejected master changes must preserve original files'
    try:
        for _ in range(60):
            try: request('/fixture-session.php'); break
            except urllib.error.URLError: time.sleep(.05)
        company=['Fixture Research Company','FIX','Pakistan','Pakistan','Exporter','No','Fixture notes','','','','','','','[]','[]','[]','[]','','']
        product=['Rice','Fixture Quality','White','','Pakistan','Contract specific','','5%','','','','','','','','','','Reasonably well milled','Fixture description','Fixture notes','Active','1006.3090']
        cases=[
            ('companies',company,6),
            ('export_customers',['Fixture Buyer','FXB','Export Buyer','Fixture Address','Pakistan','','','','KG','[]','Active','Fixture notes','No','No','No','No','[]','[]','[]','[]','USD','Fixture instructions'],11),
            ('business_parties',['Fixture Broker','FXP','Broker','','','','','','','','Active','Fixture notes','{}'],11),
            ('products',product,19),
            ('purchase_products',['CORN','Fixture Maize','','RAW','KG','','','','Active','Fixture notes',''],9),
            ('mills',['Fixture Warehouse','FIX-WH','Warehouse','','','Active','Fixture notes','BRM'],6),
            ('export_documents',['Fixture Document','Fixture title','Fixture content','Active','Fixture notes'],4),
            ('export_terms',['FIXTURE','Fixture wording','Active'],1),
            ('salary_staff',['Fixture Person','TTI','OFFICE_STAFF','1000','0','0','2026-07-01','','STAFF_COST','No','Active','Fixture notes'],11),
        ]
        ids={}; original_journals=json.loads(books.read_text())['journals']
        for kind,values,note_index in cases:
            status,data=save(kind,values); assert status==200,(kind,status,data.get('error'))
            record_id=data['id']; ids[kind]=record_id
            row=next(r for r in data['masters'][kind] if r['id']==record_id)
            changed=copy.deepcopy(row['values']); changed[note_index]='Amended fixture text'
            status,data=save(kind,changed,record_id); assert status==200,(kind,status,data.get('error'))
            status,data=request(); row=next(r for r in data['masters'][kind] if r['id']==record_id)
            assert row['values'][note_index]=='Amended fixture text',(kind,row)
            assert json.loads(books.read_text())['journals']==original_journals,'Master edits must not post financial entries'
        service=['Fixture Tax Consultant','','Service Provider','','','','','','','','Active','',json.dumps({'service':{'expenseAccountCode':'6700','type':'Tax consultant'}})]
        stable=books.read_bytes();status,data=save('business_parties',service);assert status==200,(status,data)
        service_id=data['id'];saved_service=next(r for r in data['masters']['business_parties'] if r['id']==service_id)
        assert json.loads(saved_service['values'][12])['service']['expenseAccountCode']=='6700' and books.read_bytes()==stable
        assert any(h['code']=='6700' for h in data['options']['service_expense_heads'])
        for code in ['1110','2130','6000','UNKNOWN']:
            bad=copy.deepcopy(service);bad[12]=json.dumps({'service':{'expenseAccountCode':code}});unchanged_failure('business_parties',bad,service_id)
        bad=copy.deepcopy(service);bad[2]='Supplier';unchanged_failure('business_parties',bad,service_id)
        kat=['CORN','Fixture Maize','','RAW','Fixture KAT','2026-07-01','','Active','Fixture notes',json.dumps([{'name':'Moisture','ranges':[{'from':'0','to':'15','value':'0'},{'from':'15','to':'','value':'1'}]}])]
        status,data=save('purchase_kat',kat); assert status==200,(status,data); ids['purchase_kat']=data['id']
        kat[8]='Amended fixture text'; status,data=save('purchase_kat',kat,ids['purchase_kat']); assert status==200,(status,data)
        purchase=next(r for r in data['masters']['purchase_products'] if r['id']==ids['purchase_products'])['values']; purchase[5]=ids['purchase_kat']
        assert save('purchase_products',purchase,ids['purchase_products'])[0]==200
        advanced_values=next(r for r in request()[1]['masters']['export_customers'] if r['id']==ids['export_customers'])['values']
        advanced_payloads=[[{'id':'CONTACT','name':'Fixture Contact','email':'fixture@example.test','phone':'123','designation':'Manager'}],[{'name':'Fixture Consignee','address':'Consignee Road'}],[{'name':'Fixture Notify','address':'Notify Road'}],[{'label':'Billing Office','address':'Billing Road'}]]
        for field,payload in zip(range(16,20),advanced_payloads): advanced_values[field]=json.dumps(payload)
        advanced_values[20]='EUR';advanced_values[21]='Fixture payment instructions'
        assert save('export_customers',advanced_values,ids['export_customers'])[0]==200
        advanced_reader=next(c for c in request('/api/export_customers.php')[1]['customers'] if c['masterId']==ids['export_customers'])
        for key,payload in zip(['contacts','consignees','additionalNotifies','documentAddresses'],advanced_payloads): assert advanced_reader[key]==payload
        assert advanced_reader['defaultCurrency']=='EUR' and advanced_reader['customerInstructions']=='Fixture payment instructions' and advanced_reader['advancedWarnings']==[]
        for field,bad in [(16,'{}'),(16,'[{"name":"Contact","unsupported":"ignored value"}]'),(17,'[{"name":"Missing address"}]'),(18,'broken json'),(19,'[1]'),(20,'UNKNOWN')]:
            invalid=copy.deepcopy(advanced_values);invalid[field]=bad;unchanged_failure('export_customers',invalid,ids['export_customers'])
        # Canonical customer consumer uses the same saved identity and preserves advanced tail on edit.
        status,customers=request('/api/export_customers.php'); assert status==200
        customer=next(c for c in customers['customers'] if c['masterId']==ids['export_customers'])
        assert customer['notes']=='Amended fixture text' and customer['address']=='Fixture Address'
        tail=next(r for r in request()[1]['masters']['export_customers'] if r['id']==customer['masterId'])['values'][16:]
        customer['address']='Revised fixture address'; assert request('/api/export_customers.php',{'action':'upsert','customer':customer})[0]==200
        assert next(r for r in request()[1]['masters']['export_customers'] if r['id']==customer['masterId'])['values'][16:]==tail
        # The native editor rejects incomplete notify parties; the endpoint must
        # not silently discard malformed input or truncate the thirteenth party.
        for invalid in [[{'name':'Notify without address'}], [{'address':'Address without name'}], ['invalid row'], {'name':'Not a list'}, 123,
                        '{"name":"Not a list"}', '{}', '[{"name":', 'null', '123',
                        [{'name':[],'address':{}}], [{'name':'X'*181,'address':'Valid address'}],
                        [{'name':'Valid name','address':'X'*1201}], [{'name':'名'*61,'address':'住所'}],
                        [{'name':f'Notify {i}','address':f'Address {i}'} for i in range(13)]]:
            changed=copy.deepcopy(customer); changed['notifies']=invalid
            before=(auth.read_bytes(),books.read_bytes())
            status,data=request('/api/export_customers.php',{'action':'upsert','customer':changed})
            assert status==422 and not data['ok'],('Malformed notify party accepted',invalid,status)
            assert (auth.read_bytes(),books.read_bytes())==before,'Rejected customer edits must preserve originals'
            generic=next(r for r in request()[1]['masters']['export_customers'] if r['id']==customer['masterId'])['values']
            generic[9]=invalid if isinstance(invalid,str) else json.dumps(invalid)
            unchanged_failure('export_customers',generic,customer['masterId'])
            generic[0]='Invalid New Notify Fixture'
            unchanged_failure('export_customers',generic)
        valid_parties=[{'name':f'Notify {i}','address':f'Address {i}'} for i in range(12)]
        for supplied,expected in [(valid_parties,valid_parties),(json.dumps(valid_parties),valid_parties),
                                  ('Notify One | Full Address One\nNotify Two | Full Address Two',
                                   [{'name':'Notify One','address':'Full Address One'},{'name':'Notify Two','address':'Full Address Two'}]),
                                  ('',[]),([],[])]:
            changed=copy.deepcopy(customer); changed['notifies']=supplied
            status,data=request('/api/export_customers.php',{'action':'upsert','customer':changed})
            assert status==200 and data['customer']['notifies']==expected,(status,data)
            saved=next(r for r in request()[1]['masters']['export_customers'] if r['id']==customer['masterId'])
            assert saved['values'][16:]==tail and json.loads(books.read_text())['journals']==original_journals
        # Existing incomplete historical data is not silently migrated or made
        # uneditable when an owner changes an unrelated master note.
        stored=json.loads(auth.read_text()); legacy=next(r for r in stored['masters']['export_customers'] if r['id']==customer['masterId'])
        legacy[ 'values'][9]='[{"name":"Historical notify without address"}]';auth.write_text(json.dumps(stored))
        legacy_values=copy.deepcopy(legacy['values']);legacy_values[11]='Unrelated note amendment'
        assert save('export_customers',legacy_values,customer['masterId'])[0]==200
        saved=next(r for r in request()[1]['masters']['export_customers'] if r['id']==customer['masterId'])
        assert saved['values'][9]==legacy_values[9] and saved['values'][16:]==tail
        legacy_values[9]=json.dumps(valid_parties)
        assert save('export_customers',legacy_values,customer['masterId'])[0]==200
        legacy_values[9]='Generic Notify | Full generic address'
        assert save('export_customers',legacy_values,customer['masterId'])[0]==200
        canonical_customer=next(c for c in request('/api/export_customers.php')[1]['customers'] if c['masterId']==customer['masterId'])
        assert canonical_customer['notifies']==[{'name':'Generic Notify','address':'Full generic address'}]
        generic_new=copy.deepcopy(legacy_values);generic_new[0]='Valid Generic Notify Fixture';generic_new[1]='VGN'
        status,data=save('export_customers',generic_new);assert status==200
        assert next(c for c in request('/api/export_customers.php')[1]['customers'] if c['masterId']==data['id'])['notifies']==canonical_customer['notifies']
        # Reference options are a separate visible family with their canonical shared reader.
        status,data=request(body={'action':'manage-option','type':'reference_lists','optionAction':'add','optionKey':'packing_types','value':'Fixture Packing'}); assert status==200,(status,data)
        status,data=request(body={'action':'manage-option','type':'reference_lists','optionAction':'rename','optionKey':'packing_types','old':'Fixture Packing','value':'Fixture Packing Revised'}); assert status==200,(status,data)
        assert 'Fixture Packing Revised' in request()[1]['options']['packing_types']
        # Company identity cannot introduce a second set of banks under one legal-book short code.
        duplicate=copy.deepcopy(company); duplicate[0]='Completely Different Fixture Company'; unchanged_failure('companies',duplicate)
        duplicate=copy.deepcopy(company); duplicate[1]='tti'; unchanged_failure('companies',duplicate,ids['companies'])
        for date in ['2026-02-29','2026-13-01','2026-04-31']:
            salary=copy.deepcopy(cases[-1][1]); salary[6]=date; unchanged_failure('salary_staff',salary,ids['salary_staff'])
            bad_kat=copy.deepcopy(kat); bad_kat[5]=date; unchanged_failure('purchase_kat',bad_kat,ids['purchase_kat'])
            broker=copy.deepcopy(cases[2][1]); broker[12]=json.dumps({'buying':[{'amount':1,'basis':'PER_TON','effectiveFrom':date}]}); unchanged_failure('business_parties',broker,ids['business_parties'])
            registration=copy.deepcopy(company); registration[16]=json.dumps([{'type':'Fixture','number':'1','expiryDate':date}]); unchanged_failure('companies',registration,ids['companies'])
        for amount in ['1e999','NaN','invalid','-1']:
            salary=copy.deepcopy(cases[-1][1]); salary[3]=amount; unchanged_failure('salary_staff',salary,ids['salary_staff'])
        broker=copy.deepcopy(cases[2][1]); broker[12]=json.dumps({'buying':[{'amount':'1e999','basis':'PER_TON','effectiveFrom':'2026-07-01'}]}); unchanged_failure('business_parties',broker,ids['business_parties'])
        bad_kat=copy.deepcopy(kat); bad_kat[9]=json.dumps([{'name':'Moisture','ranges':[{'from':'0','to':'15','value':'1e999'}]}]); unchanged_failure('purchase_kat',bad_kat,ids['purchase_kat'])
        salary=copy.deepcopy(cases[-1][1]); salary[6]='2028-02-29'; salary[4]=salary[5]=''; assert save('salary_staff',salary,ids['salary_staff'])[0]==200
        assert json.loads(books.read_text())['journals']==original_journals
        saved_books=books.read_bytes()
        for invalid in ['', '{"journals":', 'null', '[1]', '{"journals":"damaged"}']:
            books.write_text(invalid); before=books.read_bytes(); status,data=save('salary_staff',salary,ids['salary_staff'])
            assert status==500 and not data['ok'] and books.read_bytes()==before,'Damaged books must not be replaced with seed salary data'
        books.write_bytes(saved_books)
        print('PASS 10 visible master families: explicit create/amend/reload, canonical customer/KAT/reference consumers, preserved advanced fields, company identity, calendar/amount rejection and zero financial postings')
    finally: server.terminate(); server.wait(timeout=10); log.close()
