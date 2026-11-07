<?php
declare(strict_types=1);

/** Owner-authorized, Accounts-only production test-data reset. CLI only. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
function ar_arg(array $argv,string $name): string {$i=array_search($name,$argv,true);return $i!==false?(string)($argv[$i+1]??''):'';}
function ar_fail(string $message): never {fwrite(STDERR,$message."\n");exit(2);}
function ar_env(string $name):string {$constant='TT_'.$name;return defined($constant)?(string)constant($constant):(string)(getenv($constant)?:'');}

$target=ar_arg($argv,'--target');$resetId=ar_arg($argv,'--reset-id');$apply=in_array('--apply',$argv,true);
if(!preg_match('~^/home/[^/]+/domains/app\.transtradeinternational\.com/public_html$~',$target))ar_fail('Unexpected production path.');
if(!preg_match('/^[A-Za-z0-9._:-]{8,120}$/',$resetId))ar_fail('A unique Accounts reset ID is required.');
if(!is_file($target.'/auth_store.php')||!is_file($target.'/backup_lib.php'))ar_fail('Production configuration is unavailable.');
require $target.'/auth_store.php';
require $target.'/backup_lib.php';
require __DIR__.'/accounts_reset_core.php';

$accountPath=TT_DATA_DIR.'/accounts.json';
if(!is_file($accountPath))ar_fail('Accounts store is missing; no changes made.');
$accountHandle=fopen($accountPath,'c+');
if($accountHandle===false||!flock($accountHandle,$apply?LOCK_EX:LOCK_SH))ar_fail('Accounts store is locked.');
try{
    rewind($accountHandle);$old=json_decode(stream_get_contents($accountHandle)?:'',true,512,JSON_THROW_ON_ERROR);
    if(!is_array($old))throw new RuntimeException('Accounts store is invalid.');
    $counts=[];foreach($old as $key=>$value)if(is_array($value)&&!in_array($key,['masters','commodityKatMaster','bankAccountSettings','salaryMasters','rentMasters','utilityMasters','creditCardMasters','brokerageMaster','bagTaxRates','inventoryCostRates','localSalesCostRates','transportMaster'],true))$counts[$key]=count($value);
    $wasApplied=(string)($old['lastAccountsResetId']??'')===$resetId;
    if(!$apply){
        $advice=[];
        foreach((array)($old['exportReceipts']??[]) as $row){
            if(!is_array($row))continue;
            $ids=array_values(array_filter([(string)($row['journalId']??''),(string)($row['separateChargeJournalId']??'')]));
            foreach((array)($row['tgMirrorPostIds']??[]) as $mirrorId)$ids[]=(string)$mirrorId;
            $posts=[];foreach($ids as $jid){$journal=$old['journals'][$jid]??null;if(!is_array($journal))continue;
                $posts[]=['postId'=>$jid,'entity'=>$journal['entity']??'','lines'=>array_map(static fn($line)=>['account'=>$line['account']??'','debit'=>$line['debit']??0,'credit'=>$line['credit']??0],(array)($journal['lines']??[]))];}
            $advice[]=['id'=>$row['id']??'','date'=>$row['date']??'','entity'=>$row['entity']??'','currency'=>$row['transactionCurrency']??'','foreignAmount'=>$row['foreignAmount']??0,'bankCreditPkr'=>$row['pkrBankCredit']??null,'posts'=>$posts];
        }
        echo json_encode(['ok'=>true,'applied'=>false,'resetId'=>$resetId,'counts'=>$counts,'alreadyApplied'=>$wasApplied,'creditAdviceAudit'=>$advice],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;exit(0);
    }
    // Backup includes the complete private Accounts store before any mutation.
    $snapshot='existing reset '.(string)($old['lastAccountsResetAt']??'');
    if(!$wasApplied){
        // Capture the database-backed Exports receipt projection in the same private
        // server snapshot; the standard snapshot only includes private files.
        $dbHost=ar_env('DB_HOST');$dbName=ar_env('DB_NAME');$dbUser=ar_env('DB_USER');
        $projectionCopy=null;
        if($dbHost!==''||$dbName!==''||$dbUser!==''){
            if($dbHost===''||$dbName===''||$dbUser==='')throw new RuntimeException('Incomplete database configuration; no reset performed.');
            $copyDb=new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",$dbUser,ar_env('DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $fetch=$copyDb->prepare('SELECT payload FROM tt_operation_records WHERE storage_key=?');$fetch->execute(['transtrade_export_v3_operational']);
            $payload=$fetch->fetchColumn();if(!is_string($payload))throw new RuntimeException('Exports projection unavailable; no reset performed.');
            $projectionCopy=TT_DATA_DIR.'/pre-accounts-export-projection-'.$resetId.'.json';
            if(file_put_contents($projectionCopy,$payload,LOCK_EX)!==strlen($payload))throw new RuntimeException('Could not capture Exports projection.');
            chmod($projectionCopy,0600);
        }
        try{$snapshot=basename(tt_create_server_snapshot('pre-accounts-test-reset'));}
        finally{if($projectionCopy!==null)unlink($projectionCopy);}
    }
    if(!$wasApplied){
        $fresh=tt_accounts_reset_store($old,$resetId);
        $json=json_encode($fresh,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        rewind($accountHandle);if(!ftruncate($accountHandle,0)||fwrite($accountHandle,$json)!==strlen($json))throw new RuntimeException('Accounts reset write failed; restore the pre-reset snapshot.');
        fflush($accountHandle);
    }
}finally{flock($accountHandle,LOCK_UN);fclose($accountHandle);}

// Exports operational rows copied from Accounts must be cleared too. Contracts,
// shipments, FI/GD and document records remain untouched.
$projection=['database'=>false,'file'=>false];$host=ar_env('DB_HOST');$name=ar_env('DB_NAME');$user=ar_env('DB_USER');
if($host!==''||$name!==''||$user!==''){
    if($host===''||$name===''||$user==='')throw new RuntimeException('Incomplete database configuration; projection cleanup requires retry.');
    $db=new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4",$user,ar_env('DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $db->beginTransaction();
    try{
        $key='transtrade_export_v3_operational';$select=$db->prepare('SELECT payload,version FROM tt_operation_records WHERE storage_key=? FOR UPDATE');$select->execute([$key]);$record=$select->fetch();
        if(!is_array($record))throw new RuntimeException('Exports operational record missing.');
        $root=json_decode((string)$record['payload'],true,512,JSON_THROW_ON_ERROR);if(!is_array($root))throw new RuntimeException('Exports operational record invalid.');
        $root=tt_accounts_reset_export_root($root);$payload=json_encode($root,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $version=(int)$record['version']+1;$now=gmdate('Y-m-d H:i:s.u');
        $update=$db->prepare('UPDATE tt_operation_records SET payload=?,version=?,updated_at=?,updated_by=?,updated_by_user=NULL,updated_by_module=? WHERE storage_key=?');$update->execute([$payload,$version,$now,'Owner Accounts test reset','Accounts',$key]);
        $history=$db->prepare('INSERT INTO tt_operation_history (storage_key,version,payload_sha256,updated_at,updated_by,updated_by_user,updated_by_module) VALUES (?,?,?,?,?,NULL,?)');$history->execute([$key,$version,hash('sha256',$payload),$now,'Owner Accounts test reset','Accounts']);
        $db->commit();$projection['database']=true;
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
}
$operations=TT_DATA_DIR.'/operations.json';
if(is_file($operations)){
    $h=fopen($operations,'c+');if($h===false||!flock($h,LOCK_EX))throw new RuntimeException('Operations store locked; projection cleanup requires retry.');
    try{
        rewind($h);$store=json_decode(stream_get_contents($h)?:'',true,512,JSON_THROW_ON_ERROR);if(!is_array($store))throw new RuntimeException('Operations store invalid.');
        $key='transtrade_export_v3_operational';$root=json_decode((string)($store['values'][$key]??''),true);
        if(is_array($root)){
            $store['values'][$key]=json_encode(tt_accounts_reset_export_root($root),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            $store['revision']=(int)($store['revision']??0)+1;$store['meta'][$key]['version']=(int)($store['meta'][$key]['version']??0)+1;
            $store['meta'][$key]['updatedAt']=gmdate('c');$store['meta'][$key]['updatedBy']='Owner Accounts test reset';
            $json=json_encode($store,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            rewind($h);if(!ftruncate($h,0)||fwrite($h,$json)!==strlen($json))throw new RuntimeException('Operations projection write failed; retry required.');fflush($h);$projection['file']=true;
        }
    }finally{flock($h,LOCK_UN);fclose($h);}
}
$verified=json_decode((string)file_get_contents($accountPath),true,512,JSON_THROW_ON_ERROR);
if(!is_array($verified)||!empty($verified['journals'])||!empty($verified['exportReceipts'])||($verified['lastAccountsResetId']??'')!==$resetId)throw new RuntimeException('Accounts reset verification failed.');
echo json_encode(['ok'=>true,'applied'=>true,'resetId'=>$resetId,'snapshot'=>$snapshot,'previousCounts'=>$counts,'exportsReceiptProjection'=>$projection,'preserved'=>['Milling','Exports contracts/shipments/FI/GD','users','companies','banks','currencies','Accounts masters']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
