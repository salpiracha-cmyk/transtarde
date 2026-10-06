"""Real opening JV and ledger endpoints against disposable books and users."""
import json, os, pathlib, shutil, socket, subprocess, tempfile, time
import urllib.request, urllib.error, urllib.parse

ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='opening-jv-qa-') as tmp:
    root=pathlib.Path(tmp)
    for folder in ['api','accounts','data']: (root/folder).mkdir()
    for name in ['journal_vouchers.php','opening_balance_core.php','accounts_ledger_browser.php','accounts_reference.php','tg_remittance_core.php','fi_credit_advice_link.php','receipt_invoice_links.php']:
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
    function tt_list_masters(){return ['business_parties'=>[['id'=>'P1','values'=>['ACME RICE']]],'banks'=>[
      ['id'=>'B1','values'=>['Company Account','TTI','','TTI','PK BANK','','Pakistan','PKR','123','','','','','Active']],
      ['id'=>'B2','values'=>['Company Account','TG','','TG','UAE BANK','','UAE','USD','456','','','','','Active']]]];}
    ''')
    store={'revision':1,'journals':{},'jvDrafts':{},'supplierBills':{'BILL-EXISTING':{'sentinel':True}}}
    books=root/'data/accounts.json';books.write_text(json.dumps(store))
    with socket.socket() as sock: sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    env=os.environ.copy()
    for key in ['TT_DB_HOST','TT_DB_NAME','TT_DB_USER','TT_DB_PASS']: env.pop(key,None)
    server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root)],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    def call(body=None,user='admin',entity='TTI',scope=True,endpoint='journal_vouchers.php',**query):
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
        body=post(party=' acme   rice ',note='OLD PAYABLE')
        status,data=call(body,user='director');assert status==200,data
        jid=data['result']['journalId'];saved=books.read_bytes()
        j=json.loads(saved)['journals'][jid]
        assert j['date']=='2026-07-01' and j['narration']=='OPENING BALANCE B/F — OLD PAYABLE'
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
        assert call(post(account='1110',bankId='B2',party='',amount=100),entity='TG')[0]==422,'Foreign bank needs opening rate'
        status,data=call(post(account='1110',bankId='B2',party='',side='Debit',amount=100,rate=3.67),entity='TG');assert status==200,data
        j=json.loads(books.read_text())['journals'][data['result']['journalId']]
        assert j['lines'][0]['debit']==367 and j['lines'][0]['bankDebit']==j['lines'][0]['nativeDebit']==100
        assert j['lines'][1]['credit']==367
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
        print('Opening JV HTTP: privileged routes, company scope, fixed date/narration, balanced debit/credit, bank/native amounts, party ledger, replay/duplicate prevention, disable/re-enable, reversal and ordinary JV passed.')
    finally:server.terminate();server.wait(timeout=5)
