"""Real authenticated home/module delivery uses existing grants without granting owner access."""
import json, os, pathlib, shutil, subprocess, tempfile
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tti-workspaces-') as directory:
 root=pathlib.Path(directory);app=root/'app';shutil.copytree(ROOT,app,ignore=shutil.ignore_patterns('.git','node_modules','__pycache__','tmp','playwright-report','test-results'))
 private=root/'transtrade_private';private.mkdir();books=private/'accounts.json';books.write_text(json.dumps({'revision':3,'journals':{}}));before=books.read_bytes()
 user={'id':1,'username':'fixture','full_name':'Fixture Director','role':'Director','active':True,'session_version':1,'must_change_password':False,'permissions':{'Accounts':{'entity-tti':['View','Create','Approve'],'jv':['View','Create','Approve']},'Mill':{'stock':['View']},'Exports':{'active':['View']},'Directors':{'dashboard':['View']}}}
 harness=root/'request.php';harness.write_text('''<?php
$_SERVER['REQUEST_METHOD']='GET';$_SERVER['REQUEST_URI']=$argv[2];$_SERVER['REMOTE_ADDR']='192.0.2.7';parse_str((string)(parse_url($argv[2],PHP_URL_QUERY)??''),$_GET);
session_name('TRANSTRADE_SESSION');session_id('workspace-fixture');session_start();$_SESSION=['user_id'=>1,'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];session_write_close();
register_shutdown_function(static function(){echo "\\nHTTP_STATUS=".(http_response_code()?:200);});require $argv[1].parse_url($argv[2],PHP_URL_PATH);
''')
 env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
 def save_user():(private/'auth.json').write_text(json.dumps({'users':[user],'masters':{},'audit':[]}))
 def request(path):
  r=subprocess.run(['php','-d',f'session.save_path={root}',str(harness),str(app),path],env=env,text=True,capture_output=True);assert r.returncode==0,r.stderr+r.stdout
  html,status=r.stdout.rsplit('\nHTTP_STATUS=',1);return int(status),html
 save_user();status,home=request('/staff-home.php');assert status==200
 for href in ['/accounts/index.php','/module.php?id=milling','/module.php?id=exports','/directors/index.php']:assert f'href="{href}"' in home
 assert 'Your authorised workspaces' in home and 'TT_JV_NOTICE_ACCESS' in home and '"homeSelector":"#staffHome"' in home
 assert 'Create User' not in home and 'Reset Password' not in home and 'not live yet' not in home
 status,accounts=request('/accounts/index.php');assert status==200 and '"workspaces":[{"module":"Accounts"' in accounts and '"module":"Directors"' in accounts
 for path in ['/module.php?id=milling','/module.php?id=exports']:
  status,html=request(path);assert status==200 and '"workspaces":[' in html and '/staff-home.php' in html
 status,directors=request('/directors/index.php');assert status==200 and 'href="/staff-home.php"' in directors
 # A new page reads current permissions; no stale role/session capability grants.
 user['permissions']={'Accounts':{'entity-tti':['View'],'jv':['View']}};save_user();status,home=request('/staff-home.php');assert status==200 and 'href="/accounts/index.php"' in home
 for href in ['/module.php?id=milling','/module.php?id=exports','/directors/index.php']:assert f'href="{href}"' not in home
 assert request('/module.php?id=exports')[0]==403 and request('/directors/index.php')[0]==403
 assert request('/index.php')[0]==302,'Director does not receive owner console authority'
 assert books.read_bytes()==before,'Home/module reads changed original financial books'
 print('PASS actual-auth Director workspaces and header delivery, current permission narrowing, owner-console separation and unchanged books')
