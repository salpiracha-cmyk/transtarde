<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/tti-module-backup-'.bin2hex(random_bytes(8));$app=$root.'/app';mkdir($app,0700,true);
foreach(['auth_store.php','master_store.php','product_stage.php','offline_idempotency.php','session_store.php','backup_lib.php']as$file)copy(__DIR__.'/../../'.$file,$app.'/'.$file);
function module_expect(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
function module_clean(string $p):void{if(is_dir($p)&&!is_link($p)){foreach(scandir($p)as$n)if($n!=='.'&&$n!=='..')module_clean($p.'/'.$n);rmdir($p);}elseif(file_exists($p))unlink($p);}
try{
 require $app.'/auth_store.php';require $app.'/backup_lib.php';
 tt_mutate_store(static function(array &$s){$s['users'][]=['id'=>9,'username'=>'fixture','password_hash'=>'fictional-hash-must-not-export','role'=>'Super Admin'];});
 $ops=['revision'=>2,'values'=>['transtrade_export_v3_operational'=>json_encode(['shipments'=>[['id'=>'SHIP-1','containers'=>[['number'=>'BOX-1','weight'=>12]]]],'settings'=>['nonListSibling'=>'KEEP-ME']]),'tt30prod'=>json_encode([['id'=>'RUN-1','recovery'=>['bran'=>4]]])],'meta'=>[]];
 file_put_contents(TT_DATA_DIR.'/operations.json',json_encode($ops));
 file_put_contents(TT_DATA_DIR.'/accounts.json',json_encode(['journals'=>['JV-1'=>['entity'=>'TTI','date'=>'2026-10-26','status'=>'Posted','lines'=>[['account'=>'1100','debit'=>17,'credit'=>0,'subaccountId'=>'BANK-1']]]]]));
 mkdir(TT_DATA_DIR.'/documents',0700,true);file_put_contents(TT_DATA_DIR.'/documents/fixture.pdf','original-document-fixture');
 mkdir(TT_DATA_DIR.'/export_documents',0700,true);file_put_contents(TT_DATA_DIR.'/export_documents/opaque.pdf','shipment-original');
 file_put_contents(TT_DATA_DIR.'/export_documents/index.json',json_encode(['DOC-1'=>['id'=>'DOC-1','stored_name'=>'opaque.pdf','original_name'=>'Invoice.pdf','contract_ref'=>'FIX-001','lot_ref'=>'LOT-1','category'=>'Invoice']]));
 $password='fixture-module-password-2026';$built=tt_build_download_backup(false,$password);
 $zip=new ZipArchive();module_expect($zip->open($built['path'])===true,'ZIP must open');
 module_expect(@$zip->getFromName('Export_Index.csv')===false,'CSV must require password');
 $zip->setPassword('wrong-fixture-password');module_expect(@$zip->getFromName('Export_Index.csv')===false,'Wrong password rejected');
 $zip->setPassword($password);$index=$zip->getFromName('Export_Index.csv');module_expect(is_string($index)&&str_contains($index,'accounts.json'),'Canonical Accounts exported');
 $names=[];$all='';for($i=0;$i<$zip->numFiles;$i++){$names[]=$name=$zip->getNameIndex($i);$all.=$zip->getFromIndex($i);$stat=$zip->statIndex($i);module_expect(($stat['encryption_method']??0)!==0,'Every business file must be encrypted');}
 module_expect(str_contains($all,'BOX-1')&&str_contains($all,'KEEP-ME')&&str_contains($all,'/recovery/bran'),'Nested and mixed inputs must remain available');
 module_expect(in_array('Accounts/Ledgers/All_Posting_Lines.csv',$names,true),'Ledger lines missing');
 module_expect(in_array('Shared/Documents/documents/fixture.pdf',$names,true)&&$zip->getFromName('Shared/Documents/documents/fixture.pdf')==='original-document-fixture','Original document bytes preserved');
 module_expect(in_array('Exports/Shipment_Folders/FIX-001/LOT-1/Invoice/DOC-1-Invoice.pdf',$names,true),'Shipment files must retain human-readable contract/lot/category/name relationships');
 module_expect(!str_contains($all,'fictional-hash-must-not-export'),'Readable package must exclude login hashes');
 $zip->close();unlink($built['path']);
 $built=tt_build_download_backup(true,$password);[$zip,$manifest,$key]=tt_backup_open_verified($built['path'],$password);
 module_expect(isset($manifest['encryptedEntries']['Accounts/Ledgers/All_Posting_Lines.csv']),'Complete backup must include same business layout');
 $zip->close();unlink($built['path']);echo "PASS password-protected module CSV package, all nested inputs, canonical Accounts, original documents, secret exclusion and full-backup parity\n";
}finally{if(session_status()===PHP_SESSION_ACTIVE)session_write_close();module_clean($root);}
