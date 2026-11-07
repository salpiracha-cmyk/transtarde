"""Run the actual lookup endpoint against disposable, explicitly QA-labelled books."""
import json
import pathlib
import shutil
import subprocess
import tempfile

ROOT = pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='receipt-lookup-') as tmp:
    root = pathlib.Path(tmp)
    (root / 'api').mkdir()
    (root / 'data').mkdir()
    shutil.copy(ROOT / 'api/commodity_lookup.php', root / 'api')
    (root / 'auth_store.php').write_text('''<?php
define('TT_DATA_DIR',__DIR__.'/data');
function tt_require_login(){return [];}
function tt_user_can_open_module($u,$m){return true;}
function tt_user_can_access_entity($u,$e,$a){return $e==='TTI';}
function tt_ensure_data_dir(){}
function tt_broker_profiles(){return [];}
function tt_product_identity(...$a){return ['baseVariety'=>'Corn','riceType'=>'','productStage'=>'RAW','displayName'=>'Corn'];}
''')
    (root / 'api/commodity_bill_calculation.php').write_text('''<?php
function tt_bill_operational_values(){return [];}
function tt_bill_bag_defaults($m,$v){return ['emptyBagWeightGrams'=>0];}
''')
    (root / 'runner.php').write_text('''<?php
$_GET=['entity'=>$argv[1]];$_SERVER['REQUEST_METHOD']='GET';
register_shutdown_function(function(){fwrite(STDERR,'STATUS:'.(http_response_code()?:200));});
require __DIR__.'/api/commodity_lookup.php';
''')
    path = root / 'data/accounts.json'
    books = {'revision': 17, 'events': {}, 'journals': {}, 'postingIdentities': {}}
    for i, meta in enumerate([{'broker': 'QA BULK CONTRACTOR'}, {'truck': 'QA-123'}, {'broker': 'REAL BROKER'}]):
        jid, eid = f'J{i}', f'E{i}'
        books['events'][eid] = {'id': eid, 'eventType': 'COMMODITY_RECEIPT_ACCEPTED', 'entity': 'TTI', 'journalId': jid, 'postingIdentity': eid}
        books['journals'][jid] = {'id': jid, 'entity': 'TTI', 'date': '2026-09-23', 'meta': {'commodity': 'CORN', **meta}, 'totalDebit': 100}
        books['postingIdentities'][eid] = {'journalId': jid}
    path.write_text(json.dumps(books))
    before = path.read_bytes()

    def request(entity='TTI'):
        r = subprocess.run(['php', str(root / 'runner.php'), entity], text=True, capture_output=True, check=True)
        return int(r.stderr.rsplit('STATUS:', 1)[1]), json.loads(r.stdout)

    for _ in range(2):
        status, response = request()
        assert status == 200 and len(response['receipts']) == 3, response
        assert path.read_bytes() == before, 'Reading changed journals/events/posting identities or migration flags'
    assert request('BRM')[0] == 403
    assert path.read_bytes() == before
    path.unlink()
    assert request()[0] == 200
    assert not path.exists(), 'Reading empty books created Accounts storage'
    print('PASS actual commodity lookup: labelled receipts retained; repeated/denied/empty GET never changes books')
