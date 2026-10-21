"""The real amendment controller must preview cancellation without changing storage."""
import json, pathlib, shutil, subprocess, tempfile
ROOT=pathlib.Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='tti-cancel-preview-') as directory:
 base=pathlib.Path(directory);(base/'api').mkdir()
 for name in ['accounts_post_amend.php','accounts_posting_preview.php']:
  shutil.copy(ROOT/'api'/name,base/'api'/name)
 (base/'auth_store.php').write_text("""<?php
 define('TT_DATA_DIR',__DIR__);
 function tt_require_login(){return ['role'=>'Super Admin'];}
 function tt_user_can_open_module($u,$m){return true;}
 function tt_verify_csrf($v){return $v==='fixture';}
 function tt_ensure_data_dir(){}
 function tt_post_correction_allowed($u,$j){return true;}
 function tt_user_can_access_entity($u,$e,$a){return true;}
 function tt_accounts_resolve_post_key($s,$id,$e=''){return $id;}
 function tt_accounts_input(){return $GLOBALS['input'];}
 """)
 (base/'api/accounts_post_amend_core.php').write_text('<?php function apa_correct(&$s,$u,$id,$b){throw new RuntimeException("Unexpected correction");}')
 (base/'api/accounts_post_delete_core.php').write_text("""<?php
 function apd_delete(&$s,$u,$id,$b){if(strlen($b['reason'])<5)throw new DomainException('Reason required');$j=$s['journals'][$id];$j['id']='REV';$j['lines']=[['account'=>'1110','debit'=>100,'credit'=>0],['account'=>'6210','debit'=>0,'credit'=>100]];$s['journals']['REV']=$j;$s['postDeletions'][]=['postId'=>$id];return ['publicPostId'=>$id];}
 """)
 harness=base/'request.php';harness.write_text("<?php $GLOBALS['input']=$argv[1];$_SERVER['REQUEST_METHOD']='POST';require __DIR__.'/api/accounts_post_amend.php';")
 source={'revision':7,'journals':{'POST-1':{'id':'POST-1','entity':'TTI','status':'Posted','sourceType':'JV','lines':[{'account':'1110','debit':0,'credit':100},{'account':'6210','debit':100,'credit':0}]}}}
 path=base/'accounts.json';path.write_text(json.dumps(source));before=path.read_bytes()
 def request(**extra):
  body={'csrf':'fixture','action':'delete_post','postId':'POST-1','reason':'Wrong entry',**extra}
  result=subprocess.run(['php',str(harness),json.dumps(body)],capture_output=True,text=True)
  assert result.returncode==0,result.stdout+result.stderr
  return json.loads(result.stdout)
 preview=request(preview=True);assert preview['ok'] and preview['previews'][0]['id']=='PREVIEW'
 assert preview['previews'][0]['lines'][0]['debit']==100
 assert path.read_bytes()==before,'Preview changed persisted books or revision'
 denied=request(preview=True,csrf='wrong');assert not denied['ok'] and path.read_bytes()==before
 invalid=request(preview=True,reason='');assert not invalid['ok'] and path.read_bytes()==before
 saved=request();assert saved['ok'];book=json.loads(path.read_text());assert book['revision']==8 and 'REV' in book['journals'] and len(book['postDeletions'])==1
 print('PASS real controller cancellation preview: reversal review, byte-identical storage, CSRF/validation rejection, explicit commit')
