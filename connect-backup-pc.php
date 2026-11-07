<?php
declare(strict_types=1);
require __DIR__.'/auth_store.php';require __DIR__.'/office_backup_auth.php';require __DIR__.'/office_pc_pairing.php';
$user=tt_require_login();if(($user['role']??'')!=='Super Admin'){http_response_code(403);exit('Super Admin access required.');}
header('Cache-Control: no-store');$id=(string)($_GET['request']??'');$error='';$done=false;
if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
    if(!tt_verify_csrf((string)($_POST['csrf']??''))){http_response_code(419);$error='Refresh this page and try again.';}
    else try{tt_pc_pair_approve($id);tt_audit((int)$user['id'],$user['username'],'Approved replacement backup PC connection');$done=true;}catch(Throwable $e){$error=$e instanceof InvalidArgumentException?$e->getMessage():'PC authorization could not be completed safely.';}
}
$pair=tt_pc_pair_view($id);function pair_escape(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Connect backup PC · Transtrade</title><link rel="stylesheet" href="admin/auth.css"></head><body>
<header style="display:flex;justify-content:space-between;padding:20px"><a href="index.php">Transtrade</a><a href="logout.php" aria-label="Log out">⏻</a></header>
<main style="max-width:560px;margin:40px auto;padding:28px;background:white;border-radius:16px;color:#18372b"><h1>Connect your backup PC</h1>
<?php if($error):?><p role="alert"><?=pair_escape($error)?></p><?php endif;?>
<?php if($done):?><h2>PC approved</h2><p>Return to the installer. It will finish the folders, schedule and first backup automatically.</p>
<?php elseif(!$pair):?><p>This request expired. Open the installer and choose Install again.</p>
<?php elseif($pair['state']==='approved'):?><p>This PC has been approved. Return to the installer.</p>
<?php else:?><p>Computer: <strong><?=pair_escape($pair['computer'])?></strong></p><p>Match this number with the number shown in your installer:</p><p style="font-size:36px;font-weight:bold;letter-spacing:8px"><?=pair_escape($pair['code'])?></p>
<p>Approve only the installer you opened yourself. This replaces the previous backup PC connection. Existing backup files and their passwords stay as they are.</p>
<form method="post"><input type="hidden" name="csrf" value="<?=pair_escape(tt_csrf())?>"><button type="submit" style="padding:14px 24px">Approve this PC</button></form>
<?php endif;?></main></body></html>
