"""Exercise shared assessment persistence, conflicts and access against disposable books."""
import json, pathlib, shutil, subprocess, tempfile
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='group-management-') as tmp:
 root=pathlib.Path(tmp);shutil.copytree(ROOT/'api',root/'api');shutil.copytree(ROOT/'accounts',root/'accounts');(root/'data').mkdir()
 (root/'auth_store.php').write_text('''<?php
define('TT_DATA_DIR',__DIR__.'/data');
function tt_require_login(){return ['id'=>1,'username'=>'Fixture','role'=>($_GET['mode']??'')==='super'?'Super Admin':'Staff'];}
function tt_user_can_module_action($u,$m,$i,$a){$mode=$_GET['mode']??'all';return $mode==='create'?$a!=='Edit':($mode==='read'?$a==='View':true);}
function tt_user_can_access_entity($u,$e,$a){return ($_GET['mode']??'')!=='limited'||$e!=='TG';}
function tt_release_read_session(){}function tt_verify_csrf($v){return $v==='fixture';}function tt_accounts_input(){return $GLOBALS['fixtureBody'];}
''')
 books={'revision':7,'journals':{}};path=root/'data/accounts.json';path.write_text(json.dumps(books));before=path.read_bytes()
 (root/'runner.php').write_text("""<?php
$r=json_decode(stream_get_contents(STDIN),true);$_GET=$r['query'];$_SERVER['REQUEST_METHOD']=$r['body']===null?'GET':'POST';$GLOBALS['fixtureBody']=json_encode($r['body']);register_shutdown_function(function(){fwrite(STDERR,'STATUS:'.(http_response_code()?:200));});require __DIR__.'/api/group_management.php';
""")
 def req(body=None,mode='all',kind='CASHFLOW',origin='accounts'):
  query={'kind':kind,'date':'2026-10-17','mode':mode,'origin':origin};data=None if body is None else {'csrf':'fixture','kind':kind,'date':'2026-10-17',**body}
  r=subprocess.run(['php',str(root/'runner.php')],input=json.dumps({'query':query,'body':data}),text=True,capture_output=True,check=True)
  return int(r.stderr.rsplit('STATUS:',1)[1]),json.loads(r.stdout)
 def save(section,version=0,requestKey='fixture-save-001',**extra):return {'action':'save-section','section':section,'version':version,'requestKey':requestKey,**extra}
 try:
  status,d=req()
  assert status==200,d;assert not (root/'data/group-management.json').exists();assert not (root/'data/group-management.json.lock').exists(),'GET created report storage'
  assert req(mode='limited')[0]==403
  assert req(mode='limited',origin='directors')[0]==403,'Directors origin bypassed company restriction'
  h=save('header',data={'rates':{'AED':76,'USD':280}});status,d=req(h,mode='create');assert status==200,d
  assert req(save('header',1,'fixture-save-002',data={'rates':{'AED':77}}),mode='create')[0]==403,'Create-only changed existing section'
  assert req(h,mode='create')[0]==403,'Retry with lost permission must not write'
  # First successful request can be replayed by an editor without repeating the save.
  status,d=req(h);assert status==200 and d['result']['duplicate'],d
  row={'id':'MANUAL-001','label':'Cash adjustment','amount':100,'currency':'PKR'}
  b=save('financials',requestKey='fixture-fin-001',rows=[row]);status,d=req(b);assert status==200,d;report=(root/'data/group-management.json').read_bytes()
  status,d=req(save('financials',0,'fixture-save-003',rows=[dict(row,amount=200)]));assert status==409,d;assert (root/'data/group-management.json').read_bytes()==report
  status,d=req(save('financials',1,'fixture-save-004',rows=[dict(row,amount=200)],complete=True,reviewConfirmed=True),origin='directors');assert status==200,d;assert d['summary']['totalPKR']==200
  status,d=req(save('financials',2,'fixture-save-005',rows=[dict(row,amount=300)]));assert status==422,d
  status,d=req(save('financials',2,'fixture-save-006',rows=[dict(row,amount=300)],reason='Correct amount'));assert status==200,d
  assert req(save('adjustments',requestKey='fixture-adj-001',rows=[row]),mode='read')[0]==403
  assert req(save('adjustments',rows=[row],csrf='bad'))[0]==419
  assert req(save('adjustments',requestKey='fixture-adj-001',rows=[row]))[0]==422,'Duplicate source allowed across sections'
  status,d=req();assert status==200 and d['record']['sections']['financials']['rows'][0]['amount']==300,d
  persisted=json.loads((root/'data/group-management.json').read_text());assert 'journals' not in persisted and path.read_bytes()==before,'Assessment changed canonical books'
  print('PASS shared group report GET zero writes; own atomic store; row persistence, section conflicts, permission/CSRF checks, duplicate protection and original books unchanged')
 finally:pass
