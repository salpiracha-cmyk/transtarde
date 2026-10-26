"""Exercise the real owner/session boundary and paused-site gate in a disposable site."""
import hashlib
import http.cookiejar
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import tempfile
import time
from urllib import error, request

ROOT = Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tti-restore-http-') as temp:
    base = Path(temp)
    app = base / 'app'
    (app / 'api').mkdir(parents=True)
    for name in ['auth_store.php', 'session_store.php', 'master_store.php', 'product_stage.php',
                 'offline_idempotency.php', 'pending_uploads.php', 'backup_lib.php', 'backup_report_exports.php',
                 'restore_jobs.php', 'restore.php', 'login.php', 'qa_account.php', 'api/restore.php']:
        shutil.copy(ROOT / name, app / name)
    (app / 'api/probe.php').write_text("<?php require dirname(__DIR__).'/auth_store.php'; tt_require_login(); echo 'BUSINESS-REACHED';")
    seed = base / 'seed.php'
    seed.write_text(r'''<?php
require $argv[1].'/auth_store.php'; require $argv[1].'/backup_lib.php';
tt_mutate_store(static function(&$s){$s['users']=[['id'=>1,'username'=>'restore-owner','full_name'=>'Fixture owner','role'=>'Super Admin','active'=>true,'password_hash'=>password_hash('FixturePassword123!',PASSWORD_DEFAULT),'session_version'=>1],['id'=>2,'username'=>'restore-staff','role'=>'Accounts','active'=>true,'password_hash'=>password_hash('FixturePassword123!',PASSWORD_DEFAULT)]];});
file_put_contents(TT_DATA_DIR.'/accounts.json','{"fixture":"current"}');
$key=tt_backup_derive_key('FixturePassword123!',$salt=random_bytes(16),200000);$z=new TT_SimpleZipWriter($argv[2]);$map=[];
foreach(['auth.json'=>'{"users":[],"masters":{"fixture":[]}}','accounts.json'=>'{"fixture":"restored"}']as$n=>$plain){$m=tt_backup_encrypt($plain,$key);$stored='Encrypted/'.$n.'.bin';$z->addString($stored,$m['cipher']);unset($m['cipher']);$m['stored']=$stored;$map['System_Recovery/private/'.$n]=$m;}
$z->addString('backup-manifest.json',json_encode(['application'=>'Transtrade','type'=>'full_recovery','schema'=>3,'scope'=>'data','createdAt'=>'2026-10-26T10:00:00Z','encryption'=>['algorithm'=>'AES-256-GCM','salt'=>base64_encode($salt),'iterations'=>200000],'encryptedEntries'=>$map]));$z->close();
''')
    backup = base / 'fixture.zip'
    subprocess.run(['php', str(seed), str(app), str(backup)], check=True, capture_output=True)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    log = (base / 'server.log').open('w+')
    env = dict(os.environ, PHP_CLI_SERVER_WORKERS='2')
    server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(app)], env=env,
                              stdout=log, stderr=log, start_new_session=True)
    url = f'http://127.0.0.1:{port}'

    def client(): return request.build_opener(request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def call(c, path, body=None, headers=None):
        req = request.Request(url + path, body, headers or {})
        try:
            with c.open(req, timeout=20) as r: return r.status, r.read().decode()
        except error.HTTPError as e: return e.code, e.read().decode()
    def login(c, username):
        _, html = call(c, '/login.php')
        csrf = re.search(r'name="csrf" value="([^"]+)"', html).group(1)
        from urllib.parse import urlencode
        call(c, '/login.php', urlencode({'username': username, 'password': 'FixturePassword123!', 'csrf': csrf}).encode(), {'Content-Type': 'application/x-www-form-urlencoded'})
        if username == 'restore-owner':
            status, page = call(c, '/restore.php')
            assert status == 200, page
            return re.search(r'csrf:"([^"]+)"', page).group(1)
    def post(c, csrf, body):
        status, raw = call(c, '/api/restore.php', json.dumps(body).encode(), {'Content-Type': 'application/json', 'X-CSRF-Token': csrf})
        return status, json.loads(raw)

    try:
        for _ in range(100):
            try:
                with socket.create_connection(('127.0.0.1', port), timeout=.1): break
            except OSError: time.sleep(.05)
        owner, staff, anon = client(), client(), client()
        assert call(anon, '/api/restore.php')[0] == 401
        csrf = login(owner, 'restore-owner')
        login(staff, 'restore-staff')
        assert call(staff, '/api/restore.php')[0] == 403
        assert post(owner, 'wrong-csrf', {'action': 'start'})[0] == 419
        data = backup.read_bytes()
        status, s = post(owner, csrf, {'action': 'start', 'name': 'fixture.zip', 'size': len(data), 'chunks': [hashlib.sha256(data).hexdigest()]})
        assert status == 200, s
        job = s['id']
        headers = {'X-CSRF-Token': csrf, 'X-Restore-Id': job, 'X-Restore-Offset': '0', 'Content-Type': 'application/octet-stream'}
        assert call(owner, '/api/restore.php?action=chunk', data, headers)[0] == 200
        assert call(owner, '/api/restore.php?action=chunk', data, headers)[0] == 200  # retry after lost reply
        assert post(owner, csrf, {'action': 'verify', 'id': job, 'password': 'WrongPassword123!'})[0] == 422
        status, s = post(owner, csrf, {'action': 'verify', 'id': job, 'password': 'FixturePassword123!'})
        assert status == 200 and s['state'] == 'verified', s
        assert call(owner, '/api/probe.php')[0] == 200
        assert post(owner, csrf, {'action': 'confirm', 'id': job, 'confirmation': ''})[0] == 422
        # A writer already inside the shared gate must drain before confirm.
        hold = base / 'hold.php'
        hold.write_text("<?php $h=fopen($argv[1],'c+');flock($h,LOCK_SH);echo \"locked\\n\";fflush(STDOUT);fgets(STDIN);")
        held = subprocess.Popen(['php', str(hold), str(base / 'transtrade_private/restore-request.lock')], stdin=subprocess.PIPE, stdout=subprocess.PIPE, text=True)
        assert held.stdout.readline().strip() == 'locked'
        assert post(owner, csrf, {'action': 'confirm', 'id': job, 'confirmation': 'RESTORE'})[0] == 503
        held.communicate('\n', timeout=5)
        status, s = post(owner, csrf, {'action': 'confirm', 'id': job, 'confirmation': 'RESTORE'})
        assert status == 200 and s['state'] == 'safety', s
        assert call(staff, '/api/probe.php')[0] == 503
        assert (base / 'transtrade_private/accounts.json').read_text() == '{"fixture":"current"}'
        # Close/reopen a browser during a paused site: owner can authenticate and continue.
        owner = client(); csrf = login(owner, 'restore-owner')
        assert call(owner, '/api/restore.php')[0] == 200
        while s['state'] not in ['done', 'failed', 'rolled_back']:
            status, s = post(owner, csrf, {'action': 'step', 'id': job})
            assert status == 200, s
        if s['state'] != 'done':
            print((base / 'transtrade_private/backups/.website-restore/state.json').read_text())
        assert s['state'] == 'done', s
        assert json.loads((base / 'transtrade_private/accounts.json').read_text()) == {'fixture': 'restored'}
        assert call(staff, '/api/probe.php')[0] == 401  # stale form sessions cannot save later
        owner = client(); csrf = login(owner, 'restore-owner')
        status, again = post(owner, csrf, {'action': 'confirm', 'id': job, 'confirmation': 'RESTORE'})
        assert status == 200 and again['state'] == 'done'
        assert post(owner, csrf, {'action': 'clear', 'id': job})[0] == 200
        assert (base / 'transtrade_private/backups' / s['safetySnapshot'] / 'manifest.json').exists()
        # Admission exceeds the old 512 MiB whole-request limit without allocating it.
        large = 600 * 1048576
        status, large_job = post(owner, csrf, {'action': 'start', 'name': 'large.zip', 'size': large, 'chunks': ['0'*64] * 600})
        assert status == 200 and large_job['size'] == large
        print('WEBSITE_RESTORE_HTTP_AUTH_CSRF_PAUSE_RESUME_LARGE_UPLOAD_OK')
    except Exception:
        log.flush()
        print((base / 'server.log').read_text()[-6000:])
        raise
    finally:
        import signal
        os.killpg(server.pid, signal.SIGTERM)
        server.wait(timeout=10)
        log.close()
