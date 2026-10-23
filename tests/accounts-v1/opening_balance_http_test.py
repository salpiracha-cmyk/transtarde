"""Real opening JV and ledger endpoints against disposable books and users."""
import hashlib, json, os, pathlib, re, shutil, socket, subprocess, tempfile, time
import urllib.request, urllib.error, urllib.parse

ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='opening-jv-qa-') as tmp:
    root=pathlib.Path(tmp)
    shutil.copy(ROOT/'document_filenames.php',root/'document_filenames.php')
    for folder in ['api','accounts','data']: (root/folder).mkdir()
    for name in ['supplier_opening_core.php','accounts_subaccounts_core.php','accounts_receipts_core.php','accounts_ledger_visibility.php','assets_registry_core.php','jv_decisions_core.php','journal_vouchers.php','opening_balance_core.php','accounts_chart_word.php','accounts_chart_word_core.php','service_ledger_groups.php','accounts_ledger_browser.php','accounts_read_policy.php','customer_receivables_core.php','accounts_post_delete_core.php','accounts_post_delete_tg.php','tg_native_currency.php','accounts_reference.php','tg_remittance_core.php','fi_credit_advice_link.php','receipt_invoice_links.php', 'tg_native_currency.php']:
        shutil.copy(ROOT/'api'/name,root/'api'/name)
    for path in (ROOT/'accounts').glob('*.json'): shutil.copy(path,root/'accounts'/path.name)
    (root/'auth_store.php').write_text('''<?php
    define('TT_DATA_DIR',__DIR__.'/data');
    if(!function_exists('mb_strlen')){function mb_strlen($s){return strlen($s);}}
    function tt_require_login(){
      $role=$_GET['user']??'admin';$a=['View','Create','Edit','Approve'];
      $p=$role==='director'?['Directors'=>['View','Approve','entity-tti'=>['View','Approve']]]:
        ($role==='viewer'?['Accounts'=>'all','Directors'=>['View']]:['Accounts'=>'all']);
      return ['role'=>$role==='admin'?'Super Admin':($role==='director'?'Director':'Accounts'),'permissions'=>$p,'id'=>$role==='admin'?1:2,'username'=>$role];
    }
    function tt_user_can_open_module($u,$m){$p=$u['permissions'][$m]??[];return $u['role']==='Super Admin'||$p==='all'||in_array('View',(array)$p,true);}
    function tt_user_can_access_entity($u,$e,$a){return $u['role']==='Super Admin'||$e==='TTI';}
    function tt_user_accounts_entities($u){return $u['role']==='Super Admin'?['TTI','BRM','TG']:['TTI'];}
    function tt_ensure_data_dir(){}
    function tt_verify_csrf($v){return $v==='fixture';}
    function tt_next_post_id($existing,$module='Accounts',$area='Journal',$date=null){$n=count($existing)+1;do{$id='2026-'.str_pad((string)$n++,5,'0',STR_PAD_LEFT);}while(isset($existing[$id]));return $id;}
    function tt_business_party_categories($v){return array_filter(array_map('trim',preg_split('/[;,|]/',(string)$v)));}
    function tt_company_fx_rate($entity,$from,$to){return empty($_GET['missingRate'])&&$entity==='TG'&&$from==='USD'&&$to==='AED'?3.67:null;}
    function tt_list_masters(){return ['business_parties'=>[['id'=>'P1','values'=>['ACME RICE','','Supplier']],['id'=>'LAB','values'=>['MILL LABOUR','','Labour Contractor']]],'banks'=>[
      ['id'=>'B1','values'=>['Company Account','TTI','','TTI','PK BANK','','Pakistan','PKR','123','','','','','Active']],
      ['id'=>'B2','values'=>['Company Account','TG','','TG','UAE BANK','','UAE','USD','456','','','','','Active']],
      ['id'=>'AED','values'=>['Company Account','TG','','TG','UAE BANK','','UAE','AED','789','','','','','Active']],
      ['id'=>'PERSONAL','values'=>['Proprietor / Owner Account','TTI','Abdul Razzak','Abdul Razzak','Meezan','','Pakistan','PKR','222','','','','','Active']]]];}
    ''')
    # Exercise the production permission helper alongside the disposable identity fixture.
    helper=re.search(r'function tt_user_can_module_action\([^\n]*\n.*?\n}',(ROOT/'auth_store.php').read_text(),re.S).group(0)
    with (root/'auth_store.php').open('a') as fixture: fixture.write(helper+'\n')
    store={'revision':1,'journals':{},'jvDrafts':{},'supplierBills':{'BILL-EXISTING':{'sentinel':True}}}
    books=root/'data/accounts.json';books.write_text(json.dumps(store))
    with socket.socket() as sock: sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    env=os.environ.copy()
    for key in ['TT_DB_HOST','TT_DB_NAME','TT_DB_USER','TT_DB_PASS']: env.pop(key,None)
    server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root)],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    def call(body=None,user='admin',entity='TTI',scope=True,endpoint='journal_vouchers.php',**query):
        if body and body.get('action')=='submit_jv':body={**body,'requestKey':body.get('requestKey') or 'fixture-'+hashlib.sha256(json.dumps(body,sort_keys=True).encode()).hexdigest()}
        params={'entity':entity,'user':user,**query}
        if scope:params['scope']='opening'
        req=urllib.request.Request(f'http://127.0.0.1:{port}/api/{endpoint}?'+urllib.parse.urlencode(params),
          data=None if body is None else json.dumps({'csrf':'fixture','entity':entity,**body}).encode(),headers={'Content-Type':'application/json'})
        try:
            with urllib.request.urlopen(req,timeout=5) as response:return response.status,json.load(response)
        except urllib.error.HTTPError as error:return error.code,json.load(error)
    counter=0
    def post(**kwargs):
        global counter
        counter+=1
        return {'action':'post_opening_balance','requestKey':f'opening-test-{counter}','account':'2110','party':'ACME RICE','side':'Credit','amount':100,'date':'2026-07-01',**kwargs}
    try:
        for _ in range(50):
            try: status,data=call();break
            except urllib.error.URLError:time.sleep(.1)
        assert status==200 and data['openingOnly'] and data['opening']['date']=='2026-07-01',data
        assert '3400' not in {a['code'] for a in data['opening']['accounts']}
        assert call(user='accounts')[0]==403
        assert call(user='viewer')[0]==403,'Accounts all plus Directors View does not grant opening posting'
        assert call(user='accounts',scope=False)[1]['opening']=={'allowed':False}
        assert call(post(),user='accounts',scope=False)[0]==403
        assert call(user='director')[0]==200,'Director-only user can use the restricted route'
        assert call(user='director',entity='BRM')[0]==403
        assert call(post(entity='BRM'),user='director')[0]==403
        assert call({'action':'save_draft'},user='director')[0]==403,'Restricted route cannot write ordinary JVs'
        assert call(post(date='2026-07-02'))[0]==422
        assert call(post(amount=0))[0]==422
        assert call(post(side='Both'))[0]==422
        assert call(post(account='3400',party=''))[0]==422
        assert call(post(party=''))[0]==422
        assert call(post(lines=[]))[0]==422
        assert call(post(csrf='wrong'))[0]==419
        targets=data['opening']['targets'] if 'opening' in data else call()[1]['opening']['targets']
        assert any(t['key']=='business_parties:P1:2110' and t['party']=='ACME RICE' for t in targets)
        assert call(post(targetKey='bank:B2'),entity='TTI')[0]==422,'Foreign company bank cannot be selected by target key'
        assert call(post(targetKey='missing'))[0]==422
        assert any(t['key']=='bank:PERSONAL' for t in targets), 'Linked proprietor bank must be available'
        assert any(t['key']=='business_parties:LAB:2190' for t in targets), 'Labour uses dedicated contractor payable'
        draft={'action':'submit_jv','date':'2026-10-06','narration':'MANUFACTURING LABOUR','lines':[{'account':'5200','subledger':'MILL LABOUR','debit':100,'credit':0},{'targetKey':'business_parties:LAB:2190','account':'2140','subledger':'FORGED','debit':0,'credit':100}]}
        status,result=call(draft,scope=False,user='accounts');assert status==200,result
        lines=json.loads(books.read_text())['jvDrafts'][result['result']['jvId']]['lines'];assert lines[1]['account']=='2190' and lines[1]['subledger']=='MILL LABOUR'
        body=post(targetKey='business_parties:P1:2110',account='6900',party='FORGED PARTY',note='OLD PAYABLE')
        status,data=call(body,user='director');assert status==200,data
        jid=data['result']['journalId'];saved=books.read_bytes()
        j=json.loads(saved)['journals'][jid]
        assert j['date']=='2026-07-01' and j['narration']=='OPENING BALANCE B/F — OLD PAYABLE'
        assert j['lines'][0]['account']=='2110'
        assert j['lines'][0]['credit']==100 and j['lines'][0]['counterparty']=='ACME RICE'
        assert j['lines'][1]['account']=='3400' and j['lines'][1]['debit']==100
        assert j['totalDebit']==j['totalCredit']==100 and j['approvedBy']=='director'
        assert call(body,user='director')[1]['result']['journalId']==jid
        assert books.read_bytes()==saved,'Retry must not post twice or alter revision'
        assert call(post(party='acme.rice'))[0]==409,'Party spacing/punctuation cannot bypass duplicates'
        status,ledger=call(endpoint='accounts_ledger_browser.php',scope=False,category='party',party='ACME RICE',**{'from':'2026-07-02','to':'2026-10-06'})
        assert status==200 and ledger['opening']==-100 and ledger['closing']==-100,ledger
        assert call(post(account='1120',party='',side='Debit',amount=50))[0]==200
        status,data=call(post(account='1110',bankId='B1',party='',side='Debit',amount=1000));assert status==200,data
        j=json.loads(books.read_text())['journals'][data['result']['journalId']]
        assert j['lines'][0]['bankAccountId']=='B1' and j['lines'][0]['bankDebit']==1000
        assert call(post(account='1110',bankId='B2',party=''))[0]==422,'Bank must belong to selected company'
        assert not [l for journal in json.loads(books.read_text())['journals'].values() for l in journal['lines'] if l.get('bankAccountId')=='AED'], 'USD opening cannot alter the separate AED bank'
        # Ordinary first-day bank charges do not count as an imported opening balance.
        state=json.loads(books.read_text());state['journals']['DAY-ONE-FEE']={'id':'DAY-ONE-FEE','entity':'TG','date':'2026-07-01','status':'Posted','sourceType':'BANK_ENTRY','lines':[{'account':'6800','debit':18.28,'credit':0,'currency':'USD','nativeDebit':4.98,'nativeCredit':0},{'account':'1110','bankAccountId':'B2','currency':'USD','bankDebit':0,'bankCredit':4.98,'debit':0,'credit':18.28}]};books.write_text(json.dumps(state));stable=books.read_bytes()
        assert not [b for b in call(entity='TG')[1]['opening']['balances'] if b['bankId']=='B2']
        missing=call(post(account='1110',bankId='B2',party='',amount=100),entity='TG',missingRate='1');assert missing[0]==422 and 'Super Admin' in missing[1]['error'] and books.read_bytes()==stable,missing
        assert call(entity='TG')[1]['opening']['ratesByCurrency']['USD']==3.67
        stale=call(post(account='1110',bankId='B2',party='',amount=100,expectedOpeningRate=3.65,expectedOpeningCurrency='USD'),entity='TG');assert stale[0]==422 and 'review' in stale[1]['error'] and books.read_bytes()==stable,stale

        status,data=call(post(account='1110',bankId='B2',party='',side='Debit',amount=100,rate=999),entity='TG');assert status==200,data
        j=json.loads(books.read_text())['journals'][data['result']['journalId']]
        assert j['lines'][0]['debit']==367 and j['lines'][0]['bankDebit']==j['lines'][0]['nativeDebit']==100
        assert j['lines'][1]['credit']==367 and j['lines'][1]['currency']=='USD' and j['lines'][1]['nativeCredit']==100
        assert j['meta']['openingRate']==3.67,'TG ignores a forged manual rate and reads the approved master'
        stable=books.read_bytes();assert call(post(account='1110',bankId='B2',party='',amount=10),entity='TG')[0]==409 and books.read_bytes()==stable
        bf=next(b for b in call(entity='TG')[1]['opening']['balances'] if b['bankId']=='B2');assert bf['nativeBalance']==100 and bf['currency']=='USD',bf
        party=call(post(account='1210',party='USD Customer',currency='USD',side='Debit',amount=200),entity='TG');assert party[0]==200,party
        pj=json.loads(books.read_text())['journals'][party[1]['result']['journalId']];assert pj['lines'][0]['nativeDebit']==200 and pj['lines'][0]['debit']==734
        assert call(post(account='1210',party='USD Customer',currency='AED',side='Debit',amount=50),entity='TG')[0]==200,'Independent currency B/F is allowed without mixing totals'
        stable=books.read_bytes();assert call(post(account='1210',party='USD Customer',currency='USD',amount=200),entity='TG')[0]==409 and books.read_bytes()==stable
        assert call(post(account='1120',party='',currency='USD',amount=100),entity='TG')[0]==422 and books.read_bytes()==stable
        assert call(post(account='1210',party='Invalid Currency',currency='ZZZ',amount=100),entity='TG')[0]==422 and books.read_bytes()==stable

        status,ledger=call(endpoint='accounts_ledger_browser.php',scope=False,entity='TG',category='bank',account='BANK|B2',**{'from':'2026-07-01','to':'2026-10-06'});assert status==200 and ledger['closing']==95.02,ledger
        status,ledger=call(endpoint='accounts_ledger_browser.php',scope=False,entity='TG',category='bank',account='BANK|AED',**{'from':'2026-07-01','to':'2026-10-06'});assert status==200 and ledger['closing']==0,ledger
        status,ledger=call(endpoint='accounts_ledger_browser.php',scope=False,category='bank',account='BANK|PERSONAL',**{'from':'2026-07-01','to':'2026-10-06'});assert status==200,ledger
        state=json.loads(books.read_text());state['journals']['PRIOR-CLOSING']={'id':'PRIOR-CLOSING','entity':'TTI','date':'2026-06-30','status':'Posted','sourceType':'JV','lines':[{'account':'1230','subledger':'Prior Party','debit':100,'credit':0},{'account':'3200','debit':0,'credit':100}]};books.write_text(json.dumps(state));stable=books.read_bytes();assert call(post(account='1230',party='Prior Party',side='Debit',amount=100))[0]==409 and books.read_bytes()==stable
        setting={'action':'set_opening_balance_enabled','enabled':False,'requestKey':'disable-test-1'}
        assert call(setting,user='director')[0]==403
        assert call(setting)[0]==200
        assert call(post(account='6900',party=''))[0]==422
        assert not call()[1]['opening']['enabled']
        reverse={'action':'reverse_opening_balance','postId':jid,'reason':'CORRECT IMPORT','requestKey':'reverse-test-1'}
        status,data=call(reverse,user='director');assert status==200,data
        assert call(reverse,user='director')[1]['result']==data['result']
        assert call(dict(reverse,requestKey='reverse-test-2'),user='director')[0]==422
        assert call({'action':'set_opening_balance_enabled','enabled':True,'requestKey':'enable-test-1'})[0]==200
        assert call(post())[0]==200,'Reversed opening can be re-entered correctly'
        ordinary={'action':'submit_jv','date':'2026-10-06','narration':'ORDINARY JV','lines':[{'account':'6900','subledger':'OFFICE EXPENSES','debit':20},{'account':'2140','subledger':'ACME RICE','credit':20}]}
        status, result = call(ordinary,user='accounts',scope=False); assert status==200,result
        ordinary['lines'][1]={'account':'3400','credit':20}
        assert call(ordinary,user='accounts',scope=False)[0]==422,'Generic JV cannot bypass clearing-account restriction'
        assert json.loads(books.read_text())['supplierBills']==store['supplierBills']
        # Ordinary JV banks retain their exact account and native balances through approval/reversal.
        status,catalog=call(scope=False);assert status==200
        assert any(x['key']=='bank:B1' for x in catalog['targets'])
        bank_jv={'action':'submit_jv','date':'2026-10-08','narration':'','lines':[{'targetKey':'bank:B1','debit':0,'credit':25},{'account':'1120','debit':25,'credit':0}]}
        status,result=call(bank_jv,scope=False,user='accounts');assert status==200,(status,result);bank_id=result['result']['jvId']
        status,result=call({'action':'approve_jv','id':bank_id},scope=False);assert status==200,(status,result);bank_journal=result['result']['journalId']
        journal=json.loads(books.read_text())['journals'][bank_journal];line=journal['lines'][0]
        assert journal['narration']=='' and line['bankAccountId']=='B1' and line['accountNumber']=='123' and line['bankCredit']==25 and line['nativeCredit']==25
        status,result=call({'action':'reverse_jv','id':bank_id,'date':'2026-10-08','reason':'Correct test bank transfer'},scope=False);assert status==200,(status,result)
        reversed_line=json.loads(books.read_text())['journals'][result['result']['journalId']]['lines'][0]
        assert reversed_line['bankAccountId']=='B1' and reversed_line['bankDebit']==25 and reversed_line['bankCredit']==0 and reversed_line['nativeDebit']==25
        before=books.read_bytes()
        for lines in [[{'account':'1110','credit':25},{'account':'1120','debit':25}],[{'targetKey':'bank:B2','credit':25},{'account':'1120','debit':25}],[{'account':'1120','debit':'bad'},{'account':'1120','credit':25}]]:
            status,result=call({**bank_jv,'lines':lines},scope=False);assert status==422,(status,result);assert books.read_bytes()==before
        foreign={**bank_jv,'lines':[{'targetKey':'bank:B2','debit':367.25,'nativeAmount':100},{'targetKey':'bank:AED','credit':367.25}]}
        status,result=call(foreign,scope=False,entity='TG');assert status==200,(status,result);foreign_id=result['result']['jvId']
        assert result['result']['status']=='Posted','Authorized Super Admin posts directly'
        line=json.loads(books.read_text())['journals'][result['result']['journalId']]['lines'][0]
        assert line['bankDebit']==100 and line['debit']==367.25 and abs(line['rate']-3.6725)<0.000001
        print('Ordinary JV bank/native linkage, optional narration, reversal, malformed amounts and company isolation passed.')
        print('Opening JV HTTP: privileged routes, company scope, fixed date/narration, balanced debit/credit, bank/native amounts, party ledger, replay/duplicate prevention, disable/re-enable, reversal and ordinary JV passed.')
        import zipfile,io,xml.etree.ElementTree as ET
        url=f'http://127.0.0.1:{port}/api/accounts_chart_word.php?user=admin&entity=ALL&to=2026-10-06'
        with urllib.request.urlopen(url) as response:
            assert response.headers['Content-Type'].startswith('application/vnd.openxmlformats')
            z=zipfile.ZipFile(io.BytesIO(response.read()));xml=z.read('word/document.xml');ET.fromstring(xml)
            assert b'Labour Contractors Payable' in xml and b'MILL LABOUR' in xml and b'USD' in xml and b'Meezan' in xml
        try:
            urllib.request.urlopen(url.replace('user=admin','user=director'));assert False,'Director without Accounts access must be denied on Accounts export'
        except urllib.error.HTTPError as error:assert error.code==403
    finally:server.terminate();server.wait(timeout=5)


