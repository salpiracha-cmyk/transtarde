"""Exercise the real Exports operations endpoint against a disposable oversized book."""
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
from urllib.error import HTTPError
from urllib.request import Request, urlopen

ROOT = Path(__file__).resolve().parents[2]
KEY = 'transtrade_export_v3_operational'
with tempfile.TemporaryDirectory(prefix='exports-scoped-save-') as tmp:
    app = Path(tmp)
    shutil.copytree(ROOT / 'api', app / 'api')
    for filename in ['backup_lib.php', 'inventory_reconciliation.php', 'product_stage.php']:
        shutil.copy(ROOT / filename, app / filename)
    (app / 'auth_store.php').write_text("""<?php
define('TT_DATA_DIR', __DIR__.'/data');
function tt_require_login(){
    if(($_GET['actor']??'')==='operator')return ['role'=>'Exports','id'=>9,'full_name'=>'Fixture Operator','permissions'=>['Exports'=>['View','Create','Edit']]];
    if(($_GET['actor']??'')==='staff')return ['role'=>'Staff','id'=>8,'full_name'=>'Fixture Exports Staff','permissions'=>['Exports'=>['bags'=>['View','Create','Edit']]]];
    return ['role'=>'Super Admin','id'=>7,'full_name'=>'Fixture Owner'];
}
function tt_verify_csrf($v){return $v==='fixture';}
function tt_ensure_data_dir(){}
function tt_release_read_session(){}
function tt_list_masters(){return [];}
function tt_user_business_entity($u,$e,$a){return $u['role']!=='Staff'||$e==='TTI';}
function tt_user_can_module_action($u,$m,$i,$a){return $u['role']==='Super Admin'||($m==='Exports'&&($u['role']==='Exports'||$i==='bags'));}
function tt_user_can_master($u,$i,$a){return false;}
function tt_user_can_open_module($u,$m){return true;}
""")
    (app / 'data').mkdir()
    source = app / 'data' / 'operations.json'
    old = {'id':'OLD','ref':'OLD/001','seller':'TTI','issued':True,'qty':25,'paymentText':'Before'}
    shipment = {'id':'OLD-P','kind':'process','contractRef':'OLD/001','plannedQty':25,'bagOrders':[{'id':'B','historicalContent':'a'*(17*1024*1024)}]}
    workspace = {'contracts':[old], 'shipments':[shipment], 'customers':[{'id':'CU','nextSeq':1}], 'audits':[], 'fi':[]}
    source.write_text(json.dumps({'revision':4,'values':{KEY:json.dumps(workspace)},'meta':{KEY:{'version':4}}}))
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    env = {key:value for key,value in os.environ.items() if not key.startswith('TT_DB_')}
    log = (app / 'server.log').open('w+')
    server = subprocess.Popen(['php','-d','memory_limit=256M','-S',f'127.0.0.1:{port}','-t',str(app)],env=env,stdout=log,stderr=log)
    url = f'http://127.0.0.1:{port}/api/operations.mysql.php?asset_refs=1'

    def send(version, changes, identity=None, staff=False, operator=False):
        body = dict(csrf='fixture',key=KEY,sourceModule='Exports',baseVersion=version,contractChanges=json.dumps(changes))
        if identity: body['contractSave']=identity
        data = json.dumps(body).encode()
        assert len(data)<4096
        try:
            with urlopen(Request(url+('&actor=operator' if operator else '&actor=staff' if staff else ''),data=data,headers={'Content-Type':'application/json'}),timeout=20) as response:
                return response.status, json.load(response)
        except HTTPError as error:
            return error.code, json.load(error)

    try:
        for _ in range(80):
            try:
                with socket.create_connection(('127.0.0.1',port),timeout=.1):
                    break
            except OSError:
                time.sleep(.05)
        wording = [{'action':'set','path':['contracts',{'id':'OLD'},'paymentText'],'value':'New wording'}]
        status, saved = send(4, wording)
        assert status==200, saved
        assert saved['keyVersion']==5
        state=json.loads(saved['value'])
        assert state['contracts'][0]['paymentText']=='New wording' and state['shipments'][0]['bagOrders']==shipment['bagOrders']
        assert send(4, wording)[0]==409
        draft={'id':'NEW','ref':'NEW/001','seller':'TTI','issued':False,'status':'Draft','customerId':'CU'}
        draft_audit={'id':'AUD-QA-DRAFT','area':'Sales Contract','action':'Draft created after Buyer & Reference','detail':'NEW/001','contractRef':'NEW/001'}
        status, saved=send(5,[
            {'action':'append','path':['contracts',None],'value':[draft]},
            {'action':'prepend','path':['audits',None],'value':[draft_audit]},
        ],{'id':'NEW','auditId':draft_audit['id'],'guards':{}})
        assert status==200 and saved['keyVersion']==6 and saved['contractReceipt']['auditId']==draft_audit['id'], saved
        issue_audit={**draft_audit,'id':'AUD-QA-ISSUE','action':'Created'}
        status,saved=send(6,[
            {'action':'set','path':['contracts',{'id':'NEW'},'issued'],'value':True},
            {'action':'set','path':['contracts',{'id':'NEW'},'status'],'value':'Awaiting Customer Confirmation'},
            {'action':'set','path':['contracts',{'id':'NEW'},'qty'],'value':50},
            {'action':'append','path':['shipments',None],'value':[{'id':'NEW-P','kind':'process','contractRef':'NEW/001','plannedQty':50}]},
            {'action':'set','path':['customers',{'id':'CU'},'nextSeq'],'value':2},
            {'action':'prepend','path':['audits',None],'value':[issue_audit]},
        ],{'id':'NEW','auditId':issue_audit['id'],'processId':'NEW-P','guards':{'contracts':{'NEW':draft}}})
        assert status==200 and saved['keyVersion']==7 and saved['contractReceipt']['auditId']==issue_audit['id'], saved
        state=json.loads(saved['value'])
        assert state['contracts'][1]['issued'] and state['contracts'][1]['qty']==50
        assert len(state['shipments'])==2 and state['shipments'][0]['bagOrders']==shipment['bagOrders']
        assert state['customers'][0]['nextSeq']==2
        status,saved=send(7,[
            {'action':'set','path':['contracts',{'id':'NEW'},'qty'],'value':60},
            {'action':'append','path':['contracts',{'id':'NEW'},'versions',None],'value':[{'at':'2026-11-05T00:00:00Z','snapshot':{'qty':50}}]},
            {'action':'set','path':['shipments',{'id':'NEW-P'},'plannedQty'],'value':60},
        ])
        assert status==200 and saved['keyVersion']==8, saved
        state=json.loads(saved['value'])
        assert state['shipments'][1]['plannedQty']==60 and state['contracts'][1]['qty']==60
        assert state['contracts'][1]['versions'][0]['snapshot']['qty']==50
        assert state['shipments'][0]['bagOrders']==shipment['bagOrders']
        original=state['contracts'][1]
        audit={'id':'AUD-QA-AMEND','area':'Sales Contract','action':'Amended','detail':'NEW/001','contractRef':'NEW/001'}
        identity={'id':'NEW','auditId':audit['id'],'processId':'NEW-P','guards':{'contracts':{'NEW':original}}}
        changes=[
            {'action':'set','path':['contracts',{'id':'NEW'},'paymentText'],'value':'Scoped payment'},
            {'action':'prepend','path':['audits',None],'value':[audit]},
        ]
        # An unrelated writer updates the same workspace after the editor opened.
        status,saved=send(8,[{'action':'set','path':['contracts',{'id':'OLD'},'paymentText'],'value':'Concurrent'}])
        assert status==200 and saved['keyVersion']==9, saved
        status,saved=send(8,changes,identity)
        assert status==200 and saved['keyVersion']==10 and saved['contractReceipt']=={'auditId':audit['id'],'id':'NEW'}, saved
        committed=json.loads(saved['value'])
        assert committed['contracts'][0]['paymentText']=='Concurrent' and committed['contracts'][1]['paymentText']=='Scoped payment'
        status,replay=send(8,changes,identity)
        assert status==200 and replay['keyVersion']==10 and replay['contractReceipt']==saved['contractReceipt'], replay
        assert len([a for a in json.loads(replay['value'])['audits'] if a['id']==audit['id']])==1
        changes[-1]['value'][0]['id']='AUD-QA-CONFLICT'
        identity['auditId']='AUD-QA-CONFLICT'
        assert send(8,changes,identity)[0]==409
        # Linked records must exist before the server returns a receipt.
        identity['processId']='MISSING'
        assert send(10,changes,identity)[0]==403
        persisted=json.loads(json.loads(source.read_text())['values'][KEY])
        assert persisted==committed
        # The real restricted Exports path sees one company, with hidden PO rows from another.
        def mill_line(ref, po, index, **extra):
            return {'contractRef':ref,'poNo':po,'line':index,'supplier':'Test Supplier',**extra}
        owner_po={'poNo':'PO-260006','lines':[{'brand':'Hidden'}]}
        staff_root={'contracts':[{'id':'A','ref':'A/001','seller':'TTI'},{'id':'B','ref':'B/001','seller':'BRM'}],
                    'shipments':[{'id':'S1','kind':'process','contractRef':'A/001','seller':'TTI','bagOrders':[]},
                                 {'id':'S2','kind':'process','contractRef':'B/001','seller':'BRM','bagOrders':[owner_po]}],
                    'millSync':{'newExportBags':[{'contractRef':'B/001','poNo':'PO-260006','lineId':i,'brand':'Hidden'} for i in (1,2)]}}
        source.write_text(json.dumps({'revision':20,'values':{KEY:json.dumps(staff_root)},'meta':{KEY:{'version':20}}}))
        po={'poNo':'PO-260007','issuedAt':'2026-11-06T09:00:00Z','lines':[{'brand':'First'},{'brand':'Second'}]}
        handoff={'action':'replaceBagPoLines','path':['millSync','newExportBags'],'shipmentId':'S1','contractRef':'A/001','poNo':po['poNo'],
                 'value':[mill_line('A/001',po['poNo'],i,brand=line['brand']) for i,line in enumerate(po['lines'],1)]}
        po_change={'action':'append','path':['shipments',{'id':'S1'},'bagOrders',None],'value':[po]}
        status,result=send(20,[po_change,handoff],staff=True)
        assert status==200 and result['keyVersion']==21,result
        public=json.loads(result['value'])
        assert [row['id'] for row in public['shipments']]==['S1']
        assert [row['line'] for row in public['millSync']['newExportBags']]==[1,2]
        original=json.loads(json.loads(source.read_text())['values'][KEY])
        assert original['shipments'][1]['bagOrders']==[owner_po] and len(original['millSync']['newExportBags'])==4
        shortened={**po,'lines':po['lines'][:1],'issuedAt':'2026-11-06T09:10:00Z'}
        update={'action':'set','path':['shipments',{'id':'S1'},'bagOrders',0],'value':shortened}
        status,result=send(21,[update,{**handoff,'value':handoff['value'][:1]}],staff=True)
        assert status==200 and result['keyVersion']==22,result
        persisted=json.loads(json.loads(source.read_text())['values'][KEY])
        assert len(persisted['millSync']['newExportBags'])==3
        assert any(row['brand']=='Hidden' for row in persisted['millSync']['newExportBags'])
        assert send(22,[update,{**handoff,'value':[handoff['value'][0]]*2}],staff=True)[0]==422
        assert send(22,[{**handoff,'shipmentId':'S2','contractRef':'B/001','value':[mill_line('B/001',po['poNo'],1)]}],staff=True)[0]==422
        # Existing freight audit events have no ID. The same legacy event may
        # occur twice, while renamed-contract history is hidden from staff.
        legacy={'at':'2026-09-30','type':'FREIGHT AGREEMENT','action':'Updated loading metadata',
                'before':{'bl':{'finalDocument':{'id':'DOC-OLD'},'gdRefs':[{'number':'GD-1'}]}},
                'after':{'loadingProgrammeNo':'LP-OLD'}}
        history=[legacy,dict(legacy),{'id':'AUD-HIDDEN','area':'Sales Contract','contractRef':'RENAMED/OLD','action':'Created'}]
        staff_root['audits']=history
        staff_root['shipments'][0]['bagOrders']=[]
        source.write_text(json.dumps({'revision':30,'values':{KEY:json.dumps(staff_root)},'meta':{KEY:{'version':30}}}))
        with urlopen(url+'&actor=operator') as response:
            visible=json.loads(json.load(response)['values'][KEY])
        assert len(visible['audits'])==2
        draft={'poNo':'PO-FORM','supplier':'Fixture Supplier','deliverTo':'TTI Rice Mills','requiredDate':'2026-11-15',
               'lines':[{'brand':'QA','tare':110,'artworkAttached':True,'artworkData':'fixture','totalBags':5656}]}
        form=subprocess.run(['node',str(ROOT/'tests/exports-v3/bag_po_request_fixture.js')],
                            input=json.dumps({'state':visible,'shipmentId':'S1','order':draft,'auditId':'AUD-FORM'}),
                            text=True,capture_output=True,check=True)
        request=json.loads(form.stdout)
        changes=request['changes']
        identity=request['identity']
        contract=visible['contracts'][0]
        identity['guards']={'bagOrders':visible['shipments'][0]['bagOrders'],
                            'contract':{field:contract.get(field) for field in ['ref','seller','qty','containers','packingUnit','packings']}}
        assert len(changes)==3,changes
        status,unrelated=send(30,[{'action':'set','path':['contracts',{'id':'B'},'paymentText'],'value':'Concurrent unrelated change'}],operator=True)
        assert status==200,unrelated
        status,result=send(30,changes,identity,operator=True)
        assert status==200,result
        persisted=json.loads(json.loads(source.read_text())['values'][KEY])
        assert persisted['audits'][:3]==history and len(persisted['audits'])==4
        assert persisted['shipments'][0]['bagOrders'][0]['poNo']=='PO-FORM'
        assert persisted['shipments'][1]==staff_root['shipments'][1]
        assert result['keyVersion']==32 and result['contractReceipt']['auditId']=='AUD-FORM'
        status,replay=send(30,changes,identity,operator=True)
        assert status==200 and replay['keyVersion']==32,replay
        conflicting=json.loads(json.dumps(changes).replace('AUD-FORM','AUD-CONFLICT'))
        assert send(30,conflicting,{**identity,'auditId':'AUD-CONFLICT'},operator=True)[0]==409
        assert len(json.loads(json.loads(source.read_text())['values'][KEY])['audits'])==4
        # History edits and invented legacy events remain rejected.
        assert send(32,[{'action':'set','path':['audits',0,'action'],'value':'Altered'}],operator=True)[0]==403
        assert send(32,[{'action':'prepend','path':['audits',None],'value':[legacy]}],operator=True)[0]==403
        # The same history is present when a contract is amended. A receipt
        # must survive the scoped projection and preserve every earlier event.
        amendment_audit={'id':'AUD-LEGACY-AMEND','area':'Sales Contract','action':'Amended','contractRef':'A/001'}
        status,result=send(32,[
            {'action':'set','path':['contracts',{'id':'A'},'paymentText'],'value':'Amended terms'},
            {'action':'prepend','path':['audits',None],'value':[amendment_audit]},
        ],{'id':'A','auditId':amendment_audit['id'],'guards':{}},operator=True)
        assert status==200 and result['contractReceipt']['auditId']==amendment_audit['id'],result
        after_amendment=json.loads(json.loads(source.read_text())['values'][KEY])
        assert after_amendment['audits'][:4]==persisted['audits']
        assert after_amendment['shipments']==persisted['shipments']
        assert after_amendment['contracts'][1]['paymentText']=='Concurrent unrelated change'
        print('PASS actual Bag PO handler with legacy duplicate and hidden audit history, stable retry and immutable saved history')
        print('PASS restricted Exports staff can issue and update one Bag PO, preserve hidden POs and all distinct lines, and reject duplicate lines')
        print('PASS scoped Exports HTTP saves: 17 MiB book, draft and issue receipts, linked amendments, unrelated concurrent writes, retry and real conflicts')
    except Exception:
        log.seek(0)
        print(log.read()[-6000:])
        raise
    finally:
        server.terminate()
        server.wait(timeout=10)
        log.close()
