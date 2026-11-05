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
function tt_require_login(){return ['role'=>'Super Admin','id'=>7,'full_name'=>'Fixture Owner'];}
function tt_verify_csrf($v){return $v==='fixture';}
function tt_ensure_data_dir(){}
function tt_release_read_session(){}
function tt_list_masters(){return [];}
function tt_user_business_entity($u,$e,$a){return true;}
function tt_user_can_module_action($u,$m,$i,$a){return true;}
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

    def send(version, changes):
        data = json.dumps(dict(csrf='fixture',key=KEY,sourceModule='Exports',baseVersion=version,contractChanges=json.dumps(changes))).encode()
        assert len(data)<4096
        try:
            with urlopen(Request(url,data=data,headers={'Content-Type':'application/json'}),timeout=20) as response:
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
        status, saved=send(5,[{'action':'append','path':['contracts',None],'value':[draft]}])
        assert status==200 and saved['keyVersion']==6, saved
        status,saved=send(6,[
            {'action':'set','path':['contracts',{'id':'NEW'},'issued'],'value':True},
            {'action':'set','path':['contracts',{'id':'NEW'},'status'],'value':'Awaiting Customer Confirmation'},
            {'action':'set','path':['contracts',{'id':'NEW'},'qty'],'value':50},
            {'action':'append','path':['shipments',None],'value':[{'id':'NEW-P','kind':'process','contractRef':'NEW/001','plannedQty':50}]},
            {'action':'set','path':['customers',{'id':'CU'},'nextSeq'],'value':2},
        ])
        assert status==200 and saved['keyVersion']==7, saved
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
        print('PASS scoped Exports HTTP saves: wording, new draft, issue and linked quantity amendment on a 17 MiB book')
    except Exception:
        log.seek(0)
        print(log.read()[-6000:])
        raise
    finally:
        server.terminate()
        server.wait(timeout=10)
        log.close()
