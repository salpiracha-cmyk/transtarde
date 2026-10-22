<?php
declare(strict_types=1);
require dirname(__DIR__).'/auth_store.php';
require_once dirname(__DIR__).'/api/opening_balance_core.php';
require_once dirname(__DIR__).'/api/tg_native_currency.php';
require_once dirname(__DIR__).'/api/tg_usd_history_core.php';
$user=tt_require_login();
if (($user['role']??'')!=='Super Admin') { http_response_code(403); exit('Super Admin access required.'); }
header('Content-Type: text/html; charset=UTF-8'); header('Cache-Control: no-store');
$escape=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$format=static fn($n)=>number_format((float)$n,2);
$message='';$error='';$review=null;$result=null;$manifest='';$evidence=[];
$path=TT_DATA_DIR.'/accounts.json';
try {
    $bank=null; foreach(job_banks('TG') as $b) if($b['id']==='banks-2'&&$b['currency']==='USD') $bank=$b;
    if(!$bank) throw new DomainException('The TG Habib USD bank is unavailable.');
    $rate=tgn_master_rate('USD','AED','2026-06-30');
    if(!$rate) throw new DomainException('Configure the TG USD/AED master rate first.');
    $write=$_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='commit';
    if($_SERVER['REQUEST_METHOD']==='POST'&&!tt_verify_csrf((string)($_POST['csrf']??''))) throw new DomainException('Session expired. Refresh this page.');
    $h=fopen($path,$write?'c+':'r');
    if(!$h||!flock($h,$write?LOCK_EX:LOCK_SH)) throw new RuntimeException('Accounts storage is unavailable.');
    try {
        rewind($h); $raw=(string)stream_get_contents($h); $store=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($store)) throw new RuntimeException('Accounts storage is unavailable.');
        if($write) {
            $token=(string)($_POST['previewToken']??''); $saved=$_SESSION['tg_usd_history_preview']??null;
            if(!is_array($saved)||!hash_equals($saved['token'],$token)||$saved['expires']<time()) throw new DomainException('Preview expired. Review the batch again.');
            $manifest=$saved['manifest']; $plan=json_decode($manifest,true,512,JSON_THROW_ON_ERROR);
            if(!isset($store['tgUsdHistoryBatches'][$plan['batch']??''])&&!hash_equals($saved['storeHash'],hash('sha256',$raw))) throw new DomainException('Accounts changed after this preview. Review the same batch again before posting.');
            $review=tgh_prepare($store,$plan,$user,$bank,(float)$rate);
            if($review['replayed']) $result=$review['result'];
            else {
                $next=$review['store'];$mapped=[];$postIds=[];
                foreach(array_keys($review['journals']) as $temporary) unset($next['journals'][$temporary]);
                foreach($review['journals'] as $temporary=>$journal) {
                    $id=tt_next_post_id((array)$next['journals'],'Accounts','Journal',(string)$journal['date']);
                    $mapped[$temporary]=$id; $journal['id']=$id;
                    if(($journal['meta']['kind']??'')==='OPENING'&&($journal['lines'][0]['account']??'')==='1110') $journal['sourceType']='OPENING_BALANCE_BF';
                    $next['journals'][$id]=$journal;$postIds[]=$id;
                }
                foreach($next['tgBankTransactions']??[] as $id=>$transaction) if(($transaction['tgHistoryBatch']??'')===$review['batch']&&isset($mapped[$transaction['journalId']??''])) $next['tgBankTransactions'][$id]['journalId']=$mapped[$transaction['journalId']];
                $old=$review['openingId'];$next['journals'][$old]['openingReversalId']=$mapped[$next['journals'][$old]['openingReversalId']];
                foreach($next['jvDrafts']??[] as $id=>$draft) if(($draft['journalId']??'')===$old) { $next['jvDrafts'][$id]['status']='Reversed';$next['jvDrafts'][$id]['reversalJournalId']=$next['journals'][$old]['openingReversalId'];$next['jvDrafts'][$id]['reversalReason']='Reconciled TG USD January–June reconstruction'; }
                $result=['hash'=>$review['hash'],'postIds'=>$postIds,'juneClosing'=>$review['juneClosing'],'currentClosing'=>$review['currentClosing'],'postedAt'=>gmdate('c'),'postedBy'=>$user['full_name']??'Super Admin'];
                $next['tgUsdHistoryBatches'][$review['batch']]=$result;$next['revision']=(int)($store['revision']??0)+1;
                $encoded=json_encode($next,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                if(disk_free_space(dirname($path))<strlen($encoded)+1048576) throw new RuntimeException('Not enough space to save safely. Nothing was posted.');
                rewind($h);
                if(!ftruncate($h,0)||fwrite($h,$encoded)!==strlen($encoded)||!fflush($h)) { rewind($h);ftruncate($h,0);fwrite($h,$raw);fflush($h);throw new RuntimeException('Save failed; the original Accounts data was restored.'); }
                $store=$next;
            }
            unset($_SESSION['tg_usd_history_preview']);$review=null;$message='Historical batch posted in TG only. TTI, BRM and AED bank records were not changed.';
        } elseif($_SERVER['REQUEST_METHOD']==='POST') {
            $manifest=(string)($_POST['manifest']??'');
            if(strlen($manifest)>150000) throw new DomainException('Batch is too large.');
            $plan=json_decode($manifest,true,512,JSON_THROW_ON_ERROR);
            if(!is_array($plan)) throw new DomainException('Provide a reconstruction batch.');
            $review=tgh_prepare($store,$plan,$user,$bank,(float)$rate);
            if($review['replayed']) {$result=$review['result'];$review=null;$message='This exact batch is already posted. No duplicate entries were created.';}
            else $_SESSION['tg_usd_history_preview']=['token'=>bin2hex(random_bytes(24)),'manifest'=>$manifest,'storeHash'=>hash('sha256',$raw),'expires'=>time()+900];
        }
        foreach($store['journals']??[] as $journal) if(($journal['entity']??'')==='TG'&&($journal['status']??'')==='Posted'&&in_array($journal['sourceType']??'',['CARRY_FORWARD_OPENING','OPENING_BALANCE_BF'],true)) {
            $evidence[]=['id'=>$journal['id'],'date'=>$journal['date'],'reference'=>$journal['reference']??'','narration'=>$journal['narration']??'','lines'=>array_map(static fn($l)=>['account'=>$l['account']??'','party'=>$l['counterparty']??$l['subledger']??'','currency'=>$l['currency']??'AED','nativeDebit'=>$l['nativeDebit']??$l['bankDebit']??null,'nativeCredit'=>$l['nativeCredit']??$l['bankCredit']??null],$journal['lines']??[])];
        }
    } finally {flock($h,LOCK_UN);fclose($h);}
} catch(DomainException|JsonException $x) {$error=$x->getMessage();$review=null;}
catch(Throwable $x) {$error='The reconstruction could not be saved. '.$x->getMessage();$review=null;}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>TG USD Historical Reconstruction</title>
<style>body{margin:0;background:#eff4f3;color:#173139;font:15px Arial,sans-serif}.topbar{display:flex;align-items:center;gap:18px;padding:16px 24px;background:#173139;color:white}.topbar a{color:inherit}.sp{flex:1}.power{border-left:1px solid #ffffff55;padding-left:16px;text-decoration:none;font-size:22px}main{max-width:1100px;margin:24px auto;padding:24px;background:white;border-radius:12px}textarea{box-sizing:border-box;width:100%;min-height:170px;padding:12px;font:13px monospace;border:1px solid #bacbc7;border-radius:8px}button{padding:12px 18px;border:0;border-radius:8px;background:#167356;color:white;cursor:pointer;font-weight:700;margin:12px 0}.error{background:#fee7e5;color:#941d15;padding:16px}.success,.review{background:#e9f5ee;padding:16px}.entry{margin:16px 0;padding:16px;border:1px solid #c9ded1;border-radius:9px}table{border-collapse:collapse;width:100%;margin-top:12px}td,th{padding:9px;border-bottom:1px solid #d7e3de;text-align:left}td.num{text-align:right;white-space:nowrap}h1{margin-top:0}details{margin-top:18px}pre{white-space:pre-wrap;overflow-wrap:anywhere}</style></head><body>
<header class="topbar"><b>TRANSTRADE</b><span>TG USD History</span><span class="sp"></span><span><?=$escape($user['full_name']??'')?> · Super Admin</span><a class="power" href="../logout.php" aria-label="Log out">⏻</a></header>
<main><h1>TG USD historical reconstruction</h1><p>January–June 2026 · Habib USD account · TG books only. Approved correspondent-bank shortfalls clear linked invoices without moving bank money.</p>
<?php if($error):?><p role="alert" class="error"><?=$escape($error)?></p><?php endif;?>
<?php if($message):?><p role="status" class="success"><?=$escape($message)?></p><?php endif;?>
<?php if($result):?><section class="success"><h2>Posted batch</h2><p>30 June balance: USD <?=$format($result['juneClosing'])?> · Current balance: USD <?=$format($result['currentClosing'])?></p><p>Post IDs: <?=$escape(implode(', ',$result['postIds']))?></p></section><?php endif;?>
<?php if($review):?><section class="review"><h2>Review accounting entries</h2><p><?=$escape(count($review['journals']))?> postings · 30 June bank balance USD <?=$format($review['juneClosing'])?> · Current bank balance USD <?=$format($review['currentClosing'])?></p><p>The July bank B/F is reversed only as part of this complete, reconciled batch. Existing invoice opening balances are bridged; existing receipts and payments remain intact.</p>
<?php foreach($review['journals'] as $journal):?><article class="entry"><b><?=$escape($journal['date'].' · '.$journal['reference'])?></b><p><?=$escape($journal['narration'])?></p><table><thead><tr><th>Account / party</th><th>USD debit</th><th>USD credit</th></tr></thead><tbody><?php foreach($journal['lines'] as $line):?><tr><td><?=$escape(($line['accountName']??$line['account']).' · '.($line['counterparty']??$line['bankName']??''))?></td><td class="num"><?=$format($line['nativeDebit']??$line['bankDebit']??0)?></td><td class="num"><?=$format($line['nativeCredit']??$line['bankCredit']??0)?></td></tr><?php endforeach;?></tbody></table></article><?php endforeach;?>
<form method="post"><input type="hidden" name="csrf" value="<?=$escape(tt_csrf())?>"><input type="hidden" name="action" value="commit"><input type="hidden" name="previewToken" value="<?=$escape($_SESSION['tg_usd_history_preview']['token'])?>"><button type="submit">Confirm and post TG historical batch</button></form></section><?php endif;?>
<form method="post"><input type="hidden" name="csrf" value="<?=$escape(tt_csrf())?>"><input type="hidden" name="action" value="preview"><label for="manifest">Historical entry batch</label><textarea id="manifest" name="manifest" required><?=$escape($manifest)?></textarea><button type="submit">Preview TG historical entries</button></form>
<details><summary>Existing TG invoice/opening evidence</summary><pre id="historyEvidence"><?=$escape(json_encode($evidence,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))?></pre></details><p><a href="index.php">Return to Accounts</a></p></main><script src="/session-activity.js"></script></body></html>
