"""Exercise company-bank deactivation against a disposable PHP store."""
import json
import pathlib
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[2]


def bank(bank_id, currency="PKR", default=False):
    return {
        "id": bank_id,
        "bankName": "Fixture Bank",
        "accountTitle": "Historical account " + bank_id,
        "accountNumber": "001" + bank_id,
        "currency": currency,
        "status": "Active",
        "isDefault": default,
    }


with tempfile.TemporaryDirectory(prefix="company-bank-qa-") as temp:
    root = pathlib.Path(temp)
    (root / "api").mkdir()
    (root / "data").mkdir()
    shutil.copy(ROOT / "api/masters.php", root / "api/masters.php")
    banks = [bank("old", default=True), bank("new"), bank("spare"), bank("usd", "USD")]
    values = [""] * 18
    values[0] = "Fixture Company"
    values[13] = json.dumps(banks)
    postings = [{"id": "posted-1", "bankAccountId": "old", "amount": 100}]
    initial = {
        "masters": {"companies": [{"id": "company-1", "values": values}]},
        "bank_deletion_requests": [],
        "postings": postings,
    }
    (root / "store.json").write_text(json.dumps(initial))
    journals = [{"id": "posted-1", "status": "Posted", "lines": [{"bankAccountId": "old"}]}]
    (root / "data/accounts.json").write_text(json.dumps({
        "revision": 1,
        "journals": journals,
        "bankAccountSettings": {"old": {"defaultReceiptAccount": True, "defaultPaymentAccount": True, "notes": "Keep"}},
    }))
    (root / "auth_store.php").write_text("""<?php
define('TT_DATA_DIR',__DIR__.'/data');
function tt_ensure_data_dir(){}
function tt_require_login(){return ['role'=>'Super Admin','id'=>1,'username'=>'Fixture'];}
function tt_user_can_access_masters($user){return true;}
function tt_user_can_master($user,$type,$action){return true;}
function tt_user_can_open_module($user,$module){return true;}
function tt_verify_csrf($csrf){return $csrf==='fixture';}
function tt_read_store(){return json_decode(file_get_contents(__DIR__.'/store.json'),true);}
function tt_mutate_store($callback){$data=tt_read_store();$result=$callback($data);file_put_contents(__DIR__.'/store.json',json_encode($data));return $result;}
function tt_list_masters(){return tt_read_store()['masters'];}
function tt_user_visible_masters($user){return tt_list_masters();}
function tt_master_options(){return [];}
function tt_export_finish_options(){return [];}
function tt_audit($id,$username,$message){}
function tt_bank_is_operational_account_type($type){return in_array($type,['Company Account','Proprietor / Owner Account'],true);}
function tt_company_bank_legacy_rows($companies){$out=[];foreach($companies as $company){foreach(json_decode($company['values'][13],true) as $bank){$out[]=['id'=>$bank['id'],'values'=>['Company Account','','','',$bank['bankName'],'','',$bank['currency']]];}}return $out;}
function tt_master_json_array($value){return json_decode($value,true);}
""")
    (root / "api/salary_master_store.php").write_text("<?php function sm_master_rows(){return [];}\n")

    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    server = subprocess.Popen(
        ["php", "-S", f"127.0.0.1:{port}", "-t", str(root)],
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )

    def request(action, **fields):
        body = {"action": action, "csrf": "fixture", "type": "companies", "id": "company-1", **fields}
        req = urllib.request.Request(
            f"http://127.0.0.1:{port}/api/masters.php",
            data=json.dumps(body).encode(),
            headers={"Content-Type": "application/json"},
        )
        try:
            with urllib.request.urlopen(req, timeout=3) as response:
                return response.status, json.load(response)
        except urllib.error.HTTPError as error:
            return error.code, json.load(error)

    def stored():
        return json.loads((root / "store.json").read_text())

    def stored_banks():
        company = stored()["masters"]["companies"][0]
        return {item["id"]: item for item in json.loads(company["values"][13])}

    def accounts():
        return json.loads((root / "data/accounts.json").read_text())

    try:
        for attempt in range(50):
            try:
                request("delete-company-bank", bankId="old")
                break
            except urllib.error.URLError:
                time.sleep(0.1)
        else:
            raise AssertionError("PHP fixture did not start")

        assert stored() == initial, "A missing replacement must not change the store"
        status, _ = request("delete-company-bank", bankId="old", replacementBankId="usd")
        assert status == 422 and stored() == initial, "Replacement must share the currency"
        status, response = request("delete-company-bank", bankId="old", replacementBankId="new")
        assert status == 200, response
        after = stored_banks()
        assert after["old"]["status"] == "Inactive" and not after["old"]["isDefault"]
        assert after["new"]["isDefault"] and after["usd"]["status"] == "Active"
        assert after["old"]["accountNumber"] == "001old", "Keep the historical bank identity"
        assert stored()["postings"] == postings, "Posted transactions must remain untouched"
        flags = accounts()["bankAccountSettings"]
        assert not flags["old"]["defaultReceiptAccount"] and not flags["old"]["defaultPaymentAccount"]
        assert flags["new"]["defaultReceiptAccount"] and flags["new"]["defaultPaymentAccount"]
        assert flags["old"]["notes"] == "Keep" and accounts()["journals"] == journals

        status, response = request("request-bank-deletion", bankId="new", reason="No longer used")
        assert status == 200, response
        request_id = response["request"]["id"]
        status, _ = request("review-bank-deletion", requestId=request_id, decision="Approve")
        assert status == 422 and stored_banks()["new"]["status"] == "Active"
        status, response = request(
            "review-bank-deletion", requestId=request_id, decision="Approve", replacementBankId="usd"
        )
        assert status == 422 and stored_banks()["new"]["status"] == "Active"
        status, response = request(
            "review-bank-deletion", requestId=request_id, decision="Approve", replacementBankId="spare"
        )
        assert status == 200, response
        after = stored_banks()
        assert after["new"]["status"] == "Inactive" and after["spare"]["isDefault"]
        assert stored()["postings"] == postings
        flags = accounts()["bankAccountSettings"]
        assert not flags["new"]["defaultReceiptAccount"] and not flags["new"]["defaultPaymentAccount"]
        assert flags["spare"]["defaultReceiptAccount"] and flags["spare"]["defaultPaymentAccount"]
        assert accounts()["journals"] == journals
        print("Company bank default replacement, historical identity and unchanged postings passed")
    finally:
        server.terminate()
        server.wait(timeout=5)
