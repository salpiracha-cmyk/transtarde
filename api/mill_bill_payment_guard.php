<?php
declare(strict_types=1);
require_once __DIR__.'/mill_liabilities_core.php';

/** Hold the source before the canonical payment lock, so a source edit cannot race payment. */
function mpg_acquire(): array {
    $env=static fn($key)=>(string)(defined('TT_'.$key)?constant('TT_'.$key):(getenv('TT_'.$key)?:''));
    $done=false;
    if ($env('DB_HOST')!==''&&$env('DB_NAME')!==''&&$env('DB_USER')!=='') {
        $db=new PDO('mysql:host='.$env('DB_HOST').';dbname='.$env('DB_NAME').';charset=utf8mb4',$env('DB_USER'),$env('DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $db->beginTransaction();
        try {$values=$db->query("SELECT storage_key,payload FROM tt_operation_records WHERE storage_key IN ('tt38labourbills','tt38kebills','tt38reprocessbills') LOCK IN SHARE MODE")->fetchAll(PDO::FETCH_KEY_PAIR);}
        catch(Throwable $e){$db->rollBack();throw $e;}
        $release=static function()use($db,&$done):void {if(!$done){$done=true;if($db->inTransaction())$db->rollBack();}};
    } else {
        $file=TT_DATA_DIR.'/operations.json';$h=null;$values=[];
        if (is_file($file)) {
            $h=fopen($file,'r');if(!$h||!flock($h,LOCK_SH))throw new RuntimeException('Mill payment sources unavailable.');
            try {$store=json_decode((string)stream_get_contents($h),true,512,JSON_THROW_ON_ERROR);$values=(array)($store['values']??[]);}
            catch(Throwable $e){flock($h,LOCK_UN);fclose($h);throw $e;}
        }
        $release=static function()use($h,&$done):void {if(!$done){$done=true;if(is_resource($h)){flock($h,LOCK_UN);fclose($h);}}};
    }
    // Existing controllers may respond early on validation failure. Release on
    // that path as well as after a successful canonical write.
    register_shutdown_function($release);return ['values'=>$values,'release'=>$release];
}

function mpg_assert(array $store,array $bill,array $values): void {
    $key=(string)($bill['sourceRecord']['millSourceKey']??'');
    if ($key==='') return;
    $record=$store['millLiabilities'][$key]??null;
    if (!is_array($record)||($record['status']??'')!=='Posted'||($record['billId']??'')!==($bill['id']??'')||($record['entity']??'')!==($bill['entity']??'')) throw new DomainException('This Mill bill is no longer the current posted liability. Reopen its linked review.');
    $matches=array_values(array_filter(ml_rows($values,(string)$record['sourceCollection']),static fn($r)=>is_array($r)&&(string)($r['id']??'')===(string)$record['sourceRecordId']&&($r['entity']??'')===$record['entity']));
    if (count($matches)!==1||!hash_equals(ml_fingerprint((array)$record['sourceSnapshot']),ml_fingerprint($matches[0]))) throw new DomainException('The Mill source changed or is missing. Reconcile its liability in Mill Bill Review before paying it.');
    $journal=$store['journals'][$record['journalId']??'']??null;
    if (!is_array($journal)||($journal['status']??'')!=='Posted'||($journal['meta']['millSourceKey']??'')!==$key) throw new DomainException('The Mill bill posting could not be verified. Reconcile its original Post ID before payment.');
}
