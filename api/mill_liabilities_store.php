<?php
declare(strict_types=1);

/** Source lock precedes Accounts lock. No route installs schemas or changes sources. */
function ml_with_sources(callable $callback,array $extraKeys=[]): mixed {
    if(array_diff($extraKeys,['tt30slips','tt39rentpayments','tt39salaryadvances']))throw new InvalidArgumentException('Unsupported source collection.');
    $keys=array_merge(['tt38labourbills','tt38kebills','tt38reprocessbills','tt37processingexpenses'],$extraKeys);
    $env=static fn($key)=>(string)(defined('TT_'.$key)?constant('TT_'.$key):(getenv('TT_'.$key)?:''));
    if ($env('DB_HOST')!==''&&$env('DB_NAME')!==''&&$env('DB_USER')!=='') {
        $db=new PDO('mysql:host='.$env('DB_HOST').';dbname='.$env('DB_NAME').';charset=utf8mb4',$env('DB_USER'),$env('DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $db->beginTransaction();
        try {
            $read=$db->prepare('SELECT storage_key,payload FROM tt_operation_records WHERE storage_key IN ('.implode(',',array_fill(0,count($keys),'?')).') LOCK IN SHARE MODE');$read->execute($keys);$rows=$read->fetchAll(PDO::FETCH_KEY_PAIR);
            $result=$callback($rows);$db->rollBack();return $result;
        } catch (Throwable $e) {if ($db->inTransaction()) $db->rollBack();throw $e;}
    }
    $path=TT_DATA_DIR.'/operations.json';if (!is_file($path)) return $callback([]);
    $h=fopen($path,'r');if (!$h||!flock($h,LOCK_SH)) throw new RuntimeException('Mill sources unavailable.');
    try {$store=json_decode((string)stream_get_contents($h),true,512,JSON_THROW_ON_ERROR);return $callback((array)($store['values']??[]));}
    finally {flock($h,LOCK_UN);fclose($h);}
}
function ml_read_accounts(): array {
    $path=TT_DATA_DIR.'/accounts.json';if (!is_file($path)) return [];
    $h=fopen($path,'r');if (!$h||!flock($h,LOCK_SH)) throw new RuntimeException('Accounts storage unavailable.');
    try {$store=json_decode((string)stream_get_contents($h),true,512,JSON_THROW_ON_ERROR);if (!is_array($store)) throw new RuntimeException('Invalid Accounts store.');return $store;}
    finally {flock($h,LOCK_UN);fclose($h);}
}
