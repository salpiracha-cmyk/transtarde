"""Render the real module route in a disposable CLI fixture with its protected helpers."""
import pathlib,re,shutil,subprocess,tempfile,json
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='rice-module-delivery-') as directory:
 root=pathlib.Path(directory);(root/'api').mkdir();(root/'milling').mkdir()
 for file in ['module.php','runtime_html.php']:shutil.copy(ROOT/file,root/file)
 for file in ['Transtrade_Master_Milling_V3_3_2_AUDITED.html','production-recovery-entry.js','rice-ledger.js','.htaccess']:shutil.copy(ROOT/'milling'/file,root/'milling'/file)
 (root/'auth_store.php').write_text('''<?php
 function tt_require_login(){return ['full_name'=>'Fixture','role'=>'Staff','permissions'=>['Mill'=>['reports'=>['View']]]];}
 function tt_user_can_open_module($u,$m){return $m==='Mill'&&getenv('TT_FIXTURE_DENIED')!=='1';}
 function tt_release_read_session(){} function tt_csrf(){return 'fixture';}
 function tt_user_can_access_masters($u){return false;} function tt_list_masters(){return [];}
 function tt_master_options(){return [];}
 ''')
 (root/'api/carry_forward_core.php').write_text('<?php function job_authorized($u){return false;}')
 (root/'request.php').write_text("<?php $_GET=['id'=>'milling'];register_shutdown_function(function(){file_put_contents(__DIR__.'/status.json',json_encode(http_response_code()?:200));});require __DIR__.'/module.php';")
 def run(denied=False):
  import os
  r=subprocess.run(['php',str(root/'request.php')],capture_output=True,text=True,env={**os.environ,'TT_FIXTURE_DENIED':'1' if denied else '0'});assert r.returncode==0,r.stderr;return json.loads((root/'status.json').read_text()),r.stdout
 status,html=run();assert status==200
 assert '<script id="milling-rice-ledger">' in html
 assert '<script src="/milling/rice-ledger.js' not in html,'Direct path is denied in production'
 assert 'window.TT_RICE_LEDGER={load,print,csv}' in html
 assert (root/'milling/.htaccess').read_text().strip()=='Require all denied','Do not open the protected folder'
 for index,body in enumerate(re.findall(r'<script\b[^>]*>([\s\S]*?)</script>',html,re.I)):
  if not body.strip():continue
  file=root/f'inline-{index}.js';file.write_text(body);r=subprocess.run(['node','--check',str(file)],capture_output=True,text=True);assert r.returncode==0,r.stderr
 status,html=run(True);assert status==403 and 'TT_RICE_LEDGER' not in html,'Denied users must not receive module source'
 (root/'milling/rice-ledger.js').unlink();status,html=run();assert status==503 and 'Rice Ledger assets are unavailable' in html
 print('PASS actual module renderer: inline Rice Ledger delivered, all inline JS parses, denied users excluded, protected folder retained, missing required asset gives503.')
