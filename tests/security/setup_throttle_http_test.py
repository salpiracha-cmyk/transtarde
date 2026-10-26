"""The one-time setup route throttles guesses while preserving valid setup."""
import json,os,pathlib,shutil,subprocess,tempfile
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tti-setup-throttle-') as temp:
    root=pathlib.Path(temp);app=root/'app';shutil.copytree(ROOT,app,ignore=shutil.ignore_patterns('.git','node_modules','tmp','__pycache__'))
    harness=root/'request.php';harness.write_text('''<?php
$_SERVER['REQUEST_URI']='/setup.php';$_SERVER['REQUEST_METHOD']='POST';$_SERVER['REMOTE_ADDR']=$argv[2];
session_name('TRANSTRADE_SESSION');session_id('setupfixture');session_start();$_SESSION=['csrf'=>'fixture'];session_write_close();
$_POST=['csrf'=>'fixture','username'=>'fixtureowner','password'=>'FixturePassword123','confirm_password'=>'FixturePassword123','setup_token'=>$argv[3]];
register_shutdown_function(static function()use($argv){file_put_contents($argv[4],(string)(http_response_code()?:200));});require $argv[1];
''')
    env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')};env['TT_SETUP_TOKEN']='isolated-fixture-setup-token-2026'
    def request(ip,token):
        status=root/'status';r=subprocess.run(['php','-d',f'session.save_path={root}',str(harness),str(app/'setup.php'),ip,token,str(status)],env=env,capture_output=True,text=True);assert r.returncode==0,r.stderr;return int(status.read_text()),r.stdout
    for i in range(5):assert 'invalid' in request('192.0.2.1','wrong-'+str(i))[1]
    assert request('192.0.2.1',env['TT_SETUP_TOKEN'])[0]==429
    assert request('192.0.2.2',env['TT_SETUP_TOKEN'])[0] in [302,303]
    auth=json.loads((root/'transtrade_private/auth.json').read_text());assert len(auth['users'])==1 and auth['users'][0]['username']=='fixtureowner'
    assert request('192.0.2.3','wrong')[0] in [302,303]
    print('PASS real setup: five guesses lock the source, valid independent setup succeeds once, completed setup remains locked')
