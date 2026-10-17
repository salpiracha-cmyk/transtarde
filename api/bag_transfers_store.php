<?php
declare(strict_types=1);
const BTS_STATE_KEY='tt41bagtransfers';
const BTS_SOURCE_KEYS=['transtrade_export_v3_operational','tt30bags','tt30prod','tt33bagreceipts','tt36bagissues',BTS_STATE_KEY];
function bts_state(array $values): array {
    $raw=$values[BTS_STATE_KEY]??'';$state=$raw===''?[]:(is_string($raw)?json_decode($raw,true,512,JSON_THROW_ON_ERROR):$raw);
    if(!is_array($state)||($state&&($state['schemaVersion']??null)!==1))throw new DomainException('The saved bag movement schema requires review.');
    return array_replace(['schemaVersion'=>1,'transfers'=>[],'requests'=>[],'audit'=>[],'reconciliations'=>[]],$state);
}
function bts_result(array $result,array $values,array $versions,int $stateVersion): array {
    if(isset($result['order']))foreach(['tt30bags','tt33bagreceipts'] as $key)$result['sourceValues'][$key]=['value'=>$values[$key]??'[]','version'=>(int)($versions[$key]??0)];
    return $result+['stateVersion'=>$stateVersion];
}
/** Same global stock lock as production, then source rows in deterministic order. No Accounts lock or writes. */
function bts_with_sources(bool $write,array $user,callable $fn): array {
    $env=static fn($n)=>(string)(defined('TT_'.$n)?constant('TT_'.$n):(getenv('TT_'.$n)?:''));$now=gmdate('c');
    if($env('DB_HOST')!==''&&$env('DB_NAME')!==''&&$env('DB_USER')!==''){
        $db=new PDO('mysql:host='.$env('DB_HOST').';dbname='.$env('DB_NAME').';charset=utf8mb4',$env('DB_USER'),$env('DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$db->exec('SET SESSION innodb_lock_wait_timeout=10');
        $lock='tt_inventory_'.substr(hash('sha256',$env('DB_NAME')),0,24);$held=false;
        try{
            if($write){$q=$db->prepare('SELECT GET_LOCK(?,10)');$q->execute([$lock]);if((int)$q->fetchColumn()!==1)throw new DomainException('Stock is being committed. Your entry is retained; retry.');$held=true;}
            $db->beginTransaction();$q=$db->prepare('SELECT storage_key,payload,version FROM tt_operation_records WHERE storage_key IN ('.implode(',',array_fill(0,count(BTS_SOURCE_KEYS),'?')).') ORDER BY storage_key '.($write?'FOR UPDATE':'LOCK IN SHARE MODE'));$q->execute(BTS_SOURCE_KEYS);$values=[];$versions=[];
            foreach($q->fetchAll() as $row){$values[$row['storage_key']]=$row['payload'];$versions[$row['storage_key']]=(int)$row['version'];}
            $sourceBefore=$values;$state=bts_state($values);$before=$state;$result=$fn($values,$state,$now);$version=$versions[BTS_STATE_KEY]??0;
            if($write){
                if($state!==$before)$values[BTS_STATE_KEY]=json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
                foreach($values as $changedKey=>$payload){
                    if(($sourceBefore[$changedKey]??null)===$payload)continue;
                    if(!in_array($changedKey,[BTS_STATE_KEY,'tt30bags','tt33bagreceipts'],true))throw new RuntimeException('Unsupported bag source mutation.');
                    $newVersion=($versions[$changedKey]??0)+1;$versions[$changedKey]=$newVersion;if($changedKey===BTS_STATE_KEY)$version=$newVersion;
                    $stamp=(new DateTimeImmutable($now))->format('Y-m-d H:i:s.u');$name=(string)($user['full_name']??$user['username']??'');
                    $q=$db->prepare('INSERT INTO tt_operation_records (storage_key,payload,version,updated_at,updated_by,updated_by_user,updated_by_module) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload),version=VALUES(version),updated_at=VALUES(updated_at),updated_by=VALUES(updated_by),updated_by_user=VALUES(updated_by_user),updated_by_module=VALUES(updated_by_module)');$q->execute([$changedKey,$payload,$newVersion,$stamp,$name,(int)$user['id'],'Mill']);
                    $q=$db->prepare('INSERT INTO tt_operation_history (storage_key,version,payload_sha256,updated_at,updated_by,updated_by_user,updated_by_module) VALUES (?,?,?,?,?,?,?)');$q->execute([$changedKey,$newVersion,hash('sha256',$payload),$stamp,$name,(int)$user['id'],'Mill']);
                }
            }
            $db->commit();return bts_result($result,$values,$versions,$version);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        finally{if($held){$q=$db->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}}
    }
    $path=TT_DATA_DIR.'/operations.json';
    if(!is_file($path)){if($write)throw new DomainException('No committed bag stock source exists.');$state=bts_state([]);return $fn([],$state,$now)+['stateVersion'=>0];}
    $h=fopen($path,$write?'r+':'r');if(!$h||!flock($h,$write?LOCK_EX:LOCK_SH))throw new RuntimeException('Bag stock sources unavailable.');
    try{
        $store=json_decode((string)stream_get_contents($h),true,512,JSON_THROW_ON_ERROR);if(!is_array($store))throw new RuntimeException('Invalid stock source store.');$values=(array)($store['values']??[]);$sourceBefore=$values;$state=bts_state($values);$before=$state;$result=$fn($values,$state,$now);$version=(int)($store['meta'][BTS_STATE_KEY]['version']??0);
        if($write){
            if($state!==$before)$values[BTS_STATE_KEY]=json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$changed=false;
            foreach($values as $changedKey=>$payload){
                if(($sourceBefore[$changedKey]??null)===$payload)continue;
                if(!in_array($changedKey,[BTS_STATE_KEY,'tt30bags','tt33bagreceipts'],true))throw new RuntimeException('Unsupported bag source mutation.');
                $newVersion=(int)($store['meta'][$changedKey]['version']??0)+1;if($changedKey===BTS_STATE_KEY)$version=$newVersion;
                $store['values'][$changedKey]=$payload;$store['meta'][$changedKey]=['version'=>$newVersion,'updatedAt'=>$now,'updatedBy'=>(string)($user['full_name']??$user['username']??''),'userId'=>(int)$user['id'],'module'=>'Mill'];$changed=true;
            }
            if($changed){$store['revision']=(int)($store['revision']??0)+1;$raw=json_encode($store,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);rewind($h);if(!ftruncate($h,0)||fwrite($h,$raw)!==strlen($raw)||!fflush($h))throw new RuntimeException('The bag movement could not be confirmed.');}
        }
        return bts_result($result,$values,array_map(static fn($r)=>(int)($r['version']??0),(array)($store['meta']??[])),$version);
    }finally{flock($h,LOCK_UN);fclose($h);}
}
