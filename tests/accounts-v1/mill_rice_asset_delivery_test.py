"""Render the real module route in a disposable CLI fixture with its protected helpers."""
import pathlib,re,shutil,subprocess,tempfile,json
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='rice-module-delivery-') as directory:
 root=pathlib.Path(directory);(root/'api').mkdir();(root/'milling').mkdir();(root/'exports').mkdir()
 for file in ['module.php','runtime_html.php']:shutil.copy(ROOT/file,root/file)
 for file in ['operations_read_policy.php','operations_scope.php','export_permission_policy.php']:shutil.copy(ROOT/'api'/file,root/'api'/file)
 for file in ['Transtrade_Master_Milling_V3_3_2_AUDITED.html','production-recovery-entry.js','rice-ledger.js','maize-labour.js','lifting-review.js','directors-exporter-links.js','.htaccess']:shutil.copy(ROOT/'milling'/file,root/'milling'/file)
 for file in ['index.html','app.css','app.js','tg-accounts-receipts.js','shipment-files.js','office-agent-shipment-hooks.js','.htaccess']:shutil.copy(ROOT/'exports'/file,root/'exports'/file)
 (root/'auth_store.php').write_text('''<?php
 function tt_require_login(){return ['id'=>1,'full_name'=>'Fixture','role'=>'Staff','permissions'=>['Mill'=>['reports'=>['View']]]];}
 function tt_user_can_open_module($u,$m){return in_array($m,['Mill','Exports'],true)&&getenv('TT_FIXTURE_DENIED')!=='1';}
 function tt_release_read_session(){} function tt_csrf(){return 'fixture';}
 function tt_user_can_access_masters($u){return false;} function tt_list_masters(){return [];}
 function tt_master_options(){return [];}
 ''')
 auth_source=(ROOT/'auth_store.php').read_text();start=auth_source.index('function tt_user_workspaces(');end=auth_source.index('function tt_user_landing_url(',start)
 with (root/'auth_store.php').open('a') as fixture:fixture.write(auth_source[start:end])
 with (root/'auth_store.php').open('a') as fixture:
  for source,name in [(auth_source,'tt_user_can_master'),((ROOT/'master_store.php').read_text(),'tt_default_master_options'),((ROOT/'master_store.php').read_text(),'tt_export_finish_options')]:
   a=source.index('function '+name+'(');b=source.find('\nfunction ',a+9);fixture.write(source[a:b if b!=-1 else None])
 (root/'api/carry_forward_core.php').write_text('<?php function job_authorized($u){return false;}')
 (root/'request.php').write_text("<?php $_GET=['id'=>$argv[1]??'milling'];register_shutdown_function(function(){file_put_contents(__DIR__.'/status.json',json_encode(http_response_code()?:200));});require __DIR__.'/module.php';")
 def run(denied=False,module='milling'):
  import os
  r=subprocess.run(['php',str(root/'request.php'),module],capture_output=True,text=True,env={**os.environ,'TT_FIXTURE_DENIED':'1' if denied else '0'});assert r.returncode==0,r.stderr;return json.loads((root/'status.json').read_text()),r.stdout
 status,html=run();assert status==200
 assert '<script id="milling-rice-ledger">' in html
 assert '<script src="/milling/rice-ledger.js' not in html,'Direct path is denied in production'
 assert 'window.TT_RICE_LEDGER={load,print,csv}' in html
 assert '<script id="milling-maize-labour">' in html and '<script id="milling-lifting-review">' in html and '<script id="milling-directors-exporter-links">' in html
 assert not re.search(r'<script[^>]*src=[\"\']/milling/',html),'Protected folder URLs cannot deliver browser scripts'
 assert (root/'milling/.htaccess').read_text().strip()=='Require all denied','Do not open the protected folder'
 for index,body in enumerate(re.findall(r'<script\b[^>]*>([\s\S]*?)</script>',html,re.I)):
  if not body.strip():continue
  file=root/f'inline-{index}.js';file.write_text(body);r=subprocess.run(['node','--check',str(file)],capture_output=True,text=True);assert r.returncode==0,r.stderr
 status,html=run(True);assert status==403 and 'TT_RICE_LEDGER' not in html,'Denied users must not receive module source'
 status,html=run(module='exports');assert status==200 and '<script id="exports-office-agent-hooks">' in html
 assert not re.search(r'<script[^>]*src=[\"\'][^\"\']*office-agent-shipment-hooks',html),'Unresolved office hook URL remains'
 assert (root/'exports/.htaccess').read_text().strip()=='Require all denied'
 status,html=run(True,module='exports');assert status==403 and '__officeHooked' not in html
 (root/'milling/maize-labour.js').unlink();status,html=run();assert status==503 and 'Milling task assets are unavailable' in html
 shutil.copy(ROOT/'milling/maize-labour.js',root/'milling/maize-labour.js')
 (root/'exports/office-agent-shipment-hooks.js').unlink();status,html=run(module='exports');assert status==503 and 'Export office hook assets are unavailable' in html
 (root/'milling/rice-ledger.js').unlink();status,html=run();assert status==503 and 'Rice Ledger assets are unavailable' in html
 print('PASS actual module renderer: Rice Ledger/Maize/review and Export office hooks delivered, all Milling inline JS parses, denied users excluded, protected folder retained, missing required asset gives503.')
