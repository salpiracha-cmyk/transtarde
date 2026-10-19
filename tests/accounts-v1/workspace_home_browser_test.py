"""Native full-permission Director navigation and persistent JV notice in disposable storage."""
import json, os, pathlib, shutil, socket, subprocess, tempfile, time
from playwright.sync_api import sync_playwright
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tti-home-browser-') as folder:
 root=pathlib.Path(folder);app=root/'repo';shutil.copytree(ROOT,app,ignore=shutil.ignore_patterns('.git','node_modules','__pycache__','test-results','playwright-report'))
 env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
 seed=root/'seed.php';seed.write_text('''<?php
require __DIR__.'/repo/auth_store.php';$pw=bin2hex(random_bytes(20));$rights=['View','Create','Edit','Approve','Reports'];$permissions=['Accounts'=>['entity-tti'=>$rights,'entity-brm'=>$rights,'entity-tg'=>$rights,'jv'=>$rights,'reports'=>$rights,'cashbank'=>$rights,'expenses'=>$rights],'Mill'=>['stock'=>['View'],'reports'=>['View']],'Exports'=>['active'=>['View'],'reports'=>['View']],'Directors'=>['dashboard'=>['View'],'reports'=>['View']]];
tt_ensure_data_dir();file_put_contents(TT_STORE_FILE,json_encode(['users'=>[['id'=>1,'username'=>'fixtureowner','full_name'=>'Fixture Owner','password_hash'=>password_hash(bin2hex(random_bytes(20)),PASSWORD_DEFAULT),'role'=>'Super Admin','permissions'=>['Accounts'=>'all'],'active'=>true,'must_change_password'=>false],['id'=>2,'username'=>'qadirector','full_name'=>'Fixture Director','password_hash'=>password_hash($pw,PASSWORD_DEFAULT),'role'=>'Director','permissions'=>$permissions,'active'=>true,'must_change_password'=>false]],'masters'=>tt_default_masters(),'settings'=>['qa_account_seeded'=>true],'audit'=>[]]));file_put_contents(TT_DATA_DIR.'/operations.json',json_encode(['revision'=>0,'values'=>new stdClass()]));file_put_contents(TT_DATA_DIR.'/accounts.json',json_encode(['revision'=>0,'journals'=>new stdClass(),'jvNoticeSequence'=>1,'jvNotices'=>[['id'=>1,'recipientIds'=>[2],'entity'=>'TTI','jvId'=>'FIXTURE-JV','status'=>'Posted','postId'=>'FIXTURE-POST','publicPostId'=>'FIXTURE-POST']]]));echo json_encode(['password'=>$pw,'dataDir'=>TT_DATA_DIR]);
''')
 result=subprocess.run(['php',str(seed)],env=env,text=True,capture_output=True);assert result.returncode==0,result.stderr;fixture=json.loads(result.stdout)
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(app)],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL);url=f'http://127.0.0.1:{port}';books=pathlib.Path(fixture['dataDir'])/'accounts.json';before=json.loads(books.read_text())['journals']
 try:
  with sync_playwright() as p:
   browser=p.chromium.launch();page=browser.new_page(viewport={'width':1280,'height':900});errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
   for attempt in range(50):
    try:page.goto(url+'/login.php');break
    except Exception:time.sleep(.1)
   assert page.url.endswith('/login.php') and page.locator('[name=username]').count()==1,'Disposable owner/Director fixture must reach the real sign-in form'
   page.locator('[name=username]').fill('qadirector');page.locator('[name=password]').fill(fixture['password']);page.locator('button[type=submit]').click();page.wait_for_url('**/accounts/index.php');home=page.get_by_role('link',name='Home',exact=True);home.wait_for();assert home.get_attribute('href')=='/staff-home.php'
   page.locator('#tt-jv-decision').wait_for(state='visible');home.click();page.wait_for_url('**/staff-home.php');notice=page.locator('#tt-jv-decision');notice.wait_for(state='visible');assert 'FIXTURE-POST' in notice.inner_text()
   nav=page.get_by_role('navigation',name='Your authorised workspaces');assert nav.get_by_role('link').count()==4
   if os.environ.get('TT_QA_OUTPUT'):page.screenshot(path=str(pathlib.Path(os.environ['TT_QA_OUTPUT'])/'director-home-desktop.png'),full_page=True)
   page.set_viewport_size({'width':390,'height':844});assert page.locator('body').evaluate('(e)=>e.scrollWidth<=innerWidth');assert page.locator('.tt-staff-header').bounding_box()['y']==0
   if os.environ.get('TT_QA_OUTPUT'):page.screenshot(path=str(pathlib.Path(os.environ['TT_QA_OUTPUT'])/'director-home-mobile.png'),full_page=True)
   notice.get_by_role('button',name='Dismiss JV decision').click();notice.wait_for(state='hidden');page.reload();assert page.locator('#tt-jv-decision:visible').count()==0
   page.set_viewport_size({'width':1280,'height':900})
   for label in ['Accounts','Mill','Exports','Directors']:
    page.get_by_role('navigation',name='Your authorised workspaces').get_by_role('link',name=label,exact=True).click();page.get_by_role('link',name='Home',exact=True).wait_for();page.get_by_role('link',name='Home',exact=True).click();page.wait_for_url('**/staff-home.php')
   saved=json.loads(books.read_text());assert saved['journals']==before and '1' in saved['jvNoticeDismissals']['2'];assert not errors,errors;browser.close()
  print('PASS native Director Home across all four modules, desktop/mobile fit, private notice survival and explicit durable X without financial posting')
 finally:server.terminate();server.wait(timeout=10)
