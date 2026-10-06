"""Run real authentication entry points in isolated PHP processes, without ports."""
import json
import pathlib
import shutil
import subprocess
import tempfile

ROOT = pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tti-auth-boundary-') as directory:
    base = pathlib.Path(directory)
    app = base / 'app'
    app.mkdir()
    for name in ['auth_store.php', 'session_store.php', 'master_store.php', 'product_stage.php',
                 'offline_idempotency.php', 'backup_lib.php', 'qa_account.php', 'login.php',
                 'change-password.php', 'logout.php', 'recover-admin.php']:
        shutil.copy(ROOT / name, app / name)
    (app / 'api').mkdir()
    shutil.copy(ROOT / 'api/session_activity.php', app / 'api/session_activity.php')
    script = base / 'request.php'
    script.write_text(r'''<?php
$cfg=json_decode($argv[2],true);$_SERVER['REQUEST_METHOD']=$cfg['method']??'GET';
$_SERVER['REQUEST_URI']=$cfg['path']??'/login.php';$_SERVER['REMOTE_ADDR']='192.0.2.40';
$_COOKIE['TRANSTRADE_SESSION']=$cfg['sid']??'';$_GET=$cfg['query']??[];$_POST=$cfg['form']??[];
if(isset($cfg['csrf']))$_SERVER['HTTP_X_CSRF_TOKEN']=$cfg['csrf'];
register_shutdown_function(static function()use($argv){file_put_contents($argv[3],json_encode([
 'status'=>http_response_code()?:200,'user'=>$_SESSION['user_id']??null,
 'csrf'=>$_SESSION['csrf']??null,'sid'=>session_id(),'reached'=>$GLOBALS['reached']??false]));});
$app=$argv[1];
if(($cfg['action']??'')==='seed'){
 require $app.'/auth_store.php';
 tt_mutate_store(static function(&$s)use($cfg){$s['users']=[[
  'id'=>1,'username'=>'fixture','full_name'=>'Fixture','role'=>'Accounts','active'=>$cfg['active']??true,
  'password_hash'=>password_hash('FixturePass123!',PASSWORD_DEFAULT),'session_version'=>2,
  'must_change_password'=>$cfg['flag']??false,'permissions'=>['Accounts'=>'all']],
  ['id'=>99,'username'=>'owner-fixture','role'=>'Super Admin','active'=>true]];});
 if(!empty($cfg['authenticated']))tt_bind_user_session(tt_find_user_by_id(1));
 if(isset($cfg['version']))$_SESSION['auth_version']=$cfg['version'];
 if(isset($cfg['last']))$_SESSION['last_activity_at']=$cfg['last'];
 tt_csrf();
 if(!empty($cfg['recovery']))file_put_contents(TT_DATA_DIR.'/admin-recovery.hash',password_hash('OFFLINEFIXTURECODE123456789ABC',PASSWORD_DEFAULT));
 foreach($cfg['locks']??[]as$scope){$identity=str_starts_with($scope,'admin-recovery')?'super-admin':($scope==='login-account'?'fixture':'all-users');
  tt_auth_rate_mutate(static function(&$s)use($scope,$identity){$s[tt_auth_rate_key($scope,$identity,in_array($scope,['login-address','admin-recovery-address'],true))]=['attempts'=>[],'locked_until'=>time()+1800];});}
}elseif(($cfg['action']??'')==='landing'){
 require $app.'/auth_store.php';echo tt_user_landing_url(tt_find_user_by_id(1));
}elseif(($cfg['action']??'')==='direct'){
 require $app.'/auth_store.php';tt_current_user();$GLOBALS['reached']=true;
}elseif(($cfg['action']??'')==='required'){
 require $app.'/auth_store.php';tt_require_login();$GLOBALS['reached']=true;
}else{require $app.'/'.ltrim($_SERVER['REQUEST_URI'],'/');}
''')

    def run(**cfg):
        meta = base / 'meta.json'
        meta.unlink(missing_ok=True)
        result = subprocess.run(['php', str(script), str(app), json.dumps(cfg), str(meta)],
                                capture_output=True, text=True)
        assert result.returncode == 0, result.stdout + result.stderr
        return json.loads(meta.read_text()), result.stdout

    seed, _ = run(action='seed', authenticated=True, flag=True)
    sid, csrf = seed['sid'], seed['csrf']
    for path in ['/api/masters.php', '/api/operations.php', '/api/export_documents.php',
                 '/api/document_ai.php', '/api/office_agent.php', '/api/export_pdf_assets.php']:
        for method in ['GET', 'POST']:
            meta, output = run(action='direct', path=path, method=method, sid=sid)
            assert meta['status'] == 403 and not meta['reached'], (path, method, meta)
            assert json.loads(output)['ok'] is False and meta['user'] == 1
    for path in ['/module.php', '/accounts/index.php', '/directors/index.php', '/index.php',
                 '/api/change-password.php', '/change-password.php/extra']:
        meta, _ = run(action='required', path=path, sid=sid)
        assert meta['status'] == (403 if path.startswith('/api/') else 303) and not meta['reached']
    assert run(action='landing', sid=sid)[1] == 'change-password.php'
    for path in ['/login.php', '/change-password.php', '/logout.php', '/api/session_activity.php']:
        for method in ['GET', 'POST']:
            meta, _ = run(action='direct', path=path, method=method, sid=sid)
            assert meta['reached'] and meta['user'] == 1
    meta, output = run(path='/api/session_activity.php', method='POST', sid=sid, csrf=csrf)
    assert meta['status'] == 200 and json.loads(output)['ok']
    meta, _ = run(path='/change-password.php', method='POST', sid=sid,
                  form={'csrf': csrf, 'current_password': 'FixturePass123!',
                        'password': 'PrivatePass456!', 'confirm_password': 'PrivatePass456!'})
    assert meta['user'] == 1
    changed_sid = meta['sid']
    meta, _ = run(action='required', path='/api/accounts.php', query={'entity': 'TTI'}, sid=changed_sid)
    assert meta['reached'] and meta['user'] == 1
    assert run(action='required', path='/api/accounts.php', query={'entity': 'TTI'}, sid=sid)[0]['status'] == 401
    for options in [{'active': False}, {'version': 1}, {'last': 1}]:
        seed, _ = run(action='seed', authenticated=True, flag=True, **options)
        meta, _ = run(action='required', path='/api/accounts.php', sid=seed['sid'])
        assert meta['status'] == 401 and meta['user'] is None
    seed, _ = run(action='seed', authenticated=True, flag=True)
    assert run(path='/logout.php', sid=seed['sid'])[0]['user'] is None

    # Only global pressure becomes a delay. Account and address hard limits remain.
    for locks, allowed in [(['login-emergency'], True), (['login-account'], False),
                           (['login-address'], False)]:
        seed, _ = run(action='seed', locks=locks)
        meta, _ = run(path='/login.php', method='POST', sid=seed['sid'],
                      form={'csrf': seed['csrf'], 'username': 'fixture', 'password': 'FixturePass123!'})
        assert (meta['user'] == 1) is allowed, (locks, meta)
    seed, _ = run(action='seed', locks=['login-emergency'])
    meta, _ = run(path='/login.php', method='POST', sid=seed['sid'],
                  form={'csrf': 'wrong', 'username': 'fixture', 'password': 'FixturePass123!'})
    assert meta['user'] is None
    for locks, code, allowed in [(['admin-recovery-emergency'], 'OFFLINEFIXTURECODE123456789ABC', True),
                                  (['admin-recovery-address'], 'OFFLINEFIXTURECODE123456789ABC', False),
                                  (['admin-recovery-emergency'], 'incorrect-code', False)]:
        seed, _ = run(action='seed', recovery=True, locks=locks)
        meta, _ = run(path='/recover-admin.php', method='POST', sid=seed['sid'],
                      form={'csrf': seed['csrf'], 'recovery_code': code,
                            'password': 'RecoveredPrivate123!', 'confirm_password': 'RecoveredPrivate123!'})
        assert (meta['user'] == 99) is allowed, (locks, code, meta)
    seed, _ = run(action='seed', recovery=True, locks=['admin-recovery-emergency'])
    meta, _ = run(path='/recover-admin.php', method='POST', sid=seed['sid'],
                  form={'csrf': 'wrong', 'recovery_code': 'OFFLINEFIXTURECODE123456789ABC',
                        'password': 'RecoveredPrivate123!', 'confirm_password': 'RecoveredPrivate123!'})
    assert meta['user'] is None
    print('PASS offline recovery under aggregate pressure, address limit, invalid code and CSRF')
    print('PASS temporary-password API/page gate, exact lifecycle exceptions, password replacement, stale sessions, logout and login-pressure controls')
