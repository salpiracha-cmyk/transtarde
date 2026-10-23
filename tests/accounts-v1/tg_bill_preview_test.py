import pathlib, tempfile, shutil, json, subprocess, os
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory() as temp:
 r=pathlib.Path(temp);shutil.copytree(ROOT/'api',r/'api');shutil.copytree(ROOT/'accounts',r/'accounts');(r/'data').mkdir()
 (r/'auth_store.php').write_text("""<?php
define('TT_DATA_DIR',__DIR__.'/data');
function tt_require_login(){return ['id'=>1,'role'=>'Super Admin'];}function tt_user_can_open_module($u,$m){return true;}function tt_verify_csrf($x){return $x==='fixture';}function tt_ensure_data_dir(){}function tt_read_store(){return ['masters'=>[]];}function tt_master_options(){return ['currencies'=>['USD','AED']];}function tt_company_fx_rate($e,$f,$t){return $f===$t?1:3.67;}function tt_accounts_input(){return file_get_contents(__DIR__.'/input.json');}function tt_next_post_id($rows,...$args){return 'POST-FIXTURE';}
""")
 candidate={'id':'C1','entity':'TG','candidateType':'TG_INTERCOMPANY_PAYABLE','transactionAmount':100,'transactionCurrency':'USD','counterparty':'Supplier','reference':'INV1'}
 initial={'revision':7,'journals':{},'tgLiabilities':{},'exportCandidates':{'C1':candidate}}
 books=r/'data/accounts.json';env={k:v for k,v in os.environ.items() if not k.startswith('TT_DB_')}
 for endpoint,body in [('tg_liabilities',{'action':'post_supplier_liability','date':'2026-10-23','counterparty':'Supplier','reference':'INV2','currency':'USD','nativeAmount':100,'payableAccount':'2140','debitAccount':'6900'}),('export_accounting',{'action':'recognize_candidate','candidateId':'C1','recognitionDate':'2026-10-23','functionalCurrency':'AED','functionalRate':3.67,'controlConfirmed':True,'costAccount':'5100'})]:
  books.write_text(json.dumps(initial));before=books.read_bytes();(r/'input.json').write_text(json.dumps({'csrf':'fixture','preview':True,**body}));(r/'run.php').write_text("<?php $_SERVER['REQUEST_METHOD']='POST';require __DIR__.'/api/"+endpoint+".php';")
  out=subprocess.run(['php',str(r/'run.php')],capture_output=True,text=True,env=env,check=True);data=json.loads(out.stdout);assert data['ok'] and len(data['previews'])==1,data;assert data['previews'][0]['totalDebit']==367;assert books.read_bytes()==before,'Preview changed books'
 print('PASS actual TG bill and linked payable previews are balanced and write nothing')
