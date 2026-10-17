<?php
declare(strict_types=1);
/** Source before Accounts. A busy Accounts lock releases the source immediately. */
function msa_with_accounts(callable $fn): mixed {
    $path=TT_DATA_DIR.'/accounts.json';
    if(!is_file($path))throw new DomainException('Canonical Accounts storage is unavailable for dependency review.');
    $h=fopen($path,'r');if(!$h||!flock($h,LOCK_SH|LOCK_NB)){if(is_resource($h))fclose($h);throw new DomainException('Accounts is processing another action. Your source is unchanged; retry the amendment.');}
    try{$books=json_decode((string)stream_get_contents($h),true,512,JSON_THROW_ON_ERROR);if(!is_array($books))throw new RuntimeException('Invalid Accounts store.');return $fn($books);}
    finally{flock($h,LOCK_UN);fclose($h);}
}
function msa_write(string $key,array $user,callable $transform): array {
    $env=static fn($n)=>(string)(defined('TT_'.$n)?constant('TT_'.$n):(getenv('TT_'.$n)?:''));$now=gmdate('c');$name=(string)($user['full_name']??$user['username']??'');
    if($env('DB_HOST')!==''&&$env('DB_NAME')!==''&&$env('DB_USER')!==''){
        $db=new PDO('mysql:host='.$env('DB_HOST').';dbname='.$env('DB_NAME').';charset=utf8mb4',$env('DB_USER'),$env('DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $db->exec('SET SESSION innodb_lock_wait_timeout=10');$db->beginTransaction();
        try{
            $read=$db->prepare('SELECT payload,version FROM tt_operation_records WHERE storage_key=? FOR UPDATE');$read->execute([$key]);$existing=$read->fetch();if(!$existing)throw new DomainException('The saved bill collection is missing.');
            $values=[$key=>(string)$existing['payload']];if($key==='tt38reprocessbills'){$r=$db->prepare('SELECT payload FROM tt_operation_records WHERE storage_key=? LOCK IN SHARE MODE');$r->execute(['tt30slips']);$values['tt30slips']=(string)($r->fetchColumn()?:'[]');}
            $result=msa_with_accounts(static function($books)use($transform,$existing,$values,$key,$db,$user,$now,$name){
                $out=$transform($values,$books,$now);$version=(int)$existing['version'];
                if($out['value']!==$existing['payload']){
                    $version++;$stamp=(new DateTimeImmutable($now))->format('Y-m-d H:i:s.u');
                    $q=$db->prepare('UPDATE tt_operation_records SET payload=?,version=?,updated_at=?,updated_by=?,updated_by_user=?,updated_by_module=? WHERE storage_key=?');
                    $q->execute([$out['value'],$version,$stamp,$name,(int)$user['id'],'Mill',$key]);
                    $q=$db->prepare('INSERT INTO tt_operation_history (storage_key,version,payload_sha256,updated_at,updated_by,updated_by_user,updated_by_module) VALUES (?,?,?,?,?,?,?)');
                    $q->execute([$key,$version,hash('sha256',$out['value']),$stamp,$name,(int)$user['id'],'Mill']);
                }
                $db->commit();return $out+['version'=>$version];
            });return $result;
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    $path=TT_DATA_DIR.'/operations.json';$h=is_file($path)?fopen($path,'r+'):false;
    if(!$h||!flock($h,LOCK_EX|LOCK_NB)){if(is_resource($h))fclose($h);throw new DomainException('Mill sources are busy or unavailable. Your draft is retained; retry.');}
    try{
        $store=json_decode((string)stream_get_contents($h),true,512,JSON_THROW_ON_ERROR);if(!is_array($store))throw new RuntimeException('Invalid operational store.');
        return msa_with_accounts(static function($books)use($h,$store,$transform,$key,$user,$now,$name){
            $values=(array)($store['values']??[]);$out=$transform($values,$books,$now);$version=(int)($store['meta'][$key]['version']??0);
            if($out['value']!==($values[$key]??'')){
                $version++;$store['values'][$key]=$out['value'];$store['revision']=(int)($store['revision']??0)+1;
                $store['meta'][$key]=['version'=>$version,'updatedAt'=>$now,'updatedBy'=>$name,'userId'=>(int)$user['id'],'module'=>'Mill'];
                $raw=json_encode($store,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);rewind($h);
                if(!ftruncate($h,0)||fwrite($h,$raw)!==strlen($raw)||!fflush($h))throw new RuntimeException('The source amendment could not be saved.');
            }
            return $out+['version'=>$version];
        });
    }finally{flock($h,LOCK_UN);fclose($h);}
}
