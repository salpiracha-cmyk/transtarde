<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require __DIR__.'/arrival_dependency_core.php';

// Do not include auth_store.php: diagnostics must not create sessions or folders.
$options=getopt('',['root:']);$root=realpath((string)($options['root']??(getenv('TT_DIAGNOSTIC_ROOT')?:dirname(__DIR__))));
if (!$root||!is_file($root.'/auth_store.php')) {fwrite(STDERR,"Application root required.\n");exit(1);}
$private=dirname($root).'/transtrade_private';$handles=[];
try {
    $read=static function(string $path)use(&$handles):array {
        if (!is_file($path)) throw new RuntimeException('Required data store missing: '.basename($path));
        $h=fopen($path,'r');if (!$h||!flock($h,LOCK_SH)) throw new RuntimeException('Could not read-lock '.basename($path));
        $handles[]=$h;$raw=stream_get_contents($h);$data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new RuntimeException('Invalid data store.');
        return [$data,hash('sha256',$raw)];
    };
    $env=static fn($name)=>(string)(getenv('TT_'.$name)?:'');$backend='file';
    if ($env('DB_HOST')!==''&&$env('DB_NAME')!==''&&$env('DB_USER')!=='') {
        $db=new PDO('mysql:host='.$env('DB_HOST').';dbname='.$env('DB_NAME').';charset=utf8mb4',$env('DB_USER'),$env('DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $db->exec('SET TRANSACTION READ ONLY');$db->beginTransaction();
        $rows=$db->query('SELECT storage_key,payload,version FROM tt_operation_records ORDER BY storage_key')->fetchAll(PDO::FETCH_ASSOC);
        $operations=['values'=>[]];foreach ($rows as $r) $operations['values'][$r['storage_key']]=$r['payload'];
        $operationsHash=hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));$backend='mysql';
    } else {[$operations,$operationsHash]=$read($private.'/operations.json');}
    [$accounts,$accountsHash]=$read($private.'/accounts.json');
    $report=arrival_dependency_audit((array)($operations['values']??[]),$accounts);
    $report['backend']=$backend;$report['storeHashes']=['operations'=>$operationsHash,'accounts'=>$accountsHash];
    // Target metadata only; do not emit arbitrary remarks, document data or images.
    foreach ($report['targets'] as &$row) $row=array_intersect_key($row,array_flip(['id','bridgeId','queueId','slip','truck','broker','soda','entity','millId','millName','locationId','sourceSodaId','arrivalDate','unloadingDate','payableWeight','bags']));unset($row);
    echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
    if (isset($db)) $db->rollBack();
} catch (Throwable $e) {fwrite(STDERR,'Arrival diagnostic failed: '.$e->getMessage().PHP_EOL);exit(1);}
finally {foreach (array_reverse($handles) as $h) {flock($h,LOCK_UN);fclose($h);}}
