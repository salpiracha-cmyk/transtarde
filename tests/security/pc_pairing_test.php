<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/tti-pc-pair-'.bin2hex(random_bytes(8));$app=$root.'/app';mkdir($app,0700,true);
foreach(['auth_store.php','session_store.php','master_store.php','product_stage.php','offline_idempotency.php','office_backup_auth.php','office_pc_pairing.php','backup_lib.php','pending_uploads.php','backup_report_exports.php']as$f)copy(__DIR__.'/../../'.$f,$app.'/'.$f);
function pair_check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function pair_rejected(Closure $f):bool{try{$f();return false;}catch(InvalidArgumentException){return true;}}
try{
 require $app.'/auth_store.php';require $app.'/office_backup_auth.php';require $app.'/office_pc_pairing.php';require $app.'/backup_lib.php';
 tt_backup_password_set('fixture-managed-pc-password');$old=tt_office_backup_issue_token();
 $id=str_repeat('a',32);$secret=str_repeat('b',64);$start=tt_pc_pair_start($id,$secret,'Fixture PC <script>');
 pair_check(preg_match('/^[0-9]{6}$/D',$start['code'])===1,'Comparison code missing.');
 pair_check(tt_office_backup_token_valid($old),'Starting setup disconnected the old PC.');
 pair_check(tt_pc_pair_poll($id,$secret)['state']==='pending','Public start authorized a PC.');
 pair_check(pair_rejected(static fn()=>tt_pc_pair_poll($id,str_repeat('c',64))),'Wrong device secret accepted.');
 pair_check(!isset(tt_pc_pair_view($id)['secretHash']),'Website exposed device authorization metadata.');
 tt_pc_pair_approve($id);pair_check(!tt_office_backup_token_valid($old),'Owner approval did not replace old connection.');
 $file=(string)file_get_contents(tt_pc_pair_file());pair_check(!str_contains($file,$secret),'Device secret stored in plaintext.');
 $reply=tt_pc_pair_poll($id,$secret);pair_check($reply['state']==='approved'&&tt_office_backup_token_valid($reply['token']),'Authorized PC did not receive its key.');
 pair_check(!str_contains($file,$reply['token']),'PC token was stored in plaintext.');
 pair_check(pair_rejected(static fn()=>tt_pc_pair_poll($id,$secret)),'Device authorization replay accepted.');
 pair_check(pair_rejected(static fn()=>tt_pc_pair_approve($id)),'Expired approval replay accepted.');
 $id=str_repeat('d',32);tt_pc_pair_start($id,$secret,'Second PC');tt_pc_pair_approve($id);tt_office_backup_revoke_token();
 pair_check(pair_rejected(static fn()=>tt_pc_pair_poll($id,$secret)),'Disconnected authorization remained usable.');
 $id=str_repeat('e',32);tt_pc_pair_start($id,$secret,'Expired PC');
 $rows=json_decode(file_get_contents(tt_pc_pair_file()),true);$rows[$id]['expiresAt']=time()-1;file_put_contents(tt_pc_pair_file(),json_encode($rows));
 pair_check(tt_pc_pair_view($id)===null&&pair_rejected(static fn()=>tt_pc_pair_poll($id,$secret)),'Expired device request remained usable.');
 pair_check(!isset(tt_backup_private_files()['office-pairing.json'])&&!tt_backup_restore_allowed('office-pairing.json'),'Pairing credentials entered backups or restoration.');
 echo "PASS owner-approved PC pairing, encrypted one-use credentials, replay/expiry/revoke boundaries and backup exclusion\n";
}finally{
 if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
 foreach(glob($root.'/transtrade_private/*')?:[]as$p)@unlink($p);@rmdir($root.'/transtrade_private');foreach(glob($app.'/*')?:[]as$p)@unlink($p);@rmdir($app);@rmdir($root);
}
