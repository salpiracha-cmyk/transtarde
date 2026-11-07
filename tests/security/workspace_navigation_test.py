"""Real authenticated home/module delivery uses existing grants without granting owner access."""
import http.cookiejar, json, os, pathlib, re, shutil, socket, subprocess, tempfile, time, urllib.error, urllib.parse, urllib.request
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tti-workspaces-') as directory:
 root=pathlib.Path(directory);app=root/'app';shutil.copytree(ROOT,app,ignore=shutil.ignore_patterns('.git','node_modules','__pycache__','tmp','playwright-report','test-results'))
 private=root/'transtrade_private';private.mkdir();books=private/'accounts.json';books.write_text(json.dumps({'revision':3,'journals':{}}));before=books.read_bytes()
 user={'id':1,'username':'fixture','full_name':'Fixture Director','role':'Director','active':True,'session_version':1,'must_change_password':False,'location':'Karachi Office','master_access':True,'master_permissions':{'companies':[]},'permissions':{}}
 harness=root/'request.php';harness.write_text('''<?php
$_SERVER['REQUEST_METHOD']='GET';$_SERVER['REQUEST_URI']=$argv[2];$_SERVER['REMOTE_ADDR']='192.0.2.7';parse_str((string)(parse_url($argv[2],PHP_URL_QUERY)??''),$_GET);
session_name('TRANSTRADE_SESSION');session_id('workspace-fixture');session_start();$_SESSION=['user_id'=>1,'auth_version'=>1,'last_activity_at'=>time(),'csrf'=>'fixture'];session_write_close();
register_shutdown_function(static function(){echo "\\nHTTP_STATUS=".(http_response_code()?:200);});require $argv[1].parse_url($argv[2],PHP_URL_PATH);
''')
 env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
 def save_user():(private/'auth.json').write_text(json.dumps({'users':[user,{'id':99,'username':'fixture.owner','role':'Super Admin','active':True}],'masters':{},'audit':[]}))
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
 # Demotion uses saved staff scope on the next request, with no lingering role rights.
 user['role']='Accounts';user['permissions']={'Accounts':{'entity-tti':['View'],'jv':['View']}};save_user();status,home=request('/staff-home.php');assert status==200 and 'href="/accounts/index.php"' in home
 for href in ['/module.php?id=milling','/module.php?id=exports','/directors/index.php']:assert f'href="{href}"' not in home
 assert request('/module.php?id=exports')[0]==403 and request('/directors/index.php')[0]==403
 user['role']='Director';user['permissions']={};save_user()
 assert request('/index.php')[0]==302,'Director does not receive owner console authority'
 for path in ['/api/users.php','/api/backup.php','/api/admin_audit.php','/document-reader-settings.php','/recovery-settings.php']:
  assert request(path)[0]==403,('Director reached owner administration',path)
 status,master=request('/index.php?view=masters');assert status==200 and '"Accounts":"all"' in master and '"masterControlled":true' in master
 user['active']=False;save_user();assert request('/accounts/index.php')[0]==302,'Inactive Director must remain blocked'
 assert books.read_bytes()==before,'Home/module reads changed original financial books'
 # Exercise the real login POST and its Location header, not just role helpers.
 user['active']=True;user['password_hash']=subprocess.check_output(['php','-r',"echo password_hash('FixturePass123!',PASSWORD_DEFAULT);"],env=env,text=True);save_user()
 class NoRedirect(urllib.request.HTTPRedirectHandler):
  def redirect_request(self,*args,**kwargs):return None
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(app)],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 try:
  base=f'http://127.0.0.1:{port}';cookies=http.cookiejar.CookieJar();opener=urllib.request.build_opener(NoRedirect(),urllib.request.HTTPCookieProcessor(cookies))
  for attempt in range(50):
   try:login=opener.open(base+'/login.php',timeout=3).read().decode();break
   except urllib.error.URLError:time.sleep(.1)
  else:raise AssertionError('Login fixture did not start')
  csrf=re.search(r'name="csrf" value="([^"]+)"',login).group(1)
  try:opener.open(base+'/login.php',urllib.parse.urlencode({'csrf':csrf,'username':'fixture','password':'FixturePass123!'}).encode(),timeout=10);raise AssertionError('Expected sign-in redirect')
  except urllib.error.HTTPError as response:
   assert response.code==302 and response.headers['Location']=='staff-home.php',('Wrong Director login destination',response.code,response.headers)
  home=opener.open(base+'/staff-home.php',timeout=10).read().decode()
  for href in ['/accounts/index.php','/module.php?id=milling','/module.php?id=exports','/directors/index.php','/index.php?view=masters']:assert f'href="{href}"' in home
  assert 'Create User' not in home and books.read_bytes()==before
 finally:server.terminate();server.wait()
 print('PASS actual-auth Director workspaces and header delivery, current permission narrowing, owner-console separation and unchanged books')
