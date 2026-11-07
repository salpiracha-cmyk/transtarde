<?php
declare(strict_types=1);
require_once __DIR__.'/mill_source_amend_store.php';

/** Deletion holds the same stock lock as packing and loading, then the Accounts read lock. */
function mrd_write(array $user,array $body): array {
    $env=static fn($n)=>(string)(defined('TT_'.$n)?constant('TT_'.$n):(getenv('TT_'.$n)?:''));
    $now=gmdate('c');$name=(string)($user['full_name']??$user['username']??'');
    if($env('DB_HOST')!==''&&$env('DB_NAME')!==''&&$env('DB_USER')!==''){
        $db=new PDO('mysql:host='.$env('DB_HOST').';dbname='.$env('DB_NAME').';charset=utf8mb4',$env('DB_USER'),$env('DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $db->exec('SET SESSION innodb_lock_wait_timeout=10');
        $lock='tt_inventory_'.substr(hash('sha256',$env('DB_NAME')),0,24);$held=false;
        try{
            $q=$db->prepare('SELECT GET_LOCK(?,10)');$q->execute([$lock]);if((int)$q->fetchColumn()!==1)throw new DomainException('Stock is being saved. Retry the same deletion.');$held=true;
            $db->beginTransaction();$values=[];$meta=[];
            foreach($db->query('SELECT storage_key,payload,version FROM tt_operation_records ORDER BY storage_key FOR UPDATE')->fetchAll() as $row){
                $values[$row['storage_key']]=$row['payload'];$meta[$row['storage_key']]=['version'=>(int)$row['version']];
            }
            return msa_with_accounts(static function($books)use($values,$meta,$user,$body,$now,$name,$db){
                $out=mrd_apply($values,$books,$user,$body,$now);$next=$out['values'];$versions=$meta;
                foreach($next as $key=>$payload){
                    if(($values[$key]??null)===$payload)continue;
                    $version=(int)($meta[$key]['version']??0)+1;$versions[$key]=['version'=>$version];
                    $stamp=(new DateTimeImmutable($now))->format('Y-m-d H:i:s.u');
                    $q=$db->prepare('INSERT INTO tt_operation_records (storage_key,payload,version,updated_at,updated_by,updated_by_user,updated_by_module) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload),version=VALUES(version),updated_at=VALUES(updated_at),updated_by=VALUES(updated_by),updated_by_user=VALUES(updated_by_user),updated_by_module=VALUES(updated_by_module)');
                    $q->execute([$key,$payload,$version,$stamp,$name,(int)$user['id'],'Mill']);
                    $q=$db->prepare('INSERT INTO tt_operation_history (storage_key,version,payload_sha256,updated_at,updated_by,updated_by_user,updated_by_module) VALUES (?,?,?,?,?,?,?)');
                    $q->execute([$key,$version,hash('sha256',$payload),$stamp,$name,(int)$user['id'],'Mill']);
                }
                $db->commit();return ['receipt'=>$out['receipt'],'values'=>$next,'meta'=>$versions];
            });
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        finally{if($held){$q=$db->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}}
    }
    $path=TT_DATA_DIR.'/operations.json';$h=is_file($path)?fopen($path,'r+'):false;
    if(!$h||!flock($h,LOCK_EX)){if(is_resource($h))fclose($h);throw new DomainException('Saved entries are unavailable. Retry the same deletion.');}
    try{
        $store=json_decode((string)stream_get_contents($h),true,512,JSON_THROW_ON_ERROR);
        if(!is_array($store))throw new RuntimeException('Invalid operational store.');
        return msa_with_accounts(static function($books)use($store,$user,$body,$now,$name,$h){
            $values=(array)($store['values']??[]);$out=mrd_apply($values,$books,$user,$body,$now);$changed=false;
            foreach($out['values'] as $key=>$payload){
                if(($values[$key]??null)===$payload)continue;
                $store['values'][$key]=$payload;$store['meta'][$key]=['version'=>(int)($store['meta'][$key]['version']??0)+1,'updatedAt'=>$now,'updatedBy'=>$name,'userId'=>(int)$user['id'],'module'=>'Mill'];$changed=true;
            }
            if($changed){
                $store['revision']=(int)($store['revision']??0)+1;
                $raw=tt_inv_json($store);rewind($h);
                if(!ftruncate($h,0)||fwrite($h,$raw)!==strlen($raw)||!fflush($h))throw new RuntimeException('Deletion could not be confirmed.');
            }
            return ['receipt'=>$out['receipt'],'values'=>$out['values'],'meta'=>$store['meta']??[]];
        });
    }finally{flock($h,LOCK_UN);fclose($h);}
}
