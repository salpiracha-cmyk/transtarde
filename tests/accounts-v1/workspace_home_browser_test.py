"""Native full-permission Director navigation and persistent JV notice in disposable storage."""
import json, os, pathlib, shutil, socket, subprocess, tempfile, time
from playwright.sync_api import sync_playwright
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tti-home-browser-') as folder:
 root=pathlib.Path(folder);app=root/'repo';shutil.copytree(ROOT,app,ignore=shutil.ignore_patterns('.git','node_modules','__pycache__','test-results','playwright-report'))
 env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
 seed=root/'seed.php';seed.write_text('''<?php
require __DIR__.'/repo/auth_store.php';$pw=bin2hex(random_bytes(20));$ownerPw=bin2hex(random_bytes(20));$permissions=[];
tt_ensure_data_dir();file_put_contents(TT_STORE_FILE,json_encode(['users'=>[['id'=>1,'username'=>'fixtureowner','full_name'=>'Fixture Owner','password_hash'=>password_hash($ownerPw,PASSWORD_DEFAULT),'role'=>'Super Admin','permissions'=>['Accounts'=>'all'],'active'=>true,'must_change_password'=>false],['id'=>2,'username'=>'qadirector','full_name'=>'Fixture Director','password_hash'=>password_hash($pw,PASSWORD_DEFAULT),'role'=>'Director','location'=>'Karachi Office','master_access'=>true,'master_permissions'=>['companies'=>[]],'permissions'=>$permissions,'active'=>true,'must_change_password'=>false]],'masters'=>tt_default_masters(),'settings'=>['qa_account_seeded'=>true],'audit'=>[]]));file_put_contents(TT_DATA_DIR.'/operations.json',json_encode(['revision'=>0,'values'=>new stdClass()]));file_put_contents(TT_DATA_DIR.'/accounts.json',json_encode(['revision'=>0,'journals'=>['FIXTURE-POST'=>['id'=>'FIXTURE-POST','entity'=>'TTI','date'=>'2026-10-01','sourceType'=>'JV','status'=>'Posted','narration'=>'Fixture opening funds','lines'=>[['account'=>'1120','debit'=>100,'credit'=>0],['account'=>'3100','debit'=>0,'credit'=>100]],'totalDebit'=>100,'totalCredit'=>100]],'jvNoticeSequence'=>1,'jvNotices'=>[['id'=>1,'recipientIds'=>[2],'entity'=>'TTI','jvId'=>'FIXTURE-JV','status'=>'Posted','postId'=>'FIXTURE-POST','publicPostId'=>'FIXTURE-POST']]]));echo json_encode(['password'=>$pw,'ownerPassword'=>$ownerPw,'dataDir'=>TT_DATA_DIR]);
''')
 result=subprocess.run(['php',str(seed)],env=env,text=True,capture_output=True);assert result.returncode==0,result.stderr;fixture=json.loads(result.stdout)
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(app)],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL);url=f'http://127.0.0.1:{port}';books=pathlib.Path(fixture['dataDir'])/'accounts.json';before=json.loads(books.read_text())['journals']
 try:
  with sync_playwright() as p:
   browser=p.chromium.launch();page=browser.new_page(viewport={'width':1280,'height':900});errors=[];page.on('pageerror',lambda e:errors.append({'url':page.url,'error':str(e),'stack':e.stack}))
   for attempt in range(50):
    try:page.goto(url+'/login.php');break
    except Exception:time.sleep(.1)
   assert page.url.endswith('/login.php') and page.locator('[name=username]').count()==1,'Disposable owner/Director fixture must reach the real sign-in form'
   page.locator('[name=username]').fill('qadirector');page.locator('[name=password]').fill(fixture['password']);page.locator('button[type=submit]').click();page.wait_for_url('**/staff-home.php');assert page.get_by_role('navigation',name='Your authorised workspaces').get_by_role('link').count()==4;assert page.get_by_role('link',name='Master Records',exact=True).is_visible()
   page.locator('#tt-jv-decision').wait_for(state='visible');page.reload();notice=page.locator('#tt-jv-decision');notice.wait_for(state='visible');assert 'FIXTURE-POST' in notice.inner_text()
   nav=page.get_by_role('navigation',name='Your authorised workspaces');assert nav.get_by_role('link').count()==4
   if os.environ.get('TT_QA_OUTPUT'):page.screenshot(path=str(pathlib.Path(os.environ['TT_QA_OUTPUT'])/'director-home-desktop.png'),full_page=True)
   page.set_viewport_size({'width':390,'height':844});assert page.locator('body').evaluate('(e)=>e.scrollWidth<=innerWidth');assert page.locator('.tt-staff-header').bounding_box()['y']==0;power=page.get_by_role('link',name='Log out').bounding_box();assert power['x']+power['width']<=390 and power['width']>=30,'Header logout must remain entirely visible on mobile'
   if os.environ.get('TT_QA_OUTPUT'):page.screenshot(path=str(pathlib.Path(os.environ['TT_QA_OUTPUT'])/'director-home-mobile.png'),full_page=True)
   notice.get_by_role('button',name='Dismiss JV decision').click();notice.wait_for(state='hidden');page.reload();assert page.locator('#tt-jv-decision:visible').count()==0
   page.set_viewport_size({'width':1280,'height':900})
   for label in ['Accounts','Mill','Exports','Directors']:
    page.get_by_role('navigation',name='Your authorised workspaces').get_by_role('link',name=label,exact=True).click();page.get_by_role('link',name='Home',exact=True).wait_for();assert page.get_by_role('link',name='Home',exact=True).get_attribute('href')=='/staff-home.php';page.get_by_role('link',name='Home',exact=True).click();page.wait_for_url('**/staff-home.php')
   # Owner sees a compact automatic Director preset, not hundreds of action boxes.
   page.goto(url+'/logout.php');page.goto(url+'/login.php');page.locator('[name=username]').fill('fixtureowner');page.locator('[name=password]').fill(fixture['ownerPassword']);page.locator('button[type=submit]').click();page.wait_for_url('**/index.php');page.get_by_role('button',name='U Users & Permissions',exact=True).click();page.get_by_role('row').filter(has_text='@qadirector').get_by_role('button',name='Edit',exact=True).click()
   dialog=page.get_by_role('dialog');summary=dialog.locator('#directorAccessSummary');assert summary.is_visible() and 'Full business access' in summary.inner_text()
   for selector in ['#userModulePermissions','#userMasterPermissions','#userLocationField']:assert not dialog.locator(selector).is_visible()
   assert dialog.locator('#userModulePermissions input:not(:checked),#userMasterPermissions input:not(:checked)').count()==0
   assert dialog.locator('#userModulePermissions input:enabled,#userMasterPermissions input:enabled').count()==0
   for size,label in [({'width':1280,'height':900},'desktop'),({'width':390,'height':844},'mobile')]:
    page.set_viewport_size(size);assert dialog.get_by_role('heading',name='Edit User').is_visible();assert dialog.locator('#userName').is_visible();assert summary.is_visible();assert page.locator('body').evaluate('(e)=>e.scrollWidth<=innerWidth');assert dialog.evaluate('(e)=>e.scrollWidth<=e.clientWidth'),'Hidden Active switch input must not overflow the compact dialog';active=dialog.locator('#userActive');active.uncheck();assert not active.is_checked();active.check();assert active.is_checked()
    if os.environ.get('TT_QA_OUTPUT'):page.screenshot(path=str(pathlib.Path(os.environ['TT_QA_OUTPUT'])/('director-role-preset-'+label+'.png')),full_page=True)
   # Demotion reveals the old custom scope, with no inherited automatic Delete rights.
   dialog.locator('#userRole').select_option('Accounts');dialog.locator('#permissionMatrix summary').filter(has_text='Accounts').click();grant=dialog.locator('[data-permission-module="Accounts"][data-permission-icon="cashbank"][value="Delete"]');assert grant.is_visible() and not grant.is_checked()
   module=grant.locator('xpath=ancestor::details[1]');header=module.locator('.permission-row.header > span').all();assert [x.text_content() for x in header]==['View','Create','Edit','Approve','Reports','Delete']
   def aligned():
    column=header[-1].bounding_box();control=grant.bounding_box();assert abs(column['x']+column['width']/2-control['x']-control['width']/2)<2,'Delete checkbox must align below its header'
   grant.scroll_into_view_if_needed();aligned();assert page.locator('body').evaluate('(e)=>e.scrollWidth<=innerWidth')
   page.set_viewport_size({'width':1280,'height':900});grant.scroll_into_view_if_needed();aligned()
   dialog.get_by_role('button',name='Cancel',exact=True).click();auth_data=json.loads((pathlib.Path(fixture['dataDir'])/'auth.json').read_text());director=next(u for u in auth_data['users'] if u['id']==2);assert director['role']=='Director' and director['permissions']==[] and director['location']=='Karachi Office','Inspection must preserve raw identity and custom grants'
   # Create and reopen through the actual user endpoint with role as the only access choice.
   page.get_by_role('button',name='+ Create User',exact=True).click();dialog=page.get_by_role('dialog');dialog.locator('#userName').fill('Second Fixture Director');dialog.locator('#username').fill('second.director');dialog.locator('#userRole').select_option('Director');dialog.get_by_role('button',name='Save User',exact=True).click();page.get_by_role('heading',name='Temporary login created').wait_for();page.get_by_role('button',name='Done',exact=True).click()
   auth_data=json.loads((pathlib.Path(fixture['dataDir'])/'auth.json').read_text());created=next(u for u in auth_data['users'] if u['username']=='second.director');assert created['role']=='Director' and created['permissions']==[] and not created['master_access'],'Derived role access must not be stored as permanent custom grants'
   page.get_by_role('row').filter(has_text='@second.director').get_by_role('button',name='Edit',exact=True).click();dialog=page.get_by_role('dialog');assert dialog.locator('#directorAccessSummary').is_visible() and not dialog.locator('#userModulePermissions').is_visible()
   dialog.locator('#userRole').select_option('Accounts');dialog.locator('#permissionMatrix summary').filter(has_text='Accounts').click();dialog.locator('[data-permission-module="Accounts"][data-permission-icon="reports"][value="View"]').check();dialog.locator('[data-permission-module="Accounts"][data-permission-icon="entity-brm"][value="View"]').check();dialog.get_by_role('button',name='Save User',exact=True).click();dialog.wait_for(state='hidden')
   auth_data=json.loads((pathlib.Path(fixture['dataDir'])/'auth.json').read_text());demoted=next(u for u in auth_data['users'] if u['username']=='second.director');assert demoted['role']=='Accounts' and demoted['permissions']=={'Accounts':{'entity-brm':['View'],'reports':['View']}},'Actual downgrade Save must retain only newly selected granular scope'

   saved=json.loads(books.read_text());assert saved['journals']==before,'Original balanced journal changed during Home navigation or acknowledgement';assert '1' in saved['jvNoticeDismissals']['2'],'X acknowledgement did not persist';assert not errors,errors;browser.close()
  print('PASS native role-only Director access, compact preset desktop/mobile, owner-only controls, staff-scope restoration, private notices and unchanged journals')
 finally:server.terminate();server.wait(timeout=10)
