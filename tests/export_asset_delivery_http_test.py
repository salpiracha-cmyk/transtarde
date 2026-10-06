#!/usr/bin/env python3
"""Exercise the production asset route against disposable storage."""
import base64
import hashlib
import http.client
import json
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time

root=Path(__file__).resolve().parents[1]
pixel=base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l1sAAAAASUVORK5CYII=")
public="data:image/png;base64,"+base64.b64encode(pixel).decode()
private="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=="
public_hash=hashlib.sha256(public.encode()).hexdigest()
private_hash=hashlib.sha256(private.encode()).hexdigest()
with tempfile.TemporaryDirectory(prefix="tt-export-assets-") as folder:
    target=Path(folder)
    (target/"exports/assets").mkdir(parents=True)
    (target/"private").mkdir()
    shutil.copy(root/"export-assets.php",target/"export-assets.php")
    shutil.copy(root/"exports/runtime_assets.php",target/"exports/runtime_assets.php")
    for name in ["inventory_reconciliation.php","product_stage.php"]:
        shutil.copy(root/name,target/name)
    (target/"exports/app.js").write_text("const image='assets/TG_sign.png'; const literal='$1';")
    (target/"exports/app.css").write_text("body{color:#222}")
    (target/"exports/release-theme.css").write_text("body{background:white}")
    for name in ["TTI_header.png","TTI_sign.png","BRM_header.png","BRM_sign.png","TG_header.png","TG_footer.png","TG_sign.png","KCCI_COO_letterpad.jpg"]:
        (target/"exports/assets"/name).write_bytes(pixel)
    (target/"auth_store.php").write_text("""<?php
define('TT_DATA_DIR',__DIR__.'/private');
function tt_require_login():array{
 $role=$_SERVER['HTTP_X_TEST_ROLE']??'';
 if(!in_array($role,['Exports','Mill'],true)){http_response_code(401);exit;}
 return ['role'=>$role];
}
function tt_user_can_open_module(array $user,string $module):bool{return $user['role']===$module;}
function tt_release_read_session():void{}
""")
    store={"revision":4,"values":{"transtrade_export_v3_operational":json.dumps({"shipments":[{"id":"LOT-1","artworkData":public}]}),"tt34ghati":json.dumps({"artworkData":private})},"meta":{}}
    storage=target/"private/operations.json"
    storage.write_text(json.dumps(store))
    before=storage.read_bytes()
    with socket.socket() as sock:
        sock.bind(("127.0.0.1",0));port=sock.getsockname()[1]
    process=subprocess.Popen(["php","-S",f"127.0.0.1:{port}","-t",folder],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    try:
        deadline=time.monotonic()+10
        while True:
            try:
                with socket.create_connection(("127.0.0.1",port),timeout=.2):break
            except OSError:
                if time.monotonic()>deadline:raise RuntimeError("PHP asset fixture did not start")
                time.sleep(.05)
        def request(query,role="Exports",method="GET",extra=None):
            connection=http.client.HTTPConnection("127.0.0.1",port,timeout=10)
            headers={"X-Test-Role":role,**(extra or {})}
            connection.request(method,"/export-assets.php?"+query,headers=headers)
            response=connection.getresponse()
            result=(response.status,dict(response.getheaders()),response.read())
            connection.close();return result
        assert request("name=app.js",role="")[0]==401
        assert request("name=app.js",role="Mill")[0]==403
        status,headers,script=request("name=app.js")
        assert status==200
        assert b"/export-assets.php?name=TG_sign.png&v=" in script
        assert b"$1" in script
        assert headers["Content-Type"].startswith("application/javascript")
        assert request("name=app.js",extra={"If-None-Match":headers["ETag"]})[0]==304
        assert request("name=../auth_store.php")[0]==404
        assert request("name=app.js",method="POST")[0]==405
        for role in ["Exports","Mill"]:
            status,headers,body=request("name=legacy&sha256="+public_hash,role)
            assert status==200 and body==pixel
            assert headers["Content-Type"]=="image/png"
            assert request("name=legacy&sha256="+public_hash,role,extra={"If-None-Match":headers["ETag"]})[0]==304
        assert request("name=legacy&sha256="+private_hash)[0]==404
        assert request("name=legacy&sha256="+("0"*64))[0]==404
        assert request("name=release-theme.css")[0]==200
        assert storage.read_bytes()==before,"Asset reads must never migrate or mutate stored records"
        print("PASS protected assets: role guards, cache validation, exact image bytes, private-store exclusion and unchanged storage.")
    finally:
        process.terminate();process.wait(timeout=10)
