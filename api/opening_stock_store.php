<?php
declare(strict_types=1);
require_once __DIR__.'/opening_stock_core.php';
function osb_save(array $input,array $user):void{
    $key='tt32stockadj';$now=gmdate('c');$name=(string)($user['full_name']??$user['username']??'');
    $env=static fn($k)=>(string)(defined('TT_DB_'.$k)?constant('TT_DB_'.$k):(getenv('TT_DB_'.$k)?:''));
    if($env('HOST')!==''&&$env('NAME')!==''&&$env('USER')!==''){
        $db=new PDO('mysql:host='.$env('HOST').';dbname='.$env('NAME').';charset=utf8mb4',$env('USER'),$env('PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $lock='tt_inventory_'.substr(hash('sha256',$env('NAME')),0,24);$take=$db->prepare('SELECT GET_LOCK(?,15)');$take->execute([$lock]);if((int)$take->fetchColumn()!==1)throw new RuntimeException('Stock storage is busy.');
        try{
            $db->beginTransaction();$q=$db->prepare('SELECT payload,version FROM tt_operation_records WHERE storage_key=? FOR UPDATE');$q->execute([$key]);$old=$q->fetch();$json=$old?(string)$old['payload']:'[]';$next=osb_apply($json,$input,$user,$now);
            if($next!==$json){
                $version=(int)($old['version']??0)+1;$stamp=(new DateTimeImmutable($now))->format('Y-m-d H:i:s.u');
                $q=$db->prepare('INSERT INTO tt_operation_records (storage_key,payload,version,updated_at,updated_by,updated_by_user,updated_by_module) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload),version=VALUES(version),updated_at=VALUES(updated_at),updated_by=VALUES(updated_by),updated_by_user=VALUES(updated_by_user),updated_by_module=VALUES(updated_by_module)');
                $q->execute([$key,$next,$version,$stamp,$name,(int)$user['id'],'Accounts']);
                $q=$db->prepare('INSERT INTO tt_operation_history (storage_key,version,payload_sha256,updated_at,updated_by,updated_by_user,updated_by_module) VALUES (?,?,?,?,?,?,?)');$q->execute([$key,$version,hash('sha256',$next),$stamp,$name,(int)$user['id'],'Accounts']);
            }$db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}finally{$q=$db->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}return;
    }
    $path=TT_DATA_DIR.'/operations.json';$h=is_file($path)?fopen($path,'r+'):false;
    if(!$h||!flock($h,LOCK_EX)){if(is_resource($h))fclose($h);throw new RuntimeException('Operational source is unavailable.');}
    try{
        $s=json_decode((string)stream_get_contents($h),true,512,JSON_THROW_ON_ERROR);if(!is_array($s)||!is_array($s['values']??null))throw new RuntimeException('Invalid operational source.');
        $json=$s['values'][$key]??'[]';$next=osb_apply($json,$input,$user,$now);
        if($next!==$json){$s['values'][$key]=$next;$s['revision']=(int)($s['revision']??0)+1;$s['meta'][$key]=['version'=>(int)($s['meta'][$key]['version']??0)+1,'updatedAt'=>$now,'updatedBy'=>$name,'userId'=>(int)$user['id'],'module'=>'Accounts'];$raw=json_encode($s,JSON_THROW_ON_ERROR);rewind($h);if(!ftruncate($h,0)||fwrite($h,$raw)!==strlen($raw)||!fflush($h))throw new RuntimeException('Opening stock could not be saved.');}
    }finally{flock($h,LOCK_UN);fclose($h);}
}
