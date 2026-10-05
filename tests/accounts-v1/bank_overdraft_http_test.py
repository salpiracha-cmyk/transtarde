"""Actual bank endpoints accept overdrafts with balanced currency accounting."""
import json, os, pathlib, shutil, socket, subprocess, tempfile, time, urllib.request, urllib.error
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='bank-overdraft-qa-') as temp:
    root=pathlib.Path(temp)
    for folder in ['api','accounts','data']:(root/folder).mkdir()
    for name in ['tg_remittance_core.php', 'receipt_invoice_links.php', 'fi_credit_advice_link.php', 'tg_bank_transactions.php','tg_bank_transfer.php','internal_bank_transfers.php','retention_remittances.php','accounts_bank_payment.php']:shutil.copy(ROOT/'api'/name,root/'api'/name)
    for name in ['accounting_master_v1.json','settlement_policy_v1.json','export_realization_policy_v1.json']:shutil.copy(ROOT/'accounts'/name,root/'accounts'/name)
    def bank(id,entity,currency):return {'id':id,'values':['Company Account',entity,'',entity,'Fixture Bank','','',currency,'12345','','','Retention','','Active']}
    proprietor=bank('owner','TG','USD');proprietor['values'][0]='Proprietor / Owner Account';proprietor['values'][2]='Fixture Owner'
    tti_personal=bank('tti-owner','TTI','PKR');tti_personal['values'][0]='Personal Account';tti_personal['values'][2]='TTI Owner'
    unassigned=bank('unassigned','','USD');unassigned['values'][0]='Personal Account';unassigned['values'][2]='Unassigned Owner'
    masters={'banks':[bank('usd','TG','USD'),bank('usd2','TG','USD'),bank('aed','TG','AED'),bank('ret','TTI','USD'),bank('brm','BRM','PKR'),proprietor,tti_personal,unassigned]}
    (root/'masters.json').write_text(json.dumps(masters));(root/'data/accounts.json').write_text(json.dumps({'journals':{}}))
    (root/'auth_store.php').write_text('''<?php
    define('TT_DATA_DIR',__DIR__.'/data');
    function tt_ensure_data_dir(){}
    function tt_list_masters(){return json_decode(file_get_contents(__DIR__.'/masters.json'),true);}
    function tt_read_store(){return ['masters'=>tt_list_masters()];}
    function tt_require_login(){$scope=(string)($_GET['scope']??'');return $scope!==''?['role'=>'Accountant','id'=>2,'username'=>'Scoped','scope'=>$scope,'permissions'=>['Accounts'=>['entity-'.strtolower($scope)=>['Create']]]]:['role'=>'Super Admin','id'=>1,'username'=>'Fixture'];}
    function tt_user_can_open_module($user,$module){return true;}
    function tt_user_can_access_entity($user,$entity,$action){return ($user['role']??'')==='Super Admin'||($user['scope']??'')===$entity;}
    function tt_verify_csrf($csrf){return $csrf==='fixture';}
    function tt_bank_can_transact($id){return in_array($id,['usd','usd2','aed','ret','brm','tti-owner']);}
    function tt_bank_is_retention($id,$store){return $id==='ret';}
    function tt_master_options(){return ['currencies'=>['USD','AED','PKR']];}
    function tt_company_fx_rate($entity,$from,$to){return $from===$to?1:($from==='AED'?1/3.67:3.67);}
    function tt_next_post_id(array $existing, string $module='Accounts', string $area='Journal', ?string $date=null): string {
        $year=substr($date ?: date('Y-m-d'),0,4);$n=count($existing)+1;
        do {$id='POST-'.$year.'-'.str_pad((string)$n,5,'0',STR_PAD_LEFT);$n++;} while (isset($existing[$id]));
        return $id;
    }
    ''')
    with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
    server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root)],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    def post(endpoint,scope='',**body):
        request=urllib.request.Request(f'http://127.0.0.1:{port}/api/{endpoint}.php?entity={body.get('entity','TG')}&scope={scope}',data=json.dumps({'csrf':'fixture','date':'2026-10-01','bankPaymentMethod':'ONLINE_BANKING',**body}).encode(),headers={'Content-Type':'application/json'})
        try:
            with urllib.request.urlopen(request,timeout=5) as res:return res.status,json.load(res)
        except urllib.error.HTTPError as e:return e.code,json.load(e)
    def assert_post(endpoint,**body):
        status,data=post(endpoint,**body);assert status==200,(endpoint,status,data)
        journal=data['journal'];assert journal['totalDebit']==journal['totalCredit'],journal
        return data
    try:
        for attempt in range(50):
            try:
                urllib.request.urlopen(f'http://127.0.0.1:{port}/masters.json',timeout=1).close();break
            except urllib.error.URLError:time.sleep(.1)
        def destinations(entity,scope=''):
            with urllib.request.urlopen(f'http://127.0.0.1:{port}/api/internal_bank_transfers.php?entity={entity}&scope={scope}',timeout=3) as response:
                return {row['id'] for row in json.load(response)['destinations']}
        assert 'owner' in destinations('TG')
        assert 'tti-owner' not in destinations('TG')
        assert 'unassigned' not in destinations('TG')
        assert 'tti-owner' in destinations('TTI')
        assert 'owner' not in destinations('TTI')
        assert 'tti-owner' in destinations('BRM')
        assert 'unassigned' not in destinations('BRM')
        assert 'tti-owner' not in destinations('BRM','BRM')
        assert post('internal_bank_transfers',scope='BRM',entity='BRM',action='post_transfer',sourceBankId='brm',destinationBankId='tti-owner',amount=10,narration='Unauthorised cross-company')[0]==422
        # Empty USD account and optional online ref. Repeat from negative balance.
        for amount in [100,150]:assert_post('tg_bank_transactions',action='post_payment',bankAccountId='usd',counterparty='Supplier',paymentType='SUPPLIER_ADVANCE',amountNative=amount,rate=3.67)
        assert post('tg_bank_transactions',action='post_payment',bankAccountId='usd',counterparty='Supplier',paymentType='SUPPLIER_ADVANCE',amountNative=10,rate=3.67,bankPaymentMethod='CHEQUE')[0]==422
        assert_post('tg_bank_transfer',action='post_transfer',direction='USD_TO_AED',usdAmount=500,rate=3.67,sourceBankId='usd',destinationBankId='aed',notes='Working capital conversion')
        assert_post('internal_bank_transfers',entity='TG',action='post_transfer',sourceBankId='usd',destinationBankId='usd2',amount=300,narration='Working capital transfer')
        personal=assert_post('internal_bank_transfers',entity='TG',action='post_transfer',sourceBankId='usd',destinationBankId='owner',amount=25,narration='Owner advance')
        assert personal['journal']['sourceType']=='PERSONAL_BANK_ADVANCE'
        cross=assert_post('internal_bank_transfers',entity='BRM',action='post_transfer',sourceBankId='brm',destinationBankId='tti-owner',amount=100,reference='BRM-X-1',narration='Intercompany advance')
        mirror=cross['mirrorJournal'];assert mirror['entity']=='TTI' and mirror['id']!=cross['journal']['id']
        assert [(line['account'],line['debit'],line['credit']) for line in cross['journal']['lines']]==[('1240',100,0),('1110',0,100)]
        assert [(line['account'],line['debit'],line['credit']) for line in mirror['lines']]==[('1110',100,0),('2500',0,100)]
        assert mirror['lines'][0]['bankAccountId']=='tti-owner'
        assert cross['transfer']['mirrorJournalId']==mirror['id']
        assert cross['journal']['meta']['mirrorJournalId']==mirror['id']
        assert mirror['meta']['journalId']==cross['journal']['id']
        assert next(row for row in json.load(urllib.request.urlopen(f'http://127.0.0.1:{port}/api/internal_bank_transfers.php?entity=TTI'))['destinations'] if row['id']=='tti-owner')['entity']=='TTI'
        assert post('internal_bank_transfers',entity='BRM',action='post_transfer',sourceBankId='brm',destinationBankId='tti-owner',amount=100,reference='BRM-X-1',narration='Duplicate advance')[0]==409
        assert post('internal_bank_transfers',entity='BRM',action='post_transfer',sourceBankId='tti-owner',destinationBankId='brm',amount=10,narration='Wrong source')[0]==422
        assert_post('retention_remittances',entity='TTI',action='post_remittance',bankAccountId='ret',payee='Supplier',purpose='Supplies',foreignAmount=200,transactionRate=280,settlementType='SUPPLIER_ADVANCE')
        store=json.loads((root/'data/accounts.json').read_text());assert len(store['journals'])==8
        assert store['internalBankTransfers'][cross['transfer']['id']]['mirrorJournalId']==mirror['id']
        assert sum(l['debit']-l['credit'] for j in store['journals'].values() if j['entity']=='TTI' for l in j['lines'] if l.get('bankAccountId')=='tti-owner')==100
        assert not any(l.get('bankAccountId')=='tti-owner' for j in store['journals'].values() if j['entity']=='BRM' for l in j['lines'])
        assert sum(l.get('bankDebit',0)-l.get('bankCredit',0) for j in store['journals'].values() for l in j['lines'] if l.get('bankAccountId')=='usd')==-1075
        assert all(j['totalDebit']==j['totalCredit'] for j in store['journals'].values())
        print('TG banking and retention, BRM-to-TTI intercompany advance, and mirrored company books post balanced journals')
    finally:server.terminate();server.wait(timeout=5)
