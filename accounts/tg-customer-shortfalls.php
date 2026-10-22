<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once dirname(__DIR__).'/api/tg_customer_shortfall_core.php';
$user=tt_require_login();if(($user['role']??'')!=='Super Admin'){http_response_code(403);exit('Super Admin access required.');}
header('Cache-Control: no-store');header('Content-Type: text/html; charset=UTF-8');
$esc=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$review=null;$posted=null;$error='';$plan=[];$balances=[];$path=TT_DATA_DIR.'/accounts.json';
try{
    $isPost=$_SERVER['REQUEST_METHOD']==='POST';$commit=$isPost&&($_POST['action']??'')==='commit';
    if($isPost&&!tt_verify_csrf((string)($_POST['csrf']??'')))throw new DomainException('Session expired. Reopen this form.');
    $h=fopen($path,$commit?'c+':'r');if(!$h||!flock($h,$commit?LOCK_EX:LOCK_SH))throw new RuntimeException('Accounts storage unavailable.');
    try{
        rewind($h);$raw=(string)stream_get_contents($h);$store=json_decode($raw,true,512,JSON_THROW_ON_ERROR);$balances=tgcs_balances($store,'9999-12-31');
        if($isPost){
            if($commit){$saved=$_SESSION['tg_customer_shortfall_preview']??null;if(!is_array($saved)||$saved['expires']<time()||!hash_equals($saved['token'],(string)($_POST['token']??'')))throw new DomainException('Preview expired. Review again.');$plan=$saved['plan'];}
            else{$rows=[];foreach((array)($_POST['party']??[]) as $i=>$party)if(trim((string)$party)!=='')$rows[]=['party'=>(string)$party,'amount'=>(string)($_POST['amount'][$i]??'')];$plan=['date'=>(string)($_POST['date']??''),'reference'=>(string)($_POST['reference']??''),'narration'=>(string)($_POST['narration']??''),'rows'=>$rows];}
            $rate=tgn_master_rate('USD','AED',$plan['date'])??0;$result=tgcs_prepare($store,$plan,$user,(float)$rate);
            if($result['replayed']){$posted=$result['journal'];unset($_SESSION['tg_customer_shortfall_preview']);}
            elseif($commit){
                if(!hash_equals($saved['storeHash'],hash('sha256',$raw))||abs($saved['rate']-$rate)>.00000001)throw new DomainException('Accounts or rates changed. Review again before posting.');
                $ids=tt_reserve_post_id_block((array)$store['journals'],1,'Accounts','TG customer shortfall');$journal=$result['journal'];$journal['id']=$ids[0];$store['journals'][$journal['id']]=$journal;$store['revision']=(int)($store['revision']??0)+1;
                $encoded=json_encode($store,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
                if(disk_free_space(dirname($path))<strlen($encoded)+1048576)throw new RuntimeException('Not enough space to save safely.');
                rewind($h);if(!ftruncate($h,0)||fwrite($h,$encoded)!==strlen($encoded)||!fflush($h)){rewind($h);ftruncate($h,0);fwrite($h,$raw);fflush($h);throw new RuntimeException('Save failed; original Accounts restored.');}
                $posted=$journal;unset($_SESSION['tg_customer_shortfall_preview']);$balances=tgcs_balances($store,'9999-12-31');
            }else{$review=$result['journal'];$_SESSION['tg_customer_shortfall_preview']=['token'=>bin2hex(random_bytes(24)),'expires'=>time()+900,'plan'=>$plan,'storeHash'=>hash('sha256',$raw),'rate'=>$rate];}
        }
    }finally{flock($h,LOCK_UN);fclose($h);}
}catch(Throwable $e){$error=$e->getMessage();$review=null;}
?>
<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>TG customer shortfalls</title><style>body{margin:0;background:#eff4f3;font:16px Arial;color:#173139}header{padding:16px 24px;background:#173139;color:white;display:flex;gap:20px;align-items:center}header a{color:white;margin-left:auto}main{max-width:1000px;margin:24px auto;padding:24px;background:white;border-radius:12px}input,select,textarea{padding:10px;box-sizing:border-box;width:100%;margin:6px 0 16px}table{width:100%;border-collapse:collapse}td,th{padding:12px;text-align:left;border-bottom:1px solid #ddd}button{padding:12px 18px;background:#167356;color:white;border:0;border-radius:6px;cursor:pointer}.green{background:#e9f5ee;padding:18px}.error{background:#fee7e5;padding:16px}.row{display:grid;grid-template-columns:3fr 1fr;gap:12px}</style><header><b>TRANSTRADE</b><span>TG customer shortfalls</span><span><?=$esc($user['full_name']??'')?> · Super Admin</span><a href="../logout.php" aria-label="Log out">⏻</a></header><main><h1>Clear historical customer shortfalls</h1><p>TG · USD · Correspondent bank charges. Customer receivables reduce; bank balances stay unchanged. No VAT is added.</p>
<?php if($error):?><p role="alert" class="error"><?=$esc($error)?></p><?php endif;?>
<?php $shown=$posted?:$review;if($shown):?><section class="green"><h2><?=$posted?'Posted '.$esc($posted['id']):'Review accounting entries'?></h2><p><?=$esc($shown['date'].' · '.$shown['reference'])?></p><p><?=$esc($shown['narration'])?></p><table><tr><th>Account / customer</th><th>USD debit</th><th>USD credit</th></tr><?php foreach($shown['lines'] as $line):?><tr><td><?=$esc($line['accountName'].' · '.($line['counterparty']??$line['shortfallCustomer']??''))?></td><td><?=number_format($line['nativeDebit'],2)?></td><td><?=number_format($line['nativeCredit'],2)?></td></tr><?php endforeach;?></table><?php if($posted):?><p role="status">Saved in TG only. No bank, VAT, TTI or BRM posting.</p><?php else:?><form method="post"><input type="hidden" name="csrf" value="<?=$esc(tt_csrf())?>"><input type="hidden" name="action" value="commit"><input type="hidden" name="token" value="<?=$esc($_SESSION['tg_customer_shortfall_preview']['token'])?>"><button>Confirm and post shortfalls</button></form><?php endif;?></section><?php endif;?>
<?php if(!$posted&&!$review):?><form method="post"><input type="hidden" name="csrf" value="<?=$esc(tt_csrf())?>"><input type="hidden" name="action" value="preview"><label>Date<input type="date" name="date" required value="<?=$esc($plan['date']??'2026-06-30')?>"></label><label>Reference<input name="reference" required maxlength="80" value="<?=$esc($plan['reference']??'')?>"></label><label>Narration<textarea name="narration" required maxlength="500"><?=$esc($plan['narration']??'')?></textarea></label><?php for($i=0;$i<2;$i++):?><div class="row"><label>Customer <?=$i+1?><select name="party[]"><option value="">Choose customer</option><?php foreach($balances as $party=>$cents)if($cents>0&&$cents<=100000):?><option value="<?=$esc($party)?>" <?=($plan['rows'][$i]['party']??'')===$party?'selected':''?>><?=$esc($party)?> · USD <?=number_format($cents/100,2)?></option><?php endif;?></select></label><label>USD amount <?=$i+1?><input name="amount[]" inputmode="decimal" value="<?=$esc($plan['rows'][$i]['amount']??'')?>"></label></div><?php endfor;?><button>Review shortfalls</button></form><?php endif;?><p><a href="index.php">Return to Accounts</a></p></main><script src="/session-activity.js"></script></html>
