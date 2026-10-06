"""Real PHP requests preserve sessions across legacy save paths and cannot resurrect logout."""
import json, pathlib, shutil, subprocess, tempfile, concurrent.futures
ROOT = pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tti-sessions-') as directory:
    root=pathlib.Path(directory); app=root/'app';app.mkdir()
    for name in ['auth_store.php','master_store.php','product_stage.php','offline_idempotency.php','backup_lib.php','session_store.php']:
        shutil.copy(ROOT/name,app/name)
    old_a=root/'legacy-a';old_b=root/'legacy-b';old_a.mkdir();old_b.mkdir()
    script=root/'request.php'
    script.write_text('''<?php
ini_set('session.save_path',$argv[2]);session_name('TRANSTRADE_SESSION');session_id('migration-fixture');
if($argv[3]==='create'){session_start();$_SESSION=['csrf'=>'fixture','counter'=>7];session_write_close();}
ini_set('session.serialize_handler',$argv[4]);
require $argv[1].'/auth_store.php';
if($argv[3]==='logout'){tt_destroy_session_state();}
$result=['counter'=>$_SESSION['counter']??null,'path'=>session_save_path(),'lifetime'=>(int)ini_get('session.gc_maxlifetime'),'mode'=>fileperms(session_save_path())&0777,'id'=>session_id()];
if($argv[3]==='increment'){$_SESSION['counter']++;}
session_write_close();echo json_encode($result);
''')
    def run(old,action,codec='php'):
        result=subprocess.run(['php',str(script),str(app),str(old),action,codec],capture_output=True,text=True)
        assert result.returncode==0, result.stdout+result.stderr
        return json.loads(result.stdout)
    first=run(old_a,'create')
    assert first['counter']==7 and first['id']=='migration-fixture'
    assert pathlib.Path(first['path']).resolve()==(root/'transtrade_private/sessions').resolve()
    assert first['lifetime']==3600 and first['mode']==0o700
    assert run(old_b,'increment','php_serialize')['counter']==7, 'A worker codec change must not destroy the session'
    record=next((root/'transtrade_private/sessions/records').glob('*.json'))
    original_inode=record.stat().st_ino
    assert run(old_a,'read')['counter']==8, 'Different legacy route/worker settings must resolve to the same session'
    assert record.stat().st_ino==original_inode, 'An unchanged session read must not replace its data file'
    assert not (old_a/'sess_migration-fixture').exists(), 'Retire the legacy copy after migration'
    # Competing PHP processes must never lose updates or read a partial record.
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
        results=list(pool.map(lambda _:run(old_b,'increment'),range(64)))
    assert sorted(x['counter'] for x in results)==list(range(8,72)), 'Concurrent increments lost a session update'
    assert run(old_a,'read')['counter']==72
    records=root/'transtrade_private/sessions/records'
    assert records.is_dir() and records.stat().st_mode&0o777==0o700
    assert all(p.stat().st_mode&0o777==0o600 for p in records.iterdir()), 'Private record permissions drifted'
    probe=root/'strict.php'
    probe.write_text("<?php $_COOKIE['TRANSTRADE_SESSION']='attacker-chosen-id';require $argv[1].'/auth_store.php';echo session_id()==='attacker-chosen-id'?'FIXATED':'ROTATED';")
    assert subprocess.run(['php',str(probe),str(app)],capture_output=True,text=True,check=True).stdout=='ROTATED', 'Strict-mode rejected IDs must be replaced'
    damaged=root/'transtrade_private/sessions/sess_damaged-id'
    damaged.write_text('csrf|s:64:"truncated')
    probe.write_text("<?php $_COOKIE['TRANSTRADE_SESSION']='damaged-id';require $argv[1].'/auth_store.php';echo empty($_SESSION['user_id'])?'ANONYMOUS':'UNSAFE';")
    assert subprocess.run(['php',str(probe),str(app)],capture_output=True,text=True,check=True).stdout=='ANONYMOUS', 'A truncated legacy session must fail closed while allowing sign-in'
    assert not damaged.exists(), 'Damaged legacy records must be retired'


    # Even a leftover/recreated old backend copy must not resurrect a signed-out session.
    (old_a/'sess_migration-fixture').write_text('csrf|s:7:"fixture";counter|i:7;')
    run(old_a,'logout')
    assert run(old_a,'read')['counter'] is None
    listing=root/'list.php'
    listing.write_text('<?php require $argv[1]."/auth_store.php";require_once $argv[1]."/backup_lib.php";echo json_encode(array_keys(tt_backup_private_files()));')
    files=json.loads(subprocess.run(['php',str(listing),str(app)],capture_output=True,text=True,check=True).stdout)
    assert not any(name.startswith('sessions/') for name in files), 'Session credentials must not enter business recovery backups'
    print('PASS atomic concurrent sessions, worker codec continuity, migration, strict IDs, private permissions, idle lifetime, logout replay protection and backup exclusion')

# A slow authorized read must not hold up a second request from the same user.
# The first PHP process stays alive on stdin, so this checks the real lock,
# rather than relying on a timing assertion or a mocked session handler.
with tempfile.TemporaryDirectory(prefix='tti-read-concurrency-') as directory:
    root=pathlib.Path(directory);app=root/'app';app.mkdir()
    for name in ['auth_store.php','master_store.php','product_stage.php','offline_idempotency.php','session_store.php']:
        shutil.copy(ROOT/name,app/name)
    private=root/'transtrade_private';private.mkdir()
    user={'id':1,'username':'fixture','full_name':'Fixture','role':'Super Admin','active':True,'session_version':1,'permissions':{}}
    auth=private/'auth.json'
    auth.write_text(json.dumps({'users':[user],'masters':{},'audit':[]}))
    seed=root/'seed.php'
    seed.write_text("""<?php
require $argv[1].'/auth_store.php';
$_SESSION=['user_id'=>1,'auth_version'=>1,'last_activity_at'=>time()-10];
$id=session_id();session_write_close();echo $id;
""")
    session_id=subprocess.run(['php',str(seed),str(app)],capture_output=True,text=True,check=True).stdout
    reader=root/'reader.php'
    reader.write_text("""<?php
$_COOKIE['TRANSTRADE_SESSION']=$argv[2];
$_SERVER['REQUEST_URI']='/api/masters.php';$_SERVER['REQUEST_METHOD']=$argv[3];
$_SERVER['HTTP_X_TT_USER_ACTIVITY']='1';
require $argv[1].'/auth_store.php';
$user=tt_require_login();
if($argv[3]==='GET'&&session_status()===PHP_SESSION_ACTIVE)throw new RuntimeException('API guard kept a read lock');
if($argv[3]==='POST'){
    try{tt_release_read_session();throw new RuntimeException('POST released');}
    catch(LogicException $expected){}
    if(session_status()!==PHP_SESSION_ACTIVE)throw new RuntimeException('POST lost its lock');
    session_write_close();echo 'POST_PROTECTED';exit;
}
tt_release_read_session();
if(session_status()===PHP_SESSION_ACTIVE)throw new RuntimeException('Read still holds lock');
if($argv[4]==='hold'){echo "READY\\n";fflush(STDOUT);fgets(STDIN);}
echo json_encode(['user'=>$user['id'],'csrf'=>tt_csrf(),'activity'=>$_SESSION['last_activity_at']]);
""")
    held=subprocess.Popen(['php',str(reader),str(app),session_id,'GET','hold'],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
    try:
        assert held.stdout.readline().strip()=='READY', 'Read did not release its session'
        second=subprocess.run(['php',str(reader),str(app),session_id,'GET','probe'],capture_output=True,text=True,timeout=5,check=True)
        data=json.loads(second.stdout)
        assert data['user']==1 and len(data['csrf'])==64
        assert data['activity']>0, 'Committed activity was lost'
        held.stdin.write('finish\n');held.stdin.flush()
        remainder,error=held.communicate(timeout=5)
        assert held.returncode==0, error
        assert json.loads(remainder)['csrf']==data['csrf'], 'Concurrent read changed CSRF'
        post=subprocess.run(['php',str(reader),str(app),session_id,'POST','probe'],capture_output=True,text=True,timeout=5,check=True)
        assert post.stdout=='POST_PROTECTED', 'Write requests must retain session protection'
        user['session_version']=2;auth.write_text(json.dumps({'users':[user],'masters':{},'audit':[]}))
        revoked=subprocess.run(['php',str(reader),str(app),session_id,'GET','probe'],capture_output=True,text=True,timeout=5,check=True)
        assert json.loads(revoked.stdout)['ok'] is False, 'Credential invalidation must survive early read release'
    finally:
        if held.poll() is None:held.kill();held.communicate()
    print('PASS slow reads permit concurrent authenticated requests; CSRF/activity persist, writes retain locks and changed passwords invalidate sessions')
